"""Point in time: what accounts were knowable on a date.

FMP's filingDate for UK companies is the period end, not the day the accounts
were published (see the data audit). So a statement is treated as usable only
from period end + 4 months (annual) or + 3 months (half-year or quarter).
Both lags are config values.

FMP holds the latest (restated) figures, not those first published. Nothing
here can fix that; every report prints the caveat.
"""
from __future__ import annotations

import numpy as np
import pandas as pd

FLOWS = ["revenue", "grossProfit", "netIncome", "ebitda", "operatingCashFlow",
         "dividendsPaid", "commonStockRepurchased", "commonStockIssued", "costOfRevenue"]
STOCKS = ["totalAssets", "totalStockholdersEquity", "totalDebt", "cashAndCashEquivalents",
          "weightedAverageShsOut", "weightedAverageShsOutDil", "totalLiabilities"]


def add_usable_from(st: pd.DataFrame, annual_lag_months: int, interim_lag_months: int) -> pd.DataFrame:
    st = st.copy()
    st["period_end"] = pd.to_datetime(st["period_end"])
    annual = st["freq"] == "annual"
    st["usable_from"] = st["period_end"]
    st.loc[annual, "usable_from"] = st.loc[annual, "period_end"] + pd.DateOffset(months=annual_lag_months)
    st.loc[~annual, "usable_from"] = st.loc[~annual, "period_end"] + pd.DateOffset(months=interim_lag_months)
    return st


class Fundamentals:
    """Ask for the accounts as they could be known on a date."""

    def __init__(self, statements: pd.DataFrame, annual_lag_months: int = 4, interim_lag_months: int = 3):
        if statements is None or statements.empty:
            self.st = pd.DataFrame(columns=["symbol", "period_end", "freq", "usable_from"])
        else:
            self.st = add_usable_from(statements, annual_lag_months, interim_lag_months)
            # Dividends paid and buybacks are reported negative; count them as cash out.
            for c in FLOWS + STOCKS:
                if c not in self.st:
                    self.st[c] = np.nan
                self.st[c] = pd.to_numeric(self.st[c], errors="coerce")
            if "netCommonStockIssuance" in statements and self.st["commonStockRepurchased"].isna().all():
                self.st["commonStockRepurchased"] = pd.to_numeric(statements["netCommonStockIssuance"], errors="coerce")
            if "reportedCurrency" not in self.st:
                self.st["reportedCurrency"] = "GBP"
        self.st = self.st.sort_values(["symbol", "usable_from", "period_end"]).reset_index(drop=True)

    def known(self, date) -> pd.DataFrame:
        date = pd.Timestamp(date)
        return self.st[self.st["usable_from"] <= date]

    def annual(self, date, back: int = 0) -> pd.DataFrame:
        """The latest usable annual accounts per symbol (back=1 for the year before)."""
        k = self.known(date)
        k = k[k["freq"] == "annual"].sort_values(["symbol", "period_end"])
        from_end = k.groupby("symbol").cumcount(ascending=False)
        return k[from_end == back].set_index("symbol")

    def latest(self, date) -> pd.DataFrame:
        """The latest usable balance sheet of either frequency per symbol."""
        k = self.known(date).sort_values(["symbol", "period_end", "freq"])
        return k.groupby("symbol").tail(1).set_index("symbol")

    def ttm(self, date, fields=FLOWS) -> pd.DataFrame:
        """Trailing twelve-month flows.

        From interim periods when the usable ones cover the twelve months to
        the latest (two halves or four quarters) and end after the latest
        annual; otherwise the latest annual figures.
        """
        k = self.known(date)
        ann = k[k["freq"] == "annual"].sort_values("period_end").groupby("symbol").tail(1).set_index("symbol")
        out = ann[[c for c in fields] + ["period_end", "reportedCurrency"]].copy()
        inter = k[k["freq"] == "interim"].sort_values(["symbol", "period_end"])
        if len(inter):
            for sym, g in inter.groupby("symbol"):
                last_end = g["period_end"].iloc[-1]
                if sym in out.index and pd.notna(out.at[sym, "period_end"]) and last_end <= out.at[sym, "period_end"]:
                    continue
                window = g[g["period_end"] > last_end - pd.DateOffset(months=12) + pd.Timedelta(days=20)]
                n = len(window)
                if n not in (2, 4):
                    continue
                gaps = window["period_end"].diff().dropna().dt.days
                step = 182 if n == 2 else 91
                if len(gaps) and (gaps - step).abs().max() > 40:
                    continue
                sums = window[fields].sum(min_count=1)
                if sym not in out.index:
                    out.loc[sym] = np.nan
                out.loc[sym, fields] = sums.to_numpy(dtype=float)
                out.loc[sym, "period_end"] = last_end
                out.loc[sym, "reportedCurrency"] = window["reportedCurrency"].iloc[-1]
        return out

    def reported_currency(self, date) -> pd.Series:
        return self.latest(date)["reportedCurrency"]
