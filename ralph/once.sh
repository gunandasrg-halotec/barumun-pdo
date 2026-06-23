#!/bin/bash

issues=$(cat .scratch/*/issues/*.md 2>/dev/null || echo "No issues found")
prds=$(cat .scratch/*/PRD.md 2>/dev/null || echo "No PRDs found")
commits=$(git log -n 5 --format="%H%n%ad%n%B---" --date=short 2>/dev/null || echo "No commits found")
prompt=$(cat ralph/codex-prompt.md)

codex --ask-for-approval never --sandbox danger-full-access exec \
  "Previous commits: $commits PRDs: $prds Issues: $issues $prompt"
