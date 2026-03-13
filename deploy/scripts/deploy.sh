#!/usr/bin/env bash
set -Eeuo pipefail

# Release-based deploy script for DMS backend.
# Expected layout:
#   /var/www/dms/repo      -> git working copy
#   /var/www/dms/releases  -> timestamped releases
#   /var/www/dms/shared    -> shared .env and storage
#   /var/www/dms/current   -> symlink to active release

APP_BASE="${APP_BASE:-/var/www/dms}"
REPO_DIR="${REPO_DIR:-$APP_BASE/repo}"
BRANCH="${BRANCH:-main}"
REPO_URL="${REPO_URL:-}"
RELEASES_TO_KEEP="${RELEASES_TO_KEEP:-5}"
ENABLE_ASSET_BUILD="${ENABLE_ASSET_BUILD:-0}"

PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
NPM_BIN="${NPM_BIN:-npm}"
SUPERVISORCTL_BIN="${SUPERVISORCTL_BIN:-supervisorctl}"
QUEUE_PROGRAM="${QUEUE_PROGRAM:-dms-queue}"
SCHEDULER_PROGRAM="${SCHEDULER_PROGRAM:-dms-scheduler}"
DEPLOY_OWNER="${DEPLOY_OWNER:-}"

RELEASES_DIR="$APP_BASE/releases"
SHARED_DIR="$APP_BASE/shared"
CURRENT_LINK="$APP_BASE/current"
TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
RELEASE_PATH="$RELEASES_DIR/$TIMESTAMP"

log() {
  echo "[deploy] $*"
}

fail() {
  echo "[deploy][error] $*" >&2
  exit 1
}

command -v "$PHP_BIN" >/dev/null 2>&1 || fail "PHP binary not found: $PHP_BIN"
command -v "$COMPOSER_BIN" >/dev/null 2>&1 || fail "Composer binary not found: $COMPOSER_BIN"

mkdir -p "$APP_BASE" "$RELEASES_DIR" "$SHARED_DIR"

if [[ ! -d "$REPO_DIR/.git" ]]; then
  [[ -n "$REPO_URL" ]] || fail "Repo not found at $REPO_DIR and REPO_URL not provided."
  log "Cloning repository into $REPO_DIR"
  git clone "$REPO_URL" "$REPO_DIR"
fi

log "Fetching latest branch: $BRANCH"
git -C "$REPO_DIR" fetch --all --prune
git -C "$REPO_DIR" checkout "$BRANCH"
git -C "$REPO_DIR" pull --ff-only origin "$BRANCH"

log "Creating new release: $RELEASE_PATH"
mkdir -p "$RELEASE_PATH"
git -C "$REPO_DIR" archive "$BRANCH" | tar -x -C "$RELEASE_PATH"

BACKEND_PATH="$RELEASE_PATH/backend"
[[ -f "$BACKEND_PATH/artisan" ]] || fail "Release does not contain backend/artisan."

if [[ ! -f "$SHARED_DIR/.env" ]]; then
  [[ -f "$REPO_DIR/backend/.env.production.example" ]] || fail "Missing shared .env and .env.production.example."
  cp "$REPO_DIR/backend/.env.production.example" "$SHARED_DIR/.env"
  log "Created $SHARED_DIR/.env from .env.production.example. Edit secrets before go-live."
fi

if [[ ! -d "$SHARED_DIR/storage" ]]; then
  mkdir -p "$SHARED_DIR/storage"
  cp -R "$BACKEND_PATH/storage/." "$SHARED_DIR/storage/"
fi

rm -f "$BACKEND_PATH/.env"
ln -s "$SHARED_DIR/.env" "$BACKEND_PATH/.env"
rm -rf "$BACKEND_PATH/storage"
ln -s "$SHARED_DIR/storage" "$BACKEND_PATH/storage"

mkdir -p "$SHARED_DIR/storage/framework/cache"
mkdir -p "$SHARED_DIR/storage/framework/sessions"
mkdir -p "$SHARED_DIR/storage/framework/views"
mkdir -p "$SHARED_DIR/storage/logs"

if [[ -n "$DEPLOY_OWNER" ]]; then
  chown -R "$DEPLOY_OWNER" "$SHARED_DIR" || true
fi

log "Installing PHP dependencies"
(
  cd "$BACKEND_PATH"
  "$COMPOSER_BIN" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
)

if [[ "$ENABLE_ASSET_BUILD" == "1" ]]; then
  command -v "$NPM_BIN" >/dev/null 2>&1 || fail "NPM not found but ENABLE_ASSET_BUILD=1."
  log "Building frontend assets"
  (
    cd "$BACKEND_PATH"
    "$NPM_BIN" ci
    "$NPM_BIN" run build
  )
fi

log "Running Laravel optimize + migrations"
(
  cd "$BACKEND_PATH"
  "$PHP_BIN" artisan optimize:clear
  "$PHP_BIN" artisan migrate --force
  "$PHP_BIN" artisan config:cache
  "$PHP_BIN" artisan route:cache
  "$PHP_BIN" artisan view:cache
)

log "Switching current symlink"
ln -sfn "$RELEASE_PATH" "$CURRENT_LINK"

if command -v "$SUPERVISORCTL_BIN" >/dev/null 2>&1; then
  log "Restarting Supervisor processes: $QUEUE_PROGRAM, $SCHEDULER_PROGRAM"
  "$SUPERVISORCTL_BIN" reread || true
  "$SUPERVISORCTL_BIN" update || true
  "$SUPERVISORCTL_BIN" restart "$QUEUE_PROGRAM" || true
  "$SUPERVISORCTL_BIN" restart "$SCHEDULER_PROGRAM" || true
else
  log "Supervisor not found. Skipping process restart."
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
log "Running smoke check"
bash "$SCRIPT_DIR/smoke-check.sh" "$CURRENT_LINK/backend"

log "Pruning old releases (keep $RELEASES_TO_KEEP)"
mapfile -t ALL_RELEASES < <(ls -1dt "$RELEASES_DIR"/* 2>/dev/null || true)
if (( ${#ALL_RELEASES[@]} > RELEASES_TO_KEEP )); then
  for old in "${ALL_RELEASES[@]:RELEASES_TO_KEEP}"; do
    rm -rf "$old"
  done
fi

log "Deploy complete: $TIMESTAMP"
