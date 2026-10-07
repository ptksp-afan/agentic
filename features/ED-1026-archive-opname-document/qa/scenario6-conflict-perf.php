<?php
/**
 * ED-1026 - bentrok & hari lain & regresi & kinerja: AC-22 (bagian BE: 400 ARCHIVE414), AC-23 (ARCHIVE415 + hari lain),
 * AC-26 (regresi list/create folder/put-in/hand-over/receive, dokumen baru is_verified=0, verified tak berubah karena
 * pindah/hand over/receive), AC-27 (kinerja Backup Arsip ±24 rb dokumen), EXTRA-CONCURRENT (dua Confirm bersamaan).
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-22',
        'title' => '(bagian BE) Sesi B memilih folder X, sesi A dikonfirmasi pada X -> confirm B 400 ARCHIVE414 (msg_code, result.folders, parameter), DB tidak berubah; PUT lalu confirm berhasil; folder tak beririsan tidak bentrok; root vs folder',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $marker = '2026-01-01 08:00:00';
                $uname = q26_uname($t);
                $seed = function (array $keys) use ($t, $x, $marker) {
                    foreach ($keys as $k) {
                        q26_set_archive($t, $x[$k], ['is_verified' => 1, 'verified_at' => $marker, 'verified_by' => 'QA26', 'id_archive_opname' => null]);
                    }
                };
                $N = function ($k) use ($t, $x) {
                    return q26_n($t, $x[$k]);
                };
                $snapshot = function ($id) use ($t, $x) {
                    $rows = [];
                    foreach (['dF1', 'dF2', 'dJF', 'd11', 'dA1', 'd21'] as $k) {
                        $rows[$k] = q26_row($t, $x[$k]);
                    }

                    return json_encode([$rows, q26_opname($t, $id), q26_opdocs($t, $id), q26_opfolders($t, $id), $t->db()->table('archive_opnames')->count()]);
                };

                $seed(['dF1', 'd11', 'dA1']);
                // B memilih F[S1, S2]; A (folder opname S1) dikonfirmasi sesudahnya
                $b = q26_session($t, $s, $x['F'], q26_sel([$x['S1'], $x['S2']]));
                q26_scan($t, $s, $b, $N('dF2'));
                sleep(1);
                $a = q26_session($t, $s, $x['S1'], []);
                $t->status(q26_confirm($t, $s, $a), 200, 'A (S1) confirm');
                $aRow = q26_opname($t, $a);
                $seedAfterA = ['d11' => q26_v($t, $x['d11']), 'dA1' => q26_v($t, $x['dA1'])];
                $before = $snapshot($b);

                $r = q26_confirm($t, $s, $b);
                $t->status($r, 400, 'B confirm bentrok');
                $t->eq($r[1]['status'] ?? null, 'fail', 'status fail (formatResponse false)');
                $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE414', 'msg_code ARCHIVE414');
                $t->true(!array_key_exists('code', $r[1]), 'bentuk formatResponse: tanpa kunci code (FE membaca msg_code)');
                $t->has($r[1], 'message', 'ada message');
                $t->true(strpos($r[1]['message'], q26_n($t, $x['S1'])) !== false, 'message memuat nama folder bentrok');
                $t->true(strpos($r[1]['message'], $uname) !== false, 'message memuat user pengonfirmasi');
                $t->true(preg_match('/\d{2}:\d{2}/', $r[1]['message']) === 1, 'message memuat jam HH:mm');
                $t->true(strpos($r[1]['message'], 'sudah diopname oleh') !== false, 'message bahasa ID (user QA)');
                $folders = $r[1]['result']['folders'] ?? [];
                $ids = array_column($folders, 'id_archive');
                $t->eq($ids, [$x['S1']], 'result.folders = folder tercakup B yang dikonfirmasi A (A hanya mencakup S1: S1a tidak dicentang di A), satu per folder');
                foreach ($folders as $f) {
                    $t->eq(array_keys($f), ['id_archive', 'name', 'created_by', 'confirmed_at'], 'kunci result.folders[]');
                    $t->eq($f['created_by'], $uname, 'result.folders: created_by');
                    $t->eq($f['confirmed_at'], $aRow['confirmed_at'], 'result.folders: confirmed_at sesi A');
                }
                $t->eq(array_column($folders, 'name', 'id_archive')[$x['S1']], q26_n($t, $x['S1']), 'result.folders: name');
                $t->true($before === $snapshot($b), 'DB tidak berubah oleh confirm yang ditolak (archives, sesi B, snapshot, jumlah sesi)');
                $t->eq((int) q26_opname($t, $b)['status'], 1, 'B tetap draft');
                $t->eq(q26_v($t, $x['d11']), $seedAfterA['d11'], 'd11 tetap hasil A');

                // Step 1 sesudahnya: S1 "sudah diopname hari ini"
                $r = q26_folders($t, $s, $x['F']);
                $c1 = q26_child($r, $x['S1']);
                $t->true($c1['opnamed_today'] !== null && $c1['is_default_checked'] === false && $c1['opnamed_today']['id_archive_opname'] === $a, 'Step 1: S1 sudah diopname hari ini oleh A');
                $t->true(q26_child($r, $x['S2'])['opnamed_today'] === null, 'Step 1: S2 belum diopname');

                // PUT (pilih ulang, S1 lanjut) sesudah jeda -> confirm berhasil, scan tetap
                sleep(2);
                $seed(['d11']);
                $r = q26_update($t, $s, $b, q26_sel([$x['S1'] => true, $x['S2'] => false]));
                $t->status($r, 200, 'PUT pilih ulang');
                $t->eq(q26_opdocs($t, $b)[strtolower($N('dF2'))]['result'] ?? null, 1, 'scan dF2 tetap sesudah PUT');
                $r = q26_confirm($t, $s, $b);
                $t->status($r, 200, 'B confirm sesudah pilih ulang');
                $t->eq(q26_v($t, $x['d11'])[0], 1, 'S1 lanjut: d11 (tak discan) tetap verified');

                // folder tak beririsan: B2 (F[S2]) vs A2 (S1) -> tidak bentrok
                sleep(1);
                $seed(['d21']);
                $b2 = q26_session($t, $s, $x['S2'], []);
                $a2 = q26_session($t, $s, $x['S1'], [], true);
                $t->status(q26_confirm($t, $s, $a2), 200, 'A2 (S1) confirm');
                $t->status(q26_confirm($t, $s, $b2), 200, 'B2 (S2) confirm: tidak beririsan dengan A2');

                // root (B_root memilih F) vs A (S1): S1 turunan F -> bentrok; nama root "All Archive" untuk root vs root
                sleep(1);
                $bRoot = q26_session($t, $s, null, q26_sel([$x['F']]));
                $bRoot2 = q26_session($t, $s, null, []);
                sleep(1);
                $a3 = q26_session($t, $s, $x['S1'], []);
                $t->status(q26_confirm($t, $s, $a3), 200, 'A3 (S1) confirm');
                $r = q26_confirm($t, $s, $bRoot);
                q26_deny($t, $r, 400, 'ARCHIVE414', 'root[F] vs S1: bentrok');
                $ids = array_column($r[1]['result']['folders'] ?? [], 'id_archive');
                $t->true(in_array($x['S1'], $ids, true), 'root[F] vs S1: S1 ada di result.folders');
                // root tanpa subfolder (hanya dokumen tanpa folder) vs root lain
                sleep(1);
                $aRoot = q26_session($t, $s, null, []);
                $t->status(q26_confirm($t, $s, $aRoot), 200, 'A_root confirm (hanya dokumen tanpa folder)');
                $r = q26_confirm($t, $s, $bRoot2);
                q26_deny($t, $r, 400, 'ARCHIVE414', 'root vs root: bentrok');
                $first = $r[1]['result']['folders'][0] ?? [];
                $t->true(array_key_exists('id_archive', $first) && $first['id_archive'] === null, 'root: id_archive null');
                $t->true(array_key_exists('name', $first) && $first['name'] === null, 'root: name null');
                $t->true(strpos($r[1]['message'], 'All Archive') !== false, 'root: message memuat "All Archive" (kontrak parameter [0])');
                // bentrok tidak memengaruhi sesi yang batal (batal tak dihitung)
                q26_cancel($t, $s, $bRoot2);
                $t->eq((int) q26_opname($t, $bRoot2)['status'], 3, 'sesi bentrok dapat dibatalkan');
                q26_cancel($t, $s, $bRoot);

                // bahasa EN: pesan Inggris, kode sama
                sleep(1);
                $e1 = q26_session($t, $s, $x['S2'], []);
                sleep(1);
                $e2 = q26_session($t, $s, $x['S2'], []);
                $t->status(q26_confirm($t, $s, $e2), 200, 'E2 confirm');
                q26_with_user($t, ['lang' => 'EN'], function () use ($t, $s, $e1) {
                    $r = q26_confirm($t, $s, $e1);
                    q26_deny($t, $r, 400, 'ARCHIVE414', 'EN: bentrok');
                    $t->true(strpos($r[1]['message'], 'was opnamed by') !== false, 'EN: message Inggris');
                });
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-23',
        'title' => 'Draft dari hari lain -> confirm 400 ARCHIVE415 (DB tak berubah); sesi terkonfirmasi kemarin -> Step 1 "Belum diopname" & tercentang; batas tengah malam Asia/Jakarta; opname di hari baru = timpa',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $marker = '2026-01-01 08:00:00';
                $yesterday = q26_now('-1 day')->format('Y-m-d');
                $todayStart = q26_today() . ' 00:00:00';

                // --- draft kemarin -> 415
                $id = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                q26_scan($t, $s, $id, q26_n($t, $x['dF1']));
                q26_set_opname($t, $id, ['created_at' => $yesterday . ' 23:59:59']);
                $snap = json_encode([q26_opname($t, $id), q26_opdocs($t, $id), q26_row($t, $x['dF1'])]);
                $r = q26_confirm($t, $s, $id);
                q26_deny($t, $r, 400, 'ARCHIVE415', 'draft kemarin 23:59:59');
                $t->true(strpos($r[1]['message'] ?? '', 'hari lain') !== false, 'message ARCHIVE415 (ID)');
                $t->true($snap === json_encode([q26_opname($t, $id), q26_opdocs($t, $id), q26_row($t, $x['dF1'])]), 'DB tidak berubah oleh 415');
                $t->eq((int) q26_opname($t, $id)['status'], 1, 'tetap draft');
                // draft hari lain boleh dibatalkan
                $t->status(q26_cancel($t, $s, $id), 200, 'draft hari lain dapat dibatalkan');

                // --- batas: created_at tepat 00:00:00 hari ini -> boleh
                sleep(1);
                $id = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                q26_set_opname($t, $id, ['created_at' => $todayStart]);
                $r = q26_confirm($t, $s, $id);
                $t->status($r, 200, 'draft dibuat 00:00:00 hari ini -> confirm OK');
                $t->eq((int) q26_opname($t, $id)['status'], 2, 'status 2');

                // --- sesi terkonfirmasi kemarin: F belum diopname hari ini
                q26_set_opname($t, $id, ['confirmed_at' => $yesterday . ' 23:59:59', 'selected_at' => $yesterday . ' 23:50:00', 'created_at' => $yesterday . ' 23:50:00']);
                $r = q26_folders($t, $s, $x['F']);
                $t->true($r[1]['result']['folder']['opnamed_today'] === null, 'konfirmasi kemarin 23:59:59: F belum diopname hari ini');
                foreach ([$x['S1'], $x['S2']] as $c) {
                    $row = q26_child($r, $c);
                    $t->true($row['opnamed_today'] === null && $row['is_default_checked'] === true, 'konfirmasi kemarin: anak belum diopname & tercentang');
                }
                q26_set_opname($t, $id, ['confirmed_at' => $todayStart]);
                $r = q26_folders($t, $s, $x['F']);
                $t->true($r[1]['result']['folder']['opnamed_today'] !== null, 'konfirmasi tepat 00:00:00 hari ini: sudah diopname hari ini');
                $t->true(q26_child($r, $x['S1'])['is_default_checked'] === false, 'konfirmasi 00:00:00 hari ini: S1 tak tercentang');
                $t->eq($r[1]['result']['folder']['opnamed_today']['confirmed_at'], $todayStart, 'confirmed_at dilaporkan apa adanya');
                // besok 00:00:00 bukan hari ini
                q26_set_opname($t, $id, ['confirmed_at' => q26_now('+1 day')->format('Y-m-d') . ' 00:00:00']);
                $r = q26_folders($t, $s, $x['F']);
                $t->true($r[1]['result']['folder']['opnamed_today'] === null, 'konfirmasi besok 00:00:00 bukan hari ini');

                // folder daun yang dikonfirmasi kemarin: Step 1 tidak dilewati? -> belum diopname hari ini => dilewati
                sleep(1);
                $leaf = q26_session($t, $s, $x['S2'], []);
                $t->status(q26_confirm($t, $s, $leaf), 200, 'confirm S2 (daun)');
                $r = q26_folders($t, $s, $x['S2']);
                $t->true($r[1]['result']['skip_select_folder'] === false, 'S2 diopname hari ini: Step 1 tidak dilewati');
                q26_set_opname($t, $leaf, ['confirmed_at' => $yesterday . ' 10:00:00']);
                $r = q26_folders($t, $s, $x['S2']);
                $t->true($r[1]['result']['folder']['opnamed_today'] === null && $r[1]['result']['skip_select_folder'] === true, 'S2 dikonfirmasi kemarin: belum diopname, Step 1 dilewati');

                // --- opname di hari baru = timpa (Lanjut diabaikan, tak discan -> unverified)
                q26_set_archive($t, $x['d21'], ['is_verified' => 1, 'verified_at' => $marker, 'verified_by' => 'QA26', 'id_archive_opname' => null]);
                $n = q26_session($t, $s, $x['S2'], [], true);   // is_continue true diminta
                $fol = q26_opfolders($t, $n);
                $t->eq([(int) $fol[0]['is_continue'], (int) $fol[0]['is_opnamed_today']], [0, 0], 'hari baru: is_continue disimpan false (folder belum diopname hari ini)');
                $t->status(q26_confirm($t, $s, $n), 200, 'confirm hari baru');
                $v = q26_v($t, $x['d21']);
                $t->true($v[0] === 0 && $v[3] === $n, 'hari baru: d21 (verified kemarin, tak discan) -> unverified');
                // sesi kemarin tak menimbulkan bentrok bagi sesi yang dipilih hari ini
                $t->eq((int) q26_opname($t, $n)['unverified_count'], 1, 'unverified_count 1');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-26',
        'title' => 'Regresi: list archives, create-folder, put-in, hand-over, receive tetap; dokumen baru (store dari cetak) is_verified=0; pindah / hand over / receive tidak mengubah is_verified',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $marker = '2026-01-01 08:00:00';
                $uname = q26_uname($t);
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
                // keluarkan ke root (take out)
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

                // dokumen yang sudah dihand over / diterima ikut dihitung opname (BR-6), dokumen -1 yang di-put-in menjadi aktif ikut
                $id = q26_session($t, $s, $x['F'], []);
                $r = q26_scan($t, $s, $id, $N('dF1'));
                $t->eq($r[1]['result']['row']['result'], 'verified', 'dokumen Taken di F discan -> verified (BR-6)');
                q26_cancel($t, $s, $id);

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
                // dokumen baru dimasukkan ke folder lewat put-in: tetap unverified
                $r = $t->call($s, 'POST', Q26_BASE . '/documents/put-in', ['id_archive_parent' => $x['S1'], 'name' => $printName]);
                $t->status($r, 200, 'put-in dokumen baru');
                $t->eq((int) $t->db()->table('archives')->where('id_archive', $created)->value('is_verified'), 0, 'dokumen baru sesudah put-in: tetap 0');
                // sesi opname setelahnya memperlakukannya sebagai dokumen cakupan unverified
                $id = q26_session($t, $s, $x['S1'], []);
                $c = q26_show($t, $s, $id)[1]['result']['counts'];
                $t->true($c['total_documents'] === 2 && $c['verified_before'] === 0, 'dokumen baru ikut cakupan S1 (d11 + baru = 2), belum ada yang verified');
                $r = q26_scan($t, $s, $id, $printName);
                $t->eq($r[1]['result']['row']['result'], 'verified', 'dokumen baru dapat discan verified');
                q26_cancel($t, $s, $id);

                // tidak ada kerusakan: baris QA tanpa sesi opname tidak punya id sesi
                $t->eq($t->db()->table('archives')->where('name', 'like', Q26_PREFIX . '%')->whereNotNull('id_archive_opname')->where('id_archive_opname', '!=', 'SESI-LAMA')->count(), 0, 'tidak ada id_archive_opname terisi oleh regresi');
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'AC-27',
        'title' => 'Kinerja: opnames/folders root dan Confirm pada Backup Arsip (±24 rb dokumen) < 30 dtk tanpa 500; angka final = oracle; show & documents draft juga < 30 dtk',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $db = $t->db();
                $backup = $db->table('archives')->where('name', 'Backup Arsip')->where('type', 1)->value('id_archive');
                $direct = $db->table('archives')->where('id_archive_parent', $backup)->where('type', 2)->where('is_active', 1)->count();
                $t->true($direct > 20000, "prasyarat: Backup Arsip punya > 20 rb dokumen ($direct)");
                $timed = function (callable $fn) {
                    $t0 = microtime(true);
                    $r = $fn();

                    return [$r, microtime(true) - $t0];
                };

                // folders root: superadmin 1 (semua) dan user biasa
                foreach ([null, 1] as $role) {
                    $run = function () use ($t, $s, $timed, $role) {
                        list($r, $sec) = $timed(function () use ($t, $s) {
                            return q26_folders($t, $s, null);
                        });
                        $t->status($r, 200, 'folders root (' . ($role ?? 'role 3') . ')');
                        $t->true($sec < 30, sprintf('folders root (%s): %.2f dtk < 30', $role ?? 'role 3', $sec));
                        $t->note(sprintf('folders root %s: %.2f dtk', $role ?? 'role 3', $sec));
                        list($r, $sec) = $timed(function () use ($t, $s) {
                            return q26_folders($t, $s, $t->db()->table('archives')->where('name', 'Backup Arsip')->where('type', 1)->value('id_archive'));
                        });
                        $t->status($r, 200, 'folders Backup Arsip');
                        $t->true($sec < 30, sprintf('folders Backup Arsip: %.2f dtk < 30', $sec));
                        $t->note(sprintf('folders Backup Arsip: %.2f dtk', $sec));
                    };
                    $role === null ? $run() : q26_with_user($t, ['role' => $role], $run);
                }

                // sesi Backup Arsip dengan semua subfolder; scan 30 dokumen asli + 1 dokumen luar cakupan
                $children = q26_child_ids(q26_folders($t, $s, $backup));
                list($r, $sec) = $timed(function () use ($t, $s, $backup, $children) {
                    return q26_store($t, $s, $backup, array_map(function ($c) {
                        return ['id_archive' => $c, 'is_continue' => false];
                    }, $children));
                });
                $t->status($r, 200, 'store Backup Arsip');
                $t->true($sec < 30, sprintf('store: %.2f dtk < 30', $sec));
                $t->note(sprintf('store Backup Arsip: %.2f dtk', $sec));
                $id = $r[1]['result']['id_archive_opname'];

                $names = $db->table('archives')->where('id_archive_parent', $backup)->where('type', 2)->where('is_active', 1)->orderBy('name')->limit(30)->pluck('id_archive', 'name')->all();
                $foreign = $db->table('archives')->where('type', 2)->where('is_active', 1)->where('id_archive_parent', '!=', $backup)->value('name');
                foreach (array_keys($names) as $code) {
                    $t->status(q26_scan($t, $s, $id, (string) $code), 200, "scan $code");
                }
                $r = q26_scan($t, $s, $id, $foreign);
                $t->eq($r[1]['result']['row']['result'], 'not_found', 'dokumen folder lain -> not_found');

                list($show, $sec) = $timed(function () use ($t, $s, $id) {
                    return q26_show($t, $s, $id);
                });
                $t->status($show, 200, 'show draft');
                $t->true($sec < 30, sprintf('show draft: %.2f dtk < 30', $sec));
                $t->note(sprintf('show draft: %.2f dtk', $sec));
                $o = q26_oracle($t, $backup, ['SMR', 'MGL', 'JOG']);
                $total = $o['direct'] + array_sum(array_map(function ($v) {
                    return $v[1];
                }, $o['children']));
                $c = $show[1]['result']['counts'];
                $t->eq($c['total_documents'], $total, 'show.total_documents = oracle (dokumen langsung + subtree anak terlihat)');
                $t->eq([$c['verified'], $c['not_found'], $c['scanned'], $c['unscanned']], [30, 1, 31, $total - 30], 'show.counts draft');

                list($docs, $sec) = $timed(function () use ($t, $s, $id) {
                    return q26_docs($t, $s, $id, ['result' => 'all', 'pagination' => 50]);
                });
                $t->status($docs, 200, 'documents all');
                $t->eq($docs[1]['result']['total'], $total + 1, 'documents all: total = cakupan + not found');
                $t->true($sec < 30, sprintf('documents all hal 1: %.2f dtk < 30', $sec));
                $t->note(sprintf('documents all p1: %.2f dtk', $sec));
                list($docs, $sec) = $timed(function () use ($t, $s, $id) {
                    return q26_docs($t, $s, $id, ['result' => 'unscanned', 'pagination' => 100, 'page' => 150]);
                });
                $t->status($docs, 200, 'documents unscanned halaman dalam');
                $t->eq(count($docs[1]['result']['data']), 100, 'unscanned halaman 150: 100 baris');
                $t->true($sec < 30, sprintf('documents unscanned hal 150: %.2f dtk < 30', $sec));
                $t->note(sprintf('documents unscanned p150: %.2f dtk', $sec));

                // Confirm
                list($conf, $sec) = $timed(function () use ($t, $s, $id) {
                    return q26_confirm($t, $s, $id);
                });
                q26_no500($t, $conf, 'confirm Backup Arsip');
                $t->status($conf, 200, 'confirm Backup Arsip');
                $t->true($sec < 30, sprintf('confirm Backup Arsip: %.2f dtk < 30', $sec));
                $t->note(sprintf('confirm Backup Arsip (%d dokumen): %.2f dtk', $total, $sec));

                $o = q26_opname($t, $id);
                $t->eq([(int) $o['status'], (int) $o['total_documents'], (int) $o['verified_count'], (int) $o['not_found_count'], (int) $o['invalid_count'], (int) $o['unscanned_count'], (int) $o['scanned_count']],
                    [2, $total, 30, 1, 0, $total - 30, 31], 'DB: angka final sesi = oracle');
                $t->eq($db->table('archives')->where('id_archive_opname', $id)->count(), $total, 'DB: tepat dokumen cakupan yang ditandai sesi');
                $t->eq($db->table('archives')->where('id_archive_opname', $id)->where('is_verified', 1)->count(), 30, 'DB: 30 dokumen verified');
                $t->eq($db->table('archive_opname_documents')->where('id_archive_opname', $id)->count(), $total + 1, 'DB: snapshot = cakupan + 1 not found');
                $t->eq($db->table('archive_opname_documents')->where('id_archive_opname', $id)->where('result', 4)->count(), $total - 30, 'DB: baris belum discan = total - 30');
                $folders = q26_opfolders($t, $id);
                $t->eq((int) array_sum(array_column($folders, 'total_documents')), $total, 'DB: jumlah total_documents per folder = total');
                $t->eq((int) array_sum(array_column($folders, 'verified_count')), 30, 'DB: jumlah verified_count per folder = 30');
                $t->eq($db->table('archives')->where('id_archive', array_values($names)[0])->value('is_verified'), 1, 'dokumen yang discan verified');
                $t->eq(count(array_unique(array_column($folders, 'id_archive_opname_folder'))), count($folders), 'id baris snapshot folder unik');
                $t->eq($db->table('archive_opname_documents')->where('id_archive_opname', $id)->distinct()->count('id_archive_opname_document'), $total + 1, 'id baris snapshot dokumen unik (tanpa tabrakan)');

                // pembacaan hasil sesi terkonfirmasi (snapshot) juga < 30 dtk
                list($docs, $sec) = $timed(function () use ($t, $s, $id) {
                    return q26_docs($t, $s, $id, ['result' => 'unscanned', 'pagination' => 100, 'page' => 150]);
                });
                $t->status($docs, 200, 'snapshot unscanned');
                $t->true($sec < 30, sprintf('snapshot unscanned hal 150: %.2f dtk < 30', $sec));
                $t->note(sprintf('snapshot unscanned p150: %.2f dtk', $sec));
                list($show, $sec) = $timed(function () use ($t, $s, $id) {
                    return q26_show($t, $s, $id);
                });
                $t->true($show[0] === 200 && $sec < 30, sprintf('show terkonfirmasi: %.2f dtk', $sec));
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'EXTRA-CONCURRENT',
        'title' => '(tambahan AC-22, BR-23) dua Confirm bersamaan pada folder yang sama: tepat satu 200, satu 400 ARCHIVE414; tidak ada 500; hasil verified konsisten',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                // dokumen tambahan agar ada baris yang dikunci
                for ($i = 0; $i < 30; $i++) {
                    q26_doc($t, 'BULK' . $i, ['parent' => $x['S1']]);
                }
                $base = rtrim($t->conf('API_URL'), '/');
                $token = $s->token;
                $outcomes = [];
                for ($round = 0; $round < 3; $round++) {
                    sleep(2); // jeda: detik yang sama dengan confirm putaran lalu dihitung bentrok
                    $a = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                    $b = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                    q26_scan($t, $s, $a, q26_n($t, $x['d11']));
                    q26_scan($t, $s, $b, q26_n($t, $x['dA1']));

                    $mh = curl_multi_init();
                    $handles = [];
                    foreach ([$a, $b] as $sid) {
                        $ch = curl_init($base . '/' . Q26_BASE . '/opnames/' . $sid . '/confirm');
                        curl_setopt_array($ch, [
                            CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
                            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $token],
                        ]);
                        curl_multi_add_handle($mh, $ch);
                        $handles[$sid] = $ch;
                    }
                    do {
                        $status = curl_multi_exec($mh, $running);
                        if ($running) {
                            curl_multi_select($mh, 0.05);
                        }
                    } while ($running && $status === CURLM_OK);
                    $codes = [];
                    $msg = [];
                    foreach ($handles as $sid => $ch) {
                        $codes[$sid] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $json = json_decode(curl_multi_getcontent($ch), true);
                        $msg[$sid] = $json['msg_code'] ?? ($json['code'] ?? null);
                        curl_multi_remove_handle($mh, $ch);
                        curl_close($ch);
                    }
                    curl_multi_close($mh);
                    $vals = array_values($codes);
                    sort($vals);
                    $outcomes[] = json_encode([$vals, array_values($msg)]);
                    $t->true(max($vals) < 500, "putaran $round: tanpa 5xx (" . json_encode($vals) . ')');
                    $t->eq($vals, [200, 400], "putaran $round: tepat satu 200 dan satu 400 (" . json_encode($msg) . ')');
                    $t->eq(array_values(array_diff($msg, ['ARCHIVE213'])), ['ARCHIVE414'], "putaran $round: yang ditolak ARCHIVE414");
                    $statuses = [(int) q26_opname($t, $a)['status'], (int) q26_opname($t, $b)['status']];
                    sort($statuses);
                    $t->eq($statuses, [1, 2], "putaran $round: satu sesi terkonfirmasi, satu tetap draft");
                    // ubah-ubah: kembalikan agar putaran berikut bersih
                    q26_cancel($t, $s, $a);
                    q26_cancel($t, $s, $b);
                }
                $t->note('hasil putaran: ' . implode(' | ', $outcomes));
            } finally {
                q26_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'EXTRA-PARALLEL-SCAN',
        'title' => '(tambahan AC-11) scan kode yang sama bersamaan (klik ganda / frame kamera berulang): semua 200, tepat satu bukan duplikat, tanpa 500 (UNIQUE id_archive_opname+code)',
        'run'   => function ($t) {
            $s = $t->session();
            q26_baseline($t);
            try {
                $x = q26_tree($t);
                $id = q26_session($t, $s, $x['F'], q26_sel([$x['S1']]));
                $base = rtrim($t->conf('API_URL'), '/');
                $token = $s->token;
                $statuses = [];
                $firsts = [];
                $codes = [q26_n($t, $x['dF1']), q26_n($t, $x['d21']), 'QA26-PARALEL-INVALID', q26_n($t, $x['d11']), 'QA26-PARALEL-2'];
                foreach ($codes as $round => $code) {
                    $mh = curl_multi_init();
                    $handles = [];
                    for ($i = 0; $i < 5; $i++) {
                        $ch = curl_init($base . '/' . Q26_BASE . '/opnames/' . $id . '/scan');
                        curl_setopt_array($ch, [
                            CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
                            CURLOPT_POSTFIELDS => json_encode(['code' => $code]),
                            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $token],
                        ]);
                        curl_multi_add_handle($mh, $ch);
                        $handles[] = $ch;
                    }
                    do {
                        $st = curl_multi_exec($mh, $running);
                        if ($running) {
                            curl_multi_select($mh, 0.05);
                        }
                    } while ($running && $st === CURLM_OK);
                    $http = [];
                    $dups = 0;
                    foreach ($handles as $ch) {
                        $http[] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $json = json_decode(curl_multi_getcontent($ch), true);
                        if (($json['result']['is_duplicate'] ?? null) === true) {
                            $dups++;
                        }
                        curl_multi_remove_handle($mh, $ch);
                        curl_close($ch);
                    }
                    curl_multi_close($mh);
                    $statuses[] = json_encode($http);
                    $t->true(max($http) < 500, "putaran $round: 5 scan bersamaan tanpa 5xx (" . json_encode($http) . ')');
                    $t->eq($http, [200, 200, 200, 200, 200], "putaran $round: semua 200");
                    $t->eq($dups, 4, "putaran $round: tepat satu bukan duplikat");
                }
                $t->eq($t->db()->table('archive_opname_documents')->where('id_archive_opname', $id)->count(), count($codes), 'DB: satu baris per kode');
                $t->note('HTTP per putaran: ' . implode(' | ', $statuses));
            } finally {
                q26_cleanup($t);
            }
        },
    ],
];
