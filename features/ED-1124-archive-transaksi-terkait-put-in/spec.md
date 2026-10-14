---
key: ED-1124
epic: ED-1022
type: bug
title: Archive - transaksi terkait 500 bila ada folder senama nomor transaksi; put-in mengaktifkan lagi sumber nonaktif
status: approved
batch: ED-1124
fe_modules: []
new_fe_modules: []
be_modules: [DocumentArchive]
qa_model: sonnet
contract: null
depends_on: []
---

# ED-1124 - Transaksi terkait 500 (folder senama nomor transaksi), put-in sumber dihapus, select lokasi induk "0"

## 1. Ringkasan

Tiga cacat BE pra-ada (QA ED-1069 B-1, B-2, B-4), FE tetap. (1) Filter "Tampilkan Transaksi Terkait" = 500 bila ada
**folder** bernama persis nomor transaksi terkait (mis. folder per SO); jadi 200, transaksi terkait hanya dokumen.
(2) put-in lewat `id_archives` menghidupkan lagi sumber yang dihapus; ditolak (K-1). (3) Select lokasi
`id_archive_parent=0` jadi 404 `ARCHIVE400` (ED-1069 K-4 a). K-2: data yang sama merusak pencarian per nama.

## 2. Reproduksi

`api_sidomaju`, BE `6fcc91fd0`. Dibuktikan QA ED-1069 lewat HTTP (`ED-1069/qa-be.md:51-54`, `qa/scenario5-extra.php`
X-4, `qa/scenario4-select-related.php:106-108`); jalurnya tidak berubah sejak `878314df7` (hanya geser baris).

| # | Langkah | Diharapkan | Aktual |
|---|---|---|---|
| 1 | Dokumen tipe 7 (DO nyata) di folder F; folder bernama persis salah satu `related_transactions[].name` (uji: `SI-JOG/2511/00002`); `GET archives?id_archive=F&search={"showRelatedTransaction":true}` | 200, folder tidak ikut | 500 `Trying to get property 'transaction_type' of non-object` |
| 2 | Dokumen + folder `is_active=0`; `POST documents/put-in` `{id_archive_parent: T, id_archives: [d, f]}` | ditolak (K-1) | 200 `ARCHIVE204`, keduanya `is_active=1` (folder kosong; subfoldernya tetap 0) |
| 3 | `GET select/document-archive/archive/locations?id_archive_parent=0` | 404 `ARCHIVE400` | 200 semua lokasi |

## 3. Akar masalah

| # | file:line | Kenapa |
|---|---|---|
| 1 | `ArchiveService::relatedTransaction` (kueri akhir + `each`) | mencari `archives` per `name` tanpa `type`, lalu membaca `document->transaction_type` pada folder (null) |
| 2 | `Archive.php:145-150`; `DocumentService.php:68` | `findSources` (id) tanpa saringan `is_active`, dipakai service + 3 rule (`Document/UniqueNameRule.php:31`, `SelfFolderRule.php:46`, `LocationRule.php:36`); service menulis `is_active=1` |
| 3 | `SelectArchiveService.php:22` | `!empty("0")` = false, dianggap tanpa induk |
| K-2 | `Archive.php:133-136` | `findName` tanpa `type`; id berawalan waktu (`MyHelper.php:161`) + indeks `name` = baris tertua. Folder lebih tua: `store()` (`DocumentService.php:149-152`) mengembalikan folder; `movement()` (`:93`) dan put-in `name` (`Archive.php:153`) mengenai folder |

Bukti niat butir 2 (tanpa v3: `archives` hanya dipakai `Modules/V5`):
- `-1` = tercetak belum disimpan (`DocumentService.php:159`); put-in = menyimpan, `-1 -> 1` disengaja (ED-1024 AC-10, ED-1027 BR-3).
- `store()` memakai ulang baris senama, juga yang dihapus (`:149-152`): scan `name` satu-satunya jalan menyimpan ulang dokumen yang dihapus.
- Hapus folder hanya bila tanpa dokumen aktif (`ARCHIVE401`) dan ikut menonaktifkan subfolder (`ArchiveService::delete`); put-in menghidupkan folder kosong itu saja, subfoldernya tetap terhapus.
- FE: list hanya `is_active > 0` (`ArchiveService::index`); Pindahkan/Pindah massal kirim `id_archives` baris list (`ArchiveMoveModalForm.js:61`, `ArchiveBulkMoveModalForm.js:139`); Simpan Dokumen kirim `name` hasil scan (`ArchiveDocumentModalForm.js:47-54`). Terjangkau UI hanya lewat list basi.

