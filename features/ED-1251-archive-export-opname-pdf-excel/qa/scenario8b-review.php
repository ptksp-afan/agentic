<?php
/**
 * ED-1251 review 2026-10-26 (QA BE r3): R-3 footer di SEMUA halaman, R-4 kertas Continuous, R-5 perubahan developer ikut + regresi PDF laporan lain.
 *   AC-R3a  sesi nyata developer (...033097, ...474864, + ...422239): HTTP QA_USER (404 bila tak terlihat) lalu render DI DALAM PROSES sebagai pemilik,
 *           BACA SAJA (dev_render.php: sesi MySQL read-only); footer di tiap halaman, tanpa tumpang tindih; data developer tak berubah
 *   AC-R3b  sesi QA 6..1000 baris, potret & lanskap & kertas lain: footer/header di tiap halaman, jumlah baris utuh, tanpa tumpang tindih
 *   AC-R4   Continuous-P/-L: pratinjau dan GET pdf tersimpan = 200, MediaBox = ukuran Continuous PdfTemplateService; bentuk salah tetap 422 paper_size
 *   AC-R5   info($paper) hilang, hunk developer ada; PDF laporan lain (VAT In/Out, PB1, WHT, AP Summary = report-template-6; VAT Summary/AP Due = -1;
 *           Journal = -2, semuanya report-header-1) 200, header + footer di tiap halaman, header tidak menimpa baris pertama
 */
require_once __DIR__ . '/qa_review.php';

