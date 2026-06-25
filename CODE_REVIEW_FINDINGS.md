# CODE REVIEW FINDINGS

## Summary
- **Overall risk for production deploy: HIGH**
- **Blocker count: 1**
- The codebase has a clean service-layer architecture, integer-safe money math, and a working role/unit authorization foundation (middleware + global scopes on `PdoHeader`/`PdoDetail`). However, the authorization model is applied **inconsistently**: it was hardened on the realization *store* path but the matching *list*, *update*, *destroy*, and *attachment* paths were not. The most serious issue is that `GET /realization-entries` returns **all** realization records with no company/unit filter, exposing financial data across units and companies to any authenticated user. Several write paths are reachable by object ID without an ownership guard (IDOR), the cumulative money-limit checks are vulnerable to concurrent-request races, and the `check.pdo.status` middleware silently no-ops on the entry-bound routes that the frontend actually uses. These must be resolved or consciously accepted before production.

> Note: I verified that monetary fields are integer-only (no float arithmetic on amounts; the only division is percentage display in `DashboardService`), and that the committed `apps/api/.env.ci` contains dummy values (`DB_PASSWORD=secret`, `AWS_*=test`, empty `APP_KEY`) — **not** real secrets. Neither is a finding.

---

## Findings

### [F-001] `GET /realization-entries` leaks all realization records (no company/unit scope)
- **Severity:** BLOCKER
- **Category:** Security
- **Location:** apps/api/app/Services/Realization/RealizationEntryService.php:18-24 (`list()`); controller apps/api/app/Http/Controllers/Realization/RealizationEntryController.php:19-25; consumed by apps/web/src/pages/RealizationPage.tsx:44-50
- **What:** `list()` runs `RealizationEntry::with(...)->when(pdo_detail_id)->get()` with no `company_id` or unit filter. `RealizationEntry` has **no global scope** (only `PdoHeader` and `PdoDetail` do — confirmed via grep). The route has `ensure.unit.access`, but that middleware only binds `current_unit_id` for the `PdoHeader`/`PdoDetail` scopes, which never touch this query.
- **Why it matters:** Any authenticated user (e.g. a KERANI from unit BN) calling `GET /realization-entries` receives every realization entry in the database — amounts, proof numbers, payment methods, recorders — across all units and all companies. Eager-loaded `pdoDetail` may come back null for other units, but the financial rows themselves are still returned. This is a cross-tenant financial data breach.
- **Suggested direction:** Filter `list()` by `company_id` (and unit for unit-bound roles) via `whereHas('pdoDetail.pdoHeader', ...)`, mirroring `TransferEntryService::listAll()`.
- **Refactor-safe:** NO

### [F-002] Realization update/destroy lack an explicit ownership guard (inconsistent with store)
- **Severity:** HIGH
- **Category:** Security
- **Location:** apps/api/app/Services/Realization/RealizationEntryService.php:148-205 (`update`), 211+ (`destroy`); request apps/api/app/Http/Requests/Realization/UpdateRealizationEntryRequest.php:11-14; route apps/api/routes/api.php:99-100
- **What:** `store()` got an explicit unit check (`BR-AUTH-001`, line ~70), but `update()`/`destroy()` did not. The routes bind `RealizationEntry $entry` directly (unscoped model), and `UpdateRealizationEntryRequest::authorize()` only checks `canRecordRealization()` (role, not ownership). For KERANI the `PdoDetail` global scope makes `$entry->pdoDetail` resolve to null for another unit's entry, so `$entry->pdoDetail->pdoHeader` throws a 500 (incidental, not a clean 403). For cross-unit recorder role `STAFF_PURCHASING` (in `CROSS_UNIT_ROLES`, `plantation_unit_id` null) the scope is a no-op, so there is no unit barrier at all.
- **Why it matters:** Authorization correctness depends on an incidental null-dereference rather than an explicit check; behavior is a 500 instead of 403, and the store/update/destroy guards are inconsistent, which is how gaps appear during future edits.
- **Suggested direction:** Apply the same explicit unit/ownership assertion used in `store()` at the top of `update()` and `destroy()` (and return 403, not 500).
- **Refactor-safe:** NO

