# Kontrak API - ED-1029 Set Permission by User

Base `api/v5`, header `Authorization: Bearer <token>`, `Accept: application/json`. Response **snake_case**
(FE meng-camelCase lewat interceptor). Berkas route `Routes/DocumentArchive/api.php`, grup
`document-archive`, `auth:api`.

**Permission (rev 2026-10-08, FB-4b + ED-1025 K-15 b)**: **`Folder Permissions`** (nama persis, label id "Perizinan Folder";
id 1140 hanya di seed ED-1025 `permission_salesman.sql`, EPIC K-4 a - FE/BE memakai nama) pada **kedua** route:
`->middleware('permission_v5:Folder Permissions')`, menggantikan `Update Folder` (K-1 a lama). Permission ini **satu-satunya**
syarat mengelola: tanpa `Update Folder` dan tanpa hak View/Update di folder pun boleh, untuk semua folder dalam scope lokasi user
login. FE: konstanta `permissions.FolderPermissions` = `'Folder Permissions'` untuk item "+" Set Permission by User,
`PrivateRoute` + guard lokal halaman. Middleware tidak mem-bypass superadmin: role 1/2/3 mendapat permission ini lewat seed
ulang (ED-1025).

Objek `access` = sama dengan kontrak ED-1025 (hak efektif **user yang login** atas satu folder):
`{ "view": bool, "update": bool, "delete": bool, "store": bool, "manage_permission": bool }`.
**(rev)** Aturan ED-1025 BR-4 (K-12 a): `view` = setiap folder Izin On di jalur root→folder memberi View; `update`/`delete`/`store`
= `view` **dan** folder itu sendiri memberi hak itu; folder Izin Off = tidak dibatasi (hak induk tidak dibutuhkan).
`manage_permission` = role pemanggil memegang `Folder Permissions` (ED-1025 K-15 b), nilainya sama di semua folder; di endpoint
ini permission itu selalu ada (route), jadi `manage_permission` = **true di semua node**. `view`…`store` hanya informasi; FE
memakai `manage_permission` + `is_folder_permission`.

