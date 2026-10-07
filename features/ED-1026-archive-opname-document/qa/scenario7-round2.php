<?php
/**
 * ED-1026 - ronde 2 (verifikasi perbaikan D-1 dan D-2 + Updater yang berubah).
 *   R2-D1       POST opnames id_archive: rule-nya sendiri (bukan string / > 30 karakter) -> 422 tanpa lookup; lolos rule tapi
 *               tidak ada -> 404 ARCHIVE400; urutan 401 > 403 GE0114 > 403 ARCHIVE407 > 422; GET folders sama; tak ada sesi tercipta.
 *   R2-D2       scanned_at DATETIME(6): urutan scan terbaru dulu pasti (draft, filter, sesudah Confirm, dibaca superadmin);
 *               jam mundur / limpah detik; format response Y-m-d H:i:s; duplikat tidak menggeser urutan.
 *   R2-SCANVOL  100 scan beruntun: urutan monoton, tanpa pelambatan berarti.
 *   R2-SCHEMA   scanned_at = datetime(6) di DB QA; Updater pada DB yang sudah menjalankan versi awalnya (scanned_at DATETIME) ->
 *               MODIFY sekali, data utuh, run ulang tanpa perubahan, skema = DB QA.
 */
require_once __DIR__ . '/qa_lib.php';

if (!function_exists('q26r2_rows_db')) {
    /** [[code, scanned_at(raw, 26 karakter)]] baris scan sesi urut scanned_at naik. */
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
}

