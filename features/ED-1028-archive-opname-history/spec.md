---
key: ED-1028
epic: ED-1022
title: Archive - Opname History (semua sesi)
status: approved
batch: ED-1028
fe_modules: [Archive]
new_fe_modules: []
be_modules: [DocumentArchive, Select/DocumentArchive]
qa_model: sonnet
contract: contract.md
depends_on: [ED-1024, ED-1025, ED-1026, ED-1027]
---
## 1. Ringkasan

User Archive bisa melihat **seluruh** sesi opname terkonfirmasi, bukan hanya 3 terakhir: CTA "Lihat semua history ›"
di modal 05 (All Archive) dan di tab Verification folder (item 04) membuka drawer **inline** "Opname History" yang
menutupi wadahnya. Tabelnya memakai pola list EQUAL v5 (display setting, kolom dari server, sort, pagination, search bar
+ filter popover dengan query rentang waktu, user, folder multi), tanpa filter di atas tabel dan tanpa teks "N sesi".
Klik baris membuka **hasil satu sesi** (kartu angka + `Segmented` + tabel Step 3, read-only) memakai endpoint dan
komponen milik item 03. Item ini hanya membaca: tanpa tabel baru, tanpa Updater, tanpa permission baru; satu display
setting baru, satu endpoint list + dua select baru, dan visibilitas baca dua endpoint 03 diperluas.

## 2. Aturan bisnis

