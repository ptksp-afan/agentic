---
key: ED-1028
epic: ED-1022
title: Archive - Opname History (semua sesi)
status: approved
batch: ED-1028
fe_modules: [Archive]
new_fe_modules: []
be_modules: [DocumentArchive, Select/DocumentArchive, Setup/Setting]   # Setup/Setting: (rev) K-8 a, K-9 a
qa_model: sonnet
contract: contract.md
depends_on: [ED-1024, ED-1025, ED-1026, ED-1027]
---
## Revisi review 2026-10-08

Sumber: `epics/ED-1022-review-feedback.md` § ED-1028 (+ `epics/ED-1022-ba2-notes.md`). Dasar = kode terpasang (BE 44bea5136,
FE 7fa63869d termasuk tweak developer `disableDefaultRowExpand`). Penanda **(rev)** = baru/berubah. Jawaban Gate 1 ronde 2
(`epics/ED-1022-gate1.md` §9b, 2026-10-08) sudah diterapkan: K-8 a, K-9 a, K-10 b, EPIC K-4 a, §5.

| Kode | Perubahan | BR / AC | Layer |
|---|---|---|---|
| D-1 | `GET opnames/{id}`: `folders[]` & `warnings[]` disaring ke folder yang boleh di-View pemanggil (nama & jumlah folder lain tidak terkirim); `counts`, `result_counts`, `is_partial` tetap | BR-16; AC-26 | BE |
| FB-8c | Tanpa `Opname Document` tidak ada tampilan riwayat. FE: ED-1027 rev. Item ini: server **K-8 a** (= EPIC K-3 a; **membalik Gate 1 K-6 a**), display setting `documentArchiveOpnameHistory` (K-8 a `show`/`update` 403, K-9 a `index`), QA | BR-1, 12, 13, 17; AC-12, 14, 21, 27, 28 | BE (+FE ED-1027) |
| FB-10 | Filter hasil sesi `Segmented` → `CheckableTag` (wrap xs/sm) lewat `ArchiveOpnameResult` milik ED-1026 (ED-1026 AC-30); di sini regresi + teks | BR-10; AC-20 | FE (ED-1026) |
| FB-3 (imbas) | Tidak ada draft/sesi batal di DB (ED-1026): baca draft/batal & AC-3 gugur; helper QA → satu `POST opnames` | BR-3, 13; AC-3, 12 | BE (ED-1026) + QA |
| ED-1027 FB-1 (imbas) | Modal verifikasi folder punya CTA riwayat konteks folder (ED-1027 BR-13/17 rev); di sini regresi | BR-1; AC-29 | QA |
| ED-1026 K-14 a + K-10 b (imbas) | Sel Scope & subjudul = `getScopeTag` bersama "<folder / Semua Arsip · root> + N subfolder"; `scope` = `{is_root, folder_name, subfolder_count}`, `first_name`/`other_count` dihapus - dikerjakan ED-1026 (pemilik `scope()`) | BR-2, 6, 16; AC-2, 15, 19 | BE+FE (ED-1026) |
| EPIC K-4 a | `Opname Document` id **1139** (dulu 1133; seed diubah ED-1025) | BR-12; §3 | - (seed ED-1025) |
| Gate 1 §5 | Skenario QA usang di folder ini diperbarui QA item ini (daftar di §7) | §7 [QA] | QA |
| Tetap | Gulir horizontal (menunggu klien); judul kolom dari server; BR lain | - | - |

**Gugur karena FB-3:** baca sesi batal/draft oleh pemiliknya, AC-3, "draft user lain" di BR-13/AC-12, `result_counts` dari
`counts` draft; dihapus di kode ED-1026 (`readable()` hanya terkonfirmasi, `OpnameService.php:796-826`).

## 1. Ringkasan

User Archive dengan permission `Opname Document` bisa melihat **seluruh** sesi opname terkonfirmasi, bukan hanya 3 terakhir:
CTA "Lihat semua history ›" di modal 05 (All Archive), di tab Verification folder, dan (rev) di modal verifikasi folder
membuka drawer **inline** "Opname History" yang menutupi wadahnya. Tabel memakai pola list EQUAL v5 (display setting, kolom
dari server, sort, pagination, search bar + filter popover: rentang waktu, user, folder multi). Klik baris membuka **hasil satu
sesi** (kartu angka + filter `CheckableTag` + tabel Step 3, read-only) memakai endpoint dan komponen ED-1026. Item ini hanya
membaca: tanpa tabel baru, tanpa Updater, tanpa permission baru. (rev) Rincian folder sesi hanya memuat folder yang boleh
di-View pemanggil; tanpa `Opname Document` riwayat tidak tampil dan tidak bisa dibaca lewat API (K-8 a).

## 2. Aturan bisnis

