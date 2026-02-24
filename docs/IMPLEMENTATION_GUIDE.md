# DMS Implementation Guide

## Project status in this repository
- Backend foundation implemented in `backend/`.
- Agent foundation implemented in `agent/`.
- This document provides Stage 0-10 plan, architecture, API contracts, protocol, security model, setup, and runbooks.
- Live operational usage is documented in `docs/FUNCTIONS_GUIDE.md`.
- Documentation governance is in `docs/DOCS_MAINTENANCE_POLICY.md`.

## 1) Project overview and scope boundaries

### In scope
- Target OS: Windows 10, Windows 11, Windows Server.
- Agent: .NET 8 Worker Service as Windows Service.
- Backend API: Laravel 11, API-first.
- DB: PostgreSQL preferred (SQLite for local tests).
- Queue: Redis + Horizon runtime model.
- Dashboard: Blade + Tailwind (API-first implementation in current code).
- Package deployment: MSI, EXE silent, winget, custom package metadata.
- Policy areas: registry, firewall, BitLocker checks, Windows Update controls, local groups/users, scheduled tasks.
- Security baseline: per-device identity, mTLS-ready, signed command envelopes (MVP TODO for full asymmetric verification), strict RBAC, immutable audit logs.

### Out of scope
- Full MDM parity.
- Recovery key escrow storage by default.
- Native remote desktop session proxying in DMS.

## 2) Stage implementation plan (Stage 0 -> Stage 10)

### Stage 0: Decisions and constraints
- Goal: architecture, identity model, MVP boundaries.
- Tasks: define data flows, enrollment trust model, pilot capabilities.
- Code changes: `docs/architecture/architecture.md`.
- Commands: `mkdir docs/architecture`.
- Tests: architecture review.
- Acceptance criteria: approved architecture and identity model.
- Rollback: temporary signed-token identity fallback.

### Stage 1: Backend foundation
- Goal: admin auth + RBAC + immutable audit.
- Tasks: Sanctum auth, permission middleware, audit logger.
- Code changes:
  - `backend/app/Http/Controllers/Api/V1/AuthController.php`
  - `backend/app/Http/Middleware/RequirePermission.php`
  - `backend/app/Services/AuditLogger.php`
- Commands: `composer install`, `php artisan migrate --seed`.
- Tests: `php artisan test --filter=RbacTest`.
- Acceptance criteria: login works, unauthorized blocked, audit logs written.
- Rollback: disable permission middleware.

### Stage 2: Device model and enrollment
- Goal: one-time enrollment and identity metadata.
- Tasks: enrollment tokens, device registration, heartbeat.
- Code changes:
  - `backend/app/Http/Controllers/Api/V1/EnrollmentController.php`
  - `backend/database/migrations/2026_02_19_154500_create_dms_domain_tables.php`
- Commands: `php artisan migrate`.
- Tests: `php artisan test --filter=EnrollmentTest`.
- Acceptance criteria: token single-use enrollment works; heartbeat updates online status.
- Rollback: disable enrollment endpoint, revoke identities.

### Stage 3: Check-in and job queue basics
- Goal: command polling, ack, result upload.
- Tasks: deterministic fetch and run reporting.
- Code changes:
  - `backend/app/Http/Controllers/Api/V1/DeviceCheckinController.php`
  - `backend/app/Http/Controllers/Api/V1/Admin/JobAdminController.php`
- Commands: `php artisan route:list`.
- Tests: feature tests around check-in + result.
- Acceptance criteria: run command/job result appears in DB.
- Rollback: disable job creation endpoint.

### Stage 4: Package repository and installers
- Goal: package metadata and installer engine.
- Tasks: package/version/file CRUD and download metadata.
- Code changes:
  - `backend/app/Http/Controllers/Api/V1/Admin/PackageAdminController.php`
  - `backend/app/Http/Controllers/Api/V1/PackageController.php`
  - `agent/src/Dms.Agent.Core/Jobs/Handlers/Handlers.cs`
- Commands: `php artisan test`.
- Tests: contract tests for metadata + handler unit tests in agent.
- Acceptance criteria: package install job can be processed and reported.
- Rollback: disable `install_*` job types.

### Stage 5: Policy engine v1
- Goal: policy model and policy fetch.
- Tasks: policy versioning, rule storage, device policy retrieval.
- Code changes:
  - `backend/app/Http/Controllers/Api/V1/Admin/PolicyAdminController.php`
  - `backend/app/Http/Controllers/Api/V1/DeviceCheckinController.php`
- Commands: `php artisan test`.
- Tests: policy endpoint and rule serialization checks.
- Acceptance criteria: device receives active policy rules.
- Rollback: force audit mode.

