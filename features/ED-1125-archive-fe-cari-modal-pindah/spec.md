---
key: ED-1125
epic: ED-1022
type: bug
title: Archive FE - modal Pindahkan / Pilih Folder pindah massal - Cari memuat ulang aplikasi (form bersarang) dan scan barcode mencari di tabel halaman
status: approved
batch: ED-1125
fe_modules: [Archive]
new_fe_modules: []
be_modules: []
qa_model: sonnet
contract: null
depends_on: []
---

## Ringkasan

Dua bug di modal **Pindahkan** dan langkah **Pilih Folder** pindah massal:

- **(A)** Enter atau tombol **Cari** di kolom Cari memuat ulang seluruh aplikasi. Akibatnya folder yang hanya terjangkau lewat pencarian tidak bisa dipilih.
- **(B)** Scan barcode saat modal terbuka mencari di tabel halaman di belakang modal.
- **(C, Bug ED-1153)** Letakkan, Serahkan dan Terima Dokumen: Kembali/✕ di review menyalakan scanner halaman selagi modal terbuka.

Sesudah perbaikan, Cari dan scan bekerja di picker modal, dan modal tetap terbuka. Perbaikan FE saja, di berkas Archive. Komponen `Search` dan hook `useBarcodeScanner` tidak diubah (A-6). Penolakan siklus ED-1069 (`ARCHIVE404`) jadi bisa dipicu dari UI.

## Reproduksi

| | |
|---|---|
| A | `QA_USER` @ `api_sidomaju`: ⋯ Pindahkan → ketik di Cari modal → Enter (atau ikon filter → **Cari**); sama di pindah massal → Lanjut. Diharapkan: GET `document-archive/archives?search={"query":…}`, modal tetap. Aktual: navigasi GET asli (CDP `formSubmissionGet http://host/?`), aplikasi dimuat ulang. Bukti: e2e ED-1069 "karakterisasi B-FE-1" (`ED-1069-validasi-pindah.spec.js:449-511`); struktur yang sama masih ada di FE `cf37552dd`; repro analyst 2026-10-14 (React 18.2.0 + antd 4.24.16 dari `node_modules` FE, Chrome headless): form di dalam form = 1 navigasi, tanpa form luar = 0 navigasi |
| B | Modal terbuka, fokus di luar input, scan USB → tabel halaman dicari (`ED-1069/qa-fe.md:28`). Dari kode `cf37552dd`: `ArchivePage/index.js:584-605` merender kedua modal tanpa `qrScanSearchRef`, jadi scanner Search halaman (`:521`) tetap aktif. Picker ikut mencari (scanner `Search` sendiri) |
| C | Letakkan Dokumen → scan → review → Kembali/✕ (modal tetap terbuka) → scan → tabel halaman ikut dicari (`qa-fe.md` O-1, e2e AC-17). Serahkan/Terima Dokumen: dari kode, sama (kode tak dikenal → review) |

## Akar masalah

- **A:** `<form>` modal (`ArchiveMoveModal/ArchiveMoveModalForm.js:68`; `ArchiveBulkMoveModal/ArchiveBulkMoveModalForm.js:177`, langkah Pilih Folder `:170`) → `ArchiveTable` (`archive-components/ArchiveTable/index.js:158-170`) → `<form>` Search (`components/Search/Search/index.js:125`, submit tersembunyi `:185`; Cari `htmlType="submit"` di `SearchContent/index.js:13`, dirender di dalam search bar `FilterPopover/index.js:27`). Di Chrome, event `submit` dari form bersarang berhenti di `<form>` induk (dilacak; tanpa `stopPropagation` dari JS). React 18 mendengarkan `submit` di container root/portal, jadi `preventDefault` milik rc-field-form (`rc-field-form/lib/Form.js:135-139`) tidak jalan dan browser men-submit form secara asli. Hook scanner tidak terlibat karena fokus ada di `INPUT` (`hooks/appHook.js:104`).
- **B:** modal lain mematikan scanner halaman saat dibuka dan menyalakannya lagi saat ditutup (`ArchiveDocumentModal/index.js:17-24`; HandOver/Receive `:16,21`; `ArchiveOpnameModal/index.js:113,120`). Pindahkan dan pindah massal tidak melakukannya.
- **C:** `onCloseAdd` menyalakan scanner halaman sebelum memeriksa langkah, padahal di review ia hanya kembali ke langkah scanner: `ArchiveDocumentModal/index.js:22-30`; di FE `800e42faf` sama persis di `ArchiveDocumentHandOverModal/index.js:20-28` dan `ArchiveDocumentReceiveModal/index.js:20-28`. Langkah `result` (sudah diserahkan/di folder/diterima) tidak terkena: Batal menutup modal.

