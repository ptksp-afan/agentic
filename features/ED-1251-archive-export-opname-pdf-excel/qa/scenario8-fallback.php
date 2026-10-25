<?php
/**
 * ED-1251 D-1 (QA r1; perbaikan BE r2) - fallback kolom (contract §5 "Fallback kolom"): bila tabel template tersimpan / payload pratinjau /
 * display setting tidak menyisakan satu pun kolom current_columns ber-data_index di KEDUA tabel, dipakai display setting, lalu config
 * display setting; hasil selalu punya minimal satu kolom (Excel tidak lagi berputar tak berujung di ReportHeader1 kolom akhir "@").
 *
 *   D-1   Simpan Customize (setting/pdf-templates/update) dengan berbagai keadaan kolom: satu tabel kosong, kedua kosong, tables [],
 *         kolom tanpa data_index / data_index aneh, tabel tak dikenal. GET xls, GET pdf, POST pdf/preview {} dan transaction-templates.
 *   D-1b  Display setting (template belum disimpan / tersimpan kosong): summaries_columns/current_columns kosong, tanpa data_index, NULL di DB,
 *         JSON skalar, baris cds dihapus, rantai template -> display setting -> config.
 *   D-1c  Payload POST pdf/preview: tables kosong / tanpa kolom / tanpa kunci / tak sah (422) / kolom payload dipakai / satu tabel kosong.
 *   D-1d  Template tersimpan BUATAN TANGAN rusak (tables bukan array, tanpa current_columns, ...): Excel wajib 200 + fallback; PDF/pratinjau/
 *         select yang 500 berasal dari PdfTemplateService::getTemplate/generatePreview (kode bersama, Simpan tak memvalidasi) dicatat.
 *
 * KEAMANAN: tiap keadaan berisiko lebih dulu dijalankan lewat proses CLI terbatas waktu (xls_worker.php: xls, pdf, pratinjau) SEBELUM
 * request HTTP mana pun yang merender; keadaan yang berputar tidak pernah dikirim ke php-fpm (pm.max_children 5).
 */
require_once __DIR__ . '/qa_check.php';

if (!defined('Q251_SUMT')) {
    define('Q251_SUMT', ['totalDocuments' => 'Total Documents', 'verified' => 'Verified', 'notFound' => 'Not Found', 'invalid' => 'Invalid', 'unscanned' => 'Not Scanned']);
    define('Q251_DETT', ['code' => 'Document No.', 'transactionTypeLabel' => 'Transaction Type', 'folderName' => 'Folder', 'scanTime' => 'Time', 'resultLabel' => 'Result']);
}

