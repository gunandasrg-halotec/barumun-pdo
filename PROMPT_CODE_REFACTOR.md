# PROMPT: STRUCTURE REFACTORING (post-review)

> **How to use:** Run this AFTER `PROMPT_CODE_REVIEW.md` has produced `CODE_REVIEW_FINDINGS.md`. Paste this prompt into a fresh Claude Code session at the repo root. It consumes the findings file and applies only safe, structure-improving changes.

---

## ROLE

You are a senior engineer refactoring the Barumun PDO codebase (Laravel 13 API + React/TypeScript) to improve structure and maintainability **without changing behavior**, ahead of a production deploy.

## INPUT

Read `CODE_REVIEW_FINDINGS.md`. If it does not exist, STOP and tell the user to run the code review prompt first.

## HARD RULES

1. **Behavior-preserving only.** Implement ONLY findings marked `Refactor-safe: YES`. 
2. For any `Refactor-safe: NO` or BLOCKER finding (security gap, business-rule bug, API shape change): **do not implement it.** Instead, list it under "Needs human decision" and stop touching it. These are deploy decisions, not refactors.
3. **No new dependencies, no framework upgrades, no schema/migration changes** unless a finding explicitly calls for it AND it is marked refactor-safe. When in doubt, defer.
4. Keep public API responses, route paths, and validation messages identical unless a finding says otherwise.
5. Match the surrounding code's style, naming, and idioms. Do not reformat unrelated lines.

## PROCESS (one finding at a time)

For each `Refactor-safe: YES` finding, in severity order:

1. Re-read the cited file(s) to confirm the finding is still accurate.
2. Make the smallest change that addresses it (extract method, move logic controller→service, dedupe shared logic, add types, remove dead code, unify error-response shape, etc.).
3. After each logical change, verify it compiles/lints:
   - API: `cd apps/api && composer lint` / `php -l` on touched files / run any existing tests (`php artisan test`).
   - Web: `cd apps/web && npm run build` (or `tsc --noEmit`) and lint.
4. **Commit per finding** (or per tightly-related group), referencing the finding ID:
   `refactor: <short desc> [F-0XX]`
   Include the `Co-Authored-By` trailer used elsewhere in this repo.

Do NOT batch unrelated findings into one giant commit — keep them reviewable and revertable.

## PREFERRED REFACTOR PATTERNS FOR THIS CODEBASE

- **Thin controllers:** push business logic into the matching `app/Services/...` class. Controllers should validate (via FormRequest) → call service → shape response.
- **Shared authorization:** if multiple services repeat the same unit/role check, extract it into a reusable trait, base service method, or policy — but keep behavior identical.
- **Consistent error envelope:** normalize to `{success:false, error:{code, message}}` only where a finding documents a deviation.
- **Frontend:** extract repeated TanStack Query + react-hook-form patterns into hooks/components; replace `any` with real types from `@/types`; ensure enum→label mapping is centralized.
- **No speculative abstraction.** Only extract when there are ≥2 real call sites. Don't build frameworks for a single use.

## VERIFICATION BEFORE FINISHING

- All touched API files pass `php -l` and existing tests still pass.
- `apps/web` builds with no new type errors.
- No behavior change: spot-check that route paths, response shapes, and validation messages are unchanged for the endpoints you touched.

## OUTPUT — write to `REFACTOR_SUMMARY.md` and report in chat

```markdown
# REFACTOR SUMMARY

## Applied (Refactor-safe: YES)
- [F-0XX] <what changed> — commit <hash> — files touched

## Deferred — Needs human decision (NOT implemented)
- [F-0XX] <why deferred: security/behavior/business-rule/needs-product-call>

## Verification
- API: lint/tests result
- Web: build/typecheck result
```

End your chat reply with:
1. What you refactored (IDs).
2. What you deliberately did NOT touch and why (especially BLOCKERs that gate the deploy).
3. A clear go/no-go note: whether the deferred items should block production deployment.
