<?php
/**
 * ED-1029 rev3 - AC-37 (konkurensi): PUT user-permissions/{id} dengan `folders` (flag + hak otomatis pengaktif K-9 a) PARALEL oleh dua
 * pengguna nyata (A = QA_USER dengan role fixture, QA_USER2 = adi dengan role aslinya yang memegang Folder Permissions) dan bersama
 * PUT archives/{F} (tab Izin, method flag bersama EPIC K-5 b). Semua lewat lockFolders (id gabungan permissions + folders).
 *
 * Lulus tiap putaran = 0 x 5xx; hasil akhir (flag + SEMUA baris folder) DAN status tiap request sama dengan hasil SALAH SATU urutan serial
 * (model r3_sim_apply di qa_lib_r3.php, semua permutasi); tepat satu baris per (folder, user) (UNIQUE); riwayat valid dan <= jumlah request.
 * Putaran stres (banyak request, tanpa model penuh): invarian saja. php-fpm pm.max_children = 5: paralel efektif 5.
 */
require_once __DIR__ . '/qa_lib_r3.php';

return [

    [
        'id'    => 'AC-37',
        'title' => '(rev3) PUT paralel folders [F:1] oleh dua user (A dan QA_USER2) dan bersama PUT archives/{F} (tab Izin): semua 200 (441 hanya bila urutan serial memungkinkan), 0 x 5xx, tanpa baris ganda (hak otomatis hanya untuk pengaktif pertama), hasil akhir + status tiap request = SATU urutan serial (model semua permutasi); folder berlawanan urutan tidak deadlock; putaran stres 40 request',
        'run'   => function ($t) {
            q9_recover($t);
            q9_role_purge($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            $adi = $t->db()->table('users')->where('username', $t->conf('QA_USER2'))->value('id_user');
            $others = q9_others($t, 3);
            $pick = [];
            foreach ($others as $o) {
                if ($o[0] !== $adi) {
                    $pick[] = $o[0];
                }
            }
            [$b, $b2] = $pick;
            $stat = ['rounds' => 0, 'req' => 0, '5xx' => 0, 'max_s' => 0.0, '441' => 0];
            $twoUsers = false;
            $s2 = null;
            if ($adi) {
                $s2 = $t->login('QA_USER2');
                $bind = $t->bind($s2, q9_dbname($t));
                $twoUsers = $bind[0] === 200;
            }

            try {
                $roles = q9_roles_profile($t);
                $F1 = q9_folder($t, 'C1', ['perm' => 0]);
                $F2 = q9_folder($t, 'C2', ['perm' => 0]);
                $F3 = q9_folder($t, 'C3', ['perm' => 0]);
                q9_codes_reset();

                q9_with_user($t, r3_ctx_a($roles), function ($set) use ($t, $s, $s2, $me, $adi, $b, $b2, $F1, $F2, $F3, &$stat, &$twoUsers) {
                    $who = ['A' => [$s, $me]];
                    if ($twoUsers) {
                        // pra-syarat: QA_USER2 benar-benar boleh mengelola (role aslinya memegang Folder Permissions, data developer F-1)
                        $r = q9_put($t, $s2, $b, r3_body([], []));
                        if ($r[0] === 200) {
                            $who['ADI'] = [$s2, $adi];
                        } else {
                            $twoUsers = false;
                            $t->note('QA_USER2 tidak boleh mengelola (HTTP ' . $r[0] . ' ' . (q9_code($r) ?? '') . '): dua-user diganti dua token A yang sama');
                        }
                    }
                    if (!isset($who['ADI'])) {
                        $who['ADI'] = [$s, $me];
                    }
                    $uid = ['A' => $me, 'ADI' => $who['ADI'][1], 'B' => $b, 'B2' => $b2];
                    $V = [1, 0, 0, 0];

                    // spesifikasi request simbolik -> model (id user nyata)
                    $up = function ($login, $target, array $ops) use ($uid) {
                        return ['kind' => 'up', 'login' => $uid[$login], 'target' => $uid[$target], 'ops' => $ops];
                    };
                    $tab = function ($login, $folder, $flag, $rows) use ($uid) {
                        $r = null;
                        if ($rows !== null) {
                            $r = [];
                            foreach ($rows as $u => $v) {
                                $r[$uid[$u]] = $v;
                            }
                        }

                        return ['kind' => 'tab', 'login' => $uid[$login], 'folder' => $folder, 'flag' => $flag, 'rows' => $r];
                    };
                    $o = function ($flag, $perm) {
                        return ['flag' => $flag, 'perm' => $perm];
                    };
                    $who2 = [];
                    foreach (['A', 'ADI'] as $k) {
                        $who2[$uid[$k]] = $who[$k];
                    }

                    $rounds = [
                        'R1 dua user, folders [F:1] + View identik (3 x A, 3 x QA_USER2)' => [[$F1], [
                            $up('A', 'B', [$F1 => $o(1, $V)]), $up('A', 'B', [$F1 => $o(1, $V)]), $up('A', 'B', [$F1 => $o(1, $V)]),
                            $up('ADI', 'B', [$F1 => $o(1, $V)]), $up('ADI', 'B', [$F1 => $o(1, $V)]), $up('ADI', 'B', [$F1 => $o(1, $V)]),
                        ]],
                        'R2 dua user, hak berbeda untuk dua target' => [[$F1], [
                            $up('A', 'B', [$F1 => $o(1, [1, 1, 0, 0])]), $up('ADI', 'B', [$F1 => $o(1, [1, 0, 0, 1])]),
                            $up('A', 'B2', [$F1 => $o(1, $V)]), $up('ADI', 'B2', [$F1 => $o(1, [1, 0, 1, 0])]),
                        ]],
                        'R3 bersama PUT archives/{F} (tab Izin ganti-semua)' => [[$F1], [
                            $up('A', 'B', [$F1 => $o(1, $V)]), $tab('ADI', $F1, 0, ['B' => [1, 1, 0, 0]]),
                            $tab('A', $F1, 1, ['B' => $V, 'B2' => $V]), $up('ADI', 'B2', [$F1 => $o(1, $V)]),
                            $tab('A', $F1, null, ['B2' => [1, 1, 1, 1]]), $up('ADI', 'B', [$F1 => $o(null, [1, 0, 0, 1])]),
                        ]],
                        'R4 saklar dibalik bolak-balik (441 mungkin bergantung urutan)' => [[$F1], [
                            $up('A', 'B', [$F1 => $o(1, [1, 0, 0, 0])]), $up('ADI', 'B', [$F1 => $o(0, [1, 1, 0, 0])]),
                            $up('A', 'B2', [$F1 => $o(0, null)]), $up('ADI', 'B2', [$F1 => $o(1, null)]),
                            $up('A', 'B', [$F1 => $o(1, null)]), $tab('ADI', $F1, 0, null),
                        ]],
                        'R5 dua folder, urutan berlawanan (deadlock), dua user' => [[$F1, $F2], [
                            $up('A', 'B', [$F1 => $o(1, $V), $F2 => $o(1, $V)]), $up('ADI', 'B', [$F2 => $o(1, [1, 1, 0, 0]), $F1 => $o(1, [1, 1, 0, 0])]),
                            $up('A', 'B2', [$F2 => $o(1, [1, 0, 0, 1]), $F1 => $o(1, [1, 0, 0, 1])]), $up('ADI', 'B2', [$F1 => $o(1, [1, 0, 1, 0]), $F2 => $o(1, [1, 0, 1, 0])]),
                            $tab('A', $F2, 0, ['B' => [1, 1, 1, 1]]), $up('ADI', 'B', [$F1 => $o(0, null), $F2 => $o(1, null)]),
                        ]],
                        'R6 tiga folder, hak otomatis berganti pengaktif' => [[$F1, $F2, $F3], [
                            $up('A', 'B', [$F3 => $o(1, $V), $F1 => $o(1, null)]), $up('ADI', 'B', [$F1 => $o(1, $V), $F2 => $o(1, null)]),
                            $up('A', 'B2', [$F2 => $o(1, $V), $F3 => $o(1, null)]), $up('ADI', 'B2', [$F3 => $o(1, [1, 1, 0, 0]), $F2 => $o(1, [1, 1, 0, 0])]),
                        ]],
                    ];

                    $perRound = 3;
                    foreach ($rounds as $label => [$folders, $reqs]) {
                        $outcomes = null;
                        for ($i = 0; $i < $perRound; $i++) {
                            foreach ($folders as $fid) {
                                r3_reset($t, $fid, 0);
                            }
                            $h0 = [];
                            foreach ($folders as $fid) {
                                $h0[$fid] = q9_hist_count($t, $fid);
                            }
                            $initial = r3_db_state($t, $folders);
                            if ($outcomes === null) {
                                $outcomes = r3_sim_outcomes($initial, $reqs);
                                $t->note(substr($label, 0, 2) . ': ' . count($outcomes) . ' hasil serial yang sah');
                            }
                            // urutan kirim diacak per putaran (ketiga putaran berbeda)
                            $order = range(0, count($reqs) - 1);
                            if ($i > 0) {
                                mt_srand(1000 * $i + count($reqs));
                                shuffle($order);
                            }
                            $batch = [];
                            foreach ($order as $k) {
                                $batch[] = r3_req_http($reqs[$k], $who2);
                            }
                            $res = r3_par($t, $batch);
                            $byReq = [];
                            foreach ($order as $pos => $k) {
                                $byReq[$k] = $res[$pos];
                            }
                            ksort($byReq);

                            $stat['rounds']++;
                            $statuses = [];
                            foreach ($byReq as $k => $x) {
                                $stat['req']++;
                                $stat['max_s'] = max($stat['max_s'], $x[2]);
                                if ($x[0] >= 500) {
                                    $stat['5xx']++;
                                    $t->fail("$label #$i req $k: HTTP {$x[0]} " . substr(json_encode($x[1]), 0, 300));
                                }
                                if ($x[0] === 400) {
                                    $stat['441']++;
                                }
                                $statuses[$k] = $x[0];
                            }
                            $final = r3_db_state($t, $folders);
                            $key = json_encode($final) . '|' . json_encode(array_values($statuses));
                            if (strpos($label, 'R1') === 0) {
                                $winner = [];
                                foreach ($final[$folders[0]]['rows'] as $u => $v) {
                                    if ($u === $me) {
                                        $winner[] = 'A';
                                    } elseif ($u === $uid['ADI']) {
                                        $winner[] = 'QA_USER2';
                                    }
                                }
                                $t->note('R1 putaran ' . $i . ': baris otomatis milik ' . implode('+', $winner));
                            }
                            $t->true(isset($outcomes[$key]), "$label (putaran $i): keadaan akhir + status sama dengan salah satu urutan serial");
                            if (!isset($outcomes[$key])) {
                                $t->note("$label #$i: akhir=" . json_encode($final) . ' status=' . json_encode(array_values($statuses)));
                            }
                            foreach ($folders as $fid) {
                                $total = r3_total_rows($t, $fid);
                                $distinct = $t->db()->table('archive_permissions')->where('id_archive', $fid)->distinct()->count('id_user');
                                $t->eq($total, $distinct, "$label (putaran $i): tanpa baris ganda per (folder, user) di " . substr($fid, -4));
                                $raw = $t->db()->table('archives')->where('id_archive', $fid)->value('history');
                                $t->true($raw === null || is_array(json_decode($raw, true)), "$label (putaran $i): riwayat JSON valid");
                                $delta = q9_hist_count($t, $fid) - $h0[$fid];
                                $t->true($delta >= 0 && $delta <= count($reqs), "$label (putaran $i): delta riwayat $delta dalam 0.." . count($reqs));
                            }
                        }
                    }

                    // ---- putaran stres: 40 request campuran pada F1/F2 (invarian saja)
                    foreach ([$F1, $F2] as $fid) {
                        r3_reset($t, $fid, 0);
                    }
                    $h0 = [$F1 => q9_hist_count($t, $F1), $F2 => q9_hist_count($t, $F2)];
                    mt_srand(2937);
                    $users = ['A', 'ADI'];
                    $targets = ['B', 'B2'];
                    $perms = [null, [1, 0, 0, 0], [1, 1, 0, 0], [1, 0, 0, 1], [0, 0, 0, 0]];
                    $batch = [];
                    for ($i = 0; $i < 40; $i++) {
                        $kind = mt_rand(0, 4);
                        $login = $users[mt_rand(0, 1)];
                        $fid = mt_rand(0, 1) ? $F1 : $F2;
                        if ($kind === 0) {
                            $req = $tab($login, $fid, mt_rand(0, 1), mt_rand(0, 1) ? ['B' => $V] : null);
                        } else {
                            $ops = [$fid => $o(mt_rand(0, 2) === 0 ? null : mt_rand(0, 1), $perms[mt_rand(0, 4)])];
                            if (mt_rand(0, 3) === 0) {
                                $other = $fid === $F1 ? $F2 : $F1;
                                $ops[$other] = $o(mt_rand(0, 1), $perms[mt_rand(0, 4)]);
                            }
                            $req = $up($login, $targets[mt_rand(0, 1)], $ops);
                        }
                        $batch[] = r3_req_http($req, $who2);
                    }
                    $res = r3_par($t, $batch);
                    $codes = [];
                    foreach ($res as $x) {
                        $stat['req']++;
                        $stat['max_s'] = max($stat['max_s'], $x[2]);
                        if ($x[0] >= 500) {
                            $stat['5xx']++;
                        }
                        $codes[$x[0] . ' ' . ($x[1]['msg_code'] ?? $x[1]['code'] ?? '?')] = ($codes[$x[0] . ' ' . ($x[1]['msg_code'] ?? $x[1]['code'] ?? '?')] ?? 0) + 1;
                    }
                    $stat['rounds']++;
                    $t->eq($stat['5xx'], 0, 'stres: 0 x 5xx (kumulatif)');
                    $t->true(!array_diff(array_keys($codes), ['200 ARCHIVE207', '400 ARCHIVE441']), 'stres: hanya 200 ARCHIVE207 / 400 ARCHIVE441: ' . json_encode($codes));
                    foreach ([$F1, $F2] as $fid) {
                        $total = r3_total_rows($t, $fid);
                        $distinct = $t->db()->table('archive_permissions')->where('id_archive', $fid)->distinct()->count('id_user');
                        $t->eq($total, $distinct, 'stres: tanpa baris ganda');
                        $t->true(in_array(r3_flag($t, $fid), [0, 1], true), 'stres: flag 0/1');
                        $raw = $t->db()->table('archives')->where('id_archive', $fid)->value('history');
                        $t->true($raw === null || is_array(json_decode($raw, true)), 'stres: riwayat JSON valid');
                        $t->true(q9_hist_count($t, $fid) - $h0[$fid] <= 40, 'stres: riwayat <= 40 entri baru');
                        foreach (r3_rows($t, $fid) as $u => $v) {
                            $t->true(in_array($u, [$me, $who['ADI'][1], $b, $b2], true), 'stres: baris hanya untuk user uji');
                        }
                    }
                    $t->note('stres 40 request: ' . json_encode($codes));
                });
                $t->note('putaran ' . $stat['rounds'] . ', request ' . $stat['req'] . ', 5xx ' . $stat['5xx'] . ', 400/441 ' . $stat['441'] . ', terlama ' . round($stat['max_s'], 2) . ' s; dua user nyata: ' . ($twoUsers ? 'ya' : 'TIDAK (QA_USER2 tidak tersedia)'));
                q9_assert_no408($t, 'AC-37');
            } finally {
                q9_purge($t);
                q9_role_purge($t);
            }
            $t->eq($stat['5xx'], 0, '0 x 5xx');
            r3_clean($t, $base);
        },
    ],
    [
        'id'    => 'AC-37b',
        'title' => '(rev3, EPIC K-5 b) tulis flag lewat method bersama: PUT archives/{T} (tab Izin) {is_folder_permission:1} -> flag, updated_by = A, riwayat HANYA permission, tanpa baris otomatis dari BE (O-3: hanya di user-permissions); nilai sama = tanpa tulisan; detail (name) + flag dalam satu request -> keduanya berlaku, riwayat update + permission; flag + folder_permissions (ganti semua) -> satu entri permission, baris otomatis dari BE tidak ada; flag 0 + folder_permissions [] -> semua baris terhapus; hasil sama dengan PUT user-permissions untuk flag yang sama',
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
                $T = q9_folder($t, 'T', ['perm' => 0]);
                $T2 = q9_folder($t, 'T2', ['perm' => 0]);
                q9_codes_reset();
                $put = function ($id, array $body) use ($t, $s) {
                    return $t->call($s, 'PUT', 'api/v5/document-archive/archives/' . $id, $body);
                };

                q9_with_user($t, r3_ctx_a($roles), function ($set) use ($t, $s, $me, $meName, $b, $T, $T2, $put) {
                    $hp = q9_hist_count($t, $T);
                    $hu = q9_hist_count($t, $T, 'update');

                    // 1. izin saja: nyalakan
                    $r = $put($T, ['is_folder_permission' => 1]);
                    $t->status($r, 200, '1. PUT archives/{T} {is_folder_permission:1}');
                    $t->eq(r3_flag($t, $T), 1, '1. T On');
                    $t->eq(q9_row($t, $T)['updated_by'], $meName, '1. updated_by = A');
                    $t->eq(q9_hist_count($t, $T), $hp + 1, '1. riwayat permission +1');
                    $t->eq(q9_hist_count($t, $T, 'update'), $hu, '1. tanpa riwayat update');
                    $t->eq(r3_total_rows($t, $T), 0, '1. BE tidak menambah baris otomatis di PUT archives/{id} (O-3)');

                    // 2. nilai sama: tanpa tulisan
                    $snap = q9_snap($t, [$T]);
                    $r = $put($T, ['is_folder_permission' => 1]);
                    $t->status($r, 200, '2. nilai sama');
                    $t->true(q9_same($snap, q9_snap($t, [$T])), '2. tanpa tulisan, tanpa riwayat');

                    // 3. detail + flag dalam satu request (kelompok detail butuh Update efektif di T: A diberi View+Update lebih dulu)
                    q9_perm($t, $T, $me, 1, 1);
                    $newName = q9_name('T-baru');
                    $r = $put($T, ['name' => $newName, 'is_all_location' => 1, 'is_folder_permission' => 0]);
                    $t->status($r, 200, '3. detail + flag');
                    $row = q9_row($t, $T);
                    $t->eq($row['name'], $newName, '3. nama berubah');
                    $t->eq((int) $row['is_folder_permission'], 0, '3. flag 0');
                    $t->eq($row['updated_by'], $meName, '3. updated_by = A');
                    $t->eq(q9_hist_count($t, $T), $hp + 2, '3. riwayat permission +1');
                    $t->eq(q9_hist_count($t, $T, 'update'), $hu + 1, '3. riwayat update +1');

                    // 4. flag + folder_permissions (ganti semua): satu entri permission; baris persis kiriman
                    $r = $put($T, ['is_folder_permission' => 1, 'folder_permissions' => [
                        ['id_user' => $me, 'is_view' => 1, 'is_update' => 1, 'is_delete' => 0, 'is_store' => 1],
                        ['id_user' => $b[0], 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0],
                    ]]);
                    $t->status($r, 200, '4. flag + folder_permissions');
                    $t->eq(r3_flag($t, $T), 1, '4. T On');
                    $t->eq(r3_vec($t, $T, $me), '[1,1,0,1]', '4. (T,A) persis kiriman (tidak dinaikkan)');
                    $t->eq(r3_vec($t, $T, $b[0]), '[1,0,0,0]', '4. (T,B) View');
                    $t->eq(r3_total_rows($t, $T), 2, '4. tepat dua baris');
                    $t->eq(q9_hist_count($t, $T), $hp + 3, '4. riwayat permission +1 (satu entri)');

                    // 5. flag 0 + folder_permissions [] : semua baris terhapus (ganti-semua dengan kosong)
                    $r = $put($T, ['is_folder_permission' => 0, 'folder_permissions' => []]);
                    $t->status($r, 200, '5. flag 0 + folder_permissions []');
                    $t->eq(r3_flag($t, $T), 0, '5. T Off');
                    $t->eq(r3_total_rows($t, $T), 0, '5. semua baris terhapus');
                    $t->eq(q9_hist_count($t, $T), $hp + 4, '5. riwayat permission +1');

                    // 6. hasil flag sama dengan PUT user-permissions untuk flag yang sama (kolom yang ditulis identik)
                    $r = $put($T2, ['is_folder_permission' => 1]);
                    $t->status($r, 200, '6. PUT archives/{T2} flag 1');
                    $viaTab = q9_row($t, $T2);
                    $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($T, 1)]));
                    r3_ok($t, $r, $b[0], '6. PUT user-permissions folders [T:1]');
                    $viaPage = q9_row($t, $T);
                    $t->eq((int) $viaTab['is_folder_permission'], (int) $viaPage['is_folder_permission'], '6. flag sama');
                    $t->eq($viaTab['updated_by'], $viaPage['updated_by'], '6. updated_by sama (user login)');
                    $h1 = r3_last_hist($t, $T2);
                    $h2 = r3_last_hist($t, $T);
                    $t->eq([$h1['action'] ?? null, $h1['related_username'] ?? null], [$h2['action'] ?? null, $h2['related_username'] ?? null], '6. entri riwayat sama bentuk (permission, user login)');
                });
                q9_assert_no408($t, 'AC-37b');
            } finally {
                q9_purge($t);
                q9_role_purge($t);
            }
            r3_clean($t, $base);
        },
    ],
];