### [F-003] `TransferEntry` update has no ownership/company guard (IDOR)
- **Severity:** HIGH
- **Category:** Security
- **Location:** apps/api/app/Services/Transfer/TransferEntryService.php:238-283 (`update`); route apps/api/routes/api.php:94 (`transfer-entries/{entry}`); request apps/api/app/Http/Requests/Transfer/UpdateTransferEntryRequest.php
- **What:** The route binds `TransferEntry $entry` (no global scope on `TransferEntry`). `update()` validates only `is_auto_generated` and the budget ceiling. `authorize()` checks `canRecordTransfer()` (role only). There is no check that the entry belongs to the actor's `company_id`. `listAll()` correctly scopes by company, but `update()` does not.
- **Why it matters:** A finance-role user could modify a transfer entry belonging to another company/unit by guessing/knowing its UUID. Transfer roles are cross-unit by design, but they should still be company-bound.
- **Suggested direction:** Assert `$entry->pdoDetail->pdoHeader->company_id === $actor->company_id` (and unit where applicable) before mutating.
- **Refactor-safe:** NO

### [F-004] Cumulative money-limit checks are race-prone (no row lock)
- **Severity:** HIGH
- **Category:** Correctness
- **Location:** apps/api/app/Services/Transfer/TransferEntryService.php:83-96, 138-156, 250-267; apps/api/app/Services/Realization/RealizationEntryService.php:85-118, 169-189
- **What:** Each limit check reads `->sum('amount')` then compares to a ceiling inside `DB::transaction`, but without `lockForUpdate` on the sibling rows or the parent detail. The correct pattern already exists elsewhere — `PdoApprovalService.php:98,128` uses `PdoHeader::lockForUpdate()`.
- **Why it matters:** Two concurrent requests can both read the same pre-write sum, both pass the check, and both insert — producing total transfer > budget or total realisasi > transfer. This is exactly the invariant the business rules (BR-TRANSFER-002, BR-REAL-002/003) exist to protect, and it silently fails under concurrency.
- **Suggested direction:** Lock the parent `PdoDetail` (or the sibling entries) with `lockForUpdate()` inside the transaction before summing, consistent with the approval-service pattern.
- **Refactor-safe:** NO

### [F-005] Transfer entries remain editable on a CLOSED PDO (BR-CLOSE-003 violation)
- **Severity:** MEDIUM
- **Category:** Correctness
- **Location:** apps/api/app/Services/Transfer/TransferEntryService.php:238-283 (`update` — checks `is_auto_generated` only); store/bulk check `isFinal()` at 76/129 but `update` checks neither `isFinal()` nor `isClosed()`. Combined with F-006.
- **What:** `update()` has no PDO-status check, and the `check.pdo.status` middleware on the route is a no-op (see F-006). So a manual transfer entry attached to a closed PDO can still be edited.
- **Why it matters:** BR-CLOSE-003 states a closed PDO cannot be modified. Realization update/destroy enforce this in-service (`isClosed()` checks at RealizationEntryService.php:152,215), but transfer update does not — an inconsistency that lets closed-period financials change.
- **Suggested direction:** Add an `isClosed()` guard in `TransferEntryService::update()` mirroring the realization service.
- **Refactor-safe:** NO

### [F-006] `CheckPdoStatus` middleware silently no-ops on entry-bound routes
- **Severity:** MEDIUM
- **Category:** Correctness
- **Location:** apps/api/app/Http/Middleware/CheckPdoStatus.php:24-31; routes apps/api/routes/api.php:94,98-100
- **What:** The middleware resolves the PDO only from `route('pdo')` or `route('id')`. The routes `realization-entries/{entry}` (PUT/DELETE), `transfer-entries/{entry}` (PUT), and `realization-entries` (POST) carry none of those parameters, so `$pdoId` is null and the middleware returns `$next()` without checking anything. These top-level entry routes are the ones the frontend actually calls (e.g. RealizationPage posts to `/realization-entries`).
- **Why it matters:** The `->middleware('check.pdo.status')` on these routes provides a false sense of protection; closed-PDO enforcement actually depends entirely on per-service checks, which are present for realization but missing for transfer update (F-005).
- **Suggested direction:** Teach the middleware to resolve the PDO from `{entry}`/`{detail}` route bindings too, or remove the misleading middleware and rely on (complete) service-layer checks.
- **Refactor-safe:** NO

