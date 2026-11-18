#!/usr/bin/env python3
"""query() must not lose an UPDATE.

It called fetchall() on every statement. On an UPDATE that raises "No result
set to fetch from", the error was caught and turned into None, so the change
was never committed: insert_statistic() updates through query(), so re-running
get_api_stats.py could never correct a value, and one run printed 1,394 of
those errors.

This needs a real database, so it is skipped when SM_TEST_* is not configured;
run_tests.sh always sets it.
"""

import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "python"))

passed = failed = 0


def check(name, ok, detail=""):
    global passed, failed
    if ok:
        passed += 1
        print("  \033[32mPASS\033[0m  {}".format(name))
    else:
        failed += 1
        print("  \033[31mFAIL\033[0m  {}{}".format(name, "\n          " + detail if detail else ""))


if not os.environ.get("SM_TEST_DB"):
    print("  SKIP  SM_TEST_DB not set")
    sys.exit(0)

# run_tests.sh configures the test database under the SM_TEST_* names; the
# loaders read SM_DB_*.
os.environ.setdefault("SM_DB_NAME", os.environ["SM_TEST_DB"])
os.environ.setdefault("SM_DB_HOST", os.environ.get("SM_TEST_HOST", "127.0.0.1"))
os.environ.setdefault("SM_DB_USER", os.environ.get("SM_TEST_USER", "smtest"))
os.environ.setdefault("SM_DB_PASS", os.environ.get("SM_TEST_PASS", "smtest"))

try:
    import mysql.connector                                    # noqa: F401
except ImportError:
    print("  SKIP  mysql.connector not installed")
    sys.exit(0)

from functions import query, insert                           # noqa: E402
from share_functions import insert_statistic                  # noqa: E402

SYM, EXCH, IND = "ZTESTQ", "XLON", "test_query_indicator"
DATE = "2026-11-13"

print("\n--- query() handles statements with no result set ---")

# The first thing this hits is the bug itself: a DELETE has no result set, so
# the old query() raised here. Reported rather than left to crash the file, so
# the remaining checks still run and say what they found.
try:
    query("delete from statistics where symbol='{}'".format(SYM))
    setup_ok, setup_detail = True, ""
except Exception as e:
    setup_ok, setup_detail = False, "{}: {}".format(type(e).__name__, e)
check("a delete does not raise 'No result set to fetch from'", setup_ok, setup_detail)
if not setup_ok:
    print("\n\033[31mFAILURES\033[0m  {} passed, {} failed".format(passed, failed))
    sys.exit(1)

rows = query("select 1")
check("a select still returns rows", rows == [(1,)], repr(rows))

affected = query(
    "insert into statistics (symbol,exchange,indicator,value,date) "
    "values ('{}','{}','{}',1.0,'{}')".format(SYM, EXCH, IND, DATE))
check("an insert reports the rows it affected rather than raising",
      affected == 1, repr(affected))

# The bug: this used to raise inside query(), be swallowed, and leave the row
# untouched.
affected = query("update statistics set value=2.0 where symbol='{}'".format(SYM))
check("an update reports the rows it affected", affected == 1, repr(affected))

stored = query("select value from statistics where symbol='{}'".format(SYM))
check("the update is actually committed",
      stored and float(stored[0][0]) == 2.0, repr(stored))

print("\n--- insert_statistic corrects an existing value ---")

# Exactly what the server saw: a P/E set to -1 that a re-run would not fix.
insert_statistic(SYM, EXCH, IND, DATE, -1)
stored = query("select value from statistics where symbol='{}'".format(SYM))
check("insert_statistic updates a row that already exists",
      stored and float(stored[0][0]) == -1.0, repr(stored))

insert_statistic(SYM, EXCH, IND, DATE, 14.5)
stored = query("select value from statistics where symbol='{}'".format(SYM))
check("re-running corrects the value rather than leaving the old one",
      stored and float(stored[0][0]) == 14.5, repr(stored))

check("and does not add a second row",
      query("select count(*) from statistics where symbol='{}'".format(SYM))[0][0] == 1)

print("\n--- a failing statement raises rather than returning None ---")

try:
    query("select * from a_table_that_does_not_exist")
    ok, detail = False, "returned without raising"
except Exception as e:
    ok, detail = True, type(e).__name__
check("a bad query raises instead of silently returning None", ok, detail)

try:
    insert("insert into a_table_that_does_not_exist values (1)")
    ok = False
except Exception:
    ok = True
check("insert() raises too", ok)

query("delete from statistics where symbol='{}'".format(SYM))

print()
if failed:
    print("\033[31mFAILURES\033[0m  {} passed, {} failed".format(passed, failed))
    sys.exit(1)
print("\033[32mALL PASSED\033[0m  {} passed, 0 failed".format(passed))
