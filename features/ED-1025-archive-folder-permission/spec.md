---
key: ED-1025
epic: ED-1022
title: Archive - Folder Permission per folder (tab Permission & akses ditolak)
status: approved
batch: ED-1025
fe_modules: [Archive]
new_fe_modules: []
be_modules: [DocumentArchive, Select/DocumentArchive, UpdateVersion]
qa_model: sonnet
contract: contract.md
depends_on: [ED-1024]
---

## 1. Ringkasan
Setiap folder Archive bisa dibatasi aksesnya per user. Di drawer folder ada tab **Permission**: toggle
*Enable Folder Permission* (default mati) dan tabel User x View/Update/Delete/Store. Kalau toggle aktif,
hanya user yang dicentang yang boleh membuka isi folder (View), mengubah folder (Update), menghapusnya
(Delete), dan menyimpan dokumen ke dalamnya (Store). Permission folder induk lebih dominan daripada folder
turunannya, dan superadmin selalu lolos. User tanpa View yang membuka folder melihat layar "Anda tidak punya
akses ke folder ini" (layar 06), tetapi dokumennya tetap muncul di pencarian dan tetap bisa dipindahkan.
Hak folder menyempit **di atas** scope lokasi dari ED-1024, dan tabelnya dipakai ulang oleh
ED-1029 (Set Permission by User) serta jadi bagian "folder dalam scope user" untuk 03/04/05.

Sumber: brief S1 tidak menyebut folder permission; perilaku diambil dari desain S2 (02 tab Permission,
01, 06) dan CHANGES.pdf S4 hal.1-3; S2 menang bila beda (R-4). Masuk epic: PK-1 b.

