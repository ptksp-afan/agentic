---
key: ED-1072
scope: fe
round: 1
verdict: PASS
counts: {pass: 10, fail: 0, manual: 0, not_verifiable: 1}
be_server: fpm
---
## Ringkasan

QA FE ronde 1 untuk bug ED-1072 (klik ⋯ dan item menunya pada baris dokumen ikut memicu klik sel). Objek uji: working tree FE di atas `47bb57d1d` (4 berkas kode/test + `_module.md` M + task baru `021-fix-cell-action-click.md`), BE tidak berubah (HEAD `878314df7`, 0 berkas kotor, tanpa `contract.md`). Tidak ada yang di-stash, di-revert, atau di-commit oleh QA.

- **Verdict PASS.** AC-1..AC-10 semuanya PASS. 0 defect pada item ini. Satu sub-klaim tidak bisa diverifikasi: *keystroke* Enter asli pada tombol ⋯ (lihat "Yang tidak bisa diverifikasi" dan N-1); penyebabnya pra-ada dan di luar item, jadi AC-2 dinilai lewat klik dan aktivasi papan-tombol setara.
- **Bukti perbaikan bermakna (kontrol negatif):** spec e2e yang sama dijalankan terhadap build `HEAD 47bb57d1d` (tanpa perbaikan; dibuat dari `git archive` ke direktori scratch, working tree developer tidak disentuh): AC-2, 3, 4, 5, 9 dan K-2 popover **GAGAL** (breadcrumb berubah / baris terpilih / "1 dipilih"), sedangkan AC-1, 6, 7, 8 (regresi) tetap lulus. Terhadap build dengan perbaikan semuanya lulus. Galeri HEAD: `work/e2e/ED-1072-archive-fe-klik-menu-baris-20261010-163812/`.
- Build staging OK (`scripts/fe-build.sh build`, 67 dtk, exit 0; bundle memuat `data-cell-action`). Peringatan harness "build staging MUNGKIN USANG" = positif palsu karena working tree kotor; build (16:12) lebih baru dari semua perubahan kode (`archive.function.js` 15:40, `ArchivePage/index.js` 15:46).
- **e2e akhir: 11 OK, 0 GAGAL, 0 SKIP** (1 smoke route + 10 flow), `--workers 1`, profil `default` (QA_USER, DB `api_sidomaju`), 0 error konsol/page/API >= 400, 0 respons 5xx, log-out token dibuktikan. Galeri kanonik: `/var/www/opname-archive/agentic/work/e2e/ED-1072-archive-fe-klik-menu-baris-20261010-172748/index.html`.
- Jest: modul Archive `--runInBand` **11 suite, 372/372 lulus** (termasuk 14 test baru). Full suite sekali: 79 suite, 11 gagal / 15 test (beban); di-run ulang sendirian: 7 suite lulus (ArchivePage 39/39 dua kali), tersisa hanya 4 suite baseline di luar diff (lihat Galeri & regresi).
- Regresi BE (BE tidak berubah): ED-1024 26/26; ED-1025 39 PASS + 1 FAIL (X-12, milik ED-1071, sudah tercatat); ED-1026 39/39; ED-1027 28 PASS + 1 SKIP (X-3); ED-1028 24/24; ED-1029 29 PASS + 1 SKIP (X-ISO); ED-1069 16/16. Tanpa 5xx baru.
- Data: hanya fixture berawalan `0QA72-` (dibuat dan dibuang persis; akhir run: sisa 0, count + CHECKSUM 4 tabel `archive*` = baseline). Tidak ada data developer yang dibuang, tabel opname tidak disentuh.

## Hasil per AC

