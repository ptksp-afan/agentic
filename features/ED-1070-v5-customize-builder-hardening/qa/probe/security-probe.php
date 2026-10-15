<?php
/**
 * ED-1070 AC-2 / AC-3 - proba keamanan AMAN (in-process); cara kerja & dua lapis pengaman: lihat lab.php. Dari agentic/:
 *   "$PHP_BIN" features/ED-1070-v5-customize-builder-hardening/qa/probe/security-probe.php [--out=<file.json>]
 */

namespace Probe;

require __DIR__ . '/lab.php';

// ------------------------------------------------------------------------------------------------ main

$runDir = QA_AGENTIC . '/work/qa-ED-1070/security-' . date('Ymd-His');
Lab::boot($runDir);

$folder = '250224000117709896682704327481'; // folder "Backup Arsip" (golden archives@folder)
$lists = [
    'archives' => 'api/v5/document-archive/archives?pagination=10',
    'archives@folder' => 'api/v5/document-archive/archives?pagination=10&id_archive=' . $folder,
    'customers' => 'api/v5/customer/customers?pagination=10',
    'purchase-orders' => 'api/v5/purchase/purchase-orders?pagination=10',
    'sales-invoices' => 'api/v5/sales/sales-invoices?pagination=10',
    'items' => 'api/v5/item/items?pagination=10',
    'suppliers' => 'api/v5/supplier/suppliers?pagination=10',
];
$tables = ['archives', 'customers', 'purchase_orders', 'sales_invoices', 'items', 'suppliers'];
// [id, AC, search, jumlah entri berbahaya, filter sah yang harus tetap berlaku (null = tanpa)]
$probes = [
    ['delete()', 'AC-2', ['delete()' => 1], 1, null],
    ['update()', 'AC-2', ['update()' => ['is_active' => 9]], 1, null],
    ['truncate()', 'AC-2', ['truncate()' => 'x'], 1, null],
    ['whereRaw()', 'AC-2', ['whereRaw()' => '1=1'], 1, null],
    ['latest', 'AC-3', ['latest' => ['a' => 'b']], 1, null],
    ['getTable', 'AC-3', ['getTable' => ['a' => 'b']], 1, null],
    ['truncate', 'AC-3', ['truncate' => ['a' => 'b']], 1, null],
    ['sumX;DROP', 'AC-3', ['sumX;DROP' => ['>=', '1']], 1, null],
    ['campuran-3', 'AC-2/3', ['delete()' => 1, 'truncate' => ['a' => 'b'], 'whereRaw()' => '1=1'], 3, null],
    ['query+delete()', 'AC-2', ['query' => 'a', 'delete()' => 1, 'truncate()' => 'x'], 2, ['query' => 'a']],
];

// engine + fingerprint (read-only)
$fp = function () use ($tables) {
    return Lab::readOnly(function ($c) use ($tables) {
        $out = [];
        foreach ($tables as $t) {
            $row = $c->selectOne('CHECKSUM TABLE `' . $t . '`');
            $out[$t] = ['count' => $c->table($t)->count(), 'checksum' => $row ? (string) ($row->Checksum ?? '') : null];
        }
        $eng = $c->select('SELECT table_name AS t, engine AS e FROM information_schema.tables WHERE table_schema = database() AND table_name IN (' . implode(',', array_fill(0, count($tables), '?')) . ')', $tables);
        $out['_engines'] = array_map(function ($r) {
            return $r->t . '=' . $r->e;
        }, $eng);

        return $out;
    });
};
// swa-uji pengaman: koneksi tenant & global = GuardedMySqlConnection, TRUNCATE/DELETE ditolak sebelum dikirim.
// Tabel tujuan sengaja TIDAK ADA (qa_ed1070_tidak_ada), jadi walau pengaman gagal, server hanya membalas "table doesn't exist".
$self = Lab::fork(function () {
    $out = [];
    foreach (array_unique([Lab::$global, Lab::$db]) as $name) {
        $c = Lab::$app['db']->connection($name);
        $out[$name]['class'] = get_class($c);
        foreach (['TRUNCATE TABLE `qa_ed1070_tidak_ada`', 'DELETE FROM `qa_ed1070_tidak_ada` WHERE 1=0', 'SELECT 1; DROP TABLE `qa_ed1070_tidak_ada`'] as $sql) {
            Guard::$armed = true;
            try {
                $c->statement($sql);
                $out[$name][$sql] = 'EXECUTED';
            } catch (\Throwable $e) {
                $out[$name][$sql] = strpos($e->getMessage(), 'QA-PROBE-GUARD') === 0 ? 'blocked' : 'server-error';
            }
            Guard::$armed = false;
        }
    }

    return $out;
});
err('swa-uji pengaman: ' . json_encode($self));
foreach ($self as $name => $r) {
    if (!is_array($r) || ($r['class'] ?? '') !== 'Probe\\GuardedMySqlConnection' || count(array_filter($r, function ($v) {
        return $v === 'blocked';
    })) !== 3) {
        fail('swa-uji pengaman gagal untuk ' . $name . ' - proba dibatalkan');
    }
}

$fpBefore = $fp();
err('fingerprint sebelum: ' . json_encode($fpBefore));
Lab::login();

