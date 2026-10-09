---
key: ED-1029
epic: ED-1022
title: Archive - Set Permission by User
status: approved
batch: ED-1029
fe_modules: [Archive]
new_fe_modules: []            # halaman baru di dalam module Archive yang sudah ada, bukan module baru
be_modules: [DocumentArchive]
qa_model: sonnet
contract: contract.md
depends_on: [ED-1024, ED-1025]  # (rev) ED-1025 rev: Folder Permissions 1140, `can_manage`/`manage_permission` (K-15 b), aturan BR-4 (K-12 a)
---

## Revisi review 2026-10-08 (+ jawaban Gate 1 ronde 2)
Sumber: `epics/ED-1022-review-feedback.md` § ED-1029 (dan § ED-1025); jawaban Gate 1: `epics/ED-1022-gate1.md` §9b; aturan bersama:
`features/ED-1025-archive-folder-permission/spec.md` (rev). Kode sekarang = acuan (BE 44bea5136, FE 916dd5770..7fa63869d). BR/AC tanpa
"(rev)" sudah terimplementasi dan tetap berlaku.

| Kode | Perubahan | BR / AC | Layer |
|---|---|---|---|
| FB-4b + ED-1025 K-15 b | Halaman (route FE + guard lokal), item "+", GET/PUT `user-permissions/{id}` **dan** `assertCanManage` memakai **`Folder Permissions`** saja (tanpa `Update Folder`/Update efektif). Semua folder dalam scope lokasi user login bisa diubah, termasuk yang tidak bisa ia View; `ARCHIVE408` tidak lagi dari endpoint ini | BR-1, 5, 7, 13, 15, 18; AC-1, 3, 4, 11, 14, 21, 22, 23 | BE+FE |
| FB-9 + ED-1025 K-12 a | Sel terkunci hanya di folder Izin Off; folder Off tidak dibatasi induknya (hanya View diwarisi): `access` menurut ED-1025 BR-4, teks info baru | BR-6, 9; AC-4, 9, 22, 24 | BE (aturan di ED-1025) + FE teks |
| FB-5 | Tidak ada perilaku baru: Izin dinyalakan hanya dari tab Izin (K-3 a), baris otomatis ED-1025 BR-19 = baris tersimpan biasa | BR-19; AC-25 | - (regresi) |
| D-2 + K-8 c | **Tanpa** modal cabut-akses-sendiri di halaman ini (modal hanya di tab Izin, hanya View, ED-1025 K-16 b); tanpa field `created_by` | BR-20; AC-26, 27 | - |
| EPIC K-4 a | `Folder Permissions` = id 1140 (seed ED-1025); FE & BE memakai nama, QA tidak memakai id literal lama | BR-18; AC-20, 28 | QA |
| D-3, D-4 | **Tetap** (developer): tanpa validasi tambahan user target; `lockFolders` dipertahankan | BR-3, 11, 13 | - |
| QA usang (gate1 §5) | `qa/scenario3-access.php:241-346`, `qa/scenario1-read.php:124,132`, ekspektasi `ARCHIVE408` (`scenario2-write.php:403-475`, `scenario3-access.php:115-118,145,208-231`), e2e `Update Folder`, test FE `UpdateFolder` | AC-1, 3, 28 | QA/FE |

## 1. Ringkasan
Admin arsip bisa mengatur hak folder **satu user untuk banyak folder sekaligus** dari satu halaman "Archive Permission", dibuka
lewat menu "+" **Set Permission by User** di Archive. Halaman menampilkan seluruh folder sebagai tabel hierarkis: kolom Permission
(On/Off = *Enable Folder Permission* folder itu) dan centang View / Update / Delete / Store untuk user yang dipilih. Datanya **sama persis**
dengan tab Izin per folder (ED-1025): tabel `archive_permissions`, aturan hak efektif, validasi, riwayat. **(rev)** Halaman dan item "+"
hanya untuk role ber-*Folder Permissions*; pemegangnya boleh mengubah centang di **semua folder On dalam scope lokasinya**, termasuk folder
yang tidak bisa ia lihat. Centang hanya terkunci di folder yang Izin Folder-nya Off.

Sumber: desain S2 layar 03 (tile 03-04) dan 01 (menu "+"), CHANGES.pdf S4 hal.1-2 (S2 menang, R-4); PK-1 b; review & Gate 1 2026-10-08.
Tidak ada `R-n` brief yang jatuh ke item ini (G-1).

