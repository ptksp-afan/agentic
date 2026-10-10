---
key: ED-1072
epic: ED-1022
type: bug
title: Archive FE - klik ⋯ (dan item menunya) pada baris dokumen ikut memicu klik sel dokumen
status: approved
batch: ED-1072
fe_modules: [Archive]
new_fe_modules: []
be_modules: []                # BE tidak berubah
qa_model: sonnet
contract: null                # tidak ada endpoint yang berubah
depends_on: []                # tidak butuh hasil item lain; berkas FE dibagi dengan ED-1025/1026/1027/1029 rev (lihat Perbaikan)
---

## Ringkasan

Di tabel halaman Archive, klik tombol ⋯ pada baris **dokumen**, juga klik item menunya (Info, Pindahkan), ikut menjalankan
klik sel dokumen. Akibatnya baris terpilih, breadcrumb diganti lokasi dokumen, dan di mode Pilih dokumen ikut tercentang atau
terlepas. Efek samping: "+" (Add Folder / Store Document) memakai folder terakhir breadcrumb, sehingga sesudah ⋯ di hasil
pencarian target "+" pindah ke folder dokumen. Perbaikannya hanya di FE dan berupa satu aturan umum: klik pada elemen interaktif
di dalam sel (tombol, tautan, menu, dropdown, popover) tidak dihitung sebagai klik sel atau baris. Baris folder saat ini tidak
terkena, tetapi aturan yang sama berlaku untuk sel Nama folder dan tombol `V/T` dari ED-1027 rev.

## Reproduksi

Bukti: kode FE HEAD `7fa63869d`, ditambah pengamatan browser QA FE ED-1025 ronde 1 (`features/ED-1025-archive-folder-permission/qa-fe.md:65`, C-5).
Di jsdom menu ⋯ dokumen tidak muncul karena klik yang sama memanggil `setSelectedRowKeys` (`ksp-react/docs/specs/Archive/002-feat-folder-access-denied.md:85-87`).
Tidak diulang di browser (tanpa build staging); QA memverifikasi di Playwright.
Data/sesi: DB `api_sidomaju`, profil e2e `default`, dokumen D di folder F (BE mengirim `breadcrumbs` per baris, `ArchiveService.php:50`).

| # | Langkah | Harapan | Aktual |
|---|---|---|---|
| 1 | Buka F → klik ⋯ baris D | Menu Info/Pindahkan saja | Menu terbuka + `tr` D `ant-table-row-selected` + breadcrumb ditulis ulang dari `D.breadcrumbs` |
| 2 | Root → cari nomor D (hasil lintas folder) → ⋯ D | Breadcrumb tetap "Archive" | Breadcrumb "Archive > F"; "+" kini bertindak di F (`lastBreadcrumb` `ArchivePage/index.js:453,472,534`) |
| 3 | Lanjut klik item Info / Pindahkan | Drawer/modal saja | Klik sel dokumen terjadi lagi (pilihan + breadcrumb ditulis ulang) |
| 4 | Mode Pilih → ⋯ D → Info | Pilihan tetap | Klik ⋯: D tercentang (+1). Klik Info: D terlepas lagi |
| 5 | Klik kanan nomor transaksi di Nama D → "Open in new tab" | Tab aplikasi baru saja | Archive: D terpilih + breadcrumb berubah |
| 6 | Baris folder: ⋯ / item menu | Menu saja | Sesuai harapan (tanpa handler) |

## Akar masalah

| # | file:line (FE) | Mengapa |
|---|---|---|
| A-1 | `src/containers/Archive/ArchivePage/index.js:320-339` | Setiap kolom selain `name` diberi `onCell` → `onClickDocument` untuk baris dokumen. Kolom aksi ⋯ sudah ditambahkan `useColumns` (`:194-206`, `getActionColumn`) sebelum map ini, jadi sel ⋯ ikut mendapat `onClick` tersebut |
| A-2 | `src/containers/Archive/archive.function.js:390-400` | `Dropdown` + `Button` ⋯ tidak menghentikan propagasi, sehingga klik tombol naik ke `td` |
| A-3 | `node_modules/rc-menu/lib/MenuItem.js:167-175`; `rc-trigger/lib/index.js:353-354` | Menu = portal ke `body`, tetapi event sintetis React merambat menurut pohon React → klik item menu juga sampai ke `onClick` `td` |
| A-4 | `ArchivePage/index.js:288-308` | `onClickDocument` tidak memeriksa asal klik. Di mode Pilih ia men-toggle pilihan (`:293-300`); di mode biasa ia memanggil `setSelectedRowKeys` (`:302`) dan `setBreadcrumbs` (`:304-308`) |
| A-5 | `ArchivePage/index.js:391-396`; `components/Link/index.js:66-77`; `components/ModalReference/index.js:34-45` | Sel Nama dokumen memakai handler yang sama. Nama dokumen selalu berupa referensi bertautan (`ArchiveService.php:126-132`), dan tautan, menu konteksnya, serta isi popover "+N" ikut memicu. Hanya tag "+N" yang sudah `stopPropagation` (`ModalReference/index.js:42`) → K-2 |

