# Architecture

## Text diagram
- Admin Browser -> Nginx -> Laravel API + Blade
- Laravel API -> PostgreSQL
- Laravel API -> Redis queue
- Horizon workers -> Redis
- Windows Agent -> Laravel API (HTTPS, mTLS-ready)
- Agent -> package source via backend metadata (hash validated)
- Device details -> optional MeshCentral deep link

## Core data flows
1. Enrollment: one-time token -> device record + identity metadata.
2. Check-in: agent polls -> signed envelopes returned.
3. Execution: agent acks command -> executes installer/policy -> posts results.
4. Audit: admin and device actions written append-only in `audit_logs`.
