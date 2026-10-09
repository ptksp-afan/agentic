---
key: ED-1029
scope: be
round: 1
verdict: PASS
counts: {pass: 17, fail: 0, manual: 0, not_verifiable: 2}
be_server: fpm
---
## Ringkasan

Ronde 1 dari **run revisi** (rev 2026-10-08, subtask ED-1103 [BE] dan ED-1105 [QA]; commit lama BE 44bea5136 tetap, perubahan = 3 berkas
BE di atas HEAD 8ea8ebcf2: `Routes/DocumentArchive/api.php`, `UserPermissionService.php`, `UserPermission/UpdateRequest.php`). Semua AC [BE]
yang ber-"(rev)" (4, 9, 11, 20-23, 25, 27, 28 sisi BE) dan AC [BE] lama (5-8, 10, 12, 13) **PASS**; **tanpa defect**.

- Server BE: php-fpm, kode terbaru terbaca tanpa reload. Tanpa 500 baru dari endpoint ini: 500 yang terlihat hanya dari karakterisasi sengaja yang sudah
  dilacak, bukan defect ronde ini: ED-1029 X-1 (ED-1071, id non-latin1, 16 dari 28 kasus), ED-1025 X-12 (ED-1071, 75 dari 80 kasus) dan X-13 (ED-1070, 13 bentuk
  `pagination`/`sorts`/`search` salah). Log Laravel seluruh ronde (05:48-06:33 lokal, 203 baris ERROR) dipilah per jenis: kolisi 1267/1270 = X-1 + X-12 (3 x 16 + 75);
  Illegal offset / Unsupported operand / json_decode / Array to string / `showRelatedTransaction` / SQL 1064 = X-13 (06:04-06:05, bersamaan dengan run ED-1025);
  `websockets_statistics_entries` tidak ada (infra, bukan Archive); penolakan OAuth token yang memang dicabut runner; satu galat `route:list` dari perintah saya.
  Tidak ada galat dari `UserPermission*` di luar X-1.
- Skenario ED-1029: **30 skenario, PASS 29, SKIP 1 (X-ISO), FAIL 0, 2066 asersi** (`work/qa29rev-r1-full.txt`, JSON
  `work/qa-http/ED-1029-20261009055953.json`). Sebelum diperbarui, run yang sama gagal 6 (AC-4, 11, 13, 13b, X-PERM, AC-20: tepat di titik yang
  diubah revisi; `work/qa29rev-r1-pre.txt`), jadi asersi baru memang membedakan perilaku lama dan baru.
- Skenario milik sendiri: **7 baru** (AC-21, AC-21b, AC-22, AC-23, AC-25, AC-26-BE, AC-27 di `qa/scenario6-rev.php`), **7 diperbarui** (AC-4, AC-9,
  AC-11, AC-13, AC-13b, X-PERM, AC-20), 16 tidak berubah. Dua run penuh berturut-turut sesudah pembaruan sama-sama hijau, 29 PASS + 1 SKIP (`work/qa29rev-r1-full.txt`, `work/qa29rev-r1-full2.txt`; re-runnable).
- Regresi ED-1024..ED-1028 (skenario di working tree): ED-1024 26/26, ED-1025 40/40, ED-1026 31 PASS + 6 gagal (4 usang, 2 terkait data
  developer), ED-1027 19 PASS + 1 gagal (usang/tak idempoten) + 1 SKIP, ED-1028 22/22. **Tidak ada regresi nyata**; rincian dan file:line di
  bagian "Skenario usang milik item lain".
- Isolasi tenant tetap `not_verifiable` (`QA_DBS` hanya `api_sidomaju`).
- Data uji: satu-satunya penghapusan = 3 baris yatim `archive_documents` `QA01-R2-*` (milik QA ED-1024); DB lain identik sebelum/sesudah
  (hitungan tabel). Penolakan izin/classifier: tidak ada.
- Kode aplikasi, spec, kontrak tidak diubah. Yang diubah hanya `qa/qa_lib.php`, `scenario1-read.php`, `scenario2-write.php`,
  `scenario3-access.php` dan berkas baru `scenario6-rev.php` (semua di folder ED-1029). E2E `qa/e2e/*` **tidak** disentuh (tahap fe).

