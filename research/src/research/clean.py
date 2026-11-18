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
CAP_CEILING = 500e9            # no UK company is worth more than this: a rescale that gets there is wrong
MULTIPLY_FLOOR = 1e6           # a segment already above 1m is not 100x too small


def _is_step(r: np.ndarray) -> np.ndarray:
    return ((r >= STEP_LO) & (r <= STEP_HI)) | ((r >= 1 / STEP_HI) & (r <= 1 / STEP_LO))


def cap_step_factors(cap: np.ndarray) -> tuple[np.ndarray, list[dict]]:
    """Multipliers (1, 100 or 0.01) per row, and the rescales that were refused.

    A one-day ratio of 70 to 140, or its inverse, splits the series into
    segments (pence read as pounds changes the level by 100 at one step).
    Each segment is then put at whichever of x1, x100, x0.01 lands its median
    nearest the median of the whole series, so the anchor is the symbol's
    own typical level, not its last value (which anchoring on a tail broke:
    a few good series came out hundreds of trillions).

    Refused, and left as they were: a x100 on a segment whose median is
    already above 1m, and any rescale that would take a value above 500bn.
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
    anchor = float(np.median(v))
    for lo, hi in zip(bounds[:-1], bounds[1:]):
        seg = v[lo:hi]
        med = float(np.median(seg))
        best = min((1.0, 100.0, 0.01), key=lambda k: (abs(np.log(med * k / anchor)), k != 1.0))
        if best == 1.0:
            continue
        reason = None
        if best == 100.0 and med > MULTIPLY_FLOOR:
            reason = f"would multiply a segment whose median {med:,.0f} is already above {MULTIPLY_FLOOR:,.0f}"
        elif float(seg.max()) * best > CAP_CEILING:
            reason = f"would give a cap of {seg.max() * best:,.0f}, above {CAP_CEILING:,.0f}"
        if reason:
            rejected.append({"first_row": int(idx[lo]), "last_row": int(idx[hi - 1]), "factor": best,
                             "segment_median": med, "segment_max": float(seg.max()), "reason": reason})
            continue
        f[idx[lo:hi]] = best
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
    return fixed, pd.DataFrame(done), pd.DataFrame(refused)


def fix_cap_steps_one(cap: np.ndarray) -> tuple[np.ndarray, list[dict]]:
    return cap_step_factors(cap)
