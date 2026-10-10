<?php
/**
 * Fixture e2e ED-1072 (BUKAN skenario regresi; dipanggil lewat qa/e2e/fixtures.json dan oleh spec untuk membaca peta id).
 *
 *   fixture.php up        buat folder/dokumen uji berawalan "0QA72-" + satu baris archive_permissions; catat jurnal
 *                         (id yang dibuat + count/CHECKSUM tabel archive* sebelum)
 *   fixture.php down      buang HANYA baris yang dicatat jurnal (+ sapu berawalan "0QA72-"); verifikasi tidak ada sisa milik
 *                         fixture. count/CHECKSUM tabel archive* dibandingkan dengan baseline: sama = "= baseline"; beda =
 *                         CATATAN saja (developer boleh menulis ke tabel itu bersamaan; bukan kegagalan fixture)
 *   fixture.php ids       peta nama -> id_archive (JSON, tanpa rahasia)
 *   fixture.php inspect   baris milik fixture + status/pilihan (JSON, baca-saja): dipakai spec untuk membuktikan DB tidak berubah
 *   fixture.php real-doc  satu dokumen Delivery Order NYATA (bukan milik fixture) yang di dalam folder (breadcrumbs terisi) dan yang
 *                         transaksinya ada di delivery_orders (JSON, BACA-SAJA): untuk AC-9, karena "Open in new tab" memasang tab
 *                         halaman DO dan halaman itu memanggil GET sales/delivery-orders/{id}; id transaksi palsu = 404 (ditolak
 *                         harness). Tidak ada baris yang dibuat/diubah/dibuang untuk ini.
 *
 * Tidak menyentuh tabel opname, folder developer, role, atau user. Hanya DB QA_DB (api_sidomaju, DB uji/pengembangan).
 *
 * Peta (semua berawalan "0QA72-"; baris `breadcrumbs` dokumen terisi seperti yang ditulis BE saat dokumen diletakkan):
 *   F     folder root, tanpa folder permission (V/T = 0/2 untuk dokumen di dalamnya)
 *     D1  dokumen Delivery Order (tipe 7), status 1 (Di arsip), breadcrumbs = [F]
 *     D2  dokumen Delivery Order (tipe 7), status 2 (Handed Over: Tag di sel Nama), breadcrumbs = [F]
 *     S   subfolder (supaya baris folder ada di dalam F)
 *   FP    folder root ber-folder-permission (ikon perisai), hak penuh untuk QA_USER
 *   FE    folder root kosong (V/T = 0/0)
 */

namespace QaHttp;

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../../..')));

require QA_AGENTIC . '/scripts/qa-http/lib/Outcome.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Config.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Laravel.php';

use Illuminate\Support\Facades\DB;

const PREFIX = '0QA72-';
const TABLES = ['archives', 'archive_documents', 'archive_locations', 'archive_permissions'];

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

function state($c, array $tables)
{
    $out = [];
    foreach ($tables as $t) {
        $row = (array) $c->selectOne("CHECKSUM TABLE `$t`");
        $out[$t] = ['count' => (int) $c->table($t)->count(), 'checksum' => (string) ($row['Checksum'] ?? '')];
    }

    return $out;
}

/** buang baris milik fixture: menurut id jurnal, lalu sapu berawalan (sisa run yang mati sebelum jurnal lengkap) */
function purge($c, array $journalIds)
{
    $ids = array_values(array_unique(array_merge(
        $journalIds,
        $c->table('archives')->where('name', 'like', PREFIX . '%')->pluck('id_archive')->all()
    )));
    foreach (array_chunk($ids, 500) as $chunk) {
        $c->table('archive_permissions')->whereIn('id_archive', $chunk)->delete();
        $c->table('archive_documents')->whereIn('id_archive', $chunk)->delete();
        $c->table('archive_locations')->whereIn('id_archive', $chunk)->delete();
        $c->table('archives')->whereIn('id_archive', $chunk)->delete();
    }
    $c->table('archive_documents')->where('transaction_no', 'like', PREFIX . '%')->delete();

    return count($ids);
}

function leftovers($c)
{
    return (int) $c->table('archives')->where('name', 'like', PREFIX . '%')->count()
        + (int) $c->table('archive_documents')->where('transaction_no', 'like', PREFIX . '%')->count();
}

