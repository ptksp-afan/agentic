<?php
/**
 * ED-1250 - config + Updater + display setting (BE):
 *   AC-1   config documentArchive memuat filter verificationStatus tepat sesudah type (current & available), GET archives
 *          result.queries sama, baris cds documentArchive memuatnya; Updater dijalankan DI DALAM transaksi lalu di-rollback
 *          (membuktikan kodenya tanpa mengubah baris), terdaftar di config.php;
 *   AC-10  GET setting/display-columns/documentArchive: tanpa Opname Document tanpa entri filter (kolom tetap tanpa documentVerified),
 *          pemegang ada; customer / supplier tak berubah; documentArchiveOpnameHistory tanpa permission tetap 403 GE0114;
 *   AC-11  PUT documentArchive oleh role tanpa Opname Document: perubahan lain tersimpan, verificationStatus & documentVerified
 *          dikembalikan sesudah pendahulunya; pemegang menyembunyikan entri -> GET archives queries tanpa entri tetapi filter
 *          tetap berlaku bila key dikirim. Baris cds dipulihkan persis.
 * Preset QA50-ROLE-* (bukan role developer); baris cds disnapshot utuh dan dipulihkan persis (keadaan SESUDAH Updater).
 */
require_once __DIR__ . '/qa_lib.php';

$q50_norm = function ($v) use (&$q50_norm) {
    if (is_array($v)) {
        foreach ($v as $k => $x) {
            $v[$k] = $q50_norm($x);
        }
        if (array_keys($v) !== range(0, count($v) - 1)) {
            ksort($v);
        }
    }

    return $v;
};

$q50_cfg = function ($t) {
    $path = $t->conf('BE_DIR') . '/Modules/V5/Config/displayColumn/documentArchive.php';

    return $t->probe(function () use ($path) {
        return include $path;
    });
};

