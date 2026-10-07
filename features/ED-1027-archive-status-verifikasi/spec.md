---
key: ED-1027
epic: ED-1022
title: Archive - Status verifikasi dokumen (kolom Document Verified, tab Verification, detail per transaction type)
status: approved
batch: ED-1027
fe_modules: [Archive]
new_fe_modules: []
be_modules: [DocumentArchive, UpdateVersion]
qa_model: sonnet
contract: contract.md
depends_on: [ED-1024, ED-1025, ED-1026]
---
## 1. Ringkasan

Hasil opname (item 03) terlihat di halaman Archive tanpa membuka opname: kolom baru **Document Verified**
(folder `verified / total`, dokumen ikon verified/unverified), ringkasan **"Document Verified V / T"** di atas
breadcrumb untuk seluruh Archive, modal **05 Detail verifikasi per Transaction Type** (angka per transaction
type + 3 sesi opname terakhir), dan tab **Verification** read-only di drawer folder (angka yang sama untuk satu
folder + 3 sesi terakhir yang mencakup folder itu). Daftar transaction type diambil dari satu sumber milik
item 01 (R-23). Angka hanya menghitung dokumen yang boleh dilihat user (scope lokasi 01 + hak View folder 02).
Item ini **hanya membaca**: status verified dan data sesi ditulis oleh opname 03. Tanpa tabel baru; satu
Updater reset display setting `documentArchive`.

## 2. Aturan bisnis

