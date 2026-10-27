---
key: ED-1293
scope: fe
round: 1
verdict: PASS
counts: {pass: 11, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

QA FE ronde 1 untuk ED-1293 (filter Verification Status kembali ke root, halaman + picker; FE saja, `contract: null`). AC-1..AC-9 lulus lewat e2e
Playwright dan unit; AC-10 (regresi) lulus untuk lingkup item ini; AC-11 lulus (BE tidak disentuh); AC-12 MANUAL (Gate 2) dan ada di
`## Daftar tes UI` task 029. Tidak ada defect, tidak ada HTTP 5xx, tidak ada galat konsol / page error / API >= 400 di luar 403 `ARCHIVE407`
yang memang diharapkan (folder `QA50-PRIV`).

Satu catatan jujur untuk AC-10 "full suite": 4 suite di luar Archive gagal (Production `getRecipeTotals`, `InterbankTransferForm` tanggal
default, `Item/BatchOut` qty batch, `CustomerPurchaseOrderView` gagal impor). Keempatnya gagal **sama persis** pada ekspor bersih `HEAD`
`004018d12` (tanpa perubahan item ini), jadi bukan akibat ED-1293. Dicatat sebagai temuan di luar item (bagian "Temuan di luar item"), bukan
defect item. Orkestrator / developer yang memutuskan apakah ini cukup untuk AC-10.

Build staging `scripts/fe-build.sh build`: exit 0, "Compiled successfully" (69,6 dtk), dibangun 10:59 sesudah semua berkas sumber diubah
(terakhir 09:19). Peringatan harness "build mungkin usang: 6 berkas FE belum di-commit" tidak berlaku (build lebih baru dari berkas); perilaku
baru terbukti di build itu (AC-1: Cari dari folder menghasilkan request root).

## Suite e2e item ini (spec § 6a)

Folder `qa/e2e/` (disalin dari ED-1250, folder ED-1250 / ED-1251 tidak disunting):
- `fixture.php`, `fixtures.json`, `routes.json`: salinan, prefix `QA50-`, preset `QA50-ROLE-E2E-NOOD`, jurnal sendiri
  (`qa/e2e/.fixture-journal.json`, dihapus sesudah `down` bersih). Hanya komentar header, nama fixture dan ref yang diubah (isi logika sama).
- `ED-1293-filter-verifikasi.spec.js`: 19 test (17 `[default]`, 2 `[user2]`), tag judul "ED-1250 ..." = salinan yang tidak berbalik,
  "ED-1293 ..." = diubah / baru. Helper baru: `fetchAllRows` (fetch dalam sesi browser, `pagination=500`, untuk oracle "semua halaman" root
  berisi 3.532 dokumen; token tidak dicetak, `calls.mute` agar tidak dihitung sebagai request FE), `filterThenOpen` (filter aktif di dalam folder
  lewat jalur ED-1293: terapkan di root lalu buka folder), `atRoot`.
- Asersi ED-1250 yang dibalik (spec § 5) diubah, bukan dihapus: AC-14 bagian folder A (jadi AC-1), AC-15 `:872,:874,:881` (jadi AC-2 + AC-4),
  AC-16 `:909` (jadi AC-6), AC-18 `:1093,:1215,:1259,:1290` (jadi AC-7 dua test), AC-22 `:1413,:1453,:1464,:1518` (setup lewat root;
  ganti nilai = root). Regresi SRT / SRT + VS (`:970-1037`) tidak berbalik dan tidak diubah.
- Penjalanan: pengembangan memakai `fixture.php up/down` manual + `E2E_FIXTURES=0` (hanya untuk iterasi); bukti akhir semuanya lewat harness
  dengan fixture aktif (`up` / `down` oleh `fixtures.json`).

## Hasil per AC

Run e2e akhir: `work/e2e/ED-1293-archive-fe-verification-status-ke-root-20261027-111530/` (21 OK: 2 smoke + 19 flow, 0 GAGAL, errors 0,
allow-list 0). Test AC-2 + AC-4 dijalankan ulang sendiri sesudah satu asersi scan ditambah (root + breadcrumb "Arsip"):
`work/e2e/ED-1293-archive-fe-verification-status-ke-root-20261027-112501/` (OK).

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | e2e "ED-1293 AC-1": di `QA50-A` tanpa filter, Unverified -> Cari; lalu dari `QA50-MIX-SUB` (level 3) Verified. Cek: 1 GET, `search` persis `{verificationStatus}`, tanpa `id_archive`, `page=1`, respons `breadcrumbs` `[]`, breadcrumb "Arsip", tombol "+" sama dengan root, indikator + ✕ Reset menyala, tabel = respons, popover menampilkan nilai, V/T folder + ringkasan sama dengan tanpa filter. Semua halaman (fetch pagination 500): total = oracle `root-counts`, himpunan + urutan 15 folder root = tanpa filter, tidak ada baris folder lain (`AU1`, `MIX-U1`, `BU01` ... tidak muncul = bukan datar), dokumen hanya status itu, `QA50-RDU` ada / `QA50-RDV` tidak (Unverified) dan sebaliknya (Verified). Unit: ArchivePage "di dalam folder: Terverifikasi -> kembali ke root ..." | PASS | catatan run: "A -> Unverified: 3547 baris root (15 folder) pada semua halaman" (= 15 + 3532 oracle); "MIX-SUB -> Verified: 16 baris root (15 folder)" (= 15 + 1). Screenshot `screenshots/default/ac1-01-dari-A-unverified-root.png`, `ac1-02-dari-level3-verified-root.png` (dilihat: breadcrumb "Arsip", ikon filter biru, ✕ muncul). 27 dtk |
| AC-2 | e2e "ED-1293 AC-2 + AC-4": Unverified diterapkan di dalam `QA50-A` (= root), lalu buka A, MIX, breadcrumb A, breadcrumb "Arsip", `QA50-PRIV` (403 `ARCHIVE407`) -> "Kembali ke Arsip". Tiap langkah: `search` persis `{verificationStatus: UNVERIFIED}`, `id_archive` = folder (root = tanpa), indikator menyala, popover = "Belum terverifikasi", filter lain (Name) kosong; isi A dan MIX = semua subfolder + dokumen cocok (oracle `fixture.php children`); permintaan ke PRIV membawa key | PASS | report 111530 test "ED-1293 AC-2 + AC-4" passed (73 dtk); `ac15-01..03` screenshots; 403 PRIV dikeluarkan hanya lewat `expectDenied403` (2 entri: respons + log axios), bukan allow-list |
| AC-3 | e2e "ED-1293 AC-3": filter aktif hasil navigasi di A; (1) ganti nilai -> 1 GET root, `page=1`, total = oracle; (2) buka A lagi, Enter di kolom cari kosong **tanpa membuka popover** (popover dicek tertutup sebelum dan sesudah klik) -> 1 GET `{verificationStatus}` dari store, root, filter tidak hilang; (3) `QA50-B` halaman 2 (13 baris, 2 halaman) -> ganti nilai -> 1 GET `page=1`, root. Unit: ArchivePage "filter aktif hasil navigasi: Enter di kolom cari kosong tanpa membuka popover -> root; ganti nilai di folder -> root halaman 1" | PASS | report 111530 passed (20 dtk); `ac3-01..03` |
| AC-4 | e2e "ED-1293 AC-2 + AC-4" (bagian akhir): filter aktif hasil navigasi di A (terapkan di root, buka A): kosongkan select + Cari -> 1 GET tanpa parameter `search`, `id_archive` A, isi = anak A (total 12 = oracle), indikator mati; Reset ✕ di A -> tanpa `search`, tetap A; scan QR (`QA50-AU1`) -> `search` hanya `{query}`, tanpa `id_archive`, breadcrumb "Arsip", select kosong, menemukan `AU1`. Unit: ArchivePage + helper K-1 a | PASS | report 111530 + rerun 112501 passed; `ac15-04-scan-qr` |
| AC-5 | e2e "ED-1293 AC-5": di A: SRT saja -> tetap A; + Verified -> 1 GET kedua key, `id_archive` A, isi = oracle, dokumen verified; centang dilepas (`false`) + Verified -> 1 GET root, `search` persis `{showRelatedTransaction:false, verificationStatus:VERIFIED}`, `page=1`; muat ulang bersih, filter hasil navigasi di A + centang -> tetap A, kedua key. Regresi SRT sendiri ("ED-1250 regresi", tidak berbalik) lulus. Unit: helper K-2 b / K-3 a, ArchivePage "bersama Tampilkan Transaksi Terkait" | PASS | report 111530 passed (22 dtk + regresi 16 dtk); `ac5-01..03` |
| AC-6 | e2e "ED-1293 AC-6": di A, Verified + Name `QA50-MIX` sekaligus -> `id_archive` null, hasil = `MIX`, `MIX-SUB`, `MIX-V1`; lalu Unverified aktif di A (navigasi) + Name -> datar (folder cocok + dokumen unverified cocok nama, semua level); buka folder dari hasil -> `search` hanya `{verificationStatus}`, Name kosong; Type + filter -> kedua key, `id_archive` null | PASS | report 111530 passed (21 dtk); `ac6-00..`, `ac16-01..03` |
| AC-7 | e2e "ED-1293 AC-7 picker Pindahkan" (⋯ dokumen `MVD-U`) dan "AC-7 picker Pilih Folder" (mode Pilih, `MVD-V`): picker dibuka kosong walau filter halaman aktif (GET root tanpa search); di folder `QA50-C` picker Unverified -> 1 GET tanpa `id_archive`, `page=1`, breadcrumb picker "Arsip", semua folder root, breadcrumb dan request halaman tidak berubah; buka folder / breadcrumb picker membawa key; ganti nilai di folder -> root; kosongkan select (K-1 a), Reset, + SRT (K-2 b) -> tetap di C; + Name -> datar; `PRIV` dan folder terpilih (`MVF`) tidak bisa dibuka (0 request); pilihan tujuan `DEST` tidak hilang; Pindahkan ke `QA50-DEST` sukses (POST put-in 200, body = id + tujuan, SQL `id_archive_parent` = DEST, lalu `restore` + SQL pulih ke C). Unit ArchiveTable: "di Folder A -> kembali ke root picker", "ganti nilai di Folder A -> root picker", K-1 a, K-2 b, + Nama datar | PASS | report 111530 passed (93 dtk + 46 dtk); `ac18-*`, `ac7-01-picker-k1a-k2b-tetap-di-C`; unit `qa1293-unit-table.log` 13/13 |
| AC-8 | Unit `archive.function.test.js` (describe `getArchiveFilterRequest`, 7 kasus § 5: VS sendiri apa pun -> root; VS + SRT true -> tetap; VS + SRT false -> root; SRT sendiri -> tetap; key lain -> datar; kosong + hanya kedua key -> tetap; kosong lainnya -> root) + 4 test store form (field belum ter-mount, Enter kosong store terisi, store + SRT, select dikosongkan) + `getArchiveNavigationSearch` (`{verificationStatus}` / `null`). `ArchivePage` dan `ArchiveTable` sama-sama memanggil `getArchiveFilterRequest(values, {activeSearch: params?.search, form})`. Bermakna: dengan kode lama `HEAD` + berkas test baru, 11 test gagal / 321 lulus (3 suite) | PASS | `work/qa1293-unit-verbose.log` (2 suite, 319/319); `work/qa1293-unit-table.log` (13/13); `work/qa1293-unit-newtests-oldcode.log` ("Tests: 11 failed, 321 passed"); `grep getArchiveFilterRequest` di `ArchivePage/index.js` dan `ArchiveTable/index.js` |
| AC-9 | e2e: Archive root, folder, hasil cari ("ED-1250 AC-13 (id)" + AC-2 + AC-4), layar akses ditolak (PRIV), kedua picker (AC-7 x2), drawer Display Setting (AC-13 id / en, AC-20), `routes.json` smoke `[default]` + `[user2]`. Galat konsol / page error / API >= 400 / 5xx dicek otomatis tiap test (`monitor.check`) + `noServerError` | PASS | report 111530: `errors: []`, `allowed: 0` di semua 21 test, status passed; smoke `/archives` OK dua profil |
| AC-10 | (a) e2e ED-1125 (default): 18 OK; (b) e2e ED-1069 (default): 5 OK; (c) unit ED-1150 `getArchiveListKey` (4 test) + remount ArchivePage (6 test) + remount ArchiveTable (4 test) hijau; (d) salinan ED-1250 yang tidak berbalik hijau di suite ini: AC-13 id / en, AC-14 bagian root, regresi SRT, AC-21, AC-22 x3 (setup diubah per § 5), AC-20, AC-25 default, `[user2]` AC-19 dan AC-25; (e) full suite FE sekali | PASS (a-d); (e) catatan di bawah | (a) `work/e2e/ED-1125-...-20261027-114103/` "Total: 18 OK, 0 GAGAL"; (b) `work/e2e/ED-1069-...-20261027-114724/` "Total: 5 OK, 0 GAGAL"; (c) `qa1293-unit-verbose.log`, `qa1293-unit-table.log`; (d) report 111530; (e) `qa1293-unit-full.log`: 85 suite, 72 lulus; `qa1293-unit-rerun.log` dan `qa1293-unit-baseline-head.log` (lihat bawah) |
| AC-11 | `git -C <BE_DIR> diff --name-status 8d996000d` | PASS | keluaran kosong; `git rev-parse HEAD` = `8d996000d16ca73408c1f1d682d5d1b0223cc8a1`, `git status --short` kosong, `git log 8d996000d..HEAD` kosong; branch `v5-opname-archive` |
| AC-12 | Cek keberadaan di `## Daftar tes UI` | MANUAL (Gate 2) | `docs/specs/Archive/029-enh-filter-verification-status-ke-root.md` `## Daftar tes UI` butir 1-16; butir 16 = "Bandingkan alur Verification Status dengan filter Nama / Tipe dari dalam folder (AC-12)", butir 1-15 memuat AC-1..AC-9 |

### Full suite dan suite Archive (AC-10 e)

- Putaran paralel penuh: 85 suite, 13 gagal (18 test), 1.846 lulus; 16 dari 18 kegagalan = "Exceeded timeout of 5000 ms" (beban mesin, 628 dtk).
  Termasuk 3 suite Archive (`ArchivePage`, `ArchiveBulkMoveModal`, `ArchiveTable`). Putaran Archive saja sebelumnya: 17 suite, 542 / 543 lulus,
  satu timeout 5000 ms di `ArchiveTable` ("bersama Nama dari Folder A").
- Ulang sendiri (`--runInBand`, 11 jalur): ketiga suite Archive lulus; suite Raptor (6), `MasterLayoutAvatarMenu` lulus. Tersisa 4 suite gagal
  (lihat bawah). Jadi kegagalan Archive = timeout beban, bukan defect (ArchiveTable alone 13/13, ArchivePage alone lulus).
- 4 suite tersisa gagal juga pada ekspor bersih `HEAD` `004018d12` (`git archive` ke folder sementara, `node_modules` ditautkan, folder dihapus
  sesudahnya): `Production/production.function.test.js` (`getRecipeTotals` 2 test, 700 vs 800), `InterbankTransfer/InterbankTransferForm`
  ("tanggal transaksi default hari ini"), `Item/BatchOut` ("kelengkapan batch" qty), `CustomerPurchaseOrder/CustomerPurchaseOrderView`
  (`electronEvent` undefined saat impor). Hasil identik: "Test Suites: 4 failed, 4 total; Tests: 4 failed, 33 passed".

## Defect

Tidak ada.

## Temuan di luar item (bukan defect ED-1293)

- F-1: 4 suite FE di atas gagal di `HEAD` tanpa perubahan item ini (modul Production, InterbankTransfer, Item/BatchOut, CustomerPurchaseOrder).
  Perlu keputusan developer (tiket terpisah atau diterima); tidak memblokir ED-1293.
- F-2: Test Jest default 5000 ms rawan timeout saat full suite paralel (ArchivePage, ArchiveTable, ArchiveBulkMoveModal gagal di putaran paralel,
  lulus sendiri). Bukan defect fitur; relevan bila CI memakai paralel penuh.
- F-3: Catatan review dev (komentar `_onFinishFilter` tanpa K-3 a, `_module.md:191`, `_api.md:1485`) tidak diperiksa QA ini (bukan AC).

## Galeri e2e & regresi BE

- Galeri akhir: `/var/www/opname-archive/agentic/work/e2e/ED-1293-archive-fe-verification-status-ke-root-20261027-111530/index.html`
  (rerun AC-2 + AC-4: `.../ED-1293-archive-fe-verification-status-ke-root-20261027-112501/index.html`).
- Regresi: ED-1125 `.../ED-1125-archive-fe-cari-modal-pindah-20261027-114103/`, ED-1069 `.../ED-1069-archive-validasi-pindah-rename-folder-20261027-114724/`.
- Tidak dijalankan sesuai instruksi: e2e ED-1150 (login `sam`) dan suite e2e ED-1250 asli.
- BE regresi: item ini **tidak punya `qa/scenario*.php`** (FE saja, kontrak null); BE tidak disentuh (AC-11). Tidak ada run `qa-http` baru.
  Tidak ada HTTP 500 terlihat di seluruh run e2e.

## Data uji: pemulihan (jurnal)

Semua lewat jurnal fixture; tidak ada tabel dikosongkan / di-reset. `fixture.php down` (oleh harness, juga manual sebelum run akhir) melaporkan
"tanpa sisa, pemulihan persis" dan "C-8 OK: cds documentArchive memuat verificationStatus + documentVerified". Yang dibuat dan dibuang:
- 60 baris `archives` berawalan `QA50-` (+ `archive_documents`, `archive_locations`, `archive_permissions`-nya): dibuang.
- Preset `QA50-ROLE-E2E-NOOD` (+ `role_permissions`): dibuang; `user_roles` QA_USER2 (1 baris dari jurnal) dipulihkan.
- Bahasa QA_USER (ID) diubah ke EN hanya di test AC-13 (en), dipulihkan (`lang-restore`); kini ID.
- Baris cds `documentArchive` id 2586 (`updated_at 2026-10-26 12:04:16`): disimpan lalu dipulihkan persis (test AC-20 + teardown).
- Perpindahan (`MVD-U`, `MVD-V`, `MVF`, `D6`) dipulihkan `fixture.php restore` + asersi SQL di test.
- ED-1125 (8 baris `0QA125-`) dan ED-1069 (16 baris `0QA69E-`): dibuang oleh teardown masing-masing, "tanpa sisa".
- Pemeriksaan independen baca-saja sesudah semua run (skrip sementara di scratchpad, lewat Laravel boot QA): `QA50-%` 0, `0QA125-%` / `0QA69E-%` 0,
  `roles QA%-ROLE-%` 0 (ED-1154 P-1 aman), `archives` 49.170 dan `archive_documents` 49.138, `roles` 27, `role_permissions` 3.799, `user_roles` 126,
  `column_display_settings` 364, 1 baris cds `documentArchive` id 2586 `updated_at` lama, `QA_USER` bahasa ID. Semua sama dengan nilai
  "before" di jurnal `up`. Tidak ada `.fixture-journal.json` / `.fixture-ids.json` tersisa di folder ED-1293, ED-1125, ED-1069.
- Semua sesi QA di-log-out (harness: "token dicabut" tiap run). Folder ED-1250 / ED-1251 tidak disunting.

## Yang tidak bisa diverifikasi (dan kenapa)

Tidak ada. AC-12 MANUAL (Gate 2) karena menilai alur oleh manusia; daftar tesnya ada di dok 029.

## Riwayat ronde

- Ronde 1 (2026-10-27): QA FE pertama. Verdict PASS, 11 PASS + 1 MANUAL, 0 defect; 4 suite FE pra-ada gagal (identik di HEAD) dicatat sebagai temuan di luar item.
