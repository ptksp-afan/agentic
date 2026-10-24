<?php
/**
 * ED-1250 - tambahan (BE):
 *   X-1  tepi display setting queries (ColumnDisplaySettingService): varian nama modul (huruf besar / spasi akhir) tidak melewati saringan,
 *        PUT tanpa list queries tidak menulis queries, current_queries / available_queries null, PUT lewat varian nama modul mempertahankan entri;
 *        baris cds dipulihkan persis;
 *   X-2  filter bersama key lain yang membangun WHERE rumit (query = cari bebas, updatedAt = rentang tanggal, name): filter tidak "bocor"
 *        lewat OR (folder hasil key lain tetap, dokumen = dokumen hasil key lain yang statusnya sama).
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'X-1',
        'title' => 'tepi display setting queries: varian nama modul (DOCUMENTARCHIVE, documentarchive, "documentArchive ") tetap disaring / dipertahankan, PUT tanpa list queries tidak menulis queries, queries null tidak 500 dan entri tetap, baris cds dipulihkan persis',
        'run'   => function ($t) {
            $s = $t->session();
            q50_baseline($t);
            $snap = q50_cds_snapshot($t);
            $t->eq(count($snap), 1, 'prasyarat: 1 baris documentArchive');
            $fp = q50_sum($t, 'column_display_settings', q50_cols($t, 'column_display_settings'));
            $storedAq = q50_seq(json_decode($snap[0]['available_queries'], true));
            $storedCq = q50_seq(json_decode($snap[0]['current_queries'], true));
            try {
                q50_with_user($t, q50_opts('smr', 'LIST'), function () use ($t, $s, $storedAq, $storedCq) {
                    // a. varian nama modul: GET tanpa entri
                    foreach (['DOCUMENTARCHIVE', 'documentarchive', 'documentArchive%20', 'DocumentArchive'] as $name) {
                        $g = $t->call($s, 'GET', Q50_DS . $name);
                        $t->true($g[0] < 500, "GET varian '$name': HTTP {$g[0]} (tidak boleh 5xx)");
                        if ($g[0] === 200 && !empty($g[1]['result']['current_queries'] ?? null)) {
                            $t->true(strpos(json_encode($g[1]), 'verificationStatus') === false, "GET varian '$name': tanpa jejak verificationStatus");
                            $t->true(strpos(json_encode($g[1]), 'documentVerified') === false, "GET varian '$name': tanpa jejak documentVerified");
                        } else {
                            $t->note("varian '$name' => HTTP {$g[0]} (modul tidak ditemukan / bukan documentArchive; tidak ada kebocoran)");
                        }
                    }

                    // b. PUT hanya kolom (list queries tidak dikirim): queries di DB tidak berubah
                    $g = $t->call($s, 'GET', Q50_DS . 'documentArchive');
                    $res = $g[1]['result'];
                    $p = $t->call($s, 'PUT', Q50_DS . 'documentArchive', [
                        'current_columns' => array_reverse($res['current_columns']), 'available_columns' => $res['available_columns'],
                    ]);
                    $t->status($p, 200, 'PUT tanpa list queries');
                    $row = q50_cds_row($t);
                    $t->eq(q50_seq(json_decode($row[0]['current_queries'], true)), $storedCq, 'DB current_queries tidak berubah (list tidak dikirim)');
                    $t->eq(q50_seq(json_decode($row[0]['available_queries'], true)), $storedAq, 'DB available_queries tidak berubah (list tidak dikirim)');
                    $t->true(strpos($row[0]['current_columns'], 'documentVerified') !== false, 'documentVerified tetap di current_columns');

                    // c. queries null: tidak 500; entri tersimpan dipertahankan
                    $p = $t->call($s, 'PUT', Q50_DS . 'documentArchive', [
                        'current_columns' => $res['current_columns'], 'available_columns' => $res['available_columns'],
                        'current_queries' => $res['current_queries'], 'available_queries' => null,
                    ]);
                    $t->true($p[0] < 500, "PUT available_queries null: HTTP {$p[0]} (tidak boleh 5xx)");
                    if ($p[0] === 200) {
                        $row = q50_cds_row($t);
                        $dbAq = $row[0]['available_queries'];
                        $t->true($dbAq === null || strpos($dbAq, 'verificationStatus') !== false, 'available_queries null: entri tersimpan dipertahankan (bukan hilang)');
                        $t->note('PUT available_queries null (tanpa OD) => available_queries di DB: ' . ($dbAq === null ? 'NULL' : implode(',', q50_seq(json_decode($dbAq, true)))));
                    } else {
                        $t->note("PUT available_queries null => HTTP {$p[0]} " . json_encode($p[1]['msg_code'] ?? ($p[1]['code'] ?? null)));
                    }

                    // d. PUT lewat varian nama modul tetap mempertahankan entri
                    $g = $t->call($s, 'GET', Q50_DS . 'documentArchive');
                    $res = $g[1]['result'];
                    $cq = array_reverse($res['current_queries']);
                    $p = $t->call($s, 'PUT', Q50_DS . 'DOCUMENTARCHIVE', [
                        'current_columns' => $res['current_columns'], 'available_columns' => array_reverse($res['available_columns']),
                        'current_queries' => $cq, 'available_queries' => $res['available_queries'],
                    ]);
                    $t->true($p[0] < 500, "PUT varian DOCUMENTARCHIVE: HTTP {$p[0]} (tidak boleh 5xx)");
                    $row = q50_cds_row($t);
                    $t->eq(count($row), 1, 'tetap 1 baris documentArchive (varian nama tidak membuat baris baru)');
                    $t->true(strpos($row[0]['current_queries'], 'verificationStatus') !== false || $row[0]['current_queries'] === null, 'PUT varian: verificationStatus tidak hilang dari current_queries');
                    $t->true(strpos($row[0]['available_queries'], 'verificationStatus') !== false || $row[0]['available_queries'] === null, 'PUT varian: verificationStatus tidak hilang dari available_queries');
                    $t->true(strpos($row[0]['current_columns'], 'documentVerified') !== false && strpos($row[0]['available_columns'], 'documentVerified') !== false, 'PUT varian: documentVerified tetap');
                    $cnt = $t->db()->table('column_display_settings')->whereRaw('LOWER(TRIM(module_name)) = ?', ['documentarchive'])->count();
                    $t->eq($cnt, 1, 'tidak ada baris cds baru bernama varian documentArchive');
                });
            } finally {
                q50_cds_restore($t, $snap);
                q50_cleanup($t);
            }
            $t->eq(q50_sum($t, 'column_display_settings', q50_cols($t, 'column_display_settings')), $fp, 'seluruh tabel column_display_settings sama persis dengan sebelum AC');
            $t->eq(json_encode(q50_cds_row($t)), json_encode($snap), 'baris documentArchive dipulihkan persis');
        },
    ],

    [
        'id'    => 'X-2',
        'title' => 'filter bersama key yang membangun WHERE rumit: query (cari bebas), updatedAt (rentang tanggal), name + updatedAt: folder hasil key lain tetap, dokumen = dokumen hasil key lain yang statusnya sama (filter tidak bocor lewat OR)',
        'run'   => function ($t) {
            $s = $t->session();
            q50_baseline($t);
            try {
                $x = q50_tree($t);
                $today = date('Y-m-d');
                foreach (['smr', 'bypass'] as $mode) {
                    q50_with_user($t, q50_opts($mode), function () use ($t, $s, $x, $mode, $today) {
                        $u = q50_u($t, $mode);
                        $model = q50_model($t);

                        // ---- query (cari bebas): properti himpunan terhadap hasil tanpa filter
                        $b0 = q50_all($t, $s, ['search' => ['query' => 'QA50-']], "[$mode] query tanpa filter");
                        $m0 = q50_map($b0);
                        $t->true(count($m0) > 10, "[$mode] query=QA50- tanpa filter menemukan baris fixture (" . count($m0) . ')');
                        foreach (['VERIFIED', 'UNVERIFIED'] as $f) {
                            $all = q50_all($t, $s, ['search' => ['query' => 'QA50-', Q50_FILTER => $f]], "[$mode] query $f");
                            $m1 = q50_map($all);
                            $folders0 = array_keys(array_filter($m0, 'q50_is_folder_row'));
                            $folders1 = array_keys(array_filter($m1, 'q50_is_folder_row'));
                            q50_same_set($t, $folders1, $folders0, "[$mode] query $f: folder = hasil query tanpa filter");
                            $docs0 = array_filter(array_keys($m0), function ($id) use ($m0, $model, $f) {
                                return !q50_is_folder_row($m0[$id]) && (($model[$id]['iv'] === 1) === ($f === 'VERIFIED'));
                            });
                            $docs1 = array_values(array_filter(array_keys($m1), function ($id) use ($m1) {
                                return !q50_is_folder_row($m1[$id]);
                            }));
                            q50_same_set($t, $docs1, array_values($docs0), "[$mode] query $f: dokumen = dokumen hasil query yang statusnya sama");
                        }

                        // ---- updatedAt (rentang tanggal) + filter: fixture dibuat hari ini
                        foreach (['VERIFIED', 'UNVERIFIED'] as $f) {
                            $q50r = function ($extra, $o, $label) use ($t, $s, $u, $model, $mode, $today, $f) {
                                $all = q50_all($t, $s, ['search' => $extra + [Q50_FILTER => $f]], "[$mode] $label $f");
                                $exp = q50_expect($t, $u, $o + ['parent' => 'FLAT', 'f' => $f], $model);
                                q50_same_set($t, $all['ids'], array_keys($exp), "[$mode] $label $f", q50_names($exp));
                                $t->eq($all['total'], count($exp), "[$mode] $label $f: total");

                                return $all;
                            };
                            $a = $q50r(['updatedAt' => [$today, $today], 'name' => 'QA50-'], ['name' => 'QA50-', 'uat' => [$today, $today]], 'updatedAt+name');
                            $t->true(count($a['ids']) > 5, "[$mode] updatedAt+name $f: ada hasil (" . count($a['ids']) . ')');
                            $q50r(['updatedAt' => [$today, $today]], ['uat' => [$today, $today]], 'updatedAt');
                            $q50r(['updatedAt' => ['2001-01-01', '2001-01-02'], 'name' => 'QA50-'], ['name' => 'QA50-', 'uat' => ['2001-01-01', '2001-01-02']], 'updatedAt lampau+name');
                        }
                    });
                }
            } finally {
                q50_cleanup($t);
            }
        },
    ],
];
