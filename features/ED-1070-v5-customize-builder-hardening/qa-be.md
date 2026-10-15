---
key: ED-1070
scope: be
round: 2
verdict: PASS
counts: {pass: 13, fail: 0, manual: 0, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

QA BE ronde 2 atas diff BE `v5-opname-archive` (0b8417778 + 86 berkas kotor; perbaikan r1 terakhir 14:59 UTC), DB
`api_sidomaju`, QA_USER, php-fpm tanpa reload (perilaku baru D-1/D-2/D-3 terbukti dilayani). Jendela QA 2026-10-15
15:04-16:20 UTC (22:04-23:20 WIB; di luar 23:45-00:15). Urutan: golden "sesudah" lebih dulu (15:04-15:30, ke
`qa/golden/after-r2/`, tanpa `--mark-unstable`), baru proba, skenario, suite, e2e.

- **D-1 tertutup**: `cash-bank.cash-banks` PASS 28 dan `general-ledger.expenses` PASS 24 (r1: DIFF 2/2, kueri
  `column_display_settings` tambahan hilang).
- **D-2 tertutup**: search yang hanya berisi entri diblokir di archives = seperti tanpa search: root 3537 / folder
  "Backup Arsip" 24285 (r1: 34542). In-process 70/70 proba lulus (r1: 52/70); HTTP `AC-2-archives-total` 72 asersi
  (6 payload x root/folder, total **dan** baris sama). Folder Izin On tanpa View + search hanya-diblokir = 403 `ARCHIVE407`.
- **D-3 tertutup**: `GET company/companies/{id}` 200 (r1: 500); cetak PDF SO (ED-1024 AC-11, X-9) PASS; semua URL berkas
  buatan BE = `/api/v5/select/files/download?...`, route ada (bukan 404), browser meminta URL v5 (tanpa request api/v4).
  Di mesin ini **tidak ada satu pun berkas lampiran di disk** (254.826 baris `attachments`, `storage/app/attachments`
  tidak ada), jadi logo perusahaan = 500 `NOT_FOUND_ATTACHMENT` - kode V4 dan V5 identik untuk jalur ini (pra-ada, di luar
  item, lihat bawah). Thumbnail bawaan lewat URL v5 = 200, isi = `public/images/thumbnail.png`.
- Regresi penuh hijau: 13/13 AC PASS, 11/11 skenario ED-1070, 8 suite item hijau, e2e AC-12 8/8.
- Golden: guard tabel `users` memang berubah (baris QA_USER2, login QA sendiri 21:16:19 WIB) -> mentah PASS 3 /
  SKIPPED-guard 228; dengan guard `users` dinetralkan (salinan di `work/golden/ED-1070/r2-neutral/`) PASS 223 / DIFF 4 /
  SKIPPED-guard 4 - semua DIFF dan 4 SKIPPED dibuktikan data/seri, 0 beda karena kode.

## Hasil per AC

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | `golden.php capture --out=qa/golden/after-r2 --mode=both --sets=golden,known500` (pertama, sebelum fixture/suite) lalu `compare before after-r2`; list SKIPPED-guard karena `users` dinilai ulang pada salinan dengan guard `users` dinetralkan; DIFF dijelaskan (data vs kode) | PASS (catatan) | `work/golden/ED-1070/compare-after-r2.txt`: PASS 3 / DIFF 0 / SKIPPED-guard 228 / MISSING 0 (231 list). `compare-after-r2-neutral.txt`: PASS 223 / DIFF 4 / SKIPPED-guard 4. DIFF: `employee.users` #000/#002/#004/#015 (urutan `users.updated_at desc`: baris QA_USER2 ...1264 naik karena login QA; request sama 5x di kode sesudah = 1 urutan) + #009 (sort `isActive`, seri: 5 urutan dalam 5 ulangan); `efective-call`#011 (seri, 5 urutan); `to-do-list`#002 (seri `due_date`, 3 urutan) - `work/qa-ED-1070/r2/repeat-1.txt`; `setting.display-columns` #000-#003 total 361->363 = baris cds 2199/2200 (lihat Data). 4 list Raptor (SKIPPED cds raptorDeposit/raptorInvoice): 78/78 request identik kecuali satu `insert into column_display_settings` yang dulu dijalankan app karena setting belum ada (`work/qa-ED-1070/r2/raptor-cds-check.txt`). Log in-process golden: 0 `[CustomizeBuilder]`, 33 ERROR = known500 |
| AC-2 | In-process `qa/probe/security-probe.php` (penolak tulis/DDL + rollback, InnoDB dicek) `delete()`/`update()`/`truncate()`/`whereRaw()` x 7 list; HTTP php-fpm hanya payload aman (`AC-2-http`, `AC-2-archives-total`) | PASS | `work/qa-ED-1070/r2/security-probe.txt`: HASIL PASS, 70/70, fails 0, `fingerprint_same` true (count+`CHECKSUM TABLE` 6 tabel), 0 tripped; archives root 3537 = base, folder 24285 = base. HTTP `AC-2-http` 63 asersi, `AC-2-archives-total` 72 asersi PASS |
| AC-3 | Sama: `latest`, `getTable`, `truncate` (bukan relasi), `{"sumX;DROP":[">=","1"]}`, campuran 3 | PASS | Probe in-process 7 list PASS; HTTP `AC-3-http` 133 asersi (blocked tepat 1 per entri, total = base termasuk archives) |
| AC-4 | `scenario1-guard-http.php` AC-4 (archives/customers/PO): search non-objek, `query`/`sub`/`sum`/`zzz`/`limit`, tanggal, relasi, sorts | PASS | `run.php ED-1070` AC-4 355 asersi (`work/qa-ED-1070/r2/run-1070-r2.txt`) |
| AC-5 | AC-5: `abc`/`-1`/`0`/`2.5`/`[]=1`/`[a]=1` di archives, customers (ChecknSet), PO, Raptor deposit/failed + invoice/queue (K-6); `10&page=2`; `1000000` | PASS | 255 asersi; per_page 10 + 1 log malformed; A-8 diterima |
| AC-6 | `search[]=x`, `search[a]=b`, `search[a][b]=c` di archives, employee/users, sales-invoices, receivable-payments | PASS | 88 asersi, tanpa `json_decode()` 500 |
| AC-7 | In-process `qa/probe/sort9.php` (asc+desc, seluruh list, oracle join manual FK baru) + HTTP `AC-7-http` + known500 golden | PASS (7 baris tak terverifikasi, sama r1) | `work/qa-ED-1070/r2/sort9.txt`: 24 baris (1-4, 6-9, 12-16, 18, 20-24, 27-31) PASS asc+desc, pasangan BARU ada, LAMA tidak, oracle cocok. HTTP 192 asersi. Known500 96 -> now200 63 / still500 33, identik r1 (id & hasil) |
| AC-8 | id acak + `pagination=abc`/`search[]=x`/`search=bukan-json`/`sorts=bukan-json`/`search[a]=b`/**`search={"whereRaw()":"1=1"}`** (baru r2); folder QA02- Izin On tanpa View + param sama | PASS | 32 asersi: 404 `ARCHIVE400` / 403 `ARCHIVE407` di semua varian |
| AC-9 | `run.php` ED-1024/1025/1026/1027/1028/1029/1069/1071 (karakterisasi r1 tetap) | PASS | `work/qa-ED-1070/r2/suites.txt`: ED-1024 26/0 (AC-11, X-9 PDF SO PASS; r1 2 FAIL), ED-1025 40/0 (X-8 34, X-13 55 asersi), ED-1026 39/0, ED-1027 28/0/1 SKIP (X-3 satu DB), ED-1028 24/0, ED-1029 40/0/1 SKIP (X-ISO satu DB), ED-1069 16/0, ED-1071 16/0 |
| AC-10 | `AC-10-http` (route v4 + `/v4` dengan/tanpa token = 404); router dump; `AC-10-v5-deps`; **`AC-10-file-urls` (baru)**; e2e D-3 layar Perusahaan | PASS | 404 semua route v4; router 5395 route identik r1, 0 `api/v4`, nama `v5.select.files.download` ada (`work/qa-ED-1070/r2/routes-r2.tsv`). `company/companies/{id}` 200. `AC-10-file-urls` 31 asersi: logo (`logoSmallUrl`), `Company::info()->logo_url`, thumbnail `mainPhoto` (select issue-receipt items), lampiran Raptor (presenter in-process; tabel raptor 0 baris), template OTP (Blade dikompilasi in-memory) = URL v5; unduh tanpa token: thumbnail bawaan 200 + isi sama; berkas hilang -> 500 `NOT_FOUND_ATTACHMENT` seperti kode V4; URL v4 lama 404. e2e: request logo = URL v5, langsung 500 JSON `NOT_FOUND_ATTACHMENT`, Chrome `ERR_BLOCKED_BY_ORB`, FE tampil gambar cadangan |
| AC-11 | `php -l` (7.3.33) 85 berkas PHP diubah; grep sintaks >=7.4 / helper L>5.7; RF1-RF4 (skrip r1 diulang); RF5 router dump | PASS | lint 0 error; grep 0 temuan; `work/qa-ED-1070/r2/rf-static.json`: RF3/RF4 20 nilai = harapan; RF1 64 nama relasi config, 311 pasangan lolos, 0 gagal (2045 method relasi; 1 gagal = O-1); RF2 hanya `whereCurrentSupplierBalance()`. RF5: P=262, S=584, P+S=65, diff route vs r1 = 0 baris |
| AC-12 | `scripts/e2e/run.sh ED-1070` (staging 14:30 UTC = FE 41146943c, FE bersih; tidak dibangun ulang) | PASS | 8 OK (4 smoke + 4 flow Archive/Pelanggan/PO/Faktur), 0 allow-list: `work/e2e/ED-1070-v5-customize-builder-hardening-20261015-161622/`. Test D-3 tambahan GAGAL hanya karena error pra-ada (lihat Di luar item) |
| AC-13 | Log `storage/logs/laravel-2026-10-15.log` baris 30710-35627 (jendela r2), per fase | PASS | Golden HTTP: 35 ERROR = 33 known500 pra-ada + 2 kesalahan skrip CLI QA sendiri (QueryException kueri intip 22:09:57, `fopen` router dump 22:17:15 WIB), 0 `[CustomizeBuilder]`. `run.php ED-1070`: 150 WARNING semua `[CustomizeBuilder]` (blocked 44, malformed 106), tiap request diasersi tepat; 0 ERROR. Suite: 142 malformed (ED-1025 16, ED-1026 30, ED-1027 2, ED-1028 94 = fuzz/karakterisasi) + 28 ERROR OAuth "access token revoked" (401 token yang sudah dicabut `force_login`). e2e: 2 ERROR OAuth yang sama. 0 ERROR dari guard/middleware/trait |

## Defect

Tidak ada defect baru di item ini. Ronde 1: D-1, D-2, D-3 -> **tertutup** (bukti di Ringkasan dan AC-1/2/3/9/10).

## Isolasi tenant & permission

- Isolasi tenant: tidak bisa diuji (`QA_DBS` = `api_sidomaju` saja; ED-1027 X-3 / ED-1029 X-ISO SKIP). Tinjauan kode
  perbaikan r1: `CustomizeBuilderGuard::searchMayApply` dan `ArchiveService::isSearching` tanpa properti statis/cache.
- Permission: tidak ada endpoint baru. `normalize_*` tetap sesudah `auth:api` + `permission_v5` (router dump identik r1);
  AC-8 membuktikan 403/404 tetap sebelum pelewatan, kini juga untuk search hanya-diblokir.
- `api/v5/select/files/download` tanpa `auth:api` (grup `api, req_snake, res_camel, cns`) = sama persis dengan V4; eksposur
  tidak berubah oleh D-3 (catatan, bukan defect item).
- Diterima: A-8 (`pagination=1000000`). A-1..A-7 lain tidak muncul di jalur yang diuji.

## Data uji, sesi, berkas QA

- Dibuat/dihapus r2: AC-8 1 folder `QA02-P70-*` (helper ED-1025) dibuang `q2_purge` (`q2_assert_clean` PASS). Suite 8
  item memakai & membuang fixture sendiri (semua PASS). Golden/proba in-process: 0 baris (rollback + penolak tulis/DDL).
- Akhir 16:19 UTC: sisa `QA%` 0 (archives, archive_documents, roles, customers, purchase_orders); fingerprint 6 tabel
  (count + `CHECKSUM TABLE`) = 15:33 UTC (`work/qa-ED-1070/r2/final.json`).
- Efek samping app oleh QA: `column_display_settings` id 2199 (`raptorDeposit`) dan 2200 (`raptorInvoice`), dibuat app
  saat GET Raptor di AC-5 **ronde 1** (penyebab SKIPPED-guard cds). Follow-up runner (hapus bila terbukti milik QA): cek
  read-only **semua lolos** (`work/qa-ED-1070/r2/cds-cleanup-check.json`): `created_by` = QA_USER, `created_at`
  20:51:30/31 WIB di jendela r1 (log: GET `raptor/deposit/failed` 20:51:30 dan `raptor/invoice/queue` 20:51:31 oleh
  QA_USER), `updated_at` = `created_at`, `updated_by` null, satu-satunya baris per modul, `before/_meta.json` cds kedua
  modul count 0, 0 FK / 0 kolom lain yang merujuk. Salinan baris: `work/qa-ED-1070/r2/cds-2199-2200-snapshot.json`.
  **DELETE ditolak classifier izin (Modify Shared Resources) -> tidak dijalankan, tidak diakali; baris DIBIARKAN**
  (berkas hasil `--apply` tidak ada; cek ulang read-only sesudahnya juga ditolak). Developer memutuskan: hapus tepat
  `id_column_display_setting IN (2199,2200)` dengan `created_by` = QA_USER (skrip siap: `work/qa-ED-1070/r2/cds-cleanup.php
  --apply`, transaksi, tepat 2 baris) atau biarkan (app membuatnya lagi saat Raptor dibuka). Selama baris ada, guard
  golden cds raptor dan tabel `column_display_settings` (363 vs 361) tetap beda dari `before/_meta.json`.
  `users.updated_at` QA_USER2 berubah karena login suite (21:16:19 WIB r1, 23:07:58 WIB r2) - guard golden `users` akan
  tetap beda untuk tangkapan berikutnya. Tidak ada data developer yang diubah/dihapus.
- Sesi: semua run log-out; token aktif QA_USER akhir = 0 (golden, probe, `run.php`, 8 suite, 2 run e2e: "masih aktif 0").
- Berkas QA r2: `qa/golden/after-r2/` (baru); `qa/scenario1-guard-http.php` (archives dibandingkan dengan base sesuai
  spec; `AC-2-archives-total` 6 payload + baris; AC-8 + search hanya-diblokir); `qa/scenario3-v4-deps.php` (+`AC-10-file-urls`);
  `qa/e2e/ED-1070-d3-file-urls.spec.js` (baru). Salinan golden dinetralkan: `work/golden/ED-1070/r2-neutral*/`
  (`qa/golden/before` dan `requests.json` tidak disentuh).

## Galeri e2e & regresi BE

- Galeri AC-12: `/var/www/opname-archive/agentic/work/e2e/ED-1070-v5-customize-builder-hardening-20261015-161622/index.html`
  (AC-12 8/8 OK; D-3 waktu itu GAGAL pra-ada). Run D-3 ulang (spec diperhalus untuk request yang diblokir ORB):
  `.../ED-1070-v5-customize-builder-hardening-20261015-161751/` - semua asersi spec lulus, satu-satunya error =
  `requestfailed ERR_BLOCKED_BY_ORB` logo (berkas hilang).
- Sesudah tiket ED-1166: allow-list `test.use({ allow })` di `qa/e2e/ED-1070-d3-file-urls.spec.js` (2 entri, `ref`
  ED-1166, hanya path `/setups/companies/company` dan URL `GET .../api/v5/select/files/download?idAttachment=<angka>` dengan
  `ERR_BLOCKED_BY_ORB` atau status 500); spec juga mewajibkan kegagalan itu = 500 `NOT_FOUND_ATTACHMENT`. Hanya spec D-3
  dijalankan ulang: **1 OK (1 error allow-list)**, galeri
  `/var/www/opname-archive/agentic/work/e2e/ED-1070-v5-customize-builder-hardening-20261015-162348/index.html`.

## Di luar item

- **ED-1166** (pra-ada, tiket dibuat runner): `SelectFileService::downloadAttachment/downloadItemPhoto`/dokumen
  (`Modules/V5/Http/Services/Select/FileManager/SelectFileService.php:36,61,88,134`, identik V4
  `Modules/V4/Services/Select/FileManager/SelectFileService.php`) melempar `ErrorMessageException('NOT_FOUND_ATTACHMENT')`
  dengan status bawaan **500** (seharusnya 404). Di mesin ini semua berkas lampiran tidak ada, sehingga logo perusahaan
  (dan lampiran lain) = 500 JSON -> Chrome `ERR_BLOCKED_BY_ORB`, FE menampilkan gambar cadangan. Sebelum item, URL v4 sama
  hasilnya. Di-allow-list di spec D-3 (hapus entri begitu ED-1166 selesai).
- **ED-1167**: kumpulan 500 list pra-ada (known500 still500 33: supplier-pricelists `survey_date` 16, to-do-list
  sorts/`type` 12, periods `PeriodService.php:55`, shipments `idCarrier`, salesman-activity setup/customers, recipes
  `cleanBindings`, default-raptor-tax). Juga pra-ada dari r1: ED-1165 (customer/address|contacts|bank-accounts tanpa
  `auth:api`), O-1/O-2.
- Catatan perilaku D-2 (sesuai spec Catatan 12, bukan FE): `{"type":""}` dan nilai `null` kini = tanpa search (root);
  FE membuang nilai kosong (`deleteEmptyRequest`), golden archives PASS. `{"type":["a","b"]}` = 200/0 (tanpa 500).

## Yang tidak bisa diverifikasi (dan kenapa)

- Unduhan berkas lampiran yang ADA (200 + isi) lewat URL v5: tidak ada satu pun berkas di disk mesin ini; membuat berkas di
  `storage/` BE + baris `attachments` = tulis ke lingkungan developer, tidak dilakukan. Jalur disk `local` setara V4
  (`temporaryUrl` -> RuntimeException -> `Storage::download`); hanya thumbnail bawaan yang terbukti 200 + isi.
- Lampiran Raptor & foto check-in via HTTP: tabel raptor 0 baris; absensi terbaru tanpa lampiran (URL v5 tanpa id -> 500
  seperti V4). Dibuktikan via presenter in-process / URL respons.
- AC-7 baris #5, #10, #11, #17, #19 (data/setting tampilan, sama r1), #25/#26 (perlu user driver). Isolasi tenant (satu
  DB). Pint (K-1).

## Riwayat ronde

- Pra-dev 2026-10-15 12:36 UTC: golden "sebelum" diverifikasi ulang (231 PASS, guard identik), tidak ditangkap ulang.
- Ronde 1 (13:11-14:33 UTC): FAIL - D-3 tinggi (`select.files.download` hanya di V4 -> 12 pemanggil V5 500), D-1 rendah
  (kueri `column_display_settings` tambahan di sort relasi), D-2 rendah (archives search hanya-diblokir = semua level).
- Ronde 2 (ini, 15:04-16:25 UTC): PASS - D-1/D-2/D-3 tertutup, regresi penuh hijau, 0 defect baru; follow-up: allow-list
  ED-1166 di spec D-3 (1 OK), hapus cds 2199/2200 ditolak classifier (baris dibiarkan).
