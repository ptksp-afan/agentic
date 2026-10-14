---
key: ED-1150
epic: ED-1022
type: bug
title: Archive - baris folder hilang sesudah navigasi breadcrumb pada daftar pendek
status: approved
batch: ED-1150
fe_modules: [Archive]
new_fe_modules: []
be_modules: []
qa_model: sonnet
contract: null
depends_on: []
---

## Ringkasan

Di Archive, sesudah pindah folder (klik nama folder atau breadcrumb), tabel kadang tidak menampilkan baris terakhir, padahal API mengirimnya. Reload browser memperbaikinya. Contoh dari developer (user `sam`): root -> Folder A -> Folder B -> breadcrumb Folder A hanya menampilkan Folder B (Folder C hilang); lalu breadcrumb root tidak menampilkan Folder A. Perbaikan hanya di FE: setiap kali folder yang dibuka berganti, tabel virtual dimulai dari keadaan awal.

## Reproduksi

| Langkah | API (`GET api/v5/document-archive/archives`, sam, `api_sidomaju`) | Layar (laporan developer) |
|---|---|---|
| root | total=3: Backup Arsip, CABANG - SEMARANG, Folder A | benar |
| Folder A | total=2: Folder B, Folder C | benar |
| Folder B | total=398, 10 baris per halaman | benar |
| breadcrumb Folder A | total=2: Folder B, **Folder C** | hanya Folder B |
| breadcrumb root | total=3, **Folder A** ada | Folder A hilang |

- HTTP diulang 9 langkah sebagai `QA_USER3` pada 14 Okt, tanpa `force_login`. Respons selalu sama, jadi masalahnya di FE. Detail: `epics/ED-1022-review3-bug.md`.
- Data: Folder A dan Folder C `is_all_location=1`. Folder B lokasi `...413438` (lokasi sam). Izin A untuk sam view+store, B penuh.
- `kevinsudjadi.adm` tidak kena. Hak arsip kedua role sama kecuali `Folder Permissions`. Bedanya panjang daftar: root kevin 10 baris per halaman, root sam 3 baris. Semua daftar sam (≤3 baris) di bawah `scroll.y` 600, tanpa scrollbar.
- Reproduksi browser dijalankan saat run sebagai `sam` (K-4 a), dan AC-1 harus gagal dulu di baseline.

## Akar masalah

| # | Temuan | Bukti |
|---|---|---|
| 1 | FE tidak memfilter baris; GET tidak di-cache | `ksp-react/src/containers/Archive/ArchivePage/index.js:171-173`, `archive.function.js:57-71`, `src/hooks/apiHook.js:376-381` |
| 2 | Satu instance `VirtualTable` dipakai untuk semua folder: navigasi hanya `run({ idArchive })`, tabel tidak di-remount | `ArchivePage/index.js:236-258`, `:542-549`; picker `archive-components/ArchiveTable/index.js:74-95`, `:174` |
| 3 | Konteks virtualisasi dibuat sekali per mount dan membawa `_offset_head/_offset_tail/row_count/row_height` dari daftar sebelumnya | `src/components/VirtualTable/index.js:21` (`useVT(..., [])`) |
| 4 | `virtualizedtableforantd4@1.2.1` me-render `rows.slice(head, tail)` dari state lama. `tail` hanya dipotong saat daftar mengecil. Saat jumlah baris berubah, koreksinya menunggu recompute asinkron atau scroll native, dan daftar tanpa scrollbar tidak pernah di-scroll. Konteks baru (reload) mulai dari INIT dan benar | `node_modules/virtualizedtableforantd4/dist/esm/vt.js:648-659`, `:690`, `:303-306`, `:560`, `:382-457`, `:615-630` |

Frame persis yang melewatkan recompute belum dilacak. AC-1 harus gagal di baseline (FE `d56ce3962`) sebelum fix dianggap terbukti.

## Perbaikan (FE saja)

Aturan (B2 a + K-5 a): `VirtualTable` mulai dari keadaan awal setiap kali tabel **menampilkan daftar lain**, yaitu folder, halaman, ukuran halaman, atau pencarian/scan berbeda. Refresh daftar yang sama (Reload, sesudah tambah/ubah/hapus/pindah) **tidak** me-remount tabel, supaya posisi scroll tetap.

| Berkas | Perubahan |
|---|---|
| `src/containers/Archive/ArchivePage/index.js` (`useTable` `:118-144`, tabel `:542`) | `key` tabel = kunci daftar dari parameter **respons yang sedang tampil**: `idArchive ?? 'root'` + `search` (JSON) + `page` + `pagination`. Disimpan di `onSuccess(data, [params])` milik `useTable`, bersama `onSelectScanResult` yang sudah ada. Jangan diambil dari parameter request saat `run()` (lihat catatan). `selectedKeys`, `selectedRecords`, `selectedRowKeys`, mode Pilih dan form filter tetap di `ArchivePage`, jadi tidak di-reset |
| `src/containers/Archive/archive-components/ArchiveTable/index.js` (`useTable` `:33-36`, tabel `:174`) | Sama untuk picker (Move / Bulk Move) |
| Unit test `ArchivePage/index.test.js` (dan picker) | Lihat AC-7 |

Catatan: key yang berganti saat request (data lama masih tampil) membuat tabel baru dengan data lama, lalu data baru masuk ke instance itu dan masalahnya terulang. Jadi key harus berganti bersama datanya. React 18 menggabungkan kedua state update ke satu render.

