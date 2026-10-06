<?php
/**
 * ED-1024 - AC-1..AC-8: scope lokasi kerja Archive (list, isi folder, pencarian, superadmin, tanpa employee,
 * aksi berbasis id, 404, transaksi terkait). Memakai qa_lib.php (user QA = role 3 / employee semua lokasi
 * di keadaan asli; keadaan lain dibuat sementara oleh q1_with_user dan dipulihkan persis).
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-1',
        'title' => 'Root: user JOG saja melihat Backup Arsip, CABANG - JOGJA, PUSAT - MAGELANG, bukan CABANG - SEMARANG',
        'run'   => function ($t) {
            $s = $t->session();
            q1_purge($t);
            $loc = q1_locs($t);

            q1_with_user($t, ['role' => 3, 'emp' => ['JOG']], function ($set) use ($t, $s, $loc) {
                $r = q1_list($t, $s, ['pagination' => 100]);
                $t->status($r, 200, 'root JOG');
                $names = q1_names($r);
                foreach (['Backup Arsip', 'CABANG - JOGJA', 'PUSAT - MAGELANG'] as $n) {
                    $t->true(in_array($n, $names, true), "root JOG memuat '$n'");
                }
                $t->true(!in_array('CABANG - SEMARANG', $names, true), 'root JOG TIDAK memuat CABANG - SEMARANG');

                $t->eq($r[1]['result']['total'], q1_expected_count($t, null, [$loc['JOG']]), 'root JOG: total = hitungan DB independen');
                $all = q1_all_rows($t, $s, [], 200, 8);
                $t->eq(q1_hidden_ids($t, array_column($all[0], 'id_archive'), [$loc['JOG']]), [], 'root JOG: tidak ada baris di luar scope');

                // employee dengan banyak lokasi = gabungan
                $set(['role' => 3, 'emp' => ['JOG', 'SMR']]);
                $r = q1_list($t, $s, ['pagination' => 100]);
                $names = q1_names($r);
                $t->true(in_array('CABANG - JOGJA', $names, true) && in_array('CABANG - SEMARANG', $names, true), 'JOG+SMR: gabungan, memuat kedua cabang');
                $t->eq($r[1]['result']['total'], q1_expected_count($t, null, [$loc['JOG'], $loc['SMR']]), 'root JOG+SMR: total = hitungan DB');

                // employee MGL saja: tidak ada CABANG
                $set(['role' => 3, 'emp' => ['MGL']]);
                $r = q1_list($t, $s, ['pagination' => 100]);
                $names = q1_names($r);
                $t->true(!in_array('CABANG - JOGJA', $names, true) && !in_array('CABANG - SEMARANG', $names, true), 'MGL: tanpa CABANG JOGJA/SEMARANG');
                $t->true(in_array('Backup Arsip', $names, true) && in_array('PUSAT - MAGELANG', $names, true), 'MGL: folder semua lokasi tetap tampil');
            });
        },
    ],

    [
        'id'    => 'AC-2',
        'title' => 'Isi Backup Arsip: JOG -> SMLYK, SMLYK - BRANGKAS; JOG+MGL -> keenam folder; total = DB',
        'run'   => function ($t) {
            $s = $t->session();
            $loc = q1_locs($t);
            $bk = q1_id_by_name($t, 'Backup Arsip', null);
            $t->true($bk !== null, 'folder Backup Arsip ada di QA DB');

            $folderNames = function ($r) {
                $out = [];
                foreach ($r[1]['result']['data'] ?? [] as $row) {
                    if (($row['type'] ?? null) === 'Folder') {
                        $out[] = $row['name'][0]['transaction_no'];
                    }
                }
                sort($out);

                return $out;
            };

            q1_with_user($t, ['role' => 3, 'emp' => ['JOG']], function ($set) use ($t, $s, $loc, $bk, $folderNames) {
                $r = q1_list($t, $s, ['id_archive' => $bk, 'pagination' => 100]);
                $t->status($r, 200, 'Backup Arsip user JOG');
                $t->eq($folderNames($r), ['SMLYK', 'SMLYK - BRANGKAS'], 'user JOG: folder dalam Backup Arsip');
                $t->eq($r[1]['result']['total'], q1_expected_count($t, $bk, [$loc['JOG']]), 'user JOG: total = hitungan DB');
                $t->eq(q1_hidden_ids($t, q1_ids($r), [$loc['JOG']]), [], 'user JOG: semua baris di halaman dalam scope');
                $t->eq(count($r[1]['result']['breadcrumbs'] ?? []), 1, 'breadcrumb folder terbuka');

                $set(['role' => 3, 'emp' => ['JOG', 'MGL']]);
                $r = q1_list($t, $s, ['id_archive' => $bk, 'pagination' => 100]);
                $t->eq($folderNames($r), ['SMLHO - KANTOR ADMIN', 'SMLSMG', 'SMLSMG - BRANGKAS', 'SMLSMG - PROSES KIRIM', 'SMLYK', 'SMLYK - BRANGKAS'], 'user JOG+MGL: keenam folder');
                $t->eq($r[1]['result']['total'], q1_expected_count($t, $bk, [$loc['JOG'], $loc['MGL']]), 'user JOG+MGL: total = hitungan DB');

                // folder nonaktif tidak pernah tampil
                $t->true(!in_array('HIDDEN', q1_names($r), true) && !in_array('SMLYK - PROSES KIRIM', q1_names($r), true), 'folder is_active<=0 tidak tampil');
            });
        },
    ],

    [
        'id'    => 'AC-3',
        'title' => 'Pencarian semua level: user JOG hanya baris is_all_location=1 / lokasi JOG; SMLHO/SMLSMG tidak muncul',
        'run'   => function ($t) {
            $s = $t->session();
            $loc = q1_locs($t);

            q1_with_user($t, ['role' => 3, 'emp' => ['JOG']], function ($set) use ($t, $s, $loc) {
                $r = q1_list($t, $s, ['search' => json_encode(['query' => 'SML']), 'pagination' => 100]);
                $t->status($r, 200, 'cari SML (JOG)');
                $names = q1_names($r);
                $t->true(in_array('SMLYK', $names, true), 'SMLYK muncul');
                foreach (['SMLHO - KANTOR ADMIN', 'SMLSMG', 'SMLSMG - BRANGKAS', 'SMLSMG - PROSES KIRIM'] as $n) {
                    $t->true(!in_array($n, $names, true), "'$n' tidak muncul untuk JOG");
                }
                $t->eq(q1_hidden_ids($t, q1_ids($r), [$loc['JOG']]), [], 'cari SML: semua baris dalam scope (DB)');

                // dokumen di level dalam: dokumen bertag MGL saja tidak boleh bocor lewat pencarian
                $all = q1_all_rows($t, $s, ['search' => json_encode(['query' => 'DO-JOG/2511'])], 200, 4);
                $t->true(count($all[0]) > 0, 'cari DO-JOG/2511 (JOG) menghasilkan baris');
                $t->eq(q1_hidden_ids($t, array_column($all[0], 'id_archive'), [$loc['JOG']]), [], 'cari dokumen JOG: semua baris dalam scope');
                $mgl = q1_list($t, $s, ['search' => json_encode(['query' => 'DO-MGL/2608']), 'pagination' => 50]);
                $t->status($mgl, 200, 'cari DO-MGL (JOG)');
                $t->eq($mgl[1]['result']['total'], 0, 'dokumen bertag MGL saja tidak ditemukan user JOG');

                $set(['role' => 1, 'emp' => ['JOG']]);
                $r = q1_list($t, $s, ['search' => json_encode(['query' => 'SML']), 'pagination' => 100]);
                $names = q1_names($r);
                $t->true(in_array('SMLHO - KANTOR ADMIN', $names, true) && in_array('SMLSMG', $names, true), 'superadmin melihat SMLHO/SMLSMG (kontrol: filter memang menyaring)');
                $mgl = q1_list($t, $s, ['search' => json_encode(['query' => 'DO-MGL/2608']), 'pagination' => 50]);
                $t->true(($mgl[1]['result']['total'] ?? 0) > 0, 'superadmin menemukan dokumen DO-MGL (kontrol)');
            });
        },
    ],

    [
        'id'    => 'AC-4',
        'title' => 'Bypass superadmin 1/2; superadmin 3 dan role biasa tidak di-bypass (folder tanpa lokasi & QA01-SMR)',
        'run'   => function ($t) {
            $s = $t->session();
            q1_purge($t);
            q1_add($t, ['name' => 'QA01-TANPA-LOKASI', 'type' => 1, 'all' => 0, 'locs' => []]);
            q1_add($t, ['name' => 'QA01-SMR', 'type' => 1, 'all' => 0, 'locs' => ['SMR']]);

            try {
                $has = function ($set) use ($t, $s) {
                    $r = q1_list($t, $s, ['pagination' => 100]);
                    $t->status($r, 200, 'root');
                    $n = q1_names($r);

                    return [in_array('QA01-TANPA-LOKASI', $n, true), in_array('QA01-SMR', $n, true), $r];
                };

                q1_with_user($t, ['role' => 1, 'emp' => ['JOG']], function ($set) use ($t, $has) {
                    list($a, $b, $r) = $has($set);
                    $t->true($a && $b, 'is_superadmin 1 (employee JOG): melihat QA01-TANPA-LOKASI dan QA01-SMR');
                    $t->true(in_array('CABANG - SEMARANG', q1_names($r), true), 'is_superadmin 1: CABANG - SEMARANG tampil');

                    $set(['role' => 2, 'emp' => ['JOG']]);
                    list($a, $b) = $has($set);
                    $t->true($a && $b, 'is_superadmin 2 (Technical Support, ditempel sementara): melihat keduanya');

                    $set(['role' => 1, 'emp' => 'none']);
                    list($a, $b) = $has($set);
                    $t->true($a && $b, 'is_superadmin 1 tanpa employee (seperti equal_admin): melihat keduanya');

                    // bukan bypass
                    $set(['role' => 16, 'emp' => ['JOG']]);
                    list($a, $b) = $has($set);
                    $t->true(!$a && !$b, 'role biasa (16) employee JOG: tidak melihat keduanya');

                    $set(['role' => 3, 'emp' => ['JOG']]);
                    list($a, $b) = $has($set);
                    $t->true(!$a && !$b, 'is_superadmin 3 employee JOG saja: QA01-SMR dan QA01-TANPA-LOKASI tidak tampil');

                    $set(['role' => 3, 'emp' => 'all']);
                    list($a, $b) = $has($set);
                    $t->true(!$a, 'is_superadmin 3 employee semua lokasi: folder tanpa lokasi tetap tidak tampil (3 tidak di-bypass)');
                    $t->true($b, 'is_superadmin 3 employee semua lokasi: QA01-SMR tampil (lewat lokasi, bukan bypass)');
                });
            } finally {
                q1_purge($t);
            }
            q1_assert_clean($t, 'data uji AC-4 sudah dibuang');
        },
    ],

    [
        'id'    => 'AC-5',
        'title' => 'Non-superadmin tanpa employee: root dan isi folder hanya baris semua lokasi',
        'run'   => function ($t) {
            $s = $t->session();
            $bk = q1_id_by_name($t, 'Backup Arsip', null);

            q1_with_user($t, ['role' => 3, 'emp' => 'none'], function ($set) use ($t, $s, $bk) {
                $r = q1_list($t, $s, ['pagination' => 100]);
                $t->status($r, 200, 'root tanpa employee');
                $n = q1_names($r);
                sort($n);
                $t->eq($n, ['Backup Arsip', 'PUSAT - MAGELANG'], 'root: hanya folder semua lokasi');
                $t->eq($r[1]['result']['total'], q1_expected_count($t, null, []), 'root: total = hitungan DB (hanya is_all_location=1)');

                $r = q1_list($t, $s, ['id_archive' => $bk, 'pagination' => 100]);
                $t->status($r, 200, 'Backup Arsip tanpa employee');
                $t->eq($r[1]['result']['total'], q1_expected_count($t, $bk, []), 'Backup Arsip: total = hitungan DB');
                $t->eq(q1_hidden_ids($t, q1_ids($r), []), [], 'Backup Arsip: tidak ada baris bertag lokasi');
            });
        },
    ],

    [
        'id'    => 'AC-6',
        'title' => 'Aksi berbasis id pada folder/dokumen {SMR} oleh user JOG: 403 ARCHIVE407 (sebelum validasi), data tidak berubah; superadmin 200',
        'run'   => function ($t) {
            $s = $t->session();
            $base = 'api/v5/document-archive';
            $loc = q1_locs($t);
            q1_purge($t);

            $smrFolder = q1_id_by_name($t, 'CABANG - SEMARANG', null);
            // satu dokumen aktif bertag SMR saja
            $smrDoc = $t->db()->table('archives as a')
                ->join('archive_locations as l', 'l.id_archive', '=', 'a.id_archive')
                ->where('a.type', 2)->where('a.is_active', 1)->where('a.is_all_location', 0)
                ->groupBy('a.id_archive')->havingRaw("sum(l.id_location <> ?) = 0", [$loc['SMR']])
                ->orderBy('a.id_archive')->value('a.id_archive');
            $t->true($smrFolder !== null && $smrDoc !== null, 'prasyarat: folder CABANG - SEMARANG dan satu dokumen {SMR}');

            $jogFolder = q1_add($t, ['name' => 'QA01-JOGFOLDER', 'type' => 1, 'all' => 0, 'locs' => ['JOG']]);
            $jogDoc = q1_add($t, ['name' => 'QA01-JOGDOC', 'type' => 2, 'all' => 0, 'locs' => ['JOG'], 'doc' => ['type' => 6]]);
            $qaSmrFolder = q1_add($t, ['name' => 'QA01-SMRFOLDER', 'type' => 1, 'all' => 0, 'locs' => ['SMR']]);
            $qaSmrEmpty = q1_add($t, ['name' => 'QA01-SMREMPTY', 'type' => 1, 'all' => 0, 'locs' => ['SMR']]);
            $qaSmrDoc = q1_add($t, ['name' => 'QA01-SMRDOC', 'type' => 2, 'all' => 0, 'locs' => ['SMR'], 'doc' => ['type' => 6]]);

            $before = q1_snap($t, [$smrFolder, $smrDoc, $jogFolder, $jogDoc, $qaSmrFolder, $qaSmrDoc]);
            $childCount = $t->db()->table('archives')->where('id_archive_parent', $smrFolder)->count();
            $totalRows = $t->db()->table('archives')->count();

            $valid = ['name' => 'QA01-HACK', 'is_all_location' => 0, 'id_locations' => [$loc['SMR']]];

            try {
                q1_with_user($t, ['role' => 3, 'emp' => ['JOG']], function ($set) use ($t, $s, $base, $smrFolder, $smrDoc, $jogFolder, $jogDoc, $valid, $loc) {
                    $deny = function ($r, $label) use ($t) {
                        $t->status($r, 403, $label);
                        $t->code($r, 'ARCHIVE407', $label);
                        $t->true(strpos((string) ($r[1]['message'] ?? ''), 'Anda tidak punya akses') === 0, $label . ': pesan ID ARCHIVE407');
                    };

                    $deny(q1_list($t, $s, ['id_archive' => $smrFolder]), 'GET list id_archive=SMR');
                    $deny($t->call($s, 'GET', "$base/archives/$smrFolder"), 'GET show SMR');
                    $deny($t->call($s, 'GET', "$base/archives/history/$smrFolder"), 'GET history SMR');
                    $deny($t->call($s, 'PUT', "$base/archives/$smrFolder", $valid), 'PUT update SMR (body valid)');
                    $deny($t->call($s, 'PUT', "$base/archives/$smrFolder", []), 'PUT update SMR (body kosong -> 403 bukan 422)');
                    $deny($t->call($s, 'PUT', "$base/archives/rename/$smrFolder", ['name' => 'QA01-HACK']), 'PUT rename SMR');
                    $deny($t->call($s, 'PUT', "$base/archives/rename/$smrFolder", []), 'PUT rename SMR (body kosong)');
                    $deny($t->call($s, 'DELETE', "$base/archives/delete/$smrFolder"), 'DELETE SMR');
                    $deny($t->call($s, 'POST', "$base/archives/create-folder", $valid + ['id_archive_parent' => $smrFolder]), 'POST create-folder induk SMR');
                    $deny($t->call($s, 'POST', "$base/archives/create-folder", ['id_archive_parent' => $smrFolder]), 'POST create-folder induk SMR (body tak valid)');
                    $deny($t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $smrFolder, 'id_archives' => [$jogDoc]]), 'POST put-in ke folder SMR');
                    $deny($t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $jogFolder, 'id_archives' => [$smrDoc]]), 'POST put-in dokumen SMR');
                    $deny($t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $jogFolder, 'id_archives' => [$smrDoc], 'name' => 'x']), 'POST put-in dokumen SMR + name (403 sebelum ARCHIVE406)');
                    $deny($t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $jogFolder, 'id_archives' => [$jogDoc, $smrDoc]]), 'POST put-in campuran (satu di luar scope)');
                    $deny($t->call($s, 'PUT', "$base/archives/$jogFolder", ['name' => 'QA01-JOGFOLDER', 'is_all_location' => 0, 'id_locations' => [$loc['JOG']], 'id_archive_parent' => $smrFolder]), 'PUT update: id_archive_parent baru di luar scope');

                    // kontrol: folder/dokumen JOG sendiri tetap bisa dibaca
                    $ok = $t->call($s, 'GET', "$base/archives/$jogFolder");
                    $t->status($ok, 200, 'kontrol: show folder JOG');
                });

                $after = q1_snap($t, [$smrFolder, $smrDoc, $jogFolder, $jogDoc, $qaSmrFolder, $qaSmrDoc]);
                $t->true(q1_same($before, $after), 'DB: baris archives/lokasi/dokumen (SMR asli + QA) tidak berubah sama sekali sesudah semua penolakan');
                $t->eq($t->db()->table('archives')->where('id_archive_parent', $smrFolder)->count(), $childCount, 'DB: isi CABANG - SEMARANG tidak bertambah');
                $t->eq($t->db()->table('archives')->count(), $totalRows, 'DB: jumlah baris archives tidak berubah');

                // superadmin pada id yang sama
                q1_with_user($t, ['role' => 1, 'emp' => ['JOG']], function ($set) use ($t, $s, $base, $smrFolder, $qaSmrFolder, $qaSmrEmpty, $qaSmrDoc, $loc) {
                    $t->status(q1_list($t, $s, ['id_archive' => $smrFolder, 'pagination' => 10]), 200, 'superadmin: list id_archive=SMR');
                    $t->status($t->call($s, 'GET', "$base/archives/$smrFolder"), 200, 'superadmin: show SMR');
                    $t->status($t->call($s, 'GET', "$base/archives/history/$smrFolder"), 200, 'superadmin: history SMR');

                    $r = $t->call($s, 'PUT', "$base/archives/$qaSmrFolder", ['name' => 'QA01-SMRFOLDER', 'is_all_location' => 0, 'id_locations' => [$loc['SMR']]]);
                    $t->status($r, 200, 'superadmin: PUT update folder {SMR}');
                    $t->code($r, 'ARCHIVE207', 'update');
                    $r = $t->call($s, 'PUT', "$base/archives/rename/$qaSmrFolder", ['name' => 'QA01-SMRFOLDER-2']);
                    $t->status($r, 200, 'superadmin: rename');
                    $t->code($r, 'ARCHIVE206', 'rename');
                    $r = $t->call($s, 'POST', "$base/archives/create-folder", ['name' => 'QA01-SMRCHILD', 'is_all_location' => 0, 'id_locations' => [$loc['SMR']], 'id_archive_parent' => $qaSmrFolder]);
                    $t->status($r, 200, 'superadmin: create-folder di bawah folder {SMR}');
                    $t->code($r, 'ARCHIVE201', 'create-folder');
                    $t->true(!empty($r[1]['result']['id_archive']), 'create-folder: result.id_archive ada');
                    $r = $t->call($s, 'POST', "$base/documents/put-in", ['id_archive_parent' => $qaSmrFolder, 'id_archives' => [$qaSmrDoc]]);
                    $t->status($r, 200, 'superadmin: put-in dokumen {SMR} ke folder {SMR}');
                    $t->code($r, 'ARCHIVE204', 'put-in');
                    $r = $t->call($s, 'DELETE', "$base/archives/delete/$qaSmrEmpty");
                    $t->status($r, 200, 'superadmin: DELETE folder {SMR} kosong');
                    $t->code($r, 'ARCHIVE203', 'delete');
                });
            } finally {
                q1_restore($t, $before);   // pulihkan baris SMR asli (jaga-jaga) lalu buang data uji
                q1_purge($t);
            }
            q1_assert_clean($t, 'data uji AC-6 sudah dibuang');
        },
    ],

    [
        'id'    => 'AC-7',
        'title' => 'Id acak tak ada: list id_archive, show, history, update, rename, delete = 404 ARCHIVE400 (bukan 500)',
        'run'   => function ($t) {
            $s = $t->session();
            $base = 'api/v5/document-archive';
            $x = Q1_RANDOM_ID;
            $valid = ['name' => 'QA01-NOPE', 'is_all_location' => 1];

            $check = function ($t, $s) use ($base, $x, $valid) {
                $calls = [
                    'list id_archive' => q1_list($t, $s, ['id_archive' => $x]),
                    'show'            => $t->call($s, 'GET', "$base/archives/$x"),
                    'history'         => $t->call($s, 'GET', "$base/archives/history/$x"),
                    'update'          => $t->call($s, 'PUT', "$base/archives/$x", $valid),
                    'update (body kosong)' => $t->call($s, 'PUT', "$base/archives/$x", []),
                    'rename'          => $t->call($s, 'PUT', "$base/archives/rename/$x", ['name' => 'QA01-NOPE']),
                    'rename (body kosong)' => $t->call($s, 'PUT', "$base/archives/rename/$x", []),
                    'delete'          => $t->call($s, 'DELETE', "$base/archives/delete/$x"),
                ];
                foreach ($calls as $label => $r) {
                    $t->status($r, 404, $label);
                    $t->code($r, 'ARCHIVE400', $label);
                }
            };

            $check($t, $s);   // keadaan asli (role 3, employee semua lokasi)
            q1_with_user($t, ['role' => 3, 'emp' => ['JOG']], function ($set) use ($check, $t, $s) {
                $check($t, $s);
            });
            q1_with_user($t, ['role' => 1, 'emp' => 'none'], function ($set) use ($check, $t, $s) {
                $check($t, $s);
            });

            // sumber put-in tak ada / hand-over / receive nama tak ada (kontrak: 404 ARCHIVE400)
            $r = $t->call($s, 'POST', "$base/documents/put-in", ['name' => 'QA01-NOPE']);
            $t->status($r, 404, 'put-in nama tak ada');
            $t->code($r, 'ARCHIVE400', 'put-in nama tak ada');
            $r = $t->call($s, 'POST', "$base/documents/put-in", ['id_archives' => [$x]]);
            $t->status($r, 404, 'put-in id_archives tak ada');
            $t->code($r, 'ARCHIVE400', 'put-in id_archives tak ada');
            foreach (['hand-over', 'receive'] as $act) {
                $r = $t->call($s, 'POST', "$base/documents/$act", ['name' => 'QA01-NOPE']);
                $t->status($r, 404, "$act nama tak ada");
                $t->code($r, 'ARCHIVE400', "$act nama tak ada");
            }
        },
    ],

    [
        'id'    => 'AC-8',
        'title' => 'Transaksi terkait ikut scope lokasi: DO terkait SO yang dipindah tag ke {SMR} hilang untuk JOG, tetap ada untuk superadmin',
        'run'   => function ($t) {
            $s = $t->session();
            $loc = q1_locs($t);
            q1_purge($t);

            // satu SO yang punya DO ber-baris archive aktif bertag JOG saja
            $cand = $t->db()->selectOne(
                "select d.id_transaction so_id, d.transaction_no so_no, o.delivery_order_no do_no, ad.id_archive do_arch
                 from archive_documents d
                 join archives a on a.id_archive = d.id_archive and a.type = 2 and a.is_active = 1
                 join delivery_orders o on o.id_sales_order = d.id_transaction
                 join archives ad on ad.name = o.delivery_order_no and ad.type = 2 and ad.is_active > 0 and ad.is_all_location = 0
                 where d.transaction_type = 6
                   and (select count(*) from archive_locations l where l.id_archive = ad.id_archive) = 1
                   and exists (select 1 from archive_locations l where l.id_archive = ad.id_archive and l.id_location = ?)
                 order by d.id_archive limit 1",
                [$loc['JOG']]
            );
            if (!$cand) {
                $t->blocked('tidak ada SO bertransaksi DO ber-archive tag JOG di QA DB');
            }

            $folder = q1_add($t, ['name' => 'QA01-REL', 'type' => 1, 'all' => 1]);
            q1_add($t, ['name' => 'QA01-SO', 'type' => 2, 'all' => 1, 'parent' => $folder, 'doc' => ['type' => 6, 'id_transaction' => $cand->so_id]]);
            $before = q1_snap($t, [$cand->do_arch]);
            $relNames = function ($r) {
                $names = [];
                foreach ($r[1]['result']['data'] ?? [] as $row) {
                    foreach ($row['related_transactions'] ?? [] as $rt) {
                        $names[] = $rt['name'];
                    }
                }

                return $names;
            };
            $search = json_encode(['showRelatedTransaction' => true]);

            try {
                q1_with_user($t, ['role' => 3, 'emp' => ['JOG']], function ($set) use ($t, $s, $cand, $folder, $search, $relNames) {
                    $r = q1_list($t, $s, ['id_archive' => $folder, 'search' => $search]);
                    $t->status($r, 200, 'JOG: list folder + showRelatedTransaction');
                    $t->true(in_array($cand->do_no, $relNames($r), true), 'baseline: DO bertag JOG tampil di related_transactions SO untuk user JOG');

                    q1_set_locs($t, $cand->do_arch, ['SMR']);   // pindahkan tag DO ke {SMR}

                    $r = q1_list($t, $s, ['id_archive' => $folder, 'search' => $search]);
                    $t->status($r, 200, 'JOG: setelah tag DO -> SMR');
                    $t->eq(count($r[1]['result']['data']), 1, 'folder uji berisi 1 dokumen (SO)');
                    $t->true(!in_array($cand->do_no, $relNames($r), true), 'user JOG: DO bertag SMR TIDAK ada di related_transactions');

                    $set(['role' => 1, 'emp' => ['JOG']]);
                    $r = q1_list($t, $s, ['id_archive' => $folder, 'search' => $search]);
                    $t->true(in_array($cand->do_no, $relNames($r), true), 'superadmin: DO bertag SMR ada di related_transactions');

                    $set(['role' => 3, 'emp' => ['SMR']]);
                    $r = q1_list($t, $s, ['id_archive' => $folder, 'search' => $search]);
                    $t->true(in_array($cand->do_no, $relNames($r), true), 'user SMR: DO bertag SMR ada di related_transactions');
                });
            } finally {
                q1_restore($t, $before);
                q1_purge($t);
            }
            $t->true(q1_same($before, q1_snap($t, [$cand->do_arch])), 'tag DO dipulihkan persis');
            q1_assert_clean($t, 'data uji AC-8 sudah dibuang');
        },
    ],

];
