<?php
/**
 * Runner skenario QA HTTP (workspace agentic).
 *
 *   "$PHP_BIN" scripts/qa-http/run.php <folder-fitur|KEY> [--only=AC-1,AC-6] [--list]
 *
 * Skenario: <folder-fitur>/qa/scenario*.php (KEY = ED-1234 -> features/ED-1234-*). Config:
 * config/workspace.env + config/secrets.env + env proses (lihat README.md).
 * Laporan: work/qa-http/<KEY>-<YmdHis>.json. Exit: 0 = tanpa FAIL, 1 = ada FAIL, 2 = config/infra error.
 */

namespace QaHttp;

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../..')));
define('QA_WORK', QA_AGENTIC . '/work/qa-http');

require __DIR__ . '/lib/Outcome.php';
require __DIR__ . '/lib/Config.php';
require __DIR__ . '/lib/Http.php';
require __DIR__ . '/lib/Laravel.php';
require __DIR__ . '/lib/Sessions.php';
require __DIR__ . '/lib/Tester.php';

const USAGE = 'Pakai: <php> scripts/qa-http/run.php <folder-fitur|KEY> [--only=AC-1,AC-6] [--list]';

function out($line = '')
{
    fwrite(STDOUT, Config::redact($line) . "\n");
}

function abort($message)
{
    fwrite(STDERR, Config::redact('ERROR: ' . $message) . "\n");
    exit(2);
}

/** Terima path folder fitur, atau KEY yang dipetakan ke features/<KEY>-* (harus tepat satu). */
function resolveFeature($arg)
{
    if (is_dir($arg)) {
        return str_replace('\\', '/', realpath($arg));
    }
    if (preg_match('/^[A-Za-z][A-Za-z0-9]*-\d+$/', $arg)) {
        $hits = array_values(array_filter(glob(QA_AGENTIC . '/features/' . $arg . '-*'), 'is_dir'));
        if (count($hits) === 1) {
            return str_replace('\\', '/', realpath($hits[0]));
        }
        abort(count($hits) === 0 ? "folder features/{$arg}-* tidak ada" : "lebih dari satu folder features/{$arg}-*");
    }
    abort("'{$arg}' bukan folder dan bukan KEY fitur (mis. ED-1234)\n" . USAGE);
}

/** require di scope fungsi, supaya variabel berkas skenario tidak bocor ke runner. */
function loadScenarioFile($file)
{
    return require $file;
}

// ------------------------------------------------------------------- argumen & skenario

$feature = null;
$only = null;
$list = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        out(USAGE);
        exit(0);
    } elseif ($arg === '--list') {
        $list = true;
    } elseif (strpos($arg, '--only=') === 0) {
        $only = array_values(array_filter(array_map('trim', explode(',', substr($arg, 7)))));
    } elseif ($feature === null && $arg !== '' && $arg[0] !== '-') {
        $feature = $arg;
    } else {
        abort("argumen tidak dikenal: {$arg}\n" . USAGE);
    }
}
if ($feature === null) {
    abort(USAGE);
}

$dir = resolveFeature($feature);
$key = preg_match('/^([A-Za-z][A-Za-z0-9]*-\d+)/', basename($dir), $m) ? strtoupper($m[1]) : basename($dir);
$files = glob($dir . '/qa/scenario*.php');
sort($files);
if (empty($files)) {
    abort("tidak ada {$dir}/qa/scenario*.php");
}

$scenarios = [];
foreach ($files as $file) {
    try {
        $loaded = loadScenarioFile($file);
    } catch (\Throwable $e) {
        abort(basename($file) . ' gagal dimuat: ' . get_class($e) . ': ' . $e->getMessage() . ' @ line ' . $e->getLine());
    }
    if (!is_array($loaded)) {
        abort(basename($file) . ' harus me-return array skenario');
    }
    foreach ($loaded as $i => $scenario) {
        if (!is_array($scenario) || !isset($scenario['id'], $scenario['run']) || !is_callable($scenario['run'])) {
            abort(basename($file) . " elemen #{$i}: wajib punya 'id' dan 'run' (callable)");
        }
        if (isset($scenarios[$scenario['id']])) {
            abort("id skenario ganda: {$scenario['id']} ({$scenarios[$scenario['id']]['file']} dan " . basename($file) . ')');
        }
        $scenario['file'] = basename($file);
        $scenarios[$scenario['id']] = $scenario;
    }
}
$scenarios = array_values($scenarios);

if ($only !== null) {
    $unknown = array_diff($only, array_column($scenarios, 'id'));
    if (!empty($unknown)) {
        abort('--only memuat id yang tidak ada: ' . implode(', ', $unknown));
    }
    $scenarios = array_values(array_filter($scenarios, function ($scenario) use ($only) {
        return in_array($scenario['id'], $only, true);
    }));
}

