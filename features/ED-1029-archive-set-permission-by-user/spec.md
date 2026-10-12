---
key: ED-1029
epic: ED-1022
title: Archive - Set Permission by User
status: approved
batch: ED-1029
fe_modules: [Archive]
new_fe_modules: []            # halaman di module Archive yang sudah ada, bukan module baru
be_modules: [DocumentArchive]
qa_model: sonnet
contract: contract.md
depends_on: [ED-1024, ED-1025]  # rev3: helper kolom All ED-1025 (O-1, berkas FE bersama) dan rev3 ED-1025 lebih dulu (C-4)
---

## Revisi 3 (review batch ronde 2, 2026-10-12)
Sumber: `epics/ED-1022-review-feedback.md` § "Review batch ronde 2" (FB2-2, FB2-3); Gate 1 ronde 3: `epics/ED-1022-gate1.md` §9 (+ §8, O-1..O-6, C-1..C-6). Kode sekarang = acuan (BE 581569981, FE 41480e37c;
ED-1029 rev = BE 33f1feeb3, FE 442ae9851). BR/AC tanpa "(rev3)" sudah terimplementasi dan tetap berlaku; "(rev3)" di BR/AC lama = bagian yang berubah.

| Kode | Perubahan | BR / AC | Layer |
|---|---|---|---|
| FB2-2 | Kolom **Semua** per baris: centang/lepas keempat hak, pola tab Izin (ED-1025 BR-20) | BR-21; AC-29 | FE |
| FB2-3 | Kolom Permission jadi **saklar Izin Folder** (menggantikan K-3 a) dengan aturan tab Izin (ED-1025 K-15 b, BR-16, BR-19/K-14 a; tanpa modal, K-8 c; K-9 a, K-10 b). Satu PUT atomik, body + `folders`; tulis flag = method bersama (EPIC K-5 b) | BR-4, 7, 9, 11-14, 16, 19, 20, 22-24; AC-14, 30-37 | BE+FE |

Riwayat: rev 2026-10-08 (FB-4b, FB-9, FB-5, D-2; Gate 1 ronde 2) terimplementasi, tanda "(rev)"; rinci di git `[DOC] ED-1029 - revisi review`.

## 1. Ringkasan
Admin arsip mengatur hak folder **satu user untuk banyak folder sekaligus** di halaman "Archive Permission" (menu "+" **Set Permission by
User**). Tabel hierarkis seluruh folder: Permission (Izin Folder folder itu), **(rev3)** Semua, View / Update / Delete / Store user terpilih.
Datanya sama dengan tab Izin per folder (ED-1025): `archive_permissions`, `archives.is_folder_permission`, aturan hak efektif, riwayat.
Halaman hanya untuk role ber-*Folder Permissions*; pemegangnya mengubah semua folder dalam scope lokasinya, termasuk yang tidak bisa ia lihat.
**(rev3)** Izin Folder bisa dinyalakan/dimatikan langsung di sini dengan aturan tab Izin (yang menyalakan otomatis mendapat semua hak), dan
kolom Semua mencentang keempat hak satu baris sekaligus. Sumber: S2 layar 03 & 01, CHANGES.pdf S4 hal.1-2 (S2 menang, R-4); PK-1 b; review
2026-10-08 & 2026-10-12. Tidak ada `R-n` brief yang jatuh ke item ini (G-1).

