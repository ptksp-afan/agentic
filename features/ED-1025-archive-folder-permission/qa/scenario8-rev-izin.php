<?php
/**
 * ED-1025 (revisi review 2026-10-08) - permission Folder Permissions (K-15 b): AC-29 (manage_permission + field izin tanpa
 * permission), AC-38 (PUT dua kelompok: detail = Update Folder, izin = Folder Permissions). AC-25 (seed) dan X-16 (fuzz): scenario9.
 *
 * Peran uji (role fixture berawalan QA02-ROLE-, dibuang di finally; role bawaan/pengembang tidak disentuh):
 *   A = role 3 (QA_USER; ber-List Archive, Update/Delete Folder, Add Folder, Add Document, Move Archive, View Folder, Folder Permissions)
 *   C = A tanpa Folder Permissions, D = List Archive + View Folder + Folder Permissions saja,
 *   E = List Archive + Update Folder saja, F = Folder Permissions saja.
 * Pelaku selalu QA_USER (satu-satunya yang bisa login); peran ditukar lewat q2_with_user ($set; JANGAN bersarang: q2_with_user
 * memulihkan jurnal user di awal). Lihat qa_lib.php.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-29',
        'title' => '(rev K-15 b) C (Update efektif di F, tanpa Folder Permissions): show F -> manage_permission false, folder_permissions [], PUT field izin (nilai apa pun) 403 GE0114 "Folder Permissions" dan data tetap; PUT tanpa field itu 200 (riwayat hanya update); A -> manage_permission true, baris tampil',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            q2_role_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);
            $o = q2_others($t, 2);

            try {
                $roleC = q2_role($t, 'C', q2_perms_c());
                $F = q2_folder($t, 'F', ['perm' => 1]);
                $fName = q2_name_of($t, $F);
                q2_perm($t, $F, $b, 1, 1, 0, 0);                         // Update efektif di F
                q2_perm($t, $F, $o[0][0], 1, 0, 0, 1);
                $rowsBefore = q2_perm_map($t, $F);

                // A: baris tampil, manage_permission true
                $r = q2_show($t, $s, $F);
                $t->status($r, 200, 'A: show F');
                $t->eq(count($r[1]['result']['folder_permissions']), 2, 'A: 2 baris tampil');
                q2_access_is($t, $r[1]['result']['access'], [1, 1, 0, 0], 'A: access (Update efektif)', true);

                q2_with_user($t, ['role' => $roleC], function ($set) use ($t, $s, $F, $fName, $o, $rowsBefore, $roleC) {
                    $r = q2_show($t, $s, $F);
                    $t->status($r, 200, 'C: show F');
                    $t->eq($r[1]['result']['folder_permissions'], [], 'C: folder_permissions = []');
                    $t->eq($r[1]['result']['is_folder_permission'], 1, 'C: is_folder_permission tetap dikirim');
                    q2_access_is($t, $r[1]['result']['access'], [1, 1, 0, 0], 'C: access (Update efektif, manage false)', false);
                    $r = q2_list($t, $s, ['search' => json_encode(['query' => $fName]), 'pagination' => 20]);
                    q2_access_is($t, q2_find($r, $F)['access'], [1, 1, 0, 0], 'C: baris F di pencarian', false);

                    $before = q2_snap($t, [$F]);
                    $groups = [
                        'is_folder_permission = 0'    => ['is_folder_permission' => 0],
                        'is_folder_permission = 1'    => ['is_folder_permission' => 1],
                        'is_folder_permission null'   => ['is_folder_permission' => null],
                        'folder_permissions baris'    => ['folder_permissions' => [q2_perm_row($o[1][0])]],
                        'folder_permissions []'       => ['folder_permissions' => []],
                        'folder_permissions null'     => ['folder_permissions' => null],
                        'kedua field'                 => ['is_folder_permission' => 1, 'folder_permissions' => [q2_perm_row($o[1][0])]],
                        'field izin tak valid (tipe)' => ['folder_permissions' => 'x'],
                    ];
                    foreach ($groups as $label => $extra) {
                        // bersama kelompok detail (name) dan tanpa kelompok detail: 403 mendahului validasi
                        foreach ([q2_body($fName . 'X', $extra), $extra] as $i => $body) {
                            $r = q2_put($t, $s, $F, $body);
                            q2_forbid_perm($t, $r, 'Folder Permissions', "C: PUT $label #$i");
                            $t->true(q2_same($before, q2_snap($t, [$F])), "C: PUT $label #$i: data tidak berubah");
                        }
                    }
                    $t->eq(json_encode(q2_perm_map($t, $F)), json_encode($rowsBefore), 'C: baris izin tetap');

                    // pesan GE0114 memuat nama permission (ID dan EN)
                    $set(['role' => $roleC, 'lang' => 'ID']);
                    $r = q2_put($t, $s, $F, ['is_folder_permission' => 0]);
                    $t->eq($r[1]['message'] ?? null, 'Anda tidak memiliki izin untuk Folder Permissions!', 'pesan ID GE0114');
                    $set(['role' => $roleC, 'lang' => 'EN']);
                    $r = q2_put($t, $s, $F, ['is_folder_permission' => 0]);
                    $t->true(strpos((string) ($r[1]['message'] ?? ''), 'Folder Permissions') !== false, 'pesan EN GE0114 memuat nama permission: ' . ($r[1]['message'] ?? ''));
                    $set(['role' => $roleC]);

                    // tanpa field izin -> 200 (nama berubah; riwayat hanya update; baris dan toggle tetap)
                    $r = q2_put($t, $s, $F, q2_body($fName . 'U'));
                    $t->status($r, 200, 'C: PUT tanpa field izin');
                    $t->code($r, 'ARCHIVE207', 'C: PUT tanpa field izin');
                    $t->eq(q2_name_of($t, $F), $fName . 'U', 'C: nama berubah');
                    $t->eq(q2_history_actions($t, $F), ['update'], 'C: riwayat hanya update');
                    $t->eq(json_encode(q2_perm_map($t, $F)), json_encode($rowsBefore), 'C: baris izin tetap');
                    $t->eq((int) q2_row($t, $F)['is_folder_permission'], 1, 'C: toggle tetap');
                });
            } finally {
                q2_purge($t);
                q2_role_purge($t);
            }
            q2_assert_clean($t, $base);
            $t->eq($t->db()->table('roles')->where('role_name', 'like', Q2_ROLE_PREFIX . '%')->count(), 0, 'role fixture dibuang');
        },
    ],

    [
        'id'    => 'AC-38',
        'title' => '(rev K-15 b) PUT dua kelompok: D (tanpa Update Folder) izin saja 200, riwayat hanya permission, + detail 403 GE0114 "Update Folder" dan data tetap; A izin saja ke JB (tanpa View) dan J 200 (GET JB tetap 403 ARCHIVE407), detail ke J 403 ARCHIVE408; di luar scope lokasi 403 ARCHIVE407; tanpa kedua kelompok 422; 404; urutan 403 -> 422 -> 400 ARCHIVE411; tanpa perubahan = tanpa tulisan; E/F/superadmin',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            q2_role_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);
            $me = q2_uname($t);
            $o = q2_others($t, 3);

            try {
                $roleD = q2_role($t, 'D', q2_perms_d());
                $roleE = q2_role($t, 'E', ['List Archive', 'Update Folder']);
                $roleF = q2_role($t, 'F', ['Folder Permissions']);

                // ================= D: List Archive + View Folder + Folder Permissions saja
                $F = q2_folder($t, 'F', ['perm' => 1]);
                $fName = q2_name_of($t, $F);
                q2_perm($t, $F, $b, 1);                                       // D hanya View di F (pelaku = QA_USER)
                q2_perm($t, $F, $o[2][0], 1, 1, 0, 0);                        // baris lama (akan diganti)
                $t->eq(q2_history_actions($t, $F), [], 'prasyarat: riwayat F kosong');
                $JB = q2_folder($t, 'JB', ['perm' => 1]);                     // D tanpa View
                $jbName = q2_name_of($t, $JB);
                q2_perm($t, $JB, $o[2][0], 1);

                q2_with_user($t, ['role' => $roleD], function ($set) use ($t, $s, $F, $fName, $JB, $b, $o, $me) {
                    $rows = [q2_perm_row($b), q2_perm_row($o[0][0], 1, 1, 0, 0), q2_perm_row($o[1][0], 0, 0, 0, 0)];

                    // 1. izin saja -> 200; baris diganti persis; riwayat hanya permission
                    $r = q2_put($t, $s, $F, ['is_folder_permission' => 1, 'folder_permissions' => $rows]);
                    $t->status($r, 200, 'D: PUT izin saja');
                    $t->code($r, 'ARCHIVE207', 'D: PUT izin saja');
                    $t->eq($r[1]['result']['id_archive'], $F, 'D: result.id_archive');
                    $t->eq(json_encode(q2_perm_map($t, $F)), json_encode(q2_perm_expect([$b => [1, 0, 0, 0], $o[0][0] => [1, 1, 0, 0]])),
                        'D: baris diganti persis (baris lama hilang, baris semua-0 tidak disimpan)');
                    $t->eq(q2_history_actions($t, $F), ['permission'], 'D: riwayat HANYA permission (tanpa update)');
                    $h = q2_history($t, $F);
                    $t->eq($h[0]['related_username'] ?? null, $me, 'D: riwayat permission oleh pelaku');
                    $t->eq(q2_row($t, $F)['name'], $fName, 'D: nama tidak berubah');
                    $t->eq(q2_row($t, $F)['updated_by'], $me, 'D: updated_by = pelaku (ada perubahan)');

                    // 2. isi sama lagi -> 200 tanpa tulisan apa pun (baris archives, baris izin, riwayat)
                    $snap = q2_snap($t, [$F]);
                    $r = q2_put($t, $s, $F, ['is_folder_permission' => 1, 'folder_permissions' => $rows]);
                    $t->status($r, 200, 'D: izin sama lagi');
                    $t->true(q2_same($snap, q2_snap($t, [$F])), 'D: tanpa perubahan = tanpa tulisan (archives, izin, riwayat, updated_at)');
                    $t->eq(q2_history_actions($t, $F), ['permission'], 'D: riwayat tetap satu');

                    // 3. kelompok detail -> 403 GE0114 "Update Folder"; data tetap
                    $detail = [
                        'name + izin'          => q2_body($fName . 'X', ['is_folder_permission' => 0, 'folder_permissions' => []]),
                        'name saja'            => q2_body($fName . 'X'),
                        'description saja'     => ['description' => 'x'],
                        'id_archive_parent'    => ['id_archive_parent' => null],
                        'id_locations kosong'  => ['id_locations' => []],
                    ];
                    foreach ($detail as $label => $body) {
                        $r = q2_put($t, $s, $F, $body);
                        q2_forbid_perm($t, $r, 'Update Folder', "D: PUT $label");
                        $t->true(q2_same($snap, q2_snap($t, [$F])), "D: PUT $label: data tidak berubah");
                    }
                    q2_forbid_perm($t, q2_rename($t, $s, $F, $fName . 'X'), 'Update Folder', 'D: rename (route Update Folder)');

                    // 4. urutan: 403 -> 422 -> 400 ARCHIVE411; semuanya tanpa perubahan
                    $r = q2_put($t, $s, $F, ['folder_permissions' => [q2_perm_row($b, 0, 1, 0, 0)]]);
                    q2_deny($t, $r, 400, 'ARCHIVE411', 'D: baris U tanpa View');
                    $t->true(q2_same($snap, q2_snap($t, [$F])), 'D: ARCHIVE411 tanpa perubahan');
                    foreach ([
                        'id_user ganda'   => ['folder_permissions' => [q2_perm_row($b), q2_perm_row($b, 1, 1)]],
                        'id_user tak ada' => ['folder_permissions' => [q2_perm_row('QA02-TIDAK-ADA')]],
                        'tipe salah'      => ['folder_permissions' => 'x'],
                        'toggle = 5'      => ['is_folder_permission' => 5],
                    ] as $label => $body) {
                        $r = q2_put($t, $s, $F, $body);
                        $t->status($r, 422, "D: $label");
                        $t->true(q2_same($snap, q2_snap($t, [$F])), "D: $label: tanpa perubahan");
                    }
                    // 422 mendahului ARCHIVE411 (validasi sebelum service)
                    $r = q2_put($t, $s, $F, ['folder_permissions' => [q2_perm_row($b, 0, 1, 0, 0), q2_perm_row('QA02-TIDAK-ADA')]]);
                    $t->status($r, 422, 'D: 422 mendahului 400 ARCHIVE411');

                    // 5. tanpa kedua kelompok -> 422 (hak folder tidak dinilai)
                    $r = $t->call($s, 'PUT', 'api/v5/document-archive/archives/' . $F, []);
                    $t->status($r, 422, 'D: body kosong');
                    $r = q2_put($t, $s, $F, ['foo' => 'bar']);
                    $t->status($r, 422, 'D: body tanpa kelompok mana pun');
                    $t->true(q2_same($snap, q2_snap($t, [$F])), 'D: body kosong tanpa perubahan');

                    // 6. toggle saja (nyala/mati) dicatat permission; baris tetap
                    $r = q2_put($t, $s, $F, ['is_folder_permission' => 0]);
                    $t->status($r, 200, 'D: toggle mati');
                    $t->eq((int) q2_row($t, $F)['is_folder_permission'], 0, 'D: toggle 0');
                    $t->eq(count(q2_perm_map($t, $F)), 2, 'D: baris tetap');
                    $t->eq(q2_history_actions($t, $F), ['permission', 'permission'], 'D: toggle dicatat permission');
                    $r = q2_put($t, $s, $F, ['is_folder_permission' => 1]);
                    $t->status($r, 200, 'D: toggle nyala lagi');

                    // 7. folder yang tidak bisa D View: izin tetap bisa diatur (drawer tidak, PUT ya); folder tetap tertutup
                    q2_deny($t, q2_show($t, $s, $JB), 403, 'ARCHIVE407', 'D: show JB (tanpa View)', q2_name_of($t, $JB));
                    $r = q2_put($t, $s, $JB, ['is_folder_permission' => 1, 'folder_permissions' => [q2_perm_row($o[0][0], 1, 0, 0, 1)]]);
                    $t->status($r, 200, 'D: PUT izin ke JB (tanpa View)');
                    $t->eq(json_encode(q2_perm_map($t, $JB)), json_encode(q2_perm_expect([$o[0][0] => [1, 0, 0, 1]])), 'D: baris JB diganti');
                    $t->eq(q2_history_actions($t, $JB), ['permission'], 'D: riwayat JB hanya permission');
                    q2_deny($t, q2_show($t, $s, $JB), 403, 'ARCHIVE407', 'D: show JB tetap 403', q2_name_of($t, $JB));
                    $r = q2_open($t, $s, $JB);
                    $t->status($r, 403, 'D: buka JB tetap 403');
                    $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE407', 'D: buka JB msg_code');
                });

                // ================= A (role 3): J (View saja), JB (tanpa baris), H (di luar scope lokasi)
                $J = q2_folder($t, 'J', ['perm' => 1]);
                $jName = q2_name_of($t, $J);
                q2_perm($t, $J, $b, 1);
                q2_perm($t, $J, $o[0][0], 1, 0, 0, 1);
                $JB2 = q2_folder($t, 'JB2', ['perm' => 1]);
                q2_perm($t, $JB2, $o[0][0], 1);

                $r = q2_put($t, $s, $JB2, ['is_folder_permission' => 1, 'folder_permissions' => [q2_perm_row($o[0][0], 1, 1, 1, 1), q2_perm_row($o[1][0])]]);
                $t->status($r, 200, 'A: izin saja ke JB2 (tanpa View)');
                $t->eq(json_encode(q2_perm_map($t, $JB2)), json_encode(q2_perm_expect([$o[0][0] => [1, 1, 1, 1], $o[1][0] => [1, 0, 0, 0]])), 'A: baris JB2 diganti');
                $t->eq(q2_history_actions($t, $JB2), ['permission'], 'A: riwayat JB2 hanya permission');
                q2_deny($t, q2_show($t, $s, $JB2), 403, 'ARCHIVE407', 'A: show JB2 tetap 403', q2_name_of($t, $JB2));
                $r = q2_open($t, $s, $JB2);
                $t->status($r, 403, 'A: buka JB2 tetap 403');

                $r = q2_put($t, $s, $J, ['folder_permissions' => [q2_perm_row($b), q2_perm_row($o[0][0], 1, 0, 0, 1), q2_perm_row($o[2][0], 1, 1, 0, 0)]]);
                $t->status($r, 200, 'A: izin saja ke J (View saja)');
                $t->eq(json_encode(q2_perm_map($t, $J)), json_encode(q2_perm_expect([$b => [1, 0, 0, 0], $o[0][0] => [1, 0, 0, 1], $o[2][0] => [1, 1, 0, 0]])), 'A: baris J diganti');
                $t->eq(q2_history_actions($t, $J), ['permission'], 'A: riwayat J hanya permission');
                $t->eq(q2_row($t, $J)['name'], $jName, 'A: nama J tidak berubah');
                // detail ke J (View saja) -> 403 ARCHIVE408 parameter J; kelompok izin yang menyertainya tidak ditulis
                $snapJ = q2_snap($t, [$J]);
                q2_deny($t, q2_put($t, $s, $J, q2_body($jName . 'X')), 403, 'ARCHIVE408', 'A: detail ke J', $jName);
                q2_deny($t, q2_put($t, $s, $J, q2_body($jName . 'X', ['is_folder_permission' => 0, 'folder_permissions' => []])), 403, 'ARCHIVE408', 'A: detail + izin ke J (detail dinilai lebih dulu)', $jName);
                q2_deny($t, q2_put($t, $s, $J, ['description' => 'x', 'folder_permissions' => []]), 403, 'ARCHIVE408', 'A: description + izin ke J', $jName);
                $t->true(q2_same($snapJ, q2_snap($t, [$J])), 'A: penolakan detail: kelompok izin pun tidak tertulis');
                // urutan 403 mendahului 422: tipe izin salah + detail ditolak
                q2_deny($t, q2_put($t, $s, $J, q2_body($jName . 'X', ['folder_permissions' => 'x'])), 403, 'ARCHIVE408', 'A: 403 mendahului 422', $jName);
                // 400 ARCHIVE411 untuk A (izin saja)
                q2_deny($t, q2_put($t, $s, $J, ['folder_permissions' => [q2_perm_row($b, 0, 0, 1, 0)]]), 400, 'ARCHIVE411', 'A: baris D tanpa View');
                $t->true(q2_same($snapJ, q2_snap($t, [$J])), 'A: ARCHIVE411 tanpa perubahan');

                // di luar scope lokasi: 403 ARCHIVE407 (code, bentuk lama) untuk kedua kelompok; juga induk baru di luar scope
                $H = q2_folder($t, 'H', ['perm' => 1]);
                q2_set_archive($t, $H, ['is_all_location' => 0]);
                $snapH = q2_snap($t, [$H]);
                foreach (['izin saja' => ['is_folder_permission' => 0], 'detail' => q2_body(q2_name('X')), 'keduanya' => q2_body(q2_name('X'), ['folder_permissions' => []])] as $label => $body) {
                    $r = q2_put($t, $s, $H, $body);
                    $t->status($r, 403, "A: $label ke folder di luar scope");
                    $t->eq($r[1]['code'] ?? null, 'ARCHIVE407', "A: $label di luar scope: ARCHIVE407");
                    $t->true(q2_same($snapH, q2_snap($t, [$H])), "A: $label di luar scope: data tetap");
                }
                $r = q2_put($t, $s, $J, q2_body($jName, ['id_archive_parent' => $H]));
                $t->status($r, 403, 'A: induk baru di luar scope');
                $t->eq($r[1]['code'] ?? null, 'ARCHIVE407', 'A: induk baru di luar scope: ARCHIVE407');

                // tanpa kedua kelompok -> 422; id tidak ada -> 404
                $r = $t->call($s, 'PUT', 'api/v5/document-archive/archives/' . $J, []);
                $t->status($r, 422, 'A: body kosong');
                $r = q2_put($t, $s, $J, ['foo' => 'bar']);
                $t->status($r, 422, 'A: body tanpa kelompok mana pun');
                $r = q2_put($t, $s, Q2_RANDOM_ID, ['is_folder_permission' => 0]);
                q2_deny($t, $r, 404, 'ARCHIVE400', 'A: id tidak ada');
                $t->true(q2_same($snapJ, q2_snap($t, [$J])), 'A: J tidak berubah oleh semua penolakan');

                // dua kelompok sekaligus oleh A dengan Update efektif: satu request, riwayat update lalu permission
                $G = q2_folder($t, 'G', ['perm' => 1]);
                $gName = q2_name_of($t, $G);
                q2_perm($t, $G, $b, 1, 1, 0, 0);
                $r = q2_put($t, $s, $G, q2_body($gName . 'U', ['is_folder_permission' => 1, 'folder_permissions' => [q2_perm_row($b, 1, 1, 0, 0), q2_perm_row($o[0][0])]]));
                $t->status($r, 200, 'A: dua kelompok sekaligus (Update efektif)');
                $t->eq(q2_name_of($t, $G), $gName . 'U', 'A: detail tersimpan');
                $t->eq(count(q2_perm_map($t, $G)), 2, 'A: izin tersimpan');
                $t->eq(q2_history_actions($t, $G), ['update', 'permission'], 'A: riwayat update lalu permission');

                // kelompok detail dikirim tidak lengkap (Update efektif) -> aturan lama: name + is_all_location wajib = 422 (bukan 200 diam-diam)
                $snapG = q2_snap($t, [$G]);
                foreach (['description saja' => ['description' => 'x'], 'id_archive_parent saja' => ['id_archive_parent' => null], 'id_locations saja' => ['id_locations' => []]] as $label => $body) {
                    $r = q2_put($t, $s, $G, $body);
                    $t->status($r, 422, "A: detail tidak lengkap ($label)");
                    $t->true(isset($r[1]['errors']['name']) && isset($r[1]['errors']['is_all_location']), "A: $label: errors name + is_all_location");
                }
                $t->true(q2_same($snapG, q2_snap($t, [$G])), 'A: detail tidak lengkap: data tetap');

                // urutan kontrak 3: ARCHIVE410 (induk baru tanpa Store, kelompok detail) mendahului GE0114 kelompok izin
                $Y = q2_folder($t, 'Y', ['perm' => 1]);
                q2_perm($t, $Y, $b, 1, 1, 0, 0);
                $yName = q2_name_of($t, $Y);

                // ================= E: List Archive + Update Folder (tanpa Folder Permissions), Update efektif di G
                q2_with_user($t, ['role' => $roleE], function ($set) use ($t, $s, $G, $gName, $Y, $yName) {
                    $snapY = q2_snap($t, [$G]);
                    q2_deny($t, q2_put($t, $s, $G, q2_body($gName, ['id_archive_parent' => $Y, 'folder_permissions' => []])), 403, 'ARCHIVE410', 'E: ARCHIVE410 mendahului GE0114 kelompok izin', $yName);
                    $t->true(q2_same($snapY, q2_snap($t, [$G])), 'E: urutan 410 -> GE0114: data tetap');
                    $snap = q2_snap($t, [$G]);
                    $r = q2_put($t, $s, $G, q2_body($gName . 'E'));
                    $t->status($r, 200, 'E: detail saja');
                    $t->eq(q2_name_of($t, $G), $gName . 'E', 'E: nama berubah');
                    $snap = q2_snap($t, [$G]);
                    q2_forbid_perm($t, q2_put($t, $s, $G, ['is_folder_permission' => 0]), 'Folder Permissions', 'E: izin saja');
                    // atomik: detail yang lolos tidak ikut tertulis bila kelompok izin ditolak
                    q2_forbid_perm($t, q2_put($t, $s, $G, q2_body($gName . 'Z', ['folder_permissions' => []])), 'Folder Permissions', 'E: detail + izin');
                    $t->true(q2_same($snap, q2_snap($t, [$G])), 'E: detail + izin ditolak: nama pun tidak berubah (atomik)');
                });

                // ================= F: hanya Folder Permissions (tanpa List Archive/Update Folder)
                q2_with_user($t, ['role' => $roleF], function ($set) use ($t, $s, $G, $gName, $b, $o) {
                    $r = q2_put($t, $s, $G, ['folder_permissions' => [q2_perm_row($b, 1, 1, 0, 0)]]);
                    $t->status($r, 200, 'F: izin saja (hanya Folder Permissions)');
                    $t->eq(json_encode(q2_perm_map($t, $G)), json_encode(q2_perm_expect([$b => [1, 1, 0, 0]])), 'F: baris diganti');
                    q2_forbid_perm($t, q2_put($t, $s, $G, q2_body($gName . 'F')), 'Update Folder', 'F: detail');
                    q2_forbid_perm($t, q2_show($t, $s, $G), 'List Archive', 'F: show (route List Archive)');
                });

                // ================= superadmin 1 dan 2 (bypass hak folder; ber-Folder Permissions): izin saja ke folder tertutup
                foreach ([1, 2] as $role) {
                    q2_with_user($t, ['role' => $role], function ($set) use ($t, $s, $JB2, $o, $role) {
                        $r = q2_put($t, $s, $JB2, ['folder_permissions' => [q2_perm_row($o[1][0])]]);
                        $t->status($r, 200, "role $role: izin saja");
                        $r = q2_show($t, $s, $JB2);
                        $t->status($r, 200, "role $role: show");
                        q2_access_is($t, $r[1]['result']['access'], q2_all_true(), "role $role: access");
                        $t->eq(count($r[1]['result']['folder_permissions']), 1, "role $role: baris tampil");
                    });
                }
            } finally {
                q2_purge($t);
                q2_role_purge($t);
            }
            q2_assert_clean($t, $base);
            $t->eq($t->db()->table('roles')->where('role_name', 'like', Q2_ROLE_PREFIX . '%')->count(), 0, 'role fixture dibuang');
        },
    ],

];
