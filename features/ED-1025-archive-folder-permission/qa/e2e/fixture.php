<?php
/**
 * Fixture e2e ED-1025 (BUKAN skenario regresi; dipanggil lewat qa/e2e/fixtures.json dan oleh spec untuk tukar peran).
 *
 *   fixture.php up                  buat folder/dokumen uji berawalan "0QA25-", baris archive_permissions, dan role uji
 *                                   berawalan "QA25-ROLE-"; catat baseline (tabel archive* count+CHECKSUM, user QA: role + bahasa)
 *   fixture.php down                buang SEMUA baris berawalan "0QA25-" dan role "QA25-ROLE-", pulihkan role + bahasa user QA,
 *                                   lalu verifikasi tabel archive* (count + CHECKSUM) = baseline
 *   fixture.php ids                 peta nama -> id_archive (+ daftar permission per peran), JSON tanpa rahasia
 *   fixture.php role <who> <preset> tukar role user QA: who = default (QA_USER) | user2 (QA_USER2);
 *                                   preset = orig | A | C | D | SUPER   (orig = pulihkan dari baseline)
 *   fixture.php lang <who> <ID|EN>  tukar bahasa user QA (pulih di down)
 *   fixture.php perm <nama>         isi archive_permissions + flag satu folder fixture (JSON, baca-saja)
 *   fixture.php prop <nama>         kolom archives satu folder fixture (name, description, parent, flag) (JSON, baca-saja)
 *
 * Pengguna: A = QA_USER (profil default), B = QA_USER2 (profil user2). Keduanya non-superadmin pada keadaan asli (role 3 dan 5).
 * Peran uji (revisi ED-1025, spec 6): A = List Archive, Update/Delete Folder, Add Folder, Add Document, Move Archive, View Folder,
 * Handover/Receive Document, Folder Permissions; C = A tanpa Folder Permissions; D = List Archive, View Folder, Folder Permissions;
 * SUPER = role 1 (Super Admin EQUAL, is_superadmin 1). Semua role uji memuat `Allow Access Website`: QA_USER2 bukan karyawan,
 * tanpa permission itu middleware `permission_v5` menolak 403 dan MENCABUT tokennya. Spec memanggil `role` sebelum membuka layar (FE membaca ulang auth/info).
 *
 * Folder (semua berawalan "0QA25-"; Z = wadah Off supaya daftar root pendek, anak Z berperilaku seperti root):
 *   EDIT   Off, pembuat A                                    P      On, A VUDS, B V  (anak P-C Off, dokumen P-DOC)
 *   DENY   On, A VUDS (anak DENY-C On, A VUDS, B V)         DENYA  On, B VUDS (A ditolak)
 *   STORE  On, A VUDS, B V+S                                 MOVE   Off
 *   Z      Off, wadah:
 *     J On (A V) > JA On (A VUDS), JB On (B V), JC Off       JV On (A V)
 *     AUTO Off (tanpa baris), AUTO2 Off (A V), ALL On (A VUDS, B V), DP On (A V, B V), CED Off
 *   Y      Off, wadah kedua (daftar dipaginasi ~10 baris, jadi dipecah):
 *     SUPAUTO Off, OWN, OWN2, OWN3 On (A VUDS, B V), OWNC On (pembuat A; A VUDS, B V), OWNS On (B V)
 */

namespace QaHttp;

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../../..')));

require QA_AGENTIC . '/scripts/qa-http/lib/Outcome.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Config.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Laravel.php';

use Illuminate\Support\Facades\DB;

const PREFIX = '0QA25-';
const ROLE_PREFIX = 'QA25-ROLE-';
const TABLES = ['archives', 'archive_documents', 'archive_locations', 'archive_permissions'];
const INFO_TABLES = ['roles', 'role_permissions', 'user_roles'];

const PERMS = [
    'A' => ['List Archive', 'Update Folder', 'Delete Folder', 'Add Folder', 'Add Document', 'Move Archive', 'View Folder', 'Handover Document', 'Receive Document', 'Folder Permissions', 'Allow Access Website'],
    'C' => ['List Archive', 'Update Folder', 'Delete Folder', 'Add Folder', 'Add Document', 'Move Archive', 'View Folder', 'Handover Document', 'Receive Document', 'Allow Access Website'],
    'D' => ['List Archive', 'View Folder', 'Folder Permissions', 'Allow Access Website'],
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
    return array_map(function ($r) {
        return (array) $r;
    }, $collection->all());
}

function state($c, array $tables)
{
    $out = [];
    foreach ($tables as $t) {
        $row = (array) $c->selectOne("CHECKSUM TABLE `$t`");
        $out[$t] = ['count' => (int) $c->table($t)->count(), 'checksum' => (string) ($row['Checksum'] ?? '')];
    }

    return $out;
}

