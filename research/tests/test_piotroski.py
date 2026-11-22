"""The Piotroski F-score indicator (brief of 22 Nov 2026, option 2: tests 1 to 5)."""
from __future__ import annotations

from pathlib import Path
from types import SimpleNamespace

import numpy as np
import pandas as pd
import pytest

from research import indicators
from research.indicators import Fundamentals
from research.indicators import piotroski as pio

NAN = np.nan


def _row(symbol, year, **f):
    return {"symbol": symbol, "period_end": f"{year}-12-31", "freq": "annual", "reportedCurrency": "GBP", **f}


def _accounts() -> pd.DataFrame:
    """Hand-made annual accounts. Each company's score is counted by hand in the tests."""
    rows = [
        # A: passes all nine in 2023 (and four of nine in 2022).
        _row("A.L", 2020, totalAssets=100),
        _row("A.L", 2021, totalAssets=100, netIncome=5, operatingCashFlow=6, longTermDebt=30, totalCurrentAssets=50,
             totalCurrentLiabilities=40, weightedAverageShsOut=100, revenue=100, grossProfit=30),
        _row("A.L", 2022, totalAssets=100, netIncome=5, operatingCashFlow=6, longTermDebt=30, totalCurrentAssets=50,
             totalCurrentLiabilities=40, weightedAverageShsOut=100, revenue=100, grossProfit=30),
        _row("A.L", 2023, totalAssets=110, netIncome=8, operatingCashFlow=12, longTermDebt=25, totalCurrentAssets=60,
             totalCurrentLiabilities=40, weightedAverageShsOut=100, revenue=120, grossProfit=40),
        # B: fails all nine in 2023.
        _row("B.L", 2021, totalAssets=100),
        _row("B.L", 2022, totalAssets=100, netIncome=5, operatingCashFlow=6, longTermDebt=20, totalCurrentAssets=60,
             totalCurrentLiabilities=40, weightedAverageShsOut=100, revenue=100, grossProfit=40),
        _row("B.L", 2023, totalAssets=100, netIncome=-2, operatingCashFlow=-3, longTermDebt=30, totalCurrentAssets=50,
             totalCurrentLiabilities=50, weightedAverageShsOut=110, revenue=90, grossProfit=30),
        # BANK: a bank's accounts - no current assets or liabilities, no gross profit. Two tests missing,
        # so it is scored: 6 (shares up 0.3%, inside the 0.5% allowed; asset turnover flat fails).
        _row("BANK.L", 2021, totalAssets=1000),
        _row("BANK.L", 2022, totalAssets=1000, netIncome=10, operatingCashFlow=5, longTermDebt=100,
             weightedAverageShsOut=100, revenue=50),
        _row("BANK.L", 2023, totalAssets=1100, netIncome=12, operatingCashFlow=20, longTermDebt=100,
             weightedAverageShsOut=100.3, revenue=50),
        # GAPS: no current assets or liabilities, no gross profit and no share count: three tests missing.
        _row("GAPS.L", 2021, totalAssets=100),
        _row("GAPS.L", 2022, totalAssets=100, netIncome=5, operatingCashFlow=6, longTermDebt=0, revenue=100),
        _row("GAPS.L", 2023, totalAssets=100, netIncome=6, operatingCashFlow=7, longTermDebt=0, revenue=110),
    ]
    return pd.DataFrame(rows)


def _ctx(statements=None):
    return SimpleNamespace(f=Fundamentals(_accounts() if statements is None else statements, 4, 3), params={})


def _score(date, statements=None) -> pd.Series:
    return indicators.REGISTRY["piotroski_score"](_ctx(statements), pd.Timestamp(date))


