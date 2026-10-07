<?php
/**
 * Fixture e2e ED-1029 (BUKAN skenario regresi; dipanggil lewat qa/e2e/fixtures.json dan dari spec).
 *
 *   "$PHP_BIN" fixture.php up        pulihkan sisa run mati (jurnal), catat baseline, buat state user + folder uji "0QA29-"
 *   "$PHP_BIN" fixture.php down      buang SEMUA baris berawalan "0QA29-", pulihkan user A/E persis, verifikasi baseline
 *   "$PHP_BIN" fixture.php ids       cetak peta nama -> id (JSON, tanpa rahasia)
 *   "$PHP_BIN" fixture.php state     cetak (JSON, baris terakhir) hak tersimpan B & B2 per folder uji, jumlah riwayat
 *                                    `permission` + pelaku terakhir per folder uji, flag is_folder_permission, jumlah baris
 *                                    archive_permissions total
 *   "$PHP_BIN" fixture.php flag <nama> <0|1>   set is_folder_permission satu folder uji (nama tanpa awalan; AC-19)
 *   "$PHP_BIN" fixture.php role <id|restore>   role A sementara (mis. 1 = superadmin); restore = role asli dari jurnal
 *   "$PHP_BIN" fixture.php resetperm           hapus semua baris archive_permissions folder uji milik B dan B2, lalu pasang
 *                                              lagi nilai awal (supaya tiap test mulai dari keadaan yang sama), riwayat dikosongkan
 *
 * Pelaku: A = QA_USER (profil default; role 3 = Super Admin non-bypass, employee DIUBAH sementara jadi lokasi MGL saja supaya folder
 * L (JOG) di luar scope-nya), E = QA_USER2 (profil user2; role 5 DIGANTI sementara jadi role 18 "Pusat - Admin Sales": List Archive +
 * Handover/Receive/Add Document, TANPA Update Folder, supaya "+" masih tampil tetapi tanpa Set Permission by User). B dan B2 = dua
 * user target (aktif, ber-List Archive, bukan superadmin 1/2, bukan customer, bukan A/E) yang dipilih urut username.
 *
 * Folder uji (semua is_all_location=1 kecuali L; dibuat oleh 'QA29E2E', bukan A, jadi hak pembuat tidak berlaku):
 *   0QA29-L        On   root, hanya lokasi JOG -> di luar scope A
 *   0QA29-P        On   root; A V+U+D+S;  B View
 *     0QA29-P-C1   On   A V+U+D+S;  B kosong
 *     0QA29-P-C2   Off  A kosong (diwarisi P);  B View+Store (nilai tersimpan, terkunci karena Off)
 *   0QA29-Q        On   root; A View saja -> manage_permission false;  B View+Update
 *   0QA29-R        Off  root; A kosong
 *     0QA29-R-R1   On   A V+U+D+S;  B kosong
 * Nilai awal B (resetperm): P=1000, C2=1001, Q=1100; B2: tanpa baris.
 *
 * Yang ditulis: archives, archive_documents(0), archive_locations, archive_permissions (hanya baris folder berawalan 0QA29-),
 * user_roles (E), employees.is_all_location (A). Semuanya dipulihkan persis di `down` (jurnal qa/e2e/.fixture-journal.json).
 */

namespace QaHttp;

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../../..')));

require QA_AGENTIC . '/scripts/qa-http/lib/Outcome.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Config.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Laravel.php';

use Illuminate\Support\Facades\DB;

const PREFIX = '0QA29-';
const TABLES = ['archives', 'archive_documents', 'archive_locations', 'archive_permissions'];
const E_ROLE = 18;

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
    $out = [];
    foreach ($collection as $row) {
        $out[] = (array) $row;
    }

    return $out;
}

function tableState($c)
{
    $out = [];
    foreach (TABLES as $t) {
        $row = (array) $c->selectOne("CHECKSUM TABLE `$t`");
        $out[$t] = ['count' => (int) $c->table($t)->count(), 'checksum' => (string) ($row['Checksum'] ?? '')];
    }

    return $out;
}

