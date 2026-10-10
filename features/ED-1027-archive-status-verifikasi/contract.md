# Kontrak API - ED-1027 Status verifikasi dokumen

Base `api/v5`, header `Authorization: Bearer <token>`, `Accept: application/json`. Semua field response
**snake_case** (FE meng-camelCase lewat interceptor). Group route `document-archive` = `auth:api`.

Permission (sudah ada, `permission_salesman.sql`, hanya di-seed untuk lisensi Salesman Activity; tidak ada yang baru):
- **`List Archive`** (1082): `GET archives` (§1).
- **(rev 2026-10-08) `Opname Document`** (id **1139**, EPIC K-4 a; seed diubah ED-1025): `GET verifications` dan
  `GET verifications/{id}` (§2, §3) - sebelumnya `List Archive`. `permission_checks` (1139,1082): pemegangnya juga punya
  `List Archive`. BE (`permission_v5`, `MyHelper::hasPermission`) dan FE memakai **nama**, bukan id.
- **(rev)** Data verifikasi di `GET archives` dan entri `documentVerified` di display setting hanya untuk pemegang `Opname Document`
  (§1, §4; spec BR-18/19, K-9 a = EPIC K-3 a, Gate 1 2026-10-08). Tidak ada kode pesan baru.

Angka di semua endpoint hanya menghitung dokumen yang boleh dilihat pemanggil (spec BR-5): dokumen aktif
(`type=2`, `is_active>0`) yang lolos scope lokasi (01) dan berada di folder ber-View (02) atau di root;
superadmin `is_superadmin` 1/2 = semua. "Verified" = `archives.is_verified` saat ini (ditulis opname 03).

## Objek bersama

`verification_count`:
```json
{ "verified": 12, "total": 18 }
```

`transaction_type_row` - satu per tipe di daftar 01 (`ArchiveDocument::TRANSACTION_TYPES`), urutan tetap,
tipe bernilai 0 tetap dikirim; `label` mengikuti bahasa user (sumber sama dengan `select/document-archive/archive/types`):
```json
{ "transaction_type": 6, "label": "Sales Order", "verified": 210, "unverified": 430, "total": 640 }
```

`session_row` - satu sesi opname terkonfirmasi (03), angka = saat dikonfirmasi:
```json
{
  "id_archive_opname": "2610...",
  "confirmed_at": "2026-08-12 14:02:11",
  "created_by": "kevinsudjadi",
  "scope": { "is_root": false, "folder_name": "Cabang Jogja", "subfolder_count": 3 },
  "total_documents": 3400,
  "verified_count": 126,
  "not_found_count": 8,
  "invalid_count": 3
}
```
- Nama field = kolom `archive_opnames` 03, sama dengan baris riwayat ED-1028; tanpa `id_user`. `confirmed_at` = teks
  `Y-m-d H:i:s` (waktu server); `verified_count` = jumlah scan Verified di sesi itu.
- `scope` = objek **sama persis** dengan `scope` di `GET opnames/{id}` (dibentuk `OpnameService::scope()`, milik ED-1026).
  **(rev Gate 1, ED-1028 K-10 b)** tepat tiga kunci `is_root`, `folder_name` (null bila root), `subfolder_count` (jumlah subfolder
  yang dicentang di Step 1); `first_name`/`other_count` **dihapus** (nama subfolder tidak dikirim). Tampilan FE `getScopeTag()`
  ED-1026 (K-14 a): root ? "All Archive · root" (id "Semua Arsip · root") : `folder_name`, ditambah " + {subfolder_count} subfolder"
  bila > 0.
- Hanya di `GET verifications/{id}`: tambahan `folder_total_documents` (dokumen folder itu + subfoldernya yang ikut sesi,
  snapshot saat sesi) dan `folder_verified` (jumlah `archive_opname_folders.verified_count` = scan Verified; dokumen yang tetap
  verified karena "Lanjut opname" tanpa discan **tidak** ikut). Folder yang dijumlah = folder itu + subfolder yang **saat ini**
  terlihat pemanggil; angkanya snapshot saat Confirm.
