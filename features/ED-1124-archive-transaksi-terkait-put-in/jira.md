# ED-1124 (Bug, label ai-found) - Archive - transaksi terkait 500 bila ada folder senama nomor transaksi; put-in mengaktifkan lagi sumber nonaktif

Deskripsi Jira (disalin epic loop 2026-10-14):

Ditemukan saat long run epic ED-1022 (item ED-1069: QA BE), di luar cakupan ED-1069. Semua perilaku sudah ada sebelum ED-1069 (kode pra-ada di `Modules/V5`, branch `v5-opname-archive`); tidak diperbaiki di ED-1069.

1. **Transaksi terkait 500 karena folder senama nomor transaksi**: bila ada **folder** yang namanya persis sama dengan salah satu nomor transaksi terkait sebuah dokumen, list dengan `search={"showRelatedTransaction":true}` → 500 `Trying to get property 'transaction_type' of non-object` (`ArchiveService.php` sekitar baris 701). Akar: kueri akhir `relatedTransaction()` (`whereIn('name', ...)`) tanpa filter `type`, lalu `$archive->document->transaction_type` dipanggil pada folder. Terjangkau dari UI: pengguna cukup membuat folder bernama mis. nomor faktur. Direproduksi QA lewat HTTP:
   * dokumen tipe 7 (SI) dengan `id_transaction` nyata di folder F;
   * buat folder di root bernama persis salah satu nomor di `related_transactions` dokumen itu (uji: `SI-JOG/2511/00002`);
   * `GET api/v5/document-archive/archives?id_archive=F&search={"showRelatedTransaction":true}` → 500.
2. **Put-in mengaktifkan lagi sumber nonaktif**: `POST api/v5/document-archive/documents/put-in` dengan `id_archives` berisi dokumen/folder `is_active=0` (mis. sesudah dihapus) → 200 `ARCHIVE204` dan sumber menjadi `is_active=1` lagi (`DocumentService.php:68` selalu `$file->is_active = 1`). Perlu keputusan: disengaja (pemulihan) atau ditolak (422/404).
3. (Kecil) **Select lokasi dengan induk "0"**: `GET api/v5/select/document-archive/archive/locations?id_archive_parent=0` dibaca kosong oleh `empty()` → 200 semua lokasi root, bukan 404 `ARCHIVE400` seperti id tak ada lainnya (ED-1069 K-4). Tanpa 500.

**Dampak:** butir 1 = 500 yang bisa dipicu pengguna biasa; butir 2 hanya lewat API publik (UI tidak menampilkan baris nonaktif); butir 3 kosmetik.

**Usulan:** butir 1 `where('type', <FILE>)` pada kueri akhir `relatedTransaction()` (atau lewati baris tanpa `document`); butir 2 rule `exists` aktif pada `id_archives` atau keputusan developer; butir 3 bandingkan dengan `=== null`/`''`.

Rujukan: `agentic/features/ED-1069-archive-validasi-pindah-rename-folder/qa-be.md` § Bug di luar item (B-1, B-2, B-4); karakterisasi di `qa/scenario5-extra.php` X-4.
