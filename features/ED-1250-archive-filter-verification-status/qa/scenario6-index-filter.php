<?php
/**
 * ED-1250 - ronde 2 (review 2026-10-26, R-2): GET setting/display-columns (index) disaring untuk pemanggil tanpa Opname Document.
 *   AC-25  baris documentArchive di index: tanpa Opname Document TANPA verificationStatus (current_queries / available_queries) dan TANPA
 *          documentVerified (current_columns / available_columns); pemegang punya keduanya; urutan item lain dan field lain baris itu tetap;
 *          semua modul lain identik antara pemegang dan non-pemegang (selain baris seluruh-modul documentArchiveOpnameHistory /
 *          documentArchiveOpnameResult yang sudah disembunyikan, AC-10); total / paginasi / urutan wajar; search di index tetap jalan;
 *          baris di DB tidak ditulis; sama dengan peta GET documentArchive.
 *   X-3    varian nama baris (huruf besar + spasi akhir, kolasi latin1_general_ci) tetap disaring di index (sameModule), baris dipulihkan persis.
 * Pemanggil: preset QA50-ROLE-{OD,LIST,NOLIST} (lokasi Semarang), role sistem 1 (superadmin), QA_USER2 sungguhan (preset LIST).
 * Tidak ada data archive dibuat; satu-satunya tulis = (X-3) nama baris cds documentArchive sementara, berjurnal, dipulihkan persis.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-25',
        'title' => 'R-2: index display-columns non-pemegang Opname Document: baris documentArchive tanpa verificationStatus (queries) dan documentVerified (columns) di current_*/available_*; pemegang (preset OD, superadmin) punya keduanya; modul lain identik; total/paginasi/urutan/search wajar; DB tidak ditulis',
        'run'   => function ($t) {
            $s = $t->session();
            q50_baseline($t);
            $stored = q50_cds_row($t);
            $t->eq(count($stored), 1, 'prasyarat: 1 baris documentArchive');
            $st = [
                'current_queries'   => q50_seq(json_decode($stored[0]['current_queries'], true)),
                'available_queries' => q50_seq(json_decode($stored[0]['available_queries'], true)),
                'current_columns'   => q50_seq(json_decode($stored[0]['current_columns'], true)),
                'available_columns' => q50_seq(json_decode($stored[0]['available_columns'], true)),
            ];
            $t->eq($st['current_queries'], Q50_R1_CURRENT, 'prasyarat: current_queries tersimpan = urutan R-1');
            $t->eq($st['available_queries'], Q50_R1_AVAILABLE, 'prasyarat: available_queries tersimpan = urutan R-1');
            $t->true(in_array('documentVerified', $st['current_columns'], true) && in_array('documentVerified', $st['available_columns'], true), 'prasyarat: documentVerified di current & available columns tersimpan');
            $crcStored = q50_sum($t, 'column_display_settings', q50_cols($t, 'column_display_settings'));
            $dbTotal = $t->db()->table('column_display_settings')->count();
            $dbMods = $t->db()->table('column_display_settings')->orderBy('updated_at', 'desc')->pluck('module_name')->all();
            $dbUpd = $t->db()->table('column_display_settings')->orderBy('updated_at', 'desc')->pluck('updated_at')->all();
            $ties = count($dbUpd) - count(array_unique($dbUpd));
            $t->note("updated_at kembar di tabel cds: $ties baris (urutan antar-kembar tidak dijamin, pra-ada)");
            $whole = ['documentArchiveOpnameHistory', 'documentArchiveOpnameResult'];
            $wholeInDb = count(array_intersect($whole, $dbMods));
            $t->note("tabel cds: $dbTotal baris; modul seluruh-modul di DB: $wholeInDb");
            $lists = ['current_queries', 'available_queries', 'current_columns', 'available_columns'];
            $strip = function (array $seq, $drop) {
                return array_values(array_diff($seq, $drop));
            };
            $without = [
                'current_queries'   => $strip($st['current_queries'], ['verificationStatus']),
                'available_queries' => $strip($st['available_queries'], ['verificationStatus']),
                'current_columns'   => $strip($st['current_columns'], ['documentVerified']),
                'available_columns' => $strip($st['available_columns'], ['documentVerified']),
            ];
            $key = function ($row) {
                return $row['module_name'];
            };

            try {
                // ------------------------------------------------------------ pemegang (preset OD, lokasi Semarang) = pembanding
                list($hRows, $hTotal, $hLast, $hPages) = q50_with_user($t, q50_opts('smr', 'OD'), function () use ($t, $s) {
                    return q50_index_all($t, $s, 1000);
                });
                $t->eq($hTotal, $dbTotal, 'pemegang: total index = semua baris tabel');
                $t->eq(count($hRows), $hTotal, 'pemegang: jumlah baris (pagination 1000) = total');
                $t->eq($hLast, 1, 'pemegang: 1 halaman (pagination 1000)');
                $hBy = [];
                foreach ($hRows as $r) {
                    $hBy[$r['module_name']] = $r;
                }
                $t->eq(count($hBy), count($hRows), 'pemegang: tanpa nama modul ganda');
                // urutan = updated_at desc; baris dengan updated_at sama tidak punya tie-breaker (pra-ada, ED-1028), jadi dibandingkan lewat urutan updated_at + himpunan nama
                $t->eq(array_column($hRows, 'updated_at'), $dbUpd, 'pemegang: urutan updated_at baris = updated_at desc (sama dengan DB)');
                $mset = array_column($hRows, 'module_name');
                sort($mset);
                $dbSet = $dbMods;
                sort($dbSet);
                $t->eq($mset, $dbSet, 'pemegang: himpunan modul = semua baris tabel');
                $hRow = $hBy['documentArchive'] ?? null;
                $t->true($hRow !== null, 'pemegang: baris documentArchive ada');
                foreach ($lists as $l) {
                    $t->eq(q50_row_seq($hRow, $l), $st[$l], "pemegang: documentArchive.$l = tersimpan (lengkap, tidak disaring)");
                }
                $t->true(isset($hBy['documentArchiveOpnameHistory']) && ($wholeInDb < 2 || isset($hBy['documentArchiveOpnameResult'])), 'pemegang: baris seluruh-modul tampil');

                // ------------------------------------------------------------ pemegang superadmin (role 1)
                list($sRows, $sTotal) = q50_with_user($t, q50_opts('bypass'), function () use ($t, $s) {
                    return q50_index_all($t, $s, 1000);
                });
                $sBy = [];
                foreach ($sRows as $r) {
                    $sBy[$r['module_name']] = $r;
                }
                $t->eq($sTotal, $dbTotal, 'superadmin: total = semua baris');
                foreach ($lists as $l) {
                    $t->eq(q50_row_seq($sBy['documentArchive'], $l), $st[$l], "superadmin: documentArchive.$l lengkap");
                }
                $t->eq(json_encode($sRows), json_encode($hRows), 'superadmin: seluruh respons index identik dengan pemegang preset OD');

                // ------------------------------------------------------------ non-pemegang: preset LIST, NOLIST (lokasi Semarang) dan QA_USER2 sungguhan
                $nonHolders = [];
                foreach (['LIST', 'NOLIST'] as $preset) {
                    $nonHolders["preset $preset"] = q50_with_user($t, q50_opts('smr', $preset), function () use ($t, $s) {
                        return q50_index_all($t, $s, 1000);
                    });
                }
                $nonHolders['QA_USER2 (preset LIST)'] = q50_with_u2_preset($t, 'LIST', function () use ($t) {
                    return q50_index_all($t, q50_u2_session($t), 1000);
                });

                foreach ($nonHolders as $who => $res) {
                    list($rows, $total, $last) = $res;
                    $by = [];
                    foreach ($rows as $r) {
                        $by[$r['module_name']] = $r;
                    }
                    $t->eq($total, $dbTotal - $wholeInDb, "[$who] total = semua baris - modul seluruh-modul ($dbTotal - $wholeInDb): saringan item tidak membuang baris");
                    $t->eq(count($rows), $total, "[$who] jumlah baris = total");
                    $t->eq(count($by), count($rows), "[$who] tanpa nama modul ganda");
                    $r = $by['documentArchive'] ?? null;
                    $t->true($r !== null, "[$who] baris documentArchive tetap ada");
                    foreach ($lists as $l) {
                        $t->eq(q50_row_seq($r, $l), $without[$l], "[$who] documentArchive.$l = tersimpan tanpa entri tersaring, urutan item lain sama");
                    }
                    $js = json_encode($r);
                    $t->true(strpos($js, 'verificationStatus') === false && strpos($js, 'Verification Status') === false && strpos($js, 'Status Verifikasi') === false && strpos($js, 'documentVerified') === false && strpos($js, 'Dokumen Terverifikasi') === false, "[$who] baris documentArchive tanpa jejak verificationStatus / documentVerified (nama maupun judul)");
                    // field lain baris documentArchive identik dengan pemegang
                    foreach ($hRow as $f => $v) {
                        if (in_array($f, $lists, true)) {
                            continue;
                        }
                        $t->eq(json_encode(array_key_exists($f, $r) ? $r[$f] : '__tidak ada__'), json_encode($v), "[$who] documentArchive.$f identik dengan pemegang");
                    }
                    $t->eq(array_keys($r), array_keys($hRow), "[$who] documentArchive: daftar field sama dengan pemegang");
                    // item lain (bukan yang tersaring) objeknya identik per item
                    foreach (['current_queries', 'available_queries', 'current_columns', 'available_columns'] as $l) {
                        $hl = is_string($hRow[$l]) ? json_decode($hRow[$l], true) : $hRow[$l];
                        $nl = is_string($r[$l]) ? json_decode($r[$l], true) : $r[$l];
                        $drop = strpos($l, 'queries') !== false ? 'verificationStatus' : 'documentVerified';
                        $exp = array_values(array_filter((array) $hl, function ($x) use ($drop) {
                            return (is_array($x['data_index']) ? implode('.', $x['data_index']) : $x['data_index']) !== $drop;
                        }));
                        $t->eq(json_encode($nl), json_encode($exp), "[$who] documentArchive.$l: tiap item lain identik objek-per-objek dengan pemegang");
                    }
                    // modul lain identik
                    $diff = [];
                    foreach ($hBy as $name => $hr) {
                        if ($name === 'documentArchive' || in_array($name, $whole, true)) {
                            continue;
                        }
                        if (!isset($by[$name]) || json_encode($by[$name]) !== json_encode($hr)) {
                            $diff[] = $name;
                        }
                    }
                    $t->true($diff === [], "[$who] semua modul lain identik dengan pemegang (" . count($hBy) . ' modul; beda: ' . implode(',', array_slice($diff, 0, 5)) . ')');
                    $miss = array_values(array_diff(array_keys($hBy), array_keys($by)));
                    sort($miss);
                    $expMiss = array_values(array_intersect($whole, array_keys($hBy)));
                    sort($expMiss);
                    $t->eq($miss, $expMiss, "[$who] satu-satunya modul yang hilang = baris seluruh-modul (history, result)");
                    $extra = array_values(array_diff(array_keys($by), array_keys($hBy)));
                    $t->eq($extra, [], "[$who] tidak ada modul tambahan");
                    // urutan = urutan pemegang tanpa modul seluruh-modul (dibandingkan lewat updated_at; antar-kembar tidak dijamin)
                    $expUpd = [];
                    foreach ($hRows as $hr) {
                        if (!in_array($hr['module_name'], $whole, true)) {
                            $expUpd[] = $hr['updated_at'];
                        }
                    }
                    $t->eq(array_column($rows, 'updated_at'), $expUpd, "[$who] urutan updated_at baris = urutan pemegang tanpa baris seluruh-modul");
                    $t->eq(array_column($rows, 'module_name')[0] ?? null, 'documentArchive', "[$who] baris pertama = documentArchive (updated_at terbaru)");
                    // sama dengan peta GET documentArchive (show)
                }

                // GET documentArchive (show) non-pemegang = peta yang sama dengan baris index
                list($lRows) = $nonHolders['preset LIST'];
                $lBy = [];
                foreach ($lRows as $r) {
                    $lBy[$r['module_name']] = $r;
                }
                q50_with_user($t, q50_opts('smr', 'LIST'), function () use ($t, $s, $lBy, $lists) {
                    $g = $t->call($s, 'GET', Q50_DS . 'documentArchive');
                    $t->status($g, 200, 'tanpa OD: GET documentArchive (show)');
                    foreach ($lists as $l) {
                        $t->eq(q50_seq($g[1]['result'][$l]), q50_row_seq($lBy['documentArchive'], $l), "peta sama: show.$l = index documentArchive.$l (non-pemegang)");
                    }
                });

                // ------------------------------------------------------------ paginasi non-pemegang (default 10, 7, 1) + halaman melewati batas
                $expectNon = array_column($nonHolders['preset LIST'][0], 'module_name');
                foreach ([null, 7, 1, 150] as $pp) {
                    $label = $pp === null ? 'default' : $pp;
                    $res = q50_with_user($t, q50_opts('smr', 'LIST'), function () use ($t, $s, $pp) {
                        $rows = [];
                        $page = 1;
                        $meta = null;
                        do {
                            $q = ['page' => $page] + ($pp === null ? [] : ['pagination' => $pp]);
                            $r = $t->call($s, 'GET', rtrim(Q50_DS, '/') . '?' . http_build_query($q));
                            $t->status($r, 200, "non-pemegang index pagination " . ($pp === null ? 'default' : $pp) . " hal. $page");
                            $meta = $r[1]['result'];
                            foreach ($meta['data'] as $row) {
                                $rows[] = $row;
                            }
                            $page++;
                        } while ($page <= (int) $meta['last_page'] && $page < 400);
                        $over = $t->call($s, 'GET', rtrim(Q50_DS, '/') . '?' . http_build_query(['page' => (int) $meta['last_page'] + 1] + ($pp === null ? [] : ['pagination' => $pp])));

                        return [$rows, $meta, $over];
                    });
                    list($rows, $meta, $over) = $res;
                    $per = $pp === null ? 10 : $pp;
                    $t->eq((int) $meta['per_page'], $per, "[pagination $label] per_page");
                    $t->eq((int) $meta['total'], $dbTotal - $wholeInDb, "[pagination $label] total konsisten");
                    $t->eq((int) $meta['last_page'], (int) ceil(($dbTotal - $wholeInDb) / $per), "[pagination $label] last_page = ceil(total / per_page)");
                    $names = array_column($rows, 'module_name');
                    $dups = array_keys(array_filter(array_count_values($names), function ($n) {
                        return $n > 1;
                    }));
                    if ($dups) {
                        // urutan updated_at desc tanpa tie-breaker (pra-ada, ED-1028): catat bila ada updated_at sama di batas halaman
                        $t->note("[pagination $label] modul ganda antar halaman: " . implode(',', array_slice($dups, 0, 5)) . ' (cek tie updated_at, pra-ada)');
                    }
                    $t->eq(count($names), $dbTotal - $wholeInDb, "[pagination $label] jumlah baris gabungan halaman = total");
                    $t->eq($dups, [], "[pagination $label] gabungan halaman tanpa duplikat");
                    $t->eq(array_values(array_diff($expectNon, $names)), [], "[pagination $label] tidak ada modul yang hilang dibanding pemegang-tanpa-seluruh-modul");
                    $first = $rows[0]['module_name'] ?? null;
                    $t->eq($first, 'documentArchive', "[pagination $label] halaman 1 baris pertama = documentArchive (updated_at terbaru)");
                    $fr = q50_resp_row(['result' => ['data' => $rows]]);
                    foreach ($lists as $l) {
                        $t->eq(q50_row_seq($fr, $l), $without[$l], "[pagination $label] documentArchive.$l tersaring juga di halaman ini");
                    }
                    $t->status($over, 200, "[pagination $label] halaman di luar batas = 200");
                    $t->eq(count($over[1]['result']['data']), 0, "[pagination $label] halaman di luar batas = data kosong");
                    $t->eq((int) $over[1]['result']['total'], $dbTotal - $wholeInDb, "[pagination $label] halaman di luar batas: total tetap");
                }

                // ------------------------------------------------------------ search di index (LIKE module_name) tetap jalan, tersaring konsisten
                $nonSearch = function ($q, $who = 'LIST') use ($t, $s) {
                    return q50_with_user($t, q50_opts('smr', $who), function () use ($t, $s, $q) {
                        return q50_index_all($t, $s, 1000, ['search' => $q]);
                    });
                };
                $sorted = function (array $a) {
                    sort($a);

                    return $a;
                };
                $desc = function (array $upd) {
                    $s2 = $upd;
                    rsort($s2);

                    return $upd === $s2;
                };
                foreach (['documentArchive', 'documentarchive', 'Archive', 'customer', 'Opname', 'documentArchiveOpnameHistory', 'zzz-tidak-ada-modul'] as $q) {
                    $dbHit = $t->db()->table('column_display_settings')->where('module_name', 'like', '%' . $q . '%')->orderBy('updated_at', 'desc')->pluck('module_name')->all();
                    $dbHitNon = array_values(array_diff($dbHit, $whole));
                    list($nRows, $nTotal) = $nonSearch($q);
                    list($oRows, $oTotal) = $nonSearch($q, 'OD');
                    $t->eq($oTotal, count($dbHit), "search='$q': pemegang total = DB (" . count($dbHit) . ')');
                    $t->eq($sorted(array_column($oRows, 'module_name')), $sorted($dbHit), "search='$q': pemegang himpunan baris = DB LIKE");
                    $t->true($desc(array_column($oRows, 'updated_at')), "search='$q': pemegang urutan updated_at desc");
                    $t->eq($nTotal, count($dbHitNon), "search='$q': non-pemegang total = DB tanpa baris seluruh-modul (" . count($dbHitNon) . ')');
                    $t->eq($sorted(array_column($nRows, 'module_name')), $sorted($dbHitNon), "search='$q': non-pemegang himpunan baris = DB LIKE tanpa seluruh-modul");
                    $t->true($desc(array_column($nRows, 'updated_at')), "search='$q': non-pemegang urutan updated_at desc");
                    foreach ($nRows as $row) {
                        $hr = $hBy[$row['module_name']];
                        if ($row['module_name'] === 'documentArchive') {
                            foreach ($lists as $l) {
                                $t->eq(q50_row_seq($row, $l), $without[$l], "search='$q': documentArchive.$l tersaring juga di hasil search");
                            }
                        } else {
                            $t->eq(json_encode($row), json_encode($hr), "search='$q': {$row['module_name']} identik dengan pemegang");
                        }
                    }
                }
                list($nRows, $nTotal) = $nonSearch('documentArchiveOpnameHistory');
                $t->eq($nTotal, 0, 'search nama modul seluruh-modul: non-pemegang 0 baris (200, bukan 403)');

                // ------------------------------------------------------------ DB tidak ditulis oleh index
                $t->eq(q50_sum($t, 'column_display_settings', q50_cols($t, 'column_display_settings')), $crcStored, 'tabel column_display_settings tidak berubah oleh semua GET index (saringan hanya di respons)');
                $row = q50_cds_row($t);
                foreach ($lists as $l) {
                    $t->eq(q50_seq(json_decode($row[0][$l], true)), $st[$l], "DB documentArchive.$l tetap lengkap (verificationStatus / documentVerified masih tersimpan)");
                }
                $t->eq(json_encode($row), json_encode($stored), 'baris documentArchive di DB identik sebelum / sesudah (updated_at tidak maju)');
            } finally {
                q50_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'X-3',
        'title' => 'index display-columns: baris dengan nama varian (DOCUMENTARCHIVE + spasi akhir, kolasi latin1_general_ci) tetap disaring untuk non-pemegang (sameModule) dan lengkap untuk pemegang; baris dipulihkan persis',
        'run'   => function ($t) {
            $s = $t->session();
            q50_baseline($t);
            $snap = q50_cds_snapshot($t);
            $t->eq(count($snap), 1, 'prasyarat: 1 baris documentArchive');
            $fp = q50_sum($t, 'column_display_settings', q50_cols($t, 'column_display_settings'));
            $find = function (array $rows) {
                foreach ($rows as $r) {
                    if (strcasecmp(rtrim($r['module_name'], ' '), 'documentArchive') === 0) {
                        return $r;
                    }
                }

                return null;
            };
            try {
                foreach (['DOCUMENTARCHIVE ', 'documentarchive', 'DocumentArchive  '] as $variant) {
                    $idRow = $snap[0]['id_column_display_setting'];
                    q50_w($t, function ($c) use ($variant, $idRow) {
                        $c->table('column_display_settings')->where('id_column_display_setting', $idRow)->update(['module_name' => $variant]);
                    });
                    $t->eq($t->db()->table('column_display_settings')->where('module_name', 'documentArchive')->count(), 1, "nama baris varian '$variant': masih terbaca 1 baris lewat kolasi ci (prasyarat uji)");
                    $name = (string) $t->db()->table('column_display_settings')->where('id_column_display_setting', $snap[0]['id_column_display_setting'])->value('module_name');
                    $t->eq($name, $variant, "nama tersimpan = '$variant'");
                    $non = q50_with_user($t, q50_opts('smr', 'LIST'), function () use ($t, $s) {
                        return q50_index_all($t, $s, 1000);
                    });
                    $hold = q50_with_user($t, q50_opts('smr', 'OD'), function () use ($t, $s) {
                        return q50_index_all($t, $s, 1000);
                    });
                    $nr = $find($non[0]);
                    $hr = $find($hold[0]);
                    $t->true($nr !== null && $hr !== null, "[varian '$variant'] baris ada di index non-pemegang dan pemegang");
                    $t->eq(q50_row_seq($hr, 'current_queries'), Q50_R1_CURRENT, "[varian '$variant'] pemegang: current_queries lengkap");
                    $t->true(in_array('documentVerified', q50_row_seq($hr, 'current_columns'), true), "[varian '$variant'] pemegang: documentVerified ada");
                    $js = json_encode($nr);
                    $t->true(strpos($js, 'verificationStatus') === false && strpos($js, 'documentVerified') === false, "[varian '$variant'] non-pemegang: baris tanpa verificationStatus / documentVerified (disaring walau nama berbeda huruf / spasi)");
                    $t->eq(q50_row_seq($nr, 'current_queries'), ['name', 'type', 'updatedAt', 'showRelatedTransaction'], "[varian '$variant'] non-pemegang: current_queries tanpa entri");
                    $t->eq($non[1], $hold[1] - 2, "[varian '$variant'] total non-pemegang = pemegang - 2 (modul seluruh-modul)");
                }
            } finally {
                q50_cds_restore($t, $snap);
                q50_cleanup($t);
            }
            $t->eq(q50_sum($t, 'column_display_settings', q50_cols($t, 'column_display_settings')), $fp, 'seluruh tabel column_display_settings sama persis dengan sebelum AC');
            $t->eq(json_encode(q50_cds_row($t)), json_encode($snap), 'baris documentArchive dipulihkan persis (nama, id, isi, updated_at)');
        },
    ],
];
