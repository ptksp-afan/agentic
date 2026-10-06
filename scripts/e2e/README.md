# E2E browser (Playwright)

Membuka layar FE v5 di Chrome sistem (headless) per profil sesi, mengumpulkan console error, page error,
respons API >= 400 dan request gagal, menyimpan screenshot, lalu menjalankan spec fitur.

## Pemakaian

```bash
scripts/e2e/run.sh ED-1234                      # smoke routes.json + spec fitur, profil default
scripts/e2e/run.sh ED-1234 --profile default,user2
scripts/e2e/run.sh none --all-routes            # semua layar FE (lambat, opt-in)
scripts/e2e/run.sh ED-1234 --list               # daftar test (tanpa jaringan/login)
scripts/e2e/run.sh --doctor [--login]           # cek config, Chrome, build, BE, CORS; --login = login/log-out
scripts/e2e/run.sh ED-1234 -- --grep logo       # argumen sesudah -- ke playwright
```

Argumen pertama: `<KEY>` (folder `features/<KEY>-*`), path folder fitur, atau `none`. Opsi lain: `--workers N`,
`--include-add` (`--all-routes` ikut layar `/add`). Exit: 0 lulus/skip, 1 ada GAGAL, 2 config/infra sebelum
Playwright (build staging tidak ada, argumen salah, `npm ci` gagal), 3 `--doctor` dengan profil yang akan di-SKIP.

`run.sh` memasang dependensi sekali (`npm ci`, tanpa unduhan browser). Prasyarat: Node >= 16.20, Chrome terpasang,
`config/workspace.env` dan `config/secrets.env` terisi, **build FE staging** di `FE_STAGING_DIR` (orchestrator
membangunnya: `BUILD_PATH=<FE_STAGING_DIR> yarn build`, tidak pernah ke `build/`). Harness menyajikan staging itu di
`E2E_PORT` (default 4100) lewat server statis sendiri; `build/` dan `FE_SERVE_PORT` milik developer tidak disentuh
(config menolak keduanya). Build lebih tua dari commit FE terakhir atau FE kotor = peringatan di awal run
(`E2E_REQUIRE_FRESH_BUILD=1` menjadikannya gagal).

## Profil

Profil = satu user + satu DB = satu token per run; namanya = tag `[nama]` di judul test. Didefinisikan config:

| Profil | User/password (secrets.env) | DB |
|---|---|---|
| `default` | `QA_USER` / `QA_PASS` | `QA_DB` |
| `user2` | `QA_USER2` / `QA_PASS2` (user berhak lebih sedikit) | `QA_DB` |
| `<nama>` | `QA_<NAMA>_USER` / `QA_<NAMA>_PASS` | `E2E_PROFILE_<nama>_DB`, lalu `PROFILE_<nama>_DB`, lalu `QA_DB` |

Kredensial kosong, atau DB di luar `QA_DBS`, = profil di-SKIP dengan pesan jelas (tanpa login). Tanpa DB di config,
dipakai satu-satunya DB user. Profil lisensi (`PROFILES` di workspace.env): jalankan di dalam
`scripts/profile.sh run <nama> -- scripts/e2e/run.sh <KEY> --profile <nama>`.

Login lewat API (`auth/token` -> `auth/database` -> `PUT auth/database`), localStorage diisi seperti sign-in UI. Bila
user sudah punya sesi di DB itu (GS0500) diulang dengan `force_login=1` dan dicatat (`E2E_FORCE_LOGIN=0` mematikan).
Di akhir semua token di-log-out dan pencabutannya diverifikasi. Token hanya di memori; semua keluaran disamarkan.

## Test fitur: `features/<KEY>-<slug>/qa/e2e/`

| Berkas | Isi |
|---|---|
| `routes.json` | daftar layar untuk smoke (tiap route x profil) |
| `*.spec.js` | flow fitur (Playwright) |
| `fixtures.json` | opsional: perintah setup/teardown data |
| `allowlist.json` | opsional: error lama yang diizinkan |

Contoh: `templates/qa/e2e/`.

### routes.json

```json
{ "feature": "ED-1234", "routes": [
  { "name": "Daftar X", "path": "/setups/x/x", "waitFor": ".ant-table", "ref": "AC-1" },
  { "name": "Ditolak", "path": "/setups/y/y", "profiles": ["user2"], "expectRedirect": "/unathorized", "forbidApi": "y/ys" }
] }
```