## Perbaikan (FE saja)

| Berkas | Perubahan |
|---|---|
| `ArchiveMoveModalForm.js:68`, `ArchiveBulkMoveModalForm.js:177` | (A, K-1 a) `<Form … component={false}>`; `form`, `initialValues`, `onFinish` tetap |
| `ArchiveTable/index.js:63-67` | (K-3 a) `_onFinishFilter` seperti `ArchivePage/index.js:224-237`: `idArchive: null`, kecuali search hanya `showRelatedTransaction` |
| `ArchivePage/index.js:584-605` | (B) teruskan `qrScanSearchRef` ke `ArchiveMoveModal` dan `ArchiveBulkMoveModal` |
| `ArchiveMoveModal/index.js:16-23`, `ArchiveBulkMoveModal/index.js:25-26` | (B) `onAdd` → `setActiveScanner(false)`; `onCloseAdd` → `setActiveScanner(true)`. Pola `ArchiveDocumentModal/index.js:17-24`. Semua jalan tutup lewat `onCloseAdd`: Batal/✕ = `onCancel`, sukses = `useStore` memanggil `onCancel` (`hooks/apiHook.js:471`) |
| `ArchiveDocumentModal/index.js:22-30`, `ArchiveDocumentHandOverModal/index.js:20-28`, `ArchiveDocumentReceiveModal/index.js:20-28` | (C) `setActiveScanner(true)` hanya di cabang `!isReview` (modal benar-benar tertutup). Sukses: `onCloseAdd` kedua (`ArchivePage/index.js:580,615,625`, 500 ms `apiHook.js:456-459`) menutup dan menyalakan |
| `index.test.js` `ArchiveMoveModal`; baru: `ArchiveBulkMoveModal`, `ArchiveDocumentModal`, `ArchiveDocumentHandOverModal`, `ArchiveDocumentReceiveModal` | AC-10, AC-18 |
| `docs/specs/Archive/026-fix-*.md`, `_module.md` | pola `025` |

- **A:** pola `component={false}` sudah ada di `ArchiveOpnameModal/index.js:456` (dengan `ModalAdd`). Pindahkan tetap jalan karena `useStore.onMenuClick` memanggil `form.submit()` instance (`apiHook.js:511-514`). Bila tampilan berbeda di Gate 2, pakai `component="div"`. "Search tanpa `<form>`" ditolak: Enter jadi men-submit form modal, artinya menjalankan Pindahkan.
- **B:** perilaku scan di dalam modal mengikuti pola riwayat opname (`docs/specs/Archive/010-feat-opname-history-drawer.md:92-93`, tes UI 19; ED-1028 BR-14). Scanner halaman mati, sedangkan scanner bawaan `Search` picker tetap aktif. Jadi scan mengisi Cari picker lalu mencari, sama dengan Enter.

## Acceptance criteria

Fixture e2e `0QA125-` sendiri: A (A>B>C), M, M2, T di root (pola `ED-1069/qa/e2e/fixture.php`); profil `[default]`, bahasa ID. Definisi yang dipakai di tabel:

- **0 navigasi**: CDP `Page.frameRequestedNavigation` kosong dan penanda `window` bertahan.
- **GET search**: `GET document-archive/archives`, `search={"query":"<teks>"}`, `page=1`, 200.
- **Scan**: fokus di luar input (klik judul modal), ketik kode dengan jeda < 30 ms per karakter, lalu Enter.
- **Halaman utuh**: baris, breadcrumb dan teks Cari halaman sama seperti sebelum aksi.

