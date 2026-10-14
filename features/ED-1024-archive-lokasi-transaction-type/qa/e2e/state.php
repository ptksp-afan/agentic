<?php
/**
 * Fixture e2e ED-1024: menaruh user QA (QA_USER) sementara di keadaan "user JOG" atau "superadmin", lalu memulihkan
 * PERSIS. BUKAN skenario regresi (nama berkas tidak cocok scenario*.php); dipanggil lewat qa/e2e/fixtures.json.
 *
 *   "$PHP_BIN" state.php up jog     PRESET role QA24-ROLE-E2E-JOG (is_superadmin 4; izin Archive lengkap + Allow Sign In/Check In/Check Out/Create & Revise
 *                                   Outside Radius, dari NAMA permission) + employee lokasi JOG saja
 *   "$PHP_BIN" state.php up super   role 1 (is_superadmin 1, role sistem; hanya user_roles QA_USER yang ditukar) + employee lokasi JOG saja (bypass harus
 *                                   terbukti tidak bergantung pada lokasi employee); satu dokumen Billing MILIK FIXTURE (BILL/QA24-E2E/00001, created_by
 *                                   QA24E2E, is_active 1, semua lokasi, root) dibuat supaya filter Type = Billing punya hasil; dibuang di down
 *   "$PHP_BIN" state.php up noperm  PRESET role QA24-ROLE-E2E-NOPERM (hanya Allow Access Website + 3 izin Outside Radius, tanpa permission Archive; bisa login
 *                                   tanpa geotag): simulasi "tanpa permission/lisensi" (AC-13)
 *   "$PHP_BIN" state.php up none    tidak mengubah apa pun (state bukan jog/super)
 *   "$PHP_BIN" state.php down       pulihkan dari jurnal, verifikasi persis, buang jurnal
 *   "$PHP_BIN" state.php dump <out> tulis ringkasan keadaan DB (CHECKSUM + baris user QA) ke berkas, untuk dibandingkan
 *
 * Jurnal pemulihan: qa/e2e/.state-journal.json. Bila ada sisa jurnal (run sebelumnya mati), `up` memulihkannya dulu.
 * Tidak mencetak rahasia. Yang ditulis: user_roles, employee_locations, employees (baris employee QA), role preset QA24-ROLE-E2E-* (+ role_permissions-nya)
 * dan, state super, satu dokumen Billing milik fixture (archives + archive_documents). (ED-1149 AC-10/BR-1..BR-4) role developer 26/28 dan baris archives
 * developer TIDAK dipakai/diubah lagi: dulu state jog/noperm menukar QA_USER ke role developer 26/28 (bergantung pada izin role itu) dan state super
 * mengaktifkan sementara dokumen Billing developer (is_active -1 -> 1).
 */

namespace QaHttp;

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../../..')));

require QA_AGENTIC . '/scripts/qa-http/lib/Outcome.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Config.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Laravel.php';

use Illuminate\Support\Facades\DB;

