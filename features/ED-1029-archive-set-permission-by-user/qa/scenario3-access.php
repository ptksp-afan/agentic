<?php
/**
 * ED-1029 - AC-5b (superadmin 1/2 boleh mengelola semua folder On, BR-15), AC-13 (penegakan 02 langsung berlaku/dicabut, BR-16),
 * X-PERM (permission per route: 2xx untuk Update Folder, 403 GE0114 tanpa; QA_USER2; tanpa token 401), AC-20 (bagian BE:
 * tanpa permission Archive 403 GE0114, data tetap, tanpa seed baru), X-ISO (isolasi tenant).
 *
 * B tidak punya kredensial: identitas "B" = QA_USER dalam role non-bypass (role 3); pemberi hak = QA_USER dalam role 1
 * (superadmin) yang melakukan PUT atas id_user QA_USER sendiri. Peran ditukar lewat user_roles dan dipulihkan.
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
        'title' => 'Penegakan 02: "B" (role 3, non-bypass) GET archives?id_archive=P = 403 ARCHIVE407; sesudah A (superadmin) memberi View di P lewat PUT user-permissions = 200; sesudah dicabut lewat PUT = 403 ARCHIVE407 lagi; pencabutan hak Update memengaruhi PUT archives/{P}; sebaliknya tab Permission (02) mengubah GET per user',
        'run'   => function ($t) {
            q9_recover($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);

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

                // 1. awal: B tanpa baris di P (On) -> 403 ARCHIVE407
                $r = $open();
                q9_deny($t, $r, 403, 'ARCHIVE407', 'awal: B buka P');

                // 2. A (role 1) memberi View
                $r = $putPerm([q9_row_body($P, 1)], 1);
                $t->status($r, 200, 'A memberi View');
                $t->eq(json_encode(q9_user_map($t, $me, [$P])), json_encode([$P => [1, 0, 0, 0]]), 'DB: (P,B) = View');
                $r = $open();
                $t->status($r, 200, 'B buka P setelah diberi View');
                // B tanpa Update: PUT archives/{P} ditolak 408
                $r = $t->call($s, 'PUT', 'api/v5/document-archive/archives/' . $P, ['name' => $pName, 'is_all_location' => 1]);
                q9_deny($t, $r, 403, 'ARCHIVE408', 'B (View saja) PUT archives/{P}', $pName);
                // B tanpa Update: tidak boleh mengelola lewat halaman per user juga (manage_permission false)
                $r = q9_put($t, $s, $me, ['permissions' => [q9_row_body($P, 1, 1)]]);
                q9_deny($t, $r, 403, 'ARCHIVE408', 'B (View saja) PUT user-permissions P', $pName);
                $t->eq(json_encode(q9_user_map($t, $me, [$P])), json_encode([$P => [1, 0, 0, 0]]), 'DB: (P,B) tetap View');
                $g = q9_get($t, $s, $me);
                $n = q9_flatten($g[1]['result']['data'])[$P];
                q9_access_is($t, $n['access'], [1, 0, 0, 0], 'GET per user oleh B: access P');

                // 3. A menambah Update: B bisa PUT archives/{P}; B juga boleh mengelola di halaman per user
                $r = $putPerm([q9_row_body($P, 1, 1)], 1);
                $t->status($r, 200, 'A memberi View+Update');
                $r = $t->call($s, 'PUT', 'api/v5/document-archive/archives/' . $P, ['name' => $pName, 'is_all_location' => 1, 'is_folder_permission' => 1, 'folder_permissions' => [
                    ['id_user' => $me, 'is_view' => 1, 'is_update' => 1, 'is_delete' => 0, 'is_store' => 0],
                ]]);
                $t->status($r, 200, 'B (V+U) PUT archives/{P} (tab Permission)');
                $r = q9_put($t, $s, $me, ['permissions' => [q9_row_body($P, 1, 1, 0, 1)]]);
                $t->status($r, 200, 'B (V+U) PUT user-permissions P (halaman per user)');
                $t->eq(json_encode(q9_user_map($t, $me, [$P])), json_encode([$P => [1, 1, 0, 1]]), 'DB: (P,B) = V+U+S setelah B mengubah sendiri');
                $g = q9_get($t, $s, $me);
                $n = q9_flatten($g[1]['result']['data'])[$P];
                q9_access_is($t, $n['access'], [1, 1, 0, 1], 'GET per user oleh B: access P setelah ubah');

                // 4. A mencabut: B ulang -> 403 ARCHIVE407
                $r = $putPerm([q9_row_body($P, 0, 0, 0, 0)], 1);
                $t->status($r, 200, 'A mencabut');
                $t->eq(json_encode(q9_user_map($t, $me, [$P])), '[]', 'DB: baris (P,B) hilang');
                $r = $open();
                q9_deny($t, $r, 403, 'ARCHIVE407', 'B buka P setelah dicabut');
                $r = q9_put($t, $s, $me, ['permissions' => [q9_row_body($P, 1)]]);
                q9_deny($t, $r, 403, 'ARCHIVE408', 'B tanpa hak: PUT user-permissions P', $pName);
                $g = q9_get($t, $s, $me);
                $n = q9_flatten($g[1]['result']['data'])[$P];
                q9_access_is($t, $n['access'], [0, 0, 0, 0], 'GET per user oleh B: access P setelah dicabut');
                $t->eq(json_encode([$n['is_view'], $n['is_update'], $n['is_delete'], $n['is_store']]), '[0,0,0,0]', 'GET per user: nilai P = 0');

                // 5. riwayat: tiap perubahan nyata satu entri permission
                // (PUT archives/{P} dengan set yang sama dengan tersimpan = tanpa entri, jadi 4 bukan 5)
                $t->eq(q9_hist_count($t, $P), 4, 'riwayat P: 4 entri permission (pemberian, V+U, ubah oleh B di halaman, pencabutan)');
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-13b',
        'title' => 'Penegakan 02 dengan dua pengguna nyata: A = QA_USER (pembuat P, role 3), B = QA_USER2 (adi, role 5 non-bypass): B buka P 403 ARCHIVE407; A PUT {B} View -> B buka P 200; B (View saja) tidak boleh mengelola; A beri Update -> B boleh PUT user-permissions untuk user lain; A cabut -> B 403 ARCHIVE407 / 408',
        'run'   => function ($t) {
            q9_recover($t);
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

                // B (role 5 punya Update Folder, tetapi hanya View di P): GET 200, manage_permission false, PUT P ditolak
                $r = q9_get($t, $s2, $other[0]);
                $t->status($r, 200, 'B GET {user lain}');
                q9_access_is($t, q9_flatten($r[1]['result']['data'])[$P]['access'], [1, 0, 0, 0], 'B access P (View saja)');
                $r = q9_put($t, $s2, $other[0], ['permissions' => [q9_row_body($P, 1)]]);
                q9_deny($t, $r, 403, 'ARCHIVE408', 'B (View saja) PUT {user lain} P', $pName);
                $t->eq(json_encode(q9_user_map($t, $other[0], [$P])), '[]', 'DB: tidak ada baris untuk user lain');

                // A beri Update: B boleh mengelola P untuk user lain
                $r = q9_put($t, $s, $adi, ['permissions' => [q9_row_body($P, 1, 1)]]);
                $t->status($r, 200, 'A PUT {B} View+Update');
                $r = q9_put($t, $s2, $other[0], ['permissions' => [q9_row_body($P, 1, 0, 0, 1)]]);
                $t->status($r, 200, 'B (V+U) PUT {user lain} P');
                $t->eq(json_encode(q9_user_map($t, $other[0], [$P])), json_encode([$P => [1, 0, 0, 1]]), 'DB: (P,user lain) = V+S oleh B');
                $h = q9_history($t, $P);
                $t->eq(end($h)['related_username'] ?? null, $t->conf('QA_USER2'), 'riwayat: entri terakhir oleh B (QA_USER2)');

                // A mencabut: B ditolak
                $r = q9_put($t, $s, $adi, ['permissions' => [q9_row_body($P, 0, 0, 0, 0)]]);
                $t->status($r, 200, 'A mencabut');
                $r = $open();
                q9_deny($t, $r, 403, 'ARCHIVE407', 'B buka P setelah dicabut');
                $r = q9_put($t, $s2, $other[0], ['permissions' => [q9_row_body($P, 1)]]);
                q9_deny($t, $r, 403, 'ARCHIVE408', 'B tanpa hak PUT {user lain} P', $pName);
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-PERM',
        'title' => 'Permission per route (GET dan PUT user-permissions/{id}): role dengan Update Folder (1,2,3,5,26) 200; role tanpa (6,16,18,19,20,25,29) 403 GE0114 parameter "Update Folder", data tidak berubah; tanpa token 401 GE0111; QA_USER2 dicoba login',
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

                // tanpa token
                $r = $t->raw('GET', 'api/v5/document-archive/user-permissions/' . $b[0]);
                $t->status($r, 401, 'GET tanpa token');
                $t->eq(q9_code($r), 'GE0111', 'GET tanpa token: code');
                $r = $t->raw('PUT', 'api/v5/document-archive/user-permissions/' . $b[0], ['permissions' => [q9_row_body($f['P'], 1)]]);
                $t->status($r, 401, 'PUT tanpa token');
                $t->eq(q9_code($r), 'GE0111', 'PUT tanpa token: code');

                // role tanpa Update Folder: 403 GE0114 (GET, PUT sah, PUT invalid, PUT tidak ada user)
                foreach ([6, 16, 18, 19, 20, 25, 29] as $role) {
                    q9_with_user($t, ['role' => $role], function ($set) use ($t, $s, $b, $f, $role) {
                        foreach (['GET' => null,
                                  'PUT sah' => ['permissions' => [q9_row_body($f['P'], 1, 1, 1, 1)]],
                                  'PUT invalid' => ['permissions' => [q9_row_body($f['P'], 9)]],
                                  'PUT []' => ['permissions' => []]] as $label => $body) {
                            $r = $label === 'GET' ? q9_get($t, $s, $b[0]) : q9_put($t, $s, $b[0], $body);
                            q9_deny($t, $r, 403, 'GE0114', "role $role: $label");
                            $t->eq($r[1]['parameter'] ?? null, 'Update Folder', "role $role: $label: parameter");
                            $t->true(!array_key_exists('result', $r[1]), "role $role: $label: tanpa data");
                        }
                        $r = q9_put($t, $s, Q9_RANDOM_ID, ['permissions' => []]);
                        q9_deny($t, $r, 403, 'GE0114', "role $role: user tidak ada -> 403 mendahului 404");
                    });
                }
                $t->true(q9_same($before, q9_snap($t, $ids)), 'data tidak berubah oleh penolakan');
                $t->eq(q9_perm_total($t), $permBefore, 'jumlah baris tetap');

                // role dengan Update Folder: GET 200 (PUT [] 200)
                foreach ([1, 2, 3, 5, 26] as $role) {
                    q9_with_user($t, ['role' => $role], function ($set) use ($t, $s, $b, $role) {
                        $r = q9_get($t, $s, $b[0]);
                        $t->status($r, 200, "role $role: GET");
                        $t->eq(q9_code($r), 'ARCHIVE200', "role $role: GET code");
                        $r = q9_put($t, $s, $b[0], ['permissions' => []]);
                        $t->status($r, 200, "role $role: PUT []");
                        $t->eq(q9_code($r), 'ARCHIVE207', "role $role: PUT [] code");
                    });
                }

                // QA_USER2 (adi): coba login + bind ke QA_DB
                $s2 = $t->login('QA_USER2');
                $bind = $t->bind($s2, q9_dbname($t));
                if ($bind[0] === 200) {
                    $r = q9_get($t, $s2, $b[0]);
                    $t->note('QA_USER2 berhasil bind; GET = ' . $r[0] . ' ' . q9_code($r));
                    $t->true(in_array($r[0], [200, 403]), 'QA_USER2: GET 200 atau 403 (bukan 5xx)');
                    $r = q9_put($t, $s2, $b[0], ['permissions' => []]);
                    $t->true(in_array($r[0], [200, 403]), 'QA_USER2: PUT 200 atau 403');
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
        'title' => '(bagian BE, cara seragam EPIC K-2 b) role tanpa Update Folder: GET/PUT 403 GE0114 (bukan 500), data tetap; tidak ada permission baru di DB (Set Permission by User tidak ada); permission Update Folder (1085) dari seed lama',
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

                foreach ([6, 29] as $role) {
                    q9_with_user($t, ['role' => $role, 'lang' => 'ID'], function ($set) use ($t, $s, $b, $f, $role) {
                        $r = q9_get($t, $s, $b[0]);
                        q9_deny($t, $r, 403, 'GE0114', "role $role: GET");
                        $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['P'], 1, 1, 1, 1)]]);
                        q9_deny($t, $r, 403, 'GE0114', "role $role: PUT");
                        $t->true(!array_key_exists('result', $r[1]), "role $role: tanpa result");
                    });
                }
                $t->true(q9_same($before, q9_snap($t, $ids)), 'data tetap');

                $c = $t->db();
                $t->eq($c->table('permissions')->where('permission_name', 'like', '%Set Permission%')->count(), 0, 'tidak ada permission "Set Permission by User" di DB (K-1 a)');
                $t->eq($c->table('permissions')->where('id_permission', 1085)->value('permission_name'), 'Update Folder', 'permission 1085 = Update Folder');
                $t->eq($c->table('permissions')->max('id_permission'), 1133, 'id permission maksimum tetap 1133 (Opname Document; tidak ada 1134/1135)');
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