## 2. Aturan bisnis
| # | Aturan | Sumber |
|---|---|---|
| BR-1 (rev) | Menu "+" Archive punya item **Set Permission by User** (paling akhir, sesudah Opname Document), di root maupun di dalam folder, tanpa syarat hak folder aktif; membuka halaman "Archive Permission". Item hanya ada untuk role ber-`Folder Permissions` (BR-18), tanpa syarat `Update Folder`; tanpa itu disembunyikan, seperti item "+" lain tanpa permission (`archive.function.js:260-282`) | [S2 01]; [S4 hal.1]; FB-4b; ED-1025 K-15 b |
| BR-2 | Halaman berdiri sendiri (judul "Archive Permission"/"Izin Arsip", menu Archive tetap aktif), bukan drawer/modal; isinya selalu seluruh pohon folder dari root | [S4 hal.2]; [S2 03] |
| BR-3 | Pilih **User** (wajib sebelum tabel tampil). Daftar = `select/document-archive/archive/users` (user aktif ber-`List Archive`, tanpa superadmin 1/2 & customer). D-3: PUT tidak memvalidasi user target lebih dari itu (cukup aktif, BR-13) | [S2 03]; ED-1025 BR-14; D-3 |
| BR-4 | Tabel hierarkis: Folder (ikon + nama, indentasi, semua level terbuka), Permission (tag On/Off, read-only - K-3), View, Update, Delete, Store (centang). Urut nama per level. Hanya folder aktif (`type`=1, `is_active`>0) | [S2 03]; [S4 hal.2]; `ArchiveService.php:71` |
| BR-5 (rev) | Folder yang tampil = folder aktif dalam scope lokasi user login (`scopeVisibleToUser`; superadmin 1/2 semua); induk di luar scope → level teratas. Folder yang tidak bisa di-View user login tetap tampil **dan bisa diubah** seperti folder lain ("read-only" lama gugur) | K-2; ED-1025 K-6, BR-7, K-15 b |
| BR-6 (rev) | Nilai centang = baris `archive_permissions` user terpilih (tanpa baris = kosong): nilai **tersimpan**, bukan hak efektif (View dari induk On, hak pembuat, folder Off yang tidak membatasi, lokasi user target tidak dihitung di sel) - K-5 | [S2 03]; ED-1025 BR-1/4/6, K-12 a |
| BR-7 (rev) | Sel bisa diubah **hanya bila folder itu On**. `access.manage_permission` = role user login memegang `Folder Permissions` (ED-1025 BR-7), sama di semua folder dan selalu true di halaman ini (BR-18); hak View/Update user login di folder itu atau induknya **tidak** dibutuhkan. Folder Off: terkunci, menampilkan nilai tersimpan. Child On dari folder Off tetap bisa dicentang (K-4). Akibat (sama dengan tab Izin): user login bisa memilih dirinya sendiri dan memberi dirinya hak di folder On mana pun dalam scope lokasinya | [S2 03]; [S4 hal.2]; ED-1025 BR-7, K-15 b; K-3, K-4 |
| BR-8 | Centang Update/Delete/Store ikut mencentang View; lepas View melepas semuanya; BE menolak U/D/S=1 dengan View=0 (400 `ARCHIVE411`) | ED-1025 BR-3 |
| BR-9 (rev) | Kotak info di atas tabel (en/id): "Checkboxes are only active on folders whose {setting} is On. Off folders cannot be checked and do not restrict access. Only View on parent folders applies: a user without View on a parent folder cannot open its subfolders even if checked here. Update, Delete and Store are judged on each folder." / "Checkbox hanya aktif pada folder yang {setting}-nya On. Folder Off tidak bisa dicentang dan tidak membatasi akses. Hanya izin Lihat di folder induk yang berlaku: user tanpa izin Lihat di folder induk tidak bisa membuka subfoldernya walaupun dicentang di sini. Ubah, Hapus, dan Letakkan dinilai di folder masing-masing." Frasa "parent folder lebih dominan" dan kalimat "folder Off mengikuti folder induk On" dibuang | [S2 03 info]; FB-9; ED-1025 BR-1, BR-22, K-12 a |
| BR-10 | **Search folder** menyaring pohon di FE (nama mengandung, tanpa peka huruf besar): folder cocok + induknya; tidak membuang perubahan belum disimpan | [S2 03]; seluruh pohon sudah dimuat |
| BR-11 | **Save Permission**: satu request atomik, hanya folder yang berubah; per folder baris (folder, user) diganti; keempat hak 0 = baris dihapus; folder & user lain tidak tersentuh; gagal satu = tidak ada yang tersimpan. Simpan paralel folder yang sama bergiliran (D-4) | K-7; ED-1025 BR-16; D-4 |
| BR-12 | Folder yang barisnya benar-benar berubah mendapat satu entri riwayat `permission` oleh user login | ED-1025 BR-16 |
| BR-13 (rev) | BE menolak, berurutan: role tanpa `Folder Permissions` (403 `GE0114`, BR-18); user target tidak ada/nonaktif (404 `ARCHIVE440`); folder di luar scope lokasi user login (403 `ARCHIVE407`); body tidak sah (422); id bukan folder aktif (404 `ARCHIVE400`); folder Off (400 `ARCHIVE441`, parameter folder itu); U/D/S tanpa View (400 `ARCHIVE411`). Hak folder user login (View/Update, induk maupun folder itu) tidak dicek: `ARCHIVE408` tidak lagi dikirim endpoint ini | D-6; K-4; ED-1025 BR-7, K-15 b, K-12 a |
| BR-14 | **Reset** = kembali ke nilai tersimpan tanpa request; ganti User saat ada perubahan → konfirmasi (K-6 a) | [S2 03] |
| BR-15 (rev) | Superadmin 1/2: semua folder tampil & bisa dikelola tanpa batas scope lokasi (`access` bypass); route tetap butuh `Folder Permissions` (role 1/2/3 lewat seed ulang ED-1025). Non-superadmin ber-`Folder Permissions`: semua folder dalam scope lokasinya (BR-5, 7) | ED-1025 BR-5, BR-7, BR-18 |
| BR-16 | Perubahan di halaman ini langsung berlaku di penegakan ED-1025 dan terlihat di tab Izin folder itu, dan sebaliknya - satu sumber data | ED-1025 §3 |
| BR-17 (rev) | Archive hanya untuk lisensi Salesman Activity: tanpa lisensi menu Archive tidak ada, route FE ditolak, kedua endpoint 4xx (bukan 500). `Folder Permissions` hanya di-seed di `permission_salesman.sql` (ED-1025) | R-2; `knowledge/license-features.md` |
| BR-18 (rev) | Permission halaman = **`Folder Permissions`** (nama persis; label "Perizinan Folder"; id 1140 hanya di seed ED-1025, FE & BE memakai nama): item "+" (BR-1), route FE `/archives/permissions` + guard lokal halaman, `permission_v5` GET/PUT `user-permissions/{id}`, `assertCanManage` (BR-13). `Update Folder` tidak dipakai di sini dan tidak terkait di seed: mencentang `Folder Permissions` hanya ikut mencentang `List Archive` & `View Folder` (ED-1025 BR-18). Role ber-`Folder Permissions` tanpa `Update Folder` bisa memakai halaman ini sepenuhnya | FB-4b; ED-1025 BR-18, K-15 b; EPIC K-4 a |
| BR-19 (rev) | Hak otomatis saat Izin Folder dinyalakan (ED-1025 BR-19) terjadi hanya di tab Izin (kolom Permission di sini read-only, K-3 a). Barisnya baris tersimpan biasa: tampil di halaman ini untuk user itu (V/U/D/S) dan bisa diubah/dicabut di sini dengan aturan BR-7/8/11. Baris otomatis superadmin 1/2 tidak tampil di sini (superadmin tidak ada di daftar User, BR-3; gate1 C-6). Halaman ini tidak membuat baris otomatis | FB-5; K-3 a; ED-1025 K-14 a |
| BR-20 (rev) | Save saat User terpilih = user login berjalan **tanpa modal konfirmasi**, juga bila mencabut View-nya sendiri (K-8 c). Modal cabut akses hanya di tab Izin (ED-1025 BR-21, hanya View). Karena kelola tidak bergantung View (BR-7), sel folder itu tetap bisa diubah sesudahnya | D-2; K-8 c; ED-1025 BR-21, K-16 b |

