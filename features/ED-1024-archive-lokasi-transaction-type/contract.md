# Kontrak API - ED-1024 Archive: scope lokasi kerja & transaction type terpusat

Base: `api/v5/`. Response `snake_case` (FE meng-camelCase lewat interceptor). Envelope sukses
`{ "status": "success", "msg_code": "...", "message": "...", "result": ... }`. Error dari
`ErrorMessageException`: `{ "message", "code", ["parameter"] }` (kunci `code`, bukan `msg_code`).
Permission = K-4 a (Gate 1) (nama persis seperti di `app/Sql/data/permission_salesman.sql:67-76`; tanpa
lisensi `SALESMAN_ACTIVITY` baris permission ini tidak ada → semua route di bawah 403 `GE0114`).

Bentuk `result` semua endpoint **tidak berubah** kecuali yang disebut. Yang berubah: baris yang dikembalikan
(scope lokasi), status HTTP error, permission, opsi types.

## Error bersama (semua endpoint Archive non-select)

| HTTP | `code` | Kapan |
|---|---|---|
| 401 | `GE0111` | tanpa token |
| 403 | `GE0114` | role tanpa permission route; `parameter` = nama permission yang kurang (dipisah `, ` bila alternatif) |
| 403 | `ARCHIVE407` | baris (folder/dokumen) ada tetapi di luar scope lokasi user; dicek sebelum validasi field (sebelum 422) |
| 404 | `ARCHIVE400` | id tidak ada |
| 400 | `ARCHIVE401`-`406` | validasi lama (spec BR-10): kini 400 tanpa syarat, sebelumnya 500 |

Scope lokasi: baris terlihat bila `is_all_location = 1` atau salah satu lokasinya termasuk lokasi kerja user;
role `is_superadmin` 1/2 melihat semua (spec BR-1..BR-5).

## GET `document-archive/archives` - list / jelajah / cari

Permission `List Archive`.

| Param | Tipe | Arti |
|---|---|---|
| `id_archive` | string | folder yang dibuka; kosong = root. Di luar scope → 403 `ARCHIVE407`; tak ada → 404 `ARCHIVE400` (sebelumnya 500 dari `formattingBreadcrumb(null)`) |
| `search` | string JSON, kunci camelCase (tidak dikonversi) | `query` (teks bebas), `name`, `type` (`"FOLDER"` atau angka transaction type dari endpoint types), `updatedAt` (rentang), `showRelatedTransaction` (bool), `updatedBy`. Ada kunci selain `showRelatedTransaction` → cari di semua level |
| `page`, `pagination`, `sort` | | standar |

`result` = paginator Laravel (`current_page`, `data`, `per_page`, `total`, …) + `columns`, `queries` +
`breadcrumbs` (`[{value, label}]`). Baris `data[]`: `id_archive`, `name` (`[{id_transaction, transaction_no,
transaction_type}]`), `type` (label: "Folder" atau label transaction type bahasa user), `status` (1 in folder,
2 given, 3 received), `history` (teks status terakhir), `updated_at`, `breadcrumbs`, `document` (dokumen saja),
`related_transactions` (hanya bila `showRelatedTransaction`; **kini ikut scope lokasi**).

## GET `document-archive/archives/{id}` - detail folder

Permission `List Archive` (dipakai drawer View dan modal Add Folder untuk membaca induk). `result`: kolom
`archives` tanpa `history`/`archive_locations` + `id_locations` (array, bila `is_all_location = 0`).
Error: 403 `ARCHIVE407`, 404 `ARCHIVE400` (sebelumnya 500).

## GET `document-archive/archives/history/{id}`

Permission `List Archive`. `result`: `{ "data": [ {action, related_username, timestamp, from_folder?, to_folder?} ],
"action_labels": {...} }`. Error: 403 `ARCHIVE407`, 404 `ARCHIVE400`.

## POST `document-archive/archives/create-folder`

Permission `Add Folder`. Body: `name` (wajib), `description`, `id_archive_parent`, `is_all_location` (0/1, wajib),
`id_locations` (array). `result`: `{ "id_archive" }`, `msg_code` `ARCHIVE201`.
Error tambahan: 403 `ARCHIVE407` bila `id_archive_parent` di luar scope; 400 `ARCHIVE402` lokasi tidak ⊆ induk
(K-6 a; sebelumnya 500 / tidak tervalidasi); 400 `ARCHIVE405` nama kembar; 422 field.

## PUT `document-archive/archives/{id}` dan PUT `document-archive/archives/rename/{id}`

