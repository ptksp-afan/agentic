<?php
/**
 * ED-1026 - ronde 2 (perbaikan D-1 dan D-2) disesuaikan alur tanpa draft (rev 2026-10-09):
 *   R2-D1       POST opnames id_archive: rule-nya sendiri (bukan string / > 30 karakter) -> 422 tanpa lookup; lolos rule tapi
 *               tidak ada -> 404 ARCHIVE400; urutan 401 > 403 GE0114 > 403 ARCHIVE407 > 422; GET folders sama.
 *   R2-D2       scanned_at DATETIME(6): urutan scan terbaru dulu pasti (Konfirmasi, filter, dibaca superadmin); jam mundur /
 *               kode ganda tidak menggeser urutan; format response Y-m-d H:i:s.
 *   R2-SCANVOL  100 scan beruntun (POST opnames/scan, tanpa simpan) lalu satu Konfirmasi: urutan monoton, tanpa pelambatan.
 *   R2-PARALLEL-DIFF  scan kode BERBEDA bersamaan: semua 200 dan tanpa tulis; Konfirmasi sesudahnya menyimpan urutan pasti.
 *   R2-SCHEMA   scanned_at = datetime(6) di DB QA; Updater pada DB yang sudah menjalankan versi awalnya (scanned_at DATETIME) ->
 *               MODIFY sekali, data utuh, run ulang tanpa perubahan, skema = DB QA.
 */
require_once __DIR__ . '/qa_lib.php';

if (!function_exists('q26r2_rows_db')) {
    /** [[code, scanned_at(raw, 26 karakter), result]] baris scan sesi urut scanned_at naik. */
    function q26r2_rows_db($t, $id)
    {
        $out = [];
        foreach ($t->db()->select('SELECT code, scanned_at, result FROM archive_opname_documents WHERE id_archive_opname = ? AND scanned_at IS NOT NULL ORDER BY scanned_at ASC', [$id]) as $r) {
            $out[] = [$r->code, (string) $r->scanned_at, (int) $r->result];
        }

        return $out;
    }

    function q26r2_codes($rows)
    {
        return array_map(function ($r) {
            return $r['code'];
        }, $rows);
    }

    function q26r2_strict($db)
    {
        for ($i = 1; $i < count($db); $i++) {
            if (strcmp($db[$i][1], $db[$i - 1][1]) <= 0) {
                return false;
            }
        }

        return true;
    }
}

