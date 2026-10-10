---
key: ED-1028
scope: be
round: 1
verdict: PASS
counts: {pass: 16, fail: 0, manual: 0, not_verifiable: 1}
be_server: fpm
---
## Ringkasan

QA BE **revisi** (ronde 1 revisi, 2026-10-10) atas kode BE ED-1100 (saring `folders[]`/`warnings[]`) dan ED-1101 (riwayat hanya untuk `Opname Document`), diff vs baseline `3ae86a5c1` (4 berkas: `OpnameService.php`, `SelectArchiveService.php`, `ColumnDisplaySettingService.php`, `Routes/DocumentArchive/api.php`). Laporan ronde 1 asli (2026-10-07, PASS 14/14) ada di git: `git show 934f11f:features/ED-1028-archive-opname-history/qa-be.md`.

- Cakupan: 15 AC `[BE]` (AC-1, 2, 4-14, 26, 28; AC-3 dihapus oleh FB-3) + sisi BE AC-24 = **16 PASS, 0 FAIL**. Tidak ada HTTP 500 dari kode item ini. Verdict PASS.
- Skenario permanen di `qa/` (9 berkas `scenario*.php` + `qa_lib.php`, `qa_hist.php`): **24 skenario, 3.309 asersi, semuanya PASS** pada run penuh terakhir di DB tanpa data developer (`work/qa-ed1028-rev/final-full2.txt`). Seluruh suite juga dijalankan terhadap **data developer simulasi** (4 sesi termasuk 1 sesi root + 4.241 baris snapshot + 4.241 dokumen root bertanda verifikasi): 24/24 PASS, dan DB identik (jumlah + CRC) sebelum/sesudah.
- Server `fpm` (tanpa reload). Penolakan izin/classifier: **0** (satu `sleep` foreground diblokir guard tool, bukan penolakan izin; diganti loop `until`).
- 3 catatan di luar item ("Bug di luar item"), tidak memblokir. Tidak ada defect pada ED-1100 / ED-1101.
- Subtask ED-1102 (QA, folder ini saja): skenario usang diperbarui + skenario baru AC-26/AC-24/AC-28 + pengaman data uji (lihat bagian sendiri).

## Hasil per AC

