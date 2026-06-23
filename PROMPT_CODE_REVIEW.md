# PROMPT: PRE-DEPLOYMENT CODE REVIEW

> **How to use:** Paste this whole prompt into a fresh Claude Code session (or hand to a subagent) at the repo root. It produces a single report file: `CODE_REVIEW_FINDINGS.md`. Do NOT change any code in this pass — review only.

---

## ROLE

You are a senior engineer doing a pre-production code review of the Barumun PDO application:
- **Backend:** Laravel 13 (PHP), PostgreSQL, Sanctum auth, service-layer architecture
- **Frontend:** React + TypeScript, react-hook-form + Zod, TanStack Query
- **Deploy:** Docker Compose (production)

The app manages plantation operational funds (PDO) with role-based, unit-scoped access control. Key roles: ADMIN, KERANI, ASISTEN_KEBUN, MANAJER_KEBUN, MANAJER_KEUANGAN, STAFF_KEUANGAN, STAFF_PURCHASING, DIREKTUR_KEUANGAN.

## SCOPE & CONSTRAINTS

- **Read-only.** Do not edit, refactor, or fix anything. Output is a report only.
- Cover both `apps/api` and `apps/web`.
- Prioritize correctness and security over style.
- Every finding must cite `file:line` and explain *why* it matters — no vague advice.
- Distinguish facts you verified in code from hypotheses. Never invent file paths; confirm they exist.

## WHAT TO REVIEW

### 1. Security & Authorization (highest priority)
- Row-level security: every write path (realization, transfer, PDO details, supplementary) must enforce unit scope AND role. Verify global scopes on models (`PdoHeader`, `PdoDetail`, etc.) and explicit service-layer checks. Look for endpoints that load a model by ID without a unit/role guard.
- Compare write operations against each other — if `RealizationEntryService` validates unit ownership but `TransferEntryService` does not, flag the inconsistency.
- Mass assignment (`$fillable` vs request input), authorization in FormRequest `authorize()`, IDOR via route-model binding that bypasses global scopes (e.g. `RealizationEntry $entry` resolved without unit check).
- Secrets, tokens, or credentials committed to the repo.

### 2. Correctness & Business Rules
- Money math: all amounts are integers (Rupiah). Flag any float arithmetic, rounding, or implicit casts on monetary fields.
- Cumulative validation (realisasi ≤ transfer, per-item and global) — confirm it can't be bypassed by concurrent requests (check for DB transactions / locking).
- Status-machine enforcement (`check.pdo.status` middleware) applied consistently to all mutating routes.
- N+1 queries, missing eager loads, queries inside loops.

### 3. Structure & Maintainability
- Controllers staying thin; business logic in services. Flag fat controllers or logic leaking into models/requests.
- Duplicated logic across services (transfer vs realization patterns) that should be shared.
- Inconsistent error-response shapes between endpoints (`{success, error:{code,message}}` vs other shapes).
- Frontend: duplicated form/query patterns, components doing data-fetching + presentation + mutation all at once, `any` types, missing error/loading states.
- Dead code, commented-out blocks, TODO/FIXME that are real.

### 4. Consistency
- Naming, response envelopes, validation message language (Indonesian), enum handling (frontend showing raw enums like `kas_kebun` vs labels).

## METHOD

1. Map the codebase first: list controllers, services, models, middleware, FormRequests, and the main frontend pages/hooks. 
2. Trace the critical write paths end-to-end (route → middleware → controller → FormRequest → service → model).
3. For each area above, record findings as you go.
4. Do not stop at the first issue in a file — read the whole file.

## OUTPUT — write to `CODE_REVIEW_FINDINGS.md`

Use this exact structure so the refactoring prompt can consume it:

```markdown
# CODE REVIEW FINDINGS

## Summary
- Overall risk for production deploy: LOW / MEDIUM / HIGH
- Blocker count: N
- One-paragraph verdict.

## Findings

### [F-001] <short title>
- **Severity:** BLOCKER | HIGH | MEDIUM | LOW
- **Category:** Security | Correctness | Structure | Consistency
- **Location:** path/to/file.php:line
- **What:** factual description of the issue
- **Why it matters:** concrete impact / exploit / bug scenario
- **Suggested direction:** (one line — not full code)
- **Refactor-safe:** YES (pure refactor) | NO (behavior change / needs decision)

### [F-002] ...
```

Rules for findings:
- ID them F-001, F-002… so they can be referenced later.
- Sort by severity (BLOCKERs first).
- Mark `Refactor-safe: YES` only when the fix changes structure without changing observable behavior. Anything that alters behavior, API shape, or business rules is `NO` and must be decided by a human.

## FINISH

End with: a list of BLOCKER IDs that must be resolved before deploy, and the count of `Refactor-safe: YES` findings (these feed the refactoring prompt).
