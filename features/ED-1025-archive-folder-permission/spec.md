---
key: ED-1025
epic: ED-1022
title: Archive - Folder Permission per folder (tab Permission & akses ditolak)
status: approved
batch: ED-1025
fe_modules: [Archive]
new_fe_modules: []
be_modules: [DocumentArchive, Select/DocumentArchive, UpdateVersion, app/Sql]
qa_model: sonnet
contract: contract.md
depends_on: [ED-1024]
---

## Revisi ronde 3 (review batch 2026-10-12)
Sumber: `epics/ED-1022-review-feedback.md` § "Review batch ronde 2" (ED-1025, P6). Acuan: BE HEAD 581569981, FE HEAD 41480e37c (termasuk
commit UI developer). BR/AC lama tetap; baru = "(rev3)". **Hanya FE + QA; BE, skema, permission, contract tetap.** Gate 1 ronde 3:
`epics/ED-1022-gate1.md` §9 (disetujui 2026-10-12).

| Kode | Masalah | Akar / data | BR / AC |
|---|---|---|---|
| FB2-1 | Perubahan **hanya** lewat kolom Semua → Save: "Tidak ada perubahan untuk disimpan", drawer tertutup, perubahan hilang | Checkbox Semua bukan `Form.Item` (`ArchiveFolderPermission/index.js:94-104`); `onChangeAll` (`:50-53`) = `form.setFieldValue` → `setFields` tanpa `touched` (rc-field-form 1.38.2 `lib/useForm.js:655-660`; `touched` hanya lewat trigger `Form.Item` `lib/Field.js:462-464` / `setFields` ber-`touched` `:189-192`). `useUpdate.onFinish` (`src/hooks/apiHook.js:611-620`): `isFieldsTouched()` false → `message.info` + `onClose()` tanpa PUT | BR-23; AC-39 |
| FB2-5 | Picker tujuan Pindahkan & pindah massal tanpa perisai | List: `ArchivePage/index.js:382-390`. Kedua picker = `ArchiveTable` (`ArchiveTable/index.js:123-135`, tanpa perisai), data GET `document-archive/archives` yang sama → `is_folder_permission` sudah per baris (`ArchiveService.php:81`, `:134`): **BE tetap**. Konfirmasi pindah massal memakai kolom list → sudah berperisai | BR-24; AC-40 |
| P6 a | Oracle e2e usang | `qa/e2e/ED-1025-folder-permission.spec.js:124`: `set-permission` masih `Update Folder`; FE = `Folder Permissions` | AC-41 |
| G1-r3 #1 | Hak otomatis tanpa baris ganda (syarat developer pada ED-1029 K-9 a, juga tab Izin) | Ganda tidak terjadi di alur normal (`grantOwnFolderPermission` `archive.function.js:241-252` menaikkan baris sendiri; "+ Add User" `excepts` `ArchiveFolderPermission/index.js:41`), tetapi `add()` (`:169-171`) tanpa penjaga dan pencocokan FE peka huruf/spasi. BE sudah menolak/menyatukan (`UpdateRequest.php:54` `distinct` 422; `saveUserRow` + UNIQUE) → **BE tetap** | BR-25; AC-42 |
| catatan | X-12 (latin1) | di `qa/scenario5-input-types.php:358` (bukan scenario4); diubah ED-1071 (AC-9), tidak di sini | - |

## Revisi review 2026-10-08 (ronde 2, terimplementasi: BE 8ea8ebcf2, FE e5355a9c6)
FB-4a/K-15 b, FB-5/K-14 a, FB-6, FB-7, FB-9/K-12 a/K-13 a, D-2/K-16 b, N-3, EPIC K-4 a → BR/AC bertanda "(rev)". Tetap: `ARCHIVE407` dua
bentuk body; `_module.md`; Detail read-only penuh tanpa Update.

## 1. Ringkasan
Folder Archive bisa dibatasi per user lewat tab **Izin** di drawer folder: toggle *Aktifkan Izin Folder* (default mati) dan tabel Pengguna ×
All/View/Update/Delete/Store; superadmin selalu lolos. **(rev)** Hanya View yang mengikuti folder induk; Update/Delete/Store dinilai di folder
itu sendiri dan folder Izin nonaktif tidak membatasinya. Tanpa View user melihat layar "Anda tidak punya akses ke folder ini"; dokumennya tetap
bisa dicari dan dipindahkan. **(rev)** Tab Izin hanya dan selalu bisa diubah oleh role ber-*Folder Permissions*; yang menyalakan Izin otomatis
mendapat semua hak; mencabut akses lihat sendiri meminta konfirmasi. **(rev3)** Perubahan lewat kolom Semua tersimpan; picker tujuan
pindah berperisai seperti list. Sumber: S2 (01, 02, 06), S4 hal.1-3; PK-1 b; review 2026-10-08 dan 2026-10-12.