| AC | Tag | Given / When / Then | BR | Cek |
|---|---|---|---|---|
| AC-1 | [FE] | ⋯ Pindahkan A → `0QA125-C` + Enter → 0 navigasi; 1 GET search; tabel modal = respons (C ada, T/M tidak); modal terbuka, judul "Pindahkan - 0QA125-A", teks Cari tetap; tanpa POST put-in | 1,3 | e2e |
| AC-2 | [FE] | Sama dengan AC-1, lewat ikon filter → klik **Cari**; popover menutup | 1 | e2e |
| AC-3 | [FE] | Pilih M, M2 → Pindahkan → Lanjut → `0QA125-T` + Enter → 0 navigasi; GET search; tabel = respons; tetap di Pilih Folder, judul "Pindahkan - 2 dipilih"; Kembali → Konfirmasi masih M, M2; mode Pilih tetap | 1,3 | e2e |
| AC-4 | [FE] | Sama dengan AC-3, lewat tombol **Cari** | 1 | e2e |
| AC-5 | [FE] | Kedua modal: pilih T dulu, lalu ketik + Enter di Cari → tanpa POST put-in, baris sumber di DB sama, T tetap terpilih, Pindahkan aktif | 2,3 | e2e + DB |
| AC-6 | [FE] | (a) A → cari C → Pindahkan → 400 `ARCHIVE404`, toast id-ID, modal tetap, A tidak pindah. (b) M → cari T → 200, M di bawah T (DB). (c) Massal [M, M2] → cari T → 200. Dipulihkan fixture | 4 | e2e + DB |
| AC-7 | [FE] | Picker di A > B, cari `0QA125-T` → request tanpa `idArchive`/`id_archive`, breadcrumb "Archive", tabel = respons; reset (X) → root | 5 | e2e |
| AC-8 | [FE] | Regresi Search halaman: Enter dan Cari → GET search, 0 navigasi | 6 | e2e |
| AC-9 | [FE] | Root, modal Pindahkan (folder & dokumen), pindah massal 2 langkah, sebelum/sesudah cari dan scan: tanpa error konsol, 5xx, toast galat; screenshot desktop + 390 px | - | e2e |
| AC-10 | [FE] | Unit kedua modal: (a) 1 `form`, `form form` = 0; (b) submit Search → GET `search.query`; (c) pilih baris → Pindahkan → POST put-in `{idArchives, idArchiveParent}`; (d) buka → `setActiveScanner(false)`, Batal dan sukses → `(true)` | 1,2,7,8 | unit |
| AC-11 | [FE] | e2e ED-1069: "karakterisasi B-FE-1" (`:449`) dibalik jadi asersi (1 form, tidak bersarang, 0 navigasi, GET search, modal tetap); AC-13 single/massal memakai ketik sungguhan, bukan `armPickerSearch` (`:447-448`). Suite lulus `--workers 1`; karakterisasi lama yang gagal **bukan** regresi | 1,4 | e2e |
| AC-12 | [FE] | Regresi (K-4 a): ED-1025 AC-3 A/C, AC-21 B, AC-40 A id/en; ED-1027 AC-15; ED-1072 AC-4/5; ED-1150 AC-6/7/8 (`sam`); ED-1026 GAMMA (scan opname); ED-1028 AC-22 (scan riwayat); Jest Archive | 6,9 | e2e + unit |
| AC-13 | Gate 2 | Developer mencoba Enter/Cari di kedua modal dan scan dengan scanner USB asli; tampilan modal sama seperti sebelumnya | - | manual (Gate 2) |
| AC-14 | [FE] | Modal Pindahkan A, scan `0QA125-T` → halaman utuh; tepat 1 GET search (dari picker); Cari picker berisi kode, tabel modal = respons; judul dan pilihan modal tetap | 7,3 | e2e |
| AC-15 | [FE] | Pindah massal [M, M2]: scan di Konfirmasi → halaman utuh, langkah dan item tetap; scan di Pilih Folder → seperti AC-14 | 7,3 | e2e |
| AC-16 | [FE] | Sesudah modal ditutup lewat Batal, ✕, dan sukses (single & massal), scan → tabel halaman mencari (GET search, Cari halaman berisi kode) | 8 | e2e |
| AC-17 | [FE] | (ED-1153) Letakkan Dokumen: scan → review berisi kode. Kembali → langkah scanner, modal terbuka; scan → review kode baru, 0 GET search, halaman utuh. Juga ✕. Tutup modal (Batal) → scan → 1 GET search, Cari halaman = kode | 8,9 | e2e |
| AC-18 | [FE] | Unit `onCloseAdd` Letakkan, Serahkan dan Terima Dokumen: (a) di review → langkah scanner, modal terbuka, tanpa `setActiveScanner(true)`; (b) di scanner → tertutup, `(true)` sekali; (c) simpan sukses → tertutup, `(true)`; (d) Serahkan/Terima di `result` → tertutup, `(true)` | 8 | unit |
| AC-19 | [FE] | (C) "+" → Serahkan Dokumen: scan `0QA125-HO1` (tak dikenal) → GET modal `search={"name":…}`, langkah review. Kembali → scanner; scan `0QA125-HO2` → hanya GET `{"name":…}` milik modal, review berisi kode baru, 0 GET search, halaman utuh. Juga ✕. Batal → scan → 1 GET search, Cari halaman = kode | 8,9 | e2e |
| AC-20 | [FE] | Sama dengan AC-19 untuk Terima Dokumen (`0QA125-RC1`/`RC2`) | 8,9 | e2e |

