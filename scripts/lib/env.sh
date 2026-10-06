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

# Path gaya Git Bash (/d/dev/...) dari path Windows (D:/dev/...), untuk cd dan perintah unix.
to_unix_path() { local p="${1//\\//}"; if [[ "$p" =~ ^([A-Za-z]):(.*)$ ]]; then echo "/${BASH_REMATCH[1],,}${BASH_REMATCH[2]}"; else echo "$p"; fi; }

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
