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
#     SM_T212_DEMO=on               # read and trade the practice account
#     SM_T212_DEMO_API_KEY=...      # the practice account's key
#     SM_T212_DEMO_API_SECRET=...
#     SM_T212_LIVE_READ=on          # read the live account (read-only key)
#     SM_T212_API_KEY=...
#     SM_T212_API_SECRET=...
#     SM_T212_LIVE_ORDERS=on        # SEND to the live account. Off by default.
#     SM_T212_LIVE_ORDERS_API_KEY=...     # a key of its own, Orders - Execute
#     SM_T212_LIVE_ORDERS_API_SECRET=...
#
# Two senders, because there are two accounts and they must not be reachable
# from one another. send_broker_orders.php refuses anything but the practice
# account and posts only to the demo host. send_live_orders.php sends the
# live account's orders and posts only to the live host - and sends nothing
# at all unless Dale has armed that account on its page and approved the
# night's batch. Neither this wrapper nor anything it runs can arm or
# approve: both are forms in his own logged in session.
#
# Exit code: the practice sender's, or the live one's if that was the only
# failure. The sync is not allowed to fail the job - the
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

echo "broker_run: $(date '+%Y-%m-%d %H:%M:%S') demo=${SM_T212_DEMO:-off}" \
     "live-read=${SM_T212_LIVE_READ:-off} live-orders=${SM_T212_LIVE_ORDERS:-off}" \
     "app=$APP_DIR"

# The read first. The sender no longer needs it - it reads the positions
# again itself after writing a fill up, which is what fix J was - but the
# Broker page shows the cash, the order history and the dividends, and this
# is what keeps them fresh during the day. Drop this line and the page goes
# back to showing last night's account.
"$PHP" "$APP_DIR/includes/sync_broker.php" || echo "broker_run: the sync did not finish; sending anyway"

# Then the practice account's orders.
"$PHP" "$APP_DIR/includes/send_broker_orders.php"
PRACTICE_RC=$?

# And the live account's, which does nothing unless SM_T212_LIVE_ORDERS is
# on, the account is armed and the batch approved. Run even when the
# practice sender failed: the two accounts are independent, and a rate limit
# on one is not a reason to leave an approved order unsent on the other.
"$PHP" "$APP_DIR/includes/send_live_orders.php"
LIVE_RC=$?

if [ "$PRACTICE_RC" -ne 0 ]; then exit "$PRACTICE_RC"; fi
exit "$LIVE_RC"
