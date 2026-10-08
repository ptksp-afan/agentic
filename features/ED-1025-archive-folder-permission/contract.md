# Kontrak API - ED-1025 Folder Permission per folder

Base `api/v5`, header `Authorization: Bearer <token>`, `Accept: application/json`. Semua field response
**snake_case** (FE meng-camelCase lewat interceptor). Group route Archive = `auth:api`. Nama permission module yang
dipakai FE untuk menu (sudah ada, `permission_salesman.sql:67-76`): `List Archive`, `Add Folder`, `View Folder`,
`Update Folder`, `Delete Folder`, `Move Archive`, `Handover Document`, `Receive Document`, `Add Document`. Penambahan
`permission_v5:*` pada route Archive mengikuti item ED-1024.

**(rev 2026-10-08, Gate 1: K-15 b, EPIC K-4 a)** Permission baru **`Folder Permissions`** (persis nama ini; id 1140; label id
"Perizinan Folder"; §7). Route PUT `archives/{id}` berubah ke `permission_v5:Update Folder|Folder Permissions` (§3); route lain tetap.
Permission ini juga dicek di dalam (`access.manage_permission`, PUT §3, show §2). FE: konstanta `permissions.FolderPermissions` =
`'Folder Permissions'` (FE memakai nama, bukan id); ED-1029 memakai nama yang sama untuk halaman by User dan item "+".

## Objek bersama

`access` - hak efektif user yang memanggil atas satu folder (sudah memperhitungkan bypass superadmin,
pembuat folder, folder induk):
```json
{ "view": true, "update": false, "delete": false, "store": true, "manage_permission": false }
```
**(rev)** Aturan (spec BR-4): `view` = setiap folder ber-Izin On di jalur root→folder memberi View (induk dominan hanya untuk View).
`update`/`delete`/`store` = `view` **dan** folder itu sendiri memberi hak itu; folder Izin Off = tidak dibatasi (spec K-12 a). Hak yang
sama di folder induk tidak dibutuhkan.
`manage_permission` **(rev)** = role pemanggil memegang `Folder Permissions` (spec BR-7, K-15 b); nilainya sama di semua folder,
tidak bergantung `view`/`update` (dulu = `update`).

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
        "access": { "view": false, "update": false, "delete": false, "store": false, "manage_permission": true } },
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
- Contoh di atas = pemanggil ber-`Folder Permissions` (**(rev)** `manage_permission` true juga di folder tanpa View).
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
**(rev)** Pemanggil tanpa `Folder Permissions`: `folder_permissions` = `[]` dan `access.manage_permission` = `false` (bentuk sama).
Folder tanpa View tetap 403 `ARCHIVE407` walau pemanggil ber-`Folder Permissions` (izinnya diatur lewat §3 atau ED-1029).

| HTTP | Kode | Arti |
|---|---|---|
| 403 | `ARCHIVE407` | folder tanpa View efektif (kode 01; body `ErrorMessageException`: `message`, `code`, `parameter` = `[<nama folder penolak>]`) |
| 404 | `ARCHIVE400` | tidak ditemukan (status diperbaiki item 01) |

## 3. PUT `document-archive/archives/{id}` (ubah)

