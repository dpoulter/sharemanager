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
        # decision day -> the N100's as-of date, where they differ (the as-of date is a holiday or a
        # weekend and the decision is taken on the last close before it). The N100 counts its
        # lookback back from the as-of date, not from the close it uses.
        self.asof: dict = {}

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
        return self.m.ma200

    @cached_property
    def _next_prev_valid(self) -> tuple[np.ndarray, np.ndarray]:
        """For every row and share: the position of the first price at or after it,
        and of the last price at or before it, in the share's own price series."""
        a = self.m.adj
        pos = np.where(a.notna().to_numpy(), np.arange(len(a), dtype=np.float32)[:, None], np.nan).astype(np.float32)
        frame = pd.DataFrame(pos)
        return frame.bfill().to_numpy(), frame.ffill().to_numpy()

    def n100_return_detail(self, date, months: int = 6) -> pd.DataFrame:
        """The N100's momentum, as calc_momentum() and backfill_trend_statistics.php define it.

        Calendar months, not trading days: from the FIRST price on or AFTER
        (date - N months) to the LAST price on or BEFORE the date, each from
        the share's own prices (a day it has none is skipped, not filled), and
        only where the start is before the end. A share with less history than
        the window gets the return over the history it has. Returns a fraction
        (the N100 stores per cent) with the two base dates.
        """
        cols = self.m.adj.columns
        i = self.pos(date)
        out = pd.DataFrame(index=cols, columns=["ret", "start", "end"], dtype=object)
        out["ret"] = np.nan
        if i < 0:
            return out
        back = pd.Timestamp(self.asof.get(pd.Timestamp(date), date)) - pd.DateOffset(months=months)
        j = int(self.m.dates.searchsorted(back, side="left"))      # first panel date on or after
        if j > i:
            return out
        nxt, prv = self._next_prev_valid
        s_idx, e_idx = nxt[j], prv[i]
        ok = np.isfinite(s_idx) & np.isfinite(e_idx) & (s_idx < e_idx)
        a = self.m.adj.to_numpy()
        k = np.arange(len(cols))
        si = np.where(ok, s_idx, 0).astype(int)
        ei = np.where(ok, e_idx, 0).astype(int)
        sp, ep = a[si, k], a[ei, k]
        good = ok & (sp > 0)
        out["ret"] = np.where(good, ep / np.where(sp > 0, sp, np.nan) - 1, np.nan)
        dates = self.m.dates
        out["start"] = np.where(good, dates[si].strftime("%Y-%m-%d"), None)
        out["end"] = np.where(good, dates[ei].strftime("%Y-%m-%d"), None)
        return out

    def n100_return(self, date, months: int = 6) -> pd.Series:
        return self.n100_return_detail(date, months)["ret"].astype(float)

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
