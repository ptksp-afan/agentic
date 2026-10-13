---
key: ED-1071
scope: fe
round: 1
verdict: PASS
counts: {pass: 1, fail: 0, manual: 0, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

Tahap QA FE ronde 1 untuk Bug ED-1071 (epic ED-1022). Spec `fe_modules: []`: FE tidak berubah (K-6 a, BE saja). `ksp-react` HEAD `d56ce3962`, 0 berkas kotor (dicek ulang di akhir). Satu-satunya AC tahap ini: **AC-10** (`[FE]`, e2e `QA_USER`) = **PASS**, dibuktikan oleh harness (`work/e2e/ED-1071-v5-collation-latin1-20261013-045901/`, 7 dari 7 OK; run sebelumnya `...-20261013-042308/` juga 7/7).

- Sidik jari kode BE sama dengan QA BE r2: sha256 `git diff` + `Latin1Rule.php` di `ksp-erp` = `7472b9fffa7eeb67` (dicek sebelum mulai dan sesudah semua run). Kode BE tidak berubah.
- Build staging FE (`scripts/fe-build.sh build`): **lulus** ("Compiled successfully", exit 0, 78 dtk; `work/qa71-fe-build.txt`, tidak ke `build/` developer).
- Jest modul Archive: 12 suite / 447 tes. Run pertama (paralel) 5 suite gagal karena batas waktu 5 dtk di bawah beban; **semua 5 lulus bila dijalankan sendiri** (`--runInBand`: ArchivePage 39, ArchiveOpnameModal 22, ArchiveDrawer 35, ArchiveOpnameHistory 10, ArchivePermissionPage 29). Bukan defect.
- Jest penuh (80 suite, `--maxWorkers=2`): 71 lulus, 9 gagal. Tiga suite Archive gagal lagi karena batas waktu (tes yang gagal berbeda dari run sebelumnya; lulus sendiri: 39/35/10). Dua suite non-Archive lulus sendiri (RaptorSync 58, ItemRaptorImport 26). **Empat suite non-Archive gagal stabil** meski dijalankan sendiri (pra-ada, FE 0 perubahan, bukan defect item ini): `components/Item/BatchOut` (1 tes), `containers/Production/production.function.test.js` (2 tes `getRecipeTotals`: 700 vs 800), `containers/CustomerPurchaseOrder/CustomerPurchaseOrderView` (suite gagal dimuat), `containers/InterbankTransfer/InterbankTransferForm` (1 tes, `NaN` pada tanggal default hari ini).
- Regresi BE: ED-1071 16/16 PASS; ED-1025 39 PASS / 1 FAIL (AC-25 baseline, bukan defect); ED-1029 40 PASS / 1 SKIP; ED-1069 16/16 PASS. Tidak ada respons 5xx.
- Data uji: kembali persis (snapshot 16 tabel + 2 hitungan sebelum/sesudah sama, kecuali checksum `users` karena `updated_at` login/logout). Tidak ada baris di luar prefiks QA yang dihapus.

## Hasil per AC

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-10 (a) | List Archive, drawer folder (tab Rincian/Detail dan Izin) dan Izin Arsip by User (`/archives/permissions`) terbuka tanpa error konsol/halaman/API >= 400 dan tanpa toast | PASS | e2e `[default] ED-1071 AC-10 a` + smoke `routes.json` (2 layar): OK. Tab drawer: `Rincian | Izin | Verifikasi`; `GET archives/{id}` 200; Nama terisi; toggle Izin Off. Galeri `AC10a-01..04`, `smoke-_archives*.png` |
| AC-10 (b) | "+" -> Tambah Folder, Nama `QA71 😀`, Simpan: `POST archives/create-folder` 422, `errors.name` = teks GE0748 (ID); teks yang sama tampil di bawah kolom Nama; modal tetap terbuka dan Nama tetap terisi; tanpa folder baru | PASS | e2e `AC-10 b`. Respons `{"errors":{"name":["Mengandung karakter yang tidak didukung (mis. emoji, huruf Yunani/Mandarin). Gunakan huruf Latin."]}}`. DB sebelum/sesudah: `archives` 49149 = 49149 (termasuk 1 folder uji), `stray_count` 0 (`archives LIKE 'QA71 %'`), `archive_locations` 48037, `archive_permissions` 6 sama. Daftar UI sesudah muat ulang: 0 baris `QA71 ...`. Galeri `AC10b-01..03` |
| AC-10 (c) | Drawer folder uji, Deskripsi `中文`, Simpan: `PUT archives/{id}` 422, `errors.description` = teks GE0748; teks di bawah Deskripsi; drawer tetap terbuka; DB folder tidak berubah | PASS | e2e `AC-10 c`. `name`, `description` (NULL, bukan `?`), `updated_at` sama sebelum/sesudah (`fixture.php prop`). Galeri `AC10c-01..02` |
| AC-10 (d) | Search `😀` di Archive: `GET archives?search={"query":"😀"}` 200, `total` 0, `data` kosong; tabel kosong ("Tidak ada data"), tanpa error/toast; reset filter mengembalikan daftar | PASS | e2e `AC-10 d`; catatan: `200 total=0 data=0`. Galeri `AC10d-01..02` |
| AC-10 (e) | Select User di tab Izin (toggle Izin Folder On lokal, "+ Tambah User", ketik `😀`): `GET select/.../archive/users?...&search=😀` 200, `options` `[]`, dropdown "Tidak ada data" tanpa opsi, tanpa error. Tambahan: select User di Izin Arsip by User sama (200, `[]`) | PASS | e2e `AC-10 e`. DB sesudah: `is_folder_permission`, `updated_at`, `archive_permissions` tidak berubah (toggle hanya keadaan form, drawer ditutup tanpa Simpan). Galeri `AC10e-01..02` |

Setiap test memanggil `monitor.check()` (0 error konsol/halaman/respons >= 400/request gagal yang tidak diharapkan) dan `noServerError` (0 respons 5xx). Satu-satunya error yang dikeluarkan dari pemeriksaan adalah penolakan 422 yang memang diuji (test b: 1 `POST create-folder` 422; test c: 1 `PUT archives/{id}` 422) beserta log konsol axios `Request failed with status code 422` yang menyertainya (`useStore`/`useUpdate` memanggil `console.error(error)`); jumlah respons 422 dipastikan tepat 1 per test. Allow-list tidak dipakai.

Mapping AC ke berkas: `features/ED-1071-v5-collation-latin1/qa/e2e/ED-1071-latin1.spec.js` (5 flow), `routes.json` (2 layar), `fixtures.json` + `fixture.php` (satu folder uji root `QA71-E2E-A`; teardown membuang baris `QA71-E2E-%` dan folder `QA71 %` yang salah dibuat, dan membuat run gagal bila yang terakhir ada).

## Defect

Tidak ada.

Catatan perilaku (bukan defect, bukan bagian AC):
- N-1: pada penolakan 422, FE juga memunculkan toast standar `[422] The given data was invalid.` (pesan generik Laravel, berbahasa Inggris) di samping teks GE0748 yang dilokalkan di bawah field dan di kotak "Error ketika submit data" pada form. Sesuai K-6 a ("`setFieldsError` + toast standar"; `postArchive`/`putArchive` memakai `showMessage: true`). Tidak diubah.
- N-2 (dari qa-be N-1): `name` di put-in/hand-over/receive dan `id_archive` add-document kini 422 untuk angka/array. Di FE `name` selalu teks (`ArchiveDocumentModalForm.js:18` memasang `rawValue` pemindai, atau `TextFormLocale`); `add-document` tidak dipanggil FE. Hanya dibuktikan lewat pembacaan kode; alur Letakkan Dokumen/Serahkan/Terima tidak dijalankan di e2e (mengubah data, di luar AC-10; dibuktikan BE oleh `ED-1071` AC-3/AC-7, R2-2).
- N-3: di tab Izin, `Escape` menutup drawer (bukan hanya dropdown), jadi spec menutup dropdown dengan klik header drawer. Perilaku FE lama, bukan bagian item ini.
- N-4: Cari di dalam modal Pindahkan (ED-1125) tidak dipakai/diuji di item ini.

## Galeri e2e & regresi BE

**Galeri:** `/var/www/opname-archive/agentic/work/e2e/ED-1071-v5-collation-latin1-20261013-045901/index.html` (run akhir: 7 OK, 0 GAGAL, 0 SKIP; 15 tangkapan layar (13 flow + 2 smoke) di `screenshots/default/`). Run `...-20261013-042308/` identik (7/7). Run pertama `...-20261013-042016/` 4 OK / 3 GAGAL **karena kesalahan spec saya sendiri**, bukan FE/BE: (a) pencarian kolom Nama memakai `input` pertama yang ternyata kolom tersembunyi, (b) `hasText: 'QA71 '` dipangkas Playwright jadi `QA71` dan cocok dengan folder uji `QA71-E2E-A`, (e) mengharapkan GET select users saat membuka dropdown di halaman Izin Arsip by User padahal opsi sudah dimuat saat layar dibuka. Ketiganya diperbaiki di spec; di run itu POST 422 dan teks GE0748 sudah benar.

**Perintah:** `scripts/e2e/run.sh ED-1071 --profile default --workers 1`; fixture `qa71-folder-uji` setup/teardown OK tiap run (baseline non-fixture `archives` 49148, `archive_documents` 49126, `archive_locations` 48037, `archive_permissions` 6, sama saat teardown); log-out 1/1 token dicabut.

**Regresi BE** (kode BE tidak berubah sejak QA BE r2; `/usr/bin/php scripts/qa-http/run.php <KEY>`, satu per satu; keluaran di `work/qa71-fe-be-<KEY>.txt`):

| Suite | Hasil | Catatan |
|---|---|---|
| ED-1071 | 16 PASS / 0 FAIL | AC-1..AC-8, X-1..X-6, R2-1, R2-2 (`work/qa-http/ED-1071-20261013113411.json`) |
| ED-1025 | 39 PASS / 1 FAIL | AC-25 baseline (role 3 baris 1140 `permission_name` NULL, "diharapkan 1, didapat 0"), dicatat, bukan defect; X-12 (AC-9 ED-1071) PASS: 80 kasus {200:5, 404:20, 422:55}; X-13 pagination/sorts 500 = karakterisasi ED-1070 (tunda) |
| ED-1029 | 40 PASS / 0 FAIL / 1 SKIP | X-1 PASS: 49 kasus {404:29, 422:20}; X-ISO SKIP (QA_DBS satu DB) |
| ED-1069 | 16 PASS / 0 FAIL | AC-10: id Sirilik/Omega/emoji/CJK = 404 ARCHIVE400 |
| ED-1024/1026/1027/1028 | tidak dijalankan ulang (sesuai tugas) | dikutip dari `qa-be.md` r2 pada kode BE yang sama: 1024 26 PASS; 1026 38 PASS / 1 FAIL (AC-3 baseline role 5); 1027 25 PASS / 3 FAIL / 1 SKIP (X-8, X-9, X-10 baseline); 1028 21 PASS / 3 FAIL (AC-12, AC-14, AC-28 baseline role 5) |

Hasil sama dengan QA BE r2 untuk 1025/1029/1069/1071 (tanpa kegagalan baru). Tidak ada respons 5xx yang terlihat (e2e: `noServerError` di tiap flow; BE: asersi otomatis runner).

**Jest** (Node 16.20.2 dari `.nvmrc`): berkas keluaran `work/qa71-jest-archive.txt` (Archive, paralel), `work/qa71-jest-full.txt` (penuh), `work/qa71-jest-<suite>.txt` / `qa71-jest-rerun-*.txt` (suite dijalankan sendiri). Ringkasan di Ringkasan.

## Isolasi data uji (ringkas)

- Dibuat dan dibuang sendiri: folder `QA71-E2E-A` (fixture, 3 run, selalu dibuang teardown); folder `QA71 ...` salah dibuat: **0** (`stray_count` 0 di tiap pengecekan, termasuk akhir).
- Snapshot 16 tabel + 2 hitungan (`archives` 49148 / checksum 3907421388, `archive_permissions` 6, `archive_documents` 49126, `archive_locations` 48037, `roles` 27, `user_roles` 126, `role_permissions` 3794, `column_display_settings` 361, `updater_logs` 99, `employees` 124, `employee_locations` 157, `permissions` 832, `archive_opname*` 0; `archives QA%` 0, `roles QA71%` 0) sebelum dan sesudah semua run: **sama**, satu-satunya beda checksum `users` (login/logout).
- Dihapus di luar data sendiri: **tidak ada**. `ED-1027/qa/.restore-journal-q27-user2.json` tidak disentuh (masih ada, untracked, untuk developer). Tidak ada berkas `baseline/*.status` developer yang disentuh.
- `QA_DBS` hanya `api_sidomaju`: isolasi antar-tenant tidak berlaku di tahap FE.

## Yang tidak bisa diverifikasi (dan kenapa)

Tidak ada untuk AC-10. Catatan: spec tidak punya subtask (tanpa `## Daftar tes UI`); tidak ada AC yang tersisa sebagai MANUAL (Gate 2). Tampilan visual dibandingkan desain tidak diuji (tidak ada `brief/` untuk item ini; tidak ada perubahan FE). Penolakan izin/classifier selama tahap ini: **tidak ada**; semua perintah berjalan.

## Riwayat ronde

- Ronde 1 (2026-10-13): AC-10 PASS (e2e 7/7 dua kali berturut-turut sesudah spec diperbaiki); build FE lulus; Jest Archive 447/447 (kegagalan beban lulus sendiri); regresi BE tanpa kegagalan baru; 0 defect; verdict PASS.
