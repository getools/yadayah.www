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
mkdir -p "$LOG_DIR"
LOG_FILE="$LOG_DIR/event_${EVENT_KEY}.log"

# Path to Claude config — persistent across container restarts via host bind-mount
export HOME="/var/www/.claude-home"
mkdir -p "$HOME/.claude"

# Resource & safety limits
TIMEOUT_SECS=300

# Fetch error details via psql
PGPASSWORD="${PG_PASS:-yada_password}" \
ERR_JSON=$(psql -h "${PG_HOST:-postgres}" -U "${PG_USER:-postgres}" -d "${PG_DB:-yada}" -t -A -F'|' -c \
    "SELECT event_source, event_severity, event_message, COALESCE(event_detail, ''), COALESCE(event_file, ''), COALESCE(event_referer, '') FROM yy_monitor_event WHERE event_key = $EVENT_KEY")

if [ -z "$ERR_JSON" ]; then
    echo "Event $EVENT_KEY not found" | tee -a "$LOG_FILE"
    exit 1
fi

IFS='|' read -r SOURCE SEVERITY MESSAGE DETAIL FILE REFERER <<< "$ERR_JSON"

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
1. Investigate the root cause (read the relevant source files in /var/www/html, check Apache logs at /var/log/apache2/error.log, query the DB if needed).
2. Apply the fix in the code (write the corrected file).
3. Verify the fix works (curl the endpoint, query the DB).
4. Mark the error resolved with a description of what you did.

Constraints:
- ONLY modify files under /var/www/html.
- DB writes must use psql with PGPASSWORD/PG_HOST env vars.
- Mark resolved with: UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = 'description', event_resolve_notes = 'detailed notes' WHERE event_key = $EVENT_KEY;
- If you can't confidently fix it, leave it unresolved and write a note explaining what you investigated.

Be concise. End with a one-line summary: 'RESOLVED: ...' or 'UNRESOLVED: ...'.
"

cd /var/www/html

echo "[$(date -u +%FT%TZ)] claude-fix starting for event $EVENT_KEY" >> "$LOG_FILE"

# Timeout-wrapped claude invocation
timeout "$TIMEOUT_SECS" claude -p "$PROMPT" \
    --allow-dangerously-skip-permissions \
    >> "$LOG_FILE" 2>&1
RC=$?

echo "[$(date -u +%FT%TZ)] claude-fix exit=$RC" >> "$LOG_FILE"

# Tail of log for inline display
tail -50 "$LOG_FILE"

exit $RC
