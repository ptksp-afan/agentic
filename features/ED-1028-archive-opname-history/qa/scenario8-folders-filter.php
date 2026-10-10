<?php
/**
 * ED-1028 (REVISI 2026-10-10, subtask ED-1100 / ED-1102):
 *   AC-26  D-1 / BR-16: GET opnames/{S} bagi pemanggil bukan superadmin 1/2 - folders[] & warnings[] hanya folder yang boleh di-View
 *          pemanggil SAAT INI (nama & jumlah folder lain tidak terkirim); unverify_count = jumlah baris terlihat saja; peringatan 0 hilang;
 *          counts = kolom archive_opnames; result_counts / is_partial = AC-13; superadmin = semua baris; pembuat non-superadmin yang
 *          kehilangan View: folder itu ikut tersaring (tetap 200 sebagai pembuat);
 *   AC-24  (bagian BE) hak folder aturan baru ED-1025 FB-9 (induk tidak superior kecuali View) tidak mengubah sesi terlihat: hanya View
 *          (efektif, lewat induk) yang menentukan; Update/Delete/Store saja tidak membuat sesi terlihat.
 * Sesi dibuat lewat API opname ED-1026 (satu POST opnames); oracle = tabel mentah (q28_summary_oracle / q28_oracle_sessions).
 * Tahan data developer (q28_guard); user2 = pemegang Opname Document (grant sementara guard).
 */
require_once __DIR__ . '/qa_hist.php';

