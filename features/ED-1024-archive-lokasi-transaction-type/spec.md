---
key: ED-1024
epic: ED-1022
title: Archive - Visibilitas folder per lokasi kerja & daftar transaction type terpusat
status: approved
batch: ED-1024
fe_modules: []                # tidak ada perubahan kode FE; layar Archive diverifikasi e2e (lihat §5)
new_fe_modules: []
be_modules: [DocumentArchive, Select/DocumentArchive, Rules/DocumentArchive]
qa_model: sonnet
contract: contract.md
depends_on: []
---

# Archive - Visibilitas folder per lokasi kerja & daftar transaction type terpusat

## 1. Ringkasan

Item fondasi epic Opname Archive. (1) **Satu scope "baris archive yang boleh dilihat user"** berdasarkan lokasi
kerja employee (tag lokasi folder/dokumen), dengan bypass untuk role `is_superadmin` 1 dan 2. Scope ini
dipakai seluruh Archive: jelajah folder, pencarian, transaksi terkait, detail, histori, dan aksi berbasis id.
Item 02-05 memakai titik yang sama; hak View folder 02 berada di luar scope ini (`viewableFolderIds()`). (2) **Satu sumber daftar
transaction type archive**, dipakai untuk validasi dokumen yang boleh masuk archive (`DocumentService::store`),
opsi filter Type, label baris, dan nanti tabel per transaction type (item 04). (3) **Akses lisensi**: endpoint
Archive dijaga permission module Archive yang hanya di-seed untuk lisensi Salesman Activity, sehingga tenant
tanpa lisensi ditolak juga di BE (FE sudah menyembunyikan menu). Tanpa tabel baru, tanpa Updater, tanpa
perubahan kode FE.

## 2. Aturan bisnis