# ---------------------------------------------------------------- test 1
def test_1_hand_made_accounts_give_the_hand_counted_scores():
    s = _score("2024-06-30")
    assert s["A.L"] == 9
    assert s["B.L"] == 0
    assert s["BANK.L"] == 6
    t = pio.piotroski_tests(_ctx(), pd.Timestamp("2024-06-30"))
    assert t.loc["A.L"].tolist() == [1.0] * 9
    assert t.loc["B.L"].tolist() == [0.0] * 9
    bank = t.loc["BANK.L"]
    assert bank.isna().sum() == 2 and np.isnan(bank["delta_liquidity"]) and np.isnan(bank["delta_margin"])
    assert bank["no_new_equity"] == 1.0 and bank["delta_turnover"] == 0.0


def test_1_no_long_term_debt_in_either_year_passes_and_the_share_tolerance_is_a_setting():
    t = pio.piotroski_tests(_ctx(), pd.Timestamp("2024-06-30"))
    assert t.loc["GAPS.L", "delta_leverage"] == 1.0
    tight = pio.piotroski_tests(_ctx(), pd.Timestamp("2024-06-30"), share_issue_tolerance=0.001)
    assert tight.loc["BANK.L", "no_new_equity"] == 0.0


# ---------------------------------------------------------------- test 2
def test_2_accounts_to_31_december_are_not_used_before_30_april():
    # On 29 April 2024 the 2023 accounts are not yet usable: 2022 against 2021, which scores 4.
    assert _score("2024-04-29")["A.L"] == 4
    assert _score("2024-04-30")["A.L"] == 9


# ---------------------------------------------------------------- test 3
def test_3_three_missing_tests_means_no_score():
    t = pio.piotroski_tests(_ctx(), pd.Timestamp("2024-06-30"))
    assert t.loc["GAPS.L"].isna().sum() == 3
    s = _score("2024-06-30")
    assert "GAPS.L" not in s.dropna().index
    # and a company with no annual accounts usable yet has none either
    assert "A.L" not in _score("2021-03-01").dropna().index


# ---------------------------------------------------------------- test 4
def test_4_a_bank_gets_a_score_nothing_is_excluded_by_sector():
    defn = indicators.REGISTRY["piotroski_score"]
    assert defn.uses == frozenset({"statements"})            # reads no symbol master, so no sector
    assert _score("2024-06-30")["BANK.L"] == 6
    src = Path(pio.__file__).read_text()
    code = src.split('"""', 2)[2]                              # below the docstring
    for word in ("sector", "industry", "Financial", "bank"):
        assert word not in code


# ---------------------------------------------------------------- test 5
def test_5_the_providers_ready_made_score_is_never_read():
    src = Path(pio.__file__).read_text()
    assert "piotroskiScore" not in src
    # and a statement row carrying it changes nothing
    st = _accounts()
    st["piotroskiScore"] = 1
    pd.testing.assert_series_equal(_score("2024-06-30", st), _score("2024-06-30"))


# ---------------------------------------------------------------- stored under the sharemanager name
def test_stored_as_piotroski_score_from_2000_with_its_settings_in_the_version(cfg):
    defn = indicators.REGISTRY["piotroski_score"]
    assert defn.keys() == ["piotroski_score"]
    assert defn.settings(cfg, "piotroski_score")["start"] == "2000-01-01"
    v = defn.version(cfg, "piotroski_score")
    cfg.raw.setdefault("indicators", {}).setdefault("piotroski_score", {})["share_issue_tolerance"] = 0.01
    assert defn.version(cfg, "piotroski_score") != v
    # the other definitions carry no start or config of their own, so their versions did not move
    assert "config" not in indicators.REGISTRY["book_to_market"].settings(cfg, "book_to_market")


