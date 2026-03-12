# DMS Function Guide

Complete functional inventory for the DMS workspace.

Last updated: 2026-03-12

## Scope
This guide covers:
- Laravel web/admin functions (Blade UI routes)
- Laravel API functions (`/api/v1`)
- Console/Artisan functions
- Backend operational scripts (`backend/scripts`)
- Agent runtime functions and supported job handlers

## 1. Web/Admin Functions
Source: `backend/routes/web.php`

### 1.1 Public and Guest Auth
- `GET /` -> welcome page
- `GET /login` -> redirect to `/admin/login`
- `GET /admin/login` -> admin login form
- `POST /admin/login` -> authenticate admin
- `GET /admin/login/captcha-refresh` -> refresh captcha challenge
- `GET /admin/login/mfa` -> MFA challenge form
- `POST /admin/login/mfa` -> verify MFA code
- `POST /admin/login/mfa/cancel` -> cancel MFA flow

### 1.2 Admin Session
- `POST /admin/logout` -> terminate admin session

### 1.3 Dashboard
- `GET /admin/` -> operations dashboard

### 1.4 Devices
- `GET /admin/devices` -> list devices
- `GET /admin/enroll-devices` -> enrollment workflow page
- `GET /admin/devices/{deviceId}` -> device detail
- `GET /admin/devices/{deviceId}/live` -> live device detail panel
- `PATCH /admin/devices/{deviceId}` -> update device metadata
- `DELETE /admin/devices/{deviceId}` -> soft delete device
- `POST /admin/devices/{deviceId}/force-delete` -> permanent delete
- `DELETE /admin/devices/{deviceId}/policy-assignments/{assignmentId}` -> remove device policy assignment
- `POST /admin/devices/{deviceId}/packages/uninstall` -> queue package uninstall
- `POST /admin/devices/{deviceId}/agent/uninstall` -> queue agent uninstall
- `POST /admin/devices/{deviceId}/reboot` -> queue reboot action
- `POST /admin/devices/{deviceId}/reenroll` -> regenerate enrollment identity/token
- `POST /admin/devices/enrollment-token` -> create enrollment token

### 1.5 Groups
- `GET /admin/groups` -> list groups
- `GET /admin/groups/{groupId}` -> group detail
- `POST /admin/groups` -> create group
- `DELETE /admin/groups/{groupId}` -> delete group
- `POST /admin/groups/bulk-assign` -> bulk assign devices to group
- `POST /admin/groups/{groupId}/members` -> add member
- `DELETE /admin/groups/{groupId}/members/{deviceId}` -> remove member
- `POST /admin/groups/{groupId}/kiosk-lockdown` -> apply kiosk lockdown profile
- `POST /admin/groups/{groupId}/policy-assignments` -> assign policy to group
- `DELETE /admin/groups/{groupId}/policy-assignments/{assignmentId}` -> remove group policy assignment
- `POST /admin/groups/{groupId}/package-assignments` -> assign/deploy package to group
- `DELETE /admin/groups/{groupId}/package-assignments/{jobId}` -> remove package assignment job

### 1.6 Packages
- `GET /admin/packages` -> list packages
- `GET /admin/packages/icon/windows-store` -> resolve package icon from Windows Store metadata
- `POST /admin/packages/hash-from-uri` -> derive SHA256 from package URL
- `GET /admin/packages/{packageId}` -> package detail
- `POST /admin/packages` -> create package
- `POST /admin/packages/{packageId}/versions` -> create package version
- `DELETE /admin/packages/{packageId}` -> delete package
- `DELETE /admin/packages/{packageId}/versions/{versionId}` -> delete package version
- `POST /admin/packages/versions/{versionId}/deploy` -> deploy version (queue jobs)

