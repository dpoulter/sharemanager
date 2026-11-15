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
# now call the same function; these tests pin that it stays one function, and
# that the date is the last trading day rather than the calendar's yesterday -
# which on a Sunday is a Saturday, with no prices and no fundamentals at all.
import datetime                                                        # noqa: E402
from pipeline import (pipeline_asofdate,                               # noqa: E402
                      pipeline_last_trading_date,
                      pipeline_resolve_asofdate)

# A stand-in for functions.query. Friday 13 November 2026 is the last day with
# prices; the 14th is the Saturday after it.
asked = []


def fake_query(sql):
    asked.append(sql)
    if 'max(date)' in sql:
        return [('2026-11-13',)]
    if 'count(*)' in sql:
        return [(1,)] if "date='2026-11-13'" in sql else [(0,)]
    return []


check("the as-of date is the last day prices were loaded for",
      pipeline_asofdate(fake_query) == '2026-11-13',
      str(pipeline_asofdate(fake_query)))
check("it is read from historical_prices, not worked out from the clock",
      any('historical_prices' in sql for sql in asked))
check("it is scoped to the exchange",
      any("exchange='XLON'" in sql for sql in asked))
check("a date object comes back as a plain date string",
      pipeline_last_trading_date(lambda sql: [(datetime.date(2026, 11, 13),)])
      == '2026-11-13')
check("no prices at all is reported rather than guessed at",
      pipeline_last_trading_date(lambda sql: [(None,)]) is None)

check("a requested date with prices is accepted",
      pipeline_resolve_asofdate('2026-11-13', fake_query) == '2026-11-13')
check("the Saturday after the last session is refused",
      pipeline_resolve_asofdate('2026-11-14', fake_query) is None)
check("nothing requested falls back to the last trading day",
      pipeline_resolve_asofdate(None, fake_query) == '2026-11-13')
for bad in ['yesterday', '13/11/2026', '2026-11-13 00:00:00', '2026-02-30',
            '2026-2-3', '']:
    check("a date spelled {!r} is refused rather than interpreted".format(bad),
          pipeline_resolve_asofdate(bad, fake_query) != bad)

loader = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..',
                           'python', 'fetch_eodhd_fundamentals.py')).read()
reader = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..',
                           'python', 'get_api_stats.py')).read()
check("the loader takes its date from the shared function",
      'pipeline_resolve_asofdate(' in loader and 'date.today()' not in loader)
check("the reader takes its date from the shared function",
      'pipeline_asofdate()' in reader and 'timedelta(days=1)' not in reader)
check("neither derives an as-of date from the calendar any more",
      'timedelta(days=1)' not in loader and 'timedelta(days=1)' not in reader)

# The conversion itself, which both halves of the pipeline go through.
from pipeline import fx_correct, report_ccy_is_stale                   # noqa: E402

# SHEL: FMP says 8.43, the pound buys 1.3212 dollars, so the real multiple is
# about 11.1. BGEO: 2.38 lari-denominated earnings against a sterling price,
# 3.58 lari to the pound, about 8.5.
check("a dollar reporter's P/E is multiplied by the rate",
      abs(fx_correct("8.43", "MULTIPLY", 1.3212) - 11.1377) < 0.001,
      str(fx_correct("8.43", "MULTIPLY", 1.3212)))
check("a sterling reporter's is untouched",
      fx_correct("37.52", "MULTIPLY", 1.0) == 37.52)
check("an indicator that needs no conversion is left exactly as it arrived",
      fx_correct("0.1234", "NONE", 1.3212) == "0.1234")
check("an unknown mode is treated as no conversion",
      fx_correct("0.1234", "", 1.3212) == "0.1234"
      and fx_correct("0.1234", None, 1.3212) == "0.1234")
check("a yield is divided rather than multiplied",
      fx_correct("4.0", "DIVIDE", 2.0) == 2.0)
check("no rate means no value, so the ratio is not ranked at all",
      fx_correct("8.43", "MULTIPLY", None) is None)
check("a nonsense rate is refused rather than used",
      fx_correct("8.43", "MULTIPLY", 0) is None
      and fx_correct("8.43", "MULTIPLY", -1.3) is None)
check("a value that is not a number is not corrected into one",
      fx_correct("n/a", "MULTIPLY", 1.3212) is None)
check("converting twice is not the same as converting once (the job must not)",
      fx_correct(fx_correct("8.43", "MULTIPLY", 1.3212), "MULTIPLY", 1.3212)
      != fx_correct("8.43", "MULTIPLY", 1.3212))

# How often the loader asks what a company reports in.
check("a currency that has never been looked up is stale",
      report_ccy_is_stale(None, None) is True)
check("one looked up today is not",
      report_ccy_is_stale("USD", datetime.date.today()) is False)
check("one looked up five days ago is not",
      report_ccy_is_stale("USD", datetime.date.today() - datetime.timedelta(days=5)) is False)
check("one looked up forty days ago is",
      report_ccy_is_stale("USD", datetime.date.today() - datetime.timedelta(days=40)) is True)
check("a date stored as a string is read rather than rejected",
      report_ccy_is_stale("USD", datetime.date.today().strftime('%Y-%m-%d')) is False)
check("an unreadable date is treated as stale rather than trusted",
      report_ccy_is_stale("USD", "not a date") is True)

check("bare codes get the LSE suffix", eodhd_symbol("VOD") == "VOD.LSE")
check("an already-suffixed code is left alone", eodhd_symbol("VOD.LSE") == "VOD.LSE")

print("\n{}  {} passed, {} failed\n".format(
    "\033[32mALL PASSED\033[0m" if failed == 0 else "\033[31mFAILURES\033[0m", passed, failed))
sys.exit(0 if failed == 0 else 1)
