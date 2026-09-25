#!/bin/bash
# ------------------------------------------------------------------------
# book-rename-reconcile.sh [--apply] [--volume-key N]
#
# Makes a book RENAME propagate by itself.
#
# `volume_code` is THE canonical root for a book — admin-books.php says so
# in as many words ("volume_code is the canonical root that drives every
# per-book filename"). Nothing enforced it, though: renaming a book updated
# volume_code + volume_docx and left volume_pdf (COALESCE'd, so only ever
# written when it was NULL), volume_file (written by NO code at all), the
# on-disk PDF, the flipbook directory and the wrapper's bookCode all on the
# OLD name. The book then half-works in a way nothing reports: /books drops
# its Read link (gated on <volume_code>/index.php) and, inside the flipbook,
# every book_code API call — bookmarks, TTS audio, paragraph text — answers
# "book_code not found".
#
# So: bring the stragglers back in line with volume_code, for the Word file,
# the PDF and the flipbook alike. RENAME, never rebuild — the rendered pages
# are identical, so a 2-4 minute pdftoppm run would be pure waste — and
# leave a redirect behind for every old URL so nothing already published
# breaks.
#
# Default is a DRY RUN. Pass --apply to actually move anything.
# ------------------------------------------------------------------------
set -uo pipefail

PUBLIC=/opt/yada-www/public
PDF_DIR="$PUBLIC/pdf"
DOCX_DIR="$PUBLIC/u/books-word"
DOCX_URL=/u/books-word
TTS_DIR="$PUBLIC/u/tts-audio"
TTS_URL=/u/tts-audio
ARCHIVE=/opt/yada-www/_archive_renamed
PG_CONTAINER=yada-postgres-prod

APPLY=0
ONLY_KEY=""
while [ $# -gt 0 ]; do
    case "$1" in
        --apply)      APPLY=1 ;;
        --volume-key) shift; ONLY_KEY="${1:-}" ;;
        *) echo "unknown arg: $1" >&2; exit 2 ;;
    esac
    shift
done

ts()  { date '+%Y-%m-%d %H:%M:%S'; }
log() { echo "[$(ts)] [rename-reconcile] $*"; }
act() { if [ "$APPLY" = 1 ]; then log "DO    $*"; else log "WOULD $*"; fi; }

psql_at() { docker exec "$PG_CONTAINER" psql -U postgres -d yada -At -F'|' -c "$1" 2>/dev/null; }
psql_do() {
    if [ "$APPLY" = 1 ]; then
        docker exec "$PG_CONTAINER" psql -U postgres -d yada -q -c "$1" >/dev/null 2>&1
    fi
}
# Postgres literal escaping — volume_file legitimately contains apostrophes.
sq() { printf '%s' "$1" | sed "s/'/''/g"; }

# One old URL -> new URL. SELECT before INSERT on purpose: yy_redirect
# carries a _rev trigger, and an ON CONFLICT write fires it even when it
# changes nothing, littering the revision table.
redirect_one() {
    local from="$1" to="$2"
    local fe; fe=$(sq "$from")
    local existing; existing=$(psql_at "SELECT COALESCE(redirect_target,'') FROM yy_redirect WHERE redirect_request='$fe' LIMIT 1")
    [ "$existing" = "$to" ] && return 0
    act "redirect $from -> $to"
    if [ -z "$existing" ]; then
        # The row may already exist as a 404/attack log entry with a NULL
        # target, in which case the INSERT no-ops and the UPDATE claims it.
        psql_do "INSERT INTO yy_redirect (redirect_request, redirect_target, redirect_active_flag, redirect_queue_flag)
                 SELECT '$fe', '$(sq "$to")', true, false
                  WHERE NOT EXISTS (SELECT 1 FROM yy_redirect WHERE redirect_request='$fe')"
        psql_do "UPDATE yy_redirect SET redirect_target='$(sq "$to")', redirect_active_flag=true, redirect_attack_flag=false
                  WHERE redirect_request='$fe' AND COALESCE(redirect_target,'')=''"
    else
        psql_do "UPDATE yy_redirect SET redirect_target='$(sq "$to")', redirect_active_flag=true
                  WHERE redirect_request='$fe'"
    fi
}

# A page URL, stored both bare and trailing-slash: case-redirect.php matches
# the request path exactly, and both forms get linked in the wild.
redirect_page() {
    redirect_one "$1"  "$2/"
    redirect_one "$1/" "$2/"
}

mkdir -p "$ARCHIVE" 2>/dev/null

WHERE="volume_code IS NOT NULL AND volume_code <> ''"
[ -n "$ONLY_KEY" ] && WHERE="$WHERE AND volume_key = $ONLY_KEY"

