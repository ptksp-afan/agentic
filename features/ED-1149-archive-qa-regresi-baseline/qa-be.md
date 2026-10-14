---
key: ED-1149
scope: be
round: 1
verdict: PASS
counts: {pass: 11, fail: 0, manual: 0, not_verifiable: 2}
be_server: fpm
---
## Ringkasan

Task QA murni (tanpa kode aplikasi). Tahap BE menulis ulang skenario ED-1025..ED-1028 supaya tidak lagi bergantung pada role/data
developer, lalu menjalankan regresi BE penuh. Hasil: 9 suite dijalankan satu per satu, **230 PASS / 0 FAIL / 2 SKIP** (SKIP = isolasi
tenant satu-DB: ED-1027 X-3, ED-1029 X-ISO), 0 respons 5xx baru, 0 defect kode baru. Repo BE (`6fcc91fd0`) dan FE (`cf37552dd`) tetap bersih
(0 berkas kotor). Snapshot data developer sebelum vs sesudah tahap BE **identik** (AC-13 bagian BE; verdict akhir AC-13 di scope fe).

Sebelum ditulis ulang, 7 skenario sasaran memang gagal di prasyarat baseline (bukan kode): ED-1026 AC-3 (`QA_USER2: GET folders: HTTP
diharapkan 403, didapat 200`), ED-1027 X-8 (`role 5 user2 belum memegang Opname Document`), X-9 (`verifications: diharapkan 403, didapat 200`),
X-10 (`total (19) < root biasa (7)`), ED-1028 AC-12/AC-14/AC-28 (`R0 = role asli user2 ... tanpa Opname Document: kondisi tidak terpenuhi`).
ED-1025 AC-25 pada baseline sekarang sudah PASS (role 3 tak lagi ber-`permission_name` NULL), tetap ditulis ulang sesuai K-4 b. Log "sebelum":
`work/qa-runs/ED-1149-be-*-pre.log`.

Cara kerja baru: (1) role preset sementara `QA2x-ROLE-*` dibuat dari **nama permission eksplisit** (`permissions.permission_name` ->
`id_permission`, `created_by` = tag QA, memuat `Allow Access Website` karena QA_USER2 bukan karyawan), dibuang persis; (2) grant
`Opname Document` hanya ke preset, `q27_grant`/`q28_grant` melempar exception untuk role non-preset; (3) `q2x_role_ref` menolak id role selain
1/2/3 (role sistem) dan preset; (4) hak dibaca per `id_permission` (join `permissions`), bukan kolom `permission_name`; (5) asersi tabel bersama
dibatasi ke baris QA (X-10) atau dipindah ke DB scratch (AC-25); (6) user_roles QA_USER/QA_USER2 diubah sementara lewat jurnal dan dipulihkan
persis (bukti: baris `user_roles` identik + snapshot).

