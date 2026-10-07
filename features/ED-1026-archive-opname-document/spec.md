---
key: ED-1026
epic: ED-1022
title: Archive - Opname Document per folder (pilih folder, scan, konfirmasi)
status: approved
batch: ED-1026
fe_modules: [Archive]
new_fe_modules: []
be_modules: [DocumentArchive, UpdateVersion]
qa_model: sonnet
contract: contract.md
depends_on: [ED-1024, ED-1025]
---
## 1. Ringkasan
User Archive memastikan dokumen mana yang benar-benar ada fisiknya lewat opname per folder per hari: pilih folder
(root/folder) + subfolder langsungnya, scan QR/barcode (kamera/scanner USB), lalu konfirmasi. Tiap dokumen berstatus
verified/unverified dari opname terakhir yang mencakupnya; "Lanjut opname" menentukan opname ulang di hari yang sama
menimpa atau melanjutkan. Sesi terkonfirmasi disimpan lengkap (folder, angka, hasil per dokumen) untuk 04/05. Opname
hanya menyentuh yang terlihat user (lokasi 01 + View 02): opname per lokasi oleh user berbeda tidak saling menimpa.

## 2. Aturan bisnis
| BR | Aturan | Sumber |
|---|---|---|
| BR-1 | Opname per folder, modal 3 langkah (Select Folder, Opname Folder, Confirm), hasil Verified / Not found / Invalid (bukan opname global S4) | R-1, R-4, R-5; S2 04a-c; brief K-1/K-2 (desain menang) |
| BR-2 | Permission baru `Opname Document` (module 1266, seed hanya lisensi Salesman Activity) menjaga menu "+"/baris Opname dan endpoint opname selain baca sesi (`GET opnames/{id}`, `/documents` = `List Archive`); tanpa: menu hilang, 403 `GE0114`. Folder opname wajib terlihat + View efektif (01/02), selain itu 403 `ARCHIVE407` | R-2; `permission_salesman.sql:67-76`; `ApplicationSetupService.php:223-238`; K-6; gate1 O-8 |
| BR-3 | Titik masuk: "+" → Opname Document (di root = scope "All Archive · root"; di dalam folder = folder aktif); menu ⋯ baris folder → Opname (urutan Info, Pindahkan, Lihat, Opname, Hapus). Baris dokumen: tanpa Opname | R-3; S2 01 (menu & catatan), S2 04 catatan |
| BR-4 | Cakupan sesi = folder opname F (atau root) + subfolder langsung yang dicentang di Step 1, masing-masing beserta seluruh turunannya (sub-subfolder ikut otomatis, tidak bisa dipilih). Dokumen langsung di F selalu ikut (K-3); opname dari root mencakup dokumen tanpa folder (K-4) | R-5; S2 04a teks info |
| BR-5 | Subfolder/turunan di luar scope user (lokasi 01 ∩ View 02, `viewableFolderIds()`) tidak tampil/tercentang/ikut sesi dan data opname-nya tetap; dokumen tak terlihat user (scope 01 per baris) tidak dihitung. Superadmin 1/2 melihat semua | R-11; K-5; item 01 BR-1..5; item 02 §3 |
| BR-6 | Dokumen dihitung = archive dokumen `is_active = 1` (seperti list, `ArchiveService.php:29`) di folder tercakup, status apa pun (In folder/Handed Over/Taken); `-1` (tercetak belum disimpan) dan `0` tidak | S2 01 (dokumen Taken/Handed Over berikon verified); `DocumentService.php:133` |
| BR-7 | "Hari ini" = tanggal kalender server Asia/Jakarta untuk semua lokasi. Folder = "Sudah diopname hari ini · HH:mm" bila folder itu tercakup sesi terkonfirmasi hari ini (jam = konfirmasi terakhir); ganti hari → "Belum diopname" | R-6; S2 04a; `app/Providers/AppServiceProvider.php:20`; K-2 |
| BR-8 | Step 1: alert info desain; tabel centang Folder · Document (jumlah BR-5/6 se-subtree) · Opname hari ini; baris pertama = folder opname sendiri, terkunci tercentang (K-3); subfolder belum diopname hari ini tercentang, yang sudah tidak; centang-semua; footer "N folder dipilih · M dokumen" (M = total sesi); Cancel, Next | R-9; S2 04a |
| BR-9 | Subfolder tak dicentang: folder & dokumennya tidak masuk hitungan sesi, verified-nya tetap | R-10 |
| BR-10 | Folder sudah diopname hari ini yang dicentang (ulang): label "(dicentang ulang)", toggle Lanjut opname default aktif, teks akibat sesuai posisi toggle (teks desain) | R-13, R-14; S2 04a |
| BR-11 | Saat Confirm, per folder tercakup: discan Verified → verified. Tidak discan: Lanjut aktif → tetap; Lanjut nonaktif **atau** folder belum diopname hari ini (tanpa toggle) → unverified walau sebelumnya verified. Nilai Lanjut baris Step 1 berlaku untuk seluruh turunannya | R-13, R-14; K-2 |
| BR-12 | Step 1 dilewati bila F tidak punya subfolder yang terlihat **dan** F belum diopname hari ini; stepper tinggal 2 langkah, tombol kiri Step 2 = Cancel | S2 04 catatan; K-3 |
| BR-13 | Klasifikasi (kode = nomor dokumen = `archives.name`, seperti hand-over `DocumentService.php:72`): **Verified** = dokumen dalam cakupan (BR-4..6); **Not found** = archive dokumen bernama kode itu ada, tetapi di luar cakupan (folder lain, root, `-1`, `0`, tak terlihat user); **Invalid** = tidak ada (termasuk nama folder) | S2 04b keterangan; K-7 |
| BR-14 | Scan ulang kode yang sama dalam satu sesi: tanpa baris, angka, atau pesan baru | K-11 (i) |
| BR-15 | Bentuk modal: `ModalAdd` FE; judul "Opname Document" (Step 1-2), "Confirm Opname" (Step 3); tag di samping judul: Step 1 = "All Archive · root" / nama folder opname; Step 2-3 = subfolder pertama yang dicentang + " + N subfolder" (N = sisa), tanpa subfolder dicentang = tag Step 1 | R-12, R-15; S2 04a-c |
| BR-16 | Input "Scan QR / barcode dokumen" menerima scanner USB (keyboard, fokus di input atau tidak) dan ketik + Enter; ikon `ScanOutlined` (sama dengan `QRScanSearch`) membuka modal kamera (`ArchiveScanner`) yang tetap terbuka sesudah scan sampai ditutup; USB tanpa modal; selama modal opname terbuka scanner pencarian Archive nonaktif | R-18..R-20; `components/QRScanSearch/index.js:66`; K-11 (iv) |
| BR-17 | Step 2: kartu "Status verifikasi saat ini X / Y" (verified di cakupan sebelum sesi) + progress; kartu "Sesi opname berjalan" (scan, verified, not found, invalid + keterangan); "Scan result" = antd `Segmented` warna bawaan All/Verified/Not found/Invalid (+ jumlah); tabel Document No, Transaction Type, Salesman, Time, Result; tanpa alert terpisah; footer "<tag> · M dokumen", Back, Finish Opname | R-16; S2 04b |
| BR-18 | Baris Invalid & Not found berlatar khusus di tabel Step 2/3 lewat kelas baru modal opname (acuan `.row-error` di `.modal-import-export-excel`, `styles/themes/metronic/modal.less:115`); kelas import/export tidak diubah | R-17 |
| BR-19 | Step 3: kartu Total dokumen, Verified sesi ini, Not found, Invalid, Belum discan; antd `Segmented` All/Scanned/Verified/Not Found/Invalid (+ jumlah), default Scanned; tabel Document No, Transaction Type, Folder (Not found: "Di luar scope opname", Invalid: "—"), Time, Result; peringatan per folder yang menurunkan verified; Back, Confirm Opname | R-21, R-22; S2 04c; K-11 (vi) |
| BR-20 | Total = dokumen cakupan; Belum discan = Total − Verified sesi ini; Scanned = semua baris scan; All = cakupan + Not found + Invalid (angka contoh desain tak konsisten) | S2 04c |
| BR-21 | Sesi berjalan = draft di server milik pembuatnya (hanya ia yang scan/ubah/konfirmasi/batal); Cancel/tutup modal = batal. Draft & sesi batal tidak mengubah verified, tidak dihitung "sudah diopname". Sesi terkonfirmasi dibaca pemiliknya + superadmin 1/2 (05 memperluas) | K-1; gate1 O-8 |
| BR-22 | Confirm menilai ulang cakupan dan tiap hasil scan di server (dokumen yang dipindah selama sesi ikut berubah hasilnya) | K-1 |
| BR-23 | Confirm ditolak 400 `ARCHIVE414` bila folder tercakup sudah dikonfirmasi sesi lain sesudah folder sesi ini dipilih; draft yang dimulai di hari lain ditolak 400 `ARCHIVE415` | R-6, R-11; K-8 |
| BR-24 | Not found & Invalid tidak mengubah dokumen apa pun (folder, status, verified) | S2 04b/05 (dihitung per sesi saja) |
| BR-25 | Sesi terkonfirmasi menyimpan waktu, user, scope, angka, folder tercakup (jumlah dokumen & verified saat itu) dan hasil per dokumen | S2 02/05/05b catatan "jumlah dokumen direkam sesuai kondisi folder saat itu"; K-9 |
| BR-26 | Verified tidak berubah karena pindah / hand over / receive; dokumen baru unverified | K-10 |
| BR-27 | Confirm berhasil: pesan sukses, modal tertutup, list Archive dimuat ulang | pola `ArchivePage/index.js:486-534` (`refresh` di `onSuccessAdd`) |