### Stage 6: Hardening and signing
- Goal: replay protection and signature verification pipeline.
- Tasks: nonce/sequence enforcement, signature verifier integration.
- Code changes:
  - `agent/src/Dms.Agent.Core/Transport/CommandEnvelopeVerifier.cs`
  - `agent/src/Dms.Agent.Core/Security/ReplayProtector.cs`
- Commands: `dotnet test`.
- Tests: replay/invalid signature rejection.
- Acceptance criteria: replayed command rejected.
- Rollback: grace window for prior signing key IDs.

### Stage 7: Scaling primitives
- Goal: groups, targeting, rate control.
- Tasks: group membership targeting and fan-out.
- Code changes:
  - `backend/app/Http/Controllers/Api/V1/Admin/GroupAdminController.php`
  - `backend/app/Http/Controllers/Api/V1/Admin/JobAdminController.php`
- Commands: `docker compose up -d`.
- Tests: integration for group fan-out.
- Acceptance criteria: group-targeted jobs generate run rows.
- Rollback: target explicit devices only.

### Stage 8: UI polish
- Goal: full admin workflow through dashboard.
- Tasks: implement Blade pages for devices/jobs/policies/packages/audit.
- Code changes: `backend/resources/views/admin/*`.
- Commands: `npm run build`.
- Tests: feature tests for pages.
- Acceptance criteria: no DB console access needed for workflows.
- Rollback: hide incomplete pages, keep API.

### Stage 9: Optional MeshCentral integration
- Goal: deep links from device record.
- Tasks: mesh id mapping and URL template.
- Code changes: uses `devices.meshcentral_device_id`.
- Commands: update app config and UI link template.
- Tests: link generation test.
- Acceptance criteria: click-through opens mesh session.
- Rollback: disable deep-link render.

### Stage 10: Production readiness
- Goal: backup, restore, secrets, TLS, monitoring, pilot operations.
- Tasks: production deployment docs and runbooks.
- Code changes:
  - `backend/docker-compose.yml`
  - `docs/runbooks/operations.md`
- Commands: backup restore drills.
- Tests: restore and queue recovery validation.
- Acceptance criteria: 10-device pilot completed.
- Rollback: stop enrollments + rollback image.

## 3) Repository structure
- Backend: `backend/`
- Agent: `agent/`
- Key folders are implemented per requested design.

## 4) Database schema
Implemented migration:
- `backend/database/migrations/2026_02_19_154500_create_dms_domain_tables.php`
- `backend/database/migrations/2026_02_19_154501_make_audit_logs_append_only.php`

Includes all requested entities:
- tenants
- users, roles, permissions
- devices, device_identities, key_materials
- device_groups, memberships
- packages, versions, files, install_profiles
- policies, versions, rules, assignments
- jobs, job_runs, job_events
- compliance checks/results
- audit_logs append-only

## 5) API routes and JSON contracts
Implemented routes: `backend/routes/api.php`.

Device endpoints:
- `POST /api/v1/device/enroll`
- `POST /api/v1/device/heartbeat`
- `POST /api/v1/device/checkin`
- `GET /api/v1/device/policies`
- `POST /api/v1/device/job-ack`
- `POST /api/v1/device/job-result`
- `GET /api/v1/device/packages/{packageVersionId}/download-meta`

Admin endpoints:
- `POST /api/v1/auth/login`
- `GET /api/v1/auth/me`
- `POST /api/v1/auth/logout`
- `GET/PATCH /api/v1/admin/devices`
- `POST /api/v1/admin/enrollment-tokens`
- `GET/POST /api/v1/admin/groups`
- `GET/POST /api/v1/admin/packages`
- `POST /api/v1/admin/packages/{packageId}/versions`
- `GET/POST /api/v1/admin/policies`
- `POST /api/v1/admin/policies/{policyId}/versions`
- `GET/POST /api/v1/admin/jobs`
- `GET /api/v1/admin/audit-logs`

Enrollment request example:
```json
{
  "enrollment_token": "token",
  "csr_pem": "-----BEGIN CERTIFICATE REQUEST-----...",
  "device_facts": {
    "hostname": "PC-001",
    "os_name": "Windows 11",
    "os_version": "23H2",
    "serial_number": "ABC123",
    "agent_version": "1.0.0"
  }
}
```

## 6) Agent protocol, nonce/replay, signature scheme
Models in:
- `agent/src/Dms.Agent.Core/Protocol/EnvelopeModels.cs`

Verifier in:
- `agent/src/Dms.Agent.Core/Transport/CommandEnvelopeVerifier.cs`

Replay protection in:
- `agent/src/Dms.Agent.Core/Security/ReplayProtector.cs`

