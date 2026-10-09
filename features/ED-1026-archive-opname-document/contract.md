# Contract API - ED-1026 Archive Opname Document (revisi 2026-10-08, FB-3 "tanpa draft" + Gate 1 ronde 2)

Prefix `api/v5`, grup `document-archive` (`auth:api`). Permission per route §1-§5: **`Opname Document`** (`permission_v5:Opname Document`;
seed id 1139, EPIC K-4 a - FE & BE memakai nama). (rev Gate 1) §6, §7 (dan `GET opnames`): permission baca sesi milik **ED-1028** =
**`Opname Document`** (EPIC K-3 a; sebelumnya `List Archive`). Tanpa permission → 403 `GE0114`, `parameter` = nama permission.
Envelope sukses `Message::formatResponse`: `{ "status": "success", "msg_code": "...", "message": "...", "result": ... }`.
Response snake_case (FE meng-camelCase). Body request boleh camelCase (kunci dikonversi rekursif oleh `req_snake`; nilai tidak).
Error `ErrorMessageException`: `{ "message", "code", ["parameter"] }` dengan status di tabel; `ARCHIVE414` dikirim sebagai
`formatResponse(false, ...)` (`status: "fail"`, `msg_code`) agar FE bisa bercabang. Validasi = 422 Laravel default.
Error akses dipakai ulang dari item 01/02: `ARCHIVE400` 404 (folder tidak ada/nonaktif), `ARCHIVE407` 403 (di luar scope lokasi /
tanpa View folder), `ARCHIVE417` 400 (bukan folder).

**Revisi FB-3:** tidak ada sesi di server sebelum Konfirmasi Opname. §2-§4 tidak menulis apa pun ke DB; §5 menyimpan sesi
langsung terkonfirmasi. **Dihapus:** `PUT opnames/{id}`, `DELETE opnames/{id}`, `POST opnames/{id}/scan`, `PUT opnames/{id}/confirm`
(rute tidak ada → 404/405), kode `ARCHIVE211`, `ARCHIVE214`, `ARCHIVE413`, status sesi 1/3.

**Revisi Gate 1 ronde 2 (2026-10-08):** baca sesi (§6, §7, `GET opnames`) = `Opname Document` (EPIC K-3 a, route milik ED-1028);
`scope` tanpa `first_name`/`other_count` (ED-1028 K-10 b); §6 `folders`/`warnings` disaring per pemanggil (C-8, ED-1028 BR-16);
id seed `Opname Document` 1139 (EPIC K-4 a, seed oleh ED-1025).

## Objek bersama

`opnamed_today` (null bila belum diopname hari ini, tanggal Asia/Jakarta; `confirmed_at` = konfirmasi terakhir hari ini):
```json
{ "id_archive_opname": "2610...", "confirmed_at": "2026-10-06 14:02:11", "created_by": "kevinsudjadi" }
```

`selection` (body §2-§5) - pilihan Step 1 + daftar scan yang disimpan FE:
```json
{ "id_archive": null, "is_continue": false,
  "folders": [ { "id_archive": "2502...", "is_continue": false }, { "id_archive": "2502...", "is_continue": true } ],
  "scans": [ { "code": "DO-MGL/2604/00001", "scanned_at": "2026-10-08 09:41:02" } ] }
```
| field | aturan |
|---|---|
| `id_archive` | nullable, string, max 30 (folder opname; null = root). Dicek di `authorize()` sebelum 422 field lain: `ARCHIVE400` 404 / `ARCHIVE417` 400 / `ARCHIVE407` 403; nilai yang melanggar aturannya sendiri (bukan string, > 30) tidak dicari → 422 |
| `is_continue` | boolean, opsional (Lanjut opname untuk dokumen langsung folder opname; hanya berlaku bila folder itu sudah diopname hari ini) |
| `folders` | array, boleh kosong; `folders.*.id_archive` required, string, max 30, distinct; `folders.*.is_continue` boolean (hanya berlaku untuk folder yang sudah diopname hari ini). Bukan subfolder langsung aktif yang terlihat dari folder opname → 400 `ARCHIVE416` |
| `scans` | array, boleh kosong (tidak dipakai §2). Urut waktu scan **lama → baru**. `scans.*.code` required, string, max 255, hanya Latin-1 (U+0000-U+007F, U+00A0-U+00FF); `scans.*.scanned_at` required, `Y-m-d H:i:s` (nilai `row.scanned_at` dari §2). Kode ganda (tanpa beda huruf besar/kecil & spasi akhir, kolasi `archives.name`) dihitung sekali (yang pertama) |

