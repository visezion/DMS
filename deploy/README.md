# DMS Production Deployment Kit

This folder provides a practical production deployment flow for the Laravel backend in `backend/`.

## What You Get

- `scripts/deploy.sh`: release-based deployment (fetch, build, migrate, cache, switch symlink).
- `scripts/rollback.sh`: quick rollback to previous release.
- `scripts/smoke-check.sh`: post-deploy checks.
- `scripts/docker-deploy.sh`: Docker production deploy/update from GitHub.
- `scripts/bootstrap-docker-from-github.sh`: first-time one-command bootstrap from GitHub.
- `nginx/dms.conf`: Nginx site template.
- `supervisor/dms-queue.conf`: queue worker process template.
- `supervisor/dms-scheduler.conf`: scheduler worker process template.
- `docker/docker-compose.prod.yml`: Docker production stack (nginx, app, queue, scheduler, agent-backend, mysql, redis).
- `docker/docker.env.example`: Docker compose env template.
- `docker/laravel.env.example`: Laravel env template for Docker mode.

## Recommended Server Stack

- Ubuntu 22.04+
- Nginx
- PHP-FPM 8.2 with required extensions
- MySQL 8+ (or PostgreSQL if you prefer)
- Redis
- Supervisor

## One-Time Server Setup

1. Create deployment base folders:
   - `/var/www/dms/repo`
   - `/var/www/dms/releases`
   - `/var/www/dms/shared`
2. Clone repo into `/var/www/dms/repo`.
3. Copy production env:
   - `cp /var/www/dms/repo/backend/.env.production.example /var/www/dms/shared/.env`
4. Edit `/var/www/dms/shared/.env` for your domain, DB, Redis, mail, and secrets.
5. Install Nginx site config from `deploy/nginx/dms.conf`.
6. Install Supervisor configs from `deploy/supervisor/*.conf`.

## Deploy (New Release)

Run on server:

```bash
cd /var/www/dms/repo
bash deploy/scripts/deploy.sh
```

Useful env overrides:

```bash
APP_BASE=/var/www/dms \
BRANCH=main \
RELEASES_TO_KEEP=5 \
ENABLE_ASSET_BUILD=0 \
bash deploy/scripts/deploy.sh
```

## Docker Deploy (One Command, GitHub-Connected)

Prerequisites on server:

- Debian/Ubuntu Linux server
- Root privileges or `sudo`
- Internet access to GitHub and Docker apt repository

The bootstrap script auto-installs required dependencies:

- Docker Engine
- Docker Compose plugin
- Git
- Curl + GnuPG + CA certificates
- Apache2 (enabled as reverse proxy by default)
- .NET SDK (8.0 by default, can switch to 10.0)

It may prompt for your sudo password during package installation.

