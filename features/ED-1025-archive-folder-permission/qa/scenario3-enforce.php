<?php
/**
 * ED-1025 - AC-13 (hapus: ARCHIVE409), AC-14 (put-in/create-folder: ARCHIVE410/408, dokumen tanpa cek sumber),
 * AC-24 (hapus: subfolder aktif tanpa Delete efektif = ARCHIVE418), X-2 (teks pesan ID/EN + nama folder dengan karakter khusus),
 * X-3 (BR-13: hand-over/receive tidak dicek hak folder).
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-13',
        'title' => 'B tanpa Delete: DELETE 403 ARCHIVE409 (is_active tetap, riwayat tetap); Delete juga dituntut di induk aktif (K-1 a); urutan 404 -> 409 -> 401; dengan Delete 200',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);

            try {
                $P = q2_folder($t, 'P', ['perm' => 1]);
                $Cin = q2_folder($t, 'CIN', ['perm' => 0, 'parent' => $P]);
                $pName = q2_row($t, $P)['name'];
                q2_perm($t, $P, $b, 1, 1, 0, 1);
                $before = q2_snap($t, [$P, $Cin]);

                q2_deny($t, q2_delete($t, $s, $P), 403, 'ARCHIVE409', 'DELETE P tanpa Delete', $pName);
                $t->eq((int) q2_row($t, $P)['is_active'], 1, 'P tetap aktif');
                $t->true(q2_same($before, q2_snap($t, [$P, $Cin])), 'data (P dan anak) tidak berubah');
                // anak nonaktif-permission: Delete dituntut di induk aktif
                q2_deny($t, q2_delete($t, $s, $Cin), 403, 'ARCHIVE409', 'DELETE anak: induk tanpa Delete', $pName);
                $t->eq((int) q2_row($t, $Cin)['is_active'], 1, 'anak tetap aktif');

                // 404 mendahului 409
                $r = q2_delete($t, $s, Q2_RANDOM_ID);
                q2_deny($t, $r, 404, 'ARCHIVE400', 'id tidak ada: 404');

                // 409 mendahului 401 (folder berisi dokumen aktif)
                $P2 = q2_folder($t, 'P2', ['perm' => 1]);
                q2_perm($t, $P2, $b, 1, 1, 0, 1);
                q2_doc($t, 'DOC', ['parent' => $P2]);
                q2_deny($t, q2_delete($t, $s, $P2), 403, 'ARCHIVE409', '409 sebelum 401', q2_row($t, $P2)['name']);

                // dengan Delete: 200; P dan anak (aktif) nonaktif
                q2_unperm($t, $P, $b);
                q2_perm($t, $P, $b, 1, 1, 1, 1);
                $r = q2_delete($t, $s, $P);
                $t->status($r, 200, 'DELETE P dengan Delete');
                $t->code($r, 'ARCHIVE203', 'DELETE P');
                $t->eq((int) q2_row($t, $P)['is_active'], 0, 'P nonaktif');
                $t->eq((int) q2_row($t, $Cin)['is_active'], 0, 'anak nonaktif ikut');

                // bahasa EN
                $E = q2_folder($t, 'E', ['perm' => 1]);
                q2_perm($t, $E, $b, 1);
                $eName = q2_row($t, $E)['name'];
                $r = q2_with_user($t, ['lang' => 'EN'], function ($set) use ($t, $s, $E) {
                    return q2_delete($t, $s, $E);
                });
                q2_deny($t, $r, 403, 'ARCHIVE409', 'EN', $eName);
                $t->eq($r[1]['message'], 'You do not have delete permission on folder <b>' . $eName . '</b>', 'pesan EN');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-14',
        'title' => 'Tanpa Store di P: put-in/create-folder 403 ARCHIVE410; dokumen di P (B tanpa View) boleh dipindah ke folder ber-Store; folder tanpa Update 403 ARCHIVE408 (id_archives dan name, atomik); dokumen ke root 200',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);

            try {
                // --- tanpa Store di P
                $P = q2_folder($t, 'P', ['perm' => 1]);
                $pName = q2_row($t, $P)['name'];
                q2_perm($t, $P, $b, 1, 1, 1, 0);
                $Dr = q2_doc($t, 'DR');
                $drName = q2_row($t, $Dr)['name'];
                $before = q2_snap($t, [$P, $Dr]);
                q2_deny($t, q2_putin($t, $s, $P, $Dr), 403, 'ARCHIVE410', 'put-in dokumen ke P', $pName);
                q2_deny($t, q2_putin($t, $s, $P, null, $drName), 403, 'ARCHIVE410', 'put-in lewat name ke P', $pName);
                q2_deny($t, q2_create($t, $s, ['name' => q2_name('SUB'), 'id_archive_parent' => $P, 'is_all_location' => 1]), 403, 'ARCHIVE410', 'create-folder di P', $pName);
                q2_deny($t, $t->call($s, 'POST', 'api/v5/document-archive/documents/put-in', ['id_archive_parent' => $P, 'id_archives' => ['TIDAK-ADA']]), 403, 'ARCHIVE410', '403 sebelum validasi (id sumber tidak ada)', $pName);
                q2_deny($t, $t->call($s, 'POST', 'api/v5/document-archive/archives/create-folder', ['id_archive_parent' => $P]), 403, 'ARCHIVE410', 'create-folder tubuh tak lengkap: 403 sebelum 422', $pName);
                $t->true(q2_same($before, q2_snap($t, [$P, $Dr])), 'data tidak berubah oleh penolakan');
                $t->eq($t->db()->table('archives')->where('id_archive_parent', $P)->count(), 0, 'tidak ada baris baru di P');

                // --- dokumen D di P (B tanpa View) dipindah ke S (B View+Store)
                $P2 = q2_folder($t, 'P2', ['perm' => 1]);                  // B tanpa baris
                $S = q2_folder($t, 'S', ['perm' => 1]);
                q2_perm($t, $S, $b, 1, 0, 0, 1);
                $D = q2_doc($t, 'D', ['parent' => $P2]);
                $r = q2_putin($t, $s, $S, $D);
                $t->status($r, 200, 'dokumen dari folder tanpa View ke S');
                $t->code($r, 'ARCHIVE204', 'put-in');
                $t->eq(q2_row($t, $D)['id_archive_parent'], $S, 'DB: dokumen kini di S');
                $hist = q2_history($t, $D);
                $last = end($hist);
                $t->eq($last['action'], 'move', 'riwayat dokumen: move');
                $t->eq($last['from_folder'], q2_row($t, $P2)['name'], 'riwayat: from_folder = P2');
                $t->eq($last['to_folder'], q2_row($t, $S)['name'], 'riwayat: to_folder = S');
                // dokumen ke root: tanpa cek
                $r = q2_putin($t, $s, null, $D);
                $t->status($r, 200, 'dokumen ke root');
                $t->eq(q2_row($t, $D)['id_archive_parent'], null, 'DB: dokumen di root');
                $hist = q2_history($t, $D);
                $t->eq(end($hist)['action'], 'take out', 'riwayat dokumen: take out');
                // dokumen dari root ke S lewat name
                $r = q2_putin($t, $s, $S, null, q2_row($t, $D)['name']);
                $t->status($r, 200, 'dokumen lewat name ke S');

                // --- folder dipindah: Update di folder itu
                $Fv = q2_folder($t, 'FV', ['perm' => 1]);                  // B View saja
                $Fu = q2_folder($t, 'FU', ['perm' => 1]);                  // B View+Update
                q2_perm($t, $Fv, $b, 1);
                q2_perm($t, $Fu, $b, 1, 1, 0, 0);
                $fvName = q2_row($t, $Fv)['name'];
                $D3 = q2_doc($t, 'D3');
                $snap = q2_snap($t, [$Fv, $Fu, $D3, $S]);
                q2_deny($t, q2_putin($t, $s, $S, $Fv), 403, 'ARCHIVE408', 'folder tanpa Update ke S (id_archives)', $fvName);
                q2_deny($t, q2_putin($t, $s, $S, null, $fvName), 403, 'ARCHIVE408', 'folder tanpa Update ke S (name)', $fvName);
                q2_deny($t, q2_putin($t, $s, $S, [$D3, $Fv]), 403, 'ARCHIVE408', 'campuran dokumen + folder tanpa Update', $fvName);
                $t->eq(q2_row($t, $D3)['id_archive_parent'], null, 'atomik: dokumen D3 tidak ikut pindah');
                q2_deny($t, q2_putin($t, $s, null, $Fv), 403, 'ARCHIVE408', 'folder tanpa Update ke root', $fvName);
                $t->true(q2_same($snap, q2_snap($t, [$Fv, $Fu, $D3, $S])), 'data tidak berubah oleh penolakan');
                // Store ditolak lebih dulu daripada Update (urutan cek di authorize)
                q2_deny($t, q2_putin($t, $s, $P, $Fv), 403, 'ARCHIVE410', 'tujuan tanpa Store dan folder tanpa Update: 410 lebih dulu', $pName);

                $r = q2_putin($t, $s, $S, $Fu);
                $t->status($r, 200, 'folder dengan Update ke S');
                $t->eq(q2_row($t, $Fu)['id_archive_parent'], $S, 'DB: folder FU kini di S');
                // K-1 a: sesudah di bawah S (aktif; B View+Store tanpa Update), Update di FU juga butuh Update di S
                q2_deny($t, q2_putin($t, $s, null, $Fu), 403, 'ARCHIVE408', 'FU di bawah S tanpa Update: ditolak oleh S', q2_row($t, $S)['name']);
                // folder dengan Update ke root (dev: tetap butuh Update di folder yang dipindah): FU di bawah Z nonaktif-permission
                $Z = q2_folder($t, 'Z', ['perm' => 0]);
                q2_set_archive($t, $Fu, ['id_archive_parent' => $Z]);
                $r = q2_putin($t, $s, null, $Fu);
                $t->status($r, 200, 'folder dengan Update ke root');
                $t->eq(q2_row($t, $Fu)['id_archive_parent'], null, 'DB: FU di root');
                $hist = q2_history($t, $Fu);
                $t->eq(end($hist)['action'], 'take out', 'riwayat folder: take out');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-24',
        'title' => 'Hapus P: subfolder aktif turunan (semua tingkat) tanpa Delete efektif -> 403 ARCHIVE418, tidak ada yang terhapus; subfolder nonaktif tidak menghalangi; diberi Delete -> 200; superadmin 1/2 -> 200; 418 sebelum 401',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);

            try {
                // P (B V+D), C aktif (B View, tanpa Delete), G cucu nonaktif-permission
                $P = q2_folder($t, 'P', ['perm' => 1]);
                $C = q2_folder($t, 'C', ['perm' => 1, 'parent' => $P]);
                $G = q2_folder($t, 'G', ['perm' => 0, 'parent' => $C]);
                q2_perm($t, $P, $b, 1, 0, 1, 0);
                q2_perm($t, $C, $b, 1);
                $ids = [$P, $C, $G];
                $before = q2_snap($t, $ids);

                $r = q2_with_user($t, ['lang' => 'ID'], function ($set) use ($t, $s, $P) {
                    return q2_delete($t, $s, $P);
                });
                q2_deny($t, $r, 403, 'ARCHIVE418', 'DELETE P: C tanpa Delete');
                $t->eq($r[1]['message'], 'Folder tidak dapat dihapus, berisi subfolder yang tidak dapat Anda akses atau hapus', 'pesan ID');
                foreach ($ids as $id) {
                    $t->eq((int) q2_row($t, $id)['is_active'], 1, 'tetap aktif: ' . $id);
                }
                $t->true(q2_same($before, q2_snap($t, $ids)), 'semua data tidak berubah (tidak ada yang terhapus diam-diam)');
                // G (nonaktif-permission) di bawah C: Delete di G mengikuti C -> ditolak
                q2_deny($t, q2_delete($t, $s, $G), 403, 'ARCHIVE409', 'DELETE G langsung: ditolak oleh C', q2_row($t, $C)['name']);

                // diberi Delete di C -> 200, seluruh pohon nonaktif
                q2_unperm($t, $C, $b);
                q2_perm($t, $C, $b, 1, 0, 1, 0);
                $r = q2_delete($t, $s, $P);
                $t->status($r, 200, 'DELETE P sesudah Delete di C');
                $t->code($r, 'ARCHIVE203', 'DELETE P');
                foreach ($ids as $id) {
                    $t->eq((int) q2_row($t, $id)['is_active'], 0, 'nonaktif: ' . $id);
                }

                // cucu aktif-permission tanpa Delete (tingkat ke-3) tetap menghalangi
                $P3 = q2_folder($t, 'P3', ['perm' => 1]);
                $C3 = q2_folder($t, 'C3', ['perm' => 1, 'parent' => $P3]);
                $G3 = q2_folder($t, 'G3', ['perm' => 1, 'parent' => $C3]);
                q2_perm($t, $P3, $b, 1, 0, 1, 0);
                q2_perm($t, $C3, $b, 1, 0, 1, 0);
                q2_perm($t, $G3, $b, 1);
                q2_deny($t, q2_delete($t, $s, $P3), 403, 'ARCHIVE418', 'cucu tanpa Delete menghalangi');
                q2_deny($t, q2_delete($t, $s, $C3), 403, 'ARCHIVE418', 'DELETE C3: cucu tanpa Delete');
                foreach ([$P3, $C3, $G3] as $id) {
                    $t->eq((int) q2_row($t, $id)['is_active'], 1, 'tetap aktif: ' . $id);
                }
                // 418 mendahului 401: ada dokumen aktif di G3 juga
                q2_doc($t, 'DOC', ['parent' => $G3]);
                q2_deny($t, q2_delete($t, $s, $P3), 403, 'ARCHIVE418', '418 sebelum 401');

                // subfolder nonaktif (is_active 0) tanpa Delete tidak menghalangi
                $P4 = q2_folder($t, 'P4', ['perm' => 1]);
                q2_folder($t, 'C4', ['perm' => 1, 'parent' => $P4, 'active' => 0]);
                q2_perm($t, $P4, $b, 1, 0, 1, 0);
                $r = q2_delete($t, $s, $P4);
                $t->status($r, 200, 'subfolder nonaktif tidak menghalangi');
                $t->eq((int) q2_row($t, $P4)['is_active'], 0, 'P4 nonaktif');

                // superadmin 1 dan 2: P dengan anak aktif tanpa baris -> 200
                foreach ([1, 2] as $role) {
                    q2_with_user($t, ['role' => $role], function ($set) use ($t, $s, $role) {
                        $PS = q2_folder($t, 'PS' . $role, ['perm' => 1]);
                        $CS = q2_folder($t, 'CS' . $role, ['perm' => 1, 'parent' => $PS]);
                        $r = q2_delete($t, $s, $PS);
                        $t->status($r, 200, "role $role: hapus P beranak aktif tanpa baris");
                        $t->eq((int) q2_row($t, $PS)['is_active'], 0, "role $role: P nonaktif");
                        $t->eq((int) q2_row($t, $CS)['is_active'], 0, "role $role: anak nonaktif");
                    });
                }
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-2',
        'title' => 'Teks pesan ARCHIVE408/409/410 (ID dan EN) memuat nama folder penolak persis, termasuk nama dengan $1, \\1, [0], tag HTML; kode ada di kedua berkas bahasa',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);

            try {
                $plain = q2_name('MSG');
                $weird = Q2_PREFIX . 'W-$1 \\1 \\0 [0] [1] <i>x</i> "q"' . substr(uniqid(), -4);
                foreach (['biasa' => $plain, 'karakter khusus' => $weird] as $kind => $name) {
                    $P = q2_folder($t, 'X', ['perm' => 1, 'name' => $name]);
                    q2_perm($t, $P, $b, 1);
                    $Dr = q2_doc($t, 'DR');
                    $cases = [
                        'ARCHIVE408' => [function () use ($t, $s, $P, $name) { return q2_rename($t, $s, $P, $name . 'X'); },
                            'ID' => 'Anda tidak punya permission update di folder <b>%s</b>', 'EN' => 'You do not have update permission on folder <b>%s</b>'],
                        'ARCHIVE409' => [function () use ($t, $s, $P) { return q2_delete($t, $s, $P); },
                            'ID' => 'Anda tidak punya permission delete di folder <b>%s</b>', 'EN' => 'You do not have delete permission on folder <b>%s</b>'],
                        'ARCHIVE410' => [function () use ($t, $s, $P, $Dr) { return q2_putin($t, $s, $P, $Dr); },
                            'ID' => 'Anda tidak punya permission store di folder <b>%s</b>', 'EN' => 'You do not have store permission on folder <b>%s</b>'],
                    ];
                    foreach ($cases as $code => $c) {
                        foreach (['ID', 'EN'] as $lang) {
                            $r = q2_with_user($t, ['lang' => $lang], $c[0]);
                            q2_deny($t, $r, 403, $code, "$kind $code $lang", $name);
                            $t->eq($r[1]['message'], str_replace('%s', $name, $c[$lang]), "$kind $code $lang: teks pesan");
                        }
                    }
                    $t->eq((int) q2_row($t, $P)['is_active'], 1, "$kind: folder tetap aktif");
                }

                // kedua berkas bahasa memuat ARCHIVE408-411 dengan teks berbeda
                $beDir = $t->probe(function () {
                    return base_path();
                });
                foreach (['ARCHIVE408', 'ARCHIVE409', 'ARCHIVE410', 'ARCHIVE411'] as $code) {
                    $en = file_get_contents($beDir . '/app/Lib/lang/en_EN.php');
                    $id = file_get_contents($beDir . '/app/Lib/lang/id_ID.php');
                    $t->eq(preg_match_all("/'" . $code . "'\s*=>/", $en), 1, "$code di en_EN.php tepat sekali");
                    $t->eq(preg_match_all("/'" . $code . "'\s*=>/", $id), 1, "$code di id_ID.php tepat sekali");
                }
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-3',
        'title' => 'BR-13: hand-over dan receive dokumen di folder tanpa View tidak dicek hak folder (200); dokumen tetap bisa dipindah (put-in) walau folder asal menolak',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);

            try {
                $P = q2_folder($t, 'P', ['perm' => 1]);
                $D = q2_doc($t, 'D', ['parent' => $P]);
                $name = q2_row($t, $D)['name'];
                q2_deny($t, q2_open($t, $s, $P), 403, 'ARCHIVE407', 'prasyarat: P menolak B');

                $r = $t->call($s, 'POST', 'api/v5/document-archive/documents/hand-over', ['name' => $name]);
                $t->status($r, 200, 'hand-over dokumen di P');
                $t->code($r, 'ARCHIVE209', 'hand-over');
                $r = $t->call($s, 'POST', 'api/v5/document-archive/documents/receive', ['name' => $name]);
                $t->status($r, 200, 'receive dokumen di P');
                $t->code($r, 'ARCHIVE210', 'receive');
                $t->eq(q2_row($t, $D)['id_archive_parent'], $P, 'dokumen tetap di P');
                $actions = q2_history_actions($t, $D);
                $t->eq($actions, ['give', 'receive'], 'riwayat dokumen: give lalu receive');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

];