# ---------------------------------------------------------------- the job, the strategies and the coverage report
def _good(symbol, equity):
    return [
        _row(symbol, 2021, totalAssets=100e6, netIncome=5e6, operatingCashFlow=6e6, longTermDebt=30e6,
             totalCurrentAssets=50e6, totalCurrentLiabilities=40e6, weightedAverageShsOut=1e6, revenue=100e6,
             grossProfit=30e6, totalStockholdersEquity=equity),
        _row(symbol, 2022, totalAssets=100e6, netIncome=5e6, operatingCashFlow=6e6, longTermDebt=30e6,
             totalCurrentAssets=50e6, totalCurrentLiabilities=40e6, weightedAverageShsOut=1e6, revenue=100e6,
             grossProfit=30e6, totalStockholdersEquity=equity),
        _row(symbol, 2023, totalAssets=110e6, netIncome=8e6, operatingCashFlow=12e6, longTermDebt=25e6,
             totalCurrentAssets=60e6, totalCurrentLiabilities=40e6, weightedAverageShsOut=1e6, revenue=120e6,
             grossProfit=40e6, totalStockholdersEquity=equity),
    ]


def _bad(symbol, equity):
    return [
        _row(symbol, 2021, totalAssets=100e6, totalStockholdersEquity=equity),
        _row(symbol, 2022, totalAssets=100e6, netIncome=5e6, operatingCashFlow=6e6, longTermDebt=20e6,
             totalCurrentAssets=60e6, totalCurrentLiabilities=40e6, weightedAverageShsOut=1e6, revenue=100e6,
             grossProfit=40e6, totalStockholdersEquity=equity),
        _row(symbol, 2023, totalAssets=100e6, netIncome=-2e6, operatingCashFlow=-3e6, longTermDebt=30e6,
             totalCurrentAssets=50e6, totalCurrentLiabilities=50e6, weightedAverageShsOut=1.1e6, revenue=90e6,
             grossProfit=30e6, totalStockholdersEquity=equity),
    ]


def test_the_job_builds_it_and_piotroski_value_holds_the_cheap_high_scorers_less_financials(cfg, tmp_path):
    import json
    from conftest import built_workspace, make_market
    from research import indicator_job
    from research.cli import holdings_over_time
    from research.indicator_store import IndicatorStore
    from research.strategy import spec_from_dict
    from research.universe import build_universe

    rng = np.random.default_rng(3)
    syms = [f"S{i}.L" for i in range(8)]
    prices = {s: list(100 * np.cumprod(1 + rng.normal(0.0004, 0.01, 220))) for s in syms}
    # S6 is a bank and the cheapest; S0 the next cheapest and an F-score of 9; S7 scores 9 but is dear.
    eq = {"S6.L": 1e10, "S0.L": 5e9}
    rows = []
    for s in syms:
        rows += (_good if s in ("S0.L", "S6.L", "S7.L") else _bad)(s, eq.get(s, 1e6))
    master = {s: {"sector": "Financial Services" if s == "S6.L" else "Technology", "industry": "x"} for s in syms}
    m = make_market(prices, start="2024-06-03", statements=pd.DataFrame(rows), master=master)
    u = build_universe(m, size=8, large=4, start="2024-06-03")
    cfg.raw["universe"]["start"] = "2024-06-03"
    ws = built_workspace(cfg, m, u, names=["piotroski_score", "book_to_market"])
    store = IndicatorStore.for_config(cfg)
    assert store.meta("piotroski_score")["dates"][0] == m.dates[0]
    f = store.read("piotroski_score", [m.dates[-1]]).frame.iloc[0]
    assert f["S0.L"] == 9 and f["S6.L"] == 9 and f["S1.L"] == 0

    imap = tmp_path / "claim.json"
    imap.write_text(json.dumps({"industry_map": {"version": "v1", "industries": ["Technology", "Financials"],
                                                 "fmp": {"sectors": {"Technology": "Technology",
                                                                     "Financial Services": "Financials"},
                                                         "exceptions": []}}}))
    spec = spec_from_dict({"name": "pv_test", "family": "piotroski_test", "signals": {},
                           "screen": {"criteria": [["book_to_market", "top_frac", 0.2, "desc"],
                                                   ["industry", "ind_not_in", ["Financials"], None],
                                                   ["piotroski_score", ">=", 8, None]],
                                      "rank_by": None, "require": None, "industry_map_file": str(imap)},
                           "holdings": "all", "rebalance": "monthly", "universe": "ftse350"})
    res = ws.run(spec, start=m.dates[0], end=m.dates[-1], write_report=False)
    for d, sc in res["scores"].items():
        assert list(sc.dropna().index) == ["S0.L"], d      # cheapest fifth: S6 and S0; S6 is a bank
    h = holdings_over_time(res)
    assert (h.iloc[1:] == 1).all()
    assert res["record"]["industry_map_version"] == "v1"

    # the F-score alone, top quintile
    spec2 = spec_from_dict({"name": "pf_test", "family": "piotroski_test", "signals": {"piotroski_score": 1},
                            "holdings": "quintile", "rebalance": "monthly", "universe": "ftse350"})
    res2 = ws.run(spec2, start=m.dates[0], end=m.dates[-1], write_report=False)
    assert res2["record"]["coverage_share_median"] == 1.0

    share = indicator_job.universe_share(cfg, "piotroski_score", market=m, universe=u)
    assert list(share["year"]) == [2024, 2025] and (share["share_median"] == 1.0).all()


