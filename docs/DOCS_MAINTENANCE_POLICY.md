# Documentation Maintenance Policy

This project requires documentation updates for every functional change.

## Mandatory rule
- Any change that adds, removes, or alters behavior in:
  - admin UI
  - API endpoints/contracts
  - agent handlers/protocol
  - operations/security controls
- must include documentation updates in the same change.

## Required files to keep current
- `docs/FUNCTIONS_GUIDE.md` (primary usage reference)
- `docs/runbooks/operations.md` (incident + operations steps)
- `docs/architecture/architecture.md` (if architecture or data flow changes)

## Definition of done (DoD)
A feature is not complete until all are true:
1. Code builds/tests pass.
2. Function usage is documented in `docs/FUNCTIONS_GUIDE.md`.
3. Runbook updates are included if operations behavior changed.
4. API changes include request/response updates in docs.
5. Security-sensitive changes include mitigation notes.

## Pull request checklist (copy into PR description)
- [ ] Updated `docs/FUNCTIONS_GUIDE.md`
- [ ] Updated `docs/runbooks/operations.md` (if needed)
- [ ] Updated `docs/architecture/architecture.md` (if needed)
- [ ] Added/updated examples for new API/UI flow
- [ ] Verified docs against running build

## Review gate
- Reviewer should reject feature changes that do not include required docs updates.
