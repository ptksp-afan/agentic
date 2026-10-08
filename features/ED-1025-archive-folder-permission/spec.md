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

## Revisi review 2026-10-08 (+ jawaban Gate 1 ronde 2)
Sumber: `epics/ED-1022-review-feedback.md` § ED-1025; jawaban Gate 1: `epics/ED-1022-gate1.md` §9b. Kode sekarang = acuan (BE
9fec7c427..44bea5136, FE 89b3b98a6..7fa63869d). BR/AC tanpa "(rev)" sudah terimplementasi dan tetap berlaku.

| Kode | Perubahan | BR / AC | Layer |
|---|---|---|---|
| FB-4a + K-15 b | Permission baru `Folder Permissions` (id 1140). Tab Izin tampil **dan bisa diubah** hanya dengannya, tanpa syarat Update; PUT memisah kelompok detail (Update) dan izin (`Folder Permissions`) | BR-7, 9, 15, 18, 22; AC-4, 12, 18, 23, 25, 29, 30, 38 | BE+FE |
| FB-5 + K-14 a | Yang menyalakan Izin Folder otomatis punya baris V/U/D/S (FE, sebelum Save) | BR-16, 19; AC-17, 31 | FE |
| FB-6 | Kolom **All** sesudah Pengguna | BR-20; AC-32 | FE |
| FB-7 | Tooltip perisai "Restricted Access" / "Akses Terbatas" | BR-10; AC-20, 34 | FE |
| FB-9 + K-12 a, K-13 a | Induk superior hanya untuk View; folder Off tidak dibatasi; pindah folder = Update di folder + Store tujuan | BR-1, 4, 6, 9, 22; AC-9, 11, 26-28, 35, 37 | BE (+teks FE) |
| D-2 + K-16 b | Simpan yang mencabut **View** sendiri → modal, hanya di tab Izin | BR-21; AC-33 | FE |
| N-3 | id-ID `archive.Back to Archive` = "Kembali ke Arsip" | BR-11; AC-19, 34 | FE |
| EPIC K-4 a | `Opname Document` 1133 → **1139**, `Folder Permissions` **1140** di `permission_salesman.sql` (oleh item ini); QA seed ulang + grant ulang role uji | BR-18; AC-25 | BE+QA |
| QA usang | AC-1 (`qa/scenario1-schema-read.php:95`); e2e jumlah tab & item "+" (`qa/e2e/ED-1025-folder-permission.spec.js:113,198`); gate1 §5 | AC-1, 3, 36 | QA |

Tetap: `ARCHIVE407` dua bentuk body; `_module.md`; Detail read-only penuh tanpa Update. Imbas: ED-1029 (`Folder Permissions`, BR-4), ED-1026 (id
1139), ED-1026..1028 hanya View → regresi.

