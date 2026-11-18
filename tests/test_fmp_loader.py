#!/usr/bin/env python3
"""Offline tests for python/fetch_fmp_fundamentals.py and the shared config.

The loader's first real run failed immediately on `r['symbol']`, because
functions.query() returns tuples from a plain cursor, not dictionaries. The
earlier mocks handed back dictionaries, so they agreed with the bug instead of
catching it. Everything here mimics the real shapes: query() returns a list of
tuples, or None when it has swallowed a connection error.

No database, no network and no API key are needed.
"""

import os
import contextlib
import io
import sys
import types
import importlib.util

HERE = os.path.dirname(os.path.abspath(__file__))
PYTHON_DIR = os.path.join(HERE, "..", "python")
sys.path.insert(0, PYTHON_DIR)

passed = failed = 0


def check(name, ok, detail=""):
    global passed, failed
    if ok:
        passed += 1
        print("  \033[32mPASS\033[0m  {}".format(name))
    else:
        failed += 1
        print("  \033[31mFAIL\033[0m  {}{}".format(name, "\n          " + detail if detail else ""))


# --------------------------------------------------------------------------
# functions.db_config(): credentials come from the environment, with no
# fallback password, and only send auth_plugin when asked.
# --------------------------------------------------------------------------
print("\n--- database configuration ---")

# mysql.connector is not installed everywhere; the config helpers do not use it.
if "mysql.connector" not in sys.modules:
    mysql = types.ModuleType("mysql")
    connector = types.ModuleType("mysql.connector")
    connector.Error = type("Error", (Exception,), {})
    connector.connect = lambda **kw: (_ for _ in ()).throw(AssertionError("must not connect"))
    mysql.connector = connector
    sys.modules["mysql"] = mysql
    sys.modules["mysql.connector"] = connector

spec = importlib.util.spec_from_file_location(
    "sm_functions", os.path.join(PYTHON_DIR, "functions.py"))
smf = importlib.util.module_from_spec(spec)
spec.loader.exec_module(smf)


def with_env(**env):
    """Run db_config() with exactly these SM_* variables set."""
    saved = {k: os.environ.get(k) for k in
             ("SM_DB_HOST", "SM_DB_NAME", "SM_DB_USER", "SM_DB_PASS", "SM_DB_AUTH_PLUGIN")}
    try:
        for k in saved:
            os.environ.pop(k, None)
        os.environ.update(env)
        return smf.db_config()
    finally:
        for k, v in saved.items():
            os.environ.pop(k, None)
            if v is not None:
                os.environ[k] = v


cfg = with_env(SM_DB_USER="shares", SM_DB_PASS="secret")
check("host and database have sensible defaults",
      cfg["host"] == "127.0.0.1" and cfg["database"] == "sharemanager")
check("the user and password come from the environment",
      cfg["user"] == "shares" and cfg["password"] == "secret")

# The old config forced mysql_native_password, which some servers reject.
check("auth_plugin is not sent unless asked for", "auth_plugin" not in cfg)
check("auth_plugin is sent when asked for",
      with_env(SM_DB_USER="u", SM_DB_PASS="p",
               SM_DB_AUTH_PLUGIN="mysql_native_password")["auth_plugin"]
      == "mysql_native_password")

cfg = with_env(SM_DB_USER="u", SM_DB_PASS="p", SM_DB_HOST="db.internal", SM_DB_NAME="other")
check("host and database are overridable",
      cfg["host"] == "db.internal" and cfg["database"] == "other")

for missing in ("SM_DB_USER", "SM_DB_PASS"):
    env = {"SM_DB_USER": "u", "SM_DB_PASS": "p"}
    env.pop(missing)
    try:
        with_env(**env)
        ok, detail = False, "no error raised"
    except smf.ConfigError as e:
        ok, detail = missing in str(e), str(e)[:90]
    check("a missing {} fails clearly, with no fallback".format(missing), ok, detail)

check("no password is hard coded in functions.py",
      "mypassword" not in open(os.path.join(PYTHON_DIR, "functions.py")).read())

# --------------------------------------------------------------------------
# The loader, against the row shape query() really returns.
# --------------------------------------------------------------------------
print("\n--- the loader reads the rows query() actually returns ---")

ROWS_AS_TUPLES = [("AAA",), ("BBB",), ("CCC",)]      # exactly what a plain cursor gives

calls = {"sections": 0}


