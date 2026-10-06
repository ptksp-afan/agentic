<?php
/**
 * ED-1024 - pemeriksaan tambahan (di luar baris AC, memperkuat BR): BR-8 (scan nama tidak dibatasi lokasi),
 * pesan dua bahasa, tanpa state basi antar-request (pengganti isolasi tenant: QA_DBS hanya 1 DB), kontrak
 * respons, role ganda/nonaktif, lokasi nonaktif (BR-2), input aneh tidak 500.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'X-1',
        'title' => 'BR-8/K-5: hand-over, receive, put-in lewat name pada dokumen {SMR} oleh user JOG tidak dibatasi lokasi',
        'run'   => function ($t) {
            $s = $t->session();
            $base = 'api/v5/document-archive';
            q1_purge($t);
            $doc = q1_add($t, ['name' => 'QA01-SMRDOC-SCAN', 'type' => 2, 'all' => 0, 'locs' => ['SMR'], 'doc' => ['type' => 6]]);
            $folder = q1_add($t, ['name' => 'QA01-ALLFOLDER', 'type' => 1, 'all' => 1]);

            try {
                q1_with_user($t, ['role' => 3, 'emp' => ['JOG']], function ($set) use ($t, $s, $base, $doc, $folder) {
                    $r = $t->call($s, 'POST', "$base/documents/hand-over", ['name' => 'QA01-SMRDOC-SCAN']);
                    $t->status($r, 200, 'hand-over dokumen {SMR} oleh user JOG');
                    $t->code($r, 'ARCHIVE209', 'hand-over');
                    $t->true(isset($r[1]['result']['action']), 'hand-over: result.action ada (kontrak)');
                    $t->eq((int) q1_row($t, $doc)['status'], 2, 'DB: status = 2 (given)');

                    $r = $t->call($s, 'POST', "$base/documents/receive", ['name' => 'QA01-SMRDOC-SCAN']);
                    $t->status($r, 200, 'receive dokumen {SMR} oleh user JOG');
                    $t->code($r, 'ARCHIVE210', 'receive');
                    $t->eq((int) q1_row($t, $doc)['status'], 3, 'DB: status = 3 (received)');

                    $r = $t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $folder, 'name' => 'QA01-SMRDOC-SCAN']);
                    $t->status($r, 200, 'put-in lewat name dokumen {SMR} ke folder semua lokasi');
                    $t->code($r, 'ARCHIVE204', 'put-in name');
                    $t->eq(q1_row($t, $doc)['id_archive_parent'], $folder, 'DB: dokumen masuk folder');
                    $t->true(is_array($r[1]['result']), 'put-in: result berupa array baris');
                });
            } finally {
                q1_purge($t);
            }
            q1_assert_clean($t);
        },
    ],

    [
        'id'    => 'X-2',
        'title' => 'Pesan ARCHIVE400..407/418 ada di en_EN dan id_ID (bukan kode mentah) dan berbeda antar bahasa',
        'run'   => function ($t) {
            $s = $t->session();
            $base = 'api/v5/document-archive';
            q1_purge($t);
            $x = Q1_RANDOM_ID;
            $f = q1_add($t, ['name' => 'QA01-F', 'type' => 1, 'all' => 1]);
            $dup = q1_add($t, ['name' => 'QA01-DUP', 'type' => 1, 'all' => 1]);
            $child = q1_add($t, ['name' => 'QA01-CHILD', 'type' => 1, 'all' => 1, 'parent' => $f]);
            $subSmr = q1_add($t, ['name' => 'QA01-SUBSMR', 'type' => 1, 'all' => 0, 'locs' => ['SMR'], 'parent' => $f]);
            $doc = q1_add($t, ['name' => 'QA01-DOC', 'type' => 2, 'all' => 1, 'parent' => $child, 'doc' => ['type' => 6]]);
            $smr = q1_id_by_name($t, 'CABANG - SEMARANG', null);
            $jog = q1_id_by_name($t, 'CABANG - JOGJA', null);
            $loc = q1_locs($t);

            // [code => callable yang menghasilkan respons]; satu per kode
            $makers = [
                'ARCHIVE400' => function () use ($t, $s, $base, $x) { return $t->call($s, 'GET', "$base/archives/$x"); },
                'ARCHIVE401' => function () use ($t, $s, $base, $f) { return $t->call($s, 'DELETE', "$base/archives/delete/$f"); },
                'ARCHIVE402' => function () use ($t, $s, $base, $jog, $loc) { return $t->call($s, 'POST', "$base/archives/create-folder", ['name' => 'QA01-L', 'is_all_location' => 0, 'id_locations' => [$loc['SMR']], 'id_archive_parent' => $jog]); },
                'ARCHIVE403' => function () use ($t, $s, $base, $jog, $doc, $loc) {
                    q1_set_locs($t, $doc, ['SMR']);
                    q1_set_archive($t, $doc, ['is_all_location' => 0]);

                    return $t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $jog, 'id_archives' => [$doc]]);
                },
                'ARCHIVE404' => function () use ($t, $s, $base, $f) { return $t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $f, 'id_archives' => [$f]]); },
                'ARCHIVE405' => function () use ($t, $s, $base) { return $t->call($s, 'POST', "$base/archives/create-folder", ['name' => 'QA01-DUP', 'is_all_location' => 1]); },
                'ARCHIVE406' => function () use ($t, $s, $base, $f) { return $t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $f]); },
            ];
            $statuses = ['ARCHIVE400' => 404, 'ARCHIVE401' => 400, 'ARCHIVE402' => 400, 'ARCHIVE403' => 400, 'ARCHIVE404' => 400, 'ARCHIVE405' => 400, 'ARCHIVE406' => 400, 'ARCHIVE407' => 403, 'ARCHIVE418' => 403];

            $messages = [];
            try {
                foreach (['ID', 'EN'] as $lang) {
                    q1_with_user($t, ['role' => 3, 'emp' => 'all', 'lang' => $lang], function ($set) use ($t, $s, $base, $makers, $statuses, $smr, $x, &$messages, $lang, $f, $subSmr, $child) {
                        foreach ($makers as $code => $make) {
                            $r = $make();
                            $t->status($r, $statuses[$code], "$lang $code");
                            $t->code($r, $code, "$lang $code");
                            $messages[$lang][$code] = (string) ($r[1]['message'] ?? '');
                        }
                        // ARCHIVE407 dan ARCHIVE418 oleh user JOG
                        $set(['role' => 3, 'emp' => ['JOG'], 'lang' => $lang]);
                        $r = $t->call($s, 'GET', "$base/archives/$smr");
                        $t->status($r, 403, "$lang ARCHIVE407");
                        $messages[$lang]['ARCHIVE407'] = (string) ($r[1]['message'] ?? '');
                        $r = $t->call($s, 'DELETE', "$base/archives/delete/$f");
                        $t->code($r, 'ARCHIVE418', "$lang ARCHIVE418");
                        $messages[$lang]['ARCHIVE418'] = (string) ($r[1]['message'] ?? '');
                    });
                    // kembalikan tag dokumen untuk kode 403 berikutnya (dibuat ulang tiap bahasa)
                }
            } finally {
                q1_purge($t);
            }
            foreach ($statuses as $code => $_) {
                foreach (['ID', 'EN'] as $lang) {
                    $m = $messages[$lang][$code] ?? '';
                    $t->true($m !== '' && $m !== $code && strpos($m, 'ARCHIVE') === false, "$lang $code: pesan terjemahan ada ('$m')");
                }
                $t->true(($messages['ID'][$code] ?? '') !== ($messages['EN'][$code] ?? ''), "$code: teks ID berbeda dari EN");
            }
            $t->eq($messages['EN']['ARCHIVE407'], "You don't have access to this folder or document", 'EN ARCHIVE407 sesuai kontrak');
            $t->eq($messages['ID']['ARCHIVE407'], 'Anda tidak punya akses ke folder atau dokumen ini', 'ID ARCHIVE407 sesuai kontrak');
            $t->eq($messages['EN']['ARCHIVE418'], "Folder can't be deleted, it contains subfolders you can't access or delete", 'EN ARCHIVE418 sesuai kontrak');
            $t->eq($messages['ID']['ARCHIVE418'], 'Folder tidak dapat dihapus, berisi subfolder yang tidak dapat Anda akses atau hapus', 'ID ARCHIVE418 sesuai kontrak');
            q1_assert_clean($t);
        },
    ],

    [
        'id'    => 'X-3',
        'title' => 'Tanpa state basi antar-request: user JOG -> superadmin -> JOG -> tanpa employee bergantian, hasil mengikuti keadaan terbaru (pengganti isolasi tenant; QA_DBS hanya 1 DB)',
        'run'   => function ($t) {
            $s = $t->session();
            $loc = q1_locs($t);
            q1_purge($t);
            q1_add($t, ['name' => 'QA01-TANPA-LOKASI', 'type' => 1, 'all' => 0, 'locs' => []]);

            try {
                q1_with_user($t, ['role' => 3, 'emp' => ['JOG']], function ($set) use ($t, $s, $loc) {
                    $states = [
                        'jog'   => [['role' => 3, 'emp' => ['JOG']], [$loc['JOG']], false],
                        'super' => [['role' => 1, 'emp' => ['JOG']], null, true],
                        'none'  => [['role' => 3, 'emp' => 'none'], [], false],
                        'smr'   => [['role' => 3, 'emp' => ['SMR']], [$loc['SMR']], false],
                    ];
                    for ($i = 0; $i < 3; $i++) {
                        foreach ($states as $name => $st) {
                            $set($st[0]);
                            $r = q1_list($t, $s, ['pagination' => 100]);
                            $t->status($r, 200, "putaran $i $name");
                            $t->eq($r[1]['result']['total'], q1_expected_count($t, null, $st[1]), "putaran $i $name: total mengikuti keadaan user saat ini");
                            $n = q1_names($r);
                            $t->eq(in_array('QA01-TANPA-LOKASI', $n, true), $st[2], "putaran $i $name: folder tanpa lokasi " . ($st[2] ? 'tampil' : 'tidak tampil'));
                        }
                    }
                });
            } finally {
                q1_purge($t);
            }
            q1_assert_clean($t);
        },
    ],

    [
        'id'    => 'X-4',
        'title' => 'Kontrak: bentuk result list/show/history/create/update/rename/delete/put-in/select locations sesuai contract.md',
        'run'   => function ($t) {
            $s = $t->session();
            $base = 'api/v5/document-archive';
            q1_purge($t);
            $loc = q1_locs($t);
            $bk = q1_id_by_name($t, 'Backup Arsip', null);
            $jog = q1_id_by_name($t, 'CABANG - JOGJA', null);

            try {
                // list
                $r = q1_list($t, $s, ['id_archive' => $bk, 'pagination' => 5]);
                $t->status($r, 200, 'list');
                $t->eq($r[1]['status'], 'success', 'envelope status');
                $t->code($r, 'ARCHIVE200', 'list msg_code');
                foreach (['current_page', 'data', 'per_page', 'total', 'columns', 'queries', 'breadcrumbs'] as $k) {
                    $t->has($r[1], "result.$k", "list: result.$k");
                }
                $row = $r[1]['result']['data'][0];
                foreach (['id_archive', 'name', 'type', 'status', 'history', 'updated_at', 'breadcrumbs'] as $k) {
                    $t->true(array_key_exists($k, $row), "list: baris memuat $k");
                }
                $t->has($row, 'name.0.transaction_no', 'list: name[0].transaction_no');
                $t->true(array_key_exists('id_transaction', $row['name'][0]) && array_key_exists('transaction_type', $row['name'][0]), 'list: name[0] memuat id_transaction & transaction_type');
                $t->true(isset($r[1]['result']['breadcrumbs'][0]['value'], $r[1]['result']['breadcrumbs'][0]['label']), 'list: breadcrumbs [{value,label}]');
                $t->eq($r[1]['result']['breadcrumbs'][0]['label'], 'Backup Arsip', 'list: breadcrumb folder terbuka');

                $typeQuery = array_values(array_filter($r[1]['result']['queries'], function ($q) { return ($q['data_index'] ?? null) === 'type'; }));
                $t->eq($typeQuery[0]['endpoint'] ?? null, 'select/document-archive/archive/types', 'queries: filter Type memakai endpoint types (opsi Billing ikut otomatis)');

                // show
                $r = $t->call($s, 'GET', "$base/archives/$jog");
                $t->status($r, 200, 'show');
                $t->eq($r[1]['result']['id_locations'], [$loc['JOG']], 'show: id_locations (is_all_location=0)');
                $t->true(!array_key_exists('history', $r[1]['result']) && !array_key_exists('archive_locations', $r[1]['result']), 'show: tanpa history/archive_locations');
                $r = $t->call($s, 'GET', "$base/archives/$bk");
                $t->true(!array_key_exists('id_locations', $r[1]['result']), 'show: folder semua lokasi tanpa id_locations');

                // history
                $r = $t->call($s, 'GET', "$base/archives/history/$jog");
                $t->status($r, 200, 'history');
                $t->has($r[1], 'result.data', 'history: result.data');
                $t->has($r[1], 'result.action_labels', 'history: result.action_labels');

                // create / update / rename / delete / put-in
                $r = $t->call($s, 'POST', "$base/archives/create-folder", ['name' => 'QA01-C1', 'is_all_location' => 1]);
                $t->status($r, 200, 'create-folder');
                $t->code($r, 'ARCHIVE201', 'create-folder');
                $t->eq(array_keys($r[1]['result']), ['id_archive'], 'create-folder: result = {id_archive}');
                $id = $r[1]['result']['id_archive'];
                $r = $t->call($s, 'PUT', "$base/archives/$id", ['name' => 'QA01-C1', 'is_all_location' => 1]);
                $t->code($r, 'ARCHIVE207', 'update');
                $t->eq($r[1]['result']['id_archive'] ?? null, $id, 'update: result.id_archive');
                $r = $t->call($s, 'PUT', "$base/archives/rename/$id", ['name' => 'QA01-C2']);
                $t->code($r, 'ARCHIVE206', 'rename');
                $t->eq($r[1]['result']['id_archive'] ?? null, $id, 'rename: result.id_archive');
                $t->eq(q1_row($t, $id)['name'], 'QA01-C2', 'DB: nama berubah');
                $doc = q1_add($t, ['name' => 'QA01-D1', 'type' => 2, 'all' => 1, 'doc' => ['type' => 6]]);
                $r = $t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $id, 'id_archives' => [$doc]]);
                $t->code($r, 'ARCHIVE204', 'put-in');
                $t->true(is_array($r[1]['result']) && isset($r[1]['result'][0]['id_archive']), 'put-in: result = array baris dipindah');
                $t->true(!isset($r[1]['result'][0]['history']), 'put-in: history tidak ikut');
                $r = $t->call($s, 'DELETE', "$base/archives/delete/" . q1_add($t, ['name' => 'QA01-C3', 'type' => 1, 'all' => 1]));
                $t->status($r, 200, 'delete');
                $t->code($r, 'ARCHIVE203', 'delete');
                $t->eq($r[1]['result'], null, 'delete: result null');

                // select locations (tidak berubah)
                $r = $t->call($s, 'GET', q1_url('api/v5/select/document-archive/archive/locations', ['id_archive_parent' => $jog]));
                $t->status($r, 200, 'select locations');
                $t->eq(array_column($r[1]['result']['options'], 'value'), [$loc['JOG']], 'select locations: dibatasi lokasi induk (K-8 a)');
            } finally {
                q1_purge($t);
            }
            q1_assert_clean($t);
        },
    ],

    [
        'id'    => 'X-5',
        'title' => 'Role ganda & role nonaktif untuk bypass: bypass bila salah satu role aktif is_superadmin 1/2; role superadmin nonaktif tidak membypass',
        'run'   => function ($t) {
            $s = $t->session();
            q1_purge($t);
            q1_add($t, ['name' => 'QA01-TANPA-LOKASI', 'type' => 1, 'all' => 0, 'locs' => []]);
            $roleSnap = (array) $t->db()->table('roles')->where('id_role', 2)->first();

            $seen = function () use ($t, $s) {
                $r = q1_list($t, $s, ['pagination' => 100]);

                return [$r[0], in_array('QA01-TANPA-LOKASI', q1_names($r), true), $r];
            };

            try {
                q1_with_user($t, ['role' => [29, 2], 'emp' => ['JOG']], function ($set) use ($t, $seen) {
                    list($st, $has) = $seen();
                    $t->status([$st], 200, 'role ganda [29, 2]');
                    $t->true($has, 'role ganda [29 (List), 2 (superadmin 2)]: bypass');

                    $set(['role' => [16, 1], 'emp' => ['JOG']]);
                    list($st, $has) = $seen();
                    $t->true($has, 'role ganda [16, 1]: bypass');

                    $set(['role' => [3, 6], 'emp' => ['JOG']]);
                    list($st, $has) = $seen();
                    $t->true(!$has, 'role ganda [3, 6] (tanpa superadmin 1/2): tidak bypass');
                });

                // role 2 dinonaktifkan sementara: tidak membypass lagi
                q1_w($t, function ($c) { $c->table('roles')->where('id_role', 2)->update(['is_active' => 0]); });
                q1_with_user($t, ['role' => [3, 2], 'emp' => ['JOG']], function ($set) use ($t, $seen) {
                    list($st, $has) = $seen();
                    $t->status([$st], 200, 'role [3, 2 nonaktif]');
                    $t->true(!$has, 'role superadmin 2 nonaktif: tidak membypass (BR-3 "role aktif")');
                });
            } finally {
                q1_w($t, function ($c) use ($roleSnap) { $c->table('roles')->where('id_role', 2)->update(['is_active' => $roleSnap['is_active']]); });
                q1_purge($t);
            }
            $t->true(json_encode((array) $t->db()->table('roles')->where('id_role', 2)->first()) === json_encode($roleSnap), 'role 2 dipulihkan persis');
            q1_assert_clean($t);
        },
    ],

    [
        'id'    => 'X-6',
        'title' => 'BR-2 (perlu konfirmasi): lokasi JOG dinonaktifkan sementara - catat apakah user JOG masih melihat baris bertag JOG (karakterisasi, tidak FAIL)',
        'run'   => function ($t) {
            $s = $t->session();
            $loc = q1_locs($t);
            $snap = (array) $t->db()->table('locations')->where('id_location', $loc['JOG'])->first();
            $names = function () use ($t, $s) {
                $r = q1_list($t, $s, ['pagination' => 100]);
                $t->status($r, 200, 'root');

                return [q1_names($r), $r[1]['result']['total']];
            };

            try {
                q1_with_user($t, ['role' => 3, 'emp' => ['JOG']], function ($set) use ($t, $names, $loc) {
                    list($n1) = $names();
                    $t->true(in_array('CABANG - JOGJA', $n1, true), 'baseline: JOG aktif -> CABANG - JOGJA tampil');

                    q1_w($t, function ($c) use ($loc) { $c->table('locations')->where('id_location', $loc['JOG'])->update(['is_active' => 0]); });
                    list($n2, $total) = $names();
                    // PERLU DIKONFIRMASI (bukan FAIL): BR-2 menyebut "hanya lokasi aktif", tetapi MyHelper::getUserLocation()
                    // (dasar BR-2) tidak menyaring locations.is_active. Skenario hanya mencatat perilaku sebenarnya.
                    $t->note('BR-2 lokasi nonaktif: CABANG - JOGJA ' . (in_array('CABANG - JOGJA', $n2, true) ? 'MASIH tampil (lokasi nonaktif tetap dihitung; tidak sesuai kalimat "hanya lokasi aktif" di BR-2)' : 'tidak tampil (sesuai BR-2)') . "; total=$total");
                });
            } finally {
                q1_w($t, function ($c) use ($loc, $snap) { $c->table('locations')->where('id_location', $loc['JOG'])->update(['is_active' => $snap['is_active']]); });
            }
            $t->true(json_encode((array) $t->db()->table('locations')->where('id_location', $loc['JOG'])->first()) === json_encode($snap), 'lokasi JOG dipulihkan persis');
        },
    ],

    [
        'id'    => 'X-7',
        'title' => 'Input aneh pada jalur yang diubah tidak menghasilkan 5xx (array/kosong/skalar)',
        'run'   => function ($t) {
            $s = $t->session();
            $base = 'api/v5/document-archive';
            q1_purge($t);
            $f = q1_add($t, ['name' => 'QA01-F', 'type' => 1, 'all' => 1]);
            $d = q1_add($t, ['name' => 'QA01-D', 'type' => 2, 'all' => 1, 'doc' => ['type' => 6]]);
            $d2 = q1_add($t, ['name' => 'QA01-D2', 'type' => 2, 'all' => 1, 'doc' => ['type' => 6]]);

            $cases = [
                'list id_archive[]'              => function () use ($t, $s, $base) { return $t->call($s, 'GET', "$base/archives?id_archive[]=a&id_archive[]=b"); },
                'list id_archive kosong'         => function () use ($t, $s) { return q1_list($t, $s, ['id_archive' => '', 'pagination' => 5]); },
                'put-in id_archives skalar'      => function () use ($t, $s, $base, $f, $d) { return $t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $f, 'id_archives' => $d]); },
                'put-in id_archives kosong []'   => function () use ($t, $s, $base, $f) { return $t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $f, 'id_archives' => []]); },
                'put-in parent array'            => function () use ($t, $s, $base, $d2) { return $t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => ['a', 'b'], 'id_archives' => [$d2]]); },
                'put-in id_archives bersarang'   => function () use ($t, $s, $base, $f) { return $t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $f, 'id_archives' => [['x']]]); },
                'create-folder parent array'     => function () use ($t, $s, $base) { return $t->call($s, 'POST', "$base/archives/create-folder", ['name' => 'QA01-N', 'is_all_location' => 1, 'id_archive_parent' => ['a']]); },
                'update parent array'            => function () use ($t, $s, $base, $f) { return $t->call($s, 'PUT', "$base/archives/$f", ['name' => 'QA01-F', 'is_all_location' => 1, 'id_archive_parent' => ['a']]); },
                'update id_locations skalar'     => function () use ($t, $s, $base, $f) { return $t->call($s, 'PUT', "$base/archives/$f", ['name' => 'QA01-F', 'is_all_location' => 0, 'id_locations' => 'x']); },
                'create-folder id_locations ""'  => function () use ($t, $s, $base, $f) { return $t->call($s, 'POST', "$base/archives/create-folder", ['name' => 'QA01-N2', 'is_all_location' => 0, 'id_locations' => [], 'id_archive_parent' => $f]); },
                'create-folder tanpa body'       => function () use ($t, $s, $base) { return $t->call($s, 'POST', "$base/archives/create-folder", []); },
                'hand-over tanpa body'           => function () use ($t, $s, $base) { return $t->call($s, 'POST', "$base/documents/hand-over", []); },
                'delete id acak berspasi'        => function () use ($t, $s, $base) { return $t->call($s, 'DELETE', "$base/archives/delete/%20"); },
            ];
            $out = [];
            $fives = [];
            try {
                foreach ([['asli', null], ['JOG', ['role' => 3, 'emp' => ['JOG']]]] as $mode) {
                    $run = function () use ($cases, $t, &$out, &$fives, $mode) {
                        foreach ($cases as $label => $fn) {
                            $r = $fn();
                            $out[] = $mode[0] . ' | ' . $label . ' -> ' . $r[0] . ' ' . (q1_code($r) ?? '-');
                            if ($r[0] >= 500) {
                                $fives[] = $mode[0] . ' | ' . $label . ' -> HTTP ' . $r[0];
                            }
                        }
                    };
                    if ($mode[1] === null) {
                        $run();
                    } else {
                        q1_with_user($t, $mode[1], function ($set) use ($run) { $run(); });
                    }
                }
            } finally {
                q1_purge($t);
                $t->note(implode(' ; ', $out));
            }
            q1_assert_clean($t);
            $t->eq($fives, [], 'tidak ada respons 5xx untuk input aneh');
        },
    ],

    [
        'id'    => 'X-8',
        'title' => 'Kontrol positif: user JOG tetap bisa show/history/create/update/rename/put-in/delete pada baris dalam scope-nya (scope tidak over-blocking)',
        'run'   => function ($t) {
            $s = $t->session();
            $base = 'api/v5/document-archive';
            $loc = q1_locs($t);
            q1_purge($t);
            $folder = q1_add($t, ['name' => 'QA01-JOGF', 'type' => 1, 'all' => 0, 'locs' => ['JOG']]);
            $doc = q1_add($t, ['name' => 'QA01-JOGD', 'type' => 2, 'all' => 0, 'locs' => ['JOG'], 'doc' => ['type' => 6]]);
            $allDoc = q1_add($t, ['name' => 'QA01-ALLD', 'type' => 2, 'all' => 1, 'doc' => ['type' => 6]]);

            try {
                q1_with_user($t, ['role' => 3, 'emp' => ['JOG']], function ($set) use ($t, $s, $base, $loc, $folder, $doc, $allDoc) {
                    $t->status($t->call($s, 'GET', "$base/archives/$folder"), 200, 'JOG: show folder JOG');
                    $t->status($t->call($s, 'GET', "$base/archives/history/$folder"), 200, 'JOG: history folder JOG');
                    $r = q1_list($t, $s, ['id_archive' => $folder]);
                    $t->status($r, 200, 'JOG: list isi folder JOG');
                    $r = $t->call($s, 'POST', "$base/archives/create-folder", ['name' => 'QA01-JOGSUB', 'is_all_location' => 0, 'id_locations' => [$loc['JOG']], 'id_archive_parent' => $folder]);
                    $t->status($r, 200, 'JOG: create-folder di folder JOG');
                    $sub = $r[1]['result']['id_archive'];
                    $r = $t->call($s, 'PUT', "$base/archives/$sub", ['name' => 'QA01-JOGSUB', 'is_all_location' => 0, 'id_locations' => [$loc['JOG']]]);
                    $t->status($r, 200, 'JOG: update subfolder JOG');
                    $r = $t->call($s, 'PUT', "$base/archives/rename/$sub", ['name' => 'QA01-JOGSUB2']);
                    $t->status($r, 200, 'JOG: rename subfolder JOG');
                    $r = $t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $sub, 'id_archives' => [$doc]]);
                    $t->status($r, 200, 'JOG: put-in dokumen JOG ke subfolder JOG');
                    $r = $t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $folder, 'id_archives' => [$allDoc]]);
                    $t->code($r, 'ARCHIVE403', 'JOG: dokumen semua lokasi ke folder {JOG} ditolak lokasi (bukan scope)');
                    $r = $t->call($s, 'DELETE', "$base/archives/delete/$sub");
                    $t->status($r, 400, 'JOG: delete subfolder berisi dokumen aktif');
                    $t->code($r, 'ARCHIVE401', 'JOG: delete subfolder berisi dokumen aktif');
                    $r = $t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => null, 'id_archives' => [$doc]]);
                    $t->status($r, 200, 'JOG: take out ke root');
                    $r = $t->call($s, 'DELETE', "$base/archives/delete/$sub");
                    $t->status($r, 200, 'JOG: delete subfolder kosong');
                    $t->eq((int) q1_row($t, $sub)['is_active'], 0, 'DB: subfolder is_active = 0');
                });
            } finally {
                q1_purge($t);
            }
            q1_assert_clean($t);
        },
    ],

    [
        'id'    => 'X-9',
        'title' => 'BR-9: pencatatan saat cetak PDF tidak difilter scope user - user JOG mencetak SO bertag SMR tetap membuat baris archive',
        'run'   => function ($t) {
            $s = $t->session();
            $loc = q1_locs($t);
            $so = $t->db()->selectOne("select s.id_sales_order, s.sales_order_no, s.id_location from sales_orders s left join archives a on a.name = s.sales_order_no where a.id_archive is null and s.id_location = ? order by s.sales_order_no desc limit 1", [$loc['SMR']]);
            if (!$so) {
                $t->blocked('tidak ada SO bertag SMR tanpa baris archive');
            }
            $cleanup = function () use ($t, $so) {
                q1_w($t, function ($c) use ($so) {
                    $ids = $c->table('archives')->where('name', $so->sales_order_no)->pluck('id_archive')->all();
                    if ($ids) {
                        $c->table('archive_documents')->whereIn('id_archive', $ids)->delete();
                        $c->table('archive_locations')->whereIn('id_archive', $ids)->delete();
                        $c->table('archives')->whereIn('id_archive', $ids)->delete();
                    }
                });
            };

            try {
                q1_with_user($t, ['role' => 3, 'emp' => ['JOG']], function ($set) use ($t, $s, $so, $loc) {
                    $r = $t->callFile($s, 'GET', 'api/v5/sales/sales-orders/' . $so->id_sales_order . '/pdf');
                    $t->status($r, 200, 'user JOG mencetak PDF SO {SMR}');
                    $t->rowCount(q1_dbname($t), 'archives', ['name' => $so->sales_order_no], 1, 'baris archive tercatat walau di luar lokasi user');
                    $id = $t->db()->table('archives')->where('name', $so->sales_order_no)->value('id_archive');
                    $t->eq(q1_loc_codes($t, $id), ['SMR'], 'lokasi baris = lokasi SO');
                });
            } finally {
                $cleanup();
            }
            $t->rowCount(q1_dbname($t), 'archives', ['name' => $so->sales_order_no], 0, 'baris uji dibuang');
        },
    ],

];
