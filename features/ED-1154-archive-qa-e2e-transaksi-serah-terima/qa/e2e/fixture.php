<?php
/**
 * ED-1154 (ED-1155) - helper e2e BACA SAJA untuk tes archive lewat transaksi nyata. BUKAN skenario regresi, BUKAN setup data:
 * tidak ada fixtures.json. Semua koneksi DB = QaHttp\Laravel::db() (sesi MySQL `SET SESSION TRANSACTION READ ONLY`): tulisan apa
 * pun ditolak server. Data run dibuat lewat UI oleh spec dan DIBIARKAN (spec § 10, G-3); run dikenali dari jurnal
 * work/ED-1154/runs/<runId>.json yang ditulis spec (Node).
 *
 *   "$PHP_BIN" fixture.php preflight [resumeId] AC-1: prasyarat sebelum A.0 (spec r2: approval CUSTOMER/SALES_ORDER, PIN mati) -> JSON
 *                                               resumeId = run gagal yang dilanjutkan (debug alat uji): carrier/attendance milik
 *                                               run itu tidak dihitung sebagai penghalang
 *   "$PHP_BIN" fixture.php inspect <runId>      keadaan transaksi + arsip run itu (rantai dari SO buatan QA_USER sejak run mulai) -> JSON
 *   "$PHP_BIN" fixture.php queue <runId>        bukti antrian (BR-11): baris transaction_queues transaksi run + jumlah `jobs` -> JSON
 *   "$PHP_BIN" fixture.php changed <runId>      AC-15: baris `archives` yang dibuat/diubah sejak run mulai -> JSON
 *   "$PHP_BIN" fixture.php previous <runId>     AC-16: arsip + Billing run SEBELUMNYA (dari jurnal lengkap) -> JSON
 *
 * Keluaran: satu baris JSON terakhir di stdout. Tanpa password/token (hanya username dari config).
 */

namespace QaHttp;

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../../..')));

require QA_AGENTIC . '/scripts/qa-http/lib/Outcome.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Config.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Laravel.php';

const RUNS_DIR = QA_AGENTIC . '/work/ED-1154/runs';
const CUSTOMER_CODE = 'CST-10120';      // BR-6 "BERKAT, TK." (bukan "...(KALIURANG)")
const LOCATION_NAME = 'Semarang';      // BR-6
const ROLE_I = 3;                       // spec § 4: role 3 (i), 5 (a), 8 (w)
const ROLE_A = 5;
const ROLE_W = 8;
const TYPE_DRIVER = 2;                  // Employee::TYPE_DRIVER
const TYPE_SALESMAN = 1;                // Employee::TYPE_SALESMAN
const TT_SO = 6;
const TT_DO = 7;
const TT_SI = 8;

function out(array $data, $code = 0)
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit($code);
}

function db()
{
    static $db = null;
    if ($db === null) {
        $db = Laravel::db(Config::defaultDb());
    }

    return $db;
}

/** waktu aplikasi (zona app Laravel, sama dengan kolom created_at/updated_at yang ditulis BE) */
function appNow()
{
    Laravel::boot();

    return \Carbon\Carbon::now()->format('Y-m-d H:i:s');
}

function users()
{
    return [
        'i' => Config::get('QA_USER'),
        'a' => Config::get('QA_USER2'),
        'w' => Config::get('QA_WAWAN_USER'),
    ];
}

function journalPath($runId)
{
    if (!preg_match('/^\d{12}$/', (string) $runId)) {
        throw new \InvalidArgumentException("runId tidak valid: {$runId}");
    }

    return RUNS_DIR . "/{$runId}.json";
}

function readJournal($runId)
{
    $file = journalPath($runId);
    if (!is_file($file)) {
        throw new \RuntimeException("jurnal tidak ada: {$file}");
    }

    return json_decode(file_get_contents($file), true);
}

/** semua jurnal run (urut runId), opsional kecuali satu */
function allJournals($exceptRunId = null)
{
    $out = [];
    foreach (glob(RUNS_DIR . '/*.json') ?: [] as $file) {
        $id = basename($file, '.json');
        if ($id === $exceptRunId || !preg_match('/^\d{12}$/', $id)) {
            continue;
        }
        $j = json_decode(file_get_contents($file), true);
        if (is_array($j)) {
            $out[$id] = $j;
        }
    }
    ksort($out);

    return $out;
}

