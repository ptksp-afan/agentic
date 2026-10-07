# Contract API - ED-1026 Archive Opname Document

Prefix `api/v5`, grup `document-archive` (`auth:api`). Permission per route: **`Opname Document`** (`permission_v5:Opname Document`),
kecuali §4 dan §6 = **`List Archive`** (G-4, gate1 O-8); tanpa permission → 403 `GE0114`, `parameter` = nama permission.
Envelope sukses `Message::formatResponse`: `{ "status": "success", "msg_code": "...", "message": "...", "result": ... }`.
Response snake_case (FE meng-camelCase). Body request boleh camelCase di level terluar (`req_snake`).
Error `ErrorMessageException`: `{ "message", "code", ["parameter"] }` dengan status di tabel; `ARCHIVE414` dikirim
sebagai `formatResponse(false, ...)` (`status: "fail"`, `msg_code`) agar FE bisa bercabang. Validasi = 422 Laravel default.
Error akses yang dipakai ulang dari item 01/02: `ARCHIVE400` 404 (folder tidak ada/nonaktif), `ARCHIVE407` 403 (di luar scope
lokasi / tanpa View folder).

## Objek bersama

`opnamed_today` (null bila belum diopname hari ini, tanggal Asia/Jakarta; `confirmed_at` = konfirmasi terakhir hari ini):
```json
{ "id_archive_opname": "2610...", "confirmed_at": "2026-10-06 14:02:11", "created_by": "kevinsudjadi" }
```

`document row` (Step 2/3, hasil sesi):
| field | tipe | arti |
|---|---|---|
| `id_archive_opname_document` | string\|null | null untuk baris belum discan pada draft (dihitung langsung) |
| `id_archive` | string\|null | dokumen archive; null = Invalid |
| `code` | string | teks scan (belum discan = nomor dokumen) |
| `transaction_no` | string\|null | `archive_documents.transaction_no` |
| `transaction_type` | int\|null | id tipe (item 01 `ArchiveDocument::TRANSACTION_TYPES`) |
| `transaction_type_label` | string\|null | label bahasa user |
| `salesman` | string\|null | `archive_documents.related_employee_name` |
| `id_archive_folder` | string\|null | folder dokumen dalam cakupan; null = root atau di luar cakupan |
| `folder_name` | string\|null | nama folder; null bila di luar cakupan/Invalid (FE: "Di luar scope opname" / "—") |
| `is_out_of_scope` | bool | true untuk Not found |
| `result` | string | `verified` \| `not_found` \| `invalid` \| `unscanned` |
| `result_label` | string | label bahasa user (en: Verified / Not found / Invalid / Not scanned; id: Terverifikasi / Tidak ditemukan / Tidak valid / Belum discan) |
| `is_verified_after` | bool\|null | status sesudah Confirm (null pada draft) |
| `scanned_at` | string\|null | `Y-m-d H:i:s` waktu server; null bila belum discan |

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
  "skip_select_folder": false } }
```
- `folder.document_count` = dokumen langsung di folder opname (root: dokumen tanpa folder); `children[].document_count` =
  dokumen di seluruh subtree yang terlihat. `children` hanya subfolder langsung aktif yang terlihat (lokasi 01 ∩ View 02), urut nama.
- `skip_select_folder` = `children` kosong **dan** `folder.opnamed_today` null.

| Error | HTTP | Arti |
|---|---|---|
| `ARCHIVE400` | 404 | `id_archive` tidak ada / nonaktif |
| `ARCHIVE417` | 400 | `id_archive` adalah dokumen, bukan folder |
| `ARCHIVE407` | 403 | folder di luar scope user / tanpa View |

## 2. POST `api/v5/document-archive/opnames` - buat sesi (Next Step 1, atau saat Step 1 dilewati)
Body:
```json
{ "id_archive": null, "is_continue": false,
  "folders": [ { "id_archive": "2502...", "is_continue": false }, { "id_archive": "2502...", "is_continue": true } ] }