## Hasil per AC

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-4 (rev) | `scenario1-read` AC-4: GET `{B}` oleh A (QA_USER role 3, lokasi SMR), fixture P/C1/C2/Q/R/R1/QC/N/L/LC/M; himpunan+urutan DFS vs SQL; `access` menurut ED-1025 BR-4; `manage_permission` di setiap node | PASS | 492 asersi. C2 (Off di bawah P V+U) `access` 1/1/1/1 (K-12 a); Q (A View saja) `manage_permission` true; N tanpa View tetap ada, view false; `manage_permission` true di SEMUA node; L tidak ada, LC di level teratas. Run awal (rev code, skenario lama) gagal tepat di C2 `[1,1,0,0]` |
| AC-5 | `scenario1-read` AC-5: role 1 dan 2 | PASS | 83 asersi: L ikut, jumlah+urutan = semua folder aktif SQL, `access` true di semua node |
| AC-6 | AC-6 (GET, 72), AC-6b (PUT, 47), X-1 kontrol latin1 | PASS | 404 `ARCHIVE440` id/en; 12 kontrol latin1 = 404; non-latin1 = 500 dilacak ED-1071 (karakterisasi X-1, bukan defect ronde ini) |
| AC-7 | `scenario2-write` AC-7 | PASS | 52 asersi: PUT `[P V+S, C1 V]` 200 `ARCHIVE207`; baris lain utuh; riwayat +1 oleh A; GET `archives/{P}` memuat baris B sama (dua arah) |
| AC-8 | `scenario2-write` AC-8 | PASS | 32 asersi: hapus baris, nilai sama = tanpa riwayat, `[]` = 200 |
| AC-9 (rev) | `scenario2-write` AC-9 (en+id) | PASS | 57 asersi: C2 dan R (Off) 400 `ARCHIVE441` parameter = nama; kombinasi dengan P/C1 sah (4 urutan) = tidak ada yang tersimpan (data+riwayat+jumlah baris). C2 dinyalakan: A tanpa baris sendiri di C2 tetap 200 (K-15 b); dimatikan lagi: hapus baris pun 441 |
| AC-10 | `scenario2-write` AC-10 | PASS | 166 asersi: `ARCHIVE411`, rollback penuh |
| AC-11 (rev) | `scenario2-write` AC-11 ditulis ulang | PASS | 173 asersi: Q (View saja), N (tanpa View), QCh (induk Q membatasi) PUT = **200**, baris + riwayat +1 (dulu 408); body tidak sah + Q = 422 (bukan 408); P sah + Q = 200 dua-duanya; L = 403 `ARCHIVE407` tanpa `parameter`, mendahului 422/404, juga `Q + L`; dokumen/tidak ada/nonaktif 404 `ARCHIVE400`; 14 kasus 422 + 5 bentuk `permissions` salah; data tidak berubah pada semua penolakan; **0 x `ARCHIVE408`** |
| AC-12 | `scenario2-write` AC-12 | PASS | 14 asersi: R1 (On di bawah R Off) 200; R tetap 441 |
| AC-13 | `scenario3-access` AC-13 (diperbarui) dan AC-13b (diperbarui, dua pengguna nyata) | PASS | 34 + 44 asersi. Membuka folder tetap butuh View (403 `ARCHIVE407` -> View -> 200 -> cabut -> 403). Rev K-15 b: B ber-Folder Permissions dengan View saja, lalu tanpa View, **PUT `{user lain}` 200 (bukan 408)**; `PUT archives/{P}` kelompok detail tetap 403 `ARCHIVE408` (aturan ED-1025). 13b: QA_USER2 (role 5, Update Folder tanpa Folder Permissions) GET/PUT = 403 `GE0114` "Folder Permissions" walau punya V/U/D/S di P; diberi role fixture ber-FP sementara = 200 dan riwayat atas nama QA_USER2; dilepas = 403 lagi. Riwayat P: 6 entri tepat (hanya perubahan nyata) |
| AC-20 (rev, sisi BE) | `scenario3-access` AC-20 (ditulis ulang) | PASS | 37 asersi. Role tanpa Folder Permissions (6, 29, juga 5 yang ber-Update Folder): GET/PUT 403 `GE0114` parameter "Folder Permissions", tanpa `result`, data tetap. DB: tepat satu permission bernama `Folder Permissions`, tidak ada "Set Permission"; seed: nama itu hanya di `app/Sql/data/permission_salesman.sql`; grep: `UserPermissionService.php`, `UpdateRequest.php`, baris route `user-permissions`, dan semua `qa/*.php` ED-1029 **tanpa id permission literal** (1082-1085, 1133-1149). Sisi FE/lisensi nyata = tahap fe / Gate 2 epic (grep informatif: `ksp-react/src/containers/Archive` juga bersih) |
| AC-21 (rev) | `scenario6-rev` AC-21 (baru) + `scenario3` X-PERM | PASS | AC-21 203 asersi, X-PERM 189. D (Update Folder tanpa FP) dan E, en+id: GET dan 9 bentuk PUT (sah, flag 9, tanpa `permissions`, `[]`, user tidak ada sah/tidak sah, folder L, folder Off, id tidak ada) = 403 `GE0114` parameter "Folder Permissions", tanpa `result`, snapshot data+riwayat+jumlah baris utuh. A, M (tanpa Update Folder), superadmin 1 dan 2: GET 200, PUT `[P: View]` 200 + baris + riwayat oleh pemanggil. X-PERM: 401 `GE0111` tanpa token; role ber-FP (dicari lewat nama di `role_permissions`: 1, 2, 3) 200; 9 role tanpa FP (5, 26, 6, 16, 18, 19, 20, 25, 29) 403; QA_USER2 403 |
| AC-22 (rev) | `scenario6-rev` AC-22 (baru), data J | PASS | 50 asersi. GET: J, JA, JB, JC tampil, `manage_permission` true x4; `access` J 1/0/0/0, JA 1/1/1/1, JB semua false, JC (Off) 1/1/1/1. PUT `[JA V+S]`, `[J V]`, `[JB V]` = 200, baris benar, riwayat +1 masing-masing oleh A. `[JC]` = 400 `ARCHIVE441` parameter JC (bukan 408), `[JA+JC]` dan `[JC,JA]` = 400 dan JA tidak tersimpan (snapshot utuh) |
| AC-23 (rev) | `scenario6-rev` AC-23 (baru) | PASS | 37 asersi. Baris A di J dihapus: GET `{B}` 200, J/JA/JB/JC tampil, `access.view` false, `manage_permission` true; PUT `[JA: V+U]` 200 + riwayat JA oleh A (action `permission`); `[JC]` 441; GET `archives?id_archive=` J/JA/JB/JC oleh A tetap 403 `ARCHIVE407` (sebelum dan sesudah PUT) |
| AC-25 (rev, sisi BE) | `scenario6-rev` AC-25 (baru) | PASS | 21 asersi. F Off tanpa baris A; `PUT archives/{F}` (nyalakan Izin + baris A 1/1/1/1, bentuk tab Izin) 200; GET `user-permissions/{A}`: F On, 1/1/1/1; PUT `{A}` `[F: V,U,S]` 200; `GET archives/{F}` memuat A V/U/S (tanpa Delete); riwayat +1 tiap langkah; F dimatikan lagi -> 441. Sisi FE (e2e) = tahap fe |
| AC-26 (pendukung BE) | `scenario6-rev` AC-26-BE | PASS (bukan klaim AC [FE]) | 16 asersi: A mencabut semua hak sendiri di JA via PUT `{A}` = 200 langsung; GET sesudahnya JA tampil, view false, `manage_permission` true; bisa memberi View lagi |
| AC-27 (rev) | `scenario6-rev` AC-27 (baru) | PASS | 24 asersi. Badan = `status, msg_code, message, result{user,data}`; `user` = `id_user, username`; node di semua kedalaman tepat 9 kunci kontrak (+`children`); tidak ada `created_by`/`created_at`/`updated_by`/`updated_at`/`history` di node maupun `access`; sama untuk A, M, superadmin 1 dan 2; string `created_by` tidak ada di badan mentah |
| AC-28 (rev, sisi BE) | Skenario usang milik sendiri diperbarui, semua skenario ED-1029 + D-4 dijalankan ulang, X-1 tetap karakterisasi | PASS | Lihat "Skenario milik sendiri yang diperbarui". 30 skenario: 29 PASS, 1 SKIP. `scenario5-concurrency` X-5/6/7: 210 + 172 + 330 request, **0 x 5xx**, terlama 1,4 s. X-1: 28 kasus, 12 x 404 dan 16 x 500 (ED-1071) = karakterisasi hijau. Bagian e2e AC-28 (`routes.json:4-5`, spec `:10,872,913`, `fixture.php:18`) = tahap fe |
| AC-1..3, 14-19, 24, 26 | - | bukan [BE] | tahap fe |

