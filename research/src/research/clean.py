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
    after it, outside the segment.

    - Two neighbours: x100 or x0.01 when the ratio to both is within 80 to 125
      (or its inverse) and the segment is flat (largest over median below 3).
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
    w = v.copy()                      # the series as corrected so far
    factor = np.ones(len(segs))
    refused_seg: dict[int, dict] = {}

    def ratios(i: int, series: np.ndarray) -> tuple[float, float, float, float]:
        lo, hi = segs[i]
        med = float(np.median(series[lo:hi]))
        before = _ratio(med, series[max(0, lo - NEIGHBOUR_DAYS):lo]) if lo > 0 else float("nan")
        after = _ratio(med, series[hi:hi + NEIGHBOUR_DAYS]) if hi < n else float("nan")
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
                if flat:
                    factor[i] = k
                else:
                    refuse(i, f"not flat: largest {mx / med:.1f} times the median (limit {FLAT:g})", rb, ra, med, mx)
                break
        else:
            refuse(i, "ratio to the neighbours outside 80 to 125 on both sides", rb, ra, med, mx)
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
