<?php
/**
 * ED-1251 AC-9, AC-13b - akses (permission_v5 Opname Document, display setting seluruh modul, route select tanpa data sesi), 401/403/404/422
 * tanpa 500. Tiga pemanggil: QA_USER (preset FULL), QA_USER2 sebagai U2 (Opname Document, lokasi/View terbatas) dan sebagai R0 (List Archive
 * tanpa Opname Document). Sesi tak terlihat user2 = sesi folder B.
 */
require_once __DIR__ . '/qa_check.php';

if (!function_exists('q251_ds_pages')) {

    /** Semua baris index display setting (nama modul) + total, untuk sesi $sess dan query $q. */
    function q251_ds_pages($t, $sess, array $q)
    {
        $rows = [];
        $total = null;
        for ($page = 1; $page < 300; $page++) {
            $r = $t->call($sess, 'GET', q251_url(rtrim(Q251_DS, '/'), $q + ['page' => $page]));
            $t->status($r, 200, 'GET setting/display-columns ' . json_encode($q) . " hal $page");
            $res = $r[1]['result'] ?? [];
            $total = $total ?? ($res['total'] ?? null);
            foreach ($res['data'] ?? [] as $row) {
                $rows[] = $row['module_name'];
            }
            if ($page >= (int) ($res['last_page'] ?? 1)) {
                break;
            }
        }

        return [$rows, $total];
    }

    function q251_denied($t, $r, $label, $param = 'Opname Document')
    {
        $j = is_string($r[1] ?? null) ? json_decode($r[1], true) : ($r[1] ?? []);
        $t->eq($r[0], 403, "$label: HTTP 403");
        $t->eq($j['code'] ?? null, 'GE0114', "$label: code GE0114");
        $t->eq($j['parameter'] ?? null, $param, "$label: parameter '$param'");
        $t->true(!empty($j['message']), "$label: message terisi");
    }
}

