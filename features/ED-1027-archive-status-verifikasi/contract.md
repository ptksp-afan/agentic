# Kontrak API - ED-1027 Status verifikasi dokumen

Base `api/v5`, header `Authorization: Bearer <token>`, `Accept: application/json`. Semua field response
**snake_case** (FE meng-camelCase lewat interceptor). Group route `document-archive` = `auth:api`.
Permission (sudah ada, `permission_salesman.sql:67-76`, hanya di-seed untuk lisensi Salesman Activity):
**`List Archive`** untuk ketiga endpoint di bawah (penerapan `permission_v5` pada route Archive mengikuti
ED-1024 K-4). Tidak ada permission dan kode pesan baru.

Angka di semua endpoint hanya menghitung dokumen yang boleh dilihat pemanggil (spec BR-5): dokumen aktif
(`type=2`, `is_active>0`) yang lolos scope lokasi (01) dan berada di folder ber-View (02) atau di root;
superadmin `is_superadmin` 1/2 = semua. "Verified" = status verified saat ini dari opname (03).

## Objek bersama

`verification_count`:
```json
{ "verified": 12, "total": 18 }
```

`transaction_type_row` - satu per tipe di daftar 01 (`ArchiveDocument::TRANSACTION_TYPES`), urutan tetap,
tipe bernilai 0 tetap dikirim:
```json
{ "transaction_type": 6, "label": "Sales Order", "verified": 210, "unverified": 430, "total": 640 }
```
`label` mengikuti bahasa user (sumber label sama dengan `select/document-archive/archive/types`).

`session_row` - satu sesi opname terkonfirmasi (03), angka = saat dikonfirmasi:
```json
{
  "id_archive_opname": "2610...",
  "confirmed_at": "2026-08-12 14:02:11",
  "created_by": "kevinsudjadi",
  "scope": { "is_root": false, "folder_name": "Backup", "first_name": "Backup Arsip", "other_count": 3 },
  "total_documents": 3400,
  "verified_count": 126,
  "not_found_count": 8,
  "invalid_count": 3
}
```
- Nama field = kolom `archive_opnames` 03 (gate1 C-2), sama dengan kunci `history_row` 05; tanpa `id_user`/`username`.
  `confirmed_at` = teks `Y-m-d H:i:s` (waktu server); `verified_count` = jumlah scan berhasil Verified di sesi itu.
- `scope` = objek **sama persis** dengan `scope` di `GET opnames/{id}` (kontrak 03 §4, dibentuk `OpnameService::scope()`);
  FE memakai `getScopeTag()` 03 ("All Archive · root" / "<first_name> + <other_count> subfolder" / `folder_name`).
- Hanya di `GET verifications/{id}`: tambahan `folder_total_documents` (dokumen folder itu + subfoldernya
  yang ikut sesi, snapshot saat sesi) dan `folder_verified` (yang hasilnya Verified di sesi itu = jumlah
  `archive_opname_folders.verified_count`, yaitu scan Verified; dokumen yang tetap verified karena "Lanjut opname"
  tanpa discan **tidak** ikut, sama dengan arti `verified_count` sesi). Folder yang dijumlah = folder itu + subfolder
  yang **saat ini** terlihat pemanggil (pohon sekarang, bukan induk snapshot); angkanya snapshot saat Confirm.
- Sesi terlihat (dipakai juga 05): superadmin `is_superadmin` 1/2 semua sesi terkonfirmasi; user lain: sesi dari root
  (`id_archive` null), sesi buatannya sendiri (`created_by`), dan sesi yang punya baris folder (level 0-2) di folder
  yang saat ini terlihat (aktif + scope lokasi 01 + View 02). Sesi draft/batal tidak pernah ikut. Urutan
  `confirmed_at` turun, lalu `id_archive_opname` turun.

## 1. GET `document-archive/archives` (ubah)

Query tidak berubah (`page`, `pagination`, `id_archive`, `search` JSON string camelCase, `sort`/`sorts`).
Permission: `List Archive` (01).