Kolom v3: tabel `archive*` tidak dipakai v3 (grep `Modules/*` selain V5/UpdateVersion = 0), tidak ada yang dijaga (D-5).

## 3. Model data (DB tenant, `latin1`/`latin1_general_ci` seperti `archives`; semua `id_*`/`*_by` = `VARCHAR(30)`, PK = `MyHelper::generateId`)
| Perubahan | Detail |
|---|---|
| `archives` + kolom (baris dokumen) | `is_verified TINYINT(2) DEFAULT 0`, `verified_at DATETIME NULL`, `verified_by` (username), `id_archive_opname NULL` (sesi terakhir yang menetapkan status); indeks baru `id_archive_parent` (kini hanya PK & `name`; 49 rb baris di QA DB). Baris lama = unverified |
| Baru `archive_opnames` (sesi) | PK `id_archive_opname`, `id_archive NULL` (folder opname; NULL = root), `status TINYINT DEFAULT 1` (1 berjalan, 2 dikonfirmasi, 3 dibatalkan; `STATUS_*` di model), `scope_name VARCHAR(255) NULL` (subfolder pertama dicentang / nama F; NULL = root), `scope_folder_count`, `total_documents`, `verified_before_count`, `scanned_count`, `verified_count`, `not_found_count`, `invalid_count`, `unscanned_count`, `unverified_count` (`INT DEFAULT 0`, final saat Confirm), `selected_at DATETIME` (BR-23), `confirmed_at DATETIME NULL`, `created_at/by`, `updated_at/by`; `KEY (status, confirmed_at)`, `KEY created_by` |
| Baru `archive_opname_folders` | PK `id_archive_opname_folder`, `id_archive_opname NOT NULL`, `id_archive NULL` (NULL = root), snapshot `id_archive_parent NULL` & `name VARCHAR(255) NULL`, `level TINYINT` (0 folder opname, 1 subfolder dicentang, 2 turunan otomatis), `is_continue`, `is_opnamed_today` (`TINYINT(2) DEFAULT 0`), `total_documents`, `verified_count` (discan sesi ini), `verified_after_count`, `unverified_count` (`INT DEFAULT 0`, dokumen langsung di folder itu), `created_at`; `KEY id_archive_opname`, `KEY (id_archive, id_archive_opname)`. Draft: level 0-1; Confirm menulis ulang 0-2 dengan angka final |
| Baru `archive_opname_documents` | PK `id_archive_opname_document`, `id_archive_opname NOT NULL`, `id_archive NULL` (NULL = Invalid), `id_archive_folder NULL` (folder dalam cakupan; NULL = root/di luar), `code VARCHAR(255)` (teks scan / nomor dokumen), `result TINYINT` (1 verified, 2 not found, 3 invalid, 4 belum discan), `is_verified_after TINYINT(2) NULL`, `scanned_at DATETIME NULL` (waktu server), `created_at`; `UNIQUE (id_archive_opname, code)`, `KEY (id_archive_opname, result)`, `KEY id_archive`. Baris 1-3 saat scan; 4 saat Confirm (K-9) |
| Updater | `Updaters/<Y_m_d_H_i_s>_AddArchiveOpname.php` + `config.php`; `CREATE TABLE/ADD/ADD INDEX IF NOT EXISTS`, semua tenant (pola `2025_11_04_10_02_59_RelatedEmployeeArchive.php`) |
| Model | baru `ArchiveOpname`, `ArchiveOpnameFolder`, `ArchiveOpnameDocument` (`Entities/Models`, pola `Archive.php`); `Archive`: 4 kolom baru ke `$fillable` (`Archive.php:18-33`) |
| Permission | `permission_salesman.sql`: `(1133,1266,'Opname Document','Opname Dokumen','Opname Document',10,1)` (max global 1132, `permissions.sql`, cek ulang saat dev; satu-satunya permission baru epic); `permission_checks` `(1081,1133)`, `(1133,1082)`; `permission_unchecks` `(1133,1081)`, `(1081,1133)`, `(1082,1133)` (pola baris 1090: `:137-145`, `:214-225`) |
| Kode pesan (en_EN + id_ID, blok `ARCHIVE` `en_EN.php:3345-3363`, `id_ID.php:3222-3240`) | sukses `ARCHIVE211`-`214` (sesi disimpan, discan, dikonfirmasi, dibatalkan); error `ARCHIVE412` (404 sesi tidak ada), `413` (400 bukan draft), `414` (400 bentrok), `415` (400 hari lain), `416` (400 bukan subfolder langsung), `417` (400 bukan folder). Pakai ulang `ARCHIVE400` (404), `ARCHIVE407` (403, 01). Teks: contract.md |
| Display setting | tidak ada (kolom Document Verified = 04; tabel modal berkolom tetap di FE) |

