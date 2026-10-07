<?php
/**
 * ED-1029 - konkurensi (D-2, ronde 2): simpan paralel pada baris (folder, user) yang sama lewat DUA penulis archive_permissions:
 * PUT user-permissions/{id} (halaman ini) dan PUT archives/{id} tab Permission (ED-1025, sama-sama lewat saveUserRow/lockFolders).
 *
 *   X-5  penulis 1 saja (PUT user-permissions): nilai berbeda, nilai sama, tanpa perubahan, hapus, hapus vs tulis
 *   X-6  penulis 2 saja (PUT archives/{id}, folder_permissions): idem + ganti-semua dengan user berbeda
 *   X-7  campuran penulis 1 + 2 pada baris yang sama; banyak folder urutan berlawanan (deadlock); dua user di folder yang sama;
 *        id tidak ada (gap lock) dan create-folder bersamaan
 *
 * Lulus = 0 x 5xx dan semua 200, tepat satu baris (UNIQUE), hasil akhir sama dengan SATU kiriman, riwayat `permission` hanya untuk
 * perubahan nyata (delta tepat 1 untuk kiriman sama dari keadaan awal berbeda, tepat 0 untuk kiriman sama dengan yang tersimpan).
 * Catatan lingkungan: php-fpm pm.max_children = 5, jadi paralel efektif 5 request; sisanya antre.
 */
require_once __DIR__ . '/qa_lib.php';

if (!function_exists('q9_par')) {

    /**
     * Kirim semua request bersamaan (curl_multi). $reqs = [[method, uri, body|null], ...]. Hasil searah: [[http, json|null, detik], ...].
     */
    function q9_par($t, $s, array $reqs)
    {
        $base = rtrim($t->conf('API_URL'), '/') . '/';
        $mh = curl_multi_init();
        $handles = [];
        foreach ($reqs as $i => $rq) {
            [$method, $uri, $body] = $rq;
            $ch = curl_init($base . $uri);
            $opts = [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $s->token],
            ];
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            }
            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }
        $t0 = microtime(true);
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.2);
        } while ($running > 0);

        $out = [];
        foreach ($handles as $i => $ch) {
            $raw = curl_multi_getcontent($ch);
            $j = json_decode((string) $raw, true);
            $out[$i] = [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), is_array($j) ? $j : null, (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return $out;
    }

    /** Penulis 1: PUT user-permissions/{id_user}. */
    function q9_preq($idUser, array $rows)
    {
        return ['PUT', 'api/v5/document-archive/user-permissions/' . rawurlencode($idUser), ['permissions' => $rows]];
    }

    /** Penulis 2: PUT archives/{id_archive} dengan folder_permissions (tab Permission, mengganti SEMUA baris folder). */
    function q9_treq($idArchive, $name, array $folderRows)
    {
        return ['PUT', 'api/v5/document-archive/archives/' . $idArchive, ['name' => $name, 'is_all_location' => 1, 'folder_permissions' => $folderRows]];
    }

    function q9_tabrow($idUser, array $v)
    {
        return ['id_user' => $idUser, 'is_view' => $v[0], 'is_update' => $v[1], 'is_delete' => $v[2], 'is_store' => $v[3]];
    }

    function q9_prow($idArchive, array $v)
    {
        return q9_row_body($idArchive, $v[0], $v[1], $v[2], $v[3]);
    }

    /** Hapus semua baris archive_permissions di folder-folder itu (opsional hanya user tertentu). */
    function q9_clear($t, array $ids, array $users = null)
    {
        q9_w($t, function ($c) use ($ids, $users) {
            $q = $c->table('archive_permissions')->whereIn('id_archive', $ids);
            if ($users !== null) {
                $q->whereIn('id_user', $users);
            }
            $q->delete();
        });
    }

    function q9_set_row($t, $idArchive, $idUser, array $v)
    {
        q9_perm_restore_row($t, $idArchive, $idUser, array_sum($v) === 0 ? null : $v);
    }

    /** Semua respons harus 200 (ARCHIVE207); catat 5xx dan penyimpangan ke $bad dan statistik ke $stat. */
    function q9_expect_all_ok(array $res, $label, array &$bad, array &$stat)
    {
        foreach ($res as $i => $r) {
            $stat['n']++;
            $stat['max_s'] = max($stat['max_s'], $r[2]);
            if ($r[0] >= 500) {
                $stat['5xx']++;
            }
            $code = $r[1]['msg_code'] ?? $r[1]['code'] ?? '?';
            if ($r[0] !== 200 || $code !== 'ARCHIVE207') {
                $bad[] = "$label #$i: HTTP {$r[0]} $code " . substr(json_encode($r[1]['message'] ?? ''), 0, 120);
            }
        }
    }

    function q9_stat0()
    {
        return ['n' => 0, '5xx' => 0, 'max_s' => 0.0];
    }

    function q9_vec($v)
    {
        return json_encode(array_values($v));
    }

    /** Delta riwayat `permission` folder sejak $h0. */
    function q9_hdelta($t, $id, $h0)
    {
        return q9_hist_count($t, $id) - $h0;
    }

    function q9_hist_valid($t, $id)
    {
        $raw = $t->db()->table('archives')->where('id_archive', $id)->value('history');

        return $raw === null || is_array(json_decode($raw, true));
    }

    function q9_updated_at($t, $idArchive, $idUser)
    {
        return $t->db()->table('archive_permissions')->where('id_archive', $idArchive)->where('id_user', $idUser)->value('updated_at');
    }

    function q9_combos()
    {
        return [[1, 0, 0, 0], [1, 1, 0, 0], [1, 0, 1, 0], [1, 0, 0, 1], [1, 1, 1, 0], [1, 1, 0, 1], [1, 0, 1, 1], [1, 1, 1, 1]];
    }
}

