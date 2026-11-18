"""A strategy is a small YAML file; running one logs it and writes a report.

    name: momentum_12_1          # unique; a changed file deserves a new name
    family: momentum             # variants of one idea share a family (trial count)
    signals: {momentum_12_1: 1}  # one signal, or weights for a blend of percentile ranks
    holdings: quintile           # a number, or quintile / decile of the universe
    rebalance: monthly           # monthly | weekly | quarterly
    hold_rank: null              # keep a holding while it ranks inside this
    exit: null                   # or below_ma200_2d
    universe: ftse350            # ftse350 | ftse100 | ftse250 | list:<csv of symbols>
    costs: {}                    # overrides of costs.py, e.g. {spread_large_bp: 10}
    start_cash: 100000
    drift_band: 0.02
    max_turnover: null
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

from . import engine, metrics, periods
from .config import Config
from .costs import CHECKED_ON, from_dict
from .pit import Fundamentals
from .runlog import RunLog, git_commit
from .signals import REGISTRY, Context, blend
from .store import Market, load_market
from .universe import fixed_universe, membership

log = logging.getLogger(__name__)


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
                            start_cash=float(r.get("start_cash", 100_000)))


def load_spec(path: str | Path) -> Spec:
    path = Path(path)
    text = path.read_bytes()
    raw = yaml.safe_load(text)
    for s in raw.get("signals", {}):
        if s not in REGISTRY:
            raise ValueError(f"{path.name}: unknown signal {s!r}; known: {', '.join(sorted(REGISTRY))}")
    return Spec(raw=raw, path=path, hash=hashlib.sha256(text).hexdigest()[:16])


def spec_from_dict(raw: dict) -> Spec:
    text = yaml.safe_dump(raw, sort_keys=True).encode()
    return Spec(raw=raw, path=None, hash=hashlib.sha256(text).hexdigest()[:16])


class Workspace:
    """The market, accounts and universe, loaded once for any number of runs."""

    def __init__(self, cfg: Config, market: Market | None = None, universe: pd.DataFrame | None = None):
        self.cfg = cfg
        self.m = market or load_market(cfg.data_dir)
        pit = cfg["point_in_time"]
        self.f = Fundamentals(self.m.statements, pit["annual_lag_months"], pit["half_year_lag_months"])
        if universe is None:
            up = cfg.data_dir / "universe.parquet"
            if not up.exists():
                raise FileNotFoundError("no universe yet: run `research universe` first")
            universe = pd.read_parquet(up)
        self.universe = universe
        self.log = RunLog(cfg.p("run_log"))

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
        member, large = membership(uni, self.m.dates)
        member = member.reindex(columns=self.m.close.columns, fill_value=False)
        large = large.reindex(columns=self.m.close.columns, fill_value=False)
        ctx = Context(self.m, self.f, member)
        rules = spec.rules()
        dates = self.m.dates[(self.m.dates >= start) & (self.m.dates <= end)]
        flags = engine.rebalance_flags(dates, rules.rebalance)
        flags[0] = True
        parts = [(REGISTRY[s], float(w)) for s, w in spec.raw["signals"].items()]
        scores, cover = {}, []
        for d in dates[flags]:
            row = member.loc[d]
            uni_syms = list(row.index[row.to_numpy()])
            line = {"date": d, "members": len(uni_syms)}
            if len(parts) == 1:
                scores[d] = parts[0][0](ctx, d).reindex(uni_syms)
                line[parts[0][0].signal_name] = int(scores[d].notna().sum())
            else:
                for fn, _ in parts:
                    line[fn.signal_name] = int(fn(ctx, d).reindex(uni_syms).notna().sum())
                scores[d] = blend(ctx, d, parts, uni_syms)
            line["scored"] = int(scores[d].notna().sum())
            cover.append(line)
        coverage = pd.DataFrame(cover).set_index("date")
        exit_flags = engine.ma200_below_twice(self.m.adj, self.m.ma200) if rules.exit_ma200 else None
        costs = from_dict(spec.raw.get("costs"))
        res = engine.simulate(self.m, member, large, scores, rules, costs, start, end, exit_flags)
        free = engine.simulate(self.m, member, large, scores, rules, costs.free(), start, end, exit_flags)
        bench = engine.benchmarks(self.m, member, start, end)
        out = metrics.summarise(res.values["total"], bench, res.fills, res.values["holdings"], res.pnl,
                                free.values["total"])
        share = coverage["scored"] / coverage["members"].replace(0, np.nan)
        out["coverage_median"] = float(coverage["scored"].median())
        out["coverage_min"] = int(coverage["scored"].min())
        out["coverage_share_median"] = float(share.median())
        out["members_median"] = float(coverage["members"].median())
        out["rebalance_refusals"] = res.refusals
        out["orders_skipped"] = res.skipped

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
            **out,
        }
        self.log.append(record)
        if final and kind == "research":
            self.log.freeze(spec.name, spec.hash, run_id)
        result = {"record": record, "result": res, "zero_cost": free, "bench": bench, "coverage": coverage,
                  "yearly": metrics.yearly(res.values["total"], bench)}
        if write_report:
            from .report import write_report as wr
            result["report"] = wr(cfg, result, self.log.trials(spec.family), periods.LABELS.get(period))
        return result
