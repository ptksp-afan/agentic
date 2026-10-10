<?php
/**
 * Fixture e2e ED-1027 (BUKAN skenario regresi; dipanggil lewat qa/e2e/fixtures.json dan spec).
 *
 *   "$PHP_BIN" fixture.php up        buat folder/dokumen uji berawalan "0QA27-" (angka verifikasi diketahui tangan), 4 sesi opname
 *                                    terkonfirmasi + 1 draft (dibuat langsung di DB), simpan snapshot baris display setting Archive
 *   "$PHP_BIN" fixture.php down      buang HANYA data milik fixture (lihat "Jurnal"), pulihkan baris display setting persis, lalu
 *                                    pastikan tidak ada sisa milik fixture (count + CHECKSUM 8 tabel vs baseline hanya INFO)
 *   "$PHP_BIN" fixture.php ids       cetak peta nama -> id_archive + angka harapan (JSON, tanpa rahasia)
 *   "$PHP_BIN" fixture.php inspect   cetak keadaan DB: is_verified per dokumen uji + sesi opname milik fixture (JSON, baca saja)
 *   "$PHP_BIN" fixture.php display   cetak baris display setting documentArchive (kolom aktif; JSON, baca saja)
 *   "$PHP_BIN" fixture.php grant-od  (rev) beri role(s) QA_USER2 permission `Opname Document` (per NAMA) SEMENTARA: hanya baris
 *                                    role_permissions yang dibuat (created_by QA27E2E, id di .grant-journal.json); role yang sudah
 *                                    memegangnya tidak disentuh. Dipakai satu test [user2] (EN + lokasi terbatas), selalu dicabut lagi.
 *   "$PHP_BIN" fixture.php set-folder-active <nama 0QA27-...> <0|1>  (QA FE rev r2) nonaktifkan/aktifkan lagi SATU folder milik fixture
 *                                    (hanya berawalan PREFIX dan hanya selama fixture up): jalur error NYATA modal folder (folder
 *                                    hilang sesudah list dimuat -> BE 404 ARCHIVE400 asli). `down` membuang barisnya apa pun nilainya.
 *   "$PHP_BIN" fixture.php revoke-od hapus PERSIS baris yang dibuat grant-od; ringkasan (jumlah+CRC) role_permissions harus sama
 *                                    dengan sebelum grant, bila beda exit 1. `down` ikut mencabut sisa grant.
 *
 * Jurnal (.fixture-journal.json, ditulis up SEBELUM baris pertama): id sesi opname yang sudah ada sebelum up, snapshot kolom
 * verifikasi archives yang tak-bawaan, id archive dan id sesi yang disisipkan fixture. Milik fixture = archives berawalan
 * PREFIX / tercatat di jurnal; sesi yang disisipkan fixture, atau dibuat lewat aplikasi selama run pada folder fixture atau di
 * root oleh QA_USER/QA_USER2. Kolom verifikasi archives lain yang disentuh sesi itu dikembalikan ke nilai jurnal. Data
 * developer di tabel yang sama tidak pernah dihapus/direset; down tanpa jurnal tidak melakukan apa pun.
 *
 * Pohon uji (semua aktif, is_all_location=1; user QA = role 3 semua lokasi, bukan bypass superadmin 1/2):
 *   0QA27-ALPHA   AD1(6,V) AD2(7) AD-HO(8, Handed Over) AD-TK(9, V, Taken) AD-NEG(6,V,is_active=-1) AD-DEL(6,V,is_active=0)
 *                 | S1 (S1D1 6 V, S1D2 8) | S1-X (S1XD1 7 V, di bawah S1) | S2 (S2D1 6)
 *                 => ALPHA 4/8, S1 2/3, S1-X 1/1, S2 0/1; tipe: 6 V2/U1, 7 V1/U1, 8 V0/U2, 9 V1/U0
 *   0QA27-BETA    BD1(6,V) | T1 (T1D1 7 V, T1D2 6)                      => BETA 2/3, T1 1/2
 *   0QA27-GAMMA   GD1(6) GD2(7) GD3(8)                                   => 0/3 (alur Confirm Opname lewat UI)
 *   0QA27-EMPTY   kosong                                                 => 0/0
 *   0QA27-VIEWONLY VD1(6); folder permission: user QA hanya View          => 0/1
 *   0QA27-NOVIEW  ND1(6,V); folder permission aktif tanpa baris user QA   => "-" (tak ikut angka)
 *   dokumen root  0QA27-RD-V (6, V), 0QA27-RD-U (7)                      => 1/2 di root
 *   Kontribusi fixture ke ringkasan All Archive (user QA): verified 7, total 17.
 * Sesi terkonfirmasi (terbaru dulu): S4 ALPHA (scope ED-1026 rev "0QA27-ALPHA + 2 subfolder": folder opname ALPHA, 2 subfolder
 * dicentang di Step 1), S3 root ("Semua Arsip · root" / "All Archive · root"), S2 GAMMA (tanpa subfolder: nama folder saja),
 * S1 BETA (tidak tampil: batas 3). (rev, ED-1026 FB-3) DB hanya berisi sesi terkonfirmasi (status 2): tanpa draft.
 */

