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

# Claude Code refuses --allow-dangerously-skip-permissions when run as root.
# If we're root, re-exec as www-data (which is what Apache/PHP runs as anyway).
if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data "$HOME" 2>/dev/null || true
    exec su www-data -s /bin/bash -c "HOME=$HOME PG_HOST=${PG_HOST:-postgres} PG_PORT=${PG_PORT:-5432} PG_DB=${PG_DB:-yada} PG_USER=${PG_USER:-postgres} PG_PASS=${PG_PASS:-yada_password} bash $0 $EVENT_KEY"
fi

# Resource & safety limits
TIMEOUT_SECS=300

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
if [ -d /var/www/html ]; then
    CODE_ROOT=/var/www/html
    DB_QUERY_HINT="DB writes use \`psql\` with PGPASSWORD env var (psql is in PATH)."
else
    CODE_ROOT=/opt/yada-www/public
    DB_QUERY_HINT="DB writes use \`docker exec -e PGPASSWORD=\$PGPASSWORD yada-postgres-prod psql -U postgres -d yada -c '...'\` (psql is NOT directly on the host PATH)."
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
2. Apply the fix in the code (write the corrected file).
3. Verify the fix works (curl the endpoint, query the DB).
4. Mark the error resolved with a description of what you did.

Constraints:
- ONLY modify files under $CODE_ROOT (or /opt/yada-www/api/ if you see that path exists).
- $DB_QUERY_HINT
- Mark resolved with: UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = 'description', event_resolve_notes = 'detailed notes' WHERE event_key = $EVENT_KEY;
- If you can't confidently fix it, leave it unresolved and write a note explaining what you investigated.

Be concise. End with a one-line summary: 'RESOLVED: ...' or 'UNRESOLVED: ...'.
"

cd "$CODE_ROOT"

echo "[$(date -u +%FT%TZ)] claude-fix starting for event $EVENT_KEY" >> "$LOG_FILE"

# If ANTHROPIC_API_KEY is set, use --bare so claude bypasses OAuth (which
# may have an expired token) and authenticates strictly via the API key.
# Without --bare, claude tries OAuth first and fails with "Not logged in".
CLAUDE_BARE_FLAG=""
if [ -n "${ANTHROPIC_API_KEY:-}" ]; then
    CLAUDE_BARE_FLAG="--bare"
    echo "[$(date -u +%FT%TZ)] using --bare + ANTHROPIC_API_KEY auth path" >> "$LOG_FILE"
fi

# Timeout-wrapped claude invocation
timeout "$TIMEOUT_SECS" claude $CLAUDE_BARE_FLAG -p "$PROMPT" \
    --allow-dangerously-skip-permissions \
    >> "$LOG_FILE" 2>&1
RC=$?

echo "[$(date -u +%FT%TZ)] claude-fix exit=$RC" >> "$LOG_FILE"

# Tail of log for inline display
tail -50 "$LOG_FILE"

exit $RC