## 2. Aturan bisnis
| # | Aturan | Sumber |
|---|---|---|
| BR-1 (rev) | Flag Izin Folder (`is_folder_permission`), default nonaktif (folder baru & lama). Nonaktif = folder itu tidak membatasi Update/Delete/Store (cukup View efektif, scope lokasi 01, permission module); View-nya tetap mengikuti induk On (BR-4) | [S2 02]; [S4 hal.1]; FB-9; K-12 a |
| BR-2 | Hak per user per folder: **View** (melihat & membuka isi), **Update** (mengubah folder), **Delete** (menghapus), **Store** (menyimpan/menambah dokumen) | [S4 hal.1]; [S2 02] |
| BR-3 | Update/Delete/Store hanya bersama View: centang salah satunya ikut mencentang View; lepas View melepas semua; BE menolak U/D/S=1 dengan View=0 (400 `ARCHIVE411`) | pola `permission_checks`; desain 02 |
| BR-4 (rev) | Hak efektif user U atas folder F. **View**: setiap folder Izin On di jalur root→F (termasuk F) memberi View (induk dominan hanya untuk View). **Update/Delete/Store**: View efektif di F **dan** F memberi hak itu; F Off = tidak dibatasi (K-12 a). Hak di folder induk tidak dibutuhkan. *Folder penolak* (pesan/`denied_by`): folder On teratas di jalur yang tidak memberi View; bila View lolos, F | FB-9 (contoh "Area A"); K-12 a; menggantikan K-1 a |
| BR-5 | Superadmin (role `is_superadmin` 1 dan 2) selalu lolos semua hak folder | [S2 02]; R-8; K-2 |
| BR-6 (rev) | Pembuat folder (`created_by`) punya semua hak di folder buatannya (saat folder itu dinilai: di jalur View atau sebagai F); View tetap tunduk induk On | [S2 02]; K-3; FB-9 |
| BR-7 (rev) | Tab Izin tampil **dan bisa diubah** hanya bagi role ber-`Folder Permissions` (`access.manage_permission` = memegangnya, sama di semua folder; tanpa syarat `Update Folder`/Update efektif). Pemegangnya boleh mengatur izin folder mana pun dalam scope lokasinya, termasuk yang tidak bisa ia View: drawer hanya untuk folder ber-View, sisanya lewat halaman Set Permission by User (ED-1029). BE: field izin tanpa permission itu → 403 `GE0114`; kelompok izin tidak dicek hak folder (selain scope lokasi); show tanpanya → `folder_permissions`=[], `manage_permission`=false | FB-4a; K-15 b |
| BR-8 (rev) | Hak folder **dan** permission module 1266 sama-sama wajib (`Update Folder`, `Delete Folder`, `Add Document`, `Move Archive`, `Add Folder`, `View Folder`); pengecualian kelompok izin: BR-7 | `permission_salesman.sql:67-76`; K-5 |
| BR-9 (rev) | Aksi → hak (BR-4): buka/detail/Info/riwayat folder = View; ubah detail & rename = Update di F; ubah izin = BR-7; hapus = Delete di F dan di setiap subfolder aktif turunannya (ada yang tanpa → 403 `ARCHIVE418`), folder tetap harus kosong (400 `ARCHIVE401`); Store Document, pindah apa pun **ke** X, buat subfolder di X = Store di X; pindah **folder** F (put-in / ganti induk) = Update di F + Store di tujuan, induk asal tidak dicek (K-13 a); dokumen keluar dari folder tanpa View = boleh; tujuan root tidak dicek. Akibat K-12 a + K-13 a: subfolder Off bisa dipindah keluar induknya oleh siapa pun ber-View di sana | [S4 hal.1]; [S2 06]; K-7; K-12 a; K-13 a |
| BR-10 (rev) | Folder Izin On tetap tampil di daftar induknya dengan ikon perisai bertooltip **"Restricted Access"/"Akses Terbatas"**, juga bagi user tanpa View; dibuka → layar 06 | [S2 01, 06]; K-6; FB-7 |
| BR-11 (rev) | Layar 06 (teks desain S2 06): judul "Anda tidak punya akses ke folder ini", deskripsi dengan nama folder penolak, catatan dokumen tetap bisa dicari & dipindahkan, tombol **Back to Archive** (id-ID **"Kembali ke Arsip"**) ke root; toolbar & tabel tidak tampil, breadcrumb tetap | [S2 06]; [S4 hal.3]; N-3 |
| BR-12 | Pencarian tetap mengembalikan dokumen di folder tanpa View; tiap baris folder membawa hak efektifnya | [S2 06] |
| BR-13 | Hand Over, Receive, Info dokumen, related transaction tidak dicek hak folder | K-8 |
| BR-14 | "+ Add User": user aktif ber-`List Archive`, tanpa superadmin 1/2 & customer, tanpa yang sudah di tabel | K-9 |
| BR-15 (rev) | Detail + Izin disimpan dengan satu **Save** drawer (satu request, atomik); tab Verification tidak ikut. Body memuat kelompok detail bila `Update Folder` + Update efektif, kelompok izin bila `Folder Permissions`; tanpa Update, Detail read-only, Save tetap ada untuk tab Izin | [S2 02]; K-15 b |
| BR-16 (rev) | Baris tabel Izin menggantikan seluruh isi folder saat disimpan; baris tanpa hak tidak disimpan; toggle nonaktif tidak menghapus baris; "+ Add User" default View saja, baris otomatis BR-19 = keempat hak; perubahan dicatat di riwayat (aksi `permission`) | K-10; FB-5 |
| BR-17 | Tanpa folder Izin On, perilaku Archive sama dengan sebelum fitur ini | D-5 |
| BR-18 (rev) | Permission baru **`Folder Permissions`** (id **1140**, label "Folder Permissions"/"Perizinan Folder", module 1266, seed lisensi Salesman Activity). Mencentangnya ikut mencentang `List Archive` & `View Folder`; `All Archive` ikut mencentangnya; melepas `All Archive`/`List Archive`/`View Folder` melepasnya, melepasnya melepas `All Archive`; tidak terkait `Update Folder` (K-15 b). Role 1/2/3 mendapatnya saat seed ulang. Dipakai ulang ED-1029 | FB-4a/4b; K-15 b; EPIC K-4 a |
| BR-19 (rev) | Menyalakan toggle (Off→On) langsung menambah baris user login dengan V/U/D/S tercentang, atau menaikkan barisnya; tampil sebelum Save, masih bisa diubah (lepas View sendiri → BR-21). Juga superadmin & pembuat (gate1-r2 C-6) | FB-5; K-14 a |
| BR-20 (rev) | Kolom **All** ("Semua") sesudah Pengguna: tercentang bila keempat hak, indeterminate bila sebagian; centang = keempatnya, lepas = keempatnya lepas (baris tetap; tetap kosong saat Save = tidak disimpan). Tanpa centang-semua-baris di header | FB-6 |
| BR-21 (rev) | Save drawer saat sesudah simpan user login (bukan superadmin 1/2, bukan pembuat) di folder Izin On tidak lagi punya **View** di barisnya (baris dihapus atau View dilepas) → modal konfirmasi; **Ya** = simpan; **Tidak** = tanpa request API, drawer tetap terbuka dengan perubahannya. Lepas U/D/S saja = tanpa modal. Hanya di tab Izin (ED-1029 tanpa modal) | D-2; K-16 b; ED-1029 K-8 c |
| BR-22 (rev) | Teks tab Izin: bantuan toggle "Default nonaktif. Saat aktif, akses folder ini dibatasi sesuai izin di bawah. Bisa diubah oleh user dengan izin Perizinan Folder."; peringatan "Hanya izin Lihat di folder induk yang berlaku: user tanpa izin Lihat di folder induk tetap tidak bisa membuka folder ini walaupun dicentang di sini. Ubah, Hapus, dan Letakkan dinilai di folder ini. Superadmin EQUAL selalu bypass." (en setara; tanpa kalimat folder nonaktif, K-12 a) | FB-4a; FB-9; K-12 a; K-15 b |
| BR-23 (rev3) | Centang/lepas kolom **Semua** = perubahan, sama dengan centang hak satu per satu: Save drawer mengirim PUT, tanpa "Tidak ada perubahan untuk disimpan"/"No changes to save" dan tanpa menutup drawer. BR-16 (baris tanpa hak tidak disimpan) dan BR-21 (lepas Semua di baris sendiri = cabut View → modal) tetap | FB2-1 |
| BR-24 (rev3) | Picker folder tujuan modal **Pindahkan** dan langkah **Pilih Folder** pindah massal menampilkan perisai bertooltip "Restricted Access"/"Akses Terbatas" di samping nama setiap folder Izin On, sama dengan list (BR-10), juga folder yang tidak bisa dipilih/dibuka dan hasil pencarian picker; folder Off & dokumen tanpa ikon; aturan pilih/buka tetap (AC-21) | FB2-5 |
| BR-25 (rev3) | Tabel Izin satu folder: **paling banyak satu baris per user**. Hak otomatis BR-19 memakai/menaikkan baris user login yang sudah ada (dimuat atau ditambah lewat "+ Add User"), tidak menambah baris kedua; "+ Add User" tidak menawarkan/menambahkan user yang sudah di tabel. User sama = `idUser` sama tanpa beda huruf besar/kecil & spasi akhir (setara kolasi DB). BE tetap menolak ganda (AC-5) | Gate 1 r3 #1 (ED-1029 K-9 a + syarat) |

