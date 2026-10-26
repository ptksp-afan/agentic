---
key: ED-1251
scope: be
round: 3
verdict: PASS
counts: {pass: 18, fail: 0, manual: 0, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

Ronde 3 = revisi review 2026-10-26 (spec § "Revisi review 2026-10-26", subtask ED-1258/ED-1259): R-3 footer di semua halaman, R-4 kertas Continuous, R-5 perubahan developer ikut
(+ regresi PDF laporan lain), lalu regresi BE penuh. Diff yang dinilai = working tree BE (6 berkas, belum di-commit): `OpnameExportService.php`, `PdfReportService.php` (param ke-3 opsional),
`PdfTemplateService.php` (hunk developer `getHeaderPadding` + `getPaperFormat` public), `report-template-6.blade.php` (include footer dipindah sebelum `$content`), dua view `report-header-1` (hunk developer).

- **AC-R3 PASS.** Dengan view lama (kontrol negatif, `git show HEAD:` report-template-6) sesi nyata developer **mereproduksi bug persis seperti laporannya**: `...033097` footer 3/3 halaman, `...474864` **0/11**,
  `...422239` **5/11**. Dengan kode sekarang: 3/3, **11/11**, **11/11** (GET pdf dan pratinjau `{}`, A4-L, Continuous-P, Continuous-L semuanya footer di setiap halaman, nomor "Page i of N" benar).
  Sesi QA S1 (kecil, ±10 baris) dan 50/199/200/201/399/400/401/1000 baris (potret, A4-L, Letter-L, Legal-P, Folio-L, bahasa id, template tersimpan A4-L): 184 kondisi lulus, footer di semua halaman, jumlah baris utuh, footer tidak menimpa baris terakhir.
- **AC-R4 PASS.** Pratinjau dan GET pdf tersimpan `Continuous-P` = MediaBox **612 x 381,6 pt** (215,9 x 134,62 mm), `Continuous-L` = **784,8 x 597,6 pt** (276,86 x 210,82 mm) = `PdfTemplateService::getPaperFormat`, semua halaman; bukan A4.
  `continuous-p`, `Continuous`, `Continuous-X`, `Continuous-PL`, `Continuous -P`, `B5-P`, `A4`, `Continuous--P`, array, angka = 422 `paper_size` ("Format paper size tidak valid.").
- **AC-R5 PASS.** `info($paper)` tidak ada lagi di `Modules/V5/Http` (dan log `local.INFO: A4` berhenti 14:27 WIB, tidak muncul lagi selama QA); hunk developer ada (`getHeaderPadding`, padding 20px `report-header-1`, kelas `paper-*`, gaya `.paper-A5`).
  Laporan lain: VAT In (151 baris, 7 hal.), VAT Out (130 baris, 6 hal.), PB1, WHT Sales/Purchase, AP Summary (report-template-6) 200, header + footer di setiap halaman, header tidak menimpa baris pertama, semua nomor transaksi oracle API ada di PDF.
  VAT Summary + AP Due (report-template-1) dan Journal (report-template-2), semuanya `report-header-1`, tetap 200 dengan footer di semua halaman.
- **Regresi BE penuh:** `qa/scenario*.php` **PASS=36 FAIL=0** (32 lama + AC-R3a/R3b/R4/R5; 19 mnt 02 s di beban mesin; `work/ed1251/final-run-r3b.txt`, `work/qa-http/ED-1251-20261026153858.json`). ED-1028 `AC-1,AC-12,AC-13,AC-28` PASS=4
  (`work/ed1251/r3-regress-ed1028.txt`; keadaan "baris Result ADA" kini nyata dan lulus), ED-1250 `AC-10,AC-11` PASS=2 (`work/ed1251/r3-regress-ed1250.txt`). `git status` folder ED-1028 dan ED-1250 bersih (tidak diubah).
- **Tidak ada HTTP 500 pada jalur yang dikirim FE atau pada AC putaran ini.** 500 yang tercatat hanya data tak sah buatan tangan di kode bersama (E-2/E-3/E-6/D-1d: "Di luar item" #5-#7 r2, tidak berubah).
- **Pengamatan O-1 (bukan syarat putaran ini, keputusan orkestrator/developer):** pada **A5-P** header 5 baris Opname (perusahaan, judul, Folder, Opname Date, Opname By) lebih tinggi dari ruang header A5, sehingga baris terakhir ("Opname By") tertutup baris judul tabel
  (celah -4,6 pt hal. 1, -2,5 pt hal. 2+; `work/ed1251/r3-images/opname-a5p-header-hidden-line.png`). Sebab: kode bersama `PdfTemplateService::getHeaderHeight` tidak punya ukuran A5 khusus `report-header-1` (base 110px). Bukan akibat diff ini: dengan view header HEAD celahnya **-23,2 / -21,1 pt**
  (lebih buruk); hunk developer memperbaikinya sebagian. A4/A4-L/Letter/Legal/Folio/Continuous: celah header positif (13,6 / 7,3 pt).
- Data uji: semua dibuang (prefix `0QA251-`, `QA251BULK`, preset `QA251-ROLE-*` = 0 sisa, jurnal 0). Baris `documentArchiveOpnameResult` **ada 1 template (id 519) + 1 cds (id 2585) saat saya datang** (dibuat developer sendiri, `created_by kevinsudjadi.adm`; r2: 0/0);
  akhir: 1/1, **byte-identik** dengan snapshot awal (`cmp` JSON seluruh kolom). Total `transaction_pdf_templates` 142 -> 142, `column_display_settings` 364 -> 364. Tiga sesi nyata developer tidak ditulis. Tidak ada baris milik orang lain yang dihapus.
  Catatan jujur: skenario lama (AC-8/13/15, E-1, ...) memulihkan baris itu dengan hapus + sisip ulang isi yang sama persis (pola DU-5 a, dijurnal); AC-R3a sendiri tidak menulis apa pun (sesi MySQL read-only).

## Hasil per AC

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | GET pdf/xls S1, S2 (en, id), header, ZIP, `%PDF-` | PASS | `AC-1` 70 asersi |
| AC-2 | Header XLSX/PDF vs DB, footer printed by | PASS | AC-2 55, AC-2b 28 |
| AC-3 | Ringkasan = rekaman S1/S2, "398.00"-gaya, XLSX numerik | PASS | 53 |
| AC-4 | Rincian vs gabungan `GET documents?result=all` | PASS | 81 |
| AC-5 | `users.language` id: label, bulan, hasil | PASS | 72 |
| AC-6 | Tanpa warna FE (PDF palet template, XLSX tanpa fill) | PASS | 169 |
| AC-7 | Catatan BR-18 user2/QA_USER/bypass, Ringkasan, Rincian | PASS | AC-7 62, AC-7b 42 |
| AC-8 | PUT display setting tanpa Time/Invalid -> PDF dan XLSX; fallback | PASS | 72 (+ D-1b 365) |
| AC-9 | R0 403 `GE0114`, 401, 404 `ARCHIVE412`, select 200, tanpa 5xx | PASS | 404 asersi, 78 permintaan, 5xx = 0 |
| AC-10 | S-BULK 10.000 baris PDF + XLSX | PASS | PDF 24,9 s / 251 hal. (r2: 14,8 s, mesin lebih sibuk), XLSX 3,5 s; 38 asersi |
| AC-11 | Unit teks scope en/id | PASS | 44 |
| AC-12 | F-1 dibalik, PO Report pdf/xls 200; ED-1028 AC-1/12/13/28, ED-1250 AC-10/11 | PASS | AC-12 52; ED-1028 4/4; ED-1250 2/2 |
| AC-13 | `transaction-templates`, select, paper-sizes (Continuous ada) | PASS | AC-13 80, AC-13b 33 |
| AC-14 | Pratinjau A4-L, A5-P, Letter-L, Legal-P, Folio-P, id, template-1/-2, validasi; **kini Continuous-L/-P = 200 dan bentuk salah 422** | PASS | 156 (r2: 150; +6 kasus R-4) |
| AC-15 | Simpan Customize -> pdf dan xls, C-9; **kini Continuous-L tersimpan = ukuran Continuous, B5-P tersimpan = A4-P** | PASS | AC-15 99 (r2: 95), AC-15c 41, D-1 464 |
| **AC-R3** (a+b) | Footer `report-footer-1` di SETIAP halaman GET pdf + pratinjau (lihat tabel) | PASS | AC-R3a 37 asersi (113 kondisi terkumpul), AC-R3b 68 asersi (184 kondisi) |
| **AC-R4** | Continuous-P/-L pratinjau + GET tersimpan = 200 + MediaBox; 422 `paper_size` | PASS | AC-R4 91 asersi (72 kondisi) |
| **AC-R5** | Statis (debug hilang, hunk ada) + PDF 9 laporan lain | PASS | AC-R5 45 asersi (57 kondisi) |

### AC-R3: sesi nyata developer (render dalam proses, BACA SAJA)

`GET` HTTP sebagai QA_USER = **404 `ARCHIVE412`** untuk ketiga sesi (tak terlihat; tidak ada role/izin diberikan, tidak ada penolakan classifier pada putaran ini). Karena itu dirender dengan `qa/dev_render.php`: bootstrap Laravel,
**`SET SESSION TRANSACTION READ ONLY`** pada koneksi default (tulisan apa pun akan melempar exception), login sebagai pemilik sesi, `OpnameExportService::pdf($id [, payload])`. Pengaturan template developer apa adanya (A4-P, report-template-6, header-1, footer-1).
Sebelum/sesudah: baris template, baris cds, template lain (count+checksum) dan tiga tabel opname + `archives` **sama persis**. Footer dicari per halaman sebagai "Printed by <user>"/"Dicetak oleh <user>" dan "Page i of N"/"Halaman i dari N" (teks PDF, nomor halaman = urutan halaman); `gs txtwrite` dipakai terpisah untuk geometri.

| Sesi (pemilik) | Baris terlihat | GET pdf A4-P | Pratinjau A4-L | Continuous-P | Continuous-L | **Kontrol view lama** |
|---|---|---|---|---|---|---|
| `...033097` (lydia, bahasa id) | 97 | 3/3 | 4/4 | 8/8 | 4/4 | 3/3 (footer dulu tampil) |
| `...474864` (kevinsudjadi.adm) | 401 | 11/11 | 16/16 | 30/30 | 16/16 | **0/11** (footer dulu hilang) |
| `...422239` (dirender sbg kevinsudjadi.adm; pemilik `sam` tidak melihat baris Rincian) | 401 | 11/11 | 16/16 | 29/29 | 16/16 | **5/11** |

Pratinjau `{}` identik dengan GET pdf (3/3, 11/11, 11/11). Format = halaman berfooter/total. Semua dengan header perusahaan di setiap halaman.

### AC-R3: sesi QA via HTTP (QA_USER, preset FULL, bahasa en; sesi sisipan `QA251BULK-*`)

| Baris | Kertas | Halaman | Footer | Celah footer-baris terakhir (min) | Baris di PDF |
|---|---|---|---|---|---|
| S1 fixture (±10) | A4-P / A4-L | 1 / 1 | 1/1, 1/1 | 494 / 249 pt | - |
| 50, 199, 200, 201 | A4-P | 2, 6, 6, 6 | semua | 21,7 pt | 50, 199, 200, 201 = jumlah sesi |
| 399, 400, 401 | A4-P | 11, 11, 11 | semua | 21,7 pt | = jumlah sesi |
| 1000 | A4-P | 26 | 26/26 | 21,7 pt | 1000 |
| 201 / 401 / 1000 | A4-L (pratinjau) | 8 / 16 / 39 | semua | 29,7-31,7 pt | = jumlah sesi |
| 401 | Letter-L / Legal-P / Folio-L / A5-P | 16 / 9 / 16 / 12 | semua | 29,7 / 21,7 / 29,7 / 15,1 pt | 401 |
| 401 | `language: id` ("Dicetak oleh", "Halaman i dari N") | 11 | 11/11 | 21,7 pt | 401 |
| 401 | footer hanya `page_number` (Customize) | 11 | "Page i of 11" 11/11, tanpa "Printed by" | - | - |
| 401 | template tersimpan A4-L (Simpan Customize) -> GET pdf | 16 | 16/16 | 29,7 pt | 401 |

Batas potongan `DETAIL_CHUNK` 200 dilewati (199/200/201, 399/400/401). Kontrol negatif detektor: footer dikosongkan lewat Customize -> detektor melihat **0**/11 halaman berfooter (detektor memang bisa melihat footer hilang).
`report-template-1` pada 401 baris juga 16/16 (tidak terpecah per potongan; tidak ada bukti bug pola footer-di-akhir pada template-1/-2).
Pemeriksaan visual gambar halaman: header, tabel, footer tidak bertumpuk (`work/ed1251/r3-images/`: A4-P hal. 1 id dan hal. 5, Continuous-P hal. 2, VAT In hal. terakhir, AP Due). Continuous: footer tepat di bawah baris terakhir (celah 3,2 pt, tidak menimpa).

### AC-R4: Continuous

| Pemeriksaan | Hasil |
|---|---|
| Ukuran sumber `getPaperFormat` | Continuous-P [215,9; 134,62] mm = **612 x 381,6 pt**; Continuous-L [276,86; 210,82] mm = **784,8 x 597,6 pt** (orientasi P, ukuran apa adanya) |
| Pratinjau `Continuous-P/-L`, S1 (1 hal.) dan 401 baris (30 / 16 hal.) | 200; MediaBox semua halaman = ukuran di atas (toleransi 0,3 pt); bukan A4; footer di semua halaman; 401 baris utuh; pratinjau tidak menulis baris template |
| Simpan `paper_size Continuous-P/-L` -> `transaction-templates` -> GET pdf (S1, 401 baris) | 200, MediaBox sama, footer semua halaman; GET xls = 200; pratinjau `A4-P` menimpa kertas tersimpan; simpan kembali kertas semula -> A4-P |
| 422 `paper_size` | `continuous-p`, `Continuous`, `Continuous-X`, `Continuous-PL`, `Continuous -P`, `B5-P`, `A4`, `Continuous--P`, `['Continuous-P']`, `5`: semua 422, pesan memuat `paper_size`; `" Continuous-P\n"` dipangkas TrimStrings = 200 Continuous-P; `""` = tak dikirim (200) |
| Tersimpan di luar pola (`B5-P`) | tetap A4-P, bukan 500 |

### AC-R5: laporan lain (QA_USER role bawaan 3, bahasa id, `api/v5/report/...`, read-only)

| Laporan | Template | Hasil |
|---|---|---|
| VAT In `tax/vat-in` (Agu 2026) | template-6 + header-1 | 200, 7 hal. A4-L, header+footer 7/7, celah header 31,2 / footer 25,7 pt, 151/151 nomor oracle API di PDF |
| VAT Out `tax/vat-out` (Okt 2026) | template-6 | 200, 6 hal., 6/6, 130/130 nomor di PDF |
| PB1, WHT Sales, WHT Purchase, AP Summary | template-6 | 200; 1, 1, 1, 2 hal.; header+footer di semua halaman |
| VAT Summary, AP Due (9 hal.), Journal (template-2, 8 hal.) | template-1 / -2 + header-1 | 200; footer 1/1, 9/9, 8/8 (dicatat, bukan asersi); header tidak menimpa (31,2 pt) |

Baris template laporan lain sudah ada: tidak ada baris dibuat/diubah (count+checksum `transaction_pdf_templates` lain, jumlah cds dan template tetap).

## Defect

Tidak ada pada AC-1..15, AC-R3..R5. **O-1 (pengamatan)** di atas bukan defect AC; bila orkestrator menganggapnya defect item: subtask ED-1259 (layer BE, kode bersama), langkah: Customize pratinjau `paper_size: A5-P` pada sesi apa pun >= 1 halaman,
harapan: baris header ke-5 terlihat di atas judul tabel; aktual: tertutup (`PdfTemplateService::getHeaderHeight` `A5` base `-50px`, tanpa entri `report-header-1`; hunk `getHeaderPadding` developer `A5 => base 0px`).

## Isolasi tenant & permission

- Tenant: `QA_DBS` = satu DB (`api_sidomaju`) -> tidak berlaku. php-fpm; diff putaran ini tidak menambah state statis (`paperConfig()` fungsi privat tanpa state).
- Permission: tidak ada endpoint baru. AC-9 diulang (404 asersi, 78 permintaan, 5xx = 0): `pdf`, `pdf/preview`, `xls` QA_USER 200, QA_USER2 U2 200/404 sesi folder B, R0 403 `GE0114` "Opname Document", tanpa token 401, `display-columns/documentArchiveOpnameResult` R0 403, select 200.
- Sesi nyata developer tidak terlihat QA_USER (404 `ARCHIVE412`): perilaku aturan visibilitas, sesuai harapan.

## Yang tidak bisa diverifikasi (dan kenapa)

- Tenant ke-2 (isolasi), kasus tanpa lisensi, `number_precision` selain `api_sidomaju`, superadmin role 2, memori fpm 128M (mesin 2048M): tidak berubah dari r2.
- Counterfactual R-3 untuk `report-template-1/-2` dan laporan lain dengan Continuous (dev: tetap 500 di `PdfReportService` laporan lain): tidak diuji, di luar putaran.
- Render sesi developer lewat HTTP: tidak mungkin (404), diganti render dalam proses baca saja (diizinkan prompt). Tidak ada penolakan classifier.

## Di luar item (bukan defect item ini)

1. **O-1** A5-P header 5 baris tertutup (di atas); pra-ada, diperbaiki sebagian oleh hunk developer.
2. `report-template-1/-2` (footer di akhir): probe 16/16, 9/9, 8/8, 1/1 halaman berfooter; usulan dev memindahkan footer di sana tampak belum perlu kecuali ada laporan yang `html-separator`-nya memecah isi.
3. Padding header 20px developer (R-5) berlaku semua `report-header-1`: A4 celah header-baris pertama menyempit 28,6 -> 13,6 pt (tetap positif; VAT In 31,2 pt).
4. Tidak berubah dari r2: C-9 terbukti (Simpan Customize tanpa cek permission), sel Excel berawalan `=`, template tersimpan buatan tangan rusak = 500 (PdfTemplateService), `users.language` tak sah = 500, PUT cds sebelum baris ada = 500 (A-1), footer `h:i:s` 12 jam, AC-10 memori 128M.

## Perubahan skrip QA (milik item ini; tidak menyentuh ED-1028/ED-1250)

- `qa/scenario3-template.php`: **baris 199** (dulu `'paper_size Continuous-L' => 422`) -> baris 199-203: Continuous-L dan -P = **200**, `Continuous` tanpa orientasi dan `continuous-p` = 422; **baris 359-377** (dulu 356-363: Continuous-L tersimpan = A4-P 595,28 x 841,89) -> Continuous-L tersimpan = **784,8 x 597,6 pt**,
  ditambah B5-P tersimpan (di luar pola) = A4-P + GET xls 200. `qa/scenario1-template.php:173` hanya teks label.
- Baru: `qa/scenario8b-review.php` (AC-R3a, R3b, R4, R5; 32,6 KB), `qa/qa_review.php` (helper footer/geometri `gs txtwrite`/kertas), `qa/dev_render.php` (render dalam proses read-only; `QA251_OLD_VIEWS` kontrol negatif),
  `qa/fixtures/oldviews/.../report-template-6.blade.php` (view sebelum perbaikan R-3, untuk kontrol negatif). Hasil per PDF: `work/ed1251/r3-results.json`.

## Riwayat ronde

- r1 (2026-10-24/25): BE FAIL - D-1 (Excel berputar bila kedua tabel tanpa kolom). 27/29 skenario PASS. Data uji dihapus; 0 sisa.
- r2 (2026-10-25): BE PASS - D-1 tertutup (fallback tabel tersimpan -> display setting -> config); `scenario8-fallback.php`; regresi 32/32, ED-1028 4/4, ED-1250 2/2; data uji 0 sisa; baris cds/template sendiri 0 -> 0.
- r3 (2026-10-26): BE PASS - revisi review R-3 (footer semua halaman; akar `<htmlpagefooter>` di potongan terakhir, kontrol view lama mereproduksi 0/11 dan 5/11), R-4 (Continuous), R-5 (hunk developer, `info($paper)` hilang, 9 laporan lain 200). Skenario baru `scenario8b-review.php`;
  `scenario3` disesuaikan kontrak R-4; regresi 36/36, ED-1028 4/4, ED-1250 2/2. Pengamatan O-1 (A5-P). Baris template/cds developer 1/1 -> 1/1 byte-identik; data QA 0 sisa.
