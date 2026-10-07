<?php
/**
 * Fixture e2e ED-1025 (BUKAN skenario regresi; dipanggil lewat qa/e2e/fixtures.json).
 *
 *   "$PHP_BIN" fixture.php up     buat folder/dokumen uji berawalan "0QA25-" + baris archive_permissions; catat baseline
 *   "$PHP_BIN" fixture.php down   buang SEMUA baris berawalan "0QA25-" (archives, archive_documents, archive_locations,
 *                                 archive_permissions) lalu verifikasi jumlah baris + CHECKSUM tabel = baseline
 *   "$PHP_BIN" fixture.php ids    cetak peta nama -> id_archive (JSON, tanpa rahasia)
 *
 * Pengguna: A = QA_USER (profil default), B = QA_USER2 (profil user2). Keduanya non-superadmin (role 3 dan 5), jadi tidak
 * di-bypass hak folder. Nama diawali "0" supaya urut paling awal di daftar root (urutan BE: type, name).
 *
 *   0QA25-EDIT   nonaktif, created_by = A (AC-17: A mengubah permission lewat drawer, tetap boleh karena pembuat)
 *   0QA25-P      aktif; A V+U+D+S, B View saja           (AC-3, AC-18, AC-20, AC-21)
 *     0QA25-P-C    nonaktif (anak; hak B diwarisi dari P)
 *     0QA25-P-DOC  dokumen
 *   0QA25-DENY   aktif; A V+U+D+S, B tanpa baris         (AC-19, AC-20)
 *     0QA25-DENY-C aktif; A V+U+D+S, B View (ditolak induk -> denied_by = 0QA25-DENY)
 *   0QA25-STORE  aktif; A V+U+D+S, B View+Store          (AC-20, AC-21)
 *   0QA25-MOVE   nonaktif, bebas                          (AC-21: folder yang dipindahkan B)
 */

namespace QaHttp;

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../../..')));

require QA_AGENTIC . '/scripts/qa-http/lib/Outcome.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Config.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Laravel.php';

use Illuminate\Support\Facades\DB;

const PREFIX = '0QA25-';
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

function state($c)
{
    $out = [];
    foreach (TABLES as $t) {
        $row = (array) $c->selectOne("CHECKSUM TABLE `$t`");
        $out[$t] = ['count' => (int) $c->table($t)->count(), 'checksum' => (string) ($row['Checksum'] ?? '')];
    }

    return $out;
}