namespace QaHttp;

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../../..')));

require QA_AGENTIC . '/scripts/qa-http/lib/Outcome.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Config.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Laravel.php';

use Illuminate\Support\Facades\DB;

const PREFIX = '0QA27-';
const TABLES = ['archives', 'archive_documents', 'archive_locations', 'archive_permissions', 'archive_opnames', 'archive_opname_folders', 'archive_opname_documents', 'column_display_settings'];
const OP_TABLES = ['archive_opnames', 'archive_opname_folders', 'archive_opname_documents'];
const DISPLAY_MODULE = 'documentArchive';
const JOURNAL_V = 2;
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
$grantJournal = __DIR__ . '/.grant-journal.json';

Laravel::boot();
$db = Config::defaultDb();
$c = DB::connection($db);

function state($c)
{
    $out = [];
    foreach (TABLES as $t) {
        $row = (array) $c->selectOne("CHECKSUM TABLE `$t`");
        $out[$t] = ['count' => (int) $c->table($t)->count(), 'checksum' => (string) ($row['Checksum'] ?? '')];
    }

    return $out;
}

function displayRows($c)
{
    $out = [];
    foreach ($c->table('column_display_settings')->where('module_name', DISPLAY_MODULE)->orderBy('id_column_display_setting')->get() as $r) {
        $out[] = (array) $r;
    }

    return $out;
}

