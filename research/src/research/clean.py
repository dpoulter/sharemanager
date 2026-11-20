"""Bad ticks.

A day move over 50% that comes back to within 20% of the price before it
within 3 sessions is a bad tick (a price jumping 15 times and back, or a
pence/pounds flip for a day or two). Those days are dropped and recorded in
data/bad_ticks.parquet; load_market leaves them out of both the raw and the
adjusted close. A move over 50% that does not come back stays, and is
listed in the data report.
"""
from __future__ import annotations

from pathlib import Path

import numpy as np
import pandas as pd

MOVE = 0.5
BACK_WITHIN = 0.2
SESSIONS = 3


def bad_tick_positions(p: np.ndarray, move: float = MOVE, back: float = BACK_WITHIN,
                       sessions: int = SESSIONS) -> list[int]:
    """Indexes of bad ticks in one price series (NaNs are skipped over)."""
    idx = np.where(np.isfinite(p) & (p > 0))[0]
    v = p[idx]
    if len(v) < 3:
        return []
    jumps = np.where(np.abs(v[1:] / v[:-1] - 1) > move)[0] + 1
    bad: list[int] = []
    covered = -1
    for k in jumps:
        if k <= covered:
            continue
        base = v[k - 1]
        for j in range(k, min(k + sessions, len(v) - 1)):
            if abs(v[j + 1] / base - 1) <= back:
                bad.extend(idx[k: j + 1].tolist())
                covered = j + 1
                break
    return bad


def find_bad_ticks(prices: pd.DataFrame) -> pd.DataFrame:
    """symbol, date, close for every bad tick in a long prices frame."""
    rows = []
    for sym, g in prices.sort_values(["symbol", "date"]).groupby("symbol"):
        close = g["close"].to_numpy(dtype=float)
        for i in bad_tick_positions(close):
            rows.append((sym, g["date"].iloc[i], close[i]))
    return pd.DataFrame(rows, columns=["symbol", "date", "close"])


def clean_prices(data: Path) -> pd.DataFrame:
    data = Path(data)
    p = data / "prices.parquet"
    if not p.exists():
        return pd.DataFrame()
    ticks = find_bad_ticks(pd.read_parquet(p, columns=["symbol", "date", "close"]))
    ticks.to_parquet(data / "bad_ticks.parquet")
    return ticks


# ---------------------------------------------------------------- market caps 100x out
STEP_LO, STEP_HI = 70.0, 140.0
NEIGHBOUR_DAYS = 40            # trading days either side of a segment that its neighbours are measured over
BOTH_LO, BOTH_HI = 80.0, 125.0  # ratio to both neighbours for a rescale (or its inverse)
ONE_LO, ONE_HI = 90.0, 112.0    # ratio to the one neighbour of a segment at either end of the series
ONE_MIN_DAYS = 20              # ... and the segment must be at least this long
FLAT = 3.0                     # largest over median inside a segment that is rescaled
SPIKE_TIMES = 20.0             # a single-day value this many times the median of the 20 days around it
SPIKE_WINDOW = 10              # days each side
CAP_CEILING = 500e9            # final check only: no UK company is worth more than this
CAP_GIANT_LEVEL = 300e9        # above this only the genuine giants below may go
GIANTS = ("AZN", "HSBA", "SHEL", "BP", "ULVR", "GSK", "BATS", "RIO", "LLOY", "BARC", "DGE", "REL", "LSEG", "BHP")


def _is_step(r: np.ndarray) -> np.ndarray:
    return ((r >= STEP_LO) & (r <= STEP_HI)) | ((r >= 1 / STEP_HI) & (r <= 1 / STEP_LO))


def _near(r: float, lo: float, hi: float, inverse: bool) -> bool:
    """r within lo..hi, or within 1/hi..1/lo when `inverse`."""
    return (1 / hi <= r <= 1 / lo) if inverse else (lo <= r <= hi)


def _ratio(seg_median: float, neighbour: np.ndarray) -> float:
    n = neighbour[np.isfinite(neighbour) & (neighbour > 0)]
    return seg_median / float(np.median(n)) if len(n) else float("nan")


