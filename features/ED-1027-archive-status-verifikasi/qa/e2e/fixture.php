<?php
/**
 * Fixture e2e ED-1027 (BUKAN skenario regresi; dipanggil lewat qa/e2e/fixtures.json dan spec).
 *
 *   "$PHP_BIN" fixture.php up        buat folder/dokumen uji berawalan "0QA27-" (angka verifikasi diketahui tangan), 4 sesi opname
 *                                    terkonfirmasi + 1 draft (dibuat langsung di DB), simpan snapshot baris display setting Archive
 *   "$PHP_BIN" fixture.php down      buang SEMUA data uji + baris archive_opname*, pulihkan baris display setting persis, lalu
 *                                    verifikasi jumlah baris + CHECKSUM 7 tabel archive* + column_display_settings = baseline
 *   "$PHP_BIN" fixture.php ids       cetak peta nama -> id_archive + angka harapan (JSON, tanpa rahasia)
 *   "$PHP_BIN" fixture.php inspect   cetak keadaan DB: is_verified per dokumen uji + sesi opname (JSON, baca saja)
 *   "$PHP_BIN" fixture.php display   cetak baris display setting documentArchive (kolom aktif; JSON, baca saja)
 *
 * Pohon uji (semua aktif, is_all_location=1; user QA = role 3 semua lokasi, bukan bypass superadmin 1/2):
 *   0QA27-ALPHA   AD1(6,V) AD2(7) AD-HO(8, Handed Over) AD-TK(9, V, Taken) AD-NEG(6,V,is_active=-1) AD-DEL(6,V,is_active=0)
 *                 | S1 (S1D1 6 V, S1D2 8) | S1-X (S1XD1 7 V, di bawah S1) | S2 (S2D1 6)
 *                 => ALPHA 4/8, S1 2/3, S1-X 1/1, S2 0/1; tipe: 6 V2/U1, 7 V1/U1, 8 V0/U2, 9 V1/U0
 *   0QA27-BETA    BD1(6,V) | T1 (T1D1 7 V, T1D2 6)                      => BETA 2/3, T1 1/2
 *   0QA27-GAMMA   GD1(6) GD2(7) GD3(8)                                   => 0/3 (alur Confirm Opname lewat UI)
 *   0QA27-EMPTY   kosong                                                 => 0/0
 *   0QA27-VIEWONLY VD1(6); folder permission: user QA hanya View          => 0/1
 *   0QA27-NOVIEW  ND1(6,V); folder permission aktif tanpa baris user QA   => "-" (tak ikut angka)
 *   dokumen root  0QA27-RD-V (6, V), 0QA27-RD-U (7)                      => 1/2 di root
 *   Kontribusi fixture ke ringkasan All Archive (user QA): verified 7, total 17.
 * Sesi terkonfirmasi (terbaru dulu): S4 ALPHA (scope "ALPHA-S1 + 1 subfolder"), S3 root, S2 GAMMA, S1 BETA (tidak tampil: batas 3);
 * satu draft (status 1) tidak pernah tampil.
 */

namespace QaHttp;

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../../..')));

require QA_AGENTIC . '/scripts/qa-http/lib/Outcome.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Config.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Laravel.php';

use Illuminate\Support\Facades\DB;

const PREFIX = '0QA27-';
const TABLES = ['archives', 'archive_documents', 'archive_locations', 'archive_permissions', 'archive_opnames', 'archive_opname_folders', 'archive_opname_documents', 'column_display_settings'];
const OP_TABLES = ['archive_opnames', 'archive_opname_folders', 'archive_opname_documents'];
const DISPLAY_MODULE = 'documentArchive';

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
    foreach ($c->table('column_display_settings')->where('module_name', DISPLAY_MODULE)->orderBy('id_column_display_setting')->get() as $r) {
        $out[] = (array) $r;
    }

    return $out;
}

