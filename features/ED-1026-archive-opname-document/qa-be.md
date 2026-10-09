---
key: ED-1026
scope: be
round: 2
verdict: PASS
counts: {pass: 23, fail: 0, manual: 0, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

Revisi opname tanpa draft (FB-3, Gate 1 ronde 2), BE dasar `33f1feeb3` + working tree (`changed.txt`, 14 berkas; fix D-1 di `OpnameService.php`). Ronde 2 (2026-10-09): **D-1 tertutup, verdict PASS**. **Tanpa HTTP 5xx dari endpoint opname**: 0 entri log BE (`storage/logs/laravel-2026-10-09.log`) menyebut `Opname*`/`archive_opname` selama run ED-1026 dan regresi; entri lain = § 5xx di luar item ini.

- `counts`: 17 AC `[BE]` PASS + bagian BE 6 AC `[BE+FE]` (AC-3, 11, 22, 24, 28, 31) PASS = 23 PASS, 0 FAIL. AC-8 (`[FE]`) hanya data BE-nya (PASS, tak dihitung). AC-2: tukar lisensi sungguhan = MANUAL Gate 2 epic (EPIC K-2 b, `PROFILES` kosong), bagian berkas seed + DB PASS.
- Skenario permanen `features/ED-1026-.../qa/`: **39 skenario, 39 PASS, 0 FAIL, 3.251 asersi**, satu run penuh 4 mnt 24 dtk (`work/qa-be-ed1026-rev-r2-full.txt`, `work/qa-http/ED-1026-20261009110044.json`). Pengecekan D-1 lebih dulu: `--only=EXTRA-FUTURE,AC-22,AC-23,AC-9` 4/4 PASS (`work/qa-be-ed1026-rev-r2-d1.txt`).
- Regresi: ED-1024 26/26; ED-1025 40/40; ED-1029 29 PASS + 1 SKIP (X-ISO, 1 DB); ED-1027 12 PASS / 8 FAIL / 1 SKIP **identik dengan ronde 1**; ED-1028 2 PASS / 20 FAIL **identik**. Semua FAIL ED-1027/1028 = skenario usang (§ Skenario usang), sebab sama, tanpa 5xx, tanpa sebab baru.
- php-fpm: tanpa reload; kode fix terbukti jalan (selected_at masa depan -> 400 `ARCHIVE415`).

## Hasil per AC

Perintah: `"$PHP_BIN" scripts/qa-http/run.php ED-1026 [--only=ID]`. "n asersi" = run penuh ronde 2. Oracle jumlah dokumen/folder = SQL+PHP independen (`q26_oracle`, hormat Folder Permission), bukan kode BE.

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | `scenario1`: 4 kolom + indeks `archives`, 3 tabel; Updater `AddArchiveOpname` & `DeleteArchiveOpnameDraft` terdaftar tepat sekali di `config.php`; Add 2x di DB QA; Delete 2x bila tak ada sesi 1/3 non-QA (kalau ada: dilewati + catatan); DB scratch: Add dari nol (SHOW CREATE identik), Delete pada data campuran (hanya status 2 tersisa, 0 anak yatim, idempoten), tenant tanpa tabel dilewati | PASS | `AC-1 PASS 176` |
| AC-2 | `scenario1`: seed `permission_salesman.sql` tuple 1139/1140, checks/unchecks, tak ada tuple Archive id 1133; DB dicari per nama (id 1139 module 1266, role 1/2/3 punya, 29/6 tidak); seed dijalankan utuh di DB scratch | PASS (tukar lisensi = MANUAL Gate 2) | `AC-2 PASS 33` |
| AC-5 | `scenario2`: root user Semarang / role 3 / superadmin; children = oracle (lokasi + Folder Permission), `document_count` subtree, `opnamed_today`, `is_default_checked`, `selected_at` = waktu server, kunci persis kontrak | PASS | `AC-5 PASS 239` |
| AC-6 | `scenario2`: dokumen 400 `ARCHIVE417`; tak ada/nonaktif 404 `ARCHIVE400`; luar lokasi / tanpa View 403 `ARCHIVE407`; id aneh tanpa 5xx | PASS | `AC-6 PASS 53`, `R2-D1 PASS 105` |
| AC-9 | `scenario2` pada 4 endpoint (scan, preview, preview/documents, POST opnames): 416 (7 bentuk), 403/404/400 sebelum 422, ~25 bentuk 422 (`scans.*`, `selected_at` kosong/bukan tanggal), urutan 415 sebelum 416, camelCase, tanpa tulis; POST valid = tepat satu sesi. Cek tanggal bersama AC-22/23 diulang (ronde 2) | PASS | `AC-9 PASS 300`, `AC-9-LEN 15`, `EXTRA-SCAN 98`, `EXTRA-FUZZ 105`, `R2-D1 105` |
| AC-10 | `scenario3`: Verified/Not found/Invalid (7 luar cakupan, 9 invalid), kunci `row`, `scanned_at` = waktu server, tanpa baris DB baru | PASS | `AC-10 PASS 190` |
| AC-12 | `scenario4`: PUT/DELETE `opnames/{id}`, POST `{id}/scan`, PUT `{id}/confirm` -> 404/405, sesi tak berubah; GET `{id}` sisa draft/batal -> 404 `ARCHIVE412`; sesi terkonfirmasi terlihat | PASS | `AC-12 PASS 99` |
| AC-15 | `scenario4`: `POST preview`: kunci result, `counts` (BR-20), `result_counts`, `is_partial`, `warnings`, `scope`; sama dengan `GET opnames/{id}` sesudah Konfirmasi | PASS | `AC-15 PASS 47` |
| AC-16 | `scenario3`: 6 filter, paginator, urutan scan terbaru dulu lalu belum discan, Not found `folder_name` null, 422 | PASS | `AC-16 PASS 212`, `EXTRA-PAGING 242` |
| AC-18 | `scenario5`: scan tak menulis; Konfirmasi: discan -> `is_verified=1`+`verified_at/by`+`id_archive_opname`; tak discan -> 0; status 2, `selected_at` = body, angka final, level 0-2, `result=4`, urut `scans` | PASS | `AC-18 PASS 127`, `R2-D2 PASS 122` |
| AC-19 | `scenario5`: Lanjut aktif tetap 1; nonaktif -> 0 dan `unverified_count`; turunan ikut Step 1; Lanjut diabaikan bila belum diopname | PASS | `AC-19 PASS 53` |
| AC-20 | `scenario5`: subfolder tak dicentang & dokumen lokasi JOG tak tersentuh; user Semarang/JOG opname root masing-masing; superadmin melihat "sudah diopname" | PASS | `AC-20 PASS 45` |
| AC-21 | `scenario5`: 7 Not found + 3 Invalid: `archives` + `archive_documents` identik sesudah scan dan Konfirmasi | PASS | `AC-21 PASS 27` |
| AC-23 | `scenario6`: `selected_at` / satu `scans.*.scanned_at` kemarin (23:59:59, 00:00:00) atau besok -> 400 `ARCHIVE415`, DB tak berubah; tepat 00:00:00 hari ini lolos; sesi kemarin -> "belum diopname" & tercentang; hari baru = timpa. Ditambah `EXTRA-FUTURE` (selected_at masa depan hari ini, D-1) | PASS | `AC-23 PASS 50`, `EXTRA-FUTURE PASS 52` |
| AC-25 | `scenario5`: dokumen dipindah/dinonaktifkan sesudah discan: pratinjau dan Konfirmasi menilai ulang | PASS | `AC-25 PASS 34` |
| AC-26 | `scenario8`: list, create-folder, put-in, hand-over, receive, `DocumentService::store`; `is_verified` tak berubah; dokumen baru 0 | PASS | `AC-26 PASS 39` |
| AC-27 | `scenario8` Backup Arsip (24.279 dok): folders root 0,45 dtk; scan 0,26; preview 1.000 scan 0,85; preview/documents 0,85 (unscanned hal 150: 0,92); **Konfirmasi 2,14 dtk** (semua < 30 dtk, tanpa 5xx); angka sesi = oracle | PASS | `AC-27 PASS 52` |
| AC-3 (BE) | `scenario2`: 5 endpoint Opname Document -> 403 `GE0114` untuk role 6, 29 dan QA_USER2; 401 tanpa token; role 3 + superadmin lolos | PASS | `AC-3 PASS 88` |
| AC-11 (BE) | `scenario3`: kode ganda (persis/besar-kecil/spasi tepi/É-é) dihitung sekali, satu baris snapshot | PASS | `AC-11 PASS 22` |
| **AC-22 (BE)** | `scenario6`: B muat Step 1, A konfirmasi sesudahnya -> 400 `ARCHIVE414` (`status fail`, `msg_code`, `result.folders`, `parameter`), tanpa sesi B; kirim ulang 2-3x tetap 414 (satu sesi); pilih ulang + scan tetap -> 200; folder tak beririsan tak bentrok; root vs S1; urutan 422>415>416>414; `EXTRA-CONCURRENT` 2/2/2/4 bersamaan: tepat satu 200, sisanya 414; **`EXTRA-FUTURE`: selected_at masa depan ditolak 415 (D-1 tertutup)** | PASS | `AC-22 PASS 68`, `EXTRA-CONCURRENT PASS 47`, `EXTRA-FUTURE PASS 52` |
| AC-24 (BE) | `scenario4`: Step 1, scan, pratinjau berulang tanpa Konfirmasi: 3 tabel opname & verifikasi `archives` identik; sisa draft/batal lama tak memblokir | PASS | `AC-24 PASS 39` |
| AC-28 (BE) | `scenario6`: `ARCHIVE414` root `[0]` = `<b>Semua Arsip</b>` (id) / `<b>All Archive</b>` (en), `result.folders[0]` id/name null; folder biasa = nama; pratinjau root | PASS | `AC-28 PASS 46`, `EXTRA-LANG 97` |
| AC-31 (BE) | `scenario4` EXTRA-SCOPE: `scope` = tepat `is_root,folder_name,subfolder_count` di POST preview, GET opnames/{id}, baris GET opnames, `last_sessions` | PASS | `EXTRA-SCOPE PASS 32` |
| AC-8 (`[FE]`, data BE) | folder tanpa subfolder + belum diopname: `skip_select_folder` true, scope tanpa subfolder, tanpa tulis | PASS (tak dihitung) | `AC-8 PASS 30` |

`[FE]` (AC-4, 7, 8, 13, 14, 17, 29, 30) dan bagian FE AC-3/11/22/24/28/31 = tahap FE.

Skenario tambahan (semua PASS): `EXTRA-PERM` 54, `EXTRA-STALE` 65 (subfolder nonaktif/dipindah -> 416 tidak diam-diam), `EXTRA-PARALLEL-SCAN` 21, `EXTRA-FUZZ` 105 (3.500 scan: pratinjau+Konfirmasi 5,1 dtk), `R2-D1` 105, `R2-D2` 122, `R2-SCANVOL` 19, `R2-PARALLEL-DIFF` 86, `R2-SCHEMA` 22, `AC-9-LEN` 15.

## Defect

Tidak ada yang terbuka. **D-1 (ronde 1, rendah) - AC-22 / BR-23, subtask [BE] ED-1089 "Konfirmasi Opname sekali kirim": TERTUTUP.**
- Perbaikan: `OpnameService.php:1254-1272` `assertToday` menolak `selected_at` sesudah waktu server (selain bukan hari ini) dengan 400 `ARCHIVE415`; `store()` `:371-381` memakai `selected_at` apa adanya sebagai acuan bentrok (tanpa `min(..., sekarang)`). Karena `selected_at <= sekarang <= confirmed_at`, kiriman ulang body yang sama selalu `ARCHIVE414`, dan sesi user lain sesudah muat Step 1 selalu terdeteksi.
- Bukti: `EXTRA-FUTURE` (saya perkuat 9 -> 52 asersi, lihat § Perubahan qa/): tiga nilai masa depan (hari ini 23:59:59, +5 dtk, +1 jam) -> 400 `ARCHIVE415` + pesan "hari lain", DB (3 tabel, `archives`) tak berubah; dua kiriman identik berjarak 2 dtk (reproduksi D-1) -> keduanya 400 `ARCHIVE415`, 0 sesi baru; B (Step 1 sebelum A) kirim masa depan sesudah A konfirmasi -> 415 (bukan 200, bukan 414), kiriman dengan `selected_at` sah tetap 414; urutan 422 > 415 dan 415 > 416 (dengan kontrol 416); `selected_at` = waktu server (batas) lolos 200 dan kirim ulang (jeda 1 dan 2 dtk) -> 414, satu sesi.
- Kontrak vs spec: `contract.md` §5 (baris `ARCHIVE415`: "atau `selected_at` sesudah waktu server sekarang", urutan cek) dan `spec.md` Catatan implementasi (2026-10-09 "fix QA rev D-1") cocok dengan perilaku aktual dan tidak bertentangan dengan BR-23/AC-22/AC-23.

Catatan (bukan defect, keputusan developer):
- `scans.*.scanned_at` masa depan di hari ini (mis. 23:59:59) diterima 200 dan disimpan. BR-23/K-15 a hanya mensyaratkan format + hari ini; bukan acuan bentrok, jadi tidak menyebabkan sesi ganda. Dicatat di `EXTRA-FUTURE` tanpa diasersikan.
- Teks `ARCHIVE415` "Sesi opname ini dimulai di hari lain" untuk `selected_at` masa depan di hari yang sama sedikit menyesatkan; FE tidak pernah mengirim nilai ini (selalu dari `GET opnames/folders`). BR-23/AC-23 belum menyebut aturan "sesudah sekarang"; hanya kontrak §5 dan Catatan implementasi.

## Isolasi tenant & permission

- Tenant: `QA_DBS` = 1 DB (`api_sidomaju`) -> uji selang-seling dua tenant **tidak bisa dijalankan** (tidak diverifikasi). Tinjau kode: `OpnameService`/`OpnameController`/Request tanpa `static` dan tanpa cache lintas request (cakupan per request, lewat parameter); fix D-1 hanya menambah perbandingan waktu lokal, tanpa state baru.
- Permission: 5 endpoint item ini = `Opname Document`: 403 `GE0114` untuk role 6, role 29, QA_USER2; 200 untuk role 3, 1, 2; 401 tanpa token. Folder opname selain terlihat+View -> 403 `ARCHIVE407` (juga di `authorize()` sebelum 422). Baca sesi (`GET opnames*`) tetap `List Archive` (`api.php:44-50`) = ED-1028.
- Login QA_USER2 memakai `force_login=1`: hanya mencabut token lain milik QA_USER2 sendiri. Akhir tiap run: 0 token aktif tersisa.

## Review diff BE (langkah 7)

Kontrak vs aktual: §1-§5 sesuai (kunci, envelope, kode, permission, urutan cek); §6/§7 sesuai dengan tambahan ED-1028 (`result_counts`, `is_partial`). `ARCHIVE414` = `formatResponse(false)` 400 tanpa kunci `code`; kode 211/214/413 terhapus dari `en_EN`/`id_ID`; `ArchiveOpname` tinggal `STATUS_CONFIRMED`; Updater `DeleteArchiveOpnameDraft` idempoten dan dalam transaksi. `POST opnames` dalam `DB::transaction`: kegagalan (414/415/416/404/403) tidak menyimpan apa pun. Kolom v3: tabel `archive*` tidak dipakai v3. Diff fix D-1 (`OpnameService.php`): `php -l` bersih, `strcmp` teks atas `Y-m-d H:i:s` (format sudah divalidasi `StoreRequest`) benar.

## Data uji (dibuat, dihapus, dipulihkan)

- **DB `api_sidomaju` dibiarkan seperti ditemukan.** Diukur sebelum run dan sesudah seluruh run + regresi: checksum 3 tabel opname 0 baris (0/0/0), kolom verifikasi `archives` 49.148 baris checksum sama (`105707195517737`), hitungan `archives` 49.148 / `archive_documents` 49.126 / `archive_permissions` 7 / `archive_locations` 48.037 sama, 0 baris `QA%`, tanpa berkas jurnal tersisa. Hasil: identik.
- DB tidak punya sesi opname maupun dokumen verified milik developer; **purge/reset data developer: nihil**. `q26_baseline/q26_cleanup` (aman terhadap data developer, diuji dengan data simulasi `QADEVSIM-*` di ronde 1) tidak berubah ronde ini (`qa_lib.php` tak diubah), jadi simulasi tidak diulang.
- Dibuat & dibuang QA: fixture `QA26-*`, sesi opname QA (jurnal sesi), perubahan sementara user QA (dipulihkan persis), DB scratch AC-1 (dibuang).
- **Direset (bukan milik QA, efek regresi ED-1027 X-1, sebab sama dengan ronde 1):** `updater_logs` id 197 (`key_id` 1791357196): `executed_at` `2026-10-09 06:15:28` -> `2026-10-09 11:13:25` dan `execution_time` `0.002` -> `0.001` (ditimpa `executeUpdater`); dipulihkan ke `2026-10-09 06:15:28` / `0.002` (UPDATE satu baris, `id=197 AND key_id=1791357196`); kolom lain (`file_name`, `status`, `error_log`, `hardware_info`) tak berubah; baris penuh sebelum/sesudah pemulihan identik. Hal yang sama akan terulang tiap run ED-1027 sampai QA ED-1027 memperbaiki X-1.

## Skenario usang item lain (dicatat, TIDAK diubah; gate1 §5)

Hasil ronde 2 = ronde 1 (sebab, jumlah, file:baris sama; tak ada sebab baru, tak ada 5xx; respons terakhir 422).

| Item | file:baris | Hasil run | Sebab | Pemilik |
|---|---|---|---|---|
| ED-1027 | `scenario1-columns-updater.php:69` (AC-1) | FAIL | "kunci Updater = terbesar di `config.php`": `DeleteArchiveOpnameDraft` (1791512946) kini terbesar (didapat 1791512946, diharapkan 1791357196) | QA ED-1027 |
| ED-1027 | `scenario1-columns-updater.php:169` (X-1) | FAIL | `updater_logs` tak kembali persis: baris log key 1791357196 sudah ada, `executeUpdater` menimpa; bukan akibat item ini | QA ED-1027 |
| ED-1027 | `qa_lib.php:537-542` `q27_session` (AC-10, 11, 12, X-4, X-7, X-8) | FAIL 6 | membuat draft via `POST opnames` tanpa `selected_at`/`scans` -> 422; alur draft dihapus (FB-3) | QA ED-1027 (revisi epic) |
| ED-1027 | X-3 | SKIP | `QA_DBS` 1 DB | - |
| ED-1028 | `qa_lib.php:537-542` `q28_session` (20 dari 22: AC-1..9, 11-14, X-1..5, 7, 8) | FAIL 20 | sama; PASS: AC-10, X-6 | QA ED-1028 (revisi epic) |
| ED-1028 | `scenario1-list.php:177-189`, `scenario5-access.php:150-154`, `qa_hist.php:153-155`, e2e `.ant-segmented-item` (`e2e/ED-1028-opname-history.spec.js:978,1019`) | belum tercapai | `scope` tanpa `first_name`/`other_count` (`scenario1-list.php:177-189`); `PUT .../confirm`, `DELETE opnames/{id}` dihapus -> 404/405 (`scenario5-access.php:150-154`, `qa_hist.php:153-155`, dugaan dari membaca, belum tercapai di run); filter `CheckableTag` | QA ED-1028 |

Catatan: `q27_baseline`/`q28_baseline` menegaskan tabel opname kosong; lolos di DB kosong ini, gagal bila developer punya data opname. Tidak diuji dengan data simulasi (item direvisi lagi).

## 5xx di luar item ini (dilaporkan, bukan defect ED-1026)

Log BE jendela run ronde 2 (10:54-11:17 WIB): 111 entri `local.ERROR` selain job websockets, **0 menyebut `Opname*`/`archive_opname`**: 91 `SQLSTATE 1267/1270 Illegal mix of collations` (probe teks non-Latin-1 ED-1025 X-12 / ED-1029; = **ED-1071** ditunda Gate 1), 12 masukan aneh pada list/search archives (json_decode array, `CustomizeBuilder`, `Illegal offset type`, `Unsupported operand`, `Array to string`, SQL 1064, `showRelatedTransaction`; = **ED-1070** ditunda Gate 1), 5 `OAuthServerException` (token dicabut `force_login` antar run), dan 3 entri buatan saya sendiri (skrip ukur salah kolom `code` 1x; `curl` tanpa `Accept: application/json` ke `auth/info` tanpa token -> 500 "Route [login] not defined", `app/Http/Middleware/Authenticate.php:19`, perilaku lama untuk klien non-JSON, bukan endpoint item ini). Kode HTTP kasus karakterisasi ED-1025/1029/1070/1071 tak diasersikan di sini.

## Yang tidak bisa diverifikasi (dan kenapa)

- Tukar lisensi Salesman Activity sungguhan (AC-2/AC-3 "tanpa lisensi"): MANUAL Gate 2 epic (EPIC K-2 b, `PROFILES` kosong); dibuktikan lewat baris seed hanya di `permission_salesman.sql` dan 403 `GE0114` tanpa permission.
- Isolasi tenant (`QA_DBS` 1 DB), RoadRunner (`BE_SERVER=fpm`).
- `GET opnames*` per permission/akses pemilik: milik ED-1028.
- Seluruh `[FE]`: tahap FE. `qa/e2e/ED-1026-opname.spec.js` masih untuk alur draft (usang untuk tahap FE); `qa/e2e/fixture.php` per nama permission; perintah `backdate` (draft) tak berlaku.
- Kebocoran jejak: `PUT/DELETE opnames/{id}` (405) mengembalikan JSON `exception/file/trace` (APP_DEBUG nyala di mesin ini, perilaku framework).

## Perubahan di folder qa/ (item ini)

Ronde 2: `scenario6-conflict-perf.php` `EXTRA-FUTURE` ditulis ulang (9 -> 52 asersi, isi di § Defect; SKIP bila jam server >= 23:59:40). Sebelumnya (ronde 1): `qa_lib.php` + `qa_lib_oracle.php` (jurnal pra-citra, sweep pemulih, oracle Folder Permission), `scenario1`..`scenario8`, `e2e/fixture.php`.

## Riwayat ronde

- Ronde 1 (2026-10-07, alur draft): 19 AC `[BE]` + 3 `[BE+FE]`; 30/32 PASS; FAIL D-1 (ED-1038), D-2 (ED-1044).
- Ronde 2 (2026-10-07): D-1/D-2 tertutup; 37/37 PASS; regresi ED-1024 26/26, ED-1025 30/31 (asersi usang).
- Ronde 1 revisi (2026-10-09, tanpa draft): 39 skenario ditulis ulang; 38 PASS, 1 FAIL (D-1 rendah, `selected_at` masa depan lolos kiriman ulang); regresi ED-1027 12/8, ED-1028 2/20 usang.
- Ronde 2 revisi (2026-10-09): D-1 tertutup (`selected_at` > sekarang -> 415); 39/39 PASS, 3.251 asersi; regresi ED-1024 26/26, ED-1025 40/40, ED-1029 29+1 SKIP, ED-1027 12/8/1 dan ED-1028 2/20 (usang, identik); `updater_logs` 197 dipulihkan; verdict PASS.
