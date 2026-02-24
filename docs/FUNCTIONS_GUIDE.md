# DMS Function Guide

This is the operational reference for using every major function in this project.

Last updated: 2026-02-20

## 1. Admin Console Functions (Blade UI)

### 1.1 Overview (`/admin`)
- Purpose: global health, operations controls, security controls.
- Functions:
  - View metrics:
    - devices total/online/enrolled
    - pending/failed jobs
    - retry queue depth
    - compliance rate
    - job success rate
    - replay rejects
    - last key rotation
  - Update runtime controls:
    - kill switch
    - max retries
    - base backoff seconds
    - allowed script SHA256 allowlist
  - Rotate command signing key.
- How to use:
  1. Open `Overview`.
  2. Change controls in `Operations Controls`.
  3. Click `Save Ops Settings`.
  4. For key rotation click `Rotate Signing Key`.

### 1.2 Devices (`/admin/devices`)
- Purpose: enrollment and per-device management.
- Functions:
  - Create one-time enrollment token.
  - View device status, last check-in, retry/failed counts.
  - Update status and MeshCentral device ID.
  - Re-enroll a device (revokes old identity, creates new token).
  - Open device details page.
- How to use:
  1. Generate token from `Create Enrollment Token`.
  2. Install agent on client using Agent Delivery script.
  3. Use `Re-enroll` if identity reset is needed.

### 1.3 Device Detail (`/admin/devices/{deviceId}`)
- Purpose: single-device drilldown.
- Functions:
  - View recent job runs and retry state.
  - View compliance history.
  - View policy assignments.
  - View audit events for the device.

### 1.4 Groups (`/admin/groups`)
- Purpose: target management.
- Functions:
  - Create group.
  - Assign members during group creation.
  - Bulk assign devices to an existing group.
- How to use:
  1. Create a group.
  2. Use `Bulk Assign Devices to Group` for fleet operations.

### 1.5 Packages (`/admin/packages`)
- Purpose: software catalog and versions.
- Functions:
  - Create package metadata.
  - Add package versions.
  - Add package file metadata (hash, size, URI) through version creation flow.

### 1.6 Policies (`/admin/policies`)
- Purpose: create and publish enforceable policies.
- Functions:
  - Create policy definition.
  - Publish policy version with rule JSON.
  - Assign published version to device/group.
  - Auto-queue `apply_policy` jobs for assigned targets.
- Supported rule types:
  - `firewall`
  - `registry`
  - `bitlocker`
  - `local_group`
  - `windows_update`
  - `scheduled_task` (model supported; enforcement can be extended)

### 1.7 Jobs (`/admin/jobs`)
- Purpose: command dispatch center.
- Functions:
  - Queue jobs:
    - `install_package`
    - `uninstall_package`
    - `install_msi`
    - `install_exe`
    - `apply_policy`
    - `run_command` (hash-allowlist gated)
  - Choose target type: `device` or `group`.
  - Set stagger rollout (`stagger_seconds`) for group fan-out.
  - View runtime controls summary.

### 1.8 Agent Delivery (`/admin/agent`)
- Purpose: release lifecycle + client install bootstrap.
- Functions:
  - Auto-build agent release.
  - Upload release artifact.
  - Activate release.
  - Delete inactive release.
  - Generate signed install script URL and one-liner.
  - Copy one-liner for client installation.
  - Test API connectivity.
- Important:
  - Do not use `localhost` in install links for remote clients.
  - Use LAN IP or DNS hostname reachable by clients.

### 1.9 Audit (`/admin/audit`)
- Purpose: immutable audit visibility.
- Functions:
  - View action/event stream.
  - Review actor user/device and entity changes.

## 2. Device/API Functions

Base path: `/api/v1`

### 2.1 Enrollment
- Endpoint: `POST /device/enroll`
- Purpose: register a new device with one-time token.
- Required fields:
  - `enrollment_token`
  - `device_facts.hostname`
  - `device_facts.os_name`
  - `device_facts.agent_version`
- Returns:
  - `device_id`
  - identity metadata
  - bootstrap timing hints

### 2.2 Heartbeat
- Endpoint: `POST /device/heartbeat`
- Purpose: mark device online/update version and last seen.

### 2.3 Check-in (command polling)
- Endpoint: `POST /device/checkin`
- Purpose: fetch signed command envelopes.
- Behavior:
  - respects kill switch
  - returns pending commands in deterministic order
  - filters jobs by retry schedule (`next_retry_at`)

### 2.4 Keyset retrieval
- Endpoint: `GET /device/keyset`
- Purpose: retrieve active/retiring Ed25519 public keys for command verification.

