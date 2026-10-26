---
key: ED-1250
scope: fe
round: 3
verdict: PASS
counts: {pass: 12, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

Ronde 3 = cek ulang **D-1** (ronde 2: AC-25 bagian FE tidak bisa dibuka karena halaman Setup > Display Setting tidak punya route) sesudah orkestrator menambah satu baris di spec § "Catatan implementasi" (2026-10-26) dan
menyelaraskan doc FE `027-...md` butir 23 + digest `_api.md` #30. Lalu regresi penuh ronde 2+. **13 AC milik stage (AC-13..AC-25): 12 PASS, 1 MANUAL (AC-23), 0 FAIL, 0 tidak bisa diverifikasi. Verdict PASS.**

- **Kode aplikasi identik dengan ronde 2** (dicek sendiri): FE HEAD `7ca714504`, `git status` FE hanya 2 dok (`027-...md` mtime 06:32, `_api.md` 06:32), `find src public package.json -newermt '2026-10-26 05:30'` kosong, build staging
  `/tmp/ksp-react-v5-build` 05:44 UTC >= semua berkas src (harness: "Build staging ... >= commit FE 7ca714504"). BE HEAD `fba6a5883`; 3 berkas BE item (hash `2683dd94`, `ad1a73a5`, `0ccb0c77`) mtime 05:02-05:03, sebelum QA BE r2 (05:32); 3 berkas kotor
  developer di `baseline/be.hashes` hash-nya SAMA; `features/ED-1251-*` tidak disentuh (berkas kotornya sudah ada di awal).
- **D-1 ditutup, dengan satu catatan lingkup (lihat "Catatan lingkup AC-25")**: fakta orkestrator terbukti dari kode FE; bagian FE AC-25 dinilai pada layar display setting FE yang ada + respons index dari sesi browser; 2 test AC-25 dijalankan ulang, hijau
  (holder dan non-holder); butir 23 dapat dijalankan manusia dan cocok dengan aplikasi (satu catatan redaksi, O-1, tidak memblokir).
- e2e ED-1250 penuh: **18 OK / 0 GAGAL** pada run akhir `work/e2e/ED-1250-archive-filter-verification-status-20261026-070046/index.html` (14 flow `[default]`, 2 `[user2]`, 2 smoke); tanpa 5xx, tanpa galat konsol/halaman, tanpa entri allow-list.
  Run penuh pertama (`...063725`) 17 OK / 1 GAGAL = flake infrastruktur (lihat P-1), test itu lulus sendiri (`...064913`) dan di run penuh kedua.
- Regresi BE `run.php ED-1250`: **PASS=18 FAIL=0 SKIP=1** (SKIP = ISO-1, satu DB). Teardown C-8 PASS.
- Dibawa dari ronde 2 (kode FE/BE identik, tidak diulang): build `fe-build.sh build` (exit 0), unit 5 suite / 341 PASS + ED-1150 14 PASS, e2e ED-1125 (18 OK) dan ED-1069 (5 OK), regresi BE ED-1027 (12 PASS + AC-22 usang) dan ED-1028 AC-28 PASS.

## Hasil per AC

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-13 (rev R-1) | e2e id + en + smoke: popover persis `[Nama,Tipe,Tanggal Dimodifikasi,Tampilkan Transaksi Terkait,Status Verifikasi]` (en padanannya), select kosong, 2 opsi sesuai bahasa, Cari tanpa memilih = tanpa key; respons `queries` = `current_queries` DB (`verificationStatus` terakhir); folder/hasil cari/drawer Display Setting tanpa error | PASS | run akhir `...070046` "AC-13 (id)", "AC-13 (en)", smoke `[default]` |
| AC-14 | e2e root + folder A (Unverified/Verified): 1 GET dengan key saja, tanpa `id_archive`, folder root tetap semua, `total` = oracle SQL, di folder A `id_archive` tetap | PASS | `...070046` "AC-14 root" |
| AC-15 | e2e subfolder/breadcrumb/Kembali ke Arsip membawa key; Reset tetap di folder; scan QR hanya `query` | PASS | `...070046` "AC-15" |
| AC-16 | e2e + Nama / + Tipe -> `id_archive` null, folder cocok tetap, dokumen hanya status itu; buka folder dari hasil -> hanya `verificationStatus` | PASS | `...070046` "AC-16" |
| AC-17 | unit helper + kedua pemakai (`archive.function`, `ArchivePage`, `ArchiveTable`): **dibawa dari ronde 2** (kode identik) | PASS | r2 `work/ed1250fe2/jest-r2.out`: 5 suite / 341 tes PASS |
| AC-18 | e2e picker Pindahkan + Pilih Folder (pindah massal): kosong saat dibuka, filter di root/folder/breadcrumb/Reset/+Nama, semua folder tetap, terpilih/tanpa View terkunci, Pindahkan sukses dengan filter aktif; "Status Verifikasi" terakhir di popover picker | PASS | `...070046` 2 test "AC-18"; picker Pindahkan juga sendiri `...064913` |
| AC-19 | e2e `[user2]` (preset tanpa Opname Document, EN): `queries` root/folder/hasil cari/picker tanpa `verificationStatus`; popover tanpa select; Displayed Filters tanpa entri; simpan PUT 200; DB `verificationStatus` tetap terakhir, `documentVerified` tetap | PASS | `...070046` "[user2] AC-19" |
| AC-20 | e2e pemegang: sembunyikan -> popover halaman & picker tanpa select, tampilkan -> kembali; `cds-restore` memulihkan persis | PASS | `...070046` "AC-20" |
| AC-21 | e2e folder NOV + Verified -> `.ant-empty`, tanpa error, induk tetap (halaman dan picker) | PASS | `...070046` "AC-21 halaman", "AC-18 Pilih Folder" |
| AC-22 (FE+BE) | e2e dengan filter aktif: kolom Document Verified, V/T + modal folder, ringkasan + modal All, halaman 2, Reload, Letakkan/Serahkan/Terima, Pindahkan folder (SQL). Regresi BE ED-1027/ED-1028: **dibawa dari ronde 2** | PASS | `...070046` 3 test "AC-22"; r2 `be-reg-1027.out`, `be-reg-1028.out` |
| AC-23 | letak/gaya popover EQUAL = penilaian manusia; ada di Daftar tes UI butir 1 (sudah urutan R-1) | MANUAL (Gate 2) | `027-feat-filter-verification-status.md` `## Daftar tes UI` butir 1 |
| AC-24 | e2e ED-1125 18 OK, ED-1069 5 OK, unit ED-1150 14 PASS: **dibawa dari ronde 2** (kode identik); e2e sendiri (penanda DOM hilang saat filter berganti, bertahan saat Reload) diulang | PASS | r2 `...ED-1125-...061125`, `...ED-1069-...061817`; `...070046` "AC-22"/"AC-18" |
| AC-25 (R-2, FE) | **Diulang ronde ini.** 2 test e2e: `[default]` pemegang dan `[user2]` non-pemegang. (a) sumber FE (dibaca di dalam test): `routes.js` tanpa `DisplaySettingPage`, `paths.js` tanpa `displaySetting`; (b) `GET setting/display-columns?search=documentArchive` dari dalam sesi browser: pemegang 200, 3 baris, `documentArchive` = DB (queries R-1, kolom memuat `documentVerified`); non-pemegang 200, 1 baris, tanpa `verificationStatus`/`documentVerified` di `current_*`/`available_*`, item lain + urutan = DB tanpa kedua entri, modul seluruh-modul (`OpnameHistory`/`OpnameResult`) tidak ada, index tanpa search 100 baris dari total 362; (c) drawer Display Setting Archive (komponen `DisplaySettingDrawer`, `GET show documentArchive` 200): pemegang -> Filter `[Nama,Tipe,Tanggal Dimodifikasi,Tampilkan Transaksi Terkait,Status Verifikasi,Dimodifikasi Oleh]` + kolom memuat "Dokumen Terverifikasi"; non-pemegang (EN) -> filter `[Name,Type,Date Modified,Show Related Transaction,Modified By]`, kolom `[Name,Date Modified,Type,Salesman,Status,Modified By]`, tanpa kedua entri; tanpa 5xx, tanpa galat konsol | PASS (menurut lingkup Catatan implementasi 2026-10-26; lihat catatan lingkup) | `...063600` (2 test, 2 OK), `...070046` (idem); `screenshots/default/ac25-01-drawer-pemegang.png`, `screenshots/user2/ac25-02-drawer-non-pemegang.png`; sisi API = QA BE r2 AC-25 + `run.php ED-1250` AC-25 PASS (746 asersi) |

## Pemeriksaan D-1 (klaim orkestrator, dibuktikan dari kode FE sendiri)

| Klaim | Bukti | Hasil |
|---|---|---|
| `DisplaySettingPage` tanpa route/path/menu | `grep -rn -i "displaySetting\|DisplaySettingPage" src/routes src/configuration` hanya `endpoints.js:2050-2055` (kunci endpoint); `routes.js`, `paths.js`, `permissions.js`, `menus.js` kosong; tidak ada `DisplaySettingPage` di luar `src/containers/DisplaySetting/` (selain re-export `index.js:5`) | benar |
| Halaman itu memakai `paths.displaySetting` yang tidak ada | `DisplaySettingPage/index.js:21` `activeSetupSpaceMenu: paths.displaySetting` (undefined di `paths.js`) | benar |
| Sejak commit awal | `git log -S"DisplaySettingPage" -- src` = hanya `c61af18d0` (Initial Setup, 2023-01-18) | benar |
| Satu-satunya pemanggil FE index `GET setting/display-columns` | `getDisplaySettings` dipakai di `displaySetting.api.js:6` dan satu-satunya konsumen `DisplaySettingPage/index.js:44` (`useTable(fetch.getDisplaySettings)`); `DisplaySettingDrawer` memakai `getDisplaySetting`/`putDisplaySetting` (show/update), `Shortcut`/`Modal` hanya `postDisplaySetting`, `apiHook.js:670` `useDisplaySetting` = show | benar |
| Drawer Archive = layar display setting FE yang ada | `ArchivePage/index.js:490,552` `ButtonDisplaySetting` (tautan "Pengaturan Tampilan"/"Display Setting"), `:661` `DisplaySettingDrawer` -> `GET`/`PUT setting/display-columns/documentArchive` | benar |

Akibatnya R-2 (filter index) tidak punya efek yang terlihat di layar FE; efek nyata ada di API (lulus). Digest `_api.md` #30 sudah memuat penjelasan ini (`_api.md:1169`).

### Daftar tes UI butir 23 (`027-feat-filter-verification-status.md`)
Dapat dijalankan manusia: tombol ada di kanan baris breadcrumb (layar >= md; di layar kecil di atas kartu, `showInSmallScreen`), drawer terbuka dengan dua panel; urutan dan label yang ditulis butir 23 cocok dengan screenshot dan notes e2e
(pemegang: "Status Verifikasi" terakhir sebelum "Dimodifikasi Oleh" di Filter, "Dokumen Terverifikasi" di Kolom; non-pemegang: keduanya tidak ada, daftar lain tetap). Prasyarat peran (pemegang / role tanpa Opname Document) = pembukaan Daftar tes UI dan butir 21.
**O-1 (redaksi, tidak memblokir):** butir 20 dan 23 menyebut "ikon pengaturan di kanan breadcrumb", padahal kontrolnya tautan teks biru "Pengaturan Tampilan" / "Display Setting" tanpa ikon; butir 20 menyebut "tab Displayed Filters", padahal dua panel
berdampingan ("Kolom yang Ditampilkan" / "Filter yang Ditampilkan"; EN "Displayed Columns" / "Displayed Filters"). Saran redaksi: "tautan 'Pengaturan Tampilan' di kanan breadcrumb" dan "panel Filter yang Ditampilkan". QA tidak mengubah dok.

### Catatan lingkup AC-25
Teks baris AC-25 di spec (R-2) tetap berbunyi "halaman Setup > Display Setting FE terbuka tanpa error". **Layar itu tidak ada** (terbukti di atas), jadi klausa harfiah itu tidak dapat dijalankan oleh siapa pun. Penilaian PASS saya bertumpu pada
Catatan implementasi 2026-10-26 yang **ditulis orkestrator** (bukan keputusan developer): bagian FE AC-25 = layar display setting FE yang ada (drawer Archive) + respons index dari sesi browser. Bukti untuk lingkup itu lengkap dan hijau, tetapi
penafsiran ulang ini sebaiknya dikonfirmasi developer di Gate 2 / batch review (butir 23 = bukti manusia; bila developer ingin halaman itu dipasang: route + menu Setup = item terpisah, di luar ED-1250).

## Defect

Tidak ada. D-1 (ronde 2) ditutup menurut catatan lingkup di atas; tidak ada defect baru, tidak ada respons 5xx.

## Pengamatan (bukan defect ED-1250)

- **P-1 (flake infrastruktur)**: run penuh pertama `...063725`: 17 OK / 1 GAGAL, test "AC-18 picker Pindahkan" gagal dengan `TimeoutError: page.screenshot: Timeout 15000ms exceeded` pada tangkapan layar ke-3 (`ac18-03`), bukan asersi, tanpa 5xx;
  9 GET sebelumnya semua 200. Diulang sendiri `...064913` OK; run penuh kedua `...070046` 18/18 OK. (Ronde 2 juga mencatat satu flake di test yang sama, beda sebab.)
- **P-2 (usang, dibawa dari r1/r2/QA BE r2)**: ED-1027 `scenario9` AC-22 FAIL usang (pembanding lama; `queries` pemegang 5 vs non-pemegang 4 entri sejak R-1); pembanding AC-8 ED-1250 menggantikannya (lulus). Tidak diulang ronde ini.
- **P-3 (pra-ada, ED-1028 O-5)**: index display-columns diurutkan `updated_at desc` tanpa tie-breaker (301 baris kembar); e2e AC-25 mencari baris lewat `search=documentArchive`, bukan posisi.
- **P-4 (data)**: root `api_sidomaju` berisi folder asli (termasuk `E2E-ARSIP-*` milik run lama); `total` dibandingkan dengan oracle SQL.

## Build, unit, e2e fitur lain

| Langkah | Ronde 3 | Bukti |
|---|---|---|
| `scripts/fe-build.sh build` | **tidak diulang**: kode FE identik (lihat Ringkasan); hasil r2 exit 0 "Compiled successfully." dipakai, build staging 05:44 UTC >= commit | r2 `work/ed1250fe2/build-r2.out` |
| unit `archive.function`, `ArchivePage`, `ArchiveTable`, `ArchiveMoveModal`, `ArchiveBulkMoveModal` + ED-1150 | **dibawa dari r2**: 341 PASS + 14 PASS; suite penuh proyek tidak diulang (lihat catatan r2: 4 suite pra-ada non-Archive merah di r1) | r2 `jest-r2.out`, `jest-ed1150-r2.out` |
| e2e ED-1125 / ED-1069 | **dibawa dari r2** (18 OK / 5 OK), berkas fitur lain tidak diubah | r2 `...ED-1125-...061125`, `...ED-1069-...061817` |
| e2e ED-1250 | **diulang penuh**: 18 OK / 0 GAGAL | `...070046` |

## Galeri e2e & regresi BE

- ED-1250 akhir: `work/e2e/ED-1250-archive-filter-verification-status-20261026-070046/` (index.html, report.json, screenshots; 18 OK). Ronde ini juga: AC-25 saja `...063600` (2 OK), run penuh pertama `...063725` (17 OK / 1 flake), AC-18 picker sendiri `...064913` (1 OK).
- BE (`work/ed1250fe3/`): `be-reg-1250.out` PASS=18 FAIL=0 SKIP=1 (C-0, AC-1..AC-12, ISO-1 SKIP, X-1..X-3, AC-25 746 asersi, C-8; `work/qa-http/ED-1250-20261026140033.json`); `c8.out` (`...141253.json`). Tanpa 500.
  Output e2e: `e2e-ac25.out`, `e2e-full.out`, `e2e-ac18.out`, `e2e-full2.out`. ED-1027/ED-1028 tidak diulang (kode BE identik; hasil r2 dibawa).
- Skrip permanen tidak diubah ronde ini. Jalankan: `scripts/e2e/run.sh ED-1250 --profile default,user2 --workers 1`; BE: `"$PHP_BIN" scripts/qa-http/run.php ED-1250`.

## Data uji, perubahan di luar baris sendiri, teardown

- Dibuat dan dibuang per run e2e (`fixture.php up/down`, prefiks `QA50-`, jurnal sendiri): 60 baris `archives` (+ dokumen/lokasi/izin) dan preset `QA50-ROLE-E2E-NOOD`; setiap teardown fixture melapor "tanpa sisa, pemulihan persis".
- Diubah sementara dan dipulihkan persis (oleh test/fixture): `user_roles` QA_USER2, `users.language` QA_USER, baris cds `documentArchive` (AC-19/AC-20 menyimpan lewat drawer; `cds-restore`), dokumen/folder fixture. **Tidak ada baris di luar milik sendiri yang dihapus atau di-reset; tidak ada tabel dikosongkan; Updater tidak dijalankan QA.**
- **Teardown C-8 (diperiksa terakhir, sesudah semua e2e dan regresi BE):** `run.php ED-1250 --only=C-8` PASS (15 asersi: `archives`/`archive_documents`/`archive_permissions`/`roles`/`role_permissions` sisa QA50-/QA% = 0, cds memuat `verificationStatus` + `documentVerified` urutan R-1, keadaan di luar baris sendiri sama dengan awal run,
  `updater_logs` 99 = awal, tanpa jurnal basi di `features/*/qa`); `fixture.php cds` = id 2586, `updated_at` 2026-10-26 12:04:16 (sama dengan awal ronde), `current_queries=[name,type,updatedAt,showRelatedTransaction,verificationStatus]`; token QA aktif 0
  (runner "masih aktif: 0"; e2e: log-out default dan user2 `dicabut=ya`, 2/2 pada tiap run).

## Yang tidak bisa diverifikasi (dan kenapa)

1. AC-23 (letak/gaya popover EQUAL): penilaian visual manusia, MANUAL Gate 2 (butir 1 Daftar tes UI).
2. Klausa harfiah AC-25 "halaman Setup > Display Setting terbuka": layar tidak ada (D-1); dinilai menurut Catatan implementasi (lihat catatan lingkup), konfirmasi developer disarankan.
3. e2e ED-1150 (`run-sam.sh`, login `sam`) tidak dijalankan (Tambahan Gate 1); `listKey` dibuktikan unit (r2) + penanda DOM e2e sendiri.
4. Desain `brief/` tidak ada untuk filter ini (sumber `jira.md`): struktur/alur dicek, tampilan tidak dinilai.
5. Isolasi tenant: `QA_DBS` satu DB; bukan lingkup stage FE.

## Riwayat ronde

- Ronde 1 (2026-10-24): 11 PASS / 1 MANUAL / 0 FAIL (AC-13..AC-24); e2e ED-1250 16 OK, ED-1125 18 OK, ED-1069 5 OK; unit modul Archive hijau bila sendiri (4 suite pra-ada non-Archive merah); regresi BE hijau (ED-1027 AC-22 usang); teardown C-8 PASS.
- Ronde 2 (2026-10-26, revisi review R-1/R-2): AC-13 diulang urutan R-1 (5 asersi e2e terbalik, kontrol spec r1 gagal seperti diharapkan), AC-25 baru: bukti pengganti lulus, halaman tidak ber-route = D-1 (FAIL); e2e ED-1250 18 OK, ED-1125 18, ED-1069 5; unit 341 PASS;
  regresi BE ED-1250 PASS=18, ED-1027 12 PASS + 1 usang, ED-1028 AC-28 PASS; teardown C-8 PASS.
- Ronde 3 (2026-10-26): D-1 dicek ulang (klaim kode FE terbukti, butir 23 dapat dijalankan, AC-25 e2e 2/2 OK ulang) -> PASS; kode identik r2; e2e ED-1250 penuh 18/18 (run pertama 17 + 1 flake screenshot); BE ED-1250 PASS=18/SKIP=1; C-8 PASS; sisanya dibawa dari r2.
