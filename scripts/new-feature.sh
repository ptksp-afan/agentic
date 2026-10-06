#!/usr/bin/env bash
# Buat folder fitur + run.md + baseline per repo. Idempoten: kalau folder sudah ada, hanya mencetak path-nya.
#   scripts/new-feature.sh <KEY|NEW-yyyymmdd> <slug> [--epic ED-1200] [--rebaseline]
set -euo pipefail
source "$(dirname "$0")/lib/env.sh"
id="${1:?KEY}"; slug="${2:?slug}"; shift 2
epic=""; rebase=0
while [ $# -gt 0 ]; do case "$1" in --epic) epic="$2"; shift 2;; --rebaseline) rebase=1; shift;; *) echo "argumen tidak dikenal: $1" >&2; exit 2;; esac; done
dir="$AGENTIC_DIR/features/$id-$slug"
existing=$(ls -d "$AGENTIC_DIR/features/$id"-*/ 2>/dev/null | head -1 || true)
[ -n "$existing" ] && dir="${existing%/}"
# Selesai = run.md + baseline lengkap. Folder setengah jadi (run sebelumnya gagal) dilanjutkan.
if [ -f "$dir/run.md" ] && [ -s "$dir/baseline/fe.head" ] && [ "$rebase" = 0 ]; then echo "sudah ada: $dir"; exit 0; fi
mkdir -p "$dir/qa/e2e" "$dir/baseline"

snap() { # $1 = nama repo, $2 = dir repo
  local name="$1" repo; repo="$(to_unix_path "$2")"
  git -C "$repo" rev-parse HEAD > "$dir/baseline/$name.head"
  git -C "$repo" branch --show-current > "$dir/baseline/$name.branch"
  git -C "$repo" status --porcelain=v1 -uall > "$dir/baseline/$name.status"
  : > "$dir/baseline/$name.hashes"
  while IFS= read -r l; do
    p="${l:3}"; p="${p##* -> }"; p="${p%\"}"; p="${p#\"}"
    if [ -f "$repo/$p" ]; then printf '%s\t%s\n' "$p" "$(git -C "$repo" hash-object -- "$p")"; else printf '%s\t-\n' "$p"; fi
  done < "$dir/baseline/$name.status" >> "$dir/baseline/$name.hashes"
}
snap be "$BE_DIR"; snap fe "$FE_DIR"

if [ ! -f "$dir/run.md" ]; then
  now="$(date '+%Y-%m-%d %H:%M')"
  cat > "$dir/run.md" <<MD
---
key: $id
epic: ${epic:-}
title:
slug: $slug
phase: ba
qa_round: 0
be_branch: $(cat "$dir/baseline/be.branch")
fe_branch: $(cat "$dir/baseline/fe.branch")
agents: {}
---
## Status ringkas
Baru dibuat. Berikutnya: BA.

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|

## Keputusan developer

## Usulan pipeline

## Log
- $now start: baseline BE $(cut -c1-9 "$dir/baseline/be.head") ($(wc -l < "$dir/baseline/be.status") berkas kotor), FE $(cut -c1-9 "$dir/baseline/fe.head") ($(wc -l < "$dir/baseline/fe.status") berkas kotor)
MD
fi
echo "$dir"
