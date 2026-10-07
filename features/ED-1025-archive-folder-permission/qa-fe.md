---
key: ED-1025
scope: fe
round: 1
verdict: PASS
counts: {pass: 6, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

QA FE ronde 1 untuk Archive - Folder Permission per folder. AC milik scope fe: AC-3, 17, 18, 19, 20, 21 (`[FE]`) dan AC-23 (`[BE+FE]`).
**Tidak ada AC yang gagal dan tidak ada 5xx yang dihasilkan alur FE.** Enam AC PASS lewat e2e Playwright + unit test; AC-23 = bagian BE dan guard menu PASS,
tukar lisensi sungguhan tetap **MANUAL (Gate 2 epic)** (`PROFILES` kosong).

- **Build**: `scripts/fe-build.sh build` exit 0, "Compiled successfully" (122 dtk) ke `/tmp/ksp-react-v5-build` (bukan `build/`). Tidak ada berkas `src/`, `docs/specs/Archive/`
  atau `public/` yang lebih baru dari build (`find -newer`: kosong). Peringatan harness "build MUNGKIN USANG" hanya karena 18 berkas FE belum di-commit.
- **Unit test Archive** (`yarn test --watchAll=false src/containers/Archive`): 4 suite, 62 test, tidak ada asersi yang gagal. Satu test (`ArchiveDrawer` "Save mengirim detail + permission ...")
  kena timeout 5 dtk pada run pertama (5,8 dtk, load mesin 4); diulang sendirian 5x: 4 lulus (2,2-4,1 dtk), 1 timeout lagi; ulang `--runInBand` lulus. Lihat C-1.
- **Full suite**: 72 suite / 1383 test. Run bersamaan di mesin yang sedang sibuk (load rata-rata 8-10 pada 4 core, ada proses PHP/queue lain) = 13 suite gagal / 40 test, semuanya timeout;
  9 suite yang bukan baseline (termasuk `ArchiveDrawer`, `ArchivePage`) diulang sendirian `--runInBand`: **10 suite, 171/171 lulus** (`work/qa-runs/ed1025-fe-r1-rerun9.txt`).
  Sisa 4 suite gagal = baseline lama yang **sama persis di salinan HEAD bersih** (`git archive HEAD` ke scratchpad + tautan `node_modules`, dibuang sesudahnya): `Production/production.function`
  (getRecipeTotals x2), `InterbankTransfer/InterbankTransferForm` (tanggal default), `components/Item/BatchOut` (qty batch), `CustomerPurchaseOrderView` (suite gagal dimuat: `electronEvent` undefined).
  Bukan akibat diff ED-1025 (`work/qa-runs/ed1025-fe-r1-baseline4-current.txt` = `...-head.txt`: 4 suite / 4 test gagal di keduanya).
- **E2E** (`scripts/e2e/run.sh ED-1025 --profile default,user2`): **7 OK, 0 GAGAL, 0 SKIP**, 0 error konsol/page/HTTP >= 400 (selain 403 `ARCHIVE407` yang memang diharapkan, dikeluarkan secara eksplisit
  di AC-19), 0 respons 5xx, tanpa entri allow-list. Dua user nyata: A = `QA_USER` (role 3, non-superadmin, bahasa ID), B = `QA_USER2` (`adi`, role 5, non-superadmin, bahasa EN; kini bisa login, sesuai catatan developer).
- **Regresi BE** (fpm, `be-reload.sh` no-op): ED-1025 **31 PASS / 0 FAIL**, ED-1024 **26 PASS / 0 FAIL**.
- **Data uji dan keadaan**: fixture `qa/e2e/fixture.php` membuat 8 folder/dokumen berawalan `0QA25-` + baris `archive_permissions`, dibuang persis di teardown; teardown memverifikasi jumlah baris
  dan `CHECKSUM TABLE` 4 tabel (`archives`, `archive_documents`, `archive_locations`, `archive_permissions`) = baseline (OK di setiap run). Sesudah semua run: `archives` 49145 (sama dengan awal),
  0 baris `0QA25-`/`QA02-`, `archive_permissions` 0, role/bahasa kedua user tidak berubah, token aktif 0, semua sesi di-log-out. Kode BE/FE tidak disentuh (21 + 19 berkas kotor sama dengan `changed.txt`).

## Hasil per AC

Galeri: `work/e2e/ED-1025-archive-folder-permission-20261007-025357/` (index.html, report.json, screenshots/default + user2). Spec: `qa/e2e/ED-1025-folder-permission.spec.js`.

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-3 | e2e `[default]` A: root, masuk folder P, drawer P (tab Detail + tab Izin/Permission), "+ Add User" (select dipanggil), Info, modal Pindahkan, pindah massal (Pilih -> Pindahkan -> Konfirmasi -> Lanjut -> Pilih Folder) | PASS | Test "AC-3 A" OK, 0 error konsol/HTTP, tanpa toast. `GET archives` 200 dengan `access` penuh + `is_folder_permission`; `GET archives/{P}` memuat `folder_permissions` A,B; `GET select/document-archive/archive/users` 200, `excepts` memuat id A dan B, opsi tidak memuat keduanya. Screenshot AC3-01..08 |
| AC-17 | e2e `[default]` A di folder fixture `0QA25-EDIT` (pembuat = A): toggle mati -> nyala, "+ Add User" cari `adi`, user baru default View saja, lepas View = semua lepas, centang Store = View ikut, centang Update, Save, buka ulang | PASS | Test "AC-17 A" OK. PUT `archives/{id}` 200 `ARCHIVE207`, body `isFolderPermission` = 1 (integer) + 1 baris B dengan flag integer; drawer menutup; ikon perisai muncul; `GET archives/{id}` = aktif + baris `adi` [1,1,0,1]; UI membaca ulang toggle aktif + checkbox [V,U,-,S]; Info menampilkan entri "Permission changed by <QA_USER>" (juga di kolom Status list). Screenshot AC17-01..04 |
| AC-18 | e2e `[user2]` B (View saja di P): drawer P | PASS | Test "AC-18/AC-20 B" OK. `access` B di P = view saja, `manage_permission` false; tombol Save tidak ada; 5/5 isian tab Detail disabled; tab Permission: toggle aktif tetapi disabled, tanpa "+ Add User", 8/8 checkbox disabled, tanpa tombol hapus baris. AC18-01, AC18-02 |
| AC-19 | e2e `[user2]` B tanpa baris di `0QA25-DENY`: klik folder; lalu folder anak `0QA25-DENY-C` (B View, induk menolak) lewat pencarian; Back to Archive | PASS (tampilan = MANUAL Gate 2, ada di task 002 butir 2-3, 6) | Test "AC-19/AC-20 B" OK. `GET archives?id_archive=DENY` 403 `msg_code` `ARCHIVE407`, `result.denied_by` = {id, `0QA25-DENY`}; layar 06 tampil (judul EN "You do not have access to this folder", teks "Folder **0QA25-DENY** has folder permission enabled and your account has not been given **view** permission...", tombol Back to Archive, catatan dokumen tetap muncul di pencarian); 0 toast (`.ant-message-notice`/`.ant-notification-notice` = 0 setelah 1,2 dtk); search bar, "+", Select, tabel tersembunyi; breadcrumb "Archive > 0QA25-DENY" tetap. Anak via pencarian: baris folder membawa `access.view` false, 403 `denied_by.name` = induk, layar menyebut **induk** (`<b>` = `0QA25-DENY`). Back to Archive -> `GET archives` tanpa `id_archive` 200, layar 06 hilang, daftar tampil. AC19-01..04 |
| AC-20 | unit (`archive.function.test.js`: `getArchiveActionKeys`, `getArchiveAddKeys`, `archiveActionColumn`; `ArchivePage/index.test.js`) + e2e A dan B | PASS | e2e: P ber-permission = ikon perisai (`.anticon-safety-certificate`), folder nonaktif tanpa perisai; menu baris P (B View saja) = Info + View (tanpa Move/Delete); DENY (tanpa hak) = tombol ⋯ tidak ada; anak P-C (hak diwarisi dari P) = Info + View; dokumen = Info + Move (BR-13); folder bebas = Info, Move, View, Delete; "+" di dalam P untuk B = Hand Over + Receive saja (tanpa Add Folder/Store Document), di `0QA25-STORE` (View+Store) = Add Folder + Store Document ada, untuk A di P = 4 item. Unit: tanpa View tanpa Info/Lihat, tanpa Update tanpa Pindahkan, tanpa Delete tanpa Hapus, permission modul tetap wajib. AC20-01, 02(b), 03(b), AC19-01 |
| AC-21 | e2e `[user2]` B: modal Pindahkan (folder `0QA25-MOVE`) dan pindah massal (mode Pilih -> Lanjut -> Pilih Folder); unit `ArchiveMoveModal/index.test.js` | PASS | Di kedua picker: radio P (tanpa Store) disabled, DENY (tanpa View + Store) disabled dan klik nama tidak memanggil `GET archives?id_archive=DENY` (sel nama tanpa `cursor-pointer`, 0 request dalam 1,5 dtk), STORE dan EDIT bisa dipilih; P (View) bisa dibuka lewat klik nama, isi P tampil dan anak `P-C` (Store diwarisi dari P) disabled; pindah massal: memilih STORE mengaktifkan tombol Move. Tidak ada konfirmasi pindah (non-mutasi). AC21-01..03 |
| AC-23 | `[BE+FE]` uniform EPIC K-2 b: bagian BE = regresi `AC-23` (56 asersi); guard menu/route FE dicek di kode; tukar lisensi sungguhan = manual | PASS (BE + guard kode); **MANUAL** (tukar lisensi) | Regresi BE: AC-23 PASS (403 `GE0114` + `parameter`, data tetap). Kode: `ksp-react/src/configuration/menus.js:169` (`permission: hasSalesmanFeature ? permissions.ListArchive : shouldNotAppear`) dan `src/routes/routes.js:2087-2091` (`PrivateRoute permission={permissions.ListArchive}`), keduanya tidak berubah (`git diff` kosong). Karakterisasi guard route pra-ada: temuan F-1 pada e2e ED-1024 AC-13. Sisa MANUAL: lihat F-1 |

Cek struktur desain (`epics/ED-1022-brief/render/tiles/design-02.png`, `design-08.png`): tab Detail/Permission, kartu toggle + teks bantuan, judul "Folder Permission" + "+ Add User", tabel User x View/Update/Delete/Store
+ hapus baris, peringatan "parent dominan ... Superadmin EQUAL selalu bypass", layar 06 (judul, teks, tombol Back to Archive, catatan, breadcrumb) semuanya ada dan berfungsi. Tab Verification = item ED-1027 (bukan item ini). Tampilan mengikuti EQUAL, tidak dinilai.

## Defect

Tidak ada defect AC (fail = 0).

**Temuan non-blokir**
- **F-1 (dokumentasi, rendah)** AC-23 bagian MANUAL ("tukar lisensi sungguhan, sekali, di Gate 2 epic": dengan lisensi tanpa Salesman Activity menu Archive tidak tampil dan endpoint menolak 4xx) tidak ada di `## Daftar tes UI`
  task mana pun (`ksp-react/docs/specs/Archive/001-...md`, `002-...md`); hanya tercatat di epic gate1 EPIC K-2 b dan `qa-be.md`. Usul: tambah satu butir di task 002 atau di daftar Gate 2 epic.
  Pemilik: FE (docs task) / orkestrator. Tidak mengubah verdict (tidak ada AC tanpa cek; perilakunya sendiri lulus).

## Catatan (bukan defect)

- **C-1 test tidak stabil di bawah beban** (`src/containers/Archive/ArchiveDrawer/index.test.js:109`, juga `ArchivePage/index.test.js` di full suite sibuk): test Save butuh 2,2-5,8 dtk melawan timeout bawaan 5 dtk; timeout hanya muncul saat mesin sibuk, tidak ada asersi yang gagal,
  lulus sendirian `--runInBand`. Usul: `jest.setTimeout(20000)` atau timeout per `it` di kedua berkas. Pemilik: FE (test).
- **C-2 breadcrumb layar 06 dari hasil pencarian tidak lengkap**: bila folder anak ditolak oleh induk dan dibuka dari hasil pencarian, breadcrumb = "Archive > <anak>" (bukan "Archive > <induk> > <anak>") karena FE tidak memakai
  `result.breadcrumbs` dari body 403 (kontrak §1 menyediakannya). Alur klik biasa (jalur folder) benar. BR-11 "breadcrumb tetap" terpenuhi. Pemilik: FE (`ArchivePage/index.js`, saran: set breadcrumb dari `getArchiveDeniedBy`/error body).
- **C-3 kosmetik**: label switch EN "Active" terpotong ("Ac") pada switch disabled (AC18-02); ID "Aktif" utuh (AC17-02). Masukkan ke daftar tes UI manual; tidak menggagalkan AC.
- **C-4 risiko yang sudah diketahui** (qa-be O-4, digest FE P-2): non-pembuat yang menyimpan tab Permission tanpa menyertakan dirinya kehilangan View/Update di folder itu; FE mengikuti spec (tanpa pengaman). Tidak diuji ulang di UI.
- **C-5 pra-ada, di luar diff**: klik tombol ⋯ pada baris dokumen ikut memicu klik sel dokumen (breadcrumb diganti breadcrumb dokumen). Terlihat saat menulis e2e (fixture dokumen tanpa kolom `breadcrumbs` = breadcrumb hanya "Archive"); bukan perubahan ED-1025.
- **C-6 console 403**: respons 403 `ARCHIVE407` yang memang diharapkan tetap menulis `console.error` axios "Request failed with status code 403" dan baris respons >= 400 di monitor; spec mengeluarkan **hanya** entri itu (403 `GET archives?id_archive=<DENY|DENY-C>` + log axios) dan mengasersi minimal satu 403 tercatat.

## Galeri e2e & regresi BE

- E2E final: `work/e2e/ED-1025-archive-folder-permission-20261007-025357/index.html` (7 test: 2 smoke route `/archives` default + user2, AC-3, AC-17, AC-18/20, AC-19/20, AC-21; 29 screenshot). Run penuh sebelumnya: `...-20261007-022541` (7 OK).
  Log: `work/qa-runs/ed1025-fe-r1-e2e-final.txt`. Perintah: `scripts/e2e/run.sh ED-1025 --profile default,user2` (fixture `qa/e2e/fixtures.json` -> `fixture.php up/down`).
- Regresi BE: `"$PHP_BIN" scripts/qa-http/run.php ED-1025` = **31 PASS, 0 FAIL, 0 SKIP** (`work/qa-runs/ed1025-fe-r1-be-regr-1025.txt`, `work/qa-http/ED-1025-20261007093257.json`), token run di-log-out (aktif 0).
  `run.php ED-1024` = **26 PASS, 0 FAIL** (`work/qa-runs/ed1025-fe-r1-be-regr-1024.txt`, `work/qa-http/ED-1024-20261007093420.json`).
- **Dilacak di luar item** (pra-ada, sudah bertiket, karakterisasi sengaja di skenario BE, tetap hijau): X-12 (ED-1071, 75 dari 80 kasus karakter non-Windows-1252 = 500 kolasi) dan X-13 (ED-1070, 13/13 parameter list salah bentuk = 500).
  Dua keluarga ini adalah probe BE sengaja dan bukan alur FE; di e2e **0 respons 5xx**.
- Unit/full suite: log `work/qa-runs/ed1025-fe-r1-archive-test.txt`, `...-drawer-test-1..5.txt`, `...-fullsuite.txt`, `...-rerun9.txt`, `...-baseline4-current.txt`, `...-baseline4-head.txt`.

## Yang tidak bisa diverifikasi (dan kenapa)

- Tukar lisensi sungguhan tanpa Salesman Activity: `PROFILES` kosong -> MANUAL Gate 2 epic (F-1). Simulasi role tanpa permission Archive sudah ada di e2e ED-1024 AC-13 (karakterisasi guard route pra-ada).
- Tampilan layar 06 dan tab Permission secara visual terhadap desain: MANUAL Gate 2 (task 002 butir 2-3; task 001 butir 1-5). Hanya struktur, field, aksi, state yang dinilai di sini.
- Folder dengan superadmin 1/2 dan pembuat di UI: tidak ada user superadmin di profil e2e (QA_USER role 3, QA_USER2 role 5); perilakunya ada di BE (AC-10/AC-11, PASS) dan FE hanya merender `access` dari BE (unit). Pembuat diuji di AC-17 (A menyimpan `0QA25-EDIT` dan tetap bisa membukanya).
- Perangkat mobile / breakpoint kecil: tidak diuji (di luar AC).

## Riwayat ronde

- Ronde 1 (ini): build OK; unit Archive 62/62 (1 flake timeout, lihat C-1); full suite = 4 suite baseline lama + timeout beban (lulus sendirian); e2e 7/7; regresi BE 31/31 + 26/26; 6 AC PASS, AC-23 MANUAL sebagian; verdict PASS.
