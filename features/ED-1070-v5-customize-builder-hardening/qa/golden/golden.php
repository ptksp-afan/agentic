<?php
/**
 * ED-1070 AC-1 - harness golden regresi (tangkap "sebelum"/"sesudah" + bandingkan). PHP 7.3, dari agentic/.
 *
 *   php golden.php discover [--only=uri,uri]           probe halaman 1 tiap list kandidat (routes.tsv)  -> work/golden/ED-1070/discover.json
 *   php golden.php gen [--discover=<f>]                 bangun set request -> qa/golden/requests.json (beku; dipakai sebelum & sesudah)
 *   php golden.php capture --out=<dir> [--mode=both|inproc|http] [--repeat=1|2] [--only=list,list] [--sets=golden,known500,all]
 *   php golden.php split --before=<dir>                 sesudah tangkapan "sebelum": status 500 -> set known500 (requests.json diperbarui)
 *   php golden.php compare <beforeDir> <afterDir> [--mark-unstable]
 *                                                       per list PASS / DIFF / SKIPPED-guard (+ known500, info jendela waktu);
 *                                                       exit 0 = tanpa DIFF/MISSING. --mark-unstable HANYA untuk "sebelum" vs
 *                                                       ulangan "sebelum" (kode sama): field yang beda ditandai unstable di <beforeDir>
 *   php golden.php show <uri> [--body=N] [--sqlw=N]      diagnosa 1 request in-process (status, potongan body, SQL)
 *
 * Alur "sebelum" (sudah dijalankan, lihat ../golden-before.md): discover -> gen -> capture --out=before --mode=both ->
 * split --before=before -> capture ulang (sesi baru) -> compare before ulangan --mark-unstable -> compare (PASS semua).
 * Alur "sesudah": capture --out=<dir> --mode=both --sets=golden,known500 -> compare before <dir>.
 * Dinilai per request golden: status, total, per_page, current_page (tipe juga: "10" != 10), ids berurutan, SQL lengkap
 * (DB::listen ternormalisasi) dan sql_core (s.d. kueri utama `limit N offset M`). Field "unstable" tidak dinilai
 * (urutan seri kolom sort tidak unik -> ids + eager-load; sql_core tetap). List dengan binding <NOW-REL>
 * (now()->subDays/subMonths) = jendela waktu: total/ids/sql jadi info, sql_core tetap dinilai.
 * Guard per list = count + max(updated_at) tiap tabel yang disebut SQL list itu (+ baris column_display_settings per modul);
 * beda antara _meta.json sebelum/sesudah -> SKIPPED-guard.
 *
 * In-process: Laravel BE_DIR di-bootstrap SEKALI (HTTP kernel), tiap request di-fork (state bersih seperti php-fpm),
 * dikirim lewat HTTP kernel sebagai QA_USER (token asli login+bind ke QA_DB), DB::listen menangkap SQL, semua koneksi
 * di dalam transaksi yang selalu di-ROLLBACK (tidak ada baris yang tersimpan). Log & cache Laravel proses ini diarahkan ke
 * folder run (tidak membuat berkas milik root di storage/ BE). HTTP: request sama lewat server API_URL (token sama).
 * Rahasia tidak pernah dicetak/ditulis (Config::redact; token hanya di memori).
 */

namespace Golden;

use QaHttp\Config;
use QaHttp\Http;

error_reporting(E_ALL);
ini_set('memory_limit', '3072M');
set_time_limit(0);

define('G_DIR', str_replace('\\', '/', __DIR__));
define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../../..')));
define('G_FEATURE', str_replace('\\', '/', realpath(__DIR__ . '/../..')));
define('G_WORK', QA_AGENTIC . '/work/golden/ED-1070');
define('G_JSON', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);

require QA_AGENTIC . '/scripts/qa-http/lib/Outcome.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Config.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Http.php';

// ------------------------------------------------------------------------------------------------ util

function err($msg)
{
    fwrite(STDERR, Config::redact($msg) . "\n");
}

function fail($msg, $code = 2)
{
    err('ERROR: ' . $msg);
    exit($code);
}

function opt($name, $default = null)
{
    global $argv;
    foreach ($argv as $a) {
        if (strpos($a, '--' . $name . '=') === 0) {
            return substr($a, strlen($name) + 3);
        }
        if ($a === '--' . $name) {
            return true;
        }
    }

    return $default;
}

function readJson($file)
{
    if (!is_file($file)) {
        fail("berkas tidak ada: {$file}");
    }
    $raw = file_get_contents($file);
    if (substr($file, -3) === '.gz') {
        $raw = gzdecode($raw);
    }
    $d = json_decode($raw, true);
    if (!is_array($d)) {
        fail("JSON rusak: {$file}");
    }

    return $d;
}

function writeJson($file, $data, $pretty = false)
{
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0775, true);
    }
    $json = json_encode($data, G_JSON | ($pretty ? JSON_PRETTY_PRINT : 0));
    if ($json === false) {
        fail('json_encode gagal untuk ' . $file . ': ' . json_last_error_msg());
    }
    file_put_contents($file, substr($file, -3) === '.gz' ? gzencode($json . "\n", 9) : $json . "\n");
}

/** Berkas tangkapan satu list: <slug>.json.gz (format tulis) atau <slug>.json. */
function capFile($dir, $slug)
{
    foreach (['.json.gz', '.json'] as $ext) {
        if (is_file($dir . '/' . $slug . $ext)) {
            return $dir . '/' . $slug . $ext;
        }
    }

    return null;
}

function isList($a)
{
    return is_array($a) && ($a === [] || array_keys($a) === range(0, count($a) - 1));
}

function slug($uri)
{
    $p = preg_replace('#^api/v5/#', '', strtok($uri, '?'));

    return str_replace('/', '.', $p);
}

function camel($s)
{
    return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $s))));
}

function beHead()
{
    $be = Config::beDir();
    $head = trim((string) shell_exec('git -C ' . escapeshellarg($be) . ' rev-parse --short HEAD 2>/dev/null'));
    $dirty = trim((string) shell_exec('git -C ' . escapeshellarg($be) . ' status --porcelain 2>/dev/null | wc -l'));

    return ['head' => $head, 'dirty_files' => (int) $dirty,
        'branch' => trim((string) shell_exec('git -C ' . escapeshellarg($be) . ' rev-parse --abbrev-ref HEAD 2>/dev/null'))];
}

/** Ringkasan respons list (dipakai in-process dan HTTP, supaya bentuknya identik). */
function summarize($status, $json, $raw, $idField)
{
    $s = ['status' => $status];
    $r = is_array($json) ? ($json['result'] ?? null) : null;
    if (is_array($r) && !isList($r)) {
        foreach (['total' => ['total'], 'per_page' => ['perPage', 'per_page'], 'current_page' => ['currentPage', 'current_page']] as $k => $cands) {
            $s[$k] = null;
            foreach ($cands as $c) {
                if (array_key_exists($c, $r)) {
                    $s[$k] = $r[$c];
                    break;
                }
            }
        }
        $rows = $r['data'] ?? null;
    } else {
        $s['total'] = $s['per_page'] = $s['current_page'] = null;
        $rows = isList($r) ? $r : null;
    }
    if (is_array($rows) && isList($rows)) {
        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row) && $idField !== null && isset($row[$idField]) && is_scalar($row[$idField])) {
                $ids[] = (string) $row[$idField];
            } else {
                $ids[] = 'h:' . substr(sha1(json_encode($row, G_JSON)), 0, 12);
            }
        }
        $s['ids'] = $ids;
        $s['rows_sha1'] = sha1(json_encode($rows, G_JSON));
    } else {
        $s['ids'] = null;
        $s['rows_sha1'] = null;
    }
    if ($status >= 400 || !is_array($json)) {
        $s['code'] = is_array($json) ? ($json['msg_code'] ?? $json['code'] ?? null) : null;
        $msg = is_array($json) ? ($json['message'] ?? $json['msg'] ?? null) : null;
        if (is_array($msg)) {
            $msg = json_encode($msg, G_JSON);
        }
        if ($msg === null) {
            $msg = substr((string) $raw, 0, 300);
        }
        $s['message'] = Config::redact(substr((string) $msg, 0, 300));
    }

    return $s;
}

// ------------------------------------------------------------------------------------------------ Laravel in-process

