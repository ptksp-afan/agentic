<?php
/**
 * ED-1026 (rev 2026-10-09, opname tanpa draft) - AC-1 (skema + dua Updater: terdaftar, 2x tanpa error, objek ada, pembersih
 * draft di DB scratch dan DB QA), AC-2 (seed 'Opname Document' id 1139 hanya di permission_salesman.sql + DB + SQL seed di DB
 * scratch), EXTRA-LANG (kode pesan en_EN/id_ID sesuai kontrak; kode lama dihapus; respons mengikuti bahasa user).
 * Tukar lisensi sungguhan = MANUAL Gate 2 epic (PROFILES kosong, EPIC K-2 b).
 * Data developer di DB uji dijaga: tidak ada asersi "tabel opname kosong"; Updater pembersih hanya dijalankan di DB QA bila tidak
 * ada sesi status 1/3 milik non-QA (kalau ada: dibuktikan di DB scratch saja).
 */
require_once __DIR__ . '/qa_lib.php';

/** [kolom => [tipe-dasar, nullable(bool), default|null|'*' bebas]] */
$spec = [
    'archive_opnames' => [
        'id_archive_opname' => ['varchar(30)', false, null], 'id_archive' => ['varchar(30)', true, null],
        'status' => ['tinyint', true, '1'], 'scope_name' => ['varchar(255)', true, null],
        'scope_folder_count' => ['int', true, '0'], 'total_documents' => ['int', true, '0'],
        'verified_before_count' => ['int', true, '0'], 'scanned_count' => ['int', true, '0'],
        'verified_count' => ['int', true, '0'], 'not_found_count' => ['int', true, '0'], 'invalid_count' => ['int', true, '0'],
        'unscanned_count' => ['int', true, '0'], 'unverified_count' => ['int', true, '0'],
        'selected_at' => ['datetime', true, null], 'confirmed_at' => ['datetime', true, null],
        'created_at' => ['datetime', true, '*'], 'created_by' => ['varchar(30)', true, '*'],
        'updated_at' => ['datetime', true, '*'], 'updated_by' => ['varchar(30)', true, '*'],
    ],
    'archive_opname_folders' => [
        'id_archive_opname_folder' => ['varchar(30)', false, null], 'id_archive_opname' => ['varchar(30)', false, null],
        'id_archive' => ['varchar(30)', true, null], 'id_archive_parent' => ['varchar(30)', true, null],
        'name' => ['varchar(255)', true, null], 'level' => ['tinyint', true, '*'], 'is_continue' => ['tinyint', true, '0'],
        'is_opnamed_today' => ['tinyint', true, '0'], 'total_documents' => ['int', true, '0'], 'verified_count' => ['int', true, '0'],
        'verified_after_count' => ['int', true, '0'], 'unverified_count' => ['int', true, '0'], 'created_at' => ['datetime', true, '*'],
    ],
    'archive_opname_documents' => [
        'id_archive_opname_document' => ['varchar(30)', false, null], 'id_archive_opname' => ['varchar(30)', false, null],
        'id_archive' => ['varchar(30)', true, null], 'id_archive_folder' => ['varchar(30)', true, null],
        'code' => ['varchar(255)', true, '*'], 'result' => ['tinyint', true, '*'], 'is_verified_after' => ['tinyint', true, null],
        'scanned_at' => ['datetime', true, null], 'created_at' => ['datetime', true, '*'],
    ],
];

/** SHOW COLUMNS -> [kolom => [tipe, nullable, default]] */
$columns = function ($c, $table) {
    $out = [];
    foreach ($c->select("SHOW COLUMNS FROM `$table`") as $r) {
        $out[$r->Field] = [$r->Type, $r->Null === 'YES', $r->Default];
    }

    return $out;
};

/** SHOW INDEX -> [nama => ['cols' => [..], 'unique' => bool]] */
$indexes = function ($c, $table) {
    $idx = [];
    foreach ($c->select("SHOW INDEX FROM `$table`") as $r) {
        $idx[$r->Key_name]['cols'][(int) $r->Seq_in_index] = $r->Column_name;
        $idx[$r->Key_name]['unique'] = (int) $r->Non_unique === 0;
    }

    return $idx;
};

/** Cari indeks (nama bebas) dengan kolom persis $cols (urut) dan unique sesuai. */
$hasIndex = function (array $idx, array $cols, $unique) {
    foreach ($idx as $name => $i) {
        if ($name !== 'PRIMARY' && array_values($i['cols']) === $cols && $i['unique'] === $unique) {
            return $name;
        }
    }

    return null;
};