## Hasil per AC

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | `run.php ED-1025 --only=AC-25`; asersi `role_permissions` developer dibuang, hak role 1/2/3 atas 1139/1140 + `permission_name` diasersi di DB scratch hasil seed utuh (`permission_salesman.sql` lalu `role_permissions.sql`; pra-seed: 18 sisa grant 1133-1138 + baris role 3 atas 1140 ber-NULL; dijalankan 2x = idempoten); DB bersama hanya `permissions` 1139/1140 per id, `permission_checks/unchecks`, `modules` | PASS | `AC-25 PASS 45 asersi` (`work/qa-runs/ED-1149-be-ED-1025.log`); asersi seed `:155-166` lama tetap |
| AC-2 | `run.php ED-1026 --only=AC-3`; 5 rute opname -> 403 `GE0114` `parameter`=`Opname Document` untuk preset `QA26-ROLE-NONE`, `QA26-ROLE-LIST`, dan QA_USER2 di kedua preset (bukan role 5/6/29); DB tidak berubah; tanpa token 401; role 3 dan 1/2 lolos (kontrol) | PASS | `AC-3 PASS 113 asersi`; juga R2-D1 (`:148`, pakai preset) `PASS 107 asersi`; ED-1026.log |
| AC-3 | `run.php ED-1027 --only=X-8`; QA_USER2 di preset `QA27-ROLE-U2` (website + List Archive): tanpa OD `verifications`, `verifications/{F}` 403 `GE0114`, list 200 tanpa `document_verified_summary`; OD diberikan ke preset (baris QA27 = 2 dasar + 1 grant) -> angka = oracle user2, detail 403 SV/SP/SJ/JOGR, sesi K-4 | PASS | `X-8 PASS 145 asersi` |
| AC-4 | `run.php ED-1027 --only=X-9`; preset U2 list 200 + verifications 403; +OD ke preset 200; preset `QA27-ROLE-NOLIST` (website saja, ganti role 22) -> 403 `GE0114` `parameter` List Archive / Opname Document; `user_roles` QA_USER2 asli identik (JSON baris) | PASS | `X-9 PASS 40 asersi`; snapshot `user_roles` hash sama |
| AC-5 | `run.php ED-1027 --only=X-10`; pemegang OD: `search {"isVerified":1,"query":"QA27-"}` = TEPAT id baris QA terverifikasi menurut oracle DB (aktif + lokasi SMR), `{"isVerified":0,...}` = tepat yang belum, keduanya membagi seluruh baris QA; tidak dibandingkan dengan total root; bagian tanpa OD (`:200-244`) utuh; role 29 diganti preset LIST; baris `sam` tidak disentuh | PASS | `X-10 PASS 139 asersi`; hash `archives_sam_rows` sebelum=sesudah (`861167797856`) |
| AC-6 | `run.php ED-1028 --only=AC-12`; user2 di preset `QA28-ROLE-R0` + OD dari grant ke preset (baris OD `created_by QA28` = jumlah role preset = 1, satu-satunya); `opnames/{id}` dan `/documents` sesi terlihat 200; 404 `ARCHIVE412` tak terlihat/tak ada/bekas draft/status 1 dan 3 | PASS | `AC-12 PASS 421 asersi` |
| AC-7 | `run.php ED-1028 --only=AC-14`; R0 = preset tanpa OD: `opnames`, `opnames/{S}`, `/documents` (+ varian id/search) 403 `GE0114` `parameter` Opname Document tanpa data; preset `QA28-ROLE-NONE` (ganti role 22) idem; grant ke preset -> 200, dicabut -> 403 lagi | PASS | `AC-14 PASS 240 asersi` |
| AC-8 | `run.php ED-1028 --only=AC-28`; R0 = preset: GET/PUT `documentArchiveOpnameHistory` 403 (+varian nama), baris tak dibuat; pemegang 200; hash `column_display_settings` dan (baru) **`updater_logs`** sama sebelum/sesudah | PASS | `AC-28 PASS 219 asersi` |
| AC-10 (BE) | grep + suite pemilik. Konstanta `Q26/Q27/Q28_ROLE_LIST_ONLY/NONE` hilang (diganti `Q2x_PRESET_*`); `q27/q28_u2_roles([22])` hilang; grep `'role' => (5|6|16|18|22|29)` di `features/ED-102[4-9]*/qa` (non-e2e) = **0**; semua `INSERT` ke `role_permissions` hanya di fixture/preset/scratch; kontrol negatif: `q26/q27/q28_role_ref(5|6|22|29)` dan `q27/q28_grant([5|6|22|29])` melempar exception, `role_permissions` role 5 tak berubah. Sisi e2e FE = scope fe | PASS (BE) | grep 0 baris; kontrol negatif `NC-1 PASS 22 asersi` (scratchpad); suite pemilik PASS |
| AC-11 | `run.php <KEY>` satu per satu, log `work/qa-runs/ED-1149-be-<KEY>.log` | PASS | tabel suite di bawah; 0 FAIL, 0 5xx baru |
| AC-13 (mulai) | `qa/snapshot.php before` ... `after-be`, `--compare` | SAMA (bagian BE) | lihat "Snapshot AC-13"; verdict akhir scope fe |

