#!/bin/bash
# Sync changed code files from the deployment directory to the git repo and push.
# Called after auto-fix applies changes, or via cron.
#
# Usage: /opt/yada-www/api/git-push.sh [commit message]

DEPLOY_DIR="/opt/yada-www"
GIT_DIR="/opt/yada-git"

# Use summary file from auto-fix if available, otherwise use argument or default
SUMMARY_FILE="/tmp/auto-fix-summary.txt"
if [ -f "$SUMMARY_FILE" ]; then
    MSG="$(cat "$SUMMARY_FILE")"
    rm -f "$SUMMARY_FILE"
elif [ -n "$1" ]; then
    MSG="$1"
else
    MSG="Auto-fix applied changes"
fi

cd "$GIT_DIR" || exit 1

# Abort any in-progress rebase and sync local master to remote before rsync.
# This ensures our rsync'd changes are applied on top of origin rather than
# stale local commits that would conflict on push.
git rebase --abort 2>/dev/null || true
git fetch origin master 2>&1
git checkout -B master origin/master 2>&1

# Sync API PHP files
rsync -a --delete "$DEPLOY_DIR/api/" "$GIT_DIR/api/" \
    --include='*.php' --include='*.sh' --include='*/' --exclude='*'

# Sync only specific public subdirectories that contain code
rsync -a "$DEPLOY_DIR/public/css/" "$GIT_DIR/public/css/" \
    --include='*.css' --include='*/' --exclude='*'

rsync -a "$DEPLOY_DIR/public/js/" "$GIT_DIR/public/js/" \
    --include='*.js' --include='*/' --exclude='*'

# Sync public root HTML files only (no subdirectories)
rsync -a "$DEPLOY_DIR/public/" "$GIT_DIR/public/" \
    --include='*.html' --exclude='*/' --exclude='*'

# Sync rumble-scraper scripts (shell and Node.js)
mkdir -p "$GIT_DIR/rumble-scraper/yy"
rsync -a --delete "$DEPLOY_DIR/rumble-scraper/yy/" "$GIT_DIR/rumble-scraper/yy/" \
    --include='*.sh' --include='*.cjs' --include='*.js' --include='*/' --exclude='*'

# Sync root config files
for f in .htaccess CLAUDE.md Dockerfile docker-compose.yml docker-compose.prod.yml docker-entrypoint.sh php.ini cron-monitor.sh cron-backup.sh book-pipeline-worker.sh claude-fix-runner.sh cron-claude-fix-gated.sh cron-flipbook-retry.sh cron-recording-lock-cleanup.sh flipbook-download-host.sh fliphtml5-download.cjs fliphtml5-upload.cjs process-email-cron.sh sync-blog-cron.sh sync-invite-cron.sh; do
    [ -f "$DEPLOY_DIR/$f" ] && cp -f "$DEPLOY_DIR/$f" "$GIT_DIR/$f" 2>/dev/null
done

# Check if there are any changes
if git diff --quiet && git diff --cached --quiet && [ -z "$(git ls-files --others --exclude-standard)" ]; then
    echo "[git-push] No changes to push"
    exit 0
fi

# Stage, commit, push
git add -A
git commit -m "$MSG

Co-Authored-By: Yada Auto-Fix <autofix@yadayah.com>"
git push origin master

echo "[git-push] Pushed: $MSG"