**Titik baca 04/05 (tanpa ubah skema):** verified per dokumen = `archives.is_verified` (`type=2`, `is_active=1`); sesi = `archive_opnames` `status=2`; "3 sesi terakhir yang mencakup folder X" = `archive_opname_folders.id_archive = X` (+ snapshot `total_documents`, `verified_after_count`; subtree dijumlah lewat `id_archive_parent` snapshot dalam sesi yang sama); hasil sesi = `GET opnames/{id}` + `/documents` (juga sesi terkonfirmasi).

## 4. Endpoint v5 (prefix `api/v5`; `Routes/DocumentArchive/api.php` grup `document-archive` `auth:api`, `:18-34`)
Semua baru. `permission_v5` per route (bukan middleware grup, agar `GET opnames` 05 tidak ikut): `Opname Document`, kecuali GET `opnames/{id}` & `/documents` = `List Archive` (G-4, gate1 O-8). Baru: `Http/Controllers/DocumentArchive/OpnameController.php`, `Http/Services/DocumentArchive/OpnameService.php` (extends `DocumentArchiveService`), FormRequest di `Http/Requests/DocumentArchive/Opname/`. `{id}` di-lookup di service seperti route Archive tetangga; `opnames/folders` didaftarkan sebelum `opnames/{id}`.

