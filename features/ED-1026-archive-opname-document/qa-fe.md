---
key: ED-1026
scope: fe
round: 1
verdict: PASS
counts: {pass: 13, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

QA FE revisi "opname tanpa draft" (FB-3, FB-2, FB-10, FB-11, FB-8a, N-4), ronde 1 revisi 2026-10-09. FE = HEAD `442ae9851` + working tree (22 berkas, `changed.txt`); BE = qa-be.md ronde 2 (PASS). AC scope fe: `[FE]` AC-4, 7, 8, 13, 14, 17, 29, 30; `[BE+FE]` AC-3, 11, 22, 24, 28, 31.

- **Verdict PASS.** 0 defect AC, 0 respons 5xx dari endpoint opname (log BE 12:40-13:40 WIB: 174 entri ERROR, **0** menyebut `Opname*`/`archive_opname`; sisanya collation ED-1071, `websockets_statistics`, OAuth `force_login`, masukan aneh ED-1070).
- `counts`: PASS 13 (AC-4, 7, 8, 11, 13, 14, 17, 22, 24, 28, 29, 30, 31); MANUAL 1 (AC-3: bagian FE + BE lulus, "tukar lisensi sungguhan" = Gate 2 epic, EPIC K-2 b, `PROFILES` kosong). Butir MANUAL dalam AC-13/14/29 (kamera, scanner USB, warna, tampilan) ada di `## Daftar tes UI` task 005/018, tidak mengubah hitungan.
- Build staging bersih; e2e **main 15 OK / 0 GAGAL / 1 SKIP, norole 2 OK / 0 GAGAL / 14 SKIP**; Jest Archive 336/338 (2 gagal bukan milik item); regresi BE ED-1026 **39/39**.
- Spec e2e + fixture ditulis ulang untuk alur tanpa draft, **tidak bergantung data developer** (diuji juga dengan data developer simulasi).
- Temuan non-blokir: F-1 (butir manual lisensi AC-3 masih tidak ada di `## Daftar tes UI`, dicek ulang), F-2 baru (daftar tes UI task 004-006 masih alur draft), O-1 (rev), O-2.
- Data uji `api_sidomaju` dikembalikan persis. Tanpa penolakan izin/classifier.

## Hasil per AC

Spec e2e: `qa/e2e/ED-1026-opname.spec.js` (15 test) + `fixture.php`. "run main" = `work/e2e/ED-1026-archive-opname-document-20261009-055322/`; norole = `...-055746`. "BE" = regresi `qa/scenario*.php` (`work/qa-fe-ed1026r-regress-1026.txt`).

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-3 `[BE+FE]` | (a) Role ber-`Opname Document`: "+" root memuat Opname Dokumen tepat satu, sesudah Tambah Folder/Serahkan/Terima (item sesudahnya = ED-1029, tidak diasumsikan); menu baris folder = Info, Pindahkan, Lihat, Opname, Hapus; "+" dalam folder; subfolder punya Opname; baris dokumen tanpa; **membuka menu = nol request `opnames/*`**. (b) Hanya-View = Info, Lihat, Opname, opname jalan tanpa request tulis; tanpa View = tanpa tombol. (c) run `norole`: menu tanpa Opname, **`.modal-opname-archive` tidak ada di DOM, nol request `opnames/*` sepanjang sesi** (FB-8a); unit `ArchivePage` AC-3 hijau. (d) BE `GE0114` = regresi `AC-3` 88 asersi. (e) Guard lisensi di kode tak berubah (`menus.js:169`, `routes.js:2088-2090`) | PASS (FE+BE), **MANUAL** (tukar lisensi sungguhan, Gate 2; lihat F-1) | run main test 1 & 3; run norole; screenshot `ac3-*`; BE `AC-3 PASS 88` |
| AC-4 `[FE]` | `routes.json` + monitor otomatis di semua flow: list, "+", menu baris, Step 1, 2, modal kamera, Step 3 - desktop 1440, 768 dan **375 px** (modal kamera juga di 375). Error console/page/request gagal/respons >= 400: 0, kecuali 400/404 yang sengaja dibuat (414, 415, 416, folders 404), dikeluarkan sesudah ditegaskan terjadi. Tanpa allow-list | PASS | run main `report.json` `errors: []` semua test; `ac4-modal-kamera.png`, `ac4-modal-kamera-375.png`, `smoke-_archives.png` |
| AC-7 `[FE]` | Step 1 root (id): isi tabel = respons API (urutan, nama, centang = `is_default_checked`, "Belum/Sudah diopname hari ini", Dokumen = `document_count`, footer N/M dihitung dari API); centang-semua + lepas (baris root tetap); BETA: "(dicentang ulang)" + Lanjut aktif + teks, T1 dicentang -> footer 3 folder · 4 dokumen, toggle mati + teks merah; ALPHA: sub-subfolder tak bisa dipilih, S2/S3 dilepas = 2 folder · 5 dokumen; body pratinjau (`isContinue` per folder) sesuai | PASS | run main tests Step 1 root, BETA, ALPHA; `ac7-*.png` |
| AC-8 `[FE]` | Folder tanpa subfolder + belum diopname (fixture `0QA26-ZULFA`, bukan ZULFA developer): `skip_select_folder` true, stepper 2 langkah, **tag langsung** `0QA26-ZULFA`, tombol kiri Batal, request sejak dibuka **hanya** `GET opnames/folders` + `POST opnames/preview` (body `folders: []`); Batal tanpa scan = tutup tanpa dialog dan tanpa request; dengan scan = dialog (Tidak = tetap, Ya = tutup) tanpa request tulis | PASS | run main test ZULFA; `ac8-zulfa-step2-tanpa-step1.png` |
| AC-11 `[BE+FE]` | FE: kode sama (huruf kecil + spasi akhir, sesudah jendela 2 dtk) di input dan di modal kamera = **tanpa request**, tanpa baris/angka/pesan baru; kamera menampilkan baris lama; body Konfirmasi memuat `scans` urut lama -> baru. BE: `AC-11 PASS 22` | PASS | run main tests ALPHA dan GAMMA; BE `AC-11` |
| AC-13 `[FE]` | Ketik + Enter; scanner USB tiruan (ketikan cepat) dengan fokus di luar input dan sesudah memilih tag filter; ikon scan membuka kamera yang tetap terbuka sesudah scan, "Scan terakhir" berganti; pencarian Archive tidak terpicu selama modal terbuka dan menyala lagi sesudah ditutup | PASS (e2e); kamera nyata + scanner USB fisik = MANUAL (task 005 butir 12-14) | run main test GAMMA; `ac13-modal-kamera-sesudah-scan.png` |
| AC-14 `[FE]` | Step 2: `Tag.CheckableTag` Semua/Terverifikasi/Tidak ditemukan/Tidak valid, **tepat satu aktif** (`ant-tag-checkable-checked`), jumlah = kartu sesi, tanpa `Segmented`, klik menyaring + fokus kembali ke input, klik tag aktif tidak mengosongkan; `tr.row-invalid`/`row-not-found` (1/1); tag judul "<F> + 3 subfolder" (3 dicentang), "+ 1", "+ 2", "<F>" tanpa subfolder, root "Semua Arsip · root + 2 subfolder"; 375 px: tag membungkus 2 baris, `scrollWidth <= clientWidth` | PASS (e2e); warna baris = MANUAL (task 005 butir 9) | run main tests GAMMA, ALPHA, Steps; `ac14-*.png` |
| AC-17 `[FE]` | Step 3: filter awal Discan, 5 tag + jumlah (Semua 6, Discan 6, ...), kartu + peringatan dari pratinjau; klik tag = `POST preview/documents` `result=` snake_case `page=1` (juga dari halaman 2), klik tag aktif tanpa request; Back ke Step 2 tanpa kehilangan scan; Konfirmasi (Popconfirm) = **tepat satu `POST opnames`** (`scans` urut lama -> baru, `selectedAt` = `selected_at` GET folders terakhir), toast, modal tertutup tanpa dialog, list dimuat ulang; DB +1 sesi, GD1-3 verified, angka final; paginasi page=2; ARCHIVE415 (`selectedAt` diubah di jaringan jadi kemarin): toast, tetap di Step 3, DB sama | PASS | run main tests GAMMA, EPS; `ac17-*.png` |
| AC-22 `[BE+FE]` | B (UI) pilih DELTA + scan, A (API) konfirmasi sesudah B memuat Step 1: B Konfirmasi = 400 `ARCHIVE414` (toast folder/user/jam), kembali ke Step 1 (stepper 3 langkah, DELTA "Sudah diopname hari ini", "(dicentang ulang)" + Lanjut aktif), **GET folders dimuat ulang (`selected_at` baru)**, scan tetap, Next = `POST preview` (bukan Konfirmasi), Konfirmasi kedua 200 dengan `selectedAt` baru; DB dua sesi; **kirim ulang body yang sama = 400 `ARCHIVE414`, tetap dua sesi**. BE: `AC-22 PASS 68` + `EXTRA-CONCURRENT` | PASS | run main test DELTA; `ac22-*.png`; BE `AC-22` |
| AC-24 `[BE+FE]` | Scan Verified + Not found + Invalid lalu X -> "Tidak" (tetap, scan tetap) -> "Ya"; Step 2 -> Back -> Batal; scan lalu Back lalu Batal (dialog); Step 3 tanpa scan = X tanpa dialog, dengan scan = dialog; buka lagi = angka 0 dan tabel kosong. Seluruh rangkaian: hanya `GET folders`, `POST preview`, `POST preview/documents`, `POST scan`; **nol POST `opnames`/PUT/DELETE**; DB (3 tabel opname, kolom verifikasi, sesi fixture) identik; gagal muat folder (404) menutup modal tanpa request lain | PASS | run main test AC-24 (+ `expectDbUnchanged` di tiap test); `ac24-dialog-batal.png` |
| AC-28 `[BE+FE]` | id: tag root "Semua Arsip · root", baris pertama Step 1 "Semua Arsip", kolom Folder dokumen root "Semua Arsip", peringatan root "Semua Arsip: N dokumen ..." (N = `unverify_count` API; bentuk teks diturunkan dari `is_opnamed_today`), tanpa "All Archive". en (`users.language` fixture, dipulihkan): "All Archive · root", baris "All Archive", peringatan root "All Archive ..." tanpa "Semua Arsip". `ARCHIVE414` root `[0]` = BE (`AC-28 PASS 46`, `EXTRA-LANG 97`); tidak diulang di e2e (butuh konfirmasi root yang menyentuh 4.234 dokumen root developer) | PASS | run main tests Step 1 root dan en; `ac28-*.png`; BE `AC-28` |
| AC-29 `[FE]` | `.steps-container` berisi `ant-steps-small` + `ant-steps-navigation`; desktop: wadah 803 px < body modal 1100 px, `scrollLeft` 0; **375 px**: stepper (803 px) terpotong di wadah 351 px dan **tergulir otomatis**: Step 1 `scrollLeft` 0, Step 2 270, Step 3 452 (judul langkah aktif selalu di dalam wadah), Back ke Step 2 lalu Step 1 -> kembali ke 0; screenshot 375/768/desktop | PASS (e2e); tampilan = MANUAL (task 018 butir 2, 7) | run main test Steps; `ac29-steps-*.png` |
| AC-30 `[FE]` | Riwayat ED-1028 (Dokumen Terverifikasi -> Lihat semua riwayat -> klik sesi -> Hasil Opname): 5 `CheckableTag`, jumlah = `result_counts` API, default Discan, tanpa `Segmented`; klik Semua = `GET documents?result=all&page=1`, Tidak ditemukan = `result=not_found&page=1`, tag aktif tanpa request; 375 px membungkus 2 baris tanpa gulir horizontal. Test komponen `ArchiveOpnameResult` + `ArchiveOpnameHistory` hijau | PASS | run main test AC-30; `ac30-riwayat-*.png` (username QA disamarkan di DOM) |
| AC-31 `[BE+FE]` | BE: `EXTRA-SCOPE PASS 32` (kunci tepat `is_root, folder_name, subfolder_count` di preview, show, index, `last_sessions`). FE: unit `archive.function.test.js` `getScopeTag` (root N=0 "Semua Arsip · root", root N=3, "ZULFA", "Backup + 3 subfolder") hijau; e2e: `GET opnames/{id}` sesi GAMMA = `{is_root:false, folder_name, subfolder_count:0}`, sesi HIST dan OMEGA+S1 via API; kolom Scope riwayat "0QA26-OMEGA + 1 subfolder"; tag modal Step 2-3 (lihat AC-14) | PASS | run main tests GAMMA, riwayat; BE `EXTRA-SCOPE` |

Cek ulang O-1 lama (tag Step 2/3 menunggu `GET opnames/{id}`): **hilang**, tag langsung "<F> + N subfolder" (asersi tanpa poll di test ZULFA/GAMMA/ALPHA); sisa berbeda = O-1 (rev). F-1: lihat Defect.

## Defect

Tidak ada defect AC, tidak ada 5xx.

**F-1 (dokumentasi, rendah, masih terbuka)**: AC-3 bagian MANUAL "tukar lisensi sungguhan sekali di Gate 2 epic" (tanpa Salesman Activity: menu Archive tidak ada, route tertolak, endpoint opname 403 `GE0114`) tetap tidak ada di `## Daftar tes UI` task ED-1026 mana pun (`ksp-react/docs/specs/Archive/003-006`, `016-018`; dicek ulang). Satu-satunya butir lisensi = task 012 butir 23 (ED-1029, hanya Set Permission). Usul: satu butir di task 018 (ED-1093) atau daftar Gate 2 epic. Tidak mengubah verdict.

**F-2 (dokumentasi, rendah, baru)**: `## Daftar tes UI` task 004, 005, 006 masih menjelaskan alur lama: `004:6,40,47,49,51` (POST lalu PUT draft, `DELETE` saat batal), `005:7,21,26,40` (`Segmented`), `006:7,20,32,40,41,45,47,49` (`PUT .../confirm`, `Segmented`, ARCHIVE415 lewat "mundurkan tanggal draft", `DELETE`). Task 016-018 menggantikannya tetapi 004-006 tidak diberi catatan "digantikan". Daftar lama dijalankan di Gate 2 = gagal di butir usang. Pemilik: ED-1091/1092/1093 (docs FE).

Observasi (bukan defect):
- **O-1 (rev, kosmetik)** Step 3: sampai `POST opnames/preview` Step 3 kembali, kartu angka memakai ringkasan Step 2 (contoh BETA: "0 Terverifikasi sesi ini / 4 Belum discan" padahal 1 scan Verified); tombol Konfirmasi nonaktif selama itu (diasersikan), lalu angka benar. `ArchiveOpnameModal/index.js:343,489`. Footer teks Step 2 juga menunggu pratinjau.
- **O-2** Teks en peringatan: "1 documents" (`archive.locale.js:116,118`).
- **O-3** php-fpm `pm.max_children (5)` tercapai selama run e2e; tanpa dampak pada hasil.

## Galeri e2e & regresi BE

**Galeri kanonik: `/var/www/opname-archive/agentic/work/e2e/ED-1026-archive-opname-document-20261009-055322/index.html`** (15 OK, 0 GAGAL, 1 SKIP = test norole; 40 screenshot). Run norole: `.../ED-1026-archive-opname-document-20261009-055746/index.html` (2 OK, 0 GAGAL, 14 SKIP). Ketahanan data developer: `.../20261009-054825` = run penuh dengan data developer **simulasi** (sesi root hari ini + 2 dokumen verified, id `QADEVSIM*`, dibuang persis): 13 OK, 2 GAGAL (asersi QA saya: bentuk teks peringatan root berbeda bila root sudah diopname hari ini, diperbaiki dengan menurunkan varian dari `is_opnamed_today`), ulang dua test itu `.../20261009-055246` = 2 OK. Perintah: `E2E_WORKERS=1 scripts/e2e/run.sh ED-1026`; `QA26_MODE=norole ...`.

| Langkah | Hasil | Bukti |
|---|---|---|
| `scripts/fe-build.sh build` (staging) | "Compiled successfully", exit 0, 78 dtk; tanpa berkas FE lebih baru dari build; peringatan harness "MUNGKIN USANG" hanya karena 22 berkas belum di-commit | `work/qa-fe-ed1026r-build.out` |
| `yarn test --watchAll=false --maxWorkers=2 src/containers/Archive` | 11 suite: 9 lulus, 2 gagal; 336/338 test. (1) `ArchiveVerificationModal` "Tutup" = stale ED-1027 (footer dihapus commit `7fa63869d`; gagal juga sendirian); (2) `ArchivePage` "tooltip ikon perisai" `findByRole('tooltip')` timeout 1 dtk = flake ED-1025 C-1 (sendirian: gagal, gagal, lulus; lulus di suite penuh). Semua suite ED-1026 hijau: `ArchiveOpnameModal` index + function, `ArchiveOpnameResult`, `ArchiveOpnameHistory`, `archive.function`, `ArchivePage` AC-3/FB-8a | `work/qa-fe-ed1026r-test-archive.txt`, `...-archivepage*.txt`, `...-rerun-verifmodal.txt` |
| Full suite `--maxWorkers=2` (sekali) | 79 suite: 73 lulus, 6 gagal; 1659 test: 1653 lulus, 6 gagal (240 dtk). 4 suite = baseline lama di luar diff (`BatchOut`, `production.function`, `InterbankTransferForm`, `CustomerPurchaseOrderView`; gagal sendirian, tanpa berkas di `git status`), `ArchiveVerificationModal` (stale), `RaptorPendingItemsPanel` (timeout beban, **14/14 lulus sendirian**) | `work/qa-fe-ed1026r-test-full.txt`, `...-rerun-baseline4.txt`, `...-rerun-raptor.txt` |
| Regresi BE ED-1026 (`run.php ED-1026`, fpm tanpa reload) | **PASS=39 FAIL=0** (sama dengan qa-be.md ronde 2) | `work/qa-fe-ed1026r-regress-1026.txt`, `work/qa-http/ED-1026-20261009130238.json` |

**Regresi item lain** (dijalankan apa adanya, berkas tidak diubah, gate1 §5):

| Item | BE | e2e |
|---|---|---|
| ED-1024 | 26/26 PASS | 3 keadaan: jog 2 OK, super 2 OK, noperm 1 OK, 0 GAGAL |
| ED-1025 | 36 PASS + 4 FAIL = **HTTP 503 dari Apache** (X-15, AC-29, AC-38, X-16) tepat saat Apache dan php-fpm di-restart pihak lain (log Apache 06:08:33, php-fpm 06:08:19/28 UTC; bukan proses saya); ulang 4 skenario itu = 4 PASS, jadi **40/40** | default 11 OK + 1 GAGAL (stale), user2 5 OK |
| ED-1027 | 12 PASS / 8 FAIL / 1 SKIP (identik qa-be) | default 5 OK / 6 GAGAL, user2 1 OK / 1 GAGAL (stale, tabel di bawah) |
| ED-1028 | 2 PASS / 20 FAIL (identik qa-be) | 5 OK / 8 GAGAL (default + user2; stale, tabel di bawah) |
| ED-1029 | 29 PASS + 1 SKIP (X-ISO, 1 DB) | 22 OK / 0 GAGAL (default + user2) |

Semua kegagalan item lain = **skenario usang milik item itu**, bukan defect ED-1026 (0 x 5xx; BE 422 karena alur draft dihapus):

| Item | file:baris | Sebab | Pemilik |
|---|---|---|---|
| ED-1025 | e2e `ED-1025-folder-permission.spec.js:124` (AC-3 C) | oracle `if (has('Update Folder')) keys.push('set-permission')`; aturan ED-1029 = Folder Permissions | QA ED-1025 |
| ED-1027 | e2e `ED-1027-verifikasi.spec.js:327` (AC-13) | total dokumen 34.536 vs 33.267 (data developer berubah) | QA ED-1027 |
| ED-1027 | e2e `:618` (AC-16/19) | subjudul "All Archive — ..." kini "Semua Arsip — ..." (N-4, diputuskan) | QA ED-1027 |
| ED-1027 | e2e `:744` (AC-17/18/19) | Scope "<subfolder pertama> + N subfolder", kini "<folder> + N subfolder" (K-14 a) | QA ED-1027 |
| ED-1027 | e2e `:1028` (AC-20), `:685,:1065` (jalur error), `:1203` ([user2]) | "+" root tepat 4 item (ED-1029 menambah "Atur Izin per User"); `.ant-modal-footer button` (footer dihapus `7fa63869d`); "tiga tab" kini 2 (ED-1025 rev) | QA ED-1027 |
| ED-1027 | e2e `:1226-1261` (Confirm Opname GAMMA) | alur draft (`POST .../scan` + `PUT .../confirm`) dihapus FB-3 | QA ED-1027 |
| ED-1027 | BE `scenario1-columns-updater.php:69` (AC-1), `:169` (X-1); `qa_lib.php:537-542` `q27_session` (6 skenario) | kunci Updater terbesar kini `DeleteArchiveOpnameDraft`; `updater_logs` ditimpa; buat sesi tanpa `selected_at`/`scans` -> 422 | QA ED-1027 |
| ED-1027 | unit `ArchiveVerificationModal/index.test.js` "Tutup" | footer dihapus `7fa63869d` | QA ED-1027 |
| ED-1028 | e2e `ED-1028-opname-history.spec.js:39` (peta `S`, `rowKeys :321`) | teks Scope "<subfolder> + N" -> "<folder> + N subfolder" (K-14 a): AC-15, 16, 17, 18, 20, 23, 24 | QA ED-1028 |
| ED-1028 | e2e `:978,981,1019,1099,1367,1383,1387,1481,1484,1518` | `.ant-segmented-item` -> `CheckableTag` (FB-10) | QA ED-1028 |
| ED-1028 | e2e `:1343,:1412` ([user2] AC-21 "kolom EN") | selisih daftar header; penyebab tidak diisolasi (Scope baru satu-satunya perubahan terlihat di drawer) | QA ED-1028 |
| ED-1028 | BE `qa_lib.php:537-542` `q28_session` (20 dari 22) | sama dengan ED-1027 | QA ED-1028 |

## Data uji (dibuat, dihapus, dipulihkan)

- **DB `api_sidomaju` dikembalikan persis.** Snapshot awal vs akhir (hitungan + `CHECKSUM TABLE`): `archives` 49.148 (2374777730), `archive_documents` 49.126, `archive_locations` 48.037, `archive_permissions` 7, 3 tabel opname 0, `role_permissions`, `user_roles`, `employees`, `employee_locations`, `roles`, `updater_logs` = **identik**; 0 baris kolom verifikasi tak-default; 0 baris `0QA*`/`QA*`; tanpa jurnal sisa di semua `qa/e2e/`. Beda hanya di `oauth_access_tokens` (+37 token login yang semuanya dicabut; aktif tetap 2 milik developer, tidak disentuh) dan `users` (`updated_at`/`is_login` oleh login). Bahasa QA_USER ID dan QA_USER2 EN seperti semula.
- Dibuat dan dibuang oleh fixture ED-1026 (jurnal, hanya baris sendiri; teardown tiap run: "tidak ada sisa milik fixture, user/role/bahasa QA = snapshot"): 54 archives uji `0QA26-*`, sesi opname dibuat spec (GAMMA, DELTA x2, HIST, OMEGA, seed BETA), user QA ke lokasi Semarang sementara. **Data developer: tidak ada yang dihapus/di-reset.**
- Simulasi data developer (id `QADEVSIM*`, milik saya): 1 sesi root + 1 folder + 2 baris `archives` ber-verifikasi (nilai awal dicatat); dibuang persis, diverifikasi snapshot.
- Di luar item: `updater_logs` id 197 (`key_id` 1791357196) ditimpa regresi ED-1027 X-1 (`executed_at` -> `2026-10-09 13:14:18`, `execution_time` 0.002 -> 0.001); **dipulihkan** ke `2026-10-09 06:15:28` / `0.002` (satu UPDATE `id=197 AND key_id=1791357196`, baris penuh identik). Terulang tiap run ED-1027 (X-1).
- Fixture ED-1024/1025/1027/1028/1029: teardown masing-masing OK.

## Yang tidak bisa diverifikasi (dan kenapa)

1. Tukar lisensi sungguhan (AC-3): `PROFILES` kosong; MANUAL Gate 2 epic (F-1).
2. Kamera nyata dan scanner USB fisik (AC-13), penilaian warna `.row-invalid`/`.row-not-found` dan tampilan Steps/tag (AC-14, 29): MANUAL (task 005 butir 9, 12-14; task 018 butir 2, 7). Tampilan tidak dipakai menggagalkan AC.
3. `ARCHIVE414` root di UI id ("Semua Arsip" di toast): hanya BE (butuh konfirmasi root yang menyentuh 4.234 dokumen root developer).
4. 403/404 `GET opnames/folders` selain 404 hasil intersepsi jaringan; ARCHIVE407 di UI; isolasi tenant (`QA_DBS` 1 DB); layar mobile nyata (hanya viewport 375).
5. ZULFA asli developer tidak dipakai (AC-8 dibuktikan dengan folder fixture ekuivalen).

## Perubahan di folder qa/ (item ini)

`qa/e2e/ED-1026-opname.spec.js` ditulis ulang (15 test, 128 KB satu berkas dengan helper bersama); harapan diturunkan dari API saat run, baris folder dicari ke semua halaman list (`ensureRow`), "+" root tidak lagi mengharapkan Opname item terakhir, CABANG - SEMARANG/Folder A/PUSAT - MAGELANG/ZULFA asli tidak dipakai. `qa/e2e/fixture.php`: `backdate` dihapus; + `0QA26-ZULFA`, `HIST`, `OMEGA`, dokumen root `RD1` (verified)/`RD2`; perintah `lang`, `reparent`; `inspect` memuat hitungan 3 tabel opname; snapshot user memuat bahasa. `fixtures.json`: alasan. Scenario BE tidak diubah.

## Riwayat ronde

- Ronde 1 (2026-10-07, alur draft): 8 AC scope fe (7 PASS, 1 MANUAL), 0 defect, F-1; e2e 10 OK + 2 OK, regresi BE 37/37.
- Ronde 1 revisi (2026-10-09, tanpa draft, laporan ini): 14 AC (13 PASS, 1 MANUAL), 0 defect, F-1 terbuka, F-2 baru; e2e main 15 OK + norole 2 OK; BE 39/39.
