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
## Revisi review 2026-10-08
Sumber: `epics/ED-1022-review-feedback.md` § ED-1026. Dasar: BE 44bea5136, FE 7fa63869d. **(rev)** = baru/berubah.

| Kode | Perubahan | BR / AC | Layer |
|---|---|---|---|
| FB-3 | Tidak ada data opname di DB sebelum Konfirmasi: scan & ringkasan = request tanpa simpan, satu `POST opnames` menyimpan sesi terkonfirmasi; Cancel/✕ tanpa request; draft/batal lama dihapus Updater (K-13) | BR-14, 17, 19, 21-23, 25; AC-1, 8-12, 15-18, 22-25, 27 | BE+FE |
| FB-2 | Tag Step 2-3 = tag Step 1 + " + N subfolder" (N = subfolder dicentang) | BR-15; AC-8, 14 | FE (+BE `scope.subfolder_count`) |
| FB-10 | Filter hasil `Segmented` → `Tag.CheckableTag`, wrap xs/sm; `ArchiveOpnameResult` milik item ini (dipakai ED-1028) | BR-17, 19; AC-14, 17, 30 | FE |
| FB-11 | Steps seperti `ApprovalTransactionCreatePin/index.js:120-121` | BR-29; AC-4, 29 | FE |
| FB-8a | Tanpa `Opname Document` tidak ada tampilan opname | BR-2; AC-3 | FE |
| N-4 | "All Archive" diterjemahkan (id "Semua Arsip"): tag, Folder dokumen root, peringatan, `ARCHIVE414` | BR-28; AC-28 | BE+FE |
| Tetap | Gagal muat folder menutup modal; cakupan, Lanjut, klasifikasi, snapshot, bentrok | - | - |

**Hilang (FB-3):** PUT/DELETE `opnames/{id}`, POST `opnames/{id}/scan`, PUT `opnames/{id}/confirm` (+ `UpdateRequest`, `CancelRequest`, `ConfirmRequest`, `OpnameService::update/cancel/confirm/ownDraft/draftContext/scanTime`); status sesi 1 & 3 + label; `ARCHIVE211/214/413`; baca draft/batal oleh pemilik (`OpnameService.php:796-808`); FE `putArchiveOpname`, `deleteArchiveOpname`, `putArchiveOpnameConfirm`, `saveSession`/`deleteSession`. `ARCHIVE415` tetap, arti baru (BR-23).
**Tetap dibaca ED-1027/ED-1028:** sesi `status=2` + angka, `archive_opname_folders` 0-2, `archive_opname_documents`, `archives.is_verified`, `GET opnames/{id}` (+ `scope.subfolder_count`), `/documents`.

## Gate 1 ronde 2 (2026-10-08) - jawaban diterapkan
Sumber: `epics/ED-1022-gate1.md` §9b (final), O-5/7/8, C-2/3/8, §5, §8.

| Asal | Perubahan (rev) | Bagian |
|---|---|---|
| EPIC K-3 a (C-2) | Baca sesi (`GET opnames`, `opnames/{id}`, `/documents`) = `Opname Document`, bukan `List Archive`; route & AC milik **ED-1028** (AC-14), item ini menulis "baca = ED-1028" | BR-2, §4, AC-3, contract baris 3-4, komentar `api.php:39` |
| C-8 | `folders`/`warnings` `GET opnames/{id}` disaring per pemanggil (ED-1028 BR-16, D-1), bukan "nilai tersimpan" | contract §6 |
| ED-1028 K-10 b + K-14 a (C-3) | `scope` = `{ is_root, folder_name, subfolder_count }`; Scope "<folder / Semua Arsip · root> + N subfolder" di semua tempat. Contract ED-1027 (`:36,46-48,147`) & ED-1028 (`:30,40-42`) mengikuti (diperbarui pemiliknya) | BR-30, AC-31, §4, §5, contract |
| EPIC K-4 a | `Opname Document` 1133 → **1139**; seed diubah **ED-1025** (jalan lebih dulu; DB QA diseed ulang di run-nya) | §3, AC-2 |
| ED-1070, ED-1071 ditunda | Tanpa dependensi/hand-off; regex `code` scan tetap (1071 K-5 tidak berlaku) | §9 |
| §5 QA | QA 1026: AC-1, AC-2, AC-12, AC-15, `scenario1/2/4`, e2e; skenario ED-1027/ED-1028 hanya dicatat usang | §7 |

## 1. Ringkasan
User Archive memastikan dokumen mana yang benar-benar ada fisiknya lewat opname per folder per hari: pilih folder
(root/folder) + subfolder langsungnya, scan QR/barcode (kamera/scanner USB), lalu konfirmasi. Selama sesi berjalan tidak
ada yang disimpan di server; Konfirmasi Opname menyimpan sesi lengkap (folder, angka, hasil per dokumen) dan memperbarui
status verified dokumen. "Lanjut opname" menentukan opname ulang di hari yang sama menimpa atau melanjutkan. Opname hanya
menyentuh yang terlihat user (lokasi 01 + View 02).

