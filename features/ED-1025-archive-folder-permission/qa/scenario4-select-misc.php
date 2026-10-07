<?php
/**
 * ED-1025 - AC-16 (select user Archive), AC-23 (bagian BE/http: modul permission wajib bersama hak folder; 403 GE0114),
 * X-4 (tanpa state basi antar request, role nonaktif bukan bypass), X-5 (tidak ada 5xx untuk input aneh di endpoint yang
 * diubah/baru), X-6 (ArchivePermissionService::viewableFolderIds untuk item 03-06, lewat probe), X-8 (D-2 diperbaiki;
 * karakterisasi perilaku pra-ada yang dilacak di ED-1070). Ketahanan input FormRequest lainnya: scenario5-input-types.php.
 *
 * Isolasi tenant: tidak berlaku (QA_DBS hanya api_sidomaju); tidak adanya state basi dibuktikan di X-4 (php-fpm).
 */
require_once __DIR__ . '/qa_lib.php';

/** Pengguna yang seharusnya muncul di select (hitung independen dari DB): [[id_user, username], ...] urut username. */
function q2_expected_users($t)
{
    $rows = $t->db()->select("
        SELECT u.id_user, u.username FROM users u
        WHERE u.is_active = 1
          AND EXISTS (SELECT 1 FROM user_roles ur
                      JOIN roles r ON r.id_role = ur.id_role AND r.is_active = 1
                      JOIN role_permissions rp ON rp.id_role = r.id_role
                      JOIN permissions p ON p.id_permission = rp.id_permission AND p.permission_name = 'List Archive'
                      WHERE ur.id_user = u.id_user)
          AND NOT EXISTS (SELECT 1 FROM user_roles ur
                          JOIN roles r ON r.id_role = ur.id_role AND r.is_active = 1 AND r.is_superadmin IN (1, 2)
                          WHERE ur.id_user = u.id_user)
          AND NOT EXISTS (SELECT 1 FROM customers cu WHERE cu.id_user = u.id_user)
        ORDER BY u.username");

    return array_map(function ($r) {
        return [$r->id_user, $r->username];
    }, $rows);
}

function q2_select_users($t, $query = '')
{
    return $t->call($t->session(), 'GET', 'api/v5/select/document-archive/archive/users' . ($query === '' ? '' : '?' . $query));
}

function q2_option_ids($r)
{
    return array_map(function ($o) {
        return $o['value'];
    }, $r[1]['result']['options'] ?? []);
}

/** Keadaan tabel yang disentuh AC-16 / X-4 (untuk pembuktian pemulihan persis). */
function q2_user_state($t)
{
    $c = $t->db();

    return md5(json_encode([
        $c->table('user_roles')->orderBy('id_user_role')->get(),
        $c->table('users')->orderBy('id_user')->get(['id_user', 'is_active', 'language']),
        $c->table('customers')->orderBy('id_customer')->pluck('id_user', 'id_customer'),
        $c->table('roles')->orderBy('id_role')->get(['id_role', 'is_active', 'is_superadmin']),
    ]));
}

return [

    [
        'id'    => 'AC-16',
        'title' => 'GET select/document-archive/archive/users: hanya user aktif ber-List Archive tanpa superadmin 1/2 & customer; excepts (array/skalar) menyembunyikan; search menyaring username; selected_id tetap ikut; bentuk dan urutan; 422/401',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            $stateBefore = q2_user_state($t);
            $expected = q2_expected_users($t);
            $ids = array_column($expected, 0);
            $t->true(count($expected) >= 4, 'prasyarat: >= 4 user layak di DB uji (' . count($expected) . ')');

            // 1. tanpa parameter: persis himpunan yang dihitung independen
            $r = q2_select_users($t);
            $t->status($r, 200, 'tanpa parameter');
            $t->code($r, 'SUCCESS', 'tanpa parameter');
            $t->eq($r[1]['result']['default'], null, 'default null');
            $got = $r[1]['result']['options'];
            foreach ($got as $o) {
                $t->eq(json_encode(array_keys($o)), json_encode(['value', 'label']), 'bentuk opsi {value,label}');
            }
            $t->eq(count($got), count($expected), 'jumlah opsi = hitungan independen');
            $a = q2_option_ids($r);
            $b = $ids;
            sort($a);
            sort($b);
            $t->eq(json_encode($a), json_encode($b), 'himpunan user = hitungan independen');
            $labels = array_column($got, 'label', 'value');
            foreach ($expected as $e) {
                $t->eq($labels[$e[0]] ?? null, $e[1], 'label = username (' . $e[1] . ')');
            }
            $prev = null;
            foreach ($got as $o) {
                $t->true($prev === null || strcasecmp($prev, $o['label']) <= 0, 'urut username naik: ' . $prev . ' <= ' . $o['label']);
                $prev = $o['label'];
            }

            // 2. excepts: array dan skalar
            $x = $expected[1][0];
            $y = $expected[2][0];
            $r = q2_select_users($t, http_build_query(['excepts' => [$x]]));
            $t->status($r, 200, 'excepts[]');
            $t->eq(json_encode(array_values(array_diff($ids, [$x]))), json_encode(array_values(q2_option_ids($r))), 'excepts[]: semua kecuali x (urutan tetap)');
            $r = q2_select_users($t, 'excepts=' . urlencode($x));
            $t->status($r, 200, 'excepts skalar');
            $t->eq(json_encode(array_values(array_diff($ids, [$x]))), json_encode(array_values(q2_option_ids($r))), 'excepts skalar: semua kecuali x');
            $r = q2_select_users($t, http_build_query(['excepts' => [$x, $y]]));
            $t->eq(count(q2_option_ids($r)), count($ids) - 2, 'excepts 2 id');
            $t->true(!in_array($x, q2_option_ids($r)) && !in_array($y, q2_option_ids($r)), 'x dan y tidak muncul');

            // 3. search menyaring username (tak peka huruf besar/kecil)
            foreach (['ad', 'AH', substr($expected[0][1], 1, 3)] as $term) {
                $r = q2_select_users($t, http_build_query(['search' => $term]));
                $t->status($r, 200, "search $term");
                $want = array_values(array_map(function ($e) {
                    return $e[0];
                }, array_filter($expected, function ($e) use ($term) {
                    return stripos($e[1], $term) !== false;
                })));
                $t->eq(json_encode($want), json_encode(array_values(q2_option_ids($r))), "search '$term': himpunan = filter independen");
            }
            $r = q2_select_users($t, http_build_query(['search' => 'zzzz-tidak-ada-' . uniqid()]));
            $t->eq(q2_option_ids($r), [], 'search tanpa hasil: options []');
            $r = q2_select_users($t, http_build_query(['search' => $expected[0][1], 'excepts' => [$expected[0][0]]]));
            $t->eq(in_array($expected[0][0], q2_option_ids($r)), false, 'search + excepts: yang di-excepts tidak muncul');

            // 4. selected_id tetap ikut walau tak layak (user non-aktif/tanpa List Archive/superadmin)
            $inelig = $t->db()->table('users')->whereNotIn('id_user', $ids)->orderBy('id_user')->limit(3)->pluck('username', 'id_user')->all();
            $t->true(count($inelig) >= 1, 'prasyarat: ada user tidak layak');
            $one = array_keys($inelig)[0];
            $r = q2_select_users($t, 'selected_id=' . urlencode($one));
            $t->status($r, 200, 'selected_id skalar');
            $t->true(in_array($one, q2_option_ids($r)), 'selected_id (tidak layak) tetap ikut');
            $t->eq(count(q2_option_ids($r)), count($ids) + 1, 'hanya satu tambahan');
            $r = q2_select_users($t, http_build_query(['selected_id' => array_keys($inelig)]));
            $t->eq(count(q2_option_ids($r)), count($ids) + count($inelig), 'selected_id array');

            // 5. kriteria dibuktikan dengan mengubah keadaan satu user layak (dipulihkan persis)
            $cand = null;
            foreach (array_reverse($expected) as $e) {
                if ($e[1] !== $t->conf('QA_USER')) {
                    $cand = $e;
                    break;
                }
            }
            $custId = $t->db()->table('customers')->whereNull('id_user')->orderBy('id_customer')->value('id_customer');
            $t->true($custId !== null, 'prasyarat: ada baris customers tanpa id_user');
            $snapRoles = q2_rows($t->db()->table('user_roles')->where('id_user', $cand[0])->orderBy('id_user_role')->get());
            $isActive = $t->db()->table('users')->where('id_user', $cand[0])->value('is_active');
            try {
                // a. punya role superadmin 2 (juga) -> hilang
                q2_w($t, function ($c) use ($cand) {
                    $c->table('user_roles')->insert(['id_user' => $cand[0], 'id_role' => 2, 'is_all_location' => 1, 'id_location' => null, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                });
                $r = q2_select_users($t);
                $t->true(!in_array($cand[0], q2_option_ids($r)), 'user dengan role superadmin 2 tidak muncul');
                $t->eq(count(q2_option_ids($r)), count($ids) - 1, 'hanya user itu yang hilang');
                q2_w($t, function ($c) use ($cand) {
                    $c->table('user_roles')->where('id_user', $cand[0])->where('id_role', 2)->delete();
                });
                $r = q2_select_users($t);
                $t->true(in_array($cand[0], q2_option_ids($r)), 'muncul lagi sesudah role dilepas');

                // b. non-aktif -> hilang
                q2_w($t, function ($c) use ($cand) {
                    $c->table('users')->where('id_user', $cand[0])->update(['is_active' => 0]);
                });
                $r = q2_select_users($t);
                $t->true(!in_array($cand[0], q2_option_ids($r)), 'user nonaktif tidak muncul');
                q2_w($t, function ($c) use ($cand, $isActive) {
                    $c->table('users')->where('id_user', $cand[0])->update(['is_active' => $isActive]);
                });

                // c. user customer -> hilang
                q2_w($t, function ($c) use ($cand, $custId) {
                    $c->table('customers')->where('id_customer', $custId)->update(['id_user' => $cand[0]]);
                });
                $r = q2_select_users($t);
                $t->true(!in_array($cand[0], q2_option_ids($r)), 'user customer tidak muncul');
                q2_w($t, function ($c) use ($custId) {
                    $c->table('customers')->where('id_customer', $custId)->update(['id_user' => null]);
                });
                $r = q2_select_users($t);
                $t->true(in_array($cand[0], q2_option_ids($r)), 'muncul lagi sesudah customer dilepas');
            } finally {
                q2_w($t, function ($c) use ($cand, $snapRoles, $isActive, $custId) {
                    $c->table('user_roles')->where('id_user', $cand[0])->delete();
                    if ($snapRoles) {
                        $c->table('user_roles')->insert($snapRoles);
                    }
                    $c->table('users')->where('id_user', $cand[0])->update(['is_active' => $isActive]);
                    $c->table('customers')->where('id_customer', $custId)->update(['id_user' => null]);
                });
            }
            $t->eq(q2_user_state($t), $stateBefore, 'user_roles/users/customers/roles dipulihkan persis');

            // 6. input salah -> 422 (bukan 500); tanpa token -> 401; tanpa permission_v5: role tanpa Archive tetap 200
            foreach (['search[]=x' => 'search array', 'excepts[][]=x' => 'excepts bersarang', 'selected_id[][]=x' => 'selected_id bersarang'] as $q => $label) {
                $r = q2_select_users($t, $q);
                q2_no500($t, $r, $label);
                $t->status($r, 422, $label);
            }
            $r = $t->raw('GET', 'api/v5/select/document-archive/archive/users');
            $t->status($r, 401, 'tanpa token');
            $r = q2_with_user($t, ['role' => 6], function ($set) use ($t, $s) {
                return q2_select_users($t);
            });
            $t->status($r, 200, 'role tanpa permission Archive: select tanpa permission_v5 (konvensi select) tetap 200');
            $t->eq(q2_user_state($t), $stateBefore, 'keadaan akhir sama dengan awal');
        },
    ],

    [
        'id'    => 'AC-23',
        'title' => '(bagian BE/http) permission modul Archive DAN hak folder sama-sama wajib: role tanpa List Archive / Update Folder 403 GE0114 walau hak folder lengkap, data tetap; role ber-modul tetap ditolak hak folder; 401 tanpa token. Tukar lisensi = MANUAL Gate 2',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);

            try {
                $P = q2_folder($t, 'P', ['perm' => 1]);
                $pName = q2_row($t, $P)['name'];
                q2_perm($t, $P, $b, 1, 1, 1, 1);                   // hak folder lengkap
                $Dr = q2_doc($t, 'DR');
                $before = q2_snap($t, [$P, $Dr]);
                $forbid = function ($r, $label, $permission) use ($t) {
                    $t->status($r, 403, $label);
                    $t->code($r, 'GE0114', $label);
                    $t->eq($r[1]['parameter'] ?? null, $permission, $label . ': parameter');
                    $t->true(!array_key_exists('result', $r[1]), $label . ': tanpa data');
                };
                $putBody = q2_body($pName, ['is_folder_permission' => 1, 'folder_permissions' => [
                    ['id_user' => $b, 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0],
                ]]);

                // role 6: tanpa permission Archive sama sekali
                q2_with_user($t, ['role' => 6], function ($set) use ($t, $s, $P, $putBody, $forbid) {
                    $forbid(q2_open($t, $s, $P), 'role 6: GET archives?id_archive=P', 'List Archive');
                    $forbid(q2_show($t, $s, $P), 'role 6: GET archives/{P}', 'List Archive');
                    $forbid(q2_hist($t, $s, $P), 'role 6: GET history/{P}', 'List Archive');
                    $forbid(q2_put($t, $s, $P, $putBody), 'role 6: PUT archives/{P} dengan folder_permissions', 'Update Folder');
                    $forbid(q2_delete($t, $s, $P), 'role 6: DELETE', 'Delete Folder');
                });

                // role 29: hanya List Archive -> membaca lolos modul + hak folder; menulis ditolak modul walau hak folder lengkap
                q2_with_user($t, ['role' => 29], function ($set) use ($t, $s, $P, $Dr, $putBody, $forbid) {
                    $r = q2_open($t, $s, $P);
                    $t->status($r, 200, 'role 29: buka P (modul List + View folder)');
                    q2_access_is($t, $r[1]['result']['access'], q2_all_true(), 'role 29: access (hak folder; modul dinilai terpisah di FE)');
                    $forbid(q2_put($t, $s, $P, $putBody), 'role 29: PUT dengan folder_permissions', 'Update Folder');
                    $forbid(q2_rename($t, $s, $P, 'x'), 'role 29: rename', 'Update Folder');
                    $forbid(q2_delete($t, $s, $P), 'role 29: DELETE', 'Delete Folder');
                    $forbid(q2_create($t, $s, ['name' => q2_name('SUB'), 'id_archive_parent' => $P, 'is_all_location' => 1]), 'role 29: create-folder', 'Add Folder');
                    $forbid(q2_putin($t, $s, $P, $Dr), 'role 29: put-in', 'Move Archive, Add Document');
                });
                $t->true(q2_same($before, q2_snap($t, [$P, $Dr])), 'data P dan dokumen tidak berubah oleh semua penolakan modul');

                // role 29 tetapi hak folder tidak ada: modul lolos, hak folder menolak
                q2_unperm($t, $P, $b);
                q2_with_user($t, ['role' => 29], function ($set) use ($t, $s, $P) {
                    $r = q2_open($t, $s, $P);
                    $t->status($r, 403, 'role 29 tanpa hak folder: buka P');
                    $t->eq($r[1]['msg_code'], 'ARCHIVE407', 'role 29 tanpa hak folder: ARCHIVE407 (bukan GE0114)');
                });
                // role 16 (List+Move+Handover+Receive) dengan hak folder lengkap: put-in lolos, rename/PUT ditolak modul
                q2_perm($t, $P, $b, 1, 1, 1, 1);
                q2_with_user($t, ['role' => 16], function ($set) use ($t, $s, $P, $Dr, $forbid) {
                    $r = q2_putin($t, $s, $P, $Dr);
                    $t->status($r, 200, 'role 16: put-in (Move Archive + Store folder)');
                    $forbid(q2_rename($t, $s, $P, 'x'), 'role 16: rename', 'Update Folder');
                });
                // tanpa token
                $r = $t->raw('GET', q2_url('api/v5/document-archive/archives', ['id_archive' => $P]));
                $t->status($r, 401, 'tanpa token: list');
                $r = $t->raw('PUT', 'api/v5/document-archive/archives/' . $P, $putBody);
                $t->status($r, 401, 'tanpa token: PUT');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-4',
        'title' => 'Tanpa state basi antar request (php-fpm): 20 pergantian berurutan baris permission / toggle / role / pembuat langsung tercermin; role nonaktif bukan bypass',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);
            $me = q2_uname($t);
            $seq = [];

            try {
                q2_with_user($t, [], function ($set) use ($t, $s, $b, $me, &$seq) {
                    $P = q2_folder($t, 'P', ['perm' => 1]);
                    $C = q2_folder($t, 'C', ['perm' => 1, 'parent' => $P]);
                    $step = function ($label, $http, callable $apply = null) use ($t, $s, $C, &$seq) {
                        if ($apply) {
                            $apply();
                        }
                        $r = q2_open($t, $s, $C);
                        $seq[] = $label . '=' . $r[0];
                        $t->status($r, $http, $label);
                    };
                    for ($i = 1; $i <= 3; $i++) {
                        $step("[$i] tanpa baris", 403);
                        $step("[$i] View di C dan P", 200, function () use ($t, $P, $C, $b) {
                            q2_perm($t, $P, $b, 1);
                            q2_perm($t, $C, $b, 1);
                        });
                        $step("[$i] View di C dicabut", 403, function () use ($t, $C, $b) {
                            q2_unperm($t, $C, $b);
                        });
                        $step("[$i] role 1 (bypass)", 200, function () use ($set) {
                            $set(['role' => 1]);
                        });
                        $step("[$i] role asli kembali", 403, function () use ($set) {
                            $set([]);
                        });
                        q2_unperm($t, $P, $b);
                    }
                    $step('[x] toggle P mati (C aktif tanpa baris tetap menolak)', 403, function () use ($t, $P) {
                        q2_set_archive($t, $P, ['is_folder_permission' => 0]);
                    });
                    $step('[x] C nonaktif-permission dan P mati: tetap 200', 200, function () use ($t, $C) {
                        q2_set_archive($t, $C, ['is_folder_permission' => 0]);
                    });
                    $step('[x] P aktif lagi', 403, function () use ($t, $P) {
                        q2_set_archive($t, $P, ['is_folder_permission' => 1]);
                    });
                    $step('[x] pembuat P = B', 200, function () use ($t, $P, $me) {
                        q2_set_archive($t, $P, ['created_by' => $me]);
                    });
                    $step('[x] pembuat P = orang lain', 403, function () use ($t, $P) {
                        q2_set_archive($t, $P, ['created_by' => 'QA02']);
                    });
                });
                $t->note(implode(' ', $seq));
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);

            // role nonaktif bukan bypass (roles.is_active = 0 -> role itu tidak dihitung)
            $role2 = $t->db()->table('roles')->where('id_role', 2)->first(['id_role', 'is_active']);
            $stateBefore = q2_user_state($t);
            try {
                $P2 = q2_folder($t, 'P2', ['perm' => 1]);
                q2_with_user($t, ['role' => [3, 2]], function ($set) use ($t, $s, $P2) {
                    $t->status(q2_open($t, $s, $P2), 200, 'role 3+2 aktif: bypass');
                    q2_w($t, function ($c) {
                        $c->table('roles')->where('id_role', 2)->update(['is_active' => 0]);
                    });
                    try {
                        $r = q2_open($t, $s, $P2);
                        $t->status($r, 403, 'role 2 nonaktif: bukan bypass');
                        $t->eq($r[1]['msg_code'], 'ARCHIVE407', 'role 2 nonaktif: ARCHIVE407');
                    } finally {
                        q2_w($t, function ($c) {
                            $c->table('roles')->where('id_role', 2)->update(['is_active' => 1]);
                        });
                    }
                    $t->status(q2_open($t, $s, $P2), 200, 'role 2 aktif lagi: bypass');
                });
            } finally {
                q2_w($t, function ($c) use ($role2) {
                    $c->table('roles')->where('id_role', 2)->update(['is_active' => $role2->is_active]);
                });
                q2_purge($t);
            }
            $t->eq(q2_user_state($t), $stateBefore, 'roles/user_roles dipulihkan persis');
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-5',
        'title' => 'Input aneh pada field/endpoint baru atau diubah item ini (folder_permissions, is_folder_permission, select user, id di route, put-in/create-folder): tidak ada 5xx, tak valid = 4xx',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);
            $o = q2_others($t, 1);

            try {
                $P = q2_folder($t, 'P', ['perm' => 1]);
                $pName = q2_row($t, $P)['name'];
                $api = 'api/v5/document-archive/';
                $good = ['id_user' => $o[0][0], 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0];
                $reset = function () use ($t, $P, $b, $pName) {
                    q2_set_archive($t, $P, ['is_folder_permission' => 1, 'name' => $pName, 'id_archive_parent' => null]);
                    q2_w($t, function ($c) use ($P) {
                        $c->table('archive_permissions')->where('id_archive', $P)->delete();
                    });
                    q2_perm($t, $P, $b, 1, 1, 1, 1);
                };
                $withB = function (array $row) use ($b) {
                    return [['id_user' => $b, 'is_view' => 1, 'is_update' => 1, 'is_delete' => 1, 'is_store' => 1], $row];
                };

                // [label, method, uri, body, status yang diharapkan (null = hanya <500)]
                $cases = [
                    ['GET id_archive[]', 'GET', $api . 'archives?id_archive[]=x', null, 404],
                    ['GET id_archive objek', 'GET', $api . 'archives?id_archive[a]=x', null, 404],
                    ['GET id_archive %00', 'GET', $api . 'archives?id_archive=%00', null, null],
                    ['GET search []', 'GET', $api . 'archives?search=' . urlencode('[]'), null, null],
                    ['GET show id aneh', 'GET', $api . 'archives/%27%20OR%201%3D1', null, 404],
                    ['GET show id panjang', 'GET', $api . 'archives/' . str_repeat('a', 300), null, 404],
                    ['GET history id aneh', 'GET', $api . 'archives/history/%27%20OR%201%3D1', null, 404],
                    ['DELETE id aneh', 'DELETE', $api . 'archives/delete/%27%20OR%201%3D1', null, 404],
                    ['rename id aneh', 'PUT', $api . 'archives/rename/%27%20OR%201%3D1', ['name' => 'x'], 404],
                    ['PUT id aneh', 'PUT', $api . 'archives/%27%20OR%201%3D1', q2_body('x'), 404],
                    ['PUT rows [[]]', 'PUT', $api . 'archives/' . $P, q2_body($pName, ['folder_permissions' => [[]]]), 422],
                    ['PUT rows [null]', 'PUT', $api . 'archives/' . $P, q2_body($pName, ['folder_permissions' => [null]]), 422],
                    ['PUT rows id_user array', 'PUT', $api . 'archives/' . $P, q2_body($pName, ['folder_permissions' => $withB(['id_user' => ['x']] + $good)]), 422],
                    ['PUT rows is_view array', 'PUT', $api . 'archives/' . $P, q2_body($pName, ['folder_permissions' => $withB(['is_view' => [1]] + $good)]), 422],
                    ['PUT rows is_update objek', 'PUT', $api . 'archives/' . $P, q2_body($pName, ['folder_permissions' => $withB(['is_update' => ['a' => 1]] + $good)]), 422],
                    ['PUT is_folder_permission objek', 'PUT', $api . 'archives/' . $P, q2_body($pName, ['is_folder_permission' => ['a' => 1]]), 422],
                    ['PUT rows 300 duplikat', 'PUT', $api . 'archives/' . $P, q2_body($pName, ['folder_permissions' => array_fill(0, 300, $good)]), 422],
                    ['PUT rows flag string "1" + kunci asing', 'PUT', $api . 'archives/' . $P, q2_body($pName, ['folder_permissions' => $withB(['is_view' => '1', 'is_store' => '1'] + $good + ['x' => 1])]), 200],
                    ['PUT rows flag boolean true', 'PUT', $api . 'archives/' . $P, q2_body($pName, ['folder_permissions' => $withB(['is_view' => true, 'is_update' => true] + $good)]), null],
                    ['PUT rows objek berkunci', 'PUT', $api . 'archives/' . $P, q2_body($pName, ['folder_permissions' => ['a' => $good, 'b' => ['id_user' => $b, 'is_view' => 1, 'is_update' => 1, 'is_delete' => 1, 'is_store' => 1]]]), null],
                    ['PUT rows 200 baris valid tak ada (id_user tak ada)', 'PUT', $api . 'archives/' . $P, q2_body($pName, ['folder_permissions' => array_map(function ($i) {
                        return ['id_user' => 'QA02-NOPE-' . $i, 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0];
                    }, range(1, 200))]), 422],
                    ['create parent array', 'POST', $api . 'archives/create-folder', ['name' => 'x', 'id_archive_parent' => ['a'], 'is_all_location' => 1], 422],
                    ['create parent objek', 'POST', $api . 'archives/create-folder', ['name' => 'x', 'id_archive_parent' => ['a' => 'b'], 'is_all_location' => 1], 422],
                    ['put-in parent array', 'POST', $api . 'documents/put-in', ['id_archive_parent' => ['a'], 'name' => 'x'], 422],
                    ['put-in id_archives objek', 'POST', $api . 'documents/put-in', ['id_archives' => ['a' => 'b'], 'id_archive_parent' => $P], null],
                    ['select search array', 'GET', 'api/v5/select/document-archive/archive/users?search[]=x', null, 422],
                    ['select excepts objek', 'GET', 'api/v5/select/document-archive/archive/users?excepts[a]=x', null, 200],
                    ['select excepts bersarang', 'GET', 'api/v5/select/document-archive/archive/users?excepts[a][b]=x', null, 422],
                    ['select selected_id objek', 'GET', 'api/v5/select/document-archive/archive/users?selected_id[a]=x', null, 200],
                    ['select search panjang', 'GET', 'api/v5/select/document-archive/archive/users?search=' . str_repeat('x', 3000), null, 200],
                    ['select search wildcard %', 'GET', 'api/v5/select/document-archive/archive/users?search=%25', null, 200],
                ];

                $seen = [];
                $bad = [];
                foreach ($cases as $c) {
                    $reset();
                    $r = $t->call($s, $c[1], $c[2], $c[3]);
                    $seen[] = $c[0] . '=' . $r[0];
                    if ($r[0] >= 500) {
                        $bad[] = $c[0] . ' -> HTTP ' . $r[0] . ' ' . substr(json_encode($r[1]), 0, 160);
                        continue;
                    }
                    if ($c[4] !== null && (int) $r[0] !== (int) $c[4]) {
                        $bad[] = $c[0] . ' -> HTTP ' . $r[0] . ', diharapkan ' . $c[4] . ' ' . substr(json_encode($r[1]), 0, 160);
                    }
                }
                $t->note(implode(' ; ', $seen));
                $t->eq($bad, [], 'tidak ada 5xx / status tak terduga');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-8',
        'title' => 'D-2 diperbaiki: name bertipe array pada PUT archives/{id}, rename, create-folder = 422 (data tidak berubah). ED-1070 (karakterisasi perilaku pra-ada): search list bukan JSON objek = 500; PUT id_archive_parent = diri sendiri = 200 + siklus',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);
            $archivesBefore = $t->db()->table('archives')->count();

            try {
                $P = q2_folder($t, 'P', ['perm' => 0]);
                $pName = q2_row($t, $P)['name'];
                $api = 'api/v5/document-archive/';

                // --- D-2 (diperbaiki ronde 1): ASERSI. name array/objek = 422 di tiga endpoint, folder tidak berubah
                foreach (['array' => ['x'], 'objek' => ['a' => 'b']] as $label => $v) {
                    $r = $t->call($s, 'PUT', $api . 'archives/' . $P, ['name' => $v, 'is_all_location' => 1]);
                    $t->status($r, 422, 'PUT name ' . $label);
                    $r = $t->call($s, 'PUT', $api . 'archives/rename/' . $P, ['name' => $v]);
                    $t->status($r, 422, 'rename name ' . $label);
                    $r = $t->call($s, 'POST', $api . 'archives/create-folder', ['name' => $v, 'is_all_location' => 1]);
                    $t->status($r, 422, 'create name ' . $label);
                }
                $t->eq(q2_row($t, $P)['name'], $pName, 'nama P tidak berubah');
                $t->eq($t->db()->table('archives')->count(), $archivesBefore + 1, 'create-folder yang ditolak tidak membuat baris (hanya P uji)');

                // --- ED-1070 (karakterisasi, BUKAN asersi perilaku yang benar): perilaku pra-ada yang dilacak di Jira ED-1070.
                // Bila salah satu berubah (mis. 500 menjadi 4xx), skenario ini GAGAL dengan sengaja: perbarui karakterisasi
                // ini mengikuti ED-1070 (ubah menjadi asersi 4xx / tanpa siklus) dan hapus tag.
                $drift = [];
                $search = [
                    'GET search bukan JSON'           => 'bukan-json',
                    'GET search angka'                => '123',
                    'GET search string JSON'          => '"str"',
                    'GET search query array'          => '{"query":["a"]}',
                ];
                foreach ($search as $label => $q) {
                    $r = $t->call($s, 'GET', $api . 'archives?search=' . urlencode($q));
                    if ($r[0] !== 500) {
                        $drift[] = 'ED-1070 berubah: ' . $label . ' kini HTTP ' . $r[0] . ' (sebelumnya 500)';
                    }
                }
                $r = $t->call($s, 'GET', $api . 'archives?id_archive=' . $P . '&search=bukan-json');
                if ($r[0] !== 500) {
                    $drift[] = 'ED-1070 berubah: GET search + id_archive bukan JSON kini HTTP ' . $r[0] . ' (sebelumnya 500)';
                }

                // PUT id_archive_parent = diri sendiri (O-3): 200 dan folder menjadi induk dirinya (siklus); dipulihkan
                $r = q2_put($t, $s, $P, q2_body($pName, ['id_archive_parent' => $P]));
                $selfParent = q2_row($t, $P)['id_archive_parent'] === $P;
                q2_set_archive($t, $P, ['id_archive_parent' => null]);
                if ($r[0] !== 200 || !$selfParent) {
                    $drift[] = 'ED-1070 berubah: PUT id_archive_parent = diri sendiri kini HTTP ' . $r[0] . ', induk = diri sendiri: ' . var_export($selfParent, true) . ' (sebelumnya 200 + siklus)';
                }
                $t->eq($drift, [], 'karakterisasi ED-1070 sama dengan perilaku pra-ada (bila gagal: ED-1070 telah mengubah perilaku, perbarui skenario)');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-6',
        'title' => 'ArchivePermissionService::viewableFolderIds (dipakai item 03-06) lewat probe: folder aktif dalam scope lokasi dan View efektif; induk menolak, nonaktif, di luar lokasi dikeluarkan; superadmin = semua folder aktif',
        'run'   => function ($t) {
            q2_recover($t);
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);
            $db = q2_dbname($t);

            try {
                $V1 = q2_folder($t, 'V1', ['perm' => 1]);                                  // B View -> ya
                $V2 = q2_folder($t, 'V2', ['perm' => 1]);                                  // B tanpa baris -> tidak
                $V3 = q2_folder($t, 'V3', ['perm' => 0]);                                  // nonaktif-permission -> ya
                $V4 = q2_folder($t, 'V4', ['perm' => 1, 'parent' => $V2]);                 // B View, induk V2 menolak -> tidak
                $V5 = q2_folder($t, 'V5', ['perm' => 1, 'active' => 0]);                   // folder nonaktif -> tidak
                $V6 = q2_folder($t, 'V6', ['perm' => 0]);                                  // di luar scope lokasi -> tidak
                $V7 = q2_folder($t, 'V7', ['perm' => 1, 'by' => q2_uname($t)]);            // pembuat -> ya
                $V8 = q2_folder($t, 'V8', ['perm' => 1]);                                  // baris U/D/S tanpa View -> tidak
                q2_perm($t, $V1, $b, 1);
                q2_perm($t, $V4, $b, 1);
                q2_perm($t, $V5, $b, 1);
                q2_perm($t, $V8, $b, 0, 1, 1, 1);
                q2_set_archive($t, $V6, ['is_all_location' => 0]);

                $call = function ($idUser) use ($t, $db) {
                    return $t->probe(function () use ($db, $idUser) {
                        \Illuminate\Support\Facades\DB::setDefaultConnection($db);

                        return (new \Modules\V5\Http\Services\DocumentArchive\ArchivePermissionService)->viewableFolderIds($idUser);
                    });
                };

                $ids = $call($b);
                $mine = array_values(array_intersect($ids, [$V1, $V2, $V3, $V4, $V5, $V6, $V7, $V8]));
                sort($mine);
                $want = [$V1, $V3, $V7];
                sort($want);
                $t->eq(json_encode($mine), json_encode($want), 'viewableFolderIds(B) di antara folder uji = V1, V3, V7');
                $active = $t->db()->table('archives')->where('type', 1)->where('is_active', '>', 0)->pluck('id_archive')->all();
                $t->eq(count(array_diff($ids, $active)), 0, 'semua id yang dikembalikan = folder aktif');
                $t->eq(count($ids), count(array_unique($ids)), 'tanpa duplikat');

                // superadmin 1/2 (user lain di DB bila ada): semua folder aktif
                $super = $t->db()->table('user_roles')->join('roles', 'roles.id_role', '=', 'user_roles.id_role')
                    ->whereIn('roles.is_superadmin', [1, 2])->where('roles.is_active', 1)->orderBy('user_roles.id_user')->value('user_roles.id_user');
                if ($super) {
                    $sid = $call($super);
                    sort($sid);
                    $act = $active;
                    sort($act);
                    $t->eq(json_encode($sid), json_encode($act), 'viewableFolderIds(superadmin) = semua folder aktif (termasuk V2, V4, V6)');
                } else {
                    $t->note('tidak ada user is_superadmin 1/2 lain di DB uji: bagian superadmin dilewati');
                }
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

];