| BR | Aturan | Sumber |
|---|---|---|
| BR-1 | List Archive punya kolom **Document Verified** (sesudah Salesman, sebelum Status), ada di kolom aktif & tersedia display setting, tidak bisa di-sort, bukan filter | [S2 01]; urutan kolom `Config/displayColumn/documentArchive.php:5-58` |
| BR-2 | Baris folder menampilkan `verified/total` folder itu (K-3: folder + semua subfolder yang terlihat user). Baris dokumen menampilkan ikon centang hijau (verified) atau lingkaran abu (unverified). Folder yang tidak bisa dibuka user (tanpa View, 02 K-6 a) menampilkan `-` | [S2 01 keterangan "Folder menampilkan verified / total, dokumen menampilkan ikon…"]; 02 BR-10 |
| BR-3 | "Total" = dokumen archive aktif: `archives.type = 2` dan `is_active > 0`, apa pun statusnya (di folder, Handed Over, Taken). Dokumen tercetak yang belum disimpan (`is_active = -1`) dan yang dihapus (`is_active = 0`) tidak dihitung | filter list `ArchiveService.php:29`; `DocumentService.php:133` (`-1` saat cetak); [S2 01: baris "Taken"/"Handed Over" berikon verified] |
| BR-4 | "Verified" = status verified dokumen **saat ini** yang ditulis Confirm Opname (03), dibaca dari satu titik milik 03; "Unverified" = total − verified. Pergantian hari tidak me-reset angka (K-1, harus sama dengan G-3 di 03) | R-13, R-14; [S2 04c "akan berubah menjadi unverified"]; K-1 |
| BR-5 | Dokumen yang dihitung = dokumen yang boleh dilihat user: lolos scope lokasi per baris (01 `Archive::scopeVisibleToUser`) **dan** berada di folder yang boleh di-View (02 `viewableFolderIds()`) atau di root. Superadmin `is_superadmin` 1/2 = semua. Angka sebuah folder = isi yang user lihat saat membuka folder itu | PK-2 a (scope seluruh Archive); 01 BR-1..5; 02 BR-4/5; K-2 |
| BR-6 | Ringkasan di atas breadcrumb: "Document Verified **V** / T" + progress bar + "seluruh dokumen di sistem" + tautan "Lihat detail per transaction type ›". Selalu **All Archive** (tidak ikut folder aktif, pencarian, filter, atau halaman) dan tampil di root, di dalam folder, dan saat mencari. Angkanya diperbarui setiap kali tabel dimuat (buka halaman, pindah folder, reload, sesudah aksi yang me-refresh tabel termasuk Confirm Opname 03) | [S2 01 "Di samping breadcrumb ada ringkasan verified seluruh dokumen di sistem"] |
| BR-7 | Tautan BR-6 membuka modal **Document Verified** subjudul "All Archive — seluruh dokumen di sistem": V / T + progress + "V dokumen terverifikasi dari T dokumen di seluruh folder"; tabel per transaction type (BR-8); overview Opname History (BR-10); catatan "Handover, Receive, dan Store adalah aktivitas Archive, bukan transaction type, sehingga tidak muncul pada breakdown ini. Breakdown yang sama tersedia per folder pada tab Verification di folder detail."; tombol Close. Read-only. Angka header modal = angka ringkasan BR-6 | [S2 05]; [S4 hal.4 Gambar 5]; R-4 |
| BR-8 | Tabel Transaction Type · Verified · Unverified · Total: satu baris per tipe di daftar 01 (`ArchiveDocument::TRANSACTION_TYPES`), urutan & label sama dengan filter Type; **semua tipe tampil walau 0**; baris akhir **Total** = jumlah kolom = angka header. Handover/Receive/Store bukan baris | R-23; [S2 05 catatan]; [S4 hal.4]; 01 BR-11/12 |
| BR-9 | Drawer folder (menu baris "Lihat") punya tab **Verification** sesudah Detail dan Permission (02): read-only, tidak ikut tombol Save; V / T folder + progress + "V dokumen terverifikasi dari T dokumen di folder ini" (= angka kolom BR-2 folder itu); tabel BR-8 untuk folder itu; overview BR-11; catatan "Read-only. Handover, Receive, dan Store adalah aktivitas Archive, bukan transaction type…" (teks desain) | [S2 02 tab Verification + keterangan]; [S4 hal.4 "tab Verification … read-only"]; 02 BR-15 |
| BR-10 | Overview Opname History di modal 05: maksimal **3 sesi terkonfirmasi terbaru** yang terlihat user (K-4), terbaru di atas. Kolom Waktu (tanggal + jam), User, Scope, Total dokumen, Verified, Not found, Invalid = angka sesi saat dikonfirmasi (03), tidak dihitung ulang | [S2 05]; K-3 brief (S2 menang, R-4) |
| BR-11 | Overview di tab folder F: maksimal 3 sesi terkonfirmasi terbaru yang **mencakup F** (F atau subfoldernya, K-3). Kolom Waktu, User, Scope sesi, **Dokumen folder** (jumlah dokumen F yang direkam saat sesi itu), **Verified di folder ini** (dokumen F yang hasilnya Verified di sesi itu). Not found & invalid tidak tampil di sini | [S2 02 keterangan "History menampilkan 3 sesi opname terakhir yang mencakup folder ini — angkanya adalah potongan folder ini dari sesi tersebut, dan jumlah dokumen direkam sesuai kondisi folder saat itu"] |
| BR-12 | Scope sesi ditulis "<folder opname> + N subfolder" (tanpa "+ N…" bila N = 0); opname dari root = "All Archive · root". Format sama dengan tag scope Step 2 milik 03 | [S2 05 data]; R-15 |
| BR-13 | CTA "Lihat semua history ›" di kedua overview adalah titik masuk 05b (milik 05). Sebelum 05 terpasang CTA tidak dirender (K-6) | [S2 02, 05]; R-24 |
| BR-14 | Tanpa dokumen: "0 / 0", progress 0 %, semua baris tipe 0. Tanpa sesi: tabel overview kosong (empty state standar) | turunan BR-6..11 |
| BR-15 | Archive hanya untuk lisensi Salesman Activity: menu/route FE sudah dijaga; endpoint baru dijaga `permission_v5:List Archive` (pola 01 K-4) → tanpa lisensi/permission 403 `GE0114`, tanpa data | R-2; `permission_salesman.sql:67-76`; 01 BR-15 |
| BR-16 | Detail verifikasi per folder: id tidak ada / folder nonaktif → 404 `ARCHIVE400`; di luar scope lokasi → 403 `ARCHIVE407` (01); tanpa View → 403 `ARCHIVE407` (02); id berupa dokumen → 400 `ARCHIVE417` (03) | D-6; 01 `findVisible`; 02 `ArchivePermissionService::assert` |

Kolom v3: Archive tidak ada di v3 (01 §2), item ini juga tidak menulis tabel apa pun → tidak ada kolom v3 yang
harus dijaga (D-4/D-5).

## 3. Model data

