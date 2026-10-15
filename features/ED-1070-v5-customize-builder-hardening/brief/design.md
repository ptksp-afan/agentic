# Pengerasan `CustomizeBuilder` V5: desain

- **Tanggal:** 2026-10-15
- **Sumber temuan:** `diskusi-tim.md` di direktori yang sama (ED-1070; butir #1–#10, sedangkan #11 sudah beres di ED-1069)
- **Lingkup:** V5 saja. Trait V4 tidak disentuh.
- **Dokumen pasangan:** rencana implementasi di `plan.md` (direktori yang sama). Pengujian
  fungsional ditulis di dokumen terpisah.

## 1. Latar belakang

`Modules/V5/Entities/Traits/CustomizeBuilder.php` dipakai sekitar 190 model V5 supaya service cukup memanggil
`searchAll($request->search)` dan `sortAll($request->sorts)`. Developer cukup mendefinisikan kolom di
`Modules/V5/Config/displayColumn/*.php`. Trait ini membaca nama kunci dari input user dan meneruskannya ke query
builder hampir tanpa saringan. Akibatnya:

- method builder apa pun bisa dipanggil (jalur `()`);
- method model apa pun bisa dipanggil (filter relasi);
- nama kunci masuk ke `DB::raw`/`orderByRaw` (injeksi SQL);
- input salah bentuk menghasilkan 500.

## 2. Tujuan dan prinsip

1. **Request sah tidak berubah.** Request yang dibentuk FE dari config `displayColumn` menghasilkan SQL dan data yang
   sama seperti sebelumnya. Pengecualian yang disengaja: §6 #9 dan #7.
2. **Input bermasalah dilewati, bukan ditolak.** Entri `search`/`sorts`/`pagination` yang berbahaya atau salah bentuk
   dibuang. Sisa filter tetap jalan, respons tetap 200, dan setiap pembuangan dicatat dengan `Log::warning()`.
3. **Satu sumber aturan.** Semua aturan saringan berada di satu kelas guard, bukan tersebar di cabang trait.

## 3. Di luar lingkup

- Trait V4 (`Modules/V4/Entities/Traits/CustomizeBuilder.php`).
- Respons 422/400 untuk input salah bentuk (diputuskan: dilewati + log).
- `scopeSelectBuilderQuery`, `scopeSelectFormat`, `scopeSelectFormatJSON` sebagai titik masuk langsung. Argumennya
  berasal dari kode atau config developer, bukan input user. Yang ditutup hanya pemanggilannya lewat jalur `()` (§5.2).
- Mengubah 160 service yang membaca `pagination` (ditangani middleware, §5.4).
- Test fungsional/golden (dokumen terpisah).

## 4. Komponen

| Berkas | Status | Tanggung jawab |
|---|---|---|
| `Modules/V5/Entities/Helper/CustomizeBuilderGuard.php` | baru | Semua aturan saringan dan pencatatan log (§5) |
| `Modules/V5/Entities/Traits/CustomizeBuilder.php` | diubah | Memanggil guard di setiap cabang; perbaikan sort relasi (#9) |
| `Modules/V5/Http/Middleware/NormalizePagination.php` | baru | Membuang `pagination` yang tidak valid |
| `app/Http/Kernel.php` | diubah | Alias `normalize_pagination` di `$routeMiddleware` |
| Berkas route V5 (Lampiran A) | diubah | `->middleware('normalize_pagination')` pada route list |
| `Modules/V5/Entities/Models/*` | opsional | Properti `protected $searchableScopes = [...]` hanya bila suatu scope perlu dibuka di luar config |

## 5. Aturan guard (`CustomizeBuilderGuard`)

Kelas tanpa state selain cache statis per proses. Semua method `public` supaya bisa diuji terpisah. Kode wajib
kompatibel PHP 7.3 (tanpa arrow function, typed property, atau `match`).

### 5.1 `isSafeIdentifier(string $name): bool`

Lolos bila cocok `/^[A-Za-z0-9_.]+$/` dan tidak diawali atau diakhiri `.`. Wajib dicek sebelum nama apa pun masuk ke:

- `DB::raw('COALESCE(' . $attribute . ', 0)')` (cabang `sum*` dan `scopeSearchOwnTable` cabang operator);
- `DB::raw($attribute)` di `scopeSearchNumber` dan `scopeSearchCondition`;
- `DB::raw($condition)` di `scopeSearchCondition`.

Titik tetap diizinkan karena FE mengirim `data_index` bersarang sebagai `a.b`.

### 5.2 `isAllowedScope($model, string $name): bool` (jalur `()`)

Lolos hanya bila **semua** syarat berikut terpenuhi:

1. `method_exists($model, 'scope' . ucfirst($name))`. Method builder (`delete`, `update`, `truncate`, `whereRaw`, ...)
   otomatis gugur karena bukan scope.
2. `$name` tidak termasuk scope internal trait: `selectFormat`, `dependsOn`, `selectFormatJSON`, `searchAll`, `sortAll`,
   `selectBuilderQuery`, `exclude`, `searchOwnTable`, `searchDate`, `searchNumber`, `whereLocation`, `searchCondition`.
3. `$name . '()'` terdaftar sebagai `data_index` (termasuk `children`) di **berkas config**
   `config('v5.displayColumn.' . $moduleName)`, **atau** `$name` ada di `$model->searchableScopes`.

Alasan memakai berkas config, bukan `MyHelper::getDisplaySetting()`: setting tersebut dibaca dari tabel
`column_display_settings` yang bisa diubah lewat endpoint setup. Kalau allowlist diambil dari DB, user bisa
menambahkan scope sendiri ke daftar itu. Bila `$moduleName` tidak diset (service tidak memanggil
`selectBuilderQuery`), yang berlaku hanya `$searchableScopes`.

### 5.3 `isRelationMethod($model, string $name): bool` (filter relasi #2, sort relasi #9)

Lolos bila, menurut `ReflectionMethod`:

- method ada, `public`, tidak `static`, dan `getNumberOfRequiredParameters() === 0`;
- kelas pendeklarasinya tidak berada di namespace `Illuminate\`;
- nama tidak diawali `scope`, `get`, `set`, atau `boot`;
- isi method (dibaca dari `getFileName()`, `getStartLine()`–`getEndLine()`) memuat
  `return $this->(hasOne|hasMany|belongsTo|belongsToMany|hasOneThrough|hasManyThrough|morphTo|morphOne|morphMany|morphToMany|morphedByMany)(`.

Hasilnya di-cache per `class::method` dalam array statis. Relasi yang ditulis dengan pola lain (misalnya disimpan
dulu ke variabel) akan gugur. Plan wajib memindai model V5 untuk pola seperti ini dan mendaftarkannya sebelum
implementasi.

### 5.4 Middleware `NormalizePagination`

- Bila request memiliki `pagination` dan nilainya **bukan** string/int yang cocok `/^[1-9][0-9]*$/`, panggil
  `$request->query->remove('pagination')` dan `$request->request->remove('pagination')`, lalu catat log `malformed`.
- Service kemudian jatuh ke `$request->pagination ?? AppConfig::getPagination()` yang sudah ada.
- Dipasang **per route** (Lampiran A), bukan di grup global V5.

### 5.5 Validasi bentuk lain

| Method guard | Aturan |
|---|---|
| `isValidOperator($op)` | `in_array(strtolower(trim($op)), ['=', '<>', '!=', '>', '>=', '<', '<=', 'between'])` |
| `isValidDateRange($v)` | Array berisi tepat 2 elemen; tiap elemen `null` atau bisa di-parse Carbon tanpa exception; tidak boleh keduanya `null` |
| `isValidNumberFilter($v)` | Array dengan `$v[0]` lolos `isValidOperator`; untuk `between`, elemen `[1]` dan `[2]` numerik; selain itu `[1]` numerik |
| `normalizeSortType($t)` | `strtolower(trim((string) $t)) === 'asc' ? 'asc' : 'desc'` (sama dengan `Builder::orderBy` Laravel 5.7) |
| `relatedHasColumn($relation, $col)` | `Str::snake($col)` ada di `getColumnListing()` tabel model relasi; di-cache per tabel |

### 5.6 Pencatatan log: `skip(string $kind, string $key, string $reason, $value = null): void`

```php
Log::warning("[CustomizeBuilder][{$kind}] {$reason}", [
    'user'  => optional(auth()->user())->getAuthIdentifier(),
    'route' => optional(request()->route())->uri(),
    'model' => $modelClass,
    'key'   => Str::limit($key, 100),
    'value' => Str::limit(json_encode($value), 200),
]);
```

- `$kind = 'blocked'`: input berbahaya (#1, #2, #3, identifier tidak aman).
- `$kind = 'malformed'`: bentuk salah (#4–#8, #10, `pagination`, `limit`).
- `sortBy` yang tidak cocok dengan kolom atau relasi mana pun **tidak** dicatat. Ini perilaku normal: FE mengirim
  sort kolom hitungan yang diurutkan di PHP (`MyHelper::arraySorting`).

## 6. Perilaku per temuan

| # | Input | Sekarang | Sesudah | Log |
|---|---|---|---|---|
| 1 | `{"xxx()":v}` | Method builder apa pun dijalankan | Jalan hanya bila `isAllowedScope` lolos | blocked |
| 2 | `{"rel":{k:v}}`, `rel` bukan relasi | `$model->rel()` dijalankan, lalu 500 | Jalan hanya bila `isRelationMethod` lolos | blocked |
| 3 | Kunci `sum*`, kunci bertitik, atribut ke `searchNumber`/`searchCondition` | Masuk `DB::raw` | Dilewati bila gagal `isSafeIdentifier` | blocked |
| 3+ | `sortType` di jalur sort relasi | Masuk `orderByRaw` | `normalizeSortType` | – |
| 4 | `search` bukan JSON objek/array | 500 `INVALID_DATA` | Seluruh `search` diabaikan | malformed |
| 5 | `{"query":[...]}` | 500 | `query` wajib skalar | malformed |
| 5 | `{"subXyz":"a"}` | 500 | `sub*` wajib array asosiatif tidak kosong dengan kunci yang lolos `isSafeIdentifier` | malformed |
| 5 | `{"sumXyz":"a"}` | 500 | `sum*` wajib `[op, nilai]` dengan `isValidOperator` | malformed |
| 6 | `sorts` bukan array / entri tanpa `sortBy` | 500 | `sorts` atau entri tersebut dilewati; `sortType` hilang dianggap `desc` | malformed |
| 7 | `pagination` = `abc`, `-1`, `[]`, `0`, `2.5` | 500 / 200 ukuran aneh | Dibuang oleh middleware, lalu dipakai default `AppConfig` | malformed |
| 8 | Tanggal `["abc","def"]`, `["x"]`, `[null,null]` | 500 | `isValidDateRange` | malformed |
| 8 | Angka/currency salah bentuk | 500 | `isValidNumberFilter` | malformed |
| 8 | Kolom tak dikenal di filter relasi | 500 | `relatedHasColumn` | malformed |
| 9 | Sort kolom relasi dengan FK ≠ PK (contoh: Pelanggan → `relatedEmployee.firstName`) | 500 | Kunci diambil dari objek Relation (§7) | – |
| 10 | `search=[1,2]` (array tanpa kunci string) | 200 tanpa filter | 200 tanpa filter | malformed |
| – | `limit` bukan bilangan bulat ≥ 1 | Di-cast Laravel | Dilewati | malformed |

Validasi `search` tetap berada di trait, bukan middleware, karena endpoint select (`scopeSelectFormat`) memakai
`search` sebagai teks biasa.

## 7. Perbaikan sort relasi (#9)

Di `scopeSortAll`, setelah relasi ditemukan lewat `getRelationAttributes`:

1. Pastikan `isRelationMethod($model, $relationName)` lolos; kalau tidak, entri dilewati.
2. `$rel = Relation::noConstraints(function () use ($model, $relationName) { return $model->{$relationName}(); });`
   (tanpa arrow function: runtime aplikasi PHP 7.3)
3. Tentukan pasangan kolom penghubung:
   - `BelongsTo`: `related.{$rel->getOwnerKey()}` = `parent.{$rel->getForeignKey()}`
   - `HasOne`/`HasMany`: `related.{$rel->getForeignKeyName()}` = `parent.{$rel->getLocalKeyName()}`
   - Tipe lain: tetap memakai perilaku lama (PK relasi = kolom bernama sama di tabel induk).
4. Hack `if ($relation['relation_name'] == 'baseUnit')` dihapus. `baseUnit` adalah
   `belongsTo(Unit::class, 'id_base_unit')`, jadi sudah tercakup langkah 3.
5. `$sortType` dinormalkan dengan `normalizeSortType` sebelum masuk `orderByRaw`.

Dampak: hasil identik untuk relasi yang nama FK-nya sama dengan nama PK relasi (mayoritas). Yang berubah hanya
relasi dengan FK ≠ PK, yang sekarang 500 atau urutannya salah.

Catatan binding: `orderByRaw($sql, $sortType)` saat ini mengirim `$sortType` sebagai binding tanpa placeholder.
Koneksi memakai `PDO::ATTR_EMULATE_PREPARES => true` (`config/database.php:75`). Aturan keputusannya: jalankan query uji
di container (langkah ada di plan).

- Bila hasilnya `SQLSTATE[HY093]`, binding ikut menyebabkan 500 pada **semua** sort relasi, sehingga argumen kedua
  dihapus.
- Bila query berhasil, argumen kedua dibiarkan seperti sekarang.

## 8. Perubahan perilaku yang disengaja

- **#9:** urutan berubah pada sort relasi yang FK-nya berbeda dari PK.
- **#7:** `pagination=0` sebelumnya memakai default model (15); sekarang memakai `AppConfig::getPagination()`.
- **Semua kasus di §6:** dari 500 menjadi 200, dengan entri yang bermasalah dilewati.
- **Klien di luar FE** (mobile, integrasi) yang memakai kunci `()` untuk scope tak terdaftar kehilangan filter tersebut
  tanpa error. Deteksinya lewat log `[CustomizeBuilder][blocked]`. Log ini perlu dipantau seminggu pertama setelah rilis.

## 9. Verifikasi dalam lingkup dokumen ini

- `php -l` dengan PHP 7.3 (versi runtime aplikasi) untuk setiap berkas PHP yang dibuat atau diubah.
- Laravel Pint. Pint belum terpasang di proyek maupun global, dan butuh PHP ≥ 8.1, jadi dijalankan sebagai tool
  terpisah di host (PHP 8.5), tidak ditambahkan ke `composer.json` proyek:
  - berkas baru: `pint <file>` (diterapkan);
  - berkas yang sudah ada (trait, `Kernel.php`, berkas route): `pint --test <file>` saja, supaya diff tidak tercemar
    format ulang seluruh berkas.
- Test fungsional dan golden test ada di dokumen terpisah.

## Lampiran A: Route yang dipasangi `normalize_pagination`

Dihasilkan secara statis dengan memetakan service yang membaca `$request->pagination` / `$request['pagination']`,
lalu method controller yang memanggilnya, lalu baris route. Hasilnya 202 route di 58 berkas route.

| Berkas route | Baris | Controller@method | Service::method |
|---|---|---|---|
| `Modules/V5/Routes/Application/notification.php` | 7 | `NotificationController@index` | NotificationService::index |
| `Modules/V5/Routes/Authentication/authentication.php` | 25 | `AuthenticationController@getDatabase` | CustomerDeviceService::index |
| `Modules/V5/Routes/Authentication/authentication.php` | 41 | `UserController@index` | UserService::index |
| `Modules/V5/Routes/Authentication/authentication.php` | 48 | `RoleController@index` | RoleService::index |
| `Modules/V5/Routes/DocumentArchive/api.php` | 20 | `ArchiveController@index` | ArchiveService::index |
| `Modules/V5/Routes/Financial/bank-reconcile.php` | 18 | `BankReconcileController@index` | BankReconcileService::index |
| `Modules/V5/Routes/Financial/bg-cheque.php` | 19 | `BilyetGiroChequeController@needApproval` | BilyetGiroChequeService::needApproval |
| `Modules/V5/Routes/Financial/bg-cheque.php` | 20 | `BilyetGiroChequeController@needApproval` | BilyetGiroChequeService::needApproval |
| `Modules/V5/Routes/Financial/bg-cheque.php` | 23 | `BilyetGiroChequeController@index` | BilyetGiroChequeService::index |
| `Modules/V5/Routes/Financial/cash-management.php` | 19 | `CashManagementController@index` | CashManagementService::index |
| `Modules/V5/Routes/Financial/interbank-transfer.php` | 18 | `InterbankTransferController@index` | InterbankTransferService::index |
| `Modules/V5/Routes/Financial/payable-payment.php` | 19 | `PayablePaymentController@index` | PayablePaymentService::index |
| `Modules/V5/Routes/Financial/petty-cash-transaction.php` | 18 | `PettyCashTransactionController@index` | PettyCashTransactionService::index |
| `Modules/V5/Routes/Financial/purchase-variant.php` | 21 | `DebitMemoController@index` | DebitMemoService::index |
| `Modules/V5/Routes/Financial/purchase-variant.php` | 40 | `DebitMemoRefundController@index` | DebitMemoRefundService::index |
| `Modules/V5/Routes/Financial/receipt-note.php` | 19 | `ReceiptNoteController@index` | ReceiptNoteService::index |
| `Modules/V5/Routes/Financial/receivable-payment.php` | 20 | `ReceivablePaymentController@needApproval` | ReceivablePaymentService::needApproval |
| `Modules/V5/Routes/Financial/receivable-payment.php` | 21 | `ReceivablePaymentController@needApproval` | ReceivablePaymentService::needApproval |
| `Modules/V5/Routes/Financial/receivable-payment.php` | 24 | `ReceivablePaymentController@index` | ReceivablePaymentService::index |
| `Modules/V5/Routes/Financial/sales-variant.php` | 20 | `CreditMemoController@index` | CreditMemoService::index |
| `Modules/V5/Routes/Financial/sales-variant.php` | 39 | `CreditMemoRefundController@index` | CreditMemoRefundService::index |
| `Modules/V5/Routes/FixedAsset/fixed-asset-revaluation.php` | 18 | `FixedAssetRevaluationController@index` | FixedAssetRevaluationService::index |
| `Modules/V5/Routes/FixedAsset/fixed-asset-transfer.php` | 18 | `FixedAssetTransferController@index` | FixedAssetTransferService::index |
| `Modules/V5/Routes/FixedAsset/fixed-asset.php` | 18 | `FixedAssetController@index` | FixedAssetService::index |
| `Modules/V5/Routes/Import/api.php` | 24 | `CustomerController@log` | CustomerService::log |
| `Modules/V5/Routes/Import/api.php` | 33 | `CustomerPricelistController@log` | CustomerPricelistService::log |
| `Modules/V5/Routes/Import/api.php` | 42 | `IssueReceiptController@log` | IssueReceiptService::log |
| `Modules/V5/Routes/Import/api.php` | 49 | `StockOpnameController@log` | StockOpnameService::log |
| `Modules/V5/Routes/Inventory/issue-receipt.php` | 19 | `IssueReceiptController@index` | IssueReceiptService::index |
| `Modules/V5/Routes/Inventory/issue-receipt.php` | 20 | `IssueReceiptController@index` | IssueReceiptService::index |
| `Modules/V5/Routes/Inventory/item-request.php` | 10 | `ItemRequestController@index` | ItemRequestService::index |
| `Modules/V5/Routes/Inventory/item-request.php` | 11 | `ItemRequestController@index` | ItemRequestService::index |
| `Modules/V5/Routes/Inventory/item-request.php` | 28 | `PurchaseRequestController@index` | ItemRequestService::index |
| `Modules/V5/Routes/Inventory/item-request.php` | 29 | `PurchaseRequestController@index` | ItemRequestService::index |
| `Modules/V5/Routes/Inventory/item-request.php` | 46 | `ProductionRequestController@index` | ItemRequestService::index |
| `Modules/V5/Routes/Inventory/item-request.php` | 47 | `ProductionRequestController@index` | ItemRequestService::index |
| `Modules/V5/Routes/Inventory/item-transfer.php` | 9 | `ItemTransferLocationController@index` | ItemTransferLocationService::index |
| `Modules/V5/Routes/Inventory/item-transfer.php` | 10 | `ItemTransferLocationController@index` | ItemTransferLocationService::index |
| `Modules/V5/Routes/Inventory/item-transfer.php` | 33 | `ItemTransferWarehouseController@index` | ItemTransferWarehouseService::index |
| `Modules/V5/Routes/Inventory/job-cost-rollover.php` | 18 | `JobCostRolloverController@index` | JobCostRolloverService::index |
| `Modules/V5/Routes/Inventory/job-costing.php` | 18 | `JobCostingController@index` | JobCostingService::index |
| `Modules/V5/Routes/Inventory/production.php` | 19 | `ProductionController@index` | ProductionService::index |
| `Modules/V5/Routes/Inventory/recipe-simulation.php` | 19 | `RecipePriceSimulationController@index` | RecipePriceSimiulationService::index |
| `Modules/V5/Routes/Inventory/stock-opname.php` | 19 | `StockOpnameController@index` | StockOpnameService::index |
| `Modules/V5/Routes/Inventory/stock-opname.php` | 20 | `StockOpnameController@index` | StockOpnameService::index |
| `Modules/V5/Routes/Journal/journal.php` | 19 | `JournalController@index` | JournalService::index |
| `Modules/V5/Routes/Promotion/customer-pricelist.php` | 18 | `CustomerPricelistController@index` | CustomerPricelistService::index |
| `Modules/V5/Routes/Promotion/supplier-pricelist.php` | 18 | `SupplierPricelistController@index` | SupplierPricelistService::index |
| `Modules/V5/Routes/Purchase/purchase-invoice.php` | 19 | `PurchaseInvoiceController@index` | PurchaseInvoiceService::index |
| `Modules/V5/Routes/Purchase/purchase-invoice.php` | 20 | `PurchaseInvoiceController@index` | PurchaseInvoiceService::index |
| `Modules/V5/Routes/Purchase/purchase-order.php` | 19 | `PurchaseOrderController@index` | PurchaseOrderService::index |
| `Modules/V5/Routes/Purchase/purchase-order.php` | 20 | `PurchaseOrderController@index` | PurchaseOrderService::index |
| `Modules/V5/Routes/Purchase/purchase-order.php` | 39 | `PurchasePriceController@lastPrice` | PurchasePriceService::lastAndLowestPrice |
| `Modules/V5/Routes/Purchase/purchase-order.php` | 40 | `PurchasePriceController@lowestPrice` | PurchasePriceService::lastAndLowestPrice |
| `Modules/V5/Routes/Purchase/purchase-receipt.php` | 19 | `PurchaseReceiptController@index` | PurchaseReceiptService::index |
| `Modules/V5/Routes/Purchase/purchase-receipt.php` | 20 | `PurchaseReceiptController@index` | PurchaseReceiptService::index |
| `Modules/V5/Routes/Purchase/purchase-return.php` | 19 | `PurchaseReturnController@index` | PurchaseReturnService::index |
| `Modules/V5/Routes/Purchase/survey-supplier-price.php` | 18 | `SurveySupplierPriceController@index` | SurveySupplierPriceService::index |
| `Modules/V5/Routes/Raptor/raptor-deposit.php` | 22 | `RaptorDepositController@index` | RaptorDepositService::index -> TransactionList::build |
| `Modules/V5/Routes/Raptor/raptor-deposit.php` | 38 | `RaptorDepositController@index` | RaptorDepositService::index -> TransactionList::build |
| `Modules/V5/Routes/Raptor/raptor-deposit.php` | 54 | `RaptorDepositController@index` | RaptorDepositService::index -> TransactionList::build |
| `Modules/V5/Routes/Raptor/raptor-deposit.php` | 67 | `RaptorDepositController@index` | RaptorDepositService::index -> TransactionList::build |
| `Modules/V5/Routes/Raptor/raptor-invoice.php` | 22 | `RaptorInvoiceController@index` | RaptorInvoiceService::index -> TransactionList::build |
| `Modules/V5/Routes/Raptor/raptor-invoice.php` | 47 | `RaptorInvoiceController@summaryDetail` | IssueSummary -> Pending/FailedSummary::summaryDetail |
| `Modules/V5/Routes/Raptor/raptor-invoice.php` | 55 | `RaptorInvoiceController@index` | RaptorInvoiceService::index -> TransactionList::build |
| `Modules/V5/Routes/Raptor/raptor-invoice.php` | 73 | `RaptorInvoiceController@index` | RaptorInvoiceService::index -> TransactionList::build |
| `Modules/V5/Routes/Raptor/raptor-invoice.php` | 90 | `RaptorInvoiceController@summaryDetail` | IssueSummary -> Pending/FailedSummary::summaryDetail |
| `Modules/V5/Routes/Raptor/raptor-invoice.php` | 95 | `RaptorInvoiceController@index` | RaptorInvoiceService::index -> TransactionList::build |
| `Modules/V5/Routes/Raptor/raptor-invoice.php` | 110 | `RaptorInvoiceController@transaction` | RaptorInvoiceService::transaction -> BulkTransactionList::build |
| `Modules/V5/Routes/Sale/billing.php` | 18 | `BillingController@index` | BillingService::index |
| `Modules/V5/Routes/Sale/delivery-order.php` | 19 | `DeliveryOrderController@index` | DeliveryOrderService::index |
| `Modules/V5/Routes/Sale/sales-invoice.php` | 19 | `SalesInvoiceController@index` | SalesInvoiceService::index |
| `Modules/V5/Routes/Sale/sales-order.php` | 18 | `SalesOrderController@index` | SalesOrderService::index |
| `Modules/V5/Routes/Sale/sales-order.php` | 21 | `SalesOrderController@index` | SalesOrderService::index |
| `Modules/V5/Routes/Sale/sales-return.php` | 19 | `SalesReturnController@index` | SalesReturnService::index |
| `Modules/V5/Routes/Sale/shipment.php` | 20 | `ShipmentController@index` | ShipmentService::index |
| `Modules/V5/Routes/Sale/shipment.php` | 40 | `ShipmentAcceptanceController@index` | ShipmentAcceptanceService::index |
| `Modules/V5/Routes/Sale/shipment.php` | 52 | `ShipmentAcceptanceController@needApproval` | ShipmentAcceptanceService::needApproval |
| `Modules/V5/Routes/Sale/shipment.php` | 53 | `ShipmentAcceptanceController@needApproval` | ShipmentAcceptanceService::needApproval |
| `Modules/V5/Routes/SalesmanActivity/api.php` | 30 | `CustomerController@index` | CustomerService::index |
| `Modules/V5/Routes/SalesmanActivity/api.php` | 38 | `EmployeeController@index` | EmployeeService::index |
| `Modules/V5/Routes/SalesmanActivity/api.php` | 46 | `ItemController@index` | ItemService::index |
| `Modules/V5/Routes/SalesmanActivity/api.php` | 48 | `ItemController@salesOrder` | ItemService::salesOrder |
| `Modules/V5/Routes/SalesmanActivity/api.php` | 52 | `CustomerController@index` | CustomerService::index |
| `Modules/V5/Routes/SalesmanActivity/api.php` | 65 | `AttendanceController@index` | AttendanceService::index |
| `Modules/V5/Routes/SalesmanActivity/api.php` | 69 | `AttendanceController@needApprove` | AttendanceService::needApprove |
| `Modules/V5/Routes/SalesmanActivity/api.php` | 78 | `SalesmanActivityController@showCalendar` | SalesmanActivityService::showCalendar |
| `Modules/V5/Routes/SalesmanActivity/api.php` | 81 | `SalesmanActivityController@index` | SalesmanActivityService::index |
| `Modules/V5/Routes/SalesmanActivity/api.php` | 82 | `SalesmanActivityController@export` | SalesmanActivityService::export |
| `Modules/V5/Routes/SalesmanActivity/api.php` | 83 | `SalesmanActivityController@effectiveCall` | SalesmanActivityService::effectiveCall |
| `Modules/V5/Routes/SalesmanActivity/api.php` | 84 | `SalesmanActivityController@exportEffectiveCall` | SalesmanActivityService::exportEffectiveCall |
| `Modules/V5/Routes/SalesmanActivity/api.php` | 85 | `SalesmanActivityController@customerActivity` | SalesmanActivityService::customerActivity |
| `Modules/V5/Routes/SalesmanActivity/api.php` | 86 | `SalesmanActivityController@exportCustomerActivity` | SalesmanActivityService::exportCustomerActivity |
| `Modules/V5/Routes/SalesmanActivity/api.php` | 92 | `SalesOrderController@needApprovalCustomer` | SalesOrderService::needApprovalCustomer |
| `Modules/V5/Routes/SalesmanActivity/api.php` | 93 | `SalesOrderController@needApprovalSpv` | SalesOrderService::needApprovalSpv |
| `Modules/V5/Routes/SalesmanActivity/api.php` | 96 | `SalesOrderController@index` | SalesOrderService::index |
| `Modules/V5/Routes/Select/select.php` | 363 | `SelectSalesAnalysisCategoryController@advanceItems` | SelectSalesAnalysisCategoryService::advanceItems |
| `Modules/V5/Routes/Select/select.php` | 383 | `SelectRecipeController@advanceItems` | SelectRecipeService::advanceItems |
| `Modules/V5/Routes/Select/select.php` | 387 | `SelectRecipeController@advanceItems` | SelectRecipeService::advanceItems |
| `Modules/V5/Routes/Select/select.php` | 445 | `SelectSupplierSurveyPriceController@advanceItems` | SelectSupplierSurveyPriceService::advanceItems |
| `Modules/V5/Routes/Select/select.php` | 464 | `SelectPurchaseOrderController@advanceItems` | SelectPurchaseOrderService::advanceItems |
| `Modules/V5/Routes/Select/select.php` | 536 | `SelectPurchaseInvoiceController@purchaseReceipt` | SelectPurchaseInvoiceService::purchaseReceipt |
| `Modules/V5/Routes/Select/select.php` | 668 | `SelectSupplierController@advanceItems` | SelectSupplierService::advanceItems |
| `Modules/V5/Routes/Select/select.php` | 799 | `SelectReceiptNoteController@previewPurchaseInvoice` | SelectReceiptNoteService::previewPurchaseInvoice |
| `Modules/V5/Routes/Select/select.php` | 819 | `SelectClearingController@receiptNote` | SelectClearingService::receiptNote |
| `Modules/V5/Routes/Select/select.php` | 829 | `SelectPayablePaymentController@previewPurchaseInvoice` | SelectPayablePaymentService::previewPurchaseInvoice |
| `Modules/V5/Routes/Select/select.php` | 831 | `SelectPayablePaymentController@receiptNote` | SelectPayablePaymentService::receiptNote |
| `Modules/V5/Routes/Select/select.php` | 890 | `SelectBgChequeController@receiptNote` | SelectBgChequeService::receiptNote |
| `Modules/V5/Routes/Select/select.php` | 924 | `SelectCashManagementController@oldCashMan` | SelectCashManagementService::oldCashMan |
| `Modules/V5/Routes/Select/select.php` | 981 | `SelectSalesOrderController@advanceItems` | SelectSalesOrderService::advanceItems |
| `Modules/V5/Routes/Select/select.php` | 1057 | `SelectSalesInvoiceController@advanceItems` | SelectSalesInvoiceService::advanceItems |
| `Modules/V5/Routes/Select/select.php` | 1170 | `SelectBillingController@receivablePayment` | SelectBillingService::receivablePayment |
| `Modules/V5/Routes/Select/select.php` | 1272 | `SelectItemTransferController@itemRequestOnTrans` | SelectItemRequestService::itemRequestOnTrans |
| `Modules/V5/Routes/Select/select.php` | 1273 | `SelectItemTransferController@advanceItemTransfers` | SelectItemTransferService::advanceItemTransfers |
| `Modules/V5/Routes/Select/select.php` | 1277 | `SelectItemTransferController@unreceivedList` | SelectItemTransferService::unreceivedList |
| `Modules/V5/Routes/Select/select.php` | 1286 | `SelectWarehouseTransferController@advanceItems` | SelectWarehouseTransferService::advanceWhTransfers |
| `Modules/V5/Routes/Select/select.php` | 1297 | `SelectWarehouseTransferController@itemRequestOnTrans` | SelectItemRequestService::itemRequestOnTrans |
| `Modules/V5/Routes/Select/select.php` | 1311 | `SelectIssueReceiptController@advanceItems` | SelectIssueReceiptService::advanceItems |
| `Modules/V5/Routes/Select/select.php` | 1327 | `SelectStockOpnameController@advanceItems` | SelectStockOpnameService::advanceItems |
| `Modules/V5/Routes/Select/select.php` | 1362 | `SelectJobCostingController@advanceItems` | SelectJobCostingService::advanceItems |
| `Modules/V5/Routes/Select/select.php` | 1364 | `SelectJobCostingController@oldJobCosting` | SelectJobCostingService::oldJobCosting |
| `Modules/V5/Routes/Select/select.php` | 1390 | `SelectJobCostRolloverController@oldJobCosting` | SelectJobCostRolloverService::oldJobCosting |
| `Modules/V5/Routes/Select/select.php` | 1406 | `SelectProductionController@advanceItemRaw` | SelectProductionService::advanceItems |
| `Modules/V5/Routes/Select/select.php` | 1407 | `SelectProductionController@advanceItemFinished` | SelectProductionService::advanceItems |
| `Modules/V5/Routes/Select/select.php` | 1436 | `SelectProductionController@itemRequestOnTrans` | SelectItemRequestService::itemRequestOnTrans |
| `Modules/V5/Routes/Select/select.php` | 1437 | `SelectProductionController@recipeItemRequest` | SelectProductionService::recipeItemRequest |
| `Modules/V5/Routes/Select/select.php` | 1447 | `SelectRecipeSimulationController@advanceItems` | SelectRecipeSimulationService::advanceItems |
| `Modules/V5/Routes/Setup/account.php` | 21 | `AccountTypeController@index` | AccountTypeService::index |
| `Modules/V5/Routes/Setup/account.php` | 30 | `AccountBudgetPlanController@index` | AccountBudgetPlanService::index |
| `Modules/V5/Routes/Setup/account.php` | 36 | `CostAllocationController@index` | CostAllocationService::index |
| `Modules/V5/Routes/Setup/asset.php` | 28 | `AssetFiscalController@index` | AssetFiscalService::index |
| `Modules/V5/Routes/Setup/asset.php` | 35 | `AssetServiceTypeController@index` | AssetServiceTypeService::index |
| `Modules/V5/Routes/Setup/asset.php` | 42 | `AssetTypeController@index` | AssetTypeService::index |
| `Modules/V5/Routes/Setup/cash-bank.php` | 22 | `CashBankController@index` | CashBankService::index |
| `Modules/V5/Routes/Setup/cash-bank.php` | 29 | `PettyCashTypeController@index` | PettyCashTypeService::index |
| `Modules/V5/Routes/Setup/cash-bank.php` | 36 | `PettyCashController@index` | PettyCashService::index |
| `Modules/V5/Routes/Setup/cash-bank.php` | 45 | `CashFlowTypeController@index` | CashFlowTypeService::index |
| `Modules/V5/Routes/Setup/company.php` | 26 | `CompanyController@index` | CompanyService::index |
| `Modules/V5/Routes/Setup/company.php` | 32 | `LocationController@index` | LocationService::index |
| `Modules/V5/Routes/Setup/company.php` | 38 | `WarehouseController@index` | WarehouseService::index |
| `Modules/V5/Routes/Setup/company.php` | 44 | `LocationTypeController@index` | LocationTypeService::index |
| `Modules/V5/Routes/Setup/company.php` | 50 | `SiteController@index` | SiteService::index |
| `Modules/V5/Routes/Setup/company.php` | 65 | `ProjectController@show` | ProjectService::show |
| `Modules/V5/Routes/Setup/company.php` | 73 | `CarrierController@index` | CarrierService::index |
| `Modules/V5/Routes/Setup/company.php` | 79 | `CarrierController@loadedTransaction` | CarrierService::loadedTransaction |
| `Modules/V5/Routes/Setup/company.php` | 80 | `CarrierController@deliveredTransaction` | CarrierService::deliveredTransaction |
| `Modules/V5/Routes/Setup/company.php` | 84 | `AccessLocationController@index` | AccessLocationService::index |
| `Modules/V5/Routes/Setup/currency.php` | 7 | `CurrencyController@index` | CurrencyService::index |
| `Modules/V5/Routes/Setup/customer.php` | 15 | `CustomerTypeController@index` | CustomerTypeService::index |
| `Modules/V5/Routes/Setup/customer.php` | 23 | `CustomerAddressController@index` | CustomerAddressService::index |
| `Modules/V5/Routes/Setup/customer.php` | 30 | `CustomerContactController@index` | CustomerContactService::index |
| `Modules/V5/Routes/Setup/customer.php` | 37 | `CustomerBankAccountController@index` | CustomerBankAccountService::index |
| `Modules/V5/Routes/Setup/customer.php` | 44 | `CustomerController@index` | CustomerService::index |
| `Modules/V5/Routes/Setup/customer.php` | 47 | `CustomerController@index` | CustomerService::index |
| `Modules/V5/Routes/Setup/customer.php` | 65 | `CustomerDeviceController@index` | CustomerDeviceService::index |
| `Modules/V5/Routes/Setup/employee.php` | 10 | `EmployeeController@index` | EmployeeService::index |
| `Modules/V5/Routes/Setup/employee.php` | 17 | `JobTitleController@index` | JobTitleService::index |
| `Modules/V5/Routes/Setup/employee.php` | 24 | `SalesmanActivityTypeController@index` | SalesmanActivityTypeService::index |
| `Modules/V5/Routes/Setup/employee.php` | 31 | `HelperController@index` | HelperService::index |
| `Modules/V5/Routes/Setup/general-ledger.php` | 21 | `PeriodController@index` | PeriodService::index |
| `Modules/V5/Routes/Setup/general-ledger.php` | 38 | `ExpenseController@index` | ExpenseService::index |
| `Modules/V5/Routes/Setup/general-ledger.php` | 45 | `RaptorDiscountController@index` | RaptorDiscountService::index |
| `Modules/V5/Routes/Setup/item.php` | 37 | `ReturnReasonController@index` | ReturnReasonService::index |
| `Modules/V5/Routes/Setup/item.php` | 44 | `AdjustmentTypeController@index` | AdjustmentTypeService::index |
| `Modules/V5/Routes/Setup/item.php` | 51 | `UnitController@index` | UnitService::index |
| `Modules/V5/Routes/Setup/item.php` | 65 | `SizeController@index` | SizeService::index |
| `Modules/V5/Routes/Setup/item.php` | 72 | `ColorController@index` | ColorService::index |
| `Modules/V5/Routes/Setup/item.php` | 79 | `CatalogueController@index` | CatalogueService::index |
| `Modules/V5/Routes/Setup/item.php` | 86 | `ItemController@index` | ItemService::index |
| `Modules/V5/Routes/Setup/item.php` | 98 | `ItemVisibilityController@internal` | ItemVisibilityService::internal |
| `Modules/V5/Routes/Setup/item.php` | 99 | `ItemVisibilityController@external` | ItemVisibilityService::external |
| `Modules/V5/Routes/Setup/item.php` | 104 | `ItemGroupController@index` | ItemGroupService::index |
| `Modules/V5/Routes/Setup/item.php` | 112 | `AllowanceController@index` | AllowanceService::index |
| `Modules/V5/Routes/Setup/item.php` | 119 | `RecipeController@index` | RecipeService::index |
| `Modules/V5/Routes/Setup/item.php` | 126 | `SalesAnalysisCategoryController@index` | SalesAnalysisCategoryService::index |
| `Modules/V5/Routes/Setup/item.php` | 128 | `SalesAnalysisCategoryController@histories` | SalesAnalysisCategoryService::histories |
| `Modules/V5/Routes/Setup/item.php` | 136 | `ItemLocationController@available` | ItemLocationService::index |
| `Modules/V5/Routes/Setup/item.php` | 137 | `ItemLocationController@notAvailable` | ItemLocationService::index |
| `Modules/V5/Routes/Setup/item.php` | 142 | `DefaultRecipeController@index` | DefaultRecipeService::index |
| `Modules/V5/Routes/Setup/item.php` | 149 | `ItemPhotoController@index` | ItemPhotoService::index |
| `Modules/V5/Routes/Setup/item.php` | 155 | `MeasureUnitController@index` | MeasureUnitService::index |
| `Modules/V5/Routes/Setup/payment.php` | 19 | `PaymentCategoryController@index` | PaymentCategoryService::index |
| `Modules/V5/Routes/Setup/payment.php` | 27 | `PaymentTermController@index` | PaymentTermService::index |
| `Modules/V5/Routes/Setup/raptor.php` | 20 | `RaptorConnectionController@index` | RaptorConnectionService::index |
| `Modules/V5/Routes/Setup/setting.php` | 36 | `PrinterSettingController@index` | PrinterSettingService::index |
| `Modules/V5/Routes/Setup/setting.php` | 43 | `AppMasterCodeController@index` | AppMasterCodeService::index |
| `Modules/V5/Routes/Setup/setting.php` | 49 | `AppTransCodeController@index` | AppTransCodeService::index |
| `Modules/V5/Routes/Setup/setting.php` | 55 | `TemplateExcelController@index` | TemplateExcelService::index |
| `Modules/V5/Routes/Setup/setting.php` | 57 | `TemplateExcelController@histories` | TemplateExcelService::histories |
| `Modules/V5/Routes/Setup/setting.php` | 62 | `ColumnDisplaySettingController@index` | ColumnDisplaySettingService::index |
| `Modules/V5/Routes/Setup/setting.php` | 63 | `ColumnDisplaySettingController@update` | ColumnDisplaySettingService::index |
| `Modules/V5/Routes/Setup/setting.php` | 72 | `LabelSettingController@index` | LabelSettingService::index |
| `Modules/V5/Routes/Setup/setting.php` | 79 | `PhotoTypeController@index` | PhotoTypeService::index |
| `Modules/V5/Routes/Setup/supplier.php` | 25 | `SupplierTypeController@index` | SupplierTypeService::index |
| `Modules/V5/Routes/Setup/supplier.php` | 33 | `SupplierAddressController@index` | SupplierAddressService::index |
| `Modules/V5/Routes/Setup/supplier.php` | 40 | `SupplierContactController@index` | SupplierContactService::index |
| `Modules/V5/Routes/Setup/supplier.php` | 47 | `SupplierBankAccountController@index` | SupplierBankAccountService::index |
| `Modules/V5/Routes/Setup/supplier.php` | 54 | `CourierController@index` | CourierService::index |
| `Modules/V5/Routes/Setup/supplier.php` | 67 | `SupplierController@index` | SupplierService::index |
| `Modules/V5/Routes/Setup/tax.php` | 19 | `TaxController@index` | TaxService::index |
| `Modules/V5/Routes/Setup/tax.php` | 28 | `TaxController@indexDefaultRaptorTax` | TaxService::getDefaultRaptorTax |
| `Modules/V5/Routes/ToDoList/api.php` | 23 | `ToDoListController@index` | ToDoListService::index |

### A.1 Cara daftar ini dibuat, dan yang sengaja tidak dipasangi

Daftar dibuat dengan pemindaian statis kode (skrip sekali pakai, tidak disimpan di repo) dalam beberapa langkah:

1. Mengambil method service yang membaca `$request->pagination` atau `$request['pagination']`.
2. Menelusuri pemanggilnya di dalam kelas yang sama (`$this->`/`parent::`) dan di kelas turunan (`extends`).
3. Memetakan ke method controller berdasarkan FQCN dari `use`.
4. Memetakan ke baris route.

Hasilnya 191 route. Ditambah 11 route Raptor yang ditelusuri manual, karena service-nya memanggil kelas lain dengan
`new X` atau `X::forRoute(...)`:

- `RaptorInvoiceService::index` memanggil `TransactionList`;
- `transaction` memanggil `BulkTransactionList`;
- `summaryDetail` memanggil `PendingSummary`/`FailedSummary`;
- `RaptorDepositService::index` memanggil `TransactionList`.

**Tidak dipasangi**, karena tidak ada route yang menjangkaunya (kode mati):

- `CustomerOpeningBalanceService::index` dan `SupplierOpeningBalanceService::index`: satu-satunya pemanggil sudah
  dikomentari di `TemplateExcelService.php:94-95`.
- `CustomerService::device`: method controller `CustomerController@device` tidak memiliki route.
