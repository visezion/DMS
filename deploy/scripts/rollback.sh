#!/usr/bin/env bash
set -Eeuo pipefail

APP_BASE="${APP_BASE:-/var/www/dms}"
CURRENT_LINK="$APP_BASE/current"
RELEASES_DIR="$APP_BASE/releases"
TARGET_RELEASE="${1:-}"

SUPERVISORCTL_BIN="${SUPERVISORCTL_BIN:-supervisorctl}"
QUEUE_PROGRAM="${QUEUE_PROGRAM:-dms-queue}"
SCHEDULER_PROGRAM="${SCHEDULER_PROGRAM:-dms-scheduler}"

log() {
  echo "[rollback] $*"
}

fail() {
  echo "[rollback][error] $*" >&2
  exit 1
}

[[ -L "$CURRENT_LINK" ]] || fail "Current symlink not found: $CURRENT_LINK"
[[ -d "$RELEASES_DIR" ]] || fail "Releases directory not found: $RELEASES_DIR"

CURRENT_TARGET="$(readlink -f "$CURRENT_LINK")"

if [[ -n "$TARGET_RELEASE" ]]; then
  TARGET_PATH="$RELEASES_DIR/$TARGET_RELEASE"
  [[ -d "$TARGET_PATH" ]] || fail "Target release does not exist: $TARGET_PATH"
else
  mapfile -t RELEASES < <(ls -1dt "$RELEASES_DIR"/* 2>/dev/null || true)
  TARGET_PATH=""
  for rel in "${RELEASES[@]}"; do
    if [[ "$(readlink -f "$rel")" != "$CURRENT_TARGET" ]]; then
      TARGET_PATH="$rel"
      break
    fi
  done
  [[ -n "$TARGET_PATH" ]] || fail "No previous release found for rollback."
fi

log "Switching current from $CURRENT_TARGET to $TARGET_PATH"
ln -sfn "$TARGET_PATH" "$CURRENT_LINK"

if command -v "$SUPERVISORCTL_BIN" >/dev/null 2>&1; then
  log "Restarting Supervisor processes"
  "$SUPERVISORCTL_BIN" restart "$QUEUE_PROGRAM" || true
  "$SUPERVISORCTL_BIN" restart "$SCHEDULER_PROGRAM" || true
else
  log "Supervisor not found. Skipping process restart."
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
bash "$SCRIPT_DIR/smoke-check.sh" "$CURRENT_LINK/backend"

log "Rollback complete"
