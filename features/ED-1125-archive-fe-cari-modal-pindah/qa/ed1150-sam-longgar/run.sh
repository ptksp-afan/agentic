#!/usr/bin/env bash
# QA ED-1125 r2: regresi ED-1150 AC-6/7/8 sebagai `sam` (QA_USER3) saat root sam bukan lagi 3 baris (developer menambah "Folder X").
# Spec ED-1150 asli di-SKIP (BLOCKED-by-data: guard `rows.length === 3`). Skrip ini menyalin spec itu ke folder scratch, menerapkan
# guard-root.diff (hanya baris guard `requireRoot`: root wajib memuat Folder A, tanpa syarat jumlah baris), lalu menjalankannya
# dengan pemetaan profil sam yang sama dengan features/ED-1150-*/qa/e2e/run-sam.sh. Tidak mengubah spec ED-1150 (ED-1149 BR-7).
#   features/ED-1125-*/qa/ed1150-sam-longgar/run.sh [-- --grep "AC-6|AC-7|AC-8"]
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
AGENTIC_DIR="$(cd "$HERE/../../../.." && pwd)"
source "$AGENTIC_DIR/scripts/lib/env.sh" || exit $?
[ -n "${QA_USER3:-}" ] && [ -n "${QA_PASS3:-}" ] || { echo "QA_USER3/QA_PASS3 kosong di config/secrets.env" >&2; exit 2; }
export QA_SAM_USER="$QA_USER3" QA_SAM_PASS="$QA_PASS3"
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/ed1150-longgar/qa/e2e"
SRC="$AGENTIC_DIR/features/ED-1150-archive-fe-virtualtable-list-pendek/qa/e2e/ED-1150-virtualtable-list-pendek.spec.js"
cp "$SRC" "$WORK/ed1150-longgar/qa/e2e/ED-1150-virtualtable-list-pendek.spec.js"
patch -s "$WORK/ed1150-longgar/qa/e2e/ED-1150-virtualtable-list-pendek.spec.js" "$HERE/guard-root.diff" || { echo "guard-root.diff tidak cocok lagi dengan spec ED-1150" >&2; exit 2; }
"$AGENTIC_DIR/scripts/e2e/run.sh" "$WORK/ed1150-longgar" --profile sam --workers 1 "$@"
