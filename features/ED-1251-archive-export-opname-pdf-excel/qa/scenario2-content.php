<?php
/**
 * ED-1251 AC-1..AC-7 (subtask ED-1259) - isi berkas export PDF & Excel satu sesi opname. Fixture sendiri per AC (q251_guard):
 * S1 = folder F + subfolder A (View user2) + B (izin folder, tanpa user2); S2 = folder G sepenuhnya terlihat user2.
 */
require_once __DIR__ . '/qa_check.php';

return [
    [
        'id'    => 'AC-1',
        'title' => 'QA_USER S1: GET pdf 200 application/pdf + Content-Disposition + expose header + %PDF-; GET xls 200 .xlsx berisi sheet1.xml',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                // waktu tetap: nama berkas dari teks DB (bukan jam mesin), bulan Des
                q251_set_opname($t, $ids['S1'], ['confirmed_at' => '2026-12-05 09:07:03']);
                $stem = 'Opname_Archive_05-Dec-2026_090703';
                foreach (['en' => 'EN', 'id' => 'ID'] as $lang => $ul) {
                    $set(['role' => Q251_PRESET_FULL, 'lang' => $ul]);
                    $rp = q251_file($t, $s, $ids['S1'], 'pdf');
                    q251_assert_file($t, $rp, 'pdf', $stem, "S1 pdf ($lang)");
                    $rx = q251_file($t, $s, $ids['S1'], 'xls');
                    q251_assert_file($t, $rx, 'xls', $stem, "S1 xls ($lang)");
                    $xl = q251_xlsx($rx[1]);
                    $t->eq($xl['sheet'], 'Report', "sheet bernama Report ($lang)");
                    $t->true(count($xl['rows']) > 5, "sheet1.xml berisi baris ($lang)");
                    $p = q251_pdf($rp[1]);
                    $t->eq($p['pages'], 1, "PDF S1 satu halaman ($lang)");
                    $t->true(abs($p['media'][0][0] - 595.28) < 0.1 && abs($p['media'][0][1] - 841.89) < 0.1, "PDF A4 potret (MediaBox $lang)");
                }
                // S2 (confirmed_at asli dari DB)
                $raw2 = q251_confirmed($t, $ids['S2']);
                $stem2 = q251_file_stem($raw2);
                q251_assert_file($t, q251_file($t, $s, $ids['S2'], 'pdf'), 'pdf', $stem2, 'S2 pdf');
                q251_assert_file($t, q251_file($t, $s, $ids['S2'], 'xls'), 'xls', $stem2, 'S2 xls');
                // header JSON tidak bocor: gagal = JSON, bukan berkas
                $r = $t->call($s, 'GET', Q251_BASE . '/opnames/' . Q251_RANDOM_ID . '/pdf');
                $t->status($r, 404, 'sesi tak ada = JSON 404');
            });
        },
    ],

    [
        'id'    => 'AC-2',
        'title' => '(en) XLSX & PDF memuat perusahaan (DB), Opname Archive, Folder: scope, Opname Date (teks DB), Opname By; PDF footer printed by = username',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                $by = q251_uname($t);
                $companies = $t->db()->table('companies')->pluck('company_name')->all();
                $t->eq(count($companies), 1, 'prasyarat: satu perusahaan di DB uji');
                $folderF = $t->db()->table('archives')->where('id_archive', $x['F'])->value('name');
                $folderG = $t->db()->table('archives')->where('id_archive', $x['G'])->value('name');
                $cases = [
                    ['S1', $folderF . ' + 2 subfolder'],  // folder F + 2 subfolder dicentang (BR-9 folder/N)
                    ['S2', $folderG],                     // folder G tanpa subfolder (BR-9 folder/0)
                ];
                foreach ($cases as $c) {
                    list($k, $scope) = $c;
                    $raw = q251_confirmed($t, $ids[$k]);
                    $f = q251_fetch($t, $s, $ids[$k]);
                    $exp = q251_head_expect($t, 'en', $scope, $raw, $by, false);
                    $t->eq(array_slice($f['xp']['head'], 0, 5), $exp, "$k XLSX header (perusahaan, judul, Folder, Opname Date, Opname By)");
                    $t->eq(count($f['xp']['head']), 5, "$k XLSX header tepat 5 baris (tanpa catatan: tak ada baris tersembunyi)");
                    $t->eq(array_slice($f['pp']['head'], 0, 5), $exp, "$k PDF header (perusahaan, judul, Folder, Opname Date, Opname By)");
                    // footer PDF: Printed at, Printed by <username>, Page 1 of 1
                    $foot = array_slice($f['pp']['head'], 5);
                    $t->true(count(array_filter($foot, function ($x) {
                        return preg_match('/^Printed at \d{1,2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2}$/', $x) === 1;
                    })) === 1, "$k PDF footer: Printed at <tanggal jam> (" . json_encode($foot) . ')');
                    $t->true(in_array('Printed by ' . $by, $foot, true), "$k PDF footer: Printed by $by");
                    $t->true(in_array('Page 1 of 1', $foot, true), "$k PDF footer: Page 1 of 1");
                    $t->true(!in_array('Some documents outside your access are not shown', $f['pp']['head'], true), "$k PDF tanpa catatan BR-18");
                    $t->eq($f['pp']['head'][1] ?? null, 'Opname Archive', "$k PDF judul laporan");
                }
                // sesi lain tidak bocor ke berkas: header S1 tidak memuat nama folder G
                $f1 = q251_fetch($t, $s, $ids['S1']);
                $t->true(strpos(json_encode($f1['pp']['head']), $folderG) === false, 'header S1 tidak memuat folder G');
            });
        },
    ],

    [
        'id'    => 'AC-2b',
        'title' => 'AC-2 (escape): nama folder berisi <i>, &, " tampil harafiah di header PDF (tanpa HTML terbaca) dan tidak merusak Excel/PDF',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                $uid = q251_uid($t);
                $name = Q251_PREFIX . 'H<i>x</i>&amp;"q' . substr(uniqid(), -4);
                $h = q251_folder($t, 'H', ['name' => $name]);
                list($d1, $n1) = q251_xdoc($t, 'h1', ['parent' => $h], 6);
                $id = q251_run($t, $s, $h, [], [$n1]);
                $raw = q251_confirmed($t, $id);
                $f = q251_fetch($t, $s, $id);
                $t->eq($f['pp']['head'][2] ?? null, 'Folder: ' . $name, 'PDF: Folder: <nama> harafiah (tag/entity tidak ditafsirkan)');
                $t->eq($f['pp']['head'][3] ?? null, 'Opname Date: ' . q251_date_text($raw, 'en'), 'PDF: Opname Date');
                $t->true(count($f['pp']['rows']) === 1 && $f['pp']['rows'][0][0] === $n1, 'PDF: satu baris Rincian benar');
                $t->true($f['xp']['found'] && count($f['xp']['rows']) === 1, 'XLSX: satu baris Rincian');
                $xl3 = $f['xp']['head'][2] ?? null;
                $t->note('Excel A3 = ' . json_encode($xl3, JSON_UNESCAPED_UNICODE) . ' (nama folder uji ' . json_encode($name) . '; header Excel teks apa adanya; sel tabel Excel memangkas tag HTML lewat trait XlsReport::extractText = mesin XLS bersama, bukan item ini)');
                $t->true(is_string($xl3) && strpos($xl3, 'Folder: ' . Q251_PREFIX . 'H') === 0, 'XLSX: header Folder terbaca');
            });
        },
    ],

    [
        'id'    => 'AC-3',
        'title' => 'Ringkasan: judul Summaries, 5 kolom urut summaries_columns, satu baris angka rekaman S1; PDF 2 desimal, XLSX numerik #,##0.00',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                $counts = q251_counts($t, $ids['S1']);
                $t->eq($counts, [8, 4, 1, 1, 4], 'angka rekaman S1 di DB (total 8, verified 4, not found 1, invalid 1, not scanned 4)');
                $f = q251_fetch($t, $s, $ids['S1']);
                $cols = ['Total Documents', 'Verified', 'Not Found', 'Invalid', 'Not Scanned'];
                $t->eq($f['pp']['sumcols'], $cols, 'PDF: 5 kolom Ringkasan urut summaries_columns');
                $t->eq($f['pp']['sumvals'], array_map('q251_num', $counts), 'PDF: angka rekaman "n.nn" (2 desimal)');
                $t->eq($f['xp']['sumcols'], $cols, 'XLSX: 5 kolom Ringkasan');
                $t->eq($f['xp']['sumvals'], array_map('floatval', $counts), 'XLSX: angka numerik rekaman');
                foreach (['A', 'B', 'C', 'D', 'E'] as $i => $col) {
                    $cell = $f['x']['rows'][$f['xp']['sumrow']][$col];
                    $t->true($cell['num'] === true, "XLSX sel $col numerik (bukan teks)");
                    $t->true(in_array($cell['fmt'], ['builtin:4', '#,##0.00'], true), "XLSX sel $col format #,##0.00 (didapat " . json_encode($cell['fmt']) . ')');
                }
                // S2: nilai lain, tetap rekaman
                $c2 = q251_counts($t, $ids['S2']);
                $f2 = q251_fetch($t, $s, $ids['S2']);
                $t->eq($f2['pp']['sumvals'], array_map('q251_num', $c2), 'S2 PDF: angka rekaman');
                $t->eq($f2['xp']['sumvals'], array_map('floatval', $c2), 'S2 XLSX: angka rekaman');
                // hanya SATU baris angka: tepat satu baris antara header kolom Ringkasan dan judul Rincian (baris kosong)
                $t->eq(count($f['pp']['sumvals']), 5, 'PDF: satu baris angka (5 sel)');
            });
        },
    ],

    [
        'id'    => 'AC-4',
        'title' => 'Rincian: judul Detail, 5 kolom, baris = gabungan semua halaman GET documents?result=all (urutan sama, BR-13)',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                $n = $x['n'];
                $f = q251_fetch($t, $s, $ids['S1']);
                $api = q251_api_rows($t, $s, $ids['S1'], 3); // 4 halaman x 3 baris
                $t->eq(count($api), 10, 'API documents: 10 baris (semua halaman)');
                $exp = q251_expect_rows($api, 'en');
                $t->eq($f['pp']['detcols'], ['Document No.', 'Transaction Type', 'Folder', 'Time', 'Result'], 'PDF: 5 kolom Rincian');
                $t->eq($f['xp']['detcols'], ['Document No.', 'Transaction Type', 'Folder', 'Time', 'Result'], 'XLSX: 5 kolom Rincian');
                $t->eq($f['pp']['rows'], $exp, 'PDF: baris Rincian = gabungan halaman API (isi + urutan)');
                $t->eq($f['xp']['rows'], q251_xl_rows($exp), 'XLSX: baris Rincian = gabungan halaman API (isi + urutan; sel dilewatkan mesin XLS bersama = tag HTML dipangkas)');
                // ekspektasi dari data yang dibuat sendiri (bukan dari API): urutan scan terbalik, lalu belum discan menurut kode
                $folderOf = function ($k) use ($t, $x) {
                    return $t->db()->table('archives')->where('id_archive', $x[$k])->value('name');
                };
                $explicit = [
                    [$x['invalid'], '—', '—', null, 'Invalid'],
                    [$n['dO1'], 'Sales Order', 'Outside opname scope', null, 'Not found'],
                    [$n['dB1'], 'Sales Return', $folderOf('B'), null, 'Verified'],
                    [$n['dA1'], 'Sales Invoice', $folderOf('A'), null, 'Verified'],
                    [$n['dF3'], '—', $folderOf('F'), null, 'Verified'],
                    [$n['dF1'], 'Sales Order', $folderOf('F'), null, 'Verified'],
                    [$n['dA2'], 'Sales Return', $folderOf('A'), '—', 'Not scanned'],
                    [$n['dB2'], 'Sales Order', $folderOf('B'), '—', 'Not scanned'],
                    [$n['dF2'], 'Delivery Order', $folderOf('F'), '—', 'Not scanned'],
                    [$n['dFx'], 'Sales Invoice', $folderOf('F'), '—', 'Not scanned'],
                ];
                foreach ($explicit as $i => $e) {
                    $got = $f['pp']['rows'][$i] ?? [];
                    $t->true(count($got) === 5, "PDF baris $i lengkap");
                    $t->eq([$got[0], $got[1], $got[2], $got[4]], [$e[0], $e[1], $e[2], $e[4]], "PDF baris $i kode/tipe/folder/hasil (data uji)");
                    if ($e[3] === null) {
                        $t->true(preg_match('/^\d{2}:\d{2}$/', $got[3]) === 1, "PDF baris $i Time = HH:mm (" . $got[3] . ')');
                        $t->eq($got[3], substr($api[$i]['scanned_at'], 11, 5), "PDF baris $i Time = HH:mm scanned_at");
                    } else {
                        $t->eq($got[3], $e[3], "PDF baris $i Time belum discan '—'");
                    }
                }
                // tidak ada paginasi: jumlah baris = result_counts.all
                $show = $t->call($s, 'GET', Q251_BASE . '/opnames/' . $ids['S1']);
                $t->eq(count($f['pp']['rows']), (int) $show[1]['result']['result_counts']['all'], 'PDF: jumlah baris = result_counts.all (tanpa paginasi)');
                $t->eq(count($f['xp']['rows']), (int) $show[1]['result']['result_counts']['all'], 'XLSX: jumlah baris = result_counts.all');
                // sel Time/kode di XLSX bertipe teks
                $first = $f['x']['rows'][$f['xp']['detfirst']];
                $t->true($first['A']['num'] === false && $first['D']['num'] === false, 'XLSX: kode dan Time bertipe teks');
            });
        },
    ],

    [
        'id'    => 'AC-5',
        'title' => 'users.language = id: judul/label/bulan/judul tabel & kolom/hasil/"Semua Arsip"/"Di luar scope opname" id di PDF & XLSX; dipulihkan',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                $by = q251_uname($t);
                $folderF = $t->db()->table('archives')->where('id_archive', $x['F'])->value('name');
                // sesi akar sisipan (BR-13 "Semua Arsip"): satu dokumen akar Verified, satu belum discan, satu Not found, satu Invalid
                list($dr1, $nr1) = q251_xdoc($t, 'dR1', [], 6);
                list($dr2, $nr2) = q251_xdoc($t, 'dR2', [], 7);
                $root = q251_insert_session($t, 'ROOT', ['scope_folder_count' => 3, 'total_documents' => 2, 'verified_count' => 1, 'not_found_count' => 1,
                    'invalid_count' => 1, 'unscanned_count' => 1, 'scanned_count' => 3, 'confirmed_at' => '2026-05-17 14:05:09']);
                q251_insert_rows($t, $root, [
                    [1, $dr1, null, $nr1, '2026-05-17 14:03:01.000100'],
                    [4, $dr2, null, $nr2, null],
                    [2, $x['dO1'], null, $x['n']['dO1'], '2026-05-17 14:03:05.000100'],
                    [3, null, null, 'QA251BULK-TIDAK-ADA', '2026-05-17 14:03:09.000100'],
                ]);
                $set(['role' => Q251_PRESET_FULL, 'lang' => 'ID']);
                $ti = q251_titles('id');
                // --- S1 (id)
                $raw = q251_confirmed($t, $ids['S1']);
                $f = q251_fetch($t, $s, $ids['S1'], 'id');
                $exp = q251_head_expect($t, 'id', $folderF . ' + 2 subfolder', $raw, $by, false);
                $t->eq(array_slice($f['xp']['head'], 0, 5), $exp, 'XLSX id: header');
                $t->eq(array_slice($f['pp']['head'], 0, 5), $exp, 'PDF id: header');
                foreach (['pp', 'xp'] as $k) {
                    $nm = $k === 'pp' ? 'PDF' : 'XLSX';
                    $t->eq($f[$k]['sumcols'], $ti['sumcols'], "$nm id: judul kolom Ringkasan");
                    $t->eq($f[$k]['detcols'], $ti['detcols'], "$nm id: judul kolom Rincian");
                }
                $api = q251_api_rows($t, $s, $ids['S1'], 4);
                $expRows = q251_expect_rows($api, 'id');
                $t->eq($f['pp']['rows'], $expRows, 'PDF id: baris (tipe/folder/hasil bahasa id) = API bahasa id');
                $t->eq($f['xp']['rows'], q251_xl_rows($expRows), 'XLSX id: baris = API bahasa id (mesin XLS bersama)');
                $results = array_unique(array_column($expRows, 4));
                sort($results);
                $t->eq($results, ['Belum discan', 'Terverifikasi', 'Tidak ditemukan', 'Tidak valid'], 'hasil dalam bahasa id (4 nilai)');
                $t->true(in_array('Di luar scope opname', array_column($expRows, 2), true), 'Folder Not found "Di luar scope opname"');
                $t->true(in_array('Delivery Order', array_column($expRows, 1), true) || count(array_filter(array_column($expRows, 1))) > 0, 'tipe transaksi berlabel');
                // --- sesi akar (id): Semua Arsip + scope root
                $f = q251_fetch($t, $s, $root, 'id');
                $t->eq(array_slice($f['pp']['head'], 0, 5), q251_head_expect($t, 'id', 'Semua Arsip · root + 3 subfolder', '2026-05-17 14:05:09', $by, false),
                    'PDF id akar: header, scope "Semua Arsip · root + 3 subfolder", bulan Mei');
                $t->eq(array_slice($f['xp']['head'], 0, 5), q251_head_expect($t, 'id', 'Semua Arsip · root + 3 subfolder', '2026-05-17 14:05:09', $by, false), 'XLSX id akar: header');
                $rootApi = q251_api_rows($t, $s, $root, 10);
                $exp = q251_expect_rows($rootApi, 'id');
                $t->true(in_array('Semua Arsip', array_column($exp, 2), true), 'baris akar: Folder "Semua Arsip" ada di ekspektasi');
                $t->eq($f['pp']['rows'], $exp, 'PDF id akar: baris');
                $t->eq($f['xp']['rows'], q251_xl_rows($exp), 'XLSX id akar: baris');
                // --- bulan id/en: Okt, Agu, Des, Mei di Opname Date + nama berkas
                foreach ([['2026-10-05 08:09:10', 'Okt', 'Oct'], ['2026-08-31 23:59:59', 'Agu', 'Aug'], ['2026-12-01 00:00:00', 'Des', 'Dec'], ['2026-05-02 12:30:45', 'Mei', 'May']] as $c) {
                    q251_set_opname($t, $ids['S2'], ['confirmed_at' => $c[0]]);
                    $rx = q251_file($t, $s, $ids['S2'], 'xls');
                    $t->status([$rx[0]], 200, 'xls ' . $c[1]);
                    $xl = q251_xlsx($rx[1]);
                    $dt = $xl['rows'][4]['A']['v'] ?? null;
                    $t->eq($dt, 'Tanggal Opname: ' . substr($c[0], 8, 2) . ' ' . $c[1] . ' 2026 ' . substr($c[0], 11, 5), 'XLSX id bulan ' . $c[1]);
                    $t->eq($rx[2]['content-disposition'] ?? null, 'attachment; filename="' . q251_file_stem($c[0]) . '.xlsx"', 'nama berkas bulan ' . $c[2] . ' (Inggris, bukan lokal)');
                    $rp = q251_file($t, $s, $ids['S2'], 'pdf');
                    $pp = q251_pdf_parts(q251_pdf($rp[1])['texts'][0], 'Ringkasan', 'Rincian', 5, 5);
                    $t->eq($pp['head'][3] ?? null, 'Tanggal Opname: ' . substr($c[0], 8, 2) . ' ' . $c[1] . ' 2026 ' . substr($c[0], 11, 5), 'PDF id bulan ' . $c[1]);
                }
                // bahasa en kembali (bulan en)
                $set(['role' => Q251_PRESET_FULL, 'lang' => 'EN']);
                $rx = q251_file($t, $s, $ids['S2'], 'xls');
                $t->eq(q251_xlsx($rx[1])['rows'][4]['A']['v'] ?? null, 'Opname Date: 02 May 2026 12:30', 'en: bulan May');
            });
            // pemulihan persis oleh q251_guard (bahasa QA_USER 'ID' asli)
        },
    ],

    [
        'id'    => 'AC-6',
        'title' => 'Tanpa warna FE: PDF hanya palet template (sel Result tanpa re f); XLSX sel data tanpa fill solid, font hitam/abu',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                $f = q251_fetch($t, $s, $ids['S1']);
                $t->true(count(array_unique(array_column($f['pp']['rows'], 4))) === 4, 'prasyarat: S1 memuat 4 jenis hasil (Verified, Not found, Invalid, Not scanned)');
                $p = $f['p'];
                $allowed = ['0.000 0.000 0.000', '1.000 1.000 1.000', '0.502 0.502 0.502', '0.569 0.580 0.643', '0.898 0.910 0.922', '0.937 0.949 0.961'];
                foreach (array_keys($p['colors']) as $c) {
                    $t->true(in_array($c, $allowed, true), "PDF warna $c ada di palet template (hitam/putih/abu #808080, #9194a4, #e5e8eb, #eff2f5)");
                    list($r, $g, $b) = array_map('floatval', explode(' ', $c));
                    $isGreen = $g > $r + 0.15 && $g > $b + 0.15;
                    $isRedOrange = $r > $g + 0.15 && $r > $b + 0.15;
                    $t->true(!$isGreen && !$isRedOrange, "PDF warna $c bukan hijau/oranye/merah");
                }
                // pengisi latar hanya baris judul kolom (5 + 5 sel #e5e8eb): sel Result/baris data tanpa "re f"
                $t->eq($p['fills'], ['0.898 0.910 0.922' => 10], 'PDF: latar hanya 10 sel judul kolom (#e5e8eb); tidak ada latar sel/baris data');
                // XLSX
                $xl = $f['x'];
                $head = [$f['xp']['sumrow'] - 1, $f['xp']['detfirst'] - 1];
                $dataRows = array_merge([$f['xp']['sumrow']], range($f['xp']['detfirst'], $f['xp']['detfirst'] + count($f['xp']['rows']) - 1));
                foreach ($dataRows as $r) {
                    foreach ($xl['rows'][$r] as $col => $cell) {
                        if ($cell['v'] === null) {
                            continue;
                        }
                        $t->true($cell['fill'] === null, "XLSX sel $col$r (data) tanpa fill solid");
                        $t->true(in_array($cell['color'], ['FF000000', 'FF7A7A7A', null], true), "XLSX sel $col$r font hitam/abu (" . json_encode($cell['color']) . ')');
                    }
                }
                // judul kolom gaya PO: #7A7A7A teks putih
                foreach ($head as $r) {
                    $c = $xl['rows'][$r]['A'];
                    $t->eq([$c['fill'], $c['color']], ['FF7A7A7A', 'FFFFFFFF'], "XLSX baris judul kolom $r: latar #7A7A7A teks putih (gaya PO)");
                }
                // tidak ada fill di luar judul kolom
                foreach ($xl['rows'] as $r => $cols) {
                    foreach ($cols as $col => $cell) {
                        if ($cell['fill'] !== null) {
                            $t->true(in_array($r, $head, true), "XLSX fill hanya di baris judul kolom (sel $col$r fill {$cell['fill']})");
                        }
                    }
                }
            });
        },
    ],

    [
        'id'    => 'AC-7',
        'title' => 'Catatan BR-18: user2 S1 ada; user2 S2 tidak; QA_USER S1 tidak; bypass (role 1) tidak; Ringkasan user2 = QA_USER; Rincian user2 = documents user2',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                $note = 'Some documents outside your access are not shown';
                $s2 = q251_u2_session($t);
                try {
                    $by = q251_uname($t);
                    $folderF = $t->db()->table('archives')->where('id_archive', $x['F'])->value('name');
                    $raw = q251_confirmed($t, $ids['S1']);
                    $t->eq(q251_u2_name($t), 'adi', 'prasyarat: QA_USER2 = user2 sungguhan (adi)');

                    // --- QA_USER (FULL): tidak ada yang tersembunyi
                    $fu = q251_fetch($t, $s, $ids['S1']);
                    $t->true(!in_array($note, $fu['pp']['head'], true), 'QA_USER S1 PDF: tanpa catatan');
                    $t->true(!in_array($note, $fu['xp']['head'], true), 'QA_USER S1 XLSX: tanpa catatan');
                    $t->eq(count($fu['pp']['rows']), 10, 'QA_USER S1: 10 baris Rincian');

                    // --- user2 S1: catatan ada (baris 6 header), baris B tersembunyi
                    $f2 = q251_fetch($t, $s2, $ids['S1']);
                    $exp = q251_head_expect($t, 'en', $folderF . ' + 2 subfolder', $raw, $by, true);
                    $t->eq(array_slice($f2['pp']['head'], 0, 6), $exp, 'user2 S1 PDF: header + catatan BR-18 (baris ke-6)');
                    $t->eq(array_slice($f2['xp']['head'], 0, 6), $exp, 'user2 S1 XLSX: header + catatan BR-18 (A6)');
                    $api2 = q251_api_rows($t, $s2, $ids['S1'], 3);
                    $t->eq($f2['pp']['rows'], q251_expect_rows($api2, 'en'), 'user2 S1 PDF: Rincian = documents?result=all user2');
                    $t->eq($f2['xp']['rows'], q251_xl_rows(q251_expect_rows($api2, 'en')), 'user2 S1 XLSX: Rincian = documents?result=all user2 (mesin XLS bersama)');
                    $codes2 = array_column($f2['pp']['rows'], 0);
                    $t->true(!in_array($x['n']['dB1'], $codes2, true) && !in_array($x['n']['dB2'], $codes2, true), 'user2: baris folder B (izin folder tanpa user2) tidak ada');
                    $t->eq(count($codes2), 8, 'user2 S1: 8 baris (10 - 2 di folder B)');
                    // oracle independen (SQL mentah + PHP)
                    $o = q251_summary_oracle($t, $ids['S1'], q251_uu($t, 'user2'));
                    $t->eq($o['hidden_docs'], 2, 'oracle: 2 baris tersembunyi bagi user2');
                    $t->eq(array_sum($o['tally']), count($codes2), 'oracle: jumlah baris terlihat user2 = baris Rincian export');
                    // Ringkasan user2 = angka rekaman = QA_USER
                    $t->eq($f2['pp']['sumvals'], $fu['pp']['sumvals'], 'Ringkasan user2 = Ringkasan QA_USER (PDF)');
                    $t->eq($f2['xp']['sumvals'], $fu['xp']['sumvals'], 'Ringkasan user2 = Ringkasan QA_USER (XLSX)');
                    $t->eq($f2['pp']['sumvals'], array_map('q251_num', q251_counts($t, $ids['S1'])), 'Ringkasan user2 = angka rekaman DB');

                    // --- user2 S2: sepenuhnya terlihat -> tanpa catatan
                    $g2 = q251_fetch($t, $s2, $ids['S2']);
                    $t->true(!in_array($note, $g2['pp']['head'], true) && !in_array($note, $g2['xp']['head'], true), 'user2 S2: tanpa catatan');
                    $t->eq(count($g2['pp']['rows']), 2, 'user2 S2: 2 baris');
                } finally {
                    q251_u2_out($t, $s2);
                }

                // --- bypass (superadmin 1): QA_USER sementara role 1
                $set(['role' => 1, 'lang' => 'EN']);
                $fb = q251_fetch($t, $s, $ids['S1']);
                $t->true(!in_array($note, $fb['pp']['head'], true) && !in_array($note, $fb['xp']['head'], true), 'bypass (role 1) S1: tanpa catatan');
                $t->eq(count($fb['pp']['rows']), 10, 'bypass: 10 baris (semua)');
            });
        },
    ],

    [
        'id'    => 'AC-7b',
        'title' => 'AC-7 (lokasi): dokumen di lokasi lain (JOG) tersembunyi bagi user2 (SMR) -> catatan BR-18 ada, baris tersaring; QA_USER (semua lokasi) melihat semua',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                $note = 'Some documents outside your access are not shown';
                $f = q251_folder($t, 'LOC');
                $out = q251_folder($t, 'LOCOUT');
                $d1 = q251_doc($t, 'lokSMR', ['parent' => $f], 6);                                  // semua lokasi
                $d2 = q251_doc($t, 'lokJOG', ['parent' => $f, 'all' => 0, 'locs' => ['JOG']], 7);   // hanya JOG: tersembunyi bagi user2
                $d3 = q251_doc($t, 'lokJOGnf', ['parent' => $out, 'all' => 0, 'locs' => ['JOG']], 8); // Not found di JOG: tersembunyi bagi user2
                $d4 = q251_doc($t, 'lokSMRnf', ['parent' => $out], 9);                               // Not found terlihat
                $n = function ($id) use ($t) {
                    return q251_n($t, $id);
                };
                $id = q251_run($t, $s, $f, [], [$n($d1), $n($d2), $n($d3), $n($d4), Q251_PREFIX . 'TIDAK-ADA-' . substr(uniqid(), -5)]);
                $s2 = q251_u2_session($t);
                try {
                    $fu = q251_fetch($t, $s, $id);
                    $t->eq(count($fu['pp']['rows']), 5, 'QA_USER: 5 baris (2 Verified + 2 Not found + 1 Invalid)');
                    $t->true(!in_array($note, $fu['pp']['head'], true), 'QA_USER: tanpa catatan');
                    $f2 = q251_fetch($t, $s2, $id);
                    $codes2 = array_column($f2['pp']['rows'], 0);
                    $t->true(in_array($n($d1), $codes2, true) && in_array($n($d4), $codes2, true), 'user2: dokumen semua lokasi + Not found terlihat tampil');
                    $t->true(!in_array($n($d2), $codes2, true) && !in_array($n($d3), $codes2, true), 'user2: dokumen JOG (sesi dan Not found) tersembunyi');
                    $t->true(in_array($note, $f2['pp']['head'], true) && in_array($note, $f2['xp']['head'], true), 'user2: catatan BR-18 di PDF dan XLSX (tersembunyi karena lokasi)');
                    $api2 = q251_api_rows($t, $s2, $id, 2);
                    $t->eq($f2['pp']['rows'], q251_expect_rows($api2, 'en'), 'user2 PDF = documents?result=all user2');
                    $t->eq($f2['xp']['rows'], q251_xl_rows(q251_expect_rows($api2, 'en')), 'user2 XLSX = documents?result=all user2');
                    $o = q251_summary_oracle($t, $id, q251_uu($t, 'user2'));
                    $t->eq(array_sum($o['tally']), count($codes2), 'oracle independen: jumlah baris terlihat user2 = baris export');
                    $t->eq($o['hidden_docs'], 2, 'oracle: 2 baris tersembunyi (lokasi)');
                    $t->eq($f2['pp']['sumvals'], $fu['pp']['sumvals'], 'Ringkasan user2 = QA_USER (angka rekaman)');
                } finally {
                    q251_u2_out($t, $s2);
                }
            });
        },
    ],
];
