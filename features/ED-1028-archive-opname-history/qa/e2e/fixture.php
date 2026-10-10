<?php
/**
 * Fixture e2e ED-1028 (BUKAN skenario regresi; dipanggil lewat qa/e2e/fixtures.json dan spec).
 *
 *   "$PHP_BIN" fixture.php up         buat folder/dokumen uji berawalan "0QA28-" (tanpa sesi opname) + snapshot baris display setting
 *   "$PHP_BIN" fixture.php sessions   sisipkan sesi opname uji (14 terkonfirmasi terlihat + 1 tersembunyi); gagal bila sesi milik
 *                                     fixture sudah ada (sesi developer tidak dihitung)
 *   "$PHP_BIN" fixture.php ensure-sessions   (rev) pastikan TEPAT 15 sesi uji ada dan tidak ada sesi lain milik fixture (mis. sesi nyata
 *                                     hasil Confirm lewat UI): keadaan menyimpang dibereskan (hanya milik fixture) lalu diisi ulang
 *   "$PHP_BIN" fixture.php reset-sessions    buang sesi opname MILIK FIXTURE saja (kolom verifikasi dokumen yang tersentuh dikembalikan);
 *                                     folder/dokumen uji tetap
 *   "$PHP_BIN" fixture.php down       buang HANYA data milik fixture (lihat "Jurnal"), cabut sisa grant, pulihkan display setting persis,
 *                                     lalu pastikan tidak ada sisa milik fixture (count + CHECKSUM 8 tabel vs baseline hanya INFO)
 *   "$PHP_BIN" fixture.php ids        cetak peta nama -> id (JSON, tanpa rahasia)
 *   "$PHP_BIN" fixture.php inspect    cetak keadaan DB (dokumen uji + sesi milik fixture; JSON, baca saja)
 *   "$PHP_BIN" fixture.php display    cetak kolom aktif display setting riwayat (JSON, baca saja)
 *   "$PHP_BIN" fixture.php grant-od   (rev, K-8 a) beri role(s) QA_USER2 permission `Opname Document` (dicari per NAMA) SEMENTARA:
 *                                     hanya baris role_permissions yang dibuat (created_by QA28E2E, id di .grant-journal.json); role yang
 *                                     sudah memegangnya tidak disentuh. Dipakai test [user2] AC-21 (EN + lokasi terbatas), selalu dicabut.
 *   "$PHP_BIN" fixture.php revoke-od  hapus PERSIS baris yang dibuat grant-od; ringkasan (jumlah + CRC) role_permissions harus sama dengan
 *                                     sebelum grant, bila beda exit 1. `down` ikut mencabut sisa grant.
 *
 * Jurnal (.fixture-journal.json, ditulis up SEBELUM baris pertama): id sesi opname yang sudah ada sebelum up, snapshot kolom
 * verifikasi archives yang tak-bawaan, id archive dan id sesi yang disisipkan fixture. Milik fixture = archives berawalan
 * PREFIX / tercatat di jurnal; sesi yang disisipkan fixture, atau dibuat lewat aplikasi selama run pada folder fixture atau di
 * root oleh QA_USER/QA_USER2. Kolom verifikasi archives lain yang disentuh sesi itu dikembalikan ke nilai jurnal. Data
 * developer di tabel yang sama tidak pernah dihapus/direset; down tanpa jurnal tidak melakukan apa pun.
 *
 * (rev 2026-10-10) Tanpa draft/sesi batal (ED-1026 FB-3: tidak ada lagi di DB). Waktu konfirmasi sesi uji = TAHUN 2031 (jauh di depan
 * semua data nyata): sesi uji selalu di atas sesi developer pada urutan bawaan (Waktu terbaru di atas), jadi halaman 1, 3 sesi
 * overview, dan rentang tanggal uji deterministik walau tabel opname berisi data developer; asersi spec dilingkup ke sesi uji.
 *
 * Pohon uji (semua aktif, is_all_location=1; user QA = role 3 semua lokasi bukan superadmin, user2 = Sales Supervisor lokasi SMR):
 *   0QA28-ALFA   A1(6) A2(7) | ALFA-S1 (S1a 6) | ALFA-S1-X (X1 6, di bawah S1) | ALFA-S2 (S2a 7)
 *   0QA28-BRAVO  B1(6) | BRAVO-T1 (T1a 7)
 *   0QA28-GAMMA  GD1(6) GD2(7) GD3(8)     (alur Confirm Opname nyata lewat UI)
 *   0QA28-EMPTYF kosong (folder tanpa sesi sama sekali)
 *   0QA28-SECRET folder permission aktif tanpa baris user (tak terlihat user QA maupun user2): H1(6) H2(7)
 *   0QA28-VIEWONLY folder permission aktif, user QA hanya View (drawer folder read-only); user2 tanpa baris = tak terlihat: VD1(6)
 *   dokumen root 0QA28-RD (6)
 *
 * Sesi (snapshot; terbaru dulu; semua 2031). Terkonfirmasi dan terlihat user QA/user2 kecuali yang ditandai. Scope (ED-1026 K-14 a,
 * K-10 b) = "<nama folder opname> + N subfolder", N = scope_folder_count; tanpa akhiran bila 0; root "Semua Arsip · root":
 *   N01 alice  ALFA  N=2 "0QA28-ALFA + 2 subfolder" total 5 verified 3 not found 1 invalid 1 scanned 5 unscanned 2   (hasil lengkap, 7 baris)
 *   N02 root   root  "All Archive · root" total 8 verified 3 nf 1 inv 1 scanned 5 unscanned 2    (PARSIAL untuk user QA/user2: H1/H2 di SECRET)
 *   N03 bob    BRAVO
 *   N04 carol  BRAVO-T1   N05 alice ALFA-S2   N06 dave BRAVO    N07 erin ALFA-S1   N08 bob BRAVO N=1 "0QA28-BRAVO + 1 subfolder"
 *   N09 carol  ALFA       N10 dave BRAVO      N11 alice ALFA-S1-X N12 erin BRAVO    N13 bob ALFA
 *   N14 qa28view VIEWONLY (terlihat user QA, TIDAK terlihat user2)
 *   HID  qa28hid SECRET (terkonfirmasi, tidak terlihat user QA/user2)
 */

