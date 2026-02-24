# Operations Runbook

## Daily checks
- Queue depth and worker health.
- Device online/offline deltas.
- Failed job runs and top error codes.
- Time sanity:
  - `APP_TIMEZONE` is set in `backend/.env` (recommended: `UTC`).
  - `php artisan tinker --execute="echo now()->toIso8601String();"`
  - Verify server clock/NTP is in sync.

## Incident: package deployment failures spike
1. Pause new install jobs.
2. Inspect package hash and source URI integrity.
3. Verify winget source health and endpoint DNS/TLS.
4. Resume staged rollout after validation.

## Incident: suspected command replay/tampering
1. Disable command dispatch globally.
2. Rotate signing keys and publish new keyset.
3. Force agent keyset refresh on next check-in.
4. Investigate affected device IDs and command IDs.

## Backup and restore drill
1. Create backup:
   - `powershell -ExecutionPolicy Bypass -File backend/scripts/backup.ps1`
2. Restore to staging:
   - `powershell -ExecutionPolicy Bypass -File backend/scripts/restore.ps1 -BackupZip <path-to-zip>`
3. Run integrity checks on `audit_logs` row hash chain.
4. Validate API read path and latest job/compliance data.

## Kill switch
- Use `Overview -> Operations Controls -> Pause all command dispatch`.
- Agents continue heartbeat/reporting but receive no new commands.

## Retry and backoff
- Configure in `Overview -> Operations Controls`:
  - `Max retries`
  - `Base backoff (seconds)`
- Device page shows `Retry/PFail` to track pending retries and failed runs.

## Key rotation
1. Click `Overview -> Rotate Signing Key`.
2. Confirm new key appears in `GET /api/v1/device/keyset`.
3. Monitor `Replay Rejects` metric for anomalies.

## Fleet policy enforcement with minimal interruption
Goal: enforce a policy on all agents without causing broad endpoint disruption.

### 1) Pre-flight checks (must pass before rollout)
1. `Devices`:
   - Confirm target devices are online recently.
   - Confirm `Agent` and `Build` are consistent across rollout scope.
2. `Overview -> Operations Controls`:
   - `Pause all command dispatch` must be OFF.
   - Retry values set (`Max retries`, `Base backoff`).
3. `Jobs` / `Device Detail`:
   - No ongoing spike of `E_SIG_INVALID` or repeated `failed` runs.
4. Time sanity:
   - App timezone is expected and server time is correct.

### 2) Create rollout groups
1. Open `Groups`.
2. Create/verify these groups:
   - `Policy-Pilot` (5-10 representative devices)
   - `Policy-Wave1` (25-30%)
   - `Policy-Wave2` (remaining endpoints)
3. Ensure every device is in exactly one rollout wave group.

### 3) Create policy and publish version
1. Open `Policies`.
2. Create policy (or reuse existing policy).
3. Publish new version:
   - Use preset or custom rule JSON.
   - Keep a rollback version ready (inverse settings).
4. Do not assign to `all` at once; start with `Policy-Pilot`.

### 4) Pilot rollout
1. In policy version `Assignments`, add `target_type=group`, `target_id=Policy-Pilot`.
2. Keep `queue job now` enabled.
3. Wait at least one agent polling cycle plus retries.
4. Validate in `Jobs` and `Device Detail`:
   - No critical failures.
   - Compliance trend is improving.
   - No service-impacting behavior on pilot devices.

### 5) Go/no-go gate after pilot
Promote only if all are true:
1. No systemic signature or transport errors.
2. Failure rate is low and explainable.
3. Pilot endpoints remain usable.

If gate fails:
1. Enable kill switch (`Overview -> Pause all command dispatch`).
2. Remove or pause problematic assignment.
3. Publish/assign rollback policy version to pilot.
4. Re-test and only resume after stable results.

### 6) Wave rollout
1. Assign same policy version to `Policy-Wave1`.
2. Monitor until stable.
3. Assign to `Policy-Wave2`.
4. Keep observing:
   - failed run count
   - retry backlog
   - compliance status