| BR | Aturan | Sumber |
|---|---|---|
| BR-1 (rev) | Titik masuk: CTA "Lihat semua history ›" di overview Opname History modal **05** (konteks All Archive), tab **Verification** drawer folder F (konteks F), dan modal verifikasi folder F dari kolom Document Verified (konteks F). Hanya untuk role ber-`Opname Document` (BR-17). Tidak ada menu/tombol lain | R-24; 04 BR-13, K-6 a; ED-1027 BR-13/17 (rev); FB-8c |
| BR-2 | History = drawer **inline** selebar wadahnya (menutupi isi modal/drawer). Judul "Opname History"; subjudul "<All Archive> — seluruh sesi opname" (label root diterjemahkan, id "Semua Arsip", ED-1026 N-4) atau "<nama F> — seluruh sesi opname yang mencakup folder ini" (K-1). ✕ kembali ke tampilan verifikasi; menutup wadah ikut menutupnya | R-24; [S2 05b header] |
| BR-3 (rev) | Yang tampil hanya sesi **terkonfirmasi** (`archive_opnames.status = 2`, filter tetap) yang **terlihat user** menurut aturan overview 3 sesi 04 (04 K-4 + EPIC K-1, `VerificationService::sessionQuery`). Sesudah ED-1026 FB-3 tidak ada lagi draft/sesi batal di DB (Updater ED-1026 K-13). Superadmin `is_superadmin` 1/2 melihat semua | 04 BR-10, K-4; ED-1026 FB-3 |
| BR-4 | Konteks folder F: hanya sesi yang **mencakup F** (04 K-3: F atau subfoldernya). 3 baris teratas = 3 baris overview konteks itu | 04 BR-11, K-3; K-1 |
| BR-5 | Pola tabel v5: tombol Display Setting, search bar + filter popover berisi query, kolom dari server, sort per kolom, pagination server. Tanpa filter di atas tabel, tanpa teks jumlah sesi | R-25, R-26 |
| BR-6 (rev) | Kolom default: **Waktu** (konfirmasi, tanggal + jam), **User** (username pembuat), **Scope** (`getScopeTag` bersama ED-1026: "<nama folder opname / All Archive · root> + N subfolder", N = `scope.subfolder_count`, tanpa akhiran bila 0, root id "Semua Arsip · root"; nama subfolder tidak ditampilkan/dikirim), **Total dokumen**, **Verified**, **Not found**, **Invalid**; tersedia: **Scanned**, **Belum discan** (K-7). Angka = nilai rekaman saat Confirm | [S2 05b]; 03 BR-15, 25; ED-1026 BR-30, K-14 a; K-10 b |
| BR-7 | Urutan bawaan Waktu terbaru di atas. Sort: Waktu, User, semua kolom angka; Scope tidak | [S2 05b]; Catatan implementasi |
| BR-8 | Search bar: username pembuat **atau** nama folder sesi (level 0-2, nama snapshot), mengandung, tanpa beda huruf; hanya folder yang boleh dilihat user yang dicocokkan (K-3) | R-27 |
| BR-9 | Query filter (AND dengan search): (a) **Waktu Opname** rentang tanggal-jam inklusif, ujung boleh satu; (b) **User** satu pilihan: pelaku sesi terlihat (K-2); (c) **Folder** pilihan ganda, folder terlihat (lokasi 01 ∩ View 02), cocok bila sesi mencakup **salah satu** (BR-4, K-3) | R-28; `helpers/tableHelper.js:520-543`, `:675-720` |
| BR-10 (rev) | Klik baris (tanpa baris expand bawaan, `disableDefaultRowExpand`) membuka **hasil sesi** (K-4): drawer bersarang; judul "Hasil Opname", subjudul Waktu · User · Scope; kartu Total dokumen, Verified sesi ini, Not found, Invalid, Belum discan; filter **`CheckableTag`** All / Scanned / Verified / Not Found / Invalid (+ jumlah), tepat satu aktif, default **Scanned**, membungkus di xs/sm (komponen ED-1026, FB-10); tabel Document No, Transaction Type, Folder ("Di luar scope opname" untuk Not found, "—" Invalid), Time, Result; latar baris khusus. Read-only, tanpa peringatan & aksi. Tutup → tabel riwayat dengan halaman & filter sama | R-21, R-22; FB-10; FE 7fa63869d |
| BR-11 | Pemanggil yang melihat sebagian cakupan: baris dokumen di luar scope-nya tidak tampil, jumlah filter dari baris tampil, kartu = angka rekaman + catatan "Sebagian dokumen di luar akses Anda tidak ditampilkan" (K-5 b) | PK-2 a; 04 K-2 a |
| BR-12 (rev) | Akses (K-8 a; **membalik Gate 1 K-6 a** "`List Archive` cukup"): daftar riwayat, ringkasan & dokumen sesi butuh **`Opname Document`** (id **1139**, EPIC K-4 a; seed `permission_checks (1139,1082)` → pemegangnya juga punya `List Archive`); tanpa → 403 `GE0114` `parameter` "Opname Document", tanpa data (juga role tanpa permission Archive apa pun). Select Folder: konvensi select (tanpa `permission_v5`), data dalam scope (tetap). Select User: tanpa `Opname Document` → `options` kosong | FB-8c; EPIC K-3 a, K-4 a; `permission_salesman.sql:82,138` (seed diubah ED-1025) |
| BR-13 (rev) | Penolakan: tanpa permission → 403 `GE0114` (dicek pertama); `id_archive` tidak ada/nonaktif → 404 `ARCHIVE400`; bukan folder → 400 `ARCHIVE417`; di luar lokasi / tanpa View → 403 `ARCHIVE407`; sesi tidak ada (termasuk id bekas draft) atau tidak terlihat → 404 `ARCHIVE412`; query tidak valid → 422. Tidak ada 500 | D-6; 03 contract §6/§7 |
| BR-14 | Selama drawer riwayat terbuka scanner pencarian halaman Archive nonaktif, aktif lagi saat ditutup | `QRScanSearch/index.js:21,49` |
| BR-15 | Tanpa sesi / tanpa hasil filter: tabel kosong standar, tanpa error | turunan BR-5 |
| BR-16 (rev) | Ringkasan sesi `GET opnames/{id}` bagi pemanggil bukan superadmin 1/2 (termasuk pembuat sesi): (a) `folders[]` hanya baris snapshot yang `id_archive`-nya folder terlihat pemanggil **saat ini** (aktif, scope lokasi 01, View efektif 02 = himpunan yang sama dengan saringan baris dokumen BR-11); baris root (`id_archive` null) selalu ikut; nama & `document_count` tetap snapshot. (b) `warnings[]` hanya untuk folder level 0-1 yang lolos (a) (root selalu); `unverify_count` = jumlah `unverified_count` folder itu + turunan level 2 **yang juga lolos**; hasil 0 → peringatan tidak dikirim. (c) Tidak berubah: `counts` (angka rekaman, K-5 b), `result_counts` & `is_partial` (dari baris dokumen; folder tersembunyi tanpa dokumen tidak membuat `is_partial` true). `scope` = `{is_root, folder_name, subfolder_count}` tanpa nama subfolder (K-10 b, ED-1026). Superadmin 1/2: semua baris | D-1 (butir 13); K-5 b; K-10 b; `OpnameService.php:1777-1848` |
| BR-17 (rev) | Tanpa `Opname Document` tidak ada tampilan riwayat: CTA tidak dirender di wadah mana pun, `ArchiveOpnameHistory` tidak di-mount (tidak ada request `opnames*`); display setting `documentArchiveOpnameHistory`: `show`/`update` 403 (K-8 a) dan tidak ada di daftar Setup > Display Setting (`index`, K-9 a). Wadah & mount di FE dikerjakan ED-1027 rev (FB-8b: modal 05/folder, tab Verification, `ArchiveDrawer`); item ini memverifikasinya | FB-8c; ED-1027 BR-15..19 (rev); K-8 a, K-9 a |