**(rev)** Route `permission_v5:Update Folder|Folder Permissions` (cukup salah satu; tanpa keduanya → 403 `GE0114`, `parameter`
`"Update Folder, Folder Permissions"`). Body terdiri dari dua kelompok yang dinilai terpisah (spec K-15 b); boleh salah satu atau keduanya:

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
| Kelompok / field | Aturan |
|---|---|
| **detail**: `name`, `description`, `id_archive_parent`, `is_all_location`, `id_locations` | **(rev)** dikirim bila body memuat salah satu key ini; maka aturan lama berlaku (`name`, `is_all_location` wajib; `UpdateRequest`). Butuh role `Update Folder` + Update efektif di folder; induk baru butuh Store |
| **izin**: `is_folder_permission` | opsional, `in:0,1`; tidak dikirim = tidak berubah |
| **izin**: `folder_permissions` | opsional, array; **dikirim = mengganti semua baris folder ini**; baris dengan keempat flag 0 tidak disimpan |
| `folder_permissions.*.id_user` | wajib, `exists:users,id_user`, tidak boleh ganda |
| `folder_permissions.*.is_view/is_update/is_delete/is_store` | wajib, `in:0,1` |
| kelompok izin (hak) | **(rev)** butuh role `Folder Permissions` saja; **tidak** dicek hak folder (View/Update) - folder tanpa View pun boleh, asal dalam scope lokasi |
| tanpa kedua kelompok | **(rev)** 422 (`name` dan `is_all_location` wajib); tanpa cek hak folder (body kosong = 422, bukan 403) |

FE **(rev)**: tanpa Update (permission `Update Folder` + `access.update`) tidak mengirim kelompok detail; tanpa `Folder Permissions` tidak
mengirim kelompok izin. Drawer dengan Detail read-only tetap bisa menyimpan tab Izin.

200 `{ "status": "success", "msg_code": "ARCHIVE207", "result": { "id_archive": "C" } }`. Riwayat **(rev)**: aksi `update` hanya bila
kelompok detail dikirim; aksi `permission` bila flag atau baris berubah. Kelompok izin saja tanpa perubahan = 200 tanpa tulisan apa pun
(`updated_by` dan riwayat tetap).

**(rev)** Hak otomatis saat Izin Folder dinyalakan (spec BR-19, K-14 a) dikerjakan FE: body `folder_permissions` sudah memuat
baris user login dengan keempat flag 1; BE menyimpan body apa adanya (tidak ada field atau perilaku BE baru).

Urutan **(rev)**: 404 `ARCHIVE400` → 403 `ARCHIVE407` (folder / induk baru di luar scope lokasi) → [detail] 403 `GE0114` `Update Folder`
→ 403 `ARCHIVE408` → 403 `ARCHIVE410` → [izin] 403 `GE0114` `Folder Permissions` → 422 → 400 `ARCHIVE411`. Ditolak = data tidak berubah.

| HTTP | Kode | Arti |
|---|---|---|
| 403 | `GE0114` | **(rev)** kelompok detail dikirim, role tanpa `Update Folder` (`parameter` `"Update Folder"`); kelompok izin dikirim (nilai apa pun), role tanpa `Folder Permissions` (`parameter` `"Folder Permissions"`, `message` "Anda tidak memiliki izin untuk Folder Permissions!"). Bentuk sama dengan penolakan middleware `permission_v5` |
| 403 | `ARCHIVE408` | kelompok detail dikirim, tanpa Update efektif di folder ini (dicek di `authorize()`, sebelum 422) |
| 403 | `ARCHIVE410` | `id_archive_parent` diganti ke folder tanpa Store efektif |
| 403 | `ARCHIVE407` | folder atau induk baru di luar scope lokasi (item 01; teks generik) |
| 400 | `ARCHIVE411` | baris dengan `is_update`/`is_delete`/`is_store` = 1 tetapi `is_view` = 0 |
| 422 | - | validasi Laravel (`folder_permissions.N.id_user` dll.; tanpa kedua kelompok); juga tipe salah: `name`/`description`/`id_archive_parent`/`folder_permissions.*.id_user` bukan teks, elemen `id_locations` bukan teks, termasuk di daftar >= 2 elemen (fix ronde 1-2, sebelumnya 500) |
| 404 | `ARCHIVE400` | folder tidak ditemukan (status diperbaiki item 01) |

## 4. Endpoint lain yang hanya bertambah penolakan

