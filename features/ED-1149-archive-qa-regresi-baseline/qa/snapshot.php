<?php
/**
 * ED-1149 AC-13 - snapshot data developer (BACA SAJA) dan pembanding. BUKAN skenario (nama berkas tidak cocok scenario*.php).
 *
 *   "$PHP_BIN" features/ED-1149-*\/qa/snapshot.php <label>              -> work/ED-1149/snap-<label>.json
 *   "$PHP_BIN" features/ED-1149-*\/qa/snapshot.php --compare <a> <b>    -> hanya perbedaan (exit 0 = sama, 1 = beda)
 *   "$PHP_BIN" features/ED-1149-*\/qa/snapshot.php --leftover           -> hanya sisa QA saat ini (exit 1 = ada sisa)
 *
 * Koneksi: QaHttp\Laravel::db() = sesi MySQL `SET SESSION TRANSACTION READ ONLY` (tulisan apa pun ditolak server) ke QA_DB.
 * Isi snapshot (AC-13): jumlah + CHECKSUM TABLE + hash baris (jumlah CRC32 per baris, tak bergantung urutan) untuk roles,
 * role_permissions (seluruh + per role + himpunan id_permission per role, BR-3), user_roles, permissions, archives (seluruh +
 * hash kolom verifikasi baris non-QA + baris updated_by='sam'), archive_documents, archive_locations, archive_permissions,
 * archive_opnames/_folders/_documents, column_display_settings, updater_logs, employees, employee_locations; users (kolom stabil;
 * login mengubah users.updated_at/last_login/last_activity/is_login -> dikecualikan, itulah beda yang diizinkan); sisa QA
 * (role QA2x-ROLE-%, archives/archive_documents/archive_permissions/role_permissions berpenanda QA); berkas jurnal di features/*\/qa.
 * Tidak ada password/token yang dibaca atau ditulis.
 */

namespace QaHttp;

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../..')));
define('QA_WORK', QA_AGENTIC . '/work/qa-http');
foreach (['Outcome', 'Config', 'Http', 'Laravel', 'Sessions', 'Tester'] as $f) {
    require QA_AGENTIC . '/scripts/qa-http/lib/' . $f . '.php';
}

const SNAP_DIR = QA_AGENTIC . '/work/ED-1149';

function snap_cols($db, $table)
{
    $out = [];
    foreach ($db->select("SHOW COLUMNS FROM `$table`") as $r) {
        $out[] = $r->Field;
    }

    return $out;
}

/** [n, hash] = jumlah baris dan jumlah CRC32 per baris (kolom $cols), opsional WHERE. */
function snap_sum($db, $table, array $cols, $where = '', array $bind = [])
{
    $parts = array_map(function ($c) {
        return "IFNULL(CAST(`$c` AS CHAR),'~')";
    }, $cols);
    $r = $db->selectOne("SELECT COUNT(*) n, IFNULL(SUM(CRC32(CONCAT_WS('|'," . implode(',', $parts) . '))),0) h FROM `' . $table . '` ' . $where, $bind);

    return ['n' => (int) $r->n, 'hash' => (string) $r->h];
}

function snap_checksum($db, $table)
{
    $r = $db->selectOne("CHECKSUM TABLE `$table`");
    $a = array_values((array) $r);

    return (string) end($a);
}

function snap_table($db, $table, array $cols = null, $where = '', array $bind = [], $withChecksum = true)
{
    $cols = $cols ?: snap_cols($db, $table);
    $out = snap_sum($db, $table, $cols, $where, $bind);
    if ($withChecksum && $where === '') {
        $out['checksum'] = snap_checksum($db, $table);
    }

    return $out;
}

/** Baris QA (penanda nama/created_by). Sebuah baris developer yang kebetulan cocok tetap tercatat sama di sebelum & sesudah. */
const SNAP_QA_ARCHIVE = "(name LIKE 'QA%' OR name LIKE '0QA%' OR created_by LIKE 'QA%')";