`G` = `work/e2e/ED-1072-archive-fe-klik-menu-baris-20261010-172748/` (`report.json`: 11 passed; screenshot di `screenshots/default/`). Spec: `features/ED-1072-archive-fe-klik-menu-baris/qa/e2e/ED-1072-klik-sel.spec.js`. Fixture `fixture.php`/`fixtures.json`: folder F (root) berisi dokumen D1 (status 1) dan D2 (status 2, Tag "Diserahkan") yang baris list-nya membawa `breadcrumbs=[F]`, subfolder S; FP (ber-perisai), FE (kosong). Jest = `work/ED-1072-jest-archive.log`.

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | e2e smoke `/archives` (`routes.json`) + test AC-1: root, di dalam F, hasil pencarian `0QA72-D` (menemukan D1+D2 lintas folder); tanpa toast error (`.ant-message-error`, `.ant-notification-notice-error`), monitor otomatis: console/page error, API >= 400 | PASS | `G`: route "Archive (QA_USER)" OK 3,6 dtk, test AC-1 OK, `errors=0`; `AC1-root.png`, `AC1-dalam-F.png`, `AC1-hasil-cari.png`, `smoke-_archives.png` |
| AC-2 | e2e: di F tanpa baris terpilih, klik ⋯ D1 -> menu persis `Info`, `Pindahkan`; `tr` D1 tanpa `ant-table-row-selected`; breadcrumb tetap `Arsip > 0QA72-F`; tanpa muat ulang daftar (jumlah GET archives tetap). Variasi fokus+Enter: lihat catatan. Unit `ArchivePage/index.test.js` "klik ⋯ baris dokumen..." | PASS (klik + aktivasi setara; Enter asli: N-1) | `G` test AC-2, `AC2-menu-terbuka-klik.png`, `AC2-menu-terbuka-enter.png`; HEAD: GAGAL "sesudah klik ⋯: tidak ada perubahan" (gejala nyata); jest 372/372 |
| AC-3 | e2e: root, cari `0QA72-D`, ⋯ D1 -> Info: drawer "Info" terbuka (GET `archives/history/{id D1}` 200); breadcrumb tetap `Arsip`; D1 tidak terpilih; item "+" sama dengan root (`add-folder, hand-over, receive, opname, set-permission`, tanpa `add-document`) sebelum, sesudah ⋯, dan sesudah drawer ditutup. Kontrol positif: di dalam F "+" memuat `add-document` (gejala yang bocor bila breadcrumb berganti) | PASS | `G` test AC-3 (+ catatan item "+"), `AC3-drawer-info.png`; HEAD: GAGAL |
| AC-4 | e2e: ⋯ D1 -> Pindahkan, di hasil pencarian dan di dalam F: modal "Pindahkan - 0QA72-D1" terbuka; breadcrumb + pilihan tidak berubah saat modal terbuka dan sesudah Batal; tidak ada Pindahkan dikirim (baris fixture di DB identik sebelum/sesudah, `fixture.php inspect`). Unit "klik item menu Pindahkan (portal)" | PASS | `G` test AC-4, `AC4-modal-pindahkan-hasil-cari.png`; HEAD: GAGAL |
| AC-5 | e2e: mode Pilih (0 dipilih), ⋯ D1, lalu Info, lalu Pindahkan/Batal: `N dipilih` tetap 0 di setiap langkah, checkbox D1 kosong; keluar mode tanpa konfirmasi. Unit "mode Pilih: klik ⋯ dokumen tidak mencentangnya" | PASS | `G` test AC-5, `AC5-select-menu-terbuka.png`; HEAD: GAGAL "Expected 0, Received 1" |
| AC-6 (regresi) | e2e di hasil pencarian root (breadcrumb awal `Arsip`): klik ikon file di sel Nama (di luar tautan), sel Tipe, ikon Document Verified D1, dan Tag "Diserahkan" D2 -> baris itu saja terpilih dan breadcrumb = `Arsip > 0QA72-F`. Mode Pilih: klik sel Tipe D1 -> "1 dipilih" (tercentang), klik lagi -> 0. Unit: klik teks Nama dan Tipe | PASS | `G` test AC-6, `AC6-klik-D1-*.png`, `AC6-klik-D2-Tag-*.png`, `AC6-select-toggle-1.png`; lulus juga di HEAD (perilaku tidak berubah) |
| AC-7 (regresi folder) | e2e: klik Nama folder F -> terbuka (`GET archives?idArchive=F` 200, breadcrumb `Arsip > F`); klik ikon perisai FP -> terbuka; ⋯ F -> menu `Info, Pindahkan, Lihat, Opname, Hapus`, tanpa GET `idArchive` (folder tidak dibuka), breadcrumb/pilihan tetap; Info (GET history F 200), Lihat (GET `archives/{F}` 200, nama F di isian), Opname (modal "Opname Dokumen 0QA72-F", Batal) bekerja; mode Pilih: FE dicentang lalu klik Namanya -> tidak membuka (terkunci), "1 dipilih" tetap. DB fixture identik. Unit: ⋯ folder, klik nama dan perisai | PASS | `G` test AC-7, `AC7-menu-folder.png`, `AC7-modal-opname.png`, `AC7-select-terkunci.png`; lulus juga di HEAD |
| AC-8 (ED-1027 AC-27) | ED-1027 rev ada di branch (kolom `V/T` = `button.ant-btn-link`). e2e: (a) root, mode biasa, klik `0/2` F -> modal "Dokumen Terverifikasi 0QA72-F" (2 dokumen); (b) root, mode Pilih dengan FE dicentang, klik `V/T` F -> modal terbuka, "1 dipilih" tetap, F tidak terpilih; (c) di dalam F, mode Pilih dengan D1 tercentang, klik `V/T` subfolder S -> modal S, "1 dipilih" (D1) tetap. Di semua kasus: tanpa GET archives ber-`idArchive` (folder tidak dibuka), breadcrumb tetap | PASS | `G` test AC-8, `AC8-modal-verifikasi-F.png`, `AC8-modal-verifikasi-mode-pilih.png` |
| AC-9 (K-2 a) | e2e: dokumen DO **nyata** `DO-JOG/2511/00007` di folder "Backup Arsip" (baca-saja; `fixture.php real-doc`; fixture palsu menghasilkan 404 di halaman DO yang ikut ter-mount di tab baru, ditolak harness): cari nomornya di root (breadcrumb `Arsip`; baris list membawa `breadcrumbs`), klik kanan nomor -> "Buka ditab baru" -> tab bertambah (1 -> 2: `Arsip | Pengantaran Pesanan`), D tidak terpilih, breadcrumb tetap; tab dibuang lagi. Tambahan sintetis: popover "+2" (respons list diubah di jaringan, hanya di test itu), klik judul dan padding popover (portal) tidak memilih/mengganti breadcrumb | PASS | `G` test AC-9 + test "K-2 a (sintetis)", `AC9-menu-konteks.png`, `AC9-tab-baru-ditambahkan.png`, `K2-popover-terbuka.png`; HEAD: kedua GAGAL |
| AC-10 | Unit `archive.function.test.js` describe `isCellActionClick`: `true` untuk target di dalam `button` (termasuk ikon tombol ⋯), `a[href]`, `[role=menuitem]`, `[role=button]`, `[data-cell-action]`, dan target di luar sel (portal, `document.body`); `false` untuk teks, ikon, `Tag` status, sel itu sendiri, elemen interaktif yang hanya ada di luar sel, dan event tidak lengkap | PASS | `work/ED-1072-jest-archive.log`: 11 suite 372/372; 5 test baru helper |

