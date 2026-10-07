<?php
/**
 * ED-1027 - endpoint detail verifikasi: AC-5 (GET verifications: All Archive), AC-6 (GET verifications/{id}: per folder),
 * AC-8 (kasus error 404/400/403, tanpa 500, kode pesan di dua bahasa), AC-9 (permission List Archive: 403 GE0114, 401).
 * Semua data uji dipulihkan di finally (q27_cleanup). Oracle = q27_oracle (SQL mentah, tanpa kode BE).
 */
require_once __DIR__ . '/qa_lib.php';

$q27_types_url = 'api/v5/select/document-archive/archive/types';

/** Opsi select types tanpa "Folder": [[value, label], ...]. */
$q27_options = function ($t, $s) use ($q27_types_url) {
    $r = $t->call($s, 'GET', $q27_types_url);
    $t->status($r, 200, 'select types');
    $opts = [];
    foreach ($r[1]['result']['options'] ?? [] as $o) {
        if (strtoupper((string) $o['value']) === 'FOLDER') {
            continue;
        }
        $opts[] = [(int) $o['value'], $o['label']];
    }

    return $opts;
};

/** Validasi bentuk detail + konsistensi angka. Mengembalikan result. */
$q27_check_detail = function ($t, $r, array $opts, $label) {
    $t->status($r, 200, $label . ' status');
    $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE200', $label . ': msg_code ARCHIVE200');
    $res = $r[1]['result'] ?? [];
    $t->eq(array_keys($res), ['id_archive', 'name', 'verified', 'unverified', 'total', 'transaction_types', 'last_sessions'], $label . ': kunci result sesuai kontrak');
    foreach (['verified', 'unverified', 'total'] as $k) {
        $t->true(is_int($res[$k]), $label . ": $k integer");
    }
    $t->eq($res['verified'] + $res['unverified'], $res['total'], $label . ': verified + unverified = total');
    $rows = $res['transaction_types'];
    $t->eq(array_map(function ($row) {
        return [$row['transaction_type'], $row['label']];
    }, $rows), $opts, $label . ': transaction_types urutan & label = opsi select types tanpa Folder');
    $sum = ['verified' => 0, 'unverified' => 0, 'total' => 0];
    foreach ($rows as $row) {
        $t->eq(array_keys($row), ['transaction_type', 'label', 'verified', 'unverified', 'total'], $label . ': kunci baris tipe ' . $row['transaction_type']);
        $t->eq($row['verified'] + $row['unverified'], $row['total'], $label . ': tipe ' . $row['transaction_type'] . ' verified+unverified=total');
        foreach ($sum as $k => $_) {
            $sum[$k] += $row[$k];
        }
    }
    $t->eq($sum, ['verified' => $res['verified'], 'unverified' => $res['unverified'], 'total' => $res['total']], $label . ': jumlah kolom tipe = header');
    $t->true(is_array($res['last_sessions']), $label . ': last_sessions array');

    return $res;
};

