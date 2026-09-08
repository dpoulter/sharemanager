"""
Offline tests for the EODHD client helpers.

Only the pure functions are covered: flattening a fundamentals document and
building a ticker. The HTTP calls themselves are not exercised, so nothing here
needs an API key or network access, and nothing here proves EODHD's real field
names are what sql/eodhd_migration.sql assumes.
"""

import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'python'))

from eodhd import flatten_fundamentals, eodhd_symbol   # noqa: E402

passed = failed = 0


def check(name, ok, detail=''):
    global passed, failed
    if ok:
        passed += 1
        print("  \033[32mPASS\033[0m  {}".format(name))
    else:
        failed += 1
        print("  \033[31mFAIL\033[0m  {}{}".format(name, '' if not detail else "\n          " + str(detail)))


# A fundamentals document shaped the way EODHD documents it. The field names
# here are the ones sql/eodhd_migration.sql maps; confirm them against a real
# response before trusting the mapping.
DOCUMENT = {
    "General":   {"Code": "VOD", "Name": "Vodafone Group Plc", "CurrencyCode": "GBX"},
    "Highlights": {"MarketCapitalization": 21000000000, "ReturnOnEquityTTM": 0.185,
                   "PERatio": 12.5, "MostRecentQuarter": ""},
    "Valuation": {"TrailingPE": 12.5, "PriceSalesTTM": 1.9, "PriceBookMRQ": 0.45,
                  "EnterpriseValueEbitda": 6.2, "ForwardPE": None},
    "SharesStats": {"SharesOutstanding": 27000000000},
    "Financials": {"Cash_Flow": {"yearly": {"2025-03-31": {"freeCashFlow": 123}}}},
    "unlisted_section": {"ignored": 1},
}

flat = flatten_fundamentals(DOCUMENT)

check("keys are Section.Field",
      flat.get("Valuation.TrailingPE") == 12.5 and flat.get("Highlights.ReturnOnEquityTTM") == 0.185,
      flat)
check("every mapped ratio survives flattening",
      all(k in flat for k in ("Valuation.TrailingPE", "Valuation.PriceSalesTTM",
                              "Valuation.PriceBookMRQ", "Valuation.EnterpriseValueEbitda",
                              "Highlights.ReturnOnEquityTTM")),
      sorted(flat))
check("unmapped fields are kept too, so a new indicator needs no code change",
      "General.Name" in flat and "SharesStats.SharesOutstanding" in flat)
check("nulls are dropped rather than stored as 'None'", "Valuation.ForwardPE" not in flat)
check("empty strings are dropped", "Highlights.MostRecentQuarter" not in flat)
check("nested Financials is excluded, it belongs in financial_statement_*",
      not any(k.startswith("Financials") for k in flat), sorted(flat))
check("sections outside the allow list are ignored",
      not any(k.startswith("unlisted") for k in flat))
check("no key exceeds stock_info.attribute's 50 characters",
      all(len(k) <= 50 for k in flat), [k for k in flat if len(k) > 50])

# A field name long enough to overflow the column must be skipped, not
# truncated: two truncated keys could collide and silently overwrite each other.
long_doc = {"Valuation": {"A" * 60: 1, "TrailingPE": 2}}
long_flat = flatten_fundamentals(long_doc)
check("an over-long field is skipped rather than truncated",
      list(long_flat) == ["Valuation.TrailingPE"], long_flat)

check("a malformed document yields nothing rather than raising",
      flatten_fundamentals(None) == {} and flatten_fundamentals("nope") == {}
      and flatten_fundamentals({"Valuation": "not a dict"}) == {})

# The loader stamps stock_info.asofdate and get_api_stats.py reads it back with
# an exact match, so the two must agree. They were separate expressions in
# separate files and drifted: the loader used today, the reader yesterday, so
# the join matched nothing and the fundamentals never reached statistics. Both
# now call the same function; these tests pin that it stays one function and
# stays yesterday.
import datetime                                                        # noqa: E402
from pipeline import pipeline_asofdate                                 # noqa: E402

expected = (datetime.datetime.now() - datetime.timedelta(days=1)).strftime('%Y-%m-%d')
check("the pipeline as-of date is yesterday", pipeline_asofdate() == expected,
      "{} != {}".format(pipeline_asofdate(), expected))

loader = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..',
                           'python', 'fetch_eodhd_fundamentals.py')).read()
reader = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..',
                           'python', 'get_api_stats.py')).read()
check("the loader takes its date from the shared function",
      'pipeline_asofdate()' in loader and 'date.today()' not in loader)
check("the reader takes its date from the shared function",
      'pipeline_asofdate()' in reader and 'timedelta(days=1)' not in reader)

check("bare codes get the LSE suffix", eodhd_symbol("VOD") == "VOD.LSE")
check("an already-suffixed code is left alone", eodhd_symbol("VOD.LSE") == "VOD.LSE")

print("\n{}  {} passed, {} failed\n".format(
    "\033[32mALL PASSED\033[0m" if failed == 0 else "\033[31mFAILURES\033[0m", passed, failed))
sys.exit(0 if failed == 0 else 1)
