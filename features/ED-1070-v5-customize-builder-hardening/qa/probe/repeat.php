<?php
/**
 * ED-1070 AC-1 diagnosa DIFF golden: putar ulang request yang sama N kali in-process (kode sesudah) dan bandingkan ids
 * antar-ulangan + kunci sort pada posisi yang berbeda. Hanya GET (lab.php: penolak tulis/DDL + rollback).
 *   "$PHP_BIN" .../qa/probe/repeat.php <n> <uri> [<uri> ...]
 */

namespace Probe;

require __DIR__ . '/lab.php';

$n = (int) ($argv[1] ?? 3);
$uris = array_slice($argv, 2);
Lab::boot(QA_AGENTIC . '/work/qa-ED-1070/repeat-' . date('Ymd-His'));
Lab::login();
foreach ($uris as $uri) {
    $runs = [];
    for ($i = 0; $i < $n; $i++) {
        $runs[] = Lab::fork(function () use ($uri) {
            $d = Lab::dispatch($uri);
            $ids = [];
            foreach ($d['json_rows'] as $row) {
                $ids[] = is_array($row) ? sha1(json_encode($row)) : '?';
            }
            $main = null;
            foreach ($d['sql'] as $l) {
                if (stripos($l, ' limit ') !== false && stripos($l, 'select count(*)') === false) {
                    $main = $l;
                }
            }

            return ['status' => $d['status'], 'rows' => $ids, 'main_sql_sha' => sha1((string) $main), 'n_sql' => count($d['sql'])];
        });
    }
    $sig = array_map(function ($r) {
        return sha1(json_encode($r['rows'] ?? null));
    }, $runs);
    echo $uri, "\n  status=", json_encode(array_column($runs, 'status')), ' rows_sig=', json_encode(array_map(function ($s) {
        return substr($s, 0, 8);
    }, $sig)), ' main_sql=', json_encode(array_map(function ($r) {
        return substr($r['main_sql_sha'] ?? '', 0, 8);
    }, $runs)), ' n_sql=', json_encode(array_column($runs, 'n_sql')), ' distinct_orders=', count(array_unique($sig)), "\n";
}
Lab::logout();