| BR | Aturan | Sumber |
|---|---|---|
| BR-1 | Titik masuk: CTA "Lihat semua history ›" di overview Opname History modal **05** (konteks All Archive) dan di tab **Verification** drawer folder F (konteks F). Tidak ada menu/tombol lain | R-24; [S2 05b judul "dibuka dari CTA di kedua tampilan verifikasi"]; 04 BR-13, K-6 a |
| BR-2 | History = drawer **inline** selebar wadahnya: dari modal 05 menutupi isi modal, dari drawer folder menutupi isi drawer. Judul "Opname History"; subjudul "All Archive — seluruh sesi opname" atau "<nama F> — seluruh sesi opname yang mencakup folder ini" (K-1). Tutup (✕) kembali ke tampilan verifikasi; menutup modal/drawer induk ikut menutupnya | R-24; [S2 05b header] |
| BR-3 | Hanya sesi **terkonfirmasi** (`archive_opnames.status = 2`) yang **terlihat user** menurut aturan yang sama dengan overview 3 sesi 04 (04 K-4 + EPIC K-1, satu scope di `VerificationService`). Draft dan sesi batal tidak pernah tampil. Superadmin `is_superadmin` 1/2 melihat semua | 04 BR-10, K-4; 03 BR-21 |
| BR-4 | Konteks folder F (dari tab Verification): hanya sesi yang **mencakup F** dengan definisi yang sama dengan overview tab 04 (04 K-3: F atau subfoldernya). 3 baris teratas = 3 baris overview tab itu | 04 BR-11, K-3; K-1 |
| BR-5 | Pola tabel v5: tombol Display Setting (kolom aktif/tersedia, query aktif/tersedia, bisa diubah lewat drawer Display Setting), search bar dengan filter popover berisi query, kolom dari server, sort per kolom, pagination server. Tidak ada filter di atas tabel, tidak ada teks jumlah sesi ("7 sesi") | R-25, R-26 |
| BR-6 | Kolom default: **Waktu** (waktu konfirmasi, tanggal + jam), **User** (username pembuat sesi), **Scope** (format tag 03: "All Archive · root" / "<subfolder pertama> + N subfolder" / nama folder), **Total dokumen**, **Verified**, **Not found**, **Invalid**. Kolom tersedia tambahan: **Scanned**, **Belum discan** (K-7). Angka = nilai yang direkam saat Confirm, tidak dihitung ulang | [S2 05b tabel + catatan "Total dokumen direkam per sesi, sesuai kondisi folder saat opname dijalankan"]; 03 BR-15, BR-25; 04 BR-10/12 |
| BR-7 | Urutan bawaan: Waktu terbaru di atas. Bisa di-sort: Waktu, User, semua kolom angka; Scope tidak | [S2 05b urutan turun]; `CustomizeBuilder.php:211-252` (sort hanya kolom tabel) |
| BR-8 | Search bar mencari (mengandung, tanpa beda huruf besar) **username** pembuat sesi **atau** **nama folder** mana pun yang ikut sesi: folder opname, subfolder yang dicentang, dan turunan yang ikut otomatis (03 level 0-2). Nama = nama folder saat sesi (snapshot 03); hanya folder yang boleh dilihat user yang dicocokkan (K-3) | R-27; 03 §3 `archive_opname_folders.name` |
| BR-9 | Query di filter popover (digabung AND dengan search bar): (a) **Waktu Opname** rentang tanggal-jam atas waktu konfirmasi, ujung boleh salah satu saja, inklusif; (b) **User** satu pilihan dari endpoint: user yang punya minimal satu sesi terlihat (K-2); (c) **Folder** pilihan ganda dari endpoint, daftar datar folder yang boleh dilihat user (lokasi 01 ∩ View 02), nilai = array id datar; sesi cocok bila mencakup **salah satu** folder terpilih (definisi "mencakup" = BR-4, K-3) | R-28; `helpers/tableHelper.js:520-543` (`multiple`), `:675-720` (`dateTimeRange`) |
| BR-10 | Klik baris membuka **hasil sesi** (K-4): drawer inline di dalam drawer history; judul "Hasil Opname", subjudul Waktu · User · Scope; kartu Total dokumen, Verified sesi ini, Not found, Invalid, Belum discan; `Segmented` All / Scanned / Verified / Not Found / Invalid (+ jumlah), default **Scanned**; tabel Document No, Transaction Type, Folder ("Di luar scope opname" untuk Not found, "—" untuk Invalid), Time, Result; latar baris Not found/Invalid sama dengan Step 3. Read-only: tanpa peringatan Step 3, tanpa Back/Confirm. Tutup → kembali ke tabel history dengan halaman & filter yang sama | [S2 05b catatan "tabel yang sama dengan step Confirm Opname, termasuk filter"]; R-21, R-22; 03 BR-18..20 |
| BR-11 | Isi hasil sesi untuk user yang hanya melihat sebagian cakupan sesi: baris dokumen di luar scope-nya tidak tampil, jumlah `Segmented` dihitung dari baris yang tampil, kartu tetap angka rekaman + catatan "Sebagian dokumen di luar akses Anda tidak ditampilkan" (K-5) | PK-2 a; 04 K-2 a; R-11 |
| BR-12 | Akses: daftar history dan hasil sesi cukup permission **`List Archive`** (sudah ada; hanya di-seed untuk lisensi Salesman Activity), tidak butuh `Opname Document` (K-6; route `opnames/{id}`, `/documents` sudah `List Archive` dari 03). Tanpa `List Archive` → 403 `GE0114`, tanpa data. Select (folder, user) mengikuti konvensi select (tanpa `permission_v5`) tetapi hanya mengembalikan data dalam scope user | R-2; 01 K-4; 04 BR-15; `responses.md` §4 |
| BR-13 | Penolakan: konteks `id_archive` tidak ada/nonaktif → 404 `ARCHIVE400`; bukan folder → 400 `ARCHIVE417`; di luar scope lokasi / tanpa View → 403 `ARCHIVE407`; sesi tidak ada, draft milik user lain, atau sesi tidak terlihat → 404 `ARCHIVE412`; nilai query tidak valid (tanggal bukan `Y-m-d H:i:s`, folder bukan array) → 422. Tidak ada 500 | D-6; 01 `findVisible`; 02 `ArchivePermissionService::assert`; 03 contract §4/§6 |
| BR-14 | Selama drawer history terbuka, scanner pencarian halaman Archive (kamera/USB) nonaktif dan aktif lagi saat drawer ditutup, supaya scan tidak memicu pencarian list di belakang | `ArchiveDocumentHandOverModal/index.js:15-21`; `QRScanSearch/index.js:21,49` (setiap `Search` punya scanner aktif) |
| BR-15 | Tanpa sesi / tanpa hasil filter: tabel kosong standar (empty state `DefaultTable`), tanpa error | turunan BR-5 |

Kolom v3: tabel `archive*` tidak dipakai v3 (03 §2) dan item ini tidak menulis apa pun → tidak ada kolom v3 yang dijaga
(D-4/D-5).

## 3. Model data