## 2. Aturan bisnis
| # | Aturan | Sumber |
|---|---|---|
| BR-1 (rev) | Menu "+" Archive: item **Set Permission by User** paling akhir (sesudah Opname Document), di root & di dalam folder, tanpa syarat hak folder aktif; hanya role ber-`Folder Permissions` (BR-18), tanpa itu disembunyikan (`getArchiveAddKeys`, `archive.function.js:396`) | [S2 01]; [S4 hal.1]; FB-4b |
| BR-2 | Halaman berdiri sendiri (judul "Archive Permission"/"Izin Arsip", menu Archive tetap aktif), bukan drawer/modal; isinya selalu seluruh pohon folder | [S4 hal.2]; [S2 03] |
| BR-3 | Pilih **User** dulu (tabel kosong sebelumnya). Daftar = `select/document-archive/archive/users` (user aktif ber-`List Archive`, tanpa superadmin 1/2 & customer). PUT tidak memvalidasi user target selain aktif (D-3) | [S2 03]; ED-1025 BR-14; D-3 |
| BR-4 (rev3) | Kolom: Folder (ikon + nama, indentasi, semua level terbuka), Permission (**saklar On/Off**, BR-22; dulu tag read-only), **Semua** (BR-21), View, Update, Delete, Store. Urut nama per level; hanya folder aktif (`type`=1, `is_active`>0) | [S2 03]; FB2-2/3 |
| BR-5 (rev) | Folder tampil = folder aktif dalam scope lokasi user login (`visibleToUser`; superadmin 1/2 semua); induk di luar scope → level teratas. Folder tanpa View user login tetap tampil **dan bisa diubah** | K-2; ED-1025 K-15 b |
| BR-6 (rev) | Nilai sel = baris `archive_permissions` user terpilih (tanpa baris = kosong), nilai **tersimpan**, bukan hak efektif (K-5) | [S2 03]; K-5 |
| BR-7 (rev, rev3) | Sel hak dan Semua bisa diubah hanya bila folder **On menurut layar** (tersimpan, atau saklar yang baru diubah - rev3) **dan** `access.manage_permission` (= role memegang `Folder Permissions`, true di semua node bagi pemegangnya). Folder Off: terkunci, nilai tersimpan. Child On dari folder Off bisa dicentang (K-4). User login bisa memilih dirinya sendiri | ED-1025 BR-7, K-15 b; K-3, K-4 |
| BR-8 | Centang Update/Delete/Store ikut mencentang View; lepas View melepas semuanya; BE menolak U/D/S=1 dengan View=0 (400 `ARCHIVE411`) | ED-1025 BR-3 |
| BR-9 (rev, rev3) | Kotak info = teks rev (`archive.User permission info`) **+ (rev3) kalimat**: en "Turning on {setting} here restricts that folder for every user without View on it and gives you all rights on it." / id "Menyalakan {setting} di sini membatasi folder itu bagi semua user tanpa izin Lihat di sana dan memberi Anda semua hak di folder itu." (K-9 a) | FB-9; FB2-3; ED-1025 BR-22 |
| BR-10 | **Search folder** menyaring pohon di FE (nama mengandung, tanpa peka huruf besar): folder cocok + induknya; perubahan belum disimpan tetap | [S2 03] |
| BR-11 (rev3) | **Save Permission**: satu request atomik berisi hanya folder yang berubah - hak (`permissions`, per folder baris (folder, user) diganti, keempat hak 0 = baris dihapus) dan **(rev3) saklar** (`folders`); folder & user lain tidak tersentuh (kecuali BR-23); gagal satu = tidak ada yang tersimpan. Simpan paralel folder yang sama bergiliran (D-4) | K-7; ED-1025 BR-16; D-4 |
| BR-12 (rev3) | Folder yang baris **atau flag**-nya benar-benar berubah (termasuk baris otomatis BR-23) mendapat satu entri riwayat `permission` oleh user login; flag berubah → `updated_by` folder = user login (sama `PUT archives/{id}`) | ED-1025 BR-16 |
| BR-13 (rev, rev3) | BE menolak, berurutan: role tanpa `Folder Permissions` (403 `GE0114`); user target tidak ada/nonaktif (404 `ARCHIVE440`); folder di `permissions` **atau `folders`** di luar scope lokasi (403 `ARCHIVE407`); body tidak sah (422); id bukan folder aktif (404 `ARCHIVE400`); hak untuk folder yang **tetap Off** (tersimpan Off, tidak dinyalakan request itu; 400 `ARCHIVE441`, BR-24); U/D/S tanpa View (400 `ARCHIVE411`). Hak View/Update user login tidak dicek; `ARCHIVE408` tidak dipakai | D-6; K-4; ED-1025 K-15 b |
| BR-14 (rev3) | **Reset** = semua sel **dan saklar** kembali ke tersimpan tanpa request; ganti User saat ada perubahan (termasuk saklar saja) → konfirmasi (K-6 a) | [S2 03]; K-6 |
| BR-15 (rev) | Superadmin 1/2: semua folder tampil & bisa dikelola tanpa batas scope lokasi; route tetap butuh `Folder Permissions` (role 1/2/3 lewat seed ED-1025) | ED-1025 BR-5, 18 |
| BR-16 (rev3) | Satu sumber data dengan tab Izin: hak **dan flag** yang diubah di sini langsung berlaku di penegakan ED-1025, terlihat di tab Izin (saklar + baris) dan perisai "Akses Terbatas" list, dan sebaliknya | ED-1025 §3, BR-10 |
| BR-17 (rev) | Hanya lisensi Salesman Activity: tanpa lisensi menu tidak ada, route ditolak, endpoint 4xx (bukan 500); seed hanya `permission_salesman.sql` | R-2; `license-features.md` |
| BR-18 (rev) | Permission halaman = **`Folder Permissions`** (label "Perizinan Folder"; id 1140 hanya di seed ED-1025; FE & BE memakai nama): item "+", route FE `/archives/permissions` + guard lokal, `permission_v5` GET/PUT `user-permissions/{id}`, `assertCanManage`. Tanpa syarat `Update Folder`; juga untuk saklar (rev3) | FB-4b; ED-1025 BR-18, K-15 b |
| BR-19 (rev, rev3) | Hak otomatis saat Izin dinyalakan (ED-1025 BR-19) kini juga dari halaman ini (BR-23). Baris otomatis dari tab Izin = baris tersimpan biasa, tampil & bisa diubah di sini. Baris otomatis superadmin 1/2 tidak tampil di sini (bukan anggota daftar User; gate1-r2 C-6) | FB-5; FB2-3; ED-1025 K-14 a |
| BR-20 (rev, rev3) | Save berjalan **tanpa modal konfirmasi**, juga saat User terpilih = user login mencabut View-nya sendiri dan saat saklar diubah (K-8 c; modal hanya di tab Izin, ED-1025 BR-21). Kelola tidak butuh View, jadi sel tetap bisa diubah sesudahnya | D-2; K-8 c; ED-1025 K-16 b |
| BR-21 (rev3) | Kolom **Semua** (judul `common.All`: "All"/"Semua") sesudah Permission, sebelum View: tercentang bila keempat hak, indeterminate bila sebagian; centang = keempatnya, lepas = keempatnya lepas (baris dihapus saat Save, BR-11); kunci sama dengan sel (BR-7); perubahannya dihitung sebagai perubahan (Save aktif, Reset). Tanpa centang-semua di header | FB2-2; ED-1025 BR-20 |
| BR-22 (rev3) | **Saklar Izin Folder** per folder: bisa diubah di semua folder yang tampil bagi pemegang `Folder Permissions` (`access.manage_permission`), termasuk folder tanpa View user login; berlaku sesudah Save. Off→On: sel folder itu aktif dengan nilai di layar (user terpilih tidak dicentang otomatis, kecuali BR-23). On→Off (K-10 b, seperti tab Izin): sel terkunci tetap menampilkan perubahan yang belum disimpan, dan perubahan itu ikut tersimpan; pengecualian: folder yang tersimpan Off lalu dimatikan lagi kembali persis ke tersimpan (perubahannya dibuang, karena folder itu tetap Off, BR-24). Mematikan tidak menghapus baris (ED-1025 BR-16), folder tidak lagi membatasi. Hanya folder itu (subfolder tidak ikut) | FB2-3; K-10 b; ED-1025 BR-1, 7, 16 |
| BR-23 (rev3) | **Hak otomatis pengaktif** (K-9 a): folder yang **tersimpan Off** lalu dinyalakan lewat halaman ini memberi **user login** V/U/D/S, juga superadmin & pembuat (ED-1025 K-14 a). Tidak pernah baris kedua: baris (folder, user login) yang sudah ada - tersimpan, atau dikirim di request yang sama saat user login mengubah barisnya sendiri - dipakai/dinaikkan (syarat developer K-9). User terpilih = user login: FE mencentang keempat hak sel & Semua baris itu sebelum Save (pemicu sama dengan BE: tersimpan Off, C-1), masih bisa diubah; BE tidak menambah apa pun. User terpilih lain: BE menaikkan baris user login dalam PUT yang sama (tidak terlihat sebelum Save). Hanya di `UserPermissionService::update`, bukan di method bersama/`PUT archives/{id}` (O-3) | FB-5; FB2-3; K-9 a; ED-1025 BR-19 |
| BR-24 (rev3) | Hak ditolak 400 `ARCHIVE441` hanya untuk folder yang **tetap Off**: tersimpan Off dan tidak dinyalakan di request itu (K-4 a). Folder yang dinyalakan, atau dimatikan, di request yang sama boleh diberi hak (K-10 b) | K-4 a; K-10 b |

