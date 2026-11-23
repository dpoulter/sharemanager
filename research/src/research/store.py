"""Load the Parquet files into the wide panels the engine and signals use.

Everything the engine sees is in pounds. FMP quotes London lines in GBp
(pence); a few lines are quoted in GBP, USD or EUR. The raw close is kept in
its own unit too, because the brief's pounds/pence rule and minimum order
sizes work on it.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from pathlib import Path

import numpy as np
import pandas as pd

PENCE = {"GBp", "GBX", "GBx"}


@dataclass
class Market:
    """Prices and caps as date x symbol frames, plus the symbol master."""
    close: pd.DataFrame           # raw close, pounds
    adj: pd.DataFrame             # dividend-adjusted close, pounds-equivalent
    market_cap: pd.DataFrame      # pounds
    master: pd.DataFrame          # indexed by symbol
    statements: pd.DataFrame = field(default_factory=pd.DataFrame)
    fx: pd.DataFrame = field(default_factory=pd.DataFrame)   # date x currency, GBP per unit
    last_price: pd.Series = field(default_factory=pd.Series)  # symbol -> last date with a price
    gone: pd.Series = field(default_factory=pd.Series)        # symbol -> True if it stopped trading
    volume: pd.DataFrame | None = None                         # shares traded, for choosing between two lines
    # One row per dividend: symbol, ex_date, pay_date (the ex-date where FMP has none), amount (pounds a
    # share, in the share's quote unit turned to pounds as its prices are), kind ('cash', or 'scrip' /
    # 'stock' where FMP says so). Credited to cash by engine.simulate on the raw price basis only.
    dividends: pd.DataFrame = field(default_factory=pd.DataFrame)
    _ma200: pd.DataFrame | None = None

    @property
    def ma200(self) -> pd.DataFrame:
        if self._ma200 is None:
            self._ma200 = moving_average(self.adj, 200)
        return self._ma200

    @property
    def dates(self) -> pd.DatetimeIndex:
        return self.close.index

    def fx_rate(self, currency: str, date) -> float:
        if not currency or currency in ("GBP",) or currency in PENCE:
            return 1.0 / 100 if currency in PENCE else 1.0
        if self.fx.empty or currency not in self.fx.columns:
            return np.nan
        s = self.fx[currency].loc[:date].dropna()
        return float(s.iloc[-1]) if len(s) else np.nan


def quote_currency(master: pd.DataFrame) -> pd.Series:
    """The currency a line is QUOTED in.

    master.py infers it from the prices (quote_currency). FMP's profile
    `currency` is the reporting currency for some London lines, so it is
    only a fallback, and only when it is a sterling unit.
    """
    if "quote_currency" in master:
        return master["quote_currency"].fillna("GBp")
    cur = master.get("currency", pd.Series("GBp", index=master.index)).fillna("GBp")
    return cur.where(cur.isin(list(PENCE | {"GBP"})), "GBp")


def to_pounds_factor(master: pd.DataFrame, fx: pd.DataFrame, dates: pd.DatetimeIndex,
                     column: str | None = None) -> pd.DataFrame:
    """date x symbol multiplier from the quote unit to pounds."""
    cur = (master[column] if column else quote_currency(master)).fillna("GBp")
    out = pd.DataFrame(1.0, index=dates, columns=master.index)
    pence = cur[cur.isin(PENCE)].index
    out[pence] = 0.01
    for c in sorted(set(cur) - PENCE - {"GBP"}):
        cols = cur[cur == c].index
        if not fx.empty and c in fx.columns:
            rate = fx[c].reindex(dates).ffill()
            out[cols] = np.repeat(rate.to_numpy()[:, None], len(cols), axis=1)
        else:
            out[cols] = np.nan
    return out


DIVIDEND_COLUMNS = ["symbol", "ex_date", "pay_date", "amount", "kind"]


def dividends_in_pounds(raw: pd.DataFrame, factor: pd.DataFrame) -> pd.DataFrame:
    """Stored dividends (amount in the share's quote unit) -> pounds a share, by the same unit rule as
    the share's prices (factor: date x symbol, from to_pounds_factor), at the ex-date."""
    if raw is None or raw.empty:
        return pd.DataFrame(columns=DIVIDEND_COLUMNS)
    d = raw.copy()
    d = d[d["symbol"].isin(factor.columns)]
    d["ex_date"] = pd.to_datetime(d["ex_date"])
    pay = pd.to_datetime(d["payment_date"], errors="coerce") if "payment_date" in d else pd.Series(pd.NaT, index=d.index)
    d["pay_date"] = pay.fillna(d["ex_date"])
    kind = d["kind"].astype(str).str.lower() if "kind" in d else pd.Series("cash", index=d.index)
    d["kind"] = kind.where(~kind.isin(["", "nan", "none"]), "cash")
    rows = factor.index.searchsorted(d["ex_date"].to_numpy(), side="right") - 1
    cols = factor.columns.get_indexer(d["symbol"])
    f = factor.to_numpy()
    unit = np.where(rows >= 0, f[np.clip(rows, 0, None), cols], f[0, cols]) if len(factor) else np.nan
    d["amount"] = pd.to_numeric(d["amount"], errors="coerce") * unit
    d = d.drop_duplicates(["symbol", "ex_date"], keep="last").sort_values(["ex_date", "symbol"])
    return d[DIVIDEND_COLUMNS].reset_index(drop=True)


def load_dividends(data: Path, factor: pd.DataFrame) -> pd.DataFrame:
    p = Path(data) / "dividends.parquet"
    return dividends_in_pounds(pd.read_parquet(p) if p.exists() else None, factor)


def load_market(data: Path, symbols: list[str] | None = None) -> Market:
    data = Path(data)
    master = pd.read_parquet(data / "symbols.parquet").set_index("symbol")
    prices = pd.read_parquet(data / "prices.parquet")
    if symbols is not None:
        prices = prices[prices["symbol"].isin(symbols)]
    prices["date"] = pd.to_datetime(prices["date"])
    prices = prices.drop_duplicates(["symbol", "date"], keep="last")
    ticks_path = data / "bad_ticks.parquet"
    if ticks_path.exists():
        t = pd.read_parquet(ticks_path, columns=["symbol", "date"])
        if len(t):
            t["date"] = pd.to_datetime(t["date"])
            key = pd.MultiIndex.from_frame(prices[["symbol", "date"]])
            prices = prices[~key.isin(pd.MultiIndex.from_frame(t))]
    fixes_path = data / "price_fixes.parquet"
    if fixes_path.exists():
        # price_clean owns "is this price usable": its fixes, and its unusable rows dropped (no price).
        from .price_clean import apply_fixes
        prices = apply_fixes(prices, pd.read_parquet(fixes_path))
    close = prices.pivot(index="date", columns="symbol", values="close").sort_index()
    adj = prices.pivot(index="date", columns="symbol", values="adj_close").sort_index()
    adj = adj.where(adj.notna(), close)

    fx = pd.DataFrame()
    if (data / "fx.parquet").exists():
        f = pd.read_parquet(data / "fx.parquet")
        if len(f):
            f["date"] = pd.to_datetime(f["date"])
            fx = f.pivot(index="date", columns="currency", values="gbp_per_unit").sort_index()
    master = master.reindex(master.index.union(close.columns))
    factor = to_pounds_factor(master.loc[close.columns], fx, close.index)
    close = close * factor
    adj = adj * factor

    mcap = pd.DataFrame(index=close.index, columns=close.columns, dtype=float)
    if (data / "market_cap.parquet").exists():
        m = pd.read_parquet(data / "market_cap.parquet")
        m["date"] = pd.to_datetime(m["date"])
        m = m[m["symbol"].isin(close.columns)].drop_duplicates(["symbol", "date"], keep="last")
        mcap = m.pivot(index="date", columns="symbol", values="market_cap").reindex(index=close.index, columns=close.columns)
        # The cap's currency is checked against price x shares in master.py;
        # it is the reporting currency for a few lines, pounds for the rest.
        if "cap_currency" in master:
            ccy = master.loc[close.columns, "cap_currency"].fillna("GBP")
        else:
            ccy = quote_currency(master.loc[close.columns]).replace({"GBp": "GBP", "GBX": "GBP", "GBx": "GBP"})
        cap_factor = to_pounds_factor(pd.DataFrame({"c": ccy}), fx, close.index, column="c")
        corr_path = data / "cap_lse_corrections.parquet"
        mcap = mcap * cap_factor
        if corr_path.exists():
            from .lse import apply_cap_corrections
            apply_cap_corrections(mcap, pd.read_parquet(corr_path))

    statements = pd.DataFrame()
    if (data / "statements.parquet").exists():
        statements = pd.read_parquet(data / "statements.parquet")

    volume = None
    if "volume" in prices:
        volume = prices.pivot(index="date", columns="symbol", values="volume").sort_index() \
            .reindex(index=close.index, columns=close.columns)
    dividends = load_dividends(data, factor)
    m = Market(close=close, adj=adj, market_cap=mcap, master=master, statements=statements, fx=fx, volume=volume,
               dividends=dividends)
    return finish_market(drop_thin_dates(m))


def drop_thin_dates(m: Market, share: float = 0.3) -> Market:
    """Keep only real trading days.

    The panel's calendar is every date any line has a price on. FMP has stray
    rows on holidays and weekends for a few lines, and on those dates nearly
    every share is missing: a 200-day average over the panel would have a
    hole in it, and a rebalance falling there would find nothing priced.
    A date is a trading day if at least `share` of the lines priced around
    it (the median count over a month) have a close.
    """
    if m.close.empty:
        return m
    count = m.close.notna().sum(axis=1)
    typical = count.rolling(21, center=True, min_periods=1).median()
    keep = count >= share * typical
    if keep.all():
        return m
    m.close, m.adj = m.close[keep], m.adj[keep]
    m.market_cap = m.market_cap.reindex(m.close.index)
    if m.volume is not None:
        m.volume = m.volume.reindex(m.close.index)
    return m


def moving_average(adj: pd.DataFrame, n: int = 200) -> pd.DataFrame:
    """Each share's average of its own last n closes, as the N100 computes it.

    Over its own prices, not over the panel's rows: a day a share has no
    price (a dropped bad tick, a suspension) does not blank its average for
    the next n days, which rolling(n, min_periods=n) on the panel would.
    """
    out = {}
    for c in adj.columns:
        s = adj[c].dropna()
        out[c] = s.rolling(n, min_periods=n).mean().reindex(adj.index).ffill(limit=10)
    return pd.DataFrame(out, index=adj.index).where(adj.ffill(limit=10).notna())


def finish_market(m: Market, end_slack_sessions: int = 10) -> Market:
    """Work out each symbol's last price and whether it has stopped trading."""
    last = m.close.apply(lambda s: s.last_valid_index())
    m.last_price = last
    end = m.close.index[-1] if len(m.close.index) else None
    pos = {d: i for i, d in enumerate(m.close.index)}
    delisted = m.master.get("delisted_date", pd.Series(index=m.master.index, dtype=object))
    gone = {}
    for sym, d in last.items():
        if d is None or pd.isna(d):
            gone[sym] = False
            continue
        flagged = sym in delisted.index and pd.notna(delisted.get(sym))
        stale = end is not None and (pos[end] - pos[d]) >= end_slack_sessions
        gone[sym] = bool(flagged or stale)
    m.gone = pd.Series(gone, dtype=bool)
    return m