### Regresi AC-11 (satu suite per giliran)

| Suite | Hasil | Catatan |
|---|---|---|
| ED-1024 | PASS 26 / FAIL 0 / SKIP 0 | `AC-12`, `AC-13`, `AC-4`, `X-5` memakai fixture `QA01-ROLE-*` (lihat "Perluasan") |
| ED-1025 | PASS 40 / 0 / 0 | AC-25 skenario baru; AC-16, AC-23, X-4 memakai fixture `QA02-ROLE-*` |
| ED-1026 | PASS 39 / 0 / 0 | AC-3, R2-D1 preset; AC-2 (seed) hak role dipindah ke scratch |
| ED-1027 | PASS 28 / 0 / **SKIP 1** (X-3 isolasi satu-DB) | X-13 mencatat ED-1122 (500 PUT identik di detik sama) |
| ED-1028 | PASS 24 / 0 / 0 | seluruh AC lewat `q28_guard` (user2 di preset R0) |
| ED-1029 | PASS 40 / 0 / **SKIP 1** (X-ISO) | X-PERM, AC-20 memakai fixture `QA29-ROLE-*` |
| ED-1069 | PASS 16 / 0 / 0 | tidak diubah; catatan X-4(a) 500 `ArchiveService.php:708` (karakterisasi, ED-1124) |
| ED-1071 | PASS 16 / 0 / 0 | tidak diubah |
| ED-1150 | PASS 1 (`SESI-1`: token aktif `sam` = 0) | baca-saja; developer tidak memakai sesi sam saat dijalankan |

ED-1072 tidak punya suite BE. Total 230 PASS, 2 SKIP, 0 FAIL, 0 BLOCKED. Setiap run: "masih aktif: 0" (semua token run di-log-out).

## Defect

Tidak ada defect kode baru. Respons 500 yang terlihat semuanya karakterisasi bug yang sudah dikenal dan tetap seperti adanya (BR-8):

| Asal | Perilaku | Status |
|---|---|---|
| ED-1025 X-13, ED-1027 X-10 (catatan "sorts bentuk array") | `pagination`/`sorts`/`search` bentuk salah pada GET archives = 500 | known ED-1070 |
| ED-1027 X-13 | PUT display setting identik di detik yang sama = 500 (`ColumnDisplaySettingService::update`) | known ED-1122 |
| ED-1069 X-4(a) | `showRelatedTransaction` pada folder bernama nomor transaksi = 500 `ArchiveService.php:708` | known ED-1124 (ED-1069 X-4(a)) |

## Isolasi tenant & permission

- Permission: tiap rute yang diuji punya pasangan user berhak (2xx) dan tak berhak (403 `GE0114` + `parameter` = nama permission) dengan **preset sendiri**
  (QA_USER dan QA_USER2); pembuktian lewat `id_permission` (join), bukan kolom `permission_name`.
- Isolasi tenant: `QA_DBS` hanya `api_sidomaju` -> ED-1027 X-3 dan ED-1029 X-ISO **SKIP** (diharapkan). Lihat "tidak bisa diverifikasi".

## Yang tidak bisa diverifikasi (dan kenapa)

1. Isolasi tenant (step 6): hanya satu DB QA. Tetap MANUAL Gate 2 epic (spec § 9).
2. Tukar lisensi sungguhan (`PROFILES` kosong): tetap MANUAL Gate 2 epic; bukti statis lewat berkas seed.
3. Asumsi data developer yang masih dipakai (bukan role; tidak gagal, dicatat agar tidak terkejut): folder `Backup Arsip` (>= 20.000 dokumen: ED-1024,
   ED-1026 AC-26/27), user2 `adi` berlokasi SMR saja (ED-1027 X-8 angka tangan), `QA_USER` karyawan.
4. Angka "343 baris `sam`" di spec tidak cocok dengan DB sekarang: `updated_by='sam'` = 400 baris, terverifikasi oleh sam = 12, total terverifikasi
   non-QA = 32. Developer rupanya sudah mengubahnya sejak 13 Okt. Tidak berpengaruh (X-10 dibatasi ke baris QA; hash baris non-QA dicatat apa adanya).

