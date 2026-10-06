<?php
/**
 * Fixture e2e ED-1024: menaruh user QA (QA_USER) sementara di keadaan "user JOG" atau "superadmin", lalu memulihkan
 * PERSIS. BUKAN skenario regresi (nama berkas tidak cocok scenario*.php); dipanggil lewat qa/e2e/fixtures.json.
 *
 *   "$PHP_BIN" state.php up jog     role 26 (Pusat - Support, is_superadmin 4, hak Archive lengkap + Allow Sign In Outside Radius) + employee lokasi JOG saja
 *   "$PHP_BIN" state.php up super   role 1 (is_superadmin 1) + employee lokasi JOG saja (bypass harus terbukti tidak
 *                                   bergantung pada lokasi employee); satu dokumen Billing (is_active -1, semua lokasi)
 *                                   diaktifkan (is_active 1) supaya filter Type = Billing punya hasil; dipulihkan di down
 *   "$PHP_BIN" state.php up noperm  role 28 (Pusat - Auditor, tanpa permission Archive; bisa login tanpa geotag): simulasi "tanpa permission/lisensi" (AC-13)
 *   "$PHP_BIN" state.php up none    tidak mengubah apa pun (state bukan jog/super)
 *   "$PHP_BIN" state.php down       pulihkan dari jurnal, verifikasi persis, buang jurnal
 *   "$PHP_BIN" state.php dump <out> tulis ringkasan keadaan DB (CHECKSUM + baris user QA) ke berkas, untuk dibandingkan
 *
 * Jurnal pemulihan: qa/e2e/.state-journal.json. Bila ada sisa jurnal (run sebelumnya mati), `up` memulihkannya dulu.
 * Tidak mencetak rahasia. Yang ditulis: user_roles, employee_locations, employees (baris employee QA) dan, state super,
 * satu baris archives (dokumen Billing).
 */

namespace QaHttp;

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../../..')));

require QA_AGENTIC . '/scripts/qa-http/lib/Outcome.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Config.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Laravel.php';

use Illuminate\Support\Facades\DB;

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
    $bill = billingDoc($c);
    $snap['billing'] = $bill ? (array) $bill : null;

    return $snap;
}

/** Dokumen Billing yang dipakai state super: baris archives pertama (urut id) dengan transaction_type 222, is_all_location 1. */
function billingDoc($c)
{
    $id = $GLOBALS['BILLING_ID'] ?? null;
    if ($id === null) {
        $id = $c->table('archives as a')->join('archive_documents as d', 'a.id_archive', '=', 'd.id_archive')
            ->where('d.transaction_type', 222)->where('a.is_all_location', 1)->where('a.type', 2)
            ->orderBy('a.id_archive')->value('a.id_archive');
        $GLOBALS['BILLING_ID'] = $id ?: false;
    }

    return $id ? $c->table('archives')->where('id_archive', $id)->first() : null;
}

function restore($c, array $snap)
{
    $uid = $snap['uid'];
    $c->transaction(function () use ($c, $snap, $uid) {
        $c->table('user_roles')->where('id_user', $uid)->delete();
        if ($snap['roles']) {
            $c->table('user_roles')->insert($snap['roles']);
        }
        if (!empty($snap['billing'])) {
            $c->table('archives')->where('id_archive', $snap['billing']['id_archive'])->update($snap['billing']);
        }
        if ($snap['emp']) {
            $id = $snap['emp']['id_employee'];
            $c->table('employees')->where('id_employee', $id)->update($snap['emp']);
            $c->table('employee_locations')->where('id_employee', $id)->delete();
            if ($snap['emp_locs']) {
                $c->table('employee_locations')->insert($snap['emp_locs']);
            }
        }
    });
}

function verify($c, array $snap)
{
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
    file_put_contents($journal, json_encode($snap));

    $jog = $c->table('locations')->where('location_code', 'JOG')->value('id_location');
    if (!$jog) {
        fail('lokasi JOG tidak ada di DB');
    }
    $role = ['jog' => 26, 'super' => 1, 'noperm' => 28][$arg];
    $now = date('Y-m-d H:i:s');
    try {
        $c->transaction(function () use ($c, $snap, $role, $jog, $now, $arg) {
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
            if ($arg === 'super' && !empty($snap['billing'])) {
                $c->table('archives')->where('id_archive', $snap['billing']['id_archive'])->update(['is_active' => 1]);
            }
        });
    } catch (\Throwable $e) {
        restore($c, $snap);
        @unlink($journal);
        fail('gagal menerapkan state, dipulihkan: ' . $e->getMessage());
    }
    say("state '{$arg}' dipasang: QA_USER role {$role}" . ($arg === 'noperm' ? ', employee tidak diubah' : ', employee lokasi JOG saja')
        . ($arg === 'super' && !empty($snap['billing']) ? '; dokumen Billing ' . $snap['billing']['name'] . ' diaktifkan' : ''));
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