### 7) Completion criteria
Rollout is complete when:
1. All rollout groups have assignment to intended policy version.
2. New `apply_policy` runs reach final states without systemic errors.
3. Endpoint usability is unaffected.
4. Compliance data converges to expected state.

### 8) Emergency rollback procedure
1. Toggle kill switch ON.
2. Publish/assign rollback policy version.
3. Queue apply jobs for affected groups.
4. Validate recovery on pilot subset.
5. Toggle kill switch OFF and continue staged recovery.

## Standalone install by IP (WinRM)
Use this only as a standalone utility. It does not change backend app logic.

- Script path: `backend/scripts/install-agent-by-ip.ps1`
- Target requirements:
  - WinRM reachable from admin machine
  - Local/domain admin credential
  - PowerShell enabled

### Single IP
```powershell
powershell -ExecutionPolicy Bypass -File backend\scripts\install-agent-by-ip.ps1 `
  -InstallScriptUrl "http://YOUR_SERVER/DMS/backend/public/agent/releases/<release-id>/install-script?..." `
  -TargetIp "172.16.43.55" `
  -Username "DOMAIN\Administrator" `
  -Password "YourPassword"
```

### Multiple IPs from file
Create a text file with one IP per line (`#` for comments), then:

```powershell
powershell -ExecutionPolicy Bypass -File backend\scripts\install-agent-by-ip.ps1 `
  -InstallScriptUrl "http://YOUR_SERVER/DMS/backend/public/agent/releases/<release-id>/install-script?..." `
  -TargetListPath "C:\temp\target-ips.txt" `
  -Username "DOMAIN\Administrator" `
  -Password "YourPassword"
```

### Dry run
```powershell
powershell -ExecutionPolicy Bypass -File backend\scripts\install-agent-by-ip.ps1 `
  -InstallScriptUrl "http://YOUR_SERVER/DMS/backend/public/agent/releases/<release-id>/install-script?..." `
  -TargetListPath "C:\temp\target-ips.txt" `
  -Username "DOMAIN\Administrator" `
  -Password "YourPassword" `
  -WhatIf
```

Result CSV is saved under:
- `backend/scripts/logs/install-agent-by-ip-<timestamp>.csv`

## Standalone install by SMB/RPC (when WinRM 5985 is closed)
Use this if:
- `5985` is closed
- `445` and `135` are open

Script path:
- `backend/scripts/install-agent-by-smb-rpc.ps1`

This script is standalone and removable. It does not change backend app logic.

### Single IP
```powershell
powershell -ExecutionPolicy Bypass -File backend\scripts\install-agent-by-smb-rpc.ps1 `
  -InstallScriptUrl "http://YOUR_SERVER/DMS/backend/public/agent/releases/<release-id>/install-script?expires=...&signature=..." `
  -TargetIp "172.16.155.165" `
  -Username "TARGET-PC\Administrator" `
  -Password "YourPassword"
```

### IP range (CIDR)
```powershell
powershell -ExecutionPolicy Bypass -File backend\scripts\install-agent-by-smb-rpc.ps1 `
  -InstallScriptUrl "http://YOUR_SERVER/DMS/backend/public/agent/releases/<release-id>/install-script?expires=...&signature=..." `
  -IpRangeCidr "172.16.155.0/24" `
  -Username "DOMAIN\IT-Deploy" `
  -Password "YourPassword"
```

### Dry run
```powershell
powershell -ExecutionPolicy Bypass -File backend\scripts\install-agent-by-smb-rpc.ps1 `
  -InstallScriptUrl "http://YOUR_SERVER/DMS/backend/public/agent/releases/<release-id>/install-script?expires=...&signature=..." `
  -TargetIp "172.16.155.165" `
  -Username "TARGET-PC\Administrator" `
  -Password "YourPassword" `
  -WhatIf
```

Result CSV is saved under:
- `backend/scripts/logs/install-agent-by-smb-rpc-<timestamp>.csv`

### GUI mode with live popup progress
Launch:

```powershell
powershell -ExecutionPolicy Bypass -File backend\scripts\install-agent-by-smb-rpc-gui.ps1
```

GUI shows:
- found targets
- processed targets
- installed / failed counters
- per-IP live log
- final popup summary with totals and report path
