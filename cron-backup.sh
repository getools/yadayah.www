#!/bin/bash
# cron-backup.sh — Daily PostgreSQL backup for yadayah.com
#
# Crontab (run daily at 03:00 as root):
#   0 3 * * * /opt/yada-www/cron-backup.sh >> /var/log/yada-backup.log 2>&1
#
# Dumps the yada DB to /opt/yada-www/backups/, logs result to yy_monitor_event,
# and prunes files older than RETAIN_DAYS.

set -uo pipefail

PG_CONTAINER=yada-postgres-prod
BACKUP_DIR=/opt/yada-www/backups
RETAIN_DAYS=7

ts()  { date '+%Y-%m-%d %H:%M:%S'; }
log() { echo "[$(ts)] $*"; }

FNAME="yada-$(date -u '+%Y%m%d-%H%M%S').sql.gz"
FPATH="$BACKUP_DIR/$FNAME"

mkdir -p "$BACKUP_DIR"

log "Starting backup: $FNAME"

RC=0
if docker exec -i "$PG_CONTAINER" pg_dump -U postgres -d yada --no-owner --no-acl | gzip > "$FPATH"; then
    SIZE=$(stat -c%s "$FPATH" 2>/dev/null || echo 0)
    # Sanity: a valid dump should be at least 1 MB
    if [ "$SIZE" -lt 1048576 ]; then
        log "Backup suspiciously small (${SIZE} bytes) — marking error"
        MSG_ESC="pg_dump output only ${SIZE} bytes — possible empty dump"
        docker exec "$PG_CONTAINER" psql -U postgres -d yada -c \
            "INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_file) VALUES ('backup', 'error', '$MSG_ESC', 'cron-backup.sh');" > /dev/null 2>&1
        rm -f "$FPATH"
        exit 1
    fi
    SIZE_MB=$(awk "BEGIN{printf \"%.1f\", $SIZE/1048576}")
    log "Backup succeeded: $FNAME (${SIZE_MB} MB)"
    MSG="Backup complete: $FNAME (${SIZE_MB} MB)"
    MSG_ESC=$(echo "$MSG" | sed "s/'/''/g")
    docker exec "$PG_CONTAINER" psql -U postgres -d yada -c \
        "INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_file) VALUES ('backup', 'info', '$MSG_ESC', 'cron-backup.sh');" > /dev/null 2>&1
    # Prune old backups
    find "$BACKUP_DIR" -maxdepth 1 -name "yada-*.sql.gz" -mtime +"$RETAIN_DAYS" -delete 2>/dev/null
    log "Pruned backups older than ${RETAIN_DAYS} days"
else
    RC=$?
    log "pg_dump FAILED (exit $RC)"
    rm -f "$FPATH" 2>/dev/null
    ERR_ESC="pg_dump failed (exit $RC)"
    docker exec "$PG_CONTAINER" psql -U postgres -d yada -c \
        "INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_file) VALUES ('backup', 'error', '$ERR_ESC', 'cron-backup.sh');" > /dev/null 2>&1
    exit $RC
fi
