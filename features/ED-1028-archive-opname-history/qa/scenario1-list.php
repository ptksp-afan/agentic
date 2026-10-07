<?php
/**
 * ED-1028 - daftar sesi Opname History (GET document-archive/opnames): AC-1 (kolom, query, display setting, urutan, bentuk
 * baris, pagination), AC-2 (angka = kolom archive_opnames, scope = GET opnames/{id}, angka tak berubah), AC-3 (draft & batal).
 * Sesi dibuat lewat API opname ED-1026 oleh superadmin sementara (role 1); semua dipulihkan di finally (q28_guard).
 */
require_once __DIR__ . '/qa_hist.php';

$q28_session_keys = ['id_archive_opname', 'id_archive', 'confirmed_at', 'created_by', 'scope', 'total_documents', 'verified_count', 'not_found_count', 'invalid_count'];

return [

    [
        'id'    => 'AC-1',
        'title' => 'GET opnames: 200 ARCHIVE200, paginator + columns (7 kolom urut BR-6) + queries (confirmedAt dateTimeRange, createdBy select opname-users, idArchives select folders multiple); baris column_display_settings terbentuk otomatis; urut confirmed_at turun; bentuk baris; pagination; keadaan kosong',
        'run'   => function ($t) use ($q28_session_keys) {
            $s = $t->session();
            q28_guard($t, function ($ds) use ($t, $s, $q28_session_keys) {
                // keadaan awal: tanpa baris display setting -> request pertama membuatnya (tanpa Updater)
                q28_ds_restore($t, null);
                $t->eq(q28_dsrow($t), null, 'prasyarat: baris display setting belum ada');

                // --- kosong (BR-15): 200, tanpa error, columns & queries tetap ada
                $r = q28_hist($t, $s);
                $t->status($r, 200, 'kosong');
                $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE200', 'kosong: msg_code');
                $res = $r[1]['result'];
                $t->eq($res['data'], [], 'kosong: data []');
                $t->eq($res['total'], 0, 'kosong: total 0');
                $t->eq(count($res['columns']), 7, 'kosong: 7 kolom');
                $t->eq(count($res['queries']), 3, 'kosong: 3 query');

                $row = q28_dsrow($t);
                $t->true($row !== null, 'baris column_display_settings documentArchiveOpnameHistory terbentuk pada request pertama');
                $t->eq($t->db()->table('column_display_settings')->where('module_name', 'documentArchiveOpnameHistory')->count(), 1, 'tepat 1 baris');
                q28_hist($t, $s);
                $t->eq($t->db()->table('column_display_settings')->where('module_name', 'documentArchiveOpnameHistory')->count(), 1, 'request kedua tidak menggandakan baris');

                // --- terhadap config di BE_DIR
                $config = require rtrim($t->conf('BE_DIR'), '/') . '/Modules/V5/Config/displayColumn/documentArchiveOpnameHistory.php';
                $t->eq($config['module_name'], 'documentArchiveOpnameHistory', 'config module_name');
                $idx = function (array $cols) {
                    return array_column($cols, 'data_index');
                };
                $expect = ['confirmedAt', 'createdBy', 'scope', 'totalDocuments', 'verifiedCount', 'notFoundCount', 'invalidCount'];
                $t->eq($idx($res['columns']), $expect, 'columns: urutan BR-6 (Waktu, User, Scope, Total dokumen, Verified, Not found, Invalid)');
                $cur = json_decode($row['current_columns'], true);
                $avail = json_decode($row['available_columns'], true);
                $t->eq($idx($cur), $expect, 'DB current_columns = 7 kolom aktif');
                $t->eq($idx($avail), array_merge($expect, ['scannedCount', 'unscannedCount']), 'DB available_columns = 7 + Scanned + Belum discan');
                $t->eq($res['columns'], $cur, 'respons columns = DB current_columns');
                $byIdx = [];
                foreach ($res['columns'] as $c) {
                    $byIdx[$c['data_index']] = $c;
                    $t->true(!empty($c['title']['en']) && !empty($c['title']['id']), "kolom {$c['data_index']}: judul en & id");
                }
                $t->eq($byIdx['confirmedAt']['type'], 'dateTime', 'Waktu tipe dateTime');
                $t->eq($byIdx['scope']['sort'], false, 'Scope tidak bisa di-sort');
                foreach (['confirmedAt', 'createdBy', 'totalDocuments', 'verifiedCount', 'notFoundCount', 'invalidCount'] as $k) {
                    $t->eq($byIdx[$k]['sort'], true, "$k sortable");
                }
                $t->eq($avail[7]['title'], ['en' => 'Scanned', 'id' => 'Discan'], 'tersedia: Scanned');
                $t->eq($avail[8]['title'], ['en' => 'Not Scanned', 'id' => 'Belum Discan'], 'tersedia: Belum discan');

                $q = $res['queries'];
                $t->eq(array_column($q, 'data_index'), ['confirmedAt', 'createdBy', 'idArchives'], 'queries: 3 query berurutan');
                $t->eq($q[0]['type'], 'dateTimeRange', 'query Waktu Opname = dateTimeRange');
                $t->eq($q[1]['type'], 'select', 'query User = select');
                $t->eq($q[1]['endpoint'], 'select/document-archive/archive/opname-users', 'query User endpoint');
                $t->eq($q[2]['type'], 'select', 'query Folder = select');
                $t->eq($q[2]['endpoint'], 'select/document-archive/archive/folders', 'query Folder endpoint');
                $t->eq($q[2]['multiple'] ?? null, true, 'query Folder multiple');
                $t->eq(json_decode($row['available_queries'], true), json_decode($row['current_queries'], true), 'available_queries = current_queries');
                $t->eq(array_column(json_decode($row['current_queries'], true), 'data_index'), ['confirmedAt', 'createdBy', 'idArchives'], 'DB current_queries');

                // --- dengan sesi
                $x = null;
                $ids = null;
                q28_with_user($t, ['role' => 1], function () use ($t, $s, &$x, &$ids) {
                    $x = q28_tree($t);
                    $ids = q28_hfix($t, $s, $x);
                });
                $r = q28_hist($t, $s, ['pagination' => 100]);
                $t->status($r, 200, 'dengan sesi');
                $res = $r[1]['result'];
                foreach (['current_page', 'data', 'first_page_url', 'from', 'last_page', 'last_page_url', 'next_page_url', 'path', 'per_page', 'prev_page_url', 'to', 'total', 'columns', 'queries'] as $k) {
                    $t->true(array_key_exists($k, $res), "paginator: kunci $k");
                }
                // user QA (role 3, semua lokasi, bukan superadmin): E (SP tanpa View) tak terlihat
                $u = q28_uu($t, 'user3');
                $exp = q28_oracle_sessions($t, $u);
                $t->true(count($exp) === 6, 'oracle: 6 sesi terlihat user QA (A,B,C,D,Y,Z; E = SP tanpa View): ' . count($exp));
                $t->eq(count(q28_hids($r)), count($exp), 'jumlah baris = oracle');
                $t->eq($res['total'], count($exp), 'total = oracle');
                $t->eq(q28_hkeys(q28_hids($r), $ids), ['Z', 'Y', 'D', 'C', 'B', 'A'], 'urut confirmed_at turun (Z, Y, D, C, B, A)');
                $conf = array_column($res['data'], 'confirmed_at');
                $sorted = $conf;
                rsort($sorted);
                $t->eq($conf, $sorted, 'confirmed_at non-increasing');
                foreach ($res['data'] as $row) {
                    $keys = array_keys($row);
                    $a = $keys;
                    $b = $q28_session_keys;
                    sort($a);
                    sort($b);
                    $t->eq($a, $b, 'kunci baris = kolom aktif + id_archive_opname/id_archive/scope: ' . implode(',', $keys));
                    $t->true(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['confirmed_at']) === 1, 'confirmed_at format Y-m-d H:i:s');
                    $t->true(is_int($row['total_documents']) && is_int($row['verified_count']) && is_int($row['not_found_count']) && is_int($row['invalid_count']), 'angka integer');
                }

                // --- pagination
                $p1 = q28_hist($t, $s, ['pagination' => 2, 'page' => 1]);
                $p2 = q28_hist($t, $s, ['pagination' => 2, 'page' => 2]);
                $p3 = q28_hist($t, $s, ['pagination' => 2, 'page' => 3]);
                $p4 = q28_hist($t, $s, ['pagination' => 2, 'page' => 4]);
                $t->eq(q28_hkeys(q28_hids($p1), $ids), ['Z', 'Y'], 'halaman 1');
                $t->eq(q28_hkeys(q28_hids($p2), $ids), ['D', 'C'], 'halaman 2');
                $t->eq(q28_hkeys(q28_hids($p3), $ids), ['B', 'A'], 'halaman 3');
                $t->eq($p1[1]['result']['last_page'], 3, 'last_page 3');
                $t->eq($p1[1]['result']['per_page'], 2, 'per_page 2');
                $t->eq($p1[1]['result']['total'], 6, 'total tetap 6');
                $t->status($p4, 200, 'halaman di luar jangkauan 200');
                $t->eq($p4[1]['result']['data'], [], 'halaman 4: kosong');
                foreach ([['pagination' => 0], ['pagination' => -3], ['pagination' => 'abc'], ['page' => 0], ['page' => 'x'], ['page' => -2]] as $odd) {
                    $o = q28_hist($t, $s, $odd);
                    q28_no500($t, $o, 'parameter aneh ' . json_encode($odd));
                    $t->status($o, 200, 'parameter aneh ' . json_encode($odd));
                }

                // --- bahasa: pesan mengikuti bahasa user (QA_USER = ID)
                $t->eq($r[1]['message'], 'Dokumen ditemukan', 'pesan ARCHIVE200 bahasa ID');
                $s2 = q28_u2_session($t);
                $r2 = q28_hist($t, $s2);
                $t->status($r2, 200, 'user2 (EN)');
                $t->eq($r2[1]['message'], 'Document is found', 'pesan ARCHIVE200 bahasa EN');
                $t->call($s2, 'GET', 'api/v5/auth/log-out');
            });
        },
    ],

    [
        'id'    => 'AC-2',
        'title' => 'baris sesi terkonfirmasi: angka = kolom archive_opnames (dan angka tangan sesi A), scope = scope GET opnames/{id}; dokumen ditambah ke folder sesi sesudahnya -> angka tak berubah',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s) {
                $x = null;
                $ids = null;
                q28_with_user($t, ['role' => 1], function ($set) use ($t, $s, &$x, &$ids) {
                    $x = q28_tree($t);
                    $ids = q28_hfix($t, $s, $x);

                    // sesi dengan banyak subfolder: root + 3 subfolder (scope "<pertama> + N subfolder"), folder + 2 subfolder
                    $ids['R'] = q28_run($t, $s, null, q28_sel([$x['OTH'], $x['PRIV'], $x['JOGR']]), []);
                    $ids['M'] = q28_run($t, $s, $x['F'], q28_sel([$x['S1'], $x['S2']]), []);
                    q28_set_opname($t, $ids['R'], ['confirmed_at' => date('Y-m-d', strtotime('-2 days')) . ' 12:00:00']);
                    q28_set_opname($t, $ids['M'], ['confirmed_at' => date('Y-m-d', strtotime('-1 days')) . ' 12:00:00']);

                    // superadmin: semua sesi terkonfirmasi (9)
                    $r = q28_hist($t, $s, ['pagination' => 100]);
                    $t->status($r, 200, 'superadmin');
                    $t->eq(count($r[1]['result']['data']), 9, 'superadmin: 9 sesi terkonfirmasi');
                    $before = [];
                    foreach ($r[1]['result']['data'] as $row) {
                        $k = array_search($row['id_archive_opname'], $ids, true);
                        $db = q28_opname($t, $row['id_archive_opname']);
                        $before[$k] = $row;
                        foreach (['total_documents', 'verified_count', 'not_found_count', 'invalid_count'] as $f) {
                            $t->eq($row[$f], (int) $db[$f], "sesi $k: $f = kolom archive_opnames");
                        }
                        $t->eq($row['created_by'], $db['created_by'], "sesi $k: created_by = DB");
                        $t->eq($row['confirmed_at'], $db['confirmed_at'], "sesi $k: confirmed_at = DB");
                        $t->eq($row['id_archive'], $db['id_archive'], "sesi $k: id_archive = DB");
                        $show = $t->call($s, 'GET', Q28_BASE . '/opnames/' . $row['id_archive_opname']);
                        $t->status($show, 200, "show $k");
                        $t->eq($row['scope'], $show[1]['result']['scope'], "sesi $k: scope = scope GET opnames/{id}");
                        $t->eq(array_keys($row['scope']), ['is_root', 'folder_name', 'first_name', 'other_count'], "sesi $k: kunci scope");
                    }
                    // angka tangan sesi A (F 6 + S1 2 + S1a 1 = 9 dokumen; scan dF2 & d11 sah, dO1 di luar scope, 1 kode tak dikenal)
                    $t->eq([$before['A']['total_documents'], $before['A']['verified_count'], $before['A']['not_found_count'], $before['A']['invalid_count']], [9, 2, 1, 1], 'sesi A: angka tangan 9/2/1/1');
                    $t->eq($before['A']['scope'], ['is_root' => false, 'folder_name' => q28_n($t, $x['F']), 'first_name' => q28_n($t, $x['S1']), 'other_count' => 0], 'sesi A: scope F + S1');
                    $t->eq($before['C']['scope']['is_root'], true, 'sesi C: scope root');
                    $t->eq($before['C']['id_archive'], null, 'sesi C: id_archive null');
                    $t->eq($before['B']['scope'], ['is_root' => false, 'folder_name' => q28_n($t, $x['S2']), 'first_name' => null, 'other_count' => 0], 'sesi B: scope nama folder');
                    $dbR = q28_opname($t, $ids['R']);
                    $t->eq([$before['R']['scope']['is_root'], $before['R']['scope']['folder_name'], $before['R']['scope']['other_count']], [true, null, 2], 'sesi R: root + 3 subfolder -> is_root, other_count 2');
                    $t->eq($before['R']['scope']['first_name'], $dbR['scope_name'], 'sesi R: first_name = scope_name tersimpan');
                    $t->eq([$before['M']['scope']['is_root'], $before['M']['scope']['folder_name'], $before['M']['scope']['other_count']], [false, q28_n($t, $x['F']), 1], 'sesi M: F + 2 subfolder -> folder_name F, other_count 1');
                    $t->true(in_array($before['M']['scope']['first_name'], [q28_n($t, $x['S1']), q28_n($t, $x['S2'])], true), 'sesi M: first_name salah satu subfolder yang dicentang');

                    // angka tersimpan: dokumen ditambah / diubah sesudah Confirm tidak mengubah baris
                    $new1 = q28_doc($t, 'late1', ['parent' => $x['F']], 6);
                    $new2 = q28_doc($t, 'late2', ['parent' => $x['S1']], 7);
                    $new3 = q28_doc($t, 'late3', ['parent' => $x['S2']], 8);
                    $nr = q28_hist($t, $s, ['pagination' => 100]);
                    $after = [];
                    foreach ($nr[1]['result']['data'] as $row) {
                        $after[array_search($row['id_archive_opname'], $ids, true)] = $row;
                    }
                    $t->eq($after, $before, 'sesudah dokumen ditambah ke F/S1/S2: semua baris sama persis');
                    q28_set_archive($t, $x['dF2'], ['is_verified' => 0, 'verified_at' => null]);
                    q28_set_archive($t, $x['dF1'], ['is_active' => 0]);
                    $nr = q28_hist($t, $s, ['pagination' => 100]);
                    $after = [];
                    foreach ($nr[1]['result']['data'] as $row) {
                        $after[array_search($row['id_archive_opname'], $ids, true)] = $row;
                    }
                    $t->eq($after, $before, 'sesudah dokumen di-unverify / dinonaktifkan: baris tetap sama (nilai rekaman, bukan hitung ulang)');
                    $show = $t->call($s, 'GET', Q28_BASE . '/opnames/' . $ids['A']);
                    $t->eq($show[1]['result']['counts']['total_documents'], 9, 'GET opnames/{A}: counts tetap angka rekaman');
                });
            });
        },
    ],

    [
        'id'    => 'AC-3',
        'title' => 'draft dan sesi batal tidak pernah ada di list, juga untuk pembuatnya (user biasa dan superadmin)',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s) {
                $x = null;
                $ids = null;
                q28_with_user($t, ['role' => 1], function ($set) use ($t, $s, &$x, &$ids) {
                    $x = q28_tree($t);
                    $ids = q28_hfix($t, $s, $x);
                    // draft milik user uji sendiri (pembuat) + satu draft lain di folder lain
                    $ids['J'] = q28_session($t, $s, $x['OTH']);
                    $dbH = q28_opname($t, $ids['H']);
                    $dbJ = q28_opname($t, $ids['J']);
                    $dbK = q28_opname($t, $ids['K']);
                    $t->true((int) $dbH['status'] !== 2 && (int) $dbJ['status'] !== 2 && (int) $dbK['status'] !== 2, 'H, J draft dan K batal: status bukan 2 (' . $dbH['status'] . ',' . $dbJ['status'] . ',' . $dbK['status'] . ')');
                    $t->true((int) $dbH['status'] !== (int) $dbK['status'], 'draft dan batal berstatus beda (kasus uji benar-benar dua jenis)');

                    $check = function ($label) use ($t, $s, $ids) {
                        $r = q28_hist($t, $s, ['pagination' => 100]);
                        $t->status($r, 200, "$label: list");
                        foreach (['H', 'J', 'K'] as $bad) {
                            $t->true(!in_array($ids[$bad], q28_hids($r), true), "$label: sesi $bad (draft/batal) tidak muncul");
                        }
                        $t->eq($r[1]['result']['total'], 7, "$label: total hanya sesi terkonfirmasi (7)");
                        // pembuat sesi: dengan konteks folder & filter user juga tidak muncul
                        $r2 = q28_hist($t, $s, ['pagination' => 100, 'search' => ['createdBy' => (string) q28_uname($t)]]);
                        $t->true(!in_array($ids['J'], q28_hids($r2), true) && !in_array($ids['K'], q28_hids($r2), true), "$label: filter user pembuat: draft J & batal K tidak muncul");
                    };
                    $check('superadmin (role 1)');
                    $set([]);
                    // role asli (3): ceklist 6 sesi terlihat
                    $r = q28_hist($t, $s, ['pagination' => 100]);
                    foreach (['H', 'J', 'K'] as $bad) {
                        $t->true(!in_array($ids[$bad], q28_hids($r), true), "user QA role 3: sesi $bad tidak muncul");
                    }
                    $t->eq($r[1]['result']['total'], 6, 'user QA role 3: 6 sesi terkonfirmasi terlihat');
                    $rf = q28_hist($t, $s, ['id_archive' => $x['F'], 'pagination' => 100]);
                    foreach (['H', 'J', 'K'] as $bad) {
                        $t->true(!in_array($ids[$bad], q28_hids($rf), true), "konteks folder F: sesi $bad tidak muncul");
                    }
                    // setelah draft J dibatalkan dan H dikonfirmasi? (H milik qa28bob: tetap draft) -> J dibatalkan: tetap tak muncul
                    $t->status($t->call($s, 'DELETE', Q28_BASE . '/opnames/' . $ids['J']), 200, 'batalkan J');
                    $r = q28_hist($t, $s, ['pagination' => 100]);
                    $t->true(!in_array($ids['J'], q28_hids($r), true), 'J batal: tetap tidak muncul');
                    $t->eq($r[1]['result']['total'], 6, 'total tetap 6');
                    // draft yang di-konfirmasi muncul (kontrol positif)
                    $x1 = q28_doc($t, 'ctl', ['parent' => $x['OTH']], 6);
                    $ctl = q28_session($t, $s, $x['OTH']);
                    $t->status(q28_scan($t, $s, $ctl, q28_n($t, $x1)), 200, 'scan kontrol');
                    $r = q28_hist($t, $s, ['pagination' => 100]);
                    $t->true(!in_array($ctl, q28_hids($r), true), 'kontrol: sesi draft belum muncul');
                    $t->status(q28_confirm($t, $s, $ctl), 200, 'confirm kontrol');
                    $r = q28_hist($t, $s, ['pagination' => 100]);
                    $t->true(in_array($ctl, q28_hids($r), true), 'kontrol: setelah Confirm sesi muncul');
                    $t->eq($r[1]['result']['total'], 7, 'total 7 setelah confirm kontrol');
                });
            });
        },
    ],
];