## 2. Aturan bisnis
| BR | Aturan | Sumber |
|---|---|---|
| BR-1 | Opname per folder, modal 3 langkah (Select Folder, Opname Folder, Confirm), hasil Verified / Not found / Invalid | R-1, R-4, R-5; S2 04a-c |
| BR-2 (rev) | `Opname Document` (id 1139, module 1266, seed hanya lisensi Salesman Activity) menjaga semua endpoint item ini (`opnames/folders`, `/scan`, `/preview`, `/preview/documents`, POST `opnames`); tanpa: 403 `GE0114` dan **tidak ada tampilan opname** ("+", menu baris, modal tidak di-mount). Baca sesi terkonfirmasi (`GET opnames`, `opnames/{id}`, `/documents`) = permission milik ED-1028 (`Opname Document`, EPIC K-3 a). Folder opname wajib terlihat + View efektif, selain itu 403 `ARCHIVE407` | R-2; K-6; FB-8a; EPIC K-3 a, K-4 a |
| BR-3 | Titik masuk: "+" → Opname Document (root = "All Archive · root"; di dalam folder = folder aktif); menu ⋯ baris folder → Opname (Info, Pindahkan, Lihat, Opname, Hapus). Baris dokumen: tanpa Opname | R-3; S2 01 |
| BR-4 | Cakupan = folder opname F (atau root) + subfolder langsung yang dicentang, masing-masing beserta seluruh turunannya. Dokumen langsung di F selalu ikut (K-3); dari root mencakup dokumen tanpa folder (K-4) | R-5; S2 04a |
| BR-5 | Subfolder/turunan di luar scope user (lokasi 01 ∩ View 02, `viewableFolderIds()`) tidak tampil/ikut, data opname-nya tetap; dokumen tak terlihat tidak dihitung. Superadmin 1/2 semua | R-11; K-5 |
| BR-6 | Dokumen dihitung = archive dokumen `is_active = 1` di folder tercakup, status apa pun; `-1` dan `0` tidak | S2 01; `DocumentService.php:133` |
| BR-7 | "Hari ini" = tanggal server Asia/Jakarta. Folder "Sudah diopname hari ini · HH:mm" bila tercakup sesi terkonfirmasi hari ini (jam = konfirmasi terakhir); ganti hari → "Belum diopname" | R-6; K-2 |
| BR-8 | Step 1: alert info; tabel centang Folder · Document · Opname hari ini; baris pertama = F terkunci tercentang (K-3); subfolder belum diopname hari ini tercentang; centang-semua; footer "N folder dipilih · M dokumen"; Cancel, Next | R-9; S2 04a |
| BR-9 | Subfolder tak dicentang: folder & dokumennya tidak ikut, verified-nya tetap | R-10 |
| BR-10 | Folder sudah diopname hari ini yang dicentang ulang: "(dicentang ulang)", toggle Lanjut opname default aktif + teks akibat | R-13, R-14 |
| BR-11 | Saat Confirm, per folder tercakup: discan Verified → verified; tidak discan: Lanjut aktif → tetap, selain itu → unverified. Nilai Lanjut baris Step 1 berlaku untuk turunannya | R-13, R-14; K-2 |
| BR-12 | Step 1 dilewati bila F tanpa subfolder terlihat **dan** belum diopname hari ini; stepper 2 langkah, tombol kiri Step 2 = Cancel | S2 04; K-3 |
| BR-13 | Klasifikasi (kode = `archives.name`): **Verified** = dokumen dalam cakupan; **Not found** = archive dokumen bernama kode itu di luar cakupan (folder lain, root, `-1`, `0`, tak terlihat); **Invalid** = tidak ada (termasuk nama folder) | S2 04b; K-7 |
| BR-14 (rev) | Kode yang sudah ada di daftar scan sesi (sama tanpa beda huruf besar/kecil & spasi akhir, seperti kolasi `archives.name`) tidak dikirim lagi: tanpa baris, angka, atau pesan baru; modal kamera menampilkan baris lamanya. Confirm/pratinjau menghitung kode ganda sekali | K-11 (i); `OpnameService.php:2053` |
| BR-15 (rev) | Judul "Opname Document" (Step 1-2), "Confirm Opname" (Step 3). Tag: Step 1 = "All Archive · root" / nama F; Step 2-3 = tag Step 1 + " + N subfolder", N = subfolder dicentang di Step 1 (baris F tidak dihitung); N = 0 → tag Step 1 | R-12; FB-2 |
| BR-16 | Input scan menerima scanner USB (fokus di input atau tidak) dan ketik + Enter; ikon `ScanOutlined` membuka modal kamera yang tetap terbuka sampai ditutup; selama modal opname terbuka scanner pencarian Archive nonaktif | R-18..R-20; K-11 (iv) |
| BR-17 (rev) | Step 2: kartu "Status verifikasi saat ini X / Y" + progress (pratinjau); kartu sesi (scan, verified, not found, invalid; dari daftar scan modal); filter "Scan result" `CheckableTag` All/Verified/Not found/Invalid + jumlah, satu aktif, wrap xs/sm; tabel Document No, Transaction Type, Salesman, Time, Result (terbaru di atas); footer "<tag> · M dokumen", Back, Finish Opname | R-16; FB-10 |
| BR-18 | Baris Invalid & Not found berlatar khusus (`.row-invalid`/`.row-not-found` di `.modal-opname-archive`) | R-17 |
| BR-19 (rev) | Step 3: kartu Total dokumen, Verified sesi ini, Not found, Invalid, Belum discan; filter `CheckableTag` All/Scanned/Verified/Not Found/Invalid + jumlah, default Scanned, wrap xs/sm (komponen sama dengan riwayat ED-1028); tabel Document No, Transaction Type, Folder (Not found "Di luar scope opname", Invalid "—"), Time, Result; peringatan per folder yang menurunkan verified; Back, Confirm Opname | R-21, R-22; FB-10 |
| BR-20 | Total = dokumen cakupan; Belum discan = Total − Verified sesi ini; Scanned = semua baris scan; All = cakupan + Not found + Invalid | S2 04c |
| BR-21 (rev) | **Tidak ada data opname di DB sebelum Konfirmasi Opname.** Sesi berjalan hanya ada di modal (pilihan folder, daftar scan + waktu scan server, waktu muat Step 1). Cancel/✕/gagal muat/reload = sesi hilang tanpa request tulis. Konfirmasi menyimpan sesi langsung terkonfirmasi dalam satu transaksi (gagal = tidak ada yang tersimpan) | FB-3; K-1 diganti |
| BR-22 (rev) | Tiap scan dikirim hanya untuk diklasifikasi, hasil langsung tampil (K-12 a). Scan, pratinjau (ringkasan & tabel Step 2/3) dan Confirm menilai cakupan & tiap kode terhadap kondisi saat request (dokumen dipindah selama sesi ikut berubah hasilnya). Subfolder terpilih yang tidak lagi subfolder langsung terlihat → 400 `ARCHIVE416` (K-16 a) | K-1; FB-3; K-12 a, K-16 a |
| BR-23 (rev) | Confirm ditolak 400 `ARCHIVE414` bila folder tercakup dikonfirmasi sesi lain pada/sesudah waktu muat Step 1 (`selected_at`); kirim ulang Confirm yang sama juga `ARCHIVE414` (tidak ada sesi ganda). `selected_at` atau waktu scan bukan hari ini → 400 `ARCHIVE415` | R-6, R-11; K-8; K-15 a |
| BR-24 | Not found & Invalid tidak mengubah dokumen apa pun | S2 04b/05 |
| BR-25 (rev) | Sesi terkonfirmasi menyimpan waktu, user, scope, angka, folder tercakup (jumlah dokumen & verified saat itu) dan hasil per dokumen; waktu scan = waktu server saat kode discan (K-15 a) | S2 02/05; K-9 |
| BR-26 | Verified tidak berubah karena pindah / hand over / receive; dokumen baru unverified | K-10 |
| BR-27 | Confirm berhasil: pesan sukses, modal tertutup, list Archive dimuat ulang | `ArchivePage/index.js` `refresh` |
| BR-28 (rev) | Label root mengikuti bahasa user: en "All Archive", id "Semua Arsip" (tag "Semua Arsip · root", Folder dokumen root, peringatan Step 3, `[0]` `ARCHIVE414`); tanpa teks Inggris tetap | N-4; `common.Archive` id "Arsip" (`languages/locales/id_ID.js:641`) |
| BR-29 (rev) | Stepper seperti `ApprovalTransactionCreatePin/index.js:120-121`: `Steps size="small" type="navigation" responsive={false}` dalam `.steps-container` (lebar dibatasi, gulir horizontal), auto scroll ke langkah aktif (`approvalTransactionCreatePin.function.js:25-34`) | FB-11 |
| BR-30 (rev) | Scope sesi satu format di semua tempat (tag modal Step 2-3, kolom Scope & subjudul hasil riwayat ED-1028, 3 sesi terakhir ED-1027): "<nama folder opname / All Archive · root> + N subfolder", N = subfolder dicentang di Step 1 sesi itu, tanpa akhiran bila 0; root diterjemahkan (BR-28). API `scope` hanya `is_root`, `folder_name`, `subfolder_count` (nama subfolder tidak dikirim) | K-14 a; ED-1028 K-10 b |

