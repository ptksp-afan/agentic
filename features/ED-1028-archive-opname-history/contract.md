# Kontrak API - ED-1028 Opname History (revisi 2026-10-08, jawaban Gate 1 ronde 2 diterapkan)

Base `api/v5`, header `Authorization: Bearer <token>`, `Accept: application/json`. Response **snake_case** (FE
meng-camelCase lewat interceptor). Body/param terluar boleh camelCase (`req_snake`); isi JSON-string `search` **tidak**
dikonversi: kunci persis seperti tabel di bawah (camelCase).
Error `ErrorMessageException`: `{ "message", "code", ["parameter"] }` dengan status di tabel; validasi = 422 Laravel.

**Permission (rev, spec K-8 a = EPIC K-3 a, Gate 1 2026-10-08):** §1-§3 = **`Opname Document`** (id **1139**, EPIC K-4 a; seed
`permission_salesman.sql` diubah ED-1025, hanya lisensi Salesman Activity; `permission_checks (1139,1082)` → pemegangnya juga punya
`List Archive`). Tanpa permission → 403 `GE0114`, `parameter` = `"Opname Document"`, dicek **pertama** (middleware route, sebelum
semua kode lain di tabel). Menggantikan `List Archive` (Gate 1 K-6 a, dibalik). Tidak ada permission & kode pesan baru.

"Sesi terlihat" = sesi `archive_opnames.status = 2` yang lolos aturan 04 K-4 untuk pemanggil (superadmin `is_superadmin` 1/2 =
semua), termasuk EPIC K-1 a: sesi dari root terlihat semua pemegang permission; sesi yang semua foldernya kini
terhapus/tak terlihat hanya untuk superadmin 1/2 dan pembuat sesi. "Mencakup folder X" = 04 K-3 (X atau subfoldernya).
(rev) Sesudah ED-1026 FB-3 tidak ada lagi sesi draft/batal di DB; id lama sesi seperti itu = tidak ada (404).

"Folder terlihat" = folder aktif dalam scope lokasi 01 dengan View efektif 02 (`VerificationService::context()['folders']`).

## Objek bersama

`history_row` (baris list; kunci kolom hanya ada bila kolomnya aktif di display setting, kecuali `id_archive_opname`,
`id_archive`, `scope` yang selalu ada):
```json
{
  "id_archive_opname": "2610...",
  "id_archive": "2502...",
  "confirmed_at": "2026-08-12 14:02:11",
  "created_by": "kevinsudjadi",
  "scope": { "is_root": false, "folder_name": "Backup", "subfolder_count": 4 },
  "total_documents": 3400,
  "verified_count": 126,
  "not_found_count": 8,
  "invalid_count": 3,
  "scanned_count": 137,
  "unscanned_count": 3274
}
```
- `id_archive` = folder tempat opname dijalankan, `null` = root.
- `scope` = objek **sama persis** dengan `scope` `GET opnames/{id}`, dibentuk `OpnameService::scope()` milik ED-1026. (rev, spec
  K-10 b) Tepat tiga kunci: `is_root` (bool), `folder_name` (string\|null; null = root), `subfolder_count` (int, subfolder yang
  dicentang di Step 1). `first_name`/`other_count` **dihapus** (nama subfolder tidak dikirim; perubahan dikerjakan ED-1026). FE: sel
  Scope = `getScopeTag()` ED-1026 → "<folder_name / All Archive · root> + N subfolder" (id root "Semua Arsip · root"; tanpa akhiran
  bila N = 0).
- Angka = nilai tersimpan saat Confirm (tidak dihitung ulang).

## 1. GET `document-archive/opnames` - daftar sesi Opname History

Permission (rev) `Opname Document`. Controller `OpnameController@index`.

| Param | Tipe | Arti |
|---|---|---|
| `page` | int | halaman |
| `pagination` | int | ukuran halaman, bawaan `AppConfig::getPagination()` |
| `sort` / `sorts` | array `{sortBy, sortType}` | elemen JSON string (`sorts[]={"sortBy":…}`) atau objek. `sortBy` ∈ `confirmedAt`, `createdBy`, `totalDocuments`, `verifiedCount`, `notFoundCount`, `invalidCount`, `scannedCount`, `unscannedCount` (juga bila kolomnya tidak aktif); lain (mis. `scope`) diabaikan tanpa error. `sortType` `asc`, selain itu `desc`. Tanpa sort: `confirmed_at desc`; pemecah seri selalu `confirmed_at desc, id_archive_opname desc` (= urutan `last_sessions`) |
| `id_archive` | string, opsional | konteks folder: hanya sesi yang mencakup folder ini |
| `search` | JSON string | kunci di bawah; kosong/`null` = tanpa filter |

