#!/bin/bash
# ── book-pipeline-worker.sh ───────────────────────────────────────────────
# Host-side worker that processes book pipeline jobs queued by admin-books.php.
#
# For each job in /opt/yada-www/jobs/book-pipeline/:
#   1. Convert .docx → .pdf via LibreOffice headless
#   2. Move PDF to /opt/yada-www/public/pdf/
#   3. Trigger FlipHTML5 upload (re-uses upload_fliphtml5.py via the rsshub container)
#   4. Download published flipbook .zip and extract to /opt/yada-www/public/flipbook/<code>/
#   5. Update yy_volume.volume_pdf, .volume_flip_code, and .volume_pipeline_*
#
# Crontab (every 2 minutes):
#   */2 * * * * /opt/yada-www/book-pipeline-worker.sh >> /var/log/book-pipeline.log 2>&1
#
# Requirements on host:
#   - libreoffice (apt install libreoffice --no-install-recommends)
#   - docker (already available)
#   - rsshub container with puppeteer-real-browser (already deployed for Rumble scraping)

set -uo pipefail

# ── Resource gates ────────────────────────────────────────────────────
# Host has only ~3.9 GB RAM total; a single LibreOffice headless can claim
# 2.6 GB. Without limits we OOM-kill ourselves and starve the website. Two
# guards:
#   1. Pre-flight: if free RAM < MIN_FREE_MB or load avg > MAX_LOAD, skip.
#   2. Per-process: every heavy external (LibreOffice, Puppeteer/Node, the
#      Python parser) runs inside a transient systemd scope with hard
#      MemoryMax + CPUQuota. Excess gets cgroup-killed cleanly (logged as
#      exit 137) instead of starving the box.
MIN_FREE_MB=1500
MAX_LOAD=6.0
SOFFICE_MEM_MAX=2600M
SOFFICE_CPU_QUOTA=50%
PARSER_MEM_MAX=400M
PARSER_CPU_QUOTA=40%

# Bind-mounted by the web container (host: /opt/yada-www/public/jobs/...,
# container: /var/www/html/jobs/...). admin-books.php drops job files here
# whenever a docx is uploaded.
JOBS_DIR=/opt/yada-www/public/jobs/book-pipeline
DOCX_DIR=/opt/yada-www/public/books/word
PDF_DIR=/opt/yada-www/public/pdf
FLIP_DIR=/opt/yada-www/public/flipbook
LOCK=/var/lock/book-pipeline-worker.lock
PG_CONTAINER=yada-postgres-prod
WEB_CONTAINER=yada-www-web-1
RSSHUB_CONTAINER=yada-www-rsshub-1

mkdir -p "$JOBS_DIR" "$DOCX_DIR" "$PDF_DIR" "$FLIP_DIR" "$JOBS_DIR/done" "$JOBS_DIR/failed"

# Single-instance lock — exits immediately if another worker is running.
exec 9>"$LOCK"
flock -n 9 || exit 0

ts() { date '+%Y-%m-%d %H:%M:%S'; }
log() { echo "[$(ts)] $*"; }

# Pre-flight: bail if the host doesn't have headroom. We check BEFORE any
# heavy work so a heavy tick is naturally re-tried by cron 2 min later when
# the previous load has decayed.
free_mb=$(awk '/MemAvailable:/ {print int($2/1024)}' /proc/meminfo)
load_now=$(awk '{print $1}' /proc/loadavg)
if [ "${free_mb:-0}" -lt "$MIN_FREE_MB" ]; then
    log "Skip tick: only ${free_mb}MB free (< ${MIN_FREE_MB}MB threshold)"
    exit 0
fi
if awk -v l="$load_now" -v m="$MAX_LOAD" 'BEGIN{exit !(l>m)}'; then
    log "Skip tick: load avg ${load_now} (> ${MAX_LOAD} threshold)"
    exit 0
fi
log "Pre-flight OK: ${free_mb}MB free, load ${load_now}"

# Catch-all error trap: anything that triggers ERR (a failing command outside
# a conditional, a syntax error, an undefined variable etc.) gets logged to
# yy_monitor_event so the admin notices even if nothing reaches update_status.
set -E
trap 'rc=$?; if [ "$rc" -ne 0 ]; then log_monitor_event "book_pipeline" "error" "Worker shell trap fired (exit $rc)" "line $LINENO: ${BASH_COMMAND:-unknown command}"; fi' ERR

