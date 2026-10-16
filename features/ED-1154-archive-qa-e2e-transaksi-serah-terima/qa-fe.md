---
key: ED-1154
scope: fe
round: 5
verdict: PASS
counts: {pass: 16, fail: 0, manual: 0, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

Task QA murni (subtask ED-1155 [QA]). Diff BE/FE 0 berkas (`changed.txt` 0 baris). BE `5c4c69bcd` dan FE `41146943c` bersih
sebelum dan sesudah run. Ronde 5 mengikuti keputusan developer R4-K1 (spec § 8; `knowledge/decisions.md` A-9, A-10):
- A.5: w hanya membuat dan **Simpan** SA (status 4), tanpa Konfirmasi driver.
- A.6: i membuka drawer SA dan menekan "Konfirmasi Transaksi".
- A.9: SI lewat Tambah → Impor Pengantaran Pesanan → **Simpan** → **Konfirmasi** di drawer.
- AC-15: 0 respons 5xx **tanpa allow-list apa pun**.

**Hasil: PASS 16/16.** Run 6 `261016140921` adalah rantai baru (SO..RP, folder `E2E-ARSIP-261016140921`). Run ini lulus
dalam **satu pemanggilan harness tanpa resume**: 30 OK, 0 GAGAL, 1 SKIP (K-6: tidak ada attendance terbuka). Tiga kegagalan r4
dicek ulang lebih dulu di run itu, dan ketiganya sekarang lulus:
- AC-5: SA-SMR/2610/00033 disimpan w dengan status 4 dan `confirmed_by` null. Monitor w mencatat 0 panggilan PUT `…/confirm`.
  i lalu menekan Konfirmasi Transaksi (`PUT …/confirm` 200), dan SA menjadi 6 dengan `confirmed_by` imansudjadi.
- AC-7: SI disimpan dengan status 1 (`POST sales-invoices` 200), lalu dikonfirmasi lewat drawer (`PUT …/confirm` 200, Q).
  Hasilnya SI-SMR/2610/00024 status 2. Tercatat 0 panggilan `save-and-confirm`.
- AC-15: jumlah 5xx = 0 di 3 konteks. Allow-list kosong (`ALLOW = []`, allow-list global harness juga 0 entri), dan
  `allowed` = 0 di seluruh 31 test.

Semua titik A → B → C cocok dengan § 4, baik di DB (snapshot per langkah di jurnal; `skipped_asserts` kosong) maupun di layar Archive i.
Defect baru: 0. Temuan terhadap ED-1070: 0. Tidak ada BLOCKED.

Build: staging tidak dibangun ulang. FE tidak berubah sejak build staging `/tmp/ksp-react-v5-build` (2026-10-16T02:03:41Z, build r2
lulus `work/ED-1154/fe-build-r2.out` exit 0); build itu lebih baru dari commit FE 41146943c (2026-10-15T05:05Z), dan harness
melaporkan `stale: false`, `feDirty: []`. `build/` developer tidak disentuh. `yarn test`: n/a (`fe_modules: []`, diff FE kosong).
Regresi BE: n/a (fitur tanpa `qa/scenario*.php`).

## Hasil per AC

Bukti untuk semua baris di bawah:
- jurnal `work/ED-1154/runs/261016140921.json` (`snap` per langkah);
- galeri `work/e2e/ED-1154-archive-qa-e2e-transaksi-serah-terima-20261016-070910/` (78 screenshot, `report.json`);
- keluaran harness `work/ED-1154/r5/run-r5-1.out`.

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | `fixture.php preflight` (read-only) sebelum run + test AC-1 | **PASS** | `work/ED-1154/r5/preflight-r5-pre.json`: `ok: true`, 0 blocked, 0 warn. `use_approval_pin=0`. Role: i 3, a [5] (tanpa `QA2x-ROLE-*`), w 8 (`employee_type` 2). Attendance terbuka []. CST-10120 "BERKAT, TK." `approval_status=2`. i berada di level terakhir CUSTOMER (4/4) dan SALES_ORDER (2/2); level bawah `is_mandatory=0` dan `user_need=1`. Role 3 punya `Allow Approval As Customer`. GUDANG SMG busy 0. Item 019223 stok 259. Worker antrian hidup, `jobs` 0 |
| AC-2 | smoke `routes.json`, 15 route × profil pemakai | **PASS** | 15/15 OK (i 8, a 4, w 3), 0 error, 0 allow-list (`report.json` `errors` [], `allowed` 0) |
| AC-3 | test A.0, A.1-A.2 | **PASS** | A.0 dilewati karena CST-10120 sudah 2. SO-SMR/2610/00037: sesudah Simpan [4, 1, 0], lalu `transaction_status=1` tanpa klik Confirm, `approval_status=2`, `customer_approval_status=4`, salesman adi. `approval_transactions` = hanya [42804 i L2 status 1]. Arsip SO aktif 0. SP-SMR/2610/00010 status 2, driver wawan, tepat 1 DO (DO-SMR/2610/00048). Arsip DO: 1 baris, st 2, aktif, `imansudjadi`, [give i], root, sumber SO-SMR/2610/00037 (K-3). Screenshot `A1-approval-drawer.png`, `A2-archive-i.png` |
| AC-4 | test A.3 | **PASS** | Shipment 3 (`accepted_by` wawan). DO st 3, `wawan`, len 2, ekor receive w, sebelumnya give i. `A3-archive-i.png` |
| AC-5 | test A.4-A.7 (jalur A-9) | **PASS** | Attendance w …346609371642165: toko Buka, check-in 14:11:00, check-out 14:11:23. Snapshot A.5: SA-SMR/2610/00033 st 4, `created_by` wawan, `confirmed_by` null, `is_pay` 0, carrier [4]. `a5_w_sa_confirm_calls` []. A.6: menu i [Konfirmasi Transaksi, Batalkan Transaksi] → `PUT …/shipment-acceptances/{id}/confirm` **200** → SA **6**, `confirmed_by` imansudjadi, `is_pay` 0, carrier [7], `can_done` true. Sales Return 0, SI dari SA 0. DO tetap (3, wawan, len 2). Screenshot `wawan/A5-sa-saved-w.png` (toast "Sukses"), `default/A6-sa-menu-i.png`, `A6-sa-confirmed-i.png` |
| AC-6 | test A.8 | **PASS** | Shipment 5. DO st 3, `imansudjadi`, len 4, ekor receive i, sebelumnya give w. `A8-archive-i.png` |
| AC-7 | test A.9-A.10 (jalur A-10) | **PASS** | Menu Action [Salin Faktur Penjualan, Impor Pengantaran Pesanan]. Pelanggan terisi dari SO. Simpan → `POST sales-invoices` 200, SI st 1. Drawer Konfirmasi → `PUT …/sales-invoices/{id}/confirm` **200** (Q) → SI-SMR/2610/00024 **st 2**, salesman = salesman SO (Adi), belum ada baris arsip. `a9_si_save_and_confirm_calls` []. BILL-SMR/2610/00007 st 2 berisi tepat [SI-SMR/2610/00024]. Arsip SI: 1 baris, st 2, `imansudjadi`, [give i] (K-3). Arsip DO tidak berubah (3, len 4). Screenshot `A9-si-saved.png`, `A9-si-confirmed.png` (toast "Berhasil Dikonfirmasi"), `A10-archive-i.png` |
| AC-8 | test A.11 | **PASS** | Billing 6, `accepted_by` adi, `is_match` [1]. SI st 3, `adi`, len 2, ekor receive a, sebelumnya give i. `A11-archive-i.png` |
| AC-9 | test A.12-A.13 | **PASS** | Attendance a …347631118382758 (check-in 14:12:43). RP-SMR/2610/00024 `created_by` adi. i menekan "Setujui" (customer, `customer_approval_status` 4) lalu Konfirmasi → status 2, `processed_by` imansudjadi (K-2 b). SI tetap (3, adi, len 2) |
| AC-10 | test A.14 | **PASS** | Check-out a 14:13:24. Billing 7 (`updated_by` adi). SI st 2, `adi`, len 3, ekor give a (K-3). `A14-archive-i.png` |
| AC-11 | test A.15 | **PASS** | Billing 8, `completed_by` imansudjadi. SI st 3, `imansudjadi`, len 4, ekor receive i, sebelumnya give a. `A15-archive-i.png` |
| AC-12 | test B | **PASS** | F `E2E-ARSIP-261016140921` (id …348329599460706) di root, `is_all_location=1`. DO lalu SI di-Store: keduanya st 1, induk F, `imansudjadi`, len 5, ekor place i. Isi F = [DO-SMR/2610/00048, SI-SMR/2610/00024]. Root tidak lagi menampilkan keduanya. `B-isi-folder-F.png`, `B-DO-archive-i.png` |
| AC-13 | test C.2-C.7 | **PASS** | C.2: 2, i, len 6, give i. C.4: 3, a, len 7, receive a. C.5: 2, a, len 8, give a. C.7: 3, i, len 9, receive i. Induk F di semua titik. Modal Hand Over tanpa select penerima, body POST = `["name"]` (C.2, C.5). `C2/C4/C5/C7-archive-i.png` |
| AC-14 | test C.8 | **PASS** | DO st 1, `imansudjadi`, induk F, len 10, ekor `move` dari `E2E-ARSIP-261016140921` ke folder yang sama (K-4 a). `C8-archive-i.png` |
| AC-15 | test AC-15/16 + monitor + log-out + ED-1149 `--leftover` | **PASS** | Jurnal memuat SO/SP/DO/SA/SI/BILL/RP/F, attendance w+a, dan approval A.1 (customer SO + 42804) serta A.13 (customer RP). `archives_changed` = tepat {DO-SMR/2610/00048, SI-SMR/2610/00024, E2E-ARSIP-261016140921}. `http5xx` 0, `forbidden_paths` [] (A-9/A-10 tidak dilalui), `ALLOW` [], `allowed` 0 di 31 test. Monitor semua konteks: error 0. Log-out 3/3 `dicabut=ya`. `--leftover` "tidak ada sisa QA" exit 0, sebelum dan sesudah run |
| AC-16 | run 6 di DB yang sama dengan run 1-5 | **PASS** | Nomor baru (SO 00037, SP 00010, DO 00048, SA 00033, SI 00024, BILL 00007, RP 00024). `previous_before == previous_after` untuk 5 run lengkap sebelumnya (261016092317, 261016100813, 261016102000, 261016104228, 261016131002): arsip DO/SI, F, dan Billing tidak berubah |

## Defect

**Defect baru: tidak ada.** Tidak ada 5xx, tidak ada error monitor, dan tidak ada asersi yang gagal. A-9 dan A-10 adalah perilaku yang sudah
diterima. Jalurnya tidak dilalui (dibuktikan oleh `forbidden_paths` []), dan keduanya tidak dilaporkan. O-1 juga tidak dilaporkan.

**Temuan terhadap ED-1070: tidak ada.** Log BE selama run (`laravel-2026-10-16.log` baris 6106-6288, potongan di
`work/ED-1154/r5/be-log-window.txt`) hanya berisi 3 `OAuthServerException` pada 14:16:17, yaitu verifikasi pencabutan token saat
log-out. Selain itu: 0 QueryException, 0 baris `[CustomizeBuilder]`. Semua list/select alur 200, dan tidak ada 404.

**Perubahan alat uji r5** hanya di `qa/e2e/ED-1154-archive-transaksi.spec.js` (md5 `902e06e7…5b0f02`, 90,6 KB; salinan r4
`work/ED-1154/r5/spec-r4.js`). `fixture.php`, `routes.json` dan `checkin.jpg` tidak berubah.
- Allow-list dihapus seluruhnya, termasuk entri E-1 dan semua entri 500 r3/r4: `ALLOW = []`. Flag `ED1154_ALLOW_TICKETED`,
  `KNOWN_5XX`, jurnal `retry` dan `defects` juga dihapus. `count5xx` sekarang menghitung **setiap** 5xx sebagai gagal (di
  `Actors.finish` dan AC-15). Selain itu ada `FORBIDDEN`: setiap panggilan A-9 (PUT SA confirm oleh w) atau A-10 (POST
  `sales-invoices/save-and-confirm`) dicatat di jurnal `forbidden_paths`, dan AC-15 mensyaratkan jumlahnya 0.
- A.5 (BR-8): w hanya Simpan. Asersi snapshot A.5: SA [4, wawan, null], `is_pay` 0, 0 panggilan confirm oleh w.
  A.6: i membuka drawer SA (Butuh Persetujuan Admin) → Konfirmasi Transaksi. Syarat sebelum: SA = 4. Asersi A.7: SA 6,
  `created_by` w, `confirmed_by` i, `is_pay` 0, carrier [7], `can_done`, dan DO = (3, w, 2).
- A.9 (BR-14): langkah baru `A.9a` (Impor → Simpan, id SI dari respons) lalu `A.9` (Konfirmasi drawer, Q). Asersi: SI st 2 =
  SI dari A.9a, salesman = SO, belum ada arsip, 0 `save-and-confirm`.
- Tidak berubah: resume (`ED1154_RESUME`) + snapshot per langkah, jurnal, `clickReady`, monitor per konteks, dan langkah lain.

## Galeri e2e & regresi BE

| Pemanggilan | runId / jurnal | Jalannya (UTC) | Hasil | Galeri |
|---|---|---|---|---|
| `--list` | - (tanpa jaringan/login) | 07:08 | 31 test terdaftar | `work/ED-1154/r5/list-r5.out` |
| 1 (satu-satunya run) | `261016140921` baru, tanpa resume | 07:09:10-07:16:18 (14:09-14:16 WIB). Port 4100 bebas, tanpa harness lain, tanpa `force_login` | **30 OK, 0 GAGAL, 1 SKIP** (K-6), exit 0 | `work/e2e/ED-1154-archive-qa-e2e-transaksi-serah-terima-20261016-070910/index.html` |

**Dokumen dan perubahan DB permanen ronde 5** (dibiarkan sesuai G-3; dikenali dari jurnal `261016140921`):

| SO | Shipment | DO | SA | SI | Billing | RP | Folder F | attendance w / a |
|---|---|---|---|---|---|---|---|---|
| SO-SMR/2610/00037 | SP-SMR/2610/00010 | DO-SMR/2610/00048 | SA-SMR/2610/00033 | SI-SMR/2610/00024 | BILL-SMR/2610/00007 | RP-SMR/2610/00024 | E2E-ARSIP-261016140921 | …346609371642165 / …347631118382758 |

- Approval, semuanya oleh imansudjadi tanpa PIN:
  - persetujuan customer SO (`customer_approval_status=4`);
  - `approval_transactions` **42804** (SALES_ORDER L2 status 1);
  - persetujuan customer RP (A.13, `salesman-activity/receivable-payments/{id}/approve`).

  A.0 tidak dijalankan, dan CST-10120 tidak berubah (tetap 2).
- `archives`: baris DO-SMR/2610/00048 (akhir st 1 di F, history 10), baris SI-SMR/2610/00024 (st 1 di F, history 5), dan folder F.
  Tidak ada baris lain.
- Stok item 019223 turun dari 259 ke 258. Jurnal/GL/AR, nomor dokumen, dan `transaction_queues` transaksi (sudah diproses) adalah efek normal transaksi.
- Foto unggahan: 9 berkas (`attendancePhotos` 4, `shipmentAcceptancePhotos` 3, `receivablePaymentPhotos` 2) sesudah 07:09:10 UTC.
  Kesembilannya JPEG 640x480 24.245 B yang identik (`cmp`) dengan `checkin.jpg` (`work/ED-1154/r5/uploads.txt`).
- Sesi: 3 token dibuat lalu di-log-out, dan pencabutannya terverifikasi. Tidak ada `force_login`. Tidak ada yang dihapus atau dipurge.

Sesudah run (`work/ED-1154/r5/preflight-r5-after.json`): `ok: true`, 0 attendance terbuka, GUDANG SMG bebas (busy 0; `AA 9516 BA` busy
14 sudah begitu sebelum run), `jobs` 0, `use_approval_pin=0`. `--leftover` sesudah run: "tidak ada sisa QA"
(`work/ED-1154/r5/leftover-r5-after.out`).

Regresi BE: n/a (fitur tanpa `qa/scenario*.php`). MANUAL (Gate 2): tidak ada, karena semua AC dibuktikan otomatis.

## Yang tidak bisa diverifikasi (dan kenapa)

- Tidak ada. Tidak ada penolakan izin/classifier.
- Catatan: build staging FE tidak dibangun ulang di ronde ini (alasan dan bukti ada di Ringkasan).

## Riwayat ronde

- r1 (2026-10-16 00:01-00:05 WIB): BLOCKED di AC-1 (approval customer/SO), AC-2 PASS. Run `261016000149` dan `261016000404`, tanpa data.
- r2-r3 (09:04-10:55 WIB): r2 FAIL 13/3 (AC-5/7/15: dua 500, sekarang A-9/A-10) di 3 run; approval 42798 (CST-10120 0→2) + 42799..42801.
  r3 PASS 16/16 dengan allow-list 500 bertiket; run `261016104228`, approval 42802.
- r4 (13:09-13:22 WIB): FAIL 13/3, allow-list dimatikan, dua 500 direproduksi (driver Konfirmasi SA; SI Simpan & Konfirmasi
  sesudah Impor). Run 5 `261016131002` (1 pemanggilan + 2 resume), approval 42803. Diputuskan R4-K1 → A-9/A-10.
- r5 (14:08-14:17 WIB): **PASS 16/16**. Jalur A-9/A-10 dihindari, tanpa allow-list. Run 6 `261016140921` dalam satu pemanggilan tanpa resume, 0 5xx, approval 42804.
