---
key: ED-1150
scope: fe
round: 1
verdict: PASS
counts: {pass: 9, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---

## Ringkasan

Ronde 1 = FE dengan fix (working tree: 6 berkas M + 2 baru, `changed.txt`; HEAD FE `d56ce3962`, BE tidak berubah). **Verdict PASS.**

- Prasyarat data (HTTP, sam = QA_USER3, `api_sidomaju`, baca-saja): terpenuhi. root 3 baris (Backup Arsip, CABANG - SEMARANG, Folder A); Folder A 2 baris (Folder B, Folder C);
  Folder B 398 dokumen, 10 baris/halaman, halaman 40 = 8 baris. Tidak ada AC BLOCKED-by-data. (`work/ed1150-r1-prereq.txt`)
- Build staging `scripts/fe-build.sh build`: exit 0 (90 dtk), ke `/tmp/ksp-react-v5-build`, bukan `build/`. Bukti build memuat fix: chunk `7649.107c8cdc.chunk.js` berisi
  `JSON.stringify([...??"root",...,page??1,pagination??null])` (= `getArchiveListKey`); `builtAt 03:28:23` lebih baru daripada mtime berkas ubahan terakhir (03:19:25).
  Peringatan harness "Build staging MUNGKIN USANG: 6 berkas FE belum di-commit" hanya heuristik berkas kotor; sudah dibuktikan di atas.
- Unit: Archive 13 suite / **461 tes hijau** (berurutan `--runInBand`); full suite hanya menyisakan 4 kegagalan pra-ada yang sudah tercatat (lihat bawah). Kegagalan lain di run paralel = timeout 5 dtk karena mesin
  berbeban (load average 5-8), semuanya hijau saat dijalankan sendiri.
- e2e sebagai sam: **9 test OK, 0 GAGAL, 0 SKIP**, dua kali berturut-turut (run 1 `...-20261014-034752`, ulang `...-20261014-035034`). Tiap run 39 langkah banding: **39/39 literal OK dan 39/39 terlihat OK**
  (baseline: 11/39 terlihat OK, lihat tabel). Folder B 10 baris kini **350/350** px di semua kunjungan (baseline 313/350).
- Sesi: sam login 2 kali (cek prasyarat + 2 run e2e = 3 login), semuanya di-log-out (`sessions.json`: `log_out 200, revoked true`); `SESI-1` PASS (0 token aktif QA_USER3 di `oauth_access_tokens`) dicek sesudah run 1 dan run 2.
  Tidak ada token lain yang dicabut (`notes: []`). Tidak ada sesi QA_USER. Tidak ada tulis DB, tidak ada fixture.

## Baseline (sebelum fix) - ringkas dari ronde 0

Bukti bug ada: FE `d56ce3962` bersih, run `work/e2e/ED-1150-archive-fe-virtualtable-list-pendek-20261014-020558/` (spec e2e yang sama, tidak diubah ronde ini), deterministik 3 run.
Jumlah `tr.ant-table-row` dan nama baris **sama dengan respons** di semua langkah (bunyi literal AC-1 lolos di baseline, N-1); yang rusak = **tinggi wadah VirtualTable** memakai tinggi daftar sebelumnya,
baris ada di DOM tetapi terpotong (kriteria `terlihat` GAGAL). Hasil baseline: AC-1, 2, 3, 4, 6, 7 GAGAL (terlihat), AC-5 dan AC-8 PASS.

Perbandingan AC-1 per langkah (respons / DOM / terlihat; wadah = `clientHeight/scrollHeight` px):

| AC-1 (tanpa scroll/reload) | Baseline respons/DOM/terlihat | Baseline wadah | **Ronde 1 respons/DOM/terlihat** | **Ronde 1 wadah** | literal / terlihat r1 |
|---|---|---|---|---|---|
| 1 root (muat awal) | 3 / 3 / 3 | 111/111 | 3 / 3 / 3 | 111/111 | OK / OK |
| 2 klik Folder A | 2 / 2 / 2 | 74/74 | 2 / 2 / 2 | 74/74 | OK / OK |
| 3 klik Folder B | 10 / 10 / **9** | **313/350** | 10 / 10 / 10 | 350/350 | OK / OK |
| 4 breadcrumb Folder A | 2 / 2 / **1** (Folder C hilang) | **37/74** | 2 / 2 / 2 (Folder B + Folder C) | 74/74 | OK / OK |
| 5 breadcrumb root | 3 / 3 / **2** (Folder A hilang) | **74/111** | 3 / 3 / 3 (Folder A ada) | 111/111 | OK / OK |

Baseline per AC yang gagal `terlihat` -> ronde 1: AC-2 12 dari 14 langkah gagal -> 14/14 OK; AC-3 4 dari 5 -> 5/5; AC-4 4 dari 5 -> 5/5; AC-6 2 dari 3 -> 3/3; AC-7 3 dari 5 -> 5/5; AC-5 dan AC-8 tetap OK.

## Hasil per AC

Pembanding e2e tiap langkah = `result.data` respons list (`GET document-archive/archives`) TERAKHIR yang ditangkap dari jaringan (bukan hardcode; id folder dari respons), diratakan seperti `flattenArchives`.
Run utama: `work/e2e/ED-1150-archive-fe-virtualtable-list-pendek-20261014-034752/` (log langkah `steps-ED-1150.log`, galeri `index.html`, `screenshots/sam/`). Run ulang: `...-20261014-035034/` (hasil identik, 39/39).

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | e2e AC-1: root -> A -> B -> crumb A -> crumb root, 5 langkah, tanpa scroll/reload; literal + terlihat | **PASS** | tabel di atas; `[AC1] langkah 1..5` semua `literal OK, terlihat OK`; `screenshots/sam/AC1-04-breadcrumb-Folder-A.png` (Folder B + Folder C), `AC1-05-breadcrumb-root.png` (Folder A), `AC1-03-Folder-B.png` (10 baris utuh) |
| AC-2 | e2e AC-2: 2 siklus (klik nama folder + breadcrumb) + varian 3->2->3->2(C, 10 baris)->2 = 14 langkah | **PASS** (bagian "folder ditolak View" SKIP sesuai spec, lihat bawah) | `[AC2] langkah 1..14` literal+terlihat OK (A 74/74, root 111/111, B/C 350/350); `[AC2] ... SKIP` |
| AC-3 | e2e AC-3 di Folder B: hal 1 -> 40 (8 baris) -> 1, ukuran 10 -> 20 -> 10 | **PASS** | `[AC3] langkah 1..5`: hal-40 8 baris 280/280, ukuran-20 DOM 20, wadah 600/700 (tervirtualisasi, `scroll.y` 600), ukuran-10 350/350 |
| AC-4 | e2e AC-4: dari Folder A cari nomor transaksi nyata (dari respons Folder B), reset, buka Folder A lagi | **PASS** (dengan catatan N-3) | `[AC4] langkah 3` hasil cari 1 baris = respons; `langkah 4` reset -> root 3 baris = respons; `langkah 5` Folder A 2 baris (Folder B, Folder C) utuh 74/74; `[AC4] pencarian ... idArchive=null` |
| AC-5 | e2e AC-5: Folder B ukuran 20, scroll ke bawah, tombol Reload; penanda `__ed1150` di `.ant-table-body` | **PASS** | `[AC5] sebelum Reload scrollTop=100, sesudah scrollTop=100, penanda elemen wadah tabel=wadah-sama`; baris = respons (DOM 20, 600/700) |
| AC-6 | e2e AC-6: Folder A -> mode Pilih -> centang Folder C -> klik Folder B -> centang dokumen -> modal Bulk Move -> tutup (X) -> crumb A | **PASS** | penghitung 1 -> 2; modal Konfirmasi 2 baris `Folder C` + `DO-SMR/2610/00029` (`AC6-modal-bulk-move.png`); kembali ke Folder A: 2 terpilih, Folder C tercentang, 78/78; tanpa simpan (assert `noWrites`) |
| AC-7 | e2e AC-7: modal Move (menu baris Backup Arsip), picker root -> A -> B -> crumb A -> crumb root; modal ditutup (X) | **PASS** | `[AC7] langkah 1..5` modal: 105/105, 70/70, 350/350, 70/70, 105/105, literal+terlihat OK. sam punya Move (varian QA_USER tidak perlu, tidak ada sesi QA_USER) |
| AC-8 | e2e AC-8 + smoke `routes.json` (`/archives`): layar, modal Move, modal Bulk Move (Konfirmasi dan Pilih Folder); konsol/page error/HTTP >= 400 dicek harness | **PASS** | `report.json`: `status passed`, 9 passed, `errors: []`; `AC8-*.png`, `smoke-_archives.png`. 0 HTTP 500 |
| AC-9 | unit `yarn test src/containers/Archive` (+ full suite) | **PASS** | lihat "Tes unit" di bawah: 461/461 berurutan. ArchivePage: folder + crumb root, halaman + ukuran halaman, pencarian + reset, Reload (kunci tetap), `run()` sebelum respons (kunci tetap, berubah begitu respons tiba), mode Pilih. Picker: folder/crumb, halaman, Reload, `run()` sebelum respons. `getArchiveListKey` 4 tes |
| AC-10 | MANUAL (Gate 2): developer mengulang skenario asli sebagai `sam` | **MANUAL** | Ada di `ksp-react/docs/specs/Archive/025-fix-table-remount-per-list.md` `## Daftar tes UI`, 13 butir; butir 1-5 = skenario asli (butir 4 = "kasus asli developer": breadcrumb Folder A -> Folder B dan Folder C utuh). Tidak ada temuan |

Semua flow non-mutasi: tiap test menjaga tidak ada request non-GET ke `/document-archive/` (`noWrites`), modal hanya ditutup lewat X.

## Tes unit

| Perintah | Hasil |
|---|---|
| `yarn test --watchAll=false src/containers/Archive` (paralel, mesin berbeban) | 13 suite: 10 hijau, 3 gagal (ArchiveDrawer 1 tes, ArchiveOpnameModal 2 tes, ArchiveTable picker 1 tes = timeout 5 dtk). 457/461 |
| ulang sendiri: ArchiveTable picker; ArchiveDrawer + ArchiveOpnameModal `--runInBand` | semua hijau (picker 4/4 dalam 1,7 dtk; 57/57) |
| `yarn test --watchAll=false --runInBand src/containers/Archive` | **13/13 suite, 461/461 tes hijau** (`work/ed1150-r1-unit-archive-inband.txt`) |
| `yarn test --watchAll=false` (full, 1 kali, 622 dtk) | 81 suite: 66 hijau, 15 gagal; 1731 hijau, 51 gagal dari 1782 (`work/ed1150-r1-unit-full.txt`) |
| ulang 8 suite Archive yang gagal di full run (berurutan) | hijau semua (bagian dari 13/13, 461/461 di atas) |
| ulang 3 suite Raptor yang gagal di full run (RaptorLog, RaptorSync, RaptorFailedPage) sendiri | hijau |
| ulang 4 suite pra-ada (BatchOut, production.function, InterbankTransferForm, CustomerPurchaseOrderView) sendiri | tetap gagal: BatchOut 1 tes, production.function 2 tes (`getRecipeTotals`), InterbankTransferForm 1 tes, CustomerPurchaseOrderView gagal dimuat (`electronHelper.js:48` `appBeforeClose` undefined). Tidak ada yang mengimpor berkas Archive (grep kosong). Pra-ada, bukan defect item ini |

Jadi full suite = 11 dari 15 suite gagal murni timeout beban (hijau saat sendiri) + 4 pra-ada yang sudah tercatat di run ini. Tidak ada kegagalan baru yang disebabkan ED-1150.

## Defect

Tidak ada defect.

## Catatan (bukan defect)

- **N-1 (AC-1/2/3/4/6/7, wording "jumlah `tr.ant-table-row` = respons"):** metrik literal itu lolos di baseline; e2e menilai juga `terlihat`. Terselesaikan di ronde ini (kedua kriteria OK). Usul pipeline di `run.md` tetap relevan (kalimat AC memuat kriteria terlihat).
- **N-3 / AC-4 (konfirmasi reviewer, bukan keputusan QA):** pencarian dari Folder A mengirim `idArchive: null` (cari di semua folder, perilaku lama, tidak diubah item ini), jadi tombol reset kembali ke **root** (3 baris), bukan Folder A (2 baris) seperti bunyi spec "reset pencarian -> isi Folder A". Dinilai: baris sesudah cari (1) = respons, sesudah reset (root 3) = respons, lalu membuka Folder A lagi = 2 baris (Folder B, Folder C) utuh. Ketidaksamaan kata-kata dicatat, bukan defect.
- **N-5 (Folder B 313/350 di baseline):** terjawab. Kini 350/350 di semua kunjungan (kunjungan pertama dari Folder A, kunjungan berulang, ukuran 10 kembali dari 20, picker). Bukan efek layout terpisah milik library: hilang bersama fix `key`. Kriteria terlihat untuk daftar 10 baris valid.
- **N-8 (cakupan unit picker):** tes picker `ArchiveTable/index.test.js` memeriksa folder/breadcrumb, halaman, Reload, dan `run()` sebelum respons; **ukuran halaman dan pencarian tidak diuji di picker** (hanya di `ArchivePage` + `getArchiveListKey`). Mekanismenya identik (`onSuccess(_, [params])` -> `getArchiveListKey(params)`), jadi AC-9 saya nilai PASS; usul ke dev FE menambah satu tes ukuran halaman/pencarian di picker bila mau menutup celahnya.
- **N-9 (risiko flake tes baru):** tes pertama picker ("root -> Folder A -> Folder B -> breadcrumb A -> breadcrumb root ...") 5 navigasi dalam batas default 5 dtk; gagal timeout dua kali pada run paralel berbeban (load 5-8), hijau saat sendiri (1,7 dtk) dan berurutan (suite 7,5 dtk). Usul: timeout per-tes 15-20 dtk. Tidak memblokir.
- **N-10:** klaim dev FE "tanpa `key={listKey}` 9 dari 10 tes remount gagal" tidak saya ulang (QA tidak mengubah kode aplikasi). Bukti efektivitas fix saya = e2e: spec yang sama gagal di baseline (11 dari 39 langkah OK) dan lolos 39/39 sesudah fix, dua kali.
- Review benar-salahnya diff (bukan gaya): `key` diisi dari parameter respons di `onSuccess` (`ArchivePage/index.js` `onSuccessList`, `ArchiveTable/index.js` `onSuccessList`), kunci awal = muat awal (tanpa remount pada respons pertama), Reload tetap kunci sama (AC-5 PASS di e2e), mode Pilih tidak ikut di-reset (AC-6 PASS). Tanpa perubahan kontrak/BE (`contract: null`).

## Galeri e2e & regresi BE

- Galeri run utama: `/var/www/opname-archive/agentic/work/e2e/ED-1150-archive-fe-virtualtable-list-pendek-20261014-034752/index.html` (46 screenshot, tidak ada berawalan `GAGAL-`).
- Run ulang (determinisme): `/var/www/opname-archive/agentic/work/e2e/ED-1150-archive-fe-virtualtable-list-pendek-20261014-035034/index.html`.
- Aset QA tidak diubah ronde ini: `qa/e2e/run-sam.sh`, `qa/e2e/ED-1150-virtualtable-list-pendek.spec.js`, `qa/e2e/routes.json`, `qa/e2e/prereq-sam.js`, `qa/scenario-sesi.php`. Kode harness bersama (`scripts/e2e/**`) tidak diubah.
- Regresi BE: item tanpa perubahan BE; satu-satunya scenario `qa/scenario-sesi.php` `SESI-1` **PASS** (2 asersi, token aktif sam = 0), dijalankan sesudah run e2e 1 dan sesudah run 2 (`work/ed1150-r1-sesi.txt`). Runner mencatat `Token run: 0 dibuat` (tidak ada login QA_USER). Regresi lintas item = ED-1149 (item berikutnya).
- Cara ulang: `qa/e2e/run-sam.sh > work/ed1150-<tanda>.txt 2>&1` sesudah `scripts/fe-build.sh build` (1 worker); data: `qa/e2e/run-sam.sh --prereq`.

## Yang tidak bisa diverifikasi (dan kenapa)

- **AC-2 bagian "folder ditolak View" (HTTP 403 `ARCHIVE407`, "Back to Archive"): SKIP sesuai spec ("Tidak ada folder seperti itu = bagian ini SKIP dengan catatan").** Tidak ada folder `access.view=false` untuk sam di root maupun Folder A (semuanya `view=true`), jadi tidak ada 403 untuk dibuka. Bagian navigasi AC-2 yang lain PASS. Tidak dibuat fixture (spec § Data uji melarang).
- Tidak ada AC lain yang tidak bisa diverifikasi. AC-10 = MANUAL Gate 2 (sudah ada di `## Daftar tes UI`).
- Sesi dan pemulihan: sam login 3 kali (1 cek prasyarat, 2 run e2e), masing-masing di-log-out (`log_out 200`, `revoked true`, harness juga: `log-out: 1/1 token dicabut`); DB baca-saja `SESI-1` = 0 token aktif sam. `force_login` tidak mencabut sesi sam lain (`notes: []` di ketiganya). Tidak ada baris DB ditulis atau dihapus, tidak ada fixture `QA1150-`; Folder A/B/C, lokasi dan role sam tidak disentuh.
- Penolakan izin/classifier: tidak ada.

## Riwayat ronde

- Ronde 0 (2026-10-14, baseline FE `d56ce3962`): bug terbukti lewat kriteria `terlihat` (AC-1/2/3/4/6/7 GAGAL, wadah VirtualTable memakai tinggi daftar sebelumnya), AC-5 dan AC-8 PASS, literal DOM = respons lolos (N-1). verdict FAIL = bug tereproduksi, bukan defect proses.
- Ronde 1 (2026-10-14, FE dengan fix): PASS. e2e 9/9 dua kali (39/39 literal dan terlihat), Archive 13 suite / 461 tes hijau, full suite hanya 4 kegagalan pra-ada, build OK, `SESI-1` PASS.