Kolom v3: tabel `archive*` tidak dipakai v3 dan item ini tidak menulis apa pun → tidak ada kolom v3 yang dijaga (D-4/D-5).

## 3. Model data

| Hal | Isi |
|---|---|
| Tabel | Tidak ada yang baru/berubah. Dibaca (DB tenant): `archive_opnames`, `archive_opname_folders`, `archive_opname_documents`, `archives`, `archive_permissions` |
| Display setting | `Config/displayColumn/documentArchiveOpnameHistory.php` (terpasang, isi di contract §4), terdaftar di `Config/config.php`; baris `column_display_settings` dibuat otomatis saat request pertama (`ColumnDisplaySettingService.php:33-38`). **Config tidak berubah → tanpa Updater.** (rev) K-8 a: satu entri **seluruh modul** `documentArchiveOpnameHistory → Opname Document` di peta saringan milik ED-1027 (`ColumnDisplaySettingService`, jenis entri (2) ED-1027 §3); K-9 a: `index` (`:14-23`) membuang baris modul berentri seluruh modul yang permission-nya tidak dimiliki pemanggil. Kode saja |
| `data_index` | nama kolom `archive_opnames` (camelCase) + `scope` (objek, lewat `excepts`) |
| Updater | Tidak ada (pembersih draft/batal = ED-1026 K-13) |
| Permission | Tidak ada yang baru. (rev) `Opname Document` id **1139** (EPIC K-4 a; `permission_salesman.sql:82` + checks diubah ED-1025; QA DB diseed ulang di run ED-1025) dipakai riwayat (K-8 a); `List Archive` (1082) tetap untuk sisi Archive lain. BE/FE memakai nama, bukan id |
| Kode pesan | Tidak ada yang baru. `ARCHIVE200`, `SUCCESS`, `ARCHIVE400/407/412/417`, `GE0114` (`PermissionVersion5.php:61`) |
| Service | `OpnameService::index` memakai `VerificationService::context()/sessionQuery()/subtreeIds()/whereCovers()` (satu sumber dengan `last_sessions`). (rev) D-1: `show` meneruskan konteks (`readable()`, sudah dihitung) ke `confirmedSummary` dan menyaring baris dengan himpunan `context['folders']` (`VerificationService.php:115-158`), tanpa query pohon kedua. `scope()` (ED-1026) tidak diubah item ini |