## 2. Aturan bisnis
| # | Aturan | Sumber |
|---|---|---|
| BR-1 | Folder punya flag *Enable Folder Permission*, default nonaktif (folder baru & folder lama = nonaktif). Nonaktif = folder tidak membatasi siapa pun (selain scope lokasi 01 dan permission module) | [S2 02 tab Permission]; [S4 hal.1] |
| BR-2 | Hak per user per folder: **View** (melihat & membuka isi folder), **Update** (mengubah data folder), **Delete** (menghapus folder), **Store** (menyimpan/menambahkan dokumen ke folder) | [S4 hal.1 tabel Hak Akses]; [S2 02 kolom View/Update/Delete/Store] |
| BR-3 | Update/Delete/Store hanya berlaku bersama View: mencentang salah satunya ikut mencentang View; melepas View melepas semuanya; BE menolak baris Update/Delete/Store=1 dengan View=0 (400) | pola `permission_checks/unchecks` (BE `v5-be-conventions/references/decisions.md` § Permission); desain 02: semua baris ber-View |
| BR-4 | Hak efektif user U atas folder F untuk hak R: periksa setiap folder di jalur root→F (termasuk F) yang permission-nya aktif; U harus punya R di **setiap** folder itu (parent dominan). Folder nonaktif di jalur tidak membatasi. Detail untuk Update/Delete/Store: K-1 | [S4 hal.2 "hak akses pada folder induk lebih dominan"]; [S2 02 catatan "User tanpa view di Backup Arsip tetap tidak bisa membuka folder ini walaupun dicentang di sini"] |
| BR-5 | Superadmin selalu lolos semua hak folder (role `is_superadmin` 1 dan 2, sama dengan bypass lokasi item 01) | [S2 02 "Superadmin EQUAL selalu bypass"]; R-8; K-2 |
| BR-6 | Pembuat folder (`archives.created_by`) diperlakukan punya semua hak di folder buatannya, tetap tunduk ke folder induk | [S2 02 "bisa diubah oleh ... pembuat folder"]; K-3 |
| BR-7 | Tab Permission (toggle + tabel) boleh diubah oleh user dengan Update efektif di folder itu (termasuk superadmin & pembuat, BR-5/6); selain itu tab tampil read-only | [S2 02 "Bisa diubah oleh superadmin, pembuat folder, atau user dengan permission update"]; read-only mengikuti `ArchiveDrawer/index.js:44` (`disabled={!canUpdate}`); K-4 |
| BR-8 | Hak folder **dan** permission module 1266 sama-sama wajib: menu/aksi tampil hanya bila role punya permission module-nya (`Update Folder`, `Delete Folder`, `Add Document`, `Move Archive`, `Add Folder`, `View Folder`) **dan** hak folder efektif mengizinkan | `permission_salesman.sql:67-76`; `ArchivePage/index.js:142-145`; `ArchiveButtonAdd/index.js:8-11`; K-5 |
| BR-9 | Pemetaan aksi → hak folder: buka folder/lihat detail/Info folder = View di folder itu; ubah detail & rename = Update; hapus = Delete di folder itu dan di semua subfolder aktif turunannya (ada yang tanpa → 403 `ARCHIVE418`, G-3); Store Document & memindahkan apa pun **ke** folder X = Store di X; buat subfolder di X = Store di X; memindahkan **folder** F = Update di F + Store di tujuan; memindahkan **dokumen** keluar dari folder tanpa View = boleh (cukup Store di tujuan); keluarkan ke root = tanpa cek hak folder; mengganti `id_archive_parent` lewat ubah detail = Store di induk baru | [S4 hal.1]; [S2 06 "tetap bisa dipindahkan ke folder yang Anda punya aksesnya"]; sisanya K-7 |
| BR-10 | Folder yang permission-nya aktif tetap tampil di daftar folder induknya (dengan ikon perisai di samping nama), juga bagi user tanpa View; membukanya menampilkan layar 06 | [S2 01: ikon perisai pada "SMLSMG - BRANGKAS"]; [S2 06: breadcrumb "Archive › SMLSMG - BRANGKAS"]; K-6 |
| BR-11 | Layar 06: judul "Anda tidak punya akses ke folder ini", teks "Folder <nama> mengaktifkan folder permission dan akun Anda belum diberi permission view. Hubungi admin arsip atau superadmin bila memerlukan akses.", tombol **Back to Archive** (kembali ke root Archive), catatan "Dokumen di dalam folder ini tetap dapat muncul pada hasil pencarian dokumen, dan tetap bisa dipindahkan ke folder yang Anda punya aksesnya." Toolbar (search, Select, "+") dan tabel tidak tampil; breadcrumb tetap. <nama> = folder yang menolak (bisa folder induk, BR-4) | [S2 06]; [S4 hal.3 Gambar 4] |
| BR-12 | Pencarian (search/scan) tetap mengembalikan dokumen di folder tanpa View dan folder-folder dalam scope lokasi; tiap baris folder membawa hak efektifnya | [S2 06 catatan]; `ArchiveService.php:59-66` (search lintas folder) |
| BR-13 | Aksi dokumen (Hand Over, Receive, Info dokumen, related transaction) tidak dicek hak folder | K-8 |
| BR-14 | "+ Add User" memilih dari user aktif yang role-nya punya `List Archive`, tanpa role superadmin 1/2 dan tanpa user customer; user yang sudah ada di tabel tidak muncul lagi | pola `ApprovalService.php:648-665` + `SelectApprovalService.php:27-47` (`excepts`); K-9 |
| BR-15 | Simpan tab Detail + Permission dengan satu tombol **Save** drawer (satu request, atomik). Tab Verification (item 04) tidak ikut Save | [S2 02 "verifikasi read-only sehingga tidak ikut terkena tombol Save"]; `ArchiveDrawer/index.js:25-48` (satu `putService`) |
| BR-16 | Baris tabel Permission menggantikan seluruh isi folder itu saat disimpan; baris tanpa hak apa pun tidak disimpan; toggle nonaktif tidak menghapus baris (diabaikan sampai aktif lagi); user baru default View saja; perubahan permission dicatat di riwayat folder (aksi `permission`) | K-10 |
| BR-17 | Tanpa satu pun folder ber-permission aktif, semua perilaku Archive sama dengan sebelum fitur ini (regresi) | D-5 kompatibilitas; BR-1 |

