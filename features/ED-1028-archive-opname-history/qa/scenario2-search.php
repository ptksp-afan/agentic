<?php
/**
 * ED-1028 - search & filter & sort GET document-archive/opnames: AC-4 (query: username, nama folder level 0-2 snapshot, besar/kecil
 * huruf, K-3 i untuk user2, %/_ harfiah, teks non-latin1), AC-5 (confirmedAt rentang inklusif / satu ujung / tidak valid 422),
 * AC-6 (createdBy, idArchives mencakup salah satu + subfolder, AND, 422), AC-7 (sorts tiap kolom naik/turun, scope diabaikan).
 */
require_once __DIR__ . '/qa_hist.php';

/** Sesi tambahan R: root + OTH + PRIV + JOGR oleh qa28dave (PRIV tanpa View user2, JOGR lokasi JOG) pada hari -2 12:00:00. */
$q28_add_r = function ($t, $s, array $x, array &$ids) {
    $ids['R'] = q28_run($t, $s, null, q28_sel([$x['OTH'], $x['PRIV'], $x['JOGR']]), [q28_n($t, $x['dO1']), q28_n($t, $x['dPR'])]);
    q28_set_opname($t, $ids['R'], ['confirmed_at' => date('Y-m-d', strtotime('-2 days')) . ' 12:00:00']);
    q28_set_owner($t, $ids['R'], 'qa28dave');
};

/** Oracle pencarian: sesi terlihat $u yang usernamenya atau nama (snapshot) folder-nya (folder terlihat saja bila bukan bypass) mengandung $text. */
$q28_name_oracle = function ($t, array $u, $text) {
    $o = q28_oracle($t, $u);
    $visible = $o['visible'];
    $out = [];
    $sessions = q28_oracle_sessions($t, $u);
    $rows = $t->db()->table('archive_opname_folders')->whereIn('id_archive_opname', $sessions ?: ['-'])->get(['id_archive_opname', 'id_archive', 'name']);
    $byOp = [];
    foreach ($rows as $r) {
        $byOp[$r->id_archive_opname][] = $r;
    }
    $creator = [];
    foreach ($t->db()->table('archive_opnames')->whereIn('id_archive_opname', $sessions ?: ['-'])->get(['id_archive_opname', 'created_by']) as $r) {
        $creator[$r->id_archive_opname] = (string) $r->created_by;
    }
    foreach ($sessions as $id) {
        $hit = stripos($creator[$id], $text) !== false;
        foreach ($byOp[$id] ?? [] as $f) {
            if (($u['bypass'] || ($f->id_archive !== null && isset($visible[$f->id_archive]))) && stripos((string) $f->name, $text) !== false) {
                $hit = true;
            }
        }
        if ($hit) {
            $out[] = $id;
        }
    }

    return $out;
};

/** Urutkan id sesi default: confirmed_at turun, id turun (dari DB). */
$q28_default_order = function ($t, array $ids) {
    $rows = $t->db()->table('archive_opnames')->whereIn('id_archive_opname', $ids ?: ['-'])->get(['id_archive_opname', 'confirmed_at'])->all();
    usort($rows, function ($a, $b) {
        return strcmp($b->confirmed_at, $a->confirmed_at) ?: strcmp($b->id_archive_opname, $a->id_archive_opname);
    });

    return array_map(function ($r) {
        return $r->id_archive_opname;
    }, $rows);
};

