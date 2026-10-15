<?php
/**
 * ED-1070 - laboratorium in-process bersama untuk proba QA (security-probe.php = AC-2/AC-3, sort9.php = AC-7). PHP 7.3:
 *
 *   "$PHP_BIN" features/ED-1070-v5-customize-builder-hardening/qa/probe/security-probe.php [--out=<file.json>]
 *
 * Kenapa in-process: payload `delete()` / `update()` / `truncate()` / `truncate` TIDAK BOLEH dikirim ke server sungguhan
 * (data developer). Di sini request berjalan lewat HTTP kernel Laravel BE_DIR yang sama (kode yang sama dengan php-fpm),
 * dengan DUA lapis pengaman yang tetap melindungi data walau guard CustomizeBuilder rusak:
 *   1. Koneksi DB (driver mysql) diganti GuardedMySqlConnection: setiap statement tulis/DDL (insert, update, delete,
 *      replace, truncate, alter, create, drop, rename, grant, lock, ...) atau multi-statement (`;` diikuti teks) DILEMPAR
 *      sebelum dikirim ke server dan dicatat sebagai "tripped". TRUNCATE/DDL tidak pernah sampai ke MariaDB.
 *   2. Semua koneksi dibuka dalam transaksi yang selalu di-ROLLBACK (lapis kedua untuk DML; engine tabel dicek InnoDB).
 * Tiap request di-fork (state bersih seperti php-fpm). Log Laravel proses ini diarahkan ke folder run (bukan storage/ BE),
 * jadi hitungan `[CustomizeBuilder][blocked]` per request terbaca persis. Token QA_USER asli (login + bind force_login),
 * hanya di memori, di-log-out di akhir. Tidak ada baris yang dibuat.
 *
 * Dinilai per list x proba: status 200, total = total tanpa search (atau = filter sah saja untuk proba campuran),
 * SQL = SQL tanpa entri berbahaya, tepat N log blocked (N = jumlah entri berbahaya), 0 malformed, 0 statement tulis/DDL
 * di query log, 0 "tripped".
 */

namespace Probe;

use QaHttp\Config;
use QaHttp\Http;

