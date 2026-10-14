---
key: ED-1125
scope: fe
round: 1
verdict: PASS
counts: {pass: 16, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---

## Ringkasan

Ronde 1, FE saja (`changed.txt`: 11 baris FE, BE tidak berubah; HEAD FE `cf37552dd` + 9 berkas M/?? milik item; BE HEAD `0b8417778`). **Verdict PASS**: 16 AC PASS, 1 MANUAL (AC-13, Gate 2), 0 defect.

- Build staging `scripts/fe-build.sh build`: exit 0 (75 dtk) ke `/tmp/ksp-react-v5-build` (bukan `build/`); `builtAt 17:10:14` lebih baru dari mtime berkas kode terakhir (16:51 modal, 17:04 test). Peringatan harness "Build staging MUNGKIN USANG" = heuristik berkas kotor, bukan fakta.
- e2e fitur (`--workers 1`, profil default = QA_USER, bahasa ID, fixture `0QA125-`): **14 OK, 0 GAGAL** (13 test + smoke `/archives`). Galeri kanonik: `work/e2e/ED-1125-archive-fe-cari-modal-pindah-20261014-185200/index.html`.
- **Kontrol negatif** (spec e2e yang sama terhadap build FE `cf37552dd` dari `git archive` di scratch, sudah dihapus): **9 GAGAL** (AC-1..7, 9, 14, 15: form bersarang `inModal 2`/`nested 1`, tidak ada GET search sesudah Enter karena aplikasi dimuat ulang, scan mengubah tabel halaman) dan 4 OK (AC-8, AC-16, AC-17, smoke = tes regresi yang memang lulus di baseline). Galeri: `work/e2e/ED-1125-archive-fe-cari-modal-pindah-20261014-174744/`. Jadi asersi mendeteksi gejala bug dan lulus hanya dengan perbaikan.
- Unit: modul Archive `--runInBand` **14 suite, 476/476 hijau**; tiga berkas milik item (`ArchiveMoveModal`, `ArchiveBulkMoveModal`, `ArchiveTable`) 26/26.
- Regresi AC-12 semuanya hijau; regresi BE 10 fitur (240 PASS, 0 FAIL, 2 SKIP isolasi 1-DB); tanpa 500 baru.
- Data dan sesi bersih: sisa `0QA125-` = 0, snapshot DB sama dengan sebelum run, tiga user QA tanpa token aktif.

## Hasil per AC

Jalur bukti: `E` = galeri kanonik `...-20261014-185200/` (log `work/ED-1125-e2e-final2.log`, spec `qa/e2e/ED-1125-cari-modal-pindah.spec.js`). Definisi dipakai persis dari spec: "0 navigasi" = CDP `Page.frameRequestedNavigation` kosong dan penanda `window` bertahan; "GET search" = `GET document-archive/archives?search={"query":"<teks>"}&page=1` 200; "halaman utuh" = teks semua baris, breadcrumb dan teks Cari halaman (di luar `.ant-modal`) sama dengan sebelum aksi; ketikan sungguhan (`keyboard.type`, jeda 40 ms, ditunda 350 ms sesudah klik kolom Cari).

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | e2e AC-1: ⋯ Pindahkan `0QA125-A`, ketik `0QA125-C` + Enter | PASS | `E`: modal `{"inModal":1,"nested":0}`; tepat 1 GET search `{"query":"0QA125-C"}` `page=1` tanpa `idArchive`, 200; navs `[]` + penanda bertahan; jumlah dan nama baris tabel modal = respons (C ada, T/M tidak); judul `Pindahkan - 0QA125-A`; Cari tetap `0QA125-C`; 0 POST put-in; halaman utuh; DB baris A identik. `screenshots/default/AC01-02-sesudah-enter-cari-C.png` |
| AC-2 | e2e AC-2: ketik, ikon filter, klik Cari | PASS | sama seperti AC-1 + popover `.search-bar-overlay` berkelas `ant-popover-hidden` sesudahnya. `AC02-01-sesudah-cari-lewat-popover.png` |
| AC-3 | e2e AC-3/4: pilih M, M2 → Pindahkan → Lanjut → `0QA125-T` + Enter | PASS | 0 navigasi; 1 GET search; tabel modal = respons (hanya baris terlihat, panel Konfirmasi tersembunyi tidak dihitung); tetap di Pilih Folder, judul `Pindahkan - 2 dipilih`; **Sebelum** (`button.light`) → Konfirmasi memuat M dan M2; "Batal Pilih" tampil dan checkbox M, M2 halaman masih tercentang; 0 POST. `AC03-02-pilih-folder-sesudah-enter.png`, `AC03-03-kembali-konfirmasi.png` |
| AC-4 | e2e AC-3/4 bagian kedua: Lanjut lagi, ikon filter → Cari | PASS | 1 GET search, 0 navigasi, popover menutup, tetap di Pilih Folder, 0 POST. `AC04-01-pilih-folder-sesudah-cari.png` |
| AC-5 | e2e AC-5: pilih T (radio) dulu, lalu ketik + Enter; kedua modal; DB sebelum/sesudah | PASS | single dan massal: 0 POST put-in (juga 0 di seluruh test); DB A, M, M2, T identik; radio T tetap tercentang di hasil cari; Pindahkan aktif, juga sesudah cari kedua (`0QA125-C`, T tidak ada di tabel) = pilihan bertahan di state modal. `AC05-01-...png`, `AC05-02-...png` |
| AC-6 | e2e AC-6 (a)(b)(c) + DB, dipulihkan `fixture.php restore` | PASS | (a) A → cari C → Pindahkan: POST 400, body `id_archives=[A]`, `id_archive_parent=C`, respons memuat `ARCHIVE404`, toast `Error [400] Tidak bisa dipindahkan ke dirinya sendiri atau subfoldernya` (id-ID), modal tetap dan Pindahkan aktif lagi, DB A/B/C identik. (b) M → cari T: 200 `ARCHIVE204`, DB `id_archive_parent` M = T + history place/move, M hilang dari root layar, restore → M identik. (c) massal [M, M2] → cari T: 200, DB keduanya di bawah T, restore identik. Tepat 3 POST. `AC06-01..03-*.png`, `AC06a-toast-A-ke-C.png` |
| AC-7 | e2e AC-7: modal M, buka A lalu B di picker, cari T, reset X | PASS | breadcrumb picker `Arsip > 0QA125-A > 0QA125-B`; sesudah cari: GET tanpa `idArchive`/`id_archive`, tepat 1 GET search, breadcrumb **"Arsip"** (teks id; "Archive" di en), tabel = respons; reset X → GET root tanpa search/`idArchive`, tabel = respons (A, T, M2, F ada), Cari kosong. `AC07-01..03-*.png` |
| AC-8 | e2e AC-8: Search halaman, Enter lalu ikon filter → Cari | PASS | 1 GET search tiap aksi, 0 navigasi + penanda bertahan, baris halaman = hasil (C ada, T tidak), popover menutup. Lulus juga di baseline (tes regresi). `AC08-01/02-*.png` |
| AC-9 | e2e AC-9 (+ smoke `routes.json` `/archives`): root, modal Pindahkan folder (A) dan dokumen (D6 di F), pindah massal 2 langkah; sebelum/sesudah cari dan scan; desktop 1440 dan 390 px | PASS | monitor harness otomatis: 0 error konsol, 0 page error, 0 respons >= 400 (kecuali 400 `ARCHIVE404` AC-6 yang diharapkan dan dikeluarkan eksplisit); 0 respons 5xx; tanpa toast galat; 0 POST. 15 screenshot `AC09-01..15-*.png` (4 di antaranya 390 px) |
| AC-10 | Jest: `yarn test --watchAll=false src/containers/Archive/ArchiveMoveModal src/containers/Archive/ArchiveBulkMoveModal src/containers/Archive/archive-components/ArchiveTable` | PASS | 3 suite, 26/26 (`work/ED-1125-jest-modals.txt`): (a) 1 `form`, `form form` = 0 di kedua modal; (b) submit Search → GET `search.query`, `page` 1, `idArchive` null, modal tetap; (c) pilih baris → Pindahkan → POST `{idArchives, idArchiveParent}`; (d) buka → `setActiveScanner(false)`, Batal, ✕ dan sukses → `(true)`, 400 → tetap mati; tambahan K-3 a di `ArchiveTable`. Mutasi balik oleh dev (tercatat di 026) tidak diulang; kontrol negatif e2e cukup |
| AC-11 | e2e ED-1069 `--workers 1` (skrip disunting QA: `armPickerSearch` dihapus, helper `typeSearch`, "karakterisasi B-FE-1" dibalik jadi asersi) | PASS | `work/e2e/ED-1069-archive-validasi-pindah-rename-folder-20261014-175934/`: **5 OK**. AC-13 single dan massal memakai ketik sungguhan (GET search asli, ARCHIVE404 dan sukses tetap lulus); "B-FE-1 (diperbaiki ED-1125)": `{"nested":false,"formsInModal":1,"formInForm":0}`, 0 navigasi + penanda bertahan, GET search 200, modal tetap, untuk Enter DAN tombol Cari di kedua modal; 0 POST put-in. Dicatat sebagai F-6/F-7/F-8 gate1 §3 |
| AC-12 | e2e regresi + Jest (tabel di bawah) | PASS | lihat "Galeri e2e & regresi BE" |
| AC-13 | MANUAL (Gate 2) | MANUAL | Ada di `ksp-react/docs/specs/Archive/026-fix-cari-scan-modal-pindah.md` `## Daftar tes UI`: butir 1-4 (Enter/Cari kedua modal), 7-11 (massal, scan USB), **12 (bandingkan tampilan modal desktop dan 390 px, dengan jalan keluar `component="div"`)**. Bukti pendukung QA (bukan pengganti): metrik tata letak, lihat bawah |
| AC-14 | e2e AC-14: modal Pindahkan A, pilih M, klik judul, scan `0QA125-T` | PASS | tepat 1 GET search (dari picker); Cari picker = `0QA125-T`; tabel modal = respons; halaman utuh dan Cari halaman tetap `''`; judul tetap; Pindahkan masih aktif (pilihan M bertahan); 0 navigasi; 0 POST. `AC14-01-sesudah-scan-di-modal.png`. Baseline: GAGAL (tabel halaman berubah) |
| AC-15 | e2e AC-15: massal [M, M2], scan di Konfirmasi (Pilih Folder belum dibuka); Lanjut, pilih T, scan di Pilih Folder | PASS | Konfirmasi: 0 GET archives, halaman utuh, judul/langkah/item tetap. Pilih Folder: tepat 1 GET search, Cari picker berisi kode, tabel = respons, judul dan pilihan T tetap, halaman utuh. Baseline: GAGAL (1 GET dari tabel halaman). Catatan O-2 di bawah |
| AC-16 | e2e AC-16: tutup lewat Batal, ✕, sukses (single dan massal), tiap kali scan di halaman | PASS | 6 skenario: tiap kali tepat 1 GET search `{"query":"<kode>"}` 200, Cari halaman berisi kode, jumlah baris halaman = respons, kode tampil; modal sudah tidak ada (picker tidak ikut mendengar); sukses single (M → T) dan massal ([M, M2] → T) 200, DB dipulihkan (M, M2, T identik); 0 navigasi per segmen. `AC16-*.png` |
| AC-17 | e2e AC-17: folder A → "+" → Letakkan Dokumen, scan `0QA125-DOC17`; tutup, scan | PASS | langkah review memuat kode; 0 GET archives saat modal terbuka; halaman utuh; sesudah modal ditutup tepat 1 GET search dan Cari halaman = kode; 0 POST. `AC17-02-review-berisi-kode.png`. Catatan O-1 |

### AC-13 - bukti pendukung (bukan asersi tampilan)

Test "AC-13 pendukung" mencatat kotak elemen (x, y, w, h) dan gaya terhitung (font-size, line-height, font-family, margin, padding) 10 selektor (`.ant-modal-content`, `-header`, `-body`, `.ant-steps`, `.search-bar`, `.breadcrumbs`, `.ant-table-thead`, `.ant-table-row`, `.ant-pagination`, tombol primer) di 5 keadaan: modal Pindahkan, + popover filter, massal Konfirmasi, massal Pilih Folder, Pilih Folder 390 px. Dijalankan di build baseline (`...-20261014-185139/`) dan build perbaikan (`...-185200/`); dibandingkan lewat skrip. Hasil: **nol selisih pada semua kotak dan gaya**; satu-satunya beda = pembungkus `<form class="ant-form ant-form-vertical">` hilang (jumlah `form` di modal 2 → 1, massal Konfirmasi 1 → 0). Ini mendukung perkiraan dev bahwa `component={false}` tidak mengubah tampilan, tetapi penilaian visual dan scanner USB asli tetap di Gate 2. Screenshot: `AC13p-01..05-*.png`.

## Defect

Tidak ada.

## Galeri e2e & regresi BE

Galeri kanonik ED-1125: `/var/www/opname-archive/agentic/work/e2e/ED-1125-archive-fe-cari-modal-pindah-20261014-185200/index.html` (14 OK, `report.json` `errors: []`, 52 screenshot di `screenshots/default/`, termasuk 390 px).

**AC-12 regresi e2e** (semua `--workers 1`, fixture sendiri-sendiri, teardown "tanpa sisa"):

| Item | Test | Hasil | Galeri (`work/e2e/...`) |
|---|---|---|---|
| ED-1025 | AC-3 A, AC-3 C, AC-40 A (id), AC-40 A (en) [default]; AC-21 B [user2] | 5 OK | `ED-1025-archive-folder-permission-20261014-180137` |
| ED-1027 | AC-15 (modal Pindahkan folder/dokumen + Bulk Move, kolom Document Verified) | 1 OK | `ED-1027-archive-status-verifikasi-20261014-180353` |
| ED-1072 | AC-4, AC-5 | 2 OK | `ED-1072-archive-fe-klik-menu-baris-20261014-180409` |
| ED-1150 | AC-6, AC-7, AC-8 sebagai `sam` (`run-sam.sh`; prasyarat data `--prereq` OK sebelumnya) | 3 OK | `ED-1150-archive-fe-virtualtable-list-pendek-20261014-180618` |
| ED-1026 | GAMMA (scan opname: ketik, USB di luar input, kamera) | 1 OK | `ED-1026-archive-opname-document-20261014-180443` |
| ED-1028 | AC-22 (scan saat drawer riwayat terbuka, sesudah ✕ / modal ditutup) | 1 OK | `ED-1028-archive-opname-history-20261014-180528` |
| ED-1069 | seluruh spec (AC-11) | 5 OK | `ED-1069-archive-validasi-pindah-rename-folder-20261014-175934` |

**Jest:**

| Perintah | Hasil |
|---|---|
| `yarn test --watchAll=false --runInBand src/containers/Archive` | 14 suite, **476/476** (`work/ED-1125-jest-archive.txt`) |
| `yarn test --watchAll=false` (full, paralel, mesin berbeban load 6-11) | 82 suite: 66 hijau, 16 gagal; 1768 hijau, 29 gagal (`work/ED-1125-jest-full.txt`) |
| ulang 16 suite itu `--runInBand` (`work/ED-1125-jest-rerun-failed.txt`) | 12 suite hijau (timeout beban: ArchivePage, ArchivePermissionPage, ArchiveTable, ArchiveBulkMoveModal, ArchiveRestrictedIcon, Raptor*, MasterLayoutAvatarMenu, ItemRaptorImport); tetap gagal 4 suite / 4 tes: `BatchOut` (1), `production.function` `getRecipeTotals` (2), `InterbankTransferForm` (1), `CustomerPurchaseOrderView` (gagal dimuat). Pra-ada: tidak ada yang menyebut `Archive` (grep kosong), seluruh berkas M/?? item ada di `src/containers/Archive` + `docs`, dan keempatnya sama dengan yang dicatat QA ED-1072/ED-1150 |

**Regresi BE** (`"$PHP_BIN" scripts/qa-http/run.php <KEY>`, `work/ED-1125-be-<KEY>.log`; BE tidak diubah item ini): ED-1024 26/0, ED-1025 40/0, ED-1026 39/0, ED-1027 28/0 (+1 SKIP X-3), ED-1028 24/0, ED-1029 40/0 (+1 SKIP X-ISO), ED-1069 16/0, ED-1071 16/0, ED-1124 10/0, ED-1150 1/0 = **240 PASS, 0 FAIL, 2 SKIP** (isolasi tenant butuh 2 DB; `QA_DBS` hanya `api_sidomaju`), 0 BLOCKED. Karakterisasi 500 yang tercatat = perilaku diterima/ditunda (A-1 PUT identik sedetik; ED-1070 sorts bentuk array), bukan temuan. Tidak ada selisih yang perlu dikaitkan ke ED-1124 (karakterisasi ED-1069 sudah dibalik di a654b1c).

## Yang tidak bisa diverifikasi (dan kenapa)

Tidak ada AC `not_verifiable`. Di luar jangkauan otomatis (masuk Gate 2 / AC-13, daftar tes UI 026 butir 1-13):

- Scanner USB asli (keystroke fisik) dan scan kamera di modal: e2e mensimulasikan scanner dengan `keyboard.type` tanpa jeda + Enter (jeda < 30 ms, `useBarcodeScanner` tidak diubah); kamera tidak dijalankan.
- Penilaian tampilan: hanya metrik tata letak yang dibandingkan (nol selisih); mata developer tetap perlu (AC-13, butir 12).
- Mutasi balik per fix: dikerjakan dev (tercatat 026 `Catatan`, 2/6/2 test gagal); QA tidak mengulang, diganti kontrol negatif e2e terhadap build baseline.

## Pengamatan di luar item (bukan defect, tidak mengubah verdict)

- **O-1 (pra-ada, rendah) `ArchiveDocumentModal/index.js:20-24`**: `onCloseAdd` selalu `setActiveScanner(true)`, juga saat ✕/Kembali di langkah review hanya mengembalikan modal ke langkah scanner (modal tetap terbuka). Scan sesudahnya lalu juga mencari di tabel halaman di belakang modal (diamati e2e AC-17: GET search `0QA125-DOC17B` sementara modal masih terbuka). Item ini tidak mengubah modal itu (BR-9, AC-17 = regresi); kandidat tiket terpisah bila developer mau.
- **O-2 (sudah dicatat dev di 026, sesuai desain)**: di pindah massal, sesudah Pilih Folder pernah dibuka lalu Sebelum ke Konfirmasi, scan diterima picker tersembunyi (panel rc-tabs tetap ter-mount): diamati 1 GET search (`0QA125-F`); halaman, judul, langkah dan item tetap utuh (dinilai di AC-15). Efek lihat: Cari picker sudah berisi kode saat Lanjut lagi. AC-15 hanya mensyaratkan kasus Pilih Folder belum dibuka.
- **O-3 (pra-ada, `components/Search`, di luar cakupan BR-6)**: `Search.onInputClick` memfokus ulang (`cursor: 'all'`) 100 ms sesudah klik kolom Cari; ketikan pertama dalam 100 ms tertimpa. Ditemukan saat menulis e2e (`0QA` hilang); skrip diberi jeda 350 ms. Tidak mengganggu pemakaian normal; tanpa tindakan.

## Data uji, kebersihan, sesi

- Tulisan DB hanya `archives` `0QA125-*` (A, B, C, T, M, M2, F, dokumen D6 = 8 baris + 1 `archive_locations` untuk B), dibuat `fixture.php up` dan dibuang persis `fixture.php down` (jurnal id + awalan); **8 run** (pengembangan, kontrol negatif, kanonik) semuanya "tanpa sisa". Alur pindah sukses (AC-6, AC-16) dipulihkan `fixture.php restore` dan dibandingkan baris-per-baris. Teardown tidak pernah jalan sesudah setup gagal (tidak ada setup gagal). Fixture suite regresi memakai fixture dan teardown mereka sendiri (semua "tanpa sisa"/"baseline").
- Pemeriksaan akhir (`qa/scenario-teardown.php`, baca saja, `--only=CLEAN-1,CLEAN-2,SESI-1`): CLEAN-1 PASS (0 baris `0QA125-%` di `archives` dan `archive_documents`), CLEAN-2 PASS (bahasa QA_USER = `ID`, tidak diubah QA; ED-1025 AC-40 en memulihkannya lewat teardown), SESI-1 PASS (token aktif QA_USER 0, QA_USER2 0, QA_USER3/`sam` 0).
- Snapshot read-only ED-1149: `snapshot.php ED-1125-after` dibanding `ED-1124-r2-after` = **SAMA** (roles, role_permissions, user_roles, permissions, archives*, archive_opname*, column_display_settings, updater_logs, employees; beda yang diizinkan hanya `users.updated_at`); `--leftover` = "tidak ada sisa QA". Tidak ada tabel dikosongkan atau di-reset; data developer tidak ditulis; tidak ada baris yang bukan buatan fixture dihapus.
- Sesi: QA_USER (default) dan QA_USER2 (ED-1025 `user2`) dan `sam` (ED-1150: prasyarat + run) semuanya di-log-out harness (`log-out ... dicabut=ya` di tiap log, 7x `1/1`, 1x `2/2`, sam 1x). `force_login=1` dipakai `sam` di prasyarat ED-1150 dan mencabut satu sesi `sam` lain (diizinkan K-4 a / DU-4 a). `api_sidomaju` dan `sam` tidak dipakai di luar itu.
- Scratch: build baseline `/tmp/ksp-react-v5-build-base-ed1125` dan salinan `git archive` dihapus; `build/` developer dan port 4000 tidak disentuh; tidak ada proses lain yang dimatikan. Penolakan izin/classifier: tidak ada.

## Berkas QA (di `features/ED-1125-archive-fe-cari-modal-pindah/qa/`)

`e2e/ED-1125-cari-modal-pindah.spec.js` (13 test), `e2e/fixture.php`, `e2e/fixtures.json`, `e2e/routes.json`, `scenario-teardown.php` (bukan regresi BE; bukti pembersihan). Disunting di luar folder ini: `features/ED-1069-archive-validasi-pindah-rename-folder/qa/e2e/ED-1069-validasi-pindah.spec.js` (AC-11; `qa-fe.md` ED-1069 tidak diedit, ED-1149 BR-7). Log: `work/ED-1125-e2e-final2.log`, `...-e2e-negctl.log`, `...-e2e-reg-*.log`, `...-be-*.log`, `...-jest-*.txt`, `...-build-baseline.log`.

## Riwayat ronde

- Ronde 1: PASS. 16 AC PASS, AC-13 MANUAL, 0 defect; kontrol negatif baseline 9 GAGAL / 4 OK; regresi AC-12 + BE hijau; 3 pengamatan di luar item (O-1..O-3). Selama penulisan e2e ada 5 perbaikan skrip QA sendiri (jeda sesudah klik kolom Cari, baris panel tersembunyi pindah massal, penanda sesudah muat ulang, ✕ dua kali di Letakkan Dokumen, tombol Sebelum bukan paginasi); bukan defect FE.
