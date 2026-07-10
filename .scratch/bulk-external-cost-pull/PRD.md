Status: done

# Bulk External Cost Pull

## Problem Statement

Kerani must click **External Cost Pull** one row at a time when a draft PDO contains many **Eligible External Cost Details**. This is slow, repetitive, and easy to miss, especially because each **Auto External Expense Item** must have a fresh external amount before PDO submission.

## Solution

Add **Bulk External Cost Pull** to the draft PDO form. The action pulls external amounts for every **Eligible External Cost Detail** in the PDO, keeps successful pulls even when other rows fail, then shows a concise success/failure summary and per-row errors.

## User Stories

1. As a kerani, I want one button to pull all eligible external costs in a draft PDO, so that I do not click each Auto External row manually.
2. As a kerani, I want the bulk action to target only missing or stale external snapshots, so that fresh rows are not pulled again unnecessarily.
3. As a kerani, I want manual Expense Items skipped, so that manual amounts remain under my control.
4. As a kerani, I want fresh external snapshots skipped, so that valid captured amounts do not change without need.
5. As a kerani, I want unsaved Auto External rows skipped, so that only DB-backed PDO details are pulled.
6. As a kerani, I want a warning when unsaved Auto External rows exist, so that I know to save the draft first.
7. As a kerani, I want successful rows to stay saved when another Payroll pull fails, so that one bad mapping does not waste all successful pulls.
8. As a kerani, I want failed rows to show row-level messages, so that I know which Cost Mapping or Payroll issue needs attention.
9. As a kerani, I want the button label to become `Semua Data Sudah Fresh` when no rows need pulling, so that I understand the PDO is up to date.
10. As a kerani, I want the bulk button disabled when no rows need pulling, so that I do not trigger no-op actions.
11. As a kerani, I want the bulk button in the **Rencana Biaya** header, so that the action is near item management controls.
12. As a kerani, I want a loading state during bulk pull, so that I cannot accidentally start duplicate pulls.
13. As a kerani, I want a toast like `9 berhasil, 1 gagal`, so that I can quickly assess the result.
14. As a kerani, I want the PDO detail list refreshed after the bulk pull, so that amounts, flags, and totals reflect saved server state.
15. As a kerani, I want retry to pull only rows still missing or stale, so that I can fix a failed row and rerun safely.
16. As an asisten or manager, I want submitted PDO details to keep their captured snapshots, so that approved numbers do not change after a later master-data change.
17. As finance, I want each successful external pull audited like a single-row pull, so that captured external amounts remain traceable.
18. As an admin, I want existing Cost Mapping rules preserved, so that bulk pull does not introduce new mapping semantics.
19. As a developer, I want one backend endpoint for bulk pull, so that unit access, draft-status checks, audit, and Payroll error handling stay centralized.
20. As a developer, I want serial Payroll calls, so that rate-limit risk and partial failure handling stay predictable.
21. As a developer, I want the response to separate succeeded and failed details, so that the UI can show accurate summary and per-row error states.
22. As a developer, I want no schema change, so that the feature reuses current External Cost Snapshot fields.

## Implementation Decisions

- Add a backend API action for **Bulk External Cost Pull** on a single PDO.
- The bulk action uses the same auth, unit access, draft-status rule, Cost Mapping rules, Payroll request rules, snapshot update behavior, and audit behavior as single-row **External Cost Pull**.
- The backend identifies **Eligible External Cost Details** as draft PDO details for active **Auto External Expense Items** where the external amount is missing or stale.
- The backend skips manual details, fresh external snapshots, and any detail not persisted in the PDO.
- Payroll calls run serially.
- Partial success is required: successful pulls commit; failed pulls do not roll back successful rows.
- Response includes counts plus `succeeded[]` and `failed[]`; each failed item includes enough identity/message data for UI row error display.
- Frontend adds `Ambil Semua Data` in the **Rencana Biaya** header near item actions.
- Frontend disables the button and changes copy to `Semua Data Sudah Fresh` when there are no eligible persisted rows.
- Frontend shows loading state while bulk pull runs.
- Frontend shows warning copy when unsaved Auto External rows exist, because they cannot be pulled until saved.
- Frontend refetches PDO details after bulk completion instead of manually merging all row changes.
- Frontend maps backend failures to the same per-row error display used by single-row External Cost Pull.
- No DB schema changes.
- ADR `0001-payroll-component-key-options` and ADR `0002-payroll-role-filter-separate-from-component-key` remain in force: role stays distinct from External Component Key, and existing Cost Mapping semantics are unchanged.

