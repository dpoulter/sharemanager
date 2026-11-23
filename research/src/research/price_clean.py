"""price_clean (contracts/modules.yaml, approved 23 Nov 2026): the one owner of "is this price usable".

Runs after the fetch and before indicator_job (cli.cmd_fetch calls it, nightly and by hand;
`research price-clean` runs it alone). It never edits prices.parquet, FMP's answer as fetched.
It works out, from that file (less the bad ticks clean.py drops), a list of fixes:

    data/price_fixes.parquet   symbol, date, close_before, close_after, adj_before, adj_after,
                               usable, rule, note. Prices in the line's quote unit, as prices.parquet.
                               usable=False: the row is unusable, no price (store.load_market drops it,
                               the same as a day with no trade). Only rows that change are listed.

store.load_market applies it, so the engine, the indicators and the publish all read the cleaned
prices and none of them has its own check. After writing, run() records a data version
(data_version.record, reason "price_clean"): the companies and months changed, so the indicators
affected rebuild and the next publish carries the fix to the N100. It never writes to the N100.

The rules, per symbol, on the raw close and on the dividend-adjusted close, in this order. A "big
move" is a daily rise over `rise` or a fall over `fall` (config price_clean). A move a recorded split
or consolidation explains (FMP `splits`, within SPLIT_DAYS, the price ratio within a factor of
SPLIT_SLACK of the split's) is never a big move, and no rule changes a price across one.

    1  pence and pounds: a move of a factor between factor_lo and factor_hi (either way) is a unit
       step. Each stretch between steps gets a level (the number of x100 steps from the start); the
       level held on the most days is right, every other stretch is rescaled by exactly 100 per level.
       A stretch with a recorded split inside it is not rescaled; it is reported.
    2  a one-day spike: a big move whose next day comes back to within `spike_back` of the day
       before it. The spike's value becomes the previous close.
    3  an adjusted-close jump with the raw close not making a big move: the adjusted close is rebuilt
       from the raw close and the dividends (and splits): R = close x product over later ex-dates of
       (1 - dividend / close the day before), scaled to FMP's own base (the median of adj / R over the
       last ANCHOR_SESSIONS sessions: FMP anchors its adjusted series to the latest close). The
       dates where adj is more than `adj_tolerance` away from that are set to it.
    4  any other big move: if it comes back to within `revert_within` of the price before it inside
       `revert_sessions` sessions, the dates from the move to the return are unusable. A move that
       stays (a real collapse, a re-rating) is left as it is and listed in the report. Until FMP's split
       history is available (the endpoint refused, or not asked for a symbol), rule 4 only reports.

research/price_overrides.yaml records Dale's decisions per symbol and date (or from/to), each with
a reason, and wins over the rules: keep (FMP's value stands), exclude (unusable), set (close and/or
adj_close given). An entry without a reason is refused.

Running it again on the same data writes the same fixes, so the second record changes nothing.
Every change and every move only reported goes to data/price_clean_report.md and .csv.
"""
from __future__ import annotations

import datetime as dt
import json
import logging
from pathlib import Path

import numpy as np
import pandas as pd
import yaml

log = logging.getLogger(__name__)

FIXES = "price_fixes.parquet"
SPLITS = "splits.parquet"
SPLITS_ASKED = "splits_asked.json"
REPORT_MD = "price_clean_report.md"
REPORT_CSV = "price_clean_report.csv"
SPLIT_ENDPOINT = "splits"
OVERRIDES = Path(__file__).resolve().parents[2] / "price_overrides.yaml"
OVERRIDE_ACTIONS = ("keep", "exclude", "set")

