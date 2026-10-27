---
epic: ED-1022
mode: epic-check
round: 2
items: [ED-1025, ED-1026, ED-1027, ED-1028, ED-1029, ED-1069, ED-1070, ED-1071, ED-1072]
---
# Gate 1 ronde 2 ED-1022 - cek lintas item

Ringkas: kode pesan tanpa tabrakan; 9 konflik; temuan baru tinggi: id permission bentrok branch lain (EPIC K-4); depends_on +1;
urutan = rank; 33 pertanyaan (6 tinggi, 27 rendah). Spec tidak diubah. EPIC K lanjut dari ronde 1 (K-1 a, K-2 b tetap).

## 1. Kepemilikan
| # | Hal | Item | Pemilik → catatan |
|---|---|---|---|
| O-1 | Aturan tanpa-siklus put-in + `PUT archives/{id}` | 1069, 1070 | 1069 (K-3 selesai di sini, tidak ditanyakan); 1070 AC-8 regresi |
| O-2 | `ArchiveService::index` | 1027, 1070, 1071 (1069: `relatedTransaction`) | 1027 hitungan/kolom; validasi awal (sesudah cek folder `:36-43`) 1070; 1071 sesudahnya |
| O-3 | Peta saringan `ColumnDisplaySettingService` | 1027, 1028 | 1027 struktur peta + saring kolom; peta wajib menerima entri **seluruh modul**; 1028 entri `documentArchiveOpnameHistory` (403) + saring `index` |
| O-4 | Request Archive (Update, PutIn, CreateFolder, RenameFolder, UserPermission), 2x `UniqueNameRule`, `SelfFolderRule`, `UserPermissionService` | 1025, 1029, 1069, 1071 | logika = item lebih awal (1025 `GE0114`; 1069 induk/siklus/nama; 1029 `created_by`); 1071 hanya guard di depan. Urutan 404/403, `GE0114`, 422, 400 |
| O-5 | `Routes/DocumentArchive/api.php` | 1026, 1027, 1028, 1029 | baris berbeda; komentar `:39` "baca sesi = List Archive" + 3 route GET `opnames*` milik 1028 |
| O-6 | `SelectArchiveService` | 1028 `opnameUser`, 1069 `location`, 1071 `user`/`location` | method berbeda; 1071 #5 mengikuti 1069 K-4 |
| O-7 | `OpnameService` | 1026, 1028 | 1026 (store/scan/preview/`readable`/`scope()`); 1028 saringan D-1 hanya di `show`, bukan `preview` |
| O-8 | `ArchiveOpnameResult`, `getScopeTag` (+test) | 1026, 1027, 1028 | 1026; lainnya regresi (konsisten) |
| O-9 | FE `ArchivePage/index.js`, `ArchiveDrawer`, `archive.locale.js`, `permissions.FolderPermissions` | 1025, 1026, 1027, 1029, 1072 | urutan rank; 1072 terakhir; V/T 1027 = antd `Button` (AT-1); tab Izin 1025 dulu, tab Verification 1027 ikut pola; konstanta 1025 |
| O-10 | `archive.function.js` (+test) | 1025, 1026, 1027, 1029, 1072 | predikat "kehilangan akses sendiri" milik 1025 (ekspor per baris); 1029 memakainya, bukan menyalin |
| O-11 | `permission_salesman.sql` | 1025, 1026 | 1025 (termasuk EPIC K-4 bila a) |

Kode pesan (`en_EN.php:3345-3381`, `id_ID.php`, `git log --all -S` semua branch):
| Kode | Status | Pemilik |
|---|---|---|
| `ARCHIVE211/214/413` | dihapus, tidak dipakai ulang; sisa rujukan hanya QA 1026 | 1026 |
| `ARCHIVE404` | teks diperluas (K-2 a); hanya `SelfFolderRule.php:39`, FE tidak merujuk | 1069 |
| `ARCHIVE419` (`420`) | baru bila 1069 K-2 b / K-6 a; bebas | 1069 |
| `GE0747` | baru (K-4 a); terakhir `GE0746`, lalu `GE0800`; bebas | 1071 |