Body/response sukses tidak berubah. Body error = `ErrorMessageException` (`message`, `code`, `parameter`).
Untuk `ARCHIVE407`-`ARCHIVE410` (juga di §2-§3), `parameter` = `[<nama folder penolak>]` dan `[0]` di pesan = nama itu.
**(rev)** Folder penolak: folder ber-Izin On teratas di jalur root -> folder yang tidak memberi View (bisa induk); bila View lolos,
folder itu sendiri (On) yang tidak memberi Update/Delete/Store. Folder Izin Off tidak pernah menolak U/D/S (spec K-12 a).
Dulu: folder teratas yang tidak memberi hak tersebut (K-1 a, digantikan FB-9). Kode per hak tidak berubah.
Contoh: `{"message": "Anda tidak punya permission update di folder <b>Backup Arsip</b>", "code": "ARCHIVE408", "parameter": ["Backup Arsip"]}`.

| Endpoint | Penolakan baru |
|---|---|
| PUT `document-archive/archives/rename/{id}` | 403 `ARCHIVE408`; `name` bukan teks = 422 (sebelumnya 500) |
| DELETE `document-archive/archives/delete/{id}` | 403 `ARCHIVE409` tanpa Delete di folder ini; 403 `ARCHIVE418` (kode 01) ada subfolder aktif turunan tanpa Delete efektif - tidak ada yang terhapus; 400 `ARCHIVE401` (tetap) folder/turunan berisi dokumen aktif |
| GET `document-archive/archives/history/{id}` | 403 `ARCHIVE407` (folder saja; dokumen tidak dicek) |
| POST `document-archive/archives/create-folder` | 403 `ARCHIVE410` bila `id_archive_parent` tanpa Store; `name`/`description`/`id_archive_parent` bukan teks atau elemen `id_locations` bukan teks = 422 (sebelumnya 500, parent array 404) |
| POST `document-archive/documents/put-in` (`id_archive_parent`, `id_archives[]` atau `name`) | 403 `ARCHIVE410` tujuan tanpa Store; 403 `ARCHIVE408` folder yang dipindah tanpa Update (juga saat dipindah ke root); tujuan kosong (root) tidak dicek; dokumen tidak dicek hak folder asalnya |
| POST `documents/hand-over`, `documents/receive` | tidak berubah (K-8) |

Folder baru dari `create-folder` selalu `is_folder_permission` = 0.
**(rev)** Memindahkan folder (put-in atau ganti `id_archive_parent`) = Update di folder itu + Store di tujuan; induk asal tidak
dicek (spec K-13 a). Semua hak di tabel ini dinilai menurut aturan `access` (rev) di atas: di folder Izin Off cukup View efektif.

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

## 7. Permission baru (rev 2026-10-08, EPIC K-4 a)

Seed `app/Sql/data/permission_salesman.sql` (hanya lisensi Salesman Activity). Id 1133-1138 dipakai branch AI, jadi Archive memakai 1139/1140:

| id_permission | id_module | permission_label | indonesian_permission_label | permission_name |
|---|---|---|---|---|
| 1139 (dulu 1133, ED-1026) | 1266 (Archive) | Opname Document | Opname Dokumen | `Opname Document` |
| 1140 | 1266 (Archive) | Folder Permissions | Perizinan Folder | `Folder Permissions` |

`Opname Document`: semua rujukan 1133 di berkas (`:82`, checks `:138,147`, unchecks `:216,227`) menjadi 1139, pasangan tidak berubah.
`permission_checks` (centang X ikut mencentang Y): `(1081,1140)`, `(1140,1082)`, `(1140,1084)`.
`permission_unchecks` (lepas X ikut melepas Y): `(1140,1081)`, `(1081,1140)`, `(1082,1140)`, `(1084,1140)`.
Tanpa pasangan dengan `Update Folder` (1085): `Folder Permissions` berdiri sendiri (spec K-15 b). Role 1/2/3 mendapatnya lewat
`role_permissions.sql` saat seed ulang; role lain diberi ulang. Tidak ada kode pesan baru (`GE0114` dipakai ulang).
