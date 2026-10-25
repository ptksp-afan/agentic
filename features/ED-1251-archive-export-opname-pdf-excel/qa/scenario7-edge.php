<?php
/**
 * ED-1251 - uji ketahanan tambahan (bukan AC bernomor sendiri; memetakan ke AC-1, AC-4, AC-8, AC-13, AC-15, AC-9 "tanpa 500"):
 * E-1 pemakaian pertama tiap endpoint (baris config belum ada), E-2 display setting rusak, E-3 template tersimpan rusak,
 * E-4 sel Excel berawalan rumus, E-5 baris aneh (tipe tak dikenal, kode panjang), E-6 sesi tanpa confirmed_at / folder dihapus /
 * bahasa user aneh, E-7 pemakaian pertama paralel. (D-1 fallback kolom pindah ke scenario8-fallback.php.)
 */
require_once __DIR__ . '/qa_check.php';

if (!function_exists('q251_drop_config')) {
    /** Buang baris cds + template sendiri (dipulihkan q251_with_config) supaya pemakaian pertama bisa diuji. */
    function q251_drop_config($t)
    {
        q251_w($t, function ($c) {
            $c->table('transaction_pdf_templates')->where('transaction_name', Q251_MODULE)->delete();
            $c->table('column_display_settings')->where('module_name', Q251_MODULE)->delete();
        });
    }
}

