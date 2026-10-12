---
key: ED-1029
scope: fe
round: 1
verdict: PASS
counts: {pass: 18, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

Ronde 1 dari **revisi 3** (review batch ronde 2: FB2-2 kolom Semua, FB2-3 saklar Izin Folder; subtask FE ED-1129 = task `docs/specs/Archive/024-enh-archive-permission-page-toggle.md`,
subtask QA ED-1130). Diff FE: 6 M + 1 baru di atas HEAD f1fd4f69e (`ArchivePermissionPage/index.js` + test, `archive.function.js` + test, `archive.locale.js`, `_api.md`, `_module.md`, task 024).
BE rev3 (4 M, ED-1128) tidak berubah sejak QA BE (mtime 08:40 < `qa-be.md` 09:35). Dari 19 AC milik stage ini (`[FE]` 1, 2, 3, 14-19, 24, 26, 29, 30 dan `[BE+FE]` 20, 25, 28, 31, 36, 37):
**18 PASS, 0 FAIL, 1 MANUAL** (AC-20: tukar lisensi sungguhan, Gate 2 epic). **Tanpa defect.** Tanpa 5xx dari alur item ini (e2e: 0 error/5xx pada 30 test; 28 x 500 di regresi BE
hanya probe karakterisasi X-1 = ED-1071, sengaja).

- Build staging **PASS** (`Compiled successfully.`, 63,2 dtk, exit 0; kalimat BR-9 rev3 "Menyalakan {setting}" dan "Turning on" ada di `main.*.js`; build lebih baru dari berkas FE yang diubah).
- Jest `src/containers/Archive`: **12 suite / 447 test hijau**. Suite penuh: 80 suite / 1768 test, 6 suite / 7 test gagal; diulang `--runInBand`: 3 RaptorPending hijau (timeout beban), tersisa
  **4 baseline lama, tidak menyentuh diff** (production.function 2 test, InterbankTransferForm 1, Item/BatchOut 1, CustomerPurchaseOrderView suite gagal jalan `electronEvent`), sama dengan ronde revisi sebelumnya.
- E2E ED-1029 (`E2E_WORKERS=1`, profil default + user2): run akhir **30 OK, 0 GAGAL, 0 SKIP** (21 flow default + 5 flow user2 + 4 smoke), 0 error konsol/halaman/HTTP >= 400 selain 400/403 yang diharapkan
  di test jalur error (dikeluarkan eksplisit). 8 test baru dan 2 test lama diperluas untuk rev3. Run sebelumnya di sesi ini: `...-142353` 27 OK / 2 GAGAL (kesalahan spek QA: `th` kosong
  placeholder scrollbar ikut terhitung di judul kolom, bukan FE), `...-143022` 29 OK.
- Regresi tab Izin (ED-1025, berkas helper `archive.function.js` bersama): e2e ED-1025 **21 OK / 0 GAGAL**; unit `archive.function.test.js` hijau (helper ED-1025 tidak diubah); QA BE ED-1025 `--only=AC-5,AC-38` **PASS** (61 + 165 asersi, EPIC K-5 b).
- Regresi BE: seluruh `qa/scenario*.php` ED-1029 (termasuk scenario7/8/9-rev3 + `qa_lib_r3.php`): **PASS=40 FAIL=0 SKIP=1 (X-ISO) BLOCKED=0**. Log Laravel selama sesi: 40 galat, semuanya 28 x kolasi 1267/1270 (X-1, ED-1071) + 12 x OAuth
  "denied" (token yang sengaja dicabut `force_login`); tanpa galat lain.
- Data uji: hitungan + md5 baris 12 tabel identik sebelum vs sesudah (`work/qa29r3fe/counts-before.json` = `counts-after.json`); 0 baris `0QA29-`/`QA29*`; tidak ada data developer yang dihapus (rinci di "Pemulihan data uji"). Token: e2e log-out 2/2 tiap run, runner BE "masih aktif: 0".
- Penolakan izin/classifier: **tidak ada**. Kode aplikasi, spec, kontrak tidak diubah QA; yang diubah hanya `qa/e2e/*` ED-1029 (daftar di bawah).

## Hasil per AC

Pelaku e2e: A = QA_USER (role 3 = Update Folder + Folder Permissions, employee dibatasi lokasi MGL sementara, bahasa ID). QA_USER2 (EN) bergantian sebagai E (role 18), M (role sementara
`QA29F-ROLE-M`), D (`QA29F-ROLE-D`); dipulihkan di `finally`. Folder uji `0QA29-`: P On (A penuh, B View) > C1 On, C2 Off (B View+Store); Q On (A View, B V+U); R Off > R1 On; L di luar lokasi A; J > JA/JB/JC;
F Off tanpa baris; **G Off, A View saja (baru, fixture)**. B = `admintest`, B2 = `dias`. Run akhir: `work/e2e/ED-1029-archive-set-permission-by-user-20261012-144609/` (selanjutnya "run akhir").

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 (rev) | unit `getArchiveAddKeys`/`ArchivePage` (Archive 12/12 suite); e2e A (root, P), M, D, E | PASS | e2e "AC-1 + AC-2 + AC-14 A": item terakhir root dan P = "Atur Izin per User"; M root+P `Set Permission by User` paling akhir; D dan E: tanpa item itu. Run akhir, 4 test OK |
| AC-2 | e2e A: lewat "+", URL, F5 | PASS | tabel kosong + "Pilih user untuk melihat izin foldernya", Reset/Simpan disabled, 0 request `user-permissions`, `select/document-archive/archive/users` tanpa `excepts`, menu Arsip aktif; smoke `/archives/permissions` A OK |
| AC-3 (rev) | e2e A (URL, 2 x F5, lewat "+"), M, D, E; unit guard | PASS | A/M: tanpa kilasan Unauthorized (detektor DOM/path), D/E: path `/unathorized`, `.ant-result-403`, 0 request; 5 test OK |
| AC-14 (rev, rev3) | e2e A: pohon B vs GET `user-permissions/{B}` untuk SEMUA baris (urutan, level, nilai tersimpan, saklar = `is_folder_permission`, saklar aktif bila `manage_permission`, Semua indeterminate/terkunci), urutan kolom, tanpa tag, info BR-9; e2e M (en); tampilan = MANUAL | PASS (fungsi); tampilan saklar MANUAL | `expectTreeMatches` hijau (A, J, superadmin, M). Judul kolom (notes e2e): `Folder \| Izin \| Semua \| Lihat \| Ubah \| Hapus \| Letakkan`; M (en): `Permission \| All \| View \| Update \| Delete \| Store`. Saklar aktif di semua folder (termasuk C2, R, JB, JC; L tidak ada); sel + Semua aktif di P, C1, Q, R1, terkunci di C2/R dengan nilai tersimpan (C2 View+Store, Semua indeterminate terkunci); 0 `.ant-tag` di tabel; `aria-label` saklar "0QA29-P - Izin". Tampilan: task 024 `## Daftar tes UI` butir 1-3 |
| AC-15 | unit `changeUserPermission` + e2e A (C1, P, Q) | PASS | indeks checkbox dikoreksi (kolom Semua di depan); View ikut, lepas View melepas semua, Q bisa dicentang, sel terkunci tidak berubah walau diklik paksa, 1 request (GET) |
| AC-16 | unit `filterUserPermissionTree` + e2e A | PASS | "c1" -> P dan C1; perubahan tetap selama disaring; pencarian tanpa request |
| AC-17 | e2e A: Save P+C1, hapus baris P, tab Izin P | PASS | body persis `{"permissions":[P,C1]}` tanpa `folders` (integer), 200 `ARCHIVE207`, GET, PUT, GET; DB B P=1001, C1=1000; riwayat +1 oleh A; tab Izin P memuat B View+Store (4 centang terakhir) |
| AC-18 | e2e A: Reset, ganti User | PASS | Reset 0 request, tanpa konfirmasi; ganti User saat dirty = konfirmasi, Tidak = tetap, Ya = GET B2; tanpa perubahan = langsung |
| AC-19 | e2e A: C1 dimatikan di DB sebelum Save | PASS | PUT 400 `ARCHIVE441` toast "...folder <b>0QA29-P-C1</b>" (ikon silang), centang tetap, Save aktif, tanpa GET ulang, DB utuh |
| AC-20 (rev) | `[BE+FE]` cara seragam EPIC K-2 b: BE = regresi `scenario3-access` AC-20/X-PERM; FE = kode + grep; tukar lisensi sungguhan = manual | MANUAL (Gate 2 epic); sisi BE dan kode PASS | BE ronde ini: AC-20 PASS, X-PERM PASS (`work/qa29r3fe/be-reg.txt`). FE: `grep -nE "\b(1085\|1133\|1139\|1140)\b"` di `src/containers/Archive` (non-test) dan di `qa/*.php`, `qa/e2e/*` = 0 temuan. Tukar lisensi: `PROFILES` kosong; task 012 butir 23 (tetap berlaku) |
| AC-24 (rev) | e2e A (data J) + M (en) | PASS (teks MANUAL) | J, JA, JB aktif, JC terkunci (View+Store tersimpan); Save JB body hanya JB, riwayat JB +1; info id/en tanpa "dominan"/"induk On terdekat"/"dominant"/"nearest On parent". Penilaian bahasa: task 024 butir 1-2 |
| AC-25 (rev) | e2e A: tab Izin F -> halaman by User -> tab Izin | PASS | F: toggle di tab Izin = baris A otomatis, DB F On (F,A)=1111; halaman by User pilih A: F On, V/U/D/S tercentang dan aktif; lepas Delete + Save body hanya F `[1,1,0,1]`, tab Izin F: A View/Update/Store |
| AC-26 (rev) | e2e A: User = diri sendiri, lepas View di JA | PASS | tanpa modal apa pun, PUT langsung 200 body hanya JA `[0,0,0,0]` (tanpa `folders`), sesudah dimuat ulang sel JA tetap aktif, DB (JA,A) terhapus, dipulihkan lewat halaman yang sama |
| AC-28 (rev) | `[BE+FE]` skenario/e2e usang milik sendiri diperbarui; semuanya dijalankan ulang | PASS | e2e diperbarui (lihat "Berkas QA yang diubah"); e2e 30/30; BE 40 PASS + 1 SKIP; X-1 karakterisasi hijau (49 kasus: 21 x 404, 28 x 500, ED-1071) |
| **AC-29 (rev3)** | unit (`archive.function.test.js` `changeAllUserPermission`, `ArchivePermissionPage` "kolom Semua") + e2e A + DB | PASS | e2e "AC-29 A (rev3)": Semua tepat sesudah Permission; P: centang Semua -> `[T,T,T,T]` Save/Reset aktif; lepas -> `[F,F,F,F]` (beda dari tersimpan View, Save tetap aktif), kembali View -> Save mati; C1: centang+lepas = Save mati; Update saja -> `[T,T,F,F]` Semua indeterminate; C2/R Semua disabled, klik paksa tanpa efek. **Save Semua C1: body `{"permissions":[{C1 1/1/1/1}]}`** (tanpa `folders`, integer), DB (C1,B)=1111, riwayat +1 oleh A, +1 baris; lepas Semua + Save: body C1 `0/0/0/0`, baris terhapus, jumlah baris = awal. Screenshot `AC29-01..03` |
| **AC-30 (rev3)** | unit + e2e A (3 test) + DB + e2e M (en); tampilan saklar = MANUAL | PASS (fungsi); tampilan saklar MANUAL | **Saklar aktif di semua folder** (J, JA, JB, JC; L tidak ada). **F Off->On**: sel aktif, `[F,F,F,F]` (tidak dicentang otomatis), Save/Reset aktif, 0 request tambahan; centang View lalu F Off -> kembali kosong terkunci, Save+Reset mati. P Off->On = tersimpan (Save mati). **K-10 b**: centang Update di P, P Off -> terkunci tetap `[T,T,F,F]`, Semua indeterminate terkunci; **body `{"permissions":[{P 1/1/0/0}],"folders":[{P,isFolderPermission:0}]}`**, 200, DB P flag 0, (P,B)=1100, (P,A) utuh, riwayat +1 (satu), `updated_by`=A, 0 baris baru; pulih: body `{"permissions":[],"folders":[{P,1}]}`. **Hanya saklar** (F On, target B): body `{"permissions":[],"folders":[{F,1}]}`; **saklar mati selama PUT** (PUT ditahan 2,5 dtk lewat `page.route`, semua `button.ant-switch` disabled; `AC30-06`). **BR-14**: Reset mengembalikan saklar tanpa request; ganti User saat hanya saklar berubah = konfirmasi (Tidak = tetap B dengan R On, Ya = B2, saklar tersimpan). Info id (A) dan en (M) memuat kalimat rev3 ("Menyalakan Aktifkan Izin Folder di sini ..." / "Turning on Enable Folder Permission here ...", `{setting}` tidak literal). Save tanpa `.ant-modal-confirm`, toast sukses, GET ulang. Tampilan: task 024 butir 3 |
| **AC-31 (rev3)** | e2e A (2 test) + DB | PASS | A pilih A: **F (tersimpan Off) On -> `[T,T,T,T]` + Semua tercentang sebelum Save**, masih aktif; Off lagi -> kembali kosong, Save mati; On lagi -> 1111; **Q (tersimpan On) Off lalu On -> `[T,F,F,F]` (tidak dicentang otomatis, C-1)**, Save mati. Lepas Delete -> `[T,T,F,T]`. **Body `{"permissions":[{F 1/1/0/1}],"folders":[{F,1}]}`** (hanya F, tanpa Q). DB: F flag 1, `updated_by`=A, (F,A)=1101, `dups`=[], +1 baris, riwayat F +1, hak B utuh. Tab Izin F: saklar On, A View/Update/Store tanpa Delete; list Archive: F berperisai (`.anticon-safety-certificate` = 1). **G (Off, A View) self On -> naik `[T,T,T,T]`**, body `permissions [G 1/1/1/1]` + `folders [G:1]`, DB satu baris (G,A)=1111, jumlah baris tetap |
| **AC-36 (rev3)** | e2e A + DB (sisi UI; sisi BE di `qa-be.md`) | PASS | Sesudah Save F On (target B): tab Izin F saklar On, baris A 1111 (otomatis BE, K-9 a; DB (F,A)=1111, (F,B) tanpa baris, `dups`=[], +1 baris); F Off dari halaman: body `{"permissions":[],"folders":[{F,0}]}`, tab Izin F saklar Off dan baris A tetap, riwayat +1; F dinyalakan di tab Izin (`PUT archives/{F}` 200) -> halaman by User pilih B: F On. Juga (target B): G (A View) On -> baris A naik 1111, tanpa baris (G,B), jumlah baris tetap; F On + Semua + lepas Delete/Store -> body `permissions [F 1/1/0/0]` + `folders [F:1]`, DB (F,B)=1100, (F,A)=1111 otomatis, +2 baris, riwayat +1, tab Izin F memuat A dan B. (GET `archives/{F}` oleh A/B dan 403 E = tahap be) |
| **AC-37 (rev3)** | `[BE+FE]` regresi: seluruh `qa/scenario*.php` + e2e ED-1029 dan ED-1025; QA BE ED-1025 `--only` AC-5, AC-38 | PASS | BE: 40 PASS / 1 SKIP, termasuk AC-37 dan AC-37b (dua user nyata + paralel `folders [F:1]`, `scenario5-concurrency`), X-5/6/7 tanpa 5xx. ED-1025 `--only=AC-5,AC-38`: PASS (61 + 165 asersi). E2E ED-1029 30/30, ED-1025 21/21. Asersi lama kolom Permission-sebagai-tag diperbarui ke saklar (AC-14) |

AC [BE] (4-13, 21-23, 27, 32-35) bukan milik stage ini; dibuktikan di `qa-be.md` dan dijalankan ulang di regresi BE di atas (semua hijau). Tambahan di luar AC (PASS): jalur error M (Folder Permissions dicabut -> Save 403 `GE0114`, toast, centang tetap, muat ulang -> Unauthorized); BR-4 buka-tutup; BR-15 superadmin (saklar aktif semua folder, L ikut tampil).

## Defect

Tidak ada defect baru (FE maupun BE).

Pengamatan (bukan defect, tidak menggagalkan AC):
- P-1 Judul kolom tabel di DOM memuat satu `th` kosong terakhir (placeholder scrollbar tabel sticky); bukan kolom nyata. Asersi urutan kolom QA menyaringnya (kesalahan spek QA di run pertama, sudah diperbaiki).
- P-2 Pada screenshot `fullPage`, tajuk kolom tampak terpisah dari baris (sticky header, celah di bawah kotak info; sama dengan P-3 revisi lalu): penilaian tampilan, sudah di daftar tes UI Gate 2.
- P-3 Sel hak dan Semua tidak ikut mati selama menyimpan (hanya saklar mati), sesuai spec §5 dan catatan task 024 (perilaku 012/015 tetap); diamati di `AC30-06`. Bukan penyimpangan dari spec; usulan reviewer (Semua ikut mati) tetap untuk review batch.
- P-4 Saklar mati karena `access.managePermission !== true` tidak bisa dihasilkan e2e (BE selalu mengirim `true` bagi pemegang Folder Permissions): dibuktikan unit test `ArchivePermissionPage` ("saklar mati bila access.managePermission bukan true") dan `canChangeUserPermission`/`toUserPermissionRequest`.

## Galeri e2e & regresi BE

**Build dan test**

| Langkah | Hasil | Bukti |
|---|---|---|
| `scripts/fe-build.sh build` | PASS | `work/qa29r3fe/build.txt`, `work/fe-build.log`: "Compiled successfully.", exit 0, 63,2 dtk; staging 14:15 lebih baru dari berkas FE (13:59-14:00) |
| `yarn test --watchAll=false src/containers/Archive` | 12 suite / 447 test hijau | `work/qa29r3fe/jest-archive.txt` (rev3: +12 test `ArchivePermissionPage`, +30 test helper di `archive.function.test.js`) |
| suite penuh `yarn test --watchAll=false` | 80 suite / 1768 test; 6 suite / 7 test gagal | `work/qa29r3fe/jest-full.txt`. Diulang `--runInBand` (`work/qa29r3fe/jest-rerun.txt`): RaptorPendingDrawer dan RaptorPendingView hijau; tersisa baseline lama (4 test + 1 suite `electronEvent`), di luar Archive dan diff |

**E2E ED-1029** (`E2E_WORKERS=1 scripts/e2e/run.sh ED-1029 --profile default,user2`): **30 OK, 0 GAGAL, 0 SKIP**. Galeri: `work/e2e/ED-1029-archive-set-permission-by-user-20261012-144609/` (`index.html`, `report.json`,
`screenshots/default|user2/`), keluaran `work/qa29r3fe/e2e-3.txt`. Fixture `user-permission-data`: setup OK, teardown OK (13 folder `0QA29-` dibuang, user A/E dipulihkan, 6 tabel archive/roles/role_permissions count + CHECKSUM = baseline). Log-out 2/2.

| Tes | Profil | Hasil |
|---|---|---|
| AC-1 + AC-2 + AC-14 A (rev, rev3: kolom, saklar, Semua, info rev3); AC-2 A; AC-3 A | default | OK x3 |
| AC-15, AC-16, AC-17, AC-18, AC-19 | default | OK x5 |
| BR-4 buka-tutup; BR-15 superadmin; AC-14 + AC-24 (data J); AC-26 (diri sendiri); AC-25 (tab Izin -> halaman -> tab Izin) | default | OK x5 |
| **AC-29** kolom Semua (centang/lepas/indeterminate/terkunci, Save body, DB) - baru | default | OK |
| **AC-30** saklar aktif semua folder, F Off->On->Off, K-10 b P (body folders + permissions, DB, pulihkan) - baru | default | OK |
| **AC-30 BR-14** hanya saklar: Reset, ganti User (Tidak/Ya) - baru | default | OK |
| **AC-30/32/36** hanya saklar F On (body `permissions: []`), saklar mati selama Save, hak otomatis BE, tab Izin + perisai, F Off, F On di tab Izin - baru | default | OK |
| **AC-32** target B, G (A View) On: BE menaikkan baris A, tanpa baris B - baru | default | OK |
| **AC-30/32** target B, F On + Semua + lepas Delete/Store: body `permissions` + `folders`, hak otomatis A, tab Izin A dan B - baru | default | OK |
| **AC-31** A pilih A: F self-grant, Q tidak dicentang (C-1), lepas Delete, Save, tab Izin, perisai - baru | default | OK |
| **AC-31** A pilih A: G (A View) naik 1111, satu baris - baru | default | OK |
| smoke `/archives/permissions` A, `/archives` A | default | OK x2 |
| AC-1 + AC-3 + AC-14 M (info en rev3, judul kolom en, saklar aktif untuk M); jalur error M; AC-1 + AC-3 D; AC-1 E; AC-3 E | user2 | OK x5 |
| smoke `/archives/permissions` E (`expectRedirect`, `forbidApi`), `/archives` E | user2 | OK x2 |

**Berkas QA yang diubah (milik item ini; stale/rev3):**
- `qa/e2e/ED-1029-user-permission.spec.js`: `tableRows` memisahkan saklar (`flag`), Semua (`all`), 4 hak (`boxes`); `box()` melewati kolom Semua (indeks +1), pembantu baru `allBox`, `flagSwitch`, `rowOf`, `clickSave`, `expectSavedAndReloaded`, `openIzinDrawer`;
  `expectTreeMatches` kini memeriksa saklar dan Semua per baris; AC-14 memeriksa urutan kolom, saklar menggantikan tag, kalimat info rev3 (id di A, en di M); 8 test baru AC-29/30/31/32/36 (daftar di tabel).
- `qa/e2e/fixture.php`: folder G (Off, A View saja) + `initialFlags`/`initialA`; `state` kini juga melaporkan `updated_by` per folder dan `dups` (baris ganda per (folder, user)).
- `routes.json`, `fixtures.json` tidak berubah. Skenario BE ED-1029 tidak diubah ronde ini (sudah diperbarui QA BE).

**Regresi BE** (`"$PHP_BIN" scripts/qa-http/run.php ED-1029`, server fpm, tanpa reload; `work/qa29r3fe/be-reg.txt`, JSON `work/qa-http/ED-1029-20261012213939.json`): `PASS=40 FAIL=0 SKIP=1 BLOCKED=0`. Skenario: AC-4..13b, X-PERM, AC-20,
X-ISO SKIP (`QA_DBS` satu DB), X-1 karakterisasi hijau (28 x 500 kolasi = ED-1071), X-2..X-7, AC-21/21b/22/23/25/26-BE/27, AC-32/33/34/34b/19/36/35/35b/X-8, AC-37/37b. Token: 6 dibuat, 0 gagal log-out, masih aktif 0.
ED-1025 `--only=AC-5,AC-38` (`work/qa29r3fe/be-1025.txt`, JSON `work/qa-http/ED-1025-20261012215258.json`): PASS 61 + 165 asersi.

**Regresi e2e FE tab Izin (ED-1025, berkas tidak diubah):** `scripts/e2e/run.sh ED-1025 --profile default,user2` -> **21 OK, 0 GAGAL** (`work/e2e/ED-1025-archive-folder-permission-20261012-143950/`, `work/qa29r3fe/e2e-1025.txt`); teardown OK, log-out 2/2.
Skenario usang milik item lain: tidak ada yang ditemukan di e2e ED-1025 (AC-3 C yang usang di revisi lalu sudah diperbarui QA ED-1025). Suite BE ED-1025 penuh tidak dijalankan ulang di sini: X-12 (ED-1071) dan AC-25 `scenario9-rev-seed-fuzz.php:168,172` (F-1) tetap tercatat di `qa-be.md`, tidak diubah.

**Pemulihan data uji:** `fixture.php down` tiap run e2e (13 folder `0QA29-` dibuang, `QA29F-ROLE-*` dibuang, user A/E persis). Sebelum vs sesudah seluruh sesi (`work/qa29r3fe/counts-before.json` vs `counts-after.json`, `diff` kosong):
archives 49148, archive_permissions 6, archive_locations 48037, archive_documents 49126, users 129, roles 27, role_permissions 3794, permissions 832, user_roles 126, employees 124, employee_locations 157, locations 3, `archive_opname` tidak ada, `archive_opname_documents` 0;
md5 baris `archives`, `archive_permissions`, `roles`, `role_permissions`, `user_roles`, `employees`, `employee_locations`, `users` identik; `qa_archives` 0, `qa_roles` 0. Data developer yang dihapus: tidak ada.

## Yang tidak bisa diverifikasi (dan kenapa)

- Tukar lisensi sungguhan tanpa Salesman Activity (AC-20): `PROFILES` kosong; MANUAL Gate 2 epic (task 012 butir 23). Menu/route tersembunyi tanpa fitur hanya dicek di kode dan regresi BE.
- Tampilan (task 024 butir 1-3: teks en/id kotak info, bentuk dan label saklar, kolom Semua; task 012 butir 3-9): penilaian visual manusia, MANUAL Gate 2; struktur kolom, field, aksi dan alur sesuai desain
  `CHANGES-p2.png` (+ kolom Semua dan saklar dari review FB2-2/3, tanpa desain baru). Ada di `## Daftar tes UI` task 024 (16 butir): tidak ada temuan "daftar tes UI hilang".
- Saklar mati karena `managePermission !== true`: hanya unit test (lihat P-4).
- Isolasi tenant: bukan lingkup stage fe (`QA_DBS` satu DB; di BE SKIP/not_verifiable). User customer (di-bypass `permission_v5`): tidak ada user customer uji (dicatat QA BE).

## Riwayat ronde

- Ronde 1 revisi 2 (2026-10-09): 13 PASS, 0 FAIL, 1 MANUAL; Folder Permissions di "+", route, guard, sel aktif di semua folder On, teks info baru, tanpa modal; e2e 22 OK, regresi BE 29 PASS + 1 SKIP.
- Ronde 2 asli (2026-10-07): 9 PASS, 0 FAIL, 1 MANUAL; guard lokal (D-1 tertutup), e2e 16 OK. Ronde 1 asli (2026-10-07): 8 PASS, 1 FAIL (AC-3, `PrivateRoute` inert, ED-967), 1 MANUAL.
- Ronde 1 revisi 3 (2026-10-12, laporan ini): 18 PASS, 0 FAIL, 1 MANUAL; kolom Semua + saklar Izin Folder + hak otomatis pengaktif + K-10 b; e2e 30 OK / 0 GAGAL, ED-1025 e2e 21 OK, regresi BE 40 PASS + 1 SKIP.
