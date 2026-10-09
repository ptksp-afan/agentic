<?php
/**
 * ED-1029 - AC-5b (superadmin 1/2 boleh mengelola semua folder On, BR-15), AC-13 / AC-13b (penegakan 02 langsung berlaku/dicabut,
 * BR-16; rev K-15 b: mengelola = Folder Permissions saja, tanpa ARCHIVE408), X-PERM (permission per route = Folder Permissions;
 * 2xx untuk pemegangnya, 403 GE0114 tanpa; QA_USER2; tanpa token 401), AC-20 (bagian BE: tanpa Folder Permissions 403 GE0114, data
 * tetap, tanpa seed baru, tanpa id permission literal), X-ISO (isolasi tenant).
 *
 * "B" tanpa kredensial = QA_USER dalam role non-bypass (role 3); pemberi hak = QA_USER dalam role 1 (superadmin) yang melakukan PUT atas
 * id_user QA_USER sendiri. Peran ditukar lewat user_roles dan dipulihkan. Role profil spec (A, M, D, E) = role fixture QA29-ROLE-*
 * berisi permission BERNAMA (qa_lib: q9_roles_profile), dibuang di finally.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-5b',
        'title' => 'PUT oleh superadmin 1 dan 2 (BR-15): boleh mengelola semua folder On (juga Q tanpa hak, LC di lokasi lain, N), folder Off tetap 441; riwayat dicatat oleh superadmin',
        'run'   => function ($t) {
            q9_recover($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b] = q9_others($t, 1);

            try {
                $f = q9_fixture($t, $me, $meName, $b[0], false);
                $ids = array_values($f);

                foreach ([1, 2] as $role) {
                    $tag = "role $role";
                    q9_with_user($t, ['role' => $role, 'emp' => ['SMR']], function ($set) use ($t, $s, $b, $f, $ids, $tag, $meName) {
                        $hist = [];
                        foreach (['Q', 'N', 'LC', 'M', 'P'] as $k) {
                            $hist[$k] = q9_hist_count($t, $f[$k]);
                        }
                        $rows = [];
                        foreach (['Q', 'N', 'LC', 'M', 'P'] as $k) {
                            $rows[] = q9_row_body($f[$k], 1, 1, 0, 1);
                        }
                        $r = q9_put($t, $s, $b[0], ['permissions' => $rows]);
                        $t->status($r, 200, "$tag: PUT folder On tanpa hak khusus");
                        $t->eq(q9_code($r), 'ARCHIVE207', "$tag: code");
                        $map = q9_user_map($t, $b[0], $ids);
                        foreach (['Q', 'N', 'LC', 'M', 'P'] as $k) {
                            $t->eq(json_encode($map[$f[$k]] ?? null), '[1,1,0,1]', "$tag: (B,$k) tersimpan");
                            $t->eq(q9_hist_count($t, $f[$k]), $hist[$k] + 1, "$tag: riwayat $k +1");
                            $h = q9_history($t, $f[$k]);
                            $t->eq(end($h)['related_username'] ?? null, $meName, "$tag: riwayat $k oleh pemanggil");
                        }

                        // folder Off tetap ditolak 441 (juga untuk superadmin); L (Off) di luar lokasi: superadmin lolos 441 saja
                        $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['R'], 1)]]);
                        $t->status($r, 400, "$tag: R Off");
                        $t->eq(q9_code($r), 'ARCHIVE441', "$tag: R Off: 441");
                        $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['L'], 1)]]);
                        $t->status($r, 400, "$tag: L Off di lokasi lain");
                        $t->eq(q9_code($r), 'ARCHIVE441', "$tag: L Off: 441 (bukan 407)");

                        // bersihkan: hapus semua baris B lewat PUT nol (juga memeriksa hapus oleh superadmin)
                        $zero = [];
                        foreach (['Q', 'N', 'LC', 'M', 'P'] as $k) {
                            $zero[] = q9_row_body($f[$k], 0, 0, 0, 0);
                        }
                        $r = q9_put($t, $s, $b[0], ['permissions' => $zero]);
                        $t->status($r, 200, "$tag: hapus semua");
                        $t->eq(json_encode(q9_user_map($t, $b[0], $ids)), '[]', "$tag: semua baris B terhapus");
                    });
                }
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-13',
        'title' => '(rev K-15 b) Penegakan 02: "B" (role 3, non-bypass, memegang Folder Permissions) GET archives?id_archive=P = 403 ARCHIVE407; sesudah A (superadmin) memberi View di P lewat PUT user-permissions = 200; B (View saja) tetap boleh mengelola user lain di P (200, bukan 408) sedangkan PUT archives/{P} kelompok detail tetap 403 ARCHIVE408 (ED-1025); sesudah dicabut = 403 ARCHIVE407 lagi tetapi user-permissions P tetap bisa dikelola B (200); sebaliknya tab Permission (02) mengubah GET per user',
        'run'   => function ($t) {
            q9_recover($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$other] = q9_others($t, 1);

            try {
                $P = q9_folder($t, 'P', ['perm' => 1]);
                $pName = q9_row($t, $P)['name'];
                $open = function () use ($t, $s, $P) {
                    return $t->call($s, 'GET', 'api/v5/document-archive/archives?' . http_build_query(['id_archive' => $P, 'pagination' => 100]));
                };
                $putPerm = function (array $rows, $role) use ($t, $s, $me) {
                    $r = null;
                    q9_with_user($t, ['role' => $role], function ($set) use ($t, $s, $me, $rows, &$r) {
                        $r = q9_put($t, $s, $me, ['permissions' => $rows]);
                    });

                    return $r;
                };
                q9_codes_reset();

                // 1. awal: B tanpa baris di P (On) -> 403 ARCHIVE407
                $r = $open();
                q9_deny($t, $r, 403, 'ARCHIVE407', 'awal: B buka P');

                // 2. A (role 1) memberi View
                $r = $putPerm([q9_row_body($P, 1)], 1);
                $t->status($r, 200, 'A memberi View');
                $t->eq(json_encode(q9_user_map($t, $me, [$P])), json_encode([$P => [1, 0, 0, 0]]), 'DB: (P,B) = View');
                $r = $open();
                $t->status($r, 200, 'B buka P setelah diberi View');
                // B (View saja): PUT archives/{P} kelompok detail tetap ditolak 408 (Update efektif, aturan ED-1025)
                $r = $t->call($s, 'PUT', 'api/v5/document-archive/archives/' . $P, ['name' => $pName, 'is_all_location' => 1]);
                q9_deny($t, $r, 403, 'ARCHIVE408', 'B (View saja) PUT archives/{P} detail', $pName);
                // rev K-15 b: B memegang Folder Permissions, jadi boleh mengelola P di halaman per user walau hanya View
                $r = q9_put($t, $s, $other[0], ['permissions' => [q9_row_body($P, 1, 0, 0, 1)]]);
                $t->status($r, 200, 'B (View saja) PUT user-permissions {user lain} P');
                $t->eq(q9_code($r), 'ARCHIVE207', 'B (View saja) PUT: ARCHIVE207, bukan 408');
                $t->eq(json_encode(q9_user_map($t, $other[0], [$P])), json_encode([$P => [1, 0, 0, 1]]), 'DB: (P,user lain) = V+S oleh B');
                $t->eq(json_encode(q9_user_map($t, $me, [$P])), json_encode([$P => [1, 0, 0, 0]]), 'DB: (P,B) tidak tersentuh');
                $g = q9_get($t, $s, $me);
                $n = q9_flatten($g[1]['result']['data'])[$P];
                q9_access_is($t, $n['access'], [1, 0, 0, 0], 'GET per user oleh B: access P (View saja, manage true)');

                // 3. A menambah Update: B bisa PUT archives/{P} (tab Permission); B juga mengelola di halaman per user
                $r = $putPerm([q9_row_body($P, 1, 1)], 1);
                $t->status($r, 200, 'A memberi View+Update');
                $r = $t->call($s, 'PUT', 'api/v5/document-archive/archives/' . $P, ['name' => $pName, 'is_all_location' => 1, 'is_folder_permission' => 1, 'folder_permissions' => [
                    ['id_user' => $me, 'is_view' => 1, 'is_update' => 1, 'is_delete' => 0, 'is_store' => 0],
                    ['id_user' => $other[0], 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 1],
                ]]);
                $t->status($r, 200, 'B (V+U) PUT archives/{P} (tab Permission)');
                $r = q9_put($t, $s, $me, ['permissions' => [q9_row_body($P, 1, 1, 0, 1)]]);
                $t->status($r, 200, 'B (V+U) PUT user-permissions P (halaman per user)');
                $t->eq(json_encode(q9_user_map($t, $me, [$P])), json_encode([$P => [1, 1, 0, 1]]), 'DB: (P,B) = V+U+S setelah B mengubah sendiri');
                $g = q9_get($t, $s, $me);
                $n = q9_flatten($g[1]['result']['data'])[$P];
                q9_access_is($t, $n['access'], [1, 1, 0, 1], 'GET per user oleh B: access P setelah ubah');

                // 4. A mencabut: membuka P ditolak 407, tetapi mengelola P di halaman per user tetap bisa (tanpa View, K-15 b)
                $r = $putPerm([q9_row_body($P, 0, 0, 0, 0)], 1);
                $t->status($r, 200, 'A mencabut');
                $t->eq(json_encode(q9_user_map($t, $me, [$P])), '[]', 'DB: baris (P,B) hilang');
                $r = $open();
                q9_deny($t, $r, 403, 'ARCHIVE407', 'B buka P setelah dicabut');
                $g = q9_get($t, $s, $me);
                $t->status($g, 200, 'B tanpa View: GET per user tetap 200');
                $n = q9_flatten($g[1]['result']['data'])[$P];
                q9_access_is($t, $n['access'], [0, 0, 0, 0], 'GET per user oleh B: access P setelah dicabut (view false, manage true)');
                $t->eq(q9_node_vals($n), '[0,0,0,0]', 'GET per user: nilai P = 0');
                $r = q9_put($t, $s, $other[0], ['permissions' => [q9_row_body($P, 0, 0, 0, 0)]]);
                $t->status($r, 200, 'B (tanpa View) PUT user-permissions {user lain} P: 200, bukan 408');
                $t->eq(json_encode(q9_user_map($t, $other[0], [$P])), '[]', 'DB: baris (P,user lain) dihapus oleh B');
                $r = $open();
                q9_deny($t, $r, 403, 'ARCHIVE407', 'B tetap tidak bisa membuka P (membuka butuh View)');

                // 5. riwayat: tiap perubahan nyata satu entri permission:
                //   A beri V (1), B PUT {lain} V+S (2), A beri V+U (3), PUT archives/{P} tab dengan set yang sama = tanpa entri,
                //   B PUT sendiri V+U+S (4), A cabut (5), B hapus {lain} (6)
                $t->eq(q9_hist_count($t, $P), 6, 'riwayat P: 6 entri permission (hanya perubahan nyata)');
                q9_assert_no408($t, 'AC-13 user-permissions');
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-13b',
        'title' => '(rev K-15 b) Penegakan 02 dengan dua pengguna nyata: A = QA_USER (pembuat P, role 3), B = QA_USER2 (adi, role 5: Update Folder tanpa Folder Permissions): B buka P 403 ARCHIVE407; A PUT {B} View -> B buka P 200; B (View, V+U sekalipun) GET/PUT user-permissions = 403 GE0114 "Folder Permissions" (hak folder tidak menggantikan permission); B diberi role ber-Folder Permissions sementara -> GET/PUT {user lain} 200 walau hanya View, bahkan setelah View dicabut (tanpa 408); role sementara dilepas -> 403 GE0114 lagi',
        'run'   => function ($t) {
            q9_recover($t);
            q9_role_purge($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            $adi = $t->db()->table('users')->where('username', $t->conf('QA_USER2'))->value('id_user');
            if (!$adi) {
                $t->skip('QA_USER2 tidak ada di DB uji');
            }
            [$other] = q9_others($t, 1);
            if ($other[0] === $adi) {
                [, $other] = q9_others($t, 2);
            }
            $s2 = $t->login('QA_USER2');
            $bind = $t->bind($s2, q9_dbname($t));
            if ($bind[0] !== 200) {
                $t->blocked('QA_USER2 tidak bisa bind ke ' . q9_dbname($t) . ' (HTTP ' . $bind[0] . ')');
            }

            try {
                $P = q9_folder($t, 'P', ['perm' => 1, 'by' => $meName]);     // A pembuat: semua hak di P
                $pName = q9_row($t, $P)['name'];
                $open = function () use ($t, $s2, $P) {
                    return $t->call($s2, 'GET', 'api/v5/document-archive/archives?' . http_build_query(['id_archive' => $P, 'pagination' => 100]));
                };
                q9_codes_reset();

                $r = $open();
                q9_deny($t, $r, 403, 'ARCHIVE407', 'awal: B buka P');

                $r = q9_get($t, $s, $adi);
                $t->status($r, 200, 'A GET {B}');
                q9_access_is($t, q9_flatten($r[1]['result']['data'])[$P]['access'], [1, 1, 1, 1], 'A (pembuat) access P');

                $r = q9_put($t, $s, $adi, ['permissions' => [q9_row_body($P, 1)]]);
                $t->status($r, 200, 'A PUT {B} View');
                $t->eq(json_encode(q9_user_map($t, $adi, [$P])), json_encode([$P => [1, 0, 0, 0]]), 'DB: (P,B) = View');
                $h = q9_history($t, $P);
                $t->eq(end($h)['related_username'] ?? null, $meName, 'riwayat: oleh A');

                $r = $open();
                $t->status($r, 200, 'B buka P (View)');

                // B: role 5 punya Update Folder tetapi tidak Folder Permissions -> 403 GE0114, juga setelah A memberi V+U+D+S di P
                $deny = function ($label) use ($t, $s2, $other, $P) {
                    $r = q9_get($t, $s2, $other[0]);
                    q9_deny($t, $r, 403, 'GE0114', "$label: GET");
                    $t->eq($r[1]['parameter'] ?? null, 'Folder Permissions', "$label: GET parameter");
                    $r = q9_put($t, $s2, $other[0], ['permissions' => [q9_row_body($P, 1)]]);
                    q9_deny($t, $r, 403, 'GE0114', "$label: PUT");
                    $t->eq($r[1]['parameter'] ?? null, 'Folder Permissions', "$label: PUT parameter");
                    $t->eq(json_encode(q9_user_map($t, $other[0], [$P])), '[]', "$label: DB: tidak ada baris untuk user lain");
                };
                $deny('B (View, tanpa Folder Permissions)');
                $r = q9_put($t, $s, $adi, ['permissions' => [q9_row_body($P, 1, 1, 1, 1)]]);
                $t->status($r, 200, 'A PUT {B} V+U+D+S');
                $deny('B (V/U/D/S di P, tanpa Folder Permissions)');
                $r = q9_put($t, $s, $adi, ['permissions' => [q9_row_body($P, 1)]]);
                $t->status($r, 200, 'A kembali ke View saja');

                // B diberi role ber-Folder Permissions (profil M) sementara: mengelola P walau hanya View
                $roles = q9_roles_profile($t);
                q9_with_extra_role($t, $adi, $roles['M'], function () use ($t, $s2, $other, $P, $pName, $adi) {
                    $r = q9_get($t, $s2, $other[0]);
                    $t->status($r, 200, 'B + Folder Permissions: GET {user lain}');
                    q9_access_is($t, q9_flatten($r[1]['result']['data'])[$P]['access'], [1, 0, 0, 0], 'B + FP access P (View saja, manage true)');
                    $r = q9_put($t, $s2, $other[0], ['permissions' => [q9_row_body($P, 1, 0, 0, 1)]]);
                    $t->status($r, 200, 'B + FP (View saja) PUT {user lain} P');
                    $t->eq(q9_code($r), 'ARCHIVE207', 'B + FP (View saja): ARCHIVE207, bukan 408');
                    $t->eq(json_encode(q9_user_map($t, $other[0], [$P])), json_encode([$P => [1, 0, 0, 1]]), 'DB: (P,user lain) = V+S oleh B');
                    $h = q9_history($t, $P);
                    $t->eq(end($h)['related_username'] ?? null, $t->conf('QA_USER2'), 'riwayat: entri terakhir oleh B (QA_USER2)');
                });
                // setelah role sementara dilepas: ditolak lagi, baris yang ditulis B tetap (data, bukan sesi)
                $deny2 = q9_get($t, $s2, $other[0]);
                q9_deny($t, $deny2, 403, 'GE0114', 'role sementara dilepas: GET');

                // A mencabut View B; B + FP tanpa View tetap boleh mengelola, tetapi tidak membuka P
                $r = q9_put($t, $s, $adi, ['permissions' => [q9_row_body($P, 0, 0, 0, 0)]]);
                $t->status($r, 200, 'A mencabut');
                $r = $open();
                q9_deny($t, $r, 403, 'ARCHIVE407', 'B buka P setelah dicabut');
                q9_with_extra_role($t, $adi, $roles['M'], function () use ($t, $s2, $other, $P) {
                    $r = q9_put($t, $s2, $other[0], ['permissions' => [q9_row_body($P, 0, 0, 0, 0)]]);
                    $t->status($r, 200, 'B + FP (tanpa View) PUT {user lain} P: 200, bukan 408');
                    $t->eq(json_encode(q9_user_map($t, $other[0], [$P])), '[]', 'DB: baris (P,user lain) dihapus oleh B');
                });
                q9_assert_no408($t, 'AC-13b user-permissions');
            } finally {
                q9_purge($t);
                q9_role_purge($t);
            }
            q9_assert_clean($t, $base);
            $t->eq($t->db()->table('roles')->where('role_name', 'like', Q9_ROLE_PREFIX . '%')->count(), 0, 'role fixture dibuang');
        },
    ],

    [
        'id'    => 'X-PERM',
        'title' => '(rev FB-4b) Permission per route (GET dan PUT user-permissions/{id}) = Folder Permissions: role yang memegangnya (dicari lewat nama permission di role_permissions: 1, 2, 3) 200; role tanpa (termasuk 5 dan 26 yang ber-Update Folder; juga 6, 16, 18, 19, 20, 25, 29) 403 GE0114 parameter "Folder Permissions", data tidak berubah; tanpa token 401 GE0111; QA_USER2 (role 5) 403',
        'run'   => function ($t) {
            q9_recover($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b] = q9_others($t, 1);

            try {
                $f = q9_fixture($t, $me, $meName, $b[0]);
                $ids = array_values($f);
                $before = q9_snap($t, $ids);
                $permBefore = q9_perm_total($t);

                $allRoles = $t->db()->table('roles')->where('role_name', 'not like', Q9_ROLE_PREFIX . '%')->orderBy('id_role')->pluck('id_role')->all();
                $withFp = q9_roles_by_permission($t, 'Folder Permissions', $allRoles, true);
                $withoutFp = q9_roles_by_permission($t, 'Folder Permissions', [5, 26, 6, 16, 18, 19, 20, 25, 29], false);
                $withUf = q9_roles_by_permission($t, 'Update Folder', $withoutFp, true);
                $t->true(count($withFp) >= 3, 'prasyarat: minimal 3 role memegang Folder Permissions (seed ulang ED-1025): ' . implode(',', $withFp));
                $t->true(count($withUf) >= 1, 'prasyarat: ada role ber-Update Folder tanpa Folder Permissions (5/26): ' . implode(',', $withUf));
                $t->note('role dengan FP: ' . implode(',', $withFp) . '; tanpa FP: ' . implode(',', $withoutFp) . ' (di antaranya ber-Update Folder: ' . implode(',', $withUf) . ')');

                // tanpa token
                $r = $t->raw('GET', 'api/v5/document-archive/user-permissions/' . $b[0]);
                $t->status($r, 401, 'GET tanpa token');
                $t->eq(q9_code($r), 'GE0111', 'GET tanpa token: code');
                $r = $t->raw('PUT', 'api/v5/document-archive/user-permissions/' . $b[0], ['permissions' => [q9_row_body($f['P'], 1)]]);
                $t->status($r, 401, 'PUT tanpa token');
                $t->eq(q9_code($r), 'GE0111', 'PUT tanpa token: code');

                // role tanpa Folder Permissions: 403 GE0114 (GET, PUT sah, PUT invalid, PUT [], PUT user tidak ada)
                foreach ($withoutFp as $role) {
                    q9_with_user($t, ['role' => $role], function ($set) use ($t, $s, $b, $f, $role) {
                        foreach (['GET' => null,
                                  'PUT sah' => ['permissions' => [q9_row_body($f['P'], 1, 1, 1, 1)]],
                                  'PUT invalid' => ['permissions' => [q9_row_body($f['P'], 9)]],
                                  'PUT []' => ['permissions' => []]] as $label => $body) {
                            $r = $label === 'GET' ? q9_get($t, $s, $b[0]) : q9_put($t, $s, $b[0], $body);
                            q9_deny($t, $r, 403, 'GE0114', "role $role: $label");
                            $t->eq($r[1]['parameter'] ?? null, 'Folder Permissions', "role $role: $label: parameter");
                            $t->true(!array_key_exists('result', $r[1]), "role $role: $label: tanpa data");
                        }
                        $r = q9_put($t, $s, Q9_RANDOM_ID, ['permissions' => []]);
                        q9_deny($t, $r, 403, 'GE0114', "role $role: user tidak ada -> 403 mendahului 404");
                    });
                }
                $t->true(q9_same($before, q9_snap($t, $ids)), 'data tidak berubah oleh penolakan');
                $t->eq(q9_perm_total($t), $permBefore, 'jumlah baris tetap');

                // role dengan Folder Permissions: GET 200 (PUT [] 200)
                foreach ($withFp as $role) {
                    q9_with_user($t, ['role' => $role], function ($set) use ($t, $s, $b, $role) {
                        $r = q9_get($t, $s, $b[0]);
                        $t->status($r, 200, "role $role: GET");
                        $t->eq(q9_code($r), 'ARCHIVE200', "role $role: GET code");
                        $r = q9_put($t, $s, $b[0], ['permissions' => []]);
                        $t->status($r, 200, "role $role: PUT []");
                        $t->eq(q9_code($r), 'ARCHIVE207', "role $role: PUT [] code");
                    });
                }
                $t->true(q9_same($before, q9_snap($t, $ids)), 'data tidak berubah oleh GET / PUT []');

                // QA_USER2 (adi, role 5: Update Folder tanpa Folder Permissions): login + bind ke QA_DB -> 403 GE0114
                $s2 = $t->login('QA_USER2');
                $bind = $t->bind($s2, q9_dbname($t));
                if ($bind[0] === 200) {
                    $r = q9_get($t, $s2, $b[0]);
                    $t->note('QA_USER2 berhasil bind; GET = ' . $r[0] . ' ' . q9_code($r));
                    $adiRoles = $t->db()->table('user_roles')->where('id_user', $t->db()->table('users')->where('username', $t->conf('QA_USER2'))->value('id_user'))->pluck('id_role')->all();
                    $adiHasFp = count(array_intersect($adiRoles, $withFp)) > 0;
                    $t->true(in_array($r[0], [200, 403]), 'QA_USER2: GET 200 atau 403 (bukan 5xx)');
                    $t->eq($r[0], $adiHasFp ? 200 : 403, 'QA_USER2 (role ' . implode(',', $adiRoles) . '): GET sesuai kepemilikan Folder Permissions');
                    $r = q9_put($t, $s2, $b[0], ['permissions' => []]);
                    $t->eq($r[0], $adiHasFp ? 200 : 403, 'QA_USER2: PUT [] sesuai kepemilikan Folder Permissions');
                } else {
                    $t->note('QA_USER2 tidak bisa bind ke ' . q9_dbname($t) . ' (HTTP ' . $bind[0] . ' ' . (q9_code($bind) ?? '') . '): 403 diuji lewat pertukaran role');
                }
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-20',
        'title' => '(rev, bagian BE, cara seragam EPIC K-2 b) role tanpa Folder Permissions (6, 29, juga 5 yang ber-Update Folder): GET/PUT 403 GE0114 (bukan 500), data tetap; item ini tidak menambah seed (Folder Permissions satu-satunya di permission_salesman.sql, tidak ada permission "Set Permission by User"); kode BE/QA ED-1029 tanpa id permission literal (grep)',
        'run'   => function ($t) {
            q9_recover($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b] = q9_others($t, 1);

            try {
                $f = q9_fixture($t, $me, $meName, $b[0]);
                $ids = array_values($f);
                $before = q9_snap($t, $ids);

                $noFp = q9_roles_by_permission($t, 'Folder Permissions', [6, 29, 5], false);
                $t->true(count($noFp) >= 2, 'prasyarat: role uji tanpa Folder Permissions: ' . implode(',', $noFp));
                foreach ($noFp as $role) {
                    q9_with_user($t, ['role' => $role, 'lang' => 'ID'], function ($set) use ($t, $s, $b, $f, $role) {
                        $r = q9_get($t, $s, $b[0]);
                        q9_deny($t, $r, 403, 'GE0114', "role $role: GET");
                        $t->eq($r[1]['parameter'] ?? null, 'Folder Permissions', "role $role: GET parameter");
                        $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['P'], 1, 1, 1, 1)]]);
                        q9_deny($t, $r, 403, 'GE0114', "role $role: PUT");
                        $t->true(!array_key_exists('result', $r[1]), "role $role: tanpa result");
                    });
                }
                $t->true(q9_same($before, q9_snap($t, $ids)), 'data tetap');

                // seed: tidak ada permission baru dari item ini
                $c = $t->db();
                $t->eq($c->table('permissions')->where('permission_name', 'like', '%Set Permission%')->count(), 0, 'tidak ada permission "Set Permission by User" di DB (K-1 a)');
                $t->eq($c->table('permissions')->where('permission_name', 'Folder Permissions')->count(), 1, 'tepat satu permission bernama Folder Permissions (seed ED-1025)');
                $t->eq($c->table('permissions')->where('permission_name', 'Update Folder')->count(), 1, 'tepat satu permission bernama Update Folder');

                $pat = '/(?<![0-9])(10(8[2-5])|11(3[3-9]|4[0-9]))(?![0-9])/';       // id permission modul Archive (tanpa menulis angka utuh)
                $be = rtrim(str_replace('\\', '/', (string) $t->conf('BE_DIR')), '/');
                if ($be === '' || !is_dir($be . '/app/Sql/data')) {
                    $t->note('BE_DIR tidak terbaca: pemeriksaan berkas seed dilewati');
                } else {
                    $hits = [];
                    foreach (glob($be . '/app/Sql/data/*.sql') as $file) {
                        if (strpos(file_get_contents($file), 'Folder Permissions') !== false) {
                            $hits[] = basename($file);
                        }
                    }
                    $t->eq(json_encode($hits), json_encode(['permission_salesman.sql']), '"Folder Permissions" hanya di seed permission_salesman.sql');

                    // item ini tidak mengubah berkas seed/migrasi/Updater: hanya 3 berkas BE (UpdateRequest, UserPermissionService, api.php)
                    $files = [
                        $be . '/Modules/V5/Http/Services/DocumentArchive/UserPermissionService.php',
                        $be . '/Modules/V5/Http/Requests/DocumentArchive/UserPermission/UpdateRequest.php',
                    ];
                    foreach ($files as $file) {
                        $t->true(is_file($file) && !preg_match($pat, file_get_contents($file)), 'tanpa id permission literal: ' . basename($file));
                    }
                    $routes = file($be . '/Modules/V5/Routes/DocumentArchive/api.php');
                    $lit = 0;
                    foreach ($routes as $line) {
                        if (strpos($line, 'user-permission') !== false || strpos($line, 'UserPermissionController') !== false) {
                            $lit += preg_match($pat, $line);
                        }
                    }
                    $t->eq($lit, 0, 'route user-permissions tanpa id permission literal');
                }
                foreach (glob(__DIR__ . '/*.php') as $file) {
                    $src = file_get_contents($file);
                    $t->true(!preg_match($pat, $src), 'QA ED-1029 tanpa id permission literal: ' . basename($file));
                }
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-ISO',
        'title' => 'Isolasi tenant (butuh >= 2 DB di QA_DBS)',
        'run'   => function ($t) {
            $dbs = $t->dbs();
            if (count($dbs) < 2) {
                $t->skip('QA_DBS hanya memuat ' . count($dbs) . ' DB (' . implode(',', $dbs) . '): isolasi tenant tidak bisa diuji; kode tidak memakai static/cache (ditinjau)');
            }
            $t->isolation($t->session($dbs[0]), $t->session($dbs[1]), 10);
        },
    ],

];
