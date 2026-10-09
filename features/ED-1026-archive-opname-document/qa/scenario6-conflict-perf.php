<?php
/**
 * ED-1026 (rev: opname tanpa draft) - bentrok & hari lain & bahasa: AC-22 (bagian BE: 400 ARCHIVE414 pada Konfirmasi, kirim
 * ulang, urutan cek 422 -> 415 -> 416 -> 414), AC-28 (bagian BE: label root en/id di ARCHIVE414), AC-23 (ARCHIVE415 + hari lain).
 * Regresi, kinerja, konkurensi dan fuzz ada di scenario8-regress-perf.php.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-22',
        'title' => '(bagian BE) B memuat Step 1 (selected_at T), A mengonfirmasi folder yang sama sesudah T -> POST opnames B 400 ARCHIVE414 (status fail, msg_code, result.folders, parameter) tanpa sesi B; kirim ulang body sama = 400 ARCHIVE414 (satu sesi); folder tak beririsan tidak bentrok; root vs folder; urutan 415 -> 416 -> 414',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $marker = '2026-01-01 08:00:00';
                $uname = q26_uname($t);
                $seed = function (array $keys) use ($t, $x, $marker) {
                    foreach ($keys as $k) {
                        q26_set_archive($t, $x[$k], ['is_verified' => 1, 'verified_at' => $marker, 'verified_by' => 'QA26', 'id_archive_opname' => null]);
                    }
                };
                $N = function ($k) use ($t, $x) {
                    return q26_n($t, $x[$k]);
                };
                $snapshot = function () use ($t, $x) {
                    $rows = [];
                    foreach (['dF1', 'dF2', 'dJF', 'd11', 'dA1', 'd21'] as $k) {
                        $rows[$k] = q26_row($t, $x[$k]);
                    }

                    return json_encode([$rows, q26_state($t)]);
                };

                $seed(['dF1', 'd11', 'dA1']);
                // B memuat Step 1 untuk F[S1,S2] dan scan dF2; A (folder opname S1) dikonfirmasi sesudahnya
                $selB = q26_selection($x['F'], q26_sel([$x['S1'], $x['S2']]));
                $selectedB = q26_selected($t, $s, $x['F']);
                list($scansB) = q26_scan_all($t, $s, $selB, [$N('dF2')]);
                $a = q26_ok($t, $s, $x['S1'], [], null, [], 'A (S1) Konfirmasi');
                q26_set_owner($t, $a, 'otheruser');
                $aRow = q26_opname($t, $a);
                $afterA = ['d11' => q26_v($t, $x['d11']), 'dA1' => q26_v($t, $x['dA1'])];
                $before = $snapshot();

                $r = q26_store($t, $s, $selB, $scansB, $selectedB);
                $t->status($r, 400, 'B Konfirmasi bentrok');
                $t->eq($r[1]['status'] ?? null, 'fail', 'status fail (formatResponse false)');
                $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE414', 'msg_code ARCHIVE414');
                $t->true(!array_key_exists('code', $r[1]), 'bentuk formatResponse: tanpa kunci code (FE membaca msg_code)');
                $t->has($r[1], 'message', 'ada message');
                $t->true(strpos($r[1]['message'], $N('S1')) !== false, 'message memuat nama folder bentrok');
                $t->true(strpos($r[1]['message'], 'otheruser') !== false, 'message memuat user pengonfirmasi');
                $t->true(preg_match('/\d{2}:\d{2}/', $r[1]['message']) === 1, 'message memuat jam HH:mm');
                $t->true(strpos($r[1]['message'], 'sudah diopname oleh') !== false, 'message bahasa ID (user QA)');
                $folders = $r[1]['result']['folders'] ?? [];
                $t->eq(array_column($folders, 'id_archive'), [$x['S1']], 'result.folders = folder tercakup B yang dikonfirmasi A (A hanya mencakup S1), satu per folder');
                foreach ($folders as $f) {
                    $t->eq(array_keys($f), ['id_archive', 'name', 'created_by', 'confirmed_at'], 'kunci result.folders[]');
                    $t->eq([$f['created_by'], $f['confirmed_at']], ['otheruser', $aRow['confirmed_at']], 'result.folders: created_by & confirmed_at sesi A');
                    $t->eq($f['name'], $N('S1'), 'result.folders: name');
                }
                $t->true($before === $snapshot(), 'DB tidak berubah oleh Konfirmasi yang ditolak (archives, jumlah sesi, tabel opname)');
                $t->eq(q26_v($t, $x['d11']), $afterA['d11'], 'd11 tetap hasil A');
                // kirim ulang body yang sama: tetap 414, tetap tanpa sesi
                $r2 = q26_store($t, $s, $selB, $scansB, $selectedB);
                q26_deny($t, $r2, 400, 'ARCHIVE414', 'B kirim ulang (kedua)');
                $t->true($before === $snapshot(), 'kirim ulang B: DB tetap tidak berubah');
                $t->eq($t->db()->table('archive_opnames')->whereIn('id_archive_opname', q26_tracked())->count(), 1, 'hanya sesi A yang ada');

                // Step 1 sesudahnya: S1 "sudah diopname hari ini"
                $r = q26_folders($t, $s, $x['F']);
                $c1 = q26_child($r, $x['S1']);
                $t->true($c1['opnamed_today'] !== null && $c1['is_default_checked'] === false && $c1['opnamed_today']['id_archive_opname'] === $a, 'Step 1: S1 sudah diopname hari ini oleh A');
                $t->eq($c1['opnamed_today']['created_by'], 'otheruser', 'Step 1: opnamed_today.created_by');
                $t->true(q26_child($r, $x['S2'])['opnamed_today'] === null, 'Step 1: S2 belum diopname');

                // kembali ke Step 1 (selected_at baru, scan tetap, S1 lanjut) -> Konfirmasi berhasil
                $seed(['d11']);
                $selB2 = q26_selection($x['F'], q26_sel([$x['S1'] => true, $x['S2'] => false]));
                $selectedB2 = q26_selected($t, $s, $x['F']);
                $r = q26_store($t, $s, $selB2, $scansB, $selectedB2);
                $t->status($r, 200, 'B Konfirmasi sesudah pilih ulang (scan tetap)');
                $b = $r[1]['result']['id_archive_opname'];
                $t->eq(q26_opdocs($t, $b)[strtolower($N('dF2'))]['result'] ?? null, 1, 'scan dF2 tetap di sesi B');
                $t->eq(q26_v($t, $x['d11'])[0], 1, 'S1 lanjut: d11 (tak discan) tetap verified');
                // kirim ulang Konfirmasi yang sudah berhasil: 414 (tidak ada sesi ganda)
                $sessions = $t->db()->table('archive_opnames')->count();
                $r = q26_store($t, $s, $selB2, $scansB, $selectedB2);
                q26_deny($t, $r, 400, 'ARCHIVE414', 'kirim ulang Konfirmasi yang sudah berhasil');
                $t->eq($t->db()->table('archive_opnames')->count(), $sessions, 'kirim ulang: tidak ada sesi ganda');
                $r = q26_store($t, $s, $selB2, $scansB, $selectedB2);
                q26_deny($t, $r, 400, 'ARCHIVE414', 'kirim ulang Konfirmasi yang sudah berhasil (ketiga)');

                // folder tak beririsan: B2 (S2) vs A2 (S1) -> tidak bentrok
                $seed(['d21']);
                $sB2 = q26_selection($x['S2']);
                $selB2at = q26_selected($t, $s, $x['S2']);
                $a2 = q26_ok($t, $s, $x['S1'], [], true, [], 'A2 (S1)');
                $r = q26_store($t, $s, $sB2, [], $selB2at);
                $t->status($r, 200, 'B2 (S2) Konfirmasi: tidak beririsan dengan A2');

                // root[F] (dimuat sebelum A3) vs A3 (S1): S1 turunan F -> bentrok
                $selRoot = q26_selection(null, q26_sel([$x['F']]));
                $atRoot = q26_selected($t, $s, null);
                $a3 = q26_ok($t, $s, $x['S1'], [], null, [], 'A3 (S1)');
                $r = q26_store($t, $s, $selRoot, [], $atRoot);
                q26_deny($t, $r, 400, 'ARCHIVE414', 'root[F] vs S1: bentrok');
                $t->true(in_array($x['S1'], array_column($r[1]['result']['folders'] ?? [], 'id_archive'), true), 'root[F] vs S1: S1 ada di result.folders');

                // urutan cek: 422 -> 415 -> 416 -> 414 (semuanya saat ada bentrok)
                $yesterday = q26_now('-1 day')->format('Y-m-d') . ' 12:00:00';
                $selBad = q26_selection($x['F'], [['id_archive' => $x['S1a']]]);
                $r = q26_store($t, $s, $selBad, [], $selectedB);
                q26_deny($t, $r, 400, 'ARCHIVE416', 'urutan: subfolder bukan anak langsung + bentrok = 416 sebelum 414');
                $r = q26_store($t, $s, $selB, $scansB, $yesterday);
                q26_deny($t, $r, 400, 'ARCHIVE415', 'urutan: selected_at kemarin + bentrok = 415 sebelum 414');
                $r = $t->call($s, 'POST', Q26_BASE . '/opnames', $selB + ['scans' => $scansB]);
                $t->status($r, 422, 'urutan: tanpa selected_at + bentrok = 422 sebelum 414');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-28',
        'title' => '(bagian BE) Label root mengikuti bahasa user: ARCHIVE414 bentrok root -> [0] "Semua Arsip" (id) / "All Archive" (en), result.folders[0] id_archive & name null; folder biasa -> nama folder; pratinjau root: scope.folder_name & warnings root null (FE menerjemahkan)',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $marker = '2026-01-01 08:00:00';
                q26_set_archive($t, $x['dR'], ['is_verified' => 1, 'verified_at' => $marker, 'verified_by' => 'QA26']);
                $selRoot = q26_selection(null);
                $before = q26_state($t);

                // pratinjau root: tanpa teks bahasa; peringatan root (dR verified, tak discan) id_archive/name null
                $p = q26_preview($t, $s, $selRoot)[1]['result'];
                $t->eq($p['scope'], ['is_root' => true, 'folder_name' => null, 'subfolder_count' => 0], 'preview root: scope root');
                $t->true($p['id_archive'] === null && $p['folders'][0]['id_archive'] === null && $p['folders'][0]['name'] === null, 'preview root: folders[0] id_archive & name null');
                $w = array_values(array_filter($p['warnings'], function ($row) {
                    return $row['id_archive'] === null;
                }));
                $t->true(count($w) === 1 && $w[0]['name'] === null && $w[0]['unverify_count'] >= 1, 'preview root: warnings root id_archive/name null, unverify_count >= 1 (dR)');
                $t->true(q26_same_state($before, q26_state($t)), 'preview root tanpa tulis');

                // dua pemuat (id, en) memuat Step 1 root, lalu A mengonfirmasi root
                $atId = q26_selected($t, $s, null);
                $atEn = $atId;
                $a = q26_ok($t, $s, null, [], null, [], 'A root Konfirmasi');
                foreach (['ID' => ['Semua Arsip', 'All Archive', 'sudah diopname oleh'], 'EN' => ['All Archive', 'Semua Arsip', 'was opnamed by']] as $lang => $e) {
                    q26_with_user($t, ['lang' => $lang], function () use ($t, $s, $selRoot, $atId, $e, $lang) {
                        $r = q26_store($t, $s, $selRoot, [], $atId);
                        q26_deny($t, $r, 400, 'ARCHIVE414', "$lang: root vs root bentrok");
                        $msg = $r[1]['message'] ?? '';
                        $t->true(strpos($msg, '<b>' . $e[0] . '</b>') !== false, "$lang: [0] = '{$e[0]}' (tebal)");
                        $t->true(strpos($msg, $e[1]) === false, "$lang: tanpa label bahasa lain ('{$e[1]}')");
                        $t->true(strpos($msg, $e[2]) !== false, "$lang: teks pesan bahasa user");
                        $first = $r[1]['result']['folders'][0] ?? [];
                        $t->true(array_key_exists('id_archive', $first) && $first['id_archive'] === null && array_key_exists('name', $first) && $first['name'] === null, "$lang: result.folders[0] id_archive & name null (label root dibuat klien)");
                        $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE414', "$lang: msg_code");
                    });
                }

                // folder biasa: [0] = nama folder pada kedua bahasa
                $at = q26_selected($t, $s, $x['S2']);
                q26_ok($t, $s, $x['S2'], [], null, [], 'A S2');
                foreach (['ID', 'EN'] as $lang) {
                    q26_with_user($t, ['lang' => $lang], function () use ($t, $s, $x, $at, $lang) {
                        $r = q26_store($t, $s, q26_selection($x['S2']), [], $at);
                        q26_deny($t, $r, 400, 'ARCHIVE414', "$lang: folder bentrok");
                        $t->true(strpos($r[1]['message'], '<b>' . q26_n($t, $x['S2']) . '</b>') !== false, "$lang: [0] = nama folder");
                        $t->eq($r[1]['result']['folders'][0]['name'], q26_n($t, $x['S2']), "$lang: result.folders[0].name");
                    });
                }
                $t->true($a !== null, 'sesi A root tersimpan');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-23',
        'title' => 'selected_at atau satu scans.*.scanned_at kemarin/besok -> 400 ARCHIVE415 (DB tidak berubah); 00:00:00 hari ini lolos; sesi terkonfirmasi kemarin -> Step 1 "Belum diopname" & tercentang; batas tengah malam Asia/Jakarta; opname di hari baru = timpa',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $marker = '2026-01-01 08:00:00';
                $yesterday = q26_now('-1 day')->format('Y-m-d');
                $tomorrow = q26_now('+1 day')->format('Y-m-d');
                $today = q26_today();
                $todayStart = $today . ' 00:00:00';
                $N = function ($k) use ($t, $x) {
                    return q26_n($t, $x[$k]);
                };
                $sel = q26_selection($x['F'], q26_sel([$x['S1']]));
                $nowStr = q26_now()->format('Y-m-d H:i:s');
                $state = q26_state($t);
                $rowF1 = q26_row($t, $x['dF1']);

                // --- selected_at kemarin / besok -> 415, tanpa tulis
                foreach (["$yesterday 23:59:59" => 'kemarin 23:59:59', "$yesterday 00:00:00" => 'kemarin 00:00:00', "$tomorrow 00:00:00" => 'besok 00:00:00'] as $at => $label) {
                    $r = q26_store($t, $s, $sel, [['code' => $N('dF1'), 'scanned_at' => $nowStr]], $at);
                    q26_deny($t, $r, 400, 'ARCHIVE415', "selected_at $label");
                    $t->true(strpos($r[1]['message'] ?? '', 'hari lain') !== false, "selected_at $label: message ARCHIVE415 (ID)");
                }
                // --- satu scans.*.scanned_at kemarin / besok -> 415 (juga bila yang lain hari ini)
                $selected = q26_selected($t, $s, $x['F']);
                foreach (["$yesterday 23:59:59" => 'kemarin 23:59:59', "$tomorrow 00:00:00" => 'besok'] as $at => $label) {
                    $scans = [['code' => $N('dF1'), 'scanned_at' => $nowStr], ['code' => $N('dF2'), 'scanned_at' => $at], ['code' => $N('d11'), 'scanned_at' => $nowStr]];
                    $r = q26_store($t, $s, $sel, $scans, $selected);
                    q26_deny($t, $r, 400, 'ARCHIVE415', "scanned_at salah satu $label");
                }
                $t->true($state === q26_state($t) && json_encode($rowF1) === json_encode(q26_row($t, $x['dF1'])), 'DB tidak berubah oleh 415 (tabel opname, archives)');

                // --- batas: tepat 00:00:00 hari ini lolos (folder daun baru agar tak bentrok)
                $leaf = q26_folder($t, 'LEAF');
                $ld = q26_n($t, q26_doc($t, 'LD', ['parent' => $leaf]));
                $r = q26_store($t, $s, q26_selection($leaf), [['code' => $ld, 'scanned_at' => $todayStart]], $todayStart);
                $t->status($r, 200, 'selected_at & scanned_at tepat 00:00:00 hari ini -> Konfirmasi OK');
                $id = $r[1]['result']['id_archive_opname'];
                $o = q26_opname($t, $id);
                $t->eq([(int) $o['status'], $o['selected_at']], [2, $todayStart], 'DB: status 2, selected_at disimpan apa adanya');
                $t->true(strpos($t->db()->table('archive_opname_documents')->where('id_archive_opname', $id)->where('result', 1)->value('scanned_at'), $todayStart) === 0, 'DB: scanned_at 00:00:00 disimpan');

                // --- sesi terkonfirmasi kemarin: F belum diopname hari ini (diatur lewat DB)
                $id2 = q26_ok($t, $s, $x['F'], q26_sel([$x['S1']]), null, [], 'sesi F[S1]');
                q26_set_opname($t, $id2, ['confirmed_at' => "$yesterday 23:59:59", 'selected_at' => "$yesterday 23:50:00", 'created_at' => "$yesterday 23:50:00"]);
                $r = q26_folders($t, $s, $x['F']);
                $t->true($r[1]['result']['folder']['opnamed_today'] === null, 'konfirmasi kemarin 23:59:59: F belum diopname hari ini');
                foreach ([$x['S1'], $x['S2']] as $c) {
                    $row = q26_child($r, $c);
                    $t->true($row['opnamed_today'] === null && $row['is_default_checked'] === true, 'konfirmasi kemarin: anak belum diopname & tercentang');
                }
                q26_set_opname($t, $id2, ['confirmed_at' => $todayStart]);
                $r = q26_folders($t, $s, $x['F']);
                $t->true($r[1]['result']['folder']['opnamed_today'] !== null, 'konfirmasi tepat 00:00:00 hari ini: sudah diopname hari ini');
                $t->true(q26_child($r, $x['S1'])['is_default_checked'] === false, 'konfirmasi 00:00:00 hari ini: S1 tak tercentang');
                $t->eq($r[1]['result']['folder']['opnamed_today']['confirmed_at'], $todayStart, 'confirmed_at dilaporkan apa adanya');
                q26_set_opname($t, $id2, ['confirmed_at' => "$tomorrow 00:00:00"]);
                $r = q26_folders($t, $s, $x['F']);
                $t->true($r[1]['result']['folder']['opnamed_today'] === null, 'konfirmasi besok 00:00:00 bukan hari ini');

                // --- folder daun yang dikonfirmasi kemarin: Step 1 dilewati (belum diopname hari ini)
                $leaf2 = q26_ok($t, $s, $x['S2'], [], null, [], 'Konfirmasi S2 (daun)');
                $r = q26_folders($t, $s, $x['S2']);
                $t->true($r[1]['result']['skip_select_folder'] === false, 'S2 diopname hari ini: Step 1 tidak dilewati');
                q26_set_opname($t, $leaf2, ['confirmed_at' => "$yesterday 10:00:00", 'selected_at' => "$yesterday 09:50:00"]);
                $r = q26_folders($t, $s, $x['S2']);
                $t->true($r[1]['result']['folder']['opnamed_today'] === null && $r[1]['result']['skip_select_folder'] === true, 'S2 dikonfirmasi kemarin: belum diopname, Step 1 dilewati');

                // --- opname di hari baru = timpa (Lanjut diabaikan, tak discan -> unverified); kemarin tidak menimbulkan bentrok
                q26_set_archive($t, $x['d21'], ['is_verified' => 1, 'verified_at' => $marker, 'verified_by' => 'QA26', 'id_archive_opname' => null]);
                $selS2 = q26_selection($x['S2'], [], true);
                $p = q26_preview($t, $s, $selS2)[1]['result'];
                $t->eq([$p['folders'][0]['is_continue'], $p['folders'][0]['is_opnamed_today']], [false, false], 'hari baru: pratinjau is_continue false (belum diopname hari ini)');
                $o = q26_do($t, $s, $x['S2'], [], true, []);
                $t->status($o['r'], 200, 'Konfirmasi hari baru (kemarin tidak bentrok)');
                $n = $o['id'];
                $fol = q26_opfolders($t, $n);
                $t->eq([(int) $fol[0]['is_continue'], (int) $fol[0]['is_opnamed_today']], [0, 0], 'hari baru: is_continue disimpan false');
                $v = q26_v($t, $x['d21']);
                $t->true($v[0] === 0 && $v[3] === $n, 'hari baru: d21 (verified kemarin, tak discan) -> unverified');
                $t->eq((int) q26_opname($t, $n)['unverified_count'], 1, 'unverified_count 1');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'EXTRA-FUTURE',
        'title' => '(tambahan AC-22/AC-23, BR-23; fix D-1 rev 2026-10-09) selected_at sesudah waktu server (hari ini 23:59:59, +5 dtk, +1 jam) -> 400 ARCHIVE415 sebelum 416/414 (tidak meloloskan kiriman ulang maupun bentrok sesi user lain), tanpa tulis; urutan 422 > 415; selected_at = waktu server (batas) tetap lolos dan kiriman ulangnya 414 (satu sesi)',
        'run'   => function ($t) {
            $s = $t->session();
            if (q26_now()->format('H:i:s') >= '23:59:40') {
                $t->skip('terlalu dekat tengah malam Asia/Jakarta: nilai "masa depan hari ini" tak bisa dibentuk andal');
            }
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $leaf = q26_folder($t, 'LEAF');
                q26_doc($t, 'LD', ['parent' => $leaf]);
                $sel = q26_selection($leaf);
                $state = q26_state($t);
                $sessions = $t->db()->table('archive_opnames')->count();

                // 1. beberapa nilai masa depan (masih hari ini): 415, tanpa sesi, tanpa tulis
                $futures = [q26_today() . ' 23:59:59' => 'hari ini 23:59:59', q26_now('+5 seconds')->format('Y-m-d H:i:s') => '+5 dtk'];
                $plus1h = q26_now('+1 hour')->format('Y-m-d H:i:s');
                if (substr($plus1h, 0, 10) === q26_today()) {
                    $futures[$plus1h] = '+1 jam';
                }
                foreach ($futures as $at => $label) {
                    $r = q26_store($t, $s, $sel, [], $at);
                    q26_deny($t, $r, 400, 'ARCHIVE415', "selected_at masa depan ($label)");
                    $t->true(strpos($r[1]['message'] ?? '', 'hari lain') !== false, "selected_at masa depan ($label): message ARCHIVE415 (ID)");
                }
                $t->true($state === q26_state($t) && $t->db()->table('archive_opnames')->count() === $sessions, 'DB tidak berubah oleh 415 (tabel opname, archives)');

                // 2. dua kiriman identik berjarak > 1 dtk (reproduksi D-1): keduanya 415, nol sesi
                $future = q26_today() . ' 23:59:59';
                $r1 = q26_store($t, $s, $sel, [], $future);
                q26_no500($t, $r1, 'kiriman pertama selected_at masa depan');
                sleep(2);
                $r2 = q26_store($t, $s, $sel, [], $future);
                q26_no500($t, $r2, 'kiriman kedua (identik)');
                $ok = ($r1[0] === 200 ? 1 : 0) + ($r2[0] === 200 ? 1 : 0);
                $t->note('kiriman 1 HTTP ' . $r1[0] . ' ' . q26_code($r1) . '; kiriman 2 HTTP ' . $r2[0] . ' ' . q26_code($r2));
                $t->eq($t->db()->table('archive_opnames')->count() - $sessions, $ok, 'jumlah sesi baru = jumlah respons 200');
                $t->true($ok <= 1, "dua kiriman identik ber-selected_at masa depan menghasilkan paling banyak SATU sesi (BR-23 'tidak ada sesi ganda'; didapat $ok)");
                $t->eq($ok, 0, 'selected_at masa depan ditolak seluruhnya (415), bukan diterima sekali');

                // 3. tidak meloloskan bentrok sesi user lain: B memuat Step 1, A mengonfirmasi, B kirim selected_at masa depan
                $selB = q26_selection($x['F'], q26_sel([$x['S1'], $x['S2']]));
                $selectedB = q26_selected($t, $s, $x['F']);
                $a = q26_ok($t, $s, $x['S1'], [], null, [], 'A (S1) Konfirmasi');
                q26_set_owner($t, $a, 'otheruser');
                $before = q26_state($t);
                $r = q26_store($t, $s, $selB, [], q26_today() . ' 23:59:59');
                q26_deny($t, $r, 400, 'ARCHIVE415', 'bentrok + selected_at masa depan = 415 (tidak 200, tidak 414: urutan 415 sebelum 414)');
                $t->true($before === q26_state($t), 'tanpa tulis oleh penolakan');
                // nilai yang sah (dari server) tetap mendapat 414
                $r = q26_store($t, $s, $selB, [], $selectedB);
                q26_deny($t, $r, 400, 'ARCHIVE414', 'selected_at sah (Step 1 sebelum A) tetap 414');

                // 4. urutan: 422 sebelum 415; 415 sebelum 416 (subfolder bukan anak langsung)
                $r = q26_store($t, $s, $sel, [['code' => '', 'scanned_at' => q26_now()->format('Y-m-d H:i:s')]], $future);
                $t->status($r, 422, 'urutan: scans.0.code kosong + selected_at masa depan = 422 sebelum 415');
                $r = q26_store($t, $s, $sel, [], 'bukan-tanggal');
                $t->status($r, 422, 'urutan: selected_at bukan tanggal = 422');
                $selBad = q26_selection($x['F'], [['id_archive' => $x['S1a']]]);
                $r = q26_store($t, $s, $selBad, [], $future);
                q26_deny($t, $r, 400, 'ARCHIVE415', 'urutan: subfolder bukan anak langsung + selected_at masa depan = 415 sebelum 416');
                $r = q26_store($t, $s, $selBad, [], q26_selected($t, $s, $x['F']));
                q26_deny($t, $r, 400, 'ARCHIVE416', 'kontrol: subfolder bukan anak langsung + selected_at sah = 416');

                // 5. batas: selected_at = waktu server tepat (diambil dari GET opnames/folders) lolos; kirim ulang = 414, satu sesi
                $at = q26_selected($t, $s, $leaf);
                $r = q26_store($t, $s, $sel, [], $at);
                $t->status($r, 200, 'selected_at = waktu server (batas, tidak sesudah sekarang) lolos');
                $id = $r[1]['result']['id_archive_opname'] ?? null;
                $t->eq(q26_opname($t, $id)['selected_at'] ?? null, $at, 'selected_at disimpan apa adanya');
                $n = $t->db()->table('archive_opnames')->count();
                foreach ([1, 2] as $i) {
                    sleep($i);
                    $r = q26_store($t, $s, $sel, [], $at);
                    q26_deny($t, $r, 400, 'ARCHIVE414', "kirim ulang selected_at sah ke-$i (jeda $i dtk)");
                }
                $t->eq($t->db()->table('archive_opnames')->count(), $n, 'kirim ulang: tidak ada sesi ganda');

                // 6. scans.*.scanned_at masa depan hari ini: tidak ada aturan spec (K-15 a: format + hari ini); dicatat, tanpa 5xx
                $at2 = q26_selected($t, $s, $x['S2']);
                $r = q26_store($t, $s, q26_selection($x['S2']), [['code' => q26_n($t, $x['d21']), 'scanned_at' => q26_today() . ' 23:59:59']], $at2);
                q26_no500($t, $r, 'scanned_at masa depan hari ini');
                $t->note('scanned_at hari ini 23:59:59 (masa depan): HTTP ' . $r[0] . ' ' . q26_code($r) . ' (tidak diatur spec; K-15 a)');
            } finally {
                q26_cleanup($t);
            }
        },
    ],
];