return [

    [
        'id'    => 'AC-26',
        'title' => '(rev, D-1) GET opnames/{S}: folders[] & warnings[] disaring ke folder yang boleh di-View pemanggil (B/B1 Izin Folder On tanpa hak user tidak ada di folders/warnings/scope); unverify_count = SUM baris terlihat; peringatan 0 hilang; counts = kolom archive_opnames; result_counts/is_partial = oracle; superadmin semua baris; pembuat non-superadmin yang kehilangan View ikut tersaring',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s) {
                $uid = q28_uid($t);
                $uMe = (string) q28_uname($t);
                // W (opname) -> A (terlihat) {A1 terlihat, A2 Izin Folder On tanpa hak}, C {C1 tersembunyi}, D {D1 tersembunyi}, B (Izin Folder On tanpa hak) {B1}
                $x = [];
                $x['W'] = q28_folder($t, 'W');
                $x['A'] = q28_folder($t, 'A', ['parent' => $x['W']]);
                $x['A1'] = q28_folder($t, 'A1', ['parent' => $x['A']]);
                $x['A2'] = q28_folder($t, 'A2', ['parent' => $x['A'], 'perm' => 1]);
                $x['B'] = q28_folder($t, 'B', ['parent' => $x['W'], 'perm' => 1]);
                $x['B1'] = q28_folder($t, 'B1', ['parent' => $x['B']]);
                $x['C'] = q28_folder($t, 'C', ['parent' => $x['W']]);
                $x['C1'] = q28_folder($t, 'C1', ['parent' => $x['C'], 'perm' => 1]);
                $x['D'] = q28_folder($t, 'D', ['parent' => $x['W']]);
                $x['D1'] = q28_folder($t, 'D1', ['parent' => $x['D'], 'perm' => 1]);
                // dokumen: terverifikasi sebelum sesi (tidak discan -> unverified_count) kecuali dA & dD
                $x['dW'] = q28_doc($t, 'dW', ['parent' => $x['W'], 'verified' => 1], 6);
                $x['dA'] = q28_doc($t, 'dA', ['parent' => $x['A']], 7);
                $x['dA1'] = q28_doc($t, 'dA1', ['parent' => $x['A1'], 'verified' => 1], 7);
                $x['dA2'] = q28_doc($t, 'dA2', ['parent' => $x['A2'], 'verified' => 1], 8);
                $x['dB'] = q28_doc($t, 'dB', ['parent' => $x['B'], 'verified' => 1], 6);
                $x['dB1'] = q28_doc($t, 'dB1', ['parent' => $x['B1'], 'verified' => 1], 7);
                $x['dC'] = q28_doc($t, 'dC', ['parent' => $x['C'], 'verified' => 1], 6);
                $x['dC1'] = q28_doc($t, 'dC1', ['parent' => $x['C1'], 'verified' => 1], 8);
                $x['dD'] = q28_doc($t, 'dD', ['parent' => $x['D']], 7);
                $x['dD1'] = q28_doc($t, 'dD1', ['parent' => $x['D1'], 'verified' => 1], 6);
                $N = function ($k) use ($t, &$x) {
                    return q28_n($t, $x[$k]);
                };
                $hidden = ['A2', 'B', 'B1', 'C1', 'D1'];

                // sesi S oleh superadmin sementara: scan dA (sah, A), dB (sah, folder tersembunyi), 1 kode tak dikenal (invalid)
                $S = null;
                q28_with_user($t, ['role' => 1], function () use ($t, $s, $x, $N, &$S) {
                    $S = q28_run($t, $s, $x['W'], q28_sel([$x['A'], $x['B'], $x['C'], $x['D']]), [$N('dA'), $N('dB'), 'QA28-TIDAK-ADA-' . uniqid()]);
                });
                // pembuat sesi = QA_USER (user3): ganti pemilik supaya S diperlakukan sebagai sesi orang lain
                q28_set_owner($t, $S, 'qa28fb26');
                $db = q28_opname($t, $S);
                $t->eq($db['status'], 2, 'S terkonfirmasi');
                $allRows = $t->db()->table('archive_opname_folders')->where('id_archive_opname', $S)->count();
                $t->eq($allRows, 10, 'snapshot S: 10 baris folder (W, A, B, C, D + A1, A2, B1, C1, D1)');
                $sumDb = function (array $keys) use ($t, $S, $x) {
                    return (int) $t->db()->table('archive_opname_folders')->where('id_archive_opname', $S)
                        ->whereIn('id_archive', array_map(function ($k) use ($x) {
                            return $x[$k];
                        }, $keys))->sum('unverified_count');
                };
                // angka tangan snapshot: unverified_count per baris
                $uv = [];
                foreach ($t->db()->table('archive_opname_folders')->where('id_archive_opname', $S)->get(['id_archive', 'unverified_count']) as $r) {
                    $uv[array_search($r->id_archive, $x, true)] = (int) $r->unverified_count;
                }
                $t->eq($uv, ['W' => 1, 'A' => 0, 'B' => 0, 'C' => 1, 'D' => 0, 'A1' => 1, 'A2' => 1, 'B1' => 1, 'C1' => 1, 'D1' => 1], 'unverified_count snapshot per baris (angka tangan)');

                $countsDb = function () use ($db) {
                    return [
                        'total_documents' => (int) $db['total_documents'], 'verified_before' => (int) $db['verified_before_count'], 'scanned' => (int) $db['scanned_count'],
                        'verified' => (int) $db['verified_count'], 'not_found' => (int) $db['not_found_count'], 'invalid' => (int) $db['invalid_count'],
                        'unscanned' => (int) $db['unscanned_count'], 'unverified' => (int) $db['unverified_count'],
                    ];
                };
                $fold = function (array $folders) {
                    return array_map(function ($f) {
                        return ['id_archive' => $f['id_archive'], 'name' => $f['name'], 'level' => $f['level'], 'is_continue' => $f['is_continue'], 'document_count' => $f['document_count']];
                    }, $folders);
                };
                $warn = function (array $warnings) {
                    return array_map(function ($w) {
                        return ['id_archive' => $w['id_archive'], 'name' => $w['name'], 'unverify_count' => $w['unverify_count']];
                    }, $warnings);
                };

                // --- pengamat bukan superadmin: user2 (SMR, + Opname Document) dan user3 (QA_USER role 3, semua lokasi)
                $s2 = q28_u2_session($t);
                foreach (['user2' => [$s2, q28_uu($t, 'user2')], 'user3' => [$s, q28_uu($t, 'user3')]] as $label => $pair) {
                    list($sess, $u) = $pair;
                    $r = $t->call($sess, 'GET', Q28_BASE . '/opnames/' . $S);
                    $t->status($r, 200, "$label: show S");
                    $res = $r[1]['result'];
                    $exp = q28_summary_oracle($t, $S, $u);
                    $t->eq($fold($res['folders']), $exp['folders'], "$label: folders[] = baris snapshot yang folder-nya terlihat (+ root) = oracle");
                    $t->eq(array_column($res['folders'], 'name'), [$N('W'), $N('A'), $N('C'), $N('D'), $N('A1')], "$label: folders[] = W, A, C, D, A1 (urut level, nama)");
                    $t->eq($warn($res['warnings']), $exp['warnings'], "$label: warnings[] = oracle");
                    $t->eq(array_column($res['warnings'], 'name'), [$N('W'), $N('A'), $N('C')], "$label: warnings = W, A, C saja (D: hasil 0 tidak dikirim; B tersembunyi)");
                    $t->eq(array_column($res['warnings'], 'unverify_count'), [1, 1, 1], "$label: unverify_count W=1, A=1 (A1 saja, A2 tersembunyi), C=1 (C1 tersembunyi)");
                    $t->eq($warn($res['warnings'])[1]['unverify_count'], $sumDb(['A', 'A1']), "$label: unverify_count A = SUM SQL baris terlihat (A, A1)");
                    $t->eq($warn($res['warnings'])[2]['unverify_count'], $sumDb(['C']), "$label: unverify_count C = SUM SQL baris terlihat (C)");
                    $t->true($sumDb(['D', 'D1']) === 1 && $sumDb(['D']) === 0, "$label: D semula bernilai 1 (D1), terlihat 0 -> peringatan D hilang");
                    // nama folder tersembunyi tidak ada di mana pun dalam respons
                    $body = json_encode($r[1], JSON_UNESCAPED_UNICODE);
                    foreach ($hidden as $k) {
                        $t->true(strpos($body, $N($k)) === false, "$label: nama folder tersembunyi $k tidak ada di respons (folders / warnings / scope)");
                        $t->true(strpos($body, (string) $x[$k]) === false, "$label: id folder tersembunyi $k tidak ada di respons");
                    }
                    $keys = array_keys($res['scope']);
                    sort($keys);
                    $t->eq($keys, ['folder_name', 'is_root', 'subfolder_count'], "$label: scope tepat tiga kunci");
                    $t->eq($res['scope'], ['is_root' => false, 'folder_name' => $N('W'), 'subfolder_count' => 4], "$label: scope tanpa nama subfolder (jumlah 4 tetap)");
                    // yang tidak berubah: counts = kolom archive_opnames; result_counts / is_partial dari baris dokumen yang terlihat (AC-13)
                    $t->eq($res['counts'], $countsDb(), "$label: counts = kolom archive_opnames (angka rekaman, tak disaring)");
                    $tl = $exp['tally'];
                    $rc = ['all' => $tl[1] + $tl[2] + $tl[3] + $tl[4], 'scanned' => $tl[1] + $tl[2] + $tl[3], 'verified' => $tl[1], 'not_found' => $tl[2], 'invalid' => $tl[3], 'unscanned' => $tl[4]];
                    $t->eq($res['result_counts'], $rc, "$label: result_counts = jumlah baris dokumen yang boleh dilihat (AC-13)");
                    $t->eq($res['is_partial'], $exp['hidden_docs'] > 0, "$label: is_partial = ada baris dokumen tersembunyi");
                    $t->true($exp['hidden_docs'] > 0 && $res['is_partial'] === true, "$label: kasus uji benar-benar parsial (" . $exp['hidden_docs'] . ' baris tersembunyi)');
                    $t->eq($res['result_counts']['verified'], 1, "$label: verified terlihat 1 (dA); dB di folder tersembunyi tidak dihitung");
                    $t->eq($res['result_counts']['invalid'], 1, "$label: Invalid selalu terlihat");
                    // ruas bacaan baris dokumen konsisten dengan result_counts (total paginator)
                    $d = $t->call($sess, 'GET', Q28_BASE . '/opnames/' . $S . '/documents?result=all&pagination=100');
                    $t->eq($d[1]['result']['total'], $rc['all'], "$label: documents total = result_counts.all");
                }

                // --- superadmin (role 1 dan 2): semua baris snapshot, semua peringatan; counts/result_counts = rekaman, is_partial false
                q28_with_user($t, ['role' => 1], function ($set) use ($t, $s, $S, $x, $N, $fold, $warn, $countsDb, $allRows, $sumDb) {
                    foreach ([1, 2] as $role) {
                        $set(['role' => $role]);
                        $r = $t->call($s, 'GET', Q28_BASE . '/opnames/' . $S);
                        $t->status($r, 200, "superadmin role $role: show S");
                        $res = $r[1]['result'];
                        $exp = q28_summary_oracle($t, $S, q28_uu($t, 'super'));
                        $t->eq(count($res['folders']), $allRows, "superadmin role $role: folders[] = semua baris archive_opname_folders S ($allRows)");
                        $t->eq($fold($res['folders']), $exp['folders'], "superadmin role $role: folders[] = oracle");
                        $t->eq($warn($res['warnings']), $exp['warnings'], "superadmin role $role: warnings[] = oracle");
                        $t->eq(array_column($res['warnings'], 'name'), [$N('W'), $N('A'), $N('B'), $N('C'), $N('D')], "superadmin role $role: warnings = W, A, B, C, D");
                        $t->eq(array_column($res['warnings'], 'unverify_count'), [1, $sumDb(['A', 'A1', 'A2']), $sumDb(['B', 'B1']), $sumDb(['C', 'C1']), $sumDb(['D', 'D1'])], "superadmin role $role: unverify_count = SUM subtree (A=2, B=1, C=2, D=1)");
                        $t->eq(array_column($res['warnings'], 'unverify_count'), [1, 2, 1, 2, 1], "superadmin role $role: unverify_count angka tangan");
                        $body = json_encode($r[1], JSON_UNESCAPED_UNICODE);
                        foreach (['A2', 'B', 'B1', 'C1', 'D1'] as $k) {
                            $t->true(strpos($body, $N($k)) !== false, "superadmin role $role: nama $k ada di respons");
                        }
                        $t->eq($res['counts'], $countsDb(), "superadmin role $role: counts = kolom");
                        $t->eq($res['is_partial'], false, "superadmin role $role: is_partial false");
                        $t->eq($res['result_counts']['all'], $t->db()->table('archive_opname_documents')->where('id_archive_opname', $S)->count(), "superadmin role $role: result_counts.all = semua baris snapshot");
                    }
                });

                // --- sesi dari ROOT (id_archive NULL): baris root selalu ikut (BR-16 a), subfolder tersembunyi (RH) tersaring, peringatan RV tetap
                $x['RV'] = q28_folder($t, 'RV');
                $x['RH'] = q28_folder($t, 'RH', ['perm' => 1]);
                q28_doc($t, 'dRV', ['parent' => $x['RV'], 'verified' => 1], 6);
                q28_doc($t, 'dRH', ['parent' => $x['RH'], 'verified' => 1], 7);
                $SR = null;
                q28_with_user($t, ['role' => 1], function () use ($t, $s, $x, &$SR) {
                    $SR = q28_run($t, $s, null, q28_sel([$x['RV'], $x['RH']]), []);
                });
                q28_set_owner($t, $SR, 'qa28fb26');
                $t->eq($t->db()->table('archive_opname_folders')->where('id_archive_opname', $SR)->count(), 3, 'snapshot SR: 3 baris (root, RV, RH)');
                foreach (['user2' => [$s2, q28_uu($t, 'user2')], 'user3' => [$s, q28_uu($t, 'user3')], 'superadmin' => [$s, q28_uu($t, 'super')]] as $label => $pair) {
                    list($sess, $u) = $pair;
                    $run = function () use ($t, $sess, $SR) {
                        return $t->call($sess, 'GET', Q28_BASE . '/opnames/' . $SR);
                    };
                    if ($label === 'superadmin') {
                        $r = null;
                        q28_with_user($t, ['role' => 1], function () use (&$r, $run) {
                            $r = $run();
                        });
                    } else {
                        $r = $run();
                    }
                    $t->status($r, 200, "$label: show SR (sesi root)");
                    $res = $r[1]['result'];
                    $exp = q28_summary_oracle($t, $SR, $u);
                    $t->eq($fold($res['folders']), $exp['folders'], "$label SR: folders[] = oracle");
                    $t->eq($warn($res['warnings']), $exp['warnings'], "$label SR: warnings[] = oracle");
                    $t->true(array_key_exists('id_archive', $res['folders'][0]) && $res['folders'][0]['id_archive'] === null && $res['folders'][0]['level'] === 0, "$label SR: baris root (id_archive null, level 0) selalu ikut");
                    $names = array_column($res['folders'], 'name');
                    $t->true(in_array($N('RV'), $names, true), "$label SR: RV ikut");
                    $t->eq(in_array($N('RH'), $names, true), $label === 'superadmin', "$label SR: RH (Izin Folder On tanpa hak) " . ($label === 'superadmin' ? 'ikut' : 'tersaring'));
                    $wn = array_column($res['warnings'], 'name');
                    $t->true(in_array($N('RV'), $wn, true), "$label SR: peringatan RV ada");
                    $t->eq(in_array($N('RH'), $wn, true), $label === 'superadmin', "$label SR: peringatan RH " . ($label === 'superadmin' ? 'ada' : 'tidak ada'));
                    $t->eq($res['scope']['is_root'], true, "$label SR: scope root");
                    $t->eq($res['scope']['subfolder_count'], 2, "$label SR: subfolder_count 2 (jumlah saja)");
                }

                // --- pembuat non-superadmin yang kehilangan View: sesi S2 oleh user3 sendiri (QA_USER role 3), folder Izin On dengan hak View
                $x['W2'] = q28_folder($t, 'W2', ['perm' => 1]);
                $x['X'] = q28_folder($t, 'X', ['parent' => $x['W2'], 'perm' => 1]);
                $x['Y2'] = q28_folder($t, 'Y2', ['parent' => $x['W2']]);
                q28_perm($t, $x['W2'], $uid, 1, 1, 1, 1);
                q28_perm($t, $x['X'], $uid, 1);
                q28_doc($t, 'dW2', ['parent' => $x['W2'], 'verified' => 1], 6);
                q28_doc($t, 'dX', ['parent' => $x['X'], 'verified' => 1], 7);
                q28_doc($t, 'dY2', ['parent' => $x['Y2'], 'verified' => 1], 8);
                $S2 = q28_run($t, $s, $x['W2'], q28_sel([$x['X'], $x['Y2']]), []);
                $t->eq(q28_opname($t, $S2)['created_by'], $uMe, 'S2 dibuat oleh user3 sendiri (pembuat, bukan superadmin)');
                $u3 = q28_uu($t, 'user3');
                $step = function ($label, array $expectNames) use ($t, $s, $S2, $u3, $x, $fold, $warn) {
                    $r = $t->call($s, 'GET', Q28_BASE . '/opnames/' . $S2);
                    $t->status($r, 200, "$label: pembuat tetap membaca S2 (200)");
                    $res = $r[1]['result'];
                    $exp = q28_summary_oracle($t, $S2, $u3);
                    $t->eq($fold($res['folders']), $exp['folders'], "$label: folders[] = oracle");
                    $t->eq($warn($res['warnings']), $exp['warnings'], "$label: warnings[] = oracle");
                    $t->eq(array_column($res['folders'], 'name'), array_map(function ($k) use ($t, $x) {
                        return q28_n($t, $x[$k]);
                    }, $expectNames), "$label: folders[] = " . (implode(', ', $expectNames) ?: '(kosong)'));
                    $t->eq(array_column($res['warnings'], 'name'), array_map(function ($k) use ($t, $x) {
                        return q28_n($t, $x[$k]);
                    }, $expectNames), "$label: warnings[] = folder yang sama (masing-masing unverified 1)");
                    $tl = $exp['tally'];
                    $t->eq($res['result_counts']['all'], $tl[1] + $tl[2] + $tl[3] + $tl[4], "$label: result_counts.all = oracle");
                    $t->eq($res['is_partial'], $exp['hidden_docs'] > 0, "$label: is_partial = oracle");

                    return $res;
                };
                $res = $step('pembuat dengan hak View penuh', ['W2', 'X', 'Y2']);
                $t->eq($res['is_partial'], false, 'pembuat dengan hak View penuh: is_partial false');
                $countsS2 = $res['counts'];
                // View di X dicabut -> X tersaring (nama X tidak ada), W2 & Y2 tetap
                q28_w($t, function ($c) use ($x, $uid) {
                    $c->table('archive_permissions')->where('id_archive', $x['X'])->where('id_user', $uid)->where('created_by', 'QA28')->delete();
                });
                $r = $t->call($s, 'GET', Q28_BASE . '/opnames/' . $S2);
                $res = $step('pembuat kehilangan View di X', ['W2', 'Y2']);
                $t->true(strpos(json_encode($r[1], JSON_UNESCAPED_UNICODE), q28_n($t, $x['X'])) === false, 'nama X tidak ada di respons pembuat');
                $t->eq($res['is_partial'], true, 'pembuat kehilangan View di X: is_partial true (dokumen dX tersembunyi)');
                $t->eq($res['counts'], $countsS2, 'counts tetap angka rekaman');
                // View di W2 juga dicabut -> W2 tersaring, X dan Y2 ikut tersembunyi (View induk superior): folders kosong, tetap 200 untuk pembuat
                q28_w($t, function ($c) use ($x, $uid) {
                    $c->table('archive_permissions')->where('id_archive', $x['W2'])->where('id_user', $uid)->where('created_by', 'QA28')->delete();
                });
                $res = $step('pembuat kehilangan View di W2', []);
                $t->eq($res['folders'], [], 'folders[] kosong');
                $t->eq($res['warnings'], [], 'warnings[] kosong');
                $t->eq($res['counts'], $countsS2, 'counts tetap angka rekaman');
                // superadmin tetap melihat semua baris S2
                q28_with_user($t, ['role' => 1], function () use ($t, $s, $S2) {
                    $r = $t->call($s, 'GET', Q28_BASE . '/opnames/' . $S2);
                    $t->eq(count($r[1]['result']['folders']), 3, 'superadmin: 3 baris snapshot S2');
                    $t->eq(count($r[1]['result']['warnings']), 3, 'superadmin: 3 peringatan S2');
                });
                $t->call($s2, 'GET', 'api/v5/auth/log-out');
            });
        },
    ],

    [
        'id'    => 'AC-24',
        'title' => '(rev, bagian BE) hak folder aturan baru FB-9: sesi terlihat tetap ditentukan View efektif (induk View superior; Update/Delete/Store saja tidak membuat terlihat), tidak berubah oleh aturan induk-tidak-superior hak lain; riwayat = oracle sebelum dan sesudah hak diubah',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s) {
                $uid = q28_uid($t);
                $x = [];
                $x['V'] = q28_folder($t, 'V', ['perm' => 1]);              // Izin On, user3 punya baris View
                $x['VC'] = q28_folder($t, 'VC', ['parent' => $x['V'], 'perm' => 1]); // anak Izin On tanpa baris -> tidak terlihat
                $x['U'] = q28_folder($t, 'U', ['perm' => 1]);              // Izin On, baris Update/Delete/Store TANPA View
                $x['UC'] = q28_folder($t, 'UC', ['parent' => $x['U']]);    // anak tanpa Izin: View induk superior -> tidak terlihat
                $x['O'] = q28_folder($t, 'O');                             // tanpa Izin Folder: terlihat
                q28_perm($t, $x['V'], $uid, 1, 0, 0, 0);
                q28_perm($t, $x['U'], $uid, 0, 1, 1, 1);
                foreach (['V', 'VC', 'U', 'UC', 'O'] as $k) {
                    $x['d' . $k] = q28_doc($t, 'd' . $k, ['parent' => $x[$k]], 6);
                }
                $ids = [];
                q28_with_user($t, ['role' => 1], function () use ($t, $s, $x, &$ids) {
                    // anak dulu supaya irisan tidak bentrok (confirmed_at semua = sekarang; tanpa pergeseran waktu)
                    foreach (['VC', 'UC', 'V', 'U', 'O'] as $k) {
                        $ids[$k] = q28_run($t, $s, $x[$k], [], [q28_n($t, $x['d' . $k])]);
                    }
                });
                foreach (['V', 'VC', 'U', 'UC'] as $k) {
                    q28_set_owner($t, $ids[$k], 'qa28fb9'); // bukan pembuat (pembuat selalu terlihat)
                }
                q28_set_owner($t, $ids['O'], 'qa28fb9');

                $check = function ($label, $session, array $u, array $expectKeys) use ($t, $ids) {
                    $r = q28_hist($t, $session, ['pagination' => 100]);
                    $t->status($r, 200, "$label: list");
                    $exp = q28_oracle_sessions($t, $u);
                    $got = q28_hkeys(q28_hids($r), $ids);
                    sort($got);
                    $want = $expectKeys;
                    sort($want);
                    $t->eq($got, $want, "$label: sesi QA terlihat = " . implode(',', $expectKeys));
                    $oracleKeys = q28_hkeys($exp, $ids);
                    sort($oracleKeys);
                    $t->eq($oracleKeys, $want, "$label: oracle (SQL mentah) sependapat: " . implode(',', $expectKeys));
                    $t->eq($r[1]['result']['total'], count($exp), "$label: total = oracle");
                    // baca langsung sesi: terlihat 200, selain itu 404 ARCHIVE412
                    foreach ($ids as $k => $id) {
                        $show = $t->call($session, 'GET', Q28_BASE . '/opnames/' . $id);
                        if (in_array($k, $expectKeys, true)) {
                            $t->status($show, 200, "$label: show $k");
                        } else {
                            q28_deny($t, $show, 404, 'ARCHIVE412', "$label: show $k (tidak terlihat)");
                        }
                    }
                };
                // user3: V terlihat (View), O terlihat; VC (anak Izin On tanpa baris), U (hanya Update/Delete/Store), UC (induk tanpa View) tidak
                $check('user3 awal', $s, q28_uu($t, 'user3'), ['V', 'O']);
                // user2: tanpa baris apa pun: hanya O
                $s2 = q28_u2_session($t);
                $check('user2 awal', $s2, q28_uu($t, 'user2'), ['O']);
                // beri U baris View (Update/Delete/Store dicabut): U dan UC (anak, induk kini View) terlihat
                q28_w($t, function ($c) use ($x, $uid) {
                    $c->table('archive_permissions')->where('id_archive', $x['U'])->where('id_user', $uid)->where('created_by', 'QA28')
                        ->update(['is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0]);
                });
                $check('user3 U diberi View (tanpa Update/Delete/Store)', $s, q28_uu($t, 'user3'), ['V', 'U', 'UC', 'O']);
                // cabut View dari V, beri Update/Delete/Store saja: V hilang dari riwayat user3 (aturan baru tidak menjadikan hak lain superior)
                q28_w($t, function ($c) use ($x, $uid) {
                    $c->table('archive_permissions')->where('id_archive', $x['V'])->where('id_user', $uid)->where('created_by', 'QA28')
                        ->update(['is_view' => 0, 'is_update' => 1, 'is_delete' => 1, 'is_store' => 1]);
                });
                $check('user3 V hanya Update/Delete/Store', $s, q28_uu($t, 'user3'), ['U', 'UC', 'O']);
                $t->call($s2, 'GET', 'api/v5/auth/log-out');
            });
        },
    ],
];
