"""A strategy is a small YAML file; running one logs it and writes a report.

    name: momentum_12_1          # unique; a changed file deserves a new name
    family: momentum             # variants of one idea share a family (trial count)
    signals: {momentum_12_1: 1}  # one stored indicator, or weights for a blend of percentile ranks
    screen: null                 # or a screen of stored indicators (from_rules.py writes these):
                                 # {criteria: [[name, op, constant, other_name] | [name, top_n, n, asc|desc]
                                 #             | [name, top_frac, f, asc|desc]],
                                 #  rank_by: name, direction: desc|asc, require: name|null,
                                 #  industry_map_file: path|null}
                                 # top_frac: the best fraction f of the universe members with a value,
                                 # taken first, before any other criterion (a research file's "cheapest
                                 # fifth"; sharemanager screens have none). industry_map_file: a saved
                                 # backtest claim (or its industry_map alone) whose map gives the
                                 # industries for ind_in / ind_not_in (from_rules.industries_from_map).
    holdings: quintile           # a number, or quintile / decile of the universe
    rebalance: monthly           # monthly | weekly | quarterly
    hold_rank: null              # keep a holding while it ranks inside this
    exit: null                   # or below_ma200_2d
    price_basis: adjusted        # or raw: raw closes (the N100's basis)
    dividends: on                # on: cash dividends credited (raw basis; on adjusted they are in the
                                 # price already); off: none, as the N100 engine deals (the run 9 check)
    universe: ftse350            # ftse350 | ftse100 | ftse250 | list:<csv of symbols>
    costs: {}                    # overrides of costs.py, e.g. {spread_large_bp: 10}
    start_cash: 100000
    drift_band: 0.02
    max_turnover: null           # min(buys, sells) / value a rebalance may trade, or null
    turnover_rule: partial       # over it: partial (trade up to it, hold the rest back, as the N100 since
                                 # 23 Nov 2026) or refuse (refuse the whole rebalance: the old N100, run 9)
    require_traded_today: true   # false: a price on or before the date is enough (the N100)
    max_orders: null             # the N100 refuses a rebalance of more than 50

The engine works out no indicator. Every value it ranks, screens or exits on
is read from the indicator store (indicator_store.py), by name on the run's
price basis (`<name>@raw`, `<name>@adjusted`, or `<name>` for one that reads
no prices). It refuses to start, naming the indicator, if one it needs is not
in the store, was built by an older definition (`versions`, handed in by the
caller: indicator_job.workspace), or was built from an older data version
(data_version.py). Each run records the definition and data version of every
indicator it read, and the data version. Portfolio rules stay here and in
engine.py: blends of stored indicators, screens over them, quintiles, hold
rank, drift band, the two-closes exit.
"""
from __future__ import annotations

import datetime as dt
import hashlib
import logging
from dataclasses import dataclass
from pathlib import Path

import numpy as np
import pandas as pd
import yaml

import operator as op

from . import data_version, engine, metrics, periods
from .config import Config
from .costs import CHECKED_ON, from_dict
from .indicator_store import IndicatorStore, StoreError
from .runlog import RunLog, git_commit
from .store import Market, load_market
from .universe import fixed_universe, membership

log = logging.getLogger(__name__)

# Market caps before this date include known bad values (Lloyds and Hammerson as the largest
# companies in 2007, Zephyr, DCI) that are kept; the cap-weighted benchmark rests on them.
UNCHECKED_CAPS_BEFORE = "2016-01-01"


@dataclass
class Spec:
    raw: dict
    path: Path | None
    hash: str

    @property
    def name(self) -> str:
        return self.raw["name"]

    @property
    def family(self) -> str:
        return self.raw.get("family") or self.name

    def rules(self) -> engine.Rules:
        r = self.raw
        return engine.Rules(holdings=r.get("holdings", 15), rebalance=r.get("rebalance", "monthly"),
                            hold_rank=r.get("hold_rank"), exit_ma200=r.get("exit") == "below_ma200_2d",
                            drift_band=float(r.get("drift_band", 0.02)), max_turnover=r.get("max_turnover"),
                            turnover_rule=str(r.get("turnover_rule", "partial")),
                            start_cash=float(r.get("start_cash", 100_000)),
                            require_traded_today=bool(r.get("require_traded_today", True)),
                            max_orders=r.get("max_orders"),
                            rebalance_asof=tuple(r["rebalance_asof"]) if r.get("rebalance_asof") else None)


class IndicatorRefused(RuntimeError):
    """The run needs an indicator the store does not hold, or holds out of date. `indicator` names it."""

    def __init__(self, indicator: str, why: str):
        super().__init__(f"indicator {indicator}: {why}")
        self.indicator = indicator