error_reporting(E_ALL);
ini_set('memory_limit', '2048M');
set_time_limit(0);

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../../..')));
define('P_JSON', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

require QA_AGENTIC . '/scripts/qa-http/lib/Outcome.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Config.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Http.php';

function err($m)
{
    fwrite(STDERR, Config::redact($m) . "\n");
}

function fail($m)
{
    err('ERROR: ' . $m);
    exit(2);
}

function opt($name, $default = null)
{
    global $argv;
    foreach ($argv as $a) {
        if (strpos($a, '--' . $name . '=') === 0) {
            return substr($a, strlen($name) + 3);
        }
    }

    return $default;
}

final class Guard
{
    public static $armed = false;
    public static $tripped = [];

    public static function dangerous($sql)
    {
        $s = ltrim((string) $sql);
        if (preg_match('/^\(*\s*(insert|update|delete|replace|truncate|alter|create|drop|rename|grant|revoke|lock|unlock|flush|load|handler|call|do|set|optimize|repair|analyze)\b/i', $s)) {
            return true;
        }

        // multi-statement: ';' diikuti teks apa pun (bukan hanya spasi di akhir)
        return preg_match('/;\s*\S/', $s) === 1;
    }
}

final class Lab
{
    public static $app;
    public static $kernel;
    public static $db;
    public static $global;
    public static $base;
    public static $runDir;
    private static $token = null;
    private static $jti = null;
    private static $n = 0;
    private static $loggedIn = false;
    private static $ppid = null;

    public static function boot($runDir)
    {
        self::$runDir = $runDir;
        @mkdir($runDir . '/cache', 0775, true);
        $root = Config::beDir();
        self::$db = Config::defaultDb();
        self::$base = Config::base();
        self::$ppid = getmypid();
        if (!function_exists('pcntl_fork')) {
            fail('pcntl tidak ada');
        }
        $_ENV['APP_RUNNING_IN_CONSOLE'] = 'false';
        require_once $root . '/vendor/autoload.php';
        require_once __DIR__ . '/GuardedMySqlConnection.php';
        $app = require $root . '/bootstrap/app.php';
        $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
        $app->instance('request', \Illuminate\Http\Request::create(self::$base . '/api/v5', 'GET'));
        $kernel->bootstrap();
        config([
            'logging.channels.daily.path' => $runDir . '/laravel.log',
            'logging.channels.single.path' => $runDir . '/laravel.log',
            'logging.channels.dev_debug.path' => $runDir . '/dev-debug.log',
            'cache.stores.file.path' => $runDir . '/cache',
        ]);
        $app->instance('log', new \Illuminate\Log\LogManager($app));
        $app->forgetInstance('cache');
        $app->forgetInstance('cache.store');
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        // semua koneksi mysql yang dibuat sesudah ini = GuardedMySqlConnection
        \Illuminate\Database\Connection::resolverFor('mysql', function ($pdo, $database, $prefix, $config) {
            return new \Probe\GuardedMySqlConnection($pdo, $database, $prefix, $config);
        });
        self::$app = $app;
        self::$kernel = $kernel;
        self::$global = config('database.default');
        if (config('database.connections.' . self::$db) === null) {
            fail('koneksi ' . self::$db . ' tidak ada');
        }
        self::purge();
    }

    private static function purge()
    {
        foreach (array_keys(self::$app['db']->getConnections()) as $name) {
            self::$app['db']->purge($name);
        }
    }

    public static function fork(callable $fn, $timeout = 600)
    {
        self::purge();
        $file = self::$runDir . '/.child-' . getmypid() . '-' . (self::$n++) . '.json';
        $pid = pcntl_fork();
        if ($pid === -1) {
            fail('fork gagal');
        }
        if ($pid === 0) {
            pcntl_alarm($timeout);
            try {
                $res = $fn();
            } catch (\Throwable $e) {
                $res = ['child_error' => Config::redact(get_class($e) . ': ' . substr($e->getMessage(), 0, 400))];
            }
            file_put_contents($file, json_encode($res, P_JSON));
            try {
                foreach (self::$app['db']->getConnections() as $c) {
                    while ($c->transactionLevel() > 0) {
                        $c->rollBack();
                    }
                    $c->disconnect();
                }
            } catch (\Throwable $e) {
            }
            exit(0);
        }
        pcntl_waitpid($pid, $status);
        if (!is_file($file)) {
            return ['child_error' => 'anak berhenti tanpa hasil'];
        }
        $res = json_decode(file_get_contents($file), true);
        unlink($file);

        return is_array($res) ? $res : ['child_error' => 'hasil anak bukan JSON'];
    }

    public static function login()
    {
        $clientId = (int) Config::get('CLIENT_ID', 4);
        $secret = self::fork(function () use ($clientId) {
            return ['v' => self::$app['db']->connection(self::$global)->table('oauth_clients')->where('id', $clientId)->value('secret')];
        });
        if (empty($secret['v'])) {
            fail('client secret tidak terbaca');
        }
        Config::addSecret($secret['v']);
        list($st, , $json) = Http::request('POST', 'api/v5/auth/token', null, [
            'username' => Config::get('QA_USER'), 'password' => Config::get('QA_PASS'), 'grant_type' => 'password',
            'client_id' => $clientId, 'client_secret' => $secret['v'],
        ]);
        $token = $json['result']['access_token'] ?? null;
        Config::addSecret($token);
        Config::addSecret($json['result']['refresh_token'] ?? null);
        if (!$token) {
            fail("login QA_USER gagal: HTTP {$st}");
        }
        self::$token = $token;
        self::$loggedIn = true;
        register_shutdown_function([self::class, 'logout']);
        $parts = explode('.', $token);
        $payload = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
        self::$jti = $payload['jti'] ?? null;
        list($st, , $json) = Http::request('PUT', 'api/v5/auth/database', $token, ['db_name' => self::$db, 'force_login' => 1]);
        if ($st !== 200 || ($json['result']['database']['db_name'] ?? null) !== self::$db) {
            fail('bind gagal: HTTP ' . $st);
        }
        err('login QA_USER + bind ' . self::$db . ' OK');
    }

    public static function logout()
    {
        if (!self::$loggedIn || getmypid() !== self::$ppid) {
            return;
        }
        self::$loggedIn = false;
        list($st) = Http::request('GET', 'api/v5/auth/log-out', self::$token, null, 30);
        $jti = self::$jti;
        $left = self::fork(function () use ($jti) {
            return ['n' => self::$app['db']->connection(self::$global)->table('oauth_access_tokens')->where('id', $jti)->where('revoked', 0)->count()];
        });
        err("log-out HTTP {$st}; token masih aktif: " . json_encode($left['n'] ?? $left));
    }

    private static function norm($sql)
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        return preg_replace('/\b\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?\b/', '<DT>', $sql);
    }

    /** Di anak: GET $uri lewat HTTP kernel, guard koneksi aktif, semua koneksi dalam transaksi -> rollback. */
    public static function dispatch($uri)
    {
        $app = self::$app;
        $queries = [];
        $app['events']->listen(\Illuminate\Database\Events\QueryExecuted::class, function ($e) use (&$queries) {
            $b = [];
            foreach ((array) $e->bindings as $v) {
                $b[] = $v instanceof \DateTimeInterface ? '<DT>' : (is_string($v) ? (self::$jti !== null && $v === self::$jti ? '<JTI>' : preg_replace('/\b\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?\b/', '<DT>', $v)) : $v);
            }
            $queries[] = [$e->connectionName, self::norm($e->sql), $b];
        });
        foreach (array_unique([self::$global, self::$db]) as $c) {
            $app['db']->connection($c)->beginTransaction();
        }
        $logFile = self::logFile();
        $before = is_file($logFile) ? filesize($logFile) : 0;
        Guard::$armed = true;
        $request = \Illuminate\Http\Request::create(self::$base . '/' . ltrim($uri, '/'), 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::$token,
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
        $response = self::$kernel->handle($request);
        try {
            self::$kernel->terminate($request, $response);
        } catch (\Throwable $e) {
        }
        Guard::$armed = false;
        foreach ($app['db']->getConnections() as $c) {
            while ($c->transactionLevel() > 0) {
                $c->rollBack();
            }
        }
        clearstatcache();
        $new = is_file($logFile) ? (string) file_get_contents($logFile, false, null, $before) : '';
        $json = json_decode($response->getContent(), true);
        $r = is_array($json) ? ($json['result'] ?? null) : null;
        $dml = [];
        foreach ($queries as $q) {
            if (preg_match('/^\(*\s*(insert|update|delete|replace|truncate|alter|create|drop|rename)\b/i', $q[1]) || preg_match('/\b(truncate|drop)\s+table\b/i', $q[1])) {
                $dml[] = substr($q[0] . ': ' . $q[1], 0, 200);
            }
        }
        $lines = preg_split('/\n(?=\[\d{4}-\d{2}-\d{2} )/', $new, -1, PREG_SPLIT_NO_EMPTY);
        $blocked = $malformed = $errors = 0;
        $logKeys = [];
        foreach ($lines as $l) {
            if (strpos($l, '[CustomizeBuilder][blocked]') !== false) {
                $blocked++;
                if (preg_match('/"key":"((?:[^"\\\\]|\\\\.)*)"/', $l, $m)) {
                    $logKeys[] = stripslashes($m[1]);
                }
            }
            if (strpos($l, '[CustomizeBuilder][malformed]') !== false) {
                $malformed++;
            }
            if (preg_match('/^\[[^\]]+\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY):/', $l)) {
                $errors++;
            }
        }

        return [
            'status' => $response->getStatusCode(),
            'total' => is_array($r) ? ($r['total'] ?? null) : null,
            'code' => is_array($json) ? ($json['msg_code'] ?? $json['code'] ?? null) : null,
            'sql' => array_map(function ($q) {
                return $q[0] . ' | ' . $q[1] . ' | ' . json_encode($q[2], P_JSON);
            }, $queries),
            'dml' => $dml,
            'tripped' => Guard::$tripped,
            'log_blocked' => $blocked,
            'log_malformed' => $malformed,
            'log_error' => $errors,
            'log_keys' => $logKeys,
            'error_head' => $response->getStatusCode() >= 500 ? Config::redact(substr($response->getContent(), 0, 300)) : null,
            // baris data (hanya dipakai di anak, mis. sort9.php; jangan dikembalikan utuh ke induk)
            'json_rows' => is_array($r) ? (isset($r['data']) && is_array($r['data']) ? $r['data'] : (array_keys($r) === range(0, count($r) - 1) ? $r : [])) : [],
        ];
    }

    public static function logFile()
    {
        return self::$runDir . '/laravel-' . date('Y-m-d') . '.log';
    }

    public static function readOnly(callable $fn)
    {
        return self::fork(function () use ($fn) {
            $c = self::$app['db']->connection(self::$db);
            $c->statement('SET SESSION TRANSACTION READ ONLY');

            return $fn($c);
        });
    }
}

