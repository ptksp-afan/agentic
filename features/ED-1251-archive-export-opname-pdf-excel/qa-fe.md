---
key: ED-1251
scope: fe
round: 2
verdict: PASS
counts: {pass: 9, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

Ronde 2 tahap FE = revisi review 2026-10-26 (spec § "Revisi review 2026-10-26"; subtask ED-1260, QA ED-1261). Yang dinilai = working tree FE apa adanya (8 berkas belum di-commit: `ArchiveOpnameExport/index.js`,
`ArchiveOpnameHistory/Content.js` + `index.test.js`, `archive.locale.js`, `displaySetting.constant.js`, 3 dok FE) di atas working tree BE (6 berkas, termasuk 3 milik developer R-5). AC milik tahap ini: AC-16..AC-22,
AC-23 (manual Gate 2) + AC-R1 (R-1) + AC-R2 (R-2) = 10 AC. QA FE r2 yang dihentikan developer pukul 10:04 tidak dijadikan bukti (log `*-r2.txt` lama tidak dikutip); skrip e2e yang ditinggalkannya saya tinjau dan
perbaiki (lihat "Perubahan skrip QA").

- **AC-R1 PASS (tidak terjangkau di aplikasi yang berjalan; dibuktikan lewat kode + unit test + bundle).** Label `documentArchiveOpnameResult` hanya dirender subjudul `DisplaySettingDrawer`
  (`displaySetting?.[id]?.label || 'common.No data'`, `DisplaySettingDrawer/index.js:38`), dan satu-satunya pembuka baris itu adalah halaman Setup > Display Setting (`DisplaySettingPage`) yang **tidak di-route**
  (tanpa `displaySetting` di `src/routes/routes.js` dan `paths.js`; `paths.displaySetting` yang dipakai `useSetupSpace` tidak ada). Archive hanya punya Display Setting `documentArchive` dan `documentArchiveOpnameHistory`.
  Bukti: entri konstanta + 2 locale ada di bundle staging yang saya bangun (grep di `static/js/main.*.js`), dan 3 unit test (`index.test.js`: entri persis `{name, label}`; `DisplaySettingDrawer` benar-benar dirender dengan
  `moduleName` itu: subjudul "Archive Opname Result" (EN) dan "Hasil Opname Arsip" (ID), bukan "No data").
- **AC-R2 PASS di browser.** Tombol Print/Excel baris Opname History sama persis dengan tombol judul Opname Result di semua sifat terukur (kelas `ant-btn ant-btn-icon-only ant-btn-primary btn-icon icon`, 40 x 40 px, latar `rgb(0,158,247)`,
  ikon putih 20 px, radius 7,6 px, bayangan, padding), ikon = path `PrinterFilled`/`FileExcelFilled` (bukan Outlined) dibandingkan dengan paket ikon, tanpa teks/`id`, `aria-label` "Print Preview"/"Excel", tooltip desktop;
  keadaan loading Excel juga sama (latar `rgb(41,184,255)`, ikon 16 px + spinner); 375 px kolom 96 px menempel kanan.
- **AC-16..AC-22 PASS lagi** (e2e final 14/14, PDF/Excel dari kedua drawer dibaca isinya), dan R-3/R-4 terlihat di browser: footer "Halaman i dari N" + "Dicetak oleh <user>" di SETIAP halaman, kertas Continuous 200 tanpa
  notifikasi error dengan ukuran Continuous (sesi 247 baris, 7-19 halaman).
- **Build**: `scripts/fe-build.sh build` "Compiled successfully", tanpa peringatan (`work/ed1251-fe/build-r2b.txt`, `work/fe-build.log`); tidak ada berkas `src/` lebih baru dari build (peringatan harness "build mungkin usang" = FE kotor, bukan
  build lama). Tidak menyentuh `build/` developer, tanpa `publish`.
- **Unit**: Archive + DisplaySetting = **17 suite, 533 tes lulus** (r1: 529; +4 = 1 tes R-2 + 3 tes R-1; `work/ed1251-fe/jest-archive-r2b.txt`). Full suite sekali (2 worker, 448 dtk): 80 suite lulus, 5 gagal = **4 pra-ada** (BatchOut 1 tes,
  `production.function` `getRecipeTotals` 2 tes, InterbankTransferForm 1 tes "tanggal default hari ini", CustomerPurchaseOrderView gagal dimuat `appBeforeClose`; sama persis dengan r1, ED-1150, ED-1250) + 1 timeout beban
  (RaptorPendingItemsPanel 1 tes; **lulus sendiri 14/14**, `jest-rerun-raptor-r2b.txt`). Tidak ada yang menyentuh Archive/DisplaySetting/DrawerPdf (`jest-full-r2b.txt`).
- **Regresi BE**: `qa/scenario*.php` ED-1251 **PASS=36 FAIL=0** (`work/qa-http/ED-1251-20261026200658.json`, 13 mnt); pemilik ED-1028 AC-1/12/13/28 **PASS=4**, ED-1250 AC-10/11 **PASS=2**; folder ED-1028 dan ED-1250 tidak diubah
  (`git status` bersih sebelum dan sesudah). Regresi e2e ED-1028 (profil default, `histRows` F-2, tabel riwayat sekarang berkolom aksi 96 px): **13/13 OK**.
- Tidak ada defect. Tidak ada HTTP 500 server nyata (500/404 hanya yang sengaja dicegat `page.route` di AC-22). Satu pengamatan kecil di luar item (visual footer, sudah dikenal BE).

## Hasil per AC

Data: S1 (folder `0QA251-F-…` + 2 subfolder, angka 8/4/1/1/4), S2 (folder `0QA251-G-…`), dan sesi sisipan 247 baris Invalid `QA251BULKFE…` (supaya PDF berhalaman banyak) dibuat fixture; semua dibuang di teardown.
Galeri final: `work/e2e/ED-1251-archive-export-opname-pdf-excel-20261026-132126/` (`index.html`, `report.json`, 51 screenshot). Log: `work/ed1251-fe/e2e-r2-final.txt`.

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-16 | Smoke `/archives` + flow: modal 05 -> Opname History, tab Verification drawer folder, modal verifikasi folder, Opname Result, DrawerPdf, Customize; desktop dan 375 px; monitor otomatis console/pageerror/HTTP >= 400/request gagal | PASS | smoke OK; `AC-17/AC-16 Opname History`, `AC-16 layar lain (desktop)`, `(375 px)`, `AC-19`, `AC-17/AC-16/AC-21 375 px`, `R-3/R-4`, `R-2 desktop/375`: tiap test `errors: 0, allowed: 0, api>=400: 0`; tanpa allow-list. ss `ac16-01..04`, `ac16-01..04-375` |
| AC-17 | Tiap baris: sel terakhir sticky kanan, tanpa teks, tepat 2 `button` `aria-label` `["Print Preview","Excel"]`, ikon printer/file-excel, 0 `id`; header kolom aksi kosong; tooltip hover; id DOM ganda; 375 px; klik tombol tidak membuka Hasil, klik sel membuka | PASS | 3 baris terender (S1, S2, sisipan), geometri `scrollW 1112 / clientW 688`, `cellRight == wrapRight (1064)`, `position: sticky`, lebar **96 px** (R-2); id ganda baru hanya `search-bar x2`, `query x2` (komponen Search pra-ada). 375 px: sel aksi di dalam viewport. ED-1028 e2e 13/13 (F-2). ss `ac17-01..03`, `ac17-10-riwayat-375` |
| AC-18 | Print baris S1 sesudah filter nama folder: hitung GET `…/{S1}/pdf`, isi PDF diurai PHP, DrawerPdf di atas riwayat, Hasil tertutup, Download, tutup | PASS | 1 GET `pdf` 200 `application/pdf`, `Opname_Archive_dd-Mon-yyyy_HHmmss.pdf`; PDF dari FE 32.052 byte, 1 halaman, MediaBox 595,28 x 841,89, memuat judul/Folder/Diopname Oleh/Ringkasan/Rincian, dokumen S1 ada dan S2 tidak; kanvas viewer `{w:595,h:841,nonBlank:true}`; tombol Print/Download/Customize; Download = byte sama dengan pratinjau; sesudah tutup: kotak cari/jumlah baris sama, 0 GET `opnames` baru. ss `ac18-01`, `ac18-02` |
| AC-19 | Customize dari DrawerPdf: GET select modul ini + POST preview; tabel Ringkasan/Rincian; Landscape; Simpan; Excel; cabut Waktu + Tidak Valid; pulihkan lewat UI; guard C-9 lewat DB | PASS | 8 GET `select/report/document-archive/opname/*` semua 200 (4 wajib: `transaction-templates`, `selected-header/pdf/footer-templates`), 0 request `purchase-order`; Ringkasan 5 kolom, Rincian 5 kolom tercentang; Landscape -> POST `pdf/preview` `A4-L`; Simpan -> `POST setting/pdf-templates/update` 200 + PDF dimuat ulang; DB kolom tersimpan `[totalDocuments, verified, notFound, unscanned]` / `[code, transactionTypeLabel, folderName, resultLabel]`; **Excel** Ringkasan 4 kolom, Rincian 4 kolom persis; pulihkan; template lain tetap 142 baris + checksum sama (C-9). Awal ronde ini: baris template 519 dan cds 2585 milik developer sudah ada (`tables` kosong, jatuh ke display setting), test menerima keadaan itu. ss `ac19-01..05` |
| AC-20 | Excel baris S1 lalu S2 (ditahan `page.route`), klik ulang S1, status loading semua tombol, nama berkas, isi XLSX diurai PHP | PASS | tepat 2 GET `xls` (S1 1x walau 3 klik, S2 1x) 200 `spreadsheetml.sheet`; saat berjalan hanya Excel S1 `ant-btn-loading`; unduhan `Opname_Archive_*.xlsx`; XLSX S1 7.583 byte (20 baris berisi, scope + dokumen S1 tanpa S2), S2 7.229 byte (12 baris); Hasil tidak terbuka. ss `ac20-01`, `ac20-02` |
| AC-21 | Klik sel -> Hasil: `.flex-justify-between`, judul kiri, 2 tombol kanan, subjudul di bawah; Print/Excel memakai sesi yang dibuka; tutup DrawerPdf kembali ke Hasil sama; 375 px | PASS | desktop `display:flex`, `justify-content:space-between`, tombol `btnR 1064 == wrapR 1064`, `subY 89 >= titleB 78`; Print dari judul -> GET `pdf` sesi S2 (isi PDF S2), Excel -> GET `xls` S2 + isi XLSX S2; 375 px: wadah 303 px, tombol `r=351 <= vw 375`, judul dan tombol tidak bertumpuk, subjudul di bawah. ss `ac21-01`, `ac21-02`, `ac21-10-hasil-375`, `r2-12-hasil-judul-375` |
| AC-22 | `page.route` `/xls` -> 500 dan 404, `/pdf` -> 404; bahasa EN lewat fixture | PASS | notifikasi error di ketiganya ("Request failed with status code 500"), `#root` utuh, tombol Excel normal lagi, Hasil tidak terbuka, DrawerPdf tanpa kanvas pada `/pdf` 404; sesudah blokir dibuka berhasil lagi. EN: aria/tooltip `Print Preview`/`Excel`, judul "Opname Result", PDF "Opname Archive"/"Opname By", Customize "Customize Print Template" + subjudul "Opname Result", XLSX "Opname Archive"; bahasa kembali `ID` (note: "bahasa QA_USER dipulihkan: ID"). Entri error yang sengaja dicegat dikeluarkan lewat `expectRejected` (500/404 yang dicegat, bukan server) |
| AC-23 | PDF/Excel rapi, hitam-putih tanpa warna FE, posisi tombol sesuai klien | MANUAL (Gate 2) | `docs/specs/Archive/028-feat-opname-export-pdf-excel.md` § Daftar tes UI butir 24 (+ butir 1, 5, 9-10, 16, 25-26). Warna: AC-6 BE (169 asersi, 36/36 lulus ronde ini). Saya melihat pratinjau PDF di viewer (judul kolom abu-abu muda, teks hitam, tanpa hijau/oranye/merah) di `r3-0*` / `ac18-02` |
| **AC-R1** | Bundle staging (`grep` konstanta + 2 locale), kode (route/pembuka drawer), unit test `DisplaySettingDrawer` EN dan ID; run scoped Archive + DisplaySetting | PASS (tidak terjangkau di UI, alasan di Ringkasan) | `main.6c38dd82.js`: `documentArchiveOpnameResult:{name:"documentArchiveOpnameResult",label:"archive.Archive Opname Result"}`, `"archive.Archive Opname Result":"Archive Opname Result"`, `…:"Hasil Opname Arsip"`; `jest-r1r2-verbose-r2b.txt` baris "terdaftar dengan nama persis display setting BE…", "subjudul berlabel, bukan 'No data' (EN)" dan (ID) lulus; 17 suite / 533 tes lulus. Daftar tes UI butir 27 mencatat bahwa label belum bisa dilihat manusia |
| **AC-R2** | e2e `ED-1251-review.spec.js` desktop + 375 px: sifat terhitung (`getComputedStyle`), path svg vs paket ikon, geometri, tooltip, loading; bandingkan tombol baris dengan tombol judul Hasil Opname; unit test | PASS | tombol baris == tombol judul di SEMUA sifat (`diffLooks` = `[]`) pada keadaan diam, loading, desktop dan 375 px: kelas `ant-btn ant-btn-icon-only ant-btn-primary btn-icon icon` (tanpa `light`, `btn-action`, `ant-btn-sm`), 40 x 40, `bg rgb(0,158,247)`, `color rgb(255,255,255)`, radius 7,6, ikon 20 px, tanpa teks, `hasId false`; ikon = `PrinterFilled`/`FileExcelFilled` dan bukan Outlined; jarak 4 px, kolom 96 px, tombol utuh di dalam sel/baris (tinggi baris 57 px, +6 px, sel lain juga 57); tooltip baris "Print Preview"/"Excel"; 375 px: tiga posisi geser, `cellR 363 <= vw`, `b0 [273,313]`, `b1 [317,357]` berdampingan; tanpa tooltip. ss `r2-01..08`, `r2-10..13` |

### R-3 / R-4 di browser (bagian dari AC-16..AC-19, sesi sisipan 247 baris)

| Kondisi (viewer + berkas) | Halaman | Halaman berfooter (berkas = viewer) | MediaBox pt |
|---|---|---|---|
| DrawerPdf GET pdf A4-P | 7 | 7/7 | 595,3 x 841,9 |
| Customize pratinjau A4-P | 7 | 7/7 | 595,3 x 841,9 |
| Customize pratinjau A4-L | 10 | 10/10 | 841,9 x 595,3 |
| Customize pratinjau **Continuous-L** (200, tanpa notifikasi error, FE mengirim `Continuous-L`) | 10 | 10/10 | 784,8 x 597,6 |
| Customize pratinjau **Continuous-P** (200) | 19 | 19/19 | 612 x 381,6 |
| Simpan Continuous-P -> GET pdf tersimpan (DB `Continuous-P`, template lain sama, tanpa baris baru) | 19 | 19/19 | 612 x 381,6 |
| Pulihkan A4 Portrait lewat UI -> GET pdf tersimpan (DB `A4-P`) | 7 | 7/7 | 595,3 x 841,9 |

Footer diperiksa di dua tempat: teks PDF tiap halaman (`Dicetak oleh <user>` + `Halaman i dari N`, nomor halaman = urutan halaman) dan lapisan teks pdf.js tiap halaman di viewer (digulir bertahap karena viewer hanya
merender halaman di sekitar layar). Opsi kertas di Customize: A4, A5, Letter, Legal, Folio, **Continuous**. Batas potongan Rincian 200 baris dilewati (247 baris). Sesi nyata developer (…033097, …474864, …422239) tidak terlihat QA_USER
(sudah dibuktikan QA BE r3 lewat render dalam proses baca saja); bug footer lama dibuktikan di sana, di sini yang dibuktikan = tampilan FE sesudah perbaikan.

## Defect

Tidak ada.

## Galeri e2e & regresi BE

- e2e ED-1251 final: `work/e2e/ED-1251-archive-export-opname-pdf-excel-20261026-132126/` (14 OK: 1 smoke + 13 flow; 0 GAGAL, 0 SKIP; `--workers 1`, profil default; fixture setup/teardown OK, log-out 1/1 dicabut).
  Log `work/ed1251-fe/e2e-r2-final.txt`. Lari pertama (`…-125137`, 11 OK / 3 GAGAL) dan lari ulang (`…-125725`, 3 OK) = perbaikan skrip QA sendiri, lihat bawah.
- e2e ED-1028 (regresi F-2/`histRows`), profil default: `work/e2e/ED-1028-archive-opname-history-20261026-130847/` 13 OK; log `work/ed1251-fe/e2e-ed1028-r2.txt`; teardown fixture ED-1028 bersih (24 archives + 16 sesi fixture dibuang, preset dibuang, `user_roles` QA_USER2 dipulihkan). Tes `[user2]` tidak dijalankan (instruksi: profil default).
- Regresi BE ED-1251: `work/ed1251-fe/be-regress-r2.txt`, `work/qa-http/ED-1251-20261026200658.json` = **36/36** (termasuk AC-R3a/R3b/R4/R5, C-0 awal dan C-9 akhir). Pemilik: `work/qa-http/ED-1028-20261026200827.json` (AC-1/12/13/28 = 4/4, keadaan "baris Result ADA"),
  `work/qa-http/ED-1250-20261026200835.json` (AC-10/11 = 2/2). Server php-fpm (tanpa reload; kode BE tidak diubah QA).

## Perubahan skrip QA (milik item ini; folder ED-1028 dan ED-1250 tidak disentuh)

Skrip yang ditinggalkan QA FE r2 terdahulu saya tinjau dan pakai ulang dengan perbaikan. Lari pertama menemukan **3 kelemahan skrip, bukan defect produk** (bukti: setelah skrip diperbaiki, sifat yang sama identik; catatan tiap sifat ada di `report.json`):
1. `viewerLastPage` mensyaratkan jumlah `.rpv-core__inner-page` = jumlah halaman; pdf.js memvirtualisasi (4 dari 7 halaman ter-render) -> diganti `viewerFooters` (toolbar "/ N", gulir bertahap, kumpulkan lapisan teks `core__text-layer-i`, asersi footer pada SETIAP halaman).
2. R-2 desktop: tampilan loading baris vs judul diukur sesaat setelah klik (transisi/gelombang antd belum selesai) -> ukur sesudah 900 ms dengan kursor dijauhkan.
3. R-2 375 px: kursor masih di tombol Print baris (warna hover) saat tombol baris dibandingkan dengan judul -> kursor dijauhkan sebelum mengukur. Semua perbandingan kini memakai `diffLooks` (daftar sifat yang beda per tombol), bukan diff JSON yang terpotong harness.

Berkas: `qa/e2e/ED-1251-review.spec.js` (baru: 2 test R-2), `qa/e2e/ED-1251-customize.spec.js` (test R-3/R-4 baru, `viewerFooters`; awal AC-19 menerima baris template yang sudah ada), `qa/e2e/lib.js` (`footerReport`, `crop`, `antIconPaths`, `ptexts`),
`qa/e2e/fixture.php` (`pdf` mengembalikan teks per halaman), `qa/e2e/fixrun/qa/scenario-fix.php` (FIX-UP menambah sesi sisipan 247 baris `QA251BULK-…`, dibuang `q251_bulk_purge` di FIX-DOWN).

## Yang tidak bisa diverifikasi (dan kenapa)

- **Tampilan label R-1 di layar nyata:** halaman Setup > Display Setting tidak di-route dan Archive tidak punya tombol Display Setting untuk tabel export (spec §9 "menyusul"), jadi label tidak bisa dibuka lewat aplikasi; diganti kode + bundle + unit test (AC-R1). Butir 27 Daftar tes UI sudah mencatatnya.
- Dialog cetak browser (tombol Print `DrawerPdf`) dan jalur Android/Capacitor: headless, komponen bersama; AC-18 hanya mensyaratkan tombol ada.
- Butir 22 Daftar tes UI (role tanpa `Opname Document`): tidak dijalankan di tahap ini; dicakup e2e ED-1028 AC-27 `[user2]` (suite pemilik, tidak diulang). Sesi nyata developer tidak terlihat QA_USER, sehingga footer pada sesi itu hanya dibuktikan QA BE r3 (render dalam proses, baca saja).
- Worker pdf.js (`unpkg.com`) dilayani lokal dari `node_modules` FE (versi sama) lewat `page.route`; render tetap dibuktikan lewat kanvas dan lapisan teks.

## Di luar item (bukan defect item ini)

1. (info, sudah dikenal BE r3 #4) Footer PDF di viewer: "Dicetak pada 26 Oct 2026 07:58:09" saat jam WIB 19:58 (`h:i:s` 12 jam tanpa AM/PM) dan bulan Inggris pada template berbahasa id; kode bersama `report-footer-1`.
2. (info) Tinggi baris tabel Opname History kini 57 px (+6 px menurut catatan developer) karena tombol 40 px (catatan developer R-2, keputusan orkestrator: ukuran harfiah sama dengan judul Opname Result); tidak ada baris terpotong.
3. Tetap dari r1 (kode bersama, tidak berubah ronde ini): `DrawerCustomize` Simpan <±1 dtk sesudah ubah kolom = `pageerror` `undefined is not iterable` (pratinjau debounce berjalan sesudah unmount); mencentang ulang kolom menaruhnya di urutan terakhir (terlihat lagi di AC-19: Excel sesudah pulihkan = "...Tidak Valid" dan "...Hasil, Waktu"); C-9 `POST setting/pdf-templates/update` tanpa cek permission (Simpan FE mengirim id baris template sendiri, template lain tidak berubah: 142 -> 142 + checksum sama).

## Data uji, yang dibuat/diubah/dihapus

- Fixture berjurnal (prefix `0QA251-`, S1/S2 lewat `POST opnames` oleh QA_USER, folder/dokumen/izin; sesi sisipan `QA251BULKFE…` 247 baris) dibuat lalu **dibuang** di teardown `FIX-DOWN` tiap run (3 lari e2e ED-1251 + 1 ED-1028; asersi teardown: data developer 3 sesi/4 baris folder/896 baris dokumen sama persis, `user_roles`/bahasa QA_USER dan
  `user_roles` QA_USER2 sama, template lain 142 baris + checksum sama, tanpa preset `QA*-ROLE-*`). Bahasa QA_USER diubah sementara EN lalu dipulihkan (`ID`).
- **Baris developer `documentArchiveOpnameResult` (template 519, cds 2585): byte-identik.** Snapshot sebelum (`work/ed1251-fe/r2-snap-before.json`) vs sesudah semua run (`r2-snap-after.json`): `cmp` seluruh kolom kedua baris, total `transaction_pdf_templates` 142 (checksum `280946114098`),
  `column_display_settings` 364, `updater_logs` 99, `archive_opnames` 3, `archive_opname_documents` 896, `users` QA bahasa sama = **IDENTIK** (juga identik di titik tengah sesudah regresi BE). Catatan jujur: Simpan Customize dan skenario BE lama (AC-8/13/15, E-1…) memulihkan
  baris itu dengan hapus + sisip ulang isi yang sama dari jurnal (pola DU-5 a, berjurnal); ini yang membuat byte-identik terbukti, bukan diasumsikan.
- Tidak ada yang dihapus milik orang lain; tidak ada tabel dikosongkan; sesi developer `…033097` dan `…474864` tidak disentuh (hanya dibaca QA BE r3). Pemeriksaan akhir read-only: `archives` `%QA251%` 0, `%QA28%` 0, `%QA50%` 0, `roles QA%` 0, opname `QA%` 0, token aktif QA_USER/QA_USER2 0,
  tanpa `.fixture-ids`/`.journal-*` tersisa di folder ED-1251 dan ED-1028; semua token e2e/runner dilog-out (e2e: "dicabut=ya"; runner: "masih aktif: 0"). Tidak ada proses yang dimatikan; tidak ada penolakan izin/classifier ronde ini.

## Riwayat ronde

- r1 (2026-10-24/25): FE PASS, 7 PASS / 1 MANUAL (AC-23) / 0 FAIL (AC-16..22). Build OK; unit Archive 529 tes (+ full suite: 4 pra-ada merah, sisanya timeout beban yang lulus sendiri); e2e 11/11 (galeri `…-20261024-234609`); regresi BE 32/32, ED-1028 4/4, ED-1250 2/2;
  F-2: ED-1028 e2e `histRows` `td` -> `td:not(.p-0)` (13/13 hijau, merah tanpa edit). Temuan luar item: `DrawerCustomize` pageerror Simpan cepat, kolom dicentang ulang ke urutan akhir, C-9; saran dok FE butir 24 (sudah dikerjakan orkestrator).
- r1 addendum F-1 (2026-10-25): ED-1028 `scenario9-display-setting.php` `:127,130,138` benar di dua keadaan (baris Result tidak ada / ada) dengan konstanta `Q28_DSRES`; AC-1/12/13/28 PASS=4 di keduanya; baris Result dipulihkan 0/0 saat itu; verdict tetap PASS.
- r2 (2026-10-26): FE PASS, 9 PASS / 1 MANUAL / 0 FAIL: AC-16..22 ulang + AC-R1 (R-1, kode + unit test + bundle) + AC-R2 (R-2, browser). e2e final 14/14, ED-1028 e2e 13/13, unit 533 tes (scoped), full suite 4 pra-ada + 1 timeout beban, regresi BE 36/36, ED-1028 4/4, ED-1250 2/2.
  Lari e2e pertama 3 GAGAL = kelemahan skrip QA (virtualisasi viewer, hover/transisi), diperbaiki dan diulang; tanpa defect produk. Baris developer 519/2585 byte-identik.
