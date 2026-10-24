---
key: ED-1250
scope: fe
round: 1
verdict: PASS
counts: {pass: 11, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

QA FE ronde 1 (long run epic ED-1022; Gate 1: K-1 a, K-2 b, K-3 gugur, K-4 a, K-5 a, DU-5 a, DU-6 a berlaku). 12 AC milik stage ini
(AC-13..AC-24): **11 PASS, 1 MANUAL (AC-23, Gate 2), 0 FAIL**. Tidak ada respons 5xx di e2e mana pun, tidak ada entri allow-list. Kode aplikasi
(9 berkas FE, 6 berkas BE) tidak diubah QA.

- Build staging: `scripts/fe-build.sh build` exit 0, "Compiled successfully" tanpa peringatan (`work/ed1250fe/build-r1.out`, `work/fe-build.log`). Sumber FE
  (mtime 15:44) lebih tua dari build (16:56); peringatan harness "Build staging MUNGKIN USANG: 6 berkas belum di-commit" hanya karena pohon kotor.
- e2e ED-1250 (berkas baru `qa/e2e/`): **16 OK / 0 GAGAL** (13 flow `[default]`, 1 flow `[user2]`, 2 route smoke) - galeri
  `work/e2e/ED-1250-archive-filter-verification-status-20261024-184015/index.html`. e2e ED-1125: 18 OK; ED-1069: 5 OK (profil default, `--workers 1`).
- Regresi BE: `run.php ED-1250` PASS=16 FAIL=0 SKIP=1 (ISO-1, satu DB); ED-1027 scenario1/2/9/10: 12 PASS + 1 usang (AC-22, lihat bawah); ED-1028 AC-28 PASS.
- Teardown C-8 PASS (cds `documentArchive` id 2260 `updated_at` 2026-10-24 21:27:41 = awal, memuat `verificationStatus` current & available dan
  `documentVerified`); tidak ada sisa `QA%`; tidak ada jurnal basi di `features/*/qa/**`.
- Skrip permanen: `features/ED-1250-archive-filter-verification-status/qa/e2e/` (`ED-1250-filter-verifikasi.spec.js`, `fixture.php`, `fixtures.json`, `routes.json`).
  Jalankan: `scripts/e2e/run.sh ED-1250 --profile default,user2 --workers 1`.

## Hasil per AC

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-13 | e2e `[default]` id + en + route smoke: respons `queries` memuat `verificationStatus` tepat sesudah `type`; urutan popover Nama, Tipe, **Status Verifikasi**, Tanggal Dimodifikasi, Tampilkan Transaksi Terkait; select kosong; 2 opsi (id Terverifikasi / Belum terverifikasi; en Verified / Unverified, label "Verification Status"); Cari tanpa memilih = 1 GET tanpa key, indikator mati; folder A, subfolder MIX, hasil cari terbuka tanpa error; Display Setting memuat entri sesudah Tipe (id & en; bahasa QA_USER diubah sementara lewat jurnal) | PASS | run `...184015`: "AC-13 (id)", "AC-13 (en)", smoke `[default]`; `screenshots/default/ac13-01..06` |
| AC-14 | e2e: root + Unverified dan Verified: tepat 1 GET `search={"verificationStatus":"…"}` tanpa `id_archive`; breadcrumb "Arsip"; baris = respons; 15 folder root (2 halaman folder) sama dan berurutan dengan tanpa filter; `total` = folder + dokumen root berstatus itu menurut oracle tabel mentah (`fixture.php root-counts`); Verified: dokumen root = hanya QA50-RDV; halaman dokumen Unverified: semua ikon unverified; V/T semua folder dan ringkasan (respons + UI) sama dengan sebelum filter. Di folder A + Verified: `id_archive=A` tetap, search hanya `{"verificationStatus"}`, breadcrumb "Arsip > QA50-A" dan tombol "+" tetap; isi A = oracle DB (semua 5 subfolder + dokumen verified; AHO Handed Over hanya di Verified, ATK Taken dan ANL NULL di Unverified); V/T tangan EMPTY 0/0, ALLV 2/2, NOV 0/2, PRIV "-", MIX = oracle 1/4 | PASS | `...184015` "AC-14 root"; `ac14-01-root-*`, `ac14-01b-*`, `ac14-02/03-folder-A-*` |
| AC-15 | e2e, Unverified aktif: buka A, buka MIX, breadcrumb A, breadcrumb "Arsip", layar akses ditolak (PRIV: 403 `ARCHIVE407`, request ke folder itu membawa key) lalu "Kembali ke Arsip": tiap request `search` persis `{"verificationStatus":"UNVERIFIED"}`, indikator menyala, popover menampilkan nilai, Nama kosong. Reset di A: tanpa `search`, `id_archive=A` tetap, select kosong. Kosongkan select + Cari: tanpa search, tetap di A. Scan QR (`QA50-AU1`): search hanya `{"query"}`, select kosong, AU1 ditemukan | PASS | `...184015` "AC-15"; `ac15-01..04` |
| AC-16 | e2e: di A dengan Unverified, isi Nama `QA50-MIX` + Cari: search memuat `name` dan `verificationStatus`, `id_archive` null, breadcrumb "Arsip", hasil = MIX, MIX-SUB (folder cocok nama tetap) + MIX-U1, MIX-U2, MIX-SUB-U1 (MIX-V1 tidak ada); buka MIX dari hasil: `id_archive=MIX` + search hanya `verificationStatus`, select tetap, Nama kosong. Tipe + filter: kedua key, `id_archive` null, dokumen hasil semua unverified | PASS | `...184015` "AC-16"; `ac16-01..03` |
| AC-17 | unit: `archive.function.test.js` (`getArchiveFilterRequest`, `getArchiveNavigationSearch`: `{verificationStatus}` dan `{verificationStatus, showRelatedTransaction}` tetap di folder, `{verificationStatus, type}` -> `idArchive: null`, `{}` -> `search: null`, navigasi = hanya `{verificationStatus}` atau null), `ArchiveTable/index.test.js`, `ArchivePage/index.test.js`. Kode: halaman dan picker sama-sama memanggil helper (`ArchivePage/index.js:231,240`, `ArchiveTable/index.js:75,83`); `grep onlyRelatedTrans src` kosong | PASS | `jest-archive.out`: archive.function PASS; `jest-rerun1.out`: ArchivePage + ArchiveTable 2 suite / 65 tes PASS |
| AC-18 | e2e picker Pindahkan (⋯ MVD-U) dengan filter halaman Unverified aktif: picker dibuka dengan GET root **tanpa search**, select kosong, indikator mati, filter tepat sesudah Tipe (K-5 a). Root picker + Unverified: 1 GET tanpa `id_archive`, breadcrumb "Arsip", baris = respons, seluruh folder sama dengan tanpa filter. Buka C: key ikut, nilai tetap, isi = oracle (DEST kosong dan MVF tetap); breadcrumb "Arsip": key ikut; Reset: tanpa search, tetap di C; + Nama: kedua key, `id_archive` null; folder tanpa View (PRIV) diklik: 0 request; folder yang sedang dipindahkan (MVF) diklik: 0 request; DEST (kosong) dipilih dengan Verified aktif, Pindahkan: POST put-in 200 (`idArchives=[MVD-U]`, tujuan DEST), DB MVD-U di bawah DEST, refresh halaman membawa filter HALAMAN (bukan filter picker), tabel halaman = respons. Pilih Folder (pindah massal): kosong saat dibuka, root + Unverified, folder tetap, tujuan DEST tetap terpilih dan Pindahkan tetap aktif sesudah ganti filter, buka subfolder dan breadcrumb | PASS | `...184015` "AC-18 picker Pindahkan", "AC-18 Pilih Folder"; `ac18-01..10`; DB dipulihkan (`fixture.php restore`) |
| AC-19 | e2e `[user2]` (QA_USER2 di preset `QA50-ROLE-E2E-NOOD`, List Archive tanpa Opname Document, bahasa EN; `has-od` false): respons `queries` root / folder A / hasil cari / picker Pindahkan / Pilih Folder tanpa `verificationStatus`; popover tanpa select dan label; Display Setting tanpa entri "Verification Status", simpan (Show Related Transaction dilepas) PUT 200; DB: `verificationStatus` tetap di current & available queries, `documentVerified` tetap; tanpa 5xx | PASS | `...184015` "[user2] AC-19" + smoke `[user2]`; `screenshots/user2/ac19-01..04` |
| AC-20 | e2e: Displayed Filters: sembunyikan "Status Verifikasi" -> Simpan (PUT 200): DB keluar dari current_queries, tetap di available; popover halaman dan picker tanpa select; tampilkan lagi -> kembali di keduanya; `cds-restore` memulihkan baris persis, C-8 diperiksa | PASS | `...184015` "AC-20"; `ac20-01/02` |
| AC-21 | e2e: Verified aktif, A -> NOV (tanpa subfolder, tanpa verified): `total` 0, `.ant-empty`, 0 baris, tanpa error/toast; EMPTY juga kosong; NOV dan EMPTY tetap tampil di A saat filter aktif, induk A tetap di root. Picker (Pilih Folder): A -> NOV + Verified = Empty "Tidak ada data", A tetap tampil | PASS | `...184015` "AC-21 halaman", "AC-18 Pilih Folder"; `ac21-01`, `ac21-02` |
| AC-22 | e2e dengan filter aktif: kolom Dokumen Terverifikasi ada, ringkasan sama; modal All (GET verifications tanpa `verificationStatus`, angka = ringkasan); tautan V/T MIX -> modal folder = oracle 1/4 (tidak ikut filter); halaman 2 di B (13 baris, 2 halaman): request membawa key dan `page=2`, 3 baris; ganti filter -> `page=1`; Reload: 1 GET dengan key + folder yang sama; Pindahkan folder MVF dengan Verified aktif: POST put-in 200, SQL: MVF di bawah DEST dan **MVF-V serta MVF-U (tersembunyi filter) tetap di MVF aktif**, refresh berfilter; Letakkan -> Serahkan -> Terima D6 (Unverified aktif): tiap refresh membawa key + folder A, daftar = oracle, D6 dipulihkan. Regresi BE: ED-1027 scenario1 (AC-1, X-1), 2 (AC-2/3/4), 9 (AC-23, X-10, X-11), 10 (AC-24, X-12/13/14) PASS; ED-1028 AC-28 PASS (219 asersi); ED-1027 AC-22 FAIL **usang** (lihat Pengamatan P-1) | PASS | `...184015` 3 test "AC-22"; `ac22-01..07`; `be-reg-1027.out`, `be-reg-1028.out`, `be-reg-1250.out` |
| AC-23 | Letak filter di popover dan gaya EQUAL: penilaian tampilan = manusia. Struktur ada (screenshot `ac13-01`) | MANUAL (Gate 2) | `027-feat-filter-verification-status.md` `## Daftar tes UI` butir 1 ("letak, lebar, dan gaya ... AC-13, AC-23") |
| AC-24 | e2e ED-1125 (cari modal Pindahkan / Pilih Folder, scan di picker): 18/18; ED-1069 (validasi pindah): 5/5, profil default. unit `getArchiveListKey` + describe ED-1150 `ArchiveTable` PASS. e2e sendiri: penanda DOM pada tabel hilang saat filter berganti (halaman dan picker: `key` memuat `search` -> tabel mulai dari awal) dan **bertahan** saat Reload/refresh dengan parameter sama. e2e ED-1150 (`run-sam.sh`) **tidak dijalankan** (Tambahan Gate 1) | PASS | `work/e2e/ED-1125-archive-fe-cari-modal-pindah-20261024-181914`, `work/e2e/ED-1069-archive-validasi-pindah-rename-folder-20261024-182601`; `ac22`/`ac18` test di `...184015` |

Penambahan di luar AC (e2e "regresi"): Cari kosong di folder tanpa filter aktif tetap ke root (ED-1125 K-3 a); Show Related Transaction saja dan bersama
Verification Status tetap di folder (preseden); hapus nilai Verification Status saat Related Transaction aktif tetap di folder - PASS (`...184015`).

## Defect

Tidak ada.

## Pengamatan (bukan defect ED-1250)

- **P-1 (usang, dicatat sesuai AC-22)**: ED-1027 `scenario9` AC-22 FAIL. Diff diurai dari laporan JSON (`work/qa-http/ED-1027-20261025013309.json`): satu-satunya
  selisih antara pemegang Opname Document (field verifikasi dibuang) dan non-pemegang = list `result.queries`, pemegang punya entri `verificationStatus` di antara
  `type` dan `updatedAt` (BR-10/AC-1, memang diminta); baris, kolom, ringkasan, urutan identik. Pembanding AC-8 ED-1250 menggantikannya (lulus). Sama dengan O-3 QA BE.
- **P-2 (sudah ke batch review, dev)**: "Cari kosong" - dikonfirmasi di browser: tanpa filter aktif tetap ke root (ED-1125 K-3 a); dengan filter aktif hanya
  `verificationStatus` / `showRelatedTransaction` tetap di folder (BR-12). Efek samping: Show Related Transaction saja + Cari kosong dulu = root, kini tetap di folder.
- **P-3 (pra-ada)**: melepas kotak "Tampilkan Transaksi Terkait" lalu Cari mengirim `search={"showRelatedTransaction":false}` (`deleteEmptyRequest` tidak membuang false) -
  indikator filter tetap menyala. Bukan bagian item ini.
- **P-4 (tata letak)**: di layar desktop drawer Display Setting menampilkan "Kolom yang Ditampilkan" dan "Filter yang Ditampilkan" berdampingan (tab hanya di layar kecil);
  e2e menangani keduanya. Spec menyebut "tab Displayed Filters"; isi sama.
- **P-5 (data)**: root `api_sidomaju` kini 12 folder asli + ±3.533 dokumen root (hampir semua unverified; verified root asli 0). e2e memakai oracle SQL (`root-counts`)
  untuk `total`, bukan menelusuri 355 halaman. Daftar tes UI butir 3 ("ke-12 folder root") tetap benar; "root + Terverifikasi" akan menampilkan 12 folder tanpa dokumen.
- **P-6 (beban mesin)**: jest paralel gagal karena batas waktu saat beban 8-11 pada 4 core (php-fpm + `queue:work` Raptor) - lihat "Build dan unit test".

## Build dan unit test

| Langkah | Hasil | Bukti |
|---|---|---|
| `scripts/fe-build.sh build` | exit 0, tanpa peringatan | `work/ed1250fe/build-r1.out` |
| `yarn test src/containers/Archive` (paralel, beban tinggi) | 17 suite: 8 PASS, 9 gagal batas waktu (24 tes) | `work/ed1250fe/jest-archive.out` |
| ulang sendiri `--runInBand`: ArchivePage + ArchiveTable (suite ED-1250) | 2 suite, **65 / 65** PASS | `jest-rerun1.out` |
| ulang sendiri 7 suite Archive lain yang gagal | 7 suite, **97 / 97** PASS | `jest-rerun2.out` |
| full suite sekali (3 worker) | 85 suite: 62 PASS, 23 gagal (172 tes, batas waktu + 4 pra-ada) | `jest-full.out` |
| ulang sendiri suite gagal lainnya (13 path = 21 suite) | 17 PASS; **4 tetap gagal = pra-ada**: BatchOut 1 tes, production.function 2 tes (`getRecipeTotals`), InterbankTransferForm 1 tes, CustomerPurchaseOrderView gagal dimuat; sama dengan catatan QA ED-1150, tidak mengimpor berkas Archive | `jest-rerun3.out`; `features/ED-1150-.../qa-fe.md:74` |

Semua suite Archive (17) lulus bila dijalankan sendiri; kegagalan paralel = `Exceeded timeout of 5000 ms` / `findByText`, bukan perilaku.

## Galeri e2e & regresi BE

- ED-1250: `work/e2e/ED-1250-archive-filter-verification-status-20261024-184015/` (index.html, report.json, screenshots; 16 OK).
- ED-1125: `work/e2e/ED-1125-archive-fe-cari-modal-pindah-20261024-181914/` (18 OK). ED-1069: `work/e2e/ED-1069-archive-validasi-pindah-rename-folder-20261024-182601/` (5 OK).
- Ronde awal (skrip e2e masih diperbaiki, bukan defect aplikasi): `...174327` 5 OK / 10 GAGAL, `...180134`, `...180439`, `...180856`, `...183545`.
- BE (`work/ed1250fe/`): `be-reg-1250.out` PASS=16 SKIP=1 (laporan `work/qa-http/ED-1250-20261025013149.json`); `be-reg-1027.out` 12 PASS + 1 usang; `be-reg-1028.out` AC-28 PASS.
  Tanpa 500 dari request sah; satu-satunya 500 pra-ada (key `search` ber-titik, Bug ED-1262) bukan bagian item ini.

## Data uji, perubahan di luar baris sendiri, teardown

- Dibuat dan dibuang (`fixture.php up/down`, jurnal `.fixture-journal.json`, prefiks `QA50-`): 60 baris `archives` (+ dokumen, lokasi nihil, izin nihil) dan preset
  role `QA50-ROLE-E2E-NOOD` (+ `role_permissions`). Sapu sisa `QA50-` di awal tiap `up`. K-4 a: `is_verified`/`verified_at`/`verified_by` langsung, tanpa sesi opname,
  tanpa tulis `archive_opname*`.
- Diubah sementara dan dipulihkan persis: `user_roles` QA_USER2 (adi: role 5 -> preset -> role 5, dibandingkan JSON); `users.language` QA_USER (ID -> EN -> ID); baris cds
  `documentArchive` (AC-19/AC-20, snapshot lalu hapus+masukkan id sama: id 2260, `updated_at` 21:27:41 sama dengan awal); dokumen/folder fixture dipulihkan (`restore`).
  Dampak samping: penghitung auto-increment `roles`, `user_roles`, `column_display_settings` maju.
- **Tidak ada baris di luar milik sendiri yang dihapus atau di-reset; tidak ada tabel dikosongkan.** ED-1125/ED-1069 memakai fixture `0QA125-` / `0QA69E-` milik mereka (dibuang sendiri).
- Teardown C-8 (diperiksa lagi sesudah e2e terakhir): `run.php ED-1250 --only=C-8` PASS; cds memuat `verificationStatus` (current & available) dan `documentVerified`;
  `QA%` roles/archives = 0; user QA sama dengan awal; token QA aktif 0 (runner dan harness: log-out dicabut); tidak ada `.fixture-journal.json` / `.fixture-ids.json` basi.
  `updater_logs` 99 -> 99. Beda cds sebelum-vs-sesudah Updater = DU-5 a (diizinkan).

## Yang tidak bisa diverifikasi (dan kenapa)

1. AC-23 (letak/gaya popover EQUAL): penilaian visual manusia, tercantum di Daftar tes UI butir 1 (MANUAL Gate 2).
2. e2e ED-1150 (`run-sam.sh`, login `sam`) tidak dijalankan sesuai Tambahan Gate 1; regresi `listKey` dibuktikan unit + penanda DOM e2e sendiri.
3. Tanpa kontrol negatif berbasis build lama pada e2e (build HEAD terpisah tidak dibuat: 5 menit + pohon kerja bersih); bukti tes bermakna untuk unit sudah dari dev
   (8 dari 13 tes halaman/picker gagal dengan kode lama). Asersi e2e mencocokkan parameter request persis (mis. kode lama mengirim `idArchive: null` untuk `{verificationStatus}`).
4. Desain `brief/` untuk filter ini tidak ada (sumber `jira.md`); struktur/alur dicek, tampilan tidak dinilai.
5. Isolasi tenant: `QA_DBS` satu DB, bukan lingkup stage FE.

## Riwayat ronde

- Ronde 1 (2026-10-24): 11 PASS / 1 MANUAL / 0 FAIL; e2e 16 OK, ED-1125 18 OK, ED-1069 5 OK; unit modul Archive hijau (sendiri), 4 suite pra-ada tetap merah; regresi BE hijau (1 skenario lama usang);
  teardown C-8 PASS. Iterasi skrip e2e awal gagal karena kesalahan skrip (selektor label/breadcrumb, root 355 halaman, drawer dua panel, urutan key), bukan aplikasi.
