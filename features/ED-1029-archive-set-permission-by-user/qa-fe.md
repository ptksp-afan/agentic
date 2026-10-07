---
key: ED-1029
scope: fe
round: 2
verdict: PASS
counts: {pass: 9, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

Scope FE ED-1029 (subtask ED-1047, QA ED-1053), ronde 2. Dari 10 AC milik stage ini (`[FE]` 1-3, 14-19 dan `[BE+FE]` 20):
**9 PASS, 0 FAIL, 1 MANUAL** (AC-20, tukar lisensi sungguhan di Gate 2 epic). Tidak ada 5xx dari alur item ini.

Pemeriksaan ulang temuan ronde 1 (dikerjakan lebih dulu):
- **D-1 / AC-3 tertutup.** Guard lokal di `ArchivePermissionPage` (opsi c; `PrivateRoute` dan route lain sengaja tidak diubah, perbaikan global tetap di ED-967)
  terbukti di browser pada build staging baru. E (tanpa `Update Folder`): path tab aktif `/archives/permissions` -> `/unathorized`, halaman 403 ter-render,
  kotak Search folder tidak pernah ter-render, 0 request `user-permissions` dan 0 request `select/document-archive/archive/users`. A (ber-`Update Folder`): lewat URL
  langsung, dua kali hard reload (F5) dan lewat menu "+": path tab tidak pernah menjadi `/unathorized`, `.ant-result-403` tidak pernah muncul di DOM (tanpa kilasan).
- **F-1 tertutup.** Butir MANUAL AC-20 kini ada sebagai butir 23 `## Daftar tes UI` task 012; butir 10 diselaraskan dengan guard lokal (Unauthorized tanpa request select user maupun
  user-permissions; A tanpa kilasan).

Hasil lain: build staging PASS; jest modul Archive 11 suite / 262 test hijau; suite penuh = hanya 4 baseline + 8 suite timeout beban yang hijau semua pada `--runInBand`;
e2e penuh item ini **16 OK, 0 GAGAL** (4 smoke + 12 flow); regresi BE 1024-1029: tidak ada regresi (hanya yang usang dan diketahui). Data uji dipulihkan persis
(checksum 7 tabel identik dengan sebelum run). Penolakan izin/classifier: tidak ada. Tidak ada entri allow-list. Kode aplikasi, spec, kontrak tidak diubah QA.

Berkas QA yang saya ubah ronde ini (milik item ini, di `qa/e2e/`): `ED-1029-user-permission.spec.js` (pembantu `trackNavigation` + `trackUsersCalls`, penguatan tes AC-3 E, tes baru
AC-3 A) dan `routes.json` (`forbidApi` entri E kini juga mencakup `select/document-archive/archive/users`).

## Hasil per AC

Pelaku e2e: A = QA_USER (role 3, non-bypass, bahasa ID; employee dibatasi ke lokasi MGL sementara agar folder L di luar scope-nya), E = QA_USER2 (role 5 diganti role 18 sementara:
List Archive + Handover/Receive/Add Document, tanpa Update Folder; bahasa EN). B = `admintest`, B2 = `dias` (user target dari select users). Folder uji `0QA29-`: P On (A penuh, B View) > C1 On, C2 Off (B View+Store);
Q On (A View saja, B View+Update); R Off > R1 On (A penuh); L On di luar lokasi A. Semua dibuat/dibuang fixture (`qa/e2e/fixture.php`).

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | unit `getArchiveAddKeys` + `ArchivePage` (jest Archive hijau); e2e A (root dan di dalam P) dan E (root dan di dalam R) | PASS | e2e A: "+" root berisi ... Opname Dokumen, **Atur Izin per User** (terakhir); di P 6 item, terakhir Atur Izin per User. E: root = Hand Over/Receive Document, di R = + Store Document, tanpa item itu. `work/e2e/ED-1029-...-190206/` tes "AC-1 + AC-2 + AC-14 A" dan "[user2] AC-1 E" (OK) |
| AC-2 | e2e A: lewat "+" dari dalam P, lewat URL, dan sesudah F5 | PASS | Klik item -> `/archives/permissions`; header "Izin Arsip"; menu samping "Arsip" terpilih; sebelum pilih user: 0 request `user-permissions`, tabel 0 baris + "Pilih user untuk melihat izin foldernya", Reset dan Simpan Izin disabled; `select/document-archive/archive/users` dipanggil tanpa `excepts`; F5 -> tabel kosong lagi. Monitor otomatis: 0 error konsol/toast/API >= 400. Tes "AC-2 A" dan smoke A (OK) |
| AC-3 | e2e E: buka `/archives/permissions` lewat URL; e2e A: URL langsung + 2x F5 + lewat "+"; smoke `expectRedirect=/unathorized` + `forbidApi=user-permissions\|select/document-archive/archive/users` | **PASS** (ronde 1: FAIL) | **E** (tes "[user2] AC-3 E" + smoke E): riwayat path tab aktif `["/archives/permissions","/unathorized"]`; `.ant-result-403` ter-render (screenshot `screenshots/user2/AC3-01-E-buka-URL.png`: "403 You are not authorized to access this page."); kotak Search folder pernah tampil = false; request `user-permissions` = 0; request select user = 0. **A** (tes "[default] AC-3 A"): paths `["/archives/permissions"]` pada URL langsung dan pada F5 #1/#2, `["/archives/permissions","/archives","/archives/permissions"]` sesudah lewat "+"; `unauthorized=false` di semua tahap; `.ant-result-403` count 0; path tab tetap `/archives/permissions`; halaman tampil (`AC3A-02-sesudah-F5.png`). Detektor terbukti hidup: pada E menandai `unauthorized=true`, pada A menandai `search=true`. Catatan metode: app berbasis tab (URL browser tetap "/", path aktif = `appTab` di sessionStorage), jadi detektor mencatat tulis `appTab`/`appTabActiveKey` + mutasi DOM, bukan `history` |
| AC-14 | e2e A: pilih B; **semua baris** DOM dibandingkan dengan response `GET user-permissions/{B}`; asersi nama folder uji. Tampilan = MANUAL | PASS (fungsi); tampilan MANUAL | 21 baris cocok dengan DFS response: id/urutan, level indentasi, tag On/Off, nilai tersimpan, disabled = bukan(On dan `access.manage_permission`), ikon buka-tutup hanya pada folder ber-subfolder, semua terbuka. P, C1, R1 bisa dicentang; C2 (Off), Q (A tanpa Update), R (Off) terkunci; nilai tersimpan C2 (View+Store) dan Q (View+Update) terlihat; L tidak ada; info BR-9 tampil tanpa "termasuk child". Tampilan: `## Daftar tes UI` butir 3-9. Desain `epics/ED-1022-brief/render/tiles/design-03.png`: field User, Search folder, Reset, Save Permission, info, kolom Folder/Permission/View/Update/Delete/Store ada |
| AC-15 | unit (`changeUserPermission`, `archive.function.test.js`) + e2e A pada C1 dan P | PASS | e2e: centang Update -> View ikut; Delete -> V,U,D; Store -> keempatnya; lepas Update -> View tetap; lepas View -> keempatnya lepas, Reset+Simpan disabled lagi. Sel terkunci disabled dan klik paksa tidak mengubah nilai. Tidak ada request BE selain GET user |
| AC-16 | unit `filterUserPermissionTree` + e2e A | PASS | Centang Store di P dan View di C1, cari "c1" -> tepat P dan C1; "0qa29-P-c" (huruf campur) -> P, C1, C2; "BRANGKAS" -> induk + cocok sama dengan hitungan dari response; tanpa hasil -> 0 baris. Selama disaring perubahan tetap dan Simpan hidup; dikosongkan -> pohon penuh, perubahan masih ada; pencarian tanpa request |
| AC-17 | e2e A: ubah P dan C1 (+ R1 diubah lalu dikembalikan), Simpan; cek request, toast, GET ulang, DB, riwayat; lalu hapus semua hak P | PASS | `PUT .../user-permissions/{B}` body persis 2 folder (P isView 1,isStore 1; C1 isView 1; integer; R1 yang kembali ke nilai tersimpan tidak terkirim); 200 `ARCHIVE207`; toast sukses; urutan request GET, PUT, GET; tabel dimuat ulang, Simpan/Reset disabled. DB: P=1001, C1=1000, C2 dan Q utuh, B2 tanpa baris; riwayat `permission` P +1 dan C1 +1 oleh `imansudjadi`, folder lain 0. BR-16: `GET archives/{P}` dan tab Permission drawer P memuat B = View+Store. Hapus: body P keempat flag 0, baris (P,B) terhapus, riwayat P +1 |
| AC-18 | e2e A: Reset; ganti User dengan perubahan (Tidak lalu Ya); ganti User tanpa perubahan | PASS | Reset: baris kembali ke nilai tersimpan, 0 request, tanpa konfirmasi. Ganti ke B2 saat dirty: konfirmasi "Perubahan izin admintest yang belum disimpan akan hilang. Ganti user?" (Tidak/Ya). Tidak: tetap di `admintest` dengan centang dan Simpan hidup, 0 request B2. Ya: `GET user-permissions/{B2}` 200, perubahan B hilang. Tanpa perubahan: ganti langsung tanpa konfirmasi. Tidak ada PUT |
| AC-19 | e2e A: centang C1 View dan P Store; `fixture.php flag P-C1 0` sebelum Simpan | PASS | PUT 400 `ARCHIVE441` "Folder permission belum aktif di folder 0QA29-P-C1"; toast error "[400] ..."; centang di layar tidak berubah, Simpan dan Reset tetap hidup, tidak ada GET ulang. DB: tidak ada yang tersimpan (atomik), riwayat P 0. 400 + log AxiosError dikeluarkan dari monitor secara eksplisit (2 entri, diharapkan) |
| AC-20 | `[BE+FE]` cara seragam EPIC K-2 b: BE = regresi `scenario3-access` AC-20 + X-PERM; FE = guard di kode; tukar lisensi sungguhan = manual | MANUAL (Gate 2 epic); bagian BE dan guard-kode PASS | BE ronde ini: ED-1029 `AC-20` PASS (17 asersi) dan `X-PERM` PASS (157 asersi; 403 `GE0114` tanpa Update Folder, data tetap) - `work/qa29r2-reg-ED-1029.txt`. FE: `src/configuration/menus.js:169` `permission: hasSalesmanFeature ? permissions.ListArchive : shouldNotAppear`; `src/routes/routes.js:2100-2109` `PrivateRoute permission={permissions.UpdateFolder}` + guard lokal halaman (kini hidup: klausa "route FE ditolak" terbukti lewat mekanisme yang sama dengan AC-3 E, karena `Update Folder` hanya ada di `permission_salesman.sql`). Tukar lisensi sungguhan: `PROFILES` kosong; butir 23 `## Daftar tes UI` (F-1 tertutup) |

Tambahan di luar AC (semua PASS, tetap hijau di run penuh): BR-4/#7 ikon buka-tutup folder induk; BR-15/#21 A dengan role 1 sementara (L ikut tampil, `access.manage_permission` true di semua folder, semua folder On bisa dicentang,
Off tetap terkunci); bahasa EN halaman (judul "Archive Permission", placeholder "Search folder", info, teks kosong, tombol; tanpa "Missing message").