Baris folder: `onCell` non-Nama mengembalikan `undefined` (`:335`). Sel Nama → `onClickName` (`:233-245`, `:315-318`) tanpa elemen
interaktif; ikon perisai hanya Tooltip hover. Picker Pindahkan/Bulk Move (`ArchiveTable/index.js:105-112`) tidak punya elemen interaktif di sel.

## Perbaikan (FE saja)

**Aturan AT-1:** di tabel halaman Archive, klik yang berasal dari elemen interaktif di dalam sel, atau dari konten portal milik
sel (menu dropdown, popover, menu konteks), tidak menjalankan aksi sel/baris: pilih baris, ganti breadcrumb, toggle mode Pilih,
buka folder. Klik di bagian sel lainnya tetap berperilaku seperti sekarang.

| Bagian | Perubahan (K-1 a) |
|---|---|
| `archive.function.js` | Helper murni baru `isCellActionClick(event)`. Bernilai `true` bila `event.target` berada di luar `event.currentTarget` (klik dari portal), atau bila `closest('a[href], button, input, select, textarea, [role="button"], [role="menuitem"], [data-cell-action]')` ditemukan di dalam sel. Teks, ikon, dan `Tag` status → `false` |
| `ArchivePage/index.js` `onClickDocument` (`:288`) dan `onClickName` (`:233`) | Baris pertama `if (isCellActionClick(e)) return;`, sebelum `preventDefault`/`stopPropagation`, agar aksi elemen (tautan, menu) tetap berjalan normal |
| `archiveActionColumn` | Tidak wajib berubah. Bila K-1 b/c dipilih, ikuti pola `Production/.../ProductionViewButton/index.js:28-29,61` |
| Test | `archive.function.test.js` untuk helper. `ArchivePage/index.test.js`: klik ⋯ baris dokumen → baris tidak terpilih dan breadcrumb tetap |

Ketentuan lintas item:
- **ED-1027 rev** (tombol `V/T` folder, `Button type="link"` `min-h-0 p-0`):
  - Wajib berupa antd `Button`, yaitu `<button>` asli, agar dikenali AT-1.
  - Diletakkan di kolom Document Verified, bukan di Nama.
  - Modal dibuka lewat ref halaman (`ArchiveVerificationModal`), tidak dirender di dalam sel.
  - `stopPropagation` miliknya (spec ED-1027 "Render kolom") boleh tetap.
- Elemen interaktif baru di sel tabel Archive: gunakan elemen bawaan (button/a/komponen antd), atau beri atribut `data-cell-action`.
- **Berkas bersama**:
  - `ArchivePage/index.js`: ED-1025 rev (tooltip `:359-366`), ED-1026 rev (mount modal opname), ED-1027 rev (render kolom + handler).
  - `archive.function.js`, `ArchivePage/index.test.js`, `archive.function.test.js`: ED-1027 rev, ED-1029 rev.
  - Tidak ada ketergantungan fungsi, jadi `depends_on: []`. Kerjakan sesudah item-item itu (urutan rank) agar tidak bentrok berkas. AC-8 diuji bila ED-1027 rev sudah masuk branch.

## Acceptance criteria

Semua `[FE]`. E2E: profil `default`, DB `api_sidomaju`, dokumen D di folder F dengan `breadcrumbs` terisi (fixture ED-1025
tanpa `breadcrumbs` menyamarkan gejala).