Asumsi A-1..A-6 atas 03/04 sudah dipastikan saat implementasi. (rev) Sesi selalu `status = 2` (ED-1026 FB-3); baris 3 sesi 04
tidak bisa diklik (04 K-5 a).

## 4. Endpoint v5

Prefix `api/v5`; route `Routes/DocumentArchive/api.php:41-51`, select `Routes/Select/select.php:2718-2719`. Bentuk: `contract.md`.

| Route | Controller@method | FormRequest | Service | Permission | Status |
|---|---|---|---|---|---|
| GET `document-archive/opnames` | `OpnameController@index` | - (search divalidasi di service, 422) | `OpnameService::index` | (rev) `Opname Document` (K-8 a; kini `List Archive`, `api.php:42`) | ubah (rev) |
| GET `document-archive/opnames/{id}` | `@show` | - | `show`: sesi terlihat (`readable()`, ED-1026 rev: hanya terkonfirmasi), `result_counts`, `is_partial`; (rev) `folders`/`warnings` disaring BR-16 | (rev) `Opname Document` (K-8 a; `api.php:45`) | ubah (rev) |
| GET `document-archive/opnames/{id}/documents` | `@documents` | - | `documents`: baris disaring K-5 b (tetap) | (rev) `Opname Document` (K-8 a; `api.php:49`) | ubah (rev) |
| GET `select/document-archive/archive/folders` | `SelectArchiveController@folder` | - | `SelectArchiveService::folder` (`:89`) | - (select) | sudah ada |
| GET `select/document-archive/archive/opname-users` | `@opnameUser` | - | `SelectArchiveService::opnameUser` (`:139`); (rev) tanpa `Opname Document` → `options: []` (`MyHelper::hasPermission`, `MyHelper.php:521`, preseden `TransactionPrintService.php:37`) | - (select) | ubah (rev) |
| GET/PUT `setting/display-columns/{moduleName}`, GET `setting/display-columns` | `ColumnDisplaySettingController` | - | (rev) pemanggil tanpa `Opname Document`: `show`/`update` module `documentArchiveOpnameHistory` → 403 `GE0114` (`parameter` "Opname Document") lewat entri seluruh modul di peta ED-1027 (K-8 a); `index` tanpa baris itu (K-9 a). Module lain: aturan ED-1027 | - | ubah (rev) |

`GET opnames` tanpa transaksi; sort lewat whitelist (Catatan implementasi). Select `{default, options}`; `search` select = string.
`select/document-archive/archive/users` (02) tidak dipakai (02 K-9). (rev, O-5) 3 route GET `opnames*` milik item ini; komentar
`api.php:39` ditulis ulang ED-1026 ("baca sesi = ED-1028"), tidak boleh menyebut `List Archive` untuk baca sesi.

## 5. Perubahan FE per module

**Archive** (`src/containers/Archive`, master dengan aksi baris kustom). Blueprint: wadah inline
`ModalImportExportExcelDrawerHistory/index.js:28-40`, drawer bersarang `.../PreviewDrawer/index.js:21-40`, tabel v5 dalam drawer
`SalesmanItemSalesOrder/index.js:10-75`. Terpasang (FE b7e08edcb, 7fa63869d):