## 1. Ringkasan
Folder Archive bisa dibatasi per user lewat tab **Izin** di drawer folder: toggle *Aktifkan Izin Folder* (default mati) dan tabel Pengguna ×
All/View/Update/Delete/Store; superadmin selalu lolos. **(rev)** Hanya View yang mengikuti folder induk; Update/Delete/Store dinilai di folder
itu sendiri dan folder Izin nonaktif tidak membatasinya. Tanpa View user melihat layar "Anda tidak punya akses ke folder ini"; dokumennya tetap
bisa dicari dan dipindahkan. **(rev)** Tab Izin hanya dan selalu bisa diubah oleh role ber-*Folder Permissions*; yang menyalakan Izin otomatis
mendapat semua hak; mencabut akses lihat sendiri meminta konfirmasi. Sumber: S2 (01, 02, 06), S4 hal.1-3; PK-1 b; review & Gate 1 2026-10-08.

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
| BR-19 (rev) | Menyalakan toggle (Off→On) langsung menambah baris user login dengan V/U/D/S tercentang, atau menaikkan barisnya; tampil sebelum Save, masih bisa diubah (lepas View sendiri → BR-21). Juga superadmin & pembuat (gate1 C-6) | FB-5; K-14 a |
| BR-20 (rev) | Kolom **All** ("Semua") sesudah Pengguna: tercentang bila keempat hak, indeterminate bila sebagian; centang = keempatnya, lepas = keempatnya lepas (baris tetap; tetap kosong saat Save = tidak disimpan). Tanpa centang-semua-baris di header | FB-6 |
| BR-21 (rev) | Save drawer saat sesudah simpan user login (bukan superadmin 1/2, bukan pembuat) di folder Izin On tidak lagi punya **View** di barisnya (baris dihapus atau View dilepas) → modal konfirmasi; **Ya** = simpan; **Tidak** = tanpa request API, drawer tetap terbuka dengan perubahannya. Lepas U/D/S saja = tanpa modal. Hanya di tab Izin (ED-1029 tanpa modal) | D-2; K-16 b; ED-1029 K-8 c |
| BR-22 (rev) | Teks tab Izin: bantuan toggle "Default nonaktif. Saat aktif, akses folder ini dibatasi sesuai izin di bawah. Bisa diubah oleh user dengan izin Perizinan Folder."; peringatan "Hanya izin Lihat di folder induk yang berlaku: user tanpa izin Lihat di folder induk tetap tidak bisa membuka folder ini walaupun dicentang di sini. Ubah, Hapus, dan Letakkan dinilai di folder ini. Superadmin EQUAL selalu bypass." (en setara; tanpa kalimat folder nonaktif, K-12 a) | FB-4a; FB-9; K-12 a; K-15 b |

## 3. Model data (DB tenant)
| Perubahan | Detail |
|---|---|
| Skema (ada, ED-1031) | `archives.is_folder_permission`, tabel `archive_permissions` (`UNIQUE (id_archive,id_user)`, `KEY id_user`), Updater `2026_10_07_04_25_27_AddArchiveFolderPermission.php`, model `ArchivePermission`. **(rev) Tanpa perubahan skema → tanpa Updater baru** |
| Permission seed (rev) | `app/Sql/data/permission_salesman.sql` (EPIC K-4 a). `Opname Document` 1133 → **1139** di `:82` (INSERT), `:138` `(1139,1082)`, `:147` `(1081,1139)`, `:216` `(1139,1081)`, `:227` `(1081,1139),(1082,1139)`. Baru: `(1140,1266,'Folder Permissions','Perizinan Folder','Folder Permissions',11,1)`; `permission_checks` `(1081,1140)`, `(1140,1082)`, `(1140,1084)`; `permission_unchecks` `(1140,1081)`, `(1081,1140)`, `(1082,1140)`, `(1084,1140)` (tanpa pasangan dengan 1085, K-15 b). Id 1133-1138 tidak dipakai lagi di berkas (branch AI). Tenant lama: seed ulang (`ApplicationSetupService.php:223-238`, D-7); role 1/2/3 lewat `role_permissions.sql`, role lain manual |
| `ArchivePermissionService` (rev) | `deniedBy()` (`:114-133`): jalur root→F dinilai untuk View (tetap); untuk U/D/S hanya bila View lolos, lalu `grants(F, hak)` (`:398-416`; Off = true → K-12 a). `context()` + flag `can_manage` = user konteks memegang `Folder Permissions` (`MyHelper::hasPermission`, `MyHelper.php:521`; diisi sebelum keluar bypass `:57`); `access()` `manage_permission` = `can_manage` (`:100-101`, dulu = `update`). Tanda tangan tetap, pemakai tidak diubah (`assertCanManage` diubah ED-1029). Tanpa static/properti lintas request (D-3) |
| `UpdateRequest` + `ArchiveService::update` (rev) | Kelompok detail & izin dinilai terpisah (K-15 b, contract §3): detail → `Update Folder` (`GE0114`) + `ARCHIVE408`/`410`, aturan wajib lama; izin → `Folder Permissions` (`GE0114`); tanpa keduanya → 422; riwayat `update` hanya dengan detail. Di `authorize()` (`UpdateRequest.php:70-82`) dan service (`ArchiveService.php:278-319`) |
| Kode pesan | tidak ada yang baru: `GE0114` (`parameter` = nama permission, seperti `PermissionVersion5`) dipakai ulang; `ARCHIVE401`, `407`-`411`, `418` tetap |