final class Lab
{
    public static $app;
    public static $kernel;
    public static $db;
    public static $global;
    public static $base;
    public static $runDir;
    /** koneksi yang menunjuk DB tenant yang sama (mis. 'mysql' bila DB_DATABASE = QA_DB, dipakai rute proxy FeatureRouteMapping) */
    public static $tenantConns = [];
    private static $token = null;
    private static $jti = null;
    private static $n = 0;
    private static $loggedIn = false;

    public static function boot($runDir)
    {
        self::$runDir = $runDir;
        if (!is_dir($runDir . '/cache')) {
            mkdir($runDir . '/cache', 0775, true);
        }
        $root = Config::beDir();
        self::$db = Config::defaultDb();
        self::$base = Config::base();
        if (!function_exists('pcntl_fork')) {
            fail('ekstensi pcntl tidak ada di PHP ini');
        }
        // seperti php-fpm: bukan konsol (Laravel 5.7 membaca $_ENV)
        $_ENV['APP_RUNNING_IN_CONSOLE'] = 'false';
        require_once $root . '/vendor/autoload.php';
        $app = require $root . '/bootstrap/app.php';
        $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
        // seperti public/index.php: request sudah terikat sebelum bootstrap (DumpServerServiceProvider membacanya);
        // tiap anak mengikat request aslinya lewat Kernel::handle()
        $app->instance('request', \Illuminate\Http\Request::create(self::$base . '/api/v5', 'GET'));
        $kernel->bootstrap();

        // log & cache proses ini ke folder run: CLI root tidak boleh membuat berkas di storage/ BE (milik www-data)
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

        self::$app = $app;
        self::$kernel = $kernel;
        self::$global = config('database.default');
        if (config('database.connections.' . self::$db) === null) {
            fail('koneksi ' . self::$db . ' tidak ada di config database BE');
        }
        $tdb = config('database.connections.' . self::$db . '.database');
        foreach ((array) config('database.connections') as $name => $c) {
            if (is_array($c) && ($c['database'] ?? null) === $tdb) {
                self::$tenantConns[] = $name;
            }
        }
        self::purge();
    }

    /** Induk tidak pernah memegang koneksi DB: tiap anak membuka koneksinya sendiri. */
    private static function purge()
    {
        foreach (array_keys(self::$app['db']->getConnections()) as $name) {
            self::$app['db']->purge($name);
        }
    }