| AC | Given / When / Then | Cek |
|---|---|---|
| AC-1 | Archive di root, di dalam F, dan hasil pencarian terbuka tanpa error konsol/toast | e2e smoke `/archives` |
| AC-2 | Given di dalam F, tidak ada baris terpilih. When klik ⋯ D (atau fokus + Enter). Then menu Info/Pindahkan terbuka, `tr` D tanpa `ant-table-row-selected`, breadcrumb tetap "Archive > F" | e2e + unit (`ArchivePage/index.test.js`, tanpa membuka menu) |
| AC-3 | Given hasil pencarian di root. When ⋯ D → Info. Then drawer Info D terbuka, breadcrumb tetap "Archive", D tidak terpilih, item "+" tetap item root | e2e |
| AC-4 | When ⋯ D → Pindahkan. Then modal Pindahkan D terbuka, breadcrumb dan pilihan tidak berubah, juga sesudah Batal | e2e |
| AC-5 | Given mode Pilih dengan 0 terpilih. When ⋯ D, lalu Info. Then jumlah terpilih tetap 0 di setiap langkah | e2e |
| AC-6 (regresi) | Klik teks Nama di luar tautan, Tipe, atau ikon Document Verified D → D terpilih dan breadcrumb = lokasi D, seperti sebelumnya. Di mode Pilih, klik sel D tetap men-toggle pilihan | e2e + unit |
| AC-7 (regresi folder) | Klik Nama folder, termasuk ikon perisai, → folder terbuka. ⋯ folder → menu saja, folder tidak terbuka. Info/Lihat/Opname tetap bekerja. Mode Pilih: folder terpilih tetap terkunci | e2e |
| AC-8 (ED-1027) | Given ED-1027 rev sudah di branch. When klik `V/T` folder. Then modal verifikasi folder terbuka; folder tidak terbuka; pilihan dan breadcrumb tetap; di mode Pilih pilihan tetap (= ED-1027 AC-27) | e2e (N/A bila belum masuk) |
| AC-9 (K-2 a) | Klik kanan nomor transaksi di Nama D → "Open in new tab" → tab aplikasi baru ditambahkan, dan Archive: D tidak terpilih, breadcrumb tetap | e2e |
| AC-10 | `isCellActionClick` `true` untuk target di dalam `button`, `a[href]`, `[role=menuitem]`, `[data-cell-action]`, dan untuk target di luar sel. `false` untuk teks, ikon, dan `Tag` status di dalam sel | unit (`archive.function.test.js`) |

## Keputusan untuk developer

| K | Pertanyaan | Opsi | Rekomendasi | Risiko |
|---|---|---|---|---|
| K-1 | Bagaimana klik dari elemen interaktif dicegah memicu sel? | a) Satu pengaman umum `isCellActionClick` di `onClickDocument` + `onClickName`. Mencakup ⋯, item menu (portal), `V/T` ED-1027, tautan/menu konteks di Nama, dan dropdown berikutnya. b) `stopPropagation` per elemen seperti `ProductionViewButton/index.js:28-29,61` (tombol ⋯ + `menu.onClick` `domEvent.stopPropagation()`). Paling kecil, tetapi setiap elemen baru harus ingat, dan tautan/popover di Nama tetap memicu. c) a + b | a | rendah |
| K-2 | Apakah tautan nomor transaksi, menu konteksnya, dan isi popover "+N" di sel Nama dokumen ikut AT-1? Jira hanya menyebut ⋯ | a) Ya: klik tautan hanya bernavigasi, tidak memilih dan tidak mengganti breadcrumb (otomatis bila K-1 a). b) Tidak, hanya ⋯ + menunya | a | rendah |

**Jawaban Gate 1 (2026-10-08):** K-1 **a** - satu pengaman umum `isCellActionClick` di `onClickDocument` + `onClickName` (developer sempat mengira sudah tertangani `disableDefaultRowExpand`; dicek: prop itu hanya mengatur expand baris `onRow` di layar kecil dan tidak dipakai `ArchivePage`, jadi bug tetap). K-2 **a** - tautan nomor transaksi, menu konteksnya dan popover "+N" ikut aturan AT-1.

## Di luar cakupan

- Perilaku klik sel dokumen itu sendiri tetap: memilih baris + breadcrumb lokasi, dan "+" mengikuti breadcrumb.
- Picker Pindahkan/Bulk Move (`ArchiveTable`): tidak punya elemen interaktif di sel. AT-1 berlaku bila kelak ditambahkan.
- Tombol `V/T` dan modalnya (ED-1027), tooltip perisai (ED-1025), item "+" (ED-1029). BE tidak berubah, tanpa `contract.md`.

## Subtask (usulan)

| Judul | Layer | AC | Module |
|---|---|---|---|
| [FE] Archive - klik elemen interaktif di sel tidak memicu klik sel/baris (⋯, menu, tautan) | FE | 2-7, 9, 10 | Archive |
| [QA] Skenario klik ⋯/menu/tautan baris dokumen, mode Pilih, regresi klik sel & folder | QA | 1-9 | Archive |