## 4. Endpoint v5 (`api/v5`, `auth:api`; `Routes/DocumentArchive/api.php:22-34`)
| Route | Controller@method | FormRequest | Service | Hak folder / permission | Status |
|---|---|---|---|---|---|
| GET `document-archive/archives` | `ArchiveController@index` | - | `ArchiveService::index` | buka folder = View → 403 `ARCHIVE407` + `denied_by`; `access` per baris **(rev) menurut BR-4, `manage_permission` = BR-7** | ubah (rev) |
| GET `document-archive/archives/{id}` | `@show` | - | `ArchiveService::show` | View → 403 `ARCHIVE407`; **(rev)** tanpa `Folder Permissions`: `folder_permissions`=[], `manage_permission` false | ubah (rev) |
| PUT `document-archive/archives/{id}` | `@update` | `UpdateRequest::authorize()` | `ArchiveService::update` | **(rev)** route `permission_v5:Update Folder\|Folder Permissions` (`api.php:27`, dulu `Update Folder`); kelompok detail: `Update Folder` (`GE0114`) → `ARCHIVE408` → induk baru Store `ARCHIVE410`; kelompok izin: `Folder Permissions` (`GE0114`), tanpa cek hak folder | ubah (rev) |
| PUT `archives/rename/{id}`, DELETE `archives/delete/{id}`, POST `archives/create-folder`, POST `documents/put-in`, GET `archives/history/{id}` | tetap | tetap | tetap | `ARCHIVE401`, `407`-`410`, `418` (contract §4), **(rev)** menurut BR-4 (K-12 a, K-13 a) | ubah (rev, aturan) |
| POST `documents/hand-over`, `receive`; GET `select/.../archive/users` | tetap | - | tetap | tidak dicek | sudah ada |

