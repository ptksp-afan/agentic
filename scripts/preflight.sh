#!/usr/bin/env bash
# Prasyarat sebelum (atau saat melanjutkan) satu fitur. Exit 1 kalau ada FAIL; WARN tidak menghentikan.
#   scripts/preflight.sh
set -uo pipefail
source "$(dirname "$0")/lib/env.sh"
fail=0
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
exit $fail
