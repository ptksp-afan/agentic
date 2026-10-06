<?php
/**
 * ED-1024 - ronde 2: verifikasi perbaikan D-1 (PutInFolderRequest) secara mendalam.
 * R2-1: matriks tipe input put-in (array/objek/angka/bool di id_archive_parent & id_archives) -> tidak ada 5xx,
 *       422 untuk tipe salah, DB tidak berubah; urutan 403 ARCHIVE407 sebelum 422; aturan bisnis put-in tetap
 *       (ARCHIVE403/404/405/406) untuk input bertipe benar; kontrol positif tetap 200.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'R2-1',
        'title' => 'Fix D-1: put-in dengan tipe input salah = 422 (bukan 500), DB tidak berubah, 403 ARCHIVE407 tetap lebih dulu, aturan bisnis ARCHIVE403..406 tidak berubah, kontrol positif 200',
        'run'   => function ($t) {
            $s = $t->session();
            $base = 'api/v5/document-archive';
            q1_purge($t);

            $f   = q1_add($t, ['name' => 'QA01-R2-F', 'type' => 1, 'all' => 1]);                                   // folder semua lokasi
            $fj  = q1_add($t, ['name' => 'QA01-R2-FJ', 'type' => 1, 'all' => 0, 'locs' => ['JOG']]);                 // folder {JOG}
            $fs  = q1_add($t, ['name' => 'QA01-R2-FS', 'type' => 1, 'all' => 0, 'locs' => ['SMR']]);                 // folder {SMR}
            $da  = q1_add($t, ['name' => 'QA01-R2-DA', 'type' => 2, 'all' => 1, 'doc' => ['type' => 6]]);            // dokumen semua lokasi
            $ds  = q1_add($t, ['name' => 'QA01-R2-DS', 'type' => 2, 'all' => 0, 'locs' => ['SMR'], 'doc' => ['type' => 6]]); // dokumen {SMR}
            $dd  = q1_add($t, ['name' => 'QA01-R2-DD', 'type' => 2, 'all' => 1, 'doc' => ['type' => 6]]);            // dokumen kontrol positif
            $all = [$f, $fj, $fs, $da, $ds, $dd];
            $post = function ($body) use ($t, $s, $base) { return $t->call($s, 'POST', "$base/documents/put-in", $body); };

            // [label, body, status yang diharapkan | 'rekam' (cukup tidak 5xx), kode yang diharapkan | null]
            $typeCases = [
                ['parent array',              ['id_archive_parent' => ['a', 'b'], 'id_archives' => [$da]], 422, null],
                ['parent objek',              ['id_archive_parent' => ['k' => 'v'], 'id_archives' => [$da]], 422, null],
                ['parent int',                ['id_archive_parent' => 5, 'id_archives' => [$da]], 422, null],
                ['parent float',              ['id_archive_parent' => 1.5, 'id_archives' => [$da]], 422, null],
                ['parent bool',               ['id_archive_parent' => true, 'id_archives' => [$da]], 422, null],
                ['parent array + id_archives skalar', ['id_archive_parent' => ['a'], 'id_archives' => $da], 422, null],
                ['id_archives bersarang',     ['id_archive_parent' => $f, 'id_archives' => [['x']]], 422, null],
                ['id_archives [int]',         ['id_archive_parent' => $f, 'id_archives' => [5]], 422, null],
                ['id_archives [bool]',        ['id_archive_parent' => $f, 'id_archives' => [true]], 422, null],
                ['id_archives [objek]',       ['id_archive_parent' => $f, 'id_archives' => [['k' => 'v']]], 422, null],
                ['id_archives campuran [id, array]', ['id_archive_parent' => $f, 'id_archives' => [$da, ['x']]], 422, null],
                ['id_archives campuran [id, int]',   ['id_archive_parent' => $f, 'id_archives' => [$da, 7]], 422, null],
                ['parent array + id_archives bersarang', ['id_archive_parent' => ['a'], 'id_archives' => [['x']]], 422, null],
                ['parent root + id_archives bersarang',  ['id_archive_parent' => null, 'id_archives' => [['x']]], 422, null],
                // skalar non-string pada id_archives (bukan array): kontrak tidak menyebut; cukup tidak 5xx dan DB tidak berubah
                ['id_archives int skalar',    ['id_archive_parent' => $f, 'id_archives' => 5], 'rekam', null],
                ['id_archives float skalar',  ['id_archive_parent' => $f, 'id_archives' => 2.5022400011762e29], 'rekam', null],
                ['id_archives bool skalar',   ['id_archive_parent' => $f, 'id_archives' => true], 'rekam', null],
                ['id_archives int skalar + name', ['id_archive_parent' => $f, 'id_archives' => 5, 'name' => 'QA01-R2-DA'], 'rekam', null],
            ];

            $out = [];
            $fives = [];
            $unexpected = [];
            $shapes = [];
            $before = q1_snap($t, $all);

            try {
                foreach ([['asli', null], ['JOG', ['role' => 3, 'emp' => ['JOG']]]] as $mode) {
                    $run = function () use ($typeCases, $post, $t, $mode, &$out, &$fives, &$unexpected, &$shapes, $before, $all, $f, $fs, $fj, $da, $ds) {
                        foreach ($typeCases as $c) {
                            [$label, $body, $expStatus, $expCode] = $c;
                            $r = $post($body);
                            $out[] = $mode[0] . ' | ' . $label . ' -> ' . $r[0] . ' ' . (q1_code($r) ?? '-');
                            if ($r[0] >= 500) {
                                $fives[] = $mode[0] . ' | ' . $label . ' -> HTTP ' . $r[0];
                            }
                            if ($expStatus !== 'rekam' && $r[0] !== $expStatus) {
                                $unexpected[] = $mode[0] . ' | ' . $label . ' -> HTTP ' . $r[0] . ' (harap ' . $expStatus . ')';
                            }
                            if ($r[0] === 422 && !isset($shapes[$label])) {
                                $shapes[$label] = array_keys((array) ($r[1]['errors'] ?? []));
                                if (!isset($r[1]['message'])) {
                                    $unexpected[] = $mode[0] . ' | ' . $label . ' -> 422 tanpa kunci message';
                                }
                            }
                            // DB tidak berubah oleh satu pun permintaan di matriks tipe
                            if (!q1_same($before, q1_snap($t, $all))) {
                                $unexpected[] = $mode[0] . ' | ' . $label . ' -> DB berubah';
                                q1_restore($t, $before);
                            }
                        }

                        // 403 ARCHIVE407 tetap lebih dulu dari 422 (authorize sebelum validasi) - hanya user JOG
                        if ($mode[0] === 'JOG') {
                            $r = $post(['id_archive_parent' => [$fs], 'id_archives' => [$da]]);
                            $t->status($r, 403, 'JOG: parent array berisi id folder {SMR}: 403 sebelum 422');
                            $t->code($r, 'ARCHIVE407', 'JOG: parent array {SMR}');
                            $r = $post(['id_archive_parent' => $f, 'id_archives' => [$ds, ['x']]]);
                            $t->status($r, 403, 'JOG: id_archives [dokumen {SMR}, array]: 403 sebelum 422');
                            $t->code($r, 'ARCHIVE407', 'JOG: id_archives campuran {SMR}');
                            $r = $post(['id_archive_parent' => $fs, 'id_archives' => [['x']]]);
                            $t->status($r, 403, 'JOG: parent folder {SMR} + id_archives bersarang: 403 sebelum 422');
                            $t->code($r, 'ARCHIVE407', 'JOG: parent {SMR} + bersarang');
                            $t->true(q1_same($before, q1_snap($t, $all)), 'JOG: DB tidak berubah oleh 403');
                        }
                    };
                    if ($mode[1] === null) {
                        $run();
                    } else {
                        q1_with_user($t, $mode[1], function ($set) use ($run) { $run(); });
                    }
                }

                // ----- aturan bisnis put-in untuk input bertipe benar tidak berubah (user penuh: role 3 + employee semua lokasi)
                $r = $post(['id_archive_parent' => $fj, 'id_archives' => [$ds]]);
                $t->status($r, 400, 'dokumen {SMR} ke folder {JOG}');
                $t->code($r, 'ARCHIVE403', 'dokumen {SMR} ke folder {JOG}');
                $r = $post(['id_archive_parent' => $f, 'id_archives' => [$f]]);
                $t->status($r, 400, 'folder ke dirinya sendiri');
                $t->code($r, 'ARCHIVE404', 'folder ke dirinya sendiri');
                $r = $post(['id_archive_parent' => $f, 'id_archives' => [$da], 'name' => 'QA01-R2-DD']);
                $t->status($r, 400, 'id_archives + name');
                $t->code($r, 'ARCHIVE406', 'id_archives + name');
                $r = $post(['id_archive_parent' => $f]);
                $t->status($r, 400, 'tanpa sumber');
                $t->code($r, 'ARCHIVE406', 'tanpa sumber');
                $r = $post(['id_archive_parent' => $f, 'id_archives' => []]);
                $t->status($r, 400, 'id_archives [] tanpa name');
                $t->code($r, 'ARCHIVE406', 'id_archives [] tanpa name');
                $t->true(q1_same($before, q1_snap($t, $all)), 'DB tidak berubah oleh penolakan bisnis');

                // ----- kontrol positif (tipe benar tetap bekerja): id_archives array, objek-sebagai-daftar, name, parent root
                $r = $post(['id_archive_parent' => $f, 'id_archives' => [$dd]]);
                $t->status($r, 200, 'kontrol positif: put-in dokumen ke folder');
                $t->code($r, 'ARCHIVE204', 'kontrol positif');
                $t->eq(q1_row($t, $dd)['id_archive_parent'], $f, 'DB: dokumen masuk folder');
                $t->eq((int) q1_row($t, $dd)['is_active'], 1, 'DB: is_active = 1');
                $r = $post(['id_archive_parent' => null, 'id_archives' => [$dd]]);
                $t->status($r, 200, 'put-in ke root (id_archive_parent null)');
                $t->eq(q1_row($t, $dd)['id_archive_parent'], null, 'DB: dokumen di root');
                $r = $post(['id_archive_parent' => $f, 'name' => 'QA01-R2-DD']);
                $t->status($r, 200, 'put-in lewat name');
                $t->eq(q1_row($t, $dd)['id_archive_parent'], $f, 'DB: dokumen masuk folder lewat name');
                $r = $post(['id_archive_parent' => $f, 'id_archives' => ['k' => $da]]);
                $t->status($r, 200, 'id_archives bentuk objek {k: id} diperlakukan sebagai daftar (perilaku lama)');
                $t->eq(q1_row($t, $da)['id_archive_parent'], $f, 'DB: dokumen dari objek masuk folder');
            } finally {
                q1_purge($t);
                $t->note(implode(' ; ', $out) . ' || kunci errors 422: ' . json_encode($shapes));
            }

            q1_assert_clean($t);
            $t->eq($fives, [], 'tidak ada respons 5xx untuk matriks tipe put-in');
            $t->eq($unexpected, [], 'status sesuai harapan dan DB tidak berubah pada matriks tipe');
        },
    ],

];
