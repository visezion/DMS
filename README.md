# DMS

Centralized Windows Device Management System (agent-based, Option A).

## Repositories in this workspace
- `backend/`: Laravel 11 API-first backend with RBAC, audit logging, enrollment, check-in, jobs, policies, packages.
- `agent/`: .NET 8 Windows Service agent scaffold with job handlers and replay protection.
- `docs/`: Architecture, stage-by-stage implementation plan, runbooks.

## Documentation
- Full function usage guide: `docs/FUNCTIONS_GUIDE.md`
- Documentation update policy: `docs/DOCS_MAINTENANCE_POLICY.md`
- Architecture: `docs/architecture/architecture.md`
- Operations runbook: `docs/runbooks/operations.md`

## Admin Console Overview (Current)
- Main entry points:
  - `Enroll Devices`
  - `Overview` (operations dashboard)
  - `Devices`, `Groups`, `Jobs`
  - `Application Management` -> `Software Packages`
  - `Policy Center` -> `Policies`, `Policy Catalog`, `Policy Categories`
  - `Deployment Center` -> `Agent Delivery`, `IP Deployment`
  - `Settings`, `Access Control`, `Docs`, `Audit Logs`
- Enrollment flow:
  - Generate enrollment token from enrollment/settings pages.
  - Run installer script on target Windows endpoint (PowerShell as Administrator).
  - Device appears in `Devices` after successful enrollment and check-in.
- Policy workflow:
  - Policies support both apply and remove behavior.
  - New versions can be published with apply mode (`json`/`command`) and remove mode (`auto`/`json`/`command`).
  - When remove mode is set to `json` or `command`, relevant inputs are shown for operator entry.
  - Policy category fields use category dropdowns sourced from the policy category list.
- Operations controls location:
  - Runtime controls (`Kill Switch`, retries, backoff, allowed script hashes) are managed from `Settings`.
  - `Kill Switch` enabled = command dispatch paused globally.

## Quick start (backend)
1. `cd backend`
2. `copy .env.example .env`
   - Set `APP_TIMEZONE` in `.env` (recommended: `UTC` for servers)
3. `php artisan key:generate`
4. `php artisan migrate --seed`
5. `php artisan test`
6. `php artisan serve`

## Quick start (agent)
- Requires .NET 8 SDK on Windows host.
- `cd agent`
- `dotnet build Dms.Agent.sln`
- `dotnet test Dms.Agent.sln`

## Security note
- Backend now signs check-in command envelopes with Ed25519 and publishes a rotation-ready keyset at `/api/v1/device/keyset`.
- Rotate signing keys with `php artisan dms:keys:rotate`.
- Agent verification path is wired for Ed25519 + replay protection.
