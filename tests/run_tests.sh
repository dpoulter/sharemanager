#!/bin/bash
#
# Regression suite for the momentum batch jobs and the dashboard queries.
#
#   tests/run_tests.sh
#
# Creates a throwaway database, loads tests/schema.sql, seeds it and runs
# tests/tests.php. Nothing here touches the application's own constants.php:
# tests/fixtures is placed first on the include path so every relative
# require("constants.php") resolves to the test copy, which reads the settings
# below from the environment.
#
#   SM_TEST_DB    database name, must end in _test   (default sharemanager_test)
#   SM_TEST_HOST  database host                      (default 127.0.0.1)
#   SM_TEST_USER  database user                      (default smtest)
#   SM_TEST_PASS  database password                  (default smtest)
#
# The user needs create and drop on that database. The suite drops and recreates
# every table it uses on each run.

set -u

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "$TESTS_DIR")"

export SM_TEST_DB="${SM_TEST_DB:-sharemanager_test}"
export SM_TEST_HOST="${SM_TEST_HOST:-127.0.0.1}"
export SM_TEST_USER="${SM_TEST_USER:-smtest}"
export SM_TEST_PASS="${SM_TEST_PASS:-smtest}"

# Refuse to run against anything not obviously a test database. The suite
# truncates tables constantly and would destroy real data.
case "$SM_TEST_DB" in
  *_test) ;;
  *) echo "refusing to run: SM_TEST_DB ('$SM_TEST_DB') must end in _test" >&2; exit 2 ;;
esac

command -v php >/dev/null   || { echo "php not found" >&2; exit 2; }
MYSQL=$(command -v mariadb || command -v mysql) \
  || { echo "no mysql/mariadb client found" >&2; exit 2; }

db(){ "$MYSQL" -h"$SM_TEST_HOST" -u"$SM_TEST_USER" -p"$SM_TEST_PASS" "$@"; }

echo "database $SM_TEST_USER@$SM_TEST_HOST/$SM_TEST_DB"
db -e "select 1" >/dev/null 2>&1 || {
  echo "cannot connect. Create the database and user first, e.g.:" >&2
  echo "  create database $SM_TEST_DB;" >&2
  echo "  create user '$SM_TEST_USER'@'%' identified by '<password>';" >&2
  echo "  grant all on $SM_TEST_DB.* to '$SM_TEST_USER'@'%';" >&2
  exit 2; }

echo "loading schema"
db "$SM_TEST_DB" < "$TESTS_DIR/schema.sql" || exit 1

INCLUDE_PATH="$TESTS_DIR/fixtures:$REPO_DIR/includes"
echo "seeding"
php -d include_path="$INCLUDE_PATH" "$TESTS_DIR/seed.php" || exit 1

# Migrations layer on top of the seeded baseline, the way they do on a real
# database. After the seed, so the screens it creates with explicit ids are
# already there and the migration's auto-increment ids do not collide.
#
# The trend screens and their three indicators are applied here rather than
# being written into schema.sql so the suite runs against the same shape of
# database as a migrated production one, and so the nightly job calculates
# them the way it does there.
echo "applying sql/trend_screens.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/trend_screens.sql" > /dev/null || exit 1

echo "applying sql/ai_outlook.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/ai_outlook.sql" > /dev/null || exit 1

echo "applying sql/fund_sectors.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/fund_sectors.sql" > /dev/null || exit 1

echo "applying sql/report_currency.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/report_currency.sql" > /dev/null || exit 1

echo "applying sql/shareholder_yield.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/shareholder_yield.sql" > /dev/null || exit 1

echo "applying sql/fmp_scores.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/fmp_scores.sql" > /dev/null || exit 1

echo "applying sql/news.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/news.sql" > /dev/null || exit 1

echo "applying sql/trending_value.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/trending_value.sql" > /dev/null || exit 1

echo "applying sql/rns.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/rns.sql" > /dev/null || exit 1

