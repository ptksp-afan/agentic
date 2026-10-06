<?php
/**
 * ED-1024 - AC-14 (validasi lokasi folder/dokumen, K-6 a), AC-15 (hapus folder, K-7 a),
 * AC-18 (kode lama ARCHIVE404/405/406 = 400, tanpa 500).
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-14',
        'title' => 'Lokasi: create/update folder dan put-in dokumen di luar lokasi induk = 400 ARCHIVE402/403 (DB tidak berubah); lokasi subset = 200',
        'run'   => function ($t) {
            $s = $t->session();
            $base = 'api/v5/document-archive';
            q1_purge($t);
            $loc = q1_locs($t);

            $jogFolder = q1_id_by_name($t, 'CABANG - JOGJA', null);   // folder nyata {JOG}
            $t->true($jogFolder !== null, 'prasyarat: CABANG - JOGJA');
            $jogSnap = q1_snap($t, [$jogFolder]);
            $childrenBefore = $t->db()->table('archives')->where('id_archive_parent', $jogFolder)->count();

            $qaPsmr = q1_add($t, ['name' => 'QA01-PSMR', 'type' => 1, 'all' => 0, 'locs' => ['SMR']]);
            $qaPall = q1_add($t, ['name' => 'QA01-PALL', 'type' => 1, 'all' => 1]);
            $docSmr = q1_add($t, ['name' => 'QA01-DOC-SMR', 'type' => 2, 'all' => 0, 'locs' => ['SMR'], 'doc' => ['type' => 6]]);
            $docJog = q1_add($t, ['name' => 'QA01-DOC-JOG', 'type' => 2, 'all' => 0, 'locs' => ['JOG'], 'doc' => ['type' => 6]]);
            $docAll = q1_add($t, ['name' => 'QA01-DOC-ALL', 'type' => 2, 'all' => 1, 'doc' => ['type' => 6]]);
            $docMix = q1_add($t, ['name' => 'QA01-DOC-MIX', 'type' => 2, 'all' => 0, 'locs' => ['JOG', 'SMR'], 'doc' => ['type' => 6]]);
            $folderSmr = q1_add($t, ['name' => 'QA01-FOLDER-SMR', 'type' => 1, 'all' => 0, 'locs' => ['SMR']]);

            $bad = function ($r, $code, $label) use ($t) {
                $t->status($r, 400, $label);
                $t->code($r, $code, $label);
            };
            $exists = function ($name) use ($t) {
                return $t->db()->table('archives')->where('name', $name)->count();
            };
            $newFolder = function ($name, $all, array $locIds) use ($jogFolder) {
                return ['name' => $name, 'is_all_location' => $all, 'id_locations' => $locIds, 'id_archive_parent' => $jogFolder];
            };

            try {
                // --- create-folder
                $bad($t->call($s, 'POST', "$base/archives/create-folder", $newFolder('QA01-NEW-SMR', 0, [$loc['SMR']])), 'ARCHIVE402', 'create di bawah {JOG} dengan [SMR]');
                $t->eq($exists('QA01-NEW-SMR'), 0, 'DB: tidak ada folder QA01-NEW-SMR');
                $bad($t->call($s, 'POST', "$base/archives/create-folder", $newFolder('QA01-NEW-MIX', 0, [$loc['JOG'], $loc['SMR']])), 'ARCHIVE402', 'create dengan [JOG, SMR]');
                $t->eq($exists('QA01-NEW-MIX'), 0, 'DB: tidak ada folder QA01-NEW-MIX');
                $bad($t->call($s, 'POST', "$base/archives/create-folder", $newFolder('QA01-NEW-ALL', 1, [])), 'ARCHIVE402', 'create semua lokasi di bawah {JOG}');
                $t->eq($exists('QA01-NEW-ALL'), 0, 'DB: tidak ada folder QA01-NEW-ALL');

                $r = $t->call($s, 'POST', "$base/archives/create-folder", $newFolder('QA01-NEW-JOG', 0, [$loc['JOG']]));
                $t->status($r, 200, 'create dengan [JOG] (subset)');
                $t->code($r, 'ARCHIVE201', 'create subset');
                $sub = $r[1]['result']['id_archive'] ?? null;
                $t->true($sub !== null, 'create subset: id_archive');
                $t->eq(q1_row($t, $sub)['id_archive_parent'], $jogFolder, 'DB: induk = CABANG - JOGJA');
                $t->eq(q1_loc_codes($t, $sub), ['JOG'], 'DB: lokasi subfolder = JOG');

                // induk semua lokasi: apa pun boleh
                $r = $t->call($s, 'POST', "$base/archives/create-folder", ['name' => 'QA01-UNDER-ALL-1', 'is_all_location' => 0, 'id_locations' => [$loc['SMR']], 'id_archive_parent' => $qaPall]);
                $t->status($r, 200, 'create {SMR} di bawah induk semua lokasi');
                $r = $t->call($s, 'POST', "$base/archives/create-folder", ['name' => 'QA01-UNDER-ALL-2', 'is_all_location' => 1, 'id_archive_parent' => $qaPall]);
                $t->status($r, 200, 'create semua lokasi di bawah induk semua lokasi');

                // --- update (PUT) subfolder
                $subBefore = q1_snap($t, [$sub]);
                $up = function ($body) use ($t, $s, $base, $sub) {
                    return $t->call($s, 'PUT', "$base/archives/$sub", $body);
                };
                $bad($up(['name' => 'QA01-NEW-JOG', 'is_all_location' => 0, 'id_locations' => [$loc['SMR']]]), 'ARCHIVE402', 'PUT lokasi di luar induk (induk dari data)');
                $bad($up(['name' => 'QA01-NEW-JOG', 'is_all_location' => 0, 'id_locations' => [$loc['SMR']], 'id_archive_parent' => $jogFolder]), 'ARCHIVE402', 'PUT lokasi di luar induk (induk dikirim)');
                $bad($up(['name' => 'QA01-NEW-JOG', 'is_all_location' => 1]), 'ARCHIVE402', 'PUT jadi semua lokasi di bawah induk {JOG}');
                $bad($up(['name' => 'QA01-NEW-JOG', 'is_all_location' => 0, 'id_locations' => [$loc['JOG']], 'id_archive_parent' => $qaPsmr]), 'ARCHIVE402', 'PUT pindah ke induk {SMR} dengan lokasi JOG');
                $t->true(q1_same($subBefore, q1_snap($t, [$sub])), 'DB: subfolder tidak berubah sesudah semua penolakan');

                $r = $up(['name' => 'QA01-NEW-JOG', 'is_all_location' => 0, 'id_locations' => [$loc['JOG']]]);
                $t->status($r, 200, 'PUT lokasi subset');
                $t->code($r, 'ARCHIVE207', 'PUT subset');
                $r = $up(['name' => 'QA01-NEW-JOG', 'is_all_location' => 0, 'id_locations' => [$loc['SMR']], 'id_archive_parent' => $qaPsmr]);
                $t->status($r, 200, 'PUT pindah ke induk {SMR} dengan lokasi SMR');
                $t->eq(q1_row($t, $sub)['id_archive_parent'], $qaPsmr, 'DB: induk berpindah ke QA01-PSMR');
                $t->eq(q1_loc_codes($t, $sub), ['SMR'], 'DB: lokasi = SMR');

                // --- put-in dokumen
                $put = function ($ids, $parent, array $extra = []) use ($t, $s, $base) {
                    return $t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $parent, 'id_archives' => $ids] + $extra);
                };
                $bad($put([$docSmr], $jogFolder), 'ARCHIVE403', 'put-in dokumen {SMR} ke {JOG}');
                $t->eq(q1_row($t, $docSmr)['id_archive_parent'], null, 'DB: dokumen {SMR} tetap di root');
                $bad($t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $jogFolder, 'name' => 'QA01-DOC-SMR']), 'ARCHIVE403', 'put-in dokumen {SMR} lewat name ke {JOG}');
                $bad($put([$docAll], $jogFolder), 'ARCHIVE403', 'put-in dokumen semua lokasi ke {JOG}');
                $bad($put([$docMix], $jogFolder), 'ARCHIVE403', 'put-in dokumen {JOG,SMR} ke {JOG}');
                $bad($put([$folderSmr], $jogFolder), 'ARCHIVE403', 'put-in folder {SMR} ke {JOG}');
                $bad($put([$docJog, $docSmr], $jogFolder), 'ARCHIVE403', 'put-in campuran (satu di luar lokasi)');
                $t->eq(q1_row($t, $docJog)['id_archive_parent'], null, 'DB: campuran ditolak utuh, dokumen {JOG} tidak ikut berpindah');
                $t->eq(q1_row($t, $docAll)['id_archive_parent'], null, 'DB: dokumen semua lokasi tetap di root');

                $r = $put([$docJog], $jogFolder);
                $t->status($r, 200, 'put-in dokumen {JOG} ke {JOG}');
                $t->code($r, 'ARCHIVE204', 'put-in subset');
                $t->eq(q1_row($t, $docJog)['id_archive_parent'], $jogFolder, 'DB: dokumen {JOG} masuk CABANG - JOGJA');
                $r = $put([$docSmr], $qaPsmr);
                $t->status($r, 200, 'put-in dokumen {SMR} ke folder {SMR}');
                $r = $put([$docMix], $qaPall);
                $t->status($r, 200, 'put-in dokumen {JOG,SMR} ke folder semua lokasi');
            } finally {
                q1_purge($t);
            }
            $t->true(q1_same($jogSnap, q1_snap($t, [$jogFolder])), 'CABANG - JOGJA tidak berubah');
            $t->eq($t->db()->table('archives')->where('id_archive_parent', $jogFolder)->count(), $childrenBefore, 'isi CABANG - JOGJA kembali seperti semula');
            q1_assert_clean($t, 'data uji AC-14 sudah dibuang');
        },
    ],

    [
        'id'    => 'AC-15',
        'title' => 'Hapus folder: dokumen aktif di turunan mana pun = 400 ARCHIVE401; subfolder turunan di luar scope = 403 ARCHIVE418 (superadmin tidak); sukses menonaktifkan folder + turunan',
        'run'   => function ($t) {
            $s = $t->session();
            $base = 'api/v5/document-archive';
            q1_purge($t);

            $active = function ($id) use ($t) {
                return (int) q1_row($t, $id)['is_active'];
            };
            $del = function ($id) use ($t, $s, $base) {
                return $t->call($s, 'DELETE', "$base/archives/delete/$id");
            };

            try {
                // --- A: X > Y > Z (semua lokasi) > dokumen aktif
                $x = q1_add($t, ['name' => 'QA01-X', 'type' => 1, 'all' => 1]);
                $y = q1_add($t, ['name' => 'QA01-Y', 'type' => 1, 'all' => 1, 'parent' => $x]);
                $z = q1_add($t, ['name' => 'QA01-Z', 'type' => 1, 'all' => 1, 'parent' => $y]);
                $d = q1_add($t, ['name' => 'QA01-DOC', 'type' => 2, 'all' => 1, 'parent' => $z, 'doc' => ['type' => 6]]);
                $snap = q1_snap($t, [$x, $y, $z, $d]);

                foreach ([['di Z (cucu)', $z], ['di Y (anak)', $y], ['di X (langsung)', $x]] as $case) {
                    q1_set_archive($t, $d, ['id_archive_parent' => $case[1]]);
                    $snapCase = q1_snap($t, [$x, $y, $z, $d]);
                    $r = $del($x);
                    $t->status($r, 400, 'DELETE X, dokumen aktif ' . $case[0]);
                    $t->code($r, 'ARCHIVE401', 'DELETE X, dokumen aktif ' . $case[0]);
                    $t->true(q1_same($snapCase, q1_snap($t, [$x, $y, $z, $d])), 'DB: X/Y/Z/dokumen tidak berubah (dokumen ' . $case[0] . ')');
                    foreach ([$x, $y, $z, $d] as $id) {
                        $t->eq($active($id), 1, 'is_active tetap 1 (dokumen ' . $case[0] . ')');
                    }
                }

                // --- B: X2 (semua) > Y2 {SMR} kosong
                $x2 = q1_add($t, ['name' => 'QA01-X2', 'type' => 1, 'all' => 1]);
                $y2 = q1_add($t, ['name' => 'QA01-Y2', 'type' => 1, 'all' => 0, 'locs' => ['SMR'], 'parent' => $x2]);
                q1_with_user($t, ['role' => 3, 'emp' => ['JOG']], function ($set) use ($t, $del, $x2, $y2, $active) {
                    $r = $del($x2);
                    $t->status($r, 403, 'user JOG DELETE X2 (subfolder {SMR})');
                    $t->code($r, 'ARCHIVE418', 'user JOG DELETE X2');
                    $t->true(strpos((string) ($r[1]['message'] ?? ''), 'Folder tidak dapat dihapus') === 0, 'pesan ID ARCHIVE418');
                    $t->eq($active($x2), 1, 'X2 tetap aktif');
                    $t->eq($active($y2), 1, 'Y2 tetap aktif');

                    $set(['role' => 1, 'emp' => ['JOG']]);
                    $r = $del($x2);
                    $t->status($r, 200, 'superadmin DELETE X2');
                    $t->code($r, 'ARCHIVE203', 'superadmin DELETE X2');
                    $t->eq($active($x2), 0, 'X2 is_active = 0');
                    $t->eq($active($y2), 0, 'Y2 is_active = 0');
                });

                // --- C: urutan cek: 418 sebelum 401 (user JOG), superadmin kena 401
                $x3 = q1_add($t, ['name' => 'QA01-X3', 'type' => 1, 'all' => 1]);
                $y3 = q1_add($t, ['name' => 'QA01-Y3', 'type' => 1, 'all' => 0, 'locs' => ['SMR'], 'parent' => $x3]);
                $d3 = q1_add($t, ['name' => 'QA01-DOC3', 'type' => 2, 'all' => 0, 'locs' => ['SMR'], 'parent' => $y3, 'doc' => ['type' => 6]]);
                q1_with_user($t, ['role' => 3, 'emp' => ['JOG']], function ($set) use ($t, $del, $x3, $active) {
                    $r = $del($x3);
                    $t->status($r, 403, 'JOG: ada subfolder di luar scope + dokumen aktif -> 403 lebih dulu');
                    $t->code($r, 'ARCHIVE418', 'JOG: urutan 418 sebelum 401');
                    $set(['role' => 1, 'emp' => ['JOG']]);
                    $r = $del($x3);
                    $t->status($r, 400, 'superadmin: hanya cek dokumen aktif');
                    $t->code($r, 'ARCHIVE401', 'superadmin: 401');
                    $t->eq($active($x3), 1, 'X3 tetap aktif');
                });

                // --- D: subfolder di luar scope pada kedalaman 3 (X4 > Y4 > Z4 {SMR})
                $x4 = q1_add($t, ['name' => 'QA01-X4', 'type' => 1, 'all' => 1]);
                $y4 = q1_add($t, ['name' => 'QA01-Y4', 'type' => 1, 'all' => 1, 'parent' => $x4]);
                $z4 = q1_add($t, ['name' => 'QA01-Z4', 'type' => 1, 'all' => 0, 'locs' => ['SMR'], 'parent' => $y4]);
                q1_with_user($t, ['role' => 3, 'emp' => ['JOG']], function ($set) use ($t, $del, $x4, $active) {
                    $r = $del($x4);
                    $t->status($r, 403, 'JOG: subfolder {SMR} di kedalaman 3');
                    $t->code($r, 'ARCHIVE418', 'JOG: kedalaman 3');
                    $t->eq($active($x4), 1, 'X4 tetap aktif');
                });

                // --- E: subfolder nonaktif di luar scope tidak menghalangi; subfolder dalam scope ikut nonaktif
                $x5 = q1_add($t, ['name' => 'QA01-X5', 'type' => 1, 'all' => 1]);
                $y5 = q1_add($t, ['name' => 'QA01-Y5', 'type' => 1, 'all' => 0, 'locs' => ['SMR'], 'parent' => $x5, 'active' => 0]);
                $z5 = q1_add($t, ['name' => 'QA01-Z5', 'type' => 1, 'all' => 0, 'locs' => ['JOG'], 'parent' => $x5]);
                q1_with_user($t, ['role' => 3, 'emp' => ['JOG']], function ($set) use ($t, $del, $x5, $y5, $z5, $active) {
                    $r = $del($x5);
                    $t->status($r, 200, 'JOG: subfolder {SMR} nonaktif tidak menghalangi');
                    $t->eq($active($x5), 0, 'X5 is_active = 0');
                    $t->eq($active($z5), 0, 'Z5 {JOG} (turunan) is_active = 0');
                    $t->eq($active($y5), 0, 'Y5 tetap 0');
                });

                // --- F: user dengan lokasi cukup (semua lokasi) menghapus pohon bertag lokasi berbeda
                $x6 = q1_add($t, ['name' => 'QA01-X6', 'type' => 1, 'all' => 1]);
                $y6 = q1_add($t, ['name' => 'QA01-Y6', 'type' => 1, 'all' => 0, 'locs' => ['SMR'], 'parent' => $x6]);
                $r = $del($x6);
                $t->status($r, 200, 'user employee semua lokasi: DELETE pohon dengan subfolder {SMR}');
                $t->eq($active($y6), 0, 'Y6 ikut nonaktif');

                // --- G: dokumen tidak aktif (is_active -1) tidak menghalangi dan tidak diubah (catatan implementasi spec)
                $x7 = q1_add($t, ['name' => 'QA01-X7', 'type' => 1, 'all' => 1]);
                $d7 = q1_add($t, ['name' => 'QA01-DOC7', 'type' => 2, 'all' => 1, 'parent' => $x7, 'active' => -1, 'doc' => ['type' => 6]]);
                $r = $del($x7);
                $t->status($r, 200, 'DELETE folder berisi hanya dokumen is_active=-1');
                $t->eq($active($d7), -1, 'dokumen is_active=-1 tidak diubah');
            } finally {
                q1_purge($t);
            }
            q1_assert_clean($t, 'data uji AC-15 sudah dibuang');
        },
    ],

    [
        'id'    => 'AC-18',
        'title' => 'Kode lama tanpa 500: put-in ke diri sendiri 400 ARCHIVE404; nama kembar 400 ARCHIVE405; id_archives+name 400 ARCHIVE406; DB tidak berubah',
        'run'   => function ($t) {
            $s = $t->session();
            $base = 'api/v5/document-archive';
            q1_purge($t);

            $f1 = q1_add($t, ['name' => 'QA01-F1', 'type' => 1, 'all' => 1]);
            $dupRoot = q1_add($t, ['name' => 'QA01-DUP', 'type' => 1, 'all' => 1]);
            $other = q1_add($t, ['name' => 'QA01-OTHER', 'type' => 1, 'all' => 1]);
            $p = q1_add($t, ['name' => 'QA01-P', 'type' => 1, 'all' => 1]);
            $dupChild = q1_add($t, ['name' => 'QA01-DUPCHILD', 'type' => 1, 'all' => 1, 'parent' => $p]);
            $doc = q1_add($t, ['name' => 'QA01-DOC', 'type' => 2, 'all' => 1, 'doc' => ['type' => 6]]);
            $snap = q1_snap($t, [$f1, $dupRoot, $other, $p, $dupChild, $doc]);
            $count = $t->db()->table('archives')->where('name', 'like', 'QA01-%')->count();

            $bad = function ($r, $code, $label) use ($t) {
                $t->status($r, 400, $label);
                $t->code($r, $code, $label);
                q1_no500($t, $r, $label);
            };

            try {
                // ARCHIVE404
                $bad($t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $f1, 'id_archives' => [$f1]]), 'ARCHIVE404', 'put-in folder ke dirinya sendiri');
                $bad($t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $f1, 'id_archives' => [$doc, $f1]]), 'ARCHIVE404', 'put-in banyak id termasuk diri sendiri');

                // ARCHIVE405
                $bad($t->call($s, 'POST', "$base/archives/create-folder", ['name' => 'QA01-DUP', 'is_all_location' => 1]), 'ARCHIVE405', 'create-folder nama kembar di root');
                $bad($t->call($s, 'POST', "$base/archives/create-folder", ['name' => 'QA01-DUPCHILD', 'is_all_location' => 1, 'id_archive_parent' => $p]), 'ARCHIVE405', 'create-folder nama kembar di induk yang sama');
                $bad($t->call($s, 'PUT', "$base/archives/$other", ['name' => 'QA01-DUP', 'is_all_location' => 1]), 'ARCHIVE405', 'PUT nama kembar di induk yang sama');
                $bad($t->call($s, 'PUT', "$base/archives/$other", ['name' => 'QA01-DUPCHILD', 'is_all_location' => 1, 'id_archive_parent' => $p]), 'ARCHIVE405', 'PUT pindah ke induk yang punya nama sama');
                // put-in folder ke induk yang sudah punya folder bernama sama
                $dupMove = q1_add($t, ['name' => 'QA01-DUPCHILD', 'type' => 1, 'all' => 1]);
                $bad($t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $p, 'id_archives' => [$dupMove]]), 'ARCHIVE405', 'put-in folder bernama sama ke induk');

                // ARCHIVE406
                $bad($t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $f1, 'id_archives' => [$doc], 'name' => 'QA01-DOC']), 'ARCHIVE406', 'put-in id_archives + name sekaligus');
                $bad($t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $f1]), 'ARCHIVE406', 'put-in tanpa sumber sama sekali');

                $t->true(q1_same($snap, q1_snap($t, [$f1, $dupRoot, $other, $p, $dupChild, $doc])), 'DB: baris uji tidak berubah');
                $t->eq($t->db()->table('archives')->where('name', 'like', 'QA01-%')->count(), $count + 1, 'DB: tidak ada baris baru dari permintaan yang ditolak (+1 = QA01-DUPCHILD kedua untuk uji put-in)');
            } finally {
                q1_purge($t);
            }
            q1_assert_clean($t, 'data uji AC-18 sudah dibuang');
        },
    ],

];
