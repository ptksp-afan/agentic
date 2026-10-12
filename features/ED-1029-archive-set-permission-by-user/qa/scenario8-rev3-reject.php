<?php
/**
 * ED-1029 rev3 (ED-1128 BE, ED-1130 QA) - AC-35: penolakan PUT user-permissions/{id} dengan `folders` (BR-13, urutan kontrak §2):
 *   403 GE0114 -> 404 ARCHIVE440 -> 403 ARCHIVE407 (permissions ATAU folders) -> 422 -> 404 ARCHIVE400 -> 400 ARCHIVE441 -> 400 ARCHIVE411
 * plus atomik (flag dan baris otomatis yang sudah ditulis dibatalkan saat ARCHIVE411 muncul di tengah transaksi), kiriman yang sah
 * (folder tanpa View pemanggil, superadmin 1/2 di luar lokasi, bentuk nilai), fuzz id TANPA karakter non-latin1 (O-6: probe non-latin1
 * `folders` hanya di X-1 scenario4-robust.php, harapan 500 sekarang, milik ED-1071) dan penolakan QA_USER2 tanpa Folder Permissions.
 */
require_once __DIR__ . '/qa_lib_r3.php';

return [

    [
        'id'    => 'AC-35',
        'title' => '(rev3) D/E PUT {B} folders [F:1] = 403 GE0114 (mendahului 404/422/407); A: folders [L:1] = 403 ARCHIVE407 (juga bila body lain tidak sah; tanpa parameter), dokumen/tidak ada/nonaktif = 404 ARCHIVE400, id ganda / is_folder_permission bukan 0,1 / folders bukan array = 422, folders [JB:1] (A tanpa View) = 200; superadmin 1/2 folders [L:1] = 200; folders [F:1] + baris 411 / id tidak ada / 407 / 441 = F tetap Off, tanpa baris (F,A), tanpa riwayat (atomik); urutan 440 -> 407 -> 422 -> 400 -> 441 -> 411; fuzz id tanpa non-latin1 tidak 5xx',
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
                $f = r3_fixture($t, $me, $meName, $b[0]);
                $ids = array_values($f);
                $F = $f['F'];
                $K = $f['K'];
                $L = $f['L'];
                $bad = q9_row_body($f['P'], 9);
                $before = q9_snap($t, $ids);
                $permBefore = q9_perm_total($t);
                $same = function ($label) use ($t, $ids, $before, $permBefore) {
                    r3_unchanged($t, $ids, $before, $permBefore, $label);
                };
                q9_codes_reset();

                // ---- 1. D (Update Folder tanpa Folder Permissions) dan E: semua bentuk body dengan folders = GE0114 (en + id)
                foreach (['D', 'E'] as $p) {
                    foreach (['EN', 'ID'] as $lang) {
                        q9_with_user($t, ['role' => $roles[$p], 'emp' => ['SMR'], 'lang' => $lang], function ($set) use ($t, $s, $b, $F, $L, $bad, $p, $lang) {
                            $cases = [
                                'folders [F:1] + permissions []'          => [$b[0], r3_body([], [r3_fl($F, 1)])],
                                'folders [F:1] + user tidak ada'          => [Q9_RANDOM_ID, r3_body([], [r3_fl($F, 1)])],
                                'folders [L:1] + permissions tidak sah'   => [$b[0], r3_body([$bad], [r3_fl($L, 1)])],
                                'folders id tidak ada'                    => [$b[0], r3_body([], [r3_fl(Q9_RANDOM_ID, 1)])],
                                'folders id ganda (422 bila lolos)'       => [$b[0], r3_body([], [r3_fl($F, 1), r3_fl($F, 0)])],
                                'is_folder_permission 2 (422 bila lolos)' => [$b[0], r3_body([], [r3_fl($F, 2)])],
                                'folders bukan array'                     => [$b[0], ['permissions' => [], 'folders' => 'F']],
                                'folders null'                            => [$b[0], ['permissions' => [], 'folders' => null]],
                                'folders saja, tanpa permissions'         => [$b[0], ['folders' => [r3_fl($F, 1)]]],
                            ];
                            foreach ($cases as $label => $c) {
                                $r = q9_put($t, $s, $c[0], $c[1]);
                                $tag = "$p/$lang: $label";
                                q9_deny($t, $r, 403, 'GE0114', $tag);
                                $t->eq($r[1]['parameter'] ?? null, 'Folder Permissions', "$tag: parameter");
                                $t->true(!array_key_exists('result', $r[1]), "$tag: tanpa result");
                            }
                        });
                    }
                }
                $same('D dan E');

                // ---- 2. A (Folder Permissions, lokasi SMR): urutan penolakan
                q9_with_user($t, r3_ctx_a($roles), function ($set) use ($t, $s, $me, $b, $f, $ids, $F, $K, $L, $bad, $same, $before, $permBefore) {
                    $noParam = function ($r, $label) use ($t) {
                        $t->true(($r[1]['parameter'] ?? null) === null, "$label: ARCHIVE407 tanpa parameter (teks generik)");
                    };

                    // 440 mendahului 407/422/400
                    $r = q9_put($t, $s, Q9_RANDOM_ID, r3_body([$bad], [r3_fl($L, 1)]));
                    q9_deny($t, $r, 404, 'ARCHIVE440', '2.1 user tidak ada + folders [L] + permissions tidak sah: 440');
                    $r = q9_put($t, $s, Q9_RANDOM_ID, r3_body([], [r3_fl(Q9_RANDOM_ID, 1)]));
                    q9_deny($t, $r, 404, 'ARCHIVE440', '2.1b user tidak ada + folders id tidak ada: 440 (bukan 400)');
                    $same('2.1');

                    // 407: folders [L] saja, bersama folder sah, dan bersama body tidak sah (407 mendahului 422)
                    $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($L, 1)]));
                    q9_deny($t, $r, 403, 'ARCHIVE407', '2.2 folders [L:1]');
                    $noParam($r, '2.2');
                    $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($F, 1), r3_fl($L, 1)]));
                    q9_deny($t, $r, 403, 'ARCHIVE407', '2.3 folders [F:1, L:1]: F sah tidak ikut tersimpan');
                    $t->eq(r3_flag($t, $F), 0, '2.3 F tetap Off');
                    $r = q9_put($t, $s, $b[0], r3_body([$bad], [r3_fl($L, 1)]));
                    q9_deny($t, $r, 403, 'ARCHIVE407', '2.4 folders [L] + permissions tidak sah (flag 9): 407 mendahului 422');
                    $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($L, 1), r3_fl($L, 0)]));
                    q9_deny($t, $r, 403, 'ARCHIVE407', '2.5 folders [L:1, L:0] ganda: 407 mendahului 422');
                    $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($L, 7)]));
                    q9_deny($t, $r, 403, 'ARCHIVE407', '2.6 folders [L:7]: 407 mendahului 422');
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($L, 1)], [r3_fl($F, 1)]));
                    q9_deny($t, $r, 403, 'ARCHIVE407', '2.7 permissions [L] + folders [F:1]');
                    $same('2.2-2.7 (407)');

                    // 422 (folders): id ganda, nilai bukan 0/1, bukan array, bentuk elemen salah
                    $r422 = [
                        'id ganda'                    => ['permissions' => [], 'folders' => [r3_fl($F, 1), r3_fl($F, 0)]],
                        'id ganda + id tidak ada'     => ['permissions' => [q9_row_body(Q9_RANDOM_ID, 1)], 'folders' => [r3_fl($F, 1), r3_fl($F, 1)]],
                        'is_folder_permission 2'      => ['permissions' => [], 'folders' => [r3_fl($F, 2)]],
                        'is_folder_permission -1'     => ['permissions' => [], 'folders' => [r3_fl($F, -1)]],
                        'is_folder_permission "abc"'  => ['permissions' => [], 'folders' => [r3_fl($F, 'abc')]],
                        'is_folder_permission null'   => ['permissions' => [], 'folders' => [r3_fl($F, null)]],
                        'is_folder_permission ""'     => ['permissions' => [], 'folders' => [r3_fl($F, '')]],
                        'is_folder_permission hilang' => ['permissions' => [], 'folders' => [['id_archive' => $F]]],
                        'id_archive hilang'           => ['permissions' => [], 'folders' => [['is_folder_permission' => 1]]],
                        'id_archive ""'               => ['permissions' => [], 'folders' => [r3_fl('', 1)]],
                        'id_archive int'              => ['permissions' => [], 'folders' => [r3_fl(123, 1)]],
                        'id_archive array'            => ['permissions' => [], 'folders' => [r3_fl([$F], 1)]],
                        'folders string'              => ['permissions' => [], 'folders' => 'F'],
                        'folders angka'               => ['permissions' => [], 'folders' => 5],
                        'folders null'                => ['permissions' => [], 'folders' => null],
                        'folders ""'                  => ['permissions' => [], 'folders' => ''],
                        'elemen string'               => ['permissions' => [], 'folders' => [$F]],
                        'elemen null'                 => ['permissions' => [], 'folders' => [null]],
                        'elemen []'                   => ['permissions' => [], 'folders' => [[]]],
                        'objek tunggal'               => ['permissions' => [], 'folders' => r3_fl($F, 1)],
                        'folders saja, tanpa permissions' => ['folders' => [r3_fl($F, 1)]],
                        'folders + permissions null'  => ['permissions' => null, 'folders' => [r3_fl($F, 1)]],
                    ];
                    foreach ($r422 as $label => $body) {
                        $r = q9_put($t, $s, $b[0], $body);
                        $t->status($r, 422, "2.8 422: $label");
                        $t->true(!array_key_exists('result', $r[1] ?? []), "2.8 $label: tanpa result");
                    }
                    $same('2.8 (422)');

                    // 422 mendahului 400 (id folders tidak ada) dan 441/411
                    $r = q9_put($t, $s, $b[0], r3_body([$bad], [r3_fl(Q9_RANDOM_ID, 1)]));
                    $t->status($r, 422, '2.9 folders id tidak ada + permissions tidak sah: 422 mendahului 404 ARCHIVE400');
                    $same('2.9');

                    // 404 ARCHIVE400: dokumen, tidak ada, nonaktif, juga bersama folder sah
                    foreach (['dokumen DP' => $f['DP'], 'tidak ada' => Q9_RANDOM_ID, 'nonaktif PX' => $f['PX']] as $label => $id) {
                        $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($id, 1)]));
                        q9_deny($t, $r, 404, 'ARCHIVE400', "2.10 folders [$label]");
                        $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($F, 1), r3_fl($id, 1)]));
                        q9_deny($t, $r, 404, 'ARCHIVE400', "2.10 folders [F:1, $label]: F tidak ikut tersimpan");
                        $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($id, 1)], [r3_fl($F, 1)]));
                        q9_deny($t, $r, 404, 'ARCHIVE400', "2.10 permissions [$label] + folders [F:1]");
                    }
                    $same('2.10 (404 ARCHIVE400)');

                    // 400 mendahului 441: folders id tidak ada + permissions baris 441
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($f['C2'], 1)], [r3_fl(Q9_RANDOM_ID, 1)]));
                    q9_deny($t, $r, 404, 'ARCHIVE400', '2.11 folders id tidak ada + permissions [C2:View] (441): 400 dulu');
                    $same('2.11');

                    // 441 mendahului 411
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($f['C2'], 1), q9_row_body($f['P'], 0, 1)], [r3_fl($F, 1)]));
                    q9_deny($t, $r, 400, 'ARCHIVE441', '2.12 [C2:View] (441, tetap Off) + [P:U tanpa V] (411) + folders [F:1]: 441 dulu', q9_row($t, $f['C2'])['name']);
                    $same('2.12');

                    // 411 sendirian: flag dan baris otomatis sudah ditulis sebelum 411 -> harus dibatalkan seluruhnya
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($F, 0, 1)], [r3_fl($F, 1)]));
                    q9_deny($t, $r, 400, 'ARCHIVE411', '2.13 folders [F:1] + [F:U tanpa V]');
                    $t->eq(r3_flag($t, $F), 0, '2.13 F tetap Off (flag ikut dibatalkan)');
                    $t->eq(r3_total_rows($t, $F), 0, '2.13 tanpa baris (F,A) otomatis dan tanpa (F,B)');
                    $same('2.13 (411 di tengah transaksi, rollback penuh)');
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($F, 1), q9_row_body($K, 0, 0, 1)], [r3_fl($F, 1), r3_fl($K, 1)]));
                    q9_deny($t, $r, 400, 'ARCHIVE411', '2.14 folders [F:1, K:1] + [F:View sah, K:D tanpa V]');
                    $same('2.14 (F sah tidak tersimpan, K tidak menyala)');
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($f['P'], 1, 1), q9_row_body($F, 1, 1, 0, 0), q9_row_body($K, 0, 0, 0, 1)], [r3_fl($K, 1)]));
                    q9_deny($t, $r, 400, 'ARCHIVE441', '2.15 [P sah, F (tetap Off), K:S tanpa V (411)] + folders [K:1]: 441 pada F lebih dulu dari 411 pada K', q9_row($t, $F)['name']);
                    $same('2.15');

                    // 441 + folders sah lain
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($f['C2'], 1)], [r3_fl($F, 1)]));
                    q9_deny($t, $r, 400, 'ARCHIVE441', '2.16 hak C2 (tetap Off) + folders [F:1]', q9_row($t, $f['C2'])['name']);
                    $same('2.16');

                    // user target nonaktif = 404 ARCHIVE440 walau folders sah
                    q9_set_user($t, $b[0], ['is_active' => 0]);
                    try {
                        $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($F, 1)]));
                        q9_deny($t, $r, 404, 'ARCHIVE440', '2.17 target nonaktif + folders [F:1]');
                    } finally {
                        q9_set_user($t, $b[0], ['is_active' => 1]);
                    }
                    $same('2.17');
                });

                // ---- 3. kiriman sah yang sering salah dibaca (A tanpa View, di bawah induk di luar lokasi, kosong)
                q9_with_user($t, r3_ctx_a($roles), function ($set) use ($t, $s, $me, $b, $f) {
                    $JB = $f['JB'];
                    $JC = $f['JC'];
                    $LC = $f['LC'];

                    $t->eq(r3_vec($t, $JB, $me), 'none', '3. awal: A tanpa baris di JB (tanpa View)');
                    $h0 = q9_hist_count($t, $JB);
                    $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($JB, 1)]));
                    r3_ok($t, $r, $b[0], '3.1 folders [JB:1] (A tanpa View, sudah On)');
                    $t->eq(q9_hist_count($t, $JB), $h0, '3.1 sudah On: tanpa riwayat');
                    $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($JB, 0)]));
                    r3_ok($t, $r, $b[0], '3.2 folders [JB:0] (A tanpa View)');
                    $t->eq(r3_flag($t, $JB), 0, '3.2 JB Off');
                    $t->eq(r3_total_rows($t, $JB), 0, '3.2 tanpa baris otomatis saat mati');
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($JB, 1)], [r3_fl($JB, 1)]));
                    r3_ok($t, $r, $b[0], '3.3 folders [JB:1] + [JB:View] (A tanpa View)');
                    $t->eq(r3_flag($t, $JB), 1, '3.3 JB On');
                    $t->eq(r3_vec($t, $JB, $me), '[1,1,1,1]', '3.3 (JB,A) otomatis 1111');
                    $t->eq(r3_vec($t, $JB, $b[0]), '[1,0,0,0]', '3.3 (JB,B) View');
                    $node = r3_node($t, $s, $b[0], $JB);
                    $t->eq($node['access']['manage_permission'], true, '3.3 manage_permission true');

                    $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($JC, 1)]));
                    r3_ok($t, $r, $b[0], '3.4 folders [JC:1] (Off, A tanpa View)');
                    $t->eq(r3_flag($t, $JC), 1, '3.4 JC On');
                    $t->eq(r3_vec($t, $JC, $me), '[1,1,1,1]', '3.4 (JC,A) otomatis 1111');

                    $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($LC, 0)]));
                    r3_ok($t, $r, $b[0], '3.5 folders [LC:0] (induk L di luar lokasi, LC dalam scope)');
                    $t->eq(r3_flag($t, $LC), 0, '3.5 LC Off');

                    $before = q9_snap($t, array_values($f));
                    $pt = q9_perm_total($t);
                    $r = q9_put($t, $s, $b[0], r3_body([], []));
                    r3_ok($t, $r, $b[0], '3.6 folders [] + permissions []');
                    r3_unchanged($t, array_values($f), $before, $pt, '3.6');
                    $r = q9_put($t, $s, $b[0], ['permissions' => []]);
                    r3_ok($t, $r, $b[0], '3.7 tanpa kunci folders (perilaku lama)');
                    r3_unchanged($t, array_values($f), $before, $pt, '3.7');
                });

                // ---- 3b. M (List Archive, View Folder, Folder Permissions; tanpa Update Folder) boleh menyalakan/mematikan (izin = Folder Permissions saja)
                q9_with_user($t, ['role' => $roles['M'], 'emp' => ['SMR']], function ($set) use ($t, $s, $me, $meName, $b, $f) {
                    $K = $f['K'];
                    $h0 = q9_hist_count($t, $K);
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($K, 1)], [r3_fl($K, 1)]));
                    r3_ok($t, $r, $b[0], '3b. profil M: folders [K:1] + [K:View]');
                    $t->eq(r3_flag($t, $K), 1, '3b. K On');
                    $t->eq(q9_row($t, $K)['updated_by'], $meName, '3b. updated_by = user login');
                    $t->eq(r3_vec($t, $K, $me), '[1,1,1,1]', '3b. hak otomatis atas nama user login (M)');
                    $t->eq(q9_hist_count($t, $K), $h0 + 1, '3b. riwayat +1');
                    $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($K, 0)]));
                    r3_ok($t, $r, $b[0], '3b. profil M: folders [K:0]');
                    $t->eq(r3_flag($t, $K), 0, '3b. K Off');
                });

                // ---- 4. superadmin 1 dan 2: folders [L:1] (di luar lokasi) = 200, baris otomatis atas nama superadmin
                foreach ([1, 2] as $role) {
                    q9_with_user($t, ['role' => $role, 'emp' => ['SMR']], function ($set) use ($t, $s, $me, $meName, $b, $L, $role) {
                        r3_reset($t, $L, 0);
                        $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($L, 1)]));
                        r3_ok($t, $r, $b[0], "4. superadmin $role: folders [L:1]");
                        $t->eq(r3_flag($t, $L), 1, "4. superadmin $role: L On");
                        $t->eq(r3_vec($t, $L, $me), '[1,1,1,1]', "4. superadmin $role: (L, superadmin) 1111");
                        $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($L, 1, 1)], [r3_fl($L, 0)]));
                        r3_ok($t, $r, $b[0], "4. superadmin $role: folders [L:0] + [L:V+U] (K-10 b)");
                        $t->eq(r3_flag($t, $L), 0, "4. superadmin $role: L Off");
                        $t->eq(r3_vec($t, $L, $b[0]), '[1,1,0,0]', "4. superadmin $role: (L,B) V+U");
                        $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($L, 1, 1)]));
                        q9_deny($t, $r, 400, 'ARCHIVE441', "4. superadmin $role: hak L (tetap Off) tanpa folders", q9_row($t, $L)['name']);
                        r3_reset($t, $L, 0);
                    });
                }

                // ---- 5. nilai is_folder_permission selain 0/1 murni: karakterisasi (tidak 5xx; diterima hanya bila setara 0/1)
                q9_with_user($t, r3_ctx_a($roles), function ($set) use ($t, $s, $b, $f) {
                    $H = $f['H'];
                    $note = [];
                    foreach (['"1"' => '1', 'true' => true, '"01"' => '01', '1.0' => 1.0, '"0"' => '0'] as $label => $v) {
                        r3_reset($t, $H, $v === '0' ? 1 : 0);
                        $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($H, $v)]));
                        q9_no500($t, $r, "5. is_folder_permission $label");
                        $t->true(in_array($r[0], [200, 422]), "5. is_folder_permission $label: 200 atau 422 (" . $r[0] . ')');
                        if ($r[0] === 200) {
                            $t->eq(r3_flag($t, $H), $v === '0' ? 0 : 1, "5. is_folder_permission $label: diterima dan flag mengikuti nilainya");
                        } else {
                            $t->eq(r3_flag($t, $H), $v === '0' ? 1 : 0, "5. is_folder_permission $label: ditolak, flag tidak berubah");
                        }
                        $note[] = "$label=" . $r[0];
                    }
                    $t->note('bentuk nilai is_folder_permission: ' . implode(' ', $note));
                    r3_reset($t, $H, 0);
                });

                // ---- 6. fuzz id folders (TANPA karakter non-latin1; O-6): tidak 5xx, tidak ada yang tersimpan
                q9_with_user($t, r3_ctx_a($roles), function ($set) use ($t, $s, $b, $f, $ids) {
                    $pId = $f['F'];
                    $before = q9_snap($t, $ids);
                    $pt = q9_perm_total($t);
                    $odd = [
                        'spasi di belakang' => $pId . ' ',
                        'spasi di depan'    => ' ' . $pId,
                        'tab'               => $pId . "\t",
                        'newline'           => $pId . "\n",
                        'persen'            => '%',
                        'underscore'        => '_',
                        'SQL'               => "' OR '1'='1",
                        'SQL 2'             => "$pId' OR '1'='1",
                        'panjang 400'       => str_repeat('9', 400),
                        'null byte'         => $pId . "\0",
                        'unicode latin1'    => 'ünï',
                        'nol'               => '0',
                        'minus'             => '-1',
                        'titik'             => '.',
                        'kutip'             => '"',
                        'backslash'         => '\\',
                    ];
                    // TrimStrings (middleware) memangkas spasi/tab/newline/NUL di tepi: id seperti itu = id F yang sah (200), bukan 404
                    $trimmed = ['spasi di belakang', 'spasi di depan', 'tab', 'newline', 'null byte'];
                    $others = array_values(array_diff($ids, [$f['F']]));
                    $beforeOthers = q9_snap($t, $others);
                    $accepted = [];
                    foreach ($odd as $label => $id) {
                        foreach (['sendiri' => [r3_fl($id, 1)], 'bersama F sah' => [r3_fl($f['F'], 1), r3_fl($id, 1)]] as $mode => $fl) {
                            $r = q9_put($t, $s, $b[0], r3_body([], $fl));
                            q9_no500($t, $r, "6. folders id $label ($mode)");
                            if ($r[0] === 200 && in_array($label, $trimmed, true) && $mode === 'sendiri') {
                                // diterima sebagai F sendiri (id dinormalisasi middleware): hanya F yang berubah
                                $accepted[] = $label;
                                $t->eq(r3_flag($t, $f['F']), 1, "6. folders id $label ($mode): dinormalisasi menjadi F, flag 1");
                                $t->true(q9_same(q9_snap($t, $others), $beforeOthers), "6. folders id $label ($mode): folder lain tidak berubah");
                                r3_reset($t, $f['F'], 0);
                                $before = q9_snap($t, $ids);
                                $pt = q9_perm_total($t);
                                continue;
                            }
                            if ($r[0] === 200 && in_array($label, $trimmed, true)) {
                                $accepted[] = "$label/$mode";
                                r3_reset($t, $f['F'], 0);
                                $before = q9_snap($t, $ids);
                                $pt = q9_perm_total($t);
                                continue;
                            }
                            $t->true(in_array($r[0], [404, 422, 403]), "6. folders id $label ($mode): 404/422/403 (" . $r[0] . ' ' . (q9_code($r) ?? '') . ')');
                            $t->true(q9_same($before, q9_snap($t, $ids)) && q9_perm_total($t) === $pt, "6. folders id $label ($mode): tidak ada yang tersimpan");
                        }
                        // id yang sama di permissions bersama folders
                        $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($id, 1)], [r3_fl($f['F'], 1)]));
                        q9_no500($t, $r, "6. permissions+folders id $label");
                        if ($r[0] === 200) {
                            r3_reset($t, $f['F'], 0);
                            r3_clear_rows($t, $f['F']);
                            $before = q9_snap($t, $ids);
                            $pt = q9_perm_total($t);
                        } else {
                            $t->true(q9_same($before, q9_snap($t, $ids)), "6. permissions+folders id $label: tidak ada yang tersimpan");
                        }
                    }
                    $t->note('id yang dinormalisasi middleware (TrimStrings) jadi F sah (200): ' . implode(', ', array_unique($accepted)));
                });
                q9_assert_no408($t, 'AC-35');
            } finally {
                q9_purge($t);
                q9_role_purge($t);
            }
            r3_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-35b',
        'title' => '(rev3) QA_USER2 diberi role preset tanpa Folder Permissions (data developer F-1: role 5 memegangnya; dipulihkan persis): PUT {B} folders [F:1] = 403 GE0114 parameter "Folder Permissions", data tetap; dengan role asli (memegang Folder Permissions) PUT folders [F:1] = 200 dan baris otomatis atas nama QA_USER2 (pemegang izin yang menyalakan)',
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
            $adiName = $t->conf('QA_USER2');
            [$b] = q9_others($t, 1);
            if ($b[0] === $adi) {
                [, $b] = q9_others($t, 2);
            }
            $s2 = $t->login('QA_USER2');
            $bind = $t->bind($s2, q9_dbname($t));
            if ($bind[0] !== 200) {
                $t->blocked('QA_USER2 tidak bisa bind ke ' . q9_dbname($t) . ' (HTTP ' . $bind[0] . ')');
            }
            $u2snap = null;

            try {
                $roles = q9_roles_profile($t);
                $f = r3_fixture($t, $me, $meName, $b[0]);
                $ids = array_values($f);
                $F = $f['F'];

                // 1. role asli QA_USER2: bila memegang Folder Permissions, ia boleh menyalakan; baris otomatis = QA_USER2
                $has = in_array('Folder Permissions', $t->db()->table('role_permissions')
                    ->join('user_roles', 'user_roles.id_role', '=', 'role_permissions.id_role')
                    ->join('permissions', 'permissions.id_permission', '=', 'role_permissions.id_permission')
                    ->where('user_roles.id_user', $adi)->pluck('permissions.permission_name')->all(), true);
                if ($has) {
                    $h0 = q9_hist_count($t, $F);
                    $r = q9_put($t, $s2, $b[0], r3_body([q9_row_body($F, 1)], [r3_fl($F, 1)]));
                    r3_ok($t, $r, $b[0], '1. QA_USER2 (pemegang Folder Permissions) PUT {B} folders [F:1]');
                    $t->eq(r3_flag($t, $F), 1, '1. F On');
                    $t->eq(q9_row($t, $F)['updated_by'], $adiName, '1. updated_by = QA_USER2');
                    $t->eq(r3_vec($t, $F, $adi), '[1,1,1,1]', '1. baris otomatis atas nama QA_USER2 (pengaktif)');
                    $t->eq(r3_row_count($t, $F, $me), 0, '1. bukan atas nama QA_USER');
                    $t->eq(q9_hist_count($t, $F), $h0 + 1, '1. riwayat +1');
                    $hist = r3_last_hist($t, $F);
                    $t->eq($hist['related_username'] ?? null, $adiName, '1. riwayat oleh QA_USER2');
                    r3_reset($t, $F, 0);
                } else {
                    $t->note('role asli QA_USER2 tidak memegang Folder Permissions: cabang pemegang dilewati');
                }

                // 2. role preset tanpa Folder Permissions (+ Allow Access Website supaya token tidak dicabut middleware)
                $roles['DW'] = q9_role($t, 'DW', q9_perms_profile('DW'));
                $u2snap = q9_u2_swap($t, $adi, [$roles['DW']]);
                $before = q9_snap($t, $ids);
                $pt = q9_perm_total($t);
                foreach ([
                    'folders [F:1]'                      => r3_body([], [r3_fl($F, 1)]),
                    'folders [F:1] + permissions [F]'    => r3_body([q9_row_body($F, 1)], [r3_fl($F, 1)]),
                    'folders [L:1] (luar lokasi)'        => r3_body([], [r3_fl($f['L'], 1)]),
                    'folders tidak sah'                  => r3_body([], [r3_fl($F, 5)]),
                ] as $label => $body) {
                    $r = q9_put($t, $s2, $b[0], $body);
                    q9_deny($t, $r, 403, 'GE0114', "2. QA_USER2 tanpa Folder Permissions: $label");
                    $t->eq($r[1]['parameter'] ?? null, 'Folder Permissions', "2. $label: parameter");
                }
                r3_unchanged($t, $ids, $before, $pt, '2. QA_USER2 tanpa Folder Permissions');
                q9_assert_no408($t, 'AC-35b');
            } finally {
                if ($u2snap !== null) {
                    q9_u2_restore($t, $u2snap);
                }
                q9_purge($t);
                q9_role_purge($t);
            }
            r3_clean($t, $base);
        },
    ],
    [
        'id'    => 'X-8',
        'title' => '(rev3) banyak folder sekaligus: PUT {B} folders [120 folder Off -> On] + permissions [120] sekali kirim (atomik): < 20 detik, 120 flag, 120 baris otomatis A, 120 baris B, 120 riwayat (satu per folder); lalu folders [120 -> Off] semua mati, baris tetap; kiriman yang sama + 1 folder luar lokasi = 407 dan tidak ada yang berubah; superadmin target tidak 5xx',
        'run'   => function ($t) {
            q9_recover($t);
            q9_role_purge($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b] = q9_others($t, 1);
            $n = 120;

            try {
                $roles = q9_roles_profile($t);
                $parent = q9_folder($t, 'X8P', ['perm' => 0]);
                $kids = [];
                for ($i = 0; $i < $n; $i++) {
                    $kids[] = q9_folder($t, 'X8K' . $i, ['perm' => 0, 'parent' => $parent]);
                }
                $L = q9_folder($t, 'X8L', ['perm' => 0, 'all' => 0, 'locs' => ['MGL']]);
                $all = array_merge([$parent, $L], $kids);
                q9_codes_reset();

                q9_with_user($t, r3_ctx_a($roles), function ($set) use ($t, $s, $me, $b, $kids, $n, $L, $all) {
                    $flagsOn = [];
                    $flagsOff = [];
                    $perms = [];
                    foreach ($kids as $k) {
                        $flagsOn[] = r3_fl($k, 1);
                        $flagsOff[] = r3_fl($k, 0);
                        $perms[] = q9_row_body($k, 1, 1);
                    }
                    $h0 = [];
                    foreach ($kids as $k) {
                        $h0[$k] = q9_hist_count($t, $k);
                    }

                    // 407 karena satu folder luar lokasi: tidak ada yang berubah
                    $before = q9_snap($t, $all);
                    $pt = q9_perm_total($t);
                    $r = q9_put($t, $s, $b[0], r3_body($perms, array_merge($flagsOn, [r3_fl($L, 1)])));
                    q9_deny($t, $r, 403, 'ARCHIVE407', '1. 120 folders + 1 luar lokasi');
                    r3_unchanged($t, $all, $before, $pt, '1.');

                    $t0 = microtime(true);
                    $r = q9_put($t, $s, $b[0], r3_body($perms, $flagsOn));
                    $sec = microtime(true) - $t0;
                    r3_ok($t, $r, $b[0], '2. PUT 120 folders On + 120 hak');
                    $t->true($sec < 20, '2. selesai < 20 detik (' . round($sec, 2) . ' s)');
                    $on = $t->db()->table('archives')->whereIn('id_archive', $kids)->where('is_folder_permission', 1)->count();
                    $t->eq($on, $n, '2. 120 flag On');
                    $t->eq($t->db()->table('archive_permissions')->whereIn('id_archive', $kids)->where('id_user', $me)->where('is_view', 1)->where('is_update', 1)->where('is_delete', 1)->where('is_store', 1)->count(), $n, '2. 120 baris otomatis A 1111');
                    $t->eq($t->db()->table('archive_permissions')->whereIn('id_archive', $kids)->where('id_user', $b[0])->where('is_view', 1)->where('is_update', 1)->where('is_delete', 0)->where('is_store', 0)->count(), $n, '2. 120 baris B V+U');
                    $t->eq($t->db()->table('archive_permissions')->whereIn('id_archive', $kids)->count(), 2 * $n, '2. tepat 240 baris (tanpa baris ganda)');
                    $bad = 0;
                    foreach ($kids as $k) {
                        if (q9_hist_count($t, $k) !== $h0[$k] + 1) {
                            $bad++;
                        }
                    }
                    $t->eq($bad, 0, '2. tiap folder +1 riwayat permission');

                    $t0 = microtime(true);
                    $r = q9_put($t, $s, $b[0], r3_body([], $flagsOff));
                    $sec = microtime(true) - $t0;
                    r3_ok($t, $r, $b[0], '3. PUT 120 folders Off');
                    $t->true($sec < 20, '3. selesai < 20 detik (' . round($sec, 2) . ' s)');
                    $t->eq($t->db()->table('archives')->whereIn('id_archive', $kids)->where('is_folder_permission', 1)->count(), 0, '3. semua Off');
                    $t->eq($t->db()->table('archive_permissions')->whereIn('id_archive', $kids)->count(), 2 * $n, '3. baris tetap (240)');
                    $t->eq($t->db()->table('archive_permissions')->whereIn('id_archive', $kids)->where('id_user', $me)->where('is_update', 1)->where('is_delete', 1)->count(), $n, '3. baris A tetap 1111');

                    // GET: pohon dengan flag terbaru dalam waktu wajar
                    $t0 = microtime(true);
                    $g = q9_get($t, $s, $b[0]);
                    $t->status($g, 200, '4. GET sesudah');
                    $t->true(microtime(true) - $t0 < 10, '4. GET < 10 detik');
                });

                // target superadmin (user lain dengan role 1/2): tidak 5xx, baris otomatis tetap atas nama user login
                $sup = $t->db()->table('user_roles')->join('roles', 'roles.id_role', '=', 'user_roles.id_role')
                    ->join('users', 'users.id_user', '=', 'user_roles.id_user')
                    ->whereIn('roles.is_superadmin', [1, 2])->where('users.is_active', 1)->where('users.id_user', '!=', $me)
                    ->value('users.id_user');
                if ($sup) {
                    q9_with_user($t, r3_ctx_a($roles), function ($set) use ($t, $s, $me, $sup, $parent) {
                        $r = q9_put($t, $s, $sup, r3_body([q9_row_body($parent, 1)], [r3_fl($parent, 1)]));
                        r3_ok($t, $r, $sup, '5. target superadmin: folders [P:1] + [P:View]');
                        $t->eq(r3_vec($t, $parent, $sup), '[1,0,0,0]', '5. (P, superadmin target) View');
                        $t->eq(r3_vec($t, $parent, $me), '[1,1,1,1]', '5. (P, user login) otomatis');
                    });
                } else {
                    $t->note('tidak ada user superadmin aktif lain: target superadmin dilewati');
                }
                q9_assert_no408($t, 'X-8');
            } finally {
                q9_purge($t);
                q9_role_purge($t);
            }
            r3_clean($t, $base);
        },
    ],
];
