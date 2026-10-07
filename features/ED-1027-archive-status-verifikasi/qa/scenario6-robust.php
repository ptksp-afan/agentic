<?php
/**
 * ED-1027 - ketahanan & kontrak: X-4 (data tak lazim: induk berputar, folder sesi sudah terhapus fisik, nama folder
 * karakter khusus, sesi dari folder induk tak terlihat dengan subfolder terlihat), X-5 (keadaan user asli QA_USER =
 * is_all_location, user tanpa lokasi, pesan 200 dua bahasa, waktu respons data nyata 34,5 rb dokumen).
 * Semua data uji dipulihkan di finally (q27_cleanup). Oracle = q27_oracle (SQL mentah, tanpa kode BE).
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'X-4',
        'title' => 'data tak lazim tidak membuat 500/loop: induk berputar, folder sesi dihapus fisik, nama folder karakter khusus; sesi dari folder induk tak terlihat dengan subfolder terlihat tampil di detail subfolder itu',
        'run'   => function ($t) {
            $s = $t->session();
            q27_baseline($t);
            try {
                q27_with_user($t, ['role' => 1], function ($set) use ($t, $s) {
                    $x = q27_tree($t);
                    $N = function ($k) use ($t, &$x) {
                        return q27_n($t, $x[$k]);
                    };

                    // --- induk berputar CY1 <-> CY2 (tak mungkin lewat API; guard visited)
                    $x['CY1'] = q27_folder($t, 'CY1');
                    $x['CY2'] = q27_folder($t, 'CY2', ['parent' => $x['CY1']]);
                    q27_set_archive($t, $x['CY1'], ['id_archive_parent' => $x['CY2']]);
                    $x['cy1'] = q27_doc($t, 'cy1', ['parent' => $x['CY1'], 'verified' => 1], 6);
                    $x['cy2'] = q27_doc($t, 'cy2', ['parent' => $x['CY2']], 7);
                    $r = q27_list($t, $s);
                    $t->status($r, 200, 'root dengan folder berputar (ringkasan dihitung, tidak loop)');
                    $r = q27_verif($t, $s);
                    $t->status($r, 200, 'GET verifications dengan folder berputar');
                    $t->true($r[1]['result']['total'] > 0, 'All: total > 0');
                    $r = q27_verif($t, $s, $x['CY1']);
                    $t->status($r, 200, 'GET verifications/{CY1} (induk berputar)');
                    $t->eq([$r[1]['result']['verified'], $r[1]['result']['total']], [1, 2], 'CY1: subtree CY1+CY2 = 1/2 tanpa hitung ganda');
                    $r = q27_verif($t, $s, $x['CY2']);
                    $t->status($r, 200, 'GET verifications/{CY2}');
                    $t->eq([$r[1]['result']['verified'], $r[1]['result']['total']], [1, 2], 'CY2: 1/2');
                    q27_w($t, function ($c) use ($x) {
                        $c->table('archives')->whereIn('id_archive', [$x['CY1'], $x['CY2']])->update(['id_archive_parent' => null]);
                    });

                    // --- nama karakter khusus
                    $weird = "QA27-é\"'<>&💥 \\ %_";
                    $x['WD'] = q27_add($t, ['name' => $weird . '-' . uniqid(), 'type' => 1, 'parent' => $x['P']]);
                    $x['wd1'] = q27_doc($t, 'wd1', ['parent' => $x['WD'], 'verified' => 1], 8);
                    $r = q27_verif($t, $s, $x['WD']);
                    $t->status($r, 200, 'folder nama karakter khusus');
                    $t->eq($r[1]['result']['name'], q27_n($t, $x['WD']), 'nama folder dikirim apa adanya (JSON valid)');
                    $t->eq([$r[1]['result']['verified'], $r[1]['result']['total']], [1, 1], 'folder nama khusus: 1/1');

                    // --- sesi di folder yang kemudian dihapus fisik
                    $x['GONE'] = q27_folder($t, 'GONE', ['parent' => $x['P']]);
                    $x['gn1'] = q27_doc($t, 'gn1', ['parent' => $x['GONE']], 6);
                    $gone = q27_run($t, $s, $x['GONE'], [], [$N('gn1')]);
                    q27_set_owner($t, $gone, 'otheruser');
                    q27_w($t, function ($c) use ($x) {
                        $c->table('archive_documents')->where('id_archive', $x['gn1'])->delete();
                        $c->table('archives')->where('id_archive', $x['gn1'])->delete();
                        $c->table('archives')->where('id_archive', $x['GONE'])->delete();
                    });
                    $r = q27_verif($t, $s);
                    $t->status($r, 200, 'All: sesi dengan folder dihapus fisik (superadmin)');
                    $row = null;
                    foreach ($r[1]['result']['last_sessions'] as $ls) {
                        if ($ls['id_archive_opname'] === $gone) {
                            $row = $ls;
                        }
                    }
                    $t->true($row !== null, 'sesi folder terhapus terlihat superadmin');
                    $t->true($row !== null && array_key_exists('folder_name', $row['scope']) && $row['scope']['is_root'] === false, 'scope sesi folder terhapus: bentuk utuh, bukan root');
                    $set(q27_opts('smr'));
                    $r = q27_verif($t, $s);
                    $t->status($r, 200, 'All: sesi dengan folder dihapus fisik (user Semarang)');
                    $t->true(!in_array($gone, array_column($r[1]['result']['last_sessions'], 'id_archive_opname'), true), 'sesi folder terhapus tak terlihat user biasa bukan pembuat');
                    $set(['role' => 1]);

                    // --- sesi dari folder induk tak terlihat (F2 JOG saja) dengan subfolder terlihat (F2c semua lokasi)
                    $x['F2'] = q27_folder($t, 'F2', ['parent' => $x['P'], 'all' => 0, 'locs' => ['JOG']]);
                    $x['F2c'] = q27_folder($t, 'F2c', ['parent' => $x['F2']]);
                    $x['f2c1'] = q27_doc($t, 'f2c1', ['parent' => $x['F2c'], 'verified' => 1], 6);
                    $x['f2d1'] = q27_doc($t, 'f2d1', ['parent' => $x['F2']], 7);
                    $sf = q27_run($t, $s, $x['F2'], q27_sel([$x['F2c']]), [$N('f2c1'), $N('f2d1')]);
                    q27_set_owner($t, $sf, 'otheruser');
                    $set(q27_opts('smr'));
                    $r = q27_verif($t, $s);
                    $t->status($r, 200, 'All (smr)');
                    $t->true(in_array($sf, array_column($r[1]['result']['last_sessions'], 'id_archive_opname'), true), 'smr: sesi F2 (induk JOG) tampil karena mencakup F2c yang terlihat');
                    $r = q27_verif($t, $s, $x['F2c']);
                    $t->status($r, 200, 'smr: detail F2c');
                    $ids = array_column($r[1]['result']['last_sessions'], 'id_archive_opname');
                    $t->eq($ids, [$sf], 'smr: F2c: sesi F2 muncul');
                    $t->eq([$r[1]['result']['last_sessions'][0]['folder_total_documents'], $r[1]['result']['last_sessions'][0]['folder_verified']], [1, 1], 'smr: F2c: potongan hanya F2c (1 dokumen, 1 verified), bukan baris F2');
                    $rf2 = q27_verif($t, $s, $x['F2']);
                    $t->true($rf2[0] === 403, 'smr: detail F2 (JOG) 403: ' . $rf2[0]);
                });
            } finally {
                q27_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'X-5',
        'title' => 'keadaan user asli (is_all_location) dan user tanpa lokasi = oracle; pesan sukses dua bahasa; waktu respons data nyata (34,5 rb dokumen) tercatat',
        'run'   => function ($t) {
            $s = $t->session();
            q27_baseline($t);
            try {
                $x = q27_tree($t);
                $uname = q27_uname($t);
                $uid = q27_uid($t);
                $snap = q27_user_snapshot($t);
                $t->note('keadaan asli QA_USER: role ' . json_encode(array_column($snap['roles'], 'id_role')) . ', is_all_location=' . json_encode($snap['emp_all']));

                // keadaan asli: tidak diubah sama sekali
                $locsAll = array_keys(q27_locs($t));
                $uReal = ['username' => (string) $uname, 'uid' => $uid, 'bypass' => false,
                    'locs' => (int) $snap['emp_all'] === 1 ? $locsAll : array_map(function ($row) use ($t) {
                        return array_search($row['id_location'], q27_locs($t), true);
                    }, $snap['emp_locs'])];
                $o = q27_oracle($t, $uReal);
                $r = q27_list($t, $s);
                $t->status($r, 200, 'keadaan asli: list');
                $t->eq(q27_api_vt($r[1]['result']['document_verified_summary']), q27_vt($o['summary']), 'keadaan asli: ringkasan = oracle (lokasi: ' . implode(',', $uReal['locs']) . ')');
                $n = 0;
                foreach ($r[1]['result']['data'] as $row) {
                    if (($row['type'] ?? null) === 'Folder') {
                        $exp = isset($o['folders'][$row['id_archive']]) ? q27_vt($o['folders'][$row['id_archive']]) : null;
                        $t->eq(q27_api_vt($row['document_verified']), $exp, "keadaan asli: folder {$row['id_archive']} = oracle");
                        $n++;
                    }
                }
                $t->true($n > 0, "keadaan asli: $n folder dicek");
                $rv = q27_verif($t, $s);
                $t->eq([$rv[1]['result']['verified'], $rv[1]['result']['total']], q27_vt($o['summary']), 'keadaan asli: GET verifications = oracle');

                // user tanpa lokasi sama sekali: hanya baris is_all_location=1
                q27_with_user($t, ['emp' => []], function () use ($t, $s, $uname, $uid) {
                    $o = q27_oracle($t, ['username' => (string) $uname, 'uid' => $uid, 'bypass' => false, 'locs' => []]);
                    $r = q27_list($t, $s);
                    $t->status($r, 200, 'tanpa lokasi: list');
                    $t->eq(q27_api_vt($r[1]['result']['document_verified_summary']), q27_vt($o['summary']), 'tanpa lokasi: ringkasan = oracle');
                    foreach ($r[1]['result']['data'] as $row) {
                        if (($row['type'] ?? null) === 'Folder') {
                            $exp = isset($o['folders'][$row['id_archive']]) ? q27_vt($o['folders'][$row['id_archive']]) : null;
                            $t->eq(q27_api_vt($row['document_verified']), $exp, "tanpa lokasi: folder {$row['id_archive']} = oracle");
                        }
                    }
                    $rv = q27_verif($t, $s);
                    $t->status($rv, 200, 'tanpa lokasi: GET verifications');
                    $t->eq([$rv[1]['result']['verified'], $rv[1]['result']['total']], q27_vt($o['summary']), 'tanpa lokasi: header = oracle');
                    $t->eq($rv[1]['result']['last_sessions'], [], 'tanpa lokasi, tanpa sesi: []');
                });

                // pesan sukses dua bahasa
                q27_with_user($t, ['lang' => 'EN'], function ($set) use ($t, $s) {
                    $r = q27_verif($t, $s);
                    $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE200', 'EN: msg_code');
                    $t->eq($r[1]['message'] ?? null, 'Document is found', 'EN: message sesuai kontrak');
                    $set(['lang' => 'ID']);
                    $r = q27_verif($t, $s);
                    $t->eq($r[1]['message'] ?? null, 'Dokumen ditemukan', 'ID: message');
                });

                // waktu respons (data nyata): dicatat, batas longgar 5 detik
                $timeIt = function ($label, callable $fn) use ($t) {
                    $ms = [];
                    for ($i = 0; $i < 5; $i++) {
                        $a = microtime(true);
                        $r = $fn();
                        $ms[] = (int) round((microtime(true) - $a) * 1000);
                        $t->status($r, 200, "$label #$i");
                    }
                    sort($ms);
                    $t->true($ms[2] < 5000, "$label: median {$ms[2]} ms (< 5000)");

                    return $ms[2];
                };
                $m1 = $timeIt('GET archives root (default pagination)', function () use ($t, $s) {
                    return $t->call($s, 'GET', Q27_BASE . '/archives');
                });
                $m2 = $timeIt('GET verifications', function () use ($t, $s) {
                    return q27_verif($t, $s);
                });
                $m3 = $timeIt('GET verifications/{F}', function () use ($t, $s, $x) {
                    return q27_verif($t, $s, $x['F']);
                });
                $t->note("median ms: archives=$m1 verifications=$m2 verifications/{id}=$m3");
                q27_with_user($t, ['role' => 1], function () use ($t, $s) {
                    $a = microtime(true);
                    $r = $t->call($s, 'GET', Q27_BASE . '/archives');
                    $t->status($r, 200, 'superadmin list');
                    $t->note('superadmin GET archives ' . (int) round((microtime(true) - $a) * 1000) . ' ms');
                });
            } finally {
                q27_cleanup($t);
            }
        },
    ],
];