const ROLE_PREFIX = 'QA24-ROLE-E2E-';
const QA_BY = 'QA24E2E';
const BILL_NAME = 'BILL/QA24-E2E/00001';
/** Preset (NAMA permission eksplisit): state jog = izin Archive lengkap + izin Outside Radius (login employee dengan lokasi terbatas tanpa geotag). */
const PRESETS = [
    'JOG' => [
        'List Archive', 'Add Folder', 'View Folder', 'Update Folder', 'Delete Folder', 'Move Archive', 'All Archive', 'Add Document',
        'Handover Document', 'Receive Document', 'Allow Sign In Outside Radius', 'Allow Check In Outside Radius',
        'Allow Check Out Outside Radius', 'Allow Create & Revise Outside Radius',
    ],
    'NOPERM' => ['Allow Access Website', 'Allow Sign In Outside Radius', 'Allow Check Out Outside Radius', 'Allow Create & Revise Outside Radius'],
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
$arg = $argv[2] ?? '';
$journal = __DIR__ . '/.state-journal.json';

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

function snapshot($c)
{
    $user = Config::get('QA_USER');
    $uid = $c->table('users')->where('username', $user)->value('id_user');
    if (!$uid) {
        fail('QA_USER tidak ditemukan di DB');
    }
    $emp = $c->table('employees')->where('id_user', $uid)->first();

    $snap = [
        'uid'       => $uid,
        'roles'     => rows($c->table('user_roles')->where('id_user', $uid)->orderBy('id_user_role')->get()),
        'emp'       => $emp ? (array) $emp : null,
        'emp_locs'  => $emp ? rows($c->table('employee_locations')->where('id_employee', $emp->id_employee)->orderBy('id_employee_location')->get()) : [],
    ];
    return $snap;
}

/** Id role preset milik fixture: tercatat di jurnal ('_presets') + nama berawalan ROLE_PREFIX. */
function presetIds($c, array $snap)
{
    $ids = $c->table('roles')->where('role_name', 'like', ROLE_PREFIX . '%')->pluck('id_role')->all();

    return array_values(array_unique(array_map('intval', array_merge($snap['_presets'] ?? [], $ids))));
}

/** Dokumen Billing milik fixture (BILL_NAME, created_by QA_BY) + id tercatat di jurnal ('_billing'). */
function billingIds($c, array $snap)
{
    $ids = $c->table('archives')->where('name', BILL_NAME)->where('created_by', QA_BY)->pluck('id_archive')->all();

    return array_values(array_unique(array_merge($snap['_billing'] ?? [], $ids)));
}

/** Catat id ke jurnal ($key) SEBELUM barisnya ditulis ke DB, supaya run yang mati di tengah tetap bisa dibereskan. */
function journalAdd($journal, $key, $value)
{
    $j = json_decode(file_get_contents($journal), true);
    $j[$key] = array_values(array_unique(array_merge($j[$key] ?? [], [$value])));
    file_put_contents($journal, json_encode($j));
}

/** Buat role preset dari NAMA permission (id dicatat ke jurnal SEBELUM baris role_permissions-nya). */
function presetCreate($c, $journal, $label)
{
    $now = date('Y-m-d H:i:s');
    $id = (int) $c->table('roles')->insertGetId([
        'role_name' => ROLE_PREFIX . $label, 'description' => 'QA ED-1024 e2e preset (ED-1149)', 'is_superadmin' => 4,
        'is_active' => 1, 'is_used' => 1, 'created_by' => QA_BY, 'created_at' => $now, 'updated_by' => QA_BY, 'updated_at' => $now,
    ]);
    journalAdd($journal, '_presets', $id);
    foreach (PRESETS[$label] as $name) {
        $perm = $c->table('permissions')->where('permission_name', $name)->first();
        if (!$perm) {
            fail("permission '$name' tidak ada di DB uji");
        }
        $c->table('role_permissions')->insert([
            'id_role' => $id, 'id_permission' => $perm->id_permission, 'permission_name' => $perm->permission_name,
            'created_at' => $now, 'updated_at' => $now, 'created_by' => QA_BY, 'updated_by' => QA_BY,
        ]);
    }

    return $id;
}

function restore($c, array $snap)
{
    $uid = $snap['uid'];
    $c->transaction(function () use ($c, $snap, $uid) {
        $c->table('user_roles')->where('id_user', $uid)->delete();
        if ($snap['roles']) {
            $c->table('user_roles')->insert($snap['roles']);
        }
        if ($snap['emp']) {
            $id = $snap['emp']['id_employee'];
            $c->table('employees')->where('id_employee', $id)->update($snap['emp']);
            $c->table('employee_locations')->where('id_employee', $id)->delete();
            if ($snap['emp_locs']) {
                $c->table('employee_locations')->insert($snap['emp_locs']);
            }
        }
        // dokumen Billing milik fixture + role preset dibuang (user_roles QA_USER sudah dikembalikan di atas)
        $bills = billingIds($c, $snap);
        if ($bills) {
            $c->table('archive_documents')->whereIn('id_archive', $bills)->delete();
            $c->table('archive_locations')->whereIn('id_archive', $bills)->delete();
            $c->table('archives')->whereIn('id_archive', $bills)->delete();
        }
        $presets = presetIds($c, $snap);
        if ($presets) {
            $c->table('user_roles')->whereIn('id_role', $presets)->delete();
            $c->table('role_permissions')->whereIn('id_role', $presets)->delete();
            $c->table('roles')->whereIn('id_role', $presets)->delete();
        }
    });
}

function verify($c, array $snap)
{
    unset($snap['_presets'], $snap['_billing']);
    $after = snapshot($c);
    if (json_encode($after) !== json_encode($snap)) {
        fail('pemulihan user QA TIDAK persis sama dengan sebelumnya (jurnal dipertahankan)');
    }
}

function recoverJournal($c, $journal)
{
    if (!is_file($journal)) {
        return false;
    }
    $snap = json_decode(file_get_contents($journal), true);
    if (!is_array($snap)) {
        fail('jurnal rusak: ' . $journal);
    }
    restore($c, $snap);
    verify($c, $snap);
    @unlink($journal);
    say('jurnal sisa dipulihkan');

    return true;
}

if ($cmd === 'up') {
    recoverJournal($c, $journal);
    if (!in_array($arg, ['jog', 'super', 'noperm'], true)) {
        say("state '{$arg}': tidak ada perubahan (pakai E2E_STATE=jog, super atau noperm)");
        exit(0);
    }
    $snap = snapshot($c);
    if (!$snap['emp']) {
        fail('QA_USER tidak punya employee');
    }
    if ($c->table('roles')->whereIn('id_role', array_map(function ($r) {
        return (int) $r['id_role'];
    }, $snap['roles']) ?: [0])->where('role_name', 'like', ROLE_PREFIX . '%')->exists()) {
        fail('QA_USER berada di preset sisa ' . ROLE_PREFIX . '* tanpa jurnal: bereskan manual');
    }
    file_put_contents($journal, json_encode($snap));

    $jog = $c->table('locations')->where('location_code', 'JOG')->value('id_location');
    if (!$jog) {
        fail('lokasi JOG tidak ada di DB');
    }
    $now = date('Y-m-d H:i:s');
    try {
        // jog / noperm = PRESET milik fixture (nama permission eksplisit); super = role sistem 1 (is_superadmin 1; hanya user_roles QA_USER yang ditukar)
        $role = $arg === 'super' ? 1 : presetCreate($c, $journal, $arg === 'jog' ? 'JOG' : 'NOPERM');
        if ($arg === 'super') {
            journalAdd($journal, '_billing', $bill = \Modules\V5\Entities\Helper\MyHelper::generateId());
        }
        $c->transaction(function () use ($c, $snap, $role, $jog, $now, $arg, &$bill) {
            $uid = $snap['uid'];
            $idEmp = $snap['emp']['id_employee'];
            $c->table('user_roles')->where('id_user', $uid)->delete();
            $c->table('user_roles')->insert([
                'id_user' => $uid, 'id_role' => $role, 'is_all_location' => 1, 'id_location' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($arg !== 'noperm') {
                $c->table('employees')->where('id_employee', $idEmp)->update(['is_all_location' => 0]);
                $c->table('employee_locations')->where('id_employee', $idEmp)->delete();
                $c->table('employee_locations')->insert([
                    'id_employee' => $idEmp, 'id_location' => $jog, 'is_default' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            if ($arg === 'super') { // dokumen Billing milik fixture (root, semua lokasi, aktif): filter Type = Billing punya hasil tanpa menyentuh data developer
                $c->table('archives')->insert([
                    'id_archive' => $bill, 'id_archive_parent' => null, 'name' => BILL_NAME, 'type' => 2, 'status' => 1,
                    'is_all_location' => 1, 'is_folder_permission' => 0, 'is_active' => 1,
                    'created_at' => $now, 'created_by' => QA_BY, 'updated_at' => $now, 'updated_by' => QA_BY,
                ]);
                $c->table('archive_documents')->insert([
                    'id_archive_document' => \Modules\V5\Entities\Helper\MyHelper::generateId(), 'id_archive' => $bill,
                    'id_transaction' => 'QA24TX' . substr($bill, -10), 'transaction_no' => BILL_NAME, 'transaction_type' => 222,
                    'related_employee_name' => 'Sales QA24', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });
    } catch (\Throwable $e) {
        restore($c, $snap);
        @unlink($journal);
        fail('gagal menerapkan state, dipulihkan: ' . $e->getMessage());
    }
    say("state '{$arg}' dipasang: QA_USER role {$role}" . ($arg === 'noperm' ? ', employee tidak diubah' : ', employee lokasi JOG saja')
        . ($arg === 'super' ? '; dokumen Billing milik fixture ' . BILL_NAME . ' dibuat (aktif, root)' : '; preset ' . ROLE_PREFIX . ($arg === 'jog' ? 'JOG' : 'NOPERM')));
    exit(0);
}

if ($cmd === 'down') {
    if (!is_file($journal)) {
        say('tidak ada jurnal: tidak ada yang dipulihkan');
        exit(0);
    }
    recoverJournal($c, $journal);
    say('state dipulihkan persis (diverifikasi)');
    exit(0);
}

if ($cmd === 'dump') {
    $out = [];
    foreach (['archives', 'archive_locations', 'archive_documents', 'user_roles', 'employee_locations', 'employees', 'roles', 'locations', 'permissions', 'role_permissions'] as $table) {
        $sum = (array) $c->selectOne("CHECKSUM TABLE `{$table}`");
        $out[] = $table . ' ' . end($sum) . ' rows=' . $c->table($table)->count();
    }
    $snap = snapshot($c);
    $out[] = 'user_roles: ' . json_encode($snap['roles']);
    $out[] = 'emp: ' . json_encode($snap['emp']);
    $out[] = 'emp_locs: ' . json_encode($snap['emp_locs']);
    $out[] = 'lang: ' . $c->table('users')->where('id_user', $snap['uid'])->value('language');
    $out[] = 'QA01 rows: ' . $c->table('archives')->where('name', 'like', 'QA01-%')->count();
    $out[] = 'locations is_active: ' . json_encode($c->table('locations')->orderBy('location_code')->pluck('is_active', 'location_code')->all());
    $out[] = 'role2 active: ' . $c->table('roles')->where('id_role', 2)->value('is_active');
    $text = implode("\n", $out) . "\n";
    if ($arg !== '') {
        file_put_contents($arg, $text);
        say("dump ditulis ke {$arg}");
    } else {
        echo $text;
    }
    exit(0);
}

fail('pakai: state.php up <jog|super|none> | down | dump <berkas>');