## 3. Model data (DB tenant)
| Perubahan | Detail |
|---|---|
| Skema (ED-1031, ada) | `archives.is_folder_permission`, `archive_permissions` (`UNIQUE (id_archive,id_user)`), Updater `2026_10_07_04_25_27_AddArchiveFolderPermission.php` |
| Seed (rev, selesai) | `app/Sql/data/permission_salesman.sql`: `Opname Document` 1139, `Folder Permissions` 1140 (module 1266, label BR-18); checks `(1081,1140)`, `(1140,1082)`, `(1140,1084)`; unchecks `(1140,1081)`, `(1081,1140)`, `(1082,1140)`, `(1084,1140)`. Tenant lama: seed ulang (`ApplicationSetupService.php:223-238`); role 1/2/3 lewat `role_permissions.sql` |
| Service (rev, selesai) | `ArchivePermissionService::deniedBy()`, `context()['can_manage']`; PUT dua kelompok (contract §3) |
| Kode pesan | tidak ada yang baru: `GE0114` (`parameter` = nama permission) dipakai ulang; `ARCHIVE401`, `407`-`411`, `418` tetap |
| (rev3) | tanpa perubahan skema, seed, permission, kode pesan, Updater, kode BE |

## 4. Endpoint v5 (`api/v5`, `auth:api`; `Routes/DocumentArchive/api.php:22-34`)
| Route | Controller@method | FormRequest | Service | Hak folder / permission | Status |
|---|---|---|---|---|---|
| GET `document-archive/archives` | `ArchiveController@index` | - | `ArchiveService::index` | buka folder = View → 403 `ARCHIVE407` + `denied_by`; `access` per baris **(rev) menurut BR-4, `manage_permission` = BR-7**; (rev3) `is_folder_permission` dipakai picker (ada) | ubah (rev) |
| GET `document-archive/archives/{id}` | `@show` | - | `ArchiveService::show` | View → 403 `ARCHIVE407`; **(rev)** tanpa `Folder Permissions`: `folder_permissions`=[], `manage_permission` false | ubah (rev) |
| PUT `document-archive/archives/{id}` | `@update` | `UpdateRequest::authorize()` | `ArchiveService::update` | **(rev)** route `permission_v5:Update Folder\|Folder Permissions`; detail: `Update Folder` (`GE0114`) → `ARCHIVE408` → induk baru Store `ARCHIVE410`; izin: `Folder Permissions` (`GE0114`), tanpa cek hak folder | ubah (rev) |
| PUT `archives/rename/{id}`, DELETE `archives/delete/{id}`, POST `archives/create-folder`, POST `documents/put-in`, GET `archives/history/{id}` | tetap | tetap | tetap | `ARCHIVE401`, `407`-`410`, `418` (contract §4), **(rev)** menurut BR-4 (K-12 a, K-13 a) | ubah (rev, aturan) |
| POST `documents/hand-over`, `receive`; GET `select/.../archive/users` | tetap | - | tetap | tidak dicek | sudah ada |

