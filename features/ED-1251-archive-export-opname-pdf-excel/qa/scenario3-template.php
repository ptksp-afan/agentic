<?php
/**
 * ED-1251 AC-8, AC-14, AC-15 (+ guard C-9) - display setting, pratinjau Customize, Simpan Customize (subtask ED-1258/ED-1259).
 * Baris cds + template documentArchiveOpnameResult dijurnal dan dipulihkan persis oleh q251_guard (q251_with_config).
 */
require_once __DIR__ . '/qa_check.php';

return [
    [
        'id'    => 'AC-8',
        'title' => 'Template belum disimpan: PUT display setting tanpa Time (current) dan tanpa Invalid (summaries) -> PDF dan XLSX tanpa kedua kolom; baris dipulihkan persis',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                $tpl = q251_tpl($t, $s); // membuat baris template + cds dari config bila belum ada
                $row = q251_tpl_row($t);
                $t->eq(count($row), 1, 'baris template ada');
                $t->eq(json_decode($row[0]['transaction_data'], true)['tables'], [], 'prasyarat: tables template KOSONG (belum disimpan)');
                $cdsBefore = q251_cds_rows($t);
                $t->eq(count($cdsBefore), 1, 'baris cds ada (config)');

                $g = $t->call($s, 'GET', Q251_DS . Q251_MODULE);
                $t->status($g, 200, 'GET display setting (pemegang Opname Document)');
                $cur = $g[1]['result']['current_columns'];
                $t->true(!array_key_exists('summaries_columns', $g[1]['result']), 'catatan: GET show() bawaan tidak menyertakan summaries_columns (kolom Ringkasan dibaca dari DB)');
                $sum = json_decode($cdsBefore[0]['summaries_columns'], true);
                $t->eq(count($cur), 5, 'GET: 5 kolom Rincian');
                $t->eq(count($sum), 5, 'DB: 5 kolom Ringkasan');
                $curNo = array_values(array_filter($cur, function ($c) {
                    return $c['data_index'] !== 'scanTime';
                }));
                $sumNo = array_values(array_filter($sum, function ($c) {
                    return $c['data_index'] !== 'invalid';
                }));
                sleep(1); // A-1: PUT isi sama di detik yang sama = 500 (diterima); isi di sini berbeda, jeda tetap dipasang
                $put = $t->call($s, 'PUT', Q251_DS . Q251_MODULE, ['current_columns' => $curNo, 'summaries_columns' => $sumNo]);
                $t->status($put, 200, 'PUT display setting tanpa Time/Invalid');
                $cds = q251_cds_rows($t);
                $t->eq(array_column(json_decode($cds[0]['current_columns'], true), 'data_index'), ['code', 'transactionTypeLabel', 'folderName', 'resultLabel'], 'DB cds current_columns tanpa scanTime');
                $t->eq(array_column(json_decode($cds[0]['summaries_columns'], true), 'data_index'), ['totalDocuments', 'verified', 'notFound', 'unscanned'], 'DB cds summaries_columns tanpa invalid');
                $t->eq(count(json_decode($cds[0]['available_columns'], true)), 5, 'DB cds available_columns tetap 5');
                $t->eq(json_decode(q251_tpl_row($t)[0]['transaction_data'], true)['tables'], [], 'template tetap tanpa tabel tersimpan');

                // PDF + XLSX tanpa kedua kolom
                $f = q251_fetch($t, $s, $ids['S1'], 'en', 4, 4);
                $detCols = ['Document No.', 'Transaction Type', 'Folder', 'Result'];
                $sumCols = ['Total Documents', 'Verified', 'Not Found', 'Not Scanned'];
                foreach (['pp' => 'PDF', 'xp' => 'XLSX'] as $k => $nm) {
                    $t->true($f[$k]['found'], "$nm: judul Summaries/Detail ada");
                    $t->eq($f[$k]['sumcols'], $sumCols, "$nm: Ringkasan tanpa Invalid");
                    $t->eq($f[$k]['sumvals'], $k === 'pp' ? ['8.00', '4.00', '1.00', '4.00'] : [8.0, 4.0, 1.0, 4.0], "$nm: angka Ringkasan tanpa Invalid, tidak bergeser");
                    $t->eq($f[$k]['detcols'], $detCols, "$nm: Rincian tanpa Time");
                }
                $api = q251_api_rows($t, $s, $ids['S1'], 5);
                $exp4 = array_map(function ($r) {
                    return [$r[0], $r[1], $r[2], $r[4]];
                }, q251_expect_rows($api, 'en'));
                $t->eq($f['pp']['rows'], $exp4, 'PDF: baris Rincian 4 kolom tanpa Time (isi tidak bergeser)');
                $t->eq($f['xp']['rows'], q251_xl_rows($exp4), 'XLSX: baris Rincian 4 kolom tanpa Time');
                $t->true(!in_array('Time', $f['pp']['detcols'], true) && !in_array('Invalid', $f['pp']['sumcols'], true), 'PDF tidak memuat judul Time/Invalid');

                // Customize memakai sumber yang sama
                $tpl2 = q251_tpl($t, $s);
                $t->eq(array_column(q251_tbl($tpl2, 'data')['current_columns'], 'title'), array_map(function ($c) {
                    return $c['title'];
                }, $curNo), 'transaction-templates: tabel Rincian = display setting (tanpa Time)');
                $t->eq(count(q251_tbl($tpl2, 'summaries')['current_columns']), 4, 'transaction-templates: Ringkasan 4 kolom');
                $t->eq(count(q251_tbl($tpl2, 'data')['available_columns']), 5, 'transaction-templates: available Rincian tetap 5');

                // pulihkan lewat API (jeda 1 detik) -> kembali 5 kolom di kedua berkas
                sleep(1);
                $t->status($t->call($s, 'PUT', Q251_DS . Q251_MODULE, ['current_columns' => $cur, 'summaries_columns' => $sum]), 200, 'PUT pulihkan');
                $f = q251_fetch($t, $s, $ids['S1']);
                $t->eq($f['pp']['detcols'], ['Document No.', 'Transaction Type', 'Folder', 'Time', 'Result'], 'PDF: 5 kolom Rincian kembali');
                $t->eq($f['xp']['sumcols'], ['Total Documents', 'Verified', 'Not Found', 'Invalid', 'Not Scanned'], 'XLSX: 5 kolom Ringkasan kembali');
                // seluruh baris cds identik dengan keadaan config awal (selain updated_at)
                $after = q251_cds_rows($t)[0];
                $before = $cdsBefore[0];
                foreach (['current_columns', 'available_columns', 'summaries_columns', 'module_name'] as $k) {
                    $t->eq(json_decode($after[$k], true), json_decode($before[$k], true), "cds $k kembali seperti config awal");
                }
            });
        },
    ],

    [
        'id'    => 'AC-14',
        'title' => 'POST pdf/preview: A4-L, header tanpa reportFilter, Rincian tanpa scanTime, language id, report-template-1/-2 -> 200; tabel template tidak berubah; validasi 422/400',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                $id = $ids['S1'];
                $tpl = q251_tpl($t, $s);
                $rowBefore = q251_tpl_row($t);
                $cdsBefore = q251_cds_rows($t);
                $otherBefore = q251_tpl_other($t);
                $by = q251_uname($t);

                // --- tanpa isi: sama dengan GET pdf (template tersimpan)
                $r = q251_preview($t, $s, $id, []);
                $t->status([$r[0]], 200, 'preview {}');
                $t->eq($r[2]['content-type'] ?? null, 'application/pdf', 'preview {}: application/pdf');
                $t->eq($r[2]['content-disposition'] ?? null, 'attachment; filename="' . q251_file_stem(q251_confirmed($t, $id)) . '.pdf"', 'preview {}: Content-Disposition nama berkas BR-19');
                $t->true(stripos($r[2]['access-control-expose-headers'] ?? '', 'Content-Disposition') !== false, 'preview {}: expose header Content-Disposition');
                $p0 = q251_pdf($r[1]);
                $g = q251_pdf(q251_file($t, $s, $id, 'pdf')[1]);
                $t->eq(array_slice($p0['texts'][0], 0, 5), array_slice($g['texts'][0], 0, 5), 'preview {}: header sama dengan GET pdf');
                $t->eq(q251_pdf_parts($p0['texts'][0], 'Summaries', 'Detail', 5, 5)['rows'], q251_pdf_parts($g['texts'][0], 'Summaries', 'Detail', 5, 5)['rows'], 'preview {}: baris sama dengan GET pdf');
                $t->true(abs($p0['media'][0][0] - 595.28) < 0.1, 'preview {}: A4 potret');

                // --- A4-L (snake_case) dan camelCase gaya FE
                $r = q251_preview($t, $s, $id, ['paper_size' => 'A4-L']);
                $t->status([$r[0]], 200, 'preview A4-L');
                $m = q251_pdf($r[1])['media'][0];
                $t->true($m[0] > $m[1] && abs($m[0] - 841.89) < 0.1, 'A4-L: MediaBox lebar > tinggi (' . json_encode($m) . ')');
                $r = q251_preview($t, $s, $id, ['paperSize' => 'A4-L', 'idPdfTemplate' => 'report-template-6', 'transactionName' => Q251_MODULE]);
                $t->status([$r[0]], 200, 'preview camelCase A4-L');
                $m = q251_pdf($r[1])['media'][0];
                $t->true($m[0] > $m[1], 'camelCase paperSize A4-L: lanskap');
                foreach (['A5-P' => [419.53, 595.28], 'Letter-L' => [792.0, 612.0], 'Legal-P' => [612.0, 1008.0], 'Folio-P' => [612.0, 936.0]] as $paper => $wh) {
                    $r = q251_preview($t, $s, $id, ['paper_size' => $paper]);
                    $t->status([$r[0]], 200, "preview $paper");
                    $m = q251_pdf($r[1])['media'][0];
                    $t->true(abs($m[0] - $wh[0]) < 1.5 && abs($m[1] - $wh[1]) < 1.5, "preview $paper: MediaBox " . json_encode($m));
                }

                // --- header tanpa reportFilter
                $hdr = $tpl['header'];
                $hdr['current_fields'] = ['companyName', 'reportName'];
                $r = q251_preview($t, $s, $id, ['header' => $hdr]);
                $t->status([$r[0]], 200, 'preview header tanpa reportFilter');
                $txt = implode("\n", q251_pdf($r[1])['texts'][0]);
                $t->true(strpos($txt, 'Folder:') === false && strpos($txt, 'Opname Date:') === false && strpos($txt, 'Opname By:') === false, 'header tanpa reportFilter: tanpa "Folder:" / "Opname Date:" / "Opname By:"');
                $t->true(strpos($txt, 'Opname Archive') !== false && strpos($txt, 'Sido Maju Lestari') !== false, 'header tanpa reportFilter: perusahaan + judul tetap');
                // header kosong sama sekali
                $hdr['current_fields'] = [];
                $r = q251_preview($t, $s, $id, ['header' => $hdr]);
                $t->status([$r[0]], 200, 'preview header kosong');
                $txt = implode("\n", q251_pdf($r[1])['texts'][0]);
                $t->true(strpos($txt, 'Sido Maju Lestari') === false && strpos($txt, 'Summaries') !== false, 'header kosong: tanpa perusahaan; tabel tetap');

                // --- Rincian tanpa scanTime (payload gaya FE: kedua tabel; Ringkasan tidak diubah)
                $data = q251_tbl($tpl, 'data');
                $sumT = q251_tbl($tpl, 'summaries');
                $keep = ['code', 'transactionTypeLabel', 'folderName', 'resultLabel'];
                $payloadTables = [
                    ['data_index' => ['summaries'], 'current_columns' => $sumT['current_columns'], 'available_columns' => $sumT['available_columns'], 'total_field' => null, 'fields' => []],
                    ['data_index' => ['data'], 'current_columns' => q251_pick($data['current_columns'], $keep), 'available_columns' => $data['available_columns'], 'total_field' => null, 'fields' => []],
                ];
                $r = q251_preview($t, $s, $id, ['tables' => $payloadTables]);
                $t->status([$r[0]], 200, 'preview Rincian tanpa scanTime');
                $pp = q251_pdf_parts(q251_pdf($r[1])['texts'][0], 'Summaries', 'Detail', 5, 4);
                $t->eq($pp['detcols'], ['Document No.', 'Transaction Type', 'Folder', 'Result'], 'Rincian tanpa kolom Time');
                $t->eq($pp['sumcols'], ['Total Documents', 'Verified', 'Not Found', 'Invalid', 'Not Scanned'], 'Ringkasan tetap 5 kolom');
                $t->eq(count($pp['rows']), 10, 'Rincian: 10 baris');
                $t->true(strpos(implode('|', $pp['head']), 'Time') === false, 'PDF tanpa kata "Time" di luar baris data');
                // payload hanya tabel Rincian: dipakai apa adanya (kontrak §2) -> hanya tabel Detail; FE selalu mengirim keduanya
                $r = q251_preview($t, $s, $id, ['tables' => [$payloadTables[1]]]);
                $t->status([$r[0]], 200, 'preview hanya tabel Rincian');
                $txt = q251_pdf($r[1])['texts'][0];
                $t->true(in_array('Detail', $txt, true) && !in_array('Summaries', $txt, true), 'payload hanya Rincian: PDF hanya tabel Detail (payload dipakai apa adanya)');
                $t->note('payload tables hanya Rincian -> PDF tanpa tabel Summaries (sesuai kontrak §2 "payload dipakai"; FE mengirim keduanya)');

                // --- language id
                $r = q251_preview($t, $s, $id, ['language' => 'id']);
                $t->status([$r[0]], 200, 'preview language id');
                $ti = q251_titles('id');
                $pp = q251_pdf_parts(q251_pdf($r[1])['texts'][0], $ti['sum'], $ti['det'], 5, 5);
                $t->true($pp['found'], 'language id: judul Ringkasan/Rincian');
                $t->eq($pp['sumcols'], $ti['sumcols'], 'language id: judul kolom Ringkasan id');
                $t->eq($pp['detcols'], $ti['detcols'], 'language id: judul kolom Rincian id');
                $raw = q251_confirmed($t, $id);
                $folderF = $t->db()->table('archives')->where('id_archive', $x['F'])->value('name');
                $t->eq(array_slice($pp['head'], 0, 5), q251_head_expect($t, 'id', $folderF . ' + 2 subfolder', $raw, $by, false), 'language id: header id');
                $r = q251_preview($t, $s, $id, ['language' => 'ID']); // tanpa beda huruf
                $t->status([$r[0]], 200, 'preview language ID (huruf besar)');

                // --- report-template-1 / -2
                foreach (['report-template-1', 'report-template-2'] as $tid) {
                    $r = q251_preview($t, $s, $id, ['id_pdf_template' => $tid]);
                    $t->status([$r[0]], 200, "preview $tid");
                    $pp = q251_pdf($r[1]);
                    $txt = implode("\n", $pp['texts'][0] ?? []);
                    $t->true($pp['pages'] >= 1 && strpos($txt, $x['n']['dF1']) !== false, "$tid: PDF terbentuk, memuat data sesi");
                    $t->true(strpos($txt, 'Sido Maju Lestari') !== false, "$tid: memuat perusahaan");
                }

                // --- tidak menyimpan apa pun
                $t->eq(json_encode(q251_tpl_row($t)), json_encode($rowBefore), 'preview: baris transaction_pdf_templates sendiri tidak berubah');
                $t->eq(json_encode(q251_cds_rows($t)), json_encode($cdsBefore), 'preview: baris cds tidak berubah');
                $t->eq(json_encode(q251_tpl_other($t)), json_encode($otherBefore), 'preview: template lain tidak berubah (C-9)');

                // --- validasi (urutan 404 -> 422 -> 400); tidak ada 5xx
                $cases = [
                    'language fr' => [['language' => 'fr'], 422, null],
                    'language bukan teks' => [['language' => ['en']], 422, null],
                    'paper_size Continuous-L' => [['paper_size' => 'Continuous-L'], 422, null],
                    'paper_size A4 tanpa orientasi' => [['paper_size' => 'A4'], 422, null],
                    'paper_size huruf kecil' => [['paper_size' => 'a4-l'], 422, null],
                    'paper_size array' => [['paper_size' => ['A4-L']], 422, null],
                    'header string' => [['header' => 'x'], 422, null],
                    'header.current_fields string' => [['header' => ['current_fields' => 'x']], 422, null],
                    'footer string' => [['footer' => 'x'], 422, null],
                    'footer.labels string' => [['footer' => ['labels' => 'x']], 422, null],
                    'tables string' => [['tables' => 'x'], 422, null],
                    'tables.0 string' => [['tables' => ['x']], 422, null],
                    'tables.0.current_columns string' => [['tables' => [['data_index' => ['data'], 'current_columns' => 'x']]], 422, null],
                    'tables.0.available_columns string' => [['tables' => [['data_index' => ['data'], 'available_columns' => 'x']]], 422, null],
                    'id_pdf_template array' => [['id_pdf_template' => ['report-template-1']], 422, null],
                    'id_header_pdf_template > 30' => [['id_header_pdf_template' => str_repeat('a', 31)], 422, null],
                    'id_footer_pdf_template array' => [['id_footer_pdf_template' => ['x']], 422, null],
                    'id_pdf_template template-1 (bukan laporan)' => [['id_pdf_template' => 'template-1'], 400, 'PDF404'],
                    'id_pdf_template tak ada' => [['id_pdf_template' => 'nope'], 400, 'PDF404'],
                    'id_pdf_template report-template-5 (tak bisa dirender)' => [['id_pdf_template' => 'report-template-5'], 400, 'PDF404'],
                ];
                foreach ($cases as $label => $c) {
                    $r = q251_preview($t, $s, $id, $c[0]);
                    q251_no500($t, [$r[0]], "preview $label");
                    $t->eq($r[0], $c[1], "preview $label: HTTP");
                    if ($c[2] !== null) {
                        $t->eq(q251_jerr($r)['code'] ?? null, $c[2], "preview $label: kode");
                    }
                }
                // urutan: 404 sebelum 422
                $r = $t->callFile($s, 'POST', Q251_BASE . '/opnames/' . Q251_RANDOM_ID . '/pdf/preview', ['language' => 'fr']);
                $t->eq($r[0], 404, 'sesi tak ada + payload salah: 404 lebih dulu');
                $t->eq(q251_jerr($r)['code'] ?? null, 'ARCHIVE412', 'kode ARCHIVE412');
                $r = q251_preview($t, $s, $id, ['language' => 'fr', 'id_pdf_template' => 'nope']);
                $t->eq($r[0], 422, '422 lebih dulu dari 400');

                // --- ketahanan payload aneh: tidak boleh 500
                $col = ['title' => ['en' => 'X', 'id' => 'X'], 'data_index' => ['code'], 'type' => 'string', 'class' => 'align-left'];
                $robust = [
                    'kolom data_index tak dikenal' => ['tables' => [['data_index' => ['data'], 'current_columns' => [['title' => ['en' => 'Foo'], 'data_index' => ['nope'], 'type' => 'string']], 'available_columns' => []]]],
                    'Rincian tanpa kolom' => ['tables' => [['data_index' => ['data'], 'current_columns' => [], 'available_columns' => []]]],
                    'Ringkasan tanpa kolom' => ['tables' => [['data_index' => ['summaries'], 'current_columns' => [], 'available_columns' => []]]],
                    'judul kolom HTML' => ['tables' => [['data_index' => ['data'], 'current_columns' => [['title' => ['en' => '<script>alert(1)</script><b>X</b>&amp;', 'id' => 'y'], 'data_index' => ['code'], 'type' => 'string']], 'available_columns' => []]]],
                    'judul kolom 2000 huruf' => ['tables' => [['data_index' => ['data'], 'current_columns' => [['title' => ['en' => str_repeat('W', 2000)], 'data_index' => ['code'], 'type' => 'string']], 'available_columns' => []]]],
                    'kolom tanpa title' => ['tables' => [['data_index' => ['data'], 'current_columns' => [['data_index' => ['code']]], 'available_columns' => []]]],
                    'kolom data_index bersarang' => ['tables' => [['data_index' => ['data'], 'current_columns' => [['title' => ['en' => 'N'], 'data_index' => ['a', 'b', 'c'], 'type' => 'string']], 'available_columns' => []]]],
                    'kolom duplikat' => ['tables' => [['data_index' => ['data'], 'current_columns' => [$col, $col, $col], 'available_columns' => [$col]]]],
                    'kolom tipe aneh' => ['tables' => [['data_index' => ['data'], 'current_columns' => [['title' => ['en' => 'D'], 'data_index' => ['code'], 'type' => 'date', 'render' => ['x'], 'children' => [['data_index' => ['code']]]]], 'available_columns' => []]]],
                    'tabel tak dikenal' => ['tables' => [['data_index' => ['lain'], 'current_columns' => [$col], 'available_columns' => [$col]]]],
                    'tabel data_index bukan array' => ['tables' => [['data_index' => 'data', 'current_columns' => [$col]]]],
                    'header field tak dikenal' => ['header' => ['current_fields' => ['companyName', 'bogus', 'reportFilter'], 'repeat' => true]],
                    'header repeat string' => ['header' => ['repeat' => 'x']],
                    'footer field tak dikenal' => ['footer' => ['current_fields' => ['bogus', 'page_number'], 'repeat' => false]],
                    'footer kosong' => ['footer' => ['current_fields' => []]],
                    'id_header header-1 (bukan laporan)' => ['id_header_pdf_template' => 'header-1'],
                    'id_header tak ada' => ['id_header_pdf_template' => 'nope'],
                    'id_footer footer-1 (bukan laporan)' => ['id_footer_pdf_template' => 'footer-1'],
                    'id_footer tak ada' => ['id_footer_pdf_template' => 'nope'],
                    'template aneh' => ['template' => ['fields' => 'x']],
                    'signatures aneh' => ['signatures' => 'x', 'signature' => ['a' => 1]],
                    'kunci tak dikenal' => ['foo' => ['bar' => 1], 'transaction_name' => 'reportPurchaseOrder', 'id_transaction_pdf_template' => 1],
                    'available_labels aneh' => ['available_labels' => 'x', 'current_labels' => 5],
                    'badan sangat besar' => ['junk' => str_repeat('x', 200000)],
                ];
                $statuses = [];
                foreach ($robust as $label => $body) {
                    $r = q251_preview($t, $s, $id, $body);
                    $statuses[$label] = $r[0];
                    $t->true($r[0] < 500, "preview ketahanan '$label': HTTP {$r[0]} (tidak boleh 5xx)" . ($r[0] >= 500 ? ' ' . substr($r[1], 0, 200) : ''));
                }
                $t->note('ketahanan preview: ' . json_encode($statuses, JSON_UNESCAPED_UNICODE));
                // tetap tidak ada yang tertulis
                $t->eq(json_encode(q251_tpl_row($t)), json_encode($rowBefore), 'sesudah payload aneh: baris template sendiri tidak berubah');
                $t->eq(json_encode(q251_tpl_other($t)), json_encode($otherBefore), 'sesudah payload aneh: template lain tidak berubah');
            });
        },
    ],

    [
        'id'    => 'AC-15',
        'title' => 'POST setting/pdf-templates/update (Rincian tanpa Time, Result sebelum Folder, judul diganti, Ringkasan tanpa Invalid) -> GET pdf DAN xls ikut; PUT display setting tidak mengubah; guard C-9',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                $id = $ids['S1'];
                $tpl = q251_tpl($t, $s);
                $otherBefore = q251_tpl_other($t);
                $namesBefore = $t->db()->table('transaction_pdf_templates')->pluck('transaction_name')->all();
                $row0 = q251_tpl_row($t)[0];

                $det = q251_tbl($tpl, 'data');
                $sum = q251_tbl($tpl, 'summaries');
                $keepDet = ['code', 'transactionTypeLabel', 'resultLabel', 'folderName']; // tanpa Time, Result sebelum Folder
                $keepSum = ['totalDocuments', 'verified', 'notFound', 'unscanned'];       // tanpa Invalid
                $curDet = q251_pick($det['current_columns'], $keepDet);
                $curDet[2]['title'] = ['en' => 'Final Result', 'id' => 'Hasil Akhir'];     // judul diubah (Customize)
                $curSum = q251_pick($sum['current_columns'], $keepSum);
                $body = q251_save_body($tpl, [
                    ['data_index' => ['summaries'], 'current_columns' => $curSum, 'available_columns' => array_merge($curSum, q251_pick($sum['current_columns'], ['invalid'])), 'total_field' => null, 'fields' => []],
                    ['data_index' => ['data'], 'current_columns' => $curDet, 'available_columns' => array_merge($curDet, q251_pick($det['current_columns'], ['scanTime'])), 'total_field' => null, 'fields' => []],
                ]);
                unset($body['footer']['labels']);
                $r = $t->call($s, 'POST', 'api/v5/setting/pdf-templates/update', $body);
                $t->status($r, 200, 'Simpan Customize (setting/pdf-templates/update)');

                // --- DB: baris sendiri berubah, nama tetap, tidak ada baris baru, template lain sama persis (C-9)
                $rows = q251_tpl_row($t);
                $t->eq(count($rows), 1, 'masih tepat satu baris sendiri');
                $t->eq($rows[0]['id_transaction_pdf_template'], $row0['id_transaction_pdf_template'], 'id baris sama');
                $t->eq($rows[0]['transaction_name'], Q251_MODULE, 'transaction_name baris sendiri tetap');
                $td = json_decode($rows[0]['transaction_data'], true);
                $t->eq(count($td['tables']), 2, 'DB: dua tabel tersimpan');
                $t->eq(array_column($td['tables'][1]['current_columns'], 'data_index'), [['code'], ['transactionTypeLabel'], ['resultLabel'], ['folderName']], 'DB: kolom Rincian tersimpan urut baru');
                $t->eq(json_encode(q251_tpl_other($t)), json_encode($otherBefore), 'C-9: template lain sama persis (count + checksum)');
                $t->eq(array_values(array_diff($t->db()->table('transaction_pdf_templates')->pluck('transaction_name')->all(), $namesBefore)), [], 'C-9: tidak ada baris template baru');

                // --- GET pdf DAN xls mengikuti tabel tersimpan
                $f = q251_fetch($t, $s, $id, 'en', 4, 4);
                $detCols = ['Document No.', 'Transaction Type', 'Final Result', 'Folder'];
                $sumCols = ['Total Documents', 'Verified', 'Not Found', 'Not Scanned'];
                $api = q251_api_rows($t, $s, $id, 4);
                $exp = array_map(function ($r) {
                    return [$r[0], $r[1], $r[4], $r[2]];
                }, q251_expect_rows($api, 'en'));
                foreach (['pp' => 'PDF', 'xp' => 'XLSX'] as $k => $nm) {
                    $t->true($f[$k]['found'], "$nm: judul tabel Summaries/Detail tetap ada");
                    $t->eq($f[$k]['sumcols'], $sumCols, "$nm: Ringkasan tanpa Invalid");
                    $t->eq($f[$k]['detcols'], $detCols, "$nm: Rincian tanpa Time, Result sebelum Folder, judul Final Result");
                }
                $t->eq($f['pp']['sumvals'], ['8.00', '4.00', '1.00', '4.00'], 'PDF: angka Ringkasan');
                $t->eq($f['xp']['sumvals'], [8.0, 4.0, 1.0, 4.0], 'XLSX: angka Ringkasan');
                $t->eq($f['pp']['rows'], $exp, 'PDF: baris Rincian urutan kolom baru');
                $t->eq($f['xp']['rows'], q251_xl_rows($exp), 'XLSX: baris Rincian urutan kolom baru');
                // bahasa id: judul id yang diedit
                $set(['role' => Q251_PRESET_FULL, 'lang' => 'ID']);
                $fi = q251_fetch($t, $s, $id, 'id', 4, 4);
                $t->eq($fi['xp']['detcols'], ['No. Dokumen', 'Tipe Transaksi', 'Hasil Akhir', 'Folder'], 'XLSX id: judul id hasil Customize');
                $t->eq($fi['pp']['detcols'], ['No. Dokumen', 'Tipe Transaksi', 'Hasil Akhir', 'Folder'], 'PDF id: judul id hasil Customize');
                $set(['role' => Q251_PRESET_FULL, 'lang' => 'EN']);

                // --- Customize memuat ulang tabel tersimpan
                $tpl2 = q251_tpl($t, $s);
                $t->eq(array_column(array_map(function ($c) {
                    return ['d' => $c['data_index'][0]];
                }, q251_tbl($tpl2, 'data')['current_columns']), 'd'), ['code', 'transactionTypeLabel', 'resultLabel', 'folderName'], 'transaction-templates: tabel tersimpan');

                // --- PUT display setting sesudahnya TIDAK mengubah kedua berkas
                $g = $t->call($s, 'GET', Q251_DS . Q251_MODULE);
                $cur = array_reverse($g[1]['result']['current_columns']);
                sleep(1);
                $t->status($t->call($s, 'PUT', Q251_DS . Q251_MODULE, ['current_columns' => $cur, 'summaries_columns' => array_reverse(json_decode(q251_cds_rows($t)[0]['summaries_columns'], true))]), 200, 'PUT display setting setelah Simpan');
                $f2 = q251_fetch($t, $s, $id, 'en', 4, 4);
                $t->eq($f2['pp']['detcols'], $detCols, 'PDF tidak berubah oleh PUT display setting');
                $t->eq($f2['xp']['detcols'], $detCols, 'XLSX tidak berubah oleh PUT display setting');
                $t->eq($f2['pp']['rows'], $exp, 'PDF baris tidak berubah');
                $t->eq($f2['xp']['sumcols'], $sumCols, 'XLSX Ringkasan tidak berubah');

                // --- kertas/ templat tersimpan di luar yang bisa dirender
                $over = q251_save_body($tpl, $body['tables'], ['paper_size' => 'Continuous-L']);
                unset($over['footer']['labels']);
                $t->status($t->call($s, 'POST', 'api/v5/setting/pdf-templates/update', $over), 200, 'Simpan paper_size Continuous-L (tidak divalidasi saat Simpan)');
                $rp = q251_file($t, $s, $id, 'pdf');
                $t->status([$rp[0]], 200, 'GET pdf dengan kertas tersimpan Continuous-L = dirender A4-P (bukan 500)');
                $m = q251_pdf($rp[1])['media'][0];
                $t->true(abs($m[0] - 595.28) < 0.1 && abs($m[1] - 841.89) < 0.1, 'kertas Continuous-L tersimpan -> A4-P (' . json_encode($m) . ')');
                $t->status([q251_file($t, $s, $id, 'xls')[0]], 200, 'GET xls dengan kertas tersimpan Continuous-L');
                $over = q251_save_body($tpl, $body['tables'], ['paper_size' => 'A4-L']);
                unset($over['footer']['labels']);
                $t->status($t->call($s, 'POST', 'api/v5/setting/pdf-templates/update', $over), 200, 'Simpan paper_size A4-L');
                $m = q251_pdf(q251_file($t, $s, $id, 'pdf')[1])['media'][0];
                $t->true($m[0] > $m[1], 'kertas tersimpan A4-L -> GET pdf lanskap');
                foreach (['template-1' => 400, 'report-template-1' => 200, 'report-template-2' => 200, 'report-template-6' => 200] as $tid => $http) {
                    $o = q251_save_body($tpl, $body['tables'], ['id_pdf_template' => $tid]);
                    unset($o['footer']['labels']);
                    $t->status($t->call($s, 'POST', 'api/v5/setting/pdf-templates/update', $o), 200, "Simpan id_pdf_template $tid");
                    $rp = q251_file($t, $s, $id, 'pdf');
                    q251_no500($t, [$rp[0]], "GET pdf template tersimpan $tid");
                    $t->eq($rp[0], $http, "GET pdf template tersimpan $tid");
                    if ($http === 400) {
                        $t->eq(q251_jerr($rp)['code'] ?? null, 'PDF404', 'kode PDF404');
                    }
                    $t->status([q251_file($t, $s, $id, 'xls')[0]], 200, "GET xls tidak terpengaruh template tersimpan $tid");
                }

                // --- Simpan memakai id terkunci pada baris sendiri: tanpa cek permission? (C-9, catatan "Di luar item")
                $o = q251_save_body($tpl, $body['tables']);
                unset($o['footer']['labels']);
                $s2 = q251_u2_session($t);
                try {
                    q251_u2_use($t, Q251_PRESET_R0);
                    $r0 = $t->call($s2, 'POST', 'api/v5/setting/pdf-templates/update', $o);
                    $t->note('C-9 (Di luar item): POST setting/pdf-templates/update oleh QA_USER2 preset R0 (tanpa Opname Document) = HTTP ' . $r0[0] . ' pada baris sendiri');
                    q251_no500($t, $r0, 'Simpan oleh R0');
                } finally {
                    q251_u2_out($t, $s2);
                }
                $t->eq(json_encode(q251_tpl_other($t)), json_encode($otherBefore), 'akhir: template lain sama persis (C-9)');
                $t->eq($t->db()->table('transaction_pdf_templates')->where('transaction_name', Q251_MODULE)->count(), 1, 'akhir: tepat satu baris sendiri');
            });
        },
    ],

    [
        'id'    => 'AC-15c',
        'title' => 'BR-4: kertas/header/footer/template Customize tersimpan hanya berlaku untuk PDF; Excel tetap header penuh, potret, kolom dari tabel tersimpan',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                $id = $ids['S1'];
                $tpl = q251_tpl($t, $s);
                $by = q251_uname($t);
                $raw = q251_confirmed($t, $id);
                $folderF = $t->db()->table('archives')->where('id_archive', $x['F'])->value('name');
                $head = q251_head_expect($t, 'en', $folderF . ' + 2 subfolder', $raw, $by, false);
                $x0 = q251_xlsx(q251_file($t, $s, $id, 'xls')[1]);
                $p0 = q251_pdf(q251_file($t, $s, $id, 'pdf')[1]);
                $t->eq(array_slice(q251_xl_parts($x0, 'Summaries', 'Detail')['head'], 0, 5), $head, 'sebelum Simpan: header Excel penuh');

                $hdr = $tpl['header'];
                $hdr['current_fields'] = ['companyName'];            // tanpa judul dan filter
                $ftr = $tpl['footer'];
                $ftr['current_fields'] = ['page_number'];
                unset($ftr['labels']);
                $body = q251_save_body($tpl, $tpl['tables'], ['header' => $hdr, 'footer' => $ftr, 'paper_size' => 'Legal-L', 'id_pdf_template' => 'report-template-1']);
                $t->status($t->call($s, 'POST', 'api/v5/setting/pdf-templates/update', $body), 200, 'Simpan header/footer/kertas/template');

                // PDF mengikuti: kertas Legal lanskap, tanpa baris Folder:/Opname Date:, template-1
                $rp = q251_file($t, $s, $id, 'pdf');
                $t->status([$rp[0]], 200, 'GET pdf template tersimpan baru');
                $p = q251_pdf($rp[1]);
                $m = $p['media'][0];
                $t->true($m[0] > $m[1] && abs($m[0] - 1008.0) < 1.5, 'PDF: Legal lanskap (' . json_encode($m) . ')');
                $txt = implode("\n", $p['texts'][0]);
                $t->true(strpos($txt, 'Folder:') === false && strpos($txt, 'Opname Date:') === false, 'PDF: header tanpa filter (Customize berlaku)');
                // Excel TIDAK mengikuti: header penuh, potret, tabel sama
                $rx = q251_file($t, $s, $id, 'xls');
                $t->status([$rx[0]], 200, 'GET xls');
                $x1 = q251_xlsx($rx[1]);
                $parts = q251_xl_parts($x1, 'Summaries', 'Detail');
                $t->eq(array_slice($parts['head'], 0, 5), $head, 'Excel: header penuh walau header Customize disederhanakan');
                $zip = new \ZipArchive();
                $tmp = q251_tmpdir() . '/o-' . uniqid() . '.xlsx';
                file_put_contents($tmp, $rx[1]);
                $zip->open($tmp);
                $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
                $zip->close();
                @unlink($tmp);
                $t->true(strpos($sheet, 'orientation="portrait"') !== false && strpos($sheet, 'orientation="landscape"') === false, 'Excel: kertas potret (kertas Customize tidak berlaku)');
                $t->eq($parts['detcols'], ['Document No.', 'Transaction Type', 'Folder', 'Time', 'Result'], 'Excel: kolom Rincian (tabel tersimpan = default)');
            });
        },
    ],
];
