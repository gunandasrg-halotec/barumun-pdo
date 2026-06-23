#!/bin/bash
set -eo pipefail

if [ -z "$1" ]; then
  echo "Usage: $0 <iterations>"
  exit 1
fi

# jq filter to extract Codex assistant messages
stream_text='select(.type == "item.completed" and .item.type == "agent_message").item.text // empty | gsub("\n"; "\r\n") | . + "\r\n\n"'

# jq filter to extract final Codex result
final_result='select(.type == "item.completed" and .item.type == "agent_message").item.text // empty'

for ((i=1; i<=$1; i++)); do
  tmpfile=$(mktemp)
  trap "rm -f $tmpfile" EXIT

  commits=$(git log -n 5 --format="%H%n%ad%n%B---" --date=short 2>/dev/null || echo "No commits found")
  prds=$(cat .scratch/*/PRD.md 2>/dev/null || echo "No PRDs found")
  issues=$(cat .scratch/*/issues/*.md 2>/dev/null || echo "No issues found")
  prompt=$(cat ralph/prompt.md)

  codex --ask-for-approval never --sandbox danger-full-access exec \
    --json \
    "Previous commits: $commits PRDs: $prds Issues: $issues $prompt" \
    < /dev/null \
  | grep --line-buffered '^{' \
  | tee "$tmpfile" \
  | jq --unbuffered -rj "$stream_text"

  result=$(jq -r "$final_result" "$tmpfile")

  if [[ "$result" == *"<promise>NO MORE TASKS</promise>"* ]]; then
    echo "Ralph complete after $i iterations."
    exit 0
  fi
done
