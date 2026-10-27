---
epic: ED-1022
mode: epic-check
items: [NEW-ED1022-01, NEW-ED1022-02, NEW-ED1022-03, NEW-ED1022-04, NEW-ED1022-05, NEW-ED1022-06]
---
# Gate 1 epic ED-1022 - cek lintas item

Ringkas: 10 tumpang tindih, 5 asumsi bertentangan, depends_on benar semua (1 perbaikan frontmatter), 2 EPIC K,
7 grup keputusan terkait, 17 perubahan spec. Spec tidak diubah; orkestrator menerapkan §8 sesudah Gate 1.

## 1. Tumpang tindih (diperiksa ke spec/kode)
| # | Hal | Item | Pemilik → perbaikan |
|---|---|---|---|
| O-1 | `ARCHIVE408`: 01 = hapus folder berisi subfolder tak terjangkau (K-7 a); 02 = tanpa Update | 01, 02 | 02 tetap 408; 01 → `ARCHIVE418` |
| O-2 | `ARCHIVE407` dua arti: 01 di luar lokasi, 02 tanpa View | 01, 02, 03-06 | 01: satu kode 403 "tidak punya akses", 02 menambah nama folder di `result.denied_by` |
| O-3 | 400 "bukan folder": 03 `ARCHIVE417`, 04 `ARCHIVE420` | 03, 04 | 03; 04 pakai 417 (05 sudah) |
| O-4 | `ARCHIVE400-406` keluar 500 (`ErrorMessageException` default 500): 01 (400; 401-403 bersyarat) & 02 K-11(1) (400, 402-406) | 01, 02 | 01, tanpa syarat, semua 400-406 |
| O-5 | `hasFileInChild` (`DocumentArchiveService.php:114-127`, terbukti): 01 K-7 & 02 K-11(2) | 01, 02 | 01; 02 K-11 dihapus |
| O-6 | `LocationRule` `=== 0` vs "0" (`Archive/LocationRule.php:43`) | 01 saja | 01 K-6 (tidak tumpang tindih) |
| O-7 | Permission "max+1": 03 `Opname Document`, 06 K-1 b/c, 02 K-4 b | 02, 03, 06 | id tetap sesuai urutan jalan; max global = 1132 (`permissions.sql`; salesman 1130): 03 = 1133 |
| O-8 | `GET opnames/{id}`, `/documents`: 03 `Opname Document` (grup), 05 K-6 ubah ke `List Archive`; `GET opnames` 05 ikut kena middleware grup 03 | 03, 05 | 03: per route, dua route baca = `List Archive` |
| O-9 | Format baris sesi: 03 (model) vs 04 `session_row` | 03, 04, 05 | 03 (lihat C-2) |
| O-10 | Helper bersama: `getScopeTag()` (03 di `archiveOpnameModal.function.js`, dipakai 04/05); auto-centang View BR-3 (02, dipakai 06); simpan baris hak (02, dipakai 06) | 02, 03 | `archive.function.js`; `ArchivePermissionService::saveUserRow` di 02 |

Konsisten, tanpa perubahan: drawer bertab 02 → tab Verification 04 → drawer history 05; CTA `onOpenHistory` (04 K-6 a)
dipasang 05; query "sesi terlihat" milik 04, dipakai 05; select & route baru tidak bentrok. Display setting: hanya 04
me-reset `documentArchive`; `documentArchiveOpnameHistory` (05) dibuat otomatis saat request pertama
(`Modules/V5/Entities/Helper/MyHelper.php:419-427`) → tanpa Updater, tidak ada yang digabung. Updater 02/03/04 tetap per item.

## 2. Asumsi yang bertentangan
| # | Konflik | Pemilik → perbaikan |
|---|---|---|
| C-1 | 01 §3: View 02 "ditambahkan **di dalam** `scopeVisibleToUser`", padahal 02 BR-10/12 (K-6 a, K-8 a) tetap menampilkan folder tanpa View & dokumennya di pencarian; 03-06 memakai `viewableFolderIds()` terpisah | 02; 01 §3: scope = lokasi saja, View = `viewableFolderIds()` |
| C-2 | 04 `session_row`: `id_opname_session`, `opname_at`, `id_user`, `username`, `verified/not_found/invalid`, `scope{id_archive,name,subfolder_count,is_root}`; 03: `id_archive_opname`, `confirmed_at`, `created_by` (username, tanpa `id_user`), `*_count`, `scope{is_root,folder_name,first_name,other_count}`; 05 ikut 03 | 03; 04 ganti nama, `scope` bentuk 03, buang `id_user` |
| C-3 | 04 A-1..A-5 atas model 03: A-1 (`archives.is_verified`), A-3 (`archive_opname_folders` level 0-2 + snapshot), A-4 (indeks) cocok; A-2 cocok kecuali `id_user`; A-5 hanya berlaku bila 03 K-4 a & K-2 a (grup G-5) | 04 perbarui A-2 |
| C-4 | 04 K-4 a ("sesi yang mencakup folder terlihat") tidak mengatur sesi dari root (`id_archive` NULL; root saja → tak terlihat siapa pun selain superadmin) dan sesi yang foldernya sudah dihapus; 05 A-5 bergantung | EPIC K-1 |
| C-5 | 01 K-7 a menolak hapus folder bila ada subfolder di luar lokasi; 02 K-7(iii) menghapus subfolder tanpa cek hak | grup G-3 |

