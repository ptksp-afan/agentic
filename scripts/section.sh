#!/usr/bin/env bash
# Cetak satu bagian markdown saja (hemat konteks: jangan baca berkas besar utuh).
#   scripts/section.sh <file> "<teks judul>"      contoh: scripts/section.sh features/ED-1/spec.md "Subtask"
# Judul dicocokkan tanpa peka huruf besar di baris '#..'; berhenti di judul berikutnya yang levelnya sama/lebih tinggi.
# Frontmatter: scripts/section.sh <file> --frontmatter
set -euo pipefail
f="${1:?file}"; h="${2:?heading}"
if [ "$h" = "--frontmatter" ]; then awk 'NR==1&&/^---/{p=1;print;next} p{print} p&&/^---/{exit}' "$f"; exit 0; fi
awk -v h="$h" '
  BEGIN{ h=tolower(h) }
  /^#+ /{ lvl=match($0,/[^#]/)-1
          if(on && lvl<=start) exit
          if(!on && index(tolower($0),h)>0){ on=1; start=lvl } }
  on{ print }' "$f"