DEFAULTS = {
    "rise": 1.0,            # a daily rise over 100% ...
    "fall": 0.67,           # ... or a fall over 67% is a big move
    # Pence and pounds. The brief's start was 80 to 120. Lookers (LOOK.L) in January 2008 went from
    # 115p to 8,700p: a real fall to about 87p on the same day as the x100 slip, a ratio of 75.7, so
    # 80 to 120 misses it. 50 to 200 catches a slip that comes with a real move of up to half or
    # double; no real one-day move that large is left unexplained once recorded splits are excluded.
    "factor_lo": 50.0,
    "factor_hi": 200.0,
    "spike_back": 0.25,     # rule 2: the next day within 25% of the day before the spike
    "adj_tolerance": 0.5,   # rule 3: adj more than 1.5 times (or under 1/1.5) the rebuilt value
    "revert_sessions": 20,  # rule 4: comes back within 20 sessions ...
    "revert_within": 0.5,   # ... to within 50% of the price before the move
}
ANCHOR_SESSIONS = 20        # rule 3: FMP's base read from the last 20 sessions
SPLIT_DAYS = 7              # calendar days between a move and the split that explains it
SPLIT_SLACK = 2.0           # the move within a factor of 2 of the split's ratio


def settings(cfg) -> dict:
    s = dict(DEFAULTS)
    s.update((cfg.get("price_clean") or {}) if cfg is not None else {})
    return s


# ---------------------------------------------------------------- pieces
def _big(r: np.ndarray, s: dict) -> np.ndarray:
    with np.errstate(invalid="ignore"):
        return (r > 1 + s["rise"]) | (r < 1 - s["fall"])


def _ratios(x: np.ndarray) -> np.ndarray:
    r = np.full(len(x), np.nan)
    with np.errstate(divide="ignore", invalid="ignore"):
        r[1:] = x[1:] / x[:-1]
    return r


def _explained(dates: np.ndarray, r: np.ndarray, splits: pd.DataFrame) -> np.ndarray:
    """True where a recorded split or consolidation explains the move into that day."""
    out = np.zeros(len(dates), dtype=bool)
    if splits is None or splits.empty:
        return out
    for sd, ratio in zip(splits["date"].to_numpy(), splits["ratio"].to_numpy()):
        near = np.abs((dates - sd) / np.timedelta64(1, "D")) <= SPLIT_DAYS
        with np.errstate(divide="ignore", invalid="ignore"):
            q = r / ratio
        out |= near & (q <= SPLIT_SLACK) & (q >= 1 / SPLIT_SLACK)
    return out


def _split_inside(dates: np.ndarray, a: int, b: int, splits: pd.DataFrame) -> bool:
    """A recorded split strictly inside rows a..b (a stretch's own dates)."""
    if splits is None or splits.empty or b <= a:
        return False
    sd = splits["date"].to_numpy()
    return bool(((sd > dates[a]) & (sd <= dates[b])).any())


def rule_1(x: np.ndarray, dates: np.ndarray, splits: pd.DataFrame, s: dict) -> tuple[np.ndarray, list[str], list[dict]]:
    """Pence and pounds stretches rescaled by exactly 100 a level. Returns the series, the rule per row
    ('' or '1') and findings for stretches refused."""
    n = len(x)
    rule = [""] * n
    r = _ratios(x)
    with np.errstate(divide="ignore", invalid="ignore"):
        up = (r >= s["factor_lo"]) & (r <= s["factor_hi"])
        down = (r <= 1 / s["factor_lo"]) & (r >= 1 / s["factor_hi"])
    ok = ~_explained(dates, r, splits)
    step = np.where(up & ok, 1, np.where(down & ok, -1, 0))
    if not step.any():
        return x, rule, []
    level = np.cumsum(step)
    vals, counts = np.unique(level, return_counts=True)
    right = vals[np.argmax(counts)]
    out = x.copy()
    found = []
    # each stretch: a run of the same level
    edges = np.r_[0, np.where(np.diff(level) != 0)[0] + 1, n]
    for a, b in zip(edges[:-1], edges[1:]):
        k = level[a]
        if k == right:
            continue
        if _split_inside(dates, a, b - 1, splits):
            found.append({"row": a, "finding": "rule 1 refused: a recorded split inside the stretch",
                          "to_row": b - 1, "ratio": float(100.0 ** (right - k))})
            continue
        out[a:b] = x[a:b] * 100.0 ** (right - k)
        for i in range(a, b):
            rule[i] = "1"
    return out, rule, found