$results = [];
$fails = [];
$stripSchema = function ($sql) {
    return array_values(array_filter((array) $sql, function ($s) {
        return stripos($s, 'information_schema') === false;
    }));
};
foreach ($lists as $name => $uri) {
    $base = Lab::fork(function () use ($uri) {
        return Lab::dispatch($uri);
    });
    $baseFiltered = [];
    // pembanding "search hanya berisi kunci tanpa efek" (nilai null dilewati trait tanpa log): ArchiveService memakai mode
    // "mencari" (semua level) bila search punya kunci apa pun selain showRelatedTransaction - pembanding mode itu
    $noop = Lab::fork(function () use ($uri) {
        return Lab::dispatch($uri . '&search=' . rawurlencode('{"zzzQaNoop":null}'));
    });
    $results[$name]['_base'] = ['status' => $base['status'] ?? null, 'total' => $base['total'] ?? null, 'n_sql' => isset($base['sql']) ? count($base['sql']) : null, 'err' => $base['child_error'] ?? null,
        'noop_status' => $noop['status'] ?? null, 'noop_total' => $noop['total'] ?? null, 'noop_log' => [$noop['log_blocked'] ?? null, $noop['log_malformed'] ?? null], 'noop_sql_eq_base' => ($noop['sql'] ?? null) === $base['sql']];
    if (($base['status'] ?? 0) !== 200) {
        $fails[] = "$name base status " . json_encode($base['status'] ?? $base);
        continue;
    }
    foreach ($probes as $p) {
        list($pid, $ac, $search, $nBad, $legit) = $p;
        $ref = $noop;
        if ($legit !== null) {
            $key = json_encode($legit);
            if (!isset($baseFiltered[$key])) {
                $baseFiltered[$key] = Lab::fork(function () use ($uri, $legit) {
                    return Lab::dispatch($uri . '&search=' . rawurlencode(json_encode($legit)));
                });
            }
            $ref = $baseFiltered[$key];
        }
        $res = Lab::fork(function () use ($uri, $search) {
            return Lab::dispatch($uri . '&search=' . rawurlencode(json_encode($search)));
        });
        $checks = [
            'status200' => ($res['status'] ?? null) === 200,
            'total_eq_ref' => array_key_exists('total', $res) && $res['total'] === $ref['total'],
            'total_eq_base' => $legit !== null || (array_key_exists('total', $res) && $res['total'] === $base['total']),
            // SQL sama dengan pembanding, kecuali kueri skema information_schema (getTableColumns() dibaca sebelum entri
            // dinilai; pembanding noop berhenti lebih awal karena nilainya null) - kueri baca skema, bukan filter
            'sql_eq_ref' => isset($res['sql']) && $stripSchema($res['sql']) === $stripSchema($ref['sql']),
            'log_blocked_eq' => ($res['log_blocked'] ?? -1) === $nBad,
            'log_malformed_0' => ($res['log_malformed'] ?? -1) === 0,
            'log_error_0' => ($res['log_error'] ?? -1) === 0,
            'no_dml_ddl' => isset($res['dml']) && $res['dml'] === [],
            'not_tripped' => isset($res['tripped']) && $res['tripped'] === [],
        ];
        $bad = array_keys(array_filter($checks, function ($v) {
            return !$v;
        }));
        $results[$name][$pid] = ['ac' => $ac, 'status' => $res['status'] ?? null, 'total' => $res['total'] ?? null, 'ref_total' => $ref['total'] ?? null, 'base_total' => $base['total'] ?? null,
            'log_blocked' => $res['log_blocked'] ?? null, 'log_keys' => $res['log_keys'] ?? null, 'log_malformed' => $res['log_malformed'] ?? null,
            'dml' => $res['dml'] ?? null, 'tripped' => $res['tripped'] ?? null, 'fail' => $bad, 'err' => $res['child_error'] ?? ($res['error_head'] ?? null)];
        if ($bad) {
            $fails[] = "$name $pid: " . implode(',', $bad) . ' ' . json_encode(['st' => $res['status'] ?? null, 'tot' => $res['total'] ?? null, 'ref' => $ref['total'] ?? null, 'blk' => $res['log_blocked'] ?? null, 'mal' => $res['log_malformed'] ?? null, 'err' => $res['child_error'] ?? null]);
        }
        echo sprintf("%-16s %-15s %-6s st=%s total=%s ref=%s base=%s blocked=%s %s\n", $name, $pid, $ac, json_encode($res['status'] ?? null), json_encode($res['total'] ?? null), json_encode($ref['total'] ?? null), json_encode($base['total'] ?? null), json_encode($res['log_blocked'] ?? null), $bad ? 'FAIL ' . implode(',', $bad) : 'PASS');
    }
}
Lab::logout();
$fpAfter = $fp();
$same = $fpBefore === $fpAfter;
err('fingerprint sesudah: ' . json_encode($fpAfter) . ' identik=' . ($same ? 'ya' : 'TIDAK'));
if (!$same) {
    $fails[] = 'fingerprint berubah';
}
$out = opt('out', $runDir . '/result.json');
file_put_contents($out, json_encode(['run_dir' => $runDir, 'fingerprint_before' => $fpBefore, 'fingerprint_after' => $fpAfter, 'fingerprint_same' => $same,
    'results' => $results, 'fails' => $fails], P_JSON | JSON_PRETTY_PRINT));
echo 'HASIL: ' . ($fails ? 'FAIL ' . count($fails) : 'PASS') . " ; detail $out\n";
foreach ($fails as $f) {
    echo "  - $f\n";
}
exit($fails ? 1 : 0);
