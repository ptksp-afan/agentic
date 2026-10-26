# Contract ED-1251 - export PDF & Excel hasil opname (revisi Gate 1 2026-10-24)

Prefix `api/v5`, `auth:api`. Sukses `pdf`/`pdf/preview`/`xls` = **berkas**; gagal = JSON. Field JSON `snake_case` (FE: interceptor
camelCase hanya untuk `application/json`). Mengikuti jawaban Gate 1 2026-10-24 (K-1 a, K-3 b, K-4 b, K-6 a, K-7 a, K-9 a, K-10 a
diubah, K-11 c). BE boleh mengubah berkas ini (amandemen dicatat di spec). Nama modul display setting = `transaction_name` template = `documentArchiveOpnameResult`.

## 1. GET `document-archive/opnames/{id}/pdf`

| Hal | Isi |
|---|---|
| Permission | `Opname Document` (persis) |
| Path / query | `id` = `id_archive_opname` sesi terkonfirmasi yang terlihat pemanggil; tanpa query |
| 200 | `Content-Type: application/pdf`; `Content-Disposition: attachment; filename="Opname_Archive_21-Oct-2026_131800.pdf"` (`confirmed_at` `d-M-Y_His`); `Access-Control-Expose-Headers: Content-Disposition`. Template tersimpan `documentArchiveOpnameResult` (§7), tabel §5. **(BE)** `id_pdf_template` tersimpan bukan `report-template-1/2/6` → 400 `PDF404`; `paper_size` tersimpan di luar pola §2 (Simpan tidak memvalidasi) → dirender `A4-P`. **(BE, review 2026-10-26)** `Continuous-P`/`Continuous-L` tersimpan dicetak dengan ukuran Continuous `PdfTemplateService::getPaperFormat` (bukan `A4-P`): `Continuous-P` 215,9 × 134,62 mm (MediaBox 612 × 381,6 pt), `Continuous-L` 276,86 × 210,82 mm (784,8 × 597,6 pt), ukuran dipakai apa adanya seperti PDF transaksi. Footer `report-footer-1` (printed at/by, nomor halaman) ada di **setiap** halaman (R-3) |

## 2. POST `document-archive/opnames/{id}/pdf/preview`

| Hal | Isi |
|---|---|
| Permission | `Opname Document` |
| Body | payload Customize FE (`formatRequest`, `drawerPdf.function.js:194-252`; dikirim camelCase, BE `req_snake`): `transaction_name`, `language`, `id_transaction_pdf_template`, `id_header_pdf_template`, `id_footer_pdf_template`, `id_pdf_template`, `paper_size` (mis. `A4-L`), `header {current_fields, current_labels, repeat}`, `footer`, `template`, `available_labels`, `current_labels`, `tables[] {data_index, available_columns, current_columns, total_field, fields}`, `signatures`, `signature`. Dipakai sebagai template apa adanya (judul tabel diisi ulang server) |
| 200 | PDF seperti §1 (tanpa menyimpan apa pun). `id_pdf_template` bukan laporan → error `PDF404` yang ada |
| **(BE)** Penggabungan | payload ditimpakan ke template tersimpan (§4 `transaction-templates`): kunci yang tidak dikirim / `null` tetap; `header`/`footer` digabung satu tingkat; `tables` payload dipakai bila ada tabel `data_index` `["summaries"]`/`["data"]` **(BE, D-1) dengan minimal satu kolom `current_columns` ber-`data_index`**, selain itu tabel tersimpan (§5, termasuk fallback-nya); tabel lain diabaikan, urutan selalu Ringkasan lalu Rincian. Kolom dinormalisasi ke `{title{en,id}, data_index[], type: number\|string, class}` (kunci lain, mis. `render`/`children`/tipe tanggal, dibuang) |
| **(BE)** Validasi | urutan: 403 → 404 `ARCHIVE412` → 422 → 400. 422 (validasi Laravel): `language` bukan `en`/`id` (tanpa beda huruf), `paper_size` bukan `(A4\|A5\|Letter\|Legal\|Folio\|Continuous)-(P\|L)` (**(BE, review 2026-10-26)** `Continuous-P`/`-L` sah, dicetak seperti §1; huruf persis, mis. `continuous-p`, `Continuous`, `B5-P` = 422), `header`/`footer`/`header.current_fields`/`footer.current_fields`/`footer.labels`/`tables`/`tables.*`/`tables.*.current_columns`/`tables.*.available_columns` bukan array, `id_*_template` bukan teks ≤30. 400 `PDF404`: `id_pdf_template` bukan `report-template-1/2/6` |

## 3. GET `document-archive/opnames/{id}/xls`