## 3. Model data
| Hal | Isi |
|---|---|
| Tabel/kolom | **Tidak ada yang baru** (juga di revisi). Dipakai dari ED-1025 (DB tenant): `archive_permissions` (`UNIQUE (id_archive,id_user)`, `KEY id_user`), `archives.is_folder_permission`; `archives` (`id_archive_parent`, `type`, `is_active`, `history`), `users` |
| Updater | Tidak ada |
| Service | `UserPermissionService extends DocumentArchiveService` (`createHistory`); hak dari `ArchivePermissionService` (`context()` sekali, `access()`, `userRows()`, `saveUserRow()`, `lockFolders()`). **(rev)** `assertCanManage` (`UserPermissionService.php:159-183`): `assertVisible` (403 `ARCHIVE407`, `:179`) tetap; `assertFolders(UPDATE, 'ARCHIVE408')` (`:181-182`) diganti cek flag `can_manage` konteks ED-1025 (§3: memegang `Folder Permissions`); tanpa → 403 `GE0114` `parameter` "Folder Permissions" (bentuk sama ED-1025 contract §3 kelompok izin; cadangan, route sudah menolak lebih dulu). Docblock `:83-89`, `:147-151` dan `UpdateRequest.php:28-31` diperbarui (tanpa 408). `access()` (`manage_permission` = `can_manage`, U/D/S menurut BR-4 K-12 a) diubah ED-1025, `show()` tidak berubah |
| Pohon | dibangun di BE dengan `children` (`UserPermissionService.php:185-247`); `children` hanya bila punya subfolder. **(rev)** tanpa field baru (K-8 c: `created_by` tidak ditambahkan) |
| Permission seed | **(rev)** Tidak ada di item ini; `Folder Permissions` (id **1140**, EPIC K-4 a) di-seed ED-1025 §3, tanpa kaitan ke `Update Folder` |
| Display setting | Tidak berubah |
| Kode pesan | Tidak ada yang baru. Ada (item ini): `ARCHIVE440` (404 user tidak ditemukan/nonaktif), `ARCHIVE441` (400 folder permission belum aktif di <b>[0]</b>). Pakai ulang: `ARCHIVE200/207/400/407/411`, `GE0114` (parameter = nama permission). **(rev)** `ARCHIVE408` tidak lagi dipakai endpoint ini |