## Testing Decisions

- Test external behavior at API and form boundaries; do not test private helper details.
- Primary backend seam: feature test the bulk PDO endpoint with fake Payroll responses and real persisted PDO details.
- Backend tests should cover all-success bulk pull, partial failure, all-failure, no eligible details, fresh snapshots skipped, stale snapshots included, manual details skipped, non-draft PDO rejected, missing Payroll Estate Mapping, missing Cost Mapping, and audit logs for successful rows.
- Backend prior art: PDO External Cost Pull feature tests.
- Primary frontend seam: form/component test around the PDO form behavior, using mocked API responses.
- Frontend tests should cover button placement/copy, disabled fresh state, loading state, success/failure toast, detail refetch after completion, per-row error rendering, retry after partial failure, and unsaved Auto External row warning.
- Frontend prior art: External Cost Pull panel tests and PDO form behavior around single-row pull.
- Prefer one backend feature-test seam plus one frontend form-test seam over lower-level unit tests.

## Out of Scope

- Changing Payroll calculation rules.
- Parallel Payroll calls.
- Background jobs or queued pull processing.
- Pulling data across multiple PDOs.
- Pulling unsaved detail rows.
- Changing Cost Mapping semantics.
- Changing Payroll Estate Mapping.
- Changing approval, transfer, realization, or close flows.
- Adding new database snapshot fields.
- Removing the existing single-row External Cost Pull.

## Further Notes

- Domain glossary now defines **Bulk External Cost Pull**, **Eligible External Cost Detail**, **Fresh External Cost Snapshot**, and **Stale External Cost Snapshot**.
- The design intentionally favors predictable partial success over all-or-nothing behavior because Payroll/API failures may be row-specific.

## Comments

- 2026-07-07: Implemented bulk external pull end-to-end on draft PDO form and backend.
- 2026-07-07: Backend:
  - Added `POST /api/v1/pdo/{pdo}/pull-external-costs` in `apps/api/routes/api.php`.
  - Added controller action in `apps/api/app/Http/Controllers/PDO/PdoDetailController.php`.
  - Added `PdoService::bulkPullExternalCost()` in `apps/api/app/Services/PDO/PdoService.php` with eligible-row filtering, serial pulls, partial success, per-row failure payloads, and summary counts.
  - Added feature coverage in `apps/api/tests/Feature/PDO/PdoExternalCostPullTest.php` for partial success, no-eligible noop, and non-draft rejection.
- 2026-07-07: Frontend:
  - Added `useBulkPullExternalCost()` in `apps/web/src/hooks/usePdo.ts`.
  - Updated `apps/web/src/pages/PdoFormPage.tsx` with header bulk button, fresh/disabled copy, loading state, unsaved Auto External warning, row-error mapping, summary toast, and persisted-detail refetch while preserving unsaved rows.
  - Added form tests in `apps/web/src/pages/PdoFormPage.test.tsx` for fresh-state disable/copy and partial-success bulk flow with warning + refetch + row error.
- 2026-07-07: Verification:
  - `docker compose run --rm --no-deps --entrypoint /bin/sh api -lc "cd /var/www/api && php artisan test --compact"` ✅
  - `docker compose run --rm --no-deps --entrypoint /bin/sh api -lc "cd /var/www/api && vendor/bin/pint --dirty --format agent"` ✅
  - `npm --prefix apps/web test -- --run src/pages/PdoFormPage.test.tsx` ✅
  - `npm --prefix apps/web run build` ✅
- 2026-07-07: Files changed:
  - `apps/api/app/Http/Controllers/PDO/PdoDetailController.php`
  - `apps/api/app/Services/PDO/PdoService.php`
  - `apps/api/routes/api.php`
  - `apps/api/tests/Feature/PDO/PdoExternalCostPullTest.php`
  - `apps/web/src/hooks/usePdo.ts`
  - `apps/web/src/pages/PdoFormPage.tsx`
  - `apps/web/src/pages/PdoFormPage.test.tsx`
- 2026-07-07: Follow-up notes:
  - Full suite passes. Existing build still warns about large frontend chunks; unrelated to this feature.