Permission `Update Folder`. Body update: seperti create-folder; rename: `name`. `result`: `{ "id_archive" }`,
`msg_code` `ARCHIVE207` / `ARCHIVE206`. Error: 403 `ARCHIVE407` (id atau `id_archive_parent` di luar scope),
404 `ARCHIVE400`, 400 `ARCHIVE402` (update, K-6 a), 400 `ARCHIVE405` (update, nama kembar), 422.
404/403 untuk `{id}` dicek sebelum validasi field (body tidak valid pun tetap 404/403). `ARCHIVE402` di update
memakai induk dari `id_archive_parent` bila dikirim, selain itu induk folder saat ini.

## DELETE `document-archive/archives/delete/{id}`

Permission `Delete Folder`. `result`: `null`, `msg_code` `ARCHIVE203`. Error: 403 `ARCHIVE407`, 404 `ARCHIVE400`,
400 `ARCHIVE401` ada dokumen aktif di turunan mana pun (K-7 a; sebelumnya 500 dan hanya anak langsung),
403 `ARCHIVE418` ada subfolder turunan di luar scope user (K-7 a; 02 menambah: subfolder aktif tanpa Delete efektif).
Urutan cek: 404/403 `ARCHIVE407` → 403 `ARCHIVE418` (subfolder aktif `is_active > 0` di luar scope) → 400 `ARCHIVE401`
(dokumen `is_active > 0`, yaitu yang tampil di list). Sukses: folder dan semua subfolder turunannya `is_active = 0`;
baris dokumen tidak diubah (yang tersisa hanya dokumen nonaktif/`-1`).

## POST `document-archive/documents/put-in` - simpan / pindahkan

Permission `Move Archive|Add Document` (salah satu cukup). Body: `id_archive_parent` (tujuan; kosong = root),
salah satu dari `id_archives` (array) atau `name` (hasil scan). `result`: array baris yang dipindah,
`msg_code` `ARCHIVE204`. Error: 403 `ARCHIVE407` bila `id_archive_parent` atau salah satu `id_archives` di luar
scope (sumber lewat `name` tidak dicek lokasi, K-5 a); 400 `ARCHIVE403` lokasi dokumen tidak ⊆ folder (K-6 a);
400 `ARCHIVE404` (ke dirinya sendiri), `ARCHIVE405`, `ARCHIVE406`; 404 `ARCHIVE400` tidak ada sumber yang ditemukan
(sebelumnya 500); 422 (termasuk `id_archive_parent` bukan teks, atau elemen `id_archives` bukan teks/null: sebelumnya 500).

## POST `document-archive/documents/hand-over` dan `document-archive/documents/receive`

Permission `Handover Document` / `Receive Document`. Body: `name`. `result`: baris dokumen + `action`;
`msg_code` `ARCHIVE209` / `ARCHIVE210`. Tidak dibatasi lokasi (K-5 a). Error: 404 `ARCHIVE400` bila `name` tidak
ditemukan (sebelumnya 500).

## GET `select/document-archive/archive/types`

Tanpa permission (konvensi select). `result`:

```json
{ "default": null, "options": [
  { "value": "FOLDER", "label": "Folder" },
  { "value": 6,   "label": "Sales Order" },
  { "value": 7,   "label": "Delivery Order" },
  { "value": 8,   "label": "Sales Invoice" },
  { "value": 9,   "label": "Sales Return" },
  { "value": 29,  "label": "Receivable Payment" },
  { "value": 31,  "label": "Bilyet Giro/Cheque" },
  { "value": 222, "label": "Billing" }
] }
```

Label mengikuti bahasa user (ID: Pesanan Penjualan, Pengantaran Pesanan, Faktur Penjualan, Retur Penjualan,
Pembayaran Piutang, Bilyet Giro/Cek, Penagihan). Baris Billing hanya bila K-3 a. Urutan = urutan daftar
transaction type archive (satu sumber, dipakai juga item 03/04).

## GET `select/document-archive/archive/locations`

Tidak berubah (K-8 a): param `id_archive_parent`; `{default, options}` lokasi, dibatasi lokasi induk.

## Kode pesan baru

| Kode | HTTP | en | id |
|---|---|---|---|
| `ARCHIVE407` | 403 | You don't have access to this folder or document | Anda tidak punya akses ke folder atau dokumen ini |
| `ARCHIVE418` | 403 | Folder can't be deleted, it contains subfolders you can't access or delete | Folder tidak dapat dihapus, berisi subfolder yang tidak dapat Anda akses atau hapus |
