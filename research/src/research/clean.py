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
