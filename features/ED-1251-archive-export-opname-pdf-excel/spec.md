---
key: ED-1251
epic: ED-1022
title: Archive - export PDF & Excel hasil opname (drawer Opname History & Opname Result)
status: approved
batch: ED-1251
fe_modules: [Archive]
new_fe_modules: []
be_modules: [DocumentArchive, Select/Report, Setup/Setting]
qa_model: sonnet
contract: contract.md
depends_on: []                # dibangun di atas ED-1026 & ED-1028 (Done); urutan epic: sesudah ED-1250 (gate1 r6 §4)
---
## Revisi Gate 1 (2026-10-24)

Jawaban developer (`epics/ED-1022-gate1.md` §9) dan perubahan cek epic §8 diterapkan. **(rev)** = baru/berubah.

| Asal | Perubahan | BR / AC |
|---|---|---|
| K-2 diubah | Print = `DrawerPdf` PO Report persis **dengan template laporan dan Customize berfungsi**; tanpa prop penyembunyi Customize. Mesin template → K-9, sumber kolom sesudah Simpan → K-10 (baru) | BR-4, 17; AC-13..15, 18, 19 |
| K-3 b | Satu baris display setting `documentArchiveOpnameResult` pola PO: Ringkasan di `summaries_columns`, Rincian di `current_columns`/`available_columns` | BR-15, 16; AC-8 |
| K-4 b | Angka seperti PO ("398.00"), tanpa tipe `integer`, trait bersama tidak diubah | BR-11; AC-3 |
| K-6 a + syarat | Catatan hanya bila ≥1 baris export ini disembunyikan dari pemanggil | BR-18; AC-7 |
| gate1 C-5, C-6, F-1, F-2, O-4 | role preset `QA251-ROLE-*`; helper q28 disalin ke `qa/` sendiri; AC-12 (+ED-1028 scenario9, ED-1250 AC-10/11); `histRows` e2e; dok FE `028` | §6, AC-12, 17; §7 |
| Temuan baru | Customize PO Report sendiri gagal (route `selected-pdf-templates` tidak ada) → K-11 | §8 |
| Gate 1 lanjutan (2026-10-24) | K-9 a, K-10 a **diubah** (Excel ikut sumber kolom PDF), K-11 c, DU-5 a diperluas ke baris template, K-5 a hanya Excel | BR-4, 15; AC-8, 15, 19; §8 |

## 1. Ringkasan

User ber-`Opname Document` bisa mengunduh **hasil satu sesi opname** sebagai PDF dan Excel dari kolom aksi kanan tabel drawer
**Opname History** (per baris) dan baris judul drawer **Opname Result**. Print membuka drawer pratinjau PDF yang sama dengan Purchase
Order Report (Print, Download, Customize), memakai template laporan sendiri (`documentArchiveOpnameResult`) yang bisa diatur lewat
Customize. Isi: header (perusahaan, judul, Folder / Opname Date / Opname By), tabel Ringkasan (Total Documents, Verified, Not Found,
Invalid, Not Scanned) dan Rincian (Document No., Transaction Type, Folder, Time, Result), tanpa warna FE. Kolom dari satu baris
`column_display_settings` (BE saja). Isi = yang dilihat user di drawer Opname Result. Tanpa tabel/permission/Updater baru.

## 2. Aturan bisnis