return [
    [
        'id'    => 'AC-9',
        'title' => 'R0: pdf/preview/xls 403 GE0114 "Opname Document", display setting 403 (DB tetap), index tanpa baris; select 200; tanpa token 401; id tak ada/non-ASCII/tak terlihat 404 ARCHIVE412; tanpa 500',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                list($x, $ids) = q251_fixture($t, $s);
                $tpl = q251_tpl($t, $s); // baris template + cds ada
                $cdsRow = q251_cds_rows($t);
                $t->eq(count($cdsRow), 1, 'prasyarat: baris cds ada');
                $hidden = q251_run($t, $s, $x['B'], [], [$x['n']['dB1']]); // sesi folder B: tak terlihat user2 (U2)
                $five = [];
                $no500 = function ($r, $label) use ($t, &$five) {
                    $five[] = [$label, $r[0]];
                    $t->true($r[0] < 500, "$label: HTTP {$r[0]} (tanpa 5xx)");
                };

                // ================= QA_USER (pemegang): 2xx dan 404
                foreach (['pdf', 'xls'] as $kind) {
                    $r = q251_file($t, $s, $ids['S1'], $kind);
                    $t->status([$r[0]], 200, "QA_USER $kind 200");
                }
                $r = q251_preview($t, $s, $ids['S1'], []);
                $t->status([$r[0]], 200, 'QA_USER preview 200');

                $miss = [
                    'tidak ada' => Q251_RANDOM_ID,
                    'nol' => '0',
                    'non-ASCII é' => rawurlencode('é'),
                    'non-ASCII CJK' => rawurlencode('日本語'),
                    'emoji' => rawurlencode("\u{1F600}"),
                    'terlalu panjang (31)' => str_repeat('9', 31),
                    'sangat panjang (500)' => str_repeat('9', 500),
                    'kutip tunggal' => rawurlencode("' OR '1'='1"),
                    'kutip ganda' => rawurlencode('"; DROP TABLE archives; --'),
                    'persen (dikodekan dua kali)' => '%2525',
                    'titik dua' => '..',
                    'spasi' => '%20',
                    'wildcard _' => rawurlencode('_'),
                    'id Latin-1 (0xE9)' => '%E9',
                ];
                // dikembalikan web server (Apache) sebelum aplikasi: %25/%00 di path = 400/404 HTML, juga untuk endpoint lama; hanya dicek tanpa 5xx
                foreach (['persen %25' => '%25', 'NUL %00' => '%00'] as $label => $enc) {
                    $r = $t->callFile($s, 'GET', Q251_BASE . '/opnames/' . $enc . '/pdf');
                    $no500($r, "id $label (web server)");
                    $t->true(in_array($r[0], [400, 404], true), "id $label (web server): 400/404 (" . $r[0] . ')');
                }
                foreach ($miss as $label => $enc) {
                    foreach (['pdf', 'xls'] as $kind) {
                        $r = $t->callFile($s, 'GET', Q251_BASE . '/opnames/' . $enc . '/' . $kind);
                        $no500($r, "id $label $kind");
                        $t->eq($r[0], 404, "id $label $kind: 404");
                        $j = json_decode($r[1], true);
                        if (is_array($j) && isset($j['code'])) {
                            $t->eq($j['code'], 'ARCHIVE412', "id $label $kind: ARCHIVE412");
                        }
                    }
                    $r = $t->callFile($s, 'POST', Q251_BASE . '/opnames/' . $enc . '/pdf/preview', []);
                    $no500($r, "id $label preview");
                    $t->eq($r[0], 404, "id $label preview: 404");
                }
                // sesi bukan terkonfirmasi (status 1) milik QA_USER: tetap 404
                $draft = q251_insert_session($t, 'DRAFT', ['status' => 1, 'confirmed_at' => null]);
                foreach (['pdf', 'xls'] as $kind) {
                    $r = q251_file($t, $s, $draft, $kind);
                    $no500($r, "draft $kind");
                    $t->eq($r[0], 404, "sesi status 1 ($kind): 404");
                    $t->eq(q251_jerr($r)['code'] ?? null, 'ARCHIVE412', "sesi status 1 ($kind): ARCHIVE412");
                }
                $r = q251_preview($t, $s, $draft, []);
                $t->eq($r[0], 404, 'sesi status 1 (preview): 404');

                // ================= QA_USER2 sebagai U2: boleh untuk sesi terlihat, 404 untuk yang tak terlihat
                q251_u2_use($t, Q251_PRESET_U2);
                $s2 = q251_u2_session($t);
                try {
                    foreach (['pdf', 'xls'] as $kind) {
                        $r = q251_file($t, $s2, $ids['S1'], $kind);
                        $t->status([$r[0]], 200, "U2 $kind sesi terlihat: 200");
                        $r = q251_file($t, $s2, $hidden, $kind);
                        $no500($r, "U2 sesi tak terlihat $kind");
                        $t->eq($r[0], 404, "U2 sesi folder B tak terlihat ($kind): 404");
                        $t->eq(q251_jerr($r)['code'] ?? null, 'ARCHIVE412', "U2 tak terlihat ($kind): ARCHIVE412");
                    }
                    $r = q251_preview($t, $s2, $ids['S1'], []);
                    $t->status([$r[0]], 200, 'U2 preview sesi terlihat: 200');
                    $r = q251_preview($t, $s2, $hidden, ['language' => 'fr']);
                    $t->eq($r[0], 404, 'U2 preview sesi tak terlihat + payload salah: 404 lebih dulu dari 422');
                    // select template: 200, tanpa data sesi
                    $r = q251_sget($t, $s2, 'transaction-templates');
                    $t->status($r, 200, 'U2 select transaction-templates');
                    $t->true(strpos(json_encode($r[1]), (string) $ids['S1']) === false, 'U2 select: tanpa id sesi');
                } finally {
                    q251_u2_out($t, $s2);
                }

                // ================= QA_USER2 sebagai R0: 403 GE0114
                q251_u2_use($t, Q251_PRESET_R0);
                $s2 = q251_u2_session($t);
                $roles = $t->db()->table('role_permissions as rp')->join('permissions as p', 'p.id_permission', '=', 'rp.id_permission')
                    ->join('user_roles as ur', 'ur.id_role', '=', 'rp.id_role')->where('ur.id_user', q251_u2_id($t))->pluck('p.permission_name')->all();
                $t->true(!in_array(Q251_OD, $roles, true) && in_array(Q251_LA, $roles, true), 'prasyarat: QA_USER2 = R0 (List Archive, tanpa Opname Document)');
                try {
                    foreach (['pdf', 'xls'] as $kind) {
                        $r = q251_file($t, $s2, $ids['S1'], $kind);
                        $no500($r, "R0 $kind");
                        q251_denied($t, $r, "R0 $kind");
                        $t->true(strpos($r[2]['content-type'] ?? '', 'application/json') === 0, "R0 $kind: respons JSON (bukan berkas)");
                    }
                    $r = q251_preview($t, $s2, $ids['S1'], []);
                    $no500($r, 'R0 preview');
                    q251_denied($t, $r, 'R0 preview');
                    $r = q251_preview($t, $s2, $ids['S1'], ['language' => 'fr', 'paper_size' => 'x']);
                    q251_denied($t, $r, 'R0 preview payload salah: 403 lebih dulu dari 422');
                    foreach (['pdf', 'xls'] as $kind) {
                        $r = q251_file($t, $s2, Q251_RANDOM_ID, $kind);
                        q251_denied($t, $r, "R0 id tak ada $kind: 403 lebih dulu dari 404");
                        $r = q251_file($t, $s2, $hidden, $kind);
                        q251_denied($t, $r, "R0 sesi tak terlihat $kind: 403 lebih dulu dari 404");
                    }

                    // display setting seluruh modul: 403, DB tetap
                    $before = q251_cds_rows($t);
                    $deny = function ($label, $r) use ($t, $no500) {
                        $no500($r, $label);
                        q251_denied($t, [$r[0], $r[1]], $label, 'Opname Document');
                    };
                    foreach ([Q251_MODULE, strtoupper(Q251_MODULE), strtolower(Q251_MODULE), Q251_MODULE . '%20', 'documentarchiveopnameresult'] as $name) {
                        $deny("R0 GET display setting '$name'", $t->call($s2, 'GET', Q251_DS . $name));
                        $deny("R0 PUT display setting '$name'", $t->call($s2, 'PUT', Q251_DS . $name, ['current_columns' => [['title' => ['en' => 'X'], 'data_index' => 'code', 'type' => 'string']], 'summaries_columns' => []]));
                    }
                    $r = $t->call($s2, 'PUT', Q251_DS . Q251_MODULE, ['current_columns' => 'x']);
                    $deny('R0 PUT payload skalar', $r);
                    $t->eq(json_encode(q251_cds_rows($t)), json_encode($before), 'DB cds tidak berubah oleh GET/PUT R0');

                    // index: tanpa baris itu bagi R0, pemegang melihatnya
                    list($rowsHolder, $totalHolder) = q251_ds_pages($t, $s, ['pagination' => 100]);
                    list($rowsR0, $totalR0) = q251_ds_pages($t, $s2, ['pagination' => 100]);
                    $t->true(in_array(Q251_MODULE, $rowsHolder, true), 'index pemegang: baris documentArchiveOpnameResult tampil');
                    $t->true(!in_array(Q251_MODULE, $rowsR0, true), 'index R0: baris itu tidak ada (semua halaman)');
                    $t->true(in_array('documentArchive', $rowsR0, true), 'index R0: documentArchive tetap ada');
                    list($rowsRs, $tRs) = q251_ds_pages($t, $s2, ['search' => 'OpnameResult', 'pagination' => 100]);
                    $t->eq([$rowsRs, $tRs], [[], 0], 'search=OpnameResult R0: kosong');
                    list($rowsRs, $tRs) = q251_ds_pages($t, $s2, ['search' => strtoupper(Q251_MODULE), 'pagination' => 100]);
                    $t->eq([$rowsRs, $tRs], [[], 0], 'search huruf besar R0: kosong');
                    list($rowsRs, $tRs) = q251_ds_pages($t, $s2, ['pagination' => 7]);
                    $t->true(!in_array(Q251_MODULE, $rowsRs, true), 'index R0 pagination 7: baris itu tidak muncul di halaman mana pun');
                    $t->eq($tRs, $totalR0, 'index R0 pagination 7: total konsisten');

                    // route select template: konvensi select = 200 tanpa permission_v5, tanpa data sesi
                    foreach (['transaction-templates' => [], 'header-templates' => [], 'footer-templates' => [], 'pdf-templates' => [], 'paper-sizes' => [],
                        'selected-header-templates' => ['id_header_pdf_template' => 'report-header-1'],
                        'selected-footer-templates' => ['id_footer_pdf_template' => 'report-footer-1'],
                        'selected-pdf-templates' => ['id_pdf_template' => 'report-template-6']] as $route => $q) {
                        $r = q251_sget($t, $s2, $route, $q);
                        $no500($r, "R0 select $route");
                        $t->status($r, 200, "R0 select $route");
                        $blob = json_encode($r[1]);
                        foreach ([$ids['S1'], $ids['S2'], $hidden] as $sid) {
                            $t->true(strpos($blob, (string) $sid) === false, "R0 select $route: tanpa id sesi");
                        }
                        $t->true(strpos($blob, $x['n']['dF1']) === false, "R0 select $route: tanpa kode dokumen sesi");
                    }
                } finally {
                    q251_u2_out($t, $s2);
                }

                // ================= tanpa token / token rusak: 401
                foreach (['GET' => ['pdf', 'xls'], 'POST' => ['pdf/preview']] as $method => $paths) {
                    foreach ($paths as $p) {
                        $r = $t->callFile(null, $method, Q251_BASE . '/opnames/' . $ids['S1'] . '/' . $p, $method === 'POST' ? [] : null);
                        $no500($r, "tanpa token $method $p");
                        $t->eq($r[0], 401, "tanpa token $method $p: 401");
                        $j = json_decode($r[1], true) ?: [];
                        $t->eq([$j['msg_code'] ?? null, $j['status_text'] ?? null], ['GE0111', 'unauthenticated'], "tanpa token $method $p: JSON GE0111 + status_text unauthenticated (kontrak §8)");
                    }
                }
                foreach (['transaction-templates', 'paper-sizes'] as $route) {
                    $r = $t->callFile(null, 'GET', Q251_SEL . $route);
                    $no500($r, "tanpa token select $route");
                    $t->eq($r[0], 401, "tanpa token select $route: 401");
                }
                $bad = new \QaHttp\Session('QA_USER', 'x', 'bukan-token', 'x');
                $r = $t->callFile($bad, 'GET', Q251_BASE . '/opnames/' . $ids['S1'] . '/pdf');
                $no500($r, 'token rusak');
                $t->eq($r[0], 401, 'token rusak: 401');
                // metode salah
                $r = $t->callFile($s, 'POST', Q251_BASE . '/opnames/' . $ids['S1'] . '/pdf', []);
                $no500($r, 'POST /pdf');
                $t->true(in_array($r[0], [404, 405], true), 'POST /pdf: 404/405 (' . $r[0] . ')');
                $r = $t->callFile($s, 'GET', Q251_BASE . '/opnames/' . $ids['S1'] . '/pdf/preview');
                $no500($r, 'GET /pdf/preview');
                $t->true(in_array($r[0], [404, 405], true), 'GET /pdf/preview: 404/405 (' . $r[0] . ')');
                $t->note('status diamati: ' . count($five) . ' permintaan, 5xx = ' . count(array_filter($five, function ($f) {
                    return $f[1] >= 500;
                })));
            }, ['u2' => false]);
        },
    ],

    [
        'id'    => 'AC-13b',
        'title' => 'AC-13 (jalur gagal): selected-* tanpa/salah id = 422, paper-sizes search tak valid = 422, tanpa 500',
        'run'   => function ($t) {
            $s = $t->session();
            $bad = function ($route, array $q, $label) use ($t, $s) {
                $r = q251_sget($t, $s, $route, $q);
                $t->true($r[0] < 500, "$label: HTTP {$r[0]} (tanpa 5xx)");
                $t->eq($r[0], 422, "$label: 422");
            };
            $bad('selected-header-templates', [], 'selected-header tanpa id');
            $bad('selected-header-templates', ['id_header_pdf_template' => 'nope'], 'selected-header id tak ada');
            $bad('selected-header-templates', ['id_header_pdf_template' => ['a']], 'selected-header id array');
            $bad('selected-footer-templates', [], 'selected-footer tanpa id');
            $bad('selected-footer-templates', ['id_footer_pdf_template' => 'nope'], 'selected-footer id tak ada');
            $bad('selected-pdf-templates', [], 'selected-pdf tanpa id');
            $bad('selected-pdf-templates', ['id_pdf_template' => 'nope'], 'selected-pdf id tak ada');
            $bad('selected-pdf-templates', ['id_pdf_template' => "report-template-6'"], 'selected-pdf id berkutip');
            foreach (['(' => 'kurung buka', 'a.*' => 'titik bintang', '[' => 'kurung siku', '\\' => 'backslash', 'A4/' => 'garis miring', str_repeat('A', 31) => '31 huruf'] as $search => $label) {
                $bad('paper-sizes', ['search' => $search], "paper-sizes search $label");
            }
            // yang sah tetap 200
            foreach ([['search' => 'A 4'], ['search' => 'a4-'], ['search' => ''], ['search' => str_repeat('a', 30)]] as $q) {
                $r = q251_sget($t, $s, 'paper-sizes', $q);
                $t->status($r, 200, 'paper-sizes search ' . json_encode($q['search']));
            }
            $r = q251_sget($t, $s, 'selected-header-templates', ['id_header_pdf_template' => 'header-1']);
            $t->status($r, 200, 'selected-header id header-1 yang ada (non-laporan) tetap 200');
        },
    ],
];