namespace QaHttp;

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../../..')));

require QA_AGENTIC . '/scripts/qa-http/lib/Outcome.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Config.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Laravel.php';

use Illuminate\Support\Facades\DB;

const PREFIX = '0QA28-';
const TABLES = ['archives', 'archive_documents', 'archive_locations', 'archive_permissions', 'archive_opnames', 'archive_opname_folders', 'archive_opname_documents', 'column_display_settings'];
const OP_TABLES = ['archive_opnames', 'archive_opname_folders', 'archive_opname_documents'];
const DISPLAY_MODULES = ['documentArchive', 'documentArchiveOpnameHistory'];
const JOURNAL_V = 2;
const SEEDED_N = 15; // 14 terlihat + HID
const GRANT_BY = 'QA28E2E';
const GRANT_PERMISSION = 'Opname Document';
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
    foreach ($c->table('column_display_settings')->whereIn('module_name', DISPLAY_MODULES)->orderBy('module_name')->orderBy('id_column_display_setting')->get() as $r) {
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
        $c->table('column_display_settings')->whereIn('module_name', DISPLAY_MODULES)->delete();
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

// ------------------------------------------------------------------ grant sementara `Opname Document` (rev K-8 a, test [user2] AC-21)

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

/** Hapus sesi milik fixture (semua, termasuk hasil Confirm lewat UI) dan kembalikan kolom verifikasi dokumen yang disentuh. */
function resetOwnSessions($c, array $j)
{
    $archiveIds = fixtureArchiveIds($c, $j);
    $sessions = fixtureSessionIds($c, $j, $archiveIds); // hanya sesi milik fixture; sesi developer tidak disentuh
    $c->transaction(function () use ($c, $j, $archiveIds, $sessions) {
        restoreVerification($c, $j, $sessions, $archiveIds);
        // dokumen fixture (dibuat tanpa verifikasi) yang disentuh sesi itu kembali ke bawaan
        foreach (array_chunk($sessions, 200) as $chunk) {
            foreach (array_chunk($archiveIds, 500) as $own) {
                $c->table('archives')->whereIn('id_archive', $own)->whereIn('id_archive_opname', $chunk)->update([
                    'is_verified' => 0, 'verified_at' => null, 'verified_by' => null, 'id_archive_opname' => null,
                ]);
            }
        }
        deleteSessions($c, $sessions);
    });

    return $sessions;
}

$gen = function () {
    return \Modules\V5\Entities\Helper\MyHelper::generateId();
};

if ($cmd === 'up') {
    $old = journalRead($journal);
    if ($old !== null) { // run sebelumnya mati: bereskan dulu, hanya data yang tercatat jurnal itu
        if (($old['v'] ?? 0) !== JOURNAL_V) {
            fail('jurnal format lama ' . basename($journal) . ': tidak dibereskan otomatis; periksa data ' . PREFIX . ' manual lalu hapus jurnalnya');
        }
        $r = purge($c, $old);
        displayRestore($c, $old['display']);
        $lines = leftovers($c, $old, $r['sessions']);
        if (displayRows($c) !== $old['display']) {
            $lines[] = 'BEDA baris display setting';
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

    $before = state($c);
    journalWrite($journal, [
        'v' => JOURNAL_V, 'state' => $before, 'display' => displayRows($c), 'qa_users' => qaUsernames(),
        'pre_sessions' => $c->table('archive_opnames')->pluck('id_archive_opname')->all(),
        'pre_verif' => verificationSnapshot($c), 'archives' => [], 'sessions' => [],
    ]);

    $nowStr = \Carbon\Carbon::now('Asia/Jakarta')->format('Y-m-d H:i:s');
    $ids = [];
    $by = 'QA28E2E';
    $add = function ($name, $type, $parent, $txType = 6, $o = []) use ($c, &$ids, $nowStr, $gen, $by) {
        $id = $gen();
        $c->table('archives')->insert([
            'id_archive' => $id, 'id_archive_parent' => $parent, 'name' => $name, 'type' => $type, 'status' => 1,
            'history' => null, 'is_all_location' => 1, 'is_folder_permission' => $o['perm'] ?? 0, 'is_active' => 1,
            'is_verified' => 0, 'verified_at' => null, 'verified_by' => null,
            'created_at' => $nowStr, 'created_by' => $by, 'updated_at' => $nowStr, 'updated_by' => $by,
        ]);
        if ($type == 2) {
            $c->table('archive_documents')->insert([
                'id_archive_document' => $gen(), 'id_archive' => $id, 'id_transaction' => 'QA28TX' . substr($id, -10),
                'transaction_no' => $name, 'transaction_type' => $txType, 'related_employee_name' => 'Sales QA28',
                'created_at' => $nowStr, 'updated_at' => $nowStr,
            ]);
        }
        $ids[$name] = $id;

        return $id;
    };

    $user = $c->table('users')->where('username', Config::get('QA_USER'))->first();
    if (!$user) {
        fail('QA_USER tidak ditemukan di DB');
    }

    $c->transaction(function () use ($add, $c, $user, $gen, $nowStr, $by) {
        $alfa = $add(PREFIX . 'ALFA', 1, null);
        $add(PREFIX . 'A1', 2, $alfa, 6);
        $add(PREFIX . 'A2', 2, $alfa, 7);
        $s1 = $add(PREFIX . 'ALFA-S1', 1, $alfa);
        $add(PREFIX . 'S1a', 2, $s1, 6);
        $x = $add(PREFIX . 'ALFA-S1-X', 1, $s1);
        $add(PREFIX . 'X1', 2, $x, 6);
        $s2 = $add(PREFIX . 'ALFA-S2', 1, $alfa);
        $add(PREFIX . 'S2a', 2, $s2, 7);

        $bravo = $add(PREFIX . 'BRAVO', 1, null);
        $add(PREFIX . 'B1', 2, $bravo, 6);
        $t1 = $add(PREFIX . 'BRAVO-T1', 1, $bravo);
        $add(PREFIX . 'T1a', 2, $t1, 7);

        $gamma = $add(PREFIX . 'GAMMA', 1, null);
        $add(PREFIX . 'GD1', 2, $gamma, 6);
        $add(PREFIX . 'GD2', 2, $gamma, 7);
        $add(PREFIX . 'GD3', 2, $gamma, 8);

        $add(PREFIX . 'EMPTYF', 1, null);

        $secret = $add(PREFIX . 'SECRET', 1, null, 6, ['perm' => 1]);
        $add(PREFIX . 'H1', 2, $secret, 6);
        $add(PREFIX . 'H2', 2, $secret, 7);

        $vo = $add(PREFIX . 'VIEWONLY', 1, null, 6, ['perm' => 1]);
        $add(PREFIX . 'VD1', 2, $vo, 6);
        $c->table('archive_permissions')->insert([
            'id_archive_permission' => $gen(), 'id_archive' => $vo, 'id_user' => $user->id_user,
            'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0,
            'created_at' => $nowStr, 'created_by' => $by, 'updated_at' => $nowStr, 'updated_by' => $by,
        ]);

        $add(PREFIX . 'RD', 2, null, 6);
    });
    journalAdd($journal, 'archives', array_values($ids));

    $ids['_user'] = ['username' => Config::get('QA_USER'), 'username2' => Config::get('QA_USER2')];
    file_put_contents($idsFile, json_encode($ids));
    say('fixture up: ' . (count($ids) - 1) . ' entri archive; archives=' . $before['archives']['count'] . ' opname=' . $before['archive_opnames']['count'] . ' (tanpa sesi)');
    exit(0);
}

if ($cmd === 'reset-sessions') {
    $j = journalRead($journal);
    if ($j === null || ($j['v'] ?? 0) !== JOURNAL_V) {
        fail('tidak ada jurnal fixture (jalankan up dulu): tidak ada yang dihapus');
    }
    $sessions = resetOwnSessions($c, $j);
    if (is_file($idsFile)) {
        $ids = json_decode(file_get_contents($idsFile), true);
        unset($ids['_sessions']);
        file_put_contents($idsFile, json_encode($ids));
    }
    say('fixture reset-sessions: ' . count($sessions) . ' sesi milik fixture dibuang (sesi lain tidak disentuh)');
    exit(0);
}

if ($cmd === 'sessions' || $cmd === 'ensure-sessions') {
    if (!is_file($idsFile)) {
        fail('jalankan up dulu');
    }
    $ids = json_decode(file_get_contents($idsFile), true);
    $j = journalRead($journal);
    if ($j === null || ($j['v'] ?? 0) !== JOURNAL_V) {
        fail('tidak ada jurnal fixture: jalankan up dulu');
    }
    $owned = fixtureSessionIds($c, $j, fixtureArchiveIds($c, $j)); // sesi developer tidak dihitung
    $seeded = array_values(array_intersect($owned, $j['sessions'] ?? []));
    $extra = array_values(array_diff($owned, $seeded)); // dibuat aplikasi selama run (mis. Confirm Opname GAMMA lewat UI)
    if ($cmd === 'ensure-sessions') {
        if (count($seeded) === SEEDED_N && !$extra && isset($ids['_sessions'])) {
            say('fixture ensure-sessions: ' . SEEDED_N . ' sesi uji ada, tidak ada perubahan');
            exit(0);
        }
        if ($owned) { // keadaan menyimpang (kurang/lebih/sesi nyata): bereskan HANYA milik fixture lalu isi ulang
            resetOwnSessions($c, $j);
            say('fixture ensure-sessions: keadaan menyimpang (' . count($seeded) . ' sesi uji + ' . count($extra) . ' sesi nyata) dibereskan, diisi ulang');
        }
    } elseif ($owned) {
        fail('sudah ada ' . count($owned) . ' sesi milik fixture sebelum sesi fixture (jalankan reset-sessions dulu)');
    }
    $P = PREFIX;
    $sessions = [];

    $session = function ($key, $idArchive, $scopeName, $scopeCount, $at, $createdBy, $n, $status = 2) use ($c, $gen, &$sessions, $journal) {
        // $n = [total, verified, notFound, invalid, scanned, unscanned]
        $sid = $gen();
        journalAdd($journal, 'sessions', [$sid]); // dicatat dulu: sesi root (N02) tidak bisa dikenali dari foldernya
        $c->table('archive_opnames')->insert([
            'id_archive_opname' => $sid, 'id_archive' => $idArchive, 'status' => $status, 'scope_name' => $scopeName,
            'scope_folder_count' => $scopeCount, 'total_documents' => $n[0], 'verified_before_count' => 0,
            'scanned_count' => $n[4], 'verified_count' => $n[1], 'not_found_count' => $n[2], 'invalid_count' => $n[3],
            'unscanned_count' => $n[5], 'unverified_count' => 0,
            'selected_at' => $at, 'confirmed_at' => $status === 2 ? $at : null, 'created_at' => $at,
            'created_by' => $createdBy, 'updated_at' => $at, 'updated_by' => $createdBy,
        ]);
        $sessions[$key] = $sid;

        return $sid;
    };
    $folderRow = function ($sid, $level, $name, $idArchive, $parent, $at) use ($c, $gen) {
        $c->table('archive_opname_folders')->insert([
            'id_archive_opname_folder' => $gen(), 'id_archive_opname' => $sid, 'id_archive' => $idArchive,
            'id_archive_parent' => $parent, 'name' => $name, 'level' => $level, 'is_continue' => 0, 'is_opnamed_today' => 0,
            'total_documents' => 1, 'verified_count' => 0, 'verified_after_count' => 0, 'unverified_count' => 0, 'created_at' => $at,
        ]);
    };
    // $result: 1 verified, 2 not found, 3 invalid, 4 unscanned
    $docRow = function ($sid, $result, $code, $idDoc, $idFolder, $at) use ($c, $gen) {
        $c->table('archive_opname_documents')->insert([
            'id_archive_opname_document' => $gen(), 'id_archive_opname' => $sid, 'id_archive' => $idDoc,
            'id_archive_folder' => $idFolder, 'code' => $code, 'result' => $result,
            'is_verified_after' => $result === 1 ? 1 : 0, 'scanned_at' => $result === 4 ? null : $at . '.000000', 'created_at' => $at,
        ]);
    };

    $c->transaction(function () use ($ids, $P, $session, $folderRow, $docRow) {
        $f = function ($k) use ($ids, $P) {
            return $ids[$P . $k];
        };

        // N01: opname di ALFA dengan 2 subfolder dicentang (S1 + S2; X ikut otomatis): scope "0QA28-ALFA + 2 subfolder", hasil lengkap
        $at = '2031-10-06 16:40:00';
        $s = $session('N01', $f('ALFA'), $P . 'ALFA', 2, $at, 'qa28alice', [5, 3, 1, 1, 5, 2]);
        $folderRow($s, 0, $P . 'ALFA', $f('ALFA'), null, $at);
        $folderRow($s, 1, $P . 'ALFA-S1', $f('ALFA-S1'), $f('ALFA'), $at);
        $folderRow($s, 1, $P . 'ALFA-S2', $f('ALFA-S2'), $f('ALFA'), $at);
        $folderRow($s, 2, $P . 'ALFA-S1-X', $f('ALFA-S1-X'), $f('ALFA-S1'), $at);
        $docRow($s, 1, $P . 'A1', $f('A1'), $f('ALFA'), $at);
        $docRow($s, 1, $P . 'S1a', $f('S1a'), $f('ALFA-S1'), $at);
        $docRow($s, 1, $P . 'X1', $f('X1'), $f('ALFA-S1-X'), $at);
        $docRow($s, 2, $P . 'B1', $f('B1'), null, $at);            // not found: dokumen di luar scope opname
        $docRow($s, 3, $P . 'INV-001', null, null, $at);           // invalid: kode tak dikenal
        $docRow($s, 4, $P . 'A2', $f('A2'), $f('ALFA'), $at);      // belum discan
        $docRow($s, 4, $P . 'S2a', $f('S2a'), $f('ALFA-S2'), $at);

        // N02: sesi root oleh "superadmin"; mencakup SECRET yang tak terlihat user QA/user2 -> hasil parsial
        $at = '2031-10-05 09:15:00';
        $s = $session('N02', null, null, 0, $at, 'qa28root', [8, 3, 1, 1, 5, 2]);
        $folderRow($s, 1, $P . 'ALFA', $f('ALFA'), null, $at);
        $folderRow($s, 1, $P . 'BRAVO', $f('BRAVO'), null, $at);
        $folderRow($s, 1, $P . 'SECRET', $f('SECRET'), null, $at);
        $folderRow($s, 2, $P . 'BRAVO-T1', $f('BRAVO-T1'), $f('BRAVO'), $at);
        $docRow($s, 1, $P . 'B1', $f('B1'), $f('BRAVO'), $at);
        $docRow($s, 1, $P . 'T1a', $f('T1a'), $f('BRAVO-T1'), $at);
        $docRow($s, 1, $P . 'H1', $f('H1'), $f('SECRET'), $at);    // tersembunyi bagi user QA/user2
        $docRow($s, 2, $P . 'S2a', $f('S2a'), null, $at);          // not found
        $docRow($s, 3, $P . 'INV-002', null, null, $at);           // invalid
        $docRow($s, 4, $P . 'A1', $f('A1'), $f('ALFA'), $at);      // belum discan
        $docRow($s, 4, $P . 'H2', $f('H2'), $f('SECRET'), $at);    // tersembunyi bagi user QA/user2

        // N03..N13: satu folder tiap sesi; elemen ke-6 = scope_folder_count (subfolder dicentang)
        $rows = [
            ['N03', 'BRAVO', '2031-10-04 14:30:00', 'qa28bob', [1, 1, 0, 0, 1, 0], 0],
            ['N04', 'BRAVO-T1', '2031-10-03 11:05:00', 'qa28carol', [1, 1, 0, 0, 1, 0], 0],
            ['N05', 'ALFA-S2', '2031-10-02 08:20:00', 'qa28alice', [1, 1, 0, 0, 1, 0], 0],
            ['N06', 'BRAVO', '2031-10-01 17:45:00', 'qa28dave', [2, 1, 0, 1, 2, 1], 0],
            ['N07', 'ALFA-S1', '2031-09-30 10:10:00', 'qa28erin', [2, 2, 0, 0, 2, 0], 0],
            ['N08', 'BRAVO', '2031-09-29 13:00:00', 'qa28bob', [2, 1, 1, 0, 2, 1], 1],
            ['N09', 'ALFA', '2031-09-28 09:00:00', 'qa28carol', [3, 2, 0, 0, 2, 1], 0],
            ['N10', 'BRAVO', '2031-09-27 15:30:00', 'qa28dave', [2, 2, 0, 0, 2, 0], 0],
            ['N11', 'ALFA-S1-X', '2031-09-26 12:00:00', 'qa28alice', [1, 1, 0, 0, 1, 0], 0],
            ['N12', 'BRAVO', '2031-09-25 08:00:00', 'qa28erin', [2, 1, 0, 0, 1, 1], 0],
            ['N13', 'ALFA', '2031-09-24 07:30:00', 'qa28bob', [3, 3, 0, 0, 3, 0], 0],
        ];
        foreach ($rows as [$key, $folder, $at, $by, $n, $cnt]) {
            $s = $session($key, $f($folder), $P . $folder, $cnt, $at, $by, $n);
            $folderRow($s, 0, $P . $folder, $f($folder), null, $at);
        }

        // N14: folder VIEWONLY (user QA hanya View; user2 tidak punya hak, jadi sesi ini tak terlihat user2)
        $at = '2031-09-23 06:00:00';
        $s = $session('N14', $f('VIEWONLY'), $P . 'VIEWONLY', 0, $at, 'qa28view', [1, 1, 0, 0, 1, 0]);
        $folderRow($s, 0, $P . 'VIEWONLY', $f('VIEWONLY'), null, $at);

        // HID: terkonfirmasi tetapi hanya mencakup SECRET (tak terlihat user QA/user2). Tidak ada draft/sesi batal: tidak ada lagi di DB (ED-1026 FB-3)
        $at = '2031-10-06 20:00:00';
        $s = $session('HID', $f('SECRET'), $P . 'SECRET', 0, $at, 'qa28hid', [2, 1, 0, 0, 1, 1]);
        $folderRow($s, 0, $P . 'SECRET', $f('SECRET'), null, $at);
    });

    $ids['_sessions'] = $sessions;
    file_put_contents($idsFile, json_encode($ids));
    say('fixture sessions: ' . count($sessions) . ' sesi 2031 (14 terkonfirmasi terlihat user QA, 13 terlihat user2 + N14 hanya user QA; 1 tersembunyi HID)');
    exit(0);
}

if ($cmd === 'down') {
    $gl = grantRevoke($c, $grantJournal); // sisa grant sementara (run yang mati) dicabut dulu
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
        $lines[] = 'BEDA baris display setting: tidak sama dengan snapshot awal';
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
    $out = [];
    foreach (displayRows($c) as $row) {
        $out[$row['module_name']] = array_map(function ($x) {
            return $x['data_index'];
        }, json_decode($row['current_columns'], true));
    }
    echo json_encode($out) . "\n";
    exit(0);
}

if ($cmd === 'inspect') {
    $docs = [];
    $q = $c->table('archives')->whereRaw('`name` LIKE BINARY ?', [PREFIX . '%'])->where('type', 2)->orderBy('name')->get();
    foreach ($q as $r) {
        $docs[$r->name] = ['is_verified' => (int) $r->is_verified, 'has_opname' => $r->id_archive_opname !== null];
    }
    $sessions = [];
    $j = journalRead($journal);
    $own = $j ? fixtureSessionIds($c, $j, fixtureArchiveIds($c, $j)) : []; // hanya sesi milik fixture
    foreach ($own ? $c->table('archive_opnames')->whereIn('id_archive_opname', $own)->orderBy('created_at')->orderBy('id_archive_opname')->get() : [] as $s) {
        $sessions[] = [
            'id' => $s->id_archive_opname, 'folder' => $s->id_archive ? $c->table('archives')->where('id_archive', $s->id_archive)->value('name') : null,
            'status' => (int) $s->status, 'scope_name' => $s->scope_name, 'scope_folder_count' => (int) $s->scope_folder_count, 'total' => (int) $s->total_documents,
            'verified' => (int) $s->verified_count, 'not_found' => (int) $s->not_found_count, 'invalid' => (int) $s->invalid_count,
            'scanned' => (int) $s->scanned_count, 'unscanned' => (int) $s->unscanned_count,
            'confirmed_at' => $s->confirmed_at, 'created_by' => $s->created_by,
            'doc_rows' => $c->table('archive_opname_documents')->where('id_archive_opname', $s->id_archive_opname)->count(),
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

fail('pakai: up | sessions | ensure-sessions | reset-sessions | down | ids | inspect | display | grant-od | revoke-od');
