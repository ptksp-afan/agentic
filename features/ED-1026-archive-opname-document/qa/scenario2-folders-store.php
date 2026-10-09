<?php
/**
 * ED-1026 (rev: opname tanpa draft) - Step 1 & validasi endpoint tanpa simpan: AC-5 (folders per lokasi + hak folder,
 * document_count, selected_at), AC-6 (kode error folders), AC-8 (bagian BE: Step 1 dilewati, pratinjau tanpa tulis),
 * AC-9 (scan / preview / preview/documents / POST opnames: 416, 403 sebelum 422, 422, tanpa tulis), AC-3 (bagian BE:
 * Opname Document -> 403 GE0114, QA_USER2 + role 6/29).
 * Pembanding jumlah dokumen = oracle SQL independen (q26_oracle) yang menghormati Folder Permission; keadaan developer
 * dijaga (q26_baseline / q26_cleanup). Baca sesi (GET opnames*) = ED-1028, tidak diuji di sini.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-5',
        'title' => 'GET opnames/folders root: user Semarang tanpa folder lokasi JOG; children = oracle SQL (lokasi + Folder Permission), document_count subtree, opnamed_today & is_default_checked sesuai DB, selected_at = waktu server; superadmin 1 dan 2 melihat semua',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $uid = q26_uid($t);
                // folder root dengan Izin: tanpa baris View untuk QA_USER = tersembunyi; dengan baris View = tampil
                $RL = q26_folder($t, 'RLOCK', ['perm' => 1, 'by' => 'someoneelse']);
                q26_doc($t, 'dRL', ['parent' => $RL]);
                $RG = q26_folder($t, 'RGRANT', ['perm' => 1, 'by' => 'someoneelse']);
                q26_perm($t, $RG, $uid, 1);
                q26_doc($t, 'dRG', ['parent' => $RG]);
                $before = q26_state($t);

                $check = function ($r, $oracle, $label, $expectJogja, $bypass) use ($t, $x, $RL, $RG) {
                    $t->status($r, 200, $label);
                    $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE200', "$label: msg_code");
                    $res = $r[1]['result'] ?? [];
                    $t->eq(array_keys($res), ['folder', 'children', 'skip_select_folder', 'selected_at'], "$label: kunci result sesuai kontrak");
                    $t->eq(array_keys($res['folder']), ['id_archive', 'name', 'is_root', 'document_count', 'opnamed_today'], "$label: kunci folder");
                    $t->true($res['folder']['is_root'] === true && $res['folder']['id_archive'] === null && $res['folder']['name'] === null, "$label: folder = root");
                    $t->eq($res['folder']['document_count'], $oracle['direct'], "$label: folder.document_count = dokumen tanpa folder yang terlihat");
                    $today = q26_opnamed_today($t);
                    $t->true(($res['folder']['opnamed_today'] === null) === !isset($today['*']), "$label: folder.opnamed_today sesuai DB (root diopname hari ini: " . (isset($today['*']) ? 'ya' : 'tidak') . ')');
                    $t->true($res['skip_select_folder'] === false, "$label: skip_select_folder false (ada subfolder)");

                    $at = new \DateTime($res['selected_at'], new \DateTimeZone('Asia/Jakarta'));
                    $t->true(abs($at->getTimestamp() - time()) <= 15, "$label: selected_at = waktu server (" . $res['selected_at'] . ')');

                    $ids = q26_child_ids($r);
                    $t->eq($ids, array_keys($oracle['children']), "$label: children = folder terlihat menurut oracle, urut nama");
                    foreach ($res['children'] as $ch) {
                        $t->eq(array_keys($ch), ['id_archive', 'name', 'document_count', 'has_children', 'opnamed_today', 'is_default_checked'], "$label: kunci child");
                        $o = $oracle['children'][$ch['id_archive']];
                        $t->eq($ch['name'], $o[0], "$label: nama " . $o[0]);
                        $t->eq($ch['document_count'], $o[1], "$label: document_count subtree " . $o[0]);
                        $t->true($ch['has_children'] === $o[2], "$label: has_children " . $o[0]);
                        $t->true(($ch['opnamed_today'] === null) === !isset($today[$ch['id_archive']]), "$label: opnamed_today sesuai DB " . $o[0]);
                        $t->true($ch['is_default_checked'] === ($ch['opnamed_today'] === null), "$label: is_default_checked = belum diopname " . $o[0]);
                    }
                    // fixture QA: Izin On tanpa View tersembunyi, dengan View tampil; superadmin 1/2 melihat keduanya
                    $t->true(in_array($RL, $ids, true) === $bypass, "$label: folder Izin On tanpa baris View " . ($bypass ? 'tampil (bypass)' : 'tersembunyi'));
                    $t->true(in_array($RG, $ids, true), "$label: folder Izin On dengan baris View tampil");
                    $t->true(in_array($x['F'], $ids, true) && in_array($x['OTH'], $ids, true), "$label: folder uji tanpa Izin tampil");
                    $names = array_column($res['children'], 'name');
                    $t->true(in_array('CABANG - JOGJA', $names, true) === $expectJogja, "$label: CABANG - JOGJA " . ($expectJogja ? 'ada' : 'tidak ada'));

                    return $res;
                };

                // user Semarang saja (role 3, non-bypass)
                q26_with_user($t, ['emp' => ['SMR']], function () use ($t, $s, $check, $x, $RG) {
                    $res = $check(q26_folders($t, $s, null), q26_oracle($t, null, ['SMR']), 'Semarang', false, false);
                    $t->eq(array_column($res['children'], 'document_count', 'id_archive')[$RG], 1, 'Semarang: RGRANT document_count 1');
                    // subfolder level berikut mengikuti oracle juga (folder nyata terbesar yang terlihat)
                    $big = null;
                    foreach ($res['children'] as $ch) {
                        if ($ch['has_children'] && strpos($ch['name'], 'QA26-') !== 0 && ($big === null || $ch['document_count'] > $big['document_count'])) {
                            $big = $ch;
                        }
                    }
                    if ($big !== null) {
                        $r = q26_folders($t, $s, $big['id_archive']);
                        $t->status($r, 200, 'Semarang: folders ' . $big['name']);
                        $o = q26_oracle($t, $big['id_archive'], ['SMR']);
                        $t->eq(q26_child_ids($r), array_keys($o['children']), 'Semarang: children ' . $big['name'] . ' = oracle (folder nonaktif / lokasi lain tak tampil)');
                        $t->eq(array_column($r[1]['result']['children'], 'document_count', 'id_archive'), array_map(function ($v) {
                            return $v[1];
                        }, $o['children']), 'Semarang: document_count anak ' . $big['name'] . ' = oracle');
                        $t->eq($r[1]['result']['folder']['document_count'], $o['direct'], 'Semarang: folder.document_count ' . $big['name'] . ' = dokumen langsung terlihat');
                    }
                });

                // user semua lokasi tetapi non-bypass (role 3 bawaan)
                $check(q26_folders($t, $s, null), q26_oracle($t, null, array_keys(q26_locs($t))), 'role 3 semua lokasi', true, false);

                // superadmin 1 dan 2: semua (lokasi dan hak folder dilewati)
                foreach ([1, 2] as $role) {
                    q26_with_user($t, ['role' => $role], function () use ($t, $s, $check, $role) {
                        $res = $check(q26_folders($t, $s, null), q26_oracle($t, null, null), "superadmin $role", true, true);
                        $names = array_column($res['children'], 'name');
                        foreach (['CABANG - SEMARANG', 'Folder A'] as $real) {
                            $exists = $t->db()->table('archives')->where('name', $real)->where('type', 1)->whereNull('id_archive_parent')->where('is_active', '>', 0)->exists();
                            if ($exists) {
                                $t->true(in_array($real, $names, true), "superadmin $role: folder Izin On '$real' tampil (bypass)");
                            }
                        }
                    });
                }
                $t->true(q26_same_state($before, q26_state($t)), 'GET folders tidak menulis apa pun (tabel opname + kolom verifikasi archives sama)');
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
                $L = q26_folder($t, 'LOCK', ['perm' => 1, 'by' => 'someoneelse']);
                $Lc = q26_folder($t, 'LOCKC', ['parent' => $L]);
                $G = q26_folder($t, 'GRANT', ['perm' => 1, 'by' => 'someoneelse']);
                q26_perm($t, $G, $uid, 1);
                $before = q26_state($t);

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

                // folder nyata ber-Izin tanpa View untuk QA_USER (data developer) juga 407, bila ada
                $realLocked = $t->db()->table('archives')->where('type', 1)->where('is_active', '>', 0)->where('is_folder_permission', 1)
                    ->where('name', 'not like', Q26_PREFIX . '%')->whereNotIn('id_archive', $t->db()->table('archive_permissions')->where('id_user', $uid)->where('is_view', 1)->pluck('id_archive')->all())
                    ->whereRaw('LOWER(created_by) <> ?', [strtolower(q26_uname($t))])->limit(3)->pluck('id_archive')->all();
                foreach ($realLocked as $rid) {
                    $deny($rid, 403, 'ARCHIVE407', 'folder nyata ber-Izin tanpa View untuk QA_USER -> 407');
                }
                $t->note(count($realLocked) . ' folder nyata ber-Izin tanpa View diuji (data developer, hanya dibaca)');

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

                // user Semarang: folder lokasi JOG -> 403 407
                q26_with_user($t, ['emp' => ['SMR']], function () use ($t, $s, $x, $deny) {
                    $jogja = $t->db()->table('archives')->where('name', 'CABANG - JOGJA')->where('type', 1)->value('id_archive');
                    if ($jogja !== null) {
                        $deny($jogja, 403, 'ARCHIVE407', 'Semarang: CABANG - JOGJA -> 407');
                    }
                    $deny($x['SJ'], 403, 'ARCHIVE407', 'Semarang: subfolder lokasi JOG -> 407');
                    $deny($x['dj1'], 403, 'ARCHIVE407', 'Semarang: dokumen lokasi JOG -> 407');
                    $r = q26_folders($t, $s, $x['F']);
                    $t->status($r, 200, 'Semarang: F -> 200');
                    $t->eq(q26_child_ids($r), [$x['S1'], $x['S2']], 'Semarang: children F = S1, S2 (SJ lokasi JOG dan SX nonaktif tidak ada)');
                });
                // superadmin 1 lolos folder JOG dan folder terkunci
                q26_with_user($t, ['role' => 1], function () use ($t, $s, $x, $L) {
                    $r = q26_folders($t, $s, $x['SJ']);
                    $t->status($r, 200, 'superadmin 1: subfolder JOG -> 200');
                    $r = q26_folders($t, $s, $L);
                    $t->status($r, 200, 'superadmin 1: folder terkunci -> 200 (bypass)');
                });
                $t->true(q26_same_state($before, q26_state($t)), 'GET folders tidak menulis apa pun');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-8',
        'title' => '(bagian BE) Folder tanpa subfolder & belum diopname (ZULFA nyata + folder uji): skip_select_folder true; pratinjau scope = nama folder, subfolder_count 0, total = dokumen langsung; tanpa tulis; skip false bila ada subfolder / sudah diopname hari ini',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                // --- folder nyata ZULFA (data developer, hanya dibaca): aturan skip tanpa menganggap datanya
                $z = $t->db()->table('archives')->where('name', 'ZULFA')->where('type', 1)->where('is_active', '>', 0)->value('id_archive');
                if ($z !== null) {
                    $r = q26_folders($t, $s, $z);
                    $t->status($r, 200, 'folders ZULFA');
                    $res = $r[1]['result'];
                    $t->true($res['skip_select_folder'] === (empty($res['children']) && $res['folder']['opnamed_today'] === null), 'ZULFA: skip_select_folder = tanpa subfolder dan belum diopname hari ini');
                    $o = q26_oracle($t, $z, array_keys(q26_locs($t)));
                    $t->eq($res['folder']['document_count'], $o['direct'], 'ZULFA: document_count = oracle');
                    $p = q26_preview($t, $s, q26_selection($z));
                    $t->status($p, 200, 'preview ZULFA');
                    $t->eq($p[1]['result']['scope'], ['is_root' => false, 'folder_name' => 'ZULFA', 'subfolder_count' => 0], 'ZULFA: scope = nama folder, tanpa subfolder');
                    $t->eq($p[1]['result']['counts']['total_documents'], $o['direct'], 'ZULFA: counts.total_documents = dokumen ZULFA');
                    $t->eq(count($p[1]['result']['folders']), 1, 'ZULFA: hanya baris folder level 0');
                } else {
                    $t->note('folder ZULFA tidak ada di DB uji: dicakup folder uji saja');
                }

                // --- folder uji daun
                $leaf = q26_folder($t, 'LEAF');
                $names = [];
                foreach (['a', 'b'] as $k) {
                    $names[$k] = q26_n($t, q26_doc($t, "L$k", ['parent' => $leaf]));
                }
                $before = q26_state($t);
                $r = q26_folders($t, $s, $leaf);
                $t->eq($r[1]['result']['children'], [], 'LEAF: children kosong');
                $t->true($r[1]['result']['folder']['opnamed_today'] === null && $r[1]['result']['skip_select_folder'] === true, 'LEAF: belum diopname -> skip_select_folder true');
                $t->eq($r[1]['result']['folder']['document_count'], 2, 'LEAF: document_count 2');
                $p = q26_preview($t, $s, q26_selection($leaf));
                $t->status($p, 200, 'preview LEAF (pilihan tanpa folders)');
                $res = $p[1]['result'];
                $t->eq($res['scope'], ['is_root' => false, 'folder_name' => q26_n($t, $leaf), 'subfolder_count' => 0], 'LEAF: scope tag = nama folder');
                $t->eq($res['counts']['total_documents'], 2, 'LEAF: total_documents 2');
                $t->eq(count($res['folders']), 1, 'LEAF: hanya baris level 0');
                $t->true(q26_same_state($before, q26_state($t)), 'folders + preview tanpa tulis (tabel opname + verifikasi archives sama)');
                $before = null;

                // --- ada subfolder -> tidak dilewati
                $x = q26_tree($t);
                $r = q26_folders($t, $s, $x['F']);
                $t->true($r[1]['result']['skip_select_folder'] === false, 'folder dengan subfolder: skip false');

                // --- tanpa subfolder tapi sudah diopname hari ini -> tidak dilewati (K-3)
                $id = q26_ok($t, $s, $x['S2'], [], null, [], 'Konfirmasi S2 (tanpa subfolder)');
                $r = q26_folders($t, $s, $x['S2']);
                $t->true($r[1]['result']['folder']['opnamed_today'] !== null, 'S2 sudah diopname hari ini');
                $t->eq($r[1]['result']['folder']['opnamed_today']['id_archive_opname'], $id, 'S2: opnamed_today = sesi tadi');
                $t->true($r[1]['result']['skip_select_folder'] === false, 'sudah diopname hari ini: skip false (K-3)');
                $t->eq($r[1]['result']['children'], [], 'S2 tanpa children');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-9',
        'title' => 'scan / preview / preview/documents / POST opnames: subfolder bukan anak langsung terlihat -> 400 ARCHIVE416; folder opname luar scope 403 ARCHIVE407 sebelum 422; body salah 422; tanpa tulis (tabel opname + verifikasi archives); POST valid menyimpan tepat satu sesi',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $B = Q26_BASE . '/opnames';
                $now = q26_now()->format('Y-m-d H:i:s');
                $yesterday = q26_now('-1 day')->format('Y-m-d') . ' 12:00:00';
                // keempat endpoint dengan body pilihan yang sama: nama => closure($body)
                $endpoints = [
                    'scan' => function ($b) use ($t, $s, $B) {
                        return $t->call($s, 'POST', "$B/scan", $b + ['code' => 'QA26-X']);
                    },
                    'preview' => function ($b) use ($t, $s, $B) {
                        return $t->call($s, 'POST', "$B/preview", $b);
                    },
                    'preview/documents' => function ($b) use ($t, $s, $B) {
                        return $t->call($s, 'POST', "$B/preview/documents", $b);
                    },
                    'POST opnames' => function ($b) use ($t, $s, $B, $now) {
                        return $t->call($s, 'POST', $B, $b + ['selected_at' => $now]);
                    },
                ];
                $L = q26_folder($t, 'LOCKSUB', ['parent' => $x['F'], 'perm' => 1, 'by' => 'someoneelse']);
                $before = q26_state($t);

                // --- 400 ARCHIVE416: bukan anak langsung / tak terlihat / nonaktif / F sendiri / dokumen / acak / terkunci
                foreach ($endpoints as $name => $call) {
                    foreach ([
                        'sub-subfolder (cucu)' => $x['S1a'],
                        'folder lain (OTH)' => $x['OTH'],
                        'folder nonaktif (SX)' => $x['SX'],
                        'F sendiri' => $x['F'],
                        'id dokumen' => $x['dF1'],
                        'id acak' => Q26_RANDOM_ID,
                        'subfolder terkunci (tanpa View)' => $L,
                    ] as $label => $bad) {
                        $r = $call(q26_selection($x['F'], [['id_archive' => $x['S1']], ['id_archive' => $bad]]));
                        q26_deny($t, $r, 400, 'ARCHIVE416', "$name 416 $label");
                    }
                }
                $t->true(q26_same_state($before, q26_state($t)), '416: tidak ada tulis (tabel opname + verifikasi archives)');

                // user Semarang: subfolder lokasi JOG (SJ) tak terlihat -> 416; terlihat -> 200 (tanpa menyimpan)
                q26_with_user($t, ['emp' => ['SMR']], function () use ($t, $s, $x, $endpoints) {
                    foreach (['scan', 'preview', 'preview/documents'] as $name) {
                        $r = $endpoints[$name](q26_selection($x['F'], q26_sel([$x['SJ']])));
                        q26_deny($t, $r, 400, 'ARCHIVE416', "Semarang $name: SJ lokasi JOG -> 416");
                        $r = $endpoints[$name](q26_selection($x['F'], q26_sel([$x['S1']])));
                        $t->status($r, 200, "Semarang $name: S1 -> 200");
                    }
                });

                // --- 403 / 404 / 400 untuk folder opname, dicek di authorize() sebelum 422
                $jogja = $t->db()->table('archives')->where('name', 'CABANG - JOGJA')->where('type', 1)->value('id_archive');
                $jogja = $jogja ?: $x['SJ'];
                q26_with_user($t, ['emp' => ['SMR']], function () use ($t, $endpoints, $jogja, $x) {
                    foreach ($endpoints as $name => $call) {
                        $r = $call(['id_archive' => $jogja, 'folders' => 'bukan-array', 'scans' => 'x']);
                        q26_deny($t, $r, 403, 'ARCHIVE407', "$name: F luar scope + body salah -> 403 sebelum 422");
                        $r = $call(q26_selection($x['SJ']));
                        q26_deny($t, $r, 403, 'ARCHIVE407', "$name: F = subfolder lokasi JOG");
                    }
                });
                foreach ($endpoints as $name => $call) {
                    q26_deny($t, $call(q26_selection($x['dF1'])), 400, 'ARCHIVE417', "$name: F = dokumen");
                    q26_deny($t, $call(q26_selection(Q26_RANDOM_ID)), 404, 'ARCHIVE400', "$name: F tidak ada");
                    q26_deny($t, $call(q26_selection($x['SX'])), 404, 'ARCHIVE400', "$name: F nonaktif");
                    q26_deny($t, $call(q26_selection($L)), 403, 'ARCHIVE407', "$name: F tanpa View efektif");
                }
                $t->true(q26_same_state($before, q26_state($t)), '403/404/400: tidak ada tulis');

                // --- 422: pilihan salah
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
                    'scans bukan array' => ['id_archive' => $x['F'], 'scans' => 'abc'],
                    'scans.*.code hilang' => ['id_archive' => $x['F'], 'scans' => [['scanned_at' => $now]]],
                    'scans.*.code kosong' => ['id_archive' => $x['F'], 'scans' => [['code' => '', 'scanned_at' => $now]]],
                    'scans.*.code > 255' => ['id_archive' => $x['F'], 'scans' => [['code' => str_repeat('a', 256), 'scanned_at' => $now]]],
                    'scans.*.code non-Latin-1' => ['id_archive' => $x['F'], 'scans' => [['code' => '日本語', 'scanned_at' => $now]]],
                    'scans.*.code array' => ['id_archive' => $x['F'], 'scans' => [['code' => ['a'], 'scanned_at' => $now]]],
                    'scans.*.scanned_at hilang' => ['id_archive' => $x['F'], 'scans' => [['code' => 'abc']]],
                    'scans.*.scanned_at format salah' => ['id_archive' => $x['F'], 'scans' => [['code' => 'abc', 'scanned_at' => '09/10/2026 10:00']]],
                    'scans.*.scanned_at ISO' => ['id_archive' => $x['F'], 'scans' => [['code' => 'abc', 'scanned_at' => str_replace(' ', 'T', $now) . 'Z']]],
                ];
                foreach (['preview', 'preview/documents', 'POST opnames'] as $name) {
                    foreach ($bad as $label => $body) {
                        $r = $endpoints[$name]($body);
                        q26_no500($t, $r, "$name 422 $label");
                        $t->status($r, 422, "$name 422 $label");
                    }
                }
                // scan: pilihan salah + code salah
                foreach ($bad as $label => $body) {
                    if (strpos($label, 'scans') === 0) {
                        continue;
                    }
                    $r = $endpoints['scan']($body);
                    q26_no500($t, $r, "scan 422 $label");
                    $t->status($r, 422, "scan 422 $label");
                }
                foreach (['tanpa code' => [], 'code null' => ['code' => null], 'code kosong' => ['code' => ''], 'code hanya spasi' => ['code' => '   '],
                    'code 256' => ['code' => str_repeat('a', 256)], 'code kanji' => ['code' => '日本語'], 'code array' => ['code' => ['a']], 'code angka' => ['code' => 12345]] as $label => $extra) {
                    $r = $t->call($s, 'POST', "$B/scan", q26_selection($x['F']) + $extra);
                    q26_no500($t, $r, "scan $label");
                    $t->status($r, 422, "scan $label -> 422");
                }
                // POST opnames: selected_at wajib dan berformat
                foreach (['tanpa selected_at' => [], 'selected_at null' => ['selected_at' => null], 'selected_at kosong' => ['selected_at' => ''],
                    'selected_at format salah' => ['selected_at' => '2026-10-09'], 'selected_at ISO' => ['selected_at' => str_replace(' ', 'T', $now) . 'Z'],
                    'selected_at angka' => ['selected_at' => 1760000000], 'selected_at array' => ['selected_at' => [$now]]] as $label => $extra) {
                    $r = $t->call($s, 'POST', $B, q26_selection($x['F']) + $extra);
                    q26_no500($t, $r, "POST opnames $label");
                    $t->status($r, 422, "POST opnames $label -> 422");
                }
                // preview/documents: result tak dikenal -> 422
                foreach (['xyz', ['all'], 5] as $v) {
                    $r = $t->call($s, 'POST', "$B/preview/documents", q26_selection($x['F']) + ['result' => $v]);
                    q26_no500($t, $r, 'preview/documents result ' . json_encode($v));
                    $t->status($r, 422, 'preview/documents result ' . json_encode($v) . ' -> 422');
                }
                $t->true(q26_same_state($before, q26_state($t)), '422: tidak ada tulis');

                // --- urutan POST opnames: 422 -> 415 -> 416 (selected_at kemarin + subfolder salah = 415)
                $r2 = $t->call($s, 'POST', $B, q26_selection($x['F'], [['id_archive' => $x['S1a']]]) + ['selected_at' => $yesterday]);
                q26_deny($t, $r2, 400, 'ARCHIVE415', 'urutan: selected_at kemarin + subfolder salah = 415 sebelum 416');
                $t->true(q26_same_state($before, q26_state($t)), '415: tidak ada tulis');

                // --- body camelCase (kunci terluar dikonversi)
                $r = $t->call($s, 'POST', "$B/preview", ['idArchive' => $x['F'], 'isContinue' => false, 'folders' => [['idArchive' => $x['S1']]]]);
                $t->status($r, 200, 'preview camelCase (idArchive)');
                $t->eq($r[1]['result']['id_archive'], $x['F'], 'camelCase: idArchive dipakai sebagai folder opname');
                $t->eq($r[1]['result']['scope']['subfolder_count'], 1, 'camelCase: folders.*.idArchive dikenali');

                // --- root, tanpa folders, body kosong = root (pratinjau saja)
                $r = $t->call($s, 'POST', "$B/preview", []);
                $t->status($r, 200, 'preview body kosong = root');
                $t->true($r[1]['result']['scope']['is_root'] === true && $r[1]['result']['id_archive'] === null && $r[1]['result']['scope']['folder_name'] === null, 'body kosong: scope root');
                $r = $t->call($s, 'POST', "$B/preview", ['id_archive' => '', 'folders' => []]);
                $t->status($r, 200, 'preview id_archive "" = root');
                $t->true(q26_same_state($before, q26_state($t)), 'scan/preview/preview documents tidak menulis (tabel opname + verifikasi archives)');

                // --- POST valid: tepat satu sesi tersimpan (terkonfirmasi), user login
                $uname = q26_uname($t);
                $count = $t->db()->table('archive_opnames')->count();
                $o = q26_do($t, $s, $x['F'], [['id_archive' => $x['S2'], 'is_continue' => true], ['id_archive' => $x['S1'], 'is_continue' => false]], true);
                $t->status($o['r'], 200, 'POST valid');
                $t->eq($o['r'][1]['msg_code'] ?? null, 'ARCHIVE213', 'POST valid: ARCHIVE213');
                $t->eq(array_keys($o['r'][1]['result']), ['id_archive_opname'], 'POST valid: result hanya id_archive_opname');
                $t->eq($t->db()->table('archive_opnames')->count(), $count + 1, 'tepat satu sesi baru tersimpan');
                $row = q26_opname($t, $o['id']);
                $t->eq([(int) $row['status'], $row['id_archive'], $row['created_by'], $row['updated_by']], [2, $x['F'], $uname, $uname], 'DB: status 2, folder F, created_by/updated_by user login');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-9-LEN',
        'title' => '(tambahan AC-9, kontrak §selection) id_archive > 30 karakter di scan / preview / preview/documents / POST opnames: rule max:30 -> 422 (GET opnames/folders juga 422)',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $before = q26_state($t);
                $B = Q26_BASE . '/opnames';
                $long = str_repeat('a', 31);
                $r = $t->call($s, 'GET', $B . '/folders?id_archive=' . rawurlencode($long));
                $t->status($r, 422, 'GET folders: id 31 karakter');
                $now = q26_now()->format('Y-m-d H:i:s');
                foreach (['scan' => ['code' => 'x'], 'preview' => [], 'preview/documents' => [], '' => ['selected_at' => $now]] as $path => $extra) {
                    $r = $t->call($s, 'POST', rtrim("$B/$path", '/'), ['id_archive' => $long, 'folders' => []] + $extra);
                    q26_no500($t, $r, "POST opnames/$path id 31 karakter");
                    $t->status($r, 422, "POST opnames/$path: id_archive 31 karakter (max 30 -> 422)");
                }
                $t->true(q26_same_state($before, q26_state($t)), 'tanpa tulis');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-3',
        'title' => '(bagian BE) Tanpa permission Opname Document: folders / scan / preview / preview/documents / POST opnames -> 403 GE0114 (parameter = Opname Document) untuk role 6, role 29 dan QA_USER2; tanpa token 401; DB tidak berubah; role 3 dan superadmin 1/2 lolos. Baca sesi (GET opnames*) = ED-1028, tidak diuji',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $B = Q26_BASE . '/opnames';
                $now = q26_now()->format('Y-m-d H:i:s');
                $sel = q26_selection($x['F'], q26_sel([$x['S1']]));
                $routes = [
                    'GET folders' => ['GET', "$B/folders?id_archive=" . $x['F'], null],
                    'POST scan' => ['POST', "$B/scan", $sel + ['code' => 'x']],
                    'POST preview' => ['POST', "$B/preview", $sel + ['scans' => []]],
                    'POST preview/documents' => ['POST', "$B/preview/documents", $sel + ['scans' => [], 'result' => 'all']],
                    'POST opnames' => ['POST', $B, $sel + ['scans' => [], 'selected_at' => $now]],
                ];
                $forbid = function ($r, $label) use ($t) {
                    $t->status($r, 403, $label);
                    $t->eq(q26_code($r), 'GE0114', $label . ': code GE0114');
                    $t->eq($r[1]['parameter'] ?? null, 'Opname Document', $label . ': parameter');
                };
                $before = q26_state($t);

                // tanpa token
                foreach ($routes as $label => $r) {
                    $res = $t->raw($r[0], $r[1], $r[2]);
                    $t->status($res, 401, "tanpa token: $label");
                }

                // role 6 (tanpa permission Archive) dan role 29 (hanya List Archive): semua 403 GE0114
                foreach ([Q26_ROLE_NONE, Q26_ROLE_LIST_ONLY] as $role) {
                    q26_with_user($t, ['role' => $role], function () use ($t, $s, $routes, $forbid, $role) {
                        foreach ($routes as $label => $r) {
                            $forbid($t->call($s, $r[0], $r[1], $r[2]), "role $role: $label");
                        }
                    });
                }
                $t->true(q26_same_state($before, q26_state($t)), 'DB tidak berubah oleh penolakan role 6/29');

                // role 3 (QA default, punya Opname Document) dan superadmin 1/2: lolos, masing-masing satu sesi di folder uji sendiri
                foreach ([3, 1, 2] as $role) {
                    q26_with_user($t, ['role' => $role], function () use ($t, $s, $x, $role, $sel) {
                        $r = q26_folders($t, $s, $x['F']);
                        $t->status($r, 200, "role $role: folders");
                        $t->status(q26_scan($t, $s, $sel, 'QA26-X'), 200, "role $role: scan");
                        $t->status(q26_preview($t, $s, $sel), 200, "role $role: preview");
                        $t->status(q26_pdocs($t, $s, $sel), 200, "role $role: preview/documents");
                        $leaf = q26_folder($t, "R$role");
                        $id = q26_ok($t, $s, $leaf, [], null, [], "role $role: POST opnames");
                        $t->true($id !== null, "role $role: sesi tersimpan");
                    });
                }

                // QA_USER2 (role tanpa Opname Document, login sungguhan): 403 GE0114 pada semua
                $s2 = $t->login('QA_USER2');
                $b = $t->bind($s2, q26_dbname($t));
                $t->status($b, 200, 'QA_USER2: bind DB');
                $stateMid = q26_state($t);
                foreach ($routes as $label => $r) {
                    $forbid($t->call($s2, $r[0], $r[1], $r[2]), "QA_USER2: $label");
                }
                $t->true(q26_same_state($stateMid, q26_state($t)), 'DB tidak berubah oleh penolakan QA_USER2');
                $t->note('GET opnames, opnames/{id}, opnames/{id}/documents (baca sesi) = permission milik ED-1028; tidak diasersikan di item ini (spec AC-3)');
            } finally {
                q26_cleanup($t);
            }
        },
    ],
];
