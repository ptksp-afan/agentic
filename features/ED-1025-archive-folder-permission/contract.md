# Kontrak API - ED-1025 Folder Permission per folder

Base `api/v5`, header `Authorization: Bearer <token>`, `Accept: application/json`. Semua field response
**snake_case** (FE meng-camelCase lewat interceptor). Group route Archive = `auth:api`; **tidak ada
permission baru**. Nama permission module yang dipakai FE untuk menu (sudah ada, `permission_salesman.sql:67-76`):
`List Archive`, `Add Folder`, `View Folder`, `Update Folder`, `Delete Folder`, `Move Archive`,
`Handover Document`, `Receive Document`, `Add Document`. Penambahan `permission_v5:*` pada route Archive
mengikuti item ED-1024.

## Objek bersama

`access` - hak efektif user yang memanggil atas satu folder (sudah memperhitungkan bypass superadmin,
pembuat folder, folder induk):
```json
{ "view": true, "update": false, "delete": false, "store": true, "manage_permission": false }
```
`manage_permission` = boleh mengubah toggle & tabel Permission (K-4 a: sama dengan `update`).

`folder_permission_row`:
```json
{ "id_user": "USR...", "username": "lydia", "is_view": 1, "is_update": 0, "is_delete": 0, "is_store": 1 }
```

## 1. GET `document-archive/archives` (ubah)

Query (tidak berubah): `page`, `pagination`, `id_archive` (folder yang dibuka; kosong = root),
`search` (string JSON; key tetap: `query`, `type`, `showRelatedTransaction`, plus `data_index` dari
`queries` - camelCase, tidak dikonversi), `sort`/`sorts`.

200 - paginator Laravel + `columns`/`queries` + `breadcrumbs` (seperti sekarang), ditambah:
```json
{
  "status": "success", "msg_code": "ARCHIVE200", "message": "Document is found",
  "result": {
    "current_page": 1, "data": [
      { "id_archive": "A1", "name": [{"id_transaction": null, "transaction_no": "SMLSMG - BRANGKAS", "transaction_type": null}],
        "type": "Folder", "status": 1, "breadcrumbs": [], "history": "Created by kevinsudjadi",
        "is_folder_permission": 1,
        "access": { "view": false, "update": false, "delete": false, "store": false, "manage_permission": false } },
      { "id_archive": "D1", "type": "Delivery Order", "document": { "...": "..." },
        "is_folder_permission": 0, "access": null }
    ],
    "per_page": 10, "total": 2, "...": "...",
    "columns": [], "queries": [], "breadcrumbs": [{ "value": "P", "label": "Backup Arsip" }],
    "access": { "view": true, "update": true, "delete": false, "store": true, "manage_permission": true }
  }
}
```
- `data[].is_folder_permission`: 0/1 (dokumen selalu 0). `data[].access`: objek untuk folder, `null` untuk dokumen.
- `result.access`: hak atas folder `id_archive` yang dibuka; `null` di root atau saat mencari.
- Saat mencari (`search` berisi key selain `showRelatedTransaction`), dokumen di folder tanpa View tetap ikut.

403 - membuka folder (`id_archive` terisi, tidak mencari) tanpa View efektif. Bentuk `Message::formatResponse`
(FE bercabang pada `msg_code`, tanpa toast):
```json
{
  "status": "fail", "msg_code": "ARCHIVE407",
  "message": "Anda tidak punya akses ke folder atau dokumen ini",
  "result": {
    "id_archive": "C", "name": "SMLSMG - BRANGKAS",
    "denied_by": { "id_archive": "P", "name": "Backup Arsip" },
    "breadcrumbs": [{ "value": "P", "label": "Backup Arsip" }, { "value": "C", "label": "SMLSMG - BRANGKAS" }]
  }
}
```
`denied_by` = folder aktif teratas di jalur yang tidak memberi View (bisa folder itu sendiri). `ARCHIVE407` = kode 01
(teks generik); nama folder penolak hanya di `result.denied_by`.