    /** Jalankan $fn di proses anak (fork); hasil dikembalikan lewat berkas JSON sementara. */
    public static function fork(callable $fn, $timeout = 900)
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
                $res = ['child_error' => Config::redact(get_class($e) . ': ' . substr($e->getMessage(), 0, 500))];
            }
            $json = json_encode($res, G_JSON | JSON_PARTIAL_OUTPUT_ON_ERROR);
            file_put_contents($file, $json === false ? json_encode(['child_error' => 'json: ' . json_last_error_msg()]) : $json);
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
            $sig = pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : null;

            return ['child_error' => 'anak berhenti tanpa hasil' . ($sig ? " (signal {$sig}" . ($sig == 14 ? ' = timeout ' . $timeout . 's' : '') . ')' : '')];
        }
        $res = json_decode(file_get_contents($file), true);
        unlink($file);

        return is_array($res) ? $res : ['child_error' => 'hasil anak bukan JSON'];
    }

    // ---------------------------------------------------------------- sesi QA (token asli, hanya di memori)

    public static function login()
    {
        if (self::$loggedIn) {
            return;
        }
        $clientId = (int) Config::get('CLIENT_ID', 4);
        $secret = self::fork(function () use ($clientId) {
            $v = self::$app['db']->connection(self::$global)->table('oauth_clients')->where('id', $clientId)->value('secret');

            return ['v' => $v];
        });
        if (empty($secret['v'])) {
            fail('client secret tidak terbaca (' . ($secret['child_error'] ?? '-') . ')');
        }
        Config::addSecret($secret['v']);
        $user = Config::get('QA_USER');
        $pass = Config::get('QA_PASS');
        if ($user === null || $pass === null) {
            fail('QA_USER/QA_PASS kosong di config/secrets.env');
        }
        list($st, , $json) = Http::request('POST', 'api/v5/auth/token', null, [
            'username' => $user, 'password' => $pass, 'grant_type' => 'password',
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
            fail('bind ' . self::$db . " gagal: HTTP {$st} " . ($json['msg_code'] ?? $json['code'] ?? '-'));
        }
        err('login QA_USER + bind ' . self::$db . ' OK');
    }

    public static function logout()
    {
        if (!self::$loggedIn || getmypid() !== self::parentPid()) {
            return;
        }
        self::$loggedIn = false;
        try {
            list($st) = Http::request('GET', 'api/v5/auth/log-out', self::$token, null, 30);
            $jti = self::$jti;
            $left = self::fork(function () use ($jti) {
                return ['n' => self::$app['db']->connection(self::$global)->table('oauth_access_tokens')->where('id', $jti)->where('revoked', 0)->count()];
            });
            err("log-out HTTP {$st}; token masih aktif: " . json_encode($left['n'] ?? $left));
        } catch (\Throwable $e) {
            err('log-out gagal: ' . $e->getMessage());
        }
    }

    private static $ppid = null;

    public static function parentPid()
    {
        if (self::$ppid === null) {
            self::$ppid = getmypid();
        }

        return self::$ppid;
    }

    // ---------------------------------------------------------------- request in-process (di anak)

    /** Normalisasi nilai waktu: datetime +-15 menit dari jam request -> <NOW>, tanggal hari ini -> <TODAY>. */
    public static function normTime($s, $t)
    {
        return preg_replace_callback('/\b(\d{4}-\d{2}-\d{2})([ T]\d{2}:\d{2}:\d{2})?\b/', function ($m) use ($t) {
            if (isset($m[2]) && $m[2] !== '') {
                $ts = strtotime($m[0]);
                if ($ts !== false && abs($ts - $t) <= 900) {
                    return '<NOW>';
                }

                return isRelNow($m[0], $t, $t) ? '<NOW-REL>' : $m[0];
            }

            return $m[1] === date('Y-m-d', $t) ? '<TODAY>' : $m[0];
        }, $s);
    }

    private static function normBinding($b, $t)
    {
        if ($b instanceof \DateTimeInterface) {
            $b = $b->format('Y-m-d H:i:s');
        }
        if (is_bool($b)) {
            return $b ? 1 : 0;
        }
        if (is_string($b)) {
            if (self::$jti !== null && $b === self::$jti) {
                return '<JTI>';
            }

            return self::normTime($b, $t);
        }
        if (is_object($b)) {
            return '<' . get_class($b) . '>';
        }

        return $b;
    }

    /**
     * GET $uri lewat HTTP kernel (dipanggil DI ANAK). Semua koneksi dalam transaksi -> rollback.
     * @return array [status, json, raw, queries(normalized), dml, unprotectedDml]
     */
    public static function dispatch($uri)
    {
        $app = self::$app;
        $queries = [];
        $capture = true;
        $t = time();
        $app['events']->listen(\Illuminate\Database\Events\QueryExecuted::class, function ($e) use (&$queries, &$capture) {
            if ($capture) {
                $queries[] = [$e->connectionName, $e->sql, $e->bindings];
            }
        });
        $protected = [];
        foreach ([self::$global, self::$db] as $c) {
            $app['db']->connection($c)->beginTransaction();
            $protected[$c] = true;
        }
        $request = \Illuminate\Http\Request::create(self::$base . '/' . ltrim($uri, '/'), 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::$token,
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
        $response = self::$kernel->handle($request);
        $status = $response->getStatusCode();
        $raw = $response->getContent();
        try {
            self::$kernel->terminate($request, $response);
        } catch (\Throwable $e) {
        }
        $capture = false;
        $unprotected = [];
        foreach ($app['db']->getConnections() as $name => $c) {
            if (!isset($protected[$name])) {
                $unprotected[] = $name;
            }
            while ($c->transactionLevel() > 0) {
                $c->rollBack();
            }
        }
        $norm = [];
        $dml = [];
        $udml = [];
        foreach ($queries as $q) {
            $sql = self::normTime(preg_replace('/\s+/', ' ', trim($q[1])), $t);
            $b = [];
            foreach ((array) $q[2] as $v) {
                $b[] = self::normBinding($v, $t);
            }
            $norm[] = [$q[0], $sql, $b];
            if (preg_match('/^\s*(insert|update|delete|replace|truncate|alter|create|drop|rename)\b/i', $sql)) {
                $dml[] = substr($q[0] . ': ' . $sql, 0, 200);
                if (in_array($q[0], $unprotected, true)) {
                    $udml[] = substr($q[0] . ': ' . $sql, 0, 200);
                }
            }
        }

        return [$status, json_decode($raw, true), $raw, $norm, $dml, $udml];
    }

    /** Tabel (koneksi tenant) yang disebut SQL; modul column_display_settings yang dibaca. */
    public static function sqlFacts($norm)
    {
        $tables = [];
        $modules = [];
        $aggregate = null;
        foreach ($norm as $q) {
            if (!in_array($q[0], self::$tenantConns, true)) {
                continue;
            }
            if (preg_match_all('/\b(?:from|join|update|into)\s+`([A-Za-z0-9_]+)`/i', $q[1], $m)) {
                foreach ($m[1] as $tb) {
                    if (strpos($tb, 'oauth_') !== 0) { // token sesi: berubah tiap login, bukan data list
                        $tables[$tb] = true;
                    }
                }
            }
            if (preg_match('/from `column_display_settings` where `module_name` = \?/', $q[1]) && isset($q[2][0])) {
                $modules[(string) $q[2][0]] = true;
            }
            if (stripos($q[1], 'select count(*) as aggregate from') === 0) {
                $aggregate = $q[1];
            }
        }
        $main = null;
        if ($aggregate !== null && preg_match('/from `([A-Za-z0-9_]+)`/i', $aggregate, $m)) {
            $main = $m[1];
        }
        ksort($tables);
        ksort($modules);

        return ['tables' => array_keys($tables), 'modules' => array_keys($modules), 'main_table' => $main];
    }

    public static function tenant()
    {
        return self::$app['db']->connection(self::$db);
    }

    /** Token hanya untuk Http::request di proses ini; tidak pernah dicetak. */
    public static function token()
    {
        return self::$token;
    }
}

// ------------------------------------------------------------------------------------------------ discover

/** Kandidat list: routes.tsv, GET + P (membaca pagination), tanpa parameter path, bukan export. */
function candidates()
{
    $rows = file(G_FEATURE . '/routes.tsv', FILE_IGNORE_NEW_LINES);
    $head = explode("\t", array_shift($rows));
    $out = [];
    foreach ($rows as $line) {
        $r = array_combine($head, array_pad(explode("\t", $line), count($head), ''));
        if ($r['P'] !== 'P' || strpos($r['method'], 'GET') === false || strpos($r['uri'], '{') !== false
            || stripos($r['uri'], 'export') !== false) {
            continue;
        }
        $out[$r['uri']] = ['uri' => $r['uri'], 'action' => $r['action'], 'src' => $r['src']];
    }

    return array_values($out);
}

function probe($uri, $withSettings = true)
{
    return Lab::fork(function () use ($uri, $withSettings) {
        list($status, $json, $raw, $norm, $dml, $udml) = Lab::dispatch($uri);
        $facts = Lab::sqlFacts($norm);
        $res = ['status' => $status, 'dml' => $dml, 'unprotected_dml' => $udml, 'facts' => $facts, 'n_sql' => count($norm)];
        $r = is_array($json) ? ($json['result'] ?? null) : null;
        $res['shape'] = is_array($r) ? (isList($r) ? 'list' : implode(',', array_slice(array_keys($r), 0, 25))) : gettype($r);
        $res['code'] = is_array($json) ? ($json['msg_code'] ?? $json['code'] ?? null) : null;
        if ($status >= 400) {
            $res['message'] = substr((string) (is_array($json) ? json_encode($json['message'] ?? $json['msg'] ?? null) : $raw), 0, 300);
        }
        $rows = is_array($r) ? (isList($r) ? $r : ($r['data'] ?? null)) : null;
        $res['total'] = is_array($r) && !isList($r) ? ($r['total'] ?? null) : null;
        $res['columns'] = is_array($r) && !isList($r) ? ($r['columns'] ?? null) : null;
        $res['queries'] = is_array($r) && !isList($r) ? ($r['queries'] ?? null) : null;
        $sample = [];
        if (is_array($rows) && isList($rows)) {
            foreach (array_slice($rows, 0, 5) as $row) {
                $sample[] = trimRow($row);
            }
            $res['row_keys'] = isset($rows[0]) && is_array($rows[0]) ? array_keys($rows[0]) : [];
        }
        $res['sample'] = $sample;
        if ($withSettings && $status === 200) {
            $db = Lab::tenant();
            $res['settings'] = [];
            foreach ($facts['modules'] as $mod) {
                $row = $db->table('column_display_settings')->where('module_name', $mod)->first();
                if ($row) {
                    $set = [];
                    foreach (['current_columns', 'available_columns', 'current_queries', 'available_queries'] as $k) {
                        $set[$k] = isset($row->$k) ? json_decode($row->$k, true) : null;
                    }
                    $res['settings'][$mod] = $set;
                }
            }
            if ($facts['main_table']) {
                $pk = $db->select("select COLUMN_NAME c from information_schema.KEY_COLUMN_USAGE where TABLE_SCHEMA = database() and TABLE_NAME = ? and CONSTRAINT_NAME = 'PRIMARY' order by ORDINAL_POSITION", [$facts['main_table']]);
                $res['pk'] = array_map(function ($x) {
                    return $x->c;
                }, $pk);
            }
        }

        return $res;
    });
}

function trimRow($row)
{
    if (!is_array($row)) {
        return is_string($row) ? mb_substr($row, 0, 60) : $row;
    }
    $o = [];
    foreach ($row as $k => $v) {
        if (is_array($v)) {
            $o[$k] = isList($v) ? (isset($v[0]) ? [trimRow($v[0])] : []) : trimRow($v);
        } else {
            $o[$k] = is_string($v) ? mb_substr($v, 0, 60) : $v;
        }
    }

    return $o;
}

/** Nilai pertama dari endpoint select (sama seperti AsyncSelect FE: halaman 1). */
function selectValue($endpoint)
{
    $uri = 'api/v5/' . ltrim(preg_replace('#^/?api/v5/#', '', $endpoint), '/');
    $res = Lab::fork(function () use ($uri) {
        list($status, $json) = Lab::dispatch($uri);
        $r = is_array($json) ? ($json['result'] ?? null) : null;
        $rows = is_array($r) ? (isList($r) ? $r : ($r['options'] ?? $r['data'] ?? null)) : null;
        $v = null;
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && array_key_exists('value', $row) && $row['value'] !== null && $row['value'] !== '') {
                    $v = $row['value'];
                    break;
                }
            }
        }

        return ['status' => $status, 'value' => $v];
    });

    return $res;
}

function cmdDiscover()
{
    $runDir = G_WORK . '/discover-' . date('Ymd-His');
    Lab::boot($runDir);
    Lab::parentPid();
    Lab::login();
    $only = opt('only') ? explode(',', opt('only')) : null;
    $lists = [];
    $selectCache = [];
    foreach (candidates() as $i => $c) {
        if ($only && !in_array($c['uri'], $only, true)) {
            continue;
        }
        $p = probe($c['uri']);
        $entry = $c + ['probe' => $p];
        // archives: varian folder (id_archive) - folder pertama hasil filter Tipe=FOLDER urut nama
        if ($c['uri'] === 'api/v5/document-archive/archives' && ($p['status'] ?? 0) === 200) {
            $f = Lab::fork(function () {
                list($st, $json) = Lab::dispatch('api/v5/document-archive/archives?search=' . rawurlencode('{"type":"FOLDER"}')
                    . '&sorts[]=' . rawurlencode('{"sortBy":"name","sortType":"asc"}') . '&pagination=50');
                $ids = [];
                foreach ((array) ($json['result']['data'] ?? []) as $row) {
                    $ids[] = ['id' => $row['id_archive'] ?? $row['idArchive'] ?? null, 'name' => $row['name'] ?? null, 'type' => $row['type'] ?? null];
                }

                return ['status' => $st, 'folders' => $ids];
            });
            $entry['folders'] = $f;
        }
        // nilai filter select ber-endpoint
        $queries = [];
        foreach ((array) ($p['settings'] ?? []) as $set) {
            foreach (['current_queries', 'available_queries'] as $k) {
                foreach ((array) ($set[$k] ?? []) as $q) {
                    $queries[] = $q;
                }
            }
        }
        foreach ((array) ($p['queries'] ?? []) as $q) {
            $queries[] = $q;
        }
        $entry['select_values'] = [];
        foreach ($queries as $q) {
            $ep = $q['endpoint'] ?? null;
            if (($q['type'] ?? null) === 'select' && is_string($ep) && $ep !== '' && strpos($ep, '{') === false) {
                if (!isset($selectCache[$ep])) {
                    $selectCache[$ep] = selectValue($ep);
                }
                $entry['select_values'][$ep] = $selectCache[$ep];
            }
        }
        $lists[] = $entry;
        err(sprintf('[%d] %s -> %s (sql %s, dml %d, modul %s)', $i, $c['uri'], $p['status'] ?? ($p['child_error'] ?? '?'),
            $p['n_sql'] ?? '-', count($p['dml'] ?? []), implode('|', $p['facts']['modules'] ?? [])));
    }
    $out = opt('out', G_WORK . '/discover.json');
    writeJson($out, ['meta' => ['be' => beHead(), 'at' => date('Y-m-d H:i:s'), 'db' => Config::defaultDb()], 'lists' => $lists]);
    err('discover -> ' . $out);
}