def rule_2(x: np.ndarray, dates: np.ndarray, splits: pd.DataFrame, s: dict) -> tuple[np.ndarray, list[str]]:
    out = x.copy()
    rule = [""] * len(x)
    cand = np.where(_big(_ratios(x), s))[0]
    for i in cand[(cand >= 1) & (cand < len(x) - 1)]:
        r = out[i] / out[i - 1]
        if not _big(np.array([r]), s)[0]:
            continue
        back = out[i + 1] / out[i - 1]
        if not (1 / (1 + s["spike_back"]) <= back <= 1 + s["spike_back"]):
            continue
        both = np.array([r, out[i + 1] / out[i]])
        if _explained(dates[i:i + 2], both, splits).any():
            continue
        out[i] = out[i - 1]
        rule[i] = "2"
    return out, rule


def rebuilt_adjusted(close: np.ndarray, dates: np.ndarray, divs: pd.DataFrame, splits: pd.DataFrame) -> np.ndarray:
    """close x the product, over every later ex-date and split, of its adjustment factor."""
    f = np.ones(len(close))
    if divs is not None and len(divs):
        for ex, amt in zip(divs["ex_date"].to_numpy(), divs["amount"].to_numpy()):
            j = np.searchsorted(dates, ex)           # first row on or after the ex-date
            if j <= 0 or j > len(close) or not np.isfinite(amt) or amt <= 0:
                continue
            prev = close[j - 1]
            if not np.isfinite(prev) or prev <= amt:
                continue
            f[:j] *= 1 - amt / prev
    if splits is not None and len(splits):
        for sd, ratio in zip(splits["date"].to_numpy(), splits["ratio"].to_numpy()):
            j = np.searchsorted(dates, sd)
            if 0 < j <= len(close) and np.isfinite(ratio) and ratio > 0:
                f[:j] *= ratio
    return close * f


def rule_3(adj: np.ndarray, close: np.ndarray, dates: np.ndarray, divs: pd.DataFrame, splits: pd.DataFrame,
           s: dict) -> tuple[np.ndarray, list[str]]:
    rule = [""] * len(adj)
    ra, rc = _ratios(adj), _ratios(close)
    trig = _big(ra, s) & ~_big(rc, s) & ~_explained(dates, ra, splits)
    if not trig.any():
        return adj, rule
    R = rebuilt_adjusted(close, dates, divs, splits)
    with np.errstate(divide="ignore", invalid="ignore"):
        q = adj / R
    good = np.isfinite(q) & (q > 0)
    if not good.any():
        return adj, rule
    # FMP's adjusted series is anchored to the latest close (adj = close on the last day, earlier days
    # scaled down), so its base is read from the most recent sessions, not the whole history.
    qm = float(np.median(q[good][-ANCHOR_SESSIONS:]))
    tol = np.log(1 + s["adj_tolerance"])
    with np.errstate(divide="ignore", invalid="ignore"):
        bad = good & (np.abs(np.log(q / qm)) > tol)
    out = adj.copy()
    out[bad] = R[bad] * qm
    for i in np.where(bad)[0]:
        rule[i] = "3"
    return out, rule


def rule_4(x: np.ndarray, dates: np.ndarray, splits: pd.DataFrame, s: dict) -> tuple[list[tuple[int, int]], list[dict]]:
    """Stretches [a, b) that come back (unusable), and findings for moves that stay."""
    r = _ratios(x)
    big = _big(r, s) & ~_explained(dates, r, splits)
    stretches, found = [], []
    lo, hi = 1 / (1 + s["revert_within"]), 1 + s["revert_within"]
    i, n = 1, len(x)
    while i < n:
        if not big[i]:
            i += 1
            continue
        base = x[i - 1]
        back = None
        for j in range(i + 1, min(i + 1 + int(s["revert_sessions"]), n)):
            if lo <= x[j] / base <= hi:
                back = j
                break
        if back is None:
            found.append({"row": i, "finding": "big move that stays: left as it is", "ratio": float(r[i])})
            i += 1
        else:
            stretches.append((i, back))
            i = back
    return stretches, found