| BR | Aturan | Sumber |
|---|---|---|
| BR-1 | Opname History: kolom FE tambahan **tetap di kanan** (di luar display setting, tanpa judul), 2 tombol ikon saja Print (printer) dan Excel (file Excel) untuk sesi baris itu; klik tombol tidak membuka drawer Opname Result | J-1; `ModalImportExportExcelDrawerHistory/Content.js:33-41`; `ButtonExport/index.js:46-59`; `isCellActionClick` `archive.function.js:524-531` |
| BR-2 | Opname Result: judul "Opname Result" (id "Hasil Opname") dan kedua tombol dibungkus satu `div` flex `space-between` (judul kiri, tombol kanan); subjudul tetap di bawah | J-1; `ResultDrawer.js:104-110`; `.flex-justify-between` `common.less:182` |
| BR-3 | Tooltip (desktop) dan `aria-label` "Print Preview" / "Excel" (`common.Print Preview`, `common.Excel`); tanpa `id` DOM ganda (`ButtonExport` memasang `id={config.key}` `:84`) | `en_US.js:98-100` |
| BR-4 (rev) | Print = `DrawerPdf` seperti PO Report: viewer + Print + Download + **Customize** (template, header, footer, kertas, bahasa, kolom tabel) dengan 10 endpoint PDF (contract §9); Excel = unduh blob `.xlsx` (nama dari `Content-Disposition`); dari Customize hanya **kolom tabel** (pilihan, urutan, judul) yang ikut berlaku untuk Excel (BR-15), kertas/header/footer/template hanya PDF | K-2 (diubah); `ReportPurchaseOrderPage/index.js:88-96`; `reportPurchaseOrder.function.js:23-41,53-59` |
| BR-5 | Unduh Excel: tombol itu `loading` (klik ulang tanpa request kedua), tombol lain tetap; gagal → notifikasi error interceptor (blob = pesan generik, sama dengan PO), tombol normal lagi | `reportPurchaseOrder.function.js:23-26`; `utils/request.js:110-124` |
| BR-6 | Yang diekspor = satu sesi terkonfirmasi (`status = 2`); pratinjau Step 3 tidak diekspor | ED-1026 FB-3; `OpnameService.php:682-700` |
| BR-7 (rev) | `pdf`, `pdf/preview`, `xls`: `permission_v5:Opname Document` (tanpa → 403 `GE0114` `parameter` "Opname Document"; juga kasus tanpa lisensi, EPIC K-2 b); sesi tidak ada / id bukan ASCII / tak terlihat → 404 `ARCHIVE412`. Route select template ikut konvensi select (tanpa `permission_v5`), hanya mengembalikan template & definisi kolom, tanpa data sesi | ED-1028 K-8 a; `api.php:40-51`; `OpnameService.php:682-700,968-976`; responses.md §4 |
| BR-8 (rev) | Header, per baris: (1) `companies.company_name`; (2) judul en "Opname Archive" / id "Opname Arsip" (K-7 a); (3) "Folder: <scope>"; (4) "Opname Date: …" (id "Tanggal Opname"); (5) "Opname By: …" (id "Diopname Oleh"); (6) catatan BR-18. PDF: field `companyName`/`reportName`/`reportFilter` `report-header-1` (baris 3-6 = `reportFilter`, tiap baris di-escape, digabung `<br>`; Customize boleh menyembunyikan field). Excel: `ReportHeader1` baris sama. Bahasa: PDF = `language` template (default `users.language`, Customize pratinjau bisa memilih), Excel = `users.language` | J-1; `layouts/headers/report-header-1.blade.php:28-43` (`reportFilter` mentah `{!! !!}`); `ReportHeader1.php:11-47`; `PdfTemplateService.php:1030` |
| BR-9 | <scope> = teks tag Scope FE `getScopeTag`: root "All Archive · root" (id "Semua Arsip · root"), root + N "… · root + N subfolder", folder = nama, folder + N "<nama> + N subfolder"; N = `scope_folder_count` (subfolder dicentang Step 1); nama snapshot | ED-1026 K-14 a; ED-1028 K-10 b; `archive.function.js:491-505`; `OpnameService.php:660-672`; `ArchiveOpname.php:38,79` |
| BR-10 | Opname Date = `confirmed_at` apa adanya (WIB, tanpa konversi; jam mesin UTC tidak berpengaruh), `d M Y H:i`, bulan id (Okt, Agu, Des, Mei) | `AppServiceProvider.php:20`; `OpnameService.php:375`; `MyHelper.php:621-665` |
| BR-11 (rev) | Ringkasan = satu baris angka **rekaman** (`total_documents`, `verified_count`, `not_found_count`, `invalid_count`, `unscanned_count`; ED-1028 K-5 b), tipe `number` seperti PO → "398.00" (PDF & Excel, `number_precision` 2 di `api_sidomaju`) | K-4 b; `ArchiveOpnameCounts/index.js:17-23`; `OpnameService.php:1663-1672`; `reportPurchaseOrder.php:367-379` |
| BR-12 | Rincian = semua baris yang boleh dilihat pemanggil (`whereVisibleRows`; Invalid selalu; superadmin 1/2 semua), seluruh hasil (= All, K-1 a), tanpa paginasi, urutan = `GET opnames/{id}/documents` | `OpnameService.php:712-735,1710-1713` |
| BR-13 | Kolom Rincian: Document No. = `code`; Transaction Type = label (kosong "—"); Folder = nama snapshot, root "All Archive"/"Semua Arsip", Not found "Outside opname scope"/"Di luar scope opname", Invalid "—"; Time = `HH:mm` `scanned_at` (belum discan "—"); Result = label en/id `ArchiveOpnameDocument::RESULT_LABELS`. Label mengikuti bahasa berkas (BR-8) | `archive.function.js:758-817`; `ArchiveOpnameDocument.php:40-45` |
| BR-14 (rev) | Tanpa warna FE (hijau/oranye/merah): sel/baris/kolom Result tanpa latar & warna. PDF memakai palet template yang dipilih (K-9: judul kolom #e5e8eb teks #9194a4, garis #eff2f5, teks 75% hitam, filter #808080); Excel gaya PO (judul kolom #7A7A7A teks putih, info abu-abu miring; K-5 a berlaku untuk Excel saja) | J-1; `styles/templates/report-template-1.blade.php:45-55`; `ReportTemplate5.php:191-206` |
| BR-15 (rev) | Display setting **satu** baris `documentArchiveOpnameResult` (K-3 b): Ringkasan = `summaries_columns`, Rincian = `current_columns` (+`available_columns`). **Sumber kolom PDF dan Excel sama** (K-10 a diubah): selama `tables` template kosong, kedua berkas memakai display setting; sesudah Customize disimpan, kedua berkas memakai tabel template tersimpan (kolom, urutan, judul). Customize menampilkan kolom dari sumber yang sama. Baris dibuat dari config saat dipakai pertama; tanpa UI Display Setting baru | J-1; `MyHelper.php:452-496`; `PurchaseOrderReportService.php:23` |
| BR-16 (rev) | `documentArchiveOpnameResult` = entri seluruh modul `Opname Document` di `PERMISSION_FILTERS`: tanpa permission `show`/`update` 403 `GE0114`, `index` tanpa barisnya | ED-1028 K-8 a/K-9 a; `ColumnDisplaySettingService.php:28-32,142-185` |
| BR-17 (rev) | Template PDF `transaction_pdf_templates.transaction_name = documentArchiveOpnameResult` (VARCHAR 30), dibuat dari config saat `getTemplate` pertama (tanpa Updater). Default (K-9 a): `report-template-6`, `report-header-1` (fields companyName, reportName, reportFilter, repeat), `report-footer-1` (timestamp, page_number, printed_by), `A4-P`. Pratinjau Customize = `POST …/pdf/preview` merender payload tanpa menyimpan; Simpan = `POST setting/pdf-templates/update` yang ada. Judul tabel "Summaries"/"Ringkasan", "Detail"/"Rincian" | `PdfTemplateService.php:1007-1037,1129-1152`; `reportVATIn.php:1-21`; `PdfReportService.php:13-45` |
| BR-18 (rev) | Catatan "Some documents outside your access are not shown" / "Sebagian dokumen di luar akses Anda tidak ditampilkan" hanya bila pemanggil bukan bypass **dan** jumlah baris Rincian export ini < jumlah seluruh baris snapshot sesi (semua hasil: cakupan + Not Scanned + Not Found + Invalid) = perbandingan `is_partial` `show` | K-6 a (syarat developer); `OpnameService.php:578-581` |
| BR-19 | Nama berkas `Opname_Archive_<confirmed_at d-M-Y_His>.pdf|.xlsx`, `Access-Control-Expose-Headers: Content-Disposition` | K-7 a; `PdfReportService.php:42-44`; `formHelper.js:300-308` |
| BR-20 | Rincian tanpa baris: PDF judul + tabel kosong (`ReportTemplate6.php:66-69`), Excel tabel Rincian tidak ditulis (`ReportTemplate5.php:34`) - bukan defect | |

Kolom v3: tidak ada; item ini hanya membaca `archive*` (D-4/D-5 tidak berlaku).

## 3. Model data

| Hal | Isi |
|---|---|
| Tabel | Tidak ada yang baru/berubah. Dibaca: `archive_opnames`, `archive_opname_folders`, `archive_opname_documents`, `archive_documents`, `archives`, `archive_permissions`, `companies`. Baris konfigurasi dibuat aplikasi: `column_display_settings`, `transaction_pdf_templates` (kolom sudah ada di `api_sidomaju`) |
| Display setting (rev) | Baru `Config/displayColumn/documentArchiveOpnameResult.php`, bentuk `reportPurchaseOrder.php` (`current_columns`, `available_columns`, `summaries_columns`, queries `[]`); daftar di `Config/config.php` sesudah `:332`. Isi: contract §6 |
| Template PDF (rev) | Baru `Config/pdfTemplate/transaction/documentArchiveOpnameResult.php` (contract §7), daftar di `config.php` `pdfTemplate.transaction` sesudah `reportPurchaseOrder` (`:514`). `tables` `[]` di config: diisi server dari display setting (K-10 a) |
| Peta saringan | `PERMISSION_FILTERS` + 1 entri `['permission' => VerificationService::PERMISSION]` (seluruh modul; cocok definisi ED-1250) |
| Konstanta label | `ArchiveOpname`: judul laporan & label header en/id (pola `ROOT_LABELS` `:38`); `ArchiveOpnameDocument`: "Outside opname scope" en/id |
| Updater / permission / kode pesan | Tidak ada (baris konfigurasi dibuat saat dipakai; `Opname Document` 1139 lewat nama; `GE0114`, `ARCHIVE412`, `GE0111`, `PDF400/401/404` yang ada) |

## 4. Endpoint v5

| Route | Controller@method | Service | Permission | Status |
|---|---|---|---|---|
| GET `document-archive/opnames/{id}/pdf` | `OpnameController@pdf` | `OpnameExportService::pdf($id)` (baru): `PdfReportService::transactionTemplate` + tabel (K-10) + `stream` (pola `VATInReportService.php:288-303`, kolom dari display setting `SalesInvoiceReportService.php:110-149`) ← `OpnameService::exportData($id, $language)` (baru, public) | `Opname Document` | baru |
| POST `document-archive/opnames/{id}/pdf/preview` | `OpnameController@pdfPreview` | `OpnameExportService::pdf($id, $request->all())` (payload Customize = template) | `Opname Document` | baru |
| GET `document-archive/opnames/{id}/xls` | `OpnameController@xls` | `OpnameExportService::xls($id)`: trait `ExportReportXls::streamXls` (pola `PurchaseOrderReportService.php:123-129`) | `Opname Document` | baru |
| GET `select/report/document-archive/opname/{pdf-templates, selected-pdf-templates, transaction-templates, header-templates, selected-header-templates, footer-templates, selected-footer-templates, paper-sizes}` | `Select/Report/SelectArchiveOpnameReportController` (baru, salin `SelectPurchaseOrderReportController.php` + `selectedPdfTemplate` `SelectVATInReportController.php:60-64`) | `PdfTemplateService`; `transactionTemplate` → `OpnameExportService::template()` (getTemplate + tabel K-10) | - (select) | baru |
| POST `setting/pdf-templates/update` | `PdfTemplateController@update` | `PdfTemplateService::update` | - | sudah ada |
| GET/PUT `setting/display-columns/{moduleName}`, GET `setting/display-columns` | `ColumnDisplaySettingController` | entri `PERMISSION_FILTERS` | - | ubah |

Route `{id}/pdf`, `{id}/pdf/preview`, `{id}/xls` di grup `opnames` (`api.php:42-51`). Prefix `select/report/…` wajib: `getHeaderTemplate`/
`getFooterTemplate`/`getPdfTemplate` hanya menampilkan template laporan bila segmen ke-4 path = `report` (`PdfTemplateService.php:1068,1089,1110`).

## 5. Perubahan FE per module

**Archive** (master + drawer riwayat). Blueprint: `containers/ReportPurchaseOrder` (`DrawerPdf`, blob Excel), kolom aksi
`ModalImportExportExcelDrawerHistory/Content.js:33-41`.

| Bagian | Isi |
|---|---|
| `configuration/endpoints.js` | `getArchiveOpnamePdf`, `postArchiveOpnamePdfPreview`, `getArchiveOpnameXls` (`document-archive/opnames/:id/...`) + 8 `getSelectArchiveOpnameReportPdf*` (`select/report/document-archive/opname/...`), pola `:2458-2465` |
| `components/DrawerPdf/drawerPdf.constant.js` (rev) | `pdfTransactions.documentArchiveOpnameResult = 'documentArchiveOpnameResult'` (= BE `transaction_name`, dikirim sebagai `transactionName` Customize `drawerPdf.function.js:207`) dan `customizeSubtitle` → `archive.Opname Result` (pola `:74`, `:247`). Komponen DrawerPdf lain tidak berubah |
| `archive.api.js` / `archive.function.js` | `getArchiveOpnameXls` `{ raw: true, responseType: 'blob' }` + `downloadFile`; objek `pdfEndpoints` 10 kunci (pola `reportPurchaseOrder.function.js:30-41`) |
| Komponen tombol (baru) | `PrinterFilled`/`FileExcelFilled`, tooltip + `aria-label` BR-3, `loading` BR-5; dipakai kedua drawer |
| `ArchiveOpnameHistory/Content.js` | kolom aksi `{ fixed: 'right', align: 'center', className: 'p-0', actionColumn: true }` (BR-1); `onRow` (`:52-55`) mengabaikan klik tombol (`isCellActionClick`); `<DrawerPdf id={idArchiveOpname} endpoints={pdfEndpoints} transaction={…} width="100%" />` di atas drawer riwayat, tutup = halaman/filter sama |
| `ArchiveOpnameHistory/ResultDrawer.js` | judul `<div className="flex-justify-between">` judul + tombol (BR-2), subjudul tetap; `DrawerPdf` sendiri |
| Dok FE | `docs/specs/Archive/028-*` (gate1 O-4), `_api.md`, `_module.md` |
| Tetap | display setting riwayat, filter, `CheckableTag`, kartu, permission (ED-1027 rev). Tanpa locale/route/menu/permission FE baru |

## 6. Acceptance criteria

QA DB `api_sidomaju`. Role (gate1 C-5, ED-1149 BR-1/BR-4): preset `QA251-ROLE-FULL` (QA_USER: akses website, List Archive, OD,
semua lokasi), `QA251-ROLE-U2` (QA_USER2 = user2: OD, lokasi/View terbatas), `QA251-ROLE-R0` (List Archive tanpa OD, untuk QA_USER2).
Isi berkas dibaca PHP: XLSX `ZipArchive` + SimpleXML (`sharedStrings`, `sheet1`, `styles`); PDF `%PDF-`, stream `FlateDecode` →
`gzuncompress`, teks dicari UTF-16BE (font unicode mPDF, probe 2026-10-23), warna operator `rg/RG/g/G`, orientasi dari `/MediaBox`.

**Data uji & lingkungan**

| Hal | Isi |
|---|---|
| Helper | salinan helper ED-1028 `qa_lib.php` `q28_*` di `qa/` sendiri, jurnal & prefix sendiri (`0QA251-`, `QA251-`) - tidak `require` folder ED-1028 (gate1 C-6) |
| Sesi | `POST opnames` oleh QA_USER: **S1** folder F + subfolder A (View user2) & B (Izin Folder On tanpa user2), dokumen Verified/Not found/Invalid/belum discan; **S2** folder G sepenuhnya terlihat user2. Dipulihkan `sweep` (pra-citra `archives`, hapus baris sesi sendiri). S1/S2 sementara jadi sesi terbaru di overview/riwayat (C-7). 3 sesi nyata hanya dibaca |
| Sesi besar / root | S-BULK 10.000 baris `QA251BULK%` disisipkan langsung lalu dihapus. Sesi root **tidak** dibuat (menulis `is_verified` ±34,5 rb arsip); teks scope root = unit |
| Tulis di luar baris sendiri | permanen (DU-5 a, perluasan ke template disetujui 2026-10-24): baris `column_display_settings` dan `transaction_pdf_templates` `documentArchiveOpnameResult` yang dibuat aplikasi. Berjurnal & dipulihkan persis: AC-8 (baris display setting, jeda ≥1 s, A-1), AC-15/AC-19 (baris template), `users.language` QA_USER, `user_roles` QA_USER/QA_USER2, preset. Tidak ada tabel dikosongkan |
| Lingkungan | DU-6 a: developer tidak memakai Archive/opname/display setting/role `api_sidomaju` dan QA_USER* selama run. mPDF 8.2.3, laravel-mpdf, maatwebsite/excel 2.1.30 + PHPExcel; gd/mbstring/zip/xml PHP 7.3.33; fpm 128M (K-8 a); viewer `DrawerPdf` memuat worker `unpkg.com` (e2e tidak bergantung render viewer); profil default |

| AC | Given / When / Then | Layer | BR | Cek |
|---|---|---|---|---|
| AC-1 | QA_USER S1: `GET …/{S1}/pdf` → 200 `application/pdf`, `Content-Disposition` `Opname_Archive_<d-M-Y_His>.pdf`, expose header, `%PDF-`; `/xls` → 200 spreadsheet `.xlsx`, ZIP berisi `sheet1.xml` | BE | 4,19 | http |
| AC-2 | (en) XLSX & PDF memuat "Sido Maju Lestari" (= DB), "Opname Archive", "Folder: <scope S1>", "Opname Date: <confirmed_at d M Y H:i>" (teks DB), "Opname By: <created_by>"; PDF juga footer printed by = username | BE | 8-10,17 | http + SQL + isi berkas |
| AC-3 | Ringkasan: judul "Summaries", 5 kolom urut `summaries_columns`, satu baris = angka `archive_opnames` S1 tertulis "398.00"-gaya (2 desimal) di PDF; XLSX sel numerik format 2 desimal | BE | 11,15 | http + SQL + isi berkas |
| AC-4 | Rincian: judul "Detail", 5 kolom; baris = gabungan semua halaman `GET opnames/{S1}/documents?result=all` pemanggil yang sama, urutan sama, nilai BR-13 | BE | 12,13 | http + isi berkas |
| AC-5 | `users.language = id`: judul/label/bulan/judul tabel & kolom/hasil/"Semua Arsip"/"Di luar scope opname" id di PDF & XLSX; dipulihkan | BE | 8,10,13 | http + isi berkas |
| AC-6 | Tanpa warna FE: PDF tanpa warna hijau/oranye/merah, warna hanya palet template (BR-14), sel Result tanpa `re f`; XLSX sel data tanpa `fill` solid, font hitam/abu | BE | 14 | isi berkas |
| AC-7 | Catatan BR-18: user2 S1 → ada; user2 S2 → tidak ada; QA_USER (FULL, tak ada yang tersembunyi) S1 → tidak ada; bypass (superadmin 1/2) → tidak ada (http bila ada login QA superadmin, selain itu unit `context['bypass']`); Ringkasan user2 = QA_USER; Rincian user2 S1 = `/documents?result=all` user2 | BE | 8,11,12,18 | http dua user + unit |
| AC-8 | Template **belum** disimpan (`tables` kosong): baris `documentArchiveOpnameResult` = config; `PUT` tanpa kolom Time (current) dan tanpa Invalid (`summaries_columns`) → PDF **dan** XLSX tanpa kedua kolom itu; baris dipulihkan persis | BE | 15 | http + DB |
| AC-9 | R0: `pdf`, `pdf/preview`, `xls` → 403 `GE0114` `parameter` "Opname Document" (JSON); `GET`/`PUT setting/display-columns/documentArchiveOpnameResult` → 403, DB tetap; index tanpa baris itu; route select template → 200 tanpa data sesi (konvensi). Tanpa token 401; id tidak ada/non-ASCII/tak terlihat user2 → 404 `ARCHIVE412`. Tanpa 500 | BE | 7,16 | http tiga user |
| AC-10 | S-BULK 10.000 baris: PDF (template default) dan XLSX 200, waktu dicatat (< 60 s), PDF memuat baris pertama & terakhir | BE | 12,17 | http + isi berkas |
| AC-11 | Unit: teks scope BR-9 root/0, root/3, folder/0, folder/3 en & id = string `getScopeTag` FE | BE | 9 | unit |
| AC-12 | Regresi: ED-1028 AC-1/12/13; ED-1028 `scenario9-display-setting.php:127,130,138` (F-1, asersi diumumkan: R0 total −2, selisih = `documentArchiveOpnameHistory` + `documentArchiveOpnameResult` bila barisnya ada, pemegang `search=documentArchive` 3 baris); ED-1250 AC-10/11; PO Report `pdf`/`xls` 200 | BE | 15,16 | http (suite pemilik) |
| AC-13 | `GET select/report/document-archive/opname/transaction-templates` → 200: `transaction_name` `documentArchiveOpnameResult`, `report-template-6`/`report-header-1`/`report-footer-1`, `A4-P`, header fields BR-17, `tables` 2 (`summaries`, `data`; nama en/id; current/available = display setting); baris `transaction_pdf_templates` dibuat bila belum ada. `header-/footer-/pdf-templates` hanya `is_report=1`; `selected-header/footer/pdf-templates` dan `paper-sizes` 200 | BE | 15,17 | http + DB |
| AC-14 | `POST …/{S1}/pdf/preview` dengan payload template: `A4-L` → MediaBox lebar > tinggi; header tanpa `reportFilter` → tanpa "Folder:"; Rincian tanpa `scanTime` → tanpa "Time"; `language` id → label id; `report-template-1` dan `-2` → 200; `transaction_pdf_templates` tidak berubah | BE | 8,17 | http + isi berkas + DB |
| AC-15 | `POST setting/pdf-templates/update` (Rincian tanpa Time, urutan Result sebelum Folder, Ringkasan tanpa Invalid) → `GET pdf` **dan** `GET xls` keduanya tanpa Time/Invalid dengan urutan baru (K-10 a); judul tabel tetap ada; `PUT` display setting sesudahnya tidak mengubah kedua berkas; baris template & display setting dipulihkan persis | BE | 4,15,17 | http + DB + isi berkas |
| AC-16 | Layar terbuka tanpa error konsol/request gagal: Opname History (modal 05, tab Verification, modal verifikasi folder), Opname Result, `DrawerPdf`, Customize - desktop & 375 px | FE | 1,2,4 | e2e |
| AC-17 | Opname History: tiap baris kolom kanan tetap dengan tepat 2 tombol ikon (tanpa teks/judul); tooltip desktop; `aria-label`; tanpa `id` ganda; 375 px tetap terlihat. e2e ED-1028 `histRows` (`:266-272`, dipakai `:547,632,731,1450`) mengabaikan kolom aksi (F-2) dan hijau | FE | 1,3 | e2e |
| AC-18 | Print baris S → `DrawerPdf` di atas drawer riwayat, satu `GET …/{S}/pdf` 200, drawer Opname Result tidak terbuka; tombol Print, Download, Customize ada; tutup → riwayat halaman & filter sama | FE | 1,4 | e2e |
| AC-19 | Customize → `transaction-templates`, `selected-header-templates`, `selected-pdf-templates`, `selected-footer-templates` 200, form selesai dimuat, tabel Ringkasan & Rincian berisi kolom display setting; ubah orientasi → `POST …/pdf/preview`; Simpan → `POST setting/pdf-templates/update` 200, PDF dimuat ulang; lalu Excel → berkas mengikuti kolom tersimpan; template dipulihkan | FE | 4,15,17 | e2e + DB |
| AC-20 | Excel baris S → satu `GET …/{S}/xls`, download `Opname_Archive_*.xlsx`, tombol itu `loading` (baris lain tidak), drawer hasil tidak terbuka | FE | 1,4,5 | e2e |
| AC-21 | Opname Result: judul kiri & 2 tombol kanan dalam satu wadah flex space-between, subjudul di bawah; aksi memakai sesi yang dibuka; 375 px tanpa tumpuk/terpotong | FE | 2,3 | e2e |
| AC-22 | `/xls` atau `/pdf` dicegat 404/500 → notifikasi error, tanpa crash, tombol normal; bahasa id → tooltip/aria id | FE | 3,5 | e2e (intercept) |
| AC-23 | PDF/Excel rapi, hitam-putih tanpa warna FE, posisi tombol sesuai klien | BE+FE | 2,8,14 | manual (Gate 2) |

## 7. Subtask

| Kunci | Judul | Layer | AC | Module |
|---|---|---|---|---|
| ED-1258 | [BE] Archive - display setting & template PDF hasil opname (config display + pdfTemplate, registrasi, PERMISSION_FILTERS, route select template laporan) | BE | AC-8, AC-9, AC-12, AC-13 | `Config`, Setup/Setting, Select/Report (`SelectArchiveOpnameReportController`), `OpnameExportService::template` |
| ED-1259 | [BE] Archive - export PDF (template + pratinjau Customize) & Excel satu sesi opname, sumber kolom bersama | BE | AC-1..AC-7, AC-9..AC-11, AC-14, AC-15 | DocumentArchive (route, `OpnameController`, `OpnameService::exportData`, `OpnameExportService`, label model) |
| ED-1260 | [FE] Archive - tombol Print & Excel di Opname History dan Opname Result (DrawerPdf + Customize seperti PO Report) | FE | AC-16..AC-22 | Archive, `drawerPdf.constant.js`, dok `028` |
| ED-1261 | [QA] Skenario export PDF & Excel hasil opname (isi berkas, template/Customize, akses, display setting, skala, e2e, F-1/F-2) | QA | AC-1..AC-23 | - |

## 8. Keputusan untuk developer

**Dijawab 2026-10-24** (`epics/ED-1022-gate1.md` §9 + jawaban lanjutan): K-1 **a** (Rincian = All) · K-2 **diubah** (Customize
berfungsi → K-9, K-10) · K-3 **b** (1 baris pola PO) · K-4 **b** ("398.00") · K-5 **a**, **hanya untuk Excel** (PDF mengikuti gaya
template laporan K-9, tetap tanpa warna FE) · K-6 **a** dengan syarat (BR-18) · K-7 **a** · K-8 **a** · K-9 **a** · K-10 **a diubah**
(Excel ikut sumber kolom PDF) · K-11 **c** · DU-5 **a** (juga baris `transaction_pdf_templates`) · DU-6 **a**. Tidak ada K terbuka. Bukan pertanyaan: akses `Opname Document` (ED-1028 K-8 a), Ringkasan
rekaman/Rincian tersaring (K-5 b), display setting seluruh modul (K-8 a/K-9 a), uji lisensi EPIC K-2 b.

**K-9 (rendah, dijawab: a) - Mesin PDF agar template & Customize benar-benar berlaku.**
Situasi (dicek kode): PDF PO Report dibuat trait `ExportReportXls::streamPdf` dari HTML sendiri dan **mengabaikan** template; `pdf/preview`
= `pdf` (`PurchaseOrderReportService.php:131-142`); config PO `template-1`/`header-1`/`footer-1` bukan template laporan (`is_report = 0`,
pilihan Customize laporan hanya `report-*`). Jadi meniru PO persis = Customize tidak mengubah PDF. Laporan yang Customize-nya berlaku
(64 service, mis. `VATInReportService.php:288-303`) memakai `PdfReportService` + `report-template-*` (`template-1` ditolak `PDF404`,
`PdfReportService.php:15-18`), dengan tampilan lain: judul kolom latar #e5e8eb teks #9194a4, garis tipis, footer cetak/halaman.
a) pola laporan ber-template (`report-template-6` untuk data besar, `ReportTemplate6.php:6-26`); b) PO persis, Customize hanya UI;
c) trait PO dibuat membaca template (kode baru di trait bersama).
Rekomendasi **a** - satu-satunya mekanisme yang membuat Customize benar-benar mengubah PDF, sesuai "template seperti report".
Jawaban developer 2026-10-24: **a** - `PdfReportService`, `report-template-6`/`report-header-1`/`report-footer-1`, A4-P, pratinjau = payload Customize → BR-17.