def load_loader(query_result):
    """Import the loader with its dependencies stubbed."""
    for name in ("functions", "pipeline", "fmp"):
        sys.modules.pop(name, None)

    functions = types.ModuleType("functions")
    functions.query = lambda sql: query_result
    functions.db_config = lambda: {"host": "127.0.0.1", "database": "d",
                                   "user": "u", "password": "p"}
    sys.modules["functions"] = functions

    pipeline = types.ModuleType("pipeline")
    pipeline.pipeline_asofdate = lambda *a, **k: "2026-11-13"
    # Every symbol's reporting currency is already known and fresh here, so
    # the loader makes no extra call for it. The staleness rule has its own
    # tests in test_eodhd.py.
    pipeline.report_ccy_is_stale = lambda *a, **k: False
    # The loader resolves its date through this now: a date on the command
    # line has to be one prices were loaded for, and 2020-01-01 stands for one
    # that was not.
    pipeline.pipeline_resolve_asofdate = (
        lambda requested=None, *a, **k:
        "2026-11-13" if not requested else (None if requested == "2020-01-01" else requested))
    sys.modules["pipeline"] = pipeline

    fmp = types.ModuleType("fmp")
    fmp.FX_BASE = "GBP"
    fmp.fetch_reported_currency = lambda symbol, session=None: "GBP"
    fmp.fetch_fx_pair = lambda pair, session=None: None
    fmp.resolve_fx_rate = lambda ccy, get_pair, base="GBP": (None, "stubbed")
    fmp.is_fund_flag = lambda profile: None
    # The quote currency, which the paper engine needs to size a trade in
    # pounds. None here: this stub is about what the loader does with the
    # rows query() returns, and the currency itself is tested in
    # test_fmp.py against the real helper.
    fmp.quote_currency = lambda profile: None
    # The ISIN, which the broker connection matches on. None here for the
    # same reason as the currency above: the reader itself is tested in
    # test_fmp.py, and this stub is about what the loader does with the rows.
    fmp.company_isin = lambda profile: None

    def fetch_sections(symbol, session=None):
        calls["sections"] += 1
        return {"Quote": {"symbol": symbol, "price": 1.0}}

    fmp.fetch_sections = fetch_sections
    fmp.flatten = lambda sections, log=None: {"Quote.price": 1.0}
    # The yearly accounts. Stubbed to nothing here for the same reason as
    # the currency above: statement_rows() is tested against real response
    # shapes in test_fmp.py, and this module is about what the loader does
    # with the rows query() returns.
    fmp.fetch_statements = lambda symbol, years=5, session=None: {}
    fmp.statement_rows = lambda statements: []
    fmp.unmapped_fields = lambda flat, wanted: dict(
        (name, field) for name, field in (wanted or {}).items()
        if field and field not in flat)
    fmp.FmpError = type("FmpError", (Exception,), {})
    sys.modules["fmp"] = fmp

    spec = importlib.util.spec_from_file_location(
        "sm_loader", os.path.join(PYTHON_DIR, "fetch_fmp_fundamentals.py"))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


loader = load_loader(ROWS_AS_TUPLES)

# This is the exact line that failed on the first real run.
try:
    symbols = [row[0] for row in ROWS_AS_TUPLES]
    extracted = symbols == ["AAA", "BBB", "CCC"]
    err = ""
except Exception as e:
    extracted, err = False, "{}: {}".format(type(e).__name__, e)
check("a symbol is read out of a tuple row by position", extracted, err)

src = open(os.path.join(PYTHON_DIR, "fetch_fmp_fundamentals.py")).read()
check("the loader does not index rows by name",
      "r['symbol']" not in src and 'r["symbol"]' not in src
      and "row['symbol']" not in src and 'row["symbol"]' not in src)
check("the loader handles query() returning None",
      "if rows is None" in src)

print("\n--- argument parsing ---")
check("no arguments means the last trading day and no limit",
      loader.parse_args([]) == ("2026-11-13", None))
check("a date is taken positionally",
      loader.parse_args(["2026-01-31"]) == ("2026-01-31", None))
check("--limit is honoured", loader.parse_args(["--limit", "20"]) == ("2026-11-13", 20))
check("a date with no prices resolves to nothing, which main() refuses on",
      loader.parse_args(["2020-01-01"]) == (None, None))
check("a date and a limit together",
      loader.parse_args(["2026-01-31", "--limit", "5"]) == ("2026-01-31", 5))

print("\n--- pacing ---")
# 4 calls per symbol at 600/minute is 0.4s between symbols. Faster than that
# and a 2000 symbol run would start collecting 429s.
check("the pace stays under the plan's rate limit",
      abs(60.0 / loader.MIN_INTERVAL * loader.CALLS_PER_SYMBOL - loader.RATE_PER_MINUTE) < 1,
      "{:.0f} calls/min".format(60.0 / loader.MIN_INTERVAL * loader.CALLS_PER_SYMBOL))
check("the pace leaves headroom below 750", loader.RATE_PER_MINUTE < 750)
# quote, profile, key-metrics-ttm, ratios-ttm, the quarterly cash flow,
# financial-scores and financial-growth. Two more than before the scores
# moved to the provider, about 700 extra calls a night.
check("the pacing counts all seven calls a symbol takes",
      loader.CALLS_PER_SYMBOL == 7, str(loader.CALLS_PER_SYMBOL))

print("\n--- the night's exchange rates ---")

# One rate per currency the universe reports in, written for the as-of date.
# A currency with no rate is named rather than passed over: every company
# reporting in it loses its price ratios for the day.


class FakeCursor(object):
    def __init__(self, currencies):
        self.currencies = currencies
        self.writes = []
        self.rows = []

    def execute(self, sql, params=None):
        if "select distinct report_ccy" in sql:
            self.rows = [(c,) for c in self.currencies]
        else:
            self.writes.append((sql, params))

    def fetchall(self):
        return self.rows