## Defect

Tidak ada defect baru. D-1 (AC-3) dan F-1 (dokumentasi) dari ronde 1: **tertutup** (lihat AC-3 dan ringkasan). Tidak ada defect BE, tidak ada 5xx dari alur item ini
(5xx yang tercatat di regresi BE hanya probe karakterisasi ED-1070/ED-1071 yang sengaja hijau, dilacak di luar item ini).

Pengamatan (bukan defect, tidak menggagalkan AC): pada tangkapan layar penuh halaman, tajuk kolom tabel (mis. "Letakkan" pada bahasa ID) terpotong pada lebar kolom 90 px dan bilah header
tabel tampak terpisah dari baris (sticky header di screenshot `fullPage`). Itu penilaian tampilan, sudah masuk butir 3-9 / 8 `## Daftar tes UI` untuk Gate 2; tidak digagalkan (aturan: look beda dari desain bukan alasan gagal).

## Galeri e2e & regresi BE

**Build dan test**

| Langkah | Hasil | Bukti |
|---|---|---|
| `scripts/fe-build.sh build` (build staging baru, 18:57) | PASS | `work/qa29r2-build.txt`, `work/fe-build.log`: "Compiled successfully." (tanpa warning eslint), exit 0, 72,1 dtk; string "Select a user to see their folder permissions" dan `unathorized` ada di `static/js/9715.6723d842.chunk.js` |
| `yarn test --watchAll=false src/containers/Archive` | PASS 11 suite / 262 test | `work/qa29r2-jest-archive.txt` (ronde 1: 259; +3 test guard). `ArchivePermissionPage` 13 test termasuk 3 test guard (E dialihkan `REPLACE /unathorized` tanpa request; A tidak dialihkan; `app.user` null tidak dialihkan lalu halaman tampil) |
| suite penuh | 79 suite / 1583 test; run pertama 12 suite gagal (14 test) | `work/qa29r2-jest-full.txt`: baseline diketahui (4 suite: production.function 2 test, InterbankTransferForm 1, components/Item/BatchOut 1, CustomerPurchaseOrderView gagal-jalan `electronEvent`) + 8 suite timeout beban ("Exceeded timeout of 5000 ms", 10 test): ArchiveDrawer, ArchivePage, ArchivePermissionPage, RaptorFailedPage, RaptorPendingItemsPanel, RaptorPendingView, RaptorPendingDrawer, RaptorSetupPage |
| 8 suite timeout, `--runInBand` | PASS 11 suite / 261 test | `work/qa29r2-jest-rerun.txt` (semua hijau; tidak ada yang tersisa selain 4 baseline, yang tidak menyentuh berkas diff item ini) |

