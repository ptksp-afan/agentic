<?php
/**
 * Fixture e2e ED-1028 (BUKAN skenario regresi; dipanggil lewat qa/e2e/fixtures.json dan spec).
 *
 *   "$PHP_BIN" fixture.php up         buat folder/dokumen uji berawalan "0QA28-" (tanpa sesi opname) + snapshot baris display setting
 *   "$PHP_BIN" fixture.php sessions   sisipkan sesi opname uji (14 terkonfirmasi + 1 tersembunyi + draft + batal); gagal bila tabel opname tidak kosong
 *   "$PHP_BIN" fixture.php ensure-sessions   seperti sessions, tetapi tanpa efek bila tabel opname sudah berisi
 *   "$PHP_BIN" fixture.php reset-sessions    kosongkan tabel opname (kolom verifikasi dokumen yang tersentuh dikembalikan); folder/dokumen uji tetap
 *   "$PHP_BIN" fixture.php down       buang SEMUA data uji + semua baris archive_opname*, pulihkan display setting persis, lalu
 *                                     verifikasi jumlah baris + CHECKSUM 8 tabel (7 archive* + column_display_settings) = baseline
 *   "$PHP_BIN" fixture.php ids        cetak peta nama -> id (JSON, tanpa rahasia)
 *   "$PHP_BIN" fixture.php inspect    cetak keadaan DB (dokumen uji + sesi; JSON, baca saja)
 *   "$PHP_BIN" fixture.php display    cetak kolom aktif display setting riwayat (JSON, baca saja)
 *
 * Pohon uji (semua aktif, is_all_location=1; user QA = role 3 semua lokasi bukan superadmin, user2 = Sales Supervisor lokasi SMR):
 *   0QA28-ALFA   A1(6) A2(7) | ALFA-S1 (S1a 6) | ALFA-S1-X (X1 6, di bawah S1) | ALFA-S2 (S2a 7)
 *   0QA28-BRAVO  B1(6) | BRAVO-T1 (T1a 7)
 *   0QA28-GAMMA  GD1(6) GD2(7) GD3(8)     (alur Confirm Opname nyata lewat UI)
 *   0QA28-EMPTYF kosong (folder tanpa sesi sama sekali)
 *   0QA28-SECRET folder permission aktif tanpa baris user (tak terlihat user QA maupun user2): H1(6) H2(7)
 *   0QA28-VIEWONLY folder permission aktif, user QA hanya View (drawer folder read-only); user2 tanpa baris = tak terlihat: VD1(6)
 *   dokumen root 0QA28-RD (6)
 *
 * Sesi (snapshot; terbaru dulu). Semua terkonfirmasi dan terlihat user QA/user2 kecuali yang ditandai:
 *   N01 alice  ALFA scope "0QA28-ALFA-S1 + 1 subfolder"  total 5 verified 3 not found 1 invalid 1 scanned 5 unscanned 2   (hasil lengkap, 7 baris)
 *   N02 root   root  "All Archive · root" total 8 verified 3 nf 1 inv 1 scanned 5 unscanned 2    (PARSIAL untuk user QA/user2: H1/H2 di SECRET)
 *   N03 bob    BRAVO "0QA28-BRAVO"
 *   N04 carol  BRAVO-T1   N05 alice ALFA-S2   N06 dave BRAVO    N07 erin ALFA-S1   N08 bob BRAVO   N09 carol ALFA
 *   N10 dave   BRAVO      N11 alice ALFA-S1-X N12 erin BRAVO    N13 bob ALFA
 *   N14 qa28view VIEWONLY (terlihat user QA, TIDAK terlihat user2)
 *   HID  qa28hid SECRET (terkonfirmasi, tidak terlihat user QA/user2)   DRAFT qa28draft BRAVO (status 1)   CANC qa28cancel BRAVO (status 3)
 */

namespace QaHttp;

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../../..')));

require QA_AGENTIC . '/scripts/qa-http/lib/Outcome.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Config.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Laravel.php';

use Illuminate\Support\Facades\DB;

