# Kontrak API - ED-1029 Set Permission by User

Base `api/v5`, header `Authorization: Bearer <token>`, `Accept: application/json`. Response **snake_case**
(FE meng-camelCase lewat interceptor). Berkas route `Routes/DocumentArchive/api.php`, grup
`document-archive`, `auth:api`.

**Permission** (K-1 a, nama persis seperti di `permission_salesman.sql:71`): `Update Folder` - dipakai FE untuk
menu "+" dan `PrivateRoute`, dan di BE sebagai `permission_v5:Update Folder` pada kedua route bila item
ED-1024 K-4 memasang `permission_v5` di route Archive. Bila K-1 b/c: nama baru `Set Permission by User`
(module 1266). Bila 02 K-4 b: permission baru dari 02.

Objek `access` = sama dengan kontrak 02 (hak efektif **user yang login** atas satu folder):
`{ "view": bool, "update": bool, "delete": bool, "store": bool, "manage_permission": bool }`.

## 1. GET `document-archive/user-permissions/{id}` (baru)

`{id}` = `id_user` target. Tanpa query param (seluruh pohon; saring nama di FE).

200:
```json
{
  "status": "success", "msg_code": "ARCHIVE200", "message": "Document is found",
  "result": {
    "user": { "id_user": "U3", "username": "lydia" },
    "data": [
      {
        "id_archive": "P", "id_archive_parent": null, "name": "Backup Arsip",
        "is_folder_permission": 0,
        "is_view": 0, "is_update": 0, "is_delete": 0, "is_store": 0,
        "access": { "view": true, "update": true, "delete": true, "store": true, "manage_permission": true },
        "children": [
          {
            "id_archive": "S", "id_archive_parent": "P", "name": "SMLSMG",
            "is_folder_permission": 0, "is_view": 0, "is_update": 0, "is_delete": 0, "is_store": 0,
            "access": { "view": true, "update": true, "delete": true, "store": true, "manage_permission": true },
            "children": [
              {
                "id_archive": "B", "id_archive_parent": "S", "name": "SMLSMG - BRANGKAS",
                "is_folder_permission": 1, "is_view": 1, "is_update": 1, "is_delete": 0, "is_store": 1,
                "access": { "view": true, "update": true, "delete": false, "store": true, "manage_permission": true }
              }
            ]
          }
        ]
      },
      {
        "id_archive": "K", "id_archive_parent": null, "name": "PAK IMAN - TRANSAKSI LUNAS",
        "is_folder_permission": 1, "is_view": 0, "is_update": 0, "is_delete": 0, "is_store": 0,
        "access": { "view": false, "update": false, "delete": false, "store": false, "manage_permission": false }
      }
    ]
  }
}
```
| Field | Arti |
|---|---|
| `data[]` | folder aktif (`type`=1, `is_active`>0) dalam scope lokasi user login (item 01; superadmin 1/2 semua), sebagai pohon; urut `name` per level. Folder yang induknya di luar scope ada di level teratas (`id_archive_parent` tetap berisi id aslinya) |
| `children` | hanya ada bila folder punya subfolder yang tampil |
| `is_folder_permission` | 0/1 flag folder (kolom Permission On/Off, read-only) |
| `is_view` … `is_store` | 0/1 baris `archive_permissions` (folder, user target) **tersimpan**; tanpa baris = 0. Tetap dikirim untuk folder Off (02 K-10 ii) |
| `access` | hak user login; sel bisa diubah bila `is_folder_permission` = 1 **dan** `access.manage_permission` |

| HTTP | Kode | Arti |
|---|---|---|
| 404 | `ARCHIVE440` | user target tidak ada / `is_active` ≠ 1 |
| 403 | `GE0114` | tanpa permission route (middleware `permission_v5`, bila dipasang) |

## 2. PUT `document-archive/user-permissions/{id}` (baru)

