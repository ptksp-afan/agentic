---
epic: ED-1022
mode: epic-check
round: 6
items: [ED-1250, ED-1251]
---
# Gate 1 ronde 6 ED-1022 - cek lintas item (diperbarui sesudah revisi spec 2026-10-24)

Ringkas: spec revisi sudah memuat hampir semua temuan cek pertama (C-5, C-6, C-8, F-1, F-2, O-4). Berkas bersama BE tetap satu
(`ColumnDisplaySettingService`); FE kini juga `archive.function.js`. Gabungan konsisten di urutan mana pun; urutan = rank 1250 → 1251;
tanpa pertanyaan lintas item baru. Spec tidak diubah. A-1..A-12 tetap.

## 1. Kepemilikan
| # | Hal | Pemilik → aturan |
|---|---|---|
| O-1 | V5 `ColumnDisplaySettingService.php` | definisi "seluruh modul" (`:149`, `:167`), saringan `queries` di `show`/`update`, docblock = 1250; 1 entri `documentArchiveOpnameResult` di `PERMISSION_FILTERS` = 1251. Rujuk nama method |
| O-2 | BE lain | 1250: `ArchiveService::index` (satu `where` dokumen), konstanta `VerificationService`, `displayColumn/documentArchive.php`, Updater. 1251: route `opnames/{id}/pdf`, `pdf/preview`, `xls`; `OpnameController`, `OpnameService::exportData`, `OpnameExportService`; `select.php` grup baru `report/document-archive/opname` (8 route, tanpa wildcard bentrok; grup `select/document-archive` `:2713` tidak disentuh); `SelectArchiveOpnameReportController`; `Config/config.php` (displayColumn sesudah `:332`, pdfTemplate sesudah `reportPurchaseOrder` `:514`); 2 config baru. `PdfReportService`, `PdfTemplateService`, `ExportReportXls`, `XlsReport` hanya dipakai, tidak diubah (K-4 b, K-9 a) |
| O-3 | FE | 1250: `ArchivePage`, `ArchiveTable` (+test), `archive.function.js` (helper filter, +test). 1251: `endpoints.js`, `archive.api.js`, `archive.function.js` (`pdfEndpoints`), tombol baru, `ArchiveOpnameHistory/Content.js`, `ResultDrawer.js`, `drawerPdf.constant.js` (2 kunci baru, aditif; komponen DrawerPdf tidak diubah). Bersama: `archive.function.js` (fungsi berbeda). Tanpa locale baru (`archive.Opname Result` sudah ada) |
| O-4 | Dok FE | 1250 = `027-*`, 1251 = `028-*`; `_module.md`, `_api.md` berurutan |
| O-5 | Kode pesan / permission | tidak ada yang baru (EPIC K-4 a) |

## 2. Konflik dan catatan
| # | Temuan | Status |
|---|---|---|
| C-1 | `PERMISSION_FILTERS` | entri 1251 hanya `permission` → seluruh modul di cek lama maupun baru; `strcasecmp` tepat, `documentArchive` tidak menangkap `documentArchiveOpnameResult`. Bukti 1251 AC-9 sesudah 1250 |
| C-2 | Updater 1250 | delete `documentArchive` sama-dengan (`2026_10_07_14_13_16_…php:14`): baris cds `documentArchiveOpnameResult` dan `transaction_pdf_templates` tidak tersentuh. Tenant tanpa baris: 1250 dari config; 1251 dibuat saat dipakai (`MyHelper.php:452-460`, `PdfTemplateService::getTemplate` `:1007-1037`) |
| C-3 | Visibilitas | 1250 hanya menambah `where` pada query list (lokasi `visibleToUser`, aturan View list tetap; folder tidak pernah disaring); 1251 `whereVisibleRows` lewat `VerificationService::context()`. Sumber lokasi/View sama (ED-1024/1025). Beda yang sudah diputuskan: folder snapshot export (ED-1028 K-5 b), mode cari list memuat dokumen di folder tanpa View (ED-1025 BR-12), Invalid selalu di export |
| C-4 | park sesudah commit 1250 | tetap: rebaseline 1251 tepat sebelum runner-nya (`park.sh` keluar 3 bila ada `COMMITTED`) |
| C-5, C-6, C-8 | role preset, helper q28, teardown baris `documentArchive` | selesai di spec (1251 §6 `QA251-ROLE-FULL/U2/R0` + salinan helper; 1250 §6a teardown) |
| C-7 | Tabel opname | 3 sesi nyata hanya dibaca; S1, S2, S-BULK 1251 sementara jadi sesi terbaru sampai sweep |
| C-9 | Simpan Customize (1251 AC-15, AC-19) | `PdfTemplateService::update` (`:1129-1152`) menulis baris dari `id_transaction_pdf_template` body, termasuk `transaction_name` kiriman, tanpa cek permission. Salah id/kunci `drawerPdf.constant.js` = baris laporan lain berubah/terganti nama. `transaction_pdf_templates` tidak ada di snapshot ED-1149 → perlu guard sendiri (§8) |
| C-10 | Picker 1250 (K-2 b) | `ArchiveTable` memakai helper yang sama; tanpa nilai filter request picker = sekarang. `.ant-select` pertama di popover tetap Type (entri baru sesudah Type) |

