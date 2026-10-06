#!/usr/bin/env bash
# Pergantian profil lisensi + DB default secara BERPASANGAN. Lisensi dan DB tidak boleh berganti
# sendiri-sendiri (server setting dengan lisensi yang salah di DB yang salah mencemari data).
#
#   scripts/profile.sh status   # baris 1 = profil aktif; exit 0 = default, 4 = profil lain aktif, 2 = error
#   scripts/profile.sh use <profil>
#   scripts/profile.sh run <profil> -- <perintah ...>   # ganti, jalankan, SELALU kembali ke PROFILE_DEFAULT
#
# Config (config/workspace.env): PROFILES=a,b  PROFILE_DEFAULT=a  BE_DIR  PHP_BIN  API_URL  RR_RELOAD
#   PROFILE_<nama>_LIC_DIR   folder berisi EqualERP.lic dan ERPHelper.dat untuk profil itu
#   PROFILE_<nama>_DB        nilai DB_DATABASE di .env BE_DIR untuk profil itu
#   PROFILE_<nama>_INTEGRATION  (opsional) 0|1: harapan equal_integration pada probe lisensi
# PROFILES kosong = fitur nonaktif: `status` exit 0 ("profiles disabled"), `use`/`run` exit 2 tanpa mengubah apa pun.
#
# Yang diubah di BE_DIR: app/Lib/EqualERP.lic, app/Lib/ERPHelper.dat dan baris DB_DATABASE di .env
# (ketiganya tidak masuk git), lalu reload RR bila RR_RELOAD=1. Selama profil non-default aktif, SEMUA
# request ke server itu memakai lisensi dan DB profil tsb. Jalankan satu uji sekaligus.
# Perintah di bawah `run` menerima env PROFILE_ACTIVE=<profil> (dipakai scripts/qa-http/run.php).

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/env.sh
source "$SCRIPT_DIR/lib/env.sh" || exit $?
set -euo pipefail

LIC_FILES="EqualERP.lic ERPHelper.dat"
PHP="${PHP_BIN:-php}"

die() { echo "$*" >&2; exit 2; }

profiles() { local p="${PROFILES//,/ }"; echo $p; }          # tanpa kutip: normalisasi spasi
cfg() { local v="PROFILE_$1_$2"; echo "${!v:-}"; }

disabled() { [ -z "$(profiles)" ]; }
if disabled; then
  echo "profiles disabled (PROFILES kosong di config/workspace.env)"
  # `status` boleh lulus; `use`/`run` gagal supaya uji profil tidak terlihat lulus padahal tidak jalan.
  case "${1:-}" in use|run) echo "perintah tidak dijalankan: profil nonaktif" >&2; exit 2;; esac
  exit 0
fi

BE="${BE_DIR:-}"          # Git Bash menerima D:/dev/... apa adanya
[ -d "$BE" ] || die "BE_DIR tidak ada: ${BE_DIR:-<kosong>}"
[ -n "${PROFILE_DEFAULT:-}" ] || die "PROFILES diisi tapi PROFILE_DEFAULT kosong"
API="${API_URL:-}"

known() { local p; for p in $(profiles); do [ "$p" = "$1" ] && return 0; done; return 1; }
known "$PROFILE_DEFAULT" || die "PROFILE_DEFAULT '$PROFILE_DEFAULT' tidak ada di PROFILES ($(profiles))"

profile_ok() { # validasi satu profil: nama, folder lisensi, DB
  local name="$1" dir db f
  [[ "$name" =~ ^[A-Za-z0-9_]+$ ]] || { echo "Nama profil tidak valid: $name" >&2; return 2; }
  known "$name" || { echo "Profil tidak dikenal: $name (pakai: $(profiles))" >&2; return 2; }
  dir="$(cfg "$name" LIC_DIR)"; db="$(cfg "$name" DB)"
  [ -n "$dir" ] && [ -n "$db" ] || { echo "PROFILE_${name}_LIC_DIR / PROFILE_${name}_DB belum diisi" >&2; return 2; }
  [[ "$db" =~ ^[A-Za-z0-9_.-]+$ ]] || { echo "PROFILE_${name}_DB tidak valid" >&2; return 2; }
  for f in $LIC_FILES; do [ -f "$dir/$f" ] || { echo "Berkas lisensi tidak ada: $dir/$f" >&2; return 1; }; done
}

current_profile() {
  local name dir f same
  for name in $(profiles); do
    dir="$(cfg "$name" LIC_DIR)"; same=1
    for f in $LIC_FILES; do cmp -s "$dir/$f" "$BE/app/Lib/$f" || same=0; done
    [ "$same" = 1 ] && { echo "$name"; return; }
  done
  echo "lain"
}

