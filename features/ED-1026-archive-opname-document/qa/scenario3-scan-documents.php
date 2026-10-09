<?php
/**
 * ED-1026 (rev: opname tanpa draft) - scan & tabel hasil: AC-10 (POST opnames/scan: Verified / Not found / Invalid, tanpa
 * simpan), AC-11 (bagian BE: kode ganda dihitung sekali di pratinjau dan Konfirmasi), AC-16 (POST opnames/preview/documents:
 * 6 filter, paginator, urutan; sama dengan GET opnames/{id}/documents sesudah Konfirmasi), EXTRA-SCAN (masukan scan aneh
 * tanpa 500), EXTRA-PAGING (page/pagination tak lazim). Urutan scan lama dari EXTRA-ORDER dicakup R2-D2 (scenario7).
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-10',
        'title' => 'POST opnames/scan: dokumen di folder tercakup -> verified; folder lain / root / is_active -1 & 0 / lokasi tak terlihat / subfolder tak dicentang -> not_found (is_out_of_scope); kode tak dikenal / nama folder -> invalid; scanned_at = waktu server; tanpa baris DB baru dan tanpa perubahan archives',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $N = function ($k) use ($t, $x) {
                    return q26_n($t, $x[$k]);
                };
                $sel = q26_selection($x['F'], q26_sel([$x['S1']]));
                $before = q26_state($t);

                $scan = function ($code, $result, $label, $extra = [], array $selection = null) use ($t, $s, $sel) {
                    $r = q26_scan($t, $s, $selection ?? $sel, $code);
                    $t->status($r, 200, "scan $label");
                    $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE212', "scan $label: ARCHIVE212");
                    $t->eq(array_keys($r[1]['result']), ['row'], "scan $label: result hanya row");
                    $row = $r[1]['result']['row'];
                    $t->eq($row['result'], $result, "scan $label: result");
                    foreach ($extra as $k => $v) {
                        $t->true($row[$k] === $v, "scan $label: $k = " . json_encode($v));
                    }

                    return $r;
                };

                // --- Verified
                $r = $scan($N('dF1'), 'verified', 'dokumen langsung di F');
                $row = $r[1]['result']['row'];
                $t->eq(array_keys($row), ['id_archive_opname_document', 'id_archive', 'code', 'transaction_no', 'transaction_type', 'transaction_type_label', 'salesman', 'id_archive_folder', 'folder_name', 'is_out_of_scope', 'result', 'result_label', 'is_verified_after', 'scanned_at'], 'row: kunci sesuai kontrak (document row)');
                $t->true($row['id_archive_opname_document'] === null, 'row: id_archive_opname_document null (belum tersimpan)');
                $t->eq($row['id_archive'], $x['dF1'], 'row: id_archive');
                $t->eq($row['transaction_no'], $N('dF1'), 'row: transaction_no');
                $t->true($row['transaction_type'] === 6 && $row['transaction_type_label'] === 'Pesanan Penjualan', 'row: transaction_type 6 + label bahasa user');
                $t->eq($row['salesman'], 'Budi', 'row: salesman = related_employee_name');
                $t->eq($row['id_archive_folder'], $x['F'], 'row: id_archive_folder = F');
                $t->eq($row['folder_name'], q26_n($t, $x['F']), 'row: folder_name');
                $t->true($row['is_out_of_scope'] === false && $row['result_label'] === 'Terverifikasi' && $row['is_verified_after'] === null, 'row: is_out_of_scope false, label, is_verified_after null (belum disimpan)');
                $at = new \DateTime($row['scanned_at'], new \DateTimeZone('Asia/Jakarta'));
                $t->true(preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row['scanned_at']) === 1 && abs($at->getTimestamp() - time()) <= 15, 'row: scanned_at = waktu server Y-m-d H:i:s (' . $row['scanned_at'] . ')');
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

                // scanned_at dari klien diabaikan (waktu server)
                $r = $t->call($s, 'POST', Q26_BASE . '/opnames/scan', $sel + ['code' => 'QA26-WAKTU-KLIEN', 'scanned_at' => '2000-01-01 00:00:00']);
                $t->status($r, 200, 'scan dengan scanned_at dari klien');
                $t->true(strtotime($r[1]['result']['row']['scanned_at']) > strtotime('2020-01-01'), 'scanned_at klien diabaikan (waktu server)');

                // --- scan tidak menulis apa pun (BR-21/BR-24)
                $t->true(q26_same_state($before, q26_state($t)), 'scan tidak menulis: tabel opname + kolom verifikasi archives sama');
                foreach (['d21', 'dO1', 'dR', 'dNEG', 'dDEL', 'dF1', 'dF2', 'd11'] as $k) {
                    $t->eq(q26_v($t, $x[$k]), [0, false, null, null], "scan tidak mengubah verifikasi $k");
                }

                // --- user Semarang: dokumen lokasi JOG di F tak terlihat -> not found; dokumen lain verified; pratinjau mengikuti
                q26_with_user($t, ['emp' => ['SMR']], function () use ($t, $s, $x, $N, $sel, $scan) {
                    $r = q26_scan($t, $s, $sel, $N('dJF'));
                    $t->status($r, 200, 'Semarang: scan dJF');
                    $t->eq($r[1]['result']['row']['result'], 'not_found', 'Semarang: dokumen lokasi JOG di F -> not_found (tak terlihat)');
                    $r = q26_scan($t, $s, $sel, $N('dj1'));
                    $t->eq($r[1]['result']['row']['result'], 'not_found', 'Semarang: dokumen di subfolder JOG -> not_found');
                    $r = q26_scan($t, $s, $sel, $N('dF1'));
                    $t->eq($r[1]['result']['row']['result'], 'verified', 'Semarang: dokumen terlihat di F -> verified');
                    $p = q26_preview($t, $s, $sel);
                    $t->eq($p[1]['result']['counts']['total_documents'], 4, 'Semarang: total = dF1,dF2,d11,dA1 (dJF tak terlihat tidak dihitung)');
                });
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-11',
        'title' => '(bagian BE) Kode ganda (sama persis / beda huruf / spasi tepi / É-é) di pratinjau dan POST opnames dihitung sekali, satu baris snapshot (UNIQUE tidak melanggar); scan sendiri tanpa tulis',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $sel = q26_selection($x['F'], q26_sel([$x['S1']]));
                $name = q26_n($t, $x['d11']);
                $nf = q26_n($t, $x['dR']);
                $now = q26_now()->format('Y-m-d H:i:s');
                $mk = function (array $codes) use ($now) {
                    return array_map(function ($c) use ($now) {
                        return ['code' => $c, 'scanned_at' => $now];
                    }, $codes);
                };

                // scan sendiri bukan tempat deduplikasi (FE yang mengecek): setiap panggilan 200 dengan hasil sama, tanpa tulis
                $before = q26_state($t);
                $a = q26_scan($t, $s, $sel, $name);
                $b = q26_scan($t, $s, $sel, strtoupper($name));
                $t->eq([$a[0], $b[0]], [200, 200], 'scan dua kali: 200');
                $t->eq([$a[1]['result']['row']['result'], $b[1]['result']['row']['result']], ['verified', 'verified'], 'scan dua kali: hasil sama');
                $t->true(q26_same_state($before, q26_state($t)), 'scan ganda tanpa tulis');

                // pratinjau: Verified, Not found, Invalid masing-masing ganda dalam berbagai bentuk
                $codes = [$name, strtoupper($name), strtolower($name), "  $name ", $nf, strtoupper($nf), 'QA26-DUP-INVALID', 'qa26-dup-invalid ', "caf\xC3\xA9", "CAF\xC3\x89", "caf\xC3\xA9 "];
                $p = q26_preview($t, $s, $sel, $mk($codes));
                $t->status($p, 200, 'preview dengan kode ganda');
                $c = $p[1]['result']['counts'];
                $t->eq([$c['scanned'], $c['verified'], $c['not_found'], $c['invalid']], [4, 1, 1, 2], 'preview: kode ganda dihitung sekali (verified 1, not_found 1, invalid 2: DUP-INVALID dan café)');
                $t->eq($p[1]['result']['result_counts']['scanned'], 4, 'preview: result_counts.scanned 4');
                $rows = q26_pall($t, $s, $sel, $mk($codes), 'scanned', 50);
                $t->eq(count($rows), 4, 'preview/documents scanned: 4 baris unik');
                $codesOut = array_column($rows, 'code');
                $t->eq(array_map('strtolower', array_map('rtrim', $codesOut)), array_reverse(array_map('strtolower', ['' . $name, $nf, 'QA26-DUP-INVALID', "caf\xC3\xA9"])), 'preview/documents: urutan scan terbaru dulu, yang PERTAMA dikirim yang dipakai');
                $t->eq($codesOut[count($codesOut) - 1], $name, 'preview/documents: code baris = yang pertama dikirim (bentuk persis)');

                // POST opnames: satu baris snapshot per kode unik
                $selected = q26_selected($t, $s, $x['F']);
                $r = q26_store($t, $s, $sel, $mk($codes), $selected);
                $t->status($r, 200, 'POST opnames dengan kode ganda');
                $id = $r[1]['result']['id_archive_opname'];
                $o = q26_opname($t, $id);
                $t->eq([(int) $o['scanned_count'], (int) $o['verified_count'], (int) $o['not_found_count'], (int) $o['invalid_count']], [4, 1, 1, 2], 'DB: angka sesi menghitung kode ganda sekali');
                $docs = q26_opdocs($t, $id);
                $scannedRows = array_filter($docs, function ($d) {
                    return (int) $d['result'] !== 4;
                });
                $t->eq(count($scannedRows), 4, 'DB: tepat 4 baris scan (satu per kode unik)');
                $t->eq($t->db()->table('archive_opname_documents')->where('id_archive_opname', $id)->whereRaw('LOWER(code) = ?', [strtolower($name)])->count(), 1, 'DB: kode ganda = satu baris');
                $t->eq((int) $t->db()->table('archive_opname_documents')->where('id_archive_opname', $id)->count(), 4 + 4, 'DB: 4 scan + 4 belum discan (dF1, dF2, dJF, dA1)');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-16',
        'title' => 'POST opnames/preview/documents: 6 filter + paginator tanpa columns/queries; Not found folder_name null & is_out_of_scope true; belum discan unscanned (scanned_at null); urutan scan terbaru dulu lalu belum discan per code; scanned_at = nilai dikirim; sama dengan GET opnames/{id}/documents sesudah Konfirmasi',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $N = function ($k) use ($t, $x) {
                    return q26_n($t, $x[$k]);
                };
                $sel = q26_selection($x['F'], q26_sel([$x['S1']]));
                // urutan scan lama -> baru, waktu naik tiap detik (nilai dikirim FE)
                $order = [$N('dF1'), $N('d11'), $N('d21'), $N('dA1'), 'QA26-NOPE', $N('dR')];
                $base = new \DateTime(q26_today() . ' 08:00:00', new \DateTimeZone('Asia/Jakarta'));
                $scans = [];
                $times = [];
                foreach ($order as $i => $code) {
                    $d = clone $base;
                    $d->modify("+$i seconds");
                    $times[$code] = $d->format('Y-m-d H:i:s');
                    $scans[] = ['code' => $code, 'scanned_at' => $times[$code]];
                }
                // dalam cakupan (role 3, semua lokasi): dF1, dF2, dJF, d11, dA1 = 5; verified 3 (dF1,d11,dA1); belum discan 2 (dF2,dJF)
                $unscanned = [$N('dF2'), $N('dJF')];
                sort($unscanned, SORT_STRING | SORT_FLAG_CASE);
                $codes = function ($rows) {
                    return array_map(function ($r) {
                        return $r['code'];
                    }, $rows);
                };
                $expectedFilters = ['all' => count($order) + 2, 'scanned' => 6, 'verified' => 3, 'not_found' => 2, 'invalid' => 1, 'unscanned' => 2];
                $before = q26_state($t);

                $p = q26_preview($t, $s, $sel, $scans);
                $c = $p[1]['result']['counts'];
                $t->eq([$c['total_documents'], $c['scanned'], $c['verified'], $c['not_found'], $c['invalid'], $c['unscanned']], [5, 6, 3, 2, 1, 2], 'preview.counts');
                $t->eq($p[1]['result']['result_counts'], $expectedFilters, 'preview.result_counts = jumlah tiap filter');

                foreach ($expectedFilters as $filter => $n) {
                    $r = q26_pdocs($t, $s, $sel, $scans, ['result' => $filter]);
                    $t->status($r, 200, "preview/documents $filter");
                    $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE200', "preview/documents $filter: msg_code");
                    $res = $r[1]['result'];
                    $t->eq($res['total'], $n, "preview/documents $filter: total");
                    $t->true(!array_key_exists('columns', $res) && !array_key_exists('queries', $res), "preview/documents $filter: tanpa columns/queries");
                    foreach (['current_page', 'data', 'first_page_url', 'from', 'last_page', 'last_page_url', 'next_page_url', 'path', 'per_page', 'prev_page_url', 'to', 'total'] as $k) {
                        $t->true(array_key_exists($k, $res), "preview/documents $filter: kunci paginator $k");
                    }
                    $all = q26_pall($t, $s, $sel, $scans, $filter, 3);
                    $t->eq(count($all), $n, "preview/documents $filter: jumlah baris semua halaman (pagination 3)");
                }
                // default tanpa result = all; result kosong = all; pagination bawaan 10
                $r = q26_pdocs($t, $s, $sel, $scans);
                $t->eq($r[1]['result']['total'], 8, 'tanpa result = all (8)');
                $t->eq($r[1]['result']['per_page'], 10, 'pagination bawaan 10');
                $r = q26_pdocs($t, $s, $sel, $scans, ['result' => '']);
                $t->eq($r[1]['result']['total'], 8, 'result kosong = all (8)');
                // page/pagination boleh juga lewat query string
                $r = $t->call($s, 'POST', Q26_BASE . '/opnames/preview/documents?result=scanned&pagination=2&page=2', $sel + ['scans' => $scans]);
                $t->status($r, 200, 'result/page/pagination di query string');
                $t->eq([$r[1]['result']['total'], $r[1]['result']['per_page'], $r[1]['result']['current_page']], [6, 2, 2], 'query string: total 6, per_page 2, halaman 2');

                // urutan: scan terbaru dulu (dR, NOPE, dA1, d21, d11, dF1), lalu belum discan per code
                $expectAll = array_merge(array_reverse($order), $unscanned);
                $rows = q26_pall($t, $s, $sel, $scans, 'all', 100);
                $t->eq($codes($rows), $expectAll, 'urutan all: scan terbaru dulu, lalu belum discan per code');
                $rows = q26_pall($t, $s, $sel, $scans, 'scanned', 100);
                $t->eq($codes($rows), array_reverse($order), 'urutan scanned: terbaru dulu');
                foreach ($rows as $row) {
                    $t->eq($row['scanned_at'], $times[$row['code']], 'scanned_at = nilai yang dikirim (' . $row['code'] . ')');
                    $t->true($row['id_archive_opname_document'] === null && $row['is_verified_after'] === null, 'baris pratinjau: id baris & is_verified_after null');
                }
                $nf = q26_pall($t, $s, $sel, $scans, 'not_found', 100);
                $t->eq($codes($nf), [$N('dR'), $N('d21')], 'not_found: isi');
                foreach ($nf as $row) {
                    $t->true($row['folder_name'] === null && $row['is_out_of_scope'] === true && $row['id_archive_folder'] === null && $row['result'] === 'not_found', 'not_found: folder_name null, is_out_of_scope true');
                    $t->true($row['id_archive'] !== null && $row['transaction_no'] !== null, 'not_found: id_archive & transaction_no terisi');
                }
                $un = q26_pall($t, $s, $sel, $scans, 'unscanned', 100);
                $t->eq($codes($un), $unscanned, 'unscanned: dokumen cakupan belum discan, urut code');
                foreach ($un as $row) {
                    $t->true($row['result'] === 'unscanned' && $row['scanned_at'] === null && $row['id_archive_opname_document'] === null && $row['is_verified_after'] === null, 'unscanned: scanned_at null, id baris null');
                    $t->true($row['id_archive'] !== null && $row['id_archive_folder'] !== null && $row['folder_name'] !== null && $row['result_label'] === 'Belum discan', 'unscanned: id_archive, folder, label');
                }
                $inv = q26_pall($t, $s, $sel, $scans, 'invalid', 100);
                $t->eq(count($inv), 1, 'invalid: 1 baris');
                $t->true($inv[0]['id_archive'] === null && $inv[0]['folder_name'] === null && $inv[0]['transaction_no'] === null, 'invalid: id_archive null, folder_name null');

                // paginasi: pagination=2 -> halaman, from/to, next/prev; halaman melintasi batas scan/belum discan
                $p1 = q26_pdocs($t, $s, $sel, $scans, ['result' => 'all', 'pagination' => 2, 'page' => 1])[1]['result'];
                $p2 = q26_pdocs($t, $s, $sel, $scans, ['result' => 'all', 'pagination' => 2, 'page' => 2])[1]['result'];
                $p9 = q26_pdocs($t, $s, $sel, $scans, ['result' => 'all', 'pagination' => 2, 'page' => 9])[1]['result'];
                $t->eq([$p1['current_page'], $p1['per_page'], $p1['last_page'], $p1['total'], $p1['from'], $p1['to']], [1, 2, 4, 8, 1, 2], 'halaman 1: meta');
                $t->true($p1['prev_page_url'] === null && $p1['next_page_url'] !== null, 'halaman 1: prev null, next terisi');
                $t->eq([$p2['current_page'], $p2['from'], $p2['to']], [2, 3, 4], 'halaman 2: meta');
                $t->true(array_intersect($codes($p1['data']), $codes($p2['data'])) === [], 'halaman 1 dan 2 tidak tumpang tindih');
                $t->eq($p9['data'], [], 'halaman di luar jangkauan: data kosong');
                $p4 = q26_pdocs($t, $s, $sel, $scans, ['result' => 'all', 'pagination' => 5, 'page' => 2])[1]['result'];
                $t->eq($codes($p4['data']), array_slice($expectAll, 5, 3), 'halaman melintasi batas scan/belum discan');

                // result tak dikenal -> 422
                foreach (['xyz', ['all'], 5] as $bad) {
                    $r = q26_pdocs($t, $s, $sel, $scans, ['result' => $bad]);
                    q26_no500($t, $r, 'result ' . json_encode($bad));
                    $t->status($r, 422, 'result ' . json_encode($bad) . ' -> 422');
                }
                $t->true(q26_same_state($before, q26_state($t)), 'preview/documents tidak menulis');

                // --- Konfirmasi: GET opnames/{id}/documents (snapshot) = pratinjau, nilai tersimpan
                $selected = q26_selected($t, $s, $x['F']);
                $conf = q26_store($t, $s, $sel, $scans, $selected);
                $t->status($conf, 200, 'Konfirmasi');
                $id = $conf[1]['result']['id_archive_opname'];
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
                foreach (q26_all_docs($t, $s, $id, 'verified', 100) as $row) {
                    $t->true($row['is_verified_after'] === true && $row['folder_name'] !== null, 'snapshot verified: is_verified_after true, folder_name');
                }
                foreach (q26_all_docs($t, $s, $id, 'not_found', 100) as $row) {
                    $t->true($row['is_verified_after'] === false && $row['folder_name'] === null && $row['is_out_of_scope'] === true, 'snapshot not_found: is_verified_after = nilai dokumen (false), folder_name null');
                }
                $inv = q26_all_docs($t, $s, $id, 'invalid', 100);
                $t->true($inv[0]['is_verified_after'] === null, 'snapshot invalid: is_verified_after null');
                $rows = q26_all_docs($t, $s, $id, 'all', 100);
                $t->eq($codes($rows), $expectAll, 'snapshot all: urutan scan terbaru dulu, lalu belum discan per code (sama dengan pratinjau)');
                foreach (q26_all_docs($t, $s, $id, 'scanned', 100) as $row) {
                    $t->eq($row['scanned_at'], $times[$row['code']], 'snapshot scanned_at = nilai dikirim (' . $row['code'] . ')');
                }
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'EXTRA-SCAN',
        'title' => '(tambahan AC-9/AC-10) validasi code scan: kosong / hanya spasi / > 255 / non-Latin-1 / array -> 422; 255 karakter OK (invalid); karakter aneh tanpa 500; scans.*.code di pratinjau/POST idem; tanpa tulis',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $sel = q26_selection($x['F']);
                $now = q26_now()->format('Y-m-d H:i:s');
                $before = q26_state($t);
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
                    $r = $t->call($s, 'POST', Q26_BASE . '/opnames/scan', $sel + $body);
                    q26_no500($t, $r, $label);
                    $t->status($r, 422, $label);
                }
                // 255 karakter lolos
                $code255 = str_repeat('Z', 255);
                $r = q26_scan($t, $s, $sel, $code255);
                $t->status($r, 200, 'code 255 karakter');
                $t->eq($r[1]['result']['row']['result'], 'invalid', 'code 255: invalid');
                $t->eq($r[1]['result']['row']['code'], $code255, 'code 255: dikembalikan utuh');
                // Latin-1 beraksen (é, É) lolos sebagai teks
                foreach (["caf\xC3\xA9", "caf\xC3\x89", "\xC3\xBF\xC3\xBE", "\xC2\xA0nbsp\xC2\xA0"] as $odd) {
                    $r = q26_scan($t, $s, $sel, $odd);
                    q26_no500($t, $r, 'Latin-1 ' . json_encode($odd));
                    $t->status($r, 200, 'code Latin-1 ' . json_encode($odd) . ' -> 200 invalid');
                }
                // karakter kontrol & delimiter di tengah kode: tanpa 500 (200 invalid atau 422)
                foreach (["a\x01b", "a\x7Fb", "a\tb", "a\nb", "a\0b", "a\x1Fb", "a\\' OR '1'='1", '%', '_', 'a b  c', 'a"b'] as $odd) {
                    $r = q26_scan($t, $s, $sel, $odd);
                    q26_no500($t, $r, 'kode aneh ' . json_encode($odd));
                    $t->true(in_array($r[0], [200, 422], true), 'kode aneh ' . json_encode($odd) . ': 200/422');
                    if ($r[0] === 200) {
                        $t->eq($r[1]['result']['row']['result'], 'invalid', 'kode aneh ' . json_encode($odd) . ': invalid');
                    }
                }
                // scans.*.code yang sama di preview / preview/documents / POST opnames
                $selected = q26_selected($t, $s, $x['F']);
                foreach (['kosong' => '', '256' => str_repeat('a', 256), 'kanji' => '日本語', 'array' => ['a']] as $label => $badCode) {
                    $body = $sel + ['scans' => [['code' => $badCode, 'scanned_at' => $now]]];
                    foreach (['preview', 'preview/documents'] as $path) {
                        $r = $t->call($s, 'POST', Q26_BASE . "/opnames/$path", $body);
                        q26_no500($t, $r, "$path scans.code $label");
                        $t->status($r, 422, "$path scans.code $label -> 422");
                    }
                    $r = $t->call($s, 'POST', Q26_BASE . '/opnames', $body + ['selected_at' => $selected]);
                    q26_no500($t, $r, "POST opnames scans.code $label");
                    $t->status($r, 422, "POST opnames scans.code $label -> 422");
                }
                // kode 255 dan Latin-1 melewati POST opnames (bertahan UNIQUE) dan tersimpan utuh
                $scans = [['code' => $code255, 'scanned_at' => $now], ['code' => "caf\xC3\xA9", 'scanned_at' => $now]];
                $r = q26_store($t, $s, $sel, $scans, $selected);
                $t->status($r, 200, 'POST opnames dengan kode 255 karakter dan Latin-1');
                $id = $r[1]['result']['id_archive_opname'];
                $t->eq($t->db()->table('archive_opname_documents')->where('id_archive_opname', $id)->where('result', 3)->orderBy('code')->pluck('code')->all(), ["caf\xC3\xA9", $code255], 'DB: code 255 karakter tersimpan utuh; Latin-1 (é) tersimpan (koneksi membaca UTF-8)');
                $t->true(q26_same_state($before, q26_state($t)) === false, 'sesi POST valid tersimpan (kontrol)');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'EXTRA-PAGING',
        'title' => '(tambahan AC-16) pagination/page tidak lazim pada preview/documents dan GET opnames/{id}/documents: tanpa 500; nilai tak valid jatuh ke bawaan',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $sel = q26_selection($x['F'], q26_sel([$x['S1']]));
                $now = q26_now()->format('Y-m-d H:i:s');
                $scans = [['code' => q26_n($t, $x['dF1']), 'scanned_at' => $now], ['code' => 'QA26-PAGING', 'scanned_at' => $now]];
                $variants = [
                    ['pagination' => '0'], ['pagination' => '-1'], ['pagination' => 'abc'], ['pagination' => ''], ['pagination' => '1000000'],
                    ['page' => '0'], ['page' => '-3'], ['page' => 'abc'], ['page' => '99999999999999999999'], ['page' => ''],
                    ['pagination' => '1', 'page' => '3'], ['pagination' => '2.5'], ['pagination' => '1e3'],
                ];
                foreach ($variants as $q) {
                    foreach (['all', 'scanned', 'unscanned'] as $result) {
                        $r = q26_pdocs($t, $s, $sel, $scans, ['result' => $result] + $q);
                        q26_no500($t, $r, 'preview ' . json_encode($q) . " $result");
                        $t->true(in_array($r[0], [200, 422], true), 'preview ' . json_encode($q) . " $result: 200/422 (HTTP {$r[0]})");
                        if ($r[0] === 200) {
                            $t->true(is_array($r[1]['result']['data']) && isset($r[1]['result']['total']), 'preview ' . json_encode($q) . " $result: bentuk paginator");
                        }
                    }
                }
                $selected = q26_selected($t, $s, $x['F']);
                $conf = q26_store($t, $s, $sel, $scans, $selected);
                $t->status($conf, 200, 'Konfirmasi');
                $id = $conf[1]['result']['id_archive_opname'];
                foreach ($variants as $q) {
                    foreach (['all', 'scanned', 'unscanned'] as $result) {
                        $r = q26_docs($t, $s, $id, ['result' => $result] + $q);
                        q26_no500($t, $r, 'terkonfirmasi ' . json_encode($q) . " $result");
                        $t->true(in_array($r[0], [200, 422], true), 'terkonfirmasi ' . json_encode($q) . " $result: 200/422 (HTTP {$r[0]})");
                        if ($r[0] === 200) {
                            $t->true(is_array($r[1]['result']['data']) && isset($r[1]['result']['total']), 'terkonfirmasi ' . json_encode($q) . " $result: bentuk paginator");
                        }
                    }
                }
            } finally {
                q26_cleanup($t);
            }
        },
    ],
];