## 3. Model data
| Hal | Isi |
|---|---|
| Tabel/kolom | **Tidak ada yang baru** (juga rev3). DB tenant: `archive_permissions` (`UNIQUE (id_archive,id_user)`), `archives.is_folder_permission` (ED-1025), `archives.updated_by`, `history`. Kolom khusus v5; tidak ada kolom v3 terdampak (D-5) |
| Updater / seed | Tidak ada (`Folder Permissions` 1140 di-seed ED-1025) |
| Service | `UserPermissionService` (`show` `:46-83`, `update` `:97-148`, `assertCanManage` `:163-187`) di atas `ArchivePermissionService` (`context`, `access`, `assertCanManagePermission` `:167`, `lockFolders` `:302`, `saveUserRow` `:324`). **(rev3)** `update` menerima `folders`: `lockFolders` id gabungan tetap query pertama; 404/407 untuk gabungan; 441 menurut BR-24; flag lewat method bersama; baris otomatis BR-23 = `saveUserRow(folder, user login, 1/1/1/1)` (upsert, `UNIQUE` - tanpa baris kedua) bila tersimpan Off → On dan target ≠ user login; riwayat sekali per folder. **EPIC K-5 b:** tulis flag (`is_folder_permission` + `updated_by`, kini `ArchiveService.php:415-424`) diekstrak ke satu method `ArchivePermissionService` (ubah bila beda, kembalikan berubah; tanpa riwayat, tanpa baris otomatis), dipakai `ArchiveService::update` (berkas ED-1025; kolom, `updated_by`, riwayat tetap) dan `UserPermissionService::update`. `show` tetap |
| Display setting | Tidak berubah |
| Kode pesan | Tidak ada yang baru. Item ini: `ARCHIVE440` (404 user), `ARCHIVE441` (400 folder permission belum aktif di <b>[0]</b>). Dipakai ulang: `ARCHIVE200/207/400/407/411`, `GE0114`. `ARCHIVE408` tidak dipakai |

