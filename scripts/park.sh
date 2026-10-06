#!/usr/bin/env bash
# Parkir perubahan satu item (BE + FE) ke branch lokal park/<KEY>, lalu kembalikan working tree.
# Dipakai long run saat item jadi blocker, supaya item berikutnya mulai dari tree bersih.
#   scripts/park.sh <KEY> "<alasan>"
# Hanya berkas kelas NEW (dibanding baseline fitur) yang diparkir. Berkas PRE/PRE+ (kerja developer
# sendiri) tidak pernah disentuh: kalau ada PRE+ atau COMMITTED -> exit 3 tanpa mengubah apa pun.
# Tidak berpindah branch: commit dibuat dengan index sementara (plumbing), lalu ref park/<KEY> di-set.
# Exit: 0 ok (atau tidak ada yang diparkir), 2 argumen/config, 3 tidak aman untuk diparkir otomatis.
set -uo pipefail
source "$(dirname "$0")/lib/env.sh" || exit $?
key="${1:?KEY}"; reason="${2:-blocker}"
dir="$(feature_dir "$key")" || exit 2
"$(dirname "$0")/changed.sh" "$dir" >/dev/null 2>&1 || { echo "changed.sh gagal" >&2; exit 2; }
ch="$dir/changed.txt"
bad="$(awk -F'\t' '$2=="PRE+"||$2=="COMMITTED"{print $1": "$2" "$4}' "$ch")"
[ -n "$bad" ] && { echo "tidak aman untuk diparkir otomatis (berkas campuran/sudah di-commit):" >&2; echo "$bad" >&2; exit 3; }

for name in be fe; do
  var=$([ $name = be ] && echo BE_DIR || echo FE_DIR); repo="$(to_unix_path "${!var}")"
  paths=(); while IFS= read -r l; do [ -n "$l" ] && paths+=("$l"); done < <(awk -F'\t' -v n="$name" '$1==n&&$2=="NEW"{print $4}' "$ch")
  [ ${#paths[@]} -eq 0 ] && { echo "$name: tidak ada yang diparkir"; continue; }
  ref="refs/heads/park/$key"
  git -C "$repo" show-ref -q --verify "$ref" && { echo "$name: park/$key sudah ada - selesaikan/hapus dulu" >&2; exit 3; }
  idx="$(mktemp)"; rm -f "$idx"
  GIT_INDEX_FILE="$idx" git -C "$repo" read-tree HEAD || exit 2
  GIT_INDEX_FILE="$idx" git -C "$repo" add -A -- "${paths[@]}" || { rm -f "$idx"; exit 2; }
  tree="$(GIT_INDEX_FILE="$idx" git -C "$repo" write-tree)"; rm -f "$idx"
  c="$(git -C "$repo" commit-tree "$tree" -p HEAD -m "[WIP] $key diparkir: $reason")" || exit 2
  # verifikasi: isi commit = persis berkas yang diparkir
  got="$(git -C "$repo" diff --name-only HEAD "$c" | sort)"; want="$(printf '%s\n' "${paths[@]}" | sort)"
  [ "$got" = "$want" ] || { echo "$name: verifikasi gagal, tidak ada yang diubah" >&2; diff <(echo "$want") <(echo "$got") >&2; exit 3; }
  git -C "$repo" update-ref "$ref" "$c" || exit 2
  for p in "${paths[@]}"; do
    if git -C "$repo" cat-file -e "HEAD:$p" 2>/dev/null; then git -C "$repo" restore --source=HEAD --staged --worktree -- "$p"
    else git -C "$repo" rm -q --cached --ignore-unmatch -- "$p" >/dev/null; rm -f "$repo/$p"; fi
  done
  echo "$name: park/$key = ${c:0:9} (${#paths[@]} berkas), working tree dikembalikan"
  if [ $name = be ]; then be_reload || true; fi
done
printf -- '- %s park: %s\n' "$(date '+%Y-%m-%d %H:%M')" "$reason" >> "$dir/run.md"