## 3. Karakterisasi yang dibalik (aturan r2 §5(2), ED-1149 BR-7)
| # | Skenario | Hari ini → sesudah | Pemilik |
|---|---|---|---|
| F-1 | ED-1028 AC-28 `scenario9-display-setting.php:127,130,138` | R0 −1, selisih 1 modul, pemegang 2 baris → **−2, 2 modul, 3 baris** (K-3 b) | 1251 AC-12 (ada) |
| F-2 | ED-1028 e2e `histRows` (`:266-272`) di `:547,632,731,1450` | 7 → 8 sel (kolom aksi) | 1251 AC-17 (ada) |

Tidak berubah (dicek ulang): e2e ED-1024 `:48-60`, ED-1027 `:1305` (`.ant-select` pertama = Type); ED-1125 `typeAndCari :243-256`, ED-1069
`:486-493` (kolom Cari + tombol Cari, tanpa filter); ED-1027 `scenario1`, `scenario10:69`; ED-1028 e2e `histHeads :273`, `:1114`,
`:1660-1667`; PDF transaksi ED-1024 `scenario2-types:183-203` (baris template lain). Tidak ada skenario lama yang menyentuh
`transaction_pdf_templates` atau Customize. Template/DrawerPdf: hanya kunci baru, laporan lain tetap (K-11 c: Customize PO tetap rusak).

## 4. depends_on dan urutan jalan
`[]` keduanya benar. Urutan = rank **ED-1250 → ED-1251** (1251 AC-9 di definisi final; 1250 menjalankan scenario9 sebelum baris 1251
ada; F-1 dibalik pemiliknya). 1250 Blocked → park, 1251 jalan dari baseline; unpark 1250 sesudah commit 1251 bisa konflik kecil di
`PERMISSION_FILTERS`/docblock dan `archive.function.js` → blocker ulang (pola r5 §5); saat itu 1250 AC-10 memeriksa modul
`documentArchiveOpnameResult` (bukan "2 modul", §8).

## 5. Data uji & lingkungan (`api_sidomaju`)
| Item | Baris sendiri | Login | Di luar baris sendiri |
|---|---|---|---|
| 1250 | `QA50-` (pohon 3 level + dokumen), preset `QA50-ROLE-*`; `is_verified` langsung (K-4 a); Pindahkan fixture di picker | QA_USER (preset OD), QA_USER2 opsional | permanen (DU-5 a): baris cds `documentArchive` dibuat ulang (+`updater_logs`); berjurnal: AC-11/20 baris itu, `user_roles`, lokasi & bahasa QA_USER |
| 1251 | `0QA251-` + S1, S2 lewat `POST opnames` (`is_verified` fixture saja, sweep pra-citra), S-BULK `QA251BULK%`, preset `QA251-ROLE-*` | QA_USER (FULL), QA_USER2 (U2, R0) | permanen (DU-5 a diperluas): cds + `transaction_pdf_templates` `documentArchiveOpnameResult`; berjurnal: AC-8 cds, AC-15/19 baris template, bahasa, `user_roles` |

Tidak ada tabel dikosongkan/di-reset; tanpa login user nyata: e2e ED-1150 (`run-sam.sh`, login `sam`) **tidak** dijalankan (1250 AC-24
= unit), e2e ED-1125/1069 profil default. `force_login` hanya user QA (P-5). Snapshot ED-1149: beda `column_display_settings`
(1 dibuat ulang + 1 baru) dan `updater_logs` = diizinkan (DU-5 a). Preset `QA251-ROLE-*` cocok `QA2%-ROLE-%` → sisa preset memblokir
e2e ED-1154 (P-1, `fixture.php:205`): preset wajib dibuang di akhir. Prefiks lain tidak saling cocok (`QA25-`/`0QA25-` ≠ `QA251-`/`0QA251-`).
DU-6 a berlaku. Asumsi: lisensi Salesman Activity, profil default, permission 1082/1139/1140, mPDF/PHPExcel, fpm 128M, FE staging,
e2e `--workers 1`. Bisa menghentikan run: C-4, C-9 (bila baris lain tertimpa), DU-6.

