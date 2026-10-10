<?php
/**
 * Fixture e2e ED-1069 (BUKAN skenario regresi; dipanggil lewat qa/e2e/fixtures.json dan oleh spec).
 *
 *   fixture.php up            buat folder/dokumen uji berawalan "0QA69E-" (peta di bawah); catat jurnal + snapshot awal
 *   fixture.php down          buang SEMUA baris berawalan "0QA69E-" (+ archive_documents/locations/permissions mereka) dan
 *                             baris yang dicatat jurnal; verifikasi tidak ada sisa dan jumlah baris non-fixture = baseline
 *   fixture.php restore       kembalikan SEMUA baris fixture ke keadaan snapshot awal (parent, is_active, history, dst.);
 *                             dipakai spec sesudah alur pindah yang sukses
 *   fixture.php ids           peta nama -> id_archive (JSON, tanpa rahasia)
 *   fixture.php prop <nama>   kolom archives satu baris fixture (JSON, baca saja)
 *   fixture.php lang          bahasa user QA_USER (baca saja)
 *
 * Tidak mengubah role/bahasa user, tidak menyentuh tabel archive_opname*, tidak menyentuh baris yang bukan buatan sendiri.
 * Pemilik QA_USER = role 3 (tidak bypass hak folder); semua folder Off (tanpa hak folder), jadi hak = role.
 *
 * Peta (root = null):
 *   0QA69E-A   root           >  0QA69E-B (several location, 1 lokasi: drawer Detail menampilkan select Lokasi)  >  0QA69E-C
 *   0QA69E-X   root           (folder lain di root, untuk pindah massal [A, X] -> C)
 *   0QA69E-T   root           tujuan sah
 *   0QA69E-M, 0QA69E-M2  root  folder yang boleh benar-benar dipindahkan ke T (lalu dipulihkan lewat `restore`)
 *   0QA69E-F   root           berisi 7 dokumen yatim (tipe 6, 7, 8, 9, 29, 31, 222: 0QA69E-D6 ... D222) dan 0QA69E-DREAL
 *                             (menunjuk transaksi NYATA, id_transaction disalin dari baris archive_documents yang ada)
 */

namespace QaHttp;

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../../..')));

require QA_AGENTIC . '/scripts/qa-http/lib/Outcome.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Config.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Laravel.php';

use Illuminate\Support\Facades\DB;

const PREFIX = '0QA69E-';
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

function rows($collection)
{
    return array_map(function ($r) {
        return (array) $r;
    }, $collection->all());
}

/** Jumlah baris NON-fixture per tabel (pengembang boleh mengubah data lain; hanya dilaporkan). */
function counts($c)
{
    $ids = $c->table('archives')->where('name', 'like', PREFIX . '%')->pluck('id_archive')->all();
    $out = [];
    $out['archives'] = (int) $c->table('archives')->where('name', 'not like', PREFIX . '%')->count();
    $out['archive_documents'] = (int) $c->table('archive_documents')->whereNotIn('id_archive', $ids ?: ['-'])->count();
    $out['archive_locations'] = (int) $c->table('archive_locations')->whereNotIn('id_archive', $ids ?: ['-'])->count();
    $out['archive_permissions'] = (int) $c->table('archive_permissions')->whereNotIn('id_archive', $ids ?: ['-'])->count();

    return $out;
}

function purge($c, array $extraIds = [])
{
    $ids = $c->table('archives')->where('name', 'like', PREFIX . '%')->pluck('id_archive')->all();
    $ids = array_values(array_unique(array_merge($ids, $extraIds)));
    foreach (array_chunk($ids, 500) as $chunk) {
        $c->table('archive_permissions')->whereIn('id_archive', $chunk)->delete();
        $c->table('archive_documents')->whereIn('id_archive', $chunk)->delete();
        $c->table('archive_locations')->whereIn('id_archive', $chunk)->delete();
        $c->table('archives')->whereIn('id_archive', $chunk)->delete();
    }
    $c->table('archive_documents')->where('transaction_no', 'like', PREFIX . '%')->delete();

    return count($ids);
}

