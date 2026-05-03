#!/bin/bash
# flipbook-download-host.sh — Host-side wrapper around fliphtml5-download.cjs.
#
# For a given volume_key:
#   1. Look up the flip_code from yy_volume.
#   2. Read FLIP_EMAIL / FLIP_PASS from /opt/yada-www/.env.
#   3. Run fliphtml5-download.cjs inside the rsshub container with cgroup caps.
#   4. docker cp the resulting .zip out of the container.
#   5. unzip into /opt/yada-www/public/flipbook/<flip_code>/.
#   6. Update yy_volume.volume_pipeline_status / volume_pipeline_message.
#   7. Clean up the in-container scratch dir.
#
# Usage:
#   /opt/yada-www/flipbook-download-host.sh <volume_key>
#
# Run as root (needs docker exec + write to /opt/yada-www/public/flipbook/).
# Exits 0 on success, 1 on any failure. All steps are loud — log file is
# /var/log/flipbook-download.log.

set -uo pipefail

VOLUME_KEY="${1:?usage: flipbook-download-host.sh <volume_key>}"

LOG=/var/log/flipbook-download.log
RSSHUB_CONTAINER=yada-www-rsshub-1
FLIP_DIR=/opt/yada-www/public/flipbook
ENV_FILE=/opt/yada-www/.env
SCRIPT_PATH_IN_CONTAINER=/scraper/yy/fliphtml5-download.cjs

log() { echo "[$(date -Is)] [vol=$VOLUME_KEY] $*" | tee -a "$LOG"; }

PSQL="docker exec -i yada-postgres-prod psql -U postgres -d yada -t -A"

set_status() {
    local status="$1" msg="$2"
    $PSQL -c "UPDATE yy_volume SET volume_pipeline_status='$status',
              volume_pipeline_message='$(echo "$msg" | sed "s/'/''/g" | head -c 1000)'
              WHERE volume_key=$VOLUME_KEY" >/dev/null
}

# 1. Look up flip_code
FLIP_CODE=$($PSQL -c "SELECT volume_flip_code FROM yy_volume WHERE volume_key=$VOLUME_KEY" | tr -d '[:space:]')
if [ -z "$FLIP_CODE" ]; then
    log "ERROR: volume_key=$VOLUME_KEY has no flip_code in DB"
    exit 1
fi
log "flip_code=$FLIP_CODE"

# 2. Read FlipHTML5 creds from .env (root-readable; never logged). The env
#    var names match book-pipeline-worker.sh's upload path: FLIPHTML5_EMAIL
#    and FLIPHTML5_PASS. (Inside the container they get re-named to
#    FLIP_EMAIL/FLIP_PASS for the JS script.)
FLIP_EMAIL=$(grep '^FLIPHTML5_EMAIL=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'")
FLIP_PASS=$(grep  '^FLIPHTML5_PASS='  "$ENV_FILE" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'")
if [ -z "$FLIP_EMAIL" ] || [ -z "$FLIP_PASS" ]; then
    log "ERROR: FLIPHTML5_EMAIL or FLIPHTML5_PASS missing from $ENV_FILE"
    set_status warning "FlipHTML5 download skipped: account creds missing in .env"
    exit 1
fi

# 3. Pre-flight gate: same as upload — refuse if host is memory-thin.
free_mb=$(awk '/MemAvailable:/ {print int($2/1024)}' /proc/meminfo)
if [ "${free_mb:-0}" -lt 700 ]; then
    log "Skip: only ${free_mb}MB free; need >=700MB headroom for headless Chrome"
    exit 0  # exit 0 so cron retries instead of marking as failed
fi

# 4. Confirm rsshub is up + has the script.
if ! docker ps --format '{{.Names}}' | grep -q "^${RSSHUB_CONTAINER}$"; then
    log "ERROR: rsshub container '${RSSHUB_CONTAINER}' is not running"
    set_status error "FlipHTML5 download failed: rsshub container not running"
    exit 1
fi
if ! docker exec "$RSSHUB_CONTAINER" test -f "$SCRIPT_PATH_IN_CONTAINER"; then
    log "ERROR: $SCRIPT_PATH_IN_CONTAINER missing inside rsshub"
    set_status error "FlipHTML5 download failed: script missing inside rsshub"
    exit 1
fi

# 5. Inline retry loop. FlipHTML5 throttles rapid logins and renders take
#    a few minutes after upload, so single-shot failures are common. Retry
#    with exponential backoff inside the wrapper for minute-scale flakiness;
#    the cron sweep (cron-flipbook-retry.sh) handles hour-scale recovery.
MAX_ATTEMPTS=3
BACKOFFS=(45 180)   # 45s after attempt 1, 180s after attempt 2

DL_OUTPUT=""
DL_RC=1
TRANSIENT=""
for ((attempt=1; attempt<=MAX_ATTEMPTS; attempt++)); do
    set_status running "Downloading offline package from FlipHTML5 (attempt $attempt/$MAX_ATTEMPTS)"
    log "Attempt $attempt/$MAX_ATTEMPTS"

    DL_OUTPUT=$(systemd-run --scope --quiet \
        --property=MemoryMax=700M \
        --property=MemorySwapMax=0 \
        --property=CPUQuota=50% \
        docker exec \
            -e FLIP_CODE="$FLIP_CODE" \
            -e FLIP_EMAIL="$FLIP_EMAIL" \
            -e FLIP_PASS="$FLIP_PASS" \
            -e DOWNLOAD_DIR="/tmp/flip-dl-${FLIP_CODE}" \
            "$RSSHUB_CONTAINER" node "$SCRIPT_PATH_IN_CONTAINER" 2>&1)
    DL_RC=$?

    # Append all script output to the log for forensics.
    echo "--- attempt $attempt ---" >> "$LOG"
    echo "$DL_OUTPUT" >> "$LOG"

    if [ "$DL_RC" -eq 0 ]; then
        break
    fi

    # Classify the failure mode for retry strategy.
    case "$DL_OUTPUT" in
        *"Login did not redirect"*|*"Login button not found"*)
            TRANSIENT="login_blocked"
            ;;
        *"Could not obtain offline package"*)
            # Likely render still in progress, OR feature gated by plan.
            # First case is transient; second is permanent. The cron sweep
            # will keep retrying for several hours — if it really is
            # render-not-ready it'll succeed eventually.
            TRANSIENT="download_button_missing"
            ;;
        *"CHROME_PATH"*|*"chrome"*|*"puppeteer"*)
            TRANSIENT="browser_setup"
            ;;
        *"timeout"*|*"Hard timeout"*)
            TRANSIENT="timeout"
            ;;
        *)
            TRANSIENT="other"
            ;;
    esac

    log "Attempt $attempt failed (rc=$DL_RC, class=$TRANSIENT)"

    if [ "$attempt" -lt "$MAX_ATTEMPTS" ]; then
        wait_s=${BACKOFFS[$((attempt-1))]}
        set_status running "Attempt $attempt failed ($TRANSIENT); waiting ${wait_s}s before retry $((attempt+1))/$MAX_ATTEMPTS"
        log "Backing off ${wait_s}s"
        sleep "$wait_s"
    fi
