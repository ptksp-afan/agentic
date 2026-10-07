<?php
/**
 * Fixture e2e ED-1026 (BUKAN skenario regresi; dipanggil lewat qa/e2e/fixtures.json dan spec).
 *
 *   "$PHP_BIN" fixture.php up        buat folder/dokumen uji berawalan "0QA26-", sesi opname terkonfirmasi untuk BETA,
 *                                    atur user QA (lokasi Semarang saja; mode norole: cabut Opname Document dari rolenya)
 *   "$PHP_BIN" fixture.php down      buang SEMUA data uji + baris archive_opname*, pulihkan user/role, lalu verifikasi
 *                                    jumlah baris + CHECKSUM 7 tabel = baseline (exit 1 bila beda)
 *   "$PHP_BIN" fixture.php ids       cetak peta nama -> id_archive (JSON, tanpa rahasia)
 *   "$PHP_BIN" fixture.php inspect   cetak keadaan DB: is_verified per dokumen uji + sesi opname (JSON)
 *   "$PHP_BIN" fixture.php backdate  mundurkan created_at draft terbaru (status 1) satu hari (uji ARCHIVE415)
 *
 * Mode: env QA26_MODE = main (default) | norole. norole = baris role_permissions (role user QA, permission 1133) dicabut
 * sebelum login e2e dan dikembalikan persis (id baris sama) di down.
 *
 * Pohon uji (semua di root, aktif, is_all_location=1, tanpa folder permission; nama berawalan "0" agar urut paling awal):
 *   0QA26-ALPHA    AD1 AD2  | S1 (S1D1 S1D2, sub X: S1XD1) | S2 (S2D1) | S3 (S3D1)     folder dengan subfolder
 *   0QA26-BETA     BD1 (verified) | T1 (T1D1 verified, T1D2) | T2 (T2D1)   sudah diopname hari ini 09:15 (sesi terkonfirmasi)
 *   0QA26-GAMMA    GD1 GD2 GD3                                          tanpa subfolder
 *   0QA26-DELTA    DD1                                                  tanpa subfolder (uji bentrok)
 *   0QA26-EPS      E01..E12                                             tanpa subfolder, 12 dokumen (paginasi Step 3)
 *   0QA26-VIEWONLY VD1; folder permission: user QA hanya View (menu Info/Lihat/Opname; opname tetap boleh)
 *   0QA26-NOVIEW   folder permission aktif tanpa baris untuk user QA (tanpa View: tanpa menu aksi)
 */

namespace QaHttp;

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../../..')));

require QA_AGENTIC . '/scripts/qa-http/lib/Outcome.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Config.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Laravel.php';

use Illuminate\Support\Facades\DB;

const PREFIX = '0QA26-';
const ARCH_TABLES = ['archives', 'archive_documents', 'archive_locations', 'archive_permissions'];
const OP_TABLES = ['archive_opnames', 'archive_opname_folders', 'archive_opname_documents'];
const PERMISSION_ID = 1133;

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
    foreach (array_merge(ARCH_TABLES, OP_TABLES) as $t) {
        $row = (array) $c->selectOne("CHECKSUM TABLE `$t`");
        $out[$t] = ['count' => (int) $c->table($t)->count(), 'checksum' => (string) ($row['Checksum'] ?? '')];
    }

    return $out;
}

function rows($collection)
{
    $out = [];
    foreach ($collection as $r) {
        $out[] = (array) $r;
    }

    return $out;
}

function userSnapshot($c)
{
    $user = $c->table('users')->where('username', Config::get('QA_USER'))->first();
    if (!$user) {
        fail('QA_USER tidak ditemukan di DB');
    }
    $emp = $c->table('employees')->where('id_user', $user->id_user)->first();
    $roles = $c->table('user_roles')->where('id_user', $user->id_user)->pluck('id_role')->all();

    return [
        'uid'      => $user->id_user,
        'username' => $user->username,
        'emp_id'   => $emp->id_employee ?? null,
        'emp_all'  => $emp->is_all_location ?? null,
        'emp_locs' => $emp ? rows($c->table('employee_locations')->where('id_employee', $emp->id_employee)->orderBy('id_employee_location')->get()) : [],
        'role_ids' => $roles,
        'role_perm' => rows($c->table('role_permissions')->whereIn('id_role', $roles ?: [0])->where('id_permission', PERMISSION_ID)->orderBy('id_role_permission')->get()),
    ];
}

