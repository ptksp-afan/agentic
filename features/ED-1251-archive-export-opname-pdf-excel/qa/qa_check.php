<?php
/**
 * Helper pemeriksaan isi berkas ED-1251 (BUKAN skenario): ekspektasi header/tanggal/baris dan pemecah tabel PDF/XLSX.
 * Memakai qa_lib.php dan qa_files.php.
 */
require_once __DIR__ . '/qa_lib.php';
require_once __DIR__ . '/qa_files.php';

if (!function_exists('q251_norm')) {

    /** GET select/report/document-archive/opname/<x> */
    function q251_sget($t, $s, $x, array $q = [])
    {
        return $t->call($s, 'GET', q251_url(Q251_SEL . $x, $q));
    }

    /** Ligatur mPDF (ﬁ/ﬂ) -> huruf biasa, supaya teks PDF bisa dibandingkan dengan teks DB. */
    function q251_norm($s)
    {
        return str_replace(["\u{FB01}", "\u{FB02}", "\u{FB00}"], ['fi', 'fl', 'ff'], $s);
    }

    function q251_norm_all(array $a)
    {
        return array_map('q251_norm', $a);
    }

    function q251_months()
    {
        return [
            'en' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            'id' => ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'],
        ];
    }

    /** "d M Y H:i" dari teks DB confirmed_at (tanpa konversi zona), bulan menurut bahasa. */
    function q251_date_text($raw, $lang)
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})/', $raw, $m)) {
            throw new \RuntimeException("confirmed_at tak terbaca: $raw");
        }

        return sprintf('%02d %s %04d %s:%s', $m[3], q251_months()[$lang][(int) $m[2] - 1], $m[1], $m[4], $m[5]);
    }

    /** Nama berkas dasar d-M-Y_His (bulan Inggris) dari teks DB. */
    function q251_file_stem($raw)
    {
        $m = [];
        preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})/', $raw, $m);

        return sprintf('Opname_Archive_%02d-%s-%04d_%s%s%s', $m[3], q251_months()['en'][(int) $m[2] - 1], $m[1], $m[4], $m[5], $m[6]);
    }

    /** Baris header yang diharapkan (bahasa $lang). */
    function q251_head_expect($t, $lang, $scope, $raw, $by, $partial = false)
    {
        $lbl = [
            'en' => ['Opname Archive', 'Folder', 'Opname Date', 'Opname By', 'Some documents outside your access are not shown'],
            'id' => ['Opname Arsip', 'Folder', 'Tanggal Opname', 'Diopname Oleh', 'Sebagian dokumen di luar akses Anda tidak ditampilkan'],
        ][$lang];
        $out = [
            $t->db()->table('companies')->value('company_name'),
            $lbl[0],
            $lbl[1] . ': ' . $scope,
            $lbl[2] . ': ' . q251_date_text($raw, $lang),
            $lbl[3] . ': ' . $by,
        ];
        if ($partial) {
            $out[] = $lbl[4];
        }

        return $out;
    }

    /** Label hasil per bahasa (RESULT_LABELS) dan judul kolom default per bahasa. */
    function q251_titles($lang)
    {
        return [
            'en' => [
                'sum' => 'Summaries', 'det' => 'Detail',
                'sumcols' => ['Total Documents', 'Verified', 'Not Found', 'Invalid', 'Not Scanned'],
                'detcols' => ['Document No.', 'Transaction Type', 'Folder', 'Time', 'Result'],
                'root' => 'All Archive', 'out' => 'Outside opname scope',
            ],
            'id' => [
                'sum' => 'Ringkasan', 'det' => 'Rincian',
                'sumcols' => ['Total Dokumen', 'Terverifikasi', 'Tidak Ditemukan', 'Tidak Valid', 'Belum Discan'],
                'detcols' => ['No. Dokumen', 'Tipe Transaksi', 'Folder', 'Waktu', 'Hasil'],
                'root' => 'Semua Arsip', 'out' => 'Di luar scope opname',
            ],
        ][$lang];
    }

    /** Semua halaman GET opnames/{id}/documents?result=all (pagination kecil) -> daftar baris API berurutan. */
    function q251_api_rows($t, $s, $id, $per = 3, $result = 'all')
    {
        $rows = [];
        for ($page = 1; $page < 200; $page++) {
            $r = $t->call($s, 'GET', q251_url(Q251_BASE . '/opnames/' . $id . '/documents', ['result' => $result, 'pagination' => $per, 'page' => $page]));
            $t->status($r, 200, "documents hal $page");
            foreach ($r[1]['result']['data'] ?? [] as $row) {
                $rows[] = $row;
            }
            if ($page >= (int) ($r[1]['result']['last_page'] ?? 1)) {
                break;
            }
        }

        return $rows;
    }

    /** Baris API -> sel Rincian [code, tipe, folder, waktu, hasil] (BR-13) menurut bahasa. */
    function q251_expect_rows(array $api, $lang)
    {
        $ti = q251_titles($lang);
        $out = [];
        foreach ($api as $r) {
            if ($r['result'] === 'not_found') {
                $folder = $ti['out'];
            } elseif ($r['result'] === 'invalid') {
                $folder = '—';
            } elseif ($r['folder_name'] === null || $r['folder_name'] === '') {
                $folder = $ti['root'];
            } else {
                $folder = $r['folder_name'];
            }
            $out[] = [
                $r['code'],
                ($r['transaction_type_label'] === null || $r['transaction_type_label'] === '') ? '—' : $r['transaction_type_label'],
                $folder,
                $r['scanned_at'] === null ? '—' : substr($r['scanned_at'], 11, 5),
                $r['result_label'],
            ];
        }

        return $out;
    }

    /**
     * Teks sel tabel Excel sesudah mesin XLS bersama (trait XlsReport::extractText = trim(html_entity_decode(strip_tags))).
     * PDF memuat teks apa adanya; hanya sel Excel yang dilewatkan ke sini bila kode dokumen berisi tag/entity HTML.
     */
    function q251_xl_text($v)
    {
        return trim(html_entity_decode(strip_tags((string) $v), ENT_QUOTES, 'UTF-8'));
    }

    function q251_xl_rows(array $rows)
    {
        return array_map(function ($r) {
            return array_map('q251_xl_text', $r);
        }, $rows);
    }

    /** Angka "n.nn" gaya number_precision 2 / pemisah ribuan "," (sama dengan app config api_sidomaju). */
    function q251_num($n)
    {
        return number_format((float) $n, 2, '.', ',');
    }

    /**
     * PDF 1 halaman -> bagian. $nSum/$nDet = jumlah kolom. header = teks sebelum judul Ringkasan (termasuk footer: tampil dulu di stream).
     * @return array{head: array, sumcols: array, sumvals: array, detcols: array, rows: array}
     */
    function q251_pdf_parts(array $texts, $sumTitle, $detTitle, $nSum, $nDet)
    {
        $texts = q251_norm_all($texts);
        $i = array_search($sumTitle, $texts, true);
        $j = array_search($detTitle, $texts, true);
        if ($i === false || $j === false) {
            return ['head' => $texts, 'sumcols' => [], 'sumvals' => [], 'detcols' => [], 'rows' => [], 'found' => false];
        }
        $rest = array_slice($texts, $j + 1);

        return [
            'found' => true,
            'head' => array_slice($texts, 0, $i),
            'sumcols' => array_slice($texts, $i + 1, $nSum),
            'sumvals' => array_slice($texts, $i + 1 + $nSum, $nSum),
            'detcols' => array_slice($rest, 0, $nDet),
            'rows' => $nDet > 0 ? array_chunk(array_slice($rest, $nDet), $nDet) : [],
        ];
    }

    /**
     * XLSX -> bagian. Baris judul tabel dicari lewat teks kolom A.
     * @return array{head: array, sumcols: array, sumvals: array, detcols: array, rows: array}
     */
    function q251_xl_parts(array $xl, $sumTitle, $detTitle)
    {
        $rows = $xl['rows'];
        ksort($rows);
        $nums = array_keys($rows);
        $find = function ($title) use ($rows) {
            foreach ($rows as $r => $cols) {
                if (($cols['A']['v'] ?? null) === $title && count(array_filter($cols, function ($c) {
                    return $c['v'] !== null && $c['v'] !== '';
                })) === 1) {
                    return $r;
                }
            }

            return null;
        };
        $vals = function ($r) use ($rows) {
            $out = [];
            foreach ($rows[$r] ?? [] as $col => $cell) {
                if ($cell['v'] !== null && $cell['v'] !== '') {
                    $out[] = $cell['v'];
                }
            }

            return $out;
        };
        $rs = $find($sumTitle);
        $rd = $find($detTitle);
        $head = [];
        if ($rs !== null) {
            foreach ($nums as $r) {
                if ($r < $rs) {
                    $head = array_merge($head, $vals($r));
                }
            }
        }
        $detRows = [];
        if ($rd !== null) {
            foreach ($nums as $r) {
                if ($r > $rd + 1) {
                    $v = $vals($r);
                    if ($v) {
                        $detRows[] = $v;
                    }
                }
            }
        }

        return [
            'found' => $rs !== null && $rd !== null,
            'head' => $head,
            'sumcols' => $rs !== null ? $vals($rs + 1) : [],
            'sumvals' => $rs !== null ? $vals($rs + 2) : [],
            'detcols' => $rd !== null ? $vals($rd + 1) : [],
            'rows' => $detRows,
            'sumrow' => $rs !== null ? $rs + 2 : null,
            'detfirst' => $rd !== null ? $rd + 2 : null,
        ];
    }

    /** Status/Header dasar unduhan: 200, content-type, filename, expose header. */
    function q251_assert_file($t, $r, $kind, $stem, $label)
    {
        $t->status([$r[0]], 200, $label . ' HTTP');
        $h = $r[2];
        if ($kind === 'pdf') {
            $t->eq($h['content-type'] ?? null, 'application/pdf', $label . ' content-type');
            $t->true(strpos($r[1], '%PDF-') === 0, $label . ' berkas diawali %PDF-');
        } else {
            $t->true(strpos($h['content-type'] ?? '', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') === 0, $label . ' content-type spreadsheet (' . ($h['content-type'] ?? '-') . ')');
            $t->true(strpos($r[1], 'PK') === 0, $label . ' berkas ZIP (PK)');
        }
        $t->eq($h['content-disposition'] ?? null, 'attachment; filename="' . $stem . '.' . ($kind === 'pdf' ? 'pdf' : 'xlsx') . '"', $label . ' Content-Disposition');
        $t->true(stripos($h['access-control-expose-headers'] ?? '', 'Content-Disposition') !== false, $label . ' Access-Control-Expose-Headers memuat Content-Disposition');
    }

    /** Ambil sesi acuan: bind QA_USER2 (U2/R0) -> sesi; log-out di akhir lewat q251_u2_out. */
    function q251_u2_out($t, $s2)
    {
        try {
            $t->call($s2, 'GET', 'api/v5/auth/log-out');
        } catch (\Throwable $e) {
            $t->note('log-out QA_USER2 gagal: ' . $e->getMessage());
        }
    }

    /**
     * Unduh pdf + xls sesi $id sebagai $s dan uraikan. $lang = bahasa berkas yang diharapkan (en/id) untuk judul tabel;
     * $nSum/$nDet = jumlah kolom yang diharapkan di kedua tabel.
     * @return array{rp: array, rx: array, p: array, x: array, pp: array, xp: array}
     */
    function q251_fetch($t, $s, $id, $lang = 'en', $nSum = 5, $nDet = 5)
    {
        $ti = q251_titles($lang);
        $rp = q251_file($t, $s, $id, 'pdf');
        $rx = q251_file($t, $s, $id, 'xls');
        $t->status([$rp[0]], 200, 'GET pdf');
        $t->status([$rx[0]], 200, 'GET xls');
        $p = q251_pdf($rp[1]);
        $x = q251_xlsx($rx[1]);
        $pp = q251_pdf_parts($p['texts'][0] ?? [], $ti['sum'], $ti['det'], $nSum, $nDet);
        $xp = q251_xl_parts($x, $ti['sum'], $ti['det']);

        return ['rp' => $rp, 'rx' => $rx, 'p' => $p, 'x' => $x, 'pp' => $pp, 'xp' => $xp];
    }

    /** Sama persis untuk teks DB: 5 angka rekaman sesi (urut summaries_columns). */
    function q251_counts($t, $id)
    {
        $r = $t->db()->table('archive_opnames')->where('id_archive_opname', $id)->first();

        return [(int) $r->total_documents, (int) $r->verified_count, (int) $r->not_found_count, (int) $r->invalid_count, (int) $r->unscanned_count];
    }

    /** Sesi akar/bulk disisipkan langsung (id QA251BULK*): kolom wajib; $o menimpa. Mengembalikan id. */
    function q251_insert_session($t, $key, array $o = [])
    {
        $id = 'QA251BULK' . $key . substr(str_pad((string) mt_rand(), 10, '0'), 0, 10);
        $now = date('Y-m-d H:i:s');
        $row = $o + [
            'id_archive' => null, 'status' => 2, 'scope_name' => null, 'scope_folder_count' => 0, 'total_documents' => 0,
            'verified_before_count' => 0, 'scanned_count' => 0, 'verified_count' => 0, 'not_found_count' => 0, 'invalid_count' => 0,
            'unscanned_count' => 0, 'unverified_count' => 0, 'selected_at' => $now, 'confirmed_at' => $now, 'created_at' => $now,
            'created_by' => (string) q251_uname($t), 'updated_at' => $now, 'updated_by' => (string) q251_uname($t),
        ];
        q251_w($t, function ($c) use ($id, $row) {
            $c->table('archive_opnames')->insert(['id_archive_opname' => $id] + $row);
        });

        return $id;
    }

    /** Baris dokumen sesi sisipan: [result, id_archive|null, folder|null, code, scanned_at|null]. */
    function q251_insert_rows($t, $idOpname, array $rows)
    {
        $now = date('Y-m-d H:i:s');
        q251_w($t, function ($c) use ($idOpname, $rows, $now) {
            foreach (array_chunk($rows, 500) as $chunk) {
                $batch = [];
                foreach ($chunk as $i => $r) {
                    $batch[] = [
                        'id_archive_opname_document' => 'QA251BULK' . substr(md5($idOpname . '|' . $r[3] . '|' . $r[0]), 0, 21),
                        'id_archive_opname' => $idOpname, 'id_archive' => $r[1], 'id_archive_folder' => $r[2], 'code' => $r[3],
                        'result' => $r[0], 'is_verified_after' => null, 'scanned_at' => $r[4], 'created_at' => $now,
                    ];
                }
                $c->table('archive_opname_documents')->insert($batch);
            }
        });
    }


    /** Kolom dari $cols (list kolom respons) dengan data_index di $keep, urutan $keep. */
    function q251_pick(array $cols, array $keep)
    {
        $by = [];
        foreach ($cols as $c) {
            $di = is_array($c['data_index']) ? $c['data_index'][0] : $c['data_index'];
            $by[$di] = $c;
        }
        $out = [];
        foreach ($keep as $k) {
            $out[] = $by[$k];
        }

        return $out;
    }

    /** Template tersimpan (respons transaction-templates). */
    function q251_tpl($t, $s)
    {
        $r = q251_sget($t, $s, 'transaction-templates');
        $t->status($r, 200, 'transaction-templates');

        return $r[1]['result'];
    }

    /** Body Simpan Customize dari template $tpl; $tables menimpa 'tables'; $over menimpa kunci lain. */
    function q251_save_body(array $tpl, array $tables, array $over = [])
    {
        $body = [
            'transaction_name' => $tpl['transaction_name'],
            'id_transaction_pdf_template' => $tpl['id_transaction_pdf_template'],
            'id_header_pdf_template' => $tpl['id_header_pdf_template'],
            'id_footer_pdf_template' => $tpl['id_footer_pdf_template'],
            'id_pdf_template' => $tpl['id_pdf_template'],
            'paper_size' => $tpl['paper_size'],
            'header' => $tpl['header'],
            'footer' => $tpl['footer'],
            'template' => $tpl['template'],
            'available_labels' => $tpl['available_labels'],
            'current_labels' => $tpl['current_labels'],
            'tables' => $tables,
            'signatures' => $tpl['signatures'],
        ];

        return $over + $body;
    }

    function q251_tbl(array $tpl, $key)
    {
        foreach ($tpl['tables'] as $tb) {
            if (($tb['data_index'][0] ?? null) === $key) {
                return $tb;
            }
        }

        return null;
    }

    /** Preview POST. */
    function q251_preview($t, $s, $id, array $body)
    {
        return $t->callFile($s, 'POST', Q251_BASE . '/opnames/' . $id . '/pdf/preview', $body);
    }

    /** Hasil unduhan JSON (kode) dari respons callFile. */
    function q251_jerr($r)
    {
        $j = json_decode($r[1], true);

        return is_array($j) ? $j : [];
    }

    /**
     * Panggil OpnameExportService::xls|pdf|preview ($payload = body pratinjau) di proses CLI terpisah dengan batas waktu (anak proses sendiri, dibunuh bila > 25 s).
     * Dipakai untuk keadaan yang bisa membuat Excel berputar tak berujung: request HTTP seperti itu menggantung worker php-fpm
     * sampai max_execution_time (12000 s) dan TIDAK boleh dikirim dari skenario regresi.
     * @return array{ok: bool, timeout: bool, err: string, sec: float}
     */
    function q251_xls_cli($t, $id, $kind = 'xls', $payload = null)
    {
        $pf = null;
        if ($kind === 'preview') {
            $pf = q251_tmpdir() . '/payload-' . uniqid() . '.json';
            file_put_contents($pf, json_encode((array) $payload));
        }
        // 'exec' menggantikan sh dengan php, supaya proc_terminate(9) benar-benar mematikan pekerja (bukan hanya shell-nya)
        $args = [PHP_BINARY, __DIR__ . '/xls_worker.php', (string) $t->conf('BE_DIR'), $id, (string) q251_uname($t), $kind];
        if ($pf !== null) {
            $args[] = $pf;
        }
        $cmd = 'exec ' . implode(' ', array_map('escapeshellarg', $args));
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $start = microtime(true);
        $err = '';
        $timeout = false;
        while (true) {
            $st = proc_get_status($p);
            $err .= stream_get_contents($pipes[2]);
            stream_get_contents($pipes[1]);
            if (!$st['running']) {
                break;
            }
            if (microtime(true) - $start > 25) {
                proc_terminate($p, 9);
                $timeout = true;
                break;
            }
            usleep(100000);
        }
        proc_close($p);
        if ($pf !== null) {
            @unlink($pf);
        }
        $err = trim($err);
        $timeout = $timeout || strpos($err, 'Maximum execution time') !== false;

        return ['ok' => !$timeout && strpos($err, 'EXC') === false && strpos($err, '"type":1') === false, 'timeout' => $timeout, 'err' => substr($err, 0, 400), 'sec' => round(microtime(true) - $start, 1)];
    }

    /**
     * GET xls hanya bila pekerja CLI selesai wajar; bila berputar tak berujung, tidak ada request HTTP (tidak menggantung php-fpm)
     * dan hasil [null, ...] dikembalikan. Mengembalikan [status|null, body, headers, cliResult].
     */
    function q251_xls_safe($t, $s, $id)
    {
        $cli = q251_xls_cli($t, $id, 'xls');
        if (!$cli['ok']) {
            return [null, '', [], $cli];
        }
        $r = q251_file($t, $s, $id, 'xls');
        $r[3] = $cli;

        return $r;
    }
}
