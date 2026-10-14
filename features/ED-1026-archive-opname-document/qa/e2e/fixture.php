<?php
/**
 * Fixture e2e ED-1026 (BUKAN skenario regresi; dipanggil lewat qa/e2e/fixtures.json dan spec).
 *
 *   "$PHP_BIN" fixture.php up        buat folder/dokumen uji berawalan "0QA26-", sesi opname terkonfirmasi untuk BETA,
 *                                    atur user QA (lokasi Semarang saja; mode norole: QA_USER ditaruh sementara di PRESET role tanpa Opname Document)
 *   "$PHP_BIN" fixture.php down      buang HANYA data milik fixture (lihat "Jurnal"), pulihkan user/role, lalu pastikan tidak ada
 *                                    sisa milik fixture + user/role = snapshot (exit 1 bila tidak); count + CHECKSUM 7 tabel vs
 *                                    baseline hanya INFO (DB bersama)
 *   "$PHP_BIN" fixture.php ids       cetak peta nama -> id_archive (JSON, tanpa rahasia)
 *   "$PHP_BIN" fixture.php inspect   cetak keadaan DB: is_verified per dokumen uji + sesi opname milik fixture (JSON)
 *   "$PHP_BIN" fixture.php lang <ID|EN>   tukar bahasa user QA (AC-28 en); dipulihkan persis di down (bagian snapshot user)
 *   "$PHP_BIN" fixture.php reparent <nama> <induk|root>   pindahkan satu archive milik fixture (berawalan PREFIX) ke induk
 *                                    fixture lain / root (uji ARCHIVE416: subfolder terpilih tak lagi subfolder langsung)
 *
 * Jurnal (.fixture-journal.json, ditulis up SEBELUM baris pertama): id sesi opname yang sudah ada sebelum up, snapshot kolom
 * verifikasi archives yang tak-bawaan, id archive dan id sesi yang disisipkan fixture, snapshot user/role QA. Milik fixture =
 * archives berawalan PREFIX / tercatat di jurnal; sesi yang disisipkan fixture, atau dibuat lewat aplikasi selama run pada
 * folder fixture atau di root oleh QA_USER/QA_USER2. Kolom verifikasi archives lain yang disentuh sesi itu dikembalikan ke
 * nilai jurnal. Data developer di tabel yang sama tidak pernah dihapus/direset; down tanpa jurnal tidak melakukan apa pun.
 *
 * Mode: env QA26_MODE = main (default) | norole. norole (ED-1149 AC-10, BR-1..BR-4) = QA_USER ditaruh sementara di PRESET role QA26-ROLE-E2E-NOOD
 * SEBELUM login e2e: role baru dari NAMA permission eksplisit (NOOD_PRESET = izin Archive yang dipakai spec TANPA 'Opname Document', plus
 * Allow Access Website), baris user_roles QA_USER dicatat di jurnal dan dikembalikan persis di down, preset dibuang. role_permissions role
 * developer (3/5/6/22/29 dll.) TIDAK PERNAH dibaca sebagai prasyarat maupun diubah (dulu: baris Opname Document dicabut dari role 3 lalu disisipkan lagi).
 *
 * Revisi tanpa draft (FB-3): fixture tidak lagi membuat/menggeser draft; sesi yang dibuat spec lewat aplikasi (Konfirmasi) pada folder
 * fixture atau di root oleh user QA tetap dianggap milik fixture dan dibuang di down (kolom verifikasi lain dipulihkan dari jurnal).
 *
 * Pohon uji (semua di root, aktif, is_all_location=1, tanpa folder permission; nama berawalan "0" agar urut paling awal):
 *   0QA26-ALPHA    AD1 AD2  | S1 (S1D1 S1D2, sub X: S1XD1) | S2 (S2D1) | S3 (S3D1)     folder dengan subfolder
 *   0QA26-BETA     BD1 (verified) | T1 (T1D1 verified, T1D2) | T2 (T2D1)   sudah diopname hari ini 09:15 (sesi terkonfirmasi)
 *   0QA26-GAMMA    GD1 GD2 GD3                                          tanpa subfolder
 *   0QA26-DELTA    DD1                                                  tanpa subfolder (uji bentrok)
 *   0QA26-EPS      E01..E12                                             tanpa subfolder, 12 dokumen (paginasi Step 3)
 *   0QA26-VIEWONLY VD1; folder permission: user QA hanya View (menu Info/Lihat/Opname; opname tetap boleh)
 *   0QA26-NOVIEW   folder permission aktif tanpa baris untuk user QA (tanpa View: tanpa menu aksi)
 *   0QA26-ZULFA    ZD1                                                  tanpa subfolder, tak pernah dikonfirmasi (AC-8, Batal/Cancel)
 *   0QA26-HIST     H1 H2                                                tanpa subfolder; sesi riwayat dibuat spec lewat API (AC-30)
 *   0QA26-OMEGA    OD0 | OM-S1 (OD1) | OM-S2 (OD2)                      uji ARCHIVE416 (S2 dipindah di tengah sesi)
 *   0QA26-RD1/RD2  dokumen di root (tanpa folder): RD1 verified (peringatan root "Semua Arsip" AC-28), RD2 tak verified (target scan, K-4)
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
const JOURNAL_V = 3;
const ROLE_PREFIX = 'QA26-ROLE-E2E-';
const ROLE_BY = 'QA26E2E';
/**
 * Preset mode norole: izin Archive yang dipakai layar (menu baris Info/Pindahkan/Lihat/Hapus, item "+"), NAMA permission eksplisit,
 * TANPA 'Opname Document'. Allow Access Website + izin Outside Radius = syarat teknis login (bukan permission fitur).
 */