def cap_spike_positions(cap: np.ndarray) -> list[int]:
    """Indexes (into `cap`) of single-day spikes: more than 20 times the median of the
    20 valid days around the day (10 before, 10 after), while the days either side of it are not.
    One day only: a run of two or more high days is left for the step check."""
    idx = np.where(np.isfinite(cap) & (cap > 0))[0]
    v = cap[idx]
    if len(v) < 4:
        return []
    w = SPIKE_WINDOW
    pad = np.full(w, np.nan)
    win = np.lib.stride_tricks.sliding_window_view(np.r_[pad, v, pad], 2 * w + 1)
    around = np.delete(win, w, axis=1)                        # the 20 days around, the day itself left out
    enough = np.isfinite(around).sum(axis=1) >= 3
    lim = np.full(len(v), np.inf)
    if enough.any():
        lim[enough] = SPIKE_TIMES * np.nanmedian(around[enough], axis=1)
    hit = v > lim
    prev_hit = np.r_[False, v[:-1] > lim[1:]]
    next_hit = np.r_[v[1:] > lim[:-1], False]
    return [int(i) for i in idx[hit & ~prev_hit & ~next_hit]]


def fix_cap_spikes(caps: pd.DataFrame) -> tuple[pd.DataFrame, pd.DataFrame]:
    """caps: symbol, date, market_cap. A single-day spike (see cap_spike_positions) takes the
    previous day's value (the next day's, for a first row). Returns the frame and one row per
    spike: symbol, date, market_cap, replaced_with, local_median."""
    caps = caps.sort_values(["symbol", "date"]).reset_index(drop=True)
    out = caps["market_cap"].to_numpy(dtype=float).copy()
    rows = []
    for sym, g in caps.groupby("symbol", sort=False):
        ix = g.index.to_numpy()
        orig = out[ix].copy()
        pos = cap_spike_positions(orig)
        if not pos:
            continue
        valid = np.where(np.isfinite(orig) & (orig > 0))[0]
        dates = g["date"].to_numpy()
        bad = set(pos)
        for k in pos:
            j = valid.searchsorted(k)
            prev = [x for x in valid[:j][::-1] if x not in bad]
            nxt = [x for x in valid[j + 1:] if x not in bad]
            src = prev[0] if prev else (nxt[0] if nxt else None)
            if src is None:
                continue
            around = np.r_[orig[valid[max(0, j - SPIKE_WINDOW):j]], orig[valid[j + 1:j + 1 + SPIKE_WINDOW]]]
            rows.append({"symbol": sym, "date": pd.Timestamp(dates[k]), "market_cap": float(orig[k]),
                         "replaced_with": float(orig[src]), "local_median": float(np.median(around))})
            out[ix[k]] = orig[src]
    fixed = caps.copy()
    fixed["market_cap"] = out
    return fixed, pd.DataFrame(rows, columns=["symbol", "date", "market_cap", "replaced_with", "local_median"])