200 - seperti sekarang (paginator + `columns`/`queries` + `breadcrumbs` + tambahan 01/02), ditambah:
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
- `data[].document_verified`: folder = `verification_count` folder + semua subfolder terlihat (spec K-3 a);
  subfolder yang tidak terlihat (tanpa View / di luar lokasi / nonaktif) terputus beserta seluruh isinya, sama dengan
  isi saat folder dibuka; folder tanpa View (02) = `null` (FE `-`); dokumen = `{verified: 0|1, total: 1}`
  (`verified` = `archives.is_verified` saat ini, berlaku juga di hasil pencarian).
- `result.document_verified_summary`: **selalu All Archive**, sama di root, di dalam folder, dan saat
  mencari/memfilter = dokumen di root + dokumen di setiap folder terlihat (spec BR-5, per dokumen).
- Dokumen tanpa transaction type yang dikenal tetap dihitung di total/ringkasan tetapi tidak punya baris tipe
  (§2); validasi 01 membuat kasus ini hanya mungkin dari data lama (QA DB: 0).
- `documentVerified` tidak bisa di-sort dan bukan `queries`. Kolom ada juga di `available_columns`.
- Error: tidak berubah dari 01/02.

## 2. GET `document-archive/verifications` (baru)

Detail verifikasi All Archive (modal 05). Tanpa query param. Permission `List Archive`.

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
- `last_sessions`: maksimal 3 sesi terkonfirmasi terbaru yang terlihat pemanggil (spec K-4 + EPIC K-1), urut
  `confirmed_at` turun; `[]` bila belum ada.

| HTTP | Kode | Arti |
|---|---|---|
| 403 | `GE0114` | tanpa permission `List Archive` (termasuk tenant tanpa lisensi Salesman Activity) |

## 3. GET `document-archive/verifications/{id}` (baru)

Detail verifikasi satu folder (tab Verification). `{id}` = `id_archive` folder. Permission `List Archive`.

200 - bentuk sama dengan §2, dengan `id_archive`/`name` folder, angka folder (= `document_verified`
folder itu di list induknya), dan `last_sessions[]` = maksimal 3 sesi terkonfirmasi terbaru yang mencakup
folder itu atau subfoldernya, tiap baris ditambah `folder_total_documents` & `folder_verified`:
```json
{
  "status": "success", "msg_code": "ARCHIVE200", "message": "Document is found",
  "result": {
    "id_archive": "A1", "name": "SMLSMG - BRANGKAS",
    "verified": 7, "unverified": 14, "total": 21,
    "transaction_types": [ { "transaction_type": 6, "label": "Sales Order", "verified": 1, "unverified": 3, "total": 4 } ],
    "last_sessions": [
      { "id_archive_opname": "2610...", "confirmed_at": "2026-08-12 14:02:11", "created_by": "kevinsudjadi",
        "scope": { "is_root": false, "folder_name": "Backup", "first_name": "Backup Arsip", "other_count": 3 },
        "total_documents": 3400, "verified_count": 126, "not_found_count": 8, "invalid_count": 3,
        "folder_total_documents": 21, "folder_verified": 18 }
    ]
  }
}
```

| HTTP | Kode | Arti | Body |
|---|---|---|---|
| 404 | `ARCHIVE400` | id tidak ada / folder nonaktif / id berisi karakter di luar ASCII cetak atau > 30 karakter (tanpa query, seperti 03) | `ErrorMessageException` (`code`, `message`) |
| 400 | `ARCHIVE417` (03) | id adalah dokumen, bukan folder (teks pesan milik 03: "Opname can only be run on a folder") | idem |
| 403 | `ARCHIVE407` | folder di luar scope lokasi (01) atau tanpa View efektif (02); bentuk sama dengan `GET archives/{id}` 02 (`code`, `message`, `parameter` = nama folder penolak untuk View), tanpa `result` | idem |
| 403 | `GE0114` | tanpa permission `List Archive` | middleware |

Urutan cek: id tidak ada (404) → di luar lokasi (403) → dokumen (400) → folder nonaktif (404) → tanpa View (403).

Tidak ada 500 untuk kasus di atas (D-6).

## Endpoint FE (`configuration/endpoints.js`, grup Archive)

```js
getArchiveVerifications: 'document-archive/verifications',
getArchiveVerification: 'document-archive/verifications/:id',
```
