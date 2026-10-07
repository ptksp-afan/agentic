<?php
/**
 * ED-1025 - AC-1 (skema + Updater 2x), AC-2 (tanpa folder aktif = perilaku lama), AC-4 (show), AC-7/AC-8 (tolak buka folder,
 * parent dominan), AC-10 (superadmin 1 dan 2), AC-11 (pembuat folder), AC-15 (search tetap, show/history 403).
 *
 * User uji = QA_USER (role 3 = bukan bypass). Data uji berawalan QA02-, dibuang di finally. Lihat qa_lib.php.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-1',
        'title' => 'Skema: archives.is_folder_permission (default 0) + archive_permissions (+unique id_archive,id_user); Updater 2x tanpa error dan terdaftar di config.php',
        'run'   => function ($t) {
            $db = q2_dbname($t);
            $c = $t->db();

            // --- kolom archives
            $col = $c->selectOne("SHOW COLUMNS FROM archives LIKE 'is_folder_permission'");
            $t->true($col !== null, 'archives.is_folder_permission ada');
            $t->eq($col->Type, 'tinyint(2)', 'tipe kolom');
            $t->eq($col->Default, '0', 'default kolom 0');
            $order = array_map(function ($r) {
                return $r->Field;
            }, $c->select('SHOW COLUMNS FROM archives'));
            $t->eq(array_search('is_folder_permission', $order), array_search('is_all_location', $order) + 1, 'kolom tepat sesudah is_all_location');
            $t->eq($c->table('archives')->where('name', 'not like', 'QA%')->whereRaw('coalesce(is_folder_permission, -1) <> 0')->count(), 0, 'semua folder/dokumen lama = 0 (nonaktif)');

            // --- tabel archive_permissions
            $cols = [];
            foreach ($c->select('SHOW COLUMNS FROM archive_permissions') as $r) {
                $cols[$r->Field] = [$r->Type, $r->Default];
            }
            $expected = [
                'id_archive_permission' => ['varchar(30)', null], 'id_archive' => ['varchar(30)', null], 'id_user' => ['varchar(30)', null],
                'is_view' => ['tinyint(2)', '0'], 'is_update' => ['tinyint(2)', '0'], 'is_delete' => ['tinyint(2)', '0'], 'is_store' => ['tinyint(2)', '0'],
                'created_at' => ['datetime', null], 'created_by' => ['varchar(30)', null], 'updated_at' => ['datetime', null], 'updated_by' => ['varchar(30)', null],
            ];
            $t->eq(json_encode($cols), json_encode($expected), 'kolom archive_permissions (nama, tipe, default, urutan)');

            $idx = [];
            foreach ($c->select('SHOW INDEX FROM archive_permissions') as $r) {
                $idx[$r->Key_name]['cols'][(int) $r->Seq_in_index] = $r->Column_name;
                $idx[$r->Key_name]['unique'] = (int) $r->Non_unique === 0;
            }
            $t->eq(json_encode($idx['PRIMARY']['cols'] ?? null), json_encode([1 => 'id_archive_permission']), 'PRIMARY KEY id_archive_permission');
            $unique = null;
            foreach ($idx as $name => $i) {
                if ($i['unique'] && $name !== 'PRIMARY') {
                    $unique = $i['cols'];
                }
            }
            $t->eq(json_encode($unique), json_encode([1 => 'id_archive', 2 => 'id_user']), 'UNIQUE (id_archive, id_user)');
            $t->true(($idx['id_user']['unique'] ?? null) === false && $idx['id_user']['cols'] === [1 => 'id_user'], 'KEY id_user (non-unique)');

            $status = $c->selectOne("SHOW TABLE STATUS LIKE 'archive_permissions'");
            $arch = $c->selectOne("SHOW TABLE STATUS LIKE 'archives'");
            $t->eq($status->Collation, 'latin1_general_ci', 'collation latin1_general_ci');
            $t->eq($status->Collation, $arch->Collation, 'collation sama dengan archives');
            $t->eq($status->Engine, 'InnoDB', 'engine InnoDB');

            // --- unique benar-benar ditegakkan (baris ganda ditolak server)
            q2_purge($t);
            $base = q2_perm_total($t);
            try {
                $f = q2_folder($t, 'U');
                $u = q2_others($t, 1)[0][0];
                q2_perm($t, $f, $u, 1);
                $dup = false;
                try {
                    q2_perm($t, $f, $u, 1, 1);
                } catch (\Throwable $e) {
                    $dup = true;
                }
                $t->true($dup, 'baris (id_archive,id_user) ganda ditolak oleh UNIQUE');
                $t->eq($c->table('archive_permissions')->where('id_archive', $f)->count(), 1, 'tetap satu baris');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);

            // --- Updater: terdaftar di config.php, berkas ada, dijalankan 2x tanpa error, skema tidak berubah
            $beDir = $t->probe(function () {
                return base_path();
            });
            $files = glob($beDir . '/Modules/UpdateVersion/Updaters/*_AddArchiveFolderPermission.php');
            $t->eq(count($files), 1, 'tepat satu berkas Updater AddArchiveFolderPermission');
            $class = 'Modules\\UpdateVersion\\Updaters\\' . basename($files[0], '.php');
            $config = include $beDir . '/Modules/UpdateVersion/Updaters/config.php';
            $key = array_search($class, $config, true);
            $t->true($key !== false, 'Updater terdaftar di config.php');
            $others = $config;
            unset($others[$key]);
            $t->true($key > max(array_keys($others)), 'kunci config.php lebih besar dari semua Updater sebelumnya (urutan jalan)');

            $showCreate = function () use ($c) {
                return [
                    (array) $c->selectOne('SHOW CREATE TABLE archive_permissions'),
                    json_encode(array_map(function ($r) {
                        return (array) $r;
                    }, $c->select('SHOW COLUMNS FROM archives'))),
                    $c->selectOne('CHECKSUM TABLE archives')->Checksum ?? null,
                ];
            };
            $before = $showCreate();

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
            $t->true(json_encode($before) === json_encode($showCreate()), 'skema + data archives tidak berubah oleh run ulang Updater');
        },
    ],

    [
        'id'    => 'AC-2',
        'title' => 'Tanpa folder ber-permission aktif: list/buka/buat subfolder/Store Document/pindah/rename/ubah/hapus = perilaku lama; access semua true, is_folder_permission 0',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $uname = q2_uname($t);

            try {
                $t->eq($t->db()->table('archives')->where('is_folder_permission', 1)->count(), 0, 'prasyarat: tidak ada folder ber-permission aktif');
                $P = q2_folder($t, 'P');
                $C = q2_folder($t, 'C', ['parent' => $P]);
                $D = q2_doc($t, 'D', ['parent' => $P]);
                $Dr = q2_doc($t, 'DR');
                $T = q2_folder($t, 'T');

                // 1. list root: baris folder asli + uji
                $r = q2_list($t, $s, ['pagination' => 100]);
                $t->status($r, 200, 'root');
                $t->code($r, 'ARCHIVE200', 'root');
                $t->eq($r[1]['result']['access'], null, 'root: result.access null');
                $folders = 0;
                foreach ($r[1]['result']['data'] as $row) {
                    if ($row['type'] === 'Folder') {
                        $folders++;
                        q2_access_is($t, $row['access'], q2_all_true(), 'root folder ' . $row['id_archive']);
                        $t->eq($row['is_folder_permission'], 0, 'root folder is_folder_permission');
                    } else {
                        $t->eq($row['access'], null, 'root dokumen: access null');
                        $t->eq($row['is_folder_permission'], 0, 'root dokumen: is_folder_permission 0');
                    }
                }
                $t->true($folders >= 2, 'root memuat folder asli dan folder uji (>=2), didapat ' . $folders);

                // 2. buka folder
                $r = q2_open($t, $s, $P);
                $t->status($r, 200, 'buka P');
                $t->code($r, 'ARCHIVE200', 'buka P');
                q2_access_is($t, $r[1]['result']['access'], q2_all_true(), 'result.access P');
                $t->eq(json_encode(array_values(array_diff(q2_ids($r), [$C, $D]))), json_encode([]), 'isi P = C dan D');
                $t->eq(count(q2_ids($r)), 2, 'isi P = 2 baris');
                q2_access_is($t, q2_find($r, $C)['access'], q2_all_true(), 'baris C');
                $t->eq(q2_find($r, $D)['access'], null, 'baris dokumen D: access null');
                $t->eq(json_encode(array_column($r[1]['result']['breadcrumbs'], 'value')), json_encode([$P]), 'breadcrumbs');

                // 3. show + history
                $r = q2_show($t, $s, $P);
                $t->status($r, 200, 'show P');
                $t->eq($r[1]['result']['is_folder_permission'], 0, 'show: is_folder_permission 0');
                $t->eq($r[1]['result']['folder_permissions'], [], 'show: folder_permissions []');
                q2_access_is($t, $r[1]['result']['access'], q2_all_true(), 'show P');
                $t->status(q2_hist($t, $s, $P), 200, 'history P');

                // 4. buat subfolder
                $name = q2_name('NEW');
                $r = q2_create($t, $s, ['name' => $name, 'id_archive_parent' => $P, 'is_all_location' => 1]);
                $t->status($r, 200, 'create-folder di P');
                $t->code($r, 'ARCHIVE201', 'create-folder');
                $new = $t->db()->table('archives')->where('name', $name)->value('id_archive');
                $t->true($new !== null, 'folder baru ada di DB');
                $row = q2_row($t, $new);
                $t->eq((int) $row['is_folder_permission'], 0, 'folder baru: is_folder_permission 0 (BR-1)');
                $t->eq($row['id_archive_parent'], $P, 'folder baru: induk P');
                $t->eq($row['created_by'], $uname, 'folder baru: created_by = pembuat');
                $t->eq(count(q2_perm_map($t, $new)), 0, 'folder baru: tanpa baris permission');

                // 5. Store Document (put-in dokumen root ke P) + pindah folder + dokumen ke root
                $r = q2_putin($t, $s, $P, $Dr);
                $t->status($r, 200, 'put-in dokumen ke P');
                $t->code($r, 'ARCHIVE204', 'put-in dokumen');
                $t->eq(q2_row($t, $Dr)['id_archive_parent'], $P, 'dokumen kini di P');
                $r = q2_putin($t, $s, $T, $new);
                $t->status($r, 200, 'pindahkan folder ke T');
                $t->eq(q2_row($t, $new)['id_archive_parent'], $T, 'folder kini di T');
                $r = q2_putin($t, $s, null, $D);
                $t->status($r, 200, 'dokumen ke root');
                $t->eq(q2_row($t, $D)['id_archive_parent'], null, 'dokumen kini di root');

                // 6. rename, ubah, hapus
                $r = q2_rename($t, $s, $new, $name . 'R');
                $t->status($r, 200, 'rename');
                $t->code($r, 'ARCHIVE206', 'rename');
                $t->eq(q2_row($t, $new)['name'], $name . 'R', 'nama berubah');
                $r = q2_put($t, $s, $new, q2_body($name . 'U'));
                $t->status($r, 200, 'update');
                $t->code($r, 'ARCHIVE207', 'update');
                $t->eq(q2_row($t, $new)['name'], $name . 'U', 'nama berubah (update)');
                $t->eq((int) q2_row($t, $new)['is_folder_permission'], 0, 'update tanpa field permission: tetap 0');
                $r = q2_delete($t, $s, $new);
                $t->status($r, 200, 'delete');
                $t->code($r, 'ARCHIVE203', 'delete');
                $t->eq((int) q2_row($t, $new)['is_active'], 0, 'folder nonaktif');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-4',
        'title' => 'GET archives/{P} (P aktif, B View+Store): is_folder_permission=1, folder_permissions (username, flag, urut username), access milik pemanggil; toggle mati tetap kirim baris; dokumen',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);
            $bName = q2_uname($t);
            $o = q2_others($t, 2);

            try {
                $P = q2_folder($t, 'P', ['perm' => 1]);
                $D = q2_doc($t, 'D', ['parent' => $P]);
                q2_perm($t, $P, $b, 1, 0, 0, 1);
                q2_perm($t, $P, $o[0][0], 1);
                q2_perm($t, $P, $o[1][0], 1, 1, 1, 1);

                $r = q2_show($t, $s, $P);
                $t->status($r, 200, 'show P');
                $t->code($r, 'ARCHIVE200', 'show P');
                $res = $r[1]['result'];
                foreach (['id_archive', 'id_archive_parent', 'name', 'description', 'type', 'status', 'breadcrumbs', 'is_all_location', 'is_active', 'created_by', 'created_at', 'updated_by', 'updated_at', 'is_folder_permission', 'folder_permissions', 'access'] as $k) {
                    $t->has($res, $k, 'show: kunci ' . $k);
                }
                $t->eq($res['is_folder_permission'], 1, 'is_folder_permission = 1');

                $want = [
                    [$b, $bName, 1, 0, 0, 1],
                    [$o[0][0], $o[0][1], 1, 0, 0, 0],
                    [$o[1][0], $o[1][1], 1, 1, 1, 1],
                ];
                usort($want, function ($x, $y) {
                    return strcasecmp($x[1], $y[1]);
                });
                $wantRows = array_map(function ($w) {
                    return ['id_user' => $w[0], 'username' => $w[1], 'is_view' => $w[2], 'is_update' => $w[3], 'is_delete' => $w[4], 'is_store' => $w[5]];
                }, $want);
                $t->eq(json_encode($res['folder_permissions']), json_encode($wantRows), 'folder_permissions: 3 baris, urut username, field persis kontrak');
                q2_access_is($t, $res['access'], [1, 0, 0, 1], 'access B (View+Store)');
                $t->eq($res['access']['manage_permission'], false, 'manage_permission = false (tanpa Update)');

                // toggle dimatikan: baris tetap dikirim (K-10 ii), access = semua true (folder tidak membatasi)
                q2_set_archive($t, $P, ['is_folder_permission' => 0]);
                $r = q2_show($t, $s, $P);
                $t->status($r, 200, 'show P nonaktif');
                $t->eq($r[1]['result']['is_folder_permission'], 0, 'toggle mati: is_folder_permission 0');
                $t->eq(count($r[1]['result']['folder_permissions']), 3, 'toggle mati: 3 baris tetap dikirim');
                q2_access_is($t, $r[1]['result']['access'], q2_all_true(), 'toggle mati: access semua true');

                // dokumen di dalam folder ber-permission (B tanpa View): show dokumen 200, access null, rows []
                q2_set_archive($t, $P, ['is_folder_permission' => 1]);
                q2_unperm($t, $P, $b);
                $r = q2_show($t, $s, $D);
                $t->status($r, 200, 'show dokumen di folder tanpa View (BR-13)');
                $t->eq($r[1]['result']['is_folder_permission'], 0, 'dokumen: is_folder_permission 0');
                $t->eq($r[1]['result']['folder_permissions'], [], 'dokumen: folder_permissions []');
                $t->eq($r[1]['result']['access'], null, 'dokumen: access null');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-7',
        'title' => 'P aktif, B tanpa View: list id_archive=P 403 ARCHIVE407 (formatResponse, result.denied_by); dengan View 200; bentuk body, bahasa ID/EN',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);
            $o = q2_others($t, 1);

            try {
                $G = q2_folder($t, 'G');
                $P = q2_folder($t, 'P', ['perm' => 1, 'parent' => $G]);
                $pName = q2_row($t, $P)['name'];
                $gName = q2_row($t, $G)['name'];
                q2_perm($t, $P, $o[0][0], 1, 1, 1, 1);   // user lain punya hak, B tidak punya baris
                $before = q2_snap($t, [$G, $P]);

                $r = q2_with_user($t, ['lang' => 'ID'], function ($set) use ($t, $s, $P) {
                    return q2_open($t, $s, $P);
                });
                $t->status($r, 403, 'B tanpa baris');
                $body = $r[1];
                $t->eq($body['status'], 'fail', 'status fail');
                $t->eq($body['msg_code'], 'ARCHIVE407', 'msg_code');
                $t->eq($body['message'], 'Anda tidak punya akses ke folder atau dokumen ini', 'pesan ID');
                $t->eq($body['result']['id_archive'], $P, 'result.id_archive');
                $t->eq($body['result']['name'], $pName, 'result.name');
                $t->eq(json_encode($body['result']['denied_by']), json_encode(['id_archive' => $P, 'name' => $pName]), 'result.denied_by = P');
                $t->eq(json_encode($body['result']['breadcrumbs']), json_encode([['value' => $G, 'label' => $gName], ['value' => $P, 'label' => $pName]]), 'result.breadcrumbs = G > P');
                $t->true(!array_key_exists('data', $body['result']), 'tanpa isi folder pada penolakan');

                // baris dengan Update/Delete/Store tetapi tanpa View tidak memberi hak apa pun (BR-3)
                q2_perm($t, $P, $b, 0, 1, 1, 1);
                $r = q2_open($t, $s, $P);
                $t->status($r, 403, 'B baris tanpa View (U/D/S saja)');
                $t->eq($r[1]['msg_code'], 'ARCHIVE407', 'baris tanpa View: msg_code');
                q2_unperm($t, $P, $b);

                // bahasa EN
                q2_with_user($t, ['lang' => 'EN'], function ($set) use ($t, $s, $P) {
                    $r = q2_open($t, $s, $P);
                    $t->status($r, 403, 'EN');
                    $t->eq($r[1]['message'], "You don't have access to this folder or document", 'pesan EN');
                });

                // dengan View: 200
                q2_perm($t, $P, $b, 1);
                $r = q2_open($t, $s, $P);
                $t->status($r, 200, 'B dengan View');
                $t->code($r, 'ARCHIVE200', 'B dengan View');
                q2_access_is($t, $r[1]['result']['access'], [1, 0, 0, 0], 'access B (View saja)');

                // toggle dimatikan: tanpa View pun 200
                q2_unperm($t, $P, $b);
                q2_set_archive($t, $P, ['is_folder_permission' => 0]);
                $r = q2_open($t, $s, $P);
                $t->status($r, 200, 'P nonaktif: B tanpa baris tetap 200');

                // error lain di endpoint list tetap bentuk lama (kontrak §1): 403 lokasi dan 404 = `code` (bukan msg_code/result)
                $H = q2_folder($t, 'H');
                q2_set_archive($t, $H, ['is_all_location' => 0]);              // tanpa tag lokasi = di luar scope lokasi role 3
                $r = q2_open($t, $s, $H);
                $t->status($r, 403, 'folder di luar scope lokasi');
                $t->eq($r[1]['code'] ?? null, 'ARCHIVE407', 'scope lokasi: code ARCHIVE407');
                $t->true(!array_key_exists('msg_code', $r[1]) && !array_key_exists('result', $r[1]), 'scope lokasi: bentuk lama (tanpa msg_code/result.denied_by)');
                $r = q2_open($t, $s, Q2_RANDOM_ID);
                $t->status($r, 404, 'id tidak ada');
                $t->eq($r[1]['code'] ?? null, 'ARCHIVE400', '404: code ARCHIVE400');
                $t->true(!array_key_exists('msg_code', $r[1]), '404: bentuk lama');

                // penolakan tidak mengubah data
                q2_set_archive($t, $P, ['is_folder_permission' => 1]);
                $r = q2_open($t, $s, $P);
                $t->status($r, 403, 'P aktif lagi');
                $now = q2_snap($t, [$G, $P]);
                $t->true(json_encode($before['p']) === json_encode($now['p']), 'baris permission tidak berubah oleh penolakan');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-8',
        'title' => 'Parent dominan: P aktif (B tanpa View) + C aktif (B View) -> buka C 403 denied_by=P; P nonaktif -> 200; folder penolak teratas; induk nonaktif di tengah tidak membatasi',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);

            try {
                $P = q2_folder($t, 'P', ['perm' => 1]);
                $C = q2_folder($t, 'C', ['perm' => 1, 'parent' => $P]);
                $pName = q2_row($t, $P)['name'];
                $cName = q2_row($t, $C)['name'];
                q2_perm($t, $C, $b, 1);

                $r = q2_open($t, $s, $C);
                $t->status($r, 403, 'buka C: P menolak');
                $t->eq($r[1]['msg_code'], 'ARCHIVE407', 'msg_code');
                $t->eq(json_encode($r[1]['result']['denied_by']), json_encode(['id_archive' => $P, 'name' => $pName]), 'denied_by = P (bukan C)');
                $t->eq($r[1]['result']['id_archive'], $C, 'result.id_archive = C yang dibuka');
                $t->eq($r[1]['result']['name'], $cName, 'result.name = C');
                $t->eq(json_encode(array_column($r[1]['result']['breadcrumbs'], 'value')), json_encode([$P, $C]), 'breadcrumbs P > C');

                // P nonaktif -> C (B View) terbuka
                q2_set_archive($t, $P, ['is_folder_permission' => 0]);
                $r = q2_open($t, $s, $C);
                $t->status($r, 200, 'P nonaktif: buka C');
                q2_access_is($t, $r[1]['result']['access'], [1, 0, 0, 0], 'access C');

                // P aktif, B View di P: C (aktif, B View) terbuka
                q2_set_archive($t, $P, ['is_folder_permission' => 1]);
                q2_perm($t, $P, $b, 1);
                $r = q2_open($t, $s, $C);
                $t->status($r, 200, 'B View di P dan C: buka C');

                // B View di P tetapi tidak di C -> denied_by = C
                q2_unperm($t, $C, $b);
                $r = q2_open($t, $s, $C);
                $t->status($r, 403, 'B View di P, tanpa di C');
                $t->eq($r[1]['result']['denied_by']['id_archive'], $C, 'denied_by = C');

                // tiga tingkat: P aktif (B none), M nonaktif, E aktif (B View) -> denied_by = P; dua penolak -> yang teratas
                q2_unperm($t, $P, $b);
                $M = q2_folder($t, 'M', ['perm' => 0, 'parent' => $P]);
                $E = q2_folder($t, 'E', ['perm' => 1, 'parent' => $M]);
                q2_perm($t, $E, $b, 1);
                $r = q2_open($t, $s, $E);
                $t->status($r, 403, 'E di bawah M nonaktif di bawah P');
                $t->eq($r[1]['result']['denied_by']['id_archive'], $P, 'denied_by = P (jalur lewat induk nonaktif)');
                q2_unperm($t, $E, $b);
                $r = q2_open($t, $s, $E);
                $t->eq($r[1]['result']['denied_by']['id_archive'], $P, 'P dan E sama-sama menolak: yang teratas (P)');

                // M (nonaktif) sendiri di bawah P aktif: ditolak oleh P
                $r = q2_open($t, $s, $M);
                $t->status($r, 403, 'buka M (nonaktif) di bawah P aktif');
                $t->eq($r[1]['result']['denied_by']['id_archive'], $P, 'M: denied_by = P');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-10',
        'title' => 'Superadmin is_superadmin=1 dan =2 (juga role ganda 3+2) lolos semua hak di P aktif tanpa baris: buka/show/ubah/rename/Store/subfolder/hapus 200; role 3 ditolak (kontrol)',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);

            $scenario = function ($label) use ($t, $s) {
                $P = q2_folder($t, 'P', ['perm' => 1]);
                $Pdel = q2_folder($t, 'PD', ['perm' => 1]);
                $Pc = q2_folder($t, 'PC', ['perm' => 1, 'parent' => $P]);
                $D = q2_doc($t, 'D');
                $name = q2_row($t, $P)['name'];

                $r = q2_open($t, $s, $P);
                $t->status($r, 200, "$label: buka P");
                q2_access_is($t, $r[1]['result']['access'], q2_all_true(), "$label: result.access");
                $t->eq(q2_ids($r), [$Pc], "$label: isi P");
                q2_access_is($t, q2_find($r, $Pc)['access'], q2_all_true(), "$label: baris Pc");
                $r = q2_open($t, $s, $Pc);
                $t->status($r, 200, "$label: buka Pc (anak aktif)");
                $r = q2_show($t, $s, $P);
                $t->status($r, 200, "$label: show");
                q2_access_is($t, $r[1]['result']['access'], q2_all_true(), "$label: show access");
                $t->status(q2_hist($t, $s, $P), 200, "$label: history");
                $r = q2_put($t, $s, $P, q2_body($name . 'U', ['is_folder_permission' => 1]));
                $t->status($r, 200, "$label: update");
                $t->code($r, 'ARCHIVE207', "$label: update");
                $t->status(q2_rename($t, $s, $P, $name . 'R'), 200, "$label: rename");
                $r = q2_putin($t, $s, $P, $D);
                $t->status($r, 200, "$label: Store dokumen ke P");
                $t->eq(q2_row($t, $D)['id_archive_parent'], $P, "$label: dokumen di P");
                $r = q2_create($t, $s, ['name' => q2_name('SUB'), 'id_archive_parent' => $P, 'is_all_location' => 1]);
                $t->status($r, 200, "$label: subfolder di P");
                $r = q2_putin($t, $s, $Pdel, $Pc);
                $t->status($r, 200, "$label: pindahkan folder ke Pdel");
                $r = q2_putin($t, $s, null, $D);
                $t->status($r, 200, "$label: dokumen keluar ke root");
                $r = q2_delete($t, $s, $Pdel);
                $t->status($r, 200, "$label: hapus folder yang memuat subfolder aktif (tanpa baris)");
                $t->code($r, 'ARCHIVE203', "$label: hapus");
                $t->eq((int) q2_row($t, $Pdel)['is_active'], 0, "$label: Pdel nonaktif");
                $t->eq((int) q2_row($t, $Pc)['is_active'], 0, "$label: Pc ikut nonaktif");
                q2_purge($t);
            };

            try {
                // kontrol: role 3 ditolak di folder yang sama
                $Pc = q2_folder($t, 'CTRL', ['perm' => 1]);
                $r = q2_open($t, $s, $Pc);
                $t->status($r, 403, 'kontrol: role 3 ditolak');
                q2_purge($t);

                foreach ([1 => 'role 1', 2 => 'role 2'] as $role => $label) {
                    q2_with_user($t, ['role' => $role], function ($set) use ($scenario, $label) {
                        $scenario($label);
                    });
                }
                q2_with_user($t, ['role' => [3, 2]], function ($set) use ($scenario) {
                    $scenario('role 3+2');
                });
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-11',
        'title' => 'Pembuat folder (created_by) punya semua hak di folder buatannya tanpa baris; anak aktif buatan orang lain tetap menolak; induk tetap dominan; huruf besar/kecil tidak beda; folder buatan lewat API',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $me = q2_uname($t);
            $o = q2_others($t, 2);

            try {
                // P aktif dibuat B, tanpa baris
                $P = q2_folder($t, 'P', ['perm' => 1, 'by' => $me]);
                $C = q2_folder($t, 'C', ['perm' => 1, 'parent' => $P, 'by' => 'QA02']);   // anak aktif milik orang lain
                $pName = q2_row($t, $P)['name'];
                $cName = q2_row($t, $C)['name'];

                $r = q2_open($t, $s, $P);
                $t->status($r, 200, 'pembuat membuka P');
                q2_access_is($t, $r[1]['result']['access'], q2_all_true(), 'access pembuat di P');
                $r = q2_show($t, $s, $P);
                $t->status($r, 200, 'show P');
                $r = q2_put($t, $s, $P, q2_body($pName . 'U'));
                $t->status($r, 200, 'pembuat mengubah P');
                $t->eq(q2_row($t, $P)['name'], $pName . 'U', 'nama P berubah');
                $pName .= 'U';

                // anak aktif milik orang lain: ditolak di C (denied_by = C)
                $r = q2_open($t, $s, $C);
                $t->status($r, 403, 'pembuat P ditolak di C (anak aktif tanpa hak)');
                $t->eq($r[1]['result']['denied_by']['id_archive'], $C, 'denied_by = C');
                $r = q2_put($t, $s, $C, q2_body($cName . 'U'));
                q2_deny($t, $r, 403, 'ARCHIVE408', 'ubah C', $cName);
                // baris folder C di daftar P membawa access view=false
                $r = q2_open($t, $s, $P);
                q2_access_is($t, q2_find($r, $C)['access'], [0, 0, 0, 0], 'access baris C di daftar P');

                // induk tetap dominan: pembuat C tetapi P (aktif, orang lain, tanpa baris) menolak
                $P2 = q2_folder($t, 'P2', ['perm' => 1, 'by' => 'QA02']);
                $C2 = q2_folder($t, 'C2', ['perm' => 1, 'parent' => $P2, 'by' => $me]);
                $p2Name = q2_row($t, $P2)['name'];
                $r = q2_open($t, $s, $C2);
                $t->status($r, 403, 'pembuat C2 ditolak karena P2 menolak');
                $t->eq($r[1]['result']['denied_by']['id_archive'], $P2, 'denied_by = P2');
                $r = q2_rename($t, $s, $C2, q2_name('X'));
                q2_deny($t, $r, 403, 'ARCHIVE408', 'rename C2', $p2Name);

                // huruf besar/kecil tidak beda (collation DB)
                q2_set_archive($t, $P2, ['created_by' => strtoupper($me)]);
                q2_set_archive($t, $C2, ['is_folder_permission' => 0]);
                $r = q2_open($t, $s, $C2);
                $t->status($r, 200, 'created_by huruf besar tetap dikenali sebagai pembuat');

                // folder dibuat lewat API oleh B, lalu permission diaktifkan oleh pembuat -> tetap boleh
                $name = q2_name('API');
                $r = q2_create($t, $s, ['name' => $name, 'is_all_location' => 1]);
                $t->status($r, 200, 'create-folder API');
                $A = $t->db()->table('archives')->where('name', $name)->value('id_archive');
                $r = q2_put($t, $s, $A, q2_body($name, ['is_folder_permission' => 1, 'folder_permissions' => [
                    ['id_user' => $o[0][0], 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0],
                ]]));
                $t->status($r, 200, 'pembuat mengaktifkan permission folder buatannya');
                $t->eq((int) q2_row($t, $A)['is_folder_permission'], 1, 'aktif');
                $r = q2_open($t, $s, $A);
                $t->status($r, 200, 'pembuat tetap bisa membuka sesudah aktif (tanpa barisnya sendiri)');
                q2_access_is($t, $r[1]['result']['access'], q2_all_true(), 'access pembuat');
                $r = q2_delete($t, $s, $A);
                $t->status($r, 200, 'pembuat menghapus folder buatannya');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-15',
        'title' => 'B tanpa View di P: search nomor dokumen di P tetap menemukannya; baris folder hasil search access.view=false; show/history P 403 ARCHIVE407; show/history dokumen 200; search+id_archive tidak ditolak',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);

            try {
                $P = q2_folder($t, 'P', ['perm' => 1]);
                $C = q2_folder($t, 'C', ['perm' => 1, 'parent' => $P]);
                $D = q2_doc($t, 'DOC', ['parent' => $P]);
                $Dc = q2_doc($t, 'DOC', ['parent' => $C]);
                $pName = q2_row($t, $P)['name'];
                $dName = q2_row($t, $D)['name'];
                $dcName = q2_row($t, $Dc)['name'];

                // search dokumen (lintas folder)
                $r = q2_list($t, $s, ['search' => json_encode(['query' => $dName]), 'pagination' => 50]);
                $t->status($r, 200, 'search dokumen di P');
                $row = q2_find($r, $D);
                $t->true($row !== null, 'dokumen di P (B tanpa View) muncul di pencarian');
                $t->eq($row['access'], null, 'baris dokumen: access null');
                $t->eq($row['is_folder_permission'], 0, 'baris dokumen: is_folder_permission 0');
                $t->eq($row['name'][0]['transaction_no'], $dName, 'nomor dokumen');
                $t->eq($r[1]['result']['access'], null, 'result.access null saat mencari');
                $r = q2_list($t, $s, ['search' => json_encode(['query' => $dcName]), 'pagination' => 50]);
                $t->true(q2_find($r, $Dc) !== null, 'dokumen di C (folder dalam P) juga muncul');

                // search folder: baris folder membawa access.view=false & is_folder_permission=1
                $r = q2_list($t, $s, ['search' => json_encode(['query' => $pName]), 'pagination' => 50]);
                $t->status($r, 200, 'search folder');
                $row = q2_find($r, $P);
                $t->true($row !== null, 'folder P (tanpa View) muncul di pencarian');
                q2_access_is($t, $row['access'], [0, 0, 0, 0], 'baris folder P hasil search');
                $t->eq($row['is_folder_permission'], 1, 'baris folder P: is_folder_permission 1');

                // search + id_archive: bukan membuka folder -> 200
                $r = q2_list($t, $s, ['id_archive' => $P, 'search' => json_encode(['query' => $dName]), 'pagination' => 50]);
                $t->status($r, 200, 'search dengan id_archive=P');
                $t->true(q2_find($r, $D) !== null, 'dokumen tetap muncul');
                $t->eq($r[1]['result']['access'], null, 'result.access null saat mencari');
                // hanya showRelatedTransaction bukan pencarian: tetap membuka folder -> 403 bentuk formatResponse
                $r = q2_list($t, $s, ['id_archive' => $P, 'search' => json_encode(['showRelatedTransaction' => true])]);
                $t->status($r, 403, 'showRelatedTransaction saja = membuka folder');
                $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE407', 'msg_code');

                // show / history
                q2_deny($t, q2_show($t, $s, $P), 403, 'ARCHIVE407', 'show P', $pName);
                q2_deny($t, q2_hist($t, $s, $P), 403, 'ARCHIVE407', 'history P', $pName);
                $r = q2_show($t, $s, $D);
                $t->status($r, 200, 'show dokumen');
                $r = q2_hist($t, $s, $D);
                $t->status($r, 200, 'history dokumen (tidak dicek hak folder)');
                $t->has($r[1], 'result.data', 'history dokumen: result.data');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

];
