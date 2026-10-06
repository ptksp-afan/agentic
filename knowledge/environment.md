# Lingkungan lokal (mesin developer, Windows + Git Bash)

Nilai yang bisa berbeda per mesin ada di `config/workspace.env`; berkas ini menjelaskan **kenapa**.
Dibaca agent hanya saat butuh.

| Hal | Fakta | Catatan |
|---|---|---|
| Shell | Git Bash untuk semua perintah (`grep`, `ls`, `$(cat .nvmrc)`) | PowerShell/cmd tidak dipakai agent |
| PHP | `PHP_BIN` = php-nts 7.3.33, binary yang sama dengan worker RoadRunner | `php` di PATH = 7.3.11 ZTS, jangan dipakai untuk `php -l`, artisan, runner (developer, 2026-09-28) |
| Server BE | RoadRunner per checkout; port di `<BE_DIR>/.rr.env` (`RR_HTTP_ADDRESS`). `ksp-erp-next` (v5-rr) = `:8002`, `ksp-erp-next-release` = `:8003`, `ksp-erp-next-multidb` = `:8004` | cek hidup: `curl -s -o /dev/null -w '%{http_code}' $API_URL/` → 302 |
| Reload RR | `"$PHP_BIN" artisan equal:rr-reload` di `BE_DIR` | kadang crash `0xC0000005`: ulangi |
| DB | MySQL di WSL `kspdb.wsl:3307`, **dipakai bersama semua checkout** | DB uji boleh dikotori (ada backup), tapi QA memulihkan yang diubah. Pilih `QA_DB` yang tidak dipakai pipeline multidb |
| `route:list` | gagal di checkout ini (constructor controller membaca config central) | pakai one-liner router di `v5-be-conventions` `verification.md` |
| Node FE | versi hanya di `<FE_DIR>/.nvmrc`; `nvm use $(cat .nvmrc)`, **jangan** tulis angka versi | nvm-windows mengganti versi global: jangan paralel dengan proyek Node lain |
| Build FE | `scripts/fe-build.sh build` → `FE_STAGING_DIR` | developer menyajikan `<FE_DIR>/build` dengan `serve .\build\ -p <FE_SERVE_PORT>` dan membukanya dari Mac lewat SSH |
| `force_login` | boleh dipakai QA/dev saat bind DB (developer, 2026-09-29) | bisa me-logout sesi developer kalau user-nya sama |
| Browser e2e | Playwright + Chrome sistem, headless, profil sementara, port `E2E_PORT` | tidak pernah memakai profil Chrome developer |
| Daya | PC Windows: sleep "never", hibernate otomatis 3 jam (AC) | task panjang bisa terhenti; restart/Windows Update mematikan agent |
| Akses | developer bekerja dari Mac lewat SSH ke PC Windows; sesi Claude berjalan di Windows | mematikan Mac tidak menghentikan agent; gate menunggu sampai developer kembali |

## Checkout yang dipakai bersama pipeline multidb

| | Workflow general (berkas ini) | Pipeline multidb |
|---|---|---|
| BE | `D:\dev\ksp-erp-next` @ `v5-rr`, `:8002` | `D:\dev\ksp-erp-next-multidb` @ `v5-rr-multidb`, `:8004` |
| FE | `D:\dev\ksp-react-v5` @ `next-canvasing` (worktree) | `D:\dev\ksp-react` @ `next-canvasing-multidb-ai` |
| `serve` | `serve .\build\ -p 4001` (`FE_SERVE_PORT`) | `serve .\build\ -p 4000` |