function userRow($username)
{
    $u = db()->table('users')->where('username', $username)->first(['id_user', 'username', 'is_active', 'is_login', 'language']);
    if (!$u) {
        return null;
    }
    $e = db()->table('employees')->where('id_user', $u->id_user)->first(['id_employee', 'first_name', 'employee_type', 'is_all_location', 'is_active']);
    $roles = db()->table('user_roles as ur')->join('roles as r', 'r.id_role', '=', 'ur.id_role')
        ->where('ur.id_user', $u->id_user)->orderBy('ur.id_role')->get(['ur.id_role', 'r.role_name', 'ur.is_all_location']);
    $locs = $e ? db()->table('employee_locations as el')->join('locations as l', 'l.id_location', '=', 'el.id_location')
        ->where('el.id_employee', $e->id_employee)->pluck('l.location_name')->all() : [];

    return [
        'username' => $u->username,
        'id_user' => $u->id_user,
        'is_active' => (int) $u->is_active,
        'language' => $u->language,
        'id_employee' => $e->id_employee ?? null,
        'employee_name' => $e->first_name ?? null,
        'employee_type' => isset($e->employee_type) ? (int) $e->employee_type : null,
        'employee_all_location' => isset($e->is_all_location) ? (int) $e->is_all_location : null,
        'employee_locations' => $locs,
        'roles' => array_map(function ($r) {
            return ['id_role' => (int) $r->id_role, 'role_name' => $r->role_name];
        }, $roles->all()),
    ];
}

/** keadaan satu baris archives (dokumen/folder), untuk asersi § 4 */
function archiveRow($row)
{
    if (!$row) {
        return null;
    }
    $history = json_decode($row->history ?? '[]', true) ?: [];
    $parentName = $row->id_archive_parent
        ? db()->table('archives')->where('id_archive', $row->id_archive_parent)->value('name')
        : null;
    $doc = db()->table('archive_documents')->where('id_archive', $row->id_archive)
        ->first(['id_transaction', 'transaction_no', 'transaction_type', 'source_transaction_no', 'source_transaction_type', 'related_employee_name']);
    $locs = db()->table('archive_locations')->where('id_archive', $row->id_archive)->pluck('id_location')->all();

    return [
        'id_archive' => $row->id_archive,
        'name' => $row->name,
        'type' => (int) $row->type,
        'status' => (int) $row->status,
        'is_active' => (int) $row->is_active,
        'is_all_location' => (int) $row->is_all_location,
        'id_parent' => $row->id_archive_parent,
        'parent_name' => $parentName,
        'created_by' => $row->created_by,
        'created_at' => $row->created_at,
        'updated_by' => $row->updated_by,
        'updated_at' => $row->updated_at,
        'history_len' => count($history),
        'history' => $history,
        'tail' => $history ? end($history) : null,
        'document' => $doc ? (array) $doc : null,
        'locations' => $locs,
    ];
}

function archivesByName($name, $type = 2)
{
    return db()->table('archives')->where('name', $name)->where('type', $type)->orderBy('created_at')->get()->all();
}

