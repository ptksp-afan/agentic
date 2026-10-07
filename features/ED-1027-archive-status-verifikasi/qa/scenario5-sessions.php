<?php
/**
 * ED-1027 - sesi opname & status verified dari opname ED-1026: AC-10 (last_sessions All Archive: 3 terbaru terkonfirmasi,
 * terlihat sesuai K-4 + EPIC K-1 a), AC-11 (per folder: sesi yang mencakup folder / subfolder terlihat, potongan snapshot),
 * AC-12 (Confirm Opname menaikkan / menurunkan angka). Sesi dibuat lewat API opname 03; "user lain" = created_by diubah
 * di DB. Semua data uji dipulihkan di finally (q27_cleanup: hapus 3 tabel opname sesi tercatat + reset kolom verifikasi).
 */
require_once __DIR__ . '/qa_lib.php';

/** Jam server dengan selisih (mis. '-5 days'). */
$q27_ts = function ($modify) {
    return date('Y-m-d H:i:s', strtotime($modify));
};

/** Kunci ringkas id sesi dari respons last_sessions. */
$q27_ids = function ($res, array $names) {
    $out = [];
    foreach ($res['last_sessions'] ?? [] as $row) {
        $out[] = array_search($row['id_archive_opname'], $names, true);
    }

    return $out;
};

