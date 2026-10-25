---
key: ED-1251
scope: fe
round: 1
verdict: PASS
counts: {pass: 7, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

Tahap FE ED-1251 (subtask ED-1260; QA ED-1261): tombol Print + Excel di kolom aksi kanan tabel Opname History dan di judul Opname Result, `DrawerPdf` + Customize
sendiri (`documentArchiveOpnameResult`). AC milik tahap ini: AC-16..AC-22 (FE) dan AC-23 (BE+FE, manual Gate 2) = 8 AC.

- **Build**: `scripts/fe-build.sh build` lulus ("Compiled successfully", tanpa peringatan; `work/ed1251-fe/build-r1.txt`). Tidak ada berkas `src/` lebih baru dari build staging
  (22:29), jadi yang diuji = kode FE saat ini. Tidak menyentuh `build/` developer.
- **Unit**: modul Archive 17 suite, 529 tes. Run paralel pertama 9 suite gagal (28 tes, timeout beban mesin, 4 core). Kesembilannya **lulus saat dijalankan sendiri `--runInBand`**
  (ArchiveOpnameHistory + `archive.function` 274 tes; 5 suite 43 tes; 3 suite 86 tes). Full suite sekali (85 suite, 2 worker): 72 lulus, 13 gagal = 9 suite Archive/Raptor
  timeout beban (lulus sendiri, lihat `jest-rerun-*.txt`) + **4 pra-ada** (BatchOut 1 tes, production.function `getRecipeTotals` 2 tes, InterbankTransferForm 1 tes,
  CustomerPurchaseOrderView gagal dimuat `electronEvent.appBeforeClose`) = sama persis dengan catatan QA ED-1150 dan ED-1250, tanpa kaitan dengan Archive/DrawerPdf.
- **Browser (e2e)**: 10 flow ED-1251 + 1 smoke `routes.json` = **11 OK, 0 GAGAL** (final, fixture aktif, `--workers 1`). 0 error konsol/pageerror/HTTP >= 400 di luar yang sengaja dicegat,
  tanpa entri allow-list. Isi berkas dibuktikan dari isi (teks/MediaBox PDF, sel XLSX, kanvas viewer), bukan HTTP 200.
- **Regresi BE**: 32 skenario `qa/scenario*.php` **PASS=32 FAIL=0** (`work/ed1251-fe/be-regress-r1.txt`); pemilik: ED-1028 AC-1/12/13/28 PASS=4, ED-1250 AC-10/11 PASS=2.
- **Regresi e2e F-2 (ED-1028 `histRows`)**: tanpa perubahan, e2e ED-1028 AC-15 **gagal** (tiap baris 8 sel, +1 sel kosong kolom aksi); sesudah `histRows` mengabaikan kolom aksi
  (perubahan minimal, lihat bawah) e2e ED-1028 profil default **13/13 OK**.
- Tidak ada defect. Satu temuan "Di luar item" baru (kode bersama, rendah) dan satu saran dokumentasi (tidak memblokir).

## Hasil per AC

Semua flow memakai S1 (folder `0QA251-F-…` + 2 subfolder, angka 8/4/1/1/4) dan S2 (folder `0QA251-G-…`), dibuat lewat `POST opnames` oleh QA_USER (bahasa ID bawaan; EN lewat jurnal).
Galeri final: `work/e2e/ED-1251-archive-export-opname-pdf-excel-20261024-234609/` (`index.html`, `report.json`, `screenshots/default/`).

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-16 | Smoke `/archives` (routes.json) + flow: modal 05 -> Opname History, tab Verification drawer folder, modal verifikasi folder, Opname Result dari riwayat folder, DrawerPdf, Customize; desktop dan 375 px. Monitor otomatis: console error, pageerror, respons >= 400, request gagal | PASS | smoke `/archives` OK; `AC-17/AC-16 Opname History (modal 05)` OK; `AC-16 layar lain (desktop)` dan `(375 px)` OK; `AC-19 Customize` (desktop) dan `AC-17/AC-16/AC-21 375 px` (DrawerPdf menu + Customize) OK; ss `ac16-01..04`, `ac16-01..04-375`, `ac19-01`, `ac19-10-customize-375` |
| AC-17 | Tiap baris tabel terender: sel terakhir `ant-table-cell-fix-right` (sticky), tanpa teks, tepat 2 `button` ber-`aria-label` `["Print Preview","Excel"]`, ikon printer lalu file-excel, 0 atribut `id`; header kolom aksi kosong; hover Print/Excel -> tooltip; id DOM ganda baru; 375 px: kotak sel di dalam viewport, tanpa tooltip. Klik tombol tidak membuka Hasil, klik sel membuka Hasil | PASS | `AC-17/AC-16 Opname History (modal 05)`: tiap baris 2 tombol, geometri `cellRight == wrapRight (1064)`, `position: sticky`, lebar 76 px; id ganda baru hanya `search-bar x2`, `query x2` (komponen Search, pra-ada, bukan tombol export). 375 px: semua sel aksi x+w <= 375. F-2: ED-1028 `histRows` merah tanpa edit, 13/13 hijau sesudah edit (`work/e2e/ED-1028-archive-opname-history-20261024-233608/`). ss `ac17-01..03`, `ac17-10-riwayat-375` |
| AC-18 | Filter pencarian dulu (nama folder S1), klik Print baris S1: hitung GET `…/{S1}/pdf` (status, tipe, `Content-Disposition`), isi PDF diurai PHP (`fixture.php pdf`), DrawerPdf di atas riwayat, Hasil tidak terbuka, 3 tombol + tooltip, Download, viewer, tutup | PASS | tepat 1 GET `pdf` 200 `application/pdf`, `filename="Opname_Archive_dd-Mon-yyyy_HHmmss.pdf"`; PDF memuat "Opname Arsip", `Folder: 0QA251-F-… + 2 subfolder`, `Diopname Oleh: <user>`, Ringkasan/Rincian, dokumen S1 ada dan dokumen S2 tidak ada, MediaBox potret; kanvas viewer terisi (bukan putih) + teks halaman berisi judul laporan; tombol Print/Download/Customize + tooltip `Unduh`/`Sesuaikan`; Download -> `Opname_Archive_*.pdf` dengan byte sama dengan pratinjau; sesudah tutup: kotak cari = nama folder, jumlah/baris pertama sama, 0 GET `opnames` baru, Hasil tidak terbuka. ss `ac18-01`, `ac18-02` |
| AC-19 | Customize dari DrawerPdf: GET select modul ini + POST preview saat dibuka; tabel Ringkasan/Rincian; orientasi Landscape; Simpan; Excel; ronde cabut Waktu + Tidak Valid; pulihkan; guard C-9 lewat DB | PASS | 8 GET `select/report/document-archive/opname/*` semua 200 (termasuk 4 yang diwajibkan: `transaction-templates`, `selected-header/pdf/footer-templates`), 0 request `purchase-order`; preview membawa `transactionName: documentArchiveOpnameResult`; Ringkasan = Total Dokumen..Belum Discan, Rincian = No. Dokumen..Hasil (semua tercentang); baris template dibuat aplikasi (1 baris); Landscape -> POST `pdf/preview` `A4-L`, MediaBox lebar > tinggi, kertas tersimpan tetap A4-P (preview tidak menyimpan); Simpan -> `POST setting/pdf-templates/update` 200 dengan `idTransactionPdfTemplate` = id baris sendiri, DB kertas `A4-L`, **template lain tetap 141 baris + checksum sama (C-9)**, PDF dimuat ulang landscape, toast sukses; cabut Waktu + Tidak Valid -> DB kolom `[code, transactionTypeLabel, folderName, resultLabel]` / `[totalDocuments, verified, notFound, unscanned]`, PDF tanpa "Waktu" dan "Tidak Valid" satu kali lebih sedikit, **Excel**: header Ringkasan 4 kolom, Rincian 4 kolom persis; pulihkan lewat UI (Portrait + kolom kembali) lalu `fixture.php own-restore`: baris template 0, cds 0 (= awal). ss `ac19-01..05` |
| AC-20 | Excel baris S1 lalu S2 (permintaan ditahan 2,5 dtk lewat `page.route`), klik ulang S1 dua kali, status loading semua tombol, nama berkas, isi XLSX diurai PHP | PASS | tepat 2 GET `xls` (S1 sekali walau 3 klik, S2 sekali) 200 `spreadsheetml.sheet`; saat berjalan tombol Excel S1 `ant-btn-loading`, Print S1, Excel S2 dan Print S2 normal; S2 ikut loading sendiri; normal lagi sesudah selesai; unduhan `Opname_Archive_dd-Mon-yyyy_HHmmss.xlsx`; sheet `Report`, isi S1 memuat scope S1 dan dokumen S1 (tanpa S2), isi S2 sebaliknya; Hasil tidak terbuka. ss `ac20-01`, `ac20-02` |
| AC-21 | Klik sel S2 -> Hasil: wadah `.flex-justify-between` (flex/space-between), judul teks di kiri, 2 tombol rata kanan, subjudul di bawah; Print/Excel memakai sesi yang dibuka (S2); tutup DrawerPdf kembali ke Hasil sama; 375 px | PASS | desktop: judul "Hasil Opname" di wadah, 2 tombol, `btnR >= wrapR-2`, subjudul y >= bawah judul, subjudul memuat `<user> · <folder G>`; Print dari judul -> GET `pdf` **S2** (isi PDF S2), tutup -> Hasil S2; Excel dari judul -> GET `xls` S2 + isi XLSX S2. 375 px: tombol tidak melewati viewport, judul dan tombol tidak bertumpuk, subjudul di bawah. ss `ac21-01`, `ac21-02`, `ac21-10-hasil-375` |
| AC-22 | `page.route` `/xls` -> 500 dan 404, `/pdf` -> 404 (respons JSON); bahasa EN lewat `fixture.php lang EN` | PASS | notifikasi error tampil di ketiganya, `#root` utuh (tanpa crash), tombol Excel kembali normal (bukan loading), Hasil tidak terbuka, DrawerPdf terbuka tanpa kanvas pada `/pdf` 404; sesudah blokir dibuka Print dan Excel berhasil lagi (isi PDF/XLSX benar). 500/404 yang sengaja dikeluarkan dari monitor lewat `expectRejected` (terjadi dulu); tanpa 5xx server nyata. EN: aria/tooltip `Print Preview`/`Excel`, judul "Opname Result", PDF "Opname Archive"/"Opname By", Customize "Customize Print Template" + subjudul "Opname Result", XLSX "Opname Archive"; bahasa kembali `ID`. Bahasa ID (bawaan QA_USER) dipakai semua test lain: tooltip/aria sama |
| AC-23 | PDF/Excel rapi, hitam-putih tanpa warna FE, posisi tombol sesuai klien | MANUAL (Gate 2) | Daftar tes UI task 028 butir 1, 5 (posisi tombol), 9-10 (isi PDF), 15-17 (Excel). Warna: AC-6 (BE QA r1/r2, 169 asersi, tanpa warna FE di PDF/XLSX). Lihat saran dokumentasi di bawah |

## Defect

Tidak ada.

## Galeri e2e & regresi BE

- e2e ED-1251 final: `work/e2e/ED-1251-archive-export-opname-pdf-excel-20261024-234609/` (11 OK: 10 flow + smoke). Log: `work/ed1251-fe/e2e-final.txt`. Berkas unduhan/PDF yang dibaca PHP: `work/ed1251-fe/dl/`.
- e2e ED-1028 (F-2), profil default, 13 OK: `work/e2e/ED-1028-archive-opname-history-20261024-233608/`; log `work/ed1251-fe/e2e-ed1028-default.txt`. Bukti merah tanpa edit: `work/ed1251-fe/e2e-ed1028-unmodified.txt`.
  Tes `[user2]` ED-1028 tidak dijalankan (instruksi: profil default saja).
- Regresi BE ED-1251: `work/qa-http/ED-1251-20261025065551.json` (32/32; C-0 awal dan C-9 akhir termasuk: template lain 141 baris + checksum sama, baris cds/template sendiri 0 -> 0, preset `QA251-ROLE-*` 0).
  Pemilik: `work/qa-http/ED-1028-20261025065931.json` (AC-1/12/13/28 = 4/4), `work/qa-http/ED-1250-20261025065938.json` (AC-10/11 = 2/2).
- Server php-fpm (tanpa reload); kode BE tidak berubah sejak QA BE r2.

## Yang tidak bisa diverifikasi (dan kenapa)

- Dialog cetak browser (tombol Print `DrawerPdf`) dan jalur Android/Capacitor: headless, komponen bersama; AC-18 hanya mensyaratkan tombol ada.
- Butir 22 Daftar tes UI (role tanpa `Opname Document`): tidak dijalankan di run ini (profil `user2`/preset R0 di luar rencana data uji 1251); sudah dicakup e2e ED-1028 AC-27 `[user2]` (suite pemilik, tidak diulang ronde ini). Guard tampilan = riwayat tidak di-mount tanpa `Opname Document` (task 020).
- Worker pdf.js (`unpkg.com`) dilayani lokal dari `node_modules` FE (versi 3.0.279 sama) lewat `page.route` supaya render viewer tidak bergantung internet; render tetap dibuktikan lewat kanvas + lapisan teks.
- Tes unit full suite sekali dengan 2 worker memunculkan timeout beban (lihat Ringkasan); kegagalan dianggap defect hanya bila tetap merah saat sendiri (tidak ada).

## Di luar item (bukan defect item ini; kode bersama)

1. **(baru, rendah) `DrawerCustomize`: Simpan kurang dari ±1 dtk sesudah mengubah kolom tabel = `pageerror` `TypeError: undefined is not iterable`** (konsol; Simpan sendiri tetap 200, toast
   sukses, PDF dimuat ulang). Penyebab (dibuktikan): pratinjau yang tertunda (debounce) berjalan sesudah Customize ditutup/unmount; `formatRequest` (`drawerPdf.function.js`
   `[...currentColumns, ..._availableColumns]`) menyebar `undefined`. Repro `work/ed1251-fe/debug8.json`: ubah kolom lalu Simpan langsung -> pageerror; ubah kolom, tunggu 3 dtk
   (pratinjau terkirim), Simpan -> bersih. Kode bersama (semua laporan ber-Customize), bukan perubahan item ini; e2e saya menunggu `POST pdf/preview` selesai sebelum Simpan.
2. **(info) Mencentang kembali kolom di Customize menaruhnya di urutan pilihan (paling akhir), bukan di posisi semula** (perilaku `DrawerPdfTable` bersama, sama di laporan lain). Dampak ke
   Daftar tes UI butir 15 ("Pulihkan: kembalikan Time"): Time kembali sebagai kolom terakhir di PDF/Excel; urutan asli hanya pulih dengan drag. Bukan defect.
3. C-9 (gate1): `POST setting/pdf-templates/update` tanpa cek permission tetap sudah tercatat; di FE terbukti Simpan mengirim id baris template sendiri (`idTransactionPdfTemplate` = baris
   `documentArchiveOpnameResult`) dan template lain tidak berubah (count + checksum). Tidak ada temuan baru.

Saran dokumentasi (non-blokir): `docs/specs/Archive/028-feat-opname-export-pdf-excel.md` § Daftar tes UI sebaiknya (a) menambah satu butir "buka PDF dan Excel hasil unduhan: rapi, tanpa warna
hijau/oranye/merah" agar AC-23 tertutup tegas (butir 9/15/16 hanya menyebut isi/kolom), dan (b) di butir 15 menyebut bahwa mencentang Time kembali menaruhnya di urutan terakhir (butir 2 di atas).

## Data uji, yang dibuat/diubah/dihapus

- Dibuat fixture (berjurnal, prefix `0QA251-`, 2 sesi S1/S2 lewat `POST opnames` + folder/dokumen/izin) lalu **dibuang** persis di teardown `FIX-DOWN` tiap run (21 asersi: data developer 3 sesi/4 baris folder/896
  baris dokumen opname sama persis, 495 baris verifikasi tidak default sama, `user_roles`/bahasa QA_USER dan `user_roles` QA_USER2 sama, template lain 141 baris + checksum sama (C-9), tanpa preset).
- Baris `transaction_pdf_templates` dan `column_display_settings` `documentArchiveOpnameResult` dibuat aplikasi saat Customize dipakai (DU-5 a); dipulihkan dari jurnal ke keadaan awal (**0 baris, 0 baris**).
- Bahasa QA_USER diubah sementara EN lalu dipulihkan (`ID`). Tidak ada preset role dibuat (QA_USER memakai role aslinya), tidak ada login user nyata, QA_USER2 tidak dipakai.
- Tidak ada baris milik orang lain yang dihapus; tidak ada tabel dikosongkan; tidak ada proses yang dimatikan. Pemeriksaan akhir (read-only, setelah semua run): `roles QA%-ROLE-%` 0, `archives %QA251%` 0,
  `%QA28%` 0, `archive_opnames` 3 (developer), template sendiri 0, cds sendiri 0, template total 141, token aktif QA_USER/QA_USER2 0.
- Berkas QA ditambah/diubah: `qa/e2e/{fixtures.json, routes.json, fixture.php, lib.js, ED-1251-export-opname.spec.js, ED-1251-customize.spec.js, fixrun/qa/scenario-fix.php}`; **diubah di luar folder ini**:
  `features/ED-1028-archive-opname-history/qa/e2e/ED-1028-opname-history.spec.js` `histRows` (`td` -> `td:not(.p-0)`, 3 baris; F-2 gate1 milik ED-1251 AC-17). Folder ED-1250 tidak disentuh.

## F-1 (addendum, 2026-10-25): ED-1028 AC-28 dibalik ke nilai baru

Gate1 §3 F-1 (pemilik 1251 AC-12; aturan (2) gate1-r2 "karakterisasi di folder lain dibalik QA item"): `features/ED-1028-archive-opname-history/qa/scenario9-display-setting.php` diperbarui
supaya benar di DUA keadaan (konstanta `Q28_DSRES`; `$resRow` = baris cds `documentArchiveOpnameResult` ada, dibaca dari DB; `$hiddenMods` = `[History]` atau `[History, Result]`, dibandingkan urut):
(1) R0 total = pemegang - `count($hiddenMods)` (baris lama `:127`: -1 -> -1 / -2), (2) selisih pemegang vs R0 = `$hiddenMods` (`:130`), (3) `search=documentArchive` pemegang = `documentArchive` + `$hiddenMods`
(`:138`: 2 -> 2 / 3 baris), plus komentar yang menyebut ED-1251 AC-12 / gate1 F-1 dan satu `note` keadaan. Tidak ada perubahan lain di folder ED-1028 (edit `histRows` e2e F-2 dari ronde ini tetap).

| Keadaan | Cara | Hasil | Bukti |
|---|---|---|---|
| A: baris `documentArchiveOpnameResult` TIDAK ada (cds 0, template 0) | `run.php ED-1028 --only=AC-1,AC-12,AC-13,AC-28` | AC-1 159, AC-13 141, AC-12 421, AC-28 219 asersi = PASS=4; note "baris ... tidak ada -> R0 total -1, selisih 1 modul, search 2 baris" | `work/qa-http/ED-1028-20261025070518.json`; `work/ed1251-f1/stateA.txt` |
| B: baris ADA (dibuat aplikasi: satu `GET select/report/document-archive/opname/transaction-templates` oleh QA_USER, 200; cds 1 + template 1) | perintah sama | PASS=4 (jumlah asersi sama); note "baris ... ADA -> R0 total -2, selisih 2 modul, search 3 baris" | `work/qa-http/ED-1028-20261025070656.json`; `work/ed1251-f1/stateB.txt`, `up.txt` |
| Pemulihan | `qa/.journal-config-q251.json` (keadaan awal dicatat sebelum GET) lalu `q251_tpl_recover` | cds 0, template 0 (= awal); template lain 141 baris + checksum 279886701241 sama (C-9); daftar `transaction_name` sama; preset `QA%-ROLE-%` 0, `QA251-ROLE-*` 0; jurnal dibuang | `work/qa-http/f1run-20261025070701.json` (8 asersi); `work/ed1251-f1/down.txt` |

Yang dihapus: tepat dua baris buatan aplikasi di keadaan B (1 `column_display_settings` + 1 `transaction_pdf_templates`, keduanya `documentArchiveOpnameResult`, id template 518) = keadaan awal 0/0 (DU-5 a). Tidak ada data lain
dihapus/diubah. Pemeriksaan akhir read-only: cds Result 0, template Result 0, template total 141, `archives` `%QA251%` 0, `archive_opnames` 3 (developer), `updater_logs` 99 (= awal), token aktif QA_USER/QA_USER2 0.
Skenario sementara `UP/DOWN` hanya di scratchpad (bukan regresi permanen); folder ED-1250 dan kode aplikasi tidak disentuh. Verdict tetap PASS.

## Riwayat ronde

- r1 (2026-10-24/25): FE PASS, 7 PASS / 1 MANUAL (AC-23) / 0 FAIL. e2e 11/11, regresi BE 32/32, ED-1028 e2e 13/13 (F-2), unit hijau (4 pra-ada merah). Satu "Di luar item" baru, satu saran dokumentasi.
- r1 addendum (2026-10-25): F-1 ditutup; ED-1028 scenario9 `:127,130,138` benar di dua keadaan (baris Result tidak ada / ada), AC-1/12/13/28 PASS=4 di keduanya, baris Result dipulihkan ke 0/0, verdict tetap PASS.
