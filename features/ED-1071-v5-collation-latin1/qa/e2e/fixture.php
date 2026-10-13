<?php
/**
 * Fixture e2e ED-1071 (BUKAN skenario regresi; dipanggil lewat qa/e2e/fixtures.json dan oleh spec).
 *
 *   fixture.php up          buat satu folder uji di root, "QA71-E2E-A" (folder biasa, semua lokasi, tanpa hak folder), catat jurnal + snapshot
 *   fixture.php down        buang baris "QA71-E2E-%" dan folder "QA71 %" (nama dengan spasi = salah buat oleh spec, mis. "QA71 ?") beserta
 *                           archive_documents/locations/permissions mereka dan baris jurnal; verifikasi tidak ada sisa dan jumlah
 *                           baris non-fixture = baseline. Folder salah buat membuat perintah ini keluar 1 (setelah dibuang).
 *   fixture.php ids         peta nama -> id_archive (JSON)
 *   fixture.php prop        kolom archives folder uji (JSON, baca saja)
 *   fixture.php lang        bahasa user QA_USER (baca saja)
 *   fixture.php snap        JSON hitungan: archives total, "QA71 %" (salah buat), archive_documents/locations/permissions, hash kolom folder uji
 *
 * Tidak mengubah role/bahasa user, tidak menyentuh archive_opname*, tidak menyentuh baris yang bukan buatannya.
 */

namespace QaHttp;

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../../..')));

require QA_AGENTIC . '/scripts/qa-http/lib/Outcome.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Config.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Laravel.php';

use Illuminate\Support\Facades\DB;

const PREFIX = 'QA71-E2E-';
const STRAY = 'QA71 ';   // nama berspasi: "QA71 😀" yang lolos validasi akan tersimpan "QA71 ?"

function say($line)
{
    fwrite(STDOUT, Config::redact($line) . "\n");
}

function fail($line)
{
    fwrite(STDERR, Config::redact('ERROR: ' . $line) . "\n");
    exit(1);
}

$cmd = $argv[1] ?? '';
$journal = __DIR__ . '/.fixture-journal.json';
$idsFile = __DIR__ . '/.fixture-ids.json';

Laravel::boot();
$db = Config::defaultDb();
$c = DB::connection($db);

function fixtureIds($c)
{
    return $c->table('archives')->where('name', 'like', PREFIX . '%')->pluck('id_archive')->all();
}

function strayIds($c)
{
    return $c->table('archives')->where('name', 'like', STRAY . '%')->pluck('id_archive')->all();
}

/** Jumlah baris di luar fixture + salah buat, per tabel. */
function counts($c)
{
    $own = array_merge(fixtureIds($c), strayIds($c));
    $out = [];
    $out['archives'] = (int) $c->table('archives')->where('name', 'not like', PREFIX . '%')->where('name', 'not like', STRAY . '%')->count();
    $out['archive_documents'] = (int) $c->table('archive_documents')->whereNotIn('id_archive', $own ?: ['-'])->count();
    $out['archive_locations'] = (int) $c->table('archive_locations')->whereNotIn('id_archive', $own ?: ['-'])->count();
    $out['archive_permissions'] = (int) $c->table('archive_permissions')->whereNotIn('id_archive', $own ?: ['-'])->count();

    return $out;
}

function purge($c, array $extraIds = [])
{
    $ids = array_values(array_unique(array_merge(fixtureIds($c), strayIds($c), $extraIds)));
    foreach (array_chunk($ids, 500) as $chunk) {
        $c->table('archive_permissions')->whereIn('id_archive', $chunk)->delete();
        $c->table('archive_documents')->whereIn('id_archive', $chunk)->delete();
        $c->table('archive_locations')->whereIn('id_archive', $chunk)->delete();
        $c->table('archives')->whereIn('id_archive', $chunk)->delete();
    }

    return count($ids);
}