return [

    [
        'id'    => 'X-5',
        'title' => 'D-2 (penulis 1: PUT user-permissions/{B}) paralel pada baris (P,B) yang sama, 5 jenis putaran, ~140 request: 0 x 5xx, semua 200 ARCHIVE207, tepat satu baris, hasil akhir = satu kiriman, riwayat permission hanya untuk perubahan nyata (kiriman sama dari keadaan awal: delta tepat 1; sama dengan tersimpan: delta 0)',
        'run'   => function ($t) {
            q9_recover($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $meName = q9_uname($t);
            [$b] = q9_others($t, 1);
            $B = $b[0];
            $combos = q9_combos();
            $bad = [];
            $stat = q9_stat0();

            try {
                $P = q9_folder($t, 'P', ['perm' => 1, 'by' => $meName]);

                // (a) nilai berbeda, keadaan awal tanpa baris: 10 request x 6 putaran
                for ($round = 0; $round < 6; $round++) {
                    q9_clear($t, [$P]);
                    $h0 = q9_hist_count($t, $P);
                    $reqs = [];
                    $sent = [];
                    for ($i = 0; $i < 10; $i++) {
                        $v = $combos[($i + $round) % 8];
                        $sent[] = q9_vec($v);
                        $reqs[] = q9_preq($B, [q9_prow($P, $v)]);
                    }
                    q9_expect_all_ok(q9_par($t, $s, $reqs), "a$round", $bad, $stat);
                    $map = q9_user_map($t, $B, [$P]);
                    if (count($map) !== 1) {
                        $bad[] = "a$round: jumlah baris (P,B) = " . count($map) . ' (harus 1)';
                    } elseif (!in_array(q9_vec($map[$P]), $sent, true)) {
                        $bad[] = "a$round: nilai akhir " . q9_vec($map[$P]) . ' bukan salah satu kiriman';
                    }
                    $d = q9_hdelta($t, $P, $h0);
                    if ($d < 1 || $d > 10) {
                        $bad[] = "a$round: delta riwayat permission $d di luar 1..10";
                    }
                }

                // (b) nilai sama, keadaan awal tanpa baris: 12 request x 4 putaran => tepat satu perubahan => tepat 1 riwayat
                for ($round = 0; $round < 4; $round++) {
                    q9_clear($t, [$P]);
                    $h0 = q9_hist_count($t, $P);
                    $v = $combos[($round * 2 + 1) % 8];
                    $reqs = [];
                    for ($i = 0; $i < 12; $i++) {
                        $reqs[] = q9_preq($B, [q9_prow($P, $v)]);
                    }
                    q9_expect_all_ok(q9_par($t, $s, $reqs), "b$round", $bad, $stat);
                    $map = q9_user_map($t, $B, [$P]);
                    if (json_encode($map) !== json_encode([$P => $v])) {
                        $bad[] = "b$round: DB " . json_encode($map) . ' != kiriman ' . q9_vec($v);
                    }
                    if (($d = q9_hdelta($t, $P, $h0)) !== 1) {
                        $bad[] = "b$round: delta riwayat permission $d (harus tepat 1)";
                    }
                }

                // (c) nilai sama dengan yang tersimpan: tanpa perubahan, tanpa riwayat, updated_at utuh
                for ($round = 0; $round < 2; $round++) {
                    $v = $combos[3 + $round];
                    q9_set_row($t, $P, $B, $v);
                    $h0 = q9_hist_count($t, $P);
                    $u0 = q9_updated_at($t, $P, $B);
                    $reqs = [];
                    for ($i = 0; $i < 12; $i++) {
                        $reqs[] = q9_preq($B, [q9_prow($P, $v)]);
                    }
                    q9_expect_all_ok(q9_par($t, $s, $reqs), "c$round", $bad, $stat);
                    if (($d = q9_hdelta($t, $P, $h0)) !== 0) {
                        $bad[] = "c$round: delta riwayat $d (harus 0)";
                    }
                    if (q9_updated_at($t, $P, $B) !== $u0 || json_encode(q9_user_map($t, $B, [$P])) !== json_encode([$P => $v])) {
                        $bad[] = "c$round: baris berubah padahal kiriman sama";
                    }
                }

                // (d) hapus bersamaan (keempat hak 0) dari baris yang ada: baris hilang, tepat 1 riwayat
                for ($round = 0; $round < 3; $round++) {
                    q9_set_row($t, $P, $B, $combos[$round + 1]);
                    $h0 = q9_hist_count($t, $P);
                    $reqs = [];
                    for ($i = 0; $i < 10; $i++) {
                        $reqs[] = q9_preq($B, [q9_prow($P, [0, 0, 0, 0])]);
                    }
                    q9_expect_all_ok(q9_par($t, $s, $reqs), "d$round", $bad, $stat);
                    if (count(q9_user_map($t, $B, [$P])) !== 0) {
                        $bad[] = "d$round: baris masih ada sesudah hapus";
                    }
                    if (($d = q9_hdelta($t, $P, $h0)) !== 1) {
                        $bad[] = "d$round: delta riwayat $d (harus tepat 1)";
                    }
                }

                // (e) hapus lawan tulis: 6 hapus + 6 tulis V2 bersamaan, baris awal V1: akhir = tidak ada atau V2
                for ($round = 0; $round < 4; $round++) {
                    q9_set_row($t, $P, $B, [1, 0, 0, 0]);
                    $v2 = [1, 1, 0, 1];
                    $reqs = [];
                    for ($i = 0; $i < 12; $i++) {
                        $reqs[] = $i % 2 === 0 ? q9_preq($B, [q9_prow($P, [0, 0, 0, 0])]) : q9_preq($B, [q9_prow($P, $v2)]);
                    }
                    q9_expect_all_ok(q9_par($t, $s, $reqs), "e$round", $bad, $stat);
                    $map = q9_user_map($t, $B, [$P]);
                    $ok = $map === [] || json_encode($map) === json_encode([$P => $v2]);
                    if (!$ok) {
                        $bad[] = "e$round: akhir " . json_encode($map) . ' bukan (tidak ada) atau V2';
                    }
                    if (!q9_hist_valid($t, $P)) {
                        $bad[] = "e$round: kolom history bukan JSON valid";
                    }
                }

                $t->note(sprintf('%d request, %d x 5xx, terlama %.2fs', $stat['n'], $stat['5xx'], $stat['max_s']));
                $t->eq($stat['5xx'], 0, 'tidak ada 5xx dari PUT paralel (penulis 1)');
                $t->eq(json_encode($bad), '[]', 'penulis 1 paralel: ' . implode(' | ', array_slice($bad, 0, 6)));
                $t->true($stat['n'] >= 140, 'jumlah request >= 140 (' . $stat['n'] . ')');
                $t->true(q9_hist_valid($t, $P), 'riwayat tetap JSON valid');
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-6',
        'title' => 'D-2 (penulis 2: PUT archives/{P} tab Permission, shared saveUserRow) paralel pada baris (P,B) yang sama dan ganti-semua dengan user berbeda: 0 x 5xx, semua 200 ARCHIVE207, tepat satu baris per user, hasil akhir = satu kiriman utuh, riwayat permission hanya untuk perubahan nyata',
        'run'   => function ($t) {
            q9_recover($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $meName = q9_uname($t);
            [$b1, $b2] = q9_others($t, 2);
            $B = $b1[0];
            $B2 = $b2[0];
            $combos = q9_combos();
            $bad = [];
            $stat = q9_stat0();

            try {
                $P = q9_folder($t, 'P', ['perm' => 1, 'by' => $meName]);
                $name = q9_row($t, $P)['name'];

                // (a) nilai berbeda dari keadaan tanpa baris: 8 request x 5 putaran
                for ($round = 0; $round < 5; $round++) {
                    q9_clear($t, [$P]);
                    $reqs = [];
                    $sent = [];
                    for ($i = 0; $i < 8; $i++) {
                        $v = $combos[($i + $round) % 8];
                        $sent[] = q9_vec($v);
                        $reqs[] = q9_treq($P, $name, [q9_tabrow($B, $v)]);
                    }
                    $h0 = q9_hist_count($t, $P);
                    q9_expect_all_ok(q9_par($t, $s, $reqs), "a$round", $bad, $stat);
                    $map = q9_user_map($t, $B, [$P]);
                    if (count($map) !== 1) {
                        $bad[] = "a$round: jumlah baris (P,B) = " . count($map) . ' (harus 1)';
                    } elseif (!in_array(q9_vec($map[$P]), $sent, true)) {
                        $bad[] = "a$round: nilai akhir " . q9_vec($map[$P]) . ' bukan salah satu kiriman';
                    }
                    $d = q9_hdelta($t, $P, $h0);
                    if ($d < 1 || $d > 8) {
                        $bad[] = "a$round: delta riwayat permission $d di luar 1..8";
                    }
                }

                // (b) nilai sama dari keadaan tanpa baris: 12 request x 4 putaran: tepat 1 riwayat permission
                for ($round = 0; $round < 4; $round++) {
                    q9_clear($t, [$P]);
                    $h0 = q9_hist_count($t, $P);
                    $v = $combos[($round * 2 + 2) % 8];
                    $reqs = [];
                    for ($i = 0; $i < 12; $i++) {
                        $reqs[] = q9_treq($P, $name, [q9_tabrow($B, $v)]);
                    }
                    q9_expect_all_ok(q9_par($t, $s, $reqs), "b$round", $bad, $stat);
                    if (json_encode(q9_user_map($t, $B, [$P])) !== json_encode([$P => $v])) {
                        $bad[] = "b$round: DB " . json_encode(q9_user_map($t, $B, [$P])) . ' != kiriman ' . q9_vec($v);
                    }
                    if (($d = q9_hdelta($t, $P, $h0)) !== 1) {
                        $bad[] = "b$round: delta riwayat permission $d (harus tepat 1)";
                    }
                }

                // (c) sama dengan tersimpan: delta 0, updated_at baris utuh
                q9_clear($t, [$P]);
                $v = $combos[5];
                q9_set_row($t, $P, $B, $v);
                $h0 = q9_hist_count($t, $P);
                $u0 = q9_updated_at($t, $P, $B);
                $reqs = [];
                for ($i = 0; $i < 12; $i++) {
                    $reqs[] = q9_treq($P, $name, [q9_tabrow($B, $v)]);
                }
                q9_expect_all_ok(q9_par($t, $s, $reqs), 'c', $bad, $stat);
                if (($d = q9_hdelta($t, $P, $h0)) !== 0) {
                    $bad[] = "c: delta riwayat permission $d (harus 0)";
                }
                if (q9_updated_at($t, $P, $B) !== $u0) {
                    $bad[] = 'c: updated_at baris berubah padahal kiriman sama';
                }

                // (d) dua user sekaligus, kiriman sama dari keadaan kosong: tepat dua baris, tepat 1 riwayat
                for ($round = 0; $round < 3; $round++) {
                    q9_clear($t, [$P]);
                    $h0 = q9_hist_count($t, $P);
                    $v1 = $combos[$round + 1];
                    $v2 = $combos[$round + 4];
                    $reqs = [];
                    for ($i = 0; $i < 8; $i++) {
                        $reqs[] = q9_treq($P, $name, [q9_tabrow($B, $v1), q9_tabrow($B2, $v2)]);
                    }
                    q9_expect_all_ok(q9_par($t, $s, $reqs), "d$round", $bad, $stat);
                    $dump = q9_perm_dump($t, [$P]);
                    if (count($dump) !== 2) {
                        $bad[] = "d$round: jumlah baris folder " . count($dump) . ' (harus 2)';
                    }
                    if (json_encode(q9_user_map($t, $B, [$P])) !== json_encode([$P => $v1]) || json_encode(q9_user_map($t, $B2, [$P])) !== json_encode([$P => $v2])) {
                        $bad[] = "d$round: nilai dua user tidak sama dengan kiriman";
                    }
                    if (($d = q9_hdelta($t, $P, $h0)) !== 1) {
                        $bad[] = "d$round: delta riwayat permission $d (harus tepat 1)";
                    }
                }

                // (e) ganti-semua dengan himpunan user berbeda: 6 request [B] dan 6 request [B2]: akhir tepat salah satu himpunan
                for ($round = 0; $round < 4; $round++) {
                    q9_clear($t, [$P]);
                    if ($round % 2 === 1) {
                        q9_set_row($t, $P, $B, [1, 0, 0, 0]);
                        q9_set_row($t, $P, $B2, [1, 1, 0, 0]);
                    }
                    $reqs = [];
                    for ($i = 0; $i < 12; $i++) {
                        $reqs[] = $i % 2 === 0 ? q9_treq($P, $name, [q9_tabrow($B, [1, 1, 1, 1])]) : q9_treq($P, $name, [q9_tabrow($B2, [1, 0, 1, 0])]);
                    }
                    q9_expect_all_ok(q9_par($t, $s, $reqs), "e$round", $bad, $stat);
                    $dump = q9_perm_dump($t, [$P]);
                    $a = json_encode([[$P, $B, 1, 1, 1, 1]]);
                    $c = json_encode([[$P, $B2, 1, 0, 1, 0]]);
                    if (!in_array(json_encode($dump), [$a, $c], true)) {
                        $bad[] = "e$round: akhir " . json_encode($dump) . ' bukan himpunan {B} atau {B2} utuh';
                    }
                    if (!q9_hist_valid($t, $P)) {
                        $bad[] = "e$round: kolom history bukan JSON valid";
                    }
                }

                $t->note(sprintf('%d request, %d x 5xx, terlama %.2fs', $stat['n'], $stat['5xx'], $stat['max_s']));
                $t->eq($stat['5xx'], 0, 'tidak ada 5xx dari PUT archives/{id} paralel (penulis 2)');
                $t->eq(json_encode($bad), '[]', 'penulis 2 paralel: ' . implode(' | ', array_slice($bad, 0, 6)));
                $t->true($stat['n'] >= 140, 'jumlah request >= 140 (' . $stat['n'] . ')');
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-7',
        'title' => 'D-2 (campuran): penulis 1 + penulis 2 bersamaan pada baris (P,B) yang sama; banyak folder dengan urutan berlawanan (deadlock); dua user di satu folder; id tidak ada (gap lock) dan create-folder bersamaan; flag is_folder_permission dibalik saat PUT: 0 x 5xx, semua status sesuai, hasil akhir = satu kiriman utuh, riwayat hanya perubahan nyata',
        'run'   => function ($t) {
            q9_recover($t);
            $s = $t->session();
            q9_purge($t);
            $base = q9_perm_total($t);
            $meName = q9_uname($t);
            [$b1, $b2] = q9_others($t, 2);
            $B = $b1[0];
            $B2 = $b2[0];
            $combos = q9_combos();
            $bad = [];
            $stat = q9_stat0();

            try {
                $P = q9_folder($t, 'P', ['perm' => 1, 'by' => $meName]);
                $name = q9_row($t, $P)['name'];

                // (a) campuran, nilai berbeda, tanpa baris awal: 5 penulis 1 + 5 penulis 2, 6 putaran
                for ($round = 0; $round < 6; $round++) {
                    q9_clear($t, [$P]);
                    $h0 = q9_hist_count($t, $P);
                    $reqs = [];
                    $sent = [];
                    for ($i = 0; $i < 10; $i++) {
                        $v = $combos[($i + $round) % 8];
                        $sent[] = q9_vec($v);
                        $reqs[] = $i % 2 === 0 ? q9_preq($B, [q9_prow($P, $v)]) : q9_treq($P, $name, [q9_tabrow($B, $v)]);
                    }
                    q9_expect_all_ok(q9_par($t, $s, $reqs), "a$round", $bad, $stat);
                    $map = q9_user_map($t, $B, [$P]);
                    if (count($map) !== 1) {
                        $bad[] = "a$round: jumlah baris (P,B) = " . count($map) . ' (harus 1)';
                    } elseif (!in_array(q9_vec($map[$P]), $sent, true)) {
                        $bad[] = "a$round: nilai akhir " . q9_vec($map[$P]) . ' bukan salah satu kiriman';
                    }
                    $d = q9_hdelta($t, $P, $h0);
                    if ($d < 1 || $d > 10) {
                        $bad[] = "a$round: delta riwayat permission $d di luar 1..10";
                    }
                    if (!q9_hist_valid($t, $P)) {
                        $bad[] = "a$round: kolom history bukan JSON valid";
                    }
                }

                // (b) campuran, nilai sama: tepat 1 riwayat permission
                for ($round = 0; $round < 4; $round++) {
                    q9_clear($t, [$P]);
                    $h0 = q9_hist_count($t, $P);
                    $v = $combos[($round * 2 + 3) % 8];
                    $reqs = [];
                    for ($i = 0; $i < 12; $i++) {
                        $reqs[] = $i % 2 === 0 ? q9_preq($B, [q9_prow($P, $v)]) : q9_treq($P, $name, [q9_tabrow($B, $v)]);
                    }
                    q9_expect_all_ok(q9_par($t, $s, $reqs), "b$round", $bad, $stat);
                    if (json_encode(q9_user_map($t, $B, [$P])) !== json_encode([$P => $v])) {
                        $bad[] = "b$round: DB " . json_encode(q9_user_map($t, $B, [$P])) . ' != kiriman ' . q9_vec($v);
                    }
                    if (($d = q9_hdelta($t, $P, $h0)) !== 1) {
                        $bad[] = "b$round: delta riwayat permission $d (harus tepat 1)";
                    }
                }

                // (c) banyak folder, urutan berlawanan (potensi deadlock): F1..F3, 6 request [F1,F2,F3] dan 6 request [F3,F2,F1];
                //     tiap request menulis vektor yang sama ke ketiga folder => akhir: ketiga baris sama = satu kiriman utuh
                $F = [q9_folder($t, 'F1', ['perm' => 1, 'by' => $meName]), q9_folder($t, 'F2', ['perm' => 1, 'by' => $meName]), q9_folder($t, 'F3', ['perm' => 1, 'by' => $meName])];
                for ($round = 0; $round < 4; $round++) {
                    q9_clear($t, $F);
                    $reqs = [];
                    $sent = [];
                    for ($i = 0; $i < 12; $i++) {
                        $v = $combos[($i + $round) % 8];
                        $sent[] = q9_vec($v);
                        $order = $i % 2 === 0 ? [$F[0], $F[1], $F[2]] : [$F[2], $F[1], $F[0]];
                        $rows = [];
                        foreach ($order as $id) {
                            $rows[] = q9_prow($id, $v);
                        }
                        $reqs[] = q9_preq($B, $rows);
                    }
                    q9_expect_all_ok(q9_par($t, $s, $reqs), "c$round", $bad, $stat);
                    $map = q9_user_map($t, $B, $F);
                    $vecs = [];
                    foreach ($F as $id) {
                        $vecs[] = isset($map[$id]) ? q9_vec($map[$id]) : 'tidak ada';
                    }
                    if (count(array_unique($vecs)) !== 1 || !in_array($vecs[0], $sent, true)) {
                        $bad[] = "c$round: ketiga folder tidak sama = satu kiriman utuh (" . implode(' / ', $vecs) . ')';
                    }
                }
                // (c2) sama, ditambah penulis 2 pada F2 (replace-all) dan penulis 1 pada folder tunggal: hanya 0 x 5xx dan semua 200
                $n2 = q9_row($t, $F[1])['name'];
                for ($round = 0; $round < 3; $round++) {
                    $reqs = [];
                    for ($i = 0; $i < 12; $i++) {
                        $v = $combos[($i + $round) % 8];
                        if ($i % 3 === 0) {
                            $reqs[] = q9_preq($B, [q9_prow($F[0], $v), q9_prow($F[1], $v), q9_prow($F[2], $v)]);
                        } elseif ($i % 3 === 1) {
                            $reqs[] = q9_preq($B, [q9_prow($F[2], $v), q9_prow($F[1], $v)]);
                        } else {
                            $reqs[] = q9_treq($F[1], $n2, [q9_tabrow($B, $v), q9_tabrow($B2, $v)]);
                        }
                    }
                    q9_expect_all_ok(q9_par($t, $s, $reqs), "c2$round", $bad, $stat);
                    if (!q9_hist_valid($t, $F[1])) {
                        $bad[] = "c2$round: kolom history F2 bukan JSON valid";
                    }
                }

                // (d) dua user di satu folder: 6 request B (v1) + 6 request B2 (v2): akhir tepat dua baris sesuai, riwayat tepat 2
                for ($round = 0; $round < 3; $round++) {
                    q9_clear($t, [$P]);
                    $h0 = q9_hist_count($t, $P);
                    $v1 = $combos[$round + 1];
                    $v2 = $combos[$round + 5];
                    $reqs = [];
                    for ($i = 0; $i < 12; $i++) {
                        $reqs[] = $i % 2 === 0 ? q9_preq($B, [q9_prow($P, $v1)]) : q9_preq($B2, [q9_prow($P, $v2)]);
                    }
                    q9_expect_all_ok(q9_par($t, $s, $reqs), "d$round", $bad, $stat);
                    if (json_encode(q9_user_map($t, $B, [$P])) !== json_encode([$P => $v1]) || json_encode(q9_user_map($t, $B2, [$P])) !== json_encode([$P => $v2])) {
                        $bad[] = "d$round: baris dua user tidak sama dengan kiriman masing-masing";
                    }
                    if (count(q9_perm_dump($t, [$P])) !== 2) {
                        $bad[] = "d$round: jumlah baris folder " . count(q9_perm_dump($t, [$P])) . ' (harus 2)';
                    }
                    if (($d = q9_hdelta($t, $P, $h0)) !== 2) {
                        $bad[] = "d$round: delta riwayat permission $d (harus tepat 2: satu per user)";
                    }
                }

                // (e) id_archive tidak ada (SELECT .. FOR UPDATE pada kunci yang tidak ada = gap lock), create-folder dan PUT sah bersamaan
                for ($round = 0; $round < 3; $round++) {
                    q9_clear($t, [$P]);
                    $reqs = [];
                    $kind = [];
                    for ($i = 0; $i < 18; $i++) {
                        if ($i % 3 === 0) {
                            $reqs[] = q9_preq($B, [q9_prow($P, $combos[$i % 8]), q9_prow(substr(Q9_RANDOM_ID, 0, 20) . sprintf('%010d', $i + $round * 100), [1, 0, 0, 0])]);
                            $kind[] = 404;
                        } elseif ($i % 3 === 1) {
                            $reqs[] = ['POST', 'api/v5/document-archive/archives/create-folder', ['name' => q9_name('NEW'), 'is_all_location' => 1]];
                            $kind[] = 200;
                        } else {
                            $reqs[] = q9_preq($B, [q9_prow($P, $combos[($i + 1) % 8])]);
                            $kind[] = 200;
                        }
                    }
                    foreach (q9_par($t, $s, $reqs) as $i => $r) {
                        $stat['n']++;
                        $stat['max_s'] = max($stat['max_s'], $r[2]);
                        if ($r[0] >= 500) {
                            $stat['5xx']++;
                        }
                        $code = $r[1]['msg_code'] ?? $r[1]['code'] ?? '?';
                        if ($r[0] !== $kind[$i] || ($kind[$i] === 404 && $code !== 'ARCHIVE400')) {
                            $bad[] = "e$round #$i: HTTP {$r[0]} $code (harus {$kind[$i]})";
                        }
                    }
                    // PUT [P sah, id tidak ada] harus atomik: baris (P,B) hanya dari PUT 200
                    if (count(q9_user_map($t, $B, [$P])) > 1) {
                        $bad[] = "e$round: lebih dari satu baris (P,B)";
                    }
                }

                // (f) flag is_folder_permission dibalik (PUT archives/{P}) bersamaan dengan PUT user-permissions: penolakan sah 400 ARCHIVE441
                //     (folder Off) atau 200; tidak ada 5xx; paling banyak satu baris (P,B); riwayat JSON valid; flag akhir 0/1
                for ($round = 0; $round < 4; $round++) {
                    q9_clear($t, [$P]);
                    q9_set_archive($t, $P, ['is_folder_permission' => 1]);
                    $reqs = [];
                    for ($i = 0; $i < 12; $i++) {
                        if ($i % 3 === 0) {
                            $reqs[] = ['PUT', 'api/v5/document-archive/archives/' . $P, ['name' => $name, 'is_all_location' => 1, 'is_folder_permission' => $i % 2]];
                        } else {
                            $reqs[] = q9_preq($B, [q9_prow($P, $combos[($i + $round) % 8])]);
                        }
                    }
                    foreach (q9_par($t, $s, $reqs) as $i => $r) {
                        $stat['n']++;
                        $stat['max_s'] = max($stat['max_s'], $r[2]);
                        if ($r[0] >= 500) {
                            $stat['5xx']++;
                        }
                        $code = $r[1]['msg_code'] ?? $r[1]['code'] ?? '?';
                        $isFlagWriter = $i % 3 === 0;
                        $ok = $r[0] === 200 || (!$isFlagWriter && $r[0] === 400 && $code === 'ARCHIVE441');
                        if (!$ok) {
                            $bad[] = "f$round #$i: HTTP {$r[0]} $code";
                        }
                    }
                    if (count(q9_user_map($t, $B, [$P])) > 1 || !in_array((int) q9_row($t, $P)['is_folder_permission'], [0, 1], true)) {
                        $bad[] = "f$round: keadaan akhir tidak konsisten";
                    }
                    if (!q9_hist_valid($t, $P)) {
                        $bad[] = "f$round: kolom history bukan JSON valid";
                    }
                }

                $t->note(sprintf('%d request, %d x 5xx, terlama %.2fs', $stat['n'], $stat['5xx'], $stat['max_s']));
                $t->eq($stat['5xx'], 0, 'tidak ada 5xx dari skenario konkurensi campuran');
                $t->eq(json_encode($bad), '[]', 'campuran paralel: ' . implode(' | ', array_slice($bad, 0, 6)));
                $t->true($stat['max_s'] < 60, 'tidak ada request menggantung (terlama ' . round($stat['max_s'], 2) . 's)');
            } finally {
                q9_purge($t);
            }
            q9_assert_clean($t, $base);
        },
    ],

];