| Bagian | Isi |
|---|---|
| `archive-components/ArchiveOpnameHistory/index.js` | `forwardRef`, `onOpen({ idArchive, name })`/`onClose`; `Drawer` inline `width="100%"`, wadah = `closest('.ant-modal-content, .ant-drawer-content')` dari jangkar; judul + subjudul BR-2; scanner halaman nonaktif selama terbuka (BR-14) |
| `ArchiveOpnameHistory/Content.js` | `useTable(fetch.getArchiveOpnames, { params: { idArchive }, displaySettingId })`, `useColumns` + render Scope/Waktu, `ButtonDisplaySetting`, `FilterWrapper` + `Search isInsideModal`, `DefaultTable rowKey="idArchiveOpname" onRow disableDefaultRowExpand`, catatan kaki, `DisplaySettingDrawer`, `ResultDrawer` |
| `ArchiveOpnameHistory/ResultDrawer.js` | drawer bersarang `getContainer={false}`; `getArchiveOpname` → `ArchiveOpnameCounts` + Alert BR-11 bila `isPartial` + `ArchiveOpnameResult` (`defaultResult` scanned, `resultCounts`). Tidak merender `folders`/`warnings` (D-1 murni BE) |
| (rev) `ArchiveOpnameResult`, `getScopeTag` (ED-1026) | filter `CheckableTag` wrap xs/sm dan format Scope (`renderOpnameScope` → `getScopeTag`, tanpa `firstName`/`otherCount`) dibuat ED-1026; pemanggilan di `ResultDrawer.js`/`Content.js` disesuaikan ED-1026 bila props berubah (ED-1026 AC-30/AC-31) |
| (rev) Penyembunyian FB-8c | **ED-1027 rev**, tanpa kode FE di sini: `ArchiveDrawer` tab Verification + `<ArchiveOpnameHistory>` (`:86,93`) hanya dengan `usePermission({ permission: permissions.OpnameDocument })`; modal verifikasi folder + CTA hanya dengan permission. Diperiksa AC-27/AC-29 |
| (rev) Display setting | tanpa perubahan FE: tombol ikut hilang bersama drawer; daftar Setup dari BE (K-9 a) |
| Registrasi | `endpoints.js` `getArchiveOpnames`; `displaySetting.constant.js:164`; locale `archive.*` en/id. Tanpa route/menu/permission FE baru |

## 6. Acceptance criteria

QA DB `api_sidomaju` (sudah diseed ulang dengan `Opname Document` 1139 di run ED-1025; prasyarat dicek per **nama** permission).
(rev) Sesi dibuat lewat satu `POST opnames` (ED-1026 contract §5) oleh superadmin dan user2 (lokasi/View terbatas), dipulihkan (P-4). Role uji (rev, K-8 a): **user2** = role uji user2 + `Opname Document` (grant
`role_permissions` sementara, dipulihkan) untuk AC-1..AC-26 & AC-29 (dulu tanpa `Opname Document`); **R0** = role ber-`List Archive`
tanpa `Opname Document` (mis. role asli user2) untuk AC-14/AC-27/AC-28. Lisensi: EPIC K-2 b.

