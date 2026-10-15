<?php
/**
 * ED-1070 AC-7 (#9 sort relasi FK != PK) - in-process (lab.php: HTTP kernel BE_DIR, koneksi penolak tulis/DDL, rollback).
 *   "$PHP_BIN" features/ED-1070-v5-customize-builder-hardening/qa/probe/sort9.php [--only=1,2] [--out=<file.json>]
 *
 * Per baris relation-sort-9.md (asc & desc, pagination=100000 = seluruh list):
 *   1. status 200;
 *   2. SQL kueri utama memuat subquery sort relasi target dengan pasangan kolom BARU (related.kunci = parent.FK baru) dan
 *      tidak memuat pasangan LAMA;
 *   3. oracle urutan: kunci target dihitung ULANG dengan join manual FK baru (relation-sort-9.md, bukan dari SQL app);
 *      butir ORDER BY lain (mis. relasi FK=PK lain dengan kolom bernama sama) diambil dari SQL. Barisan tuple kunci sesuai
 *      urutan respons = barisan tuple kunci hasil ORDER BY manual atas id yang sama (huruf kecil + trim; seri boleh beda id).
 * Hanya SELECT (sesudah request di-rollback). Tidak ada baris dibuat.
 */

namespace Probe;

require __DIR__ . '/lab.php';