- Sesi terlihat (dipakai juga ED-1028): superadmin 1/2 semua sesi terkonfirmasi; user lain: sesi dari root (`id_archive` null),
  sesi buatannya (`created_by`), dan sesi yang punya baris folder (level 0-2) di folder yang saat ini terlihat. Urutan
  `confirmed_at` turun, lalu `id_archive_opname` turun. (rev, ED-1026 FB-3: DB hanya berisi sesi terkonfirmasi.)

## 1. GET `document-archive/archives` (ubah)

Query tidak berubah (`page`, `pagination`, `id_archive`, `search` JSON string camelCase, `sort`/`sorts`).
Permission: `List Archive` (01).

200 - seperti sekarang (paginator + `columns`/`queries` + `breadcrumbs` + `access` 01/02), ditambah (hanya pemegang `Opname Document`):
```json
{
  "status": "success", "msg_code": "ARCHIVE200", "message": "Document is found",
  "result": {
    "current_page": 1,
    "data": [
      { "id_archive": "A1", "type": "Folder", "...": "...", "document_verified": { "verified": 12, "total": 18 } },
      { "id_archive": "A2", "type": "Folder", "...": "...", "document_verified": null },
      { "id_archive": "D1", "type": "Delivery Order", "document": { "...": "..." },
        "document_verified": { "verified": 1, "total": 1 } }
    ],
    "total": 3, "per_page": 10, "...": "...",
    "columns": [
      "...",
      { "title": { "en": "Salesman", "id": "Salesman" }, "type": "string", "sort": true,
        "data_index": ["document", "relatedEmployeeName"], "foreign_key": "idArchive" },
      { "title": { "en": "Document Verified", "id": "Dokumen Terverifikasi" }, "type": "string",
        "sort": false, "data_index": "documentVerified" },
      { "title": { "en": "Status", "id": "Status" }, "type": "string", "sort": false, "data_index": "history" }
    ],
    "queries": [],
    "breadcrumbs": [],
    "document_verified_summary": { "verified": 1204, "total": 3680 }
  }
}
```
- `data[].document_verified`: folder = `verification_count` folder + semua subfolder terlihat; subfolder tak terlihat (tanpa View /
  di luar lokasi / nonaktif) terputus beserta isinya; folder tanpa View = `null` (FE `-`); dokumen = `{verified: 0|1, total: 1}`.
- `result.document_verified_summary`: **selalu All Archive**, sama di root, di dalam folder, dan saat mencari/memfilter
  (dokumen di root + di setiap folder terlihat).
- Dokumen tanpa transaction type dikenal: dihitung di total/ringkasan, tanpa baris tipe (§2); hanya data lama (QA DB: 0).
- `documentVerified` tidak bisa di-sort dan bukan `queries`. Ada juga di `available_columns` display setting (§4).
- **(rev) Pemanggil tanpa `Opname Document`**: item `data_index: "documentVerified"` tidak ada di `columns`; key
  `document_verified` tidak ada di baris; key `document_verified_summary` tidak ada di `result`; angka tidak dihitung. Isi lain
  (baris, urutan, paginasi, field lain) sama. (klarifikasi fix r1) Key `search` dan item `sorts` yang menyebut kolom verifikasi
  `archives` (`is_verified`, `verified_at`, `verified_by`, `id_archive_opname`; camel/snake, `tabel.kolom`, `where...()`, juga di
  dalam filter relasi) diabaikan seolah tidak dikirim: tanpa key lain = list root biasa, tanpa sort lain = urutan default. Tanpa
  kode pesan baru; pemegang `Opname Document` tidak berubah.
- Error: tidak berubah dari 01/02.

## 1b. GET `document-archive/archives/{id}` (ubah, rev fix r1b)

Detail folder/dokumen 01/02, permission `List Archive`, bentuk tidak berubah, kecuali untuk **pemanggil tanpa `Opname Document`**:
key kolom verifikasi `is_verified`, `verified_at`, `verified_by`, `id_archive_opname` tidak ada di `result` (folder maupun
dokumen; spec BR-19, K-9 a). Key lain sama; pemegang `Opname Document` tidak berubah. FE tidak membaca key ini dari respons detail
(grep `ksp-react/src` 2026-10-09).