| AC | Given / When / Then | Layer | BR | Cek |
|---|---|---|---|---|
| AC-1 | `GET opnames` → 200 `ARCHIVE200`, paginator + `columns` (7, urut BR-6) + `queries` (3); baris display setting terbentuk; urut `confirmed_at` turun | BE | 5,6,7 | http + DB |
| AC-2 (rev) | Angka baris S = kolom `archive_opnames` S; `scope` baris = `scope` `GET opnames/{S}` = tepat kunci `is_root`, `folder_name`, `subfolder_count` (K-10 b; `subfolder_count` = `scope_folder_count`); dokumen ditambah sesudahnya → angka tetap | BE | 6,16 | http + SQL |
| AC-3 (rev) | **Dihapus** (FB-3): draft/sesi batal tidak ada lagi di DB (ED-1026 AC-1/K-13) | - | - | - |
| AC-4 | `search.query`: sebagian username; nama folder level 2; tanpa beda huruf; user2: nama folder tak terlihat tidak mencocokkan | BE | 8 | http |
| AC-5 | `confirmedAt` [awal, akhir] inklusif; hanya awal; hanya akhir; tanggal tidak valid → 422 | BE | 9,13 | http |
| AC-6 | `createdBy` satu username; `idArchives` [F1,F2] = mencakup F1 **atau** F2 (termasuk subfolder, K-3 ii); AND dengan `query`; bukan array → 422 | BE | 9 | http |
| AC-7 | `sorts` tiap kolom sortable naik/turun; sort `scope` diabaikan tanpa 500 | BE | 7 | http |
| AC-8 | Superadmin semua sesi terkonfirmasi; user2 sesuai 04 K-4; 3 baris pertama = `last_sessions` `GET verifications` user yang sama | BE | 3 | http dua user |
| AC-9 | `id_archive=F`: hanya sesi mencakup F; 3 baris pertama = `last_sessions` `GET verifications/{F}`; tak ada → 404 `ARCHIVE400`; dokumen → 400 `ARCHIVE417`; di luar lokasi / tanpa View → 403 `ARCHIVE407` | BE | 4,13 | http |
| AC-10 | Select folders: user2 hanya folder aktif lokasi ∩ View, label path, `search`, `selected_id`; superadmin semua folder aktif | BE | 9 | http dua user |
| AC-11 | Select opname-users: username unik pelaku sesi terlihat (termasuk superadmin bila pelaku), `search`, urut | BE | 9 | http |
| AC-12 (rev) | (K-8 a; membalik AC-12 Gate 1 "user2 tanpa `Opname Document` = 200") User2 ber-`Opname Document`: `GET opnames/{S}` & `/documents` sesi terlihat → 200; sesi tidak terlihat / id tidak ada / id bekas draft → 404 `ARCHIVE412` | BE | 12,13 | http |
| AC-13 | Sesi root superadmin lintas lokasi: user2 `/documents?result=all` hanya baris dalam scope (+ Invalid), `result_counts` = jumlah baris itu, `is_partial=true`; superadmin semua, `is_partial=false` | BE | 11 | http dua user |
| AC-14 (rev) | R0: `GET opnames`, `opnames/{S}`, `/documents` (juga id tidak ada dan `id_archive` di luar scope) → 403 `GE0114` `parameter` "Opname Document", tanpa data; select opname-users → 200 `options: []`; select folders tetap = scope (lokasi ∩ View). Role tanpa permission Archive (mis. role 22) → 403 `GE0114` `parameter` "Opname Document". Role dipulihkan persis | BE | 12,13 | http; lisensi EPIC K-2 b, tukar lisensi manual Gate 2 epic |
| AC-15 (rev) | Modal 05 → CTA → drawer inline menutupi modal tanpa error; subjudul All Archive (bahasa id "Semua Arsip"); tanpa teks "N sesi"; satu `GET opnames` tanpa `idArchive`; ✕ kembali ke isi modal | FE | 1,2,5 | e2e, profil default |
| AC-16 | Drawer folder F → tab Verification → CTA → drawer inline; request `idArchive=F`; subjudul nama F; ✕ kembali | FE | 1,2,4 | e2e |
| AC-17 | Search kirim `search` `{"query":…}`; popover terbuka; select User & Folder memuat opsi; rentang waktu → `confirmedAt` `["YYYY-MM-DD HH:mm:ss", …]`; Folder multi → `idArchives` array; Reset mengosongkan | FE | 8,9 | e2e (request) |
| AC-18 | Display Setting terbuka; sembunyikan/tampilkan kolom (Scanned) → tabel ikut; halaman 2 → `page=2`; klik header → `sorts` | FE | 5,7 | e2e |
| AC-19 (rev) | Sel Scope = `getScopeTag(scope)` (K-14 a, K-10 b): folder tanpa subfolder "ZULFA", folder + 3 subfolder "Backup + 3 subfolder", root id "Semua Arsip · root" / en "All Archive · root"; Waktu tanggal + jam. Test helper milik ED-1026 (AC-31); di sini e2e sel tabel | FE | 6 | e2e sel Scope (unit = ED-1026) |
| AC-20 (rev) | Klik baris → drawer hasil tanpa error dan tanpa baris expand: `GET opnames/{id}` + `/documents?result=scanned`; kartu 5 angka; `CheckableTag` default Scanned + jumlah dari `resultCounts`, tepat satu aktif; ganti tag → request `result` baru halaman 1; Not found "Di luar scope opname", Invalid "—"; di 375 px tag membungkus tanpa gulir horizontal; ✕ kembali dengan halaman & filter sama | FE | 10 | e2e |
| AC-21 (rev) | (K-8 a) User2 ber-`Opname Document` (lokasi/View terbatas) membuka riwayat & hasil sesi tanpa error; sesi parsial → catatan BR-11 | FE | 11,12 | e2e user2 |
| AC-22 | Drawer riwayat terbuka: scan USB tidak memicu `GET archives`; sesudah ditutup memicu lagi | FE | 14 | e2e |
| AC-23 | Tanpa sesi → tabel kosong; filter tanpa hasil → kosong, Reset mengembalikan | FE | 15 | e2e |
| AC-24 (rev) | Regresi: modal 05, tab Verification, modal verifikasi folder (ED-1027 rev) tampil benar; opname ED-1026 rev (scan/pratinjau/Confirm) jalan; list Archive, scan pencarian, mode Select; hak folder aturan baru ED-1025 FB-9 tidak mengubah sesi terlihat | BE+FE | - | e2e + http |
| AC-25 | Struktur & alur sesuai desain 05b (kecuali filter, R-25/26); tampilan EQUAL | FE | 2,5,6,10 | manual (Gate 2) |
| AC-26 (rev) | Given sesi S superadmin dari F dengan subfolder A (terlihat user2) dan B (Izin Folder On tanpa user2) berturunan B1, peringatan di B; When user2 `GET opnames/{S}`, Then `folders[]` = root/F/A/turunan A terlihat saja, "B"/"B1" tidak ada di `folders`/`warnings`/`scope`; `unverify_count` = SUM SQL baris terlihat, peringatan 0 hilang; `counts` = kolom `archive_opnames`; `result_counts`/`is_partial` = AC-13. Superadmin: semua baris `archive_opname_folders` S. Pembuat non-superadmin yang kehilangan View: folder itu ikut tersaring | BE | 16 | http dua user + SQL |
| AC-27 (rev) | (implementasi ED-1027 rev) R0: halaman Archive, drawer folder (Detail), list → tanpa CTA "Lihat semua history", tanpa `.drawer-history` di DOM, tanpa request `opnames*`; Setup > Display Setting tanpa baris `documentArchiveOpnameHistory` (K-9 a). Profil default: CTA ada di ketiga wadah, baris Setup tampil | FE | 1,17 | e2e dua profil |
| AC-28 (rev) | R0: `GET`/`PUT setting/display-columns/documentArchiveOpnameHistory` → 403 `GE0114` `parameter` "Opname Document", DB tetap, baris tidak dibuat bila belum ada (K-8 a); `GET setting/display-columns` (juga `search=documentArchive`) tanpa baris `documentArchiveOpnameHistory`, `total` ikut berkurang (K-9 a); `documentArchive` tetap 200 (aturan ED-1027 AC-24); profil default: baris tampil, show/put 200 | BE | 17 | http + DB |
| AC-29 (rev) | Regresi (implementasi ED-1027 AC-26): list Archive → klik nilai Document Verified baris folder F → modal folder → CTA → drawer riwayat menutupi modal tanpa error; request `idArchive=F`; subjudul nama F; ✕ kembali ke modal | FE | 1,4 | e2e (sesudah ED-1027 rev) |