if ($list) {
    foreach ($scenarios as $scenario) {
        out(sprintf('%-7s %s  [needs: %s]', $scenario['id'], $scenario['title'] ?? '', implode(', ', $scenario['needs'] ?? [])));
    }
    exit(0);
}

// ------------------------------------------------------------------- config & infrastruktur

$missing = Config::missing();
if (!empty($missing)) {
    abort('config belum mengisi: ' . implode(', ', $missing) . ' (config/workspace.env, config/secrets.env)');
}

try {
    Http::request('GET', '', null, null, 15);
    Laravel::boot();
} catch (\Throwable $e) {
    abort('infrastruktur tidak siap: ' . $e->getMessage());
}

$profile = Config::activeProfile();
$who = Config::primaryWho();
out("Runner QA HTTP {$key}  api=" . Config::base() . '  user=' . Config::get($who) . '  db=' . implode(',', Config::qaDbs())
    . ($profile !== null ? "  profil={$profile}" : '') . '  skenario=' . implode(',', array_map('basename', $files)));
out('PERINGATAN: sesi QA di-bind dengan force_login=1: token LAIN milik ' . Config::get($who) . ' sendiri di DB yang sama dicabut (bukan milik user lain).');
out('');

// ------------------------------------------------------------------- jalankan

$sessions = new Sessions();
$cleanupDone = false;
register_shutdown_function(function () use ($sessions, &$cleanupDone) {
    if (!$cleanupDone) { // run mati di tengah (fatal error): jangan tinggalkan token
        try {
            $sessions->logoutAll();
        } catch (\Throwable $e) {
        }
    }
});

$results = [];
$infraError = null;
$startedAt = date('c');

foreach ($scenarios as $scenario) {
    $t = new Tester($sessions);
    $start = microtime(true);
    $status = 'PASS';
    $message = '';

    try {
        foreach ($scenario['needs'] ?? [] as $db) {
            $t->session($db);
        }
        call_user_func($scenario['run'], $t);
        $message = $t->assertions() . ' asersi';
    } catch (Skipped $e) {
        $status = 'SKIP';
        $message = $e->getMessage();
    } catch (Blocked $e) {
        $status = 'BLOCKED';
        $message = $e->getMessage();
    } catch (AssertionFailed $e) {
        $status = 'FAIL';
        $message = $e->getMessage();
    } catch (InfraError $e) {
        $status = 'FAIL';
        $message = 'infra: ' . $e->getMessage();
        $infraError = $e->getMessage();
    } catch (\Throwable $e) {
        $status = 'FAIL';
        $message = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    if (!empty($t->notes())) {
        $message .= ' | ' . implode(' | ', $t->notes());
    }

    $result = [
        'id'          => $scenario['id'],
        'title'       => $scenario['title'] ?? '',
        'status'      => $status,
        'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        'message'     => Config::redact($message),
    ];
    if ($status === 'FAIL' || $status === 'BLOCKED') {
        $result['last_exchange'] = $t->lastExchange();
    }
    $results[] = $result;

    out(sprintf('%-7s %-8s %6.1fs  %s', $result['id'], $status, $result['duration_ms'] / 1000, $result['message']));

    if ($infraError !== null) {
        break;
    }
}

// ------------------------------------------------------------------- bersih-bersih & laporan

$cleanup = $sessions->logoutAll();
$cleanupDone = true;

$counts = array_count_values(array_column($results, 'status'));
out('');
out('Ringkasan: ' . implode('  ', array_map(function ($status) use ($counts) {
    return $status . '=' . ($counts[$status] ?? 0);
}, ['PASS', 'FAIL', 'SKIP', 'BLOCKED'])));
out("Token run: {$cleanup['tokens']} dibuat, {$cleanup['log_out_200']} log-out, {$cleanup['already_revoked']} sudah dicabut, "
    . "{$cleanup['errors']} gagal log-out; masih aktif: " . json_encode($cleanup['still_active']));

if (!is_dir(QA_WORK)) {
    mkdir(QA_WORK, 0777, true);
}
$reportFile = QA_WORK . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $key) . '-' . date('YmdHis') . '.json';
$report = [
    'feature'     => $key,
    'feature_dir' => $dir,
    'scenarios'   => array_map('basename', $files),
    'api_url'     => Config::base(),
    'user'        => Config::get($who),
    'db'          => Config::qaDbs(),
    'profile'     => $profile,
    'started_at'  => $startedAt,
    'finished_at' => date('c'),
    'summary'     => $counts,
    'cleanup'     => $cleanup,
    'infra_error' => $infraError,
    'results'     => $results,
];
file_put_contents($reportFile, Config::redact(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)) . "\n");
out('Laporan: ' . substr($reportFile, strlen(QA_AGENTIC) + 1));

if ($infraError !== null) {
    exit(2);
}
exit(isset($counts['FAIL']) ? 1 : 0);