if ($cmd === 'up') {
    if (is_file($journal)) { // run sebelumnya mati: buang dulu sisanya
        $old = json_decode(file_get_contents($journal), true);
        purge($c, $old['ids'] ?? []);
        @unlink($journal);
        say('jurnal sisa: baris fixture lama dibuang');
    }
    if ($c->table('archives')->where('name', 'like', PREFIX . '%')->count() > 0) {
        purge($c);
    }

    $before = counts($c);
    $user = $c->table('users')->where('username', Config::get('QA_USER'))->first();
    if (!$user) {
        fail('QA_USER tidak ada di DB');
    }

    $gen = function () {
        return \Modules\V5\Entities\Helper\MyHelper::generateId();
    };
    $now = date('Y-m-d H:i:s');
    $by = 'QA69E';
    $ids = [];
    $created = [];

    $active = $c->table('locations')->where('is_active', 1)->orderBy('id_location')->pluck('id_location')->all();
    if (count($active) < 2) {
        fail('prasyarat: minimal 2 lokasi aktif di DB uji');
    }

    $add = function ($label, $type, $parent, $allLocation = 1, array $locations = [], array $doc = []) use ($c, $gen, $now, $by, &$ids, &$created) {
        $name = PREFIX . $label;
        $id = $gen();
        $c->table('archives')->insert([
            'id_archive' => $id, 'id_archive_parent' => $parent, 'name' => $name, 'type' => $type, 'status' => 1,
            'is_all_location' => $allLocation, 'is_folder_permission' => 0, 'is_active' => 1,
            'created_at' => $now, 'created_by' => $by, 'updated_at' => $now, 'updated_by' => $by,
        ]);
        foreach ($locations as $loc) {
            $c->table('archive_locations')->insert(['id_archive' => $id, 'id_location' => $loc]);
        }
        if ($type == 2) {
            $c->table('archive_documents')->insert([
                'id_archive_document' => $gen(), 'id_archive' => $id,
                'id_transaction' => $doc['tx'] ?? ('QA69ETX' . substr($id, -10)),
                'transaction_no' => $name, 'transaction_type' => $doc['type'] ?? 6, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $ids[$name] = $id;
        $created[] = $id;

        return $id;
    };

    // dokumen NYATA yang punya transaksi (dipakai sebagai penunjuk, baris aslinya tidak diubah)
    $real = $c->table('archive_documents as d')->join('archives as a', 'a.id_archive', '=', 'd.id_archive')
        ->where('d.transaction_no', 'DO-JOG/2511/00003')->where('a.is_active', '>', 0)
        ->select('d.id_transaction', 'd.transaction_type')->first();
    if (!$real) {
        $real = $c->table('archive_documents as d')->join('archives as a', 'a.id_archive', '=', 'd.id_archive')
            ->whereIn('d.transaction_type', [6, 7, 8])->where('a.is_active', '>', 0)->where('d.id_transaction', 'not like', 'QA%')
            ->orderBy('d.id_archive_document')->select('d.id_transaction', 'd.transaction_type')->first();
    }

    $c->transaction(function () use ($add, $active, $real) {
        $a = $add('A', 1, null);
        $b = $add('B', 1, $a, 0, [$active[0]]);
        $add('C', 1, $b);
        $add('X', 1, null);
        $add('T', 1, null);
        $add('M', 1, null);
        $add('M2', 1, null);
        $f = $add('F', 1, null);
        foreach ([6, 7, 8, 9, 29, 31, 222] as $type) {
            $add('D' . $type, 2, $f, 1, [], ['type' => $type]); // id_transaction QA69ETX... tidak ada di tabel transaksi mana pun
        }
        if ($real) {
            $add('DREAL', 2, $f, 1, [], ['type' => (int) $real->transaction_type, 'tx' => $real->id_transaction]);
        }
    });

    // snapshot awal semua baris fixture (untuk `restore`)
    $snap = [];
    foreach (rows($c->table('archives')->whereIn('id_archive', $created)->get()) as $row) {
        $snap[$row['id_archive']] = $row;
    }
    $ids['_langOfQaUser'] = $user->language;
    $ids['_hasReal'] = $real ? ['type' => (int) $real->transaction_type] : null;
    file_put_contents($journal, json_encode(['ids' => $created, 'before' => $before, 'snap' => $snap]));
    file_put_contents($idsFile, json_encode($ids));
    say('fixture up: ' . count($created) . ' baris archives berawalan ' . PREFIX . '; baseline non-fixture archives=' . $before['archives'] . ', archive_documents=' . $before['archive_documents'] . '; DREAL=' . ($real ? 'tipe ' . $real->transaction_type : 'tidak ada'));
    exit(0);
}

if ($cmd === 'down') {
    $old = is_file($journal) ? json_decode(file_get_contents($journal), true) : null;
    $n = purge($c, $old['ids'] ?? []);
    $ok = true;
    $left = (int) $c->table('archives')->where('name', 'like', PREFIX . '%')->count()
        + (int) $c->table('archive_documents')->where('transaction_no', 'like', PREFIX . '%')->count();
    if ($left > 0) {
        $ok = false;
        say("SISA $left baris berawalan " . PREFIX);
    }
    if ($old) {
        $leftIds = (int) $c->table('archives')->whereIn('id_archive', $old['ids'] ?? ['-'])->count();
        if ($leftIds > 0) {
            $ok = false;
            say("SISA $leftIds baris menurut jurnal");
        }
        $after = counts($c);
        foreach (TABLES as $t) {
            if (($old['before'][$t] ?? null) !== $after[$t]) {
                // pengembang boleh mengubah data lain secara bersamaan: hanya dilaporkan, tidak menggagalkan
                say("CATATAN $t non-fixture: sebelum=" . json_encode($old['before'][$t] ?? null) . ' sesudah=' . json_encode($after[$t]) . ' (bukan buatan fixture ini; fixture hanya menghapus baris berawalan ' . PREFIX . ')');
            }
        }
        if ($ok) {
            @unlink($journal);
        }
    } else {
        say('tidak ada jurnal (down tanpa up?)');
    }
    @unlink($idsFile);
    say("fixture down: $n baris archives berawalan " . PREFIX . ' dibuang (+ dokumen/lokasi/izin-nya); ' . ($ok ? 'tanpa sisa' : 'ADA SISA'));
    exit($ok ? 0 : 1);
}

if ($cmd === 'restore') {
    if (!is_file($journal)) {
        fail('restore: jurnal tidak ada (jalankan up dulu)');
    }
    $old = json_decode(file_get_contents($journal), true);
    $c->transaction(function () use ($c, $old) {
        foreach ($old['snap'] as $id => $row) {
            $c->table('archives')->where('id_archive', $id)->update($row);
        }
    });
    $bad = 0;
    foreach ($old['snap'] as $id => $row) {
        $now = (array) $c->table('archives')->where('id_archive', $id)->first();
        if (json_encode($now) !== json_encode($row)) {
            $bad++;
        }
    }
    say('fixture restore: ' . count($old['snap']) . ' baris dikembalikan; beda=' . $bad);
    exit($bad ? 1 : 0);
}

if ($cmd === 'ids') {
    echo is_file($idsFile) ? file_get_contents($idsFile) : '{}';
    echo "\n";
    exit(0);
}

if ($cmd === 'prop') {
    $name = $argv[2] ?? '';
    $row = $c->table('archives')->where('name', PREFIX . $name)->first();
    if (!$row) {
        fail("prop: baris " . PREFIX . "$name tidak ada");
    }
    echo json_encode((array) $row) . "\n";
    exit(0);
}

if ($cmd === 'lang') {
    echo json_encode(['language' => $c->table('users')->where('username', Config::get('QA_USER'))->value('language')]) . "\n";
    exit(0);
}

fail('perintah tidak dikenal: ' . $cmd);