return [

    [
        'id'    => 'AC-10',
        'title' => 'GET verifications last_sessions: maks 3 sesi terkonfirmasi terbaru, urut waktu turun, field sesuai kontrak dan = angka tersimpan saat Confirm (tak dihitung ulang); draft/batal tak ikut; visibilitas K-4 + EPIC K-1 a (root semua; folder terlihat; pembuat; superadmin semua; folder dihapus/tak terlihat hanya superadmin & pembuat)',
        'run'   => function ($t) use ($q27_ts, $q27_ids) {
            $s = $t->session();
            q27_baseline($t);
            try {
                q27_with_user($t, ['role' => 1], function ($set) use ($t, $s, $q27_ts, $q27_ids) {
                    $x = q27_tree($t);
                    $uname = q27_uname($t);
                    $N = function ($k) use ($t, $x) {
                        return q27_n($t, $x[$k]);
                    };
                    $other = 'otheruser';
                    $ids = [];

                    // A: F + S1 (scan sah + tak ditemukan + tak valid), B: S2, C: root + OTH, D: SJ (JOG saja), E: SP (tanpa View), G: SJ buatan user uji, Z: OTH
                    $ids['A'] = q27_run($t, $s, $x['F'], q27_sel([$x['S1']]), [$N('dF2'), $N('d11'), 'QA27-TIDAK-ADA-' . uniqid(), $N('dO1')]);
                    $ids['B'] = q27_run($t, $s, $x['S2'], [], [$N('d21')]);
                    $ids['C'] = q27_run($t, $s, null, q27_sel([$x['OTH']]), [$N('dO1'), $N('dR')]);
                    $ids['D'] = q27_run($t, $s, $x['SJ'], [], [$N('dj1')]);
                    $ids['E'] = q27_run($t, $s, $x['SP'], [], [$N('dsp1')]);
                    $ids['G'] = q27_run($t, $s, $x['SJ'], [], []);
                    $ids['Z'] = q27_run($t, $s, $x['OTH'], [], [$N('dO1')]);
                    // draft (belum dikonfirmasi) dan sesi batal
                    $ids['H'] = q27_session($t, $s, $x['F'], q27_sel([$x['S2']]));
                    $ids['K'] = q27_session($t, $s, $x['S1a']);
                    $t->status($t->call($s, 'DELETE', Q27_BASE . '/opnames/' . $ids['K']), 200, 'batalkan sesi K');
                    $t->true(q27_opname($t, $ids['H'])['status'] != 2 && q27_opname($t, $ids['K'])['status'] != 2, 'H draft dan K batal: status bukan terkonfirmasi');

                    foreach (['A' => '-5 days', 'B' => '-4 days', 'C' => '-3 days', 'D' => '-2 days', 'E' => '-1 day', 'G' => '-12 hours', 'Z' => '-1 hour'] as $k => $when) {
                        q27_set_opname($t, $ids[$k], ['confirmed_at' => $q27_ts($when)]);
                    }
                    foreach (['A', 'B', 'C', 'D', 'E', 'Z'] as $k) {
                        q27_set_owner($t, $ids[$k], $other);
                    }
                    // G tetap milik user uji (pembuat sesi di folder yang tak terlihat user Semarang)
                    q27_set_owner($t, $ids['H'], $other);

                    // scope tiap sesi menurut GET opnames/{id} (superadmin boleh membaca sesi terkonfirmasi)
                    $scopes = [];
                    foreach ($ids as $k => $id) {
                        if (in_array($k, ['H', 'K'], true)) {
                            continue;
                        }
                        $show = $t->call($s, 'GET', Q27_BASE . '/opnames/' . $id);
                        $t->status($show, 200, "GET opnames/$k");
                        $scopes[$id] = $show[1]['result']['scope'];
                    }
                    $get = function ($label, array $expect) use ($t, $s, $ids, $q27_ids) {
                        $r = q27_verif($t, $s);
                        $t->status($r, 200, "$label status");
                        $res = $r[1]['result'];
                        $t->true(count($res['last_sessions']) <= 3, "$label: maksimal 3 sesi");
                        $t->eq($q27_ids($res, $ids), $expect, "$label: last_sessions = " . implode(',', $expect));
                        foreach (['H', 'K'] as $bad) {
                            $t->true(!in_array($ids[$bad], array_column($res['last_sessions'], 'id_archive_opname'), true), "$label: sesi $bad (draft/batal) tidak muncul");
                        }

                        return $res;
                    };

                    // --- superadmin (role 1): semua sesi terkonfirmasi, 3 terbaru
                    $res = $get('superadmin', ['Z', 'G', 'E']);

                    // --- bentuk & isi baris sesi (kontrak) = baris DB saat Confirm = scope GET opnames/{id}
                    $keys = ['id_archive_opname', 'confirmed_at', 'created_by', 'scope', 'total_documents', 'verified_count', 'not_found_count', 'invalid_count'];
                    $shape = function ($label, $res) use ($t, $ids, $keys, $scopes) {
                        foreach ($res['last_sessions'] as $row) {
                            $k = array_search($row['id_archive_opname'], $ids, true);
                            $db = q27_opname($t, $row['id_archive_opname']);
                            $sortedKeys = array_keys($row);
                            sort($sortedKeys);
                            $want = $keys;
                            sort($want);
                            $t->eq($sortedKeys, $want, "$label sesi $k: kunci sesuai kontrak (tanpa id_user/username)");
                            $t->eq(array_keys($row['scope']), ['is_root', 'folder_name', 'first_name', 'other_count'], "$label sesi $k: kunci scope");
                            $t->eq($row['scope'], $scopes[$row['id_archive_opname']], "$label sesi $k: scope = scope GET opnames/{id}");
                            $t->true(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['confirmed_at']) === 1, "$label sesi $k: confirmed_at format Y-m-d H:i:s");
                            $t->eq($row['confirmed_at'], $db['confirmed_at'], "$label sesi $k: confirmed_at = DB");
                            $t->eq($row['created_by'], $db['created_by'], "$label sesi $k: created_by = DB");
                            foreach (['total_documents', 'verified_count', 'not_found_count', 'invalid_count'] as $f) {
                                $t->true(is_int($row[$f]), "$label sesi $k: $f integer");
                                $t->eq($row[$f], (int) $db[$f], "$label sesi $k: $f = angka tersimpan");
                            }
                        }
                    };
                    $shape('superadmin', $res);

                    // contoh sesi A: F + S1 -> folder_name F, first_name S1, other_count 0; sesi C root; angka A
                    $dbA = q27_opname($t, $ids['A']);
                    $t->eq($scopes[$ids['A']], ['is_root' => false, 'folder_name' => q27_n($t, $x['F']), 'first_name' => q27_n($t, $x['S1']), 'other_count' => 0], 'sesi A: scope F + S1');
                    $t->eq($scopes[$ids['C']]['is_root'], true, 'sesi C: scope root');
                    $t->true((int) $dbA['not_found_count'] >= 1 && (int) $dbA['invalid_count'] >= 1 && (int) $dbA['verified_count'] >= 2, 'sesi A: ada Verified, Not found, Invalid (' . json_encode(array_intersect_key($dbA, array_flip(['total_documents', 'verified_count', 'not_found_count', 'invalid_count']))) . ')');

                    // sesi dengan tiga sesi lain dikecualikan: periksa scope untuk A,B,C,D,E lewat set per user di bawah
                    // --- user Semarang (SMR): Z, G (milik sendiri di SJ), C (root), B, A ; D (SJ) dan E (SP) tak terlihat
                    $set(q27_opts('smr'));
                    $resSmr = $get('smr', ['Z', 'G', 'C']);
                    $shape('smr', $resSmr);

                    // --- user JOG: Z, G, D (SJ terlihat JOG), C, B, A ; E (SP tanpa View) tak
                    $set(q27_opts('jog'));
                    $get('jog', ['Z', 'G', 'D']);

                    // --- SMR+JOG
                    $set(q27_opts('smrjog'));
                    $get('smr+jog', ['Z', 'G', 'D']);

                    // --- superadmin role 2
                    $set(['role' => 2]);
                    $get('superadmin role 2', ['Z', 'G', 'E']);

                    // --- role List Archive saja (lokasi asli QA_USER) tidak 500, sesi root & buatan sendiri tetap
                    $set(['role' => Q27_ROLE_LIST_ONLY, 'emp' => ['SMR']]);
                    $r = q27_verif($t, $s);
                    $t->status($r, 200, 'role List Archive saja (SMR)');
                    $t->true(in_array('G', $q27_ids($r[1]['result'], $ids), true) && in_array('Z', $q27_ids($r[1]['result'], $ids), true), 'role List Archive saja: sesi Z (OTH) dan G (sendiri) terlihat');

                    // --- folder dihapus/tak terlihat: SJ dinonaktifkan -> D tak terlihat user JOG; G tetap (pembuat); superadmin tetap
                    $set(q27_opts('jog'));
                    q27_set_archive($t, $x['SJ'], ['is_active' => 0]);
                    $get('jog (SJ dinonaktifkan)', ['Z', 'G', 'C']);
                    $set(['role' => 1]);
                    $get('superadmin (SJ dinonaktifkan)', ['Z', 'G', 'E']);
                    $set(q27_opts('jog'));
                    // pembuat D = user uji (huruf besar) -> terlihat lagi walau SJ nonaktif
                    q27_set_owner($t, $ids['D'], strtoupper($uname));
                    $get('jog (pembuat D, SJ nonaktif)', ['Z', 'G', 'D']);
                    q27_set_owner($t, $ids['D'], $other);
                    q27_set_archive($t, $x['SJ'], ['is_active' => 1]);

                    // --- sesi root terlihat semua pemegang List Archive walau OTH dihapus
                    $set(q27_opts('smr'));
                    q27_set_archive($t, $x['OTH'], ['is_active' => 0]);
                    $r = $get('smr (OTH nonaktif)', ['G', 'C', 'B']);
                    q27_set_archive($t, $x['OTH'], ['is_active' => 1]);

                    // --- urutan sama waktu: id sesi turun
                    $set(['role' => 1]);
                    $same = $q27_ts('-30 minutes');
                    q27_set_opname($t, $ids['A'], ['confirmed_at' => $same]);
                    q27_set_opname($t, $ids['B'], ['confirmed_at' => $same]);
                    q27_set_opname($t, $ids['Z'], ['confirmed_at' => $q27_ts('-40 minutes')]);
                    q27_set_opname($t, $ids['G'], ['confirmed_at' => $q27_ts('-50 minutes')]);
                    $expectOrder = strcmp($ids['A'], $ids['B']) > 0 ? ['A', 'B', 'Z'] : ['B', 'A', 'Z'];
                    $get('urutan waktu sama', $expectOrder);

                    // --- hanya satu sesi terkonfirmasi tersisa + draft + batal: tidak ada yang bocor
                    $victims = [$ids['B'], $ids['C'], $ids['D'], $ids['E'], $ids['G'], $ids['Z']];
                    q27_w($t, function ($c) use ($victims) {
                        $c->table('archive_opname_documents')->whereIn('id_archive_opname', $victims)->delete();
                        $c->table('archive_opname_folders')->whereIn('id_archive_opname', $victims)->delete();
                        $c->table('archive_opnames')->whereIn('id_archive_opname', $victims)->delete();
                    });
                    $get('satu sesi tersisa (superadmin)', ['A']);
                    $set(q27_opts('smr'));
                    $get('satu sesi tersisa (smr)', ['A']);

                    // --- sesi yang dikonfirmasi lalu data berubah: angka sesi tetap (tidak dihitung ulang)
                    $set(['role' => 1]);
                    $before = q27_verif($t, $s)[1]['result']['last_sessions'][0];
                    q27_w($t, function ($c) use ($x) {
                        $c->table('archives')->whereIn('id_archive', [$x['dF2'], $x['d11']])->update(['is_verified' => 0]);
                        $c->table('archives')->where('id_archive', $x['dF1'])->update(['is_active' => 0]);
                    });
                    $afterRow = q27_verif($t, $s)[1]['result']['last_sessions'][0];
                    $t->eq($afterRow, $before, 'angka sesi tidak berubah sesudah data dokumen berubah (bukan dihitung ulang)');

                    // --- tanpa sesi sama sekali
                    q27_w($t, function ($c) use ($ids) {
                        $c->table('archive_opname_documents')->whereIn('id_archive_opname', array_values($ids))->delete();
                        $c->table('archive_opname_folders')->whereIn('id_archive_opname', array_values($ids))->delete();
                        $c->table('archive_opnames')->whereIn('id_archive_opname', array_values($ids))->delete();
                    });
                    $r = q27_verif($t, $s);
                    $t->status($r, 200, 'tanpa sesi');
                    $t->eq($r[1]['result']['last_sessions'], [], 'tanpa sesi: last_sessions = []');
                });
            } finally {
                q27_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-11',
        'title' => 'GET verifications/{F}: sesi yang hanya mencakup subfolder F muncul, sesi lain tidak; folder_total_documents / folder_verified = potongan snapshot (bukan angka saat ini, tetap saat data berubah); subfolder tak terlihat tak ikut; folder dipindah mengikuti posisi sekarang; maks 3',
        'run'   => function ($t) use ($q27_ts, $q27_ids) {
            $s = $t->session();
            q27_baseline($t);
            try {
                q27_with_user($t, ['role' => 1], function ($set) use ($t, $s, $q27_ts, $q27_ids) {
                    $x = q27_tree($t);
                    $N = function ($k) use ($t, $x) {
                        return q27_n($t, $x[$k]);
                    };
                    $ids = [];
                    // X1: S1 (+ S1a dicentang): rows S1 2 dok (d11,d12), S1a 1 dok (dA1)
                    $ids['X1'] = q27_run($t, $s, $x['S1'], q27_sel([$x['S1a']]), [$N('d11'), $N('dA1')]);
                    // X2: OTH saja
                    $ids['X2'] = q27_run($t, $s, $x['OTH'], [], [$N('dO1')]);
                    // X3: F + S2: rows F 6 dok (dF1..dF5, dJF), S2 2 dok
                    $ids['X3'] = q27_run($t, $s, $x['F'], q27_sel([$x['S2']]), [$N('dF1'), $N('d21')]);
                    // X4: SJ (JOG saja) : 1 dok
                    $ids['X4'] = q27_run($t, $s, $x['SJ'], [], [$N('dj1')]);
                    // X5: F saja: rows F 6 dok
                    $ids['X5'] = q27_run($t, $s, $x['F'], [], [$N('dF2')]);
                    foreach (['X1' => '-4 days', 'X3' => '-1 day', 'X4' => '-2 hours', 'X5' => '-1 hour', 'X2' => '-30 minutes'] as $k => $when) {
                        q27_set_opname($t, $ids[$k], ['confirmed_at' => $q27_ts($when)]);
                        q27_set_owner($t, $ids[$k], 'otheruser');
                    }

                    $detail = function ($label, $folder, array $expectIds, array $expectSlice) use ($t, $s, $x, $ids, $q27_ids) {
                        $r = q27_verif($t, $s, $x[$folder]);
                        $t->status($r, 200, "$label status");
                        $res = $r[1]['result'];
                        $t->eq($q27_ids($res, $ids), $expectIds, "$label: sesi = " . implode(',', $expectIds));
                        foreach ($res['last_sessions'] as $row) {
                            $k = array_search($row['id_archive_opname'], $ids, true);
                            $t->true(array_key_exists('folder_total_documents', $row) && array_key_exists('folder_verified', $row), "$label sesi $k: ada folder_total_documents & folder_verified");
                            $t->true(is_int($row['folder_total_documents']) && is_int($row['folder_verified']), "$label sesi $k: slice integer");
                            if (isset($expectSlice[$k])) {
                                $t->eq([$row['folder_total_documents'], $row['folder_verified']], $expectSlice[$k], "$label sesi $k: potongan [dokumen folder, verified di folder ini]");
                            }
                            $db = q27_opname($t, $row['id_archive_opname']);
                            $t->eq($row['total_documents'], (int) $db['total_documents'], "$label sesi $k: total_documents sesi = angka sesi");
                            $t->true($row['folder_total_documents'] <= $row['total_documents'], "$label sesi $k: potongan <= total sesi");
                        }

                        return $res;
                    };

                    // user Semarang: F subtree terlihat = F, S1, S1a, S2, SV
                    $set(q27_opts('smr'));
                    $detail('smr F', 'F', ['X5', 'X3', 'X1'], ['X5' => [6, 1], 'X3' => [8, 2], 'X1' => [3, 2]]);
                    $detail('smr P (induk)', 'P', ['X5', 'X3', 'X1'], ['X5' => [6, 1], 'X3' => [8, 2], 'X1' => [3, 2]]);
                    $detail('smr S1', 'S1', ['X1'], ['X1' => [3, 2]]);
                    $detail('smr S1a (daun)', 'S1a', ['X1'], ['X1' => [1, 1]]);
                    $detail('smr S2', 'S2', ['X3'], ['X3' => [2, 1]]);
                    $detail('smr SV (belum pernah diopname)', 'SV', [], []);
                    $detail('smr OTH', 'OTH', ['X2'], ['X2' => [1, 1]]);

                    // superadmin: SJ ikut subtree F -> X4 muncul; maks 3 terbaru
                    $set(['role' => 1]);
                    $detail('superadmin F', 'F', ['X5', 'X4', 'X3'], ['X5' => [6, 1], 'X4' => [1, 1], 'X3' => [8, 2]]);
                    $detail('superadmin SJ', 'SJ', ['X4'], ['X4' => [1, 1]]);
                    // JOG
                    $set(q27_opts('jog'));
                    $detail('jog F', 'F', ['X5', 'X4', 'X3'], ['X5' => [6, 1], 'X4' => [1, 1], 'X3' => [8, 2]]);

                    // --- potongan = snapshot: data berubah, potongan tetap, angka folder sekarang berubah
                    $set(q27_opts('smr'));
                    $listF = function () use ($t, $s, $x) {
                        $r = q27_list($t, $s, ['id_archive' => $x['P']]);

                        return q27_api_vt(q27_map($r)[$x['F']]['document_verified']);
                    };
                    $f0 = $listF();
                    $d13 = q27_doc($t, 'd13', ['parent' => $x['S1']], 6);
                    q27_w($t, function ($c) use ($x) {
                        $c->table('archives')->where('id_archive', $x['d12'])->update(['is_active' => 0]);
                        $c->table('archives')->where('id_archive', $x['d11'])->update(['is_verified' => 0]);
                    });
                    $detail('smr F sesudah data berubah', 'F', ['X5', 'X3', 'X1'], ['X5' => [6, 1], 'X3' => [8, 2], 'X1' => [3, 2]]);
                    $f1 = $listF();
                    $t->true($f1 !== $f0, "angka folder sekarang berubah ($f0[0]/$f0[1] -> $f1[0]/$f1[1]) sementara potongan sesi tetap");

                    // --- folder dipindah: ikut posisi sekarang (S1 -> di bawah OTH)
                    q27_set_archive($t, $x['S1'], ['id_archive_parent' => $x['OTH']]);
                    $detail('smr F sesudah S1 dipindah', 'F', ['X5', 'X3'], ['X5' => [6, 1], 'X3' => [8, 2]]);
                    $detail('smr OTH sesudah S1 dipindah ke bawahnya', 'OTH', ['X2', 'X1'], ['X2' => [1, 1], 'X1' => [3, 2]]);
                    q27_set_archive($t, $x['S1'], ['id_archive_parent' => $x['F']]);
                    $detail('smr F sesudah S1 dikembalikan', 'F', ['X5', 'X3', 'X1'], ['X5' => [6, 1], 'X3' => [8, 2], 'X1' => [3, 2]]);

                    // --- subfolder tak terlihat tak ikut: S2 dinonaktifkan -> X3 hanya lewat F sendiri (slice tanpa S2)
                    q27_set_archive($t, $x['S2'], ['is_active' => 0]);
                    $detail('smr F sesudah S2 dinonaktifkan', 'F', ['X5', 'X3', 'X1'], ['X5' => [6, 1], 'X3' => [6, 1], 'X1' => [3, 2]]);
                    q27_set_archive($t, $x['S2'], ['is_active' => 1]);
                });
            } finally {
                q27_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-12',
        'title' => 'Confirm Opname 03 memverifikasi dokumen X di folder F: X verified=1; angka F, induknya, ringkasan, baris tipe X naik 1; sesi ulang tanpa lanjut yang tak men-scan X: X kembali unverified dan angka turun; Lanjut mempertahankan; Not found/Invalid tak mengubah angka; ganti hari tidak me-reset',
        'run'   => function ($t) use ($q27_ts) {
            $s = $t->session();
            q27_baseline($t);
            try {
                q27_with_user($t, q27_opts('smr'), function () use ($t, $s, $q27_ts) {
                    $x = q27_tree($t);
                    $N = function ($k) use ($t, $x) {
                        return q27_n($t, $x[$k]);
                    };
                    $u = q27_u($t, 'smr');
                    $snap = function ($label) use ($t, $s, $x, $u) {
                        $rp = q27_list($t, $s);
                        $rf = q27_list($t, $s, ['id_archive' => $x['P']]);
                        $rd = q27_verif($t, $s, $x['F']);
                        $ra = q27_verif($t, $s);
                        $t->status($rp, 200, "$label root");
                        $t->status($rf, 200, "$label P");
                        $t->status($rd, 200, "$label detail F");
                        $t->status($ra, 200, "$label detail All");
                        $types = [];
                        foreach ($rd[1]['result']['transaction_types'] as $row) {
                            $types[$row['transaction_type']] = [$row['verified'], $row['total']];
                        }
                        $typesAll = [];
                        foreach ($ra[1]['result']['transaction_types'] as $row) {
                            $typesAll[$row['transaction_type']] = [$row['verified'], $row['total']];
                        }
                        $o = q27_oracle($t, $u);
                        $out = [
                            'P'        => q27_api_vt(q27_map($rp)[$x['P']]['document_verified']),
                            'F'        => q27_api_vt(q27_map($rf)[$x['F']]['document_verified']),
                            'detailF'  => [$rd[1]['result']['verified'], $rd[1]['result']['total']],
                            'summary'  => q27_api_vt($rp[1]['result']['document_verified_summary']),
                            'all'      => [$ra[1]['result']['verified'], $ra[1]['result']['total']],
                            'typesF'   => $types,
                            'typesAll' => $typesAll,
                            'dF2'      => q27_api_vt(q27_map(q27_list($t, $s, ['id_archive' => $x['F']]))[$x['dF2']]['document_verified']),
                        ];
                        $t->eq($out['F'], q27_vt($o['folders'][$x['F']]), "$label: F = oracle");
                        $t->eq($out['summary'], q27_vt($o['summary']), "$label: ringkasan = oracle");

                        return $out;
                    };
                    $diff = function ($a, $b) {
                        return [$b[0] - $a[0], $b[1] - $a[1]];
                    };

                    $t0 = $snap('awal');
                    $t->eq($t0['F'], [5, 11], 'awal: F 5/11');
                    $t->eq($t0['dF2'], [0, 1], 'awal: dF2 unverified');
                    $t->eq((int) q27_row($t, $x['dF2'])['is_verified'], 0, 'awal: dF2 is_verified 0');

                    // ---- sesi A: scan dF1 (sudah verified), dF2 (belum), dF3 (sudah verified); Not found & Invalid tak mengubah angka
                    $a = q27_session($t, $s, $x['F'], []);
                    foreach ([$N('dF1'), $N('dF2'), $N('dF3'), 'QA27-TIDAK-ADA-' . uniqid(), $N('dO1')] as $code) {
                        $t->status(q27_scan($t, $s, $a, $code), 200, "scan $code");
                    }
                    $tScan = $snap('draft (sebelum Confirm)');
                    $t->eq($tScan['F'], $t0['F'], 'sebelum Confirm angka F tidak berubah (scan belum menulis status)');
                    $t->status(q27_confirm($t, $s, $a), 200, 'confirm A');
                    sleep(1);
                    $t1 = $snap('sesudah sesi A');
                    $t->eq((int) q27_row($t, $x['dF2'])['is_verified'], 1, 'sesudah A: dF2 is_verified 1');
                    $t->eq($diff($t0['F'], $t1['F']), [1, 0], 'sesudah A: F +1 verified, total tetap');
                    $t->eq($diff($t0['P'], $t1['P']), [1, 0], 'sesudah A: induk P +1');
                    $t->eq($diff($t0['summary'], $t1['summary']), [1, 0], 'sesudah A: ringkasan +1');
                    $t->eq($diff($t0['all'], $t1['all']), [1, 0], 'sesudah A: header All +1');
                    $t->eq($diff($t0['detailF'], $t1['detailF']), [1, 0], 'sesudah A: header detail F +1');
                    $t->eq($diff($t0['typesF'][7], $t1['typesF'][7]), [1, 0], 'sesudah A: baris tipe 7 (DO) di F +1');
                    $t->eq($diff($t0['typesAll'][7], $t1['typesAll'][7]), [1, 0], 'sesudah A: baris tipe 7 di All +1');
                    foreach ([6, 8, 9, 29, 31, 222] as $tp) {
                        $t->eq($t1['typesF'][$tp], $t0['typesF'][$tp], "sesudah A: tipe $tp di F tidak berubah");
                    }
                    $t->eq($t1['dF2'], [1, 1], 'sesudah A: baris dokumen dF2 verified 1/1');
                    // subfolder tak dicentang tak tersentuh
                    $t->eq((int) q27_row($t, $x['d11'])['is_verified'], 1, 'sesudah A: d11 (subfolder S1 tak dicentang, verified) tetap verified');

                    // ---- ganti hari tidak me-reset (K-1 a): geser waktu sesi & verified_at ke 3 hari lalu
                    q27_set_opname($t, $a, ['confirmed_at' => $q27_ts('-3 days'), 'created_at' => $q27_ts('-3 days')]);
                    q27_set_archive($t, $x['dF2'], ['verified_at' => $q27_ts('-3 days')]);
                    $tDay = $snap('3 hari kemudian');
                    $t->eq($tDay['F'], $t1['F'], 'ganti hari: angka F tidak di-reset');
                    $t->eq($tDay['summary'], $t1['summary'], 'ganti hari: ringkasan tidak di-reset');

                    // ---- sesi B (hari ini): timpa (is_continue false): hanya scan dF1 -> dF2 & dF3 kembali unverified
                    $b = q27_session($t, $s, $x['F'], [], false);
                    $t->status(q27_scan($t, $s, $b, $N('dF1')), 200, 'scan dF1 (B)');
                    $t->status(q27_confirm($t, $s, $b), 200, 'confirm B');
                    sleep(1);
                    $t2 = $snap('sesudah sesi B (timpa)');
                    $t->eq((int) q27_row($t, $x['dF2'])['is_verified'], 0, 'sesudah B: dF2 kembali unverified');
                    $t->eq((int) q27_row($t, $x['dF3'])['is_verified'], 0, 'sesudah B: dF3 (tak discan) unverified');
                    $t->eq((int) q27_row($t, $x['dF1'])['is_verified'], 1, 'sesudah B: dF1 (discan) verified');
                    $t->eq($diff($t1['F'], $t2['F']), [-2, 0], 'sesudah B: F turun 2 (dF2, dF3)');
                    $t->eq($diff($t1['summary'], $t2['summary']), [-2, 0], 'sesudah B: ringkasan turun 2');
                    $t->eq($diff($t1['typesF'][7], $t2['typesF'][7]), [-1, 0], 'sesudah B: baris tipe 7 turun 1');
                    $t->eq($diff($t1['typesF'][8], $t2['typesF'][8]), [-1, 0], 'sesudah B: baris tipe 8 turun 1 (dF3)');
                    $t->eq($t2['dF2'], [0, 1], 'sesudah B: baris dokumen dF2 0/1');
                    $t->eq($t2['F'], [4, 11], 'sesudah B: F = 4/11 (dF1 + d11 + d22 + dsv1)');

                    // ---- sesi C (hari ini, Lanjut aktif): scan dF5 saja -> dF1 (tak discan) tetap verified, dF5 verified
                    sleep(1);
                    $c = q27_session($t, $s, $x['F'], [], true);
                    $t->status(q27_scan($t, $s, $c, $N('dF5')), 200, 'scan dF5 (C)');
                    $t->status(q27_confirm($t, $s, $c), 200, 'confirm C');
                    $t3 = $snap('sesudah sesi C (Lanjut)');
                    $t->eq((int) q27_row($t, $x['dF1'])['is_verified'], 1, 'sesudah C: dF1 (tak discan, Lanjut) tetap verified');
                    $t->eq((int) q27_row($t, $x['dF5'])['is_verified'], 1, 'sesudah C: dF5 verified');
                    $t->eq($diff($t2['F'], $t3['F']), [1, 0], 'sesudah C: F +1');
                    $t->eq($diff($t2['typesF'][9], $t3['typesF'][9]), [1, 0], 'sesudah C: baris tipe 9 +1');
                    $t->eq($t3['F'], [5, 11], 'sesudah C: F = 5/11');

                    // ---- last_sessions mencatat angka tiap sesi saat Confirm
                    $rv = q27_verif($t, $s);
                    $row = null;
                    foreach ($rv[1]['result']['last_sessions'] as $r) {
                        if ($r['id_archive_opname'] === $c) {
                            $row = $r;
                        }
                    }
                    $t->true($row !== null, 'sesi C muncul di last_sessions All (sesi terbaru)');
                    $dbc = q27_opname($t, $c);
                    $t->eq([$row['total_documents'], $row['verified_count']], [(int) $dbc['total_documents'], (int) $dbc['verified_count']], 'angka sesi C = angka tersimpan saat Confirm');
                });
            } finally {
                q27_cleanup($t);
            }
        },
    ],
];