**E2E** (`E2E_WORKERS=1 scripts/e2e/run.sh ED-1029 --profile default,user2`): **16 OK, 0 GAGAL, 0 SKIP** (smoke 4 + flow 12). Galeri/laporan akhir:
`work/e2e/ED-1029-archive-set-permission-by-user-20261007-190206/` (`index.html`, `report.json`, `screenshots/default|user2/`), keluaran `work/qa29r2-e2e-3.txt`.
Run iterasi ronde ini: `...-185838` (14 OK / 2 GAGAL: dua pemeriksaan AC-3 yang baru saya tulis gagal karena detektor pertama membaca `history`, padahal app berbasis tab dengan URL tetap "/" - kesalahan spek QA, bukan FE;
detektor diganti ke path tab aktif + mutasi DOM) dan `...-190132` (`--grep AC-3`: 2 OK). Ronde 1 `...-181154` (13 OK / 2 GAGAL) tidak lagi berlaku.

| Tes | Profil | Hasil |
|---|---|---|
| AC-1 + AC-2 + AC-14 (menu "+", halaman lewat "+", pohon user B) | default | OK |
| AC-2 lewat URL + F5 | default | OK |
| **AC-3 A (URL langsung, 2x F5, lewat "+"; tanpa kilasan Unauthorized)** - baru ronde 2 | default | OK |
| AC-15 centang View-wajib | default | OK |
| AC-16 Search folder | default | OK |
| AC-17 Simpan (+ DB, riwayat, tab Permission, hapus baris) | default | OK |
| AC-18 Reset dan ganti user | default | OK |
| AC-19 BE menolak 441 | default | OK |
| BR-4 buka-tutup folder induk | default | OK |
| BR-15 superadmin (role 1 sementara) | default | OK |
| smoke `/archives/permissions` A, `/archives` A | default | OK, OK |
| AC-1 E ("+" tanpa item) | user2 | OK |
| **AC-3 E (Unauthorized, tanpa request; diperkuat ronde 2)** | user2 | **OK** (ronde 1: GAGAL) |
| smoke `/archives/permissions` E (`expectRedirect=/unathorized`, `forbidApi` diperluas) | user2 | **OK** (ronde 1: GAGAL) |
| smoke `/archives` E | user2 | OK |

