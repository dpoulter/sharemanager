"""What an indicator definition is, and the point-in-time context it reads.

A definition is a function (context, date) -> a value per symbol, NaN where
the company has none. It is registered with what it reads, so the indicator
job knows which changes to the data make its stored values stale:

    prices      the close (raw or dividend-adjusted, by the price basis)
    caps        market capitalisation
    statements  accounts, only through pit.Fundamentals (the publication lag)
    fx          exchange rates (accounts in another currency)
    universe    the FTSE 350-like membership (a rank within the universe)
    master      the symbol master (sector)

A definition that reads prices is built once per price basis: the stored
indicator is `<name>@raw` or `<name>@adjusted`. One that does not read
prices is stored once, as `<name>`.

The calendar is "trading" (every trading day) or "daily" (every calendar day,
the last close carried over a weekend or holiday). The N100-style indicators
are daily because the N100's statistics carry rows on some Sundays and
holidays, and its rebalances and the 200-day exit read those rows.

Version: the first 16 hex characters of a SHA-256 over the definition
function's source, the source of every helper it lists in `deps` (the
Context class always, pit.Fundamentals when it reads accounts, the
moving average when it reads one), and a JSON of its settings (price basis,
calendar, the first stored date, publication lags when it reads accounts,
the universe settings when it ranks within the universe). A change to any of
them changes the version, and the job rebuilds that indicator whole.
"""
from __future__ import annotations

import hashlib
import inspect
import json
from dataclasses import dataclass, field
from functools import cached_property
from typing import Callable

import numpy as np
import pandas as pd

from .pit import Fundamentals
from ..store import Market, moving_average

USES = {"prices", "caps", "statements", "fx", "universe", "master"}
BASES = ("raw", "adjusted")


@dataclass
class Definition:
    name: str
    fn: Callable
    uses: frozenset
    calendar: str = "trading"
    cross_sectional: bool = False
    deps: tuple = ()
    unit: str = ""
    source: str = ""          # the paper, or where the N100 defines it

    def __call__(self, ctx, date) -> pd.Series:
        return self.fn(ctx, date)

    @property
    def by_basis(self) -> bool:
        return "prices" in self.uses

    def keys(self) -> list[str]:
        return [key(self.name, b) for b in BASES] if self.by_basis else [self.name]

    def basis_of(self, k: str) -> str:
        return k.split("@", 1)[1] if "@" in k else "adjusted"

    def settings(self, cfg, k: str) -> dict:
        out = {"name": self.name, "calendar": self.calendar, "start": str(cfg["universe"]["start"]),
               "basis": self.basis_of(k) if self.by_basis else None, "uses": sorted(self.uses)}
        if "statements" in self.uses:
            pit = cfg["point_in_time"]
            out["lags"] = [int(pit["annual_lag_months"]), int(pit["half_year_lag_months"])]
        if "universe" in self.uses:
            u = cfg["universe"]
            out["universe"] = {k2: u.get(k2) for k2 in ("size", "start", "include_aim", "rank_with_trusts")}
        return out

    def version(self, cfg, k: str) -> str:
        parts = [_source(self.fn)] + [_source(d) for d in (Context,) + tuple(self.deps)]
        parts.append(json.dumps(self.settings(cfg, k), sort_keys=True, default=str))
        return hashlib.sha256("\n\x00".join(parts).encode()).hexdigest()[:16]


def _source(obj) -> str:
    try:
        return inspect.getsource(obj)
    except (OSError, TypeError):
        return repr(obj)


def key(name: str, basis: str | None) -> str:
    return f"{name}@{basis}" if basis else name


REGISTRY: dict[str, Definition] = {}


def indicator(name: str, uses, calendar: str = "trading", cross_sectional: bool = False, deps=(),
              unit: str = "", source: str = ""):
    uses = frozenset(uses)
    bad = uses - USES
    if bad:
        raise ValueError(f"{name}: unknown inputs {sorted(bad)}")
    if calendar not in ("trading", "daily"):
        raise ValueError(f"{name}: calendar is trading or daily, not {calendar!r}")

    def wrap(fn):
        deps2 = tuple(deps) + ((Fundamentals,) if "statements" in uses else ())
        REGISTRY[name] = Definition(name=name, fn=fn, uses=uses, calendar=calendar,
                                    cross_sectional=cross_sectional or "universe" in uses, deps=deps2,
                                    unit=unit, source=source or " ".join((fn.__doc__ or "").split()))
        fn.signal_name = name
        return fn
    return wrap


def market_on_basis(m: Market, basis: str) -> Market:
    """The market on a price basis: adjusted as loaded, raw with the raw close in place of the adjusted."""
    if basis == "adjusted":
        return m
    if basis != "raw":
        raise ValueError(f"price basis is raw or adjusted, not {basis!r}")
    from dataclasses import replace
    return replace(m, adj=m.close, _ma200=None)


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
        """n100_return_detail's return alone, without the base dates (the same arithmetic)."""
        cols = self.m.adj.columns
        i = self.pos(date)
        if i < 0:
            return pd.Series(np.nan, index=cols)
        back = pd.Timestamp(self.asof.get(pd.Timestamp(date), date)) - pd.DateOffset(months=months)
        j = int(self.m.dates.searchsorted(back, side="left"))
        if j > i:
            return pd.Series(np.nan, index=cols)
        nxt, prv = self._next_prev_valid
        s_idx, e_idx = nxt[j], prv[i]
        ok = np.isfinite(s_idx) & np.isfinite(e_idx) & (s_idx < e_idx)
        a = self._adj_np
        k = np.arange(len(cols))
        sp = a[np.where(ok, s_idx, 0).astype(int), k]
        ep = a[np.where(ok, e_idx, 0).astype(int), k]
        good = ok & (sp > 0)
        return pd.Series(np.where(good, ep / np.where(sp > 0, sp, np.nan) - 1, np.nan), index=cols)

    @cached_property
    def _adj_np(self) -> np.ndarray:
        return self.m.adj.to_numpy()

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
