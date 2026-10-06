#!/usr/bin/env bash
# Prasyarat sebelum (atau saat melanjutkan) satu fitur. Exit 1 kalau ada FAIL; WARN tidak menghentikan.
#   scripts/preflight.sh               cek biasa
#   scripts/preflight.sh --long-run    + cek untuk long run (hibernate, branch park/*)
set -uo pipefail
source "$(dirname "$0")/lib/env.sh"
fail=0; long=0; [ "${1:-}" = "--long-run" ] && long=1
ok()   { printf 'OK    %s\n' "$*"; }
warn() { printf 'WARN  %s\n' "$*"; }
bad()  { printf 'FAIL  %s\n' "$*"; fail=1; }

for k in BE_DIR BE_BRANCH FE_DIR FE_BRANCH PHP_BIN API_URL FE_STAGING_DIR FE_SERVE_PORT E2E_PORT QA_DB; do
  [ -n "${!k:-}" ] || bad "config: $k kosong di config/workspace.env"
done
for pair in "be:BE_DIR:BE_BRANCH" "fe:FE_DIR:FE_BRANCH"; do
  IFS=: read -r n d b <<< "$pair"; repo="$(to_unix_path "${!d}")"
  if git -C "$repo" rev-parse --git-dir >/dev/null 2>&1; then
    cur="$(git -C "$repo" branch --show-current)"
    [ "$cur" = "${!b}" ] && ok "$n branch $cur ($repo)" || bad "$n branch '$cur', seharusnya '${!b}' ($repo)"
  else bad "$n bukan repo git: $repo"; fi
done
[ -x "$(to_unix_path "$PHP_BIN")" ] || [ -f "$(to_unix_path "$PHP_BIN")" ] && ok "PHP_BIN ada" || bad "PHP_BIN tidak ada: $PHP_BIN"
code="$(curl -s -o /dev/null -m 10 -w '%{http_code}' "$API_URL/")"
case "$code" in
  000) bad "API $API_URL tidak menjawab (server BE mati?)" ;;
  5*)  warn "API $API_URL -> $code (server hidup tapi error; cek log BE / lisensi)" ;;
  *)   ok "API $API_URL -> $code" ;;
esac

be="$(to_unix_path "$BE_DIR")"; fe="$(to_unix_path "$FE_DIR")"

# Mode server BE harus cocok dengan kenyataan: RR yang tidak di-reload menguji kode lama.
api_port="$(printf '%s' "$API_URL" | sed -E 's#^[a-z]+://[^/:]+:?([0-9]*).*#\1#')"
rr_port="$(grep -h '^RR_HTTP_ADDRESS' "$be/.rr.env" 2>/dev/null | sed -E 's/.*:([0-9]+).*/\1/' | head -1)"
has_rr=0; [ -f "$be/app/RoadRunner/AppState.php" ] && has_rr=1
case "${BE_SERVER:-}" in
  rr)  if [ $has_rr = 0 ]; then bad "BE_SERVER=rr tapi branch $BE_BRANCH tidak punya app/RoadRunner/ -> pakai BE_SERVER=fpm"
       elif [ -n "$rr_port" ] && [ -n "$api_port" ] && [ "$rr_port" != "$api_port" ]; then warn "API_URL port $api_port, RR_HTTP_ADDRESS di .rr.env port $rr_port: yakin servernya RR milik BE_DIR?"
       else ok "BE_SERVER=rr (reload: artisan equal:rr-reload)"; fi ;;
  fpm) if [ -n "$rr_port" ] && [ "$rr_port" = "$api_port" ]; then bad "API_URL menunjuk RoadRunner (.rr.env port $rr_port) tapi BE_SERVER=fpm: kode baru tidak akan termuat"
       else ok "BE_SERVER=fpm${BE_RELOAD_CMD:+ (reload: $BE_RELOAD_CMD)}"; fi ;;
  *)   bad "BE_SERVER harus 'rr' atau 'fpm'" ;;
esac
[ -f "$be/bootstrap/cache/config.php" ] && warn "config Laravel di-cache (bootstrap/cache/config.php): perubahan .env/profil tidak terbaca sampai config:clear"

for f in "$be/.claude/skills/v5-be-conventions/SKILL.md" "$be/.claude/agents/v5-be-dev.md" "$be/.claude/agents/v5-be-reviewer.md" \
         "$fe/CLAUDE.md" "$fe/.claude/skills/equal-conventions/references/auto-mode.md" "$fe/.claude/agents/convention-reviewer.md" \
         "$fe/.claude/agents/api-contract-analyst.md" "$fe/.claude/skills/ui-logic/SKILL.md"; do
  [ -f "$f" ] || bad "berkas workflow tidak ada di branch yang ter-checkout: $f"
done
[ $fail = 0 ] && ok "skill/agent BE+FE ada"