## 2. GET `document-archive/verifications` (ubah: permission)

Detail verifikasi All Archive (modal Document Verified dari ringkasan). Tanpa query param. Permission **(rev) `Opname Document`**.

200:
```json
{
  "status": "success", "msg_code": "ARCHIVE200", "message": "Document is found",
  "result": {
    "id_archive": null,
    "name": null,
    "verified": 1204,
    "unverified": 2476,
    "total": 3680,
    "transaction_types": [
      { "transaction_type": 6, "label": "Sales Order", "verified": 210, "unverified": 430, "total": 640 },
      { "transaction_type": 7, "label": "Delivery Order", "verified": 402, "unverified": 778, "total": 1180 }
    ],
    "last_sessions": [ { "...": "session_row" } ]
  }
}
```
- `verified`/`total` = `document_verified_summary` di list; jumlah tiap kolom `transaction_types` = header.
- `last_sessions`: maksimal 3 sesi terkonfirmasi terbaru yang terlihat pemanggil; `[]` bila belum ada.

| HTTP | Kode | Arti |
|---|---|---|
| 403 | `GE0114` | (rev, EPIC K-3 a) tanpa permission `Opname Document` (termasuk tenant tanpa lisensi Salesman Activity); middleware `permission_v5`, tanpa `result` |

## 3. GET `document-archive/verifications/{id}` (ubah: permission)

Detail verifikasi satu folder: tab Verification drawer folder dan **(rev, FB-1) modal verifikasi folder** dari klik nilai
Document Verified baris folder di list (endpoint dan bentuk tidak berubah). `{id}` = `id_archive` folder.
Permission **(rev) `Opname Document`**.

200 - bentuk sama dengan §2, dengan `id_archive`/`name` folder, angka folder (= `document_verified` folder itu di list induknya),
dan `last_sessions[]` = maksimal 3 sesi terkonfirmasi terbaru yang mencakup folder itu atau subfoldernya, tiap baris ditambah
`folder_total_documents` & `folder_verified`:
```json
{
  "status": "success", "msg_code": "ARCHIVE200", "message": "Document is found",
  "result": {
    "id_archive": "A1", "name": "SMLSMG - BRANGKAS",
    "verified": 7, "unverified": 14, "total": 21,
    "transaction_types": [ { "transaction_type": 6, "label": "Sales Order", "verified": 1, "unverified": 3, "total": 4 } ],
    "last_sessions": [
      { "id_archive_opname": "2610...", "confirmed_at": "2026-08-12 14:02:11", "created_by": "kevinsudjadi",
        "scope": { "is_root": false, "folder_name": "Cabang Jogja", "subfolder_count": 3 },
        "total_documents": 3400, "verified_count": 126, "not_found_count": 8, "invalid_count": 3,
        "folder_total_documents": 21, "folder_verified": 18 }
    ]
  }
}
```

| HTTP | Kode | Arti | Body |
|---|---|---|---|
| 404 | `ARCHIVE400` | id tidak ada / folder nonaktif / id di luar ASCII cetak atau > 30 karakter (tanpa query) | `ErrorMessageException` (`code`, `message`) |
| 400 | `ARCHIVE417` (03) | id adalah dokumen, bukan folder (teks pesan milik 03) | idem |
| 403 | `ARCHIVE407` | folder di luar scope lokasi (01) atau tanpa View efektif (02); bentuk sama dengan `GET archives/{id}` 02 (`parameter` = nama folder penolak untuk View), tanpa `result` | idem |
| 403 | `GE0114` | (rev, EPIC K-3 a) tanpa permission `Opname Document` | middleware `permission_v5` |

Urutan cek: permission (403 `GE0114`) → id tidak ada (404) → di luar lokasi (403) → dokumen (400) → folder nonaktif (404) →
tanpa View (403). Tidak ada 500 untuk kasus di atas (D-6). Modal folder FB-1 menampilkan error ini lewat toast interceptor + isi kosong.