## 4. Endpoint v5 (`api/v5`, `Routes/DocumentArchive/api.php:59-64`, grup `document-archive`, `auth:api`)
| Route | Controller@method | FormRequest | Service | Permission | Status |
|---|---|---|---|---|---|
| GET `document-archive/user-permissions/{id}` (`{id}` = `id_user` target) | `UserPermissionController@show` | - | `UserPermissionService::show` | `permission_v5:Folder Permissions` | sudah ada (rev3 tidak berubah) |
| PUT `document-archive/user-permissions/{id}` | `UserPermissionController@update` (transaksi + `ErrorMessageException`) | `UserPermission/UpdateRequest.php` (`authorize()` GE0114 → 440 → 407 sebelum 422) | `UserPermissionService::update` | `permission_v5:Folder Permissions` | **ubah (rev3)**: `folders` opsional, `authorize()` menilai id gabungan |
| GET `select/document-archive/archive/users` | `SelectArchiveController@user` | - | `SelectArchiveService::user` | - | sudah ada |

Ditolak (rev3): memanggil `PUT archives/{id}` per folder dari halaman ini - N request tidak atomik dengan simpan hak, dan baris otomatis
butuh seluruh baris folder (`folder_permissions` = ganti semua) yang hanya terbaca lewat `GET archives/{id}` (butuh View). Detail: contract.md §2.

## 5. Perubahan FE per module - `src/containers/Archive` (master, aksi baris kustom `archiveActionColumn`)
| Bagian | Perubahan |
|---|---|
| Halaman (ada) | `ArchivePermissionPage/index.js`: guard lokal (`:39-50`), `SelectUser`, Search, Reset, Save (tanpa modal), `Alert` BR-9, `DefaultTable` pohon; peta `changes` per `idArchive`; konfirmasi ganti user (`:91-111`). "+" (`ArchiveButtonAdd/index.js:32`) & route (`routes.js:2101-2103`) = `permissions.FolderPermissions` |
| **(rev3)** Kolom Permission | tag (`:151-167`) → `Switch size="small"` (`checkedChildren`/`unCheckedChildren` `archive.On`/`archive.Off`, `aria-label` "<folder> - Permission"); `checked` = flag di layar; `disabled` = `access.managePermission !== true` atau sedang menyimpan |
| **(rev3)** Kolom Semua | sesudah Permission, judul `common.All`; `Checkbox` `checked`/`indeterminate` dari `getFolderPermissionAllState` (ED-1025, `archive.function.js:157`); `disabled` sama sel hak |
| **(rev3)** Helper `archive.function.js` (+test) | milik ED-1029 (O-1). Entri peta boleh memuat `isFolderPermission`. Baru: flag di layar per node; `changeUserPermissionFlag(changes, node, checked, { isSelf })` (On + `isSelf` + tersimpan Off: keempat hak lewat `onChangeAllFolderPermission`, satu entri per folder; Off: hak entri tetap (K-10 b), kecuali tersimpan Off → entri dibuang); `changeAllUserPermission(changes, node, checked)`. `canChangeUserPermission` (`:1132`) = On di layar. `toUserPermissionRequest` (`:1244`) menambah `folders` (flag ≠ tersimpan) dan mengirim hak folder yang tersimpan On atau On di layar. Entri yang kembali sama dengan tersimpan keluar dari peta (dirty = peta tidak kosong). `isSelf` = `app.user.idUser` = user terpilih |
| **(rev3)** Teks | `archive.locale.js` `archive.User permission info` en `:151-152`, id `:293-294` + kalimat BR-9 |
| **(rev3)** Test & dok | `archive.function.test.js` (helper baru/ubah), `ArchivePermissionPage/index.test.js` (saklar, Semua, body); task baru `docs/specs/Archive/0xx-enh-archive-permission-page-toggle.md`; `_module.md`; `_api.md` #26 lewat api-contract-analyst |
| Bersama ED-1025 | Helper murni kolom All (`getFolderPermissionAllState`, `onChangeAllFolderPermission`) milik ED-1025 (O-10), dipakai tanpa diubah; **tidak** ada komponen bersama (tab Izin = `Form.List`, halaman ini = peta perubahan; bug touched FB2-1 tidak berlaku di sini). Bila ED-1025 rev3 mengubah tanda tangan helper itu, ED-1029 mengikuti |
| Tidak berubah | `ArchivePage`, `ArchiveDrawer`, `menus.js`, `endpoints.js`, `archive.api.js` |