Body (FE boleh kirim camelCase di level terluar; isi array ikut dinormalisasi `req_snake`):
```json
{
  "permissions": [
    { "id_archive": "B", "is_view": 1, "is_update": 1, "is_delete": 0, "is_store": 1 },
    { "id_archive": "K", "is_view": 0, "is_update": 0, "is_delete": 0, "is_store": 0 }
  ]
}
```
| Field | Aturan |
|---|---|
| `permissions` | `present`, array; hanya folder yang berubah (K-7 a). `[]` = 200 tanpa perubahan |
| `permissions.*.id_archive` | wajib, string, `distinct` |
| `permissions.*.is_view/is_update/is_delete/is_store` | wajib, `in:0,1` |

Proses (satu transaksi; satu baris gagal = tidak ada yang tersimpan): per baris, baris (folder, user target)
diganti; keempat flag 0 = baris dihapus; folder lain dan user lain tidak tersentuh. Folder yang barisnya benar-benar
berubah mendapat entri riwayat `permission` (label 02: "Permission changed by …" / "Permission diubah oleh …").
Simpan bersamaan untuk folder yang sama (klik Save ganda, dua admin, atau bersama tab Permission `PUT archives/{id}`)
berjalan bergiliran: semua 200, hasil akhir = kiriman yang diproses terakhir, riwayat hanya untuk perubahan nyata (fix D-2).

200 `{ "status": "success", "msg_code": "ARCHIVE207", "message": "Successfully updated", "result": { "id_user": "U3" } }`

| HTTP | Kode | Arti | Dicek di |
|---|---|---|---|
| 404 | `ARCHIVE440` | user target tidak ada / nonaktif | `authorize()` (sebelum 422) + service |
| 403 | `ARCHIVE407` | folder aktif di luar scope lokasi user login (teks generik item 01, tanpa `parameter`) | `authorize()` (sebelum 422) + service |
| 403 | `ARCHIVE408` | user login tidak boleh mengelola permission folder itu (`manage_permission` false = tanpa Update efektif, termasuk tanpa View efektif; `parameter` = nama folder penolak, bisa folder induk) | `authorize()` + service |
| 404 | `ARCHIVE400` | `id_archive` tidak ada, bukan folder, atau tidak aktif | service |
| 400 | `ARCHIVE441` | folder permission folder itu nonaktif (`is_folder_permission` = 0) | service |
| 400 | `ARCHIVE411` | `is_update`/`is_delete`/`is_store` = 1 dengan `is_view` = 0 | service |
| 422 | - | validasi Laravel (`permissions.N.id_archive` ganda, flag kosong/bukan 0/1) | FormRequest |
| 403 | `GE0114` | tanpa permission route | middleware |

Body error = `ErrorMessageException` (`message`, `code`, `parameter`); FE menampilkan toast standar
(`showMessage: true`), tidak bercabang pada kode.

## 3. GET `select/document-archive/archive/users` (dipakai ulang dari 02)

Sesuai kontrak 02 §5 (`search`, `excepts[]`, `selected_id`; `{default, options:[{value: id_user, label: username}]}`).
Halaman ini tidak mengirim `excepts`.

## 4. Kode pesan baru (`app/Lib/lang/en_EN.php` + `id_ID.php`, blok ARCHIVE)

| Kode | HTTP | en | id |
|---|---|---|---|
| `ARCHIVE440` | 404 | User isn't found | User tidak ditemukan |
| `ARCHIVE441` | 400 | Folder permission is not enabled on folder <b>[0]</b> | Folder permission belum aktif di folder <b>[0]</b> |

Dipakai ulang: `ARCHIVE200`, `ARCHIVE207`, `ARCHIVE400` (status 404 sesudah perbaikan 01), `ARCHIVE407` (01),
`ARCHIVE408`, `ARCHIVE411` (02); nomor final gate1 §5.

## 5. FE (ringkas)

| Kunci `configuration/endpoints.js` | Nilai |
|---|---|
| `getArchiveUserPermission` | `document-archive/user-permissions/:id` |
| `putArchiveUserPermission` | `document-archive/user-permissions/:id` |
| `getSelectArchiveUsers` | `select/document-archive/archive/users` (dari 02) |

Route FE: `paths.archivePermission` = `/archives/permissions`, `PrivateRoute permission={permissions.UpdateFolder}` (K-1 a).
