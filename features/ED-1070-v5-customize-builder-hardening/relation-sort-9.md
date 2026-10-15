# ED-1070 #9 - kolom sort relasi dengan FK != PK (daftar untuk penguji manusia + QA)

Sort kolom relasi memakai `foreign_key` = `getKeyName()` (PK model relasi) di **kedua** sisi join
(`CustomizeBuilder.php:235-243`). Benar hanya saat nama FK di tabel induk kebetulan = PK relasi. Untuk
kolom di bawah, FK induk != PK relasi, sehingga sort sekarang **500** (kolom FK tidak ada di tabel induk)
atau salah urut. Perbaikan (#7 desain): ambil pasangan kolom dari objek `Relation`
(`BelongsTo`: `getForeignKey()`/`getOwnerKey()`; `HasOne/Many`: `getForeignKeyName()`/`getLocalKeyName()`).

Dibuat dengan refleksi atas `Modules/V5/Config/displayColumn/**` (semua seksi `current/available/child/
grand_child_columns` + `children`) di DB `api_sidomaju` (2026-10-15): tiap `data_index` bentuk `[relasi, kolom]`
dengan `sort: true`, model V5 yang dipetakan dari `selectBuilderQuery('<modul>')` statik, lalu bandingkan
pasangan FK lama vs baru. Hanya baris yang **berubah** (lama != baru) yang ada di sini; relasi FK = PK
(mayoritas, mis. `customerType`, `location` pada banyak modul) tidak berubah.

**AC-#9 (QA):** untuk tiap baris, `GET <route> ?sorts[]={"sortBy":"<kolom>","sortType":"asc"}` (dan `desc`)
→ 200 (sebelumnya 500), dan urutan `id` sesuai kolom relasi `baru`. Oracle urutan: bandingkan dengan query
manual yang join pada FK `baru`. Jalankan terhadap baris QA sendiri / data yang tidak ditulis ulang. Semua
kolom sort relasi lain pada list yang sama (FK = PK) **tetap** urutannya (ambil sampel ≥1 per list).

Catatan HasOne dengan anak ganda: `salesman_activities` punya 15 baris dengan >1 `attendances`
(`id_salesman_activity`); sort subquery `related.{FK}={parent.PK}` bisa mengembalikan >1 nilai → MySQL
memakai baris pertama (bukan error). Penguji cukup memastikan 200 + stabil.

| # | Modul display | Kolom (judul) | relasi.kolom | tipe | lama (FK join) | baru (FK join) | Route list | halaman
|---|---|---|---|---|---|---|---|---|
| 1 | adjustmentType | Kode Akun Penyesuaian | accountAdjustment.accountCode | BelongsTo | adjustment_types.id_account=accounts.id_account | adjustment_types.id_account_adjustment=accounts.id_account | item/adjustment-types | |
| 2 | adjustmentType | Nama Akun Penyesuaian | accountAdjustment.accountName | BelongsTo | adjustment_types.id_account=accounts.id_account | adjustment_types.id_account_adjustment=accounts.id_account | item/adjustment-types | |
| 3 | carrier | Satuan Berat | weightUnit.measureUnitCode | BelongsTo | carriers.id_measure_unit=measure_units.id_measure_unit | carriers.id_weight_unit=measure_units.id_measure_unit | company/carriers | |
| 4 | carrier | Satuan Volume | volumeUnit.measureUnitCode | BelongsTo | carriers.id_measure_unit=measure_units.id_measure_unit | carriers.id_volume_unit=measure_units.id_measure_unit | company/carriers | |
| 5 | courier | Karyawan Terkait | relatedEmployee.firstName | BelongsTo | couriers.id_employee=employees.id_employee | couriers.id_related_employee=employees.id_employee | supplier/couriers | |
| 6 | documentArchive | Salesman | document.relatedEmployeeName | HasOne | archives.id_archive_document=archive_documents.id_archive_document | archives.id_archive=archive_documents.id_archive | document-archive/archives | |
| 7 | employee | Terdaftar Di | registeredAt.locationName | BelongsTo | employees.id_location=locations.id_location | employees.id_registered_location=locations.id_location | employee/employees | |
| 8 | interbankTransfer | Kas/Bank Penarikan | withdrawCashBank.cashBankName | BelongsTo | interbank_transfers.id_cash_bank=cash_banks.id_cash_bank | interbank_transfers.withdraw_id_cash_bank=cash_banks.id_cash_bank | financial/interbank-transfers | |
| 9 | interbankTransfer | Kas/Bank Penyetoran | depositCashBank.cashBankName | BelongsTo | interbank_transfers.id_cash_bank=cash_banks.id_cash_bank | interbank_transfers.deposit_id_cash_bank=cash_banks.id_cash_bank | financial/interbank-transfers | |
| 10 | paymentCategory | Akun | account.accountName | BelongsTo | payment_categories.id_account=accounts.id_account | payment_categories.id_account_direct_sales=accounts.id_account | payment/payment-categories | |
| 11 | purchaseOrder | Dikirim Ke | purchaseOrderDelivery.shipTo | HasOne | purchase_orders.id_purchase_order_delivery=purchase_order_deliveries.id_purchase_order_delivery | purchase_orders.id_purchase_order=purchase_order_deliveries.id_purchase_order | purchase/purchase-orders | |
| 12 | salesmanActivity.customer | Dikelola Oleh | relatedEmployee.firstName | BelongsTo | customers.id_employee=employees.id_employee | customers.id_related_employee=employees.id_employee | customer/customers (fitur salesman) | |
| 13 | salesmanActivity.customerOnSalesman | Dikelola Oleh | relatedEmployee.firstName | BelongsTo | customers.id_employee=employees.id_employee | customers.id_related_employee=employees.id_employee | salesman-activity/customers | |
| 14 | salesmanActivity.employee | Terdaftar Di | registeredAt.locationName | BelongsTo | employees.id_location=locations.id_location | employees.id_registered_location=locations.id_location | salesman-activity/setup/employees | |
| 15 | salesmanActivity.salesOrder | Nama Salesman | salesman.firstName | BelongsTo | sales_orders.id_employee=employees.id_employee | sales_orders.id_salesman=employees.id_employee | select/salesman-activity/salesman-activity/sales-orders | |
| 16 | salesmanActivity.salesmanActivity | Check In | attendance.checkIn | HasOne | salesman_activities.id_attendance=attendances.id_attendance | salesman_activities.id_salesman_activity=attendances.id_salesman_activity | salesman-activity/salesman-activities | |
| 17 | salesmanActivity.salesmanActivity | Geotag Check In | attendance.geotagCheckIn | HasOne | salesman_activities.id_attendance=attendances.id_attendance | salesman_activities.id_salesman_activity=attendances.id_salesman_activity | salesman-activity/salesman-activities | |
| 18 | salesmanActivity.salesmanActivity | Check Out | attendance.checkOut | HasOne | salesman_activities.id_attendance=attendances.id_attendance | salesman_activities.id_salesman_activity=attendances.id_salesman_activity | salesman-activity/salesman-activities | |
| 19 | salesmanActivity.salesmanActivity | Geotag Check Out | attendance.geotagCheckOut | HasOne | salesman_activities.id_attendance=attendances.id_attendance | salesman_activities.id_salesman_activity=attendances.id_salesman_activity | salesman-activity/salesman-activities | |
| 20 | salesmanActivity.salesmanActivity | Catatan Check Out | attendance.noteCheckOut | HasOne | salesman_activities.id_attendance=attendances.id_attendance | salesman_activities.id_salesman_activity=attendances.id_salesman_activity | salesman-activity/salesman-activities | |
| 21 | selectSalesInvoiceInBilling | Salesman | salesman.firstName | BelongsTo | sales_invoices.id_employee=employees.id_employee | sales_invoices.id_salesman=employees.id_employee | select/sales/billing/old-sales-invoices | |
| 22 | selectSalesOrderInCustomer | Nama Salesman | salesman.firstName | BelongsTo | sales_orders.id_employee=employees.id_employee | sales_orders.id_salesman=employees.id_employee | select/customer/customer/sales-orders | |
| 23 | shipment | Satuan Berat | weightUnit.measureUnitCode | BelongsTo | shipments.id_measure_unit=measure_units.id_measure_unit | shipments.id_weight_unit=measure_units.id_measure_unit | sales/shipments | |
| 24 | shipment | Satuan Volume | volumeUnit.measureUnitCode | BelongsTo | shipments.id_measure_unit=measure_units.id_measure_unit | shipments.id_volume_unit=measure_units.id_measure_unit | sales/shipments | |
| 25 | shipmentDriver | Satuan Berat | weightUnit.measureUnitCode | BelongsTo | shipments.id_measure_unit=measure_units.id_measure_unit | shipments.id_weight_unit=measure_units.id_measure_unit | sales/shipments (user driver) | |
| 26 | shipmentDriver | Satuan Volume | volumeUnit.measureUnitCode | BelongsTo | shipments.id_measure_unit=measure_units.id_measure_unit | shipments.id_volume_unit=measure_units.id_measure_unit | sales/shipments (user driver) | |
| 27 | tax | Akun Penjualan | accountSale.accountName | BelongsTo | taxes.id_account=accounts.id_account | taxes.id_account_sale=accounts.id_account | tax/taxes | |
| 28 | tax | Akun Pembelian | accountPurchase.accountName | BelongsTo | taxes.id_account=accounts.id_account | taxes.id_account_purchase=accounts.id_account | tax/taxes | |
| 29 | unreceivedList | Lokasi Tujuan | toLocation.locationName | BelongsTo | item_transfers.id_location=locations.id_location | item_transfers.id_to_location=locations.id_location | select/inventory/item-transfer-location/unreceive-transactions | |
| 30 | unreceivedList | Lokasi Asal | fromLocation.locationName | BelongsTo | item_transfers.id_location=locations.id_location | item_transfers.id_from_location=locations.id_location | select/inventory/item-transfer-location/unreceive-transactions | |
| 31 | user | Wewenang | userRoles.idRole | HasMany | users.id_user_role=user_roles.id_user_role | users.id_user=user_roles.id_user | employee/users | |

## Relasi yang gagal refleksi `isRelationMethod` (Review Focus 1) - perlu diperiksa dev

Empat `data_index` relasi di config memetakan ke method yang **tidak ada** di model terpetakan (bukan relasi,
atau dipakai lewat join manual di service, bukan `whereHas`/sort trait). Tidak menghasilkan sort relasi;
dicatat agar dev tidak menyangka regex `isRelationMethod` yang salah:

- `employee` / `registeredLocation` (available_columns) - Employee; relasi yang ada = `registeredAt`.
- `purchaseReturn` / `cashBank`, `differenceAccount` (available_columns) - PurchaseReturn.
- `reportSupplier` / `supplier.supplierTypeName` (available_columns) - Supplier.

Plan Task 1 Step 4 tetap wajib: pindai **semua** model V5 (bukan hanya yang terpetakan statik) dan longgarkan
regex bila ada relasi sah (mis. `return\n $this\n ->hasMany(` lintas baris) yang gugur, sebelum implementasi.