// ------------------------------------------------------------------------------------------------ gen

function setPath(&$arr, $path, $value)
{
    $ref = &$arr;
    foreach ((array) $path as $p) {
        if (!isset($ref[$p]) || !is_array($ref[$p])) {
            $ref[$p] = [];
        }
        $ref = &$ref[$p];
    }
    $ref = $value;
}

/** Ambil nilai baris sampel; respons BE snake_case, data_index camelCase -> coba keduanya. */
function getPath($row, $path)
{
    foreach ((array) $path as $p) {
        if (is_array($row) && isset($row[0]) && isList($row)) {
            $row = $row[0];
        }
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', (string) $p));
        if (is_array($row) && array_key_exists($p, $row)) {
            $row = $row[$p];
        } elseif (is_array($row) && array_key_exists($snake, $row)) {
            $row = $row[$snake];
        } else {
            return null;
        }
    }

    return $row;
}

function qs($params)
{
    $parts = [];
    foreach ($params as $k => $v) {
        if ($k === 'sorts') {
            foreach ($v as $s) {
                $parts[] = 'sorts[]=' . rawurlencode(json_encode($s, G_JSON));
            }
        } elseif ($k === 'search') {
            $parts[] = 'search=' . rawurlencode(json_encode($v, G_JSON));
        } else {
            $parts[] = $k . '=' . rawurlencode((string) $v);
        }
    }

    return implode('&', $parts);
}

/** Nilai filter gaya FE (tableHelper.getQueries) per tipe. */
function filterValues($q, $sample, $selectValues, &$skipped)
{
    $type = $q['type'] ?? 'input';
    $di = $q['data_index'] ?? $q['dataIndex'] ?? null;
    $opts = $q['options'] ?? null;
    $vals = [];
    $firstOpt = (is_array($opts) && isset($opts[0]['value'])) ? $opts[0]['value'] : null;
    switch ($type) {
        case 'select':
            $ep = $q['endpoint'] ?? null;
            $v = $firstOpt;
            if ($v === null && is_string($ep) && $ep !== '') {
                $v = $selectValues[$ep]['value'] ?? null;
            }
            if ($v === null) {
                $skipped[] = ['data_index' => $di, 'type' => $type, 'reason' => 'tidak ada opsi (endpoint ' . ($ep ?: '-') . ')'];

                return [];
            }
            $vals[] = !empty($q['multiple']) ? [$v] : $v;
            break;
        case 'number':
        case 'currency':
        case 'inventory':
            $ops = [];
            foreach ((array) $opts as $o) {
                if (isset($o['value'])) {
                    $ops[] = $o['value'];
                }
            }
            $op = $ops[0] ?? '>=';
            if ($op === 'between') {
                $op = $ops[1] ?? '>=';
            }
            $vals[] = [$op, 0];
            if (in_array('between', $ops, true)) {
                $vals[] = ['between', 0, 1000000000];
            }
            break;
        case 'date':
            $vals[] = '2026-01-02';
            break;
        case 'dateTime':
            $vals[] = '2026-01-02 08:00:00';
            break;
        case 'dateRange':
            $vals[] = ['2025-01-01', '2026-12-31'];
            break;
        case 'dateTimeRange':
            $vals[] = ['2025-01-01 00:00:00', '2026-12-31 23:59:59'];
            break;
        case 'year':
        case 'monthYear':
            $vals[] = '2025-12-31T17:00:00.000Z';
            break;
        case 'radioButton':
            if ($firstOpt === null) {
                $skipped[] = ['data_index' => $di, 'type' => $type, 'reason' => 'radio tanpa opsi'];

                return [];
            }
            $vals[] = $firstOpt;
            break;
        case 'boolean':
            $vals[] = true;
            break;
        default: // input & tipe teks lain
            $v = null;
            foreach ($sample as $row) {
                $x = getPath($row, $di);
                if (is_string($x) && trim($x) !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}/', $x)) {
                    $v = mb_substr(trim($x), 0, 3);
                    break;
                }
            }
            $vals[] = $v === null ? 'a' : $v;
    }

    return $vals;
}

