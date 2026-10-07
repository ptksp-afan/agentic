<?php
/**
 * ED-1029 - ED-1041: AC-6 (bagian PUT: user tidak ada/nonaktif = 404 ARCHIVE440 sebelum 422/403), AC-7 (simpan + DB + riwayat +
 * konsistensi dengan GET archives/{P}), AC-8 (hapus bila 0, tanpa riwayat bila sama, [] = 200), AC-9 (folder Off 441, atomik),
 * AC-10 (411, rollback sesudah tulis), AC-11 (408/407/400/422), AC-12 (K-4 a: anak On di bawah induk Off).
 *
 * Pemanggil A = QA_USER (role 3, bukan bypass); folder uji QA29-; target B = user aktif lain.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-6b',
        'title' => 'PUT user-permissions/<id tidak ada> atau user nonaktif: 404 ARCHIVE440 (mendahului 422 dan 403), data tetap; PUT [] ke user nonaktif juga 404',
        'run'   => function ($t) {
            q9_recover($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b] = q9_others($t, 1);
            $wasActive = $t->db()->table('users')->where('id_user', $b[0])->value('is_active');

            try {
                $f = q9_fixture($t, $me, $meName, $b[0]);
                $ids = array_values($f);
                $before = q9_snap($t, $ids);
                $permBefore = q9_perm_total($t);

                $okBody = ['permissions' => [q9_row_body($f['P'], 1, 0, 0, 1)]];
                $badBody = ['permissions' => [q9_row_body($f['P'], 5, 'x', null)]];                  // 422 bila user ada
                $forbidBody = ['permissions' => [q9_row_body($f['Q'], 1)]];                           // 403 bila user ada
                $emptyBody = ['permissions' => []];

                // user tidak ada
                foreach (['valid' => $okBody, 'invalid (422 bila user ada)' => $badBody, 'ditolak (403 bila user ada)' => $forbidBody, '[]' => $emptyBody, 'tanpa body' => []] as $label => $body) {
                    $r = q9_put($t, $s, Q9_RANDOM_ID, $body);
                    q9_deny($t, $r, 404, 'ARCHIVE440', "PUT tidak ada, body $label");
                    $t->true(!array_key_exists('result', $r[1]), "PUT tidak ada, body $label: tanpa result");
                }

                // user nonaktif
                foreach ([0, 2, null] as $val) {
                    q9_set_user($t, $b[0], ['is_active' => $val]);
                    foreach (['valid' => $okBody, 'invalid' => $badBody, 'ditolak' => $forbidBody, '[]' => $emptyBody] as $label => $body) {
                        $r = q9_put($t, $s, $b[0], $body);
                        q9_deny($t, $r, 404, 'ARCHIVE440', 'PUT user is_active=' . var_export($val, true) . ", body $label");
                    }
                }
                q9_set_user($t, $b[0], ['is_active' => $wasActive]);

                $t->true(q9_same($before, q9_snap($t, $ids)), 'data (archives, permissions, riwayat) tidak berubah oleh penolakan');
                $t->eq(q9_perm_total($t), $permBefore, 'jumlah archive_permissions tetap');

                // bahasa: ID dan EN
                foreach (['EN' => "User isn't found", 'ID' => 'User tidak ditemukan'] as $lang => $msg) {
                    q9_with_user($t, ['lang' => $lang], function ($set) use ($t, $s, $msg, $lang, $okBody) {
                        $r = q9_put($t, $s, Q9_RANDOM_ID, $okBody);
                        $t->eq($r[1]['message'] ?? null, $msg, "PUT 404: pesan $lang");
                    });
                }
            } finally {
                q9_set_user($t, $b[0], ['is_active' => $wasActive]);
                q9_purge($t);
            }
            $t->eq($t->db()->table('users')->where('id_user', $b[0])->value('is_active'), $wasActive, 'is_active user uji dipulihkan');
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-7',
        'title' => 'A PUT {B} [P: View+Store, C1: View]: 200 ARCHIVE207 (result.id_user), baris (P,B) diganti dan (C1,B) dibuat, baris user lain/folder lain tidak berubah, riwayat permission +1 oleh A hanya di P dan C1, created_by/updated_by = A, GET archives/{P} (02) memuat baris B sama, GET user-permissions/{B} mengembalikan nilai baru',
        'run'   => function ($t) {
            q9_recover($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b, $b2] = q9_others($t, 2);

            try {
                $f = q9_fixture($t, $me, $meName, $b[0], false);
                $ids = array_values($f);
                // keadaan awal: B di P = V+U (akan diganti), C1 tanpa baris (akan dibuat), C2 (Off) V+U dan Q V+D (tidak disentuh);
                // B2 di P = V, di C1 = V+S (tidak disentuh)
                q9_perm($t, $f['P'], $b[0], 1, 1);
                q9_perm($t, $f['C2'], $b[0], 1, 1);
                q9_perm($t, $f['Q'], $b[0], 1, 0, 1);
                q9_perm($t, $f['P'], $b2[0], 1);
                q9_perm($t, $f['C1'], $b2[0], 1, 0, 0, 1);
                $dumpBefore = q9_perm_dump($t, $ids);
                $hist = [];
                foreach ($f as $k => $id) {
                    $hist[$k] = q9_hist_count($t, $id);
                }
                $archivesBefore = $t->db()->table('archives')->count();

                $r = null;
                q9_with_user($t, ['lang' => 'EN'], function ($set) use ($t, $s, $b, $f, &$r) {
                    $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['P'], 1, 0, 0, 1), q9_row_body($f['C1'], 1)]]);
                });
                $t->status($r, 200, 'PUT');
                $t->eq(q9_code($r), 'ARCHIVE207', 'msg_code');
                $t->eq($r[1]['status'] ?? null, 'success', 'status');
                $t->eq($r[1]['message'] ?? null, 'Successfully updated', 'message');
                $t->eq(json_encode($r[1]['result'] ?? null), json_encode(['id_user' => $b[0]]), 'result = {id_user}');

                // DB
                $map = q9_user_map($t, $b[0], $ids);
                $t->eq(json_encode($map[$f['P']] ?? null), '[1,0,0,1]', 'DB: (P,B) = V+S');
                $t->eq(json_encode($map[$f['C1']] ?? null), '[1,0,0,0]', 'DB: (C1,B) = V');
                $t->eq(json_encode($map[$f['C2']] ?? null), '[1,1,0,0]', 'DB: (C2,B) tidak berubah');
                $t->eq(json_encode($map[$f['Q']] ?? null), '[1,0,1,0]', 'DB: (Q,B) tidak berubah');
                $t->eq(count($t->db()->table('archive_permissions')->where('id_archive', $f['P'])->where('id_user', $b[0])->get()), 1, 'DB: (P,B) tepat satu baris');
                $t->eq(count($t->db()->table('archive_permissions')->where('id_archive', $f['C1'])->where('id_user', $b[0])->get()), 1, 'DB: (C1,B) tepat satu baris');
                $dumpAfter = q9_perm_dump($t, $ids);
                $changedKeys = [$f['P'] . '|' . $b[0], $f['C1'] . '|' . $b[0]];
                $strip = function (array $dump) use ($changedKeys) {
                    return array_values(array_filter($dump, function ($row) use ($changedKeys) {
                        return !in_array($row[0] . '|' . $row[1], $changedKeys);
                    }));
                };
                $t->true(q9_same($strip($dumpBefore), $strip($dumpAfter)), 'DB: semua baris lain (B folder lain, B2, A) persis sama');
                $t->eq(count($dumpAfter), count($dumpBefore) + 1, 'DB: total baris fixture +1 (hanya C1,B baru)');
                $rowP = $t->db()->table('archive_permissions')->where('id_archive', $f['P'])->where('id_user', $b[0])->first();
                $rowC1 = $t->db()->table('archive_permissions')->where('id_archive', $f['C1'])->where('id_user', $b[0])->first();
                $t->eq($rowC1->created_by, $meName, 'baris baru: created_by = A');
                $t->eq($rowC1->updated_by, $meName, 'baris baru: updated_by = A');
                $t->true($rowC1->id_archive_permission !== '' && $rowC1->created_at !== null, 'baris baru: PK dan created_at terisi');
                $t->eq($rowP->updated_by, $meName, 'baris diganti: updated_by = A');
                $t->eq($rowP->created_by, 'QA29', 'baris diganti: created_by tetap (pembuat awal)');
                $t->eq($t->db()->table('archives')->count(), $archivesBefore, 'tidak ada baris archives baru/hilang');

                // riwayat: +1 permission di P dan C1 oleh A; folder lain tidak
                foreach ($f as $k => $id) {
                    $want = $hist[$k] + (in_array($k, ['P', 'C1']) ? 1 : 0);
                    $t->eq(q9_hist_count($t, $id), $want, "riwayat permission $k = $want");
                }
                foreach (['P', 'C1'] as $k) {
                    $h = q9_history($t, $f[$k]);
                    $last = end($h);
                    $t->eq($last['action'] ?? null, 'permission', "riwayat $k: action terakhir = permission");
                    $t->eq($last['related_username'] ?? null, $meName, "riwayat $k: related_username = A");
                    $t->true(!empty($last['timestamp']), "riwayat $k: timestamp terisi");
                }

                // tab Permission (02): GET archives/{P} memuat baris B yang sama
                $show = $t->call($s, 'GET', 'api/v5/document-archive/archives/' . $f['P']);
                $t->status($show, 200, 'GET archives/{P}');
                $rows = [];
                foreach ($show[1]['result']['folder_permissions'] ?? [] as $row) {
                    $rows[$row['id_user']] = $row;
                }
                $t->true(isset($rows[$b[0]]), 'GET archives/{P}: baris B ada');
                $t->eq(json_encode([$rows[$b[0]]['is_view'] ?? null, $rows[$b[0]]['is_update'] ?? null, $rows[$b[0]]['is_delete'] ?? null, $rows[$b[0]]['is_store'] ?? null]), '[1,0,0,1]', 'GET archives/{P}: nilai B = V+S');
                $t->eq($rows[$b[0]]['username'] ?? null, $b[1], 'GET archives/{P}: username B');
                $t->eq(json_encode([$rows[$b2[0]]['is_view'] ?? null, $rows[$b2[0]]['is_store'] ?? null]), '[1,0]', 'GET archives/{P}: B2 tidak berubah');
                $show1 = $t->call($s, 'GET', 'api/v5/document-archive/archives/' . $f['C1']);
                $rows1 = [];
                foreach ($show1[1]['result']['folder_permissions'] ?? [] as $row) {
                    $rows1[$row['id_user']] = $row;
                }
                $t->eq(json_encode([$rows1[$b[0]]['is_view'] ?? null, $rows1[$b[0]]['is_store'] ?? null]), '[1,0]', 'GET archives/{C1}: B = V');

                // GET per user (bulatan penuh)
                $g = q9_get($t, $s, $b[0]);
                $flat = q9_flatten($g[1]['result']['data']);
                $n = $flat[$f['P']];
                $t->eq(json_encode([$n['is_view'], $n['is_update'], $n['is_delete'], $n['is_store']]), '[1,0,0,1]', 'GET user: P = V+S');
                $n = $flat[$f['C1']];
                $t->eq(json_encode([$n['is_view'], $n['is_update'], $n['is_delete'], $n['is_store']]), '[1,0,0,0]', 'GET user: C1 = V');

                // sebaliknya: simpan lewat tab Permission (02) terlihat di halaman per user
                $pName = q9_row($t, $f['P'])['name'];
                $put = $t->call($s, 'PUT', 'api/v5/document-archive/archives/' . $f['P'], ['name' => $pName, 'is_all_location' => 1, 'is_folder_permission' => 1, 'folder_permissions' => [
                    ['id_user' => $me, 'is_view' => 1, 'is_update' => 1, 'is_delete' => 0, 'is_store' => 0],
                    ['id_user' => $b[0], 'is_view' => 1, 'is_update' => 0, 'is_delete' => 1, 'is_store' => 0],
                ]]);
                $t->status($put, 200, 'PUT archives/{P} (tab Permission)');
                $g2 = q9_get($t, $s, $b[0]);
                $n = q9_flatten($g2[1]['result']['data'])[$f['P']];
                $t->eq(json_encode([$n['is_view'], $n['is_update'], $n['is_delete'], $n['is_store']]), '[1,0,1,0]', 'sebaliknya: tab Permission -> GET user: P = V+D');
                $t->eq(q9_user_map($t, $b2[0], [$f['P']]) === [] ? 'kosong' : 'ada', 'kosong', 'tab Permission mengganti seluruh set P: B2 terhapus (perilaku 02)');
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-8',
        'title' => 'PUT P keempat hak 0: baris (P,B) terhapus (+1 riwayat); ulang pada P tanpa baris = 200 tanpa riwayat/baris; nilai sama seperti tersimpan = tanpa entri riwayat dan tanpa perubahan data (termasuk updated_at); permissions=[] = 200 tanpa perubahan; kunci camelCase di isi baris diterima',
        'run'   => function ($t) {
            q9_recover($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b, $b2] = q9_others($t, 2);

            try {
                $f = q9_fixture($t, $me, $meName, $b[0]);            // B: P=V+S, C1=V, C2=V+U
                q9_perm($t, $f['P'], $b2[0], 1, 1);
                $ids = array_values($f);

                // 1. hapus (P,B)
                $histP = q9_hist_count($t, $f['P']);
                $others = q9_perm_dump($t, $ids);
                $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['P'], 0, 0, 0, 0)]]);
                $t->status($r, 200, 'hapus: PUT');
                $t->eq(q9_code($r), 'ARCHIVE207', 'hapus: code');
                $t->true(!isset(q9_user_map($t, $b[0], [$f['P']])[$f['P']]), 'hapus: baris (P,B) tidak ada lagi');
                $t->eq($t->db()->table('archive_permissions')->where('id_archive', $f['P'])->where('id_user', $b[0])->count(), 0, 'hapus: count = 0 (bukan baris semua-0)');
                $afterDump = q9_perm_dump($t, $ids);
                $t->eq(count($afterDump), count($others) - 1, 'hapus: tepat satu baris hilang');
                $t->true(q9_user_map($t, $b2[0], [$f['P']]) === [$f['P'] => [1, 1, 0, 0]], 'hapus: baris B2 di P utuh');
                $t->true(isset(q9_user_map($t, $b[0], [$f['C1']])[$f['C1']]) && isset(q9_user_map($t, $b[0], [$f['C2']])[$f['C2']]), 'hapus: baris B di C1/C2 utuh');
                $t->eq(q9_hist_count($t, $f['P']), $histP + 1, 'hapus: riwayat permission P +1');

                // 2. ulang hapus pada P tanpa baris: tanpa riwayat, tanpa baris
                $snap = q9_snap($t, $ids);
                $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['P'], 0, 0, 0, 0)]]);
                $t->status($r, 200, 'hapus ulang: PUT');
                $t->eq(q9_code($r), 'ARCHIVE207', 'hapus ulang: code');
                $t->true(q9_same($snap, q9_snap($t, $ids)), 'hapus ulang: tidak ada perubahan (data + riwayat + updated_at)');
                $t->eq($t->db()->table('archive_permissions')->where('id_archive', $f['P'])->where('id_user', $b[0])->count(), 0, 'hapus ulang: tidak ada baris semua-0 dibuat');

                // 3. nilai sama seperti tersimpan: (C1,B) = V -> kirim V lagi; (C2? Off ditolak, bukan di sini)
                sleep(1);                                              // updated_at harus terbedakan bila disentuh
                $snap = q9_snap($t, $ids);
                $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['C1'], 1, 0, 0, 0)]]);
                $t->status($r, 200, 'nilai sama: PUT');
                $t->true(q9_same($snap, q9_snap($t, $ids)), 'nilai sama: tanpa perubahan data, tanpa entri riwayat, updated_at utuh');

                // 4. permissions = []
                $snap = q9_snap($t, $ids);
                $r = q9_put($t, $s, $b[0], ['permissions' => []]);
                $t->status($r, 200, 'permissions=[]: PUT');
                $t->eq(q9_code($r), 'ARCHIVE207', 'permissions=[]: code');
                $t->eq(json_encode($r[1]['result'] ?? null), json_encode(['id_user' => $b[0]]), 'permissions=[]: result');
                $t->true(q9_same($snap, q9_snap($t, $ids)), 'permissions=[]: tanpa perubahan');

                // 5. campuran: satu berubah + satu sama + satu hapus-tanpa-baris
                $histC1 = q9_hist_count($t, $f['C1']);
                $histP = q9_hist_count($t, $f['P']);
                $r = q9_put($t, $s, $b[0], ['permissions' => [
                    q9_row_body($f['C1'], 1, 1, 1, 1),     // berubah
                    q9_row_body($f['P'], 0, 0, 0, 0),      // P tanpa baris: tanpa perubahan
                ]]);
                $t->status($r, 200, 'campuran: PUT');
                $t->eq(json_encode(q9_user_map($t, $b[0], [$f['C1']])[$f['C1']] ?? null), '[1,1,1,1]', 'campuran: C1 jadi V+U+D+S');
                $t->eq(q9_hist_count($t, $f['C1']), $histC1 + 1, 'campuran: riwayat C1 +1');
                $t->eq(q9_hist_count($t, $f['P']), $histP, 'campuran: riwayat P tetap');

                // 6. kunci camelCase di isi baris (FE): diterima req_snake
                $r = q9_put($t, $s, $b[0], ['permissions' => [['idArchive' => $f['C1'], 'isView' => 1, 'isUpdate' => 0, 'isDelete' => 0, 'isStore' => 1]]]);
                $t->status($r, 200, 'camelCase: PUT');
                $t->eq(json_encode(q9_user_map($t, $b[0], [$f['C1']])[$f['C1']] ?? null), '[1,0,0,1]', 'camelCase: C1 jadi V+S');

                // 7. flag bertipe string "1"/"0" (form) dan bool
                $r = q9_put($t, $s, $b[0], ['permissions' => [['id_archive' => $f['C1'], 'is_view' => '1', 'is_update' => '1', 'is_delete' => '0', 'is_store' => '0']]]);
                $t->status($r, 200, 'flag string: PUT');
                $t->eq(json_encode(q9_user_map($t, $b[0], [$f['C1']])[$f['C1']] ?? null), '[1,1,0,0]', 'flag string: C1 jadi V+U');
                // flag boolean JSON bukan bagian kontrak (0/1): hanya tidak boleh 5xx dan tidak boleh menyimpan nilai aneh
                $r = q9_put($t, $s, $b[0], ['permissions' => [['id_archive' => $f['C1'], 'is_view' => true, 'is_update' => false, 'is_delete' => false, 'is_store' => false]]]);
                q9_no500($t, $r, 'flag bool JSON');
                $t->true(in_array($r[0], [200, 422]), 'flag bool JSON: 200/422 (' . $r[0] . ')');
                $t->eq(json_encode(q9_user_map($t, $b[0], [$f['C1']])[$f['C1']] ?? null), '[1,1,0,0]', 'flag bool JSON: C1 tidak berubah dari V+U');
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-9',
        'title' => 'PUT baris C2 (Off) / R (Off): 400 ARCHIVE441 (parameter = nama folder, pesan ID/EN memuat nama); bersama baris P yang sah (urutan mana pun) P juga tidak tersimpan (atomik); data, riwayat dan updated_at tidak berubah',
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
                $c2Name = q9_row($t, $f['C2'])['name'];
                $rName = q9_row($t, $f['R'])['name'];
                $before = q9_snap($t, $ids);
                $permBefore = q9_perm_total($t);

                foreach (['EN' => ["Folder permission is not enabled on folder <b>$c2Name</b>", "Folder permission is not enabled on folder <b>$rName</b>"],
                          'ID' => ["Folder permission belum aktif di folder <b>$c2Name</b>", "Folder permission belum aktif di folder <b>$rName</b>"]] as $lang => $msgs) {
                    q9_with_user($t, ['lang' => $lang], function ($set) use ($t, $s, $b, $f, $c2Name, $rName, $msgs, $lang, $ids, $before, $permBefore) {
                        $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['C2'], 1)]]);
                        q9_deny($t, $r, 400, 'ARCHIVE441', "C2 ($lang)", $c2Name);
                        $t->eq($r[1]['message'] ?? null, $msgs[0], "C2 ($lang): pesan memuat nama folder");

                        $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['R'], 1, 1)]]);
                        q9_deny($t, $r, 400, 'ARCHIVE441', "R ($lang)", $rName);
                        $t->eq($r[1]['message'] ?? null, $msgs[1], "R ($lang): pesan memuat nama folder");

                        // atomik: P sah di depan, di belakang, dan di tengah
                        $valid = q9_row_body($f['P'], 1, 1, 1, 1);
                        $validC1 = q9_row_body($f['C1'], 1, 1, 0, 0);
                        foreach (['P,C2' => [$valid, q9_row_body($f['C2'], 1)],
                                  'C2,P' => [q9_row_body($f['C2'], 1), $valid],
                                  'P,C2,C1' => [$valid, q9_row_body($f['C2'], 1), $validC1],
                                  'P,C1,R' => [$valid, $validC1, q9_row_body($f['R'], 1)]] as $label => $rows) {
                            $r = q9_put($t, $s, $b[0], ['permissions' => $rows]);
                            $t->status($r, 400, "atomik $label ($lang)");
                            $t->eq(q9_code($r), 'ARCHIVE441', "atomik $label ($lang): code");
                            $t->true(q9_same($before, q9_snap($t, $ids)), "atomik $label ($lang): tidak ada yang tersimpan (data+riwayat)");
                            $t->eq(q9_perm_total($t), $permBefore, "atomik $label ($lang): jumlah baris tetap");
                        }
                    });
                }

                // folder Off diaktifkan -> sah (parameter 441 berlaku per saat PUT)
                q9_set_archive($t, $f['C2'], ['is_folder_permission' => 1]);
                // C2 On: A butuh hak sendiri di C2 (induk P sudah V+U) supaya boleh mengelola
                q9_perm($t, $f['C2'], $me, 1, 1);
                $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['C2'], 1, 0, 0, 1)]]);
                $t->status($r, 200, 'C2 diaktifkan: PUT sah');
                $t->eq(json_encode(q9_user_map($t, $b[0], [$f['C2']])[$f['C2']] ?? null), '[1,0,0,1]', 'C2 diaktifkan: baris tersimpan');
                // dan dimatikan lagi: baris tersimpan, PUT ditolak 441, baris tidak berubah
                q9_set_archive($t, $f['C2'], ['is_folder_permission' => 0]);
                $snap = q9_snap($t, [$f['C2']]);
                $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['C2'], 0, 0, 0, 0)]]);
                q9_deny($t, $r, 400, 'ARCHIVE441', 'C2 dimatikan: hapus baris pun ditolak', $c2Name);
                $t->true(q9_same($snap, q9_snap($t, [$f['C2']])), 'C2 dimatikan: baris tersimpan utuh (K-10 ii)');
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-10',
        'title' => 'PUT is_update/is_delete/is_store=1 dengan is_view=0: 400 ARCHIVE411 (ID/EN), data tidak berubah; rollback penuh juga bila baris sah ditulis lebih dulu (P sah lalu C1 invalid): tidak ada baris dan tidak ada riwayat tersisa',
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
                $before = q9_snap($t, $ids);
                $permBefore = q9_perm_total($t);
                $valid = q9_row_body($f['P'], 1, 1, 1, 1);
                $validNew = q9_row_body($f['R1'], 1, 0, 0, 1);     // baris baru (R1 tanpa baris B)
                $bad = [
                    'update tanpa view' => q9_row_body($f['C1'], 0, 1, 0, 0),
                    'delete tanpa view' => q9_row_body($f['C1'], 0, 0, 1, 0),
                    'store tanpa view'  => q9_row_body($f['C1'], 0, 0, 0, 1),
                    'semua tanpa view'  => q9_row_body($f['C1'], 0, 1, 1, 1),
                ];

                foreach (['EN' => 'Update, Delete and Store permission require View permission', 'ID' => 'Permission Update, Delete, dan Store membutuhkan permission View'] as $lang => $msg) {
                    q9_with_user($t, ['lang' => $lang], function ($set) use ($t, $s, $b, $bad, $valid, $validNew, $ids, $before, $permBefore, $msg, $lang) {
                        foreach ($bad as $label => $row) {
                            foreach (['sendiri' => [$row], 'sah dulu' => [$valid, $row], 'sah baru dulu' => [$validNew, $valid, $row], 'sah sesudah' => [$row, $valid]] as $pos => $rows) {
                                $r = q9_put($t, $s, $b[0], ['permissions' => $rows]);
                                q9_deny($t, $r, 400, 'ARCHIVE411', "$label / $pos ($lang)");
                                $t->eq($r[1]['message'] ?? null, $msg, "$label / $pos ($lang): pesan");
                                $t->true(q9_same($before, q9_snap($t, $ids)), "$label / $pos ($lang): data, baris dan riwayat tidak berubah (rollback)");
                                $t->eq(q9_perm_total($t), $permBefore, "$label / $pos ($lang): jumlah baris tetap");
                            }
                        }
                    });
                }

                // 441 dan 411 bersama: salah satu, tetap tidak ada yang tersimpan
                $r = q9_put($t, $s, $b[0], ['permissions' => [$valid, $bad['update tanpa view'], q9_row_body($f['C2'], 1)]]);
                $t->true(in_array($r[0], [400]), 'campur 411+441: 400 (' . $r[0] . ')');
                $t->true(in_array(q9_code($r), ['ARCHIVE411', 'ARCHIVE441']), 'campur 411+441: salah satu kode ' . q9_code($r));
                $t->true(q9_same($before, q9_snap($t, $ids)), 'campur 411+441: tidak ada yang tersimpan');
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-11',
        'title' => 'A PUT baris Q: 403 ARCHIVE408 (mendahului 422 walau body lain tidak valid; parameter = nama folder penolak, bisa induk); baris L (di luar lokasi) 403 ARCHIVE407; dokumen/tidak ada/folder nonaktif 404 ARCHIVE400; id_archive ganda atau flag kosong/di luar 0,1 = 422; data tidak berubah',
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
                $f['QCh'] = q9_folder($t, 'QCh', ['perm' => 1, 'parent' => $f['Q']]);       // A V+U sendiri, tetapi induk Q hanya View
                q9_perm($t, $f['QCh'], $me, 1, 1);
                $ids = array_values($f);
                $before = q9_snap($t, $ids);
                $permBefore = q9_perm_total($t);
                $qName = q9_row($t, $f['Q'])['name'];
                $nName = q9_row($t, $f['N'])['name'];
                $valid = q9_row_body($f['P'], 1, 1, 1, 1);
                $unchanged = function ($label) use ($t, $ids, $before, $permBefore) {
                    $t->true(q9_same($before, q9_snap($t, $ids)), "$label: data tidak berubah");
                    $t->eq(q9_perm_total($t), $permBefore, "$label: jumlah baris tetap");
                };

                q9_with_user($t, ['emp' => ['SMR']], function ($set) use ($t, $s, $b, $f, $qName, $nName, $valid, $unchanged) {
                    // 408
                    $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['Q'], 1)]]);
                    q9_deny($t, $r, 403, 'ARCHIVE408', 'Q (A tanpa Update)', $qName);
                    $unchanged('Q');

                    $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['N'], 1)]]);
                    q9_deny($t, $r, 403, 'ARCHIVE408', 'N (On, A tanpa baris/View)', $nName);
                    $unchanged('N');

                    $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['QCh'], 1)]]);
                    q9_deny($t, $r, 403, 'ARCHIVE408', 'QCh (induk Q membatasi)', $qName);
                    $unchanged('QCh');

                    // 408 mendahului 422 (body lain tidak valid)
                    foreach ([
                        'flag salah'        => [q9_row_body($f['Q'], 1), ['id_archive' => $f['P'], 'is_view' => 7, 'is_update' => 'x', 'is_delete' => null]],
                        'id ganda'          => [q9_row_body($f['Q'], 1), $valid, $valid],
                        'flag hilang'       => [q9_row_body($f['Q'], 1), ['id_archive' => $f['P']]],
                        'baris bukan array' => [q9_row_body($f['Q'], 1), 'x'],
                    ] as $label => $rows) {
                        $r = q9_put($t, $s, $b[0], ['permissions' => $rows]);
                        q9_deny($t, $r, 403, 'ARCHIVE408', "408 sebelum 422 ($label)", $qName);
                        $unchanged("408 sebelum 422 ($label)");
                    }

                    // P sah bersama Q: P pun tidak tersimpan
                    $r = q9_put($t, $s, $b[0], ['permissions' => [$valid, q9_row_body($f['Q'], 1)]]);
                    q9_deny($t, $r, 403, 'ARCHIVE408', 'P sah + Q', $qName);
                    $unchanged('P sah + Q');

                    // 407: L di luar lokasi (A juga tidak punya hak di L, 407 mendahului 408)
                    $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['L'], 1)]]);
                    $t->status($r, 403, 'L (di luar lokasi)');
                    $t->eq(q9_code($r), 'ARCHIVE407', 'L: code');
                    $t->true(!isset($r[1]['parameter']), 'L: tanpa parameter (teks generik)');
                    $unchanged('L');
                    $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['L'], 1), ['id_archive' => $f['P'], 'is_view' => 9]]]);
                    $t->status($r, 403, 'L + body invalid');
                    $t->eq(q9_code($r), 'ARCHIVE407', 'L + body invalid: 407 sebelum 422');
                    $unchanged('L + body invalid');
                    $r = q9_put($t, $s, $b[0], ['permissions' => [$valid, q9_row_body($f['L'], 1)]]);
                    $t->eq(q9_code($r), 'ARCHIVE407', 'P sah + L: 407');
                    $unchanged('P sah + L');
                    // 407 dan 408 bersamaan: yang tampil satu dari keduanya, tanpa tersimpan
                    $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['Q'], 1), q9_row_body($f['L'], 1)]]);
                    $t->status($r, 403, 'Q + L');
                    $t->true(in_array(q9_code($r), ['ARCHIVE407', 'ARCHIVE408']), 'Q + L: 407/408 (' . q9_code($r) . ')');
                    $unchanged('Q + L');

                    // 404: dokumen, tidak ada, folder nonaktif
                    foreach (['dokumen' => $f['DP'], 'tidak ada' => Q9_RANDOM_ID, 'folder nonaktif' => $f['PX']] as $label => $id) {
                        $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($id, 1)]]);
                        q9_deny($t, $r, 404, 'ARCHIVE400', "id_archive $label");
                        $unchanged("id_archive $label");
                        $r = q9_put($t, $s, $b[0], ['permissions' => [$valid, q9_row_body($id, 1)]]);
                        q9_deny($t, $r, 404, 'ARCHIVE400', "P sah + id_archive $label");
                        $unchanged("P sah + id_archive $label");
                    }

                    // 422
                    $cases = [
                        'id_archive ganda'        => [$valid, $valid],
                        'id_archive ganda (3)'    => [$valid, q9_row_body($f['C1'], 1), $valid],
                        'is_view kosong ""'       => [['id_archive' => $f['P'], 'is_view' => '', 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0]],
                        'is_view null'            => [['id_archive' => $f['P'], 'is_view' => null, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0]],
                        'is_store hilang'         => [['id_archive' => $f['P'], 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0]],
                        'is_update = 2'           => [['id_archive' => $f['P'], 'is_view' => 1, 'is_update' => 2, 'is_delete' => 0, 'is_store' => 0]],
                        'is_delete = -1'          => [['id_archive' => $f['P'], 'is_view' => 1, 'is_update' => 0, 'is_delete' => -1, 'is_store' => 0]],
                        'is_view = "abc"'         => [['id_archive' => $f['P'], 'is_view' => 'abc', 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0]],
                        'is_view array'           => [['id_archive' => $f['P'], 'is_view' => [1], 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0]],
                        'id_archive hilang'       => [['is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0]],
                        'id_archive null'         => [['id_archive' => null, 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0]],
                        'id_archive ""'           => [['id_archive' => '', 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0]],
                        'id_archive array'        => [['id_archive' => [$f['P']], 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0]],
                        'baris string'            => ['x'],
                        'baris kosong {}'         => [[]],
                    ];
                    foreach ($cases as $label => $rows) {
                        $r = q9_put($t, $s, $b[0], ['permissions' => $rows]);
                        q9_no500($t, $r, "422: $label");
                        $t->status($r, 422, "422: $label");
                        $t->true(is_array($r[1]['errors'] ?? null) && count($r[1]['errors']) > 0, "422: $label: errors terisi");
                        $unchanged("422: $label");
                    }
                    foreach (['permissions hilang' => [], 'permissions null' => ['permissions' => null], 'permissions string' => ['permissions' => 'x'],
                              'permissions objek' => ['permissions' => 'a:b'], 'permissions angka' => ['permissions' => 5]] as $label => $body) {
                        $r = q9_put($t, $s, $b[0], $body);
                        q9_no500($t, $r, "422: $label");
                        $t->status($r, 422, "422: $label");
                        $unchanged("422: $label");
                    }
                });
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-12',
        'title' => 'R Off, R1 On: A (V+U di R1) PUT baris R1 = 200 dan tersimpan (K-4 a: induk Off tidak membatasi); PUT R (Off) tetap 441',
        'run'   => function ($t) {
            q9_recover($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $me = q9_uid($t);
            $meName = q9_uname($t);
            [$b] = q9_others($t, 1);

            try {
                $f = q9_fixture($t, $me, $meName, $b[0], false);
                $ids = array_values($f);
                $histR1 = q9_hist_count($t, $f['R1']);
                $histR = q9_hist_count($t, $f['R']);

                $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['R1'], 1, 0, 0, 1)]]);
                $t->status($r, 200, 'PUT R1');
                $t->eq(q9_code($r), 'ARCHIVE207', 'PUT R1: code');
                $t->eq(json_encode(q9_user_map($t, $b[0], [$f['R1']])[$f['R1']] ?? null), '[1,0,0,1]', 'DB: (R1,B) = V+S');
                $t->eq(q9_hist_count($t, $f['R1']), $histR1 + 1, 'riwayat R1 +1');
                $t->eq(q9_hist_count($t, $f['R']), $histR, 'riwayat R (induk Off) tidak berubah');

                $r = q9_put($t, $s, $b[0], ['permissions' => [q9_row_body($f['R1'], 1, 0, 0, 1), q9_row_body($f['R'], 1)]]);
                $t->status($r, 400, 'PUT R1 + R (Off)');
                $t->eq(q9_code($r), 'ARCHIVE441', 'PUT R1 + R: 441');
                $t->eq(q9_hist_count($t, $f['R1']), $histR1 + 1, 'riwayat R1 tetap +1 (PUT kedua atomik, tanpa tulis)');

                // GET menunjukkan nilai R1 dan R1 bisa dikelola, R terkunci (Off)
                $g = q9_get($t, $s, $b[0]);
                $flat = q9_flatten($g[1]['result']['data']);
                $t->eq(json_encode([$flat[$f['R1']]['is_view'], $flat[$f['R1']]['is_store']]), '[1,1]', 'GET: R1 = V+S');
                $t->true($flat[$f['R1']]['is_folder_permission'] === 1 && $flat[$f['R1']]['access']['manage_permission'] === true, 'GET: R1 On dan manage_permission true');
                $t->true($flat[$f['R']]['is_folder_permission'] === 0, 'GET: R Off');
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

];
