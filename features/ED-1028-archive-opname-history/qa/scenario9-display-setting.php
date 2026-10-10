<?php
/**
 * ED-1028 (REVISI 2026-10-10, subtask ED-1101 / ED-1102):
 *   AC-28  display setting `documentArchiveOpnameHistory` (K-8 a, K-9 a; entri SELURUH MODUL di peta saringan ColumnDisplaySettingService
 *          milik ED-1027): R0 (role asli user2 = List Archive tanpa Opname Document) -> GET / PUT setting/display-columns/
 *          documentArchiveOpnameHistory = 403 GE0114 parameter "Opname Document", DB tetap, baris TIDAK dibuat bila belum ada; juga varian
 *          nama (huruf besar, spasi akhir: kolasi DB tak peka); GET setting/display-columns (index, juga search=documentArchive) tanpa baris
 *          itu dan `total` ikut berkurang; documentArchive tetap 200 (aturan ED-1027 AC-24); pemegang (profil default): baris tampil,
 *          show / put 200.
 * Baris column_display_settings `documentArchiveOpnameHistory` dan `documentArchive` dicadangkan utuh dan dikembalikan persis (q28_guard +
 * snapshot); sidik jari seluruh tabel dicek sebelum / sesudah. Bug pra-ada ED-1122 (PUT isi sama di detik yang sama = 500) tidak dipicu:
 * PUT pemegang selalu mengubah isi, sekali.
 */
require_once __DIR__ . '/qa_hist.php';

define('Q28_DS', 'api/v5/setting/display-columns');
define('Q28_DSMOD', 'documentArchiveOpnameHistory');