| Hal | Isi |
|---|---|
| Tabel baru/berubah | **Tidak ada.** Dibaca (DB tenant): `archives`, `archive_documents`, `archive_locations` (01), `archive_permissions` (02), tabel sesi & status verified milik 03 (asumsi A-1..A-5 di bawah) |
| Display setting | `Config/displayColumn/documentArchive.php`: tambah kolom `{title: {en: 'Document Verified', id: 'Dokumen Terverifikasi'}, type: 'string', sort: false, data_index: 'documentVerified'}` di `current_columns` (sesudah Salesman) dan `available_columns`. `documentVerified` bukan kolom tabel → `ArchiveService::index` memberinya lewat argumen `excepts` `selectBuilderQuery` (pola `SalesmanActivity/ItemService.php:25`, `Setup/Customer/CustomerDeviceService.php:30`), jangan sampai `addSelect` kolom yang tidak ada |
| Updater | 1 berkas `Modules/UpdateVersion/Updaters/<Y_m_d_H_i_s>_AddDocumentVerifiedColumnArchive.php` + daftar di `Updaters/config.php`: hapus baris `column_display_settings` `module_name = documentArchive` lalu `MyHelper::getDisplaySetting('documentArchive')` (salinan pola `2026_05_21_16_06_22_UpdateColumnTypeOnArchive.php`, terdaftar `config.php:60`). Akibat: pilihan kolom yang pernah diubah user di module Archive kembali ke default (perilaku semua Updater display) |
| Permission | Tidak ada yang baru. Endpoint baru = `List Archive` (1082) |
| Kode pesan | Tidak ada yang baru (gate1 O-3: `ARCHIVE420` dihapus). Dipakai ulang: `ARCHIVE200` (sukses baca, `en_EN.php:3345`), `ARCHIVE400` (404), `ARCHIVE407` (403, 01), `ARCHIVE417` (400 bukan folder, 03) |
| Service baru | `Http/Services/DocumentArchive/VerificationService.php` (`extends DocumentArchiveService`): satu tempat hitungan verifikasi, dipakai list (kolom + ringkasan), detail All, detail folder. Hitung tanpa N+1: satu query semua folder aktif (id, parent) - pakai ulang yang dimuat `ArchivePermissionService` 02 - + satu agregat dokumen per folder induk & per `transaction_type` (sum verified), lalu digulung ke folder leluhur di memori. Tanpa cache static lintas request (D-3) |
| Titik pakai ulang untuk 05 | `VerificationService::lastSessions($idArchive = null, $limit = 3)` + satu scope "sesi yang terlihat user" (K-4 + EPIC K-1) + satu format baris sesi (`session_row` = nama kolom 03 + `scope` bentuk 03, sama dengan baris list 05; gate1 C-2). 05 (daftar semua sesi) wajib memakai scope & format yang sama |

**Asumsi atas model 03** (`depends_on: ED-1026`; epic-check C-3: A-1, A-3, A-4 cocok, A-2 diperbarui, A-5 berlaku karena 03 K-2 a & K-4 a; nama tabel/kolom mengikuti 03):

| # | Yang dibutuhkan item ini dari 03 |
|---|---|
| A-1 | Satu status **verified saat ini** per dokumen (mis. flag di `archives`/`archive_documents`), ditulis hanya oleh Confirm Opname, plus satu titik baca (scope/helper) yang dipakai 04 |
| A-2 | Satu baris per **sesi terkonfirmasi** (`archive_opnames` `status=2`): `confirmed_at`, `created_by` (username; tanpa `id_user`), `id_archive` (`null` = root), `scope_name`/`scope_folder_count`, `total_documents`, `verified_count`, `not_found_count`, `invalid_count`. Sesi batal/belum dikonfirmasi tidak ikut |
| A-3 | Satu baris per **folder yang ikut sesi** (termasuk sub-subfolder yang ikut otomatis): jumlah dokumen folder itu saat sesi (snapshot) dan jumlah dokumennya yang Verified di sesi itu (atau hasil scan per dokumen yang menyimpan folder asal, supaya bisa dihitung) |
| A-4 | Indeks untuk "3 sesi terbaru" global dan per folder (sesi per waktu; folder-per-sesi per `id_archive`) |
| A-5 | Opname dari root ikut memverifikasi dokumen di root (K-8 a) dan arti verified lintas hari = jawaban G-3 (K-1) |

## 4. Endpoint v5

Prefix `api/v5`, group `document-archive` (`auth:api`); `permission_v5` per route mengikuti 01 K-4.

| Route | Controller@method | FormRequest | Service | Permission | Status |
|---|---|---|---|---|---|
| GET `document-archive/archives` | `ArchiveController@index` | - | `ArchiveService::index`: tiap baris + `document_verified` (`{verified, total}`; dokumen `total = 1`; folder tanpa View `null`); `result` + `document_verified_summary` (`{verified, total}` All Archive, BR-6) memakai `VerificationService` | `List Archive` (01) | ubah |
| GET `document-archive/verifications` | `VerificationController@index` (baru, `Http/Controllers/DocumentArchive/`) | - | `VerificationService::detail(null)`: angka All Archive, `transaction_types[]`, `last_sessions[]` (BR-7/8/10) | `List Archive` | baru |
| GET `document-archive/verifications/{id}` | `VerificationController@show` | - | `VerificationService::detail($id)`: `findVisible($id)` 01 (404/403), `ArchivePermissionService::assert($id, view)` 02 (403), bukan folder → 400 `ARCHIVE417` (03); angka folder, `transaction_types[]`, `last_sessions[]` dengan `folder_total_documents`/`folder_verified` (BR-9/11) | `List Archive` | baru |

