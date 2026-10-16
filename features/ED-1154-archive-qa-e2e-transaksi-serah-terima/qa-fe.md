---
key: ED-1154
scope: fe
round: 3
verdict: PASS
counts: {pass: 16, fail: 0, manual: 0, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

Task QA murni (subtask ED-1155 [QA]); diff BE/FE 0 berkas (`changed.txt` kosong), BE `5c4c69bcd` (kode ED-1070, bersih), FE
`41146943c` (bersih). Ronde 3 = konfirmasi dengan alat uji final: ref allow-list/KNOWN_5XX diganti ke tiket Bug di luar task
(D-1 = **ED-1168**, D-2 = **ED-1169**, E-1 = **ED-1166**), AC-15 "0 5xx" kini menghitung hanya 5xx yang **tidak** bertiket, lalu
**run 4 `261016104228` sekali jalan, bersih** (tanpa resume): **30 OK, 0 GAGAL, 1 SKIP** (K-6, tidak ada attendance terbuka), exit 0.

Alur A → B → C (spec § 4) lengkap lewat UI; semua keadaan arsip DO/SI cocok dengan § 4 di DB (snapshot per langkah) dan layar
Archive. 5xx yang terlihat di run 4 = tepat 2, keduanya bertiket: `ED-1168 D-1 w 500 PUT …/shipment-acceptances/{id}/confirm`
(GE0114) dan `ED-1169 D-2 i 500 POST …/sales-invoices/save-and-confirm` (Unknown column `sales_invoice_serials`); 5xx tidak
bertiket = 0. E-1 (ED-1166) tidak muncul (A.0 dilewati, CST-10120 sudah `approval_status=2`). Defect aplikasi baru 0; temuan
terhadap ED-1070 0; tidak ada BLOCKED.

Catatan penilaian: aturan agent "verdict PASS hanya bila tidak ada 500" secara harfiah tidak terpenuhi (2 × 500 terlihat). Dinilai
PASS sesuai instruksi ronde ini dan preseden ED-1149 (500 dari defect bertiket di luar task = known, `qa-be.md` ED-1149 baris 67-69).
Bila developer menilai lain, AC-5/AC-7/AC-15 kembali FAIL dengan bukti yang sama.

Build: tidak dibangun ulang (tidak perlu): staging `/tmp/ksp-react-v5-build` 2026-10-16T02:03:41Z ≥ commit FE 41146943c, FE bersih,
harness `stale: false` (`report.json` → `staging`); `build/` developer tidak disentuh. `yarn test`: n/a (`fe_modules: []`, diff FE
0). Regresi BE: n/a (fitur tanpa `qa/scenario*.php`, tanpa AC `[BE]`). Desain `brief/`: hanya R-1..R-23 (tanpa layar), lewat § 4.

## Hasil per AC

Bukti = run 4 `261016104228`, jurnal `work/ED-1154/runs/261016104228.json` (`snap` = `fixture.php inspect` saat langkah selesai),
galeri `work/e2e/ED-1154-archive-qa-e2e-transaksi-serah-terima-20261016-034219/`, keluaran `work/ED-1154/run-r3-4.out`.

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | test AC-1 → `fixture.php preflight` (read-only); juga sebelum run `work/ED-1154/preflight-r3-pre.json` | **PASS** | `ok: true`, 0 blocked, 0 warn. `use_approval_pin=0`; CUSTOMER i L4/4, SALES_ORDER i L2/2 (level bawah `is_mandatory=0`, `user_need=1`); role 3 `Allow Approval As Customer`; i role 3, a role [5] (0 `QA2%-ROLE-%`), w role 8; attendance terbuka w/a = []; CST-10120 `approval_status=2`; carrier GUDANG SMG busy 0; item 019223 stok 261; worker antrian hidup, `jobs` 0 |
| AC-2 | smoke `routes.json` 15 route × profil pemakai | **PASS** | 15/15 OK, 0 error, 0 allow-list (i 8, a 4, w 3); `screenshots/*/smoke-*.png` |
| AC-3 | test A.0, A.1-A.2 | **PASS** | A.0 dilewati (CST-10120 sudah 2; `a0.skipped`). SO-SMR/2610/00035: `transaction_status=1` tanpa klik Confirm, `approval_status=2`, `customer_approval_status=4`, `approval_transactions` = hanya [42802 i L2 status 1], salesman adi; 0 arsip SO. SP-SMR/2610/00008 status 2, tepat 1 DO DO-SMR/2610/00046; arsip DO st 2, aktif, `imansudjadi`, history [give i], root (K-3). `A1-approval-drawer.png`, `A2-archive-i.png` |
| AC-4 | test A.3 | **PASS** | Shipment 3 (`accepted_by` wawan); DO st 3, `wawan`, len 2, ekor receive w; `A3-archive-i.png` |
| AC-5 | test A.4-A.7 | **PASS** (5xx bertiket ED-1168) | attendance w 250224000117921222508451766398: toko Buka (`store_status=1`), geotag customer, check-in 10:44:10, check-out 10:44:41; SA-SMR/2610/00031 `status 4/acceptance_status 2` lalu **6**, `is_pay=0`, `confirmed_by` imansudjadi; carrier tx DO status **7**, `can_done` true; Sales Return 0, SI dari SA 0; DO tetap (3 w len 2). Satu-satunya 5xx: `w 500 PUT …/shipment-acceptances/{id}/confirm` GE0114 = D-1 **ED-1168**; i menyelesaikan "Konfirmasi Transaksi" → 200 (`sa_admin_api`). `wawan/A5-sa-confirm-w-result.png` |
| AC-6 | test A.8 | **PASS** | Shipment 5; DO st 3, `imansudjadi`, len 4, ekor receive i, sebelumnya give w |
| AC-7 | test A.9-A.10 | **PASS** (5xx bertiket ED-1169) | Impor Pengantaran Pesanan (Pelanggan "CST-10120 BERKAT, TK." dari SO); SI-SMR/2610/00022 status 2, salesman Adi; BILL-SMR/2610/00005 status 2 berisi tepat [SI-SMR/2610/00022]; arsip SI st 2, `imansudjadi`, [give i] (K-3); arsip DO tidak berubah (3 i len 4). Satu-satunya 5xx: `i 500 POST …/sales-invoices/save-and-confirm` = D-2 **ED-1169** (log BE 10:45:12, tanpa data parsial); jalur layar lain Simpan + Konfirmasi drawer (`si_path`). `A9-si-save-and-confirm-500.png`, `A10-archive-i.png` |
| AC-8 | test A.11 | **PASS** | Billing 6, `accepted_by` adi, `is_match` [1]; SI st 3, `adi`, len 2, ekor receive a |
| AC-9 | test A.12-A.13 | **PASS** | attendance a 250224000117921223767130951723 (check-in 10:46:16); RP-SMR/2610/00022 `created_by` adi, i "Setujui" (customer) + Konfirmasi → status 2, `processed_by` imansudjadi (K-2 b); arsip SI tetap (3 a len 2) |
| AC-10 | test A.14 | **PASS** | check-out a 10:46:50; Billing 7 (`updated_by` adi); SI st 2, `adi`, len 3, ekor give a (K-3) |
| AC-11 | test A.15 | **PASS** | Billing 8, `completed_by` imansudjadi; SI st 3, `imansudjadi`, len 4, ekor receive i |
| AC-12 | test B | **PASS** | F `E2E-ARSIP-261016104228` (id …224367681906608) root, `is_all_location=1`; DO lalu SI di-Store → keduanya st 1, induk F, `imansudjadi`, len 5, ekor place i; isi F = [DO, SI]; `B-isi-folder-F.png` |
| AC-13 | test C.2-C.7 | **PASS** | C.2 DO 2 i len 6 give i; C.4 3 a len 7 receive a; C.5 2 a len 8 give a; C.7 3 i len 9 receive i; induk F di semua titik; body POST hand-over = `["name"]` (C.2, C.5), tanpa pilihan penerima; label layar cocok |
| AC-14 | test C.8 | **PASS** | DO st 1, `imansudjadi`, induk F, len 10, ekor `move` F→F (`E2E-ARSIP-261016104228` → sama, K-4 a); layar "Dipindahkan oleh imansudjadi" (`C8-archive-i.png`) |
| AC-15 | test AC-15/16 + monitor + log-out + ED-1149 `--leftover` | **PASS** (5xx bertiket ED-1168, ED-1169) | jurnal memuat id+nomor SO/Shipment/DO/SA/SI/Billing/RP/F, attendance w+a, approval A.1 (customer + SALES_ORDER 42802) + A.13 (RP customer); A.0 tidak perlu. `archives_changed` = tepat {DO-SMR/2610/00046, SI-SMR/2610/00022, E2E-ARSIP-261016104228}. 5xx total 2 = `http5xx_known` [ED-1168 D-1, ED-1169 D-2], `http5xx_unknown` = [] (asersi baru lulus). Monitor semua konteks: error tak di-allow 0 (`[i]/[w]/[a] … error 0` di notes); allow-list terpakai hanya ED1154-D2-* (report.json) dan D-1 di konteks w. Log-out 3/3 `dicabut=ya`; `--leftover` "tidak ada sisa QA" exit 0 sebelum dan sesudah (`work/ED-1154/leftover-r3-{before,after}.out`) |
| AC-16 | run 4 pada DB yang sama dengan run 1-3 | **PASS** | nomor baru (SO 00035, SP 00008, DO 00046, …); `previous_before == previous_after` untuk 3 run sebelumnya (261016092317, 261016100813, 261016102000: DO/SI st 1 di F masing-masing, Billing 8, `updated_at` tidak berubah) |

## Defect

**Defect baru: tidak ada.**

Defect aplikasi bertiket (di luar task, tidak diperbaiki di sini; direproduksi lagi di run 4, rantai lanjut lewat jalur layar lain):

| ID | Tiket | Langkah | Respons run 4 | Status di alat uji |
|---|---|---|---|---|
| D-1 | ED-1168 | A.5 w "Konfirmasi Transaksi" SA | 500 `GE0114` "Anda tidak memiliki izin untuk konfirmasi Penerimaan Pengiriman!" (+ `pageerror: Error: Object`) | ALLOW `ED1154-D1-*` (profil wawan) + KNOWN_5XX D-1 |
| D-2 | ED-1169 | A.9 i "Simpan & Konfirmasi" SI hasil impor DO | 500 SQLSTATE[42S22] Unknown column `sales_invoice_serials` (+ `Error: Object`) | ALLOW `ED1154-D2-*` (profil default, `/sales/sales-invoices`) + KNOWN_5XX D-2 |
| E-1 | ED-1166 | A.0 drawer Pelanggan, foto customer tidak ada di storage lokal | tidak muncul di run 4 (A.0 dilewati) | ALLOW `ED1154-E1-foto-customer-lokal` (requestfailed ORB, path `/setups/customers/customers`) |

Dugaan lokasi (dari r2, tidak berubah): D-1 `ksp-erp Modules/V5/Http/Requests/Sale/ShipmentAcceptance/ConfirmRequest.php:26-32`
(ErrorMessageException tanpa kode HTTP → default 500, `app/Exceptions/ErrorMessageException.php:32`) + FE
`ShipmentAcceptanceDrawerAction/index.js:54-60`; D-2 `QueueService.php:329` (`persistDraftForConfirm` memakai request mentah) vs
`SalesInvoiceController.php:51` (`validated()`), FE `salesInvoice.function.js:353`.

**Temuan terhadap ED-1070: tidak ada.** Log BE selama run 4 (`laravel-2026-10-16.log` baris 5126-5400): 1 QueryException (D-2,
10:45:12), 3 `OAuthServerException` 10:50:03-04 (verifikasi log-out harness), 0 baris `[CustomizeBuilder]`; tidak ada 404/route
hilang; semua list/select alur 200.

**Perubahan alat uji r3** (`qa/e2e/ED-1154-archive-transaksi.spec.js`, md5 `9e93a39b…7906` = berkas yang dijalankan run 4;
`fixture.php`, `routes.json`, `checkin.jpg` tidak berubah sejak r2):
- `ALLOW` (baris 53-102): `ref` E-1 → ED-1166, `ED1154-D1-*` → ED-1168, `ED1154-D2-*` → ED-1169; `match`/`kind`/`status`/
  `profiles`/`paths` tidak diubah; alasan dipertahankan, hanya klausa "tetap dihitung di AC-15" diganti "5xx bertiket (KNOWN_5XX),
  dicatat, tidak dihitung di 0 5xx" (klausa lama tidak lagi benar).
- `KNOWN_5XX` (110-130) dipersempit: aktor + method + path + status 500 **dan** badan respons yang ditangkap langkahnya untuk URL
  yang sama (`RUN.defects`: D-1 `code === 'GE0114'`, D-2 pesan `sales_invoice_serials`), plus `ref`. Tanpa entri KNOWN_5XX E-1
  (E-1 tercatat sebagai requestfailed, bukan respons 5xx; tidak diperluas).
- `count5xx()` (132-151): semua 5xx → `http5xx_list`/`http5xx`; bertiket → `http5xx_known` {id, ref, line}; lainnya →
  `http5xx_unknown`. Dipanggil di `Actors.finish()`, di `afterEach` (test tanpa Actors: AC-1, A.0, test gagal) dan awal AC-15.
- AC-15 (1560-1564): `expect.soft(http5xx_unknown).toEqual([])` + konsistensi total = bertiket + tidak bertiket; note mencantumkan
  setiap 5xx bertiket dengan tiketnya.

## Galeri e2e & regresi BE

| Run | runId / jurnal | Jalannya | Hasil | Galeri |
|---|---|---|---|---|
| 4 (r3) | `261016104228` | **bersih, sekali jalan**, 03:42:19-03:50:04 UTC; port 4100 bebas + tidak ada harness lain sebelum mulai; sesi tanpa `force_login` (notes kosong) | 30 OK, 0 GAGAL, 1 SKIP (K-6); exit 0; 76 screenshot | `work/e2e/ED-1154-archive-qa-e2e-transaksi-serah-terima-20261016-034219/index.html` |

Dokumen dibuat run 4 (dibiarkan, G-3):

| SO | Shipment | DO | SA | SI | Billing | RP | Folder F | attendance w / a |
|---|---|---|---|---|---|---|---|---|
| SO-SMR/2610/00035 | SP-SMR/2610/00008 | DO-SMR/2610/00046 | SA-SMR/2610/00031 | SI-SMR/2610/00022 | BILL-SMR/2610/00005 | RP-SMR/2610/00022 | E2E-ARSIP-261016104228 | …222508451766398 / …223767130951723 |

Approval run 4 (semua oleh imansudjadi, tanpa PIN): A.1 persetujuan customer SO (`salesman-activity` approve →
`customer_approval_status=4`) + `approval_transactions` **42802** SALES_ORDER L2 status 1; A.13 persetujuan customer RP
(`salesman-activity/receivable-payments/{id}/approve`). A.0 tidak dijalankan. Stok item 019223 261 → 260. Sesudah run
(`work/ED-1154/preflight-r3-after.json`): 0 attendance terbuka, GUDANG SMG bebas, `jobs` 0 (baris antrian JOG status 4 2026-10-05 =
catatan spec § 9). Foto unggahan: 9 berkas run 4 (`attendancePhotos` 4, `shipmentAcceptancePhotos` 3, `receivablePaymentPhotos` 2,
`storage/app/attachments/*/2026-10-16/*_202610161044*|1046*`) semuanya JPEG 24.245 B identik (`cmp`) dengan `checkin.jpg`.

Regresi BE: n/a (fitur tanpa `qa/scenario*.php`).

## Yang tidak bisa diverifikasi (dan kenapa)

- Tidak ada. Jalur spec A.9 (`save-and-confirm`) dan konfirmasi SA oleh w tetap gagal karena ED-1169/ED-1168; hasil sesudahnya
  diverifikasi lewat jalur layar lain (jurnal `si_path`, `sa_admin_api`).
- E-1 (ED-1166) tidak teramati di run 4 karena A.0 dilewati; entri allow-list-nya tidak teruji ulang ronde ini (terakhir terpakai
  run 1 r2).
- Penilaian "PASS dengan 5xx bertiket" bergantung pada aturan preseden (lihat Ringkasan), bukan pada aturan harfiah "tidak ada 500".

## Riwayat ronde

- r1 (2026-10-16 00:01-00:05 WIB): BLOCKED AC-1 (X-1 customer belum approve, X-2 approval SO), AC-2 PASS, 2 run tanpa data
  (`261016000149`, `261016000404`). Jawaban developer: R1-K1 c, R1-K2 (O-1 dibiarkan), R1-K3 c (PIN approval dimatikan).
- r2 (2026-10-16 09:04-10:30 WIB): FAIL — A→B→C lengkap di 3 run (`261016092317` debug+resume, `261016100813` 1× resume,
  `261016102000` bersih), 13 PASS, 3 FAIL (AC-5/AC-7/AC-15 karena D-1/D-2 500). Dibuat: SO-SMR/2610/00032..00034, SP 00005..00007,
  DO 00043..00045, SA 00028..00030, SI 00019..00021, BILL 00002..00004, RP 00019..00021, folder E2E-ARSIP-{261016092317,
  261016100813,261016102000}; approval 42798 CUSTOMER CST-10120 L4 (A.0, 0→2, permanen) + 42799/42800/42801 SALES_ORDER L2; stok
  264 → 261. Sesudah r2: D-1 → ED-1168, D-2 → ED-1169, E-1 = ED-1166.
- r3 (2026-10-16 10:40-10:55 WIB): PASS — ref allow-list/KNOWN_5XX ke tiket, AC-15 hitung hanya 5xx tak bertiket; run 4
  `261016104228` bersih 30 OK / 0 GAGAL / 1 SKIP; 16 PASS (AC-5/AC-7/AC-15 dengan 5xx bertiket ED-1168/ED-1169).
