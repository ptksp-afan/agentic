<?php
/**
 * ED-1029 rev3 (ED-1128 BE, ED-1130 QA; spec BR-22..24, K-9 a, K-10 b, EPIC K-5 b) - PUT user-permissions/{id} + `folders`:
 *   AC-32  nyalakan Izin (tersimpan Off -> On) untuk user lain: flag + updated_by + riwayat; hak otomatis pengaktif (user login) 1/1/1/1
 *          lewat saveUserRow: baris yang ada DINAIKKAN (tidak pernah baris kedua), juga pembuat dan superadmin 1/2; riwayat sekali
 *   AC-33  target = user login: BE tidak menambah baris apa pun
 *   AC-34  mati (K-10 b: hak ikut tersimpan), 441 hanya untuk folder yang TETAP Off, nilai sama = tanpa tulisan
 *   AC-19  (sisi BE) folder dimatikan lewat tab Izin (PUT archives/{id}) sesudah halaman dibuka -> Save hak = 441 atomik
 *   AC-36  penegakan nyata sesudah AC-32 (QA_USER2 sebagai user tanpa baris / dengan baris), tab Izin <-> halaman by User
 *
 * Pemanggil A = QA_USER dengan role fixture A (QA29-ROLE-A: List Archive, View Folder, Update Folder, Folder Permissions) dan lokasi
 * SMR (non-bypass); superadmin = role 1 / 2. Semua data folder berawalan QA29- (q9_purge), role QA29-ROLE-* (q9_role_purge).
 */
require_once __DIR__ . '/qa_lib_r3.php';