function cmdGen()
{
    $disc = readJson(opt('discover', G_WORK . '/discover.json'));
    $old = readJson(G_FEATURE . '/qa/baseline-valid-params.json');
    $oldMap = ['customers' => 'api/v5/customer/customers', 'purchase-orders' => 'api/v5/purchase/purchase-orders',
        'items' => 'api/v5/item/items', 'suppliers' => 'api/v5/supplier/suppliers',
        'sales-invoices' => 'api/v5/sales/sales-invoices', 'archives' => 'api/v5/document-archive/archives'];
    $lists = [];
    $skippedLists = [];
    foreach ($disc['lists'] as $L) {
        $p = $L['probe'];
        $st = $p['status'] ?? null;
        $isPaged = isset($p['shape']) && strpos($p['shape'], 'data') !== false && strpos($p['shape'], 'total') !== false;
        if ($st === 500) {
            // halaman 1 sudah 500 di baseline: hanya request dasar, masuk set known500 lewat split
            $lists[slug($L['uri'])] = ['slug' => slug($L['uri']), 'uri' => $L['uri'], 'action' => $L['action'], 'base' => [], 'id_field' => null,
                'main_table' => $p['facts']['main_table'] ?? null, 'modules' => $p['facts']['modules'] ?? [], 'writes_on_get' => !empty($p['dml']),
                'page1_500' => true, 'requests' => [['kind' => 'base', 'desc' => 'halaman 1 default (500 di discover)', 'url' => $L['uri']]]];
        }
        if ($st !== 200 || !$isPaged) {
            $skippedLists[] = ['uri' => $L['uri'], 'status' => $st, 'code' => $p['code'] ?? null,
                'reason' => $st === 403 ? 'QA_USER tanpa izin (403)' : ($st !== 200 ? ('halaman 1 = ' . ($st ?: ($p['child_error'] ?? '?'))
                    . (isset($p['message']) ? ' ' . substr($p['message'], 0, 120) : '')) : 'bukan list berpaginasi (' . ($p['shape'] ?? '-') . ')')];
            continue;
        }
        $variants = [['slug' => slug($L['uri']), 'base' => []]];
        if ($L['uri'] === 'api/v5/document-archive/archives') {
            foreach ((array) ($L['folders']['folders'] ?? []) as $f) {
                if (!empty($f['id']) && strtoupper((string) ($f['type'] ?? '')) === 'FOLDER') {
                    $fname = is_array($f['name']) ? ($f['name'][0]['transaction_no'] ?? json_encode($f['name'])) : $f['name'];
                    $variants[] = ['slug' => slug($L['uri']) . '@folder', 'base' => ['id_archive' => $f['id']], 'folder_name' => $fname];
                    break;
                }
            }
        }
        // id field: PK tabel utama (camel) bila ada di baris; selain itu kunci id* pertama
        $keys = $p['row_keys'] ?? [];
        $idField = null;
        foreach ((array) ($p['pk'] ?? []) as $pk) {
            foreach ([$pk, camel($pk)] as $cand) {
                if (in_array($cand, $keys, true)) {
                    $idField = $cand;
                    break 2;
                }
            }
        }
        if ($idField === null) {
            foreach ($keys as $k) {
                if (preg_match('/^id_|^id[A-Z]/', $k) || $k === 'id') {
                    $idField = $k;
                    break;
                }
            }
        }
        if ($idField === null && !$keys && !empty($p['pk'][0])) {
            $idField = $p['pk'][0]; // list kosong saat discover: pakai PK tabel utama
        }
        // kolom & filter: setting DB (current + available) + kolom respons
        $cols = [];
        $qs = [];
        foreach ((array) ($p['settings'] ?? []) as $set) {
            foreach (['current_columns', 'available_columns'] as $k) {
                foreach ((array) ($set[$k] ?? []) as $c) {
                    $cols[] = $c + ['_src' => $k];
                }
            }
            foreach (['current_queries', 'available_queries'] as $k) {
                foreach ((array) ($set[$k] ?? []) as $q) {
                    $qs[] = $q + ['_src' => $k];
                }
            }
        }
        foreach ((array) ($p['columns'] ?? []) as $c) {
            $cols[] = ['data_index' => $c['data_index'] ?? $c['dataIndex'] ?? null, 'sort' => $c['sort'] ?? false, '_src' => 'response'];
        }
        foreach ((array) ($p['queries'] ?? []) as $q) {
            $q['data_index'] = $q['data_index'] ?? $q['dataIndex'] ?? null;
            $qs[] = $q + ['_src' => 'response'];
        }
        $sorts = [];
        foreach ($cols as $c) {
            $di = $c['data_index'] ?? null;
            if (empty($c['sort']) || $di === null || $di === '' || $di === []) {
                continue;
            }
            $by = is_array($di) ? end($di) : $di;
            if (!is_string($by) || isset($sorts[$by])) {
                continue;
            }
            $sorts[$by] = ['by' => $by, 'relation' => is_array($di), 'data_index' => $di, 'src' => $c['_src']];
        }
        $filters = [];
        foreach ($qs as $q) {
            $di = $q['data_index'] ?? null;
            if ($di === null || $di === '' || $di === []) {
                continue;
            }
            $k = is_array($di) ? implode('.', $di) : $di;
            if (!isset($filters[$k])) {
                $filters[$k] = $q;
            }
        }
        foreach ($variants as $V) {
            $reqs = [];
            $add = function ($kind, $params, $desc) use (&$reqs, $L, $V) {
                $all = $V['base'] + $params;
                $q = qs($all);
                $reqs[] = ['kind' => $kind, 'desc' => $desc, 'url' => $L['uri'] . ($q !== '' ? '?' . $q : '')];
            };
            $add('base', [], 'halaman 1 default');
            $add('page', ['pagination' => 10, 'page' => 2], 'pagination=10 page=2');
            $add('page', ['pagination' => 20], 'ukuran 20 (opsi FE)');
            $add('page', ['pagination' => 50, 'page' => 2], 'ukuran 50 page=2');
            $add('search', ['search' => ['query' => 'a']], 'cari umum "a"');
            $i = 0;
            $firstOwn = null;
            foreach ($sorts as $s) {
                $add('sort', ['sorts' => [['sortBy' => $s['by'], 'sortType' => 'asc']]], 'sort ' . $s['by'] . ' asc' . ($s['relation'] ? ' (relasi ' . implode('.', $s['data_index']) . ')' : '') . ' [' . $s['src'] . ']');
                if ($s['relation'] || $i === 0) {
                    $add('sort', ['sorts' => [['sortBy' => $s['by'], 'sortType' => 'desc']]], 'sort ' . $s['by'] . ' desc' . ($s['relation'] ? ' (relasi)' : ''));
                }
                if (!$s['relation'] && $firstOwn === null) {
                    $firstOwn = $s['by'];
                }
                $i++;
            }
            $sortKeys = array_keys($sorts);
            if (count($sortKeys) >= 2) {
                $add('sort', ['sorts' => [['sortBy' => $sortKeys[0], 'sortType' => 'asc'], ['sortBy' => $sortKeys[1], 'sortType' => 'desc']]], 'multi-sort ' . $sortKeys[0] . ' asc + ' . $sortKeys[1] . ' desc');
            }
            $skipped = [];
            foreach ($filters as $k => $q) {
                foreach (filterValues($q, $p['sample'] ?? [], $L['select_values'] ?? [], $skipped) as $v) {
                    $search = [];
                    setPath($search, $q['data_index'], $v);
                    $add('filter', ['search' => $search], 'filter ' . $k . ' (' . ($q['type'] ?? '?') . ') [' . $q['_src'] . ']');
                }
            }
            if ($firstOwn !== null || $sortKeys) {
                $by = $firstOwn ?? $sortKeys[0];
                $add('combo', ['search' => ['query' => 'a'], 'sorts' => [['sortBy' => $by, 'sortType' => 'desc']], 'pagination' => 10, 'page' => 2], 'query a + sort ' . $by . ' desc + page 2');
            }
            if (count($filters) >= 2) {
                $fk = array_keys($filters);
                $search = ['query' => 'a'];
                foreach (array_slice($fk, 0, 2) as $k) {
                    $dummy = [];
                    $vs = filterValues($filters[$k], $p['sample'] ?? [], $L['select_values'] ?? [], $dummy);
                    if ($vs) {
                        setPath($search, $filters[$k]['data_index'], $vs[0]);
                    }
                }
                $add('combo', ['search' => $search], 'query a + 2 filter (' . implode(', ', array_slice($fk, 0, 2)) . ')');
            }
            $lists[$V['slug']] = ['slug' => $V['slug'], 'uri' => $L['uri'], 'action' => $L['action'], 'base' => $V['base'],
                'folder_name' => $V['folder_name'] ?? null, 'id_field' => $idField, 'main_table' => $p['facts']['main_table'] ?? null,
                'modules' => $p['facts']['modules'] ?? [], 'writes_on_get' => !empty($p['dml']), 'dml_sample' => array_slice($p['dml'] ?? [], 0, 3),
                'skipped_filters' => $skipped, 'requests' => $reqs];
        }
    }
    // replay 59 request tangkapan lama
    foreach ($old['entries'] as $n => $e) {
        $uri = $oldMap[$e['list']] ?? null;
        $slug = $uri ? slug($uri) : 'replay.' . $e['list'];
        if (!isset($lists[$slug])) {
            $lists[$slug] = ['slug' => $slug, 'uri' => $uri, 'action' => null, 'base' => [], 'id_field' => null, 'requests' => []];
        }
        $lists[$slug]['requests'][] = ['kind' => 'replay', 'desc' => 'baseline-valid-params #' . $n, 'url' => $e['url'], 'old_index' => $n,
            'old' => ['status' => $e['status'], 'total' => $e['total'], 'per_page' => $e['per_page'], 'current_page' => $e['current_page'], 'ids' => $e['ids']]];
    }
    // id per request (stabil) + set awal
    $count = 0;
    foreach ($lists as $slug => &$l) {
        if ($l['id_field'] === null) {
            $old = ['document-archive.archives' => 'idArchive', 'customer.customers' => 'idCustomer', 'purchase.purchase-orders' => 'idPurchaseOrder',
                'item.items' => 'idItem', 'supplier.suppliers' => 'idSupplier', 'sales.sales-invoices' => 'idSalesInvoice'];
            $l['id_field'] = $old[$slug] ?? null;
        }
        $seen = [];
        $k = 0;
        foreach ($l['requests'] as $j => &$r) {
            $r['id'] = $slug . '#' . str_pad((string) $k++, 3, '0', STR_PAD_LEFT);
            $r['set'] = 'pending';
            $dup = isset($seen[$r['url']]) && $r['kind'] !== 'replay';
            $seen[$r['url']] = true;
            if ($dup) {
                unset($l['requests'][$j]);
            }
        }
        unset($r);
        $l['requests'] = array_values($l['requests']);
        $count += count($l['requests']);
    }
    unset($l);
    ksort($lists);
    $out = G_DIR . '/requests.json';
    writeJson($out, ['meta' => ['generated_at' => date('Y-m-d H:i:s'), 'be' => beHead(), 'db' => Config::defaultDb(),
        'source' => 'routes.tsv (GET+P, tanpa {param}, bukan export) + column_display_settings current/available + baseline-valid-params.json',
        'lists' => count($lists), 'requests' => $count], 'skipped_lists' => $skippedLists, 'lists' => array_values($lists)], true);
    err("gen: " . count($lists) . " list, {$count} request, " . count($skippedLists) . " list dilewati -> {$out}");
}

// ------------------------------------------------------------------------------------------------ guard

