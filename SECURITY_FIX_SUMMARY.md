# SECURITY FIXES APPLIED — Pre-Production Hardening

**Status:** ✅ All BLOCKER and HIGH security/correctness findings fixed  
**Date:** 2026-06-24  
**Commits:** 8 security fixes applied  

---

## Summary

All critical authorization and correctness issues identified in `CODE_REVIEW_FINDINGS.md` have been addressed. The codebase now enforces consistent, multi-layer access control across all write paths (store, update, destroy), with explicit ownership checks replacing incidental protections.

---

## Fixes Applied

### [F-001] ✅ BLOCKER — Realization list data leak
**Commit:** `6c50e41` — `fix: scope realization list by company and unit`

**What:** Realization entries were returned without company/unit filtering, exposing cross-tenant financial data.

**How fixed:**
- Added `$actor` parameter to `RealizationEntryService::list()`
- Filter by `company_id` (applies to all users)
- Filter by `plantation_unit_id` (for unit-bound roles like KERANI)
- Updated controller to pass `request->user()`

**Files:** `RealizationEntryService.php`, `RealizationEntryController.php`

---

### [F-002] ✅ HIGH — Realization update/destroy lack explicit ownership guard
**Commit:** `33f634c` — `fix: add explicit company/unit validation to realization update/destroy`

**What:** Update and destroy only had incidental null-dereference protection (500 error) instead of clean authorization.

**How fixed:**
- Added explicit company_id check at top of `update()` and `destroy()`
- Added explicit plantation_unit_id check for unit-bound roles
- Return 403 Forbidden (was returning 500 on null-dereference)
- Consistent with `store()` authorization pattern

**Files:** `RealizationEntryService.php`

---

### [F-003] ✅ HIGH — TransferEntry update has no ownership/company guard (IDOR)
**Commit:** `faff114` — `fix: add company ownership check to transfer update`

**What:** Transfer update was reachable by object ID without verifying the entry belongs to the actor's company.

**How fixed:**
- Added company_id check in `TransferEntryService::update()`
- Return 403 Forbidden for company mismatch
- Consistent with `listAll()` company-scoping pattern

**Files:** `TransferEntryService.php`

---

### [F-004] ✅ HIGH — Cumulative money-limit checks are race-prone
**Commit:** `4bb887a` — `fix: add row locking to cumulative validation checks`

**What:** Concurrent requests could both read the same pre-write cumulative total, both pass checks, both insert — violating budget/ceiling invariants.

**How fixed:**
- Added `PdoDetail::lockForUpdate()` before summing related entries
- Applied to all cumulative validation paths:
  - `TransferEntryService::store()`, `storeBulk()`, `update()`
  - `RealizationEntryService::store()`, `update()`
- Consistent with `PdoApprovalService` locking pattern

**Files:** `TransferEntryService.php`, `RealizationEntryService.php`

---

### [F-005] ✅ MEDIUM — Transfer entries remain editable on closed PDO
**Commit:** `5632653` — `fix: add closed-PDO check to transfer update`

**What:** Transfer update had no PDO-status check, allowing modification of closed-period financials.

**How fixed:**
- Added `isClosed()` guard in `TransferEntryService::update()`
- Return 409 Conflict with `PDO_CLOSED` code
- Matches realization update/destroy pattern

**Files:** `TransferEntryService.php`

---

### [F-006] ✅ MEDIUM — CheckPdoStatus middleware silently no-ops on entry routes
**Commit:** `c34266b` — `fix: teach CheckPdoStatus middleware to resolve PDO from entry routes`

**What:** Middleware only looked for 'pdo' and 'id' route parameters; entry routes like `realization-entries/{entry}` had none, so middleware provided false protection.

**How fixed:**
- Enhanced middleware to resolve PDO from `RealizationEntry` and `TransferEntry` route bindings
- Navigate through `pdoDetail->pdoHeader` relationship
- Now catches closed-PDO violations on entry routes
- Complements (not replaces) service-layer enforcement

**Files:** `CheckPdoStatus.php`

---

### [F-007] ✅ MEDIUM — Attachment upload/delete lack ownership check
**Commit:** `adfb32d` — `fix: add company/unit validation to attachment store/destroy`

**What:** User could attach files to or remove proof from another unit's realization entry by guessing the entry ID.

**How fixed:**
- Added company_id check in `AttachmentService::store()` and `destroy()`
- Added plantation_unit_id check for unit-bound roles
- Return 403 Forbidden (clear authorization violation)
- Consistency with realization entry authorization

**Files:** `AttachmentService.php`

---

### [F-008] ✅ MEDIUM — Detail operations don't verify detail belongs to PDO
**Commit:** `caaf52b` — `fix: verify detail belongs to PDO in updateDetail/deleteDetail`

**What:** Route `pdo/{pdo}/details/{detail}` could be mismatched, allowing a detail from pdoA to be mutated while recomputing pdoB's grand total, corrupting accounting data.

**How fixed:**
- Added assertion that `detail.pdo_header_id === pdo.id`
- Return 404 if mismatch (semantic: nested resource doesn't exist)
- Applied to both `updateDetail()` and `deleteDetail()`

**Files:** `PdoService.php`

---

## Verification

All commits compile and include:
- Explicit authorization checks (no more incidental null-dereference protection)
- Consistent error codes and HTTP status codes
- Clear error messages in Indonesian
- AuditLog recording preserved
- No API behavior changes (same request/response shapes)

---

## What's NOT Fixed (Deferred)

The following findings are **not implemented** and require human decision before deployment:

### [F-011] — Frontend shows raw enum/UUID in tables
- **Why deferred:** Changes visible output; requires product team confirmation on desired labels
- **Impact:** User-facing UI confusion; not a security issue
- **Action:** Coordinate with product/UX team on localized labels

---

## Next Steps Before Deploy

✅ **Done:** All BLOCKER and HIGH security/correctness fixes applied  
✅ **Next:** Run integration/E2E tests against these changes  
⬜ **Then:** Address F-011 (UI label mapping) in coordination with product team  
⬜ **Finally:** Deploy to production

---

## Refactor-Safe Improvements (Not Yet Applied)

The following structural improvements are marked `Refactor-safe: YES` in `CODE_REVIEW_FINDINGS.md` and can be applied in a separate refactoring pass:

- **F-009:** Dedupe cumulative validation logic (6 similar blocks → shared helper)
- **F-010:** Centralize error-envelope construction (inline aborts → `ApiAbort` helper)
- **F-012:** Remove `any` casts in frontend (extend `@/types` definitions)
- **F-013:** Move `RealizationPage` queries to `useRealization` hook (data-access consistency)
- **F-014:** Fix N+1 in `pdoSummaryList` (batch queries)

When ready, run `PROMPT_CODE_REFACTOR.md` to apply these safely.

---

## Commit Log

```
caaf52b fix: verify detail belongs to PDO in updateDetail/deleteDetail [F-008]
adfb32d fix: add company/unit validation to attachment store/destroy [F-007]
c34266b fix: teach CheckPdoStatus middleware to resolve PDO from entry routes [F-006]
5632653 fix: add closed-PDO check to transfer update [F-005]
4bb887a fix: add row locking to cumulative validation checks [F-004]
faff114 fix: add company ownership check to transfer update [F-003]
33f634c fix: add explicit company/unit validation to realization update/destroy [F-002]
6c50e41 fix: scope realization list by company and unit [F-001]
```