## 6. Acceptance criteria
Profil: QA_DB `api_sidomaju` (Salesman Activity), seed permission ED-1025 (1140/1139) + grant ulang role uji.
User A (non-superadmin; `List Archive`, `Update Folder`, `Folder Permissions`), M (`List Archive`, `View Folder`, `Folder Permissions`), B
(target, `List Archive`), E (tanpa `Update Folder` & `Folder Permissions`), D (= A tanpa `Folder Permissions`), superadmin 1 dan 2. Folder: P
(On, A V+U) > C1 (On), C2 (Off); Q (On, A View saja); R (Off) > R1 (On); L di luar lokasi A; J (On, A View) > JA (On, A V/U/D/S), JB (On, tanpa
baris A), JC (Off). **(rev3)** F, H, K (Off, tanpa baris A/B), G (Off, A View saja). Data uji: hanya folder/role/baris milik QA (`0QA29-`/`QA29F-`),
termasuk baris otomatis BR-23 user login/superadmin di folder QA; tidak ada purge tabel lain.

| # | Given / When / Then | Tag | BR | Cek |
|---|---|---|---|---|
| AC-1 (rev) | A atau M di root & di dalam P klik "+" → "Set Permission by User" paling akhir; D atau E → item tidak ada | [FE] | 1, 18 | unit (`getArchiveAddKeys`, `ArchiveButtonAdd`) + e2e (A, D) |
| AC-2 | A membuka halaman lewat "+" dan URL `/archives/permissions` → "Archive Permission" tanpa error konsol/toast; menu Archive aktif; dropdown User memanggil `select/document-archive/archive/users`; sebelum memilih user tabel kosong, Reset & Save nonaktif | [FE] | 2, 3 | e2e (A) |
| AC-3 (rev) | D atau E membuka `/archives/permissions` → Unauthorized tanpa request `user-permissions`/select user; A dan M → halaman terbuka tanpa kilasan Unauthorized | [FE] | 18 | e2e (A, D, E) + unit guard (M) |
| AC-4 (rev) | A GET `user-permissions/{B}` → 200 `ARCHIVE200`; `result.user.username`=B; `result.data` pohon folder aktif dalam scope A (tanpa L; folder tanpa View A ada), urut nama, R1 di bawah R; node: `is_folder_permission`, nilai B (0 tanpa baris), `access` A menurut ED-1025 BR-4 (C2: semua true), `manage_permission` true di semua node termasuk Q | [BE] | 4-7 | http + DB |
| AC-5 | Superadmin 1 dan 2 GET `user-permissions/{B}` → L ikut, `manage_permission` true di semua folder | [BE] | 5, 15 | http |
| AC-6 | GET/PUT `user-permissions/<id tidak ada>` atau user nonaktif → 404 `ARCHIVE440` | [BE] | 13 | http |
| AC-7 | A PUT `{B}` [P: View+Store, C1: View] → 200 `ARCHIVE207`; baris (P,B), (C1,B) sesuai, lainnya tetap; `history` P dan C1 +1 `permission` oleh A; GET `archives/{P}` memuat baris B yang sama | [BE] | 11, 12, 16 | http + DB |
| AC-8 | (P,B) ada, PUT P keempat hak 0 → baris terhapus; nilai sama → tanpa riwayat baru; `permissions`=[] → 200 tanpa perubahan | [BE] | 11, 12 | http + DB |
| AC-9 (rev) | PUT baris C2 (Off, tanpa `folders`) → 400 `ARCHIVE441` (parameter C2); bersama baris P yang sah → P juga tidak tersimpan | [BE] | 7, 11, 13 | http + DB |
| AC-10 | PUT baris `is_update`=1, `is_view`=0 → 400 `ARCHIVE411`, data tetap | [BE] | 8 | http |
| AC-11 (rev) | A PUT baris L → 403 `ARCHIVE407` (sebelum 422); id dokumen/tidak ada → 404 `ARCHIVE400`; `id_archive` ganda/flag kosong → 422; baris Q (A View saja) → 200. Tanpa `ARCHIVE408` | [BE] | 13 | http + DB |
| AC-12 | R Off, R1 On: PUT baris R1 → 200 [K-4 a] | [BE] | 7 | http |
| AC-13 | A memberi B View di P → B GET `archives?id_archive=P` 200; dicabut → 403 `ARCHIVE407` | [BE] | 16 | http (A, B) |
| AC-14 (rev, rev3) | A pilih B → hierarki & indentasi sesuai GET, semua level terbuka; kolom Permission = **saklar** sesuai `is_folder_permission`; sel & Semua aktif di folder On (P, C1, Q, R1), terkunci di folder Off (C2, R) dengan nilai tersimpan; info BR-9 tampil | [FE] | 4, 5, 7, 9 | e2e (A) + `manual (Gate 2)` |
| AC-15 | Centang Update di P → View ikut; lepas View → keempatnya lepas | [FE] | 8 | unit + e2e |
| AC-16 | Ubah P, cari "C1" → hanya P dan C1; kosongkan → perubahan di P masih ada | [FE] | 10 | unit + e2e |
| AC-17 | Ubah P dan C1, Save → body PUT hanya P dan C1, toast sukses, tabel dimuat ulang | [FE] | 11 | e2e (request) |
| AC-18 | [K-6 a] Reset → nilai tersimpan tanpa request; ganti User saat ada perubahan → konfirmasi, Tidak = tetap di user lama | [FE] | 14 | e2e |
| AC-19 | BE menolak saat Save (P dimatikan di tab Izin sesudah halaman dibuka → 400 `ARCHIVE441`) → toast error, perubahan tetap | [FE] | 11, 13 | e2e (ubah DB) |
| AC-20 (rev) | Tanpa lisensi: menu tidak tampil, route tertolak, endpoint 4xx bukan 500 (EPIC K-2 b → AC-21); tanpa seed baru; tanpa id permission literal; tukar lisensi manual di Gate 2 epic | [BE+FE] | 17, 18 | http + grep |
| AC-21 (rev) | D GET dan PUT (sah, tidak sah, user tidak ada) `user-permissions/{B}` → 403 `GE0114` `parameter` "Folder Permissions" (mendahului 404/422), DB tetap; A, M dan superadmin 1/2 → GET 200, PUT [P: View] 200 | [BE] | 13, 15, 18 | http + DB |
| AC-22 (rev) | Data J: GET `{B}` → J, JA, JB, JC, `manage_permission` true, `access` JC semua true. PUT [JA: V+S], [J: V], [JB: V] → 200 + riwayat; [JC] → 400 `ARCHIVE441`; [JA + JC] → 400, JA tidak tersimpan | [BE] | 7, 13 | http + DB |
| AC-23 (rev) | Baris A di J dihapus: GET `{B}` → J, JA, JB, JC tetap, `access.view` false, `manage_permission` true; PUT [JA: View+Update] → 200 + riwayat; GET `archives?id_archive=JA` oleh A tetap 403 `ARCHIVE407` | [BE] | 5, 7, 13 | http + DB |
| AC-24 (rev) | A pilih B di data J → J, JA, JB bisa dicentang, JC terkunci; ubah JB + Save → sukses; info en lalu id tanpa "dominant"/"dominan" dan "nearest On parent"/"induk On terdekat" | [FE] | 7, 9 | e2e (A) + `manual (Gate 2)` |
| AC-25 (rev) | F Off tanpa baris A: A menyalakan Izin di tab Izin F + Save → halaman ini, pilih A: F On, V/U/D/S tercentang & bisa diubah; lepas Delete + Save → tab Izin F: A View/Update/Store | [BE+FE] | 16, 19 | e2e (A) + http |
| AC-26 (rev) | [K-8 c] A pilih A, lepas View di JA, Save → tanpa modal, 200; sesudah dimuat ulang sel JA tetap bisa dicentang | [FE] | 7, 20 | e2e (A) |
| AC-27 (rev) | Node GET hanya kunci kontrak §1 (tanpa `created_by`) | [BE] | 4 | http |
| AC-28 (rev) | Skenario usang milik sendiri (`ED-1022-gate1-r2.md` §5) diperbarui di rev; semua skenario (termasuk `scenario5-concurrency.php`) dan e2e dijalankan ulang; X-1 tetap karakterisasi (ED-1071); tanpa 500 | [BE+FE] | 7, 13, 18 | http + e2e |
| AC-29 (rev3) | A pilih B: kolom Semua tepat sesudah Permission. P: centang Semua → V/U/D/S tercentang, Save aktif; lepas → keempatnya lepas, Save nonaktif bila sama dengan tersimpan; centang Update saja → Semua indeterminate; C2/R (Off) → Semua terkunci. Semua di C1 + Save → body hanya C1 1/1/1/1, DB (C1,B) 1/1/1/1; lepas Semua di C1 + Save → baris (C1,B) terhapus | [FE] | 7, 8, 11, 21 | unit + e2e (A) + DB |
| AC-30 (rev3) | A pilih B: saklar aktif di semua folder (termasuk JB, JC; L tidak ada). F Off→On → sel F aktif dengan nilai tersimpan B (tidak tercentang otomatis), Save aktif; centang View F lalu F Off lagi → sel F kembali kosong. [K-10 b] P: centang Update lalu P On→Off → sel P terkunci tetap Update+View; Save → body `folders` [P: 0] + `permissions` [P: V+U]. Saklar saja diubah → Reset mengembalikan; ganti User → konfirmasi. Info en lalu id memuat kalimat rev3. Save tanpa modal, toast, dimuat ulang | [FE] | 4, 7, 9, 14, 20, 22 | e2e (A) + unit + `manual (Gate 2)` tampilan saklar |
| AC-31 (rev3) | A pilih A: F (Off, tanpa baris A) On → View/Update/Delete/Store & Semua tercentang sebelum Save (Q, tersimpan On, Off lalu On → tidak dicentang otomatis, C-1); lepas Delete; Save → body `folders` [F: 1], `permissions` [F: 1/1/0/1]; DB F flag 1, (F,A) 1/1/0/1; drawer F tab Izin: saklar On, A tanpa Delete; list: F berperisai | [BE+FE] | 16, 19, 23 | e2e (A) + DB |
| AC-32 (rev3) | A PUT `{B}` `folders` [F: 1] + `permissions` [F: View] → 200 `ARCHIVE207`; F `is_folder_permission`=1, `updated_by`=A; (F,B) View; [K-9 a] (F,A) 1/1/1/1; riwayat F +1 `permission` oleh A. `folders` [G: 1], `permissions` [] → (G,A) dinaikkan 1/1/1/1, tetap **satu** baris (G,A), tanpa baris (G,B). Superadmin 1 PUT `{B}` `folders` [H: 1] → baris (H, superadmin) 1/1/1/1 | [BE] | 11, 12, 22, 23 | http + DB |
| AC-33 (rev3) | A PUT `{A}` `folders` [F: 1] + `permissions` [F: 1/1/0/1] → (F,A) 1/1/0/1, satu baris (tidak dinaikkan, tanpa baris kedua); `folders` [K: 1] tanpa baris hak → tanpa baris (K,A) [K-9 a: otomatis BE hanya bila target ≠ user login] | [BE] | 23 | http + DB |
| AC-34 (rev3) | P On, (P,B) View: PUT `{B}` `folders` [P: 0] → 200, flag 0, (P,B) tetap, tanpa baris otomatis, riwayat +1. [K-10 b] `folders` [P: 0] + `permissions` [P: V+U] → 200, P Off, (P,B) V+U, riwayat +1. `folders` [C2: 1] + `permissions` [C2: View] → 200; `folders` [C2: 0] (sudah Off) + `permissions` [C2: View] → 400 `ARCHIVE441` parameter C2, data tetap; `folders` [Q: 1] (sudah On) → 200 tanpa tulisan/riwayat/baris otomatis | [BE] | 12, 13, 22, 24 | http + DB |
| AC-35 (rev3) | D PUT `{B}` `folders` [F: 1] → 403 `GE0114` (sebelum 404/422). A: `folders` [L: 1] → 403 `ARCHIVE407` (juga bila body lain tidak sah); id dokumen/tidak ada → 404 `ARCHIVE400`; id ganda di `folders`, `is_folder_permission` bukan 0/1, `folders` bukan array → 422; `folders` [JB: 1] (A tanpa View) → 200. Superadmin 1/2 `folders` [L: 1] → 200. `folders` [F: 1] + baris U tanpa V (411) atau + id tidak ada → F tetap Off, tanpa baris (F,A), tanpa riwayat. [O-6] Fuzz `folders` tanpa karakter non-latin1; probe non-latin1 di `folders` hanya di X-1 (`qa/scenario4-robust.php`, harapan 500 sekarang, milik ED-1071) | [BE] | 13, 15 | http + DB |
| AC-36 (rev3) | Sesudah AC-32: A GET `archives/{F}` → `is_folder_permission` 1, `folder_permissions` A 1/1/1/1 + B View; E (tanpa baris) GET `archives?id_archive=F` → 403 `ARCHIVE407`, B → 200. F dimatikan dari halaman ini → tab Izin F Off dengan baris tetap, E → 200. F dinyalakan di tab Izin → halaman ini F On | [BE+FE] | 16, 22 | http + e2e (A) |
| AC-37 (rev3) | Regresi: semua skenario QA BE ED-1029 (body tanpa `folders` = perilaku lama) dan e2e dijalankan ulang; asersi lama kolom Permission sebagai tag diperbarui (AC-14). `scenario5-concurrency.php` + PUT paralel `folders` [F: 1] oleh dua user dan bersama `PUT archives/{F}` → semua 200, 0 × 5xx, tanpa baris ganda, hasil = yang terakhir diproses. [EPIC K-5 b] QA BE ED-1025 `--only` AC-5, AC-38 diulang (X-12 FAIL diketahui, milik ED-1071). Tanpa 500 baru | [BE+FE] | 11, 13 | http + e2e |

