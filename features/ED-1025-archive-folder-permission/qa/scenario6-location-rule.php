<?php
/**
 * ED-1025 ronde 3 - aturan lokasi folder (Rules/DocumentArchive/Archive/LocationRule, milik ED-1024, disentuh ED-1025 untuk D-3)
 * dan aturan id_locations pada UpdateRequest / CreateFolderRequest:
 *   X-14: matriks is_all_location x id_locations x induk (induk lokasi tertentu {L1,L2}, {L1}, semua lokasi, tanpa induk)
 *         pada PUT archives/{id} (induk dari data dan induk dikirim) dan POST archives/create-folder.
 *         Perilaku yang berlaku sejak ED-1024 tidak boleh berubah (ARCHIVE402 = 400, daftar valid/duplikat/kosong = 200),
 *         tipe salah (array/objek pada is_all_location atau elemen id_locations) = 422 (bukan 500, bukan 400, bukan 200),
 *         setiap penolakan tidak mengubah data, dan penolakan tidak bisa dihindari dengan tipe salah (tanpa lolos).
 * Data uji berawalan QA02-; sisa diperiksa di akhir.
 */
require_once __DIR__ . '/qa_lib.php';

if (!function_exists('q6_cases')) {

    /**
     * Kasus [label, is_all_location, id_locations ('__absent__' = kunci tidak dikirim), harapan per induk].
     * Harapan: 200 | 'E402' (400 ARCHIVE402) | 422. Urutan induk: PA {L1,L2}, PL {L1}, PALL (semua lokasi), ROOT (tanpa induk).
     * Nilai lokasi dipakai simbolik: 'L1','L2','L3' diganti id lokasi nyata saat run.
     */
    function q6_cases()
    {
        $A = '__absent__';

        return [
            // ---- daftar valid, duplikat, kosong
            ['all=0 [L1]',              0, ['L1'],                      [200,    200,    200,  200]],
            ['all=0 [L1,L2]',           0, ['L1', 'L2'],                [200,    'E402', 200,  200]],
            ['all=0 [L2,L1]',           0, ['L2', 'L1'],                [200,    'E402', 200,  200]],
            ['all=0 [L1,L1] duplikat',  0, ['L1', 'L1'],                [200,    200,    200,  200]],
            ['all=0 [L1,L2,L1]',        0, ['L1', 'L2', 'L1'],          [200,    'E402', 200,  200]],
            ['all=0 tanpa id_locations', 0, $A,                         [200,    200,    200,  200]],
            ['all=0 []',                0, [],                          [200,    200,    200,  200]],
            ['all=0 null',              0, null,                        [200,    200,    200,  200]],
            // ---- lokasi di luar induk / id tak ada
            ['all=0 [L3]',              0, ['L3'],                      ['E402', 'E402', 200,  200]],
            ['all=0 [L1,L3]',           0, ['L1', 'L3'],                ['E402', 'E402', 200,  200]],
            ['all=0 [L3,L3]',           0, ['L3', 'L3'],                ['E402', 'E402', 200,  200]],
            ["all=0 ['nope']",          0, ['nope'],                    ['E402', 'E402', 422,  422]],
            ["all=0 [L1,'nope']",       0, ['L1', 'nope'],              ['E402', 'E402', 422,  422]],
            ['all=0 [null]',            0, [null],                      [422,    422,    422,  422]],
            ['all=0 [L1,null]',         0, ['L1', null],                [422,    422,    422,  422]],
            ["all=1 ['']",              1, [''],                        ['E402', 'E402', 422,  422]],   // '' -> null (middleware); ARCHIVE402 (induk lokasi tertentu) mendahului
            // id_locations skalar (bukan daftar): diterima sebagai satu lokasi, sama seperti sebelum ED-1025
            ['all=0 skalar L1',         0, 'L1',                        [200,    200,    200,  200]],
            ['all=0 skalar L3',         0, 'L3',                        ['E402', 'E402', 200,  200]],
            // ---- semua lokasi di bawah induk lokasi tertentu
            ['all=1',                   1, $A,                          ['E402', 'E402', 200,  200]],
            ['all=1 [L1]',              1, ['L1'],                      ['E402', 'E402', 200,  200]],
            ['all=2 (di luar in:0,1)',  2, ['L1'],                      ['E402', 'E402', 'E402', 422]],
            ["all='abc'",               'abc', ['L1'],                  [422,    422,    422,  422]],
            // ---- tipe salah pada is_all_location = 422
            ['all=[x]',                 ['x'], ['L1'],                  [422,    422,    422,  422]],
            ['all={a:b}',               ['a' => 'b'], ['L1'],           [422,    422,    422,  422]],
            ['all=[[x]]',               [['x']], ['L1'],                [422,    422,    422,  422]],
            ['all=[1]',                 [1], $A,                        [422,    422,    422,  422]],
            ['all=[] ',                 [], ['L1'],                     [422,    422,    422,  422]],
            // ---- elemen id_locations array/objek = 422 (daftar 1 elemen dan >= 2 elemen)
            ['all=0 [[x]]',             0, [['x']],                     [422,    422,    422,  422]],
            ['all=0 [L1,[x]]',          0, ['L1', ['x']],               [422,    422,    422,  422]],
            ['all=0 [[x],L1]',          0, [['x'], 'L1'],               [422,    422,    422,  422]],
            ['all=0 [L3,[x]]',          0, ['L3', ['x']],               [422,    422,    422,  422]],
            ['all=0 [[x],L3]',          0, [['x'], 'L3'],               [422,    422,    422,  422]],
            ['all=0 [[x],[y]]',         0, [['x'], ['y']],              [422,    422,    422,  422]],
            ['all=0 [{a:b}]',           0, [['a' => 'b']],              [422,    422,    422,  422]],
            ['all=0 [nope,[x]]',        0, ['nope', ['x']],             [422,    422,    422,  422]],
            ['all=0 [L1,L1,[x]]',       0, ['L1', 'L1', ['x']],         [422,    422,    422,  422]],
            ['all=0 [L1,[]]',           0, ['L1', []],                  [422,    422,    422,  422]],
            ['all=0 [[]]',              0, [[]],                        [422,    422,    422,  422]],
            ['all=0 {a:[x]}',           0, ['a' => ['x']],              [422,    422,    422,  422]],
            ['all=0 {a:L1,b:[x]}',      0, ['a' => 'L1', 'b' => ['x']], [422,    422,    422,  422]],
            // ---- tipe salah tidak bisa menghindari ARCHIVE402 (tidak ada yang lolos 200)
            ['all=[x] + [L3]',          ['x'], ['L3'],                  [422,    422,    422,  422]],
            ['all=1 + [[x]]',           1, [['x']],                     [422,    422,    422,  422]],
            ['all=[x] + [L3,[x]]',      ['x'], ['L3', ['x']],           [422,    422,    422,  422]],
        ];
    }

    /** Terjemahkan simbol lokasi 'L1'... (juga di dalam array bersarang) menjadi id lokasi nyata. */
    function q6_resolve($v, array $map)
    {
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $e) {
                $out[$k] = q6_resolve($e, $map);
            }

            return $out;
        }

        return is_string($v) && isset($map[$v]) ? $map[$v] : $v;
    }

    /** Ganti set lokasi satu folder (pembuatan data uji langsung di DB). */
    function q6_set_locs($t, $id, $all, array $locs)
    {
        q2_set_archive($t, $id, ['is_all_location' => $all]);
        q2_w($t, function ($c) use ($id, $locs) {
            $c->table('archive_locations')->where('id_archive', $id)->delete();
            foreach ($locs as $l) {
                $c->table('archive_locations')->insert(['id_archive' => $id, 'id_location' => $l]);
            }
        });
    }

    function q6_locs_of($t, $id)
    {
        $l = $t->db()->table('archive_locations')->where('id_archive', $id)->pluck('id_location')->all();
        sort($l);

        return $l;
    }

    /** Snapshot target (baris archives + set lokasi). */
    function q6_snap($t, $id)
    {
        return ['row' => q2_row($t, $id), 'locs' => q6_locs_of($t, $id), 'perm' => q2_perm_map($t, $id)];
    }
}

