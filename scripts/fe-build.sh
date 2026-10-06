#!/usr/bin/env bash
# Build FE ke folder staging, dan salin ke build/ milik developer hanya saat `serve` mati.
#   scripts/fe-build.sh build      BUILD_PATH=$FE_STAGING_DIR yarn build (Node dari .nvmrc). Log: work/fe-build.log
#   scripts/fe-build.sh publish    port $FE_SERVE_PORT kosong -> SALIN staging ke $FE_DIR/$FE_SERVE_DIR (tanpa build ulang)
#                                  port dipakai -> exit 3 (minta developer mematikan serve)
#   scripts/fe-build.sh status     keadaan port + waktu build staging dan build/
# Tidak pernah build langsung ke build/, tidak pernah mematikan proses apa pun.
set -uo pipefail
source "$(dirname "$0")/lib/env.sh"
fe="$(to_unix_path "$FE_DIR")"; stg="$(to_unix_path "$FE_STAGING_DIR")"; tgt="$fe/${FE_SERVE_DIR:-build}"
mkdir -p "$AGENTIC_DIR/work"

listening() {
  netstat -ano 2>/dev/null | grep -qE "[:.]${FE_SERVE_PORT} .*LISTEN" && return 0
  [ "$(curl -s -o /dev/null -m 3 -w '%{http_code}' "http://127.0.0.1:${FE_SERVE_PORT}/")" != "000" ]
}
mtime() { [ -f "$1" ] && date -r "$1" '+%Y-%m-%d %H:%M:%S' || echo "-"; }

case "${1:-}" in
  build)
    cd "$fe" || exit 2
    want="$(tr -d ' \r\n' < .nvmrc)"; have="$(node -v 2>/dev/null | tr -d 'v\r')"
    if [ "${have}" != "${want#v}" ]; then nvm use "$(cat .nvmrc)" >/dev/null || { echo "nvm use \$(cat .nvmrc) gagal - laporkan, jangan diakali" >&2; exit 2; }; fi
    log="$AGENTIC_DIR/work/fe-build.log"
    BUILD_PATH="$FE_STAGING_DIR" yarn build > "$log" 2>&1; rc=$?
    tail -n 15 "$log"; echo "# exit=$rc log=$log staging=$stg"; exit $rc ;;
  publish)
    [ -f "$stg/index.html" ] || { echo "staging belum berisi build ($stg/index.html tidak ada): jalankan 'build' dulu" >&2; exit 2; }
    if listening; then echo "port ${FE_SERVE_PORT} masih dipakai (serve jalan). Minta developer mematikan serve, lalu ulangi publish." >&2; exit 3; fi
    case "$tgt" in "$fe"/?*) ;; *) echo "target tidak aman: $tgt" >&2; exit 2;; esac
    mkdir -p "$tgt" && rm -rf "${tgt:?}"/* && cp -r "$stg"/. "$tgt"/ || exit 2
    if [ -d "$fe/public" ]; then
      for p in "$fe/public"/*; do b="$(basename "$p")"; [ "$b" = index.html ] && continue; cp -rn "$p" "$tgt"/ 2>/dev/null; done
    fi
    echo "build/ diperbarui dari staging ($(mtime "$stg/index.html")). Developer bisa menyalakan lagi: serve .\${FE_SERVE_DIR:-build}\ -p ${FE_SERVE_PORT}" ;;
  status)
    if listening; then echo "port ${FE_SERVE_PORT}: dipakai"; else echo "port ${FE_SERVE_PORT}: kosong"; fi
    echo "staging : $(mtime "$stg/index.html")  ($stg)"
    echo "build/  : $(mtime "$tgt/index.html")  ($tgt)" ;;
  *) sed -n '2,7p' "$0"; exit 2 ;;
esac