| Hal | Isi |
|---|---|
| Permission / path | sama dengan §1 |
| 200 | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`; `filename="Opname_Archive_21-Oct-2026_131800.xlsx"`; expose header. Sheet `Report`, trait `ExportReportXls::streamXls` (report-header-1 XLS + report-template-5). Kolom = **sumber yang sama dengan PDF** (§5: display setting selama template belum disimpan, tabel template tersimpan sesudahnya); kertas/header/footer/template Customize tidak berlaku untuk Excel |

## 4. Select template laporan `GET select/report/document-archive/opname/<x>`

Tanpa `permission_v5` (konvensi select). Envelope `formatResponse(true, 'SUCCESS', …)`. Prefix `select/report/` wajib (template laporan `is_report = 1`).

| `<x>` | Query | `result` |
|---|---|---|
| `transaction-templates` | - | template `documentArchiveOpnameResult` (`PdfTemplateService::getTemplate`, baris dibuat dari config bila belum ada): `id_transaction_pdf_template`, `transaction_name`, `id_pdf_template`, `id_header_pdf_template`, `id_footer_pdf_template`, `paper_size`, `header`, `footer` (+`labels`), `available_labels`, `current_labels`, `tables` (§5), `signatures`, `template`, `language` |
| `pdf-templates` / `header-templates` / `footer-templates` | `search` | `{default: null, options: [{value, label}]}` hanya `is_report = 1` (`report-template-1/2/6`, `report-header-1`, `report-footer-1`) |
| `selected-pdf-templates` | `id_pdf_template` (wajib, **(BE)** harus ada di `pdf_templates`, selain itu 422) | satu `pdf_templates` + `template_data` digabung |
| `selected-header-templates` | `id_header_pdf_template` (wajib, **(BE)** harus ada, selain itu 422) | satu header + `header_data` (`available_fields`, `available_labels`) |
| `selected-footer-templates` | `id_footer_pdf_template` (wajib, **(BE)** harus ada, selain itu 422) | satu footer + `footer_data` |
| `paper-sizes` | `search` (**(BE)** hanya huruf/angka/spasi/`-`, ≤30, selain itu 422: dipakai sebagai pola regex) | daftar ukuran kertas `PdfTemplateService::paperSize` (termasuk `Continuous`; **(BE, review 2026-10-26)** pratinjau/template tersimpan `Continuous-P`/`-L` dicetak dengan ukuran Continuous, §1-§2) |

Simpan Customize = route yang ada `POST setting/pdf-templates/update` (body = §2 tanpa `language`/`footer.labels`).

## 5. Isi berkas

Contoh en (PDF: bahasa = `language` template, default bahasa user; Excel: bahasa user):

```
Sido Maju Lestari                     <- companyName
Opname Archive                        <- reportName {en: "Opname Archive", id: "Opname Arsip"}
Folder: Backup + 3 subfolder          <- reportFilter baris 1 (id "Folder: …")
Opname Date: 21 Oct 2026 13:18        <- baris 2 (id "Tanggal Opname: 21 Okt 2026 13:18")
Opname By: admintest                  <- baris 3 (id "Diopname Oleh: …")
Some documents outside your access are not shown   <- baris 4 hanya bila BR-18 (id "Sebagian dokumen di luar akses Anda tidak ditampilkan")

Summaries (id Ringkasan)   | Total Documents | Verified | Not Found | Invalid | Not Scanned |
                           |          398.00 |    12.00 |      0.00 |    0.00 |      386.00 |
Detail (id Rincian)        | Document No. | Transaction Type | Folder | Time | Result |
```

Scope: root `All Archive · root` / `Semua Arsip · root`; root + N `… · root + N subfolder`; folder = nama; folder + N `<nama> + N subfolder`.
Opname Date = `confirmed_at` teks DB (WIB) `d M Y H:i`, bulan id Jan Feb Mar Apr Mei Jun Jul Agu Sep Okt Nov Des.
Rincian: semua baris yang boleh dilihat pemanggil, urutan = `GET opnames/{id}/documents`.

| Kolom Rincian | Nilai |
|---|---|
| Document No. | `code` |
| Transaction Type | label jenis; kosong `—` |
| Folder | nama snapshot; root `All Archive`/`Semua Arsip`; Not found `Outside opname scope`/`Di luar scope opname`; Invalid `—` |
| Time | `HH:mm` `scanned_at`; belum discan `—` |
| Result | `Verified`/`Not found`/`Invalid`/`Not scanned`; id `Terverifikasi`/`Tidak ditemukan`/`Tidak valid`/`Belum discan` |

Tabel PDF **dan** Excel (dari display setting selama `tables` template kosong; sesudah Simpan Customize = tabel tersimpan, termasuk urutan & judul kolom, K-10 a diubah):

| # | `data_index` | `name` | `current_columns` / `available_columns` |
|---|---|---|---|
| 0 | `["summaries"]` | Summaries / Ringkasan | dari `summaries_columns` (keduanya sama) |
| 1 | `["data"]` | Detail / Rincian | dari `current_columns` / `available_columns` |

**(BE, D-1) Fallback kolom** (sama untuk PDF, Excel, pratinjau dan `transaction-templates`): bila tabel template tersimpan (atau
display setting) tidak menyisakan satu pun kolom `current_columns` ber-`data_index` di kedua tabel (mis. Customize mencabut semua kolom
lalu Simpan; `current_columns` kosong/bukan array/tanpa kunci; kolom tanpa `data_index`), dipakai display setting, dan bila display setting
juga tanpa kolom, config display setting (§6). Hasil selalu punya minimal satu kolom (Excel tidak pernah tanpa kolom). Satu tabel tanpa kolom
tetap apa adanya, tanpa fallback (Excel tidak menulis tabel itu).

Kolom dipetakan `{title, data_index: [..], type, class}` (`number` → `align-right`, lainnya `align-left`). **(BE)** Tabel hanya berisi
`data_index`, `name`, `current_columns`, `available_columns`. Angka `number` di PDF ditulis dengan `number_precision` (mis. "398.00"): saat render
saja kolom itu diformat di data dan dicetak sebagai teks rata kanan (renderer template laporan memotong desimal nol); template tersimpan dan
Excel tetap `type: number` (Excel sel numerik format `#,##0.00`). `transaction-templates` panggilan pertama (baris baru) tetap membawa
`id_transaction_pdf_template`.