## 3. Model data (DB tenant; v3 tidak memakai tabel `archives` - grep `Modules/*` selain V5 = 0)
| Perubahan | Detail |
|---|---|
| `archives` + kolom | `is_folder_permission TINYINT(2) DEFAULT 0` sesudah `is_all_location` (gaya `is_all_location`, `SalesmanActivitySchema2025_10_16_13_10_32.php:607`) |
| Tabel baru `archive_permissions` | `id_archive_permission VARCHAR(30)` PK (`MyHelper::generateId`), `id_archive VARCHAR(30) NOT NULL`, `id_user VARCHAR(30) NOT NULL`, `is_view`, `is_update`, `is_delete`, `is_store` `TINYINT(2) DEFAULT 0`, `created_at DATETIME`, `created_by VARCHAR(30)`, `updated_at DATETIME`, `updated_by VARCHAR(30)`; `UNIQUE (id_archive, id_user)`, `KEY id_user`; `latin1` / `latin1_general_ci` seperti `archives`. Dibaca per folder (item ini) dan per user (item 06) |
| Updater | 1 berkas `Modules/UpdateVersion/Updaters/<Y_m_d_H_i_s>_AddArchiveFolderPermission.php` + daftar di `config.php`; `CREATE TABLE IF NOT EXISTS` / `ADD IF NOT EXISTS`; jalan di semua tenant seperti `2025_11_04_10_02_59_RelatedEmployeeArchive.php` |
| Model | baru `Entities/Models/ArchivePermission.php`; `Archive`: `is_folder_permission` di `$fillable` (`Archive.php:18-33`), relasi `archivePermissions()` |
| Service hak (dipakai ulang 03-06) | baru `Http/Services/DocumentArchive/ArchivePermissionService.php`: `access($idArchive)` → `{view, update, delete, store, manage_permission}`, `deniedBy($idArchive, $right)`, `assert($idArchive, $right, $code)` (403), `viewableFolderIds()` = folder dalam scope lokasi 01 ∩ View efektif (dipakai di samping scope lokasi 01, bukan di dalamnya), `saveUserRow($idArchive, $idUser, $flags)` = simpan/hapus satu baris (folder, user) dengan BR-3/BR-16, kembalikan apakah berubah (pemanggil mencatat riwayat `permission`; dipakai simpan tab Permission dan 06). Satu kali query semua folder aktif (`id_archive`, `id_archive_parent`, `is_folder_permission`, `created_by`) + baris user itu, hitung di memori. **Tanpa cache di static/properti yang hidup lintas request** (D-3: controller bisa dipakai ulang worker) |
| Permission seed | tidak ada yang baru (K-4 a) |
| Display setting | tidak berubah (ikon perisai di render kolom Name, bukan kolom baru) |
| Kode pesan (en_EN + id_ID) | baru: `ARCHIVE408` tanpa Update, `ARCHIVE409` tanpa Delete, `ARCHIVE410` tanpa Store, `ARCHIVE411` Update/Delete/Store tanpa View (nomor final gate1 §5). Pakai ulang milik 01: `ARCHIVE407` (403, teks generik; nama folder penolak di `result.denied_by`), `ARCHIVE418` (403, hapus folder yang subfolder aktifnya tanpa Delete efektif, G-3) |
| Label riwayat | aksi `permission` di `DocumentArchiveService::action()` (`DocumentArchiveService.php:31-79`): "Permission changed by …" / "Permission diubah oleh …" |

## 4. Endpoint v5 (prefix `api/v5`, group `auth:api`; `permission_v5` di route Archive mengikuti keputusan item 01)
| Route | Controller@method | FormRequest | Service | Hak folder | Status |
|---|---|---|---|---|---|
| GET `document-archive/archives` | `ArchiveController@index` | - | `ArchiveService::index` + `ArchivePermissionService` | buka folder (`id_archive`, tidak mencari) = View → 403 `ARCHIVE407` (formatResponse, `result.denied_by`); baris folder + folder aktif membawa `access`, `is_folder_permission` | ubah |
| GET `document-archive/archives/{id}` | `@show` | - | `ArchiveService::show` | View (folder) → 403 `ARCHIVE407`; tambah `is_folder_permission`, `folder_permissions[]`, `access` | ubah |
| PUT `document-archive/archives/{id}` | `@update` | `UpdateRequest` (+`authorize()`) | `ArchiveService::update` | Update → 403 `ARCHIVE408`; ganti induk = Store induk baru → `ARCHIVE410`; body opsional `is_folder_permission`, `folder_permissions[]` (ganti semua), `ARCHIVE411` | ubah |
| PUT `document-archive/archives/rename/{id}` | `@rename` | `RenameFolderRequest` | `ArchiveService::rename` | Update → `ARCHIVE408` | ubah |
| DELETE `document-archive/archives/delete/{id}` | `@delete` | baru `DeleteRequest` (pola `authorize()`) | `ArchiveService::delete` | Delete → `ARCHIVE409`; subfolder aktif turunan tanpa Delete efektif → `ARCHIVE418` (01, G-3) | ubah |
| GET `document-archive/archives/history/{id}` | `@history` | - | `ArchiveService::history` | View (folder) → `ARCHIVE407` | ubah |
| POST `document-archive/archives/create-folder` | `@createFolder` | `CreateFolderRequest` | `ArchiveService::createFolder` | Store di `id_archive_parent` → `ARCHIVE410` | ubah |
| POST `document-archive/documents/put-in` | `DocumentController@putInFolder` | `PutInFolderRequest` | `DocumentService::putInFolder` | Store di tujuan → `ARCHIVE410`; tiap folder yang dipindah: Update → `ARCHIVE408`; dokumen: tanpa cek sumber | ubah |
| POST `documents/hand-over`, `documents/receive` | `DocumentController` | - | `DocumentService::movement` | tidak dicek (BR-13) | sudah ada |
| GET `select/document-archive/archive/users` | `SelectArchiveController@user` | inline | `SelectArchiveService::user` | tanpa `permission_v5` (konvensi select) | baru |