// ------------------------------------------------------------------------------------------------------------- preflight
function preflight($resumeId = null)
{
    $resume = $resumeId ? readJournal($resumeId) : null;
    $resumeShipment = $resume['created']['shipment']['id'] ?? null;
    $now = appNow();
    $u = users();
    $checks = [];
    $blocked = [];
    $warn = [];

    foreach (['i' => 'QA_USER', 'a' => 'QA_USER2', 'w' => 'QA_WAWAN_USER'] as $k => $key) {
        if (!$u[$k]) {
            $blocked[] = ['code' => 'P-0', 'msg' => "{$key} kosong di config/secrets.env"];
        }
    }

    $users = [];
    foreach ($u as $k => $name) {
        $users[$k] = $name ? userRow($name) : null;
        if ($name && !$users[$k]) {
            $blocked[] = ['code' => 'P-0', 'msg' => "user {$k} ({$name}) tidak ada di DB"];
        }
    }
    $checks['users'] = $users;

    // P-1 role a hanya 5, tanpa preset QA2x-ROLE-* (ED-1027/1028 menaruh QA_USER2 di preset sementara)
    $qaRoles = db()->table('roles')->where('role_name', 'like', 'QA2%-ROLE-%')->count();
    $checks['qa_role_presets'] = $qaRoles;
    if ($users['a']) {
        $ids = array_column($users['a']['roles'], 'id_role');
        if ($ids !== [ROLE_A]) {
            $blocked[] = ['code' => 'P-1', 'msg' => 'role user a bukan hanya ' . ROLE_A . ': ' . json_encode($users['a']['roles'])];
        }
    }
    if ($qaRoles > 0) {
        $blocked[] = ['code' => 'P-1', 'msg' => "masih ada {$qaRoles} role preset QA2x-ROLE-* (sisa fixture e2e lain)"];
    }
    if ($users['i'] && array_column($users['i']['roles'], 'id_role') !== [ROLE_I]) {
        $warn[] = 'role user i bukan hanya ' . ROLE_I . ': ' . json_encode($users['i']['roles']);
    }
    if ($users['w'] && array_column($users['w']['roles'], 'id_role') !== [ROLE_W]) {
        $warn[] = 'role user w bukan hanya ' . ROLE_W . ': ' . json_encode($users['w']['roles']);
    }
    if ($users['w'] && $users['w']['employee_type'] !== TYPE_DRIVER) {
        $blocked[] = ['code' => 'P-1', 'msg' => 'user w bukan karyawan bertipe driver (employee_type ' . TYPE_DRIVER . ')'];
    }
    if ($users['a'] && $users['a']['employee_type'] !== TYPE_SALESMAN) {
        $blocked[] = ['code' => 'P-1', 'msg' => 'user a bukan karyawan bertipe salesman (employee_type ' . TYPE_SALESMAN . ')'];
    }

    // P-3 customer + lokasi
    $loc = db()->table('locations')->where('location_name', LOCATION_NAME)->where('is_active', 1)->first(['id_location', 'location_code', 'location_name']);
    $cust = db()->table('customers as c')->leftJoin('locations as l', 'l.id_location', '=', 'c.id_location')
        ->where('c.customer_code', CUSTOMER_CODE)
        ->first(['c.id_customer', 'c.customer_code', 'c.customer_name', 'c.id_location', 'l.location_name', 'c.is_active', 'c.approval_status', 'c.id_related_employee']);
    $addr = $cust ? db()->table('customer_addresses')->where('id_customer', $cust->id_customer)->where('is_active', 1)->whereNotNull('geotag')
        ->orderBy('created_at')->first(['id_customer_address', 'customer_address_name', 'geotag']) : null;
    $checks['location'] = $loc;
    $checks['customer'] = $cust ? (array) $cust + ['address' => $addr ? (array) $addr : null] : null;
    if (!$loc) {
        $blocked[] = ['code' => 'P-3', 'msg' => 'lokasi ' . LOCATION_NAME . ' tidak ada/aktif'];
    }
    if (!$cust || (int) $cust->is_active !== 1) {
        $blocked[] = ['code' => 'P-3', 'msg' => 'customer ' . CUSTOMER_CODE . ' tidak ada/aktif'];
    } elseif ($loc && $cust->id_location !== $loc->id_location) {
        $blocked[] = ['code' => 'P-3', 'msg' => 'customer ' . CUSTOMER_CODE . ' bukan di lokasi ' . LOCATION_NAME];
    }
    if ($cust && $users['a'] && $cust->id_related_employee !== $users['a']['id_employee']) {
        $warn[] = 'id_related_employee customer bukan karyawan a (select customer salesman bisa berbeda)';
    }
    if ($cust && !$addr) {
        $blocked[] = ['code' => 'P-3', 'msg' => 'customer tanpa alamat bergeotag (geolokasi check-in = geotag customer)'];
    }

    // P-4 carrier Semarang + item ber-stok di gudang Semarang (qty 1)
    $carriers = [];
    $item = null;
    $warehouse = null;
    if ($loc) {
        $carriers = db()->table('carriers as c')
            ->where('c.is_active', 1)
            ->where(function ($q) use ($loc) {
                $q->where('c.is_all_location', 1)->orWhereExists(function ($s) use ($loc) {
                    $s->from('carrier_locations as cl')->whereColumn('cl.id_carrier', 'c.id_carrier')->where('cl.id_location', $loc->id_location);
                });
            })
            ->orderBy('c.police_number')
            ->get(['c.id_carrier', 'c.merk', 'c.type', 'c.police_number', 'c.weight', 'c.volume'])->all();
        // dipakai hari ini (bukan LOADED/FINISH/CANCELED) = tidak tampil di select Shipment (SelectShipmentService.php:32-36)
        foreach ($carriers as $c) {
            $c->busy = db()->table('carrier_transactions')->where('id_carrier', $c->id_carrier)->whereNotIn('status', [1, 5, 6])
                ->when($resumeShipment, function ($q) use ($resumeShipment) {
                    $q->where(function ($w) use ($resumeShipment) {
                        $w->whereNull('id_shipment')->orWhere('id_shipment', '!=', $resumeShipment);
                    });
                })->count();
            $c->own_run = $resumeShipment ? db()->table('carrier_transactions')->where('id_carrier', $c->id_carrier)->where('id_shipment', $resumeShipment)->count() : 0;
        }
        $warehouse = db()->table('warehouses')->where('id_location', $loc->id_location)->where('is_active', 1)->orderByDesc('is_default')
            ->first(['id_warehouse', 'warehouse_name']);
        if ($warehouse) {
            $rows = db()->select(
                "SELECT x.id_item, i.item_code, i.item_name, getEndingStock(NOW(), x.id_item, ?, ?, 1, NULL) + 1 AS stock
                   FROM (SELECT DISTINCT id_item FROM inventory_transactions WHERE id_warehouse = ? AND is_active = 1) x
                   JOIN items i ON i.id_item = x.id_item AND i.is_active = 1
                   LEFT JOIN item_batch_serial_configs b ON b.id_item = i.id_item
                  WHERE COALESCE(b.batch_number, 0) = 0 AND COALESCE(b.serial_number, 0) = 0
                  ORDER BY stock DESC LIMIT 3",
                [$loc->id_location, $warehouse->id_warehouse, $warehouse->id_warehouse]
            );
            $item = $rows[0] ?? null;
            $checks['items'] = $rows;
        }
    }
    $free = array_values(array_filter($carriers, function ($c) {
        return $c->busy === 0;
    }));
    if ($resumeShipment && !$free) {
        $free = array_values(array_filter($carriers, function ($c) {
            return $c->own_run > 0;
        }));
    }
    $checks['carriers'] = $carriers;
    $checks['warehouse'] = $warehouse;
    $checks['item'] = $item;
    if (!$free) {
        $blocked[] = ['code' => 'P-4', 'msg' => 'tidak ada carrier aktif di ' . LOCATION_NAME . ' yang tersedia (semua sedang dipakai)'];
    }
    if (!$item || (float) $item->stock < 1) {
        $blocked[] = ['code' => 'P-4', 'msg' => 'tidak ada item non-batch/serial ber-stok >= 1 di gudang ' . LOCATION_NAME];
    }

    // P-5 antrian hidup: worker supervisor + tabel jobs tidak tersangkut
    $ps = (string) @shell_exec('ps -eo args 2>/dev/null');
    $worker = (bool) preg_match('/artisan queue:(listen|work)/', $ps);
    $jobs = db()->table('jobs')->count();
    $oldJobs = db()->table('jobs')->where('created_at', '<', time() - 600)->count();
    $stuck = db()->table('transaction_queues')->whereIn('status', [1, 4])->get(['id_transaction_queue', 'transaction_no', 'transaction_type', 'status', 'id_location', 'updated_at'])->all();
    $checks['queue'] = ['worker_process' => $worker, 'jobs' => $jobs, 'jobs_older_10min' => $oldJobs, 'queue_rows_in_queue_or_processing' => $stuck];
    if (!$worker) {
        $blocked[] = ['code' => 'P-5', 'msg' => 'proses worker antrian (artisan queue:listen/work) tidak terlihat'];
    }
    if ($oldJobs > 0) {
        $blocked[] = ['code' => 'P-5', 'msg' => "{$oldJobs} baris `jobs` lebih tua dari 10 menit (antrian tersangkut: QueueService hanya dispatch saat jobs kosong)"];
    }

    // P-2 / K-6 attendance terbuka w/a: yang tercatat di jurnal run sebelumnya boleh di-check-out lewat UI; selain itu BLOCKED
    $recorded = [];
    foreach (allJournals() as $rid => $j) {
        foreach (($j['created']['attendance'] ?? []) as $who => $att) {
            if (!empty($att['id_attendance'])) {
                $recorded[$att['id_attendance']] = ['run' => $rid, 'who' => $who];
            }
        }
    }
    $open = [];
    foreach (['w', 'a'] as $k) {
        if (!$users[$k] || !$users[$k]['id_employee']) {
            continue;
        }
        foreach (db()->table('attendances')->where('id_employee', $users[$k]['id_employee'])->whereNull('check_out')
            ->get(['id_attendance', 'id_customer', 'check_in', 'created_by']) as $row) {
            $open[] = ['who' => $k, 'id_attendance' => $row->id_attendance, 'check_in' => $row->check_in, 'id_customer' => $row->id_customer,
                'journal' => $recorded[$row->id_attendance] ?? null];
        }
    }
    $checks['attendance_open'] = $open;
    foreach ($open as $o) {
        if (!$o['journal'] && !($resume && in_array($o['id_attendance'], array_column($resume['created']['attendance'] ?? [], 'id_attendance'), true))) {
            $blocked[] = ['code' => 'P-2', 'msg' => "attendance terbuka {$o['who']} ({$o['id_attendance']}, check-in {$o['check_in']}) tidak tercatat di jurnal run sebelumnya (K-6: tidak disentuh)"];
        }
    }

    // P-6..P-9 (spec r2 AC-1, BR-13): lisensi; i di level TERAKHIR CUSTOMER dan SALES_ORDER, level bawah is_mandatory=0,
    // user_need=1; role 3 punya `Allow Approval As Customer`; app_configs.use_approval_pin=0 (R1-K3 c). Semua baca saja.
    Laravel::boot();
    $license = new \Modules\V5\Entities\Helper\LicenseHelper;
    $approvalFeature = (bool) $license->featureLicenseByModule('APPROVAL_TRANSACTION');
    $salesmanFeature = (bool) $license->featureLicenseByModule('SALESMAN_ACTIVITY');
    if (!$salesmanFeature) {
        $blocked[] = ['code' => 'P-6', 'msg' => 'lisensi tanpa SALESMAN_ACTIVITY: modul Archive tidak tersedia (knowledge/license-features.md)'];
    }
    if (!$approvalFeature) {
        $blocked[] = ['code' => 'P-6', 'msg' => 'lisensi tanpa APPROVAL_TRANSACTION: alur approval BR-13 (A.0/A.1) tidak berlaku, spec r2 tidak cocok dengan lingkungan'];
    }
    $iId = $users['i']['id_user'] ?? null;
    $levelsOf = function ($module) use ($iId) {
        $cfg = db()->table('approval_configs')->where('module', $module)->first(['id_approval_config', 'number_level', 'generate_transaction_no']);
        if (!$cfg) {
            return null;
        }
        $rows = db()->table('approval_level_configs')->where('id_approval_config', $cfg->id_approval_config)->orderBy('level')
            ->get(['level', 'is_mandatory', 'user_need', 'can_perform', 'use_password', 'id_user'])->all();
        $levels = [];
        $first = null;
        foreach ($rows as $r) {
            $ids = json_decode($r->id_user, true) ?: [];
            $has = $iId && in_array($iId, $ids, true);
            if ($has && $first === null) {
                $first = (int) $r->level;
            }
            $levels[] = ['level' => (int) $r->level, 'is_mandatory' => (int) $r->is_mandatory, 'user_need' => (int) $r->user_need,
                'can_perform' => (int) $r->can_perform, 'use_password' => (int) $r->use_password, 'i_member' => $has,
                'approvers' => db()->table('users')->whereIn('id_user', $ids)->orderBy('username')->pluck('username')->all()];
        }

        return ['number_level' => (int) $cfg->number_level, 'generate_transaction_no' => (int) $cfg->generate_transaction_no,
            'count_levels' => count($levels), 'i_first_level' => $first, 'levels' => $levels];
    };
    $approval = ['CUSTOMER' => $levelsOf('CUSTOMER'), 'SALES_ORDER' => $levelsOf('SALES_ORDER')];
    foreach ($approval as $module => $cfg) {
        if (!$approvalFeature) {
            break;
        }
        if (!$cfg || !$cfg['count_levels']) {
            $blocked[] = ['code' => 'P-7', 'msg' => "approval {$module} tidak dikonfigurasi (spec r2 mengharapkan i di level terakhir)"];
            continue;
        }
        $last = $cfg['levels'][$cfg['count_levels'] - 1];
        // ApprovalService::approvalProcess: level approver = level PERTAMA yang memuat user; "terakhir" = level == count(levels)
        if ($cfg['i_first_level'] !== $last['level']) {
            $blocked[] = ['code' => 'P-7', 'msg' => "{$u['i']} bukan approver level terakhir {$module} (level pertama yang memuat i: " . json_encode($cfg['i_first_level']) . ", terakhir: {$last['level']})"];
        }
        if ($last['user_need'] !== 1) {
            $blocked[] = ['code' => 'P-7', 'msg' => "{$module} level terakhir user_need={$last['user_need']} (spec: 1)"];
        }
        if ($last['can_perform'] !== 1) {
            $blocked[] = ['code' => 'P-7', 'msg' => "{$module} level terakhir can_perform={$last['can_perform']}"];
        }
        foreach (array_slice($cfg['levels'], 0, -1) as $lv) {
            if ($lv['is_mandatory'] !== 0) {
                $blocked[] = ['code' => 'P-7', 'msg' => "{$module} level {$lv['level']} is_mandatory=1 (spec: level bawah tidak wajib)"];
            }
        }
    }
    $perm = db()->table('role_permissions as rp')->join('permissions as p', 'p.id_permission', '=', 'rp.id_permission')
        ->where('rp.id_role', ROLE_I)->whereIn('p.permission_name', ['Allow Approval As Customer', 'Approve Customer', 'Confirm Sales Order'])
        ->pluck('p.permission_name')->all();
    if (!in_array('Allow Approval As Customer', $perm, true)) {
        $blocked[] = ['code' => 'P-8', 'msg' => 'role ' . ROLE_I . ' tanpa permission `Allow Approval As Customer`'];
    }
    foreach (['Approve Customer', 'Confirm Sales Order'] as $need) {
        if (!in_array($need, $perm, true)) {
            $blocked[] = ['code' => 'P-8', 'msg' => 'role ' . ROLE_I . " tanpa permission `{$need}` (FE menyembunyikan approval)"];
        }
    }
    $pin = db()->table('app_configs')->where('app_key', 'use_approval_pin')->first(['app_value', 'updated_at']);
    if (!$pin || (string) $pin->app_value !== '0') {
        $blocked[] = ['code' => 'P-9', 'msg' => 'app_configs.use_approval_pin = ' . json_encode($pin->app_value ?? null) . ' (R1-K3 c: harus 0); tes tidak memakai PIN'];
    }
    if ($users['i'] && !$users['i']['id_employee']) {
        $blocked[] = ['code' => 'P-8', 'msg' => 'user i tanpa baris employees (tombol persetujuan customer SO butuh employee, SalesmanActivity/SalesOrderService.php:189-190)'];
    }
    // A.0 dibutuhkan bila approval_status customer 0/1; 2 = lewati; 3 (ditolak) = BLOCKED
    $custApproval = $cust ? (int) $cust->approval_status : null;
    $needA0 = $approvalFeature && $cust && in_array($custApproval, [0, 1], true);
    if ($cust && $approvalFeature && !in_array($custApproval, [0, 1, 2], true)) {
        $blocked[] = ['code' => 'P-3', 'msg' => CUSTOMER_CODE . " approval_status={$custApproval} (ditolak): A.0 tidak bisa menyetujui"];
    }
    $checks['approval'] = [
        'license_APPROVAL_TRANSACTION' => $approvalFeature,
        'license_SALESMAN_ACTIVITY' => $salesmanFeature,
        'customer_approval_status' => $custApproval,
        'customer_approval_transactions' => $cust ? db()->table('approval_transactions')->where('id_transaction', $cust->id_customer)
            ->orderBy('created_at')->get(['id_user', 'status', 'level', 'revision', 'created_at'])->map(function ($r) {
                return (array) $r;
            })->all() : [],
        'need_a0' => $needA0,
        'configs' => $approval,
        'role_i_permissions' => $perm,
        'use_approval_pin' => $pin->app_value ?? null,
        'use_approval_pin_updated_at' => $pin->updated_at ?? null,
        'customer_so_count_ever' => $cust ? db()->table('sales_orders')->where('id_customer', $cust->id_customer)->count() : null,
    ];

    // baseline arsip (AC-15) dan jurnal lama (AC-16)
    $checks['archives_baseline'] = [
        'count' => db()->table('archives')->count(),
        'max_updated_at' => db()->table('archives')->max('updated_at'),
        'max_created_at' => db()->table('archives')->max('created_at'),
    ];
    $checks['journals'] = array_map(function ($j) {
        return ['status' => $j['status'] ?? null, 'blocked' => $j['blocked'] ?? null];
    }, allJournals());

    out([
        'ok' => empty($blocked),
        'now' => $now,
        'blocked' => $blocked,
        'warn' => $warn,
        'checks' => $checks,
        'db' => Config::defaultDb(),
    ]);
}