return [

    [
        'id'    => 'X-14',
        'title' => 'LocationRule (ED-1024) + id_locations (D-3): matriks is_all_location x id_locations x induk pada PUT archives/{id} dan create-folder: daftar valid/duplikat/kosong/null = 200, di luar induk = 400 ARCHIVE402, id tak ada = 422 (tanpa induk) atau 400 (induk lokasi tertentu), tipe salah = 422 (tanpa 5xx, tanpa lolos), data tidak berubah pada penolakan',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $api = 'api/v5/document-archive/';
            $locIds = $t->db()->table('locations')->orderBy('id_location')->limit(3)->pluck('id_location')->all();
            if (count($locIds) < 3) {
                $t->fail('prasyarat: butuh 3 lokasi di DB uji');
            }
            $map = ['L1' => $locIds[0], 'L2' => $locIds[1], 'L3' => $locIds[2]];

            try {
                // induk (lokasi tertentu {L1,L2}; {L1}; semua lokasi; tanpa induk) dan satu folder target per induk, awal {L1}
                $parents = [
                    'PA'   => q2_folder($t, 'PA', ['perm' => 0]),
                    'PL'   => q2_folder($t, 'PL', ['perm' => 0]),
                    'PALL' => q2_folder($t, 'PALL', ['perm' => 0]),
                ];
                q6_set_locs($t, $parents['PA'], 0, [$map['L1'], $map['L2']]);
                q6_set_locs($t, $parents['PL'], 0, [$map['L1']]);
                q6_set_locs($t, $parents['PALL'], 1, []);
                $targets = [];
                foreach ($parents as $k => $p) {
                    $targets[$k] = q2_folder($t, 'T' . $k, ['parent' => $p, 'perm' => 0]);
                }
                $targets['ROOT'] = q2_folder($t, 'TROOT', ['perm' => 0]);
                foreach ($targets as $id) {
                    q6_set_locs($t, $id, 0, [$map['L1']]);
                }
                $names = [];
                foreach ($targets as $k => $id) {
                    $names[$k] = q2_row($t, $id)['name'];
                }
                $order = ['PA', 'PL', 'PALL', 'ROOT'];
                $reset = function ($k) use ($t, $targets, $parents, $names, $map) {
                    q2_set_archive($t, $targets[$k], [
                        'name' => $names[$k], 'is_all_location' => 0, 'is_active' => 1, 'updated_by' => 'QA02',
                        'id_archive_parent' => $k === 'ROOT' ? null : $parents[$k],
                    ]);
                    q6_set_locs($t, $targets[$k], 0, [$map['L1']]);
                };
                $archivesBefore = $t->db()->table('archives')->count();

                $violations = [];
                $count = ['200' => 0, 'E402' => 0, '422' => 0];
                $total = 0;
                foreach (q6_cases() as $case) {
                    [$label, $all, $locs, $expects] = $case;
                    foreach ($order as $i => $k) {
                        $exp = $expects[$i];
                        $body = ['is_all_location' => $all];
                        if ($locs !== '__absent__') {
                            $body['id_locations'] = q6_resolve($locs, $map);
                        }

                        // modus: put implisit (induk dari data), put eksplisit (induk dikirim), create
                        $modes = ['put-implisit', 'create'];
                        if ($k !== 'ROOT') {
                            $modes = ['put-implisit', 'put-eksplisit', 'create'];
                        }
                        foreach ($modes as $mode) {
                            $total++;
                            $tag = $label . ' | induk ' . $k . ' | ' . $mode;
                            $reset($k);
                            $beforeSnap = q6_snap($t, $targets[$k]);
                            $beforeCount = $t->db()->table('archives')->count();
                            $createName = q2_name('N');

                            if ($mode === 'create') {
                                $payload = ['name' => $createName] + $body + ($k === 'ROOT' ? [] : ['id_archive_parent' => $parents[$k]]);
                                $r = $t->call($s, 'POST', $api . 'archives/create-folder', $payload);
                            } else {
                                $payload = ['name' => $names[$k]] + $body + ($mode === 'put-eksplisit' ? ['id_archive_parent' => $parents[$k]] : []);
                                $r = $t->call($s, 'PUT', $api . 'archives/' . $targets[$k], $payload);
                            }

                            $want = $exp === 'E402' ? 400 : $exp;
                            if ($r[0] >= 500) {
                                $violations[] = $tag . ' -> HTTP ' . $r[0] . ' ' . substr(json_encode($r[1]['message'] ?? $r[1]), 0, 100);
                            } elseif ((int) $r[0] !== $want) {
                                $violations[] = $tag . ' -> HTTP ' . $r[0] . ' ' . (q2_code($r) ?? '-') . ', diharapkan ' . $want . ($exp === 'E402' ? ' ARCHIVE402' : '');
                            } elseif ($exp === 'E402' && q2_code($r) !== 'ARCHIVE402') {
                                $violations[] = $tag . ' -> 400 dengan kode ' . q2_code($r) . ', diharapkan ARCHIVE402';
                            }

                            if ($r[0] >= 400) {
                                // penolakan: tidak ada perubahan, tidak ada folder baru
                                if (q6_snap($t, $targets[$k]) !== $beforeSnap) {
                                    $violations[] = $tag . ' -> HTTP ' . $r[0] . ' tetapi target berubah';
                                }
                                if ($t->db()->table('archives')->count() !== $beforeCount) {
                                    $violations[] = $tag . ' -> HTTP ' . $r[0] . ' tetapi jumlah folder berubah';
                                }
                            } elseif ($exp === 200 && (int) $r[0] === 200) {
                                // sukses: set lokasi sesuai aturan sync (daftar tidak kosong menggantikan; kosong/null/tanpa = tidak disentuh)
                                $given = $locs === '__absent__' || empty($locs) ? [] : array_values(array_unique((array) q6_resolve($locs, $map)));
                                if ($mode === 'create') {
                                    $newId = $t->db()->table('archives')->where('name', $createName)->value('id_archive');
                                    $expected = $given;
                                    sort($expected);
                                    if ($newId === null) {
                                        $violations[] = $tag . ' -> 200 tetapi folder baru tidak ada';
                                    } elseif (q6_locs_of($t, $newId) !== $expected) {
                                        $violations[] = $tag . ' -> lokasi folder baru ' . json_encode(q6_locs_of($t, $newId)) . ', diharapkan ' . json_encode($expected);
                                    } elseif ((int) q2_row($t, $newId)['is_all_location'] !== (int) $all) {
                                        $violations[] = $tag . ' -> is_all_location folder baru salah';
                                    }
                                } else {
                                    $expected = $given ?: [$map['L1']];
                                    sort($expected);
                                    if (q6_locs_of($t, $targets[$k]) !== $expected) {
                                        $violations[] = $tag . ' -> lokasi target ' . json_encode(q6_locs_of($t, $targets[$k])) . ', diharapkan ' . json_encode($expected);
                                    }
                                }
                            }
                            $count[(string) $exp]++;
                            // bersihkan folder buatan create (nama QA02-N-*)
                            q2_w($t, function ($c) {
                                $ids = $c->table('archives')->where('name', 'like', Q2_PREFIX . 'N-%')->pluck('id_archive')->all();
                                foreach ($ids as $id) {
                                    $c->table('archive_locations')->where('id_archive', $id)->delete();
                                    $c->table('archive_permissions')->where('id_archive', $id)->delete();
                                    $c->table('archives')->where('id_archive', $id)->delete();
                                }
                            });
                        }
                    }
                }
                $t->note($total . ' kasus (induk x kasus x mode); harapan: ' . json_encode($count));
                $t->eq($violations, [], 'tidak ada 5xx, status sesuai, data tidak berubah pada penolakan, lokasi tersimpan sesuai');
                $t->eq($t->db()->table('archives')->count(), $archivesBefore, 'jumlah folder sama seperti sebelum matriks (tidak ada folder bocor)');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

];