/** count + max(updated_at) tiap tabel; column_display_settings juga per modul. Dijalankan di anak. */
function guard($tables, $modules)
{
    return Lab::fork(function () use ($tables, $modules) {
        $db = Lab::tenant();
        $out = [];
        foreach ($tables as $t) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) {
                continue;
            }
            $has = $db->select("select count(*) n from information_schema.COLUMNS where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = 'updated_at'", [$t]);
            $exists = $db->select('select count(*) n from information_schema.TABLES where TABLE_SCHEMA = database() and TABLE_NAME = ?', [$t]);
            if ((int) $exists[0]->n === 0) {
                continue;
            }
            if ($t === 'users' && (int) $has[0]->n) {
                // login QA_USER memperbarui baris user itu sendiri (last_login/updated_at): baris itu tidak ikut max
                $row = $db->selectOne('select count(*) n, max(case when `username` <> ? then `updated_at` end) m from `users`', [(string) Config::get('QA_USER')]);
                $out[$t] = ['count' => (int) $row->n, 'max_updated_at' => isset($row->m) ? (string) $row->m : null, 'note' => 'max tanpa baris QA_USER'];
                continue;
            }
            $row = $db->selectOne('select count(*) n' . ((int) $has[0]->n ? ', max(`updated_at`) m' : '') . " from `{$t}`");
            $out[$t] = ['count' => (int) $row->n, 'max_updated_at' => isset($row->m) ? (string) $row->m : null];
        }
        $cds = [];
        foreach ($modules as $m) {
            $r = $db->table('column_display_settings')->where('module_name', $m)->selectRaw('count(*) n, max(updated_at) m')->first();
            $cds[$m] = ['count' => (int) $r->n, 'max_updated_at' => $r->m];
        }

        return ['tables' => $out, 'cds_modules' => $cds];
    }, 3600);
}

// ------------------------------------------------------------------------------------------------ capture

function cmdCapture()
{
    $outDir = opt('out');
    if (!$outDir || $outDir === true) {
        fail('pakai --out=<dir>');
    }
    $outDir = rtrim($outDir, '/');
    $mode = opt('mode', 'both');
    $repeat = max(1, (int) opt('repeat', 1));
    $sets = explode(',', opt('sets', 'all'));
    $only = opt('only') ? explode(',', opt('only')) : null;
    $req = readJson(opt('requests', G_DIR . '/requests.json'));
    $runDir = G_WORK . '/capture-' . date('Ymd-His');
    Lab::boot($runDir);
    Lab::parentPid();
    Lab::login();
    $be = beHead();
    $startedAt = date('Y-m-d H:i:s');

    // tabel guard: 7 wajib + tabel list (diisi dari SQL; untuk tangkapan pertama dari discover)
    $baseTables = ['archives', 'customers', 'purchase_orders', 'items', 'suppliers', 'sales_invoices', 'column_display_settings'];
    $tables = $baseTables;
    $modules = [];
    foreach ($req['lists'] as $l) {
        foreach ((array) ($l['tables'] ?? []) as $t) {
            $tables[] = $t;
        }
        foreach ((array) ($l['modules'] ?? []) as $m) {
            $modules[] = $m;
        }
    }
    $g0 = guard(array_values(array_unique($tables)), array_values(array_unique($modules)));
    err('guard awal: ' . count($g0['tables'] ?? []) . ' tabel');

    $summary = ['lists' => 0, 'requests' => 0, 'status' => [], 'http_mismatch' => 0, 'unstable' => 0, 'writes' => 0, 'unprotected_dml' => 0];
    $allTables = [];
    $allModules = [];
    $t0 = microtime(true);
    foreach ($req['lists'] as $l) {
        if ($only && !in_array($l['slug'], $only, true)) {
            continue;
        }
        $res = ['slug' => $l['slug'], 'uri' => $l['uri'], 'id_field' => $l['id_field'], 'sql_dict' => [], 'bind_dict' => [], 'requests' => []];
        $dict = [];
        $bdict = [];
        $lt = [];
        $lm = [];
        foreach ($l['requests'] as $r) {
            if (!in_array('all', $sets, true) && !in_array($r['set'], $sets, true)) {
                continue;
            }
            $one = ['id' => $r['id'], 'kind' => $r['kind'], 'set' => $r['set'], 'url' => $r['url']];
            $writes = false;
            if ($mode !== 'http') {
                $runs = [];
                for ($k = 0; $k < $repeat; $k++) {
                    $idField = $l['id_field'];
                    $runs[] = Lab::fork(function () use ($r, $idField) {
                        $ts = microtime(true);
                        list($status, $json, $raw, $norm, $dml, $udml) = Lab::dispatch($r['url']);
                        $s = summarize($status, $json, $raw, $idField);
                        $s['ms'] = (int) ((microtime(true) - $ts) * 1000);
                        $s['sql'] = $norm;
                        $s['dml'] = $dml;
                        $s['unprotected_dml'] = $udml;
                        $s['facts'] = Lab::sqlFacts($norm);

                        return $s;
                    });
                }
                $a = $runs[0];
                if (isset($a['child_error'])) {
                    $one['inproc'] = ['status' => 'ERR', 'message' => $a['child_error']];
                } else {
                    // kamus SQL + kamus bindings per list (hemat ukuran): [idx sql, idx bindings]
                    $sqlRef = [];
                    foreach ($a['sql'] as $q) {
                        $key = $q[0] . "\t" . $q[1];
                        if (!isset($dict[$key])) {
                            $dict[$key] = count($dict);
                        }
                        $bkey = json_encode($q[2], G_JSON);
                        if (!isset($bdict[$bkey])) {
                            $bdict[$bkey] = count($bdict);
                        }
                        $sqlRef[] = [$dict[$key], $bdict[$bkey]];
                    }
                    foreach ($a['facts']['tables'] as $tb) {
                        $lt[$tb] = true;
                    }
                    foreach ($a['facts']['modules'] as $m) {
                        $lm[$m] = true;
                    }
                    $writes = !empty($a['dml']);
                    $summary['writes'] += $writes ? 1 : 0;
                    $summary['unprotected_dml'] += count($a['unprotected_dml']);
                    $one['inproc'] = array_intersect_key($a, array_flip(['status', 'total', 'per_page', 'current_page', 'ids', 'rows_sha1', 'code', 'message', 'ms', 'dml', 'unprotected_dml']));
                    $one['inproc']['sql'] = $sqlRef;
                    if ($repeat > 1) {
                        $unstable = [];
                        foreach (array_slice($runs, 1) as $b) {
                            foreach (['status', 'total', 'per_page', 'current_page', 'ids', 'rows_sha1', 'sql'] as $f) {
                                if (isset($b['child_error']) || json_encode($a[$f] ?? null) !== json_encode($b[$f] ?? null)) {
                                    $unstable[$f] = true;
                                }
                            }
                        }
                        if ($unstable) {
                            $one['unstable'] = array_keys($unstable);
                            $summary['unstable']++;
                        }
                    }
                }
            }
            if ($mode !== 'inproc') {
                if ($writes || !empty($l['writes_on_get'])) {
                    $one['http'] = ['skipped' => 'GET menulis (DML) - tidak dikirim ke server agar tidak ada baris tersimpan'];
                } else {
                    $ts = microtime(true);
                    list($st, $raw, $json) = Http::request('GET', $r['url'], Lab::token());
                    $h = summarize($st, $json, $raw, $l['id_field']);
                    $h['ms'] = (int) ((microtime(true) - $ts) * 1000);
                    $one['http'] = $h;
                    if (isset($one['inproc']) && is_array($one['inproc'])) {
                        $d = [];
                        foreach (['status', 'total', 'per_page', 'current_page', 'ids', 'rows_sha1'] as $f) {
                            if (json_encode($one['inproc'][$f] ?? null) !== json_encode($h[$f] ?? null)) {
                                $d[] = $f;
                            }
                        }
                        if ($d) {
                            $one['http_vs_inproc'] = $d;
                            $summary['http_mismatch']++;
                        }
                    }
                }
            }
            if ($r['kind'] === 'replay' && isset($r['old'])) {
                $cur = $one['inproc'] ?? $one['http'] ?? [];
                $d = [];
                foreach (['status', 'total', 'per_page', 'current_page', 'ids'] as $f) {
                    if (json_encode($r['old'][$f]) !== json_encode($cur[$f] ?? null)) {
                        $d[] = $f;
                    }
                }
                $one['vs_old_capture'] = $d ?: 'same';
            }
            $stKey = (string) ($one['inproc']['status'] ?? $one['http']['status'] ?? '?');
            $summary['status'][$stKey] = ($summary['status'][$stKey] ?? 0) + 1;
            $summary['requests']++;
            $res['requests'][] = $one;
            if ($summary['requests'] % 50 === 0) {
                err(sprintf('... %d request, %.0fs, list %s', $summary['requests'], microtime(true) - $t0, $l['slug']));
            }
        }
        if (!$res['requests']) {
            continue;
        }
        $res['sql_dict'] = array_map('strval', array_keys($dict));
        $res['bind_dict'] = array_map(function ($k) {
            return json_decode($k, true);
        }, array_keys($bdict));
        ksort($lt);
        ksort($lm);
        $res['tables'] = array_keys($lt);
        $res['modules'] = array_keys($lm);
        $allTables += $lt;
        $allModules += $lm;
        $summary['lists']++;
        writeJson($outDir . '/' . $l['slug'] . '.json.gz', $res);
    }
    $g1 = guard(array_values(array_unique(array_merge($tables, array_keys($allTables)))), array_values(array_unique(array_merge($modules, array_keys($allModules)))));
    $meta = ['key' => 'ED-1070', 'be' => $be, 'be_end' => beHead(), 'db' => Config::defaultDb(), 'user' => 'QA_USER', 'mode' => $mode, 'repeat' => $repeat,
        'started_at' => $startedAt, 'captured_at' => date('Y-m-d H:i:s'), 'seconds' => (int) (microtime(true) - $t0),
        'requests_file' => 'qa/golden/requests.json', 'summary' => $summary, 'guard_start' => $g0, 'guard' => $g1,
        'guard_changed_during_capture' => guardDiff($g0, $g1), 'run_dir' => str_replace(QA_AGENTIC . '/', '', $runDir)];
    writeJson($outDir . '/_meta.json', $meta, true);
    err('capture selesai: ' . json_encode($summary) . ' -> ' . $outDir);
}