## 4. GET / PUT `setting/display-columns/{moduleName}` (ubah, rev)

Route lama (`Routes/Setup/setting.php:61-65`, group `setting`, `auth:api` + `cns`, tanpa `permission_v5`); body & response tidak
berubah. Saringan per permission lewat **satu peta** di `ColumnDisplaySettingService` (rev Gate 1, O-3), kunci = `module_name`
sesudah alias/`specialCase` (nama di `column_display_settings`). Cek permission = `MyHelper::hasPermission` (berbasis role, tanpa
bypass superadmin - sama dengan `permission_v5`). Pemegang permission dan modul tanpa entri: seperti sekarang.

| Jenis entri | Contoh | GET (pemanggil tanpa permission) | PUT (pemanggil tanpa permission) | Pemilik |
|---|---|---|---|---|
| per `data_index` | `documentArchive` → `Opname Document` → `[documentVerified]` | 200; item ber-`data_index` itu tidak ada di `current_columns` maupun `available_columns` (`current_queries`/`available_queries` tidak terdampak); disaring sesudah saringan lisensi | 200; item itu dikembalikan dari baris tersimpan (aturan di bawah) | ED-1027 |
| seluruh modul (entri tanpa `columns` atau `columns` kosong) | `documentArchiveOpnameHistory` → `Opname Document` | 403 `GE0114` (`parameter` = nama permission), tanpa `result`; dicek sebelum baris dibuat otomatis → baris tidak dibuat | 403 `GE0114`, DB tidak ditulis | struktur ED-1027; entri + saringan `GET setting/display-columns` (index) ED-1028 |

(klarifikasi v5-be-dev 2026-10-09) Peta = `ColumnDisplaySettingService::PERMISSION_FILTERS`, `[module_name => ['permission' => ..., 'columns' => [data_index, ...]]]`;
`data_index` berbentuk array ditulis digabung titik (`document.relatedEmployeeName`). Peta hanya berlaku untuk endpoint display
setting ini; `GET archives` menyaring kolom `documentVerified` sendiri (§1).

Aturan PUT entri per `data_index` (`documentArchive`, spec BR-19; body `current_columns`, `available_columns`, `current_queries`,
`available_queries` dari form display setting): sebelum disimpan, server mengembalikan item `documentVerified` dari baris tersimpan
- ke `available_columns` bila tadinya ada di sana, ke `current_columns` bila tadinya ada di sana, disisipkan sesudah kolom yang
mendahuluinya di baris tersimpan (kolom itu tidak ada di body → posisi indeks semula, paling jauh di akhir). Item `documentVerified`
yang dikirim pemanggil ini diabaikan (nilai tersimpan menang). Pemegang `Opname Document`: seperti sekarang (boleh
menyembunyikan/menampilkan kolom itu). (klarifikasi v5-be-dev) List yang tidak dikirim di body tidak ditulis (seperti
sekarang), jadi isinya tetap; `available_columns: null` dari pemanggil ini diganti list berisi item tersimpan yang disaring.

| HTTP | Kode | Arti |
|---|---|---|
| 403 | `GE0114` | (rev) modul berentri seluruh modul, pemanggil tanpa permission entri itu (dari ED-1028). Status 403 diberikan eksplisit (pola `PresetReportService.php:123-129`; default `ErrorMessageException` = 500) |
| 500 | `FAILED` | seperti sekarang: PUT ke baris yang tidak ada (`ColumnDisplaySettingService.php:89-91`) |

Isi peta dari item ini hanya `documentArchive`. Modul lain (mis. `customer`) tidak berubah.

## Endpoint FE (`configuration/endpoints.js`, grup Archive) - tidak berubah

```js
getArchiveVerifications: 'document-archive/verifications',
getArchiveVerification: 'document-archive/verifications/:id', // (rev) dipakai juga modal folder FB-1
```
Display setting memakai endpoint yang sudah ada (`getDisplaySetting` / `putDisplaySetting`).