## 3. depends_on
Benar semua: 02→01; 03→01,02; 04→01-03; 05→01-04; 06→01,02 (posisi menu "+" & id permission sesudah 03 dijamin
urutan jalan, bukan dependensi). Frontmatter: 02 dan 03 membuat Updater, `be_modules` tanpa `UpdateVersion` (04 ada).

## 4. Urutan jalan usulan
01 → 02 → 03 → 04 → 05 → 06 (= rencana; tidak ada yang perlu dipindah).

## 5. Kode pesan final (en_EN + id_ID, blok `ARCHIVE` `en_EN.php:3345-3363`)
| Kode | HTTP | Arti | Pemilik | Dipakai |
|---|---|---|---|---|
| 400 / 401-406 | 404 / 400 (kini 500) | lama | status diperbaiki 01 | semua |
| 407 | 403 | tidak punya akses folder/dokumen (lokasi 01 atau View 02, `result.denied_by`) | 01 | 02-06 |
| 408 / 409 / 410 | 403 | tanpa Update / Delete / Store | 02 | 06 (408) |
| 411 | 400 | Update/Delete/Store tanpa View | 02 | 06 |
| 211-214, 412-417 | 200; 404/400 | sesi opname; 417 = bukan folder | 03 | 04 (417), 05 (412, 417) |
| 418 | 403 | folder tak bisa dihapus: ada subfolder yang tak bisa Anda akses/hapus (01 K-7 a) | 01 | 02 (G-3) |
| 430 | 403 | hanya bila 05 K-5 c | 05 | - |
| 440 / 441 | 404 / 400 | user tidak ada / folder permission belum aktif | 06 | - |

Celah 408-417 saat 01 jalan tidak masalah; penomoran ulang berurutan ditolak (mengubah 4 spec + kontrak).

## 6. Keputusan terkait (dijawab bersama)
| Grup | K item | Jawaban gabungan yang direkomendasikan |
|---|---|---|
| G-1 Visibilitas | 01 K-1, 02 K-6, 03 K-5, 04 K-2, 04 K-4, 05 K-5, 06 K-2 | a, a, a, a, a (+ EPIC K-1 a), b, a: daftar/pencarian = lokasi per baris; hitungan, opname, history = lokasi ∩ View |
| G-2 Superadmin | 01 K-2, 02 K-2, 02 K-9, 05 K-2 | a, a, a, a: bypass = `is_superadmin` 1/2; `=3` tanpa employee hanya "semua lokasi"; daftar hak tanpa superadmin, daftar pelaku opname dengan superadmin |
| G-3 Hapus folder | 01 K-7, 02 K-7(iii), 02 K-11 | 01 K-7 a (perbaiki deteksi + 418); 02 K-7(iii) ganti: subfolder aktif tanpa Delete efektif → 418 juga ("tidak ada yang terhapus diam-diam"); 02 K-11 gugur (pindah ke 01) |
| G-4 Permission | 01 K-4, 02 K-4, 02 K-5, 03 K-6, 05 K-6, 06 K-1 | a semua: `permission_v5` per route; kelola hak = Update efektif + `Update Folder`; opname = `Opname Document` + View; baca hasil sesi = `List Archive`. Satu permission baru saja (1133) |
| G-5 Verified | 03 K-2 = 04 K-1; 03 K-4 = 04 K-8; 03 K-9 (05 A-1); 04 K-3 ↔ 05 K-1, K-3(ii) | a/a; a/a; a; a/a/ya: verified bertahan sampai opname berikutnya, root ikut, snapshot "belum discan", angka folder rekursif |
| G-6 Dokumen fisik | 01 K-5, 02 K-8 | a, a: hand over/receive/put-in scan tidak dibatasi lokasi maupun View |
| G-7 Hasil sesi | 04 K-5, 05 K-4 | a, a: baris overview 04 tidak bisa diklik; hasil hanya dari 05b, default Scanned |

