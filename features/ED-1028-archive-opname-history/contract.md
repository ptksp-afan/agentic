# Kontrak API - ED-1028 Opname History

Base `api/v5`, header `Authorization: Bearer <token>`, `Accept: application/json`. Response **snake_case** (FE
meng-camelCase lewat interceptor). Body/param terluar boleh camelCase (`req_snake`); isi JSON-string `search` **tidak**
dikonversi: kunci persis seperti tabel di bawah (camelCase).
Error `ErrorMessageException`: `{ "message", "code", ["parameter"] }` dengan status di tabel; validasi = 422 Laravel.
Permission: **`List Archive`** (sudah ada, `permission_salesman.sql:67-76`, hanya di-seed untuk lisensi Salesman
Activity). Tanpa permission → 403 `GE0114` (`parameter` = nama permission). Tidak ada permission & kode pesan baru.

"Sesi terlihat" = sesi `archive_opnames.status = 2` (dikonfirmasi) yang lolos aturan 04 K-4 untuk pemanggil
(superadmin `is_superadmin` 1/2 = semua), termasuk EPIC K-1 a: sesi dari root terlihat semua pemegang `List Archive`;
sesi yang semua foldernya kini terhapus/tak terlihat hanya untuk superadmin 1/2 dan pembuat sesi. "Mencakup folder X" = definisi 04 K-3 (X atau subfoldernya).

## Objek bersama

`history_row` (baris list; kunci kolom hanya ada bila kolomnya aktif di display setting, kecuali `id_archive_opname`,
`id_archive`, `scope` yang selalu ada):
```json
{
  "id_archive_opname": "2610...",
  "id_archive": "2502...",
  "confirmed_at": "2026-08-12 14:02:11",
  "created_by": "kevinsudjadi",
  "scope": { "is_root": false, "folder_name": "Backup", "first_name": "Backup Arsip", "other_count": 3 },
  "total_documents": 3400,
  "verified_count": 126,
  "not_found_count": 8,
  "invalid_count": 3,
  "scanned_count": 137,
  "unscanned_count": 3274
}
```
- `id_archive` = folder tempat opname dijalankan, `null` = root.
- `scope` = objek **sama persis** dengan `scope` di `GET opnames/{id}` (kontrak 03 §4), dibentuk method yang sama; FE
  memakai `getScopeTag()` 03 ("All Archive · root" / "<first_name> + <other_count> subfolder" / `folder_name`).
- Angka = nilai tersimpan saat Confirm (tidak dihitung ulang).

## 1. GET `document-archive/opnames` (baru) - daftar sesi Opname History

Permission `List Archive`. Controller `OpnameController@index`.

| Param | Tipe | Arti |
|---|---|---|
| `page` | int | halaman |
| `pagination` | int | ukuran halaman, bawaan `AppConfig::getPagination()` |
| `sort` / `sorts` | array `{sortBy, sortType}` | elemen JSON string (bentuk FE `sorts[]={"sortBy":…}`) atau objek. `sortBy` ∈ `confirmedAt`, `createdBy`, `totalDocuments`, `verifiedCount`, `notFoundCount`, `invalidCount`, `scannedCount`, `unscannedCount` (juga bila kolomnya tidak aktif); lain (mis. `scope`) diabaikan tanpa error. `sortType` `asc`, selain itu `desc`. Tanpa sort: `confirmed_at desc`; pemecah seri selalu `confirmed_at desc, id_archive_opname desc` (= urutan `last_sessions`) |
| `id_archive` | string, opsional | konteks folder (dari tab Verification): hanya sesi yang mencakup folder ini |
| `search` | JSON string | kunci di bawah; kosong/`null` = tanpa filter |

Kunci `search` (camelCase persis):

| Kunci | Tipe | Arti |
|---|---|---|
| `query` | string | mengandung (tanpa beda huruf; di-trim; `%`/`_` harfiah) pada `created_by` **atau** `archive_opname_folders.name` (level 0-2) sesi itu; untuk non-superadmin hanya folder dalam `viewableFolderIds()`. Teks di luar latin1 (mis. CJK, emoji) = tanpa hasil (200), bukan error |
| `confirmedAt` | `[string\|null, string\|null]` | `Y-m-d H:i:s`; `[awal, akhir]` inklusif pada `confirmed_at`; salah satu boleh `null`/kosong |
| `createdBy` | string | username persis (nilai dari select opname-users); kosong = tanpa filter |
| `idArchives` | string[] | sesi yang mencakup **salah satu** folder ini; id di luar scope pemanggil tidak mencocokkan apa pun; `[]` = tanpa filter |