**Regresi BE** (semua `qa/scenario*.php` item 1024-1029 di working tree, `server fpm`, tanpa reload; keluaran `work/qa29r2-reg-ED-10xx.txt`):

| Item | Hasil | Catatan |
|---|---|---|
| ED-1024 | PASS 26, FAIL 0 | bersih |
| ED-1025 | PASS 30, FAIL 1 | AC-1 usang (Updater "kunci terbesar" `Updaters/config.php`, diketahui). ED-1071 X-12 PASS dan ED-1070 X-13 PASS (karakterisasi tetap hijau) |
| ED-1026 | PASS 34, FAIL 3 | usang diketahui: AC-1 (sama), AC-12 dan AC-15 (diubah sah oleh ED-1028) |
| ED-1027 | PASS 20, SKIP 1 | X-3 SKIP: isolasi tenant, `QA_DBS` satu DB |
| ED-1028 | PASS 22, FAIL 0 | bersih |
| ED-1029 | PASS 22, FAIL 0, SKIP 1 | X-ISO SKIP (satu DB). X-1 PASS = karakterisasi ED-1071 (16 respons 500 pada id non-Windows-1252, dilacak di luar item, bukan regresi). AC-20 dan X-PERM hijau |

Tidak ada regresi BE. Berkas QA item lain tidak diubah. e2e ED-1025 tidak dijalankan ulang ronde ini (tidak diminta; diff sejak ronde 1 hanya `ArchivePermissionPage`, test, dan dokumen; usangnya jumlah tab drawer = 3 dan jumlah item "+" = 6 sudah dibuktikan ronde 1).