/** baris approval_transactions satu transaksi + username approver */
function approvalRows($idTransaction)
{
    return db()->table('approval_transactions as at')->leftJoin('users as u', 'u.id_user', '=', 'at.id_user')
        ->where('at.id_transaction', $idTransaction)->orderBy('at.created_at')->orderBy('at.id_approval_transaction')
        ->get(['at.id_approval_transaction', 'u.username', 'at.transaction_type', 'at.status', 'at.level', 'at.revision', 'at.created_at'])
        ->map(function ($r) {
            return (array) $r;
        })->all();
}

// ------------------------------------------------------------------------------------------------------------- inspect
function chain($runId)
{
    $j = readJournal($runId);
    $u = users();
    $since = $j['started_app'] ?? null;
    if (!$since) {
        throw new \RuntimeException('jurnal tanpa started_app');
    }
    $cust = db()->table('customers')->where('customer_code', CUSTOMER_CODE)->first(['id_customer']);
    $created = $j['created'] ?? [];

    // SO: dari jurnal, atau SO terbaru buatan i untuk customer sejak run mulai
    $soQ = db()->table('sales_orders');
    if (!empty($created['so']['id'])) {
        $soQ->where('id_sales_order', $created['so']['id']);
    } else {
        $soQ->where('id_customer', $cust->id_customer ?? '-')->where('created_by', $u['i'])->where('created_at', '>=', $since)->orderByDesc('created_at');
    }
    $so = $soQ->first(['id_sales_order', 'sales_order_no', 'transaction_status', 'approval_status', 'approval_level', 'approval_revision', 'customer_approval_status', 'last_approved_by', 'id_salesman', 'id_location', 'created_by', 'created_at', 'updated_by', 'ordered_by']);

    $custRow = $cust ? db()->table('customers')->where('id_customer', $cust->id_customer)->first(['approval_status', 'approval_level', 'last_approved_by', 'last_approved_at']) : null;
    $res = ['run' => $runId, 'since' => $since, 'now' => appNow(), 'so' => $so ? (array) $so : null,
        'customer' => $custRow ? (array) $custRow + ['approval_transactions' => approvalRows($cust->id_customer)] : null];
    if (!$so) {
        return $res;
    }
    $res['so']['approval_transactions'] = approvalRows($so->id_sales_order);
    $res['so']['salesman_username'] = db()->table('employees as e')->join('users as u', 'u.id_user', '=', 'e.id_user')
        ->where('e.id_employee', $so->id_salesman)->value('u.username');

    $st = db()->table('shipment_transactions')->where('id_transaction', $so->id_sales_order)->orderByDesc('created_at')->first(['id_shipment']);
    $ship = $st ? db()->table('shipments')->where('id_shipment', $st->id_shipment)
        ->first(['id_shipment', 'shipment_no', 'status', 'id_carrier', 'id_employee', 'id_location', 'created_by', 'confirmed_by', 'accepted_by', 'updated_by']) : null;
    if ($ship) {
        $ship = (array) $ship;
        $ship['driver_username'] = db()->table('employees as e')->join('users as u', 'u.id_user', '=', 'e.id_user')
            ->where('e.id_employee', $ship['id_employee'])->value('u.username');
        $ship['carrier'] = db()->table('carriers')->where('id_carrier', $ship['id_carrier'])->value('police_number');
        $ship['carrier_transactions'] = db()->table('carrier_transactions')->where('id_shipment', $ship['id_shipment'])
            ->get(['id_transaction', 'transaction_no', 'transaction_type', 'status', 'id_shipment_acceptance'])->map(function ($r) {
                return (array) $r;
            })->all();
        $ship['can_done'] = !empty($ship['carrier_transactions'])
            && !array_filter($ship['carrier_transactions'], function ($c) {
                return !in_array((int) $c['status'], [7, 5], true);
            })
            && (int) $ship['status'] !== 5;
    }
    $res['shipment'] = $ship;

    $dos = db()->table('delivery_orders')->where('id_sales_order', $so->id_sales_order)->orderBy('created_at')
        ->get(['id_delivery_order', 'delivery_order_no', 'status', 'id_salesman', 'created_by', 'confirmed_by'])->map(function ($r) {
            return (array) $r;
        })->all();
    $res['delivery_orders'] = $dos;
    $do = $dos[0] ?? null;

    // arsip SO (BR-3: confirm SO tidak membuat arsip; print tidak dilakukan)
    $res['archives_so'] = array_map(function ($r) {
        return ['id_archive' => $r->id_archive, 'status' => (int) $r->status, 'is_active' => (int) $r->is_active];
    }, archivesByName($so->sales_order_no ?? '-'));

    if ($do) {
        $rows = archivesByName($do['delivery_order_no']);
        $res['archives_do_count'] = count($rows);
        $res['archive_do'] = archiveRow($rows[0] ?? null);

        $saIds = db()->table('shipment_acceptance_transactions')->where('id_transaction', $do['id_delivery_order'])->pluck('id_shipment_acceptance')->unique()->all();
        $res['shipment_acceptances'] = db()->table('shipment_acceptances')->whereIn('id_shipment_acceptance', $saIds ?: ['-'])
            ->get(['id_shipment_acceptance', 'shipment_acceptance_no', 'status', 'acceptance_status', 'is_pay', 'created_by', 'confirmed_by', 'last_approved_by'])
            ->map(function ($r) {
                return (array) $r;
            })->all();

        $siIds = db()->table('sales_invoice_details')->where('id_delivery_order', $do['id_delivery_order'])->pluck('id_sales_invoice')->unique()->all();
        $sis = db()->table('sales_invoices')->whereIn('id_sales_invoice', $siIds ?: ['-'])->orderBy('created_at')
            ->get(['id_sales_invoice', 'sales_invoice_no', 'status', 'payment_status', 'id_salesman', 'salesman_name', 'id_location', 'created_by', 'transaction_date'])
            ->map(function ($r) {
                return (array) $r;
            })->all();
        $res['sales_invoices'] = $sis;
        $si = $sis[0] ?? null;
        if ($si) {
            $rows = archivesByName($si['sales_invoice_no'] ?? '-');
            $res['archives_si_count'] = count($rows);
            $res['archive_si'] = archiveRow($rows[0] ?? null);

            $bIds = db()->table('billing_transactions')->where('id_transaction', $si['id_sales_invoice'])->pluck('id_billing')->unique()->all();
            $res['billings'] = db()->table('billings')->whereIn('id_billing', $bIds ?: ['-'])->orderBy('created_at')
                ->get(['id_billing', 'billing_no', 'status', 'id_employee', 'created_by', 'confirmed_by', 'accepted_by', 'completed_by', 'updated_by'])
                ->map(function ($r) {
                    $r = (array) $r;
                    $r['transactions'] = db()->table('billing_transactions')->where('id_billing', $r['id_billing'])
                        ->get(['id_transaction', 'transaction_no', 'is_match', 'payment_status'])->map(function ($t) {
                            return (array) $t;
                        })->all();

                    return $r;
                })->all();

            $rpIds = db()->table('receivable_payment_details')->where('id_sales_invoice', $si['id_sales_invoice'])->pluck('id_receivable_payment')->unique()->all();
            $res['receivable_payments'] = db()->table('receivable_payments')->whereIn('id_receivable_payment', $rpIds ?: ['-'])
                ->get(['id_receivable_payment', 'receivable_payment_no', 'status', 'customer_approval_status', 'id_salesman', 'created_by', 'processed_by', 'last_approved_by'])
                ->map(function ($r) {
                    return (array) $r;
                })->all();
        }
    }

    // SA tidak boleh menghasilkan Sales Return / SI (BR-8): hitung yang dibuat sejak run mulai untuk customer ini
    $res['sales_returns_since'] = db()->table('sales_returns')->where('id_customer', $cust->id_customer ?? '-')->where('created_at', '>=', $since)->count();
    $res['sales_invoices_since_not_i'] = db()->table('sales_invoices')->where('id_customer', $cust->id_customer ?? '-')->where('created_at', '>=', $since)
        ->where('created_by', '!=', $u['i'])->count();

    // attendance w/a sejak run mulai
    $att = [];
    foreach (['w', 'a'] as $k) {
        $emp = db()->table('employees as e')->join('users as us', 'us.id_user', '=', 'e.id_user')->where('us.username', $u[$k])->value('e.id_employee');
        $att[$k] = db()->table('attendances')->where('id_employee', $emp ?: '-')->where('created_at', '>=', $since)->orderBy('created_at')
            ->get(['id_attendance', 'id_customer', 'store_status', 'check_in', 'check_out', 'geotag_check_in', 'created_by'])->map(function ($r) {
                return (array) $r;
            })->all();
    }
    $res['attendance'] = $att;

    // folder F (K-5) + isinya
    $folderName = $j['folder']['name'] ?? null;
    if ($folderName) {
        $f = db()->table('archives')->where('name', $folderName)->where('type', 1)->orderByDesc('created_at')->first();
        $res['folder'] = archiveRow($f);
        $res['folder_children'] = $f ? db()->table('archives')->where('id_archive_parent', $f->id_archive)->where('is_active', '>', 0)
            ->orderBy('name')->pluck('name')->all() : [];
    }

    return $res;
}