function purge($c)
{
    $ids = $c->table('archives')->where('name', 'like', PREFIX . '%')->pluck('id_archive')->all();
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
    if (is_file($journal)) { // run sebelumnya mati: pulihkan dulu
        purge($c);
        $old = json_decode(file_get_contents($journal), true);
        $now = state($c);
        if ($old && $old !== $now) {
            fail('sisa jurnal run lama: keadaan setelah purge tidak sama dengan baseline lama');
        }
        @unlink($journal);
    }
    if ($c->table('archives')->where('name', 'like', PREFIX . '%')->count() > 0) {
        purge($c);
    }

    $userA = $c->table('users')->where('username', Config::get('QA_USER'))->first();
    $userB = Config::get('QA_USER2') ? $c->table('users')->where('username', Config::get('QA_USER2'))->first() : null;
    if (!$userA || !$userB) {
        fail('QA_USER / QA_USER2 tidak ditemukan di DB');
    }

    $before = state($c);
    file_put_contents($journal, json_encode($before));

    $ids = [];
    $now = date('Y-m-d H:i:s');
    $gen = function () {
        return \Modules\V5\Entities\Helper\MyHelper::generateId();
    };
    $add = function ($name, $type, $parent, $perm, $by) use ($c, &$ids, $now, $gen) {
        $id = $gen();
        $c->table('archives')->insert([
            'id_archive' => $id, 'id_archive_parent' => $parent, 'name' => $name, 'type' => $type, 'status' => 1,
            'is_all_location' => 1, 'is_folder_permission' => $perm, 'is_active' => 1,
            'created_at' => $now, 'created_by' => $by, 'updated_at' => $now, 'updated_by' => $by,
        ]);
        if ($type == 2) {
            $c->table('archive_documents')->insert([
                'id_archive_document' => $gen(), 'id_archive' => $id, 'id_transaction' => 'QA25TX' . substr($id, -10),
                'transaction_no' => $name, 'transaction_type' => 6, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $ids[$name] = $id;

        return $id;
    };
    $perm = function ($idArchive, $idUser, $v, $u, $d, $s) use ($c, $now, $gen) {
        $c->table('archive_permissions')->insert([
            'id_archive_permission' => $gen(), 'id_archive' => $idArchive, 'id_user' => $idUser,
            'is_view' => $v, 'is_update' => $u, 'is_delete' => $d, 'is_store' => $s,
            'created_at' => $now, 'created_by' => 'QA25E2E', 'updated_at' => $now, 'updated_by' => 'QA25E2E',
        ]);
    };

    $c->transaction(function () use ($add, $perm, $userA, $userB) {
        $add(PREFIX . 'EDIT', 1, null, 0, $userA->username);

        $p = $add(PREFIX . 'P', 1, null, 1, 'QA25E2E');
        $perm($p, $userA->id_user, 1, 1, 1, 1);
        $perm($p, $userB->id_user, 1, 0, 0, 0);
        $add(PREFIX . 'P-C', 1, $p, 0, 'QA25E2E');
        $add(PREFIX . 'P-DOC', 2, $p, 0, 'QA25E2E');

        $d = $add(PREFIX . 'DENY', 1, null, 1, 'QA25E2E');
        $perm($d, $userA->id_user, 1, 1, 1, 1);
        $dc = $add(PREFIX . 'DENY-C', 1, $d, 1, 'QA25E2E');
        $perm($dc, $userA->id_user, 1, 1, 1, 1);
        $perm($dc, $userB->id_user, 1, 0, 0, 0);

        $s = $add(PREFIX . 'STORE', 1, null, 1, 'QA25E2E');
        $perm($s, $userA->id_user, 1, 1, 1, 1);
        $perm($s, $userB->id_user, 1, 0, 0, 1);

        $add(PREFIX . 'MOVE', 1, null, 0, 'QA25E2E');
    });

    $ids['_userA'] = ['id_user' => $userA->id_user, 'username' => $userA->username];
    $ids['_userB'] = ['id_user' => $userB->id_user, 'username' => $userB->username];
    file_put_contents($idsFile, json_encode($ids));
    say('fixture up: ' . count($ids) . ' entri; baseline archives=' . $before['archives']['count'] . ' archive_permissions=' . $before['archive_permissions']['count']);
    exit(0);
}

if ($cmd === 'down') {
    $n = purge($c);
    $after = state($c);
    $ok = true;
    if (is_file($journal)) {
        $before = json_decode(file_get_contents($journal), true);
        foreach (TABLES as $t) {
            if (($before[$t] ?? null) !== $after[$t]) {
                $ok = false;
                say("BEDA $t: sebelum=" . json_encode($before[$t] ?? null) . ' sesudah=' . json_encode($after[$t]));
            }
        }
        if ($ok) {
            @unlink($journal);
        }
    } else {
        say('tidak ada jurnal baseline (down tanpa up?)');
    }
    @unlink($idsFile);
    say("fixture down: $n folder/dokumen berawalan " . PREFIX . ' dibuang; ' . ($ok ? 'keadaan tabel = baseline (count + CHECKSUM)' : 'KEADAAN TIDAK SAMA DENGAN BASELINE'));
    exit($ok ? 0 : 1);
}

if ($cmd === 'ids') {
    echo is_file($idsFile) ? file_get_contents($idsFile) : '{}';
    echo "\n";
    exit(0);
}

fail('pakai: up | down | ids');