## 2. Asumsi bertentangan
| # | Konflik | Penyelesaian |
|---|---|---|
| C-1 | 1027 K-9 = 1028 K-8 | satu pertanyaan: EPIC K-3 |
| C-2 | 1026 BR-2, §4, contract baris 3-4, komentar route: "baca sesi = `List Archive`" vs EPIC K-3 a | 1026 menulis "permission baca = 1028"; ronde 1 EPIC K-1 a jadi "pemegang `Opname Document`" |
| C-3 | 1026 K-14 ↔ 1028 K-10; contract 1026 (`:56-60,107`) & 1027 (`:36,46-48,147`) "`first_name`/`other_count` tetap dikirim" | tanya bersama; bila K-10 b, 1026 (pemilik `scope()`) menghapus dan kedua contract diperbarui |
| C-4 | 1025 K-16 ↔ 1029 K-8 (pemicu sama, `created_by` per node) | tanya bersama; O-10 |
| C-5 | 1025 K-12/K-15 ↔ 1029 BR-7/9/13, `assertCanManage` | 1029 sudah bersyarat |
| C-6 | 1025 K-14 a (baris otomatis juga superadmin) ↔ 1029 BR-3 (superadmin tak ada di daftar User) | baris itu tak tampil di by User; aman (bypass); disebut saat K-14 |
| C-7 | 1027 AC-24 "`documentArchiveOpnameHistory` tidak berubah" ↔ 1028 AC-28 (403) | 1027 AC-24 contoh `customer` saja |
| C-8 | 1026 contract §6 "`warnings` = nilai tersimpan" ↔ 1028 BR-16 (D-1) | §6: `folders`/`warnings` disaring menurut 1028 BR-16 |
| C-9 | 1071 AC-8 `opnames/{S_BAD}` = `ARCHIVE400`; kode: `readable()` 404 `ARCHIVE412` (`OpnameService.php:796-801`) | AC-8 → `ARCHIVE412` |

Tanpa konflik: 1025 K-13 ↔ 1069 (Store tujuan di kedua opsi). 1071 K-5 b mengubah `ScanRequest`/`StoreRequest` 1026 → oleh 1071.

## 3. Seed permission untuk tenant yang sudah ada
- Praktik repo: `server-setup` menjalankan Updater lalu impor ulang seed (V5 `ApplicationSetupService.php:38-42,141,176-258`:
  `permissions.sql` TRUNCATE+INSERT, `permission_salesman.sql` bila lisensi `SALESMAN_ACTIVITY`, `role_permissions.sql` = semua
  untuk role 1/2/3). 0 dari 99 Updater mengisi `permissions`; = decisions D-7.
- 1025 rev (seed saja) dan 1026 (1133) **konsisten**. Yang keliru hanya digest FB-4a "(permission_v5 + Updater)" → dibetulkan
  orkestrator, bukan pertanyaan. Role selain 1/2/3 diberi manual; tab Izin & halaman by User belum rilis → tanpa regresi.
- **Temuan baru:** `origin/v5-ai-doc-capture` (bb6bf9107, fa3172e53; juga `v5-ai-doc-ft-remove-time`) memakai id **1133-1138**
  di `permissions.sql` (module 1272 AI Integration). Bentrok dengan `Opname Document` 1133 (1026, 20c4f0f19) dan rencana
  `Folder Permissions` 1134 (1025). Id bebas berikutnya di semua branch: 1139 → EPIC K-4.

## 4. depends_on dan urutan jalan
Benar kecuali **1070 + ED-1027** (`ArchiveService::index`; ada di catatan BA, belum di frontmatter; 1071 ikut transitif). 1072 `[]`
benar (AC-8 bersyarat). Urutan = rank, tanpa pindah (semua dependensi sudah di depan): 1025, 1026, 1027, 1028, 1029, 1069,
1070, 1071, 1072.

