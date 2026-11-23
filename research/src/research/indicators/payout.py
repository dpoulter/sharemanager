"""Net payout yield and dividend yield, stored as `net_payout_yield` and `dividend_yield`.

Boudoukh, J., Michaely, R., Richardson, M. and Roberts, M. R. (2007), "On the
Importance of Measuring Payout Yield: Implications for Empirical Asset
Pricing", Journal of Finance 62(2), 877-915.

net_payout_yield = (common dividends paid + common shares repurchased
                    - common shares issued) / market cap on the date
dividend_yield   = common dividends paid / market cap on the date

The three cash flows (FMP commonDividendsPaid, commonStockRepurchased,
commonStockIssuance) come from the latest annual accounts usable on the date
under the publication lag (pit.Fundamentals: period end plus
`annual_lag_months`). They are turned into pounds at the date's rate, so they
are in the same currency as the market cap (as book_to_market does).

Sign rule: FMP reports cash paid out as negative numbers and cash raised as
positive. Each figure is taken by its size (absolute value): dividends and
buybacks paid raise the yield, shares issued lower it, whatever sign FMP
gives. The net yield can be negative (a company that issues more than it
returns).

Missing data: a company with any of the three figures missing has no net
payout yield (no dividend field: no dividend yield). A missing field is never
read as zero; a field present and zero is zero. No sector or industry logic.
The provider's ready-made yields and the N100's own yield are never read.
"""
from __future__ import annotations

import numpy as np
import pandas as pd

from .base import Context, indicator

DIVIDENDS, REPURCHASED, ISSUED = "commonDividendsPaid", "commonStockRepurchased", "commonStockIssuance"


def payout_flows(ctx: Context, date) -> pd.DataFrame:
    """The three annual cash flows in pounds, each by its size; NaN where the accounts lack the field."""
    a0 = ctx.f.annual(date)
    cols = [DIVIDENDS, REPURCHASED, ISSUED]
    raw = pd.DataFrame({c: (pd.to_numeric(a0[c], errors="coerce") if c in a0 else pd.Series(np.nan, index=a0.index))
                        for c in cols}, index=a0.index)
    raw["reportedCurrency"] = a0["reportedCurrency"] if "reportedCurrency" in a0 else "GBP"
    gbp = ctx.to_gbp(raw, cols, date)
    return gbp[cols].abs()


@indicator("net_payout_yield", uses={"statements", "caps", "fx"}, deps=(payout_flows,), start="2000-01-01",
           unit="fraction", source="Boudoukh, Michaely, Richardson and Roberts (2007), Journal of Finance 62(2), 877-915")
def net_payout_yield(ctx: Context, date) -> pd.Series:
    """(dividends + buybacks - issuance) from the latest usable annual accounts over market cap on the date."""
    f = payout_flows(ctx, date)
    cap = ctx.market_cap(date).reindex(f.index)
    net = f[DIVIDENDS] + f[REPURCHASED] - f[ISSUED]          # NaN if any is missing
    return (net / cap).where(cap > 0)


@indicator("dividend_yield", uses={"statements", "caps", "fx"}, deps=(payout_flows,), start="2000-01-01",
           unit="fraction", source="Boudoukh, Michaely, Richardson and Roberts (2007): the comparison measure")
def dividend_yield(ctx: Context, date) -> pd.Series:
    """Common dividends paid from the latest usable annual accounts over market cap on the date."""
    f = payout_flows(ctx, date)
    cap = ctx.market_cap(date).reindex(f.index)
    return (f[DIVIDENDS] / cap).where(cap > 0)