### 1.7 Policies and Catalog
- `GET /admin/policies` -> list policies
- `GET /admin/policy-categories` -> category management page
- `GET /admin/policies/{policyId}` -> policy detail
- `POST /admin/policies` -> create policy
- `GET /admin/catalog` -> policy catalog presets
- `POST /admin/policies/catalog` -> create preset
- `PATCH /admin/policies/catalog/{catalogKey}` -> update preset
- `DELETE /admin/policies/catalog/{catalogKey}` -> delete preset
- `POST /admin/policies/categories` -> create category
- `PATCH /admin/policies/categories` -> update category
- `DELETE /admin/policies/categories` -> delete category
- `PATCH /admin/policies/{policyId}` -> update policy
- `DELETE /admin/policies/{policyId}` -> delete policy
- `POST /admin/policies/{policyId}/versions` -> create policy version
- `PATCH /admin/policies/{policyId}/versions/{versionId}` -> update policy version
- `DELETE /admin/policies/{policyId}/versions/{versionId}` -> delete policy version
- `POST /admin/policies/{policyId}/versions/{versionId}/assignments` -> assign policy version
- `DELETE /admin/policies/{policyId}/versions/{versionId}/assignments/{assignmentId}` -> remove policy assignment

Supported policy rule types in current controller validation:
- `firewall`
- `dns`
- `network_adapter`
- `registry`
- `bitlocker`
- `local_group`
- `windows_update`
- `scheduled_task`
- `command`
- `baseline_profile`
- `reboot_restore_mode`
- `uwf`

### 1.8 Jobs and Operations
- `GET /admin/jobs` -> list jobs
- `GET /admin/jobs/{jobId}` -> job detail
- `POST /admin/jobs` -> create/queue job
- `POST /admin/jobs/{jobId}/rerun` -> rerun whole job
- `POST /admin/job-runs/{runId}/rerun` -> rerun specific run
- `POST /admin/jobs/store-clear` -> snapshot and clear job history
- `POST /admin/ops/settings` -> update runtime controls
- `POST /admin/ops/rotate-signing-key` -> rotate command signing key

Supported UI job types in current validation:
- `install_package`
- `uninstall_package`
- `install_msi`
- `install_exe`
- `install_custom`
- `install_archive`
- `uninstall_msi`
- `uninstall_exe`
- `uninstall_archive`
- `apply_policy`
- `run_command`
- `create_snapshot`
- `restore_snapshot`
- `update_agent`
- `uninstall_agent`
- `reconcile_software_inventory`

### 1.9 Agent Delivery and IP Deployment
- `GET /admin/agent` -> agent delivery page
- `POST /admin/agent/releases` -> upload release artifact
- `POST /admin/agent/releases/autobuild` -> build release from repo
- `POST /admin/agent/releases/{releaseId}/activate` -> activate release
- `DELETE /admin/agent/releases/{releaseId}` -> delete release
- `POST /admin/agent/releases/generate` -> generate signed installer URL/script
- `POST /admin/agent/push-update` -> push update job
- `POST /admin/agent/test-connectivity` -> test API connectivity for installer path
- `POST /admin/agent/backend/start` -> start optional local backend helper
- `GET /admin/agent/backend/status` -> backend helper status
- `GET /admin/ip-deploy` -> IP deployment page
- `POST /admin/ip-deploy/run` -> execute deployment run