Kolom v3: tabel `archive*` tidak dipakai v3, tidak ada yang dijaga (D-5).

## 3. Model data (DB tenant, `latin1`/`latin1_general_ci`; `id_*`/`*_by` = `VARCHAR(30)`)
| Perubahan | Detail |
|---|---|
| `archives` (sudah ada) | `is_verified`, `verified_at/by`, `id_archive_opname`, indeks `id_archive_parent` - tetap |
| `archive_opnames` (**skema tetap**) | (rev) hanya sesi **terkonfirmasi**: `status` selalu 2 (query ED-1027/ED-1028 tetap); `selected_at` = waktu muat Step 1 dari body; `created_at` = `confirmed_at` = waktu Confirm; `scope_name`/`scope_folder_count` tetap ditulis |
| `archive_opname_folders` | (rev) ditulis hanya saat Confirm, level 0-2 |
| `archive_opname_documents` | (rev) ditulis hanya saat Confirm (result 1-4); `scanned_at DATETIME(6)` = waktu scan dari body, dipaksa naik per urutan `scans` (+1 µs); `created_at` = waktu Confirm |
| Updater (rev) | baru `Updaters/<Y_m_d_H_i_s>_DeleteArchiveOpnameDraft.php` + `config.php`: hapus `_documents` & `_folders` milik sesi `status IN (1,3)`, lalu sesinya; idempoten, semua tenant (K-13 a; pola `2025_12_17_09_44_25_updateDisplayColumnPO.php`). Aman: `archives.id_archive_opname` hanya ditulis Confirm (`OpnameService.php:548-566`) |
| Model (rev) | `ArchiveOpname`: status 1/3 + label dihapus (tinggal `STATUS_CONFIRMED`); konstanta label root en/id (pola `STATUS_LABELS`, `ArchiveOpname.php:31-35`) untuk BR-28 |
| Permission (rev) | `Opname Document` id **1139** (EPIC K-4 a; dulu 1133, bentrok branch AI `v5-ai-doc-capture` 1133-1138). Seed `permission_salesman.sql:82,138,147,216,227` diubah **ED-1025** (jalan lebih dulu, O-11); item ini tanpa perubahan seed, BE/FE memakai nama |
| Kode pesan (rev, en_EN + id_ID) | dihapus `ARCHIVE211`, `ARCHIVE214`, `ARCHIVE413` (nomor tidak dipakai ulang). Tetap: `212`, `213`, `412`, `414`, `415` (arti BR-23), `416`, `417`, `400`, `407` |

## 4. Endpoint v5 (prefix `api/v5`; `Routes/DocumentArchive/api.php:39-51`, grup `opnames`)
Route item ini: `Opname Document`. (rev) GET `opnames`, `opnames/{id}`, `/documents`: permission milik **ED-1028** (EPIC K-3 a → `Opname Document`; kini `List Archive` `api.php:42,45,49`). Komentar `api.php:39` ditulis ulang tanpa nama permission baca: "opname per folder (ED-1026): Opname Document; baca sesi = ED-1028". POST tanpa simpan: pola `purchase-order.php:27` (`calculate-pph`), tanpa transaksi (`PurchaseOrderController.php:149-152`). FormRequest ber-`id_archive` memeriksa folder opname di `authorize()` (pola `StoreRequest.php`).

