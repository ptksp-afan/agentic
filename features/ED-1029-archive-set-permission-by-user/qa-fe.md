---
key: ED-1029
scope: fe
round: 1
verdict: PASS
counts: {pass: 13, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

Ronde 1 dari **run revisi** (review batch ED-1022: FB-4b, FB-9, FB-5; subtask FE ED-1104, QA ED-1105; diff FE 10 M + 1 baru di atas HEAD e5355a9c6, task
`docs/specs/Archive/015-enh-archive-permission-page-rev.md`). Dari 14 AC milik stage ini (`[FE]` 1, 2, 3, 14-19, 24, 26 dan `[BE+FE]` 20, 25, 28):
**13 PASS, 0 FAIL, 1 MANUAL** (AC-20: tukar lisensi sungguhan, Gate 2 epic). **Tanpa defect baru.** Tanpa 5xx dari alur item ini (e2e: 0; 16 x 500
di regresi BE hanya probe karakterisasi X-1 = ED-1071, sengaja, ditunda Gate 1).

- Build staging PASS (`Compiled successfully`, 65,6 dtk; teks BR-9 baru ada di bundel). Jest modul Archive 11 suite / 325 test: 323 hijau, 2 gagal, keduanya bukan dari diff
  (lihat Galeri). Suite penuh 79 suite / 1646 test: 9 gagal / 7 suite; sesudah diulang `--runInBand` yang tersisa hanya 4 baseline lama + 1 test usang ED-1027.
- E2E ED-1029 (diperbarui di folder sendiri): **22 OK, 0 GAGAL** (smoke 4 + flow 18) pada run akhir. Run pertama revisi (`...-013630`): 14 OK / 7 GAGAL, semuanya kesalahan
  spek/fixture QA (bukan FE), sudah diperbaiki, lihat "Berkas QA yang diubah".
- Regresi BE: 6 berkas `qa/scenario*.php` ED-1029 **29 PASS, 1 SKIP (X-ISO), 0 FAIL** (`work/qa29f-rev-be-reg-ED-1029.txt`), 0 x 5xx di X-5/6/7 (712 request paralel).
- Regresi e2e FE Archive item lain (berkasnya tidak diubah): 16 + 7 + 7 + 11 OK; yang gagal semuanya skenario usang/data milik item itu (dicatat di bawah).
- Data uji dipulihkan persis: hitungan + CHECKSUM 9 tabel (`archives`, `archive_documents`, `archive_locations`, `archive_permissions`, `user_roles`, `employees`,
  `employee_locations`, `roles`, `role_permissions`) sama dengan sebelum run; 0 baris `0QA29-`/`QA29F-`. Tidak ada data lain yang dihapus. Penolakan izin/classifier: tidak ada.
- Kode aplikasi, spec, kontrak tidak diubah QA; yang diubah hanya `qa/e2e/*` ED-1029.

## Hasil per AC

Pelaku e2e: A = QA_USER (role 3 = Update Folder + Folder Permissions; employee dibatasi ke lokasi MGL sementara; bahasa ID). QA_USER2 (EN) bergantian sebagai
E (role 18, tanpa Update Folder dan tanpa Folder Permissions), **M** (role sementara `QA29F-ROLE-M` = List Archive + View Folder + Folder Permissions) dan **D**
(`QA29F-ROLE-D` = semua permission role A kecuali Folder Permissions, jadi masih Update Folder). Peran dibaca FE dari `auth/info`: tiap tukar diikuti muat ulang penuh, dipulihkan di `finally`. Folder uji `0QA29-`: P On (A penuh) > C1 On, C2 Off; Q On (A View saja); R Off > R1 On; L di luar lokasi A; J On (A View saja) > JA (A penuh), JB (tanpa baris A),
JC (Off); F Off tanpa baris. B = `admintest`, B2 = `dias`. Run akhir: `work/e2e/ED-1029-archive-set-permission-by-user-20261009-020703/` (selanjutnya "run akhir").

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 (rev) | unit `getArchiveAddKeys` + `ArchivePage` (jest Archive hijau, `describe` "Set Permission by User", profil A/M/D/E); e2e A (root, P), M (root, P), D (root, P), E (root, R) | PASS | A root: Tambah Folder, Serahkan, Terima, Opname Dokumen, **Atur Izin per User** (terakhir); di P 6 item, terakhir sama. **M** (tanpa Update Folder): "+" di root dan di P = `Set Permission by User` (satu-satunya item). **D** (ber-Update Folder, tanpa Folder Permissions): root = Add Folder / Hand Over / Receive / Opname, di P = Hand Over / Receive / Opname, tanpa item itu. E: root = Hand Over / Receive, di R + Store Document, tanpa item itu. Tes "AC-1 + AC-2 + AC-14 A", "M", "D", "AC-1 E"; screenshot `AC1-01/02/05/06/07/08`, `AC1-03/04` |
| AC-2 | e2e A: lewat "+" dari dalam P, lewat URL, sesudah F5 (tidak berubah, regresi) | PASS | Halaman "Izin Arsip", menu Arsip aktif; sebelum pilih user 0 request `user-permissions`, tabel 0 baris + "Pilih user untuk melihat izin foldernya", Reset/Simpan disabled; `select/document-archive/archive/users` dipanggil tanpa `excepts`; F5 -> tabel kosong lagi. Tes "AC-1 + AC-2 + AC-14 A", "AC-2 A" + smoke A (OK) |
| AC-3 (rev) | e2e A (URL langsung, 2 x F5, lewat "+"), M (lewat "+", URL, 2 x F5), D, E; unit guard A/M/D/E (`ArchivePermissionPage` "guard lokal Folder Permissions"); smoke E `expectRedirect=/unathorized` + `forbidApi` | PASS | **D** dan **E**: riwayat path tab aktif `["/archives/permissions","/unathorized"]`, `.ant-result-403` ter-render (screenshot `user2/AC3-03-D-buka-URL.png`: "403 You are not authorized to access this page."), kotak Search folder tidak pernah ter-render, 0 request select user, 0 request `user-permissions`. **M** (tanpa Update Folder): paths hanya `/archives` <-> `/archives/permissions`, `unauthorized=false`, `search=true`, tanpa `.ant-result-403`, 2 x F5 tetap di halaman. **A**: sama (`AC3A-01/02`). Detektor terbukti hidup (D/E: `unauthorized=true`; A/M: `search=true`) |
| AC-14 (rev) | e2e A: pilih B; semua baris DOM dibandingkan dengan GET `user-permissions/{B}`; asersi per folder uji. Tampilan = MANUAL | PASS (fungsi); tampilan MANUAL | 29 baris cocok DFS response (urutan, level, tag On/Off, nilai tersimpan, disabled = bukan(On dan `manage_permission`), ikon buka-tutup, semua terbuka). **P, C1, Q, R1 aktif** (Q: A hanya View = `access` view true, update/delete/store false, `manage_permission` true; nilai tersimpan B View+Update terlihat), **hanya C2 dan R (Off) terkunci** dengan nilai tersimpan C2 View+Store; L tidak ada. Sama untuk M (juga J, JA, JB aktif; M tanpa View di JB). Info BR-9 id tampil. Struktur sesuai `design-03.png`. Tampilan: `## Daftar tes UI` task 015 butir 7, task 012 butir 3-9 |
| AC-15 | unit `changeUserPermission` + e2e A (C1, P, Q) | PASS | Update -> View ikut; Delete -> V,U,D; Store -> keempatnya; lepas Update -> View tetap; lepas View -> keempatnya lepas, Reset/Simpan disabled lagi. **Rev:** Q (A View saja) bisa dicentang (Delete -> V,U,D; lepas View -> semua lepas; kembali); sel terkunci tersisa hanya C2 dan R (disabled, klik paksa tidak mengubah). Hanya 1 request BE (GET user B) |
| AC-16 | unit `filterUserPermissionTree` + e2e A | PASS | "c1" -> tepat P dan C1; "0qa29-P-c" -> P, C1, C2; "BRANGKAS" sama dengan hitungan dari response; tanpa hasil -> 0 baris; perubahan di P dan C1 tetap selama disaring dan sesudah dikosongkan; pencarian tanpa request |
| AC-17 | e2e A: ubah P, C1 (R1 diubah lalu dikembalikan), Save | PASS | `PUT .../user-permissions/{B}` body persis 2 folder (P isView 1 isStore 1; C1 isView 1; integer); 200 `ARCHIVE207`; toast sukses; urutan GET, PUT, GET; DB: P=1001, C1=1000, C2/Q/J-JC utuh, B2 tanpa baris; riwayat `permission` P +1, C1 +1 oleh `imansudjadi`, folder lain 0. BR-16: `GET archives/{P}` dan tab Izin drawer P memuat B View+Store (**pembetulan QA:** tab Izin kini punya kolom All, asersi membaca 4 centang terakhir). Hapus baris P: body keempat flag 0, baris terhapus, riwayat +1 |
| AC-18 | e2e A: Reset; ganti User dengan perubahan (Tidak, Ya); tanpa perubahan | PASS | Reset: kembali ke tersimpan, 0 request, tanpa konfirmasi. Ganti ke B2 saat dirty: konfirmasi memuat nama B (Tidak/Ya). Tidak: tetap B dengan centang dan Simpan hidup, 0 request B2. Ya: `GET user-permissions/{B2}` 200, perubahan B hilang. Tanpa perubahan: ganti langsung. 0 PUT, DB hak B = nilai awal |
| AC-19 | e2e A: centang C1 View + P Store; `fixture.php flag P-C1 0` sebelum Simpan | PASS | PUT 400 `ARCHIVE441`; toast error memuat "0QA29-P-C1" + "belum aktif" (ikon silang); centang di layar tidak berubah, Simpan/Reset hidup, tanpa GET ulang; DB tidak ada yang tersimpan (atomik), riwayat P 0. 400 + log AxiosError dikeluarkan dari monitor eksplisit |
| AC-20 (rev) | `[BE+FE]` cara seragam EPIC K-2 b: BE = regresi `scenario3-access` AC-20 + X-PERM; FE = kode + grep; tukar lisensi sungguhan = manual | MANUAL (Gate 2 epic); bagian BE dan kode PASS | BE ronde ini: AC-20 PASS (37 asersi), X-PERM PASS (189; role ber-FP 1/2/3 = 200, 9 role tanpa FP termasuk 5 dan 26 yang ber-Update Folder = 403 `GE0114` "Folder Permissions"). FE: `src/routes/routes.js:2101-2103` `permission` dan `PrivateRoute` = `permissions.FolderPermissions`; `menus.js` tidak berubah (menu Archive ikut lisensi Salesman Activity); `grep` id permission literal (1085/1133/1139/1140...) di `src/containers/Archive` (non-test) dan di semua `qa/*` ED-1029 = 0 temuan. Tukar lisensi sungguhan: `PROFILES` kosong; task 012 butir 23 (tidak berubah, masih berlaku) |
| AC-24 (rev) | e2e A (data J) + M (en); teks persis | PASS (teks MANUAL tetap di daftar) | A: J, JA, JB aktif (JB: `access.view` false, `manage_permission` true), JC (Off) terkunci dengan nilai tersimpan View+Store; centang View di JB + Simpan: body `PUT` hanya JB `{isView:1,...}`, 200, toast sukses, DB (JB,B)=1000, riwayat JB +1 oleh A, JC utuh. Info id persis "...Folder Off tidak bisa dicentang dan tidak membatasi akses. Hanya izin Lihat di folder induk yang berlaku: ... Ubah, Hapus, dan Letakkan dinilai di folder masing-masing." tanpa "dominan"/"induk On terdekat"; info en (M) persis teks BR-9 tanpa "dominant"/"nearest On parent". Penilaian bahasa/tampilan: task 015 butir 1-2 |
| AC-25 (rev) | `[BE+FE]` e2e A: tab Izin F -> halaman by User -> tab Izin; BE (http) = `scenario6-rev` AC-25 | PASS | F (Off, tanpa baris A): toggle Izin menyala di tab Izin = baris A otomatis V/U/D/S sebelum Save; Save `PUT archives/{F}` 200; DB F On, (F,A)=1111. Halaman by User, pilih A: GET F = On 1/1/1/1, DOM F tag On, V/U/D/S tercentang dan aktif. Lepas Delete + Simpan: body hanya F `[1,1,0,1]`, 200, DB 1101; tab Izin F menampilkan A View/Update/Store tanpa Delete (kolom All indeterminate, `AC25-03`). BE: AC-25 PASS 21 asersi |
| AC-26 (rev) | e2e A: User = diri sendiri, lepas View di JA, Save | PASS | `GET user-permissions/{A}` 200; JA 1111 aktif; lepas View -> 0000; Simpan: **tanpa `.ant-modal-confirm` dan tanpa modal apa pun**, `PUT .../user-permissions/{A}` langsung 200 body hanya JA `[0,0,0,0]`, toast sukses; sesudah GET ulang JA kosong dan **tetap aktif** (bisa dicentang lagi). DB: (JA,A) terhapus, riwayat JA +1 oleh A, baris A lain dan hak B utuh. Dipulihkan lewat halaman yang sama (juga tanpa modal) lalu `resetperm` |
| AC-28 (rev) | `[BE+FE]` skenario/e2e usang milik sendiri diperbarui; semua skenario BE dan e2e dijalankan ulang | PASS | Lihat "Berkas QA yang diubah". Regresi BE: 29 PASS + 1 SKIP, X-1 PASS sebagai karakterisasi (28 kasus: 12 x 404, 16 x 500, ED-1071), X-5/6/7 0 x 5xx. E2E 22 OK / 0 GAGAL. Item lain: dicatat di "Skenario usang milik item lain" |

Tambahan di luar AC (PASS di run akhir): jalur error M (task 015 butir 10): Folder Permissions dicabut sesudah halaman dibuka -> Save `403 GE0114`, toast "[403] You don't have permission to Folder Permissions!", centang dan Simpan tetap,
DB utuh, muat ulang -> Unauthorized tanpa request; BR-4 buka-tutup; BR-15 A dengan role 1 sementara; M menyimpan di folder tanpa View (JB) -> 200, riwayat atas nama M.

## Defect

Tidak ada defect baru (FE maupun BE).

Pengamatan (bukan defect, tidak menggagalkan AC):
- P-1 Profil M "List Archive + View Folder + Folder Permissions saja" tidak bisa dipakai login web oleh user yang `employee_type` bukan 0 (QA_USER2 = 1): `PermissionVersion5::allowAccessWebsite` mencabut token dan 403 bila tanpa `Allow Access Website`
  (perilaku lama, di luar item). Pada run pertama itu menjatuhkan sesi QA_USER2 (cascade 401); role fixture M kini menyertakan `Allow Access Website` sebagai syarat teknis login, bukan permission fitur.
- P-2 "+" untuk M hanya satu item, jadi "paling akhir" dibuktikan pada A (5 dan 6 item).
- P-3 Pada screenshot `fullPage`, tajuk kolom tabel tampak terpisah dari baris (sticky header, mis. `user2/AC14-02`: celah kosong di bawah info); penilaian tampilan, sudah di daftar tes UI Gate 2 (task 012 butir 3-9, 015 butir 7-8).
- P-4 Dua test jest ArchivePage (tooltip perisai; "+ ber-Folder Permissions") timeout di bawah beban suite penuh, hijau saat diulang sendiri (24/24); usulan FE dev (timeout lebih longgar) tetap relevan.

## Galeri e2e & regresi BE

**Build dan test**

| Langkah | Hasil | Bukti |
|---|---|---|
| `scripts/fe-build.sh build` | PASS | `work/qa29f-rev-build.txt`, `work/fe-build.log`: "Compiled successfully.", exit 0, 65,6 dtk; teks BR-9 baru (`Only View on parent folders applies`) ada di `main.aa1fe99b.js` |
| `yarn test --watchAll=false src/containers/Archive` | 323 / 325 hijau | `work/qa29f-rev-jest-archive.txt`. Gagal: `ArchiveVerificationModal` (1) dan `ArchivePage` tooltip (1). Diulang `--runInBand` (`work/qa29f-rev-jest-rerun.txt`): ArchivePage 24/24 hijau; ArchiveVerificationModal tetap gagal, **bukan dari diff**: tombol footer "Tutup" dihapus commit developer `7fa63869d` (ED-1022 UI/UX, modal tanpa footer), berkas modal dan test tidak ada di diff |
| suite penuh `yarn test --watchAll=false` | 79 suite / 1646 test; 7 suite / 9 test gagal | `work/qa29f-rev-jest-full.txt`. Diulang `--runInBand` (`work/qa29f-rev-jest-rerun2.txt`): RaptorPendingView dan ArchivePage hijau (timeout beban). Tersisa: baseline lama (production.function 2 test, InterbankTransferForm 1, Item/BatchOut 1, CustomerPurchaseOrderView suite gagal jalan `electronEvent`) + ArchiveVerificationModal (di atas); tidak satu pun menyentuh berkas diff |

**E2E ED-1029** (`E2E_WORKERS=1 scripts/e2e/run.sh ED-1029 --profile default,user2`): **22 OK, 0 GAGAL, 0 SKIP**. Galeri: `work/e2e/ED-1029-archive-set-permission-by-user-20261009-020703/` (`index.html`, `report.json`, `screenshots/default|user2/`),
keluaran `work/qa29f-rev-e2e-3.txt`. Run sebelumnya: `...-014325` (21 OK, tanpa tes jalur error M); `...-013630` (14 OK / 7 GAGAL, kesalahan QA, tidak berlaku).

| Tes | Profil | Hasil |
|---|---|---|
| AC-1 + AC-2 + AC-14 A (rev) | default | OK |
| AC-2 A (URL + F5) | default | OK |
| AC-3 A (URL, 2 x F5, lewat "+"; tanpa kilasan Unauthorized) | default | OK |
| AC-15, AC-16, AC-17, AC-18, AC-19 | default | OK x5 |
| BR-4 buka-tutup; BR-15 superadmin | default | OK x2 |
| **AC-14 + AC-24 A (data J)**; **AC-26 A (diri sendiri)**; **AC-25 A (tab Izin -> halaman -> tab Izin)** - baru revisi | default | OK x3 |
| smoke `/archives/permissions` A, `/archives` A | default | OK x2 |
| **AC-1 + AC-3 + AC-14 M** (Folder Permissions tanpa Update Folder; info EN; Save oleh M) - baru | user2 | OK |
| **jalur error M** (Folder Permissions dicabut; 403 `GE0114`; reload -> Unauthorized) - baru | user2 | OK |
| **AC-1 + AC-3 D** (Update Folder tanpa Folder Permissions) - baru | user2 | OK |
| AC-1 E; AC-3 E (dirombak memakai `expectPageDenied`) | user2 | OK x2 |
| smoke `/archives/permissions` E (`expectRedirect`, `forbidApi`), `/archives` E | user2 | OK x2 |

**Berkas QA yang diubah (milik item ini, gate1 §5, AC-28):**
- `qa/e2e/ED-1029-user-permission.spec.js`: komentar/profil (dulu `:10`), pesan "PrivateRoute permission Update Folder" (dulu `:913`) dan komentar E "tanpa Update Folder" (dulu `:872`) diperbarui ke Folder Permissions; AC-14 (Q kini aktif, tidak lagi terkunci), AC-15 (daftar sel terkunci C2/R + Q bisa diubah), teks info BR-9 id, `B0` (nilai awal B + J-JC) di AC-17/18/19, kolom All di tab Izin (AC-17); AC-3 E dirombak ke pembantu `expectPageDenied` (blok "karakterisasi bila halaman tetap tampil" dibuang, tidak terjangkau karena guard lokal sudah terbukti); tes baru AC-14+AC-24 (data J), AC-26, AC-25, M, jalur error M, D; pembantu `asProfile`, `plusItemsOrNone`, `enterFolder`, log isi respons >= 400.
- `qa/e2e/fixture.php`: data J (J/JA/JB/JC) + F, baris View QA_USER2 di P, `resetperm` membuang semua baris folder uji lalu memasang nilai awal A/B/E, perintah `uprofile D|M|E` (role sementara `QA29F-ROLE-*` disusun lewat `id_permission`: `role_permissions.permission_name` di baris lama kosong), `roles`/`role_permissions` ikut baseline CHECKSUM dan dibuang `down`, dokumentasi pelaku (dulu `:18`).
- `qa/e2e/routes.json` (nama entri A/E, dulu `:4-5`) dan `fixtures.json` (alasan). Skenario BE usang sudah diperbarui QA BE (`qa-be.md`).

**Regresi BE** (`"$PHP_BIN" scripts/qa-http/run.php ED-1029`, server fpm, tanpa reload; `work/qa29f-rev-be-reg-ED-1029.txt`, JSON `work/qa-http/ED-1029-20261009084923.json`, 2 mnt 3 dtk): `PASS=29 FAIL=0 SKIP=1 BLOCKED=0`. Skenario: AC-4..13b, X-PERM, AC-20 (hijau),
X-ISO SKIP (`QA_DBS` satu DB), X-1 karakterisasi hijau, X-2 (193 folder GET 0,23 s, PUT 160 baris 0,40 s), X-3, X-4, X-5/6/7 (210 + 172 + 330 request, 0 x 5xx), AC-21, AC-21b, AC-22, AC-23, AC-25, AC-26-BE, AC-27. Token: 3 dibuat, 0 gagal log-out, masih aktif 0. Tanpa regresi BE.

**Regresi e2e FE Archive item lain** (hanya dijalankan, berkas mereka tidak diubah; gate1 §5):

| Item | Hasil | Catatan (semua usang/data milik item itu, bukan defect item ini) | Pemilik |
|---|---|---|---|
| ED-1025 | 16 OK, 1 GAGAL (`...ED-1025-...-20261009-015002`) | "AC-3 C": oracle `expectedPlus` `ED-1025-folder-permission.spec.js:124` masih `if (has('Update Folder')) keys.push('set-permission')`; aturan baru = `Folder Permissions`, jadi role C (A tanpa Folder Permissions, masih Update Folder) kini benar tanpa "Set Permission by User" (dibuktikan juga e2e ED-1029 D). Lulus untuk A/D hanya kebetulan | QA ED-1025 |
| ED-1026 | 7 OK, 3 GAGAL, 1 SKIP (`...ED-1026-...-20261009-015437`) | "AC-3/AC-4": `:267` mengharapkan Opname Dokumen item terakhir di root, padahal BR-1 ED-1029 menaruh "Set Permission by User" sesudahnya (sudah begitu sejak ED-1029 asli). "AC-4/AC-7 Step 1" dan "AC-8 ZULFA": CABANG - SEMARANG dan Folder A kini Izin On (data developer 2026-10-08, QA_USER tanpa baris View) dan "PUSAT - MAGELANG" tergeser ke halaman 2 daftar (10/halaman) oleh 7 folder fixture (screenshot GAGAL); sama dengan catatan `qa-be.md` | QA ED-1026 |
| ED-1027 | 7 OK, 6 GAGAL (`...ED-1027-...-20261009-015730`) | AC-13: total dokumen 33267 vs 34536 (data berubah); AC-16/19 dan AC-17/18/19: oracle "CTA Lihat semua riwayat tidak dirender" (CTA datang dari ED-1028); AC-20: menu "+" root diharapkan tepat 4 item (kini 5, item ED-1029); jalur error: `.ant-modal-footer button` (footer modal dihapus `7fa63869d`); user2 "tiga tab": E tanpa Folder Permissions kini 2 tab (ED-1025 rev) | QA ED-1027 |
| ED-1028 | 11 OK, 2 GAGAL (`...ED-1028-...-20261009-020021`) | AC-15 dan AC-24: `.ant-modal-footer button` tidak ada (footer modal dihapus `7fa63869d`) | QA ED-1028 |

Fixture semua item itu: teardown OK. Tidak satu pun kegagalan ini mengenai halaman/guard milik revisi ini; satu-satunya yang terkait aturan baru item "+" adalah ED-1025 AC-3 C.

**Pemulihan data uji:** `fixture.php down`: 12 folder `0QA29-` dibuang, user A/E dipulihkan, role `QA29F-ROLE-M/D` dibuang, 6 tabel (4 `archive*`, `roles`, `role_permissions`) = baseline count + CHECKSUM (diverifikasi tiap run, dicek ulang manual sesudah semua run bersama `user_roles`, `employees`, `employee_locations`: identik; `archive_permissions` 7 baris developer utuh). AUTO_INCREMENT `roles.id_role` maju (metadata). Semua token di-log-out
(e2e: 2/2 dicabut tiap run; runner BE: masih aktif 0). `users.is_login` QA_USER2 sempat 0 akibat P-1 pada run pertama, run berikutnya login ulang dan log-out normal.

## Yang tidak bisa diverifikasi (dan kenapa)

- Tukar lisensi sungguhan tanpa Salesman Activity (AC-20): `PROFILES` kosong; MANUAL Gate 2 epic (task 012 butir 23). Menu Archive tersembunyi tanpa fitur hanya dicek di kode (`menus.js`).
- Tampilan (task 015 butir 1-2 teks en/id, butir 7-8 sel, task 012 butir 3-9): penilaian visual manusia, MANUAL Gate 2; struktur dan alur sesuai desain (`design-03.png`).
- Isolasi tenant: bukan lingkup stage fe (`QA_DBS` satu DB; di BE SKIP/not_verifiable).
- User customer (di-bypass `permission_v5`): tidak ada user customer uji; dicatat QA BE.
- Kilasan Unauthorized dibuktikan lewat detektor DOM/path (headless, satu mesin); kilasan sub-frame lebih singkat dari satu siklus mutasi DOM tidak terbukti mutlak (halaman me-render `null` sampai `app.user` terisi; ada test unit "app.user null").

## Riwayat ronde

- Ronde 1 (2026-10-07, run asli): 8 PASS, 1 FAIL (AC-3, guard `PrivateRoute` inert, ED-967), 1 MANUAL (AC-20); e2e 13 OK / 2 GAGAL; temuan dokumentasi F-1.
- Ronde 2 (2026-10-07, run asli): 9 PASS, 0 FAIL, 1 MANUAL; D-1 tertutup (guard lokal), F-1 tertutup (butir 23 task 012); e2e 16 OK; jest Archive 262/262.
- Ronde 1 revisi (2026-10-09, laporan ini): 13 PASS, 0 FAIL, 1 MANUAL; Folder Permissions di "+", route, guard, sel aktif di semua folder On, teks info baru, tanpa modal; e2e 22 OK / 0 GAGAL, regresi BE 29 PASS + 1 SKIP; skenario usang item lain dicatat.