```
| field | aturan |
|---|---|
| `id_archive` | nullable, string, max 30 (folder opname; null = root) |
| `is_continue` | boolean, opsional (Lanjut opname untuk dokumen langsung folder opname) |
| `folders` | array, boleh kosong; `folders.*.id_archive` required, string, distinct; `folders.*.is_continue` boolean |

`is_continue` hanya berlaku untuk folder yang sudah diopname hari ini; selain itu disimpan `false`.
Sukses `ARCHIVE211`: `{ "id_archive_opname": "2610..." }`.

| Error | HTTP | Arti |
|---|---|---|
| `ARCHIVE400` / `ARCHIVE417` / `ARCHIVE407` | 404 / 400 / 403 | seperti endpoint 1, untuk `id_archive` (dicek di `authorize()`, sebelum 422 field lain); `id_archive` yang melanggar aturannya sendiri (bukan string, > 30 karakter) tidak dicari → 422, sama dengan endpoint 1 |
| `ARCHIVE416` | 400 | salah satu `folders.*.id_archive` bukan subfolder langsung aktif yang terlihat dari folder opname |
| (validasi) | 422 | field tidak valid |

## 3. PUT `api/v5/document-archive/opnames/{id}` - ganti pilihan folder (Back lalu Next)
Body sama dengan POST tanpa `id_archive` (folder opname tetap). Hasil scan dipertahankan dan diklasifikasi ulang;
`selected_at` diperbarui. Sukses `ARCHIVE211`: `{ "id_archive_opname": "..." }`.
Error: `ARCHIVE412` 404 (tidak ada / draft milik user lain), `ARCHIVE413` 400 (bukan draft), `ARCHIVE416` 400, 422; folder opname
sudah tidak ada/nonaktif/terlihat sejak draft dibuat -> `ARCHIVE400` 404 / `ARCHIVE407` 403 seperti POST.

## 4. GET `api/v5/document-archive/opnames/{id}` - ringkasan sesi (Step 2, Step 3, history)
```json
{ "msg_code": "ARCHIVE200", "result": {
  "id_archive_opname": "2610...", "id_archive": null, "status": 1, "status_label": "Berjalan",
  "scope": { "is_root": true, "folder_name": null, "first_name": "Backup Arsip", "other_count": 3 },
  "folders": [
    { "id_archive": null, "name": null, "level": 0, "is_continue": false, "is_opnamed_today": false, "document_count": 4234 },
    { "id_archive": "2502...", "name": "Backup Arsip", "level": 1, "is_continue": false, "is_opnamed_today": false, "document_count": 412 }
  ],
  "counts": { "total_documents": 3400, "verified_before": 1088, "scanned": 12, "verified": 8, "not_found": 2,
              "invalid": 2, "unscanned": 3392, "unverified": 96 },
  "warnings": [ { "id_archive": "2502...", "name": "PAK IMAN - TRANSAKSI LUNAS", "is_opnamed_today": true, "unverify_count": 96 } ],
  "created_by": "kevinsudjadi", "created_at": "2026-10-06 09:40:02", "confirmed_at": null } }
```
- `status` 1 berjalan, 2 dikonfirmasi, 3 dibatalkan; `status_label` en Running / Confirmed / Cancelled, id Berjalan /
  Dikonfirmasi / Dibatalkan. `folders` = level 0-1 (draft; `document_count` level 0 = dokumen langsung folder opname, level 1 =
  seluruh subtree terlihat, seperti Step 1) atau 0-2 (dikonfirmasi; `document_count` = dokumen langsung saat Confirm).
- Draft (dan sesi batal milik sendiri): cakupan dan hasil setiap baris scan dinilai ulang langsung terhadap kondisi saat ini
  (dokumen pindah/folder tak terlihat lagi ikut berubah); subfolder terpilih yang bukan lagi subfolder langsung terlihat tidak
  dihitung (tanpa error). Hal yang sama berlaku untuk `/scan` (`counts`), `/documents` dan Confirm. `scope.first_name`/`other_count` = subfolder pertama dicentang + sisa (tag BR-15);
  tanpa subfolder dicentang: `first_name` null.
- Draft: `counts` dan `warnings` dihitung langsung (`unverified` = perkiraan); dikonfirmasi: nilai tersimpan.
- `warnings`: satu per baris level 0-1 yang tidak lanjut dan `unverify_count` > 0 (dokumen verified tak discan yang akan/telah jadi unverified).
- `unscanned` = `total_documents` − `verified`.

Permission `List Archive`. Error: `ARCHIVE412` 404 (tidak ada, draft milik user lain, atau sesi terkonfirmasi milik user lain bagi
non-superadmin 1/2; 05 memperluas ke sesi terlihat).

## 5. POST `api/v5/document-archive/opnames/{id}/scan`
Body: `{ "code": "DO-MGL/2604/00001" }` - `code` required, string, max 255 (di-trim), hanya karakter Latin-1 (U+0000-U+007F,
U+00A0-U+00FF; lainnya 422 karena kolom `latin1` dan tidak mungkin nomor dokumen). Pembanding kode tidak membedakan huruf besar/kecil
(kolasi `archives.name`).
Sukses `ARCHIVE212`:
```json
{ "row": { "...": "document row" }, "is_duplicate": false,
  "counts": { "scanned": 12, "verified": 8, "not_found": 2, "invalid": 2 } }