| Route | Controller@method | FormRequest | Service | Status |
|---|---|---|---|---|
| GET `opnames/folders` | `OpnameController@folders` | inline | `folders($request)` | ubah (rev): + `selected_at` (waktu server) |
| POST `opnames/scan` | `@scan` | `ScanRequest` (rev: + `id_archive`, `folders`) | `scan($data)`: klasifikasi satu kode, **tanpa tulis** | baru (rev) |
| POST `opnames/preview` | `@preview` | `PreviewRequest` (baru) | `preview($data)`: ringkasan bentuk `show` (scope, folders 0-1, counts, result_counts, warnings), tanpa tulis, tanpa saringan D-1 (O-7) | baru (rev) |
| POST `opnames/preview/documents` | `@previewDocuments` | `PreviewRequest` + `result` | `previewDocuments($request)`: paginator seperti `/documents`, tanpa tulis | baru (rev) |
| POST `opnames` | `@store` | `StoreRequest` (rev: + `scans`, `selected_at`) | `store($data)`: isi `confirm()` lama tanpa draft (BR-11/22/23/25), `DB::transaction`; `ARCHIVE414` = `formatResponse(false, …)` 400 (`OpnameController.php:97-121`) | ubah (rev): = Konfirmasi, `ARCHIVE213` |
| GET `opnames/{id}`, `/{id}/documents` | `@show`, `@documents` | - | `readable()`: hanya sesi terkonfirmasi (lainnya 404 `ARCHIVE412`); + `scope.subfolder_count`. Visibilitas, saringan `folders`/`warnings` (BR-16 D-1), permission = ED-1028 | ubah (rev) |
| GET `opnames` | `@index` | - | ED-1028 | sudah ada |
| (service) | - | - | (rev) `OpnameService::scope()` (`:769-785`) → `{ is_root, folder_name, subfolder_count }` (`= scope_folder_count`); `first_name`/`other_count` dihapus (ED-1028 K-10 b). Satu sumber: `preview`, `show`, baris ED-1028 `index`, ED-1027 `VerificationService.php:313` | ubah (rev) |
| PUT/DELETE `opnames/{id}`, POST `opnames/{id}/scan`, PUT `opnames/{id}/confirm` | - | - | - | **dihapus** (rev) |

Cakupan dihitung sekali per request (tanpa static, D-3); `selection()` (`OpnameService.php:1218`) untuk semua endpoint ber-body. Tanpa 500 (D-6).

## 5. Perubahan FE per module - `src/containers/Archive` (master)
| Bagian | Perubahan |
|---|---|
| Registrasi (rev) | `configuration/endpoints.js:68-75`: `postArchiveOpnameScan` → `document-archive/opnames/scan`; baru `postArchiveOpnamePreview`, `postArchiveOpnamePreviewDocuments`; hapus `putArchiveOpname`, `deleteArchiveOpname`, `putArchiveOpnameConfirm`; `postArchiveOpname` = Confirm. Hook `archive.api.js:30-45` |
| Locale (rev) | id-ID `archive.All Archive` = "Semua Arsip", `archive.All Archive · root` = "Semua Arsip · root" (`archive.locale.js:208-209`); en tetap |
| `ArchivePage/index.js` (rev) | `ArchiveOpnameModal` (`:593`) hanya di-mount bila `canOpname` (`:167`); "+" & menu baris sudah bergantung `canOpname` |
| `ArchiveOpnameModal/index.js` (rev) | state `scans` (terbaru di atas) & `selectedAt` (dari `GET opnames/folders`, tiap Step 1 dimuat); hapus `idArchiveOpname`, `saveSession`, `deleteSession`, ref `confirmed`. Next/Step 1 dilewati = tanpa request. Masuk Step 2/3 → `postArchiveOpnamePreview` (`toOpnameRequest` + `scans`). Confirm = `postArchiveOpname` (+ `scans` lama → baru, `selectedAt`); `ARCHIVE414`/`ARCHIVE416` → Step 1 dimuat ulang (stepper lengkap, scan tetap); error lain toast, tetap di Step 3. Cancel/✕: ada scan → `modal.confirm` lalu tutup, tanpa scan → tutup; tanpa request |
| Tag judul & Scope (rev) | `getScopeTag` (`archive.function.js:290-303`) satu helper (BR-30): masukan `{ isRoot, folderName, subfolderCount }` → dasar = root ? `archive.All Archive · root` : `folderName`; `subfolderCount` > 0 → `archive.{name} + {count} subfolder`. Modal: Step 1 count 0, Step 2-3 = subfolder dicentang (lokal); riwayat & 3 sesi (`renderOpnameScope` `:793-801`) = `scope.subfolderCount`. `firstName`/`otherCount` dihapus (juga JSDoc); test `archive.function.test.js:480-497,860-917` & `ArchiveVerification/index.test.js:28,126,222` disesuaikan (O-8) |
| Steps (rev) | BR-29; gaya `.steps-container` di `archive.style.less` (acuan `approvalTransaction.style.less:142-153`) |
| Step 2 `ArchiveOpnameScan.js` (rev) | scan → `postArchiveOpnameScan(body + code)`; duplikat dicek lokal (BR-14, kunci = trim akhir + huruf kecil); angka sesi dari `scans`; masuk lagi dengan scan → klasifikasi ulang lewat `postArchiveOpnamePreviewDocuments` `result=scanned` (semua halaman); `Segmented` (`:265`) → `CheckableTag` (fokus kembali ke input scan) |
| `archive-components/ArchiveOpnameResult` (rev, bersama ED-1028) | `Segmented` (`:108`) → `Tag.CheckableTag` (wrap; tepat satu aktif, klik tag aktif tidak mengosongkan, label + jumlah); + prop opsional `body` → `postArchiveOpnamePreviewDocuments` (Step 3), tanpa `body` → `getArchiveOpnameDocuments` (riwayat ED-1028) |
| Step 3 `ArchiveOpnameConfirm.js` (rev) | kartu & peringatan dari pratinjau; `ArchiveOpnameResult body={…} defaultResult="scanned"`; root peringatan = `archive.All Archive` |

