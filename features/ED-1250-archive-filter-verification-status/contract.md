# Kontrak API - ED-1250 filter Verification Status (Archive)

Base `api/v5`, header `Authorization: Bearer <token>`, `Accept: application/json`. Field response **snake_case** (FE meng-camelCase
lewat interceptor). Key di dalam `search` (JSON string) **camelCase dan tidak dikonversi**. Tidak ada route baru, tidak ada kode
pesan baru.

Permission (sudah ada, `permission_salesman.sql`, hanya lisensi Salesman Activity; BE `permission_v5`/`MyHelper::hasPermission`
dan FE `permissions.*` memakai **nama**):
- **`List Archive`** (1082): `GET document-archive/archives` (§1), tidak berubah.
- **`Opname Document`** (1139): pemegangnya saja yang mendapat filter ini (§1, §2), pola sama dengan kolom `documentVerified` ED-1027.

## 1. GET `document-archive/archives` (ubah)

Query tetap: `page`, `pagination`, `id_archive`, `search` (JSON string), `sorts`. Key baru di `search`:

| Key | Nilai sah | Arti |
|---|---|---|
| `verificationStatus` | `"VERIFIED"` \| `"UNVERIFIED"` (tepat, peka huruf besar) | hanya baris **dokumen** disaring: `archives.is_verified = 1` / `≠ 1` (termasuk NULL). Baris **folder tidak pernah** disaring (rev koreksi developer 2026-10-24): semua folder yang tampil tanpa filter tetap tampil, isi apa pun, hak View apa pun |

Nilai lain (`"verified"`, `"X"`, `1`, `0`, `true`, `null`, `""`, list, objek) = key diabaikan seolah tidak dikirim (200).

Contoh (isi folder F: semua subfolder + hanya dokumen yang belum terverifikasi):
```
GET api/v5/document-archive/archives?id_archive=F1&page=1&pagination=10&search={"verificationStatus":"UNVERIFIED"}
```

Mode list:

| `search` berisi | Mode | Baris |
|---|---|---|
| hanya `verificationStatus` dan/atau `showRelatedTransaction` | **hirarki** (seperti tanpa search) | tanpa `id_archive`: level root; dengan `id_archive`: anak langsung folder itu (butuh View, selain itu 403 `ARCHIVE407` + `result.denied_by`); semua folder + dokumen yang cocok |
| key lain yang menyaring (`query`, `name`, `type`, `updatedAt`, `updatedBy`, ...) | **cari datar** (seperti sekarang, semua level) | key lain menentukan baris seperti sekarang; lalu hanya dokumen yang tidak cocok dibuang (termasuk dokumen di folder tanpa View yang muncul di pencarian, ED-1025 BR-12); folder hasil cari tetap. `type: "FOLDER"` + filter = hasil `type: "FOLDER"` saja |

200 - bentuk tidak berubah (paginator + `columns` + `queries` + `breadcrumbs` + `access` + `document_verified_summary`):
- `total`, `last_page`, `data` = baris yang tersisa (folder seperti tanpa filter + dokumen cocok).
- `breadcrumbs` dan `access` mode hirarki = sama dengan request tanpa filter.
- `data[].document_verified` dan `document_verified_summary` **tidak terpengaruh filter** (menghitung semua dokumen terlihat).
- Folder tanpa View tetap ada dengan `document_verified: null` dan `access.view: false`, seperti tanpa filter.
- Server menyaring dengan satu kondisi di query list: baris dibuang hanya bila `type = FILE` (dokumen) dan statusnya tidak
  sama; baris lain (`type = FOLDER`, juga `type` lain/NULL bila ada) selalu tetap. Tanpa query tambahan. (impl. ED-1253)
- `queries` (pemegang `Opname Document`) memuat, sesudah item `type`:
```json
{
  "title": { "en": "Verification Status", "id": "Status Verifikasi" },
  "data_index": "verificationStatus",
  "type": "select",
  "endpoint": null,
  "options": [
    { "en": "Verified", "id": "Terverifikasi", "value": "VERIFIED" },
    { "en": "Unverified", "id": "Belum terverifikasi", "value": "UNVERIFIED" }
  ]
}
```
  Item ada bila tercantum di `current_queries` display setting (pemegang boleh menyembunyikannya, §2); filter tetap berlaku bila
  key dikirim walau item disembunyikan.

**Pemanggil tanpa `Opname Document`**: key `verificationStatus` dibuang sebelum query (respons identik dengan request yang sama
tanpa key itu; tanpa key lain = list hirarki biasa); item `verificationStatus` tidak ada di `queries`; tetap tanpa
`documentVerified`/`document_verified`/`document_verified_summary` (ED-1027 §1). Bukan 403.

| HTTP | Kode | Arti (tidak berubah) |
|---|---|---|
| 403 | `GE0114` | tanpa `List Archive` / lisensi |
| 403 | `ARCHIVE407` | `id_archive` folder tanpa View atau di luar lokasi, mode hirarki (juga dengan filter) |
| 404 | `ARCHIVE400` | `id_archive` tidak ada / nonaktif |

## 2. GET / PUT `setting/display-columns/{moduleName}` (ubah, `documentArchive`)

Route lama (`Routes/Setup/setting.php:61-65`, tanpa `permission_v5`); body & response tidak berubah. Peta
`ColumnDisplaySettingService::PERMISSION_FILTERS` entri `documentArchive` → `Opname Document` sekarang berisi `columns`
`[documentVerified]` **dan** `queries` `[verificationStatus]`.

| | Pemanggil tanpa `Opname Document` | Pemegang |
|---|---|---|
| GET | 200; item `data_index: "verificationStatus"` tidak ada di `current_queries` maupun `available_queries` (dan `documentVerified` tidak ada di kolom, seperti ED-1027) | seperti sekarang |
| PUT (`current_columns`, `available_columns`, `current_queries`, `available_queries`) | 200; item `verificationStatus` kiriman pemanggil diabaikan, item tersimpan dikembalikan ke list yang tadinya memuatnya, disisipkan sesudah item yang mendahuluinya di baris tersimpan (tidak ada → indeks semula, paling jauh di akhir); list yang tidak dikirim tidak ditulis; aturan sama dengan kolom ED-1027 §4 | seperti sekarang (boleh menyembunyikan/menampilkan) |

Entri **seluruh modul** = entri tanpa `columns` **dan** tanpa `queries` (mis. `documentArchiveOpnameHistory`): tetap 403 `GE0114`
untuk GET/PUT dan tidak ikut `GET setting/display-columns` (index). Modul lain tidak berubah.

| HTTP | Kode | Arti (tidak berubah) |
|---|---|---|
| 403 | `GE0114` | modul berentri seluruh modul, pemanggil tanpa permission |
| 500 | `FAILED` | PUT ke baris yang tidak ada; PUT isi sama di detik yang sama (A-1, diterima) |

## Endpoint FE (`configuration/endpoints.js`) - tidak berubah

`getArchives` (`document-archive/archives`), `getDisplaySetting` / `putDisplaySetting` dipakai apa adanya. (rev Gate 1
2026-10-24, spec K-2 b) Picker `ArchiveTable` (Pindahkan, Pilih Folder pindah massal) memakai `GET archives` yang sama dan
mengirim `verificationStatus` dengan aturan yang sama dengan halaman (§1 tabel mode: sendiri = hirarki di folder picker, bersama
key lain = cari datar dengan `id_archive` null; folder tidak pernah disaring). Tidak ada perubahan server khusus picker.