def cap_step_factors(cap: np.ndarray) -> tuple[np.ndarray, list[dict]]:
    """Multipliers (1, 100 or 0.01) per row, and the rescales that were refused.

    A one-day ratio of 70 to 140, or its inverse, splits the series into
    segments (pence read as pounds changes the level by 100 at one step).
    A segment is judged against its neighbours, not against the series: its
    median against the median of the 40 trading days before it and the 40
    after it, outside the segment and not past the next step.

    - Two neighbours: x100 or x0.01 when the ratio to both is within 80 to 125
      (or its inverse) and the segment is flat (largest over median below 3), and
      only if that lands it nearer the series' median than it was (a normal stretch
      between two stretches at the other level is not moved towards them).
    - A segment at either end of the series has one neighbour: the ratio must be
      within 90 to 112 (or its inverse) and the segment at least 20 days long.
      It is measured against the series as already corrected, and only towards a
      neighbour that is at least as long and was not itself refused (that refusal is
      the one logged), so the shorter end of a two-segment series is the one that moves.
    - Anything else that sits at a step against a neighbour is refused and left as
      it was; the refusal records the ratio to each neighbour.

    The 500bn ceiling is not applied here: it is a check on the result (check_cap_ceiling).
    """
    f = np.ones(len(cap))
    rejected: list[dict] = []
    idx = np.where(np.isfinite(cap) & (cap > 0))[0]
    if len(idx) < 2:
        return f, rejected
    v = cap[idx]
    steps = np.where(_is_step(v[1:] / v[:-1]))[0] + 1
    if len(steps) == 0:
        return f, rejected
    bounds = np.r_[0, steps, len(v)]
    segs = list(zip(bounds[:-1], bounds[1:]))
    n = len(v)
    anchor = float(np.median(v))      # only to say which of two 100x-apart levels is the series' own
    w = v.copy()                      # the series as corrected so far
    factor = np.ones(len(segs))
    refused_seg: dict[int, dict] = {}

    def ratios(i: int, series: np.ndarray) -> tuple[float, float, float, float]:
        lo, hi = segs[i]
        med = float(np.median(series[lo:hi]))
        # 40 days either side, but not past the next step: beyond it is a different level, and in
        # a series that flips (11 segments) the median over both levels is in line with neither.
        before = _ratio(med, series[max(lo - NEIGHBOUR_DAYS, segs[i - 1][0]):lo]) if lo > 0 else float("nan")
        after = _ratio(med, series[hi:min(hi + NEIGHBOUR_DAYS, segs[i + 1][1])]) if hi < n else float("nan")
        return med, before, after, float(series[lo:hi].max())

    def refuse(i: int, why: str, before: float, after: float, med: float, mx: float) -> None:
        lo, hi = segs[i]
        refused_seg[i] = {"first_row": int(idx[lo]), "last_row": int(idx[hi - 1]), "factor": float("nan"),
                          "segment_median": med, "segment_max": mx, "ratio_before": before,
                          "ratio_after": after, "reason": why}

    # Pass 1: segments with a neighbour on both sides, judged on the series as it came.
    for i, (lo, hi) in enumerate(segs):
        if lo == 0 or hi == n:
            continue
        med, rb, ra, mx = ratios(i, v)
        if not (_is_step(np.array([rb])) | _is_step(np.array([ra])))[0]:
            continue                                   # not 100x out from either neighbour: left alone, silently
        flat = mx / med < FLAT
        for k, inv in ((0.01, False), (100.0, True)):
            if _near(rb, BOTH_LO, BOTH_HI, inv) and _near(ra, BOTH_LO, BOTH_HI, inv):
                if abs(np.log(med * k / anchor)) >= abs(np.log(med / anchor)):
                    break          # already the series' own level, between two stretches at the other one: left alone
                if flat:
                    factor[i] = k
                else:
                    refuse(i, f"not flat: largest {mx / med:.1f} times the median (limit {FLAT:g})", rb, ra, med, mx)
                break
        else:
            side = "both sides" if not (_near(rb, BOTH_LO, BOTH_HI, False) or _near(rb, BOTH_LO, BOTH_HI, True)
                                        or _near(ra, BOTH_LO, BOTH_HI, False) or _near(ra, BOTH_LO, BOTH_HI, True)) \
                else "one side only (or the two sides disagree on direction)"
            refuse(i, f"ratio to the neighbours outside 80 to 125 on {side}", rb, ra, med, mx)
        if factor[i] != 1:
            w[lo:hi] = v[lo:hi] * factor[i]
    # Pass 2: the two end segments, against the series as corrected.
    for i in sorted({0, len(segs) - 1}):
        lo, hi = segs[i]
        j = 1 if i == 0 else len(segs) - 2             # its one neighbour
        if j == i:
            continue
        med, rb, ra, mx = ratios(i, w)
        r = rb if i > 0 else ra
        if not _is_step(np.array([r]))[0]:
            continue                                   # in line with its neighbour: left alone, silently
        nlo, nhi = segs[j]
        if j in refused_seg:
            continue                                   # the refusal is logged on that neighbour
        elif nhi - nlo < hi - lo:
            continue                                   # the longer end is the reference: the other one moves
        elif hi - lo < ONE_MIN_DAYS:
            refuse(i, f"one neighbour, and only {hi - lo} days (needs {ONE_MIN_DAYS})", rb, ra, med, mx)
        elif _near(r, ONE_LO, ONE_HI, False):
            factor[i] = 0.01
        elif _near(r, ONE_LO, ONE_HI, True):
            factor[i] = 100.0
        else:
            refuse(i, "ratio to its one neighbour outside 90 to 112", rb, ra, med, mx)
        if factor[i] != 1:
            w[lo:hi] = w[lo:hi] * factor[i]
    for i, (lo, hi) in enumerate(segs):
        if factor[i] != 1:
            f[idx[lo:hi]] = factor[i]
    rejected = [refused_seg[i] for i in sorted(refused_seg)]
    return f, rejected