| Route | Controller@method | FormRequest | Service | Catatan |
|---|---|---|---|---|
| GET `opnames/folders` | `OpnameController@folders` | inline `$request->validate` (`id_archive` nullable) | `folders($request)` | Step 1: folder opname + subfolder langsung terlihat, jumlah dokumen, status hari ini, `skip_select_folder`; 404 `ARCHIVE400`, 400 `ARCHIVE417`, 403 `ARCHIVE407` |
| POST `opnames` | `@store` | `StoreRequest` (`authorize()`: scope F → 403 sebelum 422) | `store($data)` | buat draft; subfolder bukan anak langsung/tak terlihat → 400 `ARCHIVE416`; `ARCHIVE211` + id |
| GET `opnames/{id}` | `@show` | - | `show($id)` | ringkasan sesi (scope, folder, angka, peringatan); draft user lain, atau sesi terkonfirmasi user lain bagi non-superadmin 1/2 → 404 `ARCHIVE412` (05 memperluas) |
| PUT `opnames/{id}` | `@update` | `UpdateRequest` | `update($data, $id)` | ganti pilihan folder (Back → Next), klasifikasi ulang scan, `selected_at` diperbarui; `ARCHIVE413` bila bukan draft |
| POST `opnames/{id}/scan` | `@scan` | `ScanRequest` (`code` required, max 255) | `scan($data, $id)` | BR-13/14; `ARCHIVE212` + baris + angka |
| GET `opnames/{id}/documents` | `@documents` | - | `documents($request, $id)` | akses seperti `show`; paginator Laravel tanpa `columns/queries`; `result` all/scanned/verified/not_found/invalid/unscanned; draft: belum discan dihitung langsung, terkonfirmasi: snapshot |
| PUT `opnames/{id}/confirm` | `@confirm` | `ConfirmRequest` (`authorize()`: pemilik, draft, hari sama) | `confirm($id)` | BR-11/22/23/25; `lockForUpdate` sesi; `ARCHIVE213`; `ARCHIVE414` lewat `Message::formatResponse(false, …)` 400 agar FE membaca `msgCode` (BE `references/decisions.md` § Error) |
| DELETE `opnames/{id}` | `@cancel` | - | `cancel($id)` | status 3; `ARCHIVE214` |

Tulis: `DB::transaction(fn, MyHelper::ATTEMPTS)` + tangkap `ErrorMessageException` (`ArchiveController.php:32-45`), input `validated()`. Cakupan dihitung sekali per request (satu query folder aktif + `viewableFolderIds()` 02 + `Archive::scopeVisibleToUser` 01, pohon di memori; tanpa cache static, D-3). Snapshot di-insert per chunk ≤ 500. Label tipe = `ArchiveDocument::transactionTypeLabel()` (01); Salesman = `archive_documents.related_employee_name`. Penolakan 400/403/404, tanpa 500 (D-6).

## 5. Perubahan FE per module - `src/containers/Archive` (master, aksi baris kustom `archiveActionColumn`)
| Bagian | Perubahan |
|---|---|
| Registrasi | `configuration/endpoints.js:58-67` + 8 kunci (nama di contract.md); `routes/permissions.js:328-337` + `OpnameDocument: 'Opname Document'`; hook di `archive.api.js`; locale `archive.*` di `en-US.js` + `id-ID.js`. Route & menu tetap (`routes.js:2087-2094`) |
| "+" (`ArchiveButtonAdd/index.js:8-48`) | item "Opname Document" sesudah Store Document bila `usePermission(OpnameDocument)` (di dalam folder juga `access.view` folder aktif, 02) → `modalOpname.current.onAdd({ idArchive: lastBreadcrumb?.value ?? null, name })` |
| Menu baris (`archive.function.js:116-139`, `ArchivePage/index.js:157-169`) | folder: "Opname" di antara Lihat dan Hapus bila `canOpname` && `access.view` (02); `onMenuClick` key `opname` → `onAdd(record)` |
| Baru `ArchiveOpnameModal/` (sejajar `ArchiveBulkMoveModal`) | `index.js`: `ModalAdd` lebar ±1100, `saveAndNew={false}`, `className="modal-opname-archive"`, `getContainer` = `archivePageRef`, `actionRender` per langkah, antd `Steps` (blueprint `ArchiveBulkMoveModal/index.js:47-91`, `ArchiveBulkMoveModalForm.js:2,142`); buka/tutup: scanner pencarian `qrScanSearchRef.current.setActiveScanner(false/true)` (pola `ArchiveDocumentHandOverModal/index.js:16,21`); `archiveOpnameModal.function.js`: kunci langkah, opsi filter; `getScopeTag()` (BR-15) di `archive.function.js` (dipakai 04/05) |
| Step 1 `ArchiveOpnameSelectFolder.js` | `getArchiveOpnameFolders`; antd `Table` rowSelection (baris F terkunci), kolom Folder · Document (`NumberLocale`) · Opname hari ini (teks/jam; dicentang ulang: `Switch` Lanjut opname default on + teks); `Alert` info; footer; Next → `postArchiveOpname` (pertama) / `putArchiveOpname` (sesudah Back); `skipSelectFolder` → langsung POST lalu Step 2 |
| Step 2 `ArchiveOpnameScan.js` | `Input` autofocus, `onPressEnter` → `postArchiveOpnameScan` (tanpa toast sukses), ikon `ScanOutlined` di suffix → modal kamera; `useBarcodeScanner` aktif hanya di Step 2 saat modal kamera tertutup (hook mengabaikan ketikan di input, `hooks/appHook.js:104`); kartu + `Progress`; `Segmented` (pola `ApprovalTransactionFilter/index.js:40`); tabel scan (muat `getArchiveOpnameDocuments?result=scanned`, lalu tambah dari respons scan); kode sama dalam ±2 dtk diabaikan (frame kamera berulang) |
| Baru `archive-components/ArchiveOpnameScannerModal` | `Modal` (components) + `ArchiveScanner` (`archive-components/ArchiveScanner/index.js:11-52`), tidak menutup saat scan (beda dengan `QRScanSearch/index.js:44-48`), menampilkan hasil scan terakhir (kode + Result, K-11 iii); tutup lewat ✕ |
| Baru `archive-components/ArchiveOpnameResult` | `Segmented` + tabel server-paginated `getArchiveOpnameDocuments` satu sesi (prop `defaultResult`); dipakai Step 3 dan 05 |
| Step 3 `ArchiveOpnameConfirm.js` | `getArchiveOpname` (kartu, `Alert` per folder), `ArchiveOpnameResult defaultResult="scanned"`; Confirm = `Popconfirm` (components) → `putArchiveOpnameConfirm`; sukses → tutup + `refresh()`; `msgCode === 'ARCHIVE414'` → kembali ke Step 1 & muat ulang (scan tetap) |
| Batal | Cancel/✕ di Step 2-3: ada scan → konfirmasi `modal.confirm` lalu `deleteArchiveOpname`; tanpa scan → langsung delete |
| Gaya | kelas baru `.modal-opname-archive` dengan `.row-invalid` (nada error, acuan `modal.less:115-121`) dan `.row-not-found` (nada peringatan) di `archive.style.less` (variabel tema sudah ada di sana); `.modal-import-export-excel` tidak disentuh |
| Beda dari desain | Document No teks biasa (bukan tautan); tampilan mengikuti EQUAL (`knowledge/design-sources.md`) |