Pemetaan Daftar tes UI (`ksp-react/docs/specs/Archive/021-fix-cell-action-click.md` § Daftar tes UI) ke bukti e2e: butir 1 -> AC-2; 2 -> AC-2 (Enter, lihat N-1); 3 -> AC-3; 4 -> AC-4; 5 -> AC-5; 6, 7 -> AC-6; 8 -> AC-7; 9 -> AC-8; 10 -> AC-9 (bagian "+N": N-2); 11 -> AC-1. Semua butir sudah ada di daftar developer; tidak ada MANUAL yang hilang dari daftar.

## Defect

Tidak ada defect pada ED-1072 (tidak ada subtask FE/QA yang dibuka QA ini).

## Di luar item ini (bukan defect ED-1072; orkestrator yang membuka tiket)

- **N-1 (rendah, kegunaan/aksesibilitas, pra-ada): Enter/Spasi tidak mengaktifkan tombol apa pun di halaman Archive.** `useBarcodeScanner` (`src/hooks/appHook.js:106`, dipasang oleh `src/containers/Archive/archive-components/ArchiveScanner/index.js:12`) memanggil `e.preventDefault()` pada setiap `keydown` di luar `INPUT`/`TEXTAREA` (kecuali Ctrl/Meta/Alt), jadi *keystroke* Enter pada tombol ⋯, "+" dan "Pilih" tidak menjadi klik. Dibuktikan: `keydown` Enter `defaultPrevented=true` pada tombol terfokus, `activeElement` benar, dan pola sama persis di build `HEAD 47bb57d1d` (sebelum perbaikan). **Akibat langsung pada item ini:** spec AC-2 "(atau fokus + Enter)" dan Daftar tes UI task 021 butir 2 ("Tab lalu Enter -> menu terbuka") **tidak bisa lulus** di UI (bukan akibat perbaikan). Usul: tiket terpisah (scanner mengabaikan keydown dari tombol/tautan terfokus), dan butir 2 di task diberi catatan sampai tiket itu selesai.
- **N-2 (info): popover "+N" di sel Nama dokumen tidak muncul dari data nyata.** BE selalu mengirim satu referensi per baris (`ArchiveService.php:161-167`, `name` = larik berisi satu elemen), jadi Daftar tes UI butir 10 bagian "+N" tidak bisa dijalankan developer dengan data nyata; perilaku K-2 a untuk isi popover hanya terbukti sintetis (respons list diubah di jaringan, kode FE `ModalReference`/`Content` tidak diubah). Perbaikan tetap berlaku bila kelak BE mengirim banyak referensi.
- **N-3 (pra-ada, sudah tercatat):** test ED-1025 "tooltip ikon perisai" di `ArchivePage/index.test.js` gagal bila dijalankan dengan filter `-t` (bergantung urutan) dan flake beban di full suite (ED-1025 C-1); suite utuh sendirian 39/39 dua kali dan di run modul Archive 372/372. ED-1025 X-12 FAIL = ED-1071; 500 BE yang tampak di log regresi = karakterisasi ED-1071/ED-1122 (sudah diajukan, tidak diajukan ulang).
- Tidak ada bug baru lain.

