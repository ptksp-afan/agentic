#!/usr/bin/env bash
# Kembalikan perubahan item yang diparkir (park/<KEY>) ke working tree BE/FE, di atas HEAD sekarang.
#   scripts/unpark.sh <KEY>
# Jalankan SESUDAH `scripts/new-feature.sh <KEY> <slug> --rebaseline`, supaya berkas yang kembali
# terhitung NEW. Branch park/<KEY> dibiarkan sampai item di-commit (lihat references/commit.md).
# Exit: 0 ok / tidak ada park, 3 bentrok (berkas sudah berubah atau patch tidak bisa diterapkan; tidak ada yang diubah).
set -uo pipefail
source "$(dirname "$0")/lib/env.sh" || exit $?
key="${1:?KEY}"
for name in be fe; do
  var=$([ $name = be ] && echo BE_DIR || echo FE_DIR); repo="$(to_unix_path "${!var}")"
  ref="park/$key"
  git -C "$repo" show-ref -q --verify "refs/heads/$ref" || { echo "$name: tidak ada $ref"; continue; }
  paths=(); while IFS= read -r l; do [ -n "$l" ] && paths+=("$l"); done < <(git -C "$repo" diff --name-only "$ref^" "$ref")
  dirty="$(git -C "$repo" status --porcelain -- "${paths[@]}")"
  [ -n "$dirty" ] && { echo "$name: berkas park sedang berubah di working tree:" >&2; echo "$dirty" >&2; exit 3; }
  patch="$(mktemp)"; git -C "$repo" diff --binary "$ref^" "$ref" > "$patch"
  if git -C "$repo" apply --check "$patch" 2>/dev/null; then git -C "$repo" apply "$patch"
  else echo "$name: patch $ref bentrok dengan HEAD sekarang (commit item lain menyentuh berkas yang sama):" >&2
       git -C "$repo" apply --check "$patch" 2>&1 | head -10 >&2; rm -f "$patch"; exit 3; fi
  rm -f "$patch"
  echo "$name: $ref diterapkan (${#paths[@]} berkas)"
  if [ $name = be ]; then be_reload || true; fi
done