### 1.10 Docs, Notes, Profile, Settings, RBAC, Audit
- `GET /admin/getting-started` -> onboarding page
- `GET /admin/docs` -> docs page
- `GET /admin/notes` -> notes page
- `POST /admin/notes` -> create note
- `PATCH /admin/notes/{noteId}` -> update note
- `DELETE /admin/notes/{noteId}` -> delete note
- `GET /admin/profile` -> profile page
- `POST /admin/profile` -> update profile
- `POST /admin/profile/mfa/setup` -> generate MFA setup
- `POST /admin/profile/mfa/enable` -> enable MFA
- `POST /admin/profile/mfa/disable` -> disable MFA
- `GET /admin/settings` -> settings page
- `GET /admin/security-hardening` -> security command center
- `GET /admin/security-command-center` -> alias of security command center
- `GET /admin/settings/branding` -> branding settings page
- `POST /admin/settings/branding` -> update branding
- `POST /admin/settings/signature-bypass` -> update signature bypass policy
- `POST /admin/settings/auth-policy` -> update auth hardening policy
- `POST /admin/settings/https-app-url` -> update HTTPS application URL
- `POST /admin/settings/environment-posture` -> update environment posture
- `GET /admin/access` -> access control page
- `POST /admin/access/users` -> create staff user
- `POST /admin/access/roles` -> create role
- `PATCH /admin/access/roles/{roleId}/permissions` -> update role permissions
- `DELETE /admin/access/roles/{roleId}` -> delete role
- `PATCH /admin/access/users/{userId}/roles` -> assign user roles
- `GET /admin/audit` -> audit log page
- `GET /admin/behavior-ai` -> AI control center page for cases, runtime, and recommendations
- `POST /admin/behavior-ai/recommendations/{recommendationId}/review` -> approve or reject recommendation
- `POST /admin/behavior-ai/recommendations/approve-all-pending` -> approve all pending recommendations
- `POST /admin/behavior-ai/runtime/start` -> start behavior AI runtime helpers
- `GET /admin/behavior-ai/runtime/status` -> current behavior AI runtime status
- `POST /admin/behavior-ai/train-now` -> queue dataset backfill plus model training
- `POST /admin/behavior-ai/retrain` -> queue adaptive retraining from feedback
- `POST /admin/behavior-ai/replay` -> replay queued or failed stream events
- `GET /admin/behavior-alerts` -> full anomaly detection review page with filters and actions
- `POST /admin/behavior-alerts/{alertId}/confirm` -> confirm anomaly and allow policy/alert follow-up
- `POST /admin/behavior-alerts/{alertId}/dismiss` -> dismiss anomaly

### 1.11 Signed/External Downloads
- `GET /agent/releases/{releaseId}/download` -> signed agent release download
- `GET /agent/releases/{releaseId}/install-script` -> signed installer script
- `GET /agent/releases/{releaseId}/install-launcher` -> signed installer launcher
- `GET /packages/files/{packageFileId}/download` -> signed package file download
- `GET /packages/files/{packageFileId}/download-public` -> public package file download

## 2. API Functions (`/api/v1`)
Source: `backend/routes/api.php`

### 2.1 Device and Auth Public Endpoints
- `POST /api/v1/auth/login` -> issue Sanctum token
- `POST /api/v1/device/enroll` -> enroll endpoint and issue device identity
- `POST /api/v1/device/heartbeat` -> heartbeat/update liveness
- `POST /api/v1/device/checkin` -> poll for commands
- `GET /api/v1/device/keyset` -> fetch active + retiring signing keys
- `GET /api/v1/device/policies` -> fetch effective policies for device
- `POST /api/v1/device/job-ack` -> acknowledge command receipt
- `POST /api/v1/device/job-result` -> upload execution result
- `POST /api/v1/device/compliance-report` -> upload compliance report
- `POST /api/v1/device/behavior-log` -> ingest behavioral telemetry events (logon/app launch/file access)
- `GET /api/v1/device/packages/{packageVersionId}/download-meta` -> fetch package metadata (URI/hash/size)

### 2.2 Authenticated API Endpoints (`auth:sanctum`)
- `GET /api/v1/auth/me` -> token identity
- `POST /api/v1/auth/logout` -> revoke current token

### 2.3 Authenticated Admin API (`/api/v1/admin`)
- Devices:
  - `GET /devices`
  - `GET /devices/{id}`
  - `PATCH /devices/{id}`
  - `POST /enrollment-tokens`
- Groups:
  - `GET /groups`
  - `POST /groups`
- Packages:
  - `GET /packages`
  - `POST /packages`
  - `POST /packages/{packageId}/versions`
- Policies:
  - `GET /policies`
  - `POST /policies`
  - `POST /policies/{policyId}/versions`
- Jobs:
  - `GET /jobs`
  - `POST /jobs`
- Audit:
  - `GET /audit-logs`