return [

    [
        'id'    => 'AC-5',
        'title' => 'GET verifications: verified+unverified=total; transaction_types = daftar 01 (urutan & label = opsi select types tanpa Folder, dua bahasa); tipe bernilai 0 tetap ada; jumlah tiap kolom = header; angka & angka per tipe = oracle (data nyata dan fixture, user Semarang & superadmin)',
        'run'   => function ($t) use ($q27_options, $q27_check_detail) {
            $s = $t->session();
            q27_baseline($t);
            try {
                foreach (['smr', 'bypass'] as $mode) {
                    q27_with_user($t, q27_opts($mode), function ($set) use ($t, $s, $mode, $q27_options, $q27_check_detail) {
                        $opts = $q27_options($t, $s);
                        $t->eq(count($opts), 7, "[$mode] opsi select types tanpa Folder = 7");
                        $t->eq(array_column($opts, 0), [6, 7, 8, 9, 29, 31, 222], "[$mode] urutan tipe 01");

                        // --- data nyata (tanpa fixture): tipe bernilai 0 tetap ada
                        $o0 = q27_oracle($t, q27_u($t, $mode));
                        $r0 = q27_verif($t, $s);
                        $res0 = $q27_check_detail($t, $r0, $opts, "[$mode] data nyata");
                        $zeros = array_filter($res0['transaction_types'], function ($row) {
                            return $row['total'] === 0;
                        });
                        $t->true(count($zeros) >= 1, "[$mode] data nyata: ada tipe total 0 yang tetap dikirim (" . count($zeros) . ' tipe)');
                        $t->eq([$res0['verified'], $res0['total']], q27_vt($o0['summary']), "[$mode] data nyata: header = oracle");
                        $t->eq($res0['id_archive'], null, "[$mode] id_archive null");
                        $t->eq($res0['name'], null, "[$mode] name null");
                        foreach ($res0['transaction_types'] as $row) {
                            $vt = $o0['summary']['types'][$row['transaction_type']] ?? [0, 0];
                            $t->eq([$row['verified'], $row['total']], [$vt[0], $vt[1]], "[$mode] data nyata: tipe {$row['transaction_type']} = oracle");
                        }

                        // --- dengan fixture
                        $x = q27_tree($t);
                        $o = q27_oracle($t, q27_u($t, $mode));
                        $res = $q27_check_detail($t, q27_verif($t, $s), $opts, "[$mode] fixture");
                        $t->eq([$res['verified'], $res['total']], q27_vt($o['summary']), "[$mode] fixture: header = oracle");
                        foreach ($res['transaction_types'] as $row) {
                            $vt = $o['summary']['types'][$row['transaction_type']] ?? [0, 0];
                            $t->eq([$row['verified'], $row['total']], [$vt[0], $vt[1]], "[$mode] fixture: tipe {$row['transaction_type']} = oracle");
                        }
                        $t->eq([$res['verified'] - $res0['verified'], $res['total'] - $res0['total']], $mode === 'smr' ? [6, 13] : [7, 21], "[$mode] fixture: delta header = angka tangan");

                        // --- dua bahasa: label sama dengan opsi select types bahasa itu
                        foreach (['EN', 'ID'] as $lang) {
                            $set(q27_opts($mode) + ['lang' => $lang]);
                            $optsL = $q27_options($t, $s);
                            $q27_check_detail($t, q27_verif($t, $s), $optsL, "[$mode][$lang]");
                        }
                        $set(q27_opts($mode) + ['lang' => 'EN']);
                        $en = array_column(q27_verif($t, $s)[1]['result']['transaction_types'], 'label');
                        $set(q27_opts($mode) + ['lang' => 'ID']);
                        $id = array_column(q27_verif($t, $s)[1]['result']['transaction_types'], 'label');
                        $t->true($en !== $id, "[$mode] label EN berbeda dari ID (mengikuti bahasa user): " . $en[0] . ' / ' . $id[0]);

                        q27_cleanup($t, "[$mode] fixture dibuang");
                        q27_baseline($t);
                    });
                }
            } finally {
                q27_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-6',
        'title' => 'GET verifications/{F}: angka header = document_verified F di list induknya; jumlah baris tipe = header; tipe = oracle & angka tangan; folder kosong 0/0 dengan 7 baris tipe 0; id/name folder',
        'run'   => function ($t) use ($q27_options, $q27_check_detail) {
            $s = $t->session();
            q27_baseline($t);
            try {
                foreach (['smr', 'bypass'] as $mode) {
                    q27_with_user($t, q27_opts($mode), function () use ($t, $s, $mode, $q27_options, $q27_check_detail) {
                        $opts = $q27_options($t, $s);
                        $x = q27_tree($t);
                        $x['E'] = q27_folder($t, 'E', ['parent' => $x['P']]);
                        $o = q27_oracle($t, q27_u($t, $mode));
                        $n = 0;
                        foreach ($x as $k => $id) {
                            if ($k[0] === 'd' || !isset($o['visible'][$id])) {
                                continue;
                            }
                            $n++;
                            $res = $q27_check_detail($t, q27_verif($t, $s, $id), $opts, "[$mode] folder $k");
                            $t->eq($res['id_archive'], $id, "[$mode] $k: id_archive");
                            $t->eq($res['name'], q27_n($t, $id), "[$mode] $k: name");

                            // = document_verified folder itu di list induknya
                            $parent = $o['folders'][$id]['parent'];
                            $lr = q27_list($t, $s, $parent === null ? [] : ['id_archive' => $parent]);
                            $row = q27_map($lr)[$id] ?? null;
                            $t->true($row !== null, "[$mode] $k: baris folder ada di list induk");
                            $t->eq([$res['verified'], $res['total']], q27_api_vt($row['document_verified']), "[$mode] $k: header = kolom di list induk");
                            $t->eq([$res['verified'], $res['total']], q27_vt($o['folders'][$id]), "[$mode] $k: header = oracle");
                            foreach ($res['transaction_types'] as $tr) {
                                $vt = $o['folders'][$id]['types'][$tr['transaction_type']] ?? [0, 0];
                                $t->eq([$tr['verified'], $tr['total']], [$vt[0], $vt[1]], "[$mode] $k: tipe {$tr['transaction_type']} = oracle");
                            }
                            if ($k === 'F') {
                                $expected = $mode === 'smr'
                                    ? [6 => [1, 2], 7 => [2, 3], 8 => [1, 2], 9 => [0, 1], 29 => [0, 1], 31 => [0, 1], 222 => [1, 1]]
                                    : [6 => [2, 4], 7 => [2, 5], 8 => [1, 2], 9 => [0, 1], 29 => [0, 1], 31 => [0, 1], 222 => [1, 1]];
                                $got = [];
                                foreach ($res['transaction_types'] as $tr) {
                                    $got[$tr['transaction_type']] = [$tr['verified'], $tr['total']];
                                }
                                $t->eq($got, $expected, "[$mode] F: tipe = angka tangan");
                                $t->eq([$res['verified'], $res['unverified'], $res['total']], $mode === 'smr' ? [5, 6, 11] : [6, 9, 15], "[$mode] F: header = angka tangan");
                            }
                        }
                        $t->true($n >= 6, "[$mode] folder dicek: $n");

                        // folder kosong
                        $re = $q27_check_detail($t, q27_verif($t, $s, $x['E']), $opts, "[$mode] folder kosong");
                        $t->eq([$re['verified'], $re['unverified'], $re['total']], [0, 0, 0], "[$mode] folder kosong: 0/0/0");
                        foreach ($re['transaction_types'] as $tr) {
                            $t->eq([$tr['verified'], $tr['unverified'], $tr['total']], [0, 0, 0], "[$mode] folder kosong: tipe {$tr['transaction_type']} = 0");
                        }
                        $t->eq(count($re['transaction_types']), 7, "[$mode] folder kosong: 7 baris tipe tetap ada");
                        $t->eq($re['last_sessions'], [], "[$mode] folder kosong: tanpa sesi");
                        $lr = q27_list($t, $s, ['id_archive' => $x['P']]);
                        $t->eq(q27_map($lr)[$x['E']]['document_verified'], ['verified' => 0, 'total' => 0], "[$mode] folder kosong di list: 0/0");

                        q27_cleanup($t, "[$mode] fixture dibuang");
                        q27_baseline($t);
                    });
                }
            } finally {
                q27_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-8',
        'title' => 'GET verifications/{id}: tak ada 404 ARCHIVE400; dokumen 400 ARCHIVE417; folder di luar lokasi 403 ARCHIVE407; folder tanpa View (langsung & turunan) 403 ARCHIVE407 + nama folder penolak; folder nonaktif 404; id aneh 404 tanpa 500; kode ada di dua bahasa',
        'run'   => function ($t) {
            $s = $t->session();
            q27_baseline($t);
            try {
                $be = rtrim($t->conf('BE_DIR'), '/');
                foreach (['en_EN', 'id_ID'] as $f) {
                    $src = file_get_contents($be . '/app/Lib/lang/' . $f . '.php');
                    foreach (['ARCHIVE200', 'ARCHIVE400', 'ARCHIVE407', 'ARCHIVE417', 'GE0114'] as $code) {
                        $t->true(preg_match("/'$code'\\s*=>/", $src) === 1, "kode $code ada di $f.php");
                    }
                }

                q27_with_user($t, q27_opts('smr'), function ($set) use ($t, $s) {
                    $x = q27_tree($t);
                    $msgs = [];
                    foreach (['EN', 'ID'] as $lang) {
                        $set(q27_opts('smr') + ['lang' => $lang]);

                        $r = q27_verif($t, $s, Q27_RANDOM_ID);
                        q27_deny($t, $r, 404, 'ARCHIVE400', "[$lang] id tak ada");
                        $msgs[$lang]['400'] = $r[1]['message'] ?? null;
                        $t->true(!isset($r[1]['result']) || empty($r[1]['result']), "[$lang] id tak ada: tanpa result");

                        $r = q27_verif($t, $s, $x['dF1']);
                        q27_deny($t, $r, 400, 'ARCHIVE417', "[$lang] id dokumen");
                        $msgs[$lang]['417'] = $r[1]['message'] ?? null;

                        $r = q27_verif($t, $s, $x['SJ']);
                        q27_deny($t, $r, 403, 'ARCHIVE407', "[$lang] folder di luar lokasi (SJ)");
                        $msgs[$lang]['407'] = $r[1]['message'] ?? null;
                        $t->true(!isset($r[1]['result']) || empty($r[1]['result']), "[$lang] di luar lokasi: tanpa result");

                        $r = q27_verif($t, $s, $x['JOGR']);
                        q27_deny($t, $r, 403, 'ARCHIVE407', "[$lang] folder root di luar lokasi (JOGR)");

                        $r = q27_verif($t, $s, $x['dJF']);
                        q27_deny($t, $r, 403, 'ARCHIVE407', "[$lang] dokumen di luar lokasi: urutan cek lokasi dulu (403)");

                        $r = q27_verif($t, $s, $x['SP']);
                        q27_deny($t, $r, 403, 'ARCHIVE407', "[$lang] folder tanpa View (SP)");
                        $t->true(!isset($r[1]['result']) || empty($r[1]['result']), "[$lang] tanpa View: tanpa result");

                        $r = q27_verif($t, $s, $x['SPc']);
                        q27_deny($t, $r, 403, 'ARCHIVE407', "[$lang] turunan folder tanpa View (SPc)");
                        $t->true(strpos(json_encode($r[1], JSON_UNESCAPED_UNICODE), q27_n($t, $x['SP'])) !== false, "[$lang] SPc: pesan memuat nama folder penolak (SP)");

                        $r = q27_verif($t, $s, $x['PRIV']);
                        q27_deny($t, $r, 403, 'ARCHIVE407', "[$lang] root tanpa View (PRIV)");

                        $r = q27_verif($t, $s, $x['SX']);
                        q27_deny($t, $r, 404, 'ARCHIVE400', "[$lang] folder nonaktif (SX)");

                        // dokumen nonaktif yang terlihat lokasi: 400 ARCHIVE417 atau 404 (tidak boleh 500)
                        $r = q27_verif($t, $s, $x['dDEL']);
                        $t->true(in_array($r[0], [400, 404], true), "[$lang] dokumen is_active 0: HTTP {$r[0]} (400/404)");

                        // folder terlihat: 200 (kontrol)
                        $t->status(q27_verif($t, $s, $x['SV']), 200, "[$lang] folder dengan baris View (SV) 200");
                        $t->status(q27_verif($t, $s, $x['F']), 200, "[$lang] F 200");
                    }
                    foreach (['400', '407', '417'] as $k) {
                        $t->true(!empty($msgs['EN'][$k]) && !empty($msgs['ID'][$k]) && $msgs['EN'][$k] !== $msgs['ID'][$k], "pesan ARCHIVE$k berbeda EN/ID dan tidak kosong: '{$msgs['EN'][$k]}' / '{$msgs['ID'][$k]}'");
                    }

                    // id aneh: tak boleh 500
                    $weird = [
                        'spasi' => 'a%20b', 'unicode' => rawurlencode("caf\xc3\xa9"), 'kutip' => rawurlencode("x'y"), 'sql' => rawurlencode("1' OR '1'='1"),
                        'persen' => '%25', 'titik2' => '..', 'panjang-31' => str_repeat('1', 31), 'panjang-200' => str_repeat('9', 200),
                        'nol' => '0', 'huruf' => 'abc', 'nul' => '%00', 'array' => 'a%5B%5D', 'emoji' => rawurlencode("\xf0\x9f\x98\x80"),
                        'minus' => '-1', 'koma' => '1,2', 'garis-miring' => rawurlencode('a/b'), 'tab' => '%09', 'trailing-space' => $x['F'] . '%20',
                    ];
                    foreach ($weird as $label => $id) {
                        $r = $t->call($s, 'GET', Q27_BASE . '/verifications/' . $id);
                        $t->true($r[0] < 500, "id aneh '$label': HTTP {$r[0]}");
                        $t->true(in_array($r[0], [200, 400, 403, 404], true), "id aneh '$label': HTTP {$r[0]} (200/4xx), code " . q27_code($r));
                    }
                    $r = $t->call($s, 'GET', Q27_BASE . '/verifications/' . $x['F'] . '/');
                    $t->true($r[0] < 500, 'trailing slash: HTTP ' . $r[0]);

                    // metode lain tidak 500
                    foreach (['POST', 'PUT', 'DELETE'] as $m) {
                        $r = $t->call($s, $m, Q27_BASE . '/verifications/' . $x['F'], []);
                        $t->true($r[0] < 500 && $r[0] >= 400, "$m verifications/{id}: HTTP {$r[0]}");
                    }
                    $r = $t->call($s, 'POST', Q27_BASE . '/verifications', []);
                    $t->true($r[0] < 500 && $r[0] >= 400, 'POST verifications: HTTP ' . $r[0]);
                });
            } finally {
                q27_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-9',
        'title' => 'permission List Archive: role List Archive saja 200 untuk kedua endpoint baru; role tanpa permission Archive 403 GE0114 tanpa data; tanpa token 401; superadmin 200',
        'run'   => function ($t) {
            $s = $t->session();
            q27_baseline($t);
            try {
                $x = q27_tree($t);
                $F = $x['F'];
                $calls = function () use ($t, $s, $F) {
                    return [
                        'GET verifications' => q27_verif($t, $s),
                        'GET verifications/{F}' => q27_verif($t, $s, $F),
                        'GET archives (list)' => q27_list($t, $s, ['pagination' => 5]),
                    ];
                };

                // role bawaan (3): 200
                foreach ($calls() as $label => $r) {
                    $t->status($r, 200, "role bawaan: $label");
                }

                // role 29: hanya List Archive -> 200
                q27_with_user($t, ['role' => Q27_ROLE_LIST_ONLY], function () use ($t, $calls) {
                    foreach ($calls() as $label => $r) {
                        $t->status($r, 200, "role List Archive saja: $label");
                        $t->true(isset($r[1]['result']), "role List Archive saja: $label memuat result");
                    }
                });

                // role 6: tanpa permission Archive -> 403 GE0114, tanpa data
                q27_with_user($t, ['role' => Q27_ROLE_NONE], function () use ($t, $calls) {
                    foreach ($calls() as $label => $r) {
                        q27_deny($t, $r, 403, 'GE0114', "role tanpa List Archive: $label");
                        $t->true(!isset($r[1]['result']) || empty($r[1]['result']), "role tanpa List Archive: $label tanpa data");
                        $body = json_encode($r[1]);
                        $t->true(strpos($body, 'verified') === false && strpos($body, 'document_verified') === false, "role tanpa List Archive: $label tanpa angka verifikasi di body");
                    }
                });

                // superadmin
                q27_with_user($t, ['role' => 1], function () use ($t, $calls) {
                    foreach ($calls() as $label => $r) {
                        $t->status($r, 200, "superadmin 1: $label");
                    }
                });

                // tanpa token
                foreach (['/verifications', '/verifications/' . $F] as $path) {
                    $r = $t->raw('GET', Q27_BASE . $path);
                    $t->eq($r[0], 401, "tanpa token $path: 401");
                }
                $t->eq($t->raw('GET', Q27_BASE . '/archives')[0], 401, 'tanpa token archives: 401 (kontrol)');
            } finally {
                q27_cleanup($t);
            }
        },
    ],
];
