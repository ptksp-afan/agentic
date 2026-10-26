<?php
/**
 * ED-1250 - config + Updater + display setting (BE):
 *   AC-1   (rev R-1, review 2026-10-26) config documentArchive: current_queries = name, type, updatedAt, showRelatedTransaction,
 *          verificationStatus (TERAKHIR); available_queries = urutan sama + updatedBy paling akhir (tidak ada di current); GET archives
 *          result.queries dan GET display-columns/documentArchive (pemegang) mengikuti urutan itu, baris cds documentArchive sama; Updater
 *          dijalankan DI DALAM transaksi lalu di-rollback (membuktikan kodenya tanpa mengubah baris), terdaftar di config.php;
 *   AC-10  GET setting/display-columns/documentArchive: tanpa Opname Document tanpa entri filter (kolom tetap tanpa documentVerified),
 *          pemegang ada; customer / supplier tak berubah; documentArchiveOpnameHistory tanpa permission tetap 403 GE0114;
 *   AC-11  PUT documentArchive oleh role tanpa Opname Document: perubahan lain tersimpan, verificationStatus & documentVerified
 *          dikembalikan sesudah pendahulunya (urutan R-1: pendahulu verificationStatus = showRelatedTransaction, bukan type; respons PUT =
 *          index tersaring, R-2); pemegang menyembunyikan entri -> GET archives queries tanpa entri tetapi filter tetap berlaku bila key
 *          dikirim. Baris cds dipulihkan persis.
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
        'title' => 'R-1: config documentArchive + GET archives result.queries + GET display-columns + baris cds: current = name,type,updatedAt,showRelatedTransaction,verificationStatus (terakhir), available = urutan sama + updatedBy terakhir (bukan di current); entri verificationStatus (title en/id, select, endpoint null, tepat 2 opsi); Updater (dalam transaksi + rollback) menghasilkan baris = config; terdaftar di config.php',
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
                $t->eq(q50_seq($cfg['current_queries']), Q50_R1_CURRENT, 'R-1 config current_queries: name, type, updatedAt, showRelatedTransaction, verificationStatus (terakhir)');
                $t->eq(q50_seq($cfg['available_queries']), Q50_R1_AVAILABLE, 'R-1 config available_queries: urutan current + updatedBy paling akhir');
                $t->true(!in_array('updatedBy', q50_seq($cfg['current_queries']), true), 'R-1 config: updatedBy tidak ada di current_queries');
                foreach (['current_queries', 'available_queries'] as $list) {
                    $seq = q50_seq($cfg[$list]);
                    $iF = array_search('verificationStatus', $seq, true);
                    $t->true($iF !== false, "config $list: verificationStatus ada");
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
                        $iF = array_search('verificationStatus', $seq, true);
                        // R-1: urutan result.queries = urutan R-1; verificationStatus di indeks terakhir (sesudah showRelatedTransaction)
                        $t->eq($seq, Q50_R1_CURRENT, "[$mode] result.queries: name, type, updatedAt, showRelatedTransaction, verificationStatus (R-1)");
                        $t->eq($iF, count($seq) - 1, "[$mode] result.queries: verificationStatus di indeks terakhir (" . implode(',', $seq) . ')');
                        $t->eq($seq[$iF - 1] ?? null, 'showRelatedTransaction', "[$mode] result.queries: verificationStatus tepat sesudah showRelatedTransaction");
                        $t->true(!in_array('updatedBy', $seq, true), "[$mode] result.queries: updatedBy tidak ada (hanya di available)");
                        $t->eq(count(array_keys($seq, 'verificationStatus', true)), 1, "[$mode] result.queries: tepat satu entri");
                        $t->eq($q50_norm($q[$iF]), $q50_norm($want), "[$mode] result.queries: entri = kontrak §1 (en/id, select, endpoint null, 2 opsi)");
                        $t->eq(count($q[$iF]['options']), 2, "[$mode] tepat 2 opsi");
                        $t->eq(array_column($q[$iF]['options'], 'value'), ['VERIFIED', 'UNVERIFIED'], "[$mode] nilai opsi");
                        // data FE drawer Display Setting (AC-13 bagian BE): GET display-columns/documentArchive pemegang = urutan R-1 di current & available
                        $g = $t->call($s, 'GET', Q50_DS . 'documentArchive');
                        $t->status($g, 200, "[$mode] GET display-columns/documentArchive");
                        $t->eq(q50_seq($g[1]['result']['current_queries']), Q50_R1_CURRENT, "[$mode] display setting current_queries (Displayed Filters) = urutan R-1");
                        $t->eq(q50_seq($g[1]['result']['available_queries']), Q50_R1_AVAILABLE, "[$mode] display setting available_queries = urutan R-1 + updatedBy terakhir");
                    });
                }

                // ---- baris cds (keadaan sesudah Updater): current & available = config
                $row = q50_cds_row($t);
                $t->eq(count($row), 1, 'tepat 1 baris cds documentArchive');
                $t->eq(q50_seq(json_decode($row[0]['current_queries'], true)), Q50_R1_CURRENT, 'R-1 baris cds current_queries: urutan R-1, verificationStatus terakhir');
                $t->eq(q50_seq(json_decode($row[0]['available_queries'], true)), Q50_R1_AVAILABLE, 'R-1 baris cds available_queries: urutan R-1 + updatedBy terakhir, updatedBy tidak di current');
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
                $t->eq(q50_seq(json_decode($made['row']['current_queries'], true)), Q50_R1_CURRENT, 'R-1 Updater: current_queries urutan R-1 (verificationStatus terakhir)');
                $t->eq(q50_seq(json_decode($made['row']['available_queries'], true)), Q50_R1_AVAILABLE, 'R-1 Updater: available_queries urutan R-1 (updatedBy terakhir, bukan di current)');
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
            $t->eq($sq, Q50_R1_CURRENT, 'prasyarat (R-1): current_queries tersimpan = urutan R-1');
            $t->eq($saq, Q50_R1_AVAILABLE, 'prasyarat (R-1): available_queries tersimpan = urutan R-1 + updatedBy terakhir');
            $gCust = $gSupp = null;
            try {
                // ---------- tanpa Opname Document (preset LIST)
                q50_with_user($t, q50_opts('smr', 'LIST'), function () use ($t, $s, $sq, $saq, $sc, &$gCust, &$gSupp) {
                    $g = $t->call($s, 'GET', Q50_DS . 'documentArchive');
                    $t->status($g, 200, 'tanpa OD: GET documentArchive');
                    $res = $g[1]['result'];
                    $t->eq(q50_seq($res['current_queries']), array_values(array_diff($sq, ['verificationStatus'])), 'tanpa OD: current_queries = tersimpan tanpa verificationStatus (urutan sama)');
                    $t->eq(q50_seq($res['current_queries']), ['name', 'type', 'updatedAt', 'showRelatedTransaction'], 'tanpa OD (R-1): current_queries = name, type, updatedAt, showRelatedTransaction');
                    $t->eq(q50_seq($res['available_queries']), ['name', 'type', 'updatedAt', 'showRelatedTransaction', 'updatedBy'], 'tanpa OD (R-1): available_queries = urutan R-1 tanpa verificationStatus (updatedBy tetap paling akhir)');
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
                    $t->eq(q50_seq($res['current_queries']), $sq, 'dengan OD: current_queries = tersimpan (R-1: verificationStatus terakhir)');
                    $t->eq(q50_seq($res['current_queries']), Q50_R1_CURRENT, 'dengan OD: current_queries = urutan R-1 (name, type, updatedAt, showRelatedTransaction, verificationStatus)');
                    $t->eq(q50_seq($res['available_queries']), $saq, 'dengan OD: available_queries = tersimpan');
                    $t->eq(q50_seq($res['available_queries']), Q50_R1_AVAILABLE, 'dengan OD: available_queries = urutan R-1 + updatedBy terakhir (updatedBy tidak di current)');
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

                // ---------- langkah unpark sesudah ED-1251 (spec Tambahan Gate 1): modul pembanding documentArchiveOpnameResult.
                // ED-1251 sudah di-commit (fba6a5883) dan PERMISSION_FILTERS memuat OpnameExportService::MODULE: langkah BERLAKU sekarang.
                $src = file_get_contents($t->conf('BE_DIR') . '/Modules/V5/Http/Services/Setup/Setting/ColumnDisplaySettingService.php');
                $t->true(strpos($src, 'OpnameExportService::MODULE') !== false, 'ED-1251 ada di PERMISSION_FILTERS (langkah unpark berlaku)');
                $resRow = $t->db()->table('column_display_settings')->where('module_name', 'documentArchiveOpnameResult')->count();
                $crcBefore = q50_sum($t, 'column_display_settings', q50_cols($t, 'column_display_settings'));
                q50_with_user($t, q50_opts('smr', 'LIST'), function () use ($t, $s, $resRow, $crcBefore) {
                    $g = $t->call($s, 'GET', Q50_DS . 'documentArchiveOpnameResult');
                    $t->status($g, 403, 'tanpa OD: GET documentArchiveOpnameResult');
                    $t->eq(q50_code($g), 'GE0114', 'tanpa OD: documentArchiveOpnameResult GET code GE0114');
                    $p = $t->call($s, 'PUT', Q50_DS . 'documentArchiveOpnameResult', ['current_columns' => [['data_index' => 'zzz']]]);
                    $t->status($p, 403, 'tanpa OD: PUT documentArchiveOpnameResult');
                    $t->eq(q50_code($p), 'GE0114', 'tanpa OD: documentArchiveOpnameResult PUT code GE0114');
                    $idx = q50_index_modules($t, $s);
                    $t->true(!in_array('documentArchiveOpnameResult', $idx, true) && !in_array('documentArchiveOpnameHistory', $idx, true), 'tanpa OD: index tidak memuat kedua modul seluruh-modul (history, result)');
                    $t->eq(count($idx), $t->db()->table('column_display_settings')->count() - 1 - $resRow, 'tanpa OD: jumlah modul di index = semua baris - history - result (R0)');
                    $t->eq(q50_sum($t, 'column_display_settings', q50_cols($t, 'column_display_settings')), $crcBefore, 'tabel cds tidak berubah oleh GET/PUT yang ditolak 403');
                });
                $t->note('langkah unpark-sesudah-ED-1251 dijalankan: documentArchiveOpnameResult 403 GE0114 (GET, PUT) untuk non-pemegang, tidak ada di index R0 (baris result ' . ($resRow ? 'ADA' : 'tidak ada') . ' di DB)');
            } finally {
                q50_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-11',
        'title' => 'R-1/R-2: PUT documentArchive oleh role tanpa Opname Document (default tak diubah; lalu urutan query diubah, updatedAt hanya di available, item verificationStatus palsu): 200, respons PUT = index tersaring, perubahan lain tersimpan, verificationStatus & documentVerified dikembalikan dari baris tersimpan sesudah pendahulunya (R-1: showRelatedTransaction, bukan type; pendahulu disembunyikan -> indeks semula); pemegang menyembunyikan entri: GET archives queries tanpa entri tapi filter tetap berlaku; baris dipulihkan persis',
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
            $t->eq(q50_seq($storedCq), Q50_R1_CURRENT, 'prasyarat (R-1): current_queries tersimpan = urutan R-1');
            $t->eq(q50_seq($storedAq), Q50_R1_AVAILABLE, 'prasyarat (R-1): available_queries tersimpan = urutan R-1 + updatedBy terakhir');
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
            $hiddenModules = ['documentArchiveOpnameHistory', 'documentArchiveOpnameResult'];
            $totalRows = $t->db()->table('column_display_settings')->count();
            // respons PUT (index tersaring, R-2) untuk non-pemegang / pemegang
            $checkResp = function ($p, $label, $holder) use ($t, $totalRows, $hiddenModules) {
                $t->status($p, 200, "$label: PUT 200");
                $row = q50_resp_row($p[1]);
                $t->true($row !== null, "$label: respons PUT memuat baris documentArchive (halaman 1)");
                if ($row === null) {
                    return;
                }
                $has = function ($list, $di) use ($row) {
                    return in_array($di, q50_row_seq($row, $list), true);
                };
                if ($holder) {
                    $t->true($has('current_columns', 'documentVerified') && $has('available_columns', 'documentVerified') && $has('available_queries', 'verificationStatus'), "$label: respons PUT pemegang memuat documentVerified & verificationStatus (tidak disaring)");
                } else {
                    $t->true(!$has('current_columns', 'documentVerified') && !$has('available_columns', 'documentVerified') && !$has('current_queries', 'verificationStatus') && !$has('available_queries', 'verificationStatus'), "$label: respons PUT non-pemegang tanpa documentVerified / verificationStatus di current_* dan available_* (R-2)");
                    $t->true(strpos(json_encode($row), 'verificationStatus') === false && strpos(json_encode($row), 'documentVerified') === false, "$label: baris documentArchive di respons PUT tanpa jejak kedua entri");
                    $t->eq($p[1]['result']['total'], $totalRows - count($hiddenModules), "$label: respons PUT total = semua baris - 2 modul seluruh-modul (saringan item tidak membuang baris)");
                }
                $mods = array_column($p[1]['result']['data'], 'module_name');
                foreach ($hiddenModules as $m) {
                    if ($holder) {
                        continue;
                    }
                    $t->true(!in_array($m, $mods, true), "$label: respons PUT tanpa baris $m");
                }
            };
            $first = null;
            try {
                $F = q50_folder($t, 'F11');
                $dv = q50_doc($t, 'dv11', ['parent' => $F, 'verified' => 1], 6);
                $du = q50_doc($t, 'du11', ['parent' => $F], 6);

                // ---------- non-pemegang: PUT tanpa mengubah urutan default (alur FE "simpan" biasa) -> urutan R-1 tidak bergeser
                q50_with_user($t, q50_opts('smr', 'LIST'), function () use ($t, $s, $checkResp, $storedCq, $storedAq, $storedCc, $storedAc) {
                    $g = $t->call($s, 'GET', Q50_DS . 'documentArchive');
                    $res = $g[1]['result'];
                    $p = $t->call($s, 'PUT', Q50_DS . 'documentArchive', [
                        'current_columns' => $res['current_columns'], 'available_columns' => $res['available_columns'],
                        'current_queries' => $res['current_queries'], 'available_queries' => $res['available_queries'],
                    ]);
                    $checkResp($p, 'tanpa OD, PUT default', false);
                    $row = q50_cds_row($t);
                    $t->eq(q50_seq(json_decode($row[0]['current_queries'], true)), Q50_R1_CURRENT, 'DB sesudah PUT default non-pemegang: current_queries tetap urutan R-1 (verificationStatus terakhir)');
                    $t->eq(q50_seq(json_decode($row[0]['available_queries'], true)), Q50_R1_AVAILABLE, 'DB sesudah PUT default non-pemegang: available_queries tetap urutan R-1 (verificationStatus sebelum updatedBy)');
                    $t->eq(q50_seq(json_decode($row[0]['current_columns'], true)), q50_seq($storedCc), 'DB sesudah PUT default: current_columns tak bergeser (documentVerified tetap)');
                    $t->eq(q50_seq(json_decode($row[0]['available_columns'], true)), q50_seq($storedAc), 'DB sesudah PUT default: available_columns tak bergeser');
                });

                // ---------- non-pemegang: urutan diubah, updatedAt hanya di available, item palsu
                q50_with_user($t, q50_opts('smr', 'LIST'), function () use ($t, $s, $item, $fv, $storedCq, $storedAq, $storedCc, $storedAc, $q50_norm, $checkResp) {
                    $g = $t->call($s, 'GET', Q50_DS . 'documentArchive');
                    $res = $g[1]['result'];
                    $cq = $res['current_queries'];   // name, type, updatedAt, showRelatedTransaction
                    $t->eq(q50_seq($cq), ['name', 'type', 'updatedAt', 'showRelatedTransaction'], 'tanpa OD: GET awal = urutan R-1 tanpa verificationStatus');
                    // current baru: type, name, showRelatedTransaction (updatedAt hanya di available) + item palsu verificationStatus di akhir
                    $fake = $fv;
                    $fake['title'] = ['en' => 'HACKED', 'id' => 'HACKED'];
                    $newCq = [$item($cq, 'type'), $item($cq, 'name'), $item($cq, 'showRelatedTransaction'), $fake];
                    $newAq = array_reverse($res['available_queries']);
                    $newCc = $res['current_columns'];
                    $newAc = array_reverse($res['available_columns']);
                    $p = $t->call($s, 'PUT', Q50_DS . 'documentArchive', [
                        'current_columns' => $newCc, 'available_columns' => $newAc, 'current_queries' => $newCq, 'available_queries' => $newAq,
                    ]);
                    $checkResp($p, 'tanpa OD, PUT urutan baru', false);
                    $row = q50_cds_row($t);
                    $t->eq(count($row), 1, 'sesudah PUT: tetap 1 baris');
                    $dbCq = json_decode($row[0]['current_queries'], true);
                    $dbAq = json_decode($row[0]['available_queries'], true);
                    $dbCc = json_decode($row[0]['current_columns'], true);
                    $dbAc = json_decode($row[0]['available_columns'], true);
                    // R-1: pendahulu verificationStatus di baris tersimpan = showRelatedTransaction (bukan type) -> disisipkan sesudahnya
                    $t->eq(q50_seq($dbCq), ['type', 'name', 'showRelatedTransaction', 'verificationStatus'], 'DB current_queries (R-1): urutan baru, updatedAt disembunyikan, verificationStatus dikembalikan sesudah showRelatedTransaction');
                    $t->eq(q50_seq($dbCq), q50_restore_expect(q50_seq($storedCq), ['type', 'name', 'showRelatedTransaction'], ['verificationStatus']), 'DB current_queries = oracle aturan sisip kontrak §2');
                    $t->eq(q50_seq($dbAq), ['updatedBy', 'showRelatedTransaction', 'verificationStatus', 'updatedAt', 'type', 'name'], 'DB available_queries (R-1): urutan terbalik tersimpan, verificationStatus sesudah showRelatedTransaction');
                    $t->eq(q50_seq($dbAq), q50_restore_expect(q50_seq($storedAq), ['updatedBy', 'showRelatedTransaction', 'updatedAt', 'type', 'name'], ['verificationStatus']), 'DB available_queries = oracle aturan sisip kontrak §2');
                    $t->eq($q50_norm($item($dbCq, 'verificationStatus')), $q50_norm($fv), 'objek verificationStatus current = objek tersimpan semula (bukan kiriman palsu)');
                    $t->eq($q50_norm($item($dbAq, 'verificationStatus')), $q50_norm($fv), 'objek verificationStatus available = objek tersimpan semula');
                    $t->true(strpos($row[0]['current_queries'], 'HACKED') === false && strpos($row[0]['available_queries'], 'HACKED') === false, 'kiriman verificationStatus palsu diabaikan');
                    $t->eq(substr_count($row[0]['current_queries'], '"verificationStatus"'), 1, 'verificationStatus tepat satu di current_queries (tanpa ganda)');
                    // columns: documentVerified tetap (ED-1027 AC-24), urutan baru tersimpan
                    $t->true(in_array('documentVerified', q50_seq($dbCc), true) && in_array('documentVerified', q50_seq($dbAc), true), 'DB columns: documentVerified tetap di current & available');
                    $t->eq(array_values(array_diff(q50_seq($dbAc), ['documentVerified'])), array_values(array_diff(q50_seq($newAc), ['documentVerified'])), 'DB available_columns: urutan terbalik tersimpan');
                    $t->eq(q50_seq($dbAc), q50_restore_expect(q50_seq($storedAc), q50_seq($newAc), ['documentVerified']), 'DB available_columns = oracle aturan sisip (documentVerified sesudah pendahulunya)');
                    $t->eq(q50_seq($dbCc), q50_restore_expect(q50_seq($storedCc), q50_seq($newCc), ['documentVerified']), 'DB current_columns = oracle aturan sisip');
                    // GET lagi (tanpa OD): tanpa entri
                    $g2 = $t->call($s, 'GET', Q50_DS . 'documentArchive');
                    $t->eq(q50_seq($g2[1]['result']['current_queries']), ['type', 'name', 'showRelatedTransaction'], 'tanpa OD: GET sesudah PUT = urutan baru tanpa entri');
                    $t->true(strpos(json_encode($g2[1]), 'verificationStatus') === false, 'tanpa OD: GET sesudah PUT tanpa jejak verificationStatus');
                });

                // ---------- pemegang menyembunyikan entri dari current (tetap di available)
                $holder = null;
                q50_with_user($t, q50_opts('smr', 'OD'), function () use ($t, $s, $F, $dv, $du, $checkResp, &$holder) {
                    $g = $t->call($s, 'GET', Q50_DS . 'documentArchive');
                    $t->status($g, 200, 'dengan OD: GET');
                    $res = $g[1]['result'];
                    $t->eq(q50_seq($res['current_queries']), ['type', 'name', 'showRelatedTransaction', 'verificationStatus'], 'dengan OD: entri tampil terakhir pada urutan baru (sesudah showRelatedTransaction)');
                    $cq = array_values(array_filter($res['current_queries'], function ($q) {
                        return $q['data_index'] !== 'verificationStatus';
                    }));
                    $p = $t->call($s, 'PUT', Q50_DS . 'documentArchive', [
                        'current_columns' => $res['current_columns'], 'available_columns' => $res['available_columns'],
                        'current_queries' => $cq, 'available_queries' => $res['available_queries'],
                    ]);
                    $checkResp($p, 'dengan OD, PUT menyembunyikan', true);
                    $pr = q50_resp_row($p[1]);
                    $t->true($pr !== null && !in_array('verificationStatus', q50_row_seq($pr, 'current_queries'), true) && in_array('verificationStatus', q50_row_seq($pr, 'available_queries'), true), 'respons PUT pemegang: entri hilang dari current_queries, tetap di available_queries');
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
                q50_with_user($t, q50_opts('smr', 'LIST'), function () use ($t, $s, $checkResp) {
                    $g = $t->call($s, 'GET', Q50_DS . 'documentArchive');
                    $res = $g[1]['result'];
                    $p = $t->call($s, 'PUT', Q50_DS . 'documentArchive', [
                        'current_columns' => $res['current_columns'], 'available_columns' => $res['available_columns'],
                        'current_queries' => array_reverse($res['current_queries']), 'available_queries' => $res['available_queries'],
                    ]);
                    $checkResp($p, 'tanpa OD, PUT sesudah entri disembunyikan pemegang', false);
                    $row = q50_cds_row($t);
                    $t->true(strpos($row[0]['current_queries'], 'verificationStatus') === false, 'verificationStatus tetap tidak ada di current_queries (tadinya tidak ada di sana)');
                    $t->true(strpos($row[0]['available_queries'], 'verificationStatus') !== false, 'verificationStatus tetap ada di available_queries');
                    $t->eq(q50_seq(json_decode($row[0]['available_queries'], true)), ['updatedBy', 'showRelatedTransaction', 'verificationStatus', 'updatedAt', 'type', 'name'], 'available_queries: posisi verificationStatus tak bergeser (sesudah showRelatedTransaction)');
                    $t->true(strpos($row[0]['current_columns'], 'documentVerified') !== false && strpos($row[0]['available_columns'], 'documentVerified') !== false, 'documentVerified tetap di current & available columns');
                });

                // ---------- pemegang menampilkan lagi (entri ditambahkan di akhir current)
                q50_with_user($t, q50_opts('smr', 'OD'), function () use ($t, $s, $fv, $checkResp) {
                    $g = $t->call($s, 'GET', Q50_DS . 'documentArchive');
                    $res = $g[1]['result'];
                    $cq = $res['current_queries'];
                    $cq[] = $fv;
                    $p = $t->call($s, 'PUT', Q50_DS . 'documentArchive', [
                        'current_columns' => $res['current_columns'], 'available_columns' => $res['available_columns'],
                        'current_queries' => $cq, 'available_queries' => $res['available_queries'],
                    ]);
                    $checkResp($p, 'dengan OD, PUT menampilkan lagi', true);
                    $r = q50_get($t, $s, ['pagination' => 2]);
                    $t->true(in_array('verificationStatus', q50_seq($r[1]['result']['queries']), true), 'GET archives: entri kembali di queries');
                    $t->eq(q50_seq($r[1]['result']['queries']), ['showRelatedTransaction', 'name', 'type', 'verificationStatus'], 'GET archives: queries mengikuti current_queries tersimpan (entri terakhir)');
                });

                // ---------- non-pemegang menyembunyikan pendahulu verificationStatus: sisip ke "indeks semula, paling jauh di akhir"
                q50_with_user($t, q50_opts('smr', 'LIST'), function () use ($t, $s, $checkResp) {
                    $before = q50_cds_row($t);
                    $stCq = q50_seq(json_decode($before[0]['current_queries'], true));   // showRelatedTransaction, name, type, verificationStatus
                    $stAq = q50_seq(json_decode($before[0]['available_queries'], true)); // updatedBy, showRelatedTransaction, verificationStatus, updatedAt, type, name
                    $g = $t->call($s, 'GET', Q50_DS . 'documentArchive');
                    $res = $g[1]['result'];
                    // pendahulu di current = type, di available = showRelatedTransaction: keduanya dibuang dari kiriman
                    $cq = array_values(array_filter($res['current_queries'], function ($q) {
                        return $q['data_index'] !== 'type';
                    }));
                    $aq = array_values(array_filter($res['available_queries'], function ($q) {
                        return $q['data_index'] !== 'showRelatedTransaction';
                    }));
                    $p = $t->call($s, 'PUT', Q50_DS . 'documentArchive', [
                        'current_columns' => $res['current_columns'], 'available_columns' => $res['available_columns'],
                        'current_queries' => $cq, 'available_queries' => $aq,
                    ]);
                    $checkResp($p, 'tanpa OD, PUT pendahulu disembunyikan', false);
                    $row = q50_cds_row($t);
                    $dbCq = q50_seq(json_decode($row[0]['current_queries'], true));
                    $dbAq = q50_seq(json_decode($row[0]['available_queries'], true));
                    $t->eq($dbCq, q50_restore_expect($stCq, array_column($cq, 'data_index'), ['verificationStatus']), 'current_queries (pendahulu type hilang dari kiriman) = oracle');
                    $t->eq($dbCq, ['showRelatedTransaction', 'name', 'verificationStatus'], 'current_queries: tanpa pendahulu -> indeks semula (3) terpotong ke akhir');
                    $t->eq($dbAq, q50_restore_expect($stAq, array_column($aq, 'data_index'), ['verificationStatus']), 'available_queries (pendahulu showRelatedTransaction hilang dari kiriman) = oracle');
                    $t->eq($dbAq, ['updatedBy', 'updatedAt', 'verificationStatus', 'type', 'name'], 'available_queries: tanpa pendahulu -> indeks semula (2)');
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