### [F-007] Attachment upload binds to any realization entry without ownership check
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** apps/api/app/Services/Realization/AttachmentService.php:20-60 (`store`); request apps/api/app/Http/Requests/Realization/StoreAttachmentRequest.php:9-12; route apps/api/routes/api.php:103
- **What:** `store(RealizationEntry $entry, ...)` is reached via an unscoped route binding with only `canRecordRealization()` role authorization. It checks `isClosed()` but never that the entry belongs to the actor's unit/company. Same incidental KERANI protection via the `PdoDetail` scope (500 on null) as F-002; no barrier for cross-unit `STAFF_PURCHASING`.
- **Why it matters:** A user could attach files to (or, via `destroy`, remove proof from) another unit's realization entry by ID.
- **Suggested direction:** Add the same ownership assertion as F-002 in `AttachmentService::store()`/`destroy()`.
- **Refactor-safe:** NO

### [F-008] `updateDetail`/`deleteDetail` don't verify the detail belongs to the PDO in the URL
- **Severity:** MEDIUM
- **Category:** Correctness
- **Location:** apps/api/app/Services/PDO/PdoService.php:269-288 (`updateDetail`), 290+ (`deleteDetail`); controller apps/api/app/Http/Controllers/PDO/PdoDetailController.php:37-46
- **What:** The route `pdo/{pdo}/details/{detail}` binds both models independently. The service uses `$pdo` for `assertDraft()` and `syncGrandTotal()` but mutates `$detail` without asserting `$detail->pdo_header_id === $pdo->id`.
- **Why it matters:** A mismatched `(pdoA, detailB)` pair edits/deletes detailB while recomputing pdoA's grand total — data integrity corruption. The `PdoDetail` global scope confines this to the user's own unit but not to the correct PDO.
- **Suggested direction:** Assert `$detail->pdo_header_id === $pdo->id` (404 otherwise) at the top of both methods, or use nested route-model binding scoping.
- **Refactor-safe:** NO

### [F-009] Duplicated cumulative-validation + abort blocks across transfer & realization services
- **Severity:** LOW
- **Category:** Structure
- **Location:** apps/api/app/Services/Transfer/TransferEntryService.php:84-96,145-156,251-267; apps/api/app/Services/Realization/RealizationEntryService.php:86-118,170-189
- **What:** The "sum existing → add new → compare to ceiling → abort with JSON error" logic is copy-pasted across six call sites with near-identical structure.
- **Why it matters:** Divergence risk (the error messages already differ subtly between store and update), and it's where the F-004 race fix must be applied uniformly — easier with one shared helper.
- **Suggested direction:** Extract a shared `assertWithinCeiling(detail, newTotal, ceiling, code, message)` helper (and apply the lock there).
- **Refactor-safe:** YES

### [F-010] Repeated inline `abort(response()->json([...]))` error-envelope construction
- **Severity:** LOW
- **Category:** Structure
- **Location:** pervasive — e.g. apps/api/app/Services/Transfer/TransferEntryService.php:77-80,89-95,130-133,149-155,242-245,259-265; apps/api/app/Services/Realization/RealizationEntryService.php (multiple)
- **What:** The `{success:false, error:{code,message}}` envelope is hand-built at ~dozens of sites with varying HTTP codes.
- **Why it matters:** The shape is consistent today but only by convention; one typo diverges it. A single helper makes the envelope authoritative and the services far more readable.
- **Suggested direction:** Add a small domain-exception or helper (e.g. `ApiAbort::with($code, $message, $status)`) and replace inline aborts.
- **Refactor-safe:** YES