```
Kode yang sudah ada di sesi → baris lama, `is_duplicate: true`, tanpa baris/angka baru.

| Error | HTTP | Arti |
|---|---|---|
| `ARCHIVE412` | 404 | sesi tidak ada / draft milik user lain |
| `ARCHIVE413` | 400 | sesi sudah dikonfirmasi/dibatalkan |
| (validasi) | 422 | `code` kosong / > 255 |

## 6. GET `api/v5/document-archive/opnames/{id}/documents` - tabel Step 2/3 & hasil sesi
Query: `result` = `all` (default) \| `scanned` \| `verified` \| `not_found` \| `invalid` \| `unscanned`; `page`;
`pagination` (default `AppConfig::getPagination()`). Urutan: baris scan `scanned_at` terbaru dulu, lalu belum discan per `code`.
Waktu scan disimpan sampai mikrodetik dan selalu naik per scan dalam satu sesi, jadi beberapa scan dalam detik yang sama tetap
urut scan terbaru dulu (draft maupun sesudah Confirm); `scanned_at` di response tetap `Y-m-d H:i:s`.
Response = paginator Laravel **tanpa** `columns`/`queries`:
```json
{ "msg_code": "ARCHIVE200", "result": { "current_page": 1, "data": [ { "...": "document row" } ], "first_page_url": "...",
  "from": 1, "last_page": 340, "last_page_url": "...", "next_page_url": "...", "path": "...", "per_page": 10,
  "prev_page_url": null, "to": 10, "total": 3404 } }
```
`all` = dokumen cakupan + Not found + Invalid; `scanned` = semua baris scan. Draft: baris `unscanned` dihitung langsung
(`id_archive_opname_document` null); dikonfirmasi: dari snapshot. Permission `List Archive`, akses seperti §4. Error: `ARCHIVE412` 404; `result` tidak dikenal 422.

## 7. PUT `api/v5/document-archive/opnames/{id}/confirm`
Tanpa body. Menilai ulang cakupan & hasil scan, menerapkan verified (BR-11), menyimpan snapshot, `status` 2.
Sukses `ARCHIVE213`: `{ "id_archive_opname": "..." }`.

| Error | HTTP | Arti |
|---|---|---|
| `ARCHIVE412` | 404 | sesi tidak ada / draft milik user lain |
| `ARCHIVE413` | 400 | sesi sudah dikonfirmasi/dibatalkan |
| `ARCHIVE414` | 400 | folder tercakup sudah dikonfirmasi sesi lain pada/sesudah detik pilihan folder sesi ini (`confirmed_at >= selected_at`); body `formatResponse` fail, `msg_code: "ARCHIVE414"`, `result: { "folders": [ { "id_archive", "name", "created_by", "confirmed_at" } ] }` (satu per folder, root: `id_archive`/`name` null), parameter pesan `[0]` folder (root: "All Archive"), `[1]` user, `[2]` jam `HH:mm` |
| `ARCHIVE415` | 400 | sesi dimulai di hari lain (`created_at`, tanggal server Asia/Jakarta) |
| `ARCHIVE400` / `ARCHIVE417` / `ARCHIVE407` | 404 / 400 / 403 | folder opname sudah tidak ada/nonaktif/terlihat (seperti POST) |

## 8. DELETE `api/v5/document-archive/opnames/{id}` - batal
Sukses `ARCHIVE214`: `{ "id_archive_opname": "..." }` (`status` 3). Error: `ARCHIVE412` 404, `ARCHIVE413` 400.

## Kode pesan baru (`app/Lib/lang/en_EN.php` + `id_ID.php`)
| Kode | en | id |
|---|---|---|
| `ARCHIVE211` | Opname session saved | Sesi opname disimpan |
| `ARCHIVE212` | Document scanned | Dokumen discan |
| `ARCHIVE213` | Opname successfully confirmed | Opname berhasil dikonfirmasi |
| `ARCHIVE214` | Opname session cancelled | Sesi opname dibatalkan |
| `ARCHIVE412` | Opname session isn't found | Sesi opname tidak ditemukan |
| `ARCHIVE413` | Opname session has already been confirmed or cancelled | Sesi opname sudah dikonfirmasi atau dibatalkan |
| `ARCHIVE414` | Folder <b>[0]</b> was opnamed by <b>[1]</b> at [2] while this session was running. Please re-select the folders | Folder <b>[0]</b> sudah diopname oleh <b>[1]</b> pukul [2] selama sesi ini berjalan. Pilih ulang folder |
| `ARCHIVE415` | This opname session was started on another day. Please start a new session | Sesi opname ini dimulai di hari lain. Mulai sesi baru |
| `ARCHIVE416` | Selected folder must be a direct subfolder of the opname folder | Folder yang dipilih harus subfolder langsung dari folder yang diopname |
| `ARCHIVE417` | Opname can only be run on a folder | Opname hanya bisa dijalankan pada folder |

## Kunci FE (`configuration/endpoints.js`, blok Archive)
`getArchiveOpnameFolders: 'document-archive/opnames/folders'`, `postArchiveOpname: 'document-archive/opnames'`,
`getArchiveOpname` / `putArchiveOpname` / `deleteArchiveOpname: 'document-archive/opnames/:id'`,
`postArchiveOpnameScan: 'document-archive/opnames/:id/scan'`, `getArchiveOpnameDocuments: 'document-archive/opnames/:id/documents'`,
`putArchiveOpnameConfirm: 'document-archive/opnames/:id/confirm'`. Permission FE: `OpnameDocument: 'Opname Document'`.
