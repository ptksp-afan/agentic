<?php
/**
 * ED-1027 - hitungan verifikasi di list Archive: AC-2 (document_verified per baris, cocok SQL pembanding + angka tangan),
 * AC-3 (is_active -1/0 tak dihitung; Handed Over/Taken dihitung; aksi nyata put-in/hand-over/receive), AC-4 (ringkasan
 * All Archive sama di root/folder/pencarian/halaman = header GET verifications).
 * Semua data uji dipulihkan di finally (q27_cleanup). Oracle = q27_oracle (SQL mentah, tanpa kode BE).
 */
require_once __DIR__ . '/qa_lib.php';

/** Angka tangan fixture q27_tree per mode: [verified, total] per kunci folder, ringkasan fixture (delta), tipe di F. */
$q27_expect = [
    'smr' => [
        'folders' => ['P' => [5, 11], 'F' => [5, 11], 'S1' => [1, 3], 'S1a' => [0, 1], 'S2' => [1, 2], 'SV' => [1, 1], 'OTH' => [0, 1]],
        'null'    => ['SP', 'PRIV'],
        'absent'  => ['SJ', 'SX', 'JOGR', 'dJF', 'dNEG', 'dDEL', 'dR2'],
        'summary' => [6, 13],
        'F_types' => [6 => [1, 2], 7 => [2, 3], 8 => [1, 2], 9 => [0, 1], 29 => [0, 1], 31 => [0, 1], 222 => [1, 1]],
    ],
    'bypass' => [
        'folders' => ['P' => [6, 15], 'F' => [6, 15], 'S1' => [1, 3], 'S1a' => [0, 1], 'S2' => [1, 2], 'SJ' => [0, 1], 'SP' => [1, 2], 'SPc' => [0, 1],
            'SV' => [1, 1], 'OTH' => [0, 1], 'PRIV' => [0, 2], 'PRc' => [0, 1], 'JOGR' => [0, 1]],
        'null'    => [],
        'absent'  => ['SX', 'dNEG', 'dDEL'],
        'summary' => [7, 21],
        'F_types' => [6 => [2, 4], 7 => [2, 5], 8 => [1, 2], 9 => [0, 1], 29 => [0, 1], 31 => [0, 1], 222 => [1, 1]],
    ],
];

/**
 * Bandingkan semua baris respons list dengan oracle: folder = [v,t] terlihat atau null; dokumen = {is_verified, total 1}.
 * Mengembalikan jumlah baris folder/dokumen yang dicek.
 */
$q27_cmp = function ($t, $r, array $o, $label) {
    $t->status($r, 200, $label . ' status');
    $rows = $r[1]['result']['data'] ?? [];
    $db = $t->db();
    $ids = array_column($rows, 'id_archive');
    $verified = $ids ? $db->table('archives')->whereIn('id_archive', $ids)->pluck('is_verified', 'id_archive')->all() : [];
    $nf = $nd = 0;
    foreach ($rows as $row) {
        $t->true(array_key_exists('document_verified', $row), $label . ': baris punya document_verified');
        $t->true(!array_key_exists('is_verified', $row), $label . ': is_verified tidak bocor ke respons');
        $isFolder = ($row['type'] ?? null) === 'Folder';
        $id = $row['id_archive'];
        if ($isFolder) {
            $nf++;
            $exp = isset($o['folders'][$id]) ? [(int) $o['folders'][$id]['v'], (int) $o['folders'][$id]['t']] : null;
            $t->eq(q27_api_vt($row['document_verified']), $exp, $label . ' folder ' . $id);
            if ($row['document_verified'] !== null) {
                $t->eq(array_keys($row['document_verified']), ['verified', 'total'], $label . ': kunci document_verified');
                $t->true(is_int($row['document_verified']['verified']) && is_int($row['document_verified']['total']), $label . ': angka bertipe integer');
            }
        } else {
            $nd++;
            $t->eq($row['document_verified'], ['verified' => (int) ($verified[$id] ?? 0) === 1 ? 1 : 0, 'total' => 1], $label . ' dokumen ' . $id);
        }
    }

    return [$nf, $nd];
};