200:
```json
{
  "status": "success", "msg_code": "ARCHIVE200", "message": "Document is found",
  "result": {
    "current_page": 1,
    "data": [ { "...": "history_row" } ],
    "first_page_url": "...", "from": 1, "last_page": 3, "last_page_url": "...", "next_page_url": "...",
    "path": "...", "per_page": 10, "prev_page_url": null, "to": 10, "total": 27,
    "columns": [ "... current_columns display setting (§4)" ],
    "queries": [ "... current_queries display setting (§4)" ]
  }
}
```

| HTTP | Kode | Arti |
|---|---|---|
| 404 | `ARCHIVE400` | `id_archive` tidak ada / nonaktif |
| 400 | `ARCHIVE417` | `id_archive` adalah dokumen, bukan folder (kode "bukan folder" 03, dipakai juga 04) |
| 403 | `ARCHIVE407` | `id_archive` di luar scope lokasi (01) atau tanpa View efektif (02) |
| 422 | (validasi) | `search` bukan JSON objek (termasuk list non-kosong, mis. `[1,2]`; `[]` = tanpa filter seperti `{}`); `confirmedAt` bukan array 2 elemen / tanggal bukan `Y-m-d H:i:s`; `idArchives` bukan array string; `createdBy` bukan string. Kunci error = kunci `search` (`confirmedAt.0`, `idArchives`, ...) |
| 403 | `GE0114` | tanpa `List Archive` |

## 2. GET `document-archive/opnames/{id}` (ubah, milik 03)

Bentuk response = kontrak 03 §4, ditambah:
```json
{ "result_counts": { "all": 260, "scanned": 61, "verified": 58, "not_found": 2, "invalid": 1, "unscanned": 202 },
  "is_partial": true }
```
- Permission `List Archive` (sudah dipasang 03, gate1 O-8). Item ini memperluas visibilitas: di 03 sesi terkonfirmasi hanya
  untuk pemilik + superadmin 1/2.
- Sesi terkonfirmasi: harus sesi terlihat (pembuatnya selalu), selain itu 404 `ARCHIVE412`. Draft dan sesi batal: tetap
  hanya pemiliknya (03); milik user lain 404.
- `result_counts` = jumlah baris `GET opnames/{id}/documents` per nilai `result` **yang boleh dilihat pemanggil**
  (spec K-5 b), selalu dihitung dari baris snapshot (`all` = `scanned` + `unscanned`). `is_partial` = ada baris yang
  disembunyikan. Pemanggil yang melihat seluruh cakupan (superadmin 1/2, atau semua baris sesi lolos filter §3):
  `is_partial=false` dan `result_counts` = `counts` 03, kecuali `unscanned`/`all` bisa lebih kecil bila beberapa dokumen
  bernama sama dicatat satu baris saat Confirm (UNIQUE code 03) - angka itu mengikuti baris tabel. Draft (pemilik):
  `result_counts` dari `counts` draft, `is_partial=false`.
- `counts` (kartu) tetap angka rekaman.

| HTTP | Kode | Arti |
|---|---|---|
| 404 | `ARCHIVE412` | tidak ada, draft user lain, sesi batal, atau sesi terkonfirmasi tidak terlihat |
| 403 | `GE0114` | tanpa `List Archive` |

## 3. GET `document-archive/opnames/{id}/documents` (ubah, milik 03)

Query & bentuk = kontrak 03 §6 (`result` = `all|scanned|verified|not_found|invalid|unscanned`, `page`, `pagination`).
- Permission `List Archive` (dari 03); cek akses sama dengan §2 (404 `ARCHIVE412`).
- Sesi terkonfirmasi, pemanggil bukan superadmin 1/2 (termasuk pembuat sesi): baris dokumen hanya bila dokumennya lolos
  scope lokasi 01 (`Archive::scopeVisibleToUser`) **dan** `id_archive_folder` null atau dalam `viewableFolderIds()` 02;
  baris Invalid (`id_archive` null) selalu tampil. Baris Not found selalu `id_archive_folder` null (snapshot 03), jadi
  hanya disaring scope lokasi dokumennya. `total` paginator = `result_counts[result]` §2.

## 4. Display setting `documentArchiveOpnameHistory` (baru)

`Modules/V5/Config/displayColumn/documentArchiveOpnameHistory.php`, didaftarkan di `Config/config.php` (`displayColumn`).
`available_columns` = 7 kolom aktif + 2 kolom tambahan; `available_queries` = `current_queries`.