## Berkas yang diubah / ditambah (hanya `features/<item>/qa/`, tanpa kode aplikasi, tanpa spec/contract/qa-*.md item lain, tanpa `baseline/*.status`)

- Baru: `ED-1149/qa/snapshot.php` (baca-saja; `<label>`, `--compare <a> <b>`, `--leftover`; keluaran `work/ED-1149/snap-<label>.json`).
- ED-1025/qa: `qa_lib.php` (`q2_role` + param superadmin/aktif, `q2_with_u2_roles` QA_USER2 berjurnal), `scenario9-rev-seed-fuzz.php` (AC-25), `scenario4-select-misc.php` (AC-16 langkah 5 pakai QA_USER2 + fixture, bukan user developer; AC-23 role 6/29/16 -> fixture; X-4 role 2 nonaktif -> fixture SA2).
- ED-1026/qa: `qa_lib.php` (preset NONE/LIST, `q26_with_u2_preset`, guard), `scenario2-folders-store.php` (AC-3), `scenario7-round2.php` (R2-D1), `scenario1-schema-seed.php` (AC-2: asersi `role_permissions` developer 1/2/3/29/6 -> scratch).
- ED-1027/qa: `qa_lib.php` (preset LIST/NOLIST/U2, `q27_with_u2_preset`, guard grant, `q27_qa_rows`), `scenario8-user2.php` (X-8, X-9), `scenario9-permission-opname-document.php` (X-10 + rename konstanta), `scenario3-detail.php`, `scenario5-sessions.php`, `scenario10-display-setting-filter.php` (rename konstanta saja).
- ED-1028/qa: `qa_lib.php` (preset R0/NONE, guard), `qa_hist.php` (`q28_guard` menukar user2 ke preset R0 + jurnal user_roles asli), `scenario5-access.php` (AC-12, AC-14), `scenario9-display-setting.php` (AC-28 + hash `updater_logs`).

### Perluasan di luar daftar awal (K-3 b, AC-10 grep `ED-102[4-9]`; suite tetap hijau)

Ditemukan saat audit grep AC-10; semuanya lulus dan dilaporkan di sini karena menyentuh item "hanya dijalankan":
- ED-1024/qa (`qa_lib.php`, `scenario1-scope.php` AC-4, `scenario3-permission.php` AC-12/AC-13, `scenario5-extra.php` X-5): role developer 6/29/16/18 -> fixture `QA01-ROLE-*`.
  AC-13 dulu **menghapus sementara 12 baris `permissions` developer** (simulasi "tanpa seed Archive") dan X-5 **menonaktifkan sementara role developer 2**; keduanya
  melanggar § 10 (permissions/role 1-29 tak pernah diubah). Kini disimulasikan dengan role fixture (is_superadmin 3 dan 1) yang memegang semua permission aktif KECUALI 12
  permission Archive (middleware v5 hanya menilai nama permission milik role), dan fixture superadmin-2 yang dinonaktifkan.
- ED-1025/qa AC-16: dulu mengubah `user_roles`/`users.is_active`/`customers.id_user` milik **user developer** (dipulihkan); kini pada QA_USER2 (fixture List Archive, berjurnal).
- ED-1029/qa `scenario3-access.php` X-PERM, AC-20: dulu memindai role developer (`[5,26,6,16,18,19,20,25,29]`, "minimal 3 role ber-FP") dan memakai role asli adi;
  kini fixture `QA29-ROLE-*` (A/M/MW ber-FP; D/E/DW/N/Z tanpa) + QA_USER2 ditukar ke DW lalu MW (`q9_u2_swap`, dipulihkan persis). Kata "ED-1149" sengaja tidak ditulis di berkas itu
  (X-PERM/AC-20 memindai berkas `qa/` untuk literal id permission `11(3[3-9]|4[0-9])`).

## Snapshot AC-13 (bagian BE)