function queueEvidence($runId)
{
    $c = chain($runId);
    $ids = array_filter([
        $c['so']['id_sales_order'] ?? null,
        $c['shipment']['id_shipment'] ?? null,
        $c['sales_invoices'][0]['id_sales_invoice'] ?? null,
    ]);
    $rows = db()->table('transaction_queues')->whereIn('id_transaction', $ids ?: ['-'])
        ->get(['id_transaction_queue', 'id_transaction', 'transaction_no', 'transaction_type', 'status', 'queue_status', 'url_data', 'created_by', 'processed_by', 'created_at', 'updated_at'])
        ->map(function ($r) {
            return (array) $r;
        })->all();
    $details = [];
    foreach ($rows as $r) {
        $details[$r['id_transaction_queue']] = db()->table('transaction_queue_details')->where('id_transaction_queue', $r['id_transaction_queue'])
            ->limit(5)->get()->map(function ($d) {
                return (array) $d;
            })->all();
    }
    $ps = (string) @shell_exec('ps -eo pid,etime,args 2>/dev/null | grep -E "artisan queue:(listen|work)" | grep -v grep');
    out([
        'run' => $runId,
        'now' => appNow(),
        'transaction_queues' => $rows,
        'transaction_queue_details' => $details,
        'jobs' => db()->table('jobs')->count(),
        'jobs_rows' => db()->table('jobs')->limit(5)->get(['id', 'queue', 'attempts', 'reserved_at', 'available_at', 'created_at'])->map(function ($r) {
            return (array) $r;
        })->all(),
        'worker_processes' => array_values(array_filter(array_map('trim', explode("\n", $ps)))),
    ]);
}

