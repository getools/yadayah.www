#!/bin/bash
# Picks up /opt/yada-www/public/jobs/fliphtml5/match-trigger.req (dropped by
# admin UI clicks) and runs the matcher. Removes the trigger after running.
set -uo pipefail
TRIG=/opt/yada-www/public/jobs/fliphtml5/match-trigger.req
[ -f "$TRIG" ] || exit 0
rm -f "$TRIG"
/opt/yada-www/cron-fliphtml5-match.sh >/dev/null 2>&1