## 7. Subtask

Subtask awal ED-1034, ED-1040, ED-1046, ED-1052, ED-1058, ED-1062 selesai (run.md). Baru untuk perbaikan:

| Kunci | Judul | Layer | AC | Module |
|---|---|---|---|---|
| (rev) | [BE] Archive - Opname History: saring folders & warnings ringkasan sesi sesuai hak View pemanggil | BE | AC-26 | DocumentArchive (`OpnameService::show`, `confirmedSummary`) |
| (rev) | [BE] Archive - Opname History hanya untuk permission Opname Document (route GET opnames*, select user, display setting show/update/index) | BE | AC-12, AC-14, AC-28 | DocumentArchive (route), Select/DocumentArchive, Setup/Setting (entri seluruh modul di peta ED-1027 + saringan `index`; K-8 a, K-9 a) - sesudah subtask display setting ED-1027 |
| (rev) | [QA] Skenario Opname History revisi (helper satu POST opnames, CheckableTag, D-1, permission Opname Document, scope tanpa first_name, skenario usang gate1 §5, regresi) | QA | AC-1..AC-29 (rev; AC-3 dihapus) | - |

QA (rev, gate1 §5 - berkas di folder ini, diubah QA item ini):

| Berkas | Perubahan |
|---|---|
| `qa/qa_lib.php:537-580` | `q28_session`/`q28_scan`/`q28_confirm`/`q28_run` → satu `POST opnames` (`scans` + `selected_at`) |
| `qa/qa_hist.php:153-155` | fixture H (draft) & K (batal + `DELETE`) dibuang |
| `qa/scenario1-list.php:177-189` | kunci `scope` = `is_root, folder_name, subfolder_count`; asersi `first_name`/`other_count` → `subfolder_count` (K-10 b, AC-2) |
| `qa/scenario1-list.php:228-265` | sesi J/ctl draft + `DELETE` → "id tidak ada = 404 `ARCHIVE412`" |
| `qa/scenario5-access.php:43-47` | prasyarat user2: **punya** `Opname Document` (dicari per nama, bukan 1133) |
| `qa/scenario5-access.php:144-156` | rute dihapus ED-1026 (`:150-154` PUT confirm, DELETE, scan) dibuang; sisa penolakan tulis = ED-1026 AC-3 |
| `qa/scenario5-access.php:186-247` (AC-14) | R0 → 403 `parameter` "Opname Document" (bukan "List Archive"); select opname-users `options: []` |
| e2e `ED-1028-opname-history.spec.js:978-1019,1099,1367,1383,1387,1481-1518` | `.ant-segmented-item` → selektor `CheckableTag` |
| e2e `:1327-1336` (AC-21) | user2 ber-`Opname Document`; tambah profil R0 untuk AC-27 |

Skenario usang ED-1026 AC-12/AC-15 milik QA ED-1026, bukan item ini.

**Jira subtask (rev), dibuat 2026-10-08 (sprint 46):** ED-1100 [BE] saring folders/warnings · ED-1101 [BE] riwayat per permission · ED-1102 [QA]

## 8. Keputusan untuk developer

Jawaban Gate 1 (2026-10-06) tetap: K-1 a, K-2 a, K-3 ya semua, K-4 a (filter kini `CheckableTag`, FB-10), K-5 b, K-7, EPIC K-1 a
(sesi root terlihat semua pemegang permission), EPIC K-2 b. **K-6 a
(`List Archive` cukup untuk membaca riwayat) dibalik oleh K-8 a (2026-10-08).** Sudah diputuskan, bukan pertanyaan: CTA riwayat di
modal verifikasi folder (ED-1027 BR-13/17 rev), gulir horizontal (menunggu klien), judul kolom dari server, D-1.