## 4. Endpoint v5 (prefix `api/v5`, `Routes/DocumentArchive/api.php:59-63`, group `document-archive`, `auth:api`)
| Route | Controller@method | FormRequest | Service | Permission | Status |
|---|---|---|---|---|---|
| GET `document-archive/user-permissions/{id}` (`{id}` = `id_user` target) | `UserPermissionController@show` | - | `UserPermissionService::show($id)` (`:44-81`) | **(rev)** `permission_v5:Folder Permissions` (dulu `Update Folder`) | ubah (rev): permission; `access` dari ED-1025 (`manage_permission` true di semua node) |
| PUT `document-archive/user-permissions/{id}` | `UserPermissionController@update` (transaksi + `ErrorMessageException`) | `Requests/DocumentArchive/UserPermission/UpdateRequest.php` (`authorize()`: 440 → 407 sebelum 422) | `UserPermissionService::update` (`:94-145`; `lockFolders` query pertama) | **(rev)** `permission_v5:Folder Permissions` | ubah (rev): permission; `assertCanManage` = `Folder Permissions` (tanpa 408) |
| GET `select/document-archive/archive/users` | `SelectArchiveController@user` | - | `SelectArchiveService::user` | - (select) | sudah ada |

Komentar route `api.php:59` diperbarui (FB-4b). Detail body/response: contract.md.

## 5. Perubahan FE per module - `src/containers/Archive` (master, aksi baris kustom `archiveActionColumn`)
| Bagian | Perubahan |
|---|---|
| Halaman `ArchivePermissionPage/` (ada) | `usePage` judul `archive.Archive Permission`, `activeMenu: paths.archive`; `SelectUser` (`endpoints.getSelectArchiveUsers`), Search folder, Reset, Save Permission; `Alert` info BR-9; `DefaultTable` pohon (`rowKey="idArchive"`, terbuka semua, `pagination={false}`); kolom Folder, Permission (Tag On/Off), View/Update/Delete/Store (`Checkbox`, `disabled` = `!canChangeUserPermission(node)`, `archive.function.js:917-918`) |
| State & logika (ada) | peta perubahan per `idArchive` (`changeUserPermission`), saring pohon (`filterUserPermissionTree`), body diff (`toUserPermissionRequest`), konfirmasi ganti user (`index.js:89-109`), sesudah Save muat ulang |
| **(rev)** Permission FB-4b | `ArchiveButtonAdd/index.js:31-32` `canSetPermission` = `usePermission({ permission: permissions.FolderPermissions })`; `routes/routes.js:2097-2107` `permission` + `PrivateRoute` → `permissions.FolderPermissions`; guard lokal `ArchivePermissionPage/index.js:37,44,67,189` → `FolderPermissions` (guard tetap sampai ED-967); docblock `archive.function.js:256` |
| **(rev)** Aturan sel | tanpa ubah logika: `canChangeUserPermission` = On && `access.managePermission` (kini true di semua node bagi pemegang `Folder Permissions`) → sel aktif = semua folder On; docblock `:912-913` ("boleh dikelola" → `Folder Permissions`, ED-1025 K-15 b) |
| **(rev)** Teks info BR-9 | `archive.locale.js` `archive.User permission info` en `:149-150`, id `:289-290` |
| **(rev)** Save (K-8 c) | `onSave` (`ArchivePermissionPage/index.js:124`) **tidak berubah**: tanpa modal cabut akses, tanpa fungsi/locale baru |
| **(rev)** Test & dok | `ArchivePage/index.test.js:62,210-257`, `ArchivePermissionPage/index.test.js:81,159-203`, `archive.function.test.js:464` (UpdateFolder → FolderPermissions; ber-`Update Folder` tanpa `Folder Permissions` = tanpa item/halaman; ber-`Folder Permissions` tanpa `Update Folder` = item & halaman ada); `archive.function.test.js:1091-1102` tetap; `docs/specs/Archive/_module.md:121,131`; `_api.md` #25/#26 lewat api-contract-analyst (`manage_permission`, tanpa 408) |
| Tidak berubah | `ArchivePage` selain "+"; `menus.js` (menu Archive tetap satu); `endpoints.js`, `archive.api.js` |

