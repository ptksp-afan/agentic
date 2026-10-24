<?php
/**
 * Fixture e2e ED-1250 (BUKAN skenario regresi; dipanggil lewat qa/e2e/fixtures.json dan oleh spec). Pola ED-1125/ED-1027 e2e fixture.php.
 *
 *   fixture.php up            sapu sisa "QA50-" lama; catat jurnal + keadaan awal (baris cds documentArchive, user_roles QA_USER2, bahasa QA_USER);
 *                             pasang QA_USER2 di preset QA50-ROLE-E2E-NOOD (List Archive TANPA Opname Document); buat pohon uji
 *   fixture.php down          buang data uji + preset, pulihkan user_roles QA_USER2, bahasa QA_USER, baris cds documentArchive; verifikasi
 *                             (exit 1 bila ada sisa / pemulihan tidak persis); jurnal dihapus hanya bila semuanya bersih
 *   fixture.php ids           peta nama -> id_archive (JSON)
 *   fixture.php prop <nama>   kolom archives satu baris fixture (JSON, baca saja)
 *   fixture.php children <nama|root>  anak langsung (aktif) satu folder fixture / root-QA50: [{name,type,is_verified,status}] (baca saja)
 *   fixture.php vt <nama>     {verified,total} dokumen aktif di bawah folder (rekursif) menurut tabel mentah (baca saja)
 *   fixture.php restore       kembalikan semua baris archives fixture ke keadaan snapshot awal (pindah / status / dsb.)
 *   fixture.php root-counts   {folders,verified,unverified,docs,others}: baris aktif level root menurut tabel mentah (oracle, baca saja)
 *   fixture.php cds           baris cds documentArchive: data_index current/available queries & columns + updated_at (baca saja)
 *   fixture.php cds-restore   pulihkan baris cds documentArchive persis seperti snapshot `up` (hapus lalu masukkan, id sama)
 *   fixture.php lang <ID|EN>  ubah bahasa QA_USER sementara (jurnal); `lang-restore` memulihkan
 *   fixture.php lang-restore
 *   fixture.php has-od        {QA_USER: bool, QA_USER2: bool}: apakah role user memuat permission "Opname Document" (baca saja)
 *
 * Tidak menulis archive_opname*; is_verified/verified_at/verified_by di-set LANGSUNG pada dokumen fixture (Gate 1 K-4 a). Tidak menyentuh
 * baris yang bukan berawalan "QA50-" / tidak tercatat di jurnal. Role developer tidak dibaca sebagai prasyarat, tidak di-grant.
 *
 * Pohon (semua aktif, is_all_location=1, is_folder_permission=0 kecuali PRIV; v = is_verified 1, u = 0, n = NULL):
 *   QA50-A (root)   folder: EMPTY | ALLV(ALLV-1 v6, ALLV-2 v7) | MIX(MIX-V1 v7, MIX-U1 u7, MIX-U2 u6, MIX-SUB(MIX-SUB-U1 u6)) | NOV(NOV-U1 u6, NOV-U2 u7)
 *                   | PRIV (Izin On, QA_USER tanpa View; PRIV-U1 u6)
 *                   dokumen: AV1 v6, AV2 v7, AHO v8 (status 2 Handed Over), AU1 u6, AU2 u7, ATK u6 (status 3 Taken), ANL n8
 *   QA50-B (root)   BSUB (BSUB-U1 u6) | BV01..BV12 (v, tipe 6) | BU01..BU12 (u, tipe 7)        => 13 baris per nilai filter (2 halaman)
 *   QA50-C (root)   DEST (kosong) | MVF (MVF-V v6, MVF-U u7) | MVD-U u6 | MVD-V v7 | D6 u6 (Letakkan / Serahkan / Terima)
 *   dokumen root    QA50-RDV v6, QA50-RDU u7
 */

namespace QaHttp;

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../../..')));

require QA_AGENTIC . '/scripts/qa-http/lib/Outcome.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Config.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Laravel.php';

use Illuminate\Support\Facades\DB;