## 4. Perbaikan (BE saja, `Modules/V5`)

| # | Lapisan | Perubahan |
|---|---|---|
| 1 | Service `ArchiveService::relatedTransaction` | kueri akhir hanya `type = FILE` yang punya `document` |
| 2 (K-1 a) | Model `Archive::findSources`, cabang `id_archives` | lewati `is_active = 0`; berlaku sekaligus di service dan 3 rule. Cabang `name` dan `DocumentService.php:68` tetap |
| 3 | Service `SelectArchiveService::location` | `$request->filled('id_archive_parent')` (pola `$request->filled('id_archive')` di `ArchiveService::index`) |
| K-2 b | Model `Archive::findName` | hanya `type = FILE` |

Tanpa Updater, tabel, permission, kode pesan baru. Kontrak tetap (ED-1024 `contract.md:76-83` sudah memuat 404
`ARCHIVE400`). Urutan tetap: 403 `authorize()` (juga sumber dihapus) -> 422 -> 400 -> 404.

## 5. Acceptance criteria

Semua `[BE]`, `api_sidomaju`, fixture `QA124-`. "Tidak berubah" = snapshot kolom `archives` + `archive_documents` identik.

| AC | Given / When / Then | Cek |
|---|---|---|
| AC-1 | Dokumen uji D tipe 7 (nama/`transaction_no` `QA124-`, `id_transaction` DO nyata bertransaksi terkait, K-3) di folder F; N = salah satu `related_transactions[].name` D. Buat folder G bernama N (K-3); `GET archives?id_archive=F&search={"showRelatedTransaction":true}` (request FE, `ArchivePage/index.js:229-237`) -> 200, `related_transactions` D sama dengan sebelum G ada, tanpa G, tiap baris ber-`type` transaksi. Sama dengan `"query":"<nama D>"` ditambahkan | http, `result.data[].related_transactions[].id_archive` |
| AC-2 | Baris `archives` `type=2` bernama N tanpa baris `archive_documents` (data rusak) -> tidak ikut, 200 | http |
| AC-3 | Regresi: tanpa folder senama, hasil dokumen nyata tipe 6/7/8 + dokumen uji 7 tipe identik dengan `relatedTransaction()` `be.head` (pola ED-1069 AC-11); tanpa filter kunci tidak ada | unit (reflection) + http |
| AC-4 | (K-1 a) put-in `id_archives` [dokumen dihapus, folder dihapus] ke folder T dan ke root -> 404 `ARCHIVE400` "Dokumen tidak ditemukan", tidak berubah. [A aktif, X dihapus] -> 200 `ARCHIVE204`, `result` hanya A, A pindah + history, X tidak berubah. Sumber dihapus di luar lokasi -> tetap 403 `ARCHIVE407` | http + DB |
| AC-5 | Regresi butir 2: put-in `id_archives` dokumen `-1` -> 200, `is_active=1`; put-in `name` dokumen `-1` dan dokumen dihapus -> 200, `is_active=1`, history `place`/`move` | http + DB |
| AC-6 | `locations?id_archive_parent=0` -> 404 `ARCHIVE400`; tanpa parameter / `id_archive_parent=` -> 200 semua lokasi aktif; induk berlokasi -> lokasinya; id acak -> 404 (ED-1069 AC-10 tetap) | http |
| AC-7 | (K-2 b) Folder FQ bernama N1, tanpa dokumen N1: put-in `name`=N1, `hand-over`, `receive` -> 404 `ARCHIVE400`, FQ tidak berubah. Folder FQ3 (id lebih kecil) + dokumen D3 senama N3 di folder lain: put-in `name`=N3 ke T -> D3 pindah; `hand-over` N3 -> D3 `status=2` + history; FQ3 tidak berubah | http + DB |
| AC-8 | (K-2 b) Folder FQ2 bernama N2: `DocumentService::store` (`transaction_no`=N2, tipe 7, id fiktif QA) -> 1 baris `archives` `type=2`, `is_active=-1` + `archive_documents`; panggilan kedua tidak menggandakan; FQ2 tidak berubah | unit (service di harness) |
| AC-9 | Karakterisasi usang dibalik (pola ED-1069 AC-12): ED-1069 `scenario5-extra.php` X-4 (a) -> 200 tanpa folder; (b) -> AC-4; `scenario4-select-related.php:106-108` "0" -> asersi 404. `run.php ED-1069 --only=X-4` sebelum (FAIL) dan sesudah (PASS) | http |
| AC-10 | Suite ED-1024..ED-1029, ED-1069, ED-1071 lulus (terutama ED-1024 `scenario2-types.php:85`, ED-1027 `scenario2-list-counts.php:181`); tanpa 500 baru. Karakterisasi ED-1070 (ditunda; `ArchiveService::index` tidak berubah) tetap: ED-1025 X-8/X-13, ED-1027 scenario9 | http |

