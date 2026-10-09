<?php
/**
 * ED-1029 (rev 2026-10-08, ED-1103) - AC-21 (permission Folder Permissions pada kedua route; profil A, M, D, E, superadmin 1/2),
 * AC-21b (urutan penolakan BR-13), AC-22 (data J: kelola semua folder On, JC Off 441), AC-23 (folder tanpa View tetap bisa
 * dikelola), AC-25 (sisi BE: baris otomatis tab Izin = baris tersimpan biasa), AC-26-BE (pendukung AC-26 [FE]: cabut View sendiri
 * 200 tanpa penolakan, sel tetap bisa dikelola), AC-27 (bentuk node tanpa created_by).
 *
 * Profil (spec § 6): A = QA_USER dengan role fixture [List Archive, View Folder, Update Folder, Folder Permissions]; M = tanpa Update
 * Folder; D = Update Folder tanpa Folder Permissions; E = tanpa keduanya. Semua permission BERNAMA (tanpa id literal); role fixture
 * QA29-ROLE-* dibuang di finally. Data folder berawalan QA29-. Pemanggil non-bypass dibatasi lokasi SMR.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-21',
        'title' => '(rev) D (Update Folder tanpa Folder Permissions) dan E (tanpa keduanya): GET dan PUT (sah, tidak sah, user tidak ada, [], folder L) user-permissions/{B} = 403 GE0114 parameter "Folder Permissions" (mendahului 404/422/407), data tetap; A, M (tanpa Update Folder), superadmin 1 dan 2: GET 200, PUT [P: View] 200 (baris + riwayat oleh pemanggil)',
        'run'   => function ($t) {
            q9_recover($t);
            q9_role_purge($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b] = q9_others($t, 1);

            try {
                $roles = q9_roles_profile($t);
                $f = q9_fixture($t, $me, $meName, $b[0]);
                $ids = array_values($f);
                $before = q9_snap($t, $ids);
                $permBefore = q9_perm_total($t);
                q9_codes_reset();

                foreach (['D', 'E'] as $p) {
                    foreach (['EN', 'ID'] as $lang) {
                        q9_with_user($t, ['role' => $roles[$p], 'emp' => ['SMR'], 'lang' => $lang], function ($set) use ($t, $s, $b, $f, $p, $lang) {
                            $cases = [
                                'GET'                       => null,
                                'PUT sah'                   => [$b[0], ['permissions' => [q9_row_body($f['P'], 1)]]],
                                'PUT tidak sah (flag 9)'    => [$b[0], ['permissions' => [q9_row_body($f['P'], 9)]]],
                                'PUT tanpa permissions'     => [$b[0], []],
                                'PUT []'                    => [$b[0], ['permissions' => []]],
                                'PUT user tidak ada (sah)'  => [Q9_RANDOM_ID, ['permissions' => [q9_row_body($f['P'], 1)]]],
                                'PUT user tidak ada + tidak sah' => [Q9_RANDOM_ID, ['permissions' => [q9_row_body($f['P'], 9)]]],
                                'PUT folder L (luar scope)' => [$b[0], ['permissions' => [q9_row_body($f['L'], 1)]]],
                                'PUT folder Off C2'         => [$b[0], ['permissions' => [q9_row_body($f['C2'], 1)]]],
                                'PUT id tidak ada'          => [$b[0], ['permissions' => [q9_row_body(Q9_RANDOM_ID, 1)]]],
                            ];
                            foreach ($cases as $label => $c) {
                                $r = $c === null ? q9_get($t, $s, $b[0]) : q9_put($t, $s, $c[0], $c[1]);
                                $tag = "$p/$lang: $label";
                                q9_deny($t, $r, 403, 'GE0114', $tag);
                                $t->eq($r[1]['parameter'] ?? null, 'Folder Permissions', "$tag: parameter");
                                $t->true(!array_key_exists('result', $r[1]), "$tag: tanpa result");
                            }
                        });
                    }
                }
                $t->true(q9_same($before, q9_snap($t, $ids)), 'D dan E: data (archives, riwayat, izin) tidak berubah');
                $t->eq(q9_perm_total($t), $permBefore, 'D dan E: jumlah baris tetap');

                // A, M, superadmin 1 dan 2: GET 200 dan PUT [P: View] 200
                $who = ['A' => $roles['A'], 'M' => $roles['M'], 'superadmin 1' => 1, 'superadmin 2' => 2];
                foreach ($who as $tag => $role) {
                    q9_with_user($t, ['role' => $role, 'emp' => ['SMR']], function ($set) use ($t, $s, $b, $f, $tag, $meName) {
                        $r = q9_get($t, $s, $b[0]);
                        $t->status($r, 200, "$tag: GET");
                        $t->eq(q9_code($r), 'ARCHIVE200', "$tag: GET code");
                        $fl = q9_flatten($r[1]['result']['data']);
                        $t->true(isset($fl[$f['P']]) && isset($fl[$f['Q']]) && isset($fl[$f['N']]), "$tag: P, Q, N tampil");
                        foreach ($fl as $n) {
                            if ($n['access']['manage_permission'] !== true) {
                                $t->fail("$tag: manage_permission bukan true di " . $n['name']);
                            }
                        }

                        $hist0 = q9_hist_count($t, $f['P']);
                        $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['P'], 1)]]);
                        $t->status($r, 200, "$tag: PUT [P: View]");
                        $t->eq(q9_code($r), 'ARCHIVE207', "$tag: PUT code");
                        $t->eq(json_encode(q9_user_map($t, $b[0], [$f['P']])[$f['P']] ?? null), '[1,0,0,0]', "$tag: (P,B) = View");
                        $t->eq(q9_hist_count($t, $f['P']), $hist0 + 1, "$tag: riwayat P +1");
                        $h = q9_history($t, $f['P']);
                        $t->eq(end($h)['related_username'] ?? null, $meName, "$tag: riwayat oleh pemanggil");
                        $t->eq(end($h)['action'] ?? null, 'permission', "$tag: action permission");
                        // pulihkan (P,B) = V+S (nilai fixture)
                        q9_perm_restore_row($t, $f['P'], $b[0], [1, 0, 0, 1]);
                    });
                }
                q9_assert_no408($t, 'AC-21');
            } finally {
                q9_purge($t);
                q9_role_purge($t);
            }
            q9_assert_clean($t, $base);
            $t->eq($t->db()->table('roles')->where('role_name', 'like', Q9_ROLE_PREFIX . '%')->count(), 0, 'role fixture dibuang');
        },
    ],

    [
        'id'    => 'AC-21b',
        'title' => '(rev BR-13) Urutan penolakan PUT: 403 GE0114 -> 404 ARCHIVE440 -> 403 ARCHIVE407 -> 422 -> 404 ARCHIVE400 -> 400 ARCHIVE441 -> 400 ARCHIVE411; tidak ada ARCHIVE408; ditolak = data tidak berubah',
        'run'   => function ($t) {
            q9_recover($t);
            q9_role_purge($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b] = q9_others($t, 1);

            try {
                $roles = q9_roles_profile($t);
                $f = q9_fixture($t, $me, $meName, $b[0]);
                $ids = array_values($f);
                $before = q9_snap($t, $ids);
                $permBefore = q9_perm_total($t);
                $bad = ['id_archive' => $f['P'], 'is_view' => 9];
                $unchanged = function ($label) use ($t, $ids, $before, $permBefore) {
                    $t->true(q9_same($before, q9_snap($t, $ids)), "$label: data tidak berubah");
                    $t->eq(q9_perm_total($t), $permBefore, "$label: jumlah baris tetap");
                };
                q9_codes_reset();

                // 1. D (tanpa Folder Permissions): semua kombinasi = GE0114
                q9_with_user($t, ['role' => $roles['D'], 'emp' => ['SMR']], function ($set) use ($t, $s, $f, $bad) {
                    $r = q9_put($t, $s, Q9_RANDOM_ID, ['permissions' => [q9_row_body($f['L'], 1), $bad]]);
                    q9_deny($t, $r, 403, 'GE0114', '1. D + user tidak ada + L + tidak sah');
                });
                $unchanged('1');

                // 2..7: A (pemegang Folder Permissions, lokasi SMR)
                q9_with_user($t, ['role' => $roles['A'], 'emp' => ['SMR']], function ($set) use ($t, $s, $b, $f, $bad, $unchanged) {
                    $lRow = q9_row_body($f['L'], 1);
                    $r = q9_put($t, $s, Q9_RANDOM_ID, ['permissions' => [$lRow, $bad]]);
                    q9_deny($t, $r, 404, 'ARCHIVE440', '2. user tidak ada + L + tidak sah: 440 mendahului 407/422');
                    $unchanged('2');

                    $r = q9_put($t, $s, $b[0], ['permissions' => [$lRow, $bad]]);
                    q9_deny($t, $r, 403, 'ARCHIVE407', '3. L + tidak sah: 407 mendahului 422');
                    $r = q9_put($t, $s, $b[0], ['permissions' => [$lRow, q9_row_body(Q9_RANDOM_ID, 1)]]);
                    q9_deny($t, $r, 403, 'ARCHIVE407', '3b. L + id tidak ada: 407 mendahului 404');
                    $r = q9_put($t, $s, $b[0], ['permissions' => [$lRow, q9_row_body($f['C2'], 1)]]);
                    q9_deny($t, $r, 403, 'ARCHIVE407', '3c. L + C2 Off: 407 mendahului 441');
                    $unchanged('3');

                    $r = q9_put($t, $s, $b[0], ['permissions' => [$bad, q9_row_body(Q9_RANDOM_ID, 1)]]);
                    $t->status($r, 422, '4. tidak sah + id tidak ada: 422 mendahului 404');
                    $r = q9_put($t, $s, $b[0], ['permissions' => [$bad, q9_row_body($f['C2'], 1)]]);
                    $t->status($r, 422, '4b. tidak sah + C2 Off: 422 mendahului 441');
                    $unchanged('4');

                    foreach ([[q9_row_body($f['C2'], 1), q9_row_body(Q9_RANDOM_ID, 1)], [q9_row_body(Q9_RANDOM_ID, 1), q9_row_body($f['C2'], 1)],
                              [q9_row_body($f['P'], 1), q9_row_body(Q9_RANDOM_ID, 1)]] as $i => $rows) {
                        $r = q9_put($t, $s, $b[0], ['permissions' => $rows]);
                        q9_deny($t, $r, 404, 'ARCHIVE400', '5.' . $i . ' id tidak ada + (C2 Off / P): 400 mendahului 441');
                    }
                    $unchanged('5');

                    $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['C2'], 0, 1, 0, 0)]]);
                    q9_deny($t, $r, 400, 'ARCHIVE441', '6. C2 Off dengan U tanpa View: 441 mendahului 411');
                    $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['P'], 0, 1, 0, 0), q9_row_body($f['C2'], 1)]]);
                    q9_deny($t, $r, 400, 'ARCHIVE441', '6b. P (411) + C2 Off: 441 mendahului 411');
                    $unchanged('6');

                    $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['P'], 0, 1, 0, 0)]]);
                    q9_deny($t, $r, 400, 'ARCHIVE411', '7. P dengan U tanpa View: 411');
                    $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['Q'], 0, 0, 1, 0)]]);
                    q9_deny($t, $r, 400, 'ARCHIVE411', '7b. Q (A View saja) dengan D tanpa View: 411, bukan 408');
                    $unchanged('7');
                });
                q9_assert_no408($t, 'AC-21b');
            } finally {
                q9_purge($t);
                q9_role_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-22',
        'title' => '(rev K-15 b, K-12 a) Data J: A GET user-permissions/{B} -> J, JA, JB, JC tampil, manage_permission true di keempatnya, access JC semua true, JB tanpa View; A PUT [JA: View+Store], [J: View], [JB: View] -> 200, baris tersimpan, riwayat masing-masing oleh A (dulu J/JB 403 ARCHIVE408); [JC] -> 400 ARCHIVE441 parameter JC (bukan 408); [JA + JC] -> 400 dan JA tidak tersimpan',
        'run'   => function ($t) {
            q9_recover($t);
            q9_role_purge($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b] = q9_others($t, 1);

            try {
                $roles = q9_roles_profile($t);
                $j = q9_fixture_j($t, $me);
                $ids = array_values($j);
                $jcName = q9_row($t, $j['JC'])['name'];
                q9_codes_reset();

                q9_with_user($t, ['role' => $roles['A'], 'emp' => ['SMR']], function ($set) use ($t, $s, $b, $j, $ids, $jcName, $meName) {
                    $r = q9_get($t, $s, $b[0]);
                    $t->status($r, 200, 'GET');
                    $fl = q9_flatten($r[1]['result']['data']);
                    foreach (['J', 'JA', 'JB', 'JC'] as $k) {
                        $t->true(isset($fl[$j[$k]]), "$k tampil");
                    }
                    q9_access_is($t, $fl[$j['J']]['access'], [1, 0, 0, 0], 'J (A View saja)');
                    q9_access_is($t, $fl[$j['JA']]['access'], [1, 1, 1, 1], 'JA (A V/U/D/S)');
                    q9_access_is($t, $fl[$j['JB']]['access'], [0, 0, 0, 0], 'JB (On, tanpa baris A: tanpa View)');
                    q9_access_is($t, $fl[$j['JC']]['access'], [1, 1, 1, 1], 'JC (Off di bawah J ber-View: semua true, K-12 a)');
                    foreach (['J', 'JA', 'JB', 'JC'] as $k) {
                        $t->true($fl[$j[$k]]['access']['manage_permission'] === true, "$k: manage_permission true (K-15 b)");
                    }
                    $t->eq($fl[$j['J']]['__level'] . $fl[$j['JA']]['__level'] . $fl[$j['JB']]['__level'] . $fl[$j['JC']]['__level'], '0111', 'J level 0, JA/JB/JC level 1');
                    $t->eq(json_encode([$fl[$j['J']]['is_folder_permission'], $fl[$j['JA']]['is_folder_permission'], $fl[$j['JB']]['is_folder_permission'], $fl[$j['JC']]['is_folder_permission']]), '[1,1,1,0]', 'flag Izin: J, JA, JB On; JC Off');

                    // PUT berhasil: JA V+S, J V, JB V
                    $cases = [
                        'JA' => [q9_row_body($j['JA'], 1, 0, 0, 1), '[1,0,0,1]'],
                        'J'  => [q9_row_body($j['J'], 1), '[1,0,0,0]'],
                        'JB' => [q9_row_body($j['JB'], 1), '[1,0,0,0]'],
                    ];
                    foreach ($cases as $k => [$row, $want]) {
                        $hist0 = q9_hist_count($t, $j[$k]);
                        $r = q9_put($t, $s, $b[0], ['permissions' => [$row]]);
                        $t->status($r, 200, "PUT [$k]");
                        $t->eq(q9_code($r), 'ARCHIVE207', "PUT [$k]: code");
                        $t->eq(json_encode(q9_user_map($t, $b[0], [$j[$k]])[$j[$k]] ?? null), $want, "DB: ($k,B) tersimpan");
                        $t->eq(q9_hist_count($t, $j[$k]), $hist0 + 1, "riwayat $k +1");
                        $h = q9_history($t, $j[$k]);
                        $t->eq(end($h)['related_username'] ?? null, $meName, "riwayat $k oleh A");
                    }

                    // JC (Off): 441 parameter JC, bukan 408; data tidak berubah
                    $snap = q9_snap($t, $ids);
                    $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($j['JC'], 1)]]);
                    q9_deny($t, $r, 400, 'ARCHIVE441', 'PUT [JC]', $jcName);
                    $t->true(q9_same($snap, q9_snap($t, $ids)), 'PUT [JC]: data tidak berubah');

                    // JA + JC: atomik, JA tidak tersimpan (tetap V+S)
                    foreach (['JA,JC' => [q9_row_body($j['JA'], 1, 1), q9_row_body($j['JC'], 1)], 'JC,JA' => [q9_row_body($j['JC'], 1), q9_row_body($j['JA'], 1, 1)]] as $label => $rows) {
                        $r = q9_put($t, $s, $b[0], ['permissions' => $rows]);
                        q9_deny($t, $r, 400, 'ARCHIVE441', "PUT [$label]", $jcName);
                        $t->true(q9_same($snap, q9_snap($t, $ids)), "PUT [$label]: JA tidak tersimpan, tidak ada yang berubah");
                    }
                    $t->eq(json_encode(q9_user_map($t, $b[0], [$j['JA']])[$j['JA']] ?? null), '[1,0,0,1]', '(JA,B) tetap V+S');

                    // GET sesudah: nilai tersimpan terlihat
                    $r = q9_get($t, $s, $b[0]);
                    $fl = q9_flatten($r[1]['result']['data']);
                    $t->eq(q9_node_vals($fl[$j['JA']]), '[1,0,0,1]', 'GET: JA = V+S');
                    $t->eq(q9_node_vals($fl[$j['JB']]), '[1,0,0,0]', 'GET: JB = V');
                    $t->eq(q9_node_vals($fl[$j['JC']]), '[0,0,0,0]', 'GET: JC tetap 0');
                });
                q9_assert_no408($t, 'AC-22');
            } finally {
                q9_purge($t);
                q9_role_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-23',
        'title' => '(rev K-15 b) Folder tanpa View tetap bisa dikelola: baris A di J dihapus (A tanpa View di J dan turunannya) -> GET user-permissions/{B}: J, JA, JB, JC tetap tampil, access.view false, manage_permission true; PUT [JA: View+Update] 200 + riwayat JA oleh A; GET archives?id_archive=JA oleh A tetap 403 ARCHIVE407 (membuka butuh View)',
        'run'   => function ($t) {
            q9_recover($t);
            q9_role_purge($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b] = q9_others($t, 1);

            try {
                $roles = q9_roles_profile($t);
                $j = q9_fixture_j($t, $me);
                q9_perm_restore_row($t, $j['J'], $me, null);                 // A tanpa View di J (baris JA milik A tetap, tetapi J membatasi)
                $t->eq(json_encode(q9_user_map($t, $me, array_values($j))), json_encode([$j['JA'] => [1, 1, 1, 1]]), 'prasyarat: hanya baris A di JA');
                q9_codes_reset();

                q9_with_user($t, ['role' => $roles['A'], 'emp' => ['SMR']], function ($set) use ($t, $s, $b, $j, $meName) {
                    $open = function ($k) use ($t, $s, $j) {
                        return $t->call($s, 'GET', 'api/v5/document-archive/archives?' . http_build_query(['id_archive' => $j[$k], 'pagination' => 100]));
                    };
                    $r = q9_get($t, $s, $b[0]);
                    $t->status($r, 200, 'GET {B}: 200 walau A tanpa View di J');
                    $fl = q9_flatten($r[1]['result']['data']);
                    foreach (['J', 'JA', 'JB', 'JC'] as $k) {
                        $t->true(isset($fl[$j[$k]]), "$k tetap tampil");
                        $t->true($fl[$j[$k]]['access']['view'] === false, "$k: access.view false");
                        $t->true($fl[$j[$k]]['access']['manage_permission'] === true, "$k: manage_permission true");
                    }
                    q9_access_is($t, $fl[$j['JA']]['access'], [0, 0, 0, 0], 'JA (View A ditolak J)');

                    // membuka tetap butuh View (ED-1025)
                    foreach (['J', 'JA', 'JB', 'JC'] as $k) {
                        $r = $open($k);
                        q9_deny($t, $r, 403, 'ARCHIVE407', "GET archives?id_archive=$k tanpa View");
                    }

                    // PUT [JA: View+Update] 200 + riwayat JA oleh A
                    $hist0 = q9_hist_count($t, $j['JA']);
                    $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($j['JA'], 1, 1)]]);
                    $t->status($r, 200, 'PUT [JA: V+U] oleh A tanpa View');
                    $t->eq(q9_code($r), 'ARCHIVE207', 'PUT code');
                    $t->eq(json_encode(q9_user_map($t, $b[0], [$j['JA']])[$j['JA']] ?? null), '[1,1,0,0]', 'DB: (JA,B) = V+U');
                    $t->eq(q9_hist_count($t, $j['JA']), $hist0 + 1, 'riwayat JA +1');
                    $h = q9_history($t, $j['JA']);
                    $t->eq(end($h)['related_username'] ?? null, $meName, 'riwayat JA oleh A');
                    $t->eq(end($h)['action'] ?? null, 'permission', 'action = permission');

                    // dan folder Off turunan folder tanpa View: tetap 441 (bukan 408)
                    $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($j['JC'], 1)]]);
                    q9_deny($t, $r, 400, 'ARCHIVE441', 'PUT [JC] tanpa View: 441');

                    $r = $open('JA');
                    q9_deny($t, $r, 403, 'ARCHIVE407', 'GET archives?id_archive=JA tetap 403 sesudah PUT');
                });
                q9_assert_no408($t, 'AC-23');
            } finally {
                q9_purge($t);
                q9_role_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-25',
        'title' => '(rev, sisi BE) Folder F Off tanpa baris A: A menyalakan Izin di tab Izin F (PUT archives/{F} dengan baris otomatis A 1/1/1/1, ED-1025 BR-19/K-14 a) -> GET user-permissions/{A}: F On, 1/1/1/1, access manage true; A melepas Delete F lewat PUT user-permissions/{A} 200 -> tab Izin F (GET archives/{F}) menampilkan A View/Update/Store; riwayat permission +1 tiap langkah',
        'run'   => function ($t) {
            q9_recover($t);
            q9_role_purge($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            q9_uname($t);

            try {
                $roles = q9_roles_profile($t);
                $F = q9_folder($t, 'F', ['perm' => 0]);                       // Off, dibuat user lain, tanpa baris A
                q9_codes_reset();

                q9_with_user($t, ['role' => $roles['A'], 'emp' => ['SMR']], function ($set) use ($t, $s, $me, $F) {
                    $r = q9_get($t, $s, $me);
                    $t->status($r, 200, 'GET {A} awal');
                    $n = q9_flatten($r[1]['result']['data'])[$F];
                    $t->eq($n['is_folder_permission'], 0, 'awal: F Off');
                    $t->eq(q9_node_vals($n), '[0,0,0,0]', 'awal: A tanpa baris di F');

                    // tab Izin: nyalakan Izin + baris otomatis A (FE menambahkannya, K-14 a); BE menyimpan sebagai baris biasa
                    $hist0 = q9_hist_count($t, $F);
                    $r = $t->call($s, 'PUT', 'api/v5/document-archive/archives/' . $F, ['is_folder_permission' => 1, 'folder_permissions' => [
                        ['id_user' => $me, 'is_view' => 1, 'is_update' => 1, 'is_delete' => 1, 'is_store' => 1],
                    ]]);
                    $t->status($r, 200, 'tab Izin: nyalakan + baris otomatis A');
                    $t->eq(json_encode(q9_user_map($t, $me, [$F])[$F] ?? null), '[1,1,1,1]', 'DB: (F,A) = 1/1/1/1');
                    $t->eq(q9_hist_count($t, $F), $hist0 + 1, 'riwayat F +1 (tab Izin)');

                    // halaman by User, pilih A: F On, 1/1/1/1, bisa diubah
                    $r = q9_get($t, $s, $me);
                    $t->status($r, 200, 'GET {A} sesudah');
                    $n = q9_flatten($r[1]['result']['data'])[$F];
                    $t->eq($n['is_folder_permission'], 1, 'F On');
                    $t->eq(q9_node_vals($n), '[1,1,1,1]', 'GET {A}: F = 1/1/1/1');
                    q9_access_is($t, $n['access'], [1, 1, 1, 1], 'access F (A)');

                    // lepas Delete di halaman ini
                    $r = q9_put($t, $s, $me, ['permissions' => [q9_row_body($F, 1, 1, 0, 1)]]);
                    $t->status($r, 200, 'PUT {A} [F: V,U,S]');
                    $t->eq(q9_hist_count($t, $F), $hist0 + 2, 'riwayat F +1 lagi (halaman by User)');
                    $show = $t->call($s, 'GET', 'api/v5/document-archive/archives/' . $F);
                    $t->status($show, 200, 'GET archives/{F} (tab Izin)');
                    $row = null;
                    foreach ($show[1]['result']['folder_permissions'] ?? [] as $x) {
                        if ($x['id_user'] === $me) {
                            $row = $x;
                        }
                    }
                    $t->true($row !== null, 'tab Izin F: baris A ada');
                    $t->eq(json_encode([$row['is_view'] ?? null, $row['is_update'] ?? null, $row['is_delete'] ?? null, $row['is_store'] ?? null]), '[1,1,0,1]', 'tab Izin F: A View/Update/Store (tanpa Delete)');

                    // folder Off tidak pernah mendapat baris dari halaman ini: PUT F sesudah dimatikan = 441
                    q9_set_archive($t, $F, ['is_folder_permission' => 0]);
                    $r = q9_put($t, $s, $me, ['permissions' => [q9_row_body($F, 1)]]);
                    q9_deny($t, $r, 400, 'ARCHIVE441', 'F dimatikan lagi: PUT {A} [F] = 441');
                });
                q9_assert_no408($t, 'AC-25');
            } finally {
                q9_purge($t);
                q9_role_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-26-BE',
        'title' => '(pendukung AC-26 [FE]) A memilih dirinya sendiri dan mencabut View-nya di JA: PUT {A} 200 langsung (BE tidak menolak / tidak ada kode khusus); sesudah dimuat ulang JA tetap tampil dengan view false dan manage_permission true; A bisa memberi dirinya View lagi (akibat BR-7)',
        'run'   => function ($t) {
            q9_recover($t);
            q9_role_purge($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);

            try {
                $roles = q9_roles_profile($t);
                $j = q9_fixture_j($t, $me);
                q9_codes_reset();

                q9_with_user($t, ['role' => $roles['A'], 'emp' => ['SMR']], function ($set) use ($t, $s, $me, $j, $meName) {
                    $r = q9_get($t, $s, $me);
                    $t->status($r, 200, 'GET {A}');
                    $n = q9_flatten($r[1]['result']['data'])[$j['JA']];
                    $t->eq(q9_node_vals($n), '[1,1,1,1]', 'awal: A di JA = 1/1/1/1');

                    $r = q9_put($t, $s, $me, ['permissions' => [q9_row_body($j['JA'], 0, 0, 0, 0)]]);
                    $t->status($r, 200, 'A mencabut semua hak di JA (termasuk View sendiri)');
                    $t->eq(q9_code($r), 'ARCHIVE207', 'code ARCHIVE207');
                    $t->eq(json_encode(q9_user_map($t, $me, [$j['JA']])), '[]', 'DB: baris (JA,A) terhapus');
                    $h = q9_history($t, $j['JA']);
                    $t->eq(end($h)['related_username'] ?? null, $meName, 'riwayat JA oleh A');

                    $r = q9_get($t, $s, $me);
                    $t->status($r, 200, 'GET {A} sesudah mencabut: masih 200');
                    $n = q9_flatten($r[1]['result']['data'])[$j['JA']];
                    $t->true($n['access']['view'] === false, 'JA: access.view false');
                    $t->true($n['access']['manage_permission'] === true, 'JA: manage_permission true (sel tetap bisa diubah)');
                    $t->eq(q9_node_vals($n), '[0,0,0,0]', 'JA: nilai 0');

                    $r = q9_put($t, $s, $me, ['permissions' => [q9_row_body($j['JA'], 1)]]);
                    $t->status($r, 200, 'A memberi dirinya View lagi');
                    $t->eq(json_encode(q9_user_map($t, $me, [$j['JA']])[$j['JA']] ?? null), '[1,0,0,0]', 'DB: (JA,A) = View');
                });
                q9_assert_no408($t, 'AC-26-BE');
            } finally {
                q9_purge($t);
                q9_role_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-27',
        'title' => '(rev K-8 c) Node GET user-permissions/{B} hanya berisi kunci kontrak §1 (tanpa created_by atau kolom audit lain, di semua kedalaman); bentuk response sama untuk A, M, dan superadmin 1/2; badan = status/msg_code/message/result{user,data}',
        'run'   => function ($t) {
            q9_recover($t);
            q9_role_purge($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b] = q9_others($t, 1);

            try {
                $roles = q9_roles_profile($t);
                $f = q9_fixture($t, $me, $meName, $b[0]);
                $j = q9_fixture_j($t, $me);
                $contractNode = ['id_archive', 'id_archive_parent', 'name', 'is_folder_permission', 'is_view', 'is_update', 'is_delete', 'is_store', 'access'];
                $forbidden = ['created_by', 'created_at', 'updated_by', 'updated_at', 'history', 'creator', 'owner', 'username_creator'];
                $shapes = [];

                foreach (['A' => $roles['A'], 'M' => $roles['M'], 'superadmin 1' => 1, 'superadmin 2' => 2] as $tag => $role) {
                    q9_with_user($t, ['role' => $role, 'emp' => ['SMR']], function ($set) use ($t, $s, $b, $tag, $contractNode, $forbidden, &$shapes) {
                        $r = q9_get($t, $s, $b[0]);
                        $t->status($r, 200, "$tag: GET");
                        $t->eq(json_encode(array_keys($r[1])), json_encode(['status', 'msg_code', 'message', 'result']), "$tag: kunci badan");
                        $t->eq(json_encode(array_keys($r[1]['result'])), json_encode(['user', 'data']), "$tag: result = user + data");
                        $t->eq(json_encode(array_keys($r[1]['result']['user'])), json_encode(['id_user', 'username']), "$tag: user = id_user + username");
                        $count = 0;
                        $walk = function (array $nodes) use (&$walk, $t, $tag, $contractNode, $forbidden, &$count) {
                            foreach ($nodes as $n) {
                                $count++;
                                $keys = array_values(array_diff(array_keys($n), ['children']));
                                if (json_encode($keys) !== json_encode($contractNode)) {
                                    $t->fail("$tag: node " . ($n['name'] ?? '?') . ' kunci menyimpang: ' . implode(',', $keys));
                                }
                                foreach ($forbidden as $k) {
                                    if (array_key_exists($k, $n) || array_key_exists($k, $n['access'])) {
                                        $t->fail("$tag: node " . ($n['name'] ?? '?') . " memuat $k");
                                    }
                                }
                                if (array_key_exists('children', $n)) {
                                    $walk($n['children']);
                                }
                            }
                        };
                        $walk($r[1]['result']['data']);
                        $t->true($count > 10, "$tag: " . $count . ' node diperiksa');
                        $shapes[$tag] = $count;
                    });
                }
                $raw = json_encode($t->call($s, 'GET', 'api/v5/document-archive/user-permissions/' . $b[0])[1] ?? []);
                $t->true(strpos($raw, 'created_by') === false, 'badan mentah (user login bawaan) tanpa string created_by');
            } finally {
                q9_purge($t);
                q9_role_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

];