`path` wajib ada di `<FE_DIR>/src/routes/paths.js` (salah ketik = error saat load; path `:param` tidak bisa).
`profiles` opsional (default: semua profil run). `waitFor` = selector yang wajib tampil. `expectRedirect` = FE harus
mengalihkan ke path itu. `forbidApi` = regex URL API yang tidak boleh terpanggil. `allow` (array allow-list) boleh ada.

### Menulis spec fitur

```js
const { test, expect } = require('e2e-harness'); // juga: require('@playwright/test') (instans yang sama)
test('[default] ED-1234 ...', async ({ app, page, monitor, session }) => { ... });
```

- Judul **wajib** diawali tag profil `[default]` / `[user2]` / `[<nama>]`; tanpa tag test tidak jalan.
- Fixture: `app` (`open(path, {waitFor})`, `settle()`, `screenshot(nama)`, `currentPath()`, `redirectOf()`,
  `imageInfo(locator)`), `monitor` (`check()`, `apiResponses()`, `note()`), `session` (`dbName`, `userKey`, `info`), `page`.
- Error console/page/HTTP >= 400 dicek **otomatis** di akhir tiap test.
- Flow **non-mutasi**, atau kembalikan persis apa yang diubah (hitung baris/byte sebelum dan sesudah). Data yang
  perlu ada sebelum flow dibuat lewat `fixtures.json`, bukan dari spec.
- `require('e2e-harness').config`: `get(KEY)`, `featureDir()`, `redact()`.

### fixtures.json (opsional)

```json
{ "fixtures": [ { "name": "data-qa", "reason": "butuh 3 baris QA",
  "setup": "\"$PHP_BIN\" scripts/qa-http/run.php \"$FEATURE_DIR\" --only=FIX-UP",
  "teardown": "\"$PHP_BIN\" scripts/qa-http/run.php \"$FEATURE_DIR\" --only=FIX-DOWN", "profiles": ["default"] } ] }
```

`setup` jalan sebelum sesi browser dibuka, `teardown` sesudah log-out (urutan terbalik); lewat `bash -c` dari `agentic/`
(`cwd`, `timeout_s` opsional), env = semua kunci config + `FEATURE_DIR` + `AGENTIC_DIR`. Setup gagal = fixture yang naik
dibuang lagi dan run berhenti; teardown gagal = run gagal. `E2E_FIXTURES=0` melewati semuanya.

### Allow-list

Hanya untuk error **lama** yang bukan buatan fitur ini; error baru dari fitur harus diperbaiki. Entri wajib punya
`id`, `kind` (`console|pageerror|response|requestfailed|timeout|*`), `match` (regex), `reason` dan `ref` (tiket Jira).
Opsional `status`, `profiles`, `paths`, `since`. Sumber: `scripts/e2e/allowlist.json` (global),
`qa/e2e/allowlist.json` (fitur), `allow` di routes.json, `test.use({ allow: [...] })`. Error yang cocok tetap tercatat di
laporan sebagai "allow-list". Format lengkap: `lib/allowlist.js`.

## Keluaran

`agentic/work/e2e/<fitur>-<tanggal-jam>/` (gitignored): `index.html` (galeri screenshot + error per test, dilihat
developer di Gate 2), `report.json`, `screenshots/<profil>/*.png` (`GAGAL-*.png` = layar saat flow gagal),
`staging.json`, `sessions.json` (tanpa token).

## Env opsional

`E2E_WORKERS` (2), `E2E_TEST_TIMEOUT` (120000 ms), `E2E_FORCE_LOGIN` (1), `E2E_FIXTURES` (1), `E2E_INCLUDE_ADD` (0),
`E2E_REQUIRE_FRESH_BUILD` (0), `E2E_API_BASEPATH` (dari `REACT_APP_API_BASEPATH` FE, default `api/v5`), `CLIENT_ID`,
`CLIENT_SECRET` (default dari `.env` FE; bila ditolak dan `BE_DIR`/`PHP_BIN` ada, dibaca read-only dari `oauth_clients`),
`E2E_SHELL` (bash). Dua run bersamaan butuh `E2E_PORT` berbeda dan user+DB berbeda (saling mencabut sesi).

## Batasan

- Penilaian visual tetap oleh manusia (bukan pembanding piksel); "OK" = tidak ada error teknis terdeteksi dan asersi flow terpenuhi.
- Data salah yang tampil tanpa error tidak tertangkap kecuali flow memeriksanya.
- Aksi di dalam layar (simpan, hapus, cetak, unggah) dan route ber-parameter hanya lewat spec.
- `--all-routes` = semua path statis `paths.js`; `/unathorized` = SKIP (permission/scope), bukan GAGAL.
- Build staging tidak dibangun harness; bisa lebih tua dari kode FE (ada peringatan).
