<?php
/**
 * ED-1251 AC-13 - route select template laporan documentArchiveOpnameResult (subtask ED-1258). Tanpa sesi opname.
 * Harus jalan sebelum AC lain bila baris cds/template belum ada (membuktikan "dibuat bila belum ada"); bila sudah ada dan
 * masih default (tables kosong, tanpa perubahan), baris itu dibuang dulu (baris hasil buatan aplikasi sendiri) lalu dibuktikan ulang.
 */
require_once __DIR__ . '/qa_check.php';

if (!function_exists('q251_exp_summaries')) {
    function q251_exp_summaries()
    {
        return [
            ['totalDocuments', 'number', 'Total Documents', 'Total Dokumen'],
            ['verified', 'number', 'Verified', 'Terverifikasi'],
            ['notFound', 'number', 'Not Found', 'Tidak Ditemukan'],
            ['invalid', 'number', 'Invalid', 'Tidak Valid'],
            ['unscanned', 'number', 'Not Scanned', 'Belum Discan'],
        ];
    }

    function q251_exp_detail()
    {
        return [
            ['code', 'string', 'Document No.', 'No. Dokumen'],
            ['transactionTypeLabel', 'string', 'Transaction Type', 'Tipe Transaksi'],
            ['folderName', 'string', 'Folder', 'Folder'],
            ['scanTime', 'string', 'Time', 'Waktu'],
            ['resultLabel', 'string', 'Result', 'Hasil'],
        ];
    }

    /** kolom [data_index, type, en, id] dari list kolom respons/DB (data_index bisa string atau [string]) */
    function q251_cols_of(array $cols)
    {
        return array_map(function ($c) {
            $di = $c['data_index'];

            return [is_array($di) ? implode('.', $di) : $di, $c['type'], $c['title']['en'] ?? null, $c['title']['id'] ?? null];
        }, $cols);
    }
}