if ($cmd === 'up') {
    if (is_file($journal)) { // run sebelumnya mati: buang dulu sisanya
        $old = json_decode(file_get_contents($journal), true);
        purge($c, $old['ids'] ?? []);
        @unlink($journal);
        say('jurnal sisa: baris fixture lama dibuang');
    }
    $stray = strayIds($c);
    if (count($stray) > 0) {
        say('CATATAN: ' . count($stray) . ' baris "QA71 %" sisa run lama dibuang sebelum up');
    }
    purge($c);

    $before = counts($c);
    $user = $c->table('users')->where('username', Config::get('QA_USER'))->first();
    if (!$user) {
        fail('QA_USER tidak ada di DB');
    }
    $id = \Modules\V5\Entities\Helper\MyHelper::generateId();
    $now = date('Y-m-d H:i:s');
    $by = 'QA71E';
    $c->table('archives')->insert([
        'id_archive' => $id, 'id_archive_parent' => null, 'name' => PREFIX . 'A', 'type' => 1, 'status' => 1,
        'is_all_location' => 1, 'is_folder_permission' => 0, 'is_active' => 1,
        'created_at' => $now, 'created_by' => $by, 'updated_at' => $now, 'updated_by' => $by,
    ]);
    $row = (array) $c->table('archives')->where('id_archive', $id)->first();
    file_put_contents($journal, json_encode(['ids' => [$id], 'before' => $before, 'snap' => $row]));
    file_put_contents($idsFile, json_encode([PREFIX . 'A' => $id, '_langOfQaUser' => $user->language]));
    say('fixture up: 1 baris archives ' . PREFIX . 'A; baseline non-fixture archives=' . $before['archives'] . ', archive_documents=' . $before['archive_documents'] . ', archive_locations=' . $before['archive_locations'] . ', archive_permissions=' . $before['archive_permissions']);
    exit(0);
}

if ($cmd === 'down') {
    $old = is_file($journal) ? json_decode(file_get_contents($journal), true) : null;
    $strayBefore = count(strayIds($c));
    $n = purge($c, $old['ids'] ?? []);
    $ok = true;
    $left = (int) $c->table('archives')->where('name', 'like', PREFIX . '%')->count() + (int) $c->table('archives')->where('name', 'like', STRAY . '%')->count();
    if ($left > 0) {
        $ok = false;
        say("SISA $left baris berawalan " . PREFIX . ' atau "' . STRAY . '"');
    }
    if ($old) {
        $leftIds = (int) $c->table('archives')->whereIn('id_archive', $old['ids'] ?? ['-'])->count();
        if ($leftIds > 0) {
            $ok = false;
            say("SISA $leftIds baris menurut jurnal");
        }
        $after = counts($c);
        foreach (['archives', 'archive_documents', 'archive_locations', 'archive_permissions'] as $t) {
            if (($old['before'][$t] ?? null) !== $after[$t]) {
                say("CATATAN $t non-fixture: sebelum=" . json_encode($old['before'][$t] ?? null) . ' sesudah=' . json_encode($after[$t]) . ' (bukan buatan fixture ini)');
            }
        }
        if ($ok) {
            @unlink($journal);
        }
    } else {
        say('tidak ada jurnal (down tanpa up?)');
    }
    @unlink($idsFile);
    if ($strayBefore > 0) {
        $ok = false;
        say("SALAH-BUAT: $strayBefore folder \"" . STRAY . '..." ada di DB saat teardown (dibuang); validasi GE0748 gagal mencegahnya');
    }
    say("fixture down: $n baris archives dibuang (+ dokumen/lokasi/izin-nya); " . ($ok ? 'tanpa sisa' : 'ADA SISA/SALAH-BUAT'));
    exit($ok ? 0 : 1);
}

if ($cmd === 'ids') {
    echo is_file($idsFile) ? file_get_contents($idsFile) : '{}';
    echo "\n";
    exit(0);
}

if ($cmd === 'prop') {
    $row = $c->table('archives')->where('name', PREFIX . 'A')->first();
    if (!$row) {
        fail('prop: baris ' . PREFIX . 'A tidak ada');
    }
    $a = (array) $row;
    $a['_hex_description'] = $row->description === null ? null : strtoupper(bin2hex($row->description));
    $a['_hex_name'] = strtoupper(bin2hex($row->name));
    echo json_encode($a) . "\n";
    exit(0);
}

if ($cmd === 'lang') {
    echo json_encode(['language' => $c->table('users')->where('username', Config::get('QA_USER'))->value('language')]) . "\n";
    exit(0);
}

if ($cmd === 'snap') {
    $stray = $c->table('archives')->where('name', 'like', STRAY . '%')->select('id_archive', 'name')->get();
    echo json_encode([
        'archives_total' => (int) $c->table('archives')->count(),
        'archives_non_fixture' => counts($c)['archives'],
        'stray_count' => count($stray),
        'stray_hex' => array_map(function ($r) {
            return strtoupper(bin2hex($r->name));
        }, $stray->all()),
        'archive_documents' => (int) $c->table('archive_documents')->count(),
        'archive_locations' => (int) $c->table('archive_locations')->count(),
        'archive_permissions' => (int) $c->table('archive_permissions')->count(),
        'archives_qa71_other' => (int) $c->table('archives')->where('name', 'like', 'QA71%')->where('name', 'not like', PREFIX . '%')->count(),
    ]) . "\n";
    exit(0);
}

fail('perintah tidak dikenal: ' . $cmd);
