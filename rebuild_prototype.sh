#!/bin/bash
# Rebuild a flipbook-prototype bundle from the current PDF.
#
# Usage:  rebuild_prototype.sh <stem>
#         e.g.  rebuild_prototype.sh YY-s02v03-Yada-Yahowah-Beyth-In-the-Family
#
# Reads /opt/yada-www/public/pdf/<stem>.pdf and regenerates the four
# derived assets in /opt/yada-www/public/flipbook-prototype/<stem>/:
#     pages/page-NNN.jpg   — 200dpi rendered pages (1200x1804)
#     thumbs/thumb-NNN.jpg —  33dpi thumbnails    (~200x300)
#     text/page-NNN.json   — per-page span-positioned text overlays
#     search.json          — single-file search index
#     toc.json             — outline with stable slugs
#
# Does NOT touch index.html (handcrafted) or any other files in the dir.
# Renders into a temp dir and rsyncs over the live one so we never serve
# a half-built prototype.
set -euo pipefail

STEM="${1:?stem required, e.g. YY-s02v03-Yada-Yahowah-Beyth-In-the-Family}"
PDF=/opt/yada-www/public/pdf/${STEM}.pdf
OUT=/opt/yada-www/public/flipbook-prototype/${STEM}

if [ ! -f "$PDF" ]; then
    echo "no such PDF: $PDF" >&2; exit 1
fi
mkdir -p "$OUT"

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

echo "[$(date +%H:%M:%S)] rendering pages (200dpi)…"
mkdir -p "$TMP/pages"
pdftoppm -jpeg -r 200 -jpegopt 'quality=85,progressive=y' "$PDF" "$TMP/pages/page" 2>&1 | tail -3

echo "[$(date +%H:%M:%S)] rendering thumbs (33dpi)…"
mkdir -p "$TMP/thumbs"
pdftoppm -jpeg -r 33 -jpegopt 'quality=80' "$PDF" "$TMP/thumbs/thumb" 2>&1 | tail -3

# pdftoppm names files page-1.jpg, page-2.jpg, etc. Re-pad to 3 digits to
# match existing pages/page-001.jpg / thumbs/thumb-001.jpg convention.
# Force base-10 with `10#` so already-padded inputs like "008", "009" don't
# trip printf's octal parser (which rejects 8 and 9 as invalid octal digits).
pad_dir() {
    local d="$1" prefix="$2"
    for f in "$d"/${prefix}-*.jpg; do
        local b; b=$(basename "$f")
        local n; n=$(echo "$b" | sed -E "s/^${prefix}-([0-9]+)\.jpg/\1/")
        local padded; padded=$(printf '%03d' "$((10#$n))")
        if [ "$n" != "$padded" ]; then
            mv "$f" "$d/${prefix}-${padded}.jpg"
        fi
    done
}
pad_dir "$TMP/pages" page
pad_dir "$TMP/thumbs" thumb

echo "[$(date +%H:%M:%S)] extract_text → search.json…"
python3 /tmp/extract_text.py "$PDF" "$TMP/search.json"

echo "[$(date +%H:%M:%S)] extract_text_layer → text/…"
mkdir -p "$TMP/text"
python3 /tmp/extract_text_layer.py "$PDF" "$TMP/text"

echo "[$(date +%H:%M:%S)] extract_toc → toc.json…"
# Look up volume_key by inverting the disk-stem ↔ volume_file rule.
# DB volume_file uses spaces and apostrophes; disk stem replaces spaces
# with hyphens but PRESERVES apostrophes. So query for any volume whose
# space-replaced volume_file matches our stem.
VOL_KEY=$(docker exec yada-postgres-prod psql -U postgres -d yada -At -c \
    "SELECT volume_key FROM yy_volume \
     WHERE replace(volume_file, ' ', '-') = '${STEM}'")
if [ -z "$VOL_KEY" ]; then
    echo "could not resolve volume_key for stem: $STEM" >&2; exit 1
fi
# extract_toc_v3 reads from yy_chapter via docker exec psql, so it needs
# no DB env. ONLYOFFICE-produced PDFs have no outline bookmarks; this
# extractor walks pdftotext output looking for "<n> <chapter_name>" lines
# matched against yy_chapter rows for the volume.
python3 /tmp/extract_toc_v3.py "$PDF" "$VOL_KEY" "$TMP/toc.json"

# Sync into place. --delete on the subdirs only — index.html is preserved.
echo "[$(date +%H:%M:%S)] syncing to $OUT/…"
mkdir -p "$OUT/pages" "$OUT/thumbs" "$OUT/text"
rsync -a --delete "$TMP/pages/"  "$OUT/pages/"
rsync -a --delete "$TMP/thumbs/" "$OUT/thumbs/"
rsync -a --delete "$TMP/text/"   "$OUT/text/"
cp "$TMP/search.json" "$OUT/search.json"
cp "$TMP/toc.json"    "$OUT/toc.json"

echo "[$(date +%H:%M:%S)] done. Page count:"
ls "$OUT/pages" | wc -l

# Bump bundleVersion in the book's index.html so browsers refetch all
# bundle assets (page JPGs, text-layer JSON, search.json, toc.json) after
# this rebuild. Without this, browsers serve cached copies of the OLD
# PDF's images for hours, while the text-layer they overlay comes from
# the freshly-rebuilt JSON — search highlights then land on stale image
# positions because the visible word and the text-layer's data-text are
# from different PDFs.
INDEX="$OUT/index.html"
if [ -f "$INDEX" ]; then
    BV=$(date +%Y%m%d%H%M)
    if grep -q bundleVersion "$INDEX"; then
        sed -i "s|bundleVersion: \"[^\"]*\",|bundleVersion: \"$BV\",|" "$INDEX"
    else
        sed -i "s|pdfPath:|bundleVersion: \"$BV\",\n      pdfPath:|" "$INDEX"
    fi
    echo "[$(date +%H:%M:%S)] bumped bundleVersion to $BV"
fi
