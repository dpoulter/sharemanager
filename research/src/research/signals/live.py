"""The two strategies live on the N100, rebuilt from the same data as the rest,
so they can be compared on the same footing.

Trend following (sql/strategy_rules.sql, screen 'Trend following (200-day)'):
hold what closes above its 200-day average, ranked by the six-month return.
The exit (two closes below the average) is a portfolio rule, set in the YAML.

Trending Value (sql/trending_value.sql): the cheapest names on the average
percentile of six value measures, then those with a positive six-month
return, ranked by it. O'Shaughnessy, J. (2012), "What Works on Wall Street",
4th edition, chapter 27.
"""
from __future__ import annotations

import numpy as np
import pandas as pd

from .base import Context, signal

VALUE_TOP_FRACTION = 0.10     # the N100 screen takes the cheapest 26 of ~256 non-funds


def six_month_return(ctx: Context, date) -> pd.Series:
    """As calc_momentum(): last close against the close six calendar months before."""
    return ctx.adj_at(date) / ctx.adj_months_back(date, 6) - 1


@signal("six_month")
def six_month(ctx: Context, date) -> pd.Series:
    return six_month_return(ctx, date)


@signal("trend_following")
def trend_following(ctx: Context, date) -> pd.Series:
    """Six-month return, for names closing above their 200-day average only."""
    i = ctx.pos(date)
    above = ctx.adj_at(date) > ctx.ma200.iloc[i]
    return six_month_return(ctx, date).where(above)


def value_measures(ctx: Context, date) -> pd.DataFrame:
    """The six Trending Value measures, each oriented so higher = cheaper.

    Earnings, book, sales, operating cash flow and EBITDA yields (the inverses
    of P/E, P/B, P/S, P/CF and EV/EBITDA) and shareholder yield. Negative
    ratios are excluded (left unranked, the N100's rank_negative EXCLUDE);
    EV/EBITDA is not used for Financial Services. A measure whose inputs are
    missing is left empty for that company (it then counts as the neutral 50th
    percentile in the composite, as on the N100), never computed from zeros.
    """
    flows = ["netIncome", "revenue", "operatingCashFlow", "ebitda", "dividendsPaid", "commonStockRepurchased",
             "commonStockIssued"]
    t = ctx.to_gbp(ctx.f.ttm(date), flows, date)
    bs = ctx.to_gbp(ctx.f.latest(date), ["totalStockholdersEquity", "totalDebt", "cashAndCashEquivalents"], date)
    idx = t.index.union(bs.index)
    t, bs = t.reindex(idx), bs.reindex(idx)
    cap = ctx.market_cap(date).reindex(idx)
    # A missing field means the company is skipped for that measure, never read as zero.
    ev = cap + bs["totalDebt"] - bs["cashAndCashEquivalents"]
    out = pd.DataFrame(index=idx)
    out["earnings_yield"] = t["netIncome"] / cap
    out["book_yield"] = bs["totalStockholdersEquity"] / cap
    out["sales_yield"] = t["revenue"] / cap
    out["cash_flow_yield"] = t["operatingCashFlow"] / cap
    out["ebitda_ev"] = (t["ebitda"] / ev).where(ev > 0)
    sector = ctx.m.master.reindex(idx).get("sector")
    if sector is not None:
        out.loc[sector == "Financial Services", "ebitda_ev"] = np.nan
    paid = -t["dividendsPaid"]
    bought = -t["commonStockRepurchased"] - t["commonStockIssued"]
    out["shareholder_yield"] = (paid + bought) / cap
    for c in ["earnings_yield", "book_yield", "sales_yield", "cash_flow_yield", "ebitda_ev"]:
        out[c] = out[c].where(out[c] > 0)
    return out.where(cap.gt(0), axis=0)


@signal("value_composite")
def value_composite(ctx: Context, date) -> pd.Series:
    """Average percentile of the six measures; a missing measure counts as 50."""
    members = None
    if ctx.member is not None and pd.Timestamp(date) in ctx.member.index:
        row = ctx.member.loc[pd.Timestamp(date)]
        members = row.index[row.to_numpy()]
    v = value_measures(ctx, date)
    if members is not None:
        v = v.reindex(members)
    pct = v.rank(pct=True).fillna(0.5)
    have_any = v.notna().any(axis=1)
    return pct.mean(axis=1).where(have_any)


@signal("trending_value")
def trending_value(ctx: Context, date) -> pd.Series:
    """Cheapest tenth on the value composite, positive six-month return, ranked by it."""
    vc = value_composite(ctx, date).dropna()
    n = max(1, int(round(len(vc) * VALUE_TOP_FRACTION)))
    cheap = vc.sort_values(ascending=False).index[:n]
    mom = six_month_return(ctx, date).reindex(cheap)
    return mom.where(mom > 0)