| BR | Aturan | Sumber |
|---|---|---|
| BR-1 | Baris archive (folder atau dokumen) terlihat oleh user bila `is_all_location = 1`, **atau** minimal satu `archive_locations.id_location`-nya termasuk lokasi kerja user. Folder "Several" dengan banyak lokasi = cukup satu yang beririsan | R-7; perilaku list sekarang `ArchiveService.php:25-36` |
| BR-2 | Lokasi kerja user = hasil `MyHelper::getUserLocation()`: employee `is_all_location=1` → semua lokasi aktif; selain itu gabungan `employee_locations` (hanya lokasi aktif). Employee >1 lokasi = gabungan semuanya | `MyHelper.php:1491-1528`, `AuthHelper.php:256-306` (`$trans_type` null → lokasi aktif) |
| BR-3 | User yang punya role aktif `is_superadmin` 1 (Super Admin EQUAL) atau 2 (Technical Support) melihat **semua** baris tanpa cek lokasi, termasuk folder `is_all_location=0` yang tidak punya baris lokasi (sekarang tersembunyi juga untuk mereka). Role `is_superadmin` 3 (Super Admin tenant) dan 4 **tidak** di-bypass | R-8; `MyHelper::checkUserRole` `MyHelper.php:1544-1555`; data `roles` QA DB |
| BR-4 | User non-superadmin tanpa data employee hanya melihat baris `is_all_location = 1` | perilaku `getUserLocation` (koleksi kosong, `MyHelper.php:1509-1524`); **K-2** |
| BR-5 | Visibilitas dinilai **per baris** dengan tag lokasinya sendiri: folder memakai tag folder; dokumen memakai tag dokumen (lokasi transaksi saat dicetak, `DocumentService.php:134-140`; Billing tanpa lokasi = semua lokasi, `BillingService.php:725-732`). Tidak diwariskan dari folder induk | perilaku sekarang; **K-1** |
| BR-6 | Scope berlaku di semua bacaan Archive: jelajah root & isi folder (`id_archive`), pencarian (semua level, `ArchiveService.php:59-66`), **transaksi terkait** (`showRelatedTransaction`; kini tanpa filter lokasi, `ArchiveService.php:369-379`), detail folder, histori | PK-2 a (seluruh Archive) |
| BR-7 | Aksi/bacaan berbasis id terhadap baris yang ada tetapi di luar scope ditolak **403 `ARCHIVE407`** sebelum validasi field; data tidak berubah. Berlaku: list dengan `id_archive`, show, history, update, rename, delete, create-folder (`id_archive_parent`), put-in (`id_archive_parent` dan `id_archives`) | PK-2 a; `references/decisions.md` (BE) baris "Hak akses di luar permission_v5" → 403 + kode modul, sebelum 422 |
| BR-8 | Aksi berbasis **scan nama dokumen** (hand-over, receive, put-in dengan `name`) tidak dibatasi lokasi (perilaku sekarang) | `DocumentService.php:70-116`, `:16-19`; **K-5** |
| BR-9 | Alur internal tidak difilter scope user: pencatatan dokumen saat cetak PDF dan histori serah-terima dari Billing/Shipment | `BillingService.php:893-918`, `ShipmentService.php:897-922` |
| BR-10 | Id yang tidak ada → **404 `ARCHIVE400`** (sekarang 500) di list dengan `id_archive`, show, history, update, rename, delete. Kode validasi lama `ARCHIVE401`-`406` keluar **400** (sekarang 500) di semua titik lempar, tanpa syarat | D-6; `ArchiveService.php:161,180,193,210,233` (tanpa status); list: `formattingBreadcrumb(null)` `ArchiveService.php:113-118`; Rule `Archive/UniqueNameRule.php:47`, `Document/UniqueNameRule.php:47`, `Document/SelfFolderRule.php:38`, `Document/ArchiveSourceRule.php:34`; gate1 O-4 |
| BR-11 | Transaction type yang didukung Archive = satu daftar berurutan: Sales Order (6), Delivery Order (7), Sales Invoice (8), Sales Return (9), Receivable Payment (29), Bilyet Giro/Cheque (31), Billing (222). Handover/Receive/Store bukan transaction type | R-23; `SelectArchiveService.php:39-46` (6 tipe), `ArchiveService.php:269-361` (7 tipe), 9 pemanggil `store`; Billing → **K-3** |
| BR-12 | Opsi filter Type (`select/document-archive/archive/types`) = "Folder" lalu daftar BR-11 dengan urutan yang sama, label sesuai bahasa user | `SelectArchiveService.php:37-63` |
| BR-13 | Label kolom Type baris dokumen diambil dari sumber yang sama dengan opsi filter (teks label tidak berubah: kedua daftar label identik untuk 7 tipe) | `DocumentArchiveService.php:94-98` (`App\Lib\lang\Transaction`) vs `SelectArchiveService.php:53` (`Entities\Languages\Transaction`) |
| BR-14 | `DocumentService::store` hanya mencatat tipe di BR-11; tipe lain → tidak membuat baris archive, tanpa error, cetak PDF tetap jalan | R-23; pola keluar diam yang sudah ada `DocumentService.php:120-121` |
| BR-15 | Archive hanya untuk lisensi Salesman Activity: menu & route FE sudah dijaga (`menus.js:162-169`, `routes.js:2086-2094`); BE: tiap route Archive dijaga permission module Archive (id 1266) yang hanya di-seed bila lisensi punya `SALESMAN_ACTIVITY` → tanpa lisensi 403 `GE0114`. Pemetaan di §4 | R-2; `permission_salesman.sql:67-76`; `ApplicationSetupService.php:223-238`; pola module lisensi `Routes/Setup/customer.php:65`, `Routes/Setup/employee.php:31`; **K-4** |
| BR-16 | (bila K-6 a) Lokasi subfolder ⊆ lokasi folder induk dan lokasi dokumen ⊆ lokasi folder tujuan benar-benar divalidasi BE, juga saat update folder; pelanggaran → **400** `ARCHIVE402`/`ARCHIVE403` | `Rules/DocumentArchive/Archive/LocationRule.php:43`, `Document/LocationRule.php:51` (`=== 0` terhadap string "0", PDO `ATTR_EMULATE_PREPARES` `config/database.php:74-75`) |
| BR-17 | (bila K-7 a) Hapus folder ditolak **400 `ARCHIVE401`** bila ada dokumen aktif di turunan mana pun; ditolak **403 `ARCHIVE418`** bila ada subfolder turunan di luar scope user (superadmin 1/2 tidak; 02 memakai kode yang sama untuk subfolder tanpa Delete efektif, G-3) | `DocumentArchiveService.php:114-127` (deteksi tidak jalan), `ArchiveService.php:236-260` |

Kolom v3: Archive tidak ada di v3 (tabel `archives*` hanya dipakai `Modules/V5` dan Updater), jadi tidak ada
kolom v3 yang harus dijaga (D-4/D-5).

## 3. Model data

