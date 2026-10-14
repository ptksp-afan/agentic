<?php
/**
 * ED-1025 (revisi review 2026-10-08) - hak folder efektif: AC-26, AC-27, AC-28, AC-37 (data FB-9: J, JA, JB, JC, X, K) dan
 * X-15 (matriks hak efektif BR-4: model referensi independen di sini vs `access`/403/denied_by/ARCHIVE408-410-409-418 dari HTTP).
 *
 * Aturan yang diuji (spec BR-1/4/6/9, K-12 a, K-13 a): hanya View yang mengikuti induk On; Update/Delete/Store dinilai di folder itu
 * sendiri; folder Izin Off tidak membatasi U/D/S (cukup View efektif); pindah folder = Update di folder + Store di tujuan, induk
 * asal tidak dicek.
 *
 * "A" = QA_USER (role 3: bukan bypass, ber-Folder Permissions). Data berawalan QA02-, dibuang di finally. Lihat qa_lib.php.
 * Skenario tidak bergantung pada folder milik pengembang: semua diasersi pada fixture sendiri.
 */
require_once __DIR__ . '/qa_lib.php';

if (!function_exists('q2_fb9')) {

    /**
     * Data FB-9: J (On, A View saja) dengan anak JA (On, A V/U/D/S), JB (On, tanpa baris A), JC (Off); X (On, A View+Store).
     * @return array id folder bernama
     */
    function q2_fb9($t, $b)
    {
        $f = [];
        $f['J'] = q2_folder($t, 'J', ['perm' => 1]);
        $f['JA'] = q2_folder($t, 'JA', ['perm' => 1, 'parent' => $f['J']]);
        $f['JB'] = q2_folder($t, 'JB', ['perm' => 1, 'parent' => $f['J']]);
        $f['JC'] = q2_folder($t, 'JC', ['perm' => 0, 'parent' => $f['J']]);
        $f['X'] = q2_folder($t, 'X', ['perm' => 1]);
        q2_perm($t, $f['J'], $b, 1);
        q2_perm($t, $f['JA'], $b, 1, 1, 1, 1);
        q2_perm($t, $f['X'], $b, 1, 0, 0, 1);

        return $f;
    }

    // ---- model referensi BR-4 (ditulis ulang di sini dari spec, bukan memanggil kode BE) ----

    /** Apakah node memberi hak itu kepada $me. $n: perm, by, row [v,u,d,s] atau null. */
    function q2_model_grants(array $n, $right, $me)
    {
        if (!$n['perm']) {
            return true;                                              // K-12 a: Off tidak membatasi
        }
        if (strcasecmp((string) $n['by'], (string) $me) === 0) {
            return true;                                              // BR-6 pembuat
        }
        if (!$n['row'] || !$n['row'][0]) {
            return false;                                             // tanpa baris / tanpa View
        }
        $idx = ['view' => 0, 'update' => 1, 'delete' => 2, 'store' => 3][$right];

        return (bool) $n['row'][$idx];
    }

    /** Id folder penolak (atau null) untuk $right pada $id menurut BR-4. $nodes: id => node (parent, perm, by, row). */
    function q2_model_denied(array $nodes, $id, $right, $me)
    {
        $path = [];
        for ($x = $id; $x !== null; $x = $nodes[$x]['parent']) {
            array_unshift($path, $x);
        }
        foreach ($path as $p) {
            if (!q2_model_grants($nodes[$p], 'view', $me)) {
                return $p;                                            // View: folder On teratas di jalur yang tidak memberi View
            }
        }
        if ($right !== 'view' && !q2_model_grants($nodes[$id], $right, $me)) {
            return $id;                                               // U/D/S: folder itu sendiri
        }

        return null;
    }

    /** Aturan LAMA (K-1 a): semua hak dominan dari induk. Hanya untuk mengukur cakupan: berapa kasus yang membedakan aturan baru. */
    function q2_model_denied_old(array $nodes, $id, $right, $me)
    {
        $path = [];
        for ($x = $id; $x !== null; $x = $nodes[$x]['parent']) {
            array_unshift($path, $x);
        }
        foreach ($path as $p) {
            if (!q2_model_grants($nodes[$p], $right, $me)) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Bangun pohon acak (seed tetap) di bawah pembungkus W (Off, dibuat orang lain: tidak membatasi). Mengembalikan
     * [W, nodes] dengan nodes: id => ['parent' => id|null (W = null), 'perm', 'by', 'row', 'kids' => [...]].
     */
    function q2_build_random_tree($t, $seed, $b, $me)
    {
        // fase 1: seluruh keputusan acak diambil lebih dulu (tanpa memanggil helper lain yang ikut memakai mt_rand,
        // mis. q2_name/generateId) -> pohon identik di setiap run untuk seed yang sama
        mt_srand($seed);
        $spec = [];
        $n = 0;
        $make = function ($parent, $depth) use (&$make, &$spec, &$n) {
            $node = [
                'parent' => $parent,
                'depth'  => $depth,
                'perm'   => mt_rand(0, 9) < 6 ? 1 : 0,
                'by'     => mt_rand(0, 9) < 1 ? 'ME' : 'QA02',
                'row'    => null,
                'kids'   => [],
            ];
            if (mt_rand(0, 9) < 7) {
                $view = mt_rand(0, 9) < 8 ? 1 : 0;
                $node['row'] = [$view, mt_rand(0, 1), mt_rand(0, 1), mt_rand(0, 1)];
                if (!$view && mt_rand(0, 9) < 7) {
                    $node['row'] = [0, 0, 0, 0];                       // baris tanpa hak tidak ada di DB nyata
                }
            }
            $key = 'n' . (++$n);
            $spec[$key] = $node;
            if ($depth < 3) {
                $count = $depth === 1 ? 3 : mt_rand(1, 3);
                for ($i = 0; $i < $count; $i++) {
                    $spec[$key]['kids'][] = $make($key, $depth + 1);
                }
            }

            return $key;
        };
        for ($i = 0; $i < 3; $i++) {
            $make(null, 1);
        }

        // fase 2: tulis ke DB (induk lebih dulu: urutan pembuatan = pra-urutan)
        $W = q2_folder($t, 'W', ['perm' => 0]);
        $real = [];
        $nodes = [];
        foreach ($spec as $key => $node) {
            $by = $node['by'] === 'ME' ? $me : 'QA02';
            $id = q2_folder($t, 'M' . $node['depth'], ['perm' => $node['perm'], 'by' => $by, 'parent' => $node['parent'] === null ? $W : $real[$node['parent']]]);
            $real[$key] = $id;
            if ($node['row'] && array_sum($node['row']) > 0) {
                q2_perm($t, $id, $b, $node['row'][0], $node['row'][1], $node['row'][2], $node['row'][3]);   // langsung ke DB: boleh U/D/S tanpa View
            }
        }
        foreach ($spec as $key => $node) {
            $nodes[$real[$key]] = [
                'parent' => $node['parent'] === null ? null : $real[$node['parent']],
                'perm'   => $node['perm'],
                'by'     => $node['by'] === 'ME' ? $me : 'QA02',
                'row'    => $node['row'],
                'kids'   => array_map(function ($k) use ($real) {
                    return $real[$k];
                }, $node['kids']),
            ];
        }

        return [$W, $nodes];
    }
}

return [

    [
        'id'    => 'AC-26',
        'title' => '(rev FB-9) A View saja di J, V/U/D/S di JA: PUT, rename, create-folder di, put-in dokumen ke, DELETE (kosong) JA = 200; baris JA di J access semua true (JB tertutup, JC semua true); A rename/DELETE J = 403 ARCHIVE408/409 (parameter J), create-folder/put-in di J = 403 ARCHIVE410',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);

            try {
                $f = q2_fb9($t, $b);
                [$J, $JA, $JB, $JC, $X] = [$f['J'], $f['JA'], $f['JB'], $f['JC'], $f['X']];
                $jName = q2_name_of($t, $J);
                $jaName = q2_name_of($t, $JA);

                // daftar isi J: access per baris menurut BR-4 (View induk + hak di folder itu)
                $r = q2_open($t, $s, $J);
                $t->status($r, 200, 'buka J');
                q2_access_is($t, $r[1]['result']['access'], [1, 0, 0, 0], 'result.access J (View saja)');
                foreach (['JA' => $JA, 'JB' => $JB, 'JC' => $JC] as $label => $id) {
                    $t->true(q2_find($r, $id) !== null, "$label tampil di daftar J");
                }
                q2_access_is($t, q2_find($r, $JA)['access'], q2_all_true(), 'baris JA: semua true');
                q2_access_is($t, q2_find($r, $JB)['access'], [0, 0, 0, 0], 'baris JB (On, tanpa baris A): semua false');
                q2_access_is($t, q2_find($r, $JC)['access'], q2_all_true(), 'baris JC (Off): semua true (K-12 a)');
                $t->eq(q2_find($r, $JA)['is_folder_permission'], 1, 'JA is_folder_permission 1');
                $t->eq(q2_find($r, $JC)['is_folder_permission'], 0, 'JC is_folder_permission 0');

                // open JA / show JA: hak efektif
                $r = q2_open($t, $s, $JA);
                $t->status($r, 200, 'buka JA');
                q2_access_is($t, $r[1]['result']['access'], q2_all_true(), 'result.access JA');

                // --- A di JA: Update, rename, create-folder, put-in, DELETE = 200 (View saja di J tidak menolak)
                $r = q2_put($t, $s, $JA, q2_body($jaName . 'U'));
                $t->status($r, 200, 'PUT detail JA');
                $t->code($r, 'ARCHIVE207', 'PUT JA');
                $t->eq(q2_name_of($t, $JA), $jaName . 'U', 'DB: nama JA (PUT)');
                $r = q2_rename($t, $s, $JA, $jaName . 'R');
                $t->status($r, 200, 'rename JA');
                $t->eq(q2_name_of($t, $JA), $jaName . 'R', 'DB: nama JA (rename)');
                $sub = q2_name('SUB');
                $r = q2_create($t, $s, ['name' => $sub, 'id_archive_parent' => $JA, 'is_all_location' => 1]);
                $t->status($r, 200, 'create-folder di JA');
                $subId = $t->db()->table('archives')->where('name', $sub)->value('id_archive');
                $t->eq(q2_row($t, $subId)['id_archive_parent'], $JA, 'DB: subfolder di JA');
                $Dr = q2_doc($t, 'DR');
                $r = q2_putin($t, $s, $JA, $Dr);
                $t->status($r, 200, 'put-in dokumen ke JA');
                $t->eq(q2_row($t, $Dr)['id_archive_parent'], $JA, 'DB: dokumen di JA');
                $JA2 = q2_folder($t, 'JA2', ['perm' => 1, 'parent' => $J]);
                q2_perm($t, $JA2, $b, 1, 1, 1, 1);
                $r = q2_delete($t, $s, $JA2);
                $t->status($r, 200, 'DELETE JA2 (kosong)');
                $t->code($r, 'ARCHIVE203', 'DELETE JA2');
                $t->eq((int) q2_row($t, $JA2)['is_active'], 0, 'DB: JA2 nonaktif');

                // --- A di J (View saja): ditolak, parameter = J, data tetap
                $Dr2 = q2_doc($t, 'DR2');
                $before = q2_snap($t, [$J, $Dr2]);
                q2_deny($t, q2_rename($t, $s, $J, $jName . 'X'), 403, 'ARCHIVE408', 'rename J', $jName);
                q2_deny($t, q2_delete($t, $s, $J), 403, 'ARCHIVE409', 'DELETE J', $jName);
                q2_deny($t, q2_create($t, $s, ['name' => q2_name('SUB'), 'id_archive_parent' => $J, 'is_all_location' => 1]), 403, 'ARCHIVE410', 'create-folder di J', $jName);
                q2_deny($t, q2_putin($t, $s, $J, $Dr2), 403, 'ARCHIVE410', 'put-in dokumen ke J', $jName);
                q2_deny($t, q2_put($t, $s, $J, q2_body($jName . 'X')), 403, 'ARCHIVE408', 'PUT detail J', $jName);
                $t->true(q2_same($before, q2_snap($t, [$J, $Dr2])), 'penolakan tidak mengubah data');
                $t->eq((int) q2_row($t, $J)['is_active'], 1, 'J tetap aktif');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-27',
        'title' => '(rev FB-9) JB (On, tanpa baris A) menolak walau A View di J: denied_by = JB; baris A di J dihapus (JA tetap V/U/D/S): buka JA 403 ARCHIVE407 denied_by = J, PUT/rename JA 403 ARCHIVE408 parameter J, search JA access semua false',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);

            try {
                $f = q2_fb9($t, $b);
                [$J, $JA, $JB, $JC] = [$f['J'], $f['JA'], $f['JB'], $f['JC']];
                $jName = q2_name_of($t, $J);
                $jaName = q2_name_of($t, $JA);
                $jbName = q2_name_of($t, $JB);

                // JB: A View di J tetapi tanpa baris di JB -> JB menolak (denied_by = JB)
                $r = q2_open($t, $s, $JB);
                $t->status($r, 403, 'A buka JB');
                $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE407', 'JB: msg_code');
                $t->eq(json_encode($r[1]['result']['denied_by']), json_encode(['id_archive' => $JB, 'name' => $jbName]), 'JB: denied_by = JB');
                q2_deny($t, q2_show($t, $s, $JB), 403, 'ARCHIVE407', 'show JB', $jbName);
                q2_deny($t, q2_hist($t, $s, $JB), 403, 'ARCHIVE407', 'history JB', $jbName);
                q2_deny($t, q2_rename($t, $s, $JB, q2_name('X')), 403, 'ARCHIVE408', 'rename JB (View ditolak oleh JB)', $jbName);

                // JA ok selagi A View di J
                $t->status(q2_open($t, $s, $JA), 200, 'JA terbuka selagi A View di J');

                // baris A di J dihapus; JA tetap V/U/D/S
                q2_unperm($t, $J, $b);
                $t->eq(q2_perm_map($t, $JA), [$b => [1, 1, 1, 1]], 'JA tetap V/U/D/S');
                $r = q2_open($t, $s, $JA);
                $t->status($r, 403, 'buka JA tanpa View di J');
                $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE407', 'JA: msg_code');
                $t->eq(json_encode($r[1]['result']['denied_by']), json_encode(['id_archive' => $J, 'name' => $jName]), 'JA: denied_by = J');
                $t->eq($r[1]['result']['id_archive'], $JA, 'JA: result.id_archive = JA');
                $t->true(!array_key_exists('data', $r[1]['result']), 'JA: tanpa isi');
                q2_deny($t, q2_put($t, $s, $JA, q2_body($jaName . 'X')), 403, 'ARCHIVE408', 'PUT JA', $jName);
                q2_deny($t, q2_rename($t, $s, $JA, $jaName . 'X'), 403, 'ARCHIVE408', 'rename JA', $jName);
                q2_deny($t, q2_show($t, $s, $JA), 403, 'ARCHIVE407', 'show JA', $jName);
                q2_deny($t, q2_delete($t, $s, $JA), 403, 'ARCHIVE409', 'DELETE JA', $jName);
                q2_deny($t, q2_putin($t, $s, $JA, q2_doc($t, 'DR')), 403, 'ARCHIVE410', 'put-in ke JA', $jName);
                $t->eq(q2_name_of($t, $JA), $jaName, 'DB: nama JA tetap');

                // search JA: baris membawa access semua false (View ditolak J), tetap tampil
                $r = q2_list($t, $s, ['search' => json_encode(['query' => $jaName]), 'pagination' => 20]);
                $t->status($r, 200, 'search JA');
                $row = q2_find($r, $JA);
                $t->true($row !== null, 'JA muncul di pencarian');
                q2_access_is($t, $row['access'], [0, 0, 0, 0], 'search JA: semua false');
                $t->eq($row['is_folder_permission'], 1, 'search JA: is_folder_permission 1');

                // JB sesudah baris J hilang: penolak teratas = J
                $r = q2_open($t, $s, $JB);
                $t->eq($r[1]['result']['denied_by']['id_archive'] ?? null, $J, 'JB tanpa View di J: denied_by = J (teratas)');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-28',
        'title' => '(rev K-12 a) JC (Off) di bawah J (A View saja): PUT detail, rename, create-folder & put-in dokumen ke, DELETE (kosong) JC = 200; JC berisi dokumen -> DELETE 400 ARCHIVE401; baris A di J dihapus -> JC 403 ARCHIVE407 denied_by J, rename 403 ARCHIVE408 parameter J; K (On, B View+Delete) dengan satu anak Off kosong -> DELETE K 200',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);

            try {
                $f = q2_fb9($t, $b);
                [$J, $JA, $JB, $JC] = [$f['J'], $f['JA'], $f['JB'], $f['JC']];
                $jName = q2_name_of($t, $J);
                $jcName = q2_name_of($t, $JC);

                // JC tampil di J dengan access semua true, bisa dibuka
                $r = q2_open($t, $s, $J);
                q2_access_is($t, q2_find($r, $JC)['access'], q2_all_true(), 'baris JC di J: semua true');
                $r = q2_open($t, $s, $JC);
                $t->status($r, 200, 'buka JC');
                q2_access_is($t, $r[1]['result']['access'], q2_all_true(), 'result.access JC');
                $r = q2_show($t, $s, $JC);
                $t->status($r, 200, 'show JC');
                $t->eq($r[1]['result']['is_folder_permission'], 0, 'show JC: is_folder_permission 0');
                q2_access_is($t, $r[1]['result']['access'], q2_all_true(), 'show JC: access');

                // PUT detail, rename, create-folder di, put-in ke
                $r = q2_put($t, $s, $JC, q2_body($jcName . 'U'));
                $t->status($r, 200, 'PUT detail JC');
                $t->eq(q2_name_of($t, $JC), $jcName . 'U', 'DB: nama JC (PUT)');
                $r = q2_rename($t, $s, $JC, $jcName . 'R');
                $t->status($r, 200, 'rename JC');
                $sub = q2_name('SUB');
                $r = q2_create($t, $s, ['name' => $sub, 'id_archive_parent' => $JC, 'is_all_location' => 1]);
                $t->status($r, 200, 'create-folder di JC');
                $subId = $t->db()->table('archives')->where('name', $sub)->value('id_archive');
                $t->eq(q2_row($t, $subId)['id_archive_parent'], $JC, 'DB: subfolder di JC');
                $Dr = q2_doc($t, 'DR');
                $r = q2_putin($t, $s, $JC, $Dr);
                $t->status($r, 200, 'put-in dokumen ke JC');
                $t->eq(q2_row($t, $Dr)['id_archive_parent'], $JC, 'DB: dokumen di JC');

                // DELETE: harus kosong. JC berisi dokumen aktif (+ subfolder) -> 400 ARCHIVE401, data tetap
                $beforeJc = q2_snap($t, [$JC, $subId, $Dr]);
                q2_deny($t, q2_delete($t, $s, $JC), 400, 'ARCHIVE401', 'DELETE JC berisi dokumen');
                $t->true(q2_same($beforeJc, q2_snap($t, [$JC, $subId, $Dr])), 'ARCHIVE401: data tidak berubah');
                // kosong -> 200
                $JC3 = q2_folder($t, 'JC3', ['perm' => 0, 'parent' => $J]);
                $r = q2_delete($t, $s, $JC3);
                $t->status($r, 200, 'DELETE JC3 (Off, kosong, A View saja di J)');
                $t->code($r, 'ARCHIVE203', 'DELETE JC3');
                $t->eq((int) q2_row($t, $JC3)['is_active'], 0, 'DB: JC3 nonaktif');
                $t->eq((int) q2_row($t, $J)['is_active'], 1, 'J tetap aktif');
                // JC dikosongkan (dokumen ke root, subfolder dihapus) -> DELETE 200 dan subfolder ikut nonaktif
                $t->status(q2_putin($t, $s, null, $Dr), 200, 'dokumen keluar ke root');
                $r = q2_delete($t, $s, $JC);
                $t->status($r, 200, 'DELETE JC (kosong dari dokumen; subfolder Off ikut)');
                $t->eq((int) q2_row($t, $JC)['is_active'], 0, 'DB: JC nonaktif');
                $t->eq((int) q2_row($t, $subId)['is_active'], 0, 'DB: subfolder JC nonaktif');

                // baris A di J dihapus: JC kehilangan View lewat induk On (hanya View yang diwarisi)
                $JC2 = q2_folder($t, 'JC2', ['perm' => 0, 'parent' => $J]);
                $jc2Name = q2_name_of($t, $JC2);
                q2_unperm($t, $J, $b);
                $r = q2_open($t, $s, $JC2);
                $t->status($r, 403, 'JC2 tanpa View di J');
                $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE407', 'JC2: msg_code');
                $t->eq(json_encode($r[1]['result']['denied_by']), json_encode(['id_archive' => $J, 'name' => $jName]), 'JC2: denied_by = J');
                q2_deny($t, q2_rename($t, $s, $JC2, $jc2Name . 'X'), 403, 'ARCHIVE408', 'rename JC2', $jName);
                q2_deny($t, q2_delete($t, $s, $JC2), 403, 'ARCHIVE409', 'DELETE JC2', $jName);
                q2_deny($t, q2_putin($t, $s, $JC2, q2_doc($t, 'DR3')), 403, 'ARCHIVE410', 'put-in ke JC2', $jName);
                $t->eq((int) q2_row($t, $JC2)['is_active'], 1, 'JC2 tetap aktif');

                // K: On, B View+Delete, satu anak Off kosong -> DELETE K 200 (anak Off tidak menghalangi)
                $K = q2_folder($t, 'K', ['perm' => 1]);
                $Kc = q2_folder($t, 'KC', ['perm' => 0, 'parent' => $K]);
                q2_perm($t, $K, $b, 1, 0, 1, 0);
                $r = q2_delete($t, $s, $K);
                $t->status($r, 200, 'DELETE K (anak Off kosong)');
                $t->code($r, 'ARCHIVE203', 'DELETE K');
                $t->eq((int) q2_row($t, $K)['is_active'], 0, 'DB: K nonaktif');
                $t->eq((int) q2_row($t, $Kc)['is_active'], 0, 'DB: anak Off ikut nonaktif');
                // kontrol: anak ber-Izin On tanpa Delete menghalangi (418), anak Off berisi dokumen -> 401
                $K2 = q2_folder($t, 'K2', ['perm' => 1]);
                $K2c = q2_folder($t, 'K2C', ['perm' => 1, 'parent' => $K2]);
                q2_perm($t, $K2, $b, 1, 0, 1, 0);
                q2_perm($t, $K2c, $b, 1);
                q2_deny($t, q2_delete($t, $s, $K2), 403, 'ARCHIVE418', 'kontrol: anak On tanpa Delete menghalangi');
                $K3 = q2_folder($t, 'K3', ['perm' => 1]);
                $K3c = q2_folder($t, 'K3C', ['perm' => 0, 'parent' => $K3]);
                q2_perm($t, $K3, $b, 1, 0, 1, 0);
                q2_doc($t, 'DOC', ['parent' => $K3c]);
                q2_deny($t, q2_delete($t, $s, $K3), 400, 'ARCHIVE401', 'kontrol: anak Off berisi dokumen -> 401');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-37',
        'title' => '(rev K-13 a) A Update di JA, View saja di J, Store di X: put-in JA ke X / ke root dan PUT id_archive_parent = X = 200 (induk asal tidak dicek); tujuan tanpa Store 403 ARCHIVE410; JB put-in oleh A 403 ARCHIVE408 parameter JB; JC (Off) put-in ke X = 200',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);

            try {
                $f = q2_fb9($t, $b);
                [$J, $JB, $JC, $X] = [$f['J'], $f['JB'], $f['JC'], $f['X']];
                $jbName = q2_name_of($t, $JB);
                $mkJa = function ($label) use ($t, $b, $J) {
                    $id = q2_folder($t, $label, ['perm' => 1, 'parent' => $J]);
                    q2_perm($t, $id, $b, 1, 1, 0, 0);                  // A: View + Update di folder yang dipindah
                    return $id;
                };

                // put-in JA ke X (Store di X; Update di JA; View saja di J, induk asal tidak dicek)
                $JA1 = $mkJa('JA1');
                $r = q2_putin($t, $s, $X, $JA1);
                $t->status($r, 200, 'put-in JA1 ke X');
                $t->eq(q2_row($t, $JA1)['id_archive_parent'], $X, 'DB: JA1 di X');
                $h = q2_history($t, $JA1);
                $t->eq(end($h)['action'], 'move', 'riwayat JA1: move');
                // put-in JA ke root (tujuan root tidak dicek)
                $JA2 = $mkJa('JA2');
                $r = q2_putin($t, $s, null, $JA2);
                $t->status($r, 200, 'put-in JA2 ke root');
                $t->eq(q2_row($t, $JA2)['id_archive_parent'], null, 'DB: JA2 di root');
                $h = q2_history($t, $JA2);
                $t->eq(end($h)['action'], 'take out', 'riwayat JA2: take out');
                // PUT ganti induk ke X
                $JA3 = $mkJa('JA3');
                $ja3Name = q2_name_of($t, $JA3);
                $r = q2_put($t, $s, $JA3, q2_body($ja3Name, ['id_archive_parent' => $X]));
                $t->status($r, 200, 'PUT JA3 id_archive_parent = X');
                $t->code($r, 'ARCHIVE207', 'PUT JA3');
                $t->eq(q2_row($t, $JA3)['id_archive_parent'], $X, 'DB: JA3 di X');
                // lewat name (scan): dibalik ED-1124 K-2 b - name yang hanya cocok folder = tidak ada (findName hanya dokumen) -> 404 ARCHIVE400, JA5 tetap di J
                $JA5 = $mkJa('JA5');
                $r = q2_putin($t, $s, $X, null, q2_name_of($t, $JA5));
                $t->status($r, 404, 'put-in JA5 lewat name ke X: nama folder tidak dikenal');
                $t->code($r, 'ARCHIVE400', 'put-in JA5 lewat name');
                $t->eq(q2_row($t, $JA5)['id_archive_parent'], $J, 'DB: JA5 tetap di J');

                // tujuan tanpa Store: Y (A View+Update) dan J (View saja)
                $Y = q2_folder($t, 'Y', ['perm' => 1]);
                q2_perm($t, $Y, $b, 1, 1, 0, 0);
                $yName = q2_name_of($t, $Y);
                $jName = q2_name_of($t, $J);
                $JA4 = $mkJa('JA4');
                $ja4Name = q2_name_of($t, $JA4);
                $before = q2_snap($t, [$JA4, $Y, $J]);
                q2_deny($t, q2_putin($t, $s, $Y, $JA4), 403, 'ARCHIVE410', 'put-in JA4 ke Y (tanpa Store)', $yName);
                q2_deny($t, q2_put($t, $s, $JA4, q2_body($ja4Name, ['id_archive_parent' => $Y])), 403, 'ARCHIVE410', 'PUT JA4 induk = Y (tanpa Store)', $yName);
                q2_deny($t, q2_putin($t, $s, $J, $JA4), 403, 'ARCHIVE410', 'put-in JA4 ke J (View saja)', $jName);
                $t->true(q2_same($before, q2_snap($t, [$JA4, $Y, $J])), 'penolakan tidak mengubah data');
                $t->eq(q2_row($t, $JA4)['id_archive_parent'], $J, 'DB: JA4 tetap di J');

                // JB (On, tanpa baris A): A tidak punya View -> tidak bisa dipindah (ARCHIVE408, parameter JB)
                $before = q2_snap($t, [$JB, $X]);
                q2_deny($t, q2_putin($t, $s, $X, $JB), 403, 'ARCHIVE408', 'put-in JB ke X', $jbName);
                q2_deny($t, q2_putin($t, $s, null, $JB), 403, 'ARCHIVE408', 'put-in JB ke root', $jbName);
                q2_deny($t, q2_put($t, $s, $JB, q2_body($jbName, ['id_archive_parent' => $X])), 403, 'ARCHIVE408', 'PUT JB induk = X', $jbName);
                $t->true(q2_same($before, q2_snap($t, [$JB, $X])), 'JB tidak berubah');

                // JC (Off, A hanya View di J): bisa dipindah ke X
                $r = q2_putin($t, $s, $X, $JC);
                $t->status($r, 200, 'put-in JC (Off) ke X');
                $t->eq(q2_row($t, $JC)['id_archive_parent'], $X, 'DB: JC di X');

                // folder yang dipindah tanpa Update (hanya View): ditolak walau tujuan ber-Store
                $JV = q2_folder($t, 'JV', ['perm' => 1, 'parent' => $J]);
                q2_perm($t, $JV, $b, 1);
                q2_deny($t, q2_putin($t, $s, $X, $JV), 403, 'ARCHIVE408', 'put-in JV (View saja) ke X', q2_name_of($t, $JV));
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-15',
        'title' => '(rev) Matriks hak efektif BR-4: 2 pohon acak (seed tetap, kedalaman 3, ±35 folder, On/Off x pembuat x baris V/U/D/S, juga baris U/D/S tanpa View): access (pencarian + buka folder), 403 denied_by, rename (408), put-in (410), DELETE post-order (409/418/200) = model referensi independen',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);
            $me = q2_uname($t);
            $rights = ['view', 'update', 'delete', 'store'];
            $stats = ['nodes' => 0, 'view_ok' => 0, 'view_no' => 0, 'upd_ok' => 0, 'upd_no' => 0, 'sto_ok' => 0, 'sto_no' => 0, 'del_200' => 0, 'del_409' => 0, 'del_418' => 0, 'beda_dari_aturan_lama' => 0];

            foreach ([1025, 20261008] as $seed) {
                q2_purge($t);
                try {
                    [$W, $nodes] = q2_build_random_tree($t, $seed, $b, $me);
                    $ids = array_keys($nodes);
                    $t->true(count($ids) >= 25, "seed $seed: pohon cukup besar (" . count($ids) . ' folder)');
                    $Dx = q2_doc($t, 'DX');

                    // 1. access per folder: pencarian (baris) dan buka folder (result.access / 403 + denied_by)
                    foreach ($ids as $id) {
                        $stats['nodes']++;
                        $exp = [];
                        foreach ($rights as $right) {
                            $exp[$right] = q2_model_denied($nodes, $id, $right, $me) === null;
                            // cakupan: kasus yang HANYA benar menurut aturan baru (aturan lama K-1 a menolaknya)
                            if ($right !== 'view' && $exp[$right] && q2_model_denied_old($nodes, $id, $right, $me) !== null) {
                                $stats['beda_dari_aturan_lama']++;
                            }
                        }
                        $name = q2_name_of($t, $id);
                        $r = q2_list($t, $s, ['search' => json_encode(['query' => $name]), 'pagination' => 20]);
                        $t->status($r, 200, "seed $seed $name: search");
                        $row = q2_find($r, $id);
                        $t->true($row !== null, "seed $seed $name: folder tampil di pencarian");
                        q2_access_is($t, $row['access'], array_values($exp), "seed $seed $name: access baris", true);
                        $t->eq($row['is_folder_permission'], $nodes[$id]['perm'], "seed $seed $name: is_folder_permission");

                        $r = q2_open($t, $s, $id);
                        if ($exp['view']) {
                            $stats['view_ok']++;
                            $t->status($r, 200, "seed $seed $name: buka (View)");
                            q2_access_is($t, $r[1]['result']['access'], array_values($exp), "seed $seed $name: result.access", true);
                        } else {
                            $stats['view_no']++;
                            $t->status($r, 403, "seed $seed $name: buka (tanpa View)");
                            $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE407', "seed $seed $name: msg_code");
                            $den = q2_model_denied($nodes, $id, 'view', $me);
                            $t->eq($r[1]['result']['denied_by']['id_archive'] ?? null, $den, "seed $seed $name: denied_by = penolak teratas (model)");
                        }
                        $show = q2_show($t, $s, $id);
                        $t->status($show, $exp['view'] ? 200 : 403, "seed $seed $name: show");
                    }

                    // 2. Update (rename) dan Store (put-in dokumen ke folder, lalu dikeluarkan ke root)
                    foreach ($ids as $id) {
                        $den = q2_model_denied($nodes, $id, 'update', $me);
                        $r = q2_rename($t, $s, $id, q2_name_of($t, $id) . 'R');
                        if ($den === null) {
                            $stats['upd_ok']++;
                            $t->status($r, 200, "seed $seed rename $id: 200");
                        } else {
                            $stats['upd_no']++;
                            q2_deny($t, $r, 403, 'ARCHIVE408', "seed $seed rename $id", q2_name_of($t, $den));
                        }
                        $den = q2_model_denied($nodes, $id, 'store', $me);
                        $r = q2_putin($t, $s, $id, $Dx);
                        if ($den === null) {
                            $stats['sto_ok']++;
                            $t->status($r, 200, "seed $seed put-in $id: 200");
                            $t->eq(q2_row($t, $Dx)['id_archive_parent'], $id, "seed $seed put-in $id: dokumen di folder");
                            $t->status(q2_putin($t, $s, null, $Dx), 200, "seed $seed dokumen keluar ke root");
                        } else {
                            $stats['sto_no']++;
                            q2_deny($t, $r, 403, 'ARCHIVE410', "seed $seed put-in $id", q2_name_of($t, $den));
                            $t->eq(q2_row($t, $Dx)['id_archive_parent'], null, "seed $seed put-in $id: dokumen tetap di root");
                        }
                    }

                    // 3. DELETE post-order (anak dulu); model melacak folder yang sudah nonaktif
                    $deleted = [];
                    $post = [];
                    $walk = function ($id) use (&$walk, &$post, $nodes) {
                        foreach ($nodes[$id]['kids'] as $k) {
                            $walk($k);
                        }
                        $post[] = $id;
                    };
                    foreach ($ids as $id) {
                        if ($nodes[$id]['parent'] === null) {
                            $walk($id);
                        }
                    }
                    $descendants = function ($id) use (&$descendants, $nodes) {
                        $out = [];
                        foreach ($nodes[$id]['kids'] as $k) {
                            $out[] = $k;
                            $out = array_merge($out, $descendants($k));
                        }
                        return $out;
                    };
                    foreach ($post as $id) {
                        $r = q2_delete($t, $s, $id);
                        $name = q2_name_of($t, $id);
                        if (isset($deleted[$id])) {
                            $t->true($r[0] < 500, "seed $seed delete $name (sudah nonaktif): bukan 5xx");
                            continue;
                        }
                        $den = q2_model_denied($nodes, $id, 'delete', $me);
                        if ($den !== null) {
                            $stats['del_409']++;
                            q2_deny($t, $r, 403, 'ARCHIVE409', "seed $seed delete $name", q2_name_of($t, $den));
                            $t->eq((int) q2_row($t, $id)['is_active'], 1, "seed $seed delete $name: tetap aktif");
                            continue;
                        }
                        $block = false;
                        foreach ($descendants($id) as $d) {
                            if (!isset($deleted[$d]) && q2_model_denied($nodes, $d, 'delete', $me) !== null) {
                                $block = true;
                            }
                        }
                        if ($block) {
                            $stats['del_418']++;
                            q2_deny($t, $r, 403, 'ARCHIVE418', "seed $seed delete $name");
                            $t->eq((int) q2_row($t, $id)['is_active'], 1, "seed $seed delete $name: tetap aktif (418)");
                        } else {
                            $stats['del_200']++;
                            $t->status($r, 200, "seed $seed delete $name: 200");
                            $deleted[$id] = true;
                            foreach ($descendants($id) as $d) {
                                $deleted[$d] = true;
                                $t->eq((int) q2_row($t, $d)['is_active'], 0, "seed $seed delete $name: turunan nonaktif");
                            }
                            $t->eq((int) q2_row($t, $id)['is_active'], 0, "seed $seed delete $name: nonaktif");
                        }
                    }
                } finally {
                    q2_purge($t);
                }
            }
            q2_assert_clean($t, $base);
            // pohon acak cukup beragam: setiap cabang model terpakai (bila tidak, seed perlu diganti)
            foreach (['view_ok', 'view_no', 'upd_ok', 'upd_no', 'sto_ok', 'sto_no', 'del_200', 'del_409', 'del_418', 'beda_dari_aturan_lama'] as $k) {
                $t->true($stats[$k] > 0, "cakupan matriks: cabang $k terpakai (" . $stats[$k] . ')');
            }
            $t->note(json_encode($stats));
        },
    ],

];
