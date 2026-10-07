<?php
/**
 * ED-1026 - konfirmasi opname: AC-18 (folder belum diopname hari ini: timpa), AC-19 (opname ulang hari sama: Lanjut / timpa),
 * AC-20 (subfolder tak dicentang & lokasi lain tak tersentuh; root Semarang vs lokasi lain), AC-21 (Not found & Invalid tak
 * mengubah archives), AC-25 (dokumen dipindah sesudah scan dinilai ulang saat Confirm).
 * Semua data uji dipulihkan di finally: q26_cleanup() mengembalikan kolom verifikasi archives ke default.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-18',
        'title' => 'Confirm folder belum diopname hari ini: discan -> is_verified=1 + verified_at/by + id_archive_opname; verified tak discan -> 0; archive_opnames status 2 + angka; folder level 0-2; baris result=4 per dokumen belum discan; dokumen Handed Over/Taken ikut dihitung',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $x['dHO'] = q26_doc($t, 'dHO', ['parent' => $x['F'], 'status' => 2]);
                $x['dTK'] = q26_doc($t, 'dTK', ['parent' => $x['F'], 'status' => 3]);
                $marker = '2026-01-01 08:00:00';
                foreach (['dF1', 'd11', 'dA1', 'd21', 'dj1', 'dO1'] as $k) {
                    q26_set_archive($t, $x[$k], ['is_verified' => 1, 'verified_at' => $marker, 'verified_by' => 'QA26']);
                }
                $N = function ($k) use ($t, $x) {
                    return q26_n($t, $x[$k]);
                };
                $uname = q26_uname($t);
                $untouched = ['d21', 'dj1', 'dO1', 'dR', 'dNEG', 'dDEL', 'dx1', 'dJF'];
                $snap = [];
                foreach (array_merge($untouched, ['S1', 'S2', 'SJ', 'S1a', 'F']) as $k) {
                    $snap[$k] = q26_row($t, $x[$k]);
                }

                $id = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                foreach ([$N('dF2'), $N('d11'), $N('dHO'), $N('d21'), 'QA26-INVALID'] as $code) {
                    $t->status(q26_scan($t, $s, $id, $code), 200, "scan $code");
                }
                $draft = q26_show($t, $s, $id)[1]['result'];

                $r = q26_confirm($t, $s, $id);
                $t->status($r, 200, 'confirm');
                $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE213', 'ARCHIVE213');
                $t->eq($r[1]['result'], ['id_archive_opname' => $id], 'result hanya id_archive_opname');

                // --- archives: yang discan verified
                $now = time();
                foreach (['dF2', 'd11', 'dHO'] as $k) {
                    $row = q26_row($t, $x[$k]);
                    $t->eq((int) $row['is_verified'], 1, "$k: is_verified 1");
                    $t->true($row['verified_at'] !== null && abs(strtotime($row['verified_at']) - $now) < 60, "$k: verified_at = waktu konfirmasi");
                    $t->true($row['verified_at'] !== $marker, "$k: verified_at diperbarui");
                    $t->eq($row['verified_by'], $uname, "$k: verified_by = user login");
                    $t->eq($row['id_archive_opname'], $id, "$k: id_archive_opname = sesi");
                }
                // verified tak discan (folder tanpa lanjut) -> 0
                foreach (['dF1', 'dA1'] as $k) {
                    $row = q26_row($t, $x[$k]);
                    $t->eq((int) $row['is_verified'], 0, "$k: verified tak discan -> 0");
                    $t->true($row['verified_at'] === null && $row['verified_by'] === null, "$k: verified_at/by dikosongkan");
                    $t->eq($row['id_archive_opname'], $id, "$k: id_archive_opname = sesi (penetap status)");
                }
                // tak verified & tak discan: tetap 0 (ditandai sesi)
                foreach (['dJF', 'dTK'] as $k) {
                    $row = q26_row($t, $x[$k]);
                    $t->eq((int) $row['is_verified'], 0, "$k: tetap 0");
                }
                // di luar cakupan / Not found / Invalid: tak tersentuh
                foreach ($untouched as $k) {
                    if ($k === 'dJF') {
                        continue; // dJF di cakupan untuk user semua lokasi (dicek di atas)
                    }
                    $t->true(json_encode(q26_row($t, $x[$k])) === json_encode($snap[$k]), "$k: baris archives tidak berubah");
                }
                $t->eq(q26_v($t, $x['d21']), [1, true, 'QA26', null], 'd21 (subfolder tak dicentang, verified) tetap verified, tanpa id sesi');
                $t->true(json_encode(q26_row($t, $x['S2'])) === json_encode($snap['S2']), 'folder S2 tak berubah');

                // --- archive_opnames
                $o = q26_opname($t, $id);
                $t->eq((int) $o['status'], 2, 'DB: status 2');
                $t->true($o['confirmed_at'] !== null && abs(strtotime($o['confirmed_at']) - $now) < 60, 'DB: confirmed_at = waktu server');
                $t->true(strtotime($o['confirmed_at']) >= strtotime($o['selected_at']), 'DB: confirmed_at >= selected_at');
                $t->eq($o['scope_name'], q26_n($t, $x['S1']), 'DB: scope_name = subfolder pertama');
                $t->eq((int) $o['scope_folder_count'], 1, 'DB: scope_folder_count');
                $t->eq([(int) $o['total_documents'], (int) $o['verified_before_count'], (int) $o['scanned_count'], (int) $o['verified_count'],
                    (int) $o['not_found_count'], (int) $o['invalid_count'], (int) $o['unscanned_count'], (int) $o['unverified_count']],
                    [7, 3, 5, 3, 1, 1, 4, 2], 'DB: angka final (total 7, verified_before 3, scanned 5, verified 3, not_found 1, invalid 1, unscanned 4, unverified 2)');
                $t->eq($o['updated_by'], $uname, 'DB: updated_by');
                $t->eq($draft['counts']['total_documents'], 7, 'ringkasan draft sebelum confirm: total 7 (cocok)');
                $t->eq($draft['counts']['unverified'], 2, 'ringkasan draft: unverified 2 (perkiraan = hasil)');

                // --- archive_opname_folders: level 0-2, angka final per folder (dokumen langsung)
                $fol = [];
                foreach (q26_opfolders($t, $id) as $f) {
                    $fol[$f['id_archive']] = $f;
                }
                $t->eq(count($fol), 3, 'DB: 3 baris folder (F, S1, S1a)');
                $exp = [
                    'F'   => [0, 5, 2, 2, 1],
                    'S1'  => [1, 1, 1, 1, 0],
                    'S1a' => [2, 1, 0, 0, 1],
                ];
                foreach ($exp as $k => $e) {
                    $f = $fol[$x[$k]] ?? null;
                    $t->true($f !== null, "DB: baris folder $k");
                    $t->eq([(int) $f['level'], (int) $f['total_documents'], (int) $f['verified_count'], (int) $f['verified_after_count'], (int) $f['unverified_count']], $e, "DB: folder $k level/total/verified/verified_after/unverified");
                    $t->eq([(int) $f['is_continue'], (int) $f['is_opnamed_today']], [0, 0], "DB: folder $k belum diopname hari ini, tanpa lanjut");
                    $t->eq($f['name'], q26_n($t, $x[$k]), "DB: snapshot nama $k");
                }
                $t->eq($fol[$x['S1a']]['id_archive_parent'], $x['S1'], 'DB: snapshot id_archive_parent S1a = S1');
                $t->true(!isset($fol[$x['S2']]) && !isset($fol[$x['SJ']]), 'DB: S2 & SJ (tak dicentang) tak ada di snapshot');

                // --- archive_opname_documents: scan + belum discan (result 4)
                $docs = q26_opdocs($t, $id);
                $t->eq(count($docs), 9, 'DB: 9 baris (5 scan + 4 belum discan)');
                $byRes = [];
                foreach ($docs as $d) {
                    $byRes[(int) $d['result']] = ($byRes[(int) $d['result']] ?? 0) + 1;
                }
                ksort($byRes);
                $t->eq($byRes, [1 => 3, 2 => 1, 3 => 1, 4 => 4], 'DB: result 1x3, 2x1, 3x1, 4x4');
                foreach (['dF1', 'dJF', 'dTK', 'dA1'] as $k) {
                    $d = $docs[strtolower($N($k))] ?? null;
                    $t->true($d !== null, "DB: baris belum discan $k");
                    $t->eq([(int) $d['result'], $d['id_archive'], (int) $d['is_verified_after']], [4, $x[$k], 0], "DB: $k result 4, is_verified_after 0");
                    $t->true($d['scanned_at'] === null, "DB: $k scanned_at NULL");
                    $t->eq($d['id_archive_folder'], $t->db()->table('archives')->where('id_archive', $x[$k])->value('id_archive_parent'), "DB: $k id_archive_folder = induk");
                }
                foreach (['dF2', 'd11', 'dHO'] as $k) {
                    $d = $docs[strtolower($N($k))];
                    $t->eq([(int) $d['result'], (int) $d['is_verified_after']], [1, 1], "DB: $k result 1, is_verified_after 1");
                    $t->true($d['scanned_at'] !== null, "DB: $k scanned_at");
                }
                $t->eq((int) $docs[strtolower($N('d21'))]['result'], 2, 'DB: d21 not_found');
                $t->eq((int) $docs[strtolower($N('d21'))]['is_verified_after'], 1, 'DB: d21 is_verified_after = nilai dokumen (1, tak berubah)');
                $t->true($docs['qa26-invalid']['is_verified_after'] === null, 'DB: invalid is_verified_after NULL');

                // --- GET opnames/{id} dan /documents sesudah confirm = nilai tersimpan
                $show = q26_show($t, $s, $id)[1]['result'];
                $t->eq($show['counts'], ['total_documents' => 7, 'verified_before' => 3, 'scanned' => 5, 'verified' => 3, 'not_found' => 1, 'invalid' => 1, 'unscanned' => 4, 'unverified' => 2], 'show.counts = nilai tersimpan');
                $t->eq(array_map(function ($f) {
                    return [$f['level'], $f['document_count']];
                }, $show['folders']), [[0, 5], [1, 1], [2, 1]], 'show.folders level 0-2 (dokumen langsung)');
                $names = [];
                foreach ($show['warnings'] as $w) {
                    $names[$w['id_archive']] = $w['unverify_count'];
                }
                $t->eq($names, [$x['F'] => 1, $x['S1'] => 1], 'warnings sesudah confirm: F 1 (dF1), S1 1 (dA1 di turunan S1a dijumlah ke baris S1)');
                $un = q26_all_docs($t, $s, $id, 'unscanned', 100);
                $t->eq(count($un), 4, '/documents unscanned = 4');
                $t->eq(q26_dirty($t)['archives'], 0, 'tidak ada baris archives non-QA26 yang berubah');
                $t->eq($t->db()->table('archives')->where('id_archive', $x['dHO'])->value('status'), 2, 'dokumen Handed Over tetap status 2');
                $t->eq($t->db()->table('archives')->where('id_archive', $x['dTK'])->value('status'), 3, 'dokumen Taken tetap status 3');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-19',
        'title' => 'Opname ulang hari yang sama: Lanjut aktif -> verified tak discan tetap 1; Lanjut nonaktif -> 0 dan unverified_count = jumlah peringatan Step 3; turunan mengikuti baris Step 1; Lanjut diabaikan untuk folder belum diopname hari ini',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $marker = '2026-01-01 08:00:00';
                $seed = function (array $keys) use ($t, $x, $marker) {
                    foreach ($keys as $k) {
                        q26_set_archive($t, $x[$k], ['is_verified' => 1, 'verified_at' => $marker, 'verified_by' => 'QA26', 'id_archive_opname' => null]);
                    }
                };
                $N = function ($k) use ($t, $x) {
                    return q26_n($t, $x[$k]);
                };
                $uname = q26_uname($t);

                // sesi A: F + S1, semua tanpa discan -> F, S1, S1a "sudah diopname hari ini"
                $a = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                $t->status(q26_confirm($t, $s, $a), 200, 'sesi A confirm');
                $r = q26_folders($t, $s, $x['F']);
                $t->true($r[1]['result']['folder']['opnamed_today'] !== null && q26_child($r, $x['S1'])['opnamed_today'] !== null, 'F & S1 sudah diopname hari ini');
                $t->true(q26_child($r, $x['S1'])['is_default_checked'] === false && q26_child($r, $x['S2'])['is_default_checked'] === true, 'Step 1: S1 tak tercentang, S2 tercentang');
                $t->true(q26_child($r, $x['S2'])['opnamed_today'] === null, 'S2 belum diopname hari ini');
                // turunan otomatis (level 2) juga "sudah diopname hari ini" (BR-7: folder tercakup sesi terkonfirmasi)
                $r1 = q26_folders($t, $s, $x['S1']);
                $t->true(q26_child($r1, $x['S1a'])['opnamed_today'] !== null && q26_child($r1, $x['S1a'])['opnamed_today']['id_archive_opname'] === $a, 'S1a (turunan otomatis) sudah diopname hari ini oleh sesi A');

                // --- sesi B: F lanjut, S1 lanjut, S2 lanjut diminta tapi belum diopname hari ini
                $seed(['dF1', 'dF2', 'd11', 'dA1', 'd21']);
                sleep(1); // detik yang sama dengan confirm sesi lain dihitung bentrok (BR-23)
                $b = q26_session($t, $s, $x['F'], q26_sel([$x['S1'] => true, $x['S2'] => true]), true);
                $fol = [];
                foreach (q26_show($t, $s, $b)[1]['result']['folders'] as $f) {
                    $fol[$f['id_archive']] = [$f['is_continue'], $f['is_opnamed_today']];
                }
                $t->eq($fol, [$x['F'] => [true, true], $x['S1'] => [true, true], $x['S2'] => [false, false]], 'B: lanjut tersimpan hanya untuk folder yang sudah diopname hari ini');
                $t->status(q26_scan($t, $s, $b, $N('dF2')), 200, 'B scan dF2');
                $t->status(q26_confirm($t, $s, $b), 200, 'B confirm');
                $t->eq(q26_v($t, $x['dF1']), [1, true, 'QA26', null], 'B: dF1 (F lanjut, tak discan) tetap 1, tak ditandai sesi');
                $t->eq(q26_row($t, $x['dF1'])['verified_at'], $marker, 'B: dF1 verified_at tidak berubah');
                $t->eq(q26_v($t, $x['d11']), [1, true, 'QA26', null], 'B: d11 (S1 lanjut) tetap 1');
                $t->eq(q26_v($t, $x['dA1']), [1, true, 'QA26', null], 'B: dA1 (S1a ikut lanjut S1) tetap 1');
                $v = q26_v($t, $x['dF2']);
                $t->true($v[0] === 1 && $v[2] === $uname && $v[3] === $b, 'B: dF2 discan -> verified oleh sesi B');
                $v = q26_v($t, $x['d21']);
                $t->true($v[0] === 0 && $v[1] === false && $v[3] === $b, 'B: d21 (S2 lanjut diabaikan, belum diopname hari ini) -> 0');
                $o = q26_opname($t, $b);
                $t->eq((int) $o['unverified_count'], 1, 'B: unverified_count 1 (hanya d21)');
                $fol = [];
                foreach (q26_opfolders($t, $b) as $f) {
                    $fol[$f['id_archive']] = $f;
                }
                $t->eq([(int) $fol[$x['F']]['is_continue'], (int) $fol[$x['F']]['is_opnamed_today'], (int) $fol[$x['F']]['unverified_count']], [1, 1, 0], 'B: folder F is_continue 1, is_opnamed_today 1, unverified 0');
                $t->eq([(int) $fol[$x['S1']]['is_continue'], (int) $fol[$x['S1']]['is_opnamed_today']], [1, 1], 'B: S1 lanjut 1');
                $t->eq([(int) $fol[$x['S1a']]['is_continue'], (int) $fol[$x['S1a']]['is_opnamed_today'], (int) $fol[$x['S1a']]['level']], [1, 1, 2], 'B: S1a (level 2) ikut lanjut S1');
                $t->eq([(int) $fol[$x['S2']]['is_continue'], (int) $fol[$x['S2']]['is_opnamed_today'], (int) $fol[$x['S2']]['unverified_count']], [0, 0, 1], 'B: S2 tanpa lanjut, belum diopname hari ini, unverified 1');
                $docs = q26_opdocs($t, $b);
                $t->eq((int) $docs[strtolower($N('dF1'))]['is_verified_after'], 1, 'B: snapshot dF1 is_verified_after 1 (lanjut)');
                $t->eq((int) $docs[strtolower($N('d21'))]['is_verified_after'], 0, 'B: snapshot d21 is_verified_after 0');

                // --- sesi C: F lanjut off, S1 lanjut off: dokumen verified tak discan turun; peringatan Step 3 = unverified_count
                $seed(['dF1', 'd11', 'dA1']);   // dF2 sudah 1 dari B
                sleep(1);
                $c = q26_session($t, $s, $x['F'], q26_sel([$x['S1'] => false]), false);
                $draft = q26_show($t, $s, $c)[1]['result'];
                $warn = [];
                foreach ($draft['warnings'] as $w) {
                    $warn[$w['id_archive']] = $w['unverify_count'];
                }
                $t->eq($warn, [$x['F'] => 2, $x['S1'] => 2], 'C: warnings draft F 2 (dF1, dF2) + S1 2 (d11, dA1)');
                $t->eq($draft['counts']['unverified'], 4, 'C: counts.unverified draft 4');
                $t->status(q26_confirm($t, $s, $c), 200, 'C confirm');
                foreach (['dF1', 'dF2', 'd11', 'dA1'] as $k) {
                    $v = q26_v($t, $x[$k]);
                    $t->true($v[0] === 0 && $v[1] === false && $v[2] === null && $v[3] === $c, "C: $k -> 0 (lanjut nonaktif), ditandai sesi C");
                }
                $o = q26_opname($t, $c);
                $t->eq((int) $o['unverified_count'], 4, 'C: unverified_count = jumlah peringatan Step 3 (4)');
                $after = q26_show($t, $s, $c)[1]['result'];
                $warnAfter = [];
                foreach ($after['warnings'] as $w) {
                    $warnAfter[$w['id_archive']] = $w['unverify_count'];
                }
                $t->eq($warnAfter, [$x['F'] => 2, $x['S1'] => 2], 'C: warnings sesudah confirm = draft');
                $t->eq(array_sum($warnAfter), (int) $o['unverified_count'], 'C: jumlah warnings = unverified_count');
                $t->eq($after['counts']['unverified'], 4, 'C: show.counts.unverified = 4');
                // S2 tidak dicentang di sesi C: d21 tak tersentuh (masih dari sesi B)
                $t->eq(q26_row($t, $x['d21'])['id_archive_opname'], $b, 'C: d21 (S2 tak dicentang) tetap ditandai sesi B');

                // --- sesi D: F lanjut aktif, S1 lanjut off -> dokumen langsung F tetap, S1 turun
                $seed(['dF1', 'd11']);
                sleep(1);
                $d = q26_session($t, $s, $x['F'], q26_sel([$x['S1'] => false]), true);
                $draftD = q26_show($t, $s, $d)[1]['result'];
                $t->eq(array_column($draftD['warnings'], 'unverify_count', 'id_archive'), [$x['S1'] => 1], 'D: hanya S1 yang menurunkan (d11)');
                $t->status(q26_confirm($t, $s, $d), 200, 'D confirm');
                $t->eq(q26_v($t, $x['dF1']), [1, true, 'QA26', null], 'D: dF1 (F lanjut) tetap 1');
                $t->eq(q26_v($t, $x['d11'])[0], 0, 'D: d11 (S1 tanpa lanjut) -> 0');
                $t->eq((int) q26_opname($t, $d)['unverified_count'], 1, 'D: unverified_count 1');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-20',
        'title' => 'Subfolder tak dicentang & dokumen lokasi lain tetap (is_verified, verified_at, id_archive_opname); user Semarang dan user JOG masing-masing opname root; superadmin melihat keduanya "sudah diopname hari ini"',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $marker = '2026-01-01 08:00:00';
                foreach (['dj1', 'dJF', 'd21', 'd11'] as $k) {
                    q26_set_archive($t, $x[$k], ['is_verified' => 1, 'verified_at' => $marker, 'verified_by' => 'QA26']);
                }
                $same = function ($k) use ($t, $x, $marker) {
                    return q26_v($t, $x[$k]) === [1, true, 'QA26', null] && q26_row($t, $x[$k])['verified_at'] === $marker;
                };

                // (a) Semarang opname F (S1, S2): SJ (JOG) & dJF (JOG) tak tersentuh; dokumen terlihat dinilai
                q26_with_user($t, ['emp' => ['SMR']], function () use ($t, $s, $x, $same) {
                    $id = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                    $t->status(q26_confirm($t, $s, $id), 200, 'Semarang: confirm F[S1]');
                    $t->true($same('dj1'), 'Semarang F[S1]: dj1 (subfolder & lokasi JOG) tetap');
                    $t->true($same('dJF'), 'Semarang F[S1]: dJF (dokumen lokasi JOG di F) tetap');
                    $t->true($same('d21'), 'Semarang F[S1]: d21 (S2 tak dicentang) tetap');
                    $t->eq(q26_v($t, $x['d11'])[0], 0, 'Semarang F[S1]: d11 (dicentang, tak discan) -> 0');
                });

                // (b) root oleh user Semarang, lalu root oleh user JOG (jeda: detik yang sama dengan confirm (a) dihitung bentrok BR-23)
                sleep(2);
                $jogja = $t->db()->table('archives')->where('name', 'CABANG - JOGJA')->where('type', 1)->value('id_archive');
                $semarang = $t->db()->table('archives')->where('name', 'CABANG - SEMARANG')->where('type', 1)->value('id_archive');
                $countMarked = function ($folder, $id = null) use ($t) {
                    $q = $t->db()->table('archives')->where('id_archive_parent', $folder)->where('type', 2);

                    return $id === null ? $q->whereNotNull('id_archive_opname')->count() : $q->where('id_archive_opname', $id)->count();
                };
                $semarangDocs = $t->db()->table('archives')->where('id_archive_parent', $semarang)->where('type', 2)->where('is_active', 1)->count();
                $jogjaDocs = $t->db()->table('archives')->where('id_archive_parent', $jogja)->where('type', 2)->where('is_active', 1)->count();
                $t->true($semarangDocs > 0 && $jogjaDocs > 0, "prasyarat: CABANG - SEMARANG ($semarangDocs) & CABANG - JOGJA ($jogjaDocs) punya dokumen aktif");
                // JOG-only dokumen QA: dj1 ada di SJ; dJF di F: pastikan hanya JOG yang melihat
                $idA = null;
                $idB = null;
                q26_with_user($t, ['emp' => ['SMR']], function () use ($t, $s, $x, &$idA, $jogja, $semarang, $countMarked, $same, $semarangDocs) {
                    $r = q26_folders($t, $s, null);
                    $children = q26_child_ids($r);
                    $t->true(!in_array($jogja, $children, true) && in_array($semarang, $children, true), 'Semarang: root tidak memuat CABANG - JOGJA, memuat CABANG - SEMARANG');
                    $idA = q26_session($t, $s, null, array_map(function ($c) {
                        return ['id_archive' => $c, 'is_continue' => false];
                    }, $children));
                    $show = q26_show($t, $s, $idA)[1]['result'];
                    $t->true($show['counts']['total_documents'] > $semarangDocs, 'Semarang: total root > dokumen CABANG - SEMARANG');
                    $conf = q26_confirm($t, $s, $idA);
                    $t->status($conf, 200, 'Semarang: confirm root');
                    $t->eq($countMarked($jogja), 0, 'Semarang root: dokumen CABANG - JOGJA tak ditandai sesi apa pun');
                    $t->eq($countMarked($semarang, $idA), $semarangDocs, 'Semarang root: semua dokumen CABANG - SEMARANG ditandai sesi A');
                    $t->true($same('dj1') && $same('dJF'), 'Semarang root: dj1 & dJF (lokasi JOG) tetap');
                    $t->eq(q26_v($t, $x['d21'])[3], $idA, 'Semarang root: d21 (S2 di F, terlihat) ditandai sesi A');
                });
                sleep(1);
                q26_with_user($t, ['emp' => ['JOG']], function () use ($t, $s, $x, &$idB, $idA, $jogja, $semarang, $countMarked, $same, $jogjaDocs, $semarangDocs) {
                    $r = q26_folders($t, $s, null);
                    $children = q26_child_ids($r);
                    $t->true(in_array($jogja, $children, true) && !in_array($semarang, $children, true), 'JOG: root memuat CABANG - JOGJA, tidak CABANG - SEMARANG');
                    $idB = q26_session($t, $s, null, array_map(function ($c) {
                        return ['id_archive' => $c, 'is_continue' => false];
                    }, $children));
                    $conf = q26_confirm($t, $s, $idB);
                    $t->status($conf, 200, 'JOG: confirm root');
                    $t->eq($countMarked($jogja, $idB), $jogjaDocs, 'JOG root: semua dokumen CABANG - JOGJA ditandai sesi B');
                    $t->eq($countMarked($semarang, $idA), $semarangDocs, 'JOG root: dokumen CABANG - SEMARANG tetap ditandai sesi A (tak tersentuh)');
                    $t->eq($countMarked($semarang, $idB), 0, 'JOG root: tidak ada dokumen CABANG - SEMARANG ditandai sesi B');
                    $t->eq(q26_v($t, $x['dj1'])[3], $idB, 'JOG root: dj1 (lokasi JOG) kini ditandai sesi B');
                    $t->eq(q26_v($t, $x['dJF'])[0], 0, 'JOG root: dJF (lokasi JOG) dinilai -> 0');
                });
                // superadmin melihat keduanya "Sudah diopname hari ini"
                q26_with_user($t, ['role' => 1], function () use ($t, $s, $jogja, $semarang, $idA, $idB) {
                    $r = q26_folders($t, $s, null);
                    $t->status($r, 200, 'superadmin: folders root');
                    $j = q26_child($r, $jogja);
                    $sm = q26_child($r, $semarang);
                    $t->true($j['opnamed_today'] !== null && $j['is_default_checked'] === false, 'superadmin: CABANG - JOGJA sudah diopname hari ini');
                    $t->eq($j['opnamed_today']['id_archive_opname'], $idB, 'superadmin: JOGJA oleh sesi B (JOG)');
                    $t->true($sm['opnamed_today'] !== null && $sm['is_default_checked'] === false, 'superadmin: CABANG - SEMARANG sudah diopname hari ini');
                    $t->eq($sm['opnamed_today']['id_archive_opname'], $idA, 'superadmin: SEMARANG oleh sesi A (Semarang), tak ditimpa sesi B');
                    $t->true($r[1]['result']['folder']['opnamed_today'] !== null, 'superadmin: root sudah diopname hari ini');
                });
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-21',
        'title' => 'Dokumen Not found & Invalid: baris archives (folder, status, is_active, is_verified, history, updated_at, ...) tidak berubah oleh scan maupun Confirm',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $marker = '2026-01-01 08:00:00';
                q26_set_archive($t, $x['d21'], ['is_verified' => 1, 'verified_at' => $marker, 'verified_by' => 'QA26']);
                q26_set_archive($t, $x['dO1'], ['status' => 2]);
                q26_set_archive($t, $x['dR'], ['status' => 3, 'history' => json_encode([['action' => 'place', 'by' => 'QA26']])]);
                $nf = ['d21', 'dO1', 'dR', 'dNEG', 'dDEL', 'dx1', 'dj1'];
                $snapRows = function () use ($t, $x, $nf) {
                    $out = [];
                    foreach ($nf as $k) {
                        $out[$k] = q26_row($t, $x[$k]);
                        $out[$k . '_doc'] = (array) $t->db()->table('archive_documents')->where('id_archive', $x[$k])->first();
                    }

                    return json_encode($out);
                };
                $before = $snapRows();
                $total = $t->db()->table('archives')->count();

                $id = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                foreach ($nf as $k) {
                    $r = q26_scan($t, $s, $id, q26_n($t, $x[$k]));
                    $t->eq($r[1]['result']['row']['result'], 'not_found', "scan $k -> not_found");
                }
                foreach (['QA26-INV-1', 'QA26-INV-2', q26_n($t, $x['S1'])] as $code) {
                    $r = q26_scan($t, $s, $id, $code);
                    $t->eq($r[1]['result']['row']['result'], 'invalid', "scan $code -> invalid");
                }
                $t->true($before === $snapRows(), 'sesudah scan: baris archives Not found tidak berubah');
                $folderBefore = [q26_row($t, $x['S1']), q26_row($t, $x['F'])];
                $t->status(q26_confirm($t, $s, $id), 200, 'confirm');
                $t->true($before === $snapRows(), 'sesudah confirm: baris archives (dan archive_documents) Not found tidak berubah');
                $t->eq($t->db()->table('archives')->count(), $total, 'jumlah baris archives tetap');
                $t->true(json_encode($folderBefore) === json_encode([q26_row($t, $x['S1']), q26_row($t, $x['F'])]), 'baris folder F & S1 tak berubah');
                // sesi mencatat hasilnya
                $o = q26_opname($t, $id);
                $t->eq([(int) $o['not_found_count'], (int) $o['invalid_count']], [7, 3], 'sesi menghitung Not found 7 & Invalid 3');
                // history tidak ditambah untuk dokumen dalam cakupan (opname bukan edit dokumen)
                $hist = $t->db()->table('archives')->whereIn('id_archive', [$x['dF1'], $x['dF2'], $x['d11']])->pluck('history')->all();
                $t->true(count(array_filter($hist)) === 0, 'history dokumen dalam cakupan tidak ditambah (opname bukan edit)');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-25',
        'title' => 'Dokumen dipindah sesudah discan: draft dan Confirm menilai ulang (verified -> Not found, verified-nya tak berubah; Not found -> Verified bila dipindah masuk; nonaktif -> Not found)',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $marker = '2026-01-01 08:00:00';
                q26_set_archive($t, $x['d11'], ['is_verified' => 1, 'verified_at' => $marker, 'verified_by' => 'QA26']);
                $N = function ($k) use ($t, $x) {
                    return q26_n($t, $x[$k]);
                };
                $put = function ($name, $parent) use ($t, $s) {
                    return $t->call($s, 'POST', Q26_BASE . '/documents/put-in', ['id_archive_parent' => $parent, 'name' => $name]);
                };

                $id = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                foreach (['dF1', 'd11', 'dF2', 'dO1', 'dA1'] as $k) {
                    $r = q26_scan($t, $s, $id, $N($k));
                    $t->status($r, 200, "scan $k");
                }
                $c = q26_show($t, $s, $id)[1]['result']['counts'];
                $t->eq([$c['verified'], $c['not_found'], $c['total_documents']], [4, 1, 5], 'sebelum dipindah: verified 4 (dF1,d11,dF2,dA1), not_found 1 (dO1), total 5');

                // d11 dipindah ke OTH (keluar cakupan), dO1 dipindah ke S1 (masuk cakupan), dF2 dinonaktifkan
                $r = $put($N('d11'), $x['OTH']);
                $t->status($r, 200, 'put-in d11 -> OTH');
                $r = $put($N('dO1'), $x['S1']);
                $t->status($r, 200, 'put-in dO1 -> S1');
                q26_set_archive($t, $x['dF2'], ['is_active' => 0]);

                $show = q26_show($t, $s, $id)[1]['result'];
                $c = $show['counts'];
                // total = dF1, dJF, dA1, dO1 (dF2 nonaktif, d11 keluar) = 4; verified: dF1, dA1, dO1 = 3; not_found: d11, dF2 = 2
                $t->eq([$c['total_documents'], $c['verified'], $c['not_found'], $c['invalid'], $c['scanned'], $c['unscanned']], [4, 3, 2, 0, 5, 1], 'draft menilai ulang: total 4, verified 3, not_found 2, unscanned 1');
                $rows = q26_all_docs($t, $s, $id, 'scanned', 100);
                $byCode = [];
                foreach ($rows as $row) {
                    $byCode[strtolower($row['code'])] = $row['result'];
                }
                $t->eq($byCode[strtolower($N('d11'))], 'not_found', 'draft: d11 -> not_found');
                $t->eq($byCode[strtolower($N('dO1'))], 'verified', 'draft: dO1 -> verified');
                $t->eq($byCode[strtolower($N('dF2'))], 'not_found', 'draft: dF2 (nonaktif) -> not_found');

                $d11Before = q26_row($t, $x['d11']);
                $t->status(q26_confirm($t, $s, $id), 200, 'confirm');
                $docs = q26_opdocs($t, $id);
                $t->eq((int) $docs[strtolower($N('d11'))]['result'], 2, 'snapshot: d11 result 2 (not_found)');
                $t->eq((int) $docs[strtolower($N('dO1'))]['result'], 1, 'snapshot: dO1 result 1 (verified)');
                $t->eq((int) $docs[strtolower($N('dF2'))]['result'], 2, 'snapshot: dF2 result 2');
                $t->eq((int) $docs[strtolower($N('d11'))]['is_verified_after'], 1, 'snapshot: d11 is_verified_after = nilai dokumen (1)');
                // verified d11 tidak berubah (Not found tak mengubah dokumen)
                $d11After = q26_row($t, $x['d11']);
                $t->true(json_encode($d11Before) === json_encode($d11After), 'd11: baris archives (verified, folder OTH) tidak berubah oleh confirm');
                $t->eq($d11After['id_archive_parent'], $x['OTH'], 'd11 tetap di OTH');
                $t->eq(q26_v($t, $x['dO1'])[0], 1, 'dO1 (dipindah masuk, discan) -> verified');
                $t->eq(q26_row($t, $x['dO1'])['id_archive_opname'], $id, 'dO1 ditandai sesi');
                $t->eq(q26_row($t, $x['dF2'])['is_verified'], 0, 'dF2 (nonaktif) tak diverifikasi');
                $o = q26_opname($t, $id);
                $t->eq([(int) $o['total_documents'], (int) $o['verified_count'], (int) $o['not_found_count']], [4, 3, 2], 'angka final sesi menilai ulang');
            } finally {
                q26_cleanup($t);
            }
        },
    ],
];