def test_holdings_all_with_nothing_qualifying_holds_cash(cfg):
    from conftest import built_workspace, make_market
    from research.strategy import spec_from_dict
    from research.universe import build_universe
    rng = np.random.default_rng(5)
    syms = [f"S{i}.L" for i in range(5)]
    prices = {s: list(100 * np.cumprod(1 + rng.normal(0.0004, 0.01, 120))) for s in syms}
    rows = [r for s in syms for r in _bad(s, 1e6)]
    m = make_market(prices, start="2024-06-03", statements=pd.DataFrame(rows))
    u = build_universe(m, size=5, large=2, start="2024-06-03")
    cfg.raw["universe"]["start"] = "2024-06-03"
    ws = built_workspace(cfg, m, u, names=["piotroski_score"])
    spec = spec_from_dict({"name": "none_test", "signals": {},
                           "screen": {"criteria": [["piotroski_score", ">=", 8, None]], "rank_by": None},
                           "holdings": "all", "rebalance": "monthly", "universe": "ftse350"})
    res = ws.run(spec, start=m.dates[0], end=m.dates[-1], write_report=False)
    assert (res["result"].values["holdings"] == 0).all()
    assert res["result"].values["total"].iloc[-1] == pytest.approx(100_000)


def test_the_translator_finds_both_by_their_sharemanager_names_with_no_name_map():
    """The Piotroski value screen as sharemanager will hold it: book-to-market above 1, industry not
    Financials, F-score of 8 or more, ranked by the F-score."""
    import json
    from research.from_rules import translate
    from test_industry import FMP_MAP, SHARES, claim, master
    from test_worker import STORE
    c = claim(("ind_not_in", "Financials"))
    c["indicators"] = {"15": {"name": "piotroski_score", "rank_order": "value DESC"},
                       "300": {"name": "book_to_market", "rank_order": "value DESC"}}
    c["rank_orders"] = {"piotroski_score": "value DESC"}
    c["rules_snapshot"]["criteria"] = [["300", ">", "1", ""], ["", "ind_not_in", "Financials", ""], ["15", ">=", "8", ""]]
    c["rules_snapshot"]["rules"]["rank_by"] = "piotroski_score"
    s = translate(json.loads(json.dumps(c)), STORE, master=master(), universe=list(SHARES))
    assert s["screen"]["criteria"][0] == ["book_to_market", ">", 1.0, None]
    assert s["screen"]["criteria"][2] == ["piotroski_score", ">=", 8.0, None]
    assert s["screen"]["rank_by"] == "piotroski_score"
    assert s["screen"]["industry_map_version"] == FMP_MAP["version"]