Error lain di endpoint ini **tidak** berubah bentuk (bentuk `ErrorMessageException`: `message`, `code`): 404 `ARCHIVE400`
dan 403 `ARCHIVE407` karena folder di luar scope lokasi (item 01). Jadi `msg_code` + `result.denied_by` hanya ada bila
penolakannya hak folder (View).

## 2. GET `document-archive/archives/{id}` (ubah)

200 `result` = field lama (`id_archive`, `id_archive_parent`, `name`, `description`, `type`, `status`,
`breadcrumbs`, `is_all_location`, `id_locations` bila Several, `is_active`, `created_by`, `created_at`,
`updated_by`, `updated_at`) ditambah:
```json
{ "is_folder_permission": 1,
  "folder_permissions": [ { "id_user": "U2", "username": "dias", "is_view": 1, "is_update": 0, "is_delete": 0, "is_store": 0 } ],
  "access": { "view": true, "update": true, "delete": true, "store": true, "manage_permission": true } }
```
`folder_permissions` urut `username`; tetap dikirim walau toggle 0 (K-10 ii). Bila `{id}` sebuah dokumen: hak folder
tidak dicek, `is_folder_permission` = 0, `folder_permissions` = `[]`, `access` = `null`.

| HTTP | Kode | Arti |
|---|---|---|
| 403 | `ARCHIVE407` | folder tanpa View efektif (kode 01; body `ErrorMessageException`: `message`, `code`, `parameter` = `[<nama folder penolak>]`) |
| 404 | `ARCHIVE400` | tidak ditemukan (status diperbaiki item 01) |

## 3. PUT `document-archive/archives/{id}` (ubah)

Body (snake_case di level terluar; FE boleh kirim camelCase):
```json
{
  "name": "SMLSMG - BRANGKAS", "description": null, "id_archive_parent": "P",
  "is_all_location": 0, "id_locations": ["LOC1"],
  "is_folder_permission": 1,
  "folder_permissions": [
    { "id_user": "U1", "is_view": 1, "is_update": 1, "is_delete": 1, "is_store": 1 },
    { "id_user": "U2", "is_view": 1, "is_update": 0, "is_delete": 0, "is_store": 0 }
  ]
}
```
| Field | Aturan |
|---|---|
| `name`, `description`, `id_archive_parent`, `is_all_location`, `id_locations` | tidak berubah (`UpdateRequest`) |
| `is_folder_permission` | opsional, `in:0,1`; tidak dikirim = tidak berubah |
| `folder_permissions` | opsional, array; **dikirim = mengganti semua baris folder ini**; baris dengan keempat flag 0 tidak disimpan |
| `folder_permissions.*.id_user` | wajib, `exists:users,id_user`, tidak boleh ganda |
| `folder_permissions.*.is_view/is_update/is_delete/is_store` | wajib, `in:0,1` |

200 `{ "status": "success", "msg_code": "ARCHIVE207", "result": { "id_archive": "C" } }`. Bila field
permission dikirim dan berubah, riwayat folder mendapat aksi `permission`.

| HTTP | Kode | Arti |
|---|---|---|
| 403 | `ARCHIVE408` | tanpa Update efektif di folder ini (dicek di `authorize()`, sebelum 422) |
| 403 | `ARCHIVE410` | `id_archive_parent` diganti ke folder tanpa Store efektif |
| 400 | `ARCHIVE411` | baris dengan `is_update`/`is_delete`/`is_store` = 1 tetapi `is_view` = 0 |
| 422 | - | validasi Laravel (`folder_permissions.N.id_user` dll.); juga tipe salah: `name`/`description`/`id_archive_parent`/`folder_permissions.*.id_user` bukan teks, elemen `id_locations` bukan teks, termasuk di daftar >= 2 elemen (fix ronde 1-2, sebelumnya 500) |
| 404 | `ARCHIVE400` | folder tidak ditemukan (status diperbaiki item 01) |

