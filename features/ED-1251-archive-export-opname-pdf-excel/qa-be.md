---
key: ED-1251
scope: be
round: 2
verdict: PASS
counts: {pass: 15, fail: 0, manual: 0, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

Subtask BE: ED-1258 (display setting + template + route select) dan ED-1259 (export PDF/Excel/pratinjau). Ronde 2 = cek ulang D-1 (Excel berputar tak
berujung bila kedua tabel tanpa kolom) sesudah perbaikan BE (`OpnameExportService::tables()`: tabel tersimpan/payload bila punya kolom terpakai,
selain itu display setting, selain itu config; kontrak §2/§5 "Fallback kolom"), lalu regresi BE penuh.

- **D-1 tertutup.** Semua keadaan yang dulu menggantung Excel kini 200 dengan kolom fallback di PDF, pratinjau, XLSX dan `transaction-templates`.
  Sesuai permintaan, **tidak ada request HTTP yang merender dikirim sebelum proses CLI terbatas waktu menunjukkan ia selesai** (`qa/xls_worker.php`, batas CPU 8 s,
  dimatikan paksa di 25 s; kini juga mode `preview` dengan payload). Urutan HTTP tiap keadaan: pdf, pratinjau, baru xls, dan xls tidak dikirim bila PDF belum
  menunjukkan kolom yang diharapkan (tanda php-fpm melayani kode lama). Nyatanya tidak ada satu pun CLI berputar (tiap panggilan ±0,4 s).
- **Skenario baru** `qa/scenario8-fallback.php` (menggantikan D-1 lama di `scenario7-edge.php`): `D-1` (17 keadaan Simpan Customize), `D-1b` (13 keadaan display
  setting/rantai), `D-1c` (15 payload pratinjau x 2 keadaan template + 3 pada template khusus), `D-1d` (11 bentuk tersimpan buatan tangan). `xls_worker.php` dan
  `q251_xls_cli` (`qa_check.php`) mendukung `preview`.
- **Regresi penuh**: `qa/scenario*.php` 32 skenario, **PASS=32 FAIL=0** dalam 6 mnt 42 s (`work/ed1251/final-run-r2.txt`, JSON `work/qa-http/ED-1251-20261025041643.json`).
  Regresi pemilik: ED-1028 `--only=AC-1,AC-12,AC-13,AC-28` PASS=4 (`work/ed1251/r2-regress-ed1028.txt`), ED-1250 `AC-10,AC-11` PASS=2 (`r2-regress-ed1250.txt`);
  `git status` kedua folder fitur itu bersih (tidak ada yang diubah). Teardown akhir `C-9` PASS.
- Satu-satunya berkas BE yang berubah sejak r1 = `OpnameExportService.php` (mtime 20:51; sisa 11 berkas tidak berubah). php-fpm: `opcache.validate_timestamps=On`,
  `revalidate_freq=2`, jadi kode baru langsung dipakai; `be-reload.sh` tidak dijalankan (fpm, tanpa `BE_RELOAD_CMD`).
- Tidak ada HTTP 500 pada jalur yang bisa dikirim FE. HTTP 500 yang teramati hanya pada data **tak sah buatan tangan** di kode bersama ("Di luar item" #5-#7, tidak
  berubah dari r1, daftar lengkap di bawah); dilaporkan terbuka, orkestrator memutuskan apakah dihitung sebagai defect item. Verdict PASS bertumpu pada klasifikasi itu.
- Data uji: semua dibuang oleh teardown skenario (prefix `0QA251-`, `QA251BULK`, preset `QA251-ROLE-*` = 0 sisa). Baris `column_display_settings` dan
  `transaction_pdf_templates` `documentArchiveOpnameResult`: **0 baris sebelum run, 0 baris sesudah run** (dibuat aplikasi tiap skenario, dibuang persis; DU-5 a).
  Template lain count+checksum sama persis, tidak ada nama template baru (C-9). Tidak ada baris milik orang lain yang dihapus. Tidak ada proses CLI yatim; tidak ada proses yang dimatikan.

## Hasil per AC

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | S1 (`POST opnames` QA_USER, preset FULL): GET pdf/xls (en & id) + S2; `confirmed_at` dipatok | PASS | `AC-1` 70 asersi: 200, `application/pdf` / spreadsheetml, `Content-Disposition` `Opname_Archive_<d-M-Y_His>`, expose header, `%PDF-`, ZIP `Report`; sesi tak ada = JSON 404 |
| AC-2 | Header XLSX (A1-A5) dan PDF vs `companies`, `Folder:`, `Opname Date` teks DB, `Opname By`; footer PDF; AC-2b nama folder berisi `<i>`, `&amp;`, `"` | PASS | AC-2 55 asersi, AC-2b 28 |
| AC-3 | Ringkasan = `archive_opnames` S1/S2 | PASS | 53 asersi. PDF "8.00 4.00 1.00 1.00 4.00"; XLSX numerik `#,##0.00`; 5 kolom urut `summaries_columns` |
| AC-4 | Rincian PDF & XLSX vs gabungan 4 halaman `GET documents?result=all` | PASS | 81 asersi (tipe NULL, Not found, Invalid, belum discan, jumlah = `result_counts.all`) |
| AC-5 | `users.language=ID`: header, judul, hasil, `Semua Arsip`, `Di luar scope opname`, bulan, nama berkas | PASS | 72 asersi; bahasa asli QA_USER dipulihkan |
| AC-6 | Palet warna PDF (`rg/RG/g/G`, `re f`) dan fill/font XLSX | PASS | 169 asersi (tanpa hijau/oranye/merah; XLSX sel data tanpa fill) |
| AC-7 | user2 S1 catatan BR-18, S2/QA_USER/bypass tidak; Ringkasan user2 = QA_USER; Rincian = `documents` user2 + oracle SQL; AC-7b lokasi JOG | PASS | AC-7 62, AC-7b 42 asersi (hanya superadmin role 1 diuji) |
| AC-8 | Template belum disimpan: PUT display setting tanpa Time/Invalid -> PDF & XLSX; Customize ikut; pulih. **Diperluas r2: rantai fallback display setting** | PASS | AC-8 72 asersi; D-1b 365 asersi (13 keadaan, lihat D-1b di bawah) |
| AC-9 | R0 403 `GE0114` "Opname Document" (pdf/preview/xls, display setting GET/PUT), select 200, U2, sesi folder B 404, 16 id aneh, tanpa/rusak token 401 | PASS | 404 asersi, 78 permintaan, 0 respons 5xx |
| AC-10 | S-BULK 10.000 baris Invalid: PDF + XLSX | PASS | 38 asersi. PDF 14,8 s, 251 hal., 0,9 MB; XLSX 2,3 s. Risiko memori: "Di luar item" #8 |
| AC-11 | Unit `scopeText` root/0, root/3, folder/0, folder/3 (en, id) vs `getScopeTag`; sesi akar sisipan; Rincian kosong (BR-20) | PASS | 44 asersi |
| AC-12 | F-1 dibalik (R0 -2, selisih History+Result, `search=documentArchive` 3 baris); PO Report pdf/xls 200; suite pemilik | PASS | AC-12 52 asersi; ED-1028 AC-1/12/13/28 PASS=4; ED-1250 AC-10/11 PASS=2. Baris Result ada -> ED-1028 AC-28 gagal di asersi lama (#9) |
| AC-13 | `transaction-templates` (baris dibuat dari config, tabel dari display setting), header/footer/pdf-templates `is_report=1`, selected-*, paper-sizes; AC-13b jalur gagal 422 | PASS | AC-13 80 asersi, AC-13b 33 |
| AC-14 | `pdf/preview`: `{}`, A4-L, A5-P, Letter-L, Legal-P, Folio-P, header/Rincian/`language`, report-template-1/-2; tabel tak berubah; 20 payload tak sah + 24 payload aneh. **r2: payload `tables` kosong/tanpa kolom/tanpa kunci (D-1c)** | PASS | AC-14 150 asersi; D-1c 204 asersi; 0 respons 5xx pada payload yang bisa dikirim FE |
| AC-15 | `POST setting/pdf-templates/update` (Rincian tanpa Time, Result sebelum Folder, judul "Final Result", Ringkasan tanpa Invalid) -> GET pdf **dan** xls; PUT display setting sesudahnya tak berpengaruh; guard C-9; kertas/`id_pdf_template`; AC-15c Excel tak ikut kertas/header/footer. **Kasus tepi "semua kolom dicabut" kini lulus (D-1)** | PASS | AC-15 95 asersi, AC-15c 41, D-1 464 asersi |

AC `[FE]`/`[BE+FE]` (AC-16..AC-23) bukan milik tahap ini.

### Cek ulang D-1 dan tetangganya (r2)

Semua keadaan: CLI terbatas waktu (xls, pdf, pratinjau `{}`) lebih dulu, lalu HTTP; di tiap keadaan: 200, kolom PDF = XLSX = pratinjau = `transaction-templates` = yang diharapkan,
baris Rincian (10) dan angka Ringkasan cocok, `transaction_data` baris template tidak berubah oleh pembacaan (fallback tidak menulis).

| Skenario | Keadaan | Hasil yang diamati (sesuai kontrak §2/§5) |
|---|---|---|
| D-1 (Simpan Customize) | satu tabel kosong (Ringkasan atau Rincian), satu kolom saja | tabel kosong tidak tertulis, tabel lain apa adanya (tanpa fallback) |
| D-1 | **kedua kosong** (`current_columns: []`, `available_columns` penuh atau kosong), `tables: []`, hanya satu tabel yang ada dan kosong | fallback: Ringkasan 5 + Rincian 5 kolom di PDF, XLSX, pratinjau, select |
| D-1 | kolom tanpa `data_index`, `data_index` `[]`/`null`/`""`/`[1]`/`[[]]`, kolom `null`, tabel `other`, satu tabel tanpa kolom terpakai + lainnya tanpa `data_index` | fallback 5+5; dengan satu tabel berkolom penuh + satu tanpa kolom terpakai: tabel itu tidak tertulis |
| D-1b (display setting; template `tables` kosong) | `summaries_columns` `[]` + `current_columns` tanpa `data_index`; hanya `summaries_columns []`; satu kolom; urutan dibalik; kolom cds NULL / JSON skalar / `[]` / tanpa `data_index`; baris cds dihapus; hanya `current_columns` `[]` (lewat DB) | config bila tak ada kolom terpakai; display setting dipakai bila ada (urutan/judul ikut) |
| D-1b (template tersimpan kosong) | + cds kustom; + cds tak terpakai; + cds NULL | rantai: template -> cds kustom -> config |
| D-1c (pratinjau) | tanpa `tables`; `tables []`; kedua `current_columns []`; tanpa `data_index`; tanpa kunci kolom; `null`; hanya tabel `other` | tabel tersimpan / fallback (5+5, atau tabel tersimpan khusus) |
| D-1c | payload berkolom (Ringkasan `[verified]`, Rincian `[resultLabel, code]`; Rincian dibalik; satu tabel kosong) | payload dipakai apa adanya |
| D-1c | `tables` string / berisi string / `current_columns` string / `available_columns` angka | 422 JSON |
| D-1d (tersimpan buatan tangan) | `tables` string/`['x']`/`[[]]`, tabel tanpa `current_columns`/`available_columns`, `current_columns` `"x"`/`null`, `available_columns` `"x"`, kolom berisi string, `data_index` tabel `null` | **XLSX 200 + fallback di semua (11/11)**; PDF/pratinjau/select 200 bila `current_columns` & `available_columns` array, **500 pada 9 bentuk** (kode bersama, lihat #5) |

## Defect

Tidak ada. D-1 (AC-15, juga AC-8/BR-15, subtask ED-1259, layer BE) **tertutup**: bukti di atas (D-1 464, D-1b 365, D-1c 204, D-1d 151 asersi PASS).
Tidak ada defect baru pada AC-1..AC-15.

## Isolasi tenant & permission

- Tenant: `QA_DBS` = satu DB (`api_sidomaju`) -> **tidak berlaku** (tidak ada sepasang tenant). Server php-fpm, `OpnameExportService` tanpa static / state antar-request
  (perbaikan ronde ini tidak menambah state; hanya fungsi privat).
- Permission (AC-9, 78 permintaan, tidak berubah dari r1, diulang lulus):

| Endpoint | QA_USER (FULL) | QA_USER2 U2 (OD) | QA_USER2 R0 (tanpa OD) | tanpa token |
|---|---|---|---|---|
| GET `opnames/{id}/pdf`, `/xls`, POST `/pdf/preview` | 200 | 200 sesi terlihat; 404 `ARCHIVE412` sesi folder B | 403 `GE0114` parameter "Opname Document" | 401 `GE0111` |
| GET/PUT `setting/display-columns/documentArchiveOpnameResult` | 200 | - | 403 `GE0114`, DB tetap | - |
| GET `select/report/document-archive/opname/*` (8 route) | 200 | 200 | 200 (konvensi select; tanpa data sesi) | 401 |

## Yang tidak bisa diverifikasi (dan kenapa)

- Tenant ke-2 (isolasi) - hanya satu DB di `QA_DBS`.
- Kasus tanpa lisensi Salesman Activity (BR-7) - `PROFILES` kosong; yang diuji hanya penolakan permission.
- `number_decimal`/`number_precision` selain `api_sidomaju`; superadmin role 2 (hanya role 1 dijalankan).
- Memori fpm 128M (asumsi spec) - pool mesin ini 2048M, diukur lewat CLI (lihat #8).
- `PUT display-columns` dengan `current_columns: []` ditolak (bukan 200), jadi "Rincian kosong" di tingkat display setting hanya dicapai lewat DB (D-1b); bukan defect.

## Di luar item (bukan defect item ini; kode bersama atau diterima)

Tidak ada temuan baru r2. Butir yang berubah / dipertegas:

1. **C-9 terbukti lagi**: `POST setting/pdf-templates/update` tanpa cek permission: QA_USER2 preset R0 (tanpa `Opname Document`) menyimpan baris `documentArchiveOpnameResult` = 200. Template lain count+checksum sama, `transaction_name` tak berubah, tanpa baris baru (AC-15, C-9 PASS).
2. **Sel Excel berawalan `=`** (kode Invalid hasil scan bebas) tertulis sebagai rumus aktif; `+ - @` aman; PDF literal (E-4). Tidak berubah.
3. Sel tabel Excel memangkas tag HTML dan mendekode entity (`Traits/XlsReport.php:317-321`); PDF dan header Excel literal.
4. Footer PDF `report-footer-1.blade.php:19` memakai `h:i:s` (12 jam tanpa AM/PM). Template laporan bersama.
5. **(dipertegas r2) Template tersimpan buatan tangan rusak -> PDF, pratinjau, `transaction-templates` 500; Excel kini 200.** Sumber: `PdfTemplateService::getTemplate` (`:1029`) ->
   `generatePreview` (`:1209`, `:1216`, `:1224`) pada `tables` bukan array / elemen string / tanpa `current_columns` atau `available_columns` / keduanya bukan array (9 dari 11 bentuk D-1d;
   log: "Invalid argument supplied for foreach()", "Undefined index: available_columns/current_columns", "array_map(): Expected parameter 2 to be an array"). Tabel dengan kedua kunci berupa array
   (kosong, tanpa `data_index`, kolom string, `data_index` null) aman. Bentuk ini hanya lahir dari Simpan Customize yang tak memvalidasi (route bersama); FE (`formatRequest`
   `drawerPdf.function.js:232-247`) selalu mengirim `currentColumns`/`availableColumns` berupa array. Perbaikan butuh kode bersama; daftar: `work/ed1251/d1d-result.json`, `e3-result.json`.
6. `users.language` selain en/id (`''`, `XX`): GET pdf/xls 500 (`PdfTemplateService.php:1201`, `ReportHeader1.php:29`); NULL jatuh ke en (E-6). Data tak sah.
7. PUT display setting `documentArchiveOpnameResult` sebelum barisnya ada = 500 `FAILED` (keluarga A-1). Tidak ada UI untuk modul ini (E-1).
8. **Memori/skala**: spec mengasumsikan fpm 128M; mesin ini `pool.d/www.conf php_value[memory_limit]=2048M`. CLI 128M: PDF 10.000 baris gagal `Allowed memory size exhausted`, XLSX puncak 123,5 MB. Di 2048M: PDF 10.000 baris puncak 145 MB;
   35.000 baris (sesi akar nyata ±34,5 rb): PDF ±107 s/275 MB, XLSX ±15 s/246 MB. AC-10 (10.000, < 60 s) lulus di mesin ini (r2: PDF 14,8 s, XLSX 2,3 s); bila produksi 128M atau sesi akar penuh diekspor, AC-10 tidak terpenuhi - butuh keputusan developer.
9. ED-1028 `scenario9-display-setting.php:127,130,138` (F-1, diumumkan): bila baris Result ada, tiga asersi lama gagal (-2 vs -1; `[History, Result]`; 3 vs 2 baris). Tidak muncul di r2 karena baris belum ada saat suite itu jalan; File ED-1028 tidak diubah, perlu diperbarui pemilik.
10. Kosmetik: `Content-Type` xlsx membawa `; charset=UTF-8`; `GET display-columns/{modul}` tidak mengembalikan `summaries_columns`; pemakaian pertama paralel tidak menggandakan baris (E-7) tanpa penjaga race.

## Riwayat ronde

- r1 (2026-10-24/25): BE FAIL - D-1 (Excel berputar bila kedua tabel tanpa kolom; worker php-fpm 100% CPU). 27/29 skenario PASS (E-3 dan D-1 = satu defect). Data uji dihapus; 0 sisa.
- r2 (2026-10-25): BE PASS - D-1 tertutup (fallback tabel tersimpan -> display setting -> config; PDF/pratinjau/XLSX/Customize). Skenario baru `scenario8-fallback.php` (D-1, D-1b, D-1c, D-1d) dengan gerbang CLI terbatas waktu sebelum HTTP; regresi penuh 32/32 PASS, ED-1028 4/4, ED-1250 2/2; data uji 0 sisa; baris cds/template sendiri 0 -> 0.