function changed($runId)
{
    $j = readJournal($runId);
    $since = $j['started_app'];
    $rows = db()->table('archives')->where(function ($q) use ($since) {
        $q->where('updated_at', '>=', $since)->orWhere('created_at', '>=', $since);
    })->orderBy('updated_at')->get(['id_archive', 'name', 'type', 'status', 'is_active', 'created_by', 'updated_by', 'created_at', 'updated_at']);
    out(['run' => $runId, 'since' => $since, 'now' => appNow(), 'rows' => $rows->map(function ($r) {
        return (array) $r;
    })->all()]);
}

/** AC-16: keadaan arsip DO/SI/F dan Billing run-run sebelumnya yang lengkap (status complete) */
function previous($runId)
{
    $out = [];
    foreach (allJournals($runId) as $rid => $j) {
        if (($j['status'] ?? null) !== 'complete') {
            continue;
        }
        $docs = [];
        foreach (['do', 'si'] as $k) {
            $name = $j['created'][$k]['no'] ?? null;
            $rows = $name ? archivesByName($name) : [];
            $r = archiveRow($rows[0] ?? null);
            $docs[$k] = $r ? ['name' => $r['name'], 'status' => $r['status'], 'is_active' => $r['is_active'], 'updated_by' => $r['updated_by'],
                'updated_at' => $r['updated_at'], 'history_len' => $r['history_len'], 'id_parent' => $r['id_parent']] : null;
        }
        $fname = $j['folder']['name'] ?? null;
        $f = $fname ? db()->table('archives')->where('name', $fname)->where('type', 1)->first(['id_archive', 'name', 'status', 'is_active', 'updated_at']) : null;
        $bno = $j['created']['billing']['no'] ?? null;
        $b = $bno ? db()->table('billings')->where('billing_no', $bno)->first(['billing_no', 'status', 'updated_at', 'updated_by']) : null;
        $out[$rid] = ['docs' => $docs, 'folder' => $f ? (array) $f : null, 'billing' => $b ? (array) $b : null];
    }
    out(['run' => $runId, 'previous' => $out]);
}

try {
    $cmd = $argv[1] ?? '';
    switch ($cmd) {
        case 'preflight':
            preflight($argv[2] ?? null);
            break;
        case 'inspect':
            out(chain($argv[2] ?? ''));
            break;
        case 'queue':
            queueEvidence($argv[2] ?? '');
            break;
        case 'changed':
            changed($argv[2] ?? '');
            break;
        case 'previous':
            previous($argv[2] ?? '');
            break;
        default:
            fwrite(STDERR, "pemakaian: fixture.php preflight | inspect <runId> | queue <runId> | changed <runId> | previous <runId>\n");
            exit(2);
    }
} catch (\Throwable $e) {
    out(['error' => Config::redact(get_class($e) . ': ' . $e->getMessage())], 1);
}