## 7. Subtask - subtask lama ED-1035, ED-1041, ED-1047, ED-1053 dan rev ED-1103, ED-1104, ED-1105 tetap
| # | Judul | Layer | AC | Module |
|---|---|---|---|---|
| 1 (rev) | [BE] Set Permission by User: permission Folder Permissions di user-permissions, kelola semua folder dalam scope lokasi (ED-1103) | BE | 4, 9, 11, 20-23, 27 | DocumentArchive |
| 2 (rev) | [FE] Archive - Set Permission by User: permission Perizinan Folder, sel aktif di semua folder On, teks aturan induk (ED-1104) | FE | 1, 3, 14, 24, 26 | Archive |
| 3 (rev) | [QA] Skenario revisi Set Permission by User, perbarui skenario usang, regresi ED-1025 (ED-1105) | QA | 1, 3, 4, 9, 11, 14, 20-28 | Archive |
| 4 (rev3, ED-1128) | [BE] Set Permission by User: nyalakan/matikan Izin Folder lewat PUT user-permissions, hak otomatis pengaktif, tulis flag bersama | BE | 32-35, 36, 37 | DocumentArchive |
| 5 (rev3, ED-1129) | [FE] Archive - Set Permission by User: kolom Semua dan saklar Izin Folder per folder | FE | 14, 29, 30, 31, 36 | Archive |
| 6 (rev3, ED-1130) | [QA] Skenario kolom Semua dan saklar Izin Folder di halaman by User, regresi tab Izin | QA | 9, 14, 19, 25, 29-37 | Archive |