Cek hak tulis di `authorize()` FormRequest (403 sebelum 422) dan diulang di service; cek baca di service
(BE `references/decisions.md` § Permission). Semua penolakan 403/400/404, tidak ada 500 (D-6).

## 5. Perubahan FE per module - `src/containers/Archive` (master, aksi baris kustom `archiveActionColumn`)
| Bagian | Perubahan |
|---|---|
| `ArchiveDrawer` | jadi bertab **Detail** / **Permission** (antd `Tabs`, contoh `JobCostingDetail/index.js:60`); satu `Form` lintas tab (tab tidak di-destroy) supaya Save mengirim keduanya (BR-15). Item 04 menambah tab Verification |
| Tab Permission (komponen baru `archive-components/ArchiveFolderPermission`) | kartu toggle "Enable Folder Permission" + teks bantuan desain; judul "Folder Permission" + tautan "+ Add User" (memunculkan `SelectUser` dengan `endpoints.getSelectArchiveUsers`, `excepts` = user di tabel; pola `ApprovalTransactionUser/index.js`); tabel User · View · Update · Delete · Store (checkbox) · hapus baris; auto-centang View / lepas semua (BR-3) lewat fungsi di `archive.function.js` (dipakai ulang 06); peringatan "Permission parent folder lebih dominan… Superadmin EQUAL selalu bypass."; read-only bila `!(canUpdate && access.managePermission)`; tabel read-only saat toggle mati (BR-16). Kirim `isFolderPermission` + `folderPermissions` hanya bila boleh mengelola |
| `ArchivePage` | `getArchives` memakai `showMessage` fungsi (pola `utils/request.js:174-176`) agar error `msgCode === 'ARCHIVE407'` tidak memunculkan toast; saat itu render komponen baru `archive-components/ArchiveAccessDenied` (BR-11) menggantikan toolbar + tabel; **Back to Archive** = `run({ idArchive: null })` + breadcrumb root. Ikon perisai di render Name bila `isFolderPermission` (`ArchivePage/index.js:313-349`) |
| Menu baris (`archive.function.js:64-159`) | folder: Info & Lihat butuh `access.view`; Pindahkan butuh `access.update`; Hapus butuh `access.delete`; tombol ⋯ disembunyikan bila tak ada item. Dokumen: tidak berubah |
| "+" (`ArchiveButtonAdd/index.js`) | di dalam folder: Add Folder & Store Document butuh `result.access.store` folder aktif; di root tidak berubah. Opname (03) & Set Permission by User (06) bukan item ini |
| Picker tujuan (`ArchiveTable`, `ArchiveMoveModalForm.js:17-21`, `ArchiveBulkMoveModalForm.js:99-112`) | folder tanpa `access.store` tidak bisa dipilih; folder tanpa `access.view` tidak bisa dibuka |
| Registrasi | `configuration/endpoints.js` grup Select: `getSelectArchiveUsers: 'select/document-archive/archive/users'` (dekat `:3542`); locale `archive.*` di `entries/en-US.js` + `id-ID.js`; permission & route tidak berubah (`routes.js:2087-2094`, `menus.js:169`) |

## 6. Acceptance criteria
Profil uji: lisensi QA_DB `api_sidomaju` (Salesman Activity aktif). Data uji dibuat sendiri: user A (role
non-superadmin ber-`List Archive`, `Update/Delete Folder`, `Add Folder`, `Add Document`, `Move Archive`, `View
Folder`), user B (sama), superadmin `is_superadmin` 1 dan 2; folder P (induk) dan C (anak) dalam scope lokasi.