# Insert a row into yy_monitor_event so failures surface in the central
# events monitor (/admin-monitor.html) alongside Apache, sync, and DB issues.
# Severities the UI already recognizes: error, warning, info.
log_monitor_event() {
    local source="$1" severity="$2" message="$3" detail="$4"
    local esc_msg esc_detail
    esc_msg=$(echo "$message" | sed "s/'/''/g")
    esc_detail=$(echo "$detail" | sed "s/'/''/g")
    docker exec "$PG_CONTAINER" psql -U postgres -d yada -c \
        "INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_file) \
         VALUES ('$source', '$severity', '$esc_msg', '$esc_detail', 'book-pipeline-worker.sh');" \
        > /dev/null 2>&1
}

# Update yy_volume status (escapes single quotes). Error/warning states also
# log a monitor event so admins notice them on /admin-monitor.html without
# having to be on the Books page when the failure happens.
update_status() {
    local volume_key="$1" status="$2" message="$3" detail="${4:-}"
    local esc_msg
    esc_msg=$(echo "$message" | sed "s/'/''/g")
    docker exec "$PG_CONTAINER" psql -U postgres -d yada -c \
        "UPDATE yy_volume SET volume_pipeline_status='$status', volume_pipeline_message='$esc_msg', volume_revision_dtime=NOW() WHERE volume_key=$volume_key;" \
        > /dev/null 2>&1
    if [ "$status" = "error" ] || [ "$status" = "warning" ]; then
        local d="${detail:-volume_key=$volume_key status=$status}"
        log_monitor_event "book_pipeline" "$status" \
            "Volume $volume_key: $message" "$d"
    fi
}

# Update yy_volume PDF + flip_code.
update_outputs() {
    local volume_key="$1" pdf_name="$2" flip_code="$3"
    local sets="volume_pdf='$pdf_name'"
    if [ -n "$flip_code" ]; then
        sets="$sets, volume_flip_code='$flip_code'"
    fi
    docker exec "$PG_CONTAINER" psql -U postgres -d yada -c \
        "UPDATE yy_volume SET $sets, volume_revision_dtime=NOW() WHERE volume_key=$volume_key;" \
        > /dev/null 2>&1
}