**K-10 (rendah, dijawab: a diubah) - Sesudah Customize disimpan, kolom tabel PDF dari mana?**
Situasi: Customize mengambil tabel dari template (`transaction-templates`), bukan display setting (43/43 controller select laporan hanya
`getTemplate`); developer ingin display setting "dipanggil di drawer pdf dan di customize". Simpan menyimpan `tables` ke
`transaction_pdf_templates` (FE tidak mengirim `name` tabel, `drawerPdf.function.js:233-252`).
a) display setting selama `tables` template kosong, tabel tersimpan sesudah Simpan; b) PDF selalu display setting; c) Simpan ikut
menulis display setting.
Rekomendasi **a** - Customize berlaku seperti laporan lain, display setting tetap sumber awal dan Excel.
Jawaban developer 2026-10-24: **a, diubah** - "excel juga sama, bukan selalu dari display setting": PDF dan Excel memakai sumber kolom
yang sama (display setting selama template belum disimpan, tabel template tersimpan sesudahnya) → BR-4, BR-15, AC-8, AC-15, AC-19.

**K-11 (rendah, dijawab: c) - Customize PO Report (dan laporan lain) membuka route yang tidak ada.**
Situasi: Customize memanggil `selected-pdf-templates` (`drawerPdf.function.js:507-512`); PO Report tidak punya route itu
(`endpoints.js:2463` vs `select.php:2171-2179`, router 2026-10-24: 404); hanya 18 dari 50 grup select laporan punya route itu
(`select.php:1564-2760`). Akibat: notifikasi error, `Promise.all` gagal sebelum loading form dimatikan. Item ini tetap menambahkannya
untuk dirinya.
a) tiket Bug terpisah; b) perbaiki PO Report di item ini; c) dibiarkan tanpa tiket.
Rekomendasi **a** - di luar cakupan Archive, menyentuh ±32 laporan.
Jawaban developer 2026-10-24: **c** - laporan lain dibiarkan, tanpa tiket; item ini hanya menambah `selected-pdf-templates` untuk laporannya sendiri.

