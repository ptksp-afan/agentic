---
key: ED-1028
scope: fe
round: 1
verdict: PASS
counts: {pass: 12, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

QA FE **revisi** (ronde 1 revisi, 2026-10-10), Story ED-1028. Dasar: FE `5a0c34be1` (tanpa kode FE baru di item ini; hanya `docs/specs/Archive/_api.md` kotor), BE `3ae86a5c1` + 4 berkas kotor (ED-1100/1101). Perubahan FE tampak di item ini datang dari ED-1027 rev (FB-8c, `5a0c34be1`), ED-1026 rev (FB-10 `CheckableTag`, `getScopeTag`, `5656a5e83`) dan developer `7fa63869d` (modal tanpa footer, `disableDefaultRowExpand`). Laporan ronde asli (2026-10-07, PASS 10 / MANUAL 1) ada di git: `git show 934f11f:features/ED-1028-archive-opname-history/qa-fe.md`.

- Cakupan: 13 AC `[FE]`/`[BE+FE]` (AC-15..25, AC-27, AC-29) = **12 PASS, 1 MANUAL (AC-25, Gate 2), 0 FAIL**. Tidak ada HTTP 5xx dari alur UI maupun kode item ini. **0 defect.** Verdict PASS.
- Build staging `scripts/fe-build.sh build`: exit 0, "Compiled successfully" (build 11:34 UTC lebih baru dari commit FE; tidak ada peringatan "build usang").
- Jest: modul Archive 11 suite / 358 tes semuanya lulus (2 tes `ArchiveOpnameModal` kena timeout 5 dtk saat beban, lulus sendirian 64/64). Full suite sekali: 79 suite, 1679 tes, 10 gagal = 4 tes baseline non-Archive + 6 timeout beban (semua lulus bila diulang sendiri; lihat Galeri & regresi).
- E2E (Chrome headless, profil `default` = QA_USER, `user2` = QA_USER2): **16 OK, 0 GAGAL** pada run akhir di tabel opname kosong (apa adanya) dan **16 OK, 0 GAGAL** dengan data developer simulasi (5 sesi + 5 baris folder, dibuang lagi). Dijalankan `--workers 1`; spec juga mengambil kunci lintas-proses (`takeStateLock`).
- Regresi BE (`qa-http`): ED-1028 24/0, ED-1024 26/0, ED-1025 40/0, ED-1026 39/0, ED-1027 28/0/1 SKIP (X-3, 1 DB), ED-1029 29/0/1 SKIP (X-ISO, 1 DB). Tanpa FAIL.
- Subtask **ED-1102** (QA, folder ini saja): e2e usang diperbarui (lihat "Subtask ED-1102"). Penolakan izin/classifier: **0**.
- DB `api_sidomaju` sama persis dengan keadaan awal (8 tabel `archive*`/`column_display_settings` + `role_permissions`: count + CHECKSUM identik; token aktif 0).

## Hasil per AC

Spec e2e: `features/ED-1028-archive-opname-history/qa/e2e/ED-1028-opname-history.spec.js`. Bukti = run akhir `work/e2e/ED-1028-archive-opname-history-20261010-121046/` (`report.json`, `screenshots/default|user2/`) kecuali disebut lain.

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-15 (rev) | e2e: modal Document Verified (All Archive) -> CTA "Lihat semua riwayat". Tepat satu `GET opnames` tanpa `idArchive`; judul "Riwayat Opname", subjudul **"Semua Arsip — seluruh sesi opname"** (bukan "All Archive"); tanpa teks "N sesi" dan tanpa filter ala desain di atas tabel; kotak drawer = kotak `.ant-modal-content` (736x835) dan judul + dasar modal tertutup; modal tanpa footer; 10 baris halaman 1 = angka rekaman, 3 teratas = overview modal; paginasi >= 2 halaman; kolom terakhir terjangkau gulir; ✕ kembali ke isi modal, buka lagi = request baru, ✕ modal lalu buka = history tertutup | PASS | test "AC-15 modal Document Verified..."; `ac15-01/02-*.png`; catatan `cover: {topInHistory:true,bottomInHistory:true}` |
| AC-16 | e2e: drawer folder ALFA -> tab Verification -> CTA: `idArchive` = id ALFA pada request awal, search, Reset, sort, reload; subjudul "0QA28-ALFA — seluruh sesi opname yang mencakup folder ini"; kotak drawer = kotak `.ant-drawer-content` (736x1000); isi = 7 sesi yang mencakup ALFA, 3 teratas = overview tab; BRAVO = 7 sesi termasuk sesi subfolder dan sesi root; VIEWONLY (read-only): search, popover, Display Setting aktif; ✕ kembali ke tab, drawer folder tetap | PASS | test "AC-16 drawer folder..."; `ac16-01..03-*.png` |
| AC-17 | e2e: search `{"query":"alice"}`, `s1-x` (folder level 2, tanpa beda huruf), `SECRET` (tak boleh dilihat) = 0 sesi uji; popover Waktu Opname / User / Folder; select User dimuat dari `select/.../opname-users` (7 pelaku sesi uji, tanpa HID), pilih -> `createdBy`, AND dengan query; select Folder dimuat dari `.../folders`, label path "Induk / Folder", SECRET tidak ada di opsi, dua folder -> `idArchives` array 2 id -> sesi yang mencakup salah satunya; rentang `["2031-10-05 00:00:00","2031-10-06 23:59:59"]`, hanya awal `[awal,null]`, hanya akhir `[null,akhir]`, ujung inklusif; Reset: search kosong, total kembali ke total awal; tanpa `GET archives` di belakang | PASS | test "AC-17 search bar + filter popover..."; `ac17-01..07-*.png` |
| AC-18 | e2e: Display Setting tampil DI ATAS history + modal; sembunyikan Invalid + tampilkan Discan -> PUT 200, respons `columns` ikut, tabel 7 kolom dengan Discan (N01 = 5); dipulihkan lewat UI + DB; sort: 6 header punya pengurut, Scope tidak, urutan UI = urutan harapan dari tabel tangan memakai `sorts[]` (klik ke-3 membatalkan), klik Scope tanpa request; `page=2` membawa sort + search | PASS | test "AC-18 Display Setting..."; `ac18-01..04-*.png` |
| AC-19 (rev) | e2e sel Scope = `getScopeTag`: "0QA28-ALFA + 2 subfolder", "0QA28-BRAVO + 1 subfolder", folder tanpa subfolder = nama saja, root id "Semua Arsip · root", EN "All Archive · root" (user2); respons `scope` = tepat `is_root, folder_name, subfolder_count` (baris dan `GET opnames/{id}`); Waktu "06 Okt 2031 16:40". Unit `archive.function.test.js` (renderOpnameScope, getScopeTag) hijau di suite Archive | PASS | `ac15-01-history-modal.png`, notes AC-15/AC-20/AC-21; `Tests: 358 passed` modul Archive |
| AC-20 (rev) | e2e: klik baris -> `GET opnames/{id}` lalu `GET .../documents?result=scanned&page=1` (daftar tidak di-request ulang); tanpa baris expand bawaan; subjudul "Waktu · User · 0QA28-ALFA + 2 subfolder"; drawer hasil menutupi history; 5 kartu 5/3/1/1/2; **5 `CheckableTag`** "Semua 7 / Discan 5 / Terverifikasi 3 / Tidak ditemukan 1 / Tidak valid 1", tanpa `.ant-segmented`, tepat satu aktif (default Discan); ganti tag -> `result=not_found/invalid/verified/all` halaman 1; klik tag yang sudah aktif = tanpa request; Not found "Di luar scope opname", Invalid "—", latar baris khusus; **375 px: 5 tag membungkus 2 baris, tanpa gulir horizontal** (`lines:2, spaceOverflow:0, docOverflow:0`); ✕ kembali ke halaman 2 / search yang sama tanpa request daftar; sesi parsial N02: catatan BR-11, kartu rekaman 8/3/1/1/2, tag 5/4/2/1/1, nama SECRET tidak bocor; jalur error 404 -> satu toast, tanpa kartu 0 | PASS | tests "AC-20 klik baris..." + "AC-20 jalur error..."; `ac20-01..06-*.png`, `ac20-04b-375px.png` |
| AC-21 (rev) | e2e [user2] **dengan `Opname Document` diberikan sementara** (grant `role_permissions` per NAMA, dicabut persis; halaman dimuat ulang): history 200, total 13 sesi uji (N14/HID tak terlihat), judul/subjudul/kolom EN, Scope root "All Archive · root", hasil penuh N01 tanpa catatan, parsial N02: "Some documents outside your access are not shown.", kartu 8/3/1/1/2, tag 5/4/2/1/1, tanpa H1/H2; konteks folder EN; tanpa respons 4xx dari alur UI | PASS | test "[user2] AC-21 ..."; `user2-01..04-*.png`; catatan `grant made:[…]` dan `revoke: role_permissions identik` |
| AC-22 | e2e: scan USB. History terbuka: 0 `GET archives` (scan masuk ke search riwayat); sesudah ✕: `GET archives?search=kode`; modal ditutup lewat mask saat history terbuka: ikut tertutup, scan memicu `GET archives`; sama untuk drawer folder | PASS | test "AC-22 scan USB..."; `ac22-01-*.png` |
| AC-23 | e2e: (a) tanpa sesi uji: 200 `total 0` (tabel opname kosong), 7 header tetap, empty state standar, tanpa "N sesi"; bila DB berisi sesi developer cabang itu hanya memastikan tanpa sesi uji (empty state dibuktikan b-c); (b) folder EMPTYF: overview 0, `idArchive=EMPTYF` kosong; (c) rentang 2040: kosong, Reset = total awal + 10 sesi uji; (d) `GET opnames` 422: toast, tabel kosong, ✕ menutup, buka lagi normal | PASS | tests "AC-23 tanpa sesi (uji)..." + "AC-23 folder tanpa sesi..."; `ac23-01..04-*.png` |
| AC-24 (rev) | e2e: modal 05 overview, tab Verification (AC-16), modal verifikasi folder (AC-29) benar; list Archive sort Nama, mode Pilih/Batal, scan mencari list; **Opname nyata GAMMA via alur ED-1026 rev**: scan = `POST opnames/scan` (belum ada sesi di DB), Step 3 kartu 3/2/1/1/1 + 5 `CheckableTag` (Discan aktif), Konfirmasi = satu `POST opnames` 200, DB 3/2/1/1 scanned 4 unscanned 1 (5 baris dokumen); total history +1, sesi baru (dicari lewat nama folder) angka rekaman, hasilnya identik Step 3. Sisi BE: `scenario8` AC-24 (hak folder FB-9 tidak mengubah sesi terlihat) 81 asersi PASS | PASS | test "AC-24 regresi..." + 2 smoke `routes.json`; `ac24-01-step3.png`, `ac24-02-hasil-nyata.png`; `work/qa-fe-ed1028-r2-be-ED-1028.txt` |
| AC-25 | Struktur & alur sesuai desain 05b (judul + subjudul + ✕, 7 kolom, catatan kaki, klik baris = hasil; filter ala desain diganti search + popover + Display Setting sesuai R-25/R-26). Penilaian tampilan = manusia | MANUAL (Gate 2) | Ada di `## Daftar tes UI` task FE 010 (butir 1-8), 011 (1-7), 017 (butir 4, 9, 10: CheckableTag + 375 px) di `ksp-react/docs/specs/Archive/` |
| AC-27 (rev) | e2e dua profil. **Default**: CTA ada di modal 05, tab Verification, modal verifikasi folder; `GET setting/display-columns?search=documentArchiveOpnameHistory` 200 memuat barisnya, `GET .../documentArchiveOpnameHistory` 200 dengan 7 kolom. **R0** (role asli QA_USER2, tanpa `Opname Document`): list tanpa kolom Document Verified dan tanpa tautan ringkasan, drawer folder (Detail, form ALFA tampil, tanpa tab), tanpa teks "See all history"/"Lihat semua riwayat", `.archive-opname-history`/`.drawer-history` = 0 di DOM, **0 request `opnames*`/`opname-users`** sepanjang alur; daftar Setup (`index`) memuat `documentArchive` tetapi tanpa `documentArchiveOpnameHistory`; `show` = 403 `GE0114` `parameter` "Opname Document"; `GET opnames` = 403 `GE0114` | PASS | tests "AC-27 profil default..." + "[user2] AC-27 R0..."; `ac27-01-*.png`, `ac27-r0-01/02-*.png` (halaman Setup tidak punya route FE, lihat "Tidak bisa diverifikasi") |
| AC-29 (rev) | e2e: list Archive -> klik nilai Document Verified (V/T) baris folder ALFA -> modal folder (tanpa footer, subjudul nama folder) -> CTA -> `GET opnames?idArchive=<ALFA>` 200, drawer menutupi isi modal (736x873), subjudul "0QA28-ALFA — seluruh sesi opname yang mencakup folder ini", 7 sesi ALFA; ✕ kembali ke modal (isi sama, modal tetap), tanpa `GET archives` tambahan | PASS | test "AC-29 list Archive..."; `ac29-00/01-*.png` |

## Defect

Tidak ada defect FE maupun BE pada ronde ini. Dua GAGAL sementara pada run simulasi pertama (`...-115858`) = salah spec saya, diperbaiki sebelum run bukti: (1) asersi R0 memakai `input` pertama drawer (field tersembunyi); (2) `rowKeys` dibaca sekali saat tabel masih dirender ulang (kini `expect.poll`).

Catatan (bukan defect, tidak mengubah verdict):
- **O-1 (UX, sudah tercatat di ronde asli).** Di modal 05 (736 px) hanya Waktu, User, Scope, Total Dokumen yang tampak; Verified/Not Found/Invalid lewat gulir horizontal (teruji terjangkau). Pola sama dengan overview 3 sesi (ED-1027); menunggu klien (spec §9).
- **O-2.** Judul kolom dari server ("User", "Verified", "Not Found", "Invalid") tidak ikut bahasa ID (spec §9: judul dari server, tetap).
- **O-3 (dokumen FE, bukan kode).** `ksp-react/docs/specs/Archive/011-feat-opname-history-result.md:17,38,47,92,107,122,124` masih menyebut `Segmented` (kini `CheckableTag`, FB-10) dan `010-feat-opname-history-drawer.md:94` menulis "footer Tutup" dan subjudul "All Archive — seluruh sesi opname" (kini tanpa footer, id "Semua Arsip"). Daftar tes UI yang dipakai Gate 2 sebaiknya disesuaikan pemilik FE (butir CheckableTag sudah benar di 017). Tidak diubah (kode/dokumen FE bukan wilayah QA).

## Subtask ED-1102 - e2e diperbarui (folder ini saja)

| Berkas | Perubahan |
|---|---|
| `qa/e2e/ED-1028-opname-history.spec.js` (ditulis ulang, 16 test) | (1) Scope = `getScopeTag` ("<folder> + N subfolder", root id/en; peta `S` + `scopeOf` + `rowKey`: K-14 a / K-10 b); (2) `.ant-segmented-item` -> `.ant-tag-checkable` / `-checked` (+ asersi tanpa `.ant-segmented`, klik tag aktif tanpa request, 375 px membungkus); (3) footer modal dihapus `7fa63869d` -> `.ant-modal-close` (+ asersi tanpa footer); (4) subjudul "Semua Arsip" (id); (5) AC-21: user2 **dengan `Opname Document`** lewat grant sementara per NAMA (try/finally + revoke); (6) baru: AC-27 default, AC-27 R0 (user2 role asli), AC-29 modal folder; (7) alur Opname nyata = ED-1026 rev (`POST opnames/scan`, satu `POST opnames`); (8) tanpa draft/batal; (9) **asersi dilingkup ke sesi uji**: baris asing hanya dibuang dari perbandingan (`rowKeys`), total terhadap batas bawah/"total awal", sort/halaman di bawah search `qa28`, sesi nyata GAMMA dicari lewat nama folder; baris kosong-tabel (`:344-347,389,593,740,1255,1508,1339,616,707,717,727,737,806-807,861,879,907,1069,1082,1256,1284`) semuanya tidak lagi mengandaikan tabel kosong; (10) `takeStateLock` lintas-proses (ED-1027 Q-1) |
| `qa/e2e/fixture.php` | sesi uji 2031 (selalu di atas data developer), N01 `scope_name` ALFA + N=2, N08 N=1, tanpa DRAFT/CANC; `ensure-sessions` membereskan hanya milik fixture (sesi nyata hasil Confirm ikut) lalu mengisi ulang; `grant-od`/`revoke-od` (jurnal `.grant-journal.json`, `down` mencabut sisa); jurnal v2 tetap (hanya baris sendiri) |
| `qa/e2e/fixtures.json` | alasan diperbarui; `routes.json` tidak berubah (smoke default + user2 lulus) |

Baris usang yang dicatat QA item lain dan kini teratasi: ED-1026 qa-fe (peta Scope `:39`/`rowKeys :321`, `CheckableTag`, "[user2] AC-21 kolom EN" - kini lulus 4 run berturut-turut, header EN = daftar harapan), ED-1029 qa-fe (AC-15/AC-24 `.ant-modal-footer`), ED-1027 qa-fe (idem + AC-29), spec §7 gate1 §5. Skenario usang item lain (ED-1025/1026/1027/1029) tidak dijalankan/diubah di sini.

## Galeri e2e & regresi BE

**Build & Jest** (berkas di `work/`):

| Langkah | Hasil | Berkas |
|---|---|---|
| `scripts/fe-build.sh build` | exit 0, "Compiled successfully", Done 77 dtk | `qa-fe-ed1028-r2-build.txt`, `fe-build.log` |
| `yarn test --watchAll=false src/containers/Archive` | 10 suite lulus + 1 suite 2 tes timeout beban (`ArchiveOpnameModal`); 356/358 | `qa-fe-ed1028-r2-test-archive.txt` |
| ulang `ArchiveOpnameModal --runInBand` | 64/64 lulus | `qa-fe-ed1028-r2-test-rerun.txt` |
| full suite sekali | 79 suite, 1679 tes; 8 suite / 10 tes gagal = 4 baseline + 6 timeout beban (`ArchiveOpnameModal` 2, `ArchivePage` 1, `RaptorPendingView` 2, `RaptorFailedPage` 1) | `qa-fe-ed1028-r2-test-full.txt` |
| ulang suite beban `ArchivePage` + `RaptorPending*` + `RaptorFailed*` `--runInBand` | 9 suite: 8 lulus; `ArchivePage` 1 tes tooltip ikon perisai gagal (ED-1025 BR-10/FB-7), 246/247 tes lulus | `qa-fe-ed1028-r2-test-rerun2.txt` |
| `ArchivePage` sendirian x2 | 30/30 lulus kedua kali (tes tooltip = flaky saat suite jalan berurutan dengan banyak suite; bukan kode item ini) | `qa-fe-ed1028-r2-test-archivepage-1/2.txt` |
| 4 suite baseline non-Archive sendirian (`Production/production.function`, `InterbankTransferForm`, `Item/BatchOut`, `CustomerPurchaseOrderView`) | gagal deterministik (4 tes + 1 suite tak jalan); `git diff HEAD~6..HEAD` pada modulnya kosong; sama dengan baseline ronde asli dan QA ED-1026/1027 | `qa-fe-ed1028-r2-test-baseline4.txt` |

**E2E** (`scripts/e2e/run.sh ED-1028 --workers 1 --profile default,user2`; 12 flow `default` + 2 flow `user2` + 2 smoke `routes.json`):

| Run | Keadaan DB | Hasil | Galeri |
|---|---|---|---|
| **akhir (bukti)** | tabel opname kosong (apa adanya) | 16 OK, 0 GAGAL, `errors: []`, allow-list tidak dipakai | `work/e2e/ED-1028-archive-opname-history-20261010-121046/index.html` |
| simulasi developer | 5 sesi `devsim.*` (1 root, 4 folder; 4 terlihat QA_USER dan user2, termasuk pengguna "alice" dan sesi bertanggal hari ini) | 16 OK, 0 GAGAL | `work/e2e/ED-1028-archive-opname-history-20261010-120527/index.html` |
| pertama | kosong | 16 OK (sebelum dua perbaikan kecil spec) | `...-20261010-115206/` |
| simulasi, percobaan 1 | 5 sesi devsim | 14 OK, 2 GAGAL = salah spec (di atas) | `...-20261010-115858/` |

Log: `qa-fe-ed1028-r2-e2e-1.txt`, `-devsim.txt`, `-devsim2.txt`, `-final.txt`. Fixture setup/teardown OK tiap run ("tidak ada sisa milik fixture, baris display setting identik"); 2 token tercabut tiap run.

**Regresi BE** (`"$PHP_BIN" scripts/qa-http/run.php <KEY>`, `fpm`, tanpa reload; berkas `work/qa-fe-ed1028-r2-be-<KEY>.txt`):

| Item | PASS | FAIL | SKIP | Catatan |
|---|---|---|---|---|
| ED-1028 | 24 | 0 | 0 | AC-1..14, 24, 26, 28 + X; data developer dijaga |
| ED-1024 | 26 | 0 | 0 | |
| ED-1025 | 40 | 0 | 0 | X-12 (75 x 500) dan X-13 = karakterisasi bug pra-ada ED-1071 / ED-1070 |
| ED-1026 | 39 | 0 | 0 | |
| ED-1027 | 28 | 0 | 1 | X-3 SKIP (1 DB); catatan 500 pra-ada: PUT identik di detik yang sama (ED-1122), `sorts` array (ED-1070) |
| ED-1029 | 29 | 0 | 1 | X-ISO SKIP (1 DB); X-1 16 x 500 SQLSTATE pra-ada (ED-1071) |

Semua 500 di keluaran adalah karakterisasi bug yang sudah dilacak (ED-1070, ED-1071, ED-1122); tidak ada 500 dari kode ED-1028 (ED-1100/1101) maupun dari alur UI e2e.

## Bug di luar item

Tidak ada bug baru. Yang terlihat sudah dikenal / bukan produk:
- 4 suite Jest baseline non-Archive di atas (pra-ada, tak tersentuh item ini; bukti `qa-fe-ed1028-r2-test-baseline4.txt` + diff modul kosong).
- Tes tooltip `ArchivePage/index.test.js` ("tooltip ikon perisai 'Akses Terbatas'", ED-1025) flaky saat dijalankan berurutan dengan suite lain (gagal 1x di `--runInBand` gabungan, lulus 30/30 dua kali sendirian dan di run penuh pertama modul Archive). 
- 500 pra-ada ED-1070/ED-1071/ED-1122 (dilacak); ED-1123 (V4 display-columns tanpa saringan) tetap seperti `qa-be.md`.

## Yang tidak bisa diverifikasi (dan kenapa)

- **Halaman Setup > Display Setting (UI) untuk AC-27**: komponen `DisplaySettingPage` tidak punya route di build ini (`paths.displaySetting` tidak ada; hanya diekspor di `containers/DisplaySetting/index.js`). Sisi yang dijanjikan spec (K-9 a: baris tidak ada di daftar untuk R0, ada untuk pemegang) dibuktikan lewat `GET setting/display-columns` dari sesi browser masing-masing profil, bukan lewat layar.
- **AC-25** tampilan = MANUAL Gate 2 (bukan "tidak terverifikasi": ada di Daftar tes UI 010/011/017).
- Scanner kamera (modal "Scan Code"): tidak bisa di headless; hanya scanner USB (keyboard wedge) diemulasi.
- Layar mobile penuh (tombol Display Setting `showInSmallScreen`, modal layar penuh): hanya tag 375 px di drawer hasil yang diperiksa.
- Tampilan superadmin 1/2 di browser (tidak ada profil e2e superadmin): `is_partial=false` dan angka penuh dibuktikan di BE (AC-13).
- Isolasi tenant antar-DB dan tukar lisensi sungguhan: `QA_DBS` 1 DB, `PROFILES` kosong (tetap seperti `qa-be.md`; Gate 2 epic).

## Data uji (dihapus / dipulihkan)

- **Data developer dihapus: 0.** Opname tables kosong sebelum dan sesudah (`archive_opnames/_folders/_documents` = 0).
- Milik QA sendiri, dibuang/dipulihkan persis oleh fixture + jurnal: 24 baris `archives` berawalan `0QA28-` per run, 15 sesi uji + 1 sesi nyata GAMMA (hasil Confirm lewat UI; kolom verifikasi GD1/GD2 dikembalikan), baris `role_permissions` grant `Opname Document` ke role 5 (dibuat 1x per run, dicabut; ringkasan jumlah + CRC identik), baris display setting `documentArchiveOpnameHistory` (id 1662) dan `documentArchive` (id 1683) = identik snapshot.
- Simulasi developer (`devsim.*`, jurnal sendiri di scratchpad): 5 sesi + 5 baris folder disisipkan lalu dibuang persis (`archive_opnames -5`, `archive_opname_folders -5`).
- Sebelum dan sesudah semua run (`work/qa-fe-ed1028-r2-baseline.txt` vs `-after-e2e.txt`): `archives` 49.148 / 2374777730, `archive_documents` 49.126 / 1197277553, `archive_locations` 48.037 / 721229928, `archive_permissions` 7 / 1818044252, opname 0/0/0, `column_display_settings` 361 / 992050222, `role_permissions` 3.794 / 2046058876 - semuanya identik; peran QA_USER [3] dan QA_USER2 [5] tak berubah; token aktif 0.
- Berkas `-baseline.txt` ditulis ulang dari snapshot identik (snapshot pertama hanya tampil di layar; nilainya sama).

## Riwayat ronde

- Ronde 1 asli (2026-10-07): PASS 10 / MANUAL 1; e2e 13 OK; D-1 qa-be (422 `search` list) fixed. Teks lengkap: `git show 934f11f:features/ED-1028-archive-opname-history/qa-fe.md`.
- Ronde 1 revisi (2026-10-10, laporan ini): PASS 12 / MANUAL 1 / 0 FAIL; e2e diperbarui (ED-1102) 16 OK pada tabel opname kosong dan dengan data developer simulasi; regresi BE ED-1028/1024/1025/1026/1027/1029 tanpa FAIL.
