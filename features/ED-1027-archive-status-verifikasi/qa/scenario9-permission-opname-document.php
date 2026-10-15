<?php
/**
 * ED-1027 (REVISI 2026-10-08) - verifikasi hanya untuk pemegang permission "Opname Document" (K-9 a = EPIC K-3 a, BR-15/18/19):
 *   AC-22  GET archives tanpa Opname Document: columns tanpa documentVerified, baris tanpa document_verified, result tanpa
 *          document_verified_summary; sisanya identik dengan user yang SAMA ber-Opname Document (preset QA27-ROLE-LIST + grant sementara);
 *   AC-23  GET verifications / verifications/{id}: 403 GE0114 tanpa result tanpa Opname Document; 200 dengan (AC-5/6);
 *   X-10   search / sorts yang menyebut kolom verifikasi archives (is_verified, verified_at, verified_by, id_archive_opname) diabaikan
 *          tanpa Opname Document (fix r1, kontrak §1), berfungsi bagi pemegang;
 *   X-11   GET archives/{id}: key kolom verifikasi tidak ada tanpa Opname Document (fix r1b, kontrak §1b).
 * Pasangan "dengan / tanpa" = preset QA27-ROLE-LIST (List Archive saja; ED-1149: bukan role 29) yang SAMA, dengan q27_with_grant (baris role_permissions
 * sementara created_by QA27, dicabut persis; bukti jumlah+CRC). Data uji dipulihkan di finally (q27_cleanup).
 */
require_once __DIR__ . '/qa_lib.php';

/** Urutan data_index kolom (array data_index digabung titik). */
$q27_seq9 = function (array $columns) {
    return array_map(function ($col) {
        return is_array($col['data_index']) ? implode('.', $col['data_index']) : $col['data_index'];
    }, $columns);
};

/** Result list tanpa field verifikasi (untuk dibandingkan dengan respons tanpa Opname Document). */
$q27_strip9 = function (array $res) use ($q27_seq9) {
    unset($res['document_verified_summary']);
    foreach ($res['data'] ?? [] as $i => $row) {
        unset($res['data'][$i]['document_verified']);
    }
    $res['columns'] = array_values(array_filter($res['columns'] ?? [], function ($col) {
        return ($col['data_index'] ?? null) !== 'documentVerified';
    }));

    return $res;
};

/** Daftar id_archive berurutan dari respons list. */
$q27_idseq = function ($r) {
    return array_column($r[1]['result']['data'] ?? [], 'id_archive');
};