## 4. Endpoint lain yang hanya bertambah penolakan

Body/response sukses tidak berubah. Body error = `ErrorMessageException` (`message`, `code`, `parameter`).
Untuk `ARCHIVE407`-`ARCHIVE410` (juga di §2-§3), `parameter` = `[<nama folder penolak>]` dan `[0]` di pesan = nama itu:
folder aktif-permission teratas di jalur root -> folder yang tidak memberi hak tersebut, jadi bisa folder induk (K-1 a).
Contoh: `{"message": "Anda tidak punya permission update di folder <b>Backup Arsip</b>", "code": "ARCHIVE408", "parameter": ["Backup Arsip"]}`.

| Endpoint | Penolakan baru |
|---|---|
| PUT `document-archive/archives/rename/{id}` | 403 `ARCHIVE408`; `name` bukan teks = 422 (sebelumnya 500) |
| DELETE `document-archive/archives/delete/{id}` | 403 `ARCHIVE409` tanpa Delete di folder ini; 403 `ARCHIVE418` (kode 01) ada subfolder aktif turunan tanpa Delete efektif - tidak ada yang terhapus |
| GET `document-archive/archives/history/{id}` | 403 `ARCHIVE407` (folder saja; dokumen tidak dicek) |
| POST `document-archive/archives/create-folder` | 403 `ARCHIVE410` bila `id_archive_parent` tanpa Store; `name`/`description`/`id_archive_parent` bukan teks atau elemen `id_locations` bukan teks = 422 (sebelumnya 500, parent array 404) |
| POST `document-archive/documents/put-in` (`id_archive_parent`, `id_archives[]` atau `name`) | 403 `ARCHIVE410` tujuan tanpa Store; 403 `ARCHIVE408` folder yang dipindah tanpa Update (juga saat dipindah ke root); tujuan kosong (root) tidak dicek; dokumen tidak dicek hak folder asalnya |
| POST `documents/hand-over`, `documents/receive` | tidak berubah (K-8) |

Folder baru dari `create-folder` selalu `is_folder_permission` = 0.

## 5. GET `select/document-archive/archive/users` (baru)

Tanpa `permission_v5` (konvensi select). Query: `search` (string, cari `username` like), `excepts[]`
(id_user yang disembunyikan, mis. user yang sudah ada di tabel), `selected_id` (tetap ikut walau tak lolos filter).
`excepts` dan `selected_id` boleh satu nilai atau array; `search`/elemen bukan teks -> 422.
Isi: user `is_active` = 1, punya role aktif dengan permission `List Archive`, tidak punya role
`is_superadmin` 1/2, bukan user customer; urut `username`.
```json
{ "status": "success", "msg_code": "SUCCESS", "result": { "default": null,
  "options": [ { "value": "U2", "label": "dias" }, { "value": "U3", "label": "lydia" } ] } }
```

## 6. Kode pesan baru (`app/Lib/lang/en_EN.php` + `id_ID.php`)

Nomor final (gate1 §5). `ARCHIVE407` (akses ditolak, teks generik) dan `ARCHIVE418` (hapus ditolak karena subfolder)
milik item 01, dipakai ulang di sini.

| Kode | en | id |
|---|---|---|
| `ARCHIVE408` | You do not have update permission on folder <b>[0]</b> | Anda tidak punya permission update di folder <b>[0]</b> |
| `ARCHIVE409` | You do not have delete permission on folder <b>[0]</b> | Anda tidak punya permission delete di folder <b>[0]</b> |
| `ARCHIVE410` | You do not have store permission on folder <b>[0]</b> | Anda tidak punya permission store di folder <b>[0]</b> |
| `ARCHIVE411` | Update, Delete and Store permission require View permission | Permission Update, Delete, dan Store membutuhkan permission View |

Label riwayat baru (`DocumentArchiveService::action`): `permission` → en "Permission changed by
<strong>{relatedUsername}</strong>", id "Permission diubah oleh <strong>{relatedUsername}</strong>".