| Hal | Isi |
|---|---|
| Tabel | **Tidak ada yang baru/berubah.** Dibaca (DB tenant): `archive_opnames`, `archive_opname_folders`, `archive_opname_documents` (03 §3), `archives` (01/02/03), `archive_permissions` (02) |
| Display setting | baru `Config/displayColumn/documentArchiveOpnameHistory.php` (`module_name` = `documentArchiveOpnameHistory`, isi persis di contract.md), didaftarkan di `Config/config.php` di samping `documentArchive` (`:331`). Module baru → baris `column_display_settings` dibuat otomatis saat request pertama (`MyHelper.php:419-427`, juga `ColumnDisplaySettingService.php:25-40`) → **tanpa Updater**. Panjang nama 28 karakter, preseden lebih panjang ada (`selectPurchaseInvoiceInReceiptNote.php:3`). `scope` bukan kolom tabel → lewat argumen `excepts` `selectBuilderQuery` (`CustomizeBuilder.php:259-296`) |
| `data_index` kolom | = nama kolom `archive_opnames` (camelCase): `confirmedAt`, `createdBy`, `totalDocuments`, `verifiedCount`, `notFoundCount`, `invalidCount`, `scannedCount`, `unscannedCount` + `scope` (objek). Alasan: `sortAll` & `selectBuilderQuery` hanya mengenal kolom tabel (`CustomizeBuilder.php:228`, `:273-286`) |
| Updater | Tidak ada |
| Permission | Tidak ada yang baru. `List Archive` (1082) untuk list; `GET opnames/{id}` & `/documents` sudah `List Archive` dari 03 (gate1 O-8; 03 men-seed `permission_checks (1133, 1082)`, jadi pemegang `Opname Document` juga punya `List Archive`). Item ini hanya memperluas visibilitas sesi terkonfirmasi (04 K-4 + EPIC K-1) |
| Kode pesan | **Tidak ada yang baru.** Dipakai ulang: `ARCHIVE200` (list), `SUCCESS` (select), `ARCHIVE400`/`ARCHIVE407` (01/02), `ARCHIVE412`/`ARCHIVE417` (03). `ARCHIVE430` tidak dipakai (K-5 = b) |
| Indeks | memakai indeks 03: `archive_opnames KEY (status, confirmed_at)`, `KEY created_by`; `archive_opname_folders KEY id_archive_opname`, `KEY (id_archive, id_archive_opname)` |
| Service | baru `OpnameService::index` (file 03) memakai **query sesi terlihat** dari 04 `VerificationService` (scope 04 K-4 + EPIC K-1, "mencakup F" K-3) - satu sumber dengan `lastSessions`; select di `SelectArchiveService` (`folder`, `opnameUser`). Pohon folder & `viewableFolderIds()` dihitung sekali per request (02), tanpa cache static (D-3) |

**Asumsi atas 03/04 (diperiksa epic-check):**

| # | Asumsi / ketidakcocokan |
|---|---|
| A-1 | 03 §3: sesi `archive_opnames` (`status` 2 = dikonfirmasi, `confirmed_at`, `created_by` = username, `id_archive` NULL = root, `scope_name`, `scope_folder_count`, `total_documents`, `verified_count`, `not_found_count`, `invalid_count`, `scanned_count`, `unscanned_count`); `archive_opname_folders` level 0-2 dengan snapshot `name`; `archive_opname_documents` memuat baris "belum discan" saat Confirm (03 K-9 a). Bila 03 K-9 = b, filter All/Belum discan di hasil sesi lama tidak bisa ditampilkan |
| A-2 | 03 `GET opnames/{id}` & `/documents` berlaku untuk sesi terkonfirmasi (03 §3 "Titik baca"); komponen `archive-components/ArchiveOpnameResult` (prop `defaultResult`) dipakai ulang; `getScopeTag()` di `archive.function.js` (03 §5, gate1 O-10) |
| A-3 | 04 `VerificationService` menyediakan query builder sesi terlihat (K-4) + filter "mencakup folder" (K-3) yang juga dipakai `lastSessions`; 05 tidak membuat aturan kedua |
| A-4 | Nama baris sesi: 04 `last_sessions` memakai nama kolom 03 (`confirmed_at`, `created_by`, `*_count`) + `scope` bentuk 03, sama dengan baris list 05 (satu formatter) - diselesaikan epic-check C-2, 04 diperbarui |
| A-5 | Visibilitas sesi dari root (level 0 `id_archive` NULL) dan sesi yang semua foldernya sudah dihapus = EPIC K-1 a (masuk 04 K-4): sesi root terlihat semua pemegang `List Archive`; sesi yang semua foldernya terhapus/tak terlihat hanya superadmin 1/2 & pembuat |
| A-6 | 04 K-5 a (baris overview 3 sesi tidak bisa diklik); bila b, 04 membuka drawer hasil sesi milik item ini |

## 4. Endpoint v5

Prefix `api/v5`. Route Archive di `Routes/DocumentArchive/api.php` (grup `document-archive`, `auth:api`, `:18-34`) +
route 03; select di `Routes/Select/select.php:2713-2718`. Bentuk lengkap: `contract.md`.