function displayRestore($c, array $snap)
{
    $c->table('column_display_settings')->where('module_name', DISPLAY_MODULE)->delete();
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
    $nondefault = $c->table('archives')->where(function ($q) {
        $q->where('is_verified', '!=', 0)->orWhereNotNull('verified_at')->orWhereNotNull('verified_by')->orWhereNotNull('id_archive_opname');
    })->count();
    if ($nondefault > 0) {
        fail("archives punya $nondefault baris kolom verifikasi tak-default sebelum fixture");
    }
    if (count(displayRows($c)) !== 1) {
        fail('baris display setting documentArchive bukan tepat 1 (Updater ED-1027 belum jalan?)');
    }

    $before = state($c);
    $baselineTotal = (int) $c->table('archives')->where('type', 2)->where('is_active', '>', 0)->count();
    file_put_contents($journal, json_encode(['state' => $before, 'display' => displayRows($c)]));

    $user = $c->table('users')->where('username', Config::get('QA_USER'))->first();
    if (!$user) {
        fail('QA_USER tidak ditemukan di DB');
    }

    $now = \Carbon\Carbon::now('Asia/Jakarta');
    $nowStr = $now->format('Y-m-d H:i:s');
    $gen = function () {
        return \Modules\V5\Entities\Helper\MyHelper::generateId();
    };

    $ids = [];
    $by = 'QA27E2E';
    $add = function ($name, $type, $parent, $txType = 6, $o = []) use ($c, &$ids, $nowStr, $gen, $by) {
        $id = $gen();
        $active = $o['active'] ?? 1;
        $verified = !empty($o['v']) ? 1 : 0;
        $status = $o['status'] ?? 1;
        $history = null;
        if ($status === 2) {
            $history = json_encode([['action' => 'give', 'related_username' => 'qa27', 'timestamp' => '05 Okt 2026 09:00:00']]);
        } elseif ($status === 3) {
            $history = json_encode([['action' => 'take', 'related_username' => 'qa27', 'timestamp' => '05 Okt 2026 09:00:00']]);
        }
        $c->table('archives')->insert([
            'id_archive' => $id, 'id_archive_parent' => $parent, 'name' => $name, 'type' => $type, 'status' => $status,
            'history' => $history, 'is_all_location' => 1, 'is_folder_permission' => $o['perm'] ?? 0, 'is_active' => $active,
            'is_verified' => $verified, 'verified_at' => $verified ? $nowStr : null, 'verified_by' => $verified ? $by : null,
            'created_at' => $nowStr, 'created_by' => $by, 'updated_at' => $nowStr, 'updated_by' => $by,
        ]);
        if ($type == 2) {
            $c->table('archive_documents')->insert([
                'id_archive_document' => $gen(), 'id_archive' => $id, 'id_transaction' => 'QA27TX' . substr($id, -10),
                'transaction_no' => $name, 'transaction_type' => $txType, 'related_employee_name' => 'Sales QA27',
                'created_at' => $nowStr, 'updated_at' => $nowStr,
            ]);
        }
        $ids[$name] = $id;

        return $id;
    };

    $c->transaction(function () use ($add, $c, $user, $gen, $nowStr, $by) {
        $alpha = $add(PREFIX . 'ALPHA', 1, null);
        $add(PREFIX . 'AD1', 2, $alpha, 6, ['v' => 1]);
        $add(PREFIX . 'AD2', 2, $alpha, 7);
        $add(PREFIX . 'AD-HO', 2, $alpha, 8, ['status' => 2]);
        $add(PREFIX . 'AD-TK', 2, $alpha, 9, ['status' => 3, 'v' => 1]);
        $add(PREFIX . 'AD-NEG', 2, $alpha, 6, ['active' => -1, 'v' => 1]);
        $add(PREFIX . 'AD-DEL', 2, $alpha, 6, ['active' => 0, 'v' => 1]);
        $s1 = $add(PREFIX . 'ALPHA-S1', 1, $alpha);
        $add(PREFIX . 'S1D1', 2, $s1, 6, ['v' => 1]);
        $add(PREFIX . 'S1D2', 2, $s1, 8);
        $x = $add(PREFIX . 'ALPHA-S1-X', 1, $s1);
        $add(PREFIX . 'S1XD1', 2, $x, 7, ['v' => 1]);
        $s2 = $add(PREFIX . 'ALPHA-S2', 1, $alpha);
        $add(PREFIX . 'S2D1', 2, $s2, 6);

        $beta = $add(PREFIX . 'BETA', 1, null);
        $add(PREFIX . 'BD1', 2, $beta, 6, ['v' => 1]);
        $t1 = $add(PREFIX . 'BETA-T1', 1, $beta);
        $add(PREFIX . 'T1D1', 2, $t1, 7, ['v' => 1]);
        $add(PREFIX . 'T1D2', 2, $t1, 6);

        $gamma = $add(PREFIX . 'GAMMA', 1, null);
        $add(PREFIX . 'GD1', 2, $gamma, 6);
        $add(PREFIX . 'GD2', 2, $gamma, 7);
        $add(PREFIX . 'GD3', 2, $gamma, 8);

        $add(PREFIX . 'EMPTY', 1, null);

        $vo = $add(PREFIX . 'VIEWONLY', 1, null, 6, ['perm' => 1]);
        $add(PREFIX . 'VD1', 2, $vo, 6);
        $c->table('archive_permissions')->insert([
            'id_archive_permission' => $gen(), 'id_archive' => $vo, 'id_user' => $user->id_user,
            'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0,
            'created_at' => $nowStr, 'created_by' => $by, 'updated_at' => $nowStr, 'updated_by' => $by,
        ]);
        $nv = $add(PREFIX . 'NOVIEW', 1, null, 6, ['perm' => 1]);
        $add(PREFIX . 'ND1', 2, $nv, 6, ['v' => 1]);

        $add(PREFIX . 'RD-V', 2, null, 6, ['v' => 1]);
        $add(PREFIX . 'RD-U', 2, null, 7);
    });

    // sesi opname terkonfirmasi (snapshot angka; dibuat langsung di DB)
    $sessions = [];
    $session = function ($key, $idArchive, $scopeName, $scopeCount, $confirmedAt, $createdBy, $nums, $status = 2) use ($c, $gen, &$sessions) {
        $sid = $gen();
        $c->table('archive_opnames')->insert([
            'id_archive_opname' => $sid, 'id_archive' => $idArchive, 'status' => $status, 'scope_name' => $scopeName,
            'scope_folder_count' => $scopeCount, 'total_documents' => $nums[0], 'verified_before_count' => 0,
            'scanned_count' => $nums[1] + $nums[2] + $nums[3], 'verified_count' => $nums[1], 'not_found_count' => $nums[2],
            'invalid_count' => $nums[3], 'unscanned_count' => 0, 'unverified_count' => 0,
            'selected_at' => $confirmedAt, 'confirmed_at' => $status === 2 ? $confirmedAt : null, 'created_at' => $confirmedAt,
            'created_by' => $createdBy, 'updated_at' => $confirmedAt, 'updated_by' => $createdBy,
        ]);
        $sessions[$key] = $sid;

        return $sid;
    };
    $folderRow = function ($sid, $level, $name, $idArchive, $parent, $total, $verified, $at) use ($c, $gen) {
        $c->table('archive_opname_folders')->insert([
            'id_archive_opname_folder' => $gen(), 'id_archive_opname' => $sid, 'id_archive' => $idArchive,
            'id_archive_parent' => $parent, 'name' => $name, 'level' => $level, 'is_continue' => 0, 'is_opnamed_today' => 0,
            'total_documents' => $total, 'verified_count' => $verified, 'verified_after_count' => $verified,
            'unverified_count' => 0, 'created_at' => $at,
        ]);
    };

    $c->transaction(function () use ($c, $ids, $session, $folderRow, &$sessions) {
        $P = PREFIX;
        // S1 (terlama, di luar 3 terbaru): BETA
        $at = '2026-10-01 08:00:00';
        $s = $session('S1', $ids[$P . 'BETA'], $P . 'BETA', 0, $at, 'qa27oldest', [3, 2, 0, 0]);
        $folderRow($s, 0, $P . 'BETA', $ids[$P . 'BETA'], null, 1, 1, $at);
        $folderRow($s, 1, $P . 'BETA-T1', $ids[$P . 'BETA-T1'], $ids[$P . 'BETA'], 2, 1, $at);
        // S2: GAMMA
        $at = '2026-10-04 14:30:00';
        $s = $session('S2', $ids[$P . 'GAMMA'], $P . 'GAMMA', 0, $at, 'qa27gamma', [3, 1, 0, 0]);
        $folderRow($s, 0, $P . 'GAMMA', $ids[$P . 'GAMMA'], null, 3, 1, $at);
        // S3: root (All Archive, tanpa subfolder dicentang); mencakup BETA (level 1) + T1 (level 2)
        $at = '2026-10-05 09:15:00';
        $s = $session('S3', null, null, 0, $at, 'qa27root', [20, 9, 2, 1]);
        $folderRow($s, 1, $P . 'BETA', $ids[$P . 'BETA'], null, 1, 1, $at);
        $folderRow($s, 2, $P . 'BETA-T1', $ids[$P . 'BETA-T1'], $ids[$P . 'BETA'], 2, 1, $at);
        // S4 (terbaru): ALPHA + 2 subfolder (S1, S2; S1-X ikut otomatis, level 2)
        $at = '2026-10-06 16:40:00';
        $s = $session('S4', $ids[$P . 'ALPHA'], $P . 'ALPHA-S1', 2, $at, 'qa27alpha', [8, 5, 1, 2]);
        $folderRow($s, 0, $P . 'ALPHA', $ids[$P . 'ALPHA'], null, 4, 2, $at);
        $folderRow($s, 1, $P . 'ALPHA-S1', $ids[$P . 'ALPHA-S1'], $ids[$P . 'ALPHA'], 2, 1, $at);
        $folderRow($s, 1, $P . 'ALPHA-S2', $ids[$P . 'ALPHA-S2'], $ids[$P . 'ALPHA'], 1, 0, $at);
        $folderRow($s, 2, $P . 'ALPHA-S1-X', $ids[$P . 'ALPHA-S1-X'], $ids[$P . 'ALPHA-S1'], 1, 1, $at);
        // draft (tidak pernah tampil)
        $session('DRAFT', $ids[$P . 'GAMMA'], $P . 'GAMMA', 0, '2026-10-07 07:00:00', 'qa27draft', [3, 0, 0, 0], 1);
    });

    $ids['_sessions'] = $sessions;
    $ids['_baseline_total'] = $baselineTotal;
    $ids['_expect'] = ['summary_verified' => 7, 'summary_total' => $baselineTotal + 17];
    $ids['_user'] = ['id_user' => $user->id_user, 'username' => $user->username];
    file_put_contents($idsFile, json_encode($ids));
    say('fixture up: ' . (count($ids) - 4) . ' entri archive, 5 sesi (4 terkonfirmasi + 1 draft); baseline dokumen aktif=' . $baselineTotal
        . '; archives=' . $before['archives']['count'] . ' opname=' . $before['archive_opnames']['count']);
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
            $lines[] = 'BEDA baris display setting documentArchive: tidak sama dengan snapshot awal';
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
    $row = displayRows($c)[0] ?? null;
    $cols = $row ? array_map(function ($x) {
        return $x['data_index'];
    }, json_decode($row['current_columns'], true)) : [];
    echo json_encode(['current' => $cols, 'updated_by' => $row['updated_by'] ?? null]) . "\n";
    exit(0);
}