## 5. Perubahan FE per module - `src/containers/Archive` (master, aksi baris kustom `archiveActionColumn`)
| Bagian | Perubahan |
|---|---|
| Rev (selesai) | `ArchiveDrawer` (`canManagePermission`, Save), `toArchiveRequest` per kelompok, `ArchiveFolderPermission` (kolom All, `grantOwnFolderPermission`, teks BR-22), modal `willLoseOwnFolderAccess`, perisai list, `permissions.FolderPermissions`, locale |
| `ArchiveFolderPermission` (rev3, BR-23) | `onChangeAll` (`:50-53`) menulis keempat hak dengan `form.setFields` per field hak terdaftar (`['folderPermissions', name, right]`) + `touched: true`, bukan `setFieldValue` satu baris, lewat **fungsi murni baru** (mis. `toFolderPermissionRowFields(name, row)` → entri `setFields`); tanda tangan & hasil `getFolderPermissionAllState`/`onChangeAllFolderPermission` **tetap** (dipakai ED-1029, gate1 O-1). `DrawerUpdate`/`useUpdate` tidak diubah |
| Satu baris per user (rev3, BR-25) | pencocokan user (`grantOwnFolderPermission`, penjaga "+ Add User" `onChange` `:169-171`) lewat satu fungsi murni (mis. `isSameFolderPermissionUser(a, b)`: trim akhir + tanpa beda huruf); "+ Add User" user yang sudah ada = tanpa baris baru. Tanda tangan `grantOwnFolderPermission` tetap |
| Perisai bersama (rev3, BR-24) | komponen kecil baru di `archive-components` (mis. `ArchiveRestrictedIcon`, diekspor `index.js`): `SafetyCertificateTwoTone` + `Tooltip` `archive.Restricted Access` bila `type === 'Folder'` && `Number(isFolderPermission) === 1`; dipakai `ArchivePage/index.js:382-390` (ganti inline, tampilan sama) dan render nama `ArchiveTable/index.js:125-135` (`render: (text, row)`). Tanpa request/locale baru |

## 6. Acceptance criteria
Profil: QA_DB `api_sidomaju` (lisensi Salesman Activity). **(rev)** Seed ulang permission (1139/1140) lalu grant ulang role uji (selain 1/2/3;
1133 lama hilang). User A (role non-superadmin ber-`List Archive`, `Update/Delete Folder`, `Add Folder`,
`Add Document`, `Move Archive`, `View Folder`, `Folder Permissions`), B (sama), C (A tanpa `Folder Permissions`), **(rev) D** (`List Archive`,
`View Folder`, `Folder Permissions` saja), superadmin 1 dan 2; folder P (induk) & C (anak). FB-9: J (On, A View saja) dengan anak JA (On, A V/U/D/S),
JB (On, tanpa baris A), JC (Off); folder X (A Store).