// [no, route, extra query, sortBy, parent, pk, tipe(BT|HO|HM), kolom parent (FK baru / local key), related, kolom related (owner / FK), kolom, pasangan lama "related.x=parent.y"]
$ROWS = [
    [1, 'api/v5/item/adjustment-types', [], 'accountCode', 'adjustment_types', 'id_adjustment_type', 'BT', 'id_account_adjustment', 'accounts', 'id_account', 'account_code', 'accounts.id_account=adjustment_types.id_account'],
    [2, 'api/v5/item/adjustment-types', [], 'accountName', 'adjustment_types', 'id_adjustment_type', 'BT', 'id_account_adjustment', 'accounts', 'id_account', 'account_name', 'accounts.id_account=adjustment_types.id_account'],
    [3, 'api/v5/company/carriers', [], 'measureUnitCode', 'carriers', 'id_carrier', 'BT', 'id_weight_unit', 'measure_units', 'id_measure_unit', 'measure_unit_code', 'measure_units.id_measure_unit=carriers.id_measure_unit'],
    [4, 'api/v5/company/carriers', [], 'measureUnitCode', 'carriers', 'id_carrier', 'BT', 'id_volume_unit', 'measure_units', 'id_measure_unit', 'measure_unit_code', 'measure_units.id_measure_unit=carriers.id_measure_unit'],
    [5, 'api/v5/supplier/couriers', [], 'firstName', 'couriers', 'id_courier', 'BT', 'id_related_employee', 'employees', 'id_employee', 'first_name', 'employees.id_employee=couriers.id_employee'],
    [6, 'api/v5/document-archive/archives', ['id_archive' => '250224000117709896682704327481'], 'relatedEmployeeName', 'archives', 'id_archive', 'HO', 'id_archive', 'archive_documents', 'id_archive', 'related_employee_name', 'archive_documents.id_archive_document=archives.id_archive_document'],
    [7, 'api/v5/employee/employees', [], 'locationName', 'employees', 'id_employee', 'BT', 'id_registered_location', 'locations', 'id_location', 'location_name', 'locations.id_location=employees.id_location'],
    [8, 'api/v5/financial/interbank-transfers', [], 'cashBankName', 'interbank_transfers', 'id_interbank_transfer', 'BT', 'withdraw_id_cash_bank', 'cash_banks', 'id_cash_bank', 'cash_bank_name', 'cash_banks.id_cash_bank=interbank_transfers.id_cash_bank'],
    [9, 'api/v5/financial/interbank-transfers', [], 'cashBankName', 'interbank_transfers', 'id_interbank_transfer', 'BT', 'deposit_id_cash_bank', 'cash_banks', 'id_cash_bank', 'cash_bank_name', 'cash_banks.id_cash_bank=interbank_transfers.id_cash_bank'],
    [10, 'api/v5/payment/payment-categories', [], 'accountName', 'payment_categories', 'id_payment_category', 'BT', 'id_account_direct_sales', 'accounts', 'id_account', 'account_name', 'accounts.id_account=payment_categories.id_account'],
    [11, 'api/v5/purchase/purchase-orders', [], 'shipTo', 'purchase_orders', 'id_purchase_order', 'HO', 'id_purchase_order', 'purchase_order_deliveries', 'id_purchase_order', 'ship_to', 'purchase_order_deliveries.id_purchase_order_delivery=purchase_orders.id_purchase_order_delivery'],
    [12, 'api/v5/customer/customers', [], 'firstName', 'customers', 'id_customer', 'BT', 'id_related_employee', 'employees', 'id_employee', 'first_name', 'employees.id_employee=customers.id_employee'],
    [13, 'api/v5/salesman-activity/customers', [], 'firstName', 'customers', 'id_customer', 'BT', 'id_related_employee', 'employees', 'id_employee', 'first_name', 'employees.id_employee=customers.id_employee'],
    [14, 'api/v5/salesman-activity/setup/employees', [], 'locationName', 'employees', 'id_employee', 'BT', 'id_registered_location', 'locations', 'id_location', 'location_name', 'locations.id_location=employees.id_location'],
    [15, 'api/v5/select/salesman-activity/salesman-activity/sales-orders', [], 'firstName', 'sales_orders', 'id_sales_order', 'BT', 'id_salesman', 'employees', 'id_employee', 'first_name', 'employees.id_employee=sales_orders.id_employee'],
    [16, 'api/v5/salesman-activity/salesman-activities', [], 'checkIn', 'salesman_activities', 'id_salesman_activity', 'HO', 'id_salesman_activity', 'attendances', 'id_salesman_activity', 'check_in', 'attendances.id_attendance=salesman_activities.id_attendance'],
    [17, 'api/v5/salesman-activity/salesman-activities', [], 'geotagCheckIn', 'salesman_activities', 'id_salesman_activity', 'HO', 'id_salesman_activity', 'attendances', 'id_salesman_activity', 'geotag_check_in', 'attendances.id_attendance=salesman_activities.id_attendance'],
    [18, 'api/v5/salesman-activity/salesman-activities', [], 'checkOut', 'salesman_activities', 'id_salesman_activity', 'HO', 'id_salesman_activity', 'attendances', 'id_salesman_activity', 'check_out', 'attendances.id_attendance=salesman_activities.id_attendance'],
    [19, 'api/v5/salesman-activity/salesman-activities', [], 'geotagCheckOut', 'salesman_activities', 'id_salesman_activity', 'HO', 'id_salesman_activity', 'attendances', 'id_salesman_activity', 'geotag_check_out', 'attendances.id_attendance=salesman_activities.id_attendance'],
    [20, 'api/v5/salesman-activity/salesman-activities', [], 'noteCheckOut', 'salesman_activities', 'id_salesman_activity', 'HO', 'id_salesman_activity', 'attendances', 'id_salesman_activity', 'note_check_out', 'attendances.id_attendance=salesman_activities.id_attendance'],
    [21, 'api/v5/select/sales/billing/old-sales-invoices', [], 'firstName', 'sales_invoices', 'id_sales_invoice', 'BT', 'id_salesman', 'employees', 'id_employee', 'first_name', 'employees.id_employee=sales_invoices.id_employee'],
    [22, 'api/v5/select/customer/customer/sales-orders', [], 'firstName', 'sales_orders', 'id_sales_order', 'BT', 'id_salesman', 'employees', 'id_employee', 'first_name', 'employees.id_employee=sales_orders.id_employee'],
    [23, 'api/v5/sales/shipments', [], 'measureUnitCode', 'shipments', 'id_shipment', 'BT', 'id_weight_unit', 'measure_units', 'id_measure_unit', 'measure_unit_code', 'measure_units.id_measure_unit=shipments.id_measure_unit'],
    [24, 'api/v5/sales/shipments', [], 'measureUnitCode', 'shipments', 'id_shipment', 'BT', 'id_volume_unit', 'measure_units', 'id_measure_unit', 'measure_unit_code', 'measure_units.id_measure_unit=shipments.id_measure_unit'],
    [27, 'api/v5/tax/taxes', [], 'accountName', 'taxes', 'id_tax', 'BT', 'id_account_sale', 'accounts', 'id_account', 'account_name', 'accounts.id_account=taxes.id_account'],
    [28, 'api/v5/tax/taxes', [], 'accountName', 'taxes', 'id_tax', 'BT', 'id_account_purchase', 'accounts', 'id_account', 'account_name', 'accounts.id_account=taxes.id_account'],
    [29, 'api/v5/select/inventory/item-transfer-location/unreceive-transactions', [], 'locationName', 'item_transfers', 'id_item_transfer', 'BT', 'id_to_location', 'locations', 'id_location', 'location_name', 'locations.id_location=item_transfers.id_location'],
    [30, 'api/v5/select/inventory/item-transfer-location/unreceive-transactions', [], 'locationName', 'item_transfers', 'id_item_transfer', 'BT', 'id_from_location', 'locations', 'id_location', 'location_name', 'locations.id_location=item_transfers.id_location'],
    [31, 'api/v5/employee/users', [], 'idRole', 'users', 'id_user', 'HM', 'id_user', 'user_roles', 'id_user', 'id_role', 'user_roles.id_user_role=users.id_user_role'],
];