| Hal | Isi |
|---|---|
| Tabel | Tidak ada yang baru/berubah. Dipakai (DB tenant, ada di `api_sidomaju`): `archives` (`type` 1 folder/2 dokumen, `is_all_location`, `is_active`, `id_archive_parent`), `archive_documents` (`transaction_type` int), `archive_locations` (`id_archive`, `id_location`) |
| Updater | Tidak ada |
| Display setting | Tidak berubah: query `type` sudah memakai endpoint `select/document-archive/archive/types` (`Config/displayColumn/documentArchive.php`), jadi opsi Billing ikut otomatis tanpa reset cache |
| Permission | Tidak ada baris baru. Dipakai ulang (`permission_salesman.sql:67-76`): `List Archive` 1082, `Add Folder` 1083, `Update Folder` 1085, `Delete Folder` 1086, `Move Archive` 1087, `Handover Document` 1088, `Receive Document` 1089, `Add Document` 1090. `permission_checks` sudah ada (`:137-145`) |
| Kode pesan baru (en_EN + id_ID) | `ARCHIVE407` (403, teks generik, dipakai ulang 02-06; 02 menambah nama folder penolak di `result.denied_by`) en "You don't have access to this folder or document", id "Anda tidak punya akses ke folder atau dokumen ini". `ARCHIVE418` (403, K-7 a; dipakai ulang 02, G-3) en "Folder can't be deleted, it contains subfolders you can't access or delete", id "Folder tidak dapat dihapus, berisi subfolder yang tidak dapat Anda akses atau hapus". Nomor final gate1 §5 (408-411 milik 02) |
| Status HTTP kode lama | tanpa syarat (gate1 O-4): `ARCHIVE400` → 404; `ARCHIVE401`-`406` → 400 di semua titik lempar (sekarang 500, default `ErrorMessageException`) |

**Titik pakai ulang untuk item 02-05 (wajib satu tempat, nama boleh disesuaikan BE dev, lalu dicatat di run.md):**

| Titik | Bentuk yang diusulkan | Dipakai oleh |
|---|---|---|
| Scope query | local scope `Archive::scopeVisibleToUser($query, $idUser = null)`: no-op untuk superadmin 1/2, selain itu BR-1/2/4. Lokasi user dihitung per panggilan/instance service, **bukan** static cache (D-3, RoadRunner) | list, search, related (01); child folder Step 1 & hitungan (03); angka verifikasi (04); select folder history (05). Scope = **lokasi saja**: hak View 02 tidak masuk ke sini, tetapi lewat `ArchivePermissionService::viewableFolderIds()` (02) yang dipakai 03-06 di samping scope ini (gate1 C-1) |
| Ambil satu baris | `DocumentArchiveService::findVisible($id)` (base class `ArchiveService`/`DocumentService`): tidak ada → 404 `ARCHIVE400`, di luar scope → 403 `ARCHIVE407` | show/history/update/rename/delete/put-in (01), aksi folder (02), opname per folder (03) |
| Daftar transaction type | konstanta berurutan `ArchiveDocument::TRANSACTION_TYPES` + `ArchiveDocument::transactionTypeOptions()` (value/label bahasa user) + `ArchiveDocument::transactionTypeLabel($type)` | `store`, select types, label baris, `relatedTransaction` (01); kolom Transaction Type Step 2/3 (03); tabel per transaction type (04) |

## 4. Endpoint v5

Semua di `Routes/DocumentArchive/api.php` (grup `document-archive`, `auth:api`) kecuali select. Permission per
route = rekomendasi K-4 a.

| Route | Controller@method | FormRequest | Service | Permission | Status |
|---|---|---|---|---|---|
| GET `document-archive/archives` | `ArchiveController@index` | - | `ArchiveService::index` (scope BR-1..6; `id_archive` di luar scope → 403) | `List Archive` | ubah |
| POST `document-archive/archives/create-folder` | `@createFolder` | `CreateFolderRequest` (+ cek scope induk di `authorize()`) | `createFolder` | `Add Folder` | ubah |
| GET `document-archive/archives/{id}` | `@show` | - | `show` (404/403) | `List Archive` (dipanggil juga oleh modal Add Folder untuk induk) | ubah |
| PUT `document-archive/archives/{id}` | `@update` | `UpdateRequest` (+ scope; + `LocationRule` induk bila K-6 a) | `update` | `Update Folder` | ubah |
| GET `document-archive/archives/history/{id}` | `@history` | - | `history` (404/403) | `List Archive` | ubah |
| PUT `document-archive/archives/rename/{id}` | `@rename` | `RenameFolderRequest` (+ scope) | `rename` | `Update Folder` | ubah |
| DELETE `document-archive/archives/delete/{id}` | `@delete` | - | `delete` (404/403; K-7) | `Delete Folder` | ubah |
| POST `document-archive/archives/add-document` | `@addDocument` | - | method tidak ada (500) | - | tidak disentuh (Di luar epic, tiket terpisah) |
| POST `document-archive/documents/put-in` | `DocumentController@putInFolder` | `PutInFolderRequest` (+ scope `id_archive_parent`, `id_archives`) | `DocumentService::putInFolder` | `Move Archive\|Add Document` | ubah |
| POST `document-archive/documents/hand-over` | `@handOver` | `HandOverRequest` | `movement` | `Handover Document` | ubah (permission saja) |
| POST `document-archive/documents/receive` | `@receive` | `ReceiveRequest` | `movement` | `Receive Document` | ubah (permission saja) |
| GET `select/document-archive/archive/types` | `SelectArchiveController@type` | - | `SelectArchiveService::type` (BR-11/12) | - (select tanpa permission, `responses.md` §4) | ubah |
| GET `select/document-archive/archive/locations` | `@location` | - | `location` | - | sudah ada (K-8) |