env_db() { grep -E '^DB_DATABASE=' "$BE/.env" | head -n 1 | cut -d= -f2- | tr -d '\r'; }
probe() { "$PHP" "$SCRIPT_DIR/lib/profile-probe.php" "$BE"; }
http_code() { curl -s -o /dev/null -m "$1" -w '%{http_code}' "$API/" || true; }

reload_rr() {
  [ "${RR_RELOAD:-}" = "1" ] || return 0
  # Worker RR sesekali crash saat reload; ulangi sekali.
  if ! (cd "$BE" && "$PHP" artisan equal:rr-reload); then
    echo "rr-reload gagal, mencoba sekali lagi..." >&2
    (cd "$BE" && "$PHP" artisan equal:rr-reload)
  fi
}

# Baris pertama = nama profil aktif (menurut berkas lisensi; "lain" bila tidak cocok profil mana pun).
# Exit 0 = aktif == PROFILE_DEFAULT, 4 = profil lain/tak dikenal aktif, 2 = config/probe error.
status() {
  local cur line rc=0
  cur="$(current_profile)"
  echo "$cur"
  echo "default : $PROFILE_DEFAULT"
  echo ".env    : DB_DATABASE=$(env_db)"
  line="$(probe 2>&1)" || rc=2
  echo "probe   : $line"
  [ -z "$API" ] || echo "server  : $(http_code 5) ($API)"
  [ "$rc" -eq 0 ] || exit 2
  [ "$cur" = "$PROFILE_DEFAULT" ] || exit 4
}

use_profile() {
  local name="$1" dir db want line f
  profile_ok "$name" || return 1
  dir="$(cfg "$name" LIC_DIR)"; db="$(cfg "$name" DB)"; want="$(cfg "$name" INTEGRATION)"
  grep -qE '^DB_DATABASE=' "$BE/.env" || { echo "Baris DB_DATABASE tidak ada di .env" >&2; return 1; }

  for f in $LIC_FILES; do cp "$dir/$f" "$BE/app/Lib/$f"; done
  sed -i -E "s/^DB_DATABASE=[^\r]*/DB_DATABASE=$db/" "$BE/.env"

  reload_rr

  line="$(probe)" || { echo "${line:-probe gagal}" >&2; echo "VERIFIKASI GAGAL: probe lisensi" >&2; return 1; }
  echo "$line"
  case "$line" in
    "equal_integration="*" db=$db "*) ;;
    *) echo "VERIFIKASI GAGAL: harap db=$db" >&2; return 1 ;;
  esac
  if [ -n "$want" ]; then
    case "$line" in
      "equal_integration=$want "*) ;;
      *) echo "VERIFIKASI GAGAL: harap equal_integration=$want" >&2; return 1 ;;
    esac
  fi
  if [ -n "$API" ] && [ "$(http_code 10)" = "000" ]; then echo "Server $API tidak menjawab" >&2; return 1; fi
  echo "OK: profil $name aktif (lisensi + DB $db)"
}

on_exit() {
  local rc=$?
  trap - EXIT
  echo "Mengembalikan ke $PROFILE_DEFAULT..." >&2
  if ! use_profile "$PROFILE_DEFAULT"; then
    echo "PERINGATAN: gagal mengembalikan ke $PROFILE_DEFAULT. Jalankan: scripts/profile.sh use $PROFILE_DEFAULT" >&2
    [ "$rc" -ne 0 ] || rc=3
  fi
  exit "$rc"
}

case "${1:-}" in
  status)
    status
    ;;
  use)
    name="${2:?pakai: profile.sh use <profil>}"
    use_profile "$name"
    [ "$name" = "$PROFILE_DEFAULT" ] || echo "Ingat: kembalikan ke default dengan: scripts/profile.sh use $PROFILE_DEFAULT" >&2
    ;;
  run)
    name="${2:?pakai: profile.sh run <profil> -- <perintah>}"
    shift 2
    [ "${1:-}" = "--" ] && shift
    [ "$#" -gt 0 ] || die "Tidak ada perintah untuk dijalankan"
    profile_ok "$name" || exit 2
    profile_ok "$PROFILE_DEFAULT" || exit 2
    trap on_exit EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM
    use_profile "$name"
    rc=0
    PROFILE_ACTIVE="$name" "$@" || rc=$?
    exit "$rc"
    ;;
  *)
    sed -n '2,18p' "$0"
    exit 2
    ;;
esac
