#!/bin/bash
# claude-fix.sh — invokes Claude Code on production to investigate & fix a single error.
#
# Usage:
#   claude-fix.sh <event_key>
#
# Reads error details from yy_monitor_event, builds a focused prompt,
# invokes `claude -p` (non-interactive), and writes the agent's resolution
# back to event_resolve_notes / event_action_taken.

set -e

EVENT_KEY="${1:?event_key required}"
LOG_DIR="/tmp/claude-fix-logs"
mkdir -p "$LOG_DIR" 2>/dev/null || true
chmod 1777 "$LOG_DIR" 2>/dev/null || true
LOG_FILE="$LOG_DIR/event_${EVENT_KEY}.log"

# claude-fix.sh must NOT run as root. The scheduler (claude-fix-runner.sh)
# drops to user 'claudefix' via systemd-run --uid before invoking us. If
# we somehow get here as root, refuse loudly — never try to "fix" it by
# chown'ing $HOME. On 2026-05-02 a previous version of this script ran
# `chown -R www-data:www-data "$HOME"` as root, with HOME defaulted to
# /root (systemd doesn't auto-set HOME for User=root services). That
# trashed every file in /root, broke key-based SSH for hours, and required
# console recovery. NEVER do that. If this branch is hit, exit and let the
# operator investigate why HOME/uid propagation broke.
if [ "$(id -u)" = "0" ]; then
    echo "claude-fix.sh refuses to run as root. Invoke via claude-fix-runner.sh, which drops to claudefix via systemd-run --uid." >&2
    exit 2
fi

