#!/usr/bin/env bash
set -Eeuo pipefail

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <github_repo_url> [branch] [app_base]"
  echo "Example: $0 https://github.com/visezion/DMS.git main /opt/dms"
  echo ""
  echo "Optional environment variables:"
  echo "  WITH_APACHE=1|0      (default: 1)"
  echo "  WITH_DOTNET=1|0      (default: 1)"
  echo "  DOTNET_CHANNEL=8.0|10.0 (default: 8.0)"
  echo "  APACHE_SERVER_NAME=<fqdn> (default: _)"
  echo "  APACHE_PUBLIC_PORT=<port> (default: 8123)"
  echo "  APACHE_TARGET_PORT=<port> (default: 80)"
  exit 1
fi

GITHUB_REPO="$1"
BRANCH="${2:-main}"
APP_BASE="${3:-/opt/dms}"
REPO_DIR="$APP_BASE/repo"
SHARED_DIR="$APP_BASE/shared"

WITH_APACHE="${WITH_APACHE:-1}"
WITH_DOTNET="${WITH_DOTNET:-1}"
DOTNET_CHANNEL="${DOTNET_CHANNEL:-8.0}"
APACHE_SERVER_NAME="${APACHE_SERVER_NAME:-_}"
APACHE_PUBLIC_PORT="${APACHE_PUBLIC_PORT:-8123}"
APACHE_TARGET_PORT="${APACHE_TARGET_PORT:-80}"
APP_PORT="${APP_PORT:-}"

OS_ID=""
OS_CODENAME=""
OS_VERSION_ID=""

log() {
  echo "[bootstrap-docker] $*"
}

fail() {
  echo "[bootstrap-docker][error] $*" >&2
  exit 1
}

as_root() {
  if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
    bash -lc "$*"
    return
  fi
  if command -v sudo >/dev/null 2>&1; then
    sudo bash -lc "$*"
    return
  fi
  fail "Root privileges are required. Re-run as root or install sudo."
}

require_supported_linux() {
  [[ "$(uname -s)" == "Linux" ]] || fail "This bootstrap supports Linux servers only."
  command -v apt-get >/dev/null 2>&1 || fail "This bootstrap currently supports Debian/Ubuntu (apt-get)."
  [[ -r /etc/os-release ]] || fail "/etc/os-release not found."

  # shellcheck disable=SC1091
  source /etc/os-release
  OS_ID="${ID:-}"
  OS_CODENAME="${VERSION_CODENAME:-}"
  OS_VERSION_ID="${VERSION_ID:-}"

  if [[ "$OS_ID" != "ubuntu" && "$OS_ID" != "debian" ]]; then
    fail "Unsupported distro: ${OS_ID:-unknown}. Use Debian/Ubuntu or install Docker/Git manually."
  fi

  if [[ -z "$OS_CODENAME" ]] && command -v lsb_release >/dev/null 2>&1; then
    OS_CODENAME="$(lsb_release -cs)"
  fi
  [[ -n "$OS_CODENAME" ]] || fail "Could not detect OS codename."
  [[ -n "$OS_VERSION_ID" ]] || fail "Could not detect OS version."
}

install_base_dependencies() {
  local install_needed=0
  for cmd in git curl gpg dpkg; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
      install_needed=1
      break
    fi
  done

  if [[ "$install_needed" -eq 1 ]]; then
    log "Installing base dependencies: git, curl, gnupg, ca-certificates."
    as_root "apt-get update -y"
    as_root "DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates curl gnupg git lsb-release"
  fi
}

install_docker_if_missing() {
  if command -v docker >/dev/null 2>&1 && (docker compose version >/dev/null 2>&1 || command -v docker-compose >/dev/null 2>&1); then
    log "Docker and Compose already available."
    return
  fi

  log "Installing Docker Engine and Docker Compose plugin."
  as_root "install -m 0755 -d /etc/apt/keyrings"
  as_root "rm -f /etc/apt/keyrings/docker.gpg"
  as_root "curl -fsSL https://download.docker.com/linux/$OS_ID/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg"
  as_root "chmod a+r /etc/apt/keyrings/docker.gpg"

  local arch
  arch="$(dpkg --print-architecture)"
  as_root "echo \"deb [arch=$arch signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/$OS_ID $OS_CODENAME stable\" > /etc/apt/sources.list.d/docker.list"
  as_root "apt-get update -y"
  as_root "DEBIAN_FRONTEND=noninteractive apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin"
  as_root "systemctl enable --now docker || true"
}

add_current_user_to_docker_group() {
  local current_user
  current_user="$(id -un)"

  if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
    return
  fi

  if ! id -nG "$current_user" | tr ' ' '\n' | grep -qx "docker"; then
    as_root "groupadd -f docker"
    as_root "usermod -aG docker '$current_user'"
    log "Added $current_user to docker group. Current session may still require sudo for docker until you re-login."
  fi
}