BR:
1. Enter/Cari di modal mencari di tabel modal tanpa muat ulang (seperti `ArchivePage/index.js:510-521`).
2. Cari tidak pernah menjalankan Pindahkan (`ArchiveMoveModal/index.js:34-44`, `ArchiveBulkMoveModal/index.js:64-85`).
3. Pencarian/scan tidak mengubah modal: terbuka, judul, langkah, item, pilihan (`ArchiveMoveModal/index.js:13-25`, `ArchiveBulkMoveModal/index.js:22-39`).
4. Hasil cari dipilih dengan aturan biasa (`ArchiveMoveModalForm.js:19-23`, `ArchiveBulkMoveModalForm.js:101-105`); turunan ditolak BE (ED-1069).
5. Cari picker lintas folder (`ArchiveService.php:41-48,114-119`); breadcrumb dan reset seperti halaman (K-3 a).
6. `components/Search` dan layar lain tidak berubah.
7. Selama kedua modal terbuka, scan tidak mencari tabel halaman; scan diterima `Search` picker (pola `010-feat-opname-history-drawer.md:92-93`).
8. Scanner halaman aktif lagi **hanya** saat modal benar-benar tertutup (Batal, ✕, sukses), bukan saat kembali ke langkah sebelumnya (juga Letakkan, Serahkan, Terima Dokumen; ED-1153).
9. Selama Letakkan, Serahkan atau Terima Dokumen terbuka, scan hanya diterima modal. Scan opname dan riwayat tidak berubah.

## Keputusan untuk developer

Semua dijawab di Gate 1 ronde 5 (2026-10-14). Tidak ada pertanyaan terbuka.

| # | Pertanyaan | Jawaban |
|---|---|---|
| K-1 | Lokasi fix form bersarang | **Dijawab: a (2026-10-14)**: `component={false}` di 2 form modal Archive; `components/Search` tidak diubah |
| K-2 | Form update Customer (`CustomerForm:575-593`) kemungkinan punya pola yang sama | **Dijawab: c (2026-10-14)**: diabaikan, tanpa tiket |
| K-3 | Cari di picker dari dalam folder | **Dijawab: a (2026-10-14)**: sama dengan halaman (breadcrumb "Archive", reset ke root) |
| K-4 | Regresi e2e termasuk ED-1150 (`sam`, `force_login=1`) | **Dijawab: a (2026-10-14)**: AC-12 penuh |

## Data uji

- Ditulis: hanya `archives` `0QA125-*` (+ `archive_locations` bila perlu), di-`restore` per test dan dihapus persis saat teardown. Suite regresi memakai fixture dan teardown-nya sendiri.
- Sesi: QA_USER, QA_USER2, `sam` (K-4), dengan `force_login` bila perlu (P-5); semua di-log-out dan dicek. Bahasa QA_USER harus ID; bila bukan, diubah sementara lalu dipulihkan (ED-1149 BR-4). AC-19/20 butuh permission `Handover Document`/`Receive Document`; bila QA_USER tidak punya, pakai role preset QA, dipulihkan (ED-1149 BR-1/BR-4).
- Tidak ada tabel yang dikosongkan atau di-reset, dan data developer tidak ditulis.

## Di luar cakupan

- `components/Search`, `useBarcodeScanner` (A-6: Enter/Spasi pada tombol terfokus tetap diblokir, termasuk Cari di modal, karena scanner `Search` picker tetap aktif), layar non-Archive (K-2 c), BE, kontrak.
- `armPickerSearch` di e2e ED-1025 (`:1707`) tetap sah. `qa-fe.md` item lain tidak diedit (ED-1149 BR-7).
- ED-1124 dan ED-1070 (BE) tidak saling bergantung dengan item ini. A-1..A-6 tidak dibuka ulang.