/** Catat satu baris hasil ke work/ed1251/r3-results.json (bukti laporan). */
function q251r_log($key, array $row)
{
    $f = QA_AGENTIC . '/work/ed1251/r3-results.json';
    $all = is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    $all[$key][] = $row;
    @mkdir(dirname($f), 0775, true);
    file_put_contents($f, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/** Ringkasan satu hasil q251r_assert untuk laporan. */
function q251r_summ($label, array $a)
{
    $g = $a['geom'] ?? null;
    $gf = $g ? array_filter(array_column($g, 'gap_footer'), function ($v) {
        return $v !== null;
    }) : [];
    $gh = $g ? array_filter(array_column($g, 'gap_header'), function ($v) {
        return $v !== null;
    }) : [];

    return ['label' => $label, 'pages' => $a['pages'], 'footer_pages' => $a['footer_ok'], 'min_gap_footer' => $gf ? min($gf) : null, 'min_gap_header' => $gh ? min($gh) : null,
        'media' => $a['parse']['media'][0] ?? null];
}

/** Ukuran Continuous (pt) dari PdfTemplateService::getPaperFormat: [lebar, tinggi] untuk P / L. */
function q251r_cont($t, $o)
{
    $mm = $t->probe(function () use ($o) {
        return (new \Modules\V5\Http\Services\Setup\Setting\PdfTemplateService)->getPaperFormat('Continuous-' . $o);
    });

    return [q251r_pt($mm[0]), q251r_pt($mm[1])];
}

/** Sesi sisipan N baris Invalid (kode QA251BULK-0000000..N-1), terlihat pemilik QA_USER. */
function q251r_session($t, $n, $key)
{
    $base = strtotime('2026-10-24 08:00:00');
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $rows[] = [3, null, null, sprintf('QA251BULK-%07d', $i), date('Y-m-d H:i:s', $base + intdiv($i, 100)) . '.' . sprintf('%06d', ($i % 100) * 10000)];
    }
    $id = q251_insert_session($t, $key, ['scope_folder_count' => 0, 'total_documents' => $n, 'verified_count' => 0, 'not_found_count' => 0, 'invalid_count' => $n,
        'unscanned_count' => 0, 'scanned_count' => $n, 'confirmed_at' => '2026-10-24 09:30:00']);
    q251_insert_rows($t, $id, $rows);
    $t->eq($t->db()->table('archive_opname_documents')->where('id_archive_opname', $id)->count(), $n, "prasyarat: sesi $key $n baris tersisip");

    return $id;
}

return [
    [
        'id'    => 'AC-R3a',
        'title' => 'R-3 sesi nyata developer (...033097, ...474864, ...422239): footer di semua halaman, GET pdf + pratinjau, render dalam proses BACA SAJA; tanpa tumpang tindih; data developer tak berubah',
        'run'   => function ($t) {
            $GLOBALS['q251r_fail'] = [];
            $GLOBALS['q251r_pass'] = 0;
            $dev = [
                ['id' => '250224000117918817485624033097', 'owner' => 'lydia', 'rows' => 97, 'note' => 'footer dulu tampil'],
                ['id' => '250224000117920274494615474864', 'owner' => 'kevinsudjadi.adm', 'rows' => 401, 'note' => 'footer dulu hilang'],
                ['id' => '250224000117918792318276422239', 'owner' => 'kevinsudjadi.adm', 'rows' => 401, 'note' => 'sesi 398 baris, 5/11 menurut dev'],
            ];
            q251_baseline($t);
            $c = $t->db();
            $devBefore = q251_devstate($t);
            $tplBefore = json_encode(q251_tpl_row($t));
            $cdsBefore = json_encode(q251_cds_rows($t));
            $otherBefore = q251_tpl_other($t);
            $t->true(count(q251_tpl_row($t)) === 1, 'prasyarat: baris template documentArchiveOpnameResult developer ada (pengaturan sekarang dipakai apa adanya, tidak ditulis)');
            $company = $c->table('companies')->value('company_name');
            $s = $t->session();
            foreach ($dev as $d) {
                $row = $c->table('archive_opnames')->where('id_archive_opname', $d['id'])->first();
                $t->true($row !== null && (int) $row->status === 2, 'prasyarat: sesi nyata ' . $d['id'] . ' ada & terkonfirmasi (' . $d['note'] . ')');
                // --- HTTP sebagai QA_USER (tanpa mengubah role/izin): 404 ARCHIVE412 bila tak terlihat
                $r = q251_file($t, $s, $d['id'], 'pdf');
                q251_no500($t, [$r[0]], 'HTTP GET pdf sesi nyata ' . $d['id']);
                $t->true(in_array($r[0], [200, 404], true), 'HTTP QA_USER GET pdf sesi nyata ' . substr($d['id'], -6) . ' = ' . $r[0] . ' (200 terlihat / 404 ARCHIVE412 tak terlihat)');
                if ($r[0] === 200) {
                    $a = q251r_assert($t, 'HTTP QA_USER ' . substr($d['id'], -6), $r[1], ['user' => q251_uname($t), 'company' => $company]);
                    q251r_log('R3a-http', q251r_summ('http ' . $d['id'], $a));
                } else {
                    $t->eq(q251_jerr($r)['code'] ?? null, 'ARCHIVE412', 'HTTP 404 = ARCHIVE412 (sesi tak terlihat QA_USER; tanpa role/izin diberikan)');
                    $t->note('sesi ' . $d['id'] . ' tak terlihat QA_USER (404): dirender dalam proses sebagai ' . $d['owner'] . ', baca saja');
                }
                // --- dalam proses, sebagai pemilik, sesi MySQL read-only
                $variants = [
                    'GET pdf (template tersimpan)' => null,
                    'pratinjau {}' => [],
                    'pratinjau A4-L' => ['paper_size' => 'A4-L'],
                    'pratinjau Continuous-P' => ['paper_size' => 'Continuous-P'],
                    'pratinjau Continuous-L' => ['paper_size' => 'Continuous-L'],
                ];
                $expectMedia = [
                    'pratinjau Continuous-P' => q251r_cont($t, 'P'),
                    'pratinjau Continuous-L' => q251r_cont($t, 'L'),
                ];
                foreach ($variants as $vl => $payload) {
                    $res = q251r_dev_render($t, $d['id'], $d['owner'], $payload);
                    $label = substr($d['id'], -6) . ' [' . $d['owner'] . '] ' . $vl;
                    $t->true($res['bytes'] !== null && $res['err'] !== '' && strpos($res['err'], 'RETURNED') === 0, "$label: render dalam proses selesai tanpa exception/penulisan DB (" . substr($res['err'], 0, 120) . ')');
                    if ($res['bytes'] === null) {
                        continue;
                    }
                    $o = ['user' => $d['owner'], 'company' => $company];
                    if (isset($expectMedia[$vl])) {
                        $o['media'] = $expectMedia[$vl];
                    }
                    $a = q251r_assert($t, $label, $res['bytes'], $o);
                    q251r_log('R3a', q251r_summ($label, $a) + ['sec' => $res['sec']]);
                }
            }
            // --- kontrol negatif: report-template-6 SEBELUM perbaikan R-3 (footer sesudah $content; qa/fixtures/oldviews) memunculkan bug yang dilaporkan developer
            //     (...033097 footer tampil di semua halaman, ...474864 tanpa footer, ...422239 sebagian) -> detektor dan sesi nyata terbukti peka terhadap perbaikan
            $old = __DIR__ . '/fixtures/oldviews';
            $t->true(is_file($old . '/pdf-templates/layouts/templates/report-template-6.blade.php'), 'fixture view pra-perbaikan ada');
            $ctl = [];
            foreach ($dev as $d) {
                $res = q251r_dev_render($t, $d['id'], $d['owner'], null, $old);
                $t->true($res['bytes'] !== null && strpos($res['err'], 'RETURNED') === 0, 'kontrol pra-perbaikan ' . substr($d['id'], -6) . ': render selesai (' . substr($res['err'], 0, 100) . ')');
                $pp = q251r_pages($res['bytes'], $d['owner'], $company);
                $ctl[substr($d['id'], -6)] = count($pp['footer_ok']) . '/' . $pp['pages'];
                q251r_log('R3a-kontrol-pra-perbaikan', ['sesi' => $d['id'], 'footer_pages' => count($pp['footer_ok']), 'pages' => $pp['pages']]);
                if ($d['id'] === '250224000117920274494615474864') {
                    q251r_chk($t, count($pp['footer_ok']) === 0 && $pp['pages'] > 1, 'kontrol pra-perbaikan ...474864 (footer dulu hilang): ' . count($pp['footer_ok']) . '/' . $pp['pages'] . ' halaman berfooter (bug direproduksi, harus 0)');
                }
                if ($d['id'] === '250224000117918817485624033097') {
                    q251r_chk($t, count($pp['footer_ok']) === $pp['pages'], 'kontrol pra-perbaikan ...033097 (footer dulu tampil): ' . count($pp['footer_ok']) . '/' . $pp['pages'] . ' halaman berfooter');
                }
            }
            $t->note('kontrol pra-perbaikan (footer berhalaman/total): ' . json_encode($ctl));
            $t->eq(json_encode(q251_tpl_row($t)), $tplBefore, 'baris template documentArchiveOpnameResult developer tidak berubah (byte demi byte)');
            $t->eq(json_encode(q251_cds_rows($t)), $cdsBefore, 'baris display setting developer tidak berubah');
            $t->eq(q251_tpl_other($t), $otherBefore, 'template lain tidak berubah (count + checksum)');
            $t->eq(json_encode(q251_devstate($t)), json_encode($devBefore), 'tiga tabel opname + archives developer sama persis (sesi nyata tidak ditulis)');
            q251r_finish($t);
        },
    ],

    [
        'id'    => 'AC-R3b',
        'title' => 'R-3 sesi QA 6..1000 baris (S1 fixture, 50/199/200/201/399/400/401/1000), potret + lanskap + kertas lain + id: footer/header di tiap halaman, baris utuh, tanpa tumpang tindih',
        'run'   => function ($t) {
            $GLOBALS['q251r_fail'] = [];
            $GLOBALS['q251r_pass'] = 0;
            ini_set('memory_limit', '2G');
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                $user = q251_uname($t);
                $company = $t->db()->table('companies')->value('company_name');
                $rx = '/^QA251BULK-\d{7}$/';

                // --- S1 fixture (struktur nyata: Verified/Not found/Invalid/belum discan, tiga bagian), potret + lanskap
                list($x, $ids) = q251_fixture($t, $s);
                $rp = q251_file($t, $s, $ids['S1'], 'pdf');
                $t->status([$rp[0]], 200, 'S1 GET pdf');
                q251r_log('R3b', q251r_summ('S1 potret', q251r_assert($t, 'S1 potret', $rp[1], ['user' => $user, 'company' => $company, 'media' => [595.3, 841.9]])));
                $rv = q251_preview($t, $s, $ids['S1'], ['paper_size' => 'A4-L']);
                $t->status([$rv[0]], 200, 'S1 pratinjau A4-L');
                q251r_log('R3b', q251r_summ('S1 lanskap', q251r_assert($t, 'S1 lanskap', $rv[1], ['user' => $user, 'company' => $company, 'media' => [841.9, 595.3]])));

                // --- jumlah baris berbeda, potret (template default A4-P), batas potongan DETAIL_CHUNK = 200 dilewati
                $sessions = [];
                foreach ([50, 199, 200, 201, 399, 400, 401, 1000] as $n) {
                    $sessions[$n] = q251r_session($t, $n, 'R3N' . $n);
                    $t0 = microtime(true);
                    $r = q251_file($t, $s, $sessions[$n], 'pdf');
                    $sec = round(microtime(true) - $t0, 1);
                    $t->status([$r[0]], 200, "GET pdf $n baris");
                    $a = q251r_assert($t, "GET pdf $n baris potret", $r[1], ['user' => $user, 'company' => $company, 'row_rx' => $rx, 'rows' => $n, 'media' => [595.3, 841.9]]);
                    q251r_log('R3b', q251r_summ("$n baris potret (GET pdf)", $a) + ['sec' => $sec]);
                }

                // --- pratinjau (payload Customize) lanskap dan kertas lain
                foreach ([[201, 'A4-L', [841.9, 595.3]], [401, 'A4-L', [841.9, 595.3]], [1000, 'A4-L', [841.9, 595.3]], [401, 'A5-P', [419.5, 595.3]], [401, 'Letter-L', [792, 612]],
                    [401, 'Legal-P', [612, 1008]], [401, 'Folio-L', [936, 612]]] as $c) {
                    list($n, $paper, $media) = $c;
                    $r = q251_preview($t, $s, $sessions[$n], ['paper_size' => $paper]);
                    $t->status([$r[0]], 200, "pratinjau $paper $n baris");
                    // A5-P: header 5 baris (perusahaan, judul, Folder, Opname Date, Opname By) lebih tinggi dari tinggi header A5 kode bersama
                    // (PdfTemplateService::getHeaderHeight A5 base = 110 px): pengamatan, bukan syarat putaran ini (lihat qa-be.md O-1)
                    $a = q251r_assert($t, "pratinjau $paper $n baris", $r[1], ['user' => $user, 'company' => $company, 'row_rx' => $rx, 'rows' => $n, 'media' => $media, 'soft_header' => $paper === 'A5-P']);
                    q251r_log('R3b', q251r_summ("$n baris $paper (pratinjau)", $a));
                }

                // --- bahasa id (label footer "Dicetak oleh" / "Halaman i dari N") dan kunci footer dipilih
                $r = q251_preview($t, $s, $sessions[401], ['language' => 'id']);
                $t->status([$r[0]], 200, 'pratinjau language id 401 baris');
                $a = q251r_assert($t, 'pratinjau language id 401 baris', $r[1], ['user' => $user, 'company' => $company, 'row_rx' => $rx, 'rows' => 401]);
                $t->true(strpos(q251_pdf($r[1])['text'], 'Dicetak oleh') !== false && strpos(q251_pdf($r[1])['text'], 'Halaman') !== false, 'footer berbahasa id: "Dicetak oleh" dan "Halaman i dari N"');
                q251r_log('R3b', q251r_summ('401 baris language id (pratinjau)', $a));

                // --- footer hanya sebagian field (Customize): tetap di semua halaman
                $r = q251_preview($t, $s, $sessions[401], ['footer' => ['current_fields' => ['page_number'], 'repeat' => true]]);
                $t->status([$r[0]], 200, 'pratinjau footer hanya page_number');
                $pp = q251r_pages($r[1], $user, $company);
                $n = $pp['pages'];
                $okPg = 0;
                foreach ($pp['texts'] as $i => $tx) {
                    $okPg += preg_match('/Page ' . ($i + 1) . ' of ' . $n . '\b/', implode("\n", $tx)) ? 1 : 0;
                }
                $t->eq($okPg, $n, "pratinjau footer hanya nomor halaman: 'Page i of $n' di $okPg/$n halaman");
                $t->true(strpos(q251_pdf($r[1])['text'], 'Printed by') === false, 'footer hanya page_number: tanpa "Printed by"');

                // --- template tersimpan lanskap (Simpan Customize) -> GET pdf
                $tpl = q251_tpl($t, $s);
                $over = q251_save_body($tpl, $tpl['tables'], ['paper_size' => 'A4-L']);
                unset($over['footer']['labels']);
                $t->status($t->call($s, 'POST', 'api/v5/setting/pdf-templates/update', $over), 200, 'Simpan paper_size A4-L');
                $r = q251_file($t, $s, $sessions[401], 'pdf');
                $t->status([$r[0]], 200, 'GET pdf template tersimpan A4-L, 401 baris');
                $a = q251r_assert($t, 'GET pdf tersimpan A4-L 401 baris', $r[1], ['user' => $user, 'company' => $company, 'row_rx' => $rx, 'rows' => 401, 'media' => [841.9, 595.3]]);
                q251r_log('R3b', q251r_summ('401 baris A4-L (template tersimpan)', $a));

                // --- kontrol negatif detektor: footer dimatikan lewat Customize (current_fields []) -> detektor HARUS melihat 0 halaman berfooter
                $r = q251_preview($t, $s, $sessions[401], ['footer' => ['current_fields' => []]]);
                $t->status([$r[0]], 200, 'kontrol negatif: pratinjau footer tanpa field');
                $pn = q251r_pages($r[1], $user, $company);
                $t->eq(count($pn['footer_ok']), 0, 'kontrol negatif: detektor footer melihat 0/' . $pn['pages'] . ' halaman berfooter bila footer dikosongkan (detektor memang bisa mendeteksi footer hilang)');

                // --- kontrol: report-template-1 (footer di akhir, pola lama) pada 401 baris. Hanya dicatat (di luar item).
                $r = q251_preview($t, $s, $sessions[401], ['id_pdf_template' => 'report-template-1']);
                $t->status([$r[0]], 200, 'kontrol: pratinjau report-template-1 401 baris');
                $pc = q251r_pages($r[1], $user, $company);
                $t->note(sprintf('kontrol detektor (di luar item): report-template-1, 401 baris: %d halaman, footer ada di %d halaman (hilang: %s)', $pc['pages'], count($pc['footer_ok']), implode(',', array_slice($pc['footer_missing'], 0, 12))));
                q251r_log('R3b-control', ['label' => 'report-template-1 401 baris', 'pages' => $pc['pages'], 'footer_pages' => count($pc['footer_ok'])]);
                q251r_finish($t);
            });
        },
    ],

    [
        'id'    => 'AC-R4',
        'title' => 'R-4 kertas Continuous: pratinjau dan GET pdf tersimpan Continuous-P/-L = 200, MediaBox = ukuran Continuous (bukan A4); bentuk salah tetap 422 paper_size',
        'run'   => function ($t) {
            $GLOBALS['q251r_fail'] = [];
            $GLOBALS['q251r_pass'] = 0;
            ini_set('memory_limit', '2G');
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                $user = q251_uname($t);
                $company = $t->db()->table('companies')->value('company_name');
                $rx = '/^QA251BULK-\d{7}$/';
                $cP = q251r_cont($t, 'P');
                $cL = q251r_cont($t, 'L');
                $t->eq($cP, [612.0, 381.6], 'ukuran Continuous-P dari PdfTemplateService::getPaperFormat = 215,9 x 134,62 mm = 612 x 381,6 pt');
                $t->eq($cL, [784.8, 597.6], 'ukuran Continuous-L dari PdfTemplateService::getPaperFormat = 276,86 x 210,82 mm = 784,8 x 597,6 pt');
                list($x, $ids) = q251_fixture($t, $s);
                $big = q251r_session($t, 401, 'R4N401');
                $rowBefore = json_encode(q251_tpl_row($t));

                // --- pratinjau
                foreach (['P' => $cP, 'L' => $cL] as $o => $media) {
                    $r = q251_preview($t, $s, $ids['S1'], ['paper_size' => "Continuous-$o"]);
                    $t->status([$r[0]], 200, "pratinjau Continuous-$o S1");
                    $a = q251r_assert($t, "pratinjau Continuous-$o S1", $r[1], ['user' => $user, 'company' => $company, 'media' => $media]);
                    q251r_log('R4', q251r_summ("S1 pratinjau Continuous-$o", $a));
                    $r = q251_preview($t, $s, $big, ['paper_size' => "Continuous-$o"]);
                    $t->status([$r[0]], 200, "pratinjau Continuous-$o 401 baris");
                    $a = q251r_assert($t, "pratinjau Continuous-$o 401 baris", $r[1], ['user' => $user, 'company' => $company, 'media' => $media, 'row_rx' => $rx, 'rows' => 401, 'min_pages' => 10]);
                    q251r_log('R4', q251r_summ("401 baris pratinjau Continuous-$o", $a));
                    // bukan A4
                    $m0 = q251_pdf($r[1])['media'][0];
                    $t->true(!(abs($m0[0] - 595.28) < 1 && abs($m0[1] - 841.89) < 1) && !(abs($m0[0] - 841.89) < 1 && abs($m0[1] - 595.28) < 1), "pratinjau Continuous-$o: bukan A4 (" . json_encode($m0) . ')');
                }
                // pratinjau tidak menyimpan
                $t->eq(json_encode(q251_tpl_row($t)), $rowBefore, 'pratinjau Continuous tidak menulis baris template');

                // --- bentuk salah tetap 422 paper_size (tanpa 500)
                $bad = ['continuous-p' => 'continuous-p', 'Continuous (tanpa orientasi)' => 'Continuous', 'Continuous-X' => 'Continuous-X', 'Continuous-PL' => 'Continuous-PL', 'Continuous-P di tengah spasi' => 'Continuous -P',
                    'B5-P' => 'B5-P', 'A4 (tanpa orientasi)' => 'A4', 'Continuous--P' => 'Continuous--P', 'string kosong' => '', 'array' => ['Continuous-P'], 'angka' => 5];
                $shown = false;
                foreach ($bad as $label => $val) {
                    $r = q251_preview($t, $s, $ids['S1'], ['paper_size' => $val]);
                    q251_no500($t, [$r[0]], "pratinjau paper_size $label");
                    if ($val === '') {
                        // string kosong diperlakukan sebagai tak dikirim (null/'' = kunci tetap): dicatat saja, bukan 500
                        $t->note('pratinjau paper_size "" -> HTTP ' . $r[0]);
                        continue;
                    }
                    $t->eq($r[0], 422, "pratinjau paper_size $label: 422");
                    $t->true(strpos($r[1], 'paper_size') !== false, "pratinjau paper_size $label: pesan menyebut paper_size");
                    if (!$shown) {
                        $t->note('contoh 422: ' . substr($r[1], 0, 200));
                        $shown = true;
                    }
                }

                // spasi/baris baru di tepi dipangkas middleware TrimStrings (bukan 422, bukan 500): dicatat saja
                $r = q251_preview($t, $s, $ids['S1'], ['paper_size' => " Continuous-P\n"]);
                q251_no500($t, [$r[0]], 'pratinjau paper_size " Continuous-P\\n" (dipangkas TrimStrings)');
                $t->note('pratinjau paper_size " Continuous-P\\n" (spasi/newline di tepi) -> HTTP ' . $r[0] . ($r[0] === 200 ? ', MediaBox ' . json_encode(q251_pdf($r[1])['media'][0]) : ''));

                // --- template tersimpan Continuous-P / -L -> GET pdf
                $tpl = q251_tpl($t, $s);
                $paperBefore = $tpl['paper_size'];
                foreach (['P' => $cP, 'L' => $cL] as $o => $media) {
                    $over = q251_save_body($tpl, $tpl['tables'], ['paper_size' => "Continuous-$o"]);
                    unset($over['footer']['labels']);
                    $t->status($t->call($s, 'POST', 'api/v5/setting/pdf-templates/update', $over), 200, "Simpan paper_size Continuous-$o");
                    $t->eq(q251_tpl($t, $s)['paper_size'], "Continuous-$o", "transaction-templates: paper_size tersimpan Continuous-$o");
                    $r = q251_file($t, $s, $ids['S1'], 'pdf');
                    $t->status([$r[0]], 200, "GET pdf tersimpan Continuous-$o S1");
                    $a = q251r_assert($t, "GET pdf tersimpan Continuous-$o S1", $r[1], ['user' => $user, 'company' => $company, 'media' => $media]);
                    q251r_log('R4', q251r_summ("S1 GET pdf tersimpan Continuous-$o", $a));
                    $r = q251_file($t, $s, $big, 'pdf');
                    $t->status([$r[0]], 200, "GET pdf tersimpan Continuous-$o 401 baris");
                    $a = q251r_assert($t, "GET pdf tersimpan Continuous-$o 401 baris", $r[1], ['user' => $user, 'company' => $company, 'media' => $media, 'row_rx' => $rx, 'rows' => 401, 'min_pages' => 10]);
                    q251r_log('R4', q251r_summ("401 baris GET pdf tersimpan Continuous-$o", $a));
                    // pratinjau dengan kertas lain menimpa kertas tersimpan
                    $r = q251_preview($t, $s, $ids['S1'], ['paper_size' => 'A4-P']);
                    $m0 = q251_pdf($r[1])['media'][0];
                    $t->true(abs($m0[0] - 595.28) < 0.5 && abs($m0[1] - 841.89) < 0.5, "pratinjau A4-P menimpa kertas tersimpan Continuous-$o (" . json_encode($m0) . ')');
                    // Excel tidak ikut kertas
                    $rx2 = q251_file($t, $s, $ids['S1'], 'xls');
                    $t->status([$rx2[0]], 200, "GET xls dengan kertas tersimpan Continuous-$o");
                }
                // Simpan kembali kertas semula, GET pdf = ukuran semula
                $over = q251_save_body($tpl, $tpl['tables'], ['paper_size' => $paperBefore]);
                unset($over['footer']['labels']);
                $t->status($t->call($s, 'POST', 'api/v5/setting/pdf-templates/update', $over), 200, "Simpan kembali paper_size $paperBefore");
                $m0 = q251_pdf(q251_file($t, $s, $ids['S1'], 'pdf')[1])['media'][0];
                $t->true(abs($m0[0] - 595.28) < 0.5 && abs($m0[1] - 841.89) < 0.5, 'kertas kembali A4-P -> GET pdf A4-P (' . json_encode($m0) . ')');
                q251r_finish($t);
            });
        },
    ],

    [
        'id'    => 'AC-R5',
        'title' => 'R-5 info($paper) hilang, hunk developer (getHeaderPadding, 2 view report-header-1) ada; PDF laporan lain (template-6: VAT In/Out, PB1, WHT, AP Summary; -1: VAT Summary, AP Due; -2: Journal) 200, header+footer, tanpa tumpang tindih',
        'run'   => function ($t) {
            $GLOBALS['q251r_fail'] = [];
            $GLOBALS['q251r_pass'] = 0;
            $be = rtrim((string) $t->conf('BE_DIR'), '/');
            // --- statis: berkas BE
            $hit = [];
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($be . '/Modules/V5/Http', \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (substr($f->getFilename(), -4) === '.php' && preg_match('/\binfo\(\s*\$paper\s*\)/', file_get_contents($f->getPathname()))) {
                    $hit[] = $f->getPathname();
                }
            }
            $t->eq($hit, [], 'tanpa baris debug info($paper) di Modules/V5/Http');
            $pts = file_get_contents($be . '/Modules/V5/Http/Services/Setup/Setting/PdfTemplateService.php');
            $t->true(strpos($pts, 'public function getHeaderPadding(') !== false, 'PdfTemplateService::getHeaderPadding (hunk developer) ada');
            $t->true(strpos($pts, "'report-header-1' => 20 . 'px'") !== false, 'getHeaderPadding: padding report-header-1 20px (hunk developer utuh)');
            $t->true(strpos($pts, 'public function getPaperFormat(') !== false, 'getPaperFormat public (R-4)');
            $hv = file_get_contents($be . '/Modules/V5/Resources/views/pdf-templates/layouts/headers/report-header-1.blade.php');
            $t->true(strpos($hv, 'getHeaderPadding(') !== false && strpos($hv, 'padding-top: {{ $headerPadding }}') !== false && strpos($hv, 'paper-{{ $pdfTemplate->getPaper($paper) }} header report-header-1') !== false, 'layouts/headers/report-header-1: padding + kelas paper (hunk developer) ada');
            $sv = file_get_contents($be . '/Modules/V5/Resources/views/pdf-templates/styles/headers/report-header-1.blade.php');
            $t->true(strpos($sv, '.paper-A5.report-header-1 .company-name') !== false && strpos($sv, '.paper-A5.report-header-1 .report-filter') !== false, 'styles/headers/report-header-1: gaya paper-A5 (hunk developer) ada');
            $t6 = file_get_contents($be . '/Modules/V5/Resources/views/pdf-templates/layouts/templates/report-template-6.blade.php');
            $pf = strpos($t6, "layouts.footers.report-footer-1'");
            $pc = strpos($t6, '{!! $content !!}');
            $t->true($pf !== false && $pc !== false && $pf < $pc && substr_count($t6, 'layouts.footers.report-footer-1') === 1, 'report-template-6: include footer SEBELUM $content (satu kali)');

            // --- regresi PDF laporan lain (QA_USER role bawaan; pemakai laporan, tanpa mengubah role)
            q251_baseline($t);
            $c = $t->db();
            $otherBefore = q251_tpl_other($t);
            $ownBefore = json_encode(q251_tpl_row($t));
            $cdsCount = $c->table('column_display_settings')->count();
            $tplCount = $c->table('transaction_pdf_templates')->count();
            $company = $c->table('companies')->value('company_name');
            $user = q251_uname($t);
            $s = $t->session();
            $aug = json_encode(['transactionDate' => ['2026-08-01 00:00:00', '2026-08-31 23:59:59']]);
            $oct = json_encode(['transactionDate' => ['2026-10-01 00:00:00', '2026-10-31 23:59:59']]);
            $q3 = json_encode(['transactionDate' => ['2026-07-01 00:00:00', '2026-09-30 23:59:59']]);
            $reports = [
                // nama => [path, search, template, baris minimal oracle API (grup transactions[])]
                'VAT In (template-6, header-1, multi-chunk)' => ['api/v5/report/tax/vat-in', $aug, 'report-template-6', 51, true],
                'VAT Out (template-6)' => ['api/v5/report/tax/vat-out', $oct, 'report-template-6', 51, true],
                'PB1 (template-6)' => ['api/v5/report/tax/pb1', $q3, 'report-template-6', 0, false],
                'WHT Sales (template-6)' => ['api/v5/report/tax/withholding-sales', $q3, 'report-template-6', 0, false],
                'WHT Purchase (template-6)' => ['api/v5/report/tax/withholding-purchase', $q3, 'report-template-6', 0, false],
                'AP Summary (template-6)' => ['api/v5/report/account-payable/ap-summary', $q3, 'report-template-6', 0, false],
                'VAT Summary (report-template-1, header-1)' => ['api/v5/report/tax/vat-summary', $q3, 'report-template-1', 0, false],
                'AP Due (report-template-1, header-1)' => ['api/v5/report/account-payable/ap-due', $q3, 'report-template-1', 0, false],
                'Journal (report-template-2, header-1)' => ['api/v5/report/general-ledger/journals', $q3, 'report-template-2', 0, false],
            ];
            foreach ($reports as $name => $spec) {
                list($path, $search, $tplId, $minRows, $oracle) = $spec;
                $row = $c->table('transaction_pdf_templates')->where('id_pdf_template', $tplId)->where('id_header_pdf_template', 'report-header-1')->count();
                $t->true($row >= 1, "$name: prasyarat template laporan $tplId + report-header-1 dipakai di DB");
                $q = ['search' => $search];
                $apiRows = null;
                if ($oracle) {
                    $api = $t->call($s, 'GET', q251_url($path, $q));
                    $t->status($api, 200, "$name: index API");
                    $apiRows = [];
                    foreach (($api[1]['result']['data'] ?? $api[1]['data'] ?? []) as $g) {
                        foreach ($g['transactions'] ?? [] as $tr) {
                            $apiRows[] = $tr['transactionNo'][0]['transactionNo'] ?? null;
                        }
                    }
                    $t->true(count($apiRows) >= $minRows, "$name: oracle API memuat " . count($apiRows) . " baris (>= $minRows, lintas potongan 50)");
                }
                $t0 = microtime(true);
                $r = $t->callFile($s, 'GET', q251_url($path . '/pdf', $q));
                $sec = round(microtime(true) - $t0, 1);
                q251_no500($t, [$r[0]], "$name GET pdf");
                $t->status([$r[0]], 200, "$name: GET pdf 200 ($sec s)");
                if ($r[0] !== 200) {
                    continue;
                }
                $a = q251r_assert($t, $name, $r[1], ['user' => $user, 'company' => $company, 'min_pages' => $oracle ? 2 : 1, 'soft_footer' => $tplId !== 'report-template-6']);
                // template-1/-2: pola footer di akhir tidak diubah item ini (usulan) -> hanya dicatat bila footer tidak di semua halaman, bukan gagal
                q251r_log('R5', q251r_summ($name, $a) + ['sec' => $sec, 'rows_api' => $apiRows === null ? null : count($apiRows)]);
                if ($oracle) {
                    $p = q251_pdf($r[1]);
                    $txt = implode("\n", array_map('q251_norm', array_merge(...$p['texts'])));
                    $found = 0;
                    foreach ($apiRows as $no) {
                        if ($no !== null && strpos($txt, $no) !== false) {
                            $found++;
                        }
                    }
                    $t->eq($found, count($apiRows), "$name: semua " . count($apiRows) . ' nomor transaksi oracle API ada di PDF (isi tidak hilang karena footer dipindah)');
                }
            }
            $t->eq(q251_tpl_other($t), $otherBefore, 'regresi laporan lain tidak membuat/mengubah template lain (count + checksum)');
            $t->eq(json_encode(q251_tpl_row($t)), $ownBefore, 'baris template documentArchiveOpnameResult tidak berubah');
            $t->eq($c->table('column_display_settings')->count(), $cdsCount, 'jumlah baris column_display_settings tidak berubah');
            $t->eq($c->table('transaction_pdf_templates')->count(), $tplCount, 'jumlah baris transaction_pdf_templates tidak berubah');
            q251r_finish($t);
        },
    ],
];