function guardDiff($a, $b)
{
    $d = [];
    foreach ((array) ($a['tables'] ?? []) as $t => $v) {
        if (isset($b['tables'][$t]) && $b['tables'][$t] != $v) {
            $d[] = $t;
        }
    }
    foreach ((array) ($a['cds_modules'] ?? []) as $m => $v) {
        if (isset($b['cds_modules'][$m]) && $b['cds_modules'][$m] != $v) {
            $d[] = 'cds:' . $m;
        }
    }

    return $d;
}

// ------------------------------------------------------------------------------------------------ split

/** Sesudah tangkapan "sebelum": request 500 -> known500, lain -> golden; tabel/modul list dicatat (guard). */
function cmdSplit()
{
    $dir = rtrim((string) opt('before'), '/');
    $file = G_DIR . '/requests.json';
    $req = readJson($file);
    $n = ['golden' => 0, 'known500' => 0, 'missing' => 0];
    foreach ($req['lists'] as &$l) {
        $f = capFile($dir, $l['slug']);
        $cap = $f !== null ? readJson($f) : null;
        $by = [];
        foreach ((array) ($cap['requests'] ?? []) as $c) {
            $by[$c['id']] = $c;
        }
        $l['tables'] = $cap['tables'] ?? [];
        $l['modules'] = $cap['modules'] ?? ($l['modules'] ?? []);
        foreach ($l['requests'] as &$r) {
            $c = $by[$r['id']] ?? null;
            $st = $c['inproc']['status'] ?? null;
            if ($c === null || $st === 'ERR') {
                $r['set'] = 'missing';
            } elseif ((int) $st >= 500) {
                $r['set'] = 'known500';
                $r['before_message'] = substr((string) ($c['inproc']['message'] ?? ''), 0, 200);
            } else {
                $r['set'] = 'golden';
            }
            $n[$r['set']]++;
        }
        unset($r);
    }
    unset($l);
    $req['meta']['split'] = ['from' => str_replace(G_FEATURE . '/', '', $dir), 'at' => date('Y-m-d H:i:s'), 'counts' => $n];
    writeJson($file, $req, true);
    err('split: ' . json_encode($n));
}

// ------------------------------------------------------------------------------------------------ compare

/** Jam tetap yang dipakai gen (bukan turunan now()); tidak pernah dianggap relatif. */
const LITERAL_TIMES = ['00:00:00', '23:59:59', '08:00:00'];

/**
 * Datetime relatif terhadap now() (now()->subDays/subMonths(..)): jam-menitnya +-15 menit dari jam tangkapan
 * [$from, $to] (detik epoch), tanggal lain. Jam literal gen dikecualikan.
 */
function isRelNow($dt, $from, $to)
{
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/', $dt, $m) || in_array("{$m[2]}:{$m[3]}:{$m[4]}", LITERAL_TIMES, true)) {
        return false;
    }
    $tod = $m[2] * 3600 + $m[3] * 60 + $m[4];
    $a = ((int) date('G', $from) * 3600 + (int) date('i', $from) * 60 + (int) date('s', $from) - 900 + 86400) % 86400;
    $span = min(86400, ($to - $from) + 1800);
    $d = ($tod - $a + 86400) % 86400;

    return $d <= $span;
}

/** Normalisasi ulang saat membandingkan (idempoten): binding datetime relatif now() pada jendela tangkapan itu -> <NOW-REL>. */
function renormSql($sql, $meta)
{
    $from = strtotime($meta['started_at']);
    $to = strtotime($meta['captured_at']);
    foreach ($sql as &$q) {
        if (is_array($q[1])) {
            foreach ($q[1] as &$v) {
                if (is_string($v) && isRelNow($v, $from, $to)) {
                    $v = '<NOW-REL>';
                }
            }
            unset($v);
        }
    }
    unset($q);

    return $sql;
}

/** SQL inti: sampai dan termasuk kueri utama paginate (`limit N offset M`); kueri eager-load sesudahnya bergantung pada ids. */
function sqlCore($sql)
{
    $core = [];
    foreach ($sql as $q) {
        $core[] = $q;
        if (preg_match('/ limit \d+ offset \d+$/', $q[0])) {
            return $core;
        }
    }

    return $sql;
}