Tidak diubah: `components/VirtualTable` global, versi library, BE.

## Acceptance criteria

Sesi: `sam` = `QA_USER3` (K-4 a), data asli sam. Prasyarat dicek lewat HTTP di awal: root sam 3 baris termasuk Folder A, Folder A 2 baris (Folder B, Folder C), Folder B ≥10 baris. Tidak terpenuhi = AC BLOCKED (bukan FAIL), tanpa membuat fixture.

| AC | Tag | Given / When / Then | Cek |
|---|---|---|---|
| AC-1 | [FE] | When root -> Folder A -> Folder B -> breadcrumb Folder A -> breadcrumb root (tanpa scroll, tanpa reload). Then setiap langkah: jumlah `tr.ant-table-row` = `result.data.length` respons list terakhir dan nama barisnya sama (A: Folder B + Folder C; root: Folder A ada). Dijalankan dulu di baseline FE `d56ce3962`: diharapkan gagal. Bila lolos di baseline, QA mencatat "tidak tereproduksi di baseline" di laporan, fix tetap diuji, dan AC-8 yang menentukan | e2e |
| AC-2 | [FE] | Sama dengan AC-1, ditambah klik nama folder bolak-balik (root -> A -> B -> A -> root dua kali). Bila ada folder yang ditolak View untuk sam (HTTP 403 `ARCHIVE407`): buka lalu "Back to Archive", baris root = respons. Tidak ada folder seperti itu = bagian ini SKIP dengan catatan | e2e |
| AC-3 | [FE] | (K-5 a) Di Folder B: halaman 1 -> halaman terakhir (398 = 8 baris) -> halaman 1, lalu ganti ukuran halaman 10 -> 20 -> 10. Then baris = respons setiap kali | e2e |
| AC-4 | [FE] | (K-5 a) Dari Folder A: cari/scan sebuah nomor transaksi -> hasil = respons. Reset pencarian -> isi Folder A (2 baris) utuh | e2e |
| AC-5 | [FE] | Refresh daftar yang sama (tombol Reload di Folder B sesudah scroll ke bawah) tidak me-remount tabel: posisi scroll tetap dan baris = respons | e2e |
| AC-6 | [FE] | Mode Pilih: pilih di Folder A, pindah ke Folder B lewat breadcrumb, pilih lagi. Then keduanya tetap terpilih dan modal Bulk Move menampilkan keduanya (regresi `preserveSelectedRowKeys`, ED-1072). Modal ditutup tanpa simpan | e2e |
| AC-7 | [FE] | Picker `ArchiveTable` di modal Move: root -> Folder A -> Folder B -> breadcrumb A -> breadcrumb root, baris = respons. Modal ditutup tanpa simpan. Sam tanpa akses Move (tombol tidak ada / 403) = jalankan sebagai `QA_USER` dengan folder yang sama | e2e |
| AC-8 | [FE] | Layar Archive, modal Move dan modal Bulk Move terbuka tanpa error konsol | e2e |
| AC-9 | [FE] | Unit: key berubah saat respons untuk folder/halaman/ukuran halaman/pencarian lain tiba; tetap saat refresh daftar yang sama; tidak berubah saat `run()` dipanggil sebelum respons tiba | unit |
| AC-10 | Gate 2 | Developer mengulang skenario aslinya sebagai `sam` di browser | manual (Gate 2) |

## Keputusan untuk developer

Semua diputuskan di Gate 1 (2026-10-14). Tidak ada pertanyaan terbuka.

| # | Keputusan | Pilihan |
|---|---|---|
| B2 | Cara fix | **a**: `key` per daftar di `VirtualTable` ArchivePage dan picker `ArchiveTable`. Library dan komponen global tidak diubah |
| B3 | Tiket | **a**: Bug terpisah (ED-1150) |
| K-4 | User uji browser | **a**: `sam` (`QA_USER3`) dengan `force_login=1` selama run. Developer tidak memakai sesi sam saat itu. Semua sesi di-log-out sesudahnya |
| K-5 | Cakupan key | **a**: key juga berganti saat ganti halaman, ukuran halaman, dan pencarian/scan, bukan hanya ganti folder |

## Data uji

Tidak perlu fixture `QA1150-` dan tidak perlu user tiruan. Data asli sam sudah memenuhi syarat daftar pendek (root 3, Folder A 2, Folder B 398 dokumen = 10 baris per halaman). Fixture hanya akan menambah folder di root semua user.

| Data | QA menyentuh | Pemulihan |
|---|---|---|
| Sesi `sam` (`QA_USER3`) | Login + bind dengan `force_login=1` (mencabut token sam lain di `api_sidomaju`) | Semua token run di-log-out di akhir (juga bila run mati), pencabutan dicek di `oauth_access_tokens`. Developer login ulang |
| Sesi `QA_USER` (hanya AC-7, bila sam tanpa Move) | Login biasa | Di-log-out di akhir |
| Folder A/B/C, Folder B (398 dokumen), data sam | Hanya baca. Modal Move/Bulk Move ditutup tanpa simpan | - |

Tidak ada baris DB yang ditulis, dan tidak ada tabel yang dikosongkan atau di-reset.

## Di luar cakupan

- Perbaikan `components/VirtualTable` global atau upgrade/patch `virtualizedtableforantd4` (list lain tidak dilaporkan).
- Aturan lokasi ED-1024 (folder di lokasi lain memang disembunyikan, sesuai desain) dan BE secara umum.