# Process a single job file.
process_job() {
    local job="$1"
    local volume_key docx_name pdf_name
    volume_key=$(grep -oE '"volume_key"\s*:\s*[0-9]+' "$job" | grep -oE '[0-9]+')
    docx_name=$(grep -oE '"docx_name"\s*:\s*"[^"]+"' "$job" | sed -E 's/.*"([^"]+)"$/\1/')
    pdf_name=$(grep -oE '"pdf_name"\s*:\s*"[^"]+"' "$job" | sed -E 's/.*"([^"]+)"$/\1/')

    if [ -z "$volume_key" ] || [ -z "$docx_name" ] || [ -z "$pdf_name" ]; then
        log "Bad job file (missing fields): $job"
        log_monitor_event "book_pipeline" "error" \
            "Malformed pipeline job file: $(basename "$job")" \
            "$(head -c 500 "$job")"
        mv "$job" "$JOBS_DIR/failed/"
        return
    fi

    log "Job start volume_key=$volume_key docx=$docx_name"
    update_status "$volume_key" "running" "Converting DOCX → PDF"

    local docx_path="$DOCX_DIR/$docx_name"
    if [ ! -f "$docx_path" ]; then
        log "DOCX not found: $docx_path"
        update_status "$volume_key" "error" "DOCX file not found: $docx_name"
        mv "$job" "$JOBS_DIR/failed/"
        return
    fi

    # ── Phase 1: LibreOffice headless conversion ──
    # Capture LibreOffice's combined output so the actual error message can
    # be embedded in the monitor event (and not just left in the rotating
    # /var/log/book-pipeline.log where the admin has to go hunt for it).
    # The conversion runs inside a transient systemd scope with hard memory
    # cap; OOMs become a clean cgroup kill (exit 137) instead of taking down
    # the whole host.
    # On retry (after FlipHTML5 auto-requeue) the PDF is already on disk —
    # skip LibreOffice so retries don't waste 30-60s re-converting an
    # unchanged docx.
    local tmp_out="" lo_output="" lo_rc=0
    if [ -f "$PDF_DIR/$pdf_name" ] && [ -s "$PDF_DIR/$pdf_name" ]; then
        log "PDF already on disk — skipping LibreOffice (retry path): $PDF_DIR/$pdf_name"
    else
        tmp_out=$(mktemp -d)
        lo_output=$(systemd-run --scope --quiet \
            --property=MemoryMax=$SOFFICE_MEM_MAX \
            --property=MemorySwapMax=0 \
            --property=CPUQuota=$SOFFICE_CPU_QUOTA \
            env HOME=/tmp soffice --headless --norestore --nologo --nofirststartwizard "-env:UserInstallation=file:///tmp/lo_profile_$$" --convert-to pdf --outdir "$tmp_out" "$docx_path" 2>&1) || lo_rc=$?
        lo_rc=${lo_rc:-0}
        echo "$lo_output" >> /var/log/book-pipeline.log
    fi
    if [ "$lo_rc" -ne 0 ]; then
        log "LibreOffice failed for $docx_name (exit $lo_rc)"
        update_status "$volume_key" "error" "LibreOffice conversion failed (exit $lo_rc)" "$lo_output"
        mv "$job" "$JOBS_DIR/failed/"
        [ -n "$tmp_out" ] && rm -rf "$tmp_out"
        return
    fi
    if [ ! -f "$PDF_DIR/$pdf_name" ] || [ ! -s "$PDF_DIR/$pdf_name" ]; then
        # Only when LibreOffice ran (skipped on retry path where PDF existed).
        local generated
        generated=$(ls "${tmp_out:-/nonexistent}"/*.pdf 2>/dev/null | head -1)
        if [ -z "$generated" ] || [ ! -f "$generated" ]; then
            log "LibreOffice produced no PDF"
            update_status "$volume_key" "error" "LibreOffice produced no output" "$lo_output"
            mv "$job" "$JOBS_DIR/failed/"
            [ -n "$tmp_out" ] && rm -rf "$tmp_out"
            return
        fi
        mv "$generated" "$PDF_DIR/$pdf_name"
        chmod 644 "$PDF_DIR/$pdf_name" 2>/dev/null
        log "PDF written: $PDF_DIR/$pdf_name ($(stat -c%s "$PDF_DIR/$pdf_name") bytes)"
    fi
    [ -n "$tmp_out" ] && rm -rf "$tmp_out"

    update_outputs "$volume_key" "$pdf_name" ""
    update_status "$volume_key" "running" "PDF generated; uploading to FlipHTML5"

    # ── Phase 2: FlipHTML5 upload (best-effort) ──
    # Runs the Node-based puppeteer worker inside the rsshub container, which has
    # puppeteer-real-browser installed. The worker does the upload and outputs the
    # new flip_code on stdout; on error or container missing, we keep the PDF and
    # report partial success — the admin can paste the flip_code manually.
    local flip_code=""
    # Per-phase pre-flight: don't launch Chromium if the host is already busy.
    # The book-pipeline-worker's outer pre-flight uses MIN_FREE_MB=1500; for
    # the puppeteer step we want a stricter floor since Chromium adds ~500MB.
    local fhx_free fhx_load
    fhx_free=$(awk '/MemAvailable:/ {print int($2/1024)}' /proc/meminfo)
    fhx_load=$(awk '{print $1}' /proc/loadavg)
    local fhx_skip=0
    if [ "${fhx_free:-0}" -lt 1800 ]; then fhx_skip=1; fi
    if awk -v l="$fhx_load" 'BEGIN{exit !(l>4.0)}'; then fhx_skip=1; fi

    # Pull existing flip_code from DB so re-upload mode kicks in (if no code,
    # the upload script exits 2 → first-time uploads need manual handling).
    local existing_flip
    existing_flip=$(docker exec "$PG_CONTAINER" psql -U postgres -d yada -t -A -c \
        "SELECT COALESCE(volume_flip_code, '') FROM yy_volume WHERE volume_key=$volume_key" 2>/dev/null \
        | tr -d '[:space:]')

    if [ "$fhx_skip" = "1" ]; then
        log "FlipHTML5 phase deferred (free=${fhx_free}MB load=${fhx_load}); PDF kept, will retry on next upload"
        update_status "$volume_key" "warning" "PDF generated; FlipHTML5 deferred to next tick (host load)" \
            "free=${fhx_free}MB load=${fhx_load}"
    elif docker ps --format '{{.Names}}' | grep -q "^${RSSHUB_CONTAINER}$" \
       && [ -f /opt/yada-www/rumble-scraper/yy/fliphtml5-upload.cjs ] \
       && [ -f /opt/yada-www/.env ]; then
        # Pull credentials from the host .env (POSTGRES_* lives there too).
        local fhx_email fhx_pass
        fhx_email=$(grep '^FLIPHTML5_EMAIL=' /opt/yada-www/.env 2>/dev/null | cut -d= -f2- | tr -d '"')
        fhx_pass=$(grep '^FLIPHTML5_PASS=' /opt/yada-www/.env 2>/dev/null | cut -d= -f2- | tr -d '"')
        if [ -z "$fhx_email" ] || [ -z "$fhx_pass" ]; then
            log "FlipHTML5 credentials missing in /opt/yada-www/.env — skipping"
            update_status "$volume_key" "warning" "PDF generated; FlipHTML5 credentials not configured" ""
        else
            log "Triggering FlipHTML5 re-upload via $RSSHUB_CONTAINER (existing flip=${existing_flip:-NONE})"
            local up_output up_rc
            # systemd-run cgroup ceiling: 700M is enough for headless Chromium with
            # images/extensions disabled; CPUQuota lets it use one core but yield
            # under load. Inner --js-flags=--max-old-space-size=400 inside the JS
            # is the soft ceiling; this 700M is the hard ceiling.
            # Script lives at /scraper/yy/fliphtml5-upload.cjs inside the
            # container (bind-mounted from /opt/yada-www/rumble-scraper/yy/
            # on the host). docker exec ignores -v flags, so we can't add
            # mounts at exec time — the script HTTP-fetches the PDF instead.
            up_output=$(systemd-run --scope --quiet \
                --property=MemoryMax=700M \
                --property=MemorySwapMax=0 \
                --property=CPUQuota=50% \
                docker exec \
                    -e PDF_NAME="$pdf_name" \
                    -e VOLUME_KEY="$volume_key" \
                    -e FLIP_CODE="$existing_flip" \
                    -e FLIP_EMAIL="$fhx_email" \
                    -e FLIP_PASS="$fhx_pass" \
                    "$RSSHUB_CONTAINER" node /scraper/yy/fliphtml5-upload.cjs 2>&1) || up_rc=$?
            up_rc=${up_rc:-0}
            echo "$up_output" >> /var/log/book-pipeline.log
            flip_code=$(echo "$up_output" | tail -1 | tr -d '[:space:]')
            if ! echo "$flip_code" | grep -qE '^[A-Za-z0-9_-]+$'; then
                flip_code=""
            fi
            # (exit 2 used to mean "first-time upload requires manual step"
            #  — that's now automated, so any non-zero exit is a real error.)
        fi
        if [ "$up_rc" -eq 0 ] && [ -n "$flip_code" ]; then
            log "FlipHTML5 upload returned flip_code=$flip_code"
            update_outputs "$volume_key" "$pdf_name" "$flip_code"
            update_status "$volume_key" "running" "FlipHTML5 uploaded ($flip_code); downloading offline package"

            # ── Phase 3: download .zip and self-host ──
            local zip_dir="$FLIP_DIR/$flip_code"
            mkdir -p "$zip_dir"
            local dl_output dl_rc
            dl_output=$(docker exec -e FLIP_CODE="$flip_code" -e OUT_DIR="/tmp/flip_$flip_code" \
                "$RSSHUB_CONTAINER" node /scraper/yy/fliphtml5-download.cjs 2>&1) || dl_rc=$?
            dl_rc=${dl_rc:-0}
            echo "$dl_output" >> /var/log/book-pipeline.log
            if [ "$dl_rc" -eq 0 ]; then
                log "Flipbook downloaded + extracted to $zip_dir"
                update_status "$volume_key" "success" "PDF + FlipHTML5 + offline package complete"
            else
                log "Flipbook download failed (exit $dl_rc; kept PDF + FlipHTML5)"
                update_status "$volume_key" "warning" "PDF + FlipHTML5 done; offline package download failed (exit $dl_rc)" "$dl_output"
            fi
        else
            # Auto-requeue on transient FlipHTML5 failure (Puppeteer flake,
            # site CAPTCHA, selector timing). Cap at 3 so a permanently broken
            # upload doesn't loop forever.
            local retry_max=3
            local retry_now
            retry_now=$(docker exec "$PG_CONTAINER" psql -U postgres -d yada -t -A -c \
                "UPDATE yy_volume SET volume_pipeline_retry_count = COALESCE(volume_pipeline_retry_count, 0) + 1
                 WHERE volume_key = $volume_key
                 RETURNING volume_pipeline_retry_count" 2>/dev/null | tr -d '[:space:]')
            retry_now=${retry_now:-99}
            if [ "$retry_now" -lt "$retry_max" ]; then
                log "FlipHTML5 upload failed (exit $up_rc) — auto-requeueing (attempt $retry_now/$retry_max)"
                update_status "$volume_key" "queued" \
                    "FlipHTML5 upload failed (exit $up_rc) — auto-retry $retry_now/$retry_max queued" \
                    "$up_output"
                # Leave the job file in JOBS_DIR for the next cron tick.
                return 0
            else
                log "FlipHTML5 upload failed (exit $up_rc) — retry cap ($retry_max) reached, marking error"
                update_status "$volume_key" "error" \
                    "FlipHTML5 upload failed (exit $up_rc) after $retry_max retries — manual intervention required" \
                    "$up_output"
            fi
        fi
    else
        log "Skipping FlipHTML5 (rsshub container or fliphtml5-upload.cjs missing)"
        local skip_detail="rsshub_running=$(docker ps --format '{{.Names}}' | grep -c \"^${RSSHUB_CONTAINER}\$\"); upload_script_present=$( [ -f /opt/yada-www/fliphtml5-upload.cjs ] && echo yes || echo no )"
        update_status "$volume_key" "warning" "PDF generated; FlipHTML5 automation not configured on this host" "$skip_detail"
    fi

    # ── Phase 4: paragraph + translation extraction ──────────────────
    # Runs the dedicated per-volume parser which deletes ONLY this volume's
    # rows in yy_paragraph + yy_translation and re-inserts from the new docx.
    # Honors yy_volume.volume_parse_flag (if FALSE, parser exits early with
    # status='skipped'). Failures here log a monitor event but do NOT mark
    # the docx→pdf→flip pipeline as failed — the artifacts are still good.
    if [ -x /opt/yada-www/parsers/parse_volume.py ]; then
        log "Running paragraph + translation extraction (capped at $PARSER_MEM_MAX)"
        local pv_output pv_rc
        pv_output=$(systemd-run --scope --quiet \
            --property=MemoryMax=$PARSER_MEM_MAX \
            --property=MemorySwapMax=0 \
            --property=CPUQuota=$PARSER_CPU_QUOTA \
            python3 /opt/yada-www/parsers/parse_volume.py --volume-key "$volume_key" 2>&1) || pv_rc=$?
        pv_rc=${pv_rc:-0}
        echo "$pv_output" >> /var/log/book-pipeline.log
        if [ "$pv_rc" -ne 0 ]; then
            log "Parser failed for volume $volume_key (exit $pv_rc) — pipeline artifacts kept"
            log_monitor_event "book_parse" "error" \
                "Volume $volume_key parser failed (exit $pv_rc)" "$pv_output"
        else
            log "Parser succeeded for volume $volume_key"
        fi
    else
        log "Skipping parser (parse_volume.py not deployed/executable)"
    fi

    mv "$job" "$JOBS_DIR/done/"
    log "Job done volume_key=$volume_key"
}

# Process all queued jobs
shopt -s nullglob
for j in "$JOBS_DIR"/*.json; do
    process_job "$j"
done

# ── Phase 5: parse-only sweep ─────────────────────────────────────
# Pick up volumes that already have a docx but no parse output (or status
# 'queued'/'error'/'stale'). These appear as 'stale' in the admin UI because
# their parse work was never triggered (e.g. docx was uploaded before the
# parser pipeline existed, or a previous parse errored). Process at most 1
# per tick to avoid hogging the host. The next 2-min tick picks up the next.
if [ -x /opt/yada-www/parsers/parse_volume.py ]; then
    parse_target=$(docker exec "$PG_CONTAINER" psql -U postgres -d yada -At -c "SELECT volume_key FROM yy_volume WHERE volume_docx IS NOT NULL AND (volume_parse_status IS NULL OR volume_parse_status IN ('queued','error','stale')) ORDER BY CASE WHEN volume_parse_status='queued' THEN 0 ELSE 1 END, volume_key LIMIT 1")
    if [ -n "$parse_target" ]; then
        log "Parse-sweep: picking up volume $parse_target"
        ps_rc=0
        ps_output=$(systemd-run --scope --quiet \
            --property=MemoryMax=$PARSER_MEM_MAX \
            --property=MemorySwapMax=0 \
            --property=CPUQuota=$PARSER_CPU_QUOTA \
            python3 /opt/yada-www/parsers/parse_volume.py --volume-key "$parse_target" 2>&1) || ps_rc=$?
        echo "$ps_output" >> /var/log/book-pipeline.log
        if [ "$ps_rc" -ne 0 ]; then
            log "Parse-sweep: volume $parse_target failed (exit $ps_rc)"
            log_monitor_event "book_parse" "error" "Volume $parse_target parse-sweep failed (exit $ps_rc)" "$ps_output"
        else
            log "Parse-sweep: volume $parse_target succeeded"
        fi
    fi
fi
