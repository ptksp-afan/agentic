---
key: ED-1154
epic: ED-1022
type: task
title: "[QA] Archive - tes end-to-end pembentukan dokumen archive dan serah-terima lewat transaksi nyata"
status: approved
batch: ED-1154
fe_modules: []
new_fe_modules: []
be_modules: []
qa_model: opus                # stok/piutang, 3 user, antrian
contract: null
depends_on: []
---

# ED-1154 - E2E archive lewat transaksi nyata (A), store (B), serah-terima manual (C)

## 1. Ringkasan

Task QA murni: satu tes Playwright di `api_sidomaju` sebagai `imansudjadi` (i), `wawan` (w), `adi` (a) - transaksi SO→Billing (A),
DO+SI ke folder baru (B), serah-terima manual DO (C), tiap titik dicek terhadap § 4 (telusur kode; **10 keadaan developer cocok**, +3
antara, K-3). Data dibiarkan (G-3), dikenali dari jurnal run. Revisi 2026-10-16: approval lewat UI (BR-13), jalur layar yang jalan (BR-8, BR-14).

## 2. Aturan bisnis (aturan alat uji)

| BR | Aturan | Sumber |
|---|---|---|
| BR-1 | A→B→C dalam satu `describe.serial`; B/C memakai DO/SI run itu; ganti user = konteks browser per profil | R-1..R-23 |
| BR-2 | Status 1 = di folder, 2 = Handed Over ("Diserahkan"), 3 = Taken ("Dibawa"). "Oleh X" = `status` + `history` terakhir (`give`/`receive`, `related_username` X) + `updated_by` X | `Archive.php:49-51`; `DocumentService.php:91-137`; `DocumentArchiveService.php:14-32` |
| BR-3 | Confirm SO/SI tidak membuat arsip; DO lahir di confirm Shipment admin, SI di confirm Billing admin. Print PDF membuat baris `-1`: tes **tidak mencetak** | `ShipmentService.php:286-299,897-924`; `BillingService.php:145-167,893-920`; `pdf()` SO/DO/SI |
| BR-4 | Hand Over tanpa penerima (body hanya `name`); yang Receive berikutnya jadi pemegang (G-8 selesai dari kode) | `HandOverRequest.php:19-20,42` |
| BR-5 | Hand Over/Receive tidak mengubah induk: DO tetap di folder F selama C.2-C.7 (G-9 selesai dari kode) | `DocumentService.php:91-137` |
| BR-6 | `CST-10120` "BERKAT, TK." (bukan "...(KALIURANG)"), `approval_status=2` agar muncul di select SO (A.0). Semua transaksi di **Semarang** (satu-satunya lokasi a); item ber-stok Gudang Semarang, qty 1; carrier dari select Shipment, kapasitas cukup | G-4/G-5; DB `customers`, `employee_locations`; `SelectShipmentService.php:25-50` |
| BR-7 | 1 SO → 1 Shipment → 1 DO → 1 SI. Billing **hanya** berisi SI run itu (confirm/close memindahkan arsip semua SI-nya) | `ShipmentService.php:823-893`; `BillingService.php:893-920` |
| BR-8 | SA penuh, tanpa retur/bayar (retur → Sales Return, bayar → SI otomatis); toko Buka saat check-in. A.5: w membuat + **Simpan** SA saja (status 4), tanpa Konfirmasi driver (A-9). A.6: i Konfirmasi Transaksi di drawer SA → 6, carrier 7 (`can_done`) | `ShipmentAcceptanceService.php:103-133,277-340`; SA `ConfirmRequest.php:26-32`; `AttendanceService.php:64-69`; `ShipmentService.php:806-821` |
| BR-9 | Close billing: a = request close (7), i = close (8). Accept a: semua transaksi *match* (tidak match = Request Revision + `give`) | G-6; `BillingService.php:113-140,359-391` |
| BR-10 | Folder F di root, **semua lokasi** (selain itu 400 `ARCHIVE403`), nama `E2E-ARSIP-<waktu>` (K-5). "Store Document" di F, modal dibuka ulang per dokumen (tanpa "Kembali", ED-1153); scan = ketik + Enter | `LocationRule.php`; FE `getArchiveAddKeys` |
| BR-11 | Langkah Q: polling status transaksi (bukan `transaction_queues`) maks 180 s, lalu BLOCKED + bukti (transaksi, antrian, `jobs`) | `QueueService.php:68-160` |
| BR-12 | Tiap titik dicek di DB (`fixture.php inspect`) **dan** layar Archive i | ED-1027 `qa/e2e` |
| BR-13 | Approval (`APPROVAL_TRANSACTION` + `SALESMAN_ACTIVITY`). A.0, bila `CST-10120` `approval_status≠2`: i approve customer di drawer Pelanggan (L4; L1-L3 tidak wajib, dilewati). A.1: i Simpan SO, lalu di drawer SO (1) Setujui sebagai customer, (2) approve L2 (L1 dilewati); SO terkonfirmasi otomatis. Persetujuan customer berlaku untuk **setiap** SO (default kolom 1; X-3). Tanpa PIN (R1-K3 c); semua approval masuk jurnal | `ApprovalService.php:192-194,347-350,959-985,1047-1062,1342-1346`; `SalesmanActivity/SalesOrderService.php:198-202,300-320`; `SelectSalesService.php:90-92`; FE `SalesOrderDrawer/index.js:173-181` |
| BR-14 | Pengiriman: Tambah = **Simpan**, lalu Konfirmasi di drawer (Q) (S-1). SI: Tambah → **Impor Pengantaran Pesanan** (DO run; customer dari SO, tanpa filter approval) → **Simpan** → **Konfirmasi** di drawer (Q); bukan "Simpan & Konfirmasi" (A-10) | FE `shipment.function.js:525-541`, `salesInvoice.function.js:288-303`; `SelectSalesService.php:829-842` |