# After sql/trend_screens.sql and sql/trending_value.sql: the strategies it
# defines are matched to those screens by name, so the screens have to exist
# or the rules rows come out with a null screen_id.
echo "applying sql/strategy_rules.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/strategy_rules.sql" > /dev/null || exit 1

echo "applying sql/strategy_rules_updated_at.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/strategy_rules_updated_at.sql" > /dev/null || exit 1

echo "applying sql/strategy_hold_rank.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/strategy_hold_rank.sql" > /dev/null || exit 1

echo "applying sql/strategy_display_name.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/strategy_display_name.sql" > /dev/null || exit 1

echo "applying sql/strategy_backtest.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/strategy_backtest.sql" > /dev/null || exit 1

echo "applying sql/strategy_backtest_overrides.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/strategy_backtest_overrides.sql" > /dev/null || exit 1

echo "applying sql/strategy_backtest_costs.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/strategy_backtest_costs.sql" > /dev/null || exit 1

echo "applying sql/user_theme.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/user_theme.sql" > /dev/null || exit 1

echo "applying sql/market_breadth.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/market_breadth.sql" > /dev/null || exit 1

echo "applying sql/statistics_indicator_index.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/statistics_indicator_index.sql" > /dev/null || exit 1

echo "applying sql/screen_last_run.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/screen_last_run.sql" > /dev/null || exit 1

# After sql/rns.sql, which adds the column stock_symbols.isin sits after.
echo "applying sql/broker.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/broker.sql" > /dev/null || exit 1

echo "applying sql/broker_account_fields.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/broker_account_fields.sql" > /dev/null || exit 1

echo "applying sql/broker_mapping.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/broker_mapping.sql" > /dev/null || exit 1

# After sql/paper_trading.sql and sql/broker.sql: it reshapes columns both of
# them create.
echo "applying sql/broker_trading.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/broker_trading.sql" > /dev/null || exit 1

echo "applying sql/broker_fills.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/broker_fills.sql" > /dev/null || exit 1

# After every broker table exists: it adds a column to five of them.
echo "applying sql/broker_environment.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/broker_environment.sql" > /dev/null || exit 1

# Whether the order book was visible when the last resolve ran. Needs nothing
# else. The file keeps its name from an earlier version; it makes one table.
echo "applying sql/broker_key_scopes.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/broker_key_scopes.sql" > /dev/null || exit 1

# The figures the one-screen quote page shows: the one month return, the two
# ends of the year, return on capital, operating margin, the lines of the
# yearly accounts, and the in_score column that keeps a new measure out of
# the scores until somebody asks for it. After the files above, because it
# edits rows they create.
echo "applying sql/quote_report.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/quote_report.sql" > /dev/null || exit 1

# Live orders, stage B: arming, the batches a live account's orders wait in,
# the approval column and the log that is only ever added to. After
# sql/broker_trading.sql, which is what makes strategy_orders a broker table
# at all, and after sql/paper_trading.sql's accounts.
echo "applying sql/strategy_live.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/strategy_live.sql" > /dev/null || exit 1

# After sql/report_currency.sql, which adds the column this one sits after.
echo "applying sql/strategy_prices.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/strategy_prices.sql" > /dev/null || exit 1

# The workflow's two columns: archived_at on the rules and the snapshot and
# fingerprint on a backtest run. After sql/strategy_rules.sql and
# sql/strategy_backtest.sql, which create the tables it alters.
echo "applying sql/strategy_workflow.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/strategy_workflow.sql" > /dev/null || exit 1

# The queue columns on a backtest run: who asked, when, the cost model and the
# cash. After sql/strategy_workflow.sql.
echo "applying sql/backtest_queue.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/backtest_queue.sql" > /dev/null || exit 1

# Backtests on the Mac mini research engine: which engine ran a run, the claim,
# and the results it posts back. After sql/backtest_queue.sql.
echo "applying sql/backtest_mac_mini.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/backtest_mac_mini.sql" > /dev/null || exit 1

echo "applying sql/backtest_dsr.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/backtest_dsr.sql" > /dev/null || exit 1

