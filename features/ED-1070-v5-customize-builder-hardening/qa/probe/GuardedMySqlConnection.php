<?php
/**
 * ED-1070 proba keamanan: koneksi MySQL yang MENOLAK statement tulis/DDL/multi-statement selama proba (Guard::$armed),
 * SEBELUM statement dikirim ke server. Semua jalur Laravel (select, statement, affectingStatement, unprepared, cursor)
 * lewat Connection::run(), jadi satu titik ini cukup. Dipakai hanya oleh security-probe.php (in-process, QA).
 */

namespace Probe;

use Closure;
use Illuminate\Database\MySqlConnection;

class GuardedMySqlConnection extends MySqlConnection
{
    protected function run($query, $bindings, Closure $callback)
    {
        if (Guard::$armed && Guard::dangerous($query)) {
            Guard::$tripped[] = substr(preg_replace('/\s+/', ' ', (string) $query), 0, 200);
            throw new \RuntimeException('QA-PROBE-GUARD: statement tulis/DDL ditolak sebelum dikirim ke server');
        }

        return parent::run($query, $bindings, $callback);
    }
}