## 3. Model data

Tidak ada perubahan.

## 4. Endpoint dan keadaan arsip menurut kode

Tidak ada endpoint baru/berubah. Q = antrian; prefiks `api/v5/`, S = `sales/`, DA = `document-archive/documents/`; "+give w" = entri
`history` baru. Layar per profil: `qa/e2e/routes.json` (+ i `/setups/customers/customers` untuk A.0).

| Langkah | U | Endpoint (layar) | Arsip menurut kode | Developer |
|---|---|---|---|---|
| A.0 | i | `setting/approvals/approve` `{module:CUSTOMER}` (bila perlu) | - | - |
| A.1 | i | S`sales-orders` (Simpan); `salesman-activity/sales-orders/{id}/approve`; `setting/approvals/approve` `{module:SALES_ORDER}` → confirm otomatis | tidak ada baris | - |
| A.2 | i | S`shipments` (Simpan); S`shipments/{id}/confirm` Q | **DO baru** 2, `is_active=1`, i, [give i], root, sumber SO (`ShipmentService:294`) | - (K-3) |
| A.3 | w | S`shipments/{id}/confirm` Q | DO 3, w, +receive w (`:270-285`) | 3 w dari i ✓ |
| A.4-7 | w,i | `salesman-activity/attendances/check-in`, `check-out`; w S`shipment-acceptances` (Simpan); i S`shipment-acceptances/{id}/confirm` | tidak berubah | - |
| A.8 | i | S`shipments/{id}/done` | DO 3, i, +give w, +receive i (`:350-378`) | 3 i ✓ |
| A.9 | i | S`sales-invoices` (Simpan); S`sales-invoices/{id}/confirm` Q | SI belum ada baris | - |
| A.10 | i | S`billings/save-and-confirm` | **SI baru** 2, `is_active=1`, i, [give i] (`BillingService:145-167`) | - (K-3) |
| A.11 | a | S`billings/{id}/confirm` | SI 3, a, +receive a (`:105-140`) | 3 a ✓ |
| A.12-13 | a | check-in; `financial/receivable-payments` (K-2) | tidak berubah | - |
| A.14 | a | check-out; S`billings/{id}/done` | SI 2, a, +give a (`:365-371`) | - (K-3) |
| A.15 | i | S`billings/{id}/done` | SI 3, i, +receive i (`:372-380`) | 3 i ✓ |
| B | i | `document-archive/archives/create-folder`; DA`put-in {name, id_archive_parent F}` ×2 | DO, SI: 1, induk F, i, +place i (`DocumentService:17-89`) | 1 ✓ |
| C.2 | i | DA`hand-over {name}` | DO 2, i, +give i, induk F | 2 i ✓ |
| C.4 | a | DA`receive {name}` | DO 3, a, +receive a | 3 a ✓ |
| C.5 | a | DA`hand-over` | DO 2, a, +give a | 2 a ✓ |
| C.7 | i | DA`receive` | DO 3, i, +receive i | 3 i ✓ |
| C.8 | i | DA`put-in {name, F}` | DO 1, i, +**move F→F** (`DocumentService:72-77`) | 1 ✓ (K-4) |