return [

    [
        'id'    => 'R2-D1',
        'title' => 'D-1: POST opnames id_archive > 30 karakter / bukan string -> 422 (bukan 404); 30 karakter tak ada -> 404 ARCHIVE400; urutan penolakan; GET folders konsisten',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $B = Q26_BASE . '/opnames';
                $count = function () use ($t) {
                    return $t->db()->table('archive_opnames')->count();
                };
                $before = $count();
                $post = function ($body, $session = null) use ($t, $s, $B) {
                    return $t->call($session ?: $s, 'POST', $B, $body);
                };
                $mentions = function ($r) {
                    return strpos(json_encode($r[1]), 'id_archive') !== false;
                };

                // 1) panjang: 31..300 karakter -> 422 (rule max:30), tanpa lookup
                foreach ([31, 32, 60, 255, 300] as $n) {
                    $r = $post(['id_archive' => str_repeat('a', $n), 'folders' => []]);
                    q26_no500($t, $r, "POST id $n karakter");
                    $t->status($r, 422, "POST id_archive $n karakter -> 422");
                    $t->true($mentions($r), "POST id_archive $n karakter: pesan validasi menyebut id_archive");
                    $t->true(!isset($r[1]['msg_code']) || $r[1]['msg_code'] !== 'ARCHIVE400', "POST id_archive $n karakter: bukan ARCHIVE400");
                }
                // tanpa body lain & dengan body lain yang juga salah
                $r = $post(['id_archive' => str_repeat('a', 31)]);
                $t->status($r, 422, 'id 31 karakter tanpa folders -> 422');
                $r = $post(['id_archive' => str_repeat('a', 31), 'folders' => 'bukan-array', 'is_continue' => 'x']);
                $t->status($r, 422, 'id 31 karakter + field lain salah -> 422');
                // camelCase level terluar
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

                // 5) dokumen -> 400 ARCHIVE417; folder valid -> 200 (lalu dibatalkan); string kosong & null -> root 200
                $r = $post(['id_archive' => $x['dF1'], 'folders' => []]);
                q26_deny($t, $r, 400, 'ARCHIVE417', 'POST id dokumen');
                $r = $post(['id_archive' => $x['F'], 'folders' => []]);
                $t->status($r, 200, 'POST folder valid');
                q26_track($r[1]['result']['id_archive_opname'] ?? '');
                $created = [$r[1]['result']['id_archive_opname'] ?? null];
                foreach (['string kosong' => '', 'null' => null] as $label => $v) {
                    $r = $post(['id_archive' => $v, 'folders' => []]);
                    $t->status($r, 200, "POST id_archive $label = root");
                    q26_track($r[1]['result']['id_archive_opname'] ?? '');
                    $created[] = $r[1]['result']['id_archive_opname'] ?? null;
                    $row = q26_opname($t, $r[1]['result']['id_archive_opname'] ?? '');
                    $t->true($row !== null && $row['id_archive'] === null, "DB: id_archive $label -> root (NULL)");
                }
                // subfolder 30 karakter tak ada -> 416; 31 karakter -> 422 (folders.*.id_archive)
                $r = $post(['id_archive' => $x['F'], 'folders' => [['id_archive' => str_repeat('c', 30)]]]);
                q26_deny($t, $r, 400, 'ARCHIVE416', 'folders.* 30 karakter tak ada');
                $r = $post(['id_archive' => $x['F'], 'folders' => [['id_archive' => str_repeat('c', 31)]]]);
                $t->status($r, 422, 'folders.* 31 karakter -> 422');
                $t->eq($count(), $before + 3, 'hanya 3 sesi yang sah tercipta; semua penolakan tidak menyimpan apa pun');

                // 6) GET opnames/folders: perilaku sama untuk input yang sama
                $g = function ($id) use ($t, $s) {
                    return q26_folders($t, $s, $id);
                };
                $t->status($g(str_repeat('a', 31)), 422, 'GET folders 31 karakter -> 422');
                q26_deny($t, $g(str_repeat('a', 30)), 404, 'ARCHIVE400', 'GET folders 30 karakter tak ada');
                q26_deny($t, $g(str_repeat('é', 10)), 404, 'ARCHIVE400', 'GET folders non-ASCII 10 karakter');
                $t->status($g(str_repeat('é', 31)), 422, 'GET folders non-ASCII 31 karakter -> 422');

                // 7) urutan penolakan: tanpa token 401 > tanpa permission 403 GE0114 > F luar scope 403 ARCHIVE407 > 422
                $res = $t->raw('POST', $B, ['id_archive' => str_repeat('a', 31), 'folders' => []]);
                $t->status($res, 401, 'tanpa token + id 31 karakter -> 401');
                foreach ([Q26_ROLE_NONE, Q26_ROLE_LIST_ONLY] as $role) {
                    q26_with_user($t, ['role' => $role], function () use ($t, $s, $B, $x, $role) {
                        foreach ([str_repeat('a', 31), str_repeat('a', 30), $x['F'], $x['dF1']] as $id) {
                            $r = $t->call($s, 'POST', $B, ['id_archive' => $id, 'folders' => 'x']);
                            q26_deny($t, $r, 403, 'GE0114', "role $role tanpa Opname Document, id " . strlen($id) . ' karakter');
                            $t->eq($r[1]['parameter'] ?? null, 'Opname Document', "role $role: parameter");
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
                $t->eq($count(), $before + 3, 'penolakan role/lokasi tidak menyimpan sesi');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'R2-D2',
        'title' => 'D-2: scanned_at DATETIME(6): urutan scan terbaru dulu pasti (draft, filter, sesudah Confirm, dibaca superadmin); jam mundur / limpah detik; format Y-m-d H:i:s',
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

                $id = q26_session($t, $s, $F, []);
                $respTimes = [];
                $secs = [];
                foreach ($codes as $c) {
                    $r = q26_scan($t, $s, $id, $c);
                    $t->status($r, 200, "scan $c");
                    $at = $r[1]['result']['row']['scanned_at'] ?? null;
                    $respTimes[] = $at;
                    $t->true(is_string($at) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $at) === 1, 'response /scan scanned_at = Y-m-d H:i:s (' . var_export($at, true) . ')');
                    $secs[$at] = ($secs[$at] ?? 0) + 1;
                }
                $t->true(max($secs) > 1, 'prasyarat: beberapa scan jatuh pada detik yang sama (maks ' . max($secs) . ' per detik)');

                // DB: scanned_at 26 karakter (mikrodetik), naik ketat sesuai urutan scan
                $db = q26r2_rows_db($t, $id);
                $t->eq(array_map(function ($r) { return $r[0]; }, $db), $codes, 'DB: urutan scanned_at naik = urutan scan');
                $strict = true;
                $micro = true;
                for ($i = 0; $i < count($db); $i++) {
                    $micro = $micro && strlen($db[$i][1]) === 26 && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{6}$/', $db[$i][1]) === 1;
                    if ($i > 0 && strcmp($db[$i][1], $db[$i - 1][1]) <= 0) {
                        $strict = false;
                    }
                }
                $t->true($micro, 'DB: scanned_at format Y-m-d H:i:s.uuuuuu');
                $t->true($strict, 'DB: scanned_at naik ketat (tanpa nilai kembar) per scan dalam sesi');
                $t->true(count(array_unique(array_map(function ($r) { return substr($r[1], 0, 19); }, $db))) < count($db), 'DB: ada nilai satu detik yang sama dengan mikrodetik berbeda');
                $dbMap = [];
                foreach ($db as $r) { $dbMap[$r[0]] = $r[1]; }

                // GET documents: scanned terbaru dulu, halaman kecil melintasi batas
                $expect = array_reverse($codes);
                $rows = q26_all_docs($t, $s, $id, 'scanned', 4);
                $t->eq(q26r2_codes($rows), $expect, 'documents?result=scanned (pagination 4): urut scan terbaru dulu');
                foreach ($rows as $row) {
                    $t->true(preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $row['scanned_at']) === 1, 'documents: scanned_at Y-m-d H:i:s');
                    $t->eq($row['scanned_at'], substr($dbMap[$row['code']], 0, 19), 'documents: scanned_at = DB (detik)');
                }
                $t->eq(q26r2_codes(q26_all_docs($t, $s, $id, 'scanned', 100)), $expect, 'documents?result=scanned (pagination 100) sama');
                // filter per hasil: subset dengan urutan kebalikan scan
                foreach ([1 => 'verified', 2 => 'not_found', 3 => 'invalid'] as $k => $name) {
                    $sub = array_values(array_filter($expect, function ($c) use ($kind, $k) { return $kind[$c] === $k; }));
                    $t->eq(q26r2_codes(q26_all_docs($t, $s, $id, $name, 3)), $sub, "documents?result=$name: urut scan terbaru dulu");
                }
                // all: scan terbaru dulu, lalu belum discan per code naik
                $sortedUn = $unscanned;
                usort($sortedUn, 'strcasecmp');
                $all = q26_all_docs($t, $s, $id, 'all', 6);
                $t->eq(q26r2_codes($all), array_merge($expect, $sortedUn), 'documents?result=all: scan terbaru dulu, lalu belum discan per code');

                // scan duplikat: tidak menggeser urutan / scanned_at
                $dup = $codes[3];
                $r = q26_scan($t, $s, $id, strtoupper($dup));
                $t->status($r, 200, 'scan ulang (huruf besar)');
                $t->eq($r[1]['result']['is_duplicate'] ?? null, true, 'duplikat: is_duplicate true');
                $t->eq($r[1]['result']['row']['scanned_at'] ?? null, substr($dbMap[$dup], 0, 19), 'duplikat: scanned_at baris lama');
                $t->eq(q26r2_codes(q26_all_docs($t, $s, $id, 'scanned', 100)), $expect, 'duplikat: urutan tetap');
                $db2 = q26r2_rows_db($t, $id);
                $t->eq($db2, $db, 'duplikat: DB tidak berubah (kode, scanned_at, result)');

                // jam mundur: scan terakhir "di masa depan" -> scan baru harus tetap sesudahnya (+1 mikrodetik)
                $lastCode = $codes[count($codes) - 1];
                q26_w($t, function ($c) use ($id, $lastCode) {
                    $c->table('archive_opname_documents')->where('id_archive_opname', $id)->where('code', $lastCode)
                        ->update(['scanned_at' => '2099-01-01 00:00:00.500000']);
                });
                $newA = q26_name('NEWA');
                $r = q26_scan($t, $s, $id, $newA);
                $t->status($r, 200, 'scan sesudah scan terakhir bertanggal masa depan');
                $rowA = $t->db()->table('archive_opname_documents')->where('id_archive_opname', $id)->where('code', $newA)->first();
                $t->eq((string) $rowA->scanned_at, '2099-01-01 00:00:00.500001', 'jam mundur: scanned_at baru = terakhir + 1 mikrodetik');
                $rows = q26_all_docs($t, $s, $id, 'scanned', 50);
                $t->eq($rows[0]['code'] ?? null, $newA, 'jam mundur: scan baru di baris pertama');
                $t->eq(count($rows), count($codes) + 1, 'jam mundur: jumlah baris scan');

                // limpah detik: terakhir .999999 -> scan berikut = detik berikutnya .000000
                q26_w($t, function ($c) use ($id, $newA) {
                    $c->table('archive_opname_documents')->where('id_archive_opname', $id)->where('code', $newA)
                        ->update(['scanned_at' => '2099-01-01 23:59:59.999999']);
                });
                $newB = q26_name('NEWB');
                $r = q26_scan($t, $s, $id, $newB);
                $t->status($r, 200, 'scan sesudah .999999');
                $rowB = $t->db()->table('archive_opname_documents')->where('id_archive_opname', $id)->where('code', $newB)->first();
                $t->eq((string) $rowB->scanned_at, '2099-01-02 00:00:00.000000', 'limpah: .999999 + 1 mikrodetik = detik berikutnya');
                $t->eq($r[1]['result']['row']['scanned_at'] ?? null, '2099-01-02 00:00:00', 'limpah: response Y-m-d H:i:s');
                $kind[$newA] = 3; // kode baru tak dikenal = Invalid
                $kind[$newB] = 3;
                $full = array_merge([$newB, $newA], $expect);
                $t->eq(q26r2_codes(q26_all_docs($t, $s, $id, 'scanned', 7)), $full, 'sesudah jam mundur + limpah: urutan penuh benar');

                // Back/Next (PUT): scan dipertahankan dan urutan tetap
                $r = q26_update($t, $s, $id, []);
                $t->status($r, 200, 'PUT pilihan folder');
                $t->eq(q26r2_codes(q26_all_docs($t, $s, $id, 'scanned', 100)), $full, 'sesudah PUT: urutan tetap');

                // Confirm: snapshot menyalin scanned_at (mikrodetik) dan urutan sama
                $pre = [];
                foreach (q26r2_rows_db($t, $id) as $r) { $pre[$r[0]] = $r[1]; }
                $r = q26_confirm($t, $s, $id);
                $t->status($r, 200, 'confirm');
                $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE213', 'confirm: ARCHIVE213');
                $post = [];
                foreach (q26r2_rows_db($t, $id) as $r) { $post[$r[0]] = $r[1]; }
                $t->eq($post, $pre, 'DB: scanned_at snapshot sama persis (mikrodetik) dengan draft');
                $t->eq(array_map('strtolower', q26r2_codes(q26_all_docs($t, $s, $id, 'scanned', 4))), array_map('strtolower', $full), 'terkonfirmasi: scanned urut scan terbaru dulu (pagination 4)');
                foreach ([1 => 'verified', 2 => 'not_found', 3 => 'invalid'] as $k => $name) {
                    $sub = array_values(array_filter($full, function ($c) use ($kind, $k) { return isset($kind[$c]) && $kind[$c] === $k; }));
                    $t->eq(q26r2_codes(q26_all_docs($t, $s, $id, $name, 3)), $sub, "terkonfirmasi: result=$name urut scan terbaru dulu");
                }
                $allAfter = q26_all_docs($t, $s, $id, 'all', 6);
                $t->eq(q26r2_codes($allAfter), array_merge($full, $sortedUn), 'terkonfirmasi: all = scan terbaru dulu, lalu belum discan per code');
                foreach ($allAfter as $row) {
                    if ($row['scanned_at'] !== null) {
                        $t->true(preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row['scanned_at']) === 1, 'terkonfirmasi: scanned_at Y-m-d H:i:s');
                    } else {
                        $t->eq($row['result'], 'unscanned', 'terkonfirmasi: scanned_at null hanya untuk belum discan');
                    }
                }
                // dibaca superadmin 1 (sesi milik user lain bagi mereka): urutan sama
                q26_with_user($t, ['role' => 1], function () use ($t, $s, $id, $full, $sortedUn) {
                    $t->eq(q26r2_codes(q26_all_docs($t, $s, $id, 'all', 50)), array_merge($full, $sortedUn), 'superadmin 1 membaca sesi terkonfirmasi: urutan sama');
                });
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'R2-SCANVOL',
        'title' => 'D-2 (volume): 100 scan beruntun dalam satu sesi: scanned_at naik ketat, urutan tabel benar, scan terakhir tidak melambat berarti',
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
                $id = q26_session($t, $s, $F, []);
                $times = [];
                foreach ($codes as $c) {
                    $t0 = microtime(true);
                    $r = q26_scan($t, $s, $id, $c);
                    $times[] = microtime(true) - $t0;
                    if ($r[0] !== 200) {
                        $t->fail("scan $c: HTTP {$r[0]}");
                    }
                }
                $first = array_sum(array_slice($times, 0, 10)) / 10;
                $last = array_sum(array_slice($times, -10)) / 10;
                $t->note(sprintf('scan rata-rata 10 pertama %.2f dtk, 10 terakhir %.2f dtk, maks %.2f dtk', $first, $last, max($times)));
                $t->true(max($times) < 10, 'tidak ada scan > 10 dtk (maks ' . round(max($times), 2) . ')');
                $db = q26r2_rows_db($t, $id);
                $t->eq(count($db), 100, 'DB: 100 baris scan');
                $t->eq(array_map(function ($r) { return $r[0]; }, $db), $codes, 'DB: urutan scanned_at = urutan scan (100 scan)');
                $strict = true;
                for ($i = 1; $i < count($db); $i++) {
                    if (strcmp($db[$i][1], $db[$i - 1][1]) <= 0) {
                        $strict = false;
                    }
                }
                $t->true($strict, 'DB: scanned_at naik ketat untuk 100 scan');
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
        'title' => 'D-2 (konkurensi): scan kode BERBEDA bersamaan dalam satu sesi: semua 200, scanned_at tidak kembar (monoton di bawah kunci sesi), urutan tabel = urutan scanned_at',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $F = q26_folder($t, 'F');
                $id = q26_session($t, $s, $F, []);
                $base = rtrim($t->conf('API_URL'), '/');
                $token = $s->token;
                $all = [];
                for ($round = 0; $round < 4; $round++) {
                    $codes = [];
                    for ($i = 0; $i < 8; $i++) {
                        $codes[] = q26_n($t, q26_doc($t, "P{$round}x{$i}", ['parent' => $F]));
                    }
                    $mh = curl_multi_init();
                    $handles = [];
                    foreach ($codes as $code) {
                        $ch = curl_init($base . '/' . Q26_BASE . '/opnames/' . $id . '/scan');
                        curl_setopt_array($ch, [
                            CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
                            CURLOPT_POSTFIELDS => json_encode(['code' => $code]),
                            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $token],
                        ]);
                        curl_multi_add_handle($mh, $ch);
                        $handles[] = $ch;
                    }
                    do {
                        $st = curl_multi_exec($mh, $running);
                        if ($running) {
                            curl_multi_select($mh, 0.05);
                        }
                    } while ($running && $st === CURLM_OK);
                    $http = [];
                    foreach ($handles as $ch) {
                        $http[] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_multi_remove_handle($mh, $ch);
                        curl_close($ch);
                    }
                    curl_multi_close($mh);
                    $t->eq($http, array_fill(0, 8, 200), "putaran $round: 8 scan berbeda bersamaan semua 200");
                    $all = array_merge($all, $codes);
                }
                $db = q26r2_rows_db($t, $id);
                $t->eq(count($db), 32, 'DB: 32 baris scan');
                $dupTs = count($db) - count(array_unique(array_map(function ($r) { return $r[1]; }, $db)));
                $t->eq($dupTs, 0, 'DB: scanned_at tidak ada yang kembar (urutan scan pasti)');
                $rows = q26_all_docs($t, $s, $id, 'scanned', 9);
                $dbDesc = array_reverse(array_map(function ($r) { return $r[0]; }, $db));
                $t->eq(q26r2_codes($rows), $dbDesc, 'tabel = urutan scanned_at menurun (bersamaan)');
                $t->eq(array_values(array_unique(array_map('strtolower', q26r2_codes($rows)))), array_map('strtolower', q26r2_codes($rows)), 'tidak ada baris ganda');
                $t->eq(count(array_diff(array_map('strtolower', $all), array_map('strtolower', q26r2_codes($rows)))), 0, 'semua kode tercatat');
                $r = q26_confirm($t, $s, $id);
                $t->status($r, 200, 'confirm sesudah scan bersamaan');
                $t->eq(q26r2_codes(q26_all_docs($t, $s, $id, 'scanned', 9)), $dbDesc, 'terkonfirmasi: urutan sama');
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
