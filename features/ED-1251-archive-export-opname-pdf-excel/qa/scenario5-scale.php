<?php
/**
 * ED-1251 AC-10 - sesi besar S-BULK 10.000 baris (QA251BULK%, disisipkan langsung lalu dibuang oleh q251_guard/q251_bulk_purge):
 * PDF (template default) dan XLSX 200 < 60 s, PDF memuat baris pertama dan terakhir.
 */
require_once __DIR__ . '/qa_check.php';

return [
    [
        'id'    => 'AC-10',
        'title' => 'S-BULK 10.000 baris: PDF (template default) dan XLSX 200 (< 60 s), PDF memuat baris pertama & terakhir; Ringkasan ribuan "12,345.00"',
        'run'   => function ($t) {
            ini_set('memory_limit', '2G');
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                $N = 10000;
                $base = strtotime('2026-10-24 08:00:00');
                $rows = [];
                for ($i = 0; $i < $N; $i++) {
                    $rows[] = [3, null, null, sprintf('QA251BULK-%07d', $i), date('Y-m-d H:i:s', $base + intdiv($i, 100)) . '.' . sprintf('%06d', ($i % 100) * 10000)];
                }
                $id = q251_insert_session($t, 'BULK', ['scope_folder_count' => 0, 'total_documents' => 12345, 'verified_count' => 1000, 'not_found_count' => 0,
                    'invalid_count' => 10000, 'unscanned_count' => 11345, 'scanned_count' => 11000, 'confirmed_at' => '2026-10-24 09:30:00']);
                q251_insert_rows($t, $id, $rows);
                $t->eq($t->db()->table('archive_opname_documents')->where('id_archive_opname', $id)->count(), $N, 'prasyarat: 10.000 baris tersisip');
                $api = $t->call($s, 'GET', Q251_BASE . '/opnames/' . $id . '/documents?result=all&pagination=5');
                $t->eq($api[1]['result']['total'] ?? null, $N, 'API documents: total 10.000 (sesi terlihat pemilik)');
                $first = $api[1]['result']['data'][0]['code'];
                $t->eq($first, sprintf('QA251BULK-%07d', $N - 1), 'baris pertama menurut API = scan terakhir');
                $lastCode = 'QA251BULK-0000000';

                // --- PDF default
                $t0 = microtime(true);
                $rp = q251_file($t, $s, $id, 'pdf');
                $secPdf = round(microtime(true) - $t0, 1);
                $t->status([$rp[0]], 200, 'PDF 10.000 baris');
                $t->true($secPdf < 60, "PDF < 60 s ($secPdf s)");
                $t->eq($rp[2]['content-type'] ?? null, 'application/pdf', 'PDF content-type');
                $t->true(strpos($rp[1], '%PDF-') === 0, 'PDF diawali %PDF-');
                $t0 = microtime(true);
                $p = q251_pdf($rp[1]);
                $t->note(sprintf('PDF 10.000 baris: %.1f s, %d byte, %d halaman (parse %.1f s)', $secPdf, strlen($rp[1]), $p['pages'], microtime(true) - $t0));
                $codes = [];
                foreach ($p['texts'] as $pageTexts) {
                    foreach ($pageTexts as $tx) {
                        if (preg_match('/^QA251BULK-\d{7}$/', $tx)) {
                            $codes[] = $tx;
                        }
                    }
                }
                $t->eq(count($codes), $N, 'PDF memuat 10.000 kode baris');
                $t->eq($codes[0], $first, 'PDF baris pertama');
                $t->eq($codes[$N - 1], $lastCode, 'PDF baris terakhir');
                $t->eq(count(array_unique($codes)), $N, 'PDF: kode unik (tanpa baris ganda/hilang)');
                $expected = [];
                for ($i = $N - 1; $i >= 0; $i--) {
                    $expected[] = sprintf('QA251BULK-%07d', $i);
                }
                $t->true($codes === $expected, 'PDF: urutan = GET documents (scan terbaru dulu)');
                $t->true($p['pages'] > 100, 'PDF: ratusan halaman (' . $p['pages'] . ')');
                $pp = q251_pdf_parts($p['texts'][0], 'Summaries', 'Detail', 5, 5);
                $t->eq($pp['sumvals'], ['12,345.00', '1,000.00', '0.00', '10,000.00', '11,345.00'], 'PDF Ringkasan: pemisah ribuan + 2 desimal');
                $t->eq($pp['detcols'], ['Document No.', 'Transaction Type', 'Folder', 'Time', 'Result'], 'PDF Rincian: 5 kolom');
                $t->true(count($pp['rows']) > 0 && $pp['rows'][0][4] === 'Invalid' && $pp['rows'][0][1] === '—' && $pp['rows'][0][2] === '—', 'PDF baris Invalid: tipe/folder "—"');

                // --- XLSX
                $t0 = microtime(true);
                $rx = q251_file($t, $s, $id, 'xls');
                $secX = round(microtime(true) - $t0, 1);
                $t->status([$rx[0]], 200, 'XLSX 10.000 baris');
                $t->true($secX < 60, "XLSX < 60 s ($secX s)");
                $xl = q251_xlsx($rx[1]);
                $xp = q251_xl_parts($xl, 'Summaries', 'Detail');
                $t->note(sprintf('XLSX 10.000 baris: %.1f s, %d byte', $secX, strlen($rx[1])));
                $t->eq(count($xp['rows']), $N, 'XLSX: 10.000 baris Rincian');
                $t->eq($xp['rows'][0][0], $first, 'XLSX baris pertama');
                $t->eq($xp['rows'][$N - 1][0], $lastCode, 'XLSX baris terakhir');
                $t->eq($xp['sumvals'], [12345.0, 1000.0, 0.0, 10000.0, 11345.0], 'XLSX Ringkasan numerik');
            });
        },
    ],
];
