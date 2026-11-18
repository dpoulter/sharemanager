"""fetch -> consolidate -> data report -> universe -> run -> report, against a fake FMP."""
from __future__ import annotations

import numpy as np
import pandas as pd

from research.checks import write_data_report
from research.config import mask
from research.fetch import Fetcher, exclusion_reason
from research.store import load_market
from research.strategy import Workspace, load_spec
from research.universe import build_universe

DATES = pd.bdate_range("2006-06-01", "2008-12-31")


class FakeFmp:
    calls = 0

    def __init__(self):
        rng = np.random.default_rng(3)
        self.px = {s: 100 * np.cumprod(1 + rng.normal(0.0003, 0.012, len(DATES))) for s in ["AAA.L", "BBB.L", "CCC.L", "DED.L"]}

    def get(self, path, **p):
        self.calls += 1
        sym = p.get("symbol")
        if path == "stock-list":
            return [{"symbol": s, "companyName": f"{s} plc"} for s in ["AAA.L", "BBB.L", "CCC.L"]] + \
                   [{"symbol": "0ABC.L", "companyName": "Foreign line"}, {"symbol": "TRU.L", "companyName": "Big Investment Trust plc"},
                    {"symbol": "VOD", "companyName": "US line"}]
        if path == "delisted-companies":
            return [{"symbol": "DED.L", "companyName": "Dead plc", "delistedDate": "2008-06-30"}] if p["page"] == 0 else []
        if path == "profile":
            return [{"symbol": sym, "currency": "GBp", "sector": "Industrials", "exchange": "LSE", "isEtf": False, "isFund": False}]
        if path.startswith("historical-price-eod"):
            if sym not in self.px:
                return []
            a, b = pd.Timestamp(p["from"]), pd.Timestamp(p["to"])
            if sym == "DED.L":
                b = min(b, pd.Timestamp("2008-06-30"))
            sel = (DATES >= a) & (DATES <= b)
            rows = [{"date": d.date().isoformat(), "close": float(v), "adjClose": float(v) * 1.01, "volume": 1000}
                    for d, v in zip(DATES[sel], self.px[sym][sel])]
            return rows[::-1]
        if path == "historical-market-capitalization":
            a, b = pd.Timestamp(p["from"]), pd.Timestamp(p["to"])
            sel = (DATES >= a) & (DATES <= b)
            if sym == "DED.L":
                sel &= DATES <= pd.Timestamp("2008-06-30")
            return [{"date": d.date().isoformat(), "marketCap": float(v) * 1e7} for d, v in zip(DATES[sel], self.px[sym][sel])]
        if path == "dividends":
            return [{"date": "2007-05-01", "dividend": 3.0}]
        if path in ("income-statement", "balance-sheet-statement", "cash-flow-statement"):
            if p["period"] != "annual":
                return []
            return [{"date": f"{y}-12-31", "period": "FY", "reportedCurrency": "GBP", "revenue": 1e9, "grossProfit": 4e8,
                     "netIncome": 1e8, "totalAssets": 2e9 + y, "operatingCashFlow": 1.2e8,
                     "weightedAverageShsOut": 1e7, "totalStockholdersEquity": 1e9} for y in (2005, 2006, 2007)]
        return []


def test_pipeline_end_to_end(cfg, tmp_path):
    f = Fetcher(cfg, client=FakeFmp())
    summary = f.run(full=True)
    assert summary["symbols"] == 4 and summary["failures"] == 0
    data = cfg.data_dir
    master = pd.read_parquet(data / "symbols.parquet").set_index("symbol")
    assert master.loc["0ABC.L", "exclude_reason"].startswith("international order book")
    assert master.loc["TRU.L", "exclude_reason"] == "fund or trust by name"
    assert "VOD" not in master.index

    # Resume: a second full run fetches nothing per symbol that it already holds.
    before = FakeFmp.calls
    f2 = Fetcher(cfg, client=FakeFmp())
    f2.run(full=True)
    assert f2.fmp.calls < 20

    m = load_market(data)
    assert m.close["AAA.L"].dropna().iloc[0] < 5          # pence turned into pounds
    assert m.gone["DED.L"] and not m.gone["AAA.L"]
    report = write_data_report(m, data)
    assert "Companies per year" in report.read_text()

    u = build_universe(m, size=3, large=1, start="2007-01-01")
    u.to_parquet(data / "universe.parquet")
    assert u.groupby("date").size().max() == 3
    assert "DED.L" not in set(u[u["date"] > "2008-07-01"]["symbol"])

    ws = Workspace(cfg)
    spec = load_spec(cfg.path.parent / "strategies" / "trend_following.yaml")
    spec.raw["holdings"] = 2
    out = ws.run(spec, start="2007-03-01", end="2008-12-31")
    assert out["report"].exists()
    html = out["report"].read_text()
    assert "Restatements" in html and "<svg" in html
    spec2 = load_spec(cfg.path.parent / "strategies" / "book_to_market.yaml")
    out2 = ws.run(spec2, start="2007-06-01", end="2008-12-31")
    assert out2["record"]["fills"] > 0


def test_key_is_masked():
    assert "SECRET" not in mask("https://x/y?symbol=A&apikey=SECRET&b=1")


def test_exclusions():
    assert exclusion_reason({"symbol": "BP.L", "name": "BP p.l.c."}) is None
    assert exclusion_reason({"symbol": "ABC.L", "name": "ABC 8.75% Cum Pref"}).startswith("preference")
    assert exclusion_reason({"symbol": "XYZ.L", "name": "XYZ", "is_fund": True}) == "FMP isFund"
