# ED-1070 - daftar route `normalize_pagination` (P) dan `normalize_search` (S), branch `v5-opname-archive`

Pengganti `brief/design.md` Lampiran A untuk branch ini (BE `0b8417778`). Data lengkap per route (URI, Controller@method,
rantai service, FormRequest) ada di `routes.tsv` (satu baris = satu statement route). Dibuat ulang 2026-10-15 oleh BA.

## Cara daftar dibuat (bisa diulang)

1. **Route nyata** dari router Laravel (bukan regex berkas route): setiap `Route::get/post/...` direkam berkas:baris tempat
   statement dimulai (baris pertama statement multi-baris, mis. `Raptor/raptor-sync.php:7`). Tiap route punya baris unik.
2. **Controller@method → method service**: `$this->prop->m()` (prop dari constructor `new X` / injeksi), `(new X)->m()`,
   `X::m()`, `X::forRoute(..)->m()` dan variabel lokal `$s = new X` / `$s = X::forRoute(..)`; method abstrak diturunkan ke
   subkelas (`IssueSummary` → `PendingSummary`/`FailedSummary`). Ditelusuri transitif lintas kelas, `$this->`/`parent::`, trait.
3. **P** = rantai membaca `pagination` (`$x->pagination`, `$x['pagination']`, `->get/input/query('pagination')`).
4. **S** = rantai (atau FormRequest method itu) memanggil `json_decode` atas `search` dari request tanpa `is_string`/`isJSON`
   di baris yang sama: `$request->search`, `$request['search']`, `$this->search` (FormRequest), variabel yang diisi dari itu
   (`ArchiveService.php:42-43` `$searchRequest`), atau parameter method yang di-decode dan dipanggil dengan `$request->search`.
   Service yang memvalidasi `search` lebih dulu (`OpnameService.php:803` rule `json` → 422) tidak termasuk.

## Ringkasan

| | Jumlah |
|---|---|
| Statement route P | 262 (58 berkas; 260 GET, 1 POST `opnames/preview/documents`, 1 PUT `setting/display-columns/{moduleName}`) |
| Statement route S | 584 (29 berkas; 459 GET, 125 POST - kebanyakan export/print laporan) |
| P dan S sekaligus | 65 |
| Total statement yang disentuh | 781 di 68 berkas |
| P yang `pagination`-nya sudah divalidasi FormRequest (kini 422, bukan 500) | 11 (Raptor: `IndexRequest`, `SummaryDetailRequest`, `TransactionRequest`) - lihat spec K-6 |

## Beda dengan Lampiran A tim (202 route, branch `v5-salesman-activity`)

- **Dibuang 1**: `Authentication/authentication.php:25` `AuthenticationController@getDatabase` - di branch ini memanggil
  `DatabaseUserService::index()` tanpa `pagination` (`AuthenticationController.php:44`, `DatabaseUserService.php:15-20`).
  Route `CustomerDeviceService::index` yang dimaksud ada di `Setup/customer.php:65` (sudah P).
- **Ditambah 61**: 57 route `Select/select.php` yang memanggil `index` service lain lintas kelas (mis.
  `SelectCustomerService::salesOrder` → `(new SalesOrderService)->index($request)`, `SelectCustomerService.php:240-241`;
  pemindaian tim hanya menelusuri kelas yang sama + turunan); 3 route `DocumentArchive/api.php` (`opnames` :43,
  `opnames/{id}/documents` :50, `opnames/preview/documents` :47 POST - `OpnameService.php:98,295,612`); 1
  `Purchase/purchase-order.php` `PurchasePriceController@surveyPrice`.
- Nomor baris berbeda (contoh `DocumentArchive/api.php` index = :23, Lampiran A :20).
- 11 route Raptor kini ditemukan otomatis (pola `X::forRoute(..)->m()`), bukan manual.

## Verifikasi sesudah pemasangan (Review Focus 5)

