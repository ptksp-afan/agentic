# ED-1069 (Bug, label ai-found) - Archive - celah validasi pindah/rename folder & 500 di select lokasi / transaksi terkait

Deskripsi Jira (disalin epic loop 2026-10-08):

Ditemukan saat long run epic ED-1022 (item ED-1024: dev BE dan QA BE), di luar cakupan ED-1024. Semua perilaku sudah ada sebelum ED-1024 (kode pra-ada di `Modules/V5`, branch `v5-opname-archive`); tidak diperbaiki di ED-1024.

1. **Put-in ke folder yang tidak ada**: `POST api/v5/document-archive/documents/put-in` dengan `id_archive_parent` berisi id yang tidak ada → 200 `ARCHIVE204` dan item dipindah ke root (tidak ada rule `exists` pada `id_archive_parent`). Harapan: 404/422. Direproduksi QA (O-2).
2. **Put-in folder ke turunannya sendiri**: `SelfFolderRule` hanya menolak folder dipindah ke dirinya sendiri, bukan ke subfolder turunannya, sehingga bisa terbentuk siklus induk-anak; breadcrumb (`with('parents')`) lalu berisiko berputar tanpa henti. Ditemukan saat review kode, belum direproduksi lewat HTTP.
3. **Rename tanpa cek nama kembar**: `PUT archives/rename/{id}` tidak memakai `UniqueNameRule`, jadi nama folder kembar di induk yang sama lolos lewat rename (create-folder dan update menolaknya dengan `ARCHIVE405`). Ditemukan saat review kode.
4. **Select lokasi dengan induk tidak ada**: `GET api/v5/select/document-archive/archive/locations` dengan `id_archive_parent` yang tidak ada → 500 (`$parent` null). Ditemukan saat review kode.
5. **Transaksi terkait dengan dokumen yatim**: `ArchiveService::relatedTransaction` (mis. `ArchiveService.php:327` untuk Billing, pola sama untuk SO/DO/SI/Return/Payment/BG) memakai transaksi tanpa cek null: bila `archive_documents.id_transaction` menunjuk transaksi yang sudah tidak ada, list dengan `search={"showRelatedTransaction":true}` → 500. Data QA `api_sidomaju`: 0 dokumen yatim, jadi hanya terpicu data sintetis.

**Dampak:** FE v5 tidak mengirim input butir 1, 4 (alur normal), tetapi API publik tetap bisa dipanggil; butir 2 dan 3 bisa terjadi lewat UI (modal Pindahkan / rename).

**Usulan:** perbaiki dalam satu perubahan kecil di `Rules/DocumentArchive/*` dan `ArchiveService` (rule `exists` + cek turunan + `UniqueNameRule` di rename + guard null), dengan skenario QA-nya.

Rujukan: `agentic/features/ED-1024-archive-lokasi-transaction-type/qa-be.md` § Perlu dikonfirmasi (O-2, O-3) dan run log item tersebut.