Urutan: sesudah ED-1025 rev3 (berkas FE bersama, O-1; C-4); 4 → 5 (body `folders`) → 6.

## 8. Keputusan untuk developer
### Revisi 3 (Gate 1 ronde 3, dijawab 2026-10-12; `epics/ED-1022-gate1.md` §9)
| # | Pertanyaan (opsi; rek.; risiko) | Jawaban |
|---|---|---|
| K-9 | Hak otomatis saat menyalakan Izin untuk user terpilih lain (a BE menulis baris user login di PUT yang sama; b PUT kedua dari FE; c tanpa; rek. a; tinggi) | **a + syarat**: tidak pernah baris kedua untuk user yang sama - baris pengaktif yang sudah ada (tersimpan / dikirim di request yang sama) dipakai/dinaikkan; berlaku juga di tab Izin (ED-1025). Hanya di `UserPermissionService::update` (O-3); FE self-grant hanya bila tersimpan Off (C-1) (BR-9, 23; AC-31-33) |
| K-10 | Matikan saklar folder yang selnya sudah diubah (a buang, 441; b ikut tersimpan seperti tab Izin; rek. a; rendah) | **b**: perubahan tetap (terkunci, terlihat) dan tersimpan; BE menerima hak untuk folder yang dimatikan di request yang sama (BR-22, 24; AC-30, 34) |
| EPIC K-5 | Tulis flag: sendiri di `UserPermissionService` (a) atau ekstrak bersama (b); rek. a; rendah | **b**: satu method `ArchivePermissionService` dipakai `ArchiveService::update` dan `UserPermissionService::update`; hak otomatis di luarnya; ulang QA BE ED-1025 `--only` AC-5, AC-38 (§3; AC-37) |
| C-4, O-6 | koreksi epic-check | tetap menunggu ED-1025 rev3 (§7); non-latin1 `folders` hanya di X-1 (AC-35) |

