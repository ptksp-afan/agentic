<?php
/**
 * ED-1026 (rev: opname tanpa draft) - Konfirmasi Opname (POST opnames): AC-18 (folder belum diopname hari ini: timpa; sesi,
 * folder, dokumen tersimpan dari body), AC-19 (opname ulang hari sama: Lanjut / timpa), AC-20 (subfolder tak dicentang &
 * lokasi lain tak tersentuh; user Semarang dan user JOG masing-masing opname root), AC-21 (Not found & Invalid tak mengubah
 * archives), AC-25 (dokumen dipindah sesudah discan dinilai ulang di pratinjau dan Konfirmasi).
 * Semua data uji dipulihkan di finally: q26_cleanup() mengembalikan kolom verifikasi archives yang ditandai sesi QA ke NILAI
 * SEBELUMNYA (pra-citra), bukan default; data developer dijaga.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-18',
        'title' => 'POST opnames folder belum diopname hari ini: discan -> is_verified=1 + verified_at/by + id_archive_opname; verified tak discan -> 0; sesi status 2 + angka + selected_at = body; folder level 0-2; baris result=4 per dokumen belum discan; scanned_at = body, urut sesuai scans; dokumen Handed Over/Taken ikut dihitung; scan sebelum Konfirmasi tidak menulis',
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

                // Step 1 -> scan (tanpa simpan) -> Konfirmasi
                $sel = q26_selection($x['F'], q26_sel([$x['S1']]));
                $selected = q26_selected($t, $s, $x['F']);
                $before = q26_state($t);
                list($scans) = q26_scan_all($t, $s, $sel, [$N('dF2'), $N('d11'), $N('dHO'), $N('d21'), 'QA26-INVALID']);
                $t->true(q26_same_state($before, q26_state($t)), 'scan sebelum Konfirmasi: tidak ada data opname / verifikasi berubah');
                $prev = q26_preview($t, $s, $sel, $scans)[1]['result'];

                $r = q26_store($t, $s, $sel, $scans, $selected);
                $t->status($r, 200, 'Konfirmasi');
                $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE213', 'ARCHIVE213');
                $id = $r[1]['result']['id_archive_opname'];
                $t->eq(array_keys($r[1]['result']), ['id_archive_opname'], 'result hanya id_archive_opname');

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
                foreach (['dJF', 'dTK'] as $k) {
                    $t->eq((int) q26_row($t, $x[$k])['is_verified'], 0, "$k: tetap 0");
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
                $t->eq($o['selected_at'], $selected, 'DB: selected_at = nilai body (waktu muat Step 1)');
                $t->true($o['confirmed_at'] !== null && abs(strtotime($o['confirmed_at']) - $now) < 60, 'DB: confirmed_at = waktu server');
                $t->eq($o['created_at'], $o['confirmed_at'], 'DB: created_at = confirmed_at = waktu Konfirmasi');
                $t->true(strtotime($o['confirmed_at']) >= strtotime($o['selected_at']), 'DB: confirmed_at >= selected_at');
                $t->eq($o['scope_name'], q26_n($t, $x['S1']), 'DB: scope_name = subfolder pertama');
                $t->eq((int) $o['scope_folder_count'], 1, 'DB: scope_folder_count');
                $t->eq($o['id_archive'], $x['F'], 'DB: id_archive = folder opname');
                $t->eq([(int) $o['total_documents'], (int) $o['verified_before_count'], (int) $o['scanned_count'], (int) $o['verified_count'],
                    (int) $o['not_found_count'], (int) $o['invalid_count'], (int) $o['unscanned_count'], (int) $o['unverified_count']],
                    [7, 3, 5, 3, 1, 1, 4, 2], 'DB: angka final (total 7, verified_before 3, scanned 5, verified 3, not_found 1, invalid 1, unscanned 4, unverified 2)');
                $t->eq($o['updated_by'], $uname, 'DB: updated_by');
                $t->eq([$o['created_by']], [$uname], 'DB: created_by');
                $t->eq($prev['counts']['total_documents'], 7, 'pratinjau sebelum Konfirmasi: total 7 (cocok)');
                $t->eq($prev['counts']['unverified'], 2, 'pratinjau: unverified 2 (perkiraan = hasil)');
                $t->eq($prev['counts'], q26_show($t, $s, $id)[1]['result']['counts'], 'counts tersimpan = pratinjau');

                // --- archive_opname_folders: level 0-2, angka final per folder (dokumen langsung)
                $fol = [];
                foreach (q26_opfolders($t, $id) as $f) {
                    $fol[$f['id_archive']] = $f;
                }
                $t->eq(count($fol), 3, 'DB: 3 baris folder (F, S1, S1a)');
                $exp = ['F' => [0, 5, 2, 2, 1], 'S1' => [1, 1, 1, 1, 0], 'S1a' => [2, 1, 0, 0, 1]];
                foreach ($exp as $k => $e) {
                    $f = $fol[$x[$k]] ?? null;
                    $t->true($f !== null, "DB: baris folder $k");
                    $t->eq([(int) $f['level'], (int) $f['total_documents'], (int) $f['verified_count'], (int) $f['verified_after_count'], (int) $f['unverified_count']], $e, "DB: folder $k level/total/verified/verified_after/unverified");
                    $t->eq([(int) $f['is_continue'], (int) $f['is_opnamed_today']], [0, 0], "DB: folder $k belum diopname hari ini, tanpa lanjut");
                    $t->eq($f['name'], q26_n($t, $x[$k]), "DB: snapshot nama $k");
                }
                $t->eq($fol[$x['S1a']]['id_archive_parent'], $x['S1'], 'DB: snapshot id_archive_parent S1a = S1');
                $t->true(!isset($fol[$x['S2']]) && !isset($fol[$x['SJ']]), 'DB: S2 & SJ (tak dicentang) tak ada di snapshot');

                // --- archive_opname_documents: scan (scanned_at = body, urut sesuai scans) + belum discan (result 4)
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
                    $t->eq($d['created_at'], $o['confirmed_at'], "DB: $k created_at = waktu Konfirmasi");
                }
                foreach (['dF2', 'd11', 'dHO'] as $k) {
                    $d = $docs[strtolower($N($k))];
                    $t->eq([(int) $d['result'], (int) $d['is_verified_after']], [1, 1], "DB: $k result 1, is_verified_after 1");
                    $t->true($d['scanned_at'] !== null, "DB: $k scanned_at");
                }
                $t->eq((int) $docs[strtolower($N('d21'))]['result'], 2, 'DB: d21 not_found');
                $t->eq((int) $docs[strtolower($N('d21'))]['is_verified_after'], 1, 'DB: d21 is_verified_after = nilai dokumen (1, tak berubah)');
                $t->true($docs['qa26-invalid']['is_verified_after'] === null, 'DB: invalid is_verified_after NULL');
                // scanned_at = body (detik) dan urut naik sesuai urutan scans (mikrodetik menjaga urutan)
                $stored = $t->db()->table('archive_opname_documents')->where('id_archive_opname', $id)->whereNotNull('scanned_at')->orderBy('scanned_at')->pluck('code')->all();
                $t->eq(array_map('strtolower', $stored), array_map('strtolower', array_column($scans, 'code')), 'DB: urutan scanned_at naik = urutan scans');
                foreach ($scans as $sc) {
                    $d = $docs[strtolower($sc['code'])];
                    $t->eq(substr($d['scanned_at'], 0, 19) >= $sc['scanned_at'], true, 'DB: scanned_at >= nilai body ' . $sc['code']);
                    $t->eq(substr($d['scanned_at'], 0, 10), substr($sc['scanned_at'], 0, 10), 'DB: scanned_at tanggal = body ' . $sc['code']);
                }

                // --- GET opnames/{id} dan /documents sesudah Konfirmasi = nilai tersimpan
                $show = q26_show($t, $s, $id)[1]['result'];
                $t->eq(array_map(function ($f) {
                    return [$f['level'], $f['document_count']];
                }, $show['folders']), [[0, 5], [1, 1], [2, 1]], 'show.folders level 0-2 (dokumen langsung)');
                $names = [];
                foreach ($show['warnings'] as $w) {
                    $names[$w['id_archive']] = $w['unverify_count'];
                }
                $t->eq($names, [$x['F'] => 1, $x['S1'] => 1], 'warnings sesudah Konfirmasi: F 1 (dF1), S1 1 (dA1 di turunan S1a dijumlah ke baris S1)');
                $t->eq(count(q26_all_docs($t, $s, $id, 'unscanned', 100)), 4, '/documents unscanned = 4');
                $t->eq($t->db()->table('archives')->where('id_archive', $x['dHO'])->value('status'), 2, 'dokumen Handed Over tetap status 2');
                $t->eq($t->db()->table('archives')->where('id_archive', $x['dTK'])->value('status'), 3, 'dokumen Taken tetap status 3');
                // nilai kolom lain archives tak disentuh (updated_at/by, history)
                $t->eq(q26_row($t, $x['d11'])['updated_by'], 'QA26', 'd11: updated_by tak disentuh (opname bukan edit)');
                $t->true(q26_row($t, $x['d11'])['history'] === null, 'd11: history tak ditambah');
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
                $warnOf = function ($res) {
                    $w = [];
                    foreach ($res['warnings'] as $row) {
                        $w[$row['id_archive']] = $row['unverify_count'];
                    }

                    return $w;
                };

                // sesi A: F + S1, tanpa scan -> F, S1, S1a "sudah diopname hari ini"
                $a = q26_ok($t, $s, $x['F'], q26_sel([$x['S1']]), null, [], 'sesi A');
                $r = q26_folders($t, $s, $x['F']);
                $t->true($r[1]['result']['folder']['opnamed_today'] !== null && q26_child($r, $x['S1'])['opnamed_today'] !== null, 'F & S1 sudah diopname hari ini');
                $t->true(q26_child($r, $x['S1'])['is_default_checked'] === false && q26_child($r, $x['S2'])['is_default_checked'] === true, 'Step 1: S1 tak tercentang, S2 tercentang');
                $t->true(q26_child($r, $x['S2'])['opnamed_today'] === null, 'S2 belum diopname hari ini');
                $r1 = q26_folders($t, $s, $x['S1']);
                $t->true(q26_child($r1, $x['S1a'])['opnamed_today'] !== null && q26_child($r1, $x['S1a'])['opnamed_today']['id_archive_opname'] === $a, 'S1a (turunan otomatis) sudah diopname hari ini oleh sesi A');

                // --- sesi B: F lanjut, S1 lanjut, S2 lanjut diminta tapi belum diopname hari ini
                $seed(['dF1', 'dF2', 'd11', 'dA1', 'd21']);
                $selB = q26_sel([$x['S1'] => true, $x['S2'] => true]);
                $prev = q26_preview($t, $s, q26_selection($x['F'], $selB, true))[1]['result'];
                $fol = [];
                foreach ($prev['folders'] as $f) {
                    $fol[$f['id_archive']] = [$f['is_continue'], $f['is_opnamed_today']];
                }
                $t->eq($fol, [$x['F'] => [true, true], $x['S1'] => [true, true], $x['S2'] => [false, false]], 'B pratinjau: lanjut berlaku hanya untuk folder yang sudah diopname hari ini');
                $o = q26_do($t, $s, $x['F'], $selB, true, [$N('dF2')]);
                $t->status($o['r'], 200, 'B Konfirmasi');
                $b = $o['id'];
                $t->eq(q26_v($t, $x['dF1']), [1, true, 'QA26', null], 'B: dF1 (F lanjut, tak discan) tetap 1, tak ditandai sesi');
                $t->eq(q26_row($t, $x['dF1'])['verified_at'], $marker, 'B: dF1 verified_at tidak berubah');
                $t->eq(q26_v($t, $x['d11']), [1, true, 'QA26', null], 'B: d11 (S1 lanjut) tetap 1');
                $t->eq(q26_v($t, $x['dA1']), [1, true, 'QA26', null], 'B: dA1 (S1a ikut lanjut S1) tetap 1');
                $v = q26_v($t, $x['dF2']);
                $t->true($v[0] === 1 && $v[2] === $uname && $v[3] === $b, 'B: dF2 discan -> verified oleh sesi B');
                $v = q26_v($t, $x['d21']);
                $t->true($v[0] === 0 && $v[1] === false && $v[3] === $b, 'B: d21 (S2 lanjut diabaikan, belum diopname hari ini) -> 0');
                $row = q26_opname($t, $b);
                $t->eq((int) $row['unverified_count'], 1, 'B: unverified_count 1 (hanya d21)');
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

                // --- sesi C: F lanjut off, S1 lanjut off: verified tak discan turun; peringatan Step 3 = unverified_count
                $seed(['dF1', 'd11', 'dA1']);   // dF2 sudah 1 dari B
                $selC = q26_sel([$x['S1'] => false]);
                $draft = q26_preview($t, $s, q26_selection($x['F'], $selC, false))[1]['result'];
                $t->eq($warnOf($draft), [$x['F'] => 2, $x['S1'] => 2], 'C: warnings pratinjau F 2 (dF1, dF2) + S1 2 (d11, dA1)');
                $t->eq($draft['counts']['unverified'], 4, 'C: counts.unverified pratinjau 4');
                $o = q26_do($t, $s, $x['F'], $selC, false, []);
                $t->status($o['r'], 200, 'C Konfirmasi');
                $c = $o['id'];
                foreach (['dF1', 'dF2', 'd11', 'dA1'] as $k) {
                    $v = q26_v($t, $x[$k]);
                    $t->true($v[0] === 0 && $v[1] === false && $v[2] === null && $v[3] === $c, "C: $k -> 0 (lanjut nonaktif), ditandai sesi C");
                }
                $row = q26_opname($t, $c);
                $t->eq((int) $row['unverified_count'], 4, 'C: unverified_count = jumlah peringatan Step 3 (4)');
                $after = q26_show($t, $s, $c)[1]['result'];
                $t->eq($warnOf($after), [$x['F'] => 2, $x['S1'] => 2], 'C: warnings tersimpan = pratinjau');
                $t->eq(array_sum($warnOf($after)), (int) $row['unverified_count'], 'C: jumlah warnings = unverified_count');
                $t->eq($after['counts']['unverified'], 4, 'C: show.counts.unverified = 4');
                $t->eq(q26_row($t, $x['d21'])['id_archive_opname'], $b, 'C: d21 (S2 tak dicentang) tetap ditandai sesi B');

                // --- sesi D: F lanjut aktif, S1 lanjut off -> dokumen langsung F tetap, S1 turun
                $seed(['dF1', 'd11']);
                $selD = q26_sel([$x['S1'] => false]);
                $draftD = q26_preview($t, $s, q26_selection($x['F'], $selD, true))[1]['result'];
                $t->eq($warnOf($draftD), [$x['S1'] => 1], 'D: hanya S1 yang menurunkan (d11)');
                $o = q26_do($t, $s, $x['F'], $selD, true, []);
                $t->status($o['r'], 200, 'D Konfirmasi');
                $d = $o['id'];
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
        'title' => 'Subfolder tak dicentang & dokumen lokasi lain tetap (is_verified, verified_at, id_archive_opname); user Semarang dan user JOG masing-masing opname root (subfolder uji dipilih); superadmin melihat keduanya "sudah diopname hari ini"',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $marker = '2026-01-01 08:00:00';
                // subfolder uji di root berlokasi tunggal (folder nyata developer TIDAK dipilih: hanya yang dicentang yang disentuh)
                $RS = q26_folder($t, 'RS', ['all' => 0, 'locs' => ['SMR']]);
                $rs1 = q26_doc($t, 'rs1', ['parent' => $RS, 'all' => 0, 'locs' => ['SMR']]);
                $RJ = q26_folder($t, 'RJ', ['all' => 0, 'locs' => ['JOG']]);
                $rj1 = q26_doc($t, 'rj1', ['parent' => $RJ, 'all' => 0, 'locs' => ['JOG']]);
                $x['rs1'] = $rs1;
                $x['rj1'] = $rj1;
                foreach (['dj1', 'dJF', 'd21', 'd11', 'rs1', 'rj1'] as $k) {
                    q26_set_archive($t, $x[$k], ['is_verified' => 1, 'verified_at' => $marker, 'verified_by' => 'QA26']);
                }
                $same = function ($k) use ($t, $x, $marker) {
                    return q26_v($t, $x[$k]) === [1, true, 'QA26', null] && q26_row($t, $x[$k])['verified_at'] === $marker;
                };

                // (a) Semarang opname F[S1]: SJ (JOG) & dJF (JOG) tak tersentuh; dokumen terlihat dinilai
                q26_with_user($t, ['emp' => ['SMR']], function () use ($t, $s, $x, $same) {
                    $id = q26_ok($t, $s, $x['F'], q26_sel([$x['S1']]), null, [], 'Semarang: F[S1]');
                    $t->true($same('dj1'), 'Semarang F[S1]: dj1 (subfolder & lokasi JOG) tetap');
                    $t->true($same('dJF'), 'Semarang F[S1]: dJF (dokumen lokasi JOG di F) tetap');
                    $t->true($same('d21'), 'Semarang F[S1]: d21 (S2 tak dicentang) tetap');
                    $t->eq(q26_v($t, $x['d11'])[0], 0, 'Semarang F[S1]: d11 (dicentang, tak discan) -> 0');
                    $t->eq((int) q26_opname($t, $id)['total_documents'], 4, 'Semarang F[S1]: total 4 (dJF lokasi JOG tak dihitung)');
                });

                // (b) root oleh user Semarang, lalu root oleh user JOG (selected_at user kedua dimuat sesudah Konfirmasi pertama)
                $jogja = $t->db()->table('archives')->where('name', 'CABANG - JOGJA')->where('type', 1)->value('id_archive');
                $countMarked = function ($folder, $id) use ($t) {
                    return $t->db()->table('archives')->where('id_archive_parent', $folder)->where('type', 2)->where('id_archive_opname', $id)->count();
                };
                $idA = null;
                $idB = null;
                q26_with_user($t, ['emp' => ['SMR']], function () use ($t, $s, $x, &$idA, $RS, $RJ, $jogja, $countMarked, $same) {
                    $r = q26_folders($t, $s, null);
                    $children = q26_child_ids($r);
                    $t->true(in_array($RS, $children, true) && !in_array($RJ, $children, true), 'Semarang: root memuat RS (SMR), tidak RJ (JOG)');
                    if ($jogja !== null) {
                        $t->true(!in_array($jogja, $children, true), 'Semarang: root tidak memuat CABANG - JOGJA');
                    }
                    $o = q26_do($t, $s, null, q26_sel([$RS]), null, [q26_n($t, $x['rs1'])]);
                    $t->status($o['r'], 200, 'Semarang: Konfirmasi root[RS]');
                    $idA = $o['id'];
                    $t->eq(q26_v($t, $x['rs1'])[0], 1, 'Semarang root: rs1 (discan) verified');
                    $t->eq(q26_row($t, $x['rs1'])['id_archive_opname'], $idA, 'Semarang root: rs1 ditandai sesi A');
                    $t->eq($t->db()->table('archives')->where('id_archive_opname', $idA)->where('name', 'like', Q26_PREFIX . 'rj1%')->count(), 0, 'Semarang root: rj1 (lokasi JOG) tak ditandai sesi A');
                    if ($jogja !== null) {
                        $t->eq($t->db()->table('archives')->where('id_archive_opname', $idA)->where('id_archive_parent', $jogja)->count(), 0, 'Semarang root: dokumen CABANG - JOGJA tak ditandai sesi A');
                    }
                    $t->true($same('dj1') && $same('dJF'), 'Semarang root: dj1 & dJF (lokasi JOG) tetap');
                    $t->true($same('rj1'), 'Semarang root: rj1 tetap (tak terlihat, subfolder RJ tak dipilih)');
                    $t->eq($t->db()->table('archives')->where('id_archive_opname', $idA)->where('id_archive_parent', $x['F'])->count(), 0, 'Semarang root: F (subfolder uji tak dicentang) tak tersentuh');
                    $t->true($same('d21'), 'Semarang root: d21 (di F, F tak dicentang) tetap');
                });
                q26_with_user($t, ['emp' => ['JOG']], function () use ($t, $s, $x, &$idB, $idA, $RS, $RJ, $same) {
                    $r = q26_folders($t, $s, null);
                    $children = q26_child_ids($r);
                    $t->true(in_array($RJ, $children, true) && !in_array($RS, $children, true), 'JOG: root memuat RJ (JOG), tidak RS (SMR)');
                    $o = q26_do($t, $s, null, q26_sel([$RJ]), null, [q26_n($t, $x['rj1'])]);
                    $t->status($o['r'], 200, 'JOG: Konfirmasi root[RJ]');
                    $idB = $o['id'];
                    $t->eq(q26_row($t, $x['rj1'])['id_archive_opname'], $idB, 'JOG root: rj1 ditandai sesi B');
                    $t->eq(q26_row($t, $x['rs1'])['id_archive_opname'], $idA, 'JOG root: rs1 (SMR) tetap ditandai sesi A (tak tersentuh)');
                    $t->eq(q26_v($t, $x['rs1'])[0], 1, 'JOG root: rs1 tetap verified');
                    $t->true($same('d21'), 'JOG root: d21 (di F, F tak dicentang) tetap');
                });
                // superadmin melihat keduanya "Sudah diopname hari ini"
                q26_with_user($t, ['role' => 1], function () use ($t, $s, $RS, $RJ, $idA, $idB) {
                    $r = q26_folders($t, $s, null);
                    $t->status($r, 200, 'superadmin: folders root');
                    $a = q26_child($r, $RS);
                    $b = q26_child($r, $RJ);
                    $t->true($a['opnamed_today'] !== null && $a['is_default_checked'] === false, 'superadmin: RS sudah diopname hari ini');
                    $t->eq($a['opnamed_today']['id_archive_opname'], $idA, 'superadmin: RS oleh sesi A (Semarang)');
                    $t->true($b['opnamed_today'] !== null && $b['is_default_checked'] === false, 'superadmin: RJ sudah diopname hari ini');
                    $t->eq($b['opnamed_today']['id_archive_opname'], $idB, 'superadmin: RJ oleh sesi B (JOG), tak ditimpa sesi A');
                    $t->true($r[1]['result']['folder']['opnamed_today'] !== null, 'superadmin: root sudah diopname hari ini');
                    $t->eq($r[1]['result']['folder']['opnamed_today']['id_archive_opname'], $idB, 'superadmin: root = konfirmasi terakhir (sesi B)');
                });
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-21',
        'title' => 'Dokumen Not found & Invalid: baris archives (folder, status, is_active, is_verified, history, updated_at, ...) dan archive_documents tidak berubah oleh scan maupun Konfirmasi',
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

                $sel = q26_selection($x['F'], q26_sel([$x['S1']]));
                $selected = q26_selected($t, $s, $x['F']);
                $state = q26_state($t);
                $codes = [];
                foreach ($nf as $k) {
                    $codes[] = q26_n($t, $x[$k]);
                }
                $codes = array_merge($codes, ['QA26-INV-1', 'QA26-INV-2', q26_n($t, $x['S1'])]);
                list($scans, $rows) = q26_scan_all($t, $s, $sel, $codes);
                $t->eq(array_column(array_slice($rows, 0, 7), 'result'), array_fill(0, 7, 'not_found'), 'scan 7 dokumen luar cakupan -> not_found');
                $t->eq(array_column(array_slice($rows, 7), 'result'), array_fill(0, 3, 'invalid'), 'scan 2 kode + nama folder -> invalid');
                $t->true($before === $snapRows(), 'sesudah scan: baris archives Not found tidak berubah');
                $t->true(q26_same_state($state, q26_state($t)), 'sesudah scan: tabel opname + verifikasi archives sama');
                $folderBefore = [q26_row($t, $x['S1']), q26_row($t, $x['F'])];
                $r = q26_store($t, $s, $sel, $scans, $selected);
                $t->status($r, 200, 'Konfirmasi');
                $t->true($before === $snapRows(), 'sesudah Konfirmasi: baris archives (dan archive_documents) Not found tidak berubah');
                $t->eq($t->db()->table('archives')->count(), $total, 'jumlah baris archives tetap');
                $t->true(json_encode($folderBefore) === json_encode([q26_row($t, $x['S1']), q26_row($t, $x['F'])]), 'baris folder F & S1 tak berubah');
                $o = q26_opname($t, $r[1]['result']['id_archive_opname']);
                $t->eq([(int) $o['not_found_count'], (int) $o['invalid_count']], [7, 3], 'sesi menghitung Not found 7 & Invalid 3');
                $hist = $t->db()->table('archives')->whereIn('id_archive', [$x['dF1'], $x['dF2'], $x['d11']])->pluck('history')->all();
                $t->true(count(array_filter($hist)) === 0, 'history dokumen dalam cakupan tidak ditambah (opname bukan edit)');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-25',
        'title' => 'Dokumen dipindah sesudah discan: pratinjau Step 3 dan Konfirmasi menilai ulang (verified -> Not found, verified-nya tak berubah; Not found -> Verified bila dipindah masuk; nonaktif -> Not found)',
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

                $sel = q26_selection($x['F'], q26_sel([$x['S1']]));
                $selected = q26_selected($t, $s, $x['F']);
                list($scans, $rows) = q26_scan_all($t, $s, $sel, [$N('dF1'), $N('d11'), $N('dF2'), $N('dO1'), $N('dA1')]);
                $t->eq(array_column($rows, 'result'), ['verified', 'verified', 'verified', 'not_found', 'verified'], 'saat discan: dF1,d11,dF2,dA1 verified; dO1 not_found');
                $c = q26_preview($t, $s, $sel, $scans)[1]['result']['counts'];
                $t->eq([$c['verified'], $c['not_found'], $c['total_documents']], [4, 1, 5], 'sebelum dipindah: verified 4, not_found 1, total 5');

                // d11 dipindah ke OTH (keluar cakupan), dO1 dipindah ke S1 (masuk cakupan), dF2 dinonaktifkan
                $r = $put($N('d11'), $x['OTH']);
                $t->status($r, 200, 'put-in d11 -> OTH');
                $r = $put($N('dO1'), $x['S1']);
                $t->status($r, 200, 'put-in dO1 -> S1');
                q26_set_archive($t, $x['dF2'], ['is_active' => 0]);

                $state = q26_state($t);
                $res = q26_preview($t, $s, $sel, $scans)[1]['result'];
                $c = $res['counts'];
                // total = dF1, dJF, dA1, dO1 (dF2 nonaktif, d11 keluar) = 4; verified: dF1, dA1, dO1 = 3; not_found: d11, dF2 = 2
                $t->eq([$c['total_documents'], $c['verified'], $c['not_found'], $c['invalid'], $c['scanned'], $c['unscanned']], [4, 3, 2, 0, 5, 1], 'pratinjau menilai ulang: total 4, verified 3, not_found 2, unscanned 1');
                $t->eq($res['result_counts'], ['all' => 6, 'scanned' => 5, 'verified' => 3, 'not_found' => 2, 'invalid' => 0, 'unscanned' => 1], 'result_counts menilai ulang');
                $byCode = [];
                foreach (q26_pall($t, $s, $sel, $scans, 'scanned', 100) as $row) {
                    $byCode[strtolower($row['code'])] = $row['result'];
                }
                $t->eq($byCode[strtolower($N('d11'))], 'not_found', 'pratinjau: d11 -> not_found');
                $t->eq($byCode[strtolower($N('dO1'))], 'verified', 'pratinjau: dO1 -> verified');
                $t->eq($byCode[strtolower($N('dF2'))], 'not_found', 'pratinjau: dF2 (nonaktif) -> not_found');
                $t->true(q26_same_state($state, q26_state($t)), 'pratinjau tidak menulis');

                $d11Before = q26_row($t, $x['d11']);
                $r = q26_store($t, $s, $sel, $scans, $selected);
                $t->status($r, 200, 'Konfirmasi');
                $id = $r[1]['result']['id_archive_opname'];
                $docs = q26_opdocs($t, $id);
                $t->eq((int) $docs[strtolower($N('d11'))]['result'], 2, 'snapshot: d11 result 2 (not_found)');
                $t->eq((int) $docs[strtolower($N('dO1'))]['result'], 1, 'snapshot: dO1 result 1 (verified)');
                $t->eq((int) $docs[strtolower($N('dF2'))]['result'], 2, 'snapshot: dF2 result 2');
                $t->eq((int) $docs[strtolower($N('d11'))]['is_verified_after'], 1, 'snapshot: d11 is_verified_after = nilai dokumen (1)');
                $d11After = q26_row($t, $x['d11']);
                $t->true(json_encode($d11Before) === json_encode($d11After), 'd11: baris archives (verified, folder OTH) tidak berubah oleh Konfirmasi');
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