| Route | Controller@method | FormRequest | Service | Permission | Status |
|---|---|---|---|---|---|
| GET `document-archive/opnames` | `OpnameController@index` (03) | - (nilai `search` divalidasi di service, 422) | `OpnameService::index($request)`: `getDisplaySetting('documentArchiveOpnameHistory')` → query sesi terlihat 04 (+ `id_archive` konteks: `findVisible` 01, bukan folder 400, `assert` View 02) → `query` (username / nama folder BR-8) → `confirmedAt` / `createdBy` / `idArchives` → `sortAll` atau `confirmed_at desc` → paginate → `scope` → `array_merge` (blueprint `SalesmanActivityService.php:18-87`) | `List Archive` | baru |
| GET `document-archive/opnames/{id}` | `OpnameController@show` (03) | - | `OpnameService::show`: sesi terkonfirmasi wajib terlihat (04 K-4 + EPIC K-1; 03 hanya pemilik + superadmin 1/2) → selain itu 404 `ARCHIVE412`; draft tetap hanya pemilik; tambah `result_counts`, `is_partial` (K-5 b) | `List Archive` (sudah dari 03) | ubah |
| GET `document-archive/opnames/{id}/documents` | `OpnameController@documents` (03) | - | `OpnameService::documents`: cek sama dengan `show`; sesi terkonfirmasi difilter ke baris dalam scope user (K-5 b) | `List Archive` (sudah dari 03) | ubah |
| GET `select/document-archive/archive/folders` | `SelectArchiveController@folder` | - | `SelectArchiveService::folder`: folder `type=1`, `is_active=1`, dalam `viewableFolderIds()` 02 (superadmin semua), `search` nama, `selected_id`, label = path (K-3 iii), urut label | - (select) | baru |
| GET `select/document-archive/archive/opname-users` | `SelectArchiveController@opnameUser` | - | `SelectArchiveService::opnameUser`: `DISTINCT created_by` sesi terkonfirmasi terlihat (query 04), `search`, `selected_id`, urut username | - (select) | baru |

`GET opnames` didaftarkan bersama route 03 (`Route::get('/', …)` tidak bentrok dengan `opnames/folders` maupun
`opnames/{id}`). Controller baca tanpa transaksi (`ArchiveController.php:27-30`). Select `{default, options}`
(`SelectArchiveService.php` pola `type()`); `search` select = string biasa (FE `AsyncSelect` `APISearch` bawaan true,
`components/Select/AsyncSelect/index.js:50,167-169`). `select/document-archive/archive/users` milik 02 **tidak**
dipakai: ia membuang superadmin 1/2 dan berbasis permission, bukan pelaku opname (02 K-9).

## 5. Perubahan FE per module

**Archive** (`src/containers/Archive`, master dengan aksi baris kustom). Struktur & alur dari desain 05b, tampilan
mengikuti EQUAL (`knowledge/design-sources.md`; filter desain diganti R-25). Blueprint: wadah inline =
`components/ModalImportExportExcel/ModalImportExportExcelDrawerHistory/index.js:28-40` (dipakai di modal dan drawer:
`ModalImportExportExcelContent/index.js:8`, `DrawerImportExportExcelContent/index.js:7`), drawer bersarang =
`.../PreviewDrawer/index.js:21-40`, tabel v5 di dalam drawer = `SalesmanItem/salesman-item-components/SalesmanItemSalesOrder/index.js:10-75`.

| Bagian | Perubahan |
|---|---|
| Baru `archive-components/ArchiveOpnameHistory/index.js` | `forwardRef`, `onOpen({ idArchive, name })` / `onClose`; `Drawer` (components) `width="100%"`, `className="ant-drawer-inline drawer-history"`, `getContainer` = elemen wadah dari prop `container` (ref), `destroyOnClose`; judul + subjudul BR-2; buka → `qrScanSearchRef.current.setActiveScanner(false)`, tutup → `true` (BR-14) |
| Baru `ArchiveOpnameHistory/Content.js` | `useTable(fetch.getArchiveOpnames, { form, params: { idArchive }, displaySettingId: displaySetting.documentArchiveOpnameHistory.name })`, `useColumns`; render kolom `scope` dengan `getScopeTag()` 03 dan `confirmedAt` tanggal + jam (format tampilan yang ada); `ButtonDisplaySetting` (2x seperti blueprint), `FilterWrapper canAdd={false} isSettingModule={false}` + `Search isInsideModal` (query dari server), `DefaultTable rowKey="idArchiveOpname"` dengan `onRow` klik → drawer hasil (preseden `onRow`: `ModalImportExportExcelContent/Preview.js:40`), `DisplaySettingDrawer canUpdate` + `onSuccessUpdateInModule` (seperti `ArchivePage/index.js:545-546`). Tanpa teks jumlah sesi |
| Baru `ArchiveOpnameHistory/ResultDrawer.js` | drawer bersarang `getContainer={false}`, `width="100%"`, `destroyOnClose`; judul "Hasil Opname" + subjudul Waktu · User · Scope; `getArchiveOpname` (03) → kartu 5 angka (`NumberLocale`) + catatan BR-11 bila `isPartial`; `ArchiveOpnameResult` (03) `defaultResult="scanned"`, jumlah `Segmented` dari `resultCounts`; tanpa peringatan & tombol aksi |
| `ArchiveVerificationModal` (04) | pasang `ArchiveOpnameHistory` (ref) dengan wadah = isi modal; `onOpenHistory={() => history.current.onOpen({ idArchive: null })}` ke `ArchiveVerification` (04 K-6 a) |
| `ArchiveDrawer` tab Verification (02/04) | idem dengan wadah = isi drawer; `onOpen({ idArchive: id, name })` |
| `ArchivePage` | teruskan `qrScanSearchRef` (`ArchivePage/index.js:41`) ke modal 05 & `ArchiveDrawer` sampai `ArchiveOpnameHistory` |
| `archive.function.js` | helper render kolom history (scope, waktu) + test di `archive.function.test.js` |
| Registrasi | `configuration/endpoints.js` grup Archive (`:58-67`): `getArchiveOpnames: 'document-archive/opnames'` (endpoint select dipakai lewat string `endpoint` di `queries`, tanpa kunci FE); `archive.api.js`: `useAPI`; `containers/DisplaySetting/displaySetting.constant.js` sesudah `documentArchive` (`:163`): `documentArchiveOpnameHistory: { name: 'documentArchiveOpnameHistory', label: 'archive.Opname History' }`; locale `archive.*` di `entries/en-US.js` + `id-ID.js` (judul, subjudul, "Hasil Opname", catatan BR-11). Tanpa route/menu/permission FE baru (halaman sudah dijaga `ListArchive`) |