function cmdCompare($beforeDir, $afterDir, $markUnstable = false)
{
    $marked = 0;
    $bm = readJson($beforeDir . '/_meta.json');
    $am = readJson($afterDir . '/_meta.json');
    $req = readJson(G_DIR . '/requests.json');
    $setOf = [];
    foreach ($req['lists'] as $l) {
        foreach ($l['requests'] as $r) {
            $setOf[$r['id']] = $r['set'];
        }
    }
    $fields = ['status', 'total', 'per_page', 'current_page', 'ids', 'sql', 'sql_core'];
    $timeInfo = [];
    $tot = ['PASS' => 0, 'DIFF' => 0, 'SKIPPED-guard' => 0, 'MISSING' => 0];
    $k500 = [];
    $details = [];
    $lines = [];
    foreach (array_merge(glob($beforeDir . '/*.json.gz'), glob($beforeDir . '/*.json')) as $bf) {
        if (basename($bf)[0] === '_') {
            continue;
        }
        $b = readJson($bf);
        $af = capFile($afterDir, $b['slug']);
        if ($af === null) {
            $tot['MISSING']++;
            $lines[] = sprintf('%-60s MISSING (tidak ada di sesudah)', $b['slug']);
            continue;
        }
        $a = readJson($af);
        // guard: tabel list + modul column_display_settings
        $changed = [];
        foreach ((array) $b['tables'] as $t) {
            if ($t === 'column_display_settings') {
                continue;
            }
            $x = $bm['guard']['tables'][$t] ?? null;
            $y = $am['guard']['tables'][$t] ?? null;
            if ($x != $y) {
                $changed[] = $t;
            }
        }
        foreach ((array) $b['modules'] as $m) {
            if (($bm['guard']['cds_modules'][$m] ?? null) != ($am['guard']['cds_modules'][$m] ?? null)) {
                $changed[] = 'cds:' . $m;
            }
        }
        $aby = [];
        foreach ($a['requests'] as $r) {
            $aby[$r['id']] = $r;
        }
        $diffs = [];
        $n = 0;
        foreach ($b['requests'] as $r) {
            $set = $setOf[$r['id']] ?? $r['set'];
            $ar = $aby[$r['id']] ?? null;
            if ($set === 'known500') {
                $k500[] = ['id' => $r['id'], 'url' => $r['url'], 'before' => $r['inproc']['status'] ?? null, 'after' => $ar['inproc']['status'] ?? ($ar ? '?' : 'tidak ditangkap')];
                continue;
            }
            if ($set !== 'golden') {
                continue;
            }
            $n++;
            if ($ar === null) {
                $diffs[] = ['id' => $r['id'], 'fields' => ['missing']];
                continue;
            }
            $bx = $r['inproc'];
            $ax = $ar['inproc'];
            $bx['sql'] = renormSql(expandSql($b, $bx['sql'] ?? []), $bm);
            $ax['sql'] = renormSql(expandSql($a, $ax['sql'] ?? []), $am);
            $bx['sql_core'] = sqlCore($bx['sql']);
            $ax['sql_core'] = sqlCore($ax['sql']);
            // list dengan jendela waktu relatif now(): total/ids/eager-load ikut bergeser -> info; sql_core tetap dinilai
            $timeDep = strpos(json_encode($bx['sql']), '<NOW-REL>') !== false;
            $f = [];
            $info = [];
            foreach ($fields as $fd) {
                if (in_array($fd, (array) ($r['unstable'] ?? []), true)) {
                    continue;
                }
                if (json_encode($bx[$fd] ?? null) !== json_encode($ax[$fd] ?? null)) {
                    $note = $fd;
                    if (in_array($fd, ['per_page', 'current_page', 'total'], true) && (string) ($bx[$fd] ?? '') === (string) ($ax[$fd] ?? '')) {
                        $note .= '(type-only)';
                    }
                    if ($timeDep && in_array($fd, ['total', 'ids', 'sql'], true)) {
                        $info[] = $note;
                    } else {
                        $f[] = $note;
                    }
                }
            }
            if ($info) {
                $timeInfo[] = ['id' => $r['id'], 'fields' => $info];
            }
            if ($f || ($markUnstable && $info)) {
                $diffs[] = ['id' => $r['id'], 'url' => $r['url'], 'fields' => $f ?: $info, 'before' => brief($bx), 'after' => brief($ax),
                    'sql_first_diff' => array_intersect(['sql', 'sql_core'], $f) ? firstSqlDiff(in_array('sql_core', $f, true) ? $bx['sql_core'] : $bx['sql'], in_array('sql_core', $f, true) ? $ax['sql_core'] : $ax['sql']) : null];
            }
        }
        // --mark-unstable (hanya untuk "sebelum" vs ulangan "sebelum" pada kode yang sama): beda = tidak deterministik
        if ($markUnstable && $diffs && !$changed) {
            $byId = [];
            foreach ($diffs as $d) {
                $fs = preg_replace('/\(type-only\)$/', '', $d['fields']);
                if (in_array('ids', $fs, true)) {
                    // urutan seri (kolom sort tidak unik): eager-load ikut beda; sql_core tetap dinilai bila sama
                    $fs = array_merge($fs, ['rows_sha1', 'sql']);
                }
                $byId[$d['id']] = $fs;
            }
            foreach ($b['requests'] as &$br) {
                if (isset($byId[$br['id']])) {
                    $br['unstable'] = array_values(array_unique(array_merge((array) ($br['unstable'] ?? []), $byId[$br['id']])));
                }
            }
            unset($br);
            writeJson($bf, $b);
            $marked += count($byId);
        }
        if ($n === 0) {
            continue;
        }
        if ($changed) {
            $tot['SKIPPED-guard']++;
            $lines[] = sprintf('%-60s SKIPPED-guard (%s)%s', $b['slug'], implode(',', array_slice($changed, 0, 4)), $diffs ? ' [' . count($diffs) . ' beda, tidak dinilai]' : '');
        } elseif ($diffs) {
            $tot['DIFF']++;
            $lines[] = sprintf('%-60s DIFF %d/%d (%s)', $b['slug'], count($diffs), $n, implode(' ', array_unique(array_merge(...array_map(function ($d) {
                return $d['fields'];
            }, $diffs)))));
            $details[$b['slug']] = $diffs;
        } else {
            $tot['PASS']++;
            $lines[] = sprintf('%-60s PASS %d', $b['slug'], $n);
        }
    }
    foreach ($lines as $line) {
        echo $line, "\n";
    }
    $k = ['still500' => 0, 'now200' => 0, 'other' => 0];
    foreach ($k500 as $x) {
        $kk = $x['after'] === 500 ? 'still500' : ($x['after'] === 200 ? 'now200' : 'other');
        $k[$kk]++;
    }
    echo "\nRINGKASAN list: ", json_encode($tot), "\n";
    echo 'known500 (bukan golden; AC-7 menilai): ', count($k500), ' request -> ', json_encode($k), "\n";
    echo 'info jendela waktu now() (tidak dinilai; sql_core tetap dinilai): ', count($timeInfo), " request\n";
    echo 'BE sebelum ', $bm['be']['head'], ' @', $bm['captured_at'], ' | sesudah ', $am['be']['head'], ' (kotor ', $am['be']['dirty_files'], ') @', $am['captured_at'], "\n";
    if (!empty($am['guard_changed_during_capture'])) {
        echo 'PERINGATAN: guard berubah SELAMA tangkapan sesudah: ', implode(',', $am['guard_changed_during_capture']), "\n";
    }
    if (substr($bm['captured_at'], 0, 10) !== substr($am['captured_at'], 0, 10)) {
        echo "CATATAN: tanggal tangkapan beda; list dengan filter default berbasis tanggal hari ini bisa berbeda.\n";
    }
    if ($markUnstable) {
        echo "mark-unstable: {$marked} request ditandai unstable di {$beforeDir}\n";
    }
    $out = $afterDir . '/_compare.json';
    writeJson($out, ['before' => $beforeDir, 'after' => $afterDir, 'totals' => $tot, 'known500' => $k500, 'time_info' => $timeInfo, 'diffs' => $details], true);
    echo 'detail -> ', $out, "\n";
    exit($tot['DIFF'] || $tot['MISSING'] ? 1 : 0);
}

/** [idx sql, idx bindings] -> ["koneksi\tsql", bindings] */
function expandSql($file, $refs)
{
    $o = [];
    foreach ((array) $refs as $x) {
        $o[] = [$file['sql_dict'][$x[0]] ?? '?', $file['bind_dict'][$x[1]] ?? '?'];
    }

    return $o;
}

function brief($x)
{
    return ['status' => $x['status'] ?? null, 'total' => $x['total'] ?? null, 'per_page' => $x['per_page'] ?? null,
        'current_page' => $x['current_page'] ?? null, 'ids_head' => array_slice((array) ($x['ids'] ?? []), 0, 3), 'n_sql' => count((array) ($x['sql'] ?? []))];
}

function firstSqlDiff($a, $b)
{
    $n = max(count($a), count($b));
    for ($i = 0; $i < $n; $i++) {
        if (json_encode($a[$i] ?? null) !== json_encode($b[$i] ?? null)) {
            return ['index' => $i, 'before' => $a[$i] ?? null, 'after' => $b[$i] ?? null];
        }
    }

    return null;
}

// ------------------------------------------------------------------------------------------------ main

/** Diagnosa satu request in-process: status, potongan body, SQL ternormalisasi (koneksi + 160 karakter). */
function cmdShow($uri)
{
    Lab::boot(G_WORK . '/show-' . date('Ymd-His'));
    Lab::parentPid();
    Lab::login();
    $r = Lab::fork(function () use ($uri) {
        list($status, $json, $raw, $norm, $dml, $udml) = Lab::dispatch($uri);

        return ['status' => $status, 'body' => mb_substr((string) $raw, 0, (int) opt('body', 1500)), 'dml' => $dml, 'unprotected_dml' => $udml,
            'facts' => Lab::sqlFacts($norm), 'sql' => array_map(function ($q) {
                return $q[0] . ' | ' . mb_substr($q[1], 0, (int) opt('sqlw', 160)) . ' | ' . mb_substr(json_encode($q[2], G_JSON), 0, 80);
            }, $norm)];
    });
    echo Config::redact(json_encode($r, G_JSON | JSON_PRETTY_PRINT)), "\n";
}

$cmd = $argv[1] ?? null;
switch ($cmd) {
    case 'show':
        cmdShow($argv[2] ?? fail('pakai: show <uri>'));
        break;
    case 'discover':
        cmdDiscover();
        break;
    case 'gen':
        cmdGen();
        break;
    case 'capture':
        cmdCapture();
        break;
    case 'split':
        cmdSplit();
        break;
    case 'compare':
        if (empty($argv[2]) || empty($argv[3])) {
            fail('pakai: compare <beforeDir> <afterDir>');
        }
        cmdCompare(rtrim($argv[2], '/'), rtrim($argv[3], '/'), (bool) opt('mark-unstable'));
        break;
    default:
        fwrite(STDERR, "pakai: php golden.php discover|gen|capture|split|compare ... (lihat kepala berkas)\n");
        exit(2);
}