## 5. Perubahan FE per module - `src/containers/Archive` (master, aksi baris kustom `archiveActionColumn`)
| Bagian | Perubahan |
|---|---|
| `ArchiveDrawer` (rev) | `canManagePermission` = `usePermission(permissions.FolderPermissions)` && `hasFolderRight(access, 'managePermission')` (`ArchiveDrawer/index.js:38`, dulu `canUpdate` && ...); tab Izin (`:72-78`) hanya bila true, tidak read-only; Save (`DrawerUpdate canUpdate`, `:46`) = `canUpdateFolder \|\| canManagePermission`; `disabled` `Form` (`ArchiveForm/index.js:136`) pindah ke isi tab Detail agar tab Izin tidak ikut terkunci |
| `toArchiveRequest` (rev) | `archive.function.js:160-182` menerima `{ canUpdateFolder, canManagePermission }`: tanpa `canUpdateFolder` kelompok detail tidak dikirim; tanpa `canManagePermission` kelompok izin tidak dikirim (tetap) |
| `ArchiveFolderPermission` (rev) | kolom All (BR-20) sesudah kolom User (`ArchiveFolderPermission/index.js:66-70`, judul `common.All`); `Switch` On → fungsi baru `grantOwnFolderPermission(rows, user)` di `archive.function.js` (`useAppProvider().app.user`: `idUser`, `username`); teks BR-22 |
| Simpan (rev) | `useArchiveController` (`archive.function.js:22-30`): fungsi murni baru `willLoseOwnFolderAccess(values, { user, createdBy })` = folder On sesudah simpan, user login bukan superadmin (`findLowestSuperadminLevel`, `helpers/authHelper.js:274`)/pembuat (`createdBy`, tanpa beda huruf), barisnya tanpa View (K-16 b); true → `modal.confirm` (pola `ArchivePermissionPage/index.js:102-108`), `onOk` = `onFinish`; batal = tanpa request. Hanya drawer; ekspor per baris untuk ED-1029 tidak perlu (O-10 gugur, ED-1029 K-8 c) |
| `ArchivePage` | **(rev)** tooltip perisai `archive.Restricted Access` (`ArchivePage/index.js:359-366`) |
| Menu baris, "+", picker | mengikuti `access` BE (`archive.function.js:227-241`, `:260-284`); **(rev)** tanpa ubah kode, hasil menurut BR-4 (AC-35). Item "+" Set Permission by User → ED-1029 |
| Registrasi (rev) | `routes/permissions.js` (dekat `:332`) `FolderPermissions: 'Folder Permissions'` (nama, bukan id; dipakai ED-1029); `archive.locale.js` en+id: `archive.Back to Archive` id "Kembali ke Arsip" (`:202`), `archive.Restricted Access`, isi modal (id "Sesudah disimpan, Anda tidak lagi bisa melihat folder ini. Lanjutkan?"), teks BR-22 (`:50-56`, `:189-195`) |

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
| AC-36 (rev) | Regresi (gate1 §5): QA BE ED-1024 dijalankan ulang, ekspektasi usang karena item ini (FB-9, K-12 a, K-15 b, 1139/1140) diperbarui di folder ED-1024 oleh QA ini; skenario & e2e sendiri diperbarui (AC-1, e2e `:113,198`). QA BE ED-1026..ED-1029 + e2e ED-1029 = regresi: yang usang hanya **dicatat** di qa-be.md/qa-fe.md. Karakterisasi bug terbuka tetap, tidak dibalik: X-8 bagian search, X-13, X-12 (+ probe D-1 ED-1029); X-8 induk = diri sendiri milik QA ED-1069. Tanpa 500 baru | [BE+FE] | 4, 7 | http + e2e |
| AC-37 (rev) | [K-13 a] A Update di JA, View saja di J, Store di X: put-in JA ke X / ke root, PUT `archives/{JA}` `id_archive_parent`=X → 200 (induk asal tidak dicek); tujuan tanpa Store → 403 `ARCHIVE410`; JB put-in ke X oleh A → 403 `ARCHIVE408` parameter JB; [K-12 a] JC put-in ke X oleh A → 200 | [BE] | 9 | http |
| AC-38 (rev) | [K-15 b] D: PUT `archives/{F}` kelompok izin saja → 200, baris diganti, riwayat hanya `permission`; + kelompok detail → 403 `GE0114` (`parameter` "Update Folder"), DB tetap. A: kelompok izin saja ke JB (tanpa View) dan J → 200 (GET `archives/{JB}` tetap 403 `ARCHIVE407`); kelompok detail ke J → 403 `ARCHIVE408` parameter J. Folder di luar scope lokasi → 403 `ARCHIVE407`; tanpa kedua kelompok → 422 | [BE] | 7, 9, 15 | http + DB |

## 7. Subtask (rev) - hanya pekerjaan perbaikan; subtask lama ED-1031..ED-1066 tetap
| # | Judul | Layer | AC | Module |
|---|---|---|---|---|
| 1 (rev) | [BE] Hak folder efektif: induk hanya dominan untuk View, folder Izin nonaktif tidak dibatasi | BE | 9, 11, 26, 27, 28, 37 | DocumentArchive |
| 2 (rev) | [BE] Permission Folder Permissions: seed id 1140 & Opname Document 1139, manage_permission, kelompok detail/izin di ubah folder | BE | 4, 12, 23, 25, 29, 38 | `app/Sql`, DocumentArchive |
| 3 (rev) | [FE] Archive - tab Izin: permission Perizinan Folder, kolom All, hak otomatis saat Izin Folder dinyalakan | FE | 3, 17, 18, 30, 31, 32 | Archive |
| 4 (rev) | [FE] Archive - konfirmasi cabut akses lihat sendiri, tooltip Akses Terbatas, teks aturan induk, Kembali ke Arsip | FE | 19, 20, 33, 34, 35 | Archive |
| 5 (rev) | [QA] Skenario revisi folder permission, perbarui skenario usang, regresi ED-1024/ED-1026..ED-1029 | QA | 1, 3, 36 + semua (rev) | Archive |

