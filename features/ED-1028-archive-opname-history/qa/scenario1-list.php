<?php
/**
 * ED-1028 - daftar sesi Opname History (GET document-archive/opnames): AC-1 (kolom, query, display setting, urutan, bentuk
 * baris, pagination), AC-2 (rev: angka = kolom archive_opnames, scope = scope GET opnames/{id} = TEPAT {is_root, folder_name,
 * subfolder_count} (K-10 b; subfolder_count = kolom scope_folder_count), angka tak berubah).
 * AC-3 dihapus (FB-3: tidak ada draft/sesi batal di DB; "id bekas draft = 404" diuji di AC-12).
 * Sesi dibuat lewat API opname ED-1026 (satu POST opnames) oleh superadmin sementara (role 1); semua dipulihkan di finally (q28_guard).
 * (rev) Tahan data developer: asersi dilingkup ke sesi QA (q28_hkeys), total/halaman dari oracle yang ikut menghitung sesi asing.
 */
require_once __DIR__ . '/qa_hist.php';

$q28_session_keys = ['id_archive_opname', 'id_archive', 'confirmed_at', 'created_by', 'scope', 'total_documents', 'verified_count', 'not_found_count', 'invalid_count'];

return [

    [
        'id'    => 'AC-1',
        'title' => 'GET opnames: 200 ARCHIVE200, paginator + columns (7 kolom urut BR-6) + queries (confirmedAt dateTimeRange, createdBy select opname-users, idArchives select folders multiple); baris column_display_settings terbentuk otomatis; urut confirmed_at turun; bentuk baris; pagination; keadaan tanpa sesi QA',
        'run'   => function ($t) use ($q28_session_keys) {
            $s = $t->session();
            q28_guard($t, function ($ds) use ($t, $s, $q28_session_keys) {
                // keadaan awal: tanpa baris display setting -> request pertama membuatnya (tanpa Updater)
                q28_ds_restore($t, null);
                $t->eq(q28_dsrow($t), null, 'prasyarat: baris display setting belum ada');

                // --- tanpa sesi QA (BR-15): 200, tanpa error, columns & queries tetap ada; total = sesi yang sudah ada (data developer bila ada)
                $r = q28_hist($t, $s);
                $t->status($r, 200, 'tanpa sesi QA');
                $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE200', 'tanpa sesi QA: msg_code');
                $res = $r[1]['result'];
                $existing = q28_oracle_sessions($t, q28_uu($t, 'user3'));
                $t->eq($res['total'], count($existing), 'tanpa sesi QA: total = oracle sesi yang sudah ada (' . count($existing) . ')');
                if (count($existing) === 0) {
                    $t->eq($res['data'], [], 'tanpa sesi (tabel opname kosong): data []');
                }
                $t->eq(count($res['columns']), 7, 'tanpa sesi QA: 7 kolom');
                $t->eq(count($res['queries']), 3, 'tanpa sesi QA: 3 query');

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
                $t->true(q28_hcount($exp, $ids) === 6, 'oracle: 6 sesi QA terlihat user QA (A,B,C,D,Y,Z; E = SP tanpa View): ' . q28_hcount($exp, $ids));
                $all = q28_hids($r);
                $t->eq(count($all), count($exp), 'jumlah baris (semua halaman) = oracle (termasuk sesi developer bila ada)');
                $t->eq($res['total'], count($exp), 'total = oracle');
                $t->eq(q28_hkeys($all, $ids), ['Z', 'Y', 'D', 'C', 'B', 'A'], 'urut confirmed_at turun (Z, Y, D, C, B, A)');
                $conf = array_column($res['data'], 'confirmed_at');
                $sorted = $conf;
                rsort($sorted);
                $t->eq($conf, $sorted, 'confirmed_at non-increasing');
                $t->eq(q28_hcount(array_slice($all, 0, 6), $ids), 6, 'sesi QA ada di atas sesi developer: 6 baris pertama seluruhnya sesi QA');
                foreach ($res['data'] as $row) {
                    if (array_search($row['id_archive_opname'], $ids, true) === false) {
                        continue; // sesi developer: bentuk baris diperiksa juga, tetapi isi tidak
                    }
                    $keys = array_keys($row);
                    $a = $keys;
                    $b = $q28_session_keys;
                    sort($a);
                    sort($b);
                    $t->eq($a, $b, 'kunci baris = kolom aktif + id_archive_opname/id_archive/scope: ' . implode(',', $keys));
                    $t->true(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['confirmed_at']) === 1, 'confirmed_at format Y-m-d H:i:s');
                    $t->true(is_int($row['total_documents']) && is_int($row['verified_count']) && is_int($row['not_found_count']) && is_int($row['invalid_count']), 'angka integer');
                }

                // --- pagination (halaman 1-3 ukuran 2 = potongan urutan penuh; sesi QA di atas sesi developer)
                $p1 = q28_hist($t, $s, ['pagination' => 2, 'page' => 1]);
                $p2 = q28_hist($t, $s, ['pagination' => 2, 'page' => 2]);
                $p3 = q28_hist($t, $s, ['pagination' => 2, 'page' => 3]);
                $t->eq(q28_hids($p1), array_slice($all, 0, 2), 'halaman 1 = 2 baris pertama urutan penuh');
                $t->eq(q28_hids($p2), array_slice($all, 2, 2), 'halaman 2 = baris 3-4');
                $t->eq(q28_hids($p3), array_slice($all, 4, 2), 'halaman 3 = baris 5-6');
                $t->eq(q28_hkeys(q28_hids($p1), $ids), ['Z', 'Y'], 'halaman 1: Z, Y');
                $t->eq(q28_hkeys(q28_hids($p2), $ids), ['D', 'C'], 'halaman 2: D, C');
                $t->eq(q28_hkeys(q28_hids($p3), $ids), ['B', 'A'], 'halaman 3: B, A');
                $last = (int) ceil(count($exp) / 2);
                $t->eq($p1[1]['result']['last_page'], $last, "last_page $last (= ceil(total / 2))");
                $t->eq($p1[1]['result']['per_page'], 2, 'per_page 2');
                $t->eq($p1[1]['result']['total'], count($exp), 'total tetap = oracle');
                $pz = q28_hist($t, $s, ['pagination' => 2, 'page' => $last + 1]);
                $t->status($pz, 200, 'halaman di luar jangkauan 200');
                $t->eq($pz[1]['result']['data'], [], 'halaman di luar jangkauan: kosong');
                foreach ([['pagination' => 0], ['pagination' => -3], ['pagination' => 'abc'], ['page' => 0], ['page' => 'x'], ['page' => -2]] as $odd) {
                    $o = q28_hist($t, $s, $odd);
                    q28_no500($t, $o, 'parameter aneh ' . json_encode($odd));
                    $t->status($o, 200, 'parameter aneh ' . json_encode($odd));
                }

                // --- bahasa: pesan mengikuti bahasa user (QA_USER = ID); user2 = pemegang Opname Document (grant sementara guard)
                $t->eq($r[1]['message'], 'Dokumen ditemukan', 'pesan ARCHIVE200 bahasa ID');
                $s2 = q28_u2_session($t);
                $r2 = q28_hist($t, $s2);
                $t->status($r2, 200, 'user2 + Opname Document (EN)');
                $t->eq($r2[1]['message'], 'Document is found', 'pesan ARCHIVE200 bahasa EN');
                $t->call($s2, 'GET', 'api/v5/auth/log-out');
            });
        },
    ],

    [
        'id'    => 'AC-2',
        'title' => '(rev) baris sesi terkonfirmasi: angka = kolom archive_opnames (dan angka tangan sesi A), scope = scope GET opnames/{id} = tepat kunci is_root, folder_name, subfolder_count (subfolder_count = scope_folder_count; tanpa first_name/other_count); dokumen ditambah ke folder sesi sesudahnya -> angka tak berubah',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s) {
                $x = null;
                $ids = null;
                q28_with_user($t, ['role' => 1], function ($set) use ($t, $s, &$x, &$ids) {
                    $x = q28_tree($t);
                    // sesi dengan banyak subfolder: root + 3 subfolder, folder + 2 subfolder; dibuat SEBELUM confirmed_at fixture digeser
                    $ids = q28_hfix($t, $s, $x, [
                        'R' => ['at' => q28_day(2) . ' 12:00:00', 'run' => function () use ($t, $s, $x) {
                            return q28_run($t, $s, null, q28_sel([$x['OTH'], $x['PRIV'], $x['JOGR']]), []);
                        }],
                        'M' => ['at' => q28_day(1) . ' 12:00:00', 'run' => function () use ($t, $s, $x) {
                            return q28_run($t, $s, $x['F'], q28_sel([$x['S1'], $x['S2']]), []);
                        }],
                    ]);

                    // superadmin: semua sesi terkonfirmasi (9 sesi QA; sesi developer bila ada ikut tetapi tidak diperiksa isinya)
                    $r = q28_hist($t, $s, ['pagination' => 100]);
                    $t->status($r, 200, 'superadmin');
                    $mine = [];
                    foreach ($r[1]['result']['data'] as $row) {
                        if (array_search($row['id_archive_opname'], $ids, true) !== false) {
                            $mine[] = $row;
                        }
                    }
                    $t->eq(count($mine), 9, 'superadmin: 9 sesi QA terkonfirmasi (A,B,C,D,E,Y,Z,R,M)');
                    $t->eq($r[1]['result']['total'], count(q28_oracle_sessions($t, q28_uu($t, 'super'))), 'superadmin: total = oracle');
                    $before = [];
                    foreach ($mine as $row) {
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
                        $keys = array_keys($row['scope']);
                        sort($keys);
                        $t->eq($keys, ['folder_name', 'is_root', 'subfolder_count'], "sesi $k: kunci scope tepat is_root, folder_name, subfolder_count");
                        $t->true(!array_key_exists('first_name', $row['scope']) && !array_key_exists('other_count', $row['scope']), "sesi $k: first_name / other_count tidak ada");
                        $t->eq($row['scope']['subfolder_count'], (int) $db['scope_folder_count'], "sesi $k: subfolder_count = kolom scope_folder_count");
                        $t->eq($row['scope']['is_root'], $db['id_archive'] === null, "sesi $k: is_root = (id_archive null)");
                    }
                    // angka tangan sesi A (F 6 + S1 2 + S1a 1 = 9 dokumen; scan dF2 & d11 sah, dO1 di luar scope, 1 kode tak dikenal)
                    $t->eq([$before['A']['total_documents'], $before['A']['verified_count'], $before['A']['not_found_count'], $before['A']['invalid_count']], [9, 2, 1, 1], 'sesi A: angka tangan 9/2/1/1');
                    $t->eq($before['A']['scope'], ['is_root' => false, 'folder_name' => q28_n($t, $x['F']), 'subfolder_count' => 1], 'sesi A: scope F + 1 subfolder (S1)');
                    $t->eq($before['C']['scope'], ['is_root' => true, 'folder_name' => null, 'subfolder_count' => 1], 'sesi C: scope root + 1 subfolder (OTH)');
                    $t->eq($before['C']['id_archive'], null, 'sesi C: id_archive null');
                    $t->eq($before['B']['scope'], ['is_root' => false, 'folder_name' => q28_n($t, $x['S2']), 'subfolder_count' => 0], 'sesi B: scope nama folder, tanpa subfolder');
                    $t->eq($before['R']['scope'], ['is_root' => true, 'folder_name' => null, 'subfolder_count' => 3], 'sesi R: root + 3 subfolder -> is_root, folder_name null, subfolder_count 3');
                    $t->eq($before['M']['scope'], ['is_root' => false, 'folder_name' => q28_n($t, $x['F']), 'subfolder_count' => 2], 'sesi M: F + 2 subfolder -> folder_name F, subfolder_count 2');
                    $t->eq($before['Z']['scope'], ['is_root' => false, 'folder_name' => q28_n($t, $x['OTH']), 'subfolder_count' => 0], 'sesi Z: OTH tanpa subfolder');
                    // nama subfolder tidak bocor: tidak ada nama S1/S2/OTH-sub di scope sesi dengan subfolder
                    foreach (['A' => ['S1'], 'M' => ['S1', 'S2'], 'R' => ['OTH', 'PRIV', 'JOGR']] as $k => $subs) {
                        $json = json_encode($before[$k]['scope']);
                        foreach ($subs as $sub) {
                            $t->true(strpos($json, q28_n($t, $x[$sub])) === false, "sesi $k: nama subfolder $sub tidak ada di scope");
                        }
                    }

                    // angka tersimpan: dokumen ditambah / diubah sesudah Confirm tidak mengubah baris
                    $pick = function () use ($t, $s, $ids) {
                        $nr = q28_hist($t, $s, ['pagination' => 100]);
                        $after = [];
                        foreach ($nr[1]['result']['data'] as $row) {
                            $k = array_search($row['id_archive_opname'], $ids, true);
                            if ($k !== false) {
                                $after[$k] = $row;
                            }
                        }

                        return $after;
                    };
                    q28_doc($t, 'late1', ['parent' => $x['F']], 6);
                    q28_doc($t, 'late2', ['parent' => $x['S1']], 7);
                    q28_doc($t, 'late3', ['parent' => $x['S2']], 8);
                    $t->eq($pick(), $before, 'sesudah dokumen ditambah ke F/S1/S2: semua baris sama persis');
                    q28_set_archive($t, $x['dF2'], ['is_verified' => 0, 'verified_at' => null]);
                    q28_set_archive($t, $x['dF1'], ['is_active' => 0]);
                    $t->eq($pick(), $before, 'sesudah dokumen di-unverify / dinonaktifkan: baris tetap sama (nilai rekaman, bukan hitung ulang)');
                    $show = $t->call($s, 'GET', Q28_BASE . '/opnames/' . $ids['A']);
                    $t->eq($show[1]['result']['counts']['total_documents'], 9, 'GET opnames/{A}: counts tetap angka rekaman');
                });
            });
        },
    ],
];
