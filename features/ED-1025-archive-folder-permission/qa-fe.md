---
key: ED-1025
scope: fe
round: 1
verdict: PASS
counts: {pass: 4, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

QA FE **revisi ronde 3 (rev3)**, subtask ED-1133, ronde 1. Luas = keputusan Gate 1 K-17 a: build staging FE, unit Archive, **seluruh e2e ED-1025** (1 worker); **tanpa** suite BE dan tanpa e2e item lain. Diff yang diuji: FE task 022 (ED-1131: kolom Semua + satu baris per user) dan 023 (ED-1132: perisai di picker), 10 berkas M + 1 folder baru vs HEAD `41480e37c`; BE tanpa perubahan.

- **AC rev3: AC-39, AC-40, AC-41, AC-42 semuanya PASS.** Tidak ada defect kode FE, tidak ada respons 5xx, tidak ada error konsol/page/HTTP >= 400 di 21 test e2e akhir (403 `ARCHIVE407` yang diharapkan dikeluarkan eksplisit oleh test AC-19/20/34, tanpa allow-list).
- **Build**: `scripts/fe-build.sh build` exit 0, "Compiled successfully" (100 dtk) ke `/tmp/ksp-react-v5-build` (bukan `build/`); tidak ada berkas `src/` lebih baru dari build.
- **Unit Archive**: 12 suite, **405/405 lulus** (`--runInBand`, `work/qa-runs/ed1025-rev3-archive-test-inband.txt`). Run paralel default pada load mesin ~9 gagal 8 test (397/405), **semuanya timeout 5 dtk / pencarian teks saat beban**, bukan logika: ArchiveDrawer 3, ArchiveOpnameModal 3, ArchivePage 1, ArchivePermissionPage 1 (`...-archive-test.txt`); empat suite itu diulang `--runInBand`: 112/113 (sisa 1 = ArchivePage ED-1027, timeout pencarian teks); ArchivePage sendirian 39/39 dua kali (`...-archivepage-alone1/2.txt`); lalu seluruh Archive in-band 405/405. Tidak ada kegagalan pra-ada yang tersisa (test `ArchiveVerificationModal` "Tutup" milik ronde lalu kini lulus; test tooltip list yang dulu tidak stabil sudah dikuatkan developer, tidak gagal lagi).
- **E2E ED-1025**: **21 OK / 0 GAGAL** (19 flow: 15 `[default]` + 4 `[user2]`, + 2 smoke route). Galeri final: `work/e2e/ED-1025-archive-folder-permission-20261012-082117/index.html` (76 screenshot default + 17 user2). 4 flow baru: AC-39, AC-42, AC-40 id, AC-40 en.
- **Kontrol negatif** (bukti test baru menangkap bug): spec yang sama dijalankan terhadap build **HEAD bersih** (`git archive HEAD`, build ke `/tmp/ksp-react-head-build`, dipakai lewat `FE_STAGING_DIR`, sudah dihapus): **AC-39 GAGAL** (Save lewat Semua saja tidak mengirim PUT: `waitForResponse` timeout), **AC-40 GAGAL** (tanpa perisai di picker), **AC-42 LULUS** (wajar: spec menyebut ganda tidak terjadi di alur normal; e2e ini penjaga regresi, kasus huruf/spasi akhir dijaga unit test). Log `work/qa-runs/ed1025-rev3-e2e-negctl-head.txt`.
- **Data**: `archives`/`archive_documents`/`archive_locations`/`archive_permissions` kembali persis baseline setiap run (teardown fixture "count + CHECKSUM" OK; 49148/49126/48037/6; tabel opname 0). Sisa `QA01-R2-*` ronde lalu: **sudah tidak ada** (0 baris `name LIKE 'QA01%'` atau `created_by='QA01'`), tidak ada yang dihapus, tidak ada permintaan ke classifier. Token QA_USER/QA_USER2 aktif 0, `is_login` 0, role (3 dan 5) dan bahasa (ID, EN) pulih.

## Hasil per AC

Peran uji: A = role asli QA_USER (role 3) atau preset `QA25-ROLE-A`; C = A tanpa Folder Permissions; D = List Archive + View Folder + Folder Permissions; SUPER = role 1; B = QA_USER2 (preset C untuk tes "tanpa Folder Permissions", lihat F-1). Semua preset uji kini memuat `Opname Document` (tab Verification ED-1027 BR-18).

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-39 (rev3) | e2e A di `0QA25-W > 0QA25-SEM` (On, A V/U/D/S, B View saja, pembuat bukan A) + DB + unit | **PASS** | (b) hanya centang Semua B (indeterminate -> tercentang) + Save: **tepat 1 PUT** `archives/{F}` 200 `ARCHIVE207`, body B 1/1/1/1, A tetap 1/1/1/1; tanpa toast "Tidak ada perubahan untuk disimpan"; drawer menutup; DB `perm 0QA25-SEM` B = 1/1/1/1; buka ulang: baris B Semua tercentang, bukan indeterminate. (c) hanya lepas Semua B + Save: 1 PUT 200, body B 0/0/0/0, DB baris B **hilang** (A utuh), buka ulang 1 baris. (a) hanya lepas Semua A (bukan pembuat/superadmin) + Save: modal "Sesudah disimpan, Anda tidak lagi bisa melihat folder ini. Lanjutkan?", **Tidak = 0 PUT (monitor `request`)**, drawer tetap, Semua A tetap lepas, DB utuh. (d) kontrol: Save tanpa perubahan = toast "Tidak ada perubahan untuk disimpan", 0 PUT (perilaku lama tetap). Test `AC-39 A` (spec `:1363`) OK 32 dtk; screenshot AC39-01..05. Unit: `ArchiveDrawer/index.test.js` "kolom All tercatat sebagai perubahan (BR-23)" 3 test + "hanya lepas All baris sendiri ... modal" lulus; `toFolderPermissionRowFields` 4 test |
| AC-40 (rev3) | e2e A (id dan en) + unit | **PASS** (tampilan visual = MANUAL Gate 2, lihat bawah) | Test `AC-40 A (id)` dan `(en)` (spec `:1583` alur, `:1818`, `:1822`) OK 38/36 dtk. List: perisai P/DENY/DENYA/STORE, tanpa pada MOVE; **klik ikon P di list = membuka P**. **Modal Pindahkan (⋯ dokumen `P-DOC`)**: root: P, DENY, DENYA (A tanpa View dan Store, radio disabled), STORE berperisai; MOVE, Z, W tanpa; di dalam P: `P-C` (Off) dan `P-DOC` (dokumen) tanpa; di dalam DENY: `DENY-C` berperisai; di dalam wadah Z: J, JV, DP (A View saja, tanpa Store), ALL berperisai, CED tanpa. **Hasil pencarian** picker (respons BE asli; Search di dalam modal tidak bisa dipakai = bug pra-ada ED-1125, dialihkan lewat pengalih jaringan pola QA ED-1069): `DENY`, `DENY-C`, `DENYA` berperisai (DENYA tetap tidak bisa dipilih), `P` berperisai, `P-C`/`P-DOC` tanpa. **Pindah massal**: Konfirmasi (MOVE + P): P berperisai, MOVE tidak, hover = tooltip; Pilih Folder: P (ikut dipindah, terkunci), DENY, DENYA, STORE berperisai; MOVE, Z tanpa. **Tooltip** hover = "Akses Terbatas" (id) / "Restricted Access" (en) di semua picker/langkah (teks diasersi). **Klik ikon = klik nama**: ikon P/STORE membuka folder (GET `archives?id_archive` 200, breadcrumb bertambah), ikon DENYA (tanpa View) dan ikon P terkunci = tanpa request apa pun. AC-21 tetap (e2e AC-21 B OK). **Tanpa request tambahan**: 11 request selama picker/pindah massal, **semuanya `GET document-archive/archives`** (catatan monitor). Tanpa error konsol/toast, tidak ada yang dipindah (DB `MOVE`, `P-DOC` utuh). Unit: `ArchiveMoveModal/index.test.js` (2 test AC-40), `ArchiveRestrictedIcon/index.test.js`, `ArchivePage/index.test.js` (list tetap berikon, AC-20) lulus |
| AC-41 (rev3) | perbaikan oracle + e2e A, C + unit | **PASS** | `qa/e2e/ED-1025-folder-permission.spec.js:127-128` `expectedPlus`: `set-permission` kini `has('Folder Permissions')` (dulu `Update Folder`, `:124`); penelepon: `ac3Flow` (AC-3 A dan C, `:336`) dan AC-35 (`:944`, `:974`), semuanya lewat fungsi yang sama. Asersi eksplisit baru `:338-340` (di `ac3Flow`): "+" memuat Set Permission by User/Atur Izin per User = ber-Folder Permissions. Bukti run: A (role asli, ber-FP) "+" = Tambah Folder, Serahkan, Terima, Letakkan Dokumen, Opname Dokumen, **Atur Izin per User**; C (ber-`Update Folder`, tanpa FP) = Tambah Folder, Serahkan, Terima, Letakkan Dokumen, Opname Dokumen, **tanpa** Atur Izin. Seluruh e2e ED-1025 21/21, unit Archive 405/405, tanpa 500 baru; `qa/scenario5-input-types.php:358` (X-12) tidak disentuh dan tidak dijalankan (ED-1071) |
| AC-42 (rev3) | e2e A di `0QA25-W > 0QA25-F42` (On, pembuat A, tanpa baris A, B View saja) + DB + unit | **PASS** | "+ Add User" **menawarkan A** (excepts hanya B) -> baris A View saja (Semua indeterminate); toggle Off (baris tak berubah) lalu On: **tetap 2 baris, tepat satu baris A**, naik ke Semua + V/U/D/S, B tak berubah; "+ Add User" lagi: excepts memuat A dan B, opsi **tanpa A**; Save: tanpa modal (pembuat), 1 PUT 200 `ARCHIVE207`, body **2 baris, A sekali 1/1/1/1**, B View saja; DB `perm 0QA25-F42` = A 1/1/1/1 + B 1/0/0/0, tepat satu baris A; buka ulang 2 baris. Test `AC-42 A` (spec `:1474`) OK. Unit: `ArchiveDrawer` 5 test BR-25 ("+ Add User" user sudah ada beda huruf/spasi akhir, Off->On dengan baris sendiri beda huruf, `willLoseOwnFolderAccess`), `archive.function.test.js` `isSameFolderPermissionUser` 6 test + `grantOwnFolderPermission` beda huruf/spasi akhir. Penolakan BE ganda (AC-5, 422) tidak diuji (BE tidak berubah, K-17 a) |

**Regresi e2e ED-1025 (seluruh suite, 21/21 OK)** menutup ulang AC-3 (A, C), AC-17/31/32, AC-18 (D, B), AC-19, AC-20, AC-21, AC-30 (A, C), AC-33 (A, SUPER), AC-34 (id, en), AC-35, dua smoke route. **MANUAL (Gate 2)**: tampilan visual perisai di picker/tab Izin/kolom Semua terhadap EQUAL; butir ada di `## Daftar tes UI` task 022 (butir 1-11) dan 023 (butir 1-10), jadi tidak ada butir MANUAL yang hilang dari daftar.

Cek desain (`brief/`, `knowledge/design-sources.md`): tidak ada layar/kolom baru dari desain di ronde ini; perisai di picker dan perilaku Semua berasal dari review developer. Tampilan tidak dinilai.

## Defect

Tidak ada defect kode produk (FE maupun BE): fail = 0.

## Temuan non-blokir

- **F-1 (data bersama berubah di luar prefiks QA, dicatat, tidak diubah; pemilik developer)**: sejak ronde lalu `role_permissions` di `api_sidomaju` berubah: **role 3 dan role 5** memegang `Folder Permissions` (id 1140), **role 5** (QA_USER2, "Sales Supervisor") kini memegang seluruh permission Archive (List/Add/View/Update/Delete Folder, Move, Handover, Receive, Add Document, `Opname Document` 1139 + 1140), sebagian besar baris dengan kolom `permission_name` **NULL** (hasil centang lewat UI role); hanya role 1 dan 2 berbaris bernama. Pada ronde lalu role 5 tanpa Folder Permissions dan role 3 memegangnya berbaris bernama. Akibat bagi QA: (1) fixture e2e membaca daftar permission role asli dari kolom `permission_name`, sehingga role 3 terbaca "tanpa Folder Permissions" dan 7 test gagal (hasil run 1) - **diperbaiki di alat uji**: `fixture.php` `permissionNames` kini join ke `permissions` lewat `id_permission`; (2) test B yang memakai role asli (AC-18/20/30 B, AC-19/20 B, AC-21 B) mengasumsikan B tanpa Folder Permissions - kini memakai preset `C` (role uji tanpa FP) supaya tidak bergantung pada role developer. Role/permission developer **tidak diubah**. Bukti QA bersih: tidak ada `0QA25-%`/`QA25-ROLE-%` tersisa, tabel archive* = baseline.
- **F-2 (oracle usang milik item ini, diperbarui di folder ini)**: (a) `expectedPlus` (AC-41); (b) jumlah tab: sejak ED-1027 (BR-18, FB-8b) tab Verification hanya untuk pemegang `Opname Document`, preset A/C/D tidak memuatnya sehingga drawer C tanpa tab sama sekali (AC-3 C, AC-30 C gagal "tab 2 vs 0") - **preset A/C/D kini memuat `Opname Document`** (oracle Detail [+ Izin] + Verification tetap benar, fixture header dicatat); (c) AC-40 pertama dibuat memakai `0QA25-EDIT` sebagai folder Off, tetapi AC-17 menyalakan Izin-nya lebih dulu di run penuh - diganti MOVE/Z/Y/W; (d) AC-42 membaca opsi sebelum pencarian ber-debounce selesai (run penuh 2 gagal "opsi tanpa A diharapkan menawarkan A") - kini menunggu respons pencarian bertekstual penuh. Semua di alat uji, bukan di produk.
- **F-3 (bug FE pra-ada, sudah dilaporkan, tidak diajukan ulang)**: Search di dalam modal Pindahkan/pindah massal memuat ulang aplikasi (form bersarang) = ED-1125; hasil pencarian picker diuji lewat pengalih jaringan (respons BE asli).
- **F-4 (alat QA, rendah)**: `app.screenshot` memakai `fullPage` sehingga tooltip hover tidak tampak di screenshot galeri (AC40-03/03b/08/09b); tooltip dibuktikan lewat teks (`.ant-tooltip` = "Akses Terbatas"/"Restricted Access"), bukan gambar. Pola sama dengan AC34-01 ronde lalu.
- **F-5 (beban mesin)**: unit paralel default timeout pada load ~9 (lihat Ringkasan); hijau in-band. Bukan defect item ini.
- **Dari ronde lalu, tidak berubah**: F-1 lama (butir MANUAL AC-23 tukar lisensi sungguhan tidak ada di `## Daftar tes UI` task mana pun); C-2/C-3 (ED-1029); C-4 pra-ada.

## Galeri e2e & regresi BE

- E2E final **21/21 OK**: `work/e2e/ED-1025-archive-folder-permission-20261012-082117/index.html` (+ `report.json`, `screenshots/default` 76, `screenshots/user2` 17). Perintah: `scripts/e2e/run.sh ED-1025 --profile default,user2 --workers 1`. Log `work/qa-runs/ed1025-rev3-e2e-full3.txt`.
- Riwayat run e2e rev3: test baru sendiri `...-e2e-new1.txt` (AC-39, AC-42: 2 OK), `-new2` (AC-40: 2 GAGAL, alat uji: baris Konfirmasi tersembunyi ikut terpilih), `-new3` (AC-40: 2 OK); kontrol negatif HEAD `...-e2e-negctl-head.txt`; `-full1` (7 GAGAL: F-1/F-2 + EDIT), `-full2` (1 GAGAL: AC-42 timing), `-full3` (21 OK).
- Build: `work/qa-runs/ed1025-rev3-fe-build.txt`, `work/fe-build.log`; build HEAD: `...-head-build.txt`. Unit: `...-archive-test.txt` (paralel), `...-archive-test-inband.txt` (hijau), `...-archive-rerun-inband.txt`, `...-archivepage-alone1/2.txt`.
- **Regresi BE: tidak dijalankan** (K-17 a: BE tanpa perubahan, X-12 milik ED-1071 tidak disentuh).

## Skenario/e2e usang (hanya dicatat, item lain tidak diedit)

| Pemilik | file:baris | Sebab |
|---|---|---|
| ED-1025 BE (item ini, tidak dijalankan) | `qa/scenario9-rev-seed-fuzz.php:168`, `:172` | memeriksa `role_permissions.permission_name = 'Folder Permissions'` untuk role 3; DB sekarang role 3 berbaris `permission_name` NULL (F-1) -> diperkirakan FAIL bila dijalankan; bukan cacat kode (BE tidak berubah). Tidak diedit karena BE di luar K-17 a |
| ED-1029 | `qa/e2e/ED-1029-user-permission.spec.js:364/:370`, `:499`, `:654` | usang sejak ronde lalu (catatan qa-fe lama), tidak dijalankan |
| ED-1027, ED-1028, ED-1029 | profil `[user2]` | mengasumsikan role 5 (QA_USER2) minim permission; role 5 kini memegang Folder Permissions + Opname Document (F-1); mungkin usang, tidak dijalankan |

## Yang tidak bisa diverifikasi (dan kenapa)

- Tampilan visual perisai/tab Izin vs EQUAL: MANUAL Gate 2 (task 022/023 `## Daftar tes UI`).
- Tukar lisensi sungguhan (AC-23): `PROFILES` kosong, MANUAL Gate 2 epic (tidak berubah).
- Suite BE ED-1025, e2e ED-1024/1026/1027/1028/1029: di luar K-17 a, tidak dijalankan.
- Search di dalam picker lewat UI: terhalang ED-1125 (pra-ada); diuji lewat pengalih jaringan.
- Penolakan: **tidak ada** penolakan izin/classifier pada ronde ini.

## Data yang dihapus

- Milik QA, otomatis oleh fixture tiap run (teardown OK, count + CHECKSUM = baseline): 30 baris `archives` berawalan `0QA25-` (+ `archive_documents`, `archive_permissions` turunannya) dan 3 role `QA25-ROLE-A/C/D` beserta `role_permissions`/`user_roles`-nya. Sisa `QA01-R2-*`: tidak ada (sudah hilang sebelum ronde ini).
- Baris milik developer/pihak lain: **tidak ada yang dihapus atau diubah**. Berkas sementara saya sendiri (salinan `git archive HEAD` dan build HEAD di `/tmp`) sudah dihapus; `build/` dan working tree FE tidak disentuh (10 berkas M + 3 berkas baru sama dengan `changed.txt`).
- Perubahan alat uji di folder ini: `qa/e2e/ED-1025-folder-permission.spec.js` (+491 baris: AC-39, AC-40 x2, AC-42, oracle AC-41, B pakai preset C), `qa/e2e/fixture.php` (wadah `W` + `SEM` + `F42`, `Opname Document` di preset, `permissionNames` via id), `qa/e2e/fixtures.json` (alasan).

## Riwayat ronde

- Run asli ronde 1 (2026-10-07): build OK; unit Archive 62/62; e2e 7/7; regresi BE 31/31 + 26/26; verdict PASS.
- Ronde 1 run revisi rev2 (2026-10-08, ED-1087): build OK; unit 316/318 (pra-ada + flake); e2e 17/17 (alat uji diperbaiki dulu); BE 1025 40/40; verdict PASS dengan insiden penghapusan data opname developer oleh fixture ED-1026/27/28 (D-1, bukan item ini).
- **Ronde 1 run rev3 (ini, ED-1133)**: build OK; unit Archive 405/405 in-band (paralel 8 timeout karena beban); e2e ED-1025 21/21 (4 flow baru, kontrol negatif HEAD: AC-39 dan AC-40 gagal, AC-42 lulus); AC-39..42 PASS, 1 MANUAL (visual); tanpa suite BE (K-17 a); verdict PASS.
