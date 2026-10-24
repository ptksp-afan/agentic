<?php
/**
 * ED-1250 - paginasi/sort, tanpa Opname Document, input salah bentuk (BE):
 *   AC-7  pagination kecil + filter: gabungan semua halaman = oracle tanpa duplikat, total/last_page konsisten, sorts name desc berlaku,
 *         tanpa sort = type, name; root dengan pagination 500;
 *   AC-8  preset List Archive tanpa Opname Document: search.verificationStatus (root, folder, bersama name, type, sorts, halaman 2) -> 200,
 *         data/total/urutan identik dengan tanpa key, queries tanpa entri, tanpa document_verified*; pembanding dengan-OD (field verifikasi
 *         + entri queries dibuang) identik (menggantikan asersi ED-1027 AC-22 yang usang); QA_USER2 sungguhan; tanpa List Archive = 403 GE0114;
 *   AC-9  pemegang: nilai verificationStatus salah bentuk -> 200 identik dengan tanpa key (juga bersama type / name), tanpa 500; teks non-latin1;
 *         search rusak / bukan objek; ejaan key lain.
 */
require_once __DIR__ . '/qa_lib.php';

/** Hapus bidang yang memantulkan query request (URL paginasi) dari result. */
$q50_nourl = function (array $res) {
    foreach (['first_page_url', 'last_page_url', 'next_page_url', 'prev_page_url', 'path'] as $u) {
        unset($res[$u]);
    }

    return $res;
};

/** result pemegang OD tanpa field verifikasi dan tanpa entri filter: harus sama dengan result non-pemegang. */
$q50_stripod = function (array $res) use ($q50_nourl) {
    $res = $q50_nourl($res);
    foreach ($res['data'] as $i => $row) {
        unset($res['data'][$i]['document_verified']);
    }
    unset($res['document_verified_summary']);
    $res['columns'] = array_values(array_filter($res['columns'], function ($c) {
        return $c['data_index'] !== 'documentVerified';
    }));
    $res['queries'] = array_values(array_filter($res['queries'], function ($q) {
        return $q['data_index'] !== 'verificationStatus';
    }));

    return $res;
};

/** Tanda tangan respons list untuk perbandingan: status, id berurutan, total, halaman, breadcrumbs, access. */
$q50_sig = function ($r) {
    $res = $r[1]['result'] ?? [];

    return json_encode([
        $r[0], array_column($res['data'] ?? [], 'id_archive'), $res['total'] ?? null, $res['last_page'] ?? null, $res['current_page'] ?? null,
        $res['breadcrumbs'] ?? null, $res['access'] ?? null,
    ]);
};

