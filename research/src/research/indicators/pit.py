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
            # The same columns as a real set of accounts, so an indicator asked about companies
            # with no accounts finds nothing rather than a missing column.
            self.st = pd.DataFrame(columns=["symbol", "period_end", "freq", "usable_from",
                                            *FLOWS, *STOCKS, "reportedCurrency"])
            self.st["usable_from"] = pd.to_datetime(self.st["usable_from"])
            self.st["period_end"] = pd.to_datetime(self.st["period_end"])
            for c in FLOWS + STOCKS:
                self.st[c] = pd.to_numeric(self.st[c], errors="coerce")
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
        # What is known on a date depends only on how many statements are usable by then, so each
        # answer is kept per count: an indicator worked out for every day of 18 years asks the same
        # question thousands of times between two publication dates.
        self._usable = np.sort(pd.to_datetime(self.st["usable_from"]).to_numpy(dtype="datetime64[ns]"))
        self._memo: dict = {}

    def _cached(self, what: str, date, args: tuple, make):
        n = int(np.searchsorted(self._usable, np.datetime64(pd.Timestamp(date), "ns"), side="right"))
        key = (what, n, args)
        if key not in self._memo:
            self._memo[key] = make()
        return self._memo[key].copy()

    def known(self, date) -> pd.DataFrame:
        date = pd.Timestamp(date)
        return self.st[self.st["usable_from"] <= date]

    def annual(self, date, back: int = 0) -> pd.DataFrame:
        """The latest usable annual accounts per symbol (back=1 for the year before)."""
        return self._cached("annual", date, (back,), lambda: self._annual(date, back))

    def _annual(self, date, back: int) -> pd.DataFrame:
        k = self.known(date)
        k = k[k["freq"] == "annual"].sort_values(["symbol", "period_end"])
        from_end = k.groupby("symbol").cumcount(ascending=False)
        return k[from_end == back].set_index("symbol")

    def latest(self, date) -> pd.DataFrame:
        """The latest usable balance sheet of either frequency per symbol."""
        return self._cached("latest", date, (), lambda: self._latest(date))

    def _latest(self, date) -> pd.DataFrame:
        k = self.known(date).sort_values(["symbol", "period_end", "freq"])
        return k.groupby("symbol").tail(1).set_index("symbol")

    def ttm(self, date, fields=FLOWS) -> pd.DataFrame:
        """Trailing twelve-month flows.

        From interim periods when the usable ones cover the twelve months to
        the latest (two halves or four quarters) and end after the latest
        annual; otherwise the latest annual figures.
        """
        return self._cached("ttm", date, tuple(fields), lambda: self._ttm(date, fields))

    def _ttm(self, date, fields) -> pd.DataFrame:
        fields = list(fields)
        k = self.known(date)
        ann = k[k["freq"] == "annual"].sort_values("period_end").groupby("symbol").tail(1).set_index("symbol")
        fields = list(fields)
        out = ann[[c for c in fields] + ["period_end", "reportedCurrency"]].copy()
        inter = k[k["freq"] == "interim"].sort_values(["symbol", "period_end"])
        if len(inter):
            # Per symbol: the interims in the twelve months to its latest, used when there are two halves
            # or four quarters about evenly spaced and they end after the latest annual.
            last_end = inter.groupby("symbol")["period_end"].transform("max")
            w = inter[inter["period_end"] > last_end - pd.DateOffset(months=12) + pd.Timedelta(days=20)].copy()
            w["gap"] = w.groupby("symbol")["period_end"].diff().dt.days
            g = w.groupby("symbol")
            n = g.size()
            step = n.map({2: 182, 4: 91})
            worst = (w["gap"] - w["symbol"].map(step)).abs().groupby(w["symbol"]).max()
            ok = n.isin([2, 4]) & ~(worst > 40)
            ends = g["period_end"].max()
            if len(out):
                ann_end = out["period_end"].reindex(ends.index)
                ok &= ~(ann_end.notna() & (ends <= ann_end))
            use = ok[ok].index
            if len(use):
                ww = w[w["symbol"].isin(use)]
                sums = ww.groupby("symbol")[fields].sum(min_count=1)
                cur = ww.groupby("symbol")["reportedCurrency"].last()
                new = out.reindex(out.index.union(use))
                new["period_end"] = pd.to_datetime(new["period_end"])
                new["reportedCurrency"] = new["reportedCurrency"].astype(object)
                new.loc[use, fields] = sums.loc[use, fields].to_numpy(dtype=float)
                new.loc[use, "period_end"] = ends.loc[use]
                new.loc[use, "reportedCurrency"] = cur.loc[use]
                out = new
        return out

    def reported_currency(self, date) -> pd.Series:
        return self.latest(date)["reportedCurrency"]
