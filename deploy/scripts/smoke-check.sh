#!/usr/bin/env bash
set -Eeuo pipefail

BACKEND_PATH="${1:-/var/www/dms/current/backend}"
PHP_BIN="${PHP_BIN:-php}"

log() {
  echo "[smoke] $*"
}

fail() {
  echo "[smoke][error] $*" >&2
  exit 1
}

[[ -d "$BACKEND_PATH" ]] || fail "Backend path not found: $BACKEND_PATH"
[[ -f "$BACKEND_PATH/artisan" ]] || fail "artisan not found in $BACKEND_PATH"
command -v "$PHP_BIN" >/dev/null 2>&1 || fail "PHP binary not found: $PHP_BIN"

log "Checking storage and cache permissions"
[[ -w "$BACKEND_PATH/storage" ]] || fail "storage is not writable"
[[ -w "$BACKEND_PATH/bootstrap/cache" ]] || fail "bootstrap/cache is not writable"

log "Running lightweight Laravel checks"
(
  cd "$BACKEND_PATH"
  "$PHP_BIN" artisan about --no-interaction >/dev/null
  "$PHP_BIN" artisan migrate:status --no-interaction >/dev/null
)

APP_URL="$(
  grep -E '^APP_URL=' "$BACKEND_PATH/.env" 2>/dev/null | head -n1 | cut -d'=' -f2- | tr -d '"' || true
)"
if [[ -n "$APP_URL" ]] && command -v curl >/dev/null 2>&1; then
  HEALTH_URL="${APP_URL%/}/up"
  log "Checking HTTP health endpoint: $HEALTH_URL"
  curl -fsS --max-time 8 "$HEALTH_URL" >/dev/null || fail "Health endpoint failed: $HEALTH_URL"
fi

log "Smoke check passed"