Pemanggil `DocumentService::store` (tidak diubah, validasi tipe di dalam `store`): `SalesOrderService.php:789`,
`DeliveryOrderService.php:542`, `SalesInvoiceService.php:737`, `SalesReturnService.php:549`,
`ReceivablePaymentService.php:597`, `BilyetGiroChequeService.php:620`, `BillingService.php:732`, `:911`,
`ShipmentService.php:914`.

## 5. Perubahan FE per module

**Archive** (`src/containers/Archive`, tipe custom/master dengan `archiveActionColumn`): **tidak ada perubahan
kode**. Alasan: opsi filter Type datang dari endpoint di `queries` display setting; label Type baris dari BE;
menu (`menus.js:162-169`) dan route (`routes.js:2086-2094`) sudah dijaga lisensi + `List Archive`; aksi baris dan
tombol "+" sudah dijaga permission yang sama dengan pemetaan §4 (`ArchivePage/index.js:142-145`,
`ArchiveButtonAdd/index.js:8-11`); navigasi folder berbasis state (bukan URL), sehingga folder di luar scope
tidak terjangkau dari UI. Hasil scope terlihat otomatis di: list root/isi folder, pencarian, tabel pilih folder
di modal Pindahkan (`ArchiveTable` memakai endpoint list yang sama), lookup dokumen di modal Hand Over/Receive
(`ArchiveDocumentHandOverModalForm.js:28`). Error 403/404 tampil lewat interceptor standar. Diverifikasi e2e
(AC-15, AC-16); bila e2e menemukan masalah FE, itu defect yang ditambahkan sebagai subtask FE.

## 6. Acceptance criteria

Data QA DB `api_sidomaju` (lisensi dengan Salesman Activity). Lokasi: JOG, MGL, SMR. Root: Backup Arsip (semua),
CABANG - JOGJA {JOG}, CABANG - SEMARANG {SMR}, PUSAT - MAGELANG (semua). Isi Backup Arsip: SMLYK & SMLYK -
BRANGKAS {JOG,MGL}, SMLHO - KANTOR ADMIN {MGL}, SMLSMG, SMLSMG - BRANGKAS, SMLSMG - PROSES KIRIM {SMR,MGL}.
Contoh user: lydia (employee JOG saja, role 16), andika (JOG+MGL, role 5), equal_admin (is_superadmin 1, tanpa
employee). QA boleh memakai user lain / mengubah lokasi employee user QA sementara, lalu memulihkan (P-4).