## 9. Di luar cakupan

- Export dari Step 3 modal opname, overview 3 sesi (ED-1027), daftar riwayat banyak sesi.
- UI Display Setting FE untuk tabel export (klien: menyusul); kertas/header/footer/template Customize untuk Excel (hanya kolom yang ikut).
- `warnings`, rincian `folders`, kolom Salesman/No. transaksi; permission Print/Export baru; aturan sesi terlihat (ED-1028); filter ED-1250.
- Route `selected-pdf-templates` laporan lain (K-11 c: dibiarkan, tanpa tiket); gaya/desimal laporan lain.

## Catatan implementasi

- `exportData`: tanpa paginasi, `ArchiveDocument::whereIn` per `CHUNK` (`OpnameService.php:44`), baris lewat `formatRow` (`:1756-1785`)
  + label bahasa berkas; hasil `{ summaries: [counts], data: rows, header_lines, is_partial }`. Trait Excel: baris header
  `config['filter']` digabung `' <br> '` tanpa escape (Excel teks biasa); PDF `reportFilter` = baris di-`e()` digabung `<br>`.
- Tabel template: data_index `['summaries']` / `['data']`, kolom dipetakan seperti `SalesInvoiceReportService::pdfColumns` (`:131-149`);
  `name` diisi ulang menurut data_index; tiap tabel wajib punya `current_columns` dan `available_columns` (`generatePreview`
  `PdfTemplateService.php:1209-1225`). `summaries_columns` = current = available.