const NOOD_PRESET = [
    'Allow Access Website', 'All Archive', 'List Archive', 'Add Folder', 'Add Document', 'View Folder', 'Update Folder', 'Delete Folder',
    'Move Archive', 'Handover Document', 'Receive Document', 'Folder Permissions',
    // QA_USER = karyawan berlokasi Semarang saja: tanpa izin Outside Radius login ditolak 422 (validasi geotag); bukan permission fitur
    'Allow Sign In Outside Radius', 'Allow Check In Outside Radius', 'Allow Check Out Outside Radius', 'Allow Create & Revise Outside Radius',
];
const VERIF_COLS = ['is_verified', 'verified_at', 'verified_by', 'id_archive_opname'];

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

    return [
        'uid'      => $user->id_user,
        'username' => $user->username,
        'language' => $user->language ?? null,
        'emp_id'   => $emp->id_employee ?? null,
        'emp_all'  => $emp->is_all_location ?? null,
        'emp_locs' => $emp ? rows($c->table('employee_locations')->where('id_employee', $emp->id_employee)->orderBy('id_employee_location')->get()) : [],
        'user_roles' => rows($c->table('user_roles')->where('id_user', $user->id_user)->orderBy('id_user_role')->get()),
    ];
}

function userRestore($c, array $snap)
{
    if (array_key_exists('language', $snap) && !empty($snap['uid'])) {
        $c->table('users')->where('id_user', $snap['uid'])->update(['language' => $snap['language']]);
    }
    if ($snap['emp_id']) {
        $c->table('employees')->where('id_employee', $snap['emp_id'])->update(['is_all_location' => $snap['emp_all']]);
        $c->table('employee_locations')->where('id_employee', $snap['emp_id'])->delete();
        if ($snap['emp_locs']) {
            $c->table('employee_locations')->insert($snap['emp_locs']);
        }
    }
    // user_roles QA_USER dikembalikan persis (norole menukarnya ke preset); role developer tidak disentuh
    $c->table('user_roles')->where('id_user', $snap['uid'])->delete();
    if (!empty($snap['user_roles'])) {
        $c->table('user_roles')->insert($snap['user_roles']);
    }
}

/** Id role preset milik fixture: tercatat di jurnal + nama berawalan ROLE_PREFIX. */
function presetIds($c, ?array $j)
{
    $ids = $c->table('roles')->where('role_name', 'like', ROLE_PREFIX . '%')->pluck('id_role')->all();

    return array_values(array_unique(array_map('intval', array_merge($j['roles'] ?? [], $ids))));
}