`document row` (Step 2/3, hasil sesi):
| field | tipe | arti |
|---|---|---|
| `id_archive_opname_document` | string\|null | null pada §2/§4 (belum tersimpan); terisi pada sesi terkonfirmasi §7 |
| `id_archive` | string\|null | dokumen archive; null = Invalid |
| `code` | string | teks scan (belum discan = nomor dokumen) |
| `transaction_no` | string\|null | `archive_documents.transaction_no` |
| `transaction_type` | int\|null | id tipe (item 01 `ArchiveDocument::TRANSACTION_TYPES`) |
| `transaction_type_label` | string\|null | label bahasa user |
| `salesman` | string\|null | `archive_documents.related_employee_name` |
| `id_archive_folder` | string\|null | folder dokumen dalam cakupan; null = root atau di luar cakupan |
| `folder_name` | string\|null | nama folder; null bila root / di luar cakupan / Invalid (FE: root `archive.All Archive`, Not found "Di luar scope opname", Invalid "—") |
| `is_out_of_scope` | bool | true untuk Not found |
| `result` | string | `verified` \| `not_found` \| `invalid` \| `unscanned` |
| `result_label` | string | label bahasa user (en: Verified / Not found / Invalid / Not scanned; id: Terverifikasi / Tidak ditemukan / Tidak valid / Belum discan) |
| `is_verified_after` | bool\|null | status sesudah Confirm (null pada §2/§4) |
| `scanned_at` | string\|null | `Y-m-d H:i:s`; null bila belum discan |

`scope` (§3, §6; juga baris riwayat ED-1028 dan `last_sessions` ED-1027), dibentuk `OpnameService::scope()`:
```json
{ "is_root": false, "folder_name": "Cabang Jogja", "subfolder_count": 3 }
```
`folder_name` = nama folder opname (root: null); `subfolder_count` = jumlah subfolder yang dicentang di Step 1. (rev Gate 1,
ED-1028 K-10 b) `first_name`/`other_count` **dihapus** (nama subfolder tidak dikirim); contract ED-1027 (`last_sessions[].scope`)
dan ED-1028 (baris `GET opnames`) mengikuti. Tampilan FE `getScopeTag()` di semua tempat (spec K-14 a, BR-30): root ?
"All Archive · root" (id "Semua Arsip · root") : `folder_name`, ditambah " + {subfolder_count} subfolder" bila > 0. Modal Step 1
memakai 0, Step 2-3 jumlah subfolder dicentang (lokal).

## 1. GET `api/v5/document-archive/opnames/folders` - data Step 1
Query: `id_archive` (string, opsional; kosong = root).
```json
{ "msg_code": "ARCHIVE200", "result": {
  "folder": { "id_archive": null, "name": null, "is_root": true, "document_count": 4234, "opnamed_today": null },
  "children": [
    { "id_archive": "2502...", "name": "Backup Arsip", "document_count": 412, "has_children": true,
      "opnamed_today": null, "is_default_checked": true },
    { "id_archive": "2502...", "name": "CABANG - SEMARANG", "document_count": 1104, "has_children": false,
      "opnamed_today": { "id_archive_opname": "...", "confirmed_at": "2026-10-06 14:02:11", "created_by": "lydia" },
      "is_default_checked": false }
  ],
  "skip_select_folder": false,
  "selected_at": "2026-10-08 09:40:00" } }
```
- `folder.document_count` = dokumen langsung di folder opname (root: dokumen tanpa folder); `children[].document_count` = seluruh
  subtree yang terlihat. `children` = subfolder langsung aktif yang terlihat (lokasi 01 ∩ View 02), urut nama.