| # | Given / When / Then | Tag | BR | Cek |
|---|---|---|---|---|
| AC-1 | Given Updater dijalankan (2x) When cek skema Then `archives.is_folder_permission` (default 0) dan `archive_permissions` (+unique `id_archive,id_user`) ada, tanpa error | [BE] | Model | http/DB: `SHOW COLUMNS` / `SHOW INDEX` di QA_DB |
| AC-2 | Given tidak ada folder ber-permission aktif When A list root, buka folder, buat subfolder, Store Document, pindah, rename, ubah, hapus Then hasil sama dengan sebelum fitur; tiap baris folder `access` semua `true`, `is_folder_permission`=0 | [BE] | 1, 17 | http: endpoint §4 |
| AC-3 | When A membuka Archive, masuk folder, membuka drawer folder (tab Detail & Permission), "+ Add User" (select dipanggil), Info, modal Pindahkan & pindah massal Then semua layar terbuka tanpa error konsol/toast | [FE] | 15 | e2e (A) |
| AC-4 | Given P aktif, B punya View+Store When GET `archives/{P}` Then `is_folder_permission`=1, `folder_permissions` berisi B (`username`, `is_view`…), `access` milik pemanggil | [BE] | 2, 7 | http: `GET archives/{id}` |
| AC-5 | When A (Update di P) PUT `archives/{P}` dengan `is_folder_permission`=1 dan 2 baris Then 200 `ARCHIVE207`; `archive_permissions` P = persis 2 baris (yang lama diganti); baris semua-0 tidak disimpan; `id_user` ganda → 422 | [BE] | 15, 16 | http + DB |
| AC-6 | When PUT baris `is_update`=1, `is_view`=0 Then 400 `ARCHIVE411`, data tidak berubah | [BE] | 3 | http |
| AC-7 | Given P aktif, B tanpa View When B GET `archives?id_archive=P` Then 403, `msg_code` `ARCHIVE407`, `result.denied_by.id_archive`=P; With View Then 200 | [BE] | 4, 11 | http: index |
| AC-8 | Given P aktif (B tanpa View), C aktif (B ber-View) When B buka C Then 403 `ARCHIVE407`, `denied_by`=P. Given P nonaktif Then B buka C = 200 | [BE] | 4 | http |
| AC-9 | Given P aktif, B View+Update+Store di P dan C aktif dengan B View saja When B PUT rename C / Store ke C Then 403 (`ARCHIVE408`/`ARCHIVE410`); [dengan K-1 a] B Store di C tanpa Store di P → 403 | [BE] | 4 | http |
| AC-10 | Given P aktif tanpa baris When superadmin is_superadmin=1 dan =2 buka/ubah/hapus/Store di P Then 200 | [BE] | 5 | http (2 user) |
| AC-11 | Given P aktif, pembuatnya A tanpa baris When A buka & ubah P Then 200; A tetap ditolak di C (anak aktif) bila tak punya hak di C [K-3 a] | [BE] | 6 | http |
| AC-12 | Given B View tanpa Update When PUT `archives/{P}` / rename Then 403 `ARCHIVE408`; ganti `id_archive_parent` ke folder tanpa Store → 403 `ARCHIVE410` | [BE] | 7, 9 | http |
| AC-13 | Given B tanpa Delete When DELETE `delete/{P}` Then 403 `ARCHIVE409`, `is_active` tetap | [BE] | 9 | http + DB |
| AC-14 | Given B tanpa Store di P When put-in dokumen ke P / create-folder di P Then 403 `ARCHIVE410`. Given dokumen D di P (B tanpa View) When B put-in D ke folder ber-Store Then 200; put-in folder tanpa Update → 403 `ARCHIVE408`; put-in ke root (tanpa tujuan) → 200 | [BE] | 9 | http |
| AC-15 | Given B tanpa View di P When B search nomor dokumen di P Then dokumen muncul; baris folder hasil search membawa `access.view`=false; GET `archives/{P}` dan `history/{P}` → 403 `ARCHIVE407` | [BE] | 12, 9 | http |
| AC-16 | When GET `select/document-archive/archive/users?excepts[]=<B>` Then hanya user aktif ber-`List Archive`, tanpa superadmin 1/2 & customer, tanpa B; `search` menyaring username | [BE] | 14 | http |
| AC-17 | Given A di drawer P tab Permission When nyalakan toggle, + Add User B, centang Update (View ikut), Save Then sukses; buka ulang menampilkan data tersimpan; lepas View melepas semua | [FE] | 3, 7, 15 | e2e (A) |
| AC-18 | Given B tanpa Update di P When buka drawer P Then tab Detail & Permission read-only, Save tidak bisa | [FE] | 7, 8 | e2e (B) |
| AC-19 | Given B tanpa View di P When klik P di list Then layar 06 (nama folder penolak, tanpa toast error, toolbar & tabel tersembunyi); Back to Archive → list root | [FE] | 10, 11 | e2e (B) + `manual (Gate 2)` tampilan |
| AC-20 | Given hak B di P terbatas When menu ⋯ baris P dan "+" di dalam folder Then item mengikuti BR-8/9 (tanpa View: tanpa Info/Lihat; tanpa Store: tanpa Add Folder/Store Document); P ber-permission menampilkan ikon perisai | [FE] | 8, 9, 10 | unit (`archive.function.js`) + e2e |
| AC-21 | Given modal Pindahkan When folder tujuan tanpa Store / tanpa View Then tidak bisa dipilih / dibuka | [FE] | 9 | e2e (B) |
| AC-22 | Given PUT permission P oleh A When GET `history/{P}` Then entri aksi `permission` + username A [K-10] | [BE] | 16 | http |
| AC-23 | Given lisensi tanpa Salesman Activity Then menu Archive tidak tampil (`menus.js:169`) dan endpoint yang diubah menolak sama seperti endpoint Archive lain (01 K-4 a), 4xx bukan 500 | [BE+FE] | - | cara seragam EPIC K-2 b: http dengan role tanpa permission terkait → GET `archives?id_archive=P` (tanpa `List Archive`) & PUT `archives/{P}` dengan `folder_permissions` (tanpa `Update Folder`) 403 `GE0114`, data tetap; seed: tidak ada baris baru (permission Archive hanya di `permission_salesman.sql`); FE: guard menu/route yang ada dicek di kode; tukar lisensi sungguhan sekali, manual, di Gate 2 epic |
| AC-24 | [K-7 iii, G-3] Given B ber-Delete di P, subfolder C (permission aktif) tanpa Delete untuk B When B DELETE `delete/{P}` Then 403 `ARCHIVE418`, P & C tetap `is_active=1`; B diberi Delete di C → 200; superadmin → 200 | [BE] | 9 | http + DB |

