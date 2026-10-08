---
key: ED-1025
scope: be
round: 1
verdict: PASS
counts: {pass: 25, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

QA BE **ronde 1 run revisi** (subtask ED-1087) untuk perbaikan review batch 2026-10-08 (BE `44bea5136` + 5 berkas M: `ArchivePermissionService`, `ArchiveService`, `UpdateRequest`, `api.php`, `permission_salesman.sql`).
Seluruh 24 AC `[BE]` **PASS**; AC-23 (bagian BE) PASS dan tukar lisensi sungguhan **MANUAL (Gate 2 epic)**; AC-36 (bagian BE: regresi) PASS dengan catatan di bawah. AC `[FE]` (3, 17-21, 30-35) milik scope fe. **Tidak ada defect kode**, tidak ada 5xx baru.

- Suite ED-1025: **40 skenario, 40 PASS, 0 FAIL, 3531 asersi** (`work/qa-runs/ed1025-rev-r1-final.txt`, laporan `work/qa-http/ED-1025-20261009002244.json`; sesudah itu berkas dipecah ukuran dan skenario yang dipindah serta AC-38 dijalankan ulang: PASS). 9 skenario baru: AC-25, AC-26, AC-27, AC-28, AC-29, AC-37, AC-38, X-15, X-16.
- Uji mutasi X-15: aturan induk-dominan-untuk-semua-hak (K-1 a lama) dimasukkan sementara ke model -> X-15 FAIL pada folder kedalaman 3 (3,5 dtk; `access` store); pohon acak memuat 16 folder yang hanya benar menurut aturan baru (diasersi > 0); mutasi dikembalikan.
- **Regresi** (AC-36): ED-1024 **26/26** sesudah skenario usangnya diperbarui QA ini (4 berkas). ED-1026/1027/1028 **tidak bisa dijalankan apa adanya** di DB uji bersama (prasyarat "tabel opname kosong" gagal oleh data opname pengembang; lihat T-1). Dijalankan lewat salinan di scratchpad (prasyarat dilonggarkan, berkas asli tidak diubah): semua kegagalan yang tersisa dijelaskan oleh skenario usang atau data pengembang, **tidak ada yang menunjuk regresi kode**. ED-1029: 4 FAIL, semuanya usang akibat (rev) -> dicatat untuk pemilik.
- **Seed ulang QA DB**: DB `api_sidomaju` sudah sama dengan `permission_salesman.sql` untuk id 1133-1140 (diterapkan developer 15:57; skrip banding QA: 0 langkah). QA tidak menulis apa pun ke permission/role bawaan. Rincian di "Seed ulang".
- Lingkungan: `BE_SERVER=fpm` (kode terbaru langsung aktif, terbukti oleh perilaku (rev)). `QA_DBS` hanya `api_sidomaju`: isolasi tenant tidak berlaku. Pelaku selalu `QA_USER` (force_login); peran A/C/D/E/F/N lewat role fixture `QA02-ROLE-*` (dibuat per skenario, dibuang di finally).

## Hasil per AC

Peran uji: **A** = role 3 (non-bypass, ber-`Folder Permissions`); **C** = A tanpa `Folder Permissions`; **D** = `List Archive` + `View Folder` + `Folder Permissions`; **E** = `List Archive` + `Update Folder`; **F** = `Folder Permissions` saja; N = `List Archive` saja.

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 (rev) | `SHOW COLUMNS/INDEX`, Updater 2x lewat probe, `config.php` hanya **terdaftar** (tanpa syarat kunci terbesar), default 0 lewat INSERT tanpa kolom, tanpa nilai selain 0/1 (tidak bergantung folder On milik pengembang) | PASS | `AC-1` (23 asersi), `scenario1-schema-read.php` (baris 95 lama diganti) |
| AC-2 (rev) | A tanpa folder On milik sendiri: list/buka/subfolder/Store/pindah/rename/ubah/hapus = lama; baris root milik orang lain dibandingkan dengan DB | PASS | `AC-2` (250) |
| AC-4 (rev) | A: `manage_permission` true walau tanpa Update di P; C: `folder_permissions` [] + false + bentuk sama; D: baris tampil; folder tanpa View tetap 403 `ARCHIVE407` walau ber-`Folder Permissions` | PASS | `AC-4` (60) |
| AC-5, 6, 22 | PUT baris/atomik/`ARCHIVE411`/riwayat `permission` | PASS | `AC-5` 61, `AC-6` 59, `AC-22` 29 |
| AC-7, 8 | 403 `ARCHIVE407` + `denied_by` (View lewat induk) | PASS | `AC-7` 29, `AC-8` 20 |
| AC-9 (rev) | C On View saja: rename/Store 408/410 param C; hak penuh di C, View saja di P: Store ke, rename, PUT, DELETE kosong = 200 (dulu 403); P sendiri tetap ditolak | PASS | `AC-9` (49) |
| AC-10 | superadmin 1 dan 2 (juga 3+2) lolos semua | PASS | `AC-10` (73) |
| AC-11 (rev) | pembuat P tanpa baris: buka+ubah 200; ditolak di C On tanpa hak; pembuat C (On) dengan View saja di P: ubah/rename/Store 200; tanpa View di P: C tertutup | PASS | `AC-11` (43) |
| AC-12 (rev) | B View tanpa Update: 408/410; **PUT body kosong = 422** (bukan 403); `{description}` saja = 403 `ARCHIVE408` | PASS | `AC-12` (40) |
| AC-13, 14, 24 (rev) | anak Off dihapus/dipindah tanpa Delete/Update di induk = 200; 409/418/401 urutan; FU keluar ke root dengan induk View/Store saja = 200 | PASS | `AC-13` 26, `AC-14` 57, `AC-24` 37 |
| AC-15, 16 | search tetap menemukan dokumen; select user | PASS | `AC-15` 29, `AC-16` 114 |
| AC-23 (rev, bagian BE) | role tanpa `List Archive` -> GET 403 `GE0114`; tanpa `Update Folder` dan `Folder Permissions` -> PUT (detail, izin saja, body kosong) 403 `GE0114` parameter "Update Folder, Folder Permissions", data tetap; role 29 `manage_permission` false; 401 tanpa token | PASS (BE); **MANUAL** tukar lisensi | `AC-23` (64); `PROFILES` kosong |
| AC-25 (rev) | seed: tuple 1139/1140 (module 1266, label en/id, sort 10/11), tanpa 1133-1138 di berkas, checks/unchecks persis spec 3 (tanpa pasangan 1085), hanya di `permission_salesman.sql`, `role_permissions.sql` dinamis; DB = seed, role 1/2/3 memegang keduanya, tanpa sisa 1133; seed utuh di DB scratch; kode BE (Modules/V5, app) dan FE `src` tanpa id permission | PASS | `AC-25` (39), scratch DB dibuang |
| AC-26, 27 (rev) | data FB-9: J/JA/JB/JC/X; JA 200 semua aksi; J 408/409/410 parameter J; baris A di J dihapus -> JA 403 `denied_by`=J; JB `denied_by`=JB; search JA access false | PASS | `AC-26` 49, `AC-27` 44 |
| AC-28 (rev) | JC (Off): PUT/rename/create/put-in/DELETE 200, dokumen -> 400 `ARCHIVE401`, tanpa View di J -> 403 `denied_by`=J; K (On, View+Delete) dengan anak Off kosong -> 200; kontrol 418/401 | PASS | `AC-28` (51) |
| AC-29 (rev) | C: show `manage_permission` false + `folder_permissions` []; 8 bentuk field izin x 2 -> 403 `GE0114` "Folder Permissions" (ID/EN), data tetap; tanpa field itu 200, riwayat hanya `update`; A true + baris | PASS | `AC-29` (104) |
| AC-37 (rev) | JA put-in ke X/root, PUT induk=X, lewat name = 200 (induk asal tidak dicek); tujuan tanpa Store 410; JB 408 param JB; JC (Off) 200; folder View saja 408 | PASS | `AC-37` (40) |
| AC-38 (rev) | D izin saja 200 (riwayat hanya `permission`, tanpa-perubahan = tanpa tulisan), + detail 403 `GE0114` "Update Folder"; A izin ke JB (tanpa View) dan J 200, GET JB tetap 403, detail J 408; di luar scope 407; kosong 422; 404; urutan 403 -> 422 -> 411 dan 410 -> `GE0114` izin; E atomik; F/superadmin 1 dan 2 | PASS | `AC-38` (165) |
| AC-36 (rev, bagian BE) | ED-1024 diperbarui + dijalankan ulang; skenario sendiri diperbarui; ED-1026..1029 dijalankan dan yang usang **dicatat** (bagian berikut); karakterisasi X-8/X-12/X-13 utuh; tanpa 5xx baru | PASS (dengan T-1) | `ed1025-rev-r1-regr-*.txt` |
| AC-3, 17-21, 30-35 | `[FE]` | di luar scope be | scope fe |

Pemeriksaan tambahan (bukan baris AC), semua PASS: **X-15** matriks hak efektif BR-4 (2 pohon acak seed tetap, 60 folder, 1135 asersi: `access` pencarian + buka, 403 `denied_by`, rename 408, put-in 410, DELETE post-order 409/418/200 = model referensi independen; cakupan semua cabang: view 26/34, update 20/40, store 21/39, delete 13/41/6); **X-16** PUT izin saja dengan 22 tipe/nilai aneh x 2 field x peran A/D/C/N (352 kasus: 200x44, 422x132, 403x176, **5xx x0**, penolakan tanpa perubahan data); X-1..X-6, X-9..X-11, X-14 (lama) hijau; X-9/X-10/X-14 tetap **5xx x0**.

## Defect

Tidak ada. Semua kegagalan awal adalah skenario usang akibat (rev) atau data pengembang, bukan perilaku kode:

- Run awal suite lama: 13 FAIL = `manage_permission` (= Folder Permissions), dominasi induk Update/Delete/Store (K-1 a) yang digantikan K-12 a, PUT body kosong (422), parameter route PUT, prasyarat data pengembang (AC-1, AC-2: 4 folder Izin On). Semuanya diperbarui (lihat bawah); sesudahnya 40/40.

### Temuan non-defect

- **T-1 (lingkungan, perlu tahu developer)**: DB uji bersama memuat data pengembang yang menggagalkan prasyarat skenario item lain: 13 sesi opname + 45 folder + 12 dokumen opname (`kevinsudjadi.adm`, mis. "FOLDER - WULAN" 2026-10-08 11:18), 4 dokumen ber-verifikasi, 4 folder Izin On (mis. CABANG - SEMARANG, "Folder A"), `PUSAT - MAGELANG` diubah jadi {MGL} saja pada 09:50, 7 baris `archive_permissions`. Akibat: suite ED-1026/1027/1028 gagal 100% apa adanya (prasyarat "opname kosong"). Saran: developer membersihkan data opname uji atau pemilik QA 1026-1028 membuat baseline mandiri. QA ini tidak menyentuh data itu.
- **O-1** `PUT archives/{id}` dengan kelompok izin ke id **dokumen** (type FILE) menulis `archive_permissions` + `is_folder_permission`=1 pada baris dokumen (HTTP 200); `GET archives/{id dokumen}` lalu mengembalikan `is_folder_permission`=1, bertentangan kalimat kontrak 2 ("dokumen: 0"). Perilaku pra-ada (tercatat developer), kini dapat dicapai tanpa `Update Folder`. Tidak ada AC; usul: tolak/abaikan untuk dokumen.
- **O-2** `MyHelper::hasPermission` tidak menyaring `permissions.is_active` dan tidak punya bypass customer, beda dengan middleware `permission_v5` (`scopePermissionByRole`: `is_active`=1; customer lolos). Praktis tidak berpengaruh (1140 aktif); mengikuti R-4 developer.
- **O-3** Tenant lain: seed ulang + grant ulang role manual (1133 lama tidak dibersihkan di sana), sesuai R-6; di luar QA ini.
- **O-4** `user-permissions` (ED-1029) masih `permission_v5:Update Folder` dan `assertCanManage` = Update efektif (R-5); halaman by User dan drawer sekarang tidak sama untuk D/F. Revisi ED-1029.

## Review koreksi diff BE

- Aturan spec vs respons nyata: BR-1/4/6/7/8/9/15/18 terpetakan ke AC di atas, tidak ada selisih. `deniedBy()` = model referensi independen yang ditulis ulang dari spec (X-15: 60 folder x 4 hak, plus rename/put-in/DELETE). Urutan kontrak 3 (404 -> 407 scope -> `GE0114` detail -> 408 -> 410 -> `GE0114` izin -> 422 -> 411) terbukti per langkah (AC-38). `authorize()` mendahului validasi; `rules()`: `name`/`is_all_location` `sometimes` hanya bila izin saja.
- Kontrak vs respons: `parameter` GE0114 ("Update Folder, Folder Permissions" di route, nama tunggal di dalam), pesan ID "Anda tidak memiliki izin untuk Folder Permissions!", bentuk 403 list (`msg_code`+`denied_by`) vs show/history (`code`+`parameter`), kelompok izin tanpa perubahan = tanpa tulisan: sesuai. Satu selisih kecil: O-1.
- Seed: sesuai spec 3 (5 check, 7 uncheck untuk 1139/1140; `UNIQUE`/PK aman di DB scratch). Skema: tanpa perubahan, tanpa Updater baru. Rollback: penolakan 403/422/400 tidak meninggalkan tulisan (snapshot archives, izin, riwayat di setiap tolakan; E: detail lolos + izin ditolak = tidak tertulis). Kolom v3: tabel `archives*` tidak dipakai v3. State lintas request: tanpa `static`/properti (X-4 + role fixture tercermin langsung).
- Log Laravel pada jendela run: error hanya dari probe karakterisasi sengaja (kolasi latin1 = X-12 dan ED-1029 X-1; `CustomizeBuilder`/`Str.php`/`json_decode`/`Unsupported operand` = X-8, X-13) dan token yang dicabut `force_login`. Tidak ada error dari kode diff.

## Isolasi tenant & permission

- **Tenant**: tidak berlaku (`QA_DBS` = `api_sidomaju` saja); kode tanpa `static`/cache (X-4).
- **Permission** (tanpa endpoint baru; yang berubah: PUT archives/{id}): user berhak 2xx (A, D, E, F, superadmin 1/2 sesuai kelompok); tanpa permission 403 `GE0114` dengan `parameter` tepat: role 6/N (kedua nama), C (izin), D (detail), E (izin), F (detail dan `List Archive` pada show). `QA_USER2` sekarang bisa bind (dipakai ED-1029 X-PERM); QA ini memakai pertukaran role `QA_USER` (konsisten ronde sebelumnya).

## Seed ulang (EPIC K-4 a), yang diterapkan

1. Diterapkan developer (run.md 15:57) tanpa TRUNCATE: `permissions` 1139 + 1140, `permission_checks`/`unchecks` 1139/1140, `role_permissions` role 1/2/3 x {1139, 1140}; 1133 dibuang.
2. QA: skrip banding (scratchpad `reseed.php`) mem-parse `permission_salesman.sql` dan membandingkan dengan DB untuk id 1133-1140 (permissions, checks, unchecks, role_permissions role 1/2/3, sisa 1133-1138 di role mana pun). Hasil **DRY-RUN: 0 langkah**; tidak ada tulisan oleh QA. State terverifikasi (AC-25): permissions 1139 "Opname Document" dan 1140 "Folder Permissions"/"Perizinan Folder" (module 1266, sort 10/11, aktif); checks `(1081,1139) (1081,1140) (1139,1082) (1140,1082) (1140,1084)`; unchecks `(1081,1139) (1081,1140) (1082,1139) (1082,1140) (1084,1140) (1139,1081) (1140,1081)`; role 1, 2, 3 memegang keduanya; 0 baris untuk 1133-1138.
3. Grant ulang role uji: role 3 (A) sudah memegang 1140; peran lain = role fixture yang dibuat skenario dengan permission persis (tanpa mengubah role bawaan 6/16/18/26/29).

## Skenario usang

**Diperbarui QA ini** (`features/ED-1025-.../qa/`): `qa_lib.php` (`q2_access_is` manage = peran, role fixture, helper), `scenario1` (AC-1 baris 29 dan 95, AC-2, AC-4, AC-11; AC-10/11/15 dipindah ke `scenario1b-...`), `scenario2` (AC-12 body kosong 422; AC-9 9b/9c), `scenario3` (AC-13 anak Off, AC-14 FU, AC-24 G), `scenario4` (AC-23 parameter, `manage_permission` role 29), baru `scenario7-rev-hak.php`, `scenario8-rev-izin.php`, `scenario9-rev-seed-fuzz.php`. e2e `ED-1025-folder-permission.spec.js:113,198` = scope fe (tidak disentuh).

**ED-1024 (diperbarui QA ini, `features/ED-1024-.../qa/`)**: `scenario3-permission.php:17` (AC-12: parameter PUT = "Update Folder, Folder Permissions" + baris "PUT izin saja"); `qa_lib.php:469-512` (simulasi tanpa seed Archive kini menghapus 1081-1090 **dan 1139, 1140** = 12 baris, pulih persis; sebelumnya izin-saja tetap lolos route lewat 1140); karena data pengembang (bukan (rev)): `scenario1-scope.php` AC-1 dan AC-5 serta `scenario2-types.php` AC-10 memakai fixture `QA01-` / himpunan dari DB, bukan folder `PUSAT - MAGELANG` (kini {MGL}) dan "Folder A". Hasil 26/26.

**Hanya dicatat (tidak diedit)**:

| Pemilik | file:baris | Sebab |
|---|---|---|
| ED-1026 | `scenario1-schema-seed.php:238-339` (AC-2) | id 1133 -> 1139/1140 (EPIC K-4 a, dari item ini) |
| ED-1026 | `scenario1-schema-seed.php:154` (AC-1) | kunci `config.php` ED-1026 bukan terbesar (Updater ED-1027); bukan dari item ini |
| ED-1026 | `scenario4-session-access.php:157` (AC-15) | kunci `result` bertambah `result_counts`, `is_partial` (item lain) |
| ED-1026 | `qa_lib.php:663` oracle + `scenario2-folders-store.php:30` (AC-5), `scenario5-confirm.php:313` (AC-20) | oracle hanya lokasi, tanpa View folder; folder Izin On pengembang (FB-3) |
| ED-1026 | `qa_lib.php:461-480`, `scenario3-...:112`, `scenario4-...:62`, `scenario5-...:152` | prasyarat/asersi "opname kosong" dan `archives` terverifikasi = 0 gagal oleh data pengembang (T-1): apa adanya 36 FAIL (34 prasyarat opname, AC-1 data pengembang, AC-2 id 1133), 1 PASS |
| ED-1027 | `qa_lib.php:448-466` | idem (apa adanya 19 FAIL, 1 PASS, 1 SKIP) |
| ED-1027 | `scenario1-columns-updater.php:169` (X-1) | `updater_logs` sudah ada barisnya (`hadLog`): `executeUpdater` menulis ulang baris log, hash berbeda; baris log id 197 (key 1791357196) kemungkinan tertulis ulang oleh run regresi ini (hanya log) |
| ED-1027 | `scenario5-sessions.php:81` (AC-10), `scenario6-robust.php:156` (X-5), `scenario8-user2.php:148` (X-8) | `last_sessions`/sesi terlihat memuat sesi pengembang |
| ED-1028 | `qa_lib.php:448-466` | idem (apa adanya 22 dari 22 FAIL); salinan: hitungan +1/+2 (sesi pengembang, user `kevinsudjadi.adm`) di AC-1..9, 11, X-3, X-5, X-8 |
| ED-1028 | `scenario5-access.php:47` | laten: `!in_array(1133, ...)` kini selalu benar (harus 1139) |
| ED-1029 | `scenario1-read.php:124` (AC-4 C2), `:132` | induk membatasi U/D/S (K-1 a) digantikan K-12 a; `manage_permission` = Folder Permissions |
| ED-1029 | `scenario2-write.php:439` (AC-11 QCh) | induk Q tidak lagi menolak Update/Store anak |
| ED-1029 | `scenario3-access.php:122,136,148` (AC-13), `:208`, `:346` (AC-20 max id 1133 -> 1140), `qa_lib.php:464-472` (`q9_access_is`: manage = update) | `manage_permission` dan id permission (K-15 b, EPIC K-4 a) |

Catatan: skenario berhenti di asersi gagal pertama, jadi baris di balik kegagalan (mis. ED-1029 `:132`, `:208`; asersi sesudah baseline di ED-1026..1028) belum tentu semuanya terdaftar; pemilik perlu menjalankan ulang setelah memperbarui. Regresi ED-1026..1028 lewat salinan (prasyarat opname dilonggarkan jadi "data pengembang tidak berubah"): ED-1026 29/37 PASS (8 FAIL = tabel di atas), ED-1027 16 PASS, 3 FAIL (sesi pengembang), 1 SKIP (X-1 tidak diulang), ED-1028 9/22 (13 FAIL = hitungan sesi pengembang). Regresi asli ED-1029: 18 PASS, 4 FAIL (usang), 1 SKIP (isolasi tenant).

**Karakterisasi bug terbuka tetap, tidak dibalik**: X-8 bagian search dan X-13 (ED-1070), X-12 (ED-1071) PASS = perilaku lama utuh (500 x13, kolasi 500 x75); X-8 PUT induk=diri sendiri tetap 200 + siklus (QA ED-1069); ED-1029 X-1 probe D-1 (ED-1071) 500 x16 sengaja. Dilacak di luar item: ED-1069, ED-1070, ED-1071.

## Yang tidak bisa diverifikasi (dan kenapa)

- Tukar lisensi sungguhan (tanpa Salesman Activity): `PROFILES` kosong -> **MANUAL (Gate 2 epic)**; cara seragam dicek (AC-23, AC-25 + simulasi hapus permission di ED-1024 AC-13).
- Regresi ED-1026/1027/1028 **apa adanya** (T-1): hanya via salinan; verifikasi penuh menunggu DB uji bersih atau baseline mandiri oleh pemiliknya.
- Isolasi tenant: satu DB uji.
- Perilaku php-fpm lain dari RoadRunner: tidak relevan (`BE_SERVER=fpm`).

## Keadaan dipulihkan

Checksum 13 tabel (archives, archive_documents, archive_locations, archive_permissions, user_roles, roles, role_permissions, permissions, permission_checks, permission_unchecks, customers, employees, employee_locations) **identik** sebelum dan sesudah; `users` berubah hanya pada baris `QA_USER` (login). 0 baris `QA01/02/26/27/28/29-`, 0 role `QA02-ROLE-*`, 0 DB scratch, token run di-log-out. Kode aplikasi BE tidak disentuh (5 berkas M sama dengan `changed.txt`); `qa/dev-smoke-rev/` milik developer, diabaikan.

## Riwayat ronde

- Ronde 1 (run awal): 17 AC `[BE]` PASS, AC-23 PASS (BE) + MANUAL, ED-1024 26/26; FAIL hanya 500 pra-ada (`search` non-JSON, `name` array) di luar diff.
- Ronde 2: D-2 diperbaiki; fuzz tipe penuh menemukan D-3 (`id_locations` array >= 2 elemen), D-4/D-5 pra-ada; 27/30 PASS.
- Ronde 3: D-3 diperbaiki; D-4 -> ED-1071, D-5 -> ED-1070 (karakterisasi X-12/X-13); 31/31 PASS, ED-1024 26/26; verdict PASS.
- **Ronde 1 revisi (ini)**: suite diperbarui + 9 skenario baru, 40/40 PASS (3531 asersi); ED-1024 26/26 (diperbarui); regresi ED-1026..1029 dicatat; tanpa defect; verdict PASS.