- `skip_select_folder` = `children` kosong **dan** `folder.opnamed_today` null.
- (rev) `selected_at` = waktu server saat respons dibuat; FE menyimpannya (setiap Step 1 dimuat ulang) dan mengirimnya di §5.

| Error | HTTP | Arti |
|---|---|---|
| `ARCHIVE400` | 404 | `id_archive` tidak ada / nonaktif |
| `ARCHIVE417` | 400 | `id_archive` adalah dokumen, bukan folder |
| `ARCHIVE407` | 403 | folder di luar scope user / tanpa View |

## 2. POST `api/v5/document-archive/opnames/scan` - klasifikasi satu kode (baru, tanpa simpan)
Body: `selection` tanpa `scans` + `code` (aturan sama dengan `scans.*.code`, di-trim). Kode duplikat dicek FE (spec BR-14), bukan di sini.
Sukses `ARCHIVE212` (FE tanpa toast):
```json
{ "row": { "...": "document row", "result": "verified", "scanned_at": "2026-10-08 09:41:02" } }
```
`row.scanned_at` = waktu server saat scan; FE menyimpannya dan mengirimnya kembali di `scans`. Tidak ada baris DB yang dibuat.

| Error | HTTP | Arti |
|---|---|---|
| `ARCHIVE400` / `ARCHIVE417` / `ARCHIVE407` | 404 / 400 / 403 | folder opname (lihat `selection`) |
| `ARCHIVE416` | 400 | subfolder terpilih bukan (lagi) subfolder langsung terlihat |
| (validasi) | 422 | `code` kosong / > 255 / bukan Latin-1, field `selection` tidak valid |

## 3. POST `api/v5/document-archive/opnames/preview` - ringkasan Step 2 & Step 3 (baru, tanpa simpan)
Body: `selection` (dengan `scans`). Cakupan dan tiap kode dinilai terhadap kondisi saat ini.
```json
{ "msg_code": "ARCHIVE200", "result": {
  "id_archive": null,
  "scope": { "is_root": true, "folder_name": null, "subfolder_count": 4 },
  "folders": [
    { "id_archive": null, "name": null, "level": 0, "is_continue": false, "is_opnamed_today": false, "document_count": 4234 },
    { "id_archive": "2502...", "name": "Backup Arsip", "level": 1, "is_continue": false, "is_opnamed_today": false, "document_count": 412 }
  ],
  "counts": { "total_documents": 3400, "verified_before": 1088, "scanned": 12, "verified": 8, "not_found": 2,
              "invalid": 2, "unscanned": 3392, "unverified": 96 },
  "result_counts": { "all": 3404, "scanned": 12, "verified": 8, "not_found": 2, "invalid": 2, "unscanned": 3392 },
  "is_partial": false,
  "warnings": [ { "id_archive": "2502...", "name": "PAK IMAN - TRANSAKSI LUNAS", "is_opnamed_today": true, "unverify_count": 96 } ] } }
```
- `folders` = level 0-1; `document_count` level 0 = dokumen langsung folder opname, level 1 = seluruh subtree terlihat (seperti Step 1).
- `counts.unverified` = perkiraan dokumen verified tak discan yang akan menjadi unverified; `unscanned` = `total_documents` − `verified`.
- `warnings`: satu per baris level 0-1 yang tidak lanjut dan `unverify_count` > 0 (root: `id_archive`/`name` null, FE menampilkan label root).
- Tanpa saringan per pemanggil (pembuat hanya memilih folder terlihat; saringan D-1 hanya §6).
- Error sama dengan §2 (`ARCHIVE400`/`417`/`407`/`416`, 422).