For first-time install directly from GitHub on a clean server:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/visezion/DMS/main/deploy/scripts/bootstrap-docker-from-github.sh) https://github.com/visezion/DMS.git main /opt/dms
```

Customize bootstrap behavior:

```bash
WITH_APACHE=1 \
WITH_DOTNET=1 \
DOTNET_CHANNEL=8.0 \
APACHE_SERVER_NAME=dms.example.com \
APACHE_PUBLIC_PORT=8123 \
APACHE_TARGET_PORT=80 \
LARAVEL_DB_CONNECTION=sqlite \
bash <(curl -fsSL https://raw.githubusercontent.com/visezion/DMS/main/deploy/scripts/bootstrap-docker-from-github.sh) https://github.com/visezion/DMS.git main /opt/dms
```

Optional flags:

- `WITH_APACHE=1|0` (default `1`)
- `WITH_DOTNET=1|0` (default `1`)
- `DOTNET_CHANNEL=8.0|10.0` (default `8.0`)
- `APACHE_SERVER_NAME` (default `_`)
- `APACHE_PUBLIC_PORT` (default `8123`)
- `APACHE_TARGET_PORT` (default `80`)
- `LARAVEL_DB_CONNECTION` (`mysql|pgsql|sqlite`, default keeps template value)
- `LARAVEL_SQLITE_PATH` (default `/var/www/html/storage/database/database.sqlite`)
- `AGENT_BACKEND_WORKDIR` (optional path containing `app/main.py`; Docker default `/var/www/html/agent-backend`)
- `AGENT_BACKEND_START_COMMAND` (optional launcher command)
- `AGENT_BACKEND_HOST` (optional health check host, Docker default `agent-backend`)
- `AGENT_BACKEND_PORT` (optional health check port, default `8000`)
- `AGENT_DIR` (optional host path for DMS agent source repository; default `${APP_BASE}/repo/agent`)
- `RUN_SEEDERS=1|0` (default `1`, runs `php artisan db:seed --force`)

Seeder admin defaults:

- Seeder now uses generic env-based bootstrap credentials (no personal credentials in repo):
  - `DMS_SEED_TENANT_NAME="Default Tenant"`
  - `DMS_SEED_TENANT_SLUG=default`
  - `DMS_SEED_ADMIN_EMAIL=admin@example.com`
  - `DMS_SEED_ADMIN_PASSWORD=admin123`
- Set these in `${APP_BASE}/shared/.env` before first deploy.
- Seeder creates the tenant plus a platform-scoped `super-admin` role and admin user if they do not exist.
- Reruns do not force-reset existing admin passwords.

Self-signup (SaaS onboarding):

- `DMS_SELF_SIGNUP_ENABLED=true|false` controls public `/admin/signup`.
- Signup creates:
  - a new active tenant (organization),
  - a tenant-scoped `super-admin` role,
  - the tenant admin user attached to that tenant.
- Tenant users are scoped to their own organization data by default.

Agent note:

- The DMS Windows agent targets `net8.0-windows`.
- Agent auto-build runs inside the `app` container. The app image now installs .NET SDK (`DOTNET_CHANNEL`, default `8.0`) and uses a Linux shell build fallback when PowerShell is unavailable.

Script source:

- `https://github.com/visezion/DMS/blob/main/deploy/scripts/bootstrap-docker-from-github.sh`

For updates after first install:

```bash
GITHUB_REPO=https://github.com/visezion/DMS.git BRANCH=main APP_BASE=/opt/dms bash /opt/dms/repo/deploy/scripts/docker-deploy.sh
```

Common command examples:

```bash
# Fresh install on custom app port and run seeders
WITH_APACHE=0 APP_PORT=8089 RUN_SEEDERS=1 \
bash <(curl -fsSL https://raw.githubusercontent.com/visezion/DMS/main/deploy/scripts/bootstrap-docker-from-github.sh) \
https://github.com/visezion/DMS.git main /opt/dms

# Update existing install, set app port, and skip seeders
APP_BASE=/opt/dms GITHUB_REPO=https://github.com/visezion/DMS.git BRANCH=main APP_PORT=8089 RUN_SEEDERS=0 \
bash /opt/dms/repo/deploy/scripts/docker-deploy.sh

# Run only seeders manually later (if needed)
docker compose --env-file /opt/dms/shared/docker.env -f /opt/dms/repo/deploy/docker/docker-compose.prod.yml exec -T app php artisan db:seed --force
```

Docker mode files created on first run:

- `/opt/dms/shared/.env` (Laravel runtime env)
- `/opt/dms/shared/docker.env` (Docker compose env)

Edit both files for production secrets and ports, then re-run the same deploy command.

Service startup behavior:

- Docker service is enabled and started.
- Apache service is enabled and started when `WITH_APACHE=1`.
- App containers are started: `nginx`, `app`, `queue`, `scheduler`, `agent-backend`, `mysql`, `redis`.
- Post-start automation runs: `migrate --force`, `db:seed --force` (default), cache warmup.

## Docker Troubleshooting

- `Bind for 0.0.0.0:8080 failed: port is already allocated`
  - Ensure `/opt/dms/shared/docker.env` has `APP_PORT=80` (for Apache proxy `8123 -> 80`), then rerun deploy.
- Health check says `http://localhost/up` failed while app is up on custom port
  - Deploy now checks `http://127.0.0.1:${APP_PORT}/up` first, then falls back to `APP_URL`.
  - For first-time install, if `APP_URL` is still `http://localhost`, deploy auto-sets it to `http://127.0.0.1:${APP_PORT}`.
- `Bind for 0.0.0.0:80 failed: port is already allocated`
  - Another host service is using port `80` (often Apache/Nginx).
  - If using Apache public `8123`, disable host `Listen 80` and restart Apache, then recreate `nginx` container.
- Use SQLite in automation (no manual `.env` edits)
  - Add `LARAVEL_DB_CONNECTION=sqlite` to bootstrap/deploy command.
  - Deploy script auto-creates SQLite file from `DB_DATABASE` path before migrations.
  - If you hit HTTP 500 after switching to SQLite, fix storage ownership/permissions and clear Laravel caches.
- `APP_KEY` empty and `php artisan key:generate` fails with read-only `.env`
  - In Docker mode, `.env` is mounted read-only into the app container by design.
  - Set `APP_KEY` in `/opt/dms/shared/.env` on host, then clear/cache config.
- `Backend start command expects app/main.py` in Agent Delivery
  - DMS includes a bundled Python API at `backend/agent-backend/app/main.py` for default startup.
  - Set `AGENT_BACKEND_WORKDIR` to your Python project folder (must contain `app/main.py` for the default command), then redeploy or update `/opt/dms/shared/.env`.
  - If you override `AGENT_BACKEND_WORKDIR`, ensure that folder contains `app/main.py`.
- Agent backend not reachable after app/container recreate
  - Use the built-in `agent-backend` service in Docker compose (started automatically by deploy).
  - Service startup now uses `scripts/runtime/agent-backend.sh`, which auto-discovers valid workdirs such as:
    - `/var/www/html/agent-backend`
    - `/var/www/html/backend/agent-backend`
  - Python dependencies are installed in `/opt/agent-backend-venv` inside the app image (PEP 668 safe), and launcher uses that venv automatically.
  - Keep `AGENT_BACKEND_HOST=agent-backend` in `/opt/dms/shared/.env`.
- Agent auto-build fails with `Agent repository folder not found at: /var/www/agent`
  - Docker deploy now mounts host `AGENT_DIR` into app container as `/var/www/agent`.
  - Default host source is `${APP_BASE}/repo/agent`; override with `AGENT_DIR=/path/to/agent`.
  - Laravel runtime env is auto-corrected to `AGENT_BUILD_REPO_PATH=/var/www/agent` by default.
  - Deploy now fails fast if `${AGENT_DIR}/src/Dms.Agent.Service/Dms.Agent.Service.csproj` is missing.
- Agent auto-build fails with `Permission denied` under `storage/app/agent-releases/builds/.work`
  - Deploy now auto-repairs permissions inside the running `app` container and verifies writable access as uid `82` before migrations/cache warmup.
  - This removes the need for manual `chown/chmod` after one-command install/update.
- Agent auto-build fails with `PowerShell runtime was not found inside the app environment`
  - Auto-build no longer requires PowerShell on Linux: it falls back to `backend/scripts/build-agent.sh`.
  - Rebuild and restart the app image so .NET SDK is present in the container:
    `docker compose --env-file /opt/dms/shared/docker.env -f /opt/dms/repo/deploy/docker/docker-compose.prod.yml up -d --build app nginx queue scheduler agent-backend`
- Agent auto-build fails with `.NET SDK was not found in the app runtime`
  - Ensure app image was rebuilt after pull and that `dotnet` exists in app container:
    `docker compose --env-file /opt/dms/shared/docker.env -f /opt/dms/repo/deploy/docker/docker-compose.prod.yml exec -T app dotnet --list-sdks`
- AI Runtime shows offline while `queue`/`scheduler` containers are up
  - Docker queue/scheduler now write heartbeat files in `storage/runtime`.
  - Redeploy so compose uses `scripts/runtime/queue-worker.sh` and `scripts/runtime/scheduler-worker.sh`.
  - Verify files exist in shared storage:
    - `/opt/dms/shared/storage/runtime/queue-heartbeat`
    - `/opt/dms/shared/storage/runtime/scheduler-heartbeat`
- `.env` parse error for AGENT_BACKEND_START_COMMAND
  - If you set this value manually in `.env`, wrap it in quotes:
    `AGENT_BACKEND_START_COMMAND="python -m uvicorn app.main:app --host 127.0.0.1 --port 8000"`.
  - `deploy/scripts/docker-deploy.sh` now normalizes this value to a quoted dotenv-safe format on each run.
- MySQL migration error `SQLSTATE[HY000]: 1419` while creating triggers
  - The Docker MySQL service is configured with `--log-bin-trust-function-creators=1`.
  - Recreate MySQL service so the option is applied, then rerun migrations.

## Rollback

Rollback to previous release:

```bash
cd /var/www/dms/repo
bash deploy/scripts/rollback.sh
```

Rollback to a specific release folder name:

```bash
APP_BASE=/var/www/dms \
bash deploy/scripts/rollback.sh 20260313_171500
```

## Smoke Check Only

```bash
bash deploy/scripts/smoke-check.sh /var/www/dms/current/backend
```

## Notes

- Keep `APP_DEBUG=false` in production.
- Keep queue/scheduler always running via Supervisor.
- Ensure `/var/www/dms/shared/storage` is writable by your web user.
- Run backups for DB and shared storage on schedule.

## GitHub Actions Integration

This repo includes a CI/CD workflow at `.github/workflows/ci-cd.yml`.

- Pull request to `main`: runs Laravel backend tests.
- Push to `main`: runs tests, then deploys over SSH.
  - `DEPLOY_MODE=release` (default): runs `deploy/scripts/deploy.sh`
  - `DEPLOY_MODE=docker`: runs `deploy/scripts/docker-deploy.sh`

Set these repository secrets in GitHub:

- Required: `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`
- Optional: `DEPLOY_PORT`, `DEPLOY_REPO_DIR`, `DEPLOY_APP_BASE`, `DEPLOY_MODE`, `DEPLOY_GITHUB_REPO`