return [

    [
        'id'    => 'AC-22',
        'title' => '(rev) GET archives tanpa Opname Document (preset LIST): columns tanpa documentVerified, baris tanpa key document_verified, result tanpa document_verified_summary; baris, urutan, paginasi, field lain identik dengan user yang sama ber-Opname Document (root, folder, search, paginasi, sort)',
        'run'   => function ($t) use ($q27_seq9, $q27_strip9) {
            $s = $t->session();
            q27_baseline($t);
            try {
                $x = q27_tree($t);
                q27_with_user($t, ['role' => Q27_PRESET_LIST, 'emp' => ['SMR']], function () use ($t, $s, $x, $q27_seq9, $q27_strip9) {
                    $shapes = [
                        'root'                       => [],
                        'folder P'                   => ['id_archive' => $x['P']],
                        'folder F'                   => ['id_archive' => $x['F']],
                        'folder F (halaman 2, 3/hal)' => ['id_archive' => $x['F'], 'pagination' => 3, 'page' => 2],
                        'folder S1 (daun, dokumen)'  => ['id_archive' => $x['S1']],
                        'search query'               => ['search' => q27_search(['query' => Q27_PREFIX])],
                        'search type 7'              => ['search' => q27_search(['type' => '7', 'query' => Q27_PREFIX])],
                        'search type FOLDER'         => ['search' => q27_search(['type' => 'FOLDER', 'query' => Q27_PREFIX])],
                        'search tanpa hasil'         => ['search' => q27_search(['query' => 'QA27-TIDAK-ADA-' . uniqid()])],
                        'sort name desc'             => ['id_archive' => $x['F'], 'sorts' => [json_encode(['sortBy' => 'name', 'sortType' => 'desc'])]],
                        'sort documentVerified'      => ['id_archive' => $x['F'], 'sorts' => [json_encode(['sortBy' => 'documentVerified', 'sortType' => 'desc'])]],
                    ];

                    // --- tanpa Opname Document
                    $no = [];
                    $msNo = [];
                    foreach ($shapes as $label => $q) {
                        $t0 = microtime(true);
                        $r = q27_list($t, $s, $q);
                        $msNo[$label] = (int) round((microtime(true) - $t0) * 1000);
                        $t->status($r, 200, "tanpa OD [$label]");
                        $no[$label] = $r;
                        $res = $r[1]['result'];
                        $t->true(!in_array('documentVerified', $q27_seq9($res['columns']), true), "tanpa OD [$label]: columns tanpa documentVerified");
                        $t->true(!array_key_exists('document_verified_summary', $res), "tanpa OD [$label]: result tanpa key document_verified_summary");
                        $bad = 0;
                        foreach ($res['data'] as $row) {
                            if (array_key_exists('document_verified', $row)) {
                                $bad++;
                            }
                        }
                        $t->eq($bad, 0, "tanpa OD [$label]: 0 baris dengan key document_verified (dari " . count($res['data']) . ' baris)');
                        // (URL paginasi memantulkan query request itu sendiri, mis. sorts[0]={"sortBy":"documentVerified"}: dikecualikan)
                        $echo = $r[1];
                        foreach (['first_page_url', 'last_page_url', 'next_page_url', 'prev_page_url', 'path'] as $u) {
                            unset($echo['result'][$u]);
                        }
                        $t->true(stripos(json_encode($echo), 'document_verified') === false && stripos(json_encode($echo), 'documentVerified') === false, "tanpa OD [$label]: body tanpa jejak document_verified/documentVerified");
                    }
                    $t->true(count($no['root'][1]['result']['data']) > 0 && count($no['folder F'][1]['result']['data']) > 0, 'tanpa OD: root dan folder F berisi baris (list tetap berfungsi)');

                    // --- dengan Opname Document (role yang sama)
                    $msYes = [];
                    q27_with_grant($t, Q27_PRESET_LIST, Q27_OD, function () use ($t, $s, $shapes, $no, $q27_seq9, $q27_strip9, &$msYes) {
                        foreach ($shapes as $label => $q) {
                            $t0 = microtime(true);
                            $r = q27_list($t, $s, $q);
                            $msYes[$label] = (int) round((microtime(true) - $t0) * 1000);
                            $t->status($r, 200, "dengan OD [$label]");
                            $res = $r[1]['result'];
                            $t->true(in_array('documentVerified', $q27_seq9($res['columns']), true), "dengan OD [$label]: columns memuat documentVerified");
                            $t->true(array_key_exists('document_verified_summary', $res), "dengan OD [$label]: result memuat document_verified_summary");
                            foreach ($res['data'] as $row) {
                                if (!array_key_exists('document_verified', $row)) {
                                    $t->fail("dengan OD [$label]: baris {$row['id_archive']} tanpa key document_verified");
                                }
                            }
                            // identik setelah field verifikasi dibuang: baris, urutan, paginasi, field lain
                            $t->eq(json_encode($q27_strip9($res)), json_encode($no[$label][1]['result']), "[$label]: dengan OD (field verifikasi dibuang) identik dengan tanpa OD");
                            $t->eq(array_column($res['data'], 'id_archive'), array_column($no[$label][1]['result']['data'], 'id_archive'), "[$label]: urutan baris identik");
                        }
                    });
                    $t->note('waktu GET archives root (ms): tanpa OD ' . $msNo['root'] . ' vs dengan OD ' . $msYes['root'] . ' (hitungan verifikasi dilewati tanpa OD)');

                    // --- permission dicabut lagi: kembali tanpa field (tidak ada cache lintas request)
                    $r = q27_list($t, $s, ['id_archive' => $x['F']]);
                    $t->true(!array_key_exists('document_verified_summary', $r[1]['result']), 'sesudah grant dicabut: kembali tanpa document_verified_summary');
                });
            } finally {
                q27_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-23',
        'title' => '(rev) role List Archive tanpa Opname Document: GET verifications dan /{id folder} 403 GE0114 (parameter Opname Document) tanpa result, permission dicek sebelum id/lokasi/View; dengan Opname Document 200 (header All = ringkasan list, header folder = document_verified folder di list induknya)',
        'run'   => function ($t) use ($q27_seq9) {
            $s = $t->session();
            q27_baseline($t);
            try {
                $x = q27_tree($t);
                q27_with_user($t, ['role' => Q27_PRESET_LIST, 'emp' => ['SMR']], function () use ($t, $s, $x) {
                    $paths = [
                        'GET verifications'                 => '/verifications',
                        'GET verifications/{F}'             => '/verifications/' . $x['F'],
                        'GET verifications/{id tak ada}'    => '/verifications/' . Q27_RANDOM_ID,
                        'GET verifications/{dokumen}'       => '/verifications/' . $x['dF1'],
                        'GET verifications/{SP tanpa View}' => '/verifications/' . $x['SP'],
                        'GET verifications/{SJ lokasi lain}' => '/verifications/' . $x['SJ'],
                        'GET verifications/{id aneh}'       => '/verifications/a%20b',
                    ];
                    foreach (['EN', 'ID'] as $lang) {
                        q27_user_apply_lang($t, $lang);
                        foreach ($paths as $label => $path) {
                            $r = $t->call($s, 'GET', Q27_BASE . $path);
                            q27_deny($t, $r, 403, 'GE0114', "[$lang] tanpa OD: $label");
                            $t->true(empty($r[1]['result']), "[$lang] tanpa OD: $label tanpa result");
                            $body = json_encode($r[1]);
                            $t->true(strpos($body, Q27_OD) !== false, "[$lang] tanpa OD: $label: parameter pesan = Opname Document");
                            $t->true(strpos($body, 'transaction_types') === false && strpos($body, 'last_sessions') === false && strpos($body, '"verified"') === false, "[$lang] tanpa OD: $label: body tanpa angka verifikasi");
                        }
                    }
                });

                q27_with_user($t, ['role' => Q27_PRESET_LIST, 'emp' => ['SMR']], function () use ($t, $s, $x) {
                    q27_with_grant($t, Q27_PRESET_LIST, Q27_OD, function () use ($t, $s, $x) {
                        $rl = q27_list($t, $s, ['id_archive' => $x['P']]);
                        $t->status($rl, 200, 'dengan OD: list P');
                        $rv = q27_verif($t, $s);
                        $t->status($rv, 200, 'dengan OD: GET verifications');
                        $t->eq([$rv[1]['result']['verified'], $rv[1]['result']['total']], q27_vt(['v' => $rl[1]['result']['document_verified_summary']['verified'], 't' => $rl[1]['result']['document_verified_summary']['total']]), 'dengan OD: header All = ringkasan list');
                        $rf = q27_verif($t, $s, $x['F']);
                        $t->status($rf, 200, 'dengan OD: GET verifications/{F}');
                        $t->eq([$rf[1]['result']['verified'], $rf[1]['result']['total']], q27_api_vt(q27_map($rl)[$x['F']]['document_verified']), 'dengan OD: header F = document_verified F di list P');
                        $t->eq($rf[1]['result']['id_archive'], $x['F'], 'dengan OD: id_archive F');
                        // urutan cek (kontrak §3): permission -> id tak ada 404 -> lokasi 403 -> dokumen 400 -> View 403
                        q27_deny($t, q27_verif($t, $s, Q27_RANDOM_ID), 404, 'ARCHIVE400', 'dengan OD: id tak ada 404 ARCHIVE400');
                        q27_deny($t, q27_verif($t, $s, $x['dF1']), 400, 'ARCHIVE417', 'dengan OD: dokumen 400 ARCHIVE417');
                        q27_deny($t, q27_verif($t, $s, $x['SJ']), 403, 'ARCHIVE407', 'dengan OD: folder lokasi lain 403 ARCHIVE407');
                        q27_deny($t, q27_verif($t, $s, $x['SP']), 403, 'ARCHIVE407', 'dengan OD: folder tanpa View 403 ARCHIVE407');
                    });
                    // grant dicabut: 403 lagi
                    q27_deny($t, q27_verif($t, $s), 403, 'GE0114', 'grant dicabut: GET verifications 403 lagi');
                });

                // role bawaan QA_USER (3) memegang Opname Document: 200 (kontrol)
                $t->status(q27_verif($t, $s), 200, 'role bawaan (punya OD): GET verifications 200');
                $t->status(q27_verif($t, $s, $x['F']), 200, 'role bawaan (punya OD): GET verifications/{F} 200');
            } finally {
                q27_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'X-10',
        'title' => '(fix r1, kontrak §1) tanpa Opname Document key search / item sorts yang menyebut kolom verifikasi archives diabaikan seolah tidak dikirim (200, tanpa 500, hasil = list tanpa key itu); pemegang Opname Document: search/sort kolom itu berfungsi (tidak berubah)',
        'run'   => function ($t) use ($q27_idseq) {
            $s = $t->session();
            q27_baseline($t);
            try {
                $x = q27_tree($t);
                q27_with_user($t, ['role' => Q27_PRESET_LIST, 'emp' => ['SMR']], function () use ($t, $s, $x, $q27_idseq) {
                    $base = q27_list($t, $s);
                    $t->status($base, 200, 'basis: root biasa');
                    $baseIds = $q27_idseq($base);
                    $t->true(count($baseIds) > 5, 'basis: root berisi baris (' . count($baseIds) . ')');
                    $baseType7 = q27_list($t, $s, ['search' => q27_search(['type' => '7'])]);
                    $t->status($baseType7, 200, 'basis: search type 7');
                    $type7Ids = $q27_idseq($baseType7);

                    // --- search: key verifikasi saja -> sama dengan root biasa
                    $keys = [
                        'isVerified' => 1, 'is_verified' => 1, 'IsVerified' => 1, 'verifiedBy' => 'QA27', 'verified_by' => 'QA27',
                        'verifiedAt' => '2026-01-01', 'verified_at' => '2026-01-01', 'idArchiveOpname' => 'x', 'id_archive_opname' => 'x',
                        'archives.is_verified' => 1, 'archives.verified_by' => 'QA27', 'whereIsVerified()' => 1,
                        'document' => ['isVerified' => 1],
                    ];
                    foreach ($keys as $k => $v) {
                        $r = q27_list($t, $s, ['search' => q27_search([$k => $v])]);
                        $t->true($r[0] < 500, "tanpa OD search {$k}: HTTP {$r[0]} (tidak 500)");
                        $t->status($r, 200, "tanpa OD search {$k}");
                        $t->eq($q27_idseq($r), $baseIds, "tanpa OD search {$k}: hasil = root biasa (key diabaikan)");
                        $t->eq($r[1]['result']['total'], $base[1]['result']['total'], "tanpa OD search {$k}: total = root biasa");
                        // dicampur key lain: hanya key verifikasi yang dibuang
                        $r2 = q27_list($t, $s, ['search' => q27_search([$k => $v, 'type' => '7'])]);
                        $t->status($r2, 200, "tanpa OD search {$k}+type");
                        $t->eq($q27_idseq($r2), $type7Ids, "tanpa OD search {$k}+type 7: hasil = search type 7 saja");
                    }

                    // --- sorts: item verifikasi dibuang -> urutan default
                    $baseF = q27_list($t, $s, ['id_archive' => $x['F']]);
                    $baseFIds = $q27_idseq($baseF);
                    foreach (['isVerified', 'is_verified', 'verifiedAt', 'verifiedBy', 'idArchiveOpname', 'archives.is_verified'] as $sb) {
                        foreach (['desc', 'asc'] as $dir) {
                            $r = q27_list($t, $s, ['id_archive' => $x['F'], 'sorts' => [json_encode(['sortBy' => $sb, 'sortType' => $dir])]]);
                            $t->status($r, 200, "tanpa OD sorts {$sb} {$dir}");
                            $t->eq($q27_idseq($r), $baseFIds, "tanpa OD sorts {$sb} {$dir}: urutan default");
                        }
                    }
                    // sort verifikasi + sort name: hanya name yang berlaku
                    $rn = q27_list($t, $s, ['id_archive' => $x['F'], 'sorts' => [json_encode(['sortBy' => 'name', 'sortType' => 'desc'])]]);
                    $rm = q27_list($t, $s, ['id_archive' => $x['F'], 'sorts' => [json_encode(['sortBy' => 'isVerified', 'sortType' => 'desc']), json_encode(['sortBy' => 'name', 'sortType' => 'desc'])]]);
                    $t->status($rm, 200, 'tanpa OD sorts isVerified+name');
                    $t->eq($q27_idseq($rm), $q27_idseq($rn), 'tanpa OD sorts isVerified+name: sama dengan sort name saja');
                    // bentuk array sorts[0][sortBy]=... (FE tidak memakainya; req_snake mengubah kunci jadi sort_by): dulu 500 untuk kolom apa pun;
                    // sejak ED-1070 = 200, entri dilewati + log [CustomizeBuilder][malformed] (CustomizeBuilder::scopeSortAll) -> dibandingkan dengan kolom biasa
                    $ra = $t->call($s, 'GET', q27_url(Q27_BASE . '/archives', ['pagination' => 1000, 'id_archive' => $x['F'], 'sorts' => [['sortBy' => 'isVerified', 'sortType' => 'desc']]]));
                    $rb = $t->call($s, 'GET', q27_url(Q27_BASE . '/archives', ['pagination' => 1000, 'id_archive' => $x['F'], 'sorts' => [['sortBy' => 'name', 'sortType' => 'desc']]]));
                    $t->note('CATATAN (ED-1070: dulu 500, kini 200 + log malformed): tanpa OD sorts bentuk array isVerified = HTTP ' . $ra[0] . ', name = HTTP ' . $rb[0]);
                    $t->eq($rb[0], 200, 'tanpa OD sorts bentuk array name: 200 (ED-1070)');
                    $t->eq($ra[0], $rb[0], 'tanpa OD sorts bentuk array: kolom verifikasi berperilaku sama dengan kolom biasa (name) - tidak ada jalur baru');

                    // --- pemegang Opname Document: search/sort kolom verifikasi berfungsi.
                    // (ED-1149 K-2 a / BR-5) Dibatasi ke baris QA sendiri: query = prefiks QA27- (kolom nama); hasil = TEPAT baris QA yang terlihat
                    // user (aktif + lokasi SMR) dengan is_verified sesuai, id sama dengan oracle DB. TIDAK dibandingkan dengan total root/DB
                    // (memuat data developer, mis. 343+ baris verified updated_by 'sam' yang tidak disentuh).
                    q27_with_grant($t, Q27_PRESET_LIST, Q27_OD, function () use ($t, $s, $x, $q27_idseq, $baseFIds) {
                        $qa = q27_qa_rows($t, ['SMR']);
                        $expAll = array_keys($qa);
                        $expV = array_keys(array_filter($qa, function ($v) {
                            return $v === 1;
                        }));
                        $expU = array_keys(array_filter($qa, function ($v) {
                            return $v === 0;
                        }));
                        $t->true(count($expV) >= 6 && count($expU) >= 6, 'oracle QA: baris QA terverifikasi (' . count($expV) . ') dan belum (' . count($expU) . ') cukup untuk membedakan filter');
                        $sorted = function (array $ids) {
                            sort($ids);

                            return $ids;
                        };
                        $rq = q27_list($t, $s, ['search' => q27_search(['query' => Q27_PREFIX])]);
                        $t->status($rq, 200, 'dengan OD search query QA27-');
                        $t->eq($sorted($q27_idseq($rq)), $sorted($expAll), 'dengan OD search query QA27-: tepat semua baris QA terlihat (oracle DB)');

                        $r = q27_list($t, $s, ['search' => q27_search(['isVerified' => 1, 'query' => Q27_PREFIX])]);
                        $t->status($r, 200, 'dengan OD search isVerified=1 (+query QA27-)');
                        $got = $q27_idseq($r);
                        $t->eq($sorted($got), $sorted($expV), 'dengan OD search isVerified=1: hasil = TEPAT baris QA terverifikasi (' . count($expV) . ', id sama dengan oracle DB)');
                        $t->eq($r[1]['result']['total'], count($expV), 'dengan OD search isVerified=1: total = jumlah baris QA terverifikasi');
                        $t->true(count($got) < count($expAll), 'dengan OD search isVerified=1: lebih sedikit dari semua baris QA (' . count($got) . ' < ' . count($expAll) . ') = filter berlaku');
                        foreach ($r[1]['result']['data'] as $row) {
                            if (($row['type'] ?? '') !== 'Folder') {
                                $t->eq($row['document_verified']['verified'], 1, "dengan OD search isVerified=1: dokumen {$row['id_archive']} verified");
                            }
                        }
                        $t->true(in_array($x['dF1'], $got, true) && in_array($x['dF3'], $got, true), 'dengan OD search isVerified=1: dokumen fixture verified (dF1, dF3) muncul');
                        $t->true(!in_array($x['dF2'], $got, true) && !in_array($x['dF5'], $got, true), 'dengan OD search isVerified=1: dokumen fixture unverified (dF2, dF5) tidak muncul');
                        $t->true(!in_array($x['dNEG'], $got, true) && !in_array($x['dDEL'], $got, true), 'dengan OD search isVerified=1: dokumen verified nonaktif (dNEG, dDEL) tidak muncul');

                        $r0 = q27_list($t, $s, ['search' => q27_search(['isVerified' => 0, 'query' => Q27_PREFIX])]);
                        $t->status($r0, 200, 'dengan OD search isVerified=0 (+query QA27-)');
                        $t->eq($sorted($q27_idseq($r0)), $sorted($expU), 'dengan OD search isVerified=0: hasil = TEPAT baris QA belum terverifikasi (' . count($expU) . ', id sama dengan oracle DB)');
                        $t->eq(count($expV) + count($expU), count($expAll), 'dengan OD: isVerified=1 dan =0 membagi semua baris QA (tanpa irisan, tanpa sisa)');

                        $rs = q27_list($t, $s, ['id_archive' => $x['F'], 'sorts' => [json_encode(['sortBy' => 'isVerified', 'sortType' => 'desc'])]]);
                        $t->status($rs, 200, 'dengan OD sorts isVerified desc');
                        $t->true($q27_idseq($rs) !== $baseFIds, 'dengan OD sorts isVerified desc: urutan berbeda dari default (sort berlaku)');
                        $firstDocs = array_values(array_filter($rs[1]['result']['data'], function ($row) {
                            return ($row['type'] ?? '') !== 'Folder';
                        }));
                        $t->eq($firstDocs[0]['document_verified']['verified'] ?? null, 1, 'dengan OD sorts isVerified desc: dokumen pertama verified');
                    });
                });
            } finally {
                q27_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'X-11',
        'title' => '(fix r1b, kontrak §1b) GET archives/{id}: tanpa Opname Document key is_verified, verified_at, verified_by, id_archive_opname tidak ada di result (folder & dokumen), key lain identik; pemegang Opname Document tidak berubah',
        'run'   => function ($t) {
            $s = $t->session();
            q27_baseline($t);
            try {
                $x = q27_tree($t);
                $cols = ['is_verified', 'verified_at', 'verified_by', 'id_archive_opname'];
                $targets = ['folder F' => $x['F'], 'folder S1' => $x['S1'], 'dokumen dF1 (verified)' => $x['dF1'], 'dokumen dF2 (unverified)' => $x['dF2']];
                $no = [];
                q27_with_user($t, ['role' => Q27_PRESET_LIST, 'emp' => ['SMR']], function () use ($t, $s, $targets, $cols, &$no) {
                    foreach ($targets as $label => $id) {
                        $r = $t->call($s, 'GET', Q27_BASE . '/archives/' . $id);
                        $t->status($r, 200, "tanpa OD detail $label");
                        $no[$label] = $r[1]['result'];
                        foreach ($cols as $c) {
                            $t->true(!array_key_exists($c, $r[1]['result']), "tanpa OD detail $label: tanpa key $c");
                        }
                    }
                    q27_with_grant($t, Q27_PRESET_LIST, Q27_OD, function () use ($t, $s, $targets, $cols, $no) {
                        foreach ($targets as $label => $id) {
                            $r = $t->call($s, 'GET', Q27_BASE . '/archives/' . $id);
                            $t->status($r, 200, "dengan OD detail $label");
                            $res = $r[1]['result'];
                            foreach ($cols as $c) {
                                $t->true(array_key_exists($c, $res), "dengan OD detail $label: key $c ada (perilaku lama tidak berubah)");
                            }
                            $strip = $res;
                            foreach ($cols as $c) {
                                unset($strip[$c]);
                            }
                            $t->eq(json_encode($strip), json_encode($no[$label]), "detail $label: dengan OD (4 key dibuang) identik dengan tanpa OD");
                        }
                        $r = $t->call($s, 'GET', Q27_BASE . '/archives/' . $targets['dokumen dF1 (verified)']);
                        $t->eq((int) $r[1]['result']['is_verified'], 1, 'dengan OD: dF1 is_verified 1');
                    });
                });
            } finally {
                q27_cleanup($t);
            }
        },
    ],
];