/** Keadaan user A dan E yang diubah fixture (baris persis, untuk pemulihan dan verifikasi). */
function userState($c, $idA, $idE)
{
    $empA = $c->table('employees')->where('id_user', $idA)->first();
    $empE = $c->table('employees')->where('id_user', $idE)->first();

    return [
        'idA' => $idA,
        'idE' => $idE,
        'rolesA' => rows($c->table('user_roles')->where('id_user', $idA)->orderBy('id_user_role')->get()),
        'rolesE' => rows($c->table('user_roles')->where('id_user', $idE)->orderBy('id_user_role')->get()),
        'empA' => $empA ? (array) $empA : null,
        'empLocsA' => $empA ? rows($c->table('employee_locations')->where('id_employee', $empA->id_employee)->orderBy('id_employee_location')->get()) : [],
        'empE' => $empE ? (array) $empE : null,
        'empLocsE' => $empE ? rows($c->table('employee_locations')->where('id_employee', $empE->id_employee)->orderBy('id_employee_location')->get()) : [],
    ];
}

function userRestore($c, array $s)
{
    $c->table('user_roles')->whereIn('id_user', [$s['idA'], $s['idE']])->delete();
    foreach (['rolesA', 'rolesE'] as $k) {
        if ($s[$k]) {
            $c->table('user_roles')->insert($s[$k]);
        }
    }
    foreach ([['empA', 'empLocsA'], ['empE', 'empLocsE']] as [$ek, $lk]) {
        if ($s[$ek]) {
            $id = $s[$ek]['id_employee'];
            $c->table('employees')->where('id_employee', $id)->update($s[$ek]);
            $c->table('employee_locations')->where('id_employee', $id)->delete();
            if ($s[$lk]) {
                $c->table('employee_locations')->insert($s[$lk]);
            }
        }
    }
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

    return count($ids);
}

function jsonLast($v)
{
    echo json_encode($v) . "\n";
}

function ids()
{
    global $idsFile;

    return is_file($idsFile) ? json_decode(file_get_contents($idsFile), true) : [];
}

function insertPerm($c, $idArchive, $idUser, array $r)
{
    $now = date('Y-m-d H:i:s');
    $c->table('archive_permissions')->insert([
        'id_archive_permission' => \Modules\V5\Entities\Helper\MyHelper::generateId(),
        'id_archive' => $idArchive, 'id_user' => $idUser,
        'is_view' => $r[0], 'is_update' => $r[1], 'is_delete' => $r[2], 'is_store' => $r[3],
        'created_at' => $now, 'created_by' => 'QA29E2E', 'updated_at' => $now, 'updated_by' => 'QA29E2E',
    ]);
}

/** Nilai awal B (nama tanpa awalan => [V,U,D,S]); B2 tanpa baris. */
function initialB()
{
    return ['P' => [1, 0, 0, 0], 'P-C2' => [1, 0, 0, 1], 'Q' => [1, 1, 0, 0]];
}