function qaUser($c, $who)
{
    $key = $who === 'user2' ? 'QA_USER2' : ($who === 'default' ? 'QA_USER' : null);
    if (!$key || !Config::get($key)) {
        fail("who tidak dikenal atau kosong: $who (default | user2)");
    }
    $user = $c->table('users')->where('username', Config::get($key))->first();
    if (!$user) {
        fail("user $key tidak ada di DB");
    }

    return $user;
}

function userSnapshot($c, $user)
{
    return [
        'id_user' => $user->id_user,
        'roles' => rows($c->table('user_roles')->where('id_user', $user->id_user)->orderBy('id_user_role')->get()),
        'language' => $c->table('users')->where('id_user', $user->id_user)->value('language'),
    ];
}

function restoreUser($c, array $snap)
{
    $c->table('user_roles')->where('id_user', $snap['id_user'])->delete();
    if ($snap['roles']) {
        $c->table('user_roles')->insert($snap['roles']);
    }
    $c->table('users')->where('id_user', $snap['id_user'])->update(['language' => $snap['language']]);
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

function makeRole($c, $label, array $names)
{
    $now = date('Y-m-d H:i:s');
    $id = $c->table('roles')->insertGetId([
        'role_name' => ROLE_PREFIX . $label, 'description' => 'QA ED-1025 e2e fixture', 'is_superadmin' => 4,
        'is_active' => 1, 'is_used' => 1, 'created_by' => 'QA25E2E', 'created_at' => $now, 'updated_by' => 'QA25E2E', 'updated_at' => $now,
    ]);
    foreach ($names as $name) {
        $perm = $c->table('permissions')->where('permission_name', $name)->first();
        if (!$perm) {
            fail("permission '$name' tidak ada di DB uji (seed ulang belum diterapkan?)");
        }
        $c->table('role_permissions')->insert([
            'id_role' => $id, 'id_permission' => $perm->id_permission, 'permission_name' => $perm->permission_name,
            'created_at' => $now, 'updated_at' => $now, 'created_by' => 'QA25E2E', 'updated_by' => 'QA25E2E',
        ]);
    }

    return (int) $id;
}

function permissionNames($c, array $roleIds)
{
    return $c->table('role_permissions')->whereIn('id_role', $roleIds)->orderBy('permission_name')->pluck('permission_name')->all();
}

function recover($c, $journal)
{
    if (!is_file($journal)) {
        return null;
    }
    $old = json_decode(file_get_contents($journal), true);
    purge($c);
    purgeRoles($c);
    foreach (($old['users'] ?? []) as $snap) {
        restoreUser($c, $snap);
    }

    return $old;
}

if ($cmd === 'up') {
    if (is_file($journal)) { // run sebelumnya mati: pulihkan dulu
        $old = recover($c, $journal);
        $now = state($c, TABLES);
        if ($old && ($old['before'] ?? null) !== $now) {
            fail('sisa jurnal run lama: keadaan setelah purge tidak sama dengan baseline lama');
        }
        @unlink($journal);
    }
    if ($c->table('archives')->where('name', 'like', PREFIX . '%')->count() > 0) {
        purge($c);
    }
    purgeRoles($c);

    $userA = qaUser($c, 'default');
    $userB = qaUser($c, 'user2');

    $before = state($c, TABLES);
    file_put_contents($journal, json_encode([
        'before' => $before,
        'info' => state($c, INFO_TABLES),
        'users' => ['default' => userSnapshot($c, $userA), 'user2' => userSnapshot($c, $userB)],
    ]));

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

    $roleIds = [];
    $c->transaction(function () use ($add, $perm, $c, $userA, $userB, &$roleIds) {
        $a = $userA->id_user;
        $b = $userB->id_user;
        $E = 'QA25E2E';

        // --- ED-1025 asli (AC-3, 17-21)
        $add(PREFIX . 'EDIT', 1, null, 0, $userA->username);

        $p = $add(PREFIX . 'P', 1, null, 1, $E);
        $perm($p, $a, 1, 1, 1, 1);
        $perm($p, $b, 1, 0, 0, 0);
        $add(PREFIX . 'P-C', 1, $p, 0, $E);
        $add(PREFIX . 'P-DOC', 2, $p, 0, $E);

        $d = $add(PREFIX . 'DENY', 1, null, 1, $E);
        $perm($d, $a, 1, 1, 1, 1);
        $dc = $add(PREFIX . 'DENY-C', 1, $d, 1, $E);
        $perm($dc, $a, 1, 1, 1, 1);
        $perm($dc, $b, 1, 0, 0, 0);

        $da = $add(PREFIX . 'DENYA', 1, null, 1, $E); // A ditolak (layar 06 bagi A, id-ID)
        $perm($da, $b, 1, 1, 1, 1);

        $s = $add(PREFIX . 'STORE', 1, null, 1, $E);
        $perm($s, $a, 1, 1, 1, 1);
        $perm($s, $b, 1, 0, 0, 1);

        $add(PREFIX . 'MOVE', 1, null, 0, $E);

        // --- revisi (rev): wadah Z + pohon FB-9
        $z = $add(PREFIX . 'Z', 1, null, 0, $E);

        $j = $add(PREFIX . 'J', 1, $z, 1, $E);
        $perm($j, $a, 1, 0, 0, 0);
        $ja = $add(PREFIX . 'JA', 1, $j, 1, $E);
        $perm($ja, $a, 1, 1, 1, 1);
        $jb = $add(PREFIX . 'JB', 1, $j, 1, $E);
        $perm($jb, $b, 1, 0, 0, 0);
        $add(PREFIX . 'JC', 1, $j, 0, $E);

        $jv = $add(PREFIX . 'JV', 1, $z, 1, $E);
        $perm($jv, $a, 1, 0, 0, 0);

        $add(PREFIX . 'AUTO', 1, $z, 0, $E);
        $auto2 = $add(PREFIX . 'AUTO2', 1, $z, 0, $E);
        $perm($auto2, $a, 1, 0, 0, 0);
        $add(PREFIX . 'CED', 1, $z, 0, $E);

        $all = $add(PREFIX . 'ALL', 1, $z, 1, $E);
        $perm($all, $a, 1, 1, 1, 1);
        $perm($all, $b, 1, 0, 0, 0);

        $dp = $add(PREFIX . 'DP', 1, $z, 1, $E);
        $perm($dp, $a, 1, 0, 0, 0);
        $perm($dp, $b, 1, 0, 0, 0);

        $y = $add(PREFIX . 'Y', 1, null, 0, $E); // wadah kedua: daftar tabel dipaginasi, maks. ~10 baris per halaman
        $add(PREFIX . 'SUPAUTO', 1, $y, 0, $E);
        foreach (['OWN', 'OWN2', 'OWN3'] as $n) {
            $o = $add(PREFIX . $n, 1, $y, 1, $E);
            $perm($o, $a, 1, 1, 1, 1);
            $perm($o, $b, 1, 0, 0, 0);
        }
        $oc = $add(PREFIX . 'OWNC', 1, $y, 1, $userA->username); // pembuat = A
        $perm($oc, $a, 1, 1, 1, 1);
        $perm($oc, $b, 1, 0, 0, 0);
        $os = $add(PREFIX . 'OWNS', 1, $y, 1, $E);
        $perm($os, $b, 1, 0, 0, 0);

        foreach (PERMS as $label => $names) {
            $roleIds[$label] = makeRole($c, $label, $names);
        }
    });

    $ids['_userA'] = ['id_user' => $userA->id_user, 'username' => $userA->username];
    $ids['_userB'] = ['id_user' => $userB->id_user, 'username' => $userB->username];
    $origA = array_map(function ($r) {
        return $r['id_role'];
    }, rows($c->table('user_roles')->where('id_user', $userA->id_user)->get()));
    $origB = array_map(function ($r) {
        return $r['id_role'];
    }, rows($c->table('user_roles')->where('id_user', $userB->id_user)->get()));
    $ids['_perms'] = [
        'A' => PERMS['A'], 'C' => PERMS['C'], 'D' => PERMS['D'],
        'orig_default' => permissionNames($c, $origA),
        'orig_user2' => permissionNames($c, $origB),
    ];
    $ids['_roles'] = $roleIds;
    file_put_contents($idsFile, json_encode($ids));
    say('fixture up: ' . (count($ids) - 4) . ' folder/dokumen + 3 role; baseline archives=' . $before['archives']['count'] . ' archive_permissions=' . $before['archive_permissions']['count']);
    exit(0);
}

if ($cmd === 'down') {
    $n = purge($c);
    $r = purgeRoles($c);
    $ok = true;
    if (is_file($journal)) {
        $old = json_decode(file_get_contents($journal), true);
        foreach (($old['users'] ?? []) as $who => $snap) {
            restoreUser($c, $snap);
            $now = userSnapshot($c, (object) ['id_user' => $snap['id_user']]);
            if (json_encode($now) !== json_encode($snap)) {
                $ok = false;
                say("BEDA user $who: role/bahasa tidak sama dengan baseline");
            }
        }
        $after = state($c, TABLES);
        foreach (TABLES as $t) {
            if (($old['before'][$t] ?? null) !== $after[$t]) {
                $ok = false;
                say("BEDA $t: sebelum=" . json_encode($old['before'][$t] ?? null) . ' sesudah=' . json_encode($after[$t]));
            }
        }
        $info = state($c, INFO_TABLES);
        foreach (INFO_TABLES as $t) {
            if (($old['info'][$t] ?? null) !== $info[$t]) {
                say("CATATAN $t (informasi, tidak menggagalkan): sebelum=" . json_encode($old['info'][$t] ?? null) . ' sesudah=' . json_encode($info[$t]) . ' (data bersama bisa berubah oleh proses lain)');
            }
        }
        if ($c->table('roles')->where('role_name', 'like', ROLE_PREFIX . '%')->count() > 0) {
            $ok = false;
            say('role ' . ROLE_PREFIX . ' masih ada');
        }
        if ($ok) {
            @unlink($journal);
        }
    } else {
        say('tidak ada jurnal baseline (down tanpa up?)');
    }
    @unlink($idsFile);
    say("fixture down: $n folder/dokumen berawalan " . PREFIX . " dibuang, $r role " . ROLE_PREFIX . ' dibuang; ' . ($ok ? 'tabel archive* = baseline (count + CHECKSUM), role + bahasa user QA pulih' : 'KEADAAN TIDAK SAMA DENGAN BASELINE'));
    exit($ok ? 0 : 1);
}

if ($cmd === 'ids') {
    echo is_file($idsFile) ? file_get_contents($idsFile) : '{}';
    echo "\n";
    exit(0);
}

if ($cmd === 'role') {
    $who = $argv[2] ?? '';
    $preset = $argv[3] ?? '';
    if (!is_file($journal)) {
        fail('role: jurnal tidak ada (jalankan up dulu)');
    }
    $old = json_decode(file_get_contents($journal), true);
    $user = qaUser($c, $who);
    $snap = $old['users'][$who] ?? null;
    if (!$snap) {
        fail("role: tidak ada snapshot untuk $who");
    }
    $roleId = null;
    if ($preset === 'orig') {
        restoreUser($c, ['id_user' => $snap['id_user'], 'roles' => $snap['roles'], 'language' => $c->table('users')->where('id_user', $snap['id_user'])->value('language')]);
        say("role $who: orig");
        exit(0);
    }
    if ($preset === 'SUPER') {
        $roleId = 1;
    } elseif (isset(PERMS[$preset])) {
        $roleId = (int) $c->table('roles')->where('role_name', ROLE_PREFIX . $preset)->value('id_role');
    }
    if (!$roleId) {
        fail("preset tidak dikenal atau role belum dibuat: $preset");
    }
    $now = date('Y-m-d H:i:s');
    $c->table('user_roles')->where('id_user', $user->id_user)->delete();
    $c->table('user_roles')->insert([
        'id_user' => $user->id_user, 'id_role' => $roleId, 'is_all_location' => 1, 'id_location' => null,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    say("role $who: $preset (id_role $roleId)");
    exit(0);
}

if ($cmd === 'lang') {
    $who = $argv[2] ?? '';
    $lang = strtoupper($argv[3] ?? '');
    if (!in_array($lang, ['ID', 'EN'], true)) {
        fail('lang: ID | EN');
    }
    $user = qaUser($c, $who);
    $c->table('users')->where('id_user', $user->id_user)->update(['language' => $lang]);
    say("bahasa $who: $lang");
    exit(0);
}

if ($cmd === 'perm' || $cmd === 'prop') {
    $name = $argv[2] ?? '';
    if (strpos($name, PREFIX) !== 0) {
        fail('hanya folder berawalan ' . PREFIX);
    }
    $row = $c->table('archives')->where('name', $name)->first();
    if (!$row) {
        echo json_encode(null), "\n";
        exit(0);
    }
    if ($cmd === 'prop') {
        echo json_encode((array) $row), "\n";
        exit(0);
    }
    $rowsOut = $c->table('archive_permissions')->join('users', 'users.id_user', '=', 'archive_permissions.id_user')
        ->where('archive_permissions.id_archive', $row->id_archive)->orderBy('users.username')
        ->get(['users.username', 'archive_permissions.is_view', 'archive_permissions.is_update', 'archive_permissions.is_delete', 'archive_permissions.is_store']);
    echo json_encode([
        'is_folder_permission' => (int) $row->is_folder_permission,
        'rows' => array_map(function ($r) {
            return [$r->username, (int) $r->is_view, (int) $r->is_update, (int) $r->is_delete, (int) $r->is_store];
        }, $rowsOut->all()),
    ]), "\n";
    exit(0);
}

fail('pakai: up | down | ids | role <who> <preset> | lang <who> <ID|EN> | perm <nama> | prop <nama>');