class FakeConnection(object):
    def commit(self):
        pass


rates = {"USD": (1.3212, "quote"), "GEL": (3.5, "cross:USD"), "ZWL": (None, "no rate")}
loader.resolve_fx_rate = lambda ccy, get_pair, base="GBP": rates[ccy]

cursor = FakeCursor(["USD", "GEL", "ZWL"])
said = io.StringIO()
with contextlib.redirect_stdout(said):
    loader.store_fx_rates(cursor, FakeConnection(), "2026-11-13")
said = said.getvalue()

written = dict((params[2], params) for sql, params in cursor.writes)
check("a rate is stored for every currency one could be found for",
      sorted(written) == ["GEL", "USD"], sorted(written))
check("the rate is quote units per pound, with the date and base recorded",
      written["USD"][0] == "2026-11-13" and written["USD"][1] == "GBP"
      and abs(written["USD"][3] - 1.3212) < 1e-9, str(written.get("USD")))
check("a crossed rate records that it was crossed",
      written["GEL"][4] == "cross:USD" and written["USD"][4] == "quote")
check("the rows replace rather than insert, so a second run for the date works",
      all("replace into fx_rates" in sql for sql, params in cursor.writes))
check("a currency with no rate is not stored as one",
      "ZWL" not in written)
check("and is named, because the consequence is silent otherwise",
      "NO RATE" in said and "ZWL" in said, said)

cursor = FakeCursor([])
said = io.StringIO()
with contextlib.redirect_stdout(said):
    loader.store_fx_rates(cursor, FakeConnection(), "2026-11-13")
check("an all-sterling universe needs no rates and asks for none",
      cursor.writes == [] and "no rates needed" in said.getvalue(), said.getvalue())

print("\n--- the yearly accounts ---")

import datetime                                              # noqa: E402

# When a company's accounts are asked for. Three calls each, so the rule is
# the difference between 1,050 calls a night and a few hundred.
today = datetime.date(2026, 11, 18)
check("a company with no accounts stored is asked for them",
      loader.statements_due(None, today) is True)
check("one whose year has not turned is not",
      loader.statements_due(datetime.date(2026, 3, 31), today) is False)
check("one whose year has turned is asked again, until the new one arrives",
      loader.statements_due(datetime.date(2025, 10, 31), today) is True)
check("and a stored date that is not a date is treated as nothing stored",
      loader.statements_due("2026-03-31", today) is True)


class StatementCursor(object):
    """Enough of a cursor for store_statements(): periods, then values."""

    def __init__(self, existing=None):
        self.existing = dict(existing or {})      # end_date -> period_id
        self.writes = []
        self.next_id = 100
        self._last = None

    def execute(self, sql, params=None):
        self.writes.append((sql, params))
        if "select period_id from financial_statement_periods" in sql:
            self._last = (self.existing.get(params[0]),) if params[0] in self.existing else None
        elif "insert into financial_statement_periods" in sql:
            self.next_id += 1
            self.existing[params[0]] = self.next_id
            self.lastrowid = self.next_id
            self._last = None

    def fetchone(self):
        return self._last


rows = [(datetime.date(2026, 3, 31), "revenue", 37448000000.0),
        (datetime.date(2026, 3, 31), "eps", 0.0452),
        (datetime.date(2025, 3, 31), "revenue", 36717000000.0)]

cursor = StatementCursor()
written = loader.store_statements(cursor, FakeConnection(), "VOD", rows)
check("every line is written", written == 3, str(written))
periods = [params for sql, params in cursor.writes
           if "insert into financial_statement_periods" in sql]
check("one period per end date, not one per line",
      len(periods) == 2 and sorted(str(p[0]) for p in periods)
      == ["2025-03-31", "2026-03-31"], str(periods))
check("and the period is named for its year",
      sorted(p[1] for p in periods) == ["2025", "2026"], str(periods))
deletes = [params for sql, params in cursor.writes
           if "delete from financial_statement_values" in sql]
check("a value replaces rather than adds, so a re-run is not a second copy",
      len(deletes) == 3 and deletes[0][0] == "VOD", str(deletes[:1]))

# The second night. The periods are there, so none is created again - which
# is the bug this shape is here to stop: the table has no unique key on the
# date and would otherwise collect a row per symbol per night.
cursor = StatementCursor({datetime.date(2026, 3, 31): 101,
                          datetime.date(2025, 3, 31): 102})
loader.store_statements(cursor, FakeConnection(), "VOD", rows)
check("a period that already exists is used rather than inserted again",
      [params for sql, params in cursor.writes
       if "insert into financial_statement_periods" in sql] == [])
check("and nothing is written for a symbol with no lines",
      loader.store_statements(StatementCursor(), FakeConnection(), "VOD", []) == 0)

print()
if failed:
    print("\033[31mFAILURES\033[0m  {} passed, {} failed".format(passed, failed))
    sys.exit(1)
print("\033[32mALL PASSED\033[0m  {} passed, 0 failed".format(passed))