## 7. Pertanyaan lintas item
**EPIC K-1. Sesi opname dari root & sesi yang foldernya sudah dihapus - siapa melihatnya** (04 K-4, 05 overview/daftar).
a) root (`id_archive` NULL) dianggap terlihat oleh semua pemegang `List Archive` → sesi dari root selalu tampil (isi
hasil tetap disaring 05 K-5 b); sesi yang semua foldernya kini terhapus/tak terlihat hanya untuk superadmin 1/2 dan
pembuat sesi; b) aturan 04 K-4 a apa adanya: sesi root tanpa subfolder dicentang hanya terlihat superadmin 1/2 (dan
pembuat); c) semua sesi untuk semua pemegang `List Archive` (= 04 K-4 b). Rekomendasi **a** - ringkasan 04 menghitung
dokumen root (04 K-8 a), sesi yang memverifikasinya harus bisa dilihat. risiko: tinggi.

**EPIC K-2. AC "tanpa lisensi Salesman Activity" tidak bisa diuji lokal (`PROFILES` kosong)** - 01 AC-13, 02 AC-23,
03 AC-2/3, 04 AC-9, 05 AC-14, 06 AC-20. a) developer mengisi `PROFILES` (profil tanpa Salesman Activity) sebelum run;
b) cara seragam: BE = http dengan role tanpa permission terkait (403 `GE0114`), seed = baris hanya di
`permission_salesman.sql` (`ApplicationSetupService.php:223-238`), FE = guard menu/route yang ada dicek di kode; tukar
lisensi sungguhan sekali di Gate 2 epic (manual); c) AC lisensi hanya di 01. Rekomendasi **b**. risiko: rendah.

## 8. Perubahan spec yang diusulkan (diterapkan bila disetujui)
| # | Item | Bagian | Perubahan | Alasan |
|---|---|---|---|---|
| 1 | 01 | §3 kode pesan, §8 K-7 | `ARCHIVE408` → `ARCHIVE418`; teks 407 generik, dipakai ulang 02 | O-1, O-2 |
| 2 | 01 | §3 status HTTP, §8 K-6 | status `ARCHIVE400` 404 & `401-406` 400 tanpa syarat; K-6 tinggal perbandingan `=== 0` + `LocationRule` di update | O-4 |
| 3 | 01 | §3 titik pakai ulang | scope = lokasi saja; View 02 lewat `viewableFolderIds()`, bukan di dalam scope | C-1 |
| 4 | 02 | §3 kode pesan, contract | 407 = kode 01 (nama folder di `result.denied_by`); 408-411 tetap; pakai 418 untuk hapus (G-3) | O-2, G-3 |
| 5 | 02 | §8 K-11, §7 | hapus K-11 (1)(2) dan "perbaikan bug yang disentuh" dari subtask | O-4, O-5 |
| 6 | 02 | §8 K-7(iii) | rekomendasi → cek Delete di semua subfolder aktif (418) | G-3 |
| 7 | 02 | §3 service, §5 | tambah `ArchivePermissionService::saveUserRow`; auto-centang BR-3 di `archive.function.js` | O-10 (06) |
| 8 | 02, 03 | frontmatter | `be_modules` + `UpdateVersion` | §3 |
| 9 | 03 | §3 permission | `<max+1>` → `1133` (1134 bila 02 K-4 b); cek ulang max global saat dev | O-7 |
| 10 | 03 | §4 | `permission_v5` per route; `GET opnames/{id}` & `/documents` = `List Archive`; sesi terkonfirmasi di 03 hanya pemilik + superadmin 1/2 | O-8 |
| 11 | 03 | §5 | `getScopeTag()` di `archive.function.js` | O-10 |
| 12 | 03 | contract | `opname_at` (`opnamed_today`, `ARCHIVE414`) → `confirmed_at` (opsional) | satu nama |
| 13 | 04 | §3, contract | hapus `ARCHIVE420` → 417; `session_row` = `id_archive_opname`, `confirmed_at`, `created_by`, `total_documents`, `verified_count`, `not_found_count`, `invalid_count`, `scope` bentuk 03 (tanpa `id_user`/`username`); A-2 diperbarui | O-3, C-2, C-3 |
| 14 | 04 | §8 K-4 | tambah aturan sesi root & folder terhapus | EPIC K-1 |
| 15 | 05 | §3 permission, §4 | baris `opnames/{id}`, `/documents`: permission sudah dari 03; 05 hanya memperluas visibilitas sesi (04 K-4 + EPIC K-1) | #10 |
| 16 | 06 | §3 permission | bila K-1 b/c: id = berikutnya sesudah 03 (1134/1135) | O-7 |
| 17 | semua | §6 AC lisensi | cara cek seragam sesuai EPIC K-2 | EPIC K-2 |