**K-8 (rev, tinggi) - server ikut menahan riwayat bagi user tanpa `Opname Document`? (= ED-1027 K-9 = EPIC K-3)**
a) server + FE: `GET opnames`, `opnames/{id}`, `/documents` → `permission_v5:Opname Document` (403 `GE0114`); select opname-users
`options: []`; select folders tetap; display setting `documentArchiveOpnameHistory` = entri seluruh modul di peta ED-1027 →
`show`/`update` 403; b) server hanya display setting, `GET opnames*` tetap `List Archive`; c) FE saja (Gate 1 K-6 a). Rek. a.
Jawaban Gate 1 (2026-10-08): **a** - server + FE (EPIC K-3 a); membalik Gate 1 K-6 a → BR-12/13/17, AC-12/14/21/27/28 (rev).

**K-9 (rev, rendah) - baris `documentArchiveOpnameHistory` di Setup > Display Setting (`index`, `ColumnDisplaySettingService.php:14-23`) bagi role tanpa `Opname Document`?**
a) disembunyikan di `index` BE; b) tetap tampil, membukanya 403; c) FE menyaring (pagination meleset). Rek. a.
Jawaban Gate 1 (2026-10-08): **a** - `index` tanpa baris modul berentri seluruh modul yang permission-nya tidak dimiliki → BR-17, AC-27/28.

**K-10 (rev, rendah) - `scope.first_name` bisa menyebut subfolder yang tak boleh di-View pemanggil.**
a) dibiarkan; b) `first_name`/`other_count` dihapus dari `scope` (ED-1026, pemilik `scope()`); c) `first_name` null bila tak terlihat. Rek. b.
Jawaban Gate 1 (2026-10-08): **b** - dikerjakan ED-1026; `scope` = `{is_root, folder_name, subfolder_count}` → BR-6/16, AC-2/19, contract.

## 9. Di luar cakupan

- Penulisan sesi opname, alur tanpa draft, `readable()`, `scope()`/`subfolder_count` (termasuk hapus `first_name`/`other_count`),
  `ArchiveOpnameResult` (`CheckableTag`), `getScopeTag` + format Scope (K-14), terjemahan "All Archive" → **ED-1026**. Saringan
  `folders`/`warnings` `POST opnames/preview` tidak perlu (pembuat hanya memilih folder terlihat, O-7).
- Overview 3 sesi, `VerificationService`, aturan sesi terlihat (04 K-4/K-3), modal verifikasi folder (FB-1), penyembunyian modal 05 /
  tab Verification / kolom Document Verified, mount `ArchiveOpnameHistory` & CTA tanpa `Opname Document`, struktur peta saringan
  display setting (FB-8b, O-3) → **ED-1027**.
- Seed `Opname Document` 1139 + checks (EPIC K-4 a) → **ED-1025**. Lokasi & `findVisible` → ED-1024; View, `viewableFolderIds()`,
  aturan induk tidak superior (FB-9) → ED-1025; by User → ED-1029.
- Gulir horizontal tabel riwayat di wadah sempit (menunggu klien); judul kolom per bahasa (judul dari server, tetap).
- Export/cetak riwayat, hapus/batal sesi terkonfirmasi, filter per hasil, riwayat di aplikasi mobile.
- R-1..R-23 milik item lain (`epics/ED-1022-plan.md`); R-24..R-28 di BR-1..9.

## Catatan implementasi

- (BE, ED-1034) `index`: + `visibleFolder()` (public); pemecah seri `confirmed_at desc, id_archive_opname desc`. Baris = `sessionRow()`
  + `id_archive`, `scanned_count`, `unscanned_count`, disaring ke kolom aktif (+ `id_archive_opname`, `id_archive`, `scope`).
- (BE, ED-1034) Sort lewat whitelist `HISTORY_SORTS` + `orderBy` (bukan `sortAll`: 500 untuk elemen array / atribut relasi).
- (BE, ED-1034) Kolom `archive_*` latin1: teks `query`/`createdBy` di luar latin1 = tidak cocok (200 kosong); `%`/`_` harfiah;
  `search` bukan JSON objek → 422.
- (BE, ED-1046) `result_counts` dari baris snapshot (juga superadmin); sama dengan `counts` kecuali dokumen bernama ganda yang dicatat
  satu baris; saringan K-5 b juga bagi pembuat non-superadmin.
- (BE, ED-1040) Select `folders`/`opname-users`: `search` & `selected_id` disaring di PHP atas daftar dalam scope (aman kolasi);
  label = path dari root (nama induk ikut walau induk tak terlihat).
- (FE, developer 7fa63869d) `DefaultTable` meneruskan `onRow` saat `disableDefaultRowExpand`: klik baris hanya membuka hasil sesi.
- (FE) Riwayat di `ArchiveDrawer` dirender di luar `ArchiveForm`; `ArchiveOpnameCounts` komponen bersama (Step 3 ED-1026).