## 6. Acceptance criteria
Profil: `QA_DB=api_sidomaju` (lisensi Salesman Activity, permission 1266 ada). Prasyarat QA: seed ulang permission, role QA diberi `Opname Document`; user non-superadmin berlokasi Semarang saja (QA DB: CABANG - JOGJA = Yogyakarta, CABANG - SEMARANG = Semarang).

| AC | Given / When / Then | Tag | BR | Cek |
|---|---|---|---|---|
| AC-1 | Updater dijalankan dua kali → 3 tabel baru + 4 kolom & indeks `archives` ada, tanpa error; dokumen lama `is_verified=0` | [BE] | §3 | http (DB `api_sidomaju`: `SHOW CREATE TABLE`) |
| AC-2 | Seed dengan lisensi Salesman Activity → `permissions` punya `Opname Document` (1133, module 1266) + checks/unchecks; tanpa lisensi tidak ada | [BE] | BR-2 | EPIC K-2 b: baris hanya di `permission_salesman.sql` (BR-2) + DB sesudah seed ulang |
| AC-3 | Role tanpa `Opname Document` → "+" & menu baris tanpa Opname; endpoint opname 403 `GE0114` kecuali GET `opnames/{id}` & `/documents` (`List Archive`, AC-12). Tanpa lisensi Salesman Activity → menu Archive tidak ada, route FE tertolak, endpoint 403 `GE0114` | [BE+FE] | BR-2 | EPIC K-2 b: http role tanpa permission (403 `GE0114`) + e2e menu role tanpa `Opname Document`; guard lisensi menu/route FE dicek di kode; tukar lisensi sungguhan sekali, manual, di Gate 2 epic |
| AC-4 | Tanpa error konsol/request gagal: list Archive, menu "+" (Opname Document), menu ⋯ folder (Info, Pindahkan, Lihat, Opname, Hapus), Step 1, Step 2, modal kamera, Step 3 | [FE] | BR-3 | e2e (Archive, sesi QA) |
| AC-5 | User Semarang, GET `opnames/folders` (root) → `children` tanpa CABANG - JOGJA, `document_count` subtree, `opnamed_today` null, `is_default_checked` true; superadmin 1/2 → semua | [BE] | BR-4,5,8 | http |
| AC-6 | `opnames/folders` dengan id dokumen → 400 `ARCHIVE417`; id tidak ada → 404 `ARCHIVE400`; folder di luar scope/tanpa View → 403 `ARCHIVE407` | [BE] | BR-2 | http |
| AC-7 | Step 1: belum diopname → tercentang; sudah diopname hari ini → tidak tercentang, "Sudah diopname hari ini · HH:mm"; dicentang ulang → "(dicentang ulang)" + Lanjut opname aktif + teks; footer "N folder dipilih · M dokumen" | [FE] | BR-8,10 | e2e |
| AC-8 | Opname dari menu folder ZULFA (tanpa subfolder, belum diopname hari ini) → Step 1 dilewati, stepper 2 langkah, tag "ZULFA" | [BE+FE] | BR-12,15 | e2e |
| AC-9 | POST `opnames` dengan subfolder bukan anak langsung → 400 `ARCHIVE416`; F di luar scope → 403 `ARCHIVE407` (sebelum 422); body salah → 422; valid → `ARCHIVE211` + `id_archive_opname`, `archive_opnames.status=1` | [BE] | BR-2,21 | http |
| AC-10 | Scan: dokumen di folder tercakup → `verified`; folder lain / root / `is_active=-1` → `not_found` (`is_out_of_scope`); kode tak dikenal / nama folder → `invalid`; `scanned_at` = waktu server | [BE] | BR-13 | http (`archive_opname_documents`) |
| AC-11 | Kode yang sama discan lagi → 200 `is_duplicate=true`, jumlah baris & `counts` tetap | [BE] | BR-14 | http |
| AC-12 | Scan/confirm/delete draft milik user lain → 404 `ARCHIVE412`; ke sesi terkonfirmasi/batal → 400 `ARCHIVE413`; GET `opnames/{id}` & `/documents` sesi terkonfirmasi user lain: non-superadmin 404 `ARCHIVE412`, superadmin 1/2 200 | [BE] | BR-21 | http |
| AC-13 | Step 2: ketikan scanner USB (keyboard + Enter) dengan fokus di dalam dan di luar input → baris baru; ikon scan membuka modal kamera yang tetap terbuka sesudah scan; pencarian Archive tidak terpicu | [FE] | BR-16 | e2e (simulasi keyboard); kamera nyata: manual (Gate 2) |
| AC-14 | Step 2: `Segmented` All/Verified/Not found/Invalid menyaring tabel dan jumlahnya cocok dengan kartu sesi; baris Invalid/Not found memakai kelas `.row-invalid`/`.row-not-found`; tag judul "<pertama> + N subfolder" | [FE] | BR-15,17,18 | e2e; warna: manual (Gate 2) |
| AC-15 | GET `opnames/{id}` draft → `counts.total_documents`, `verified_before`, `scanned/verified/not_found/invalid/unscanned` sesuai BR-20; `warnings` berisi folder tanpa lanjut dengan `unverify_count` > 0 | [BE] | BR-17,19,20 | http |
| AC-16 | GET `opnames/{id}/documents?result=`(6 nilai)`&page=` → paginator benar; Not found `folder_name` null, `is_out_of_scope` true; belum discan `unscanned`, `scanned_at` null | [BE] | BR-19,20 | http |
| AC-17 | Step 3: filter awal Scanned; kartu & peringatan tampil; Back ke Step 2 tanpa kehilangan scan; Confirm (Popconfirm) → pesan sukses, modal tertutup, list dimuat ulang | [FE] | BR-19,27 | e2e |
| AC-18 | Confirm folder belum diopname hari ini: discan → `is_verified=1` + `verified_at/by`, `id_archive_opname`; verified tak discan → 0; `archive_opnames` status 2 + angka; folder level 0-2; baris `result=4` per dokumen belum discan | [BE] | BR-11,25 | http (DB) |
| AC-19 | Opname ulang hari sama, dicentang ulang + Lanjut aktif → verified tak discan tetap 1; Lanjut nonaktif → 0, `unverified_count` = peringatan Step 3 | [BE] | BR-10,11 | http (DB) |
| AC-20 | Subfolder tak dicentang & di luar lokasi user: `is_verified`, `verified_at`, `id_archive_opname` dokumennya tetap; user Semarang & user lokasi lain masing-masing opname root → superadmin melihat keduanya "Sudah diopname hari ini" | [BE] | BR-5,9 | http (DB) |
| AC-21 | Dokumen Not found & Invalid: `archives` (folder, status, `is_active`, `is_verified`) tidak berubah | [BE] | BR-24 | http (DB) |
| AC-22 | Sesi B memilih folder X, lalu sesi A dikonfirmasi pada X → confirm B 400 `ARCHIVE414` (`msg_code`), DB tidak berubah; FE kembali ke Step 1 dengan X "Sudah diopname hari ini" | [BE+FE] | BR-23 | http + e2e |
| AC-23 | Draft dengan `created_at` kemarin (ubah di DB) → confirm 400 `ARCHIVE415`; sesi terkonfirmasi kemarin → Step 1 "Belum diopname" & tercentang | [BE] | BR-7,23 | http |
| AC-24 | DELETE draft → status 3, `ARCHIVE214`; folder tidak dianggap "sudah diopname"; confirm sesudahnya 400 `ARCHIVE413` | [BE] | BR-21 | http |
| AC-25 | Dokumen dipindah ke luar cakupan sesudah discan → saat Confirm hasilnya Not found dan verified-nya tidak berubah | [BE] | BR-22 | http |
| AC-26 | Regresi: list, create folder, put-in, hand-over, receive tetap; dokumen baru (cetak PDF) `is_verified=0`; pindah/hand over tidak mengubah `is_verified` | [BE] | BR-26 | http |
| AC-27 | Kinerja: `opnames/folders` root dan Confirm pada Backup Arsip (±24 rb dokumen) selesai < 30 dtk tanpa 500 | [BE] | BR-4 | http (waktu respons) |