| AC | Given / When / Then | Tag | BR | Cara cek |
|---|---|---|---|---|
| AC-1 | User employee JOG saja buka root → `data` berisi Backup Arsip, CABANG - JOGJA, PUSAT - MAGELANG; **tidak** CABANG - SEMARANG | [BE] | 1,2 | http: GET `api/v5/document-archive/archives`, `result.data[].name[0].transaction_no` |
| AC-2 | `id_archive`=Backup Arsip: user JOG → hanya SMLYK, SMLYK - BRANGKAS (+ dokumen dalam scope); user JOG+MGL → keenam folder | [BE] | 1,2,5 | http: GET `archives?id_archive=` ; bandingkan `archive_locations` di DB |
| AC-3 | User JOG cari `search={"query":"SML"}` → setiap baris hasil (folder & dokumen, semua level) ber-`is_all_location=1` atau punya lokasi JOG; SMLHO/SMLSMG tidak muncul | [BE] | 1,5,6 | http + DB `archives`/`archive_locations` per `id_archive` hasil |
| AC-4 | QA menyisipkan folder root `QA01-TANPA-LOKASI` (`is_all_location=0`, tanpa baris lokasi) dan `QA01-SMR` {SMR}. User is_superadmin 1 (dan 2, role ditempel sementara) → keduanya tampil; user JOG → tidak keduanya; user is_superadmin 3 dengan employee JOG saja → `QA01-SMR` tidak tampil. Pulihkan | [BE] | 3 | http GET `archives`; DB untuk setup/pulih |
| AC-5 | User non-superadmin tanpa employee (role diberi `List Archive` sementara) → root hanya Backup Arsip & PUSAT - MAGELANG (semua lokasi) | [BE] | 4 | http GET `archives` (sesuai jawaban K-2) |
| AC-6 | User JOG, id CABANG - SEMARANG (dan satu dokumen {SMR}): GET `archives?id_archive=`, GET `archives/{id}`, GET `archives/history/{id}`, PUT `archives/{id}`, PUT `archives/rename/{id}`, DELETE `archives/delete/{id}`, POST `create-folder` dengan `id_archive_parent`, POST `documents/put-in` dengan `id_archive_parent` / `id_archives` → semuanya **403 `ARCHIVE407`**, termasuk saat body juga tidak valid (403 sebelum 422); `archives.updated_at/is_active/history/id_archive_parent` tidak berubah. Superadmin pada id yang sama → 200 | [BE] | 7 | http (status + `code`) + DB sebelum/sesudah |
| AC-7 | Id acak tak ada → GET `archives?id_archive=`, show, history, update, rename, delete = **404 `ARCHIVE400`** (bukan 500) | [BE] | 10 | http |
| AC-8 | QA memindah tag lokasi satu DO terkait sebuah SO (dokumen dalam folder scope JOG) ke {SMR}. User JOG list folder itu dengan `search={"showRelatedTransaction":true}` → `related_transactions` SO tidak memuat DO tsb; superadmin → memuat. Pulihkan | [BE] | 6 | http `result.data[].related_transactions[].name` |
| AC-9 | GET types (user bahasa ID, lalu EN) → `options` persis berurutan `FOLDER, 6, 7, 8, 9, 29, 31, 222`; label ID "Pesanan Penjualan … Bilyet Giro/Cek, Penagihan", EN "Sales Order … Bilyet Giro/Cheque, Billing" | [BE] | 11,12 | http GET `api/v5/select/document-archive/archive/types` `result.options` |
| AC-10 | Satu dokumen Billing (`is_active=-1`) di-put-in lewat `name` ke PUSAT - MAGELANG → GET `archives?search={"type":222}` mengembalikannya dengan `type` "Penagihan"/"Billing"; filter `type` 6 tidak memuatnya. Pulihkan | [BE] | 11,13 | http + DB `archives` |
| AC-11 | Regresi pencatatan: cetak PDF SO yang belum punya baris archive → 1 baris `archives` (`type=2`, `is_active=-1`, lokasi = lokasi SO) + `archive_documents.transaction_type=6`; cetak ulang tidak menggandakan. Cetak PDF Billing → `is_all_location=1`, `transaction_type=222` | [BE] | 14,11 | http GET `api/v5/sales/sales-orders/{transaction}/pdf`, `api/v5/sales/billings/{id}/pdf`; DB |
| AC-12 | Lisensi ada, role tanpa permission: role tanpa archive (mis. 6 Sales) → GET `archives` 403 `GE0114` (`parameter` "List Archive"); role hanya `List Archive` (mis. 29) → GET `archives`, `archives/{id}`, `history/{id}` 200; create-folder 403 "Add Folder"; PUT `{id}` & rename 403 "Update Folder"; delete 403 "Delete Folder"; put-in 403 "Move Archive, Add Document"; hand-over 403 "Handover Document"; receive 403 "Receive Document". Role 16 (Move) → put-in lolos guard | [BE] | 15 | http (status, `code`, `parameter`) |
| AC-13 | Lisensi **tanpa** `SALESMAN_ACTIVITY`: menu Archive tidak tampil, route `/archive` tertolak, GET `archives` 403 `GE0114` (bukan 500, tanpa data). Dengan lisensi: menu tampil dan layar terbuka (AC-16) | [BE+FE] | 15 | cara seragam EPIC K-2 b: http GET `archives` dengan role tanpa `List Archive` → 403 `GE0114`; seed: permission Archive hanya ada di `permission_salesman.sql` (dimuat `ApplicationSetupService.php:223-238` hanya bila lisensi punya `SALESMAN_ACTIVITY`); FE: guard lisensi menu/route yang ada dicek di kode (`menus.js:162-169`, `routes.js:2086-2094`); tukar lisensi sungguhan sekali, manual, di Gate 2 epic |
| AC-14 | (K-6 a) create-folder di bawah CABANG - JOGJA {JOG} dengan `id_locations=[SMR]` → 400 `ARCHIVE402`; PUT subfolder dengan lokasi di luar induk → 400 `ARCHIVE402`; put-in dokumen {SMR} ke CABANG - JOGJA → 400 `ARCHIVE403`; DB tidak berubah. Lokasi ⊆ induk → 200 | [BE] | 16 | http + DB |
| AC-15 | (K-7 a) QA membuat X > Y > Z (semua lokasi) > dokumen aktif di Z → DELETE X 400 `ARCHIVE401` (juga bila dokumen di Y), X/Y/Z/dokumen tetap `is_active=1`. X (semua) > Y {SMR} kosong → user JOG DELETE X 403 `ARCHIVE418`; superadmin → 200, X & Y `is_active=0`. Pulihkan | [BE] | 17 | http + DB |
| AC-16 | e2e sesi user JOG: halaman Archive terbuka tanpa error; root tanpa CABANG - SEMARANG; popover filter Type memuat opsi dari endpoint types termasuk Billing; Display Setting terbuka; buka Backup Arsip tanpa SMLHO/SMLSMG; drawer Info (history) & drawer View (show) & modal Add Folder (select lokasi) & modal Pindahkan (tabel folder) & modal Hand Over terbuka tanpa error; tidak ada respons 5xx | [FE] | 1,6,12 | e2e layar Archive, sesi user JOG |
| AC-17 | e2e sesi superadmin (is_superadmin 1): root menampilkan CABANG - SEMARANG dan CABANG - JOGJA; filter Type = Billing menampilkan hasil tanpa error | [FE] | 3,12 | e2e layar Archive, sesi superadmin |
| AC-18 | Status kode lama tanpa 500: put-in folder ke dirinya sendiri → 400 `ARCHIVE404`; create-folder / PUT `archives/{id}` dengan nama yang sudah ada di induk yang sama → 400 `ARCHIVE405`; put-in dengan `id_archives` dan `name` sekaligus → 400 `ARCHIVE406`; DB tidak berubah | [BE] | 10 | http (status + `code`) + DB |

