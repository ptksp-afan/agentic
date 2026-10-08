<?php
/**
 * ED-1025 - AC-10 (superadmin 1 dan 2 lolos semua hak), AC-11 ((rev) pembuat folder; View tetap tunduk induk), AC-15 (search tetap,
 * show/history 403). Dipisah dari scenario1-schema-read.php (ukuran). User uji = QA_USER (role 3 = bukan bypass). Lihat qa_lib.php.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-10',
        'title' => 'Superadmin is_superadmin=1 dan =2 (juga role ganda 3+2) lolos semua hak di P aktif tanpa baris: buka/show/ubah/rename/Store/subfolder/hapus 200; role 3 ditolak (kontrol)',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);

            $scenario = function ($label) use ($t, $s) {
                $P = q2_folder($t, 'P', ['perm' => 1]);
                $Pdel = q2_folder($t, 'PD', ['perm' => 1]);
                $Pc = q2_folder($t, 'PC', ['perm' => 1, 'parent' => $P]);
                $D = q2_doc($t, 'D');
                $name = q2_row($t, $P)['name'];

                $r = q2_open($t, $s, $P);
                $t->status($r, 200, "$label: buka P");
                q2_access_is($t, $r[1]['result']['access'], q2_all_true(), "$label: result.access");
                $t->eq(q2_ids($r), [$Pc], "$label: isi P");
                q2_access_is($t, q2_find($r, $Pc)['access'], q2_all_true(), "$label: baris Pc");
                $r = q2_open($t, $s, $Pc);
                $t->status($r, 200, "$label: buka Pc (anak aktif)");
                $r = q2_show($t, $s, $P);
                $t->status($r, 200, "$label: show");
                q2_access_is($t, $r[1]['result']['access'], q2_all_true(), "$label: show access");
                $t->status(q2_hist($t, $s, $P), 200, "$label: history");
                $r = q2_put($t, $s, $P, q2_body($name . 'U', ['is_folder_permission' => 1]));
                $t->status($r, 200, "$label: update");
                $t->code($r, 'ARCHIVE207', "$label: update");
                $t->status(q2_rename($t, $s, $P, $name . 'R'), 200, "$label: rename");
                $r = q2_putin($t, $s, $P, $D);
                $t->status($r, 200, "$label: Store dokumen ke P");
                $t->eq(q2_row($t, $D)['id_archive_parent'], $P, "$label: dokumen di P");
                $r = q2_create($t, $s, ['name' => q2_name('SUB'), 'id_archive_parent' => $P, 'is_all_location' => 1]);
                $t->status($r, 200, "$label: subfolder di P");
                $r = q2_putin($t, $s, $Pdel, $Pc);
                $t->status($r, 200, "$label: pindahkan folder ke Pdel");
                $r = q2_putin($t, $s, null, $D);
                $t->status($r, 200, "$label: dokumen keluar ke root");
                $r = q2_delete($t, $s, $Pdel);
                $t->status($r, 200, "$label: hapus folder yang memuat subfolder aktif (tanpa baris)");
                $t->code($r, 'ARCHIVE203', "$label: hapus");
                $t->eq((int) q2_row($t, $Pdel)['is_active'], 0, "$label: Pdel nonaktif");
                $t->eq((int) q2_row($t, $Pc)['is_active'], 0, "$label: Pc ikut nonaktif");
                q2_purge($t);
            };

            try {
                // kontrol: role 3 ditolak di folder yang sama
                $Pc = q2_folder($t, 'CTRL', ['perm' => 1]);
                $r = q2_open($t, $s, $Pc);
                $t->status($r, 403, 'kontrol: role 3 ditolak');
                q2_purge($t);

                foreach ([1 => 'role 1', 2 => 'role 2'] as $role => $label) {
                    q2_with_user($t, ['role' => $role], function ($set) use ($scenario, $label) {
                        $scenario($label);
                    });
                }
                q2_with_user($t, ['role' => [3, 2]], function ($set) use ($scenario) {
                    $scenario('role 3+2');
                });
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-11',
        'title' => 'Pembuat folder (created_by) punya semua hak di folder buatannya tanpa baris; anak aktif buatan orang lain tetap menolak; induk tetap dominan; huruf besar/kecil tidak beda; folder buatan lewat API',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $me = q2_uname($t);
            $o = q2_others($t, 2);

            try {
                // P aktif dibuat B, tanpa baris
                $P = q2_folder($t, 'P', ['perm' => 1, 'by' => $me]);
                $C = q2_folder($t, 'C', ['perm' => 1, 'parent' => $P, 'by' => 'QA02']);   // anak aktif milik orang lain
                $pName = q2_row($t, $P)['name'];
                $cName = q2_row($t, $C)['name'];

                $r = q2_open($t, $s, $P);
                $t->status($r, 200, 'pembuat membuka P');
                q2_access_is($t, $r[1]['result']['access'], q2_all_true(), 'access pembuat di P');
                $r = q2_show($t, $s, $P);
                $t->status($r, 200, 'show P');
                $r = q2_put($t, $s, $P, q2_body($pName . 'U'));
                $t->status($r, 200, 'pembuat mengubah P');
                $t->eq(q2_row($t, $P)['name'], $pName . 'U', 'nama P berubah');
                $pName .= 'U';

                // anak aktif milik orang lain: ditolak di C (denied_by = C)
                $r = q2_open($t, $s, $C);
                $t->status($r, 403, 'pembuat P ditolak di C (anak aktif tanpa hak)');
                $t->eq($r[1]['result']['denied_by']['id_archive'], $C, 'denied_by = C');
                $r = q2_put($t, $s, $C, q2_body($cName . 'U'));
                q2_deny($t, $r, 403, 'ARCHIVE408', 'ubah C', $cName);
                // baris folder C di daftar P membawa access view=false
                $r = q2_open($t, $s, $P);
                q2_access_is($t, q2_find($r, $C)['access'], [0, 0, 0, 0], 'access baris C di daftar P');

                // induk tetap dominan: pembuat C tetapi P (aktif, orang lain, tanpa baris) menolak
                $P2 = q2_folder($t, 'P2', ['perm' => 1, 'by' => 'QA02']);
                $C2 = q2_folder($t, 'C2', ['perm' => 1, 'parent' => $P2, 'by' => $me]);
                $p2Name = q2_row($t, $P2)['name'];
                $r = q2_open($t, $s, $C2);
                $t->status($r, 403, 'pembuat C2 ditolak karena P2 menolak');
                $t->eq($r[1]['result']['denied_by']['id_archive'], $P2, 'denied_by = P2');
                $r = q2_rename($t, $s, $C2, q2_name('X'));
                q2_deny($t, $r, 403, 'ARCHIVE408', 'rename C2', $p2Name);

                // (rev AC-11) pembuat C (On) dengan View SAJA di P (On, milik orang lain): hak di C milik pembuat -> ubah/rename/Store 200
                // (dulu K-1 a: P menolak Update). View tetap tunduk P: tanpa View di P, C tertutup (kontrol di bawah)
                $P3 = q2_folder($t, 'P3', ['perm' => 1, 'by' => 'QA02']);
                $C3 = q2_folder($t, 'C3', ['perm' => 1, 'parent' => $P3, 'by' => $me]);
                $b = q2_uid($t);
                q2_perm($t, $P3, $b, 1);
                $c3Name = q2_row($t, $C3)['name'];
                $D3 = q2_doc($t, 'D3');
                $r = q2_open($t, $s, $C3);
                $t->status($r, 200, '(rev) pembuat C3, View saja di P3: buka C3');
                q2_access_is($t, $r[1]['result']['access'], q2_all_true(), '(rev) access pembuat di C3 (View saja di P3)');
                $r = q2_put($t, $s, $C3, q2_body($c3Name . 'U'));
                $t->status($r, 200, '(rev) pembuat C3 mengubah C3 (View saja di P3)');
                $t->code($r, 'ARCHIVE207', '(rev) ubah C3');
                $t->eq(q2_row($t, $C3)['name'], $c3Name . 'U', '(rev) nama C3 berubah');
                $t->status(q2_rename($t, $s, $C3, $c3Name . 'R'), 200, '(rev) rename C3');
                $t->status(q2_putin($t, $s, $C3, $D3), 200, '(rev) Store dokumen ke C3');
                $t->eq(q2_row($t, $D3)['id_archive_parent'], $C3, '(rev) dokumen kini di C3');
                q2_deny($t, q2_rename($t, $s, $P3, q2_name('X')), 403, 'ARCHIVE408', '(rev) P3 sendiri: View saja, rename ditolak', q2_row($t, $P3)['name']);
                q2_unperm($t, $P3, $b);
                $r = q2_open($t, $s, $C3);
                $t->status($r, 403, '(rev) tanpa View di P3: C3 tertutup walau pembuat');
                $t->eq($r[1]['result']['denied_by']['id_archive'], $P3, '(rev) denied_by = P3');

                // huruf besar/kecil tidak beda (collation DB)
                q2_set_archive($t, $P2, ['created_by' => strtoupper($me)]);
                q2_set_archive($t, $C2, ['is_folder_permission' => 0]);
                $r = q2_open($t, $s, $C2);
                $t->status($r, 200, 'created_by huruf besar tetap dikenali sebagai pembuat');

                // folder dibuat lewat API oleh B, lalu permission diaktifkan oleh pembuat -> tetap boleh
                $name = q2_name('API');
                $r = q2_create($t, $s, ['name' => $name, 'is_all_location' => 1]);
                $t->status($r, 200, 'create-folder API');
                $A = $t->db()->table('archives')->where('name', $name)->value('id_archive');
                $r = q2_put($t, $s, $A, q2_body($name, ['is_folder_permission' => 1, 'folder_permissions' => [
                    ['id_user' => $o[0][0], 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0],
                ]]));
                $t->status($r, 200, 'pembuat mengaktifkan permission folder buatannya');
                $t->eq((int) q2_row($t, $A)['is_folder_permission'], 1, 'aktif');
                $r = q2_open($t, $s, $A);
                $t->status($r, 200, 'pembuat tetap bisa membuka sesudah aktif (tanpa barisnya sendiri)');
                q2_access_is($t, $r[1]['result']['access'], q2_all_true(), 'access pembuat');
                $r = q2_delete($t, $s, $A);
                $t->status($r, 200, 'pembuat menghapus folder buatannya');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-15',
        'title' => 'B tanpa View di P: search nomor dokumen di P tetap menemukannya; baris folder hasil search access.view=false; show/history P 403 ARCHIVE407; show/history dokumen 200; search+id_archive tidak ditolak',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);

            try {
                $P = q2_folder($t, 'P', ['perm' => 1]);
                $C = q2_folder($t, 'C', ['perm' => 1, 'parent' => $P]);
                $D = q2_doc($t, 'DOC', ['parent' => $P]);
                $Dc = q2_doc($t, 'DOC', ['parent' => $C]);
                $pName = q2_row($t, $P)['name'];
                $dName = q2_row($t, $D)['name'];
                $dcName = q2_row($t, $Dc)['name'];

                // search dokumen (lintas folder)
                $r = q2_list($t, $s, ['search' => json_encode(['query' => $dName]), 'pagination' => 50]);
                $t->status($r, 200, 'search dokumen di P');
                $row = q2_find($r, $D);
                $t->true($row !== null, 'dokumen di P (B tanpa View) muncul di pencarian');
                $t->eq($row['access'], null, 'baris dokumen: access null');
                $t->eq($row['is_folder_permission'], 0, 'baris dokumen: is_folder_permission 0');
                $t->eq($row['name'][0]['transaction_no'], $dName, 'nomor dokumen');
                $t->eq($r[1]['result']['access'], null, 'result.access null saat mencari');
                $r = q2_list($t, $s, ['search' => json_encode(['query' => $dcName]), 'pagination' => 50]);
                $t->true(q2_find($r, $Dc) !== null, 'dokumen di C (folder dalam P) juga muncul');

                // search folder: baris folder membawa access.view=false & is_folder_permission=1
                $r = q2_list($t, $s, ['search' => json_encode(['query' => $pName]), 'pagination' => 50]);
                $t->status($r, 200, 'search folder');
                $row = q2_find($r, $P);
                $t->true($row !== null, 'folder P (tanpa View) muncul di pencarian');
                q2_access_is($t, $row['access'], [0, 0, 0, 0], 'baris folder P hasil search');
                $t->eq($row['is_folder_permission'], 1, 'baris folder P: is_folder_permission 1');

                // search + id_archive: bukan membuka folder -> 200
                $r = q2_list($t, $s, ['id_archive' => $P, 'search' => json_encode(['query' => $dName]), 'pagination' => 50]);
                $t->status($r, 200, 'search dengan id_archive=P');
                $t->true(q2_find($r, $D) !== null, 'dokumen tetap muncul');
                $t->eq($r[1]['result']['access'], null, 'result.access null saat mencari');
                // hanya showRelatedTransaction bukan pencarian: tetap membuka folder -> 403 bentuk formatResponse
                $r = q2_list($t, $s, ['id_archive' => $P, 'search' => json_encode(['showRelatedTransaction' => true])]);
                $t->status($r, 403, 'showRelatedTransaction saja = membuka folder');
                $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE407', 'msg_code');

                // show / history
                q2_deny($t, q2_show($t, $s, $P), 403, 'ARCHIVE407', 'show P', $pName);
                q2_deny($t, q2_hist($t, $s, $P), 403, 'ARCHIVE407', 'history P', $pName);
                $r = q2_show($t, $s, $D);
                $t->status($r, 200, 'show dokumen');
                $r = q2_hist($t, $s, $D);
                $t->status($r, 200, 'history dokumen (tidak dicek hak folder)');
                $t->has($r[1], 'result.data', 'history dokumen: result.data');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

];