## 6. Acceptance criteria
`QA_DB=api_sidomaju`; role QA punya `Opname Document` (id 1139: seed ulang & grant role uji diulang di run ED-1025); user non-superadmin berlokasi Semarang saja.

| AC | Given / When / Then | Tag | BR | Cek |
|---|---|---|---|---|
| AC-1 (rev) | Updater `AddArchiveOpname` & `DeleteArchiveOpnameDraft` terdaftar di `config.php` (tidak harus kunci terbesar), dijalankan dua kali tanpa error; objek ED-1026 ada (3 tabel, 4 kolom & indeks `archives`; asersi per objek, bukan `SHOW CREATE` utuh); sesudah pembersih: 0 sesi status 1/3, 0 baris `_folders`/`_documents` tanpa sesi; sesi status 2 & `archives` tidak berubah | [BE] | §3, BR-21 | http (DB) |
| AC-2 (rev) | `permission_salesman.sql`: `Opname Document` id **1139** (module 1266), checks `(1139,1082)`, `(1081,1139)`, unchecks `(1139,1081)`, `(1081,1139)`, `(1082,1139)`; tidak ada baris Archive ber-id 1133; hanya di berkas lisensi Salesman Activity. DB QA: baris & checks 1139 ada (seed ulang run ED-1025) | [BE] | BR-2 | berkas seed + DB; lisensi EPIC K-2 b |
| AC-3 (rev) | Role tanpa `Opname Document`: tanpa "+"/menu baris Opname, modal opname tidak di DOM, tanpa request `opnames/*`; `opnames/folders`, `/scan`, `/preview`, `/preview/documents`, POST `opnames` → 403 `GE0114` (`parameter` "Opname Document"). GET `opnames*` (baca sesi) diuji ED-1028 AC-14, bukan di sini. Tanpa lisensi → menu Archive tidak ada, endpoint 403 `GE0114` | [BE+FE] | BR-2 | http + e2e; lisensi EPIC K-2 b, tukar lisensi manual Gate 2 |
| AC-4 (rev) | Tanpa error konsol/request gagal: list, "+", menu ⋯ folder, Step 1, Step 2, modal kamera, Step 3 - desktop dan 375 px | [FE] | BR-3, 29 | e2e |
| AC-5 (rev) | User Semarang, GET `opnames/folders` root → `children` tanpa CABANG - JOGJA, `document_count` subtree, `opnamed_today` null, `is_default_checked` true, `selected_at` = waktu server; superadmin 1/2 → semua | [BE] | BR-4,5,8 | http |
| AC-6 | `opnames/folders` id dokumen → 400 `ARCHIVE417`; tidak ada → 404 `ARCHIVE400`; di luar scope/tanpa View → 403 `ARCHIVE407` | [BE] | BR-2 | http |
| AC-7 | Step 1: belum diopname → tercentang; sudah → tidak, "Sudah diopname hari ini · HH:mm"; dicentang ulang → "(dicentang ulang)" + Lanjut aktif + teks; footer | [FE] | BR-8,10 | e2e |
| AC-8 (rev) | Opname folder ZULFA (tanpa subfolder, belum diopname) → Step 1 dilewati, stepper 2 langkah, tag "ZULFA", tanpa request tulis saat dibuka | [FE] | BR-12,15,21 | e2e (log request) |
| AC-9 (rev) | `opnames/scan`, `/preview`, `/preview/documents`, POST `opnames`: subfolder bukan anak langsung terlihat → 400 `ARCHIVE416`; F di luar scope → 403 `ARCHIVE407` (sebelum 422); `code` kosong/>255/non-Latin-1, `scans.*.scanned_at` bukan `Y-m-d H:i:s`, POST `opnames` tanpa `selected_at` → 422; baris 3 tabel opname & kolom verifikasi `archives` tidak berubah oleh scan/preview | [BE] | BR-2,21,22 | http (DB) |
| AC-10 (rev) | POST `opnames/scan`: dokumen di folder tercakup → `verified`; folder lain/root/`is_active=-1` → `not_found` (`is_out_of_scope`); kode tak dikenal/nama folder → `invalid`; `row.scanned_at` = waktu server; tanpa baris DB baru | [BE] | BR-13,21,22 | http (DB) |
| AC-11 (rev) | Step 2: kode sama (juga beda huruf besar/kecil) discan lagi → tanpa request, baris & angka tetap; POST `opnames`/pratinjau dengan kode ganda → dihitung sekali, satu baris snapshot | [BE+FE] | BR-14 | e2e + http |
| AC-12 (rev) | Rute lama PUT/DELETE `opnames/{id}`, POST `opnames/{id}/scan`, PUT `opnames/{id}/confirm` → 404/405, bukan 500; GET `opnames/{id}` & `/documents` (role ber-`Opname Document`) id tidak ada (termasuk id bekas draft) → 404 `ARCHIVE412`; akses sesi user lain & permission baca = ED-1028 (skenario lama "404 bagi non-superadmin" & role 29 `List Archive` diganti) | [BE] | BR-21 | http |
| AC-13 | Step 2: ketikan scanner USB (fokus di dalam & di luar input) → baris baru; ikon scan membuka modal kamera yang tetap terbuka; pencarian Archive tidak terpicu | [FE] | BR-16 | e2e; kamera nyata manual (Gate 2) |
| AC-14 (rev) | Step 2: `CheckableTag` All/Verified/Not found/Invalid menyaring tabel, tepat satu aktif, jumlah = kartu sesi; 375 px membungkus tanpa gulir horizontal; `.row-invalid`/`.row-not-found`; tag judul "<F> + 3 subfolder" (3 dicentang), "<F>" tanpa subfolder, root "All Archive · root + N subfolder" | [FE] | BR-15,17,18 | e2e; warna manual |
| AC-15 (rev) | POST `opnames/preview` (pilihan + `scans`) → `counts` (`total_documents`, `verified_before`, `scanned/verified/not_found/invalid/unscanned`, `unverified`) sesuai BR-20, `result_counts`, `warnings` (folder tanpa lanjut, `unverify_count` > 0), `scope.subfolder_count`; tanpa tulis DB (skenario lama "GET draft" diganti) | [BE] | BR-17,19,20,21 | http |
| AC-16 (rev) | POST `opnames/preview/documents` `result` (6 nilai) & `page`/`pagination` → paginator benar; scan terbaru dulu lalu belum discan per kode; Not found `folder_name` null, `is_out_of_scope` true; belum discan `scanned_at` null; `scanned_at` scan = nilai dikirim | [BE] | BR-19,20 | http |
| AC-17 (rev) | Step 3: filter awal Scanned, kartu & peringatan tampil; Back ke Step 2 tanpa kehilangan scan; Confirm (Popconfirm) → satu POST `opnames`, pesan sukses, modal tertutup, list dimuat ulang; DB: tepat satu sesi baru | [FE] | BR-19,27 | e2e + DB |
| AC-18 (rev) | POST `opnames` folder belum diopname: discan → `is_verified=1` + `verified_at/by`, `id_archive_opname`; verified tak discan → 0; sesi status 2 + angka + `selected_at` = body; folder level 0-2; baris `result=4`; `scanned_at` = body, urut sesuai `scans` | [BE] | BR-11,25 | http (DB) |
| AC-19 | Opname ulang hari sama, dicentang ulang + Lanjut → verified tak discan tetap 1; Lanjut nonaktif → 0, `unverified_count` = peringatan Step 3 | [BE] | BR-10,11 | http (DB) |
| AC-20 | Subfolder tak dicentang & di luar lokasi: kolom verifikasi dokumennya tetap; user Semarang & user lokasi lain masing-masing opname root → superadmin melihat keduanya "Sudah diopname hari ini" | [BE] | BR-5,9 | http (DB) |
| AC-21 | Not found & Invalid: `archives` (folder, status, `is_active`, `is_verified`) tidak berubah | [BE] | BR-24 | http (DB) |
| AC-22 (rev) | B memuat Step 1 (`selected_at` T), A mengonfirmasi X sesudah T → POST `opnames` B 400 `ARCHIVE414` (`msg_code`, `result.folders`), tanpa sesi B; body sama dikirim dua kali → kedua 400 `ARCHIVE414`, satu sesi; FE kembali ke Step 1 dengan X "Sudah diopname hari ini", scan tetap, Confirm berikutnya berhasil | [BE+FE] | BR-23 | http + e2e |
| AC-23 (rev) | `selected_at` kemarin, atau satu `scans.*.scanned_at` kemarin → 400 `ARCHIVE415`, DB tidak berubah; sesi terkonfirmasi kemarin → Step 1 "Belum diopname" & tercentang | [BE] | BR-7,23 | http |
| AC-24 (rev) | Scan beberapa kode (Verified/Not found/Invalid) lalu Cancel → Ya; ulangi dengan ✕, Back lalu Cancel, dan tanpa scan → tanpa POST `opnames`/PUT/DELETE; baris 3 tabel opname & kolom verifikasi `archives` sama dengan sebelum dibuka; buka lagi = tanpa scan | [BE+FE] | BR-21 | e2e (log request) + DB |
| AC-25 (rev) | Dokumen dipindah ke luar cakupan sesudah discan → di pratinjau Step 3 dan saat Confirm hasilnya Not found, verified-nya tidak berubah | [BE] | BR-22 | http |
| AC-26 | Regresi: list, create folder, put-in, hand-over, receive tetap; dokumen baru `is_verified=0`; pindah/hand over tidak mengubah `is_verified` | [BE] | BR-26 | http |
| AC-27 (rev) | Kinerja: `opnames/folders` root, `opnames/preview`, `preview/documents` dan POST `opnames` pada Backup Arsip (±24 rb dokumen) dengan 1.000 scan, masing-masing < 30 dtk tanpa 500 | [BE] | BR-4 | http (waktu) |
| AC-28 (rev) | Bahasa id: tag root "Semua Arsip · root", Folder dokumen root & peringatan root "Semua Arsip"; en "All Archive"; `ARCHIVE414` bentrok root: `[0]` "Semua Arsip" (id) / "All Archive" (en) | [BE+FE] | BR-28 | http (user id & en) + e2e id |
| AC-29 (rev) | Stepper small + navigation; di 375 px langkah aktif tergulir ke tampilan saat pindah ke Step 2/3 dan kembali ke Step 1; desktop lebar dibatasi | [FE] | BR-29 | e2e screenshot 375/768/desktop; tampilan manual (Gate 2) |
| AC-30 (rev) | Riwayat ED-1028 (drawer Hasil Opname) tetap jalan dengan `CheckableTag`: default Scanned, jumlah dari `resultCounts`, ganti filter → request baru halaman 1, wrap di 375 px | [FE] | BR-19 | e2e drawer riwayat + test komponen |
| AC-31 (rev) | `scope` di POST `opnames/preview`, `GET opnames/{id}`, baris `GET opnames` dan `last_sessions` ED-1027 = tepat kunci `is_root`, `folder_name`, `subfolder_count` (tanpa `first_name`/`other_count`), `subfolder_count` = subfolder dicentang. `getScopeTag`: root N=0 "Semua Arsip · root" (id) / "All Archive · root" (en), root N=3 "… · root + 3 subfolder", folder "ZULFA", "Backup + 3 subfolder" | [BE+FE] | BR-15,28,30 | http + unit `archive.function.test.js`; tampilan riwayat = regresi ED-1027 AC-28 / ED-1028 AC-19 |