| # | Given / When / Then | Tag | BR | Cek |
|---|---|---|---|---|
| AC-1 (rev) | Updater dijalankan 2x → `archives.is_folder_permission` (default 0) & `archive_permissions` (+unique) ada tanpa error; Updater **terdaftar** di `Updaters/config.php` (tidak harus kunci terbesar) | [BE] | Model | DB `SHOW COLUMNS/INDEX` |
| AC-2 | Tanpa folder Izin On: A list, buka, buat subfolder, Store, pindah, rename, ubah, hapus = seperti sebelum fitur; `access` semua `true`, `is_folder_permission`=0 | [BE] | 1, 17 | http |
| AC-3 (rev) | A membuka Archive, folder, drawer, "+ Add User", Info, Pindahkan, pindah massal → tanpa error konsol/toast; jumlah tab & item "+" diasersi menurut permission role | [FE] | 15 | e2e (A, C) |
| AC-4 (rev) | P On, B View+Store: A GET `archives/{P}` → `is_folder_permission`=1, `folder_permissions` berisi B, `access` pemanggil; [K-15 b] `manage_permission`=true bagi A walau tanpa Update di P; C → `folder_permissions`=[], `manage_permission`=false | [BE] | 2, 7 | http |
| AC-5 | A PUT `archives/{P}` `is_folder_permission`=1 + 2 baris → 200 `ARCHIVE207`, P persis 2 baris; baris semua-0 tidak disimpan; `id_user` ganda → 422 | [BE] | 15, 16 | http + DB |
| AC-6 | Baris `is_update`=1, `is_view`=0 → 400 `ARCHIVE411`, data tetap | [BE] | 3 | http |
| AC-7 | P On, B tanpa View: GET `archives?id_archive=P` → 403 `ARCHIVE407`, `result.denied_by.id_archive`=P; dengan View → 200 | [BE] | 4, 11 | http |
| AC-8 | P On (B tanpa View), C On (B View): B buka C → 403, `denied_by`=P; P Off → 200 | [BE] | 4 | http |
| AC-9 (rev) | P On, B V/U/S di P, C On B View saja: rename C / Store ke C → 403 `ARCHIVE408`/`410` parameter C. B V/U/D/S di C, View saja di P: rename, Store ke, DELETE C (kosong) → 200 (dulu 403) | [BE] | 4 | http |
| AC-10 | P On tanpa baris: superadmin 1 dan 2 buka/ubah/hapus/Store → 200 | [BE] | 5 | http |
| AC-11 (rev) | P On dibuat A tanpa baris: A buka & ubah P → 200; A ditolak di C (On) tanpa hak di C. A pembuat C (On), View saja di P → ubah C 200 | [BE] | 6 | http |
| AC-12 (rev) | B View tanpa Update: PUT berisi kelompok detail / rename P → 403 `ARCHIVE408`; ganti induk ke folder tanpa Store → 403 `ARCHIVE410` | [BE] | 7, 9 | http |
| AC-13 | B tanpa Delete: DELETE `delete/{P}` → 403 `ARCHIVE409`, `is_active` tetap | [BE] | 9 | http + DB |
| AC-14 | B tanpa Store di P: put-in/create-folder ke P → 403 `ARCHIVE410`; dokumen di P (B tanpa View) ke folder ber-Store → 200; folder tanpa Update → 403 `ARCHIVE408`; dokumen ke root → 200 | [BE] | 9 | http |
| AC-15 | B tanpa View di P: search nomor dokumen di P → muncul; baris folder `access.view`=false; GET `archives/{P}` & `history/{P}` → 403 `ARCHIVE407` | [BE] | 12, 9 | http |
| AC-16 | GET `select/document-archive/archive/users?excepts[]=<B>` → user aktif ber-`List Archive`, tanpa superadmin 1/2, customer, B; `search` menyaring username | [BE] | 14 | http |
| AC-17 (rev) | A di drawer P tab Izin: nyalakan toggle (baris A otomatis), + Add User B, centang Update B (View ikut), Save → sukses; buka ulang = data tersimpan; lepas View melepas semua | [FE] | 3, 7, 15, 19 | e2e (A) |
| AC-18 (rev) | [K-15 b] D (tanpa `Update Folder`) buka drawer folder ber-View: Detail read-only, tab Izin bisa diubah, Save ada; ubah baris + Save → body hanya `isFolderPermission`/`folderPermissions`, 200; buka ulang = tersimpan | [FE] | 7, 15 | e2e (D) |
| AC-19 (rev) | B tanpa View di P klik P → layar 06 (nama folder penolak, tanpa toast, toolbar & tabel tersembunyi); tombol (id-ID "Kembali ke Arsip") → root | [FE] | 10, 11 | e2e (B) + `manual (Gate 2)` |
| AC-20 (rev) | Hak B di P terbatas: menu ⋯ baris P & "+" di dalam folder mengikuti BR-8/9; P ber-Izin tampil ikon perisai bertooltip "Restricted Access"/"Akses Terbatas" | [FE] | 8, 9, 10 | unit + e2e |
| AC-21 | Modal Pindahkan: folder tujuan tanpa Store / tanpa View tidak bisa dipilih / dibuka | [FE] | 9 | e2e (B) |
| AC-22 | PUT permission P oleh A → GET `history/{P}` memuat aksi `permission` + username A | [BE] | 16 | http |
| AC-23 (rev) | Tanpa lisensi: menu Archive tidak tampil (`menus.js:169`); endpoint 4xx bukan 500 (EPIC K-2 b: tanpa `List Archive` → GET `archives?id_archive=P` 403 `GE0114`; tanpa `Update Folder` dan `Folder Permissions` → PUT `archives/{P}` 403 `GE0114`, data tetap); seed hanya di `permission_salesman.sql` (AC-25); guard FE dicek di kode; tukar lisensi manual di Gate 2 epic | [BE+FE] | 18 | http + grep |
| AC-24 | [G-3] B Delete di P, subfolder C (On) tanpa Delete B: DELETE P → 403 `ARCHIVE418`, P & C tetap aktif; B diberi Delete di C → 200; superadmin → 200 | [BE] | 9 | http + DB |
| AC-25 (rev) | [EPIC K-4 a] `permission_salesman.sql`: `Folder Permissions` 1140 (module 1266, label en/id BR-18) & `Opname Document` 1139, tanpa id 1133-1138, hanya di berkas itu; checks/unchecks §3; jalan di DB scratch tanpa error; sesudah seed ulang QA_DB punya keduanya, role 1/2/3 memilikinya; FE tanpa id permission | [BE] | 18 | DB + grep (pola ED-1026 AC-2) |
| AC-26 (rev) | A: PUT, rename, create-folder di, put-in dokumen ke, DELETE (kosong) JA → 200; GET `archives?id_archive=J` → baris JA `access` semua `true`; A rename/DELETE J → 403 `ARCHIVE408`/`409` parameter J; create-folder di J → 403 `ARCHIVE410` | [BE] | 4, 9 | http |
| AC-27 (rev) | Baris A di J dihapus (JA tetap V/U/D/S): GET `archives?id_archive=JA` → 403 `ARCHIVE407` `denied_by`=J; PUT/rename JA → 403 `ARCHIVE408` parameter J; search JA → `access` semua `false`. JB: A GET → 403 `denied_by`=JB | [BE] | 4 | http |
| AC-28 (rev) | [K-12 a] JC Off, A View saja di J: PUT detail, rename, create-folder & put-in dokumen ke, DELETE (kosong) JC → 200; baris JC di `archives?id_archive=J` `access` semua `true`; JC berisi dokumen → DELETE 400 `ARCHIVE401`. Baris A di J dihapus → JC: GET 403 `ARCHIVE407` `denied_by`=J, rename 403 `ARCHIVE408` parameter J. K (On, B View+Delete) dengan satu anak Off, kosong: B DELETE K → 200 | [BE] | 1, 4, 9 | http + DB |
| AC-29 (rev) | C (Update efektif di F): GET `archives/{F}` → `manage_permission`=false, `folder_permissions`=[]; PUT dengan field izin → 403 `GE0114` (`parameter` "Folder Permissions"), DB tetap; tanpa field itu → 200. A → `manage_permission`=true, baris tampil | [BE] | 7, 18 | http + DB |
| AC-30 (rev) | C: drawer tanpa tab Izin; Detail bisa diubah bila Update efektif; body Save tanpa field izin. [K-15 b] A di drawer J (View saja): Detail read-only, tab Izin bisa diubah, body Save tanpa field detail, 200 | [FE] | 7, 15 | e2e (A, C) |
| AC-31 (rev) | F Off tanpa baris A: A nyalakan toggle → baris A dengan keempat hak tercentang; Save → `archive_permissions` (F, A) = 1/1/1/1. F Off dengan baris A View saja → dinyalakan → naik ke keempat hak | [FE] | 19 | e2e (A) + DB; unit `grantOwnFolderPermission` |
| AC-32 (rev) | Centang All baris B → keempat hak; lepas All → keempatnya lepas; sebagian → All indeterminate; lepas satu hak → All lepas; kolom All tepat sesudah Pengguna | [FE] | 20 | e2e (A) + unit |
| AC-33 (rev) | [K-16 b] A (bukan superadmin/pembuat) di F On: hapus baris A atau lepas View A, Save → modal; **Tidak** → tanpa PUT `archives/{F}` (monitor jaringan), drawer tetap terbuka dengan perubahannya; **Ya** → PUT 200. Lepas U/D/S A saja, superadmin, pembuat, toggle Off → tanpa modal | [FE] | 21 | e2e (A, superadmin) + unit `willLoseOwnFolderAccess` |
| AC-34 (rev) | Bahasa en lalu id: tooltip perisai "Restricted Access"/"Akses Terbatas", tombol layar 06 "Back to Archive"/"Kembali ke Arsip", teks bantuan & peringatan BR-22 | [FE] | 10, 11, 22 | e2e + `manual (Gate 2)` |
| AC-35 (rev) | Data AC-26, A buka J: menu ⋯ JA dan JC memuat Pindahkan & Hapus; drawer JA dan JC (K-12 a) Detail bisa diubah, Save sukses; "+" di dalam JA dan JC memuat Add Folder & Store Document, di dalam J tidak | [FE] | 4, 9 | e2e (A) |
| AC-36 (rev) | Regresi (gate1-r2 §5): QA BE ED-1024 dijalankan ulang, ekspektasi usang karena item ini (FB-9, K-12 a, K-15 b, 1139/1140) diperbarui di folder ED-1024 oleh QA ini; skenario & e2e sendiri diperbarui (AC-1, e2e `:113,198`). QA BE ED-1026..ED-1029 + e2e ED-1029 = regresi: yang usang hanya **dicatat** di qa-be.md/qa-fe.md. Karakterisasi bug terbuka tetap, tidak dibalik: X-8 bagian search, X-13, X-12 (+ probe D-1 ED-1029); X-8 induk = diri sendiri milik QA ED-1069. Tanpa 500 baru | [BE+FE] | 4, 7 | http + e2e |
| AC-37 (rev) | [K-13 a] A Update di JA, View saja di J, Store di X: put-in JA ke X / ke root, PUT `archives/{JA}` `id_archive_parent`=X → 200 (induk asal tidak dicek); tujuan tanpa Store → 403 `ARCHIVE410`; JB put-in ke X oleh A → 403 `ARCHIVE408` parameter JB; [K-12 a] JC put-in ke X oleh A → 200 | [BE] | 9 | http |
| AC-38 (rev) | [K-15 b] D: PUT `archives/{F}` kelompok izin saja → 200, baris diganti, riwayat hanya `permission`; + kelompok detail → 403 `GE0114` (`parameter` "Update Folder"), DB tetap. A: kelompok izin saja ke JB (tanpa View) dan J → 200 (GET `archives/{JB}` tetap 403 `ARCHIVE407`); kelompok detail ke J → 403 `ARCHIVE408` parameter J. Folder di luar scope lokasi → 403 `ARCHIVE407`; tanpa kedua kelompok → 422 | [BE] | 7, 9, 15 | http + DB |
| AC-39 (rev3) | [FB2-1] Folder fixture F On, A V/U/D/S, B View saja. A di drawer F tab Izin **hanya** centang Semua B, Save → PUT `archives/{F}` terkirim (monitor jaringan) 200 `ARCHIVE207`, tanpa pesan "No changes to save"/"Tidak ada perubahan untuk disimpan", DB (F, B) = 1/1/1/1, buka ulang = Semua B tercentang. **Hanya** lepas Semua B, Save → PUT 200, baris (F, B) hilang. Unit: Semua saja + Save → PUT, tanpa `message.info`; lepas Semua baris sendiri (A bukan pembuat/superadmin) → modal BR-21, Tidak = tanpa PUT | [FE] | 16, 20, 21, 23 | e2e (A) + DB + unit |
| AC-40 (rev3) | [FB2-5] A buka Pindahkan (⋯ dokumen) dan pindah massal (Konfirmasi + Pilih Folder): folder Izin On di picker (root, dalam folder wadah, hasil pencarian; termasuk tanpa View/Store) berikon perisai, hover → "Restricted Access" (en) / "Akses Terbatas" (id); folder Off & dokumen tanpa ikon; klik ikon = klik nama; AC-21 tetap; tanpa error konsol & tanpa request tambahan. Unit `ArchiveTable`/`ArchiveMoveModal`: `isFolderPermission` 1 → ikon + tooltip, 0/dokumen → tanpa; list tetap berikon (AC-20) | [FE] | 10, 24 | e2e (A) + unit |
| AC-41 (rev3) | [P6] e2e `expectedPlus` (`qa/e2e/ED-1025-folder-permission.spec.js:124`): `set-permission` = `Folder Permissions` (`getArchiveAddKeys`), bukan `Update Folder`; di semua pemanggilnya (AC-3 A/C, AC-35): A → "+" memuat Set Permission by User/Atur Izin per User, C (ber-`Update Folder`, tanpa `Folder Permissions`) → tidak. Seluruh e2e ED-1025 (1 worker) + unit Archive lulus; oracle usang lain milik item ini diperbarui di folder ini saja; X-12 tidak disentuh (ED-1071). Tanpa 500 baru | [FE] | 15 | e2e (A, C) + unit |
| AC-42 (rev3) | [Gate 1 r3 #1] F On tanpa baris A: A "+ Add User" dirinya (View), toggle Off lalu On → tetap **satu** baris A, naik ke V/U/D/S; Save → body memuat A sekali, 200, DB satu baris (F, A) 1/1/1/1; "+ Add User" tidak menawarkan A. Unit: `grantOwnFolderPermission` dengan baris A beda huruf/spasi akhir → dinaikkan, tidak ditambah; `onChange` "+ Add User" dengan user yang sudah ada → jumlah baris tetap. BE: AC-5 (`id_user` ganda 422) tetap | [FE] | 16, 19, 25 | e2e (A) + DB + unit |

## 7. Subtask (rev3)
| # | Judul | Layer | AC | Module |
|---|---|---|---|---|
| 6 (rev3, ED-1131) | [FE] Archive - tab Izin: kolom Semua tercatat sebagai perubahan, satu baris per user saat Izin dinyalakan | FE | 39, 42 | Archive |
| 7 (rev3, ED-1132) | [FE] Archive - ikon perisai Akses Terbatas di picker Pindahkan dan pindah massal | FE | 40 | Archive |
| 8 (rev3, ED-1133) | [QA] Skenario revisi ronde 3: simpan lewat kolom Semua, baris tunggal per user, perisai di picker, oracle "+" e2e | QA | 39-42 | Archive |

Subtask lama ED-1031..ED-1066 dan (rev) ED-1083..ED-1087 selesai. Subtask rev3 (6-8) dibuat orkestrator.

## 8. Keputusan untuk developer
### Revisi ronde 3 (dijawab Gate 1 2026-10-12, `epics/ED-1022-gate1.md` §9)
| # | Pertanyaan | Opsi (akibat) | Jawaban |
|---|---|---|---|
| K-17 | Luas QA rev3 (diff hanya FE Archive) | a) unit Archive + seluruh e2e ED-1025, tanpa suite BE; b) a + suite BE ED-1025 (+20-30 menit; X-12 **sudah FAIL di HEAD** sejak ED-1069 B-3 - rename 200 `?` → 500 - dicatat milik ED-1071, gate1 C-2); c) b + e2e ED-1024, ED-1029 (oracle 1029 usang). Rek. a, rendah | **a** |
| G1 #1 | ED-1029 K-9 (hak otomatis by User) | a + syarat developer: hak otomatis tidak pernah membuat baris ganda untuk user yang sama, juga di tab Izin | **a + syarat** → BR-25, AC-42 (FE; BE sudah menolak) |
| EPIC K-5 | Tulis flag Izin dari halaman by User | a tulis sendiri di 1029; b ekstrak dari `ArchiveService::update` | **b** - lihat § Di luar cakupan |

