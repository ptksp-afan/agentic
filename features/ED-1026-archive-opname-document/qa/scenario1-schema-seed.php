<?php
/**
 * ED-1026 - AC-1 (skema + Updater 2x + Updater dari nol di DB scratch), AC-2 (seed permission 'Opname Document' hanya
 * di permission_salesman.sql + DB + SQL seed dijalankan di DB scratch), EXTRA-LANG (kode pesan en_EN/id_ID sesuai kontrak).
 * Tukar lisensi sungguhan = MANUAL Gate 2 epic (PROFILES kosong, EPIC K-2 b).
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
        'title' => 'Skema: 4 kolom + indeks id_archive_parent di archives, 3 tabel baru; baris lama is_verified=0; Updater 2x tanpa error; Updater dari nol (DB scratch) membuat skema identik',
        'run'   => function ($t) use ($spec, $columns, $indexes, $hasIndex) {
            $c = $t->db();
            $db = q26_dbname($t);

            // --- archives: 4 kolom baru, tepat setelah is_active
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

            // indeks baru id_archive_parent
            $aidx = $indexes($c, 'archives');
            $t->true($hasIndex($aidx, ['id_archive_parent'], false) !== null, 'archives punya indeks (id_archive_parent)');

            // baris lama = unverified
            $t->eq($c->table('archives')->where('name', 'not like', Q26_PREFIX . '%')
                ->where(function ($q) {
                    $q->whereNull('is_verified')->orWhere('is_verified', '!=', 0);
                })->count(), 0, 'semua dokumen/folder lama is_verified = 0');

            // --- 3 tabel baru
            $arch = $c->selectOne("SHOW TABLE STATUS LIKE 'archives'");
            foreach ($spec as $table => $expected) {
                $got = $columns($c, $table);
                $t->eq(array_keys($got), array_keys($expected), "$table: nama & urutan kolom");
                foreach ($expected as $col => $e) {
                    $g = $got[$col];
                    $norm = function ($type) {
                        return strpos($type, 'varchar') === 0 ? $type : preg_replace('/\(\d+\)/', '', $type);
                    };
                    $t->eq($norm($g[0]), $norm($e[0]), "$table.$col tipe");
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
            $t->eq($c->table('archive_opnames')->count() + $c->table('archive_opname_folders')->count() + $c->table('archive_opname_documents')->count(), 0, 'tabel opname kosong di awal');

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

            // --- Updater: berkas tunggal, terdaftar di config.php dengan kunci terbesar
            $beDir = $t->probe(function () {
                return base_path();
            });
            $files = glob($beDir . '/Modules/UpdateVersion/Updaters/*_AddArchiveOpname.php');
            $t->eq(count($files), 1, 'tepat satu berkas Updater AddArchiveOpname');
            $class = 'Modules\\UpdateVersion\\Updaters\\' . basename($files[0], '.php');
            $config = include $beDir . '/Modules/UpdateVersion/Updaters/config.php';
            $key = array_search($class, $config, true);
            $t->true($key !== false, 'Updater terdaftar di config.php');
            $others = $config;
            unset($others[$key]);
            $t->true($key > max(array_keys($others)), 'kunci config.php lebih besar dari semua Updater lain (urutan jalan)');

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
                $out['archives_rows'] = $c->selectOne('SELECT COUNT(*) n, SUM(is_verified) v FROM archives');

                return json_encode($out);
            };
            $before = $snapshot();
            $error = null;
            $t->probe(function () use ($db, $files, &$error) {
                \Illuminate\Support\Facades\DB::setDefaultConnection($db);
                try {
                    $updater = require $files[0];
                    $updater->run();
                    $updater->run();
                } catch (\Throwable $e) {
                    $error = get_class($e) . ': ' . $e->getMessage();
                }
            });
            $t->eq($error, null, 'Updater dijalankan 2x tanpa error');
            $t->true($before === $snapshot(), 'skema + jumlah baris archives tidak berubah oleh run ulang Updater');

            // --- Updater dari nol di DB scratch: archives lama (tanpa kolom baru) + tabel opname belum ada
            $scratch = 'qa26_scratch_' . substr(uniqid(), -6);
            $error = null;
            $diff = null;
            $t->probe(function () use ($db, $files, $scratch, &$error, &$diff) {
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
                    $updater = require $files[0];
                    $updater->run();
                    $updater->run();

                    $norm = function ($conn, $table) {
                        $row = (array) $conn->selectOne("SHOW CREATE TABLE `$table`");
                        $sql = array_values($row)[1];

                        return preg_replace('/ AUTO_INCREMENT=\d+/', '', $sql);
                    };
                    $diff = [];
                    foreach (['archives', 'archive_opnames', 'archive_opname_folders', 'archive_opname_documents'] as $tb) {
                        // archives: bandingkan hanya bagian kolom baru + indeks (tabel asli punya FK/indeks lain yang ikut dicopy LIKE)
                        $a = $norm($s, $tb);
                        $b = $norm($src, $tb);
                        $diff[$tb] = $a === $b;
                    }
                } catch (\Throwable $e) {
                    $error = get_class($e) . ': ' . $e->getMessage();
                } finally {
                    \Illuminate\Support\Facades\DB::setDefaultConnection($db);
                    $src->statement("DROP DATABASE IF EXISTS `$scratch`");
                    \Illuminate\Support\Facades\DB::purge('qa26scratch');
                }
            });
            $t->eq($error, null, 'Updater dari nol di DB scratch tanpa error (2x)');
            $t->eq(json_encode($diff), json_encode(['archives' => true, 'archive_opnames' => true, 'archive_opname_folders' => true, 'archive_opname_documents' => true]),
                'SHOW CREATE TABLE hasil Updater dari nol identik dengan DB QA (archives + 3 tabel baru)');
            $left = $t->db()->selectOne("SELECT COUNT(*) n FROM information_schema.schemata WHERE schema_name LIKE 'qa26_scratch_%'")->n;
            $t->eq($left, 0, 'DB scratch dibuang');
        },
    ],

    [
        'id'    => 'AC-2',
        'title' => 'Seed: permission Opname Document (1133, module 1266) + checks/unchecks hanya di permission_salesman.sql; DB QA punya barisnya; SQL seed dijalankan utuh di DB scratch tanpa error. Tanpa lisensi = MANUAL',
        'run'   => function ($t) {
            $c = $t->db();
            $beDir = $t->probe(function () {
                return base_path();
            });
            $dir = $beDir . '/app/Sql/data';

            // --- baris hanya di permission_salesman.sql
            $hits = [];
            foreach (glob($dir . '/*.sql') as $f) {
                $body = file_get_contents($f);
                if (stripos($body, 'Opname Document') !== false || preg_match('/\(1133,\s*1266,/', $body) || preg_match('/[(,]1133[,)]/', $body)) {
                    $hits[] = basename($f);
                }
            }
            $t->eq($hits, ['permission_salesman.sql'], "baris 'Opname Document' / id 1133 hanya di permission_salesman.sql (bukan permissions.sql / lisensi lain)");

            $salesman = file_get_contents($dir . '/permission_salesman.sql');
            $t->true(strpos($salesman, "(1133,1266,'Opname Document','Opname Dokumen','Opname Document',10,1)") !== false, 'seed: tuple permission 1133');
            $t->eq(preg_match_all("/'Opname Document'/", $salesman), 2, "seed: 'Opname Document' tepat di 2 kolom (label & nama) satu baris");

            // id 1133 belum dipakai dan lebih besar dari semua id di permissions.sql
            $base = file_get_contents($dir . '/permissions.sql');
            preg_match_all('/^\((\d+),\d+,/m', $base, $m);
            $t->true(max(array_map('intval', $m[1])) < 1133, 'id 1133 > semua id permissions.sql (tidak bentrok)');
            preg_match_all('/^\((\d+),\d+,/m', $salesman, $m2);
            $ids = array_map('intval', $m2[1]);
            $t->eq(count(array_keys($ids, 1133)), 1, '1133 muncul sekali di tabel permissions seed salesman');

            // --- DB QA
            $p = $c->table('permissions')->where('id_permission', 1133)->first();
            $t->true($p !== null, 'DB: permission 1133 ada');
            $t->eq([(int) $p->id_module, $p->permission_label, $p->indonesian_permission_label, $p->permission_name, (int) $p->sort_index, (int) $p->is_active],
                [1266, 'Opname Document', 'Opname Dokumen', 'Opname Document', 10, 1], 'DB: kolom permission 1133');
            $t->eq($c->table('permissions')->where('permission_name', 'Opname Document')->count(), 1, 'DB: satu baris bernama Opname Document');
            $t->eq($c->table('permission_checks')->where('id_permission', 1133)->where('id_permission_ref', 1082)->count(), 1, 'DB: check (1133,1082)');
            $t->eq($c->table('permission_checks')->where('id_permission', 1081)->where('id_permission_ref', 1133)->count(), 1, 'DB: check (1081,1133)');
            $t->eq($c->table('permission_checks')->where(function ($q) {
                $q->where('id_permission', 1133)->orWhere('id_permission_ref', 1133);
            })->count(), 2, 'DB: tepat 2 check untuk 1133');
            foreach ([[1133, 1081], [1081, 1133], [1082, 1133]] as $pair) {
                $t->eq($c->table('permission_unchecks')->where('id_permission', $pair[0])->where('id_permission_ref', $pair[1])->count(), 1, "DB: uncheck ($pair[0],$pair[1])");
            }
            $t->eq($c->table('permission_unchecks')->where(function ($q) {
                $q->where('id_permission', 1133)->orWhere('id_permission_ref', 1133);
            })->count(), 3, 'DB: tepat 3 uncheck untuk 1133');
            // modul Archive ada (module 1266)
            $t->true($c->table('modules')->where('id_module', 1266)->exists(), 'DB: modul 1266 ada');
            // superadmin 1/2 dan role Super Admin QA memegang permission itu
            $roles = $c->table('role_permissions')->where('id_permission', 1133)->pluck('id_role')->all();
            foreach ([1, 2, 3] as $r) {
                $t->true(in_array($r, $roles), "DB: role $r punya Opname Document");
            }
            $t->true(!in_array(29, $roles) && !in_array(6, $roles), 'DB: role 29 (List saja) dan 6 tidak punya Opname Document');

            // --- seed SQL dijalankan utuh di DB scratch (sintaks, PK, urutan)
            $scratch = 'qa26_scratch_' . substr(uniqid(), -6);
            $db = q26_dbname($t);
            $error = null;
            $result = null;
            $t->probe(function () use ($db, $scratch, $salesman, &$error, &$result) {
                $src = \Illuminate\Support\Facades\DB::connection($db);
                $cfg = $src->getConfig();
                $src->statement("CREATE DATABASE `$scratch` CHARACTER SET latin1 COLLATE latin1_general_ci");
                try {
                    $cfg['database'] = $scratch;
                    config(['database.connections.qa26scratch' => $cfg]);
                    \Illuminate\Support\Facades\DB::purge('qa26scratch');
                    $s = \Illuminate\Support\Facades\DB::connection('qa26scratch');
                    foreach (['permissions', 'permission_checks', 'permission_unchecks', 'modules'] as $tb) {
                        $s->statement("CREATE TABLE `$tb` LIKE `$db`.`$tb`");
                    }
                    // pecah per statement INSERT (berakhir ';' di akhir baris)
                    $stmts = preg_split('/;\s*\n/', trim($salesman));
                    foreach ($stmts as $sql) {
                        $sql = trim($sql);
                        if ($sql !== '') {
                            $s->unprepared($sql);
                        }
                    }
                    $result = [
                        'p1133' => $s->table('permissions')->where('id_permission', 1133)->count(),
                        'checks' => $s->table('permission_checks')->where(function ($q) {
                            $q->where('id_permission', 1133)->orWhere('id_permission_ref', 1133);
                        })->count(),
                        'unchecks' => $s->table('permission_unchecks')->where(function ($q) {
                            $q->where('id_permission', 1133)->orWhere('id_permission_ref', 1133);
                        })->count(),
                        'perm_total' => $s->table('permissions')->count(),
                    ];
                } catch (\Throwable $e) {
                    $error = get_class($e) . ': ' . substr($e->getMessage(), 0, 300);
                } finally {
                    $src->statement("DROP DATABASE IF EXISTS `$scratch`");
                    \Illuminate\Support\Facades\DB::purge('qa26scratch');
                }
            });
            $t->eq($error, null, 'seed permission_salesman.sql dijalankan utuh di DB scratch tanpa error');
            $t->eq($result['p1133'] ?? null, 1, 'scratch: permission 1133 satu baris');
            $t->eq($result['checks'] ?? null, 2, 'scratch: 2 check untuk 1133');
            $t->eq($result['unchecks'] ?? null, 3, 'scratch: 3 uncheck untuk 1133');
            $t->note('MANUAL (Gate 2 epic): "tanpa lisensi Salesman Activity -> permission tidak ada" dibuktikan lewat baris seed yang hanya di permission_salesman.sql; tukar lisensi sungguhan tidak dijalankan (PROFILES kosong)');
            $left = $t->db()->selectOne("SELECT COUNT(*) n FROM information_schema.schemata WHERE schema_name LIKE 'qa26_scratch_%'")->n;
            $t->eq($left, 0, 'DB scratch dibuang');
        },
    ],

    [
        'id'    => 'EXTRA-LANG',
        'title' => 'Kode pesan ARCHIVE211-214 & 412-417 ada di en_EN dan id_ID dengan teks sesuai kontrak; respons mengikuti bahasa user',
        'run'   => function ($t) {
            $beDir = $t->probe(function () {
                return base_path();
            });
            $contract = [
                'ARCHIVE211' => ['Opname session saved', 'Sesi opname disimpan'],
                'ARCHIVE212' => ['Document scanned', 'Dokumen discan'],
                'ARCHIVE213' => ['Opname successfully confirmed', 'Opname berhasil dikonfirmasi'],
                'ARCHIVE214' => ['Opname session cancelled', 'Sesi opname dibatalkan'],
                'ARCHIVE412' => ["Opname session isn't found", 'Sesi opname tidak ditemukan'],
                'ARCHIVE413' => ['Opname session has already been confirmed or cancelled', 'Sesi opname sudah dikonfirmasi atau dibatalkan'],
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

            // respons API mengikuti bahasa user (EN / ID)
            $s = $t->session();
            q26_baseline($t);
            try {
                $f = q26_folder($t, 'LANG');
                foreach (['EN' => 0, 'ID' => 1] as $lang => $i) {
                    q26_with_user($t, ['lang' => $lang], function () use ($t, $s, $f, $contract, $i, $lang) {
                        $r = q26_store($t, $s, $f);
                        $t->status($r, 200, "$lang: store");
                        $t->eq($r[1]['message'] ?? null, $contract['ARCHIVE211'][$i], "$lang: message ARCHIVE211");
                        $sid = $r[1]['result']['id_archive_opname'];
                        $r = q26_scan($t, $s, $sid, 'QA26-TIDAK-ADA');
                        $t->eq($r[1]['message'] ?? null, $contract['ARCHIVE212'][$i], "$lang: message ARCHIVE212");
                        $t->eq($r[1]['result']['row']['result_label'] ?? null, $i === 0 ? 'Invalid' : 'Tidak valid', "$lang: result_label");
                        $show = q26_show($t, $s, $sid);
                        $t->eq($show[1]['result']['status_label'] ?? null, $i === 0 ? 'Running' : 'Berjalan', "$lang: status_label");
                        $r = q26_cancel($t, $s, $sid);
                        $t->eq($r[1]['message'] ?? null, $contract['ARCHIVE214'][$i], "$lang: message ARCHIVE214");
                        $r = q26_confirm($t, $s, $sid);
                        $t->status($r, 400, "$lang: confirm sesi batal");
                        $t->eq($r[1]['code'] ?? null, 'ARCHIVE413', "$lang: ARCHIVE413");
                        $t->eq($r[1]['message'] ?? null, $contract['ARCHIVE413'][$i], "$lang: message ARCHIVE413");
                        $r = q26_scan($t, $s, Q26_RANDOM_ID, 'x');
                        $t->eq($r[1]['message'] ?? null, $contract['ARCHIVE412'][$i], "$lang: message ARCHIVE412");
                        $r = q26_folders($t, $s, Q26_RANDOM_ID);
                        $t->status($r, 404, "$lang: folder tak ada");
                        $t->eq($r[1]['code'] ?? null, 'ARCHIVE400', "$lang: ARCHIVE400");
                    });
                }
            } finally {
                q26_cleanup($t);
            }
        },
    ],
];