## Galeri e2e & regresi BE

**Galeri e2e (kanonik, 11 OK):** `/var/www/opname-archive/agentic/work/e2e/ED-1072-archive-fe-klik-menu-baris-20261010-172748/index.html` (+ `report.json`, `screenshots/default/` 22 gambar). Galeri kontrol negatif (HEAD, 5 GAGAL + K-2 GAGAL yang diharapkan): `.../ED-1072-archive-fe-klik-menu-baris-20261010-163812/`. Log: `work/ED-1072-e2e-final2.log`, `work/ED-1072-e2e-negctl.log`.

**Jest:**
| Perintah | Hasil |
|---|---|
| `yarn test --watchAll=false --runInBand src/containers/Archive` | 11 suite, 372/372 lulus (`work/ED-1072-jest-archive.log`) |
| Full suite sekali (`work/ED-1072-jest-full.log`) | 79 suite: 68 lulus, 11 gagal; 1678 lulus, 15 gagal |
| Re-run sendirian 7 suite non-baseline yang gagal (ArchiveDrawer, ArchiveOpnameModal, ArchiveOpnameHistory, ItemRaptorImport, RaptorPending, RaptorSetup, ArchivePage) | 12 suite lulus; satu-satunya yang gagal = tooltip ED-1025 (N-3); ArchivePage utuh sendirian 39/39 dua kali (`work/ED-1072-jest-rerun-failed.log`, `...-archivepage-alone-1/2.log`) |
| 4 suite baseline di luar diff, sendirian (`work/ED-1072-jest-baseline4-alone.log`) | BatchOut, InterbankTransferForm, production.function, CustomerPurchaseOrderView tetap gagal (4 test), pra-ada, tidak ada di `git status` |