Kunci `search` (camelCase persis):

| Kunci | Tipe | Arti |
|---|---|---|
| `query` | string | mengandung (tanpa beda huruf; di-trim; `%`/`_` harfiah) pada `created_by` **atau** `archive_opname_folders.name` (level 0-2) sesi itu; non-superadmin hanya folder terlihat. Teks di luar latin1 = tanpa hasil (200), bukan error |
| `confirmedAt` | `[string\|null, string\|null]` | `Y-m-d H:i:s`; `[awal, akhir]` inklusif pada `confirmed_at`; salah satu boleh `null`/kosong |
| `createdBy` | string | username persis (nilai dari select opname-users); kosong = tanpa filter |
| `idArchives` | string[] | sesi yang mencakup **salah satu** folder ini; id di luar scope tidak mencocokkan apa pun; `[]` = tanpa filter |

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
| 400 | `ARCHIVE417` | `id_archive` adalah dokumen, bukan folder |
| 403 | `ARCHIVE407` | `id_archive` di luar scope lokasi (01) atau tanpa View efektif (02) |
| 422 | (validasi) | `search` bukan JSON objek (termasuk list non-kosong, mis. `[1,2]`; `[]` = tanpa filter seperti `{}`); `confirmedAt` bukan array 2 elemen / tanggal bukan `Y-m-d H:i:s`; `idArchives` bukan array string; `createdBy` bukan string. Kunci error = kunci `search` (`confirmedAt.0`, `idArchives`, ...) |
| 403 | `GE0114` | (rev) tanpa `Opname Document` (dicek pertama) |

## 2. GET `document-archive/opnames/{id}` - ringkasan sesi (milik ED-1026 §6, aturan baca milik item ini)

Bentuk = kontrak ED-1026 §6, ditambah:
```json
{ "result_counts": { "all": 260, "scanned": 61, "verified": 58, "not_found": 2, "invalid": 1, "unscanned": 202 },
  "is_partial": true }
```
- Permission (rev) `Opname Document`. Sesi wajib terkonfirmasi dan terlihat (pembuatnya selalu), selain itu 404 `ARCHIVE412`.
  (rev) Cabang draft/batal (dibaca pemilik) dihapus ED-1026.
- `result_counts` = jumlah baris `GET opnames/{id}/documents` per nilai `result` **yang boleh dilihat pemanggil** (spec K-5 b),
  selalu dari baris snapshot (`all` = `scanned` + `unscanned`). `is_partial` = ada baris dokumen yang disembunyikan. Pemanggil
  yang melihat seluruh cakupan (superadmin 1/2, atau semua baris lolos §3): `is_partial=false`, `result_counts` = `counts`, kecuali
  `unscanned`/`all` bisa lebih kecil bila dokumen bernama sama dicatat satu baris saat Confirm.
- `counts` (kartu) tetap angka rekaman.
- **(rev, D-1) Saringan folder** - pemanggil bukan superadmin 1/2 (termasuk pembuat sesi):
  - `folders[]`: hanya baris yang `id_archive`-nya folder terlihat pemanggil saat ini; baris root (`id_archive` null) selalu ikut;
    `name`/`document_count`/`is_continue`/`is_opnamed_today` tetap nilai snapshot; urutan tetap (`level`, `name`).
  - `warnings[]`: hanya folder level 0-1 yang lolos aturan di atas (root selalu); `unverify_count` = jumlah `unverified_count`
    folder itu + turunan level 2 yang juga lolos; hasil 0 → objek tidak dikirim.
  - `counts`, `result_counts`, `is_partial` tidak terpengaruh saringan folder (folder tersembunyi tanpa dokumen tidak membuat
    `is_partial` true).
  - Superadmin 1/2: semua baris seperti sebelumnya.

| HTTP | Kode | Arti |
|---|---|---|
| 404 | `ARCHIVE412` | tidak ada (termasuk id bekas draft/batal), atau sesi tidak terlihat |
| 403 | `GE0114` | (rev) tanpa `Opname Document` (dicek pertama) |

## 3. GET `document-archive/opnames/{id}/documents` (milik ED-1026 §7, aturan baca milik item ini)