## 6. Acceptance criteria
Profil: QA_DB `api_sidomaju` (lisensi Salesman Activity), **(rev)** seed ulang permission dulu (ED-1025 AC-25: `Folder Permissions` 1140,
`Opname Document` 1139) lalu grant ulang role uji. User A (non-superadmin; `List Archive`, `Update Folder`, `Folder Permissions`), **(rev) M**
(`List Archive`, `View Folder`, `Folder Permissions` saja = profil D ED-1025), B (target, `List Archive`), E (tanpa `Update Folder` & `Folder
Permissions`), D (= A tanpa `Folder Permissions`, masih ber-`Update Folder`), superadmin 1 dan 2. Folder: P (On, A V+U), anak C1 (On) & C2
(Off) di bawah P, Q (On, A View saja), R (Off) dengan anak R1 (On), L di luar lokasi A. Data FB-9 (pola ED-1025, dibuat user lain): J (On, A
View saja) dengan anak JA (On, A V/U/D/S), JB (On, tanpa baris A), JC (Off).

| # | Given / When / Then | Tag | BR | Cek |
|---|---|---|---|---|
| AC-1 (rev) | Given A atau M di root dan di dalam P When klik "+" Then "Set Permission by User" paling akhir; Given D atau E Then item tidak ada | [FE] | 1, 18 | unit (`getArchiveAddKeys`, `ArchiveButtonAdd`) + e2e (A, D) |
| AC-2 | A membuka halaman lewat "+" dan lewat URL `/archives/permissions` → "Archive Permission" tanpa error konsol/toast; menu Archive aktif; dropdown User memanggil `select/document-archive/archive/users`; sebelum memilih user tabel kosong, Reset & Save nonaktif | [FE] | 2, 3 | e2e (A) |
| AC-3 (rev) | D atau E membuka `/archives/permissions` → Unauthorized, tanpa request `user-permissions`/select user; A dan M (tanpa `Update Folder`) → halaman terbuka tanpa kilasan Unauthorized | [FE] | 18 | e2e (A, D, E) + unit guard (M) |
| AC-4 (rev) | A GET `user-permissions/{B}` → 200 `ARCHIVE200`; `result.user.username`=B; `result.data` pohon folder aktif dalam scope A (L tidak ada; folder tanpa View A tetap ada), urut nama, R1 di bawah R; tiap node `is_folder_permission`, nilai B (0 tanpa baris), `access` A menurut ED-1025 BR-4 (C2 Off di bawah P: view/update/delete/store true, K-12 a) dan `manage_permission` true di **semua** node, termasuk Q (K-15 b) | [BE] | 4, 5, 6, 7 | http + `archive_permissions` QA_DB |
| AC-5 | Superadmin 1 dan 2 GET `user-permissions/{B}` → L ikut tampil, `manage_permission`=true di semua folder | [BE] | 5, 15 | http (2 user) |
| AC-6 | GET/PUT `user-permissions/<id tidak ada>` atau user nonaktif → 404 `ARCHIVE440` | [BE] | 13 | http |
| AC-7 | A PUT `{B}` [P: View+Store, C1: View] → 200 `ARCHIVE207`; baris (P,B), (C1,B) sesuai; baris lain tidak berubah; `history` P dan C1 +1 entri `permission` oleh A; GET `archives/{P}` memuat baris B yang sama | [BE] | 11, 12, 16 | http + DB |
| AC-8 | (P,B) ada, PUT P keempat hak 0 → baris terhapus; nilai sama → tanpa riwayat baru; `permissions`=[] → 200 tanpa perubahan | [BE] | 11, 12 | http + DB |
| AC-9 (rev) | PUT baris C2 (Off) → 400 `ARCHIVE441` (parameter C2); bersama baris P yang sah → P juga tidak tersimpan | [BE] | 7, 11, 13 | http + DB |
| AC-10 | PUT baris `is_update`=1, `is_view`=0 → 400 `ARCHIVE411`, data tetap | [BE] | 8 | http |
| AC-11 (rev) | A PUT baris L → 403 `ARCHIVE407` (sebelum 422, juga bila body lain tidak sah); id dokumen/tidak ada → 404 `ARCHIVE400`; `id_archive` ganda/flag kosong → 422; [K-15 b] baris Q (A View saja) → 200, baris (Q,B) tersimpan. Tidak ada `ARCHIVE408` di kasus mana pun | [BE] | 13 | http + DB |
| AC-12 | R Off, R1 On, A Update di R1: PUT baris R1 → 200 [K-4 a] | [BE] | 7 | http |
| AC-13 | A memberi B View di P → B GET `archives?id_archive=P` 200; dicabut → 403 `ARCHIVE407` | [BE] | 16 | http (A, B) |
| AC-14 (rev) | A pilih B → hierarki & indentasi sesuai GET, semua level terbuka, Permission On/Off; centang aktif di semua folder On (P, C1, Q, R1), terkunci hanya di folder Off (C2, R) dengan nilai tersimpan; info BR-9 tampil | [FE] | 4, 5, 7, 9 | e2e (A) + `manual (Gate 2)` |
| AC-15 | Centang Update di P → View ikut; lepas View → keempatnya lepas | [FE] | 8 | unit + e2e |
| AC-16 | Ubah P, cari "C1" → hanya P dan C1; kosongkan → perubahan di P masih ada | [FE] | 10 | unit + e2e |
| AC-17 | Ubah P dan C1, Save → body PUT hanya P dan C1, toast sukses, tabel dimuat ulang | [FE] | 11 | e2e (request) |
| AC-18 | [K-6 a] Reset → nilai tersimpan tanpa request; ganti User saat ada perubahan → konfirmasi, Tidak = tetap di user lama | [FE] | 14 | e2e |
| AC-19 | BE menolak saat Save (mis. P dimatikan di tab Izin sesudah halaman dibuka → 400 `ARCHIVE441`) → toast error, perubahan tetap | [FE] | 11, 13 | e2e (ubah DB) |
| AC-20 (rev) | Tanpa lisensi: menu Archive tidak tampil, `/archives/permissions` tertolak, kedua endpoint 4xx bukan 500 - cara seragam EPIC K-2 b: role tanpa `Folder Permissions` → AC-21; seed: item ini tidak menambah baris (`Folder Permissions` 1140 hanya di `permission_salesman.sql`, ED-1025 AC-25); FE/BE/QA ED-1029 tanpa id permission literal; guard FE dicek di kode; tukar lisensi manual di Gate 2 epic | [BE+FE] | 17, 18 | http + grep |
| AC-21 (rev) | D GET dan PUT (sah, tidak sah, user tidak ada) `user-permissions/{B}` → 403 `GE0114` `parameter` "Folder Permissions" (mendahului 404/422), DB tetap; A, M (tanpa `Update Folder`) dan superadmin 1/2 (sesudah seed ulang) → GET 200, PUT [P: View] 200 | [BE] | 13, 15, 18 | http + DB |
| AC-22 (rev) | Data J: A GET `user-permissions/{B}` → J, JA, JB, JC tampil, `manage_permission` true di keempatnya [K-15 b]; `access` JC semua true [K-12 a]. A PUT `{B}` [JA: View+Store], [J: View], [JB: View] → 200, baris tersimpan, riwayat masing-masing oleh A (dulu J/JB 403 `ARCHIVE408`); [JC] → 400 `ARCHIVE441` parameter JC (bukan 408); [JA + JC] → 400, JA tidak tersimpan | [BE] | 7, 13 | http + DB |
| AC-23 (rev) | Folder tanpa View tetap bisa dikelola: baris A di J dihapus (A tanpa View di J dan turunannya) → GET `user-permissions/{B}`: J, JA, JB, JC tetap tampil, `access.view` false, `manage_permission` true; PUT [JA: View+Update] → 200 + riwayat JA oleh A; GET `archives?id_archive=JA` oleh A tetap 403 `ARCHIVE407` (membuka tetap butuh View, ED-1025) | [BE] | 5, 7, 13 | http + DB |
| AC-24 (rev) | A pilih B di data J → J, JA, JB bisa dicentang, JC terkunci dengan nilai tersimpan; ubah JB + Save → toast sukses; info BR-9 en lalu id tanpa "dominant"/"dominan" dan tanpa "nearest On parent"/"induk On terdekat" | [FE] | 7, 9 | e2e (A) + `manual (Gate 2)` teks |
| AC-25 (rev) | Folder F Off tanpa baris A: A menyalakan Izin di tab Izin F + Save (ED-1025 AC-31) → halaman ini, pilih A: F On, V/U/D/S tercentang dan bisa diubah; GET `user-permissions/{A}` F = 1/1/1/1. Lepas Delete F di sini + Save → tab Izin F menampilkan A View/Update/Store | [BE+FE] | 16, 19 | e2e (A) + http |
| AC-26 (rev) | [K-8 c] A pilih A, lepas View di JA, Save → tanpa modal, PUT langsung 200; sesudah dimuat ulang sel JA tetap bisa dicentang | [FE] | 7, 20 | e2e (A) |
| AC-27 (rev) | [K-8 c] Node GET `user-permissions/{B}` hanya berisi kunci kontrak §1 (tanpa `created_by`); bentuk response sama dengan sebelum revisi | [BE] | 4 | http |
| AC-28 (rev) | Regresi + skenario usang milik sendiri (gate1 §5): `qa/scenario3-access.php:241-346` (permission route → `Folder Permissions`; id literal 1085/1133 → nama atau 1140), `:115-118,145,208-231` dan `qa/scenario2-write.php:403-475` (408 lewat `user-permissions` → 200 / 407), `qa/scenario1-read.php:124,132` (C2 Off semua true; Q `manage_permission` true), e2e `qa/e2e/routes.json:4-5`, `ED-1029-user-permission.spec.js:10,872,913`, `fixture.php:18` (A/E menurut `Folder Permissions`) diperbarui & dicatat di qa-be.md/qa-fe.md; semua skenario (termasuk `scenario5-concurrency.php`, D-4: 0 × 5xx) dan e2e dijalankan ulang; X-1 (D-1, dilacak ED-1071, ditunda) tetap karakterisasi; tanpa 500 | [BE+FE] | 7, 13, 18 | http + e2e |