# ---------------------------------------------------------------- inputs
def load_overrides(path: Path | None = None) -> pd.DataFrame:
    """symbol, from, to, action, close, adj_close, reason. Refuses an entry without a reason or action."""
    path = Path(path or OVERRIDES)
    cols = ["symbol", "from", "to", "action", "close", "adj_close", "reason"]
    if not path.exists():
        return pd.DataFrame(columns=cols)
    raw = yaml.safe_load(path.read_text()) or []
    if isinstance(raw, dict):
        raw = raw.get("overrides") or []
    rows = []
    for k, e in enumerate(raw):
        where = f"{path.name} entry {k + 1} ({e.get('symbol', '?')})"
        if not str(e.get("reason") or "").strip():
            raise ValueError(f"{where}: every override needs a reason")
        if e.get("action") not in OVERRIDE_ACTIONS:
            raise ValueError(f"{where}: action must be one of {OVERRIDE_ACTIONS}")
        a = e.get("from", e.get("date"))
        b = e.get("to", e.get("date"))
        if not e.get("symbol") or a is None:
            raise ValueError(f"{where}: needs symbol and date (or from and to)")
        if e["action"] == "set" and e.get("close") is None and e.get("adj_close") is None:
            raise ValueError(f"{where}: set needs close or adj_close")
        rows.append({"symbol": str(e["symbol"]), "from": pd.Timestamp(a), "to": pd.Timestamp(b),
                     "action": e["action"], "close": e.get("close"), "adj_close": e.get("adj_close"),
                     "reason": str(e["reason"]).strip()})
    return pd.DataFrame(rows, columns=cols)


def load_splits(data: Path) -> pd.DataFrame:
    """symbol, date, ratio (price after / price before: denominator / numerator)."""
    p = Path(data) / SPLITS
    if not p.exists():
        return pd.DataFrame(columns=["symbol", "date", "ratio"])
    s = pd.read_parquet(p)
    s["date"] = pd.to_datetime(s["date"])
    s["ratio"] = s["denominator"].astype(float) / s["numerator"].astype(float)
    return s[["symbol", "date", "ratio"]]


def _prices(data: Path) -> pd.DataFrame:
    p = pd.read_parquet(data / "prices.parquet", columns=["symbol", "date", "close", "adj_close"])
    p["date"] = pd.to_datetime(p["date"])
    p = p.drop_duplicates(["symbol", "date"], keep="last")
    t = data / "bad_ticks.parquet"
    if t.exists():
        bt = pd.read_parquet(t, columns=["symbol", "date"])
        if len(bt):
            bt["date"] = pd.to_datetime(bt["date"])
            key = pd.MultiIndex.from_frame(p[["symbol", "date"]])
            p = p[~key.isin(pd.MultiIndex.from_frame(bt))]
    p["close"] = pd.to_numeric(p["close"], errors="coerce").astype(float)
    p["adj_close"] = pd.to_numeric(p["adj_close"], errors="coerce").astype(float)
    return p[np.isfinite(p["close"]) & (p["close"] > 0)].sort_values(["symbol", "date"]).reset_index(drop=True)


def _dividends(data: Path) -> pd.DataFrame:
    p = data / "dividends.parquet"
    if not p.exists():
        return pd.DataFrame(columns=["symbol", "ex_date", "amount"])
    d = pd.read_parquet(p)
    d["ex_date"] = pd.to_datetime(d["ex_date"])
    d["amount"] = pd.to_numeric(d["amount"], errors="coerce")
    return d[["symbol", "ex_date", "amount"]]


def moving_symbols(prices: pd.DataFrame, s: dict) -> dict[str, pd.Timestamp]:
    """Symbol -> the last date of a big move in its close or adjusted close (what the split history is
    asked for)."""
    out = {}
    for sym, g in prices.groupby("symbol", sort=True):
        c = g["close"].to_numpy()
        a = g["adj_close"].fillna(g["close"]).to_numpy()
        big = _big(_ratios(c), s) | _big(_ratios(a), s)
        if big.any():
            out[sym] = g["date"].to_numpy()[np.where(big)[0][-1]]
    return out