if [ -n "${PROFILES:-}" ]; then
  s="$("$AGENTIC_DIR/scripts/profile.sh" status 2>&1)"; rc=$?
  [ $rc = 0 ] && ok "profil: $(echo "$s" | head -1)" || bad "profil aktif bukan '$PROFILE_DEFAULT': $(echo "$s" | head -1) -> scripts/profile.sh use $PROFILE_DEFAULT"
fi
[ -f "$AGENTIC_DIR/config/secrets.env" ] && grep -q '^QA_USER=.\+' "$AGENTIC_DIR/config/secrets.env" && ok "secrets.env berisi QA_USER" || warn "config/secrets.env belum berisi QA_USER (QA HTTP/e2e akan BLOCKED)"
loc="$AGENTIC_DIR/.claude/settings.local.json"
if [ -f "$loc" ] && grep -q "additionalDirectories" "$loc"; then ok "settings.local.json: additionalDirectories ada"
else warn "settings.local.json tanpa additionalDirectories: agent/skill repo BE/FE tidak termuat by-name (fallback baca berkas)"; fi
[ -d "$(dirname "$(to_unix_path "$FE_STAGING_DIR")")" ] || bad "induk FE_STAGING_DIR tidak ada"

# Chrome untuk QA FE e2e dan render desain (Playwright channel 'chrome', atau CHROME_PATH). Daftar = lib/doctor.js.
chrome=""
if [ -n "${CHROME_PATH:-}" ]; then [ -f "$(to_unix_path "$CHROME_PATH")" ] && chrome="$CHROME_PATH"   # diisi = wajib benar
else
  for c in "${PROGRAMFILES:+$PROGRAMFILES/Google/Chrome/Application/chrome.exe}" \
           "${LOCALAPPDATA:+$LOCALAPPDATA/Google/Chrome/Application/chrome.exe}" /usr/bin/google-chrome \
           /usr/bin/google-chrome-stable /opt/google/chrome/chrome "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"; do
    [ -n "$c" ] && [ -f "$(to_unix_path "$c")" ] && { chrome="$c"; break; }
  done
fi
if [ -n "$chrome" ]; then ok "Chrome: $chrome"
elif [ -n "${CHROME_PATH:-}" ]; then bad "CHROME_PATH tidak ada: $CHROME_PATH"
elif [ $long = 1 ]; then bad "Chrome tidak ditemukan: QA FE e2e butuh Chrome (pasang, atau isi CHROME_PATH di config/workspace.env)"
else warn "Chrome tidak ditemukan: QA FE e2e dan render desain akan gagal (pasang, atau isi CHROME_PATH)"; fi

if [ $long = 1 ]; then
  # Sleep/hibernate otomatis menghentikan long run di tengah jalan (mesin tanpa input berjam-jam).
  # Hanya dicek dan dilaporkan; setelan daya tidak pernah diubah oleh script.
  case "$(uname -s)" in
    MINGW*|MSYS*|CYGWIN*)
      for s in HIBERNATEIDLE:hibernate-timeout-ac STANDBYIDLE:standby-timeout-ac; do
        q="${s%%:*}"; fix="${s#*:}"
        v="$(MSYS_NO_PATHCONV=1 powercfg /q SCHEME_CURRENT SUB_SLEEP "$q" 2>/dev/null | grep 'AC' | grep -o '0x[0-9a-fA-F]*' | head -1)"
        if [ -n "$v" ] && [ $((v)) -gt 0 ]; then warn "Windows $q (AC) setelah $(( v / 60 )) menit: long run bisa terhenti -> powercfg /change $fix 0"
        else ok "Windows $q (AC): mati"; fi
      done ;;
    Darwin)
      v="$(pmset -g 2>/dev/null | awk '$1=="sleep"{print $2; exit}')"
      if [ -n "$v" ] && [ "$v" != 0 ]; then warn "macOS sleep setelah $v menit: jalankan long run dengan 'caffeinate -i' atau set sleep 0 di Energy settings"
      else ok "macOS sleep: mati"; fi ;;
    Linux)
      if command -v systemctl >/dev/null 2>&1 && systemctl is-enabled sleep.target >/dev/null 2>&1; then
        warn "Linux: sleep.target aktif; pastikan mesin tidak suspend (mis. systemd-inhibit selama long run)"
      else ok "Linux: sleep.target tidak aktif"; fi ;;
  esac
  for pair in "be:BE_DIR" "fe:FE_DIR"; do
    IFS=: read -r n d <<< "$pair"; parks="$(git -C "$(to_unix_path "${!d}")" branch --list 'park/*' --format='%(refname:short)' | tr '\n' ' ')"
    [ -n "$parks" ] && warn "$n: kode item yang diparkir: $parks" || true
  done
fi
exit $fail
