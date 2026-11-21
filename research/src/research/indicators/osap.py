"""The first ten signals, from Open Source Asset Pricing (Chen and Zimmermann,
"Open Source Cross-Sectional Asset Pricing", Critical Finance Review 2022;
definitions at openassetpricing.com). Each is adapted to UK reporting, where
companies publish half-yearly: flows are trailing twelve months built from
the two latest halves where both are usable, otherwise the latest annual.

Every value is oriented so that higher is what the paper found to earn more.
Accounts are only those usable by the date (see pit.py).

Missing data: a company whose input field is missing (a bank has no revenue
or gross profit line; an interim row may have no balance sheet) gets no
score for that signal and is skipped. A missing field is never read as
zero. Each run's report shows how many universe members had a score on
each rebalance date.
"""
from __future__ import annotations

import numpy as np
import pandas as pd

from .base import Context, indicator


@indicator("momentum_12_1", uses={"prices"})
def momentum_12_1(ctx: Context, date) -> pd.Series:
    """Return from 12 months to 1 month before the date.

    Jegadeesh, N. and Titman, S. (1993), "Returns to Buying Winners and Selling
    Losers", Journal of Finance 48(1). OSAP: Mom12m.
    """
    return ctx.adj_months_back(date, 1) / ctx.adj_months_back(date, 12) - 1


@indicator("reversal_1m", uses={"prices"})
def reversal_1m(ctx: Context, date) -> pd.Series:
    """Minus the last month's return (last month's losers do better).

    Jegadeesh, N. (1990), "Evidence of Predictable Behavior of Security
    Returns", Journal of Finance 45(3). OSAP: STreversal.
    """
    return -(ctx.adj_at(date) / ctx.adj_months_back(date, 1) - 1)


@indicator("high_52w", uses={"prices"})
def high_52w(ctx: Context, date) -> pd.Series:
    """Price relative to its highest close in the last 52 weeks.

    George, T. and Hwang, C. (2004), "The 52-Week High and Momentum Investing",
    Journal of Finance 59(5). OSAP: High52.
    """
    i = ctx.pos(date)
    window = ctx.m.adj.iloc[max(0, i - 251): i + 1]
    hi = window.max()
    enough = window.notna().sum() >= 200
    return (ctx.adj_at(date) / hi).where(enough)


@indicator("low_volatility", uses={"prices"})
def low_volatility(ctx: Context, date) -> pd.Series:
    """Minus the volatility of daily returns over the last 12 months.

    Ang, A., Hodrick, R., Xing, Y. and Zhang, X. (2006), "The Cross-Section of
    Volatility and Expected Returns", Journal of Finance 61(1). OSAP's
    RealizedVol uses residual volatility over a month; this is total
    volatility over twelve, the low-volatility anomaly as usually traded.
    """
    i = ctx.pos(date)
    window = ctx.daily_returns.iloc[max(0, i - 251): i + 1]
    vol = window.std()
    return (-vol).where(window.notna().sum() >= 200)


@indicator("gross_profitability", uses={"statements"})
def gross_profitability(ctx: Context, date) -> pd.Series:
    """Gross profit over total assets.

    Novy-Marx, R. (2013), "The Other Side of Value: The Gross Profitability
    Premium", Journal of Financial Economics 108(1). OSAP: GP. Gross profit is
    revenue less cost of revenue where FMP leaves grossProfit empty.
    """
    t = ctx.f.ttm(date)
    bs = ctx.f.latest(date)
    gp = t["grossProfit"]
    if "costOfRevenue" in t:
        gp = gp.fillna(t["revenue"] - t["costOfRevenue"])
    ta = bs["totalAssets"].reindex(gp.index)
    return (gp / ta).where(ta > 0)


@indicator("asset_growth", uses={"statements"})
def asset_growth(ctx: Context, date) -> pd.Series:
    """Minus the annual growth in total assets (low growth does better).

    Cooper, M., Gulen, H. and Schill, M. (2008), "Asset Growth and the
    Cross-Section of Stock Returns", Journal of Finance 63(4). OSAP: AssetGrowth.
    Latest usable annual against the year before.
    """
    a0 = ctx.f.annual(date)
    a1 = ctx.f.annual(date, back=1)
    ta0 = a0["totalAssets"]
    ta1 = a1["totalAssets"].reindex(ta0.index)
    g = ta0 / ta1 - 1
    return (-g).where(ta1 > 0)


@indicator("accruals", uses={"statements"})
def accruals(ctx: Context, date) -> pd.Series:
    """Minus accruals: (net income - operating cash flow) / average total assets.

    Sloan, R. (1996), "Do Stock Prices Fully Reflect Information in Accruals and
    Cash Flows about Future Earnings?", The Accounting Review 71(3). OSAP:
    Accruals uses balance-sheet changes; the cash-flow form (Hribar and
    Collins, 2002) is used here because UK statements give operating cash
    flow directly and the balance-sheet form needs fields FMP fills unevenly.
    """
    a0 = ctx.f.annual(date)
    a1 = ctx.f.annual(date, back=1)
    avg_ta = (a0["totalAssets"] + a1["totalAssets"].reindex(a0.index)) / 2
    acc = (a0["netIncome"] - a0["operatingCashFlow"]) / avg_ta
    return (-acc).where(avg_ta > 0)


@indicator("net_share_issuance", uses={"statements"})
def net_share_issuance(ctx: Context, date) -> pd.Series:
    """Minus the log change in shares outstanding over the last year.

    Pontiff, J. and Woodgate, A. (2008), "Share Issuance and Cross-Sectional
    Returns", Journal of Finance 63(2). OSAP: ShareIss1Y. Shares from the
    weighted average in the two latest annual accounts.
    """
    a0 = ctx.f.annual(date)
    a1 = ctx.f.annual(date, back=1)
    s0 = a0["weightedAverageShsOut"]
    s1 = a1["weightedAverageShsOut"].reindex(s0.index)
    ok = (s0 > 0) & (s1 > 0)
    return (-np.log(s0 / s1)).where(ok)


@indicator("book_to_market", uses={"statements", "caps", "fx"})
def book_to_market(ctx: Context, date) -> pd.Series:
    """Book equity over market cap; negative book excluded.

    Fama, E. and French, K. (1992), "The Cross-Section of Expected Stock
    Returns", Journal of Finance 47(2); Rosenberg, B., Reid, K. and Lanstein,
    R. (1985). OSAP: BM. Latest usable balance sheet (half-year or annual),
    market cap on the date.
    """
    bs = ctx.to_gbp(ctx.f.latest(date), ["totalStockholdersEquity"], date)
    cap = ctx.market_cap(date).reindex(bs.index)
    be = bs["totalStockholdersEquity"]
    return (be / cap).where((be > 0) & (cap > 0))


@indicator("earnings_yield", uses={"statements", "caps", "fx"})
def earnings_yield(ctx: Context, date) -> pd.Series:
    """Trailing twelve-month net income over market cap.

    Basu, S. (1977), "Investment Performance of Common Stocks in Relation to
    Their Price-Earnings Ratios", Journal of Finance 32(3). OSAP: EP.
    Loss-makers are kept and rank lowest, so a top-quintile portfolio does
    not hold them.
    """
    t = ctx.to_gbp(ctx.f.ttm(date), ["netIncome"], date)
    cap = ctx.market_cap(date).reindex(t.index)
    return (t["netIncome"] / cap).where(cap > 0)