**Jira subtask (rev), dibuat 2026-10-08 (sprint 46):** ED-1083 [BE] hak folder efektif · ED-1084 [BE] permission Folder Permissions/seed · ED-1085 [FE] tab Izin · ED-1086 [FE] konfirmasi/tooltip/teks · ED-1087 [QA]

## 8. Keputusan untuk developer
### Revisi (Gate 1 ronde 2, dijawab 2026-10-08)
| # | Pertanyaan (opsi; rekomendasi; risiko) | Jawaban |
|---|---|---|
| K-12 | Folder Izin Off di bawah induk On: siapa boleh U/D/S (juga put-in, buat subfolder)? (a tidak dibatasi, cukup View efektif; b ikut folder On terdekat; rek. b; tinggi) | Jawaban Gate 1 (2026-10-08): a - folder Off tidak dibatasi induknya; siapa pun ber-View (dalam scope lokasi) boleh U/D/S, hapus tetap harus kosong; hanya View diwarisi (BR-1, 4, 9; AC-28, 35, 37) |
| K-13 | Pindah folder F (put-in, ganti induk, ke root)? (a Update di F + Store tujuan; b a + Update di induk asal; rek. a; tinggi) | Jawaban Gate 1 (2026-10-08): a - induk asal tidak dicek (BR-9; AC-37) |
| K-14 | Mekanisme hak otomatis FB-5? (a FE tambah/naikkan baris user login; b BE paksa saat 0→1; c a tanpa menaikkan; rek. a; rendah) | Jawaban Gate 1 (2026-10-08): a - FE, terlihat & bisa diubah sebelum Simpan; juga superadmin/pembuat (BR-19; AC-31) |
| K-15 | Siapa membuka & mengubah tab Izin? (a `Folder Permissions` + `Update Folder` + Update efektif; b `Folder Permissions` saja, route PUT `Update Folder\|Folder Permissions`, field dipisah; rek. a; tinggi) | Jawaban Gate 1 (2026-10-08): b - `Folder Permissions` saja; semua folder dalam scope lokasi, termasuk tanpa View (lewat ED-1029) (BR-7, 15, 18; AC-4, 18, 29, 30, 38) |
| K-16 | Pemicu modal cabut akses sendiri (D-2)? (a kehilangan View atau Update; b hanya View; rek. a; rendah) | Jawaban Gate 1 (2026-10-08): b - hanya View, hanya di tab Izin; ED-1029 tanpa modal (K-8 c) (BR-21; AC-33) |
| EPIC K-4 | Id permission bentrok branch AI 1133-1138? (a Archive mengalah 1139/1140; b tetap; c rentang per branch; rek. a; tinggi) | Jawaban Gate 1 (2026-10-08): a - `Opname Document` 1139, `Folder Permissions` 1140; berkas seed diubah item ini (§3; AC-25) |

### Gate 1 2026-10-06 (sudah dijawab)
K-1 a (semua hak dominan dari induk) **(rev) digantikan FB-9/K-12 a: hanya View** · K-2 a (superadmin 1 dan 2) · K-3 a (pembuat: semua hak;
(rev) View tunduk induk) · K-4 a (kelola = Update efektif) **(rev) digantikan FB-4a + K-15 b** · K-5 a (hak folder + permission module; (rev)
kelompok izin: BR-7) · K-6 a (tampil + perisai, layar 06) · K-7 (pemetaan aksi; subfolder tanpa Delete → `ARCHIVE418`) · K-8 a (aksi dokumen
tidak dibatasi) · K-9 a ("+ Add User") · K-10 ya ×4 · K-11 gugur (ED-1024) · EPIC K-2 b (AC-23).

