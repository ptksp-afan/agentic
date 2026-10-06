---
key: ED-1024
scope: fe
round: 1
verdict: PASS
counts: {pass: 2, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

QA FE ronde 1 untuk Archive - visibilitas per lokasi kerja & daftar transaction type terpusat. Item ini **tidak punya perubahan kode FE**
(spec §5, `fe_modules` kosong): `git status` FE bersih, HEAD FE `6afd19054` = baseline, `changed.txt` hanya berisi 19 berkas BE. Karena itu
**tes unit FE (`yarn test`) tidak dijalankan**: tidak ada diff FE yang bisa diuji. Yang dikerjakan: build staging, e2e layar Archive
(AC-16, AC-17), pemeriksaan guard lisensi sisi FE (AC-13) dan regresi BE penuh.

- **Build staging**: `scripts/fe-build.sh build` PASS (exit 0, 86 dtk, `FE_STAGING_DIR`, `work/qa-fe/build.txt`); tidak menyentuh `build/` developer.
- **AC-16 (user JOG) PASS** dan **AC-17 (is_superadmin 1) PASS**: e2e satu run per keadaan, tanpa error konsol/page/HTTP >= 400, tanpa respons 5xx.
- **AC-13 sisi FE: MANUAL (Gate 2)** untuk tukar lisensi sungguhan (`PROFILES` kosong). Guard menu dan route ada di kode dan menu terbukti hilang
  tanpa permission; **temuan F-1 (pra-ada, global, perlu keputusan developer)**: guard permission route FE tidak pernah mengalihkan, sehingga
  klausa "route tertolak" pada AC-13 hanya ditegakkan oleh BE (403 `GE0114`, tanpa data) dan oleh menu yang tersembunyi. Rinciannya di bawah.
- **Regresi BE**: seluruh `qa/scenario*.php` **26 PASS, 0 FAIL, 0 SKIP, 0 BLOCKED** (sama dengan QA BE ronde 2); tidak ada HTTP 500.
- **Pemulihan DB**: `CHECKSUM TABLE` + hitungan baris (archives, archive_locations, archive_documents, user_roles, employee_locations, employees,
  roles, locations, permissions, role_permissions) + baris user/employee QA **identik** sebelum e2e, sesudah e2e dan sesudah regresi
  (`work/qa-fe/state-before.txt` = `state-after.txt` = `state-after-regression.txt`); 0 baris `QA01-%`; semua token di-log-out
  (e2e: `log-out default HTTP 200, dicabut=ya` pada ketiga run; regresi: "masih aktif: 0").
- Verdict **PASS**: semua AC stage ini PASS atau MANUAL, tidak ada 500, regresi BE lulus. F-1 dan F-2 meminta keputusan developer di Gate 2 (bukan defect fitur).

## Cara keadaan user dibuat (e2e memakai profil `default` = QA_USER)

`QA_USER2` tidak bisa login (500 `GE0109`, pra-ada, secrets tidak diubah). Keadaan dibuat sementara oleh fixture `qa/e2e/fixtures.json` -> `qa/e2e/state.php`
(setup sebelum sesi browser, teardown sesudah log-out, jurnal pemulihan `qa/e2e/.state-journal.json`; dipilih lewat env `E2E_STATE`):

| `E2E_STATE` | Keadaan QA_USER | Dipakai |
|---|---|---|
| `jog` | role 26 "Pusat - Support" (`is_superadmin` 4, hak Archive 1081-1090 lengkap) + employee lokasi **JOG saja** | AC-16 |
| `super` | role 1 (`is_superadmin` 1) + employee JOG saja (bypass terbukti tak bergantung lokasi employee); satu dokumen Billing (`BILL/2511/00003`, `is_active -1` -> 1) diaktifkan agar filter Type = Billing punya hasil | AC-17 |
| `noperm` | role 28 "Pusat - Auditor" (tanpa permission Archive); lisensi Salesman Activity tetap aktif | AC-13 (simulasi) |

Catatan lingkungan: login (`auth/token`) meminta `geotag` (422) bagi employee reguler yang rolenya tidak punya "Allow Sign In Outside Radius"
(LoginRequest `requiredGeotagRule`), jadi role uji dipilih dari yang memilikinya (26, 28, 1). Pemulihan persis diverifikasi di `state.php down`
(snapshot baris user_roles/employees/employee_locations dan baris archives Billing dibandingkan byte per byte).
Menjalankan ulang: `E2E_STATE=jog scripts/e2e/run.sh ED-1024 -- --grep "AC-16|layar terbuka"`, `E2E_STATE=super ... --grep "AC-17|layar terbuka"`,
`E2E_STATE=noperm ... --grep "AC-13"` (tanpa `E2E_STATE` fixture tidak mengubah apa pun dan test bergating-state di-skip).

## Hasil per AC

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-16 | e2e `qa/e2e/ED-1024-archive.spec.js` "AC-16" + smoke `routes.json` (`/archives`), state `jog` | **PASS** | Run `work/e2e/ED-1024-archive-lokasi-transaction-type-20261006-210827/` (2 OK, 0 GAGAL, 0 error, 0 allow-list; 11 panggilan API Archive semuanya 200). Butir: (1) layar `/archives` terbuka, tanpa pengalihan, menu "Arsip" tampil; root = Backup Arsip, CABANG - JOGJA, PUSAT - MAGELANG, **tanpa CABANG - SEMARANG** (respons dan teks tabel) `AC16-01`; (2) popover filter Type: `GET select/document-archive/archive/types` 200, `options` berurutan `FOLDER,6,7,8,9,29,31,222`, dropdown UI memuat "Pesanan Penjualan ... Bilyet Giro/Cek, **Penagihan**" `AC16-02`; (3) Display Setting (drawer) terbuka `AC16-03`; (4) cari "SML" (semua level): hanya SMLYK dan SMLYK - BRANGKAS, tanpa SMLHO/SMLSMG `AC16-04`; (5) buka Backup Arsip: folder = [SMLYK, SMLYK - BRANGKAS], teks tabel tanpa SMLHO/SMLSMG `AC16-05`; (6) drawer Info (`GET archives/history/{id}` 200) `AC16-06` dan drawer View (`GET archives/{id}` 200, nama = SMLYK - BRANGKAS) `AC16-07`; (7) modal Pindahkan: tabel folder (`GET archives` 200) memuat PUSAT - MAGELANG, tanpa CABANG - SEMARANG `AC16-08`; (8) modal Tambah Folder dengan induk Backup Arsip (`GET archives/{induk}` 200, `GET select/.../locations?idArchiveParent=` 200, opsi lokasi muncul) `AC16-09`; (9) modal Serahkan Dokumen (Hand Over) terbuka `AC16-10`; tidak ada respons 5xx (`monitor.responses`) |
| AC-17 | e2e spec "AC-17" + smoke, state `super` | **PASS** | Run `work/e2e/ED-1024-archive-lokasi-transaction-type-20261006-210905/` (2 OK, 0 error; 6 panggilan API semuanya 200). Root memuat **CABANG - SEMARANG dan CABANG - JOGJA** (respons dan teks tabel) walau employee hanya JOG `AC17-01`; filter Type = Penagihan (`search={"type":222}`) 200, `total=1`, baris `BILL/2511/00003 [Penagihan]` tampil di UI, semua baris berlabel Billing `AC17-02`. Tambahan: isi Backup Arsip untuk superadmin = keenam folder (SMLYK, SMLYK - BRANGKAS, SMLHO - KANTOR ADMIN, SMLSMG, SMLSMG - BRANGKAS, SMLSMG - PROSES KIRIM) `AC17-03` |
| AC-13 (sisi FE) | Kode (cara cek spec) + e2e simulasi role tanpa permission (state `noperm`); tukar lisensi sungguhan = manual | **MANUAL (Gate 2)**; guard di kode PASS; **F-1** | (a) Kode: menu `src/configuration/menus.js:169` `permission: hasSalesmanFeature ? permissions.ListArchive : shouldNotAppear` (`hasFeature(user,'salesman')` = `user.features.salesmanActivity`, `menus.js:26`, `constant.js:112`) = dijaga lisensi **dan** permission; route `src/routes/routes.js:2086-2094` `permission: permissions.ListArchive` + `<PrivateRoute permission=...>` (route tidak punya cek fitur lisensi langsung; efek lisensi lewat permission yang hanya di-seed bila lisensi punya `SALESMAN_ACTIVITY`). (b) e2e `work/e2e/ED-1024-archive-lokasi-transaction-type-20261006-210935/`: `auth/info` role 28 = 22 permission tanpa satu pun Archive; **menu "Arsip" tidak tampil** di sidebar (menu tampil pada state `jog`/`super`, lisensi aktif); `GET archives` = **403 `GE0114`, `parameter` "List Archive", tanpa `result`**, tabel 0 baris `AC13-01`. (c) **Route tidak dialihkan** ke `/unathorized`: lihat F-1. (d) Bagian BE AC-13 PASS di regresi (`AC-13` 92 asersi, simulasi tanpa seed 403 `GE0114`). Sisa MANUAL: tukar lisensi sungguhan (menu/route pada tenant tanpa `SALESMAN_ACTIVITY`) sekali di Gate 2 epic |

## Defect

Tidak ada defect fitur. (Tidak ada perubahan FE; BE: regresi 26/26 PASS, tidak ada 500.)

## Temuan dan keputusan untuk developer (bukan defect fitur, tidak mengubah verdict)

- **F-1 (FE, pra-ada, global): guard permission route tidak pernah mengalihkan.** `src/components/PrivateRoute/index.js:18-24` hanya mengalihkan ke
  `/unathorized` bila `app.loadingUserData === false`, tetapi `loadingUserData` tidak pernah diisi di mana pun (`grep loadingUserData src` hanya menemukan
  berkas itu). Sudah dicatat tim di ED-946 F00 (commit `7e98fe8a9`, `_global/001-feat-scope-route-central-cabang.md` butir 2: "guard permission yang ada
  tidak pernah jalan ... dibiarkan, diusulkan terpisah"). Bukti e2e (state `noperm`): setelah `auth/info` lengkap path tetap `/archives`, layar Archive
  terbuka kosong ("Tidak ada data"), memanggil `GET archives` yang dijawab 403 `GE0114` plus console error AxiosError. Dampak ke AC-13: klausa "route
  `/archive` tertolak" **tidak** ditegakkan oleh FE. Bukan khusus Archive: probe e2e sekali pakai dengan role 28 pada dua route ber-permission lain yang tidak
  dimiliki role itu (`/setups/customers/customer-devices` = Get Customer Device, `/setups/customers/customer-types` = Get Customer Type) juga tidak dialihkan
  (`work/qa-fe/explore/paths.txt`; spec probe tidak disimpan). Yang menegakkan
  adalah BE (403, tanpa data) dan menu yang tersembunyi (pengguna biasa tidak punya jalan ke route itu, karena FE memakai `MemoryRouter` per tab). Pilihan
  developer di Gate 2: (a) terima dan sesuaikan kalimat AC-13/spec ("menu tersembunyi + BE 403"), atau (b) tiket FE terpisah untuk menghidupkan guard
  `PrivateRoute` (mengubah perilaku semua route ber-permission; di luar ED-1024).
- **F-2 (proses): butir MANUAL AC-13 belum tercatat di `## Daftar tes UI` task mana pun** karena item ini tidak punya task FE (spec §5). Spec hanya menulis
  "tukar lisensi sungguhan sekali, manual, di Gate 2 epic". Usul: orchestrator memasukkannya ke daftar tes manual Gate 2 epic ED-1022 (tenant tanpa
  `SALESMAN_ACTIVITY`: menu "Arsip" tidak tampil, `GET archives` 403 `GE0114`, route tidak punya data).
- **O-1 (informasi, pra-ada)**: di modal Tambah Folder, opsi lokasi untuk user JOG (induk Backup Arsip, semua lokasi) hanya "JOG Yogyakarta"
  (`select/document-archive/archive/locations` memakai `Location::optionSelects` -> `whereLocation`, dibatasi lokasi user). Teks K-8 spec menulis "semua lokasi aktif
  dibatasi lokasi induk". Endpoint tidak diubah oleh item ini; tidak ada AC yang terkena.
- **O-2 (informasi, dokumentasi)**: spec menulis route `/archive`, path sebenarnya `/archives` (`src/routes/paths.js:34`).
- **O-3 (alat, bukan fitur)**: bagian `api` di `report.json` harness kosong pada mesin ini (membandingkan prefix `http://127.0.0.1:80/...` padahal browser
  menormalkan tanpa `:80`). Spec ini memakai `monitor.responses` untuk pemeriksaan 5xx dan mencatat tiap panggilan API Archive ke catatan test (terlihat di `index.html`).
- Log Laravel pada jendela run: hanya derau pra-ada (`websockets_statistics_entries` tidak ada) dan satu "Access token has been revoked" per run e2e
  (verifikasi pencabutan token oleh harness, 401 bukan 500). Tidak ada 500 dari Archive.

## Galeri e2e & regresi BE

| Keadaan | Run | Hasil |
|---|---|---|
| `jog` (AC-16 + smoke) | `work/e2e/ED-1024-archive-lokasi-transaction-type-20261006-210827/index.html` | 2 OK, 0 GAGAL, 0 SKIP; 10 screenshot AC16-01..10 + smoke |
| `super` (AC-17 + smoke) | `work/e2e/ED-1024-archive-lokasi-transaction-type-20261006-210905/index.html` | 2 OK, 0 GAGAL, 0 SKIP; AC17-01..03 + smoke |
| `noperm` (AC-13 simulasi) | `work/e2e/ED-1024-archive-lokasi-transaction-type-20261006-210935/index.html` | 1 OK; AC13-01 |

Percobaan sebelumnya yang gagal (selektor/penantian spec, login `geotag` untuk role 5) ada di folder `work/e2e/ED-1024-*` lain dan diabaikan. Allow-list: **0 entri**
(satu-satunya penolakan 403 yang disengaja pada state `noperm` dikeluarkan dari pemeriksaan di dalam test setelah bentuknya diasersi: 403 `GE0114`, parameter "List Archive").

Berkas e2e (`features/ED-1024-archive-lokasi-transaction-type/qa/e2e/`): `routes.json`, `ED-1024-archive.spec.js`, `fixtures.json`, `state.php` (bukan skenario regresi:
nama tidak cocok `scenario*.php`).

**Regresi BE** (`"$PHP_BIN" scripts/qa-http/run.php ED-1024`, `work/qa-fe/regression.txt`, laporan runner `work/qa-http/ED-1024-20261007041052.json`):
`Ringkasan: PASS=26 FAIL=0 SKIP=0 BLOCKED=0`; AC-1..AC-12, AC-13 (bagian BE), AC-14, AC-15, AC-18, X-1..X-9, R2-1 semuanya PASS; token run: 1 dibuat, 1 log-out, masih aktif 0.
Build: `work/qa-fe/build.txt`. Ringkasan DB: `work/qa-fe/state-before.txt`, `state-after.txt`, `state-after-regression.txt`.

## Yang tidak bisa diverifikasi (dan kenapa)

- Tukar lisensi sungguhan tanpa `SALESMAN_ACTIVITY` (menu/route pada tenant tanpa fitur): `PROFILES` kosong; MANUAL di Gate 2 epic (F-2). Yang terbukti:
  guard di kode, menu hilang dan BE 403 pada role tanpa permission (simulasi `noperm`).
- Modal Hand Over/Receive hanya dibuka (scanner kamera headless tampil sebagai kotak kosong, tanpa error); pemindaian QR nyata dan aksi serah-terima (mutasi) tidak diuji.
- Aksi mutasi di layar (simpan folder, pindah, hapus, serah-terima) sengaja tidak dijalankan e2e (non-mutasi); perilaku BE-nya sudah dibuktikan di skenario BE.
- Tes unit FE: tidak ada diff FE, jadi tidak ada yang diuji.
- Penilaian visual: bukan bagian QA otomatis (item tanpa desain di `brief/`).

## Riwayat ronde

- Ronde 1: build staging PASS; AC-16 PASS, AC-17 PASS, AC-13 sisi FE MANUAL (guard di kode, simulasi `noperm`: menu hilang, BE 403; F-1 guard route inert, pra-ada global);
  regresi BE 26/26 PASS; DB identik sebelum/sesudah; verdict PASS.
