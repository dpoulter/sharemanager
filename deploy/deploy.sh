#!/bin/bash
#
# Pull a branch onto the server and restart PHP so it is actually served.
#
#   sudo deploy/deploy.sh [branch]
#
# Defaults to claude/code-review-z7ysrs. Run it from the deployed checkout
# (/var/www/sharemanager), not from a clone somewhere else.
#
# This exists because "pushed" and "deployed" are different things, and the
# gap between them cost a debugging session: the server kept returning an
# error from code that had been replaced three commits earlier, and every
# report of the fix was true and useless at the same time.
#
# Restarting php-fpm is not optional. opcache holds compiled copies of the
# old files, so a pull alone can leave the previous code being served with a
# new commit sitting on disk - which looks exactly like the fix not working.
#
# What this does NOT do: migrations, asset fetching, or anything to the
# database. sql/*.sql and tools/fetch_assets.sh are still run by hand, on
# purpose - a script that silently migrates is a script that silently
# migrates the wrong database one day.

set -euo pipefail

BRANCH="${1:-claude/code-review-z7ysrs}"
SERVICE="${SM_PHP_SERVICE:-php8.3-fpm}"
APP_DIR="${SM_APP_DIR:-$(pwd)}"

cd "$APP_DIR"

if [ ! -d .git ]; then
  echo "deploy: $APP_DIR is not a git working tree. Run this from the deployed" >&2
  echo "        directory, or set SM_APP_DIR to it." >&2
  exit 2
fi

echo "deploy: $APP_DIR"
echo "deploy: was at $(git log -1 --oneline)"

# A dirty tree means someone edited the server by hand. Stop rather than
# throw their change away or produce a confusing merge failure halfway
# through; they need to see it and decide.
if [ -n "$(git status --porcelain)" ]; then
  echo "deploy: refusing to pull, there are uncommitted changes here:" >&2
  git status --short >&2
  echo "deploy: commit, stash or discard them first." >&2
  exit 1
fi

echo "deploy: fetching"
git fetch origin --prune

if ! git rev-parse --verify --quiet "origin/$BRANCH" > /dev/null; then
  echo "deploy: origin/$BRANCH does not exist" >&2
  exit 1
fi

echo "deploy: checking out $BRANCH"
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"

# The restart is the point. Without it the pull above changes the files on
# disk and nothing else.
echo "deploy: restarting $SERVICE"
systemctl restart "$SERVICE"
systemctl --no-pager --quiet is-active "$SERVICE" \
  || { echo "deploy: $SERVICE did not come back up" >&2; systemctl --no-pager status "$SERVICE" >&2; exit 1; }

echo
echo "deploy: now at $(git log -1 --oneline)"
echo "deploy: done. If a page still behaves like the old code, it is not"
echo "        this checkout being served - check the php-fpm pool's docroot."