### [F-011] Frontend shows raw enum and raw UUID in the realization table
- **Severity:** MEDIUM
- **Category:** Consistency
- **Location:** apps/web/src/pages/RealizationPage.tsx:179 (`{r.pdo_detail_id}` — raw UUID), 183 (`{r.funding_source}` — raw `kas_kebun`); contrast line 176 where `payment_method` IS mapped via `PAYMENT_LABEL`
- **What:** The "Item PDO" column renders the raw `pdo_detail_id` UUID instead of the expense-item name, and "Sumber Dana" renders the raw enum (`kas_kebun`/`rekening_kebun`) instead of an Indonesian label. `payment_method` is correctly labeled, so the inconsistency is visible within one table.
- **Why it matters:** End users see `019eeadd-...` and `kas_kebun` instead of "Upah Potong Buah" and "Kas Kebun" — confusing and unprofessional for a production financial app.
- **Suggested direction:** Resolve the item name via the loaded relation and add a `FUNDING_LABEL` map alongside `PAYMENT_LABEL`. (Changes visible output → human should confirm desired labels.)
- **Refactor-safe:** NO

### [F-012] `any` casts in frontend bypass type safety
- **Severity:** LOW
- **Category:** Structure
- **Location:** apps/web/src/pages/TransferPage.tsx:129,135-136,141,159; apps/web/src/pages/RekapitulasiPage.tsx:74,82; apps/web/src/pages/RealizationPage.tsx:94 (`error: any`); apps/web/src/pages/PdoFormPage.tsx:82 (`as any` on resolver)
- **What:** Nine `any`/`as any` casts, mostly to read `expense_item.split_transfer` and to coerce `setValue`/export filters. The underlying types in `@/types` appear incomplete (e.g. `split_transfer` not on the expense-item type).
- **Why it matters:** These casts hide real type gaps and defeat compile-time checks on financial form values.
- **Suggested direction:** Extend the `@/types` definitions (add `split_transfer`, export-filter types) and remove the casts; type the axios error as `AxiosError<ApiError>`.
- **Refactor-safe:** YES

### [F-013] `RealizationPage` inlines fetch+form+mutation while a `useRealization` hook exists
- **Severity:** LOW
- **Category:** Structure
- **Location:** apps/web/src/pages/RealizationPage.tsx:44-111 (inline `useQuery`/`useMutation`); unused hook at apps/web/src/hooks/useRealization.ts
- **What:** Other domains have data hooks (`usePdo`, `useMasterData`, etc.) and `useRealization.ts` exists, but `RealizationPage` defines its queries/mutations inline instead of using it. Same inline pattern repeats across pages.
- **Why it matters:** Inconsistent data-access layer; the page mixes fetching, form state, mutation, and presentation in one component, making it the hardest to test/maintain.
- **Suggested direction:** Move the queries/mutations into `useRealization` and have the page consume it, matching the other domains.
- **Refactor-safe:** YES

### [F-014] N+1 queries in `TransferEntryService::pdoSummaryList`
- **Severity:** LOW
- **Category:** Correctness
- **Location:** apps/api/app/Services/Transfer/TransferEntryService.php:189-232
- **What:** For each PDO the method runs `$pdo->details()->get()` plus two aggregate queries (`transfersByDest`, `lastTransfer`) inside a `map()` loop — 3×N queries.
- **Why it matters:** Linear query growth with the number of final PDOs on a list endpoint; acceptable now but a latent performance cliff.
- **Suggested direction:** Pre-aggregate with grouped queries keyed by `pdo_id` outside the loop, or eager-load with `withSum`.
- **Refactor-safe:** YES

---

## FINISH

**BLOCKER IDs that must be resolved before deploy:**
- **F-001** — cross-tenant realization data leak via `GET /realization-entries`.

**Strongly recommended before deploy (security/correctness, not refactor-safe):** F-002, F-003, F-004, F-005, F-006, F-007, F-008, F-011.

**Refactor-safe: YES findings (feed the refactoring prompt):** 5 — **F-009, F-010, F-012, F-013, F-014.**