return [

    [
        'id'    => 'AC-1',
        'title' => 'config documentArchive + GET archives result.queries + baris cds memuat verificationStatus tepat sesudah type (title en/id, select, endpoint null, tepat 2 opsi); Updater (dijalankan dalam transaksi + rollback) menghasilkan baris = config; terdaftar di config.php',
        'run'   => function ($t) use ($q50_norm, $q50_cfg) {
            $s = $t->session();
            q50_baseline($t);
            $want = [
                'title'      => ['en' => 'Verification Status', 'id' => 'Status Verifikasi'],
                'data_index' => 'verificationStatus',
                'type'       => 'select',
                'endpoint'   => null,
                'options'    => [
                    ['en' => 'Verified', 'id' => 'Terverifikasi', 'value' => 'VERIFIED'],
                    ['en' => 'Unverified', 'id' => 'Belum terverifikasi', 'value' => 'UNVERIFIED'],
                ],
            ];
            try {
                // ---- config (sumber Updater)
                $cfg = $q50_cfg($t);
                foreach (['current_queries', 'available_queries'] as $list) {
                    $seq = q50_seq($cfg[$list]);
                    $iT = array_search('type', $seq, true);
                    $iF = array_search('verificationStatus', $seq, true);
                    $t->true($iT !== false && $iF === $iT + 1, "config $list: verificationStatus tepat sesudah type (" . implode(',', $seq) . ')');
                    $t->eq($seq, array_values(array_unique($seq)), "config $list: tanpa ganda");
                    $t->eq($q50_norm($cfg[$list][$iF]), $q50_norm($want), "config $list: bentuk entri (title, select, endpoint null, 2 opsi)");
                    $t->true(!array_key_exists('initial_value', $cfg[$list][$iF]) && !array_key_exists('required', $cfg[$list][$iF]), "config $list: tanpa initial_value / required");
                }

                // ---- GET archives (pemegang): result.queries
                foreach (['smr', 'bypass'] as $mode) {
                    q50_with_user($t, q50_opts($mode), function () use ($t, $s, $want, $q50_norm, $mode) {
                        $r = q50_get($t, $s, ['pagination' => 3]);
                        $t->status($r, 200, "[$mode] GET archives");
                        $q = $r[1]['result']['queries'];
                        $seq = q50_seq($q);
                        $iT = array_search('type', $seq, true);
                        $t->true($iT !== false && ($seq[$iT + 1] ?? null) === 'verificationStatus', "[$mode] result.queries: verificationStatus tepat sesudah type (" . implode(',', $seq) . ')');
                        $t->eq(count(array_keys($seq, 'verificationStatus', true)), 1, "[$mode] result.queries: tepat satu entri");
                        $t->eq($q50_norm($q[$iT + 1]), $q50_norm($want), "[$mode] result.queries: entri = kontrak §1 (en/id, select, endpoint null, 2 opsi)");
                        $t->eq(count($q[$iT + 1]['options']), 2, "[$mode] tepat 2 opsi");
                        $t->eq(array_column($q[$iT + 1]['options'], 'value'), ['VERIFIED', 'UNVERIFIED'], "[$mode] nilai opsi");
                    });
                }

                // ---- baris cds (keadaan sesudah Updater): current & available = config
                $row = q50_cds_row($t);
                $t->eq(count($row), 1, 'tepat 1 baris cds documentArchive');
                foreach (['current_queries', 'available_queries', 'current_columns', 'available_columns'] as $list) {
                    $db = json_decode($row[0][$list], true);
                    $t->eq($q50_norm($db), $q50_norm($cfg[$list]), "baris cds $list = config (pilihan lain tidak berubah)");
                }
                $t->true(in_array('documentVerified', q50_seq(json_decode($row[0]['current_columns'], true)), true) && in_array('documentVerified', q50_seq(json_decode($row[0]['available_columns'], true)), true), 'baris cds: documentVerified (ED-1027) tetap di current & available columns');
                $fp = q50_sum($t, 'column_display_settings', q50_cols($t, 'column_display_settings'), "WHERE module_name = 'documentArchive'");

                // ---- Updater: jalankan di dalam transaksi, baca hasil, rollback
                $updater = $t->conf('BE_DIR') . '/Modules/UpdateVersion/Updaters/2026_10_24_21_24_06_AddVerificationStatusFilterArchive.php';
                $t->true(is_file($updater), 'berkas Updater ada');
                $made = $t->probe(function () use ($updater) {
                    $conn = \Illuminate\Support\Facades\DB::connection();
                    $conn->beginTransaction();
                    try {
                        $up = require $updater;
                        $up->run();
                        $now = (array) $conn->table('column_display_settings')->where('module_name', 'documentArchive')->first();
                        $n = $conn->table('column_display_settings')->where('module_name', 'documentArchive')->count();

                        return ['row' => $now, 'n' => $n];
                    } finally {
                        $conn->rollBack();
                    }
                });
                $t->eq($made['n'], 1, 'Updater: tepat 1 baris documentArchive sesudah dijalankan');
                foreach (['current_queries', 'available_queries', 'current_columns', 'available_columns'] as $list) {
                    $t->eq($q50_norm(json_decode($made['row'][$list], true)), $q50_norm($cfg[$list]), "Updater: baris baru $list = config");
                }
                $t->eq(q50_sum($t, 'column_display_settings', q50_cols($t, 'column_display_settings'), "WHERE module_name = 'documentArchive'"), $fp, 'Updater dalam transaksi di-rollback: baris cds tidak berubah');

                // ---- terdaftar di config.php
                $reg = $t->probe(function () use ($t) {
                    return include $t->conf('BE_DIR') . '/Modules/UpdateVersion/Updaters/config.php';
                });
                $hit = array_keys($reg, 'Modules\\UpdateVersion\\Updaters\\2026_10_24_21_24_06_AddVerificationStatusFilterArchive', true);
                $t->eq(count($hit), 1, 'Updater terdaftar tepat sekali di Updaters/config.php');
                $t->eq((int) max(array_keys($reg)), (int) $hit[0], 'kunci Updater ED-1250 = kunci terbesar (urutan jalan)');
                $t->eq((int) $hit[0], strtotime('2026-10-24 21:24:06 Asia/Jakarta'), 'kunci epoch cocok dengan nama berkas (WIB, seperti Updater lain)');
                $prev = $reg[array_keys($reg)[count($reg) - 2]];
                $t->true(strpos($prev, '2026_10_09_09_29_06') !== false, 'Updater sebelumnya di config.php tidak bergeser: ' . $prev);
            } finally {
                q50_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-10',
        'title' => 'GET display-columns/documentArchive: tanpa Opname Document tanpa entri filter di current/available queries (kolom tanpa documentVerified), pemegang ada; customer/supplier tak berubah; documentArchiveOpnameHistory tanpa permission 403 GE0114; index tanpa modul itu',
        'run'   => function ($t) {
            $s = $t->session();
            q50_baseline($t);
            $stored = q50_cds_row($t);
            $t->eq(count($stored), 1, 'prasyarat: 1 baris documentArchive');
            $sq = q50_seq(json_decode($stored[0]['current_queries'], true));
            $saq = q50_seq(json_decode($stored[0]['available_queries'], true));
            $sc = q50_seq(json_decode($stored[0]['current_columns'], true));
            $t->true(in_array('verificationStatus', $sq, true) && in_array('verificationStatus', $saq, true) && in_array('documentVerified', $sc, true), 'prasyarat: baris tersimpan memuat verificationStatus (current & available) dan documentVerified');
            $gCust = $gSupp = null;
            try {
                // ---------- tanpa Opname Document (preset LIST)
                q50_with_user($t, q50_opts('smr', 'LIST'), function () use ($t, $s, $sq, $saq, $sc, &$gCust, &$gSupp) {
                    $g = $t->call($s, 'GET', Q50_DS . 'documentArchive');
                    $t->status($g, 200, 'tanpa OD: GET documentArchive');
                    $res = $g[1]['result'];
                    $t->eq(q50_seq($res['current_queries']), array_values(array_diff($sq, ['verificationStatus'])), 'tanpa OD: current_queries = tersimpan tanpa verificationStatus (urutan sama)');
                    $t->eq(q50_seq($res['available_queries']), array_values(array_diff($saq, ['verificationStatus'])), 'tanpa OD: available_queries = tersimpan tanpa verificationStatus (urutan sama)');
                    $t->eq(q50_seq($res['current_columns']), array_values(array_diff($sc, ['documentVerified'])), 'tanpa OD: current_columns tanpa documentVerified (ED-1027 tetap)');
                    $body = json_encode($g[1]);
                    $t->true(strpos($body, 'verificationStatus') === false && strpos($body, 'Verification Status') === false && strpos($body, 'Status Verifikasi') === false && strpos($body, 'documentVerified') === false, 'tanpa OD: body GET tanpa jejak filter / kolom verifikasi');
                    $gCust = $t->call($s, 'GET', Q50_DS . 'customer');
                    $t->status($gCust, 200, 'tanpa OD: GET customer');
                    $gSupp = $t->call($s, 'GET', Q50_DS . 'supplier');
                    $t->status($gSupp, 200, 'tanpa OD: GET supplier');

                    $h = $t->call($s, 'GET', Q50_DS . 'documentArchiveOpnameHistory');
                    $t->status($h, 403, 'tanpa OD: documentArchiveOpnameHistory');
                    $t->eq(q50_code($h), 'GE0114', 'tanpa OD: documentArchiveOpnameHistory code GE0114');

                    $idx = q50_index_modules($t, $s);
                    $t->true(in_array('documentArchive', $idx, true), 'tanpa OD: index memuat documentArchive (' . count($idx) . ' modul)');
                    $t->true(!in_array('documentArchiveOpnameHistory', $idx, true), 'tanpa OD: index tidak memuat documentArchiveOpnameHistory (entri seluruh modul)');
                });

                // ---------- pemegang (preset OD)
                q50_with_user($t, q50_opts('smr', 'OD'), function () use ($t, $s, $sq, $saq, $sc, $gCust, $gSupp) {
                    $g = $t->call($s, 'GET', Q50_DS . 'documentArchive');
                    $t->status($g, 200, 'dengan OD: GET documentArchive');
                    $res = $g[1]['result'];
                    $t->eq(q50_seq($res['current_queries']), $sq, 'dengan OD: current_queries = tersimpan (verificationStatus sesudah type)');
                    $t->eq(q50_seq($res['available_queries']), $saq, 'dengan OD: available_queries = tersimpan');
                    $t->eq(q50_seq($res['current_columns']), $sc, 'dengan OD: current_columns memuat documentVerified');
                    $t->true(in_array('verificationStatus', q50_seq($res['current_queries']), true) && in_array('verificationStatus', q50_seq($res['available_queries']), true), 'dengan OD: entri ada di current & available queries');
                    $gc = $t->call($s, 'GET', Q50_DS . 'customer');
                    $t->status($gc, 200, 'dengan OD: GET customer');
                    $t->eq(json_encode($gc[1]['result']), json_encode($gCust[1]['result']), 'customer: GET identik dengan / tanpa OD');
                    $gs = $t->call($s, 'GET', Q50_DS . 'supplier');
                    $t->eq(json_encode($gs[1]['result']), json_encode($gSupp[1]['result']), 'supplier: GET identik dengan / tanpa OD');
                    $h = $t->call($s, 'GET', Q50_DS . 'documentArchiveOpnameHistory');
                    $t->status($h, 200, 'dengan OD: documentArchiveOpnameHistory 200');
                    $idx = q50_index_modules($t, $s);
                    $t->true(in_array('documentArchiveOpnameHistory', $idx, true), 'dengan OD: index memuat documentArchiveOpnameHistory');
                });

                // ---------- QA_USER2 sungguhan tanpa OD
                q50_with_u2_preset($t, 'LIST', function () use ($t) {
                    $s2 = q50_u2_session($t);
                    $g = $t->call($s2, 'GET', Q50_DS . 'documentArchive');
                    $t->status($g, 200, 'QA_USER2 tanpa OD: GET documentArchive');
                    $t->true(strpos(json_encode($g[1]), 'verificationStatus') === false, 'QA_USER2 tanpa OD: tanpa jejak verificationStatus');
                    $h = $t->call($s2, 'GET', Q50_DS . 'documentArchiveOpnameHistory');
                    $t->status($h, 403, 'QA_USER2 tanpa OD: documentArchiveOpnameHistory');
                    $t->eq(q50_code($h), 'GE0114', 'QA_USER2 tanpa OD: GE0114');
                });

                // ---------- langkah unpark sesudah ED-1251 (spec Tambahan Gate 1): modul pembanding documentArchiveOpnameResult
                $src = file_get_contents($t->conf('BE_DIR') . '/Modules/V5/Http/Services/Setup/Setting/ColumnDisplaySettingService.php');
                if (strpos($src, 'documentArchiveOpnameResult') === false) {
                    $t->note('langkah unpark-sesudah-ED-1251 (documentArchiveOpnameResult 403 GE0114 untuk non-pemegang) TIDAK BERLAKU: ED-1251 belum di-commit, modul itu belum ada di PERMISSION_FILTERS');
                }
            } finally {
                q50_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-11',
        'title' => 'PUT documentArchive oleh role tanpa Opname Document (urutan query diubah, updatedAt hanya di available, item verificationStatus palsu di body): 200, perubahan lain tersimpan, verificationStatus & documentVerified dikembalikan dari baris tersimpan sesudah pendahulunya; pemegang menyembunyikan entri: GET archives queries tanpa entri tapi filter tetap berlaku; baris dipulihkan persis',
        'run'   => function ($t) use ($q50_norm) {
            $s = $t->session();
            q50_baseline($t);
            $snap = q50_cds_snapshot($t);
            $t->eq(count($snap), 1, 'prasyarat: 1 baris documentArchive');
            $fp = q50_sum($t, 'column_display_settings', q50_cols($t, 'column_display_settings'));
            $storedCq = json_decode($snap[0]['current_queries'], true);
            $storedAq = json_decode($snap[0]['available_queries'], true);
            $storedCc = json_decode($snap[0]['current_columns'], true);
            $storedAc = json_decode($snap[0]['available_columns'], true);
            $item = function ($list, $di) {
                foreach ($list as $x) {
                    if ($x['data_index'] === $di) {
                        return $x;
                    }
                }

                return null;
            };
            $fv = $item($storedCq, 'verificationStatus');
            $t->true($fv !== null && $item($storedAq, 'verificationStatus') !== null, 'prasyarat: entri ada di current & available tersimpan');
            $first = null;
            try {
                $F = q50_folder($t, 'F11');
                $dv = q50_doc($t, 'dv11', ['parent' => $F, 'verified' => 1], 6);
                $du = q50_doc($t, 'du11', ['parent' => $F], 6);

                q50_with_user($t, q50_opts('smr', 'LIST'), function () use ($t, $s, $item, $fv, $storedCq, $storedAq, $storedCc, $storedAc, $q50_norm) {
                    $g = $t->call($s, 'GET', Q50_DS . 'documentArchive');
                    $res = $g[1]['result'];
                    $cq = $res['current_queries'];   // name, type, updatedAt, showRelatedTransaction
                    $byIdx = function ($list, $di) use ($item) {
                        return $item($list, $di);
                    };
                    // current baru: type, name, showRelatedTransaction (updatedAt hanya di available) + item palsu verificationStatus
                    $fake = $fv;
                    $fake['title'] = ['en' => 'HACKED', 'id' => 'HACKED'];
                    $newCq = [$byIdx($cq, 'type'), $byIdx($cq, 'name'), $byIdx($cq, 'showRelatedTransaction'), $fake];
                    $newAq = array_reverse($res['available_queries']);
                    $newCc = $res['current_columns'];
                    $newAc = array_reverse($res['available_columns']);
                    $p = $t->call($s, 'PUT', Q50_DS . 'documentArchive', [
                        'current_columns' => $newCc, 'available_columns' => $newAc, 'current_queries' => $newCq, 'available_queries' => $newAq,
                    ]);
                    $t->status($p, 200, 'tanpa OD: PUT documentArchive');
                    $row = q50_cds_row($t);
                    $t->eq(count($row), 1, 'sesudah PUT: tetap 1 baris');
                    $dbCq = json_decode($row[0]['current_queries'], true);
                    $dbAq = json_decode($row[0]['available_queries'], true);
                    $dbCc = json_decode($row[0]['current_columns'], true);
                    $dbAc = json_decode($row[0]['available_columns'], true);
                    $t->eq(q50_seq($dbCq), ['type', 'verificationStatus', 'name', 'showRelatedTransaction'], 'DB current_queries: urutan baru, updatedAt disembunyikan, verificationStatus dikembalikan sesudah type');
                    $expAq = ['updatedBy', 'showRelatedTransaction', 'updatedAt', 'type', 'verificationStatus', 'name'];
                    $t->eq(q50_seq($dbAq), $expAq, 'DB available_queries: urutan terbalik tersimpan, verificationStatus sesudah type');
                    $t->eq($q50_norm($item($dbCq, 'verificationStatus')), $q50_norm($fv), 'objek verificationStatus current = objek tersimpan semula (bukan kiriman palsu)');
                    $t->true(strpos($row[0]['current_queries'], 'HACKED') === false && strpos($row[0]['available_queries'], 'HACKED') === false, 'kiriman verificationStatus palsu diabaikan');
                    $t->eq(substr_count($row[0]['current_queries'], '"verificationStatus"'), 1, 'verificationStatus tepat satu di current_queries (tanpa ganda)');
                    // columns: documentVerified tetap (ED-1027 AC-24), urutan baru tersimpan
                    $t->true(in_array('documentVerified', q50_seq($dbCc), true) && in_array('documentVerified', q50_seq($dbAc), true), 'DB columns: documentVerified tetap di current & available');
                    $t->eq(array_values(array_diff(q50_seq($dbAc), ['documentVerified'])), array_values(array_diff(q50_seq($newAc), ['documentVerified'])), 'DB available_columns: urutan terbalik tersimpan');
                    // GET lagi (tanpa OD): tanpa entri
                    $g2 = $t->call($s, 'GET', Q50_DS . 'documentArchive');
                    $t->eq(q50_seq($g2[1]['result']['current_queries']), ['type', 'name', 'showRelatedTransaction'], 'tanpa OD: GET sesudah PUT = urutan baru tanpa entri');
                    $t->true(strpos(json_encode($g2[1]), 'verificationStatus') === false, 'tanpa OD: GET sesudah PUT tanpa jejak verificationStatus');
                });

                // ---------- pemegang menyembunyikan entri dari current (tetap di available)
                $holder = null;
                q50_with_user($t, q50_opts('smr', 'OD'), function () use ($t, $s, $F, $dv, $du, &$holder) {
                    $g = $t->call($s, 'GET', Q50_DS . 'documentArchive');
                    $t->status($g, 200, 'dengan OD: GET');
                    $res = $g[1]['result'];
                    $t->eq(q50_seq($res['current_queries']), ['type', 'verificationStatus', 'name', 'showRelatedTransaction'], 'dengan OD: entri tampil sesudah type pada urutan baru');
                    $cq = array_values(array_filter($res['current_queries'], function ($q) {
                        return $q['data_index'] !== 'verificationStatus';
                    }));
                    $p = $t->call($s, 'PUT', Q50_DS . 'documentArchive', [
                        'current_columns' => $res['current_columns'], 'available_columns' => $res['available_columns'],
                        'current_queries' => $cq, 'available_queries' => $res['available_queries'],
                    ]);
                    $t->status($p, 200, 'dengan OD: PUT menyembunyikan verificationStatus dari current');
                    $row = q50_cds_row($t);
                    $t->true(strpos($row[0]['current_queries'], 'verificationStatus') === false, 'DB: entri hilang dari current_queries (pemegang boleh)');
                    $t->true(strpos($row[0]['available_queries'], 'verificationStatus') !== false, 'DB: entri tetap di available_queries');
                    $r = q50_get($t, $s, ['id_archive' => $F, 'pagination' => 50]);
                    $t->status($r, 200, 'GET archives pemegang');
                    $t->true(!in_array('verificationStatus', q50_seq($r[1]['result']['queries']), true), 'GET archives: queries tanpa entri sesudah disembunyikan');
                    // filter tetap berlaku bila key dikirim walau entri disembunyikan (kontrak §1)
                    $rv = q50_get($t, $s, ['id_archive' => $F, 'search' => [Q50_FILTER => 'VERIFIED']]);
                    $t->status($rv, 200, 'filter VERIFIED walau entri disembunyikan');
                    $t->eq(array_column($rv[1]['result']['data'], 'id_archive'), [$dv], 'filter tetap berlaku: hanya dokumen verified F');
                    $ru = q50_get($t, $s, ['id_archive' => $F, 'search' => [Q50_FILTER => 'UNVERIFIED']]);
                    $t->eq(array_column($ru[1]['result']['data'], 'id_archive'), [$du], 'filter tetap berlaku: hanya dokumen unverified F');
                });

                // ---------- tanpa OD lagi: entri yang disembunyikan pemegang tidak "dimunculkan" oleh PUT pemanggil ini
                q50_with_user($t, q50_opts('smr', 'LIST'), function () use ($t, $s) {
                    $g = $t->call($s, 'GET', Q50_DS . 'documentArchive');
                    $res = $g[1]['result'];
                    $p = $t->call($s, 'PUT', Q50_DS . 'documentArchive', [
                        'current_columns' => $res['current_columns'], 'available_columns' => $res['available_columns'],
                        'current_queries' => array_reverse($res['current_queries']), 'available_queries' => $res['available_queries'],
                    ]);
                    $t->status($p, 200, 'tanpa OD: PUT sesudah entri disembunyikan pemegang');
                    $row = q50_cds_row($t);
                    $t->true(strpos($row[0]['current_queries'], 'verificationStatus') === false, 'verificationStatus tetap tidak ada di current_queries (tadinya tidak ada di sana)');
                    $t->true(strpos($row[0]['available_queries'], 'verificationStatus') !== false, 'verificationStatus tetap ada di available_queries');
                    $t->true(strpos($row[0]['current_columns'], 'documentVerified') !== false && strpos($row[0]['available_columns'], 'documentVerified') !== false, 'documentVerified tetap di current & available columns');
                });

                // ---------- pemegang menampilkan lagi
                q50_with_user($t, q50_opts('smr', 'OD'), function () use ($t, $s, $fv) {
                    $g = $t->call($s, 'GET', Q50_DS . 'documentArchive');
                    $res = $g[1]['result'];
                    $cq = $res['current_queries'];
                    $cq[] = $fv;
                    $p = $t->call($s, 'PUT', Q50_DS . 'documentArchive', [
                        'current_columns' => $res['current_columns'], 'available_columns' => $res['available_columns'],
                        'current_queries' => $cq, 'available_queries' => $res['available_queries'],
                    ]);
                    $t->status($p, 200, 'dengan OD: PUT menampilkan lagi');
                    $r = q50_get($t, $s, ['pagination' => 2]);
                    $t->true(in_array('verificationStatus', q50_seq($r[1]['result']['queries']), true), 'GET archives: entri kembali di queries');
                });
            } finally {
                q50_cds_restore($t, $snap);
                q50_cleanup($t);
            }
            $t->eq(q50_sum($t, 'column_display_settings', q50_cols($t, 'column_display_settings')), $fp, 'seluruh tabel column_display_settings sama persis dengan sebelum AC');
            $row = q50_cds_row($t);
            $t->eq(json_encode($row), json_encode($snap), 'baris documentArchive dipulihkan persis (id, isi, updated_at)');
        },
    ],
];
