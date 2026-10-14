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
 *   "$PHP_BIN" fixture.php role <1|restore>    role A sementara (1 = role sistem superadmin EQUAL; id lain DITOLAK); restore = role asli dari jurnal
 *   "$PHP_BIN" fixture.php uprofile <D|M|E>    (rev) role E (QA_USER2) sementara menurut profil spec §6: D = role A tanpa Folder Permissions
 *                                              (masih Update Folder), M = List Archive + View Folder + Folder Permissions saja,
 *                                              E = PRESET QA29F-ROLE-E (tanpa keduanya, keadaan awal fixture). Role E/D/M dibuat lazim dari NAMA
 *                                              permission eksplisit dengan nama QA29F-ROLE-<E|D|M> dan dibuang `down`. FE membaca peran dari GET
 *                                              auth/info: muat ulang penuh.
 *   "$PHP_BIN" fixture.php resetperm           hapus SEMUA baris archive_permissions folder uji berawalan 0QA29- (semua user), lalu
 *                                              pasang lagi nilai awal (supaya tiap test mulai dari keadaan yang sama), flag dan riwayat
 *                                              folder uji dikembalikan
 *
 * Pelaku: A = QA_USER (profil default; role 3 = Super Admin non-bypass, ber-Update Folder + Folder Permissions = profil A spec; employee
 * DIUBAH sementara jadi lokasi MGL saja supaya folder L (JOG) di luar scope-nya), E = QA_USER2 (profil user2; user_roles-nya DIGANTI sementara ke
 * PRESET QA29F-ROLE-E: List Archive + Handover/Receive/Add Document + Allow Access Website, TANPA Update Folder dan TANPA Folder Permissions, supaya "+"
 * masih tampil tetapi tanpa Set Permission by User; `uprofile` menukarnya ke D atau M). B dan B2 = dua user target (aktif, ber-List Archive
 * menurut id_permission, bukan superadmin 1/2, bukan customer, bukan A/E) yang dipilih urut username.
 * (ED-1149 AC-10, BR-1..BR-4) Tidak ada role developer yang dibaca sebagai prasyarat atau diubah: dulu E = role 18 (bergantung pada izin role itu),
 * profil D menyalin permission role A (role 3) dan B/B2 dipilih lewat kolom role_permissions.permission_name (NULL pada baris hasil UI role).
 *
 * Folder uji (semua is_all_location=1 kecuali L; dibuat oleh 'QA29E2E', bukan A, jadi hak pembuat tidak berlaku):
 *   0QA29-L        On   root, hanya lokasi JOG -> di luar scope A
 *   0QA29-P        On   root; A V+U+D+S;  B View;  E View (supaya D/M/E bisa membuka P)
 *     0QA29-P-C1   On   A V+U+D+S;  B kosong
 *     0QA29-P-C2   Off  A kosong (diwarisi P);  B View+Store (nilai tersimpan, terkunci karena Off)
 *   0QA29-Q        On   root; A View saja (rev: tetap bisa dikelola, K-15 b);  B View+Update
 *   0QA29-R        Off  root; A kosong
 *     0QA29-R-R1   On   A V+U+D+S;  B kosong
 *   0QA29-J        On   root; A View saja            (data FB-9 pola ED-1025, dibuat user lain)
 *     0QA29-J-JA   On   A V+U+D+S;  B kosong
 *     0QA29-J-JB   On   tanpa baris A (A tanpa View); B kosong
 *     0QA29-J-JC   Off  tanpa baris A;  B View+Store (nilai tersimpan, terkunci karena Off)
 *   0QA29-F        Off  root; tanpa baris siapa pun (AC-25: A menyalakan Izin di tab Izin; rev3 AC-30/31: saklar)
 *   0QA29-G        Off  root; A View saja (rev3, ED-1130: saklar dinyalakan A atas dirinya = baris A dinaikkan, tetap satu baris)
 * Nilai awal (initialRows): A: J=1000, J-JA=1111, P=1111, P-C1=1111, Q=1000, R-R1=1111, G=1000; B: P=1000, C2=1001, Q=1100, J-JC=1001; E: P=1000;
 * B2: tanpa baris.
 *
 * Yang ditulis: archives, archive_documents(0), archive_locations, archive_permissions (hanya baris folder berawalan 0QA29-),
 * user_roles (E), employees.is_all_location (A), roles + role_permissions (hanya QA29F-ROLE-*). Semuanya dipulihkan persis di `down`
 * (jurnal qa/e2e/.fixture-journal.json; checksum 6 tabel (archive, roles) dibandingkan).
 */

namespace QaHttp;

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../../..')));

require QA_AGENTIC . '/scripts/qa-http/lib/Outcome.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Config.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Laravel.php';