if ($cmd === 'inspect') {
    $docs = [];
    $q = $c->table('archives')->where('name', 'like', PREFIX . '%')->where('type', 2)->orderBy('name')->get();
    foreach ($q as $r) {
        $docs[$r->name] = [
            'is_verified' => (int) $r->is_verified, 'has_opname' => $r->id_archive_opname !== null,
            'folder' => $c->table('archives')->where('id_archive', $r->id_archive_parent)->value('name'),
            'is_active' => (int) $r->is_active,
        ];
    }
    $sessions = [];
    foreach ($c->table('archive_opnames')->orderBy('created_at')->orderBy('id_archive_opname')->get() as $s) {
        $sessions[] = [
            'folder' => $s->id_archive ? $c->table('archives')->where('id_archive', $s->id_archive)->value('name') : null,
            'status' => (int) $s->status, 'scope_name' => $s->scope_name, 'total' => (int) $s->total_documents,
            'verified' => (int) $s->verified_count, 'not_found' => (int) $s->not_found_count, 'invalid' => (int) $s->invalid_count,
            'confirmed' => $s->confirmed_at !== null, 'created_by' => $s->created_by,
        ];
    }
    echo json_encode(['docs' => $docs, 'sessions' => $sessions]) . "\n";
    exit(0);
}

fail('pakai: up | down | ids | inspect | display');