function userRestore($c, array $snap)
{
    if ($snap['emp_id']) {
        $c->table('employees')->where('id_employee', $snap['emp_id'])->update(['is_all_location' => $snap['emp_all']]);
        $c->table('employee_locations')->where('id_employee', $snap['emp_id'])->delete();
        if ($snap['emp_locs']) {
            $c->table('employee_locations')->insert($snap['emp_locs']);
        }
    }
    $c->table('role_permissions')->whereIn('id_role', $snap['role_ids'] ?: [0])->where('id_permission', PERMISSION_ID)->delete();
    if ($snap['role_perm']) {
        $c->table('role_permissions')->insert($snap['role_perm']);
    }
}

/** Buang data uji archive + semua baris opname; kolom verifikasi baris archive yang tersentuh sesi dikembalikan ke default. */
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
        userRestore($c, $old['user']);
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

    $before = state($c);
    $user = userSnapshot($c);
    file_put_contents($journal, json_encode(['state' => $before, 'user' => $user]));

    $mode = getenv('QA26_MODE') ?: 'main';
    $locs = $c->table('locations')->where('location_code', 'SMR')->pluck('id_location', 'location_code')->all();
    if (empty($locs['SMR'])) {
        fail('lokasi SMR tidak ada');
    }
    $now = \Carbon\Carbon::now('Asia/Jakarta');
    $nowStr = $now->format('Y-m-d H:i:s');
    $gen = function () {
        return \Modules\V5\Entities\Helper\MyHelper::generateId();
    };

    // user QA = user lokasi Semarang saja (bukan semua lokasi)
    if ($user['emp_id']) {
        $c->table('employees')->where('id_employee', $user['emp_id'])->update(['is_all_location' => 0]);
        $c->table('employee_locations')->where('id_employee', $user['emp_id'])->delete();
        $c->table('employee_locations')->insert([
            'id_employee' => $user['emp_id'], 'id_location' => $locs['SMR'], 'is_default' => 1,
            'created_at' => $nowStr, 'updated_at' => $nowStr,
        ]);
    }
    if ($mode === 'norole') {
        $c->table('role_permissions')->whereIn('id_role', $user['role_ids'] ?: [0])->where('id_permission', PERMISSION_ID)->delete();
    }

    $ids = [];
    $by = 'QA26E2E';
    $add = function ($name, $type, $parent, $txType = 6, $folderPerm = 0) use ($c, &$ids, $nowStr, $gen, $by) {
        $id = $gen();
        $row = [
            'id_archive' => $id, 'id_archive_parent' => $parent, 'name' => $name, 'type' => $type, 'status' => 1,
            'is_all_location' => 1, 'is_folder_permission' => $folderPerm, 'is_active' => 1,
            'created_at' => $nowStr, 'created_by' => $by, 'updated_at' => $nowStr, 'updated_by' => $by,
        ];
        $c->table('archives')->insert($row);
        if ($type == 2) {
            $c->table('archive_documents')->insert([
                'id_archive_document' => $gen(), 'id_archive' => $id, 'id_transaction' => 'QA26TX' . substr($id, -10),
                'transaction_no' => $name, 'transaction_type' => $txType, 'related_employee_name' => 'Sales QA26',
                'created_at' => $nowStr, 'updated_at' => $nowStr,
            ]);
        }
        $ids[$name] = $id;

        return $id;
    };

    $c->transaction(function () use ($add, $c, $user, $gen, $nowStr, $by) {
        $alpha = $add(PREFIX . 'ALPHA', 1, null);
        $add(PREFIX . 'AD1', 2, $alpha, 6);
        $add(PREFIX . 'AD2', 2, $alpha, 7);
        $s1 = $add(PREFIX . 'ALPHA-S1', 1, $alpha);
        $add(PREFIX . 'S1D1', 2, $s1, 6);
        $add(PREFIX . 'S1D2', 2, $s1, 8);
        $x = $add(PREFIX . 'ALPHA-S1-X', 1, $s1);
        $add(PREFIX . 'S1XD1', 2, $x, 7);
        $s2 = $add(PREFIX . 'ALPHA-S2', 1, $alpha);
        $add(PREFIX . 'S2D1', 2, $s2, 6);
        $s3 = $add(PREFIX . 'ALPHA-S3', 1, $alpha);
        $add(PREFIX . 'S3D1', 2, $s3, 6);

        $beta = $add(PREFIX . 'BETA', 1, null);
        $add(PREFIX . 'BD1', 2, $beta, 6);
        $t1 = $add(PREFIX . 'BETA-T1', 1, $beta);
        $add(PREFIX . 'T1D1', 2, $t1, 7);
        $add(PREFIX . 'T1D2', 2, $t1, 6);
        $t2 = $add(PREFIX . 'BETA-T2', 1, $beta);
        $add(PREFIX . 'T2D1', 2, $t2, 6);

        $gamma = $add(PREFIX . 'GAMMA', 1, null);
        $add(PREFIX . 'GD1', 2, $gamma, 6);
        $add(PREFIX . 'GD2', 2, $gamma, 7);
        $add(PREFIX . 'GD3', 2, $gamma, 8);

        $delta = $add(PREFIX . 'DELTA', 1, null);
        $add(PREFIX . 'DD1', 2, $delta, 6);

        $eps = $add(PREFIX . 'EPS', 1, null);
        for ($i = 1; $i <= 12; $i++) {
            $add(PREFIX . sprintf('E%02d', $i), 2, $eps, 6);
        }

        // hak folder (ED-1025): VIEWONLY = user QA hanya View; NOVIEW = folder permission aktif tanpa baris untuk user QA
        $vo = $add(PREFIX . 'VIEWONLY', 1, null, 6, 1);
        $add(PREFIX . 'VD1', 2, $vo, 6);
        $c->table('archive_permissions')->insert([
            'id_archive_permission' => $gen(), 'id_archive' => $vo, 'id_user' => $user['uid'],
            'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0,
            'created_at' => $nowStr, 'created_by' => $by, 'updated_at' => $nowStr, 'updated_by' => $by,
        ]);
        $add(PREFIX . 'NOVIEW', 1, null, 6, 1);
    });

    // BETA + BETA-T1 sudah diopname hari ini 09:15 (sesi terkonfirmasi, dibuat langsung di DB); BD1 dan T1D1 verified
    $sid = $gen();
    $todayAt = $now->copy()->setTime(9, 15, 0)->format('Y-m-d H:i:s');
    $c->transaction(function () use ($c, $ids, $sid, $todayAt, $nowStr, $gen, $by) {
        $c->table('archive_opnames')->insert([
            'id_archive_opname' => $sid, 'id_archive' => $ids[PREFIX . 'BETA'], 'status' => 2, 'scope_name' => PREFIX . 'BETA-T1',
            'scope_folder_count' => 1, 'total_documents' => 3, 'verified_before_count' => 0, 'scanned_count' => 2,
            'verified_count' => 2, 'not_found_count' => 0, 'invalid_count' => 0, 'unscanned_count' => 1, 'unverified_count' => 0,
            'selected_at' => $todayAt, 'confirmed_at' => $todayAt, 'created_at' => $todayAt, 'created_by' => $by,
            'updated_at' => $todayAt, 'updated_by' => $by,
        ]);
        $folder = function ($level, $name, $idArchive, $parent, $total, $verified) use ($c, $sid, $todayAt, $gen) {
            $c->table('archive_opname_folders')->insert([
                'id_archive_opname_folder' => $gen(), 'id_archive_opname' => $sid, 'id_archive' => $idArchive,
                'id_archive_parent' => $parent, 'name' => $name, 'level' => $level, 'is_continue' => 0, 'is_opnamed_today' => 0,
                'total_documents' => $total, 'verified_count' => $verified, 'verified_after_count' => $verified,
                'unverified_count' => 0, 'created_at' => $todayAt,
            ]);
        };
        $folder(0, PREFIX . 'BETA', $ids[PREFIX . 'BETA'], null, 1, 1);
        $folder(1, PREFIX . 'BETA-T1', $ids[PREFIX . 'BETA-T1'], $ids[PREFIX . 'BETA'], 2, 1);
        foreach ([PREFIX . 'BD1', PREFIX . 'T1D1'] as $doc) {
            $c->table('archives')->where('id_archive', $ids[$doc])->update([
                'is_verified' => 1, 'verified_at' => $todayAt, 'verified_by' => $by, 'id_archive_opname' => $sid,
            ]);
        }
    });

    $ids['_user'] = ['id_user' => $user['uid'], 'username' => $user['username']];
    $ids['_mode'] = $mode;
    $ids['_today'] = $now->format('Y-m-d');
    file_put_contents($idsFile, json_encode($ids));
    say('fixture up (' . $mode . '): ' . (count($ids) - 3) . ' entri archive; baseline archives=' . $before['archives']['count']
        . ' opname=' . $before['archive_opnames']['count'] . '; user QA lokasi Semarang saja');
    exit(0);
}