const PREFIX = 'QA50-';
const ROLE_NAME = 'QA50-ROLE-E2E-NOOD';
const BY = 'QA50';
const DISPLAY_MODULE = 'documentArchive';
const OD = 'Opname Document';
const U2_PRESET = [
    'Allow Access Website', 'List Archive', 'Add Folder', 'Add Document', 'View Folder', 'Update Folder', 'Delete Folder',
    'Move Archive', 'Handover Document', 'Receive Document',
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

function jread($file)
{
    return is_file($file) ? json_decode(file_get_contents($file), true) : null;
}

function jwrite($file, array $j)
{
    if (file_put_contents($file, json_encode($j)) === false) {
        fail('jurnal tidak bisa ditulis: ' . basename($file));
    }
}

function qaUser($c, $key)
{
    $u = $c->table('users')->where('username', Config::get($key))->first();
    if (!$u) {
        fail("$key tidak ada di DB");
    }

    return $u;
}

function cdsRows($c)
{
    return rows($c->table('column_display_settings')->where('module_name', DISPLAY_MODULE)->orderBy('id_column_display_setting')->get());
}

function cdsRestore($c, array $snap)
{
    if (cdsRows($c) === $snap) {
        return false;
    }
    $c->transaction(function () use ($c, $snap) {
        $c->table('column_display_settings')->where('module_name', DISPLAY_MODULE)->delete();
        foreach ($snap as $row) {
            $c->table('column_display_settings')->insert($row);
        }
    });

    return true;
}

function seq($json)
{
    $out = [];
    foreach ((array) json_decode($json, true) as $col) {
        $out[] = is_array($col['data_index']) ? implode('.', $col['data_index']) : $col['data_index'];
    }

    return $out;
}

function sumOf($c, $table, array $cols, $where = '', array $bind = [])
{
    $parts = array_map(function ($col) {
        return "IFNULL(CAST(`$col` AS CHAR),'~')";
    }, $cols);
    $r = $c->selectOne("SELECT COUNT(*) n, IFNULL(SUM(CRC32(CONCAT_WS('|'," . implode(',', $parts) . '))),0) h FROM `' . $table . '` ' . $where, $bind);

    return [(int) $r->n, (string) $r->h];
}

function columnsOf($c, $table)
{
    return array_map(function ($r) {
        return $r->Field;
    }, $c->select("SHOW COLUMNS FROM `$table`"));
}

/** Keadaan di luar baris sendiri (hanya dilaporkan: pengembang boleh mengubah data lain; DU-6 a menjanjikan tidak). */
function devstate($c)
{
    $out = [];
    $like = [PREFIX . '%'];
    $out['archives'] = sumOf($c, 'archives', columnsOf($c, 'archives'), 'WHERE name NOT LIKE ?', $like);
    $out['archive_documents'] = sumOf($c, 'archive_documents', columnsOf($c, 'archive_documents'), 'WHERE IFNULL(transaction_no,"") NOT LIKE ?', $like);
    foreach (['archive_opnames', 'archive_opname_folders', 'archive_opname_documents', 'roles', 'role_permissions', 'user_roles', 'employee_locations', 'column_display_settings'] as $t) {
        $out[$t] = sumOf($c, $t, columnsOf($c, $t));
    }
    $out['users(id,language)'] = sumOf($c, 'users', ['id_user', 'language']);

    return $out;
}

function purgeData($c, array $extraIds = [])
{
    $ids = $c->table('archives')->whereRaw('`name` LIKE BINARY ?', [PREFIX . '%'])->pluck('id_archive')->all();
    $ids = array_values(array_unique(array_merge($ids, $extraIds)));
    foreach (array_chunk($ids, 500) as $chunk) {
        $c->table('archive_permissions')->whereIn('id_archive', $chunk)->delete();
        $c->table('archive_documents')->whereIn('id_archive', $chunk)->delete();
        $c->table('archive_locations')->whereIn('id_archive', $chunk)->delete();
        $c->table('archives')->whereIn('id_archive', $chunk)->delete();
    }
    $c->table('archive_documents')->where('transaction_no', 'like', PREFIX . '%')->delete();

    return count($ids);
}

function purgePreset($c)
{
    $ids = $c->table('roles')->where('role_name', 'like', 'QA50-ROLE-%')->pluck('id_role')->all();
    if ($ids) {
        $c->table('user_roles')->whereIn('id_role', $ids)->delete();
        $c->table('role_permissions')->whereIn('id_role', $ids)->delete();
        $c->table('roles')->whereIn('id_role', $ids)->delete();
    }

    return count($ids);
}

function u2Rows($c)
{
    return rows($c->table('user_roles')->where('id_user', qaUser($c, 'QA_USER2')->id_user)->orderBy('id_user_role')->get());
}

function u2Write($c, $uid, array $rows)
{
    $c->table('user_roles')->where('id_user', $uid)->delete();
    if ($rows) {
        $c->table('user_roles')->insert($rows);
    }
}

// ------------------------------------------------------------------------------------------------------------------ up
if ($cmd === 'up') {
    $old = jread($journal);
    if ($old) { // run sebelumnya mati: pulihkan dulu semuanya dari jurnal lama
        purgeData($c, $old['ids'] ?? []);
        if (!empty($old['u2']['rows'])) {
            u2Write($c, $old['u2']['uid'], $old['u2']['rows']);
        }
        purgePreset($c);
        if (isset($old['lang_orig'])) {
            $c->table('users')->where('id_user', $old['lang_orig']['uid'])->update(['language' => $old['lang_orig']['lang']]);
        }
        if (!empty($old['cds'])) {
            cdsRestore($c, $old['cds']);
        }
        @unlink($journal);
        say('jurnal sisa: keadaan run lama dipulihkan');
    }
    $left = (int) $c->table('archives')->whereRaw('`name` LIKE BINARY ?', [PREFIX . '%'])->count();
    purgeData($c);
    purgePreset($c);
    say("sapu sisa QA50-: $left baris archives lama dibuang");

    $u1 = qaUser($c, 'QA_USER');
    $u2 = qaUser($c, 'QA_USER2');
    $cds = cdsRows($c);
    if (count($cds) !== 1) {
        fail('prasyarat: baris cds documentArchive harus tepat 1, ada ' . count($cds));
    }
    $cq = seq($cds[0]['current_queries']);
    $aq = seq($cds[0]['available_queries']);
    $cc = seq($cds[0]['current_columns']);
    if (!in_array('verificationStatus', $cq, true) || !in_array('verificationStatus', $aq, true) || !in_array('documentVerified', $cc, true)) {
        fail('prasyarat: baris cds documentArchive tanpa verificationStatus / documentVerified (Updater ED-1250 belum jalan?)');
    }

    $j = [
        'ids' => [], 'cds' => $cds, 'u2' => ['uid' => $u2->id_user, 'rows' => u2Rows($c)],
        'lang_qa_user' => ['uid' => $u1->id_user, 'lang' => $u1->language], 'before' => devstate($c), 'snap' => [],
    ];
    jwrite($journal, $j);

    // QA_USER harus memegang Opname Document lewat role sistem 3 (bukan role developer lain); dilaporkan, bukan diubah
    $odOf = function ($uid) use ($c) {
        return (int) $c->table('user_roles as ur')->join('role_permissions as rp', 'rp.id_role', '=', 'ur.id_role')
            ->where('ur.id_user', $uid)->where('rp.permission_name', OD)->count() > 0;
    };
    if (!$odOf($u1->id_user)) {
        fail('prasyarat: QA_USER tidak memegang Opname Document (role sistem 3 harusnya memuatnya)');
    }

    // preset QA_USER2: List Archive TANPA Opname Document
    $now = date('Y-m-d H:i:s');
    $rid = $c->table('roles')->insertGetId([
        'role_name' => ROLE_NAME, 'description' => 'QA ED-1250 e2e preset', 'is_superadmin' => 4, 'is_active' => 1, 'is_used' => 1,
        'created_by' => BY, 'created_at' => $now, 'updated_by' => BY, 'updated_at' => $now,
    ]);
    $j['preset'] = [(int) $rid];
    jwrite($journal, $j);
    foreach (U2_PRESET as $name) {
        $p = $c->table('permissions')->where('permission_name', $name)->first();
        if (!$p) {
            fail("prasyarat: permission '$name' tidak ada di DB uji");
        }
        $c->table('role_permissions')->insert([
            'id_role' => $rid, 'id_permission' => $p->id_permission, 'permission_name' => $p->permission_name,
            'created_at' => $now, 'updated_at' => $now, 'created_by' => BY, 'updated_by' => BY,
        ]);
    }
    u2Write($c, $u2->id_user, [[
        'id_user' => $u2->id_user, 'id_role' => $rid, 'is_all_location' => 1, 'id_location' => null, 'created_at' => $now, 'updated_at' => $now,
    ]]);

    // pohon uji
    $gen = function () {
        return \Modules\V5\Entities\Helper\MyHelper::generateId();
    };
    $ids = [];
    $created = [];
    $add = function ($label, $type, $parent, array $o = []) use ($c, $gen, $now, &$ids, &$created, $journal) {
        $name = PREFIX . $label;
        $id = $gen();
        $row = [
            'id_archive' => $id, 'id_archive_parent' => $parent, 'name' => $name, 'type' => $type, 'status' => $o['status'] ?? 1,
            'is_all_location' => 1, 'is_folder_permission' => $o['perm'] ?? 0, 'is_active' => 1,
            'created_at' => $now, 'created_by' => BY, 'updated_at' => $now, 'updated_by' => BY,
        ];
        if (!empty($o['v'])) {
            $row['is_verified'] = 1;
            $row['verified_at'] = $now;
            $row['verified_by'] = BY;
        } elseif (array_key_exists('iv', $o)) {
            $row['is_verified'] = $o['iv'];
        }
        $jj = jread($journal);
        $jj['ids'][] = $id; // dicatat SEBELUM baris ditulis
        jwrite($journal, $jj);
        $c->table('archives')->insert($row);
        if ($type == 2) {
            $c->table('archive_documents')->insert([
                'id_archive_document' => $gen(), 'id_archive' => $id, 'id_transaction' => 'QA50TX' . substr($id, -10),
                'transaction_no' => $name, 'transaction_type' => $o['tx'] ?? 6, 'related_employee_name' => 'QA50 Sales',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $ids[$name] = $id;
        $created[] = $id;

        return $id;
    };

    $a = $add('A', 1, null);
    $add('EMPTY', 1, $a);
    $allv = $add('ALLV', 1, $a);
    $add('ALLV-1', 2, $allv, ['v' => 1, 'tx' => 6]);
    $add('ALLV-2', 2, $allv, ['v' => 1, 'tx' => 7]);
    $mix = $add('MIX', 1, $a);
    $add('MIX-V1', 2, $mix, ['v' => 1, 'tx' => 7]);
    $add('MIX-U1', 2, $mix, ['tx' => 7]);
    $add('MIX-U2', 2, $mix, ['tx' => 6]);
    $mixsub = $add('MIX-SUB', 1, $mix);
    $add('MIX-SUB-U1', 2, $mixsub, ['tx' => 6]);
    $nov = $add('NOV', 1, $a);
    $add('NOV-U1', 2, $nov, ['tx' => 6]);
    $add('NOV-U2', 2, $nov, ['tx' => 7]);
    $priv = $add('PRIV', 1, $a, ['perm' => 1]);
    $add('PRIV-U1', 2, $priv, ['tx' => 6]);
    $add('AV1', 2, $a, ['v' => 1, 'tx' => 6]);
    $add('AV2', 2, $a, ['v' => 1, 'tx' => 7]);
    $add('AHO', 2, $a, ['v' => 1, 'tx' => 8, 'status' => 2]);
    $add('AU1', 2, $a, ['tx' => 6]);
    $add('AU2', 2, $a, ['tx' => 7]);
    $add('ATK', 2, $a, ['tx' => 6, 'status' => 3]);
    $add('ANL', 2, $a, ['tx' => 8, 'iv' => null]);

    $b = $add('B', 1, null);
    $bsub = $add('BSUB', 1, $b);
    $add('BSUB-U1', 2, $bsub, ['tx' => 6]);
    for ($i = 1; $i <= 12; $i++) {
        $n = sprintf('%02d', $i);
        $add("BV$n", 2, $b, ['v' => 1, 'tx' => 6]);
        $add("BU$n", 2, $b, ['tx' => 7]);
    }

    $cc = $add('C', 1, null);
    $add('DEST', 1, $cc);
    $mvf = $add('MVF', 1, $cc);
    $add('MVF-V', 2, $mvf, ['v' => 1, 'tx' => 6]);
    $add('MVF-U', 2, $mvf, ['tx' => 7]);
    $add('MVD-U', 2, $cc, ['tx' => 6]);
    $add('MVD-V', 2, $cc, ['v' => 1, 'tx' => 7]);
    $add('D6', 2, $cc, ['tx' => 6]);
    $add('RDV', 2, null, ['v' => 1, 'tx' => 6]);
    $add('RDU', 2, null, ['tx' => 7]);

    $snap = [];
    foreach (rows($c->table('archives')->whereIn('id_archive', $created)->get()) as $row) {
        $snap[$row['id_archive']] = $row;
    }
    $j = jread($journal);
    $j['snap'] = $snap;
    jwrite($journal, $j);
    jwrite($idsFile, $ids);
    say('fixture up: ' . count($created) . ' baris archives berawalan ' . PREFIX . '; QA_USER2 di preset ' . ROLE_NAME . ' (tanpa ' . OD . '); cds documentArchive id ' . $cds[0]['id_column_display_setting']);
    exit(0);
}

// ---------------------------------------------------------------------------------------------------------------- down
if ($cmd === 'down') {
    $old = jread($journal);
    $ok = true;
    if (!$old) {
        say('tidak ada jurnal (down tanpa up?): hanya sapu awalan');
    }
    $n = purgeData($c, $old['ids'] ?? []);
    purgePreset($c);
    if ($old) {
        if (!empty($old['u2']['rows'])) {
            u2Write($c, $old['u2']['uid'], $old['u2']['rows']);
        }
        if (isset($old['lang_orig'])) {
            $c->table('users')->where('id_user', $old['lang_orig']['uid'])->update(['language' => $old['lang_orig']['lang']]);
        }
        if (!empty($old['cds'])) {
            if (cdsRestore($c, $old['cds'])) {
                say('CATATAN: baris cds documentArchive berbeda dari snapshot `up` dan DIPULIHKAN di down (test yang mengubahnya tidak memulihkan?)');
            }
        }
    }
    $left = (int) $c->table('archives')->whereRaw('`name` LIKE BINARY ?', [PREFIX . '%'])->count()
        + (int) $c->table('archive_documents')->where('transaction_no', 'like', PREFIX . '%')->count()
        + (int) $c->table('roles')->where('role_name', 'like', 'QA50-ROLE-%')->count()
        + (int) $c->table('role_permissions')->where('created_by', BY)->count()
        + (int) $c->table('archive_permissions')->where('created_by', BY)->count();
    if ($left > 0) {
        $ok = false;
        say("SISA $left baris milik fixture");
    }
    if ($old) {
        if ($old['ids'] && (int) $c->table('archives')->whereIn('id_archive', $old['ids'])->count() > 0) {
            $ok = false;
            say('SISA baris menurut jurnal');
        }
        if (json_encode(u2Rows($c)) !== json_encode($old['u2']['rows'])) {
            $ok = false;
            say('user_roles QA_USER2 TIDAK sama dengan sebelum run');
        }
        $lang = $c->table('users')->where('id_user', $old['lang_qa_user']['uid'])->value('language');
        if ($lang !== $old['lang_qa_user']['lang']) {
            $ok = false;
            say('bahasa QA_USER TIDAK sama dengan sebelum run');
        }
        if (json_encode(cdsRows($c)) !== json_encode($old['cds'])) {
            $ok = false;
            say('baris cds documentArchive TIDAK sama dengan snapshot `up`');
        }
        $row = cdsRows($c)[0] ?? null;
        $cq = $row ? seq($row['current_queries']) : [];
        $aq = $row ? seq($row['available_queries']) : [];
        $cc = $row ? seq($row['current_columns']) : [];
        $ac = $row ? seq($row['available_columns']) : [];
        if (!in_array('verificationStatus', $cq, true) || !in_array('verificationStatus', $aq, true) || !in_array('documentVerified', $cc, true) || !in_array('documentVerified', $ac, true)) {
            $ok = false;
            say('C-8 GAGAL: baris cds documentArchive kehilangan verificationStatus / documentVerified');
        } else {
            say('C-8 OK: cds documentArchive memuat verificationStatus (current & available) dan documentVerified (current & available); id ' . $row['id_column_display_setting'] . ' updated_at ' . $row['updated_at']);
        }
        $after = devstate($c);
        foreach ($old['before'] as $k => $v) {
            if (json_encode($v) !== json_encode($after[$k])) {
                say("CATATAN $k di luar baris sendiri: sebelum=" . json_encode($v) . ' sesudah=' . json_encode($after[$k]) . ' (bukan buatan fixture; fixture hanya menghapus baris berawalan ' . PREFIX . ')');
            }
        }
        if ($ok) {
            @unlink($journal);
        }
    }
    @unlink($idsFile);
    say("fixture down: $n baris archives berawalan " . PREFIX . ' dibuang (+ dokumen/lokasi/izin-nya); preset dibuang; ' . ($ok ? 'tanpa sisa, pemulihan persis' : 'ADA MASALAH'));
    exit($ok ? 0 : 1);
}

if ($cmd === 'restore') {
    $old = jread($journal);
    if (!$old) {
        fail('restore: jurnal tidak ada (jalankan up dulu)');
    }
    $c->transaction(function () use ($c, $old) {
        foreach ($old['snap'] as $id => $row) {
            $c->table('archives')->where('id_archive', $id)->update($row);
        }
    });
    $bad = 0;
    foreach ($old['snap'] as $id => $row) {
        if (json_encode((array) $c->table('archives')->where('id_archive', $id)->first()) !== json_encode($row)) {
            $bad++;
        }
    }
    say('fixture restore: ' . count($old['snap']) . ' baris dikembalikan; beda=' . $bad);
    exit($bad ? 1 : 0);
}

if ($cmd === 'ids') {
    echo is_file($idsFile) ? file_get_contents($idsFile) : '{}';
    echo "\n";
    exit(0);
}

if ($cmd === 'prop') {
    $name = $argv[2] ?? '';
    $row = $c->table('archives')->where('name', PREFIX . $name)->first();
    if (!$row) {
        fail('prop: baris ' . PREFIX . "$name tidak ada");
    }
    echo json_encode((array) $row) . "\n";
    exit(0);
}

if ($cmd === 'children') {
    $name = $argv[2] ?? '';
    $q = $c->table('archives')->where('is_active', 1)->whereRaw('`name` LIKE BINARY ?', [PREFIX . '%']);
    if ($name === 'root') {
        $q->whereNull('id_archive_parent');
    } else {
        $p = $c->table('archives')->where('name', PREFIX . $name)->value('id_archive');
        if (!$p) {
            fail('children: ' . PREFIX . "$name tidak ada");
        }
        $q->where('id_archive_parent', $p);
    }
    $out = [];
    foreach ($q->orderBy('type')->orderBy('name')->get(['name', 'type', 'is_verified', 'status']) as $r) {
        $out[] = ['name' => $r->name, 'type' => (int) $r->type, 'is_verified' => $r->is_verified === null ? null : (int) $r->is_verified, 'status' => (int) $r->status];
    }
    echo json_encode($out) . "\n";
    exit(0);
}

if ($cmd === 'vt') {
    $name = $argv[2] ?? '';
    $root = $c->table('archives')->where('name', PREFIX . $name)->value('id_archive');
    if (!$root) {
        fail('vt: ' . PREFIX . "$name tidak ada");
    }
    $frontier = [$root];
    $v = 0;
    $t = 0;
    while ($frontier) {
        $kids = $c->table('archives')->whereIn('id_archive_parent', $frontier)->where('is_active', 1)->get(['id_archive', 'type', 'is_verified']);
        $frontier = [];
        foreach ($kids as $k) {
            if ((int) $k->type === 1) {
                $frontier[] = $k->id_archive;
            } else {
                $t++;
                $v += ((int) $k->is_verified === 1) ? 1 : 0;
            }
        }
    }
    echo json_encode(['verified' => $v, 'total' => $t]) . "\n";
    exit(0);
}

if ($cmd === 'root-counts') {
    // oracle root (tabel mentah, tanpa kode BE): folder dan dokumen aktif di level root, dokumen per status; QA_USER = semua lokasi
    $q = function () use ($c) {
        return $c->table('archives')->whereNull('id_archive_parent')->where('is_active', 1);
    };
    $docs = (clone $q())->where('type', 2);
    $ver = (int) (clone $docs)->where('is_verified', 1)->count();
    $all = (int) (clone $docs)->count();
    echo json_encode(['folders' => (int) (clone $q())->where('type', 1)->count(), 'verified' => $ver, 'unverified' => $all - $ver, 'docs' => $all, 'others' => (int) (clone $q())->whereNotIn('type', [1, 2])->count()]) . "\n";
    exit(0);
}

if ($cmd === 'cds') {
    $r = cdsRows($c);
    echo json_encode([
        'rows' => count($r), 'id' => $r[0]['id_column_display_setting'] ?? null, 'updated_at' => $r[0]['updated_at'] ?? null,
        'current_queries' => seq($r[0]['current_queries'] ?? '[]'), 'available_queries' => seq($r[0]['available_queries'] ?? '[]'),
        'current_columns' => seq($r[0]['current_columns'] ?? '[]'), 'available_columns' => seq($r[0]['available_columns'] ?? '[]'),
    ]) . "\n";
    exit(0);
}

if ($cmd === 'cds-restore') {
    $old = jread($journal);
    if (!$old || empty($old['cds'])) {
        fail('cds-restore: jurnal tidak ada');
    }
    $changed = cdsRestore($c, $old['cds']);
    $same = json_encode(cdsRows($c)) === json_encode($old['cds']);
    say('cds-restore: ' . ($changed ? 'dipulihkan' : 'sudah sama') . '; persis sama dengan snapshot: ' . ($same ? 'ya' : 'TIDAK'));
    exit($same ? 0 : 1);
}

if ($cmd === 'lang') {
    $to = strtoupper($argv[2] ?? '');
    if (!in_array($to, ['ID', 'EN'], true)) {
        fail('lang: ID atau EN');
    }
    $j = jread($journal);
    if (!$j) {
        fail('lang: jurnal tidak ada');
    }
    $u = qaUser($c, 'QA_USER');
    if (!isset($j['lang_orig'])) {
        $j['lang_orig'] = ['uid' => $u->id_user, 'lang' => $u->language];
        jwrite($journal, $j); // dicatat SEBELUM ditulis
    }
    $c->table('users')->where('id_user', $u->id_user)->update(['language' => $to]);
    say("lang: QA_USER = $to (asal " . $j['lang_orig']['lang'] . ')');
    exit(0);
}

if ($cmd === 'lang-restore') {
    $j = jread($journal);
    if ($j && isset($j['lang_orig'])) {
        $c->table('users')->where('id_user', $j['lang_orig']['uid'])->update(['language' => $j['lang_orig']['lang']]);
        say('lang-restore: QA_USER = ' . $j['lang_orig']['lang']);
        unset($j['lang_orig']);
        jwrite($journal, $j);
    } else {
        say('lang-restore: tidak ada yang dipulihkan');
    }
    exit(0);
}

if ($cmd === 'has-od') {
    $has = function ($key) use ($c) {
        $u = qaUser($c, $key);

        return (int) $c->table('user_roles as ur')->join('role_permissions as rp', 'rp.id_role', '=', 'ur.id_role')
            ->where('ur.id_user', $u->id_user)->where('rp.permission_name', OD)->count() > 0;
    };
    echo json_encode(['QA_USER' => $has('QA_USER'), 'QA_USER2' => $has('QA_USER2')]) . "\n";
    exit(0);
}

fail('perintah tidak dikenal: ' . $cmd);