### Sudah dijawab
Gate 1 ronde 2 (`epics/ED-1022-gate1-r2.md` §9b): K-12 a · K-13 a · K-14 a · K-15 b · K-16 b · EPIC K-4 a (isi: BR-1, 4, 7, 9, 18, 19, 21).
Gate 1 2026-10-06: K-1 a (→ K-12 a) · K-2 a · K-3 a · K-4 a (→ K-15 b) · K-5 a · K-6 a · K-7 · K-8 a · K-9 a · K-10 ya ×4 · K-11 gugur · EPIC
K-2 b (AC-23).

## 9. Di luar cakupan
- Halaman **Set Permission by User** (+ rev3 kolom Semua & saklar Izin FB2-2/3) → **ED-1029**; memakai `getFolderPermissionAllState`/
  `onChangeAllFolderPermission` apa adanya (O-1); perisai bersama milik item ini (O-2).
- **EPIC K-5 b → ED-1029:** tulis flag Izin di `ArchiveService::update` (`:415-424`) diekstrak ke satu method `ArchivePermissionService`
  (dipakai juga `UserPermissionService::update`); hak otomatis K-9 tidak di sana (O-3). ED-1029 mengulang QA BE ED-1025 `--only` AC-5, AC-38.
- **(rev3)** Deteksi "tidak ada perubahan" generik `DrawerUpdate`/`useUpdate` (semua module) tidak diubah (P-7).
- `Opname Document` → ED-1026/1027/1028; scope lokasi → ED-1024; pindah ke turunan/nama kembar/induk diri sendiri → ED-1069; ⋯ dokumen →
  ED-1072; list v5 salah bentuk → ditunda (ED-1070; X-8 search, X-13 tetap); kolasi latin1 → **ED-1071** (P1, sesudah item ini; ubah X-12).
- Pewarisan baris ke subfolder baru; centang semua baris di header All; `POST archives/add-document`.

## Catatan implementasi (kode sekarang)
- (BE) `ArchivePermissionService::context()` sekali per request, diteruskan, tanpa static (D-3); `can_manage` = `MyHelper::hasPermission`
  (tanpa bypass customer). GET `archives` 403 hak folder = `Message::formatResponse`, lain `ErrorMessageException`. Hapus: 404/`407` → `409`
  → `418` → 400 `401`. Kelompok izin tanpa perubahan = 200 tanpa tulisan; PUT izin ke id dokumen menulis flag (pra-ada, QA O-1).