### 2.5 Policy fetch
- Endpoint: `GET /device/policies?device_id=...`
- Purpose: fetch active policies assigned directly to device or via group.

### 2.6 Job ack
- Endpoint: `POST /device/job-ack`
- Purpose: mark command receipt.

### 2.7 Job result
- Endpoint: `POST /device/job-result`
- Purpose: report execution status and payload.
- Supported statuses:
  - `success`
  - `failed`
  - `running`
  - `non_compliant`
- Behavior:
  - failed jobs can auto-schedule retry using ops settings.

### 2.8 Compliance report
- Endpoint: `POST /device/compliance-report`
- Purpose: explicit compliance upload from agent.

### 2.9 Package metadata
- Endpoint: `GET /device/packages/{packageVersionId}/download-meta`
- Purpose: provide download URI/hash/size metadata.

## 3. Agent Runtime Functions

### 3.1 Enrollment bootstrap
- Reads from:
  - `DMS_ENROLLMENT_TOKEN` or `C:\ProgramData\DMS\enrollment-token.txt`
  - `DMS_API_BASE_URL` or `C:\ProgramData\DMS\api-base-url.txt`
- Persists:
  - `DMS_DEVICE_ID` and `C:\ProgramData\DMS\device-id.txt`

### 3.2 Command verification
- Verifies:
  - schema
  - expiry
  - payload hash
  - Ed25519 signature (`kid` from keyset)
  - replay protection (command id + nonce + sequence)

### 3.3 Job handlers
- `install_package`: winget install + optional detection evaluation.
- `uninstall_package`: winget uninstall.
- `install_msi`: msiexec silent + logging.
- `install_exe`: silent args execution.
- `apply_policy`: applies supported policy rules and reports compliance state.
- `run_command`: disabled by default unless enabled + allowlisted hash.

### 3.4 `run_command` guardrails
- Must set:
  - `DMS_RUN_COMMAND_ENABLED=true`
  - `DMS_ALLOWED_SCRIPT_SHA256=<hash1,hash2,...>`
- Payload must include:
  - `script`
  - `script_sha256`

## 4. Security/Operations Functions

### 4.1 Kill switch
- Location: Overview -> Operations Controls
- Effect: check-ins continue, command dispatch pauses.

### 4.2 Retry policy
- Settings:
  - `max_retries`
  - `base_backoff_seconds`
- Behavior: exponential backoff, capped server-side.

### 4.3 Signing key rotation
- Action: Overview -> Rotate Signing Key
- Effect:
  - new key active
  - prior key transitions to retiring
  - keyset publishes both active + retiring window

### 4.4 Backup and restore
- Backup:
  - `powershell -ExecutionPolicy Bypass -File backend/scripts/backup.ps1`
- Restore:
  - `powershell -ExecutionPolicy Bypass -File backend/scripts/restore.ps1 -BackupZip <zip>`

## 5. Common Workflows

### 5.1 Onboard a new device
1. Build/activate a release in Agent Delivery.
2. Generate install script URL.
3. Run one-liner as Administrator on client.
4. Confirm service running: `sc.exe query DMSAgent`.
5. Verify device appears in Devices page.

### 5.2 Roll out a package to a group
1. Create/verify group membership.
2. Create package + version metadata.
3. Queue `install_package` job with group target.
4. Set `stagger_seconds` for controlled rollout.
5. Monitor Jobs and device detail pages.

### 5.3 Enforce firewall policy fleet-wide
1. Create policy and publish version with firewall rule JSON.
2. Assign to target group.
3. Confirm `apply_policy` jobs were created.
4. Check compliance history on device detail.

### 5.4 Emergency response
1. Enable kill switch.
2. Rotate signing key.
3. Inspect audit and failed job/replay metrics.
4. Resume dispatch after validation.

## 6. Source Map

Backend:
- Controller logic: `backend/app/Http/Controllers/Web/AdminConsoleController.php`
- Device API: `backend/app/Http/Controllers/Api/V1/DeviceCheckinController.php`
- Routes:
  - `backend/routes/web.php`
  - `backend/routes/api.php`

Agent:
- Worker loop: `agent/src/Dms.Agent.Service/Worker.cs`
- API transport: `agent/src/Dms.Agent.Core/Transport/ApiClient.cs`
- Handler execution: `agent/src/Dms.Agent.Core/Jobs/Handlers/Handlers.cs`

Ops:
- Backup script: `backend/scripts/backup.ps1`
- Restore script: `backend/scripts/restore.ps1`