**Regresi BE** (`"$PHP_BIN" scripts/qa-http/run.php <KEY>`, `work/ED-1072-be-<KEY>.log`):
| Item | PASS | FAIL | SKIP | Catatan |
|---|---|---|---|---|
| ED-1024 | 26 | 0 | 0 | |
| ED-1025 | 39 | 1 | 0 | X-12 = karakterisasi ED-1071 usang (rename name omega/cjk/emoji kini 500), sudah tercatat, milik ED-1071 |
| ED-1026 | 39 | 0 | 0 | |
| ED-1027 | 28 | 0 | 1 | X-3 (isolasi tenant, 1 DB) |
| ED-1028 | 24 | 0 | 0 | |
| ED-1029 | 29 | 0 | 1 | X-ISO (isolasi tenant, 1 DB) |
| ED-1069 | 16 | 0 | 0 | |

Semua run: token dibuat sudah di-log-out, 0 masih aktif. Tidak ada skenario BE yang diubah QA ini.

**Data yang dibuat dan dibuang (DB `api_sidomaju`):** per run e2e, lewat `fixture.php up/down` (jurnal id + sapu berawalan `0QA72-`): 6 baris `archives` (`0QA72-F`, `-D1`, `-D2`, `-S`, `-FP`, `-FE`), 2 baris `archive_documents` (untuk D1, D2), 1 baris `archive_permissions` (hak penuh QA_USER pada FP). Teardown tiap run: "sisa berawalan 0QA72- = 0; tabel archive* = baseline (count + CHECKSUM)"; `fixture.php inspect` sesudah semua run = `[]`. Tidak ada baris lain yang dibuat/diubah/dibuang oleh QA ini; tidak ada purge data developer; tabel opname dan folder developer tidak disentuh. AC-9 hanya membaca (GET) satu dokumen DO nyata. Build `HEAD` untuk kontrol negatif dibuat dari `git archive` di scratch dan sudah dihapus (`/tmp/ksp-react-v5-build-old`); `build/` developer dan port 4000 tidak disentuh. Penolakan izin/classifier: tidak ada.

## Yang tidak bisa diverifikasi (dan kenapa)

1. *Keystroke* Enter asli pada tombol ⋯ (sub-klaim "(atau fokus + Enter)" dari AC-2): `useBarcodeScanner` mem-`preventDefault` setiap keydown (N-1), di build HEAD maupun build baru, sehingga tidak ada tombol di halaman yang bisa diaktifkan lewat keyboard. Yang dibuktikan: aktivasi setara (`HTMLElement.click()` pada tombol terfokus, klik `detail=0` seperti aktivasi papan-tombol di browser) membuka menu tanpa efek klik sel. Dihitung 1 `not_verifiable` dan dilaporkan terpisah; AC-2 sendiri dinilai lewat klik.
2. Popover "+N" dengan data nyata (N-2): hanya sintetis.
3. Penilaian tampilan (tidak ada desain di `brief/` untuk item bug ini; perbaikan tanpa perubahan tampilan): Gate 2 developer lewat Daftar tes UI task 021.

## Riwayat ronde

- Ronde 1: PASS. 10 AC PASS, 0 defect, 1 sub-klaim tidak terverifikasi (Enter asli, pra-ada N-1); e2e 11 OK; kontrol negatif terhadap HEAD membuktikan spec mendeteksi gejala; regresi BE hijau kecuali X-12 (ED-1071).