## 7. Subtask
| Kunci | Judul | Layer | AC | Module |
|---|---|---|---|---|
| ED-1031 | [BE] Updater skema folder permission + model ArchivePermission | BE | 1 | DocumentArchive |
| ED-1037 | [BE] Hak folder efektif (ArchivePermissionService) + penegakan baca di list/show/history | BE | 2, 7, 8, 10, 11, 15 | DocumentArchive |
| ED-1043 | [BE] Simpan permission folder lewat ubah folder + validasi + riwayat | BE | 4, 5, 6, 22 | DocumentArchive |
| ED-1049 | [BE] Penegakan hak di rename/ubah/hapus/buat subfolder/put-in | BE | 9, 12, 13, 14, 24 | DocumentArchive |
| ED-1055 | [BE] Select user Archive | BE | 16 | Select/DocumentArchive |
| ED-1059 | [FE] Archive - Drawer folder bertab + tab Permission | FE | 3, 17, 18 | Archive |
| ED-1063 | [FE] Archive - Layar akses ditolak, menu baris/"+"/picker ikut hak folder, ikon perisai | FE | 19, 20, 21 | Archive |
| ED-1066 | [QA] Skenario folder permission | QA | semua | Archive |

## 8. Keputusan untuk developer
| # | Pertanyaan | Opsi | Rekomendasi | Risiko | Jawaban Gate 1 |
|---|---|---|---|---|---|
| K-1 | "Parent dominan" berlaku untuk hak apa? | a) **semua hak**: hak R di F berlaku hanya bila setiap folder aktif di jalur root→F memberi R; b) hanya View dominan, Update/Delete/Store dinilai di F saja (F nonaktif = bebas); c) View dominan, Update/Delete/Store: F aktif → baris F, F nonaktif → ikut folder aktif terdekat di atasnya | a - teks S4 hal.2 berlaku umum, satu aturan untuk semua hak, paling mudah diuji. Akibat: Store di anak butuh Store juga di induk yang aktif | tinggi | **Jawaban Gate 1 (2026-10-06):** a - rekomendasi |
| K-2 | Siapa "Superadmin EQUAL" yang selalu lolos? | a) role `is_superadmin` 1 dan 2 (sama dengan bypass lokasi R-8/item 01); b) hanya 1 | a | tinggi | **Jawaban Gate 1 (2026-10-06):** a - G-2 |
| K-3 | Pembuat folder | a) otomatis punya semua hak di folder buatannya (tetap tunduk induk); b) hanya boleh mengelola tab Permission; c) tidak istimewa | a - desain membolehkan pembuat mengubah permission; tanpa View ia tidak bisa membuka drawer untuk itu | tinggi | **Jawaban Gate 1 (2026-10-06):** a - rekomendasi |
| K-4 | "User dengan permission update" yang boleh mengelola tab Permission | a) hak folder Update efektif (+ permission module `Update Folder`), tanpa permission baru; b) permission module baru (mis. `Folder Permission` di module 1266) yang juga bisa dipakai item 06 untuk menu | a - sesuai teks desain 02 | tinggi | **Jawaban Gate 1 (2026-10-06):** a - G-4 (tanpa permission baru) |
| K-5 | Hak folder vs permission module 1266 | a) keduanya wajib (module = boleh aksi itu di Archive, hak folder = di folder mana); b) di folder aktif, hak folder menggantikan permission module. Penegakan permission module di BE mengikuti keputusan item 01 (`permission_v5`) | a | tinggi | **Jawaban Gate 1 (2026-10-06):** a - G-4 |
| K-6 | Folder tanpa View di daftar & hasil pencarian | a) tetap tampil (ikon perisai), dibuka → layar 06; b) disembunyikan (06 hanya lewat breadcrumb dokumen hasil pencarian) | a - desain 01 menampilkan folder ber-perisai, desain 06 dicapai dari daftar | tinggi | **Jawaban Gate 1 (2026-10-06):** a - G-1 |
| K-7 | Pemetaan aksi yang tidak disebut sumber (BR-9) | (i) buat subfolder di X = Store di X (alternatif: Update); (ii) memindahkan folder F = Update di F + Store di tujuan (alternatif: Store tujuan saja); (iii) hapus F = Delete di F **dan** di semua subfolder aktif turunannya; ada yang tanpa Delete efektif → 403 `ARCHIVE418` (kode 01), tidak ada yang terhapus diam-diam (G-3; alternatif lama: hanya F); (iv) keluarkan ke root tanpa cek | seperti tertulis | tinggi | **Jawaban Gate 1 (2026-10-06):** seperti tertulis, (iii) diganti: subfolder aktif tanpa Delete efektif → `ARCHIVE418` - G-3 |
| K-8 | Hand Over, Receive, Info dokumen, related transaction untuk dokumen di folder tanpa View | a) tidak dibatasi (dokumen tetap tampil di pencarian, desain 06); b) butuh View folder induk | a | rendah | **Jawaban Gate 1 (2026-10-06):** a - G-6 |
| K-9 | Daftar user "+ Add User" | a) user aktif ber-`List Archive`, tanpa superadmin 1/2 & customer (endpoint baru, dipakai ulang item 06); b) semua user aktif | a | rendah | **Jawaban Gate 1 (2026-10-06):** a - G-2 (daftar hak tanpa superadmin) |
| K-10 | Detail tab Permission | (i) user baru default View saja; (ii) toggle dimatikan → baris disimpan, diabaikan, tabel read-only; (iii) perubahan permission dicatat di riwayat folder; (iv) baris tanpa hak tidak disimpan | ya untuk keempatnya | rendah | **Jawaban Gate 1 (2026-10-06):** ya untuk keempatnya - rekomendasi |
| K-11 | (dihapus Gate 1) Bug v5 di kode yang disentuh: status 500 `ARCHIVE400`-`406` dan deteksi dokumen di subfolder saat hapus | - | - | - | **Jawaban Gate 1 (2026-10-06):** gugur - kedua perbaikan dipindah ke 01 (01 BR-10, K-7; gate1.md O-4/O-5, G-3) |
| EPIC K-2 | Cara cek AC lisensi (`PROFILES` kosong) | a/b/c di gate1 §7 | b | rendah | **Jawaban Gate 1 (2026-10-06):** b - rekomendasi; cara seragam di AC-23 |

