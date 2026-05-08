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

