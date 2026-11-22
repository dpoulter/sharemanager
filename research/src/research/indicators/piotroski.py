"""The Piotroski F-score, stored as `piotroski_score` (the sharemanager name).

Piotroski, J. D. (2000), "Value Investing: The Use of Historical Financial
Statement Information to Separate Winners from Losers", Journal of
Accounting Research 38 (supplement: Studies on Accounting Information and
the Economics of the Firm), 1-41.

Nine tests, each 1 if passed and 0 if not; the score is their sum (0 to 9).
Annual accounts only, as in the paper. Year t is the latest annual accounts
usable on the date under the publication lag (pit.Fundamentals: period end
plus `annual_lag_months`); year t-1 is the annual accounts before it, and
t-2 the one before that (its total assets are t-1's assets at the start of
the year).

Profitability
  1. ROA > 0: net income before extraordinary items / total assets at the
     start of the year. FMP's netIncomeFromContinuingOperations where it has
     it, otherwise netIncome.
  2. CFO > 0: cash flow from operations / total assets at the start of the year.
  3. Change in ROA > 0 (t against t-1).
  4. Accrual: CFO / assets > ROA.
Leverage, liquidity and source of funds
  5. Change in long-term debt / average total assets < 0. A company with no
     long-term debt in either year passes.
  6. Change in current ratio > 0.
  7. No new equity: shares at t not above t-1, with a tolerance for scrip
     and option noise (config indicators.piotroski_score.share_issue_tolerance,
     0.5%). DIFFERS FROM THE PAPER: the paper asks whether the company issued
     common equity in the year; FMP has no year-end share count for UK
     companies, so the weighted average shares outstanding
     (weightedAverageShsOut) of the two years is compared.
Operating efficiency
  8. Change in gross margin > 0 (gross profit, or revenue less cost of
     revenue where FMP leaves it empty, over revenue).
  9. Change in asset turnover (revenue / total assets at the start of the
     year) > 0.

Missing inputs: a test whose inputs are missing (or whose denominator is not
positive) scores nothing and is counted as missing. A company with more than
`max_missing_tests` (2) missing tests has no score on that date. A missing
field is never read as zero.

Every company is scored, banks and insurers included: the paper's exclusion
of financial companies is the screen's industry criterion, and what counts as
a financial company is the N100's industry map. Nothing here knows a sector.

The provider's own ready-made F-score is never read: every test is worked
out here from the statements.
"""
from __future__ import annotations

import numpy as np
import pandas as pd

from .base import Context, indicator

TESTS = ["roa", "cfo", "delta_roa", "accrual", "delta_leverage", "delta_liquidity", "no_new_equity",
         "delta_margin", "delta_turnover"]
DEFAULTS = {"share_issue_tolerance": 0.005, "max_missing_tests": 2}


def _num(frame: pd.DataFrame, index, *names) -> pd.Series:
    """The first of `names` the accounts carry, row by row, as numbers; NaN where none is present."""
    out = pd.Series(np.nan, index=index, dtype=float)
    for n in names:
        if n in frame:
            out = out.fillna(pd.to_numeric(frame[n], errors="coerce").reindex(index))
    return out


def _ratio(num: pd.Series, den: pd.Series) -> pd.Series:
    return (num / den).where(den > 0)


def _test(passed: pd.Series, *inputs: pd.Series) -> pd.Series:
    """1.0 passed, 0.0 failed, NaN where any input is missing."""
    ok = pd.concat(inputs, axis=1).notna().all(axis=1)
    return passed.astype(float).where(ok)


def piotroski_tests(ctx: Context, date, share_issue_tolerance: float = DEFAULTS["share_issue_tolerance"]) -> pd.DataFrame:
    """The nine tests per company with annual accounts usable on the date: 1, 0 or NaN (missing)."""
    a0 = ctx.f.annual(date)
    idx = a0.index
    a1 = ctx.f.annual(date, back=1)
    a2 = ctx.f.annual(date, back=2)

    ta0, ta1, ta2 = (_num(a, idx, "totalAssets") for a in (a0, a1, a2))
    ni0, ni1 = (_num(a, idx, "netIncomeFromContinuingOperations", "netIncome") for a in (a0, a1))
    cfo0 = _num(a0, idx, "operatingCashFlow", "netCashProvidedByOperatingActivities")
    ltd0, ltd1 = (_num(a, idx, "longTermDebt") for a in (a0, a1))
    ca0, ca1 = (_num(a, idx, "totalCurrentAssets") for a in (a0, a1))
    cl0, cl1 = (_num(a, idx, "totalCurrentLiabilities") for a in (a0, a1))
    sh0, sh1 = (_num(a, idx, "weightedAverageShsOut") for a in (a0, a1))
    rev0, rev1 = (_num(a, idx, "revenue") for a in (a0, a1))
    gp0, gp1 = (_num(a, idx, "grossProfit").fillna(_num(a, idx, "revenue") - _num(a, idx, "costOfRevenue"))
                for a in (a0, a1))

    roa0, roa1 = _ratio(ni0, ta1), _ratio(ni1, ta2)
    cfo_a = _ratio(cfo0, ta1)
    lev0 = _ratio(ltd0, (ta0 + ta1) / 2)
    lev1 = _ratio(ltd1, (ta1 + ta2) / 2)
    cr0, cr1 = _ratio(ca0, cl0), _ratio(ca1, cl1)
    gm0, gm1 = _ratio(gp0, rev0), _ratio(gp1, rev1)
    at0, at1 = _ratio(rev0, ta1), _ratio(rev1, ta2)

    out = pd.DataFrame(index=idx)
    out["roa"] = _test(roa0 > 0, roa0)
    out["cfo"] = _test(cfo_a > 0, cfo_a)
    out["delta_roa"] = _test(roa0 - roa1 > 0, roa0, roa1)
    out["accrual"] = _test(cfo_a > roa0, cfo_a, roa0)
    lev = _test(lev0 - lev1 < 0, lev0, lev1)
    no_debt = (ltd0 == 0) & (ltd1 == 0)
    out["delta_leverage"] = lev.where(~no_debt, 1.0)
    out["delta_liquidity"] = _test(cr0 - cr1 > 0, cr0, cr1)
    out["no_new_equity"] = _test(sh0 <= sh1 * (1 + float(share_issue_tolerance)),
                                 sh0.where(sh0 > 0), sh1.where(sh1 > 0))
    out["delta_margin"] = _test(gm0 - gm1 > 0, gm0, gm1)
    out["delta_turnover"] = _test(at0 - at1 > 0, at0, at1)
    return out[TESTS]


def piotroski_from_tests(tests: pd.DataFrame, max_missing_tests: int = DEFAULTS["max_missing_tests"]) -> pd.Series:
    """The sum of the tests passed; NaN where more than `max_missing_tests` are missing."""
    missing = tests.isna().sum(axis=1)
    return tests.sum(axis=1, min_count=0).where(missing <= int(max_missing_tests))


@indicator("piotroski_score", uses={"statements"}, deps=(piotroski_tests, piotroski_from_tests, _num, _ratio, _test),
           start="2000-01-01", config="piotroski_score", unit="score 0-9",
           source="Piotroski (2000), Journal of Accounting Research 38 (supplement), 1-41")
def piotroski_score(ctx: Context, date) -> pd.Series:
    """The Piotroski F-score (0-9) from the latest two (three for start-of-year assets) usable annual accounts."""
    p = {**DEFAULTS, **(getattr(ctx, "params", None) or {})}
    tests = piotroski_tests(ctx, date, p["share_issue_tolerance"])
    return piotroski_from_tests(tests, p["max_missing_tests"])