if ($cmd === 'up') {
    if (is_file($journal)) { // run sebelumnya mati: pulihkan dulu
        $old = json_decode(file_get_contents($journal), true);
        purge($c);
        if (!empty($old['users'])) {
            userRestore($c, $old['users']);
        }
        $now = tableState($c);
        if (!empty($old['tables']) && $old['tables'] !== $now) {
            fail('sisa jurnal run lama: keadaan setelah pemulihan tidak sama dengan baseline lama');
        }
        @unlink($journal);
    }
    if ($c->table('archives')->where('name', 'like', PREFIX . '%')->count() > 0) {
        purge($c);
    }

    $userA = $c->table('users')->where('username', Config::get('QA_USER'))->first();
    $userE = Config::get('QA_USER2') ? $c->table('users')->where('username', Config::get('QA_USER2'))->first() : null;
    if (!$userA || !$userE) {
        fail('QA_USER / QA_USER2 tidak ditemukan di DB');
    }

    // B, B2: pola query = select/document-archive/archive/users (SelectArchiveService::user)
    $customerUsers = $c->table('customers')->whereNotNull('id_user')->pluck('id_user')->all();
    $withListArchive = $c->table('role_permissions')->where('permission_name', 'List Archive')->pluck('id_role')->all();
    $activeRoles = $c->table('roles')->where('is_active', 1)->pluck('is_superadmin', 'id_role')->all();
    $okRoles = array_values(array_filter($withListArchive, function ($r) use ($activeRoles) {
        return isset($activeRoles[$r]);
    }));
    $cand = [];
    foreach ($c->table('users')->where('is_active', 1)->whereNotIn('id_user', array_merge($customerUsers, [$userA->id_user, $userE->id_user]))->orderBy('username')->get(['id_user', 'username']) as $u) {
        $rs = $c->table('user_roles')->where('id_user', $u->id_user)->pluck('id_role')->all();
        $active = array_values(array_filter($rs, function ($r) use ($activeRoles) {
            return isset($activeRoles[$r]);
        }));
        $hasList = count(array_intersect($active, $okRoles)) > 0;
        $isSuper = count(array_filter($active, function ($r) use ($activeRoles) {
            return in_array((int) $activeRoles[$r], [1, 2], true);
        })) > 0;
        // username tanpa titik/sama awalan dengan user lain supaya pencarian di dropdown tepat satu opsi
        if ($hasList && !$isSuper && preg_match('/^[a-z0-9]+$/', $u->username)) {
            $cand[] = $u;
        }
    }
    // dua user yang namanya tidak jadi awalan nama user lain mana pun (pencarian "mengandung" memberi tepat satu opsi)
    $all = $c->table('users')->where('is_active', 1)->pluck('username')->all();
    $unique = array_values(array_filter($cand, function ($u) use ($all) {
        $n = 0;
        foreach ($all as $name) {
            if (stripos($name, $u->username) !== false) {
                $n++;
            }
        }

        return $n === 1;
    }));
    if (count($unique) < 2) {
        fail('kurang dari 2 user target yang cocok');
    }
    $userB = $unique[0];
    $userB2 = $unique[1];

    $locs = $c->table('locations')->pluck('id_location', 'location_code')->all();
    $empA = $c->table('employees')->where('id_user', $userA->id_user)->first();
    if (!$empA || !isset($locs['JOG'])) {
        fail('employee A atau lokasi JOG tidak ada');
    }
    $locsA = $c->table('employee_locations')->where('id_employee', $empA->id_employee)->pluck('id_location')->all();
    if (!$locsA || in_array($locs['JOG'], $locsA, true)) {
        fail('lokasi employee A kosong atau memuat JOG: folder L tidak akan di luar scope');
    }

    $before = ['tables' => tableState($c), 'users' => userState($c, $userA->id_user, $userE->id_user)];
    file_put_contents($journal, json_encode($before));

    $ids = [];
    $now = date('Y-m-d H:i:s');
    $gen = function () {
        return \Modules\V5\Entities\Helper\MyHelper::generateId();
    };
    $add = function ($suffix, $parent, $perm, $all = 1, $locCodes = []) use ($c, &$ids, $now, $gen, $locs) {
        $id = $gen();
        $c->table('archives')->insert([
            'id_archive' => $id, 'id_archive_parent' => $parent, 'name' => PREFIX . $suffix, 'type' => 1, 'status' => 1,
            'is_all_location' => $all, 'is_folder_permission' => $perm, 'is_active' => 1,
            'created_at' => $now, 'created_by' => 'QA29E2E', 'updated_at' => $now, 'updated_by' => 'QA29E2E',
        ]);
        foreach ($locCodes as $code) {
            $c->table('archive_locations')->insert(['id_archive' => $id, 'id_location' => $locs[$code]]);
        }
        $ids[$suffix] = $id;

        return $id;
    };

    $c->transaction(function () use ($c, $add, $userA, $userE, $userB, $userB2, $locs, $empA) {
        // state user: A hanya lokasi MGL (employee_locations A sudah berisi MGL), E ganti role
        $c->table('employees')->where('id_employee', $empA->id_employee)->update(['is_all_location' => 0]);
        $now = date('Y-m-d H:i:s');
        $c->table('user_roles')->where('id_user', $userE->id_user)->delete();
        $c->table('user_roles')->insert([
            'id_user' => $userE->id_user, 'id_role' => E_ROLE, 'is_all_location' => 1, 'id_location' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $full = [1, 1, 1, 1];
        $L = $add('L', null, 1, 0, ['JOG']);
        $P = $add('P', null, 1);
        insertPerm($c, $P, $userA->id_user, $full);
        $C1 = $add('P-C1', $P, 1);
        insertPerm($c, $C1, $userA->id_user, $full);
        $C2 = $add('P-C2', $P, 0);
        $Q = $add('Q', null, 1);
        insertPerm($c, $Q, $userA->id_user, [1, 0, 0, 0]);
        $R = $add('R', null, 0);
        $R1 = $add('R-R1', $R, 1);
        insertPerm($c, $R1, $userA->id_user, $full);
    });

    $ids['_userA'] = ['id_user' => $userA->id_user, 'username' => $userA->username];
    $ids['_userE'] = ['id_user' => $userE->id_user, 'username' => $userE->username];
    $ids['_userB'] = ['id_user' => $userB->id_user, 'username' => $userB->username];
    $ids['_userB2'] = ['id_user' => $userB2->id_user, 'username' => $userB2->username];
    file_put_contents($idsFile, json_encode($ids));

    // nilai awal B
    foreach (initialB() as $suffix => $r) {
        insertPerm($c, $ids[$suffix], $userB->id_user, $r);
    }

    say('fixture up: folder uji=' . (count($ids) - 4) . '; B=' . $userB->username . ' B2=' . $userB2->username . '; baseline archives=' . $before['tables']['archives']['count'] . ' archive_permissions=' . $before['tables']['archive_permissions']['count']);
    exit(0);
}

if ($cmd === 'down') {
    $n = purge($c);
    $ok = true;
    if (is_file($journal)) {
        $before = json_decode(file_get_contents($journal), true);
        if (!empty($before['users'])) {
            userRestore($c, $before['users']);
            $afterUsers = userState($c, $before['users']['idA'], $before['users']['idE']);
            if (json_encode($afterUsers) !== json_encode($before['users'])) {
                $ok = false;
                say('BEDA keadaan user A/E sesudah pemulihan');
            }
        }
        $after = tableState($c);
        foreach (TABLES as $t) {
            if (($before['tables'][$t] ?? null) !== $after[$t]) {
                $ok = false;
                say("BEDA $t: sebelum=" . json_encode($before['tables'][$t] ?? null) . ' sesudah=' . json_encode($after[$t]));
            }
        }
        if ($ok) {
            @unlink($journal);
        }
    } else {
        say('tidak ada jurnal baseline (down tanpa up?)');
    }
    @unlink($idsFile);
    say("fixture down: $n folder berawalan " . PREFIX . ' dibuang; user A/E dipulihkan; ' . ($ok ? 'keadaan = baseline (4 tabel archive* count + CHECKSUM, user_roles/employees/employee_locations A dan E baris persis)' : 'KEADAAN TIDAK SAMA DENGAN BASELINE'));
    exit($ok ? 0 : 1);
}

if ($cmd === 'ids') {
    echo json_encode(ids()) . "\n";
    exit(0);
}

if ($cmd === 'state') {
    $ids = ids();
    $byId = [];
    foreach ($ids as $k => $v) {
        if ($k[0] !== '_') {
            $byId[$v] = $k;
        }
    }
    $out = ['rows' => ['B' => [], 'B2' => []], 'history' => [], 'flags' => [], 'total_permissions' => (int) $c->table('archive_permissions')->count()];
    foreach (['B' => '_userB', 'B2' => '_userB2'] as $label => $key) {
        foreach ($c->table('archive_permissions')->where('id_user', $ids[$key]['id_user'])->whereIn('id_archive', array_keys($byId))->get() as $r) {
            $out['rows'][$label][$byId[$r->id_archive]] = [(int) $r->is_view, (int) $r->is_update, (int) $r->is_delete, (int) $r->is_store];
        }
        ksort($out['rows'][$label]);
        $out['rows'][$label] = (object) $out['rows'][$label]; // kosong tetap {} di JSON
    }
    foreach ($c->table('archives')->whereIn('id_archive', array_keys($byId))->get(['id_archive', 'is_folder_permission', 'history']) as $a) {
        $h = $a->history ? json_decode($a->history, true) : [];
        $perm = array_values(array_filter(is_array($h) ? $h : [], function ($e) {
            return ($e['action'] ?? null) === 'permission';
        }));
        $out['history'][$byId[$a->id_archive]] = ['count' => count($perm), 'last_by' => $perm ? ($perm[count($perm) - 1]['related_username'] ?? null) : null];
        $out['flags'][$byId[$a->id_archive]] = (int) $a->is_folder_permission;
    }
    ksort($out['history']);
    ksort($out['flags']);
    jsonLast($out);
    exit(0);
}

if ($cmd === 'flag') {
    $ids = ids();
    $suffix = $argv[2] ?? '';
    $val = (int) ($argv[3] ?? 1);
    if (!isset($ids[$suffix]) || $suffix[0] === '_') {
        fail('folder uji tidak dikenal: ' . $suffix);
    }
    $c->table('archives')->where('id_archive', $ids[$suffix])->update(['is_folder_permission' => $val ? 1 : 0]);
    say("flag $suffix = " . ($val ? 1 : 0));
    exit(0);
}

if ($cmd === 'resetperm') {
    $ids = ids();
    $prefixIds = [];
    foreach ($ids as $k => $v) {
        if ($k[0] !== '_') {
            $prefixIds[] = $v;
        }
    }
    foreach (['L' => 1, 'P' => 1, 'P-C1' => 1, 'P-C2' => 0, 'Q' => 1, 'R' => 0, 'R-R1' => 1] as $suffix => $perm) {
        $c->table('archives')->where('id_archive', $ids[$suffix])->update(['is_folder_permission' => $perm, 'history' => null]);
    }
    $c->table('archive_permissions')->whereIn('id_archive', $prefixIds)->whereIn('id_user', [$ids['_userB']['id_user'], $ids['_userB2']['id_user']])->delete();
    foreach (initialB() as $suffix => $r) {
        insertPerm($c, $ids[$suffix], $ids['_userB']['id_user'], $r);
    }
    say('resetperm: hak B kembali ke nilai awal, B2 kosong, flag dan riwayat folder uji kembali');
    exit(0);
}

if ($cmd === 'role') { // role A sementara (1 = superadmin EQUAL); 'restore' = kembali ke role asli A dari jurnal
    $ids = ids();
    $arg = $argv[2] ?? '';
    if (!is_file($journal)) {
        fail('tanpa jurnal (jalankan up dulu)');
    }
    $j = json_decode(file_get_contents($journal), true);
    $idA = $j['users']['idA'];
    $c->table('user_roles')->where('id_user', $idA)->delete();
    if ($arg === 'restore') {
        if ($j['users']['rolesA']) {
            $c->table('user_roles')->insert($j['users']['rolesA']);
        }
        say('role A dipulihkan');
    } else {
        $now = date('Y-m-d H:i:s');
        $c->table('user_roles')->insert(['id_user' => $idA, 'id_role' => (int) $arg, 'is_all_location' => 1, 'id_location' => null, 'created_at' => $now, 'updated_at' => $now]);
        say("role A = $arg (sementara)");
    }
    exit(0);
}

fail('pakai: up | down | ids | state | flag <nama> <0|1> | resetperm | role <id|restore>');