## 7. Subtask

| Kunci | Judul | Layer | AC | Module |
|---|---|---|---|---|
| ED-1030 | [BE] Archive - scope lokasi kerja terpusat (list, pencarian, transaksi terkait, show/history, aksi berbasis id, kode ARCHIVE407, 404) | BE | AC-1..AC-8 | DocumentArchive (+ FormRequest authorize, lang) |
| ED-1036 | [BE] Archive - daftar transaction type terpusat (select Type, label, validasi store, Billing) | BE | AC-9..AC-11 | DocumentArchive, Select/DocumentArchive, `ArchiveDocument` |
| ED-1042 | [BE] Archive - permission lisensi Salesman Activity di route Archive | BE | AC-12, AC-13 | Routes/DocumentArchive |
| ED-1048 | [BE] Archive - status HTTP kode lama (ARCHIVE401-406), validasi lokasi folder/dokumen & hapus folder (K-6/K-7) | BE | AC-14, AC-15, AC-18 | Rules/DocumentArchive, DocumentArchive |
| ED-1054 | [QA] Skenario visibilitas lokasi, transaction type & lisensi Archive | QA | AC-1..AC-18 | Archive |

## 8. Keputusan untuk developer

**K-1. Satuan visibilitas lokasi.** a) per baris dengan tag masing-masing: folder pakai tag folder, dokumen pakai
tag dokumen = lokasi transaksi (perilaku list sekarang; data QA DB: 0 subfolder/dokumen yang lokasinya keluar dari
induknya); b) dokumen ikut tag folder tempatnya (dokumen di root tetap pakai tagnya); c) mewarisi penuh: baris
tampil hanya bila dirinya dan semua folder induknya dalam scope (rekursif, juga di pencarian). Akibat a: folder
{JOG,MGL} dilihat user JOG hanya berisi dokumen JOG/semua lokasi; breadcrumb bisa menampilkan nama induk di luar
scope bila data tidak konsisten. Rekomendasi **a**. risiko: tinggi.

**Jawaban Gate 1 (2026-10-06):** a - G-1 (daftar/pencarian = lokasi per baris)

**K-2. User non-superadmin tanpa data employee** (mis. role 4 "Pusat-Pelunasan-Kirim-Tagih", atau Super Admin
tenant `is_superadmin=3` tanpa employee). a) hanya melihat baris "semua lokasi" (perilaku `getUserLocation`
sekarang); b) tidak melihat apa pun; c) melihat semua seperti superadmin 1/2. Rekomendasi **a**. risiko: tinggi.

