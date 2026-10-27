# ED-1022 review ronde 3 - bug "folder hilang sesudah klik breadcrumb" (user `sam`)

Status: **FE saja** (API konsisten). Mekanisme di VirtualTable kuat, urutan frame persisnya belum dibuktikan di browser. HEAD BE 6fcc91fd0, FE d56ce3962. Developer: B1 = C dikembalikan ke All Location, B2 = a, B3 = a (Bug baru).

## Reproduksi

Data (read-only, 14 Okt): Folder C `is_all_location=1` (diubah 13 Okt 16:36:08). Baris `archive_locations` lama C (`...630621`) masih ada, tetapi diabaikan karena `is_all_location=1` (`Archive.php:126-128`). Folder A `is_all_location=1`. Folder B lokasi `...413438` (lokasi sam).

HTTP sebagai `QA_USER3` (sam), `api_sidomaju`, bind tanpa `force_login` (200 `GS0500`). 1 token dibuat, 1 log-out, 0 aktif. GET `api/v5/document-archive/archives`, 9 langkah:

| Langkah | Hasil (setiap kali sama) |
|---|---|
| root (3x) | total=3: Backup Arsip, CABANG - SEMARANG, **Folder A** |
| Folder A (4x, juga sesudah B) | total=2: Folder B, **Folder C** |
| Folder B (2x) | total=398, 10 baris per halaman |

API selalu mengirim C di bawah A dan A di bawah root. Jadi baris yang hilang di layar adalah masalah FE.

## Akar masalah

- **FE tidak memfilter baris.** `ArchivePage` hanya `flattenArchives(tableProps.dataSource)` (`ksp-react/src/containers/Archive/ArchivePage/index.js:171-173`, `archive.function.js:57-71`). `useAPI` tidak meng-cache GET (`src/hooks/apiHook.js:376-381`).
- **Satu instance VirtualTable dipakai untuk semua folder.** `onClickName`/`onClickBreadcrumb` (`index.js:236-258`) hanya `run({ idArchive })`. Tabel (`index.js:542-549`) tidak di-remount. Konteks virtualisasi dibuat sekali per mount (`components/VirtualTable/index.js:21`, `useVT(..., [])`) dan membawa `_offset_head/_offset_tail/row_count/row_height` dari folder sebelumnya.
- **`virtualizedtableforantd4@1.2.1` me-render potongan dari state lama.** `VWrapper` me-render `rows.slice(head, tail)` (`node_modules/virtualizedtableforantd4/dist/esm/vt.js:690`). `tail` milik daftar sebelumnya hanya dipotong bila daftar mengecil (`vt.js:648-659`). Bila jumlah baris berubah, koreksinya bergantung pada recompute asinkron (`set_tr_cnt` `vt.js:303-306` -> efek `vt.js:560` -> RAF `vt.js:382-457`) atau event scroll native. Konteks baru (reload) mulai dari INIT (`vt.js:615-630`) dan benar, cocok dengan "reload memperbaiki".
- **Mengapa sam:** hak arsip role sam (5) dan kevin (3) sama kecuali `Folder Permissions`, jadi kolomnya sama. Bedanya panjang daftar. Root sam = 3 baris dan A = 2, semuanya di bawah `scroll.y` 600 tanpa scrollbar, sehingga tidak ada scroll yang memicu hitung ulang. Root kevin = 10 baris per halaman (3537 item). Gejala selalu "baris terakhir hilang" (root 2 dari 3, A 1 dari 2). Frame persis yang melewatkan recompute belum dilacak; AC-1 membuktikannya.

## Perbaikan (keputusan B2 = a)

- FE, `ArchivePage/index.js:542`: `VirtualTable` diberi `key` = folder terbuka (`lastBreadcrumb?.value ?? 'root'`, plus penanda mode cari/scan). Konteks VT mulai dari INIT setiap pindah folder, sama dengan reload. Pilihan mode Pilih dan `selectedRowKeys` disimpan di `ArchivePage`, jadi tidak hilang.
- FE, `archive-components/ArchiveTable/index.js:174` (picker Move/Bulk Move): `key` per folder yang sama.
- `components/VirtualTable` global dan versi library tidak diubah. BE tidak berubah.

## Acceptance criteria

| AC | Tag | Given/When/Then | Cek |
|---|---|---|---|
| AC-1 | [FE] | Given user yang root-nya ≤3 baris dan Folder A berisi 2 subfolder (tanpa scrollbar). When root -> A -> B -> breadcrumb A -> breadcrumb root. Then tiap langkah: jumlah `tr.ant-table-row` = `result.data.length` respons terakhir (A: B dan C, root: Folder A ada), tanpa scroll/reload. Gagal di HEAD d56ce3962, lolos sesudah fix | e2e (sesi per K-4) |
| AC-2 | [FE] | Sama dengan AC-1, daftar mengecil lalu membesar (B 10 baris -> A 2 -> root 3) dan lewat klik nama folder + "Back to Archive". Pilihan mode Pilih lintas folder tetap ada (regresi bulk move/ED-1072) | e2e |
| AC-3 | [FE] | Picker `ArchiveTable` (Move): navigasi folder pendek bolak-balik, baris = respons | e2e |
| AC-4 | [FE] regresi | User dengan 10 baris per halaman: navigasi yang sama, baris = respons, paging/sort/scroll tetap | e2e |
| AC-5 | [BE] regresi | sam: folder `is_all_location=0` di lokasi lain tidak dikirim. `is_all_location=1` dikirim walau ada baris `archive_locations` lama (ED-1024) | http (`api_sidomaju`, `result.data[].name`) |

## Keputusan untuk developer

| # | Pertanyaan + situasi | Opsi + konsekuensi | Rekomendasi | Risiko |
|---|---|---|---|---|
| K-1 | (dijawab B1) Lokasi C saat bug | C = All Location | - | - |
| K-2 | (dijawab B2) Cara fix | a) `key` per folder (ArchivePage + picker) | a | rendah |
| K-3 | (dijawab B3) Tiket | a) Bug baru | a | rendah |
| K-4 | Reproduksi browser sebagai `sam` butuh login harness e2e. Bila sam sudah punya sesi (GS0500), harness mengulang login dengan `force_login=1`, sehingga sesi browser `sam` milik Anda ter-log-out. | a) Boleh selama run (Anda sedang pergi): AC-1 diuji dengan data asli sam. b) Tidak boleh: fix diverifikasi dengan user uji tiruan (role Sales Supervisor, `employees.is_all_location=0`, lokasi `...413438`), sehingga root ≤3 baris dan A = 2 baris. | b, bila tiruan menghasilkan daftar pendek yang sama (cek dulu lewat HTTP). Jika tidak, a | rendah |

## Pemilik

**Pre-existing.** `VirtualTable` sudah ada di ArchivePage sejak `ksp-react` `0c3fc2049` (2025-08-14 "[ADD] Archive"), dan navigasi folder memakai instance yang sama sejak itu. ED-1025 `89b3b98a6` hanya membungkus tabel dalam kondisi `deniedBy` (`index.js:539-549`). Penyembunyian per lokasi (ED-1024 `b0efd0722`) benar dan bukan penyebab. Tidak ada spec in-review yang diubah. Developer membuat tiket Bug.
