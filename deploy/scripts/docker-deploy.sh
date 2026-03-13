#!/usr/bin/env bash
set -Eeuo pipefail

APP_BASE="${APP_BASE:-/opt/dms}"
REPO_DIR="${REPO_DIR:-$APP_BASE/repo}"
SHARED_DIR="${SHARED_DIR:-$APP_BASE/shared}"
BRANCH="${BRANCH:-main}"
GITHUB_REPO="${GITHUB_REPO:-}"
DOCKER_BIN="${DOCKER_BIN:-docker}"
DOCKER_ENV_FILE="${DOCKER_ENV_FILE:-$SHARED_DIR/docker.env}"
DOCKER_CMD=()

COMPOSE_FILE_REL="deploy/docker/docker-compose.prod.yml"
LARAVEL_ENV_TEMPLATE_REL="deploy/docker/laravel.env.example"
DOCKER_ENV_TEMPLATE_REL="deploy/docker/docker.env.example"

log() {
  echo "[docker-deploy] $*"
}

fail() {
  echo "[docker-deploy][error] $*" >&2
  exit 1
}

ensure_env_kv() {
  local file="$1"
  local key="$2"
  local value="$3"
  local escaped_value

  escaped_value="$(printf '%s' "$value" | sed -e 's/[\/&]/\\&/g')"
  if grep -Eq "^${key}=" "$file"; then
    sed -i -E "s|^${key}=.*|${key}=${escaped_value}|g" "$file"
  else
    printf "%s=%s\n" "$key" "$value" >> "$file"
  fi
}

compose() {
  if "${DOCKER_CMD[@]}" compose version >/dev/null 2>&1; then
    "${DOCKER_CMD[@]}" compose "$@"
    return
  fi

  if command -v docker-compose >/dev/null 2>&1; then
    if docker-compose version >/dev/null 2>&1; then
      docker-compose "$@"
      return
    fi
    if command -v sudo >/dev/null 2>&1 && sudo -n docker-compose version >/dev/null 2>&1; then
      sudo -n docker-compose "$@"
      return
    fi
  fi

  fail "Docker Compose is not available."
}

command -v git >/dev/null 2>&1 || fail "git is not installed."
command -v "$DOCKER_BIN" >/dev/null 2>&1 || fail "docker is not installed."

DOCKER_CMD=("$DOCKER_BIN")
if ! "$DOCKER_BIN" info >/dev/null 2>&1; then
  if command -v sudo >/dev/null 2>&1; then
    if sudo -n "$DOCKER_BIN" info >/dev/null 2>&1; then
      DOCKER_CMD=(sudo -n "$DOCKER_BIN")
      log "Using passwordless sudo for Docker commands."
    elif [[ -t 0 ]]; then
      if sudo "$DOCKER_BIN" info >/dev/null 2>&1; then
        DOCKER_CMD=(sudo "$DOCKER_BIN")
        log "Using sudo for Docker commands."
      else
        fail "Cannot access docker daemon, even with sudo."
      fi
    else
      fail "Cannot access docker daemon. Add user to docker group or allow passwordless sudo for docker."
    fi
  else
    fail "Cannot access docker daemon and sudo is not available."
  fi
fi

mkdir -p "$APP_BASE" "$SHARED_DIR" "$SHARED_DIR/storage"
mkdir -p "$SHARED_DIR/storage/framework/cache" "$SHARED_DIR/storage/framework/sessions" "$SHARED_DIR/storage/framework/views"
mkdir -p "$SHARED_DIR/storage/logs" "$SHARED_DIR/storage/app/public"

if [[ ! -d "$REPO_DIR/.git" ]]; then
  [[ -n "$GITHUB_REPO" ]] || fail "Repo not found at $REPO_DIR. Set GITHUB_REPO to clone."
  log "Cloning repository: $GITHUB_REPO -> $REPO_DIR"
  git clone "$GITHUB_REPO" "$REPO_DIR"
fi

if [[ -n "$GITHUB_REPO" ]]; then
  git -C "$REPO_DIR" remote set-url origin "$GITHUB_REPO"
fi

log "Fetching latest branch: $BRANCH"
git -C "$REPO_DIR" fetch --all --prune
git -C "$REPO_DIR" checkout "$BRANCH"
git -C "$REPO_DIR" pull --ff-only origin "$BRANCH"