if ($cmd === 'up') {
    if (is_file($journal)) { // run sebelumnya mati: buang sisanya dulu
        $old = json_decode(file_get_contents($journal), true);
        purge($c, $old['ids'] ?? []);
        @unlink($journal);
    }
    purge($c, []);

    $userA = $c->table('users')->where('username', Config::get('QA_USER'))->first();
    if (!$userA) {
        fail('user QA_USER tidak ada di DB');
    }

    $before = state($c, TABLES);
    $created = [];
    file_put_contents($journal, json_encode(['before' => $before, 'ids' => []]));

    $ids = [];
    $now = date('Y-m-d H:i:s');
    $gen = function () {
        return \Modules\V5\Entities\Helper\MyHelper::generateId();
    };
    $E = 'QA72E2E';
    $add = function ($name, $type, $parent, $perm, $status = 1, $breadcrumbs = null) use ($c, &$ids, &$created, $now, $gen, $E, $journal, $before) {
        $id = $gen();
        $created[] = $id;
        file_put_contents($journal, json_encode(['before' => $before, 'ids' => $created])); // jurnal lebih dulu dari baris
        $c->table('archives')->insert([
            'id_archive' => $id, 'id_archive_parent' => $parent, 'name' => $name, 'type' => $type, 'status' => $status,
            'breadcrumbs' => $breadcrumbs === null ? null : json_encode($breadcrumbs),
            'is_all_location' => 1, 'is_folder_permission' => $perm, 'is_active' => 1,
            'created_at' => $now, 'created_by' => $E, 'updated_at' => $now, 'updated_by' => $E,
        ]);
        if ($type == 2) {
            $c->table('archive_documents')->insert([
                'id_archive_document' => $gen(), 'id_archive' => $id, 'id_transaction' => 'QA72TX' . substr($id, -10),
                'transaction_no' => $name, 'transaction_type' => 7, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $ids[$name] = $id;

        return $id;
    };

    $c->transaction(function () use ($add, $c, $userA, $now, $gen, $E) {
        $f = $add(PREFIX . 'F', 1, null, 0);
        $crumb = [['value' => $f, 'label' => PREFIX . 'F']];
        $add(PREFIX . 'D1', 2, $f, 0, 1, $crumb);
        $add(PREFIX . 'D2', 2, $f, 0, 2, $crumb);
        $add(PREFIX . 'S', 1, $f, 0, 1, null);

        $fp = $add(PREFIX . 'FP', 1, null, 1);
        $c->table('archive_permissions')->insert([
            'id_archive_permission' => $gen(), 'id_archive' => $fp, 'id_user' => $userA->id_user,
            'is_view' => 1, 'is_update' => 1, 'is_delete' => 1, 'is_store' => 1,
            'created_at' => $now, 'created_by' => $E, 'updated_at' => $now, 'updated_by' => $E,
        ]);

        $add(PREFIX . 'FE', 1, null, 0);
    });

    $ids['_userA'] = ['id_user' => $userA->id_user, 'username' => $userA->username];
    file_put_contents($idsFile, json_encode($ids));
    say('fixture up: ' . (count($ids) - 1) . ' baris archive (folder/dokumen) + 1 archive_permissions; baseline archives=' . $before['archives']['count'] . ' archive_documents=' . $before['archive_documents']['count'] . ' archive_permissions=' . $before['archive_permissions']['count']);
    exit(0);
}

if ($cmd === 'down') {
    $old = is_file($journal) ? json_decode(file_get_contents($journal), true) : null;
    $n = purge($c, $old['ids'] ?? []);
    $ok = true;
    if (leftovers($c) > 0) {
        $ok = false;
        say('MASIH ADA sisa berawalan ' . PREFIX);
    }
    $after = state($c, TABLES);
    $same = true;
    if ($old) {
        foreach (TABLES as $t) {
            if (($old['before'][$t] ?? null) !== $after[$t]) {
                $same = false;
                say("CATATAN $t (informasi): sebelum=" . json_encode($old['before'][$t] ?? null) . ' sesudah=' . json_encode($after[$t]) . ' (proses lain/developer dapat menulis bersamaan; baris fixture sendiri sudah 0)');
            }
        }
        if ($ok) {
            @unlink($journal);
        }
    } else {
        say('tidak ada jurnal baseline (down tanpa up?)');
    }
    @unlink($idsFile);
    say("fixture down: $n baris archive milik fixture dibuang; sisa berawalan " . PREFIX . ' = ' . leftovers($c) . '; ' . ($same && $old ? 'tabel archive* = baseline (count + CHECKSUM)' : 'baseline berbeda (lihat CATATAN) atau tanpa jurnal'));
    exit($ok ? 0 : 1);
}

if ($cmd === 'ids') {
    echo is_file($idsFile) ? file_get_contents($idsFile) : '{}';
    echo "\n";
    exit(0);
}

if ($cmd === 'inspect') {
    $rows = $c->table('archives')->where('name', 'like', PREFIX . '%')->orderBy('name')->get(['id_archive', 'id_archive_parent', 'name', 'type', 'status', 'is_folder_permission', 'is_active', 'breadcrumbs', 'updated_at']);
    $out = [];
    foreach ($rows as $r) {
        $out[$r->name] = (array) $r;
    }
    echo json_encode($out) . "\n";
    exit(0);
}

if ($cmd === 'real-doc') {
    $r = $c->selectOne(
        "select a.id_archive, a.name, a.breadcrumbs, d.transaction_no, d.id_transaction from archives a "
        . "join archive_documents d on d.id_archive = a.id_archive "
        . "join delivery_orders o on o.id_delivery_order = d.id_transaction "
        . "where a.is_active > 0 and a.type = 2 and a.status = 1 and a.breadcrumbs is not null and a.name not like ? and d.transaction_type = 7 "
        . "order by a.id_archive limit 1",
        [PREFIX . '%']
    );
    echo json_encode($r ? (array) $r : null) . "\n";
    exit(0);
}

fail('perintah tidak dikenal: ' . $cmd . ' (up | down | ids | inspect | real-doc)');