## 6. Acceptance criteria

QA DB `api_sidomaju`; sesi dibuat lewat endpoint opname 03 (draft, scan, confirm, cancel) oleh superadmin dan user2
(lokasi/View terbatas), dipulihkan sesudahnya (P-4). Lisensi (EPIC K-2 b): AC-14 diuji dengan role tanpa
`List Archive`; tukar lisensi sungguhan sekali, manual, di Gate 2 epic.

| AC | Given / When / Then | Layer | BR | Cek |
|---|---|---|---|---|
| AC-1 | When `GET opnames`, Then 200 `ARCHIVE200`, paginator + `columns` (7 kolom urut BR-6) + `queries` (`confirmedAt` dateTimeRange, `createdBy` select endpoint opname-users, `idArchives` select endpoint folders `multiple`); baris `column_display_settings` `documentArchiveOpnameHistory` terbentuk; urut `confirmed_at` turun | BE | 5,6,7 | http + DB `column_display_settings` |
| AC-2 | Given sesi terkonfirmasi S, Then baris S: angka = kolom `archive_opnames` S, `scope` = `scope` `GET opnames/{S}`; Given dokumen ditambah ke folder S sesudahnya, Then angka S tidak berubah | BE | 6 | http + SQL |
| AC-3 | Given satu draft dan satu sesi batal, Then keduanya tidak ada di list (juga untuk pembuatnya) | BE | 3 | http |
| AC-4 | `search={"query":…}`: sebagian username cocok; nama sub-subfolder (level 2) sesi cocok; huruf besar/kecil sama; user2: nama folder yang tidak boleh ia lihat tidak mencocokkan (K-3 i) | BE | 8 | http |
| AC-5 | `confirmedAt` [awal, akhir] inklusif; hanya awal; hanya akhir; tanggal tidak valid → 422 (bukan 500) | BE | 9,13 | http |
| AC-6 | `createdBy` = satu username; `idArchives` = [F1,F2] → sesi yang mencakup F1 **atau** F2 (termasuk sesi yang hanya mencakup subfolder F1 bila K-3 ii); gabungan dengan `query` = AND; `idArchives` bukan array → 422 | BE | 9 | http |
| AC-7 | `sorts` tiap kolom sortable naik/turun benar; sort `scope` diabaikan tanpa 500 | BE | 7 | http |
| AC-8 | Given superadmin & user2, Then superadmin melihat semua sesi terkonfirmasi; user2 hanya sesi sesuai 04 K-4; 3 baris pertama halaman 1 = `last_sessions` `GET verifications` untuk user yang sama | BE | 3 | http dua user |
| AC-9 | `id_archive=F`: hanya sesi yang mencakup F; 3 baris pertama = `last_sessions` `GET verifications/{F}`; id tak ada → 404 `ARCHIVE400`; id dokumen → 400 `ARCHIVE417`; F di luar lokasi / tanpa View → 403 `ARCHIVE407` | BE | 4,13 | http |
| AC-10 | `select/…/archive/folders`: user2 hanya folder aktif dalam lokasi ∩ View (tanpa dokumen, tanpa folder nonaktif), label path, `search` nama, `selected_id` ikut; superadmin semua folder aktif | BE | 9 | http dua user |
| AC-11 | `select/…/archive/opname-users`: username unik dari sesi terkonfirmasi yang terlihat pemanggil (termasuk superadmin bila ia pelaku), user tanpa sesi tidak muncul, `search`, urut | BE | 9 | http |
| AC-12 | Given role dengan `List Archive` tanpa `Opname Document`, When `GET opnames/{S}` & `/documents` sesi terkonfirmasi terlihat, Then 200; sesi tidak terlihat / tidak ada → 404 `ARCHIVE412`; draft user lain → 404 `ARCHIVE412`; alur opname 03 (Step 2/3) tetap jalan untuk pemegang `Opname Document` | BE | 12,13 | http + regresi 03 |
| AC-13 | Given sesi root oleh superadmin mencakup lokasi lain, When user2 `GET opnames/{S}/documents?result=all`, Then hanya baris dalam scope user2 (+ Invalid), `result_counts` = jumlah baris itu per filter, `is_partial=true`; superadmin: semua, `is_partial=false`, `result_counts` = `counts` | BE | 11 | http dua user |
| AC-14 | Role tanpa `List Archive` → 403 `GE0114` pada `GET opnames`, `opnames/{id}`, `opnames/{id}/documents`, tanpa data | BE | 12 | cara seragam EPIC K-2 b: http role tanpa `List Archive`; seed tidak berubah (permission Archive hanya di `permission_salesman.sql`); FE tanpa route/menu baru (guard lisensi yang ada dicek di kode); tukar lisensi sungguhan sekali, manual, di Gate 2 epic |
| AC-15 | Modal 05 → "Lihat semua history" → drawer inline menutupi modal tanpa error; judul/subjudul All Archive; tidak ada teks "N sesi"; satu request `GET opnames` tanpa `idArchive`; ✕ kembali ke isi modal 05 | FE | 1,2,5 | e2e Archive, profil default |
| AC-16 | Drawer folder F → tab Verification → CTA → drawer inline menutupi drawer folder tanpa error; request membawa `idArchive=F`; subjudul nama F; ✕ kembali ke tab Verification | FE | 1,2,4 | e2e |
| AC-17 | Search bar ketik username → request `search` `{"query":…}`; filter popover terbuka tanpa error; select User & Folder memuat opsi dari endpointnya tanpa error; rentang waktu mengirim `confirmedAt` `["YYYY-MM-DD HH:mm:ss", …]`; Folder multi mengirim `idArchives` array; Reset filter mengosongkan | FE | 8,9 | e2e (cek request) |
| AC-18 | Display Setting terbuka tanpa error; sembunyikan kolom & tampilkan kolom tersedia (Scanned) → tabel ikut; ganti halaman → `page=2`; klik header kolom → `sorts` | FE | 5,7 | e2e |
| AC-19 | Sel Scope: root → "All Archive · root", folder + subfolder → "<nama> + N subfolder", tanpa subfolder → nama; Waktu tampil tanggal + jam | FE | 6 | unit (`archive.function.test.js`) |
| AC-20 | Klik baris → drawer hasil tanpa error: `GET opnames/{id}` + `/documents?result=scanned`; kartu 5 angka; `Segmented` default Scanned + jumlah; ganti filter → request `result` baru; Not found "Di luar scope opname", Invalid "—", latar baris khusus; ✕ kembali ke tabel dengan halaman & filter sama | FE | 10 | e2e |
| AC-21 | User2 (`List Archive` tanpa `Opname Document`) membuka history dan hasil sesi tanpa error; pada sesi parsial catatan BR-11 tampil | FE | 11,12 | e2e profil user2 |
| AC-22 | Saat drawer history terbuka, scan USB (ketikan cepat + Enter di luar input) tidak memicu request `GET archives`; sesudah ditutup scan memicu pencarian list lagi | FE | 14 | e2e |
| AC-23 | Tanpa sesi → tabel kosong; filter tanpa hasil → kosong, Reset mengembalikan data | FE | 15 | e2e |
| AC-24 | Regresi: modal 05 & tab Verification (04) tetap tampil benar; opname 03 Step 2/3 tetap memuat hasil; list Archive, scan pencarian, mode Select tetap jalan | BE+FE | - | e2e |
| AC-25 | Struktur & alur sesuai desain 05b (kecuali filter, R-25/26); tampilan mengikuti EQUAL | FE | 2,5,6,10 | manual (Gate 2) |