## 6. Keputusan untuk developer

**K-1 - put-in sumber yang sudah dihapus lewat `id_archives`** (risiko: rendah) - **Dijawab: a (2026-10-14)**
Situasi: Pindahkan / Pindah massal mengirim id baris list. Folder berisi dokumen aktif tidak bisa dihapus (`ARCHIVE401`,
`ArchiveService::delete`), jadi yang bisa hidup lagi lewat list basi (atau API) hanya folder kosong (subfolder kosongnya
tetap terhapus) atau dokumen yang dihapus sendiri. Contoh: B menghapus folder kosong X; A (list basi) memindahkan X ke Q
-> hari ini 200 dan X aktif lagi di Q.
Jawaban a: sumber dihapus (`is_active=0`) = id tak ada: semua dihapus -> 404 `ARCHIVE400`, tanpa perubahan; campuran ->
yang aktif saja dipindah (200). Tercetak (`-1`) tetap boleh; scan `name` tetap memulihkan. (Opsi lain: b seluruh
permintaan 404, c 422, d pertahankan.)

**K-2 - folder bernama nomor transaksi merusak pencarian per nama** (risiko: tinggi) - **Dijawab: b (2026-10-14)**
Situasi: `findName` tanpa tipe, ambil baris tertua. Folder bernama nomor transaksi yang dibuat sebelum transaksi itu
dicetak (mis. folder per SO): (i) cetak SO/DO/SI/SR/RP/BG/Billing tidak membuat dokumen (`SalesOrderService.php:789` dkk.);
(ii) scan "Simpan Dokumen" memindahkan folder; (iii) serah-terima/terima, juga otomatis dari Pengiriman/Penagihan
(`ShipmentService.php:914-922`, `BillingService.php:911-918`), mengubah status folder.
Jawaban b: dikerjakan di item ini; `findName` hanya dokumen; nama yang hanya cocok folder -> 404 `ARCHIVE400` (sejalan
opname: nama folder = `invalid`, ED-1026 AC-10). Dalam cakupan: AC-7, AC-8 + regresi cetak ED-1024 AC-11. (Opsi lain: a tiket terpisah.)

**K-3 - data uji butir 1 di luar baris fixture QA** (risiko: rendah) - **Dijawab: a (2026-10-14)**
Jawaban a: dokumen uji ber-`transaction_no`/nama `QA124-`, hanya `id_transaction` menunjuk DO/SI nyata (tabel transaksi
hanya dibaca; `relatedTransaction` memakai id), jadi tersapu ED-1149. Satu folder bernama persis nomor transaksi nyata
ditaruh di dalam folder uji QA (bukan root), id dicatat, dihapus lewat id (tidak tersapu `QA%`); data developer tidak
diubah. (Opsi lain: b di root, c tanpa nomor nyata.)

## 7. Di luar cakupan

- Bentuk parameter salah (`search={"showRelatedTransaction":["a"]}`, `sorts`, `pagination`) = ED-1070 (ditunda dari run
  ini); item ini kasus data saja dan hanya mengubah `ArchiveService::relatedTransaction`, bukan `index`.
- FE tidak berubah (`archive.function.js:57-70` sudah meratakan baris terkait); tanpa e2e.
- Select lokasi dengan induk dihapus tetap 200 lokasinya.
- Perilaku diterima A-1..A-5 (`knowledge/decisions.md`) tidak dibuka.
