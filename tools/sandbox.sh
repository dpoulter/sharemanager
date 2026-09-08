#!/bin/bash
#
# Stand up a throwaway copy of the site with generated data.
#
#   tools/sandbox.sh              build the database and serve on :8080
#   tools/sandbox.sh --port 9000  serve somewhere else
#   tools/sandbox.sh --rebuild    drop and rebuild the data first
#   tools/sandbox.sh --no-serve   build the data and stop
#
# Nothing here touches includes/constants.php or your real database. The
# sandbox uses its own database and tests/fixtures/constants.php, which reads
# its settings from the environment:
#
#   SM_SANDBOX_DB    database name, must end in _sandbox   (default sharemanager_sandbox)
#   SM_SANDBOX_HOST  database host                         (default 127.0.0.1)
#   SM_SANDBOX_USER  database user                         (default smtest)
#   SM_SANDBOX_PASS  database password                     (default smtest)
#
# Log in as tester / testpass.

set -u

TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "$TOOLS_DIR")"

PORT=8080
REBUILD=0
SERVE=1
while [ $# -gt 0 ]; do
  case "$1" in
    --port)     PORT="${2:-8080}"; shift 2 ;;
    --rebuild)  REBUILD=1; shift ;;
    --no-serve) SERVE=0; shift ;;
    -h|--help)  sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
done

# Settings persist in tools/sandbox.env if it exists, so they do not have to be
# re-exported in every new shell. Real environment variables still win, and the
# file is gitignored because it holds a password.
ENV_FILE="$TOOLS_DIR/sandbox.env"
if [ -f "$ENV_FILE" ]; then
  # shellcheck disable=SC1090
  set -a; . "$ENV_FILE"; set +a
fi

DB="${SM_SANDBOX_DB:-sharemanager_sandbox}"
HOST="${SM_SANDBOX_HOST:-127.0.0.1}"
USER="${SM_SANDBOX_USER:-smtest}"
PASS="${SM_SANDBOX_PASS:-smtest}"

# The build drops and recreates every table, so refuse anything that is not
# obviously a sandbox. This is the only thing standing between a mistyped
# variable and your real data.
case "$DB" in
  *_sandbox) ;;
  *) echo "refusing to run: SM_SANDBOX_DB ('$DB') must end in _sandbox" >&2; exit 2 ;;
esac

command -v php >/dev/null || { echo "php not found" >&2; exit 2; }
MYSQL=$(command -v mariadb || command -v mysql) || { echo "no mysql/mariadb client found" >&2; exit 2; }

db(){ "$MYSQL" -h"$HOST" -u"$USER" -p"$PASS" "$@"; }

db -e "select 1" >/dev/null 2>&1 || {
  echo "cannot connect as '$USER' to $HOST" >&2
  echo >&2
  if [ -f "$ENV_FILE" ]; then
    echo "settings came from $ENV_FILE; check the user and password in it." >&2
  else
    echo "no $ENV_FILE, so these are the built-in defaults." >&2
    echo "If you created a different user, write the real settings once:" >&2
    echo >&2
    echo "  cp $TOOLS_DIR/sandbox.env.example $ENV_FILE" >&2
    echo "  \$EDITOR $ENV_FILE" >&2
    echo >&2
    echo "Or create the user these defaults expect:" >&2
    echo "  mysql -e \"create database if not exists $DB;" >&2
    echo "             create user '$USER'@'localhost' identified by '<password>';" >&2
    echo "             grant all on $DB.* to '$USER'@'localhost';\"" >&2
  fi
  exit 2; }

# tests/fixtures/constants.php reads the SM_TEST_* names, so map onto those.
export SM_TEST_DB="$DB" SM_TEST_HOST="$HOST" SM_TEST_USER="$USER" SM_TEST_PASS="$PASS"
INCLUDE_PATH="$REPO_DIR/tests/fixtures:$REPO_DIR/includes"

ALREADY=$(db -N "$DB" -e "select count(*) from information_schema.tables where table_schema='$DB'" 2>/dev/null || echo 0)

if [ "$REBUILD" = 1 ] || [ "${ALREADY:-0}" -lt 5 ]; then
  echo "building sandbox data in $DB"
  db "$DB" < "$REPO_DIR/tests/schema.sql"       || exit 1
  db "$DB" < "$REPO_DIR/sql/paper_trading.sql"  || exit 1
  php -d include_path="$INCLUDE_PATH" "$REPO_DIR/tests/seed.php" || exit 1
  db "$DB" < "$TOOLS_DIR/sandbox_data.sql"      || exit 1
  echo "  done"
else
  echo "using existing data in $DB (--rebuild to regenerate)"
fi

[ "$SERVE" = 1 ] || exit 0

# The stub API must be its own process. php -S serves one request at a time, so
# an application server calling a stub inside itself would deadlock.
STUB_PORT=$((PORT + 10))
php -S "127.0.0.1:$STUB_PORT" "$TOOLS_DIR/eodhd_stub.php" >/dev/null 2>&1 &
STUB_PID=$!
trap 'kill $STUB_PID 2>/dev/null' EXIT INT TERM

# Point the application at it, with a token the stub will accept. Without a
# token the stub answers 401, the same as the real API.
export EODHD_BASE_URL="http://127.0.0.1:$STUB_PORT"
export EODHD_API_KEY="sandbox-stub-token"

cat <<EOF

  sandbox ready
    url       http://127.0.0.1:$PORT/
    login     tester / testpass
    database  $DB on $HOST
    quote api stub on 127.0.0.1:$STUB_PORT, serving the seeded prices

  Ctrl-C to stop.

EOF

# opcache.enable applies under the cli-server SAPI that php -S runs, not
# opcache.enable_cli, so without this your edits are served stale.
php -S "127.0.0.1:$PORT" -t "$REPO_DIR/public" \
     -d include_path="$INCLUDE_PATH" \
     -d opcache.enable=0 \
     -d display_errors=1 \
     -d default_socket_timeout=5