## 4. POST `api/v5/document-archive/opnames/preview/documents` - tabel Step 2/3 (baru, tanpa simpan)
Body: `selection` (dengan `scans`) + `result` = `all` (default) \| `scanned` \| `verified` \| `not_found` \| `invalid` \| `unscanned`;
`page`; `pagination` (default `AppConfig::getPagination()`) - ketiganya juga diterima sebagai query string.
Urutan: baris scan `scanned_at` terbaru dulu (sama detik: urutan `scans` terbalik), lalu belum discan per `code`.
Response = paginator Laravel **tanpa** `columns`/`queries` (bentuk sama dengan §7):
```json
{ "msg_code": "ARCHIVE200", "result": { "current_page": 1, "data": [ { "...": "document row" } ], "first_page_url": "...",
  "from": 1, "last_page": 340, "last_page_url": "...", "next_page_url": "...", "path": "...", "per_page": 10,
  "prev_page_url": null, "to": 10, "total": 3404 } }
```
`all` = dokumen cakupan + Not found + Invalid; `scanned` = semua baris scan (dipakai FE untuk klasifikasi ulang daftar scan Step 2).
Error sama dengan §2; `result` tidak dikenal → 422.

## 5. POST `api/v5/document-archive/opnames` - Konfirmasi Opname (ubah: menyimpan sesi terkonfirmasi)
Body: `selection` (dengan `scans`) + `selected_at` (required, `Y-m-d H:i:s`, nilai §1).
Dalam satu transaksi: menilai ulang cakupan & tiap kode, menerapkan verified (spec BR-11), menyimpan `archive_opnames` (`status` 2,
`selected_at` dari body, `confirmed_at` = sekarang), `archive_opname_folders` level 0-2 dan `archive_opname_documents` (scan +
belum discan; `scanned_at` dari `scans`, dibuat naik per urutan). Gagal apa pun → tidak ada yang tersimpan.
Sukses `ARCHIVE213` (FE menampilkan toast): `{ "id_archive_opname": "2610..." }`.

| Error | HTTP | Arti |
|---|---|---|
| `ARCHIVE414` | 400 | folder tercakup sudah dikonfirmasi sesi lain pada/sesudah `selected_at` (termasuk mengirim ulang body yang sama sesudah berhasil); body `formatResponse` fail, `msg_code: "ARCHIVE414"`, `result: { "folders": [ { "id_archive", "name", "created_by", "confirmed_at" } ] }` (root: `id_archive`/`name` null), parameter `[0]` nama folder (root: "All Archive" / "Semua Arsip" sesuai bahasa user), `[1]` user, `[2]` jam `HH:mm` |
| `ARCHIVE415` | 400 | `selected_at` atau salah satu `scans.*.scanned_at` bukan tanggal hari ini (Asia/Jakarta), atau `selected_at` sesudah waktu server sekarang (nilai §1 tidak pernah begitu) |
| `ARCHIVE416` | 400 | subfolder terpilih bukan (lagi) subfolder langsung terlihat |
| `ARCHIVE400` / `ARCHIVE417` / `ARCHIVE407` | 404 / 400 / 403 | folder opname (lihat `selection`) |
| (validasi) | 422 | field tidak valid, `selected_at` kosong |

Urutan cek: folder opname (sebelum 422) → 422 → `ARCHIVE415` → `ARCHIVE416` → `ARCHIVE414`.