done

if [ "$DL_RC" -ne 0 ]; then
    log "All $MAX_ATTEMPTS attempts failed (last class=$TRANSIENT)"
    tail_msg=$(echo "$DL_OUTPUT" | grep -vE '^\[fliphtml5-download\] (Logging in|Probing|Trying|Clicked)' | tail -c 400)
    # Use 'warning' for transient failure modes so the cron sweep retries
    # them later. 'error' is reserved for things that won't get better
    # without operator action (script bug, plan tier, missing creds).
    case "$TRANSIENT" in
        login_blocked|download_button_missing|timeout)
            db_status=warning
            ;;
        *)
            db_status=error
            ;;
    esac
    set_status "$db_status" "FlipHTML5 download failed after $MAX_ATTEMPTS attempts ($TRANSIENT, rc=$DL_RC): ${tail_msg:-<no output>}"
    exit 1
fi

# Last stdout line is the in-container path of the .zip.
ZIP_IN_CONTAINER=$(echo "$DL_OUTPUT" | grep -E '^/' | tail -1)
if [ -z "$ZIP_IN_CONTAINER" ] || ! docker exec "$RSSHUB_CONTAINER" test -f "$ZIP_IN_CONTAINER"; then
    log "ERROR: downloader did not produce a usable .zip path (got: $ZIP_IN_CONTAINER)"
    set_status error "FlipHTML5 download failed: no .zip path returned"
    exit 1
fi
log "got zip in-container: $ZIP_IN_CONTAINER"

# 6. Copy zip to host and extract.
HOST_ZIP=$(mktemp -p /tmp flip-dl-${FLIP_CODE}-XXXX.zip)
if ! docker cp "$RSSHUB_CONTAINER:$ZIP_IN_CONTAINER" "$HOST_ZIP"; then
    log "ERROR: docker cp failed for $ZIP_IN_CONTAINER"
    set_status error "FlipHTML5 download failed: docker cp"
    rm -f "$HOST_ZIP"
    exit 1
fi
log "copied zip to host: $HOST_ZIP ($(stat -c %s "$HOST_ZIP") bytes)"

OUT_DIR="$FLIP_DIR/$FLIP_CODE"
mkdir -p "$OUT_DIR"
# -o : overwrite without prompting (essential — re-uploads will re-download)
# -q : quiet; we don't want thousands of file lines in the log
if ! unzip -oq "$HOST_ZIP" -d "$OUT_DIR"; then
    log "ERROR: unzip into $OUT_DIR failed"
    set_status error "FlipHTML5 download failed: unzip"
    rm -f "$HOST_ZIP"
    exit 1
fi
EXTRACTED=$(find "$OUT_DIR" -mindepth 1 -maxdepth 1 | wc -l)
log "extracted $EXTRACTED top-level entries into $OUT_DIR"

# 7. Cleanup. Remove the host zip and the in-container scratch dir.
rm -f "$HOST_ZIP"
docker exec "$RSSHUB_CONTAINER" rm -rf "/tmp/flip-dl-${FLIP_CODE}" 2>/dev/null || true

set_status success "FlipHTML5 offline package downloaded ($EXTRACTED entries)"
log "Done"
exit 0