Current implementation security note:
- Command envelopes are signed with Ed25519 keys from backend keyset.
- Agent verifies signature, schema, payload hash, expiration, and replay constraints.
- See `backend/app/Services/CommandEnvelopeSigner.php` and `agent/src/Dms.Agent.Core/Transport/CommandEnvelopeVerifier.cs`.

Error codes (implemented constants):
- `E_SIG_INVALID`
- `E_SIG_UNKNOWN_KID`
- `E_EXPIRED`
- `E_REPLAY`
- `E_SCHEMA`
- `E_PAYLOAD_HASH`
- `E_UNSUPPORTED`
- `E_EXEC_FAILED`
- `E_TRANSIENT`

## 7) Policy model DSL examples
Policy payload shape:
```json
{
  "policy_version_id": "uuid",
  "rules": [
    {
      "id": "uuid",
      "type": "firewall",
      "mode": "enforce",
      "config": {
        "domain": true,
        "private": true,
        "public": true
      }
    }
  ]
}
```

Example rule snippets:
- Firewall all profiles on.
- BitLocker required with metadata-only escrow.
- Local admin group allowlist.
- Disable USB storage via registry `USBSTOR Start=4`.
- Windows Update active hours and forced install window.

## 8) Software deployment engine
Implementation file:
- `agent/src/Dms.Agent.Core/Jobs/Handlers/Handlers.cs`

Included handlers:
- `WingetInstallHandler`
- `MsiInstallHandler`
- `ExeInstallHandler`
- `PolicyApplyHandler`
- `ScriptHandler` (disabled by default)

Return code handling:
- `0` = success
- `3010` = success with reboot required
- Other = failed

Detection rules:
- Implemented in agent handlers for `file`, `registry`, `product_code`, and `version` checks.

## 9) Security design and threat model

### Attack surfaces
- Enrollment token theft.
- API tampering or replay.
- Malicious package substitution.
- Admin privilege misuse.
- Audit log mutation attempts.

### Mitigations
- One-time token hash storage + expiry.
- Replay defense (nonce + sequence).
- Hash validation metadata for package files.
- Strict RBAC middleware.
- Append-only audit log DB triggers.

### Key rotation and least privilege
- `key_materials` tracks signing and identity metadata.
- agent/service runs non-interactive least privilege account.

## 10) End-to-end setup instructions

Backend local:
1. `cd backend`
2. `copy .env.example .env`
3. `php artisan key:generate`
4. `php artisan migrate --seed`
5. `php artisan serve`

Backend tests:
- `php artisan test`

Docker deployment:
1. `cd backend`
2. `docker compose up -d`
3. `docker compose exec app php artisan migrate --seed`

Agent build (on Windows with .NET 8 SDK installed):
1. `cd agent`
2. `dotnet build Dms.Agent.sln`
3. `dotnet test Dms.Agent.sln`
4. Publish single-file service binary:
   - `dotnet publish src/Dms.Agent.Service/Dms.Agent.Service.csproj -c Release -r win-x64 -p:PublishSingleFile=true`

Device onboarding:
1. Create enrollment token via admin API.
2. Install agent service and set API URL, device ID, token bootstrap.
3. Start service and confirm heartbeat/check-in.

Policy assignment and package deployment:
1. Create package/version, then create job.
2. Create policy/version and publish.
3. Verify job runs and policy fetch payloads via API and audit logs.

## 11) Observability and operations
- Logs: Laravel app logs + job events + agent local logs.
- Metrics: job success ratio, check-in latency, queue depth, compliance ratio.
- Alerts: signature failures, repeated enrollment failures, stale device check-ins.
- Backup: Postgres full + incremental WAL, restore drills.
- Safe mode: dispatch disable flag and policy audit-only mode.

## 12) Pilot rollout checklist

10-device pilot:
1. RBAC role matrix validated.
2. Enrollment flow works on all pilot devices.
3. Heartbeat and offline detection confirmed.
4. Firewall policy compliance visible.
5. MSI or winget deployment succeeds.
6. Replay protection negative test passes.
7. Audit trail coverage reviewed.
8. Backup/restore dry run completed.
9. Incident runbook walkthrough completed.
10. Pilot report template filled.

Scale to 100+:
1. Use staggered rollout policies by default.
2. Increase queue worker pool and isolate critical queues.
3. Add DB read replicas for analytics.
4. Add key rotation automation.
5. Add canary groups for policy/package rollout.

## Reference decisions
- Laravel 11 and Reverb context: https://laravel.com/docs/11.x/releases
- Winget capabilities: https://learn.microsoft.com/windows/package-manager/winget/
- .NET Windows Service worker guidance: https://learn.microsoft.com/dotnet/core/extensions/windows-service
- MeshCentral capabilities: https://github.com/Ylianst/MeshCentral
