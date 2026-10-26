<?php
/**
 * Pembantu e2e ED-1251 (BUKAN skenario regresi; dipanggil spec lewat execFileSync). TANPA login (login runner akan mencabut sesi browser
 * QA_USER): hanya baca DB / tulis jurnal-terikat. Setup/teardown data ada di qa/e2e/fixrun (FIX-UP / FIX-DOWN lewat fixtures.json).
 *
 *   fixture.php ids               isi .fixture-ids.json (S1, S2, nama folder, angka sesi)
 *   fixture.php own               baris cds + template documentArchiveOpnameResult (jumlah, kertas, template, kolom tersimpan) + template lain (count,crc)
 *   fixture.php own-restore       pulihkan baris cds + template sendiri persis seperti jurnal FIX-UP (qa/.journal-config-q251.json)
 *   fixture.php lang <ID|EN>      ubah bahasa QA_USER sementara (jurnal FIX-UP = qa/.restore-journal-q251.json memuat bahasa asli)
 *   fixture.php lang-restore      kembalikan bahasa QA_USER ke nilai jurnal
 *   fixture.php pdf <berkas>      isi PDF (teks, halaman, MediaBox, warna) sebagai JSON; xlsx <berkas> = sel XLSX sebagai JSON
 */

namespace QaHttp;

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../../..')));

require QA_AGENTIC . '/scripts/qa-http/lib/Outcome.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Config.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Laravel.php';
require QA_AGENTIC . '/features/ED-1251-archive-export-opname-pdf-excel/qa/qa_files.php';

use Illuminate\Support\Facades\DB;

const MODULE = 'documentArchiveOpnameResult';

function out($j)
{
    fwrite(STDOUT, json_encode($j, JSON_UNESCAPED_UNICODE) . "\n");
}

function bad($m)
{
    fwrite(STDERR, Config::redact('ERROR: ' . $m) . "\n");
    exit(1);
}

$cmd = $argv[1] ?? '';
$qa = realpath(__DIR__ . '/..');

if ($cmd === 'ids') {
    echo file_get_contents(__DIR__ . '/.fixture-ids.json');
    exit(0);
}
if ($cmd === 'pdf') {
    $b = file_get_contents($argv[2] ?? '');
    if ($b === false || strpos($b, '%PDF-') !== 0) {
        out(['ok' => false, 'bytes' => $b === false ? 0 : strlen($b), 'head' => $b === false ? '' : substr($b, 0, 20)]);
        exit(0);
    }
    $p = \q251_pdf($b);
    out(['ok' => true, 'bytes' => strlen($b), 'pages' => $p['pages'], 'media' => $p['media'], 'text' => $p['text'], 'ptexts' => array_map(function ($a) {
        return implode(' ', (array) $a);
    }, $p['texts']), 'fills' => $p['fills'], 'colors' => $p['colors']]);
    exit(0);
}
if ($cmd === 'xlsx') {
    $b = file_get_contents($argv[2] ?? '');
    if ($b === false || substr($b, 0, 2) !== 'PK') {
        out(['ok' => false, 'bytes' => $b === false ? 0 : strlen($b), 'head' => $b === false ? '' : bin2hex(substr($b, 0, 4))]);
        exit(0);
    }
    $x = \q251_xlsx($b);
    $rows = [];
    ksort($x['rows']);
    foreach ($x['rows'] as $r => $cols) {
        $line = [];
        foreach ($cols as $col => $cell) {
            if ($cell['v'] !== null && $cell['v'] !== '') {
                $line[$col] = is_float($cell['v']) ? (string) $cell['v'] : $cell['v'];
            }
        }
        if ($line) {
            $rows[$r] = $line;
        }
    }
    out(['ok' => true, 'bytes' => strlen($b), 'sheet' => $x['sheet'], 'rows' => $rows]);
    exit(0);
}

Laravel::boot();
$c = DB::connection(Config::defaultDb());

function rowsOf($col)
{
    return array_map(function ($r) {
        return (array) $r;
    }, $col->all());
}

if ($cmd === 'own') {
    $tpl = rowsOf($c->table('transaction_pdf_templates')->where('transaction_name', MODULE)->orderBy('id_transaction_pdf_template')->get());
    $cols = array_map(function ($r) {
        return $r->Field;
    }, $c->select('SHOW COLUMNS FROM transaction_pdf_templates'));
    $parts = array_map(function ($f) {
        return "IFNULL(CAST(`$f` AS CHAR),'~')";
    }, $cols);
    $o = $c->selectOne("SELECT COUNT(*) n, IFNULL(SUM(CRC32(CONCAT_WS('|'," . implode(',', $parts) . '))),0) h FROM transaction_pdf_templates WHERE transaction_name <> ?', [MODULE]);
    $res = ['tpl_count' => count($tpl), 'cds_count' => $c->table('column_display_settings')->where('module_name', MODULE)->count(), 'other' => [(int) $o->n, (string) $o->h]];
    if ($tpl) {
        $r = $tpl[0];
        $data = json_decode($r['transaction_data'], true) ?: [];
        $tables = [];
        foreach ($data['tables'] ?? [] as $t) {
            $tables[] = ['data_index' => $t['data_index'] ?? null, 'current' => array_map(function ($col) {
                $di = $col['data_index'] ?? null;

                return is_array($di) ? implode('.', $di) : $di;
            }, $t['current_columns'] ?? [])];
        }
        $res += ['paper_size' => $data['paper_size'] ?? $r['paper_size'], 'paper_size_col' => $r['paper_size'], 'id_pdf_template' => $r['id_pdf_template'], 'id_header' => $r['id_header_pdf_template'], 'id_footer' => $r['id_footer_pdf_template'],
            'name' => $r['transaction_name'], 'id' => (string) $r['id_transaction_pdf_template'], 'tables' => $tables];
    }
    out($res);
    exit(0);
}
if ($cmd === 'own-restore') {
    $file = $qa . '/.journal-config-q251.json';
    if (!is_file($file)) {
        bad('jurnal baris config tidak ada (FIX-UP tidak jalan?)');
    }
    $snap = json_decode(file_get_contents($file), true);
    foreach (['cds' => ['column_display_settings', 'module_name'], 'tpl' => ['transaction_pdf_templates', 'transaction_name']] as $k => $spec) {
        $c->table($spec[0])->where($spec[1], MODULE)->delete();
        foreach ($snap[$k] as $row) {
            $c->table($spec[0])->insert($row);
        }
    }
    out(['restored' => true, 'tpl' => count($snap['tpl']), 'cds' => count($snap['cds'])]);
    exit(0);
}
if ($cmd === 'lang' || $cmd === 'lang-restore') {
    $jf = $qa . '/.restore-journal-q251.json';
    if (!is_file($jf)) {
        bad('jurnal user tidak ada (FIX-UP tidak jalan?)');
    }
    $snap = json_decode(file_get_contents($jf), true);
    $to = $cmd === 'lang' ? strtoupper($argv[2] ?? '') : $snap['lang'];
    if (!in_array($to, ['ID', 'EN'], true)) {
        bad('bahasa harus ID atau EN');
    }
    $c->table('users')->where('id_user', $snap['uid'])->update(['language' => $to]);
    out(['lang' => $c->table('users')->where('id_user', $snap['uid'])->value('language')]);
    exit(0);
}
bad('perintah tidak dikenal: ' . $cmd);
