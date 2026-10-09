<?php
/**
 * ED-1026 (rev: opname tanpa draft) - AC-12 (rute draft/batal lama hilang: 404/405 bukan 500; GET opnames/{id} & /documents:
 * id tak ada / sisa draft-batal lama -> 404 ARCHIVE412; akses sesi user lain = ED-1028, hanya "200 bila terlihat"),
 * AC-15 (POST opnames/preview: counts, result_counts, is_partial, warnings, scope; = GET opnames/{id} sesudah Konfirmasi),
 * AC-24 (bagian BE: scan/preview/pratinjau berulang tanpa tulis; sisa draft lama tidak dianggap diopname),
 * AC-31 (bagian BE: scope = tepat is_root, folder_name, subfolder_count di preview, show, list, last_sessions),
 * EXTRA-PERM (Folder Permission), EXTRA-STALE (kondisi berubah sesudah Step 1).
 * "User lain" disimulasikan dengan mengubah archive_opnames.created_by pada sesi milik QA.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-12',
        'title' => 'Rute lama PUT/DELETE opnames/{id}, POST opnames/{id}/scan, PUT opnames/{id}/confirm -> 404/405 (bukan 500); GET opnames/{id} & /documents: id tak ada atau sisa sesi draft/batal lama -> 404 ARCHIVE412; sesi terkonfirmasi (milik sendiri / user lain terlihat / superadmin) 200 (akses = ED-1028)',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $B = Q26_BASE . '/opnames';

                $oldRoutes = function ($id) use ($B) {
                    return [
                        'PUT opnames/{id}' => ['PUT', "$B/$id", ['folders' => []]],
                        'DELETE opnames/{id}' => ['DELETE', "$B/$id", null],
                        'POST opnames/{id}/scan' => ['POST', "$B/$id/scan", ['code' => 'abc']],
                        'PUT opnames/{id}/confirm' => ['PUT', "$B/$id/confirm", null],
                    ];
                };
                $reads = function ($id) use ($t, $s) {
                    return ['show' => q26_show($t, $s, $id), 'documents' => q26_docs($t, $s, $id)];
                };

                // sesi terkonfirmasi milik sendiri sebagai sasaran rute lama
                $conf = q26_ok($t, $s, $x['S2'], [], null, [q26_n($t, $x['d21'])], 'Konfirmasi S2');
                $snap = json_encode([q26_opname($t, $conf), q26_opdocs($t, $conf), q26_opfolders($t, $conf)]);
                $stateAll = q26_state($t);
                foreach ([$conf, Q26_RANDOM_ID, 'folders', 'scan', '0'] as $id) {
                    foreach ($oldRoutes($id) as $label => $r) {
                        $res = $t->call($s, $r[0], $r[1], $r[2]);
                        q26_no500($t, $res, "rute lama $label id " . substr($id, 0, 8));
                        $t->true(in_array($res[0], [404, 405], true), "rute lama $label id " . substr($id, 0, 8) . ': 404/405 (HTTP ' . $res[0] . ')');
                    }
                }
                foreach (['PATCH', 'PUT', 'DELETE'] as $m) {
                    $res = $t->call($s, $m, "$B/$conf/documents", []);
                    q26_no500($t, $res, "$m opnames/{id}/documents");
                    $t->true(in_array($res[0], [404, 405], true), "$m opnames/{id}/documents: 404/405 (HTTP {$res[0]})");
                }
                $t->true($snap === json_encode([q26_opname($t, $conf), q26_opdocs($t, $conf), q26_opfolders($t, $conf)]), 'sesi terkonfirmasi tidak berubah oleh rute lama');
                $t->true(q26_same_state($stateAll, q26_state($t)), 'rute lama tidak menulis apa pun');

                // sesi tak ada
                foreach ($reads(Q26_RANDOM_ID) as $label => $r) {
                    q26_deny($t, $r, 404, 'ARCHIVE412', "tak ada: $label");
                }
                // id aneh: tanpa 500
                foreach ([rawurlencode('日本語'), rawurlencode(str_repeat('a', 300)), rawurlencode("' OR 1=1 --"), '0', 'folders'] as $badId) {
                    $r = $t->call($s, 'GET', "$B/$badId/documents");
                    q26_no500($t, $r, "documents id '$badId'");
                    if ($badId !== 'folders') {
                        $r = $t->call($s, 'GET', "$B/$badId");
                        q26_no500($t, $r, "show id '$badId'");
                    }
                }

                // sisa draft (1) dan batal (3) versi lama (belum dibersihkan Updater): bukan sesi terkonfirmasi -> 404 ARCHIVE412
                foreach ([1 => 'draft', 3 => 'batal'] as $status => $label) {
                    $old = q26_insert_session($t, $status, $x['F']);
                    foreach ($reads($old) as $name => $r) {
                        q26_deny($t, $r, 404, 'ARCHIVE412', "sisa sesi $label (status $status): $name");
                    }
                    $t->true(abs(strtotime(q26_opname($t, $old)['created_at']) - time()) < 600 && (int) q26_opname($t, $old)['status'] === $status, "sisa sesi $label: baris tidak diubah oleh pembacaan");
                }

                // sesi terkonfirmasi milik sendiri
                foreach ($reads($conf) as $label => $r) {
                    $t->status($r, 200, "terkonfirmasi milik sendiri: $label");
                }
                $show = q26_show($t, $s, $conf)[1]['result'];
                $t->eq([$show['status'], $show['status_label']], [2, 'Dikonfirmasi'], 'show: status 2 (selalu terkonfirmasi) + label');

                // milik user lain: akses = ED-1028 (sesi terlihat -> 200 bagi non-superadmin; superadmin 1/2 selalu 200)
                q26_set_owner($t, $conf, 'otheruser');
                foreach ($reads($conf) as $label => $r) {
                    $t->status($r, 200, "terkonfirmasi user lain, folder terlihat: $label");
                    q26_no500($t, $r, "user lain $label");
                }
                $t->eq(q26_show($t, $s, $conf)[1]['result']['created_by'], 'otheruser', 'created_by pemilik asli');
                foreach ([1, 2] as $role) {
                    q26_with_user($t, ['role' => $role], function () use ($t, $reads, $conf, $role, $oldRoutes) {
                        $r = $reads($conf);
                        $t->status($r['show'], 200, "superadmin $role: show");
                        $t->eq($r['show'][1]['result']['status'], 2, "superadmin $role: status 2");
                        $t->status($r['documents'], 200, "superadmin $role: documents");
                        $t->true(isset($r['documents'][1]['result']['data']), "superadmin $role: documents berisi data");
                    });
                }
                $t->eq((int) q26_opname($t, $conf)['status'], 2, 'sesi terkonfirmasi tetap status 2');
                $t->note('akses baca sesi (siapa boleh melihat sesi siapa) = ED-1028 AC-14; di sini hanya dipastikan 200 untuk sesi terlihat dan 404 ARCHIVE412 untuk yang bukan sesi terkonfirmasi / tak ada');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-15',
        'title' => 'POST opnames/preview: counts (total, verified_before, scanned, verified, not_found, invalid, unscanned = total - verified, unverified), result_counts, is_partial false, warnings per folder tanpa lanjut, folders level 0-1, scope.subfolder_count; berubah mengikuti scans; tanpa tulis; sama dengan GET opnames/{id} sesudah Konfirmasi',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $marker = '2026-01-01 08:00:00';
                // keadaan awal: dF1, d11, dA1 (dalam cakupan) dan d21 (S2, tak dicentang) verified
                foreach (['dF1', 'd11', 'dA1', 'd21'] as $k) {
                    q26_set_archive($t, $x[$k], ['is_verified' => 1, 'verified_at' => $marker, 'verified_by' => 'QA26']);
                }
                $N = function ($k) use ($t, $x) {
                    return q26_n($t, $x[$k]);
                };
                $fname = $N('F');
                $s1name = $N('S1');
                $now = q26_now()->format('Y-m-d H:i:s');
                $mk = function (array $codes) use ($now) {
                    return array_map(function ($c) use ($now) {
                        return ['code' => $c, 'scanned_at' => $now];
                    }, $codes);
                };
                $sel = q26_selection($x['F'], q26_sel([$x['S1']]));
                $before = q26_state($t);

                $r = q26_preview($t, $s, $sel, $mk([$N('dF1'), $N('d21'), 'QA26-INVALID']));
                $t->status($r, 200, 'preview');
                $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE200', 'msg_code ARCHIVE200');
                $res = $r[1]['result'];
                $t->eq(array_keys($res), ['id_archive', 'scope', 'folders', 'counts', 'result_counts', 'is_partial', 'warnings'], 'kunci result sesuai kontrak §3');
                $t->eq(array_keys($res['counts']), ['total_documents', 'verified_before', 'scanned', 'verified', 'not_found', 'invalid', 'unscanned', 'unverified'], 'kunci counts');
                $t->eq($res['counts'], ['total_documents' => 5, 'verified_before' => 3, 'scanned' => 3, 'verified' => 1, 'not_found' => 1, 'invalid' => 1, 'unscanned' => 4, 'unverified' => 2], 'counts (BR-20)');
                $t->eq($res['counts']['unscanned'], $res['counts']['total_documents'] - $res['counts']['verified'], 'unscanned = total - verified');
                $t->eq(array_keys($res['result_counts']), ['all', 'scanned', 'verified', 'not_found', 'invalid', 'unscanned'], 'kunci result_counts');
                $t->eq($res['result_counts'], ['all' => 7, 'scanned' => 3, 'verified' => 1, 'not_found' => 1, 'invalid' => 1, 'unscanned' => 4], 'result_counts = jumlah tiap filter');
                $t->true($res['is_partial'] === false, 'is_partial false (pratinjau tanpa saringan per pemanggil)');
                $t->eq($res['id_archive'], $x['F'], 'id_archive = folder opname');
                $t->eq($res['scope'], ['is_root' => false, 'folder_name' => $fname, 'subfolder_count' => 1], 'scope: tepat tiga kunci, subfolder_count = subfolder dicentang');
                $t->eq(array_map(function ($f) {
                    return [$f['id_archive'], $f['level'], $f['is_continue'], $f['is_opnamed_today'], $f['document_count']];
                }, $res['folders']), [[$x['F'], 0, false, false, 3], [$x['S1'], 1, false, false, 2]], 'folders level 0-1 (document_count level 0 langsung, level 1 subtree)');
                $t->eq(array_keys($res['folders'][0]), ['id_archive', 'name', 'level', 'is_continue', 'is_opnamed_today', 'document_count'], 'kunci folders[]');
                $t->eq($res['warnings'], [['id_archive' => $x['S1'], 'name' => $s1name, 'is_opnamed_today' => false, 'unverify_count' => 2]], 'warnings: S1 menurunkan 2 verified (d11, dA1)');
                $t->eq(array_keys($res['warnings'][0]), ['id_archive', 'name', 'is_opnamed_today', 'unverify_count'], 'kunci warnings[]');

                // scan lebih banyak: d11 verified -> kept; warnings turun
                $res = q26_preview($t, $s, $sel, $mk([$N('dF1'), $N('d21'), 'QA26-INVALID', $N('d11')]))[1]['result'];
                $t->eq([$res['counts']['verified'], $res['counts']['unscanned'], $res['counts']['unverified']], [2, 3, 1], 'sesudah scan d11: verified 2, unscanned 3, unverified 1');
                $t->eq($res['warnings'][0]['unverify_count'], 1, 'warnings: tinggal 1 (dA1)');
                $res = q26_preview($t, $s, $sel, $mk([$N('dF1'), $N('d21'), 'QA26-INVALID', $N('d11'), $N('dA1')]))[1]['result'];
                $t->eq($res['warnings'], [], 'semua verified discan: tanpa warnings');
                $t->eq($res['counts']['unverified'], 0, 'unverified 0');
                $t->true(q26_same_state($before, q26_state($t)), 'preview berulang tidak menulis (tabel opname + kolom verifikasi archives)');

                // Konfirmasi -> nilai tersimpan = pratinjau; verified tak berubah (semua discan)
                $codes = [$N('dF1'), $N('d21'), 'QA26-INVALID', $N('d11'), $N('dA1'), $N('dF2')];
                $prev = q26_preview($t, $s, $sel, $mk($codes))[1]['result'];
                $selected = q26_selected($t, $s, $x['F']);
                $conf = q26_store($t, $s, $sel, $mk($codes), $selected);
                $t->status($conf, 200, 'Konfirmasi');
                $id = $conf[1]['result']['id_archive_opname'];
                $r = q26_show($t, $s, $id);
                $res = $r[1]['result'];
                $t->eq(array_keys($res), ['id_archive_opname', 'id_archive', 'status', 'status_label', 'scope', 'folders', 'counts', 'result_counts', 'is_partial', 'warnings', 'created_by', 'created_at', 'confirmed_at'], 'show: kunci result (ED-1028 menambah result_counts & is_partial)');
                $t->eq($res['counts'], ['total_documents' => 5, 'verified_before' => 3, 'scanned' => 6, 'verified' => 4, 'not_found' => 1, 'invalid' => 1, 'unscanned' => 1, 'unverified' => 0], 'counts tersimpan sesudah Konfirmasi');
                $t->eq($res['counts'], $prev['counts'], 'counts tersimpan = counts pratinjau terakhir');
                $t->eq($res['result_counts'], $prev['result_counts'], 'result_counts tersimpan = pratinjau');
                $t->eq($res['warnings'], $prev['warnings'], 'warnings tersimpan = pratinjau');
                $t->eq($res['scope'], $prev['scope'], 'scope tersimpan = pratinjau');
                $t->true($res['is_partial'] === false, 'show: is_partial false untuk pemilik');
                $t->eq($res['status'], 2, 'status 2');
                $t->true($res['confirmed_at'] !== null && $res['created_by'] === q26_uname($t), 'confirmed_at terisi, created_by user login');
                $t->eq(array_map(function ($f) {
                    return [$f['level'], $f['document_count']];
                }, $res['folders']), [[0, 3], [1, 1], [2, 1]], 'folders sesudah Konfirmasi: level 0-2, document_count langsung');

                // --- sesi kedua: F sudah diopname hari ini -> lanjut; warnings hanya untuk yang tanpa lanjut
                foreach (['d11', 'dA1'] as $k) {
                    q26_set_archive($t, $x[$k], ['is_verified' => 1, 'verified_at' => $marker, 'verified_by' => 'QA26']);
                }
                $selC = q26_selection($x['F'], q26_sel([$x['S1'] => true]), true);
                $res = q26_preview($t, $s, $selC, [])[1]['result'];
                $t->eq(array_map(function ($f) {
                    return [$f['id_archive'], $f['is_continue'], $f['is_opnamed_today']];
                }, $res['folders']), [[$x['F'], true, true], [$x['S1'], true, true]], 'sesi 2: F dan S1 sudah diopname hari ini, is_continue true dipakai');
                $t->eq($res['warnings'], [], 'sesi 2: lanjut aktif -> tanpa warnings');
                $t->eq($res['counts']['unverified'], 0, 'sesi 2: unverified 0');
                $selN = q26_selection($x['F'], q26_sel([$x['S1'] => false]), true);
                $res = q26_preview($t, $s, $selN, [])[1]['result'];
                $t->eq($res['warnings'], [['id_archive' => $x['S1'], 'name' => $s1name, 'is_opnamed_today' => true, 'unverify_count' => 2]], 'sesi 2: S1 tanpa lanjut -> warning (is_opnamed_today true)');
                $t->eq($res['counts']['unverified'], 2, 'sesi 2: unverified 2');
                $t->eq($res['counts']['verified_before'], 4, 'sesi 2: verified_before 4 (dF1, dF2, d11, dA1 verified)');
                // Lanjut untuk folder yang belum diopname hari ini diabaikan (S2 belum)
                $res = q26_preview($t, $s, q26_selection($x['F'], q26_sel([$x['S2'] => true]), false), [])[1]['result'];
                $t->eq([$res['folders'][1]['id_archive'], $res['folders'][1]['is_continue'], $res['folders'][1]['is_opnamed_today']], [$x['S2'], false, false], 'lanjut diminta untuk S2 yang belum diopname hari ini: is_continue false');

                // --- user Semarang: dokumen lokasi JOG di F tak dihitung
                q26_with_user($t, ['emp' => ['SMR']], function () use ($t, $s, $x) {
                    $res = q26_preview($t, $s, q26_selection($x['F'], q26_sel([$x['S1']])))[1]['result'];
                    $t->eq($res['counts']['total_documents'], 4, 'Semarang: total 4 (dJF lokasi JOG tak dihitung)');
                    $t->eq($res['folders'][0]['document_count'], 2, 'Semarang: dokumen langsung F = dF1, dF2');
                });
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-24',
        'title' => '(bagian BE) Tidak ada data opname sebelum Konfirmasi: muat Step 1, scan (Verified / Not found / Invalid), pratinjau & Back/Next berulang tanpa Konfirmasi tidak menulis apa pun; F tetap "belum diopname"; sisa draft/batal lama tidak dianggap diopname dan tidak memblokir Konfirmasi',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $marker = '2026-01-01 08:00:00';
                foreach (['dF1', 'd11'] as $k) {
                    q26_set_archive($t, $x[$k], ['is_verified' => 1, 'verified_at' => $marker, 'verified_by' => 'QA26']);
                }
                $N = function ($k) use ($t, $x) {
                    return q26_n($t, $x[$k]);
                };
                $before = q26_state($t);
                $sessions = $t->db()->table('archive_opnames')->count();
                $rowsBefore = [q26_row($t, $x['dF1']), q26_row($t, $x['d11']), q26_row($t, $x['dF2']), q26_row($t, $x['d21'])];

                // sesi berjalan di FE: Step 1 -> scan beberapa kode -> Step 2/3 (pratinjau) -> Back -> Next lagi -> batal (tanpa request)
                $sel = q26_selection($x['F'], q26_sel([$x['S1']]));
                $selected = q26_selected($t, $s, $x['F']);
                list($scans, $rows) = q26_scan_all($t, $s, $sel, [$N('dF2'), $N('d21'), 'QA26-INV', $N('dF1')]);
                $t->eq(array_column($rows, 'result'), ['verified', 'not_found', 'invalid', 'verified'], 'scan: verified / not_found / invalid / verified');
                for ($i = 0; $i < 3; $i++) {
                    $t->status(q26_preview($t, $s, $sel, $scans), 200, "pratinjau Step 3 ke-$i");
                    $t->status(q26_pdocs($t, $s, $sel, $scans, ['result' => 'scanned']), 200, "tabel Step 2 ke-$i");
                    $t->status(q26_folders($t, $s, $x['F']), 200, "Step 1 dimuat ulang ke-$i");
                }
                $t->true(q26_same_state($before, q26_state($t)), 'sesudah scan + pratinjau + Back/Next tanpa Konfirmasi: tiga tabel opname & kolom verifikasi archives sama dengan sebelum dibuka');
                $t->eq($t->db()->table('archive_opnames')->count(), $sessions, 'jumlah sesi tidak bertambah');
                $t->true(json_encode($rowsBefore) === json_encode([q26_row($t, $x['dF1']), q26_row($t, $x['d11']), q26_row($t, $x['dF2']), q26_row($t, $x['d21'])]), 'baris archives dokumen (verified & kolom lain) tidak berubah');
                $r = q26_folders($t, $s, $x['F']);
                $t->true($r[1]['result']['folder']['opnamed_today'] === null, 'F belum dianggap diopname');
                foreach (q26_child_ids($r) as $cid) {
                    $c = q26_child($r, $cid);
                    $t->true($c['opnamed_today'] === null && $c['is_default_checked'] === true, 'anak belum diopname & tercentang');
                }

                // sisa draft (1) dan batal (3) versi lama berfolder F: tidak dianggap diopname, tidak bentrok
                foreach ([1, 3] as $status) {
                    $old = q26_insert_session($t, $status, $x['F'], ['confirmed_at' => date('Y-m-d H:i:s')]);
                    q26_insert_session_folder($t, $old, $x['F'], $N('F'), 0);
                    q26_insert_session_folder($t, $old, $x['S1'], $N('S1'), 1);
                }
                $r = q26_folders($t, $s, $x['F']);
                $t->true($r[1]['result']['folder']['opnamed_today'] === null, 'sisa draft/batal lama: F tetap belum diopname hari ini');
                $t->true(q26_child($r, $x['S1'])['opnamed_today'] === null && q26_child($r, $x['S1'])['is_default_checked'] === true, 'sisa draft/batal lama: S1 tetap belum diopname & tercentang');
                $p = q26_preview($t, $s, q26_selection($x['F'], q26_sel([$x['S1'] => true]), true), $scans);
                $t->true($p[1]['result']['folders'][0]['is_opnamed_today'] === false && $p[1]['result']['folders'][0]['is_continue'] === false, 'sisa draft/batal lama: pratinjau is_opnamed_today false, lanjut diabaikan');
                q26_wait();
                $selected = q26_selected($t, $s, $x['F']);
                $conf = q26_store($t, $s, $sel, $scans, $selected);
                $t->status($conf, 200, 'Konfirmasi tidak bentrok dengan sisa draft/batal lama');
                $id = $conf[1]['result']['id_archive_opname'];
                $t->eq((int) q26_opname($t, $id)['status'], 2, 'sesi baru status 2');
                $t->eq($t->db()->table('archive_opnames')->count(), $sessions + 3, 'tepat satu sesi baru (+2 sisa lama yang disisipkan QA)');

                // sesudah Konfirmasi: F sudah diopname hari ini oleh sesi itu
                $r = q26_folders($t, $s, $x['F']);
                $t->eq($r[1]['result']['folder']['opnamed_today']['id_archive_opname'] ?? null, $id, 'F diopname hari ini oleh sesi terkonfirmasi (bukan sisa lama)');
                // verified hasil Konfirmasi: dF2 discan -> 1; dF1 discan (sudah 1) tetap 1; d11 tidak discan -> 0
                $t->eq(q26_v($t, $x['dF2'])[0], 1, 'dF2 (discan) verified');
                $t->eq(q26_v($t, $x['d11'])[0], 0, 'd11 (verified, tak discan, folder tanpa lanjut) -> unverified');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'EXTRA-SCOPE',
        'title' => '(AC-31, bagian BE) scope = tepat is_root / folder_name / subfolder_count (tanpa first_name / other_count) di POST preview, GET opnames/{id}, baris GET opnames dan last_sessions verifikasi; subfolder_count = subfolder dicentang; snapshot sesi tidak ikut rename folder',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $fn = q26_n($t, $x['F']);
                $keys = ['is_root', 'folder_name', 'subfolder_count'];
                $scopeOf = function ($sel) use ($t, $s) {
                    return q26_preview($t, $s, $sel)[1]['result']['scope'];
                };
                // pratinjau
                $e = $scopeOf(q26_selection($x['F']));
                $t->eq($e, ['is_root' => false, 'folder_name' => $fn, 'subfolder_count' => 0], 'preview F tanpa subfolder');
                $t->eq(array_keys($e), $keys, 'preview: tepat tiga kunci');
                $t->eq($scopeOf(q26_selection($x['F'], q26_sel([$x['S2'], $x['S1']]))), ['is_root' => false, 'folder_name' => $fn, 'subfolder_count' => 2], 'preview F[S2,S1]: subfolder_count 2');
                $t->eq($scopeOf(q26_selection(null)), ['is_root' => true, 'folder_name' => null, 'subfolder_count' => 0], 'preview root tanpa subfolder');
                $t->eq($scopeOf(q26_selection(null, q26_sel([$x['OTH'], $x['F']]))), ['is_root' => true, 'folder_name' => null, 'subfolder_count' => 2], 'preview root[OTH,F]: subfolder_count 2');
                $t->eq($scopeOf(q26_selection(null, q26_sel([$x['OTH'], $x['F']]))), ['is_root' => true, 'folder_name' => null, 'subfolder_count' => 2], 'preview root: idem (stateless, tanpa static bocor antar request)');

                // sesi terkonfirmasi F[S2,S1]
                $id = q26_ok($t, $s, $x['F'], q26_sel([$x['S2'], $x['S1']]), null, [], 'Konfirmasi F[S2,S1]');
                $row = q26_opname($t, $id);
                $t->eq([$row['scope_name'], (int) $row['scope_folder_count']], [q26_n($t, $x['S1']), 2], 'DB: scope_name = subfolder pertama (urut nama), scope_folder_count 2');
                $r = q26_show($t, $s, $id);
                $t->eq($r[1]['result']['scope'], ['is_root' => false, 'folder_name' => $fn, 'subfolder_count' => 2], 'show: scope F[S2,S1]');
                $t->eq(array_keys($r[1]['result']['scope']), $keys, 'show: tepat tiga kunci');

                // daftar riwayat GET opnames dan last_sessions GET verifications (ED-1028 / ED-1027 memakai scope() yang sama)
                $list = $t->call($s, 'GET', Q26_BASE . '/opnames?pagination=50');
                $t->status($list, 200, 'GET opnames');
                $found = null;
                foreach ($list[1]['result']['data'] as $row) {
                    if ($row['id_archive_opname'] === $id) {
                        $found = $row;
                    }
                }
                $t->true($found !== null, 'GET opnames memuat sesi baru');
                $t->eq($found['scope'], ['is_root' => false, 'folder_name' => $fn, 'subfolder_count' => 2], 'GET opnames: scope sesi');
                $t->eq(array_keys($found['scope']), $keys, 'GET opnames: tepat tiga kunci');
                $ver = $t->call($s, 'GET', Q26_BASE . '/verifications');
                $t->status($ver, 200, 'GET verifications');
                $last = null;
                foreach ($ver[1]['result']['last_sessions'] ?? [] as $row) {
                    if ($row['id_archive_opname'] === $id) {
                        $last = $row;
                    }
                }
                $t->true($last !== null, 'last_sessions memuat sesi baru');
                $t->eq($last['scope'], ['is_root' => false, 'folder_name' => $fn, 'subfolder_count' => 2], 'last_sessions: scope sesi');
                $t->eq(array_keys($last['scope']), $keys, 'last_sessions: tepat tiga kunci');

                // sesi F tanpa subfolder dan root (baris disisipkan: root penuh terlalu berat) dibaca sebagai sesi sendiri
                $now = date('Y-m-d H:i:s');
                $root = q26_insert_session($t, 2, null, ['scope_name' => null, 'scope_folder_count' => 3, 'confirmed_at' => $now, 'created_by' => q26_uname($t), 'updated_by' => q26_uname($t)]);
                $r = q26_show($t, $s, $root);
                $t->status($r, 200, 'show sesi root (baris disisipkan)');
                $t->eq($r[1]['result']['scope'], ['is_root' => true, 'folder_name' => null, 'subfolder_count' => 3], 'show: root + 3 subfolder');
                $one = q26_insert_session($t, 2, $x['S2'], ['scope_name' => q26_n($t, $x['S2']), 'scope_folder_count' => 0, 'confirmed_at' => $now, 'created_by' => q26_uname($t), 'updated_by' => q26_uname($t)]);
                q26_insert_session_folder($t, $one, $x['S2'], q26_n($t, $x['S2']), 0);
                $r = q26_show($t, $s, $one);
                $t->eq($r[1]['result']['scope'], ['is_root' => false, 'folder_name' => q26_n($t, $x['S2']), 'subfolder_count' => 0], 'show: folder tanpa subfolder');

                // folder diganti nama sesudah Konfirmasi: scope memakai snapshot sesi
                q26_set_archive($t, $x['F'], ['name' => q26_name('RENAMED')]);
                $r = q26_show($t, $s, $id);
                $t->eq($r[1]['result']['scope']['folder_name'], $fn, 'terkonfirmasi: scope.folder_name dari snapshot (tak ikut rename)');
                $t->eq($r[1]['result']['folders'][0]['name'], $fn, 'terkonfirmasi: folders[0].name snapshot');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'EXTRA-PERM',
        'title' => '(tambahan AC-5/AC-9/AC-10, BR-5) folder dengan Izin On tanpa View: tak tampil, dokumennya tak dihitung, scan = not_found, data tetap; dengan hak View tampil; turunan terkunci ikut terputus; 403 ARCHIVE407 di semua endpoint',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $uid = q26_uid($t);
                $marker = '2026-01-01 08:00:00';
                $S3 = q26_folder($t, 'S3', ['parent' => $x['F'], 'perm' => 1, 'by' => 'someoneelse']);
                $S3c = q26_folder($t, 'S3c', ['parent' => $S3]);
                $d31 = q26_doc($t, 'd31', ['parent' => $S3]);
                $d3c = q26_doc($t, 'd3c', ['parent' => $S3c]);
                $S4 = q26_folder($t, 'S4', ['parent' => $x['F'], 'perm' => 1, 'by' => 'someoneelse']);
                q26_perm($t, $S4, $uid, 1);
                $S4a = q26_folder($t, 'S4a', ['parent' => $S4]);
                $d4a = q26_doc($t, 'd4a', ['parent' => $S4a]);
                foreach ([$d31, $d3c, $d4a] as $id) {
                    q26_set_archive($t, $id, ['is_verified' => 1, 'verified_at' => $marker, 'verified_by' => 'QA26']);
                }

                // children F untuk user semua lokasi: S1, S2, S4, SJ (S3 terkunci; SX nonaktif); urut nama
                $r = q26_folders($t, $s, $x['F']);
                $expected = [$x['S1'], $x['S2'], $S4, $x['SJ']];
                $names = [];
                foreach ($expected as $id) {
                    $names[$id] = q26_n($t, $id);
                }
                uasort($names, 'strcasecmp');
                $t->eq(q26_child_ids($r), array_keys($names), 'children F: S1, S2, S4, SJ urut nama (S3 terkunci & turunannya tak tampil)');
                $t->eq(q26_child($r, $S4)['document_count'], 1, 'S4 (hak View): document_count subtree = d4a');
                $t->true(q26_child($r, $S4)['has_children'] === true, 'S4 has_children');
                $t->eq($r[1]['result']['folder']['document_count'], 3, 'F: dokumen langsung terlihat = dF1, dF2, dJF');

                // F = S3 / turunannya -> 403 ARCHIVE407 di semua endpoint; S4 (hak View) boleh
                q26_deny($t, q26_folders($t, $s, $S3), 403, 'ARCHIVE407', 'F = S3 terkunci');
                q26_deny($t, q26_folders($t, $s, $S3c), 403, 'ARCHIVE407', 'F = S3c (turunan terkunci)');
                $now = q26_now()->format('Y-m-d H:i:s');
                foreach ([$S3, $S3c] as $bad) {
                    q26_deny($t, q26_scan($t, $s, q26_selection($bad), 'x'), 403, 'ARCHIVE407', 'scan F terkunci');
                    q26_deny($t, q26_preview($t, $s, q26_selection($bad)), 403, 'ARCHIVE407', 'preview F terkunci');
                    q26_deny($t, q26_pdocs($t, $s, q26_selection($bad)), 403, 'ARCHIVE407', 'preview/documents F terkunci');
                    q26_deny($t, q26_store($t, $s, q26_selection($bad), [], $now), 403, 'ARCHIVE407', 'POST opnames F terkunci');
                }
                $r = q26_folders($t, $s, $S4);
                $t->status($r, 200, 'F = S4 (hak View)');
                $t->eq(q26_child_ids($r), [$S4a], 'children S4 = S4a');
                foreach (['scan', 'preview', 'preview/documents'] as $path) {
                    $r = $t->call($s, 'POST', Q26_BASE . "/opnames/$path", q26_selection($x['F'], q26_sel([$S3])) + ['code' => 'x']);
                    q26_deny($t, $r, 400, 'ARCHIVE416', "$path: subfolder S3 terkunci -> 416");
                }

                // sesi F[S1,S2,S4,SJ]
                $sel = q26_selection($x['F'], q26_sel([$x['S1'], $x['S2'], $S4, $x['SJ']]));
                $c = q26_preview($t, $s, $sel)[1]['result']['counts'];
                $t->eq($c['total_documents'], 8, 'total = F 3 + S1 2 + S2 1 + S4 1 + SJ 1 (d31 & d3c tak dihitung)');
                foreach ([$d31, $d3c] as $doc) {
                    $r = q26_scan($t, $s, $sel, q26_n($t, $doc));
                    $t->eq($r[1]['result']['row']['result'], 'not_found', 'dokumen di folder terkunci -> not_found');
                }
                $r = q26_scan($t, $s, $sel, q26_n($t, $d4a));
                $t->eq($r[1]['result']['row']['result'], 'verified', 'dokumen di S4a (turunan folder ber-hak) -> verified');
                $id = q26_ok($t, $s, $x['F'], q26_sel([$x['S1'], $x['S2'], $S4, $x['SJ']]), null, [q26_n($t, $d31), q26_n($t, $d3c), q26_n($t, $d4a)], 'Konfirmasi');
                foreach ([$d31, $d3c] as $doc) {
                    $row = q26_row($t, $doc);
                    $t->eq([(int) $row['is_verified'], $row['verified_at'], $row['id_archive_opname']], [1, $marker, null], 'dokumen folder terkunci: verified & kolom tetap');
                }
                $folders = array_column(q26_opfolders($t, $id), 'id_archive');
                $t->true(!in_array($S3, $folders, true) && !in_array($S3c, $folders, true), 'snapshot folder tidak memuat S3/S3c');
                $t->true(in_array($S4, $folders, true) && in_array($S4a, $folders, true), 'snapshot folder memuat S4/S4a');

                // superadmin melihat S3 (bypass)
                q26_with_user($t, ['role' => 1], function () use ($t, $s, $x, $S3) {
                    $r = q26_folders($t, $s, $x['F']);
                    $t->true(in_array($S3, q26_child_ids($r), true), 'superadmin 1: S3 tampil');
                });
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'EXTRA-STALE',
        'title' => '(tambahan, BR-22) kondisi berubah sesudah Step 1 dimuat: folder opname nonaktif / kehilangan View -> 404 ARCHIVE400 / 403 ARCHIVE407; subfolder terpilih nonaktif / dipindah -> 400 ARCHIVE416 (tidak diam-diam dikeluarkan); dokumen discan dihapus -> invalid; tanpa 500 dan tanpa sesi tersimpan saat gagal',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $marker = '2026-01-01 08:00:00';
                $all = function ($sel, $scans) use ($t, $s) {
                    return ['scan' => q26_scan($t, $s, $sel, 'QA26-STALE-' . mt_rand()), 'preview' => q26_preview($t, $s, $sel, $scans), 'preview/documents' => q26_pdocs($t, $s, $sel, $scans)];
                };

                // 1. folder opname dinonaktifkan sesudah scan
                $sel = q26_selection($x['S2']);
                $selected = q26_selected($t, $s, $x['S2']);
                list($scans) = q26_scan_all($t, $s, $sel, [q26_n($t, $x['d21'])]);
                q26_set_archive($t, $x['S2'], ['is_active' => 0]);
                $before = q26_state($t);
                foreach ($all($sel, $scans) as $label => $r) {
                    q26_deny($t, $r, 404, 'ARCHIVE400', "S2 nonaktif: $label");
                }
                q26_deny($t, q26_store($t, $s, $sel, $scans, $selected), 404, 'ARCHIVE400', 'S2 nonaktif: Konfirmasi 404');
                $t->true(q26_same_state($before, q26_state($t)), 'S2 nonaktif: tidak ada sesi tersimpan');
                q26_set_archive($t, $x['S2'], ['is_active' => 1]);

                // 2. folder opname kehilangan View (Izin diaktifkan oleh user lain)
                $selected = q26_selected($t, $s, $x['S2']);
                q26_set_archive($t, $x['S2'], ['is_folder_permission' => 1, 'created_by' => 'someoneelse']);
                $before = q26_state($t);
                foreach ($all($sel, $scans) as $label => $r) {
                    q26_deny($t, $r, 403, 'ARCHIVE407', "S2 kehilangan View: $label");
                }
                q26_deny($t, q26_store($t, $s, $sel, $scans, $selected), 403, 'ARCHIVE407', 'S2 kehilangan View: Konfirmasi 403');
                $t->true(q26_same_state($before, q26_state($t)), 'S2 kehilangan View: tidak ada sesi tersimpan');
                q26_set_archive($t, $x['S2'], ['is_folder_permission' => 0, 'created_by' => 'QA26']);

                // 3. subfolder terpilih dinonaktifkan / dipindah: 416 (K-16 a), tanpa 500, tanpa sesi
                q26_set_archive($t, $x['d11'], ['is_verified' => 1, 'verified_at' => $marker, 'verified_by' => 'QA26']);
                $sel = q26_selection($x['F'], q26_sel([$x['S1'], $x['S2']]));
                $selected = q26_selected($t, $s, $x['F']);
                list($scans) = q26_scan_all($t, $s, $sel, [q26_n($t, $x['d11'])]);
                $c0 = q26_preview($t, $s, $sel, $scans)[1]['result']['counts']['total_documents'];
                $t->eq($c0, 6, 'sebelum diubah: total F[S1,S2] = dF1,dF2,dJF + d11 + dA1 + d21 = 6');
                q26_set_archive($t, $x['S1'], ['is_active' => 0]);
                $before = q26_state($t);
                foreach ($all($sel, $scans) as $label => $r) {
                    q26_deny($t, $r, 400, 'ARCHIVE416', "S1 nonaktif: $label");
                }
                q26_deny($t, q26_store($t, $s, $sel, $scans, $selected), 400, 'ARCHIVE416', 'S1 nonaktif: Konfirmasi 416');
                q26_set_archive($t, $x['S1'], ['is_active' => 1, 'id_archive_parent' => $x['OTH']]);
                foreach ($all($sel, $scans) as $label => $r) {
                    q26_deny($t, $r, 400, 'ARCHIVE416', "S1 dipindah: $label");
                }
                q26_deny($t, q26_store($t, $s, $sel, $scans, $selected), 400, 'ARCHIVE416', 'S1 dipindah: Konfirmasi 416');
                $t->true(q26_same_state($before, q26_state($t)), '416: tidak ada sesi tersimpan, d11 (S1 di luar cakupan) tak tersentuh');
                $t->eq(q26_v($t, $x['d11']), [1, true, 'QA26', null], 'd11 tak tersentuh');
                q26_set_archive($t, $x['S1'], ['id_archive_parent' => $x['F']]);

                // 4. dokumen yang sudah discan dihapus dari DB: jadi invalid, tanpa 500
                $sel = q26_selection($x['F'], q26_sel([$x['S1']]));
                $selected = q26_selected($t, $s, $x['F']);
                $name = q26_n($t, $x['d11']);
                list($scans, $rows) = q26_scan_all($t, $s, $sel, [$name]);
                $t->eq($rows[0]['result'], 'verified', 'sebelum dihapus: verified');
                q26_w($t, function ($c) use ($x) {
                    $c->table('archive_documents')->where('id_archive', $x['d11'])->delete();
                    $c->table('archives')->where('id_archive', $x['d11'])->delete();
                });
                foreach ($all($sel, $scans) as $label => $r) {
                    q26_no500($t, $r, "dokumen dihapus: $label");
                    $t->status($r, 200, "dokumen dihapus: $label");
                }
                $rows = q26_pall($t, $s, $sel, $scans, 'invalid', 100);
                $t->true(in_array(strtolower($name), array_map('strtolower', array_column($rows, 'code')), true), 'dokumen dihapus: baris scan menjadi invalid');
                $r = q26_store($t, $s, $sel, $scans, $selected);
                $t->status($r, 200, 'dokumen dihapus: Konfirmasi 200');
                $docs = q26_opdocs($t, $r[1]['result']['id_archive_opname']);
                $t->eq((int) $docs[strtolower($name)]['result'], 3, 'snapshot: result 3 (invalid)');
                $t->true($docs[strtolower($name)]['id_archive'] === null, 'snapshot: id_archive null');
            } finally {
                q26_cleanup($t);
            }
        },
    ],
];
