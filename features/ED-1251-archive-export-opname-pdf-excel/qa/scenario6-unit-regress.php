<?php
/**
 * ED-1251 AC-11 (unit teks scope BR-9) dan AC-12 (regresi; bagian milik item ini: asersi F-1 yang dibalik dari ED-1028 scenario9
 * baris 127/130/138, PO Report pdf/xls 200). Suite pemilik (ED-1028 AC-1/12/13, ED-1250 AC-10/11) dijalankan lewat runner masing-masing.
 */
require_once __DIR__ . '/qa_check.php';

return [
    [
        'id'    => 'AC-11',
        'title' => 'Unit: teks scope BR-9 root/0, root/3, folder/0, folder/3 (en & id) = string getScopeTag FE; sesi akar nyata (hidup)',
        'run'   => function ($t) {
            $got = $t->probe(function () {
                $out = [];
                foreach (['en', 'id'] as $l) {
                    foreach ([['root0', true, null, 0], ['root3', true, null, 3], ['folder0', false, 'Backup', 0], ['folder3', false, 'Backup', 3],
                        ['rootStr3', true, 'diabaikan', '3'], ['folderNeg', false, 'Backup', -1], ['folderNoCount', false, 'Backup', null], ['folderNull', false, null, 2]] as $c) {
                        $scope = ['is_root' => $c[1], 'folder_name' => $c[2], 'subfolder_count' => $c[3]];
                        if ($c[3] === null) {
                            unset($scope['subfolder_count']);
                        }
                        $out[$l][$c[0]] = \Modules\V5\Entities\Models\ArchiveOpname::scopeText($scope, $l);
                    }
                }

                return $out;
            });
            // string FE (src/containers/Archive/archive.function.test.js:1088-1115, archive.locale.js: '{name} + {count} subfolder')
            $exp = [
                'en' => ['root0' => 'All Archive · root', 'root3' => 'All Archive · root + 3 subfolder', 'folder0' => 'Backup', 'folder3' => 'Backup + 3 subfolder'],
                'id' => ['root0' => 'Semua Arsip · root', 'root3' => 'Semua Arsip · root + 3 subfolder', 'folder0' => 'Backup', 'folder3' => 'Backup + 3 subfolder'],
            ];
            foreach ($exp as $l => $cases) {
                foreach ($cases as $k => $v) {
                    $t->eq($got[$l][$k], $v, "scopeText $l $k");
                }
            }
            foreach (['en' => 'All Archive', 'id' => 'Semua Arsip'] as $l => $root) {
                $t->eq($got[$l]['rootStr3'], "$root · root + 3 subfolder", "scopeText $l: subfolder_count teks '3' dianggap 3, nama diabaikan untuk akar");
                $t->eq($got[$l]['folderNeg'], 'Backup', "scopeText $l: count negatif = tanpa sufiks");
                $t->eq($got[$l]['folderNoCount'], 'Backup', "scopeText $l: tanpa count = tanpa sufiks");
                $t->eq($got[$l]['folderNull'], ' + 2 subfolder', "scopeText $l: nama kosong tidak melempar");
            }
            // hidup: sesi akar sisipan (root/0 dan root/3 lewat export Excel) dalam bahasa en dan id
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                $by = q251_uname($t);
                $r0 = q251_insert_session($t, 'ROOT0', ['scope_folder_count' => 0, 'confirmed_at' => '2026-10-24 09:30:00']);
                $r3 = q251_insert_session($t, 'ROOT3', ['scope_folder_count' => 3, 'confirmed_at' => '2026-10-24 09:31:00']);
                foreach ([['EN', 'en', 'Folder: All Archive · root', 'Folder: All Archive · root + 3 subfolder'], ['ID', 'id', 'Folder: Semua Arsip · root', 'Folder: Semua Arsip · root + 3 subfolder']] as $c) {
                    $set(['role' => Q251_PRESET_FULL, 'lang' => $c[0]]);
                    $x0 = q251_xlsx(q251_file($t, $s, $r0, 'xls')[1]);
                    $x3 = q251_xlsx(q251_file($t, $s, $r3, 'xls')[1]);
                    $t->eq($x0['rows'][3]['A']['v'] ?? null, $c[2], "hidup {$c[1]}: root/0 (XLSX A3)");
                    $t->eq($x3['rows'][3]['A']['v'] ?? null, $c[3], "hidup {$c[1]}: root/3 (XLSX A3)");
                    $p0 = q251_pdf(q251_file($t, $s, $r0, 'pdf')[1])['texts'][0];
                    $t->true(in_array($c[2], $p0, true), "hidup {$c[1]}: root/0 (PDF)");
                    $t->eq($x0['rows'][5]['A']['v'] ?? null, ($c[1] === 'en' ? 'Opname By: ' : 'Diopname Oleh: ') . $by, "hidup {$c[1]}: Opname By");
                }
                // Rincian kosong (BR-20): sesi akar tanpa baris -> PDF judul + tabel kosong, Excel tabel Rincian tidak ditulis; bukan error
                $set(['role' => Q251_PRESET_FULL, 'lang' => 'EN']);
                $rp = q251_file($t, $s, $r0, 'pdf');
                $t->status([$rp[0]], 200, 'Rincian kosong: PDF 200');
                $pp = q251_pdf_parts(q251_pdf($rp[1])['texts'][0], 'Summaries', 'Detail', 5, 5);
                $t->true($pp['found'] && $pp['rows'] === [], 'Rincian kosong: PDF memuat judul Detail dan 5 kolom, tanpa baris (' . json_encode($pp['detcols']) . ')');
                $rx = q251_file($t, $s, $r0, 'xls');
                $t->status([$rx[0]], 200, 'Rincian kosong: XLSX 200');
                $xl = q251_xlsx($rx[1]);
                $names = array_column(array_map(function ($r) {
                    return ['v' => $r['A']['v'] ?? null];
                }, $xl['rows']), 'v');
                $t->true(in_array('Summaries', $names, true) && !in_array('Detail', $names, true), 'Rincian kosong: XLSX tanpa tabel Detail (BR-20), Ringkasan ada');
            });
        },
    ],

    [
        'id'    => 'AC-12',
        'title' => 'Regresi: index display setting (F-1 dibalik: R0 -2, selisih History+Result, search=documentArchive 3 baris), PO Report pdf/xls 200',
        'run'   => function ($t) {
            q251_guard($t, function ($set) use ($t) {
                $s = $t->session();
                q251_tpl($t, $s); // baris documentArchiveOpnameResult ada
                q251_u2_use($t, Q251_PRESET_R0);
                $s2 = q251_u2_session($t);
                try {
                    list($rowsHolder, $totalHolder) = q251_ds_pages($t, $s, ['pagination' => 100]);
                    list($rowsR0, $totalR0) = q251_ds_pages($t, $s2, ['pagination' => 100]);
                    $hist = 'documentArchiveOpnameHistory';
                    $t->true(in_array($hist, $rowsHolder, true) && in_array(Q251_MODULE, $rowsHolder, true), 'index pemegang: History dan Result tampil');
                    $t->true(!in_array($hist, $rowsR0, true) && !in_array(Q251_MODULE, $rowsR0, true), 'index R0: keduanya tidak ada');
                    $t->true(in_array('documentArchive', $rowsR0, true), 'index R0: documentArchive tetap ada');
                    $t->eq($totalR0, $totalHolder - 2, "F-1: total R0 berkurang 2 ($totalHolder -> $totalR0)");
                    $t->eq($totalHolder, $t->db()->table('column_display_settings')->count(), 'index pemegang: total = semua baris tabel');
                    $t->eq(count($rowsR0), $totalR0, 'index R0: jumlah baris semua halaman = total');
                    $diff = array_values(array_diff($rowsHolder, $rowsR0));
                    sort($diff);
                    $exp = [$hist, Q251_MODULE];
                    sort($exp);
                    $t->eq($diff, $exp, 'F-1: selisih pemegang vs R0 = documentArchiveOpnameHistory + documentArchiveOpnameResult');
                    list($rowsH, $tH) = q251_ds_pages($t, $s, ['search' => 'documentArchive', 'pagination' => 100]);
                    list($rowsR, $tR) = q251_ds_pages($t, $s2, ['search' => 'documentArchive', 'pagination' => 100]);
                    $t->eq($rowsR, ['documentArchive'], 'search=documentArchive R0: hanya documentArchive');
                    $t->eq($tR, 1, 'search=documentArchive R0: total 1');
                    sort($rowsH);
                    $e3 = ['documentArchive', $hist, Q251_MODULE];
                    sort($e3);
                    $t->eq($rowsH, $e3, 'F-1: search=documentArchive pemegang: 3 baris (documentArchive, History, Result)');
                    $t->eq($tH, 3, 'search=documentArchive pemegang: total 3');
                    // modul lain tetap
                    $gd = $t->call($s2, 'GET', Q251_DS . 'documentArchive');
                    $t->status($gd, 200, 'R0: GET documentArchive tetap 200');
                    $t->status($t->call($s2, 'GET', Q251_DS . 'supplier'), 200, 'R0: GET modul lain (supplier) tetap 200');
                    $t->status($t->call($s, 'GET', Q251_DS . $hist), 200, 'pemegang: GET riwayat tetap 200');
                } finally {
                    q251_u2_out($t, $s2);
                }
            }, ['u2' => false]);
            // PO Report pdf/xls 200 (QA_USER, role aslinya): endpoint laporan lain tidak terganggu route/ config baru
            $s = $t->session();
            foreach (['pdf', 'xls'] as $kind) {
                $r = $t->callFile($s, 'GET', 'api/v5/report/purchase/purchase-order/' . $kind);
                $t->true($r[0] < 500, "PO Report $kind: HTTP {$r[0]} (tanpa 5xx)");
                $t->eq($r[0], 200, "PO Report $kind: 200");
                $t->true($kind === 'pdf' ? strpos($r[1], '%PDF-') === 0 : strpos($r[1], 'PK') === 0, "PO Report $kind: berkas sah");
            }
            $r = $t->call($s, 'GET', 'api/v5/select/report/purchase/purchase-order/transaction-templates');
            $t->status($r, 200, 'PO Report select transaction-templates tetap 200');
            $t->eq($r[1]['result']['transaction_name'] ?? null, 'reportPurchaseOrder', 'PO Report: template reportPurchaseOrder');
            // route baru tidak membayangi route lama: GET opnames/{id}, /documents, /folders tetap
            $r = $t->call($s, 'GET', Q251_BASE . '/opnames?pagination=5');
            $t->status($r, 200, 'GET opnames (riwayat) tetap 200');
            $r = $t->call($s, 'GET', Q251_BASE . '/opnames/folders');
            $t->status($r, 200, 'GET opnames/folders tetap 200');
        },
    ],
];