return [
    [
        'id'    => 'AC-13',
        'title' => 'select template laporan: transaction-templates (baris dibuat dari config), header/footer/pdf-templates hanya is_report=1, selected-*, paper-sizes',
        'run'   => function ($t) {
            $s = $t->session();
            $c = $t->db();
            q251_with_config($t, function ($snap) use ($t, $s, $c) {
                // --- pra-syarat: baris belum ada (bukti "dibuat bila belum ada"); baris default buatan aplikasi dibuang dulu
                $tplRows = $snap['tpl'];
                $cdsRows = $snap['cds'];
                $deletable = true;
                foreach ($tplRows as $r) {
                    $d = json_decode($r['transaction_data'], true);
                    $deletable = $deletable && is_array($d) && ($d['tables'] ?? null) === [];
                }
                if ($tplRows && !$deletable) {
                    $t->note('baris template sudah berisi tabel tersimpan: pembuatan baris tidak dibuktikan ulang');
                } else {
                    q251_w($t, function ($cn) {
                        $cn->table('transaction_pdf_templates')->where('transaction_name', Q251_MODULE)->delete();
                        $cn->table('column_display_settings')->where('module_name', Q251_MODULE)->delete();
                    });
                    $t->eq(count(q251_tpl_row($t)), 0, 'pra-syarat: baris template belum ada');
                    $t->eq(count(q251_cds_rows($t)), 0, 'pra-syarat: baris cds belum ada');
                }
                $created = count(q251_tpl_row($t)) === 0;

                $r = q251_sget($t, $s, 'transaction-templates');
                $t->status($r, 200, 'transaction-templates');
                $res = $r[1]['result'] ?? [];
                $t->eq($res['transaction_name'] ?? null, 'documentArchiveOpnameResult', 'transaction_name');
                $t->eq($res['id_pdf_template'] ?? null, 'report-template-6', 'id_pdf_template');
                $t->eq($res['id_header_pdf_template'] ?? null, 'report-header-1', 'id_header_pdf_template');
                $t->eq($res['id_footer_pdf_template'] ?? null, 'report-footer-1', 'id_footer_pdf_template');
                $t->eq($res['paper_size'] ?? null, 'A4-P', 'paper_size');
                $t->true(!empty($res['id_transaction_pdf_template']), 'id_transaction_pdf_template terisi (panggilan pertama baris baru juga membawa id)');
                $t->eq($res['header']['current_fields'] ?? null, ['companyName', 'reportName', 'reportFilter'], 'header.current_fields');
                $t->eq($res['header']['repeat'] ?? null, true, 'header.repeat');
                $t->eq($res['footer']['current_fields'] ?? null, ['timestamp', 'page_number', 'printed_by'], 'footer.current_fields');
                $t->true(isset($res['footer']['labels']) && is_array($res['footer']['labels']), 'footer.labels (dari footer-nya)');
                $t->eq($res['available_labels'] ?? null, [], 'available_labels');
                $t->eq($res['current_labels'] ?? null, [], 'current_labels');
                $t->eq($res['signatures'] ?? null, [], 'signatures');
                $t->true(in_array($res['language'] ?? null, ['en', 'id'], true), 'language en/id');

                $tables = $res['tables'] ?? [];
                $t->eq(count($tables), 2, 'tables: 2 tabel');
                $t->eq($tables[0]['data_index'] ?? null, ['summaries'], 'tabel 0 data_index');
                $t->eq($tables[0]['name'] ?? null, ['en' => 'Summaries', 'id' => 'Ringkasan'], 'tabel 0 name');
                $t->eq($tables[1]['data_index'] ?? null, ['data'], 'tabel 1 data_index');
                $t->eq($tables[1]['name'] ?? null, ['en' => 'Detail', 'id' => 'Rincian'], 'tabel 1 name');
                $t->eq(q251_cols_of($tables[0]['current_columns'] ?? []), q251_exp_summaries(), 'Ringkasan current_columns = summaries_columns kontrak §6');
                $t->eq(q251_cols_of($tables[0]['available_columns'] ?? []), q251_exp_summaries(), 'Ringkasan available_columns');
                $t->eq(q251_cols_of($tables[1]['current_columns'] ?? []), q251_exp_detail(), 'Rincian current_columns kontrak §6');
                $t->eq(q251_cols_of($tables[1]['available_columns'] ?? []), q251_exp_detail(), 'Rincian available_columns');
                foreach ($tables[0]['current_columns'] as $col) {
                    $t->eq($col['class'] ?? null, 'align-right', 'kolom number rata kanan: ' . $col['data_index'][0]);
                }
                foreach ($tables[1]['current_columns'] as $col) {
                    $t->eq($col['class'] ?? null, 'align-left', 'kolom string rata kiri: ' . $col['data_index'][0]);
                }

                // --- DB: baris template dibuat dari config, baris cds dibuat dari config (DB = sumber kebenaran)
                $tpl = q251_tpl_row($t);
                $t->eq(count($tpl), 1, 'tepat satu baris transaction_pdf_templates documentArchiveOpnameResult');
                $t->eq($tpl[0]['id_pdf_template'], 'report-template-6', 'DB id_pdf_template');
                $t->eq($tpl[0]['id_header_pdf_template'], 'report-header-1', 'DB header');
                $t->eq($tpl[0]['id_footer_pdf_template'], 'report-footer-1', 'DB footer');
                $t->eq($tpl[0]['paper_size'], 'A4-P', 'DB paper_size');
                $t->eq((int) $res['id_transaction_pdf_template'], (int) $tpl[0]['id_transaction_pdf_template'], 'id di respons = id baris DB');
                $td = json_decode($tpl[0]['transaction_data'], true);
                $t->eq($td['tables'] ?? null, [], 'DB transaction_data.tables kosong (diisi server dari display setting)');
                $t->eq($td['header']['current_fields'] ?? null, ['companyName', 'reportName', 'reportFilter'], 'DB header fields');
                $cds = q251_cds_rows($t);
                $t->eq(count($cds), 1, 'tepat satu baris column_display_settings documentArchiveOpnameResult');
                $t->eq(q251_cols_of(json_decode($cds[0]['current_columns'], true)), q251_exp_detail(), 'DB cds current_columns');
                $t->eq(q251_cols_of(json_decode($cds[0]['available_columns'], true)), q251_exp_detail(), 'DB cds available_columns');
                $t->eq(q251_cols_of(json_decode($cds[0]['summaries_columns'], true)), q251_exp_summaries(), 'DB cds summaries_columns');
                if ($created) {
                    $t->note('baris template + cds dibuat dari config oleh panggilan pertama (dibuktikan)');
                }

                // --- panggilan kedua: tidak membuat baris lagi
                $r2 = q251_sget($t, $s, 'transaction-templates');
                $t->status($r2, 200, 'transaction-templates ke-2');
                $t->eq(count(q251_tpl_row($t)), 1, 'tidak ada baris template ganda');
                $t->eq(count(q251_cds_rows($t)), 1, 'tidak ada baris cds ganda');
                $t->eq($r2[1]['result']['tables'], $res['tables'], 'tabel sama pada panggilan ke-2');

                // --- header/footer/pdf-templates hanya is_report = 1
                $db = $t->db();
                $hdr = $db->table('header_pdf_templates')->where('is_report', 1)->pluck('id_header_pdf_template')->all();
                $ftr = $db->table('footer_pdf_templates')->where('is_report', 1)->pluck('id_footer_pdf_template')->all();
                $pdf = $db->table('pdf_templates')->where('is_report', 1)->pluck('id_pdf_template')->all();
                $vals = function ($r) {
                    return array_column($r[1]['result']['options'] ?? [], 'value');
                };
                $h = q251_sget($t, $s, 'header-templates');
                $t->status($h, 200, 'header-templates');
                $t->eq($vals($h), $hdr, 'header-templates = is_report=1 saja');
                $f = q251_sget($t, $s, 'footer-templates');
                $t->status($f, 200, 'footer-templates');
                $t->eq($vals($f), $ftr, 'footer-templates = is_report=1 saja');
                $p = q251_sget($t, $s, 'pdf-templates');
                $t->status($p, 200, 'pdf-templates');
                $t->eq($vals($p), $pdf, 'pdf-templates = is_report=1 saja (report-template-1/2/6)');
                $t->true($h[1]['result']['default'] === null && array_key_exists('default', $h[1]['result']), 'header-templates default null');
                // search (pada label pdf_template_name, bukan id)
                $label = $db->table('pdf_templates')->where('id_pdf_template', 'report-template-6')->value('pdf_template_name');
                $ps = q251_sget($t, $s, 'pdf-templates', ['search' => $label]);
                $t->status($ps, 200, 'pdf-templates search');
                $t->true(in_array('report-template-6', $vals($ps), true) && count(array_diff($vals($ps), $pdf)) === 0, 'pdf-templates search label ' . $label . ': memuat report-template-6, hanya is_report=1');

                // --- selected-*
                $sh = q251_sget($t, $s, 'selected-header-templates', ['id_header_pdf_template' => 'report-header-1']);
                $t->status($sh, 200, 'selected-header-templates');
                $t->eq($sh[1]['result']['id_header_pdf_template'] ?? null, 'report-header-1', 'selected header id');
                $t->true(isset($sh[1]['result']['available_fields']) || isset($sh[1]['result']['header_data']['available_fields']), 'selected header memuat available_fields');
                $sf = q251_sget($t, $s, 'selected-footer-templates', ['id_footer_pdf_template' => 'report-footer-1']);
                $t->status($sf, 200, 'selected-footer-templates');
                $t->eq($sf[1]['result']['id_footer_pdf_template'] ?? null, 'report-footer-1', 'selected footer id');
                $sp = q251_sget($t, $s, 'selected-pdf-templates', ['id_pdf_template' => 'report-template-6']);
                $t->status($sp, 200, 'selected-pdf-templates');
                $t->eq($sp[1]['result']['id_pdf_template'] ?? null, 'report-template-6', 'selected pdf id');
                $t->true(!isset($sp[1]['result']['template_data']), 'template_data digabung ke atas');

                // --- paper-sizes
                $pa = q251_sget($t, $s, 'paper-sizes');
                $t->status($pa, 200, 'paper-sizes');
                $t->eq($vals($pa), ['A4', 'A5', 'Letter', 'Legal', 'Folio', 'Continuous'], 'paper-sizes = ukuran PdfTemplateService::getPaperSize (Continuous ada; pratinjau Continuous-* = 422)');
                $pa2 = q251_sget($t, $s, 'paper-sizes', ['search' => 'A4']);
                $t->status($pa2, 200, 'paper-sizes search A4');
                $t->eq($vals($pa2), ['A4'], 'paper-sizes search menyaring');

                // --- tanpa data sesi: respons select tidak memuat apa pun dari archive_opnames (hanya definisi)
                $blob = json_encode($r[1]);
                foreach ($db->table('archive_opnames')->pluck('id_archive_opname')->all() as $oid) {
                    $t->true(strpos($blob, (string) $oid) === false, 'respons template tidak memuat id sesi ' . $oid);
                }
            });
        },
    ],
];
