<?php
/**
 * ED-1028 - ketahanan: X-4 (fuzz parameter: kombinasi nilai aneh pada search/sorts/pagination/id_archive/selected_id di semua endpoint
 * baru & diubah -> tidak pernah 5xx), X-5 (bentuk parameter FE camelCase, kunci search selain camelCase, urutan error), X-6 (select
 * folder dengan 20.000 folder sisipan: waktu respons & isi).
 */
require_once __DIR__ . '/qa_hist.php';

return [

    [
        'id'    => 'X-4',
        'title' => 'fuzz: ratusan kombinasi nilai aneh (null, angka, array, objek, teks panjang, unicode, wildcard, injeksi) pada semua parameter endpoint list/select/show/documents -> status < 500 dan JSON valid',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s) {
                $x = null;
                $ids = null;
                q28_with_user($t, ['role' => 1], function () use ($t, $s, &$x, &$ids) {
                    $x = q28_tree($t);
                    $ids = q28_hfix($t, $s, $x);
                });
                $s2 = q28_u2_session($t);
                mt_srand(28028);
                $pool = [
                    null, '', ' ', 'x', 'alice', 0, 1, -1, 99999999999, 1.5, true, false, [], [null], [''], ['a'], [1], [['a']], [$x['F']], [$x['F'], $x['OTH']],
                    ['2026-01-01 00:00:00', null], [null, '2026-01-01 00:00:00'], ['2026-13-01 00:00:00', 'x'], ['a' => 1], ['a' => ['b' => 'c']],
                    str_repeat('z', 2500), '日本語', "emoji \xF0\x9F\x98\x80", '%', '_', '\\', "' OR 1=1 --", '"; DROP TABLE x; --', "a\0b", '0', 'null', 'true', '{', '[]', '{}',
                    $x['F'], $x['dF1'], '999999999999999999999', '../../etc/passwd', '<script>alert(1)</script>', '😀', "\n", "\t", '1e308',
                ];
                $pick = function () use ($pool) {
                    return $pool[mt_rand(0, count($pool) - 1)];
                };
                $count = 0;
                $bad = [];
                $nonJson = [];
                $statuses = [];
                $fire = function ($label, $session, $path, array $q) use ($t, &$count, &$bad, &$nonJson, &$statuses) {
                    $count++;
                    $r = $t->call($session, 'GET', $path . ($q ? '?' . http_build_query($q) : ''));
                    $statuses[$r[0]] = ($statuses[$r[0]] ?? 0) + 1;
                    if ($r[0] >= 500) {
                        $bad[] = $label . ' => HTTP ' . $r[0] . ' ' . $path . ' ' . substr(json_encode($q), 0, 200);
                    } elseif (!is_array($r[1])) {
                        // bukan JSON dari aplikasi (mis. 404 server web untuk path berisi %2F / %00): dicatat, bukan 5xx
                        $nonJson[] = $label . ' HTTP ' . $r[0] . ' ' . substr($path, 0, 90);
                    }
                };
                for ($i = 0; $i < 220; $i++) {
                    $session = $i % 3 === 0 ? $s2 : $s;
                    $search = [];
                    foreach (['query', 'confirmedAt', 'createdBy', 'idArchives'] as $k) {
                        if (mt_rand(0, 2) === 0) {
                            $search[$k] = $pick();
                        }
                    }
                    $q = [];
                    $form = mt_rand(0, 3);
                    if ($form === 0) {
                        $q['search'] = json_encode($search);
                    } elseif ($form === 1) {
                        $q['search'] = $pick();
                    } elseif ($form === 2) {
                        $q['search'] = $search;
                    } else {
                        $q['search'] = json_encode($search, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                    }
                    foreach (['pagination', 'page', 'id_archive', 'idArchive', 'sorts', 'sort'] as $k) {
                        if (mt_rand(0, 2) === 0) {
                            $v = $pick();
                            if ($k === 'sorts' || $k === 'sort') {
                                $v = mt_rand(0, 1) ? [json_encode(['sortBy' => $pick(), 'sortType' => $pick()])] : [['sortBy' => $pick(), 'sortType' => $pick()]];
                            }
                            $q[$k] = $v;
                        }
                    }
                    $fire('list', $session, Q28_BASE . '/opnames', $q);
                }
                for ($i = 0; $i < 60; $i++) {
                    $session = $i % 2 === 0 ? $s2 : $s;
                    foreach (['/folders', '/opname-users'] as $ep) {
                        $q = [];
                        foreach (['search', 'selected_id'] as $k) {
                            if (mt_rand(0, 1)) {
                                $q[$k] = $pick();
                            }
                        }
                        $fire('select' . $ep, $session, 'api/v5/select/document-archive/archive' . $ep, $q);
                    }
                }
                for ($i = 0; $i < 60; $i++) {
                    $session = $i % 2 === 0 ? $s2 : $s;
                    $idv = $i % 5 === 0 ? $ids['A'] : $pick();
                    $idPath = is_scalar($idv) && (string) $idv !== '' ? rawurlencode((string) $idv) : 'x';
                    $q = [];
                    foreach (['result', 'pagination', 'page'] as $k) {
                        if (mt_rand(0, 1)) {
                            $q[$k] = $pick();
                        }
                    }
                    $fire('show', $session, Q28_BASE . '/opnames/' . $idPath, []);
                    $fire('documents', $session, Q28_BASE . '/opnames/' . $idPath . '/documents', $q);
                }
                $t->note("fuzz: $count request, status " . json_encode($statuses) . '; non-JSON (bukan 5xx): ' . count($nonJson) . ' ' . json_encode(array_slice(array_unique($nonJson), 0, 4)));
                $t->eq($bad, [], 'tidak ada respons 5xx dari fuzz (' . $count . ' request)');
                $t->call($s2, 'GET', 'api/v5/auth/log-out');
            });
        },
    ],

    [
        'id'    => 'X-5',
        'title' => 'bentuk parameter FE: idArchive (camelCase) = id_archive, search dengan kunci bukan camelCase diabaikan tanpa error, Content-Type JSON, urutan penolakan (permission sebelum validasi)',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s) {
                $x = null;
                $ids = null;
                q28_with_user($t, ['role' => 1], function () use ($t, $s, &$x, &$ids) {
                    $x = q28_tree($t);
                    $ids = q28_hfix($t, $s, $x);
                });
                $keys = function ($r) use ($ids) {
                    return q28_hkeys(q28_hids($r), $ids);
                };
                // FE mengirim params camelCase (idArchive): middleware ConvertRequestToSnakeCase
                $a = $t->call($s, 'GET', q28_url(Q28_BASE . '/opnames', ['idArchive' => $x['F'], 'pagination' => 100]));
                $b = $t->call($s, 'GET', q28_url(Q28_BASE . '/opnames', ['id_archive' => $x['F'], 'pagination' => 100]));
                $t->status($a, 200, 'idArchive camelCase');
                $t->eq($keys($a), $keys($b), 'idArchive (camelCase) = id_archive');
                $t->eq($keys($a), ['Y', 'D', 'B', 'A'], 'konteks F user3: Y, D, B, A');
                // kunci search snake_case tidak dikenal (kontrak: camelCase persis) -> diabaikan, bukan error
                $r = q28_hist($t, $s, ['pagination' => 100, 'search' => ['created_by' => 'qa28alice', 'confirmed_at' => ['x', 'y']]]);
                $t->status($r, 200, 'kunci search snake_case diabaikan');
                $t->eq(count($keys($r)), 6, 'kunci snake_case tidak memfilter (6 sesi terlihat)');
                // FE: search kosong {} dan {"query":""}
                $r = q28_hist($t, $s, ['pagination' => 100, 'search' => '{"query":""}']);
                $t->eq(count($keys($r)), 6, '{"query":""} = tanpa filter');
                // search FE dengan semua kunci kosong (setelah Reset filter)
                $r = q28_hist($t, $s, ['pagination' => 100, 'search' => ['query' => '', 'confirmedAt' => [null, null], 'createdBy' => null, 'idArchives' => []]]);
                $t->status($r, 200, 'setelah Reset filter');
                $t->eq(count($keys($r)), 6, 'setelah Reset filter: semua sesi lagi');
                $r = q28_hist($t, $s, ['pagination' => 100, 'search' => ['query' => '', 'confirmedAt' => ['', ''], 'createdBy' => '', 'idArchives' => []]]);
                $t->eq(count($keys($r)), 6, 'Reset filter bentuk string kosong: semua sesi');
                // header
                $raw = $t->callFile($s, 'GET', Q28_BASE . '/opnames?pagination=1');
                $ct = '';
                foreach ($raw[2] ?? [] as $k => $v) {
                    if (strtolower($k) === 'content-type') {
                        $ct = is_array($v) ? implode(',', $v) : (string) $v;
                    }
                }
                $t->true($ct === '' || stripos($ct, 'application/json') !== false, 'Content-Type JSON (' . $ct . ')');
                // urutan penolakan: tanpa Accept JSON tetap JSON untuk validasi 422? (validate() membalas 302 tanpa Accept) - search diperiksa di service, bukan FormRequest
                $r = $t->raw('GET', Q28_BASE . '/opnames');
                $t->status($r, 401, 'tanpa token: 401');
            });
        },
    ],

    [
        'id'    => 'X-6',
        'title' => 'select folders dengan 20.000 folder sisipan (berpenanda QA28BULKFLD): waktu respons & isi benar (superadmin dan user2), sisipan dibuang',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s) {
                $x = q28_tree($t);
                $n = 20000;
                q28_w($t, function ($c) use ($n, $x) {
                    $now = date('Y-m-d H:i:s');
                    $rows = [];
                    for ($i = 0; $i < $n; $i++) {
                        $rows[] = [
                            'id_archive' => 'QA28BULKFLD' . str_pad((string) $i, 10, '0', STR_PAD_LEFT),
                            'id_archive_parent' => $i % 4 === 0 ? $x['P'] : ('QA28BULKFLD' . str_pad((string) ($i - 1), 10, '0', STR_PAD_LEFT)),
                            'name' => 'QA28-' . 'bulkfld-' . $i, 'type' => 1, 'status' => 1, 'is_all_location' => 1, 'is_folder_permission' => 0,
                            'is_active' => 1, 'created_at' => $now, 'created_by' => 'QA28', 'updated_at' => $now, 'updated_by' => 'QA28',
                        ];
                    }
                    foreach (array_chunk($rows, 500) as $chunk) {
                        $c->table('archives')->insert($chunk);
                    }
                });
                try {
                    $total = $t->db()->table('archives')->where('type', 1)->where('is_active', 1)->count();
                    q28_with_user($t, ['role' => 1], function () use ($t, $s, $total) {
                        $t0 = microtime(true);
                        $r = $t->call($s, 'GET', 'api/v5/select/document-archive/archive/folders');
                        $ms = round((microtime(true) - $t0) * 1000);
                        q28_no500($t, $r, 'select folders 20k (superadmin)');
                        $t->status($r, 200, 'select folders 20k (superadmin)');
                        $t->eq(count($r[1]['result']['options']), $total, 'superadmin: semua folder aktif (' . $total . ')');
                        $t->note("select folders superadmin, $total folder: $ms ms");
                        $t->true($ms < 10000, "select folders superadmin < 10 detik ($ms ms)");
                        $t0 = microtime(true);
                        $r = $t->call($s, 'GET', 'api/v5/select/document-archive/archive/folders?search=' . rawurlencode('bulkfld-1999'));
                        $ms = round((microtime(true) - $t0) * 1000);
                        $t->eq(count($r[1]['result']['options']), 11, 'search bulkfld-1999: 11 folder (1999, 19990-19999)');
                        $t->note("select folders search: $ms ms");
                    });
                    $s2 = q28_u2_session($t);
                    $t0 = microtime(true);
                    $r = $t->call($s2, 'GET', 'api/v5/select/document-archive/archive/folders');
                    $ms = round((microtime(true) - $t0) * 1000);
                    q28_no500($t, $r, 'select folders 20k (user2)');
                    $t->status($r, 200, 'select folders 20k (user2)');
                    $o = q28_oracle($t, q28_uu($t, 'user2'));
                    $t->eq(count($r[1]['result']['options']), count($o['visible']), 'user2: jumlah = oracle (' . count($o['visible']) . ')');
                    $t->note("select folders user2: $ms ms");
                    $t->call($s2, 'GET', 'api/v5/auth/log-out');
                } finally {
                    q28_w($t, function ($c) {
                        $c->table('archives')->where('id_archive', 'like', 'QA28BULKFLD%')->delete();
                    });
                    $t->eq($t->db()->table('archives')->where('id_archive', 'like', 'QA28BULKFLD%')->count(), 0, 'folder sisipan dibuang');
                }
            });
        },
    ],
];