- Satu fungsi menentukan kolom kedua tabel (tabel template tersimpan bila `tables` tidak kosong, selain itu display setting) dan dipakai
  `pdf`, `template()` dan `xls`; untuk Excel kolom template diteruskan ke trait sebagai `summaries_columns`/`columns` (`exportColumns`
  menerima `data_index` array, `ExportReportXls.php:314-346`). Pratinjau tidak memengaruhi Excel.
- Route berakhiran `/pdf`, `/pdf/*`, `/xls` = pola heavy request RR `v5-rr` (`config/roadrunner.php:24-32`, di-reset `AppState.php:68`).
- BE dev ED-1258/ED-1259 (2026-10-24), amandemen kontrak ditandai **(BE)** di `contract.md` §1, §2, §4, §5, §8:
  - Pratinjau: payload ditimpakan ke template tersimpan (kunci tak dikirim tetap); validasi 422 (`language` en/id, `paper_size`
    `(A4|A5|Letter|Legal|Folio)-(P|L)`: `Continuous-*` ditolak mPDF = 500 di laporan lain, bentuk array); `id_pdf_template` bukan
    `report-template-1/2/6` = 400 `PDF404` (kode ada, status 400 bukan bawaan 500); urutan 404 → 422 → 400. Kolom tabel dinormalisasi ke
    `{title, data_index, type number|string, class}`, tabel selain `summaries`/`data` diabaikan (cegah 500 renderer dari payload).
  - GET pdf: template tersimpan dengan kertas di luar pola dirender `A4-P`; `id_pdf_template` tersimpan bukan laporan = 400 `PDF404`.
  - BR-11 "398.00" di PDF: renderer `formatData` `number` memotong desimal nol ("398"), jadi kolom `number` diformat
    (`number_precision`, pemisah app config) di data dan dicetak teks rata kanan saat render saja; template & Excel tetap `number`.
  - `getTemplate` baris baru tidak membawa `id_transaction_pdf_template` (`TransactionPdfTemplate` `incrementing = false`): `template()`
    membaca ulang, Simpan Customize pertama tidak `PDF400`.
  - Select: `selected-*` id tidak ada = 422 (`exists`, bukan 500 `find()->toArray()` null); `paper-sizes` `search` di luar
    `[A-Za-z0-9 -]` = 422 (dipakai `preg_match`).
  - Rincian PDF `chunk_size` 200 (render saja). Uji dev 10.000 baris Invalid: PDF 15,4 s (251 halaman), XLSX 2,4 s.
  - Catatan QA: teks PDF memakai ligatur mPDF "ﬁ" (U+FB01) untuk "fi" (Verified → "Veriﬁed", Terverifikasi), cari teks dengan normalisasi;
    "—" = satu karakter UTF-16BE `20 14`.
  - Di luar item (tidak diubah, usulan): sel Excel teks diawali `=` (kode Invalid hasil scan bebas Latin-1) ditulis PHPExcel sebagai rumus
    (`DefaultValueBinder`, trait/`ReportTemplate5` bersama); `Continuous` di `paper-sizes` tidak bisa dirender `PdfReportService` laporan lain.
