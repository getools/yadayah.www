#!/bin/bash
# cron-flipbook-retry.sh — Hourly sweep that re-runs flipbook-download-host.sh
# for any volume whose flipbook download failed transiently. Catches:
#   - FlipHTML5 login throttling that didn't clear within the wrapper's
#     inline retry budget (~5 min total)
#   - Books still rendering at FlipHTML5 (render can take 10+ min after
#     a fresh upload)
#   - rsshub container restart / temporary unavailability
#
# Selection criterion:
#   volume_flip_code IS NOT NULL                 -- something to download
#   AND volume has no extracted package on disk  -- not already done
#   AND volume_pipeline_status = 'warning'       -- wrapper marked it transient
#                                                   (NOT 'error' — those need
#                                                    human action; NOT 'running'
#                                                    — currently in flight)
#
# Cap at one volume per cron tick so we don't burst FlipHTML5 logins. The
# next tick handles the next volume. With cron at :07 hourly and a few
# pending retries, the queue drains over hours which is fine — these are
# all "eventually works" cases.

set -uo pipefail
LOG=/var/log/flipbook-download.log
LOCK=/var/lock/flipbook-retry.lock
FLIP_DIR=/opt/yada-www/public/flipbook
WRAPPER=/opt/yada-www/flipbook-download-host.sh

exec >> "$LOG" 2>&1
echo "===== $(date -Is) cron-flipbook-retry start ====="

# Single-instance: if a previous tick is still running, skip.
exec 9>"$LOCK"
if ! flock -n 9; then
    echo "previous run still active; skip"
    exit 0
fi

# Pre-flight gate (same as wrapper). If memory is tight, skip silently —
# next tick will reassess.
free_mb=$(awk '/MemAvailable:/ {print int($2/1024)}' /proc/meminfo)
if [ "${free_mb:-0}" -lt 700 ]; then
    echo "skip: only ${free_mb}MB free"
    exit 0
fi

PSQL="docker exec -i yada-postgres-prod psql -U postgres -d yada -t -A"

# Pick ONE volume that needs retry. Order by volume_pipeline_dtime so the
# longest-stuck candidate is tried first (gives recently-failed ones a
# breather to clear rate limits).
VOLUME_KEY=$($PSQL <<'SQL' | tr -d '[:space:]'
SELECT v.volume_key
FROM yy_volume v
WHERE v.volume_flip_code IS NOT NULL
  AND COALESCE(v.volume_pipeline_status,'') = 'warning'
  AND COALESCE(v.volume_pipeline_message,'') ILIKE '%FlipHTML5 download failed%'
ORDER BY v.volume_pipeline_dtime NULLS FIRST
LIMIT 1
SQL
)

if [ -z "$VOLUME_KEY" ]; then
    echo "no candidates for retry"
    exit 0
fi

# Skip if the flipbook is actually already extracted on disk (race-fixup:
# wrapper's success path already updates DB, but be defensive).
FLIP_CODE=$($PSQL -c "SELECT volume_flip_code FROM yy_volume WHERE volume_key=$VOLUME_KEY" | tr -d '[:space:]')
if [ -n "$FLIP_CODE" ] && [ -d "$FLIP_DIR/$FLIP_CODE" ] && [ -n "$(ls -A "$FLIP_DIR/$FLIP_CODE" 2>/dev/null)" ]; then
    echo "volume $VOLUME_KEY already extracted at $FLIP_DIR/$FLIP_CODE — clearing stale 'warning'"
    $PSQL -c "UPDATE yy_volume SET volume_pipeline_status='success',
              volume_pipeline_message='FlipHTML5 offline package present on disk (cron sweep)'
              WHERE volume_key=$VOLUME_KEY" >/dev/null
    exit 0
fi

echo "retrying volume $VOLUME_KEY (flip_code=$FLIP_CODE)"
"$WRAPPER" "$VOLUME_KEY"
rc=$?
echo "===== $(date -Is) cron-flipbook-retry done volume=$VOLUME_KEY rc=$rc ====="
exit 0
