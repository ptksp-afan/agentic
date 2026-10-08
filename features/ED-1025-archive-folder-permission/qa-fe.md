---
key: ED-1025
scope: fe
round: 1
verdict: PASS
counts: {pass: 13, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

QA FE **ronde 1 run revisi** (subtask ED-1087) untuk perbaikan review batch 2026-10-08 (FE task 013 = ED-1085, 014 = ED-1086; 12 berkas M + 2 baru vs HEAD `7fa63869d`). AC milik scope fe: AC-3, 17, 18, 19, 20, 21, 23 (`[BE+FE]`), 30, 31, 32, 33, 34, 35, dan AC-36 (bagian FE/regresi). **Semua perilaku FE yang diminta spec lulus; tidak ada defect kode FE/BE dan tidak ada respons 5xx dari alur FE.** AC-23 tukar lisensi sungguhan tetap **MANUAL (Gate 2 epic)** (`PROFILES` kosong).

> **INSIDEN (wajib dibaca developer/orkestrator): data opname milik developer terhapus oleh QA ini.** Saya menjalankan e2e ED-1026 (dan ED-1028, ED-1027) di DB uji bersama padahal `qa-be.md` T-1 sudah mencatat tabel opname berisi data developer. Fixture `ED-1026/qa/e2e/fixture.php up` berhenti di prasyarat ("tabel archive_opnames tidak kosong"), lalu harness (sesuai desain: "setup gagal -> fixture dibuang lagi") menjalankan `fixture.php down`, yang memanggil `purge()` = `DELETE` **seluruh** tabel opname (`archive_opnames`, `archive_opname_folders`, `archive_opname_documents`) dan mengosongkan kolom verifikasi (`is_verified`, `verified_at`, `verified_by`, `id_archive_opname`) pada baris `archives` yang menunjuk sesi. Akibat terverifikasi (DB, 19:2x): tiga tabel opname = 0 baris (T-1 mencatat 13 sesi + 45 folder + 12 dokumen opname milik `kevinsudjadi.adm`), dokumen terverifikasi `0` (layar Archive saya pada 19:08 menampilkan "Dokumen Terverifikasi 4 / 33,251"). **Tidak ada cadangan yang bisa saya pakai (binlog mati); saya tidak mencoba memulihkan.** Saran: developer memulihkan dari cadangan DB-nya atau membuat ulang lewat UI. Akar masalah ada di fixture e2e ED-1026/1027/1028 (D-1), bukan di kode produk. Rincian di bagian Defect/Temuan.

- **Build**: `scripts/fe-build.sh build` exit 0, "Compiled successfully" (78 dtk) ke `/tmp/ksp-react-v5-build` (bukan `build/`); tidak ada berkas `src/`/`public/` lebih baru dari build (`find -newer` kosong). Peringatan harness "build mungkin usang" hanya karena 14 berkas FE belum di-commit.
- **Unit test Archive** (`yarn test --watchAll=false src/containers/Archive`): 11 suite, 318 test, 316 lulus, 2 gagal: (a) `ArchiveVerificationModal` "Tutup" tidak ditemukan = **pra-ada di HEAD bersih** (commit developer `7fa63869d`, sama persis di salinan `git archive HEAD`); (b) `ArchivePage` "tooltip ikon perisai 'Akses Terbatas'" `Unable to find role="tooltip"` = test baru yang memakai `findByRole` dengan timeout bawaan 1 dtk: **lulus 20/20 sendirian, 4 dari 4 run** (`ed1025-rev-fe-archivepage-alone*.txt`), gagal hanya pada run multi-suite saat mesin dibebani (2 dari 3). Lihat C-1.
- **Full suite** (`yarn test --watchAll=false`): 79 suite / 1639 test; run paralel saat load 8-12 = 14 suite / 35 test gagal (timeout). Diulang `--runInBand`: sisa hanya `ArchivePage` tooltip (flake, lulus sendirian), `RaptorPendingItemsPanel` (modul tak tersentuh; lulus sendirian) dan 5 suite baseline. **Set kegagalan sama persis dengan HEAD bersih**: `Production/production.function` (2 test), `InterbankTransfer/InterbankTransferForm` (1), `components/Item/BatchOut` (1), `CustomerPurchaseOrderView` (gagal dimuat), `ArchiveVerificationModal` (1) (`work/qa-runs/ed1025-rev-fe-baseline-current.txt` vs `...-baseline-head.txt`; salinan HEAD dibuang). Tidak ada kegagalan baru dari diff.
- **E2E ED-1025** (`scripts/e2e/run.sh ED-1025 --profile default,user2 --workers 1`): **17 OK / 0 GAGAL** (15 flow + 2 smoke route), 0 error konsol/page/HTTP >= 400 selain 403 `ARCHIVE407` yang diharapkan (dikeluarkan eksplisit), 0 respons 5xx, tanpa allow-list. Galeri: `work/e2e/ED-1025-archive-folder-permission-20261008-190715/` (60 screenshot). Percobaan pertama 9 OK / 8 GAGAL, semuanya salah di alat uji saya, bukan di produk: toast sukses sah setelah Save dihitung sebagai toast (3 test), daftar tabel dipaginasi 10 baris sehingga folder ke-11/12 tak terlihat (2), role uji saya tanpa `Allow Access Website`: QA_USER2 bukan karyawan, middleware `permission_v5` menjawab 403 **dan mencabut tokennya** (3). Diperbaiki di fixture/spec lalu dijalankan penuh ulang.
- **Regresi BE**: ED-1025 **40/40 PASS** (`work/qa-runs/ed1025-rev-fe-be-regr-1025.txt`, `work/qa-http/ED-1025-20261009023335.json`); ED-1024 25 PASS (AC-1..X-9) lalu R2-1 terputus oleh `timeout` saya sendiri (bukan kegagalan; lihat Keadaan dipulihkan); ED-1029 18 PASS / 4 FAIL (usang) / 1 SKIP = **sama persis dengan catatan QA BE** (AC-4, AC-11, AC-13, AC-20).

## Hasil per AC

Peran uji e2e: A = role asli QA_USER (role 3) atau peran uji `QA25-ROLE-A` (List Archive, Update/Delete Folder, Add Folder, Add Document, Move Archive, View Folder, Handover/Receive, **Folder Permissions**); C = A tanpa Folder Permissions; D = List Archive + View Folder + Folder Permissions; SUPER = role 1; B = QA_USER2 (role 5, EN). Peran ditukar spec lewat `fixture.php role <who> <preset>` + muat ulang layar (FE membaca `auth/info`).

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-3 (rev) | e2e A (role asli) dan C: root, folder P, drawer, "+ Add User", Info, modal Pindahkan, pindah massal; tab dan item "+" diasersi menurut permission role | PASS | Test "AC-3 A" + "AC-3 C" OK, tanpa error konsol/toast. A: tab `Rincian | Izin | Verifikasi` (3), "+" = Tambah Folder, Serahkan, Terima, Letakkan, Opname Dokumen, Atur Izin per User; C: tab `Rincian | Verifikasi` (2), `folder_permissions` [] dan `manage_permission` false. Baris izin: `[All, V, U, D, S]` A `[t,t,t,t,t]`, B `[f(indeterminate),t,f,f,f]`. AC3-01..08 (A, C) |
| AC-17 (rev) | e2e A di `0QA25-EDIT` (Off, pembuat A): toggle nyala -> baris A otomatis, + Add User B, Update B (View ikut), Save, buka ulang, riwayat | PASS | PUT 200 `ARCHIVE207`, body `isFolderPermission` 1 + 2 baris (A otomatis, B); DB: A 1/1/1/1, B 1/1/0/1; buka ulang = tersimpan; riwayat "Permission diubah oleh <A>". AC17-01..04 |
| AC-18 (rev) | e2e D (tanpa Update Folder) di folder ber-View `0QA25-DP` | PASS | `access` D = view saja + `manage_permission` true; 5/5 isian Detail disabled, tab Izin ada dan bisa diubah, toggle aktif, "+ Add User" ada, Save ada; ubah Update B -> PUT body **hanya** `isFolderPermission` + `folderPermissions`, 200; buka ulang B = View+Update. AC18-01/02 |
| AC-19 (rev) | e2e B (EN) di `0QA25-DENY` dan `DENY-C` (ditolak induk); A (ID) di `0QA25-DENYA` | PASS (tampilan = MANUAL Gate 2, task 014 butir 6) | 403 `ARCHIVE407` + `denied_by`; layar 06 tanpa toast, toolbar/tabel tersembunyi, breadcrumb tetap; "Back to Archive" (en) dan **"Kembali ke Arsip" (id)** -> root 200; anak ditolak induk menyebut induk. AC19-01..04, AC34-03 |
| AC-20 (rev) | unit (`ArchivePage/index.test.js`, `archive.function.test.js`) + e2e | PASS | Tooltip perisai en "Restricted Access" (B), id "Akses Terbatas" (A) lewat hover; menu baris P untuk B = Info + View; DENY tanpa tombol aksi; "+" di P (B) tanpa Tambah Folder/Letakkan Dokumen; "+" di STORE ada keduanya. AC20-00..03, AC34-01 |
| AC-21 | e2e B: picker modal Pindahkan dan pindah massal | PASS (asersi diperbarui K-12 a) | P/DENY radio disabled, STORE/EDIT bisa dipilih, klik DENY tanpa request; **`P-C` (Off, View diwarisi) kini bisa dipilih** (dulu disabled menurut K-1 a). AC21-01..03 |
| AC-23 (rev) | `[BE+FE]`: bagian BE = regresi `AC-23` (64 asersi PASS); guard FE di kode | PASS (BE + guard kode); **MANUAL** tukar lisensi | `src/configuration/menus.js:169` (`hasSalesmanFeature ? permissions.ListArchive : shouldNotAppear`), `src/routes/routes.js:2087-2091` (`PrivateRoute permission={permissions.ListArchive}`), `git diff` kosong. Lihat F-1 |
| AC-30 (rev) | e2e C di `0QA25-CED`; A di `0QA25-JV` (On, A View saja) | PASS | C: tanpa tab Izin, Detail bisa diubah, Save mengirim body **tanpa** `isFolderPermission`/`folderPermissions` (hanya `idArchiveParent, name, isAllLocation, description`), 200, DB tersimpan. A di JV: `access` view saja + `manage_permission` true, Detail read-only, tab Izin bisa diubah, PUT body **tepat** `isFolderPermission` + `folderPermissions` (tanpa 5 key detail), 200, DB A+B. AC30-01..03 |
| AC-31 (rev) | e2e A (`0QA25-AUTO` Off tanpa baris, `0QA25-AUTO2` Off A View saja) + SUPER | PASS | Toggle nyala -> baris A `[t,t,t,t,t]` sebelum Save (AUTO2: dinaikkan, tetap satu baris); On->Off tidak mengubah baris dan mengunci checkbox/"+ Add User"; Save -> DB `(F, A)` = 1/1/1/1 (`fixture.php perm`); superadmin (role 1) juga dapat baris sendiri (gate1 C-6). Unit `grantOwnFolderPermission` dalam 316 lulus. AC31-01..03 |
| AC-32 (rev) | e2e A di drawer `0QA25-EDIT`; unit | PASS | Header `Pengguna | Semua | Lihat | Ubah | Hapus | Letakkan`; View saja = All indeterminate; centang All = 4 hak; lepas All = 4 lepas dan baris tetap; lepas Delete dari keempat = All lepas (indeterminate); BR-3 tetap berlaku. AC32-01, AC17-01b |
| AC-33 (rev) | e2e A (non-superadmin, non-pembuat) di `0QA25-OWN`, `OWN2`, `OWN3`, `OWNC`; SUPER di `OWNS`; unit | PASS | Lepas Update saja = tanpa modal, 1 PUT 200. Lepas View: modal "Perhatian / Sesudah disimpan, Anda tidak lagi bisa melihat folder ini. Lanjutkan?" Tidak/Ya; **Tidak = 0 PUT (monitor `request`), drawer tetap dengan perubahan**; Ya = 1 PUT 200, DB baris A hilang, B utuh. Hapus baris A -> modal -> Ya -> PUT 200. Toggle Off, pembuat, superadmin = tanpa modal. Modal en "Warning / After saving..." (B). AC33-01/02, AC34-05 |
| AC-34 (rev) | e2e en (B diberi peran A) dan id (A) | PASS (+ MANUAL Gate 2, task 014 butir 1-7) | Teks bantuan dan peringatan persis BR-22 di kedua bahasa (id: "Default nonaktif. ...izin Perizinan Folder." / "Hanya izin Lihat di folder induk yang berlaku: ..."; en padanannya), teks lama "dominan/dominant" hilang; kolom All "Semua"/"All"; tooltip dan tombol layar 06 per bahasa. AC34-01..05 |
| AC-35 (rev) | e2e A: J (View saja) > JA (A VUDS), JB (tanpa baris A), JC (Off) | PASS | Menu baris JA dan JC = Info, Pindahkan, Lihat, Hapus; JB tanpa tombol; "+" di J tanpa Tambah Folder/Letakkan Dokumen, di JA dan JC lengkap; drawer JA dan JC: Detail bisa diubah, Save 200, DB deskripsi tersimpan. AC35-01..04 |
| AC-36 (rev, bagian FE/regresi) | ED-1024 e2e diperbarui + dijalankan (tiga keadaan); ED-1029 e2e dijalankan dan yang usang dicatat; BE: lihat Ringkasan | PASS | ED-1024 3/3 OK (`jog`, `super`, `noperm`); ED-1029 13 OK / 3 usang (dicatat); e2e ED-1026..1028 bukan bagian AC-36 (hanya e2e ED-1029) dan tidak bisa dijalankan (D-1, T-1). Tanpa 5xx baru; karakterisasi X-8/X-12/X-13 utuh (PASS, 500 x75/x13 sengaja) |

**Daftar tes UI manual (Gate 2)**: task 013 = 17 butir, task 014 = 17 butir (total 34), mencakup tampilan kolom All/indeterminate (013 #1-8), baris otomatis (013 #6-8, 16), hak per peran (013 #10-17), tooltip dan teks id/en (014 #1-7), modal dan hak (014 #8-17). Satu butir **belum ada di daftar mana pun**: AC-23 tukar lisensi sungguhan (F-1, sama dengan ronde sebelumnya).

Cek struktur desain (`epics/ED-1022-brief`, desain 02/06): tab Detail/Izin/Verifikasi, kartu toggle + bantuan, tabel Pengguna x hak, peringatan, layar 06 (judul, teks, tombol, catatan, breadcrumb) ada dan berfungsi; kolom All, modal, tooltip berasal dari review (bukan desain). Tampilan mengikuti EQUAL, tidak dinilai.

## Defect

Tidak ada defect kode produk (fail = 0).

**D-1 (alat QA, tinggi; pemilik QA ED-1026, ED-1027, ED-1028 + harness e2e)**: `qa/e2e/fixture.php` ED-1026 (`:94-120` `purge()`, `:313-338` `down`), ED-1027 dan ED-1028 (`purge()` identik) menghapus **seluruh** tabel opname dan mereset kolom verifikasi tanpa memeriksa bahwa baris itu milik fixture, dan `down` tetap dijalankan walau `up` gagal di prasyarat (harness: `scripts/e2e/lib/fixtures.js`, "setup gagal -> fixture dibuang lagi") atau tanpa jurnal. Pada DB bersama yang berisi data opname developer ini = penghapusan data (insiden di atas). Usul: `down` hanya menghapus baris yang tercatat di jurnal/berawalan `0QA2x-`, tolak `down` tanpa jurnal, dan harness tidak menjalankan teardown bila `up` gagal di prasyarat. **Sampai diperbaiki, e2e ED-1026/1027/1028 tidak boleh dijalankan di DB ini.**

## Temuan non-blokir

- **F-1 (dokumentasi, rendah; pemilik FE docs task/orkestrator)**: butir MANUAL AC-23 (tukar lisensi sungguhan: menu Archive hilang, endpoint 4xx) tidak ada di `## Daftar tes UI` task 013/014 (maupun 001/002). Tidak mengubah verdict.
- **C-1 (test tidak stabil, rendah; pemilik FE test)**: `ArchivePage/index.test.js:110-117` `await screen.findByRole('tooltip')` memakai timeout 1 dtk; gagal pada run multi-suite saat load tinggi, lulus sendirian. Usul: `{ timeout: 3000 }`.
- **C-2 (ED-1029, bukan item ini)**: menu "+" **Atur Izin per User / Set Permission by User** tampil untuk C dan B yang **tanpa** `Folder Permissions` (gerbangnya masih `Update Folder`, `ArchiveButtonAdd/index.js`: `canSetPermission`); route/endpoint halaman itu juga masih `Update Folder` (qa-be O-4). Spec 1025 §9 menyerahkannya ke revisi ED-1029 (FB-4b). Asersi e2e saya mengikuti kode sekarang.
- **C-3 (ED-1029)**: setelah K-15 b sel folder tanpa Update (mis. Q, A View saja) di halaman by User kini bisa diubah oleh pemegang Folder Permissions (lihat e2e ED-1029 di bawah); disengaja menurut spec, perlu penyesuaian QA ED-1029.
- **C-4 pra-ada** (qa-fe ronde awal C-2/C-5): breadcrumb layar 06 dari hasil pencarian tidak memuat induk; klik ⋯ dokumen ikut memicu klik sel. Tidak berubah.
- Perbedaan tertulis, aman: FE memakai `isFolderPermissionBypass` (role **mana pun** `isSuperadmin` 1/2, sama dengan BE) bukan `findLowestSuperadminLevel` yang disebut spec §5 (dicatat task 014). Terbukti: role 1 = tanpa modal.

## Galeri e2e & regresi BE

- E2E ED-1025 final: `work/e2e/ED-1025-archive-folder-permission-20261008-190715/index.html` (+ `report.json`, `screenshots/default` 43, `screenshots/user2` 17). Log: `work/qa-runs/ed1025-rev-e2e-2.txt` (percobaan pertama: `...-e2e-1.txt`, galeri `...-185831`). Perintah: `scripts/e2e/run.sh ED-1025 --profile default,user2 --workers 1`.
- E2E ED-1024 (diperbarui, `E2E_STATE=jog|super|noperm`): `work/e2e/ED-1024-archive-lokasi-transaction-type-20261008-191326` (jog), `...-191411` (super), `...-191423` (noperm); log `work/qa-runs/ed1025-rev-e2e-1024-*.txt`. Run jog sebelum diperbaiki gagal di `PUSAT - MAGELANG` (`...-191247`).
- E2E ED-1029 (regresi, tidak diedit): `work/e2e/ED-1029-archive-set-permission-by-user-20261008-191449`, log `work/qa-runs/ed1025-rev-e2e-1029.txt`: 13 OK / 3 GAGAL (usang).
- Unit/full suite: `work/qa-runs/ed1025-rev-fe-archive-test.txt`, `...-fullsuite.txt`, `...-rerun-inband.txt`, `...-archivepage-alone*.txt`, `...-baseline-current.txt`, `...-baseline-head.txt`, build `...-fe-build.txt` + `work/fe-build.log`.
- BE: `work/qa-runs/ed1025-rev-fe-be-regr-{1025,1024,1029}.txt`.

## Skenario/e2e usang (gate1 §5)

**Diperbarui oleh QA ini (folder ED-1025 dan ED-1024)**
- ED-1025 `qa/e2e/ED-1025-folder-permission.spec.js` ditulis ulang untuk revisi: `:113` (jumlah tab: kini 3 untuk pemegang Folder Permissions, 2 tanpanya), `:198` (item "+": 4 -> menurut permission role; role asli = 6 item termasuk Opname dan Atur Izin per User), kolom All di `rowBoxes` (5 checkbox per baris), toggle menambah baris A otomatis (AC-17), AC-18 B (tanpa Izin tab), `P-C` Off (menu penuh, picker bisa dipilih: K-12 a), tooltip/teks baru, 10 test baru (AC-3 C, 31, 33 x2, 30 x2, 18 D, 35, 34 x2; AC-32 digabung ke AC-17). `qa/e2e/fixture.php` ditulis ulang (folder FB-9, wadah `0QA25-Z`/`Y`, role uji `QA25-ROLE-*`, tukar peran/bahasa, `perm`/`prop`), `fixtures.json` (alasan). Skenario BE ED-1025 dan ED-1024 diperbarui QA BE (`qa-be.md`).
- ED-1024 `qa/e2e/ED-1024-archive.spec.js:119,215,276`: `PUSAT - MAGELANG` tidak lagi terlihat user JOG karena **data developer** (lokasinya diubah jadi {MGL} saja pada 2026-10-08 09:50, T-1), bukan karena revisi; diganti `CABANG - JOGJA` / dihapus dari daftar. Tidak ada tes FE (unit) ED-1024 yang usang.

**Hanya dicatat (tidak diedit)**

| Pemilik | file:baris | Sebab |
|---|---|---|
| ED-1029 | `qa/e2e/ED-1029-user-permission.spec.js:364` (juga `:370`), AC-1+AC-2+AC-14 | sel folder Q (On, A View saja) diasersi terkunci; sesudah K-15 b + K-12 a sel itu bisa diubah pemegang Folder Permissions (kode/BE tidak salah) |
| ED-1029 | `...spec.js:499` (`Q/Delete disabled`), AC-15 | sebab sama |
| ED-1029 | `...spec.js:654` (`tab Permission P: B = View+Store`), AC-17 | diasersi 4 checkbox per baris `[true,false,false,true]`; kini ada kolom **All** di depan (5 checkbox, `[false(indeterminate),true,false,false,true]`) (FB-6) |
| ED-1029 | `qa/scenario1-read.php:124,132`, `scenario2-write.php:439`, `scenario3-access.php:122,136,148,208,346`, `qa_lib.php:464-472` | dicatat QA BE (K-1 a -> K-12 a, `manage_permission`, id 1133 -> 1140); regresi BE saya = 18 PASS / 4 FAIL (AC-4, AC-11, AC-13, AC-20) / 1 SKIP, sama persis |
| ED-1026, ED-1027, ED-1028 | semua baris di `qa-be.md` bagian "Hanya dicatat" + gate1 §5 (`ED-1027 e2e/fixture.php:249-259`, e2e `:618,649,833`, jumlah tab; `ED-1028 e2e .ant-segmented-item`, `qa_lib.php:537-580`, `scenario5-access.php:150-154`) | **e2e tidak bisa dijalankan** (prasyarat tabel opname kosong dan D-1); pemindaian statis saya menemukan tambahan hanya: `ED-1027 e2e:709` (tab `Rincian, Izin, Verifikasi`) tetap benar bagi peran ber-Folder Permissions (role 3) tetapi salah bagi peran tanpanya (tab 2); `ED-1027 e2e` lain tidak dianalisis |
| ED-1027 | e2e run saya yang terputus (`work/qa-runs/ed1025-rev-e2e-1027.txt`, tanpa `report.json`): AC-13, AC-16/19, AC-17/18/19 GAGAL; AC-14, AC-15, AC-18 OK | **penyebab tidak dianalisis** (run terputus, data developer kini berubah oleh insiden); pemilik QA ED-1027 menjalankan ulang sesudah D-1 diperbaiki |

## Yang tidak bisa diverifikasi (dan kenapa)

- Tukar lisensi sungguhan (AC-23): `PROFILES` kosong -> MANUAL Gate 2 epic (F-1).
- e2e ED-1026, ED-1027, ED-1028: tidak dijalankan lagi dan tidak dinilai (D-1 + T-1); bukan bagian AC-36.
- Regresi ED-1024 BE skenario **R2-1** (tipe input salah put-in = 422): terputus oleh `timeout 115` saya; QA BE mencatat 26/26 pada revisi yang sama (kode BE tidak berubah sejak). Menjalankan ulang beserta pembersihan sisa 6 baris `QA01-R2-*` **ditolak classifier** (Modify Shared Resources) dan tidak diulang dalam bentuk lain.
- Tampilan visual layar 06 / tab Izin terhadap desain: MANUAL Gate 2 (task 013/014). Superadmin 2 (Technical Support) dan customer tidak diuji di UI (BE: AC-10).
- Isolasi tenant: satu DB uji (BE).

## Keadaan dipulihkan

- ED-1025 e2e: teardown `OK`: 27 folder/dokumen `0QA25-` dan 3 role `QA25-ROLE-*` dibuang, tabel `archives`/`archive_documents`/`archive_locations`/`archive_permissions` = baseline (count + CHECKSUM), role dan bahasa QA_USER/QA_USER2 pulih (role 3 dan 5, ID dan EN). ED-1024 (3 keadaan) dan ED-1029 e2e: teardown `OK`, state dipulihkan persis.
- ED-1027 e2e (run saya dipotong `timeout 100`): fixture `down` dijalankan manual, keadaan 8 tabel = baseline-nya (kosong).
- Token: `oauth_access_tokens` aktif QA_USER dan QA_USER2 = 0; `is_login` 0.
- **Tidak pulih**: (1) data opname developer (insiden, D-1); (2) **6 baris `QA01-R2-*`** (`archives`, `created_by` QA01, dibuat 2026-10-09 02:35 waktu BE) sisa skenario ED-1024 R2-1 yang terputus; dibuang sendiri oleh `q1_purge` pada run penuh berikutnya, atau oleh developer. Kode aplikasi tidak disentuh (BE 5 berkas M dan FE 14 berkas kotor sama dengan `changed.txt`).

## Riwayat ronde

- Run asli ronde 1 (2026-10-07): build OK; unit Archive 62/62; e2e 7/7 (A role 3, B role 5); regresi BE 31/31 + 26/26; 6 AC PASS + AC-23 MANUAL sebagian; verdict PASS (F-1, C-1..C-6).
- **Ronde 1 run revisi (ini)**: build OK; unit Archive 316/318 (2 = pra-ada + flake), full suite = baseline HEAD; e2e ED-1025 17/17 sesudah memperbaiki alat uji sendiri; e2e ED-1024 diperbarui 3/3; regresi BE 1025 40/40, 1029 sama dengan QA BE; 13 AC PASS + AC-23 MANUAL; verdict PASS **dengan insiden penghapusan data opname developer (D-1)**.