use Illuminate\Support\Facades\DB;

const PREFIX = '0QA29-';
const ROLE_PREFIX = 'QA29F-ROLE-';
const TABLES = ['archives', 'archive_documents', 'archive_locations', 'archive_permissions', 'roles', 'role_permissions'];
/** Preset E (QA_USER2 keadaan awal fixture): NAMA permission eksplisit; Allow Access Website = syarat teknis login web user non-karyawan. */
const E_PRESET = ['List Archive', 'Handover Document', 'Receive Document', 'Add Document', 'Allow Access Website'];
/** Preset D: izin Archive lengkap TANPA Folder Permissions (tetap Update Folder dan item "+" lain). */
const D_PRESET = [
    'List Archive', 'All Archive', 'Add Folder', 'View Folder', 'Update Folder', 'Delete Folder', 'Move Archive', 'Add Document',
    'Handover Document', 'Receive Document', 'Opname Document', 'Allow Access Website',
];

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

/** Buang role fixture QA29F-ROLE-* (user_roles yang menunjuknya, role_permissions, role). Idempoten; role lain tidak tersentuh. */
function purgeRoles($c)
{
    $ids = $c->table('roles')->where('role_name', 'like', ROLE_PREFIX . '%')->pluck('id_role')->all();
    if ($ids) {
        $c->table('user_roles')->whereIn('id_role', $ids)->delete();
        $c->table('role_permissions')->whereIn('id_role', $ids)->delete();
        $c->table('roles')->whereIn('id_role', $ids)->delete();
    }

    return count($ids);
}

/** id_permission menurut nama (gagal bila tidak ada: seed ulang ED-1025 belum diterapkan). */
function permIds($c, array $names)
{
    $out = [];
    foreach ($names as $permName) {
        $perm = $c->table('permissions')->where('permission_name', $permName)->first();
        if (!$perm) {
            fail("permission '$permName' tidak ada di DB uji (seed ulang ED-1025 belum diterapkan?)");
        }
        $out[] = (int) $perm->id_permission;
    }

    return $out;
}

/**
 * Role fixture QA29F-ROLE-<label> dengan permission $idPermissions (id_permission; role_permissions.permission_name di baris lama
 * bisa kosong, jadi role disusun lewat id). Dibuat sekali, dipakai ulang.
 */
function ensureRole($c, $label, array $idPermissions)
{
    $name = ROLE_PREFIX . $label;
    $row = $c->table('roles')->where('role_name', $name)->first();
    if ($row) {
        return (int) $row->id_role;
    }
    $now = date('Y-m-d H:i:s');
    $id = $c->table('roles')->insertGetId([
        'role_name' => $name, 'description' => 'QA ED-1029 e2e fixture', 'is_superadmin' => 4,
        'is_active' => 1, 'is_used' => 1, 'created_by' => 'QA29F', 'created_at' => $now, 'updated_by' => 'QA29F', 'updated_at' => $now,
    ]);
    foreach (array_values(array_unique($idPermissions)) as $idPermission) {
        $perm = $c->table('permissions')->where('id_permission', $idPermission)->first();
        $c->table('role_permissions')->insert([
            'id_role' => $id, 'id_permission' => $idPermission, 'permission_name' => $perm ? $perm->permission_name : null,
            'created_at' => $now, 'updated_at' => $now, 'created_by' => 'QA29F', 'updated_by' => 'QA29F',
        ]);
    }

    return (int) $id;
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
    return ['P' => [1, 0, 0, 0], 'P-C2' => [1, 0, 0, 1], 'Q' => [1, 1, 0, 0], 'J-JC' => [1, 0, 0, 1]];
}

/** Nilai awal A (login) dan E (QA_USER2, apa pun role sementaranya). */
function initialA()
{
    $full = [1, 1, 1, 1];

    return ['J' => [1, 0, 0, 0], 'J-JA' => $full, 'P' => $full, 'P-C1' => $full, 'Q' => [1, 0, 0, 0], 'R-R1' => $full, 'G' => [1, 0, 0, 0]];
}

function initialE()
{
    return ['P' => [1, 0, 0, 0]];
}

/** Flag Izin Folder awal semua folder uji. */
function initialFlags()
{
    return ['L' => 1, 'P' => 1, 'P-C1' => 1, 'P-C2' => 0, 'Q' => 1, 'R' => 0, 'R-R1' => 1, 'J' => 1, 'J-JA' => 1, 'J-JB' => 1, 'J-JC' => 0, 'F' => 0, 'G' => 0];
}