## 1. GET `document-archive/user-permissions/{id}` (ubah rev: permission, aturan `access`; bentuk node tetap)

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
        "access": { "view": false, "update": false, "delete": false, "store": false, "manage_permission": true }
      }
    ]
  }
}
```
| Field | Arti |
|---|---|
| `data[]` | folder aktif (`type`=1, `is_active`>0) dalam scope lokasi user login (superadmin 1/2 semua), sebagai pohon; urut `name` per level. Folder yang induknya di luar scope ada di level teratas (`id_archive_parent` tetap berisi id aslinya) |
| `children` | hanya ada bila folder punya subfolder yang tampil |
| `is_folder_permission` | 0/1 flag folder (kolom Permission On/Off, read-only) |
| `is_view` … `is_store` | 0/1 baris `archive_permissions` (folder, user target) **tersimpan**; tanpa baris = 0. Tetap dikirim untuk folder Off |
| `access` | hak user login (aturan rev di atas); sel bisa diubah bila `is_folder_permission` = 1 **dan** `access.manage_permission` (= true di semua node → praktis: semua folder On). Contoh rev: folder K tanpa View pemanggil → `view` false, `manage_permission` true, sel K bisa diubah |

**(rev, K-8 c)** Tidak ada field `created_by` atau field baru lain di node; kunci node = tabel di atas (+ `children`).

| HTTP | Kode | Arti |
|---|---|---|
| 404 | `ARCHIVE440` | user target tidak ada / `is_active` ≠ 1 |
| 403 | `GE0114` | **(rev)** role tanpa `Folder Permissions`; `parameter` = `"Folder Permissions"`; dicek middleware, mendahului 404 |

Folder tanpa View pemanggil tetap ada di `data` (tidak 403); `ARCHIVE407`/`ARCHIVE408` tidak dikirim endpoint ini.

## 2. PUT `document-archive/user-permissions/{id}` (ubah rev: permission, tanpa 408)

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

User target hanya dicek ada & aktif (`ARCHIVE440`); tidak ada validasi lain (D-3, developer 2026-10-08).

Proses (satu transaksi; satu baris gagal = tidak ada yang tersimpan): per baris, baris (folder, user target)
diganti; keempat flag 0 = baris dihapus; folder lain dan user lain tidak tersentuh. Folder yang barisnya benar-benar
berubah mendapat entri riwayat `permission` ("Permission changed by …" / "Permission diubah oleh …").
Simpan bersamaan untuk folder yang sama (klik Save ganda, dua admin, atau bersama tab Izin `PUT archives/{id}`)
berjalan bergiliran lewat `lockFolders`: semua 200, hasil akhir = kiriman yang diproses terakhir, riwayat hanya untuk
perubahan nyata (fix D-2 QA; dipertahankan D-4).

200 `{ "status": "success", "msg_code": "ARCHIVE207", "message": "Successfully updated", "result": { "id_user": "U3" } }`

| HTTP | Kode | Arti | Dicek di |
|---|---|---|---|
| 403 | `GE0114` | **(rev)** role tanpa `Folder Permissions` (`parameter` `"Folder Permissions"`) | middleware (paling awal); `authorize()` (sebelum 440) + `assertCanManage` mengulang cek yang sama (cadangan, bentuk sama; berpengaruh hanya untuk user customer yang di-bypass middleware) |
| 404 | `ARCHIVE440` | user target tidak ada / nonaktif | `authorize()` (sebelum 422) + service |
| 403 | `ARCHIVE407` | folder aktif di luar scope lokasi user login (teks generik, tanpa `parameter`) | `authorize()` (sebelum 422) + service |
| 404 | `ARCHIVE400` | `id_archive` tidak ada, bukan folder, atau tidak aktif | service |
| 400 | `ARCHIVE441` | folder permission folder itu nonaktif (`is_folder_permission` = 0); `parameter` = nama folder | service (sesudah 400/407) |
| 400 | `ARCHIVE411` | `is_update`/`is_delete`/`is_store` = 1 dengan `is_view` = 0 | service |
| 422 | - | validasi Laravel (`permissions.N.id_archive` ganda, flag kosong/bukan 0/1) | FormRequest |

**(rev)** `403 ARCHIVE408` **tidak lagi** dikirim endpoint ini (ED-1025 K-15 b): hak View/Update pemanggil di folder atau induknya
tidak dicek; folder Izin Off (juga di bawah induk On, K-12 a) = 400 `ARCHIVE441`. Urutan: 403 `GE0114` → 404 `ARCHIVE440` → 403
`ARCHIVE407` → 422 → 404 `ARCHIVE400` → 400 `ARCHIVE441` → 400 `ARCHIVE411`. Ditolak = data tidak berubah.

Body error = `ErrorMessageException` (`message`, `code`, `parameter`); FE menampilkan toast standar
(`showMessage: true`), tidak bercabang pada kode.

## 3. GET `select/document-archive/archive/users` (dipakai ulang dari ED-1025)

Sesuai kontrak ED-1025 §5 (`search`, `excepts[]`, `selected_id`; `{default, options:[{value: id_user, label: username}]}`).
Halaman ini tidak mengirim `excepts`. User login non-superadmin bisa muncul di daftar dan memilih dirinya sendiri; Save tanpa
modal konfirmasi (spec K-8 c).

## 4. Kode pesan (`app/Lib/lang/en_EN.php` + `id_ID.php`, blok ARCHIVE) - tidak ada yang baru di revisi

| Kode | HTTP | en | id |
|---|---|---|---|
| `ARCHIVE440` | 404 | User isn't found | User tidak ditemukan |
| `ARCHIVE441` | 400 | Folder permission is not enabled on folder <b>[0]</b> | Folder permission belum aktif di folder <b>[0]</b> |

Dipakai ulang: `ARCHIVE200`, `ARCHIVE207`, `ARCHIVE400`, `ARCHIVE407`, `ARCHIVE411`, `GE0114`. **(rev)** `ARCHIVE408` tidak lagi.

## 5. FE (ringkas)

| Kunci `configuration/endpoints.js` | Nilai |
|---|---|
| `getArchiveUserPermission` | `document-archive/user-permissions/:id` |
| `putArchiveUserPermission` | `document-archive/user-permissions/:id` |
| `getSelectArchiveUsers` | `select/document-archive/archive/users` (dari ED-1025) |

Route FE: `paths.archivePermission` = `/archives/permissions`, **(rev)** `PrivateRoute permission={permissions.FolderPermissions}` +
guard lokal halaman dengan konstanta yang sama; item "+" `set-permission` dengan konstanta yang sama.
