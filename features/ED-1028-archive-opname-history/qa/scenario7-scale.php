<?php
/**
 * ED-1028 - skala: X-7 (satu sesi dengan ~35.000 baris snapshot dokumen: show / documents untuk user2, user3 dan superadmin; waktu respons
 * dan kebenaran angka), X-8 (30.000 folder terlihat: list history, search nama folder, konteks folder, filter idArchives untuk user bukan
 * superadmin = daftar IN panjang). Data sisipan berpenanda QA28BULK, dibuang di finally.
 */
require_once __DIR__ . '/qa_hist.php';

return [

    [
        'id'    => 'X-7',
        'title' => 'sesi besar (~35.000 baris snapshot dokumen nyata): show & documents untuk superadmin, user3, user2 benar (= oracle) dan responsif; sisipan dibuang bersama sesi',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s) {
                $x = null;
                $P = null;
                q28_with_user($t, ['role' => 1], function () use ($t, $s, &$x, &$P) {
                    $x = q28_tree($t);
                    $P = q28_run($t, $s, null, q28_sel([$x['OTH'], $x['JOGR'], $x['PRIV']]), [q28_n($t, $x['dO1']), q28_n($t, $x['dJR']), q28_n($t, $x['dPR']), q28_n($t, $x['dF1'])]);
                });
                $c = $t->db();
                $real = $c->table('archives')->where('type', 2)->where('name', 'not like', 'QA28-%')->limit(35000)->get(['id_archive', 'id_archive_parent'])->all();
                $t->true(count($real) > 1000, 'prasyarat: dokumen nyata cukup (' . count($real) . ')');
                $bulkIds = [];
                q28_w($t, function ($w) use ($P, $real) {
                    $now = date('Y-m-d H:i:s');
                    $rows = [];
                    foreach ($real as $i => $d) {
                        $rows[] = [
                            'id_archive_opname_document' => 'QA28BULKD' . str_pad((string) $i, 10, '0', STR_PAD_LEFT), 'id_archive_opname' => $P,
                            'id_archive' => $d->id_archive, 'id_archive_folder' => $d->id_archive_parent, 'code' => 'QA28BULKCODE' . $i,
                            'result' => 4, 'is_verified_after' => 0, 'scanned_at' => null, 'created_at' => $now,
                        ];
                    }
                    foreach (array_chunk($rows, 500) as $chunk) {
                        $w->table('archive_opname_documents')->insert($chunk);
                    }
                });
                $rowsTotal = $c->table('archive_opname_documents')->where('id_archive_opname', $P)->count();
                $t->true($rowsTotal > 30000 || $rowsTotal > count($real), 'sesi punya ' . $rowsTotal . ' baris snapshot');

                $oracle = function (array $u) use ($t, $P) {
                    $cc = $t->db();
                    $o = q28_oracle($t, $u);
                    $locIds = null;
                    if ($u['locs'] !== null) {
                        $map = q28_locs($t);
                        $locIds = array_map(function ($code) use ($map) {
                            return $map[$code];
                        }, $u['locs']);
                    }
                    $archiveLocs = [];
                    foreach ($cc->table('archive_locations')->get(['id_archive', 'id_location']) as $r) {
                        $archiveLocs[$r->id_archive][] = $r->id_location;
                    }
                    $cnt = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
                    $hidden = 0;
                    foreach ($cc->table('archive_opname_documents as d')->leftJoin('archives as a', 'a.id_archive', '=', 'd.id_archive')->where('d.id_archive_opname', $P)
                        ->get(['d.id_archive', 'd.id_archive_folder', 'd.result', 'a.is_all_location as doc_all']) as $r) {
                        $show = true;
                        if (!$u['bypass'] && $r->id_archive !== null) {
                            $locOk = $locIds === null || (int) $r->doc_all === 1 || count(array_intersect($archiveLocs[$r->id_archive] ?? [], $locIds)) > 0;
                            $folderOk = $r->id_archive_folder === null || isset($o['visible'][$r->id_archive_folder]);
                            $show = $locOk && $folderOk;
                        }
                        $show ? $cnt[(int) $r->result]++ : $hidden++;
                    }

                    return [$cnt, $hidden];
                };

                $views = ['user3' => [$s, q28_uu($t, 'user3')]];
                $s2 = q28_u2_session($t);
                $views['user2'] = [$s2, q28_uu($t, 'user2')];
                foreach ($views as $label => $pair) {
                    list($sess, $u) = $pair;
                    list($cnt, $hidden) = $oracle($u);
                    $t0 = microtime(true);
                    $show = $t->call($sess, 'GET', Q28_BASE . '/opnames/' . $P);
                    $ms = round((microtime(true) - $t0) * 1000);
                    q28_no500($t, $show, "$label: show sesi besar");
                    $t->status($show, 200, "$label: show sesi besar");
                    $t->note("$label show ($rowsTotal baris): $ms ms");
                    $t->true($ms < 8000, "$label: show < 8 detik ($ms ms)");
                    $rc = $show[1]['result']['result_counts'];
                    $t->eq([$rc['verified'], $rc['not_found'], $rc['invalid'], $rc['unscanned']], [$cnt[1], $cnt[2], $cnt[3], $cnt[4]], "$label: result_counts = oracle");
                    $t->eq($show[1]['result']['is_partial'], $hidden > 0, "$label: is_partial = (ada tersembunyi) " . ($hidden > 0 ? 'ya' : 'tidak'));
                    foreach (['all' => array_sum($cnt), 'unscanned' => $cnt[4], 'verified' => $cnt[1]] as $f => $expTotal) {
                        $t0 = microtime(true);
                        $d = $t->call($sess, 'GET', q28_url(Q28_BASE . '/opnames/' . $P . '/documents', ['result' => $f, 'pagination' => 50, 'page' => 3]));
                        $ms = round((microtime(true) - $t0) * 1000);
                        q28_no500($t, $d, "$label: documents $f");
                        $t->status($d, 200, "$label: documents $f");
                        $t->eq($d[1]['result']['total'], $expTotal, "$label: documents $f total = oracle");
                        $t->note("$label documents $f hal 3: $ms ms");
                        $t->true($ms < 8000, "$label: documents $f < 8 detik ($ms ms)");
                    }
                }
                q28_with_user($t, ['role' => 1], function () use ($t, $s, $P, $rowsTotal) {
                    $t0 = microtime(true);
                    $show = $t->call($s, 'GET', Q28_BASE . '/opnames/' . $P);
                    $ms = round((microtime(true) - $t0) * 1000);
                    $t->status($show, 200, 'superadmin: show sesi besar');
                    $rc = $show[1]['result']['result_counts'];
                    $t->eq($rc['all'], $rowsTotal, 'superadmin: result_counts.all = semua baris snapshot');
                    $t->eq($show[1]['result']['is_partial'], false, 'superadmin: is_partial false');
                    $t->note("superadmin show: $ms ms");
                });
                $t->call($s2, 'GET', 'api/v5/auth/log-out');
            });
        },
    ],

    [
        'id'    => 'X-8',
        'title' => '30.000 folder terlihat dan 40 sesi: list history, search nama folder, konteks folder dan filter idArchives untuk user bukan superadmin (daftar IN panjang) tanpa 500 dan benar; sisipan dibuang',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s) {
                $x = null;
                $ids = null;
                q28_with_user($t, ['role' => 1], function () use ($t, $s, &$x, &$ids) {
                    $x = q28_tree($t);
                    $ids = q28_hfix($t, $s, $x);
                });
                $n = 30000;
                q28_w($t, function ($c) use ($n, $x) {
                    $now = date('Y-m-d H:i:s');
                    $rows = [];
                    for ($i = 0; $i < $n; $i++) {
                        $rows[] = [
                            'id_archive' => 'QA28BULKFLD' . str_pad((string) $i, 10, '0', STR_PAD_LEFT),
                            'id_archive_parent' => $i % 5 === 0 ? $x['P'] : ('QA28BULKFLD' . str_pad((string) ($i - 1), 10, '0', STR_PAD_LEFT)),
                            'name' => 'QA28-bulkfld-' . $i, 'type' => 1, 'status' => 1, 'is_all_location' => 1, 'is_folder_permission' => 0,
                            'is_active' => 1, 'created_at' => $now, 'created_by' => 'QA28', 'updated_at' => $now, 'updated_by' => 'QA28',
                        ];
                    }
                    foreach (array_chunk($rows, 500) as $chunk) {
                        $c->table('archives')->insert($chunk);
                    }
                });
                try {
                    $s2 = q28_u2_session($t);
                    foreach (['user3' => $s, 'user2' => $s2] as $label => $sess) {
                        $u = q28_uu($t, $label);
                        $exp = q28_oracle_sessions($t, $u);
                        $time = function ($name, callable $call) use ($t, $label) {
                            $t0 = microtime(true);
                            $r = $call();
                            $ms = round((microtime(true) - $t0) * 1000);
                            q28_no500($t, $r, "$label $name");
                            $t->status($r, 200, "$label $name");
                            $t->note("$label $name: $ms ms");
                            $t->true($ms < 10000, "$label $name < 10 detik ($ms ms)");

                            return $r;
                        };
                        $r = $time('list', function () use ($t, $sess) {
                            return q28_hist($t, $sess, ['pagination' => 100]);
                        });
                        $t->eq($r[1]['result']['total'], count($exp), "$label list: total = oracle (" . count($exp) . ')');
                        $r = $time('search nama folder', function () use ($t, $sess) {
                            return q28_hist($t, $sess, ['pagination' => 100, 'search' => ['query' => 'QA28-']]);
                        });
                        $t->eq($r[1]['result']['total'], count($exp), "$label search nama folder 'QA28-': semua sesi terlihat");
                        $r = $time('konteks folder P', function () use ($t, $sess, $x) {
                            return q28_hist($t, $sess, ['pagination' => 100, 'id_archive' => $x['P']]);
                        });
                        $e = q28_oracle_sessions($t, $u, [$x['P']]);
                        $t->eq($r[1]['result']['total'], count($e), "$label konteks P: total = oracle (" . count($e) . ')');
                        $r = $time('idArchives 91 id', function () use ($t, $sess, $x) {
                            $ids500 = [$x['F']];
                            for ($i = 0; $i < 90; $i++) {
                                $ids500[] = 'QA28BULKFLD' . str_pad((string) $i, 10, '0', STR_PAD_LEFT);
                            }

                            return q28_hist($t, $sess, ['pagination' => 100, 'search' => ['idArchives' => $ids500]]);
                        });
                        // folder sisipan (URL GET dibatasi server web ~8 KB, maks 91 id) tak dicakup sesi mana pun dan bukan keturunan F -> sama dengan sesi yang mencakup F saja
                        $e = q28_oracle_sessions($t, $u, [$x['F']]);
                        $t->eq($r[1]['result']['total'], count($e), "$label idArchives 91 id: total = oracle sesi yang mencakup F (" . count($e) . ')');
                        $r = $time('select opname-users', function () use ($t, $sess) {
                            return $t->call($sess, 'GET', 'api/v5/select/document-archive/archive/opname-users');
                        });
                    }
                    $t->call($s2, 'GET', 'api/v5/auth/log-out');
                } finally {
                    q28_w($t, function ($c) {
                        $c->table('archives')->where('id_archive', 'like', 'QA28BULKFLD%')->delete();
                    });
                    $t->eq($t->db()->table('archives')->where('id_archive', 'like', 'QA28BULKFLD%')->count(), 0, 'folder sisipan dibuang');
                }
            });
        },
    ],
];