def load_spec(path: str | Path) -> Spec:
    path = Path(path)
    text = path.read_bytes()
    raw = yaml.safe_load(text)
    if not raw.get("signals") and not raw.get("screen"):
        raise ValueError(f"{path.name}: no signals and no screen")
    return Spec(raw=raw, path=path, hash=hashlib.sha256(text).hexdigest()[:16])


# ---- portfolio rules over stored indicators
COMPARE = {">": op.gt, ">=": op.ge, "<": op.lt, "<=": op.le, "=": op.eq, "!=": op.ne}


def blend_ranks(values: dict, weights: dict, universe: list[str]) -> pd.Series:
    """Weighted average of the percentile ranks of several indicators, within the universe."""
    total = weights_sum = None
    for name, w in weights.items():
        r = values[name].reindex(universe).rank(pct=True) * float(w)
        present = r.notna().astype(float) * float(w)
        total = r.fillna(0) if total is None else total + r.fillna(0)
        weights_sum = present if weights_sum is None else weights_sum + present
    return total / weights_sum.replace(0, np.nan)


INDUSTRY = {"ind_in", "ind_not_in"}       # sharemanager's industry criteria (from_rules.py)


def screen_names(screen: dict) -> list[str]:
    names = ([screen["rank_by"]] if screen.get("rank_by") else []) + ([screen["require"]] if screen.get("require") else [])
    for c in screen.get("criteria") or []:
        if c[1] in INDUSTRY:              # not an indicator: decided by screen["industries"]
            continue
        names.append(c[0])
        if c[1] not in ("top_n", "top_frac") and c[3] is not None:
            names.append(c[3])
    return list(dict.fromkeys(names))


def screen_scores(screen: dict, values: dict, members) -> pd.Series:
    """A screen as build_screen() applies it: comparisons intersected, then each top_n in order keeping
    the best N of what is left, scored by rank_by (negated for an ascending order). Only members."""
    keep = pd.Index(members)
    for name, _, f, d in [c for c in screen.get("criteria") or [] if c[1] == "top_frac"]:
        v = values[name].reindex(keep).dropna()
        v = v.sort_index().sort_values(ascending=(d == "asc"), kind="mergesort")
        keep = v.index[: int(np.ceil(float(f) * len(v)))]
    for name, oper, const, other in [c for c in screen.get("criteria") or [] if c[1] not in ("top_n", "top_frac")]:
        if oper in INDUSTRY:
            # "is one of": the share's industry is listed. "is not": it is not. The industries are
            # the translator's (from_rules.py), today's for every date; nothing is looked up here.
            of = screen.get("industries") or {}
            listed = np.array([of.get(str(s)) in set(const) for s in keep], dtype=bool)
            keep = keep[listed if oper == "ind_in" else ~listed]
            continue
        left = values[name].reindex(keep)
        right = const if other is None else values[other].reindex(keep)
        ok = COMPARE[oper](left, right) & left.notna()
        if other is not None:
            ok &= right.notna()
        keep = keep[ok.fillna(False).astype(bool).to_numpy()]
    if screen.get("require"):
        keep = keep[values[screen["require"]].reindex(keep).notna().to_numpy()]
    for name, _, n, d in [c for c in screen.get("criteria") or [] if c[1] == "top_n"]:
        v = values[name].reindex(keep).dropna()
        v = v.sort_index().sort_values(ascending=(d == "asc"), kind="mergesort")
        keep = v.index[: int(n)]
    if not screen.get("rank_by"):
        # every share left is held, equally: no order among them
        return pd.Series(1.0, index=keep)
    score = values[screen["rank_by"]].reindex(keep)
    return score if screen.get("direction", "desc") == "desc" else -score


def _with_industries(spec: Spec, master, universe) -> Spec:
    """A research file's screen with the industries of a saved claim's map (from_rules.industries_from_map):
    the map is the N100's, taken as it is; this keeps no list of its own."""
    import copy
    import json
    from .from_rules import industries_from_map
    raw = copy.deepcopy(spec.raw)
    sc = raw["screen"]
    m = json.loads(Path(sc["industry_map_file"]).expanduser().read_text())
    m = m.get("industry_map", m)
    industries, version = industries_from_map(m, master, universe)
    sc.update({"industries": industries, "industry_map_version": version})
    return Spec(raw=raw, path=spec.path, hash=spec.hash)


def spec_from_dict(raw: dict) -> Spec:
    text = yaml.safe_dump(raw, sort_keys=True).encode()
    return Spec(raw=raw, path=None, hash=hashlib.sha256(text).hexdigest()[:16])


