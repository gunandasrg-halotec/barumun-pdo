# Test Plan — Full Approval Cycle of PDO Tambahan (Supplementary PDO)

**Executor:** Sonnet 4.5
**Environment:** Local Docker (Postgres in `db` container, API `artisan serve` on :8000, Web Vite on :5173)
**Scope:** End-to-end approval cycle → WhatsApp notifications at each stage → merge into parent PDO Bulanan → merged items appear **at the end** of the parent list, separated by a **special divider**.
**Constraint:** Commit to **local repo only**. Do NOT push to remote.
**Rule:** If any bug/issue is encountered during the test, **fix it immediately**, then re-run the failed step.

---

## 0. What already exists (verified during planning)

| Piece | Status | Location |
|---|---|---|
| Approval chain (submit → asisten → mgr kebun → mgr keuangan → direktur → `final_merged`) | ✅ Built | `PdoSupplementaryApprovalService.php` |
| WA notifications wired at every stage (submit/approve/reject) | ✅ Built | `WhatsAppNotificationService::notifySupplementary*` |
| Merge service (copies supp details → parent `pdo_details`, appends at end via `display_order`, sets `source_pdo_supplementary_id`, `merged_at`) | ✅ Built | `PdoSupplementaryMergeService.php` |
| Merge endpoint `POST /pdo-supplementary/{id}/merge` (role: Mgr/Dir Keuangan) | ✅ Built | `PdoSupplementaryMergeController.php` |
| Parent detail grouped API (`findPdoGrouped`) groups by kategori → subkategori | ✅ Built | `PdoService.php:80` |

### ⚠️ Gaps that must be built for this test to pass its acceptance criteria

1. **Merged items are NOT visually at the end / no divider.**
   `findPdoGrouped` groups strictly by category. Merged supplementary items carry `source_pdo_supplementary_id` but get **scattered into their category groups**, not placed at the end. There is **no divider** distinguishing Bulanan items from Tambahan items. **This must be built** (backend + frontend) — see Phase C.

2. **WA templates for supplementary events** reuse the existing PDO event templates (`pdo_submitted`, `pdo_approved_asisten`, etc.). Confirm those templates exist and are `is_active` for the test company, otherwise `send()` silently logs a warning and skips (Phase A precheck).

---

## Design decisions (confirm before executing)

- **D1 — Merge trigger:** Keep the existing **two-step** flow (Direktur approve → status `final_merged`; then Mgr/Dir Keuangan calls the **merge** action). The test exercises both steps explicitly. *(Alternative: auto-merge on Direktur approval. Not recommended — it collapses the BR-MERGE-002 role check. Flag if user wants auto-merge instead.)*
- **D2 — Divider granularity:** One "PDO Tambahan" section appended after all Bulanan categories, sub-grouped per source supplementary (shows PDOT number + merge date). Divider is a full-width labeled row.

---

## Phase A — Preconditions & fixtures

1. Confirm containers up: `docker compose ps` (db healthy, api, web).
2. Confirm DB is Postgres and no sqlite fallback (already fixed): `DB default connection: pgsql`.
3. **Pick the test unit & parent PDO.** Reuse `PDOT-2026-07-BN-001` (already reset to draft, logs cleared) OR create a fresh one. Record: `parent_pdo_header_id`, `plantation_unit_id`, `company_id`.
4. **Verify approver users exist** for every role in the chain, all in the SAME plantation unit / company, each with a `whatsapp_number` set:
   - KERANI, ASISTEN_KEBUN, MANAJER_KEBUN, MANAJER_KEUANGAN, DIREKTUR_KEUANGAN.
   - Use the test accounts from memory (`project_pdo_users.md`).
5. **Verify WA gateway settings** for the company: `wa_gateway_url`, `username`, `password` set in `system_settings`; `APP_KEY` correct so `decrypt(password)` succeeds.
6. **Verify WA templates** are active for events: `pdo_submitted`, `pdo_approved_asisten`, `pdo_approved_manager`, `pdo_final`, plus reject events. If missing → seed/activate them (fix immediately).
7. Ensure the parent PDO has ≥1 existing Bulanan detail (so the divider has something to sit below).

**Precheck WA sending path:** Because WA `send()` swallows HTTP errors, add temporary observability — tail `storage/logs/laravel.log` during each step and assert the outbound `POST /send/message` is attempted (or point the gateway at a capture/echo endpoint). Do NOT assert on the real phone receiving a message unless the user provides a live test number.

---

## Phase B — Approval cycle (happy path) + WhatsApp at each stage

Drive via API (Sanctum tokens per role) — the real app path, not tinker. For each step: assert HTTP 200, assert new `status`, assert an approval log row was appended, and assert the WA notification for that stage was dispatched (log/echo).