Each endpoint is permission-gated with middleware such as:
- `permission:devices.read`
- `permission:devices.write`
- `permission:groups.read`
- `permission:groups.write`
- `permission:packages.read`
- `permission:packages.write`
- `permission:policies.read`
- `permission:policies.write`
- `permission:jobs.read`
- `permission:jobs.write`
- `permission:audit.read`

## 3. Console/Artisan Functions
Source: `backend/routes/console.php`

- `php artisan inspire`
  - Prints an inspiring quote.
- `php artisan dms:keys:rotate {kid?}`
  - Rotates/activates the command-signing key.
- `php artisan dms:behavior:detect --minutes=60`
  - Queues rule-based behavior anomaly detection.
- `php artisan dms:behavior:dataset:backfill --days=30`
  - Rebuilds local AI training dataset JSONL from stored behavior logs.
- `php artisan dms:behavior:train-ai --days=30 --min-events=200`
  - Trains AI anomaly model from behavior dataset and saves model artifact.

## 4. Backend Script Functions
Source folder: `backend/scripts`

### 4.1 Backup/Restore and Build
- `backup.ps1` -> create backend backup archive
- `restore.ps1` -> restore from backup archive
- `build-agent.ps1` -> build/package agent artifacts
- `bootstrap-enable-remote-deploy.ps1` -> configure Windows host for remote deployment prerequisites

### 4.2 Deployment Automation
- `install-agent-by-ip.ps1` -> install agent over WinRM/session-based remote execution
- `install-agent-by-psexec.ps1` -> install agent via PsExec workflow
- `install-agent-by-smb-rpc.ps1` -> install agent over SMB + remote service control
- `install-agent-by-smb-rpc-gui.ps1` -> GUI wrapper for SMB/RPC deploy script

### 4.3 Debug/Utility Scripts
- `e2e-device-test.php` -> end-to-end device API flow checks
- `queue-one-run.php` -> enqueue one run for test/debug
- `queue-debug-runs.php` -> enqueue debug runs
- `requeue-run.php` -> requeue failed/pending run
- `inspect-run.php` -> inspect run payload/status
- `inspect-run-eloquent.php` -> inspect run via Eloquent
- `find-device-assignment.php` -> inspect policy/package assignment state
- `print-php-canonical.php` -> canonical JSON/hash diagnostic helper

### 4.4 Sample Payload Files
- `command-sample.json`
- `command-sample-new.json`
- `keyset-sample.json`
- `last-checkin.json`

## 5. Agent Runtime Functions
Primary sources:
- `agent/src/Dms.Agent.Service/Worker.cs`
- `agent/src/Dms.Agent.Core/Jobs/JobProcessor.cs`
- `agent/src/Dms.Agent.Core/Jobs/Handlers/Handlers.cs`
- `agent/src/Dms.Agent.Core/Transport/*`

### 5.1 Core Runtime
- Windows service worker loop for periodic check-in and execution
- Device enrollment bootstrap and identity persistence
- Signed command verification with replay protection
- ACK/result transport back to backend
- Compliance and inventory collection helpers

### 5.2 Supported Agent Job Handlers (active in JobProcessor)
- `install_package`
- `uninstall_package`
- `install_msi`
- `uninstall_msi`
- `install_exe`
- `uninstall_exe`
- `install_custom`
- `install_archive`
- `uninstall_archive`
- `reconcile_software_inventory`
- `apply_policy`
- `run_command`
- `create_snapshot`
- `restore_snapshot`
- `update_agent`
- `uninstall_agent`

## 6. Function Ownership Map
- Web/UI controllers:
  - `backend/app/Http/Controllers/Web/AdminConsoleController.php`
  - `backend/app/Http/Controllers/Web/AdminAuthController.php`
- API controllers:
  - `backend/app/Http/Controllers/Api/V1/*`
- API route registry:
  - `backend/routes/api.php`
- Web route registry:
  - `backend/routes/web.php`
- Console route registry:
  - `backend/routes/console.php`
- Agent command execution:
  - `agent/src/Dms.Agent.Core/Jobs/JobProcessor.cs`
  - `agent/src/Dms.Agent.Core/Jobs/Handlers/Handlers.cs`