/** Buang role preset (user_roles yang menunjuknya, role_permissions, roles). Hanya id jurnal / berawalan ROLE_PREFIX. */
function presetPurge($c, ?array $j)
{
    $ids = presetIds($c, $j);
    if ($ids) {
        $c->table('user_roles')->whereIn('id_role', $ids)->delete();
        $c->table('role_permissions')->whereIn('id_role', $ids)->delete();
        $c->table('roles')->whereIn('id_role', $ids)->delete();
    }

    return count($ids);
}

/** Buat role preset dari NAMA permission (dicatat ke jurnal SEBELUM baris role_permissions-nya); mengembalikan id_role. */
function presetCreate($c, $journal, $label, array $names, $superadmin)
{
    $now = date('Y-m-d H:i:s');
    $id = (int) $c->table('roles')->insertGetId([
        'role_name' => ROLE_PREFIX . $label, 'description' => 'QA ED-1026 e2e preset (ED-1149)', 'is_superadmin' => $superadmin,
        'is_active' => 1, 'is_used' => 1, 'created_by' => ROLE_BY, 'created_at' => $now, 'updated_by' => ROLE_BY, 'updated_at' => $now,
    ]);
    journalAdd($journal, 'roles', [$id]);
    foreach ($names as $name) {
        $perm = $c->table('permissions')->where('permission_name', $name)->first();
        if (!$perm) {
            fail("permission '$name' tidak ada di DB uji (seed permission_salesman.sql belum dijalankan)");
        }
        $c->table('role_permissions')->insert([
            'id_role' => $id, 'id_permission' => $perm->id_permission, 'permission_name' => $perm->permission_name,
            'created_at' => $now, 'updated_at' => $now, 'created_by' => ROLE_BY, 'updated_by' => ROLE_BY,
        ]);
    }

    return $id;
}

/** snapshot user sama dengan jurnal (jurnal lama tanpa kunci language: bahasa tidak dibandingkan) */
function sameUser(array $now, array $old)
{
    if (!array_key_exists('language', $old)) {
        unset($now['language']);
    }

    return $now === $old;
}

// ------------------------------------------------------------------ jurnal + pembersihan terbatas pada data milik fixture

function journalRead($file)
{
    return is_file($file) ? json_decode(file_get_contents($file), true) : null;
}

function journalWrite($file, array $j)
{
    if (file_put_contents($file, json_encode($j)) === false) {
        fail('jurnal tidak bisa ditulis: ' . basename($file));
    }
}

/** Catat id ke jurnal ($key) SEBELUM barisnya ditulis ke DB, supaya run yang mati di tengah tetap bisa dibereskan. */
function journalAdd($file, $key, array $values)
{
    $j = journalRead($file);
    $j[$key] = array_values(array_unique(array_merge($j[$key] ?? [], $values)));
    journalWrite($file, $j);
}

/** User login spec: sesi root yang mereka buat lewat aplikasi selama run = milik fixture. */
function qaUsernames()
{
    return array_values(array_filter([Config::get('QA_USER'), Config::get('QA_USER2')]));
}

/** archives berawalan PREFIX (biner: peka huruf). */
function prefixArchiveIds($c)
{
    return $c->table('archives')->whereRaw('`name` LIKE BINARY ?', [PREFIX . '%'])->pluck('id_archive')->all();
}

/** archives milik fixture = tercatat di jurnal + berawalan PREFIX. */
function fixtureArchiveIds($c, array $j)
{
    return array_values(array_unique(array_merge($j['archives'] ?? [], prefixArchiveIds($c))));
}

/**
 * Sesi opname milik fixture (keadaan DB sekarang): disisipkan fixture (jurnal 'sessions'), atau belum ada saat up (bukan
 * 'pre_sessions') dan dibuat pada folder fixture atau di root oleh user QA. Sesi lain (data developer) tidak pernah ikut.
 */
