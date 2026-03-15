#!/usr/bin/env bash
set -Eeuo pipefail

APP_BASE="${APP_BASE:-/opt/dms}"
REPO_DIR="${REPO_DIR:-$APP_BASE/repo}"
SHARED_DIR="${SHARED_DIR:-$APP_BASE/shared}"
BRANCH="${BRANCH:-main}"
GITHUB_REPO="${GITHUB_REPO:-}"
DOCKER_BIN="${DOCKER_BIN:-docker}"
DOCKER_ENV_FILE="${DOCKER_ENV_FILE:-$SHARED_DIR/docker.env}"
AGENT_DIR="${AGENT_DIR:-}"
LARAVEL_DB_CONNECTION="${LARAVEL_DB_CONNECTION:-}"
LARAVEL_SQLITE_PATH="${LARAVEL_SQLITE_PATH:-/var/www/html/storage/database/database.sqlite}"
AGENT_BACKEND_WORKDIR="${AGENT_BACKEND_WORKDIR:-}"
AGENT_BACKEND_START_COMMAND="${AGENT_BACKEND_START_COMMAND:-}"
AGENT_BACKEND_HOST="${AGENT_BACKEND_HOST:-}"
AGENT_BACKEND_PORT="${AGENT_BACKEND_PORT:-}"
RUN_SEEDERS="${RUN_SEEDERS:-1}"
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

ensure_env_kv_quoted() {
  local file="$1"
  local key="$2"
  local value="$3"
  local escaped_value

  escaped_value="$(printf '%s' "$value" | sed -e 's/[\\"]/\\&/g')"
  if grep -Eq "^${key}=" "$file"; then
    sed -i -E "s|^${key}=.*|${key}=\"${escaped_value}\"|g" "$file"
  else
    printf "%s=\"%s\"\n" "$key" "$escaped_value" >> "$file"
  fi
}

read_env_kv() {
  local file="$1"
  local key="$2"
  local line

  line="$(grep -E "^${key}=" "$file" | head -n1 || true)"
  line="${line#${key}=}"
  line="${line%\"}"
  line="${line#\"}"
  line="${line%\'}"
  line="${line#\'}"
  printf '%s' "$line"
}

