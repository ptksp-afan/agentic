<?php
/**
 * ED-1027 - AC-7: angka hanya dari dokumen/folder yang boleh dilihat user (scope lokasi 01 + View folder 02, per baris),
 * superadmin 1/2 semua. "User2" disimulasikan dengan QA_USER + lokasi employee sementara (QA_USER2 tidak bisa login di
 * api_sidomaju). X-2: hak View folder (langsung, turunan, pembuat). X-3: isolasi tenant (butuh >= 2 DB di QA_DBS).
 * Semua data uji dipulihkan di finally (q27_cleanup). Oracle = q27_oracle (SQL mentah, tanpa kode BE).
 */
require_once __DIR__ . '/qa_lib.php';

/** Fixture q27_tree + tambahan lokasi: dSM (SMR), dMU (SMR+JOG), F2 (JOG) > F2c (semua) dengan dF2c, dF2d. */
$q27_extended = function ($t) {
    $x = q27_tree($t);
    $x['dSM'] = q27_doc($t, 'dSM', ['parent' => $x['F'], 'all' => 0, 'locs' => ['SMR'], 'verified' => 1], 7);
    $x['dMU'] = q27_doc($t, 'dMU', ['parent' => $x['F'], 'all' => 0, 'locs' => ['SMR', 'JOG']], 8);
    $x['F2'] = q27_folder($t, 'F2', ['parent' => $x['P'], 'all' => 0, 'locs' => ['JOG']]);
    $x['F2c'] = q27_folder($t, 'F2c', ['parent' => $x['F2']]);
    $x['dF2c'] = q27_doc($t, 'dF2c', ['parent' => $x['F2c'], 'verified' => 1], 6);
    $x['dF2d'] = q27_doc($t, 'dF2d', ['parent' => $x['F2']], 6);

    return $x;
};

/** Angka tangan per mode: delta ringkasan fixture, [v,t] per folder (null = tak terbuka). */
$q27_hand = [
    'smr'    => ['summary' => [8, 16], 'F' => [6, 13], 'P' => [6, 13], 'F2' => null, 'F2c' => [1, 1], 'SJ' => null, 'SP' => null, 'PRIV' => null],
    'jog'    => ['summary' => [7, 20], 'F' => [5, 14], 'P' => [6, 16], 'F2' => [1, 2], 'F2c' => [1, 1], 'SJ' => [0, 1], 'SP' => null, 'PRIV' => null],
    'smrjog' => ['summary' => [8, 21], 'F' => [6, 15], 'P' => [7, 17], 'F2' => [1, 2], 'F2c' => [1, 1], 'SJ' => [0, 1], 'SP' => null, 'PRIV' => null],
    'bypass' => ['summary' => [9, 25], 'F' => [7, 17], 'P' => [8, 19], 'F2' => [1, 2], 'F2c' => [1, 1], 'SJ' => [0, 1], 'SP' => [1, 2], 'PRIV' => [0, 2]],
];