## 6. Pertanyaan lintas item
DU-5 a (diperluas ke baris template) dan DU-6 a sudah dijawab (§9, §9b). Tidak ada EPIC K/DU baru: sisa temuan (C-9, AC-10) adalah
perbaikan spec tanpa pilihan bisnis.

## 7. Keputusan per item (konsistensi)
| Item | Jawaban (spec revisi) | Dampak lintas item |
|---|---|---|
| 1250 | K-1 a, K-2 b, K-3 gugur, K-4 a, K-5 a; koreksi folder | picker ikut (C-10); visibilitas lebih sederhana (C-3) |
| 1251 | K-1 a, K-2 diubah, K-3 b, K-4 b, K-5 a (Excel), K-6 a+syarat, K-7 a, K-8 a, K-9 a, K-10 a diubah, K-11 c | 1 entri cds (C-1, F-1 −2); trait tidak diubah; template baru (C-9) |

## 8. Perubahan spec sesudah Gate 1 (yang belum ada)
1250: AC-10 langkah unpark → "modul `documentArchiveOpnameResult`" (bukan "2 modul hasil opname"); §6a/AC-24: e2e ED-1150 tidak
dijalankan (login `sam`).
1251: AC-15/AC-19 guard: `transaction_pdf_templates` selain baris sendiri sama persis (count + checksum), `transaction_name` baris sendiri
tetap, tidak ada baris template baru selain `documentArchiveOpnameResult`; §6: preset `QA251-ROLE-*` dibuang di akhir (ED-1154 P-1).
Orkestrator: rebaseline 1251 sebelum runner (C-4); K-11 c (Customize laporan tanpa `selected-pdf-templates` = 404) dicatat sebagai
usulan A-13 di `run.md`, bukan defect.

## 9. Jawaban developer Gate 1 ronde 6 (2026-10-24)
| Asal | Jawaban | Catatan |
|---|---|---|
| 1250 K-1, K-3, K-4 | a | |
| 1250 K-2 | **b** | filter tampil dan berlaku di picker seperti di halaman (tetap di folder); filter lain di picker tetap lompat ke root -> revisi spec |
| 1251 K-1, K-5, K-7, K-8 | a | |
| 1251 K-2 | **diubah** | Print = drawer pratinjau PO Report persis, dengan template report (pdfTemplate seperti `reportPurchaseOrder`) dan Customize berfungsi; display setting dipakai drawer pdf + Customize -> revisi spec |
| 1251 K-3 | **b** | samakan PO Report: 1 baris (ringkasan `summaries_columns`, rincian current/available) |
| 1251 K-4 | **b** | angka seperti PO ("398.00"), tanpa tipe `integer` |
| 1251 K-6 | a | catatan hanya bila memang ada baris yang disembunyikan |
| DU-5, DU-6 | a, a | |
Revisi spec dikirim ke analis 1250 dan 1251; item yang direvisi disajikan ulang.


### 9b. Jawaban lanjutan (2026-10-24)
| Asal | Jawaban | Catatan |
|---|---|---|
| 1250 koreksi | developer: "yang disembunyikan itu dokumen yang tidak sesuai filter saja" | filter hanya menyembunyikan baris dokumen; folder tidak pernah disembunyikan (halaman dan picker); K-3 gugur |
| 1250 K-5 | a (default epic loop) | picker dibuka dengan filter kosong seperti filter lain; dampak hanya baris dokumen di picker |
| 1251 K-9 | a | `PdfReportService`, report-template-6 / report-header-1 / report-footer-1, A4-P |
| 1251 K-10 | a, diubah | kolom PDF **dan Excel** dari template tersimpan sesudah Customize disimpan; sebelum itu keduanya dari display setting |
| 1251 K-11 | c | tanpa tiket; item ini hanya menambah `selected-pdf-templates` untuk laporannya sendiri |
| DU-5 perluasan | a | baris `transaction_pdf_templates` `documentArchiveOpnameResult` dibiarkan |
