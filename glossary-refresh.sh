#!/bin/bash
#
# glossary-refresh.sh — re-align the Hebrew lexicon with the books after a parse.
#
# Parsing a volume invalidates the glossary in two separate ways, neither of
# which announces itself:
#
#   1. NEW WORDS. A new or revised book can introduce transliterations the
#      lexicon does not have yet. Nothing looked for them: the harvest was only
#      ever run by hand.
#
#   2. REFERENCES. yy_word_occurrence is what the Glossary Words tab drills
#      into when you click a word's count (series -> volume -> chapter -> page
#      -> paragraph) and what the flipbook deep links are built from.
#      parse_volume_from_bundle.py DELETEs the volume's yy_paragraph rows and
#      re-inserts them, and yy_word_occurrence is ON DELETE CASCADE from
#      yy_paragraph -- so every re-parse silently drops that volume's
#      occurrence rows, re-creating the paragraphs under brand new
#      paragraph_keys that nothing points at. word_count_yy keeps the old
#      total, so the count column still shows a number while the drill-down
#      under it has gone blank. There is no error: it just undercounts.
#      (Found this way on 2026-09-25: s04v04 and s04v05, 7,291 paragraphs
#      between them, both re-parsed after the 2026-09-14 index build, both
#      with zero occurrence rows.)
#
# Both are corpus-wide by nature -- word_count_yy is a whole-books total and
# the index is rebuilt in one TRUNCATE + reinsert -- so this takes NO volume
# argument. It is cheap enough not to need one: ~11s of scan for 134k
# paragraphs at ~430MB RSS, measured on this host.
#
# The two passes are not optional. _word_harvest.php builds the occurrence
# index (pass 2b) BEFORE it selects and inserts new candidate words (pass 3),
# using a spelling map loaded before either. So words inserted by a run are
# absent from the index that same run -- the driver prints a warning saying
# exactly that. Pass B re-indexes with the new words present. Skipping it
# leaves every freshly harvested word showing a count with an empty
# drill-down, which is the bug above wearing a different hat.
#
# Usage:
#   glossary-refresh.sh              refresh (writes)
#   glossary-refresh.sh --dry-run    report what would change, write nothing
#
# Exit codes: 0 ok, 75 another refresh holds the lock (caller should retry
# later -- keep your dirty marker), 1 the harvest failed.
#
# Called by book-pipeline-worker.sh Phase 6.8 once a drain has finished; safe
# to run by hand at any time.

set -uo pipefail

WEB_CONTAINER=yada-www-web-1
PG_CONTAINER=yada-postgres-prod
HARVEST=/var/www/html/api/_word_harvest.php
LOCK=/var/lock/glossary-refresh.lock
LOGFILE=/var/log/glossary-refresh.log

DRY_RUN=0
[ "${1:-}" = "--dry-run" ] && DRY_RUN=1

ts() { date '+%Y-%m-%d %H:%M:%S'; }
log() { echo "[$(ts)] $*" | tee -a "$LOGFILE"; }

# Mirrors book-pipeline-worker.sh's helper so failures surface on
# /admin-monitor.html rather than only in this log.
log_monitor_event() {
    local severity="$1" message="$2" detail="$3"
    local esc_msg esc_detail
    esc_msg=$(echo "$message" | sed "s/'/''/g")
    esc_detail=$(echo "$detail" | sed "s/'/''/g")
    docker exec "$PG_CONTAINER" psql -U postgres -d yada -c \
        "INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_file) \
         VALUES ('glossary_refresh', '$severity', '$esc_msg', '$esc_detail', 'glossary-refresh.sh');" \
        > /dev/null 2>&1
}

psql_at() {
    docker exec "$PG_CONTAINER" psql -U postgres -d yada -At -c "$1" 2>/dev/null
}

# Single-instance. A refresh already running covers the whole corpus, so a
# second one would only repeat it -- but it may have STARTED before the parse
# that queued us, so we must not report success. 75 tells the caller to keep
# its dirty marker and try again next tick.
exec 9>"$LOCK"
if ! flock -n 9; then
    log "Another glossary refresh holds the lock — leaving the work queued"
    exit 75
fi

if [ "$DRY_RUN" -eq 1 ]; then
    log "Glossary refresh: DRY RUN (nothing will be written)"
else
    log "Glossary refresh: starting"
fi

