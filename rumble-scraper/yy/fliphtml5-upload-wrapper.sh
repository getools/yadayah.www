#!/bin/bash
# Wrapper that starts Xvfb on :99, runs the upload script under that display,
# then cleans up. Avoids needing xvfb-run / xauth (which aren't always present
# in the rsshub image and don't survive a container recreate).
#
# All env vars (PDF_NAME, FLIP_EMAIL, FLIP_PASS, FLIP_CODE, etc.) pass through
# unchanged from the docker exec invocation in book-pipeline-worker.sh.

set -uo pipefail

DISPLAY_NUM=${DISPLAY_NUM:-99}
SCREEN_GEOM=${SCREEN_GEOM:-1280x900x24}

# Kill any Xvfb that leaked from a prior crashed run on this display (SIGKILL
# bypasses the EXIT trap, leaving zombie Xvfb processes that accumulate and
# exhaust the container's pids_limit). Clean slate before starting our own.
pkill -TERM -f "Xvfb :${DISPLAY_NUM}" 2>/dev/null || true
sleep 0.2
pkill -KILL -f "Xvfb :${DISPLAY_NUM}" 2>/dev/null || true
rm -f "/tmp/.X${DISPLAY_NUM}-lock" "/tmp/.X11-unix/X${DISPLAY_NUM}"

# Quietly start Xvfb. -ac disables host-based access control so we can connect
# without xauth cookies. -nolisten tcp keeps it socket-only.
Xvfb ":${DISPLAY_NUM}" -screen 0 "${SCREEN_GEOM}" -ac -nolisten tcp >/dev/null 2>&1 &
XVFB_PID=$!

cleanup() {
    if [ -n "${XVFB_PID:-}" ] && kill -0 "$XVFB_PID" 2>/dev/null; then
        kill "$XVFB_PID" 2>/dev/null || true
        wait "$XVFB_PID" 2>/dev/null || true
    fi
}
trap cleanup EXIT INT TERM

# Give Xvfb a moment to bind the display socket.
for _ in 1 2 3 4 5; do
    if [ -e "/tmp/.X${DISPLAY_NUM}-lock" ] || [ -e "/tmp/.X11-unix/X${DISPLAY_NUM}" ]; then
        break
    fi
    sleep 0.2
done

export DISPLAY=":${DISPLAY_NUM}"
node /scraper/yy/fliphtml5-upload.cjs
exit $?
