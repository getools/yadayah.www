#!/bin/bash
# Host-side cron wrapper for the monitor.
# Captures Docker logs, feeds them to the PHP monitor inside the container.
#
# Crontab: */15 * * * * /opt/yada-www/cron-monitor.sh >> /var/log/yada-monitor.log 2>&1

WEB=yada-www-web-1
TMP=/tmp/yada_php_errors.txt

# 1. Capture PHP errors from Docker logs (last 15 min)
docker logs --since 15m "$WEB" 2>&1 | grep -i "PHP \(Fatal\|Warning\|Parse\|Notice\)" > "$TMP" 2>/dev/null

# 2. Copy into container
docker cp "$TMP" "$WEB":/tmp/php_errors.txt 2>/dev/null

# 3. Run the monitor
docker exec "$WEB" php /var/www/html/api/cron-monitor.php

# 4. Cleanup
rm -f "$TMP"

# 5. Hourly: run fliphtml5 matcher if not run in last 55 min.
# Replaces a standalone cron entry; runs here because this script executes as root.
_flip_age_min=$(docker exec -i yada-postgres-prod psql -U postgres -d yada -t -A \
    -c "SELECT COALESCE(EXTRACT(EPOCH FROM (NOW()-max(event_dtime)))/60, 9999) \
        FROM yy_monitor_event WHERE event_source='fliphtml5_match'" 2>/dev/null | tr -d '[:space:]')
if awk -v m="${_flip_age_min:-9999}" 'BEGIN{exit !(m+0 > 55)}'; then
    /opt/yada-www/cron-fliphtml5-match.sh >> /var/log/fliphtml5-match.log 2>&1
fi