Query & bentuk = kontrak ED-1026 §7 (`result` = `all|scanned|verified|not_found|invalid|unscanned`, `page`, `pagination`).
- Permission (rev) `Opname Document`; cek akses sama dengan §2 (404 `ARCHIVE412`, 403 `GE0114`).
- Pemanggil bukan superadmin 1/2 (termasuk pembuat sesi): baris dokumen hanya bila dokumennya lolos scope lokasi 01
  (`Archive::scopeVisibleToUser`) **dan** `id_archive_folder` null atau folder terlihat; baris Invalid (`id_archive` null) selalu
  tampil. Baris Not found selalu `id_archive_folder` null, jadi hanya disaring scope lokasi dokumennya. `total` paginator =
  `result_counts[result]` §2.

## 4. Display setting `documentArchiveOpnameHistory` (terpasang, tidak berubah)

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
Baris `column_display_settings` dibuat otomatis pada request pertama (tanpa Updater). FE:
`displaySetting.constant.js` `documentArchiveOpnameHistory: { name: 'documentArchiveOpnameHistory', label: 'archive.Opname History' }`.
(rev) Penyaringan tampil per permission: §7.

## 5. GET `select/document-archive/archive/folders` (tidak berubah)

Tanpa `permission_v5` (konvensi select). Query: `search` (string, nama folder mengandung, tanpa beda huruf; bukan path),
`selected_id` (string atau array, tetap ikut walau tak lolos `search`, hanya bila folder itu dalam scope). Isi: folder `type=1`,
`is_active=1` dalam `viewableFolderIds()` (superadmin 1/2 = semua folder aktif), daftar datar, urut `label`. `label` = path dari
root "Induk / … / Folder".
```json
{ "status": "success", "msg_code": "SUCCESS", "result": { "default": null,
  "options": [ { "value": "2502...", "label": "Backup Arsip" }, { "value": "2503...", "label": "Backup Arsip / 2026" } ] } }
```

## 6. GET `select/document-archive/archive/opname-users`

Tanpa `permission_v5`. Query: `search` (string, username mengandung, tanpa beda huruf), `selected_id` (tetap ikut walau tak lolos
`search`, hanya bila ada di daftar). Isi: `DISTINCT created_by` dari sesi terlihat (sama dengan §1 tanpa filter), urut username;
`value` = `label` = username. (rev, K-8 a) Pemanggil tanpa `Opname Document` → 200 `{ "default": null, "options": [] }`.
```json
{ "status": "success", "msg_code": "SUCCESS", "result": { "default": null,
  "options": [ { "value": "dias", "label": "dias" }, { "value": "kevinsudjadi", "label": "kevinsudjadi" } ] } }
```

## 7. (rev) `setting/display-columns` untuk module `documentArchiveOpnameHistory`

Route yang ada (`Routes/Setup/setting.php:61-65`, `ColumnDisplaySettingService`); hanya berlaku bila pemanggil **tanpa**
`Opname Document`. Mekanisme = peta saringan `module_name → permission → [data_index]` milik ED-1027 (contract ED-1027 §4);
item ini menambah satu entri **seluruh modul** `documentArchiveOpnameHistory → Opname Document`. Module lain (termasuk
`documentArchive`, aturan ED-1027) dan pemanggil dengan permission: tidak berubah.

| Route | Perilaku |
|---|---|
| GET `setting/display-columns` | (spec K-9 a) paginator tanpa baris `module_name = documentArchiveOpnameHistory` (`total` ikut berkurang; juga dengan `search`). Berlaku umum untuk modul berentri seluruh modul yang permission-nya tidak dimiliki pemanggil |
| GET `setting/display-columns/documentArchiveOpnameHistory` | (spec K-8 a) 403 `GE0114`, `parameter` `"Opname Document"`; baris tidak dibuat bila belum ada |
| PUT `setting/display-columns/documentArchiveOpnameHistory` | (spec K-8 a) 403 `GE0114`, `parameter` `"Opname Document"`; DB tetap |

## Endpoint FE (`configuration/endpoints.js`, grup Archive)

```js
getArchiveOpnames: 'document-archive/opnames',
```
`getArchiveOpname` dan `getArchiveOpnameDocuments` milik ED-1026. Endpoint select dipakai lewat `endpoint` di `queries`.
(rev) Tidak ada endpoint FE baru; FE tidak memanggil §1-§3 dan §6 tanpa `Opname Document` (spec BR-17).