## 5. Skenario QA usang - satu pemilik
Aturan: (1) item yang dijalankan ulang memperbarui QA di foldernya sendiri; (2) karakterisasi bug di folder lain dibalik QA item
bug; (3) 1024 (tidak dijalankan ulang) diperbarui pengubahnya; (4) regresi item sebelumnya hanya **mencatat** skenario item
berikut yang usang. Jadi 1025 AC-36 mengubah 1024 + milik sendiri; "regresi 1027/1028" QA 1026 → catatan (= 1027 AC-28/29, 1028 AC-24).

| Berkas / skenario | Sebab | Pemilik |
|---|---|---|
| 1025 `qa/scenario1-schema-read.php:95` (AC-1), e2e `ED-1025-folder-permission.spec.js:113,198` | Updater 1026; tab/"+" per permission | QA 1025 |
| 1025 X-8 bagian induk = diri sendiri | 1069 | QA 1069 |
| 1025 X-8 bagian search + X-13 (`scenario4/5`) | 1070 | QA 1070 |
| 1025 X-12 (80 kasus) + probe D-1 1029 | 1071 | QA 1071 |
| 1026 AC-1, AC-2 (bila EPIC K-4 a), AC-12, AC-15, `scenario1/2/4`, e2e | FB-3 | QA 1026 |
| 1027 `qa_lib.php:521-575`, `scenario5-sessions.php:54`, `e2e/fixture.php:249-259`, e2e `:618,649,833`, jumlah tab | 1026, 1025 | QA 1027 |
| 1028 `qa_lib.php:537-580`, `qa_hist.php:153-155`, `scenario1-list.php:228-265`, **`scenario5-access.php:150-154`** (belum di spec), e2e `.ant-segmented-item` | 1026, EPIC K-3 | QA 1028 |
| 1029 `scenario3-access.php:241-345`, `scenario1-read.php:124`, test FE `UpdateFolder` | FB-4b, 1025 K-12 | QA/FE 1029 |

## 6. Pertanyaan lintas item
**EPIC K-3. Server ikut menahan data verifikasi & riwayat bagi role tanpa `Opname Document`?** (= 1027 K-9 = 1028 K-8)
a) server + FE: `GET archives` tanpa kolom/angka, `verifications*` & `GET opnames*` 403 `GE0114`, select opname-users kosong,
display setting disaring/dipertahankan; b) server hanya display setting; c) FE saja. Rek. **a** (data tak terbaca lewat API). Tinggi.

**EPIC K-4. Id permission bentrok dengan branch AI (1133-1138).** Sesudah merge: INSERT duplicate key → `server-setup` gagal
untuk tenant Salesman Activity; grant role per `id_permission` → menomori ulang sesudah rilis = role dapat permission salah.
a) Archive mengalah sekarang: `Opname Document` 1139, `Folder Permissions` 1140 (1025 mengubah `permission_salesman.sql` +
checks/unchecks `:82,138,147,216,227`; seed ulang QA DB, grant role uji diulang; FE pakai nama); b) tetap, yang merge belakangan
menomori ulang; c) developer menetapkan rentang id per branch. Rek. **a** (branch AI sudah di origin, commit 2026-09-17). Tinggi.