## Skenario milik sendiri yang diperbarui (AC-28, gate1 §5)

- `qa_lib.php`: `q9_access_is` (`manage_permission` default true, parameter opsional); pencatat kode respons `q9_codes_reset` / `q9_assert_no408`
  (ARCHIVE408 tidak boleh muncul dari `user-permissions`); fixture role BERNAMA `q9_role` / `q9_roles_profile` (A, M, D, E sesuai spec §6, dibuang
  `q9_role_purge`), `q9_roles_by_permission` (cari role lewat nama permission), `q9_with_extra_role` (role sementara bagi QA_USER2), `q9_fixture_j`
  (data J/JA/JB/JC), `q9_node_vals`. Tidak ada id permission literal.
- `scenario1-read.php` AC-4 (baris 124 dan 132 lama): C2 `1/1/1/1`, Q dan semua node `manage_permission` true.
- `scenario2-write.php` AC-9 (baris A di C2 dibuang) dan AC-11 (403 `ARCHIVE408` -> 200/422/407, lihat tabel; dulu `:403-475`).
- `scenario3-access.php`: AC-13 dan AC-13b (dulu `:115-118,145,208-231`: 408 -> 200 / `GE0114`), X-PERM dan AC-20 (dulu `:241-346`: permission route
  = `Folder Permissions`, daftar role dicari lewat nama, tanpa 1085/1133). AC-5b, X-ISO tetap.