return [

    [
        'id'    => 'AC-4',
        'title' => 'search {"query"}: username (sebagian, tanpa beda huruf), nama folder level 0/1/2 (snapshot), user2 hanya folder yang boleh dilihat (K-3 i), %/_ harfiah, trim, teks non-latin1/aneh tanpa 500',
        'run'   => function ($t) use ($q28_add_r, $q28_name_oracle, $q28_default_order) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s, $q28_add_r, $q28_name_oracle, $q28_default_order) {
                $x = null;
                $ids = null;
                q28_with_user($t, ['role' => 1], function () use ($t, $s, $q28_add_r, &$x, &$ids) {
                    $x = q28_tree($t);
                    $ids = q28_hfix($t, $s, $x);
                    $q28_add_r($t, $s, $x, $ids);
                });
                $N = function ($k) use ($t, $x) {
                    return q28_n($t, $x[$k]);
                };
                $keys = function ($r) use ($ids) {
                    return q28_hkeys(q28_hids($r), $ids);
                };
                $q = function ($s, $text, array $extra = []) use ($t) {
                    return q28_hist($t, $s, $extra + ['pagination' => 100, 'search' => ['query' => $text]]);
                };

                // --- superadmin (role 1): semua sesi, semua nama folder
                q28_with_user($t, ['role' => 1], function () use ($t, $s, $q, $keys, $N, $ids, $x) {
                    $r = $q($s, 'alice');
                    $t->status($r, 200, 'alice');
                    $t->eq($keys($r), ['C', 'A'], 'query "alice": sebagian username -> C, A');
                    $t->eq($keys($q($s, 'ALICE')), ['C', 'A'], 'query "ALICE": tanpa beda huruf besar');
                    $t->eq($keys($q($s, 'a28ali')), ['C', 'A'], 'query "a28ali": potongan tengah username');
                    $t->eq($keys($q($s, '  alice  ')), ['C', 'A'], 'query dengan spasi di tepi: di-trim');
                    $t->eq($keys($q($s, 'qa28bob')), ['E', 'B'], 'query "qa28bob" -> E, B');
                    $t->eq($keys($q($s, 'qa28dave')), ['R'], 'query "qa28dave" -> R');
                    // nama folder level 0 / 1 / 2 (snapshot)
                    $t->eq($keys($q($s, $N('F'))), ['A'], 'nama F (level 0 sesi A) -> A');
                    $t->eq($keys($q($s, $N('S1'))), ['A'], 'nama S1 (level 1 sesi A) -> A');
                    $t->eq($keys($q($s, $N('S1a'))), ['Y', 'A'], 'nama S1a (level 2 sesi A, level 0 sesi Y) -> Y, A');
                    $t->eq($keys($q($s, strtoupper($N('S1a')))), ['Y', 'A'], 'nama S1a huruf besar sama');
                    $t->eq($keys($q($s, strtolower($N('S1a')))), ['Y', 'A'], 'nama S1a huruf kecil sama');
                    $t->eq($keys($q($s, $N('PRIV'))), ['R'], 'superadmin: nama PRIV -> R');
                    $t->eq($keys($q($s, $N('PRc'))), ['R'], 'superadmin: nama PRc (level 2 R) -> R');
                    $t->eq($keys($q($s, $N('OTH'))), ['R', 'Z', 'C'], 'nama OTH -> R, Z, C');
                    $t->eq($keys($q($s, $N('SJ'))), ['D'], 'nama SJ -> D');
                    $t->eq(count($q($s, 'QA28-')[1]['result']['data']), 8, 'query "QA28-" cocok dengan nama folder semua 8 sesi');
                    $t->eq(count($q($s, 'zzz-tidak-ada-' . uniqid())[1]['result']['data']), 0, 'query tanpa hasil: data kosong');
                    $t->eq(count($q($s, '')[1]['result']['data']), 8, 'query kosong = tanpa filter');
                    $t->eq(count($q($s, '   ')[1]['result']['data']), 8, 'query spasi saja = tanpa filter');
                    // wildcard LIKE harfiah
                    $t->eq($keys($q($s, 'qa28_')), ['Y'], 'query "qa28_": "_" harfiah (hanya qa28_under, bukan wildcard)');
                    $t->eq($keys($q($s, '%')), [], 'query "%": harfiah, tanpa hasil');
                    $t->eq($keys($q($s, '\\')), [], 'query "\\": harfiah, tanpa hasil');
                    // teks aneh: tidak 500
                    foreach (['日本語', "emoji \xF0\x9F\x98\x80", "' OR 1=1 --", '"; DROP TABLE archive_opnames; --', str_repeat('a', 3000), "\xC3\xA9", "a\0b"] as $odd) {
                        $o = $q($s, $odd);
                        q28_no500($t, $o, 'query aneh ' . substr(json_encode($odd), 0, 30));
                        $t->true(in_array($o[0], [200, 422], true), 'query aneh ' . substr(json_encode($odd), 0, 30) . ': 200/422 (' . $o[0] . ')');
                        if ($o[0] === 200) {
                            $t->eq(count($o[1]['result']['data']), 0, 'query aneh ' . substr(json_encode($odd), 0, 30) . ': tanpa hasil');
                        }
                    }
                    // snapshot: nama folder diubah sesudah Confirm -> yang dicari nama saat sesi
                    $old = $N('S2');
                    q28_set_archive($t, $x['S2'], ['name' => 'QA28-renamed-' . uniqid()]);
                    $t->eq($keys($q($s, $old)), ['B'], 'folder S2 diganti nama: nama LAMA (snapshot) masih cocok -> B');
                    $t->eq($keys($q($s, 'QA28-renamed-')), [], 'nama BARU tidak cocok (nama = snapshot sesi)');
                    q28_set_archive($t, $x['S2'], ['name' => $old]);
                    // search = array (bukan JSON string) / bukan objek: 422, tanpa 500
                    foreach (['search[query]=alice', 'search=abc', 'search=123', 'search="x"', 'search=[', 'search=true', 'search=%7B%22query%22%3A%22%FF%FE%22%7D'] as $raw) {
                        $o = $t->call($s, 'GET', Q28_BASE . '/opnames?' . $raw);
                        q28_no500($t, $o, "search mentah $raw");
                        $t->status($o, 422, "search mentah $raw -> 422");
                    }
                    foreach (['search=null', 'search=', 'search={}', 'search=[]'] as $raw) {
                        $o = $t->call($s, 'GET', Q28_BASE . '/opnames?' . $raw);
                        $t->status($o, 200, "search mentah $raw -> 200 tanpa filter");
                    }
                });

                // --- user2 sungguhan (SMR, tanpa Opname Document): nama folder yang tak boleh dilihat tidak mencocokkan
                $s2 = q28_u2_session($t);
                $u2 = q28_uu($t, 'user2');
                $t->eq(q28_hkeys(q28_hids($q($s2, 'qa28')), $ids), q28_hkeys($q28_default_order($t, $q28_name_oracle($t, $u2, 'qa28')), $ids), 'user2: "qa28" = oracle');
                $r = $q($s2, $N('PRIV'));
                $t->status($r, 200, 'user2: PRIV');
                $t->eq($keys($r), [], 'user2: nama PRIV (tanpa View) tidak mencocokkan R');
                $t->eq($keys($q($s2, $N('PRc'))), [], 'user2: nama PRc (turunan PRIV) tidak mencocokkan');
                $t->eq($keys($q($s2, $N('JOGR'))), [], 'user2: nama JOGR (lokasi JOG) tidak mencocokkan');
                $t->eq($keys($q($s2, $N('OTH'))), ['R', 'Z', 'C'], 'user2: nama OTH (boleh dilihat) -> R, Z, C');
                $t->eq($keys($q($s2, $N('S1a'))), ['Y', 'A'], 'user2: nama S1a -> Y, A');
                $t->eq($keys($q($s2, $N('SJ'))), [], 'user2: SJ tak terlihat -> kosong (sesi D juga tak terlihat)');
                $t->eq($keys($q($s2, 'qa28bob')), ['B'], 'user2: username qa28bob -> hanya B (E tak terlihat)');
                $t->eq($keys($q($s2, 'qa28carol')), [], 'user2: username qa28carol (sesi D tak terlihat) -> kosong');
                $t->eq($keys($q($s2, 'qa28dave')), ['R'], 'user2: R (root) terlihat, username cocok');
                // superadmin vs user2 untuk nama yang sama
                q28_with_user($t, ['role' => 1], function () use ($t, $s, $q, $keys, $N) {
                    $t->eq($keys($q($s, $N('PRIV'))), ['R'], 'kontrol: superadmin masih mencocokkan PRIV -> R');
                });
                // oracle penuh beberapa teks untuk user2 dan user3
                foreach (['qa28', 'alice', 'S1', 'OTH', 'F-', 'P-', 'SP', 'PR', 'JOG', '-'] as $text) {
                    $t->eq($keys($q($s2, $text)), q28_hkeys($q28_default_order($t, $q28_name_oracle($t, $u2, $text)), $ids), "user2: oracle nama/username '$text'");
                    $t->eq($keys($q($s, $text)), q28_hkeys($q28_default_order($t, $q28_name_oracle($t, q28_uu($t, 'user3'), $text)), $ids), "user3: oracle nama/username '$text'");
                }
                $t->call($s2, 'GET', 'api/v5/auth/log-out');
            });
        },
    ],

    [
        'id'    => 'AC-5',
        'title' => 'search confirmedAt: [awal, akhir] inklusif, hanya awal, hanya akhir, kosong/null; tanggal tidak valid / bentuk salah -> 422 (bukan 500)',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s) {
                $x = null;
                $ids = null;
                q28_with_user($t, ['role' => 1], function () use ($t, $s, &$x, &$ids) {
                    $x = q28_tree($t);
                    $ids = q28_hfix($t, $s, $x);
                });
                $T = q28_times();
                $plus = function ($ts, $sec) {
                    return date('Y-m-d H:i:s', strtotime($ts) + $sec);
                };
                q28_with_user($t, ['role' => 1], function () use ($t, $s, $ids, $T, $plus) {
                    $keys = function ($r) use ($ids) {
                        return q28_hkeys(q28_hids($r), $ids);
                    };
                    $f = function ($range, array $extra = []) use ($t, $s) {
                        return q28_hist($t, $s, $extra + ['pagination' => 100, 'search' => ['confirmedAt' => $range]]);
                    };

                    $t->eq($keys($f([$T['B'], $T['D']])), ['D', 'C', 'B'], '[B, D]: kedua ujung inklusif -> D, C, B');
                    $t->eq($keys($f([$T['C'], $T['C']])), ['C'], '[C, C]: satu detik tepat -> C');
                    $t->eq($keys($f([$plus($T['B'], 1), $plus($T['D'], -1)])), ['C'], '[B+1s, D-1s]: ujung eksklusif di luar -> hanya C');
                    $t->eq($keys($f([$plus($T['B'], -1), $T['B']])), ['B'], '[B-1s, B] -> B (akhir inklusif)');
                    $t->eq($keys($f([$T['B'], $plus($T['B'], 1)])), ['B'], '[B, B+1s] -> B (awal inklusif)');
                    $t->eq($keys($f([$T['Y'], null])), ['Z', 'Y'], 'hanya awal [Y, null] -> Z, Y');
                    $t->eq($keys($f([$T['Y'], ''])), ['Z', 'Y'], 'hanya awal [Y, ""] -> Z, Y');
                    $t->eq($keys($f([null, $T['B']])), ['B', 'A'], 'hanya akhir [null, B] -> B, A');
                    $t->eq($keys($f(['', $T['B']])), ['B', 'A'], 'hanya akhir ["", B] -> B, A');
                    $t->eq($keys($f([null, null])), ['Z', 'Y', 'E', 'D', 'C', 'B', 'A'], '[null, null]: tanpa filter (7 sesi)');
                    $t->eq($keys($f(['', ''])), ['Z', 'Y', 'E', 'D', 'C', 'B', 'A'], '["", ""]: tanpa filter');
                    $t->eq($keys($f([$T['D'], $T['B']])), [], 'awal > akhir: kosong, bukan error');
                    $t->eq($keys($f(['2000-01-01 00:00:00', '2000-12-31 23:59:59'])), [], 'rentang tanpa sesi: kosong');
                    $t->eq($keys($f([$T['A'], '2099-01-01 00:00:00'])), ['Z', 'Y', 'E', 'D', 'C', 'B', 'A'], 'akhir jauh di depan');
                    // gabungan dengan sort & pagination
                    $r = $f([$T['B'], $T['D']], ['pagination' => 2, 'page' => 2]);
                    $t->eq($keys($r), ['B'], 'rentang + pagination halaman 2 -> B');
                    $t->eq($r[1]['result']['total'], 3, 'rentang: total 3');

                    // tidak valid -> 422
                    $bad = [
                        'tanggal tidak ada (bulan 13)' => ['2026-13-45 00:00:00', null],
                        'tanggal saja tanpa jam' => ['2026-01-01', null],
                        'teks' => ['abc', null],
                        'akhir teks' => [null, 'kemarin'],
                        'format ISO T' => ['2026-01-01T10:00:00', null],
                        'angka' => [1, 2],
                        'satu elemen' => [$T['A']],
                        'tiga elemen' => [$T['A'], $T['B'], $T['C']],
                        'string bukan array' => $T['A'],
                        'objek' => ['from' => $T['A'], 'to' => $T['B'], 'x' => 1],
                    ];
                    foreach ($bad as $label => $range) {
                        $o = q28_hist($t, $s, ['search' => ['confirmedAt' => $range]]);
                        q28_no500($t, $o, "confirmedAt $label");
                        $t->status($o, 422, "confirmedAt $label -> 422");
                        $t->true(isset($o[1]['errors']) || isset($o[1]['message']), "confirmedAt $label: ada pesan validasi");
                    }
                    $o = q28_hist($t, $s, ['search' => ['confirmedAt' => ['2026-13-45 00:00:00', null]]]);
                    $t->true(isset($o[1]['errors']['confirmedAt.0']), 'kunci error = confirmedAt.0: ' . json_encode(array_keys($o[1]['errors'] ?? [])));
                    $o = q28_hist($t, $s, ['search' => ['confirmedAt' => [null, 'abc']]]);
                    $t->true(isset($o[1]['errors']['confirmedAt.1']), 'kunci error = confirmedAt.1: ' . json_encode(array_keys($o[1]['errors'] ?? [])));
                    $o = q28_hist($t, $s, ['search' => ['confirmedAt' => 'x']]);
                    $t->true(isset($o[1]['errors']['confirmedAt']), 'kunci error = confirmedAt (bukan array): ' . json_encode(array_keys($o[1]['errors'] ?? [])));
                    // sesi tak berubah setelah request salah
                    $t->eq(count(q28_hids(q28_hist($t, $s, ['pagination' => 100]))), 7, 'setelah 422: list normal 7 sesi');
                });
            });
        },
    ],

    [
        'id'    => 'AC-6',
        'title' => 'search createdBy (satu username), idArchives (mencakup SALAH SATU folder + subfolder K-3 ii), gabungan dengan query/confirmedAt = AND, nilai tak valid 422',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s) {
                $x = null;
                $ids = null;
                q28_with_user($t, ['role' => 1], function () use ($t, $s, &$x, &$ids) {
                    $x = q28_tree($t);
                    $ids = q28_hfix($t, $s, $x);
                });
                $T = q28_times();
                $keys = function ($r) use ($ids) {
                    return q28_hkeys(q28_hids($r), $ids);
                };
                $f = function ($s, array $search, array $extra = []) use ($t) {
                    return q28_hist($t, $s, $extra + ['pagination' => 100, 'search' => $search]);
                };

                q28_with_user($t, ['role' => 1], function () use ($t, $s, $x, $ids, $T, $keys, $f) {
                    $uname = (string) q28_uname($t);
                    $t->eq($keys($f($s, ['createdBy' => 'qa28alice'])), ['C', 'A'], 'createdBy qa28alice -> C, A');
                    $t->eq($keys($f($s, ['createdBy' => 'qa28bob'])), ['E', 'B'], 'createdBy qa28bob -> E, B');
                    $t->eq($keys($f($s, ['createdBy' => $uname])), ['Z'], 'createdBy pelaku QA -> Z');
                    $t->eq($keys($f($s, ['createdBy' => 'qa28_under'])), ['Y'], 'createdBy qa28_under -> Y');
                    $t->eq($keys($f($s, ['createdBy' => 'qa28'])), [], 'createdBy = persis (bukan mengandung): "qa28" -> kosong');
                    $t->eq($keys($f($s, ['createdBy' => 'qa28_'])), [], 'createdBy "qa28_" harfiah/persis -> kosong');
                    $t->eq($keys($f($s, ['createdBy' => 'tidak-ada-' . uniqid()])), [], 'createdBy tak dikenal -> kosong');
                    $t->eq(count($keys($f($s, ['createdBy' => '']))), 7, 'createdBy "" = tanpa filter');
                    $t->eq(count($keys($f($s, ['createdBy' => null]))), 7, 'createdBy null = tanpa filter');
                    foreach (["日本語", "' OR 1=1 --"] as $odd) {
                        $o = $f($s, ['createdBy' => $odd]);
                        q28_no500($t, $o, 'createdBy aneh');
                        $t->status($o, 200, 'createdBy aneh: 200');
                        $t->eq(count($o[1]['result']['data']), 0, 'createdBy aneh: kosong');
                    }

                    // idArchives: mencakup SALAH SATU folder; subfolder ikut (K-3 ii)
                    $t->eq($keys($f($s, ['idArchives' => [$x['S2'], $x['OTH']]])), ['Z', 'C', 'B'], 'idArchives [S2, OTH] -> Z, C, B (ATAU)');
                    $t->eq($keys($f($s, ['idArchives' => [$x['F']]])), ['Y', 'E', 'D', 'B', 'A'], 'idArchives [F] (superadmin): A (F), B (S2), Y (S1a), D (SJ), E (SP) = semua sesi pada subtree F');
                    $t->eq($keys($f($s, ['idArchives' => [$x['S1']]])), ['Y', 'A'], 'idArchives [S1] -> A (S1), Y (S1a di bawah S1)');
                    $t->eq($keys($f($s, ['idArchives' => [$x['S1a']]])), ['Y', 'A'], 'idArchives [S1a] -> A (level 2), Y');
                    $t->eq($keys($f($s, ['idArchives' => [$x['P']]])), ['Y', 'E', 'D', 'B', 'A'], 'idArchives [P] (induk F) -> subtree P');
                    $t->eq($keys($f($s, ['idArchives' => [$x['OTH']]])), ['Z', 'C'], 'idArchives [OTH] -> Z, C');
                    $t->eq($keys($f($s, ['idArchives' => [$x['S2'], $x['S2']]])), ['B'], 'idArchives dengan id ganda -> B');
                    $t->eq(count($keys($f($s, ['idArchives' => []]))), 7, 'idArchives [] = tanpa filter');
                    $t->eq(count($keys($f($s, ['idArchives' => null]))), 7, 'idArchives null = tanpa filter');
                    $t->eq($keys($f($s, ['idArchives' => ['999999999999999999999']])), [], 'idArchives id tak ada -> kosong (200)');
                    $t->eq($keys($f($s, ['idArchives' => [$x['dF1']]])), [], 'idArchives id dokumen -> kosong (200)');
                    $t->eq($keys($f($s, ['idArchives' => [$x['SX']]])), [], 'idArchives folder nonaktif -> kosong');
                    $t->eq($keys($f($s, ['idArchives' => [$x['SX'], $x['S2']]])), ['B'], 'idArchives [SX nonaktif, S2] -> B');

                    // gabungan = AND
                    $t->eq($keys($f($s, ['query' => 'alice', 'idArchives' => [$x['F']]])), ['A'], 'query alice AND [F] -> A');
                    $t->eq($keys($f($s, ['query' => 'qa28bob', 'idArchives' => [$x['F']]])), ['E', 'B'], 'query bob AND [F] -> E, B');
                    $t->eq($keys($f($s, ['query' => 'alice', 'idArchives' => [$x['S2']]])), [], 'query alice AND [S2] -> kosong');
                    $t->eq($keys($f($s, ['createdBy' => 'qa28bob', 'idArchives' => [$x['S2']]])), ['B'], 'createdBy bob AND [S2] -> B');
                    $t->eq($keys($f($s, ['createdBy' => 'qa28bob', 'idArchives' => [$x['OTH']]])), [], 'createdBy bob AND [OTH] -> kosong');
                    $t->eq($keys($f($s, ['createdBy' => 'qa28alice', 'confirmedAt' => [$T['B'], null]])), ['C'], 'createdBy alice AND confirmedAt >= B -> C');
                    $t->eq($keys($f($s, ['query' => 'QA28-', 'createdBy' => 'qa28bob', 'idArchives' => [$x['F']], 'confirmedAt' => [$T['A'], $T['B']]])), ['B'], 'empat filter sekaligus -> B');
                    $r = $f($s, ['idArchives' => [$x['F']]], ['pagination' => 2, 'page' => 2]);
                    $t->eq($keys($r), ['D', 'B'], 'idArchives + pagination hal 2');
                    $t->eq($r[1]['result']['total'], 5, 'idArchives: total 5');

                    // tidak valid: 422
                    $bad = [
                        'idArchives string' => ['idArchives' => 'abc'],
                        'idArchives angka' => ['idArchives' => 5],
                        'idArchives isi angka' => ['idArchives' => [1, 2]],
                        'idArchives isi array' => ['idArchives' => [['x']]],
                        'createdBy array' => ['createdBy' => ['a', 'b']],
                        'createdBy angka' => ['createdBy' => 123],
                    ];
                    foreach ($bad as $label => $search) {
                        $o = $f($s, $search);
                        q28_no500($t, $o, $label);
                        $t->status($o, 422, "$label -> 422");
                    }
                    $o = $f($s, ['idArchives' => 'abc']);
                    $t->true(isset($o[1]['errors']['idArchives']), 'kunci error = idArchives: ' . json_encode(array_keys($o[1]['errors'] ?? [])));
                    $o = $f($s, ['createdBy' => ['a']]);
                    $t->true(isset($o[1]['errors']['createdBy']), 'kunci error = createdBy: ' . json_encode(array_keys($o[1]['errors'] ?? [])));
                    // [""] (elemen kosong): tidak boleh 500
                    $o = $f($s, ['idArchives' => ['']]);
                    q28_no500($t, $o, 'idArchives [""]');
                    $t->note('idArchives [""] -> HTTP ' . $o[0] . ', ' . count($o[1]['result']['data'] ?? []) . ' baris');
                });

                // user2: id di luar scope tidak mencocokkan apa pun; subtree hanya yang terlihat
                $s2 = q28_u2_session($t);
                $t->eq($keys($f($s2, ['idArchives' => [$x['SJ']]])), [], 'user2: [SJ] (lokasi JOG) -> kosong');
                $t->eq($keys($f($s2, ['idArchives' => [$x['SJ'], $x['S2']]])), ['B'], 'user2: [SJ, S2] -> B saja');
                $t->eq($keys($f($s2, ['idArchives' => [$x['SP']]])), [], 'user2: [SP] (tanpa View) -> kosong');
                $t->eq($keys($f($s2, ['idArchives' => [$x['F']]])), ['Y', 'B', 'A'], 'user2: [F] -> Y, B, A (D & E di subtree tak terlihat)');
                $o = q28_oracle_sessions($t, q28_uu($t, 'user2'), [$x['F']]);
                $t->eq(array_map(function ($i) use ($ids) {
                    return array_search($i, $ids, true);
                }, array_values(array_intersect(array_values($ids), $o))), ['A', 'B', 'Y'], 'oracle user2 [F] = A, B, Y');
                $t->call($s2, 'GET', 'api/v5/auth/log-out');
            });
        },
    ],

    [
        'id'    => 'AC-7',
        'title' => 'sorts: tiap kolom sortable naik/turun benar (bentuk JSON-string FE dan objek), pemecah seri confirmed_at turun, sort scope/kolom asing diabaikan tanpa 500, kolom tak aktif tetap bisa di-sort, banyak sort',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s) {
                $x = null;
                $ids = null;
                q28_with_user($t, ['role' => 1], function () use ($t, $s, &$x, &$ids) {
                    $x = q28_tree($t);
                    $ids = q28_hfix($t, $s, $x);
                    // angka berbeda per sesi (nilai uji; hanya untuk urutan): tiap kolom permutasi lain, ada kembar di notFound untuk uji pemecah seri
                    $vals = [
                        'total_documents' => ['A' => 10, 'B' => 70, 'C' => 30, 'D' => 50, 'E' => 20, 'Y' => 60, 'Z' => 40],
                        'verified_count' => ['A' => 7, 'B' => 1, 'C' => 6, 'D' => 2, 'E' => 5, 'Y' => 3, 'Z' => 4],
                        'not_found_count' => ['A' => 2, 'B' => 2, 'C' => 9, 'D' => 0, 'E' => 5, 'Y' => 5, 'Z' => 1],
                        'invalid_count' => ['A' => 11, 'B' => 14, 'C' => 12, 'D' => 10, 'E' => 13, 'Y' => 16, 'Z' => 15],
                        'scanned_count' => ['A' => 100, 'B' => 300, 'C' => 200, 'D' => 700, 'E' => 600, 'Y' => 500, 'Z' => 400],
                        'unscanned_count' => ['A' => 3, 'B' => 1, 'C' => 2, 'D' => 7, 'E' => 6, 'Y' => 4, 'Z' => 5],
                    ];
                    // pemilik Y tanpa garis bawah: urutan "_" vs huruf mengikuti kolasi MySQL, bukan PHP (bukan hal yang diuji di sini)
                    q28_set_owner($t, $ids['Y'], 'qa28zed');
                    foreach ($vals as $col => $per) {
                        foreach ($per as $k => $v) {
                            q28_set_opname($t, $ids[$k], [$col => $v]);
                        }
                    }
                });

                $db = [];
                foreach ($ids as $k => $id) {
                    if (in_array($k, ['H', 'K'], true)) {
                        continue;
                    }
                    $db[$k] = q28_opname($t, $id);
                }
                // urutan harapan dari nilai DB: kolom, arah, tie-break confirmed_at turun lalu id turun
                $expected = function ($col, $dir) use ($db) {
                    $keys = array_keys($db);
                    usort($keys, function ($a, $b) use ($db, $col, $dir) {
                        $va = $db[$a][$col];
                        $vb = $db[$b][$col];
                        $c = is_numeric($va) && is_numeric($vb) ? $va <=> $vb : strcasecmp($va, $vb);
                        if ($c !== 0) {
                            return $dir === 'asc' ? $c : -$c;
                        }

                        return strcmp($db[$b]['confirmed_at'], $db[$a]['confirmed_at']) ?: strcmp($db[$b]['id_archive_opname'], $db[$a]['id_archive_opname']);
                    });

                    return $keys;
                };

                q28_with_user($t, ['role' => 1], function () use ($t, $s, $ids, $expected) {
                    $keys = function ($r) use ($ids) {
                        return q28_hkeys(q28_hids($r), $ids);
                    };
                    $cols = [
                        'confirmedAt' => 'confirmed_at', 'createdBy' => 'created_by', 'totalDocuments' => 'total_documents',
                        'verifiedCount' => 'verified_count', 'notFoundCount' => 'not_found_count', 'invalidCount' => 'invalid_count',
                        'scannedCount' => 'scanned_count', 'unscannedCount' => 'unscanned_count',
                    ];
                    foreach ($cols as $by => $col) {
                        foreach (['asc', 'desc'] as $dir) {
                            // bentuk FE: sorts[]={"sortBy":..,"sortType":..}
                            $r = q28_hist($t, $s, ['pagination' => 100, 'sorts' => [json_encode(['sortBy' => $by, 'sortType' => $dir])]]);
                            $t->status($r, 200, "sort $by $dir");
                            $t->eq($keys($r), $expected($col, $dir), "sort $by $dir (JSON-string)");
                            // bentuk objek: sorts[0][sortBy]=..
                            $r = q28_hist($t, $s, ['pagination' => 100, 'sorts' => [['sortBy' => $by, 'sortType' => $dir]]]);
                            $t->eq($keys($r), $expected($col, $dir), "sort $by $dir (objek)");
                        }
                    }
                    // snake_case juga diterima, sortType besar/kecil
                    $r = q28_hist($t, $s, ['pagination' => 100, 'sorts' => [json_encode(['sortBy' => 'total_documents', 'sortType' => 'ASC'])]]);
                    $t->eq($keys($r), $expected('total_documents', 'asc'), 'sort total_documents ASC (snake, huruf besar)');
                    // sortType tak dikenal -> desc
                    $r = q28_hist($t, $s, ['pagination' => 100, 'sorts' => [json_encode(['sortBy' => 'totalDocuments', 'sortType' => 'sideways'])]]);
                    $t->eq($keys($r), $expected('total_documents', 'desc'), 'sortType tak dikenal -> desc');
                    $r = q28_hist($t, $s, ['pagination' => 100, 'sorts' => [json_encode(['sortBy' => 'totalDocuments'])]]);
                    $t->eq($keys($r), $expected('total_documents', 'desc'), 'tanpa sortType -> desc');

                    // pemecah seri pada nilai kembar (not_found: A=B=2, E=Y=5)
                    $r = q28_hist($t, $s, ['pagination' => 100, 'sorts' => [json_encode(['sortBy' => 'notFoundCount', 'sortType' => 'asc'])]]);
                    $t->eq($keys($r), ['D', 'Z', 'B', 'A', 'Y', 'E', 'C'], 'notFoundCount asc dengan kembar: B sebelum A (confirmed_at turun), Y sebelum E');

                    // sort scope & kolom asing diabaikan -> sama dengan urutan bawaan, tanpa 500
                    $default = $keys(q28_hist($t, $s, ['pagination' => 100]));
                    $t->eq($default, ['Z', 'Y', 'E', 'D', 'C', 'B', 'A'], 'urutan bawaan: confirmed_at turun');
                    foreach ([
                        'scope' => ['sortBy' => 'scope', 'sortType' => 'asc'],
                        'kolom asing' => ['sortBy' => 'foo', 'sortType' => 'asc'],
                        'relasi' => ['sortBy' => 'folder.name', 'sortType' => 'asc'],
                        'snake asing' => ['sortBy' => 'scope_name', 'sortType' => 'asc'],
                        'tanpa sortBy' => ['sortType' => 'asc'],
                        'sortBy array' => ['sortBy' => ['a', 'b'], 'sortType' => 'asc'],
                        'sortBy kosong' => ['sortBy' => '', 'sortType' => 'asc'],
                        'injeksi' => ['sortBy' => 'confirmed_at; DROP TABLE archive_opnames', 'sortType' => 'asc'],
                        'sortType injeksi' => ['sortBy' => 'confirmedAt', 'sortType' => 'asc; DROP TABLE x'],
                    ] as $label => $sort) {
                        foreach ([[$sort], [json_encode($sort)]] as $form => $sorts) {
                            $r = q28_hist($t, $s, ['pagination' => 100, 'sorts' => $sorts]);
                            q28_no500($t, $r, "sort $label bentuk $form");
                            $t->status($r, 200, "sort $label bentuk $form: 200");
                            if ($label !== 'sortType injeksi') {
                                $t->eq($keys($r), $default, "sort $label bentuk $form: diabaikan -> urutan bawaan");
                            }
                        }
                    }
                    foreach (['sorts=abc', 'sorts[0]=abc', 'sorts[0]=%7B', 'sorts[0][sortBy][]=a', 'sorts=1', 'sorts[0]=null', 'sorts[0]=123'] as $raw) {
                        $r = $t->call($s, 'GET', Q28_BASE . '/opnames?pagination=100&' . $raw);
                        q28_no500($t, $r, "sorts mentah $raw");
                        $t->status($r, 200, "sorts mentah $raw: 200");
                        $t->eq($keys($r), $default, "sorts mentah $raw: urutan bawaan");
                    }

                    // banyak sort: verifiedCount asc lalu totalDocuments desc (tak ada kembar -> urut verified), sort pertama kembar
                    $r = q28_hist($t, $s, ['pagination' => 100, 'sorts' => [
                        json_encode(['sortBy' => 'notFoundCount', 'sortType' => 'asc']),
                        json_encode(['sortBy' => 'totalDocuments', 'sortType' => 'desc']),
                    ]]);
                    $t->eq($keys($r), ['D', 'Z', 'B', 'A', 'Y', 'E', 'C'], 'dua sort: notFound asc lalu total desc (B total 70 > A 10; Y 60 > E 20)');
                    // sort + pagination konsisten
                    $p1 = $keys(q28_hist($t, $s, ['pagination' => 3, 'page' => 1, 'sorts' => [json_encode(['sortBy' => 'totalDocuments', 'sortType' => 'desc'])]]));
                    $p2 = $keys(q28_hist($t, $s, ['pagination' => 3, 'page' => 2, 'sorts' => [json_encode(['sortBy' => 'totalDocuments', 'sortType' => 'desc'])]]));
                    $p3 = $keys(q28_hist($t, $s, ['pagination' => 3, 'page' => 3, 'sorts' => [json_encode(['sortBy' => 'totalDocuments', 'sortType' => 'desc'])]]));
                    $t->eq(array_merge($p1, $p2, $p3), $expected('total_documents', 'desc'), 'sort total desc lintas halaman (3+3+1) = urutan penuh');
                    // sort + filter
                    $r = q28_hist($t, $s, ['pagination' => 100, 'search' => ['query' => 'alice'], 'sorts' => [json_encode(['sortBy' => 'totalDocuments', 'sortType' => 'asc'])]]);
                    $t->eq($keys($r), ['A', 'C'], 'sort total asc + query alice -> A (10), C (30)');

                    // kontrak §1: parameter "sort" (tunggal) - dicatat bila diabaikan
                    $r = q28_hist($t, $s, ['pagination' => 100, 'sort' => [json_encode(['sortBy' => 'totalDocuments', 'sortType' => 'asc'])]]);
                    q28_no500($t, $r, 'param sort tunggal');
                    $t->note('param "sort" (tunggal, kontrak §1) -> ' . ($keys($r) === $expected('total_documents', 'asc') ? 'DIPAKAI' : 'DIABAIKAN (urutan bawaan; hanya "sorts" berlaku)'));
                    $r = q28_hist($t, $s, ['pagination' => 100, 'sort' => ['sortBy' => 'totalDocuments', 'sortType' => 'asc']]);
                    $t->note('param "sort" objek -> ' . ($keys($r) === $expected('total_documents', 'asc') ? 'DIPAKAI' : 'DIABAIKAN'));
                });
            });
        },
    ],
];