**Pemulihan data uji:** snapshot read-only (jumlah baris/CHECKSUM) sebelum dan sesudah semua run identik: `archives` 49145 / 39393776, `archive_documents` 49126 / 1197277553, `archive_locations` 48033 / 3991310582,
`archive_permissions` 0 / 0, `user_roles` 126, `employees` 124, `employee_locations` 157; 0 baris `0QA29-`. Fixture `down` memverifikasi baseline pada tiap run. Semua token run di-log-out
(harness: "log-out 2/2 token dicabut"; runner BE: "masih aktif: 0" pada keenam item).

## Yang tidak bisa diverifikasi (dan kenapa)

- Tukar lisensi sungguhan tanpa Salesman Activity (AC-20): `PROFILES` kosong; MANUAL Gate 2 epic (butir 23 `## Daftar tes UI`). Menu Archive tersembunyi tanpa fitur hanya dicek di kode (`menus.js:169`).
- Tampilan (butir 3-9 `## Daftar tes UI`, termasuk lebar ponsel butir 8): penilaian visual manusia, MANUAL Gate 2. Struktur dan alur sesuai desain; tampilan mengikuti EQUAL.
- Isolasi tenant: bukan lingkup stage fe (`QA_DBS` hanya satu DB; di BE sudah `not_verifiable`/SKIP).
- Toast tidak terlihat di screenshot penuh `AC19-01-toast-error-441.png`; isi dan ikon toast dibuktikan lewat asersi teks/DOM.
- Kilasan Unauthorized dibuktikan lewat detektor DOM/path di browser headless pada satu mesin; kilasan sub-frame yang lebih singkat dari satu siklus mutasi DOM tidak dapat dibuktikan secara mutlak (jalur kode: halaman me-render `null` sampai `app.user` terisi, dibuktikan juga oleh test unit "app.user null").

## Riwayat ronde

- Ronde 1 (2026-10-07): 10 AC: 8 PASS, 1 FAIL (AC-3, D-1 guard `PrivateRoute` inert, pra-ada/global), 1 MANUAL (AC-20). Build PASS; jest Archive 259/259; e2e 13 OK / 2 GAGAL (AC-3 E); regresi BE tanpa regresi. Temuan dokumentasi F-1 (butir AC-20 belum di `## Daftar tes UI`).
- Ronde 2 (2026-10-07): 9 PASS, 0 FAIL, 1 MANUAL. D-1 tertutup (guard lokal `ArchivePermissionPage`, E ke Unauthorized tanpa request, A tanpa kilasan termasuk F5) dan F-1 tertutup (butir 23). Build PASS; jest Archive 262/262; suite penuh hanya 4 baseline + timeout beban yang hijau pada `--runInBand`; e2e 16 OK / 0 GAGAL; regresi BE tanpa regresi; data uji identik.
