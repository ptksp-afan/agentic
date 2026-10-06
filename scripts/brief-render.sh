#!/usr/bin/env bash
# Render HTML desain (berkas atau folder export, mis. dari Claude Design) ke PNG desktop + mobile.
#   scripts/brief-render.sh <file.html|folder> <out-dir>
# Folder: semua *.html di dalamnya (maks 20). Memakai Chrome sistem lewat Playwright milik scripts/e2e
# (dipasang otomatis tanpa unduhan browser bila belum ada). Exit 0 ok, 1 sebagian gagal, 2 argumen/dependensi.
set -uo pipefail
source "$(dirname "$0")/lib/env.sh" || exit $?
src="${1:?file.html atau folder}"; out="${2:?out-dir}"
e2e="$AGENTIC_DIR/scripts/e2e"
if [ ! -f "$e2e/node_modules/@playwright/test/package.json" ]; then
  (cd "$e2e" && PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm ci --silent) || { echo "npm ci di scripts/e2e gagal" >&2; exit 2; }
fi
files=()
if [ -d "$src" ]; then
  while IFS= read -r f; do files+=("$f"); done < <(find "$src" -type f \( -name '*.html' -o -name '*.htm' \) | sort | head -20)
else files=("$src"); fi
[ ${#files[@]} -gt 0 ] || { echo "tidak ada berkas HTML di $src" >&2; exit 2; }
node "$AGENTIC_DIR/scripts/brief/render.js" "$out" "${files[@]}"