Panjang `history` DO: 1, 2, 4, 5, 6-10; SI: 1-5. Permission role 3/5/8 cukup **kecuali** `Confirm Receivable Payment` role 5 (K-2).

## 5. Perubahan FE per module

Tidak ada.

## 6. Acceptance criteria

e2e `scripts/e2e/run.sh <KEY> --profile default,user2,wawan --workers 1` + DB read-only. "= § 4" = status, `is_active`, `updated_by`,
ekor `history`, induk, label layar.

| AC | Given / When / Then | Tag | BR |
|---|---|---|---|
| AC-1 | Prasyarat sebelum A.0: role a hanya 5 (tanpa `QA2x-ROLE-*`); w/a tanpa attendance terbuka (K-6); `CST-10120` ada; ada carrier + item ber-stok di Semarang; antrian hidup; i di level **terakhir** CUSTOMER dan SALES_ORDER, level bawah `is_mandatory=0`, `user_need=1`; role 3 punya `Allow Approval As Customer`; `use_approval_pin=0`. Gagal → BLOCKED + bukti, tanpa approval/transaksi | [BE+FE] | 6, 11 |
| AC-2 | Layar `routes.json` terbuka tanpa error untuk profil pemakainya | [FE] | 1 |
| AC-3 | A.0-A.2 → `CST-10120` `approval_status=2`; SO `customer_approval_status=4`, `approval_status=2`, `approval_transactions` i hanya L2, `transaction_status=1` tanpa klik Confirm; Shipment 2, tepat 1 DO; arsip DO = § 4 A.2; tidak ada arsip SO `is_active>0` | [BE+FE] | 3, 7 |
| AC-4 | A.3 → Shipment 3; DO = § 4 A.3 (R-4) | [BE+FE] | 2 |
| AC-5 | A.4-A.7 → attendance w in/out; SA oleh w status 4 (tanpa Konfirmasi w); i Konfirmasi → SA 6, `confirmed_by` i, `is_pay=0`; carrier 7, `can_done`; tanpa Sales Return/SI dari SA; DO tetap (3, w) | [BE+FE] | 8 |
| AC-6 | A.8 → Shipment 5; DO = § 4 A.8 (R-9) | [BE+FE] | 2 |
| AC-7 | A.9-A.10 → Impor Pengantaran Pesanan → Simpan → Konfirmasi drawer: SI status 2 (dari DO, salesman a); Billing 2 hanya SI itu; SI = § 4 A.10; arsip lain tetap | [BE+FE] | 3, 7 |
| AC-8 | A.11 → Billing 6; SI = § 4 A.11 (R-12) | [BE+FE] | 9 |
| AC-9 | A.12-A.13 → attendance a; a membuat RP atas SI, i approve + confirm (K-2 b); SI tetap | [BE+FE] | 2 |
| AC-10 | A.14 → Billing 7; SI = § 4 A.14 | [BE+FE] | 9 |
| AC-11 | A.15 → Billing 8; SI = § 4 A.15 (R-16) | [BE+FE] | 9 |
| AC-12 | B → F di root; DO lalu SI di-Store → = § 4 B; F berisi 2 dokumen, root tidak lagi (R-17, R-18) | [BE+FE] | 10 |
| AC-13 | C.2, C.4, C.5, C.7 → DO = § 4; induk tetap F; Hand Over tanpa pilihan penerima (R-19..R-22) | [BE+FE] | 4, 5 |
| AC-14 | C.8 → DO 1, i, induk F (R-23); karakterisasi K-4 a: ekor history = `move` F→F oleh i | [BE+FE] | 2 |
| AC-15 | Jurnal `work/<KEY>/runs/<runId>.json` = id+nomor semua dokumen run, approval (A.0/A.1), attendance, F. `archives` yang berubah selama run = tepat {DO, SI, F}. 0 5xx tanpa allow-list (jalur A-9/A-10 tidak dilalui); monitor bersih di semua konteks; token di-log-out; ED-1149 `snapshot.php --leftover` = 0 | [BE+FE] | 12 |
| AC-16 | Run ke-2 di DB sama lulus (nomor baru); arsip + Billing run ke-1 (jurnal) tidak berubah | [BE+FE] | 7 |

## 7. Subtask