Route baru di `Routes/DocumentArchive/api.php` sebagai `Route::prefix('verifications')` sendiri (tidak berbagi
segmen dengan `archives/{id}`, jadi tidak ada masalah urutan route). Controller baca tanpa transaksi, pola
`ArchiveController.php:27-30`. Ringkasan All Archive ikut di respons list (preseden `breadcrumbs`,
`ArchiveService.php:121`) supaya header selalu sinkron dengan tabel tanpa request kedua; modal 05 tetap
memanggil endpoint detail. Bentuk lengkap: `contract.md`.

## 5. Perubahan FE per module

**Archive** (`src/containers/Archive`, master dengan aksi baris kustom `archiveActionColumn`). Tampilan
mengikuti EQUAL, struktur & alur dari desain (`knowledge/design-sources.md`).

| Bagian | Perubahan |
|---|---|
| Render kolom `documentVerified` | helper baru di `archive.function.js` (dipakai `ArchivePage/index.js:290-358`, `archive-components/ArchiveTable/index.js:97-136` - picker modal Pindahkan & Bulk Move - dan `ArchiveBulkMoveModal` yang menerima `columns` dari halaman): folder → teks `verified/total` (teks biasa, K-7), `null` → `-`; dokumen → ikon `CheckCircleFilled` hijau / ikon lingkaran abu (ikon yang sudah dipakai FE). Tanpa ini kolom objek dirender mentah dan tabel crash |
| `archive-components/ArchiveVerificationSummary` (baru) | baris di antara toolbar/`ArchiveSelection` dan `.breadcrumbs` (`ArchivePage/index.js:453-464`): label "Document Verified", **V** / T (format angka locale), antd `Progress` tanpa teks (pola `Shipment/shipment-components/ShipmentLoad/index.js:185`), "seluruh dokumen di sistem", tautan "Lihat detail per transaction type ›". Data dari `data.result.documentVerifiedSummary` milik `useTable` (tanpa request sendiri) |
| `archive-components/ArchiveVerification` (baru) | isi bersama modal 05 & tab Verification, prop `idArchive` (`null` = All): kartu V / T + progress + kalimat (varian All / folder), `DefaultTable` Transaction Type · Verified · Unverified · Total (`pagination={false}`, baris Total di akhir; kolom statis FE), judul "Opname History" + CTA "Lihat semua history ›" hanya bila prop `onOpenHistory` ada (K-6), tabel 3 sesi (varian All: Waktu, User, Scope, Total dokumen, Verified, Not found, Invalid; varian folder: Waktu, User, Scope sesi, Dokumen folder, Verified di folder ini), catatan footer. Waktu = tanggal + jam (format tampilan `dateFormat`), Scope = `getScopeTag()` 03 di `archive.function.js` (BR-12), baris tidak bisa diklik (K-5) |
| `ArchiveVerificationModal` (baru, sejajar `ArchiveMoveModal/`) | `Modal` dari `components`, dibuka lewat `ref` dari tautan ringkasan; judul "Document Verified" + subjudul "All Archive — seluruh dokumen di sistem"; isi `ArchiveVerification idArchive={null}`; footer satu tombol Close. Fetch `getArchiveVerifications` saat dibuka. Blueprint: `SignIn/UserLoggedIn/UserLoggedInModal/index.js` (Modal + `DefaultTable` + fetch saat open) |
| `ArchiveDrawer` | tab **Verification** ditambahkan ke `Tabs` yang dibuat 02 (urutan Detail, Permission, Verification); isi `ArchiveVerification idArchive={id}`, di luar `Form`, fetch `getArchiveVerification` saat tab pertama kali dibuka; tidak mengubah `onFinish`/payload Save |
| `ArchivePage` | pasang `ArchiveVerificationSummary` + `ArchiveVerificationModal` (ref); tidak ada permission baru (halaman sudah dijaga `List Archive`) |
| Registrasi | `configuration/endpoints.js` grup Archive (`:58-67`): `getArchiveVerifications: 'document-archive/verifications'`, `getArchiveVerification: 'document-archive/verifications/:id'`; `archive.api.js`: dua `useAPI`; locale `archive.*` di `entries/en-US.js` + `id-ID.js` (semua teks desain di BR-6..11). Display setting FE tidak berubah (`displaySetting.documentArchive` sudah ada) |