$only = opt('only') ? array_map('intval', explode(',', opt('only'))) : null;
$runDir = QA_AGENTIC . '/work/qa-ED-1070/sort9-' . date('Ymd-His');
Lab::boot($runDir);
Lab::login();

/** ORDER BY kueri utama (yang memuat "limit"), dipecah per butir tingkat atas. */
function orderItems($sql)
{
    $p = stripos($sql, ' order by ');
    if ($p === false) {
        return [];
    }
    $tail = substr($sql, $p + 10);
    $tail = preg_replace('/\s+limit\s+\d+(\s+offset\s+\d+)?\s*$/i', '', $tail);
    $items = [];
    $depth = 0;
    $cur = '';
    for ($i = 0; $i < strlen($tail); $i++) {
        $ch = $tail[$i];
        if ($ch === '(') {
            $depth++;
        } elseif ($ch === ')') {
            $depth--;
        }
        if ($ch === ',' && $depth === 0) {
            $items[] = trim($cur);
            $cur = '';
            continue;
        }
        $cur .= $ch;
    }
    if (trim($cur) !== '') {
        $items[] = trim($cur);
    }
    $out = [];
    foreach ($items as $it) {
        $dir = 'asc';
        if (preg_match('/^(.*)\s+(asc|desc)$/is', $it, $m)) {
            $it = trim($m[1]);
            $dir = strtolower($m[2]);
        }
        $out[] = [$it, $dir];
    }

    return $out;
}