**Jawaban Gate 1 (2026-10-06):** a - G-2 (bypass hanya `is_superadmin` 1/2)

**K-3. Billing di daftar transaction type** (G-7). a) masuk (urutan terakhir): `store` sudah mencatat Billing
(QA DB 2.463 baris), `relatedTransaction` sudah menanganinya, filter Type jadi bisa memilih Billing; b) tidak
masuk: `store` berhenti mencatat Billing baru, baris lama dibiarkan, label baris lama tetap. Rekomendasi **a**.
risiko: rendah.

**Jawaban Gate 1 (2026-10-06):** a - rekomendasi

**K-4. Guard permission di route BE Archive** (kini hanya `auth:api`). a) `permission_v5` per route sesuai §4
(pola module lisensi lain: Customer Device, Helper, Billing; FE sudah menggating aksi yang sama); b) semua route
cukup `List Archive` (minimal); c) tidak diubah → AC "tanpa lisensi BE menolak 4xx" tidak terpenuhi. Akibat a/b:
role yang kini memanggil endpoint tanpa permission terkait mendapat 403 (tidak ditemukan pemanggil lain selain FE
web di repo; bila aplikasi mobile memakai hand-over/receive, role-nya wajib punya `Handover/Receive Document`).
Rekomendasi **a**. risiko: tinggi.

**Jawaban Gate 1 (2026-10-06):** a - G-4 (`permission_v5` per route)

**K-5. Aksi scan dokumen di luar lokasi user** (hand-over, receive, put-in dengan `name`). a) tidak dibatasi
lokasi (perilaku sekarang; dokumen fisik di tangan user, serah-terima lintas lokasi tetap jalan); b) ditolak 403
`ARCHIVE407`. Rekomendasi **a**. risiko: tinggi.

**Jawaban Gate 1 (2026-10-06):** a - G-6 (dokumen fisik tidak dibatasi lokasi)

**K-6. Validasi subset lokasi tidak pernah jalan** (temuan): `Archive/LocationRule.php:43` dan
`Document/LocationRule.php:51` membandingkan `=== 0` dengan string "0" (PDO emulate prepares) → lokasi subfolder
⊆ induk dan dokumen ⊆ folder tidak divalidasi BE (hanya dibatasi opsi select FE); `UpdateRequest` tanpa
`LocationRule`. (Status 400 kode ini sudah tanpa syarat, §3, gate1 O-4.) a) perbaiki di item ini (perbandingan
longgar, `LocationRule` induk di update) - BR-5 per baris bergantung padanya; b) tiket terpisah (P-7). Rekomendasi **a**.
risiko: tinggi (request yang dulu lolos kini 400).

**Jawaban Gate 1 (2026-10-06):** a - rekomendasi (tinggal perbandingan `=== 0` + `LocationRule` di update)

**K-7. Hapus folder** (temuan): `hasFileInChild` (`DocumentArchiveService.php:114-127`) tidak mendeteksi dokumen
di subfolder (`===` string vs int, hasil rekursi dibuang) → hapus folder induk ikut menonaktifkan dokumen di
sub-subfolder (`ArchiveService.php:253-259`); subfolder di luar lokasi user ikut terhapus. a) perbaiki deteksi
(400 `ARCHIVE401`) + tolak 403 `ARCHIVE418` bila ada subfolder turunan di luar scope; b) perbaiki deteksi saja;
c) tiket terpisah. Item 02 menambah cek Delete efektif di subfolder aktif dengan kode yang sama (G-3). Rekomendasi **a**.
risiko: tinggi.

**Jawaban Gate 1 (2026-10-06):** a - G-3 (deteksi diperbaiki + `ARCHIVE418`; perbaikan 02 K-11 pindah ke sini, gate1 O-4/O-5)

**K-8. Opsi lokasi saat membuat/mengubah folder** (`select/document-archive/archive/locations`). a) tetap: semua
lokasi aktif, dibatasi lokasi induk (folder baru bertag lokasi lain langsung hilang dari pandangan pembuatnya);
b) dibatasi juga ke lokasi kerja user (superadmin semua), hanya di opsi select. Rekomendasi **a** (brief tidak
memintanya). risiko: rendah.

**Jawaban Gate 1 (2026-10-06):** a - rekomendasi

**EPIC K-2. Cara cek AC lisensi** (`PROFILES` kosong; opsi a/b/c di gate1 §7). Rekomendasi **b**. risiko: rendah.

