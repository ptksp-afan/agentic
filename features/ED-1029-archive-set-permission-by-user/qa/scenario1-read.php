<?php
/**
 * ED-1029 - ED-1035: AC-4 (GET user-permissions/{id}: pohon, scope lokasi, nilai tersimpan, access), AC-5 (superadmin 1 dan 2),
 *           AC-6 (bagian GET: user tidak ada / nonaktif = 404 ARCHIVE440; id aneh tidak 5xx).
 *
 * Pemanggil = QA_USER (role 3, bukan bypass) dengan lokasi kerja dibatasi ke SMR; target B = user aktif lain.
 * Pembanding hasil = SQL langsung (koneksi read-only), bukan keluaran service.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-4',
        'title' => 'GET user-permissions/{B} oleh A (lokasi SMR): 200 ARCHIVE200, user.username = B, pohon folder aktif dalam scope (L tidak ada, LC di level teratas dengan id_archive_parent = L), urut nama, R1 di bawah R, nilai B tersimpan (0 tanpa baris; baris folder Off tetap tampil), access A (P true, Q false, QC pembuat, N tanpa hak), dokumen/nonaktif tidak tampil, bentuk node sesuai kontrak',
        'run'   => function ($t) {
            q9_recover($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b, $b2] = q9_others($t, 2);

            try {
                $f = q9_fixture($t, $me, $meName, $b[0]);
                $dumpBefore = q9_perm_dump($t, array_values($f));
                $histBefore = array_map(function ($id) use ($t) { return q9_history($t, $id); }, $f);

                $r = null;
                q9_with_user($t, ['emp' => ['SMR'], 'lang' => 'EN'], function ($set) use ($t, $s, $b, &$r) {
                    $r = q9_get($t, $s, $b[0]);
                });

                $t->status($r, 200, 'GET');
                $t->eq(q9_code($r), 'ARCHIVE200', 'msg_code');
                $t->eq($r[1]['status'] ?? null, 'success', 'status');
                $t->eq($r[1]['message'] ?? null, 'Document is found', 'message (en)');
                $t->eq(json_encode($r[1]['result']['user'] ?? null), json_encode(['id_user' => $b[0], 'username' => $b[1]]), 'result.user');
                $t->eq(json_encode(array_keys($r[1]['result'])), json_encode(['user', 'data']), 'result: hanya user + data');
                $tree = $r[1]['result']['data'];
                $flat = [];
                $order = [];
                q9_flatten($tree, $flat, $order);

                // --- himpunan folder = SQL (aktif, dalam scope SMR); tidak ada duplikat
                $rows = q9_expected_ids($t, ['SMR']);
                $expIds = array_map(function ($x) { return $x->id_archive; }, $rows);
                $gotIds = $order;
                $t->eq(count($gotIds), count(array_unique($gotIds)), 'tidak ada node ganda');
                $t->eq(count($gotIds), count($expIds), 'jumlah folder = SQL (' . count($expIds) . ')');
                $t->eq(json_encode(array_values(array_diff($expIds, $gotIds))), '[]', 'tidak ada folder SQL yang hilang');
                $t->eq(json_encode(array_values(array_diff($gotIds, $expIds))), '[]', 'tidak ada folder lebih');
                foreach (['L', 'PX', 'DP'] as $k) {
                    $t->true(!isset($flat[$f[$k]]), "$k tidak tampil");
                }
                foreach (['P', 'C1', 'C2', 'Q', 'R', 'R1', 'QC', 'N', 'LC', 'M'] as $k) {
                    $t->true(isset($flat[$f[$k]]), "$k tampil");
                }

                // --- struktur & urutan = SQL
                [$expOrder, $kids, $roots] = q9_expected_order($rows);
                $t->eq(json_encode($order), json_encode($expOrder), 'urutan DFS (nama per level, anak di bawah induk) = SQL');
                $t->eq($flat[$f['C1']]['__level'], 1, 'C1 level 1');
                $t->eq($flat[$f['C2']]['__level'], 1, 'C2 level 1');
                $t->eq($flat[$f['R1']]['__level'], 1, 'R1 di bawah R (level 1)');
                $t->eq($flat[$f['P']]['__level'], 0, 'P level 0');
                $t->eq($flat[$f['LC']]['__level'], 0, 'LC (induk di luar scope) di level teratas');
                $t->eq($flat[$f['LC']]['id_archive_parent'], $f['L'], 'LC: id_archive_parent tetap id asli (L)');
                $t->eq($flat[$f['C1']]['id_archive_parent'], $f['P'], 'C1: id_archive_parent = P');
                $t->eq($flat[$f['P']]['id_archive_parent'], null, 'P: id_archive_parent null');

                // --- children hanya ada bila punya subfolder tampil; bentuk node
                $nodeKeys = ['id_archive', 'id_archive_parent', 'name', 'is_folder_permission', 'is_view', 'is_update', 'is_delete', 'is_store', 'access'];
                $hasKids = [];
                $walk = function (array $nodes) use (&$walk, &$hasKids, $t, $nodeKeys) {
                    foreach ($nodes as $n) {
                        $keys = array_keys($n);
                        $k2 = array_values(array_diff($keys, ['children']));
                        $t->eq(json_encode($k2), json_encode($nodeKeys), 'node ' . $n['name'] . ': kunci sesuai kontrak (urutan & isi)');
                        if (array_key_exists('children', $n)) {
                            $t->true(is_array($n['children']) && count($n['children']) > 0, 'node ' . $n['name'] . ': children tidak kosong bila ada');
                            $hasKids[$n['id_archive']] = true;
                            $walk($n['children']);
                        }
                        foreach (['is_folder_permission', 'is_view', 'is_update', 'is_delete', 'is_store'] as $col) {
                            $t->true(is_int($n[$col]) && ($n[$col] === 0 || $n[$col] === 1), 'node ' . $n['name'] . ": $col int 0/1");
                        }
                        $t->eq(json_encode(array_keys($n['access'])), json_encode(['view', 'update', 'delete', 'store', 'manage_permission']), 'node ' . $n['name'] . ': access 5 kunci');
                        foreach ($n['access'] as $v) {
                            $t->true(is_bool($v), 'node ' . $n['name'] . ': access bool');
                        }
                    }
                };
                $walk($tree);
                foreach (array_keys($flat) as $id) {
                    $t->eq(isset($hasKids[$id]), isset($kids[$id]), 'children ada <=> punya subfolder tampil (' . $flat[$id]['name'] . ')');
                }
                $t->true(isset($hasKids[$f['P']]) && isset($hasKids[$f['R']]), 'P dan R punya children');
                $t->true(!isset($hasKids[$f['C1']]) && !isset($hasKids[$f['Q']]), 'C1 dan Q (daun) tanpa kunci children');

                // --- nilai tersimpan B dan flag folder = DB
                foreach ($flat as $id => $n) {
                    $dbRow = $t->db()->table('archives')->where('id_archive', $id)->first();
                    $t->eq($n['is_folder_permission'], (int) $dbRow->is_folder_permission, 'is_folder_permission = DB (' . $n['name'] . ')');
                    $t->eq($n['name'], $dbRow->name, 'name = DB (' . $n['name'] . ')');
                    $p = $t->db()->table('archive_permissions')->where('id_archive', $id)->where('id_user', $b[0])->first();
                    $want = $p ? [(int) $p->is_view, (int) $p->is_update, (int) $p->is_delete, (int) $p->is_store] : [0, 0, 0, 0];
                    $t->eq(json_encode([$n['is_view'], $n['is_update'], $n['is_delete'], $n['is_store']]), json_encode($want), 'nilai B = DB (' . $n['name'] . ')');
                }
                $v = function ($k) use ($flat, $f) { $n = $flat[$f[$k]]; return [$n['is_view'], $n['is_update'], $n['is_delete'], $n['is_store']]; };
                $t->eq(json_encode($v('P')), json_encode([1, 0, 0, 1]), 'P: B = View+Store');
                $t->eq(json_encode($v('C1')), json_encode([1, 0, 0, 0]), 'C1: B = View');
                $t->eq(json_encode($v('C2')), json_encode([1, 1, 0, 0]), 'C2 (Off): baris B tetap tampil (V+U)');
                $t->eq(json_encode($v('Q')), json_encode([0, 0, 0, 0]), 'Q: tanpa baris = 0');
                $t->eq($flat[$f['P']]['is_folder_permission'], 1, 'P On');
                $t->eq($flat[$f['C2']]['is_folder_permission'], 0, 'C2 Off');
                $t->eq($flat[$f['R']]['is_folder_permission'], 0, 'R Off');

                // --- access A (hak efektif pemanggil) atas fixture
                $acc = function ($k) use ($flat, $f) { return $flat[$f[$k]]['access']; };
                q9_access_is($t, $acc('P'), [1, 1, 0, 0], 'P (A: V+U)');
                q9_access_is($t, $acc('C1'), [1, 1, 0, 0], 'C1 (A: V+U, induk V+U)');
                q9_access_is($t, $acc('C2'), [1, 1, 0, 0], 'C2 (Off, induk P V+U)');
                q9_access_is($t, $acc('Q'), [1, 0, 0, 0], 'Q (A: V saja) -> manage false');
                q9_access_is($t, $acc('R'), [1, 1, 1, 1], 'R (Off, tanpa induk)');
                q9_access_is($t, $acc('R1'), [1, 1, 0, 0], 'R1 (On di bawah R Off, A: V+U)');
                q9_access_is($t, $acc('QC'), [1, 1, 1, 1], 'QC (A pembuat)');
                q9_access_is($t, $acc('N'), [0, 0, 0, 0], 'N (On, A tanpa baris)');
                q9_access_is($t, $acc('LC'), [0, 0, 0, 0], 'LC (On, A tanpa baris; induk L Off tidak membatasi)');
                q9_access_is($t, $acc('M'), [0, 0, 0, 0], 'M (On, A tanpa baris)');
                $t->true($acc('P')['manage_permission'] === true && $acc('Q')['manage_permission'] === false, 'AC-4: P manage true, Q manage false');

                // --- B lain tanpa baris: semua nol; data tidak berubah oleh GET
                $r2 = null;
                q9_with_user($t, ['emp' => ['SMR']], function ($set) use ($t, $s, $b2, &$r2) {
                    $r2 = q9_get($t, $s, $b2[0]);
                });
                $t->status($r2, 200, 'GET B2');
                $fl2 = q9_flatten($r2[1]['result']['data']);
                foreach (['P', 'C1', 'C2'] as $k) {
                    $n = $fl2[$f[$k]];
                    $t->eq(json_encode([$n['is_view'], $n['is_update'], $n['is_delete'], $n['is_store']]), '[0,0,0,0]', "B2 tanpa baris: $k nol");
                }
                $t->eq($r2[1]['result']['user']['username'], $b2[1], 'B2: username');
                $t->true(q9_same($dumpBefore, q9_perm_dump($t, array_values($f))), 'GET tidak mengubah archive_permissions');
                $histAfter = array_map(function ($id) use ($t) { return q9_history($t, $id); }, $f);
                $t->true(q9_same($histBefore, $histAfter), 'GET tidak mengubah riwayat');
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-5',
        'title' => 'GET user-permissions/{B} oleh superadmin 1 dan 2 (role 1 / role 2): L ikut tampil, semua folder aktif tanpa scope lokasi, access semua true (manage_permission true di semua folder), nilai B tersimpan tetap tampil',
        'run'   => function ($t) {
            q9_recover($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b] = q9_others($t, 1);

            try {
                $f = q9_fixture($t, $me, $meName, $b[0]);
                $rows = q9_expected_ids($t, null);
                $allIds = array_map(function ($x) { return $x->id_archive; }, $rows);
                [$expOrder] = q9_expected_order($rows);

                foreach ([1, 2] as $role) {
                    $r = null;
                    // lokasi kerja dibatasi ke SMR di sini justru harus diabaikan oleh superadmin
                    q9_with_user($t, ['role' => $role, 'emp' => ['SMR']], function ($set) use ($t, $s, $b, &$r) {
                        $r = q9_get($t, $s, $b[0]);
                    });
                    $tag = "role $role";
                    $t->status($r, 200, "$tag: GET");
                    $t->eq(q9_code($r), 'ARCHIVE200', "$tag: msg_code");
                    $flat = [];
                    $order = [];
                    q9_flatten($r[1]['result']['data'], $flat, $order);
                    $t->eq(count($order), count($allIds), "$tag: jumlah folder = semua folder aktif (" . count($allIds) . ')');
                    $t->eq(json_encode($order), json_encode($expOrder), "$tag: urutan DFS = SQL");
                    $t->true(isset($flat[$f['L']]), "$tag: L (di luar lokasi) tampil");
                    $t->true(isset($flat[$f['LC']]) && $flat[$f['LC']]['id_archive_parent'] === $f['L'] && $flat[$f['LC']]['__level'] === 1, "$tag: LC di bawah L (induk ikut tampil)");
                    $t->true(!isset($flat[$f['PX']]) && !isset($flat[$f['DP']]), "$tag: nonaktif dan dokumen tidak tampil");
                    foreach ($flat as $id => $n) {
                        q9_access_is($t, $n['access'], [1, 1, 1, 1], "$tag: " . $n['name']);
                    }
                    $t->eq(json_encode([$flat[$f['P']]['is_view'], $flat[$f['P']]['is_update'], $flat[$f['P']]['is_delete'], $flat[$f['P']]['is_store']]), '[1,0,0,1]', "$tag: nilai B di P tetap tersimpan");
                    $t->eq(json_encode([$flat[$f['C2']]['is_view'], $flat[$f['C2']]['is_update']]), '[1,1]', "$tag: nilai B di C2 (Off)");
                }
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-6',
        'title' => 'GET user-permissions/<id tidak ada> atau user nonaktif: 404 ARCHIVE440 (ID dan EN), tanpa result; id aneh (kosong-ish, panjang, SQL, unicode, spasi, path) tidak 5xx; user aktif kembali 200',
        'run'   => function ($t) {
            q9_recover($t);
            $s = $t->session();
            [$b] = q9_others($t, 1);
            $before = $t->db()->table('users')->where('id_user', $b[0])->first(['is_active']);
            $wasActive = $before->is_active;

            try {
                // tidak ada
                foreach (['en' => "User isn't found", 'id' => 'User tidak ditemukan'] as $lang => $msg) {
                    q9_with_user($t, ['lang' => strtoupper($lang)], function ($set) use ($t, $s, $msg, $lang) {
                        $r = q9_get($t, $s, Q9_RANDOM_ID);
                        q9_deny($t, $r, 404, 'ARCHIVE440', "tidak ada ($lang)");
                        $t->eq($r[1]['message'] ?? null, $msg, "pesan $lang");
                        $t->true(!array_key_exists('result', $r[1]), 'tanpa result');
                    });
                }

                // nonaktif: is_active 0, 2, null
                foreach ([0, 2, null] as $val) {
                    q9_set_user($t, $b[0], ['is_active' => $val]);
                    $r = q9_get($t, $s, $b[0]);
                    q9_deny($t, $r, 404, 'ARCHIVE440', 'user is_active=' . var_export($val, true));
                }
                q9_set_user($t, $b[0], ['is_active' => $wasActive]);
                $r = q9_get($t, $s, $b[0]);
                $t->status($r, 200, 'user aktif lagi: 200');

                // id aneh: tidak boleh 5xx; harus 404 ARCHIVE440 (atau 404 route)
                $odd = [
                    '0', '-1', "' OR '1'='1", '1;DROP TABLE users', str_repeat('A', 300), 'ünïcödé', 'a b', '%00', '..%2f..', '<script>',
                    '1e3', str_repeat('9', 40), '%', '_', 'null', 'true', '[]', '%25',
                ];
                foreach ($odd as $id) {
                    $enc = in_array($id, ['%00', '..%2f..', '%25']) ? $id : rawurlencode($id);
                    $r = $t->call($s, 'GET', 'api/v5/document-archive/user-permissions/' . $enc);
                    q9_no500($t, $r, 'GET id aneh ' . substr($id, 0, 20));
                    $t->true(in_array($r[0], [404, 400, 422]), 'GET id aneh ' . substr($id, 0, 20) . ': 4xx (' . $r[0] . ')');
                    $t->true(!isset($r[1]['result']), 'GET id aneh ' . substr($id, 0, 20) . ': tanpa result');
                }

                // tanpa id: route tidak ada (bukan 500, bukan data)
                $r = $t->call($s, 'GET', 'api/v5/document-archive/user-permissions');
                q9_no500($t, $r, 'GET tanpa id');
                $t->true(in_array($r[0], [404, 405]), 'GET tanpa id: 404/405 (' . $r[0] . ')');
            } finally {
                q9_set_user($t, $b[0], ['is_active' => $wasActive]);
            }
            $t->eq($t->db()->table('users')->where('id_user', $b[0])->value('is_active'), $wasActive, 'is_active user uji dipulihkan');
        },
    ],

];
