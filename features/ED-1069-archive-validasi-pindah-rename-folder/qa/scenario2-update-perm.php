<?php
/**
 * ED-1069 AC-4 (PUT archives/{id}: induk = diri sendiri / turunan) dan AC-6 (403 dulu: cek hak sebelum validasi, ED-1025 rev).
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-4',
        'title' => 'PUT archives/{A} detail induk = A / C / turunan lain = 400 ARCHIVE404, A tetap di root; induk sekarang dikirim ulang = 200; kelompok izin saja = 200',
        'run'   => function ($t) {
            q69_recover($t);
            $s = $t->session();
            q69_purge($t);
            $base = q69_base($t);

            try {
                $A = q69_folder($t, 'A');
                $B = q69_folder($t, 'B', ['parent' => $A]);
                $C = q69_folder($t, 'C', ['parent' => $B]);
                $E = q69_folder($t, 'E', ['parent' => $C]);
                $S = q69_folder($t, 'S', ['parent' => $A]);                     // saudara B
                $NB = q69_folder($t, 'NB', ['parent' => $A, 'active' => 0]);
                $NC = q69_folder($t, 'NC', ['parent' => $NB]);                  // turunan A lewat folder nonaktif
                $nameA = q69_name_of($t, $A);
                $nameB = q69_name_of($t, $B);
                $all = [$A, $B, $C, $E, $S, $NB, $NC];
                $snap = q69_snap($t, $all);
                $f = q69_soft($t);

                $idText = 'Tidak bisa dipindahkan ke dirinya sendiri atau subfoldernya';
                foreach (['A diri sendiri' => $A, 'C anak cucu' => $C, 'B anak' => $B, 'E cicit' => $E, 'NC lewat nonaktif' => $NC] as $label => $parent) {
                    $r = q69_put($t, $s, $A, q69_body($nameA, ['id_archive_parent' => $parent]));
                    $f->resp($r, 400, 'ARCHIVE404', "PUT A induk = $label");
                    $f->eq(q69_msg($r), $idText, "PUT A induk = $label: teks");
                }
                // B (anak A): induk = C / E (turunannya) ditolak; induk = diri sendiri ditolak
                foreach (['B diri sendiri' => $B, 'C anak' => $C, 'E cucu' => $E] as $label => $parent) {
                    $r = q69_put($t, $s, $B, q69_body($nameB, ['id_archive_parent' => $parent]));
                    $f->resp($r, 400, 'ARCHIVE404', "PUT B induk = $label");
                }
                $f->ok(q69_snap($t, $all) === $snap, 'tidak ada baris berubah (A tetap di root; history tanpa entri update)');
                $f->eq(q69_parent($t, $A), null, 'A tetap di root');

                // nama ikut diganti pada request yang ditolak: nama tidak ikut berubah
                $r = q69_put($t, $s, $A, q69_body(q69_name('NEWNAME'), ['id_archive_parent' => $C, 'description' => 'x']));
                $f->resp($r, 400, 'ARCHIVE404', 'PUT A nama baru + induk = C');
                $f->ok(q69_snap($t, $all) === $snap, 'nama/description A tidak berubah pada request yang ditolak');

                // induk sekarang dikirim ulang = 200 (B di A; A di root dikirim null)
                $r = q69_put($t, $s, $B, q69_body($nameB, ['id_archive_parent' => $A, 'description' => 'kirim ulang']));
                $f->resp($r, 200, null, 'PUT B induk sekarang (A) dikirim ulang');
                $f->eq(q69_parent($t, $B), $A, 'B tetap di A');
                $f->eq(array_slice(q69_actions($t, $B), -1), ['update'], 'history B: update');
                $r = q69_put($t, $s, $A, q69_body($nameA, ['id_archive_parent' => null]));
                $f->resp($r, 200, null, 'PUT A induk null (root) tetap root');
                $r = q69_put($t, $s, $A, q69_body($nameA));
                $f->resp($r, 200, null, 'PUT A tanpa kunci id_archive_parent');

                // induk sekarang yang sudah nonaktif (data lama) dikirim ulang = 200 (hanya induk BERUBAH yang dinilai)
                q69_set($t, $A, ['is_active' => 0]);
                $r = q69_put($t, $s, $B, q69_body($nameB, ['id_archive_parent' => $A]));
                $f->resp($r, 200, null, 'PUT B kirim ulang induk lama yang nonaktif = 200');
                q69_set($t, $A, ['is_active' => 1]);

                // kelompok izin saja (tanpa kelompok detail) = 200 dan induk tidak dinilai
                $actionsA = q69_actions($t, $A);
                $r = q69_put($t, $s, $A, ['is_folder_permission' => 0]);
                $f->resp($r, 200, null, 'PUT A kelompok izin saja');
                $f->eq(q69_parent($t, $A), null, 'A tetap di root');
                $f->eq(q69_actions($t, $A), $actionsA, 'kelompok izin saja: history tidak menambah entri');

                // pindah sah ke saudara / ke atas = 200
                $r = q69_put($t, $s, $B, q69_body($nameB, ['id_archive_parent' => $S]));
                $f->resp($r, 200, null, 'PUT B induk = S (saudara)');
                $f->eq(q69_parent($t, $B), $S, 'B di S');
                $r = q69_put($t, $s, $C, q69_body(q69_name_of($t, $C), ['id_archive_parent' => $A]));
                $f->resp($r, 200, null, 'PUT C induk = A (naik ke kakek)');
                $f->eq(q69_parent($t, $C), $A, 'C di A');
                // A ke dalam cucunya yang sah sesudah pindah: A tidak punya turunan C lagi, B di S di A -> A ke B ditolak
                $r = q69_put($t, $s, $A, q69_body($nameA, ['id_archive_parent' => $B]));
                $f->resp($r, 400, 'ARCHIVE404', 'PUT A induk = B (kini turunan lewat S)');
                $r = q69_put($t, $s, $A, q69_body($nameA, ['id_archive_parent' => $E]));
                $f->resp($r, 400, 'ARCHIVE404', 'PUT A induk = E (turunan lewat C yang kini anak A)');
                $f->eq(q69_parent($t, $A), null, 'A tetap di root');

                $f->done();
            } finally {
                q69_purge($t);
            }
            q69_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-6',
        'title' => '403 dulu: A,B Off, C On + QA_USER View saja: put-in [A]->C 403 ARCHIVE410 (bukan 400), ke id acak 422, PUT A induk=C 403 ARCHIVE410; role tanpa Update Folder 403 GE0114; rename folder On tanpa Update ke nama saudara 403 ARCHIVE408',
        'run'   => function ($t) {
            q69_recover($t);
            q69_role_purge($t);
            $s = $t->session();
            q69_purge($t);
            $base = q69_base($t);
            $me = q69_uid($t);

            try {
                $A = q69_folder($t, 'A');
                $B = q69_folder($t, 'B', ['parent' => $A]);
                $C = q69_folder($t, 'C', ['parent' => $B, 'perm' => 1]);                  // On, QA_USER View saja
                $E = q69_folder($t, 'E', ['parent' => $C]);                                // anak C (Off): View lolos, Update/Store dinilai di E sendiri
                q69_perm($t, $C, $me, 1, 0, 0, 0);
                $R = q69_folder($t, 'R', ['perm' => 1]);                                   // On, View saja
                q69_perm($t, $R, $me, 1, 0, 0, 0);
                $S = q69_folder($t, 'S');                                                  // saudara R (Off)
                $R2 = q69_folder($t, 'R2');                                                // Off
                $S2 = q69_folder($t, 'S2');                                                // saudara R2 (Off)
                $nameA = q69_name_of($t, $A);
                $nameC = q69_name_of($t, $C);
                $all = [$A, $B, $C, $E, $R, $S, $R2, $S2];
                $snap = q69_snap($t, $all);
                $f = q69_soft($t);

                // 1. put-in [A]->C : A turunan-nya C, tetapi 403 menang (Store di C ditolak)
                $r = q69_putin($t, $s, $C, [$A]);
                $f->resp($r, 403, 'ARCHIVE410', 'put-in [A]->C');
                $f->eq($r[1]['parameter'] ?? null, [$nameC], 'put-in [A]->C: parameter = nama folder penolak (C)');
                $r = q69_putin($t, $s, $C, null, $nameA);
                $f->resp($r, 403, 'ARCHIVE410', 'put-in name=A->C');
                // 2. ke id acak = 422 (bukan 403/400)
                $r = q69_putin($t, $s, Q69_RANDOM_ID, [$A]);
                $f->resp($r, 422, null, 'put-in [A]->id acak');
                // 3. PUT A induk = C
                $r = q69_put($t, $s, $A, q69_body($nameA, ['id_archive_parent' => $C]));
                $f->resp($r, 403, 'ARCHIVE410', 'PUT A induk = C');
                $r = q69_put($t, $s, $A, q69_body($nameA, ['id_archive_parent' => Q69_RANDOM_ID]));
                $f->resp($r, 422, null, 'PUT A induk = id acak');
                // 4. sumber On tanpa Update (C) ke turunannya sendiri: ARCHIVE408 menang atas 400
                $r = q69_putin($t, $s, $E, [$C]);
                $f->resp($r, 403, 'ARCHIVE408', 'put-in [C]->E (turunan C, C tanpa Update)');
                // 5. rename folder On tanpa Update ke nama saudaranya: 403 ARCHIVE408 (bukan 400 ARCHIVE405)
                $r = q69_rename($t, $s, $R, q69_name_of($t, $S));
                $f->resp($r, 403, 'ARCHIVE408', 'rename R (On, View saja) -> nama S');
                // pembanding: folder Off ke nama saudaranya = 400 ARCHIVE405
                $r = q69_rename($t, $s, $R2, q69_name_of($t, $S2));
                $f->resp($r, 400, 'ARCHIVE405', 'rename R2 (Off) -> nama S2 (pembanding)');
                // rename folder On tanpa Update ke nama unik juga 403 (hak, bukan validasi)
                $r = q69_rename($t, $s, $R, q69_name('UNIK'));
                $f->resp($r, 403, 'ARCHIVE408', 'rename R (On, View saja) -> nama unik');

                $f->ok(q69_snap($t, $all) === $snap, 'semua penolakan 403/422: data tetap persis');

                // 6. role tanpa Update Folder (tetap Folder Permissions), induk = A (diri sendiri): 403 GE0114 dulu
                $roleNoUpdate = q69_role($t, 'noupd', q69_perms_no_update());
                q69_with_user($t, ['role' => $roleNoUpdate], function () use ($t, $s, $A, $B, $nameA, $f, $all, $snap) {
                    $r = q69_put($t, $s, $A, q69_body($nameA, ['id_archive_parent' => $A]));
                    $f->resp($r, 403, 'GE0114', 'role tanpa Update Folder: PUT A induk = A');
                    $f->eq($r[1]['parameter'] ?? null, 'Update Folder', 'GE0114 parameter = Update Folder');
                    $r = q69_put($t, $s, $A, q69_body($nameA, ['id_archive_parent' => Q69_RANDOM_ID]));
                    $f->resp($r, 403, 'GE0114', 'role tanpa Update Folder: PUT A induk acak = 403 (bukan 422)');
                    $r = q69_put($t, $s, $B, q69_body(q69_name_of($t, $B), ['id_archive_parent' => $B]));
                    $f->resp($r, 403, 'GE0114', 'role tanpa Update Folder: PUT B induk = B');
                    $r = q69_rename($t, $s, $B, q69_name_of($t, $A));
                    $f->resp($r, 403, 'GE0114', 'role tanpa Update Folder: rename ke nama saudara = 403 (middleware)');
                    // pembanding: kelompok izin saja lolos (role memegang Folder Permissions)
                    $r = q69_put($t, $s, $A, ['is_folder_permission' => 0]);
                    $f->resp($r, 200, null, 'role tanpa Update Folder: kelompok izin saja = 200');
                });
                q69_role_purge($t);

                // 7. pembanding: hak penuh di C -> penolakan yang sama menjadi 400 (membuktikan 403 tadi karena hak)
                $rowsBefore = $t->db()->table('archive_permissions')->where('id_archive', $C)->count();
                q69_w($t, function ($c) use ($C, $me) {
                    $c->table('archive_permissions')->where('id_archive', $C)->where('id_user', $me)->update(['is_update' => 1, 'is_store' => 1]);
                });
                $r = q69_putin($t, $s, $C, [$A]);
                $f->resp($r, 400, 'ARCHIVE404', 'dengan Store di C: put-in [A]->C = 400 ARCHIVE404');
                $r = q69_put($t, $s, $A, q69_body($nameA, ['id_archive_parent' => $C]));
                $f->resp($r, 400, 'ARCHIVE404', 'dengan Store di C: PUT A induk = C = 400 ARCHIVE404');
                $r = q69_putin($t, $s, $E, [$C]);
                $f->resp($r, 400, 'ARCHIVE404', 'dengan Update di C: put-in [C]->E = 400 ARCHIVE404');
                $f->eq($t->db()->table('archive_permissions')->where('id_archive', $C)->count(), $rowsBefore, 'jumlah baris permission C tidak berubah');
                q69_w($t, function ($c) use ($C, $me) {
                    $c->table('archive_permissions')->where('id_archive', $C)->where('id_user', $me)->update(['is_update' => 0, 'is_store' => 0]);
                });

                $f->done();
            } finally {
                q69_purge($t);
                q69_role_purge($t);
            }
            q69_assert_clean($t, $base);
        },
    ],

];