return [

    [
        'id'    => 'AC-7',
        'title' => 'user lokasi terbatas (SMR / JOG / SMR+JOG) vs superadmin (role 1 dan 2): ringkasan, kolom folder, detail All & per folder = oracle & angka tangan; dokumen & folder lokasi lain tak ikut; folder tanpa View terputus dari angka induk & ringkasan; folder terlihat di bawah folder tak terlihat ikut ringkasan saja',
        'run'   => function ($t) use ($q27_extended, $q27_hand) {
            $s = $t->session();
            q27_baseline($t);
            try {
                $seen = [];
                foreach (['smr', 'jog', 'smrjog', 'bypass', 'role2'] as $mode) {
                    $oracleMode = $mode === 'role2' ? 'bypass' : $mode;
                    $opts = $mode === 'role2' ? ['role' => 2] : q27_opts($mode);
                    q27_with_user($t, $opts, function () use ($t, $s, $mode, $oracleMode, $q27_extended, $q27_hand, &$seen) {
                        $base = q27_api_vt(q27_list($t, $s)[1]['result']['document_verified_summary']);
                        $x = $q27_extended($t);
                        $o = q27_oracle($t, q27_u($t, $oracleMode));
                        $sumO = q27_vt($o['summary']);

                        $r = q27_list($t, $s);
                        $t->status($r, 200, "[$mode] root");
                        $sum = q27_api_vt($r[1]['result']['document_verified_summary']);
                        $t->eq($sum, $sumO, "[$mode] ringkasan = oracle");
                        $seen[$mode]['summary'] = $sum;
                        $seen[$mode]['delta'] = [$sum[0] - $base[0], $sum[1] - $base[1]];
                        foreach ($r[1]['result']['data'] as $row) {
                            if (($row['type'] ?? null) === 'Folder') {
                                $exp = isset($o['folders'][$row['id_archive']]) ? q27_vt($o['folders'][$row['id_archive']]) : null;
                                $t->eq(q27_api_vt($row['document_verified']), $exp, "[$mode] root: folder {$row['id_archive']} = oracle");
                            }
                        }

                        $rv = q27_verif($t, $s);
                        $t->status($rv, 200, "[$mode] GET verifications");
                        $t->eq([$rv[1]['result']['verified'], $rv[1]['result']['total']], $sumO, "[$mode] GET verifications = oracle");

                        foreach ($x as $k => $id) {
                            if ($k[0] === 'd') {
                                continue;
                            }
                            $rd = q27_verif($t, $s, $id);
                            if (isset($o['visible'][$id])) {
                                $t->status($rd, 200, "[$mode] detail $k");
                                $t->eq([$rd[1]['result']['verified'], $rd[1]['result']['total']], q27_vt($o['folders'][$id]), "[$mode] detail $k = oracle");
                                $seen[$mode][$k] = [$rd[1]['result']['verified'], $rd[1]['result']['total']];
                            } else {
                                $t->true(in_array($rd[0], [403, 404], true), "[$mode] detail $k tak terlihat: HTTP {$rd[0]} " . q27_code($rd));
                                $t->true(empty($rd[1]['result']), "[$mode] detail $k tak terlihat: tanpa data");
                                $seen[$mode][$k] = null;
                            }
                        }

                        foreach (['P', 'F', 'F2'] as $k) {
                            if (!isset($o['visible'][$x[$k]])) {
                                continue;
                            }
                            $rf = q27_list($t, $s, ['id_archive' => $x[$k]]);
                            $t->status($rf, 200, "[$mode] isi $k");
                            $t->eq(q27_api_vt($rf[1]['result']['document_verified_summary']), $sumO, "[$mode] isi $k: ringkasan All Archive");
                            foreach ($rf[1]['result']['data'] as $row) {
                                if (($row['type'] ?? null) === 'Folder') {
                                    $exp = isset($o['folders'][$row['id_archive']]) ? q27_vt($o['folders'][$row['id_archive']]) : null;
                                    $t->eq(q27_api_vt($row['document_verified']), $exp, "[$mode] isi $k: folder {$row['id_archive']} = oracle");
                                }
                            }
                        }

                        if ($mode !== 'role2') {
                            $h = $q27_hand[$mode];
                            $t->eq($seen[$mode]['delta'], $h['summary'], "[$mode] delta ringkasan fixture = angka tangan");
                            foreach (['F', 'P', 'F2', 'F2c', 'SJ', 'SP', 'PRIV'] as $k) {
                                $t->eq($seen[$mode][$k], $h[$k], "[$mode] $k = angka tangan");
                            }
                        }
                        q27_cleanup($t, "[$mode] fixture dibuang");
                        q27_baseline($t);
                    });
                }

                $t->eq($seen['role2']['delta'], $seen['bypass']['delta'], 'superadmin role 2 = role 1 (ringkasan)');
                $t->eq($seen['role2']['F'], $seen['bypass']['F'], 'superadmin role 2 = role 1 (F)');
                $tot = function ($m) use ($seen) {
                    return $seen[$m]['delta'][1];
                };
                $t->true($tot('bypass') > $tot('smrjog') && $tot('smrjog') > max($tot('smr'), $tot('jog')), 'dokumen terlihat: superadmin > smr+jog > smr,jog: ' . json_encode(array_map(function ($m) {
                    return $m['delta'];
                }, $seen)));
            } finally {
                q27_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'X-2',
        'title' => 'hak View folder (02): tanpa View tak ikut angka induk & ringkasan; baris View saja cukup dan mengembalikannya; folder terhalang di tengah memutus seluruh turunan; pembuat folder selalu melihat; View induk dicabut memutus lagi',
        'run'   => function ($t) {
            $s = $t->session();
            q27_baseline($t);
            try {
                q27_with_user($t, q27_opts('smr'), function () use ($t, $s) {
                    $x = q27_tree($t);
                    $uid = q27_uid($t);
                    $uname = q27_uname($t);
                    $x['SPd'] = q27_folder($t, 'SPd', ['parent' => $x['SPc']]);
                    $x['dspd'] = q27_doc($t, 'dspd', ['parent' => $x['SPd'], 'verified' => 1], 8);
                    $u = q27_u($t, 'smr');

                    // [kolom folder di list induknya, ringkasan] dan cocokkan oracle
                    $row = function ($k, $label) use ($t, $s, $x, $u) {
                        $parent = q27_row($t, $x[$k])['id_archive_parent'];
                        $r = q27_list($t, $s, $parent === null ? [] : ['id_archive' => $parent]);
                        $t->status($r, 200, "$label: list induk $k");
                        $o = q27_oracle($t, $u);
                        $col = q27_api_vt(q27_map($r)[$x[$k]]['document_verified']);
                        $sum = q27_api_vt($r[1]['result']['document_verified_summary']);
                        $t->eq($col, isset($o['folders'][$x[$k]]) ? q27_vt($o['folders'][$x[$k]]) : null, "$label: kolom $k = oracle");
                        $t->eq($sum, q27_vt($o['summary']), "$label: ringkasan = oracle");

                        return [$col, $sum];
                    };
                    $code = function ($k) use ($t, $s, $x) {
                        $r = q27_verif($t, $s, $x[$k]);

                        return $r[0] . ($r[0] === 200 ? '' : ' ' . q27_code($r));
                    };
                    $add = function ($a, $d) {
                        return [$a[0] + $d[0], $a[1] + $d[1]];
                    };

                    [$f0, $sum0] = $row('F', '0 awal');
                    $t->eq($f0, [5, 11], '0 awal: F = 5/11 (SP tanpa View tidak ikut)');
                    $t->eq($row('SP', '0 awal')[0], null, '0 awal: baris SP = null ("-")');
                    $t->eq($code('SP'), '403 ARCHIVE407', '0 awal: detail SP 403');

                    // 1. baris View saja di SP: SP, SPc, SPd (tanpa folder-permission sendiri) terlihat
                    q27_perm($t, $x['SP'], $uid, 1);
                    [$f1, $sum1] = $row('F', '1 View SP');
                    $t->eq($f1, $add($f0, [2, 3]), '1: F +3 dokumen (+2 verified) dari SP, SPc, SPd');
                    $t->eq($sum1, $add($sum0, [2, 3]), '1: ringkasan sama naiknya');
                    $t->eq($row('SP', '1')[0], [2, 3], '1: baris SP = 2/3');
                    $t->eq($code('SPd'), '200', '1: detail SPd (turunan) terbuka');

                    // 2. SPc butuh hak sendiri (folder-permission aktif, tanpa baris): memutus SPc + SPd
                    q27_set_archive($t, $x['SPc'], ['is_folder_permission' => 1]);
                    [$f2, $sum2] = $row('F', '2 SPc terhalang');
                    $t->eq($f2, $add($f0, [1, 1]), '2: hanya dsp1 (langsung di SP) yang ikut');
                    $t->eq($sum2, $add($sum0, [1, 1]), '2: ringkasan: dspc1 & dspd tak ikut');
                    $t->eq($row('SP', '2')[0], [1, 1], '2: baris SP = 1/1');
                    $t->eq($row('SPc', '2')[0], null, '2: baris SPc = null');
                    $t->eq($code('SPc') . '|' . $code('SPd'), '403 ARCHIVE407|403 ARCHIVE407', '2: detail SPc & turunannya SPd 403');

                    // 3. pembuat SPc melihat (BR-6 02): username user uji
                    q27_set_archive($t, $x['SPc'], ['created_by' => $uname]);
                    [$f3, $sum3] = $row('F', '3 pembuat SPc');
                    $t->eq($f3, $add($f0, [2, 3]), '3: SPc & SPd kembali ikut karena user pembuat SPc');
                    $t->eq($sum3, $add($sum0, [2, 3]), '3: ringkasan kembali');
                    q27_set_archive($t, $x['SPc'], ['created_by' => strtoupper($uname)]);
                    $t->eq($row('F', '3b huruf besar')[0], $add($f0, [2, 3]), '3b: pembanding pembuat tidak peka huruf besar');

                    // 4. cabut View di SP: seluruh subtree SP terputus lagi walau pembuat SPc
                    q27_w($t, function ($c) use ($x, $uid) {
                        $c->table('archive_permissions')->where('id_archive', $x['SP'])->where('id_user', $uid)->delete();
                    });
                    [$f4, $sum4] = $row('F', '4 View SP dicabut');
                    $t->eq($f4, $f0, '4: F kembali 5/11');
                    $t->eq($sum4, $sum0, '4: ringkasan kembali');
                    $t->eq($code('SP') . '|' . $code('SPc') . '|' . $code('SPd'), '403 ARCHIVE407|403 ARCHIVE407|403 ARCHIVE407', '4: detail SP, SPc, SPd 403');

                    // 5. baris dengan View=0 (data langsung) tidak memberi akses
                    q27_w($t, function ($c) use ($x, $uid) {
                        $now = date('Y-m-d H:i:s');
                        $c->table('archive_permissions')->insert([
                            'id_archive_permission' => \Modules\V5\Entities\Helper\MyHelper::generateId(), 'id_archive' => $x['SP'], 'id_user' => $uid,
                            'is_view' => 0, 'is_update' => 1, 'is_delete' => 0, 'is_store' => 0,
                            'created_at' => $now, 'created_by' => 'QA27', 'updated_at' => $now, 'updated_by' => 'QA27',
                        ]);
                    });
                    $t->eq($row('F', '5 View=0')[0], $f0, '5: baris is_view=0 tidak membuka SP');
                });
            } finally {
                q27_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'X-3',
        'title' => 'isolasi tenant: dua tenant bergantian membaca DB masing-masing sesudah panggilan fitur ini (butuh >= 2 DB di QA_DBS)',
        'run'   => function ($t) {
            $dbs = $t->dbs();
            if (count($dbs) < 2) {
                $t->skip('QA_DBS hanya berisi ' . count($dbs) . ' DB (' . implode(',', $dbs) . '): isolasi tenant tidak bisa diuji di mesin ini');
            }
            $s0 = $t->session($dbs[0]);
            $s1 = $t->session($dbs[1]);
            $t->status(q27_verif($t, $s0), 200, 'tenant 0');
            $t->status(q27_verif($t, $s1), 200, 'tenant 1');
            $t->isolation($s0, $s1, 10);
        },
    ],
];