## 6. Acceptance criteria

QA DB = `api_sidomaju`; data sesi/verified dibuat lewat endpoint opname 03 (bukan insert manual), dipulihkan sesudahnya (P-4).

| AC | Given / When / Then | Layer | BR | Cek |
|---|---|---|---|---|
| AC-1 | Given Updater dijalankan, When `GET document-archive/archives`, Then `result.columns` memuat `documentVerified` tepat sesudah `document.relatedEmployeeName` dan sebelum `history`, `sort=false`; `column_display_settings` `documentArchive` berisi kolom itu | BE | 1 | http + DB `column_display_settings` |
| AC-2 | When list root/isi folder/pencarian, Then tiap baris folder punya `document_verified.{verified,total}` = jumlah dokumen aktif terlihat di folder + subfoldernya (cocokkan SQL), dokumen `total=1, verified∈{0,1}`, folder tanpa View `null`; tidak ada 500 | BE | 2,3,5 | http `archives` + SQL pembanding |
| AC-3 | Given dokumen `is_active=-1` dan `0` di sebuah folder, Then tidak dihitung; dokumen Handed Over (`status=2`) & Taken (`status=3`) dihitung | BE | 3 | http + fixture lewat aksi cetak/hand-over |
| AC-4 | When list di root, di folder, dan dengan `search`, Then `result.document_verified_summary` sama di ketiganya dan = header `GET verifications` | BE | 6,7 | http |
| AC-5 | When `GET verifications`, Then `verified+unverified=total`; `transaction_types[].transaction_type` = daftar 01 dengan urutan & label = opsi `select/document-archive/archive/types` tanpa "Folder"; tipe bernilai 0 tetap ada; jumlah tiap kolom = header | BE | 7,8 | http |
| AC-6 | Given folder F, When `GET verifications/{F}`, Then angka header = `document_verified` F di list induknya; jumlah baris tipe = header | BE | 9 | http |
| AC-7 | Given user lokasi terbatas (user2) vs superadmin, Then angka user2 hanya dari dokumen/folder terlihat (BR-5), superadmin semua; folder tanpa View (02) tidak ikut di angka induk & ringkasan | BE | 5 | http dua user |
| AC-8 | `GET verifications/{id}`: id tak ada → 404 `ARCHIVE400`; id dokumen → 400 `ARCHIVE417`; folder di luar lokasi → 403 `ARCHIVE407`; folder tanpa View → 403 `ARCHIVE407`; tidak ada 500 | BE | 16 | http |
| AC-9 | Given role tanpa `List Archive` (setara tenant tanpa lisensi: permission 1266 hanya di-seed untuk Salesman Activity), When kedua endpoint baru, Then 403 `GE0114`, tanpa data | BE | 15 | cara seragam EPIC K-2 b: http role tanpa `List Archive`; seed tidak berubah (permission Archive hanya di `permission_salesman.sql`); FE tanpa route/menu baru (guard lisensi yang ada dicek di kode); tukar lisensi sungguhan sekali, manual, di Gate 2 epic |
| AC-10 | Given ≥4 sesi terkonfirmasi (03) + 1 sesi belum dikonfirmasi, When `GET verifications`, Then `last_sessions` = 3 terbaru terkonfirmasi, urut waktu turun, field sesuai kontrak, angka = angka sesi saat konfirmasi; user2 hanya melihat sesi sesuai K-4: termasuk sesi root oleh superadmin (EPIC K-1 a); sesi yang semua foldernya kini dihapus/tak terlihat hanya untuk superadmin 1/2 & pembuatnya | BE | 10,12 | http |
| AC-11 | Given sesi yang hanya mencakup subfolder G dari F dan sesi lain tanpa F, When `GET verifications/{F}`, Then sesi G muncul (K-3 a), sesi lain tidak; `folder_total_documents`/`folder_verified` = snapshot potongan F di sesi itu (bukan angka saat ini) | BE | 11 | http |
| AC-12 | Given Confirm Opname 03 memverifikasi dokumen X di folder F, Then X `verified=1`, angka F, induknya, ringkasan, dan baris tipe X naik 1; Given sesi ulang tanpa lanjut opname yang tidak men-scan X, Then X kembali unverified dan angka turun | BE | 4 | http (alur 03) |
| AC-13 | Halaman Archive terbuka tanpa error di root, di dalam folder, dan hasil pencarian; kolom Document Verified tampil (folder `V/T`, dokumen ikon, `-` untuk folder tanpa View); ringkasan header tampil dengan angka = `documentVerifiedSummary` | FE | 1,2,6 | e2e Archive, profil default |
| AC-14 | Display Setting Archive terbuka tanpa error; kolom Document Verified bisa disembunyikan/ditampilkan dan tabel tetap tampil | FE | 1 | e2e |
| AC-15 | Modal Pindahkan (satu baris) dan Bulk Move (mode Select) terbuka tanpa error; kolom Document Verified di tabel picker & konfirmasi dirender (bukan objek mentah) | FE | 2 | e2e |
| AC-16 | Klik "Lihat detail per transaction type" → modal terbuka tanpa error, satu request `GET verifications`, header = ringkasan list, tabel tipe + baris Total, overview 3 sesi, catatan; Close menutup | FE | 7,8,10 | e2e |
| AC-17 | Menu baris folder "Lihat" → drawer terbuka; tab Verification terbuka tanpa error, request `GET verifications/{id}` hanya saat tab dibuka, angka = kolom folder itu; kembali ke Detail form tetap terisi; Save hanya mengirim field Detail/Permission | FE | 9,11 | e2e (request body) |
| AC-18 | Folder kosong → "0 / 0" + progress 0 %; tanpa sesi → tabel overview kosong; total 0 tidak menghasilkan NaN | FE | 14 | unit (helper persen & scope label di `archive.function.test.js`) + e2e |
| AC-19 | CTA "Lihat semua history" tidak dirender tanpa `onOpenHistory`, dirender bila ada; baris sesi tidak bisa diklik | FE | 13 | unit (render `ArchiveVerification`) |
| AC-20 | Regresi: sort, filter Type, pencarian, scan QR, breadcrumb, mode Select, Store/Hand Over/Receive tetap jalan seperti sebelumnya | BE+FE | - | e2e |
| AC-21 | Tampilan: struktur & alur sesuai desain 01/02/05; tampilan mengikuti EQUAL | FE | 1-11 | manual (Gate 2) |