## 7. Subtask

| Kunci | Judul | Layer | AC | Module |
|---|---|---|---|---|
| ED-1034 | [BE] Display setting & endpoint daftar sesi Opname History | BE | 1-9, 14 | DocumentArchive (`OpnameController@index`, `OpnameService::index`, `Config/displayColumn`, `Config/config.php`) |
| ED-1040 | [BE] Select folder & user untuk query Opname History | BE | 10, 11 | Select/DocumentArchive |
| ED-1046 | [BE] Akses baca hasil sesi terkonfirmasi (visibilitas sesi & saring baris `opnames/{id}`, `/documents`) | BE | 12-14 | DocumentArchive (`OpnameService::show/documents`) |
| ED-1052 | [FE] Archive - drawer inline Opname History (tabel v5) & CTA modal 05 / tab Verification | FE | 15-19, 22, 23 | Archive |
| ED-1058 | [FE] Archive - hasil satu sesi dari Opname History | FE | 20, 21 | Archive |
| ED-1062 | [QA] Skenario Opname History | QA | semua | - |

## 8. Keputusan untuk developer

| # | Pertanyaan | Opsi | Rekomendasi | Risiko | Jawaban Gate 1 |
|---|---|---|---|---|---|
| K-1 | History dari tab Verification folder F (G-5) | a) konteks terkunci: hanya sesi yang mencakup F (param `id_archive`), subjudul nama F, query Folder tetap bisa mempersempit; kolom tetap angka sesi (bukan potongan folder seperti overview tab 04); b) seperti a tetapi menambah kolom "Dokumen folder" & "Verified di folder ini" (dari FE, di luar display setting); c) query Folder terisi F dan bisa dihapus (jadi All); d) selalu All Archive | a - "Lihat semua" = kelanjutan 3 sesi tab itu; satu display setting | rendah | **Jawaban Gate 1 (2026-10-06):** a - G-5 |
| K-2 | Isi select **User** | a) username pelaku sesi terkonfirmasi yang terlihat pemanggil (termasuk superadmin/TS dan user nonaktif); b) select 02 `archive/users` (user aktif ber-`List Archive`, tanpa superadmin 1/2); c) semua user aktif | a - brief: "menampilkan siapa yang mengopname"; b menyembunyikan opname superadmin | rendah | **Jawaban Gate 1 (2026-10-06):** a - G-2 (daftar pelaku opname dengan superadmin) |
| K-3 | Rincian search & folder | (i) nama folder dicocokkan dengan nama saat sesi (snapshot 03) dan hanya folder yang boleh dilihat user (superadmin semua); (ii) filter Folder F juga mencocokkan sesi yang hanya mencakup subfolder F - mengikuti jawaban 04 K-3; (iii) opsi folder = folder aktif yang terlihat, label path "Induk / … / Folder" (nama folder bisa kembar), cari per nama; (iv) rentang waktu tanpa nilai awal (semua sesi, terbaru dulu, ber-pagination) - default "bulan ini" desain tidak diikuti | ya untuk keempatnya | rendah | **Jawaban Gate 1 (2026-10-06):** ya untuk keempatnya - rekomendasi; (ii) = 04 K-3 a (G-5) |
| K-4 | Tampilan hasil satu sesi (tanpa desain, G-5) | a) drawer inline bersarang di drawer history: kartu 5 angka + `Segmented` default **Scanned** + tabel Step 3, tanpa peringatan & aksi, dibuka klik baris; b) seperti a, default **All**; c) a + peringatan per folder Step 3 dalam bentuk lampau ("96 dokumen verified menjadi unverified") | a - sama dengan Step 3 (R-21) dan catatan desain 05b | rendah | **Jawaban Gate 1 (2026-10-06):** a - G-7 |
| K-5 | Isi hasil sesi bagi user yang hanya melihat sebagian cakupan (sesi root/induk oleh superadmin; berlaku bila 04 K-4 = a/b) | a) semua baris (nomor dokumen & nama folder lokasi lain terlihat); b) baris dokumen hanya bila dokumennya lolos scope lokasi 01 dan folder cakupannya boleh di-View 02 (atau root); Invalid selalu; jumlah filter dari baris tampil; kartu = angka rekaman + catatan; c) minimal: hasil hanya bisa dibuka bila seluruh folder sesi terlihat (superadmin & pembuat selalu), selain itu 403 `ARCHIVE430` | b - sejalan PK-2 a dan 04 K-2 a (angka hanya dari yang terlihat) | tinggi | **Jawaban Gate 1 (2026-10-06):** b - G-1 |
| K-6 | Permission membaca hasil sesi terkonfirmasi (03 menyerahkan ke item ini) | a) `GET opnames/{id}` & `/documents` 03 diubah ke `permission_v5:List Archive` + cek visibilitas sesi (04 K-4); pemegang `Opname Document` tidak terdampak (03 `permission_checks` ke 1082); b) endpoint baca baru khusus history (`List Archive`) yang memanggil service 03; c) tetap `Opname Document`: tanpa itu baris history tidak bisa diklik | a - tanpa endpoint kembar; overview 04 sudah terbuka untuk `List Archive` | tinggi | **Jawaban Gate 1 (2026-10-06):** a - G-4; permission `List Archive` dipasang 03 (gate1 O-8), item ini hanya cek visibilitas |
| K-7 | Display setting (nama harus sama BE & FE, `module-types.md`) | `module_name` `documentArchiveOpnameHistory`; 7 kolom aktif sesuai desain; kolom tersedia tambahan Scanned & Belum discan; 3 query aktif (tanpa query tersedia lain) | setujui | rendah | **Jawaban Gate 1 (2026-10-06):** setujui - rekomendasi |
| EPIC K-1 | Sesi dari root & sesi yang foldernya sudah dihapus - siapa melihatnya (A-5, gate1 §7) | a) root terlihat semua pemegang `List Archive` (hasil disaring K-5 b), sesi yang semua foldernya terhapus/tak terlihat hanya superadmin 1/2 + pembuat; b) 04 K-4 a apa adanya (sesi root tanpa subfolder dicentang hanya superadmin 1/2 + pembuat); c) semua sesi untuk semua pemegang `List Archive` | a | tinggi | **Jawaban Gate 1 (2026-10-06):** a - rekomendasi; aturan di 04 K-4, dipakai BR-3 |
| EPIC K-2 | Cara cek AC lisensi (`PROFILES` kosong) | a/b/c di gate1 §7 | b | rendah | **Jawaban Gate 1 (2026-10-06):** b - rekomendasi; cara seragam di AC-14 |