/** Pulihkan baris display setting modul persis seperti snapshot; tidak menulis apa pun bila sudah sama. */
function displayRestore($c, array $snap)
{
    if (displayRows($c) === $snap) {
        return;
    }
    $c->transaction(function () use ($c, $snap) {
        $c->table('column_display_settings')->where('module_name', DISPLAY_MODULE)->delete();
        foreach ($snap as $row) {
            $c->table('column_display_settings')->insert($row);
        }
    });
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

// ------------------------------------------------------------------ grant sementara `Opname Document` (rev, test [user2] EN)

const GRANT_BY = 'QA27E2E';
const GRANT_PERMISSION = 'Opname Document';

/** [jumlah baris, jumlah CRC32 per baris] role_permissions: bukti pemulihan persis. */
function rpSum($c)
{
    $cols = array_map(function ($r) {
        return $r->Field;
    }, $c->select('SHOW COLUMNS FROM `role_permissions`'));
    $parts = array_map(function ($x) {
        return "IFNULL(CAST(`$x` AS CHAR),'~')";
    }, $cols);
    $r = $c->selectOne("SELECT COUNT(*) n, IFNULL(SUM(CRC32(CONCAT_WS('|'," . implode(',', $parts) . '))),0) h FROM `role_permissions`');

    return [(int) $r->n, (string) $r->h];
}

/** Cabut baris grant yang tercatat di jurnal (hanya id jurnal + created_by GRANT_BY). Mengembalikan baris pesan masalah. */
function grantRevoke($c, $file)
{
    $j = journalRead($file);
    if ($j === null) {
        return [];
    }
    $ids = $j['ids'] ?? [];
    if ($ids) {
        $c->table('role_permissions')->whereIn('id_role_permission', $ids)->where('created_by', GRANT_BY)->delete();
    }
    $lines = [];
    if (rpSum($c) !== ($j['before'] ?? null)) {
        $lines[] = 'BEDA role_permissions sesudah grant dicabut: sebelum=' . json_encode($j['before'] ?? null) . ' sesudah=' . json_encode(rpSum($c));
    } else {
        @unlink($file);
    }

    return $lines;
}

if ($cmd === 'up') {
    $gl = grantRevoke($c, $grantJournal); // sisa grant run yang mati
    if ($gl) {
        fail('sisa grant run lama tidak bisa dibereskan: ' . implode('; ', $gl));
    }
    $old = journalRead($journal);
    if ($old !== null) { // run sebelumnya mati: bereskan dulu, hanya data yang tercatat jurnal itu
        if (($old['v'] ?? 0) !== JOURNAL_V) {
            fail('jurnal format lama ' . basename($journal) . ': tidak dibereskan otomatis; periksa data ' . PREFIX . ' manual lalu hapus jurnalnya');
        }
        $r = purge($c, $old);
        displayRestore($c, $old['display']);
        $lines = leftovers($c, $old, $r['sessions']);
        if (displayRows($c) !== $old['display']) {
            $lines[] = 'BEDA baris display setting documentArchive';
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
    // tidak ada prasyarat "tabel opname kosong": fixture hidup berdampingan dengan data developer (hanya miliknya yang disentuh)
    if (count(displayRows($c)) !== 1) {
        fail('baris display setting documentArchive bukan tepat 1 (Updater ED-1027 belum jalan?)');
    }

    $user = $c->table('users')->where('username', Config::get('QA_USER'))->first();
    if (!$user) {
        fail('QA_USER tidak ditemukan di DB');
    }

    $before = state($c);
    $baselineTotal = (int) $c->table('archives')->where('type', 2)->where('is_active', '>', 0)->count();
    $baselineVerified = (int) $c->table('archives')->where('type', 2)->where('is_active', '>', 0)->where('is_verified', 1)->count();
    journalWrite($journal, [
        'v' => JOURNAL_V, 'state' => $before, 'display' => displayRows($c), 'qa_users' => qaUsernames(),
        'pre_sessions' => $c->table('archive_opnames')->pluck('id_archive_opname')->all(),
        'pre_verif' => verificationSnapshot($c), 'archives' => [], 'sessions' => [],
    ]);

    $now = \Carbon\Carbon::now('Asia/Jakarta');
    $nowStr = $now->format('Y-m-d H:i:s');
    $gen = function () {
        return \Modules\V5\Entities\Helper\MyHelper::generateId();
    };

    $ids = [];
    $by = 'QA27E2E';
    $add = function ($name, $type, $parent, $txType = 6, $o = []) use ($c, &$ids, $nowStr, $gen, $by) {
        $id = $gen();
        $active = $o['active'] ?? 1;
        $verified = !empty($o['v']) ? 1 : 0;
        $status = $o['status'] ?? 1;
        $history = null;
        if ($status === 2) {
            $history = json_encode([['action' => 'give', 'related_username' => 'qa27', 'timestamp' => '05 Okt 2026 09:00:00']]);
        } elseif ($status === 3) {
            $history = json_encode([['action' => 'take', 'related_username' => 'qa27', 'timestamp' => '05 Okt 2026 09:00:00']]);
        }
        $c->table('archives')->insert([
            'id_archive' => $id, 'id_archive_parent' => $parent, 'name' => $name, 'type' => $type, 'status' => $status,
            'history' => $history, 'is_all_location' => 1, 'is_folder_permission' => $o['perm'] ?? 0, 'is_active' => $active,
            'is_verified' => $verified, 'verified_at' => $verified ? $nowStr : null, 'verified_by' => $verified ? $by : null,
            'created_at' => $nowStr, 'created_by' => $by, 'updated_at' => $nowStr, 'updated_by' => $by,
        ]);
        if ($type == 2) {
            $c->table('archive_documents')->insert([
                'id_archive_document' => $gen(), 'id_archive' => $id, 'id_transaction' => 'QA27TX' . substr($id, -10),
                'transaction_no' => $name, 'transaction_type' => $txType, 'related_employee_name' => 'Sales QA27',
                'created_at' => $nowStr, 'updated_at' => $nowStr,
            ]);
        }
        $ids[$name] = $id;

        return $id;
    };

    $c->transaction(function () use ($add, $c, $user, $gen, $nowStr, $by) {
        $alpha = $add(PREFIX . 'ALPHA', 1, null);
        $add(PREFIX . 'AD1', 2, $alpha, 6, ['v' => 1]);
        $add(PREFIX . 'AD2', 2, $alpha, 7);
        $add(PREFIX . 'AD-HO', 2, $alpha, 8, ['status' => 2]);
        $add(PREFIX . 'AD-TK', 2, $alpha, 9, ['status' => 3, 'v' => 1]);
        $add(PREFIX . 'AD-NEG', 2, $alpha, 6, ['active' => -1, 'v' => 1]);
        $add(PREFIX . 'AD-DEL', 2, $alpha, 6, ['active' => 0, 'v' => 1]);
        $s1 = $add(PREFIX . 'ALPHA-S1', 1, $alpha);
        $add(PREFIX . 'S1D1', 2, $s1, 6, ['v' => 1]);
        $add(PREFIX . 'S1D2', 2, $s1, 8);
        $x = $add(PREFIX . 'ALPHA-S1-X', 1, $s1);
        $add(PREFIX . 'S1XD1', 2, $x, 7, ['v' => 1]);
        $s2 = $add(PREFIX . 'ALPHA-S2', 1, $alpha);
        $add(PREFIX . 'S2D1', 2, $s2, 6);

        $beta = $add(PREFIX . 'BETA', 1, null);
        $add(PREFIX . 'BD1', 2, $beta, 6, ['v' => 1]);
        $t1 = $add(PREFIX . 'BETA-T1', 1, $beta);
        $add(PREFIX . 'T1D1', 2, $t1, 7, ['v' => 1]);
        $add(PREFIX . 'T1D2', 2, $t1, 6);

        $gamma = $add(PREFIX . 'GAMMA', 1, null);
        $add(PREFIX . 'GD1', 2, $gamma, 6);
        $add(PREFIX . 'GD2', 2, $gamma, 7);
        $add(PREFIX . 'GD3', 2, $gamma, 8);

        $add(PREFIX . 'EMPTY', 1, null);

        $vo = $add(PREFIX . 'VIEWONLY', 1, null, 6, ['perm' => 1]);
        $add(PREFIX . 'VD1', 2, $vo, 6);
        $c->table('archive_permissions')->insert([
            'id_archive_permission' => $gen(), 'id_archive' => $vo, 'id_user' => $user->id_user,
            'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0,
            'created_at' => $nowStr, 'created_by' => $by, 'updated_at' => $nowStr, 'updated_by' => $by,
        ]);
        $nv = $add(PREFIX . 'NOVIEW', 1, null, 6, ['perm' => 1]);
        $add(PREFIX . 'ND1', 2, $nv, 6, ['v' => 1]);

        $add(PREFIX . 'RD-V', 2, null, 6, ['v' => 1]);
        $add(PREFIX . 'RD-U', 2, null, 7);
    });
    journalAdd($journal, 'archives', array_values($ids));

    // sesi opname terkonfirmasi (snapshot angka; dibuat langsung di DB)
    $sessions = [];
    $session = function ($key, $idArchive, $scopeName, $scopeCount, $confirmedAt, $createdBy, $nums) use ($c, $gen, &$sessions, $journal) {
        $status = 2; // hanya sesi terkonfirmasi (ED-1026 FB-3)
        $sid = $gen();
        journalAdd($journal, 'sessions', [$sid]); // dicatat dulu: sesi root (S3) tidak bisa dikenali dari foldernya
        $c->table('archive_opnames')->insert([
            'id_archive_opname' => $sid, 'id_archive' => $idArchive, 'status' => $status, 'scope_name' => $scopeName,
            'scope_folder_count' => $scopeCount, 'total_documents' => $nums[0], 'verified_before_count' => 0,
            'scanned_count' => $nums[1] + $nums[2] + $nums[3], 'verified_count' => $nums[1], 'not_found_count' => $nums[2],
            'invalid_count' => $nums[3], 'unscanned_count' => 0, 'unverified_count' => 0,
            'selected_at' => $confirmedAt, 'confirmed_at' => $status === 2 ? $confirmedAt : null, 'created_at' => $confirmedAt,
            'created_by' => $createdBy, 'updated_at' => $confirmedAt, 'updated_by' => $createdBy,
        ]);
        $sessions[$key] = $sid;

        return $sid;
    };
    $folderRow = function ($sid, $level, $name, $idArchive, $parent, $total, $verified, $at) use ($c, $gen) {
        $c->table('archive_opname_folders')->insert([
            'id_archive_opname_folder' => $gen(), 'id_archive_opname' => $sid, 'id_archive' => $idArchive,
            'id_archive_parent' => $parent, 'name' => $name, 'level' => $level, 'is_continue' => 0, 'is_opnamed_today' => 0,
            'total_documents' => $total, 'verified_count' => $verified, 'verified_after_count' => $verified,
            'unverified_count' => 0, 'created_at' => $at,
        ]);
    };

    $c->transaction(function () use ($c, $ids, $session, $folderRow, &$sessions) {
        $P = PREFIX;
        // S1 (terlama, di luar 3 terbaru): BETA
        $at = '2026-10-01 08:00:00';
        $s = $session('S1', $ids[$P . 'BETA'], $P . 'BETA', 0, $at, 'qa27oldest', [3, 2, 0, 0]);
        $folderRow($s, 0, $P . 'BETA', $ids[$P . 'BETA'], null, 1, 1, $at);
        $folderRow($s, 1, $P . 'BETA-T1', $ids[$P . 'BETA-T1'], $ids[$P . 'BETA'], 2, 1, $at);
        // S2: GAMMA
        $at = '2026-10-04 14:30:00';
        $s = $session('S2', $ids[$P . 'GAMMA'], $P . 'GAMMA', 0, $at, 'qa27gamma', [3, 1, 0, 0]);
        $folderRow($s, 0, $P . 'GAMMA', $ids[$P . 'GAMMA'], null, 3, 1, $at);
        // S3: root (All Archive, tanpa subfolder dicentang); mencakup BETA (level 1) + T1 (level 2)
        $at = '2026-10-05 09:15:00';
        $s = $session('S3', null, null, 0, $at, 'qa27root', [20, 9, 2, 1]);
        $folderRow($s, 1, $P . 'BETA', $ids[$P . 'BETA'], null, 1, 1, $at);
        $folderRow($s, 2, $P . 'BETA-T1', $ids[$P . 'BETA-T1'], $ids[$P . 'BETA'], 2, 1, $at);
        // S4 (terbaru): ALPHA + 2 subfolder (S1, S2; S1-X ikut otomatis, level 2)
        $at = '2026-10-06 16:40:00';
        $s = $session('S4', $ids[$P . 'ALPHA'], $P . 'ALPHA-S1', 2, $at, 'qa27alpha', [8, 5, 1, 2]);
        $folderRow($s, 0, $P . 'ALPHA', $ids[$P . 'ALPHA'], null, 4, 2, $at);
        $folderRow($s, 1, $P . 'ALPHA-S1', $ids[$P . 'ALPHA-S1'], $ids[$P . 'ALPHA'], 2, 1, $at);
        $folderRow($s, 1, $P . 'ALPHA-S2', $ids[$P . 'ALPHA-S2'], $ids[$P . 'ALPHA'], 1, 0, $at);
        $folderRow($s, 2, $P . 'ALPHA-S1-X', $ids[$P . 'ALPHA-S1-X'], $ids[$P . 'ALPHA-S1'], 1, 1, $at);
    });

    $ids['_sessions'] = $sessions;
    $ids['_baseline_total'] = $baselineTotal;
    $ids['_baseline_verified'] = $baselineVerified;
    // angka harapan = baseline (data lain di DB) + kontribusi fixture; baseline 0 = angka lama (7)
    $ids['_expect'] = ['summary_verified' => $baselineVerified + 7, 'summary_total' => $baselineTotal + 17];
    $ids['_user'] = ['id_user' => $user->id_user, 'username' => $user->username];
    file_put_contents($idsFile, json_encode($ids));
    say('fixture up: ' . (count($ids) - 5) . ' entri archive, 4 sesi terkonfirmasi; baseline dokumen aktif=' . $baselineTotal
        . ' verified=' . $baselineVerified . '; archives=' . $before['archives']['count'] . ' opname=' . $before['archive_opnames']['count']);
    exit(0);
}

if ($cmd === 'down') {
    $gl = grantRevoke($c, $grantJournal);
    foreach ($gl as $l) {
        say($l);
    }
    $old = journalRead($journal);
    if ($old === null) { // tanpa jurnal tidak ada bukti baris mana milik fixture: jangan sentuh apa pun
        $orphans = count(prefixArchiveIds($c));
        say('fixture down: tidak ada jurnal (down tanpa up yang lulus?) -> TIDAK ADA yang dihapus atau dipulihkan'
            . ($orphans ? "; PERIKSA: $orphans baris archives berawalan " . PREFIX . ' tanpa jurnal (bereskan manual)' : ''));
        exit(($orphans || $gl) ? 1 : 0);
    }
    if (($old['v'] ?? 0) !== JOURNAL_V) {
        say('fixture down: jurnal format lama -> TIDAK ADA yang dihapus; bereskan manual');
        exit(1);
    }
    $r = purge($c, $old);
    displayRestore($c, $old['display']);
    $lines = leftovers($c, $old, $r['sessions']);
    if (displayRows($c) !== $old['display']) {
        $lines[] = 'BEDA baris display setting documentArchive: tidak sama dengan snapshot awal';
    }
    $info = [];
    compare($old['state'], state($c), $info);
    foreach ($info as $l) {
        say('INFO (bukan kegagalan: DB bersama bisa berubah oleh aktivitas lain) ' . $l);
    }
    foreach ($lines as $l) {
        say($l);
    }
    $lines = array_merge($lines, $gl);
    if (!$lines) {
        @unlink($journal);
    }
    @unlink($idsFile);
    say('fixture down: ' . $r['archives'] . ' archives fixture + ' . count($r['sessions']) . ' sesi opname fixture dibuang, '
        . $r['restored'] . ' baris archives lain dipulihkan kolom verifikasinya; '
        . ($lines ? 'MASIH ADA SISA MILIK FIXTURE' : 'tidak ada sisa milik fixture, baris display setting identik'));
    exit($lines ? 1 : 0);
}

if ($cmd === 'ids') {
    echo is_file($idsFile) ? file_get_contents($idsFile) : '{}';
    echo "\n";
    exit(0);
}

if ($cmd === 'display') {
    $row = displayRows($c)[0] ?? null;
    $cols = $row ? array_map(function ($x) {
        return $x['data_index'];
    }, json_decode($row['current_columns'], true)) : [];
    echo json_encode(['current' => $cols, 'updated_by' => $row['updated_by'] ?? null]) . "\n";
    exit(0);
}

if ($cmd === 'inspect') {
    $docs = [];
    $q = $c->table('archives')->whereRaw('`name` LIKE BINARY ?', [PREFIX . '%'])->where('type', 2)->orderBy('name')->get();
    foreach ($q as $r) {
        $docs[$r->name] = [
            'is_verified' => (int) $r->is_verified, 'has_opname' => $r->id_archive_opname !== null,
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
            'verified' => (int) $s->verified_count, 'not_found' => (int) $s->not_found_count, 'invalid' => (int) $s->invalid_count,
            'confirmed' => $s->confirmed_at !== null, 'created_by' => $s->created_by,
        ];
    }
    echo json_encode(['docs' => $docs, 'sessions' => $sessions]) . "\n";
    exit(0);
}

if ($cmd === 'grant-od') {
    $stale = grantRevoke($c, $grantJournal);
    if ($stale) {
        fail('sisa grant sebelumnya tidak bisa dibereskan: ' . implode('; ', $stale));
    }
    $uid = $c->table('users')->where('username', Config::get('QA_USER2'))->value('id_user');
    if (!$uid) {
        fail('QA_USER2 tidak ditemukan di DB');
    }
    $pid = $c->table('permissions')->where('permission_name', GRANT_PERMISSION)->value('id_permission');
    if ($pid === null) {
        fail('permission ' . GRANT_PERMISSION . ' tidak ada (dicari per nama)');
    }
    $roles = $c->table('user_roles')->where('id_user', $uid)->pluck('id_role')->all();
    $before = rpSum($c);
    journalWrite($grantJournal, ['v' => 1, 'ids' => [], 'before' => $before, 'roles' => $roles]);
    $made = [];
    foreach ($roles as $role) {
        $has = $c->table('role_permissions')->where('id_role', $role)->where('id_permission', $pid)->exists();
        if ($has) {
            continue;
        }
        $made[] = $c->table('role_permissions')->insertGetId([
            'id_role' => $role, 'id_permission' => $pid, 'permission_name' => GRANT_PERMISSION,
            'created_at' => date('Y-m-d H:i:s'), 'created_by' => GRANT_BY,
        ]);
        journalAdd($grantJournal, 'ids', [end($made)]);
    }
    $users = [];
    foreach ($roles as $role) {
        $users[$role] = (int) $c->table('user_roles')->where('id_role', $role)->count();
    }
    say(json_encode(['roles' => $roles, 'made' => $made, 'users_per_role' => $users]));
    exit(0);
}

if ($cmd === 'revoke-od') {
    $lines = grantRevoke($c, $grantJournal);
    foreach ($lines as $l) {
        say($l);
    }
    say($lines ? 'revoke-od: GAGAL' : 'revoke-od: grant dicabut, role_permissions identik dengan sebelum grant');
    exit($lines ? 1 : 0);
}

if ($cmd === 'set-folder-active') {
    $name = (string) ($argv[2] ?? '');
    $to = ($argv[3] ?? '') === '0' ? 0 : 1;
    if (strpos($name, PREFIX) !== 0 || journalRead($journal) === null) {
        fail('hanya folder berawalan ' . PREFIX . ' dan hanya selama fixture up (jurnal ada)');
    }
    $n = $c->table('archives')->whereRaw('`name` LIKE BINARY ?', [$name])->where('type', 1)->update(['is_active' => $to]);
    say(json_encode(['name' => $name, 'is_active' => $to, 'rows' => $n]));
    exit($n === 1 ? 0 : 1);
}

fail('pakai: up | down | ids | inspect | display | grant-od | revoke-od | set-folder-active');