| Jira | Judul | Layer | AC | Module |
|---|---|---|---|---|
| ED-1155 | [QA] Skenario e2e archive lewat transaksi nyata (A/B/C) + jurnal run | QA | AC-1..AC-16 | `qa/e2e/` (spec, `routes.json`, `fixture.php` read-only, foto) |

## 8. Keputusan untuk developer

Gate 1 (2026-10-14), blocker QA FE r1 dan temuan r4 (2026-10-16). Semua terjawab.

| K | Pertanyaan | Jawaban | Akibat di spec |
|---|---|---|---|
| K-1 | Login e2e wawan | Dijawab: a (2026-10-14): `QA_WAWAN_USER`/`QA_WAWAN_PASS` | profil `wawan`; adi = `user2` |
| K-2 | Role 5 tanpa `Confirm Receivable Payment` | Dijawab: b (2026-10-14) | a membuat RP, i approve + confirm; AC-9 |
| K-3 | Keadaan antara A.2, A.10, A.14 | Dijawab: a (2026-10-14) | karakterisasi; AC-3, AC-7, AC-10 |
| K-4 | C.8 riwayat "Dipindahkan dari F ke F" (`DocumentService.php:72-77`) | Dijawab: a (2026-10-14), tanpa tiket | AC-14 karakterisasi; bila kelak `place`, asersi dibalik, bukan regresi |
| K-5 | Nama folder B vs `snapshot.php:70` | Dijawab: a (2026-10-14) | `E2E-ARSIP-<waktu>`; BR-10 |
| K-6 | Attendance w/a terbuka sisa run mati (`CHECK403`) | Dijawab: a (2026-10-14) | dari jurnal: check-out lewat UI; lainnya BLOCKED |
| DU-5 | Lingkungan run | Dijawab: a (2026-10-14) | `force_login` boleh; run sendirian; Q lewat batas = BLOCKED + bukti (BR-11) |
| R1-K1 | A.1 terhalang approval (r1 X-1/X-2) | Dijawab: c (2026-10-16): i approver CUSTOMER L4 + SALES_ORDER L2 (wajib) | A.0 + A.1 lewat approval UI (BR-13) |
| R1-K2 | O-1: BE confirm SO non-salesman tanpa cek approval | Dijawab: biarkan dulu (2026-10-16), tanpa tiket | tes tidak memakainya; tidak dilaporkan lagi |
| X-3 | Persetujuan customer SO (`SALESMAN_ACTIVITY`) | Dari kode (2026-10-16): semua SO; i menyetujui di drawer SO web | BR-13 (1) |
| R1-K3 | PIN approval (`use_approval_pin=1`, i tanpa PIN → `APPOVE403`) | Dijawab: c (2026-10-16): developer men-set `use_approval_pin=0` (global) | tes tanpa PIN; AC-1 menemukan 1 lagi = BLOCKED + bukti |
| R4-K1 | Dua 500 di r4: D-1 driver Konfirmasi SA, D-2 SI "Simpan & Konfirmasi" sesudah Impor | Dijawab (2026-10-16): D-1 disengaja (A-9), D-2 diperbaiki task lain (A-10) | A.5/A.6, A.9 lewat jalur yang jalan (BR-8, BR-14); AC-5/7/15 tanpa allow-list |

## 9. Di luar cakupan

- Kode BE/FE, role, cleanup (G-3), print PDF, arsip SO/Billing/RP, SA retur/bayar, Pindahkan massal, opname, mobile/v3.
- Catatan, tidak diasersi: arsip SI dari Billing tanpa sumber DO (`BillingService.php:905-911`); O-1 (R1-K2); A-9, A-10.

## 10. Data uji

| Hal | Isi |
|---|---|
| Dibuat dan **dibiarkan** (G-3) | semua transaksi A, attendance w+a, stok, GL/AR, nomor, arsip DO/SI, folder `E2E-ARSIP-*`; dikenali dari jurnal |
| Diubah permanen | `CST-10120` `approval_status` 0→2 (A.0, sekali); approval SO per run |
| Diubah sementara / dipurge di luar baris sendiri | **tidak ada** |
| ED-1149 | Tidak di antara snapshot "sebelum"/"sesudah" regresi; regresi berikutnya baseline baru; `--leftover` 0 |
| Asumsi lingkungan | `QA_DB=api_sidomaju`; worker antrian hidup; `force_login` boleh (DU-5 a); `GE0106` = BLOCKED; run sendirian, dimulai manual oleh developer; geolokasi = geotag customer |