return [

    [
        'id'    => 'AC-1',
        'title' => 'Skema: 4 kolom + indeks id_archive_parent di archives, 3 tabel opname; Updater AddArchiveOpname & DeleteArchiveOpnameDraft terdaftar di config.php, 2x tanpa error; pembersih draft (DB scratch + DB QA): 0 sesi status 1/3, 0 baris anak tanpa sesi, sesi status 2 & archives tak berubah',
        'run'   => function ($t) use ($spec, $columns, $indexes, $hasIndex) {
            $c = $t->db();
            $db = q26_dbname($t);

            // --- archives: 4 kolom baru, tepat setelah is_active (asersi per objek, bukan SHOW CREATE utuh)
            $cols = $columns($c, 'archives');
            foreach (['is_verified', 'verified_at', 'verified_by', 'id_archive_opname'] as $k) {
                $t->true(isset($cols[$k]), "archives.$k ada");
            }
            $t->true(preg_match('/^tinyint\(\d+\)$/', $cols['is_verified'][0]) === 1, 'is_verified tinyint');
            $t->eq($cols['is_verified'][2], '0', 'is_verified default 0');
            $t->eq($cols['verified_at'][0], 'datetime', 'verified_at datetime');
            $t->true($cols['verified_at'][1] && $cols['verified_at'][2] === null, 'verified_at NULL default NULL');
            $t->eq($cols['verified_by'][0], 'varchar(30)', 'verified_by varchar(30)');
            $t->true($cols['verified_by'][1], 'verified_by nullable');
            $t->eq($cols['id_archive_opname'][0], 'varchar(30)', 'id_archive_opname varchar(30)');
            $t->true($cols['id_archive_opname'][1], 'id_archive_opname nullable');
            $order = array_keys($cols);
            $t->eq(array_search('is_verified', $order), array_search('is_active', $order) + 1, 'is_verified tepat sesudah is_active');
            $t->eq(array_slice($order, array_search('is_verified', $order), 4), ['is_verified', 'verified_at', 'verified_by', 'id_archive_opname'], 'urutan 4 kolom');

            $aidx = $indexes($c, 'archives');
            $t->true($hasIndex($aidx, ['id_archive_parent'], false) !== null, 'archives punya indeks (id_archive_parent)');

            // data developer boleh punya dokumen verified: yang dijaga nilai bawaan kolom dan tidak ada NULL / nilai liar
            $t->eq($c->table('archives')->where(function ($q) {
                $q->whereNull('is_verified')->orWhereNotIn('is_verified', [0, 1]);
            })->count(), 0, 'archives.is_verified hanya 0/1 (tanpa NULL)');

            // --- 3 tabel opname
            $arch = $c->selectOne("SHOW TABLE STATUS LIKE 'archives'");
            foreach ($spec as $table => $expected) {
                $got = $columns($c, $table);
                $t->eq(array_keys($got), array_keys($expected), "$table: nama & urutan kolom");
                foreach ($expected as $col => $e) {
                    $g = $got[$col];
                    $norm = function ($type) {
                        return strpos($type, 'varchar') === 0 ? $type : preg_replace('/\(\d+\)/', '', $type);
                    };
                    if ($table === 'archive_opname_documents' && $col === 'scanned_at') {
                        // urutan scan terbaru dulu pasti: DATETIME(6) (ronde 2 ED-1026)
                        $t->eq($g[0], 'datetime(6)', "$table.$col tipe datetime(6)");
                    } else {
                        $t->eq($norm($g[0]), $norm($e[0]), "$table.$col tipe");
                    }
                    $t->eq($g[1], $e[1], "$table.$col nullable");
                    if ($e[2] !== '*') {
                        $t->eq($g[2], $e[2], "$table.$col default");
                    }
                }
                $st = $c->selectOne("SHOW TABLE STATUS LIKE '$table'");
                $t->eq($st->Collation, 'latin1_general_ci', "$table collation latin1_general_ci");
                $t->eq($st->Collation, $arch->Collation, "$table collation = archives");
                $t->eq($st->Engine, 'InnoDB', "$table engine InnoDB");
            }

            $i1 = $indexes($c, 'archive_opnames');
            $t->eq(array_values($i1['PRIMARY']['cols']), ['id_archive_opname'], 'archive_opnames PK');
            $t->true($hasIndex($i1, ['status', 'confirmed_at'], false) !== null, 'archive_opnames KEY (status, confirmed_at)');
            $t->true($hasIndex($i1, ['created_by'], false) !== null, 'archive_opnames KEY created_by');
            $i2 = $indexes($c, 'archive_opname_folders');
            $t->eq(array_values($i2['PRIMARY']['cols']), ['id_archive_opname_folder'], 'archive_opname_folders PK');
            $t->true($hasIndex($i2, ['id_archive_opname'], false) !== null, 'archive_opname_folders KEY id_archive_opname');
            $t->true($hasIndex($i2, ['id_archive', 'id_archive_opname'], false) !== null, 'archive_opname_folders KEY (id_archive, id_archive_opname)');
            $i3 = $indexes($c, 'archive_opname_documents');
            $t->eq(array_values($i3['PRIMARY']['cols']), ['id_archive_opname_document'], 'archive_opname_documents PK');
            $t->true($hasIndex($i3, ['id_archive_opname', 'code'], true) !== null, 'archive_opname_documents UNIQUE (id_archive_opname, code)');
            $t->true($hasIndex($i3, ['id_archive_opname', 'result'], false) !== null, 'archive_opname_documents KEY (id_archive_opname, result)');
            $t->true($hasIndex($i3, ['id_archive'], false) !== null, 'archive_opname_documents KEY id_archive');

            // --- dua Updater: berkas tunggal, terdaftar di config.php (TIDAK harus kunci terbesar), Delete jalan sesudah Add
            $beDir = $t->probe(function () {
                return base_path();
            });
            $config = include $beDir . '/Modules/UpdateVersion/Updaters/config.php';
            $files = [];
            $keys = [];
            foreach (['AddArchiveOpname', 'DeleteArchiveOpnameDraft'] as $name) {
                $found = glob($beDir . '/Modules/UpdateVersion/Updaters/*_' . $name . '.php');
                $t->eq(count($found), 1, "tepat satu berkas Updater $name");
                $files[$name] = $found[0];
                $class = 'Modules\\UpdateVersion\\Updaters\\' . basename($found[0], '.php');
                $hits = array_keys($config, $class, true);
                $t->eq(count($hits), 1, "Updater $name terdaftar tepat sekali di config.php");
                $keys[$name] = $hits[0];
                $t->true(is_int($hits[0]) && $hits[0] > 0, "Updater $name: kunci config.php bilangan (urutan jalan)");
            }
            $t->true($keys['DeleteArchiveOpnameDraft'] > $keys['AddArchiveOpname'], 'DeleteArchiveOpnameDraft jalan sesudah AddArchiveOpname (kunci lebih besar dari pasangannya, bukan dari semua Updater)');
            $implements = $t->probe(function () use ($files) {
                $out = [];
                foreach ($files as $name => $f) {
                    $o = require $f;
                    $out[$name] = $o instanceof \Modules\UpdateVersion\Contracts\Updater;
                }

                return $out;
            });
            $t->eq($implements, ['AddArchiveOpname' => true, 'DeleteArchiveOpnameDraft' => true], 'kedua berkas Updater mengembalikan objek Updater');

            // --- AddArchiveOpname 2x di DB QA: skema tak berubah
            $snapshot = function () use ($c) {
                $out = [];
                foreach (['archive_opnames', 'archive_opname_folders', 'archive_opname_documents'] as $tb) {
                    $out[$tb] = (array) $c->selectOne("SHOW CREATE TABLE `$tb`");
                }
                $out['archives_cols'] = array_map(function ($r) {
                    return (array) $r;
                }, $c->select('SHOW COLUMNS FROM archives'));
                $out['archives_idx'] = array_map(function ($r) {
                    return [$r->Key_name, $r->Column_name];
                }, $c->select('SHOW INDEX FROM archives'));

                return json_encode($out);
            };
            $before = $snapshot();
            $stateBefore = q26_devstate($t);
            $error = null;
            $t->probe(function () use ($db, $files, &$error) {
                \Illuminate\Support\Facades\DB::setDefaultConnection($db);
                try {
                    $updater = require $files['AddArchiveOpname'];
                    $updater->run();
                    $updater->run();
                } catch (\Throwable $e) {
                    $error = get_class($e) . ': ' . $e->getMessage();
                }
            });
            $t->eq($error, null, 'AddArchiveOpname dijalankan 2x tanpa error (DB QA)');
            $t->true($before === $snapshot(), 'skema archives + 3 tabel tidak berubah oleh run ulang AddArchiveOpname');
            $t->true(q26_same_state($stateBefore, q26_devstate($t)), 'data opname & kolom verifikasi developer tidak berubah oleh AddArchiveOpname');

            // --- DeleteArchiveOpnameDraft di DB QA: hanya bila tidak ada sesi status 1/3 milik non-QA (data developer tidak dihapus)
            $drafts = $c->table('archive_opnames')->whereIn('status', [1, 3])->count();
            $orphanSql = function () use ($c) {
                $f = $c->selectOne('SELECT COUNT(*) n FROM archive_opname_folders x LEFT JOIN archive_opnames o ON o.id_archive_opname = x.id_archive_opname WHERE o.id_archive_opname IS NULL')->n;
                $d = $c->selectOne('SELECT COUNT(*) n FROM archive_opname_documents x LEFT JOIN archive_opnames o ON o.id_archive_opname = x.id_archive_opname WHERE o.id_archive_opname IS NULL')->n;

                return [(int) $f, (int) $d];
            };
            if ($drafts === 0) {
                $stateBefore = q26_devstate($t);
                $error = null;
                $t->probe(function () use ($db, $files, &$error) {
                    \Illuminate\Support\Facades\DB::setDefaultConnection($db);
                    try {
                        $updater = require $files['DeleteArchiveOpnameDraft'];
                        $updater->run();
                        $updater->run();
                    } catch (\Throwable $e) {
                        $error = get_class($e) . ': ' . $e->getMessage();
                    }
                });
                $t->eq($error, null, 'DeleteArchiveOpnameDraft dijalankan 2x tanpa error (DB QA, tanpa draft)');
                $t->eq($c->table('archive_opnames')->whereIn('status', [1, 3])->count(), 0, 'DB QA: 0 sesi status 1/3');
                $t->eq($orphanSql(), [0, 0], 'DB QA: 0 baris _folders/_documents tanpa sesi');
                $t->true(q26_same_state($stateBefore, q26_devstate($t)), 'DB QA: sesi status 2 (dan semua baris opname) + kolom verifikasi archives tidak berubah');
            } else {
                $t->note("DB QA punya $drafts sesi status 1/3 milik non-QA: DeleteArchiveOpnameDraft TIDAK dijalankan di DB QA (data developer tidak dihapus); pembuktian di DB scratch saja");
            }

            // --- DB scratch: AddArchiveOpname dari nol (SHOW CREATE identik), lalu DeleteArchiveOpnameDraft pada data campuran
            $scratch = 'qa26_scratch_' . substr(uniqid(), -6);
            $error = null;
            $diff = null;
            $clean = null;
            $t->probe(function () use ($db, $files, $scratch, &$error, &$diff, &$clean) {
                $src = \Illuminate\Support\Facades\DB::connection($db);
                $cfg = $src->getConfig();
                $src->statement("CREATE DATABASE `$scratch` CHARACTER SET latin1 COLLATE latin1_general_ci");
                try {
                    $cfg['database'] = $scratch;
                    config(['database.connections.qa26scratch' => $cfg]);
                    \Illuminate\Support\Facades\DB::purge('qa26scratch');
                    $s = \Illuminate\Support\Facades\DB::connection('qa26scratch');
                    $s->statement("CREATE TABLE archives LIKE `$db`.archives");
                    $s->statement('ALTER TABLE archives DROP COLUMN IF EXISTS is_verified, DROP COLUMN IF EXISTS verified_at, DROP COLUMN IF EXISTS verified_by, DROP COLUMN IF EXISTS id_archive_opname');
                    $s->statement('ALTER TABLE archives DROP INDEX IF EXISTS id_archive_parent');
                    \Illuminate\Support\Facades\DB::setDefaultConnection('qa26scratch');
                    $add = require $files['AddArchiveOpname'];
                    $add->run();
                    $add->run();

                    $norm = function ($conn, $table) {
                        $row = (array) $conn->selectOne("SHOW CREATE TABLE `$table`");
                        $sql = array_values($row)[1];

                        return preg_replace('/ AUTO_INCREMENT=\d+/', '', $sql);
                    };
                    $diff = [];
                    foreach (['archives', 'archive_opnames', 'archive_opname_folders', 'archive_opname_documents'] as $tb) {
                        $diff[$tb] = $norm($s, $tb) === $norm($src, $tb);
                    }

                    // data campuran: draft (1), batal (3), terkonfirmasi (2) + dokumen archives yang menunjuk sesi terkonfirmasi
                    $now = '2026-10-09 08:00:00';
                    foreach (['D1' => 1, 'C1' => 3, 'K1' => 2, 'D2' => 1] as $sid => $status) {
                        $s->table('archive_opnames')->insert(['id_archive_opname' => $sid, 'id_archive' => null, 'status' => $status, 'selected_at' => $now, 'confirmed_at' => $status === 2 ? $now : null, 'created_at' => $now, 'created_by' => 'x', 'updated_at' => $now, 'updated_by' => 'x']);
                        foreach (['f1', 'f2'] as $f) {
                            $s->table('archive_opname_folders')->insert(['id_archive_opname_folder' => "$sid$f", 'id_archive_opname' => $sid, 'name' => "n$f", 'level' => 0, 'created_at' => $now]);
                        }
                        foreach (['d1', 'd2', 'd3'] as $d) {
                            $s->table('archive_opname_documents')->insert(['id_archive_opname_document' => "$sid$d", 'id_archive_opname' => $sid, 'code' => "$sid-$d", 'result' => 1, 'scanned_at' => '2026-10-09 08:00:00.000123', 'created_at' => $now]);
                        }
                    }
                    $s->table('archives')->insert(['id_archive' => 'A1', 'name' => 'A1', 'type' => 2, 'is_verified' => 1, 'verified_at' => $now, 'verified_by' => 'x', 'id_archive_opname' => 'K1', 'is_active' => 1]);
                    $sum = function () use ($s) {
                        return [
                            'k_op' => json_encode($s->table('archive_opnames')->where('id_archive_opname', 'K1')->get()->all()),
                            'k_fo' => json_encode($s->table('archive_opname_folders')->where('id_archive_opname', 'K1')->orderBy('id_archive_opname_folder')->get()->all()),
                            'k_do' => json_encode($s->table('archive_opname_documents')->where('id_archive_opname', 'K1')->orderBy('id_archive_opname_document')->get()->all()),
                            'archives' => json_encode($s->table('archives')->orderBy('id_archive')->get()->all()),
                        ];
                    };
                    $keep = $sum();
                    $del = require $files['DeleteArchiveOpnameDraft'];
                    $del->run();
                    $after1 = [
                        'sessions' => $s->table('archive_opnames')->orderBy('id_archive_opname')->pluck('status', 'id_archive_opname')->all(),
                        'folders' => $s->table('archive_opname_folders')->count(),
                        'documents' => $s->table('archive_opname_documents')->count(),
                        'orphan_f' => (int) $s->selectOne('SELECT COUNT(*) n FROM archive_opname_folders x LEFT JOIN archive_opnames o ON o.id_archive_opname = x.id_archive_opname WHERE o.id_archive_opname IS NULL')->n,
                        'orphan_d' => (int) $s->selectOne('SELECT COUNT(*) n FROM archive_opname_documents x LEFT JOIN archive_opnames o ON o.id_archive_opname = x.id_archive_opname WHERE o.id_archive_opname IS NULL')->n,
                        'keep_same' => $sum() === $keep,
                    ];
                    $del->run();
                    $after2 = ['keep_same' => $sum() === $keep, 'sessions' => $s->table('archive_opnames')->count()];
                    // tenant tanpa tabel opname: dilewati tanpa error
                    $s->statement('DROP TABLE archive_opname_documents');
                    $s->statement('DROP TABLE archive_opname_folders');
                    $s->statement('DROP TABLE archive_opnames');
                    $del->run();
                    $clean = ['after1' => $after1, 'after2' => $after2, 'no_tables' => true];
                } catch (\Throwable $e) {
                    $error = get_class($e) . ': ' . substr($e->getMessage(), 0, 300);
                } finally {
                    \Illuminate\Support\Facades\DB::setDefaultConnection($db);
                    $src->statement("DROP DATABASE IF EXISTS `$scratch`");
                    \Illuminate\Support\Facades\DB::purge('qa26scratch');
                }
            });
            $t->eq($error, null, 'Updater di DB scratch (Add dari nol 2x, Delete pada data campuran, tanpa tabel) tanpa error');
            $t->eq(json_encode($diff), json_encode(['archives' => true, 'archive_opnames' => true, 'archive_opname_folders' => true, 'archive_opname_documents' => true]),
                'SHOW CREATE TABLE hasil AddArchiveOpname dari nol identik dengan DB QA (archives + 3 tabel)');
            $a1 = $clean['after1'] ?? [];
            $t->eq($a1['sessions'] ?? null, ['K1' => 2], 'scratch: hanya sesi status 2 (K1) tersisa; draft (D1, D2) dan batal (C1) dihapus');
            $t->eq([$a1['folders'] ?? null, $a1['documents'] ?? null], [2, 3], 'scratch: baris _folders/_documents tinggal milik K1 (2 + 3)');
            $t->eq([$a1['orphan_f'] ?? null, $a1['orphan_d'] ?? null], [0, 0], 'scratch: 0 baris _folders/_documents tanpa sesi');
            $t->true(($a1['keep_same'] ?? false) === true, 'scratch: sesi, folder, dokumen K1 dan baris archives (id_archive_opname K1) tidak berubah');
            $t->true(($clean['after2']['keep_same'] ?? false) === true && ($clean['after2']['sessions'] ?? null) === 1, 'scratch: run ke-2 idempoten (K1 sama, 1 sesi)');
            $t->true(($clean['no_tables'] ?? false) === true, 'scratch: tenant tanpa tabel opname dilewati tanpa error');
            $left = $t->db()->selectOne("SELECT COUNT(*) n FROM information_schema.schemata WHERE schema_name LIKE 'qa26_scratch_%'")->n;
            $t->eq($left, 0, 'DB scratch dibuang');
        },
    ],

    [
        'id'    => 'AC-2',
        'title' => "Seed: permission 'Opname Document' id 1139 (module 1266) + checks/unchecks hanya di permission_salesman.sql; tak ada baris Archive id 1133; DB QA punya barisnya (dicari per nama); SQL seed dijalankan utuh di DB scratch. Tanpa lisensi = MANUAL",
        'run'   => function ($t) {
            $c = $t->db();
            $beDir = $t->probe(function () {
                return base_path();
            });
            $dir = $beDir . '/app/Sql/data';

            // --- teks 'Opname Document' hanya di permission_salesman.sql; tak ada tuple Archive id 1133
            $hits = [];
            $old = [];
            foreach (glob($dir . '/*.sql') as $f) {
                $body = file_get_contents($f);
                if (stripos($body, 'Opname Document') !== false) {
                    $hits[] = basename($f);
                }
                if (preg_match('/\(1133,\s*1266,/', $body)) {
                    $old[] = basename($f);
                }
            }
            $t->eq($hits, ['permission_salesman.sql'], "'Opname Document' hanya di permission_salesman.sql (bukan permissions.sql / lisensi lain)");
            $t->eq($old, [], 'tidak ada baris Archive (module 1266) ber-id 1133 di seed mana pun');

            $salesman = file_get_contents($dir . '/permission_salesman.sql');
            $t->true(strpos($salesman, "(1139,1266,'Opname Document','Opname Dokumen','Opname Document',10,1)") !== false, 'seed: tuple permission 1139');
            $t->true(strpos($salesman, "(1140,1266,'Folder Permissions','Perizinan Folder','Folder Permissions',11,1)") !== false, 'seed: Folder Permissions = 1140 (ED-1025)');
            $t->eq(preg_match_all("/'Opname Document'/", $salesman), 2, "seed: 'Opname Document' tepat di 2 kolom (label & nama) satu baris");

            // id 1139 belum dipakai dan lebih besar dari semua id di permissions.sql
            $base = file_get_contents($dir . '/permissions.sql');
            preg_match_all('/^\((\d+),\d+,/m', $base, $m);
            $t->true(max(array_map('intval', $m[1])) < 1133, 'permissions.sql: id tertinggi < 1133 (1133-1138 dipakai branch AI)');
            preg_match_all('/^\((\d+),\d+,/m', $salesman, $m2);
            $ids = array_map('intval', $m2[1]);
            $t->eq(count(array_keys($ids, 1139)), 1, '1139 muncul sekali di tabel permissions seed salesman');
            $t->eq(count(array_keys($ids, 1133)), 0, '1133 tidak ada di tabel permissions seed salesman');

            // blok checks / unchecks
            $blocks = preg_split('/^INSERT INTO /m', $salesman);
            $checks = '';
            $unchecks = '';
            foreach ($blocks as $b) {
                if (strpos($b, 'permission_checks') === 0) {
                    $checks = $b;
                } elseif (strpos($b, 'permission_unchecks') === 0) {
                    $unchecks = $b;
                }
            }
            $pairs = function ($block) {
                preg_match_all('/\((\d+),(\d+)\)/', $block, $mm, PREG_SET_ORDER);
                $out = [];
                foreach ($mm as $x) {
                    if ($x[1] == 1139 || $x[2] == 1139) {
                        $out[] = $x[1] . ',' . $x[2];
                    }
                }
                sort($out);

                return $out;
            };
            $t->eq($pairs($checks), ['1081,1139', '1139,1082'], 'seed checks: (1139,1082) dan (1081,1139) saja');
            $t->eq($pairs($unchecks), ['1081,1139', '1082,1139', '1139,1081'], 'seed unchecks: (1139,1081), (1081,1139), (1082,1139) saja');
            $t->true(strpos($salesman, '1133,1082') === false && strpos($salesman, '1081,1133') === false, 'seed: tak ada sisa pasangan checks/unchecks 1133');

            // --- DB QA: dicari per nama
            $p = $c->table('permissions')->where('permission_name', 'Opname Document')->get();
            $t->eq(count($p), 1, 'DB: tepat satu baris bernama Opname Document');
            $p = $p[0];
            $t->eq([(int) $p->id_permission, (int) $p->id_module, $p->permission_label, $p->indonesian_permission_label, $p->permission_name, (int) $p->sort_index, (int) $p->is_active],
                [1139, 1266, 'Opname Document', 'Opname Dokumen', 'Opname Document', 10, 1], 'DB: kolom permission Opname Document = id 1139');
            $fp = $c->table('permissions')->where('permission_name', 'Folder Permissions')->first();
            $t->true($fp !== null && (int) $fp->id_permission === 1140 && (int) $fp->id_module === 1266, 'DB: Folder Permissions = 1140 (module 1266)');
            $t->eq($c->table('permissions')->where('id_permission', 1133)->where('id_module', 1266)->count(), 0, 'DB: tak ada permission module Archive ber-id 1133');
            $id = (int) $p->id_permission;
            $t->eq($c->table('permission_checks')->where('id_permission', $id)->where('id_permission_ref', 1082)->count(), 1, "DB: check ($id,1082)");
            $t->eq($c->table('permission_checks')->where('id_permission', 1081)->where('id_permission_ref', $id)->count(), 1, "DB: check (1081,$id)");
            $t->eq($c->table('permission_checks')->where(function ($q) use ($id) {
                $q->where('id_permission', $id)->orWhere('id_permission_ref', $id);
            })->count(), 2, "DB: tepat 2 check untuk $id");
            foreach ([[$id, 1081], [1081, $id], [1082, $id]] as $pair) {
                $t->eq($c->table('permission_unchecks')->where('id_permission', $pair[0])->where('id_permission_ref', $pair[1])->count(), 1, "DB: uncheck ($pair[0],$pair[1])");
            }
            $t->eq($c->table('permission_unchecks')->where(function ($q) use ($id) {
                $q->where('id_permission', $id)->orWhere('id_permission_ref', $id);
            })->count(), 3, "DB: tepat 3 uncheck untuk $id");
            $t->true($c->table('modules')->where('id_module', 1266)->exists(), 'DB: modul 1266 ada');
            // (ED-1149 K-4 b / BR-1 / BR-5) TIDAK ada asersi role_permissions di DB bersama (hak role developer 1/2/3/6/29 atas Opname Document
            // bisa diubah developer lewat UI role): pemegang Opname Document dari seed utuh diasersikan di DB scratch di bawah.

            // --- seed SQL dijalankan utuh di DB scratch (sintaks, PK, urutan)
            $scratch = 'qa26_scratch_' . substr(uniqid(), -6);
            $db = q26_dbname($t);
            $error = null;
            $result = null;
            $roleSql = file_get_contents($dir . '/role_permissions.sql');
            $t->probe(function () use ($db, $scratch, $salesman, $roleSql, &$error, &$result) {
                $src = \Illuminate\Support\Facades\DB::connection($db);
                $cfg = $src->getConfig();
                $src->statement("CREATE DATABASE `$scratch` CHARACTER SET latin1 COLLATE latin1_general_ci");
                try {
                    $cfg['database'] = $scratch;
                    config(['database.connections.qa26scratch' => $cfg]);
                    \Illuminate\Support\Facades\DB::purge('qa26scratch');
                    $s = \Illuminate\Support\Facades\DB::connection('qa26scratch');
                    foreach (['permissions', 'permission_checks', 'permission_unchecks', 'modules', 'role_permissions', 'role_allow_backdates', 'role_allow_backdate_transactions'] as $tb) {
                        $s->statement("CREATE TABLE `$tb` LIKE `$db`.`$tb`");
                    }
                    $stmts = preg_split('/;\s*\n/', trim($salesman));
                    // pra-seed (ED-1149 K-4 b): permissions dasar dari DB QA TANPA id yang disuntik seed fitur; role_permissions: peran "List Archive saja"
                    // (id 29) dan "tanpa Archive" (id 6) palsu milik scratch, untuk membuktikan seed tidak memberi Opname Document ke role lain
                    preg_match_all("/\\((\\d+),\\d+,'/", $stmts[0], $seedIdMatches);
                    $seedIds = array_map('intval', $seedIdMatches[1]);
                    $s->statement("INSERT INTO `permissions` SELECT * FROM `$db`.`permissions` WHERE id_permission NOT IN (" . implode(',', $seedIds) . ')');
                    foreach ($stmts as $sql) {
                        $sql = trim($sql);
                        if ($sql !== '') {
                            $s->unprepared($sql);
                        }
                    }
                    $now = date('Y-m-d H:i:s');
                    foreach ([29 => 'List Archive', 6 => 'Get Customer'] as $fakeRole => $permName) {
                        $pid = $s->table('permissions')->where('permission_name', $permName)->value('id_permission');
                        if ($pid === null) {
                            throw new \RuntimeException("permission '$permName' tidak ada di scratch");
                        }
                        $s->table('role_permissions')->insert(['id_role' => $fakeRole, 'id_permission' => $pid, 'permission_name' => $permName, 'created_at' => $now]);
                    }
                    foreach (preg_split('/;\s*\n/', trim($roleSql)) as $sql) {
                        $sql = trim($sql);
                        if ($sql !== '') {
                            $s->unprepared($sql);
                        }
                    }
                    $odHolders = $s->table('role_permissions')->where('id_permission', 1139)->orderBy('id_role')->pluck('id_role')->all();
                    $result = [
                        'od_holders' => $odHolders,
                        'od_names' => $s->table('role_permissions')->where('id_permission', 1139)->pluck('permission_name')->unique()->values()->all(),
                        'fake_roles_kept' => $s->table('role_permissions')->whereIn('id_role', [29, 6])->count(),
                        'p1139' => $s->table('permissions')->where('id_permission', 1139)->count(),
                        'p1133' => $s->table('permissions')->where('id_permission', 1133)->count(),
                        'checks' => $s->table('permission_checks')->where(function ($q) {
                            $q->where('id_permission', 1139)->orWhere('id_permission_ref', 1139);
                        })->count(),
                        'unchecks' => $s->table('permission_unchecks')->where(function ($q) {
                            $q->where('id_permission', 1139)->orWhere('id_permission_ref', 1139);
                        })->count(),
                    ];
                } catch (\Throwable $e) {
                    $error = get_class($e) . ': ' . substr($e->getMessage(), 0, 300);
                } finally {
                    $src->statement("DROP DATABASE IF EXISTS `$scratch`");
                    \Illuminate\Support\Facades\DB::purge('qa26scratch');
                }
            });
            $t->eq($error, null, 'seed permission_salesman.sql dijalankan utuh di DB scratch tanpa error');
            $t->eq($result['p1139'] ?? null, 1, 'scratch: permission 1139 satu baris');
            $t->eq($result['p1133'] ?? null, 0, 'scratch: tidak ada permission 1133');
            $t->eq($result['checks'] ?? null, 2, 'scratch: 2 check untuk 1139');
            $t->eq($result['unchecks'] ?? null, 3, 'scratch: 3 uncheck untuk 1139');
            $t->eq($result['od_holders'] ?? null, [1, 2, 3], 'scratch (seed utuh permission_salesman.sql + role_permissions.sql): pemegang Opname Document = role 1, 2, 3 saja (role lain, mis. List-saja/tanpa-Archive, tidak)');
            $t->eq($result['od_names'] ?? null, ['Opname Document'], 'scratch: permission_name baris Opname Document = nama seed (tidak NULL)');
            $t->eq($result['fake_roles_kept'] ?? null, 2, 'scratch: baris role lain (List-saja/tanpa-Archive) tidak disentuh seed (tetap 1 baris masing-masing)');
            $t->note('MANUAL (Gate 2 epic): "tanpa lisensi Salesman Activity -> permission tidak ada" dibuktikan lewat baris seed yang hanya di permission_salesman.sql; tukar lisensi sungguhan tidak dijalankan (PROFILES kosong)');
            $left = $t->db()->selectOne("SELECT COUNT(*) n FROM information_schema.schemata WHERE schema_name LIKE 'qa26_scratch_%'")->n;
            $t->eq($left, 0, 'DB scratch dibuang');
        },
    ],

    [
        'id'    => 'EXTRA-LANG',
        'title' => 'Kode pesan ARCHIVE212/213/412/414-417 ada di en_EN dan id_ID sesuai kontrak; ARCHIVE211/214/413 sudah dihapus; respons API mengikuti bahasa user (EN/ID)',
        'run'   => function ($t) {
            $beDir = $t->probe(function () {
                return base_path();
            });
            $contract = [
                'ARCHIVE212' => ['Document scanned', 'Dokumen discan'],
                'ARCHIVE213' => ['Opname successfully confirmed', 'Opname berhasil dikonfirmasi'],
                'ARCHIVE412' => ["Opname session isn't found", 'Sesi opname tidak ditemukan'],
                'ARCHIVE414' => ['Folder <b>[0]</b> was opnamed by <b>[1]</b> at [2] while this session was running. Please re-select the folders', 'Folder <b>[0]</b> sudah diopname oleh <b>[1]</b> pukul [2] selama sesi ini berjalan. Pilih ulang folder'],
                'ARCHIVE415' => ['This opname session was started on another day. Please start a new session', 'Sesi opname ini dimulai di hari lain. Mulai sesi baru'],
                'ARCHIVE416' => ['Selected folder must be a direct subfolder of the opname folder', 'Folder yang dipilih harus subfolder langsung dari folder yang diopname'],
                'ARCHIVE417' => ['Opname can only be run on a folder', 'Opname hanya bisa dijalankan pada folder'],
            ];
            $en = file_get_contents($beDir . '/app/Lib/lang/en_EN.php');
            $id = file_get_contents($beDir . '/app/Lib/lang/id_ID.php');
            foreach ($contract as $code => $texts) {
                $t->eq(substr_count($en, "'$code'"), 1, "en_EN: $code satu kali");
                $t->eq(substr_count($id, "'$code'"), 1, "id_ID: $code satu kali");
                $t->true(strpos($en, "'$code' => " . var_export($texts[0], true)) !== false || strpos($en, "'$code' => \"" . $texts[0] . '"') !== false, "en_EN: teks $code sesuai kontrak");
                $t->true(strpos($id, "'$code' => " . var_export($texts[1], true)) !== false || strpos($id, "'$code' => \"" . $texts[1] . '"') !== false, "id_ID: teks $code sesuai kontrak");
            }
            foreach (['ARCHIVE211', 'ARCHIVE214', 'ARCHIVE413'] as $gone) {
                $t->eq(substr_count($en, "'$gone'"), 0, "en_EN: $gone dihapus (nomor tidak dipakai ulang)");
                $t->eq(substr_count($id, "'$gone'"), 0, "id_ID: $gone dihapus");
            }
            // kode lain di rentang Archive tetap ada (tidak ikut terhapus)
            foreach (['ARCHIVE200', 'ARCHIVE201', 'ARCHIVE400', 'ARCHIVE407'] as $keep) {
                $t->eq(substr_count($en, "'$keep'"), 1, "en_EN: $keep tetap");
            }

            // respons API mengikuti bahasa user (EN / ID)
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $yesterday = q26_now('-1 day')->format('Y-m-d') . ' 10:00:00';
                foreach (['EN' => 0, 'ID' => 1] as $lang => $i) {
                    q26_with_user($t, ['lang' => $lang], function () use ($t, $s, $x, $contract, $i, $lang, $yesterday) {
                        $sel = q26_selection($x['F'], q26_sel([$x['S1']]));
                        $r = q26_scan($t, $s, $sel, 'QA26-TIDAK-ADA');
                        $t->status($r, 200, "$lang: scan");
                        $t->eq($r[1]['message'] ?? null, $contract['ARCHIVE212'][$i], "$lang: message ARCHIVE212");
                        $t->eq($r[1]['result']['row']['result_label'] ?? null, $i === 0 ? 'Invalid' : 'Tidak valid', "$lang: result_label");

                        $o = q26_do($t, $s, $x['S2'], [], null, [q26_n($t, $x['d21'])]);
                        $t->status($o['r'], 200, "$lang: Konfirmasi");
                        $t->eq($o['r'][1]['message'] ?? null, $contract['ARCHIVE213'][$i], "$lang: message ARCHIVE213");
                        $show = q26_show($t, $s, $o['id']);
                        $t->eq($show[1]['result']['status_label'] ?? null, $i === 0 ? 'Confirmed' : 'Dikonfirmasi', "$lang: status_label");
                        $r = q26_show($t, $s, Q26_RANDOM_ID);
                        q26_deny($t, $r, 404, 'ARCHIVE412', "$lang: sesi tak ada");
                        $t->eq($r[1]['message'] ?? null, $contract['ARCHIVE412'][$i], "$lang: message ARCHIVE412");

                        // ARCHIVE414: kirim ulang body yang sama
                        $r = q26_store($t, $s, $o['sel'], $o['scans'], $o['selected_at']);
                        q26_deny($t, $r, 400, 'ARCHIVE414', "$lang: kirim ulang");
                        $msg = $r[1]['message'] ?? '';
                        $t->true(strpos($msg, q26_n($t, $x['S2'])) !== false, "$lang: ARCHIVE414 memuat nama folder");
                        $t->true(strpos($msg, $i === 0 ? 'was opnamed by' : 'sudah diopname oleh') !== false, "$lang: ARCHIVE414 bahasa");

                        $r = q26_store($t, $s, q26_selection($x['S2']), [], $yesterday);
                        q26_deny($t, $r, 400, 'ARCHIVE415', "$lang: selected_at kemarin");
                        $t->eq($r[1]['message'] ?? null, $contract['ARCHIVE415'][$i], "$lang: message ARCHIVE415");
                        $r = q26_scan($t, $s, q26_selection($x['F'], q26_sel([$x['S1a']])), 'x');
                        q26_deny($t, $r, 400, 'ARCHIVE416', "$lang: bukan subfolder langsung");
                        $t->eq($r[1]['message'] ?? null, $contract['ARCHIVE416'][$i], "$lang: message ARCHIVE416");
                        $r = q26_scan($t, $s, q26_selection($x['dF1']), 'x');
                        q26_deny($t, $r, 400, 'ARCHIVE417', "$lang: folder opname = dokumen");
                        $t->eq($r[1]['message'] ?? null, $contract['ARCHIVE417'][$i], "$lang: message ARCHIVE417");
                        $r = q26_folders($t, $s, Q26_RANDOM_ID);
                        q26_deny($t, $r, 404, 'ARCHIVE400', "$lang: folder tak ada");
                    });
                }
            } finally {
                q26_cleanup($t);
            }
        },
    ],
];