## 7. Update Rule
When adding or changing any route/job type/script, update this file in the same PR so the functional inventory stays accurate.

## 8. AI Anomaly Detection Ops
- Detection modes:
  - `rule` (default): z-score login-time rules.
  - `ai`: model scoring from trained behavior model.
- Rule-based detectors currently include:
  - unusual login-time z-score
  - off-hours login anomaly
  - rare app launch (user/device baseline)
  - suspicious process launch indicators
  - sensitive file access pattern detection
  - file-access burst anomaly
- Admin toggle:
  - `Settings` -> `Operations Controls` -> `Behavior Detection Mode`.
- Required training flow:
  1. Build dataset: `php artisan dms:behavior:dataset:backfill --days=30`
  2. Train model: `php artisan dms:behavior:train-ai --days=30 --min-events=200`
  3. Switch mode to AI in Admin Settings.
- Periodic retraining recommendation:
  - Weekly for stable fleets.
  - Daily for rapidly changing endpoint behavior.
  - Run with scheduler/Horizon worker active so classification jobs continue uninterrupted.

## 9. How Core Modules Work (Examples)

### 9.1 Overview Dashboard
- Use it to see fleet health, rollout risk, and security posture first.
- Example:
  - A policy rollout creates failed jobs.
  - Open `Overview`, identify the spike, then move into `Jobs` or `Policies` to fix or remove the bad assignment.

### 9.2 Enroll Devices
- Generate an enrollment token, run the installer on Windows as Administrator, then verify the agent appears in `Devices`.
- Example:
  - Onboard `LAB-PC-22`.
  - Generate the install command in `Enroll Devices`, run it on the device, then add the device to `Student Lab`.

### 9.3 Devices
- Use `Devices` and `Device Detail` to inspect inventory, network state, assignments, and one-off actions.
- Example:
  - A laptop is misbehaving after a rollout.
  - Open its detail page, review assignments, then reboot or remove the bad assignment only for that device.

### 9.4 Groups
- Groups are the main targeting layer for member devices, policy versions, and package deployments.
- Example:
  - Create `Student Lab - Floor 2`.
  - Add classroom devices, attach the kiosk lockdown bundle, then assign the required packages once for the whole lab.

### 9.5 Packages
- Create a package shell first, then create versions with detection rules and deploy those versions to devices or groups.
- Example:
  - Create `Notepad++`, add version `8.9.2`, then deploy it to the `Accounting` group from `Package Detail`.

### 9.6 Policies
- Policies are versioned rules that support assignment and cleanup.
- Example:
  - Assign `Disable Control Panel` to `Student Lab`.
  - When you remove the policy assignment, cleanup restores the Windows default behavior.

### 9.7 Network Policies
- Use `dns` and `network_adapter` rules for guided DNS and IPv4 management instead of raw command payloads.
- Example:
  - Set branch office DNS servers on the `Ethernet` adapter.
  - Later remove the policy so the adapter returns to automatic DNS or DHCP.

### 9.8 Jobs
- Every remote action becomes a job and device runs with statuses such as pending, running, acked, completed, or failed.
- Example:
  - Queue `run_command` with `hostname` against one endpoint.
  - Confirm the device ACKs the job and posts the final result.

### 9.9 Agent Delivery and IP Deployment
- Agent Delivery manages releases and updates; IP Deployment helps push installs to known remote hosts.
- Example:
  - Upload or autobuild release `1.3.0`, activate it, then push an update to a pilot group.

### 9.10 Behavior AI
- Behavior AI turns endpoint behavior logs into cases, recommendations, and model training workflows.
- Example:
  - A suspicious off-hours tool launch creates a case.
  - Review the case, approve the recommendation if valid, or dismiss it if it is expected activity.

### 9.11 Settings, Access, and Audit
- Settings define global safety rails, Access Control limits who can change what, and Audit Logs record important actions.
- Example:
  - Enable kill switch before maintenance, create a read-only helpdesk role, then verify later in Audit Logs who changed a policy.