# Every page at 390px. A check on the layout rather than on the queries, so a
# host with no browser skips it rather than failing: the application is used on
# a phone, and the fault it catches - a table that makes the page itself scroll
# sideways, taking the fixed navbar with it - is invisible from a desktop.
if command -v node >/dev/null 2>&1; then
  echo "running phone width checks (390px)"
  node "$TESTS_DIR/phone_widths.js" || PHONE_FAILED=1
else
  echo "  SKIP  phone widths: node is not installed"
fi

echo "running provider client tests (offline, no key needed)"
python3 "$TESTS_DIR/test_eodhd.py" || PY_FAILED=1
python3 "$TESTS_DIR/test_fmp.py"        || PY_FAILED=1
python3 "$TESTS_DIR/test_fmp_loader.py" || PY_FAILED=1
python3 "$TESTS_DIR/test_job_imports.py" || PY_FAILED=1
python3 "$TESTS_DIR/test_db_functions.py" || PY_FAILED=1

echo "running tests"
php -d include_path="$INCLUDE_PATH" "$TESTS_DIR/tests.php" || PHP_FAILED=1

# Last, because it empties the database: every page against a database where
# the tables exist and no job has ever run. That is the first night after a
# deploy, when every empty state on every page is being seen for the first
# time. The schema is reloaded at the top of this script, so leaving it empty
# costs nothing.
echo "running empty database checks"
php -d include_path="$INCLUDE_PATH" "$TESTS_DIR/empty_db.php" || EMPTY_FAILED=1

# And put it back. The check above leaves the database empty, which is fine for
# the next run - the schema is reloaded at the top - but not for anybody who
# wants to look at the application afterwards. Everything above has finished,
# so this cannot affect a result.
echo "restoring the seeded database"
db "$SM_TEST_DB" < "$TESTS_DIR/schema.sql" > /dev/null \
  && php -d include_path="$INCLUDE_PATH" "$TESTS_DIR/seed.php" > /dev/null \
  && for migration in trend_screens ai_outlook fund_sectors report_currency \
                      shareholder_yield fmp_scores news trending_value rns \
                      strategy_rules strategy_rules_updated_at \
                      user_theme market_breadth statistics_indicator_index screen_last_run \
                      strategy_hold_rank strategy_display_name \
                      strategy_backtest strategy_backtest_overrides strategy_backtest_costs \
                      strategy_prices strategy_workflow backtest_queue backtest_mac_mini backtest_dsr \
                      broker_key_scopes \
                      broker broker_account_fields broker_mapping broker_trading \
                      broker_fills broker_environment strategy_live quote_report; do
       db "$SM_TEST_DB" < "$REPO_DIR/sql/$migration.sql" > /dev/null
     done

# The seed holds prices and a portfolio; the statistics are calculated, and the
# jobs that calculate them are what the suite runs. Without this the restored
# database has no scores, so the dashboard and the screens are empty for
# anybody who looks at the application afterwards.
SM_DB_NAME="$SM_TEST_DB" SM_DB_HOST="$SM_TEST_HOST" \
SM_DB_USER="$SM_TEST_USER" SM_DB_PASS="$SM_TEST_PASS" SM_EXCHANGE=XLON \
  sh -c "php -d include_path='$INCLUDE_PATH' '$REPO_DIR/includes/get_momentum_statistics.php' > /dev/null 2>&1;
         php -d include_path='$INCLUDE_PATH' '$REPO_DIR/includes/get_statistics.php' > /dev/null 2>&1;
         php -d include_path='$INCLUDE_PATH' '$REPO_DIR/includes/write_market_breadth.php' > /dev/null 2>&1"

if [ "${PY_FAILED:-0}" = 1 ] || [ "${PHP_FAILED:-0}" = 1 ] \
   || [ "${PHONE_FAILED:-0}" = 1 ] || [ "${EMPTY_FAILED:-0}" = 1 ]; then
  exit 1
fi
exit 0
