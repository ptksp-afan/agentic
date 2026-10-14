<?php
/**
 * ED-1069 skenario tambahan (X-n): matriks izin endpoint yang berubah, regresi aturan yang tidak berubah, ketahanan tipe
 * input (tanpa 5xx), dua petunjuk orkestrator (transaksi terkait vs folder senama nomor transaksi; put-in sumber
 * nonaktif), tinjauan diff untuk state statis (isolasi tenant).
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'X-1',
        'title' => 'izin endpoint yang berubah: tanpa permission 403 GE0114 sebelum validasi (put-in, create-folder, rename, PUT); permission yang tepat lolos; tanpa token 401; select lokasi tanpa permission khusus',
        'run'   => function ($t) {
            q69_recover($t);
            q69_role_purge($t);
            $s = $t->session();
            q69_purge($t);
            $base = q69_base($t);

            try {
                $A = q69_folder($t, 'A');
                $B = q69_folder($t, 'B', ['parent' => $A]);
                $C = q69_folder($t, 'C', ['parent' => $B]);
                $D = q69_doc($t, 'D', ['parent' => $A]);
                $S = q69_folder($t, 'S', ['parent' => $A]);                   // saudara B (nama kembar untuk rename)
                $X = q69_folder($t, 'X');
                $Y = q69_folder($t, 'Y');
                $M1 = q69_folder($t, 'M1');                                   // sumber put-in yang sah (terpisah dari A>B>C agar siklus tetap utuh)
                $M2 = q69_folder($t, 'M2');
                $nameB = q69_name_of($t, $B);
                $f = q69_soft($t);

                // tanpa token
                foreach ([
                    'put-in'        => ['POST', Q69_API . 'documents/put-in', ['id_archive_parent' => $X, 'id_archives' => [$B]]],
                    'create-folder' => ['POST', Q69_API . 'archives/create-folder', ['name' => 'x', 'is_all_location' => 1]],
                    'rename'        => ['PUT', Q69_API . 'archives/rename/' . $B, ['name' => 'x']],
                    'PUT archives'  => ['PUT', Q69_API . 'archives/' . $B, ['name' => 'x', 'is_all_location' => 1]],
                    'select lokasi' => ['GET', 'api/v5/select/document-archive/archive/locations', null],
                ] as $label => $c) {
                    $r = $t->raw($c[0], $c[1], $c[2]);
                    $f->resp($r, 401, null, "$label tanpa token");
                }

                $rList = q69_role($t, 'list', ['List Archive']);
                $rMove = q69_role($t, 'move', ['List Archive', 'Move Archive']);
                $rAddDoc = q69_role($t, 'adddoc', ['List Archive', 'Add Document']);
                $rAddFolder = q69_role($t, 'addfolder', ['List Archive', 'Add Folder']);
                $rUpdate = q69_role($t, 'update', ['List Archive', 'Update Folder']);
                $snap = q69_snap($t, [$A, $B, $C, $D, $S, $X, $Y, $M1, $M2]);

                $bad = [
                    'put-in (induk acak)'         => function () use ($t, $s, $B) { return q69_putin($t, $s, Q69_RANDOM_ID, [$B]); },
                    'put-in (siklus)'             => function () use ($t, $s, $A, $C) { return q69_putin($t, $s, $C, [$A]); },
                    'create-folder (induk acak)'  => function () use ($t, $s) { return q69_create($t, $s, ['name' => q69_name('NEW'), 'is_all_location' => 1, 'id_archive_parent' => Q69_RANDOM_ID]); },
                    'rename (nama kembar)'        => function () use ($t, $s, $B, $S) { return q69_rename($t, $s, $B, q69_name_of($t, $S)); },
                    'rename (dokumen)'            => function () use ($t, $s, $D) { return q69_rename($t, $s, $D, q69_name('X')); },
                    'PUT (siklus)'                => function () use ($t, $s, $A, $C) { return q69_put($t, $s, $A, q69_body(q69_name_of($t, $A), ['id_archive_parent' => $C])); },
                    'PUT (induk acak)'            => function () use ($t, $s, $A) { return q69_put($t, $s, $A, q69_body(q69_name_of($t, $A), ['id_archive_parent' => Q69_RANDOM_ID])); },
                ];

                // role hanya List Archive: semua ditolak 403 GE0114 oleh middleware (sebelum validasi apa pun)
                q69_with_user($t, ['role' => $rList], function () use ($t, $s, $bad, $f) {
                    foreach ($bad as $label => $call) {
                        $r = $call();
                        $f->resp($r, 403, 'GE0114', "role List Archive saja: $label");
                    }
                    // select lokasi tidak memakai permission khusus: tetap terjawab (404 untuk induk tak ada)
                    $r = q69_loc($t, $s, ['id_archive_parent' => Q69_RANDOM_ID]);
                    $f->resp($r, 404, 'ARCHIVE400', 'select lokasi, induk tak ada, role List Archive saja');
                    $r = q69_loc($t, $s);
                    $f->resp($r, 200, null, 'select lokasi, role List Archive saja');
                });
                $f->ok(q69_snap($t, [$A, $B, $C, $D, $S, $X, $Y, $M1, $M2]) === $snap, 'role tanpa hak: data tidak berubah');

                // Move Archive saja: put-in lolos izin lalu divalidasi; selebihnya 403
                q69_with_user($t, ['role' => $rMove], function () use ($t, $s, $bad, $f, $M1, $X) {
                    $f->resp($bad['put-in (induk acak)'](), 422, null, 'Move Archive: put-in induk acak 422');
                    $f->resp($bad['put-in (siklus)'](), 400, 'ARCHIVE404', 'Move Archive: put-in siklus 400');
                    $f->resp($bad['create-folder (induk acak)'](), 403, 'GE0114', 'Move Archive: create-folder 403');
                    $f->resp($bad['rename (nama kembar)'](), 403, 'GE0114', 'Move Archive: rename 403');
                    $f->resp($bad['PUT (siklus)'](), 403, 'GE0114', 'Move Archive: PUT 403');
                    $r = q69_putin($t, $s, $X, [$M1]);
                    $f->resp($r, 200, 'ARCHIVE204', 'Move Archive: put-in sah 200');
                });
                // Add Document saja: put-in juga lolos middleware (Move Archive|Add Document)
                q69_with_user($t, ['role' => $rAddDoc], function () use ($t, $s, $bad, $f, $M2, $Y) {
                    $f->resp($bad['put-in (induk acak)'](), 422, null, 'Add Document: put-in induk acak 422');
                    $f->resp($bad['put-in (siklus)'](), 400, 'ARCHIVE404', 'Add Document: put-in siklus 400');
                    $f->resp($bad['rename (dokumen)'](), 403, 'GE0114', 'Add Document: rename 403');
                    $r = q69_putin($t, $s, $Y, [$M2]);
                    $f->resp($r, 200, 'ARCHIVE204', 'Add Document: put-in sah 200');
                });
                // Add Folder saja: create-folder divalidasi
                q69_with_user($t, ['role' => $rAddFolder], function () use ($t, $s, $bad, $f, $X) {
                    $f->resp($bad['create-folder (induk acak)'](), 422, null, 'Add Folder: create-folder induk acak 422');
                    $f->resp($bad['put-in (siklus)'](), 403, 'GE0114', 'Add Folder: put-in 403');
                    $r = q69_create($t, $s, ['name' => q69_name('NEW'), 'is_all_location' => 1, 'id_archive_parent' => $X]);
                    $f->resp($r, 200, null, 'Add Folder: create-folder sah 200');
                });
                // Update Folder saja: rename dan PUT divalidasi (PUT detail), put-in dan create 403
                q69_with_user($t, ['role' => $rUpdate], function () use ($t, $s, $bad, $f) {
                    $f->resp($bad['rename (nama kembar)'](), 400, 'ARCHIVE405', 'Update Folder: rename nama kembar 400');
                    $f->resp($bad['rename (dokumen)'](), 400, 'ARCHIVE419', 'Update Folder: rename dokumen 400');
                    $f->resp($bad['PUT (siklus)'](), 400, 'ARCHIVE404', 'Update Folder: PUT siklus 400');
                    $f->resp($bad['PUT (induk acak)'](), 422, null, 'Update Folder: PUT induk acak 422');
                    $f->resp($bad['put-in (siklus)'](), 403, 'GE0114', 'Update Folder: put-in 403');
                    $f->resp($bad['create-folder (induk acak)'](), 403, 'GE0114', 'Update Folder: create-folder 403');
                });
                q69_role_purge($t);

                // user nyata kedua (QA_USER2), bila bisa login/bind tanpa force_login: penolakan/validasi sesuai izin rolenya
                try {
                    $s2 = $t->login('QA_USER2');
                    $bound = $t->bind($s2, q69_dbname($t), null);
                    if ($bound[0] === 200) {
                        $r = $t->call($s2, 'POST', Q69_API . 'documents/put-in', ['id_archive_parent' => Q69_RANDOM_ID, 'id_archives' => [$B]]);
                        $t->note('QA_USER2 put-in induk acak -> HTTP ' . $r[0] . ' ' . (q69_code($r) ?? ''));
                        $f->ok($r[0] === 403 || $r[0] === 422, 'QA_USER2 put-in induk acak = 403 (tanpa Move Archive) atau 422 (punya izin)');
                        $r = $t->call($s2, 'GET', 'api/v5/select/document-archive/archive/locations?id_archive_parent=' . Q69_RANDOM_ID);
                        $f->resp($r, 404, 'ARCHIVE400', 'QA_USER2 select lokasi induk acak');
                        $t->call($s2, 'GET', 'api/v5/auth/log-out');
                    } else {
                        $t->note('QA_USER2 tidak bisa bind ke DB uji (HTTP ' . $bound[0] . '): dilewati, diganti role fixture');
                    }
                } catch (\QaHttp\Skipped $e) {
                    $t->note('QA_USER2 tidak diisi: dilewati, diganti role fixture');
                }

                $f->done();
            } finally {
                q69_purge($t);
                q69_role_purge($t);
            }
            q69_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-2',
        'title' => 'regresi aturan yang tidak berubah: create-folder (405 nama kembar, 402 lokasi), put-in (403 lokasi, 406 sumber, 404 tanpa sumber), PUT (402, 405); urutan 400 antar-aturan',
        'run'   => function ($t) {
            q69_recover($t);
            $s = $t->session();
            q69_purge($t);
            $base = q69_base($t);

            try {
                $locs = $t->db()->table('locations')->where('is_active', 1)->orderBy('id_location')->pluck('id_location')->all();
                $P = q69_folder($t, 'P');
                $Q = q69_folder($t, 'Q', ['parent' => $P]);
                $PL = q69_folder($t, 'PL', ['all_location' => 0, 'locations' => [$locs[0]]]);       // terbatas ke lokasi 0
                $PL2 = q69_folder($t, 'PL2', ['all_location' => 0, 'locations' => [$locs[1]]]);     // terbatas ke lokasi 1
                $DOCALL = q69_doc($t, 'DALL');                                                        // dokumen semua lokasi
                $FALL = q69_folder($t, 'FALL');                                                       // folder semua lokasi
                $FL1 = q69_folder($t, 'FL1', ['all_location' => 0, 'locations' => [$locs[0]]]);
                $nameQ = q69_name_of($t, $Q);
                $f = q69_soft($t);

                // create-folder: nama kembar di induk yang sama = 400 ARCHIVE405; di induk lain / root OK; induk sah = 200
                $r = q69_create($t, $s, ['name' => $nameQ, 'is_all_location' => 1, 'id_archive_parent' => $P]);
                $f->resp($r, 400, 'ARCHIVE405', 'create-folder nama kembar');
                $r = q69_create($t, $s, ['name' => $nameQ, 'is_all_location' => 1]);
                $f->resp($r, 200, null, 'create-folder nama sama di root (induk lain) = 200');
                $newId = $t->db()->table('archives')->where('name', $nameQ)->whereNull('id_archive_parent')->value('id_archive');
                $f->ok($newId !== null && strpos(q69_name_of($t, $newId), Q69_PREFIX) === 0, 'folder baru tercatat (dibuang di akhir)');
                $f->eq(array_slice(q69_actions($t, $newId), -1), ['create'], 'history create');
                // create-folder semua lokasi di bawah induk terbatas = 400 ARCHIVE402 (aturan lokasi tetap jalan)
                $r = q69_create($t, $s, ['name' => q69_name('NEW'), 'is_all_location' => 1, 'id_archive_parent' => $PL]);
                $f->resp($r, 400, 'ARCHIVE402', 'create-folder semua lokasi di bawah induk terbatas');
                // PUT: lokasi dan nama kembar tetap
                $r = q69_put($t, $s, $Q, ['name' => $nameQ, 'is_all_location' => 1, 'id_archive_parent' => $PL]);
                $f->resp($r, 400, 'ARCHIVE402', 'PUT Q (semua lokasi) ke induk terbatas');
                $r = q69_put($t, $s, $FALL, q69_body($nameQ, ['id_archive_parent' => $P]));
                $f->resp($r, 400, 'ARCHIVE405', 'PUT FALL ke P dengan nama = nama Q (kembar)');

                // put-in: lokasi dokumen vs folder = 400 ARCHIVE403; sumber id_archives + name = 406; tanpa sumber = 404
                $r = q69_putin($t, $s, $PL, [$DOCALL]);
                $f->resp($r, 400, 'ARCHIVE403', 'put-in dokumen semua lokasi ke folder terbatas');
                $r = q69_putin($t, $s, $PL, [$FL1]);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in folder lokasi cocok ke folder terbatas = 200');
                $r = q69_putin($t, $s, $PL2, [$FL1]);
                $f->resp($r, 400, 'ARCHIVE403', 'put-in folder lokasi 0 ke folder lokasi 1');
                $r = q69_putin($t, $s, $P, [$FALL], q69_name_of($t, $FALL));
                $f->resp($r, 400, 'ARCHIVE406', 'put-in id_archives + name = 406');
                $r = q69_putin($t, $s, $P);
                $f->resp($r, 400, 'ARCHIVE406', 'put-in tanpa sumber = 406');
                $r = q69_putin($t, $s, $P, [Q69_RANDOM_ID]);
                $f->resp($r, 404, 'ARCHIVE400', 'put-in sumber tak ada = 404');
                $r = q69_putin($t, $s, $P, null, 'QA69-TIDAK-ADA');
                $f->resp($r, 404, 'ARCHIVE400', 'put-in name tak ada = 404');

                // urutan antar-400 (Catatan implementasi): ARCHIVE404 -> ARCHIVE403 -> ARCHIVE406 -> ARCHIVE405
                $A = q69_folder($t, 'OA');                                                       // semua lokasi
                $B = q69_folder($t, 'OB', ['parent' => $A, 'all_location' => 0, 'locations' => [$locs[0]]]);  // anak terbatas
                $r = q69_putin($t, $s, $B, [$A]);
                $f->resp($r, 400, 'ARCHIVE404', 'siklus + lokasi tak cocok: ARCHIVE404 menang atas ARCHIVE403');
                $S1 = q69_folder($t, 'S1', ['parent' => $P, 'name' => q69_name('SS')]);
                $S2 = q69_folder($t, 'S2', ['parent' => $Q, 'name' => q69_name_of($t, $S1)]);
                $r = q69_putin($t, $s, $PL, [$S1, $S2]);
                $f->resp($r, 400, 'ARCHIVE403', 'lokasi tak cocok + sumber senama: ARCHIVE403 menang atas ARCHIVE405');
                $r = q69_putin($t, $s, $PL, [$DOCALL], 'x');
                $f->resp($r, 400, 'ARCHIVE403', 'lokasi tak cocok + id_archives & name sekaligus: ARCHIVE403 menang atas ARCHIVE406');
                $r = q69_putin($t, $s, $P, [$S1, $S2], 'x');
                $f->resp($r, 400, 'ARCHIVE406', 'sumber senama + name sekaligus: ARCHIVE406 menang atas ARCHIVE405');
                $r = q69_putin($t, $s, $P, [$S1, $S2]);
                $f->resp($r, 400, 'ARCHIVE405', 'sumber senama saja: ARCHIVE405');

                $f->done();
            } finally {
                q69_purge($t);
            }
            q69_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-3',
        'title' => 'ketahanan tipe input: put-in, create-folder, PUT, rename, select lokasi dengan array/objek/angka/bool/panjang/kutip = tanpa 5xx (4xx/2xx), 422 untuk bentuk salah',
        'run'   => function ($t) {
            q69_recover($t);
            $s = $t->session();
            q69_purge($t);
            $base = q69_base($t);

            try {
                $A = q69_folder($t, 'A');
                $B = q69_folder($t, 'B', ['parent' => $A]);
                $X = q69_folder($t, 'X');
                $nameB = q69_name_of($t, $B);
                $snap = q69_snap($t, [$A, $B, $X]);
                $f = q69_soft($t);

                $values = [
                    'array kosong' => [], 'array [x]' => ['x'], 'array berisi id sah' => [$X], 'objek' => ['a' => 'b'], 'angka 0' => 0, 'angka 123' => 123,
                    'float 1.5' => 1.5, 'true' => true, 'false' => false, 'string spasi' => ' ', 'string "null"' => 'null', 'string 4000' => str_repeat('a', 4000),
                    'kutip' => "x' OR '1'='1", 'garis miring' => '../../etc', 'persen' => '%', 'string 255 digit' => str_repeat('1', 255),
                ];
                $shapeWrong = ['array kosong', 'array [x]', 'array berisi id sah', 'objek', 'angka 0', 'angka 123', 'float 1.5', 'true', 'false'];

                foreach ($values as $label => $v) {
                    $rs = [
                        'put-in parent'   => $t->call($s, 'POST', Q69_API . 'documents/put-in', ['id_archive_parent' => $v, 'id_archives' => [$B]]),
                        'create parent'   => q69_create($t, $s, ['name' => q69_name('FZ'), 'is_all_location' => 1, 'id_archive_parent' => $v]),
                        'PUT parent'      => q69_put($t, $s, $B, q69_body($nameB, ['id_archive_parent' => $v])),
                    ];
                    // hanya induk " " (dipangkas middleware menjadi null = root) yang boleh membuat folder; bentuk lain tidak
                    if ($rs['create parent'][0] === 200) {
                        $f->eq($label, 'string spasi', 'create-folder berhasil hanya untuk induk " " (= root)');
                        $made = $t->db()->table('archives')->where('name', 'like', Q69_PREFIX . 'FZ%')->pluck('id_archive')->all();
                        q69_w($t, function ($c) use ($made) {
                            $c->table('archives')->whereIn('id_archive', $made)->delete();
                        });
                    }
                    foreach ($rs as $ep => $r) {
                        $f->no5xx($r, "$ep = $label");
                        if (in_array($label, $shapeWrong, true)) {
                            $f->resp($r, 422, null, "$ep = $label (bentuk salah)");
                        }
                    }
                }
                // sumber put-in bentuk aneh
                foreach (['id_archives teks' => ['id_archive_parent' => $X, 'id_archives' => $B], 'id_archives objek' => ['id_archive_parent' => $X, 'id_archives' => ['k' => $B]],
                          'id_archives bersarang' => ['id_archive_parent' => $X, 'id_archives' => [[$B]]], 'id_archives angka' => ['id_archive_parent' => $X, 'id_archives' => [5]],
                          'name array' => ['id_archive_parent' => $X, 'name' => ['x']], 'name objek' => ['id_archive_parent' => $X, 'name' => ['a' => 'b']],
                          'body kosong' => [], 'id_archives 600 id' => ['id_archive_parent' => $X, 'id_archives' => array_fill(0, 600, Q69_RANDOM_ID)]] as $label => $body) {
                    $r = $t->call($s, 'POST', Q69_API . 'documents/put-in', $body);
                    $f->no5xx($r, "put-in $label");
                }
                // rename name bentuk aneh
                foreach (['array' => ['x'], 'objek' => ['a' => 'b'], 'angka' => 5, 'null' => null, 'string 4000' => str_repeat('n', 4000)] as $label => $v) {
                    $r = q69_rename($t, $s, $B, $v);
                    $f->no5xx($r, "rename name = $label");
                }
                // select lokasi
                foreach (['id_archive_parent[]=x', 'id_archive_parent[a]=1', 'id_archive_parent=' . str_repeat('a', 4000), 'id_archive_parent=%27', 'id_archive_parent=null', 'id_archive_parent=%20'] as $q) {
                    $r = $t->call($s, 'GET', 'api/v5/select/document-archive/archive/locations?' . $q);
                    $f->no5xx($r, "select lokasi ?$q");
                }

                $r = q69_rename($t, $s, $B, $nameB);
                $f->resp($r, 200, null, 'rename ke nama sendiri masih 200 (pulih)');
                // catatan: induk " " dipangkas middleware menjadi null (= root), sehingga B boleh pindah ke root oleh kasus itu
                $f->ok(q69_parent($t, $A) === null && q69_parent($t, $X) === null, 'A dan X tetap di root oleh seluruh input aneh');
                $f->eq([q69_name_of($t, $A), q69_name_of($t, $X)], [q69_name_of($t, $A), q69_name_of($t, $X)], 'terbaca');
                $f->eq($t->db()->table('archives')->where('name', 'like', Q69_PREFIX . 'FZ%')->count(), 0, 'tidak ada folder FZ tersisa');

                $f->done();
            } finally {
                q69_purge($t);
            }
            q69_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-4',
        'title' => 'petunjuk orkestrator (a): folder bernama sama dengan nomor transaksi terkait + showRelatedTransaction = 200 tanpa folder (diperbaiki ED-1124 AC-1); (b): put-in id_archives sumber dihapus = 404 ARCHIVE400 tanpa perubahan (K-1 a, ED-1124 AC-4)',
        'run'   => function ($t) {
            q69_recover($t);
            $s = $t->session();
            q69_purge($t);
            $base = q69_base($t);

            try {
                $f = q69_soft($t);

                // (a) pilih dokumen nyata tipe 7 (DO) yang punya transaksi terkait: salin id_transaction ke dokumen uji
                $F = q69_folder($t, 'F');
                $found = null;
                $cands = $t->db()->table('archive_documents')->where('transaction_type', 7)->where('id_transaction', 'not like', 'QA69TX%')
                    ->orderBy('id_archive_document')->distinct()->limit(12)->pluck('id_transaction')->all();
                $search = json_encode(['showRelatedTransaction' => true]);
                foreach ($cands as $i => $idTx) {
                    $doc = q69_doc($t, 'R' . $i, ['parent' => $F, 'doc' => ['type' => 7, 'tx' => $idTx]]);
                    $r = $t->call($s, 'GET', Q69_API . 'archives?pagination=100&id_archive=' . $F . '&search=' . urlencode($search));
                    foreach ($r[1]['result']['data'] ?? [] as $row) {
                        if ($row['id_archive'] === $doc && !empty($row['related_transactions'])) {
                            $found = [$doc, $row['related_transactions'][0]['name'], $row['related_transactions']];
                            break 2;
                        }
                    }
                }
                if ($found === null) {
                    $t->note('MANUAL: tidak ada dokumen DO nyata dengan transaksi terkait yang ketemu dalam 12 kandidat; petunjuk (a) tidak diuji');
                } else {
                    list($doc, $relatedNo, $relatedBefore) = $found;
                    // folder (bukan dokumen) bernama persis nomor transaksi terkait itu; ED-1124 K-3: di dalam folder uji QA, bukan root
                    $FG = q69_folder($t, 'FG');
                    $fid = q69_add($t, ['name' => $relatedNo, 'type' => 1, 'parent' => $FG]);
                    q69_track($fid);
                    $r = $t->call($s, 'GET', Q69_API . 'archives?pagination=100&id_archive=' . $F . '&search=' . urlencode($search));
                    $t->note('petunjuk (a): folder bernama "' . $relatedNo . '" (nomor transaksi terkait dokumen DO uji) -> list showRelatedTransaction = HTTP ' . $r[0] . ' ' . substr(json_encode($r[1]), 0, 260));
                    // ED-1124 AC-1 (karakterisasi usang dibalik, ED-1022 Gate 1 F-4): relatedTransaction() hanya memuat dokumen (type FILE
                    // ber-archive_documents); folder senama nomor transaksi tidak ikut -> 200 (dulu 500 ArchiveService.php:701).
                    $f->resp($r, 200, null, 'petunjuk (a): folder senama nomor transaksi terkait = HTTP 200 (ED-1124, dulu 500)');
                    $rowsNow = [];
                    foreach ($r[1]['result']['data'] ?? [] as $row) {
                        $rowsNow[$row['id_archive']] = $row;
                    }
                    $f->eq(json_encode($rowsNow[$doc]['related_transactions'] ?? 'tidak ada'), json_encode($relatedBefore), 'petunjuk (a): related_transactions D sama dengan sebelum folder ada');
                    foreach ($rowsNow[$doc]['related_transactions'] ?? [] as $rt) {
                        $f->ok($rt['id_archive'] !== $fid, 'petunjuk (a): folder senama tidak ikut di related_transactions');
                    }
                    q69_purge($t);
                }

                // (b) put-in sumber nonaktif: apakah is_active menjadi 1
                q69_purge($t);
                $T = q69_folder($t, 'T');
                $dOff = q69_doc($t, 'DOFF', ['active' => 0]);
                $fOff = q69_folder($t, 'FOFF', ['active' => 0]);
                $r = q69_putin($t, $s, $T, [$dOff, $fOff]);
                $t->note('petunjuk (b): put-in [dokumen nonaktif, folder nonaktif] -> HTTP ' . $r[0] . ' ' . (q69_code($r) ?? '') . '; is_active dokumen=' . q69_row($t, $dOff)['is_active'] . ', folder=' . q69_row($t, $fOff)['is_active']);
                // ED-1124 AC-4 (K-1 a, ED-1022 Gate 1 F-4): sumber is_active = 0 lewat id_archives = id tak ada: semua dihapus -> 404 ARCHIVE400, tanpa perubahan
                $f->resp($r, 404, 'ARCHIVE400', 'put-in sumber dihapus (semua) = 404 ARCHIVE400 (ED-1124, dulu 200 dan aktif lagi)');
                $f->eq([q69_row($t, $dOff)['is_active'], q69_row($t, $fOff)['is_active']], [0, 0], 'sumber dihapus tetap is_active 0 (ED-1124)');
                $f->eq([q69_parent($t, $dOff), q69_parent($t, $fOff)], [null, null], 'sumber dihapus tetap di tempat lama (ED-1124)');

                $f->done();
            } finally {
                q69_purge($t);
            }
            q69_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-5',
        'title' => 'tinjauan diff BE: tidak ada state statis/cache/mutasi config baru (isolasi tenant; QA_DBS = 1 DB, isolasi antar-DB tidak bisa diuji langsung); php -l semua berkas berubah',
        'run'   => function ($t) {
            $be = $t->conf('BE_DIR');
            $files = [];
            foreach (preg_split('/\R/', (string) file_get_contents(__DIR__ . '/../changed.txt')) as $line) {
                $cols = preg_split('/\s+/', trim($line));
                if (count($cols) >= 4 && $cols[0] === 'be' && substr($cols[3], -4) === '.php') {
                    $files[] = $cols[3];
                }
            }
            $t->true(count($files) >= 14, 'berkas PHP BE di changed.txt = 14 (ada ' . count($files) . ')');
            $f = q69_soft($t);

            $added = [];
            foreach ($files as $path) {
                $diff = (string) shell_exec('git -C ' . escapeshellarg($be) . ' diff HEAD -- ' . escapeshellarg($path) . ' 2>/dev/null');
                $tracked = trim((string) shell_exec('git -C ' . escapeshellarg($be) . ' ls-files --error-unmatch ' . escapeshellarg($path) . ' 2>/dev/null')) !== '';
                if ($tracked && $diff === '') {
                    $t->note("$path sudah di-commit: tinjauan baris tambahan dilewati (php -l tetap)");
                } else {
                    $text = $diff !== '' ? $diff : (string) file_get_contents($be . '/' . $path);   // berkas baru (untracked) dibaca utuh
                    foreach (preg_split('/\R/', $text) as $line) {
                        if ($diff !== '' && (!isset($line[0]) || $line[0] !== '+' || strpos($line, '+++') === 0)) {
                            continue;
                        }
                        $added[$path][] = $line;
                    }
                }
                $php = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($be . '/' . $path) . ' 2>&1');
                $f->ok(strpos($php, 'No syntax errors') !== false, "php -l $path");
            }
            $hazards = [];
            foreach ($added as $path => $lines) {
                foreach ($lines as $line) {
                    $code = preg_replace('/\/\/.*$/', '', $line);
                    if (preg_match('/\b(static\s+\$|static::\$|self::\$[a-zA-Z_]+\s*=|Cache::|config\s*\(\s*\[|\$GLOBALS|putenv|setDefaultConnection|DB::purge|date_default_timezone_set|ini_set)/', $code)) {
                        $hazards[] = $path . ': ' . trim($line);
                    }
                }
            }
            $f->eq($hazards, [], 'baris yang ditambahkan tanpa static/Cache/config()/GLOBALS/setDefaultConnection');
            $t->note('QA_DBS = ' . count($t->dbs()) . ' DB: isolasi tenant bergantian tidak bisa diuji; diganti tinjauan diff ini');
            $f->done();
        },
    ],

];