function snapshot_take($label)
{
    $dbName = Config::defaultDb();
    $db = Laravel::db($dbName);
    $s = ['label' => $label, 'taken_at' => date('c'), 'db' => $dbName, 'tables' => [], 'detail' => [], 'leftover' => []];

    foreach ([
        'roles', 'role_permissions', 'user_roles', 'permissions', 'archives', 'archive_documents', 'archive_locations',
        'archive_permissions', 'archive_opnames', 'archive_opname_folders', 'archive_opname_documents',
        'column_display_settings', 'updater_logs', 'employees', 'employee_locations',
    ] as $table) {
        $s['tables'][$table] = snap_table($db, $table);
    }

    // role_permissions: per role (seluruh baris) + himpunan id_permission per role (BR-3: hak dibaca per id).
    $rpCols = snap_cols($db, 'role_permissions');
    $byRole = [];
    foreach ($db->select('SELECT DISTINCT id_role FROM role_permissions ORDER BY id_role') as $r) {
        $id = (int) $r->id_role;
        $row = snap_sum($db, 'role_permissions', $rpCols, 'WHERE id_role = ?', [$id]);
        $ids = $db->table('role_permissions')->where('id_role', $id)->orderBy('id_permission')->pluck('id_permission')->all();
        $nulls = $db->table('role_permissions')->where('id_role', $id)->whereNull('permission_name')->count();
        $row['permission_ids_md5'] = md5(implode(',', $ids));
        $row['permission_name_null'] = (int) $nulls;
        $byRole[(string) $id] = $row;
    }
    $s['detail']['role_permissions_by_role'] = $byRole;
    $s['detail']['role_permissions_roles_3_5'] = [
        '3' => $byRole['3'] ?? null,
        '5' => $byRole['5'] ?? null,
    ];

    // user_roles per role (jumlah + hash): memperlihatkan user mana pindah role bila ada beda.
    $urCols = snap_cols($db, 'user_roles');
    $s['detail']['user_roles_all'] = snap_sum($db, 'user_roles', $urCols);

    // archives: kolom verifikasi baris non-QA (termasuk baris updated_by='sam') + baris QA tersisa.
    $verifCols = ['id_archive', 'is_verified', 'verified_at', 'verified_by', 'id_archive_opname'];
    $s['detail']['archives_verif_nonqa'] = snap_sum($db, 'archives', $verifCols, 'WHERE NOT ' . SNAP_QA_ARCHIVE);
    $s['detail']['archives_verified_nonqa'] = snap_sum($db, 'archives', $verifCols, 'WHERE NOT ' . SNAP_QA_ARCHIVE . ' AND is_verified = 1');
    $s['detail']['archives_sam_rows'] = snap_sum(
        $db,
        'archives',
        ['id_archive', 'is_verified', 'verified_at', 'verified_by', 'id_archive_opname', 'updated_by', 'updated_at'],
        "WHERE updated_by = 'sam'"
    );
    $s['detail']['archives_sam_verified'] = snap_sum(
        $db,
        'archives',
        ['id_archive', 'is_verified', 'verified_at', 'verified_by', 'id_archive_opname'],
        "WHERE updated_by = 'sam' AND is_verified = 1"
    );
    $s['detail']['archives_nonqa_all'] = snap_sum($db, 'archives', snap_cols($db, 'archives'), 'WHERE NOT ' . SNAP_QA_ARCHIVE);

    // users: kolom stabil saja (tanpa password/token; login mengubah updated_at/last_login/last_activity/is_login -> beda yang diizinkan).
    $stable = ['id_user', 'username', 'is_active', 'is_global', 'language', 'created_by', 'created_at', 'updated_by', 'is_receive_notification'];
    $have = snap_cols($db, 'users');
    $stable = array_values(array_intersect($stable, $have));
    $s['detail']['users_stable'] = snap_sum($db, 'users', $stable);
    $qaUsers = [];
    foreach (['QA_USER', 'QA_USER2'] as $who) {
        $name = Config::get($who);
        if ($name === null) {
            continue;
        }
        $row = $db->table('users')->where('username', $name)->first(['id_user', 'username', 'language', 'is_active', 'is_global', 'updated_at', 'last_login']);
        $uid = $row ? $row->id_user : null;
        $qaUsers[$who] = [
            'id_user'    => $uid,
            'language'   => $row->language ?? null,
            'roles'      => $uid ? $db->table('user_roles')->where('id_user', $uid)->orderBy('id_user_role')->get(['id_user_role', 'id_role', 'is_all_location', 'id_location', 'created_at', 'updated_at'])->map(function ($r) {
                return (array) $r;
            })->all() : [],
            // beda yang diizinkan (informasi saja; TIDAK dibandingkan): login menyentuh baris users
            'updated_at' => $row->updated_at ?? null,
        ];
    }
    $s['detail']['qa_users'] = $qaUsers;

    // Sisa QA (harus 0 sesudah run).
    $lo = [];
    $lo['roles_QA2x_ROLE'] = (int) $db->table('roles')->where('role_name', 'like', 'QA%-ROLE-%')->count();
    $lo['roles_created_by_QA'] = (int) $db->table('roles')->where('created_by', 'like', 'QA%')->count();
    $lo['role_permissions_created_by_QA'] = (int) $db->table('role_permissions')->where('created_by', 'like', 'QA%')->count();
    $lo['role_permissions_orphan_role'] = (int) $db->selectOne('SELECT COUNT(*) c FROM role_permissions rp LEFT JOIN roles r ON r.id_role = rp.id_role WHERE r.id_role IS NULL')->c;
    $lo['user_roles_orphan_role'] = (int) $db->selectOne('SELECT COUNT(*) c FROM user_roles ur LEFT JOIN roles r ON r.id_role = ur.id_role WHERE r.id_role IS NULL')->c;
    $lo['archives_QA'] = (int) $db->selectOne('SELECT COUNT(*) c FROM archives WHERE ' . SNAP_QA_ARCHIVE)->c;
    $lo['archive_documents_QA'] = (int) $db->table('archive_documents')->where(function ($q) {
        $q->where('transaction_no', 'like', 'QA%')->orWhere('transaction_no', 'like', '0QA%')->orWhere('id_transaction', 'like', 'QA%');
    })->count();
    $lo['archive_permissions_created_by_QA'] = (int) $db->table('archive_permissions')->where('created_by', 'like', 'QA%')->count();
    $lo['archive_opnames_created_by_QA'] = (int) $db->table('archive_opnames')->where('created_by', 'like', 'QA%')->count();
    $lo['column_display_settings_created_by_QA'] = (int) $db->table('column_display_settings')->where('created_by', 'like', 'QA%')->count();
    $s['leftover'] = $lo;

    // Berkas jurnal (.restore/.grant/.sessions/.verif/.*-journal) yang tersisa di features/*/qa dan features/*/qa/e2e.
    $journals = [];
    // (ED-1149 tahap fe) termasuk jurnal fixture e2e di features/*/qa/e2e/ (.fixture-journal.json, .grant-journal.json, .state-journal.json)
    foreach (array_merge(glob(QA_AGENTIC . '/features/*/qa/.*journal*') ?: [], glob(QA_AGENTIC . '/features/*/qa/e2e/.*journal*') ?: []) as $f) {
        $journals[str_replace(QA_AGENTIC . '/', '', $f)] = filesize($f);
    }
    ksort($journals);
    $s['journals'] = $journals;

    return $s;
}