## 7. Subtask (rev) - hanya pekerjaan perbaikan; subtask lama ED-1035, ED-1041, ED-1047, ED-1053 tetap
| # | Judul | Layer | AC | Module |
|---|---|---|---|---|
| 1 (rev) | [BE] Set Permission by User: permission Folder Permissions di user-permissions, kelola semua folder dalam scope lokasi | BE | 4, 9, 11, 20, 21, 22, 23, 27 | DocumentArchive |
| 2 (rev) | [FE] Archive - Set Permission by User: permission Perizinan Folder, sel aktif di semua folder On, teks aturan induk | FE | 1, 3, 14, 24, 26 | Archive |
| 3 (rev) | [QA] Skenario revisi Set Permission by User, perbarui skenario usang, regresi aturan folder permission ED-1025 | QA | 1, 3, 4, 9, 11, 14, 20-28 | Archive |

Urutan: sesudah subtask BE 1-2 (rev) ED-1025 (`deniedBy`/`grants` K-12 a, flag `can_manage`, seed 1140) dan FE 3 (rev) ED-1025 (konstanta
`permissions.FolderPermissions`).

**Jira subtask (rev), dibuat 2026-10-08 (sprint 46):** ED-1103 [BE] user-permissions per Folder Permissions · ED-1104 [FE] halaman by User · ED-1105 [QA]