## 7. Subtask
| Kunci | Judul | Layer | AC | Module |
|---|---|---|---|---|
| ED-1032 | [BE] Archive - skema & seed opname (Updater, model, permission `Opname Document`, kode pesan) | BE | AC-1, AC-2 | DocumentArchive, `app/Sql`, lang |
| ED-1038 | [BE] Archive - Step 1 & sesi draft (folders, store, update, show, cancel) | BE | AC-3, AC-5, AC-6, AC-9, AC-12, AC-15, AC-24 | DocumentArchive |
| ED-1044 | [BE] Archive - scan & hasil sesi (scan, documents) | BE | AC-10, AC-11, AC-16 | DocumentArchive |
| ED-1050 | [BE] Archive - konfirmasi opname (timpa/lanjut, snapshot, bentrok) | BE | AC-18..AC-23, AC-25..AC-27 | DocumentArchive |
| ED-1056 | [FE] Archive - menu Opname (+ & menu baris), permission, endpoint, locale | FE | AC-3, AC-4 | Archive |
| ED-1060 | [FE] Archive - modal Opname Step 1 Select Folder | FE | AC-7, AC-8 | Archive |
| ED-1064 | [FE] Archive - modal Opname Step 2 scan (input, kamera, USB, Segmented, gaya baris) | FE | AC-13, AC-14 | Archive |
| ED-1067 | [FE] Archive - modal Opname Step 3 Confirm (`ArchiveOpnameResult`) | FE | AC-17, AC-22 | Archive |
| ED-1068 | [QA] Skenario opname per folder (lokasi, lanjut/timpa, bentrok, lisensi) | QA | semua | Archive |

