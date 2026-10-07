<?php
/**
 * ED-1026 - Step 1 & sesi draft: AC-5 (folders per lokasi, document_count), AC-6 (kode error folders), AC-9 (POST/PUT
 * opnames), AC-3 (bagian BE: permission Opname Document -> 403 GE0114), AC-8 (bagian BE: Step 1 dilewati + scope tag).
 * Pembanding jumlah dokumen = oracle SQL independen (q26_oracle), bukan kode BE.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-5',
        'title' => 'GET opnames/folders root: user Semarang tanpa CABANG - JOGJA, document_count subtree = oracle SQL, opnamed_today null, is_default_checked true; superadmin 1 dan 2 melihat semua',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $check = function ($r, $oracle, $label, $expectJogja) use ($t) {
                    $t->status($r, 200, $label);
                    $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE200', "$label: msg_code");
                    $res = $r[1]['result'] ?? [];
                    $t->eq(array_keys($res), ['folder', 'children', 'skip_select_folder'], "$label: kunci result sesuai kontrak");
                    $t->eq(array_keys($res['folder']), ['id_archive', 'name', 'is_root', 'document_count', 'opnamed_today'], "$label: kunci folder");
                    $t->true($res['folder']['is_root'] === true && $res['folder']['id_archive'] === null && $res['folder']['name'] === null, "$label: folder = root");
                    $t->eq($res['folder']['document_count'], $oracle['direct'], "$label: folder.document_count = dokumen tanpa folder yang terlihat");
                    $t->true($res['folder']['opnamed_today'] === null, "$label: folder.opnamed_today null");
                    $t->true($res['skip_select_folder'] === false, "$label: skip_select_folder false (ada subfolder)");

                    $ids = q26_child_ids($r);
                    $t->eq($ids, array_keys($oracle['children']), "$label: children = folder terlihat, urut nama");
                    foreach ($res['children'] as $ch) {
                        $t->eq(array_keys($ch), ['id_archive', 'name', 'document_count', 'has_children', 'opnamed_today', 'is_default_checked'], "$label: kunci child");
                        $o = $oracle['children'][$ch['id_archive']];
                        $t->eq($ch['name'], $o[0], "$label: nama " . $o[0]);
                        $t->eq($ch['document_count'], $o[1], "$label: document_count subtree " . $o[0]);
                        $t->true($ch['has_children'] === $o[2], "$label: has_children " . $o[0]);
                        $t->true($ch['opnamed_today'] === null, "$label: opnamed_today null " . $o[0]);
                        $t->true($ch['is_default_checked'] === true, "$label: is_default_checked true " . $o[0]);
                    }
                    $names = array_column($res['children'], 'name');
                    $t->true(in_array('CABANG - JOGJA', $names, true) === $expectJogja, "$label: CABANG - JOGJA " . ($expectJogja ? 'ada' : 'tidak ada'));
                    $t->true(in_array('CABANG - SEMARANG', $names, true), "$label: CABANG - SEMARANG ada");
                    $t->true(in_array('Backup Arsip', $names, true), "$label: Backup Arsip ada");

                    return $res;
                };

                // user Semarang saja (role 3, non-bypass)
                q26_with_user($t, ['emp' => ['SMR']], function () use ($t, $s, $check) {
                    $res = $check(q26_folders($t, $s, null), q26_oracle($t, null, ['SMR']), 'Semarang', false);
                    // anak langsung Backup Arsip: SMLSMG* (SMR,MGL) terlihat, SMLYK (JOG,MGL) & SMLHO (MGL) tidak
                    $bk = null;
                    foreach ($res['children'] as $ch) {
                        if ($ch['name'] === 'Backup Arsip') {
                            $bk = $ch['id_archive'];
                        }
                    }
                    $r = q26_folders($t, $s, $bk);
                    $t->status($r, 200, 'Semarang: folders Backup Arsip');
                    $names = array_column($r[1]['result']['children'], 'name');
                    $t->true(in_array('SMLSMG', $names, true) && !in_array('SMLYK', $names, true) && !in_array('SMLHO - KANTOR ADMIN', $names, true), 'Semarang: subfolder Backup Arsip hanya yang berlokasi SMR');
                    $t->true(!in_array('HIDDEN', $names, true), 'Semarang: folder nonaktif HIDDEN tidak tampil');
                    $o = q26_oracle($t, $bk, ['SMR']);
                    $t->eq(array_column($r[1]['result']['children'], 'document_count', 'id_archive'), array_map(function ($v) {
                        return $v[1];
                    }, $o['children']), 'Semarang: document_count anak Backup Arsip = oracle');
                    $t->eq($r[1]['result']['folder']['document_count'], $o['direct'], 'Semarang: folder.document_count Backup Arsip = dokumen langsung terlihat');
                });

                // superadmin 1 dan 2: semua
                foreach ([1, 2] as $role) {
                    q26_with_user($t, ['role' => $role], function () use ($t, $s, $check, $role) {
                        $check(q26_folders($t, $s, null), q26_oracle($t, null, null), "superadmin $role", true);
                    });
                }
                $t->eq(json_encode(q26_dirty($t)), json_encode(['opnames' => 0, 'folders' => 0, 'documents' => 0, 'archives' => 0]), 'GET folders tidak menulis apa pun');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-6',
        'title' => 'opnames/folders: id dokumen 400 ARCHIVE417; tak ada / nonaktif 404 ARCHIVE400; luar lokasi / tanpa View (termasuk turunan folder terkunci) 403 ARCHIVE407; masukan aneh tanpa 500',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $uid = q26_uid($t);
                // folder terkunci (folder permission aktif, tanpa hak View user QA) + turunannya; satu lagi dengan hak View
                $L = q26_folder($t, 'LOCK', ['perm' => 1, 'by' => 'someoneelse']);
                $Lc = q26_folder($t, 'LOCKC', ['parent' => $L]);
                $G = q26_folder($t, 'GRANT', ['perm' => 1, 'by' => 'someoneelse']);
                q26_perm($t, $G, $uid, 1);

                $deny = function ($id, $http, $code, $label) use ($t, $s) {
                    $r = q26_folders($t, $s, $id);
                    q26_deny($t, $r, $http, $code, $label);
                    q26_no500($t, $r, $label);
                };

                $deny($x['dF1'], 400, 'ARCHIVE417', 'id dokumen -> 417');
                $deny(Q26_RANDOM_ID, 404, 'ARCHIVE400', 'id tidak ada -> 400');
                $deny($x['SX'], 404, 'ARCHIVE400', 'folder nonaktif -> 400');
                $deny($L, 403, 'ARCHIVE407', 'folder terkunci (tanpa View) -> 407');
                $deny($Lc, 403, 'ARCHIVE407', 'turunan folder terkunci -> 407 (induk dominan)');
                $r = q26_folders($t, $s, $G);
                $t->status($r, 200, 'folder dengan hak View -> 200');

                // masukan aneh: tanpa 500
                $r = $t->call($s, 'GET', Q26_BASE . '/opnames/folders?id_archive=' . rawurlencode('日本語'));
                q26_no500($t, $r, 'id non-ASCII');
                $t->status($r, 404, 'id non-ASCII -> 404');
                $t->eq(q26_code($r), 'ARCHIVE400', 'id non-ASCII: ARCHIVE400');
                $r = $t->call($s, 'GET', Q26_BASE . '/opnames/folders?id_archive=' . rawurlencode(str_repeat('a', 31)));
                $t->status($r, 422, 'id 31 karakter -> 422');
                $r = $t->call($s, 'GET', Q26_BASE . '/opnames/folders?id_archive[]=a&id_archive[]=b');
                $t->status($r, 422, 'id_archive array -> 422');
                $r = $t->call($s, 'GET', Q26_BASE . "/opnames/folders?id_archive=" . rawurlencode("' OR 1=1 -- "));
                q26_no500($t, $r, 'id SQL meta');
                $t->true(in_array($r[0], [404, 422], true), 'id SQL meta ditolak 4xx');
                $r = $t->call($s, 'GET', Q26_BASE . '/opnames/folders?id_archive=');
                $t->status($r, 200, 'id_archive kosong = root');
                $t->true($r[1]['result']['folder']['is_root'] === true, 'id_archive kosong: is_root');

                // user Semarang: folder lokasi JOG -> 403 407; folder & dokumen JOG di dalam folder F
                q26_with_user($t, ['emp' => ['SMR']], function () use ($t, $s, $x, $deny) {
                    $jogja = $t->db()->table('archives')->where('name', 'CABANG - JOGJA')->where('type', 1)->value('id_archive');
                    $deny($jogja, 403, 'ARCHIVE407', 'Semarang: CABANG - JOGJA -> 407');
                    $deny($x['SJ'], 403, 'ARCHIVE407', 'Semarang: subfolder lokasi JOG -> 407');
                    $deny($x['dj1'], 403, 'ARCHIVE407', 'Semarang: dokumen lokasi JOG -> 407');
                    $r = q26_folders($t, $s, $x['F']);
                    $t->status($r, 200, 'Semarang: F -> 200');
                    $t->eq(q26_child_ids($r), [$x['S1'], $x['S2']], 'Semarang: children F = S1, S2 (SJ lokasi JOG dan SX nonaktif tidak ada)');
                });
                // superadmin 1 lolos folder JOG
                q26_with_user($t, ['role' => 1], function () use ($t, $s, $x, $L) {
                    $r = q26_folders($t, $s, $x['SJ']);
                    $t->status($r, 200, 'superadmin 1: subfolder JOG -> 200');
                    $r = q26_folders($t, $s, $L);
                    $t->status($r, 200, 'superadmin 1: folder terkunci -> 200 (bypass)');
                });
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-8',
        'title' => '(bagian BE) Folder ZULFA tanpa subfolder & belum diopname: skip_select_folder true, POST tanpa folders membuat draft, scope.folder_name = ZULFA, total = dokumen langsung; skip false bila ada subfolder / sudah diopname hari ini',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $z = $t->db()->table('archives')->where('name', 'ZULFA')->where('type', 1)->value('id_archive');
                $t->true($z !== null, 'prasyarat: folder ZULFA ada di DB QA');
                $r = q26_folders($t, $s, $z);
                $t->status($r, 200, 'folders ZULFA');
                $t->eq($r[1]['result']['children'], [], 'ZULFA: children kosong');
                $t->true($r[1]['result']['folder']['opnamed_today'] === null, 'ZULFA: belum diopname hari ini');
                $t->true($r[1]['result']['skip_select_folder'] === true, 'ZULFA: skip_select_folder true');
                $o = q26_oracle($t, $z, ['SMR', 'MGL', 'JOG']);
                $t->eq($r[1]['result']['folder']['document_count'], $o['direct'], 'ZULFA: document_count = oracle');

                $id = q26_session($t, $s, $z, []);
                $show = q26_show($t, $s, $id);
                $t->status($show, 200, 'show draft ZULFA');
                $res = $show[1]['result'];
                $t->eq($res['scope'], ['is_root' => false, 'folder_name' => 'ZULFA', 'first_name' => null, 'other_count' => 0], 'scope tag = ZULFA tanpa subfolder');
                $t->eq($res['counts']['total_documents'], $o['direct'], 'counts.total_documents = dokumen ZULFA');
                $t->eq(count($res['folders']), 1, 'hanya baris folder level 0');
                $t->eq($res['folders'][0]['level'], 0, 'level 0');
                $row = q26_opname($t, $id);
                $t->eq([(int) $row['status'], $row['id_archive'], $row['scope_name'], (int) $row['scope_folder_count']], [1, $z, 'ZULFA', 0], 'DB: draft ZULFA, scope_name ZULFA, 0 subfolder');
                $t->eq(q26_cancel($t, $s, $id)[0], 200, 'batalkan draft ZULFA');

                // ada subfolder -> tidak dilewati
                $x = q26_tree($t);
                $r = q26_folders($t, $s, $x['F']);
                $t->true($r[1]['result']['skip_select_folder'] === false, 'folder dengan subfolder: skip false');
                // folder tanpa subfolder tapi sudah diopname hari ini -> tidak dilewati
                $id = q26_session($t, $s, $x['S2'], []);
                $t->status(q26_confirm($t, $s, $id), 200, 'confirm S2 (tanpa subfolder)');
                $r = q26_folders($t, $s, $x['S2']);
                $t->true($r[1]['result']['folder']['opnamed_today'] !== null, 'S2 sudah diopname hari ini');
                $t->true($r[1]['result']['skip_select_folder'] === false, 'sudah diopname hari ini: skip false (K-3)');
                $t->eq($r[1]['result']['children'], [], 'S2 tanpa children');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-9',
        'title' => 'POST opnames: valid -> ARCHIVE211 + draft status 1 (DB); subfolder bukan anak langsung / tak terlihat / nonaktif -> 400 ARCHIVE416; F luar scope -> 403 ARCHIVE407 sebelum 422; body salah 422; PUT mengganti pilihan',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $uname = q26_uname($t);
                $count = function () use ($t) {
                    return $t->db()->table('archive_opnames')->count();
                };

                // --- valid
                $r = q26_store($t, $s, $x['F'], [
                    ['id_archive' => $x['S2'], 'is_continue' => true],
                    ['id_archive' => $x['S1'], 'is_continue' => false],
                ], true);
                $t->status($r, 200, 'valid');
                $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE211', 'valid: ARCHIVE211');
                $t->eq(array_keys($r[1]['result']), ['id_archive_opname'], 'valid: result hanya id_archive_opname');
                $id = $r[1]['result']['id_archive_opname'];
                $row = q26_opname($t, $id);
                $t->true($row !== null, 'DB: baris sesi ada');
                $t->eq((int) $row['status'], 1, 'DB: status 1 (berjalan)');
                $t->eq($row['id_archive'], $x['F'], 'DB: id_archive = F');
                $t->eq($row['created_by'], $uname, 'DB: created_by = user login');
                $t->eq($row['updated_by'], $uname, 'DB: updated_by = user login');
                $t->true($row['selected_at'] !== null && $row['confirmed_at'] === null, 'DB: selected_at terisi, confirmed_at NULL');
                $t->true(abs(strtotime($row['created_at']) - time()) < 30, 'DB: created_at = waktu server');
                $t->eq($row['scope_name'], q26_n($t, $x['S1']), 'DB: scope_name = subfolder pertama (urut nama)');
                $t->eq((int) $row['scope_folder_count'], 2, 'DB: scope_folder_count = 2');
                $t->eq([(int) $row['total_documents'], (int) $row['scanned_count'], (int) $row['verified_count']], [0, 0, 0], 'DB: angka final belum diisi (draft)');
                $fol = q26_opfolders($t, $id);
                $t->eq(array_map(function ($f) {
                    return [(int) $f['level'], $f['id_archive']];
                }, $fol), [[0, $x['F']], [1, $x['S1']], [1, $x['S2']]], 'DB: baris folder level 0 + 1 (S1, S2)');
                foreach ($fol as $f) {
                    $t->eq([(int) $f['is_continue'], (int) $f['is_opnamed_today']], [0, 0], 'DB: is_continue disimpan false karena belum diopname hari ini (' . $f['name'] . ')');
                }
                $t->eq($fol[1]['id_archive_parent'], $x['F'], 'DB: snapshot id_archive_parent');
                $t->eq($fol[1]['name'], q26_n($t, $x['S1']), 'DB: snapshot nama');

                // --- root, tanpa folders, camelCase
                $before = $count();
                $r = q26_store($t, $s, null, []);
                $t->status($r, 200, 'root tanpa subfolder');
                $row = q26_opname($t, $r[1]['result']['id_archive_opname']);
                $t->true($row['id_archive'] === null && $row['scope_name'] === null && (int) $row['scope_folder_count'] === 0, 'DB: root, scope_name NULL');
                $r = $t->call($s, 'POST', Q26_BASE . '/opnames', []);
                q26_track($r[1]['result']['id_archive_opname'] ?? '');
                $t->status($r, 200, 'body kosong = root');
                $r = $t->call($s, 'POST', Q26_BASE . '/opnames', ['idArchive' => $x['F'], 'isContinue' => false, 'folders' => [['id_archive' => $x['S1']]]]);
                q26_track($r[1]['result']['id_archive_opname'] ?? '');
                $t->status($r, 200, 'camelCase level terluar (idArchive)');
                $row = q26_opname($t, $r[1]['result']['id_archive_opname']);
                $t->eq($row['id_archive'], $x['F'], 'camelCase: idArchive dipakai sebagai folder opname');
                $t->eq($count(), $before + 3, 'tiga sesi baru tersimpan');

                // --- 400 ARCHIVE416: bukan anak langsung / tak terlihat / nonaktif / F sendiri / dokumen / acak
                $before = $count();
                foreach ([
                    'sub-subfolder (cucu)' => $x['S1a'],
                    'folder lain (OTH)' => $x['OTH'],
                    'folder nonaktif (SX)' => $x['SX'],
                    'F sendiri' => $x['F'],
                    'id dokumen' => $x['dF1'],
                    'id acak' => Q26_RANDOM_ID,
                ] as $label => $bad) {
                    $r = q26_store($t, $s, $x['F'], [['id_archive' => $x['S1']], ['id_archive' => $bad]]);
                    q26_deny($t, $r, 400, 'ARCHIVE416', "416 $label");
                }
                // baris yang bagus di samping yang buruk: tetap tidak ada sesi
                $t->eq($count(), $before, 'penolakan 416: tidak ada sesi dibuat');

                // user Semarang: subfolder lokasi JOG (SJ) tak terlihat -> 416; terlihat -> 200
                q26_with_user($t, ['emp' => ['SMR']], function () use ($t, $s, $x) {
                    $r = q26_store($t, $s, $x['F'], [['id_archive' => $x['SJ']]]);
                    q26_deny($t, $r, 400, 'ARCHIVE416', 'Semarang: SJ lokasi JOG -> 416');
                    $r = q26_store($t, $s, $x['F'], [['id_archive' => $x['S1']]]);
                    $t->status($r, 200, 'Semarang: S1 -> 200');
                });
                // subfolder yang folder-permission-nya menolak View -> 416
                $L = q26_folder($t, 'LOCKSUB', ['parent' => $x['F'], 'perm' => 1, 'by' => 'someoneelse']);
                $r = q26_store($t, $s, $x['F'], [['id_archive' => $L]]);
                q26_deny($t, $r, 400, 'ARCHIVE416', '416 subfolder terkunci (tanpa View)');
                $r = q26_folders($t, $s, $x['F']);
                $t->true(!in_array($L, q26_child_ids($r), true), 'subfolder terkunci tidak tampil di children');

                // --- 403 / 404 / 400 untuk F, dicek di authorize() sebelum 422
                $before = $count();
                $jogja = $t->db()->table('archives')->where('name', 'CABANG - JOGJA')->where('type', 1)->value('id_archive');
                q26_with_user($t, ['emp' => ['SMR']], function () use ($t, $s, $jogja, $x) {
                    $r = $t->call($s, 'POST', Q26_BASE . '/opnames', ['id_archive' => $jogja, 'folders' => 'bukan-array']);
                    q26_deny($t, $r, 403, 'ARCHIVE407', 'F luar scope + body salah: 403 sebelum 422');
                    $r = q26_store($t, $s, $jogja, []);
                    q26_deny($t, $r, 403, 'ARCHIVE407', 'F luar scope (CABANG - JOGJA)');
                    $r = q26_store($t, $s, $x['SJ'], []);
                    q26_deny($t, $r, 403, 'ARCHIVE407', 'F = subfolder lokasi JOG');
                });
                $r = q26_store($t, $s, $x['dF1'], []);
                q26_deny($t, $r, 400, 'ARCHIVE417', 'F = dokumen');
                $r = q26_store($t, $s, Q26_RANDOM_ID, []);
                q26_deny($t, $r, 404, 'ARCHIVE400', 'F tidak ada');
                $r = q26_store($t, $s, $x['SX'], []);
                q26_deny($t, $r, 404, 'ARCHIVE400', 'F nonaktif');
                $r = q26_store($t, $s, $L, []);
                q26_deny($t, $r, 403, 'ARCHIVE407', 'F tanpa View efektif');
                $t->eq($count(), $before, 'penolakan F: tidak ada sesi dibuat');

                // --- 422
                $bad = [
                    'folders bukan array' => ['id_archive' => $x['F'], 'folders' => 'abc'],
                    'folders.*.id_archive hilang' => ['id_archive' => $x['F'], 'folders' => [['is_continue' => true]]],
                    'folders.*.id_archive kosong' => ['id_archive' => $x['F'], 'folders' => [['id_archive' => '']]],
                    'folders.*.id_archive duplikat' => ['id_archive' => $x['F'], 'folders' => [['id_archive' => $x['S1']], ['id_archive' => $x['S1']]]],
                    'folders.*.id_archive angka' => ['id_archive' => $x['F'], 'folders' => [['id_archive' => 12345]]],
                    'folders.*.id_archive > 30' => ['id_archive' => $x['F'], 'folders' => [['id_archive' => str_repeat('a', 31)]]],
                    'folders.*.is_continue bukan boolean' => ['id_archive' => $x['F'], 'folders' => [['id_archive' => $x['S1'], 'is_continue' => 'abc']]],
                    'is_continue bukan boolean' => ['id_archive' => $x['F'], 'is_continue' => 'abc', 'folders' => []],
                    'id_archive array' => ['id_archive' => [$x['F']], 'folders' => []],
                ];
                foreach ($bad as $label => $body) {
                    $r = $t->call($s, 'POST', Q26_BASE . '/opnames', $body);
                    q26_no500($t, $r, "422 $label");
                    $t->status($r, 422, "422 $label");
                }
                $t->eq($count(), $before, '422: tidak ada sesi dibuat');

                // --- PUT: ganti pilihan (Back lalu Next); selected_at diperbarui; folder rows ditulis ulang
                $id = q26_session($t, $s, $x['F'], q26_sel([$x['S1'], $x['S2']]));
                $row1 = q26_opname($t, $id);
                sleep(2);
                $r = q26_update($t, $s, $id, q26_sel([$x['S2']]));
                $t->status($r, 200, 'PUT valid');
                $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE211', 'PUT: ARCHIVE211');
                $t->eq($r[1]['result']['id_archive_opname'], $id, 'PUT: id sama');
                $row2 = q26_opname($t, $id);
                $t->true(strtotime($row2['selected_at']) > strtotime($row1['selected_at']), 'PUT: selected_at diperbarui');
                $t->eq($row2['scope_name'], q26_n($t, $x['S2']), 'PUT: scope_name = subfolder pertama baru');
                $t->eq((int) $row2['scope_folder_count'], 1, 'PUT: scope_folder_count 1');
                $t->eq(array_map(function ($f) {
                    return [(int) $f['level'], $f['id_archive']];
                }, q26_opfolders($t, $id)), [[0, $x['F']], [1, $x['S2']]], 'PUT: baris folder ditulis ulang');
                $r = q26_update($t, $s, $id, q26_sel([$x['S1a']]));
                q26_deny($t, $r, 400, 'ARCHIVE416', 'PUT: cucu -> 416');
                $r = $t->call($s, 'PUT', Q26_BASE . '/opnames/' . $id, ['id_archive' => $x['OTH'], 'folders' => []]);
                $t->status($r, 200, 'PUT: id_archive di body diabaikan');
                $t->eq(q26_opname($t, $id)['id_archive'], $x['F'], 'PUT: folder opname tetap F');
                $r = q26_update($t, $s, Q26_RANDOM_ID, []);
                q26_deny($t, $r, 404, 'ARCHIVE412', 'PUT: sesi tak ada');
                $r = $t->call($s, 'PUT', Q26_BASE . '/opnames/' . $id, ['folders' => 'abc']);
                $t->status($r, 422, 'PUT: folders bukan array -> 422');
                $r = q26_cancel($t, $s, $id);
                $t->status($r, 200, 'batalkan');
                $r = q26_update($t, $s, $id, []);
                q26_deny($t, $r, 400, 'ARCHIVE413', 'PUT: sesi batal -> 413');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-9-LEN',
        'title' => '(tambahan AC-9, kontrak §2) id_archive > 30 karakter di POST opnames: rule max:30 -> 422 (GET opnames/folders sudah 422)',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $r = $t->call($s, 'GET', Q26_BASE . '/opnames/folders?id_archive=' . rawurlencode(str_repeat('a', 31)));
                $t->status($r, 422, 'GET folders: id 31 karakter');
                $r = $t->call($s, 'POST', Q26_BASE . '/opnames', ['id_archive' => str_repeat('a', 31), 'folders' => []]);
                q26_no500($t, $r, 'POST id 31 karakter');
                $t->status($r, 422, 'POST opnames: id_archive 31 karakter (kontrak: max 30 -> 422)');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-3',
        'title' => '(bagian BE) Tanpa permission Opname Document: 403 GE0114 (parameter = Opname Document) di semua endpoint kecuali GET opnames/{id} & /documents (List Archive); tanpa token 401; DB tidak berubah',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $id = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                $B = Q26_BASE . '/opnames';
                $routes = [
                    'GET folders'   => ['GET', "$B/folders?id_archive=" . $x['F'], null, 'Opname Document'],
                    'POST store'    => ['POST', $B, ['id_archive' => $x['F'], 'folders' => []], 'Opname Document'],
                    'PUT update'    => ['PUT', "$B/$id", ['folders' => []], 'Opname Document'],
                    'DELETE cancel' => ['DELETE', "$B/$id", null, 'Opname Document'],
                    'POST scan'     => ['POST', "$B/$id/scan", ['code' => 'x'], 'Opname Document'],
                    'PUT confirm'   => ['PUT', "$B/$id/confirm", null, 'Opname Document'],
                    'GET show'      => ['GET', "$B/$id", null, 'List Archive'],
                    'GET documents' => ['GET', "$B/$id/documents", null, 'List Archive'],
                ];
                $call = function ($r) use ($t, $s) {
                    return $t->call($s, $r[0], $r[1], $r[2]);
                };
                $forbid = function ($r, $label, $permission) use ($t) {
                    $t->status($r, 403, $label);
                    $t->eq(q26_code($r), 'GE0114', $label . ': code GE0114');
                    $t->eq($r[1]['parameter'] ?? null, $permission, $label . ': parameter');
                };
                $snapshot = function () use ($t) {
                    return json_encode([q26_dirty($t), $t->db()->table('archive_opnames')->orderBy('id_archive_opname')->get()->all(), $t->db()->table('archive_opname_documents')->count()]);
                };
                $before = $snapshot();

                // tanpa token
                foreach ($routes as $label => $r) {
                    $res = $t->raw($r[0], $r[1], $r[2]);
                    $t->status($res, 401, "tanpa token: $label");
                }

                // role 6: tanpa permission Archive sama sekali -> semua 403
                q26_with_user($t, ['role' => Q26_ROLE_NONE], function () use ($routes, $call, $forbid) {
                    foreach ($routes as $label => $r) {
                        $forbid($call($r), "role 6: $label", $r[3]);
                    }
                });

                // role 29: hanya List Archive -> baca sesi 200, sisanya 403 Opname Document
                q26_with_user($t, ['role' => Q26_ROLE_LIST_ONLY], function () use ($t, $routes, $call, $forbid, $s, $id) {
                    foreach ($routes as $label => $r) {
                        if ($r[3] === 'List Archive') {
                            $res = $call($r);
                            $t->status($res, 200, "role 29 (List Archive): $label");
                        } else {
                            $forbid($call($r), "role 29: $label", $r[3]);
                        }
                    }
                });
                $t->true($before === $snapshot(), 'DB tidak berubah oleh penolakan role 6/29');

                // role 3 (QA default, punya Opname Document) dan superadmin 1/2: lolos
                foreach ([3, 1, 2] as $role) {
                    q26_with_user($t, ['role' => $role], function () use ($t, $s, $x, $role) {
                        $r = q26_folders($t, $s, $x['F']);
                        $t->status($r, 200, "role $role: folders");
                    });
                }
            } finally {
                q26_cleanup($t);
            }
        },
    ],
];