## 7. Subtask
Subtask lama ED-1032 … ED-1068 tetap (run.md). Baru untuk perbaikan:

| Kunci | Judul | Layer | AC | Module |
|---|---|---|---|---|
| (rev) | [BE] Archive - opname tanpa draft: scan & pratinjau tanpa simpan, hapus rute draft/batal, `scope` tanpa nama subfolder | BE | AC-9, AC-10, AC-12, AC-15, AC-16, AC-25, AC-31 | DocumentArchive |
| (rev) | [BE] Archive - Konfirmasi Opname sekali kirim (scans, selected_at, bentrok, hari, label root en/id) | BE | AC-11, AC-18..AC-23, AC-27, AC-28 | DocumentArchive, lang |
| (rev) | [BE] Archive - Updater pembersih sesi draft/batal, hapus kode pesan lama | BE | AC-1 | UpdateVersion, lang |
| (rev) | [FE] Archive - modal Opname tanpa draft (scan lokal, pratinjau, konfirmasi sekali kirim, batal tanpa request) | FE | AC-8, AC-11, AC-17, AC-22, AC-24 | Archive |
| (rev) | [FE] Archive - filter hasil opname CheckableTag (Step 2 & `ArchiveOpnameResult` bersama ED-1028) | FE | AC-14, AC-30 | Archive |
| (rev) | [FE] Archive - Steps, format Scope satu helper "<folder> + N subfolder", terjemahan "All Archive", opname tersembunyi tanpa permission | FE | AC-3, AC-4, AC-28, AC-29, AC-31 | Archive |
| (rev) | [QA] Skenario opname tanpa draft + skenario usang (AC-1, AC-2, AC-12, AC-15, scenario1/2/4, e2e) + catatan skenario usang ED-1027/ED-1028 | QA | semua (rev), termasuk AC-2 | Archive |