$results = [];
$fails = [];
foreach ($ROWS as $row) {
    list($no, $route, $extra, $sortBy, $parent, $pk, $type, $pcol, $related, $rcol, $col, $old) = $row;
    if ($only && !in_array($no, $only, true)) {
        continue;
    }
    foreach (['asc', 'desc'] as $dir) {
        $q = $extra + ['pagination' => 100000];
        $uri = $route . '?' . http_build_query($q) . '&sorts[]=' . rawurlencode(json_encode(['sortBy' => $sortBy, 'sortType' => $dir]));
        $res = Lab::fork(function () use ($uri, $parent, $pk, $type, $pcol, $related, $rcol, $col, $old, $dir) {
            $d = Lab::dispatch($uri);
            $out = ['status' => $d['status'], 'total' => $d['total'], 'err' => $d['error_head'], 'tripped' => $d['tripped'], 'dml' => $d['dml']];
            if ($d['status'] !== 200) {
                return $out;
            }
            // kueri utama = SELECT dari tabel parent dengan "limit 100000"
            $main = null;
            $mainConn = Lab::$db;
            foreach ($d['sql'] as $line) {
                $sql = explode(' | ', $line)[1] ?? '';
                if (stripos($sql, 'limit 100000') !== false && preg_match('/\bfrom `' . preg_quote($parent, '/') . '`/i', $sql)) {
                    $main = $sql;
                    $mainConn = trim(explode(" | ", $line)[0]);
                }
            }
            $out['main_sql_found'] = $main !== null;
            if ($main === null) {
                return $out;
            }
            $items = orderItems($main);
            $newPair = "`{$related}`.`{$rcol}` = `{$parent}`.`{$pcol}`";
            list($oldL, $oldR) = explode('=', $old);
            $oldPair = '`' . str_replace('.', '`.`', $oldL) . '` = `' . str_replace('.', '`.`', $oldR) . '`';
            $target = null;
            foreach ($items as $i => $it) {
                if (stripos($it[0], "select `{$col}` from `{$related}`") !== false && strpos($it[0], $newPair) !== false) {
                    $target = $i;
                }
            }
            $out['order_items'] = array_map(function ($it) {
                return substr($it[0], 0, 160) . ' ' . $it[1];
            }, $items);
            $out['new_pair_in_sql'] = $target !== null;
            $out['old_pair_in_sql'] = strpos($main, $oldPair) !== false;
            $out['target_dir_ok'] = $target !== null && $items[$target][1] === $dir;
            if ($target === null) {
                return $out;
            }
            // id respons berurutan
            $json = $d['json_rows'];
            $ids = [];
            foreach ($json as $r) {
                $ids[] = (string) ($r[$pk] ?? $r[lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $pk))))] ?? '');
            }
            $out['n'] = count($ids);
            // baris tanpa id (mis. employee/users: 3 baris id_user null) tidak bisa dipetakan ke kunci: dicatat posisinya
            // (kunci NULL: asc = awal, desc = akhir) lalu dikeluarkan dari oracle
            $nullPos = array_keys(array_filter($ids, function ($v) {
                return $v === '';
            }));
            if ($nullPos) {
                $out['null_id_positions'] = $nullPos;
                $ids = array_values(array_filter($ids, 'strlen'));
            }
            if (!$ids) {
                $out['oracle'] = 'kosong';

                return $out;
            }
            // oracle: target = join manual FK baru (spesifikasi), butir lain = ekspresi SQL app
            $c = Lab::$app["db"]->connection($mainConn); // koneksi yang sama dengan kueri utama (mis. users = DB global)
            $out["conn"] = $mainConn;
            if ($type === 'BT') {
                $targetExpr = "(select r9.`{$col}` from `{$related}` r9 where r9.`{$rcol}` = `{$parent}`.`{$pcol}` limit 1)";
            } else {
                $targetExpr = "(select r9.`{$col}` from `{$related}` r9 where r9.`{$rcol}` = `{$parent}`.`{$pcol}` order by r9.`{$col}` limit 1)";
            }
            $sel = [];
            $ord = [];
            foreach ($items as $i => $it) {
                $expr = $i === $target ? $targetExpr : $it[0];
                $sel[] = $expr . ' as k' . $i;
                $ord[] = 'k' . $i . ' ' . $it[1];
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $rows = $c->select('select `' . $parent . '`.`' . $pk . '` as id, ' . implode(', ', $sel) . ' from `' . $parent . '` where `' . $parent . '`.`' . $pk . '` in (' . $in . ')', $ids);
            $tuple = [];
            foreach ($rows as $r) {
                $a = (array) $r;
                $id = (string) $a['id'];
                unset($a['id']);
                $tuple[$id] = implode("\x1f", array_map(function ($v) {
                    return mb_strtolower(trim((string) $v));
                }, array_values($a)));
            }
            $got = [];
            foreach ($ids as $id) {
                $got[] = $tuple[$id] ?? '<id?>';
            }
            $expRows = $c->select('select x.* from (select `' . $parent . '`.`' . $pk . '` as id, ' . implode(', ', $sel) . ' from `' . $parent . '` where `' . $parent . '`.`' . $pk . '` in (' . $in . ')) x order by ' . implode(', ', $ord), $ids);
            $exp = [];
            foreach ($expRows as $r) {
                $exp[] = $tuple[(string) $r->id];
            }
            $out['oracle'] = $got === $exp ? 'cocok' : 'BEDA';
            if ($got !== $exp) {
                foreach ($got as $i => $g) {
                    if ($g !== ($exp[$i] ?? null)) {
                        $out["first_diff"] = ["pos" => $i, "got" => $g, "exp" => $exp[$i] ?? null, "n_got" => count($got), "n_exp" => count($exp)];
                        break;
                    }
                }
            }
            // pembeda: kunci target via FK lama (bila kolom lama ada) menghasilkan barisan lain?
            $out['distinct_target_keys'] = count(array_unique(array_map(function ($t) {
                return $t;
            }, $got)));

            return $out;
        });
        $ok = ($res['status'] ?? null) === 200 && !empty($res['new_pair_in_sql']) && empty($res['old_pair_in_sql']) && !empty($res['target_dir_ok'])
            && in_array($res['oracle'] ?? null, ['cocok', 'kosong'], true) && empty($res['tripped']) && empty($res['dml']);
        $results[$no][$dir] = $res;
        $line = sprintf('#%-2d %-4s %-62s st=%s n=%s pairBaru=%s pairLama=%s oracle=%s %s', $no, $dir, substr($route, 7), json_encode($res['status'] ?? null), json_encode($res['n'] ?? null),
            json_encode($res['new_pair_in_sql'] ?? null), json_encode($res['old_pair_in_sql'] ?? null), $res['oracle'] ?? '-', $ok ? 'PASS' : 'FAIL ' . substr((string) ($res['err'] ?? $res['child_error'] ?? ''), 0, 160));
        echo $line, "\n";
        if (!$ok) {
            $fails[] = $line;
        }
    }
}
$out = opt('out', $runDir . '/result.json');
file_put_contents($out, json_encode(['results' => $results, 'fails' => $fails], P_JSON | JSON_PRETTY_PRINT));
echo 'HASIL: ' . ($fails ? 'FAIL ' . count($fails) : 'PASS') . " ; detail $out\n";
Lab::logout();
exit($fails ? 1 : 0);