function fixtureSessionIds($c, array $j, array $archiveIds)
{
    $mine = $j['sessions'] ?? [];
    $users = $j['qa_users'] ?? [];
    $rows = $c->table('archive_opnames')->where(function ($q) use ($mine, $archiveIds, $users) {
        $q->whereRaw('1 = 0');
        if ($mine) {
            $q->orWhereIn('id_archive_opname', $mine);
        }
        if ($archiveIds) {
            $q->orWhereIn('id_archive', $archiveIds);
        }
        if ($users) {
            $q->orWhere(function ($q) use ($users) {
                $q->whereNull('id_archive')->whereIn('created_by', $users);
            });
        }
    })->pluck('id_archive_opname')->all();
    $pre = array_flip($j['pre_sessions'] ?? []);
    $own = array_flip($mine);

    return array_values(array_filter($rows, function ($id) use ($pre, $own) {
        return isset($own[$id]) || !isset($pre[$id]);
    }));
}

/** Snapshot kolom verifikasi archives yang tidak bernilai bawaan, diambil sebelum fixture menulis apa pun. */
function verificationSnapshot($c)
{
    $out = [];
    $rows = $c->table('archives')->where(function ($q) {
        $q->where('is_verified', '!=', 0)->orWhereNull('is_verified')->orWhereNotNull('verified_at')
            ->orWhereNotNull('verified_by')->orWhereNotNull('id_archive_opname');
    })->get(array_merge(['id_archive'], VERIF_COLS));
    foreach ($rows as $r) {
        $out[$r->id_archive] = [$r->is_verified, $r->verified_at, $r->verified_by, $r->id_archive_opname];
    }

    return $out;
}

/**
 * archives BUKAN milik fixture yang terakhir disentuh sesi fixture (id_archive_opname) dikembalikan ke nilai sebelum up
 * (jurnal 'pre_verif'; tidak tercatat = bernilai bawaan saat up). Baris milik fixture dilewati (dibuang pemanggil).
 */
function restoreVerification($c, array $j, array $sessions, array $archiveIds)
{
    $own = array_flip($archiveIds);
    $pre = $j['pre_verif'] ?? [];
    $n = 0;
    foreach (array_chunk($sessions, 200) as $chunk) {
        foreach ($c->table('archives')->whereIn('id_archive_opname', $chunk)->pluck('id_archive')->all() as $id) {
            if (isset($own[$id])) {
                continue;
            }
            $c->table('archives')->where('id_archive', $id)->whereIn('id_archive_opname', $chunk)
                ->update(array_combine(VERIF_COLS, $pre[$id] ?? [0, null, null, null]));
            $n++;
        }
    }

    return $n;
}

function deleteSessions($c, array $sessions)
{
    foreach (array_chunk($sessions, 200) as $chunk) {
        foreach (['archive_opname_documents', 'archive_opname_folders', 'archive_opnames'] as $t) {
            $c->table($t)->whereIn('id_archive_opname', $chunk)->delete();
        }
    }
}

/**
 * Buang HANYA data milik fixture: sesi opname fixture + baris folder/dokumennya (kolom verifikasi archives lain yang disentuh
 * sesi itu dipulihkan dari jurnal), lalu archives fixture + baris anaknya. Tidak pernah seluruh tabel.
 */
function purge($c, array $j)
{
    $archiveIds = fixtureArchiveIds($c, $j);
    $sessions = fixtureSessionIds($c, $j, $archiveIds);
    $out = ['archives' => 0, 'sessions' => $sessions, 'restored' => 0];
    $c->transaction(function () use ($c, $j, $archiveIds, $sessions, &$out) {
        $out['restored'] = restoreVerification($c, $j, $sessions, $archiveIds);
        deleteSessions($c, $sessions);
        foreach (array_chunk($archiveIds, 500) as $chunk) {
            $c->table('archive_permissions')->whereIn('id_archive', $chunk)->delete();
            $c->table('archive_documents')->whereIn('id_archive', $chunk)->delete();
            $c->table('archive_locations')->whereIn('id_archive', $chunk)->delete();
            $out['archives'] += $c->table('archives')->whereIn('id_archive', $chunk)->delete();
        }
        $c->table('archive_documents')->whereRaw('`transaction_no` LIKE BINARY ?', [PREFIX . '%'])->delete();
    });

    return $out;
}