- `scenario6-rev.php` baru: AC-21, AC-21b (urutan penolakan BR-13: `GE0114` -> `ARCHIVE440` -> `ARCHIVE407` -> 422 -> `ARCHIVE400` -> `ARCHIVE441` ->
  `ARCHIVE411`, 44 asersi, termasuk `Q (A View saja) + D tanpa View` = 411 bukan 408), AC-22, AC-23, AC-25, AC-26-BE, AC-27.
- Kebersihan: setiap skenario membuang folder `QA29-*` dan role `QA29-ROLE-*` di `finally`, memulihkan role/lokasi/bahasa QA_USER dari jurnal dan
  memverifikasinya; user_roles QA_USER2 kembali ke jumlah awal. Sisa: nol (cek akhir `QA* leftovers: archives=0 docs=0 roles=0`).

## Defect

Tidak ada.

Catatan review diff BE (bukan defect; pengamatan):
- Kontrak vs respons aktual cocok: GET (bentuk node, `manage_permission` true di semua node, tanpa `created_by`), 403 `GE0114` `{message, code, parameter:"Folder
  Permissions"}` (en+id, kedua kode ada di `en_EN.php`/`id_ID.php`), 400 `ARCHIVE441` `parameter:[nama]`, urutan penolakan = kontrak §2, `ARCHIVE408` tidak
  pernah dikirim (diperiksa lewat pencatat di 9 skenario). Tanpa tabel/Updater/seed baru (diff = 3 berkas; `Folder Permissions` hanya di `permission_salesman.sql`).
- Atomik: ARCHIVE441/411/407 pada kombinasi baris sah+tidak sah tidak meninggalkan baris, riwayat, `updated_at` (snapshot byte-sama). Kunci `lockFolders`
  tetap query pertama; 0 x 5xx pada ~710 request paralel.
