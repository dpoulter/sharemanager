from __future__ import annotations

from functools import cached_property
from typing import Callable

import numpy as np
import pandas as pd

from ..pit import Fundamentals
from ..store import Market

REGISTRY: dict[str, Callable] = {}


def signal(name: str):
    def wrap(fn):
        REGISTRY[name] = fn
        fn.signal_name = name
        return fn
    return wrap


class Context:
    """What a signal may look at: prices up to the date and accounts usable by it.

    Signals must only index panels with `.loc[:date]` (or the helpers here),
    never past the date.
    """

    def __init__(self, m: Market, fundamentals: Fundamentals, member: pd.DataFrame | None = None):
        self.m = m
        self.f = fundamentals
        self.member = member

    @cached_property
    def adj_filled(self) -> pd.DataFrame:
        return self.m.adj.ffill(limit=10)

    @cached_property
    def cap_filled(self) -> pd.DataFrame:
        return self.m.market_cap.ffill(limit=10)

    @cached_property
    def daily_returns(self) -> pd.DataFrame:
        return self.adj_filled.pct_change(fill_method=None)

    @cached_property
    def ma200(self) -> pd.DataFrame:
        return self.m.adj.rolling(200, min_periods=200).mean()

    def pos(self, date) -> int:
        return int(self.m.dates.searchsorted(pd.Timestamp(date), side="right") - 1)

    def adj_at(self, date, sessions_back: int = 0) -> pd.Series:
        i = self.pos(date) - sessions_back
        if i < 0:
            return pd.Series(np.nan, index=self.m.adj.columns)
        return self.adj_filled.iloc[i]

    def adj_months_back(self, date, months: int) -> pd.Series:
        """The adjusted close on the last trading day on or before date - months."""
        back = pd.Timestamp(date) - pd.DateOffset(months=months)
        return self.adj_at(back)

    def market_cap(self, date) -> pd.Series:
        i = self.pos(date)
        return self.cap_filled.iloc[max(i, 0)]

    def to_gbp(self, frame: pd.DataFrame, columns: list[str], date) -> pd.DataFrame:
        """Statement figures in the reporting currency -> pounds at the date's rate."""
        out = frame.copy()
        cur = out.get("reportedCurrency", pd.Series("GBP", index=out.index)).fillna("GBP")
        rate = cur.map(lambda c: self.m.fx_rate(c, pd.Timestamp(date)) if c != "GBP" else 1.0)
        for c in columns:
            if c in out:
                out[c] = pd.to_numeric(out[c], errors="coerce") * rate
        return out


def pct_rank(s: pd.Series) -> pd.Series:
    return s.rank(pct=True)


def blend(ctx: Context, date, parts: list[tuple[Callable, float]], universe: list[str]) -> pd.Series:
    """Weighted average of the percentile ranks of several signals, within the universe."""
    total = None
    weights = None
    for fn, w in parts:
        r = pct_rank(fn(ctx, date).reindex(universe)) * w
        present = r.notna().astype(float) * w
        total = r.fillna(0) if total is None else total + r.fillna(0)
        weights = present if weights is None else weights + present
    out = total / weights.replace(0, np.nan)
    return out
