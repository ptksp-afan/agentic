#!/usr/bin/env bash
# Smoke test browser FE v5 (Playwright, Chrome sistem, headless). Dokumentasi: scripts/e2e/README.md
#
#   scripts/e2e/run.sh <feature-dir|KEY|none> [--profile a[,b]] [--all-routes] [--include-add] [--workers N]
#                      [--list] [--doctor [--login]] [-- <arg playwright>]
#
#   scripts/e2e/run.sh ED-1234                         # smoke routes.json + spec fitur, profil default
#   scripts/e2e/run.sh ED-1234 --profile default,user2
#   scripts/e2e/run.sh none --profile default --all-routes     # semua layar FE (lambat)
#   scripts/e2e/run.sh --doctor                        # cek config/Chrome/build/BE tanpa login (+ --login: login/log-out)
#   scripts/e2e/run.sh ED-1234 --list                  # daftar test, tanpa jaringan dan tanpa login
#
# KEY menunjuk features/<KEY>-*; test fitur: <feature-dir>/qa/e2e/{routes.json,*.spec.js,fixtures.json,allowlist.json}.
# Memasang dependensi bila belum ada (tanpa unduhan browser), menyajikan FE_STAGING_DIR di E2E_PORT (bukan
# FE_SERVE_PORT, build/ tidak disentuh), login API per profil, dan selalu log-out di akhir.
# Exit: 0 = semua lulus/skip, 1 = ada GAGAL (atau global setup gagal, lihat pesannya),
# 2 = config/infra sebelum Playwright (config, build staging, argumen, npm ci), 3 = --doctor: profil akan di-SKIP.

set -uo pipefail

E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/env.sh
source "$E2E_DIR/../lib/env.sh" || exit $?

usage() { sed -n '2,17p' "$0"; exit 2; }
winpath() { (cd "$1" && { pwd -W 2>/dev/null || pwd; }); }

FEATURE=""
PROFILES="default"
ALL_ROUTES=0
LIST=0
DOCTOR=0
LOGIN=0
EXTRA=()
while [ $# -gt 0 ]; do
    case "$1" in
        -h|--help) usage ;;
        --profile|--profiles) PROFILES="${2:?--profile butuh nilai}"; shift 2 ;;
        --all-routes) ALL_ROUTES=1; shift ;;
        --include-add) export E2E_INCLUDE_ADD=1; shift ;;
        --workers) export E2E_WORKERS="${2:?--workers butuh angka}"; shift 2 ;;
        --list) LIST=1; shift ;;
        --doctor) DOCTOR=1; shift ;;
        --login) LOGIN=1; shift ;;
        --) shift; EXTRA=("$@"); break ;;
        -*) echo "Argumen tidak dikenal: $1" >&2; usage ;;
        *) [ -z "$FEATURE" ] || { echo "Hanya satu fitur per run: $FEATURE dan $1" >&2; usage; }; FEATURE="$1"; shift ;;
    esac
done
[ -n "$FEATURE" ] || [ "$DOCTOR" = "1" ] || usage

# --- Node: pakai versi yang aktif (JANGAN nvm use di sini; nvm-windows global dipakai agent lain).
NODE_MAJOR="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)"
if [ "$NODE_MAJOR" -lt 16 ]; then
    echo "Node $(node -v 2>/dev/null) terlalu lama: harness butuh Node >= 16 (Playwright 1.44.1)." >&2
    exit 2
fi