## 8. Keputusan untuk developer
### Revisi (Gate 1 ronde 2, dijawab 2026-10-08)
| # | Pertanyaan (opsi; rekomendasi; risiko) | Jawaban |
|---|---|---|
| K-8 | Modal "mencabut akses sendiri" (ED-1025 D-2) juga di halaman ini saat User terpilih = user login? (a ya, pemicu tab Izin + pengecualian pembuat lewat `created_by` per node; b ya, tanpa pengecualian; c tidak; rek. a; rendah) | Jawaban Gate 1 (2026-10-08): c - tidak ada modal di halaman ini; modal hanya di tab Izin saat kehilangan View (ED-1025 K-16 b). `created_by` tidak ditambahkan, predikat bersama O-10 gugur (BR-20; AC-26, 27) |

Jawaban item lain yang diikuti (bukan pertanyaan item ini):
| Asal | Jawaban Gate 1 (2026-10-08) | Imbas di sini |
|---|---|---|
| ED-1025 K-15 | b - `Folder Permissions` saja; semua folder dalam scope lokasi, termasuk tanpa View | BR-1, 5, 7, 13, 15, 18; AC-1, 3, 4, 11, 14, 21-23; `assertCanManage` |
| ED-1025 K-12 | a - folder Izin Off tidak dibatasi induknya; hanya View diwarisi | BR-6, 9; AC-4, 9, 22, 24; QA C2 |
| ED-1025 K-16 | b - modal hanya saat kehilangan View, hanya di tab Izin | BR-20 (K-8 c) |
| ED-1025 K-14 | a - baris otomatis di FE tab Izin, juga superadmin/pembuat | BR-19 (+ gate1 C-6); AC-25 |
| EPIC K-4 | a - `Folder Permissions` 1140, `Opname Document` 1139 (seed ED-1025); FE & BE memakai nama | BR-18; AC-20, 28 |
| ED-1070, ED-1071 | ditunda (Gate 1; task lain, bukan run ini) | bukan dependensi; D-1 QA tetap dilacak di ED-1071 (§9) |