class Workspace:
    """The market, accounts and universe, loaded once for any number of runs."""

    def __init__(self, cfg: Config, market: Market | None = None, universe: pd.DataFrame | None = None, *,
                 versions: dict[str, str]):
        """`versions`: every indicator key -> its definition's version now (indicator_job.versions)."""
        self.cfg = cfg
        self.m = market or load_market(cfg.data_dir)
        self.store = IndicatorStore.for_config(cfg)
        self.versions = dict(versions)
        if universe is None:
            up = cfg.data_dir / "universe.parquet"
            if not up.exists():
                raise FileNotFoundError("no universe yet: run `research universe` first")
            universe = pd.read_parquet(up)
        self.universe = universe
        self.log = RunLog(cfg.p("run_log"))

    def dividends_setting(self, spec: "Spec") -> str:
        v = spec.raw.get("dividends", "on")
        v = {True: "on", False: "off"}.get(v, v)          # YAML reads a bare on/off as a boolean
        v = str(v).lower()
        if v not in ("on", "off"):
            raise ValueError(f"dividends must be on or off, not {v!r}")
        return v

    def price_basis(self, spec: "Spec") -> str:
        basis = str(spec.raw.get("price_basis") or self.cfg.get("engine", {}).get("price_basis", "adjusted")).lower()
        if basis not in ("adjusted", "raw"):
            raise ValueError(f"price_basis must be adjusted or raw, not {basis!r}")
        return basis

    def market_for(self, basis: str) -> Market:
        """The market on a price basis.

        adjusted: returns, averages and valuations on the dividend-adjusted
        close (total return). raw: everything on the raw close and no
        dividends credited, as the N100 prices (historical_prices.price).
        """
        if basis == "adjusted":
            return self.m
        if basis != "raw":
            raise ValueError(f"price basis is raw or adjusted, not {basis!r}")
        if getattr(self, "_raw_market", None) is None:
            from dataclasses import replace
            self._raw_market = replace(self.m, adj=self.m.close, _ma200=None)
        return self._raw_market

    def universe_for(self, which: str) -> pd.DataFrame:
        u = self.universe
        large = int(self.cfg["universe"]["large_size"])
        if which == "ftse350":
            return u
        if which == "ftse100":
            return u[u["rank"] <= large]
        if which == "ftse250":
            return u[u["rank"] > large]
        if which.startswith("list:"):
            from .universe import read_symbol_list
            syms = read_symbol_list(Path(which[5:]).expanduser())
            return fixed_universe(self.m, syms["symbol"].tolist(), self.cfg["universe"]["start"], large)
        raise ValueError(f"unknown universe {which!r}")

    def run(self, spec: Spec, period: str = "dev", final: bool = False, kind: str = "research",
            start=None, end=None, write_report: bool = True) -> dict:
        cfg = self.cfg
        latest = self.m.dates[-1]
        if start is None:
            start, end = periods.period_dates(cfg, period, latest)
        start, end = pd.Timestamp(start), min(pd.Timestamp(end), latest)
        floor = pd.Timestamp(cfg["universe"]["start"])
        if start < floor:
            raise ValueError(f"no universe before {floor.date()} (market cap history starts then)")
        if kind == "research":
            touches = periods.check_holdout(cfg, start, end, final)
        else:
            touches = end >= pd.Timestamp(cfg["periods"]["holdout_start"])
        self.log.check_frozen(spec.name, spec.hash, final)

        uni = self.universe_for(spec.raw.get("universe", "ftse350"))
        if spec.raw.get("screen") and spec.raw["screen"].get("industry_map_file"):
            spec = _with_industries(spec, self.m.master, uni["symbol"].unique() if "symbol" in uni else [])
        member, large = membership(uni, self.m.dates)
        member = member.reindex(columns=self.m.close.columns, fill_value=False)
        large = large.reindex(columns=self.m.close.columns, fill_value=False)
        basis = self.price_basis(spec)
        mk = self.market_for(basis)
        rules = spec.rules()
        if spec.raw.get("targets_file"):
            from .universe import to_fmp
            t = pd.read_csv(Path(spec.raw["targets_file"]).expanduser())
            dcol = "as_of_date" if "as_of_date" in t else "date"
            fixed: dict = {}
            for a, g in t.groupby(pd.to_datetime(t[dcol])):
                k = int(self.m.dates.searchsorted(a, side="right")) - 1
                if k >= 0:
                    fixed.setdefault(self.m.dates[k], []).extend(g["symbol"].map(to_fmp).tolist())
            rules.fixed_targets = fixed
        # decision day -> the N100's as-of date, where they differ (a holiday or a weekend; the decision is
        # taken on the last close before it). An indicator kept for every calendar day is read on the as-of date.
        asof: dict = {}
        if rules.rebalance_asof:
            for a in pd.DatetimeIndex(pd.to_datetime(list(rules.rebalance_asof))):
                k = int(self.m.dates.searchsorted(a, side="right")) - 1
                if k >= 0:
                    asof[self.m.dates[k]] = a
        dates = self.m.dates[(self.m.dates >= start) & (self.m.dates <= end)]
        flags = engine.rebalance_flags(dates, rules.rebalance, rules.rebalance_asof)
        flags[0] = True
        decision_days = dates[flags]

        # Every indicator the run needs, checked before anything is worked out.
        weights = {str(n): float(w) for n, w in (spec.raw.get("signals") or {}).items()}
        screen = spec.raw.get("screen")
        names = list(weights) + (screen_names(screen) if screen else [])
        names = list(dict.fromkeys(names))
        if not names:
            raise ValueError(f"{spec.name}: no signals and no screen")
        exit_names = ["close_price", "ma200"] if rules.exit_ma200 else []
        data_now = self._data_version()
        keys = {n: self._key(n, basis, data_now) for n in dict.fromkeys(names + exit_names)}
        used = {}
        values: dict = {}                       # name -> {decision day: Series}
        for n in names:
            k = keys[n]
            cal = self.store.meta(k)["calendar"]
            read_on = {d: (asof.get(d, d) if cal == "daily" else d) for d in decision_days}
            got = self._read(k, list(read_on.values()))
            used[k] = {"version": got.version, "data_version": got.data_version}
            values[n] = {d: got.frame.loc[pd.Timestamp(read_on[d])] for d in decision_days}

        scores, cover = {}, []
        for d in decision_days:
            row = member.loc[d]
            uni_syms = list(row.index[row.to_numpy()])
            line = {"date": d, "members": len(uni_syms)}
            today = {n: values[n][d] for n in names}
            for n in names:
                line[n] = int(today[n].reindex(uni_syms).notna().sum())
            if screen:
                scores[d] = screen_scores(screen, today, uni_syms).reindex(uni_syms)
            elif len(weights) == 1:
                scores[d] = today[next(iter(weights))].reindex(uni_syms)
            else:
                scores[d] = blend_ranks(today, weights, uni_syms)
            line["scored"] = int(scores[d].notna().sum())
            cover.append(line)
        coverage = pd.DataFrame(cover).set_index("date")

        exit_flags = None
        if rules.exit_ma200:
            # Two statistics rows in a row with the close below its 200-day average: every trading day from
            # the one before the start, plus the N100's calendar rows (some Sundays) when exit_calendar is set.
            i0 = int(self.m.dates.searchsorted(start, side="left"))
            first = self.m.dates[max(i0 - 1, 0)]
            ck, mk_ = keys["close_price"], keys["ma200"]
            built_from = max(self.store.meta(ck)["dates"][0], self.store.meta(mk_)["dates"][0])
            first = max(first, built_from)
            rows = self.m.dates[(self.m.dates >= first) & (self.m.dates <= end)]
            if spec.raw.get("exit_calendar"):
                cal = pd.to_datetime(pd.read_csv(Path(spec.raw["exit_calendar"]).expanduser())["date"])
                rows = rows.union(pd.DatetimeIndex(cal[(cal >= first) & (cal <= end)]))
            cv, mv = self._read(ck, rows), self._read(mk_, rows)
            for g in (cv, mv):
                used[g.key] = {"version": g.version, "data_version": g.data_version}
            exit_flags = engine.exit_below_twice(cv.frame, mv.frame, self.m.dates)
        costs = from_dict(spec.raw.get("costs"))
        div_setting = self.dividends_setting(spec)
        # Raw basis: the engine credits them as cash. Adjusted basis: already in the price.
        divs = mk.dividends if (div_setting == "on" and basis == "raw") else None
        # How each share ranks tonight, for the turnover rule's "weakest leaver first": a screen's rank_by
        # value (signed by its direction) for every share, not only those the screen kept; else the score.
        rank_values = None
        if screen and screen.get("rank_by"):
            sign = 1.0 if screen.get("direction", "desc") == "desc" else -1.0
            rank_values = {d: values[screen["rank_by"]][d] * sign for d in decision_days}
        res = engine.simulate(mk, member, large, scores, rules, costs, start, end, exit_flags, divs, rank_values)
        free = engine.simulate(mk, member, large, scores, rules, costs.free(), start, end, exit_flags, divs,
                               rank_values)
        # Benchmarks are total return (dividend-adjusted) whatever the strategy's basis. Run 19's
        # cap-weighted 5.1% over 2007-2025 was price only: the raw basis took the dividends out.
        bench = engine.benchmarks(self.m, member, start, end)
        out = metrics.summarise(res.values["total"], bench, res.fills, res.values["holdings"], res.pnl,
                                free.values["total"])
        share = coverage["scored"] / coverage["members"].replace(0, np.nan)
        out["coverage_median"] = float(coverage["scored"].median())
        out["coverage_min"] = int(coverage["scored"].min())
        out["coverage_share_median"] = float(share.median())
        out["members_median"] = float(coverage["members"].median())
        out["dividends"] = ("off" if div_setting == "off" else
                            "credited as cash" if basis == "raw" else "in the adjusted price")
        out["dividends_received"] = float(res.dividends_received)
        out["dividends_after_end"] = int(res.dividends_after_end)
        out["dividends_after_end_cash"] = float(res.dividends_after_end_cash)
        out["dividends_skipped_noncash"] = int(res.dividends_skipped_noncash)
        out["rebalance_refusals"] = res.refusals
        out["turnover_refusals"] = sum(1 for e in res.events if e.get("event") == "rebalance refused: turnover")
        out["turnover_held_back"] = int(res.held_back)
        out["turnover_rule"] = rules.turnover_rule
        out["orders_skipped"] = res.skipped

        if screen and screen.get("industry_map_version"):
            out["industry_map_version"] = str(screen["industry_map_version"])
        out["cap_weight_unchecked_caps"] = bool(start < pd.Timestamp(UNCHECKED_CAPS_BEFORE))
        prior = self.log.read()
        family_sharpes, n_trials = [], 1
        if not prior.empty and "family" in prior:
            fam = prior[(prior["family"] == spec.family) & (prior["kind"] == "research")]
            family_sharpes = fam["sharpe"].dropna().tolist() if "sharpe" in fam else []
            n_trials = len(fam) + 1
        out.update(metrics.deflated_sharpe(out["sharpe"], out["observations"], out["skew"], out["kurtosis"],
                                           n_trials, family_sharpes + [out["sharpe"]]))
        run_id = self.log.new_id()
        record = {
            "run_id": run_id, "time": dt.datetime.now().isoformat(timespec="seconds"),
            "git_commit": git_commit(), "kind": kind, "strategy": spec.name, "family": spec.family,
            "strategy_hash": spec.hash, "config_hash": cfg.hash, "signals": spec.raw["signals"],
            "parameters": {k: v for k, v in spec.raw.items() if k not in ("name", "family", "signals")},
            "universe": spec.raw.get("universe", "ftse350"), "period": period,
            "final": bool(final), "touches_holdout": bool(touches), "costs_checked_on": CHECKED_ON,
            "price_basis": basis, "data_version": data_now, "indicators": used,
            **out,
        }
        self.log.append(record)
        if final and kind == "research":
            self.log.freeze(spec.name, spec.hash, run_id)
        result = {"record": record, "result": res, "zero_cost": free, "bench": bench, "coverage": coverage,
                  "scores": scores, "asof": asof, "member": member, "market": mk,
                  "yearly": metrics.yearly(res.values["total"], bench)}
        if write_report:
            from .report import write_report as wr
            result["report"] = wr(cfg, result, self.log.trials(spec.family), periods.LABELS.get(period))
        return result

    # ---- the store
    def _data_version(self) -> int:
        try:
            return data_version.current(self.cfg.data_dir)
        except data_version.UnrecordedChange as e:
            raise IndicatorRefused("(all)", str(e)) from None

    def _key(self, name: str, basis: str, data_now: int) -> str:
        """The stored indicator for a name on the price basis, checked up to date."""
        for k in (f"{name}@{basis}", name):
            if self.store.has(k):
                break
        else:
            raise IndicatorRefused(f"{name}@{basis}", "not in the store; run `research indicators`")
        meta = self.store.meta(k)
        want = self.versions.get(k)
        if want is None:
            raise IndicatorRefused(k, "this code has no definition of it")
        if meta["version"] != want:
            raise IndicatorRefused(k, f"out of date: stored at definition version {meta['version']}, the "
                                      f"definition is now {want}; run `research indicators`")
        if int(meta["data_version"]) != int(data_now):
            raise IndicatorRefused(k, f"out of date: built from data version {meta['data_version']}, the data "
                                      f"is at version {data_now}; run `research indicators`")
        return k

    def _read(self, k: str, dates):
        try:
            return self.store.read(k, dates)
        except StoreError as e:
            raise IndicatorRefused(k, str(e)) from None