## 9. Di luar cakupan

- Tabel & penulisan sesi opname, endpoint `opnames/*` selain perluasan visibilitas di §4 (permission `List Archive` kedua route baca juga milik 03, gate1 O-8), komponen `ArchiveOpnameResult`,
  `getScopeTag()`, gaya baris invalid → **ED-1026**.
- Overview 3 sesi, CTA "Lihat semua history" + prop `onOpenHistory`, aturan sesi terlihat (04 K-4) & "mencakup folder"
  (04 K-3), `VerificationService` → **ED-1027** (item ini memakai, tidak mendefinisikan ulang).
- Scope lokasi & `findVisible` → **ED-1024**; hak View, `viewableFolderIds()`, tab drawer → **ED-1025**;
  Set Permission by User → **ED-1029**.
- Export/cetak history, menghapus/membatalkan sesi terkonfirmasi, filter per hasil (mis. hanya sesi dengan Invalid),
  history di aplikasi mobile.
- R-1..R-23 milik item lain (`epics/ED-1022-plan.md`); R-24..R-28 semuanya di BR-1..9 di atas.

## Catatan implementasi

- (BE, ED-1034) `OpnameService::index` memakai `VerificationService::context()/sessionQuery()/subtreeIds()/whereCovers()`
  dan `visibleFolder()` (dibuat `public`, isi tidak berubah) - satu sumber dengan `last_sessions`; urutan bawaan dan
  pemecah seri `confirmed_at desc, id_archive_opname desc`. Baris = `VerificationService::sessionRow()` + `id_archive`,
  `scanned_count`, `unscanned_count`, disaring ke kolom aktif display setting (+ `id_archive_opname`, `id_archive`, `scope`).
  `ArchiveOpname` diberi trait `CustomizeBuilder` untuk `selectBuilderQuery`.