### Gate 1 2026-10-06 (sudah dijawab)
| # | Pertanyaan → jawaban |
|---|---|
| K-1 | Permission menu/route/endpoint → a (`Update Folder`, G-4). **(rev) Digantikan FB-4b + ED-1025 K-15 b: `Folder Permissions`, juga untuk `assertCanManage` (BR-13, 18)** |
| K-2 | Folder yang tampil → a (scope lokasi user login, termasuk tanpa View; G-1). **(rev) "read-only" gugur oleh ED-1025 K-15 b: bisa diubah (BR-5)** |
| K-3 | Kolom Permission On/Off → a (read-only; nyalakan lewat tab Izin) |
| K-4 | "termasuk child dari folder Off" → a + validasi BE (hanya flag folder itu sendiri; FE kunci, BE 400 `ARCHIVE441`) |
| K-5 | Nilai tersimpan vs hak efektif → a (hanya tersimpan; aturan dijelaskan teks info BR-9) |
| K-6 | Reset & perubahan belum disimpan → a (Reset ke tersimpan; ganti User → konfirmasi) |
| K-7 | Isi request Save → a (hanya folder berubah) |
| EPIC K-2 | Cara cek AC lisensi → b (AC-20) |

Keputusan developer di review (bukan pertanyaan): D-3 tanpa validasi tambahan user target; D-4 `lockFolders` dipertahankan.

## 9. Di luar cakupan
- Permission `Folder Permissions` (seed 1140, checks), aturan hak efektif BR-4 (`deniedBy`/`grants`/`access`, flag `can_manage`), hak otomatis FB-5,
  kolom All, modal cabut akses (hanya tab Izin), tabel `archive_permissions`, select user → **ED-1025**.
- Scope lokasi dan `permission_v5` route Archive lain → **ED-1024**. Item "+" Opname Document → **ED-1026**.
- `id_user`/`id_archive` non-latin1 → 500 kolasi (D-1 QA BE ED-1029): tetap dilacak di **ED-1071**, ditunda di Gate 1 (task lain, bukan run ini,
  tidak ditunggu); karakterisasi X-1 tetap, tidak dibalik.
- Modal konfirmasi cabut akses sendiri & field `created_by` di halaman ini (K-8 c); validasi tambahan user target (D-3); menyalin hak dari user
  lain, set massal per kolom, ekspor; mengubah `is_folder_permission` dari halaman ini (K-3 a); penanda hak efektif per sel (K-5 b).

## Catatan implementasi
- 2026-10-07 BE (ED-1041): PUT 403 `ARCHIVE407` hanya untuk folder di luar scope lokasi (`DocumentArchiveService::assertVisible`, teks generik tanpa `parameter`). Folder tanpa View efektif user login → 403 `ARCHIVE408` (`manage_permission` = Update efektif), bukan `ARCHIVE407`. **(rev) Gugur (ED-1025 K-15 b): tanpa 408; `assertCanManage` = scope lokasi + `Folder Permissions`.**
- 2026-10-07 BE (ED-1041): 404 `ARCHIVE440` juga dicek di `UpdateRequest::authorize()` (sebelum 422); `id_archive` bukan folder aktif dilewati `authorize()` → 404 `ARCHIVE400` di service (sesudah 422).
- 2026-10-07 BE (ED-1035/ED-1041): `ArchivePermissionService::saveUserRow` hanya menulis/menghapus baris dan mengembalikan "berubah"; riwayat `permission` ditulis `UserPermissionService::update` (`createHistory`). Baca per user lewat `ArchivePermissionService::userRows($idUser)`.
- 2026-10-07 BE fix ronde 1 (D-2 QA): `ArchivePermissionService::lockFolders` (FOR UPDATE per id, urut id) = query pertama transaksi `UserPermissionService::update` dan `ArchiveService::update`; simpan paralel bergiliran, hasil akhir = kiriman terakhir. **2026-10-08 developer: dipertahankan (D-4).**
- 2026-10-07 FE fix (D-1 QA FE): guard lokal `ArchivePermissionPage` (redirect `/unathorized`, 0 request) menambal `PrivateRoute` inert (ED-967). **(rev)** permission guard → `Folder Permissions`.
- 2026-10-08 BE (rev, ED-1103): GET/PUT `user-permissions/{id}` → `permission_v5:Folder Permissions`. `assertCanManage` = `ArchivePermissionService::assertCanManagePermission` (403 `GE0114`, `parameter` "Folder Permissions") lalu `assertVisible` (403 `ARCHIVE407`); `assertFolders(UPDATE, 'ARCHIVE408')` dibuang, hak View/Update user login tidak dinilai. `show()` tidak diubah (`access` dari ED-1025: JB On tanpa baris A → `view` false, JC Off di bawah J ber-View → semua true; `manage_permission` true di semua node).
- 2026-10-08 BE (rev, ED-1103): `UpdateRequest::authorize()` memakai satu `context()` dengan urutan `GE0114` → `ARCHIVE440` → `ARCHIVE407` (sama BR-13). Cek `GE0114` di authorize/service hanya berpengaruh untuk user customer (middleware `permission_v5` mem-bypass customer); karena dicek sebelum daftar folder, PUT `[]` oleh role tanpa `Folder Permissions` = 403, bukan 200.