- BE fix QA r1 D-1 (ED-1259, 2026-10-25): `OpnameExportService::tables()` = satu sumber kolom dengan fallback: tabel tersimpan/payload bila
  minimal satu `current_columns` ber-`data_index` di salah satu tabel, selain itu display setting, selain itu config display setting
  (kontrak §5 "Fallback kolom", §2). Berlaku sama untuk `pdf`, `pdf/preview` (payload tanpa kolom → tabel tersimpan/fallback), `xls` dan
  `transaction-templates`. Excel tidak pernah tanpa kolom (sebelumnya `ReportHeader1` kolom akhir `@` → `mergeCells` PHPExcel tak selesai).
  Satu tabel tanpa kolom tidak berubah. Kode bersama (trait, `ReportHeader1`, `PdfReportService`, `PdfTemplateService`) tidak diubah.
- BE dev revisi review 2026-10-26 (ED-1258/ED-1259; kontrak §1, §2, §4 ditandai **(BE, review 2026-10-26)**):
  - R-3 akar masalah: `report-template-6.blade.php` menaruh `<htmlpagefooter name="page-footer">` (report-footer-1) **sesudah** `$content`, yang
    dipecah `<html-separator/>` menjadi satu `WriteHTML` per potongan (Rincian `DETAIL_CHUNK` 200, laporan lain 50). mPDF mendaftarkan footer bernama
    saat `WriteHTML` yang memuatnya dibaca (`Mpdf.php:13450`) dan memasang footer per halaman saat halaman dimulai (`:10408`, nama belum
    terdaftar = tanpa footer; halaman 1 saat ditutup, `:13218`). Akibatnya hanya halaman yang dimulai di potongan terakhir punya footer: sesi ≤200 baris
    (`…033097`, 97 baris) lengkap, sesi 401 baris (`…474864`) 0/11, sesi 398 baris (`…422239`) 5/11. Bukan soal margin/tinggi header/footer.
    Perbaikan: include footer dipindah ke **sebelum** `$content` (berkas bersama, satu baris; `<htmlpagefooter>` tidak ikut alur isi, jadi tata letak
    laporan lain tidak berubah selain footer kini ada di semua halaman; VAT In yang memakai template ini juga 0/51 → 51/51).
    `report-template-1/2.blade.php` (pola footer di akhir yang sama) tidak diubah: usulan.
  - R-4: `PAPER_PATTERN` `(A4|A5|Letter|Legal|Folio|Continuous)-(P|L)`. Continuous tidak dikenal mPDF sebagai nama, jadi
    `OpnameExportService::paperConfig()` mengirim ukuran mm `PdfTemplateService::getPaperFormat` (dibuat public) dengan orientasi `P` (ukuran apa
    adanya, sama dengan PDF transaksi) lewat parameter opsional baru `PdfReportService::stream($data, $fileName, $mpdfConfig = [])` (124 pemanggil lain
    memakai 2 argumen, perilakunya tidak berubah; laporan lain dengan Continuous tetap 500 seperti sebelumnya: usulan).
  - R-5: perubahan developer di `PdfTemplateService` (`getHeaderPadding`) dan dua view `report-header-1` dipertahankan; hanya `info($paper)` dihapus.
  - Berkas: `Http/Services/DocumentArchive/OpnameExportService.php`, `Http/Services/Report/Export/PdfReportService.php`,
    `Http/Services/Setup/Setting/PdfTemplateService.php` (R-5 + `getPaperFormat` public), `Resources/views/pdf-templates/layouts/templates/report-template-6.blade.php`,
    dua view `report-header-1` (R-5, tidak diubah agent).

