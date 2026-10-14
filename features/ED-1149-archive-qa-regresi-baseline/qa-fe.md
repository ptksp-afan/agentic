---
key: ED-1149
scope: fe
round: 1
verdict: PASS
counts: {pass: 4, fail: 0, manual: 0, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

Tahap FE task QA murni (tanpa kode aplikasi; BE `6fcc91fd0` dan FE `cf37552dd` tetap bersih, 0 berkas kotor). Hasil: e2e tidak lagi bergantung pada
role developer (mode `norole` ED-1026, `grant-od`/`revoke-od` ED-1027/ED-1028, `[user2]` tanpa Opname Document, role 26/28/18 di ED-1024/ED-1029,
permission role 3 yang disalin di ED-1029), regresi e2e 10 item dijalankan satu per satu dengan `--workers 1` dan **0 gagal akhir**
(142 OK, SKIP hanya yang disengaja per mode/state), snapshot data developer sebelum vs sesudah seluruh run (BE + FE) **SAMA**.

Build: `scripts/fe-build.sh build` sekali (Done in 105 s, staging `/tmp/ksp-react-v5-build` 2026-10-14T05:30Z >= commit FE `cf37552dd`), bukan ke `build/`
developer. Tanpa modul FE, jadi `yarn test` tidak dijalankan. Regresi BE tidak diulang: tidak ada berkas `qa/*.php` (non-e2e) yang berubah sesudah `qa-be.md`
(`find -newer` = 0), tidak ada berkas BE yang dimuat suite BE yang disentuh (fixture e2e berdiri sendiri, tidak di-`require` oleh suite BE; ED-1029 X-PERM
hanya memindai `qa/*.php`), HEAD sama; hasil `qa-be.md` (230 PASS / 2 SKIP / 0 FAIL) tetap berlaku.

Dua kegagalan putaran pertama bukan data baseline dan bukan defect kode: dua asersi e2e **usang** terhadap penyesuaian UI developer di FE `41480e37c`
(2026-10-12, review ED-1022), diperbarui ke desain yang didokumentasikan developer (D-0 di bawah). Satu "GAGAL" lain (ED-1024 state `noperm`, route smoke)
adalah perilaku yang memang didokumentasikan (403 karena tanpa permission) dan dijalankan ulang dengan `--grep AC-13` sesuai `ED-1024/qa-fe.md`.

## Hasil per AC

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-9 | `scripts/e2e/run.sh ED-1027 --profile default,user2 --workers 1` dan `ED-1028 ...`: `[user2]` tanpa OD memakai QA_USER2 di preset yang dipasang `fixture.php up` SEBELUM login; `grant-od` hanya ke preset; layar Archive user2 terbuka tanpa error (route smoke `[user2]`) | PASS | ED-1027: 21 OK / 0 GAGAL, termasuk `[user2] AC-25` (:1570), `[user2] AC-13/16/17/28`, `[user2] AC-26/AC-28`, smoke `[user2] Archive B`; log `work/ED-1149/e2e/ED-1027.log`: `QA_USER2 = preset QA27-ROLE-E2E-U2` ... teardown `1 role preset dibuang, user_roles QA_USER2 dipulihkan; tidak ada sisa`. ED-1028: 16 OK / 0 GAGAL, termasuk `[user2] AC-27 R0` (:1606), `[user2] AC-21`, smoke `[user2] Archive B`; `ED-1028.log`: `QA_USER2 = preset QA28-ROLE-E2E-R0`. Kontrol negatif: `fixture.php grant-od` tanpa `up` (QA_USER2 masih role 5) -> `ERROR: grant-od ditolak: QA_USER2 tidak (hanya) berada di preset ... role developer tidak pernah di-grant` (ED-1027 dan ED-1028). Galeri: lihat tabel e2e |
| AC-10 (FE) | Audit seluruh `features/ED-102[4-9]*/qa/e2e` + grep; ED-1026 `norole` -> preset; ED-1024/ED-1029 diganti | PASS | ED-1026 `QA26_MODE=norole`: QA_USER ditaruh di preset `QA26-ROLE-E2E-NOOD` (16 permission eksplisit: 12 izin Archive/website + 4 izin Outside Radius; `Opname Document` = 0 menurut DB), test `AC-3/FB-8a role (preset) TANPA Opname Document` OK (menu baris tepat `Info, Pindahkan, Lihat, Hapus`, nol request `opnames/*`); `down`: `1 role preset dibuang ... user/role QA = snapshot`. Grep (di bawah) = 0 temuan |
| AC-12 | e2e 10 item satu per satu, `--workers 1`, profil sesuai header (lihat tabel e2e); flaky/usang diulang sendirian dulu | PASS | 0 gagal akhir yang berasal dari baseline atau alat QA: 142 OK, 0 GAGAL, SKIP hanya per mode/state; 2 asersi usang diperbaiki lalu diulang (D-0) |
| AC-13 (akhir) | `"$PHP_BIN" features/ED-1149-*/qa/snapshot.php after-fe` lalu `--compare before after-fe` dan `--compare after-be after-fe`; `--leftover` | PASS | `SAMA: snap-before == snap-after-fe` dan `SAMA: snap-after-be == snap-after-fe`; sisa QA 0; berkas jurnal 0 (lihat "Snapshot AC-13") |

AC lain task ini (AC-1..AC-8, AC-11, AC-10 sisi BE) dimiliki tahap BE: PASS di `qa-be.md`, tidak diulang di sini.

## Audit & perubahan alat e2e (BR-1..BR-7)

Setiap `fixture.php`/`state.php` kini mencatat baris `user_roles` QA_USER/QA_USER2 ke jurnal SEBELUM menukar, membuat role preset `QA2x-ROLE-E2E-*` dari
**nama permission eksplisit** (`permissions.permission_name` -> `id_permission`, `created_by` = tag QA, memuat `Allow Access Website` bila user non-karyawan,
izin Outside Radius bila karyawan berlokasi terbatas), memasangnya SEBELUM sesi browser dibuka, lalu di `down` memulihkan `user_roles` persis dan membuang
preset (role_permissions + roles), dengan pemeriksaan sisa. Role developer tidak dibaca sebagai prasyarat, tidak di-grant, tidak di-revoke.

| Item | Ketergantungan lama | Perbaikan |
|---|---|---|
| ED-1026 `fixture.php` | mode `norole` mencabut baris Opname Document dari **role 3** lalu menyisipkan lagi (id baris sama) | preset `QA26-ROLE-E2E-NOOD` (is_superadmin 3 = tingkat role asli; 12 izin Archive + 4 izin Outside Radius; login karyawan berlokasi SMR tanpa itu = 422 geotag), snapshot `user_roles`, jurnal v3 |
| ED-1027 `fixture.php` | `grant-od` menyisipkan Opname Document ke **role 5**; `[user2]` mengandaikan role 5 tanpa OD | preset `QA27-ROLE-E2E-U2` (izin Archive "Sales Supervisor" tanpa OD + Allow Access Website) dipasang di `up`; `grant-od` DITOLAK untuk role non-preset; `rpSum` hanya role preset (bukan seluruh `role_permissions`); jurnal v3 |
| ED-1028 `fixture.php` | sama (role 5) | preset `QA28-ROLE-E2E-R0`, aturan sama |
| ED-1024 `state.php` | `jog` = role developer **26**, `noperm` = role **28** (bergantung pada isi izinnya); `super` **mengaktifkan sementara satu baris `archives` developer** (Billing `is_active -1 -> 1`, melanggar spec § 10) | `jog` = preset `QA24-ROLE-E2E-JOG` (14 izin), `noperm` = preset `QA24-ROLE-E2E-NOPERM` (4 izin = isi role 28), `super` = role sistem 1 (hanya `user_roles` QA_USER) + dokumen Billing **milik fixture** `BILL/QA24-E2E/00001` (`created_by` QA24E2E, dibuat lalu dibuang); tidak ada lagi baris `archives` developer yang disentuh |
| ED-1029 `fixture.php` | E = role developer **18**; profil D menyalin permission role A (**role 3**); user target B/B2 dipilih lewat kolom `role_permissions.permission_name` (NULL pada baris hasil UI role); `role <id>` menerima id role apa pun | E = preset `QA29F-ROLE-E` (5 izin = isi role 18), D = `D_PRESET` eksplisit, B/B2 dipilih per `id_permission` (join `permissions`, BR-3), `role` hanya `1` (role sistem) atau `restore` |
| ED-1025 spec | hanya teks catatan "role 5" | teks diperbarui; sudah memakai preset `QA25-ROLE-*`, tidak ada perubahan perilaku |
| ED-1069, ED-1071, ED-1072, ED-1150 | tidak menyentuh role | tidak diubah (ED-1072: lihat D-0) |

Berkas yang diedit (hanya `qa/e2e/` item pemilik, plus alat ED-1149 sendiri; tanpa kode aplikasi, spec, contract, `qa-*.md` item lain, `baseline/*.status`):
`ED-1024/qa/e2e/{state.php,ED-1024-archive.spec.js,fixtures.json}`, `ED-1025/qa/e2e/ED-1025-folder-permission.spec.js`,
`ED-1026/qa/e2e/{fixture.php,ED-1026-opname.spec.js,fixtures.json}`, `ED-1027/qa/e2e/{fixture.php,ED-1027-verifikasi.spec.js,fixtures.json}`,
`ED-1028/qa/e2e/{fixture.php,ED-1028-opname-history.spec.js,fixtures.json}`, `ED-1029/qa/e2e/{fixture.php,ED-1029-user-permission.spec.js,fixtures.json,routes.json}`,
`ED-1072/qa/e2e/ED-1072-klik-sel.spec.js`, `ED-1149/qa/snapshot.php` (glob jurnal diperluas ke `features/*/qa/e2e/`).

### Grep AC-10 (`features/ED-102[4-9]*/qa`, termasuk e2e)

- Konstanta/fungsi lama `Q2[678]_ROLE_(LIST_ONLY|NONE)`, `q2[78]_u2_roles(`, `E_ROLE`, `permissionId(`, `'role' => 22`: **0** baris.
- Id role developer (5, 6, 16, 18, 22, 26, 28, 29) sebagai `id_role`/nilai penukar di kode e2e: **0** baris (hanya komentar yang menyebut "dulu role 18").
- Penulisan `role_permissions` di e2e: hanya `ensure/makeRole/presetCreate` (baris role preset), `grant-od` (dijaga: ditolak bila role bukan `QA27/28-ROLE-E2E-*`), dan
  `delete` atas id preset (`presetPurge`/`purgeRoles`) atau id baris grant (`created_by` QA27E2E/QA28E2E). Tidak ada `update`.
- Penulisan `user_roles` di e2e: hanya untuk QA_USER/QA_USER2 (snapshot -> preset/role sistem 1 -> pulih persis).
- Pembacaan `role_permissions` lewat kolom `permission_name` sebagai prasyarat: **0** (sebelumnya ED-1029 `fixture.php`).
- Bukti data developer tak tersentuh: hash `role_permissions` role 3 (`1793413960154`, 832 baris) dan role 5 (`73870335711`, 40 baris) sama di snapshot before dan after-fe.

## Regresi e2e AC-12 (satu suite per giliran, `--workers 1`)

Log: `work/ED-1149/e2e/<suite>.log`. Galeri = `work/e2e/<folder>/index.html` (+ `report.json`, `screenshots/`). 0 respons 5xx, 0 entri allow-list.

| Suite (profil) | Hasil akhir | Galeri (run akhir) |
|---|---|---|
| ED-1024 `E2E_STATE=jog` (default) | 2 OK (AC-16, route) / 0 GAGAL / 2 SKIP (gated state) | `ED-1024-archive-lokasi-transaction-type-20261014-055542` |
| ED-1024 `E2E_STATE=super` (default) | 2 OK (AC-17 Billing dari dokumen milik fixture, route) / 0 / 2 SKIP | `...-20261014-055610` |
| ED-1024 `E2E_STATE=noperm -- --grep AC-13` (default) | 1 OK (AC-13 FE) / 0 / 0 | `...-20261014-055649` (run penuh tanpa grep: `...-055626`, route smoke 403 sesuai desain, lihat catatan) |
| ED-1025 (default,user2) | 21 OK / 0 / 0 | `ED-1025-archive-folder-permission-20261014-062538` |
| ED-1026 main (default) | 15 OK / 0 / 1 SKIP (test `norole`, sengaja) | `ED-1026-archive-opname-document-20261014-062115` |
| ED-1026 `QA26_MODE=norole` (default) | 2 OK (test norole preset + route) / 0 / 14 SKIP (mode main) | `...-20261014-054501` |
| ED-1027 (default,user2) | 21 OK (4 `[user2]`) / 0 / 0 | `ED-1027-archive-status-verifikasi-20261014-054525` |
| ED-1028 (default,user2) | 16 OK (3 `[user2]`) / 0 / 0 | `ED-1028-archive-opname-history-20261014-055023` |
| ED-1029 (default,user2) | 30 OK (7 `[user2]`) / 0 / 0 | `ED-1029-archive-set-permission-by-user-20261014-060337` |
| ED-1069 (default) | 5 OK / 0 / 0 | `ED-1069-archive-validasi-pindah-rename-folder-20261014-061053` |
| ED-1071 (default) | 7 OK / 0 / 0 | `ED-1071-v5-collation-latin1-20261014-061241` |
| ED-1072 (default) | 11 OK / 0 / 0 | `ED-1072-archive-fe-klik-menu-baris-20261014-061639` |
| ED-1150 `run-sam.sh --workers 1` (sam = QA_USER3) | 9 OK / 0 / 0 | `ED-1150-archive-fe-virtualtable-list-pendek-20261014-061847` |

Total OK: 142; GAGAL akhir: 0. Teardown fixture tiap run: OK ("tidak ada sisa milik fixture"). Setiap sesi (QA_USER, QA_USER2, sam) di-log-out harness dan
dicek: `is_login = 0`, token aktif 0 untuk ketiganya sesudah semua run. ED-1024 state `none` tidak dijalankan sendiri: hanya meng-skip test bergating-state; smoke route sudah OK di tiap state.

## Defect

Tidak ada defect kode (baru maupun known) yang terlihat di tahap ini. Tidak ada respons 5xx di e2e (laporan `report.json` 14 run: `5xx: 0`); log BE
`laravel-2026-10-14.log` pada jendela run hanya memuat 24 entri `OAuthServerException ... denied the request` = pemeriksaan 401 pasca-logout oleh harness (diharapkan).

Kegagalan yang ditemukan dan diselesaikan sebagai alat QA (bukan baseline data, bukan defect kode, BR-8 tetap dihormati: tidak ada yang disamarkan):

- **D-0a** (alat QA usang) ED-1026 `AC-4/AC-14/AC-29 Steps small+navigation ...` (`ED-1026-opname.spec.js:1713`): `expect(d1.containerW).toBeLessThanOrEqual(803)` didapat 1052.
  Penyebab: FE `41480e37c` (developer 2026-10-12, review ED-1022) menghapus `max-width: 803px` dari `.modal-opname-archive .steps-container`
  (`src/containers/Archive/archive.style.less:115-117`; docs/specs/Archive/018: "container mengikuti lebar modal dan hanya `.ant-steps` yang 803px").
  Deterministik (gagal di suite penuh dan sama saat diulang sendirian). Diperbarui ke desain sekarang: `.ant-steps` tetap min-width 803 (`stepsScrollW >= 803`); asersi lain (`containerW < bodyW - 40`,
  tanpa gulir di desktop, 768 px, 375 px gulir otomatis per langkah) tetap. Diulang sendirian: OK (33 dtk, seluruh alur 375 px lulus), lalu suite penuh main: 15 OK.
- **D-0b** (alat QA usang) ED-1072 `AC-3` dan `AC-7` (`ED-1072-klik-sel.spec.js:265,468`): judul drawer Info `toHaveText('Info')`, didapat `Info0QA72-D1` / `Info0QA72-F`.
  Penyebab: FE `41480e37c` menambah subjudul drawer Info (`ArchiveDrawerInfo/index.js`: `FormTitle subTitle` = nomor transaksi / nama folder baris). Asersi diperketat:
  `^Info\s*<nama baris yang diklik>$`. Suite penuh diulang: 11 OK.
- **D-0c** (perilaku didokumentasikan) ED-1024 state `noperm`, route smoke `Archive (layar terbuka tanpa error)`: GET `document-archive/archives` 403 (+ error konsol Axios) karena
  preset memang tanpa permission Archive. `ED-1024/qa-fe.md:43` menetapkan state ini dijalankan dengan `--grep "AC-13"`; dilakukan, hasil OK. Bukan defect.
- **D-0d** (alat QA, selama penulisan) login QA_USER di preset `NOOD` pertama gagal `422 (validasi: geotag)`: preset belum memuat izin Outside Radius yang dimiliki role 3 (karyawan
  berlokasi SMR saja). Izin ditambahkan ke preset; teardown run gagal itu tetap bersih.

Catatan untuk orchestrator (tidak diubah di sini sesuai aturan): `ED-1026/spec.md` BR-29 (rev) dan AC-29 ("desktop lebar dibatasi") serta `ED-1072` terhadap judul drawer Info
usang terhadap penyesuaian UI developer `41480e37c`; layak diselaraskan sebagai catatan, bukan tiket defect.

## Snapshot AC-13 (final)

`snapshot.php after-fe`, `--compare before after-fe`: **SAMA**; `--compare after-be after-fe`: **SAMA**. Tabel (n / hash / `CHECKSUM TABLE`) identik: roles 27, role_permissions 3795
(role 3: 832 baris hash `1793413960154`; role 5: 40 baris hash `73870335711`), user_roles 126, permissions 832, archives 49149 (hash kolom verifikasi non-QA `105835480096193`;
baris `updated_by='sam'` 400, hash `861167797856`), archive_documents 49126, archive_locations 48040, archive_permissions 10, archive_opnames 2, archive_opname_folders 3,
archive_opname_documents 495, column_display_settings 361, updater_logs 99, employees 124, employee_locations 157; `user_roles` QA_USER/QA_USER2 baris identik.
Satu-satunya beda yang diizinkan: `users.updated_at` QA_USER `2026-10-13 12:00:15 -> 2026-10-14 13:33:02`, QA_USER2 `2026-10-13 11:45:54 -> 2026-10-14 13:33:03` (login);
sam (QA_USER3) `updated_at` 2026-10-14 13:20:29 (login ED-1150, diizinkan; tidak ikut snapshot). Sisa QA: `QA2x-ROLE-%` 0, role/role_permissions/archives/archive_documents/
archive_permissions/archive_opnames/column_display_settings berpenanda QA 0, yatim 0; berkas jurnal `.fixture/.grant/.state-journal` di `features/*/qa` dan `qa/e2e` = 0.
Tidak ada data developer yang dihapus atau di-purge; hanya baris/role/dokumen QA sendiri yang dibuat dan dibuang. `snap-after-fe.json` di `work/ED-1149/`.

## Yang tidak bisa diverifikasi (dan kenapa)

Tidak ada untuk AC tahap ini. Ketergantungan baseline yang MASIH ada (dibaca saja, tidak diubah; tidak membuat run gagal; dicatat agar tidak mengejutkan):

1. `[default]` = QA_USER tetap berada di role 3 dan diandalkan memegang izin Archive lengkap (termasuk Opname Document, Folder Permissions, All Archive) di ED-1026/1027/1028/1029/1069/1071/1072
   (dan ED-1025 `orig`); ED-1150 mengandalkan role asli `sam` (role 5). Di luar daftar K-3 (6/22/29 + norole); role 3 sengaja tidak diganti. Bila developer mencabut izin itu dari role 3/5 lewat UI, test akan gagal.
2. Role sistem 1 (superadmin EQUAL) dirujuk lewat `user_roles` QA_USER oleh ED-1024 `super`, ED-1025 `SUPER` dan ED-1029 `role 1`; isinya (832 izin) dianggap utuh. Hanya `user_roles` yang ditukar.
3. Data developer yang dipakai asersi (bukan role): ED-1024 folder `Backup Arsip`, `CABANG - JOGJA/SEMARANG`, `SMLYK...`; lokasi SMR QA_USER2 (employee) untuk angka `[user2]` lokasi terbatas;
   dua user target ED-1029 (B=`admintest`, B2=`andika`, dipilih fixture per `id_permission`; baris `archive_permissions`-nya dibuang di `down`).
4. Tukar lisensi sungguhan dan isolasi tenant (satu DB QA): tetap MANUAL Gate 2 epic (spec § 9), tidak terkait tahap ini.

## Usulan (dicatat, tidak diterapkan di tengah run)

1. Pelajaran QA: user uji non-developer untuk e2e dipasang lewat role preset dari nama permission **di `fixture.php up` sebelum login** (bukan grant ke role developer), jurnal `user_roles` + `finally`/`down` + pemeriksaan sisa;
   karyawan berlokasi terbatas butuh izin Outside Radius, non-karyawan butuh Allow Access Website.
2. `qa/snapshot.php` (kini juga memantau jurnal e2e) dipakai ulang sebagai pengaman standar epic berikutnya.
3. Asersi tata letak/judul di e2e rawan usang bila developer menyetel UI sesudah QA: rujuk komentar FE terbaru (`archive.style.less`) saat menulis angka piksel.

## Riwayat ronde

- Ronde 1 (2026-10-14): PASS. Alat e2e ED-1024/1026/1027/1028/1029 dialihkan ke preset (role developer tidak disentuh), 2 asersi usang (ED-1026, ED-1072) diselaraskan dengan FE `41480e37c`,
  regresi e2e 142 OK / 0 GAGAL, snapshot before = after-fe.
