#!/bin/bash
#
# Run the backtests asked for from the strategy page. Cron sees no profile,
# so the settings come from the same root-only file the other jobs use.
#
#   * * * * * /var/www/sharemanager/includes/backtest_run.sh \
#     >> /var/log/sharemanager-backtest.log 2>&1
#
# Every minute is cheap: with nothing queued it reads one table and exits.
# Only one copy of the job runs at once (a lock file inside
# run_backtests.php), so a long replay is not started twice. The job never
# runs inside the web server, sends no order and reads no broker.

set -u

APP_DIR="${SM_APP_DIR:-/var/www/sharemanager}"
ENV_FILE="${SM_ENV_FILE:-/etc/sharemanager.env}"
PHP="${SM_PHP:-php}"

if [ -r "$ENV_FILE" ]; then
  set -a
  # shellcheck disable=SC1090
  . "$ENV_FILE"
  set +a
else
  echo "backtest_run: cannot read $ENV_FILE; the job has no settings" >&2
  exit 2
fi

cd "$APP_DIR/includes" || { echo "backtest_run: $APP_DIR/includes does not exist" >&2; exit 2; }

exec "$PHP" "$APP_DIR/includes/run_backtests.php"
