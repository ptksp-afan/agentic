<?php
/**
 * ED-1029 - ketahanan: X-1 (karakterisasi ED-1071: id dengan karakter di luar latin1 = 500 kolasi, pra-ada lintas modul, dilacak di
 * luar item; kontrol latin1 harus 404), X-2 (banyak folder: GET dan PUT satu request), X-3 (pohon dalam, induk berputar / induk diri
 * sendiri), X-4 (target superadmin/diri sendiri, id_archive aneh, spasi). Konkurensi (PUT paralel, D-2): scenario5-concurrency.php.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'X-1',
        'title' => 'ED-1071 (karakterisasi bug pra-ada lintas modul, dilacak di luar item, BUKAN perilaku yang benar): id dengan karakter di luar Windows-1252 (angka Arab, CJK, Omega, emoji) di path GET/PUT user-permissions dan di body id_archive = 500 (Illegal mix of collations); kontrol latin1/Windows-1252 = 404; data tidak berubah di setiap respons >= 400',
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
                $ids = array_values($f);

                // ED-1071 (karakterisasi): kolom id archives/users berkolasi latin1, koneksi utf8 -> karakter di luar Windows-1252 sebagai
                // pembanding SQL = 500 (SQLSTATE 1267). Pra-ada dan sama di endpoint lama modul (archives/{id}, archives/history/{id}, select
                // users selected_id; ED-1025 X-12). Endpoint ED-1029 mewarisinya lewat findUser/assertCanManage/lockFolders. Bila ED-1071
                // mengubah perilaku (500 menjadi 4xx), kasus "want 500" GAGAL dengan sengaja ("ED-1071 berubah"): ubah karakterisasi ini menjadi
                // asersi tidak-5xx dan hapus tag. Kontrol latin1/Windows-1252 adalah perilaku benar dan harus tetap 404 (AC-6).
                $cases = [];
                foreach (['٣', '日本', 'Ω', '😀'] as $x) {
                    $cases["GET path $x"]            = [500, 'GET', $x, null];
                    $cases["PUT path $x []"]         = [500, 'PUT', $x, ['permissions' => []]];
                    $cases["PUT body id_archive $x"] = [500, 'PUT', $b[0], ['permissions' => [q9_row_body($x, 1)]]];
                    $cases["PUT body [P, $x]"]       = [500, 'PUT', $b[0], ['permissions' => [q9_row_body($f['P'], 1), q9_row_body($x, 1)]]];
                }
                foreach (['ü', 'é', '€'] as $x) {     // latin1 / Windows-1252: tidak mengalami kolasi
                    $cases["GET path $x"]            = [404, 'GET', $x, null];
                    $cases["PUT path $x []"]         = [404, 'PUT', $x, ['permissions' => []]];
                    $cases["PUT body id_archive $x"] = [404, 'PUT', $b[0], ['permissions' => [q9_row_body($x, 1)]]];
                    $cases["PUT body [P, $x]"]       = [404, 'PUT', $b[0], ['permissions' => [q9_row_body($f['P'], 1), q9_row_body($x, 1)]]];
                }

                $before = q9_snap($t, $ids);
                $drift = [];
                $changed = [];
                $statuses = [];
                $leak = 0;
                foreach ($cases as $label => [$want, $method, $who, $body]) {
                    $r = $t->call($s, $method, 'api/v5/document-archive/user-permissions/' . rawurlencode($who), $body);
                    $statuses[$label] = $r[0];
                    if ($r[0] !== $want) {
                        $drift[] = ($want === 500 ? 'ED-1071 berubah: ' : 'KONTROL latin1 salah: ') . $label . ' kini HTTP ' . $r[0] . ' (sebelumnya/diharapkan ' . $want . ')';
                    }
                    if ($want === 404 && $r[0] === 404) {
                        $expected = strpos($label, 'body id_archive') !== false || strpos($label, 'body [P') !== false ? 'ARCHIVE400' : 'ARCHIVE440';
                        if (q9_code($r) !== $expected) {
                            $drift[] = 'KONTROL latin1: ' . $label . ' code ' . q9_code($r) . ' (diharapkan ' . $expected . ')';
                        }
                    }
                    if ($r[0] >= 400 && !q9_same($before, q9_snap($t, $ids))) {
                        $changed[] = $label;
                        $before = q9_snap($t, $ids);
                    }
                    if ($r[0] >= 500 && stripos(json_encode($r[1]), 'SQLSTATE') !== false) {
                        $leak++;
                    }
                }
                $counts = array_count_values($statuses);
                ksort($counts);
                $t->note(count($cases) . ' kasus; status: ' . json_encode($counts) . ($leak ? "; $leak badan 500 memuat teks SQLSTATE (mode debug?)" : ''));
                $t->eq($changed, [], 'data (archives, archive_permissions, riwayat) tidak berubah pada respons >= 400');
                $t->eq($drift, [], 'karakterisasi ED-1071 sama dengan perilaku pra-ada dan kontrol latin1 = 404 (bila "ED-1071 berubah": perbarui skenario)');
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-2',
        'title' => 'Banyak folder (160 anak langsung P + 12 level bersarang): GET < 8 detik dengan pohon utuh; PUT 160 baris sekali kirim (atomik) < 15 detik, 160 baris + 160 riwayat; PUT nol 160 baris menghapus semua',
        'run'   => function ($t) {
            q9_recover($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b] = q9_others($t, 1);
            $n = 160;

            try {
                $P = q9_folder($t, 'P', ['perm' => 1, 'by' => $meName]);
                $kids = [];
                $now = date('Y-m-d H:i:s');
                $pref = Q9_PREFIX . 'K-' . substr(uniqid(), -6) . '-';
                $kidIds = q9_w($t, function ($c) use ($P, $n, $now, $pref, $meName) {
                    $ids = [];
                    $rows = [];
                    for ($i = 0; $i < $n; $i++) {
                        $id = substr(\Modules\V5\Entities\Helper\MyHelper::generateId(), 0, 26) . sprintf('%04d', $i);
                        $ids[] = $id;
                        $rows[] = [
                            'id_archive' => $id, 'id_archive_parent' => $P, 'name' => $pref . sprintf('%03d', $i), 'type' => 1, 'status' => 1,
                            'is_all_location' => 1, 'is_folder_permission' => 1, 'is_active' => 1, 'created_at' => $now,
                            'created_by' => $meName, 'updated_at' => $now, 'updated_by' => $meName,
                        ];
                    }
                    foreach (array_chunk($rows, 50) as $chunk) {
                        $c->table('archives')->insert($chunk);
                    }

                    return $ids;
                });
                // rantai 12 level di bawah kid 0
                $parent = $kidIds[0];
                $deep = [];
                for ($d = 0; $d < 12; $d++) {
                    $parent = q9_folder($t, 'D' . $d, ['perm' => 1, 'by' => $meName, 'parent' => $parent]);
                    $deep[] = $parent;
                }
                $all = array_merge([$P], $kidIds, $deep);

                $t0 = microtime(true);
                $r = q9_get($t, $s, $b[0]);
                $el = microtime(true) - $t0;
                $t->status($r, 200, 'GET banyak folder');
                $flat = q9_flatten($r[1]['result']['data']);
                $t->true(isset($flat[$P]) && isset($flat[end($deep)]), 'GET: P dan folder terdalam tampil');
                $t->eq($flat[end($deep)]['__level'], 13, 'GET: folder terdalam di level 13 (P=0, kid=1, D0=2 ... D11=13)');
                foreach ($kidIds as $id) {
                    if (!isset($flat[$id]) || $flat[$id]['access']['manage_permission'] !== true) {
                        $t->fail('GET: kid ' . $id . ' tidak tampil/tidak bisa dikelola (pembuat)');
                    }
                }
                $t->true($el < 8, sprintf('GET %.2fs (< 8s)', $el));
                $t->note(sprintf('GET %d folder total %.2fs', count($flat), $el));

                // PUT 160 baris sekali kirim
                $rows = [];
                foreach ($kidIds as $i => $id) {
                    $rows[] = q9_row_body($id, 1, $i % 2, 0, $i % 3 === 0 ? 1 : 0);
                }
                $t0 = microtime(true);
                $r = q9_put($t, $s, $b[0], ['permissions' => $rows]);
                $el = microtime(true) - $t0;
                $t->status($r, 200, 'PUT 160 baris');
                $t->true($el < 15, sprintf('PUT %.2fs (< 15s)', $el));
                $t->note(sprintf('PUT %d baris %.2fs', count($rows), $el));
                $map = q9_user_map($t, $b[0], $kidIds);
                $t->eq(count($map), $n, 'DB: 160 baris tersimpan');
                $bad = 0;
                foreach ($kidIds as $i => $id) {
                    if (json_encode($map[$id] ?? null) !== json_encode([1, $i % 2, 0, $i % 3 === 0 ? 1 : 0])) {
                        $bad++;
                    }
                }
                $t->eq($bad, 0, 'DB: setiap baris sesuai kiriman');
                $t->eq($t->db()->table('archive_permissions')->whereIn('id_archive', $kidIds)->where('id_user', $b[0])->distinct()->count('id_archive_permission'), $n, 'DB: PK unik semua');
                $withHist = 0;
                foreach ($kidIds as $id) {
                    if (q9_hist_count($t, $id) === 1) {
                        $withHist++;
                    }
                }
                $t->eq($withHist, $n, 'riwayat: setiap folder tepat 1 entri permission');

                // kirim ulang sama persis: tanpa perubahan
                $snap = q9_snap($t, $kidIds);
                $r = q9_put($t, $s, $b[0], ['permissions' => $rows]);
                $t->status($r, 200, 'PUT ulang');
                $t->true(q9_same($snap, q9_snap($t, $kidIds)), 'PUT ulang sama: tanpa perubahan (data + riwayat)');

                // PUT nol: hapus semua
                $zero = [];
                foreach ($kidIds as $id) {
                    $zero[] = q9_row_body($id, 0, 0, 0, 0);
                }
                $r = q9_put($t, $s, $b[0], ['permissions' => $zero]);
                $t->status($r, 200, 'PUT nol 160');
                $t->eq(count(q9_user_map($t, $b[0], $kidIds)), 0, 'DB: semua baris terhapus');

                // PUT dengan satu invalid di akhir daftar 160: tidak ada yang tersimpan
                $rows2 = $rows;
                $rows2[] = q9_row_body($deep[0], 0, 1);
                $snap = q9_snap($t, $all);
                $r = q9_put($t, $s, $b[0], ['permissions' => $rows2]);
                q9_deny($t, $r, 400, 'ARCHIVE411', '160 sah + 1 invalid');
                $t->true(q9_same($snap, q9_snap($t, $all)), '160 sah + 1 invalid: tidak ada yang tersimpan (rollback 160 tulisan)');
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-3',
        'title' => 'Data induk tidak lazim: induk berputar (X1<->X2), induk diri sendiri, induk tidak ada/ nonaktif: GET 200 tiap folder tepat sekali tanpa 5xx; PUT atas folder itu tidak menggantung/5xx',
        'run'   => function ($t) {
            q9_recover($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b] = q9_others($t, 1);

            try {
                $X1 = q9_folder($t, 'X1', ['perm' => 1, 'by' => $meName]);
                $X2 = q9_folder($t, 'X2', ['perm' => 1, 'by' => $meName, 'parent' => $X1]);
                $S = q9_folder($t, 'S', ['perm' => 1, 'by' => $meName]);
                $G = q9_folder($t, 'G', ['perm' => 1, 'by' => $meName, 'parent' => Q9_RANDOM_ID]);       // induk tidak ada
                $D = q9_folder($t, 'D', ['perm' => 1, 'by' => $meName, 'active' => 0]);
                $DC = q9_folder($t, 'DC', ['perm' => 1, 'by' => $meName, 'parent' => $D]);               // induk nonaktif
                q9_set_archive($t, $X1, ['id_archive_parent' => $X2]);                                      // putar
                q9_set_archive($t, $S, ['id_archive_parent' => $S]);                                        // induk diri sendiri
                $ids = [$X1, $X2, $S, $G, $D, $DC];

                $t0 = microtime(true);
                $r = q9_get($t, $s, $b[0]);
                $t->true(microtime(true) - $t0 < 8, 'GET cepat (tidak menggantung)');
                $t->status($r, 200, 'GET dengan data induk tidak lazim');
                $order = [];
                $flat = [];
                q9_flatten($r[1]['result']['data'], $flat, $order);
                $t->eq(count($order), count(array_unique($order)), 'tiap folder tepat sekali');
                foreach ([$X1, $X2, $S, $G, $DC] as $id) {
                    $t->true(isset($flat[$id]), 'folder ' . substr($id, -6) . ' tampil');
                }
                $t->true(!isset($flat[$D]), 'folder nonaktif D tidak tampil');
                foreach ([$X1, $X2, $S, $G, $DC] as $id) {
                    $t->eq($flat[$id]['access']['manage_permission'], true, 'access dihitung (pembuat) untuk ' . substr($id, -6));
                }

                $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($X1, 1), q9_row_body($X2, 1, 1), q9_row_body($S, 1), q9_row_body($G, 1), q9_row_body($DC, 1)]]);
                $t->status($r, 200, 'PUT atas folder dengan induk tidak lazim');
                $t->eq(count(q9_user_map($t, $b[0], $ids)), 5, 'DB: 5 baris tersimpan');
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-4',
        'title' => 'Target tak lazim dan id_archive aneh: target superadmin/diri sendiri bisa disimpan (tanpa 5xx); id_archive int 422; "P " (spasi), huruf besar/kecil, % _ SQL 404 tanpa tersimpan; baris sah tetap tidak tersimpan bila ada baris aneh',
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
                $ids = array_values($f);

                // target superadmin (user lain dengan role 1/2)
                $sup = $t->db()->table('user_roles')->join('roles', 'roles.id_role', '=', 'user_roles.id_role')
                    ->join('users', 'users.id_user', '=', 'user_roles.id_user')
                    ->whereIn('roles.is_superadmin', [1, 2])->where('users.is_active', 1)->where('users.id_user', '!=', $me)
                    ->value('users.id_user');
                if ($sup) {
                    $r = q9_put($t, $s, $sup, ['permissions' => [q9_row_body($f['P'], 1, 1)]]);
                    q9_no500($t, $r, 'PUT target superadmin');
                    $t->status($r, 200, 'PUT target superadmin');
                    $g = q9_get($t, $s, $sup);
                    $t->status($r, 200, 'GET target superadmin');
                    $n = q9_flatten($g[1]['result']['data'])[$f['P']];
                    $t->eq(json_encode([$n['is_view'], $n['is_update']]), '[1,1]', 'GET target superadmin: nilai tersimpan tampil');
                } else {
                    $t->note('tidak ada user superadmin aktif lain: target superadmin dilewati');
                }

                // id_archive aneh
                $before = q9_snap($t, $ids);
                $pId = $f['P'];
                $odd = [
                    'spasi di belakang'  => $pId . ' ',
                    'spasi di depan'     => ' ' . $pId,
                    'tab'                => $pId . "\t",
                    'newline'            => $pId . "\n",
                    'persen'             => '%',
                    'underscore'         => '_',
                    'SQL'                => "' OR '1'='1",
                    'SQL 2'              => "$pId' OR '1'='1",
                    'panjang 400'        => str_repeat('9', 400),
                    'null byte'          => $pId . "\0",
                    'unicode latin1'     => 'ünï',
                    'huruf kecil'        => strtolower($pId),
                ];
                foreach ($odd as $label => $id) {
                    $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($id, 1)]]);
                    q9_no500($t, $r, "id_archive $label");
                    $t->true(in_array($r[0], [404, 422, 403, 200]), "id_archive $label: " . $r[0] . ' ' . q9_code($r));
                    if ($r[0] === 200) {
                        // diterima: boleh hanya bila sama dengan folder P (MySQL membandingkan tanpa spasi/kapital) dan hasilnya P saja
                        $map = q9_user_map($t, $b[0], $ids);
                        $t->eq(json_encode($map[$pId] ?? null), '[1,0,0,0]', "id_archive $label: diterima, tersimpan pada P");
                        q9_perm_restore_row($t, $pId, $b[0], [1, 0, 0, 1]);
                        $before = q9_snap($t, $ids);
                    } else {
                        $t->true(q9_same($before, q9_snap($t, $ids)), "id_archive $label: tidak ada yang tersimpan");
                    }
                    // bersama baris sah
                    $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['C1'], 1, 1, 1, 1), q9_row_body($id, 1)]]);
                    q9_no500($t, $r, "C1 + id_archive $label");
                    if ($r[0] >= 400) {
                        $t->true(q9_same($before, q9_snap($t, $ids)), "C1 + id_archive $label: atomik (tidak ada yang tersimpan)");
                    } else {
                        // sukses: kembalikan C1 supaya pembanding berikutnya tetap sah
                        q9_perm_restore_row($t, $f['C1'], $b[0], [1, 0, 0, 0]);
                    }
                    $before = q9_snap($t, $ids);
                }

                // id_archive bertipe int / float / bool
                foreach (['int' => 123, 'float' => 1.5, 'bool' => true] as $label => $id) {
                    $r = q9_put($t, $s, $b[0], ['permissions' => [['id_archive' => $id, 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0]]]);
                    q9_no500($t, $r, "id_archive $label");
                    $t->true(in_array($r[0], [422, 404]), "id_archive $label: 422/404 (" . $r[0] . ')');
                }
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

];
