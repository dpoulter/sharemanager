#!/usr/bin/env python3
"""Offline tests for the dividend mapping in python/fmp.py and its loader.

No key and no network. What is being proved is the part that is easy to get
silently wrong: which unit a payment is in, and that a record the mapping
cannot read is COUNTED rather than dropped - a loader that stores nothing and
says nothing is the failure this project has had before.
"""

import datetime
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "python"))
import fmp                                                   # noqa: E402

passed = failed = 0


def check(name, ok, detail=""):
    global passed, failed
    if ok:
        passed += 1
        print("  \033[32mPASS\033[0m  {}".format(name))
    else:
        failed += 1
        print("  \033[31mFAIL\033[0m  {}{}".format(name, "\n          " + detail if detail else ""))


# A London price in pence on the ex-date, whatever the date.
def price_1000(ex):
    return 1000.0


print("--- dividends: the unit a payment is in ---")

rows, rep = fmp.dividend_rows(
    [{"date": "2026-03-12", "paymentDate": "2026-04-30", "dividend": 25.0}], price_1000)
check("an amount of 2.5% of the price is pence and is kept as it is",
      rows == [("2026-03-12", "2026-04-30", 25.0)] and rep["as_pence"] == 1, str(rows))

rows, rep = fmp.dividend_rows(
    [{"date": "2026-03-12", "paymentDate": "2026-04-30", "dividend": 0.25}], price_1000)
check("an amount that is a hundredth of a per cent of it is pounds, in pence",
      rows == [("2026-03-12", "2026-04-30", 25.0)] and rep["as_pounds"] == 1, str(rows))

rows, rep = fmp.dividend_rows([{"date": "2026-03-12", "dividend": 400.0}], price_1000)
check("a payment of 40% of the price is not believed",
      rows == [] and rep["implausible"] == 1, str(rep))

rows, rep = fmp.dividend_rows([{"date": "2026-03-12", "dividend": 25.0}],
                              lambda ex: None)
check("with no price to judge by nothing is guessed, and it is counted",
      rows == [] and rep["no_price"] == 1, str(rep))

rows, rep = fmp.dividend_rows([{"date": "2026-03-12", "dividend": 5.0}],
                              lambda ex: None, minor_unit=False)
check("a share quoted in a major unit needs no price and no conversion",
      rows == [("2026-03-12", None, 5.0)], str(rows))

print("--- dividends: what the mapping will not hide ---")

rows, rep = fmp.dividend_rows(
    [{"foo": "2026-03-12", "dividend": 25.0},
     {"date": "2026-03-12"},
     {"date": "2026-03-12", "dividend": "nonsense"},
     {"date": "2026-03-12", "dividend": 0},
     "not a record"], price_1000)
check("records the mapping cannot read are counted by reason, not dropped",
      rows == [] and rep["seen"] == 5 and rep["no_ex_date"] == 2 and rep["no_amount"] == 3,
      str(rep))

rows, rep = fmp.dividend_rows(
    [{"date": "2026-03-12", "paymentDate": "2026-04-30", "dividend": 25.0, "adjDividend": 12.5}],
    price_1000)
check("the amount paid is read, not the split-adjusted one",
      rows[0][2] == 25.0, str(rows))

rows, rep = fmp.dividend_rows(
    [{"date": "2026-03-12", "dividend": 25.0}, {"date": "2026-03-12", "dividend": 30.0}],
    price_1000)
check("one row per ex-date, the first listed",
      rows == [("2026-03-12", None, 25.0)] and rep["kept"] == 1, str(rows))

rows, rep = fmp.dividend_rows(
    [{"exDate": "2026-03-12T00:00:00", "payDate": "not a date", "amount": 25.0}], price_1000)
check("the other spellings of the fields are read, and a bad pay date is no pay date",
      rows == [("2026-03-12", None, 25.0)], str(rows))

print("--- dividends: when the loader asks again ---")

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "python"))
try:
    import mysql.connector                                   # noqa: F401
    import fetch_fmp_dividends as loader
    today = datetime.date(2026, 11, 18)
    check("a symbol never loaded is due", loader.dividends_due(None, today))
    check("one loaded yesterday is not", not loader.dividends_due(datetime.date(2026, 11, 17), today))
    check("one loaded a week ago is",
          loader.dividends_due(datetime.datetime(2026, 11, 11, 3, 0), today))
except ImportError as e:
    print("  SKIP  the loader needs mysql.connector ({})".format(e))

print()
if failed:
    print("\033[31mFAILURES\033[0m  {} passed, {} failed".format(passed, failed))
    sys.exit(1)
print("\033[32mALL PASSED\033[0m  {} passed, {} failed".format(passed, failed))