# ---------------------------------------------------------------- the rules over everything
def clean(prices: pd.DataFrame, dividends: pd.DataFrame, splits: pd.DataFrame, overrides: pd.DataFrame,
          s: dict, splits_known: set[str] | None) -> tuple[pd.DataFrame, pd.DataFrame]:
    """(fixes, findings). `splits_known`: symbols whose split history was read (None: all of them)."""
    fixes, findings = [], []
    divs = dict(tuple(dividends.groupby("symbol"))) if len(dividends) else {}
    spl = dict(tuple(splits.groupby("symbol"))) if len(splits) else {}
    ov = dict(tuple(overrides.groupby("symbol"))) if len(overrides) else {}
    syms = sorted(set(prices["symbol"]))
    for sym, g in prices.groupby("symbol", sort=True):
        dates = g["date"].to_numpy()
        c0 = g["close"].to_numpy()
        a0 = g["adj_close"].fillna(g["close"]).to_numpy()
        a_had = g["adj_close"].notna().to_numpy()
        sp = spl.get(sym)
        known = splits_known is None or sym in splits_known

        def note(row, series, finding, ratio=np.nan, to_row=None):
            findings.append({"symbol": sym, "date": dates[row], "to": dates[to_row] if to_row is not None else dates[row],
                             "series": series, "ratio": ratio, "finding": finding})

        c, rc1, f1 = rule_1(c0, dates, sp, s)
        a, ra1, g1 = rule_1(a0, dates, sp, s)
        for f in f1:
            note(f["row"], "close", f["finding"], f["ratio"], f["to_row"])
        for f in g1:
            note(f["row"], "adj_close", f["finding"], f["ratio"], f["to_row"])
        c, rc2 = rule_2(c, dates, sp, s)
        a, ra2 = rule_2(a, dates, sp, s)
        a, ra3 = rule_3(a, c, dates, divs.get(sym), sp, s)
        unusable = np.zeros(len(c), dtype=bool)
        for series, x in (("close", c), ("adj_close", a)):
            stretches, found = rule_4(x, dates, sp, s)
            for f in found:
                note(f["row"], series, f["finding"], f["ratio"])
            for i0, i1 in stretches:
                ratio = float(x[i0] / x[i0 - 1])
                if known:
                    unusable[i0:i1] = True
                    note(i0, series, "rule 4: unusable until the price comes back", ratio, i1 - 1)
                else:
                    note(i0, series, "rule 4, report only (split history not available): "
                                     "comes back; Dale to decide in price_overrides.yaml", ratio, i1 - 1)
        rules = [",".join(sorted({x for x in (rc1[i], ra1[i], rc2[i], ra2[i], ra3[i]) if x}))
                 for i in range(len(c))]
        rules = [("4" if unusable[i] else r) for i, r in enumerate(rules)]
        notes = [""] * len(c)
        for _, o in (ov.get(sym, pd.DataFrame()).iterrows()):
            m = (dates >= np.datetime64(o["from"])) & (dates <= np.datetime64(o["to"]))
            for i in np.where(m)[0]:
                rules[i] = f"override: {o['action']}"
                notes[i] = o["reason"]
                if o["action"] == "keep":
                    c[i], a[i], unusable[i] = c0[i], a0[i], False
                elif o["action"] == "exclude":
                    unusable[i] = True
                else:
                    c[i] = float(o["close"]) if o["close"] is not None and pd.notna(o["close"]) else c0[i]
                    a[i] = float(o["adj_close"]) if o["adj_close"] is not None and pd.notna(o["adj_close"]) else a[i]
                    unusable[i] = False
        changed = unusable | ~np.isclose(c, c0, rtol=1e-12, atol=0) | (~np.isclose(a, a0, rtol=1e-12, atol=0))
        for i in np.where(changed)[0]:
            fixes.append({"symbol": sym, "date": dates[i], "close_before": c0[i],
                          "close_after": np.nan if unusable[i] else c[i],
                          "adj_before": a0[i] if a_had[i] else np.nan,
                          "adj_after": np.nan if unusable[i] else a[i],
                          "usable": not bool(unusable[i]), "rule": rules[i], "note": notes[i]})
    for _, o in overrides[~overrides["symbol"].isin(syms)].iterrows() if len(overrides) else []:
        findings.append({"symbol": o["symbol"], "date": o["from"], "to": o["to"], "series": "",
                         "ratio": np.nan, "finding": "override for a symbol with no prices"})
    fx = pd.DataFrame(fixes, columns=["symbol", "date", "close_before", "close_after", "adj_before", "adj_after",
                                      "usable", "rule", "note"])
    fd = pd.DataFrame(findings, columns=["symbol", "date", "to", "series", "ratio", "finding"])
    if len(fx):
        fx["date"] = pd.to_datetime(fx["date"])
    return fx.sort_values(["symbol", "date"]).reset_index(drop=True), fd