if ($cmd === 'up') {
    if (is_file($journal)) { // run sebelumnya mati: pulihkan dulu
        $old = json_decode(file_get_contents($journal), true);
        purge($c);
        if (!empty($old['users'])) {
            userRestore($c, $old['users']);
        }
        purgeRoles($c);
        $now = tableState($c);
        if (!empty($old['tables']) && $old['tables'] !== $now) {
            fail('sisa jurnal run lama: keadaan setelah pemulihan tidak sama dengan baseline lama');
        }
        @unlink($journal);
    }
    if ($c->table('archives')->where('name', 'like', PREFIX . '%')->count() > 0) {
        purge($c);
    }
    purgeRoles($c);

    $userA = $c->table('users')->where('username', Config::get('QA_USER'))->first();
    $userE = Config::get('QA_USER2') ? $c->table('users')->where('username', Config::get('QA_USER2'))->first() : null;
    if (!$userA || !$userE) {
        fail('QA_USER / QA_USER2 tidak ditemukan di DB');
    }

    // B, B2: pola query = select/document-archive/archive/users (SelectArchiveService::user)
    $customerUsers = $c->table('customers')->whereNotNull('id_user')->pluck('id_user')->all();
    // hak dibaca per id_permission (join permissions), bukan kolom role_permissions.permission_name (NULL pada baris hasil centang UI role; ED-1149 BR-3)
    $withListArchive = $c->table('role_permissions as rp')->join('permissions as p', 'p.id_permission', '=', 'rp.id_permission')
        ->where('p.permission_name', 'List Archive')->pluck('rp.id_role')->all();
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

    $eRole = ensureRole($c, 'E', permIds($c, E_PRESET)); // preset E (journal sudah ditulis di atas; down membuang QA29F-ROLE-*)
    $c->transaction(function () use ($c, $add, $userA, $userE, $userB, $userB2, $locs, $empA, $eRole) {
        // state user: A hanya lokasi MGL (employee_locations A sudah berisi MGL), E ganti role ke preset E
        $c->table('employees')->where('id_employee', $empA->id_employee)->update(['is_all_location' => 0]);
        $now = date('Y-m-d H:i:s');
        $c->table('user_roles')->where('id_user', $userE->id_user)->delete();
        $c->table('user_roles')->insert([
            'id_user' => $userE->id_user, 'id_role' => $eRole, 'is_all_location' => 1, 'id_location' => null,
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
        // (rev) data FB-9 pola ED-1025: J (A View saja) > JA (A penuh), JB (tanpa baris A), JC (Off); F = Off tanpa baris (AC-25)
        $J = $add('J', null, 1);
        $add('J-JA', $J, 1);
        $add('J-JB', $J, 1);
        $add('J-JC', $J, 0);
        $add('F', null, 0);
        $add('G', null, 0); // (rev3) Off, A View saja (baris A dipasang di bawah)
    });

    $ids['_userA'] = ['id_user' => $userA->id_user, 'username' => $userA->username];
    $ids['_userE'] = ['id_user' => $userE->id_user, 'username' => $userE->username];
    $ids['_userB'] = ['id_user' => $userB->id_user, 'username' => $userB->username];
    $ids['_userB2'] = ['id_user' => $userB2->id_user, 'username' => $userB2->username];
    file_put_contents($idsFile, json_encode($ids));

    // nilai awal B, A (hanya yang belum ditulis di transaksi di atas: J, J-JA) dan E
    foreach (initialB() as $suffix => $r) {
        insertPerm($c, $ids[$suffix], $userB->id_user, $r);
    }
    foreach (['J', 'J-JA', 'G'] as $suffix) {
        insertPerm($c, $ids[$suffix], $userA->id_user, initialA()[$suffix]);
    }
    foreach (initialE() as $suffix => $r) {
        insertPerm($c, $ids[$suffix], $userE->id_user, $r);
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
            $nr = purgeRoles($c); // role D/M sementara (user_roles E sudah dipulihkan di atas)
            if ($nr > 0) {
                say("role fixture " . ROLE_PREFIX . "* dibuang: $nr");
            }
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
    say("fixture down: $n folder berawalan " . PREFIX . ' dibuang; user A/E dipulihkan; ' . ($ok ? 'keadaan = baseline (6 tabel archive/roles/role_permissions count + CHECKSUM, user_roles/employees/employee_locations A dan E baris persis)' : 'KEADAAN TIDAK SAMA DENGAN BASELINE'));
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
    $out = ['rows' => ['A' => [], 'B' => [], 'B2' => []], 'history' => [], 'flags' => [], 'updated_by' => [], 'dups' => [], 'total_permissions' => (int) $c->table('archive_permissions')->count()];
    foreach (['A' => '_userA', 'B' => '_userB', 'B2' => '_userB2'] as $label => $key) {
        foreach ($c->table('archive_permissions')->where('id_user', $ids[$key]['id_user'])->whereIn('id_archive', array_keys($byId))->get() as $r) {
            $out['rows'][$label][$byId[$r->id_archive]] = [(int) $r->is_view, (int) $r->is_update, (int) $r->is_delete, (int) $r->is_store];
        }
        ksort($out['rows'][$label]);
        $out['rows'][$label] = (object) $out['rows'][$label]; // kosong tetap {} di JSON
    }
    // (rev3) tanpa baris ganda per (folder, user): grup (id_archive, id_user) dengan lebih dari satu baris di folder uji
    foreach ($c->table('archive_permissions')->whereIn('id_archive', array_keys($byId))->select('id_archive', 'id_user', $c->raw('COUNT(*) AS n'))->groupBy('id_archive', 'id_user')->havingRaw('COUNT(*) > 1')->get() as $d) {
        $out['dups'][] = $byId[$d->id_archive] . '/' . $d->id_user . ' x' . $d->n;
    }
    foreach ($c->table('archives')->whereIn('id_archive', array_keys($byId))->get(['id_archive', 'is_folder_permission', 'history', 'updated_by']) as $a) {
        $h = $a->history ? json_decode($a->history, true) : [];
        $perm = array_values(array_filter(is_array($h) ? $h : [], function ($e) {
            return ($e['action'] ?? null) === 'permission';
        }));
        $out['history'][$byId[$a->id_archive]] = ['count' => count($perm), 'last_by' => $perm ? ($perm[count($perm) - 1]['related_username'] ?? null) : null];
        $out['flags'][$byId[$a->id_archive]] = (int) $a->is_folder_permission;
        $out['updated_by'][$byId[$a->id_archive]] = $a->updated_by;
    }
    ksort($out['updated_by']);
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
    foreach (initialFlags() as $suffix => $perm) {
        $c->table('archives')->where('id_archive', $ids[$suffix])->update(['is_folder_permission' => $perm, 'history' => null]);
    }
    // semua baris folder uji (user mana pun: B, B2, A, E) dibuang lalu nilai awal dipasang lagi
    $c->table('archive_permissions')->whereIn('id_archive', $prefixIds)->delete();
    foreach (initialB() as $suffix => $r) {
        insertPerm($c, $ids[$suffix], $ids['_userB']['id_user'], $r);
    }
    foreach (initialA() as $suffix => $r) {
        insertPerm($c, $ids[$suffix], $ids['_userA']['id_user'], $r);
    }
    foreach (initialE() as $suffix => $r) {
        insertPerm($c, $ids[$suffix], $ids['_userE']['id_user'], $r);
    }
    say('resetperm: hak B, A dan E kembali ke nilai awal, B2 kosong, flag dan riwayat folder uji kembali');
    exit(0);
}

if ($cmd === 'uprofile') { // role E (QA_USER2) sementara menurut profil spec: D, M, atau E (preset awal fixture)
    $arg = strtoupper($argv[2] ?? '');
    if (!is_file($journal)) {
        fail('tanpa jurnal (jalankan up dulu)');
    }
    $j = json_decode(file_get_contents($journal), true);
    $idE = $j['users']['idE'];
    if ($arg === 'E') {
        $roleId = ensureRole($c, 'E', permIds($c, E_PRESET));
    } elseif ($arg === 'M') {
        // M = List Archive + View Folder + Folder Permissions saja (profil D ED-1025); 'Allow Access Website' hanya syarat teknis login
        // web bagi QA_USER2 (employee_type bukan 0: tanpa itu permission_v5 mencabut tokennya), bukan permission fitur
        $roleId = ensureRole($c, 'M', permIds($c, ['List Archive', 'View Folder', 'Folder Permissions', 'Allow Access Website']));
    } elseif ($arg === 'D') {
        // D = izin Archive lengkap (NAMA eksplisit, D_PRESET) tanpa Folder Permissions: tetap Update Folder dan item "+" lain
        $roleId = ensureRole($c, 'D', permIds($c, D_PRESET));
    } else {
        fail('profil tidak dikenal: ' . $arg . ' (D|M|E)');
    }
    $now = date('Y-m-d H:i:s');
    $c->table('user_roles')->where('id_user', $idE)->delete();
    $c->table('user_roles')->insert(['id_user' => $idE, 'id_role' => $roleId, 'is_all_location' => 1, 'id_location' => null, 'created_at' => $now, 'updated_at' => $now]);
    say("role E (QA_USER2) = profil $arg (id_role $roleId, preset sementara)");
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
    if ($arg !== 'restore' && $arg !== '1') {
        fail("role: hanya '1' (role sistem superadmin EQUAL) atau 'restore'; role developer lain ditolak (ED-1149 BR-4)");
    }
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

fail('pakai: up | down | ids | state | flag <nama> <0|1> | resetperm | role <1|restore> | uprofile <D|M|E>');
