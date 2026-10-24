<?php
/**
 * ED-1250 - GET document-archive/archives dengan search.verificationStatus (BE, ArchiveService::index):
 *   AC-2  root + filter: baris level root, himpunan folder = tanpa filter, dokumen hanya status sama, total = oracle, breadcrumbs [] / access null;
 *   AC-3  isi folder F (3 level) + filter: semua subfolder tetap (kosong, all-verified, tanpa dokumen cocok), dokumen hanya status sama,
 *         tidak ada baris level lain, breadcrumbs / access = tanpa filter; level 3 dan 4;
 *   AC-4  angka tidak berubah: document_verified baris, document_verified_summary, columns sama dengan tanpa filter (root, F, mode cari);
 *   AC-5  visibilitas: folder Izin On tanpa View tetap tampil (document_verified null, access.view false), dokumen di luar lokasi tidak,
 *         id_archive folder itu + filter = 403 ARCHIVE407 seperti tanpa filter; superadmin 1/2 semua;
 *   AC-6  mode cari datar: type=transaksi, type=FOLDER, name, updatedBy, showRelatedTransaction + id_archive.
 * Oracle (q50_expect) = tabel mentah, tanpa kode BE. Fixture QA50- (q50_tree), preset QA50-ROLE-*, dibuang di finally.
 */
require_once __DIR__ . '/qa_lib.php';

/** Satu kombinasi request: ambil semua halaman, bandingkan dengan oracle (himpunan, total, tanpa duplikat). Mengembalikan [all, exp]. */
$q50_chk = function ($t, $s, array $u, array $model, $label, array $q, array $o) {
    $all = q50_all($t, $s, $q, $label);
    $exp = q50_expect($t, $u, $o, $model);
    q50_same_set($t, $all['ids'], array_keys($exp), $label, q50_names($exp));
    $t->eq($all['total'], count($exp), $label . ': total = oracle');
    $t->eq(count($all['rows']), $all['total'], $label . ': jumlah baris semua halaman = total');

    return [$all, $exp];
};

/** Nama fixture (tanpa awalan acak) dari id -> label kunci, untuk asersi tangan. */
$q50_keys = function (array $x) {
    return array_flip($x);
};

