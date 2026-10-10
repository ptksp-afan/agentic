---
key: ED-1027
scope: fe
round: 2
verdict: PASS
counts: {pass: 13, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

QA FE ronde 2 **revisi** (2026-10-10, revisi review 2026-10-08), atas working tree yang sama (BE 4 M di atas `cb2e78b44`, FE 11 M + 2 task baru di atas `5656a5e83`; tidak ada yang di-stash, di-revert, atau dikomit). AC tahap ini: `[FE]` AC-13..19, 21, 25, 26, 27 dan `[BE+FE]` AC-20, 28, 29 (14 AC).

- **Verdict PASS.** **D-1 (klik `V/T` folder crash karena `name` larik) TERTUTUP**: `ArchivePage/index.js:325-329` kini menurunkan label (`Array.isArray(name) ? name[0].transactionNo : name`) sebelum `modalVerification.onOpen`. Dibuktikan terhadap **API asli tanpa memodifikasi respons**: 5 test e2e folder `V/T` yang gagal di r1 kini lulus, termasuk cabang modal folder AC-28 dan AC-29 yang r1 tidak bisa jangkau. Unit test `ArchivePage` kini memakai `name` larik (bentuk asli respons).
- Build staging OK (63 dtk). Unit test modul Archive: 358 test, 357 lulus, 1 gagal = flake pra-ada ED-1025 (Di luar item 1). Full suite 1673/1679 (4 suite baseline di luar diff + 2 flake beban, Di luar item 2-3).
- **e2e: 21 OK, 0 GAGAL, 0 SKIP** (profil `default` = QA_USER, `user2` = QA_USER2), galeri kanonik `/var/www/opname-archive/agentic/work/e2e/ED-1027-archive-status-verifikasi-20261010-092655/index.html`.
- Temuan QA milik item ini (diperbaiki, bukan defect produk): **Q-1** run e2e pertama r2 memberi 15 OK / 5 GAGAL pada lima test *lain* (AC-13, AC-15, AC-17, `[user2]` AC-25, `[user2]` AC-13/16/17/28), padahal lulus di r1. Penyebab: harness default `workers: 2` + `fullyParallel: true`, sedangkan test berkas ini mengubah state bersama (Display Setting global per modul oleh AC-14/AC-25, grant `Opname Document` ke role 5, Confirm Opname); dua worker saling menimpa. Bukti: run `--workers 1` = 20/20 (`work/qa-1027-fe-rev2/e2e-2.txt`); run bermasalah dipertahankan sebagai diagnostik `.../20261010-084454/`. Perbaikan di folder item ini: kunci lintas-proses per test (`takeStateLock`, `qa/e2e/ED-1027-verifikasi.spec.js`), sehingga hasil tidak bergantung `--workers`; run dengan default 2 worker kini hijau dua kali.
- Regresi BE: ED-1027 28 PASS / 0 FAIL / 1 SKIP (X-3); ED-1024 26/26; ED-1025 40/40; ED-1026 39/39; ED-1029 29 PASS + 1 SKIP (X-ISO); ED-1028 2 PASS / 20 FAIL = skenario usang milik QA ED-1028, hanya dicatat. Tanpa 5xx baru; 500 yang terlihat semuanya karakterisasi pra-ada yang sudah dilacak (ED-1122, ED-1070, ED-1071).
- DB `api_sidomaju`: sidik jari 17 tabel (jumlah baris + CRC semua kolom) **identik sebelum dan sesudah** (`work/qa-1027-fe-rev2/fp-before.json` = `fp-after.json`, juga = akhir r1); 0 sisa jurnal/lock, 0 baris `0QA27-`, grant sementara dicabut persis. Perintah ditolak izin/classifier: **0**.

## Hasil per AC

Subtask FE: ED-1051 = AC-13/14/15/18 · ED-1057 = AC-16/18/19 · ED-1061 = AC-17/18/19 · ED-1097 = AC-26/27 (13, 16) · ED-1098 = AC-25 (17) · ED-1099 [QA]. `G` = `work/e2e/ED-1027-archive-status-verifikasi-20261010-092655/` (`report.json`, 21 passed); unit = `work/qa-1027-fe-rev2/jest-archive.txt`.

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-13 (rev) | e2e `AC-13/AC-27` + smoke `/archives`: kolom sesudah Salesman sebelum Status, header tanpa pengurut; sel folder = respons BE = angka tangan; folder `V/T` = `button.ant-btn-link` berkelas `min-h-0` dan `p-0`, `-` dan ikon dokumen bukan tombol (root, dalam folder, pencarian); ringkasan = `document_verified_summary` (oracle longgar), progress = V/T; reload/breadcrumb/pencarian mempertahankan ringkasan | PASS | `G` test AC-13/AC-27 (+ smoke Archive A); `ac13-01..03-*.png` |
| AC-14 | e2e `AC-14`: Display Setting memuat "Dokumen Terverifikasi", disembunyikan lalu ditampilkan: PUT 200, tabel + ringkasan tetap, DB `current_columns` ikut, baris dipulihkan persis | PASS | `G` test AC-14; `ac14-01..03-*.png` |
| AC-15 | e2e `AC-15`: Pindahkan folder, Pindahkan dokumen, Bulk Move (Konfirmasi + Pilih Folder): kolom dirender (4/8, 0/0, `-`, ikon), tanpa `[object Object]`, **teks biasa tanpa tombol**; unit: Bulk Move menampilkan teks biasa | PASS | `G` test AC-15; unit `ArchivePage` "Bulk Move: ... teks biasa" |
| AC-16 (rev) | e2e `AC-16/AC-28/AC-29` (id) dan `[user2] AC-13/16/17/28` (en): satu `GET verifications` tanpa parameter bisnis; subjudul id "Semua Arsip — seluruh dokumen di sistem", en "All Archive — all documents in the system"; header = ringkasan; tabel 7 tipe + Total = respons; 3 sesi; catatan; **tanpa `.ant-modal-footer`**, ✕ menutup, buka lagi = request baru; mobile 375 px layar penuh | PASS | `G` tests AC-16, "[user2] AC-13/16/17/28", "BR-7 mobile"; `ac16-01-*.png`, `user2-02-modal-en.png`, `br7-mobile-modal-layar-penuh.png` |
| AC-17 (rev) | e2e `AC-17/18/19`: tab Rincian, Izin, Verifikasi; `GET verifications/{id}` hanya saat tab dibuka (juga sesudah Save + buka ulang); angka = kolom folder; Detail terisi; Save tanpa field verifikasi; VIEWONLY read-only. `[user2]` en dengan OD sementara: tab Detail, Verification | PASS | `G` tests AC-17, "[user2] AC-13/16/17/28"; `ac17-01..03-*.png`, `user2-03-tab-verification-en.png` |
| AC-18 | unit `getOpnamePercent`, `ArchiveVerification` "tanpa dokumen dan sesi", `ArchivePage` "total 0"; e2e folder EMPTY (0 / 0, 0 %, tanpa NaN), modal All respons 0; jalur gagal muat: toast + Empty, tanpa "0 / 0" palsu | PASS | `jest-archive.txt`; `G` tests AC-17/18/19, "AC-18 modal", "jalur error"; `ac18-01/02`, `err-01` |
| AC-19 | unit `ArchiveVerification/index.test.js`: CTA tidak dirender tanpa `onOpenHistory`, baris sesi tidak bisa diklik; e2e: klik baris sesi = 0 request, tanpa drawer | PASS | `jest-archive.txt` (suite PASS); `G` test AC-16 |
| AC-20 | e2e `AC-20`: sort Nama, filter Type = Folder, pencarian, scanner USB, mode Pilih, transaksi terkait, menu "+" (`arrayContaining` empat aksi Archive) + modal Serahkan/Terima/Letakkan; BR-6/AC-12 Confirm Opname lewat UI; BE: AC-2/AC-3/AC-22 hijau di regresi | PASS | `G` tests AC-20, "BR-6/AC-12 Confirm Opname"; `ac20-01..05-*.png`; `be-ED-1027.txt` |
| AC-21 | struktur vs desain 01/02/05: kolom, tautan folder, ringkasan + tautan, modal (kartu, tabel tipe + Total, overview, catatan), drawer tab ada dan berfungsi (e2e). Penilaian tampilan = Gate 2: `ksp-react/docs/specs/Archive/019-*.md:97` (17 butir), `020-*.md:76` (8 butir), `007-*.md:71` (14), `008-*.md:72` (15), `009-*.md:58` (12) | MANUAL (Gate 2) | file:line di kolom Cara cek |
| AC-25 (rev) | e2e `[user2]` (QA_USER2 = role 5, tanpa Opname Document): respons root/folder/pencarian/picker tanpa `documentVerified` di `columns`, tanpa `document_verified_summary`, 0 baris ber-`document_verified`; UI tanpa kolom, ringkasan, tautan, ikon; drawer folder tanpa tab Verification dan tanpa riwayat; picker Pindahkan + Bulk Move tanpa kolom; Display Setting tanpa entri itu, sembunyikan Status lalu tampilkan = PUT 200 x2, DB `documentVerified` **dipertahankan** (tepat satu); 0 request `verifications*`. Unit: `ArchivePage` tanpa OD, `ArchiveDrawer` per kombinasi permission | PASS | `G` test "[user2] AC-25" + smoke Archive B (`forbidApi`); `ac25-01..06-*.png` |
| AC-26 (rev) | e2e **terhadap API asli**: klik `V/T` folder di root (ALPHA 4/8), mode Pilih (GAMMA, BETA), subfolder dalam folder (ALPHA-S1 2/3), hasil pencarian (ALPHA-S1-X 1/1), modal folder en `[user2]`: tepat satu `GET verifications/{id}` per buka tanpa parameter bisnis, subjudul nama folder (mis. "Dokumen Terverifikasi 0QA27-ALPHA"), tanpa tab, tanpa footer, header = nilai sel, tabel tipe + Total = respons dan angka tangan, overview varian folder (5 kolom), catatan folder; CTA -> `GET opnames?id_archive=<id>` 200 + drawer riwayat bersubjudul nama folder; ✕ menutup, buka lagi = request baru (2 request); tautan ringkasan tetap membuka varian All. Jalur error: **nyata** (folder fixture dinonaktifkan sesudah list dimuat: BE 404 `ARCHIVE400` "Dokumen tidak ditemukan", toast = pesan server asli, Empty, tanpa kartu/"0 / 0" palsu, ✕ menutup, aktif lagi = modal 0/0 sah, request 404 lalu 200) dan 403 `ARCHIVE407` disimulasikan (hanya respons verifikasi diganti; 403 asli = BE AC-8) | PASS | `G` tests "AC-26/AC-27 ... root", "... mode Pilih", "... subfolder ... pencarian", "[user2] AC-26/AC-28", "jalur error NYATA", "jalur error modal folder"; `ac26-01..03-*.png`, `user2-04-modal-folder-en.png`, `err-02`, `err-03-modal-folder-404-asli.png`; unit `ArchivePage` "tautan nilai folder" (5 test, `name` larik) |
| AC-27 (rev) | unit `archive.function.test.js` + `ArchivePage/index.test.js` (bentuk tautan, picker teks biasa, klik tak merambat, mode Pilih, `name` larik) dan e2e: sel folder = `button.ant-btn-link` `min-h-0` `p-0`; `-`/ikon bukan tombol; picker teks biasa; klik tidak membuka folder (0 `GET archives` baru, tanpa drawer), breadcrumb tetap, pencarian tetap terisi; mode Pilih: pilihan tidak berubah (klik baris lain dan baris tercentang) | PASS | `jest-archive.txt` (suite `archive.function` PASS); `G` tests AC-13/AC-27, AC-26/AC-27 x3 |
| AC-28 (rev) | FE: kolom Scope format BR-12 dari `scope` respons di **modal All, tab, dan modal folder**: id ALPHA = "0QA27-ALPHA + 2 subfolder", root "Semua Arsip · root" (juga di modal folder BETA: baris S3 root lalu BETA), GAMMA nama saja; en "All Archive · root"; respons `scope` tepat `is_root`, `folder_name`, `subfolder_count`. BE: regresi `be-ED-1027.txt` AC-10 (kunci `scope` tepat tiga, = `GET opnames/{id}`) PASS | PASS | `G` tests AC-16, AC-17, "AC-26/AC-27 ... mode Pilih" (assert baru, modal BETA), "[user2] AC-13/16/17/28", "[user2] AC-26/AC-28" |
| AC-29 (rev) | CTA modal All -> `GET opnames` 200 tanpa `id_archive`, drawer "Riwayat Opname"/"Semua Arsip"; CTA tab -> `GET opnames?idArchive=ALPHA` 200; **CTA modal folder -> `GET opnames?id_archive=<ALPHA>` 200, subjudul memuat nama folder, tanpa error**. Skenario ED-1028 dijalankan: 2 PASS / 20 FAIL usang, dicatat (aturan gate1 §5) | PASS | `G` tests AC-16, AC-17, AC-26/AC-27 root; `ac29-01/02`, `ac26-02-*.png`; `be-ED-1028.txt` |

AC-22/23/24 `[BE]` (milik QA BE): dijalankan ulang di regresi (`be-ED-1027.txt`: `AC-22 PASS`, `AC-23 PASS`, `AC-24 PASS`).

## Defect

Tidak ada defect baru. **D-1 (r1) tertutup** (lihat Ringkasan; bukti AC-26/27/28/29).
- Q-1 (QA, bukan produk, diperbaiki): race antar-test pada run e2e 2 worker, lihat Ringkasan.
- Observasi non-blokir: O-1 (kegunaan, rendah, dari ronde lama) tabel modal/drawer memotong kolom kanan, terjangkau lewat gulir horizontal (`columnReach` di e2e, tetap PASS; terlihat di `ac26-01-modal-folder-ALPHA.png`, kolom "Terverifikasi" terpotong). O-2: dua test `ArchivePage/index.test.js` memakai `findByText` bawaan (1 dtk) dan timeout di full suite berbeban (satu milik ED-1025, satu milik ED-1027 "response tanpa documentVerifiedSummary"); keduanya lulus sendirian (30/30), jadi bukan defect.

## Di luar item (bukan defect ED-1027; orkestrator yang membuka tiket)

1. **Unit test ED-1025 flake**: `ArchivePage/index.test.js` "tooltip ikon perisai 'Akses Terbatas' (id-ID, BR-10, FB-7)" gagal di run modul Archive dan full suite, lulus sendirian (30/30 di r2; di r1 gagal sendirian 3/3 dan terbukti gagal juga di HEAD bersih). Sudah dilacak ED-1025 C-1.
2. **Full suite**: 4 suite tanpa berkas di `git status` gagal sendirian (re-run r2: `BatchOut` 1 test, `production.function` 2, `InterbankTransferForm` 1, `CustomerPurchaseOrderView`): `jest-baseline4-alone.txt`. Bukti: `jest-full.txt`.
3. Test ED-1027 "response tanpa documentVerifiedSummary" timeout satu kali di full suite berbeban (`Unable to find an element with the text: Kantor Admin`); lulus sendirian dan di run modul (O-2). Tidak direproduksi.
4. **500 BE pra-ada, sudah dilacak, tidak diajukan ulang**: PUT display-columns detik yang sama = Bug ED-1122 (`X-13` ED-1027); `sorts` bentuk array / `pagination` / `search` salah bentuk = ED-1070 (ED-1027 `X-10`, ED-1025 `X-13`); kolasi latin1 non-latin1 = ED-1071 (ED-1025 `X-12`, ED-1029 `X-1`).
5. **Harness e2e (alat workspace)**: `scripts/e2e/playwright.config.js` `fullyParallel: true` + `workers: 2` default membuat spec berstate bersama (Display Setting global, grant role, sesi opname) gagal acak tanpa ada yang salah di produk; header spec tidak bisa memaksa `--workers 1`. Item ini kini aman sendiri lewat kunci lintas-proses; item lain dengan pola sama (ED-1025/1028/1029) perlu `--workers 1` atau kunci serupa. Satu-satunya celah sisa: smoke `Archive B` (`forbidApi verifications`, milik harness) bisa tumpang tindih dengan jendela grant sementara bila dijalankan paralel; run ini tidak mengalaminya (jendela grant ± 6-10 dtk).
6. **Skenario usang milik QA ED-1028 (hanya dicatat, tidak diubah)**: BE `q28_session` memakai alur draft -> `buat sesi` 422, 20 dari 22 FAIL (`be-ED-1028.txt`); `scenario1-list.php:177-189` (gate1 §5); e2e `ED-1028-opname-history.spec.js` tidak dijalankan.
7. Catatan kontrak (bukan defect): respons error BE memakai kunci `code` (sukses memakai `msg_code`); FE hanya membaca `message`. `contract.md` hanya mencontohkan `msg_code` untuk 200.

## Galeri e2e & regresi BE

**Galeri kanonik**: `/var/www/opname-archive/agentic/work/e2e/ED-1027-archive-status-verifikasi-20261010-092655/index.html` (21 OK, 0 GAGAL; log `work/qa-1027-fe-rev2/e2e-6.txt`). Dipertahankan sebagai diagnostik: `...-20261010-084454` (run 2 worker tanpa kunci, 15 OK / 5 GAGAL = Q-1, `e2e-1.txt`) dan, dari r1, `...-081829` (D-1) serta `...-075751`. Run antara r2 dibuang. Profil `default` = QA_USER (role 3: List Archive, Update, Move, Opname Document, Folder Permissions; ID), `user2` = QA_USER2 (role 5: List Archive, Update, Move; tanpa Opname Document; EN).

| Langkah | Hasil | Bukti |
|---|---|---|
| `scripts/fe-build.sh build` | "Compiled successfully", exit 0, 66 dtk; build lebih baru dari semua berkas `src` | `work/qa-1027-fe-rev2/build-full.log`, `work/fe-build.log` |
| `yarn test src/containers/Archive` | 11 suite: 10 lulus, 1 gagal; 358 test: 357 lulus (51 dtk). Gagal = flake ED-1025 (Di luar item 1). `ArchivePage` ED-1027: 13/13 lulus (`jest-archivepage-1027.txt`); seluruh `ArchivePage` sendirian 30/30 (`jest-archivepage-alone.txt`) | `jest-archive.txt` |
| Full suite `--maxWorkers=2` | 79 suite: 74 lulus, 5 gagal; 1679 test: 1673 lulus (263 dtk); 6 gagal = 4 baseline + 2 flake beban (Di luar item 2-3) | `jest-full.txt`, `jest-baseline4-alone.txt` |
| e2e ED-1027 final | 21 OK, 0 GAGAL (default 2 worker, dengan kunci state); sebelumnya `--workers 1` 20/20 | `e2e-6.txt`, `e2e-2.txt`, `G/report.json` |
| BE `run.php ED-1027` | PASS 28, FAIL 0, SKIP 1 (X-3: `QA_DBS` satu DB), token run dicabut | `be-ED-1027.txt`, `work/qa-http/ED-1027-20261010160325.json` |
| BE ED-1024 / 1025 / 1026 / 1029 | 26/26 · 40/40 · 39/39 · 29 PASS + 1 SKIP (X-ISO) | `be-ED-10{24,25,26,29}.txt` |
| BE ED-1028 | 2 PASS / 20 FAIL usang (dicatat) | `be-ED-1028.txt` |

**Perubahan QA milik item ini ronde 2** (`qa/e2e/`, aturan gate1 §5; berkas aplikasi tidak disentuh; test e2e 20 -> 21):
- `ED-1027-verifikasi.spec.js`: kunci state lintas-proses (`takeStateLock`/`releaseStateLock` di `beforeEach`/`afterEach`, kunci basi > 5 menit diambil alih; jatah waktu test tidak termakan menunggu); assert baru di test mode Pilih: overview modal folder BETA = S3 root ("Semua Arsip · root") lalu BETA (AC-28, cabang modal folder, Scope root); test baru "jalur error NYATA modal folder" + helper `setFolderActive`.
- `fixture.php`: perintah `set-folder-active <0QA27-nama> <0|1>` (hanya folder berawalan `0QA27-` dan hanya selama fixture up; `down` membuang barisnya).
- Oracle lama tidak diubah.

## Yang tidak bisa diverifikasi (dan kenapa)

1. Isolasi tenant dua DB (BE; `QA_DBS` satu DB, X-3/X-ISO SKIP). Bukan AC `[FE]`.
2. Tampilan vs desain (AC-21) dan lebar kolom di 992 px ke bawah: MANUAL Gate 2 (butir di task 019/020 dan 007-009).
3. Regresi e2e item lain (ED-1025/26/28/29) tidak dijalankan di ronde ini (di luar permintaan).
4. 403 `ARCHIVE407` di modal folder hanya disimulasikan di e2e (butuh folder yang kehilangan View sesudah list dimuat); respons 403 asli dicakup BE AC-8 (`be-ED-1027.txt`), jalur FE-nya sama dengan 404 yang diuji nyata.
5. Penolakan izin/classifier: **tidak ada**; tidak ada fallback Read. Satu perintah tunggu BE regression melewati batas 600 dtk foreground dan otomatis dipindah ke background (bukan penolakan), dilanjutkan dengan loop tunggu berbatas.

## Data uji (dibuat, dihapus, dipulihkan)

- Fixture `0QA27-*` (30 archives, 4 sesi terkonfirmasi) dibuat tiap run e2e dan **dibuang persis** di teardown (`fixture down`: 30 archives + 4-5 sesi fixture/hasil Confirm lewat UI dibuang, 0 baris developer dipulihkan, display setting identik); jurnal `.fixture-journal.json`/`.grant-journal.json`/`.fixture-ids.json` dan kunci `/tmp/ed1027-e2e-state.lock` tidak tersisa. Test "jalur error NYATA" menonaktifkan lalu mengaktifkan lagi **hanya** `0QA27-EMPTY` (`finally`), baris dibuang teardown.
- Grant sementara `Opname Document` ke role 5 (QA_USER2; 6 user memegang role itu) hanya selama dua test `[user2]` per run: 1 baris `role_permissions` (`created_by QA27E2E`) dibuat lalu dihapus per id jurnal; `revoke-od` memverifikasi jumlah+CRC `role_permissions` sama sebelum/sesudah.
- Display setting `documentArchive` diubah AC-14 dan AC-25 (global per modul) lalu dipulihkan persis oleh fixture; skenario BE X-1 menyentuh `updater_logs` id 197 dan memulihkannya.
- **Bukti pemulihan**: sidik jari 17 tabel (`archives`, `archive_documents`, `archive_locations`, `archive_permissions`, 3 tabel opname, `column_display_settings`, `user_roles`, `role_permissions`, `employees`, `employee_locations`, `updater_logs`, `roles`, `permissions`, `permission_checks`, `locations`) `fp-before.json` = `fp-after.json` (diff kosong), dan sama dengan `work/qa-1027-fe-rev/fp-after.json`. Tidak ada data developer yang dihapus atau diubah. Semua token log-out (e2e: 2/2 dicabut tiap run; runner BE: masih aktif 0).

## Riwayat ronde

- Ronde 1 (2026-10-07): PASS. 8 AC FE PASS, AC-21 MANUAL; build OK, test Archive 190/190; e2e 13/13; regresi BE tanpa cacat produk. Laporan lama diganti oleh ronde revisi.
- Ronde 1 revisi (2026-10-10): **FAIL**. D-1 klik `V/T` folder crash (larik `name`); AC-13..20, 25 PASS, AC-21 MANUAL, AC-26/27 FAIL, AC-28/29 sebagian; e2e 15 OK / 5 GAGAL; QA e2e item diperbarui; regresi BE hijau (ED-1028 usang dicatat); DB identik.
- Ronde 2 revisi (2026-10-10): **PASS**. D-1 tertutup (diuji nyata, tanpa mengubah respons); 13 AC PASS + AC-21 MANUAL; unit Archive 357/358 (flake ED-1025), e2e 21/21; Q-1 (race 2 worker) diperbaiki dengan kunci state; regresi BE hijau; DB identik.