### Sudah dijawab (tidak ditanya ulang)
| # | Jawaban | Imbas |
|---|---|---|
| K-1 | a `Update Folder` → **digantikan** FB-4b + ED-1025 K-15 b: `Folder Permissions` (juga `assertCanManage`) | BR-1, 13, 18 |
| K-2 | a scope lokasi user login, termasuk tanpa View; "read-only" gugur (K-15 b) | BR-5 |
| K-3 | a kolom Permission read-only → **digantikan FB2-3 (rev3)**: saklar dengan aturan tab Izin | BR-4, 22 |
| K-4 | a + validasi BE (flag folder itu sendiri; 400 `ARCHIVE441`) | BR-7, 24 |
| K-5, K-6, K-7 | a nilai tersimpan; a Reset + konfirmasi ganti User; a hanya folder berubah | BR-6, 14, 11 |
| K-8 | c tanpa modal di halaman ini; tanpa `created_by` (2026-10-08) | BR-20; AC-26, 27 |
| ED-1025 K-12/14/15/16, EPIC K-2/K-4; D-3, D-4 | a/a/b/b, b/a (`ED-1022-gate1-r2.md` §9b); tanpa validasi tambahan target; `lockFolders` tetap | BR-3, 6, 9, 11, 18-20 |

## 9. Di luar cakupan
- Tab Izin: kolom All & bug touched (FB2-1), ikon perisai di modal pindah (FB2-5), syarat tanpa baris ganda di tab Izin (K-9), aturan hak efektif,
  `Folder Permissions` seed, select user → **ED-1025**.
- Scope lokasi, `permission_v5` route Archive lain → **ED-1024**. Item "+" Opname Document → **ED-1026**.
- `id_user`/`id_archive` non-latin1 → 500 kolasi (D-1 QA): milik **ED-1071** (P1 ronde 2); X-1 tetap karakterisasi sampai ED-1071 mengubahnya.
- Modal cabut akses sendiri & modal saat menyalakan/mematikan Izin di halaman ini (K-8 c, aturan tab Izin); hak otomatis di `PUT archives/{id}` (O-3); `created_by` di node; validasi
  tambahan user target (D-3); hak otomatis untuk user terpilih selain pengaktif; saklar subfolder ikut induk; centang semua di header / set massal
  per kolom; menyalin hak dari user lain; ekspor; penanda hak efektif per sel (K-5 b).

## Catatan implementasi
- BE: `authorize()` satu `context()`, `GE0114` → `ARCHIVE440` → `ARCHIVE407` (PUT `[]` tanpa `Folder Permissions` = 403); `ARCHIVE400` di service
  (sesudah 422). `saveUserRow` hanya menulis & mengembalikan "berubah", riwayat oleh `update`. `lockFolders` = query pertama (D-4); rev3: id gabungan.
- FE: guard lokal halaman menambal `PrivateRoute` inert (ED-967).