return [

    [
        'id'    => 'AC-28',
        'title' => '(rev, K-8 a / K-9 a) R0: GET & PUT setting/display-columns/documentArchiveOpnameHistory = 403 GE0114 parameter "Opname Document" (juga varian huruf/spasi), DB tetap, baris tidak dibuat bila belum ada; GET setting/display-columns (juga search=documentArchive) tanpa baris itu dan total berkurang; documentArchive tetap 200; pemegang: baris tampil, show/put 200',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function ($ds) use ($t, $s) {
                $fpBefore = q28_sum($t, 'column_display_settings', q28_cols($t, 'column_display_settings'));
                $docRow = $t->db()->table('column_display_settings')->where('module_name', 'documentArchive')->first();
                $t->true($docRow !== null, 'prasyarat: baris documentArchive ada (aturan ED-1027)');
                $docRowArr = (array) $docRow;

                $roles = q28_u2_roles_now($t);
                $names = $t->db()->table('role_permissions as rp')->join('permissions as p', 'p.id_permission', '=', 'rp.id_permission')
                    ->whereIn('rp.id_role', $roles)->pluck('p.permission_name')->all();
                $t->true(in_array(Q28_LA, $names, true) && !in_array(Q28_OD, $names, true), 'prasyarat: R0 = role asli user2: ' . Q28_LA . ' tanpa ' . Q28_OD . ' (per nama)');
                $s2 = q28_u2_session($t);

                $deny = function ($label, $r) use ($t) {
                    q28_no500($t, $r, $label);
                    q28_deny($t, $r, 403, 'GE0114', $label);
                    $t->eq($r[1]['parameter'] ?? null, Q28_OD, "$label: parameter = Opname Document");
                    $t->true(empty($r[1]['result']), "$label: tanpa data");
                };
                $count = function () use ($t) {
                    return $t->db()->table('column_display_settings')->where('module_name', Q28_DSMOD)->count();
                };
                $body = function ($row) {
                    return [
                        'current_columns' => json_decode($row['current_columns'], true), 'available_columns' => json_decode($row['available_columns'], true),
                        'current_queries' => json_decode($row['current_queries'], true), 'available_queries' => json_decode($row['available_queries'], true),
                    ];
                };

                // ---------- A. baris BELUM ada: R0 ditolak dan baris TIDAK dibuat
                q28_ds_restore($t, null);
                $t->eq($count(), 0, 'prasyarat: baris documentArchiveOpnameHistory belum ada');
                $deny('R0 GET (baris belum ada)', $t->call($s2, 'GET', Q28_DS . '/' . Q28_DSMOD));
                $t->eq($count(), 0, 'R0 GET: baris tidak dibuat');
                $config = require rtrim($t->conf('BE_DIR'), '/') . '/Modules/V5/Config/displayColumn/documentArchiveOpnameHistory.php';
                $cfgBody = [
                    'current_columns' => $config['current_columns'], 'available_columns' => $config['available_columns'],
                    'current_queries' => $config['current_queries'], 'available_queries' => $config['available_queries'],
                ];
                $deny('R0 PUT (baris belum ada)', $t->call($s2, 'PUT', Q28_DS . '/' . Q28_DSMOD, $cfgBody));
                $t->eq($count(), 0, 'R0 PUT: baris tidak dibuat');
                // varian nama: kolasi DB tak peka huruf besar / spasi akhir -> tetap 403, tetap tidak dibuat
                foreach (['DOCUMENTARCHIVEOPNAMEHISTORY', 'documentarchiveopnamehistory', Q28_DSMOD . '%20', Q28_DSMOD . '%20%20'] as $variant) {
                    $deny("R0 GET varian '$variant'", $t->call($s2, 'GET', Q28_DS . '/' . $variant));
                    $deny("R0 PUT varian '$variant'", $t->call($s2, 'PUT', Q28_DS . '/' . $variant, $cfgBody));
                    $t->eq($count(), 0, "varian '$variant': baris tidak dibuat");
                }

                // ---------- B. pemegang (profil default, QA_USER role 3): GET membuat baris dari config; show 200
                $g = $t->call($s, 'GET', Q28_DS . '/' . Q28_DSMOD);
                $t->status($g, 200, 'pemegang: GET documentArchiveOpnameHistory');
                $t->eq($count(), 1, 'pemegang: GET membuat baris dari config (request pertama)');
                $t->eq(array_column($g[1]['result']['current_columns'], 'data_index'), ['confirmedAt', 'createdBy', 'scope', 'totalDocuments', 'verifiedCount', 'notFoundCount', 'invalidCount'], 'pemegang: current_columns = 7 kolom BR-6');
                $rowSnap = q28_dsrow($t);
                $t->true($rowSnap !== null, 'baris ada sesudah GET pemegang');

                // ---------- C. baris SUDAH ada: R0 GET / PUT 403 dan baris identik (juga updated_at)
                $deny('R0 GET (baris ada)', $t->call($s2, 'GET', Q28_DS . '/' . Q28_DSMOD));
                $swapped = $body($rowSnap);
                $swapped['current_columns'] = array_reverse($swapped['current_columns']);
                $deny('R0 PUT (baris ada; isi berbeda)', $t->call($s2, 'PUT', Q28_DS . '/' . Q28_DSMOD, $swapped));
                $deny('R0 PUT (baris ada; isi sama)', $t->call($s2, 'PUT', Q28_DS . '/' . Q28_DSMOD, $body($rowSnap)));
                foreach (['DOCUMENTARCHIVEOPNAMEHISTORY', Q28_DSMOD . '%20'] as $variant) {
                    $deny("R0 GET varian '$variant' (baris ada)", $t->call($s2, 'GET', Q28_DS . '/' . $variant));
                    $deny("R0 PUT varian '$variant' (baris ada)", $t->call($s2, 'PUT', Q28_DS . '/' . $variant, $swapped));
                }
                $t->eq(q28_dsrow($t), $rowSnap, 'baris documentArchiveOpnameHistory identik sesudah semua penolakan R0 (current/available + updated_at)');
                $t->eq($count(), 1, 'tetap satu baris');
                // payload aneh dari R0 tetap 403 (permission dicek sebelum validasi)
                $deny('R0 PUT payload skalar', $t->call($s2, 'PUT', Q28_DS . '/' . Q28_DSMOD, ['current_columns' => 'x']));
                // tanpa current_columns: validasi controller (required) jalan lebih dulu -> 422, bukan 403; tidak membaca / menulis apa pun (sama dengan modul lain, ED-1027)
                $r = $t->call($s2, 'PUT', Q28_DS . '/' . Q28_DSMOD);
                q28_no500($t, $r, 'R0 PUT tanpa body');
                $t->true(in_array($r[0], [403, 422], true), 'R0 PUT tanpa body: 403/422 (' . $r[0] . ')');
                $t->note('R0 PUT tanpa current_columns -> HTTP ' . $r[0] . ' (validasi controller sebelum cek permission service)');
                $t->eq(q28_dsrow($t), $rowSnap, 'baris tetap identik sesudah payload aneh');

                // ---------- D. index: tanpa baris itu bagi R0, total berkurang; pemegang melihatnya
                $pages = function ($sess, array $q) use ($t) {
                    $rows = [];
                    $total = null;
                    for ($page = 1; $page < 300; $page++) {
                        $r = $t->call($sess, 'GET', q28_url(Q28_DS, $q + ['page' => $page]));
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
                };
                list($rowsHolder, $totalHolder) = $pages($s, ['pagination' => 100]);
                list($rowsR0, $totalR0) = $pages($s2, ['pagination' => 100]);
                $t->true(in_array(Q28_DSMOD, $rowsHolder, true), 'index pemegang: baris documentArchiveOpnameHistory tampil');
                $t->true(!in_array(Q28_DSMOD, $rowsR0, true), 'index R0: baris documentArchiveOpnameHistory tidak ada (semua halaman)');
                $t->true(in_array('documentArchive', $rowsR0, true), 'index R0: baris documentArchive tetap ada');
                $t->eq($totalR0, $totalHolder - 1, "index R0: total berkurang 1 ($totalHolder -> $totalR0)");
                $t->eq($totalHolder, $t->db()->table('column_display_settings')->count(), 'index pemegang: total = semua baris tabel');
                $t->eq(count($rowsR0), $totalR0, 'index R0: jumlah baris semua halaman = total');
                $t->eq(array_values(array_diff($rowsHolder, $rowsR0)), [Q28_DSMOD], 'index: satu-satunya selisih pemegang vs R0 = documentArchiveOpnameHistory');
                // search
                list($rowsH, $tH) = $pages($s, ['search' => 'documentArchive', 'pagination' => 100]);
                list($rowsR, $tR) = $pages($s2, ['search' => 'documentArchive', 'pagination' => 100]);
                $t->eq($rowsR, ['documentArchive'], 'search=documentArchive R0: hanya documentArchive');
                $t->eq($tR, 1, 'search=documentArchive R0: total 1');
                $hs = $rowsH;
                sort($hs);
                $t->eq($hs, ['documentArchive', Q28_DSMOD], 'search=documentArchive pemegang: documentArchive + documentArchiveOpnameHistory');
                list($rowsR, $tR) = $pages($s2, ['search' => 'OpnameHistory', 'pagination' => 100]);
                $t->eq([$rowsR, $tR], [[], 0], 'search=OpnameHistory R0: kosong, total 0');
                list($rowsR, $tR) = $pages($s2, ['search' => strtoupper(Q28_DSMOD), 'pagination' => 100]);
                $t->eq([$rowsR, $tR], [[], 0], 'search huruf besar R0: kosong');
                // paginasi kecil: baris tidak muncul di halaman mana pun, total konsisten
                list($rowsR, $tR) = $pages($s2, ['pagination' => 7]);
                $t->true(!in_array(Q28_DSMOD, $rowsR, true), 'index R0 pagination 7: baris itu tidak muncul di halaman mana pun');
                $t->eq($tR, $totalR0, 'index R0 pagination 7: total sama dengan pagination 100');
                $t->note('index R0 pagination 7: ' . count($rowsR) . ' baris terbaca dari total ' . $tR . '');

                // ---------- E. modul lain tetap: documentArchive 200 untuk R0 (kolom verifikasi disaring ED-1027), baris documentArchive tidak berubah
                $gd = $t->call($s2, 'GET', Q28_DS . '/documentArchive');
                $t->status($gd, 200, 'R0: GET documentArchive tetap 200');
                $t->true(strpos(json_encode($gd[1]), 'documentVerified') === false, 'R0: documentArchive tanpa kolom documentVerified (aturan ED-1027)');
                $gc = $t->call($s2, 'GET', Q28_DS . '/supplier');
                $t->status($gc, 200, 'R0: GET modul lain (supplier) tetap 200');
                $t->eq((array) $t->db()->table('column_display_settings')->where('module_name', 'documentArchive')->first(), $docRowArr, 'baris documentArchive tidak berubah oleh GET R0');

                // ---------- F. pemegang (profil default): show 200 dan PUT 200 (isi berubah, sekali)
                $put = $body($rowSnap);
                $put['current_columns'] = array_reverse($put['current_columns']);
                $p = $t->call($s, 'PUT', Q28_DS . '/' . Q28_DSMOD, $put);
                $t->status($p, 200, 'pemegang: PUT documentArchiveOpnameHistory');
                $after = q28_dsrow($t);
                $t->eq(array_column(json_decode($after['current_columns'], true), 'data_index'), array_column($put['current_columns'], 'data_index'), 'pemegang: current_columns tersimpan (urutan dibalik)');
                $g2 = $t->call($s, 'GET', Q28_DS . '/' . Q28_DSMOD);
                $t->status($g2, 200, 'pemegang: GET sesudah PUT');
                $t->eq(array_column($g2[1]['result']['current_columns'], 'data_index'), array_column($put['current_columns'], 'data_index'), 'pemegang: GET mengembalikan urutan baru');
                // dengan grant sementara Opname Document, R0 (user2) sama dengan pemegang: show 200
                q28_with_grant($t, $roles, Q28_OD, function () use ($t, $s2) {
                    $r = $t->call($s2, 'GET', Q28_DS . '/' . Q28_DSMOD);
                    $t->status($r, 200, 'user2 + Opname Document (grant sementara): GET documentArchiveOpnameHistory 200');
                    $list = [];
                    for ($page = 1; $page < 50; $page++) {
                        $rr = $t->call($s2, 'GET', q28_url(Q28_DS, ['pagination' => 100, 'page' => $page]));
                        foreach ($rr[1]['result']['data'] ?? [] as $row) {
                            $list[] = $row['module_name'];
                        }
                        if ($page >= (int) ($rr[1]['result']['last_page'] ?? 1)) {
                            break;
                        }
                    }
                    $t->true(in_array(Q28_DSMOD, $list, true), 'user2 + Opname Document: index memuat baris documentArchiveOpnameHistory');
                });
                $deny('grant dicabut: R0 GET kembali 403', $t->call($s2, 'GET', Q28_DS . '/' . Q28_DSMOD));
                $t->call($s2, 'GET', 'api/v5/auth/log-out');

                // ---------- G. pemulihan: baris dikembalikan persis; sidik jari tabel sama dengan sebelum AC
                q28_ds_restore($t, $ds);
                $t->eq(json_encode(q28_dsrow($t)), json_encode($ds), 'baris documentArchiveOpnameHistory dipulihkan persis (ke keadaan sebelum AC)');
                $t->eq(q28_sum($t, 'column_display_settings', q28_cols($t, 'column_display_settings')), $fpBefore, 'sidik jari seluruh tabel column_display_settings sama dengan sebelum AC');
            }, ['od' => false]);
        },
    ],
];
