---
key: ED-1026
scope: fe
round: 1
verdict: PASS
counts: {pass: 7, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

QA FE ronde 1, Archive - Opname Document per folder. AC milik scope fe: AC-3 (`[BE+FE]`), AC-4, 7, 13, 14, 17 (`[FE]`), AC-8, AC-22 (`[BE+FE]`).

- **Verdict PASS.** Tidak ada HTTP 5xx, tidak ada error konsol/page error, tidak ada cacat AC. Build staging bersih, test modul Archive 149/149, e2e 10 OK + 1 SKIP (run `main`) dan 2 OK + 9 SKIP (run `norole`, skip = test mode lain), regresi BE tanpa cacat produk.
- `counts`: PASS 7 (AC-4, 7, 8, 13, 14, 17, 22), MANUAL 1 (AC-3: bagian FE lulus e2e, bagian "tukar lisensi sungguhan" = MANUAL Gate 2 epic, EPIC K-2 b). Sub-butir MANUAL di dalam AC-13/AC-14 (kamera nyata, scanner USB fisik, penilaian warna) ada di `## Daftar tes UI` task 005, jadi tidak mengubah hitungan.
- **Temuan non-blokir F-1 (dokumentasi, rendah):** butir MANUAL "tukar lisensi sungguhan" (AC-3) tidak ada di `## Daftar tes UI` task 003-006 (hanya di epic gate1 EPIC K-2 b). Sama dengan F-1 ED-1025; tidak mengubah verdict (perilaku dan guard kode lulus). Detail di bagian Defect.
- Build staging dibangun ulang dari working tree sekarang (06:14 UTC): tidak ada berkas FE yang lebih baru dari build. Peringatan "MUNGKIN USANG" dari harness hanya karena 14 berkas FE belum di-commit.
- Mode BE `fpm`, kode terbaru langsung terpakai (tanpa reload).
- **DB `api_sidomaju` dibiarkan seperti ditemukan** (pengukuran akhir di bagian Isolasi/regresi): tiga tabel opname 0 baris, kolom verifikasi `archives` default, user QA identik.

## Hasil per AC

Alur e2e memakai data uji `0QA26-*` buatan fixture (`qa/e2e/fixture.php`, di-teardown persis) dan user QA yang diatur ke lokasi Semarang saja; run `main` = `scripts/e2e/run.sh ED-1026` dengan `E2E_WORKERS=1`, run `norole` = `QA26_MODE=norole` (Opname Document dicabut dari role user QA). Galeri: lihat bagian Galeri.

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-3 `[BE+FE]` | (a) e2e role ber-Opname Document: "+" root item terakhir `Opname Dokumen`; menu baris folder persis Info, Pindahkan, Lihat, Opname, Hapus; "+" dalam folder memuat Opname Dokumen sesudah Letakkan Dokumen; subfolder punya Opname; baris dokumen tanpa Opname. (b) e2e hak folder ED-1025: folder hanya-View = Info, Lihat, Opname; "+" di dalamnya tanpa Tambah Folder/Letakkan Dokumen tetapi ada Opname Dokumen; opname dari folder hanya-View jalan (GET opnames/folders 200, Step 2, tag = nama folder); folder tanpa View = tanpa tombol aksi. (c) run `norole` (baris `role_permissions` role user QA untuk 1133 dicabut sebelum login): "+" = Tambah Folder, Serahkan Dokumen, Terima Dokumen; menu baris = Info, Pindahkan, Lihat, Hapus. (d) Guard lisensi di kode: `configuration/menus.js:169` (`hasSalesmanFeature ? permissions.ListArchive : shouldNotAppear`), `routes/routes.js:2087-2089` (`PrivateRoute` `ListArchive`), `List Archive` dan `Opname Document` hanya di `permission_salesman.sql:68,82`. (e) Bagian BE 403 `GE0114` = regresi `AC-3` (62 asersi) | PASS (FE), MANUAL (tukar lisensi sungguhan, epic Gate 2) | `work/e2e/ED-1026-archive-opname-document-20261007-065419/` (3 test AC-3/AC-4 dan "hak folder"), `...-064256/` (norole); gambar `ac3-plus-root-opname-dokumen.png`, `ac3-menu-baris-folder-opname.png`, `ac3-menu-folder-hanya-view.png`, `ac3-norole-*`; `work/qa-fe-ed1026-regress-1026.txt` (AC-3 PASS 62) |
| AC-4 `[FE]` | `routes.json` (/archives) + monitor otomatis di setiap alur: list Archive, menu "+" Opname Document, menu baris folder, Step 1 (root, BETA, ALPHA, GAMMA), Step 2, modal kamera, Step 3 (+ Popconfirm). Console error, page error, request gagal, respons >= 400: 0, kecuali 2 respons 400 yang memang diuji (`ARCHIVE414` di DELTA, `ARCHIVE415` di EPS) yang dikeluarkan hanya sesudah ditegaskan terjadi. Tanpa allow-list. | PASS | run `main` 10 OK; `report.json` `errors: []`; `smoke-_archives.png`, `ac4-modal-kamera.png`, `ac4-step2-root-sesi.png` |
| AC-7 `[FE]` | e2e root: baris pertama "All Archive" tercentang + terkunci, kolom Folder/Dokumen/Opname hari ini, CABANG - JOGJA tidak ada (user Semarang), belum diopname = tercentang + "Belum diopname", BETA sudah diopname = tidak tercentang + "Sudah diopname hari ini · 09:15", Document ALPHA = 7 (subtree), footer "N folder dipilih · M dokumen" = baris tercentang, centang-semua lalu lepas (baris All Archive tetap), Batal tanpa sesi = tanpa POST/DELETE, Next = POST body `{idArchive:null,isContinue:false,folders:[ALPHA,GAMMA]}`. e2e BETA: baris folder opname sudah diopname = "(dicentang ulang)" + Lanjut opname aktif + teks; T1 dicentang = "(dicentang ulang)" + switch + teks, footer "2 folder dipilih · 2 dokumen" jadi "3 folder dipilih · 4 dokumen"; switch mati = "Tidak lanjut opname" + teks merah; POST `isContinue` BETA false, T1 true, T2 false. e2e ALPHA: sub-subfolder tidak bisa dipilih, S2/S3 dilepas = footer "2 folder dipilih · 5 dokumen", POST `folders=[S1]` | PASS | `ac7-step1-root.png`, `ac7-step1-beta-dicentang-ulang.png`, `ac7-step1-beta-tidak-lanjut.png`, `ac7-step1-alpha-sebagian.png`, `ac7-step1-gamma-sesudah-konfirmasi.png`; test "Step 1 dari + di root", "AC-7 BETA", "ALPHA: subfolder dicentang sebagian" |
| AC-8 `[BE+FE]` | e2e ZULFA (folder nyata di PUSAT - MAGELANG, menu baris > Opname): `skip_select_folder` true, stepper 2 langkah (Opname Folder, Konfirmasi), tag "ZULFA", tombol kiri "Batal" tanpa "Kembali", POST `folders: []`, footer "ZULFA · N dokumen", Batal tanpa scan = DELETE langsung. Sama untuk GAMMA/DELTA/EPS/VIEWONLY. Bagian BE = `qa-be.md` AC-8 (26 asersi, regresi PASS) | PASS | `ac8-zulfa-step2-tanpa-step1.png`; test "AC-8 ZULFA" |
| AC-13 `[FE]` | e2e Step 2: ketik + Enter di input; scanner USB disimulasikan dengan ketikan cepat (delay 0) + Enter dengan fokus di luar input (klik judul modal) dan sesudah memilih filter tanpa klik input; kode sama < 2 dtk diabaikan tanpa POST; ikon scan membuka modal kamera yang tetap terbuka sesudah scan lewat modal itu, "Scan terakhir" berganti (kode + hasil); ✕ menutup dan fokus kembali ke input; pencarian Archive tidak terpicu selama modal opname terbuka (jumlah GET archives tetap), terpicu lagi sesudah modal ditutup (batal dan Confirm) | PASS (e2e); kamera nyata + scanner USB fisik = MANUAL (Gate 2, task 005 butir 12-13) | `ac14-step2-awal.png`, `ac4-modal-kamera.png`, `ac13-modal-kamera-sesudah-scan.png`; test "AC-13/14/17 GAMMA" dan "ALPHA" |
| AC-14 `[FE]` | e2e: Segmented Step 2 `{Semua 4, Terverifikasi 2, Tidak ditemukan 1, Tidak valid 1}` = kartu sesi `{4,2,1,1}`; tiap filter menyaring tabel dan fokus kembali ke input; baris Invalid berkelas `tr.row-invalid`, Not found `tr.row-not-found` (tepat 1 masing-masing); hasil di tabel Terverifikasi/Tidak ditemukan/Tidak valid; tag judul "0QA26-ALPHA + 1 subfolder", "0QA26-BETA-T1 + 1 subfolder", satu subfolder = namanya, root = "All Archive · root". Setelah Back + PUT, S2D1 dinilai ulang jadi Terverifikasi dan kelas not-found hilang | PASS (e2e); penilaian warna = MANUAL (task 005 butir 9; tangkapan layar menunjukkan merah muda dan kuning muda) | `ac14-step2-awal.png`, `ac4-modal-kamera.png` (latar baris), `ac17-step3-discan.png` |
| AC-17 `[FE]` | e2e Step 3: filter awal "Discan 6"; kartu Total/Terverifikasi sesi ini/Tidak ditemukan/Tidak valid/Belum discan = counts (Belum discan = Total - Verified, "Semua" = 3 + 1 + 2 = 6); peringatan per folder (BETA tanpa lanjut: "...dijalankan tanpa lanjut opname — 1 dokumen..."); kolom Folder: Not found "Di luar scope opname", Invalid "—", dokumen root "All Archive" (dokumen root nyata ikut cakupan lewat baris All Archive); `result=not_found&page=1` (snake_case), `result=all`, `page=2` (EPS 12 dokumen, 10 per halaman); Back ke Step 2 dengan scan sama, Back ke Step 1 lalu Next = PUT bukan POST, Step 2 memuat ulang scan; Confirm = Popconfirm "Konfirmasi opname ini? ..." lalu PUT confirm, toast "Opname berhasil dikonfirmasi", modal tertutup tanpa DELETE, GET list Archive baru; DB: GD1-3 `is_verified=1`, AD1 (Not found) 0, sesi GAMMA `status 2 total 3 scanned 6 verified 3 not_found 1 invalid 2 unscanned 0`; sesudahnya GAMMA "Sudah diopname hari ini", Step 1 tidak dilewati, "(dicentang ulang)" + Lanjut aktif. `ARCHIVE415` (draft dimundurkan 1 hari lewat fixture): toast pesan BE, modal tetap di Step 3, Batal = DELETE | PASS | `ac17-step3-discan.png`, `ac17-step3-semua.png`, `ac17-step3-popconfirm.png`, `ac17-step3-peringatan-beta.png`, `ac17-step3-paginasi-hal2.png`, `ac17-step3-root-dokumen-tanpa-folder.png`, `ac17-arch415-tetap-step3.png` |
| AC-22 `[BE+FE]` | e2e DELTA: sesi B (UI) pilih folder + scan DD1; sesi A (API, token e2e, user sama) membuat dan mengonfirmasi sesi DELTA sesudah B memilih; B Finish > Confirm > 400 `ARCHIVE414` (`msg_code`), toast "Folder 0QA26-DELTA sudah diopname oleh <user QA> pukul HH:mm selama sesi ini berjalan. Pilih ulang folder"; modal kembali ke Step 1 (stepper 3 langkah), DELTA "Sudah diopname hari ini", "(dicentang ulang)" + Lanjut aktif; Next = PUT (tanpa POST baru), scan B masih ada (DD1); Confirm kedua 200. DB: dua sesi DELTA berstatus 2, DD1 `is_verified=1`. Bagian BE = `qa-be.md` AC-22 (65 asersi + `EXTRA-CONCURRENT`, regresi PASS) | PASS | `ac22-kembali-step1.png`, `ac22-bentrok-toast.png`; test "AC-22 DELTA" |

## Defect

Tidak ada defect AC (fail = 0). Temuan di bawah tidak memblokir.

**F-1 (dokumentasi, rendah)**: AC-3 bagian MANUAL "tukar lisensi sungguhan sekali, di Gate 2 epic" (tanpa Salesman Activity: menu Archive tidak ada, route FE tertolak, endpoint 403 `GE0114`) tidak muncul di `## Daftar tes UI` task mana pun (`ksp-react/docs/specs/Archive/003-006-*.md`); hanya tercatat di `epics/ED-1022-gate1.md` EPIC K-2 b dan `qa-be.md`. Usul: satu butir di task 003 (ED-1056, FE) atau di daftar Gate 2 epic. Pemilik: ED-1056 (docs task) / orkestrator. Tidak mengubah verdict (tidak ada AC tanpa cek; perilaku dan guard kode lulus), sama dengan F-1 ED-1025.

## Observasi non-blokir (bukan defect)

- O-1 (kosmetik) Step 2/3: sampai `GET opnames/{id}` selesai (puluhan hingga ratusan ms; lebih lama untuk sesi root besar) tag judul masih memakai tag Step 1 ("All Archive · root" atau nama folder) dan teks footer kosong, lalu berganti ke "<pertama> + N subfolder". Tertangkap oleh asersi e2e yang memakai poll. `ArchiveOpnameModal/index.js` (pilihan `tag`/`footerText`), ED-1056/ED-1064.
- O-2 Judul/tombol mengikuti locale ID aplikasi ("Opname Dokumen", "Konfirmasi Opname", "Batal", "Lanjut", "Kembali"), bukan teks Inggris desain; konsisten dengan item Archive lain. Angka memakai pemisah koma (format angka user QA).
- O-3 `ModalAdd` dipasang dengan `wrapClassName="modal-opname-archive"` (spec §5 menulis `className`); sudah tercatat di catatan task 003.
- O-4 Kunci locale `archive.*`: 90 kunci en-US = 90 kunci id-ID, semua kunci `archive.*`/`common.*` yang dipakai kode baru ada di kedua bahasa (skrip periksa statis). Tampilan bahasa Inggris tidak dijalankan di browser.

## Galeri e2e & regresi BE

**Galeri (kanonik): `/var/www/opname-archive/agentic/work/e2e/ED-1026-archive-opname-document-20261007-065419/index.html`** (run `main`: 10 OK, 0 GAGAL, 1 SKIP = test norole; 24 tangkapan layar di `screenshots/default/`).
Run `norole`: `/var/www/opname-archive/agentic/work/e2e/ED-1026-archive-opname-document-20261007-064256/index.html` (2 OK, 0 GAGAL, 9 SKIP = test mode main). Folder `...-064043` = run `main` sebelumnya (spec tanpa satu asersi frame berulang), bisa diabaikan.

Berkas e2e (permanen): `features/ED-1026-archive-opname-document/qa/e2e/` = `ED-1026-opname.spec.js`, `fixture.php` (up/down/ids/inspect/backdate), `fixtures.json`, `routes.json`. Pemakaian: `E2E_WORKERS=1 scripts/e2e/run.sh ED-1026` dan `QA26_MODE=norole E2E_WORKERS=1 scripts/e2e/run.sh ED-1026`.

| Langkah | Hasil | Bukti |
|---|---|---|
| `scripts/fe-build.sh build` | "Compiled successfully", exit 0, 63 dtk | `work/qa-fe-ed1026-build.out`, `work/fe-build.log` |
| `yarn test --watchAll=false src/containers/Archive` | 7 suite, 149/149 lulus | `work/qa-fe-ed1026-test-archive.txt` |
| Full suite FE (sekali) | 75 suite: 68 lulus, 7 gagal; 1470 test: 1462 lulus, 8 gagal (335,7 dtk). 4 suite = baseline lama (Production.function, InterbankTransferForm, components/Item/BatchOut, CustomerPurchaseOrderView); 3 suite = timeout 5000 ms karena beban (ArchivePage, ItemRaptorImport, RaptorPendingItemsPanel) | `work/qa-fe-ed1026-test-full.txt` |
| Ulang 7 suite itu sendiri-sendiri `--runInBand` | 3 suite timeout lulus semua; 4 baseline tetap gagal (4 test, tidak menyentuh berkas ED-1026) | `work/qa-fe-ed1026-test-rerun.txt` |
| Regresi BE ED-1026 (`qa/`, 37 skenario) | PASS=37 FAIL=0 (AC-1..27 BE, EXTRA-*, R2-*) | `work/qa-fe-ed1026-regress-1026.txt`, `work/qa-http/ED-1026-20261007134722.json` |
| Regresi BE ED-1024 | PASS=26 FAIL=0 | `work/qa-fe-ed1026-regress-1024.txt` |
| Regresi BE ED-1025 | PASS=30 FAIL=1: `AC-1` hanya asersi usang "kunci Updater ED-1025 terbesar di `config.php`" (Updater ED-1026 sengaja lebih baru). Bukan cacat produk, sama dengan QA BE ED-1026 N-3; berkas ED-1025 tidak diubah | `work/qa-fe-ed1026-regress-1025.txt` |

**Pengecekan akhir DB `api_sidomaju`** (sesudah semua e2e dan regresi): `archive_opnames`, `archive_opname_folders`, `archive_opname_documents` = 0 baris; `archives` 49.145 baris, 0 baris kolom verifikasi tak-default, 0 baris `QA%`; `archive_documents` 49.126; `archive_permissions` 0; `archive_locations` 48.033. `CHECKSUM TABLE` archives 39393776, archive_documents 1197277553, archive_permissions 0, archive_locations 3991310582 = nilai sebelum QA FE. Teardown fixture memverifikasi hal sama tiap run (7 tabel + user/role, exit 0). User QA: role 3, bahasa ID, `is_all_location=1`, satu baris `employee_locations` (id 271), `role_permissions` Opname Document role 1/2/3 (id 3179421-3179423) utuh; tanpa berkas jurnal/ids sisa; 0 token aktif; permission 1133 tetap ada. Satu-satunya jejak yang tidak bisa dipulihkan: `users.updated_at` user QA berubah oleh setiap login (efek samping auth, bukan data uji).

## Yang tidak bisa diverifikasi (dan kenapa)

1. Kamera nyata (AC-13): tidak ada kamera fisik; e2e hanya membuka/menutup modal dan memakai scanner USB tiruan di modal itu. MANUAL, task 005 butir 13.
2. Scanner USB fisik (AC-13): disimulasikan dengan ketikan cepat; interval antar-karakter perangkat nyata tidak diuji. MANUAL, task 005 butir 12 dan 14.
3. Penilaian warna `.row-invalid`/`.row-not-found` dan kemiripan tampilan dengan desain (AC-14): manusia di Gate 2 (task 005 butir 9). Tidak dipakai untuk menggagalkan AC.
4. Tukar lisensi sungguhan (AC-3): `PROFILES` kosong; EPIC K-2 b = manual di Gate 2 epic (lihat F-1).
5. Jalur error `GET opnames/folders` 403/404 di UI (task 004 butir 15) dan toast `ARCHIVE412/413`: butuh mencabut hak saat modal terbuka; hanya tercakup unit test (`ArchiveOpnameModal/index.test.js`) dan regresi BE.
6. Tampilan bahasa Inggris (lihat O-4) dan layar mobile: tidak dijalankan.

Catatan proses: satu perintah gabungan (penyamaran username QA di keluaran runner + regresi) sempat ditolak pemeriksa keamanan Claude Code, bukan oleh developer; tidak diakali, dikerjakan ulang sebagai perintah terpisah tanpa penghapusan. Username QA disamarkan jadi `<QA_USER>` di keluaran runner di `work/` (termasuk laporan `work/qa-http/*.json` lama yang memuatnya); tidak ada username/password di `qa-fe.md`, spec e2e, atau galeri.

## Riwayat ronde

- Ronde 1 (2026-10-07): 8 AC scope fe (7 PASS, 1 MANUAL), 0 defect, 1 temuan dokumentasi (F-1); e2e 10 OK + 2 OK (norole), build bersih, test Archive 149/149, regresi BE ED-1026 37/37, ED-1024 26/26, ED-1025 30/31 (AC-1 usang); DB dibiarkan seperti ditemukan.