install_dotnet_if_requested() {
  if [[ "$WITH_DOTNET" != "1" ]]; then
    log "Skipping .NET SDK installation (WITH_DOTNET=$WITH_DOTNET)."
    return
  fi

  if [[ "$DOTNET_CHANNEL" != "8.0" && "$DOTNET_CHANNEL" != "10.0" ]]; then
    fail "Unsupported DOTNET_CHANNEL=$DOTNET_CHANNEL. Use 8.0 or 10.0."
  fi

  if command -v dotnet >/dev/null 2>&1; then
    if dotnet --list-sdks | grep -q "^${DOTNET_CHANNEL//./\\.}\\."; then
      log ".NET SDK $DOTNET_CHANNEL already installed."
      return
    fi
  fi

  log "Installing .NET SDK $DOTNET_CHANNEL."
  local ms_pkg="/tmp/packages-microsoft-prod.deb"
  local ms_url="https://packages.microsoft.com/config/${OS_ID}/${OS_VERSION_ID}/packages-microsoft-prod.deb"

  as_root "curl -fsSL '$ms_url' -o '$ms_pkg'"
  as_root "dpkg -i '$ms_pkg'"
  as_root "rm -f '$ms_pkg'"
  as_root "apt-get update -y"
  as_root "DEBIAN_FRONTEND=noninteractive apt-get install -y dotnet-sdk-${DOTNET_CHANNEL} aspnetcore-runtime-${DOTNET_CHANNEL}"

  log ".NET SDK $DOTNET_CHANNEL installed."
}

install_apache_if_requested() {
  if [[ "$WITH_APACHE" != "1" ]]; then
    log "Skipping Apache installation (WITH_APACHE=$WITH_APACHE)."
    return
  fi

  log "Installing Apache reverse proxy."
  as_root "apt-get update -y"
  as_root "DEBIAN_FRONTEND=noninteractive apt-get install -y apache2"

  # Enable required proxy modules.
  as_root "a2enmod proxy proxy_http headers rewrite >/dev/null"

  # Ensure Apache listens on requested public port and avoid default :80 conflicts.
  if [[ "$APACHE_PUBLIC_PORT" != "80" ]]; then
    as_root "sed -i -E 's/^Listen[[:space:]]+80$/# Listen 80 (disabled by DMS bootstrap)/' /etc/apache2/ports.conf"
  fi
  as_root "grep -qE '^Listen[[:space:]]+${APACHE_PUBLIC_PORT}$' /etc/apache2/ports.conf || echo 'Listen ${APACHE_PUBLIC_PORT}' >> /etc/apache2/ports.conf"

  local apache_site_tmp
  apache_site_tmp="$(mktemp)"
  cat > "$apache_site_tmp" <<EOF
<VirtualHost *:${APACHE_PUBLIC_PORT}>
    ServerName ${APACHE_SERVER_NAME}

    ProxyPreserveHost On
    ProxyRequests Off
    RequestHeader set X-Forwarded-Proto expr=%{REQUEST_SCHEME}
    RequestHeader set X-Forwarded-Port "${APACHE_PUBLIC_PORT}"

    ProxyPass / http://127.0.0.1:${APACHE_TARGET_PORT}/
    ProxyPassReverse / http://127.0.0.1:${APACHE_TARGET_PORT}/

    ErrorLog \${APACHE_LOG_DIR}/dms-error.log
    CustomLog \${APACHE_LOG_DIR}/dms-access.log combined
</VirtualHost>
EOF

  as_root "install -m 0644 '$apache_site_tmp' /etc/apache2/sites-available/dms-docker.conf"
  rm -f "$apache_site_tmp"

  as_root "a2dissite 000-default >/dev/null 2>&1 || true"
  as_root "a2ensite dms-docker >/dev/null"
  as_root "systemctl enable --now apache2"

  log "Apache proxy enabled on port ${APACHE_PUBLIC_PORT} -> Docker app port ${APACHE_TARGET_PORT}."
}

print_runtime_status() {
  log "Runtime status snapshot:"

  if command -v systemctl >/dev/null 2>&1; then
    as_root "systemctl is-active docker || true"
    if [[ "$WITH_APACHE" == "1" ]]; then
      as_root "systemctl is-active apache2 || true"
    fi
  fi

  if command -v docker >/dev/null 2>&1; then
    if docker info >/dev/null 2>&1; then
      docker ps --format 'table {{.Names}}\t{{.Status}}' || true
    elif command -v sudo >/dev/null 2>&1; then
      sudo docker ps --format 'table {{.Names}}\t{{.Status}}' || true
    fi
  fi
}

require_supported_linux
install_base_dependencies
install_docker_if_missing
add_current_user_to_docker_group
install_dotnet_if_requested

if [[ "$WITH_APACHE" == "1" && -z "$APP_PORT" ]]; then
  APP_PORT="$APACHE_TARGET_PORT"
fi
if [[ -z "$APP_PORT" ]]; then
  APP_PORT="80"
fi

install_apache_if_requested

mkdir -p "$APP_BASE"
mkdir -p "$SHARED_DIR"

if [[ ! -d "$REPO_DIR/.git" ]]; then
  log "Cloning $GITHUB_REPO into $REPO_DIR"
  git clone "$GITHUB_REPO" "$REPO_DIR"
fi

cd "$REPO_DIR"
log "Running docker deployment from branch: $BRANCH"
GITHUB_REPO="$GITHUB_REPO" BRANCH="$BRANCH" APP_BASE="$APP_BASE" APP_PORT="$APP_PORT" bash deploy/scripts/docker-deploy.sh

print_runtime_status

if [[ "$WITH_APACHE" == "1" ]]; then
  log "Access URL: http://<server-ip-or-domain>:${APACHE_PUBLIC_PORT}"
else
  log "Access URL: http://<server-ip-or-domain>:${APP_PORT}"
fi