BACKEND_DIR="$REPO_DIR/backend"
COMPOSE_FILE="$REPO_DIR/$COMPOSE_FILE_REL"
LARAVEL_ENV_TEMPLATE="$REPO_DIR/$LARAVEL_ENV_TEMPLATE_REL"
DOCKER_ENV_TEMPLATE="$REPO_DIR/$DOCKER_ENV_TEMPLATE_REL"
SHARED_LARAVEL_ENV="$SHARED_DIR/.env"

[[ -f "$BACKEND_DIR/artisan" ]] || fail "backend/artisan not found in $REPO_DIR."
[[ -f "$COMPOSE_FILE" ]] || fail "Compose file missing: $COMPOSE_FILE"
[[ -f "$LARAVEL_ENV_TEMPLATE" ]] || fail "Laravel env template missing: $LARAVEL_ENV_TEMPLATE"
[[ -f "$DOCKER_ENV_TEMPLATE" ]] || fail "Docker env template missing: $DOCKER_ENV_TEMPLATE"

if [[ ! -f "$SHARED_LARAVEL_ENV" ]]; then
  cp "$LARAVEL_ENV_TEMPLATE" "$SHARED_LARAVEL_ENV"
  log "Created $SHARED_LARAVEL_ENV from template. Update secrets before production traffic."
fi

if [[ ! -f "$DOCKER_ENV_FILE" ]]; then
  cp "$DOCKER_ENV_TEMPLATE" "$DOCKER_ENV_FILE"
  log "Created $DOCKER_ENV_FILE with default Docker settings."
fi

ensure_env_kv "$DOCKER_ENV_FILE" "BACKEND_DIR" "$BACKEND_DIR"
ensure_env_kv "$DOCKER_ENV_FILE" "APP_SHARED_DIR" "$SHARED_DIR"

if [[ -n "${APP_PORT:-}" ]]; then
  ensure_env_kv "$DOCKER_ENV_FILE" "APP_PORT" "$APP_PORT"
fi

log "Building app image"
compose --env-file "$DOCKER_ENV_FILE" -f "$COMPOSE_FILE" build app

log "Installing PHP dependencies inside app container"
compose --env-file "$DOCKER_ENV_FILE" -f "$COMPOSE_FILE" run --rm app \
  composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

log "Building frontend assets with Node container"
"${DOCKER_CMD[@]}" run --rm \
  -v "$BACKEND_DIR:/app" \
  -w /app \
  node:20-alpine \
  sh -lc "npm ci && npm run build"

log "Starting containers"
compose --env-file "$DOCKER_ENV_FILE" -f "$COMPOSE_FILE" up -d --build

if ! grep -Eq '^APP_KEY=base64:' "$SHARED_LARAVEL_ENV"; then
  log "Generating Laravel APP_KEY"
  compose --env-file "$DOCKER_ENV_FILE" -f "$COMPOSE_FILE" exec -T app php artisan key:generate --force
fi

log "Running migrations and cache warmup"
compose --env-file "$DOCKER_ENV_FILE" -f "$COMPOSE_FILE" exec -T app php artisan migrate --force
compose --env-file "$DOCKER_ENV_FILE" -f "$COMPOSE_FILE" exec -T app php artisan storage:link || true
compose --env-file "$DOCKER_ENV_FILE" -f "$COMPOSE_FILE" exec -T app php artisan optimize:clear
compose --env-file "$DOCKER_ENV_FILE" -f "$COMPOSE_FILE" exec -T app php artisan config:cache
compose --env-file "$DOCKER_ENV_FILE" -f "$COMPOSE_FILE" exec -T app php artisan route:cache
compose --env-file "$DOCKER_ENV_FILE" -f "$COMPOSE_FILE" exec -T app php artisan view:cache

APP_URL="$(grep -E '^APP_URL=' "$SHARED_LARAVEL_ENV" | head -n1 | cut -d'=' -f2- | tr -d '"' || true)"
if [[ -n "$APP_URL" ]] && command -v curl >/dev/null 2>&1; then
  HEALTH_URL="${APP_URL%/}/up"
  log "Health check: $HEALTH_URL"
  curl -fsS --max-time 10 "$HEALTH_URL" >/dev/null || log "Health endpoint check failed. Verify app URL and network."
fi

log "Docker deployment completed successfully."