- Biaya: `context()` dihitung beberapa kali per PUT (authorize, update, assertCanManage) tetapi terukur murah (GET 193 folder 0,28 s, PUT 160 baris 0,47 s).
- Cadangan `GE0114` di `authorize()`/`assertCanManage` hanya berpengaruh untuk user customer (middleware mem-bypass); `show()` tidak punya cek cadangan,
  jadi GET oleh customer lolos seperti route Archive lain. Tidak bisa diuji di sini (lihat bawah).
- Cutover bergantung seed ED-1025: tenant yang role 1/2/3-nya belum mendapat `Folder Permissions` menolak semua orang termasuk superadmin (403 `GE0114`).
  Di `api_sidomaju` sudah ter-seed (role 1, 2, 3 ber-FP).

## Isolasi tenant & permission

- Permission (AC-21, AC-21b, X-PERM, AC-13b, AC-20): tanpa token 401 `GE0111`; tanpa `Folder Permissions` (termasuk role ber-Update Folder 5 dan 26, QA_USER2)
  GET dan PUT 403 `GE0114` parameter "Folder Permissions", mendahului 404/422/407, data tidak berubah; pemegang FP (role 1, 2, 3, profil A, profil M tanpa Update
  Folder) 200. Superadmin 1/2 boleh PUT di folder On (AC-5b, AC-21), folder Off tetap 441.
- Isolasi tenant (X-ISO): **TIDAK BISA DIVERIFIKASI**, `QA_DBS` hanya `api_sidomaju` (SKIP otomatis). Tinjauan kode: diff revisi tidak menambah `static`/singleton/cache;
  `context()` dihitung per request dan diteruskan sebagai argumen.

## Galeri e2e & regresi BE

E2E: bukan scope be (e2e ED-1029 yang usang menunggu tahap fe). Regresi BE (`work/qa29rev-reg-ED-10xx.txt`):

| Item | Hasil | Catatan |
|---|---|---|
| ED-1024 | PASS 26, FAIL 0 | run pertama gagal 19 (asersi kebersihan `archive_documents` mendapat 3 baris `QA01-R2-*`: data yatim QA sendiri, lihat "Data"); sesudah dibuang 26/26 |
| ED-1025 | PASS 40, FAIL 0 | termasuk skenario rev (`ArchiveService::update`, seed, `lockFolders`) |
| ED-1026 | PASS 31, FAIL 6 | 4 usang + 2 data developer, bukan regresi (di bawah) |
| ED-1027 | PASS 19, FAIL 1, SKIP 1 | X-1 usang/tak idempoten; X-3 SKIP isolasi |
| ED-1028 | PASS 22, FAIL 0 | bersih (tabel opname kosong, baseline/cleanup lolos) |

### Skenario usang milik item lain (DICATAT, TIDAK diubah; gate1 §5)

| Item | Skenario (file:baris) | Sebab | Pemilik |
|---|---|---|---|
| ED-1026 | `scenario1-schema-seed.php:154` (AC-1) | "kunci config.php lebih besar dari semua Updater lain": Updater item berikut (ED-1027) kini lebih besar | QA 1026 |
| ED-1026 | `scenario1-schema-seed.php:257` (AC-2) | tuple seed `(1133,...,'Opname Document',...)`: sekarang 1139 (EPIC K-4 a, ED-1025 rev) | QA 1026 |
| ED-1026 | `scenario4-session-access.php:82` (AC-12) | "terkonfirmasi user lain, non-superadmin: show" diharapkan 404, kini 200 (perubahan akses ED-1028) | QA 1026 |
| ED-1026 | `scenario4-session-access.php:157` (AC-15) | daftar kunci `result` kini juga memuat `result_counts` dan `is_partial` (ED-1028) | QA 1026 |
| ED-1026 | `scenario2-folders-store.php:30` (AC-5), `scenario5-confirm.php:313` (AC-20) | **terkait data developer**: oracle mengira folder root bebas Izin Folder; developer menyalakan Izin di "CABANG - SEMARANG" dan "Folder A" lewat UI (`archives.updated_at` 2026-10-08 14:36, `kevinsudjadi.adm`) dan QA_USER tidak punya baris View, jadi BE benar menyembunyikannya (children hanya "Backup Arsip" dari 3 yang diharapkan). Bukan regresi dan bukan dari ED-1029; data developer tidak diubah | QA 1026 |
| ED-1027 | `scenario1-columns-updater.php:169` (X-1) | `updater_logs` tidak kembali persis: baris log Updater itu (`key_id` 1791357196, id 197) sudah ada sebelum run, sedangkan skenario hanya menghapus bila belum ada (`$hadLog`, `:148-149`/`:161-166`); `executeUpdater` menulis ulang baris yang ada, tanpa snapshot-pulih. Akibat nyata: kolom `executed_at` baris itu kini `2026-10-09 06:15:28` (nilai lama tidak tercatat; status tetap `success`) | QA 1027 |