## 9. Di luar cakupan
- Layar 03 **Set Permission by User**, menu "+" Set Permission by User, endpoint per user → **ED-1029** (memakai tabel `archive_permissions` & `ArchivePermissionService` dari item ini).
- Scope lokasi folder (is_superadmin 1/2, employee location), `permission_v5`/lisensi pada route Archive, daftar transaction type, status HTTP `ARCHIVE400`-`406` dan deteksi dokumen di subfolder saat hapus (eks K-11) → **ED-1024**.
- Menu Opname, siapa boleh opname (G-4), Step 1 memakai `viewableFolderIds()` → **ED-1026**; tab Verification & angka verified → **ED-1027**; filter folder di history → **ED-1028**.
- Pewarisan otomatis baris permission ke subfolder baru (folder baru selalu nonaktif, BR-1).
- `POST archives/add-document` (method tidak ada, P-7: tiket terpisah, "Di luar epic").

## Catatan implementasi
- (BE, ED-1037) `ArchivePermissionService` (`Modules/V5/Http/Services/DocumentArchive/ArchivePermissionService.php`): `context($idUser = null)`
  memuat sekali semua folder (`type` folder, aktif maupun tidak agar jalur induk lengkap) + baris `archive_permissions` user itu;
  hasilnya diteruskan pemanggil (parameter `$context` opsional di `access`, `deniedBy`, `assert`, `assertFolders`), tidak disimpan
  di static/properti. Tambahan di luar daftar §3: `assertFolders($ids, $right, $code)` (cek hanya id yang berupa folder; dipakai
  put-in/create-folder), `folderRows($idArchive)` (isi `folder_permissions`), `replaceFolderRows($idArchive, $rows)` (simpan tab
  Permission lewat `saveUserRow`). Baris dengan Update/Delete/Store tetapi tanpa View tidak memberi hak apa pun (BR-3).
  Pembanding pembuat: `archives.created_by` vs username, tanpa beda huruf besar/kecil (sama dengan collation DB).
