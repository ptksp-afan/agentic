#!/usr/bin/env bash
# Dimuat (source) oleh semua script bash di agentic/. Tidak dijalankan langsung.
#   source "$(dirname "$0")/lib/env.sh"        # dari scripts/*.sh
# Urutan: config/workspace.env -> config/secrets.env -> env proses (env proses menang).
# Mengekspor AGENTIC_DIR dan semua kunci config. Tidak pernah mencetak nilai rahasia.

AGENTIC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
export AGENTIC_DIR

_agentic_load() { # $1 = file; kunci yang sudah ada di env proses tidak ditimpa
  local f="$1" line key val
  [ -f "$f" ] || return 0
  while IFS= read -r line || [ -n "$line" ]; do
    line="${line%$'\r'}"
    case "$line" in ''|\#*) continue ;; esac
    key="${line%%=*}"; val="${line#*=}"
    key="${key//[[:space:]]/}"
    [[ "$key" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || continue
    val="${val%%[[:space:]]#*}"                       # buang komentar di akhir baris
    val="${val#"${val%%[![:space:]]*}"}"; val="${val%"${val##*[![:space:]]}"}"
    val="${val%\"}"; val="${val#\"}"
    [ -n "${!key+x}" ] && continue
    export "$key=$val"
  done < "$f"
}

_agentic_load "$AGENTIC_DIR/config/workspace.env"
_agentic_load "$AGENTIC_DIR/config/secrets.env"

if [ ! -f "$AGENTIC_DIR/config/workspace.env" ]; then
  echo "config/workspace.env belum ada. Salin dari config/workspace.env.example lalu isi." >&2
  return 2 2>/dev/null || exit 2
fi

# Path gaya Git Bash (/d/dev/...) dari path Windows (D:/dev/... atau D:\dev\...). Path Linux/macOS
# dikembalikan apa adanya. Kompatibel bash 3.2 (bawaan macOS): tanpa ${x,,}, mapfile, declare -A.
to_unix_path() {
  local p="${1//\\//}" d
  if [[ "$p" =~ ^([A-Za-z]):(.*)$ ]]; then
    d="$(printf '%s' "${BASH_REMATCH[1]}" | tr '[:upper:]' '[:lower:]')"; echo "/$d${BASH_REMATCH[2]}"
  else echo "$p"; fi
}

# features/<KEY>-* -> path folder fitur. Menerima KEY (ED-1234) atau path folder.
feature_dir() {
  local arg="$1" hits
  if [ -d "$arg" ]; then (cd "$arg" && pwd); return 0; fi
  hits=$(ls -d "$AGENTIC_DIR/features/$arg"-*/ 2>/dev/null)
  if [ "$(printf '%s\n' "$hits" | grep -c .)" != "1" ]; then
    echo "Folder fitur untuk '$arg' tidak ditemukan atau tidak unik di features/." >&2; return 1
  fi
  (cd "$hits" && pwd)
}

# Reload server BE sesudah kode PHP atau .env berubah. Satu pintu untuk RoadRunner dan php-fpm.
#   BE_SERVER=rr  : worker RR memuat kode sekali -> artisan equal:rr-reload (dicoba 2x; kadang crash)
#   BE_SERVER=fpm : php-fpm/Apache/artisan serve membaca berkas tiap request -> tidak perlu, kecuali
#                   BE_RELOAD_CMD diisi (mis. opcache.validate_timestamps=0 -> reload php-fpm)
be_reload() {
  local be i; be="$(to_unix_path "$BE_DIR")"
  case "${BE_SERVER:-}" in
    rr)  for i in 1 2; do
           (cd "$be" && "$PHP_BIN" artisan equal:rr-reload >/dev/null 2>&1) && { echo "BE: RoadRunner di-reload"; return 0; }
         done
         echo "BE: equal:rr-reload gagal 2x - reload manual, hasil uji bisa memakai kode lama" >&2; return 1 ;;
    fpm) if [ -n "${BE_RELOAD_CMD:-}" ]; then (cd "$be" && eval "$BE_RELOAD_CMD") && echo "BE: $BE_RELOAD_CMD" || return 1
         else echo "BE: php-fpm, tidak perlu reload"; fi ;;
    *)   echo "BE_SERVER harus 'rr' atau 'fpm' (config/workspace.env)" >&2; return 2 ;;
  esac
}
