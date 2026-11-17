#!/bin/bash
#
# The daytime broker job: read the Trading 212 account, then send what the
# nightly run planned. Run from cron as root, every ten minutes through the
# London session:
#
#   0,10,20,30,40,50 8-16 * * 1-5 /var/www/sharemanager/includes/broker_run.sh \
#     >> /var/log/sharemanager-broker.log 2>&1
#
# This wrapper exists because cron sees no login shell, no profile and no
# php-fpm pool. A crontab line that runs php directly gets none of
# SM_T212_ENV, SM_T212_DEMO_API_KEY or the database login, so the sender
# refuses every time it fires and nothing is sent - quietly, because
# refusing is also what it does when the feature is switched off.
#
# The settings come from the same root-only file the nightly run uses:
#
#   /etc/sharemanager.env   (mode 600: it holds the database password and
#                            the broker key)
#
#     SM_T212_ENV=demo              # off, live-read or demo
#     SM_T212_DEMO_API_KEY=...      # the practice account's key
#     SM_T212_DEMO_API_SECRET=...
#
# DEMO ONLY. The sender refuses to run unless SM_T212_ENV=demo, and the
# order call underneath it refuses any host but the demo one.
#
# Exit code: the sender's. The sync is not allowed to fail the job - the
# sender no longer depends on it, and a 429 on the dividend history is not a
# reason to skip the orders.

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
  echo "broker_run: cannot read $ENV_FILE; the job has no credentials" >&2
  exit 2
fi

if [ ! -d "$APP_DIR" ]; then
  echo "broker_run: $APP_DIR does not exist (set SM_APP_DIR)" >&2
  exit 2
fi

echo "broker_run: $(date '+%Y-%m-%d %H:%M:%S') env=${SM_T212_ENV:-off} app=$APP_DIR"

# The read first. The sender no longer needs it - it reads the positions
# again itself after writing a fill up, which is what fix J was - but the
# Broker page shows the cash, the order history and the dividends, and this
# is what keeps them fresh during the day. Drop this line and the page goes
# back to showing last night's account.
"$PHP" "$APP_DIR/includes/sync_broker.php" || echo "broker_run: the sync did not finish; sending anyway"

# Then the orders.
"$PHP" "$APP_DIR/includes/send_broker_orders.php"