QA (rev, aturan §5 epic) - di folder ini: `qa/scenario2..6` & e2e ke alur tanpa draft; AC-1 longgar ("terdaftar", asersi per objek); AC-2 + `scenario1-schema-seed.php:238-277` → 1139; `scenario2-folders-store.php:380-430` tanpa asersi baca sesi role 29 (ED-1028 AC-14); `scenario4-session-access.php:98-101,161,302-312` → AC-12 & AC-31; e2e `fixture.php:13,39` → 1139 (lebih baik dicari per nama). Regresi ED-1027/ED-1028 dijalankan; skenario mereka yang usang **hanya dicatat** di laporan QA, tidak diubah (gate1 §5: 1027 `qa_lib.php:521-575`, `scenario5-sessions.php:54`, e2e fixture/`:618,649,833`; 1028 `qa_lib.php:537-580`, `qa_hist.php:153-155`, `scenario1-list.php:228-265`, `scenario5-access.php:150-154`, `.ant-segmented-item`; tambahan K-10 b: kunci `scope` 1027 `scenario5-sessions.php:103-118`, 1028 `scenario1-list.php:177-189`).

**Jira subtask (rev), dibuat 2026-10-08 (sprint 46):** ED-1088 [BE] tanpa draft · ED-1089 [BE] konfirmasi sekali kirim · ED-1090 [BE] Updater pembersih · ED-1091 [FE] modal tanpa draft · ED-1092 [FE] CheckableTag · ED-1093 [FE] Steps/Scope/terjemahan/permission · ED-1094 [QA]

## 8. Keputusan untuk developer
Jawaban Gate 1 (2026-10-06) tetap: K-2..K-10 = a, K-11 = ya semua, EPIC K-2 = b. Diubah revisi: **K-1 (draft di server) → b** (FB-3); K-8 acuan waktu = muat Step 1; K-11 (i) dicek di FE, (v) tutup = konfirmasi lalu tutup tanpa request.

Pertanyaan revisi (dijawab Gate 1 ronde 2, `epics/ED-1022-gate1.md` §9b; semua risiko rendah, rekomendasi a):

- **K-12** Arti "data scan belum dikirim ke BE sampai konfirmasi" (FB-3). a) tiap scan ke `POST opnames/scan` hanya untuk klasifikasi, hasil langsung tampil (04b); b) tanpa request per scan, hasil baru di Step 3.
  Jawaban Gate 1 (2026-10-08): a - tiap scan diklasifikasi tanpa simpan (BR-22, contract §2).
- **K-13** Baris draft (status 1) & batal (3) yang sudah ada di DB. a) Updater menghapusnya + baris folder/dokumen, semua tenant, idempoten; b) dibiarkan.
  Jawaban Gate 1 (2026-10-08): a - Updater `DeleteArchiveOpnameDraft` (§3, AC-1).
- **K-14** Format Scope riwayat (ED-1028) & 3 sesi (ED-1027) sesudah FB-2. a) satu format "<F / All Archive · root> + N subfolder" (`getScopeTag`, `scope.subfolder_count`); b) riwayat tetap "<subfolder pertama> + N subfolder".
  Jawaban Gate 1 (2026-10-08): a - satu format di semua tempat, root id "Semua Arsip · root" (BR-30, AC-31).