ensure_storage_permissions() {
  local storage_dir="$1"
  chmod -R u+rwX,g+rwX "$storage_dir" || true
  if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
    chown -R 82:82 "$storage_dir" || true
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
mkdir -p "$SHARED_DIR/storage/runtime"
ensure_storage_permissions "$SHARED_DIR/storage"

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

if [[ -n "$LARAVEL_DB_CONNECTION" ]]; then
  ensure_env_kv "$SHARED_LARAVEL_ENV" "DB_CONNECTION" "$LARAVEL_DB_CONNECTION"
  if [[ "$LARAVEL_DB_CONNECTION" == "sqlite" ]]; then
    ensure_env_kv "$SHARED_LARAVEL_ENV" "DB_DATABASE" "$LARAVEL_SQLITE_PATH"
  fi
fi

# Docker-mode defaults for bundled agent backend service.
if [[ -z "$(read_env_kv "$SHARED_LARAVEL_ENV" "AGENT_BACKEND_WORKDIR")" ]]; then
  ensure_env_kv "$SHARED_LARAVEL_ENV" "AGENT_BACKEND_WORKDIR" "/var/www/html/agent-backend"
fi
if [[ -z "$(read_env_kv "$SHARED_LARAVEL_ENV" "AGENT_BACKEND_HOST")" ]]; then
  ensure_env_kv "$SHARED_LARAVEL_ENV" "AGENT_BACKEND_HOST" "agent-backend"
fi
if [[ -z "$(read_env_kv "$SHARED_LARAVEL_ENV" "AGENT_BACKEND_PORT")" ]]; then
  ensure_env_kv "$SHARED_LARAVEL_ENV" "AGENT_BACKEND_PORT" "8000"
fi
if [[ -z "$(read_env_kv "$SHARED_LARAVEL_ENV" "AGENT_BUILD_REPO_PATH")" ]]; then
  ensure_env_kv "$SHARED_LARAVEL_ENV" "AGENT_BUILD_REPO_PATH" "/var/www/agent"
fi

# Normalize existing start command to a quoted dotenv-safe value.
EXISTING_AGENT_BACKEND_START_COMMAND="$(read_env_kv "$SHARED_LARAVEL_ENV" "AGENT_BACKEND_START_COMMAND")"
if [[ -n "$EXISTING_AGENT_BACKEND_START_COMMAND" ]]; then
  ensure_env_kv_quoted "$SHARED_LARAVEL_ENV" "AGENT_BACKEND_START_COMMAND" "$EXISTING_AGENT_BACKEND_START_COMMAND"
fi

if [[ -n "$AGENT_BACKEND_WORKDIR" ]]; then
  ensure_env_kv "$SHARED_LARAVEL_ENV" "AGENT_BACKEND_WORKDIR" "$AGENT_BACKEND_WORKDIR"
fi
if [[ -n "$AGENT_BACKEND_START_COMMAND" ]]; then
  ensure_env_kv_quoted "$SHARED_LARAVEL_ENV" "AGENT_BACKEND_START_COMMAND" "$AGENT_BACKEND_START_COMMAND"
fi
if [[ -n "$AGENT_BACKEND_HOST" ]]; then
  ensure_env_kv "$SHARED_LARAVEL_ENV" "AGENT_BACKEND_HOST" "$AGENT_BACKEND_HOST"
fi
if [[ -n "$AGENT_BACKEND_PORT" ]]; then
  ensure_env_kv "$SHARED_LARAVEL_ENV" "AGENT_BACKEND_PORT" "$AGENT_BACKEND_PORT"
fi

if [[ "$(read_env_kv "$SHARED_LARAVEL_ENV" "DB_CONNECTION")" == "sqlite" ]]; then
  SQLITE_DB_CONTAINER_PATH="$(read_env_kv "$SHARED_LARAVEL_ENV" "DB_DATABASE")"
  if [[ -z "$SQLITE_DB_CONTAINER_PATH" ]]; then
    SQLITE_DB_CONTAINER_PATH="$LARAVEL_SQLITE_PATH"
    ensure_env_kv "$SHARED_LARAVEL_ENV" "DB_DATABASE" "$SQLITE_DB_CONTAINER_PATH"
  fi

  SQLITE_DB_HOST_PATH=""
  if [[ "$SQLITE_DB_CONTAINER_PATH" == /var/www/html/storage/* ]]; then
    SQLITE_DB_HOST_PATH="$SHARED_DIR/storage/${SQLITE_DB_CONTAINER_PATH#/var/www/html/storage/}"
  elif [[ "$SQLITE_DB_CONTAINER_PATH" == /var/www/html/* ]]; then
    SQLITE_DB_HOST_PATH="$BACKEND_DIR/${SQLITE_DB_CONTAINER_PATH#/var/www/html/}"
  elif [[ "$SQLITE_DB_CONTAINER_PATH" == /* ]]; then
    log "DB_DATABASE path '$SQLITE_DB_CONTAINER_PATH' is absolute and not under /var/www/html; skipping host auto-create."
  else
    SQLITE_DB_HOST_PATH="$BACKEND_DIR/$SQLITE_DB_CONTAINER_PATH"
  fi

  if [[ -n "$SQLITE_DB_HOST_PATH" ]]; then
    mkdir -p "$(dirname "$SQLITE_DB_HOST_PATH")"
    touch "$SQLITE_DB_HOST_PATH"
    chmod 664 "$SQLITE_DB_HOST_PATH" || true
    if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
      chown 82:82 "$SQLITE_DB_HOST_PATH" || true
      chown 82:82 "$(dirname "$SQLITE_DB_HOST_PATH")" || true
    fi
    log "SQLite database prepared at $SQLITE_DB_HOST_PATH"
  fi
fi

ensure_env_kv "$DOCKER_ENV_FILE" "BACKEND_DIR" "$BACKEND_DIR"
if [[ -z "$AGENT_DIR" ]]; then
  AGENT_DIR="$REPO_DIR/agent"
fi
ensure_env_kv "$DOCKER_ENV_FILE" "AGENT_DIR" "$AGENT_DIR"
ensure_env_kv "$DOCKER_ENV_FILE" "APP_SHARED_DIR" "$SHARED_DIR"

if [[ -n "${APP_PORT:-}" ]]; then
  ensure_env_kv "$DOCKER_ENV_FILE" "APP_PORT" "$APP_PORT"
fi

if [[ -n "${APP_PORT:-}" ]]; then
  EXISTING_APP_URL="$(read_env_kv "$SHARED_LARAVEL_ENV" "APP_URL")"
  if [[ -z "$EXISTING_APP_URL" || "$EXISTING_APP_URL" == "http://localhost" || "$EXISTING_APP_URL" == "http://127.0.0.1" ]]; then
    ensure_env_kv "$SHARED_LARAVEL_ENV" "APP_URL" "http://127.0.0.1:${APP_PORT}"
  fi
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
  log "Generating Laravel APP_KEY in shared env"
  GENERATED_APP_KEY="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
  ensure_env_kv "$SHARED_LARAVEL_ENV" "APP_KEY" "$GENERATED_APP_KEY"
fi

log "Running migrations, seeders and cache warmup"
compose --env-file "$DOCKER_ENV_FILE" -f "$COMPOSE_FILE" exec -T app php artisan migrate --force
if [[ "$RUN_SEEDERS" == "1" ]]; then
  compose --env-file "$DOCKER_ENV_FILE" -f "$COMPOSE_FILE" exec -T app php artisan db:seed --force
fi
compose --env-file "$DOCKER_ENV_FILE" -f "$COMPOSE_FILE" exec -T app php artisan storage:link || true
compose --env-file "$DOCKER_ENV_FILE" -f "$COMPOSE_FILE" exec -T app php artisan optimize:clear
compose --env-file "$DOCKER_ENV_FILE" -f "$COMPOSE_FILE" exec -T app php artisan config:cache
compose --env-file "$DOCKER_ENV_FILE" -f "$COMPOSE_FILE" exec -T app php artisan route:cache
compose --env-file "$DOCKER_ENV_FILE" -f "$COMPOSE_FILE" exec -T app php artisan view:cache

APP_URL="$(grep -E '^APP_URL=' "$SHARED_LARAVEL_ENV" | head -n1 | cut -d'=' -f2- | tr -d '"' || true)"
CHECK_PORT="$(read_env_kv "$DOCKER_ENV_FILE" "APP_PORT")"
if [[ -z "$CHECK_PORT" ]]; then
  CHECK_PORT="80"
fi
if command -v curl >/dev/null 2>&1; then
  HEALTH_URL_LOCAL="http://127.0.0.1:${CHECK_PORT}/up"
  log "Health check: $HEALTH_URL_LOCAL"
  if ! curl -fsS --max-time 10 "$HEALTH_URL_LOCAL" >/dev/null; then
    if [[ -n "$APP_URL" ]]; then
      HEALTH_URL_APP="${APP_URL%/}/up"
      log "Fallback health check: $HEALTH_URL_APP"
      curl -fsS --max-time 10 "$HEALTH_URL_APP" >/dev/null || log "Health endpoint check failed. Verify app URL and network."
    else
      log "Health endpoint check failed. Verify app URL and network."
    fi
  fi
fi

log "Docker deployment completed successfully."
