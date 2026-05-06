#!/bin/bash
# Hourly: ask FlipHTML5 for the list of books on the account, fuzzy-match
# each one to a yy_volume row (by volume_pdf or volume_code), and populate
# volume_flip_code for any unmatched volumes.
#
# Why: /edit-book/upload is bot-blocked, so we can't auto-upload PDFs to
# FlipHTML5 from this script. The admin's only manual step is to drop PDFs
# into FlipHTML5's dashboard. Once a book exists on FlipHTML5, this script
# picks up its code and wires it to the matching volume — after which the
# book-pipeline worker's re-upload path takes over for all future docx
# updates.

set -uo pipefail

PG_CONTAINER=yada-postgres-prod
RSSHUB_CONTAINER=yada-www-rsshub-1
LOG=/var/log/fliphtml5-match.log
LOCK=/var/lock/fliphtml5-match.lock

exec 9>"$LOCK"
flock -n 9 || exit 0

ts() { date '+%Y-%m-%d %H:%M:%S'; }
log() { echo "[$(ts)] $*" >> "$LOG"; }

# We hold the only legitimate flock right now; any chrome/Xvfb still alive
# inside rsshub is a leftover from a prior crashed/aborted run. Reap them
# before launching a new puppeteer session so they don'''t accumulate and
# starve the host (we hit 240+ zombie chromes in production once).
docker exec "$RSSHUB_CONTAINER" sh -c "pkill -9 -f 'chrome.*--remote-debugging-port' 2>/dev/null; pkill -9 -f Xvfb 2>/dev/null; rm -rf /tmp/lighthouse.* 2>/dev/null; true" 2>/dev/null

log "Starting match run"

# Test-framework heartbeat — Test #N "fliphtml5-match cron alive" reads this.
docker exec -i yada-postgres-prod psql -U postgres -d yada -v ON_ERROR_STOP=1 >/dev/null 2>&1 <<SQL || true
INSERT INTO yy_monitor_event (event_source, event_severity, event_message)
VALUES ('fliphtml5_match', 'info', 'cron-fliphtml5-match.sh started');
SQL

CHROME_PATH=$(docker exec "$RSSHUB_CONTAINER" find /app/node_modules/.cache/puppeteer -name "chrome" -type f 2>/dev/null | head -1)
if [ -z "$CHROME_PATH" ]; then
    log "No chrome binary in rsshub container"
    exit 1
fi

match_json=$(docker exec -e CHROME_PATH="$CHROME_PATH" "$RSSHUB_CONTAINER" \
    bash -c 'Xvfb :99 -screen 0 1280x900x24 -ac -nolisten tcp >/dev/null 2>&1 & sleep 1; DISPLAY=:99 node /scraper/yy/fliphtml5-match.cjs 2>/dev/null')
match_rc=$?

if [ "$match_rc" -ne 0 ] || [ -z "$match_json" ]; then
    log "Matcher failed (rc=$match_rc)"
    exit 1
fi

# Hand the JSON to a Python helper that does the fuzzy match + DB writes.
# (Bash JSON manipulation + psql escaping is too fiddly to do inline.)
result=$(MATCH_JSON="$match_json" /usr/bin/python3 /opt/yada-www/cron-fliphtml5-match.py 2>>"$LOG")
log "Match result: $result"
echo "$result"
