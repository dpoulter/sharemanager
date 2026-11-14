#!/bin/bash
#
# Nightly refresh. Run from cron as root:
#
#   0 2 * * * /var/www/sharemanager/includes/refresh_all.sh >> /var/log/sharemanager-refresh.log 2>&1
#
# Cron does not see the php-fpm pool, a login shell or any profile, so the
# settings come from an env file that only root can read:
#
#   sudo install -m 600 /dev/null /etc/sharemanager.env
#   sudo editor /etc/sharemanager.env
#
#   SM_DB_HOST=127.0.0.1
#   SM_DB_NAME=sharemanager
#   SM_DB_USER=shares
#   SM_DB_PASS=the-database-password
#   SM_DATA_PROVIDER=fmp          # or eodhd
#   FMP_API_KEY=the-fmp-key       # when SM_DATA_PROVIDER=fmp
#   EODHD_API_KEY=the-eodhd-key   # when SM_DATA_PROVIDER=eodhd
#
# Mode 600 matters: the file holds the database password and an API key.

set -u

APP_DIR="${SM_APP_DIR:-/var/www/sharemanager}"
ENV_FILE="${SM_ENV_FILE:-/etc/sharemanager.env}"
PHP="${SM_PHP:-php}"
PYTHON="${SM_PYTHON:-python3}"

if [ -r "$ENV_FILE" ]; then
  set -a
  # shellcheck disable=SC1090
  . "$ENV_FILE"
  set +a
else
  echo "refresh_all: cannot read $ENV_FILE; the jobs have no credentials" >&2
  exit 2
fi

if [ ! -d "$APP_DIR" ]; then
  echo "refresh_all: $APP_DIR does not exist (set SM_APP_DIR)" >&2
  exit 2
fi

PROVIDER="${SM_DATA_PROVIDER:-eodhd}"
echo "refresh_all: $(date '+%Y-%m-%d %H:%M:%S') provider=$PROVIDER app=$APP_DIR"

# Fundamentals, from whichever provider is configured. Both are supported; see
# python/README-fmp.md and python/README-eodhd.md. fetch_stock_info.py
# (yfinance) is kept as a fallback but is no longer run.
if [ "$PROVIDER" = "fmp" ]; then
  "$PYTHON" "$APP_DIR/python/fetch_fmp_fundamentals.py"   || echo "refresh_all: fmp fundamentals failed" >&2
else
  "$PYTHON" "$APP_DIR/python/fetch_eodhd_fundamentals.py" || echo "refresh_all: eodhd fundamentals failed" >&2
fi

"$PYTHON" "$APP_DIR/python/get_api_stats.py"

"$PHP" "$APP_DIR/includes/get_share_prices.php"

# Incremental by default (most recent as of date per symbol). To rebuild
# history run manually with a date range, e.g.
#   php /var/www/sharemanager/includes/get_momentum_statistics.php 2015-01-01 2025-12-31
"$PHP" "$APP_DIR/includes/get_momentum_statistics.php"
"$PHP" "$APP_DIR/includes/get_statistics.php"
"$PHP" "$APP_DIR/includes/performance_function.php"

echo "refresh_all: $(date '+%Y-%m-%d %H:%M:%S') done"