def fix_cap_steps(caps: pd.DataFrame) -> tuple[pd.DataFrame, pd.DataFrame, pd.DataFrame]:
    """caps: symbol, date, market_cap. Returns the corrected frame, one row per rescaled
    symbol, and one row per refused rescale (to be listed in the data report)."""
    caps = caps.sort_values(["symbol", "date"]).reset_index(drop=True)
    out = caps["market_cap"].to_numpy(dtype=float).copy()
    done, refused = [], []
    for sym, g in caps.groupby("symbol", sort=False):
        ix = g.index.to_numpy()
        f, rej = fix_cap_steps_one(out[ix])
        dates = g["date"].to_numpy()
        for r in rej:
            refused.append({"symbol": sym, "from": pd.Timestamp(dates[r["first_row"]]),
                            "to": pd.Timestamp(dates[r["last_row"]]), "factor": r["factor"],
                            "segment_median": r["segment_median"], "segment_max": r["segment_max"],
                            "ratio_before": r["ratio_before"], "ratio_after": r["ratio_after"],
                            "reason": r["reason"]})
        if (f != 1).any():
            changed = f != 1
            before = out[ix][changed]
            out[ix] = out[ix] * f
            done.append({"symbol": sym, "rows": int(changed.sum()),
                         "from": pd.Timestamp(dates[changed].min()), "to": pd.Timestamp(dates[changed].max()),
                         "factor_min": float(f[changed].min()), "factor_max": float(f[changed].max()),
                         "largest_before": float(np.nanmax(before)),
                         "largest_after": float(np.nanmax(out[ix][changed])),
                         "correction": float(np.nanmax(np.abs(before - out[ix][changed])))})
    fixed = caps.copy()
    fixed["market_cap"] = out
    cols = ["symbol", "from", "to", "factor", "segment_median", "segment_max", "ratio_before", "ratio_after", "reason"]
    return fixed, pd.DataFrame(done), pd.DataFrame(refused, columns=cols)


def fix_cap_steps_one(cap: np.ndarray) -> tuple[np.ndarray, list[dict]]:
    return cap_step_factors(cap)


def clean_market_caps(caps: pd.DataFrame) -> tuple[pd.DataFrame, pd.DataFrame, pd.DataFrame, pd.DataFrame]:
    """Spikes first (so a one-day 267bn value cannot make a flat segment look unflat), then the
    pence/pounds rescale. Returns the frame, rescaled, refused and spikes."""
    caps, spikes = fix_cap_spikes(caps)
    fixed, done, refused = fix_cap_steps(caps)
    return fixed, done, refused, spikes


def cap_ceiling_offenders(caps: pd.DataFrame, giants=GIANTS) -> pd.DataFrame:
    """Rows of a long (symbol, date, market_cap) frame, in pounds, above 500bn, or above 300bn
    for a symbol that is not a genuine giant. Nothing is dropped; the caller fails the build."""
    base = caps["symbol"].astype(str).str.replace(r"\.L$", "", regex=True)
    over = (caps["market_cap"] > CAP_CEILING) | ((caps["market_cap"] > CAP_GIANT_LEVEL) & ~base.isin(list(giants)))
    return caps.loc[over, ["symbol", "date", "market_cap"]].sort_values(["symbol", "date"]).reset_index(drop=True)


class CapCeilingError(RuntimeError):
    pass


# ---------------------------------------------------------------- the price x shares referee
PENCE_UNITS = {"GBp", "GBX", "GBx"}
RESCUE_LO, RESCUE_HI = 80.0, 125.0     # cap over price x shares, for a refused segment (or its inverse)
OVERRIDE_ACTIONS = ("drop", "multiply", "price_x_shares")