CHANGED=0
while IFS='|' read -r k code file pdf docx; do
    [ -z "$k" ] && continue

    # A code with a space, quote or slash in it would make every derived
    # path ambiguous; that is a data problem to fix by hand, not to act on.
    if ! printf '%s' "$code" | grep -qE '^[A-Za-z0-9][A-Za-z0-9._-]*$'; then
        log "SKIP vol $k — volume_code '$code' is not a safe filename root"
        continue
    fi

    slug=$(printf '%s' "$file" | tr ' ' '-' | tr -d "'")
    pdf_stem="${pdf%.pdf}"
    docx_stem="${docx%.docx}"

    # An EMPTY column is "this book has no such artifact yet", not a rename,
    # and must be left alone. Filling one in would be actively harmful:
    # volume_docx IS NOT NULL is what makes the Phase 4.5 / Phase 5 sweeps
    # treat a volume as having a source document, so inventing a docx name
    # for a book that has never had one queues renders against a file that
    # does not exist. This tool renames; it does not populate.
    file_drift=0; [ -n "$file" ] && [ "$slug"      != "$code" ] && file_drift=1
    pdf_drift=0;  [ -n "$pdf"  ] && [ "$pdf_stem"  != "$code" ] && pdf_drift=1
    docx_drift=0; [ -n "$docx" ] && [ "$docx_stem" != "$code" ] && docx_drift=1
    [ $((file_drift + pdf_drift + docx_drift)) -eq 0 ] && continue

    log "vol $k drifted from canonical code '$code': file='$file' pdf='$pdf' docx='$docx'"
    CHANGED=$((CHANGED + 1))

    # -- 1. flipbook directory ----------------------------------------
    # Moving it keeps the rendered pages (and their mtime, so the Phase 6
    # sweep still reads the book as fresh and does not re-render it).
    if [ "$file_drift" = 1 ]; then
        if [ -d "$PUBLIC/$slug" ] && [ ! -d "$PUBLIC/$code" ]; then
            act "mv flipbook $PUBLIC/$slug -> $PUBLIC/$code"
            [ "$APPLY" = 1 ] && mv "$PUBLIC/$slug" "$PUBLIC/$code"
        elif [ -d "$PUBLIC/$slug" ] && [ -d "$PUBLIC/$code" ]; then
            act "archive superseded flipbook $PUBLIC/$slug (canonical $code already built)"
            [ "$APPLY" = 1 ] && mv "$PUBLIC/$slug" "$ARCHIVE/$slug.$(date +%Y%m%d-%H%M%S)"
        fi
        [ -d "$PUBLIC/$code" ] || log "  note: no flipbook at $PUBLIC/$code — the Phase 6 sweep will build one"
        redirect_page "/$slug" "/$code"
    fi

    # -- 2. the PDF ---------------------------------------------------
    # migrate_flipbook.sh reads public/pdf/<slug>.pdf, so a stale
    # volume_pdf is what makes a renamed book render from an old file.
    if [ "$pdf_drift" = 1 ]; then
        old="$PDF_DIR/$pdf_stem.pdf"; new="$PDF_DIR/$code.pdf"
        if [ -f "$old" ] && [ ! -f "$new" ]; then
            act "mv pdf $old -> $new"
            [ "$APPLY" = 1 ] && mv "$old" "$new"
        elif [ -f "$old" ] && [ -f "$new" ]; then
            if [ "$(md5sum <"$old" | cut -d' ' -f1)" = "$(md5sum <"$new" | cut -d' ' -f1)" ]; then
                act "rm duplicate pdf $old (byte-identical to $new)"
                [ "$APPLY" = 1 ] && rm -f "$old"
            else
                act "archive divergent pdf $old (canonical $new kept)"
                [ "$APPLY" = 1 ] && mv "$old" "$ARCHIVE/$(basename "$old").$(date +%Y%m%d-%H%M%S)"
            fi
        fi
        redirect_one "/pdf/$pdf_stem.pdf" "/pdf/$code.pdf"
    fi

    # -- 3. the Word file ---------------------------------------------
    if [ "$docx_drift" = 1 ]; then
        old="$DOCX_DIR/$docx_stem.docx"; new="$DOCX_DIR/$code.docx"
        if [ -f "$old" ] && [ ! -f "$new" ]; then
            act "mv docx $old -> $new"
            [ "$APPLY" = 1 ] && mv "$old" "$new"
        elif [ -f "$old" ] && [ -f "$new" ]; then
            act "archive superseded docx $old"
            [ "$APPLY" = 1 ] && mv "$old" "$ARCHIVE/$(basename "$old").$(date +%Y%m%d-%H%M%S)"
        fi
        redirect_one "$DOCX_URL/$docx_stem.docx" "$DOCX_URL/$code.docx"
    fi

    # -- 4. narrated audio --------------------------------------------
    # admin-tts-build-worker.php names every part "{volume_code}-c{NN}-p{N}"
    # and admin-tts-helpers.php bundles "{volume_code}.mp3.zip" — which the
    # viewer HEAD-probes by bookCode to decide whether to offer the MP3
    # download. Audio built before a rename therefore sits under the old
    # name while the button asks for the new one. The files are addressed by
    # yy_tts_audio.tts_audio_path, so the rows move with them.
    for oldroot in $(printf '%s\n' "$slug" "$pdf_stem" "$docx_stem" | sort -u); do
        [ -z "$oldroot" ] && continue
        [ "$oldroot" = "$code" ] && continue
        moved=0
        for f in "$TTS_DIR/$oldroot"-*.mp3 "$TTS_DIR/$oldroot".mp3.zip; do
            [ -f "$f" ] || continue
            ob=$(basename "$f"); nb="$code${ob#"$oldroot"}"
            [ "$ob" = "$nb" ] && continue
            if [ -f "$TTS_DIR/$nb" ]; then
                act "archive superseded audio $ob (canonical $nb already present)"
                [ "$APPLY" = 1 ] && mv "$f" "$ARCHIVE/$ob.$(date +%Y%m%d-%H%M%S)"
            else
                act "mv audio $ob -> $nb"
                [ "$APPLY" = 1 ] && mv "$f" "$TTS_DIR/$nb"
            fi
            redirect_one "$TTS_URL/$ob" "$TTS_URL/$nb"
            moved=$((moved + 1))
        done
        n_rows=$(psql_at "SELECT count(*) FROM yy_tts_audio WHERE volume_key=$k AND tts_audio_path LIKE '$TTS_URL/$(sq "$oldroot")%'")
        if [ "${n_rows:-0}" -gt 0 ]; then
            act "UPDATE yy_tts_audio ($n_rows rows) $oldroot -> $code"
            psql_do "UPDATE yy_tts_audio
                        SET tts_audio_path = replace(tts_audio_path, '$TTS_URL/$(sq "$oldroot")', '$TTS_URL/$(sq "$code")')
                      WHERE volume_key=$k AND tts_audio_path LIKE '$TTS_URL/$(sq "$oldroot")%'"
        fi
        [ "$moved" -gt 0 ] && log "  $moved audio file(s) under '$oldroot'"
    done

    # -- 5. the wrapper's bookCode ------------------------------------
    # Every book_code call the viewer makes (bookmarks, tts-audio,
    # paragraph-text) resolves through yy_volume.volume_code, so a wrapper
    # left on the old code answers "book_code not found" on all of them.
    idx="$PUBLIC/$code/index.php"
    if [ -f "$idx" ] && ! grep -q "'bookCode'      => '$code'," "$idx"; then
        act "rewrite bookCode in $idx -> $code"
        if [ "$APPLY" = 1 ]; then
            tmp=$(mktemp)
            if sed -E "s/('bookCode'[[:space:]]*=>[[:space:]]*')[^']*(')/\\1$code\\2/" "$idx" > "$tmp" && [ -s "$tmp" ]; then
                cat "$tmp" > "$idx"
            else
                log "  WARN could not rewrite $idx — left as is"
            fi
            rm -f "$tmp"
        fi
    fi

    # -- 6. the DB columns --------------------------------------------
    sets=""
    [ "$file_drift" = 1 ] && sets="$sets volume_file='$(sq "$code")',"
    [ "$pdf_drift"  = 1 ] && sets="$sets volume_pdf='$(sq "$code").pdf',"
    [ "$docx_drift" = 1 ] && sets="$sets volume_docx='$(sq "$code").docx',"
    if [ -n "$sets" ]; then
        act "UPDATE yy_volume SET${sets%,} WHERE volume_key=$k"
        psql_do "UPDATE yy_volume SET${sets%,}, volume_revision_dtime=NOW() WHERE volume_key=$k"
    fi
done < <(psql_at "SELECT volume_key, volume_code, COALESCE(volume_file,''), COALESCE(volume_pdf,''), COALESCE(volume_docx,'')
                    FROM yy_volume WHERE $WHERE ORDER BY volume_key")

if [ "$CHANGED" = 0 ]; then
    log "all volumes already match their volume_code"
elif [ "$APPLY" != 1 ]; then
    log "$CHANGED volume(s) would change — re-run with --apply"
fi
exit 0