- (BE, ED-1037) GET `archives` 403 hak folder memakai `ErrorMessageException(..., $result)` yang di `ArchiveController::index`
  diubah ke bentuk `Message::formatResponse`; error lain di index (404 `ARCHIVE400`, 403 scope lokasi item 01) tetap bentuk lama.
  Kontrak §1 dilengkapi kalimat ini.
- (BE, ED-1049) Put-in folder ke root tetap butuh Update di folder yang dipindah (kontrak §4 "tujuan kosong tidak dicek" = hanya
  tujuan). Alasan: memindahkan folder ke luar induk aktif mengubah hak efektif semua user (induk dominan). Dokumen ke root: tanpa cek.
- (BE, ED-1049) Hapus folder: urutan cek 404/403 `ARCHIVE407` -> 403 `ARCHIVE409` -> 403 `ARCHIVE418` (scope lokasi, lalu Delete
  efektif subfolder aktif) -> 400 `ARCHIVE401`.
- (BE) Kontrak dilengkapi tanpa perubahan bentuk: `parameter`/`[0]` = nama folder penolak (bisa induk), show untuk dokumen,
  bentuk error lain di index, nilai skalar `excepts`/`selected_id` di select user.
- (BE, fix ronde 1, D-2) `UpdateRequest`/`CreateFolderRequest`: `name` `bail|required|string` (+`UniqueNameRule`), `description` `string`,
  `id_archive_parent` `bail|nullable|string|exists|LocationRule`, `id_locations.*` `string`; `UpdateRequest` `folder_permissions.*.id_user`
  `bail|required|string|distinct|exists`; `RenameFolderRequest` `name` `required|string`. `Archive\UniqueNameRule` melewati nama/induk
  bukan skalar (biar aturan tipe yang menolak). Tipe salah = 422, sebelumnya 500 (create-folder parent array sebelumnya 404).
  Akibat sampingan: `name` angka JSON (bukan string) kini 422. Nama > 255 karakter tetap 200 dan dipotong DB (pra-ada, bukan 500, tidak diubah).
- (BE, fix ronde 2, D-3) `UpdateRequest`/`CreateFolderRequest`: `exists` pada daftar `id_locations` hanya dipasang bila semua elemennya
  skalar/null (`hasScalarLocations()`, pola `PutInFolderRequest::hasValidTypes`); ada elemen array/objek -> hanya `id_locations.*` `string`
  yang menilai -> 422 (sebelumnya 500 dari `array_unique` di `exists`). `Archive\LocationRule` melewati `is_all_location`/elemen
  `id_locations` bukan skalar (dibandingkan dengan `array_diff` -> 500 bila ada induk berlokasi). Bentuk valid tidak berubah: daftar valid,
  duplikat, `[]`, `null`, `""` = 200; `[""]`, `[valid, "nope"]`, `[valid, null]`, skalar tak ada = 422; `ARCHIVE402` tetap.
