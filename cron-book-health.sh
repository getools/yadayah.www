#!/bin/bash
# Per-book health probe — runs every 3 hours (cron: 0 */3 * * *).
#
# Probes every flipbook on both yadayah.com and any sister-domain
# (inthecompanyofgoodandevil.com today; more later). Each book gets
# two checks:
#   1. The flipbook landing page (catches a missing book directory or
#      a Caddy rewrite that drops the host block).
#   2. /toc.json (catches the case where the root redirects fine but
#      the rewritten sub-paths 4xx because file_server's root drifted
#      or the toc-extraction pipeline wrote a 0-byte file).
#
# Failures emit a CRITICAL row to yy_monitor_event the same way
# cron-site-health.sh does — same throttle window so a 1-hour outage =
# at most one alert per (book, severity).
#
# Cron entry:
#   0 */3 * * * /opt/yada-www/cron-book-health.sh >> /var/log/yada-book-health.log 2>&1
set -uo pipefail

STATE_DIR=/var/lib/yada-health
mkdir -p "$STATE_DIR"
THROTTLE_SEC=10800   # 3 hours — match the cron interval
PSQL="docker exec -i yada-postgres-prod psql -U postgres -d yada"
PUBLIC=/opt/yada-www/public

log() { echo "[$(date '+%F %T')] $*"; }

emit_event() {
    local url=$1 sev=$2 msg=$3 det=$4
    local key
    key=$(echo -n "${url}|${sev}" | md5sum | awk '{print $1}')
    local stamp_file="$STATE_DIR/last_book_$key"
    local now
    now=$(date +%s)
    if [ -f "$stamp_file" ]; then
        local last
        last=$(cat "$stamp_file")
        if [ $((now - last)) -lt $THROTTLE_SEC ]; then return 0; fi
    fi
    echo "$now" > "$stamp_file"
    local sql_msg=${msg//\'/\'\'}
    local sql_det=${det//\'/\'\'}
    $PSQL -q <<EOF >/dev/null
INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_client_ip)
VALUES ('book-health', '${sev}', '${sql_msg}', '${sql_det}', '127.0.0.1');
EOF
    log "EMIT [${sev}] ${msg}"
}

probe() {
    local url=$1
    local rc=0
    local out code
    out=$(curl -ksS -o /dev/null -m 15 -w '%{http_code}' "$url" 2>/dev/null) || rc=$?
    rc=${rc:-0}
    code=$out
    if [ "$rc" -ne 0 ]; then
        emit_event "$url" critical "Book health: connect failed ($url)" "curl rc=$rc"
        return 1
    fi
    if [ "$code" != "200" ]; then
        emit_event "$url" critical "Book health: ${url} returned ${code} (expected 200)" ""
        return 1
    fi
    return 0
}

# ── Build the URL list ──────────────────────────────────────────────
# YY books live at https://yadayah.com/<dir>/ — derived from /public/YY-*.
# Sister-domain books each get a separate host (root + toc.json), enumerated
# from $SISTER_DOMAINS below. Adding a new sister-domain book = one line.
SISTER_DOMAINS=(
    "https://inthecompanyofgoodandevil.com"
)

URLS=()
for d in "$PUBLIC"/YY-*; do
    [ -d "$d" ] || continue
    slug=$(basename "$d")
    URLS+=("https://yadayah.com/${slug}/")
    [ -f "$d/toc.json" ] && URLS+=("https://yadayah.com/${slug}/toc.json")
done
for host in "${SISTER_DOMAINS[@]}"; do
    URLS+=("${host}/")
    URLS+=("${host}/toc.json")
done

# ── Run probes ──────────────────────────────────────────────────────
ok=0
fail=0
for url in "${URLS[@]}"; do
    if probe "$url"; then
        ok=$((ok + 1))
    else
        fail=$((fail + 1))
    fi
done

log "Book health: ${ok}/${#URLS[@]} OK, ${fail} failed"

# ── Book data integrity (added 2026-09-28) ─────────────────────────
# Silent failure modes that serve wrong content with no HTTP error:
#   A. A chapter with no paragraphs. The parser MAPS chapters from the PDF
#      outline and never creates them, so a DOCX chapter heading missing the
#      yychapter style folds that chapter into the previous one (s04v04 ch2,
#      09-23): its TOC entry and its audio page-sync vanish.
#   B. TTS markers orphaned by a re-parse (paragraph_key NULLed by the FK).
#      book-pipeline-worker.sh remaps them after every parse; this is the
#      safety net for parses run by hand (parse_volume.py etc).
#   C. Any live marker whose paragraph number/page no longer matches the
#      text — the flipbook would turn to the wrong page mid-narration.
empty_ch=$($PSQL -At -F ' | ' -c "SELECT v.volume_code, 'ch '||c.chapter_number, c.chapter_name FROM yy_chapter c JOIN yy_volume v USING (volume_key) WHERE v.volume_active_flag IS DISTINCT FROM false AND v.volume_parse_status = 'success' AND COALESCE(v.volume_pipeline_status,'') NOT IN ('queued','running','waiting-docx','flipbook-running') AND NOT EXISTS (SELECT 1 FROM yy_paragraph p WHERE p.chapter_key=c.chapter_key) ORDER BY 1" 2>&1)
if [ -n "$empty_ch" ]; then
    while IFS= read -r row; do
        emit_event "empty-chapter|$row" warning "Book health: chapter has no paragraphs — $row" \
            "Usually the DOCX chapter heading is not styled yychapter, so the PDF outline and flipbook TOC skip it and the parser folds its text into the previous chapter. Fix the heading style in the DOCX and re-upload."
    done <<< "$empty_ch"
fi

if [ -f /opt/yada-www/parsers/tts_marker_remap.py ]; then
    rm_rc=0
    rm_out=$(timeout 1200 python3 /opt/yada-www/parsers/tts_marker_remap.py --apply 2>&1) || rm_rc=$?
    log "TTS marker remap: $(echo "$rm_out" | grep -E 'audios checked|APPLIED' | tr '\n' ' ')"
    if [ "$rm_rc" -ne 0 ]; then
        emit_event "tts-marker-remap" warning "Book health: TTS marker remap exited $rm_rc" "$(echo "$rm_out" | tail -40)"
    fi
fi

bad_mk=$($PSQL -At -F ' ' -c "SELECT v.volume_code, count(*) FROM yy_tts_audio a JOIN yy_volume v USING (volume_key) JOIN yy_tts_audio_marker m USING (tts_audio_key) LEFT JOIN yy_paragraph p ON p.chapter_key=a.chapter_key AND p.paragraph_number=m.paragraph_number WHERE a.tts_audio_live_dtime IS NOT NULL AND (p.paragraph_key IS NULL OR (m.tts_audio_marker_byte_offset IS NOT NULL AND m.paragraph_page <> p.paragraph_page)) GROUP BY 1 ORDER BY 1" 2>&1)
if [ -n "$bad_mk" ]; then
    while IFS= read -r row; do
        emit_event "tts-markers|${row%% *}" warning "Book health: TTS page-sync markers out of step — ${row%% *} (${row##* } markers)" \
            "Live chapter audio has markers whose paragraph/page no longer exists in yy_paragraph. See parsers/tts_marker_remap.py."
    done <<< "$bad_mk"
fi
log "Book integrity: empty chapters=$(echo -n "$empty_ch" | grep -c .) bad-marker volumes=$(echo -n "$bad_mk" | grep -c .)"