def apply_fixes(prices: pd.DataFrame, fixes: pd.DataFrame) -> pd.DataFrame:
    """prices (symbol, date, close, adj_close ...) with the fixes applied: unusable rows dropped,
    changed values set. Used by store.load_market."""
    if fixes is None or fixes.empty:
        return prices
    f = fixes.copy()
    f["date"] = pd.to_datetime(f["date"])
    key = pd.MultiIndex.from_frame(prices[["symbol", "date"]])
    drop = f[~f["usable"].astype(bool)]
    if len(drop):
        prices = prices[~key.isin(pd.MultiIndex.from_frame(drop[["symbol", "date"]]))]
    keep = f[f["usable"].astype(bool)].set_index(["symbol", "date"])
    if len(keep):
        prices = prices.copy()
        idx = pd.MultiIndex.from_frame(prices[["symbol", "date"]])
        hit = idx.isin(keep.index)
        sel = idx[hit]
        prices.loc[hit, "close"] = keep["close_after"].reindex(sel).to_numpy()
        adj_new = keep["adj_after"].reindex(sel).to_numpy()
        prices["adj_close"] = prices["adj_close"].astype(float)
        prices.loc[hit, "adj_close"] = adj_new
    return prices


# ---------------------------------------------------------------- splits
def fetch_splits(cfg, data: Path, wanted: dict[str, pd.Timestamp], client=None) -> tuple[set[str], Exception | None]:
    """Ask FMP's split history (stable `splits`) for each symbol with a big move not asked since that
    move. Returns (symbols whose history is known, the 402 refusal if there was one)."""
    from .fmp import FmpError, FmpRestricted
    asked_path = data / SPLITS_ASKED
    asked = json.loads(asked_path.read_text()) if asked_path.exists() else {}
    todo = [s for s, last in wanted.items() if s not in asked or pd.Timestamp(asked[s]) < pd.Timestamp(last)]
    known = {s for s in wanted if s in asked}
    if not todo:
        return known, None
    if client is None:
        from .fmp import Fmp
        client = Fmp(cfg)
    p = data / SPLITS
    held = pd.read_parquet(p) if p.exists() else pd.DataFrame(columns=["symbol", "date", "numerator", "denominator"])
    today = dt.date.today().isoformat()
    refused = None
    new = []
    for sym in todo:
        try:
            rows = client.get(SPLIT_ENDPOINT, symbol=sym) or []
        except FmpRestricted as e:
            refused = e
            break
        except FmpError as e:
            log.warning("split history for %s not read: %s", sym, e)
            continue
        rows = rows if isinstance(rows, list) else rows.get("historical", [])
        for r in rows:
            if r.get("date") and r.get("numerator") and r.get("denominator"):
                new.append({"symbol": sym, "date": str(r["date"])[:10], "numerator": float(r["numerator"]),
                            "denominator": float(r["denominator"])})
        held = held[held["symbol"] != sym]
        asked[sym] = today
        known.add(sym)
    if new:
        held = pd.concat([held, pd.DataFrame(new)], ignore_index=True)
    held = held.drop_duplicates(["symbol", "date"], keep="last").sort_values(["symbol", "date"]).reset_index(drop=True)
    held.to_parquet(p, index=False)
    asked_path.write_text(json.dumps(dict(sorted(asked.items())), indent=1))
    if refused is not None:
        return set(), refused       # the endpoint is off the plan: rule 4 reports for everyone
    return known, None