return [

    [
        'id'    => 'AC-32',
        'title' => '(rev3) A PUT {B} folders [F:1] + permissions [F:View] -> 200 ARCHIVE207; F flag 1, updated_by = A; (F,B) View; [K-9 a] (F,A) 1/1/1/1; riwayat F +1 permission oleh A. folders [G:1] + permissions [] -> (G,A) View DINAIKKAN 1/1/1/1 (baris yang sama, satu baris, tanpa (G,B)); pembuat (X) dan baris A yang sudah 1/1/1/1 (Y); beberapa folder sekaligus; Off->On->Off->On: tetap satu baris; superadmin 1 dan 2: folders [H:1] -> (H, superadmin) 1/1/1/1',
        'run'   => function ($t) {
            q9_recover($t);
            q9_role_purge($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b] = q9_others($t, 1);

            try {
                $roles = q9_roles_profile($t);
                $f = r3_fixture($t, $me, $meName, $b[0]);
                $ids = array_values($f);
                q9_codes_reset();

                q9_with_user($t, r3_ctx_a($roles), function ($set) use ($t, $s, $me, $meName, $b, $f, $ids) {
                    $F = $f['F'];
                    $G = $f['G'];

                    // 1. F (Off, tanpa baris A/B): flag + hak B + hak otomatis A, riwayat sekali
                    $others = array_values(array_diff($ids, [$F]));
                    $snapOthers = q9_snap($t, $others);
                    $h0 = q9_hist_count($t, $F);
                    $t->eq(r3_flag($t, $F), 0, 'awal: F Off');
                    $t->eq(r3_total_rows($t, $F), 0, 'awal: F tanpa baris');
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($F, 1)], [r3_fl($F, 1)]));
                    r3_ok($t, $r, $b[0], '1. PUT {B} folders [F:1] + [F:View]');
                    $t->eq(r3_flag($t, $F), 1, '1. F is_folder_permission = 1');
                    $t->eq(q9_row($t, $F)['updated_by'], $meName, '1. F updated_by = A');
                    $t->eq(r3_vec($t, $F, $b[0]), '[1,0,0,0]', '1. (F,B) = View');
                    $t->eq(r3_vec($t, $F, $me), '[1,1,1,1]', '1. (F,A) = 1/1/1/1 (hak otomatis pengaktif)');
                    $t->eq(r3_row_count($t, $F, $me), 1, '1. tepat satu baris (F,A)');
                    $t->eq(r3_total_rows($t, $F), 2, '1. F hanya berisi (F,A) dan (F,B)');
                    $auto = r3_row_full($t, $F, $me);
                    $t->eq($auto['created_by'], $meName, '1. baris otomatis: created_by = A');
                    $t->eq($auto['updated_by'], $meName, '1. baris otomatis: updated_by = A');
                    $t->eq(q9_hist_count($t, $F), $h0 + 1, '1. riwayat F +1 (flag + baris otomatis + baris B = SATU entri)');
                    $h = r3_last_hist($t, $F);
                    $t->eq($h['action'] ?? null, 'permission', '1. riwayat: action permission');
                    $t->eq($h['related_username'] ?? null, $meName, '1. riwayat: oleh A');
                    $t->true(q9_same($snapOthers, q9_snap($t, $others)), '1. folder lain dan user lain tidak tersentuh');
                    $node = r3_node($t, $s, $b[0], $F);
                    $t->eq($node['is_folder_permission'], 1, '1. GET {B}: F On');
                    $t->eq(q9_node_vals($node), '[1,0,0,0]', '1. GET {B}: nilai B di F');
                    $nodeA = r3_node($t, $s, $me, $F);
                    $t->eq(q9_node_vals($nodeA), '[1,1,1,1]', '1. GET {A}: nilai A di F = hak otomatis');

                    // 1b. kirim ulang kiriman yang sama: F sudah On -> tanpa tulisan, tanpa riwayat, tanpa baris otomatis lagi
                    $snapF = q9_snap($t, [$F]);
                    $permTotal = q9_perm_total($t);
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($F, 1)], [r3_fl($F, 1)]));
                    r3_ok($t, $r, $b[0], '1b. ulang kiriman sama');
                    $t->true(q9_same($snapF, q9_snap($t, [$F])), '1b. F tidak berubah sama sekali (flag, riwayat, baris)');
                    $t->eq(q9_perm_total($t), $permTotal, '1b. jumlah baris izin tetap');

                    // 2. G (Off, A sudah punya baris View): baris itu DINAIKKAN (id baris sama), tanpa baris kedua, tanpa baris B
                    $gRowId = r3_row_id($t, $G, $me);
                    $t->eq(r3_vec($t, $G, $me), '[1,0,0,0]', '2. awal: (G,A) View saja');
                    $h0 = q9_hist_count($t, $G);
                    $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($G, 1)]));
                    r3_ok($t, $r, $b[0], '2. PUT {B} folders [G:1] + permissions []');
                    $t->eq(r3_flag($t, $G), 1, '2. G flag 1');
                    $t->eq(r3_vec($t, $G, $me), '[1,1,1,1]', '2. (G,A) dinaikkan ke 1/1/1/1');
                    $t->eq(r3_row_count($t, $G, $me), 1, '2. tetap SATU baris (G,A)');
                    $t->eq(r3_row_id($t, $G, $me), $gRowId, '2. baris yang sama dinaikkan (id_archive_permission tetap), bukan dibuat ulang');
                    $t->eq(r3_row_count($t, $G, $b[0]), 0, '2. tanpa baris (G,B)');
                    $t->eq(r3_total_rows($t, $G), 1, '2. G hanya berisi baris A');
                    $t->eq(q9_hist_count($t, $G), $h0 + 1, '2. riwayat G +1');

                    // 3. X: A pembuat folder, tanpa baris -> tetap mendapat 1/1/1/1 (pembuat); Y: A sudah 1/1/1/1 -> baris tidak berubah
                    $h0 = q9_hist_count($t, $f['X']);
                    $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($f['X'], 1)]));
                    r3_ok($t, $r, $b[0], '3. PUT {B} folders [X:1] (A pembuat)');
                    $t->eq(r3_vec($t, $f['X'], $me), '[1,1,1,1]', '3. pembuat: (X,A) 1/1/1/1');
                    $t->eq(r3_total_rows($t, $f['X']), 1, '3. X hanya berisi baris A');
                    $t->eq(q9_hist_count($t, $f['X']), $h0 + 1, '3. riwayat X +1');

                    $Y = $f['Y'];
                    $yRow = r3_row_full($t, $Y, $me);
                    $h0 = q9_hist_count($t, $Y);
                    $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($Y, 1)]));
                    r3_ok($t, $r, $b[0], '3b. PUT {B} folders [Y:1] (A sudah 1/1/1/1)');
                    $t->eq(r3_flag($t, $Y), 1, '3b. Y flag 1');
                    $t->eq(json_encode(r3_row_full($t, $Y, $me)), json_encode($yRow), '3b. baris (Y,A) tidak ditulis ulang (sama persis, termasuk updated_at)');
                    $t->eq(q9_hist_count($t, $Y), $h0 + 1, '3b. riwayat Y +1 (flag berubah)');

                    // 4. beberapa folder sekaligus (K: tanpa hak untuk B; Z: hak B 0000 = tanpa baris; H: V+U)
                    $H = $f['H'];
                    $K = $f['K'];
                    $Z = $f['Z'];
                    $hk = [$H => q9_hist_count($t, $H), $K => q9_hist_count($t, $K), $Z => q9_hist_count($t, $Z)];
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($H, 1, 1), q9_row_body($Z, 0, 0, 0, 0)], [r3_fl($H, 1), r3_fl($K, 1), r3_fl($Z, 1)]));
                    r3_ok($t, $r, $b[0], '4. PUT {B} folders [H, K, Z] + permissions [H:V+U, Z:0000]');
                    foreach ([$H, $K, $Z] as $x) {
                        $t->eq(r3_flag($t, $x), 1, '4. flag 1 pada ' . $x);
                        $t->eq(r3_vec($t, $x, $me), '[1,1,1,1]', '4. (x,A) otomatis 1/1/1/1');
                        $t->eq(r3_row_count($t, $x, $me), 1, '4. satu baris A');
                        $t->eq(q9_hist_count($t, $x), $hk[$x] + 1, '4. riwayat +1 tepat sekali');
                    }
                    $t->eq(r3_vec($t, $H, $b[0]), '[1,1,0,0]', '4. (H,B) = V+U');
                    $t->eq(r3_vec($t, $K, $b[0]), 'none', '4. (K,B) tanpa baris');
                    $t->eq(r3_vec($t, $Z, $b[0]), 'none', '4. (Z,B) 0000 = tanpa baris');

                    // 5. Off -> On -> Off -> On: A menurunkan haknya sendiri, folder dimatikan (baris tetap), dinyalakan lagi untuk B:
                    //    baris A dinaikkan lagi, tetap satu baris; riwayat tiap langkah +1
                    $Hh = $f['H'];
                    $r = q9_put($t, $s, $me, r3_body([q9_row_body($Hh, 1)]));
                    $t->status($r, 200, '5a. A menurunkan baris sendiri di H ke View');
                    $t->eq(r3_vec($t, $Hh, $me), '[1,0,0,0]', '5a. (H,A) View');
                    $h0 = q9_hist_count($t, $Hh);
                    $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($Hh, 0)]));
                    r3_ok($t, $r, $b[0], '5b. folders [H:0]');
                    $t->eq(r3_flag($t, $Hh), 0, '5b. H Off');
                    $t->eq(r3_vec($t, $Hh, $me), '[1,0,0,0]', '5b. mematikan tidak menyentuh baris (A tetap View) dan tidak menambah baris otomatis');
                    $t->eq(r3_vec($t, $Hh, $b[0]), '[1,1,0,0]', '5b. (H,B) tetap');
                    $t->eq(q9_hist_count($t, $Hh), $h0 + 1, '5b. riwayat +1');
                    $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($Hh, 1)]));
                    r3_ok($t, $r, $b[0], '5c. folders [H:1] lagi');
                    $t->eq(r3_flag($t, $Hh), 1, '5c. H On');
                    $t->eq(r3_vec($t, $Hh, $me), '[1,1,1,1]', '5c. (H,A) dinaikkan lagi ke 1/1/1/1');
                    $t->eq(r3_row_count($t, $Hh, $me), 1, '5c. tetap satu baris (H,A)');
                    $t->eq(r3_vec($t, $Hh, $b[0]), '[1,1,0,0]', '5c. (H,B) tidak berubah');
                    $t->eq(q9_hist_count($t, $Hh), $h0 + 2, '5c. riwayat +1 lagi');

                    // 7. FE mengirim kunci camelCase (req_snake): permissions DAN folders (idArchive, isFolderPermission)
                    $W = $f['W'];
                    $r = q9_put($t, $s, $b[0], [
                        'permissions' => [['idArchive' => $W, 'isView' => 1, 'isUpdate' => 1, 'isDelete' => 0, 'isStore' => 0]],
                        'folders'     => [['idArchive' => $W, 'isFolderPermission' => 1]],
                    ]);
                    r3_ok($t, $r, $b[0], '7. camelCase: folders [W:1] + permissions [W:V+U]');
                    $t->eq(r3_flag($t, $W), 1, '7. camelCase: W On');
                    $t->eq(r3_vec($t, $W, $b[0]), '[1,1,0,0]', '7. camelCase: (W,B) V+U');
                    $t->eq(r3_vec($t, $W, $me), '[1,1,1,1]', '7. camelCase: (W,A) otomatis');
                    $r = q9_put($t, $s, $b[0], ['permissions' => [], 'folders' => [['idArchive' => $W, 'isFolderPermission' => 0]]]);
                    r3_ok($t, $r, $b[0], '7b. camelCase: folders [W:0]');
                    $t->eq(r3_flag($t, $W), 0, '7b. camelCase: W Off');
                });

                // 6. superadmin 1 dan 2 (bypass): baris otomatis atas nama user login superadmin itu (QA_USER dengan role 1 / 2)
                foreach ([1 => $f['H2'], 2 => $f['K']] as $role => $folder) {
                    q9_with_user($t, ['role' => $role, 'emp' => ['SMR']], function ($set) use ($t, $s, $me, $meName, $b, $folder, $role) {
                        r3_reset($t, $folder, 0);
                        $h0 = q9_hist_count($t, $folder);
                        $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($folder, 1)]));
                        r3_ok($t, $r, $b[0], "6. superadmin $role: folders [folder:1]");
                        $t->eq(r3_flag($t, $folder), 1, "6. superadmin $role: flag 1");
                        $t->eq(q9_row($t, $folder)['updated_by'], $meName, "6. superadmin $role: updated_by = user login");
                        $t->eq(r3_vec($t, $folder, $me), '[1,1,1,1]', "6. superadmin $role: baris (folder, superadmin) 1/1/1/1");
                        $t->eq(r3_total_rows($t, $folder), 1, "6. superadmin $role: tanpa baris (folder, B)");
                        $t->eq(q9_hist_count($t, $folder), $h0 + 1, "6. superadmin $role: riwayat +1");
                        $h = r3_last_hist($t, $folder);
                        $t->eq($h['related_username'] ?? null, $meName, "6. superadmin $role: riwayat oleh user login");
                    });
                }
                q9_assert_no408($t, 'AC-32');
            } finally {
                q9_purge($t);
                q9_role_purge($t);
            }
            r3_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-33',
        'title' => '(rev3) target = user login: A PUT {A} folders [F:1] + permissions [F:1/1/0/1] -> (F,A) 1/1/0/1 satu baris (tidak dinaikkan, tanpa baris kedua); folders [K:1] tanpa baris hak -> tanpa baris (K,A); baris A yang sudah ada (G View) tidak dinaikkan; hak 0000 = tanpa baris; flag On tetap tercatat (riwayat +1)',
        'run'   => function ($t) {
            q9_recover($t);
            q9_role_purge($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b] = q9_others($t, 1);

            try {
                $roles = q9_roles_profile($t);
                $f = r3_fixture($t, $me, $meName, $b[0]);
                q9_codes_reset();

                q9_with_user($t, r3_ctx_a($roles), function ($set) use ($t, $s, $me, $meName, $b, $f) {
                    $F = $f['F'];
                    $K = $f['K'];
                    $G = $f['G'];
                    $H = $f['H'];
                    $Z = $f['Z'];

                    $h0 = q9_hist_count($t, $F);
                    $r = q9_put($t, $s, $me, r3_body([q9_row_body($F, 1, 1, 0, 1)], [r3_fl($F, 1)]));
                    r3_ok($t, $r, $me, '1. PUT {A} folders [F:1] + [F:1/1/0/1]');
                    $t->eq(r3_flag($t, $F), 1, '1. F flag 1');
                    $t->eq(q9_row($t, $F)['updated_by'], $meName, '1. F updated_by = A');
                    $t->eq(r3_vec($t, $F, $me), '[1,1,0,1]', '1. (F,A) = 1/1/0/1 (nilai body, BE tidak menaikkan)');
                    $t->eq(r3_row_count($t, $F, $me), 1, '1. satu baris (F,A), tanpa baris kedua');
                    $t->eq(r3_total_rows($t, $F), 1, '1. F hanya berisi baris A');
                    $t->eq(q9_hist_count($t, $F), $h0 + 1, '1. riwayat F +1');

                    $h0 = q9_hist_count($t, $K);
                    $r = q9_put($t, $s, $me, r3_body([], [r3_fl($K, 1)]));
                    r3_ok($t, $r, $me, '2. PUT {A} folders [K:1] tanpa baris hak');
                    $t->eq(r3_flag($t, $K), 1, '2. K flag 1');
                    $t->eq(r3_total_rows($t, $K), 0, '2. K tanpa baris sama sekali (BE tidak menambah apa pun untuk target = user login)');
                    $t->eq(r3_row_count($t, $K, $me), 0, '2. tanpa baris (K,A)');
                    $t->eq(q9_hist_count($t, $K), $h0 + 1, '2. riwayat K +1 (flag berubah)');
                    $t->eq(q9_row($t, $K)['updated_by'], $meName, '2. K updated_by = A');

                    $gRow = r3_row_full($t, $G, $me);
                    $r = q9_put($t, $s, $me, r3_body([], [r3_fl($G, 1)]));
                    r3_ok($t, $r, $me, '3. PUT {A} folders [G:1] (A sudah View)');
                    $t->eq(r3_flag($t, $G), 1, '3. G flag 1');
                    $t->eq(json_encode(r3_row_full($t, $G, $me)), json_encode($gRow), '3. baris (G,A) View tidak dinaikkan dan tidak ditulis');
                    $t->eq(r3_row_count($t, $G, $me), 1, '3. satu baris');

                    $r = q9_put($t, $s, $me, r3_body([q9_row_body($H, 0, 0, 0, 0)], [r3_fl($H, 1)]));
                    r3_ok($t, $r, $me, '4. PUT {A} folders [H:1] + [H:0000]');
                    $t->eq(r3_flag($t, $H), 1, '4. H flag 1');
                    $t->eq(r3_total_rows($t, $H), 0, '4. hak 0000 = tanpa baris (A mematikan hak sendiri, BE tidak mengisi)');

                    $r = q9_put($t, $s, $me, r3_body([q9_row_body($Z, 1)], [r3_fl($Z, 1)]));
                    r3_ok($t, $r, $me, '5. PUT {A} folders [Z:1] + [Z:View]');
                    $t->eq(r3_vec($t, $Z, $me), '[1,0,0,0]', '5. (Z,A) = View (nilai body, bukan 1/1/1/1)');
                    $t->eq(r3_row_count($t, $Z, $me), 1, '5. satu baris');

                    // folder tersimpan On + user login target: tanpa baris otomatis walau A tanpa baris
                    $N = $f['N'];
                    $r = q9_put($t, $s, $me, r3_body([], [r3_fl($N, 1)]));
                    r3_ok($t, $r, $me, '6. PUT {A} folders [N:1] (sudah On)');
                    $t->eq(r3_total_rows($t, $N), 0, '6. N (sudah On) tanpa baris: tidak ada baris otomatis');
                });
                q9_assert_no408($t, 'AC-33');
            } finally {
                q9_purge($t);
                q9_role_purge($t);
            }
            r3_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-34',
        'title' => '(rev3) P On, (P,B) View: folders [P:0] -> 200, flag 0, (P,B) tetap, tanpa baris otomatis, riwayat +1; K-10 b folders [P:0] + permissions [P:V+U] -> P Off, (P,B) V+U, riwayat +1; [C2:1] + [C2:View] -> 200; [C2:0] (sudah Off) + [C2:View] -> 400 ARCHIVE441 parameter C2, data tetap; folders [Q:1] (sudah On) -> 200 tanpa tulisan; 441 hanya untuk folder yang TETAP Off (juga di antara folder lain yang dinyalakan); 0000 pada folder yang dimatikan menghapus baris',
        'run'   => function ($t) {
            q9_recover($t);
            q9_role_purge($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b] = q9_others($t, 1);

            try {
                $roles = q9_roles_profile($t);
                $f = r3_fixture($t, $me, $meName, $b[0]);
                $ids = array_values($f);
                q9_codes_reset();

                q9_with_user($t, r3_ctx_a($roles), function ($set) use ($t, $s, $me, $meName, $b, $f, $ids) {
                    $P = $f['P'];
                    $C2 = $f['C2'];
                    $Q = $f['Q'];
                    $R = $f['R'];
                    q9_perm_restore_row($t, $P, $b[0], [1, 0, 0, 0]);       // spec: (P,B) View
                    $pRowA = r3_row_full($t, $P, $me);

                    // a. folders [P:0]: mati, baris B tetap, tanpa baris otomatis, baris A tidak disentuh
                    $h0 = q9_hist_count($t, $P);
                    $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($P, 0)]));
                    r3_ok($t, $r, $b[0], 'a. folders [P:0]');
                    $t->eq(r3_flag($t, $P), 0, 'a. P Off');
                    $t->eq(q9_row($t, $P)['updated_by'], $meName, 'a. P updated_by = A');
                    $t->eq(r3_vec($t, $P, $b[0]), '[1,0,0,0]', 'a. (P,B) tetap View');
                    $t->eq(json_encode(r3_row_full($t, $P, $me)), json_encode($pRowA), 'a. baris (P,A) tidak disentuh (tanpa baris otomatis)');
                    $t->eq(r3_total_rows($t, $P), 2, 'a. P tetap berisi A dan B saja');
                    $t->eq(q9_hist_count($t, $P), $h0 + 1, 'a. riwayat +1');
                    $node = r3_node($t, $s, $b[0], $P);
                    $t->eq($node['is_folder_permission'], 0, 'a. GET: P Off');
                    $t->eq(q9_node_vals($node), '[1,0,0,0]', 'a. GET: nilai B tersimpan tetap tampil di folder Off');

                    // b. K-10 b: nyalakan lagi lewat DB, matikan bersama hak V+U: diterima, tersimpan
                    r3_set_flag($t, $P, 1);
                    $h0 = q9_hist_count($t, $P);
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($P, 1, 1)], [r3_fl($P, 0)]));
                    r3_ok($t, $r, $b[0], 'b. folders [P:0] + permissions [P:V+U] (K-10 b)');
                    $t->eq(r3_flag($t, $P), 0, 'b. P Off');
                    $t->eq(r3_vec($t, $P, $b[0]), '[1,1,0,0]', 'b. (P,B) V+U tersimpan walau P dimatikan di request yang sama');
                    $t->eq(q9_hist_count($t, $P), $h0 + 1, 'b. riwayat +1 (satu entri untuk flag + baris)');
                    $t->eq(json_encode(r3_row_full($t, $P, $me)), json_encode($pRowA), 'b. baris (P,A) tidak disentuh');

                    // b2. 0000 pada folder yang dimatikan menghapus baris B
                    r3_set_flag($t, $P, 1);
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($P, 0, 0, 0, 0)], [r3_fl($P, 0)]));
                    r3_ok($t, $r, $b[0], 'b2. folders [P:0] + permissions [P:0000]');
                    $t->eq(r3_flag($t, $P), 0, 'b2. P Off');
                    $t->eq(r3_vec($t, $P, $b[0]), 'none', 'b2. (P,B) dihapus');

                    // c. C2 (Off di bawah P): nyalakan + hak View -> 200; baris otomatis A
                    r3_reset($t, $P, 1, [$me => [1, 1, 0, 0], $b[0] => [1, 0, 0, 1]]);
                    r3_reset($t, $C2, 0, [$b[0] => [1, 1, 0, 0]]);
                    $h0 = q9_hist_count($t, $C2);
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($C2, 1)], [r3_fl($C2, 1)]));
                    r3_ok($t, $r, $b[0], 'c. folders [C2:1] + permissions [C2:View]');
                    $t->eq(r3_flag($t, $C2), 1, 'c. C2 On');
                    $t->eq(r3_vec($t, $C2, $b[0]), '[1,0,0,0]', 'c. (C2,B) = View');
                    $t->eq(r3_vec($t, $C2, $me), '[1,1,1,1]', 'c. (C2,A) otomatis 1/1/1/1');
                    $t->eq(q9_hist_count($t, $C2), $h0 + 1, 'c. riwayat +1');

                    // d. C2 sudah Off + folders [C2:0] + hak -> 441 (folder TETAP Off), data tetap
                    r3_reset($t, $C2, 0, [$b[0] => [1, 1, 0, 0]]);
                    $snap = q9_snap($t, $ids);
                    $pt = q9_perm_total($t);
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($C2, 1)], [r3_fl($C2, 0)]));
                    q9_deny($t, $r, 400, 'ARCHIVE441', 'd. folders [C2:0] (sudah Off) + permissions [C2:View]', q9_row($t, $C2)['name']);
                    r3_unchanged($t, $ids, $snap, $pt, 'd');
                    // d2. tanpa kunci folders dan folders [] -> 441 juga (AC-9 rev)
                    foreach (['tanpa folders' => null, 'folders []' => []] as $label => $fl) {
                        $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($C2, 1)], $fl));
                        q9_deny($t, $r, 400, 'ARCHIVE441', "d2. $label + permissions [C2:View]", q9_row($t, $C2)['name']);
                        r3_unchanged($t, $ids, $snap, $pt, "d2 $label");
                    }
                    // d3. 441 untuk folder Off lain walau folder lain di request yang sama dinyalakan; sebaliknya yang dinyalakan lolos
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($C2, 1)], [r3_fl($R, 1)]));
                    q9_deny($t, $r, 400, 'ARCHIVE441', 'd3. hak C2 (tetap Off) + folders [R:1] (folder lain)', q9_row($t, $C2)['name']);
                    r3_unchanged($t, $ids, $snap, $pt, 'd3 (R tidak ikut menyala)');
                    $t->eq(r3_flag($t, $R), 0, 'd3. R tetap Off (atomik)');
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($P, 1), q9_row_body($C2, 1)], [r3_fl($P, 0)]));
                    q9_deny($t, $r, 400, 'ARCHIVE441', 'd4. P dimatikan di request ini (boleh) + C2 tetap Off (441)', q9_row($t, $C2)['name']);
                    r3_unchanged($t, $ids, $snap, $pt, 'd4 (P tidak ikut mati)');
                    $t->eq(r3_flag($t, $P), 1, 'd4. P tetap On (atomik)');

                    // e. campuran sah: P dimatikan + C2 dinyalakan, masing-masing dengan hak -> 200
                    $hp = q9_hist_count($t, $P);
                    $hc = q9_hist_count($t, $C2);
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($P, 1, 1), q9_row_body($C2, 1, 1, 1, 1)], [r3_fl($P, 0), r3_fl($C2, 1)]));
                    r3_ok($t, $r, $b[0], 'e. folders [P:0, C2:1] + permissions [P:V+U, C2:1111]');
                    $t->eq(r3_flag($t, $P), 0, 'e. P Off');
                    $t->eq(r3_flag($t, $C2), 1, 'e. C2 On');
                    $t->eq(r3_vec($t, $P, $b[0]), '[1,1,0,0]', 'e. (P,B) V+U');
                    $t->eq(r3_vec($t, $C2, $b[0]), '[1,1,1,1]', 'e. (C2,B) 1111');
                    $t->eq(r3_vec($t, $C2, $me), '[1,1,1,1]', 'e. (C2,A) otomatis (hanya folder yang dinyalakan)');
                    $t->eq(r3_vec($t, $P, $me), '[1,1,0,0]', 'e. (P,A) tidak disentuh (folder dimatikan: tanpa baris otomatis)');
                    $t->eq(q9_hist_count($t, $P), $hp + 1, 'e. riwayat P +1');
                    $t->eq(q9_hist_count($t, $C2), $hc + 1, 'e. riwayat C2 +1');

                    // f. folders [Q:1] (sudah On): 200 tanpa tulisan/riwayat/baris otomatis; folders [C2:0] pada folder Off: sama
                    r3_reset($t, $C2, 0, [$b[0] => [1, 1, 0, 0]]);
                    $snapAll = q9_snap($t, $ids);
                    $pt = q9_perm_total($t);
                    $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($Q, 1)]));
                    r3_ok($t, $r, $b[0], 'f. folders [Q:1] (sudah On)');
                    r3_unchanged($t, $ids, $snapAll, $pt, 'f Q sudah On: tanpa tulisan/riwayat/baris otomatis');
                    $t->eq(r3_total_rows($t, $Q), 1, 'f. Q tetap hanya berisi baris A (View)');
                    $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($C2, 0)]));
                    r3_ok($t, $r, $b[0], 'f2. folders [C2:0] (sudah Off, tanpa hak)');
                    r3_unchanged($t, $ids, $snapAll, $pt, 'f2 C2 sudah Off: tanpa tulisan');
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($Q, 1, 1)], [r3_fl($Q, 1)]));
                    r3_ok($t, $r, $b[0], 'f3. folders [Q:1] + permissions [Q:V+U] (flag sama, baris baru)');
                    $t->eq(r3_vec($t, $Q, $b[0]), '[1,1,0,0]', 'f3. (Q,B) tersimpan');
                    $t->eq(r3_total_rows($t, $Q), 2, 'f3. tanpa baris otomatis (Q sudah On)');
                });
                q9_assert_no408($t, 'AC-34');
            } finally {
                q9_purge($t);
                q9_role_purge($t);
            }
            r3_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-34b',
        'title' => '(rev3) kolom is_folder_permission boleh NULL di DB (data lama): NULL dibaca Off: GET node 0; folders [F:0] = 200 tanpa tulisan (tetap NULL, tanpa riwayat); hak tanpa folders = 441; folders [F:1] + hak = 200, flag 1, hak otomatis',
        'run'   => function ($t) {
            q9_recover($t);
            q9_role_purge($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b] = q9_others($t, 1);

            try {
                $roles = q9_roles_profile($t);
                $F = q9_folder($t, 'FN', ['perm' => 0]);
                q9_set_archive($t, $F, ['is_folder_permission' => null]);
                q9_codes_reset();

                q9_with_user($t, r3_ctx_a($roles), function ($set) use ($t, $s, $me, $b, $F) {
                    $t->true(q9_row($t, $F)['is_folder_permission'] === null, 'awal: flag NULL di DB');
                    $node = r3_node($t, $s, $b[0], $F);
                    $t->true($node['is_folder_permission'] === 0, 'GET node: NULL dibaca 0');

                    $snap = q9_snap($t, [$F]);
                    $r = q9_put($t, $s, $b[0], r3_body([], [r3_fl($F, 0)]));
                    r3_ok($t, $r, $b[0], '1. folders [F:0] pada NULL');
                    $t->true(q9_same($snap, q9_snap($t, [$F])), '1. tanpa tulisan (tetap NULL, tanpa riwayat)');

                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($F, 1)]));
                    q9_deny($t, $r, 400, 'ARCHIVE441', '2. hak tanpa folders pada NULL (Off)', q9_row($t, $F)['name']);
                    $t->true(q9_same($snap, q9_snap($t, [$F])), '2. data tetap');

                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($F, 1)], [r3_fl($F, 1)]));
                    r3_ok($t, $r, $b[0], '3. folders [F:1] + hak pada NULL');
                    $t->true(r3_flag($t, $F) === 1, '3. flag 1');
                    $t->eq(r3_vec($t, $F, $me), '[1,1,1,1]', '3. hak otomatis A (NULL = tersimpan Off)');
                    $t->eq(r3_vec($t, $F, $b[0]), '[1,0,0,0]', '3. (F,B) View');
                });
                q9_assert_no408($t, 'AC-34b');
            } finally {
                q9_purge($t);
                q9_role_purge($t);
            }
            r3_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-19',
        'title' => '(rev3, sisi BE) P dimatikan lewat tab Izin (PUT archives/{P} is_folder_permission 0, jalur method bersama) sesudah halaman dibuka: Save hak P dari halaman by User = 400 ARCHIVE441 (id+en memuat nama folder), bersama folder sah (C1) tidak ada yang tersimpan, data tetap; baris tidak dihapus oleh tab Izin; dinyalakan lagi lewat halaman ini (folders [P:1]) -> 200, baris A dinaikkan',
        'run'   => function ($t) {
            q9_recover($t);
            q9_role_purge($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b] = q9_others($t, 1);

            try {
                $roles = q9_roles_profile($t);
                $f = r3_fixture($t, $me, $meName, $b[0]);
                $ids = array_values($f);
                q9_codes_reset();

                q9_with_user($t, r3_ctx_a($roles), function ($set) use ($t, $s, $me, $meName, $b, $f, $ids, $roles) {
                    $P = $f['P'];
                    $C1 = $f['C1'];
                    $pName = q9_row($t, $P)['name'];

                    $node = r3_node($t, $s, $b[0], $P);
                    $t->eq($node['is_folder_permission'], 1, 'halaman dibuka: P On');

                    // tab Izin mematikan P (izin saja, tanpa folder_permissions)
                    $h0 = q9_hist_count($t, $P);
                    $rowsBefore = r3_rows($t, $P);
                    $r = $t->call($s, 'PUT', 'api/v5/document-archive/archives/' . $P, ['is_folder_permission' => 0]);
                    $t->status($r, 200, 'tab Izin: matikan P');
                    $t->eq(r3_flag($t, $P), 0, 'tab Izin: P Off');
                    $t->eq(q9_row($t, $P)['updated_by'], $meName, 'tab Izin: updated_by = A (method bersama)');
                    $t->eq(json_encode(r3_rows($t, $P)), json_encode($rowsBefore), 'tab Izin: baris tidak dihapus saat mati');
                    $t->eq(q9_hist_count($t, $P), $h0 + 1, 'tab Izin: riwayat +1');

                    $snap = q9_snap($t, $ids);
                    $pt = q9_perm_total($t);
                    $msgs = [];
                    foreach (['EN', 'ID'] as $lang) {
                        $set(['role' => $roles['A'], 'emp' => ['SMR'], 'lang' => $lang]);
                        $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($P, 1, 1), q9_row_body($C1, 1, 1, 1, 1)]));
                        q9_deny($t, $r, 400, 'ARCHIVE441', "Save hak P (Off) + C1 sah [$lang]", $pName);
                        $t->true(strpos((string) ($r[1]['message'] ?? ''), $pName) !== false, "[$lang] pesan memuat nama folder");
                        $msgs[$lang] = $r[1]['message'] ?? '';
                        r3_unchanged($t, $ids, $snap, $pt, "[$lang] 441");
                    }
                    $t->true($msgs['EN'] !== $msgs['ID'], 'pesan EN dan ID berbeda (kode pesan ada di kedua berkas bahasa)');

                    // dinyalakan lagi dari halaman ini bersama baris: P Off -> On, baris A (View+Update) dinaikkan, baris B + C1 tersimpan
                    $set(['role' => $roles['A'], 'emp' => ['SMR']]);
                    $h0 = q9_hist_count($t, $P);
                    $r = q9_put($t, $s, $b[0], r3_body([q9_row_body($P, 1, 1), q9_row_body($C1, 1, 1, 1, 1)], [r3_fl($P, 1)]));
                    r3_ok($t, $r, $b[0], 'folders [P:1] + permissions [P, C1]');
                    $t->eq(r3_flag($t, $P), 1, 'P On lagi');
                    $t->eq(r3_vec($t, $P, $b[0]), '[1,1,0,0]', '(P,B) V+U');
                    $t->eq(r3_vec($t, $C1, $b[0]), '[1,1,1,1]', '(C1,B) 1111');
                    $t->eq(r3_vec($t, $P, $me), '[1,1,1,1]', '(P,A) V+U dinaikkan ke 1111 (tersimpan Off -> On, target B)');
                    $t->eq(r3_row_count($t, $P, $me), 1, 'tetap satu baris (P,A)');
                    $t->eq(q9_hist_count($t, $P), $h0 + 1, 'riwayat P +1');
                });
                q9_assert_no408($t, 'AC-19');
            } finally {
                q9_purge($t);
                q9_role_purge($t);
            }
            r3_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-36',
        'title' => '(rev3) penegakan nyata sesudah AC-32 (dua pengguna: A = QA_USER, E/B = QA_USER2): A GET archives/{F} -> flag 1, folder_permissions A 1/1/1/1 + B View; E (tanpa baris) buka F = 403 ARCHIVE407, B (View) = 200; F dimatikan dari halaman ini -> tab Izin F Off dengan baris tetap, E = 200; F dinyalakan di tab Izin -> halaman ini F On (nilai B) dan E 403 lagi; tab Izin mematikan -> halaman ini Off',
        'run'   => function ($t) {
            q9_recover($t);
            q9_role_purge($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            $adi = $t->db()->table('users')->where('username', $t->conf('QA_USER2'))->value('id_user');
            if (!$adi) {
                $t->skip('QA_USER2 tidak ada di DB uji');
            }
            $others = q9_others($t, 2);
            $b2 = $others[0][0] !== $adi ? $others[0][0] : $others[1][0];
            $s2 = $t->login('QA_USER2');
            $bind = $t->bind($s2, q9_dbname($t));
            if ($bind[0] !== 200) {
                $t->blocked('QA_USER2 tidak bisa bind ke ' . q9_dbname($t) . ' (HTTP ' . $bind[0] . ')');
            }

            try {
                $roles = q9_roles_profile($t);
                $F = q9_folder($t, 'F36', ['perm' => 0]);                 // Off, dibuat user lain, tanpa baris
                $open = function () use ($t, $s2, $F) {
                    return $t->call($s2, 'GET', 'api/v5/document-archive/archives?' . http_build_query(['id_archive' => $F, 'pagination' => 100]));
                };
                $tab = function () use ($t, $s, $F) {
                    $r = $t->call($s, 'GET', 'api/v5/document-archive/archives/' . $F);
                    $t->status($r, 200, 'A GET archives/{F} (tab Izin)');
                    $rows = [];
                    foreach ($r[1]['result']['folder_permissions'] ?? [] as $x) {
                        $rows[$x['id_user']] = json_encode([(int) $x['is_view'], (int) $x['is_update'], (int) $x['is_delete'], (int) $x['is_store']]);
                    }
                    ksort($rows, SORT_STRING);

                    return [(int) $r[1]['result']['is_folder_permission'], $rows];
                };
                // perisai "Akses Terbatas" di list (baris folder membawa is_folder_permission): dicari lewat nama
                $fName = q9_row($t, $F)['name'];
                $listFlag = function () use ($t, $s, $F, $fName) {
                    $r = $t->call($s, 'GET', 'api/v5/document-archive/archives?' . http_build_query(['search' => json_encode(['query' => $fName]), 'pagination' => 50]));
                    $t->status($r, 200, 'list: search nama F');
                    foreach ($r[1]['result']['data'] ?? [] as $row) {
                        if (($row['id_archive'] ?? null) === $F) {
                            return (int) $row['is_folder_permission'];
                        }
                    }

                    return null;
                };
                q9_codes_reset();

                $r = $open();
                if ($r[0] !== 200) {
                    $t->skip('QA_USER2 tidak bisa membuka folder uji yang Off (HTTP ' . $r[0] . ' ' . (q9_code($r) ?? '') . '): penegakan tidak bisa diperagakan dengan pengguna ini');
                }

                q9_with_user($t, r3_ctx_a($roles), function ($set) use ($t, $s, $me, $adi, $b2, $F, $open, $tab, $listFlag) {
                    // AC-32: nyalakan dari halaman ini untuk B2
                    $r = q9_put($t, $s, $b2, r3_body([q9_row_body($F, 1)], [r3_fl($F, 1)]));
                    r3_ok($t, $r, $b2, '1. PUT {B2} folders [F:1] + [F:View]');
                    [$flag, $rows] = $tab();
                    $t->eq($flag, 1, '1. tab Izin: is_folder_permission 1');
                    $exp = [$me => '[1,1,1,1]', $b2 => '[1,0,0,0]'];
                    ksort($exp, SORT_STRING);
                    $t->eq(json_encode($rows), json_encode($exp), '1. tab Izin: folder_permissions = A 1/1/1/1 + B2 View');
                    $r = $open();
                    q9_deny($t, $r, 403, 'ARCHIVE407', '1. E (QA_USER2 tanpa baris) buka F');
                    $t->eq($listFlag(), 1, '1. list: baris F is_folder_permission 1 (perisai Akses Terbatas)');

                    // B: View -> 200; dicabut -> 403
                    $r = q9_put($t, $s, $adi, r3_body([q9_row_body($F, 1)]));
                    $t->status($r, 200, '2. PUT {QA_USER2} View');
                    $r = $open();
                    $t->status($r, 200, '2. B (QA_USER2, View) buka F');
                    $r = q9_put($t, $s, $adi, r3_body([q9_row_body($F, 0, 0, 0, 0)]));
                    $t->status($r, 200, '2. cabut View');
                    $r = $open();
                    q9_deny($t, $r, 403, 'ARCHIVE407', '2. setelah dicabut: 403 lagi');

                    // dimatikan dari halaman ini: tab Izin Off, baris tetap, E = 200
                    $r = q9_put($t, $s, $b2, r3_body([], [r3_fl($F, 0)]));
                    r3_ok($t, $r, $b2, '3. folders [F:0]');
                    [$flag, $rows2] = $tab();
                    $t->eq($flag, 0, '3. tab Izin: Off');
                    $t->eq(json_encode($rows2), json_encode($exp), '3. tab Izin: baris tetap (tidak dihapus saat mati)');
                    $r = $open();
                    $t->status($r, 200, '3. E buka F (Off, tidak membatasi)');
                    $t->eq($listFlag(), 0, '3. list: baris F is_folder_permission 0 (tanpa perisai)');

                    // dinyalakan di tab Izin: halaman ini F On dengan nilai B2; E 403 lagi
                    $r = $t->call($s, 'PUT', 'api/v5/document-archive/archives/' . $F, ['is_folder_permission' => 1]);
                    $t->status($r, 200, '4. tab Izin: nyalakan');
                    $node = r3_node($t, $s, $b2, $F);
                    $t->eq($node['is_folder_permission'], 1, '4. halaman ini: F On');
                    $t->eq(q9_node_vals($node), '[1,0,0,0]', '4. halaman ini: nilai B2 tersimpan');
                    $r = $open();
                    q9_deny($t, $r, 403, 'ARCHIVE407', '4. E 403 lagi');
                    $t->eq($listFlag(), 1, '4. list: perisai lagi');

                    // dimatikan di tab Izin: halaman ini Off
                    $r = $t->call($s, 'PUT', 'api/v5/document-archive/archives/' . $F, ['is_folder_permission' => 0]);
                    $t->status($r, 200, '5. tab Izin: matikan');
                    $node = r3_node($t, $s, $b2, $F);
                    $t->eq($node['is_folder_permission'], 0, '5. halaman ini: F Off');
                    $t->eq(q9_node_vals($node), '[1,0,0,0]', '5. halaman ini: nilai B2 tetap tampil');
                });
                q9_assert_no408($t, 'AC-36');
            } finally {
                q9_purge($t);
                q9_role_purge($t);
            }
            r3_clean($t, $base);
        },
    ],
];