## 8. Keputusan untuk developer
| # | Pertanyaan | Opsi | Rekomendasi | Risiko | Jawaban Gate 1 |
|---|---|---|---|---|---|
| K-1 | Di mana sesi berjalan disimpan? | a) draft di server: Next = buat sesi, tiap scan disimpan & diklasifikasi BE, Step 3 & history memakai endpoint yang sama; b) tanpa draft: FE menyimpan scan, BE cek per scan, satu POST saat Confirm (hilang saat reload; Step 3 "All" ribuan dokumen harus mengirim ulang daftar scan) | a | tinggi | **Jawaban Gate 1 (2026-10-06):** a - rekomendasi |
| K-2 | Arti "ganti hari → folder ditandai belum diopname" (G-3) | a) hanya status/centang Step 1; verified per dokumen bertahan sampai opname berikutnya yang mencakupnya; opname di hari baru = hitung ulang (tak discan → unverified); hari = tanggal Asia/Jakarta untuk semua lokasi; b) semua verified direset tiap ganti hari (angka verified = hari ini saja); c) opname di hari baru = lanjut (tak discan tetap verified) | a | tinggi | **Jawaban Gate 1 (2026-10-06):** a - G-5 |
| K-3 | Dokumen langsung di folder opname (tidak ada di desain) | a) baris pertama Step 1 = folder itu sendiri (selalu ikut, status + toggle Lanjut bila sudah diopname hari ini); Step 1 dilewati hanya bila tanpa subfolder **dan** belum diopname hari ini; b) persis desain: tanpa baris, lanjut otomatis bila sudah diopname hari ini, Step 1 selalu dilewati bila tanpa subfolder; c) selalu ditimpa | a | tinggi | **Jawaban Gate 1 (2026-10-06):** a - rekomendasi |
| K-4 | Dokumen tanpa folder (root; QA DB 4.234, status Handed Over/Taken) | a) ikut opname dari root (baris "All Archive" di Step 1); b) tidak pernah ikut opname | a - total "seluruh dokumen di sistem" (S2 05) memuatnya | rendah | **Jawaban Gate 1 (2026-10-06):** a - G-5 |
| K-5 | Sub-subfolder & dokumen di luar scope user (K-6 brief) | a) turunan di luar lokasi/View dikecualikan beserta isinya, tidak disentuh; dokumen tak terlihat (01 per baris) tidak dihitung, discan = Not found; b) semua ikut walau tak terlihat | a - semangat R-11 | tinggi | **Jawaban Gate 1 (2026-10-06):** a - G-1 |
| K-6 | Siapa boleh opname (G-4) | a) permission baru `Opname Document` + View efektif folder (02); b) a + Update folder; c) tanpa permission baru (cukup `List Archive`) | a | tinggi | **Jawaban Gate 1 (2026-10-06):** a - G-4 |
| K-7 | Dokumen ada di sistem tapi bukan dokumen aktif dalam cakupan (`is_active=-1` tercetak belum disimpan, `0` terhapus, folder lain, root) | a) semua Not found; Invalid hanya bila tidak ada baris dokumen archive bernama kode itu; b) `-1`/`0` = Invalid | a - definisi desain "ada di sistem" | rendah | **Jawaban Gate 1 (2026-10-06):** a - rekomendasi |
| K-8 | Dua sesi bentrok pada folder sama | a) BR-23: `ARCHIVE414` → pilih ulang (tampak "Sudah diopname", bisa Lanjut), draft hari lain `ARCHIVE415`; b) konfirmasi terakhir menang | a - tanpa ini sesi kedua menghapus verified sesi pertama | tinggi | **Jawaban Gate 1 (2026-10-06):** a - rekomendasi |
| K-9 | Snapshot hasil per sesi (history 05: "All" & jumlah saat itu) | a) baris tiap dokumen cakupan saat Confirm (termasuk belum discan), volume = dokumen cakupan per sesi; b) hanya baris scan + angka; "All"/"Belum discan" sesi lama tak bisa ditampilkan | a - tidak bisa diisi belakangan | tinggi | **Jawaban Gate 1 (2026-10-06):** a - G-5 |
| K-10 | Status verified saat dokumen pindah folder / hand over / receive | a) tetap, dinilai ulang di opname berikutnya; b) reset ke unverified | a | rendah | **Jawaban Gate 1 (2026-10-06):** a - rekomendasi |
| K-11 | Rincian UX (tidak ada di sumber) | (i) scan kode sama: tanpa baris/angka/pesan baru; (ii) baris terbaru di atas tabel Step 2 (desain berurut naik); (iii) modal kamera menampilkan hasil scan terakhir; (iv) R-20 = modal **kamera** tetap terbuka sampai ditutup user, scanner USB tanpa modal (G-6); (v) tutup modal dengan hasil scan → konfirmasi lalu sesi batal; (vi) peringatan Step 3 juga untuk folder "Belum diopname" yang menurunkan verified (teks tanpa "tanpa lanjut opname"); (vii) Confirm Opname memakai `Popconfirm`; (viii) Finish Opname boleh tanpa scan | ya untuk semuanya | rendah | **Jawaban Gate 1 (2026-10-06):** ya untuk semuanya - rekomendasi |
| EPIC K-2 | Cara cek AC lisensi (`PROFILES` kosong) | a/b/c di gate1 §7 | b | rendah | **Jawaban Gate 1 (2026-10-06):** b - cara seragam di AC-2, AC-3 |

