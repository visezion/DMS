#!/bin/sh
set -eu

ROOT_DIR="/var/www/html"
DEFAULT_PORT="8000"
DEFAULT_HOST="0.0.0.0"

CANDIDATES=""
if [ -n "${AGENT_BACKEND_WORKDIR:-}" ]; then
  CANDIDATES="$AGENT_BACKEND_WORKDIR"
fi
CANDIDATES="$CANDIDATES $ROOT_DIR/agent-backend $ROOT_DIR/backend/agent-backend /var/www/html/agent-backend /var/www/html/backend/agent-backend /var/www/agent-backend /var/www/backend/agent-backend"

WORKDIR=""
for candidate in $CANDIDATES; do
  if [ -f "$candidate/app/main.py" ]; then
    WORKDIR="$candidate"
    break
  fi
done

if [ -z "$WORKDIR" ]; then
  echo "Agent backend workdir not found. Checked candidates: $CANDIDATES" >&2
  exit 1
fi

PORT="${AGENT_BACKEND_PORT:-$DEFAULT_PORT}"
HOST="${AGENT_BACKEND_BIND_HOST:-$DEFAULT_HOST}"

cd "$WORKDIR"
exec python -m uvicorn app.main:app --host "$HOST" --port "$PORT"