if (!function_exists('q251_fb_check')) {

    /** data_index pertama tiap kolom current_columns sebuah tabel respons (list atau string). */
    function q251_fb_keys($tbl)
    {
        $out = [];
        foreach ((array) ($tbl['current_columns'] ?? []) as $c) {
            $di = $c['data_index'] ?? null;
            $out[] = is_array($di) ? $di[0] : $di;
        }

        return $out;
    }

    /** Bandingkan isi satu berkas (pdf|xls) dengan kolom yang diharapkan (kunci data_index, urutan) dan baris/angka sesi. */
    function q251_fb_verify($t, $kind, $body, array $expS, array $expD, $label, array $counts, array $rowsAll)
    {
        $nS = count($expS);
        $nD = count($expD);
        $titS = array_map(function ($k) {
            return Q251_SUMT[$k];
        }, $expS);
        $titD = array_map(function ($k) {
            return Q251_DETT[$k];
        }, $expD);
        $order = array_keys(Q251_SUMT);
        $idx = ['code' => 0, 'transactionTypeLabel' => 1, 'folderName' => 2, 'scanTime' => 3, 'resultLabel' => 4];
        $rows = array_map(function ($r) use ($expD, $idx) {
            return array_map(function ($k) use ($r, $idx) {
                return $r[$idx[$k]];
            }, $expD);
        }, $rowsAll);
        $vals = array_map(function ($k) use ($order, $counts) {
            return $counts[array_search($k, $order, true)];
        }, $expS);

        if ($kind === 'xls') {
            $xp = q251_xl_parts(q251_xlsx($body), 'Summaries', 'Detail');
            $sumcols = $xp['sumcols'];
            $detcols = $xp['detcols'];
            $sumvals = $xp['sumvals'];
            $detRows = $xp['rows'];
            $expVals = array_map('floatval', $vals);
            $expRows = q251_xl_rows($rows);
            $nm = 'XLSX';
        } else {
            $texts = q251_norm_all(q251_pdf($body)['texts'][0] ?? []);
            $i = array_search('Summaries', $texts, true);
            $j = array_search('Detail', $texts, true);
            $sumcols = ($nS && $i !== false) ? array_slice($texts, $i + 1, $nS) : ($i !== false ? array_slice($texts, $i + 1, 1) : []);
            $sumvals = ($nS && $i !== false) ? array_slice($texts, $i + 1 + $nS, $nS) : [];
            $detcols = ($nD && $j !== false) ? array_slice($texts, $j + 1, $nD) : ($j !== false ? array_slice($texts, $j + 1, 1) : []);
            $rest = ($nD && $j !== false) ? array_slice($texts, $j + 1 + $nD) : [];
            $detRows = $nD ? array_chunk($rest, $nD) : [];
            $expVals = array_map('q251_num', $vals);
            $expRows = $rows;
            $nm = 'PDF';
            if ($nD === 0) {
                $t->true(!in_array('Document No.', $texts, true), "$label $nm: tanpa kolom Rincian -> judul kolom Rincian tidak tercetak");
            }
        }
        $ok = true;
        if ($nS) {
            $ok = $ok && $sumcols === $titS && $sumvals === $expVals;
            $t->eq($sumcols, $titS, "$label $nm: kolom Ringkasan");
            $t->eq($sumvals, $expVals, "$label $nm: angka Ringkasan");
        } else {
            $ok = $ok && array_intersect($sumcols, array_values(Q251_SUMT)) === [];
            $t->eq(array_values(array_intersect($sumcols, array_values(Q251_SUMT))), [], "$label $nm: tanpa kolom Ringkasan (tabel Ringkasan tidak tertulis)");
        }
        if ($nD) {
            $ok = $ok && $detcols === $titD && $detRows === $expRows;
            $t->eq($detcols, $titD, "$label $nm: kolom Rincian");
            $t->eq($detRows, $expRows, "$label $nm: baris Rincian (" . count($expRows) . ' baris)');
        } else {
            $ok = $ok && array_intersect($detcols, array_values(Q251_DETT)) === [];
            $t->eq(array_values(array_intersect($detcols, array_values(Q251_DETT))), [], "$label $nm: tanpa kolom Rincian");
        }

        return $ok;
    }

    /**
     * Satu keadaan: (1) tiga proses CLI terbatas waktu (xls, pdf, pratinjau {}), (2) baru lalu HTTP GET xls, GET pdf, POST pdf/preview {},
     * GET transaction-templates, masing-masing 200 dan kolomnya = $expS/$expD (kunci data_index berurut; [] = tabel tanpa kolom), (3) isi
     * transaction_data baris template tidak berubah oleh pembacaan (fallback tidak menulis). $soft = jenis yang boleh non-200 (dicatat).
     * @return array catatan status per jenis, atau ['hang' => [...]]
     */
    function q251_fb_check($t, $s, $id, $label, array $expS, array $expD, array $soft = [])
    {
        $before = q251_tpl_row($t)[0]['transaction_data'] ?? null;
        $cli = [];
        $hang = [];
        foreach (['xls', 'pdf', 'preview'] as $kind) {
            $cli[$kind] = q251_xls_cli($t, $id, $kind, []);
            if ($cli[$kind]['timeout']) {
                $hang[] = $kind;
            }
        }
        $t->true($hang === [], "$label: CLI xls/pdf/pratinjau selesai dalam batas waktu (tidak berputar)" . ($hang ? ' - BERPUTAR: ' . implode(',', $hang) : ''));
        if ($hang) {
            return ['hang' => $hang];
        }
        $note = ['cli' => array_map(function ($c) {
            return $c['ok'] ? 'ok ' . $c['sec'] . 's' : 'EXC ' . substr($c['err'], 0, 90);
        }, $cli)];
        foreach (['xls', 'pdf', 'preview'] as $kind) {
            if (!in_array($kind, $soft, true)) {
                $t->true($cli[$kind]['ok'], "$label: CLI $kind selesai tanpa exception " . ($cli[$kind]['ok'] ? '' : substr($cli[$kind]['err'], 0, 160)));
            }
        }

        $counts = q251_counts($t, $id);
        $rowsAll = q251_expect_rows(q251_api_rows($t, $s, $id, 5), 'en');
        // urutan HTTP: pdf dan pratinjau DULU (aman pada kode lama maupun baru); GET xls hanya bila PDF membuktikan fallback/kolom yang
        // diharapkan benar-benar berlaku di php-fpm (kode lama: Excel tanpa kolom berputar tak berujung dan menggantung worker)
        $http = [
            'pdf' => function () use ($t, $s, $id) {
                return q251_file($t, $s, $id, 'pdf');
            },
            'preview' => function () use ($t, $s, $id) {
                return q251_preview($t, $s, $id, []);
            },
            'xls' => function () use ($t, $s, $id) {
                return q251_file($t, $s, $id, 'xls');
            },
        ];
        $pdfOk = true;
        foreach ($http as $kind => $fn) {
            if ($kind === 'xls' && !$pdfOk) {
                $note['xls'] = 'TIDAK DIKIRIM';
                $t->fail("$label: GET xls HTTP tidak dikirim - PDF/pratinjau tidak menunjukkan kolom yang diharapkan (php-fpm mungkin melayani kode lama; menghindari menggantung worker)");
                continue;
            }
            $r = $fn();
            $note[$kind] = $r[0];
            if (in_array($kind, $soft, true) && $r[0] !== 200) {
                if ($kind !== 'xls') {
                    $pdfOk = false;
                }
                continue;
            }
            $t->eq($r[0], 200, "$label: HTTP $kind");
            if ($r[0] === 200) {
                $ok = q251_fb_verify($t, $kind === 'xls' ? 'xls' : 'pdf', $r[1], $expS, $expD, "$label [$kind]", $counts, $rowsAll);
                if ($kind !== 'xls') {
                    $pdfOk = $pdfOk && $ok;
                }
            } elseif ($kind !== 'xls') {
                $pdfOk = false;
            }
        }
        $r = q251_sget($t, $s, 'transaction-templates');
        $note['select'] = $r[0];
        if (!in_array('select', $soft, true) || $r[0] === 200) {
            $t->eq($r[0], 200, "$label: HTTP transaction-templates");
            if ($r[0] === 200) {
                $tpl = $r[1]['result'];
                $ts = q251_tbl($tpl, 'summaries');
                $td = q251_tbl($tpl, 'data');
                $t->eq(q251_fb_keys($ts), $expS, "$label: transaction-templates kolom Ringkasan (Customize menampilkan yang dirender)");
                $t->eq(q251_fb_keys($td), $expD, "$label: transaction-templates kolom Rincian");
            }
        }
        $t->eq(q251_tpl_row($t)[0]['transaction_data'] ?? null, $before, "$label: transaction_data baris template tidak berubah oleh pembacaan (fallback tidak menulis)");

        return $note;
    }

    /** Body Simpan Customize dengan tabel mentah $tables. */
    function q251_fb_save($t, $s, array $tpl, $tables, $label)
    {
        $o = q251_save_body($tpl, []);
        $o['tables'] = $tables; // bentuk mentah (q251_save_body menuntut array)
        unset($o['footer']['labels']);
        $r = $t->call($s, 'POST', 'api/v5/setting/pdf-templates/update', $o);
        $t->true($r[0] < 500, "$label: Simpan Customize HTTP {$r[0]} (tanpa 5xx)");

        return $r[0];
    }

    function q251_fb_table($key, array $cur, array $avail, array $extra = [])
    {
        return $extra + ['data_index' => [$key], 'available_columns' => $avail, 'current_columns' => $cur, 'total_field' => null, 'fields' => []];
    }

    function q251_fb_log($name, $data)
    {
        @mkdir(QA_AGENTIC . '/work/ed1251', 0775, true);
        file_put_contents(QA_AGENTIC . '/work/ed1251/' . $name . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

return [
    [
        'id'    => 'D-1',
        'title' => 'Simpan Customize dengan kolom kosong/tak terpakai: GET xls (CLI terbatas waktu dulu), pdf, pratinjau, transaction-templates tanpa berputar/5xx; fallback ke display setting',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                $id = $ids['S1'];
                $tpl = q251_tpl($t, $s);
                $sum = q251_tbl($tpl, 'summaries');
                $det = q251_tbl($tpl, 'data');
                $S = array_keys(Q251_SUMT);
                $D = array_keys(Q251_DETT);
                $t->eq(q251_fb_keys($sum), $S, 'prasyarat: Ringkasan default 5 kolom');
                $t->eq(q251_fb_keys($det), $D, 'prasyarat: Rincian default 5 kolom');
                $sc = $sum['current_columns'];
                $dc = $det['current_columns'];
                $noDi = [['title' => ['en' => 'X', 'id' => 'X'], 'type' => 'string', 'class' => 'align-left']];
                $diShapes = [
                    ['title' => ['en' => 'A', 'id' => 'A'], 'data_index' => [], 'type' => 'string'],
                    ['title' => ['en' => 'B', 'id' => 'B'], 'data_index' => null, 'type' => 'string'],
                    ['title' => ['en' => 'C', 'id' => 'C'], 'data_index' => '', 'type' => 'string'],
                    ['title' => ['en' => 'D', 'id' => 'D'], 'data_index' => [1], 'type' => 'string'],
                    ['title' => ['en' => 'E', 'id' => 'E'], 'data_index' => [[]], 'type' => 'string'],
                    null,
                ];
                $T = function ($s_, $d_, $sa = null, $da = null) use ($sum, $det) {
                    return [q251_fb_table('summaries', $s_, $sa ?? $sum['available_columns']), q251_fb_table('data', $d_, $da ?? $det['available_columns'])];
                };
                // [label => [tables, kolom Ringkasan, kolom Rincian]]
                $states = [
                    'satu tabel kosong: Ringkasan kosong, Rincian penuh' => [$T([], $dc), [], $D],
                    'satu tabel kosong: Ringkasan penuh, Rincian kosong' => [$T($sc, []), $S, []],
                    'satu kolom saja: Ringkasan kosong, Rincian [code]' => [$T([], [$dc[0]]), [], ['code']],
                    'satu kolom saja: Ringkasan [verified], Rincian kosong' => [$T([$sc[1]], []), ['verified'], []],
                    'KEDUA kosong (Customize mencabut semua kolom lalu Simpan)' => [$T([], []), $S, $D],
                    'kedua kosong, available_columns juga kosong' => [$T([], [], [], []), $S, $D],
                    'tables [] (kosong)' => [[], $S, $D],
                    'hanya tabel data, kosong (Ringkasan tak ada)' => [[q251_fb_table('data', [], $det['available_columns'])], $S, $D],
                    'hanya tabel summaries, kosong' => [[q251_fb_table('summaries', [], $sum['available_columns'])], $S, $D],
                    'kolom tanpa data_index di kedua tabel' => [$T($noDi, $noDi), $S, $D],
                    'data_index [] / null / "" / [1] / [[]] / kolom null (kedua tabel)' => [$T($diShapes, $diShapes), $S, $D],
                    'Ringkasan tanpa data_index + Rincian kosong' => [$T($noDi, []), $S, $D],
                    'Ringkasan kosong + Rincian tanpa data_index' => [$T([], $noDi), $S, $D],
                    'Ringkasan tanpa data_index + Rincian penuh (satu tabel tanpa kolom terpakai)' => [$T($noDi, $dc), [], $D],
                    'hanya tabel tak dikenal (data_index other) berisi kolom' => [[q251_fb_table('other', $dc, $dc)], $S, $D],
                    'tabel summaries/data kosong + tabel other berisi kolom' => [array_merge($T([], []), [q251_fb_table('other', $dc, $dc)]), $S, $D],
                    'data_index tabel string "data", kolom penuh (hanya tabel Rincian, Ringkasan tak ada)' => [[q251_fb_table('data', $dc, $dc, ['data_index' => 'data'])], [], $D],
                ];
                $log = [];
                $hangs = [];
                foreach ($states as $label => $st) {
                    $code = q251_fb_save($t, $s, $tpl, $st[0], $label);
                    if ($code !== 200) {
                        $t->note("$label: Simpan HTTP $code (keadaan tak tercapai)");
                        continue;
                    }
                    $r = q251_fb_check($t, $s, $id, $label, $st[1], $st[2]);
                    $log[$label] = $r;
                    if (isset($r['hang'])) {
                        $hangs[] = $label;
                    }
                }
                // pulihkan template baik
                $t->status($t->call($s, 'POST', 'api/v5/setting/pdf-templates/update', (function () use ($tpl) {
                    $o = q251_save_body($tpl, []);
                    unset($o['footer']['labels']);

                    return $o;
                })()), 200, 'pulihkan template (tables kosong)');
                q251_fb_log('d1-result', $log);
                $t->true($hangs === [], 'tidak ada keadaan yang membuat GET xls/pdf/pratinjau berputar tak berujung: ' . json_encode($hangs, JSON_UNESCAPED_UNICODE));
                $t->note(count($log) . ' keadaan diuji lewat CLI terbatas waktu lalu HTTP (work/ed1251/d1-result.json)');
            });
        },
    ],

    [
        'id'    => 'D-1b',
        'title' => 'Display setting tanpa kolom terpakai (template belum disimpan / tersimpan kosong): rantai template -> display setting -> config, tanpa berputar/5xx',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                $id = $ids['S1'];
                $tpl = q251_tpl($t, $s); // membuat baris cds + template dari config
                $t->eq(json_decode(q251_tpl_row($t)[0]['transaction_data'], true)['tables'], [], 'prasyarat: tables template kosong (belum disimpan)');
                $S = array_keys(Q251_SUMT);
                $D = array_keys(Q251_DETT);
                $g = $t->call($s, 'GET', Q251_DS . Q251_MODULE);
                $t->status($g, 200, 'GET display setting');
                $curOk = $g[1]['result']['current_columns'];
                $sumOk = json_decode(q251_cds_rows($t)[0]['summaries_columns'], true);
                $noDi = [['title' => ['en' => 'X', 'id' => 'X'], 'type' => 'string']];
                $reset = ['current_columns' => $curOk, 'summaries_columns' => $sumOk];
                $put = function ($body, $label) use ($t, $s, $reset) {
                    sleep(1); // A-1: isi sama di detik yang sama = 500 (diterima)
                    $a = $t->call($s, 'PUT', Q251_DS . Q251_MODULE, $reset);
                    sleep(1);
                    $r = $t->call($s, 'PUT', Q251_DS . Q251_MODULE, $body);
                    $t->true($a[0] < 500 && $r[0] < 500, "PUT '$label': HTTP {$r[0]} (tanpa 5xx)");

                    return $r[0];
                };
                $db = function (array $set) use ($t) {
                    q251_w($t, function ($c) use ($set) {
                        $c->table('column_display_settings')->where('module_name', Q251_MODULE)->update($set);
                    });
                };
                $log = [];
                $hangs = [];
                $run = function ($label, $expS, $expD) use ($t, $s, $id, &$log, &$hangs) {
                    $r = q251_fb_check($t, $s, $id, $label, $expS, $expD);
                    $log[$label] = $r;
                    if (isset($r['hang'])) {
                        $hangs[] = $label;
                    }
                };

                // --- template belum disimpan (tables []): sumber = display setting; tanpa kolom terpakai -> config
                $states = [
                    'cds summaries_columns [] + current_columns tanpa data_index' => [['summaries_columns' => [], 'current_columns' => $noDi], $S, $D],
                    'cds summaries_columns [] + current_columns []' => [['summaries_columns' => [], 'current_columns' => []], $S, $D],
                    'cds hanya summaries_columns [] (Rincian penuh)' => [['summaries_columns' => [], 'current_columns' => $curOk], [], $D],
                    'cds hanya current_columns [] (Ringkasan penuh)' => [['summaries_columns' => $sumOk, 'current_columns' => []], $S, []],
                    'cds satu kolom Rincian [resultLabel], Ringkasan []' => [['summaries_columns' => [], 'current_columns' => [$curOk[4]]], [], ['resultLabel']],
                ];
                foreach ($states as $label => $st) {
                    if ($put($st[0], $label) !== 200) {
                        $t->note("$label: PUT bukan 200 (keadaan tak tercapai)");
                        continue;
                    }
                    $run("tables [] / $label", $st[1], $st[2]);
                }
                // urutan/ judul display setting dipakai bila ada kolom terpakai (bukan config)
                $label = 'cds kolom dibalik + Ringkasan 2 kolom';
                $rev = array_reverse($curOk);
                $sum2 = [$sumOk[1], $sumOk[0]];
                if ($put(['summaries_columns' => $sum2, 'current_columns' => $rev], $label) === 200) {
                    $run("tables [] / $label", ['verified', 'totalDocuments'], array_reverse($D));
                }
                $put($reset, 'pulihkan');
                // kolom NULL / JSON skalar langsung di DB (baris tak lengkap): ketiga kolom sekaligus
                $db(['summaries_columns' => null, 'current_columns' => null, 'available_columns' => null]);
                $run('DB cds ketiga kolom NULL', $S, $D);
                $db(['summaries_columns' => json_encode($sumOk), 'current_columns' => '[]', 'available_columns' => json_encode($curOk)]);
                $run('DB cds hanya current_columns [] (PUT menolaknya; Ringkasan penuh)', $S, []);
                $db(['summaries_columns' => '"x"', 'current_columns' => '5', 'available_columns' => 'true']);
                $run('DB cds ketiga kolom JSON skalar', $S, $D);
                $db(['summaries_columns' => '[]', 'current_columns' => '[]', 'available_columns' => '[]']);
                $run('DB cds ketiga kolom []', $S, $D);
                $db(['summaries_columns' => json_encode($noDi), 'current_columns' => json_encode($noDi), 'available_columns' => json_encode($noDi)]);
                $run('DB cds ketiga kolom tanpa data_index', $S, $D);
                q251_w($t, function ($c) {
                    $c->table('column_display_settings')->where('module_name', Q251_MODULE)->delete();
                });
                $run('baris cds dihapus (template ada): dibuat ulang dari config', $S, $D);
                $t->eq(count(q251_cds_rows($t)), 1, 'baris cds dibuat ulang tepat satu');
                $db(['summaries_columns' => json_encode($sumOk), 'current_columns' => json_encode($curOk), 'available_columns' => json_encode($curOk)]);

                // --- template tersimpan KOSONG di kedua tabel: rantai ke display setting (custom), lalu ke config (cds tak terpakai)
                $sum = q251_tbl($tpl, 'summaries');
                $det = q251_tbl($tpl, 'data');
                $t->eq(q251_fb_save($t, $s, $tpl, [q251_fb_table('summaries', [], $sum['available_columns']), q251_fb_table('data', [], $det['available_columns'])], 'tersimpan kosong'), 200, 'Simpan Customize kedua tabel kosong');
                if ($put(['summaries_columns' => $sum2, 'current_columns' => [$curOk[4], $curOk[0]]], 'cds custom') === 200) {
                    $run('tersimpan kosong + cds custom (Ringkasan verified,total; Rincian resultLabel,code)', ['verified', 'totalDocuments'], ['resultLabel', 'code']);
                }
                if ($put(['summaries_columns' => [], 'current_columns' => $noDi], 'cds tak terpakai') === 200) {
                    $run('tersimpan kosong + cds tak terpakai -> config', $S, $D);
                }
                $db(['summaries_columns' => null, 'current_columns' => null, 'available_columns' => null]);
                $run('tersimpan kosong + cds NULL -> config', $S, $D);
                $db(['summaries_columns' => json_encode($sumOk), 'current_columns' => json_encode($curOk), 'available_columns' => json_encode($curOk)]);
                q251_fb_log('d1b-result', $log);
                $t->true($hangs === [], 'tidak ada keadaan display setting yang membuat xls/pdf/pratinjau berputar: ' . json_encode($hangs, JSON_UNESCAPED_UNICODE));
                $t->note(count($log) . ' keadaan display setting diuji (work/ed1251/d1b-result.json)');
            });
        },
    ],

    [
        'id'    => 'D-1c',
        'title' => 'POST pdf/preview: payload tables kosong/tanpa kolom/tanpa kunci -> tabel tersimpan atau fallback; kolom payload dipakai; payload tak sah 422; CLI terbatas waktu dulu',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                $id = $ids['S1'];
                $tpl = q251_tpl($t, $s);
                $sum = q251_tbl($tpl, 'summaries');
                $det = q251_tbl($tpl, 'data');
                $S = array_keys(Q251_SUMT);
                $D = array_keys(Q251_DETT);
                $sc = $sum['current_columns'];
                $dc = $det['current_columns'];
                $noDi = [['title' => ['en' => 'X', 'id' => 'X'], 'type' => 'string']];
                $counts = q251_counts($t, $id);
                $rowsAll = q251_expect_rows(q251_api_rows($t, $s, $id, 5), 'en');
                $T = function ($s_, $d_) use ($sum, $det) {
                    return [q251_fb_table('summaries', $s_, $sum['available_columns']), q251_fb_table('data', $d_, $det['available_columns'])];
                };
                $bare = function ($key) {
                    return ['data_index' => [$key]]; // tanpa kunci current_columns/available_columns
                };
                $payloads = [
                    // label => [payload, HTTP, kolom Ringkasan, kolom Rincian]
                    'tanpa tables (hanya paper_size)' => [['paper_size' => 'A4-P'], 200, $S, $D],
                    'tables []' => [['tables' => []], 200, $S, $D],
                    'kedua tabel current_columns []' => [['tables' => $T([], [])], 200, $S, $D],
                    'kedua tabel kolom tanpa data_index' => [['tables' => $T($noDi, $noDi)], 200, $S, $D],
                    'kedua tabel tanpa kunci current_columns/available_columns' => [['tables' => [$bare('summaries'), $bare('data')]], 200, $S, $D],
                    'current_columns null' => [['tables' => [['data_index' => ['summaries'], 'current_columns' => null, 'available_columns' => null], ['data_index' => ['data'], 'current_columns' => null]]], 200, $S, $D],
                    'hanya tabel tak dikenal berisi kolom' => [['tables' => [q251_fb_table('other', $dc, $dc)]], 200, $S, $D],
                    'payload kolom dipakai: Ringkasan [verified], Rincian [resultLabel, code]' => [['tables' => $T([$sc[1]], [$dc[4], $dc[0]])], 200, ['verified'], ['resultLabel', 'code']],
                    'payload Ringkasan [verified], Rincian [] (satu tabel kosong apa adanya)' => [['tables' => $T([$sc[1]], [])], 200, ['verified'], []],
                    'payload Ringkasan [], Rincian [code]' => [['tables' => $T([], [$dc[0]])], 200, [], ['code']],
                    'payload Rincian dibalik, urutan Ringkasan penuh' => [['tables' => $T($sc, array_reverse($dc))], 200, $S, array_reverse($D)],
                    // tak sah -> 422 (urutan 404 -> 422 -> 400)
                    'tables bukan array' => [['tables' => 'x'], 422, null, null],
                    'tables berisi string' => [['tables' => ['x']], 422, null, null],
                    'current_columns bukan array' => [['tables' => [['data_index' => ['data'], 'current_columns' => 'x', 'available_columns' => []]]], 422, null, null],
                    'available_columns bukan array' => [['tables' => [['data_index' => ['data'], 'current_columns' => [], 'available_columns' => 5]]], 422, null, null],
                ];
                $run = function ($stateLabel) use ($t, $s, $id, $payloads, $counts, $rowsAll) {
                    $hangs = [];
                    $res = [];
                    foreach ($payloads as $label => $p) {
                        $cli = q251_xls_cli($t, $id, 'preview', $p[0]);
                        if ($cli['timeout']) {
                            $hangs[] = $label;
                            $t->fail("$stateLabel / $label: CLI pratinjau BERPUTAR");
                            continue;
                        }
                        $r = q251_preview($t, $s, $id, $p[0]);
                        $res[$label] = $r[0];
                        $t->eq($r[0], $p[1], "$stateLabel / $label: HTTP pratinjau");
                        if ($p[1] === 200) {
                            $t->true($cli['ok'], "$stateLabel / $label: CLI pratinjau tanpa exception " . substr($cli['err'], 0, 120));
                            if ($r[0] === 200) {
                                q251_fb_verify($t, 'pdf', $r[1], $p[2], $p[3], "$stateLabel / $label", $counts, $rowsAll);
                            }
                        } else {
                            $t->true(is_array(json_decode($r[1], true)), "$stateLabel / $label: respons galat berupa JSON");
                        }
                    }

                    return [$hangs, $res];
                };
                $all = [];
                list($h1, $all['tables [] (belum disimpan)']) = $run('template belum disimpan');
                // template TERSIMPAN kosong di kedua tabel: pratinjau yang tak membawa kolom jatuh ke fallback yang sama dengan GET pdf
                $t->eq(q251_fb_save($t, $s, $tpl, $T([], []), 'tersimpan kosong'), 200, 'Simpan Customize kedua tabel kosong');
                list($h2, $all['tersimpan kosong']) = $run('template tersimpan kosong');
                // template tersimpan penuh dengan kolom khusus: payload tanpa kolom memakai tabel tersimpan, bukan display setting
                $t->eq(q251_fb_save($t, $s, $tpl, $T([$sc[1], $sc[0]], [$dc[4], $dc[0], $dc[1]]), 'tersimpan khusus'), 200, 'Simpan Customize kolom khusus');
                foreach (['tables []' => [['tables' => []], 200], 'kedua tabel current_columns []' => [['tables' => $T([], [])], 200], 'kolom tanpa data_index' => [['tables' => $T($noDi, $noDi)], 200]] as $label => $p) {
                    $cli = q251_xls_cli($t, $id, 'preview', $p[0]);
                    $t->true(!$cli['timeout'] && $cli['ok'], "tersimpan khusus / $label: CLI pratinjau selesai");
                    $r = q251_preview($t, $s, $id, $p[0]);
                    $t->eq($r[0], 200, "tersimpan khusus / $label: HTTP pratinjau");
                    q251_fb_verify($t, 'pdf', $r[1], ['verified', 'totalDocuments'], ['resultLabel', 'code', 'transactionTypeLabel'], "tersimpan khusus / $label", $counts, $rowsAll);
                }
                $t->eq(json_decode(q251_tpl_row($t)[0]['transaction_data'], true)['tables'][1]['current_columns'][0]['data_index'], ['resultLabel'], 'pratinjau tidak menulis: tabel tersimpan tetap');
                q251_fb_log('d1c-result', $all);
                $t->true($h1 === [] && $h2 === [], 'tidak ada payload pratinjau yang berputar: ' . json_encode([$h1, $h2], JSON_UNESCAPED_UNICODE));
            });
        },
    ],

    [
        'id'    => 'D-1d',
        'title' => 'Template tersimpan BUATAN TANGAN rusak (tables bukan array, tanpa current_columns, ...): Excel 200 + fallback; PDF/pratinjau/select yang 5xx dicatat (kode bersama)',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                $id = $ids['S1'];
                $tpl = q251_tpl($t, $s);
                $sum = q251_tbl($tpl, 'summaries');
                $det = q251_tbl($tpl, 'data');
                $S = array_keys(Q251_SUMT);
                $D = array_keys(Q251_DETT);
                $dc = $det['current_columns'];
                $bad = [
                    'tables string' => 'x',
                    'tables berisi string' => ['x'],
                    'tables berisi array kosong' => [[]],
                    'kedua tabel tanpa current_columns/available_columns' => [['data_index' => ['summaries']], ['data_index' => ['data']]],
                    'kedua tabel tanpa current_columns (available ada)' => [['data_index' => ['summaries'], 'available_columns' => $sum['available_columns']], ['data_index' => ['data'], 'available_columns' => $det['available_columns']]],
                    'kedua tabel current_columns "x"' => [['data_index' => ['summaries'], 'current_columns' => 'x', 'available_columns' => []], ['data_index' => ['data'], 'current_columns' => 'x', 'available_columns' => []]],
                    'kedua tabel current_columns null' => [['data_index' => ['summaries'], 'current_columns' => null, 'available_columns' => []], ['data_index' => ['data'], 'current_columns' => null, 'available_columns' => []]],
                    'current_columns [] tanpa available_columns' => [['data_index' => ['summaries'], 'current_columns' => []], ['data_index' => ['data'], 'current_columns' => []]],
                    'available_columns "x" (current kosong)' => [['data_index' => ['summaries'], 'current_columns' => [], 'available_columns' => 'x'], ['data_index' => ['data'], 'current_columns' => [], 'available_columns' => 'x']],
                    'current_columns berisi string (bukan kolom)' => [['data_index' => ['summaries'], 'current_columns' => ['x'], 'available_columns' => []], ['data_index' => ['data'], 'current_columns' => ['x'], 'available_columns' => []]],
                    'data_index tabel null' => [['data_index' => null, 'current_columns' => $dc, 'available_columns' => $dc]],
                ];
                $res = [];
                $hangs = [];
                $five = [];
                foreach ($bad as $label => $tables) {
                    $code = q251_fb_save($t, $s, $tpl, $tables, $label);
                    if ($code !== 200) {
                        $res[$label] = "Simpan $code";
                        continue;
                    }
                    // CLI terbatas waktu dulu untuk SEMUA jenis; pdf/pratinjau boleh EXC (5xx kode bersama), tidak boleh berputar
                    $cli = [];
                    foreach (['xls', 'pdf', 'preview'] as $kind) {
                        $cli[$kind] = q251_xls_cli($t, $id, $kind, []);
                        if ($cli[$kind]['timeout']) {
                            $hangs[] = "$label/$kind";
                        }
                    }
                    if (array_filter($cli, function ($c) {
                        return $c['timeout'];
                    })) {
                        $t->fail("$label: CLI berputar " . json_encode(array_keys(array_filter($cli, function ($c) {
                            return $c['timeout'];
                        }))));
                        continue;
                    }
                    $t->true($cli['xls']['ok'], "$label: CLI xls selesai tanpa exception " . substr($cli['xls']['err'], 0, 120));
                    $counts = q251_counts($t, $id);
                    $rowsAll = q251_expect_rows(q251_api_rows($t, $s, $id, 5), 'en');
                    $r = q251_file($t, $s, $id, 'xls');
                    $res["$label/xls"] = $r[0];
                    $t->eq($r[0], 200, "$label: GET xls 200 (Excel selalu punya kolom)");
                    if ($r[0] === 200) {
                        q251_fb_verify($t, 'xls', $r[1], $S, $D, "$label [xls]", $counts, $rowsAll);
                    }
                    $r = q251_file($t, $s, $id, 'pdf');
                    $res["$label/pdf"] = $r[0];
                    if ($r[0] === 200) {
                        q251_fb_verify($t, 'pdf', $r[1], $S, $D, "$label [pdf]", $counts, $rowsAll);
                    } elseif ($r[0] >= 500) {
                        $five[] = "$label/pdf";
                    }
                    $r = q251_preview($t, $s, $id, []);
                    $res["$label/preview"] = $r[0];
                    if ($r[0] >= 500) {
                        $five[] = "$label/preview";
                    }
                    $r = q251_sget($t, $s, 'transaction-templates');
                    $res["$label/select"] = $r[0];
                    if ($r[0] >= 500) {
                        $five[] = "$label/select";
                    }
                    // preview dengan payload berkolom pada template rusak (mis. Customize membuka lalu menyimpan ulang): informasi saja
                    $good = q251_save_body($tpl, [q251_fb_table('summaries', $sum['current_columns'], $sum['available_columns']), q251_fb_table('data', $dc, $det['available_columns'])]);
                    unset($good['footer']['labels']);
                    $t->status($t->call($s, 'POST', 'api/v5/setting/pdf-templates/update', $good), 200, "$label: Simpan ulang template baik (pemulihan)");
                }
                q251_fb_log('d1d-result', ['res' => $res, 'hangs' => $hangs, 'five' => $five]);
                $t->true($hangs === [], 'tidak ada keadaan rusak yang membuat xls/pdf/pratinjau berputar: ' . json_encode($hangs, JSON_UNESCAPED_UNICODE));
                $t->note('5xx PDF/pratinjau/select pada template tersimpan buatan tangan rusak (Di luar item #5, PdfTemplateService::getTemplate/generatePreview bersama; bentuk yang dikirim FE tidak memicu): ' . json_encode($five, JSON_UNESCAPED_UNICODE));
            });
        },
    ],
];
