<?php
/**
 * ED-1026 - akses sesi & ringkasan: AC-12 (pemilik, 404 ARCHIVE412 / 400 ARCHIVE413, superadmin membaca sesi terkonfirmasi),
 * AC-15 (GET opnames/{id}: counts BR-20, warnings, folders), AC-24 (DELETE draft).
 * "User lain" disimulasikan dengan mengubah archive_opnames.created_by (QA_USER2 tidak bisa login di api_sidomaju).
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-12',
        'title' => 'Scan/confirm/delete/PUT draft milik user lain 404 ARCHIVE412; ke sesi terkonfirmasi/batal 400 ARCHIVE413; GET show & documents sesi terkonfirmasi user lain: non-superadmin 404, superadmin 1/2 200; draft user lain 404 untuk semua',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $B = Q26_BASE . '/opnames';
                $other = 'otheruser';

                $mutations = function ($id) use ($t, $s, $x) {
                    return [
                        'scan' => q26_scan($t, $s, $id, 'abc'),
                        'scan tanpa code' => $t->call($s, 'POST', Q26_BASE . '/opnames/' . $id . '/scan', []),
                        'confirm' => q26_confirm($t, $s, $id),
                        'cancel' => q26_cancel($t, $s, $id),
                        'update' => q26_update($t, $s, $id, []),
                        'update body salah' => $t->call($s, 'PUT', Q26_BASE . '/opnames/' . $id, ['folders' => 'abc']),
                    ];
                };
                $reads = function ($id) use ($t, $s) {
                    return ['show' => q26_show($t, $s, $id), 'documents' => q26_docs($t, $s, $id)];
                };

                // --- sesi tak ada
                foreach ($mutations(Q26_RANDOM_ID) as $label => $r) {
                    q26_deny($t, $r, 404, 'ARCHIVE412', "tak ada: $label");
                }
                foreach ($reads(Q26_RANDOM_ID) as $label => $r) {
                    q26_deny($t, $r, 404, 'ARCHIVE412', "tak ada: $label");
                }

                // --- draft milik user lain: 404 untuk semua operasi, juga superadmin 1/2
                $draft = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                q26_scan($t, $s, $draft, q26_n($t, $x['d11']));
                q26_set_owner($t, $draft, $other);
                $snap = json_encode([q26_opname($t, $draft), q26_opdocs($t, $draft), q26_opfolders($t, $draft)]);
                foreach ([null, 1, 2] as $role) {
                    $run = function () use ($t, $mutations, $reads, $draft, $role) {
                        $tag = $role === null ? 'user biasa' : "superadmin $role";
                        foreach ($mutations($draft) as $label => $r) {
                            q26_deny($t, $r, 404, 'ARCHIVE412', "draft user lain ($tag): $label");
                        }
                        foreach ($reads($draft) as $label => $r) {
                            q26_deny($t, $r, 404, 'ARCHIVE412', "draft user lain ($tag): $label");
                        }
                    };
                    $role === null ? $run() : q26_with_user($t, ['role' => $role], $run);
                }
                $t->true($snap === json_encode([q26_opname($t, $draft), q26_opdocs($t, $draft), q26_opfolders($t, $draft)]), 'draft user lain tidak berubah oleh penolakan');
                $t->eq(q26_dirty($t)['archives'], 0, 'archives tak berubah');

                // --- sesi terkonfirmasi milik user lain
                $conf = q26_session($t, $s, $x['S2'], []);
                $t->status(q26_confirm($t, $s, $conf), 200, 'confirm S2 (milik sendiri)');
                // pemilik sendiri: baca 200
                foreach ($reads($conf) as $label => $r) {
                    $t->status($r, 200, "terkonfirmasi milik sendiri: $label");
                }
                // pemilik sendiri: tulis -> 400 ARCHIVE413
                foreach ($mutations($conf) as $label => $r) {
                    if (in_array($label, ['scan tanpa code', 'update body salah'], true)) {
                        // authorize() (pemilik+status) lebih dulu daripada validasi
                        q26_deny($t, $r, 400, 'ARCHIVE413', "terkonfirmasi sendiri: $label");
                        continue;
                    }
                    q26_deny($t, $r, 400, 'ARCHIVE413', "terkonfirmasi sendiri: $label");
                }
                q26_set_owner($t, $conf, $other);
                foreach ($reads($conf) as $label => $r) {
                    q26_deny($t, $r, 404, 'ARCHIVE412', "terkonfirmasi user lain, non-superadmin: $label");
                }
                foreach ([1, 2] as $role) {
                    q26_with_user($t, ['role' => $role], function () use ($t, $reads, $conf, $role, $mutations, $x) {
                        $r = $reads($conf);
                        $t->status($r['show'], 200, "terkonfirmasi user lain, superadmin $role: show");
                        $t->eq($r['show'][1]['result']['status'], 2, "superadmin $role: status 2");
                        $t->eq($r['show'][1]['result']['created_by'], 'otheruser', "superadmin $role: created_by pemilik asli");
                        $t->status($r['documents'], 200, "terkonfirmasi user lain, superadmin $role: documents");
                        $t->true(isset($r['documents'][1]['result']['data']), "superadmin $role: documents berisi data");
                        // superadmin bukan pemilik: tidak bisa mengubah sesi itu
                        foreach ($mutations($conf) as $label => $res) {
                            $t->true($res[0] >= 400 && $res[0] < 500, "superadmin $role: $label ke sesi terkonfirmasi user lain ditolak 4xx (HTTP {$res[0]})");
                        }
                    });
                }
                // role 29 (List Archive) bukan superadmin -> 404 untuk terkonfirmasi user lain
                q26_with_user($t, ['role' => Q26_ROLE_LIST_ONLY], function () use ($t, $reads, $conf) {
                    foreach ($reads($conf) as $label => $r) {
                        q26_deny($t, $r, 404, 'ARCHIVE412', "role 29: terkonfirmasi user lain $label");
                    }
                });
                $t->eq((int) q26_opname($t, $conf)['status'], 2, 'sesi terkonfirmasi tetap status 2');

                // --- sesi batal
                $can = q26_session($t, $s, $x['S2'], []);
                $t->status(q26_cancel($t, $s, $can), 200, 'batalkan');
                foreach ($mutations($can) as $label => $r) {
                    q26_deny($t, $r, 400, 'ARCHIVE413', "batal sendiri: $label");
                }
                $r = q26_show($t, $s, $can);
                $t->status($r, 200, 'sesi batal milik sendiri dapat dibaca');
                $t->eq($r[1]['result']['status'], 3, 'status 3');
                $t->eq($r[1]['result']['status_label'], 'Dibatalkan', 'status_label Dibatalkan (bahasa user ID)');
                q26_set_owner($t, $can, $other);
                foreach ($reads($can) as $label => $r) {
                    q26_deny($t, $r, 404, 'ARCHIVE412', "batal user lain (non-superadmin): $label");
                }
                q26_with_user($t, ['role' => 1], function () use ($t, $reads, $can) {
                    foreach ($reads($can) as $label => $r) {
                        $t->true($r[0] < 500, "batal user lain, superadmin 1: $label HTTP {$r[0]} (tanpa 5xx)");
                    }
                });
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-15',
        'title' => 'GET opnames/{id} draft: counts (total, verified_before, scanned, verified, not_found, invalid, unscanned = total - verified, unverified) dan warnings per folder tanpa lanjut dengan unverify_count > 0; folders level 0-1; sesudah Confirm nilai tersimpan',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                // keadaan awal: dF1, d11, dA1 (dalam cakupan) dan d21 (S2, tak dicentang) verified
                foreach (['dF1', 'd11', 'dA1', 'd21'] as $k) {
                    q26_set_archive($t, $x[$k], ['is_verified' => 1, 'verified_at' => date('Y-m-d H:i:s'), 'verified_by' => 'QA26']);
                }
                $N = function ($k) use ($t, $x) {
                    return q26_n($t, $x[$k]);
                };
                $fname = q26_n($t, $x['F']);
                $s1name = q26_n($t, $x['S1']);

                $id = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                q26_scan($t, $s, $id, $N('dF1'));   // verified (sudah verified; tetap)
                q26_scan($t, $s, $id, $N('d21'));   // not_found (S2 tak dicentang)
                q26_scan($t, $s, $id, 'QA26-INVALID'); // invalid

                $r = q26_show($t, $s, $id);
                $t->status($r, 200, 'show draft');
                $res = $r[1]['result'];
                $t->eq(array_keys($res), ['id_archive_opname', 'id_archive', 'status', 'status_label', 'scope', 'folders', 'counts', 'warnings', 'created_by', 'created_at', 'confirmed_at'], 'kunci result sesuai kontrak');
                $t->eq(array_keys($res['counts']), ['total_documents', 'verified_before', 'scanned', 'verified', 'not_found', 'invalid', 'unscanned', 'unverified'], 'kunci counts');
                $t->eq($res['counts'], ['total_documents' => 5, 'verified_before' => 3, 'scanned' => 3, 'verified' => 1, 'not_found' => 1, 'invalid' => 1, 'unscanned' => 4, 'unverified' => 2], 'counts draft (BR-20)');
                $t->eq($res['counts']['unscanned'], $res['counts']['total_documents'] - $res['counts']['verified'], 'unscanned = total - verified');
                $t->eq($res['scope'], ['is_root' => false, 'folder_name' => $fname, 'first_name' => $s1name, 'other_count' => 0], 'scope (tag judul)');
                $t->eq(array_map(function ($f) {
                    return [$f['id_archive'], $f['level'], $f['is_continue'], $f['is_opnamed_today'], $f['document_count']];
                }, $res['folders']), [[$x['F'], 0, false, false, 3], [$x['S1'], 1, false, false, 2]], 'folders level 0-1 (document_count level 0 langsung, level 1 subtree)');
                $t->eq(array_keys($res['folders'][0]), ['id_archive', 'name', 'level', 'is_continue', 'is_opnamed_today', 'document_count'], 'kunci folders[]');
                $t->eq($res['warnings'], [['id_archive' => $x['S1'], 'name' => $s1name, 'is_opnamed_today' => false, 'unverify_count' => 2]], 'warnings: S1 menurunkan 2 verified (d11, dA1)');
                $t->eq(array_keys($res['warnings'][0]), ['id_archive', 'name', 'is_opnamed_today', 'unverify_count'], 'kunci warnings[]');
                $t->true($res['status'] === 1 && $res['status_label'] === 'Berjalan' && $res['confirmed_at'] === null, 'status 1, confirmed_at null');
                $t->eq($res['created_by'], q26_uname($t), 'created_by');

                // scan lebih banyak: d11 verified -> kept; warnings turun
                q26_scan($t, $s, $id, $N('d11'));
                $res = q26_show($t, $s, $id)[1]['result'];
                $t->eq([$res['counts']['verified'], $res['counts']['unscanned'], $res['counts']['unverified']], [2, 3, 1], 'sesudah scan d11: verified 2, unscanned 3, unverified 1');
                $t->eq($res['warnings'][0]['unverify_count'], 1, 'warnings: tinggal 1 (dA1)');
                q26_scan($t, $s, $id, $N('dA1'));
                $res = q26_show($t, $s, $id)[1]['result'];
                $t->eq($res['warnings'], [], 'semua verified discan: tanpa warnings');
                $t->eq($res['counts']['unverified'], 0, 'unverified 0');

                // konfirmasi -> nilai tersimpan; verified tak berubah (semua discan)
                $r = q26_scan($t, $s, $id, $N('dF2'));
                $t->status(q26_confirm($t, $s, $id), 200, 'confirm');
                $res = q26_show($t, $s, $id)[1]['result'];
                $t->eq($res['counts'], ['total_documents' => 5, 'verified_before' => 3, 'scanned' => 6, 'verified' => 4, 'not_found' => 1, 'invalid' => 1, 'unscanned' => 1, 'unverified' => 0], 'counts tersimpan sesudah confirm');
                $t->eq($res['status'], 2, 'status 2');
                $t->true($res['confirmed_at'] !== null, 'confirmed_at terisi');
                $t->eq(array_map(function ($f) {
                    return [$f['level'], $f['document_count']];
                }, $res['folders']), [[0, 3], [1, 1], [2, 1]], 'folders sesudah confirm: level 0-2, document_count langsung');

                // --- sesi kedua: F sudah diopname hari ini -> lanjut disimpan; warnings hanya untuk yang tanpa lanjut
                foreach (['d11', 'dA1'] as $k) {
                    q26_set_archive($t, $x[$k], ['is_verified' => 1, 'verified_at' => date('Y-m-d H:i:s'), 'verified_by' => 'QA26']);
                }
                $id2 = q26_session($t, $s, $x['F'], q26_sel([$x['S1'] => true]), true);
                $res = q26_show($t, $s, $id2)[1]['result'];
                $t->eq(array_map(function ($f) {
                    return [$f['id_archive'], $f['is_continue'], $f['is_opnamed_today']];
                }, $res['folders']), [[$x['F'], true, true], [$x['S1'], true, true]], 'sesi 2: F dan S1 sudah diopname hari ini, is_continue true disimpan');
                $t->eq($res['warnings'], [], 'sesi 2: lanjut aktif -> tanpa warnings');
                $t->eq($res['counts']['unverified'], 0, 'sesi 2: unverified 0');
                $r = q26_update($t, $s, $id2, q26_sel([$x['S1'] => false]), true);
                $t->status($r, 200, 'PUT: S1 lanjut off');
                $res = q26_show($t, $s, $id2)[1]['result'];
                $t->eq($res['warnings'], [['id_archive' => $x['S1'], 'name' => $s1name, 'is_opnamed_today' => true, 'unverify_count' => 2]], 'sesi 2: S1 tanpa lanjut -> warning (is_opnamed_today true)');
                $t->eq($res['counts']['unverified'], 2, 'sesi 2: unverified 2');
                $t->eq($res['counts']['verified_before'], 4, 'sesi 2: verified_before 4 (dF1, dF2, d11, dA1 sudah verified oleh sesi 1)');

                // --- user Semarang: dokumen lokasi JOG di F tak dihitung
                q26_with_user($t, ['emp' => ['SMR']], function () use ($t, $s, $x) {
                    $id3 = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                    $res = q26_show($t, $s, $id3)[1]['result'];
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
        'title' => 'DELETE draft: status 3 + ARCHIVE214; folder tidak dianggap sudah diopname; verified tak berubah; confirm/scan/PUT/DELETE sesudahnya 400 ARCHIVE413',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                foreach (['dF1', 'd11'] as $k) {
                    q26_set_archive($t, $x[$k], ['is_verified' => 1, 'verified_at' => '2026-01-01 08:00:00', 'verified_by' => 'QA26']);
                }
                $before = [q26_row($t, $x['dF1']), q26_row($t, $x['d11']), q26_row($t, $x['dF2'])];

                $id = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                q26_scan($t, $s, $id, q26_n($t, $x['dF2']));
                $r = q26_folders($t, $s, $x['F']);
                $t->true($r[1]['result']['folder']['opnamed_today'] === null, 'draft berjalan: F belum dianggap diopname');
                $r = q26_cancel($t, $s, $id);
                $t->status($r, 200, 'DELETE draft');
                $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE214', 'ARCHIVE214');
                $t->eq($r[1]['result'], ['id_archive_opname' => $id], 'result hanya id_archive_opname');
                $row = q26_opname($t, $id);
                $t->eq((int) $row['status'], 3, 'DB: status 3');
                $t->true($row['confirmed_at'] === null, 'DB: confirmed_at tetap NULL');
                $t->eq($row['updated_by'], q26_uname($t), 'DB: updated_by');

                $r = q26_folders($t, $s, $x['F']);
                $t->true($r[1]['result']['folder']['opnamed_today'] === null, 'sesudah batal: F belum dianggap diopname');
                foreach (q26_child_ids($r) as $cid) {
                    $c = q26_child($r, $cid);
                    $t->true($c['opnamed_today'] === null && $c['is_default_checked'] === true, 'sesudah batal: anak belum diopname & tercentang');
                }
                $t->true($r[1]['result']['skip_select_folder'] === false, 'skip_select_folder tetap false (ada subfolder)');

                $after = [q26_row($t, $x['dF1']), q26_row($t, $x['d11']), q26_row($t, $x['dF2'])];
                $t->true(json_encode($before) === json_encode($after), 'archives (verified & kolom lain) tidak berubah oleh sesi batal');

                foreach ([
                    'confirm' => q26_confirm($t, $s, $id),
                    'scan' => q26_scan($t, $s, $id, 'x'),
                    'update' => q26_update($t, $s, $id, []),
                    'cancel lagi' => q26_cancel($t, $s, $id),
                ] as $label => $res) {
                    q26_deny($t, $res, 400, 'ARCHIVE413', "sesudah batal: $label");
                }
                $t->eq((int) q26_opname($t, $id)['status'], 3, 'status tetap 3');

                // draft tanpa scan juga bisa dibatalkan
                $id2 = q26_session($t, $s, $x['F'], []);
                $t->status(q26_cancel($t, $s, $id2), 200, 'batal draft tanpa scan');

                // folder yang sudah dikonfirmasi tetap "sudah diopname" walau ada sesi batal sesudahnya
                $id3 = q26_session($t, $s, $x['S2'], []);
                $t->status(q26_confirm($t, $s, $id3), 200, 'confirm S2');
                $id4 = q26_session($t, $s, $x['S2'], [], true);
                $t->status(q26_cancel($t, $s, $id4), 200, 'batal sesi baru S2');
                $r = q26_folders($t, $s, $x['S2']);
                $t->eq($r[1]['result']['folder']['opnamed_today']['id_archive_opname'], $id3, 'S2 tetap diopname hari ini oleh sesi terkonfirmasi (bukan yang batal)');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'EXTRA-SCOPE',
        'title' => '(tambahan AC-15, BR-15) scope tag sesi: root / folder, subfolder pertama + sisa; sama sebelum & sesudah Confirm',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $fn = q26_n($t, $x['F']);
                $s1 = q26_n($t, $x['S1']);
                $check = function ($id, $exp, $label) use ($t, $s) {
                    $r = q26_show($t, $s, $id);
                    $t->eq($r[1]['result']['scope'], $exp, "$label (draft)");
                };
                $a = q26_session($t, $s, $x['F'], []);
                $check($a, ['is_root' => false, 'folder_name' => $fn, 'first_name' => null, 'other_count' => 0], 'F tanpa subfolder');
                $b = q26_session($t, $s, $x['F'], q26_sel([$x['S2'], $x['S1']]));
                $check($b, ['is_root' => false, 'folder_name' => $fn, 'first_name' => $s1, 'other_count' => 1], 'F[S2,S1]');
                $c = q26_session($t, $s, null, []);
                $check($c, ['is_root' => true, 'folder_name' => null, 'first_name' => null, 'other_count' => 0], 'root tanpa subfolder');
                $d = q26_session($t, $s, null, q26_sel([$x['OTH'], $x['F']]));
                $check($d, ['is_root' => true, 'folder_name' => null, 'first_name' => $fn, 'other_count' => 1], 'root[OTH,F]');
                sleep(1);
                $t->status(q26_confirm($t, $s, $b), 200, 'confirm b');
                $r = q26_show($t, $s, $b);
                $t->eq($r[1]['result']['scope'], ['is_root' => false, 'folder_name' => $fn, 'first_name' => $s1, 'other_count' => 1], 'F[S2,S1] (terkonfirmasi)');
                $row = q26_opname($t, $b);
                $t->eq([$row['scope_name'], (int) $row['scope_folder_count']], [$s1, 2], 'DB: scope_name & scope_folder_count');
                // folder diganti nama sesudah konfirmasi: tag memakai snapshot sesi
                q26_set_archive($t, $x['F'], ['name' => q26_name('RENAMED')]);
                $r = q26_show($t, $s, $b);
                $t->eq($r[1]['result']['scope']['folder_name'], $fn, 'terkonfirmasi: scope.folder_name dari snapshot (tak ikut rename)');
                $t->eq($r[1]['result']['folders'][0]['name'], $fn, 'terkonfirmasi: folders[0].name snapshot');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'EXTRA-PERM',
        'title' => '(tambahan AC-5/AC-10, BR-5) folder dengan folder-permission aktif tanpa View: tak tampil, dokumennya tak dihitung, scan = not_found, data tetap; dengan hak View tampil; turunan terkunci ikut terputus',
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

                // F = S3 / turunannya -> 403 ARCHIVE407; S4 (hak View) boleh
                q26_deny($t, q26_folders($t, $s, $S3), 403, 'ARCHIVE407', 'F = S3 terkunci');
                q26_deny($t, q26_folders($t, $s, $S3c), 403, 'ARCHIVE407', 'F = S3c (turunan terkunci)');
                q26_deny($t, q26_store($t, $s, $S3c, []), 403, 'ARCHIVE407', 'POST F = S3c');
                $r = q26_folders($t, $s, $S4);
                $t->status($r, 200, 'F = S4 (hak View)');
                $t->eq(q26_child_ids($r), [$S4a], 'children S4 = S4a');
                q26_deny($t, q26_store($t, $s, $x['F'], q26_sel([$S3])), 400, 'ARCHIVE416', 'subfolder S3 terkunci -> 416');

                // sesi F[S1,S2,S4,SJ]
                $id = q26_session($t, $s, $x['F'], q26_sel([$x['S1'], $x['S2'], $S4, $x['SJ']]));
                $c = q26_show($t, $s, $id)[1]['result']['counts'];
                $t->eq($c['total_documents'], 8, 'total = F 3 + S1 2 + S2 1 + S4 1 + SJ 1 (d31 & d3c tak dihitung)');
                foreach ([$d31, $d3c] as $doc) {
                    $r = q26_scan($t, $s, $id, q26_n($t, $doc));
                    $t->eq($r[1]['result']['row']['result'], 'not_found', 'dokumen di folder terkunci -> not_found');
                }
                $r = q26_scan($t, $s, $id, q26_n($t, $d4a));
                $t->eq($r[1]['result']['row']['result'], 'verified', 'dokumen di S4a (turunan folder ber-hak) -> verified');
                $t->status(q26_confirm($t, $s, $id), 200, 'confirm');
                foreach ([$d31, $d3c] as $doc) {
                    $t->eq([q26_row($t, $doc)['is_verified'], q26_row($t, $doc)['verified_at'], q26_row($t, $doc)['id_archive_opname']], [1, $marker, null], 'dokumen folder terkunci: verified & kolom tetap');
                }
                $folders = array_column(q26_opfolders($t, $id), 'id_archive');
                $t->true(!in_array($S3, $folders, true) && !in_array($S3c, $folders, true), 'snapshot folder tidak memuat S3/S3c');

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
        'title' => '(tambahan, catatan implementasi) kondisi berubah sesudah draft dibuat: folder opname nonaktif / kehilangan View, subfolder terpilih nonaktif / dipindah, dokumen scan dihapus: tanpa 500, ARCHIVE400/407 di PUT & Confirm',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $marker = '2026-01-01 08:00:00';
                $all = function ($id) use ($t, $s) {
                    return ['show' => q26_show($t, $s, $id), 'documents' => q26_docs($t, $s, $id, ['pagination' => 50]), 'scan' => q26_scan($t, $s, $id, 'QA26-STALE-' . mt_rand())];
                };

                // 1. folder opname dinonaktifkan
                $id = q26_session($t, $s, $x['S2'], []);
                q26_scan($t, $s, $id, q26_n($t, $x['d21']));
                q26_set_archive($t, $x['S2'], ['is_active' => 0]);
                foreach ($all($id) as $label => $r) {
                    q26_no500($t, $r, "S2 nonaktif: $label");
                }
                q26_deny($t, q26_confirm($t, $s, $id), 404, 'ARCHIVE400', 'S2 nonaktif: confirm 404');
                q26_deny($t, q26_update($t, $s, $id, []), 404, 'ARCHIVE400', 'S2 nonaktif: PUT 404');
                $t->status(q26_cancel($t, $s, $id), 200, 'S2 nonaktif: draft tetap bisa dibatalkan');
                q26_set_archive($t, $x['S2'], ['is_active' => 1]);

                // 2. folder opname kehilangan View (folder-permission diaktifkan oleh user lain)
                sleep(1);
                $id = q26_session($t, $s, $x['S2'], []);
                q26_set_archive($t, $x['S2'], ['is_folder_permission' => 1, 'created_by' => 'someoneelse']);
                foreach ($all($id) as $label => $r) {
                    q26_no500($t, $r, "S2 kehilangan View: $label");
                }
                q26_deny($t, q26_confirm($t, $s, $id), 403, 'ARCHIVE407', 'S2 kehilangan View: confirm 403');
                q26_deny($t, q26_update($t, $s, $id, []), 403, 'ARCHIVE407', 'S2 kehilangan View: PUT 403');
                q26_cancel($t, $s, $id);
                q26_set_archive($t, $x['S2'], ['is_folder_permission' => 0, 'created_by' => 'QA26']);

                // 3. subfolder terpilih dinonaktifkan / dipindah: keluar cakupan tanpa error
                sleep(1);
                q26_set_archive($t, $x['d11'], ['is_verified' => 1, 'verified_at' => $marker, 'verified_by' => 'QA26']);
                $id = q26_session($t, $s, $x['F'], q26_sel([$x['S1'], $x['S2']]));
                $c0 = q26_show($t, $s, $id)[1]['result']['counts']['total_documents'];
                q26_set_archive($t, $x['S1'], ['is_active' => 0]);
                $r = q26_show($t, $s, $id);
                $t->status($r, 200, 'S1 nonaktif: show 200');
                $c1 = $r[1]['result']['counts']['total_documents'];
                $t->eq($c1, $c0 - 2, 'S1 nonaktif: cakupan berkurang d11 + dA1');
                $t->true(!in_array($x['S1'], array_column($r[1]['result']['folders'], 'id_archive'), true), 'S1 nonaktif: tak ada di folders');
                q26_set_archive($t, $x['S1'], ['is_active' => 1, 'id_archive_parent' => $x['OTH']]);
                $r = q26_show($t, $s, $id);
                $t->status($r, 200, 'S1 dipindah: show 200');
                $t->eq($r[1]['result']['counts']['total_documents'], $c0 - 2, 'S1 dipindah: bukan anak langsung lagi, keluar cakupan');
                $t->status(q26_confirm($t, $s, $id), 200, 'S1 dipindah: confirm tetap 200');
                $t->eq(q26_v($t, $x['d11']), [1, true, 'QA26', null], 'd11 (S1 di luar cakupan) tak tersentuh');
                q26_set_archive($t, $x['S1'], ['id_archive_parent' => $x['F']]);

                // 4. dokumen yang sudah discan dihapus dari DB: jadi invalid, tanpa 500
                sleep(1);
                $id = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                $name = q26_n($t, $x['d11']);
                $r = q26_scan($t, $s, $id, $name);
                $t->eq($r[1]['result']['row']['result'], 'verified', 'sebelum dihapus: verified');
                q26_w($t, function ($c) use ($x) {
                    $c->table('archive_documents')->where('id_archive', $x['d11'])->delete();
                    $c->table('archives')->where('id_archive', $x['d11'])->delete();
                });
                foreach ($all($id) as $label => $r) {
                    q26_no500($t, $r, "dokumen dihapus: $label");
                    $t->status($r, 200, "dokumen dihapus: $label");
                }
                $rows = q26_all_docs($t, $s, $id, 'invalid', 100);
                $t->true(in_array(strtolower($name), array_map('strtolower', array_column($rows, 'code')), true), 'dokumen dihapus: baris scan menjadi invalid');
                $t->status(q26_confirm($t, $s, $id), 200, 'dokumen dihapus: confirm 200');
                $docs = q26_opdocs($t, $id);
                $t->eq((int) $docs[strtolower($name)]['result'], 3, 'snapshot: result 3 (invalid)');
                $t->true($docs[strtolower($name)]['id_archive'] === null, 'snapshot: id_archive null');
            } finally {
                q26_cleanup($t);
            }
        },
    ],
];