/** Sisa data milik fixture sesudah purge (kosong = bersih). $sessions = sesi fixture yang dibuang purge. */
function leftovers($c, array $j, array $sessions)
{
    $lines = [];
    $n = $c->table('archives')->where(function ($q) use ($j) {
        $q->whereRaw('`name` LIKE BINARY ?', [PREFIX . '%']);
        if (!empty($j['archives'])) {
            $q->orWhereIn('id_archive', $j['archives']);
        }
    })->count();
    if ($n) {
        $lines[] = "SISA $n baris archives milik fixture";
    }
    $all = array_values(array_unique(array_merge($sessions, $j['sessions'] ?? [])));
    foreach (array_merge(OP_TABLES, ['archives']) as $t) {
        $m = 0;
        foreach (array_chunk($all, 200) as $chunk) {
            $m += $c->table($t)->whereIn('id_archive_opname', $chunk)->count();
        }
        if ($m) {
            $lines[] = "SISA $m baris $t menunjuk sesi opname fixture";
        }
    }
    $left = fixtureSessionIds($c, $j, $j['archives'] ?? []);
    if ($left) {
        $lines[] = 'SISA ' . count($left) . ' sesi opname milik fixture';
    }
    $np = (int) $c->table('roles')->whereIn('id_role', presetIds($c, $j) ?: [0])->count();
    if ($np) {
        $lines[] = "SISA $np role preset " . ROLE_PREFIX . '*';
    }

    return $lines;
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
    $old = journalRead($journal);
    if ($old !== null) { // run sebelumnya mati: bereskan dulu, hanya data yang tercatat jurnal itu
        if (($old['v'] ?? 0) !== JOURNAL_V) {
            fail('jurnal format lama ' . basename($journal) . ': tidak dibereskan otomatis; periksa data ' . PREFIX . ' dan user QA manual lalu hapus jurnalnya');
        }
        $r = purge($c, $old);
        userRestore($c, $old['user']);
        presetPurge($c, $old);
        $lines = leftovers($c, $old, $r['sessions']);
        if (!sameUser(userSnapshot($c), $old['user'])) {
            $lines[] = 'BEDA user/role QA';
        }
        if ($lines) {
            fail('sisa run lama tidak bisa dibereskan: ' . implode('; ', $lines));
        }
        @unlink($journal);
        say('sisa run lama dibereskan: ' . $r['archives'] . ' archives, ' . count($r['sessions']) . ' sesi fixture');
    }
    $orphans = count(prefixArchiveIds($c));
    if ($orphans > 0) {
        fail("$orphans baris archives berawalan " . PREFIX . ' tanpa jurnal: tidak dihapus otomatis; bereskan manual');
    }
    // tidak ada prasyarat "tabel opname kosong" / "kolom verifikasi bawaan": fixture hidup berdampingan dengan data developer

    $before = state($c);
    $user = userSnapshot($c);
    if ($c->table('roles')->whereIn('id_role', array_map(function ($r) {
        return (int) $r['id_role'];
    }, $user['user_roles']) ?: [0])->where('role_name', 'like', ROLE_PREFIX . '%')->exists()) {
        fail('QA_USER berada di preset sisa ' . ROLE_PREFIX . '* tanpa jurnal: bereskan manual (pulihkan user_roles lalu buang role itu)');
    }
    journalWrite($journal, [
        'v' => JOURNAL_V, 'state' => $before, 'user' => $user, 'qa_users' => qaUsernames(),
        'pre_sessions' => $c->table('archive_opnames')->pluck('id_archive_opname')->all(),
        'pre_verif' => verificationSnapshot($c), 'archives' => [], 'sessions' => [], 'roles' => [],
    ]);
    presetPurge($c, null); // sisa preset tanpa jurnal (nama berawalan ROLE_PREFIX) dibuang dulu

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
        // QA_USER -> PRESET tanpa Opname Document (is_superadmin 3 = tingkat yang sama dengan role asli QA_USER, hanya izin Archive-nya eksplisit)
        $roleId = presetCreate($c, $journal, 'NOOD', NOOD_PRESET, 3);
        $first = $user['user_roles'][0] ?? [];
        $c->table('user_roles')->where('id_user', $user['uid'])->delete();
        $c->table('user_roles')->insert([
            'id_user' => $user['uid'], 'id_role' => $roleId, 'is_all_location' => $first['is_all_location'] ?? 1,
            'id_location' => $first['id_location'] ?? null, 'created_at' => $nowStr, 'updated_at' => $nowStr,
        ]);
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

        // revisi tanpa draft: folder tanpa subfolder yang tak pernah dikonfirmasi (AC-8, Batal), folder riwayat (AC-30), ARCHIVE416, dokumen root
        $zulfa = $add(PREFIX . 'ZULFA', 1, null);
        $add(PREFIX . 'ZD1', 2, $zulfa, 6);
        $hist = $add(PREFIX . 'HIST', 1, null);
        $add(PREFIX . 'H1', 2, $hist, 6);
        $add(PREFIX . 'H2', 2, $hist, 7);
        $omega = $add(PREFIX . 'OMEGA', 1, null);
        $add(PREFIX . 'OD0', 2, $omega, 6);
        $os1 = $add(PREFIX . 'OMEGA-S1', 1, $omega);
        $add(PREFIX . 'OD1', 2, $os1, 6);
        $os2 = $add(PREFIX . 'OMEGA-S2', 1, $omega);
        $add(PREFIX . 'OD2', 2, $os2, 6);
        $add(PREFIX . 'RD1', 2, null, 6); // verified di bawah (peringatan root)
        $add(PREFIX . 'RD2', 2, null, 7); // tak verified: target scan dokumen root

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
    journalAdd($journal, 'archives', array_values($ids));

    // BETA + BETA-T1 sudah diopname hari ini 09:15 (sesi terkonfirmasi, dibuat langsung di DB); BD1, T1D1 dan RD1 (root) verified
    $sid = $gen();
    journalAdd($journal, 'sessions', [$sid]);
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
        foreach ([PREFIX . 'BD1', PREFIX . 'T1D1', PREFIX . 'RD1'] as $doc) {
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
    $old = journalRead($journal);
    if ($old === null) { // tanpa jurnal tidak ada bukti baris mana milik fixture: jangan sentuh apa pun
        $orphans = count(prefixArchiveIds($c));
        say('fixture down: tidak ada jurnal (down tanpa up yang lulus?) -> TIDAK ADA yang dihapus atau dipulihkan'
            . ($orphans ? "; PERIKSA: $orphans baris archives berawalan " . PREFIX . ' tanpa jurnal (bereskan manual)' : ''));
        exit($orphans ? 1 : 0);
    }
    if (($old['v'] ?? 0) !== JOURNAL_V) {
        say('fixture down: jurnal format lama -> TIDAK ADA yang dihapus; bereskan manual');
        exit(1);
    }
    $r = purge($c, $old);
    userRestore($c, $old['user']);
    $np = presetPurge($c, $old);
    $lines = leftovers($c, $old, $r['sessions']);
    if (!sameUser(userSnapshot($c), $old['user'])) {
        $lines[] = 'BEDA user/role QA: tidak sama dengan snapshot awal';
    }
    $info = [];
    compare($old['state'], state($c), $info);
    foreach ($info as $l) {
        say('INFO (bukan kegagalan: DB bersama bisa berubah oleh aktivitas lain) ' . $l);
    }
    foreach ($lines as $l) {
        say($l);
    }
    if (!$lines) {
        @unlink($journal);
    }
    @unlink($idsFile);
    say('fixture down: ' . $r['archives'] . ' archives fixture + ' . count($r['sessions']) . ' sesi opname fixture dibuang, '
        . $r['restored'] . ' baris archives lain dipulihkan kolom verifikasinya; ' . $np . ' role preset dibuang; '
        . ($lines ? 'MASIH ADA SISA MILIK FIXTURE' : 'tidak ada sisa milik fixture, user/role QA = snapshot'));
    exit($lines ? 1 : 0);
}

if ($cmd === 'ids') {
    echo is_file($idsFile) ? file_get_contents($idsFile) : '{}';
    echo "\n";
    exit(0);
}

if ($cmd === 'inspect') {
    $docs = [];
    $q = $c->table('archives')->whereRaw('`name` LIKE BINARY ?', [PREFIX . '%'])->where('type', 2)->orderBy('name')->get();
    foreach ($q as $r) {
        $docs[$r->name] = [
            'is_verified' => (int) $r->is_verified, 'verified' => $r->verified_at !== null,
            'verified_by' => $r->verified_by, 'has_opname' => $r->id_archive_opname !== null,
            'folder' => $c->table('archives')->where('id_archive', $r->id_archive_parent)->value('name'),
            'is_active' => (int) $r->is_active,
        ];
    }
    $sessions = [];
    $j = journalRead($journal);
    $own = $j ? fixtureSessionIds($c, $j, fixtureArchiveIds($c, $j)) : []; // hanya sesi milik fixture
    foreach ($own ? $c->table('archive_opnames')->whereIn('id_archive_opname', $own)->orderBy('created_at')->orderBy('id_archive_opname')->get() : [] as $s) {
        $sessions[] = [
            'folder' => $s->id_archive ? $c->table('archives')->where('id_archive', $s->id_archive)->value('name') : null,
            'status' => (int) $s->status, 'scope_name' => $s->scope_name, 'total' => (int) $s->total_documents,
            'scanned' => (int) $s->scanned_count, 'verified' => (int) $s->verified_count, 'not_found' => (int) $s->not_found_count,
            'invalid' => (int) $s->invalid_count, 'unscanned' => (int) $s->unscanned_count, 'unverified' => (int) $s->unverified_count,
            'confirmed' => $s->confirmed_at !== null, 'folders' => $c->table('archive_opname_folders')->where('id_archive_opname', $s->id_archive_opname)->count(),
            'docs' => $c->table('archive_opname_documents')->where('id_archive_opname', $s->id_archive_opname)->count(),
        ];
    }
    $tables = [];
    foreach (OP_TABLES as $t) {
        $tables[$t] = (int) $c->table($t)->count();
    }
    $tables['verification_non_default'] = count(verificationSnapshot($c));
    echo json_encode(['docs' => $docs, 'sessions' => $sessions, 'tables' => $tables]) . "\n";
    exit(0);
}

if ($cmd === 'lang') {
    $lang = strtoupper($argv[2] ?? '');
    $j = journalRead($journal);
    if (!in_array($lang, ['ID', 'EN'], true) || !$j) {
        fail('lang: ID | EN (dan fixture harus sudah up)');
    }
    $c->table('users')->where('id_user', $j['user']['uid'])->update(['language' => $lang]);
    say("bahasa user QA: $lang (dipulihkan di down)");
    exit(0);
}

if ($cmd === 'reparent') {
    $name = $argv[2] ?? '';
    $to = $argv[3] ?? '';
    $j = journalRead($journal);
    if (!$j || strpos($name, PREFIX) !== 0 || ($to !== 'root' && strpos($to, PREFIX) !== 0)) {
        fail('reparent <nama berawalan ' . PREFIX . '> <induk berawalan ' . PREFIX . ' | root>');
    }
    $row = $c->table('archives')->whereRaw("`name` = BINARY ?", [$name])->first();
    $parent = $to === 'root' ? null : $c->table('archives')->whereRaw("`name` = BINARY ?", [$to])->value('id_archive');
    if (!$row || ($to !== 'root' && !$parent)) {
        fail('archive fixture tidak ditemukan');
    }
    $c->table('archives')->where('id_archive', $row->id_archive)->update(['id_archive_parent' => $parent]);
    say("$name dipindah ke $to");
    exit(0);
}

fail('pakai: up | down | ids | inspect | lang <ID|EN> | reparent <nama> <induk|root>');