return [

    [
        'id'    => 'R2-D1',
        'title' => 'D-1: POST opnames id_archive > 30 karakter / bukan string -> 422 (bukan 404); 30 karakter tak ada -> 404 ARCHIVE400; urutan penolakan; GET folders konsisten; penolakan tidak menyimpan sesi',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $B = Q26_BASE . '/opnames';
                $now = q26_now()->format('Y-m-d H:i:s');
                $post = function ($body, $session = null) use ($t, $s, $B, $now) {
                    return $t->call($session ?: $s, 'POST', $B, $body + ['selected_at' => $now]);
                };
                $mentions = function ($r) {
                    return strpos(json_encode($r[1]), 'id_archive') !== false;
                };
                $before = q26_state($t);

                // 1) panjang: 31..300 karakter -> 422 (rule max:30), tanpa lookup
                foreach ([31, 32, 60, 255, 300] as $n) {
                    $r = $post(['id_archive' => str_repeat('a', $n), 'folders' => []]);
                    q26_no500($t, $r, "POST id $n karakter");
                    $t->status($r, 422, "POST id_archive $n karakter -> 422");
                    $t->true($mentions($r), "POST id_archive $n karakter: pesan validasi menyebut id_archive");
                    $t->true(!isset($r[1]['msg_code']) || $r[1]['msg_code'] !== 'ARCHIVE400', "POST id_archive $n karakter: bukan ARCHIVE400");
                }
                $r = $post(['id_archive' => str_repeat('a', 31)]);
                $t->status($r, 422, 'id 31 karakter tanpa folders -> 422');
                $r = $post(['id_archive' => str_repeat('a', 31), 'folders' => 'bukan-array', 'is_continue' => 'x']);
                $t->status($r, 422, 'id 31 karakter + field lain salah -> 422');
                $r = $post(['idArchive' => str_repeat('a', 31), 'folders' => []]);
                $t->status($r, 422, 'camelCase idArchive 31 karakter -> 422');
                $r = $post(['idArchive' => str_repeat('a', 30), 'folders' => []]);
                q26_deny($t, $r, 404, 'ARCHIVE400', 'camelCase idArchive 30 karakter tak ada');

                // 2) batas: 1, 29, 30 karakter yang lolos rule tapi tidak ada -> 404 ARCHIVE400
                foreach ([1, 29, 30] as $n) {
                    $r = $post(['id_archive' => str_repeat('b', $n), 'folders' => []]);
                    q26_deny($t, $r, 404, 'ARCHIVE400', "POST id $n karakter tak ada");
                }
                $r = $post(['id_archive' => Q26_RANDOM_ID, 'folders' => []]);
                q26_deny($t, $r, 404, 'ARCHIVE400', 'POST id acak 30 digit');

                // 3) non-ASCII: <= 30 karakter lolos rule -> 404; > 30 karakter -> 422
                $r = $post(['id_archive' => str_repeat('é', 10), 'folders' => []]);
                q26_no500($t, $r, 'POST id non-ASCII 10');
                q26_deny($t, $r, 404, 'ARCHIVE400', 'POST id non-ASCII 10 karakter');
                $r = $post(['id_archive' => str_repeat('é', 31), 'folders' => []]);
                q26_no500($t, $r, 'POST id non-ASCII 31');
                $t->status($r, 422, 'POST id non-ASCII 31 karakter -> 422');
                $r = $post(['id_archive' => "x\x00y", 'folders' => []]);
                q26_no500($t, $r, 'POST id mengandung NUL');
                $t->true(in_array($r[0], [404, 422], true), 'POST id mengandung NUL: 404/422, status ' . $r[0]);

                // 4) bukan string -> 422
                foreach (['int 123' => 123, 'true' => true, 'array' => ['a'], 'objek' => ['a' => 1], 'float' => 1.5] as $label => $v) {
                    $r = $post(['id_archive' => $v, 'folders' => []]);
                    q26_no500($t, $r, "POST id_archive $label");
                    $t->status($r, 422, "POST id_archive $label -> 422 (rule string)");
                }

                // 5) dokumen -> 400 ARCHIVE417; subfolder 30 karakter tak ada -> 416; 31 karakter -> 422
                $r = $post(['id_archive' => $x['dF1'], 'folders' => []]);
                q26_deny($t, $r, 400, 'ARCHIVE417', 'POST id dokumen');
                $r = $post(['id_archive' => $x['F'], 'folders' => [['id_archive' => str_repeat('c', 30)]]]);
                q26_deny($t, $r, 400, 'ARCHIVE416', 'folders.* 30 karakter tak ada');
                $r = $post(['id_archive' => $x['F'], 'folders' => [['id_archive' => str_repeat('c', 31)]]]);
                $t->status($r, 422, 'folders.* 31 karakter -> 422');
                $t->true(q26_same_state($before, q26_state($t)), 'semua penolakan tidak menyimpan apa pun');

                // 6) folder valid -> satu sesi; string kosong & null = root (pratinjau; Konfirmasi root cukup sekali di AC lain)
                $leaf = q26_folder($t, 'LEAF');
                $r = $post(['id_archive' => $leaf, 'folders' => []]);
                q26_track($r[1]['result']['id_archive_opname'] ?? null);
                $t->status($r, 200, 'POST folder valid');
                $t->eq(q26_opname($t, $r[1]['result']['id_archive_opname'] ?? '')['id_archive'] ?? null, $leaf, 'DB: id_archive = folder valid');
                foreach (['string kosong' => '', 'null' => null] as $label => $v) {
                    $r = q26_preview($t, $s, ['id_archive' => $v, 'folders' => []]);
                    $t->status($r, 200, "preview id_archive $label = root");
                    $t->true($r[1]['result']['id_archive'] === null && $r[1]['result']['scope']['is_root'] === true, "preview id_archive $label -> root");
                }
                $r = $post(['id_archive' => '', 'folders' => []]);
                $t->status($r, 200, 'POST id_archive "" = root');
                q26_track($r[1]['result']['id_archive_opname'] ?? null);
                $row = q26_opname($t, $r[1]['result']['id_archive_opname'] ?? '');
                $t->true($row !== null && $row['id_archive'] === null, 'DB: id_archive "" -> root (NULL)');

                // 7) GET opnames/folders: perilaku sama untuk input yang sama
                $g = function ($id) use ($t, $s) {
                    return q26_folders($t, $s, $id);
                };
                $t->status($g(str_repeat('a', 31)), 422, 'GET folders 31 karakter -> 422');
                q26_deny($t, $g(str_repeat('a', 30)), 404, 'ARCHIVE400', 'GET folders 30 karakter tak ada');
                q26_deny($t, $g(str_repeat('é', 10)), 404, 'ARCHIVE400', 'GET folders non-ASCII 10 karakter');
                $t->status($g(str_repeat('é', 31)), 422, 'GET folders non-ASCII 31 karakter -> 422');

                // 8) urutan penolakan: tanpa token 401 > tanpa permission 403 GE0114 > F luar scope 403 ARCHIVE407 > 422
                $res = $t->raw('POST', $B, ['id_archive' => str_repeat('a', 31), 'folders' => []]);
                $t->status($res, 401, 'tanpa token + id 31 karakter -> 401');
                $mid = q26_state($t);
                foreach ([Q26_PRESET_NONE, Q26_PRESET_LIST] as $role) {
                    q26_with_user($t, ['role' => $role], function () use ($t, $s, $B, $x, $role) {
                        foreach ([str_repeat('a', 31), str_repeat('a', 30), $x['F'], $x['dF1']] as $id) {
                            $r = $t->call($s, 'POST', $B, ['id_archive' => $id, 'folders' => 'x']);
                            q26_deny($t, $r, 403, 'GE0114', "preset $role tanpa Opname Document, id " . strlen($id) . ' karakter');
                            $t->eq($r[1]['parameter'] ?? null, 'Opname Document', "preset $role: parameter");
                        }
                    });
                }
                q26_with_user($t, ['emp' => ['SMR']], function () use ($t, $s, $B, $x) {
                    $r = $t->call($s, 'POST', $B, ['id_archive' => $x['SJ'], 'folders' => 'bukan-array']);
                    q26_deny($t, $r, 403, 'ARCHIVE407', 'user Semarang: F lokasi JOG + body salah -> 403 sebelum 422');
                    $r = $t->call($s, 'POST', $B, ['id_archive' => str_repeat('a', 31), 'folders' => []]);
                    $t->status($r, 422, 'user Semarang: id 31 karakter -> 422');
                    $r = $t->call($s, 'POST', $B, ['id_archive' => $x['F'], 'folders' => 'bukan-array']);
                    $t->status($r, 422, 'user Semarang: F valid + body salah -> 422');
                });
                $t->true(q26_same_state($mid, q26_state($t)), 'penolakan role/lokasi tidak menyimpan sesi');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'R2-D2',
        'title' => 'D-2: scanned_at DATETIME(6) pada Konfirmasi: urutan scan terbaru dulu pasti (filter, halaman kecil, dibaca superadmin); scan satu detik, waktu mundur, kode ganda; format response Y-m-d H:i:s',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $F = q26_folder($t, 'F');
                $OTH = q26_folder($t, 'OTH');
                $verified = [];
                for ($i = 0; $i < 8; $i++) {
                    $verified[] = q26_n($t, q26_doc($t, 'V' . $i, ['parent' => $F]));
                }
                $notFound = [];
                for ($i = 0; $i < 4; $i++) {
                    $notFound[] = q26_n($t, q26_doc($t, 'N' . $i, ['parent' => $OTH]));
                }
                $invalid = [];
                for ($i = 0; $i < 5; $i++) {
                    $invalid[] = q26_name('NOPE' . $i);
                }
                $unscanned = [];
                for ($i = 0; $i < 3; $i++) {
                    $unscanned[] = q26_n($t, q26_doc($t, 'U' . $i, ['parent' => $F]));
                }
                $codes = array_merge($verified, $notFound, $invalid);
                mt_srand(26);
                shuffle($codes); // verified / not found / invalid saling berselang
                $kind = [];
                foreach ($verified as $c) { $kind[$c] = 1; }
                foreach ($notFound as $c) { $kind[$c] = 2; }
                foreach ($invalid as $c) { $kind[$c] = 3; }

                // Step 1 + scan beruntun (waktu server, banyak dalam detik yang sama) + Konfirmasi
                $sel = q26_selection($F);
                $selected = q26_selected($t, $s, $F);
                $secs = [];
                $scans = [];
                foreach ($codes as $c) {
                    $r = q26_scan($t, $s, $sel, $c);
                    $t->status($r, 200, "scan $c");
                    $at = $r[1]['result']['row']['scanned_at'] ?? null;
                    $t->true(is_string($at) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $at) === 1, 'response /scan scanned_at = Y-m-d H:i:s (' . var_export($at, true) . ')');
                    $secs[$at] = ($secs[$at] ?? 0) + 1;
                    $scans[] = ['code' => $c, 'scanned_at' => $at];
                }
                $t->true(max($secs) > 1, 'prasyarat: beberapa scan jatuh pada detik yang sama (maks ' . max($secs) . ' per detik)');
                $r = q26_store($t, $s, $sel, $scans, $selected);
                $t->status($r, 200, 'Konfirmasi');
                $id = $r[1]['result']['id_archive_opname'];

                // DB: scanned_at 26 karakter (mikrodetik), naik ketat sesuai urutan scans
                $db = q26r2_rows_db($t, $id);
                $t->eq(array_map(function ($r) { return $r[0]; }, $db), $codes, 'DB: urutan scanned_at naik = urutan scans');
                $micro = true;
                foreach ($db as $row) {
                    $micro = $micro && strlen($row[1]) === 26 && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{6}$/', $row[1]) === 1;
                }
                $t->true($micro, 'DB: scanned_at format Y-m-d H:i:s.uuuuuu');
                $t->true(q26r2_strict($db), 'DB: scanned_at naik ketat (tanpa nilai kembar) per scan dalam sesi');
                $t->true(count(array_unique(array_map(function ($r) { return substr($r[1], 0, 19); }, $db))) < count($db), 'DB: ada nilai satu detik yang sama dengan mikrodetik berbeda');
                $dbMap = [];
                foreach ($db as $row) { $dbMap[$row[0]] = $row[1]; }

                // GET documents: scanned terbaru dulu, halaman kecil melintasi batas
                $expect = array_reverse($codes);
                $rows = q26_all_docs($t, $s, $id, 'scanned', 4);
                $t->eq(q26r2_codes($rows), $expect, 'documents?result=scanned (pagination 4): urut scan terbaru dulu');
                foreach ($rows as $row) {
                    $t->true(preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $row['scanned_at']) === 1, 'documents: scanned_at Y-m-d H:i:s');
                    $t->eq($row['scanned_at'], substr($dbMap[$row['code']], 0, 19), 'documents: scanned_at = DB (detik)');
                }
                $t->eq(q26r2_codes(q26_all_docs($t, $s, $id, 'scanned', 100)), $expect, 'documents?result=scanned (pagination 100) sama');
                foreach ([1 => 'verified', 2 => 'not_found', 3 => 'invalid'] as $k => $name) {
                    $sub = array_values(array_filter($expect, function ($c) use ($kind, $k) { return $kind[$c] === $k; }));
                    $t->eq(q26r2_codes(q26_all_docs($t, $s, $id, $name, 3)), $sub, "documents?result=$name: urut scan terbaru dulu");
                }
                $sortedUn = $unscanned;
                usort($sortedUn, 'strcasecmp');
                $t->eq(q26r2_codes(q26_all_docs($t, $s, $id, 'all', 6)), array_merge($expect, $sortedUn), 'documents?result=all: scan terbaru dulu, lalu belum discan per code');
                // pratinjau (sebelum Konfirmasi) urutannya sama dengan tersimpan
                $t->eq(q26r2_codes(q26_pall($t, $s, $sel, $scans, 'scanned', 4)), $expect, 'preview/documents scanned (pagination 4): urutan sama dengan yang tersimpan');

                // kode ganda dan waktu mundur di pratinjau & Konfirmasi: tidak menggeser urutan; DB menjaga naik ketat
                $leaf = q26_folder($t, 'LEAF');
                $ln = [];
                foreach (['a', 'b', 'c', 'd'] as $k) {
                    $ln[$k] = q26_n($t, q26_doc($t, "L$k", ['parent' => $leaf]));
                }
                $day = q26_today();
                $sel2 = q26_selection($leaf);
                $list = [
                    ['code' => $ln['a'], 'scanned_at' => "$day 10:00:05"],
                    ['code' => $ln['b'], 'scanned_at' => "$day 10:00:03"],   // waktu mundur
                    ['code' => strtoupper($ln['a']), 'scanned_at' => "$day 10:00:04"], // kode ganda: dibuang, tak menggeser
                    ['code' => $ln['c'], 'scanned_at' => "$day 10:00:03"],   // sama dengan sebelumnya
                    ['code' => $ln['d'], 'scanned_at' => "$day 10:00:09"],
                ];
                $r = q26_store($t, $s, $sel2, $list, q26_selected($t, $s, $leaf));
                $t->status($r, 200, 'Konfirmasi dengan waktu mundur & kode ganda');
                $id2 = $r[1]['result']['id_archive_opname'];
                $db2 = q26r2_rows_db($t, $id2);
                $t->eq(array_column($db2, 0), [$ln['a'], $ln['b'], $ln['c'], $ln['d']], 'DB: urutan = urutan scans tanpa kode ganda');
                $t->eq(array_column($db2, 1), ["$day 10:00:05.000000", "$day 10:00:05.000001", "$day 10:00:05.000002", "$day 10:00:09.000000"], 'DB: waktu mundur/sama dinaikkan +1 mikrodetik dari scan sebelumnya; kode ganda tak menambah');
                $t->eq(q26r2_codes(q26_all_docs($t, $s, $id2, 'scanned', 3)), [$ln['d'], $ln['c'], $ln['b'], $ln['a']], 'documents: terbaru dulu = scans terbalik (pagination 3)');
                $t->eq(array_column(q26_all_docs($t, $s, $id2, 'scanned', 10), 'scanned_at'), ["$day 10:00:09", "$day 10:00:05", "$day 10:00:05", "$day 10:00:05"], 'documents: scanned_at response Y-m-d H:i:s (detik)');

                // dibaca superadmin 1 (sesi milik user lain bagi mereka): urutan sama
                q26_with_user($t, ['role' => 1], function () use ($t, $s, $id, $expect, $sortedUn) {
                    $t->eq(q26r2_codes(q26_all_docs($t, $s, $id, 'all', 50)), array_merge($expect, $sortedUn), 'superadmin 1 membaca sesi terkonfirmasi: urutan sama');
                });
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'R2-SCANVOL',
        'title' => 'D-2 (volume): 100 scan beruntun (POST opnames/scan) lalu satu Konfirmasi: scanned_at naik ketat, urutan tabel benar, scan terakhir tidak melambat berarti',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $F = q26_folder($t, 'F');
                $codes = [];
                for ($i = 0; $i < 30; $i++) {
                    $codes[] = q26_n($t, q26_doc($t, 'W' . $i, ['parent' => $F]));
                }
                for ($i = 0; $i < 70; $i++) {
                    $codes[] = q26_name('INV' . $i);
                }
                mt_srand(7);
                shuffle($codes);
                $sel = q26_selection($F);
                $selected = q26_selected($t, $s, $F);
                $before = q26_state($t);
                $times = [];
                $scans = [];
                foreach ($codes as $c) {
                    $t0 = microtime(true);
                    $r = q26_scan($t, $s, $sel, $c);
                    $times[] = microtime(true) - $t0;
                    if ($r[0] !== 200) {
                        $t->fail("scan $c: HTTP {$r[0]}");
                    }
                    $scans[] = ['code' => $c, 'scanned_at' => $r[1]['result']['row']['scanned_at']];
                }
                $first = array_sum(array_slice($times, 0, 10)) / 10;
                $last = array_sum(array_slice($times, -10)) / 10;
                $t->note(sprintf('scan rata-rata 10 pertama %.2f dtk, 10 terakhir %.2f dtk, maks %.2f dtk', $first, $last, max($times)));
                $t->true(max($times) < 10, 'tidak ada scan > 10 dtk (maks ' . round(max($times), 2) . ')');
                $t->true(q26_same_state($before, q26_state($t)), '100 scan tanpa tulis');
                $r = q26_store($t, $s, $sel, $scans, $selected);
                $t->status($r, 200, 'Konfirmasi 100 scan');
                $id = $r[1]['result']['id_archive_opname'];
                $db = q26r2_rows_db($t, $id);
                $t->eq(count($db), 100, 'DB: 100 baris scan');
                $t->eq(array_map(function ($r) { return $r[0]; }, $db), $codes, 'DB: urutan scanned_at = urutan scans (100 scan)');
                $t->true(q26r2_strict($db), 'DB: scanned_at naik ketat untuk 100 scan');
                $rows = q26_all_docs($t, $s, $id, 'scanned', 25);
                $t->eq(q26r2_codes($rows), array_reverse($codes), 'documents?result=scanned: 100 baris urut scan terbaru dulu');
                $show = q26_show($t, $s, $id);
                $t->eq($show[1]['result']['counts']['scanned'] ?? null, 100, 'show: scanned 100');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'R2-PARALLEL-DIFF',
        'title' => 'D-2 (konkurensi): scan kode BERBEDA bersamaan: semua 200, klasifikasi benar, tanpa tulis; Konfirmasi sesudahnya menyimpan scanned_at tidak kembar dan urutan tabel = urutan scans',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $F = q26_folder($t, 'F');
                $sel = q26_selection($F);
                $selected = q26_selected($t, $s, $F);
                $before = null;
                $all = [];
                $scans = [];
                for ($round = 0; $round < 4; $round++) {
                    $codes = [];
                    for ($i = 0; $i < 8; $i++) {
                        $codes[] = q26_n($t, q26_doc($t, "P{$round}x{$i}", ['parent' => $F]));
                    }
                    if ($before === null) {
                        $before = q26_state($t);
                    }
                    $reqs = [];
                    foreach ($codes as $code) {
                        $reqs[] = ['POST', Q26_BASE . '/opnames/scan', $sel + ['code' => $code]];
                    }
                    $res = q26c_parallel($t, $s, $reqs);
                    $t->eq(array_column($res, 0), array_fill(0, 8, 200), "putaran $round: 8 scan berbeda bersamaan semua 200");
                    foreach ($res as $i => $r) {
                        $t->eq($r[1]['result']['row']['result'] ?? null, 'verified', "putaran $round: scan $i verified");
                        $t->eq($r[1]['result']['row']['code'] ?? null, $codes[$i], "putaran $round: scan $i code");
                        $scans[] = ['code' => $codes[$i], 'scanned_at' => $r[1]['result']['row']['scanned_at']];
                    }
                    $all = array_merge($all, $codes);
                }
                $t->eq(count($scans), 32, '32 scan terkumpul');
                $r = q26_store($t, $s, $sel, $scans, $selected);
                $t->status($r, 200, 'Konfirmasi sesudah scan bersamaan');
                $id = $r[1]['result']['id_archive_opname'];
                $db = q26r2_rows_db($t, $id);
                $t->eq(count($db), 32, 'DB: 32 baris scan');
                $t->eq(count($db) - count(array_unique(array_map(function ($r) { return $r[1]; }, $db))), 0, 'DB: scanned_at tidak ada yang kembar (urutan scan pasti)');
                $t->eq(array_map(function ($r) { return $r[0]; }, $db), $all, 'DB: urutan scanned_at = urutan scans');
                $rows = q26_all_docs($t, $s, $id, 'scanned', 9);
                $t->eq(q26r2_codes($rows), array_reverse($all), 'tabel = urutan scans terbalik (scan terbaru dulu)');
                $t->eq(count(array_unique(array_map('strtolower', q26r2_codes($rows)))), 32, 'tidak ada baris ganda');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'R2-SCHEMA',
        'title' => 'Updater berubah: scanned_at datetime(6) di DB QA; DB yang sudah menjalankan versi awal (scanned_at DATETIME) -> MODIFY sekali, data utuh, idempoten, skema = DB QA',
        'run'   => function ($t) {
            $c = $t->db();
            $db = q26_dbname($t);

            // DB QA: presisi
            $col = $c->selectOne("SELECT datetime_precision p, is_nullable n, column_default d, column_type ct FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'archive_opname_documents' AND column_name = 'scanned_at'");
            $t->eq((int) $col->p, 6, 'DB QA: scanned_at datetime_precision 6');
            $t->eq($col->ct, 'datetime(6)', 'DB QA: column_type datetime(6)');
            $t->eq($col->n, 'YES', 'DB QA: scanned_at nullable');
            $show = $c->selectOne("SHOW COLUMNS FROM archive_opname_documents LIKE 'scanned_at'");
            $t->true($show->Default === null, 'DB QA: scanned_at default NULL (SHOW COLUMNS)');
            foreach (['archive_opname_documents' => ['created_at'], 'archive_opnames' => ['selected_at', 'confirmed_at', 'created_at', 'updated_at'], 'archive_opname_folders' => ['created_at']] as $table => $cols) {
                foreach ($cols as $cn) {
                    $p = $c->selectOne('SELECT datetime_precision p FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $cn]);
                    $t->eq((int) $p->p, 0, "DB QA: $table.$cn tetap datetime (tanpa pecahan)");
                }
            }

            $beDir = $t->probe(function () {
                return base_path();
            });
            $files = glob($beDir . '/Modules/UpdateVersion/Updaters/*_AddArchiveOpname.php');
            $t->eq(count($files), 1, 'tepat satu berkas Updater AddArchiveOpname');
            $src = file_get_contents($files[0]);
            $t->true(strpos($src, 'DATETIME(6)') !== false, 'Updater: scanned_at DATETIME(6) pada CREATE TABLE');

            // DB scratch: meniru DB yang sudah menjalankan versi awal (scanned_at DATETIME, ada data)
            $scratch = 'qa26_scratch_' . substr(uniqid(), -6);
            $res = null;
            $t->probe(function () use ($db, $files, $scratch, &$res) {
                $D = \Illuminate\Support\Facades\DB::class;
                $res = ['error' => null];
                $srcConn = $D::connection($db);
                $cfg = $srcConn->getConfig();
                $srcConn->statement("CREATE DATABASE `$scratch` CHARACTER SET latin1 COLLATE latin1_general_ci");
                try {
                    $cfg['database'] = $scratch;
                    config(['database.connections.qa26scratch' => $cfg]);
                    $D::purge('qa26scratch');
                    $s = $D::connection('qa26scratch');
                    $s->statement("CREATE TABLE archives LIKE `$db`.archives");
                    foreach (['archive_opnames', 'archive_opname_folders', 'archive_opname_documents'] as $tb) {
                        $s->statement("CREATE TABLE `$tb` LIKE `$db`.`$tb`");
                    }
                    // bentuk versi awal: scanned_at DATETIME (tanpa pecahan)
                    $s->statement('ALTER TABLE archive_opname_documents MODIFY scanned_at DATETIME NULL');
                    $s->table('archive_opname_documents')->insert([
                        ['id_archive_opname_document' => 'qa26a', 'id_archive_opname' => 'qa26o', 'id_archive' => null, 'id_archive_folder' => null, 'code' => 'AAA', 'result' => 3, 'is_verified_after' => null, 'scanned_at' => '2026-10-07 10:00:00', 'created_at' => '2026-10-07 10:00:00'],
                        ['id_archive_opname_document' => 'qa26b', 'id_archive_opname' => 'qa26o', 'id_archive' => null, 'id_archive_folder' => null, 'code' => 'BBB', 'result' => 4, 'is_verified_after' => 0, 'scanned_at' => null, 'created_at' => '2026-10-07 10:00:00'],
                        ['id_archive_opname_document' => 'qa26c', 'id_archive_opname' => 'qa26o', 'id_archive' => null, 'id_archive_folder' => null, 'code' => 'CCC', 'result' => 3, 'is_verified_after' => null, 'scanned_at' => '2026-10-07 10:00:01', 'created_at' => '2026-10-07 10:00:01'],
                    ]);
                    $prec = function () use ($s) {
                        $p = $s->selectOne("SELECT datetime_precision p FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'archive_opname_documents' AND column_name = 'scanned_at'");

                        return (int) $p->p;
                    };
                    $res['precision_before'] = $prec();
                    $D::setDefaultConnection('qa26scratch');
                    $updater = require $files[0];
                    $updater->run();
                    $res['precision_after1'] = $prec();
                    $res['rows_after1'] = $s->table('archive_opname_documents')->orderBy('code')->get()->map(function ($r) { return [$r->code, $r->result, $r->scanned_at]; })->all();
                    $res['create_after1'] = array_values((array) $s->selectOne('SHOW CREATE TABLE archive_opname_documents'))[1];
                    $updater->run();
                    $res['precision_after2'] = $prec();
                    $res['create_after2'] = array_values((array) $s->selectOne('SHOW CREATE TABLE archive_opname_documents'))[1];
                    $res['rows_after2'] = $s->table('archive_opname_documents')->orderBy('code')->get()->map(function ($r) { return [$r->code, $r->result, $r->scanned_at]; })->all();
                    $res['create_qa'] = array_values((array) $srcConn->selectOne('SHOW CREATE TABLE archive_opname_documents'))[1];
                    // mikrodetik bisa disimpan setelah MODIFY
                    $s->table('archive_opname_documents')->insert(['id_archive_opname_document' => 'qa26d', 'id_archive_opname' => 'qa26o', 'code' => 'DDD', 'result' => 3, 'scanned_at' => '2026-10-07 10:00:02.123456', 'created_at' => '2026-10-07 10:00:02']);
                    $res['micro'] = (string) $s->table('archive_opname_documents')->where('code', 'DDD')->value('scanned_at');
                    // jalankan juga di DB tanpa tabel opname sama sekali, dan di DB yang sudah datetime(6) (QA) tidak berubah: sudah di AC-1
                } catch (\Throwable $e) {
                    $res['error'] = get_class($e) . ': ' . $e->getMessage();
                } finally {
                    $D::setDefaultConnection($db);
                    $srcConn->statement("DROP DATABASE IF EXISTS `$scratch`");
                    $D::purge('qa26scratch');
                }
            });
            $t->eq($res['error'], null, 'Updater pada DB versi awal: tanpa error');
            $t->eq($res['precision_before'] ?? null, 0, 'scratch: sebelum Updater scanned_at datetime tanpa pecahan');
            $t->eq($res['precision_after1'] ?? null, 6, 'scratch: sesudah Updater scanned_at datetime(6)');
            $t->eq($res['rows_after1'] ?? null, [['AAA', 3, '2026-10-07 10:00:00.000000'], ['BBB', 4, null], ['CCC', 3, '2026-10-07 10:00:01.000000']], 'scratch: data tetap (NULL tetap NULL, detik tetap)');
            $t->eq($res['precision_after2'] ?? null, 6, 'scratch: run ke-2 tetap datetime(6)');
            $t->true(($res['create_after1'] ?? 'a') === ($res['create_after2'] ?? 'b'), 'scratch: SHOW CREATE TABLE run ke-2 tidak berubah');
            $t->eq($res['rows_after2'] ?? null, $res['rows_after1'] ?? 'x', 'scratch: data run ke-2 tidak berubah');
            $norm = function ($sql) {
                return preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) $sql);
            };
            $t->true($norm($res['create_after2'] ?? 'a') === $norm($res['create_qa'] ?? 'b'), 'scratch: skema akhir identik dengan DB QA');
            $t->eq($res['micro'] ?? null, '2026-10-07 10:00:02.123456', 'scratch: mikrodetik tersimpan sesudah Updater');
            $left = $t->db()->selectOne("SELECT COUNT(*) n FROM information_schema.schemata WHERE schema_name LIKE 'qa26_scratch_%'")->n;
            $t->eq($left, 0, 'DB scratch dibuang');
        },
    ],

];
