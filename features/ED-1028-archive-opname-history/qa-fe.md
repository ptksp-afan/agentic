---
key: ED-1028
scope: fe
round: 1
verdict: PASS
counts: {pass: 10, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

QA FE ronde 1, Story ED-1028 (subtask FE ED-1052, ED-1058; QA ED-1062). Cakupan: 11 AC `[FE]`/`[BE+FE]` (AC-15..AC-25). Verdict **PASS**: 10 PASS, 1 MANUAL (AC-25, Gate 2), 0 defect, tidak ada HTTP 5xx dari alur UI maupun kode ED-1028.

- **Build** staging `scripts/fe-build.sh build`: exit 0, "Compiled successfully" (dibangun dari working tree sesudah perubahan FE terakhir; peringatan harness "build mungkin usang" tidak berlaku, build 14:02 > berkas FE terakhir 13:57).
- **Test**: modul Archive 10 suite / 216 tes PASS. Full suite 1522/1537 PASS; 10 suite gagal = 6 suite yang kena timeout beban (lulus saat diulang `--runInBand`: 17 suite / 444 tes) + 4 suite lama yang gagal identik di HEAD tanpa perubahan item ini (bukan regresi).
- **E2E browser** (Chrome headless, profil `default` = QA_USER, `user2` = QA_USER2): 13 OK, 0 GAGAL, 0 SKIP. Galeri: `work/e2e/ED-1028-archive-opname-history-20261007-151138/index.html`. Data uji dibuat/dibuang fixture; 8 tabel (7 `archive*` + `column_display_settings`) kembali identik baseline (count + CHECKSUM).
- **Regresi BE**: 22 skenario ED-1028 PASS; ED-1024 26/0; ED-1025 30/1 (stale); ED-1026 34/3 (stale / digantikan item ini); ED-1027 20/0 (+1 SKIP 1 DB). **D-1 qa-be: SUDAH DIPERBAIKI** (diverifikasi eksplisit, lihat bawah).
- Transparansi: sebelum mulai saya membaca `run.md` (catatan developer) dan berkas FE (komponen) untuk menentukan selector e2e; pengecekan diturunkan dari spec + kontrak, bukan dari catatan itu. Tidak ada perintah yang ditolak izin/classifier; satu perintah `sleep 60` di foreground ditolak guard tool (aturan "gunakan loop until"), saya ganti dengan loop `until` seperti anjuran pesan itu.

## Hasil per AC

Spec e2e permanen: `features/ED-1028-archive-opname-history/qa/e2e/ED-1028-opname-history.spec.js` (+ `fixture.php`, `fixtures.json`, `routes.json`). Bukti = judul test + catatan di `work/e2e/ED-1028-archive-opname-history-20261007-151138/` (`report.json`, `screenshots/default|user2/`).

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-15 | e2e: modal Document Verified (All Archive) -> "Lihat semua riwayat". Tepat satu `GET opnames` tanpa `idArchive`; respons `total` 14, `per_page` 10, 7 kolom + 3 query (`confirmedAt` dateTimeRange, `createdBy` select, `idArchives` select multiple, endpoint select benar); judul "Riwayat Opname", subjudul "All Archive — seluruh sesi opname"; kotak drawer = kotak `.ant-modal-content` (352,46,736x907) dan judul + footer modal tertutup (`elementFromPoint`); baris halaman 1 = 10 sesi terbaru dengan angka tersimpan, 3 teratas = overview modal; tanpa teks "N sesi" dan tanpa filter ala desain di atas tabel; paginasi 2 halaman; ✕ kembali ke isi modal, buka lagi = request baru, tutup modal lalu buka lagi = history tertutup | PASS | test "AC-15 modal Document Verified..."; `ac15-01-history-modal.png`; catatan `cover: {...topInHistory:true,bottomInHistory:true}` |
| AC-16 | e2e: row menu folder ALFA -> Lihat -> tab Verifikasi -> CTA. `idArchive` = id ALFA di request awal, search, Reset, sort, reload; subjudul "0QA28-ALFA — seluruh sesi opname yang mencakup folder ini"; kotak drawer = kotak `.ant-drawer-content` folder (990,0,450x1000), header + footer tertutup; isi = 7 sesi yang mencakup ALFA (sesi ALFA, subfolder, root), 3 teratas = overview tab; BRAVO: 7 sesi termasuk sesi subfolder BRAVO-T1 dan sesi root; folder read-only (user QA hanya View): search, popover, Display Setting tetap aktif dan search terkirim; ✕ kembali ke tab Verifikasi, drawer folder tetap terbuka | PASS | test "AC-16 drawer folder..."; `ac16-01/02/03-*.png` |
| AC-17 | e2e: search `{"query":"alice"}` (username), `s1-x` (nama folder level 2, tanpa beda huruf), `SECRET` (folder tak boleh dilihat user) = 0 baris; popover: Waktu Opname (rentang), User, Folder; select User memuat opsi dari `select/.../opname-users` (qa28alice..qa28view, tanpa pelaku sesi tersembunyi/draft/batal), pilih -> `createdBy`; query + User digabung AND; select Folder memuat dari `select/.../folders`, label path "Induk / Folder", cari per nama, folder SECRET tanpa View tidak ada di opsi, pilih dua -> `idArchives` = array 2 id datar -> sesi yang mencakup salah satunya; rentang `["2026-10-05 00:00:00","2026-10-06 23:59:59"]`, hanya awal `[awal,null]`, hanya akhir `[null,akhir]`, ujung inklusif; Reset: search kosong + 14 sesi kembali + input kosong; tidak ada `GET archives` di belakang | PASS | test "AC-17 search bar + filter popover..."; `ac17-01..07-*.png` |
| AC-18 | e2e: Display Setting terbuka (drawer tampil di ATAS history dan modal, menerima klik; kolom aktif + tersedia Discan/Belum Discan; filter aktif 3) -> sembunyikan Invalid + tampilkan Discan -> PUT 200, respons `columns` ikut, tabel 7 kolom dengan Discan (N01 = 5); dipulihkan lewat UI. Sort: 6 header punya pengurut, Scope tidak; urutan UI dibandingkan dengan urutan harapan dari tabel tangan memakai daftar `sorts[]` di request (sort bertumpuk bawaan `useTable`, klik ke-3 membatalkan); klik Scope tanpa request; halaman 2 membawa `page=2` + sort, isi N11..N14 | PASS | test "AC-18 Display Setting..."; `ac18-01..04-*.png` |
| AC-19 | unit `archive.function.test.js -t AC-19` (8 tes: `renderOpnameDateTime`, `renderOpnameScope`, `withOpnameHistoryRender`) + e2e: sel Scope "All Archive · root", "0QA28-ALFA-S1 + 1 subfolder", "0QA28-BRAVO"; Waktu "06 Okt 2026 16:40" | PASS | `Tests: 8 passed` (`-t "AC-19"`); baris di `ac15-01-history-modal.png` |
| AC-20 | e2e: klik baris N01 -> `GET opnames/{id}` lalu `GET opnames/{id}/documents?result=scanned&page=1` (urutan benar, daftar tidak di-request ulang); judul "Hasil Opname", subjudul "06 Okt 2026 16:40 · qa28alice · 0QA28-ALFA-S1 + 1 subfolder"; drawer hasil menutupi drawer history; 5 kartu 5/3/1/1/2; `Segmented` Semua 7 / Discan 5 / Terverifikasi 3 / Tidak ditemukan 1 / Tidak valid 1, default Discan; kolom No Dokumen, Tipe Transaksi, Folder, Waktu, Hasil; Not found "Di luar scope opname", Invalid "—"; baris `row-not-found`/`row-invalid` berlatar kuning/merah muda; tanpa peringatan Step 3 dan tanpa Back/Confirm; ganti filter -> `result=not_found`/`invalid`/`verified`/`all` (halaman 1; All memuat baris "Belum discan" tanpa jam); ✕ -> kembali dengan search "alice" dan halaman 2 yang sama, tanpa request daftar baru; buka lagi = GET detail baru; sesi parsial (user QA, bukan superadmin) = catatan BR-11, kartu angka rekaman 8/3/1/1/2, Segmented 5/4/2/1/1, nama dokumen SECRET tidak bocor; jalur error `GET opnames/{id}` 404 -> satu toast, tanpa kartu 0, documents tidak diminta, ✕ kembali | PASS | test "AC-20 klik baris..." + "AC-20 jalur error..."; `ac20-01..06-*.png` |
| AC-21 | e2e profil `user2` (EN, List Archive tanpa Opname Document): history 200, total 13 (N14/HID/draft/batal tak terlihat), judul/subjudul/kolom EN, hasil sesi penuh tanpa catatan, sesi parsial N02: catatan "Some documents outside your access are not shown.", kartu rekaman 8/3/1/1/2, Segmented All 5 / Scanned 4 / Verified 2 / Not found 1 / Invalid 1, tanpa H1/H2; konteks folder EN; tanpa respons 4xx dari alur UI | PASS | test "[user2] AC-21 ..."; `user2-01..04-*.png` |
| AC-22 | e2e: scan USB (ketikan cepat + Enter di luar input). Modal + history terbuka: 0 `GET archives` (scan masuk ke search riwayat `{"query":"0QA28-A1"}`); sesudah ✕: `GET archives?search=kode`; history terbuka lagi lalu modal ditutup lewat mask: history ikut tertutup, scan memicu `GET archives`; sama untuk drawer folder (history terbuka: 0 request, ✕ dan penutupan drawer lewat mask: aktif lagi) | PASS | test "AC-22 scan USB..."; catatan `GET archives baru=0`; `ac22-01-scan-saat-history.png` |
| AC-23 | e2e: (a) nol sesi nyata di DB: drawer terbuka, 200 `total 0`, 7 header tetap, empty state standar, tanpa "N sesi"; (b) folder tanpa sesi (EMPTYF): overview 0 baris, history kosong `idArchive=EMPTYF`; (c) filter tahun lalu: kosong, Reset mengembalikan 14 sesi; (d) `GET opnames` dimodifikasi 422: toast, tabel kosong, ✕ tetap menutup, buka lagi normal | PASS | tests "AC-23 tanpa sesi sama sekali..." dan "AC-23 folder tanpa sesi..."; `ac23-01..04-*.png` |
| AC-24 | e2e: modal 05 dan tab Verification (04) overview tetap benar; list Archive: sort Nama, mode Pilih/Batal Pilih, scan USB mencari list; Opname nyata GAMMA lewat UI (scan GD1, GD2, B1 = Not found, kode asing = Invalid): Step 3 kartu 3/2/1/1/1, Segmented 5/4/2/1/1 default Discan, Confirm 200, DB 3/2/1/1 scanned 4 unscanned 1 (5 baris); sesi baru di baris teratas history dengan angka rekaman, hasilnya (kartu + Segmented) identik Step 3; smoke `/archives` default + user2 tanpa error console/halaman/API; regresi BE di bawah | PASS | test "AC-24 regresi..." + 2 test smoke `routes.json`; `ac24-01-step3.png`, `ac24-02-hasil-nyata.png` |
| AC-25 | Struktur & alur sesuai desain 05b: judul + subjudul + ✕, tabel 7 kolom (Waktu, User, Scope, Total dokumen, Verified, Not found, Invalid), catatan kaki, klik baris membuka hasil; filter ala desain diganti search + popover + Display Setting (R-25/R-26) dan footer "Close" diganti ✕ (BR-2) sesuai spec. Penilaian tampilan = manusia | MANUAL (Gate 2) | Muncul di `## Daftar tes UI` task FE 010 (butir 1-8) dan 011 (butir 1-7) di `ksp-react/docs/specs/Archive/`; lihat catatan O-1 di bawah untuk butir yang sebaiknya ditambah |

## Defect

Tidak ada defect FE maupun BE pada ronde ini. D-1 dari `qa-be.md` (BE, ED-1034): **fixed**, diverifikasi eksplisit dengan skenario sekali pakai (folder scratch, sudah dihapus; laporan `work/qa-http/ED-1028-20261007214738.json`, 39 asersi PASS):
- `GET document-archive/opnames?search=` bentuk tak valid -> **422** dengan body validasi: `[1,2]`, `[{"query":"a"}]`, `["a"]`, `[null]`, `[[]]`, `[0]`, `123`, `"x"`, `abc`, `[`, `true`, `1.5` (12 bentuk).
- Bentuk valid tetap **200 ARCHIVE200**: `[]`, `{}`, `{"query":"a"}`, kombinasi bentuk FE (`query`, `createdBy`, `idArchives: []`, `confirmedAt: [null,null]`), `{"confirmedAt":["2026-10-04 00:00:00",null]}`, `null`, kosong; request normal sesudah penolakan 200.
- Skenario permanen item ini (`scenario2-search.php`) belum memuat bentuk list `[1,2]`; saran untuk pemilik QA BE: tambahkan ke AC-4.

Catatan/observasi untuk developer (bukan defect, tidak mengubah verdict):
- **O-1 (UX, usul butir Gate 2).** Lebar kolom: di modal 05 (tabel 688 px dari konten 984 px) kolom Verified, Not Found, Invalid berada di luar area terlihat; di drawer folder (450 px) hanya Waktu, User, dan sebagian Scope terlihat. Kolom terjangkau lewat gulir horizontal (teruji: kolom terakhir bisa dicapai, `overflow-x: auto`), tetapi tidak ada scrollbar horizontal terlihat di screenshot. Pola yang sama dengan tabel overview 3 sesi di modal yang sama (ED-1027). Daftar tes UI 010 butir 2 menyebut semua kolom tampil; sebaiknya tambah butir "geser tabel ke kanan di modal/drawer sempit". Catatan kaki tabel terpotong di dasar viewport 1000 px, tetapi badan drawer dapat digulir (`overflow-y: auto`, 881 > 830 px).
- **O-2.** Judul kolom dari config BE: "User", "Verified", "Not Found", "Invalid" tidak ikut bahasa ID (diketahui, ada di catatan task 010).
- **O-3.** Sort per header bertumpuk (`sorts[]` berisi semua kolom yang pernah diklik; klik ketiga membatalkan) adalah perilaku bawaan `useTable` list v5; BE menanganinya (skenario BE "dua sort"). Bukan sesuatu yang diubah item ini.
- **O-4.** Teks ID memakai "Riwayat Opname" dan "Lihat semua riwayat" (spec menulis "Opname History"/"Lihat semua history" dalam bahasa Inggris); konsisten dengan locale overview ED-1027, EN persis sesuai spec.
- **O-5.** Tiga hal "perlu dikonfirmasi developer" dari `qa-be.md` (kata-kata kontrak sesi batal, `idArchives: [""]`, label path select memuat induk tak terlihat + batas ~8 KB query string) tetap terbuka; FE tidak mengirim bentuk-bentuk itu.

## Galeri e2e & regresi BE

**Build & unit test** (berkas di `work/`):

| Langkah | Hasil | Berkas |
|---|---|---|
| `scripts/fe-build.sh build` | exit 0, "Compiled successfully", "Done in 70.40s" | `qa-fe-ed1028-r1-build.txt`, `fe-build.log` |
| `yarn test --watchAll=false src/containers/Archive` | 10 suite / 216 tes PASS | `qa-fe-ed1028-r1-test-archive.txt` |
| full suite sekali | 68/78 suite, 1522/1537 tes PASS; 10 suite gagal (di bawah) | `qa-fe-ed1028-r1-test-full.txt` |
| ulang `--runInBand` 6 suite yang kena timeout beban (ArchiveDrawer, ArchiveMoveModal, RaptorPending x3, ItemRaptorImport) | modul Archive + RaptorPending + ItemRaptorImport: 17 suite / 444 tes PASS | `qa-fe-ed1028-r1-test-rerun.txt` |
| 4 suite gagal lain (`production.function`, `InterbankTransferForm`, `BatchOut`, `CustomerPurchaseOrderView`) | gagal sendiri (4 tes + 1 suite "electronEvent undefined"); **identik di HEAD tanpa perubahan item ini** (git archive HEAD di folder scratch, sudah dihapus): 4 suite / 4 tes gagal dengan nama tes yang sama. Tidak menyentuh kode Archive | `qa-fe-ed1028-r1-test-baseline.txt`, `qa-fe-ed1028-r1-test-baseline-HEAD.txt` |

**E2E** (`scripts/e2e/run.sh ED-1028 --workers 1 --profile default,user2`, run final): 13 OK = 10 flow `default` + 1 flow `user2` + 2 smoke `routes.json` (`/archives` default dan user2). Galeri `work/e2e/ED-1028-archive-opname-history-20261007-151138/index.html`, 33 screenshot, `report.json` `errors: []`, allow-list tidak dipakai (dua test sengaja memodifikasi respons 422/404 untuk jalur error; entri itu dikeluarkan di dalam test setelah dipastikan terjadi). Log run: `work/qa-fe-ed1028-r1-e2e-final3.txt`.

**Regresi BE** (`"$PHP_BIN" scripts/qa-http/run.php <KEY>`, `fpm`, tanpa reload):

| Item | PASS | FAIL | SKIP | Catatan |
|---|---|---|---|---|
| ED-1028 | 22 | 0 | 0 | semua AC-1..14 + X-1..X-8; `qa-fe-ed1028-r1-be-ED-1028.txt` |
| ED-1024 | 26 | 0 | 0 | bersih |
| ED-1025 | 30 | 1 | 0 | AC-1 **stale** (Updater = kunci terbesar `Updaters/config.php`) |
| ED-1026 | 34 | 3 | 0 | AC-1 **stale** (alasan sama); AC-12 dan AC-15 **digantikan perubahan sah item ini** |
| ED-1027 | 20 | 0 | 1 | X-3 SKIP (1 DB tenant) |

ED-1026 AC-12/AC-15 berhenti di asersi pertama yang gagal, jadi saya menjalankan **salinan scratch** (berkas ED-1026 tidak diubah) dengan ekspektasi usang disesuaikan: sesi terkonfirmasi user lain yang terlihat kini 200 untuk non-superadmin (tiga tempat: loop `reads`, role 29 `List Archive`), dan kunci `show` kini memuat `result_counts`, `is_partial`. Hasil: AC-12 (140 asersi), AC-15 (40), AC-24 (38), EXTRA-SCOPE, EXTRA-PERM, EXTRA-STALE semuanya PASS, jadi sisa asersi (draft/batal user lain 404 untuk semua, mutasi ditolak) tidak berubah. Berkas QA ED-1026 (dan dokumen 03 §4/§6) perlu diperbarui pihak berwenang. Dua stale di e2e ED-1025 (jumlah tab drawer kini 3, item menu "+" kini 5) tidak dijalankan ulang di tahap ini dan dicatat sesuai arahan.

500 di keluaran regresi: hanya ED-1025 X-12 (75 dari 80 kasus) dan X-13 (`pagination`/`sorts`/`search` salah bentuk pada `GET archives`), karakterisasi bug lama endpoint ED-1025 (tiket ED-1071/ED-1070 menurut `qa-be.md`), bukan kode ED-1028.

Pemulihan: sesudah semua run, `archive_opnames/_folders/_documents` 0 baris, `archives` 49.145 baris (= awal), `column_display_settings` CHECKSUM 674941392 (= awal), role QA_USER [3] / QA_USER2 [5] tak berubah, token aktif 0, tanpa sisa data `QA2*`/`0QA*`. Catatan: baris display setting `documentArchiveOpnameHistory` sudah ada sebelum run saya (dibuat request sebelumnya, 20:10:46); dibiarkan seperti ditemukan.

## Yang tidak bisa diverifikasi (dan kenapa)

- **AC-25** tampilan = MANUAL Gate 2 (bukan "tidak terverifikasi": ada di Daftar tes UI 010/011).
- Scanner kamera (modal "Scan Code"): tidak bisa di headless; hanya scanner USB (keyboard wedge) yang diemulasi.
- Layar kecil/mobile (tombol Display Setting `showInSmallScreen`, expand baris bawaan `DefaultTable`): tidak diperiksa.
- Tampilan superadmin 1/2 di browser (tidak ada profil e2e superadmin): `is_partial=false` dan angka penuh dibuktikan di BE (AC-13), bukan di UI.
- Isolasi tenant antar-DB dan "tukar lisensi sungguhan" (AC-14, milik BE; `PROFILES` kosong, `QA_DBS` 1 DB): tetap seperti `qa-be.md` (Gate 2 epic).
- Performa UI pada ribuan sesi: hanya diukur di BE (X-2, X-7, X-8).

## Riwayat ronde

- Ronde 1 (2026-10-07): build + 13 e2e OK, unit Archive 216/216, regresi BE tanpa regresi nyata, D-1 fixed; verdict PASS (0 FAIL, 1 MANUAL).
