#!/usr/bin/env bash
# Berkas yang berubah per repo dibanding baseline fitur. Juga ditulis ke features/<KEY>-*/changed.txt.
#   scripts/changed.sh <KEY|feature-dir> [--all]
# Kolom: repo  kelas  status-git  path
#   NEW   bersih saat baseline, sekarang berubah  -> milik fitur ini
#   PRE+  sudah kotor saat baseline DAN berubah lagi sesudahnya -> campuran, stage per hunk
#   PRE   kotor saat baseline, tidak berubah -> bukan milik fitur (hanya tampil dengan --all)
#   COMMITTED  berubah lewat commit sesudah baseline (HEAD bergeser)
set -euo pipefail
source "$(dirname "$0")/lib/env.sh"
dir="$(feature_dir "${1:?KEY}")"; all="${2:-}"
out="$dir/changed.txt"; : > "$out"
for name in be fe; do
  var=$([ $name = be ] && echo BE_DIR || echo FE_DIR); repo="$(to_unix_path "${!var}")"
  [ -f "$dir/baseline/$name.status" ] || { echo "baseline $name tidak ada di $dir/baseline" >&2; exit 2; }
  head0="$(cat "$dir/baseline/$name.head")"
  if [ "$(git -C "$repo" rev-parse HEAD)" != "$head0" ]; then
    git -C "$repo" diff --name-only "$head0" HEAD | sed "s#^#$name\tCOMMITTED\t--\t#" >> "$out"
  fi
  git -C "$repo" status --porcelain=v1 -uall | while IFS= read -r l; do
    st="${l:0:2}"; p="${l:3}"; p="${p##* -> }"; p="${p%\"}"; p="${p#\"}"
    old="$(awk -F'\t' -v p="$p" '$1==p{print $2; exit}' "$dir/baseline/$name.hashes")"
    if [ -z "$old" ]; then cls=NEW
    else
      if [ -f "$repo/$p" ]; then now="$(git -C "$repo" hash-object -- "$p")"; else now="-"; fi
      if [ "$now" = "$old" ]; then cls=PRE; else cls=PRE+; fi
    fi
    [ "$cls" = PRE ] && [ "$all" != "--all" ] && continue
    printf '%s\t%s\t%s\t%s\n' "$name" "$cls" "${st// /.}" "$p"
  done >> "$out"
done
cat "$out"
echo "# ditulis: $out ($(grep -c . "$out" || true) baris)" >&2