if ($cmd === 'down') {
    $n = purge($c);
    $lines = [];
    $ok = true;
    if (is_file($journal)) {
        $old = json_decode(file_get_contents($journal), true);
        userRestore($c, $old['user']);
        if (userSnapshot($c) !== $old['user']) {
            $ok = false;
            $lines[] = 'BEDA user/role QA: tidak sama dengan snapshot awal';
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
    say("fixture down: $n folder/dokumen berawalan " . PREFIX . ' dibuang; ' . ($ok ? 'keadaan 7 tabel + user/role = baseline (count + CHECKSUM)' : 'KEADAAN TIDAK SAMA DENGAN BASELINE'));
    exit($ok ? 0 : 1);
}

if ($cmd === 'ids') {
    echo is_file($idsFile) ? file_get_contents($idsFile) : '{}';
    echo "\n";
    exit(0);
}

if ($cmd === 'inspect') {
    $docs = [];
    $q = $c->table('archives')->where('name', 'like', PREFIX . '%')->where('type', 2)->orderBy('name')->get();
    foreach ($q as $r) {
        $docs[$r->name] = [
            'is_verified' => (int) $r->is_verified, 'verified' => $r->verified_at !== null,
            'verified_by' => $r->verified_by, 'has_opname' => $r->id_archive_opname !== null,
            'folder' => $c->table('archives')->where('id_archive', $r->id_archive_parent)->value('name'),
            'is_active' => (int) $r->is_active,
        ];
    }
    $sessions = [];
    foreach ($c->table('archive_opnames')->orderBy('created_at')->orderBy('id_archive_opname')->get() as $s) {
        $sessions[] = [
            'folder' => $s->id_archive ? $c->table('archives')->where('id_archive', $s->id_archive)->value('name') : null,
            'status' => (int) $s->status, 'scope_name' => $s->scope_name, 'total' => (int) $s->total_documents,
            'scanned' => (int) $s->scanned_count, 'verified' => (int) $s->verified_count, 'not_found' => (int) $s->not_found_count,
            'invalid' => (int) $s->invalid_count, 'unscanned' => (int) $s->unscanned_count, 'unverified' => (int) $s->unverified_count,
            'confirmed' => $s->confirmed_at !== null, 'folders' => $c->table('archive_opname_folders')->where('id_archive_opname', $s->id_archive_opname)->count(),
            'docs' => $c->table('archive_opname_documents')->where('id_archive_opname', $s->id_archive_opname)->count(),
        ];
    }
    echo json_encode(['docs' => $docs, 'sessions' => $sessions]) . "\n";
    exit(0);
}

if ($cmd === 'backdate') {
    $id = $c->table('archive_opnames')->where('status', 1)->orderBy('created_at', 'desc')->orderBy('id_archive_opname', 'desc')->value('id_archive_opname');
    if (!$id) {
        fail('tidak ada draft untuk dimundurkan');
    }
    $c->update('UPDATE archive_opnames SET created_at = created_at - INTERVAL 1 DAY WHERE id_archive_opname = ?', [$id]);
    say('draft dimundurkan 1 hari');
    exit(0);
}

fail('pakai: up | down | ids | inspect | backdate');