## Tambahan Gate 1 (2026-10-24, disetujui developer; epic-check r6 §8)
- AC-15 / AC-19 guard (C-9): sesudah skenario, `transaction_pdf_templates` selain baris sendiri sama persis (count + checksum), `transaction_name`
  baris sendiri tetap, tidak ada baris template baru selain `documentArchiveOpnameResult`. Simpan Customize (`PdfTemplateService::update`) menulis
  baris sesuai id di body tanpa cek permission (kode bersama, tidak diubah item ini); bila terbukti, catat di laporan QA "Di luar item".
- §6: preset `QA251-ROLE-*` dibuang di akhir run (kalau tersisa, e2e ED-1154 P-1 `fixture.php:205` terblokir).
- K-11 c: Customize laporan lain tanpa `selected-pdf-templates` (404) dibiarkan; usulan A-13 dicatat di `run.md`, bukan defect.
- Jawaban Gate 1: `epics/ED-1022-gate1.md` §9 dan §9b (K-1 a, K-2 = template laporan + Customize, K-3 b, K-4 b, K-5 a hanya Excel, K-6 a
  bersyarat, K-7 a, K-8 a, K-9 a, K-10 a + Excel ikut, K-11 c, DU-5 a + `transaction_pdf_templates`, DU-6 a).