/** Ratakan array bersarang ke path => nilai skalar. */
function snap_flat($v, $path = '', array &$out = [])
{
    if (is_array($v)) {
        if ($v === []) {
            $out[$path] = '[]';
        }
        foreach ($v as $k => $x) {
            snap_flat($x, $path === '' ? (string) $k : $path . '.' . $k, $out);
        }
    } else {
        $out[$path] = $v === null ? 'null' : (string) $v;
    }

    return $out;
}

function snapshot_compare($a, $b)
{
    $fa = [];
    $fb = [];
    foreach (['tables', 'detail', 'leftover', 'journals'] as $section) {
        snap_flat($a[$section] ?? [], $section, $fa);
        snap_flat($b[$section] ?? [], $section, $fb);
    }
    $diff = [];
    foreach (array_unique(array_merge(array_keys($fa), array_keys($fb))) as $path) {
        // updated_at QA_USER/QA_USER2 = beda yang diizinkan (login), tidak dibandingkan
        if (preg_match('/^detail\.qa_users\.[^.]+\.updated_at$/', $path)) {
            continue;
        }
        $x = $fa[$path] ?? '(tidak ada)';
        $y = $fb[$path] ?? '(tidak ada)';
        if ($x !== $y) {
            $diff[$path] = [$x, $y];
        }
    }

    return $diff;
}

function snap_load($label)
{
    $file = SNAP_DIR . '/snap-' . $label . '.json';
    if (!is_file($file)) {
        fwrite(STDERR, "snapshot tidak ada: $file\n");
        exit(2);
    }

    return json_decode(file_get_contents($file), true);
}

// ------------------------------------------------------------------ CLI
$args = array_slice($argv, 1);
if (isset($args[0]) && $args[0] === '--compare') {
    if (count($args) !== 3) {
        fwrite(STDERR, "Pakai: snapshot.php --compare <labelA> <labelB>\n");
        exit(2);
    }
    $diff = snapshot_compare(snap_load($args[1]), snap_load($args[2]));
    if (!$diff) {
        echo "SAMA: snap-{$args[1]} == snap-{$args[2]} (beda yang diizinkan, users.updated_at QA_USER/QA_USER2, tidak dihitung)\n";
        exit(0);
    }
    echo count($diff) . " BEDA antara snap-{$args[1]} dan snap-{$args[2]}:\n";
    foreach ($diff as $path => $pair) {
        echo "  $path: {$pair[0]} -> {$pair[1]}\n";
    }
    exit(1);
}

if (isset($args[0]) && $args[0] === '--leftover') {
    $s = snapshot_take('leftover');
    $bad = array_filter($s['leftover']) + $s['journals'];
    echo $bad ? 'SISA: ' . json_encode($bad) . "\n" : "tidak ada sisa QA\n";
    exit($bad ? 1 : 0);
}

$label = $args[0] ?? '';
if (!preg_match('/^[A-Za-z0-9._-]+$/', $label)) {
    fwrite(STDERR, "Pakai: snapshot.php <label> | --compare <a> <b> | --leftover\n");
    exit(2);
}
if (!is_dir(SNAP_DIR)) {
    mkdir(SNAP_DIR, 0775, true);
}
$snap = snapshot_take($label);
file_put_contents(SNAP_DIR . '/snap-' . $label . '.json', json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "snapshot ditulis: work/ED-1149/snap-$label.json (db {$snap['db']}, " . count($snap['tables']) . " tabel)\n";
foreach ($snap['tables'] as $table => $v) {
    printf("  %-26s n=%-7d hash=%-14s checksum=%s\n", $table, $v['n'], $v['hash'], $v['checksum'] ?? '-');
}
echo '  sisa QA: ' . json_encode($snap['leftover']) . "\n";
echo '  berkas jurnal: ' . json_encode($snap['journals']) . "\n";
