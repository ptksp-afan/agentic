#!/usr/bin/env bash
# ED-1150: jalankan e2e sebagai profil `sam` (= QA_USER3/QA_PASS3, DB QA_DB).
# Harness memetakan profil <nama> ke QA_<NAMA>_USER/_PASS, kredensial sam ada di QA_USER3/QA_PASS3, jadi pemetaan dibuat di
# env proses ini saja (tidak ditulis ke berkas, tidak dicetak). Kode harness bersama tidak diubah.
#
#   qa/e2e/run-sam.sh [opsi run.sh]                 # spec fitur, profil sam
#   qa/e2e/run-sam.sh --with-default [opsi]         # profil sam + default (AC-7 fallback bila sam tanpa akses Move)
#   qa/e2e/run-sam.sh --prereq                      # cek prasyarat data lewat HTTP (baca-saja), login sam lalu log-out
#   qa/e2e/run-sam.sh -- --grep "AC-1"              # argumen sesudah -- ke playwright
# Sesi sam dibuat dengan force_login=1 bila perlu (K-4 a) dan di-log-out harness di akhir (dicek: auth/info -> 401).
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
AGENTIC_DIR="$(cd "$HERE/../../../.." && pwd)"
# shellcheck source=../../../../scripts/lib/env.sh
source "$AGENTIC_DIR/scripts/lib/env.sh" || exit $?

if [ -z "${QA_USER3:-}" ] || [ -z "${QA_PASS3:-}" ]; then
    echo "[run-sam] QA_USER3/QA_PASS3 belum diisi di config/secrets.env" >&2
    exit 2
fi
export QA_SAM_USER="$QA_USER3" QA_SAM_PASS="$QA_PASS3"

if [ "${1:-}" = "--prereq" ]; then
    exec node "$HERE/prereq-sam.js"
fi

PROFILES="sam"
if [ "${1:-}" = "--with-default" ]; then PROFILES="sam,default"; shift; fi
exec "$AGENTIC_DIR/scripts/e2e/run.sh" ED-1150 --profile "$PROFILES" "$@"