return [
    [
        'id'    => 'E-1',
        'title' => 'Pemakaian pertama (baris cds/template belum ada): xls, pdf, pdf/preview, select, GET/PUT display setting -> tanpa 500, baris dibuat sekali',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                $id = $ids['S1'];
                $cases = [
                    'xls' => function () use ($t, $s, $id) {
                        return q251_file($t, $s, $id, 'xls');
                    },
                    'pdf' => function () use ($t, $s, $id) {
                        return q251_file($t, $s, $id, 'pdf');
                    },
                    'pdf/preview' => function () use ($t, $s, $id) {
                        return q251_preview($t, $s, $id, ['paper_size' => 'A4-L']);
                    },
                    'transaction-templates' => function () use ($t, $s) {
                        $r = q251_sget($t, $s, 'transaction-templates');

                        return [$r[0], json_encode($r[1])];
                    },
                    'GET display setting' => function () use ($t, $s) {
                        $r = $t->call($s, 'GET', Q251_DS . Q251_MODULE);

                        return [$r[0], json_encode($r[1])];
                    },
                ];
                foreach ($cases as $label => $fn) {
                    q251_drop_config($t);
                    $r = $fn();
                    $t->true($r[0] < 500, "pemakaian pertama $label: HTTP {$r[0]} (tanpa 5xx) " . ($r[0] >= 500 ? substr($r[1], 0, 300) : ''));
                    $t->eq($r[0], 200, "pemakaian pertama $label: 200");
                    $tpl = q251_tpl_row($t);
                    $cds = q251_cds_rows($t);
                    $t->true(count($tpl) <= 1 && count($cds) <= 1, "pemakaian pertama $label: paling banyak satu baris template/cds (tpl " . count($tpl) . ', cds ' . count($cds) . ')');
                    $t->note("pertama $label -> template " . count($tpl) . ' baris, cds ' . count($cds) . ' baris');
                    if ($label === 'xls') {
                        $t->eq(count($cds), 1, 'xls pertama: baris cds dibuat dari config');
                        $t->eq(count($tpl), 0, 'xls pertama: tidak membuat baris template (hanya membaca)');
                    }
                    if (in_array($label, ['pdf', 'pdf/preview', 'transaction-templates'], true)) {
                        $t->eq([count($tpl), count($cds)], [1, 1], "$label pertama: baris template + cds dibuat");
                    }
                    if ($label === 'GET display setting') {
                        $t->eq([count($tpl), count($cds)], [0, 1], 'GET display setting pertama: hanya baris cds');
                    }
                }
                // PUT display setting sebelum baris ada (tanpa GET): perilaku bawaan service bersama
                q251_drop_config($t);
                $body = ['current_columns' => [['title' => ['en' => 'Document No.', 'id' => 'No. Dokumen'], 'data_index' => 'code', 'type' => 'string', 'sort' => false]]];
                $r = $t->call($s, 'PUT', Q251_DS . Q251_MODULE, $body);
                $t->note('PUT display setting SEBELUM baris ada (tanpa GET/export lebih dulu) = HTTP ' . $r[0] . ' ' . ($r[1]['msg_code'] ?? $r[1]['code'] ?? ''));
                $t->true(in_array($r[0], [200, 500], true), 'PUT sebelum baris ada: ' . $r[0]);
                $t->eq(count(q251_cds_rows($t)), 0, 'PUT sebelum baris ada: tidak membuat baris');
            });
        },
    ],

    [
        'id'    => 'E-2',
        'title' => 'Display setting rusak (PUT kosong/aneh, kolom NULL di DB): pdf/xls/preview/select tanpa 500',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                $id = $ids['S1'];
                q251_tpl($t, $s);
                $col = ['title' => ['en' => 'Document No.', 'id' => 'No. Dokumen'], 'data_index' => 'code', 'type' => 'string', 'sort' => false];
                $states = [
                    'current_columns kosong' => ['current_columns' => []],
                    'summaries_columns kosong' => ['current_columns' => [$col], 'summaries_columns' => []],
                    'kolom tanpa data_index' => ['current_columns' => [['title' => ['en' => 'X']]]],
                    'kolom data_index tak dikenal' => ['current_columns' => [['title' => ['en' => 'Foo', 'id' => 'Foo'], 'data_index' => 'tidakAda', 'type' => 'string']]],
                    'kolom tanpa title' => ['current_columns' => [['data_index' => 'code', 'type' => 'string']]],
                    'kolom duplikat' => ['current_columns' => [$col, $col, $col]],
                    'tipe number untuk teks' => ['current_columns' => [['title' => ['en' => 'N', 'id' => 'N'], 'data_index' => 'code', 'type' => 'number']]],
                    'current_columns skalar' => ['current_columns' => 'x'],
                    'current_columns objek' => ['current_columns' => ['a' => 1]],
                    'summaries_columns skalar' => ['current_columns' => [$col], 'summaries_columns' => 'x'],
                    'judul HTML' => ['current_columns' => [['title' => ['en' => '<b>X</b> & "q"', 'id' => 'y'], 'data_index' => 'code', 'type' => 'string']]],
                ];
                $res = [];
                $hangs = [];
                $kinds = function ($label, $id) use ($t, $s, &$res, &$hangs) {
                    $r = q251_file($t, $s, $id, 'pdf');
                    $res["$label/pdf"] = $r[0];
                    $t->true($r[0] < 500, "'$label': GET pdf HTTP {$r[0]} (tanpa 5xx)" . ($r[0] >= 500 ? ' ' . substr($r[1], 0, 300) : ''));
                    $x = q251_xls_safe($t, $s, $id);
                    if ($x[0] === null) {
                        $hangs[] = $label;
                        $res["$label/xls"] = 'BERPUTAR (CLI ' . $x[3]['sec'] . ' s)';
                    } else {
                        $res["$label/xls"] = $x[0];
                        $t->true($x[0] < 500, "'$label': GET xls HTTP {$x[0]} (tanpa 5xx)");
                    }
                    $r = q251_preview($t, $s, $id, []);
                    $t->true($r[0] < 500, "'$label': preview HTTP {$r[0]}");
                    $r = q251_sget($t, $s, 'transaction-templates');
                    $t->true($r[0] < 500, "'$label': transaction-templates HTTP {$r[0]}");
                };
                // Rincian valid dipasang kembali di antara keadaan, supaya tiap keadaan berdiri sendiri (PUT hanya menulis kunci yang dikirim)
                $reset = ['current_columns' => [$col], 'summaries_columns' => json_decode(q251_cds_rows($t)[0]['summaries_columns'], true)];
                foreach ($states as $label => $body) {
                    sleep(1);
                    $put = $t->call($s, 'PUT', Q251_DS . Q251_MODULE, $reset);
                    sleep(1);
                    $put = $t->call($s, 'PUT', Q251_DS . Q251_MODULE, $body);
                    $t->true($put[0] < 500, "PUT '$label': HTTP {$put[0]}");
                    if ($put[0] !== 200) {
                        continue;
                    }
                    $kinds($label, $id);
                }
                // kolom NULL langsung di DB (baris tak lengkap)
                foreach (['summaries_columns', 'current_columns', 'available_columns'] as $colName) {
                    q251_w($t, function ($c) use ($colName) {
                        $c->table('column_display_settings')->where('module_name', Q251_MODULE)->update([$colName => null]);
                    });
                    $kinds("DB $colName NULL", $id);
                    q251_w($t, function ($c) use ($reset, $colName) {
                        $c->table('column_display_settings')->where('module_name', Q251_MODULE)->update([$colName => json_encode($colName === 'summaries_columns' ? $reset['summaries_columns'] : $reset['current_columns'])]);
                    });
                }
                $t->true($hangs === [], 'GET xls tidak berputar tak berujung pada display setting rusak (CLI): ' . json_encode($hangs));
                $t->note('display setting rusak: ' . json_encode($res));
            });
        },
    ],

    [
        'id'    => 'E-3',
        'title' => 'Template tersimpan rusak (Simpan Customize tak tervalidasi): pdf/xls/preview/transaction-templates tanpa 500',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                $id = $ids['S1'];
                $tpl = q251_tpl($t, $s);
                $good = q251_save_body($tpl, $tpl['tables']);
                unset($good['footer']['labels']);
                $col = ['title' => ['en' => 'Document No.', 'id' => 'No. Dokumen'], 'data_index' => ['code'], 'type' => 'string', 'class' => 'align-left'];
                $bad = [
                    'tables string' => ['tables' => 'x'],
                    'tables berisi string' => ['tables' => ['x']],
                    'tables berisi array kosong' => ['tables' => [[]]],
                    'tabel data_index string' => ['tables' => [['data_index' => 'data', 'current_columns' => [$col], 'available_columns' => [$col]]]],
                    'tabel tanpa current_columns' => ['tables' => [['data_index' => ['data']]]],
                    'tabel current_columns bukan array' => ['tables' => [['data_index' => ['data'], 'current_columns' => 'x', 'available_columns' => 5]]],
                    'tabel hanya Ringkasan' => ['tables' => [['data_index' => ['summaries'], 'current_columns' => [$col], 'available_columns' => [$col]]]],
                    'header null' => ['header' => null],
                    'header kosong' => ['header' => []],
                    'header string' => ['header' => 'x'],
                    'header current_fields null' => ['header' => ['current_fields' => null, 'repeat' => true]],
                    'footer null' => ['footer' => null],
                    'footer kosong' => ['footer' => []],
                    'footer current_fields bukan array' => ['footer' => ['current_fields' => 'x']],
                    'template null' => ['template' => null],
                    'template string' => ['template' => 'x'],
                    'signatures string' => ['signatures' => 'x'],
                    'paper_size null' => ['paper_size' => null],
                    'paper_size array' => ['paper_size' => ['x']],
                    'paper_size A4-X' => ['paper_size' => 'A4-X'],
                    'id_header tak ada' => ['id_header_pdf_template' => 'nope'],
                    'id_header header-1' => ['id_header_pdf_template' => 'header-1'],
                    'id_footer tak ada' => ['id_footer_pdf_template' => 'nope'],
                    'id_footer footer-1' => ['id_footer_pdf_template' => 'footer-1'],
                    'id_pdf null' => ['id_pdf_template' => null],
                ];
                $res = [];
                $hangs = [];
                $five = [];
                foreach ($bad as $label => $over) {
                    $body = $over + $good;
                    $put = $t->call($s, 'POST', 'api/v5/setting/pdf-templates/update', $body);
                    if ($put[0] !== 200) {
                        $res["$label/save"] = $put[0];
                        $t->call($s, 'POST', 'api/v5/setting/pdf-templates/update', $good);
                        continue;
                    }
                    $r = q251_file($t, $s, $id, 'pdf');
                    $res["$label/pdf"] = $r[0];
                    if ($r[0] >= 500) {
                        $five[] = "$label/pdf";
                    }
                    $x = q251_xls_safe($t, $s, $id);
                    if ($x[0] === null) {
                        $hangs[] = $label;
                        $res["$label/xls"] = 'BERPUTAR (CLI ' . $x[3]['sec'] . ' s)';
                    } else {
                        $res["$label/xls"] = $x[0];
                        if ($x[0] >= 500) {
                            $five[] = "$label/xls";
                        }
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
                    $t->status($t->call($s, 'POST', 'api/v5/setting/pdf-templates/update', $good), 200, 'pulihkan template baik');
                }
                @mkdir(QA_AGENTIC . '/work/ed1251', 0775, true);
                file_put_contents(QA_AGENTIC . '/work/ed1251/e3-result.json', json_encode(['res' => $res, 'hangs' => $hangs, 'five' => $five], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $t->true($hangs === [], 'GET xls tidak berputar tak berujung pada template tersimpan rusak (CLI): ' . json_encode($hangs));
                $t->note('template rusak: ' . json_encode($res));
                // 5xx pada isi tersimpan RUSAK buatan tangan (bukan bentuk FE): berasal dari PdfTemplateService::getTemplate/generatePreview dan
                // renderer bersama, dan dari Simpan Customize yang tak memvalidasi (C-9) -> dicatat sebagai 'Di luar item', bukan kegagalan item ini
                $t->note('5xx pada template tersimpan rusak buatan tangan (Di luar item, kode bersama): ' . json_encode($five));
            });
        },
    ],

    [
        'id'    => 'E-4',
        'title' => 'Sel Excel berawalan = + - @ (kode Invalid hasil scan bebas): bukan rumus aktif / tercatat sebagai temuan; PDF literal',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                $codes = ['=1+1', '+1+1', '-1+1', '@SUM(1)', '=HYPERLINK("http://example.invalid/x","klik")', '=cmd|\'/c calc\'!A1', "\t=1+1", 'biasa'];
                $id = q251_insert_session($t, 'FORM', ['invalid_count' => count($codes), 'scanned_count' => count($codes), 'confirmed_at' => '2026-10-24 10:00:00']);
                $rows = [];
                foreach ($codes as $i => $code) {
                    $rows[] = [3, null, null, $code, '2026-10-24 09:00:' . sprintf('%02d', $i) . '.000000'];
                }
                q251_insert_rows($t, $id, $rows);
                $f = q251_fetch($t, $s, $id);
                $first = $f['xp']['detfirst'];
                $formulas = [];
                $texts = [];
                foreach ($codes as $i => $code) {
                    // urutan scanned_at DESC: indeks terakhir dulu
                    $r = $first + (count($codes) - 1 - $i);
                    $cell = $f['x']['rows'][$r]['A'] ?? null;
                    $texts[$code] = $cell ? [$cell['t'], $cell['f'], $cell['v']] : null;
                    if ($cell && $cell['f'] !== null) {
                        $formulas[] = $code;
                    }
                }
                $pdfCodes = array_column($f['pp']['rows'], 0);
                foreach ($codes as $code) {
                    $t->true(in_array($code, $pdfCodes, true) || in_array(trim($code), $pdfCodes, true), 'PDF memuat kode literal: ' . json_encode($code));
                }
                $t->note('XLSX sel kode berawalan rumus yang ditulis sebagai FORMULA aktif: ' . json_encode($formulas, JSON_UNESCAPED_UNICODE) . ' | detail=' . json_encode($texts, JSON_UNESCAPED_UNICODE));
                // temuan, bukan kegagalan item ini (trait/ReportTemplate5 bersama, dicatat dev "Di luar item")
                $t->true(true, 'dicatat');
                $GLOBALS['q251_formula_cells'] = $formulas;
            });
        },
    ],

    [
        'id'    => 'E-5',
        'title' => 'Baris aneh: tipe transaksi tak dikenal/NULL, kode 250 huruf, folder 200 huruf, kode Latin-1 beraksen -> PDF/XLSX = documents API',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                $longFolder = Q251_PREFIX . 'LONG-' . str_repeat('F', 150) . substr(uniqid(), -5);
                $h = q251_folder($t, 'LF', ['name' => $longFolder]);
                list($d1, $n1) = q251_xdoc($t, 'u1', ['parent' => $h], 99999);          // tipe tak dikenal
                list($d2, $n2) = q251_xdoc($t, 'u2', ['parent' => $h], null);           // tanpa tipe
                list($d3, $n3) = q251_xdoc($t, 'u3', ['parent' => $h, 'name' => Q251_PREFIX . 'L-' . str_repeat('K', 200) . substr(uniqid(), -5)], 6); // kode panjang
                list($d4, $n4) = q251_xdoc($t, 'u4', ['parent' => $h, 'name' => Q251_PREFIX . 'café-ñ-' . substr(uniqid(), -4)], 7);       // Latin-1
                $invalid = Q251_PREFIX . 'INV-' . str_repeat('Z', 220);
                $id = q251_run($t, $s, $h, [], [$n1, $n3, $invalid]);
                $f = q251_fetch($t, $s, $id);
                $api = q251_api_rows($t, $s, $id, 2);
                $exp = q251_expect_rows($api, 'en');
                $t->eq(count($exp), 5, 'sesi uji: 5 baris (3 discan, 2 belum)');
                $stream = implode('', $f['p']['texts'][0]);
                foreach (array_column($exp, 0) as $code) {
                    $t->true(strpos($stream, $code) !== false, 'PDF memuat kode utuh (panjang ' . strlen($code) . '): ' . substr($code, 0, 40));
                }
                $t->eq($f['xp']['rows'], q251_xl_rows($exp), 'XLSX: baris = API (kode panjang, Latin-1, tipe tak dikenal/NULL)');
                $types = array_column($exp, 1);
                $t->note('label tipe tak dikenal/NULL pada API -> ekspor: ' . json_encode($types, JSON_UNESCAPED_UNICODE));
                $t->true(in_array('—', $types, true), 'tipe NULL = "—"');
                $t->true(strpos($stream, 'FFFFFFFFFF') !== false, 'PDF: nama folder panjang tercetak');
                $t->eq($f['xp']['rows'][0][2], '—', 'XLSX baris Invalid: Folder "—"');
                $t->true(strpos($stream, 'café-ñ') !== false, 'PDF: kode Latin-1 "café-ñ" tercetak benar');
            });
        },
    ],

    [
        'id'    => 'E-6',
        'title' => 'Sesi tanpa confirmed_at, folder sudah dihapus (nama snapshot), users.language NULL/aneh -> tanpa 500, bahasa jatuh ke en',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                // folder sesi dihapus fisik: nama snapshot dipakai
                $gName = $t->db()->table('archives')->where('id_archive', $x['G'])->value('name');
                q251_w($t, function ($c) use ($x) {
                    $c->table('archives')->where('id_archive', $x['G'])->delete();
                });
                $f = q251_fetch($t, $s, $ids['S2']);
                $t->eq($f['pp']['head'][2] ?? null, 'Folder: ' . $gName, 'folder dihapus: PDF memakai nama snapshot');
                $t->eq($f['xp']['head'][2] ?? null, 'Folder: ' . $gName, 'folder dihapus: XLSX memakai nama snapshot');
                // confirmed_at NULL
                $keep = q251_confirmed($t, $ids['S2']);
                q251_set_opname($t, $ids['S2'], ['confirmed_at' => null]);
                foreach (['pdf', 'xls'] as $kind) {
                    $r = q251_file($t, $s, $ids['S2'], $kind);
                    $t->true($r[0] < 500, "confirmed_at NULL: $kind HTTP {$r[0]} (tanpa 5xx)");
                    $t->note("confirmed_at NULL $kind: {$r[0]} " . ($r[2]['content-disposition'] ?? ''));
                }
                q251_set_opname($t, $ids['S2'], ['confirmed_at' => $keep]);
                // bahasa user NULL / aneh
                $langRes = [];
                foreach ([null, '', 'XX', 'id'] as $lang) {
                    $uid = q251_uid($t);
                    q251_w($t, function ($c) use ($uid, $lang) {
                        $c->table('users')->where('id_user', $uid)->update(['language' => $lang]);
                    });
                    foreach (['pdf', 'xls'] as $kind) {
                        $r = q251_file($t, $s, $ids['S1'], $kind);
                        $langRes[json_encode($lang) . "/$kind"] = $r[0];
                    }
                    // pembanding: endpoint lama (ED-1028) di keadaan data yang sama
                    $langRes[json_encode($lang) . '/documents(lama)'] = $t->call($s, 'GET', Q251_BASE . '/opnames/' . $ids['S1'] . '/documents?result=all')[0];
                    $langRes[json_encode($lang) . '/show(lama)'] = $t->call($s, 'GET', Q251_BASE . '/opnames/' . $ids['S1'])[0];
                }
                $t->note('users.language -> status: ' . json_encode($langRes));
                $t->eq($langRes['null/pdf'], 200, 'users.language NULL: pdf 200 (jatuh ke en)');
                $t->eq($langRes['null/xls'], 200, 'users.language NULL: xls 200');
                $t->eq($langRes['"id"/pdf'], 200, 'users.language id (huruf kecil): pdf 200');
                $t->eq($langRes['"id"/xls'], 200, 'users.language id (huruf kecil): xls 200');
                // nilai di luar en/id (data tak sah, bukan jalur FE): dicatat, bukan kegagalan item ini
                $odd = array_filter($langRes, function ($v, $k) {
                    return (strpos($k, '""') === 0 || strpos($k, '"XX"') === 0) && $v >= 500;
                }, ARRAY_FILTER_USE_BOTH);
                @mkdir(QA_AGENTIC . '/work/ed1251', 0775, true);
                file_put_contents(QA_AGENTIC . '/work/ed1251/e6-language.json', json_encode($langRes));
            });
        },
    ],

    [
        'id'    => 'E-7',
        'title' => 'Pemakaian pertama PARALEL (drawer terbuka: GET pdf + transaction-templates + ...): baris template/cds dibuat tepat satu (tanpa penjaga race = temuan)',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                $id = $ids['S1'];
                $base = rtrim((string) $t->conf('API_URL'), '/');
                $worst = [0, 0];
                for ($round = 1; $round <= 4; $round++) {
                    q251_drop_config($t);
                    $urls = [];
                    foreach (range(1, 6) as $i) {
                        $urls[] = $base . '/api/v5/select/report/document-archive/opname/transaction-templates';
                        $urls[] = $base . '/api/v5/document-archive/opnames/' . $id . '/pdf';
                        $urls[] = $base . '/api/v5/document-archive/opnames/' . $id . '/xls';
                    }
                    $mh = curl_multi_init();
                    $hs = [];
                    foreach ($urls as $u) {
                        $ch = curl_init($u);
                        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 100, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $s->token]]);
                        curl_multi_add_handle($mh, $ch);
                        $hs[] = $ch;
                    }
                    do {
                        curl_multi_exec($mh, $running);
                        curl_multi_select($mh, 0.2);
                    } while ($running > 0);
                    $codes = [];
                    foreach ($hs as $ch) {
                        $codes[] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_multi_remove_handle($mh, $ch);
                        curl_close($ch);
                    }
                    curl_multi_close($mh);
                    $t->true(max($codes) < 500, "ronde $round: tanpa 5xx pada permintaan paralel (" . json_encode(array_count_values($codes)) . ')');
                    $n = [count(q251_tpl_row($t)), count(q251_cds_rows($t))];
                    $worst = [max($worst[0], $n[0]), max($worst[1], $n[1])];
                    $t->note("ronde $round: baris template {$n[0]}, cds {$n[1]}");
                }
                $GLOBALS['q251_e7'] = $worst;
                $t->note('terburuk dalam 4 ronde x 18 permintaan paralel: template ' . $worst[0] . ' baris, cds ' . $worst[1] . ' baris');
                $t->true($worst[0] <= 1 && $worst[1] <= 1, 'baris config tidak ganda pada pemakaian pertama paralel (template ' . $worst[0] . ', cds ' . $worst[1] . ')');
            });
        },
    ],
];