# ── Before ────────────────────────────────────────────────────────────────
before=$(psql_at "SELECT
      (SELECT count(*) FROM yy_word),
      (SELECT COALESCE(sum(word_count_yy),0) FROM yy_word),
      (SELECT count(*) FROM yy_word_occurrence),
      (SELECT COALESCE(sum(occurrence_count),0) FROM yy_word_occurrence)" | tr '|' ' ')
read -r b_words b_count b_occrows b_occ <<< "${before:-0 0 0 0}"
log "  before: ${b_words} words, count total ${b_count}, index ${b_occrows} rows / ${b_occ} occurrences"

# Volumes whose paragraphs exist but carry no occurrence rows at all — the
# signature of a re-parse having cascaded the index away.
blank_before=$(psql_at "
    SELECT count(*) FROM (
        SELECT p.volume_key
          FROM yy_paragraph p
          LEFT JOIN yy_word_occurrence o ON o.paragraph_key = p.paragraph_key
         GROUP BY p.volume_key
        HAVING count(o.paragraph_key) = 0
    ) z")
log "  before: ${blank_before:-?} volume(s) with paragraphs but no occurrence rows"

# ── Pass A: recount + rebuild the index + harvest new words ───────────────
if [ "$DRY_RUN" -eq 1 ]; then
    pass_a_args="--index"
else
    pass_a_args="--apply --index"
fi

log "  pass A: $pass_a_args"
a_rc=0
a_out=$(docker exec "$WEB_CONTAINER" php "$HARVEST" $pass_a_args 2>&1) || a_rc=$?
echo "$a_out" >> "$LOGFILE"

if [ "$a_rc" -ne 0 ]; then
    log "  pass A FAILED (exit $a_rc)"
    log_monitor_event "error" "Glossary refresh pass A failed (exit $a_rc)" "$a_out"
    exit 1
fi

inserted=$(echo "$a_out" | sed -n 's/^  inserted \([0-9,]*\) words.*/\1/p' | tr -d ',')
candidates=$(echo "$a_out" | sed -n 's/^  \([0-9,]*\) candidates .*/\1/p' | tr -d ',')
log "  pass A ok — ${candidates:-0} candidate(s), ${inserted:-0} new word(s) inserted"

# ── Pass B: re-index so the words pass A inserted are reachable ───────────
# Only needed when pass A actually inserted: the driver itself prints
# "re-run with --index --apply to index the new words" in that case.
if [ "$DRY_RUN" -eq 0 ] && [ "${inserted:-0}" -gt 0 ]; then
    log "  pass B: --recount-only --index --apply (indexing ${inserted} new word(s))"
    b_rc=0
    b_out=$(docker exec "$WEB_CONTAINER" php "$HARVEST" --recount-only --index --apply 2>&1) || b_rc=$?
    echo "$b_out" >> "$LOGFILE"
    if [ "$b_rc" -ne 0 ]; then
        log "  pass B FAILED (exit $b_rc) — new words are on file but NOT indexed"
        log_monitor_event "error" "Glossary refresh pass B failed (exit $b_rc)" "$b_out"
        exit 1
    fi
    log "  pass B ok"
else
    log "  pass B skipped (no new words to index)"
fi

if [ "$DRY_RUN" -eq 1 ]; then
    log "Glossary refresh: DRY RUN complete — nothing written"
    exit 0
fi

# ── After, and check it actually adds up ─────────────────────────────────
after=$(psql_at "SELECT
      (SELECT count(*) FROM yy_word),
      (SELECT COALESCE(sum(word_count_yy),0) FROM yy_word),
      (SELECT count(*) FROM yy_word_occurrence),
      (SELECT COALESCE(sum(occurrence_count),0) FROM yy_word_occurrence)" | tr '|' ' ')
read -r a_words a_count a_occrows a_occ <<< "${after:-0 0 0 0}"
log "  after:  ${a_words} words, count total ${a_count}, index ${a_occrows} rows / ${a_occ} occurrences"

blank_after=$(psql_at "
    SELECT count(*) FROM (
        SELECT p.volume_key
          FROM yy_paragraph p
          LEFT JOIN yy_word_occurrence o ON o.paragraph_key = p.paragraph_key
         GROUP BY p.volume_key
        HAVING count(o.paragraph_key) = 0
    ) z")
log "  after:  ${blank_after:-?} volume(s) with paragraphs but no occurrence rows"

# The index is computed from the same token pass as word_count_yy, so once
# both passes have run the two totals must be identical. If they are not, the
# drill-down disagrees with the count column it hangs off -- exactly the
# silent-undercount state this script exists to clear -- so say so loudly.
if [ "${a_occ:-0}" != "${a_count:-1}" ]; then
    log "  WARNING: index total ${a_occ} != word count total ${a_count}"
    log_monitor_event "warning" \
        "Glossary index total ${a_occ} does not match word count total ${a_count}" \
        "A glossary refresh finished but yy_word_occurrence and yy_word.word_count_yy disagree. The Words-tab drill-down will not match the count column."
fi

log "Glossary refresh: done"
exit 0