# --- Folder fitur (features/<KEY>-* atau path folder), diberikan ke Node sebagai path Windows
FEATURE_DIR=""
FEATURE_NAME="none"
if [ -n "$FEATURE" ] && [ "$FEATURE" != "none" ]; then
    FDIR="$(feature_dir "$FEATURE")" || exit 2
    FEATURE_DIR="$(winpath "$FDIR")"
    FEATURE_NAME="$(basename "$FDIR")"
    if [ "$DOCTOR" = "0" ] && [ "$ALL_ROUTES" = "0" ] \
        && [ ! -f "$FDIR/qa/e2e/routes.json" ] && ! ls "$FDIR"/qa/e2e/*.spec.js >/dev/null 2>&1; then
        echo "[e2e] $FEATURE_NAME tidak punya qa/e2e/routes.json maupun qa/e2e/*.spec.js" >&2
        exit 2
    fi
fi
if [ "$DOCTOR" = "0" ] && [ -z "$FEATURE_DIR" ] && [ "$ALL_ROUTES" = "0" ]; then
    echo "[e2e] Tidak ada test: beri fitur dan/atau --all-routes" >&2
    exit 2
fi

# --- Dependensi (sekali). Browser TIDAK diunduh: config memakai channel 'chrome' (Chrome sistem).
if [ ! -f "$E2E_DIR/node_modules/@playwright/test/package.json" ] && [ "$DOCTOR" = "0" ]; then
    echo "[e2e] memasang dependensi (npm ci, tanpa unduhan browser)..."
    (cd "$E2E_DIR" && PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm ci --no-audit --no-fund) || {
        echo "[e2e] npm ci gagal" >&2; exit 2; }
fi

STAMP="$(date +%Y%m%d-%H%M%S)"
export E2E_FEATURE_DIR="$FEATURE_DIR"
export E2E_FEATURE="$FEATURE_NAME"
export E2E_PROFILES="$PROFILES"
export E2E_ALL_ROUTES="$ALL_ROUTES"
export E2E_OUT_DIR="$(winpath "$AGENTIC_DIR")/work/e2e/$FEATURE_NAME-$STAMP"

cd "$E2E_DIR" || exit 2

if [ "$DOCTOR" = "1" ]; then
    node lib/doctor.js $([ "$LOGIN" = "1" ] && echo --login)
    exit $?
fi

if [ "$LIST" = "1" ]; then
    npx playwright test --list --reporter=list ${EXTRA[@]+"${EXTRA[@]}"}
    exit $?
fi

# --- Build FE staging wajib ada (tidak pernah memakai <FE_DIR>/build atau port FE_SERVE_PORT)
if [ -z "${FE_STAGING_DIR:-}" ]; then
    echo "[e2e] FE_STAGING_DIR belum diisi di config/workspace.env" >&2
    exit 2
fi
if [ ! -f "$FE_STAGING_DIR/index.html" ]; then
    echo "[e2e] Build FE staging tidak ada: $FE_STAGING_DIR/index.html" >&2
    echo "[e2e] Orchestrator: build dulu ke staging (±5 menit), JANGAN ke build/:" >&2
    echo "        cd ${FE_DIR:-<FE_DIR>} && BUILD_PATH=\"$FE_STAGING_DIR\" yarn build" >&2
    exit 2
fi

echo "[e2e] fitur=$FEATURE_NAME profil=$PROFILES all-routes=$ALL_ROUTES node=$(node -v)"
npx playwright test ${EXTRA[@]+"${EXTRA[@]}"}
STATUS=$?

if [ -f "$E2E_OUT_DIR/sessions.json" ]; then
    node -e '
        const s = require(process.argv[1]);
        (s.sessions || []).forEach((x) => console.log("[e2e] sesi", x.profile, x.skip ? "SKIP: " + x.skip : "db=" + x.db + " user=" + x.user_key + (x.notes && x.notes.length ? " (" + x.notes.join("; ") + ")" : "")));
        (s.logout || []).forEach((x) => console.log("[e2e] log-out", x.profile, x.error ? "ERROR " + x.error : "HTTP " + x.log_out + ", dicabut=" + (x.revoked ? "ya" : "TIDAK")));
    ' "$E2E_OUT_DIR/sessions.json"
fi
echo "[e2e] output: $E2E_OUT_DIR (index.html, report.json, screenshots/)"
exit $STATUS