- **K-15** Nilai FE yang dipercaya saat Confirm (`selected_at`, `scanned_at`). a) apa adanya, divalidasi format + hari ini (`ARCHIVE415`); b) ditandatangani server (`encrypt()`); c) `scanned_at` = waktu Confirm.
  Jawaban Gate 1 (2026-10-08): a - validasi format + hari ini (BR-23, AC-23).
- **K-16** Subfolder terpilih yang di tengah sesi tidak lagi subfolder langsung terlihat. a) 400 `ARCHIVE416`, FE kembali ke Step 1 seperti `ARCHIVE414`; b) dikeluarkan diam-diam (`OpnameService.php:1311-1335`).
  Jawaban Gate 1 (2026-10-08): a - 400 `ARCHIVE416`, scan tetap (BR-22, AC-9).

Lintas item yang mengenai item ini:
- **EPIC K-3** (server menegakkan `Opname Document`) - Jawaban Gate 1 (2026-10-08): a - server + FE; baca sesi = `Opname Document`, route & AC milik ED-1028 (BR-2, §4, AC-3).
- **EPIC K-4** (id permission bentrok branch AI) - Jawaban Gate 1 (2026-10-08): a - `Opname Document` = 1139, seed oleh ED-1025 (§3, AC-2).
- **ED-1028 K-10** (`first_name`/`other_count` di `scope`) - Jawaban Gate 1 (2026-10-08): b - dihapus di `scope()` oleh item ini; contract ED-1027/ED-1028 mengikuti (BR-30, AC-31).
- **ED-1071 K-5** (regex `code` scan) - Tidak berlaku: ED-1071 ditunda (Gate 1); regex `code` tetap.

## 9. Di luar cakupan
- Scope lokasi & transaction type → **ED-1024**; hak folder, `viewableFolderIds()`, seed `Opname Document` 1139 → **ED-1025** (FB-9 tidak mengubah View; opname tidak terpengaruh).
- Kolom Document Verified, tab/modal Verification, FB-1, FB-8b, `last_sessions` → **ED-1027** (membaca `archives.is_verified` & sesi `status=2`).
- Riwayat opname, saringan D-1 `folders`/`warnings` di `show`, FB-8c, permission & route baca sesi (`GET opnames*`) → **ED-1028**; `ArchiveOpnameResult`/`getScopeTag`/`scope()` diubah di item ini, ED-1028 hanya regresi.
- ED-1029 (by User), ED-1069 (pindah/rename), ED-1072 (klik ⋯). Kolasi umum & parameter list v5 (search/sorts/pagination): ditunda Gate 1, bukan run ini; opname tetap 422 untuk kode non-Latin-1.
- Menyimpan/memulihkan sesi berjalan sesudah reload atau tutup tab, peringatan `beforeunload` (sesi hilang, diterima FB-3).
- Verified otomatis saat cetak; pindahkan dokumen Not found; `archives.history` opname; export; aplikasi mobile.

## Catatan implementasi
- (be-dev 2026-10-07) `scope_name` = subfolder level 1 pertama / nama F / NULL root; `scope_folder_count` = jumlah level 1; `OpnameService::scope()` public (04/05); (rev Gate 1) keluaran `{ is_root, folder_name, subfolder_count }`.
- (tetap) `code` hanya Latin-1, lainnya 422 (rev: juga `scans.*.code`); id non-ASCII → 404 tanpa query; `id_archive` melanggar rule → 422 tanpa lookup (D-1 QA r1).
- (tetap) Bentrok = sesi lain `confirmed_at >= selected_at`, dicek di awal dan ulang dengan `LOCK IN SHARE MODE` sesudah UPDATE `archives`; (rev) `selected_at` dari body, `[0]` root = label BR-28.
- (tetap) UPDATE `archives` lewat query builder (`updated_at/by`, `history` tidak disentuh); `unverified_count` = dokumen 1 → 0; `is_verified_after`: Verified 1, belum discan = nilai sesudah, Not found = nilai dokumen, Invalid NULL; id snapshot = `newIds()`.
- (tetap) "Sudah diopname hari ini" = `archive_opname_folders` level 0-2 sesi `status=2`, `confirmed_at` hari ini; root hanya level 0 `id_archive` NULL. `status_label`/`result_label` en/id dari konstanta model.
- (be-dev rev 2026-10-09) POST `opnames`: urutan cek folder opname (403/404/400, sebelum 422) → 422 → `ARCHIVE415` → `ARCHIVE416` → `ARCHIVE414`. `selected_at` disimpan apa adanya dan menjadi acuan bentrok (catatan "min(`selected_at`, sekarang)" diganti, lihat baris QA D-1 di bawah). Sesi, folder & dokumen ditulis sesudah cek bentrok kedua (locking read), satu transaksi.
- (be-dev rev 2026-10-09) Kode ganda dibuang di PHP sebelum insert ke UNIQUE `(id_archive_opname, code)`: kunci = `mb_strtolower(rtrim(code))` (huruf Latin-1 non-ASCII ikut disamakan seperti kolasi; sebelumnya `strtolower`, dulu draft memakai pembanding SQL). Lanjut opname (`is_continue`) diabaikan untuk folder yang belum diopname hari ini, dinilai saat request (pratinjau & Konfirmasi).
- (be-dev fix QA rev D-1 2026-10-09) `selected_at` sesudah waktu server sekarang (mis. hari ini 23:59:59) → 400 `ARCHIVE415` (cek yang sama dengan "bukan hari ini", sebelum `ARCHIVE416`/`ARCHIVE414`; tanpa kode baru). Acuan bentrok = `selected_at` apa adanya; karena tidak pernah sesudah waktu Confirm, kiriman ulang body yang sama selalu `ARCHIVE414` dan sesi user lain sesudah muat Step 1 selalu terdeteksi (BR-23). FE tidak terpengaruh: `selected_at` selalu dari `GET opnames/folders`.