# Hard guard: even as a non-root user, refuse to operate with HOME pointing
# at a system-critical path. Belt-and-suspenders against any future re-exec
# logic that might forget to set HOME explicitly.
case "${HOME:-}" in
    ''|'/'|/root|/root/*|/etc|/etc/*|/var|/var/*|/usr|/usr/*|/home)
        echo "claude-fix.sh refuses to operate with HOME='${HOME:-<unset>}'. Set HOME to /opt/yada-www/claude-home before invoking." >&2
        exit 2
        ;;
esac

# Claude credentials live on the host bind-mount at /opt/yada-www/claude-home/.claude/
# (mapped to /var/www/.claude-home/ in the container by docker-compose.prod.yml).
# Honor externally-set HOME — the host-side claude-fix-runner.sh sets HOME to
# /opt/yada-www/claude-home (the bind-mount source) and that's the canonical
# location of .claude/.credentials.json. Only fall back to the container path
# if HOME isn't set or doesn't have the .claude dir.
if [ -z "${HOME:-}" ] || [ ! -d "${HOME}/.claude" ]; then
    export HOME="/var/www/.claude-home"
fi
mkdir -p "$HOME/.claude"

# Resource & safety limits
TIMEOUT_SECS=1500

# Fetch error details. Use direct psql if available (when running inside the
# web container); fall back to `docker exec` against the postgres container
# when running on the host (claudefix user has docker group access).
export PGPASSWORD="${PG_PASS:-yada_password}"
export PGHOST="${PG_HOST:-postgres}"
export PGUSER="${PG_USER:-postgres}"
export PGDATABASE="${PG_DB:-yada}"
psql_query() {
    if command -v psql >/dev/null 2>&1; then
        psql -t -A -F'|' -c "$1"
    else
        docker exec -e PGPASSWORD="$PGPASSWORD" yada-postgres-prod \
            psql -U "$PGUSER" -d "$PGDATABASE" -t -A -F'|' -c "$1"
    fi
}
ERR_JSON=$(psql_query \
    "SELECT event_source, event_severity, event_message, COALESCE(event_detail, ''), COALESCE(event_file, ''), COALESCE(event_referer, '') FROM yy_monitor_event WHERE event_key = $EVENT_KEY" 2>&1)

if [ -z "$ERR_JSON" ]; then
    echo "Event $EVENT_KEY not found" | tee -a "$LOG_FILE"
    exit 1
fi

IFS='|' read -r SOURCE SEVERITY MESSAGE DETAIL FILE REFERER <<< "$ERR_JSON"

# Detect environment: container (web) vs host (claude-fix-runner). The host
# has source under /opt/yada-www/{public,api}/; the container sees the same
# files as /var/www/html/{,api/} via bind mount. Pick the path that exists.
# Pick CODE_ROOT by checking for the API subdirectory specifically. Plain
# /var/www/html exists on the host too (default Apache page) but never has
# a populated /api/ subdir there — only the container does, via the
# /opt/yada-www/api → /var/www/html/api bind mount.
if [ -d /var/www/html/api ]; then
    CODE_ROOT=/var/www/html
    DB_QUERY_HINT="DB writes use \`psql\` with PGPASSWORD env var (psql is in PATH)."
else
    CODE_ROOT=/opt/yada-www
    DB_QUERY_HINT="DB writes use \`psql\` with PG_HOST/PG_USER/PG_PASS env vars set (psql is in PATH)."
fi

PROMPT="You are an autonomous code-fix agent for the Yada Yah website (PHP/PostgreSQL).

An error was logged in yy_monitor_event:

Event #$EVENT_KEY
Source: $SOURCE
Severity: $SEVERITY
Message: $MESSAGE
Detail: $DETAIL
File: $FILE
Referer: $REFERER

Your task:
1. Investigate the root cause (read the relevant source files in $CODE_ROOT, check Apache logs at /var/log/apache2/error.log, query the DB if needed).
2. Apply the MINIMAL surgical fix in the code — change only the lines that demonstrably cause this specific error.
3. Verify the fix works (curl the endpoint, query the DB).
4. Mark the error resolved with a description of what you did.

ABSOLUTE PROHIBITIONS — violating any of these is a P0 incident:
- DO NOT 'clean up', refactor, reformat, or modernize ANY code not directly causing the reported error. The operator has lost weeks of hand-tuned work to agents that decided unrelated code 'looked wrong' and rewrote it. ASSUME every existing line is intentional even when it looks redundant, suboptimal, or weird.
- DO NOT remove or alter list/array literals (e.g. playback-rate arrays, option lists, color palettes, dropdown values) unless the error explicitly names that literal as the cause. Lists like \`[0.5, 0.75, 1, 1.25, ...]\` represent deliberate operator-curated choices and have been re-trimmed by the operator multiple times after agents 'restored' them.
- DO NOT change boolean configuration flags (e.g. usePortrait, useMouseEvents) unless the error explicitly says that flag is misconfigured. These are usually deliberate workarounds for library quirks — flipping them re-introduces the bug they were added to fix.
- DO NOT delete function calls or hooks (e.g. FlipbookBookmarks.init(...), recordCurrentPage(...), pageFlip.update()) that look 'optional' or 'unused'. They are usually load-bearing integration points.
- DO NOT 'simplify' multi-line constructs (replace switch with if, collapse closures, inline helpers) unless that's the root cause of the reported error.
- DO NOT touch files unrelated to the error source. If the error names /api/transcript-worker.php, do not edit any HTML, CSS, or JS file.
- DO NOT bulk-edit across many lines. One fix per error.

If you genuinely cannot tell whether a change is in-scope, LEAVE IT ALONE and mark UNRESOLVED with a note. Reverting recently-added intentional code is FAR worse than leaving a single error unfixed.

Other constraints:
- ONLY modify files under $CODE_ROOT (or /opt/yada-www/api/ if you see that path exists).
- $DB_QUERY_HINT
- Mark resolved with: UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = 'description', event_resolve_notes = 'detailed notes' WHERE event_key = $EVENT_KEY;
- If you can't confidently fix it, leave it unresolved and write a note explaining what you investigated.

Be concise. End with a one-line summary: 'RESOLVED: ...' or 'UNRESOLVED: ...'.
"

cd "$CODE_ROOT"

echo "[$(date -u +%FT%TZ)] claude-fix starting event $EVENT_KEY (CODE_ROOT=$CODE_ROOT, HOME=$HOME, uid=$(id -u))" >> "$LOG_FILE"

# Auto-fix authenticates via the OAuth/subscription session in $HOME/.claude.
# It must NEVER use the Anthropic API key (workspace billing is reserved for
# Ask Yada). Strip any inherited ANTHROPIC_API_KEY so a stray env var can't
# silently re-enable the API path.
unset ANTHROPIC_API_KEY CLAUDE_USE_API_KEY
if [ ! -s "$HOME/.claude/.credentials.json" ]; then
    echo "[$(date -u +%FT%TZ)] OAuth credentials missing at $HOME/.claude/.credentials.json — refusing to run." >> "$LOG_FILE"
    echo "[$(date -u +%FT%TZ)] Refresh via: sudo -u claudefix HOME=$HOME claude login" >> "$LOG_FILE"
    exit 2
fi

# Claude's Read/Edit tools default to CWD only. CWD is already CODE_ROOT
# (/opt/yada-www) so the production tree is implicit.
#
# Do NOT add /opt/yada-git as a readable dir. /opt/yada-git is a downstream
# mirror that lags /opt/yada-www by up to 30 minutes (rsync runs in
# git-push.sh on the cron). If Claude reads the mirror while reasoning about
# a file and then writes the rewrite back to the live tree, any changes
# made in the gap between rsyncs get silently reverted. This was the
# proximate cause of the recurring "old versions keep coming back" pattern
# the user has flagged. Always read from the live tree only.
#
# /var/log stays — read-only Apache + monitor logs, no overwrite risk.
CLAUDE_ADD_DIRS=()
if [ "$CODE_ROOT" = "/opt/yada-www" ]; then
    [ -d /var/log ]      && CLAUDE_ADD_DIRS+=(--add-dir /var/log)
fi

# Timeout-wrapped claude invocation. NO --bare flag — that would force the
# API-key auth path; we want OAuth/subscription auth only.
timeout "$TIMEOUT_SECS" claude \
    "${CLAUDE_ADD_DIRS[@]}" \
    --allow-dangerously-skip-permissions \
    -p "$PROMPT" \
    >> "$LOG_FILE" 2>&1
RC=$?

echo "[$(date -u +%FT%TZ)] claude-fix exit=$RC" >> "$LOG_FILE"

# Tail of log for inline display
tail -50 "$LOG_FILE"

exit $RC