| Step | Actor (role) | Action | Expected status | WA event dispatched | WA recipients |
|---|---|---|---|---|---|
| B1 | KERANI | `POST /pdo-supplementary/{id}/submit` (with items, amount>0) | `submitted` | `pdo_submitted` | Asisten (unit) |
| B2 | ASISTEN_KEBUN | `POST .../approve` | `reviewed_asisten` | `pdo_approved_asisten` | Kerani + Mgr Kebun + Mgr Keuangan |
| B3 | MANAJER_KEBUN | `POST .../approve` | `in_review_manager` | `pdo_approved_asisten` | Mgr Keuangan + Asisten + Kerani |
| B4 | MANAJER_KEUANGAN | `POST .../approve` | `in_review_direktur` | `pdo_approved_manager` | Direktur + Asisten + Kerani |
| B5 | DIREKTUR_KEUANGAN | `POST .../approve` | `final_merged` | `pdo_final` | Mgr Kebun + Mgr Keuangan + Asisten + Kerani |

Assertions after B5:
- `pdo_supplementary_headers.status = final_merged`, `merged_at IS NULL` (not merged yet).
- 5 approval log rows in correct `sequence_number` order with correct `approval_stage`/`action`.

**Negative sub-check (B-neg, optional but recommended):** From `submitted`, have ASISTEN reject with reason → status `rejected`, WA `pdo_rejected` to Kerani. Then KERANI re-submit → status `submitted`, action `resubmit`. Then continue the happy path. (Confirms reject/resubmit branch + WA.)

---

## Phase C — Merge into parent PDO Bulanan + divider (BUILD + TEST)

### C1 — Backend build
- In `PdoSupplementaryMergeService::merge` — already appends at end via `display_order` and sets `source_pdo_supplementary_id`. Verify still correct. Ensure `merged_at` and an `AuditLog` row are written.
- In `PdoService::findPdoGrouped` — **change so that details with `source_pdo_supplementary_id != null` are excluded from the normal category groups and returned in a separate top-level key**, e.g.:
  ```
  'categories'          => [ ...bulanan only... ],
  'supplementary_groups'=> [
      { 'supplementary': {id, pdo_number, merged_at}, 'details': [...], 'subtotal_amount': N }
  ],
  'grand_total'         => (bulanan + supplementary)
  ```
  Keep `grand_total` inclusive of merged items. Eager-load `source_pdo_supplementary_id` and the related supplementary number.

### C2 — Merge action
- Actor MANAJER_KEUANGAN (or DIREKTUR_KEUANGAN): `POST /pdo-supplementary/{id}/merge`.
- Assert HTTP 200, `merged_at` set, N new rows in `pdo_details` with `source_pdo_supplementary_id = {supp id}`, `display_order` greater than every pre-existing Bulanan detail.
- Assert double-merge is blocked: second call → 409 `ALREADY_MERGED`.
- Assert role guard: a non-finance role → 403.

### C3 — Frontend build (divider)
- In `PdoDetailPage.tsx` — after rendering the normal category groups, if `supplementary_groups` is non-empty, render:
  - A **full-width divider row** labeled e.g. `— Item dari PDO Tambahan —` (distinct background/border so it's unmistakable).
  - For each supplementary group: a sub-header showing the PDOT number + merge date, then its item rows (same columns as Bulanan rows).
- Ensure grand total footer reflects Bulanan + Tambahan.
- Update the TS types in `apps/web/src/types/index.ts` for the new `supplementary_groups` shape.

### C4 — Verify in the running app (preview tools)
- Load parent PDO detail page.
- Assert: Bulanan items render in their categories; a clear divider appears below them; Tambahan items appear **after** the divider, at the end; totals correct.
- Screenshot as proof.

---

## Phase D — Regression & cleanup

- Re-open the PDO Tambahan detail page → status shows merged/`final_merged`, history intact.
- Confirm a PDO with **no** supplementary still renders normally (no empty divider).
- Confirm KERANI/ASISTEN unit-scope (global scope) still limits visibility.
- Remove any temporary logging added for WA observability.

---

## Acceptance criteria (all must hold)

1. ✅ Full chain submit→final_merged succeeds with correct status at every step.
2. ✅ A WhatsApp notification is dispatched at every approval stage to the correct recipients (verified via log/echo).
3. ✅ After merge, every PDO Tambahan item exists in the parent `pdo_details` with `source_pdo_supplementary_id` set.
4. ✅ Merged items appear **at the end** of the parent PDO Bulanan list.
5. ✅ A clear **divider** visually separates Bulanan items from Tambahan items in the UI.
6. ✅ Grand total includes merged items; double-merge blocked; role guards enforced.
7. ✅ Any bug found during the run is fixed in-place and the step re-passes.
8. ✅ All changes committed to **local repo only**.

---

## Deliverables from the executor

- Pass/fail table for B1–B5, C2, C3/C4.
- The code diffs for the divider feature (backend `findPdoGrouped`, frontend `PdoDetailPage.tsx` + types).
- Screenshot of the parent PDO detail page showing the divider + Tambahan section.
- List of any bugs found and how they were fixed.
- Local commit hash(es).
