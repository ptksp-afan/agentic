# Kontrak API - ED-1029 Set Permission by User

**rev3 (2026-10-12, FB2-3; Gate 1 ronde 3: K-9 a + tanpa baris ganda, K-10 b, EPIC K-5 b):** PUT §2 menerima `folders` (nyala/mati Izin
Folder) + hak otomatis pengaktif; GET §1 tidak berubah.

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
| `is_folder_permission` | 0/1 flag folder tersimpan (kolom Permission). **(rev3)** Diubah lewat §2 `folders`; saklar boleh diubah bila `access.manage_permission` (true di semua node) |
| `is_view` … `is_store` | 0/1 baris `archive_permissions` (folder, user target) **tersimpan**; tanpa baris = 0. Tetap dikirim untuk folder Off |
| `access` | hak user login (aturan rev di atas); sel bisa diubah bila `is_folder_permission` = 1 **dan** `access.manage_permission` (= true di semua node → praktis: semua folder On). Contoh rev: folder K tanpa View pemanggil → `view` false, `manage_permission` true, sel K bisa diubah |

**(rev, K-8 c)** Tidak ada field `created_by` atau field baru lain di node; kunci node = tabel di atas (+ `children`).

| HTTP | Kode | Arti |
|---|---|---|
| 404 | `ARCHIVE440` | user target tidak ada / `is_active` ≠ 1 |
| 403 | `GE0114` | **(rev)** role tanpa `Folder Permissions`; `parameter` = `"Folder Permissions"`; dicek middleware, mendahului 404 |

Folder tanpa View pemanggil tetap ada di `data` (tidak 403); `ARCHIVE407`/`ARCHIVE408` tidak dikirim endpoint ini.

## 2. PUT `document-archive/user-permissions/{id}` (ubah rev: permission, tanpa 408; **ubah rev3: `folders`**)

Body (FE boleh kirim camelCase di level terluar; isi array ikut dinormalisasi `req_snake`):
```json
{
  "permissions": [
    { "id_archive": "B", "is_view": 1, "is_update": 1, "is_delete": 0, "is_store": 1 },
    { "id_archive": "K", "is_view": 0, "is_update": 0, "is_delete": 0, "is_store": 0 },
    { "id_archive": "F", "is_view": 1, "is_update": 0, "is_delete": 0, "is_store": 0 }
  ],
  "folders": [
    { "id_archive": "F", "is_folder_permission": 1 },
    { "id_archive": "P", "is_folder_permission": 0 }
  ]
}
```
| Field | Aturan |
|---|---|
| `permissions` | `present`, array; hanya folder yang haknya berubah (K-7 a). `[]` = tanpa ubah hak |
| `permissions.*.id_archive` | wajib, string, `distinct` |
| `permissions.*.is_view/is_update/is_delete/is_store` | wajib, `in:0,1` |
| **(rev3)** `folders` | opsional (`sometimes`), array; hanya folder yang saklarnya berubah. Tidak dikirim / `[]` = flag tidak berubah (perilaku lama); dikirim `null` atau bukan array = 422 |
| **(rev3)** `folders.*.id_archive` | wajib, string, `distinct` (di dalam `folders`); boleh sama dengan id di `permissions` |
| **(rev3)** `folders.*.is_folder_permission` | wajib, `in:0,1` |

FE (rev3): flag yang sama dengan tersimpan tidak dikirim. Hak dikirim untuk folder yang tersimpan On atau On di layar; perubahan sel di
folder yang dimatikan di layar tetap dikirim (K-10 b); folder tersimpan Off yang dimatikan lagi tidak dikirim (perubahannya dibuang).

User target hanya dicek ada & aktif (`ARCHIVE440`); tidak ada validasi lain (D-3, developer 2026-10-08).

Proses (satu transaksi; satu penolakan = tidak ada yang tersimpan, termasuk flag dan baris otomatis):
1. **(rev3)** `folders`: flag folder diubah bila beda dengan tersimpan (+ `updated_by` = user login) lewat method bersama yang juga
   dipakai `PUT archives/{id}` (EPIC K-5 b). Mematikan tidak menghapus baris mana pun. Nilai sama = tanpa tulisan.
