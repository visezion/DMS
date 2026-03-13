#!/usr/bin/env bash
set -Eeuo pipefail

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <github_repo_url> [branch] [app_base]"
  echo "Example: $0 https://github.com/your-org/DMS.git main /opt/dms"
  exit 1
fi

GITHUB_REPO="$1"
BRANCH="${2:-main}"
APP_BASE="${3:-/opt/dms}"
REPO_DIR="$APP_BASE/repo"

log() {
  echo "[bootstrap-docker] $*"
}

fail() {
  echo "[bootstrap-docker][error] $*" >&2
  exit 1
}

command -v git >/dev/null 2>&1 || fail "git is not installed."
command -v docker >/dev/null 2>&1 || fail "docker is not installed."

mkdir -p "$APP_BASE"

if [[ ! -d "$REPO_DIR/.git" ]]; then
  log "Cloning $GITHUB_REPO into $REPO_DIR"
  git clone "$GITHUB_REPO" "$REPO_DIR"
fi

cd "$REPO_DIR"
log "Running docker deployment from branch: $BRANCH"
GITHUB_REPO="$GITHUB_REPO" BRANCH="$BRANCH" APP_BASE="$APP_BASE" bash deploy/scripts/docker-deploy.sh
