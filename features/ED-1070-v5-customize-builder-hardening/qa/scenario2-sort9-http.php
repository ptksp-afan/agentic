<?php
/**
 * ED-1070 AC-7 (HTTP php-fpm): tiap baris relation-sort-9.md, sort asc & desc -> 200, tanpa log error, tanpa log
 * CustomizeBuilder. Pasangan join (SQL) dan oracle urutan dibuktikan in-process oleh probe/sort9.php.
 * Baris 25-26 (modul shipmentDriver, user driver) tidak bisa dijalankan dengan QA_USER: jalur kode sama dengan 23-24.
 */

require_once __DIR__ . '/qa_lib.php';

$Q70_SORT9 = [
    1 => ['api/v5/item/adjustment-types', [], 'accountCode'],
    2 => ['api/v5/item/adjustment-types', [], 'accountName'],
    3 => ['api/v5/company/carriers', [], 'measureUnitCode'],
    5 => ['api/v5/supplier/couriers', [], 'firstName'],
    6 => ['api/v5/document-archive/archives', ['id_archive' => '250224000117709896682704327481'], 'relatedEmployeeName'],
    7 => ['api/v5/employee/employees', [], 'locationName'],
    8 => ['api/v5/financial/interbank-transfers', [], 'cashBankName'],
    10 => ['api/v5/payment/payment-categories', [], 'accountName'],
    11 => ['api/v5/purchase/purchase-orders', [], 'shipTo'],
    12 => ['api/v5/customer/customers', [], 'firstName'],
    13 => ['api/v5/salesman-activity/customers', [], 'firstName'],
    14 => ['api/v5/salesman-activity/setup/employees', [], 'locationName'],
    15 => ['api/v5/select/salesman-activity/salesman-activity/sales-orders', [], 'firstName'],
    16 => ['api/v5/salesman-activity/salesman-activities', [], 'checkIn'],
    17 => ['api/v5/salesman-activity/salesman-activities', [], 'geotagCheckIn'],
    18 => ['api/v5/salesman-activity/salesman-activities', [], 'checkOut'],
    19 => ['api/v5/salesman-activity/salesman-activities', [], 'geotagCheckOut'],
    20 => ['api/v5/salesman-activity/salesman-activities', [], 'noteCheckOut'],
    21 => ['api/v5/select/sales/billing/old-sales-invoices', [], 'firstName'],
    22 => ['api/v5/select/customer/customer/sales-orders', [], 'firstName'],
    23 => ['api/v5/sales/shipments', [], 'measureUnitCode'],
    27 => ['api/v5/tax/taxes', [], 'accountName'],
    29 => ['api/v5/select/inventory/item-transfer-location/unreceive-transactions', [], 'locationName'],
    31 => ['api/v5/employee/users', [], 'idRole'],
];

return [
    [
        'id' => 'AC-7-http',
        'title' => 'AC-7 (HTTP): sort kolom relasi FK!=PK (relation-sort-9.md; 4/9/24/28/30 = sortBy sama dengan baris sebelumnya) asc & desc -> 200, tanpa log error/CustomizeBuilder',
        'run' => function ($t) use ($Q70_SORT9) {
            $s = $t->session();
            $seen = [];
            foreach ($Q70_SORT9 as $no => $c) {
                list($path, $extra, $sortBy) = $c;
                foreach (['asc', 'desc'] as $dir) {
                    $uri = q70_url($path, $extra + ['pagination' => 10]) . '&sorts[]=' . rawurlencode(json_encode(['sortBy' => $sortBy, 'sortType' => $dir]));
                    $r = q70_get($t, $s, $uri);
                    $seen[] = "#$no $dir=" . $r[0];
                    q70_expect($t, $r, "#$no $path $sortBy $dir", 0, 0);
                }
            }
            $t->note(implode(' ; ', $seen));
        },
    ],
];
