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

# Sync root config files
for f in .htaccess CLAUDE.md Dockerfile docker-compose.yml docker-compose.prod.yml docker-entrypoint.sh php.ini cron-monitor.sh; do
    [ -f "$DEPLOY_DIR/$f" ] && cp -f "$DEPLOY_DIR/$f" "$GIT_DIR/$f" 2>/dev/null
done

cd "$GIT_DIR" || exit 1

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
