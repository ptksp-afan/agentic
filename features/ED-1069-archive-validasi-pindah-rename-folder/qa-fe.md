---
key: ED-1069
scope: fe
round: 1
verdict: PASS
counts: {pass: 2, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

QA FE ronde 1 untuk Bug ED-1069 (tanpa subtask, `contract: null`, `fe_modules: []`). **Tidak ada kode FE yang berubah** (FE `ksp-react` bersih di HEAD `47bb57d1d`, `git status` kosong); stage ini menjalankan pemeriksaan yang diminta spec: build staging, Jest Archive, e2e AC-13/AC-14, dan regresi skenario BE.

- **Build staging: lulus** (`scripts/fe-build.sh build`, exit 0, "Compiled successfully", 0 peringatan, 104 s; staging 14:09 >= commit FE).
- **Jest:** Archive 11 suite: semua lulus (empat suite gagal di run pertama karena beban mesin, lulus bila dijalankan sendiri). Suite penuh: 4 suite di luar Archive tetap gagal bila dijalankan sendiri; bukan buatan item ini (lihat "Bug di luar item", B-FE-2).
- **e2e:** 5/5 lulus (`--workers 1`): smoke `/archives`, AC-13 satu folder, AC-13 massal, AC-14, karakterisasi bug B-FE-1. Galeri: `work/e2e/ED-1069-archive-validasi-pindah-rename-folder-20261010-152924/index.html`.
- **AC-13 PASS dengan satu catatan penting:** langkah **mengetik di kolom Cari dalam modal Pindahkan tidak bisa dipakai** karena bug FE lama (B-FE-1: Enter / tombol Cari di dalam modal memicu submit form HTML asli ke `/?` dan memuat ulang seluruh aplikasi). Pada e2e, hasil pencarian modal digantikan di tingkat jaringan (GET list picker dialihkan ke `?search={"query":"..."}`, respons BE asli); seluruh sisanya (pilih baris C, POST put-in, respons 400 `ARCHIVE404`, toast, modal tetap, data tidak berubah) asli. Langkah ketik-di-Cari dicatat **MANUAL (Gate 2)** setelah B-FE-1 diperbaiki.
- Regresi BE: ED-1069 16/16, ED-1024 26/26, ED-1026 39/39, ED-1027 28 (+1 SKIP), ED-1028 24/24, ED-1029 29 (+1 SKIP) PASS; ED-1025 39 PASS + 1 FAIL = X-12 (usang, ED-1071, bukan FAIL item ini). ED-1025 X-8 (dibalik oleh QA 1069) lulus.
- Tidak ada HTTP 500 di semua jalur e2e; tidak ada penolakan izin/classifier.
- **Temuan untuk orkestrator:** Bug ini tidak punya subtask, jadi tidak ada `## Daftar tes UI`. Baris MANUAL di bawah (Search di modal) belum tercatat di daftar Gate 2 mana pun: mohon dibawa ke Gate 2 / ke tiket B-FE-1.

## Hasil per AC

Perintah e2e: `scripts/e2e/run.sh ED-1069 --profile default --workers 1` (fixture `qa/e2e/fixture.php`, folder berawalan `0QA69E-`; spec `qa/e2e/ED-1069-validasi-pindah.spec.js`; `routes.json` = `/archives`). `QA_USER` = role 3, bahasa ID. Error konsol/halaman/HTTP >= 400 dicek otomatis di akhir tiap test; satu-satunya yang dikeluarkan dari pemeriksaan adalah penolakan 400 `POST documents/put-in` yang memang diharapkan (+ log konsol axios-nya), jumlahnya diasersi.

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-13 (penolakan, toast, modal, data, sah, massal) | e2e test 1 dan 2. Fixture A>B>C, X, T, M, M2. Test 1: buka ⋯ Pindahkan A, picker memuat hasil pencarian C (respons BE asli), pilih C, Pindahkan; lalu ulang untuk B (anak langsung); lalu M -> T (sah). Test 2: mode Pilih, centang A dan X, Pindahkan, Lanjut, picker C, Pindahkan; lalu massal sah [M, M2] -> T | PASS | Test 1: `POST put-in` A -> C = **400**, body `id_archives=[A]`, `id_archive_parent=C`, respons memuat `ARCHIVE404` + teks id-ID; toast "Error [400] Tidak bisa dipindahkan ke dirinya sendiri atau subfoldernya" (`screenshots/default/AC13-03a-toast-A-ke-C.png`); modal tetap terbuka, pilihan C tetap, tombol Pindahkan aktif lagi; A -> B sama (`AC13-04a`); baris A, B (semua kolom, termasuk `history`, `updated_at`) di DB identik, C tetap di bawah B, A tetap di root pada layar. Sah M -> T = **200** `ARCHIVE204`, modal menutup, tanpa toast galat, DB `id_archive_parent` M = T, `history` entri `place`, M hilang dari root dan muncul di isi T; dipulihkan `fixture.php restore` (baris M identik dengan snapshot). Test 2: [A, X] -> C = **400** `ARCHIVE404`, body `id_archives=[A,X]`, toast sama (`AC13-09a`), modal massal tetap; A dan **X** (yang sebenarnya sah) di DB identik (permintaan ditolak utuh); massal sah [M, M2] -> T = 200, DB kedua folder di bawah T, modal menutup, dipulihkan. Run: `work/e2e/ED-1069-...-20261010-152924/` (`AC13-01` s.d. `AC13-10`) |
| AC-13 (langkah mengetik di kolom Cari modal Pindahkan / pindah massal) | percobaan e2e berulang (Enter, tombol Cari popover, scanner USB) + test karakterisasi | MANUAL (Gate 2) | **Bug FE di luar item B-FE-1.** Enter di kolom Cari modal -> CDP `Page.frameRequestedNavigation` = `formSubmissionGet http://127.0.0.1:4100/?` (aplikasi dimuat ulang, modal hilang, tanpa request search); tombol "Cari" popover sama; scanner USB mencari di tabel halaman di belakang modal, bukan tabel modal. Berlaku di modal satu folder dan di langkah Pilih Folder pindah massal. Diperbaiki, baru langkah ini dapat dicek manusia/e2e; test karakterisasi sengaja akan gagal saat itu terjadi |
| AC-14 | e2e test 4 + smoke route `/archives` (`waitFor .archive-page .ant-table`) | PASS | Tanpa error konsol/halaman/HTTP >= 400, tanpa toast. Root memuat A, X, T, M, M2, F. A > B > C: `GET archives?id_archive=` A, B, C = 200, isi A=[B], B=[C], `breadcrumbs` C = A > B > C (3 label benar, tanpa siklus); kembali ke root lewat breadcrumb. **Show Related Transaction di F** (7 dokumen yatim tipe 6, 7, 8, 9, 29, 31, 222 + 1 dokumen yang menunjuk transaksi nyata): `GET archives?id_archive=F&search={"showRelatedTransaction":true}` = **200** (dulu 500), 8 dokumen, tiap yatim `related_transactions === []`, dokumen nyata 1 transaksi terkait; jumlah baris tabel = 8 + 1; tanpa `[object Object]` (`AC14-05`). **Drawer Detail** folder B (Beberapa Lokasi): `GET select/document-archive/archive/locations?id_archive_parent=<A>` = 200, 3 opsi lokasi tampil (`AC14-06`). **Modal Pindahkan** dan **pindah massal** (konfirmasi, lalu Pilih Folder) terbuka dengan tabel terisi, ditutup tanpa konfirmasi, mode Pilih dikeluarkan; data X tidak berubah. Tanpa respons 5xx |
| Karakterisasi B-FE-1 (bukan AC) | e2e test 5 | PASS (mengunci perilaku bug) | form Search bersarang di form modal (`nested: true`, 2 form dalam modal); Enter di modal Pindahkan dan di Pilih Folder massal = tepat 1 navigasi `formSubmissionGet .../?`; data tidak berubah, tanpa POST put-in |

Tahap pemeriksaan lain (bukan AC):

| Pemeriksaan | Hasil | Bukti |
|---|---|---|
| `scripts/fe-build.sh build` | PASS | `work/qa69fe/build.out`: "Compiled successfully.", "Done in 104.32s", `# exit=0`; `grep -ci warning work/fe-build.log` = 0 |
| `yarn test --watchAll=false src/containers/Archive` | PASS (11 suite) | run 1 (`work/qa69fe/jest-archive.out`): 8 suite lulus, 3 gagal (7 tes: timeout 5000 ms / `findBy` pendek) saat beban mesin 4 s.d. 5 (php-fpm sibuk). Dijalankan sendiri: `ArchiveOpnameModal` 22/22, `ArchiveDrawer` 27/27; `ArchivePage` pertama 28/30 (dua tes lain dari run 1: ikon perisai, ED-1029), kedua 30/30 (`jest-rerun2-ArchivePage.out`). Tes yang gagal berbeda tiap run = flakiness beban, bukan defect |
| `yarn test --watchAll=false` (suite penuh, sekali) | 4 suite gagal di luar Archive | 79 suite / 1679 tes, 603 s: 12 suite gagal (41 tes) di bawah beban. Dijalankan sendiri: `ArchiveDrawer`, `ArchiveOpnameModal`, `ArchivePage`, `ArchivePermissionPage` (17/17), `RaptorFailedPage`, `RaptorPendingView`, `RaptorSync`, `RaptorPendingDrawer` lulus. Masih gagal sendirian: `components/Item/BatchOut`, `CustomerPurchaseOrderView`, `InterbankTransferForm`, `Production/production.function` (B-FE-2) |

## Defect

Tidak ada defect pada ED-1069. Item ini tidak mengubah FE; perilaku FE yang diperiksa (menampilkan penolakan BE, layar yang memanggil endpoint yang diperbaiki) benar. Tidak ada HTTP 500 pada jalur e2e.

## Bug di luar item

| # | Temuan | Reproduksi | Status |
|---|---|---|---|
| B-FE-1 | **Search di dalam modal Pindahkan dan di langkah Pilih Folder pindah massal memuat ulang seluruh aplikasi.** Akibat: modal hilang dan keadaan layar hilang; dari UI tidak ada cara memilih folder yang hanya terjangkau lewat pencarian. Efek ke item ini: turunan folder sumber hanya terjangkau lewat Search (folder sumber terkunci, tidak bisa dibuka), jadi **penolakan siklus ED-1069 saat ini tidak bisa dipicu dari UI**; kalimat spec "Lewat UI: butir 2 (Search modal Pindahkan memuat turunan)" tidak berlaku sampai B-FE-1 diperbaiki. Penyebab yang terlihat: form `Search` (`ant-form-horizontal`) bersarang di form modal (`ant-form-vertical`); submit-nya tidak dicegah dan menjadi navigasi asli | Archive -> ⋯ Pindahkan pada folder mana pun -> ketik teks di kolom Cari dalam modal -> Enter (atau ikon filter -> tombol Cari). Terbukti CDP `formSubmissionGet http://host/?`; otomatis di `qa/e2e/ED-1069-validasi-pindah.spec.js` test "karakterisasi B-FE-1" (juga langkah Pilih Folder massal). Dugaan berkas: `ArchiveMoveModal/ArchiveMoveModalForm.js` (`<Form>` pembungkus `ArchiveTable`), `ArchiveBulkMoveModal/ArchiveBulkMoveModalForm.js`, `archive-components/ArchiveTable/index.js` (`<Search>`), `components/Search/Search/index.js` (`<Form>` + `<button type="submit" hidden>`) | Bug lama (FE tidak berubah di item ini), belum bertiket |
| B-FE-2 | 4 suite Jest di luar Archive gagal walau dijalankan sendiri: `src/components/Item/BatchOut/index.test.js` (1 tes: teks `: 4$` tidak ditemukan), `src/containers/CustomerPurchaseOrder/CustomerPurchaseOrderView/index.test.js` (suite gagal jalan: `electronEvent.appBeforeClose` undefined di `src/helpers/electronHelper.js:48`), `src/containers/InterbankTransfer/InterbankTransferForm/index.test.js` (1 tes: tanggal default `NaN`), `src/containers/Production/production.function.test.js` (2 tes `getRecipeTotals`) | `cd ksp-react; nvm use $(cat .nvmrc); yarn test --watchAll=false <path>` | Bukan buatan item ini (diff FE kosong), pra-ada |

Bug BE ED-1069 B-1 s.d. B-4 (qa-be.md) dan bug yang sudah diketahui (ED-1070, ED-1071, ED-1072, ED-1122, ED-1123) tidak dilaporkan ulang.

## Galeri e2e & regresi BE

Galeri (run akhir): `/var/www/opname-archive/agentic/work/e2e/ED-1069-archive-validasi-pindah-rename-folder-20261010-152924/index.html` (`report.json`, `screenshots/default/`: `AC13-01..10`, `AC13-03a/04a/09a` = tangkapan toast, `AC14-01..09`, `B-FE-1-01/02`, `smoke-_archives.png`). Spec permanen: `features/ED-1069-.../qa/e2e/` (`ED-1069-validasi-pindah.spec.js`, `fixture.php`, `fixtures.json`, `routes.json`); setiap test memulihkan fixture lewat `fixture.php restore` di `beforeEach`/`afterEach`.

Regresi BE (`"$PHP_BIN" scripts/qa-http/run.php <KEY>`, satu suite per giliran, log `work/qa69fe/reg-<KEY>.txt`):

| Item | Hasil | Catatan |
|---|---|---|
| ED-1069 | 16 PASS, 0 FAIL | 5 berkas, 57 s |
| ED-1024 | 26 PASS, 0 FAIL | - |
| ED-1025 | 39 PASS, **1 FAIL** | X-12 = karakterisasi ED-1071 usang (rename non-latin1 kini 500; qa-be.md B-3), bukan FAIL item ini; X-8 lulus |
| ED-1026 | 39 PASS, 0 FAIL | - |
| ED-1027 | 28 PASS, 1 SKIP, 0 FAIL | X-3 = isolasi tenant (1 DB) |
| ED-1028 | 24 PASS, 0 FAIL | - |
| ED-1029 | 29 PASS, 1 SKIP, 0 FAIL | X-ISO = isolasi tenant (1 DB) |

## Yang tidak bisa diverifikasi (dan kenapa)

- Langkah ketik di kolom Cari modal Pindahkan (AC-13): lihat B-FE-1; pada e2e digantikan pengalihan jaringan (respons BE asli). Dicatat MANUAL di atas.
- Tampilan tablet/mobile modal: tidak diminta spec dan tidak dipotret.
- Tanpa tes isolasi tenant di stage FE (`QA_DBS` = 1 DB).

## Data uji dan pemulihan

- **Hanya baris buatan sendiri yang dihapus:** per run e2e fixture membuat 16 baris `archives` berawalan `0QA69E-` (+ 8 `archive_documents`, 1 `archive_locations` milik B) dan menghapusnya di teardown ("tanpa sisa"). 14 run e2e (termasuk run percobaan sewaktu menyelidiki B-FE-1), semuanya dibersihkan sendiri. Skenario BE regresi membersihkan data uji mereka sendiri (terbukti oleh pemeriksaan akhir di bawah: jumlah baris = awal).
- Pemeriksaan akhir (baca saja): `archives` 49.148 dan `archive_documents` 49.126 = jumlah awal; 0 baris `0QA69E-`, 0 baris `QA69`, 0 role `QA69`, 0 folder dengan induk = diri sendiri; bahasa `QA_USER` tetap `ID`; berkas jurnal fixture `.fixture-*.json` terhapus.
- Tidak ada tabel `archive_opname*`, folder/dokumen/transaksi milik developer, role, atau user yang diubah atau dihapus; tidak ada purge/reset data lain. Role dan bahasa user QA tidak diubah.
- Semua sesi e2e di-log-out ("token dicabut: ya"); skenario BE: "masih aktif: 0". Tidak ada proses developer yang dimatikan; build hanya ke staging `/tmp/ksp-react-v5-build`.

## Riwayat ronde

- Ronde 1 (2026-10-10): build lulus; Jest Archive lulus (flakiness beban dicatat), 4 suite non-Archive pra-ada gagal; e2e 5/5; AC-13 PASS (Search di modal MANUAL, bug B-FE-1), AC-14 PASS; regresi BE PASS kecuali ED-1025 X-12 (usang, ED-1071). Temuan di luar item: B-FE-1, B-FE-2.