## 9. Di luar cakupan
- Halaman **Set Permission by User**, item "+", `assertCanManage` = `Folder Permissions` (FB-4b), aturan sel & teks info (BR-4, K-12 a) →
  **ED-1029** (memakai permission, konstanta, `manage_permission`, `ArchivePermissionService` dari sini; tanpa modal, K-8 c).
- Penguncian dengan `Opname Document` (FB-8, EPIC K-3 a) → ED-1026/1027/1028 (id 1139 diubah di sini; AC ED-1026 menyesuaikan).
- Scope lokasi, `permission_v5` route Archive → ED-1024. Pindah ke turunan, rename nama kembar, induk = diri sendiri → ED-1069; klik ⋯ dokumen
  → ED-1072 (berkas sama: `UpdateRequest`, `PutInFolderRequest`, `RenameFolderRequest`, `ArchiveService::update`; urutan = rank).
- Ditunda di Gate 1 (task lain, bukan run ini; tidak ditunggu): parameter list v5 salah bentuk (`search`/`sorts`/`pagination` = 500) dan
  kolasi latin1. Karakterisasinya di QA item ini tetap (AC-36).
- Pewarisan baris permission ke subfolder baru; centang semua baris di header All; `POST archives/add-document` (ED-1023, dilewati).

## Catatan implementasi (kode sekarang)
- (BE) `ArchivePermissionService::context()` memuat sekali semua folder + baris user, diteruskan pemanggil (`$context`), tanpa static/properti.
  Tambahan: `assertFolders()`, `folderRows()`, `replaceFolderRows()`, `userRows()`/`lockFolders()` (ED-1029, D-4 tetap). Baris U/D/S tanpa View
  tidak memberi hak. Pembuat: `created_by` vs username tanpa beda huruf besar/kecil.
- (BE) GET `archives` 403 hak folder = `Message::formatResponse`; error lain = `ErrorMessageException` (developer: tetap). Put-in folder ke root
  butuh Update di folder itu (folder Off: cukup View, K-12 a). Hapus: 404/`ARCHIVE407` → `ARCHIVE409` → `ARCHIVE418` → 400 `ARCHIVE401`.
- (BE, fix ronde 1-2) tipe salah di request Archive = 422, bukan 500 (`hasScalarLocations()`; rule melewati nilai bukan skalar).
- (BE, rev ED-1083/ED-1084) `ArchivePermissionService::deniedBy()` menilai View di seluruh jalur lalu U/D/S hanya di folder itu;
  `context()['can_manage']` = `MyHelper::hasPermission('Folder Permissions')` (tanpa bypass customer seperti middleware); konstanta
  `ArchivePermissionService::FOLDER_PERMISSIONS` + `assertCanManagePermission($context)` (403 `GE0114`) siap dipakai ED-1029.
  PUT: kelompok = key dikirim (`ArchiveService::DETAIL_FIELDS`/`PERMISSION_FIELDS`, `sendsFields()`), dinilai di `assertCanUpdate()`
  (authorize + service). Kelompok izin saja tanpa perubahan = 200 tanpa tulisan. `UserPermissionService` (ED-1029) tidak diubah:
  `access.manage_permission` di halaman by User kini = `Folder Permissions`, route & `assertCanManage` masih `Update Folder`/Update efektif
  sampai revisi ED-1029.
- (FE) `ArchiveForm` meneruskan `disabled` (`isUsed` / tanpa Update = Detail read-only penuh); `access` null tidak membatasi di FE; pindah massal
  tidak menyaring folder tanpa Update (BE 403 `ARCHIVE408`).
