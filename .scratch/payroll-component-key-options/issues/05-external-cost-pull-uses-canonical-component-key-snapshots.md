Status: done

# External Cost Pull Uses Canonical Component Key Snapshots

## Parent

.scratch/payroll-component-key-options/PRD.md

## What to build

Update **External Cost Pull** to use canonical **External Component Key** snapshots for Payroll requests, including `base_payroll_total`. Draft PDO detail snapshots should reflect Cost Mapping changes and force a fresh pull; non-draft snapshots should remain independent.

## Acceptance criteria

- [x] Pulling `base_payroll_total` with a selected key sends `component_key`, not the legacy role parameter.
- [x] Pulling option-backed components stores the component key in the PDO Detail Snapshot.
- [x] Changing Cost Mapping updates draft PDO detail snapshots, clears pulled payload, and marks the row stale/needs pull according to existing behavior.
- [x] Submitted/final/closed PDO Detail Snapshots do not change when master Cost Mapping changes.
- [x] Existing behavior for non-option components remains unchanged.
- [x] Feature tests cover Payroll request query, snapshot persistence, draft stale behavior, and non-draft independence.

## Blocked by

- .scratch/payroll-component-key-options/issues/02-cost-mapping-save-validation-uses-external-component-options.md
- .scratch/payroll-component-key-options/issues/04-legacy-base-payroll-role-normalizes-to-external-component-key.md

## Comments

- Done.
- Summary:
  - `apps/api/app/Services/PDO/PdoService.php`
    - Resolve canonical pull key from mapping: use `external_component_key`, fallback to legacy `external_role` for option components only.
    - Send canonical `component_key` to Payroll pull and stop sending `role`.
    - Preserve legacy non-option keys in pulled detail snapshot while sending no `component_key` to Payroll for those components.
    - Skip empty request params (`component_key`/`role`) in Payroll call.
  - `apps/api/app/Models/PdoDetail.php`
    - `needs_pull` now true when detail is stale.
    - Canonicalize snapshot fingerprint key to key-first (`external_component_key` then role for legacy base payroll).
  - `apps/api/tests/Feature/PDO/PdoExternalCostPullTest.php`
    - Updated legacy/base payroll key mapping tests and snapshot expectations for canonical behavior.
- Files changed:
  - `apps/api/app/Services/PDO/PdoService.php`
  - `apps/api/app/Models/PdoDetail.php`
  - `apps/api/tests/Feature/PDO/PdoExternalCostPullTest.php`
- Tests run:
  - `docker compose run --rm --no-deps --entrypoint /bin/sh api -lc "cd /var/www/api && php artisan test --compact tests/Feature/PDO/PdoExternalCostPullTest.php"` ✅
  - `docker compose run --rm --no-deps --entrypoint /bin/sh api -lc "cd /var/www/api && php artisan test --compact"` ✅
  - `docker compose run --rm --no-deps --entrypoint /bin/sh api -lc "cd /var/www/api && vendor/bin/pint --dirty --format agent"` ✅