Dump router (one-liner `v5-be-conventions` `verification.md` §2, `gatherMiddleware()`), lalu bandingkan dengan `routes.tsv`:
himpunan route ber-`normalize_pagination` = baris P, ber-`normalize_search` = baris S, persis (tidak lebih, tidak kurang);
jumlah route `api/v5` dan Controller@method tiap route sama dengan sebelum; `grep -rnE "//.*normalize_(pagination|search)"
Modules/V5/Routes` kosong; `php -l` tiap berkas route.

## Baris per berkas (`Modules/V5/Routes/...`)

| Berkas | P (baris) | S (baris) |
|---|---|---|
| `Application/notification.php` | 7 | - |
| `Authentication/authentication.php` | 41,48 | 41 |
| `DocumentArchive/api.php` | 23,43,47,50 | 23 |
| `Financial/bank-reconcile.php` | 18 | 18 |
| `Financial/bg-cheque.php` | 19,20,23 | 19,20,23 |
| `Financial/cash-management.php` | 19 | - |
| `Financial/interbank-transfer.php` | 18 | - |
| `Financial/payable-payment.php` | 19 | - |
| `Financial/petty-cash-transaction.php` | 18 | - |
| `Financial/purchase-variant.php` | 21,40 | - |
| `Financial/receipt-note.php` | 19 | - |
| `Financial/receivable-payment.php` | 20,21,24 | 20,21,24 |
| `Financial/sales-variant.php` | 20,39 | - |
| `FixedAsset/fixed-asset-revaluation.php` | 18 | - |
| `FixedAsset/fixed-asset-transfer.php` | 18 | - |
| `FixedAsset/fixed-asset.php` | 18 | - |
| `Import/api.php` | 24,33,42,49 | 25,26,43,50 |
| `Inventory/issue-receipt.php` | 19,20 | - |
| `Inventory/item-request.php` | 10,11,28,29,46,47 | - |
| `Inventory/item-transfer.php` | 9,10,33 | - |
| `Inventory/job-cost-rollover.php` | 18 | - |
| `Inventory/job-costing.php` | 18 | - |
| `Inventory/production.php` | 19 | - |
| `Inventory/recipe-simulation.php` | 19 | - |
| `Inventory/stock-opname.php` | 19,20 | - |
| `Journal/journal.php` | 19 | - |
| `Promotion/customer-pricelist.php` | 18 | - |
| `Promotion/supplier-pricelist.php` | 18 | - |
| `Purchase/purchase-invoice.php` | 19,20 | - |
| `Purchase/purchase-order.php` | 19,20,39,40,41 | - |
| `Purchase/purchase-receipt.php` | 19,20 | - |
| `Purchase/purchase-return.php` | 19 | - |
| `Purchase/survey-supplier-price.php` | 18 | - |
| `Raptor/raptor-deposit.php` | 22,38,54,67 | 22,38,54,67 |
| `Raptor/raptor-invoice.php` | 22,47,55,73,90,95,110 | 22,39,43,47,55,73,88,89,90,95,110 |
| `Report/account-payable.php` | - | 28,29,30,31,36,37,38,39,44,45,46,47,52,53,54,55,59,60,61,62,67,68,69,70,76,77,78,79,84,85,86,87 |
| `Report/account-receivable.php` | - | 28,29,30,31,36,37,38,39,44,45,46,47,52,53,54,55,60,61,62,63,67,68,69,70,75,76,77,78,84,85,86,87 |
| `Report/cash-bank.php` | - | 29,30,31,32,37,38,39,40,43,44,45,46,49,50,51,52,55,56,57,58,64,65,66,67,72,73,74,75,81,82,83,84,89,90,91,92,97,98,99,100,105,106,107,108,112,113,114,115 |
| `Report/financial.php` | - | 30,31,32,33,38,39,40,41,46,47,48,49,54,55,56,57,64,65,66,67,70,71,72,73,76,77,78,79,84,85,86,87,92,93,94,95,99,100,101,102,108,109,110,111,116,117,118,119,124,125,126,127 |
| `Report/fixed-asset.php` | - | 24,25,26,27,32,33,34,35,40,41,42,43,48,49,50,51,56,57,58,59 |
| `Report/general-ledger.php` | - | 26,27,28,29,34,35,36,37,42,43,44,45,50,51,52,53,58,59,60,61,66,67,68,69,74,75,76,77 |
| `Report/inventory.php` | - | 43,44,45,46,51,52,53,54,59,60,61,62,67,68,69,70,75,76,77,78,83,84,85,86,91,92,93,94,99,100,101,102,107,108,109,110,115,116,117,118,123,124,125,126,131,132,133,134,139,140,141,142,147,148,149,150,155,156,157,158,159,164,165,166,167,172,173,174,175,180,181,182,183,188,189,190,191,196,197,198,199,204,205,206,207,212,213,214,215,220,221,222,223,228,229,230,231 |
| `Report/purchase.php` | - | 40,41,42,43,50,51,52,53,58,59,60,61,66,67,68,69,74,75,76,77,84,85,86,87,92,93,94,95,102,103,104,105,110,111,112,113,120,121,122,123,128,129,130,131,138,139,140,141,146,147,148,149,154,155,156,157,164,165,166,167,174,175,176,177,184,185,186,187,194,195,196,197,204,205,206,207,214,215,216,217,224,225,226,227 |
| `Report/sales.php` | - | 42,43,44,45,52,53,54,55,60,61,62,63,70,71,72,73,78,79,80,81,88,89,90,91,96,97,98,99,106,107,108,109,114,115,116,117,123,124,125,126,132,133,134,135,142,143,144,145,152,153,154,155,162,163,164,165,172,173,174,175,182,183,184,185,192,193,194,195,202,203,204,205,212,213,214,220,221,222,223,227,228,229,230 |
| `Report/tax.php` | - | 25,26,27,28,33,34,35,36,41,42,43,44,49,50,51,52,57,58,59,60,65,66,67,68 |
| `Sale/billing.php` | 18 | - |
| `Sale/delivery-order.php` | 19 | - |
| `Sale/sales-invoice.php` | 19 | 19 |
| `Sale/sales-order.php` | 18,21 | 18,21 |
| `Sale/sales-return.php` | 19 | - |
| `Sale/shipment.php` | 20,40,52,53 | 52,53 |
| `SalesmanActivity/api.php` | 30,38,46,48,52,65,69,78,81,82,83,84,85,86,92,93,96 | 46,47,65,78,81,82,83,84,85,86,92,93,96 |
| `Select/select.php` | 363,383,387,445,464,466,469,480,481,500,503,525,536,537,544,564,565,581,584,613,668,699,700,701,702,709,710,711,712,798,799,819,828,829,831,859,884,887,890,924,958,981,996,1005,1006,1007,1033,1036,1037,1057,1085,1088,1091,1092,1113,1114,1117,1132,1151,1167,1170,1190,1196,1212,1218,1230,1240,1272,1273,1277,1286,1297,1311,1327,1362,1364,1388,1390,1406,1407,1413,1436,1437,1447,1533,1548,2702,2703 | 699,701,702,709,711,712,798,828,859,884,996,1006,1033,1036,1088,1092,1113,1132,1167,1170,1437,1664,1675,1696,1708,1761,2702,2703 |
| `Setup/account.php` | 21,30,36 | 24 |
| `Setup/asset.php` | 28,35,42 | 21 |
| `Setup/cash-bank.php` | 22,29,36,45 | - |
| `Setup/company.php` | 26,32,38,44,50,65,73,79,80,84 | 56,62 |
| `Setup/currency.php` | 7 | - |
| `Setup/customer.php` | 15,23,30,37,44,47,65 | - |
| `Setup/employee.php` | 10,17,24,31 | - |
| `Setup/general-ledger.php` | 21,36,43 | 21 |
| `Setup/item.php` | 37,44,51,65,72,79,86,98,99,104,112,119,126,128,136,137,142,149,155 | 58,98,99,126 |
| `Setup/payment.php` | 19,27 | - |
| `Setup/raptor.php` | 20 | - |
| `Setup/setting.php` | 36,43,49,55,57,62,63,72,79 | - |
| `Setup/supplier.php` | 25,33,40,47,54,67 | - |
| `Setup/tax.php` | 19,28 | - |
| `ToDoList/api.php` | 23 | 23 |
