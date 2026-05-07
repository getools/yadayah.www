#!/bin/bash
# Drive migrate_flipbook.sh across every existing FlipHTML5 install dir
# at /opt/yada-www/public/{YY-*, The_Little_Green_Book}/.
#
# Sequential, not parallel — pdftoppm is CPU-bound and parallel runs would
# starve the rest of the host. ~5–10 min per book × 35 = 3–6 hours.
#
# Skips dirs that have already been swapped (detected by presence of
# index.html that references {{ }} placeholders OR that already serves
# from the new viewer — we check for the StPageFlip CDN script tag).
#
# Logs to /opt/yada-www/migration_progress.log; tails to stdout.
set -uo pipefail

LOG=/opt/yada-www/migration_progress.log
SCRIPT=/opt/yada-www/migrate_flipbook.sh

: > "$LOG"
exec > >(tee -a "$LOG") 2>&1

DIRS=$(ls -d /opt/yada-www/public/YY-* /opt/yada-www/public/The_Little_Green_Book 2>/dev/null)
TOTAL=$(echo "$DIRS" | wc -l)
echo "[$(date +%F\ %T)] migration starting — $TOTAL dirs to process"
i=0; ok=0; skip=0; fail=0
for d in $DIRS; do
    i=$((i+1))
    slug=$(basename "$d")
    echo
    echo "=== [$i/$TOTAL] $slug ==="
    # Skip if already migrated (new viewer has page-flip CDN tag).
    if [ -f "$d/index.html" ] && grep -q 'page-flip.browser.js' "$d/index.html"; then
        echo "  already migrated (StPageFlip detected) — skipping"
        skip=$((skip+1))
        continue
    fi
    if "$SCRIPT" "$slug"; then
        ok=$((ok+1))
    else
        echo "  FAIL — leaving FlipHTML5 install in place"
        fail=$((fail+1))
    fi
done
echo
echo "[$(date +%F\ %T)] migration done — $ok ok, $skip skipped, $fail failed (out of $TOTAL)"