- (BE, ED-1034) Sort **tidak** lewat `sortAll`: `sortAll` 500 untuk elemen sort berbentuk array (`Str::snake(array)`) dan
  untuk nama atribut relasi; diganti whitelist `HISTORY_SORTS` + `orderBy` (kontrak §1 "lain diabaikan").
- (BE, ED-1034) Kolom `archive_*` berkolasi latin1: teks `query`/`createdBy` di luar latin1 membuat `LIKE`/`=` gagal kolasi
  (500); diperlakukan tidak cocok (200 kosong). `%`/`_` di `query` harfiah. `search` bukan JSON objek -> 422 (kontrak §1).
- (BE, ED-1046) Sesi batal tetap bisa dibaca pemiliknya seperti 03 (skenario QA 03 "sesi batal milik sendiri dapat
  dibaca"); kontrak §2 "Batal: 404" diperjelas menjadi "milik user lain 404".
- (BE, ED-1046) `result_counts` selalu dari baris snapshot (juga superadmin): sama dengan `counts` kecuali dokumen bernama
  ganda yang dicatat satu baris saat Confirm; saringan K-5 b juga berlaku bagi pembuat sesi non-superadmin (mis. baris
  Not found dokumen di luar lokasinya) - kontrak §2/§3.
- (BE, ED-1040) Select `folders`/`opname-users`: saringan `search` dan `selected_id` dilakukan di PHP atas daftar dalam
  scope (tanpa SQL `LIKE`, jadi aman kolasi); `selected_id` di luar scope tidak ikut. Label folder = path lengkap dari
  root seperti breadcrumbs folder yang sudah ada (nama induk ikut walau induknya tidak terlihat user).
