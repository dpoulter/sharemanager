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

echo "applying sql/strategy_backtest.sql"
db "$SM_TEST_DB" < "$REPO_DIR/sql/strategy_backtest.sql" > /dev/null || exit 1

echo "running provider client tests (offline, no key needed)"
python3 "$TESTS_DIR/test_eodhd.py" || PY_FAILED=1
python3 "$TESTS_DIR/test_fmp.py"        || PY_FAILED=1
python3 "$TESTS_DIR/test_fmp_loader.py" || PY_FAILED=1
python3 "$TESTS_DIR/test_job_imports.py" || PY_FAILED=1
python3 "$TESTS_DIR/test_db_functions.py" || PY_FAILED=1

echo "running tests"
php -d include_path="$INCLUDE_PATH" "$TESTS_DIR/tests.php" || PHP_FAILED=1

[ "${PY_FAILED:-0}" = 1 ] || [ "${PHP_FAILED:-0}" = 1 ] && exit 1
exit 0