def _shares_at(st: pd.DataFrame, dates: pd.DatetimeIndex) -> np.ndarray:
    """Weighted average shares out held from each period end to the next (the first one back to the start)."""
    st = st.sort_values("period_end")
    pe = pd.to_datetime(st["period_end"]).to_numpy()
    i = np.clip(np.searchsorted(pe, dates.to_numpy(), side="right") - 1, 0, len(pe) - 1)
    return st["weightedAverageShsOut"].to_numpy(dtype=float)[i]


def _unit(quote: str | None) -> float | None:
    if quote in (None, "") or (isinstance(quote, float) and np.isnan(quote)):
        return 0.01                     # no quote on record: the London default
    return 0.01 if quote in PENCE_UNITS else (1.0 if quote == "GBP" else None)


def _load_for_referee(data: Path, symbols) -> tuple[pd.DataFrame, pd.DataFrame, pd.DataFrame, pd.Series] | None:
    data = Path(data)
    need = [data / n for n in ("market_cap.parquet", "prices.parquet", "statements.parquet", "symbols.parquet")]
    if not all(p.exists() for p in need):
        return None
    caps = pd.read_parquet(need[0])
    caps["date"] = pd.to_datetime(caps["date"])
    px = pd.read_parquet(need[1], columns=["symbol", "date", "close"])
    px = px[px["symbol"].isin(list(symbols))]
    px["date"] = pd.to_datetime(px["date"])
    st = pd.read_parquet(need[2], columns=["symbol", "period_end", "weightedAverageShsOut"])
    st = st[st["symbol"].isin(list(symbols))].dropna(subset=["weightedAverageShsOut"])
    st = st[st["weightedAverageShsOut"] > 0]
    master = pd.read_parquet(need[3])
    quote = master.set_index("symbol")["quote_currency"] if "quote_currency" in master else pd.Series(dtype=object)
    return caps, px, st, quote


def _cap_over_price_shares(caps_sym: pd.DataFrame, px_sym: pd.DataFrame, st_sym: pd.DataFrame,
                           unit: float) -> pd.DataFrame:
    """date, market_cap, implied (price x shares, pounds), ratio, for the dates held in both."""
    j = caps_sym.merge(px_sym[["date", "close"]].drop_duplicates("date", keep="last"), on="date", how="inner")
    if j.empty or st_sym.empty:
        return j.assign(implied=np.nan, ratio=np.nan)
    j["implied"] = j["close"] * unit * _shares_at(st_sym, pd.DatetimeIndex(j["date"]))
    j["ratio"] = j["market_cap"] / j["implied"]
    return j


def rescue_refused_caps(data: Path) -> pd.DataFrame:
    """A segment the neighbour test refused is rescaled when price x shares says the same thing.

    Its median cap over price x shares outstanding (from the statements) must be within 80 to 125
    times, or its inverse, and flat (every day within 3 times of the median). The two tests are
    independent, so agreement is strong evidence of a pence/pounds slip, where a neighbour ratio
    of 64 or 139 (real moves over a 40-day window) is not. Only refused segments are looked at.
    Run after refine_master (it needs the quote currency). Rewrites market_cap.parquet, drops the
    rescued rows from cap_rescale_refused.parquet, adds price_shares_ratio to the ones left, and
    writes cap_rescued_by_shares.parquet. Returns the rescued segments.
    """
    data = Path(data)
    cols = ["symbol", "from", "to", "factor", "price_shares_ratio", "rows", "reason"]
    rp = data / "cap_rescale_refused.parquet"
    if not rp.exists():
        return pd.DataFrame(columns=cols)
    refused = pd.read_parquet(rp)
    got = _load_for_referee(data, refused["symbol"].unique()) if len(refused) else None
    if got is None:
        return pd.DataFrame(columns=cols)
    caps, px, st, quote = got
    log, ratio_left = [], {}
    for k, r in refused.iterrows():
        sym = r["symbol"]
        unit = _unit(quote.get(sym))
        sel = (caps["symbol"] == sym) & (caps["date"] >= r["from"]) & (caps["date"] <= r["to"])
        if unit is None or not sel.any():
            continue
        j = _cap_over_price_shares(caps.loc[sel, ["date", "market_cap"]], px[px["symbol"] == sym],
                                   st[st["symbol"] == sym], unit)
        x = j["ratio"].to_numpy(dtype=float)
        x = x[np.isfinite(x) & (x > 0)]
        if not len(x):
            continue
        med = float(np.median(x))
        ratio_left[k] = med
        flat = x.max() / med < FLAT and med / x.min() < FLAT
        for fct, inv in ((0.01, False), (100.0, True)):
            if flat and _near(med, RESCUE_LO, RESCUE_HI, inv):
                caps.loc[sel, "market_cap"] = caps.loc[sel, "market_cap"] * fct
                log.append({"symbol": sym, "from": r["from"], "to": r["to"], "factor": fct,
                            "price_shares_ratio": med, "rows": int(sel.sum()),
                            "reason": f"neighbour test: {r['reason']}"})
                ratio_left.pop(k)
                refused = refused.drop(index=k)
                break
    out = pd.DataFrame(log, columns=cols)
    if len(out):
        caps.to_parquet(data / "market_cap.parquet")
    refused = refused.assign(price_shares_ratio=pd.Series(ratio_left).reindex(refused.index))
    refused.to_parquet(rp)
    out.to_parquet(data / "cap_rescued_by_shares.parquet")
    return out