return [

    [
        'id'    => 'AC-2',
        'title' => 'root + VERIFIED / UNVERIFIED: baris level root, himpunan folder sama dengan tanpa filter, dokumen hanya status sama (semua halaman), total = oracle, breadcrumbs [] dan access null, tanpa 500 (user lokasi Semarang + superadmin; data nyata 34,5 rb dokumen + fixture)',
        'run'   => function ($t) use ($q50_chk) {
            $s = $t->session();
            q50_baseline($t);
            try {
                $x = q50_tree($t);
                foreach (['smr', 'bypass'] as $mode) {
                    q50_with_user($t, q50_opts($mode), function () use ($t, $s, $x, $mode, $q50_chk) {
                        $u = q50_u($t, $mode);
                        $model = q50_model($t);
                        $base = q50_all($t, $s, [], "[$mode] root tanpa filter");
                        $baseMap = q50_map($base);
                        $baseFolders = array_keys(array_filter($baseMap, 'q50_is_folder_row'));
                        $t->true(count($baseFolders) > 0, "[$mode] root tanpa filter: ada folder (" . count($baseFolders) . ') dan ' . (count($baseMap) - count($baseFolders)) . ' dokumen');
                        foreach (['VERIFIED', 'UNVERIFIED'] as $f) {
                            [$all, $exp] = $q50_chk($t, $s, $u, $model, "[$mode] root $f", ['search' => [Q50_FILTER => $f]], ['parent' => null, 'f' => $f]);
                            $map = q50_map($all);
                            // semua baris level root (induk null) menurut DB
                            foreach ($all['ids'] as $id) {
                                if ($model[$id]['parent'] !== null) {
                                    $t->fail("[$mode] root $f: baris $id bukan level root");
                                }
                            }
                            // himpunan folder = tanpa filter (folder tidak pernah disaring)
                            $folders = array_keys(array_filter($map, 'q50_is_folder_row'));
                            q50_same_set($t, $folders, $baseFolders, "[$mode] root $f: himpunan folder = tanpa filter");
                            // dokumen: status cocok (dari DB) dan lengkap (dihitung dari baseline: dokumen root tanpa filter yang cocok)
                            $docs = array_diff(array_keys($map), $folders);
                            $baseDocsMatch = array_filter(array_keys($baseMap), function ($id) use ($baseMap, $model, $f) {
                                return !q50_is_folder_row($baseMap[$id]) && (($model[$id]['iv'] === 1) === ($f === 'VERIFIED'));
                            });
                            q50_same_set($t, array_values($docs), array_values($baseDocsMatch), "[$mode] root $f: dokumen = dokumen root tanpa filter yang statusnya sama");
                            foreach ($docs as $id) {
                                $t->true(($model[$id]['iv'] === 1) === ($f === 'VERIFIED'), "[$mode] root $f: dokumen $id berstatus sama (is_verified " . var_export($model[$id]['iv'], true) . ')');
                            }
                            $res = $all['first'][1]['result'];
                            $t->eq($res['breadcrumbs'], [], "[$mode] root $f: breadcrumbs []");
                            $t->eq($res['access'], null, "[$mode] root $f: access null");
                            $t->true(q50_order_ok($t, $all['ids'], [['type', 'asc'], ['name', 'asc']]), "[$mode] root $f: urutan default type, name");
                            // asersi tangan fixture
                            $has = function ($key) use ($all, $x) {
                                return in_array($x[$key], $all['ids'], true);
                            };
                            foreach (['P', 'OTH', 'ER', 'ALV', 'PRIV'] as $k) {
                                $t->true($has($k), "[$mode] root $f: folder $k tampil");
                            }
                            $t->true($has('JOGR') === ($mode === 'bypass'), "[$mode] root $f: folder JOGR (JOG saja) " . ($mode === 'bypass' ? 'tampil' : 'tidak tampil'));
                            $t->true($has('dR') === ($f === 'VERIFIED'), "[$mode] root $f: dokumen dR (verified) " . ($f === 'VERIFIED' ? 'ada' : 'tidak ada'));
                            $t->true($has('dR3') === ($f === 'UNVERIFIED'), "[$mode] root $f: dokumen dR3 (unverified) " . ($f === 'UNVERIFIED' ? 'ada' : 'tidak ada'));
                            $t->true($has('dR2') === ($mode === 'bypass' && $f === 'UNVERIFIED'), "[$mode] root $f: dokumen dR2 (JOG saja, unverified) sesuai lokasi");
                            $t->true(!$has('F') && !$has('dF1') && !$has('dF2'), "[$mode] root $f: tidak ada baris level lain");
                        }
                    });
                }
            } finally {
                q50_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-3',
        'title' => 'isi folder fixture (F, level 3 dan 4) + VERIFIED / UNVERIFIED: semua subfolder anak tampil (kosong 0/0, all-verified, tanpa dokumen cocok), dokumen anak hanya status sama (NULL / 2 = unverified), tidak ada baris level lain, breadcrumbs dan access = tanpa filter',
        'run'   => function ($t) use ($q50_chk) {
            $s = $t->session();
            q50_baseline($t);
            try {
                $x = q50_tree($t);
                foreach (['smr', 'bypass'] as $mode) {
                    q50_with_user($t, q50_opts($mode), function () use ($t, $s, $x, $mode, $q50_chk) {
                        $u = q50_u($t, $mode);
                        $model = q50_model($t);
                        $folderKeys = ['P', 'F', 'E', 'AV', 'MX', 'MXc', 'NV', 'SV', 'OTH', 'ER', 'ALV'];
                        foreach ($folderKeys as $k) {
                            $base = q50_all($t, $s, ['id_archive' => $x[$k]], "[$mode] $k tanpa filter");
                            $baseMap = q50_map($base);
                            $baseFolders = array_keys(array_filter($baseMap, 'q50_is_folder_row'));
                            foreach (['VERIFIED', 'UNVERIFIED'] as $f) {
                                [$all, $exp] = $q50_chk($t, $s, $u, $model, "[$mode] $k $f", ['id_archive' => $x[$k], 'search' => [Q50_FILTER => $f]], ['parent' => $x[$k], 'f' => $f]);
                                $map = q50_map($all);
                                $folders = array_keys(array_filter($map, 'q50_is_folder_row'));
                                q50_same_set($t, $folders, $baseFolders, "[$mode] $k $f: semua subfolder tetap (sama dengan tanpa filter)");
                                foreach ($all['ids'] as $id) {
                                    $t->eq($model[$id]['parent'], $x[$k], "[$mode] $k $f: baris $id anak langsung $k (tidak ada level lain)");
                                }
                                $a = $all['first'][1]['result'];
                                $b = $base['first'][1]['result'];
                                $t->eq(json_encode($a['breadcrumbs']), json_encode($b['breadcrumbs']), "[$mode] $k $f: breadcrumbs = tanpa filter");
                                $t->eq(json_encode($a['access']), json_encode($b['access']), "[$mode] $k $f: access = tanpa filter");
                                $t->true(q50_order_ok($t, $all['ids'], [['type', 'asc'], ['name', 'asc']]), "[$mode] $k $f: urutan default");
                            }
                        }

                        // ---- asersi tangan pada F (3 level)
                        $names = function ($f) use ($t, $s, $x) {
                            $all = q50_all($t, $s, ['id_archive' => $x['F'], 'search' => [Q50_FILTER => $f]]);
                            $flip = array_flip($x);

                            return array_values(array_map(function ($id) use ($flip) {
                                return $flip[$id] ?? $id;
                            }, $all['ids']));
                        };
                        $subfolders = ['E', 'AV', 'MX', 'NV', 'SP', 'SV'];
                        if ($mode === 'bypass') {
                            $subfolders[] = 'SJ';
                        }
                        $uDocs = ['dF2', 'dF4', 'dF5', 'dF6'];
                        if ($mode === 'bypass') {
                            $uDocs[] = 'dJF';
                        }
                        $got = $names('UNVERIFIED');
                        $t->eq(array_values(array_intersect($got, array_merge($subfolders, $uDocs))), $got, "[$mode] F UNVERIFIED: hanya subfolder + dokumen unverified");
                        $t->eq(count($got), count($subfolders) + count($uDocs), "[$mode] F UNVERIFIED: " . implode(',', $got));
                        foreach ($subfolders as $k) {
                            $t->true(in_array($k, $got, true), "[$mode] F UNVERIFIED: subfolder $k tampil");
                        }
                        foreach ($uDocs as $k) {
                            $t->true(in_array($k, $got, true), "[$mode] F UNVERIFIED: dokumen $k tampil");
                        }
                        foreach (['dF1', 'dF3', 'dNEG', 'dDEL', 'SX'] as $k) {
                            $t->true(!in_array($k, $got, true), "[$mode] F UNVERIFIED: $k tidak tampil");
                        }
                        $got = $names('VERIFIED');
                        $t->eq(count($got), count($subfolders) + 2, "[$mode] F VERIFIED: semua subfolder + dF1, dF3 (" . implode(',', $got) . ')');
                        foreach (array_merge($subfolders, ['dF1', 'dF3']) as $k) {
                            $t->true(in_array($k, $got, true), "[$mode] F VERIFIED: $k tampil (dF3 = Handed Over tetap tampil)");
                        }
                        foreach (['dF2', 'dF4', 'dF5', 'dF6', 'dJF', 'dNEG', 'dDEL', 'SX'] as $k) {
                            $t->true(!in_array($k, $got, true), "[$mode] F VERIFIED: $k tidak tampil");
                        }

                        // ---- folder kosong / all-verified / tanpa dokumen cocok
                        $byKey = function ($k, $f) use ($t, $s, $x) {
                            $all = q50_all($t, $s, ['id_archive' => $x[$k], 'search' => [Q50_FILTER => $f]]);
                            $flip = array_flip($x);

                            return array_map(function ($id) use ($flip) {
                                return $flip[$id] ?? $id;
                            }, $all['ids']);
                        };
                        $t->eq($byKey('E', 'VERIFIED'), [], "[$mode] E (kosong) VERIFIED = kosong");
                        $t->eq($byKey('E', 'UNVERIFIED'), [], "[$mode] E (kosong) UNVERIFIED = kosong");
                        $t->eq($byKey('AV', 'UNVERIFIED'), [], "[$mode] AV (all-verified) UNVERIFIED = kosong (folder AV tetap tampil di F)");
                        $t->eq($byKey('NV', 'VERIFIED'), [], "[$mode] NV (tanpa verified, tanpa subfolder) VERIFIED = kosong");
                        $t->eq($byKey('NV', 'UNVERIFIED'), ['dnv1', 'dnv2'], "[$mode] NV UNVERIFIED = dnv1 + dnv2 (is_verified NULL = unverified)");
                        $t->eq($byKey('MX', 'VERIFIED'), ['MXc', 'dmx1'], "[$mode] MX VERIFIED = subfolder MXc (level 4) + dmx1");
                        $t->eq(array_values(array_diff($byKey('MX', 'UNVERIFIED'), ['dmx2', 'dmx3'])), ['MXc'], "[$mode] MX UNVERIFIED = MXc + dmx2 + dmx3");
                        $t->eq($byKey('MXc', 'UNVERIFIED'), [], "[$mode] MXc (level 4) UNVERIFIED = kosong");
                        $t->eq($byKey('MXc', 'VERIFIED'), ['dmxc1'], "[$mode] MXc VERIFIED = dmxc1");
                        $t->eq($byKey('P', 'VERIFIED'), ['F'], "[$mode] P VERIFIED = F (tidak ada dokumen langsung; folder tetap)");
                        $t->eq($byKey('P', 'UNVERIFIED'), ['F'], "[$mode] P UNVERIFIED = F");
                    });
                }
            } finally {
                q50_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-4',
        'title' => 'angka tidak berubah: untuk baris yang ada di hasil berfilter dan tanpa filter, document_verified sama; document_verified_summary, columns dan queries sama (root, F, mode cari name); dokumen berfilter document_verified {verified 1|0, total 1} sesuai status',
        'run'   => function ($t) {
            $s = $t->session();
            q50_baseline($t);
            try {
                $x = q50_tree($t);
                foreach (['smr', 'bypass'] as $mode) {
                    q50_with_user($t, q50_opts($mode), function () use ($t, $s, $x, $mode) {
                        $shapes = [
                            'root'      => [[], []],
                            'folder F'  => [['id_archive' => $x['F']], []],
                            'folder MX' => [['id_archive' => $x['MX']], []],
                            'cari name' => [['search' => ['name' => 'QA50-']], ['name' => 'QA50-']],
                        ];
                        foreach ($shapes as $label => $shape) {
                            [$q0, $s0] = $shape;
                            $base = q50_all($t, $s, $q0, "[$mode] $label tanpa filter");
                            $baseMap = q50_map($base);
                            $bres = $base['first'][1]['result'];
                            foreach (['VERIFIED', 'UNVERIFIED'] as $f) {
                                $search = $s0 + [Q50_FILTER => $f];
                                $all = q50_all($t, $s, array_diff_key($q0, ['search' => 1]) + ['search' => $search], "[$mode] $label $f");
                                $res = $all['first'][1]['result'];
                                $both = 0;
                                foreach ($all['rows'] as $row) {
                                    if (isset($baseMap[$row['id_archive']])) {
                                        $both++;
                                        $t->eq(json_encode($row['document_verified']), json_encode($baseMap[$row['id_archive']]['document_verified']), "[$mode] $label $f: document_verified baris {$row['id_archive']} sama dengan tanpa filter");
                                    } else {
                                        $t->fail("[$mode] $label $f: baris {$row['id_archive']} tidak ada di hasil tanpa filter (filter menambah baris)");
                                    }
                                    if (!q50_is_folder_row($row)) {
                                        $t->eq($row['document_verified']['total'], 1, "[$mode] $label $f: dokumen total 1");
                                        $t->eq($row['document_verified']['verified'], $f === 'VERIFIED' ? 1 : 0, "[$mode] $label $f: dokumen verified sesuai filter");
                                    }
                                }
                                $t->true($both > 0, "[$mode] $label $f: ada baris yang dibandingkan ($both)");
                                $t->eq(json_encode($res['document_verified_summary']), json_encode($bres['document_verified_summary']), "[$mode] $label $f: document_verified_summary sama");
                                $t->eq(json_encode($res['columns']), json_encode($bres['columns']), "[$mode] $label $f: columns sama");
                                $t->eq(json_encode($res['queries']), json_encode($bres['queries']), "[$mode] $label $f: queries sama");
                            }
                        }
                    });
                }
            } finally {
                q50_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-5',
        'title' => 'user non-bypass (lokasi Semarang): folder Izin On tanpa View tetap tampil di kedua nilai dengan document_verified null dan access.view false seperti tanpa filter, dokumen di luar lokasi tidak tampil, id_archive folder itu + filter = 403 ARCHIVE407 (denied_by) seperti tanpa filter; folder ber-View terbuka; superadmin 1/2 melihat semuanya',
        'run'   => function ($t) use ($q50_chk) {
            $s = $t->session();
            q50_baseline($t);
            try {
                $x = q50_tree($t);
                // ---- non-bypass
                q50_with_user($t, q50_opts('smr'), function () use ($t, $s, $x, $q50_chk) {
                    $u = q50_u($t, 'smr');
                    $model = q50_model($t);
                    foreach (['VERIFIED', 'UNVERIFIED'] as $f) {
                        $filter = ['search' => [Q50_FILTER => $f]];
                        // root
                        $base = q50_map(q50_all($t, $s, [], '[smr] root tanpa filter'));
                        $all = q50_all($t, $s, $filter, "[smr] root $f");
                        $map = q50_map($all);
                        $t->true(isset($map[$x['PRIV']]), "[smr] root $f: folder PRIV (Izin On tanpa View) tetap tampil");
                        $t->eq($map[$x['PRIV']]['document_verified'], null, "[smr] root $f: PRIV document_verified null (angka '-')");
                        $t->eq($map[$x['PRIV']]['access']['view'], false, "[smr] root $f: PRIV access.view false");
                        $t->eq(json_encode($map[$x['PRIV']]), json_encode($base[$x['PRIV']]), "[smr] root $f: baris PRIV identik dengan tanpa filter");
                        $t->true(!isset($map[$x['JOGR']]) && !isset($map[$x['dR2']]), "[smr] root $f: folder / dokumen di luar lokasi tidak tampil");
                        $t->true(!isset($map[$x['dPR']]) && !isset($map[$x['dPRc']]), "[smr] root $f: isi PRIV tidak bocor ke root");
                        // F: SP (Izin On tanpa View) dan SV (View ada)
                        $allF = q50_all($t, $s, ['id_archive' => $x['F']] + $filter, "[smr] F $f");
                        $mapF = q50_map($allF);
                        $baseF = q50_map(q50_all($t, $s, ['id_archive' => $x['F']], '[smr] F tanpa filter'));
                        $t->true(isset($mapF[$x['SP']]), "[smr] F $f: folder SP (tanpa View) tampil");
                        $t->eq($mapF[$x['SP']]['document_verified'], null, "[smr] F $f: SP document_verified null");
                        $t->eq($mapF[$x['SP']]['access']['view'], false, "[smr] F $f: SP access.view false");
                        $t->eq(json_encode($mapF[$x['SP']]), json_encode($baseF[$x['SP']]), "[smr] F $f: baris SP identik dengan tanpa filter");
                        $t->true(isset($mapF[$x['SV']]) && $mapF[$x['SV']]['access']['view'] === true, "[smr] F $f: SV (View diberikan) tampil, access.view true");
                        $t->true(!isset($mapF[$x['SJ']]) && !isset($mapF[$x['dJF']]), "[smr] F $f: SJ / dJF (lokasi JOG) tidak tampil");
                        // 403 ARCHIVE407 sama dengan tanpa filter
                        foreach (['PRIV', 'SP'] as $k) {
                            $r0 = q50_get($t, $s, ['id_archive' => $x[$k]]);
                            $r1 = q50_get($t, $s, ['id_archive' => $x[$k]] + $filter);
                            $t->status($r0, 403, "[smr] $k tanpa filter");
                            $t->status($r1, 403, "[smr] $k $f");
                            $t->eq(q50_code($r1), 'ARCHIVE407', "[smr] $k $f: code ARCHIVE407");
                            $t->eq(q50_code($r1), q50_code($r0), "[smr] $k $f: code = tanpa filter");
                            $t->eq(json_encode($r1[1]['result']), json_encode($r0[1]['result']), "[smr] $k $f: result (denied_by, breadcrumbs) = tanpa filter");
                            $t->has($r1[1], 'result.denied_by', "[smr] $k $f: result.denied_by");
                        }
                        // anak folder tanpa View (SPc / PRc) juga 403
                        $rc = q50_get($t, $s, ['id_archive' => $x['SPc']] + $filter);
                        $t->status($rc, 403, "[smr] SPc (anak SP tanpa View) $f");
                        // SV terbuka dengan filter
                        $rv = $q50_chk($t, $s, $u, $model, "[smr] SV $f", ['id_archive' => $x['SV']] + $filter, ['parent' => $x['SV'], 'f' => $f]);
                        $t->eq($rv[0]['ids'] === [$x['dsv1']], $f === 'VERIFIED', "[smr] SV $f: dsv1 (verified) " . ($f === 'VERIFIED' ? 'ada' : 'tidak ada'));
                        // mode cari: folder tanpa View + isinya yang cocok lokasi tampil (ED-1025 BR-12), folder tetap
                        $rs = $q50_chk($t, $s, $u, $model, "[smr] cari name $f", ['search' => ['name' => 'QA50-', Q50_FILTER => $f]], ['parent' => 'FLAT', 'name' => 'QA50-', 'f' => $f]);
                        $t->true(in_array($x['SP'], $rs[0]['ids'], true) && in_array($x['PRIV'], $rs[0]['ids'], true) && in_array($x['PRc'], $rs[0]['ids'], true), "[smr] cari name $f: folder tanpa View tetap ada di hasil cari");
                        $t->eq(in_array($x['dspc1'], $rs[0]['ids'], true), $f === 'UNVERIFIED', "[smr] cari name $f: dokumen di folder tanpa View (dspc1 unverified) " . ($f === 'UNVERIFIED' ? 'ada' : 'tidak ada'));
                        $t->eq(in_array($x['dsp1'], $rs[0]['ids'], true), $f === 'VERIFIED', "[smr] cari name $f: dokumen di folder tanpa View (dsp1 verified) " . ($f === 'VERIFIED' ? 'ada' : 'tidak ada'));
                    }
                });

                // ---- superadmin 1 dan 2: tanpa batas hak / lokasi
                foreach ([1, 2] as $role) {
                    q50_with_user($t, ['role' => $role], function () use ($t, $s, $x, $role, $q50_chk) {
                        $u = q50_u($t, 'bypass');
                        $model = q50_model($t);
                        foreach (['VERIFIED', 'UNVERIFIED'] as $f) {
                            $filter = ['search' => [Q50_FILTER => $f]];
                            $r = q50_get($t, $s, ['pagination' => 3] + $filter);
                            $t->true(in_array('verificationStatus', q50_seq($r[1]['result']['queries']), true), "[role $role] $f: pemegang OD melihat entri filter");
                            $rp = $q50_chk($t, $s, $u, $model, "[role $role] PRIV $f", ['id_archive' => $x['PRIV']] + $filter, ['parent' => $x['PRIV'], 'f' => $f]);
                            $t->eq(in_array($x['dPR'], $rp[0]['ids'], true), $f === 'UNVERIFIED', "[role $role] PRIV $f: dPR (unverified) " . ($f === 'UNVERIFIED' ? 'ada' : 'tidak ada'));
                            $rf = $q50_chk($t, $s, $u, $model, "[role $role] F $f", ['id_archive' => $x['F']] + $filter, ['parent' => $x['F'], 'f' => $f]);
                            $t->true(in_array($x['SP'], $rf[0]['ids'], true) && in_array($x['SJ'], $rf[0]['ids'], true), "[role $role] F $f: SP dan SJ tampil");
                            $mapF = q50_map($rf[0]);
                            $t->eq($mapF[$x['SP']]['access']['view'], true, "[role $role] F $f: SP access.view true");
                            $t->true($mapF[$x['SP']]['document_verified'] !== null, "[role $role] F $f: SP document_verified terisi (bukan -)");
                        }
                    });
                }
            } finally {
                q50_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-6',
        'title' => 'mode cari datar + filter: type=transaksi + VERIFIED (semua level), type=FOLDER + UNVERIFIED identik type=FOLDER, name=QA50- + filter (folder cocok + dokumen status sama, juga di folder tanpa View), updatedBy, type+name+filter, showRelatedTransaction + id_archive (related_transactions) ',
        'run'   => function ($t) use ($q50_chk) {
            $s = $t->session();
            q50_baseline($t);
            try {
                $x = q50_tree($t);
                foreach (['smr', 'bypass'] as $mode) {
                    q50_with_user($t, q50_opts($mode), function () use ($t, $s, $x, $mode, $q50_chk) {
                        $u = q50_u($t, $mode);
                        $model = q50_model($t);

                        // a. type = transaksi (6) + VERIFIED, semua level (juga string "6")
                        foreach ([6, '6'] as $tx) {
                            [$all] = $q50_chk($t, $s, $u, $model, "[$mode] type=" . var_export($tx, true) . ' VERIFIED', ['search' => ['type' => $tx, Q50_FILTER => 'VERIFIED']], ['parent' => 'FLAT', 'type' => 6, 'f' => 'VERIFIED']);
                            foreach ($all['rows'] as $row) {
                                $t->true(!q50_is_folder_row($row), "[$mode] type=tx VERIFIED: baris {$row['id_archive']} bukan folder");
                                $t->eq($row['document_verified']['verified'], 1, "[$mode] type=tx VERIFIED: baris verified");
                            }
                            foreach (['dav1', 'dF1', 'dsp1', 'dalv1'] as $k) {
                                $t->true(in_array($x[$k], $all['ids'], true), "[$mode] type=6 VERIFIED: $k (verified tipe 6, level berbeda) ada");
                            }
                            foreach (['dF2', 'dmx1', 'dF3', 'dNEG'] as $k) {
                                $t->true(!in_array($x[$k], $all['ids'], true), "[$mode] type=6 VERIFIED: $k tidak ada (tipe lain / unverified / nonaktif)");
                            }
                            $t->eq(in_array($x['dsj2'], $all['ids'], true), $mode === 'bypass', "[$mode] type=6 VERIFIED: dsj2 (JOG saja) sesuai lokasi");
                        }

                        // b. type = FOLDER + filter == type = FOLDER saja
                        $folderOnly = q50_all($t, $s, ['search' => ['type' => 'FOLDER']], "[$mode] type=FOLDER");
                        foreach (['VERIFIED', 'UNVERIFIED'] as $f) {
                            [$all] = $q50_chk($t, $s, $u, $model, "[$mode] type=FOLDER $f", ['search' => ['type' => 'FOLDER', Q50_FILTER => $f]], ['parent' => 'FLAT', 'type' => 'FOLDER', 'f' => $f]);
                            $t->eq($all['ids'], $folderOnly['ids'], "[$mode] type=FOLDER $f: urutan dan isi identik dengan type=FOLDER");
                            $t->eq($all['total'], $folderOnly['total'], "[$mode] type=FOLDER $f: total identik");
                            $t->true(count($all['ids']) > 0, "[$mode] type=FOLDER $f: tidak kosong (" . $all['total'] . ' folder semua level)');
                        }

                        // c. name = QA50- + filter
                        foreach (['VERIFIED', 'UNVERIFIED'] as $f) {
                            [$all, $exp] = $q50_chk($t, $s, $u, $model, "[$mode] name=QA50- $f", ['search' => ['name' => 'QA50-', Q50_FILTER => $f]], ['parent' => 'FLAT', 'name' => 'QA50-', 'f' => $f]);
                            $folders = array_filter($all['rows'], 'q50_is_folder_row');
                            $allFolders = q50_expect($t, $u, ['parent' => 'FLAT', 'name' => 'QA50-', 'type' => 'FOLDER'], $model);
                            q50_same_set($t, array_column($folders, 'id_archive'), array_keys($allFolders), "[$mode] name=QA50- $f: semua folder fixture yang cocok nama tetap ada", q50_names($allFolders));
                            $t->true(count($allFolders) >= 14, "[$mode] name=QA50- $f: folder fixture ditemukan (" . count($allFolders) . ')');
                            $t->true(q50_order_ok($t, $all['ids'], [['type', 'asc'], ['name', 'asc']]), "[$mode] name=QA50- $f: urutan default");
                        }

                        // d. updatedBy (Modified By) + filter
                        foreach (['VERIFIED', 'UNVERIFIED'] as $f) {
                            $q50_chk($t, $s, $u, $model, "[$mode] updatedBy=QA50 $f", ['search' => ['updatedBy' => 'QA50', Q50_FILTER => $f]], ['parent' => 'FLAT', 'updby' => 'QA50', 'f' => $f]);
                        }

                        // e. tiga serangkai: type + name + filter
                        $q50_chk($t, $s, $u, $model, "[$mode] type=7+name+UNVERIFIED", ['search' => ['type' => 7, 'name' => 'QA50-', Q50_FILTER => 'UNVERIFIED']], ['parent' => 'FLAT', 'type' => 7, 'name' => 'QA50-', 'f' => 'UNVERIFIED']);

                        // f. showRelatedTransaction + filter + id_archive: tetap hirarki di F + related_transactions
                        foreach (['VERIFIED', 'UNVERIFIED'] as $f) {
                            $base = q50_all($t, $s, ['id_archive' => $x['F'], 'search' => ['showRelatedTransaction' => true]], "[$mode] F related tanpa filter");
                            $baseMap = q50_map($base);
                            [$all] = $q50_chk($t, $s, $u, $model, "[$mode] F related $f", ['id_archive' => $x['F'], 'search' => ['showRelatedTransaction' => true, Q50_FILTER => $f]], ['parent' => $x['F'], 'f' => $f]);
                            foreach ($all['rows'] as $row) {
                                if (q50_is_folder_row($row)) {
                                    $t->true(!array_key_exists('related_transactions', $row), "[$mode] F related $f: folder tanpa related_transactions");
                                } else {
                                    $t->true(array_key_exists('related_transactions', $row), "[$mode] F related $f: dokumen {$row['id_archive']} memuat related_transactions");
                                    $t->eq(json_encode($row['related_transactions']), json_encode($baseMap[$row['id_archive']]['related_transactions']), "[$mode] F related $f: related_transactions = tanpa filter");
                                }
                            }
                            $res = $all['first'][1]['result'];
                            $t->true(count($res['breadcrumbs']) > 0, "[$mode] F related $f: breadcrumbs folder F tetap (bukan 'Archive' datar)");
                            $t->eq(json_encode($res['breadcrumbs']), json_encode($base['first'][1]['result']['breadcrumbs']), "[$mode] F related $f: breadcrumbs = tanpa filter");
                        }
                        // showRelatedTransaction + filter di root = hirarki root
                        [$allR, $expR] = $q50_chk($t, $s, $u, $model, "[$mode] root related VERIFIED", ['search' => ['showRelatedTransaction' => true, Q50_FILTER => 'VERIFIED']], ['parent' => null, 'f' => 'VERIFIED']);
                        $t->eq($allR['first'][1]['result']['breadcrumbs'], [], "[$mode] root related VERIFIED: breadcrumbs []");
                    });
                }
            } finally {
                q50_cleanup($t);
            }
        },
    ],
];