## 6. GET `api/v5/document-archive/opnames/{id}` - ringkasan sesi terkonfirmasi (riwayat)
Permission: ED-1028 (`Opname Document`, EPIC K-3 a). Bentuk = §3 ditambah `id_archive_opname`, `status` (selalu 2), `status_label`
(Confirmed / Dikonfirmasi), `created_by`, `created_at`, `confirmed_at`; `folders` level 0-2 dengan `document_count` = dokumen langsung
saat Confirm; `counts` = nilai tersimpan; `result_counts`/`is_partial` & saringan baris mengikuti ED-1028; `scope` seperti objek bersama.
(rev Gate 1, C-8) `folders`/`warnings` **disaring per pemanggil** menurut ED-1028 BR-16 (D-1, dikerjakan ED-1028): bukan superadmin 1/2
(termasuk pembuat sesi) → `folders[]` hanya baris yang `id_archive`-nya folder terlihat pemanggil saat ini (root selalu; nama &
`document_count` tetap snapshot); `warnings[]` hanya level 0-1 yang lolos (root selalu), `unverify_count` = folder itu + turunan level 2
yang juga lolos, hasil 0 tidak dikirim. Superadmin 1/2: semua.
Error: 403 `GE0114` (tanpa permission); `ARCHIVE412` 404 (tidak ada, bukan sesi terkonfirmasi, atau tidak terlihat bagi pemanggil - aturan ED-1028).

## 7. GET `api/v5/document-archive/opnames/{id}/documents` - hasil per dokumen sesi terkonfirmasi
Query `result`, `page`, `pagination` seperti §4; data dari snapshot; urutan `scanned_at` terbaru dulu lalu belum discan per `code`.
Paginator Laravel tanpa `columns`/`queries`. Permission & akses seperti §6. Error: 403 `GE0114`; `ARCHIVE412` 404; `result` tidak dikenal 422.

## Kode pesan (`app/Lib/lang/en_EN.php` + `id_ID.php`)
| Kode | en | id | Status |
|---|---|---|---|
| `ARCHIVE212` | Document scanned | Dokumen discan | tetap (§2) |
| `ARCHIVE213` | Opname successfully confirmed | Opname berhasil dikonfirmasi | tetap (§5) |
| `ARCHIVE412` | Opname session isn't found | Sesi opname tidak ditemukan | tetap (§6, §7) |
| `ARCHIVE414` | Folder <b>[0]</b> was opnamed by <b>[1]</b> at [2] while this session was running. Please re-select the folders | Folder <b>[0]</b> sudah diopname oleh <b>[1]</b> pukul [2] selama sesi ini berjalan. Pilih ulang folder | tetap; `[0]` root diterjemahkan |
| `ARCHIVE415` | This opname session was started on another day. Please start a new session | Sesi opname ini dimulai di hari lain. Mulai sesi baru | tetap, arti §5 |
| `ARCHIVE416` | Selected folder must be a direct subfolder of the opname folder | Folder yang dipilih harus subfolder langsung dari folder yang diopname | tetap |
| `ARCHIVE417` | Opname can only be run on a folder | Opname hanya bisa dijalankan pada folder | tetap |
| `ARCHIVE211`, `ARCHIVE214`, `ARCHIVE413` | - | - | **dihapus** |

## Kunci FE (`configuration/endpoints.js`, blok Archive)
`getArchiveOpnameFolders: 'document-archive/opnames/folders'`, `postArchiveOpnameScan: 'document-archive/opnames/scan'` (path baru),
`postArchiveOpnamePreview: 'document-archive/opnames/preview'`, `postArchiveOpnamePreviewDocuments: 'document-archive/opnames/preview/documents'`,
`postArchiveOpname: 'document-archive/opnames'` (= Konfirmasi), `getArchiveOpname: 'document-archive/opnames/:id'`,
`getArchiveOpnameDocuments: 'document-archive/opnames/:id/documents'`. Dihapus: `putArchiveOpname`, `deleteArchiveOpname`,
`putArchiveOpnameConfirm`. Permission FE: `OpnameDocument: 'Opname Document'` (nama; id seed 1139 tidak dipakai FE).