Dicek khusus catatan Gate 1 (`ba2-notes` "Pengaman data uji"): `q26/q27/q28_baseline`/`_cleanup` yang menegaskan tabel opname kosong **lolos** (tiga tabel
`archive_opname*` = 0 baris sebelum dan sesudah); `q26_sweep` hanya menyentuh baris berjurnal QA.

## Data

Hitungan tabel sebelum vs sesudah seluruh ronde (archives, archive_permissions, archive_locations, archive_documents, archive_opname_*, stock_opname_*, user_roles,
employees, employee_locations, users, roles, role_permissions, permissions, locations; `work/qa29rev-baseline-counts.txt`): **identik, kecuali `archive_documents`
49129 -> 49126 (tepat -3 di bawah)**. Role QA_USER kembali `[3]` (all location). `roles.id_role` AUTO_INCREMENT maju (role fixture dibuat lalu dihapus; max id tetap 30, 27 role).

Dihapus (urutan: data QA sendiri dulu; Gate 1 § Data uji + K-3 a): 3 baris yatim `archive_documents` milik QA ED-1024 (induk `archives` sudah tidak ada, dibuat
2026-10-09 02:35:38 oleh ronde ED-1024 R2), `transaction_no` / `id_archive_document`:
- `QA01-R2-DD` / 250224000117914881382026153402
- `QA01-R2-DA` / 250224000117914881386518197010
- `QA01-R2-DS` / 250224000117914881388974061964
Isi penuh baris: `work/qa29rev-deleted-rows.json`. Tidak ada data developer yang dihapus atau direset.

Diubah di luar kendali skenario ED-1029: baris `updater_logs` di atas (ED-1027 X-1, `executed_at`); token lain QA_USER di DB yang sama dicabut oleh `force_login=1` runner
(peringatan standar runner).

## Yang tidak bisa diverifikasi (dan kenapa)

1. **Isolasi tenant** (`QA_DBS` hanya `api_sidomaju`): dua DB tidak tersedia; ditinjau lewat kode saja.
2. **Jalur user customer** (middleware `permission_v5` mem-bypass customer; cadangan `GE0114` di `authorize()`/`assertCanManage`, dan GET tanpa cadangan): tabel `customers`
   tidak punya user terhubung (`id_user` terisi = 0) dan tidak ada kredensial; tidak membuat user. Hanya tinjauan kode.
3. Bagian lisensi AC-20 (tukar lisensi sungguhan; `PROFILES` kosong) = Gate 2 epic. `php artisan route:list` gagal karena galat lain di repo
   (`ProfitLossReportService.php:29`, bukan denial dan bukan Archive); permission kedua route dibuktikan lewat HTTP (X-PERM, AC-21).

## Riwayat ronde

- Ronde 1-2 (2026-10-07, run awal): R1 FAIL (AC-6 D-1 id non-latin1 -> 500; D-2 PUT paralel -> 500 `1062`); R2 PASS: D-2 diperbaiki (`lockFolders`, ~4.030 request, 0 x 5xx),
  D-1 dipindah ke ED-1071 (X-1 karakterisasi); regresi ED-1024..1028 tanpa regresi nyata.
- Review batch 2026-10-08 minta perbaikan; revisi spec/kontrak (FB-4b, ED-1025 K-15 b/K-12 a, K-8 c).
- Ronde 1 revisi (2026-10-09 05:4x-06:29 lokal): lihat di atas. PASS, 0 defect, 17 AC [BE] PASS, 2 not_verifiable.