2. **(rev3, spec K-9 a)** Folder yang tersimpan Off lalu On di request ini dan `{id}` ≠ user login: baris (folder, **user login**)
   dijadikan 1/1/1/1 - hak otomatis pengaktif (ED-1025 BR-19), juga superadmin & pembuat. **Tidak pernah baris kedua**: baris yang
   sudah ada dinaikkan (upsert per (folder, user), `UNIQUE`). `{id}` = user login: tidak ada baris otomatis dari BE; FE sudah mencentang
   keempat hak (hanya bila tersimpan Off) dan nilai body yang disimpan. Hanya di endpoint ini, tidak di `PUT archives/{id}`.
3. `permissions`: per baris, baris (folder, user target) diganti; keempat flag 0 = baris dihapus; folder lain dan user lain tidak
   tersentuh. **(rev3)** Folder yang **tetap Off** (tersimpan Off dan tidak dinyalakan di `folders`) → 400 `ARCHIVE441`; folder yang
   dinyalakan atau dimatikan di request yang sama boleh diberi hak (K-10 b).
4. Folder yang flag, baris target, atau baris otomatisnya benar-benar berubah mendapat **satu** entri riwayat `permission`
   ("Permission changed by …" / "Permission diubah oleh …").
Simpan bersamaan untuk folder yang sama (klik Save ganda, dua admin, atau bersama tab Izin `PUT archives/{id}`)
berjalan bergiliran lewat `lockFolders` (**rev3**: id gabungan `permissions` + `folders`, query pertama transaksi): semua 200, hasil akhir = kiriman yang diproses terakhir, riwayat hanya untuk
perubahan nyata (fix D-2 QA; dipertahankan D-4).

200 `{ "status": "success", "msg_code": "ARCHIVE207", "message": "Successfully updated", "result": { "id_user": "U3" } }`

| HTTP | Kode | Arti | Dicek di |
|---|---|---|---|
| 403 | `GE0114` | **(rev)** role tanpa `Folder Permissions` (`parameter` `"Folder Permissions"`) | middleware (paling awal); `authorize()` (sebelum 440) + `assertCanManage` mengulang cek yang sama (cadangan, bentuk sama; berpengaruh hanya untuk user customer yang di-bypass middleware) |
| 404 | `ARCHIVE440` | user target tidak ada / nonaktif | `authorize()` (sebelum 422) + service |
| 403 | `ARCHIVE407` | folder aktif di `permissions` **atau `folders` (rev3)** di luar scope lokasi user login (teks generik, tanpa `parameter`) | `authorize()` (sebelum 422) + service |
| 404 | `ARCHIVE400` | `id_archive` (`permissions` atau `folders`) tidak ada, bukan folder, atau tidak aktif | service |
| 400 | `ARCHIVE441` | hak dikirim untuk folder yang **tetap Off**: tersimpan `is_folder_permission` = 0 dan tidak dinyalakan di `folders` (rev3: dinyalakan atau dimatikan di request yang sama = boleh, K-10 b); `parameter` = nama folder | service (sesudah 400/407) |
| 400 | `ARCHIVE411` | `is_update`/`is_delete`/`is_store` = 1 dengan `is_view` = 0 | service |
| 422 | - | validasi Laravel (`permissions.N.id_archive` ganda, flag kosong/bukan 0/1; **rev3** `folders` bukan array, `folders.N.id_archive` ganda/kosong, `is_folder_permission` bukan 0/1) | FormRequest |

**(rev)** `403 ARCHIVE408` **tidak lagi** dikirim endpoint ini (ED-1025 K-15 b): hak View/Update pemanggil di folder atau induknya
tidak dicek, juga untuk `folders` (folder tanpa View pemanggil boleh dinyalakan/dimatikan, ED-1025 K-15 b); folder Izin yang tetap Off
(juga di bawah induk On, K-12 a) = 400 `ARCHIVE441`. Urutan: 403 `GE0114` → 404 `ARCHIVE440` → 403
`ARCHIVE407` → 422 → 404 `ARCHIVE400` → 400 `ARCHIVE441` → 400 `ARCHIVE411`. Ditolak = data tidak berubah.

Body error = `ErrorMessageException` (`message`, `code`, `parameter`); FE menampilkan toast standar
(`showMessage: true`), tidak bercabang pada kode.

## 3. GET `select/document-archive/archive/users` (dipakai ulang dari ED-1025)

Sesuai kontrak ED-1025 §5 (`search`, `excepts[]`, `selected_id`; `{default, options:[{value: id_user, label: username}]}`).
Halaman ini tidak mengirim `excepts`. User login non-superadmin bisa muncul di daftar dan memilih dirinya sendiri; Save tanpa
modal konfirmasi (spec K-8 c), juga saat saklar diubah (rev3).

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
