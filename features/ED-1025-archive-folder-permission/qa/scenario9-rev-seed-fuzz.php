<?php
/**
 * ED-1025 (revisi review 2026-10-08) - AC-25 (EPIC K-4 a: seed permission_salesman.sql 1139/1140 + DB QA + DB scratch + kode tanpa id)
 * dan X-16 (PUT kelompok izin saja dengan tipe/nilai aneh: tanpa 5xx). Helper (q2_forbid_perm, q2_perm_row, q2_seed_rows, q2_seed_pairs, q2_db_pairs, q2_grep_tree) ada di qa_lib.php.
 * Peran fixture berawalan QA02-ROLE- (dibuang di finally), pelaku QA_USER. Lihat scenario8-rev-izin.php untuk definisi peran A/C/D/E/F.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'X-16',
        'title' => '(rev) PUT kelompok izin SAJA (tanpa name/is_all_location) dengan tipe/nilai aneh pada is_folder_permission dan folder_permissions, oleh A, D (tanpa Update Folder), C dan role tanpa keduanya: tidak ada 5xx, penolakan 4xx tidak mengubah data, 200 hanya untuk nilai sah',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            q2_role_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);
            $o = q2_others($t, 2);

            try {
                $roleC = q2_role($t, 'C', q2_perms_c());
                $roleD = q2_role($t, 'D', q2_perms_d());
                $roleN = q2_role($t, 'N', ['List Archive']);
                $F = q2_folder($t, 'F', ['perm' => 1]);
                q2_perm($t, $F, $b, 1, 1, 0, 0);
                q2_perm($t, $F, $o[0][0], 1);
                $valid = [q2_perm_row($o[1][0], 1, 0, 0, 1)];
                $values = [
                    'null' => null, 'string kosong' => '', 'string x' => 'x', 'string 0' => '0', 'string 1' => '1', 'int 0' => 0, 'int 1' => 1, 'int 2' => 2,
                    'int -1' => -1, 'float 1.5' => 1.5, 'true' => true, 'false' => false, 'array kosong' => [], 'array berisi array kosong' => [[]],
                    'array berisi null' => [null], 'objek a=1' => ['a' => 1], 'daftar angka' => [1, 2], 'daftar string' => ['x'],
                    'baris id_user array' => [['id_user' => [], 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0]],
                    'baris flag array' => [['id_user' => $o[1][0], 'is_view' => [1], 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0]],
                    'baris sah' => $valid, 'string 300' => str_repeat('a', 300),
                ];
                $codes = [];
                $cases = 0;
                $runAs = function ($label, $role) use ($t, $s, $F, $values, $valid, &$codes, &$cases) {
                    $one = function ($set) use ($t, $s, $F, $values, $valid, $label, &$codes, &$cases) {
                        foreach (['is_folder_permission', 'folder_permissions'] as $field) {
                            foreach ($values as $vl => $v) {
                                foreach ([[$field => $v], [$field => $v, $field === 'folder_permissions' ? 'is_folder_permission' : 'folder_permissions' => $field === 'folder_permissions' ? 1 : $valid]] as $i => $body) {
                                    $before = q2_snap($t, [$F]);
                                    $r = q2_put($t, $s, $F, $body);
                                    $cases++;
                                    $codes[$r[0]] = ($codes[$r[0]] ?? 0) + 1;
                                    q2_no500($t, $r, "$label $field=$vl #$i");
                                    if ($r[0] >= 400) {
                                        $t->true(q2_same($before, q2_snap($t, [$F])), "$label $field=$vl #$i: HTTP {$r[0]} tanpa perubahan data");
                                    }
                                    // pulihkan baris/toggle untuk kasus 200 berikutnya
                                    if ($r[0] === 200) {
                                        q2_w($t, function ($c) use ($F) {
                                            $c->table('archive_permissions')->where('id_archive', $F)->delete();
                                            $c->table('archives')->where('id_archive', $F)->update(['is_folder_permission' => 1]);
                                        });
                                    }
                                }
                            }
                        }
                    };
                    q2_with_user($t, ['role' => $role], $one);
                };
                $runAs('A', 3);
                $runAs('D', $roleD);
                $runAs('C', $roleC);
                $runAs('N', $roleN);
                $t->true($cases > 150, "jumlah kasus: $cases");
                $t->true(!isset($codes[500]) && !isset($codes[502]) && !isset($codes[503]), 'tanpa 5xx: ' . json_encode($codes));
                $t->note("$cases kasus; status: " . json_encode($codes));
            } finally {
                q2_purge($t);
                q2_role_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-25',
        'title' => '(rev EPIC K-4 a; ED-1149 K-4 b) permission_salesman.sql: Opname Document 1139 + Folder Permissions 1140 (module 1266, label en/id), tanpa id 1133-1138, checks/unchecks sesuai spec 3, hanya di berkas itu; DB QA: baris permissions 1139/1140 sama dengan seed (per id; role_permissions developer TIDAK diasersikan); seed utuh (permission_salesman.sql lalu role_permissions.sql) di DB scratch: role 1/2/3 memegang 1139/1140 dengan permission_name = seed, tanpa sisa 1133-1138 dan tanpa permission_name NULL; kode BE/FE tanpa id permission',
        'run'   => function ($t) {
            $c = $t->db();
            $beDir = $t->probe(function () {
                return base_path();
            });
            $dir = $beDir . '/app/Sql/data';
            $sql = file_get_contents($dir . '/permission_salesman.sql');

            // ---------- berkas seed
            $perms = q2_seed_rows($sql, 'permissions');
            $t->true($perms !== null && count($perms) > 0, 'blok INSERT permissions terbaca');
            $byId = [];
            foreach ($perms as $p) {
                $byId[(int) $p['id_permission']] = $p;
            }
            foreach ([1139 => ['Opname Document', 'Opname Dokumen', 'Opname Document', '10'], 1140 => ['Folder Permissions', 'Perizinan Folder', 'Folder Permissions', '11']] as $id => $want) {
                $t->true(isset($byId[$id]), "seed: tuple permission $id ada");
                $p = $byId[$id];
                $t->eq([$p['id_module'], $p['permission_label'], $p['indonesian_permission_label'], $p['permission_name'], $p['sort_index'], $p['is_active']],
                    ['1266', $want[0], $want[1], $want[2], $want[3], '1'], "seed: kolom permission $id (module 1266, label en/id, nama, sort, aktif)");
                $t->eq(count(array_filter($perms, function ($x) use ($id) {
                    return (int) $x['id_permission'] === $id;
                })), 1, "seed: $id tepat satu kali");
            }
            $t->eq(preg_match_all('/(?<![0-9])113[3-8](?![0-9])/', $sql), 0, 'seed: tanpa id 1133-1138 di mana pun di berkas (permissions/checks/unchecks)');
            $t->eq(preg_match_all("/'Folder Permissions'/", $sql), 2, "seed: 'Folder Permissions' di 2 kolom (label & nama) satu baris");
            $t->eq(preg_match_all("/'Opname Document'/", $sql), 2, "seed: 'Opname Document' di 2 kolom (label & nama) satu baris");
            $ids = array_keys($byId);
            $t->true(max($ids) === 1140, 'seed: id terbesar di tabel permissions = 1140');

            $checks = q2_seed_rows($sql, 'permission_checks');
            $unchecks = q2_seed_rows($sql, 'permission_unchecks');
            $wantChecks = ['1081,1139', '1081,1140', '1139,1082', '1140,1082', '1140,1084'];
            $wantUnchecks = ['1081,1139', '1081,1140', '1082,1139', '1082,1140', '1084,1140', '1139,1081', '1140,1081'];
            sort($wantChecks);
            sort($wantUnchecks);
            $t->eq(q2_seed_pairs($checks, [1139, 1140]), $wantChecks, 'seed: permission_checks 1139/1140 = (1081,1139) (1081,1140) (1139,1082) (1140,1082) (1140,1084)');
            $t->eq(q2_seed_pairs($unchecks, [1139, 1140]), $wantUnchecks, 'seed: permission_unchecks 1139/1140 = (1139,1081) (1140,1081) (1081,*) (1082,*) (1084,1140)');
            $t->eq(count(array_filter(array_merge(q2_seed_pairs($checks, [1140]), q2_seed_pairs($unchecks, [1140])), function ($pair) {
                return in_array('1085', explode(',', $pair), true);
            })), 0, 'seed: Folder Permissions tanpa pasangan dengan Update Folder (1085)');

            // ---------- hanya di berkas itu
            $hits = [];
            foreach (glob($dir . '/*.sql') as $f) {
                $body = file_get_contents($f);
                if (preg_match('/\((1139|1140),\s*1266,/', $body) || strpos($body, 'Folder Permissions') !== false || strpos($body, 'Perizinan Folder') !== false) {
                    $hits[] = basename($f);
                }
            }
            $t->eq($hits, ['permission_salesman.sql'], 'id 1139/1140 + "Folder Permissions" hanya di permission_salesman.sql');
            $rolePerm = file_get_contents($dir . '/role_permissions.sql');
            $t->true(strpos($rolePerm, 'SELECT 1 AS id_role,id_permission,permission_name FROM permissions') !== false
                && !preg_match('/(?<![0-9])(1139|1140)(?![0-9])/', $rolePerm), 'role_permissions.sql memberi role 1/2/3 semua permission aktif (tanpa id tertulis): 1139/1140 ikut saat seed ulang');

            // ---------- kode: nama permission, bukan id
            $feDir = $t->conf('FE_DIR');
            $codeIds = array_merge(
                q2_grep_tree($beDir . '/Modules/V5', '/(?<![0-9.])(1133|1139|1140)(?![0-9])/', ['php']),
                q2_grep_tree($beDir . '/app', '/(?<![0-9.])(1133|1139|1140)(?![0-9])/', ['php'], ['Sql', 'vendor', 'storage'])
            );
            $t->eq($codeIds, [], 'kode BE (Modules/V5, app): tanpa id permission 1133/1139/1140 (nama dipakai)');
            if ($feDir) {
                $t->eq(q2_grep_tree($feDir . '/src', '/permission[^\n]{0,80}(?<![0-9.])(1133|1139|1140)(?![0-9])/i', ['js', 'jsx', 'json'], ['node_modules', 'build']), [],
                    'kode FE: tanpa id permission 1133/1139/1140 (FE memakai nama)');
            }

            // ---------- DB QA = seed
            foreach ([1139, 1140] as $id) {
                $row = $c->table('permissions')->where('id_permission', $id)->first();
                $t->true($row !== null, "DB: permission $id ada");
                $p = $byId[$id];
                $t->eq([(int) $row->id_module, $row->permission_label, $row->indonesian_permission_label, $row->permission_name, (int) $row->sort_index, (int) $row->is_active],
                    [1266, $p['permission_label'], $p['indonesian_permission_label'], $p['permission_name'], (int) $p['sort_index'], 1], "DB: kolom permission $id = seed");
            }
            $t->eq($c->table('permissions')->whereBetween('id_permission', [1133, 1138])->count(), 0, 'DB: tanpa permission 1133-1138');
            $t->eq($c->table('permissions')->where('permission_name', 'Opname Document')->count(), 1, 'DB: satu baris Opname Document');
            $t->eq($c->table('permissions')->where('permission_name', 'Folder Permissions')->count(), 1, 'DB: satu baris Folder Permissions');
            $range = range(1133, 1140);
            $t->eq(q2_db_pairs($c, 'permission_checks', $range), $wantChecks, 'DB: permission_checks 1133-1140 = seed (tanpa sisa 1133)');
            $t->eq(q2_db_pairs($c, 'permission_unchecks', $range), $wantUnchecks, 'DB: permission_unchecks 1133-1140 = seed (tanpa sisa 1133)');
            // (ED-1149 K-4 b / BR-1 / BR-5) TIDAK ada asersi role_permissions di DB bersama: hak role 1/2/3 atas 1139/1140 dan permission_name-nya
            // (juga baris NULL hasil UI role developer) diasersikan di DB scratch hasil seed utuh (bagian "seed utuh" di bawah).
            $t->eq($c->table('modules')->where('id_module', 1266)->count(), 1, 'DB: modul Archive 1266 ada');

            // ---------- seed utuh jalan di DB scratch (sintaks, PK, urutan); dibuang sesudahnya
            // Urutan = ApplicationSetupService: permission_salesman.sql (fitur lisensi) lalu role_permissions.sql (role 1/2/3 memegang semua permission
            // aktif, DELETE + INSERT). Pra-seed scratch: permissions dasar disalin dari DB QA TANPA id yang disuntik seed, role_permissions berisi
            // sisa grant lama 1133-1138 untuk role 1/2/3 dan satu baris role 3 atas 1140 ber-permission_name NULL (bentuk hasil UI role developer):
            // sesudah seed semuanya harus bersih / tertulis ulang.
            $scratch = 'qa25_scratch_' . substr(uniqid(), -6);
            $db = q2_dbname($t);
            $error = null;
            $result = null;
            $roleSql = file_get_contents($dir . '/role_permissions.sql');
            $seedIds = array_map('intval', array_keys($byId));
            $t->probe(function () use ($db, $scratch, $sql, $roleSql, $seedIds, &$error, &$result) {
                $src = \Illuminate\Support\Facades\DB::connection($db);
                $cfg = $src->getConfig();
                $src->statement("CREATE DATABASE `$scratch` CHARACTER SET latin1 COLLATE latin1_general_ci");
                try {
                    $cfg['database'] = $scratch;
                    config(['database.connections.qa25scratch' => $cfg]);
                    \Illuminate\Support\Facades\DB::purge('qa25scratch');
                    $sc = \Illuminate\Support\Facades\DB::connection('qa25scratch');
                    foreach (['permissions', 'permission_checks', 'permission_unchecks', 'modules', 'role_permissions', 'role_allow_backdates', 'role_allow_backdate_transactions'] as $tb) {
                        $sc->statement("CREATE TABLE `$tb` LIKE `$db`.`$tb`");
                    }
                    $sc->statement("INSERT INTO `permissions` SELECT * FROM `$db`.`permissions` WHERE id_permission NOT IN (" . implode(',', $seedIds) . ')');
                    $preBase = $sc->table('permissions')->count();
                    $now = date('Y-m-d H:i:s');
                    foreach ([1, 2, 3] as $role) {
                        foreach (range(1133, 1138) as $legacy) {
                            $sc->table('role_permissions')->insert(['id_role' => $role, 'id_permission' => $legacy, 'permission_name' => 'Legacy ' . $legacy, 'created_at' => $now]);
                        }
                    }
                    $sc->table('role_permissions')->insert(['id_role' => 3, 'id_permission' => 1140, 'permission_name' => null, 'created_at' => $now]);
                    $run = function ($body) use ($sc) {
                        foreach (preg_split('/;\s*\n/', trim($body)) as $stmt) {
                            $stmt = trim($stmt);
                            if ($stmt !== '') {
                                $sc->unprepared($stmt);
                            }
                        }
                    };
                    $pairs = function ($table, $ids) use ($sc) {
                        return $sc->table($table)->whereIn('id_permission', $ids)->orWhereIn('id_permission_ref', $ids)->count();
                    };
                    $rpState = function () use ($sc) {
                        $out = ['holds' => [], 'counts' => [], 'null_names' => 0, 'legacy' => 0];
                        foreach ([1, 2, 3] as $role) {
                            foreach ([1139, 1140] as $id) {
                                $out['holds'][$role][$id] = $sc->table('role_permissions')->where('id_role', $role)->where('id_permission', $id)->pluck('permission_name')->all();
                            }
                            $out['counts'][$role] = $sc->table('role_permissions')->where('id_role', $role)->count();
                        }
                        $out['null_names'] = $sc->table('role_permissions')->whereIn('id_role', [1, 2, 3])->whereNull('permission_name')->count();
                        $out['legacy'] = $sc->table('role_permissions')->whereBetween('id_permission', [1133, 1138])->count();

                        return $out;
                    };
                    $run($sql);
                    $result = [
                        'p1139' => $sc->table('permissions')->where('id_permission', 1139)->count(),
                        'p1140' => $sc->table('permissions')->where('id_permission', 1140)->count(),
                        'old'   => $sc->table('permissions')->whereBetween('id_permission', [1133, 1138])->count(),
                        'checks'   => $pairs('permission_checks', [1139, 1140]),
                        'unchecks' => $pairs('permission_unchecks', [1139, 1140]),
                        'checks_old'   => $pairs('permission_checks', range(1133, 1138)),
                        'unchecks_old' => $pairs('permission_unchecks', range(1133, 1138)),
                    ];
                    $result['pre_legacy'] = $sc->table('role_permissions')->whereBetween('id_permission', [1133, 1138])->count();
                    $run($roleSql);
                    $result['active'] = $sc->table('permissions')->where('is_active', 1)->count();
                    $result['base'] = $preBase;
                    $result['rp'] = $rpState();
                    $run($roleSql); // role_permissions.sql dijalankan ulang (setup ulang): hasil sama, tanpa duplikat
                    $result['rp_again'] = $rpState();
                } catch (\Throwable $e) {
                    $error = get_class($e) . ': ' . substr($e->getMessage(), 0, 300);
                } finally {
                    $src->statement("DROP DATABASE IF EXISTS `$scratch`");
                    \Illuminate\Support\Facades\DB::purge('qa25scratch');
                }
            });
            $t->eq($error, null, 'seed permission_salesman.sql + role_permissions.sql dijalankan utuh di DB scratch tanpa error');
            $t->eq(array_intersect_key($result ?? [], array_flip(['p1139', 'p1140', 'old', 'checks', 'unchecks', 'checks_old', 'unchecks_old'])),
                ['p1139' => 1, 'p1140' => 1, 'old' => 0, 'checks' => count($wantChecks), 'unchecks' => count($wantUnchecks), 'checks_old' => 0, 'unchecks_old' => 0],
                'scratch: 1139 dan 1140 satu baris, checks/unchecks sesuai, tanpa 1133-1138');
            $t->eq($result['pre_legacy'] ?? null, 18, 'scratch (pra-seed role): sisa grant lama 1133-1138 untuk role 1/2/3 ada (6 id x 3 role) sebelum role_permissions.sql');
            $t->true(($result['base'] ?? 0) > 100 && ($result['active'] ?? 0) > ($result['base'] ?? 0), 'scratch: permissions dasar disalin dari DB QA (' . ($result['base'] ?? 0) . ') dan seed menambah permission aktif (' . ($result['active'] ?? 0) . ')');
            foreach ([1, 2, 3] as $role) {
                foreach ([1139 => 'Opname Document', 1140 => 'Folder Permissions'] as $id => $name) {
                    $t->eq($result['rp']['holds'][$role][$id] ?? null, [$name], "scratch (seed utuh): role $role memegang $name ($id) tepat satu baris dengan permission_name = seed");
                }
                $t->eq($result['rp']['counts'][$role] ?? null, $result['active'] ?? -1, "scratch (seed utuh): role $role memegang semua permission aktif (" . ($result['active'] ?? '?') . ')');
            }
            $t->eq($result['rp']['legacy'] ?? null, 0, 'scratch (seed utuh): tanpa role_permissions untuk id 1133-1138 (grant lama dibuang)');
            $t->eq($result['rp']['null_names'] ?? null, 0, 'scratch (seed utuh): tanpa permission_name NULL pada role 1/2/3 (baris NULL role 3 atas Folder Permissions ditulis ulang = nama seed)');
            $t->eq($result['rp_again'] ?? null, $result['rp'] ?? 'x', 'scratch: role_permissions.sql dijalankan ulang menghasilkan keadaan yang sama (idempoten)');
            $left = $c->selectOne("SELECT COUNT(*) n FROM information_schema.schemata WHERE schema_name LIKE 'qa25_scratch_%'")->n;
            $t->eq($left, 0, 'DB scratch dibuang');
            $t->note('MANUAL (Gate 2 epic): tanpa lisensi Salesman Activity permission tidak ada -> dibuktikan lewat baris seed yang hanya di permission_salesman.sql; tukar lisensi sungguhan tidak dijalankan (PROFILES kosong)');
        },
    ],

];