const PREFIX = '0QA28-';
const TABLES = ['archives', 'archive_documents', 'archive_locations', 'archive_permissions', 'archive_opnames', 'archive_opname_folders', 'archive_opname_documents', 'column_display_settings'];
const OP_TABLES = ['archive_opnames', 'archive_opname_folders', 'archive_opname_documents'];
const DISPLAY_MODULES = ['documentArchive', 'documentArchiveOpnameHistory'];

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

function displayRows($c)
{
    $out = [];
    foreach ($c->table('column_display_settings')->whereIn('module_name', DISPLAY_MODULES)->orderBy('module_name')->orderBy('id_column_display_setting')->get() as $r) {
        $out[] = (array) $r;
    }

    return $out;
}

function displayRestore($c, array $snap)
{
    $c->table('column_display_settings')->whereIn('module_name', DISPLAY_MODULES)->delete();
    foreach ($snap as $row) {
        $c->table('column_display_settings')->insert($row);
    }
}

/** Buang data uji archive + semua baris opname; baris archive yang tersentuh sesi dikembalikan ke kolom verifikasi default. */
function purge($c)
{
    $sessions = $c->table('archive_opnames')->pluck('id_archive_opname')->all();
    foreach (array_chunk($sessions, 200) as $chunk) {
        $c->table('archives')->whereIn('id_archive_opname', $chunk)->update([
            'is_verified' => 0, 'verified_at' => null, 'verified_by' => null, 'id_archive_opname' => null,
        ]);
    }
    foreach (OP_TABLES as $t) {
        $c->table($t)->delete();
    }

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

function compare($before, $after, &$lines)
{
    $ok = true;
    foreach (array_keys($before) as $t) {
        if (($before[$t] ?? null) !== ($after[$t] ?? null)) {
            $ok = false;
            $lines[] = "BEDA $t: sebelum=" . json_encode($before[$t] ?? null) . ' sesudah=' . json_encode($after[$t] ?? null);
        }
    }

    return $ok;
}

$gen = function () {
    return \Modules\V5\Entities\Helper\MyHelper::generateId();
};

if ($cmd === 'up') {
    if (is_file($journal)) { // run sebelumnya mati: pulihkan dulu
        $old = json_decode(file_get_contents($journal), true);
        purge($c);
        displayRestore($c, $old['display']);
        $lines = [];
        if (!compare($old['state'], state($c), $lines)) {
            fail('sisa jurnal run lama tidak bisa dipulihkan ke baseline: ' . implode('; ', $lines));
        }
        @unlink($journal);
    }
    if ($c->table('archives')->where('name', 'like', PREFIX . '%')->count() > 0) {
        purge($c);
    }
    foreach (OP_TABLES as $t) {
        if ($c->table($t)->count() > 0) {
            fail("tabel $t tidak kosong sebelum fixture (prasyarat: tabel opname kosong)");
        }
    }

    $before = state($c);
    file_put_contents($journal, json_encode(['state' => $before, 'display' => displayRows($c)]));

    $nowStr = \Carbon\Carbon::now('Asia/Jakarta')->format('Y-m-d H:i:s');
    $ids = [];
    $by = 'QA28E2E';
    $add = function ($name, $type, $parent, $txType = 6, $o = []) use ($c, &$ids, $nowStr, $gen, $by) {
        $id = $gen();
        $c->table('archives')->insert([
            'id_archive' => $id, 'id_archive_parent' => $parent, 'name' => $name, 'type' => $type, 'status' => 1,
            'history' => null, 'is_all_location' => 1, 'is_folder_permission' => $o['perm'] ?? 0, 'is_active' => 1,
            'is_verified' => 0, 'verified_at' => null, 'verified_by' => null,
            'created_at' => $nowStr, 'created_by' => $by, 'updated_at' => $nowStr, 'updated_by' => $by,
        ]);
        if ($type == 2) {
            $c->table('archive_documents')->insert([
                'id_archive_document' => $gen(), 'id_archive' => $id, 'id_transaction' => 'QA28TX' . substr($id, -10),
                'transaction_no' => $name, 'transaction_type' => $txType, 'related_employee_name' => 'Sales QA28',
                'created_at' => $nowStr, 'updated_at' => $nowStr,
            ]);
        }
        $ids[$name] = $id;

        return $id;
    };

    $user = $c->table('users')->where('username', Config::get('QA_USER'))->first();
    if (!$user) {
        fail('QA_USER tidak ditemukan di DB');
    }

    $c->transaction(function () use ($add, $c, $user, $gen, $nowStr, $by) {
        $alfa = $add(PREFIX . 'ALFA', 1, null);
        $add(PREFIX . 'A1', 2, $alfa, 6);
        $add(PREFIX . 'A2', 2, $alfa, 7);
        $s1 = $add(PREFIX . 'ALFA-S1', 1, $alfa);
        $add(PREFIX . 'S1a', 2, $s1, 6);
        $x = $add(PREFIX . 'ALFA-S1-X', 1, $s1);
        $add(PREFIX . 'X1', 2, $x, 6);
        $s2 = $add(PREFIX . 'ALFA-S2', 1, $alfa);
        $add(PREFIX . 'S2a', 2, $s2, 7);

        $bravo = $add(PREFIX . 'BRAVO', 1, null);
        $add(PREFIX . 'B1', 2, $bravo, 6);
        $t1 = $add(PREFIX . 'BRAVO-T1', 1, $bravo);
        $add(PREFIX . 'T1a', 2, $t1, 7);

        $gamma = $add(PREFIX . 'GAMMA', 1, null);
        $add(PREFIX . 'GD1', 2, $gamma, 6);
        $add(PREFIX . 'GD2', 2, $gamma, 7);
        $add(PREFIX . 'GD3', 2, $gamma, 8);

        $add(PREFIX . 'EMPTYF', 1, null);

        $secret = $add(PREFIX . 'SECRET', 1, null, 6, ['perm' => 1]);
        $add(PREFIX . 'H1', 2, $secret, 6);
        $add(PREFIX . 'H2', 2, $secret, 7);

        $vo = $add(PREFIX . 'VIEWONLY', 1, null, 6, ['perm' => 1]);
        $add(PREFIX . 'VD1', 2, $vo, 6);
        $c->table('archive_permissions')->insert([
            'id_archive_permission' => $gen(), 'id_archive' => $vo, 'id_user' => $user->id_user,
            'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0,
            'created_at' => $nowStr, 'created_by' => $by, 'updated_at' => $nowStr, 'updated_by' => $by,
        ]);

        $add(PREFIX . 'RD', 2, null, 6);
    });

    $ids['_user'] = ['username' => Config::get('QA_USER'), 'username2' => Config::get('QA_USER2')];
    file_put_contents($idsFile, json_encode($ids));
    say('fixture up: ' . (count($ids) - 1) . ' entri archive; archives=' . $before['archives']['count'] . ' opname=' . $before['archive_opnames']['count'] . ' (tanpa sesi)');
    exit(0);
}

if ($cmd === 'reset-sessions') {
    $sessions = $c->table('archive_opnames')->pluck('id_archive_opname')->all();
    foreach (array_chunk($sessions, 200) as $chunk) {
        $c->table('archives')->whereIn('id_archive_opname', $chunk)->update([
            'is_verified' => 0, 'verified_at' => null, 'verified_by' => null, 'id_archive_opname' => null,
        ]);
    }
    foreach (OP_TABLES as $t) {
        $c->table($t)->delete();
    }
    if (is_file($idsFile)) {
        $ids = json_decode(file_get_contents($idsFile), true);
        unset($ids['_sessions']);
        file_put_contents($idsFile, json_encode($ids));
    }
    say('fixture reset-sessions: ' . count($sessions) . ' sesi dibuang');
    exit(0);
}

if ($cmd === 'sessions' || $cmd === 'ensure-sessions') {
    if (!is_file($idsFile)) {
        fail('jalankan up dulu');
    }
    $ids = json_decode(file_get_contents($idsFile), true);
    if ($cmd === 'ensure-sessions' && $c->table('archive_opnames')->count() > 0) {
        say('fixture ensure-sessions: sudah ada sesi, tidak ada perubahan');
        exit(0);
    }
    foreach (OP_TABLES as $t) {
        if ($c->table($t)->count() > 0) {
            fail("tabel $t tidak kosong sebelum sesi fixture");
        }
    }
    $P = PREFIX;
    $sessions = [];

    $session = function ($key, $idArchive, $scopeName, $scopeCount, $at, $createdBy, $n, $status = 2) use ($c, $gen, &$sessions) {
        // $n = [total, verified, notFound, invalid, scanned, unscanned]
        $sid = $gen();
        $c->table('archive_opnames')->insert([
            'id_archive_opname' => $sid, 'id_archive' => $idArchive, 'status' => $status, 'scope_name' => $scopeName,
            'scope_folder_count' => $scopeCount, 'total_documents' => $n[0], 'verified_before_count' => 0,
            'scanned_count' => $n[4], 'verified_count' => $n[1], 'not_found_count' => $n[2], 'invalid_count' => $n[3],
            'unscanned_count' => $n[5], 'unverified_count' => 0,
            'selected_at' => $at, 'confirmed_at' => $status === 2 ? $at : null, 'created_at' => $at,
            'created_by' => $createdBy, 'updated_at' => $at, 'updated_by' => $createdBy,
        ]);
        $sessions[$key] = $sid;

        return $sid;
    };
    $folderRow = function ($sid, $level, $name, $idArchive, $parent, $at) use ($c, $gen) {
        $c->table('archive_opname_folders')->insert([
            'id_archive_opname_folder' => $gen(), 'id_archive_opname' => $sid, 'id_archive' => $idArchive,
            'id_archive_parent' => $parent, 'name' => $name, 'level' => $level, 'is_continue' => 0, 'is_opnamed_today' => 0,
            'total_documents' => 1, 'verified_count' => 0, 'verified_after_count' => 0, 'unverified_count' => 0, 'created_at' => $at,
        ]);
    };
    // $result: 1 verified, 2 not found, 3 invalid, 4 unscanned
    $docRow = function ($sid, $result, $code, $idDoc, $idFolder, $at) use ($c, $gen) {
        $c->table('archive_opname_documents')->insert([
            'id_archive_opname_document' => $gen(), 'id_archive_opname' => $sid, 'id_archive' => $idDoc,
            'id_archive_folder' => $idFolder, 'code' => $code, 'result' => $result,
            'is_verified_after' => $result === 1 ? 1 : 0, 'scanned_at' => $result === 4 ? null : $at . '.000000', 'created_at' => $at,
        ]);
    };

    $c->transaction(function () use ($ids, $P, $session, $folderRow, $docRow) {
        $f = function ($k) use ($ids, $P) {
            return $ids[$P . $k];
        };

        // N01: ALFA + S1 + S2 (X ikut otomatis), hasil lengkap
        $at = '2026-10-06 16:40:00';
        $s = $session('N01', $f('ALFA'), $P . 'ALFA-S1', 2, $at, 'qa28alice', [5, 3, 1, 1, 5, 2]);
        $folderRow($s, 0, $P . 'ALFA', $f('ALFA'), null, $at);
        $folderRow($s, 1, $P . 'ALFA-S1', $f('ALFA-S1'), $f('ALFA'), $at);
        $folderRow($s, 1, $P . 'ALFA-S2', $f('ALFA-S2'), $f('ALFA'), $at);
        $folderRow($s, 2, $P . 'ALFA-S1-X', $f('ALFA-S1-X'), $f('ALFA-S1'), $at);
        $docRow($s, 1, $P . 'A1', $f('A1'), $f('ALFA'), $at);
        $docRow($s, 1, $P . 'S1a', $f('S1a'), $f('ALFA-S1'), $at);
        $docRow($s, 1, $P . 'X1', $f('X1'), $f('ALFA-S1-X'), $at);
        $docRow($s, 2, $P . 'B1', $f('B1'), null, $at);            // not found: dokumen di luar scope opname
        $docRow($s, 3, $P . 'INV-001', null, null, $at);           // invalid: kode tak dikenal
        $docRow($s, 4, $P . 'A2', $f('A2'), $f('ALFA'), $at);      // belum discan
        $docRow($s, 4, $P . 'S2a', $f('S2a'), $f('ALFA-S2'), $at);

        // N02: sesi root oleh "superadmin"; mencakup SECRET yang tak terlihat user QA/user2 -> hasil parsial
        $at = '2026-10-05 09:15:00';
        $s = $session('N02', null, null, 0, $at, 'qa28root', [8, 3, 1, 1, 5, 2]);
        $folderRow($s, 1, $P . 'ALFA', $f('ALFA'), null, $at);
        $folderRow($s, 1, $P . 'BRAVO', $f('BRAVO'), null, $at);
        $folderRow($s, 1, $P . 'SECRET', $f('SECRET'), null, $at);
        $folderRow($s, 2, $P . 'BRAVO-T1', $f('BRAVO-T1'), $f('BRAVO'), $at);
        $docRow($s, 1, $P . 'B1', $f('B1'), $f('BRAVO'), $at);
        $docRow($s, 1, $P . 'T1a', $f('T1a'), $f('BRAVO-T1'), $at);
        $docRow($s, 1, $P . 'H1', $f('H1'), $f('SECRET'), $at);    // tersembunyi bagi user QA/user2
        $docRow($s, 2, $P . 'S2a', $f('S2a'), null, $at);          // not found
        $docRow($s, 3, $P . 'INV-002', null, null, $at);           // invalid
        $docRow($s, 4, $P . 'A1', $f('A1'), $f('ALFA'), $at);      // belum discan
        $docRow($s, 4, $P . 'H2', $f('H2'), $f('SECRET'), $at);    // tersembunyi bagi user QA/user2

        // N03..N13: satu folder tiap sesi
        $rows = [
            ['N03', 'BRAVO', '2026-10-04 14:30:00', 'qa28bob', [1, 1, 0, 0, 1, 0]],
            ['N04', 'BRAVO-T1', '2026-10-03 11:05:00', 'qa28carol', [1, 1, 0, 0, 1, 0]],
            ['N05', 'ALFA-S2', '2026-10-02 08:20:00', 'qa28alice', [1, 1, 0, 0, 1, 0]],
            ['N06', 'BRAVO', '2026-10-01 17:45:00', 'qa28dave', [2, 1, 0, 1, 2, 1]],
            ['N07', 'ALFA-S1', '2026-09-30 10:10:00', 'qa28erin', [2, 2, 0, 0, 2, 0]],
            ['N08', 'BRAVO', '2026-09-29 13:00:00', 'qa28bob', [2, 1, 1, 0, 2, 1]],
            ['N09', 'ALFA', '2026-09-28 09:00:00', 'qa28carol', [3, 2, 0, 0, 2, 1]],
            ['N10', 'BRAVO', '2026-09-27 15:30:00', 'qa28dave', [2, 2, 0, 0, 2, 0]],
            ['N11', 'ALFA-S1-X', '2026-09-26 12:00:00', 'qa28alice', [1, 1, 0, 0, 1, 0]],
            ['N12', 'BRAVO', '2026-09-25 08:00:00', 'qa28erin', [2, 1, 0, 0, 1, 1]],
            ['N13', 'ALFA', '2026-09-24 07:30:00', 'qa28bob', [3, 3, 0, 0, 3, 0]],
        ];
        foreach ($rows as [$key, $folder, $at, $by, $n]) {
            $s = $session($key, $f($folder), $P . $folder, 0, $at, $by, $n);
            $folderRow($s, 0, $P . $folder, $f($folder), null, $at);
        }

        // N14: folder VIEWONLY (user QA hanya View; user2 tidak punya hak, jadi sesi ini tak terlihat user2)
        $at = '2026-09-23 06:00:00';
        $s = $session('N14', $f('VIEWONLY'), $P . 'VIEWONLY', 0, $at, 'qa28view', [1, 1, 0, 0, 1, 0]);
        $folderRow($s, 0, $P . 'VIEWONLY', $f('VIEWONLY'), null, $at);

        // HID: terkonfirmasi tetapi hanya mencakup SECRET (tak terlihat user QA/user2); draft dan sesi batal tak pernah tampil
        $at = '2026-10-06 20:00:00';
        $s = $session('HID', $f('SECRET'), $P . 'SECRET', 0, $at, 'qa28hid', [2, 1, 0, 0, 1, 1]);
        $folderRow($s, 0, $P . 'SECRET', $f('SECRET'), null, $at);
        $at = '2026-10-07 07:00:00';
        $s = $session('DRAFT', $f('BRAVO'), $P . 'BRAVO', 0, $at, 'qa28draft', [1, 0, 0, 0, 0, 0], 1);
        $folderRow($s, 0, $P . 'BRAVO', $f('BRAVO'), null, $at);
        $at = '2026-10-03 07:00:00';
        $s = $session('CANC', $f('BRAVO'), $P . 'BRAVO', 0, $at, 'qa28cancel', [1, 0, 0, 0, 0, 0], 3);
        $folderRow($s, 0, $P . 'BRAVO', $f('BRAVO'), null, $at);
    });

    $ids['_sessions'] = $sessions;
    file_put_contents($idsFile, json_encode($ids));
    say('fixture sessions: ' . count($sessions) . ' sesi (14 terkonfirmasi, 13 terlihat user2 + N14 hanya user QA; 1 tersembunyi; draft; batal)');
    exit(0);
}

if ($cmd === 'down') {
    $n = purge($c);
    $lines = [];
    $ok = true;
    if (is_file($journal)) {
        $old = json_decode(file_get_contents($journal), true);
        displayRestore($c, $old['display']);
        if (displayRows($c) !== $old['display']) {
            $ok = false;
            $lines[] = 'BEDA baris display setting: tidak sama dengan snapshot awal';
        }
        $ok = compare($old['state'], state($c), $lines) && $ok;
        if ($ok) {
            @unlink($journal);
        }
    } else {
        $ok = false;
        $lines[] = 'tidak ada jurnal baseline (down tanpa up?)';
    }
    @unlink($idsFile);
    foreach ($lines as $l) {
        say($l);
    }
    say("fixture down: $n folder/dokumen berawalan " . PREFIX . ' dibuang; ' . ($ok ? 'keadaan 8 tabel (7 archive* + column_display_settings) = baseline (count + CHECKSUM), baris display setting identik' : 'KEADAAN TIDAK SAMA DENGAN BASELINE'));
    exit($ok ? 0 : 1);
}

if ($cmd === 'ids') {
    echo is_file($idsFile) ? file_get_contents($idsFile) : '{}';
    echo "\n";
    exit(0);
}

if ($cmd === 'display') {
    $out = [];
    foreach (displayRows($c) as $row) {
        $out[$row['module_name']] = array_map(function ($x) {
            return $x['data_index'];
        }, json_decode($row['current_columns'], true));
    }
    echo json_encode($out) . "\n";
    exit(0);
}

if ($cmd === 'inspect') {
    $docs = [];
    $q = $c->table('archives')->where('name', 'like', PREFIX . '%')->where('type', 2)->orderBy('name')->get();
    foreach ($q as $r) {
        $docs[$r->name] = ['is_verified' => (int) $r->is_verified, 'has_opname' => $r->id_archive_opname !== null];
    }
    $sessions = [];
    foreach ($c->table('archive_opnames')->orderBy('created_at')->orderBy('id_archive_opname')->get() as $s) {
        $sessions[] = [
            'id' => $s->id_archive_opname, 'folder' => $s->id_archive ? $c->table('archives')->where('id_archive', $s->id_archive)->value('name') : null,
            'status' => (int) $s->status, 'scope_name' => $s->scope_name, 'scope_folder_count' => (int) $s->scope_folder_count, 'total' => (int) $s->total_documents,
            'verified' => (int) $s->verified_count, 'not_found' => (int) $s->not_found_count, 'invalid' => (int) $s->invalid_count,
            'scanned' => (int) $s->scanned_count, 'unscanned' => (int) $s->unscanned_count,
            'confirmed_at' => $s->confirmed_at, 'created_by' => $s->created_by,
            'doc_rows' => $c->table('archive_opname_documents')->where('id_archive_opname', $s->id_archive_opname)->count(),
        ];
    }
    echo json_encode(['docs' => $docs, 'sessions' => $sessions]) . "\n";
    exit(0);
}

fail('pakai: up | sessions | down | ids | inspect | display');
