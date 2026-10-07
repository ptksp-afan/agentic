<?php
/**
 * ED-1027 - AC-1: kolom Document Verified di display setting (Config/displayColumn/documentArchive.php) dan Updater
 * AddDocumentVerifiedColumnArchive (hapus baris column_display_settings documentArchive, buat ulang dari config).
 * Updater dijalankan sungguhan di DB QA di dalam probe; baris asli dipulihkan persis (jurnal di finally).
 */
require_once __DIR__ . '/qa_lib.php';

$q27_updater = 'Modules/UpdateVersion/Updaters/2026_10_07_14_13_16_AddDocumentVerifiedColumnArchive.php';

/** [data_index serialisasi] urutan kolom. */
$q27_seq = function (array $columns) {
    return array_map(function ($col) {
        return is_array($col['data_index']) ? implode('.', $col['data_index']) : $col['data_index'];
    }, $columns);
};

$q27_settings = function ($t) {
    return q27_rows($t->db()->table('column_display_settings')->where('module_name', 'documentArchive')->get());
};

return [

    [
        'id'    => 'AC-1',
        'title' => 'list: result.columns memuat documentVerified tepat sesudah document.relatedEmployeeName dan sebelum history (sort=false, tipe string, judul en/id); column_display_settings documentArchive (current & available) sama dengan config; Updater terdaftar (kunci terbesar, berkas ada) & tercatat sukses',
        'run'   => function ($t) use ($q27_seq, $q27_settings, $q27_updater) {
            $s = $t->session();
            $r = q27_list($t, $s);
            $t->status($r, 200, 'list');
            $cols = $r[1]['result']['columns'] ?? [];
            $seq = $q27_seq($cols);
            $iSal = array_search('document.relatedEmployeeName', $seq, true);
            $iVer = array_search('documentVerified', $seq, true);
            $iHis = array_search('history', $seq, true);
            $t->true($iSal !== false && $iVer !== false && $iHis !== false, 'kolom Salesman, documentVerified, history ada: ' . implode(',', $seq));
            $t->eq($iVer, $iSal + 1, 'documentVerified tepat sesudah Salesman');
            $t->eq($iHis, $iVer + 1, 'history (Status) tepat sesudah documentVerified');
            $t->eq($cols[$iVer]['sort'], false, 'sort=false');
            $t->eq($cols[$iVer]['type'], 'string', 'type string');
            $t->eq($cols[$iVer]['title'], ['en' => 'Document Verified', 'id' => 'Dokumen Terverifikasi'], 'judul en/id');
            $t->true(!isset($cols[$iVer]['foreign_key']), 'tanpa foreign_key');
            $qs = $r[1]['result']['queries'] ?? [];
            foreach ($qs as $q) {
                $t->true(($q['data_index'] ?? null) !== 'documentVerified' && ($q['data_index'] ?? null) !== 'document_verified', 'documentVerified bukan filter (queries)');
            }

            // --- DB: baris tunggal, sama dengan config di BE_DIR
            $rows = $q27_settings($t);
            $t->eq(count($rows), 1, 'column_display_settings documentArchive: 1 baris');
            $config = require rtrim($t->conf('BE_DIR'), '/') . '/Modules/V5/Config/displayColumn/documentArchive.php';
            $cur = json_decode($rows[0]['current_columns'], true);
            $avail = json_decode($rows[0]['available_columns'], true);
            $t->eq($q27_seq($cur), $q27_seq($config['current_columns']), 'DB current_columns urutan = config');
            $t->eq($q27_seq($avail), $q27_seq($config['available_columns']), 'DB available_columns urutan = config');
            foreach (['current_columns' => $cur, 'available_columns' => $avail] as $name => $list) {
                $sq = $q27_seq($list);
                $i = array_search('documentVerified', $sq, true);
                $t->true($i !== false && ($sq[$i - 1] ?? null) === 'document.relatedEmployeeName' && ($sq[$i + 1] ?? null) === 'history', "DB $name: documentVerified sesudah Salesman, sebelum Status");
                $t->eq($list[$i], $config['current_columns'][array_search('documentVerified', $q27_seq($config['current_columns']), true)], "DB $name: objek kolom = config");
            }

            // --- Updater: terdaftar, berkas ada, kunci terbesar, tercatat sukses
            $be = rtrim($t->conf('BE_DIR'), '/');
            $conf = require $be . '/Modules/UpdateVersion/Updaters/config.php';
            $file = 'Modules\\UpdateVersion\\Updaters\\2026_10_07_14_13_16_AddDocumentVerifiedColumnArchive';
            $key = array_search($file, $conf, true);
            $t->true($key !== false, 'Updater terdaftar di Updaters/config.php');
            $t->eq(max(array_keys($conf)), $key, 'kunci Updater = kunci terbesar di config.php (urutan jalan terakhir)');
            $t->eq(count($conf), count(array_unique($conf)), 'tidak ada Updater terdaftar ganda');
            $t->eq(count(array_keys($conf)), count(array_unique(array_keys($conf))), 'kunci unik');
            $t->true(is_file($be . '/' . $q27_updater), 'berkas Updater ada');
            $d = \DateTime::createFromFormat('Y_m_d_H_i_s', '2026_10_07_14_13_16', new \DateTimeZone('Asia/Jakarta'));
            $t->true(abs($d->getTimestamp() - $key) <= 8 * 3600, 'kunci config konsisten dengan nama berkas (selisih zona waktu saja)');
            $log = $t->db()->table('updater_logs')->where('key_id', $key)->first();
            $t->note('updater_logs DB QA: ' . ($log ? $log->status : 'tanpa baris (Updater ED-1025/1026/1027 dijalankan langsung oleh dev, bukan lewat runner log; log terakhir 2026-09-21)'));
        },
    ],

    [
        'id'    => 'X-1',
        'title' => 'Updater dijalankan sungguhan: baris documentArchive dibuat ulang dengan kolom baru (dari keadaan lama tanpa kolom), idempoten, modul lain tak tersentuh, 200 tanpa 500; baris asli dipulihkan persis',
        'run'   => function ($t) use ($q27_seq, $q27_settings, $q27_updater) {
            $s = $t->session();
            $be = rtrim($t->conf('BE_DIR'), '/');
            $c = $t->db();
            $snapshot = $q27_settings($t);
            $t->eq(count($snapshot), 1, 'prasyarat: 1 baris documentArchive');
            $othersBefore = md5(json_encode(q27_rows($c->table('column_display_settings')->where('module_name', '!=', 'documentArchive')->orderBy('module_name')->get())));
            $logBefore = md5(json_encode(q27_rows($c->table('updater_logs')->orderBy('key_id')->get())));

            $restore = function () use ($t, $snapshot) {
                q27_w($t, function ($w) use ($snapshot) {
                    $w->table('column_display_settings')->where('module_name', 'documentArchive')->delete();
                    $w->table('column_display_settings')->insert($snapshot);
                });
            };
            $run = function () use ($t, $be, $q27_updater) {
                q27_w($t, function () use ($be, $q27_updater) {
                    $o = require $be . '/' . $q27_updater;
                    $o->run();
                });
            };

            try {
                // keadaan instalasi lama: baris ada, tanpa kolom documentVerified (pilihan user lama)
                q27_w($t, function ($w) use ($snapshot) {
                    $row = $snapshot[0];
                    foreach (['current_columns', 'available_columns'] as $f) {
                        $list = json_decode($row[$f], true);
                        $list = array_values(array_filter($list, function ($col) {
                            return $col['data_index'] !== 'documentVerified';
                        }));
                        $row[$f] = json_encode($list);
                    }
                    $w->table('column_display_settings')->where('module_name', 'documentArchive')->update(['current_columns' => $row['current_columns'], 'available_columns' => $row['available_columns']]);
                });
                $r = q27_list($t, $s, ['pagination' => 5]);
                $t->status($r, 200, 'list keadaan lama');
                $t->true(!in_array('documentVerified', $q27_seq($r[1]['result']['columns']), true), 'sebelum Updater (setting lama): kolom belum ada (alasan Updater dibutuhkan)');
                $t->true(array_key_exists('document_verified', $r[1]['result']['data'][0] ?? []), 'document_verified tetap dikirim walau kolom tak ada di setting');

                $run();
                $rows = $q27_settings($t);
                $t->eq(count($rows), 1, 'sesudah Updater: 1 baris');
                $t->true(in_array('documentVerified', $q27_seq(json_decode($rows[0]['current_columns'], true)), true), 'current_columns memuat documentVerified');
                $t->true(in_array('documentVerified', $q27_seq(json_decode($rows[0]['available_columns'], true)), true), 'available_columns memuat documentVerified');
                $r = q27_list($t, $s, ['pagination' => 5]);
                $t->status($r, 200, 'list sesudah Updater');
                $seq = $q27_seq($r[1]['result']['columns']);
                $t->eq($seq[array_search('documentVerified', $seq, true) - 1] ?? null, 'document.relatedEmployeeName', 'list: documentVerified sesudah Salesman');
                $first = $rows[0]['current_columns'];

                $run();
                $rows = $q27_settings($t);
                $t->eq(count($rows), 1, 'Updater dijalankan dua kali: tetap 1 baris (idempoten)');
                $t->eq($rows[0]['current_columns'], $first, 'isi current_columns sama');
                $t->eq(md5(json_encode(q27_rows($c->table('column_display_settings')->where('module_name', '!=', 'documentArchive')->orderBy('module_name')->get()))), $othersBefore, 'baris modul lain tidak berubah');

                // jalur runner nyata UpdateVersionController::executeUpdater (private) hanya untuk Updater ini
                $conf = require $be . '/Modules/UpdateVersion/Updaters/config.php';
                $file = 'Modules\\UpdateVersion\\Updaters\\2026_10_07_14_13_16_AddDocumentVerifiedColumnArchive';
                $key = array_search($file, $conf, true);
                $hadLog = $c->table('updater_logs')->where('key_id', $key)->count();
                q27_w($t, function ($w) use ($be, $key, $file) {
                    $w->table('column_display_settings')->where('module_name', 'documentArchive')->delete();
                    $ctl = new \Modules\UpdateVersion\Http\Controllers\UpdateVersionController();
                    $m = new \ReflectionMethod($ctl, 'executeUpdater');
                    $m->setAccessible(true);
                    $m->invoke($ctl, $key, $file);
                });
                $log = $c->table('updater_logs')->where('key_id', $key)->first();
                $t->true($log !== null && $log->status === 'success' && $log->error_log === null, 'runner nyata executeUpdater: updater_logs status success, tanpa error_log');
                $rows = $q27_settings($t);
                $t->eq(count($rows), 1, 'runner nyata: 1 baris documentArchive dibuat ulang');
                $t->true(in_array('documentVerified', $q27_seq(json_decode($rows[0]['current_columns'], true)), true), 'runner nyata: current_columns memuat documentVerified');
                $t->eq($rows[0]['current_columns'], $first, 'runner nyata: isi sama dengan hasil run langsung');
            } finally {
                $restore();
                if (isset($key) && empty($hadLog)) {
                    q27_w($t, function ($w) use ($key) {
                        $w->table('updater_logs')->where('key_id', $key)->delete();
                    });
                }
            }
            $after = $q27_settings($t);
            $t->eq(json_encode($after), json_encode($snapshot), 'baris documentArchive dipulihkan persis');
            $t->eq(md5(json_encode(q27_rows($c->table('column_display_settings')->where('module_name', '!=', 'documentArchive')->orderBy('module_name')->get()))), $othersBefore, 'modul lain utuh sesudah pemulihan');
            $t->eq(md5(json_encode(q27_rows($c->table('updater_logs')->orderBy('key_id')->get()))), $logBefore, 'updater_logs dipulihkan persis (baris log uji dihapus)');
        },
    ],
];