## 7. Subtask

| Kunci | Judul | Layer | AC | Module |
|---|---|---|---|---|
| ED-1033 | [BE] Updater - kolom Document Verified di display setting Archive | BE | 1 | UpdateVersion, `displayColumn/documentArchive` |
| ED-1039 | [BE] Hitungan verifikasi di list Archive (kolom per baris + ringkasan All Archive) | BE | 2-4, 7, 12 | DocumentArchive (`VerificationService`, `ArchiveService::index`) |
| ED-1045 | [BE] Endpoint detail verifikasi All Archive & per folder + 3 sesi terakhir | BE | 5, 6, 8-11 | DocumentArchive (`VerificationController`, route) |
| ED-1051 | [FE] Archive - kolom Document Verified & ringkasan header | FE | 13-15, 18 | Archive |
| ED-1057 | [FE] Archive - modal Detail verifikasi per Transaction Type | FE | 16, 18, 19 | Archive |
| ED-1061 | [FE] Archive - tab Verification di drawer folder | FE | 17-19 | Archive |
| ED-1065 | [QA] Skenario status verifikasi dokumen | QA | semua | - |

## 8. Keputusan untuk developer

| # | Pertanyaan | Opsi | Rekomendasi | Risiko | Jawaban Gate 1 |
|---|---|---|---|---|---|
| K-1 | Arti "verified" lintas hari (dampak G-3 pada angka) | a) status verified terakhir bertahan sampai sesi opname berikutnya menimpanya; ganti hari hanya membuat folder "Belum diopname" di Step 1; b) verified hanya berlaku hari itu, semua angka kembali 0 tiap ganti hari | a - desain menampilkan angka akumulasi (34/34, 1.204/3.680) dan sesi lintas hari; b membuat list hampir selalu 0 di pagi hari. **Wajib sama dengan jawaban G-3 di 03** | tinggi | **Jawaban Gate 1 (2026-10-06):** a - G-5 |
| K-2 | Dokumen mana yang dihitung untuk user yang melihat | a) hanya yang boleh dilihat user: scope lokasi 01 + View folder 02, superadmin 1/2 semua (angka = isi folder yang terlihat); b) scope lokasi 01 saja, abaikan folder permission; c) seluruh dokumen sistem untuk semua user (teks desain "seluruh dokumen di sistem") | a - konsisten dengan PK-2 a; dengan a teks desain "seluruh dokumen di sistem" tetap dipakai apa adanya | rendah | **Jawaban Gate 1 (2026-10-06):** a - G-1 |
| K-3 | Angka folder | a) rekursif: folder + semua subfolder terlihat; overview tab = sesi yang mencakup folder itu atau subfoldernya, angka = potongan subtree; b) hanya dokumen langsung di folder; overview = sesi yang memasukkan folder itu sendiri | a - R-11 (atasan me-review di Root melihat Folder Lokasi A & B sudah diopname) dan ringkasan All = jumlah semua folder | rendah | **Jawaban Gate 1 (2026-10-06):** a - G-5 |
| K-4 | Sesi mana yang tampil di overview 3 sesi (bagian "melihat" dari G-4; 05 wajib memakai aturan yang sama) | a) sesi yang mencakup minimal satu folder yang boleh dilihat user (`viewableFolderIds` 02); superadmin 1/2 semua; + EPIC K-1 a: sesi dari root (`id_archive` NULL) terlihat oleh semua pemegang `List Archive` (isi hasil tetap disaring 05 K-5 b), sesi yang semua foldernya kini terhapus/tak terlihat hanya untuk superadmin 1/2 dan pembuat sesi; b) semua sesi untuk semua user ber-`List Archive`; c) hanya sesi milik user sendiri (superadmin semua) | a - sejalan PK-2 a; nama folder lokasi lain tidak bocor | tinggi | **Jawaban Gate 1 (2026-10-06):** a + aturan EPIC K-1 a - G-1 |
| K-5 | Baris overview 3 sesi bisa diklik? | a) tidak; hasil sesi hanya dibuka dari 05b (desain menyebut klik baris hanya di 05b); b) ya, membuka hasil sesi (tampilan milik 05, dipasang 05) | a | rendah | **Jawaban Gate 1 (2026-10-06):** a - G-7 |
| K-6 | CTA "Lihat semua history" sebelum 05 terpasang (04 jalan lebih dulu) | a) 04 membuat CTA + prop `onOpenHistory`; CTA tidak dirender selama handler belum ada, 05 memasang handler + drawer inline 05b; b) CTA tampil nonaktif sampai 05; c) CTA seluruhnya dibuat 05 | a - tidak ada tautan mati saat QA 04 | rendah | **Jawaban Gate 1 (2026-10-06):** a - rekomendasi |
| K-7 | Angka `V/T` folder di kolom bisa diklik? | a) teks biasa, tidak bisa diklik (sel folder selain Name memang tidak bisa diklik, `ArchivePage/index.js:298-308`); b) klik angka membuka drawer folder di tab Verification | a - desain tidak menyebut aksi klik | rendah | **Jawaban Gate 1 (2026-10-06):** a - rekomendasi |
| K-8 | Dokumen di root (dikeluarkan dari folder / belum pernah dimasukkan tapi aktif) dalam ringkasan All Archive | a) ikut dihitung (header desain "seluruh dokumen di sistem"; sesi "All Archive · root" = total sistem) - syarat 03: opname dari root juga memverifikasi dokumen di root; b) tidak ikut (teks modal "di seluruh folder") | a | rendah | **Jawaban Gate 1 (2026-10-06):** a - G-5 |
| EPIC K-1 | Sesi dari root & sesi yang foldernya sudah dihapus - siapa melihatnya (gate1 §7) | a) root terlihat semua pemegang `List Archive` (hasil disaring 05 K-5 b), sesi yang semua foldernya terhapus/tak terlihat hanya superadmin 1/2 + pembuat; b) K-4 a apa adanya (sesi root tanpa subfolder dicentang hanya superadmin 1/2 + pembuat); c) semua sesi untuk semua pemegang `List Archive` | a - ringkasan menghitung dokumen root (K-8 a), sesi yang memverifikasinya harus terlihat | tinggi | **Jawaban Gate 1 (2026-10-06):** a - rekomendasi; aturan dimasukkan ke K-4 (dipakai juga 05) |
| EPIC K-2 | Cara cek AC lisensi (`PROFILES` kosong) | a/b/c di gate1 §7 | b | rendah | **Jawaban Gate 1 (2026-10-06):** b - rekomendasi; cara seragam di AC-9 |

