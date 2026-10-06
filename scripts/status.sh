#!/usr/bin/env bash
# Ringkasan semua fitur di features/: key, epic, phase, ronde QA, judul. Status Jira ditambahkan orkestrator.
#   scripts/status.sh [EPIC]
set -uo pipefail
source "$(dirname "$0")/lib/env.sh"
want="${1:-}"
printf '%-12s %-10s %-8s %-3s %s\n' KEY EPIC PHASE QA TITLE
for f in "$AGENTIC_DIR"/features/*/run.md; do
  [ -f "$f" ] || continue
  fm() { awk -v k="$1" 'NR==1&&/^---/{p=1;next} p&&/^---/{exit} p&&$0~"^"k":"{sub("^"k":[ ]*","");print;exit}' "$f"; }
  ep="$(fm epic)"; [ -n "$want" ] && [ "$ep" != "$want" ] && continue
  printf '%-12s %-10s %-8s %-3s %s\n' "$(fm key)" "${ep:--}" "$(fm phase)" "$(fm qa_round)" "$(fm title)"
done