return [

    [
        'id'    => 'AC-2',
        'title' => 'list root / isi folder / pencarian: document_verified folder = dokumen aktif terlihat folder + subfolder (cocok SQL + angka tangan), dokumen total=1 verified 0|1, folder tanpa View null, folder tak terlihat tak ada; user lokasi Semarang & superadmin (data nyata 34,5 rb dokumen + fixture)',
        'run'   => function ($t) use ($q27_expect, $q27_cmp) {
            $s = $t->session();
            q27_baseline($t);
            try {
                foreach (['smr', 'bypass'] as $mode) {
                    q27_with_user($t, q27_opts($mode), function () use ($t, $s, $mode, $q27_expect, $q27_cmp) {
                        $exp = $q27_expect[$mode];
                        $u = q27_u($t, $mode);

                        // --- data nyata sebelum fixture: root list + ringkasan = oracle
                        $o0 = q27_oracle($t, $u);
                        $r0 = q27_list($t, $s);
                        [$nf0, $nd0] = $q27_cmp($t, $r0, $o0, "[$mode] data nyata root");
                        $t->true($nf0 > 0, "[$mode] data nyata: ada baris folder di root ($nf0 folder, $nd0 dokumen)");
                        $base = q27_api_vt($r0[1]['result']['document_verified_summary']);
                        $t->eq($base, q27_vt($o0['summary']), "[$mode] data nyata: ringkasan = SQL pembanding");

                        $x = q27_tree($t);
                        $o = q27_oracle($t, $u);

                        // --- ringkasan fixture = delta angka tangan
                        $r = q27_list($t, $s);
                        $after = q27_api_vt($r[1]['result']['document_verified_summary']);
                        $t->eq([$after[0] - $base[0], $after[1] - $base[1]], $exp['summary'], "[$mode] delta ringkasan fixture = angka tangan");
                        $t->eq($after, q27_vt($o['summary']), "[$mode] ringkasan = oracle");

                        // --- root list: semua baris = oracle; baris fixture = angka tangan
                        [$nf, $nd] = $q27_cmp($t, $r, $o, "[$mode] root");
                        $map = q27_map($r);
                        foreach ($exp['folders'] as $k => $vt) {
                            if (in_array($k, ['P', 'OTH', 'PRIV', 'JOGR'], true)) {
                                $t->eq(q27_api_vt($map[$x[$k]]['document_verified'] ?? 'tidak ada'), $vt, "[$mode] root: $k = angka tangan");
                            }
                        }
                        foreach ($exp['null'] as $k) {
                            if (in_array($k, ['PRIV'], true)) {
                                $t->true(isset($map[$x[$k]]) && $map[$x[$k]]['document_verified'] === null, "[$mode] root: $k tanpa View = null");
                            }
                        }
                        foreach ($exp['absent'] as $k) {
                            if (in_array($k, ['JOGR', 'dR2'], true)) {
                                $t->true(!isset($map[$x[$k]]), "[$mode] root: $k tak terlihat tidak ada di list");
                            }
                        }
                        $t->eq($map[$x['dR']]['document_verified'], ['verified' => 1, 'total' => 1], "[$mode] root: dokumen verified = 1/1");

                        // --- isi setiap folder fixture yang bisa dibuka: baris = oracle; angka tangan
                        $opened = 0;
                        foreach ($x as $k => $id) {
                            if ($k[0] === 'd' || !isset($o['visible'][$id])) {
                                continue;
                            }
                            $rf = q27_list($t, $s, ['id_archive' => $id]);
                            $q27_cmp($t, $rf, $o, "[$mode] isi $k");
                            $opened++;
                            if ($k === 'F') {
                                $m = q27_map($rf);
                                foreach ($exp['folders'] as $kk => $vt) {
                                    if (in_array($kk, ['S1', 'S2', 'SV', 'SJ', 'SP'], true) && isset($m[$x[$kk]])) {
                                        $t->eq(q27_api_vt($m[$x[$kk]]['document_verified']), $vt, "[$mode] isi F: $kk = angka tangan");
                                    }
                                }
                                foreach ($exp['null'] as $kk) {
                                    if ($kk === 'SP') {
                                        $t->true(isset($m[$x[$kk]]) && $m[$x[$kk]]['document_verified'] === null, "[$mode] isi F: SP tanpa View = null");
                                    }
                                }
                                foreach ($exp['absent'] as $kk) {
                                    $t->true(!isset($m[$x[$kk]]), "[$mode] isi F: $kk tidak ada di list");
                                }
                                // dokumen F
                                $t->eq(q27_api_vt($m[$x['dF1']]['document_verified']), [1, 1], "[$mode] isi F: dF1 verified");
                                $t->eq(q27_api_vt($m[$x['dF2']]['document_verified']), [0, 1], "[$mode] isi F: dF2 unverified");
                                $t->eq(q27_api_vt($m[$x['dF3']]['document_verified']), [1, 1], "[$mode] isi F: dF3 (Handed Over) verified");
                                $t->eq(q27_api_vt($m[$x['dF4']]['document_verified']), [0, 1], "[$mode] isi F: dF4 (Taken) unverified");
                            }
                        }
                        $t->true($opened >= 5, "[$mode] folder fixture yang dibuka: $opened");

                        // --- pencarian: semua level, folder & dokumen fixture
                        $rs = q27_list($t, $s, ['search' => q27_search(['query' => Q27_PREFIX])]);
                        [$sf, $sd] = $q27_cmp($t, $rs, $o, "[$mode] pencarian");
                        $names = array_map(function ($row) {
                            return $row['name'][0]['transaction_no'] ?? null;
                        }, $rs[1]['result']['data']);
                        $t->true($sf >= count($exp['folders']) - 0 && $sd >= 10, "[$mode] pencarian: $sf folder & $sd dokumen fixture muncul");
                        $ms = q27_map($rs);
                        $t->eq(q27_api_vt($ms[$x['F']]['document_verified']), $exp['folders']['F'], "[$mode] pencarian: F = angka tangan");
                        $t->eq(q27_api_vt($ms[$x['S1']]['document_verified']), $exp['folders']['S1'], "[$mode] pencarian: S1 = angka tangan");
                        $t->eq(q27_api_vt($rs[1]['result']['document_verified_summary']), $after, "[$mode] pencarian: ringkasan tetap All Archive");
                        $rt = q27_list($t, $s, ['search' => q27_search(['type' => '7', 'query' => Q27_PREFIX])]);
                        $q27_cmp($t, $rt, $o, "[$mode] pencarian filter Type 7");
                        $rf = q27_list($t, $s, ['search' => q27_search(['type' => 'FOLDER', 'query' => Q27_PREFIX])]);
                        $q27_cmp($t, $rf, $o, "[$mode] pencarian filter Folder");
                        $t->true(isset($rf[1]['result']['data'][0]), "[$mode] filter Folder: ada baris");

                        // --- sort tetap jalan & documentVerified tidak bisa di-sort (diabaikan, bukan 500)
                        $rsrt = q27_list($t, $s, ['sorts' => [json_encode(['sortBy' => 'name', 'sortType' => 'desc'])]]);
                        $q27_cmp($t, $rsrt, $o, "[$mode] sort name desc");
                        $rdv = q27_list($t, $s, ['sorts' => [json_encode(['sortBy' => 'documentVerified', 'sortType' => 'desc'])]]);
                        $q27_cmp($t, $rdv, $o, "[$mode] sort documentVerified (tak bisa di-sort)");

                        q27_cleanup($t, "[$mode] fixture dibuang");
                        q27_baseline($t);
                    });
                }
            } finally {
                q27_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-3',
        'title' => 'is_active -1 (tercetak belum disimpan) dan 0 (dihapus) tak dihitung; put-in nyata mengaktifkan dokumen dan menambah angka; Handed Over (hand-over nyata) & Taken (receive nyata) tetap dihitung; verified tak berubah oleh aksi Archive',
        'run'   => function ($t) {
            $s = $t->session();
            q27_baseline($t);
            try {
                q27_with_user($t, q27_opts('smr'), function () use ($t, $s) {
                    $u = q27_u($t, 'smr');
                    $x = q27_tree($t);
                    $F = $x['F'];
                    // F tidak muncul sebagai baris di isinya sendiri: ambil angka F dari list induk (P)
                    $rootF = function () use ($t, $s, $x) {
                        $r = q27_list($t, $s, ['id_archive' => $x['P']]);
                        $t->status($r, 200, 'isi P');

                        return [q27_api_vt(q27_map($r)[$x['F']]['document_verified']), q27_api_vt($r[1]['result']['document_verified_summary'])];
                    };
                    [$f0, $sum0] = $rootF();
                    $t->eq($f0, [5, 11], 'awal: F = 5/11 (dNEG -1 dan dDEL 0 tak dihitung; dF3 Handed Over & dF4 Taken dihitung)');

                    // dokumen tercetak: is_active -1 di root (seperti DocumentService cetak), verified
                    $dp = q27_doc($t, 'dPRINT', ['active' => -1, 'verified' => 1], 7);
                    $dq = q27_doc($t, 'dPRINT2', ['active' => -1], 8);
                    [$f1, $sum1] = $rootF();
                    $t->eq([$f1, $sum1], [$f0, $sum0], 'dokumen is_active -1 di root: angka & ringkasan tak berubah');
                    $r = q27_list($t, $s);
                    $t->true(!isset(q27_map($r)[$dp]) && !isset(q27_map($r)[$dq]), 'dokumen is_active -1 tidak muncul di list');

                    // put-in nyata ke F: is_active menjadi 1, dokumen dihitung
                    $body = ['id_archive_parent' => $F, 'id_archives' => [$dp, $dq]];
                    $rp = $t->call($s, 'POST', Q27_BASE . '/documents/put-in', $body);
                    $t->status($rp, 200, 'put-in dua dokumen ke F');
                    $t->eq(array_map('intval', [$t->db()->table('archives')->where('id_archive', $dp)->value('is_active'), $t->db()->table('archives')->where('id_archive', $dq)->value('is_active')]), [1, 1], 'put-in: is_active menjadi 1');
                    [$f2, $sum2] = $rootF();
                    $t->eq($f2, [$f0[0] + 1, $f0[1] + 2], 'sesudah put-in: F +1 verified, +2 total');
                    $t->eq($sum2, [$sum0[0] + 1, $sum0[1] + 2], 'sesudah put-in: ringkasan +1 verified, +2 total');
                    $t->eq(q27_api_vt(q27_map(q27_list($t, $s, ['id_archive' => $F]))[$dp]['document_verified']), [1, 1], 'dokumen yang dimasukkan: verified 1/1 (status verified tidak ditulis aksi Archive)');

                    // hand-over nyata: status 2, tetap dihitung
                    $rh = $t->call($s, 'POST', Q27_BASE . '/documents/hand-over', ['name' => q27_n($t, $dq)]);
                    $t->status($rh, 200, 'hand-over');
                    $t->eq((int) q27_row($t, $dq)['status'], 2, 'status dokumen = 2 (Handed Over)');
                    [$f3, $sum3] = $rootF();
                    $t->eq([$f3, $sum3], [$f2, $sum2], 'Handed Over: tetap dihitung, angka tak berubah');
                    $rows = q27_map(q27_list($t, $s, ['id_archive' => $F]));
                    $t->eq($rows[$dq]['document_verified'], ['verified' => 0, 'total' => 1], 'baris Handed Over: 0/1');

                    // receive nyata (pengguna lain): status 3, tetap dihitung
                    $rr = $t->call($s, 'POST', Q27_BASE . '/documents/receive', ['name' => q27_n($t, $dq)]);
                    $t->true($rr[0] === 200, 'receive: HTTP ' . $rr[0]);
                    $t->true(in_array((int) q27_row($t, $dq)['status'], [2, 3], true), 'status sesudah receive 2/3: ' . q27_row($t, $dq)['status']);
                    [$f4, $sum4] = $rootF();
                    $t->eq([$f4, $sum4], [$f2, $sum2], 'sesudah receive: tetap dihitung');

                    // dua dokumen di root: dikeluarkan dari folder (take out) -> masuk root, tetap dihitung di ringkasan
                    $rt = $t->call($s, 'POST', Q27_BASE . '/documents/put-in', ['id_archive_parent' => null, 'id_archives' => [$dq]]);
                    $t->true($rt[0] < 500, 'put-in ke root: HTTP ' . $rt[0]);
                    if ($rt[0] === 200) {
                        [$f5, $sum5] = $rootF();
                        $t->eq($f5, [$f0[0] + 1, $f0[1] + 1], 'dikeluarkan dari F: F -1 total');
                        $t->eq($sum5, [$sum0[0] + 1, $sum0[1] + 2], 'dikeluarkan ke root: ringkasan tetap (+1 verified, +2 total)');
                    }

                    // fixture is_active: dNEG (-1, verified) diaktifkan -> +1/+1 ; dDEL (0) diaktifkan -> +1 total
                    $before = $rootF();
                    q27_set_archive($t, $x['dNEG'], ['is_active' => 1]);
                    $t->eq($rootF()[0], [$before[0][0] + 1, $before[0][1] + 1], 'dNEG diaktifkan: F +1/+1');
                    q27_set_archive($t, $x['dNEG'], ['is_active' => -1]);
                    q27_set_archive($t, $x['dDEL'], ['is_active' => 1]);
                    $t->eq($rootF()[0], [$before[0][0] + 1, $before[0][1] + 1], 'dDEL (verified) diaktifkan: F +1/+1');
                    q27_set_archive($t, $x['dDEL'], ['is_active' => 0]);
                    $t->eq($rootF(), $before, 'keduanya dinonaktifkan lagi: angka kembali');

                    // oracle penuh pada akhir
                    $o = q27_oracle($t, $u);
                    $t->eq(q27_api_vt(q27_list($t, $s)[1]['result']['document_verified_summary']), q27_vt($o['summary']), 'ringkasan akhir = oracle');
                });
            } finally {
                q27_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-4',
        'title' => 'result.document_verified_summary sama di root, di dalam folder, saat mencari/memfilter dan di halaman lain = header GET verifications = oracle (All Archive, tidak ikut folder aktif)',
        'run'   => function ($t) {
            $s = $t->session();
            q27_baseline($t);
            try {
                foreach (['smr', 'bypass'] as $mode) {
                    q27_with_user($t, q27_opts($mode), function () use ($t, $s, $mode) {
                        $x = q27_tree($t);
                        $o = q27_oracle($t, q27_u($t, $mode));
                        $want = q27_vt($o['summary']);

                        $calls = [
                            'root' => [],
                            'folder P' => ['id_archive' => $x['P']],
                            'folder F' => ['id_archive' => $x['F']],
                            'folder S1a (daun)' => ['id_archive' => $x['S1a']],
                            'cari query' => ['search' => q27_search(['query' => Q27_PREFIX])],
                            'cari query tak ada hasil' => ['search' => q27_search(['query' => 'QA27-TIDAK-ADA-' . uniqid()])],
                            'filter type 7' => ['search' => q27_search(['type' => '7'])],
                            'filter Folder' => ['search' => q27_search(['type' => 'FOLDER'])],
                            'halaman 2 (pagination 2)' => ['pagination' => 2, 'page' => 2],
                            'halaman 1 (pagination 1)' => ['pagination' => 1, 'page' => 1],
                            'sort name' => ['sorts' => [json_encode(['sortBy' => 'name', 'sortType' => 'asc'])]],
                        ];
                        foreach ($calls as $label => $q) {
                            $r = q27_list($t, $s, $q);
                            $t->status($r, 200, "[$mode] $label");
                            $t->eq(q27_api_vt($r[1]['result']['document_verified_summary']), $want, "[$mode] ringkasan di $label");
                        }

                        $rv = q27_verif($t, $s);
                        $t->status($rv, 200, "[$mode] GET verifications");
                        $t->eq([(int) $rv[1]['result']['verified'], (int) $rv[1]['result']['total']], $want, "[$mode] header GET verifications = ringkasan list");
                        q27_cleanup($t, "[$mode] fixture dibuang");
                        q27_baseline($t);
                    });
                }
            } finally {
                q27_cleanup($t);
            }
        },
    ],
];