## 6. Display setting `documentArchiveOpnameResult` (BE saja)

`GET/PUT setting/display-columns/documentArchiveOpnameResult` (tanpa `Opname Document` → 403 `GE0114`; tidak ada di
`GET setting/display-columns`). Queries `[]`, semua `sort: false`. `current_columns` = `available_columns`:

| # | `data_index` | `type` | en | id |
|---|---|---|---|---|
| 0 | `code` | `string` | Document No. | No. Dokumen |
| 1 | `transactionTypeLabel` | `string` | Transaction Type | Tipe Transaksi |
| 2 | `folderName` | `string` | Folder | Folder |
| 3 | `scanTime` | `string` | Time | Waktu |
| 4 | `resultLabel` | `string` | Result | Hasil |

`summaries_columns`:

| # | `data_index` | `type` | en | id |
|---|---|---|---|---|
| 0 | `totalDocuments` | `number` | Total Documents | Total Dokumen |
| 1 | `verified` | `number` | Verified | Terverifikasi |
| 2 | `notFound` | `number` | Not Found | Tidak Ditemukan |
| 3 | `invalid` | `number` | Invalid | Tidak Valid |
| 4 | `unscanned` | `number` | Not Scanned | Belum Discan |

## 7. Config template `Config/pdfTemplate/transaction/documentArchiveOpnameResult.php`

`id_pdf_template` `report-template-6`, `id_header_pdf_template` `report-header-1`, `id_footer_pdf_template` `report-footer-1`,
`paper_size` `A4-P`, `transaction_name` `documentArchiveOpnameResult`, `transaction_data`: `header {current_fields: [companyName,
reportName, reportFilter], repeat: true}`, `footer {current_fields: [timestamp, page_number, printed_by], repeat: true}`,
`available_labels []`, `current_labels []`, `tables []`, `signatures []` (bentuk `reportVATIn.php:1-21`).

## 8. Error

| HTTP | Body | Kapan |
|---|---|---|
| 401 | `formatResponse(false, 'GE0111')` + `status_text: unauthenticated` | tanpa/expired token |
| 403 | `{ "message", "code": "GE0114", "parameter": "Opname Document" }` | §1-§3 tanpa `Opname Document` (dicek pertama); display setting §6 |
| 404 | `{ "message", "code": "ARCHIVE412" }` | sesi tidak ada, id bukan ASCII 1-30, bukan terkonfirmasi, atau tidak terlihat |
| 400 | `{ "message", "code": "PDF404" }` | **(BE)** §1 template tersimpan / §2 payload dengan `id_pdf_template` bukan `report-template-1/2/6` |
| 422 | validasi Laravel | `selected-*` tanpa id wajib atau id tidak ada; `paper-sizes` `search` di luar pola; §2 payload tidak valid (§2 Validasi) |
| 500 | - | defect (D-6) |

FE meminta §1-§3 sebagai blob: error JSON tampil sebagai notifikasi generik (sama dengan PO Report).

## 9. Wiring FE (pola PO Report)

```js
pdfEndpoints = {
  getPdf: 'document-archive/opnames/:id/pdf',
  postPdfPreview: 'document-archive/opnames/:id/pdf/preview',
  getPdfHeaders: 'select/report/document-archive/opname/header-templates',
  getPdfHeader: 'select/report/document-archive/opname/selected-header-templates',
  getPdfFooters: 'select/report/document-archive/opname/footer-templates',
  getPdfFooter: 'select/report/document-archive/opname/selected-footer-templates',
  getPdfTemplates: 'select/report/document-archive/opname/pdf-templates',
  getPdfTemplateDetail: 'select/report/document-archive/opname/selected-pdf-templates',
  getPdfTemplate: 'select/report/document-archive/opname/transaction-templates',
  getPdfPapers: 'select/report/document-archive/opname/paper-sizes',
}
<DrawerPdf id={idArchiveOpname} endpoints={pdfEndpoints} transaction="documentArchiveOpnameResult" width="100%" />
getArchiveOpnameXls: 'document-archive/opnames/:id/xls'  // { raw: true, responseType: 'blob' } -> downloadFile
```

## 10. Tidak berubah

`GET document-archive/opnames`, `opnames/{id}`, `opnames/{id}/documents` (ED-1028 contract), `report/purchase/purchase-order/*`,
`setting/pdf-templates/update`.
