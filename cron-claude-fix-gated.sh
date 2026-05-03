#!/bin/bash
# Wrapper around cron-claude-fix.sh that bails out if the host is under
# memory or load pressure. The Claude AI agent uses ~200 MB resident plus
# significant CPU; running it during a heavy book-pipeline tick can push
# the host into thrashing.
free_mb=$(awk '/MemAvailable:/ {print int($2/1024)}' /proc/meminfo)
load_now=$(awk '{print $1}' /proc/loadavg)
if [ "${free_mb:-0}" -lt 1500 ]; then
    echo "[$(date '+%F %T')] Skip claude-fix: only ${free_mb}MB free"
    exit 0
fi
if awk -v l="$load_now" 'BEGIN{exit !(l>4.0)}'; then
    echo "[$(date '+%F %T')] Skip claude-fix: load $load_now > 4.0"
    exit 0
fi
exec /opt/yada-www/api/cron-claude-fix.sh "$@"