`"$PHP_BIN" features/ED-1149-*/qa/snapshot.php before` (sebelum apa pun) dan `after-be` (sesudah semua suite + ED-1150); `--compare before after-be` -> **SAMA**.
Tabel (n / hash baris / `CHECKSUM TABLE`) identik: roles 27, role_permissions 3795 (hash role 3 `1793413960154`, role 5 `73870335711`; role 5 ber-NULL 15 baris,
tetap), user_roles 126, permissions 832, archives 49149 (hash kolom verifikasi non-QA `105835480096193`, baris `sam` 400/12), archive_documents 49126, archive_locations 48040,
archive_permissions 10, archive_opnames 2, archive_opname_folders 3, archive_opname_documents 495, column_display_settings 361, updater_logs 99, employees 124,
employee_locations 157, users (kolom stabil) 129. Satu-satunya beda yang diizinkan: `users.updated_at` QA_USER `2026-10-13 12:00:15 -> 2026-10-14 12:22:38`
dan QA_USER2 `2026-10-13 11:45:54 -> 2026-10-14 12:22:39` (login). Sisa QA: `QA2x-ROLE-%` 0, role/role_permissions/archives/archive_documents/archive_permissions/
archive_opnames/column_display_settings berpenanda QA 0, `role_permissions`/`user_roles` yatim 0, berkas jurnal `.restore/.grant/.sessions/.verif` di `features/*/qa` 0
(jurnal sisa `.restore-journal-q27-user2.json` dari run lama ikut dipulihkan otomatis oleh baseline X-8: K-5 sudah tak berlaku).
Tidak ada data developer yang dihapus atau di-purge; hanya baris/role QA sendiri yang dibuat dan dibuang.

## Catatan untuk scope fe (tidak dikerjakan di sini; e2e tidak dijalankan)

- `qa/e2e/fixture.php` ED-1025..1028 berdiri sendiri (hanya `require` `scripts/qa-http/lib/{Outcome,Config,Laravel}.php`, bukan `qa_lib.php`): perubahan helper BE di atas
  **tidak** memengaruhi e2e dan tidak perlu kompatibilitas mundur.
- Yang masih memakai role developer di sisi e2e (AC-9, AC-10 FE, AC-12): ED-1026 mode `norole` (`fixture.php:22,144-146,397-398`: menghapus `role_permissions` Opname Document dari role
  QA_USER = role 3 lalu menyisipkan lagi); ED-1027 `fixture.php:12-18,300-342` dan ED-1028 `fixture.php:17-20,313-342,705-746` `grant-od`/`revoke-od` ke role QA_USER2 (role 5);
  `ED-1027-verifikasi.spec.js:1570` (AC-25) dan `ED-1028-opname-history.spec.js:1606` (AC-27 R0) mengandaikan role 5 tanpa OD. Nama preset BE yang bisa dipakai ulang: `QA26/27/28-ROLE-*`
  dengan permission `Allow Access Website` (+ `List Archive` bila perlu).
- ED-1024/ED-1029 `qa/e2e` belum diaudit untuk role developer (di luar daftar AC-9/AC-10 FE).

## Usulan (dicatat, tidak diterapkan di tengah run)

1. Pelajaran QA: "Skenario tidak membaca/mengubah himpunan permission role developer maupun baris `permissions`/`roles` bawaan; peran uji = role preset dari nama permission
   (+ `Allow Access Website` untuk user non-karyawan); bukti pemulihan = snapshot/hash sebelum vs sesudah".
2. `qa/snapshot.php` bisa dipakai ulang sebagai pengaman standar epic berikutnya (baca-saja, `--compare`, `--leftover`).
3. Catatan developer (spec § 9): v3 `MultiPermission.php:24` membaca `role_permissions.permission_name`; baris NULL hasil UI role hanya aman di v5.

## Riwayat ronde

- Ronde 1 (2026-10-14): PASS. Tulis ulang AC-1..AC-8 + perluasan AC-10, regresi AC-11 230 PASS / 2 SKIP / 0 FAIL, snapshot before = after-be.
