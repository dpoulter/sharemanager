#!/bin/bash
# The Mac mini's nightly refresh, at 00:30 (launchd or cron): fetch, indicators,
# then publish to the N100 - only if the two before it finished. The N100 waits
# for the publish until 03:30 and plans no strategy without it.
#
#   30 0 * * * $HOME/sharemanager-research/research/deploy/nightly.sh >> $HOME/sharemanager-research/logs/nightly.out 2>&1
#
# The Mac mini must not sleep through it (pmset; System Settings > Energy).
set -u
cd "$(dirname "$0")/.." || exit 2
echo "nightly: $(date '+%Y-%m-%d %H:%M:%S') start"
uv run research fetch       || { echo "nightly: fetch failed; nothing published" >&2; exit 1; }
uv run research indicators  || { echo "nightly: indicators failed; nothing published" >&2; exit 1; }
uv run research publish     || { echo "nightly: publish failed; the N100 stays on its previous version" >&2; exit 1; }
echo "nightly: $(date '+%Y-%m-%d %H:%M:%S') done"
