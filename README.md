# DMS

Centralized Windows Device Management System (agent-based, Option A).

## Repositories in this workspace
- `backend/`: Laravel 11 API-first backend with RBAC, audit logging, enrollment, check-in, jobs, policies, packages.
- `agent/`: .NET 8 Windows Service agent scaffold with job handlers and replay protection.
- `docs/`: Architecture, stage-by-stage implementation plan, runbooks.

System Architecture Diagram
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
|                |   | Storage           |   | and Rotation     |   | Behavior Models  |
| - devices      |   | - agent releases  |   | - active key     |   | - train/retrain  |
| - jobs         |   | - installers      |   | - previous keys  |   | - inference      |
| - policies     |   | - packages        |   | - key history    |   | - recommendations|
| - assignments  |   | - metadata        |   |                  |   |                  |
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



Windows Agent Internal Architecture
+----------------------------------------------------------------------------+
|                         ENDIVEX WINDOWS AGENT                              |
+----------------------------------------------------------------------------+
| Service Host                                                               |
| Runs as Windows service                                                    |
+----------------------------------------------------------------------------+
        |              |                |                |               |
        v              v                v                v               v
+---------------+ +-------------+ +--------------+ +-------------+ +---------------+
| Enrollment    | | Check-in    | | Job Runner   | | Compliance  | | Telemetry      |
| Module        | | Module      | |              | | Scanner     | | Collector      |
| - token use   | | - heartbeat | | - command    | | - registry  | | - process data |
| - device bind | | - status    | | - package    | | - firewall  | | - events       |
| - re-enroll   | | - receive   | | - policy     | | - groups    | | - AI feed      |
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
