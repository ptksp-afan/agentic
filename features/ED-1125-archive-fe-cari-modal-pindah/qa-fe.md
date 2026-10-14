---
key: ED-1125
scope: fe
round: 2
verdict: PASS
counts: {pass: 19, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---

## Ringkasan

Ronde 2 (ronde perbaikan: bug C / ED-1153 masuk spec), **seluruh spec AC-1..AC-20**, FE saja. FE: HEAD `800e42faf` + 5 berkas M + 3 berkas baru (`changed.txt`: 3 `index.js` modal Letakkan/Serahkan/Terima, 3 `index.test.js`, task 026, `_module.md`); BE tidak berubah (HEAD `0b8417778`, 0 berkas kotor); `src/hooks` dan `src/components` (`useBarcodeScanner`, `Search`) tidak berubah dibanding `cf37552dd` (A-6 dijaga). **Verdict PASS**: 19 AC PASS, 1 MANUAL (AC-13, Gate 2), 0 defect, 0 respons 5xx baru.

- Build staging `scripts/fe-build.sh build`: exit 0, `builtAt 22:17:11` > mtime kode terakhir (21:35); peringatan harness "MUNGKIN USANG" = heuristik berkas kotor.
- e2e ED-1125 (`--workers 1`, profil default = QA_USER, bahasa ID, fixture `0QA125-`): **18 OK, 0 GAGAL** (17 test + smoke `/archives`; 4 test baru: AC-17 ditulis ulang, AC-19, AC-20, dua tambahan jalur sukses). Galeri kanonik: `work/e2e/ED-1125-archive-fe-cari-modal-pindah-20261014-233211/index.html` (77 screenshot, `errors: []`, 0 allow-list).
- **Kontrol negatif bug C** (spec e2e yang sama terhadap build FE `800e42faf` dari `git archive` di scratch, sudah dihapus): AC-17, AC-19, AC-20 dan "Simpan & Baru" **GAGAL** persis pada asersi bug (scan sesudah Kembali memicu `GET search={"query":…}` ke tabel halaman); tes jalur sukses polos lulus di keduanya (memang tidak membedakan). Unit AC-18 terhadap kode lama: 8 dari 15 gagal.
- Unit: Archive `--runInBand` **17 suite, 491/491**; AC-10 26/26; AC-18 15/15. Regresi AC-12 hijau (ED-1150 lewat salinan, lihat catatan); ED-1069 5 OK; BE 10 fitur **240 PASS, 0 FAIL, 2 SKIP** (isolasi tenant, 1 DB).
- Data dan sesi bersih: `0QA125-%` = 0, bahasa QA_USER = ID, token aktif QA_USER/QA_USER2/sam = 0, `--leftover` "tidak ada sisa QA".

**Catatan untuk orchestrator:** (1) test ED-1150 asli (`AC-6/7/8`, `sam`) kini **SKIP BLOCKED-by-data** (guard `root = 3 baris`; developer menambah `Folder X` di root `sam`); regresi dijalankan dari salinan dengan guard dilonggarkan, 3 OK (lihat AC-12). (2) "Simpan & Baru" di review sudah dicoba di browser (sesuai BR-8/BR-9, lihat "Tambahan").

## Hasil per AC

Definisi persis dari spec: "0 navigasi" = CDP `Page.frameRequestedNavigation` kosong dan penanda `window` bertahan; "GET search" = `GET document-archive/archives?search={"query":"<teks>"}&page=1` 200; "scan" = klik judul modal, ketik kode tanpa jeda lalu Enter; "halaman utuh" = baris, breadcrumb dan teks Cari halaman (di luar `.ant-modal`) sama dengan sebelum aksi. `E` = galeri kanonik `...-20261014-233211/` (log `work/ED-1125-r2-e2e-final2.log`, spec `qa/e2e/ED-1125-cari-modal-pindah.spec.js`). AC-1..AC-16 dijalankan ulang penuh di ronde ini (bukan salinan hasil ronde 1).

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | e2e AC-1: ⋯ Pindahkan `0QA125-A`, ketik `0QA125-C` + Enter | PASS | `E`: 1 `form` di modal (`nested 0`); tepat 1 GET `{"query":"0QA125-C"}` `page=1` tanpa `idArchive`, 200; 0 navigasi + penanda bertahan; tabel modal = respons; judul `Pindahkan - 0QA125-A`, Cari tetap; 0 POST put-in; halaman utuh; DB A identik |
| AC-2 | e2e AC-2: ketik, ikon filter, Cari | PASS | sama dengan AC-1 + popover menutup (`ant-popover-hidden`) |
| AC-3 | e2e AC-3/4: pilih M, M2 → Pindahkan → Lanjut → `0QA125-T` + Enter | PASS | 0 navigasi; 1 GET search; tabel (baris terlihat) = respons; tetap di Pilih Folder, judul `Pindahkan - 2 dipilih`; Sebelum → Konfirmasi memuat M, M2; mode Pilih tetap; 0 POST |
| AC-4 | e2e AC-3/4 bagian kedua: ikon filter → Cari | PASS | 1 GET search, popover menutup, 0 navigasi, tetap di Pilih Folder |
| AC-5 | e2e AC-5, kedua modal, DB sebelum/sesudah | PASS | 0 POST put-in; DB A, M, M2, T identik; radio T tetap tercentang; Pindahkan aktif, juga sesudah cari kedua |
| AC-6 | e2e AC-6 (a)(b)(c) + DB, `fixture.php restore` | PASS | (a) POST 400 `ARCHIVE404`, toast id-ID `Tidak bisa dipindahkan ke dirinya sendiri atau subfoldernya`, modal tetap, DB A/B/C identik; (b) M → T 200, DB M di bawah T, dipulihkan; (c) massal [M, M2] → T 200, DB keduanya di bawah T, dipulihkan. Tepat 3 POST |
| AC-7 | e2e AC-7: picker di A > B, cari T, reset X | PASS | breadcrumb `Arsip > 0QA125-A > 0QA125-B` → `Arsip`; request tanpa `idArchive`/`id_archive`; tabel = respons; reset X → GET root tanpa search |
| AC-8 | e2e AC-8: Search halaman Enter dan Cari | PASS | 1 GET search tiap aksi, 0 navigasi, baris = hasil, popover menutup |
| AC-9 | e2e AC-9 + smoke `/archives`: root, modal Pindahkan (folder, dokumen), massal 2 langkah, sebelum/sesudah cari dan scan, 1440 dan 390 px | PASS | monitor: 0 error konsol, 0 page error, 0 respons ≥ 400 (400 `ARCHIVE404` AC-6 dikeluarkan eksplisit), 0 respons 5xx, tanpa toast galat; 15 screenshot `AC09-*.png`; run: `errors: []`, 0 allow-list |
| AC-10 | Jest: `ArchiveMoveModal`, `ArchiveBulkMoveModal`, `ArchiveTable` (`work/ED-1125-r2-jest-modals.txt`) | PASS | 3 suite, 26/26: (a) 1 `form`, `form form` = 0 di kedua modal; (b) submit Search → GET `search.query` tanpa `idArchive`, modal tetap; (c) pilih baris → Pindahkan → POST `{idArchives, idArchiveParent}`; (d) buka → `setActiveScanner(false)`, Batal/✕ → `(true)`, 400 → tetap mati; K-3 a di `ArchiveTable` |
| AC-11 | e2e ED-1069 penuh `--workers 1` (spec hasil sunting ronde 1: "B-FE-1 (diperbaiki ED-1125)", AC-13 ketik sungguhan) | PASS | `work/e2e/ED-1069-archive-validasi-pindah-rename-folder-20261014-223658`: **5 OK** (smoke, AC-13 single, AC-13 massal, AC-14, B-FE-1: 1 form tidak bersarang, 0 navigasi, GET search, modal tetap, Enter dan Cari, kedua modal) |
| AC-12 | e2e regresi + Jest Archive, lihat "Galeri e2e & regresi BE" | PASS (ED-1150 lewat salinan) | ED-1025 5 OK, ED-1027 1, ED-1072 2, ED-1026 GAMMA 1, ED-1028 AC-22 1, Jest 17/17; ED-1150 AC-6/7/8 asli = 3 SKIP BLOCKED-by-data, salinan guard dilonggarkan = 3 OK (dua kali) |
| AC-13 | MANUAL (Gate 2) | MANUAL | Ada di `ksp-react/docs/specs/Archive/026-fix-cari-scan-modal-pindah.md` `## Daftar tes UI`: butir 1-4, 7-12 (12 = bandingkan tampilan; jalan keluar `component="div"`). Pendukung (bukan pengganti): metrik tata letak 5 keadaan x 10 selektor vs baseline `cf37552dd` (run ronde 1 `...-185139`): **nol selisih** kotak dan gaya; hanya jumlah `<form>` berubah (2 → 1, massal Konfirmasi 1 → 0) |
| AC-14 | e2e AC-14: modal Pindahkan A, pilih M, klik judul, scan `0QA125-T` | PASS | tepat 1 GET search (dari picker); Cari picker = kode; tabel modal = respons; halaman utuh dan Cari halaman `''`; judul dan pilihan M (Pindahkan aktif) tetap; 0 navigasi; 0 POST |
| AC-15 | e2e AC-15: massal [M, M2], scan di Konfirmasi; Lanjut, pilih T, scan di Pilih Folder | PASS | Konfirmasi: 0 GET archives, halaman utuh, judul/langkah/item tetap. Pilih Folder: tepat 1 GET search, Cari picker berisi kode, tabel = respons, judul dan pilihan T tetap, halaman utuh. Catatan O-2 |
| AC-16 | e2e AC-16: tutup lewat Batal, ✕, sukses (single dan massal), tiap kali scan di halaman | PASS | 6 skenario: tiap kali tepat 1 GET search `{"query":"<kode>"}` 200, Cari halaman berisi kode, jumlah baris halaman = respons, modal sudah tidak ada; sukses single (M → T) dan massal ([M, M2] → T) 200, DB dipulihkan identik; 0 navigasi |
| AC-17 | e2e AC-17 (baru, ditulis ulang): folder A → "+" → Letakkan Dokumen; scan `DOC17A` → Kembali → scan `DOC17B` → ✕ → scan `DOC17C` → Kembali → Batal → scan | PASS | `E`: tiap scan di dalam modal: review memuat kode baru dan tidak memuat kode lama, **0 GET archives** (bukan hanya 0 GET search), 0 navigasi, halaman utuh (baris, breadcrumb, Cari kosong), modal terbuka dan judul sama; Kembali dan ✕ → langkah "Scan Code" + tombol Batal. Sesudah Batal: tepat 1 GET `{"query":"0QA125-T"}` 200, Cari halaman = kode, baris = respons. 0 POST. `AC17-01..07-*.png`. **Baseline `800e42faf`: GAGAL** ("scan 2 sesudah Kembali: 0 GET search" → `Array [ … ]` berisi GET search) |
| AC-18 | Jest `ArchiveDocumentModal`, `ArchiveDocumentHandOverModal`, `ArchiveDocumentReceiveModal` (`work/ED-1125-r2-jest-docmodals.txt`) + kontrol negatif | PASS | 3 suite baru, **15/15**: (a) review → Kembali dan ✕ → langkah scanner, dialog sama, `setActiveScanner.mock.calls` = `[[false]]` (tanpa `true`); Serahkan/Terima: hanya `GET search.name` milik modal; (b) di scanner Batal dan ✕ → tertutup, `[[false],[true]]` (sekali); (c) simpan/serahkan/terima sukses → POST berbody benar, `onSuccessAdd` (meniru `ArchivePage`: `onCloseAdd` kedua), tertutup, `[[false],[true]]`; (d) langkah hasil (sudah diserahkan / belum diserahkan / sudah dibawa): tombol simpan nonaktif, Batal → tertutup, `[[false],[true]]`, 0 POST. Terhadap kode lama `800e42faf` (tes sama di salinan): **8/15 gagal** (3x (a), 3x (c), 2x (b) Letakkan); (b)/(d) Serahkan/Terima lulus di keduanya (memang sudah benar). Di browser: lihat "Tambahan"  |
| AC-19 | e2e AC-19 (baru): "+" → Serahkan Dokumen; `0QA125-HO1` (tak dikenal) → Kembali → `HO2` → ✕ → `HO3` → Kembali → Batal → scan | PASS | `E`: jejak API modal persis `GET {"name":"0QA125-HO1"}`, `{"name":"0QA125-HO2"}`, `{"name":"0QA125-HO3"}` (masing-masing 200, tepat 1 per scan, tidak ada GET archives lain); **0 GET `{"query":…}`**; review memuat kode baru, tidak memuat kode lama; halaman utuh; 0 navigasi; Kembali dan ✕ → "Scan Code", modal tetap terbuka. Sesudah Batal: 1 GET `{"query":"0QA125-T"}`, Cari halaman = kode. `AC19-01..07-*.png`. **Baseline: GAGAL** (GET search ke tabel halaman) |
| AC-20 | e2e AC-20 (baru): sama dengan AC-19 untuk Terima Dokumen (`RC1`/`RC2`/`RC3`) | PASS | jejak API modal `{"name":"0QA125-RC1"}`, `RC2`, `RC3`, lalu `{"query":"0QA125-T"}` sesudah Batal; asersi sama dengan AC-19. `AC20-01..07-*.png`. **Baseline: GAGAL** |

### Tambahan (bukan syarat AC; jalur yang paling berisiko dari perubahan) - semuanya PASS

Memakai dokumen fixture `0QA125-D6` (milik QA), dipulihkan `fixture.php restore`; `prop('D6')` = snapshot dicek di dalam test.

- **Letakkan, Simpan sukses** (D6 → A): POST put-in 200 (DB `id_archive_parent` = A); modal menutup (`onCloseAdd` kedua, 500 ms, menutup dan menyalakan scanner); scan → tepat 1 GET `{"query":…}`, Cari halaman = kode. `AC17t-*.png`.
- **Serahkan "Simpan & Baru"** (jawaban pertanyaan dev review batch): POST hand-over 200 (DB D6 status 2); modal **kembali ke langkah scanner dan tetap terbuka**; scan `HO9` → hanya `GET {"name":"0QA125-HO9"}`, 0 GET search, halaman utuh (scanner halaman tetap mati, sesuai BR-8/BR-9; baseline GAGAL). Kembali → Batal → scan → 1 GET search. `AC19t-*.png`.
- **Terima sukses** (D6 2 → 3): POST receive 200; modal menutup; scan → 1 GET search. `AC20t-01-*.png`.
- **Langkah hasil di browser** (AC-18 d): Terima D6 masih di folder → "Gagal Menerima / Dokumen belum diserahkan"; Serahkan D6 sudah diserahkan → "Gagal Menyerahkan / Dokumen sudah diserahkan"; tombol simpan nonaktif, 0 GET search selama modal terbuka, Batal menutup, scan → 1 GET search. `AC18d-*.png`.

## Defect

Tidak ada.

## Galeri e2e & regresi BE

Galeri kanonik: `/var/www/opname-archive/agentic/work/e2e/ED-1125-archive-fe-cari-modal-pindah-20261014-233211/index.html` (18 OK). Kontrol negatif bug C: `.../ED-1125-archive-fe-cari-modal-pindah-20261014-222823/` (1 OK, 4 GAGAL). Run pengembangan tes baru: `-222053` (2 gagal karena asersi QA sendiri: kode `DOC17` awalan `DOC17B`; fixture D6 tanpa `breadcrumbs` sehingga review tanpa baris folder; diperbaiki di skrip, bukan defect FE), `-222306`, `-224454`.

**AC-12 regresi e2e** (semua `--workers 1`, fixture sendiri-sendiri, teardown "tanpa sisa"/"baseline"):

| Item | Test | Hasil | Galeri (`work/e2e/...`) |
|---|---|---|---|
| ED-1025 | AC-3 A, AC-3 C, AC-40 A (id), AC-40 A (en) [default]; AC-21 B [user2] | 5 OK | `ED-1025-archive-folder-permission-20261014-223859` |
| ED-1027 | AC-15 | 1 OK | `ED-1027-archive-status-verifikasi-20261014-224114` |
| ED-1072 | AC-4, AC-5 | 2 OK | `ED-1072-archive-fe-klik-menu-baris-20261014-224131` |
| ED-1026 | GAMMA (scan opname: ketik, USB di luar input, kamera) | 1 OK | `ED-1026-archive-opname-document-20261014-224217` |
| ED-1028 | AC-22 (scan saat drawer riwayat terbuka, sesudah ✕ / modal ditutup) | 1 OK | `ED-1028-archive-opname-history-20261014-224304` |
| ED-1069 | seluruh spec (AC-11) | 5 OK | `ED-1069-archive-validasi-pindah-rename-folder-20261014-223658` |
| ED-1150 (`sam`) **asli** | AC-6, AC-7, AC-8 via `run-sam.sh` (prasyarat `--prereq` = `"ok": false`) | **3 SKIP** BLOCKED-by-data | `ED-1150-archive-fe-virtualtable-list-pendek-20261014-224203` |
| ED-1150 (`sam`) salinan | AC-6, AC-7, AC-8, guard `requireRoot` dilonggarkan (`qa/ed1150-sam-longgar/`) | 3 OK, dua kali (tanpa force_login baru) | `ed1150-relaxed-20261014-224609`, `ed1150-longgar-20261014-234056` |

Penyebab SKIP ED-1150: root `sam` kini 4 baris (`Backup Arsip`, `CABANG - SEMARANG`, `Folder A`, `Folder X`); guard spec mensyaratkan `rows.length === 3`. `Folder X` (+ `Folder Y/Z` di bawahnya) dibuat `kevinsudjadi.adm` (developer; waktu app 02:47, sekitar 19:47 UTC, sebelum ronde ini mulai 21:27 UTC). Data developer tidak disentuh. Salinan melonggarkan **satu baris guard** (`ok = rows.includes('Folder A')`, `qa/ed1150-sam-longgar/guard-root.diff`); asersi isi (baris DOM = respons, terlihat, tanpa tulis, tanpa error konsol) tetap sama karena pembanding spec itu selalu respons list terakhir, bukan nilai hardcode. Spec ED-1150 asli tidak diubah (ED-1149 BR-7).

**Jest:**

| Perintah | Hasil |
|---|---|
| `yarn test --watchAll=false --runInBand src/containers/Archive` | 17 suite, **491/491** (`work/ED-1125-r2-jest-archive.txt`, load rendah, tanpa timeout) |
| 3 suite AC-10 `--verbose` | 26/26 (`work/ED-1125-r2-jest-modals.txt`) |
| 3 suite AC-18 baru | 15/15 (`work/ED-1125-r2-jest-docmodals.txt`); terhadap kode lama 8 gagal / 7 lulus (`work/ED-1125-r2-jest-docmodals-baseline.txt`) |

Full suite non-Archive tidak dijalankan ulang: ronde ini hanya menyentuh `src/containers/Archive` + `docs` (`git diff cf37552dd --name-only`); 4 kegagalan pra-ada non-Archive ronde 1 tidak relevan.

**Regresi BE** (`"$PHP_BIN" scripts/qa-http/run.php <KEY>`, `work/ED-1125-r2-be-<KEY>.log`; BE tidak diubah): ED-1024 26/0, ED-1025 40/0, ED-1026 39/0, ED-1027 28/0 (+1 SKIP X-3), ED-1028 24/0, ED-1029 40/0 (+1 SKIP X-ISO), ED-1069 16/0, ED-1071 16/0, ED-1124 10/0, ED-1150 1/0 = **240 PASS, 0 FAIL, 2 SKIP** (isolasi tenant butuh 2 DB; `QA_DBS` hanya `api_sidomaju`), 0 BLOCKED. Dua HTTP 500 di log ED-1027 (X-13 = A-1; X-10 `sorts` array = ED-1070 ditunda) adalah karakterisasi sengaja yang diterima, bukan temuan. Sama dengan ronde 1.

## Yang tidak bisa diverifikasi (dan kenapa)

Tidak ada AC `not_verifiable`. Catatan batas:

- **ED-1150 AC-6/7/8 asli tidak bisa dijalankan** (SKIP BLOCKED-by-data, di atas); diganti salinan dengan satu baris guard dilonggarkan, 3 OK. Bila orchestrator tidak menerima pengganti ini, AC-12 bagian itu harus dianggap tidak terverifikasi sampai guard ED-1150 diperbarui atau data `sam` kembali ke 3 baris.
- Scanner USB asli dan scan kamera: e2e mensimulasikan scanner dengan `keyboard.type` tanpa jeda + Enter (< 30 ms); kamera di-mock di unit, tidak dijalankan di e2e.
- Penilaian tampilan modal (AC-13, butir 12 daftar tes UI): hanya metrik tata letak yang dibandingkan (nol selisih); mata developer tetap perlu.

## Pengamatan di luar item (bukan defect, tidak mengubah verdict)

- **O-1 (ronde 1) selesai**: scan sesudah Kembali/✕ di Letakkan Dokumen yang mencari tabel halaman (ED-1153) diperbaiki di item ini, dibuktikan AC-17 (gagal di `800e42faf`).
- **O-2 (sudah dicatat dev di 026, sesuai desain, belum dikonfirmasi developer)**: di pindah massal, sesudah Pilih Folder pernah dibuka lalu Sebelum ke Konfirmasi, scan diterima picker tersembunyi (panel rc-tabs tetap ter-mount): diamati lagi di run ini (1 GET search `0QA125-F`; halaman, judul, langkah, item tetap utuh).
- **O-3 (pra-ada, `components/Search`)**: klik kolom Cari memfokus ulang 100 ms kemudian; ketikan pertama dalam 100 ms tertimpa (skrip menunggu 350 ms); tanpa tindakan.
- **O-4 (baru, rendah, alat QA)**: guard `requireRoot` spec ED-1150 (`ED-1150-virtualtable-list-pendek.spec.js:274`) membatasi `root = 3 baris`, sehingga data developer di akun `sam` (yang wajar bertambah) membuat 3 test SKIP tanpa ada regresi. Kandidat tiket/ubahan kecil: longgarkan guard (cukup memuat `Folder A`), seperti `qa/ed1150-sam-longgar/guard-root.diff`. Bukan bug FE/BE.
- **O-5 (info)**: sesudah "Simpan & Baru" di Letakkan/Serahkan/Terima, scanner halaman sengaja tetap mati sampai modal ditutup (perilaku baru perbaikan C; sebelumnya menyala tanpa modal tertutup). Dicoba di browser untuk Serahkan; Letakkan/Terima memakai `onCloseAdd` identik, tidak dijalankan terpisah.

## Data uji, kebersihan, sesi

- Tulisan DB milik QA hanya `archives` berawalan `0QA125-` (A, B, C, T, M, M2, F, D6 = 8 baris + 1 `archive_locations` untuk B), dibuat `fixture.php up` dan dibuang persis `fixture.php down` (jurnal id + awalan): 8 run ED-1125 ronde ini (satu gagal dimuat karena skrip, tiga pengembangan, satu kontrol negatif, dua kanonik/final, satu tambahan) semuanya "tanpa sisa". Alur tulis (AC-6, AC-16, tambahan D6: put-in, hand-over, receive) dipulihkan `fixture.php restore` dan dibandingkan baris-per-baris (`prop` = snapshot). Teardown tidak pernah jalan sesudah setup gagal. Suite regresi memakai fixture dan teardown masing-masing (semuanya "tanpa sisa"/"baseline"; ED-1025 AC-40 en memulihkan bahasa QA_USER).
- QA_USER (role 3 Super Admin) sudah memiliki `Handover Document`/`Receive Document`, bahasa ID: **tanpa role preset atau perubahan bahasa**.
- Pemeriksaan akhir (`qa/scenario-teardown.php`, baca saja, dijalankan dua kali): CLEAN-1 PASS (0 baris `0QA125-%` di `archives` dan `archive_documents`), CLEAN-2 PASS (bahasa QA_USER = `ID`), SESI-1 PASS (token aktif QA_USER 0, QA_USER2 0, QA_USER3/`sam` 0). Semua e2e `log-out ... dicabut=ya`; `force_login` hanya mencabut satu sesi QA_USER2 lama di run ED-1025 (pola suite itu), tidak ada sesi `sam`/developer.
- Snapshot read-only ED-1149 `snapshot.php ED-1125-r2-after` dibanding `ED-1125-after` (ronde 1): 28 beda, semuanya cocok dengan tindakan developer di jendela review (sebelum ronde ini): `archives` +3 (`Folder X/Y/Z`, `created_by kevinsudjadi.adm`) + `DO-MGL/2608/00057` dipindah user itu; `role_permissions` +4 baris (id berurutan 3224451-54, `created_by` NULL, "Allow Check In/Out Outside Radius" untuk role 5 dan 8; baris QA selalu berpenanda dan dibuang) serta hash `updater_logs`. `--leftover` = "tidak ada sisa QA". Keterbatasan: snapshot "sebelum" ronde 2 tidak diambil; atribusi berdasar `created_by`/waktu/id (kuat, bukan mutlak). Tidak ada tabel dikosongkan/di-reset; data developer tidak ditulis; tidak ada baris bukan-fixture dihapus.
- Scratch dihapus: salinan `git archive` `800e42faf` (symlink `node_modules` dilepas dulu; `node_modules` FE utuh) dan build baseline `/tmp/ksp-react-v5-build-base-ed1125`; `build/` developer dan port 4000 tidak disentuh; staging `/tmp/ksp-react-v5-build` = build perbaikan. Proses lain tidak dimatikan; tanpa penolakan izin/classifier.

## Berkas QA (di `features/ED-1125-archive-fe-cari-modal-pindah/qa/`)

`e2e/ED-1125-cari-modal-pindah.spec.js` (17 test; AC-17 ditulis ulang, AC-19, AC-20, 2 tambahan; helper `trace` mencatat `name`), `e2e/fixture.php`, `fixtures.json`, `routes.json`, `scenario-teardown.php` (bukti pembersihan), baru `ed1150-sam-longgar/run.sh` + `guard-root.diff`. Log: `work/ED-1125-r2-*`. Skrip ED-1069 tidak berubah ronde ini.

## Riwayat ronde

- Ronde 1: PASS. 16 AC PASS, AC-13 MANUAL, 0 defect; kontrol negatif baseline `cf37552dd` 9 GAGAL / 4 OK; regresi AC-12 + BE hijau; O-1 (→ ED-1153), O-2, O-3.
- Ronde 2: PASS. Spec + bug C (AC-17..AC-20); AC-1..AC-20 dijalankan ulang: 19 PASS, AC-13 MANUAL, 0 defect; e2e 18 OK; kontrol negatif bug C (e2e 4 GAGAL, unit 8/15); Jest 491/491; BE 240 PASS; ED-1150 asli SKIP (data developer), salinan 3 OK.