return [

    [
        'id'    => 'AC-7',
        'title' => 'pagination kecil + filter: gabungan semua halaman = oracle tanpa duplikat, total / last_page konsisten, sorts name desc berlaku (dengan filter), tanpa sort = type, name; root pagination 500 (user lokasi Semarang + superadmin)',
        'run'   => function ($t) {
            $s = $t->session();
            q50_baseline($t);
            try {
                $x = q50_tree($t);
                foreach (['smr', 'bypass'] as $mode) {
                    q50_with_user($t, q50_opts($mode), function () use ($t, $s, $x, $mode) {
                        $u = q50_u($t, $mode);
                        $model = q50_model($t);
                        foreach (['VERIFIED', 'UNVERIFIED'] as $f) {
                            $filter = [Q50_FILTER => $f];
                            // F, pagination=2, tanpa sort
                            $exp = q50_expect($t, $u, ['parent' => $x['F'], 'f' => $f], $model);
                            $all = q50_all($t, $s, ['id_archive' => $x['F'], 'pagination' => 2, 'search' => $filter], "[$mode] F $f p=2");
                            q50_same_set($t, $all['ids'], array_keys($exp), "[$mode] F $f p=2", q50_names($exp));
                            $t->eq($all['total'], count($exp), "[$mode] F $f p=2: total = oracle");
                            $t->eq($all['pages'], (int) ceil(count($exp) / 2), "[$mode] F $f p=2: jumlah halaman = ceil(total/2)");
                            $t->true($all['pages'] >= 3, "[$mode] F $f p=2: multi-halaman (" . $all['pages'] . ')');
                            $t->true(q50_order_ok($t, $all['ids'], [['type', 'asc'], ['name', 'asc']]), "[$mode] F $f p=2: tanpa sort = type, name (lintas halaman)");
                            // tiap halaman: current_page, per_page, total sama, <= 2 baris
                            for ($p = 1; $p <= $all['pages']; $p++) {
                                $r = q50_get($t, $s, ['id_archive' => $x['F'], 'pagination' => 2, 'page' => $p, 'search' => $filter]);
                                $res = $r[1]['result'];
                                $t->eq($res['current_page'], $p, "[$mode] F $f hal. $p: current_page");
                                $t->eq($res['total'], count($exp), "[$mode] F $f hal. $p: total konstan");
                                $t->true(count($res['data']) <= 2 && count($res['data']) >= ($p < $all['pages'] ? 2 : 1), "[$mode] F $f hal. $p: jumlah baris halaman");
                            }
                            // sorts name desc dengan filter
                            $sorts = [json_encode(['sortBy' => 'name', 'sortType' => 'desc'])];
                            $allD = q50_all($t, $s, ['id_archive' => $x['F'], 'pagination' => 3, 'search' => $filter, 'sorts' => $sorts], "[$mode] F $f sort name desc");
                            q50_same_set($t, $allD['ids'], array_keys($exp), "[$mode] F $f sort desc", q50_names($exp));
                            $t->true(q50_order_ok($t, $allD['ids'], [['name', 'desc']]), "[$mode] F $f: sorts name desc berlaku lintas halaman");
                            $allA = q50_all($t, $s, ['id_archive' => $x['F'], 'pagination' => 3, 'search' => $filter, 'sorts' => [json_encode(['sortBy' => 'name', 'sortType' => 'asc'])]], "[$mode] F $f sort name asc");
                            $t->true(q50_order_ok($t, $allA['ids'], [['name', 'asc']]), "[$mode] F $f: sorts name asc berlaku");
                            // mode cari datar, pagination=7
                            $expS = q50_expect($t, $u, ['parent' => 'FLAT', 'name' => 'QA50-', 'f' => $f], $model);
                            $allS = q50_all($t, $s, ['pagination' => 7, 'search' => ['name' => 'QA50-', Q50_FILTER => $f]], "[$mode] cari name $f p=7");
                            q50_same_set($t, $allS['ids'], array_keys($expS), "[$mode] cari name $f p=7", q50_names($expS));
                            $t->eq($allS['total'], count($expS), "[$mode] cari name $f p=7: total");
                            // root pagination 500 (data nyata)
                            $expR = q50_expect($t, $u, ['parent' => null, 'f' => $f], $model);
                            $allR = q50_all($t, $s, ['pagination' => 500, 'search' => $filter], "[$mode] root $f p=500");
                            q50_same_set($t, $allR['ids'], array_keys($expR), "[$mode] root $f p=500", q50_names($expR));
                            $t->eq($allR['total'], count($expR), "[$mode] root $f p=500: total");
                            $t->true(q50_order_ok($t, $allR['ids'], [['type', 'asc'], ['name', 'asc']]), "[$mode] root $f p=500: urutan default lintas halaman");
                        }
                    });
                }
            } finally {
                q50_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-8',
        'title' => 'preset List Archive tanpa Opname Document: search.verificationStatus (root, folder, bersama name / type / sorts, halaman 2) = 200 identik dengan tanpa key; queries tanpa entri; tanpa document_verified*; pembanding dengan-OD (field verifikasi + entri dibuang) identik; QA_USER2 sungguhan; tanpa List Archive 403 GE0114',
        'run'   => function ($t) use ($q50_nourl, $q50_stripod, $q50_sig) {
            $s = $t->session();
            q50_baseline($t);
            try {
                $x = q50_tree($t);
                $shapes = [
                    'root p1'            => ['pagination' => 50],
                    'root p2'            => ['pagination' => 50, 'page' => 2],
                    'folder F'           => ['id_archive' => $x['F']],
                    'folder F p2'        => ['id_archive' => $x['F'], 'pagination' => 2, 'page' => 2],
                    'folder MX'          => ['id_archive' => $x['MX']],
                    'name'               => ['search' => ['name' => 'QA50-']],
                    'name + F'           => ['id_archive' => $x['F'], 'search' => ['name' => 'QA50-']],
                    'type FOLDER'        => ['search' => ['type' => 'FOLDER']],
                    'type 7 + name'      => ['search' => ['type' => 7, 'name' => 'QA50-']],
                    'related + F'        => ['id_archive' => $x['F'], 'search' => ['showRelatedTransaction' => true]],
                    'sort name desc + F' => ['id_archive' => $x['F'], 'sorts' => [json_encode(['sortBy' => 'name', 'sortType' => 'desc'])]],
                ];
                $withKey = function (array $q, $f) {
                    $q['search'] = ($q['search'] ?? []) + [Q50_FILTER => $f];

                    return $q;
                };
                $noOd = [];
                q50_with_user($t, q50_opts('smr', 'LIST'), function () use ($t, $s, $shapes, $withKey, $q50_nourl, $q50_sig, &$noOd) {
                    foreach ($shapes as $label => $q) {
                        $r0 = q50_get($t, $s, $q);
                        $t->status($r0, 200, "tanpa OD [$label] tanpa key");
                        $noOd[$label] = $r0;
                        $res = $r0[1]['result'];
                        $t->true(!in_array('verificationStatus', q50_seq($res['queries']), true), "tanpa OD [$label]: queries tanpa entri verificationStatus");
                        $t->true(!in_array('documentVerified', q50_seq($res['columns']), true) && !array_key_exists('document_verified_summary', $res), "tanpa OD [$label]: tanpa kolom / ringkasan verifikasi");
                        foreach ($res['data'] as $row) {
                            if (array_key_exists('document_verified', $row)) {
                                $t->fail("tanpa OD [$label]: baris {$row['id_archive']} memuat document_verified");
                            }
                        }
                        foreach (['VERIFIED', 'UNVERIFIED'] as $f) {
                            $r1 = q50_get($t, $s, $withKey($q, $f));
                            $t->status($r1, 200, "tanpa OD [$label] key $f");
                            $t->eq($q50_sig($r1), $q50_sig($r0), "tanpa OD [$label] key $f: id berurutan / total / halaman / breadcrumbs / access identik dengan tanpa key");
                            $t->eq(json_encode($q50_nourl($r1[1]['result'])), json_encode($q50_nourl($r0[1]['result'])), "tanpa OD [$label] key $f: seluruh result identik dengan tanpa key (kecuali URL paginasi)");
                            $t->true(!in_array('verificationStatus', q50_seq($r1[1]['result']['queries']), true), "tanpa OD [$label] key $f: queries tanpa entri");
                            $body = json_encode($r1[1]['result']['data']);
                            $t->true(stripos($body, 'document_verified') === false, "tanpa OD [$label] key $f: baris tanpa document_verified");
                        }
                    }
                    // bahwa filter memang akan menyaring bila dipegang: shape folder F tanpa OD mengembalikan baris yang disembunyikan pemegang
                    $f = q50_map(['rows' => $noOd['folder F'][1]['result']['data']]);
                    $t->true(count($f) >= 10, 'tanpa OD: folder F tetap memuat semua baris (' . count($f) . ' baris, dokumen verified tidak disaring)');
                });

                // pembanding dengan OD (preset OD, lokasi sama): result - field verifikasi - entri queries = result tanpa OD
                q50_with_user($t, q50_opts('smr', 'OD'), function () use ($t, $s, $shapes, $q50_stripod, $q50_nourl, $noOd) {
                    foreach ($shapes as $label => $q) {
                        $r = q50_get($t, $s, $q);
                        $t->status($r, 200, "dengan OD [$label]");
                        $t->true(in_array('documentVerified', q50_seq($r[1]['result']['columns']), true) && in_array('verificationStatus', q50_seq($r[1]['result']['queries']), true), "dengan OD [$label]: kolom dan entri filter ada");
                        $t->eq(json_encode($q50_stripod($r[1]['result'])), json_encode($q50_nourl($noOd[$label][1]['result'])), "[$label]: dengan OD (field verifikasi + entri verificationStatus dibuang) identik dengan tanpa OD");
                    }
                });

                // QA_USER2 sungguhan (preset List Archive, tanpa OD)
                q50_with_u2_preset($t, 'LIST', function () use ($t, $x, $q50_sig) {
                    $s2 = q50_u2_session($t);
                    foreach ([['id_archive' => $x['F']], ['pagination' => 20], ['search' => ['name' => 'QA50-']]] as $q) {
                        $r0 = q50_get($t, $s2, $q);
                        $t->status($r0, 200, 'QA_USER2 tanpa key');
                        foreach (['VERIFIED', 'UNVERIFIED'] as $f) {
                            $q1 = $q;
                            $q1['search'] = ($q['search'] ?? []) + [Q50_FILTER => $f];
                            $r1 = q50_get($t, $s2, $q1);
                            $t->status($r1, 200, "QA_USER2 key $f");
                            $t->eq($q50_sig($r1), $q50_sig($r0), "QA_USER2 key $f: identik dengan tanpa key");
                            $t->true(!in_array('verificationStatus', q50_seq($r1[1]['result']['queries']), true), 'QA_USER2: queries tanpa entri');
                        }
                    }
                });

                // tanpa List Archive: tetap 403 GE0114 (dengan / tanpa key)
                q50_with_user($t, q50_opts('smr', 'NOLIST'), function () use ($t, $s) {
                    foreach ([[], ['search' => [Q50_FILTER => 'VERIFIED']]] as $q) {
                        $r = q50_get($t, $s, $q);
                        $t->status($r, 403, 'tanpa List Archive');
                        $t->eq(q50_code($r), 'GE0114', 'tanpa List Archive: GE0114');
                    }
                });
            } finally {
                q50_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-9',
        'title' => 'pemegang: verificationStatus salah bentuk ("verified", "X", 1, 0, true, null, "", [], {"a":1}, ...) = 200 identik dengan tanpa key (juga bersama type / name), tanpa 500; teks non-latin1; search rusak / bukan objek; ejaan key lain; query param di luar search',
        'run'   => function ($t) use ($q50_sig) {
            $s = $t->session();
            q50_baseline($t);
            try {
                $x = q50_tree($t);
                $bad = [
                    'verified (huruf kecil)' => 'verified', 'Verified' => 'Verified', 'X' => 'X', 'angka 1' => 1, 'angka 0' => 0, 'true' => true, 'false' => false,
                    'null' => null, '""' => '', 'list kosong' => [], 'list [VERIFIED]' => ['VERIFIED'], 'objek {"a":1}' => ['a' => 1],
                    'objek {"VERIFIED":1}' => ['VERIFIED' => 1], ' VERIFIED' => ' VERIFIED', 'VERIFIED ' => 'VERIFIED ', 'UNVERIFIED\\n' => "UNVERIFIED\n",
                    'angka 1.5' => 1.5, 'VERIFIED1' => 'VERIFIED1', 'non-latin1' => "\u{65E5}\u{672C}\u{8A9E}", 'U+FFFD' => "\u{FFFD}",
                ];
                $shapes = [
                    'root p1'  => ['pagination' => 40],
                    'folder F' => ['id_archive' => $x['F']],
                    'type'     => ['search' => ['type' => 'FOLDER']],
                    'name'     => ['search' => ['name' => 'QA50-']],
                    'F + name' => ['id_archive' => $x['F'], 'search' => ['name' => 'QA50-']],
                ];
                foreach (['smr', 'bypass'] as $mode) {
                    q50_with_user($t, q50_opts($mode), function () use ($t, $s, $x, $mode, $bad, $shapes, $q50_sig) {
                        $base = [];
                        foreach ($shapes as $label => $q) {
                            $base[$label] = q50_get($t, $s, $q);
                            $t->status($base[$label], 200, "[$mode] [$label] tanpa key");
                        }
                        foreach ($bad as $vlabel => $v) {
                            foreach ($shapes as $label => $q) {
                                $q1 = $q;
                                $q1['search'] = ($q['search'] ?? []) + [Q50_FILTER => $v];
                                $r = q50_get($t, $s, $q1);
                                $t->true($r[0] < 500, "[$mode] [$label] nilai $vlabel: HTTP {$r[0]} (tidak boleh 5xx)");
                                $t->status($r, 200, "[$mode] [$label] nilai $vlabel");
                                $t->eq($q50_sig($r), $q50_sig($base[$label]), "[$mode] [$label] nilai $vlabel: identik dengan tanpa key (id berurutan, total, breadcrumbs, access)");
                            }
                        }
                        // nilai sah tetap menyaring (kontrol): VERIFIED di folder F bukan identik dengan tanpa key
                        $rv = q50_get($t, $s, ['id_archive' => $x['F'], 'search' => [Q50_FILTER => 'VERIFIED']]);
                        $t->true($q50_sig($rv) !== $q50_sig($base['folder F']), "[$mode] kontrol: VERIFIED di F menyaring (tidak identik dengan tanpa key)");
                        // non-latin1 di key lain bersama filter sah: sama dengan key lain itu saja (hasil kosong seperti sekarang)
                        $nl = "\u{65E5}\u{672C}";
                        $r0 = q50_get($t, $s, ['search' => ['name' => $nl]]);
                        $r1 = q50_get($t, $s, ['search' => ['name' => $nl, Q50_FILTER => 'VERIFIED']]);
                        $t->status($r0, 200, "[$mode] name non-latin1");
                        $t->status($r1, 200, "[$mode] name non-latin1 + VERIFIED");
                        $t->eq($q50_sig($r1), $q50_sig($r0), "[$mode] name non-latin1 + VERIFIED identik dengan name non-latin1 saja");

                        // search rusak / bukan objek (bentuk mentah): tidak 5xx, identik dengan search yang sama tanpa key
                        foreach (['{"verificationStatus":"VERIFIED"' => '', '"VERIFIED"' => '', '["VERIFIED"]' => '', '[]' => '', '123' => '', 'null' => '', '{"verificationStatus":"VERIFIED","verificationStatus":"X"}' => ''] as $raw => $_) {
                            $r = q50_get($t, $s, ['id_archive' => $x['F'], 'search' => $raw]);
                            $t->true($r[0] < 500, "[$mode] search mentah $raw: HTTP {$r[0]} (tidak boleh 5xx)");
                        }
                        $r = q50_get($t, $s, ['id_archive' => $x['F'], 'search' => '{"verificationStatus":"VERIFIED","verificationStatus":"X"}']);
                        $t->eq($q50_sig($r), $q50_sig($base['folder F']), "[$mode] key ganda (nilai terakhir tidak sah) = tanpa key (PHP memakai nilai terakhir)");

                        // key di luar search tidak dibaca; ejaan lain tidak diperlakukan sebagai filter
                        $r = $t->call($s, 'GET', q50_url(Q50_BASE . '/archives', ['id_archive' => $x['F'], 'pagination' => 1000, 'verificationStatus' => 'VERIFIED']));
                        $t->status($r, 200, "[$mode] verificationStatus sebagai query param biasa");
                        $t->eq($q50_sig($r), $q50_sig($base['folder F']), "[$mode] query param verificationStatus di luar search diabaikan");
                        foreach (['verification_status', 'VerificationStatus', 'verificationstatus', 'verificationStatus '] as $key) {
                            $r = q50_get($t, $s, ['id_archive' => $x['F'], 'search' => [$key => 'VERIFIED']]);
                            $t->true($r[0] < 500, "[$mode] ejaan key '$key': HTTP {$r[0]} (tidak boleh 5xx)");
                            if ($mode === 'smr') {
                                $t->note("ejaan '$key' => HTTP {$r[0]}, " . ($q50_sig($r) === $q50_sig($base['folder F']) ? 'identik tanpa key' : 'beda dari tanpa key'));
                            }
                        }
                        // kontrol (di luar spec, pra-ada): key ber-titik yang tidak dikenal = 500 untuk key sembarang juga, jadi bukan akibat filter ini
                        $c1 = q50_get($t, $s, ['id_archive' => $x['F'], 'search' => ['foo.bar' => 'X']]);
                        $c2 = q50_get($t, $s, ['id_archive' => $x['F'], 'search' => ['filter.verificationStatus' => 'VERIFIED']]);
                        $t->eq($c2[0], $c1[0], "[$mode] key ber-titik 'filter.verificationStatus' berperilaku sama dengan key sembarang 'foo.bar' (kontrol pra-ada)");
                        if ($mode === 'smr') {
                            $t->note("PRA-ADA (bukan defect ED-1250): search dengan key ber-titik tak dikenal ('foo.bar', 'filter.verificationStatus') = HTTP {$c1[0]}/{$c2[0]} (SQL Unknown column), CustomizeBuilder::scopeSearchAll");
                        }
                    });
                }
            } finally {
                q50_cleanup($t);
            }
        },
    ],
];