## 9. Di luar cakupan
- Scope lokasi folder/dokumen (`is_superadmin` 1/2), daftar & label transaction type, `permission_v5` route Archive lama → **ED-1024** (dipakai lewat `Archive::scopeVisibleToUser`, `ArchiveDocument::transactionTypeLabel`).
- Hak folder View/Update/Delete/Store, `viewableFolderIds()`, `access` per baris, layar akses ditolak → **ED-1025**.
- Kolom Document Verified, ringkasan "verified / total", tab Verification, modal 05, overview 3 sesi → **ED-1027** (membaca `archives.is_verified` & tabel sesi).
- Drawer Opname History 05b (daftar sesi, display setting, filter, visibilitas sesi user lain: 04 K-4 + EPIC K-1) → **ED-1028** (memakai `GET opnames/{id}`, `/documents`, `ArchiveOpnameResult`).
- Set Permission by User → **ED-1029**.
- Dokumen otomatis Verified saat dicetak (brief K-4, hanya S4) → Di luar epic.
- Memindahkan dokumen Not found ke folder opname; entri `archives.history` untuk opname; melanjutkan draft sesudah modal ditutup/reload (draft tertinggal tetap status 1, tidak tampil); export hasil; aplikasi mobile.

## Catatan implementasi
- (v5-be-dev, 2026-10-07) `scope_folder_count` = jumlah subfolder langsung yang dipilih (level 1); `scope_name` = subfolder pertama (urut nama, seperti Step 1), tanpa subfolder = nama F, root tanpa subfolder = NULL. `OpnameService::scope($opname)` (public) membentuk objek `scope` kontrak §4 untuk 04/05; `folder_name` diambil dari baris level 0 (relasi `ArchiveOpname::folder`).
- (v5-be-dev) Draft dibaca langsung: `show`, `/documents`, `counts` di `/scan` dan Confirm menilai ulang cakupan + tiap baris scan terhadap kondisi saat ini (`result` tersimpan diperbarui saat scan/PUT/Confirm). Subfolder terpilih yang bukan lagi subfolder langsung terlihat dikeluarkan dari cakupan tanpa error; folder opname yang tidak lagi ada/terlihat → `ARCHIVE400`/`ARCHIVE407` di PUT dan Confirm. Sesi batal milik sendiri dibaca seperti draft. (contract §3, §4, §7)
- (v5-be-dev) `code` scan hanya karakter Latin-1 (U+0000-U+007F, U+00A0-U+00FF), selain itu 422: kolom `latin1` tidak bisa menyimpan/membandingkannya (pembanding SQL = 500 kolasi, akar ED-1071) dan teks itu tidak mungkin nomor dokumen. Id archive/sesi non-ASCII → 404 `ARCHIVE400`/`ARCHIVE412` tanpa query. (contract §5)
- (v5-be-dev) BR-23: bentrok = sesi lain terkonfirmasi dengan `confirmed_at >= selected_at` (detik yang sama dihitung bentrok). Dicek dua kali: di awal (gagal cepat) dan sesudah baris `archives` terkunci oleh UPDATE, dengan locking read (`LOCK IN SHARE MODE`) agar Confirm bersamaan pada dokumen yang sama tidak saling menimpa; parameter `[0]` root = "All Archive". (contract §7)
- (v5-be-dev) Confirm pada `archives` (lewat query builder, `updated_at`/`updated_by` dan `history` tidak disentuh): discan Verified → `is_verified=1`, `verified_at/by`, `id_archive_opname`; tidak discan di folder tanpa Lanjut → `is_verified=0`, `verified_at/by` NULL, `id_archive_opname` = sesi ini; tidak discan di folder Lanjut, Not found, Invalid → tidak berubah. `archive_opnames.unverified_count`/`archive_opname_folders.unverified_count` = dokumen yang berubah 1 → 0. Snapshot `is_verified_after`: Verified 1, belum discan = nilai sesudah, Not found = nilai dokumen (tidak berubah), Invalid NULL.
- (v5-be-dev) "Sudah diopname hari ini" = folder ada di `archive_opname_folders` level 0-2 sesi `status=2` dengan `confirmed_at` hari ini (Asia/Jakarta); root hanya baris level 0 `id_archive` NULL. `is_continue` level 2 mengikuti baris Step 1-nya; `is_opnamed_today` per folder sendiri.
- (v5-be-dev) Id baris snapshot (insert massal ≤ 500/chunk) = satu `MyHelper::generateId()` + urutan pada 10 digit terakhir: `generateId()` per baris = satu query `companies` per baris dan peluang tabrakan 10 digit acak dalam satu detik ~3% pada 24 rb baris.
- (v5-be-dev) Label: `status_label` en Running/Confirmed/Cancelled, id Berjalan/Dikonfirmasi/Dibatalkan; `result_label` en Verified/Not found/Invalid/Not scanned, id Terverifikasi/Tidak ditemukan/Tidak valid/Belum discan (konstanta di model, pola `DocumentArchiveService::action()`). (contract objek bersama, §4)
- (v5-be-dev, fix QA r1 D-1) POST `opnames`: `id_archive` yang melanggar rule-nya sendiri (bukan string, > 30 karakter) tidak dicari di `authorize()` → 422 dari validasi, sama dengan GET `opnames/folders`; id yang lolos rule tetapi tidak ada/nonaktif tetap 404 `ARCHIVE400`. (contract §2)
- (v5-be-dev, fix QA r1 D-2) `archive_opname_documents.scanned_at` = `DATETIME(6)` (bukan `DATETIME` seperti di Model data): waktu scan sampai mikrodetik, di bawah kunci baris sesi selalu > scan terakhir sesi itu (`OpnameService::scanTime()`), sehingga "scan terbaru dulu" (K-11 ii) pasti untuk beberapa scan per detik di draft dan snapshot. Response tetap `Y-m-d H:i:s`. Updater ED-1026 membuat kolom `DATETIME(6)` di DB baru dan `MODIFY` sekali (cek `information_schema`) di DB yang sudah menjalankan versi awalnya. (contract §6)