## 7. Keputusan Gate 1 (gabungan, per batch)
| # | Asal | Isi | Rek. | Risiko |
|---|---|---|---|---|
| 1 | EPIC K-3 (1027 K-9, 1028 K-8) | penegakan `Opname Document` di server | a | tinggi |
| 2 | EPIC K-4 | id permission | a | tinggi |
| 3 | 1025 K-15 | siapa buka/ubah tab Izin (imbas 1029 `assertCanManage`) | a | tinggi |
| 4 | 1025 K-12 | folder Off di bawah On (imbas 1029 BR-9/13) | b | tinggi |
| 5 | 1025 K-13 | pindah folder: induk asal tidak dicek | a | tinggi |
| 6 | 1071 K-1 | arah perbaikan kolasi | a (d bila perlu teks non-Latin) | tinggi |
| 7-9 | 1025 K-14, K-16; 1029 K-8 | hak otomatis (C-6); pemicu modal cabut akses; modal juga di by User | a, a, a | rendah |
| 10-17 | 1026 K-12..K-16; 1028 K-10, K-9; 1071 K-5 | opname: scan tanpa simpan, Updater draft, format Scope, nilai FE, subfolder hilang; `first_name`; daftar display setting (hanya bila #1 a/b); `code` scan | a×5; b (c bila K-14 ≠ a); a; a | rendah |
| 18-23 | 1069 K-1, K-2, K-4, K-5, K-6, K-7 | validasi pindah/rename | a×6 (1071 #5 ikut K-4) | rendah |
| 24-27 | 1070 K-1..K-4 | parameter list v5 | b, a, a, a | rendah |
| 28-31 | 1071 K-2, K-3, K-4, K-6 | bentuk, cakupan, kode, FE | a×4 | rendah |
| 32-33 | 1072 K-1, K-2 | pengaman klik sel | a, a | rendah |

Bukan pertanyaan: 1069 K-3 (O-1); seed tanpa Updater (§3, D-7); D-3, D-4, butir "Tetap" review.

Pembalikan ronde 1: G-4 "satu permission baru" + "kelola hak = `Update Folder`" (1025 K-4, 1029 K-1) → `Folder Permissions`;
1025 K-1 a → hanya View dominan (FB-9); 1026 K-1 → b (FB-3), K-8 acuan muat Step 1, K-11 (i) di FE; 1027 K-7 a → tautan V/T;
bila EPIC K-3 a: G-4/O-8 "baca sesi = `List Archive`", 1028 K-6 a, EPIC K-1 a "pemegang `List Archive`"; bila 1028 K-10 b:
`scope` C-2 tanpa `first_name`/`other_count`; O-7 (1133) → EPIC K-4.

## 8. Perubahan spec sesudah Gate 1
1025: id (EPIC K-4 a), AC-36 menurut §5, ekspor predikat (O-10) · 1026: C-2, C-8, QA §5, id 1139 + AC-2 · 1027: C-7, O-3 (peta
menerima entri seluruh modul di §3 + contract §4) · 1028: QA §5 · 1029: O-10 · 1070: §4, trait `Modules/V5/...` (bukan salinan V4)
· 1071: C-9 · digest FB-4a: "Updater" → "seed (D-7)".

## 9. Jawaban developer (Gate 1 ronde 2, 2026-10-08 - sebagian)
| Pertanyaan | Jawaban |
|---|---|
| ED-1071 (item) | **ditunda (Gate 1)** - semua modul latin1; ditangani di task lain. K-1..K-6 ED-1071 tidak berlaku |
| EPIC K-3 (= 1027 K-9 = 1028 K-8) | a - server + FE |
| EPIC K-4 | a - branch ini mengalah: `Opname Document` 1139, `Folder Permissions` 1140 |
| 1025 K-15 | **b** - `Folder Permissions` saja cukup untuk membuka/mengubah izin folder (tanpa syarat Update Folder / Update efektif) |
| 1025 K-12 | **a** - folder Izin Off tidak dibatasi induknya (siapa pun dengan View boleh U/D/S; hapus folder tetap harus kosong) |
| 1025 K-13 | a |
| 1025 K-14, 1025 K-16, 1029 K-8 | developer: "tidak valid lagi karena K-15 b" → diklarifikasi (FB-5 / D-2 tetap atau dicabut) |
| 1026 K-12..K-16 | a ×5 |
| 1028 K-9 | a |
| 1069 K-1, K-2, K-4, K-5, K-6 | a ×5 |
| 1070 K-2, K-3 | a, a |
| Ditanya ulang dengan tabel | 1028 K-10, 1069 K-7, 1070 K-1, 1070 K-4, 1072 K-1 (developer: sudah ditangani `disableDefaultRowExpand` - dicek: tidak, lihat jawaban), 1072 K-2 |

### 9b. Jawaban lengkap (dipakai analyst untuk menerapkan Gate 1) - 2026-10-08 15:15
| Item | Keputusan final |
|---|---|
| EPIC | K-3 **a** (server + FE menegakkan `Opname Document` untuk verifikasi & riwayat). K-4 **a**: `Opname Document` = **1139**, `Folder Permissions` = **1140** (1025 mengubah `permission_salesman.sql` + checks/unchecks; QA DB seed ulang saat run) |
| ED-1025 | K-12 **a**: folder Izin Off tidak dibatasi induknya - siapa pun yang punya View (dan dalam scope lokasi) boleh Update/Delete/Store; hapus folder tetap harus kosong. K-13 **a**. K-14 **a** (FE menambah/menaikkan baris user login V/U/D/S saat toggle dinyalakan, terlihat & bisa diubah sebelum Simpan). K-15 **b**: `Folder Permissions` saja cukup untuk membuka & mengubah tab Izin - tanpa syarat Update Folder / Update efektif; pemegangnya boleh mengatur izin folder mana pun dalam scope lokasinya, termasuk folder yang tidak bisa ia View. K-16 **b**: modal cabut-akses-sendiri hanya saat user kehilangan **View**, hanya di tab Izin |
| ED-1026 | K-12..K-16 **a** |
| ED-1027 | K-9 = EPIC K-3 a |
| ED-1028 | K-8 = EPIC K-3 a. K-9 **a**. K-10 **b**: `first_name`/`other_count` dihapus dari `scope` (dikerjakan ED-1026, pemilik `scope()`; contract 1026 + 1027 diperbarui) |
| ED-1029 | K-8 **c** (tidak ada modal di halaman by User; modal hanya di tab Izin menurut 1025 K-16 b). Imbas 1025 K-15 b: route/menu/endpoint = `Folder Permissions`; `assertCanManage` = `Folder Permissions` saja; folder tanpa View di halaman ini bisa diubah (BR-5 "read-only" gugur). Imbas 1025 K-12 a: aturan sel + teks info |
| ED-1069 | K-1 a, K-2 a, K-3 (pemilik aturan tanpa-siklus), K-4 a, K-5 a, K-6 a. K-7 **a tanpa kueri**: hanya mencegah ke depan; tidak ada kueri cek produksi |
| ED-1070 | **ditunda (Gate 1)**: P-5/P-6 "skip, task lain" → perbaikan search/sorts/pagination list v5 tidak di epic ini; butir 2 (induk = diri sendiri) sudah tercakup aturan ED-1069. K-1..K-4 tidak berlaku |
| ED-1071 | **ditunda (Gate 1)**: collation ditangani task lain. K-1..K-6 tidak berlaku (termasuk K-5: regex scan ED-1026 tetap) |
| ED-1072 | K-1 **a**, K-2 **a** |
| Item disetujui (P-9 a) | ED-1025, ED-1026, ED-1027, ED-1028, ED-1029, ED-1069, ED-1072. Run langsung sesudah Gate 1 + Jira sync (developer 15:20, menggantikan "tunggu instruksi") |

Imbas penundaan pada §5: skenario karakterisasi milik 1070 (X-8 bagian search, X-13) dan 1071 (X-12, probe D-1 1029) **tetap**
sebagai karakterisasi bug terbuka - tidak dibalik di run ini. X-8 bagian induk = diri sendiri tetap milik QA 1069.

## Data uji (jawaban developer 2026-10-08 ~20:00, sesudah insiden ED-1025)
- `api_sidomaju` = DB testing/development: QA **boleh** menghapus/mereset data di sana. Urutan: hapus data milik QA
  sendiri dulu (prefix/jurnal); purge/reset data lain boleh bila memang perlu - catat persis apa yang dihapus. Jangan
  hentikan run karena hal ini.
- Insiden K-1 a: data opname `kevinsudjadi.adm` dibuat ulang developer lewat UI sesudah revisi ED-1026 (tidak dipulihkan AI).
- K-2 a: harness `scripts/e2e/lib/fixtures.js` + fixture e2e ED-1026/1027/1028 diperbaiki dulu (commit pipeline terpisah),
  lalu run lanjut.
- K-3 a: 6 baris `QA01-R2-*` dihapus developer sendiri (SQL diberikan).