```php
'module_name' => 'documentArchiveOpnameHistory',
'current_columns' => [
  ['title' => ['en' => 'Time', 'id' => 'Waktu'], 'type' => 'dateTime', 'sort' => true, 'data_index' => 'confirmedAt'],
  ['title' => ['en' => 'User', 'id' => 'User'], 'type' => 'string', 'sort' => true, 'data_index' => 'createdBy'],
  ['title' => ['en' => 'Scope', 'id' => 'Scope'], 'type' => 'string', 'sort' => false, 'data_index' => 'scope'],
  ['title' => ['en' => 'Total Documents', 'id' => 'Total Dokumen'], 'type' => 'number', 'sort' => true, 'data_index' => 'totalDocuments'],
  ['title' => ['en' => 'Verified', 'id' => 'Verified'], 'type' => 'number', 'sort' => true, 'data_index' => 'verifiedCount'],
  ['title' => ['en' => 'Not Found', 'id' => 'Not Found'], 'type' => 'number', 'sort' => true, 'data_index' => 'notFoundCount'],
  ['title' => ['en' => 'Invalid', 'id' => 'Invalid'], 'type' => 'number', 'sort' => true, 'data_index' => 'invalidCount'],
],
// available_columns: 7 di atas + :
//   ['title' => ['en' => 'Scanned', 'id' => 'Discan'], 'type' => 'number', 'sort' => true, 'data_index' => 'scannedCount'],
//   ['title' => ['en' => 'Not Scanned', 'id' => 'Belum Discan'], 'type' => 'number', 'sort' => true, 'data_index' => 'unscannedCount'],
'current_queries' => [
  ['title' => ['en' => 'Opname Time', 'id' => 'Waktu Opname'], 'data_index' => 'confirmedAt', 'type' => 'dateTimeRange',
   'endpoint' => null, 'options' => null],
  ['title' => ['en' => 'User', 'id' => 'User'], 'data_index' => 'createdBy', 'type' => 'select',
   'endpoint' => 'select/document-archive/archive/opname-users', 'options' => null],
  ['title' => ['en' => 'Folder', 'id' => 'Folder'], 'data_index' => 'idArchives', 'type' => 'select',
   'endpoint' => 'select/document-archive/archive/folders', 'options' => null, 'multiple' => true],
],
```
Module baru → baris `column_display_settings` dibuat otomatis pada request pertama (tanpa Updater). FE:
`displaySetting.constant.js` `documentArchiveOpnameHistory: { name: 'documentArchiveOpnameHistory', label: 'archive.Opname History' }`.

## 5. GET `select/document-archive/archive/folders` (baru)

Tanpa `permission_v5` (konvensi select). Query: `search` (string, nama folder mengandung, tanpa beda huruf; bukan path),
`selected_id` (string atau array, tetap ikut walau tak lolos `search`, tetapi hanya bila folder itu dalam scope). Isi:
folder `type=1`, `is_active=1` dalam `viewableFolderIds()` (02; superadmin 1/2 = semua folder aktif), daftar datar, urut
`label`. `label` = path dari root "Induk / … / Folder" (spec K-3 iii; nama induk ditampilkan seperti breadcrumbs folder).
```json
{ "status": "success", "msg_code": "SUCCESS", "result": { "default": null,
  "options": [ { "value": "2502...", "label": "Backup Arsip" }, { "value": "2503...", "label": "Backup Arsip / 2026" } ] } }
```

## 6. GET `select/document-archive/archive/opname-users` (baru)

Tanpa `permission_v5`. Query: `search` (string, username mengandung, tanpa beda huruf), `selected_id` (tetap ikut walau
tak lolos `search`, hanya bila ada di daftar). Isi: `DISTINCT created_by` dari sesi terlihat (sama dengan §1 tanpa filter),
urut username; `value` = `label` = username.
```json
{ "status": "success", "msg_code": "SUCCESS", "result": { "default": null,
  "options": [ { "value": "dias", "label": "dias" }, { "value": "kevinsudjadi", "label": "kevinsudjadi" } ] } }
```

## Endpoint FE (`configuration/endpoints.js`, grup Archive)

```js
getArchiveOpnames: 'document-archive/opnames',
```
`getArchiveOpname` dan `getArchiveOpnameDocuments` sudah ditambahkan item 03. Endpoint select dipakai lewat `endpoint`
di `queries` (tanpa kunci FE).
