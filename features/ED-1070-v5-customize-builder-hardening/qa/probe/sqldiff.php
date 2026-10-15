<?php
/**
 * ED-1070 diagnosa: SQL dua request in-process (lab.php) dan bedanya (baris yang hanya ada di salah satu).
 *   "$PHP_BIN" .../qa/probe/sqldiff.php <uriA> <uriB>
 */

namespace Probe;

require __DIR__ . '/lab.php';

Lab::boot(QA_AGENTIC . '/work/qa-ED-1070/sqldiff-' . date('Ymd-His'));
Lab::login();
$a = Lab::fork(function () use ($argv) {
    $d = Lab::dispatch($argv[1]);
    return ['status' => $d['status'], 'sql' => $d['sql'], 'tripped' => $d['tripped'], 'dml' => $d['dml']];
});
$b = Lab::fork(function () use ($argv) {
    $d = Lab::dispatch($argv[2]);
    return ['status' => $d['status'], 'sql' => $d['sql'], 'tripped' => $d['tripped'], 'dml' => $d['dml']];
});
echo 'A status=', $a['status'], ' n_sql=', count($a['sql']), ' dml=', json_encode($a['dml']), ' tripped=', json_encode($a['tripped']), "\n";
echo 'B status=', $b['status'], ' n_sql=', count($b['sql']), ' dml=', json_encode($b['dml']), ' tripped=', json_encode($b['tripped']), "\n";
$onlyA = array_values(array_diff($a['sql'], $b['sql']));
$onlyB = array_values(array_diff($b['sql'], $a['sql']));
echo "hanya A:\n";
foreach ($onlyA as $s) {
    echo '  ', substr($s, 0, 300), "\n";
}
echo "hanya B:\n";
foreach ($onlyB as $s) {
    echo '  ', substr($s, 0, 300), "\n";
}
$strip = function ($xs) {
    return array_values(array_filter($xs, function ($s) {
        return stripos($s, 'information_schema') === false;
    }));
};
echo 'sama persis tanpa kueri information_schema: ', $strip($a['sql']) === $strip($b['sql']) ? 'YA' : 'TIDAK', "\n";
Lab::logout();