## Revisi review 2026-10-26 (developer, `/v5-epic review`; menimpa butir lama yang bertentangan)
- **R-1 label display setting** (FE): baris `documentArchiveOpnameResult` di Setup > Display Setting berlabel en "Archive Opname Result" / id "Hasil Opname Arsip".
- **R-2 gaya tombol Opname History** (FE): tombol Print dan Excel di kolom aksi tabel drawer Opname History sama dengan tombol di drawer Opname Result
  (button `type="primary"`, ikon filled); tetap icon only, tooltip, `aria-label`.
- **R-3 footer PDF** (BE): footer template (`report-footer-1`: dicetak oleh / nomor halaman) harus muncul di **semua** halaman setiap export. Contoh developer
  (setting sekarang, tanpa diubah): sesi `id_archive_opname = 250224000117918817485624033097` footer muncul; sesi `250224000117920274494615474864` footer
  tidak muncul. Cari akar masalah (mis. potongan `WriteHTML` Rincian `DETAIL_CHUNK`, tinggi header/footer, margin `@page`), perbaiki, buktikan dengan kedua sesi
  itu (baca saja, sesi nyata) dan sesi QA dengan jumlah baris berbeda. Developer sudah mencoba margin-bottom `@page` dan padding/margin footer negatif tanpa hasil.
- **R-4 kertas Continuous didukung penuh** (BE, keputusan a): `Continuous-P`/`Continuous-L` di pratinjau (`pdf/preview`) dan di template tersimpan dicetak dengan
  ukuran Continuous yang sudah ada di `PdfTemplateService` (`getPaperFormat`/dimensi), bukan 422 dan bukan jatuh ke A4-P. `PAPER_PATTERN` dan kontrak §2
  (422 `paper_size`) disesuaikan: kertas sah = `(A4|A5|Letter|Legal|Folio|Continuous)-(P|L)`. AC baru: pratinjau dan GET pdf dengan Continuous-P dan -L = 200,
  MediaBox sesuai ukuran Continuous.
- **R-5 perubahan developer ikut** (keputusan a): perubahan developer yang belum di-commit di `Modules/V5/Http/Services/Setup/Setting/PdfTemplateService.php`
  (`getHeaderPadding`), `Modules/V5/Resources/views/pdf-templates/layouts/headers/report-header-1.blade.php`, dan
  `Modules/V5/Resources/views/pdf-templates/styles/headers/report-header-1.blade.php` dipertahankan (jangan dibuang/ditimpa), perbaikan dibangun di atasnya, dan
  ketiganya **ikut commit perbaikan ED-1251**. Hapus baris debug `info($paper)`. Berkas dipakai semua laporan: QA regresi PDF minimal satu laporan lain yang
  memakai `report-header-1` (mis. VAT In) - terbuka 200, header/footer tampil.
- Dikonfirmasi tanpa perubahan: memori sesi besar ikut setting produksi; `pdf/preview` `$request->all()` dibiarkan; pembuatan baris template/cds tanpa penjaga
  race dibiarkan; nama kunci `getSelectArchiveOpnameReportPdf*`.