Perintah: `"$PHP_BIN" scripts/qa-http/run.php ED-1028 [--only=ID]`. Oracle = SQL mentah + PHP tanpa kode BE (`q28_oracle`, `q28_oracle_sessions`, `q28_summary_oracle`) selain angka tangan. Role: user2 = `QA_USER2` (adi, role asli 5 = `List Archive` tanpa `Opname Document`, SMR) + grant sementara `Opname Document` (per NAMA, id 1139) di `q28_guard`; R0 = role asli itu; user3 = `QA_USER` role 3; superadmin = role 1/2 sementara.

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | `scenario1` AC-1: baris display setting dihapus lebih dulu, request pertama membuatnya 1x; 200 `ARCHIVE200`; kunci paginator; 7 kolom urut BR-6 = config = DB; available 7+2; 3 query (`dateTimeRange`, select `opname-users`, select `folders` multiple); urut `confirmed_at` turun; kunci baris; halaman 1-3 = potongan urutan penuh, halaman di luar jangkauan kosong; 6 parameter aneh tidak 5xx; pesan ID/EN | PASS | 155 asersi: `sesi QA ada di atas sesi developer: 6 baris pertama seluruhnya sesi QA` |
| AC-2 (rev) | `scenario1` AC-2: angka baris = kolom `archive_opnames` (angka tangan A 9/2/1/1); `scope` baris = `scope` `GET opnames/{id}` = **tepat** `is_root, folder_name, subfolder_count` (tanpa `first_name`/`other_count`), `subfolder_count` = `scope_folder_count`; 9 sesi (A,B,C,D,E,Y,Z,R,M): A `{false,F,1}`, C `{true,null,1}`, B `{false,S2,0}`, R `{true,null,3}`, M `{false,F,2}`; nama subfolder tak ada di scope; dokumen ditambah / di-unverify / dinonaktifkan sesudahnya -> semua baris identik | PASS | 193 asersi |
| AC-4 | `scenario2`: query username (sebagian, besar-kecil, trim), nama folder level 0/1/2 snapshot, `%` `_` `\` harfiah, teks non-latin1/emoji/injeksi/3.000 char = 200 kosong, user2: nama tak terlihat tidak cocok, oracle 10 teks x 2 user, search bukan JSON = 422 | PASS | 152 asersi |
| AC-5 | `scenario2`: `confirmedAt` inklusif kedua ujung (selisih 1 detik), satu ujung, `[null,null]`, awal>akhir kosong, 10 bentuk tak valid = 422 (kunci `confirmedAt.0/.1/confirmedAt`) | PASS | 98 asersi |
| AC-6 | `scenario2`: `createdBy` persis; `idArchives` [F1,F2] = ATAU termasuk subfolder; AND dengan query/confirmedAt (4 filter); user2 id di luar scope tidak cocok; non-array = 422 | PASS | 106 asersi |
| AC-7 | `scenario2`: 8 kolom x asc/desc x bentuk JSON-string/objek = urutan oracle; pemecah seri; `scope`/kolom asing/injeksi diabaikan 200; 2 sort; lintas halaman 3+3+3 = potongan urutan penuh | PASS | 179 asersi |
| AC-8 | `scenario3`: superadmin 1 dan 2 = semua; user3 / user2 = oracle (EPIC K-1 a); 3 baris pertama = `last_sessions` `GET verifications`; total = oracle | PASS | 96 asersi |
| AC-9 | `scenario3`: `id_archive` F/S1/OTH/S1a/P = oracle + angka tangan; 3 baris pertama = `last_sessions` `GET verifications/{F}`; AND; 404 `ARCHIVE400`, 400 `ARCHIVE417`, 403 `ARCHIVE407` (user3 dan user2), konteks dicek sebelum search | PASS | 201 asersi |
| AC-10 | `scenario4`: select folders = oracle (superadmin 35, user3 27, user2 19 folder), label path, urut, search, `selected_id`, tanpa 5xx | PASS | 229 asersi; ~190-225 ms |
| AC-11 | `scenario4`: username unik sesi terlihat = oracle SQL; angka tangan hanya untuk username QA (tahan data developer); search / `%` / `_` / `selected_id`; sesudah sweep sesi QA = oracle sesi developer saja | PASS | 126 asersi |
| AC-12 (rev) | `scenario5`: user2 **ber-Opname Document**: A,B,C,Y,Z `show`+`documents` = 200; D, E, G1, G2, id tak ada/panjang/kutip/non-ASCII = 404 `ARCHIVE412` (3 URL) pesan EN; **id bekas draft** (baris sesi dibuang) dan sisa baris `status` 1 dan 3 (disisipkan) = 404 untuk user2, user3 (pembuat), superadmin; pembuat selalu terbaca; alur ED-1026 (Step 1 -> scan tanpa menulis -> satu POST -> show -> documents -> muncul di riwayat user3 dan user2) | PASS | 414 asersi; prasyarat per nama: `Opname Document` milik user2 hanya dari grant QA28 |
| AC-13 | `scenario3`: sesi root superadmin dibaca user2 dan user3 (pembuat): `documents` tiap filter (semua halaman) = oracle, `result_counts`, `total` paginator, `is_partial=true`, Invalid selalu tampil, `counts` = rekaman; superadmin `is_partial=false`, `result_counts` = `counts` | PASS | 143 asersi |
| AC-14 (rev) | `scenario5`: **R0** (role asli user2): 11 URL (`GET opnames` + `id_archive` dalam/luar scope/tak ada, search valid/tak valid, `opnames/{S}`, `/documents` (+`result=all`), id tak ada) = 403 `GE0114` `parameter` "Opname Document", tanpa data/kebocoran, pesan EN; `opnames/folders`, `POST opnames`, `POST opnames/scan` 403 (milik ED-1026 AC-3, dicatat); select opname-users 200 `options: []` (juga dengan `search`/`selected_id`); select folders = scope (oracle); **role 22** (tanpa permission Archive) = 403 "Opname Document" (bukan "List Archive") di 11 URL; grant sementara = 200/404 sesuai, dicabut = 403; role dan `role_permissions` (jumlah + CRC) dipulihkan persis; tanpa token 401 | PASS | 235 asersi |
| AC-24 (BE) | `scenario8` AC-24: hak folder FB-9 tidak mengubah sesi terlihat: user3 `V` (View) terlihat, `VC` (anak Izin On tanpa baris), `U` (hanya Update/Delete/Store), `UC` (induk tanpa View) tidak; user2 hanya `O`; `U` diberi View -> `U`,`UC` terlihat; `V` dicabut View (tinggal U/D/S) -> hilang; riwayat = oracle dan `show` 200 / 404 `ARCHIVE412` per kasus. Alur opname ED-1026 (scan/pratinjau/Confirm) = regresi ED-1026 39/39 | PASS | 81 asersi |
| AC-26 (rev) | `scenario8`: sesi S (superadmin) dari W dengan A(A1, A2 Izin On), B(B1) Izin On tanpa hak, C(C1 tersembunyi), D(D1 tersembunyi). user2 **dan** user3: `folders[]` = W, A, C, D, A1 (= oracle); `warnings[]` = W, A, C `unverify_count` 1/1/1 (A = SUM SQL baris terlihat A+A1; D hasil 0 **tidak dikirim**; B tidak ada); nama **dan id** B, B1, A2, C1, D1 tak ada di respons mana pun; `scope` 3 kunci; `counts` = kolom; `result_counts`/`is_partial` = oracle (parsial). Superadmin 1 dan 2: 10 baris, 5 peringatan [1,2,1,2,1], `is_partial=false`. **Sesi root**: baris root selalu ikut, RH tersaring. **Pembuat non-superadmin** (S2): View X dicabut -> X tersaring; View W2 dicabut -> `folders: []`, tetap 200; `counts` tetap | PASS | 176 asersi. Excerpt: `D semula bernilai 1 (D1), terlihat 0 -> peringatan D hilang` |
| AC-28 (rev) | `scenario9`: baris dihapus dulu: R0 `GET`/`PUT` = 403 `GE0114` "Opname Document" **dan baris tidak dibuat** (juga varian huruf besar/kecil, spasi akhir); sesudah pemegang membuatnya: R0 `GET`/`PUT` (isi sama/beda/skalar) 403, baris identik (+ `updated_at`); `GET setting/display-columns` R0 tanpa baris itu di semua halaman, `total` = pemegang - 1 (360 vs 361); `search=documentArchive` R0 -> hanya `documentArchive`, pemegang -> dua; `documentArchive` dan `supplier` tetap 200; pemegang: baris tampil, `GET`/`PUT` 200; user2 + grant = 200; sidik jari tabel `column_display_settings` dipulihkan persis | PASS | 213 asersi. Cabang "seluruh modul" ED-1027 (dulu hanya tingkat servis) kini terbukti lewat HTTP |
| X-1..X-8 | skenario tambahan permanen: X-1 display setting (kolom aktif = kunci baris; kini lewat **API v5**, sesuai `REACT_APP_API_BASEPATH=api/v5`) 86; X-2 3.000 sesi sisipan (208-253 ms) 88; X-3 isolasi user bergantian (6 putaran, bahasa tak bocor) 65; X-4 fuzz 460 request (200/400/404/422, nol 5xx) 50; X-5 bentuk parameter FE 59; X-6 20.000 folder (select 262-415 ms) 19; X-7 sesi 39.241 baris (show 205-392 ms) 57; X-8 30.000 folder + `idArchives` 91 id (<1 s) 88 | PASS | `work/qa-ed1028-rev/final-full2.txt` |

## Defect

Tidak ada defect pada ED-1100 (BE saring `folders`/`warnings`) maupun ED-1101 (BE `Opname Document`): AC-12, AC-14, AC-26, AC-28 PASS, tanpa 5xx. Catatan non-defect (perlu diketahui developer):
- `PUT setting/display-columns/documentArchiveOpnameHistory` oleh R0 **tanpa** `current_columns` = **422** (validasi controller `ColumnDisplaySettingController.php:31` berjalan sebelum cek permission di service), bukan 403. Tidak membaca/menulis apa pun; sama dengan modul `documentArchive` (ED-1027). FE selalu mengirim `current_columns`. Asersi AC-28 menerima 403 atau 422.
- Select `opname-users` dan display setting memakai `MyHelper::hasPermission`, sedangkan route memakai `permission_v5` (selisih kecil, lihat "Bug di luar item" B-2); sesuai spec §4 (preseden `TransactionPrintService.php:37`).

## Isolasi tenant & permission

| Endpoint | Pemegang `Opname Document` | Tanpa (R0 / role 22) | Tanpa token |
|---|---|---|---|
| GET `opnames` | superadmin 1/2, user3, user2(+grant): 200 | 403 `GE0114` "Opname Document" (dicek pertama, sebelum id/lokasi/View/validasi) | 401 |
| GET `opnames/{id}`, `/documents` | 200 / 404 `ARCHIVE412` sesuai visibilitas | 403 `GE0114`, juga id tak ada | 401 |
| GET select `opname-users` | username sesi terlihat | 200 `options: []` | 401 |
| GET select `folders` | konvensi select: data dalam scope | 200, = scope user (oracle) | 401 |
| GET/PUT `setting/display-columns/documentArchiveOpnameHistory` | 200 | 403 `GE0114` "Opname Document" | - |
| GET `setting/display-columns` | memuat baris | tanpa baris, `total` - 1 | - |

Isolasi tenant antar-DB: **tidak bisa diverifikasi** (`QA_DBS` = 1 DB, `api_sidomaju`). Pengganti: X-3 (6 putaran bergantian user2/user3: hasil, select, bahasa EN/ID identik tiap putaran) dan tinjauan diff: tidak ada `static`, singleton, `Cache::`, `config([...])`, `$GLOBALS` baru di 4 berkas; `hiddenModules()` dihitung per request. Ulangi saat ada dua DB tenant (RoadRunner tidak dipakai di mesin ini).

## Tinjauan kebenaran diff BE

- **Kontrak vs aktual** (diperiksa lewat HTTP): 403 `{message, code: GE0114, parameter: "Opname Document"}`; select opname-users R0 `{status, msg_code: SUCCESS, result: {default: null, options: []}}`; `folders[]` kunci `id_archive, name, level, is_continue, is_opnamed_today, document_count`; `warnings[]` `id_archive, name, is_opnamed_today, unverify_count`; `scope` tiga kunci; display setting 403 sebelum baris dibuat. Semua sesuai kontrak §1-§3, §6, §7. Tidak ada kode pesan baru (`GE0114` ada di kedua berkas bahasa).
- **D-1 (`confirmedSummary`)**: pengelompokan level 2 -> baris Step 1 memakai struktur snapshot (juga lewat baris tersembunyi), saringan hanya menentukan baris yang dijumlahkan dan ditampilkan; root (`id_archive` null) selalu ikut; `counts` tidak disaring; himpunan = `context['folders']` (sama dengan `whereVisibleRows`), tanpa query pohon kedua; `show` meneruskan `$context` yang sudah dihitung `readable()`. Benar untuk: folder tak terlihat karena Izin Folder, induk tanpa View, folder terhapus/nonaktif, pembuat yang kehilangan View.
- **Rute** `api.php:42,45,49`: tiga rute GET `opnames*` kini `permission_v5:Opname Document`; komentar `api.php:40` tidak lagi menyebut `List Archive` untuk baca sesi (O-5).
- **Display setting**: entri seluruh modul di `PERMISSION_FILTERS`; `index` membuang baris lewat `whereNotIn` (kolasi tak peka huruf/spasi sama dengan `permissionFilter`), `total` ikut; varian nama dan baris-belum-ada ditolak sebelum dibuat/ditulis.
- Skema: tanpa Updater, tabel, atau permission baru; tidak ada kolom v3 yang disentuh (hanya baca); tidak ada tulisan baru -> tidak ada rollback yang perlu diuji.

## Skrip QA yang diperbarui (ED-1102, hanya `features/ED-1028-archive-opname-history/qa/`)

| Berkas | Perubahan |
|---|---|
| `qa_lib.php` | `q28_session/scan/confirm/run` -> alur stateless ED-1026 (**satu** `POST opnames` dengan `scans` + `selected_at`; `Q28Session`, `q28_wait`); `q28_baseline/sweep/cleanup` -> pola pengaman data uji (pra-citra, jumlah + CRC, `q28_bulk_purge`); grant sementara berjurnal (`q28_grant/with_grant`); `Q28_OD`, `Q28_LA` |
| `qa_hist.php` | fixture H (draft) dan K (batal + `DELETE`) dibuang; `q28_hfix` + `extras`; waktu sesi QA di atas sesi developer (`q28_day`); `pagination=100` = semua halaman; `q28_hkeys` membuang sesi asing; `q28_guard` memberi user2 `Opname Document` + bukti `role_permissions` persis; `q28_summary_oracle` |
| `scenario1-list.php` | kunci `scope` = `is_root, folder_name, subfolder_count`, asersi `first_name/other_count` -> `subfolder_count`; AC-3 (draft/batal + `DELETE`) dihapus; R dan M dibuat lewat `extras` |
| `scenario5-access.php` | AC-12 dan AC-14 ditulis ulang (prasyarat per nama, user2 ber-OD, id bekas draft 404, rute dihapus ED-1026 dibuang, R0 / role 22 "Opname Document", `options: []`); X-1 ke API v5 |
| `scenario2/3/4/7` | tahan data developer (kunci dilingkup, total = oracle, halaman potongan urutan penuh), user2 ber-OD, tanpa H/K |
| `scenario8-folders-filter.php` (baru) | AC-26 (D-1) dan AC-24 (BE, FB-9) |
| `scenario9-display-setting.php` (baru) | AC-28 (K-8 a, K-9 a) |
| e2e `ED-1028-opname-history.spec.js` | **tidak disentuh** (tahap FE): `.ant-segmented-item` -> `CheckableTag`, AC-21 user2 ber-OD, profil R0 AC-27, asumsi tabel opname kosong (`epics/ED-1022-ba2-notes.md`) = tugas QA FE. `e2e/fixture.php` tidak memakai `qa_lib.php` |

## Pengaman data uji (data developer)

- Tabel opname boleh kosong **atau** berisi data developer. `q28_baseline` mencatat pra-citra kolom verifikasi semua baris `archives` non-`QA28-` yang tidak default dan keadaan developer (jumlah + CRC `archive_opnames`, `_folders`, `_documents`, `archives`); `q28_sweep` hanya menghapus baris opname sesi di jurnal dan mengembalikan kolom verifikasi baris bertanda sesi itu ke **nilai sebelumnya** (bukan default); `q28_cleanup` membuktikan keadaan developer sama persis di akhir **setiap AC**. Penghapusan hanya untuk awalan `QA28-`/`QA28BULK*`/jurnal.
- Dibuktikan dengan data simulasi (bukan data developer asli), dijurnal: 4 sesi opname (`DEVSIM-`, pemilik `devsim.kevin`, `QA_USER`, `adi`, `kevinsudjadi.adm`; satu sesi **root** yang menandai 4.241 dokumen root nyata). Seluruh 24 skenario PASS dan jumlah + CRC DB identik sesudah suite (`devstate` sama persis). Simulasi menemukan satu asersi X-8 yang mengasumsikan semua sesi cocok `QA28-`; sudah dilingkup ke sesi QA.
- Simulasi dibuang seluruhnya: 4 sesi + 5 baris folder + 4.241 baris dokumen snapshot, 4 folder + 7 dokumen `DEVSIM-` (beserta `archive_documents`), kolom verifikasi 4.241 dokumen root nyata dikembalikan ke default (keadaan awal terbukti default: pra-citra 0 baris); keadaan DB sesudahnya = sebelum simulasi (CRC sama). **Data developer yang dihapus: tidak ada (0 baris).**
- Pemulihan: `role_permissions` (jumlah + CRC) sama sesudah tiap AC, 0 baris `created_by = QA28`; role user2 = [5], QA_USER = [3], lokasi dan bahasa (ID / EN) sama; baris `documentArchiveOpnameHistory` (1 baris, total 361) dan `documentArchive` utuh; tabel opname 0 baris; 0 sisa `QA28-`/`DEVSIM-`/`QA28BULK`; token run 0 aktif. Penghitung auto-id `role_permissions` maju (tak berpengaruh).

## Regresi BE (record; bukan hitungan item ini)

Run penuh sesudah ED-1028, DB kosong (`work/qa-ed1028-rev/reg-ED-10xx.txt`):

| Item | PASS | FAIL | SKIP | Catatan |
|---|---|---|---|---|
| ED-1024 | 26 | 0 | 0 | bersih |
| ED-1025 | 40 | 0 | 0 | X-12 (75 x 500) dan X-13 (500) = karakterisasi bug pra-ada ED-1071 / ED-1070 |
| ED-1026 | 39 | 0 | 0 | AC-3 / AC-12 / AC-15 sudah diperbarui ED-1026 untuk perubahan sah ini |
| ED-1027 | 28 | 0 | 1 | X-3 SKIP (1 DB); AC-24 / X-12 / X-13 (peta saringan) PASS bersama entri seluruh modul baru; X-10 mencatat 500 pra-ada ED-1070 |
| ED-1029 | 29 | 0 | 1 | X-ISO SKIP (1 DB); X-1 mencatat 16 x 500 SQLSTATE pra-ada (ED-1071) |

"Digantikan perubahan sah": **tidak ada yang tersisa** (skenario usang ronde lama ED-1025 AC-1, ED-1026 AC-12/AC-15, ED-1027 AC-1/X-1 sudah diperbarui pemiliknya). Skenario ED-1027 yang dulu hanya memeriksa cabang "seluruh modul" di tingkat servis kini punya bukti HTTP di AC-28 ED-1028; skenario ED-1028 yang dicatat usang oleh ED-1026/ED-1027 (`q28_session`, `scenario1-list.php`, `scenario5-access.php`, `qa_hist.php`) sudah diperbaiki (bagian di atas), sehingga "ED-1028 20/22 FAIL" di laporan ED-1026/ED-1027 tidak berlaku lagi (sekarang 24/24).

## Bug di luar item

- **B-1 (rendah, keputusan developer): route V4 `setting/display-columns*` tidak ikut dijaga.** `Modules/V4/Routes/Setup/setting.php:58-62` + `Modules/V4/Services/Setup/Setting/ColumnDisplaySettingService.php:12-35` tanpa cek permission. Bukti (R0, read-only): `GET api/v4/setting/display-columns/documentArchiveOpnameHistory` -> **200** berisi `current_columns`; `GET api/v4/setting/display-columns?search=documentArchive` -> total **2** (memuat `documentArchiveOpnameHistory`) vs `api/v5/...` -> total **1** dan 403 untuk modul itu. PUT v4: dari kode, tidak dijalankan. FE memakai `api/v5` (`.env` `REACT_APP_API_BASEPATH`), jadi UI aman; yang terbuka hanya konfigurasi kolom (tanpa data sesi) lewat klien non-FE. Celah yang sama untuk saringan `documentArchive` ED-1027. Usul: tutup di V4 atau terima (legacy).
- **B-2 (info, tidak diverifikasi): `permission_v5` vs `MyHelper::hasPermission` berbeda.** Middleware memakai `permissions.is_active = 1` dan meloloskan `user->customer` (`PermissionVersion5.php:28-30`; `V4/Permission::permissionByRole`), `hasPermission` (`MyHelper.php:521`) tidak memeriksa keduanya. Saat ini tidak ada permission `is_active != 1` di DB uji, jadi tidak ada selisih nyata; pengguna aplikasi customer (tidak ada di config QA) bisa lolos `GET opnames*` tetapi mendapat `options: []` di select dan tidak melihat entri display setting. Sama dengan yang dicatat reviewer BE; konsekuensi platform (customer bypass berlaku untuk semua rute v5).
- **B-3 (info): 500 pra-ada yang terlihat di regresi**, bukan kode item ini: ED-1025 X-12 / X-13 (kolasi latin1 = ED-1071; `pagination`/`sorts`/`search` salah bentuk = ED-1070), ED-1027 X-10 (`sorts` array = ED-1070), ED-1029 X-1 (16 x SQLSTATE = ED-1071). ED-1122 (`PUT display-columns` isi sama di detik yang sama = 500) tidak dipicu (PUT pemegang selalu mengubah isi, sekali).

## Yang tidak bisa diverifikasi (dan kenapa)

- **Isolasi tenant antar-DB**: `QA_DBS` = 1 DB (lihat di atas); `not_verifiable: 1` pada front matter = butir ini (bukan AC). RoadRunner tidak dipakai (`BE_SERVER=fpm`).
- Tukar lisensi Salesman Activity sungguhan (AC-14 bagian lisensi): `PROFILES` kosong; MANUAL Gate 2 epic (EPIC K-2 b). Bagian http (R0, role 22, seed tanpa perubahan, 401) PASS.
- Pengguna aplikasi customer (B-2) dan `PUT api/v4/...` (B-1): tidak ada user customer di config QA; PUT v4 tidak dijalankan.
- Seluruh `[FE]` / `[BE+FE]` (AC-15..25, 27, 29, sisi FE AC-24): tahap FE.

## Riwayat ronde

- Ronde 1 revisi (2026-10-10): **PASS**. 16 AC BE (15 + AC-24 BE) PASS; 24 skenario / 3.309 asersi; data developer simulasi 24/24 dan DB identik; regresi ED-1024 26/26, ED-1025 40/40, ED-1026 39/39, ED-1027 28+1 SKIP, ED-1029 29+1 SKIP; 0 defect; 3 catatan di luar item.
- Ronde 1 asli (2026-10-07): PASS 14/14 AC, 22 skenario / 2.642 asersi, 1 defect rendah (D-1 kontrak, diperbaiki); laporan lengkap: `git show 934f11f:features/ED-1028-archive-opname-history/qa-be.md`.