## 9. Di luar cakupan

- Menulis status verified, tabel sesi/hasil scan, modal opname 3 step, menu "+"/baris Opname, Step 2 "Status verifikasi saat ini" → **ED-1026** (item ini hanya membaca; asumsi A-1..A-5).
- Drawer inline **05b Opname History** (semua sesi, display setting, search, queries, pagination), hasil satu sesi, select user/folder → **ED-1028** (memakai `VerificationService::lastSessions`/scope sesi K-4 dan memasang `onOpenHistory`).
- Scope lokasi, daftar transaction type & label, guard `permission_v5` route Archive → **ED-1024**; hak View folder, tab Permission, struktur `Tabs` drawer → **ED-1025**; Set Permission by User → **ED-1029**.
- Filter/sort list berdasarkan status verified (desain tidak memintanya).
- Dokumen otomatis Verified saat dicetak (K-4 brief, Di luar epic).
- R-1, R-3, R-5..R-22, R-24..R-28 milik item lain (lihat `epics/ED-1022-plan.md`); R-23 bagian validasi milik 01, bagian tampilan di sini (BR-8).

## Catatan implementasi
- (v5-be-dev, 2026-10-07) Model 03 nyata sesuai A-1..A-5, tanpa selisih yang mengubah item ini: verified = `archives.is_verified` (dokumen); sesi = `archive_opnames` `status=2` (`confirmed_at`, `created_by`, `total_documents`, `verified_count` = scan Verified, `not_found_count`, `invalid_count`, `scope_name`/`scope_folder_count` → objek `scope` lewat `OpnameService::scope()`); folder per sesi = `archive_opname_folders` level 0-2 (dokumen langsung per folder: `total_documents`, `verified_count`, `verified_after_count`); indeks `(status, confirmed_at)` dan `(id_archive, id_archive_opname)`. Catatan kecil: 03 menghitung `is_active = 1`, item ini `is_active > 0` (BR-3); di data hanya ada -1/0/1, hasilnya sama.
- (v5-be-dev) `folder_verified` (BR-11) = jumlah `archive_opname_folders.verified_count` (hasil scan Verified di sesi itu), bukan `verified_after_count` yang disebut titik baca 03: dokumen yang tetap verified karena "Lanjut opname" tanpa discan tidak ikut, sehingga potongan folder sejalan dengan kolom Verified sesi (`verified_count`). (contract `session_row`)
- (v5-be-dev) "Sesi mencakup F" (K-3 a) = sesi punya baris folder level 0-2 untuk F atau subfolder F yang **saat ini** terlihat user (pohon sekarang, bukan induk snapshot), satu definisi SQL (`VerificationService::whereCovers` + `subtreeIds`) yang bisa dipaginasi untuk 05; potongan = jumlah snapshot baris-baris itu. Folder yang dipindah sesudah sesi ikut posisi sekarang. (contract `session_row`)
- (v5-be-dev) Sesi terlihat (K-4 a + EPIC K-1 a) = `VerificationService::sessionQuery($context)`: superadmin 1/2 semua; user lain sesi root, sesi `created_by` = username (kolasi `latin1_general_ci`, tidak peka huruf besar seperti `isOwner` 03), dan sesi dengan baris folder di folder terlihat (aktif + lokasi + View). `GET opnames/{id}` 03 masih hanya pemilik + superadmin 1/2 sampai 05 memperluasnya (baris overview tidak bisa diklik, K-5 a). (contract `session_row`)
- (v5-be-dev) Angka folder: subfolder tak terlihat (tanpa View, di luar lokasi, nonaktif) terputus beserta seluruh turunannya, sama dengan isi saat folder dibuka dan pohon Step 1 03. Ringkasan All Archive dihitung per dokumen (BR-5): dokumen di root + di setiap folder terlihat, jadi bisa lebih besar dari jumlah baris root bila ada folder terlihat di bawah folder tak terlihat (lokasi per baris). (contract §1)
- (v5-be-dev) Dokumen tanpa transaction type dikenal dihitung di total tetapi tanpa baris tipe; hanya mungkin dari data lama (validasi 01; QA DB 0 baris). (contract §1)
- (v5-be-dev) `GET verifications/{id}`: id di luar ASCII cetak / > 30 karakter → 404 `ARCHIVE400` tanpa query (pola 03, akar kolasi ED-1071); 403 tanpa View memakai `ArchivePermissionService::assert` (bentuk sama dengan `GET archives/{id}` 02, `parameter` = nama folder penolak). (contract §3)
- (v5-be-dev) Biaya list: satu query folder + satu agregat dokumen per (folder induk, transaction type) per request; di QA DB (34,5 rb dokumen aktif, user non-bypass) ±100-120 ms tambahan per `GET archives`. Tanpa cache static (D-3).
