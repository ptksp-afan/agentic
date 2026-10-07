<?php
/**
 * ED-1026 - scan & hasil sesi: AC-10 (klasifikasi Verified / Not found / Invalid), AC-11 (scan ganda),
 * AC-16 (GET opnames/{id}/documents: 6 filter, paginator, draft & snapshot), EXTRA-SCAN (masukan scan aneh tanpa 500).
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-10',
        'title' => 'Scan: dokumen di folder tercakup -> verified; folder lain / root / is_active -1 & 0 / lokasi tak terlihat / subfolder tak dicentang -> not_found (is_out_of_scope); kode tak dikenal / nama folder -> invalid; scanned_at = waktu server',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $N = function ($k) use ($t, $x) {
                    return q26_n($t, $x[$k]);
                };
                $id = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));

                $scan = function ($code, $result, $label, $extra = []) use ($t, $s, $id) {
                    $r = q26_scan($t, $s, $id, $code);
                    $t->status($r, 200, "scan $label");
                    $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE212', "scan $label: ARCHIVE212");
                    $row = $r[1]['result']['row'];
                    $t->eq($row['result'], $result, "scan $label: result");
                    $t->true($r[1]['result']['is_duplicate'] === false, "scan $label: bukan duplikat");
                    foreach ($extra as $k => $v) {
                        $t->true($row[$k] === $v, "scan $label: $k = " . json_encode($v));
                    }

                    return $r;
                };

                // --- Verified
                $r = $scan($N('dF1'), 'verified', 'dokumen langsung di F');
                $row = $r[1]['result']['row'];
                $t->eq(array_keys($row), ['id_archive_opname_document', 'id_archive', 'code', 'transaction_no', 'transaction_type', 'transaction_type_label', 'salesman', 'id_archive_folder', 'folder_name', 'is_out_of_scope', 'result', 'result_label', 'is_verified_after', 'scanned_at'], 'row: kunci sesuai kontrak (document row)');
                $t->eq($row['id_archive'], $x['dF1'], 'row: id_archive');
                $t->eq($row['transaction_no'], $N('dF1'), 'row: transaction_no');
                $t->true($row['transaction_type'] === 6 && $row['transaction_type_label'] === 'Pesanan Penjualan', 'row: transaction_type 6 + label bahasa user');
                $t->eq($row['salesman'], 'Budi', 'row: salesman = related_employee_name');
                $t->eq($row['id_archive_folder'], $x['F'], 'row: id_archive_folder = F');
                $t->eq($row['folder_name'], q26_n($t, $x['F']), 'row: folder_name');
                $t->true($row['is_out_of_scope'] === false && $row['result_label'] === 'Terverifikasi' && $row['is_verified_after'] === null, 'row: is_out_of_scope false, label, is_verified_after null (draft)');
                $t->true(abs(strtotime($row['scanned_at']) - time()) < 30, 'row: scanned_at = waktu server');
                $scan($N('dF2'), 'verified', 'dokumen langsung F lain');
                $scan(strtolower($N('d11')), 'verified', 'subfolder tercentang (huruf kecil)', ['id_archive_folder' => $x['S1']]);
                $scan($N('dA1'), 'verified', 'sub-subfolder ikut otomatis', ['id_archive_folder' => $x['S1a']]);
                $scan('  ' . $N('dJF') . '  ', 'verified', 'dokumen lokasi JOG, user semua lokasi (spasi di tepi)');

                // --- Not found (is_out_of_scope true, folder null)
                foreach ([
                    'd21' => 'subfolder tak dicentang (S2)',
                    'dj1' => 'subfolder tak dicentang lokasi JOG (SJ)',
                    'dx1' => 'folder nonaktif (SX)',
                    'dO1' => 'folder lain (OTH)',
                    'dR'  => 'root (tanpa folder)',
                    'dNEG' => 'is_active -1',
                    'dDEL' => 'is_active 0',
                ] as $k => $label) {
                    $r = $scan($N($k), 'not_found', "luar cakupan: $label", ['is_out_of_scope' => true, 'id_archive_folder' => null, 'folder_name' => null]);
                    $t->eq($r[1]['result']['row']['id_archive'], $x[$k], "luar cakupan: id_archive terisi ($label)");
                }

                // --- Invalid
                foreach ([
                    'QA26-TIDAK-ADA-123' => 'kode tak dikenal',
                    q26_n($t, $x['S1']) => 'nama folder',
                    '0' => 'angka 0 (tanpa pencocokan numerik)',
                    '1' => 'angka 1',
                    '00' => 'dua nol',
                    'QA26-%' => 'wildcard LIKE %',
                    'QA26-dF1-_' => 'wildcard LIKE _',
                    "' OR '1'='1" => 'kutip SQL',
                    'a\\' => 'backslash',
                ] as $code => $label) {
                    $r = $scan((string) $code, 'invalid', "invalid: $label", ['id_archive' => null, 'is_out_of_scope' => false, 'folder_name' => null, 'transaction_no' => null]);
                    $t->eq($r[1]['result']['row']['code'], (string) $code, "invalid: code = teks scan ($label)");
                }

                // --- DB: baris archive_opname_documents
                $docs = q26_opdocs($t, $id);
                $expect = [
                    strtolower($N('dF1')) => [1, $x['dF1'], $x['F']], strtolower($N('dF2')) => [1, $x['dF2'], $x['F']],
                    strtolower($N('d11')) => [1, $x['d11'], $x['S1']], strtolower($N('dA1')) => [1, $x['dA1'], $x['S1a']],
                    strtolower($N('dJF')) => [1, $x['dJF'], $x['F']],
                    strtolower($N('d21')) => [2, $x['d21'], null], strtolower($N('dj1')) => [2, $x['dj1'], null], strtolower($N('dx1')) => [2, $x['dx1'], null],
                    strtolower($N('dO1')) => [2, $x['dO1'], null], strtolower($N('dR')) => [2, $x['dR'], null],
                    strtolower($N('dNEG')) => [2, $x['dNEG'], null], strtolower($N('dDEL')) => [2, $x['dDEL'], null],
                    'qa26-tidak-ada-123' => [3, null, null], strtolower(q26_n($t, $x['S1'])) => [3, null, null], '0' => [3, null, null],
                ];
                foreach ($expect as $code => $e) {
                    $d = $docs[$code] ?? null;
                    $t->true($d !== null, "DB: baris untuk '$code'");
                    $t->eq([(int) $d['result'], $d['id_archive'], $d['id_archive_folder']], $e, "DB: result/id_archive/id_archive_folder '$code'");
                    $t->true($d['scanned_at'] !== null && abs(strtotime($d['scanned_at']) - time()) < 60, "DB: scanned_at server '$code'");
                    $t->eq($d['id_archive_opname'], $id, "DB: id_archive_opname '$code'");
                    $t->true($d['is_verified_after'] === null, "DB: is_verified_after NULL pada draft '$code'");
                }
                $t->eq($docs[strtolower($N('d11'))]['code'], strtolower($N('d11')), 'DB: code = teks yang discan (huruf kecil)');
                $t->eq($docs[strtolower($N('dJF'))]['code'], $N('dJF'), 'DB: code di-trim');

                // scanned_at dari klien diabaikan
                $r = $t->call($s, 'POST', Q26_BASE . '/opnames/' . $id . '/scan', ['code' => 'QA26-WAKTU-KLIEN', 'scanned_at' => '2000-01-01 00:00:00']);
                $t->status($r, 200, 'scan dengan scanned_at dari klien');
                $t->true(strtotime($r[1]['result']['row']['scanned_at']) > strtotime('2020-01-01'), 'scanned_at klien diabaikan (waktu server)');

                // --- tidak ada perubahan pada archives (BR-24 untuk draft)
                $t->eq(json_encode(q26_dirty($t)['archives']), '0', 'archives (non QA26) tak berubah');
                foreach (['d21', 'dO1', 'dR', 'dNEG', 'dDEL', 'dF1'] as $k) {
                    $t->eq(q26_v($t, $x[$k]), [0, false, null, null], "draft scan tidak mengubah verifikasi $k");
                }

                // --- user Semarang: dokumen lokasi JOG di F tak terlihat -> not found; dokumen lain verified
                q26_with_user($t, ['emp' => ['SMR']], function () use ($t, $s, $x, $N) {
                    $id2 = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                    $r = q26_scan($t, $s, $id2, $N('dJF'));
                    $t->status($r, 200, 'Semarang: scan dJF');
                    $t->eq($r[1]['result']['row']['result'], 'not_found', 'Semarang: dokumen lokasi JOG di F -> not_found (tak terlihat)');
                    $r = q26_scan($t, $s, $id2, $N('dj1'));
                    $t->eq($r[1]['result']['row']['result'], 'not_found', 'Semarang: dokumen di subfolder JOG -> not_found');
                    $r = q26_scan($t, $s, $id2, $N('dF1'));
                    $t->eq($r[1]['result']['row']['result'], 'verified', 'Semarang: dokumen terlihat di F -> verified');
                    $show = q26_show($t, $s, $id2);
                    $t->eq($show[1]['result']['counts']['total_documents'], 4, 'Semarang: total = dF1,dF2,d11,dA1 (dJF tak terlihat tidak dihitung)');
                });
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-11',
        'title' => 'Scan kode yang sama (juga beda huruf / spasi): 200 is_duplicate=true, baris lama, jumlah baris DB & counts tetap',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $id = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                $name = q26_n($t, $x['d11']);

                $first = q26_scan($t, $s, $id, $name);
                $t->status($first, 200, 'scan pertama');
                $t->true($first[1]['result']['is_duplicate'] === false, 'scan pertama bukan duplikat');
                $rowId = $first[1]['result']['row']['id_archive_opname_document'];
                $before = $t->db()->table('archive_opname_documents')->where('id_archive_opname', $id)->count();
                $t->eq($first[1]['result']['counts'], ['scanned' => 1, 'verified' => 1, 'not_found' => 0, 'invalid' => 0], 'counts sesudah scan pertama');

                foreach (['sama persis' => $name, 'huruf besar' => strtoupper($name), 'huruf kecil' => strtolower($name), 'spasi di tepi' => "  $name "] as $label => $code) {
                    $r = q26_scan($t, $s, $id, $code);
                    $t->status($r, 200, "duplikat ($label)");
                    $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE212', "duplikat ($label): msg_code");
                    $t->true($r[1]['result']['is_duplicate'] === true, "duplikat ($label): is_duplicate true");
                    $t->eq($r[1]['result']['row']['id_archive_opname_document'], $rowId, "duplikat ($label): baris lama");
                    $t->eq($r[1]['result']['row']['code'], $name, "duplikat ($label): code baris lama tidak diubah");
                    $t->eq($r[1]['result']['counts'], ['scanned' => 1, 'verified' => 1, 'not_found' => 0, 'invalid' => 0], "duplikat ($label): counts tetap");
                    $t->eq($t->db()->table('archive_opname_documents')->where('id_archive_opname', $id)->count(), $before, "duplikat ($label): jumlah baris DB tetap");
                }

                // duplikat untuk Not found & Invalid juga tidak menambah
                foreach ([q26_n($t, $x['dR']) => 'not_found', 'QA26-DUP-INVALID' => 'invalid'] as $code => $result) {
                    $a = q26_scan($t, $s, $id, (string) $code);
                    $b = q26_scan($t, $s, $id, (string) $code);
                    $t->true($a[1]['result']['is_duplicate'] === false && $b[1]['result']['is_duplicate'] === true, "$result: kedua kalinya duplikat");
                    $t->eq($b[1]['result']['counts'], $a[1]['result']['counts'], "$result: counts tetap");
                    $t->eq($b[1]['result']['row']['result'], $result, "$result: hasil sama");
                }
                $show = q26_show($t, $s, $id);
                $t->eq($show[1]['result']['counts']['scanned'], 3, 'show.counts.scanned = 3 baris unik');
                $t->eq($t->db()->table('archive_opname_documents')->where('id_archive_opname', $id)->count(), 3, 'DB: 3 baris unik');

                // sesi lain boleh memindai kode yang sama (unik per sesi)
                $id2 = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                $r = q26_scan($t, $s, $id2, $name);
                $t->true($r[1]['result']['is_duplicate'] === false, 'sesi lain: bukan duplikat');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-16',
        'title' => 'GET opnames/{id}/documents: 6 filter + paginator tanpa columns/queries; Not found folder_name null & is_out_of_scope true; belum discan unscanned (scanned_at null); urutan scan terbaru dulu lalu belum discan per code; draft dan snapshot terkonfirmasi',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $N = function ($k) use ($t, $x) {
                    return q26_n($t, $x[$k]);
                };
                $id = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                // urutan scan (jeda 1,1 dtk agar urutan terbaru-dulu terukur per detik)
                $order = [$N('dF1'), $N('d11'), $N('d21'), $N('dA1'), 'QA26-NOPE', $N('dR')];
                foreach ($order as $code) {
                    $r = q26_scan($t, $s, $id, $code);
                    $t->status($r, 200, "scan $code");
                    usleep(1100000);
                }
                // dalam cakupan (role 3, semua lokasi): dF1, dF2, dJF, d11, dA1 = 5; verified 3 (dF1,d11,dA1); belum discan 2 (dF2,dJF)
                $unscanned = [$N('dF2'), $N('dJF')];
                sort($unscanned, SORT_STRING | SORT_FLAG_CASE);

                $codes = function ($rows) {
                    return array_map(function ($r) {
                        return $r['code'];
                    }, $rows);
                };

                $expectedFilters = [
                    'all'       => count($order) + 2,
                    'scanned'   => 6,
                    'verified'  => 3,
                    'not_found' => 2,
                    'invalid'   => 1,
                    'unscanned' => 2,
                ];
                $show = q26_show($t, $s, $id);
                $c = $show[1]['result']['counts'];
                $t->eq([$c['total_documents'], $c['scanned'], $c['verified'], $c['not_found'], $c['invalid'], $c['unscanned']], [5, 6, 3, 2, 1, 2], 'show.counts draft');

                foreach ($expectedFilters as $filter => $n) {
                    $r = q26_docs($t, $s, $id, ['result' => $filter]);
                    $t->status($r, 200, "documents $filter");
                    $res = $r[1]['result'];
                    $t->eq($res['total'], $n, "documents $filter: total");
                    $t->true(!array_key_exists('columns', $res) && !array_key_exists('queries', $res), "documents $filter: tanpa columns/queries");
                    foreach (['current_page', 'data', 'first_page_url', 'from', 'last_page', 'last_page_url', 'next_page_url', 'path', 'per_page', 'prev_page_url', 'to', 'total'] as $k) {
                        $t->true(array_key_exists($k, $res), "documents $filter: kunci paginator $k");
                    }
                    $all = q26_all_docs($t, $s, $id, $filter, 3);
                    $t->eq(count($all), $n, "documents $filter: jumlah baris semua halaman (pagination 3)");
                }

                // default tanpa result = all
                $r = q26_docs($t, $s, $id);
                $t->eq($r[1]['result']['total'], 8, 'tanpa result = all (8)');
                $r = q26_docs($t, $s, $id, ['result' => '']);
                $t->eq($r[1]['result']['total'], 8, 'result kosong = all (8)');
                $t->eq($r[1]['result']['per_page'], 10, 'pagination bawaan 10');

                // urutan: scan terbaru dulu (dR, NOPE, dA1, d21, d11, dF1), lalu belum discan per code
                $rows = q26_all_docs($t, $s, $id, 'all', 100);
                $t->eq($codes($rows), array_merge(array_reverse($order), $unscanned), 'urutan all: scan terbaru dulu, lalu belum discan per code');
                $rows = q26_all_docs($t, $s, $id, 'scanned', 100);
                $t->eq($codes($rows), array_reverse($order), 'urutan scanned: terbaru dulu');
                $nf = q26_all_docs($t, $s, $id, 'not_found', 100);
                $t->eq($codes($nf), [$N('dR'), $N('d21')], 'not_found: isi');
                foreach ($nf as $row) {
                    $t->true($row['folder_name'] === null && $row['is_out_of_scope'] === true && $row['id_archive_folder'] === null && $row['result'] === 'not_found', 'not_found: folder_name null, is_out_of_scope true');
                    $t->true($row['id_archive'] !== null && $row['transaction_no'] !== null, 'not_found: id_archive & transaction_no terisi');
                }
                $un = q26_all_docs($t, $s, $id, 'unscanned', 100);
                $t->eq($codes($un), $unscanned, 'unscanned: dokumen cakupan belum discan, urut code');
                foreach ($un as $row) {
                    $t->true($row['result'] === 'unscanned' && $row['scanned_at'] === null && $row['id_archive_opname_document'] === null && $row['is_verified_after'] === null, 'unscanned (draft): scanned_at null, id baris null');
                    $t->true($row['id_archive'] !== null && $row['id_archive_folder'] !== null && $row['folder_name'] !== null && $row['result_label'] === 'Belum discan', 'unscanned: id_archive, folder, label');
                }
                $inv = q26_all_docs($t, $s, $id, 'invalid', 100);
                $t->eq(count($inv), 1, 'invalid: 1 baris');
                $t->true($inv[0]['id_archive'] === null && $inv[0]['folder_name'] === null && $inv[0]['transaction_no'] === null, 'invalid: id_archive null, folder_name null');

                // paginasi: pagination=2 -> halaman, from/to, next/prev
                $p1 = q26_docs($t, $s, $id, ['result' => 'all', 'pagination' => 2, 'page' => 1])[1]['result'];
                $p2 = q26_docs($t, $s, $id, ['result' => 'all', 'pagination' => 2, 'page' => 2])[1]['result'];
                $p9 = q26_docs($t, $s, $id, ['result' => 'all', 'pagination' => 2, 'page' => 9])[1]['result'];
                $t->eq([$p1['current_page'], $p1['per_page'], $p1['last_page'], $p1['total'], $p1['from'], $p1['to']], [1, 2, 4, 8, 1, 2], 'halaman 1: meta');
                $t->true($p1['prev_page_url'] === null && $p1['next_page_url'] !== null, 'halaman 1: prev null, next terisi');
                $t->eq([$p2['current_page'], $p2['from'], $p2['to']], [2, 3, 4], 'halaman 2: meta');
                $t->true(array_intersect($codes($p1['data']), $codes($p2['data'])) === [], 'halaman 1 dan 2 tidak tumpang tindih');
                $t->eq(array_merge($codes($p1['data']), $codes($p2['data'])), array_slice(array_merge(array_reverse($order), $unscanned), 0, 4), 'halaman 1-2 = 4 baris pertama');
                $t->eq($p9['data'], [], 'halaman di luar jangkauan: data kosong');
                // halaman yang melintasi batas scan/unscanned
                $p4 = q26_docs($t, $s, $id, ['result' => 'all', 'pagination' => 5, 'page' => 2])[1]['result'];
                $t->eq($codes($p4['data']), array_slice(array_merge(array_reverse($order), $unscanned), 5, 3), 'halaman melintasi batas scan/belum discan');

                // result tak dikenal -> 422
                $r = q26_docs($t, $s, $id, ['result' => 'xyz']);
                q26_no500($t, $r, 'result tak dikenal');
                $t->status($r, 422, 'result tak dikenal -> 422');
                $r = $t->call($s, 'GET', Q26_BASE . '/opnames/' . $id . '/documents?result[]=all');
                q26_no500($t, $r, 'result array');
                $t->status($r, 422, 'result array -> 422');

                // --- sesudah Confirm: baca dari snapshot
                $conf = q26_confirm($t, $s, $id);
                $t->status($conf, 200, 'confirm');
                foreach ($expectedFilters as $filter => $n) {
                    $r = q26_docs($t, $s, $id, ['result' => $filter, 'pagination' => 50]);
                    $t->status($r, 200, "snapshot $filter");
                    $t->eq($r[1]['result']['total'], $n, "snapshot $filter: total");
                    $t->true(!array_key_exists('columns', $r[1]['result']) && !array_key_exists('queries', $r[1]['result']), "snapshot $filter: tanpa columns/queries");
                }
                $un = q26_all_docs($t, $s, $id, 'unscanned', 100);
                $t->eq($codes($un), $unscanned, 'snapshot unscanned: isi & urut code');
                foreach ($un as $row) {
                    $t->true($row['id_archive_opname_document'] !== null && $row['scanned_at'] === null && $row['is_verified_after'] === false && $row['result'] === 'unscanned', 'snapshot unscanned: id baris terisi, scanned_at null, is_verified_after false');
                    $t->true($row['transaction_no'] !== null && $row['folder_name'] !== null, 'snapshot unscanned: transaction_no & folder_name');
                }
                $ver = q26_all_docs($t, $s, $id, 'verified', 100);
                foreach ($ver as $row) {
                    $t->true($row['is_verified_after'] === true && $row['folder_name'] !== null, 'snapshot verified: is_verified_after true, folder_name');
                }
                $nf = q26_all_docs($t, $s, $id, 'not_found', 100);
                foreach ($nf as $row) {
                    $t->true($row['is_verified_after'] === false && $row['folder_name'] === null && $row['is_out_of_scope'] === true, 'snapshot not_found: is_verified_after = nilai dokumen (false), folder_name null');
                }
                $inv = q26_all_docs($t, $s, $id, 'invalid', 100);
                $t->true($inv[0]['is_verified_after'] === null, 'snapshot invalid: is_verified_after null');
                $rows = q26_all_docs($t, $s, $id, 'all', 100);
                $t->eq($codes($rows), array_merge(array_reverse($order), $unscanned), 'snapshot all: urutan scan terbaru dulu, lalu belum discan per code');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'EXTRA-SCAN',
        'title' => '(tambahan AC-10, kontrak §5) validasi code: kosong / hanya spasi / > 255 / non-Latin-1 / array -> 422; 255 karakter OK (invalid); tanpa 500; sesi tidak berubah oleh 422',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $id = q26_session($t, $s, $x['F'], []);
                $count = function () use ($t, $id) {
                    return $t->db()->table('archive_opname_documents')->where('id_archive_opname', $id)->count();
                };
                $B = Q26_BASE . '/opnames/' . $id . '/scan';
                foreach ([
                    'tanpa code' => [],
                    'code null' => ['code' => null],
                    'code kosong' => ['code' => ''],
                    'code hanya spasi' => ['code' => '    '],
                    'code 256 karakter' => ['code' => str_repeat('a', 256)],
                    'code kanji' => ['code' => '日本語'],
                    'code emoji' => ['code' => "QA26 \xF0\x9F\x98\x80"],
                    'code array' => ['code' => ['a']],
                    'code objek' => ['code' => ['x' => 'y']],
                    'code angka' => ['code' => 12345],
                ] as $label => $body) {
                    $r = $t->call($s, 'POST', $B, $body);
                    q26_no500($t, $r, $label);
                    if ($label === 'code angka') {
                        // string rule: angka JSON bukan string -> 422
                        $t->status($r, 422, $label);
                    } else {
                        $t->status($r, 422, $label);
                    }
                }
                $t->eq($count(), 0, 'validasi 422: tidak ada baris tersimpan');
                // 255 karakter lolos
                $code255 = str_repeat('Z', 255);
                $r = q26_scan($t, $s, $id, $code255);
                $t->status($r, 200, 'code 255 karakter');
                $t->eq($r[1]['result']['row']['result'], 'invalid', 'code 255: invalid');
                $t->eq($t->db()->table('archive_opname_documents')->where('id_archive_opname', $id)->value('code'), $code255, 'DB: code 255 tersimpan utuh');
                // Latin-1 beraksen (é) lolos sebagai teks
                $r = q26_scan($t, $s, $id, "caf\xC3\xA9");
                q26_no500($t, $r, 'Latin-1 beraksen');
                $t->status($r, 200, 'code Latin-1 (é) dengan UTF-8 JSON -> 200 invalid');
                $r = q26_scan($t, $s, $id, "caf\xC3\x89");
                q26_no500($t, $r, 'Latin-1 beraksen huruf besar');
                $t->status($r, 200, 'code É');
                // karakter kontrol & delimiter di tengah kode: tanpa 500 (200 invalid atau 422)
                foreach (["a\x01b", "a\x7Fb", "a\tb", "a\nb", "a\0b", "a\x1Fb", "a\\' OR '1'='1", "%", "_", "a b  c", "\xC2\xA0nbsp\xC2\xA0", "\xC3\xBF\xC3\xBE", "a\"b"] as $odd) {
                    $r = q26_scan($t, $s, $id, $odd);
                    q26_no500($t, $r, 'kode aneh ' . json_encode($odd));
                    $t->true(in_array($r[0], [200, 422], true), 'kode aneh ' . json_encode($odd) . ': 200/422');
                    if ($r[0] === 200) {
                        $t->eq($r[1]['result']['row']['result'], 'invalid', 'kode aneh ' . json_encode($odd) . ': invalid');
                    }
                }
                // sesi id aneh: tanpa 500
                foreach ([rawurlencode('日本語'), rawurlencode(str_repeat('a', 300)), rawurlencode("' OR 1=1 --"), '0', 'folders'] as $badId) {
                    $r = $t->call($s, 'POST', Q26_BASE . '/opnames/' . $badId . '/scan', ['code' => 'x']);
                    q26_no500($t, $r, "id sesi '$badId' scan");
                    $r = $t->call($s, 'GET', Q26_BASE . '/opnames/' . $badId);
                    q26_no500($t, $r, "id sesi '$badId' show");
                    $r = $t->call($s, 'GET', Q26_BASE . '/opnames/' . $badId . '/documents');
                    q26_no500($t, $r, "id sesi '$badId' documents");
                    $r = $t->call($s, 'PUT', Q26_BASE . '/opnames/' . $badId . '/confirm');
                    q26_no500($t, $r, "id sesi '$badId' confirm");
                    $r = $t->call($s, 'DELETE', Q26_BASE . '/opnames/' . $badId);
                    q26_no500($t, $r, "id sesi '$badId' cancel");
                }
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'EXTRA-ORDER',
        'title' => '(tambahan AC-16, kontrak §6) scan beruntun dalam detik yang sama: GET documents urut scan terbaru dulu (tie-break scanned_at sedetik)',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $F = q26_folder($t, 'F');
                $names = [];
                for ($i = 0; $i < 6; $i++) {
                    $names[] = q26_n($t, q26_doc($t, 'R' . $i, ['parent' => $F]));
                }
                $bad = 0;
                $sameSecond = 0;
                for ($round = 0; $round < 3; $round++) {
                    $id = q26_session($t, $s, $F, []);
                    $times = [];
                    foreach ($names as $n) {
                        $r = q26_scan($t, $s, $id, $n);
                        $times[] = $r[1]['result']['row']['scanned_at'];
                    }
                    $rows = q26_all_docs($t, $s, $id, 'scanned', 100);
                    $got = array_map(function ($r) {
                        return $r['code'];
                    }, $rows);
                    $sameSecond += 6 - count(array_unique($times));
                    if ($got !== array_reverse($names)) {
                        $bad++;
                    }
                    q26_cancel($t, $s, $id);
                }
                $t->true($sameSecond > 0, 'prasyarat: ada scan di detik yang sama');
                $t->eq($bad, 0, "urutan scan terbaru dulu konsisten ($bad dari 3 sesi urutannya acak untuk scan sedetik)");
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'EXTRA-PAGING',
        'title' => '(tambahan AC-16) pagination/page tidak lazim pada GET documents (draft & terkonfirmasi): tanpa 500; nilai tak valid jatuh ke bawaan',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $id = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                q26_scan($t, $s, $id, q26_n($t, $x['dF1']));
                q26_scan($t, $s, $id, 'QA26-PAGING');
                $variants = [
                    ['pagination' => '0'], ['pagination' => '-1'], ['pagination' => 'abc'], ['pagination' => ''], ['pagination' => '1000000'],
                    ['page' => '0'], ['page' => '-3'], ['page' => 'abc'], ['page' => '99999999999999999999'], ['page' => ''],
                    ['pagination' => '1', 'page' => '3'], ['pagination' => '2.5'], ['pagination' => '1e3'],
                ];
                foreach (['draft', 'confirmed'] as $phase) {
                    if ($phase === 'confirmed') {
                        $t->status(q26_confirm($t, $s, $id), 200, 'confirm');
                    }
                    foreach ($variants as $q) {
                        foreach (['all', 'scanned', 'unscanned'] as $result) {
                            $r = q26_docs($t, $s, $id, ['result' => $result] + $q);
                            q26_no500($t, $r, "$phase " . json_encode($q) . " $result");
                            $t->true(in_array($r[0], [200, 422], true), "$phase " . json_encode($q) . " $result: 200/422 (HTTP {$r[0]})");
                            if ($r[0] === 200) {
                                $t->true(is_array($r[1]['result']['data']) && isset($r[1]['result']['total']), "$phase " . json_encode($q) . " $result: bentuk paginator");
                            }
                        }
                    }
                }
            } finally {
                q26_cleanup($t);
            }
        },
    ],
];
