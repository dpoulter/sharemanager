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


def cap_step_factors(cap: np.ndarray) -> np.ndarray:
    """Multipliers that line every segment of one cap series up with its latest level.

    A one-day ratio between 70 and 140 (or its inverse) is pence read as pounds,
    not a market move. Walking back from the latest value, each such step
    rescales everything before it by 100, so the latest level is taken as right.
    """
    f = np.ones(len(cap))
    idx = np.where(np.isfinite(cap) & (cap > 0))[0]
    if len(idx) < 2:
        return f
    v = cap[idx]
    r = v[1:] / v[:-1]                       # ratio at each step, later / earlier
    step = np.ones(len(v))
    up = (r >= STEP_LO) & (r <= STEP_HI)     # earlier 100x too small
    down = (r >= 1 / STEP_HI) & (r <= 1 / STEP_LO)   # earlier 100x too big
    step[1:][up] = 100.0
    step[1:][down] = 0.01
    # factor for position k = product of steps after k
    cum = np.cumprod(step[::-1])[::-1]
    fk = np.r_[cum[1:], 1.0]
    f[idx] = fk
    return f


def fix_cap_steps(caps: pd.DataFrame) -> tuple[pd.DataFrame, pd.DataFrame]:
    """caps: symbol, date, market_cap. Returns the corrected frame and one row per rescaled run."""
    caps = caps.sort_values(["symbol", "date"]).reset_index(drop=True)
    out = caps["market_cap"].to_numpy(dtype=float).copy()
    log_rows = []
    for sym, g in caps.groupby("symbol", sort=False):
        ix = g.index.to_numpy()
        f = cap_step_factors(out[ix])
        if (f != 1).any():
            changed = f != 1
            before = out[ix][changed]
            out[ix] = out[ix] * f
            dates = g["date"].to_numpy()[changed]
            log_rows.append({"symbol": sym, "rows": int(changed.sum()), "from": pd.Timestamp(dates.min()),
                             "to": pd.Timestamp(dates.max()), "factor_min": float(f[changed].min()),
                             "factor_max": float(f[changed].max()),
                             "largest_before": float(np.nanmax(before)),
                             "largest_after": float(np.nanmax(out[ix][changed])),
                             "correction": float(np.nanmax(np.abs(before - out[ix][changed])))})
    fixed = caps.copy()
    fixed["market_cap"] = out
    return fixed, pd.DataFrame(log_rows)
