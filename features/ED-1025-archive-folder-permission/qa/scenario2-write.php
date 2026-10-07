<?php
/**
 * ED-1025 - AC-5 (simpan tab Permission lewat PUT), AC-6 (ARCHIVE411), AC-12 (Update/ganti induk), AC-9 (K-1 a: semua hak
 * dominan dari induk), AC-22 (riwayat `permission`), X-1 (semantik field opsional PUT).
 *
 * User uji = QA_USER (role 3 = bukan bypass); baris permission/pembuat/flag folder uji menentukan hak efektifnya.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-5',
        'title' => 'PUT archives/{P} is_folder_permission=1 + baris: 200 ARCHIVE207, baris lama diganti persis, baris semua-0 tidak disimpan, id_user ganda 422 (dan validasi lain 422, data tetap)',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $me = q2_uname($t);
            $o = q2_others($t, 5);

            try {
                $P = q2_folder($t, 'P');
                $pName = q2_row($t, $P)['name'];
                q2_perm($t, $P, $o[0][0], 1);                 // lama: U1 (akan dihapus)
                q2_perm($t, $P, $o[1][0], 1);                 // lama: U2 (akan diganti)

                $r = q2_put($t, $s, $P, q2_body($pName, ['is_folder_permission' => 1, 'folder_permissions' => [
                    ['id_user' => $o[1][0], 'is_view' => 1, 'is_update' => 1, 'is_delete' => 1, 'is_store' => 0],
                    ['id_user' => $o[2][0], 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 1],
                    ['id_user' => $o[3][0], 'is_view' => 0, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0],
                ]]));
                $t->status($r, 200, 'PUT permission');
                $t->code($r, 'ARCHIVE207', 'PUT permission');
                $t->eq($r[1]['result']['id_archive'], $P, 'result.id_archive');
                $t->eq((int) q2_row($t, $P)['is_folder_permission'], 1, 'DB: is_folder_permission = 1');

                $map = q2_perm_map($t, $P);
                $want = [$o[1][0] => [1, 1, 1, 0], $o[2][0] => [1, 0, 0, 1]];
                ksort($want);
                $t->eq(json_encode($map), json_encode($want), 'DB: tepat 2 baris (U2 diganti, U3 baru)');
                $t->eq(count($map), 2, 'DB: jumlah baris = 2');
                $t->true(!array_key_exists($o[0][0], $map), 'DB: baris lama U1 terhapus');
                $t->true(!array_key_exists($o[3][0], $map), 'DB: baris semua-0 (U4) tidak disimpan');
                $row = $t->db()->table('archive_permissions')->where('id_archive', $P)->where('id_user', $o[2][0])->first();
                $t->eq($row->created_by, $me, 'baris baru: created_by = pemanggil');
                $t->eq($row->updated_by, $me, 'baris baru: updated_by = pemanggil');
                $t->true($row->created_at !== null && $row->id_archive_permission !== '', 'baris baru: created_at dan PK terisi');
                $t->eq($t->db()->table('archive_permissions')->where('id_archive', $P)->where('id_user', $o[1][0])->value('updated_by'), $me, 'baris diganti: updated_by = pemanggil');

                // sesudah aktif, pemanggil (bukan pembuat, tanpa baris) tidak punya hak apa pun (BR-4/6)
                q2_deny($t, q2_show($t, $s, $P), 403, 'ARCHIVE407', 'sesudah aktif: show', $pName);
                q2_deny($t, q2_put($t, $s, $P, q2_body($pName)), 403, 'ARCHIVE408', 'sesudah aktif: PUT lagi', $pName);

                // validasi: data tetap
                $Q = q2_folder($t, 'Q');
                $qName = q2_row($t, $Q)['name'];
                q2_perm($t, $Q, $o[0][0], 1, 1, 0, 0);
                $before = q2_snap($t, [$Q]);
                $u1 = $o[0][0];
                $ok = function ($id, $v = 1, $u = 0, $d = 0, $s2 = 0) {
                    return ['id_user' => $id, 'is_view' => $v, 'is_update' => $u, 'is_delete' => $d, 'is_store' => $s2];
                };
                $cases = [
                    'id_user ganda'              => ['folder_permissions' => [$ok($u1), $ok($u1, 1, 1)]],
                    'id_user ganda (3 baris)'    => ['folder_permissions' => [$ok($u1), $ok($o[1][0]), $ok($u1)]],
                    'id_user tidak ada'          => ['folder_permissions' => [$ok('QA02-TIDAK-ADA')]],
                    'is_view = 2'                => ['folder_permissions' => [$ok($u1, 2)]],
                    'flag hilang (is_store)'     => ['folder_permissions' => [['id_user' => $u1, 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0]]],
                    'id_user hilang'             => ['folder_permissions' => [['is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0]]],
                    'is_folder_permission = 5'   => ['is_folder_permission' => 5],
                    'is_folder_permission array' => ['is_folder_permission' => [1]],
                    'folder_permissions string'  => ['folder_permissions' => 'x'],
                    'baris bukan objek'          => ['folder_permissions' => ['x']],
                ];
                foreach ($cases as $label => $extra) {
                    $r = q2_put($t, $s, $Q, q2_body($qName, $extra));
                    q2_no500($t, $r, $label);
                    $t->status($r, 422, $label);
                    $t->true(is_array($r[1]['errors'] ?? null) && count($r[1]['errors']) > 0, $label . ': errors terisi');
                    $t->true(q2_same($before, q2_snap($t, [$Q])), $label . ': data tidak berubah');
                }
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-6',
        'title' => 'PUT baris is_update/is_delete/is_store=1 dengan is_view=0: 400 ARCHIVE411 (ID/EN), data tidak berubah (atomik, juga bila baris lain valid); 403 mendahului 400',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $o = q2_others($t, 2);

            try {
                $P = q2_folder($t, 'P', ['by' => q2_uname($t)]);
                $pName = q2_row($t, $P)['name'];
                $before = q2_snap($t, [$P]);
                $valid = ['id_user' => $o[0][0], 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0];
                $bad = [
                    'update tanpa view' => ['id_user' => $o[1][0], 'is_view' => 0, 'is_update' => 1, 'is_delete' => 0, 'is_store' => 0],
                    'delete tanpa view' => ['id_user' => $o[1][0], 'is_view' => 0, 'is_update' => 0, 'is_delete' => 1, 'is_store' => 0],
                    'store tanpa view'  => ['id_user' => $o[1][0], 'is_view' => 0, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 1],
                    'semua tanpa view'  => ['id_user' => $o[1][0], 'is_view' => 0, 'is_update' => 1, 'is_delete' => 1, 'is_store' => 1],
                ];
                q2_with_user($t, ['lang' => 'ID'], function ($set) use ($t, $s, $P, $pName, $bad, $valid, $before) {
                    foreach ($bad as $label => $row) {
                        foreach ([[$valid, $row], [$row, $valid], [$row]] as $i => $rows) {
                            $r = q2_put($t, $s, $P, q2_body($pName . 'X', ['is_folder_permission' => 1, 'folder_permissions' => $rows]));
                            q2_deny($t, $r, 400, 'ARCHIVE411', "$label #$i");
                            $t->eq($r[1]['message'], 'Permission Update, Delete, dan Store membutuhkan permission View', "$label #$i: pesan ID");
                            $t->true(q2_same($before, q2_snap($t, [$P])), "$label #$i: data tidak berubah (nama, toggle, baris, riwayat)");
                        }
                    }
                    $set(['lang' => 'EN']);
                    $r = q2_put($t, $s, $P, q2_body($pName, ['folder_permissions' => [$bad['update tanpa view']]]));
                    q2_deny($t, $r, 400, 'ARCHIVE411', 'EN');
                    $t->eq($r[1]['message'], 'Update, Delete and Store permission require View permission', 'pesan EN');
                });

                // baris tanpa hak apa pun (semua 0) bukan pelanggaran: 200, tidak disimpan
                $r = q2_put($t, $s, $P, q2_body($pName, ['is_folder_permission' => 1, 'folder_permissions' => [
                    ['id_user' => $o[1][0], 'is_view' => 0, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0],
                ]]));
                $t->status($r, 200, 'baris semua-0 diterima');
                $t->eq(count(q2_perm_map($t, $P)), 0, 'baris semua-0 tidak disimpan');

                // 403 (hak) mendahului 400 (aturan baris)
                $Q = q2_folder($t, 'Q', ['perm' => 1]);
                q2_perm($t, $Q, q2_uid($t), 1);
                $qName = q2_row($t, $Q)['name'];
                $r = q2_put($t, $s, $Q, q2_body($qName, ['folder_permissions' => [$bad['update tanpa view']]]));
                q2_deny($t, $r, 403, 'ARCHIVE408', 'B tanpa Update + baris tidak valid: 403 lebih dulu', $qName);
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-22',
        'title' => 'Perubahan permission dicatat di riwayat folder: aksi permission + username pelaku (DB, history/{id}, label ID/EN, baris daftar); tanpa perubahan atau rename tidak mencatat',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $me = q2_uname($t);
            $o = q2_others($t, 2);
            $count = function ($P) use ($t) {
                return count(array_keys(q2_history_actions($t, $P), 'permission'));
            };
            $row = function ($id, $v = 1, $u = 0, $d = 0, $st = 0) {
                return ['id_user' => $id, 'is_view' => $v, 'is_update' => $u, 'is_delete' => $d, 'is_store' => $st];
            };

            try {
                $P = q2_folder($t, 'P', ['by' => $me]);        // pembuat = B: tetap punya semua hak sesudah aktif
                $pName = q2_row($t, $P)['name'];
                $t->eq(q2_history_actions($t, $P), [], 'prasyarat: riwayat kosong');

                $r = q2_put($t, $s, $P, q2_body($pName, ['is_folder_permission' => 1, 'folder_permissions' => [$row($o[0][0])]]));
                $t->status($r, 200, 'PUT permission');
                $t->eq(q2_history_actions($t, $P), ['update', 'permission'], 'DB history: update lalu permission');
                $h = q2_history($t, $P);
                $last = end($h);
                $t->eq($last['action'], 'permission', 'entri terakhir = permission');
                $t->eq($last['related_username'], $me, 'entri terakhir: related_username = pelaku');
                $t->true(!empty($last['timestamp']), 'entri terakhir: timestamp terisi');

                $r = q2_with_user($t, ['lang' => 'ID'], function ($set) use ($t, $s, $P) {
                    return q2_hist($t, $s, $P);
                });
                $t->status($r, 200, 'GET history');
                $data = $r[1]['result']['data'];
                $t->eq(end($data)['action'], 'permission', 'GET history: entri terakhir permission');
                $t->eq(end($data)['related_username'], $me, 'GET history: username pelaku');
                $labels = $r[1]['result']['action_labels'];
                $t->has($labels, 'permission.en', 'label en');
                $t->eq($labels['permission']['en'], 'Permission changed by <strong>{relatedUsername}</strong>', 'label en');
                $t->eq($labels['permission']['id'], 'Permission diubah oleh <strong>{relatedUsername}</strong>', 'label id');

                // baris folder di daftar membawa teks riwayat terakhir (bahasa pengguna)
                $r = q2_with_user($t, ['lang' => 'ID'], function ($set) use ($t, $s, $P) {
                    return q2_list($t, $s, ['search' => json_encode(['query' => q2_row($t, $P)['name']]), 'pagination' => 20]);
                });
                $line = q2_find($r, $P)['history'] ?? null;
                $t->eq(strip_tags($line), 'Permission diubah oleh ' . $me, 'baris daftar: teks riwayat ID');

                // PUT sama persis lagi: tidak ada entri permission baru (urutan baris beda pun tidak)
                $n = $count($P);
                $r = q2_put($t, $s, $P, q2_body($pName, ['is_folder_permission' => 1, 'folder_permissions' => [$row($o[0][0])]]));
                $t->status($r, 200, 'PUT sama');
                $t->eq($count($P), $n, 'PUT tanpa perubahan: tidak ada entri permission baru');
                $r = q2_put($t, $s, $P, q2_body($pName));
                $t->eq($count($P), $n, 'PUT tanpa field permission: tidak ada entri permission baru');
                $r = q2_rename($t, $s, $P, $pName . 'R');
                $t->eq($count($P), $n, 'rename: tidak ada entri permission baru');
                $pName .= 'R';

                // ubah satu flag baris: dicatat
                $r = q2_put($t, $s, $P, q2_body($pName, ['is_folder_permission' => 1, 'folder_permissions' => [$row($o[0][0], 1, 0, 0, 1)]]));
                $t->status($r, 200, 'ubah flag');
                $t->eq($count($P), $n + 1, 'ubah flag baris: entri permission baru');
                // hanya toggle: dicatat
                $r = q2_put($t, $s, $P, q2_body($pName, ['is_folder_permission' => 0]));
                $t->status($r, 200, 'toggle mati');
                $t->eq($count($P), $n + 2, 'toggle saja: entri permission baru');
                $t->eq(count(q2_perm_map($t, $P)), 1, 'toggle mati: baris tidak dihapus (BR-16)');
                // tambah baris baru: dicatat; hapus semua baris dengan []: dicatat
                $r = q2_put($t, $s, $P, q2_body($pName, ['folder_permissions' => [$row($o[0][0], 1, 0, 0, 1), $row($o[1][0])]]));
                $t->eq($count($P), $n + 3, 'tambah baris: entri permission baru');
                $r = q2_put($t, $s, $P, q2_body($pName, ['folder_permissions' => []]));
                $t->status($r, 200, 'folder_permissions []');
                $t->eq($count($P), $n + 4, 'hapus semua baris: entri permission baru');
                $t->eq(count(q2_perm_map($t, $P)), 0, '[] menghapus semua baris');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-12',
        'title' => 'B View tanpa Update: PUT/rename 403 ARCHIVE408 (juga tubuh kosong, 403 sebelum 422); ganti id_archive_parent ke folder tanpa Store 403 ARCHIVE410; ke folder ber-Store/nonaktif/root/induk sama 200',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);

            try {
                // --- tanpa Update
                $Q = q2_folder($t, 'Q', ['perm' => 1]);
                $qName = q2_row($t, $Q)['name'];
                q2_perm($t, $Q, $b, 1);
                $before = q2_snap($t, [$Q]);
                q2_deny($t, q2_put($t, $s, $Q, q2_body($qName . 'X')), 403, 'ARCHIVE408', 'PUT tanpa Update', $qName);
                q2_deny($t, q2_rename($t, $s, $Q, $qName . 'X'), 403, 'ARCHIVE408', 'rename tanpa Update', $qName);
                q2_deny($t, $t->call($s, 'PUT', 'api/v5/document-archive/archives/' . $Q, []), 403, 'ARCHIVE408', 'PUT tubuh kosong: 403 sebelum 422', $qName);
                q2_deny($t, $t->call($s, 'PUT', 'api/v5/document-archive/archives/rename/' . $Q, []), 403, 'ARCHIVE408', 'rename tubuh kosong: 403 sebelum 422', $qName);
                q2_deny($t, q2_put($t, $s, $Q, q2_body($qName . 'X', ['is_folder_permission' => 0, 'folder_permissions' => []])), 403, 'ARCHIVE408', 'PUT permission tanpa Update', $qName);
                $t->true(q2_same($before, q2_snap($t, [$Q])), 'data tidak berubah oleh penolakan');
                $t->eq(q2_row($t, $Q)['name'], $qName, 'nama tetap');

                // --- ganti induk
                $parentDeny = q2_folder($t, 'PD', ['perm' => 1]);                 // B tanpa baris
                $parentStore = q2_folder($t, 'PS', ['perm' => 1]);                // B View+Store
                $parentNoStore = q2_folder($t, 'PN', ['perm' => 1]);              // B View+Update (tanpa Store)
                $parentOff = q2_folder($t, 'PO', ['perm' => 0]);                  // nonaktif
                q2_perm($t, $parentStore, $b, 1, 0, 0, 1);
                q2_perm($t, $parentNoStore, $b, 1, 1, 0, 0);
                $pdName = q2_row($t, $parentDeny)['name'];
                $pnName = q2_row($t, $parentNoStore)['name'];

                $M = q2_folder($t, 'M', ['perm' => 1]);
                $mName = q2_row($t, $M)['name'];
                q2_perm($t, $M, $b, 1, 1, 0, 0);
                $beforeM = q2_snap($t, [$M]);
                q2_deny($t, q2_put($t, $s, $M, q2_body($mName, ['id_archive_parent' => $parentDeny])), 403, 'ARCHIVE410', 'ganti induk ke folder tanpa baris', $pdName);
                q2_deny($t, q2_put($t, $s, $M, q2_body($mName, ['id_archive_parent' => $parentNoStore])), 403, 'ARCHIVE410', 'ganti induk ke folder tanpa Store', $pnName);
                $t->true(q2_same($beforeM, q2_snap($t, [$M])), 'induk tidak berubah oleh penolakan');
                $t->eq(q2_row($t, $M)['id_archive_parent'], null, 'induk M masih root');

                $r = q2_put($t, $s, $M, q2_body($mName, ['id_archive_parent' => $parentOff]));
                $t->status($r, 200, 'ke induk nonaktif');
                $t->eq(q2_row($t, $M)['id_archive_parent'], $parentOff, 'DB: induk = PO');
                // K-1 a: sesudah masuk PS (aktif, B View+Store), Update di M tetap perlu Update di PS; beri B Update di PS
                $r = q2_put($t, $s, $M, q2_body($mName, ['id_archive_parent' => $parentStore]));
                $t->status($r, 200, 'ke induk ber-Store (B Update di M, Store di PS)');
                $t->eq(q2_row($t, $M)['id_archive_parent'], $parentStore, 'DB: induk = PS');

                // induk sama (tanpa Store di induk itu, tetapi Update di jalur) -> tidak dihitung ganti induk
                q2_unperm($t, $parentStore, $b);
                q2_perm($t, $parentStore, $b, 1, 1, 0, 0);                 // B: View+Update, tanpa Store
                $r = q2_put($t, $s, $M, q2_body($mName . 'S', ['id_archive_parent' => $parentStore]));
                $t->status($r, 200, 'induk sama tanpa Store di induk: 200 (bukan ganti induk)');
                $t->eq(q2_row($t, $M)['name'], $mName . 'S', 'nama berubah');
                // ... tetapi pindah ke folder lain yang juga tanpa Store tetap ditolak
                q2_deny($t, q2_put($t, $s, $M, q2_body($mName . 'S', ['id_archive_parent' => $parentNoStore])), 403, 'ARCHIVE410', 'pindah ke induk lain tanpa Store', $pnName);
                // keluar ke root: tanpa cek hak tujuan
                $r = q2_put($t, $s, $M, q2_body($mName . 'S', ['id_archive_parent' => null]));
                $t->status($r, 200, 'keluar ke root');
                $t->eq(q2_row($t, $M)['id_archive_parent'], null, 'DB: induk = root');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-9',
        'title' => 'Parent dominan untuk semua hak (K-1 a): C aktif View saja -> rename/Store ke C 403 ARCHIVE408/410; Store di C tanpa Store di P -> 403 (folder penolak = P); Update di C tanpa Update di P -> 403; kontrol 200',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);

            try {
                // --- 9a: P (B V+U+S), C aktif (B View saja)
                $P = q2_folder($t, 'P', ['perm' => 1]);
                $C = q2_folder($t, 'C', ['perm' => 1, 'parent' => $P]);
                $pName = q2_row($t, $P)['name'];
                $cName = q2_row($t, $C)['name'];
                q2_perm($t, $P, $b, 1, 1, 0, 1);
                q2_perm($t, $C, $b, 1);
                $Dr = q2_doc($t, 'DR');
                $before = q2_snap($t, [$P, $C, $Dr]);

                q2_deny($t, q2_rename($t, $s, $C, $cName . 'X'), 403, 'ARCHIVE408', '9a rename C', $cName);
                q2_deny($t, q2_put($t, $s, $C, q2_body($cName . 'X')), 403, 'ARCHIVE408', '9a PUT C', $cName);
                q2_deny($t, q2_putin($t, $s, $C, $Dr), 403, 'ARCHIVE410', '9a put-in dokumen ke C', $cName);
                q2_deny($t, q2_create($t, $s, ['name' => q2_name('SUB'), 'id_archive_parent' => $C, 'is_all_location' => 1]), 403, 'ARCHIVE410', '9a create-folder di C', $cName);
                $t->true(q2_same($before, q2_snap($t, [$P, $C, $Dr])), '9a: data tidak berubah');
                // di P (B punya Update & Store): sama-sama jalan
                $r = q2_rename($t, $s, $P, $pName . 'R');
                $t->status($r, 200, '9a rename P (Update di P)');
                $t->status(q2_putin($t, $s, $P, $Dr), 200, '9a put-in ke P (Store di P)');

                // --- 9b: C (B V+U+S), P (B V+U tanpa Store) -> Store ke C ditolak oleh P
                $P2 = q2_folder($t, 'P2', ['perm' => 1]);
                $C2 = q2_folder($t, 'C2', ['perm' => 1, 'parent' => $P2]);
                $p2Name = q2_row($t, $P2)['name'];
                q2_perm($t, $P2, $b, 1, 1, 0, 0);
                q2_perm($t, $C2, $b, 1, 1, 0, 1);
                $D2 = q2_doc($t, 'D2');
                q2_deny($t, q2_putin($t, $s, $C2, $D2), 403, 'ARCHIVE410', '9b put-in ke C2: P2 tanpa Store', $p2Name);
                q2_deny($t, q2_create($t, $s, ['name' => q2_name('SUB'), 'id_archive_parent' => $C2, 'is_all_location' => 1]), 403, 'ARCHIVE410', '9b create-folder di C2', $p2Name);
                $t->eq(q2_row($t, $D2)['id_archive_parent'], null, '9b: dokumen tetap di root');
                // rename C2 jalan (Update di P2 dan C2)
                $t->status(q2_rename($t, $s, $C2, q2_row($t, $C2)['name'] . 'R'), 200, '9b rename C2 (Update di P2 dan C2)');
                // beri Store di P2 -> put-in jalan
                q2_unperm($t, $P2, $b);
                q2_perm($t, $P2, $b, 1, 1, 0, 1);
                $t->status(q2_putin($t, $s, $C2, $D2), 200, '9b put-in ke C2 sesudah Store di P2');
                $t->eq(q2_row($t, $D2)['id_archive_parent'], $C2, '9b: dokumen kini di C2');

                // --- 9c: Update di C tetapi tidak di P -> ditolak oleh P
                $P3 = q2_folder($t, 'P3', ['perm' => 1]);
                $C3 = q2_folder($t, 'C3', ['perm' => 1, 'parent' => $P3]);
                $p3Name = q2_row($t, $P3)['name'];
                q2_perm($t, $P3, $b, 1);
                q2_perm($t, $C3, $b, 1, 1, 1, 1);
                $c3Name = q2_row($t, $C3)['name'];
                q2_deny($t, q2_rename($t, $s, $C3, $c3Name . 'X'), 403, 'ARCHIVE408', '9c rename C3: P3 tanpa Update', $p3Name);
                q2_deny($t, q2_put($t, $s, $C3, q2_body($c3Name . 'X')), 403, 'ARCHIVE408', '9c PUT C3: P3 tanpa Update', $p3Name);
                q2_deny($t, q2_delete($t, $s, $C3), 403, 'ARCHIVE409', '9c delete C3: P3 tanpa Delete', $p3Name);

                // --- 9d: kontrol: P dan C sama-sama memberi semua hak -> semua 200
                $P4 = q2_folder($t, 'P4', ['perm' => 1]);
                $C4 = q2_folder($t, 'C4', ['perm' => 1, 'parent' => $P4]);
                q2_perm($t, $P4, $b, 1, 1, 1, 1);
                q2_perm($t, $C4, $b, 1, 1, 1, 1);
                $D4 = q2_doc($t, 'D4');
                $c4Name = q2_row($t, $C4)['name'];
                $r = q2_open($t, $s, $C4);
                $t->status($r, 200, '9d buka C4');
                q2_access_is($t, $r[1]['result']['access'], q2_all_true(), '9d access C4');
                $t->status(q2_putin($t, $s, $C4, $D4), 200, '9d put-in ke C4');
                $t->status(q2_rename($t, $s, $C4, $c4Name . 'R'), 200, '9d rename C4');
                q2_deny($t, q2_delete($t, $s, $C4), 400, 'ARCHIVE401', '9d delete C4 berisi dokumen aktif: ARCHIVE401 (lolos cek hak folder)');
                $t->status(q2_putin($t, $s, null, $D4), 200, '9d dokumen keluar ke root');
                $r = q2_delete($t, $s, $C4);
                $t->status($r, 200, '9d delete C4 sesudah kosong (Delete di P4 dan C4)');
                $t->code($r, 'ARCHIVE203', '9d delete');
                $t->eq((int) q2_row($t, $C4)['is_active'], 0, '9d C4 nonaktif');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-1',
        'title' => 'Semantik field opsional PUT: tanpa field permission = toggle/baris tetap; folder_permissions null = tetap; [] = hapus semua; toggle mati menyimpan baris; folder baru selalu nonaktif walau body create-folder memuat field itu',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);
            $o = q2_others($t, 2);

            try {
                $P = q2_folder($t, 'P', ['perm' => 1]);
                $pName = q2_row($t, $P)['name'];
                q2_perm($t, $P, $b, 1, 1, 0, 0);
                q2_perm($t, $P, $o[0][0], 1);
                $rowsBefore = q2_perm_map($t, $P);

                // PUT tanpa field permission (klien lama): tidak mengubah toggle maupun baris
                $r = q2_put($t, $s, $P, q2_body($pName . 'U'));
                $t->status($r, 200, 'PUT tanpa field permission');
                $t->eq((int) q2_row($t, $P)['is_folder_permission'], 1, 'toggle tetap 1');
                $t->eq(json_encode(q2_perm_map($t, $P)), json_encode($rowsBefore), 'baris tetap');
                // folder_permissions null: tetap
                $r = q2_put($t, $s, $P, q2_body($pName . 'U', ['folder_permissions' => null, 'is_folder_permission' => null]));
                $t->status($r, 200, 'folder_permissions null');
                $t->eq((int) q2_row($t, $P)['is_folder_permission'], 1, 'null: toggle tetap 1');
                $t->eq(json_encode(q2_perm_map($t, $P)), json_encode($rowsBefore), 'null: baris tetap');
                // is_folder_permission sebagai string "0": mematikan, baris tetap tersimpan
                $r = q2_put($t, $s, $P, q2_body($pName . 'U', ['is_folder_permission' => '0']));
                $t->status($r, 200, 'toggle "0"');
                $t->eq((int) q2_row($t, $P)['is_folder_permission'], 0, 'toggle mati');
                $t->eq(json_encode(q2_perm_map($t, $P)), json_encode($rowsBefore), 'toggle mati: baris tetap');
                // dinyalakan lagi: baris yang tersimpan berlaku lagi (B View+Update)
                $r = q2_put($t, $s, $P, q2_body($pName . 'U', ['is_folder_permission' => 1]));
                $t->status($r, 200, 'toggle nyala');
                $r = q2_open($t, $s, $P);
                $t->status($r, 200, 'baris lama berlaku lagi');
                q2_access_is($t, $r[1]['result']['access'], [1, 1, 0, 0], 'access dari baris lama');
                // [] menghapus semua baris (termasuk baris pemanggil) -> pemanggil terkunci
                $r = q2_put($t, $s, $P, q2_body($pName . 'U', ['folder_permissions' => []]));
                $t->status($r, 200, 'folder_permissions []');
                $t->eq(count(q2_perm_map($t, $P)), 0, '[] menghapus semua baris');
                q2_deny($t, q2_show($t, $s, $P), 403, 'ARCHIVE407', 'sesudah [] pemanggil tanpa baris', $pName . 'U');

                // create-folder tidak bisa membuat folder langsung aktif
                $name = q2_name('NEWP');
                $r = q2_create($t, $s, ['name' => $name, 'is_all_location' => 1, 'is_folder_permission' => 1, 'folder_permissions' => [
                    ['id_user' => $o[0][0], 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0],
                ]]);
                $t->status($r, 200, 'create-folder dengan field permission');
                $id = $t->db()->table('archives')->where('name', $name)->value('id_archive');
                $t->eq((int) q2_row($t, $id)['is_folder_permission'], 0, 'folder baru: is_folder_permission = 0 (BR-1)');
                $t->eq(count(q2_perm_map($t, $id)), 0, 'folder baru: tanpa baris');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

];
