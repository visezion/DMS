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
- `docker/docker-compose.prod.yml`: Docker production stack (nginx, app, queue, scheduler, mysql, redis).
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

- Docker Engine with Docker Compose plugin
- Git

For first-time install directly from GitHub on a clean server:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/<your-org>/<your-repo>/main/deploy/scripts/bootstrap-docker-from-github.sh) https://github.com/<your-org>/<your-repo>.git main /opt/dms
```

For updates after first install:

```bash
GITHUB_REPO=https://github.com/<your-org>/<your-repo>.git BRANCH=main APP_BASE=/opt/dms bash /opt/dms/repo/deploy/scripts/docker-deploy.sh
```

Docker mode files created on first run:

- `/opt/dms/shared/.env` (Laravel runtime env)
- `/opt/dms/shared/docker.env` (Docker compose env)

Edit both files for production secrets and ports, then re-run the same deploy command.

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
