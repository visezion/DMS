# DMS

Centralized Windows Device Management System (agent-based, Option A).

## Table of Contents
- [Workspace Layout](#workspace-layout)
- [System Architecture](#system-architecture)
- [Windows Agent Internal Architecture](#windows-agent-internal-architecture)
- [Documentation](#documentation)
- [Admin Console Overview (Current)](#admin-console-overview-current)
- [Quick Start: Backend](#quick-start-backend)
- [Quick Start: Agent](#quick-start-agent)
- [Production Deployment](#production-deployment)
- [GitHub Actions CI/CD](#github-actions-cicd)
- [Security Notes](#security-notes)

## Workspace Layout
- `backend/`: Laravel 11 API-first backend with RBAC, audit logging, enrollment, check-in, jobs, policies, and packages.
- `agent/`: .NET 8 Windows Service agent scaffold with job handlers and replay protection.
- `docs/`: Architecture docs, implementation plans, and operational runbooks.

## System Architecture

<details>
<summary>View diagram</summary>

```text
+-----------------------------------------------------------------------------------+
|                                 ENDIVEX PLATFORM                                  |
+-----------------------------------------------------------------------------------+

          ADMINS / SECURITY TEAM
                   |
                   v
+-----------------------------------+
|        Admin Web Console          |
| Laravel UI                        |
| - Dashboard                       |
| - Devices                         |
| - Groups                          |
| - Policies                        |
| - Packages                        |
| - Jobs                            |
| - Audit Logs                      |
| - AI / Behavior Center            |
| - Settings                        |
+-----------------------------------+
                   |
                   v
+-----------------------------------------------------------------------------------+
|                                Laravel Backend API                                |
|-----------------------------------------------------------------------------------|
| Auth & RBAC | Device API | Policy Engine | Job Dispatcher | Package Service       |
| Compliance Engine | Audit Service | Signing Service | Behavior API | Reporting    |
+-----------------------------------------------------------------------------------+
          |                    |                     |                     |
          |                    |                     |                     |
          v                    v                     v                     v
+----------------+   +-------------------+   +------------------+   +----------------+
| MySQL Database |   | File / Package    |   | Signing Keys     |   | AI Runtime      |
|                |   | Storage           |   | and Rotation     |   | Behavior Models |
| - devices      |   | - agent releases  |   | - active key     |   | - train/retrain |
| - jobs         |   | - installers      |   | - previous keys  |   | - inference     |
| - policies     |   | - packages        |   | - key history    |   | - recommendations |
| - assignments  |   | - metadata        |   |                  |   |                 |
| - compliance   |   +-------------------+   +------------------+   +----------------+
| - audit logs   |
+----------------+
                   |
                   v
+-----------------------------------------------------------------------------------+
|                           Secure Command and Policy Channel                        |
|-----------------------------------------------------------------------------------|
| Enrollment | Check-in | Job Ack | Job Result | Compliance Report | Package Fetch   |
| Signed Payloads | TTL | Hash Validation | Retry | Backoff | Audit Trail           |
+-----------------------------------------------------------------------------------+
                   |
                   v
+-----------------------------------+     +-----------------------------------+
|      Windows Agent on Device      |     |      Windows Agent on Device      |
| .NET service                      |     | .NET service                      |
| - enrollment                      |     | - enrollment                      |
| - check-in                        |     | - check-in                        |
| - policy apply/remove             |     | - policy apply/remove             |
| - software install/uninstall      |     | - software install/uninstall      |
| - command execution               |     | - command execution               |
| - telemetry                       |     | - telemetry                       |
| - compliance scan                 |     | - compliance scan                 |
+-----------------------------------+     +-----------------------------------+
          |                                           |
          v                                           v
+-----------------------------+            +-----------------------------+
| Windows OS Controls         |            | Windows OS Controls         |
| - Registry                  |            | - Registry                  |
| - Firewall                  |            | - Firewall                  |
| - BitLocker                 |            | - BitLocker                 |
| - Local Groups              |            | - Local Groups              |
| - Scheduled Tasks           |            | - Scheduled Tasks           |
| - UWF                       |            | - UWF                       |
| - DNS / IP Settings         |            | - DNS / IP Settings         |
+-----------------------------+            +-----------------------------+
```

</details>

## Windows Agent Internal Architecture

<details>
<summary>View diagram</summary>

```text
+----------------------------------------------------------------------------+
|                         ENDIVEX WINDOWS AGENT                              |
+----------------------------------------------------------------------------+
| Service Host                                                               |
| Runs as Windows service                                                    |
+----------------------------------------------------------------------------+
        |              |                |                |               |
        v              v                v                v               v
+---------------+ +-------------+ +--------------+ +-------------+ +---------------+
| Enrollment    | | Check-in    | | Job Runner   | | Compliance  | | Telemetry     |
| Module        | | Module      | |              | | Scanner     | | Collector     |
| - token use   | | - heartbeat | | - command    | | - registry  | | - process data|
| - device bind | | - status    | | - package    | | - firewall  | | - events      |
| - re-enroll   | | - receive   | | - policy     | | - groups    | | - AI feed     |
+---------------+ +-------------+ +--------------+ +-------------+ +---------------+
        |                               |
        |                               v
        |                     +----------------------+
        |                     | Policy Executors     |
        |                     | - registry           |
        |                     | - firewall           |
        |                     | - bitlocker          |
        |                     | - local_group        |
        |                     | - scheduled_task     |
        |                     | - command            |
        |                     | - baseline_profile   |
        |                     | - uwf                |
        |                     | - dns                |
        |                     | - network            |
        |                     +----------------------+
        |
        v
+----------------------------------------------------------------------------+
| Security Layer                                                             |
| - signature verification                                                   |
| - payload hash verification                                                |
| - TTL / expiry check                                                       |
| - allowlist / bypass controls                                              |
+----------------------------------------------------------------------------+
        |
        v
+----------------------------------------------------------------------------+
| Local State and Logs                                                       |
| - job cache                                                                |
| - pending results                                                          |
| - retry queue                                                              |
| - compliance snapshots                                                     |
| - agent logs                                                               |
+----------------------------------------------------------------------------+
```

</details>

## Documentation
- Function usage guide: [docs/FUNCTIONS_GUIDE.md](docs/FUNCTIONS_GUIDE.md)
- Documentation update policy: [docs/DOCS_MAINTENANCE_POLICY.md](docs/DOCS_MAINTENANCE_POLICY.md)
- Architecture: [docs/architecture/architecture.md](docs/architecture/architecture.md)
- Operations runbook: [docs/runbooks/operations.md](docs/runbooks/operations.md)

## Admin Console Overview (Current)

Main entry points:
- `Enroll Devices`
- `Overview` (operations dashboard)
- `Devices`, `Groups`, `Jobs`
- `Application Management` -> `Software Packages`
- `Policy Center` -> `Policies`, `Policy Catalog`, `Policy Categories`
- `Deployment Center` -> `Agent Delivery`, `IP Deployment`
- `Settings`, `Access Control`, `Docs`, `Audit Logs`

Enrollment flow:
1. Generate an enrollment token from enrollment/settings pages.
2. Run the installer script on the target Windows endpoint (PowerShell as Administrator).
3. Confirm the device appears under `Devices` after successful enrollment and check-in.

Policy workflow:
- Policies support both apply and remove behavior.
- New versions can be published with apply mode (`json`/`command`) and remove mode (`auto`/`json`/`command`).
- When remove mode is `json` or `command`, operator inputs are shown.
- Policy category fields are sourced from the policy category list.

Operations controls:
- Runtime controls (`Kill Switch`, retries, backoff, allowed script hashes) are managed from `Settings`.
- `Kill Switch` enabled means command dispatch is paused globally.

## Quick Start: Backend

1. Enter backend directory:

```powershell
cd backend
```

2. Copy environment file:

```powershell
copy .env.example .env
```

```bash
cp .env.example .env
```

3. Set `APP_TIMEZONE` in `.env` (recommended: `UTC` for servers).
4. Run setup and start server:

```powershell
php artisan key:generate
php artisan migrate --seed
php artisan test
php artisan serve
```

## Quick Start: Agent

1. Install .NET 8 SDK on a Windows host.
2. Build and test:

```powershell
cd agent
dotnet build Dms.Agent.sln
dotnet test Dms.Agent.sln
```

## Production Deployment

Use the deployment kit in [`deploy/`](deploy/README.md) for both release-based and Docker deployment.

- `deploy/scripts/deploy.sh`: build, migrate, cache, switch release symlink, and smoke check.
- `deploy/scripts/rollback.sh`: fast rollback to previous release.
- `deploy/scripts/smoke-check.sh`: health checks for DB/app endpoint/write paths.
- `deploy/scripts/docker-deploy.sh`: one-command Docker deploy/update from GitHub.
- `deploy/scripts/bootstrap-docker-from-github.sh`: first-time Docker bootstrap from GitHub.
- `deploy/nginx/dms.conf`: Nginx site template.
- `deploy/supervisor/*.conf`: queue and scheduler process templates.
- `deploy/docker/docker-compose.prod.yml`: Docker production stack.

Production env template:

- [`backend/.env.production.example`](backend/.env.production.example)

One-command Docker bootstrap example:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/<your-org>/<your-repo>/main/deploy/scripts/bootstrap-docker-from-github.sh) https://github.com/<your-org>/<your-repo>.git main /opt/dms
```

## GitHub Actions CI/CD

Workflow file:

- [`.github/workflows/ci-cd.yml`](.github/workflows/ci-cd.yml)

Behavior:

- On pull requests to `main`: run backend tests.
- On push to `main`: run backend tests, then deploy through SSH if tests pass.
  - `DEPLOY_MODE=release` (default): release/symlink deployment.
  - `DEPLOY_MODE=docker`: Docker deployment (`deploy/scripts/docker-deploy.sh`).

Required GitHub repository secrets:

- `DEPLOY_HOST`: target server host or IP.
- `DEPLOY_USER`: SSH user on target server.
- `DEPLOY_SSH_KEY`: private key used by GitHub Actions.

Optional GitHub repository secrets:

- `DEPLOY_PORT` (default `22`)
- `DEPLOY_REPO_DIR` (default `/var/www/dms/repo`)
- `DEPLOY_APP_BASE` (default `/var/www/dms`)
- `DEPLOY_MODE` (`release` or `docker`)
- `DEPLOY_GITHUB_REPO` (override GitHub repo URL used by Docker deploy)

## Security Notes
- Backend signs check-in command envelopes with Ed25519 and publishes a rotation-ready keyset at `/api/v1/device/keyset`.
- Rotate signing keys with `php artisan dms:keys:rotate`.
- Agent verification path is wired for Ed25519 and replay protection.