**Jawaban Gate 1 (2026-10-06):** b - rekomendasi; cara seragam diterapkan di AC-13

## 9. Di luar cakupan

- Hak View/Update/Delete/Store per folder, toggle Enable Folder Permission, layar 06 akses ditolak →
  ED-1025 (hak View lewat `viewableFolderIds()`, di luar scope item ini; memakai ulang `ARCHIVE407` & `ARCHIVE418`).
- Child folder Step 1 opname, status opname, hitungan dokumen per sesi → ED-1026; angka verified/total,
  tabel per transaction type, modal 05 → ED-1027; select folder/user & daftar sesi history → ED-1028;
  Set Permission by User → ED-1029. Semuanya memakai scope & daftar type dari item ini.
- Kebutuhan brief milik item lain: R-1, R-3..R-6, R-9..R-22 (03), R-24..R-28 (05); R-23 bagian tampilan 05 (04).
- `POST archives/add-document` memanggil method yang tidak ada (500) → tiket terpisah (Di luar epic, P-7).
- `DocumentService::store` tetap mencatat dokumen saat cetak walau tenant tanpa lisensi Salesman Activity
  (perilaku sekarang, tidak diubah); label opsi "Folder" tidak diterjemahkan (tetap).
- User customer (diloloskan `PermissionVersion5.php:31-34` dan `getUserLocation`) - perilaku global v5.
- Tampilan FE (tidak ada perubahan kode FE).

## Catatan implementasi

- (BE, ED-1030) Titik pakai ulang §3 dibuat dengan nama yang diusulkan: `Archive::scopeVisibleToUser($query, $idUser = null, $idLocations = false)`
  (+ `Archive::userScopeLocations($idUser = null)`: `null` = bypass is_superadmin 1/2, selain itu array id lokasi; dihitung
  per panggilan, tanpa static; parameter ke-3 untuk memakai ulang hasilnya dalam satu request),
  `DocumentArchiveService::findVisible($id, $idLocations = false)` dan `DocumentArchiveService::assertVisible($ids, $idLocations = false)`
  (403 bila salah satu id yang **ada** di luar scope; id tak ada dilewati, ditangani validasi/404). FormRequest
  `CreateFolderRequest`/`UpdateRequest`/`RenameFolderRequest`/`PutInFolderRequest` memanggilnya di `authorize()`; service memeriksa ulang.
- (BE, ED-1036) `ArchiveDocument::TRANSACTION_TYPES`, `transactionTypeOptions($language = null)`, `transactionTypeLabel($type, $language = null)`
  (sumber label `Entities\Languages\Transaction`; tipe tanpa label tampil sebagai angkanya, sebelumnya 500) dan
  `ArchiveDocument::isSupportedTransactionType($type)` (dipakai `DocumentService::store`). `translationType()` lama dihapus.
- (BE, ED-1048) Hapus folder: "dokumen aktif" = `is_active > 0` (sama dengan yang tampil di list). Dokumen `is_active` 0/-1
  di dalam folder tidak lagi menghalangi hapus (sebelumnya anak langsung jenis dokumen apa pun menghalangi) dan tidak
  diubah; yang dinonaktifkan hanya folder + subfolder turunan. Cek `ARCHIVE418` dijalankan sebelum `ARCHIVE401`.
  Turunan dicari BFS dengan penjaga siklus (`descendantFolderIds()`), menggantikan `hasFileInChild()`.
- (BE, ED-1048) `Archive\LocationRule` kini `ImplicitRule` dengan argumen folder yang diubah: di update, bila
  `id_archive_parent` tidak dikirim, induk saat ini yang dipakai. Perbandingan `== 0` (di mesin QA ini PDO sudah
  mengembalikan int, jadi `=== 0` lama kebetulan jalan; perbaikan membuatnya tidak bergantung driver).
- (BE) Kontrak dilengkapi tanpa perubahan bentuk: 404 `ARCHIVE400` di put-in/hand-over/receive, urutan cek delete,
  induk fallback `ARCHIVE402` di update.
- (BE, fix ronde 1, D-1) `PutInFolderRequest`: `id_archive_parent` `string`, `id_archives.*` `nullable|string`. Bila tipe
  salah (array/objek), hanya aturan tipe yang dijalankan (422); aturan bisnis put-in (`LocationRule`, `ArchiveSourceRule`,
  `SelfFolderRule`, `UniqueNameRule`) hanya dinilai untuk input bertipe benar, jadi perilaku/kode lainnya tetap.
