# ISSUES

Local markdown issues are provided at the start of context. Parse them to understand available work.

You will work on AFK-ready issues only. In this repo, AFK-ready means the issue file has `Status: ready-for-agent` near the top.

Do not work on HITL issues. Treat these labels as HITL or not-AFK:

- `ready-for-human`
- `needs-info`
- `needs-triage`
- `wontfix`

If all AFK tasks are complete, output `<promise>NO MORE TASKS</promise>`.

# TASK SELECTION

Pick the next task. Prioritize tasks in this order:

1. Critical bugfixes
2. Development infrastructure

Getting development infrastructure like tests, local scripts, and build tooling ready is an important precursor to building features.

3. Tracer bullets for new features

Tracer bullets are small slices of functionality that go through all layers of the system, allowing you to test and validate your approach early. This helps identify potential issues and ensures the overall architecture is sound before investing significant time in development.

TL;DR - build a tiny, end-to-end slice of the feature first, then expand it out.

4. Polish and quick wins
5. Refactors

# EXPLORATION

Explore the repo.

Read the selected local issue file from the provided `Path:`:

```bash
sed -n '1,240p' .scratch/<feature>/issues/<NN>-<slug>.md
```

If the issue references a parent PRD or another issue, read that local markdown file before editing.

# IMPLEMENTATION

- Use the `tdd` skill to complete the task, alaws refer to `laravel-docs` skill and `context7` skill for searching documentation.
- Alyaws use up to date documentation for implement features, dont use your training data.

# FEEDBACK LOOPS

Before finishing, run the feedback loops relevant to the change:

- `php artisan test --compact` for PHP behavior
- `vendor/bin/pint --dirty --format agent` when PHP files changed
- `npm run build` when frontend assets changed

# THE ISSUE

If the task is complete, update the local issue file:

1. Change `Status: ready-for-agent` to `Status: ready-for-human` if human review is needed.
2. Change `Status: ready-for-agent` to `Status: done` only if the issue is fully done and no review or follow-up is needed.
3. Append a `## Comments` entry with summary, tests run, files changed, and follow-up notes.

If the task is not complete, leave or change status appropriately and append a `## Comments` entry with what was done, what remains, and the blocker.

# FINAL RULES

ONLY WORK ON A SINGLE TASK.

Do not invent tasks. Only work from the provided local markdown issues.

Do not change dependencies unless the selected issue explicitly requires it.

Do not rewrite unrelated code.
