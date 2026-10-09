<?php
/**
 * ED-1026 (rev: opname tanpa draft) - AC-26 (regresi list/create folder/put-in/hand-over/receive), AC-27 (kinerja Backup Arsip
 * ±24 rb dokumen, 1.000 scan), EXTRA-CONCURRENT (Konfirmasi bersamaan), EXTRA-PARALLEL-SCAN (scan sama bersamaan tanpa tulis),
 * EXTRA-FUZZ (masukan aneh pada scan / preview / POST opnames: tanpa 5xx, tanpa tulis).
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-26',
        'title' => 'Regresi: list archives, create-folder, put-in, hand-over, receive tetap; dokumen baru (store dari cetak) is_verified=0; pindah / hand over / receive tidak mengubah is_verified',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $marker = '2026-01-01 08:00:00';
                $N = function ($k) use ($t, $x) {
                    return q26_n($t, $x[$k]);
                };
                q26_set_archive($t, $x['dF1'], ['is_verified' => 1, 'verified_at' => $marker, 'verified_by' => 'QA26', 'id_archive_opname' => 'SESI-LAMA']);
                $verified = function ($k) use ($t, $x) {
                    $r = q26_row($t, $x[$k]);

                    return [(int) $r['is_verified'], $r['verified_at'], $r['verified_by'], $r['id_archive_opname']];
                };
                $expect = [1, $marker, 'QA26', 'SESI-LAMA'];

                // list
                $r = $t->call($s, 'GET', Q26_BASE . '/archives?pagination=5');
                $t->status($r, 200, 'GET archives');
                $t->true(isset($r[1]['result']['data']) && is_array($r[1]['result']['data']), 'list: result.data');
                $r = $t->call($s, 'GET', Q26_BASE . '/archives?pagination=50&id_archive=' . $x['F']);
                $t->status($r, 200, 'GET archives (buka folder)');

                // create-folder: folder baru is_verified default 0
                $name = q26_name('NEWF');
                $r = $t->call($s, 'POST', Q26_BASE . '/archives/create-folder', ['name' => $name, 'is_all_location' => 1, 'id_archive_parent' => $x['F']]);
                $t->status($r, 200, 'create-folder');
                $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE201', 'create-folder: ARCHIVE201');
                $newFolder = $t->db()->table('archives')->where('name', $name)->first();
                $t->true($newFolder !== null && (int) $newFolder->is_verified === 0 && $newFolder->verified_at === null && $newFolder->id_archive_opname === null, 'folder baru: kolom verifikasi default');

                // put-in: pindah dokumen verified (F -> OTH), lalu kembali (OTH -> S1)
                $r = $t->call($s, 'POST', Q26_BASE . '/documents/put-in', ['id_archive_parent' => $x['OTH'], 'name' => $N('dF1')]);
                $t->status($r, 200, 'put-in dF1 -> OTH');
                $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE204', 'put-in: ARCHIVE204');
                $t->eq($t->db()->table('archives')->where('id_archive', $x['dF1'])->value('id_archive_parent'), $x['OTH'], 'put-in: dokumen pindah');
                $t->eq($verified('dF1'), $expect, 'pindah folder: is_verified & kolom verifikasi tidak berubah');
                $r = $t->call($s, 'POST', Q26_BASE . '/documents/put-in', ['id_archive_parent' => $x['S1'], 'id_archives' => [$x['dF1']]]);
                $t->status($r, 200, 'put-in dF1 -> S1 (id_archives)');
                $t->eq($verified('dF1'), $expect, 'pindah lagi: tak berubah');
                $r = $t->call($s, 'POST', Q26_BASE . '/documents/put-in', ['id_archives' => [$x['dF1']]]);
                $t->status($r, 200, 'put-in dF1 -> root');
                $t->eq($verified('dF1'), $expect, 'keluarkan ke root: tak berubah');
                $r = $t->call($s, 'POST', Q26_BASE . '/documents/put-in', ['id_archive_parent' => $x['F'], 'name' => $N('dF1')]);
                $t->status($r, 200, 'put-in dF1 -> F');

                // hand-over, receive
                $r = $t->call($s, 'POST', Q26_BASE . '/documents/hand-over', ['name' => $N('dF1')]);
                $t->status($r, 200, 'hand-over');
                $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE209', 'hand-over: ARCHIVE209');
                $t->eq((int) $t->db()->table('archives')->where('id_archive', $x['dF1'])->value('status'), 2, 'hand-over: status 2 (Handed Over)');
                $t->eq($verified('dF1'), $expect, 'hand-over: is_verified tak berubah');
                $r = $t->call($s, 'POST', Q26_BASE . '/documents/receive', ['name' => $N('dF1')]);
                $t->status($r, 200, 'receive');
                $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE210', 'receive: ARCHIVE210');
                $t->eq((int) $t->db()->table('archives')->where('id_archive', $x['dF1'])->value('status'), 3, 'receive: status 3 (Taken)');
                $t->eq($verified('dF1'), $expect, 'receive: is_verified tak berubah');

                // dokumen unverified tetap unverified sesudah pindah
                $r = $t->call($s, 'POST', Q26_BASE . '/documents/put-in', ['id_archive_parent' => $x['S2'], 'name' => $N('dF2')]);
                $t->status($r, 200, 'put-in dF2 -> S2');
                $t->eq($verified('dF2'), [0, null, null, null], 'dokumen unverified tetap 0 sesudah pindah');

                // dokumen Taken di F ikut cakupan opname (BR-6): scan tanpa simpan -> verified
                $before = q26_state($t);
                $r = q26_scan($t, $s, q26_selection($x['F']), $N('dF1'));
                $t->eq($r[1]['result']['row']['result'] ?? null, 'verified', 'dokumen Taken di F discan -> verified (BR-6)');
                $t->true(q26_same_state($before, q26_state($t)), 'scan tidak menulis');

                // dokumen baru dari alur cetak (DocumentService::store): is_verified default 0
                $printName = q26_name('PRINT');
                $db = q26_dbname($t);
                $created = $t->probe(function () use ($db, $printName) {
                    \Illuminate\Support\Facades\DB::setDefaultConnection($db);
                    $type = \Modules\V5\Entities\Models\ArchiveDocument::TRANSACTION_TYPES[0];
                    $archive = (new \Modules\V5\Http\Services\DocumentArchive\DocumentService)->store([
                        'transaction_type' => $type, 'transaction_no' => $printName, 'id_transaction' => 'QA26TX-PRINT',
                        'id_related_employee' => null,
                    ]);

                    return $archive ? $archive->id_archive : null;
                });
                $t->true($created !== null, 'DocumentService::store membuat dokumen baru');
                $row = (array) $t->db()->table('archives')->where('id_archive', $created)->first();
                $t->eq([(int) $row['is_verified'], $row['verified_at'], $row['verified_by'], $row['id_archive_opname'], (int) $row['is_active']], [0, null, null, null, -1], 'dokumen baru: is_verified 0, kolom verifikasi NULL, is_active -1');
                $r = $t->call($s, 'POST', Q26_BASE . '/documents/put-in', ['id_archive_parent' => $x['S1'], 'name' => $printName]);
                $t->status($r, 200, 'put-in dokumen baru');
                $t->eq((int) $t->db()->table('archives')->where('id_archive', $created)->value('is_verified'), 0, 'dokumen baru sesudah put-in: tetap 0');
                // pratinjau memperlakukannya sebagai dokumen cakupan unverified
                $selS1 = q26_selection($x['S1']);
                $c = q26_preview($t, $s, $selS1)[1]['result']['counts'];
                $t->true($c['total_documents'] === 2 && $c['verified_before'] === 0, 'dokumen baru ikut cakupan S1 (d11 + baru = 2), belum ada yang verified');
                $r = q26_scan($t, $s, $selS1, $printName);
                $t->eq($r[1]['result']['row']['result'], 'verified', 'dokumen baru dapat discan verified');

                $t->eq($t->db()->table('archives')->where('name', 'like', Q26_PREFIX . '%')->whereNotNull('id_archive_opname')->where('id_archive_opname', '!=', 'SESI-LAMA')->count(), 0, 'tidak ada id_archive_opname terisi oleh regresi (opname tidak jalan)');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-27',
        'title' => 'Kinerja Backup Arsip (±24 rb dokumen): opnames/folders root, opnames/preview, preview/documents dan POST opnames dengan 1.000 scan, masing-masing < 30 dtk tanpa 500; angka sesi = oracle',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $db = $t->db();
                $backup = $db->table('archives')->where('name', 'Backup Arsip')->where('type', 1)->value('id_archive');
                $t->true($backup !== null, 'prasyarat: folder Backup Arsip ada');
                $direct = $db->table('archives')->where('id_archive_parent', $backup)->where('type', 2)->where('is_active', 1)->count();
                $t->true($direct > 20000, "prasyarat: Backup Arsip punya > 20 rb dokumen ($direct)");
                $timed = function (callable $fn) {
                    $t0 = microtime(true);
                    $r = $fn();

                    return [$r, microtime(true) - $t0];
                };

                // folders root: role 3 (non-bypass) dan superadmin 1 (semua)
                foreach ([null, 1] as $role) {
                    $run = function () use ($t, $s, $timed, $role, $backup) {
                        list($r, $sec) = $timed(function () use ($t, $s) {
                            return q26_folders($t, $s, null);
                        });
                        $t->status($r, 200, 'folders root (' . ($role ?? 'role 3') . ')');
                        $t->true($sec < 30, sprintf('folders root (%s): %.2f dtk < 30', $role ?? 'role 3', $sec));
                        $t->note(sprintf('folders root %s: %.2f dtk', $role ?? 'role 3', $sec));
                        list($r, $sec) = $timed(function () use ($t, $s, $backup) {
                            return q26_folders($t, $s, $backup);
                        });
                        $t->status($r, 200, 'folders Backup Arsip');
                        $t->true($sec < 30, sprintf('folders Backup Arsip: %.2f dtk < 30', $sec));
                        $t->note(sprintf('folders Backup Arsip %s: %.2f dtk', $role ?? 'role 3', $sec));
                    };
                    $role === null ? $run() : q26_with_user($t, ['role' => $role], $run);
                }

                // Backup Arsip dengan semua subfolder terlihat; 1.000 scan (dokumen asli folder itu) + 1 dokumen luar cakupan
                $children = q26_child_ids(q26_folders($t, $s, $backup));
                $sel = q26_selection($backup, array_map(function ($c) {
                    return ['id_archive' => $c, 'is_continue' => false];
                }, $children));
                $names = array_map('strval', $db->table('archives')->where('id_archive_parent', $backup)->where('type', 2)->where('is_active', 1)->orderBy('name')->limit(1000)->pluck('name')->all());
                $t->eq(count(array_unique(array_map('strtolower', $names))), 1000, 'prasyarat: 1.000 kode asli unik');
                $dupes = $db->selectOne('SELECT COUNT(*) - COUNT(DISTINCT name) d FROM archives WHERE type = 2 AND is_active = 1')->d;
                $t->note("nama dokumen aktif ganda di DB uji: $dupes (snapshot satu baris per kode; bila > 0 jumlah baris snapshot dilonggarkan)");
                $foreign = null;
                foreach ($db->table('archives')->where('type', 2)->where('is_active', 1)->where('id_archive_parent', '!=', $backup)->limit(50)->pluck('name') as $cand) {
                    if (!$db->table('archives')->where('type', 2)->where('is_active', 1)->where('id_archive_parent', $backup)->where('name', $cand)->exists()) {
                        $foreign = (string) $cand;
                        break;
                    }
                }
                $t->true($foreign !== null, 'prasyarat: ada dokumen di luar Backup Arsip');
                $base = new \DateTime(q26_today() . ' 00:00:00', new \DateTimeZone('Asia/Jakarta'));
                $scans = [];
                $i = 0;
                foreach ($names as $code) {
                    $d = clone $base;
                    $d->modify('+' . (int) ($i++ / 2) . ' seconds');
                    $scans[] = ['code' => (string) $code, 'scanned_at' => $d->format('Y-m-d H:i:s')];
                }
                $scans[] = ['code' => $foreign, 'scanned_at' => $base->format('Y-m-d') . ' 23:00:00'];
                $o = q26_oracle($t, $backup, array_keys(q26_locs($t)));
                $total = $o['direct'] + array_sum(array_map(function ($v) {
                    return $v[1];
                }, $o['children']));

                // scan satu kode (tanpa simpan)
                list($r, $sec) = $timed(function () use ($t, $s, $sel, $scans) {
                    return q26_scan($t, $s, $sel, $scans[0]['code']);
                });
                $t->eq($r[1]['result']['row']['result'] ?? null, 'verified', 'scan satu kode Backup Arsip: verified');
                $t->true($sec < 30, sprintf('scan: %.2f dtk < 30', $sec));
                $t->note(sprintf('scan 1 kode: %.2f dtk', $sec));

                // pratinjau dengan 1.000 scan
                list($p, $sec) = $timed(function () use ($t, $s, $sel, $scans) {
                    return q26_preview($t, $s, $sel, $scans);
                });
                q26_no500($t, $p, 'preview 1.000 scan');
                $t->status($p, 200, 'preview 1.000 scan');
                $t->true($sec < 30, sprintf('preview 1.000 scan: %.2f dtk < 30', $sec));
                $t->note(sprintf('preview 1.000 scan: %.2f dtk', $sec));
                $c = $p[1]['result']['counts'];
                $t->eq($c['total_documents'], $total, 'preview.total_documents = oracle (dokumen langsung + subtree anak terlihat)');
                $t->eq([$c['verified'], $c['not_found'], $c['scanned'], $c['unscanned']], [1000, 1, 1001, $total - 1000], 'preview.counts');

                // tabel pratinjau
                list($docs, $sec) = $timed(function () use ($t, $s, $sel, $scans) {
                    return q26_pdocs($t, $s, $sel, $scans, ['result' => 'all', 'pagination' => 50]);
                });
                $t->status($docs, 200, 'preview/documents all');
                $t->eq($docs[1]['result']['total'], $total + 1, 'preview/documents all: total = cakupan + not found');
                $t->true($sec < 30, sprintf('preview/documents all hal 1: %.2f dtk < 30', $sec));
                $t->note(sprintf('preview/documents all p1: %.2f dtk', $sec));
                list($docs, $sec) = $timed(function () use ($t, $s, $sel, $scans) {
                    return q26_pdocs($t, $s, $sel, $scans, ['result' => 'unscanned', 'pagination' => 100, 'page' => 150]);
                });
                $t->status($docs, 200, 'preview/documents unscanned halaman dalam');
                $t->eq(count($docs[1]['result']['data']), 100, 'unscanned halaman 150: 100 baris');
                $t->true($sec < 30, sprintf('preview/documents unscanned hal 150: %.2f dtk < 30', $sec));
                $t->note(sprintf('preview/documents unscanned p150: %.2f dtk', $sec));
                list($docs, $sec) = $timed(function () use ($t, $s, $sel, $scans) {
                    return q26_pdocs($t, $s, $sel, $scans, ['result' => 'scanned', 'pagination' => 100, 'page' => 11]);
                });
                $t->status($docs, 200, 'preview/documents scanned halaman 11');
                $t->eq(count($docs[1]['result']['data']), 1, 'scanned halaman 11 (1.001 baris): 1 baris');
                $t->true($sec < 30, sprintf('preview/documents scanned hal 11: %.2f dtk < 30', $sec));

                // Konfirmasi
                $selected = q26_selected($t, $s, $backup);
                $before = q26_state($t);
                list($conf, $sec) = $timed(function () use ($t, $s, $sel, $scans, $selected) {
                    return q26_store($t, $s, $sel, $scans, $selected);
                });
                q26_no500($t, $conf, 'Konfirmasi Backup Arsip');
                $t->status($conf, 200, 'Konfirmasi Backup Arsip');
                $t->true($sec < 30, sprintf('Konfirmasi Backup Arsip: %.2f dtk < 30', $sec));
                $t->note(sprintf('Konfirmasi Backup Arsip (%d dokumen, 1.001 scan): %.2f dtk', $total, $sec));
                $id = $conf[1]['result']['id_archive_opname'];

                $o = q26_opname($t, $id);
                $t->eq([(int) $o['status'], (int) $o['total_documents'], (int) $o['verified_count'], (int) $o['not_found_count'], (int) $o['invalid_count'], (int) $o['unscanned_count'], (int) $o['scanned_count']],
                    [2, $total, 1000, 1, 0, $total - 1000, 1001], 'DB: angka final sesi = oracle');
                $t->eq($db->table('archives')->where('id_archive_opname', $id)->count(), $total, 'DB: tepat dokumen cakupan yang ditandai sesi');
                $t->eq($db->table('archives')->where('id_archive_opname', $id)->where('is_verified', 1)->count(), 1000, 'DB: 1.000 dokumen verified');
                $snapRows = $db->table('archive_opname_documents')->where('id_archive_opname', $id)->count();
                $t->true($dupes > 0 ? $snapRows <= $total + 1 : $snapRows === $total + 1, "DB: snapshot = cakupan + 1 not found ($snapRows dari " . ($total + 1) . ')');
                $unscannedRows = $db->table('archive_opname_documents')->where('id_archive_opname', $id)->where('result', 4)->count();
                $t->true($dupes > 0 ? $unscannedRows <= $total - 1000 : $unscannedRows === $total - 1000, "DB: baris belum discan = total - 1000 ($unscannedRows)");
                $t->eq($db->table('archive_opname_documents')->where('id_archive_opname', $id)->distinct()->count('id_archive_opname_document'), $snapRows, 'DB: id baris snapshot dokumen unik (tanpa tabrakan)');
                $folders = q26_opfolders($t, $id);
                $t->eq((int) array_sum(array_column($folders, 'total_documents')), $total, 'DB: jumlah total_documents per folder = total');
                $t->eq((int) array_sum(array_column($folders, 'verified_count')), 1000, 'DB: jumlah verified_count per folder = 1000');
                $t->eq(count(array_unique(array_column($folders, 'id_archive_opname_folder'))), count($folders), 'DB: id baris snapshot folder unik');
                $t->eq($db->table('archive_opname_documents')->where('id_archive_opname', $id)->whereNotNull('scanned_at')->count(), 1001, 'DB: 1.001 baris scan dengan scanned_at');

                // pembacaan hasil sesi terkonfirmasi (snapshot) juga < 30 dtk
                list($docs, $sec) = $timed(function () use ($t, $s, $id) {
                    return q26_docs($t, $s, $id, ['result' => 'unscanned', 'pagination' => 100, 'page' => 150]);
                });
                $t->status($docs, 200, 'snapshot unscanned');
                $t->true($sec < 30, sprintf('snapshot unscanned hal 150: %.2f dtk < 30', $sec));
                list($show, $sec) = $timed(function () use ($t, $s, $id) {
                    return q26_show($t, $s, $id);
                });
                $t->true($show[0] === 200 && $sec < 30, sprintf('show terkonfirmasi: %.2f dtk', $sec));
                $t->eq($show[1]['result']['counts']['total_documents'], $total, 'show.total_documents = oracle');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'EXTRA-CONCURRENT',
        'title' => '(tambahan AC-22, BR-23) Konfirmasi bersamaan pada folder yang sama (selected_at sama): tepat satu 200, sisanya 400 ARCHIVE414; tidak ada 500; tepat satu sesi tersimpan; penulisan archives yang kalah di-rollback (verified konsisten dengan sesi pemenang)',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                for ($i = 0; $i < 30; $i++) {
                    q26_doc($t, 'BULK' . $i, ['parent' => $x['S1']]);
                }
                $sel = q26_selection($x['F'], q26_sel([$x['S1']]));
                $outcomes = [];
                foreach ([2, 2, 2, 4] as $round => $n) {
                    $selected = q26_selected($t, $s, $x['F']);
                    $codes = [q26_n($t, $x['d11']), q26_n($t, $x['dA1']), q26_n($t, $x['dF1']), q26_n($t, $x['dF2'])];
                    $reqs = [];
                    $scanSets = [];
                    for ($i = 0; $i < $n; $i++) {
                        list($scans) = q26_scan_all($t, $s, $sel, [$codes[$i]]);
                        $scanSets[$i] = $codes[$i];
                        $reqs[] = ['POST', Q26_BASE . '/opnames', $sel + ['scans' => $scans, 'selected_at' => $selected]];
                    }
                    $sessions = $t->db()->table('archive_opnames')->count();
                    $res = q26c_parallel($t, $s, $reqs);
                    $winner = null;
                    $winnerCode = null;
                    foreach ($res as $i => $r) {
                        $id = $r[1]['result']['id_archive_opname'] ?? null;
                        q26_track($id);
                        if ($id !== null) {
                            $winner = $id;
                            $winnerCode = $scanSets[$i];
                        }
                    }
                    $http = array_column($res, 0);
                    $msg = array_map(function ($r) {
                        return $r[1]['msg_code'] ?? ($r[1]['code'] ?? null);
                    }, $res);
                    $sorted = $http;
                    sort($sorted);
                    $outcomes[] = json_encode([$sorted, $msg]);
                    $t->true(max($http) < 500, "putaran $round ($n bersamaan): tanpa 5xx (" . json_encode($http) . ')');
                    $t->eq($sorted, array_merge([200], array_fill(0, $n - 1, 400)), "putaran $round: tepat satu 200, sisanya 400 (" . json_encode($msg) . ')');
                    $t->eq(array_values(array_diff($msg, ['ARCHIVE213'])), array_fill(0, $n - 1, 'ARCHIVE414'), "putaran $round: yang ditolak ARCHIVE414");
                    $t->eq($t->db()->table('archive_opnames')->count(), $sessions + 1, "putaran $round: tepat satu sesi baru");
                    // penulisan archives milik sesi yang kalah ikut di-rollback: semua dokumen cakupan hanya ditandai sesi pemenang,
                    // dan hanya dokumen yang dipindai pemenang yang verified
                    $stray = $t->db()->table('archives')->where('name', 'like', Q26_PREFIX . '%')->whereNotNull('id_archive_opname')->where('id_archive_opname', '!=', $winner)->count();
                    $t->eq($stray, 0, "putaran $round: tidak ada baris archives bertanda sesi lain (rollback sesi kalah)");
                    $verifiedNames = $t->db()->table('archives')->where('name', 'like', Q26_PREFIX . '%')->where('is_verified', 1)->where('id_archive_opname', $winner)->pluck('name')->all();
                    $t->eq(array_map('strtolower', $verifiedNames), [strtolower($winnerCode)], "putaran $round: yang verified hanya dokumen yang dipindai sesi pemenang");
                }
                $t->note('hasil putaran: ' . implode(' | ', $outcomes));
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'EXTRA-PARALLEL-SCAN',
        'title' => '(tambahan AC-11) scan kode yang sama bersamaan (klik ganda / frame kamera berulang): semua 200, hasil identik, tanpa 500 dan tanpa tulis (scan tanpa simpan)',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $sel = q26_selection($x['F'], q26_sel([$x['S1']]));
                $before = q26_state($t);
                $codes = [q26_n($t, $x['dF1']), q26_n($t, $x['d21']), 'QA26-PARALEL-INVALID', q26_n($t, $x['d11']), 'QA26-PARALEL-2'];
                $expect = ['verified', 'not_found', 'invalid', 'verified', 'invalid'];
                $statuses = [];
                foreach ($codes as $round => $code) {
                    $reqs = [];
                    for ($i = 0; $i < 5; $i++) {
                        $reqs[] = ['POST', Q26_BASE . '/opnames/scan', $sel + ['code' => $code]];
                    }
                    $res = q26c_parallel($t, $s, $reqs);
                    $http = array_column($res, 0);
                    $statuses[] = json_encode($http);
                    $t->true(max($http) < 500, "putaran $round: 5 scan bersamaan tanpa 5xx (" . json_encode($http) . ')');
                    $t->eq($http, [200, 200, 200, 200, 200], "putaran $round: semua 200");
                    $results = array_unique(array_map(function ($r) {
                        return $r[1]['result']['row']['result'] ?? null;
                    }, $res));
                    $t->eq(array_values($results), [$expect[$round]], "putaran $round: hasil klasifikasi identik");
                }
                $t->true(q26_same_state($before, q26_state($t)), 'scan bersamaan tidak menulis');
                $t->note('HTTP per putaran: ' . implode(' | ', $statuses));
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'EXTRA-FUZZ',
        'title' => '(tambahan AC-9) masukan aneh pada scan / preview / preview/documents / POST opnames: tanggal tak sah, tipe salah, banyak scan (3.000, kode ganda), nilai raksasa: tanpa 5xx; bentuk tak sah 422; 3.000 scan tersimpan utuh',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $B = Q26_BASE . '/opnames';
                $leaf = q26_folder($t, 'LEAF');
                q26_doc($t, 'LD', ['parent' => $leaf]);
                $sel = q26_selection($leaf);
                $now = q26_now()->format('Y-m-d H:i:s');
                $today = q26_today();
                $before = q26_state($t);
                // setiap POST opnames lewat sini: sesi yang (sah) tersimpan dicatat di jurnal supaya dibuang cleanup
                $post = function (array $body) use ($t, $s, $B) {
                    $r = $t->call($s, 'POST', $B, $body);
                    q26_track($r[1]['result']['id_archive_opname'] ?? null);

                    return $r;
                };

                // tanggal tak sah / meluap: format Y-m-d H:i:s harus persis (kembali-format sama) -> 422, bukan 500 saat simpan
                foreach (['24:00:00' => "$today 24:00:00", 'jam 25' => "$today 25:61:61", '30 Februari' => '2026-02-30 10:00:00', 'nol' => '0000-00-00 00:00:00',
                    'tahun 5 digit' => '12026-10-09 10:00:00', 'spasi ganda' => "$today  10:00:00", 'spasi tepi' => "$today 10:00:00 ", 'huruf' => 'abc', 'raksasa' => str_repeat('9', 5000)] as $label => $bad) {
                    // spasi di tepi dipangkas middleware TrimStrings -> nilai sah (200 / 400 ARCHIVE414 bila folder sudah tersimpan)
                    $trimmed = in_array($label, ['spasi tepi'], true);
                    $r = $post($sel + ['scans' => [], 'selected_at' => $bad]);
                    q26_no500($t, $r, "selected_at $label");
                    $t->true(in_array($r[0], $trimmed ? [200, 400] : [400, 422], true), "selected_at $label: " . ($trimmed ? '200/400 (dipangkas)' : '4xx') . " (HTTP {$r[0]})");
                    $r = $post($sel + ['scans' => [['code' => 'x', 'scanned_at' => $bad]], 'selected_at' => $now]);
                    q26_no500($t, $r, "scanned_at $label");
                    $t->true(in_array($r[0], $trimmed ? [200, 400] : [400, 422], true), "scanned_at $label: " . ($trimmed ? '200/400 (dipangkas)' : '4xx') . " (HTTP {$r[0]})");
                }
                // tipe salah / bentuk aneh
                $oddScans = [
                    'scans berisi string' => ['abc', 'def'],
                    'scans berisi angka' => [1, 2],
                    'scans objek bersarang' => [['code' => ['x' => ['y']], 'scanned_at' => $now]],
                    'scans null' => [null],
                    'scans objek kosong' => [[]],
                    'scans tanpa scanned_at' => [['code' => 'x']],
                ];
                foreach ($oddScans as $label => $scans) {
                    $lf = q26_folder($t, 'ODD');
                    $selX = q26_selection($lf);
                    foreach (['preview', 'preview/documents'] as $path) {
                        $r = $t->call($s, 'POST', "$B/$path", $selX + ['scans' => $scans]);
                        q26_no500($t, $r, "$path $label");
                        $t->status($r, 422, "$path $label -> 422");
                    }
                    $r = $post($selX + ['scans' => $scans, 'selected_at' => $now]);
                    q26_no500($t, $r, "POST opnames $label");
                    $t->status($r, 422, "POST opnames $label -> 422 (folder baru, bukan karena bentrok)");
                }
                $r = $t->call($s, 'POST', "$B/preview", ['id_archive' => $leaf, 'folders' => ['abc', 'def']]);
                q26_no500($t, $r, 'folders list string');
                $t->status($r, 422, 'folders berisi string -> 422');
                $r = $t->call($s, 'POST', "$B/preview", ['id_archive' => $leaf, 'folders' => [[]]]);
                q26_no500($t, $r, 'folders berisi objek kosong');
                $t->status($r, 422, 'folders berisi objek kosong -> 422');
                $r = $t->call($s, 'POST', "$B/preview", ['id_archive' => $leaf, 'folders' => array_map(function ($i) {
                    return ['id_archive' => str_pad((string) $i, 30, '0', STR_PAD_LEFT)];
                }, range(1, 300))]);
                q26_no500($t, $r, '300 folders tak ada');
                q26_deny($t, $r, 400, 'ARCHIVE416', '300 subfolder tak ada -> 416');

                // tubuh bukan JSON / kosong
                $base = rtrim($t->conf('API_URL'), '/');
                $r = $t->call($s, 'POST', "$B/preview", []);
                $t->status($r, 200, 'tubuh kosong = root (pratinjau)');
                $stored = count(q26_tracked());
                $t->eq($t->db()->table('archive_opnames')->count() - $before['opnames'][0], $stored, "masukan aneh: satu-satunya sesi baru adalah yang sah dikonfirmasi ($stored: kirim ulang dipangkas TrimStrings)");
                $t->eq($stored, 1, 'tepat satu masukan aneh yang sah (spasi tepi dipangkas) menyimpan sesi; selebihnya ditolak 4xx');
                $t->eq($t->db()->table('archives')->whereNotNull('id_archive_opname')->where('name', 'like', Q26_PREFIX . '%')->count(), 1, 'masukan aneh: hanya LD (dokumen folder daun dari satu-satunya sesi sah) yang ditandai sesi');

                // 3.000 scan unik (+ ganda) di folder daun: pratinjau & Konfirmasi tanpa 5xx, tersimpan utuh
                $scans = [];
                for ($i = 0; $i < 3000; $i++) {
                    $scans[] = ['code' => 'QA26-FUZZ-' . $i, 'scanned_at' => $now];
                }
                for ($i = 0; $i < 500; $i++) {
                    $scans[] = ['code' => 'qa26-fuzz-' . ($i * 5) . ' ', 'scanned_at' => $now];
                }
                $t0 = microtime(true);
                $p = q26_preview($t, $s, $sel, $scans);
                $t->status($p, 200, 'preview 3.500 scan (3.000 unik)');
                $t->eq($p[1]['result']['counts']['scanned'], 3000, 'preview: ganda dibuang -> 3.000');
                $t->eq($p[1]['result']['counts']['invalid'], 3000, 'preview: 3.000 invalid');
                $selected = q26_selected($t, $s, $leaf);
                $r = q26_store($t, $s, $sel, $scans, $selected);
                q26_no500($t, $r, 'Konfirmasi 3.500 scan');
                $t->status($r, 200, 'Konfirmasi 3.500 scan');
                $t->note(sprintf('preview + Konfirmasi 3.500 scan: %.2f dtk', microtime(true) - $t0));
                $id = $r[1]['result']['id_archive_opname'];
                $t->eq($t->db()->table('archive_opname_documents')->where('id_archive_opname', $id)->where('result', 3)->count(), 3000, 'DB: 3.000 baris invalid');
                $t->eq($t->db()->table('archive_opname_documents')->where('id_archive_opname', $id)->whereNotNull('scanned_at')->count(), 3000, 'DB: 3.000 baris dengan scanned_at');
                $rows = q26_all_docs($t, $s, $id, 'scanned', 500);
                $t->eq(count($rows), 3000, 'GET documents scanned: 3.000 baris semua halaman');
                $t->eq($rows[0]['code'], 'QA26-FUZZ-2999', 'terbaru dulu: scan terakhir di baris pertama');
            } finally {
                q26_cleanup($t);
            }
        },
    ],
];