def apply_cap_overrides(data: Path) -> pd.DataFrame:
    """data/reference/cap_overrides.csv: symbol, from, to, action, value, reason. Dale's decisions, one row each.

    drop: the rows are removed (the line has no cap for those dates).
    multiply: the cap times `value`.
    price_x_shares: the cap becomes price x shares outstanding (statements), for the dates where both are held.
    Every row applied is written to cap_overrides_applied.parquet and listed in the data report.
    """
    data = Path(data)
    f = data / "reference" / "cap_overrides.csv"
    cols = ["symbol", "from", "to", "action", "value", "rows", "reason"]
    if not f.exists():
        return pd.DataFrame(columns=cols)
    ov = pd.read_csv(f, dtype=str).fillna("")
    ov.columns = [c.strip().lower() for c in ov.columns]
    bad = ov[~ov["action"].isin(OVERRIDE_ACTIONS)]
    if len(bad):
        raise ValueError(f"cap_overrides.csv: action must be one of {OVERRIDE_ACTIONS}: {bad['action'].tolist()}")
    got = _load_for_referee(data, ov["symbol"].unique())
    if got is None:
        return pd.DataFrame(columns=cols)
    caps, px, st, quote = got
    log = []
    for _, r in ov.iterrows():
        lo, hi = pd.Timestamp(r["from"]), pd.Timestamp(r["to"])
        sel = (caps["symbol"] == r["symbol"]) & (caps["date"] >= lo) & (caps["date"] <= hi)
        n = int(sel.sum())
        if r["action"] == "drop":
            caps = caps[~sel]
        elif r["action"] == "multiply":
            caps.loc[sel, "market_cap"] = caps.loc[sel, "market_cap"] * float(r["value"])
        else:
            unit = _unit(quote.get(r["symbol"]))
            j = _cap_over_price_shares(caps.loc[sel, ["date", "market_cap"]], px[px["symbol"] == r["symbol"]],
                                       st[st["symbol"] == r["symbol"]], unit or 0.01)
            ok = j.dropna(subset=["implied"]).set_index("date")["implied"]
            have = caps.loc[sel, "date"].isin(ok.index)
            caps.loc[sel & ~caps["date"].isin(ok.index), "market_cap"] = np.nan   # no price or shares: no cap
            caps.loc[sel & caps["date"].isin(ok.index), "market_cap"] = \
                caps.loc[sel & caps["date"].isin(ok.index), "date"].map(ok).to_numpy()
            caps = caps.dropna(subset=["market_cap"])
            n = int(have.sum())
        log.append({"symbol": r["symbol"], "from": lo, "to": hi, "action": r["action"],
                    "value": r.get("value", ""), "rows": n, "reason": r.get("reason", "")})
    caps.to_parquet(data / "market_cap.parquet")
    out = pd.DataFrame(log, columns=cols)
    out.to_parquet(data / "cap_overrides_applied.parquet")
    return out