# ---------------------------------------------------------------- the step
def write_report(data: Path, fixes: pd.DataFrame, findings: pd.DataFrame, refused, version: dict | None) -> Path:
    kinds = np.where(fixes["usable"].astype(bool), "changed", "unusable") if len(fixes) else []
    both = pd.concat([fixes.assign(kind=kinds), findings.assign(kind="reported")], ignore_index=True)
    both.to_csv(data / REPORT_CSV, index=False)
    lines = [f"# Price cleaning, {dt.datetime.now():%Y-%m-%d %H:%M}", ""]
    if refused is not None:
        lines += [f"**FMP refused `{SPLIT_ENDPOINT}` (HTTP 402).** Rules 1 to 3 ran; rule 4 only reports.", ""]
    if version:
        lines += [f"Data version {version['version']} ({version['changed']} company/panel changes).", ""]
    by_rule = fixes.groupby("rule").size().to_dict() if len(fixes) else {}
    lines += [f"Rows changed or unusable: {len(fixes)} in {fixes['symbol'].nunique() if len(fixes) else 0} symbols; "
              f"by rule: {by_rule}. Moves only reported: {len(findings)}.", "",
              "## Changed or unusable", "",
              "| symbol | date | close before | close after | adj before | adj after | rule | note |",
              "|---|---|---|---|---|---|---|---|"]
    fmt = lambda v: "" if pd.isna(v) else f"{v:.6g}"          # noqa: E731
    for r in fixes.itertuples():
        lines.append(f"| {r.symbol} | {pd.Timestamp(r.date).date()} | {fmt(r.close_before)} | "
                     f"{fmt(r.close_after) if r.usable else 'unusable'} | {fmt(r.adj_before)} | "
                     f"{fmt(r.adj_after) if r.usable else 'unusable'} | {r.rule} | {r.note} |")
    lines += ["", "## Reported, not changed", "", "| symbol | from | to | series | ratio | finding |", "|---|---|---|---|---|---|"]
    for r in findings.itertuples():
        lines.append(f"| {r.symbol} | {pd.Timestamp(r.date).date()} | {pd.Timestamp(r.to).date()} | {r.series} | "
                     f"{fmt(r.ratio)} | {r.finding} |")
    path = data / REPORT_MD
    path.write_text("\n".join(lines) + "\n")
    return path


def run(cfg, client=None, record_version: bool = True) -> dict:
    """The step: split history, the rules, the fixes file, the data version, the report. Raises
    fmp.FmpRestricted after all that when FMP refused the split endpoint, so the night stops, named."""
    data = Path(cfg.data_dir)
    s = settings(cfg)
    prices = _prices(data)
    wanted = moving_symbols(prices, s)
    known, refused = fetch_splits(cfg, data, wanted, client) if wanted else (set(), None)
    overrides = load_overrides(Path(cfg.get("price_clean", {}).get("overrides")) if (cfg.get("price_clean") or {}).get("overrides") else None)
    fixes, findings = clean(prices, _dividends(data), load_splits(data), overrides, s, known)
    p = data / FIXES
    tmp = p.with_suffix(".tmp")
    fixes.to_parquet(tmp, index=False)
    tmp.replace(p)
    rec = None
    if record_version:
        from .data_version import record
        rec = record(cfg, "price_clean")
    path = write_report(data, fixes, findings, refused, rec)
    log.info("price_clean: %d rows changed or unusable in %d symbols, %d moves reported; report %s",
             len(fixes), fixes["symbol"].nunique() if len(fixes) else 0, len(findings), path)
    out = {"fixes": len(fixes), "unusable": int((~fixes["usable"].astype(bool)).sum()) if len(fixes) else 0,
           "reported": len(findings), "version": rec["version"] if rec else None,
           "changed": rec["changed"] if rec else None, "split_endpoint": "refused" if refused else "ok"}
    if refused is not None:
        raise refused
    return out
