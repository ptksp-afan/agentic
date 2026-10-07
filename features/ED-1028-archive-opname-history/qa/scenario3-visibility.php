<?php
/**
 * ED-1028 - visibilitas & konteks folder: AC-8 (superadmin 1/2 semua, user lain sesuai K-4 + EPIC K-1 a, 3 baris pertama = last_sessions
 * GET verifications), AC-9 (id_archive=F: hanya sesi yang mencakup F, = last_sessions GET verifications/{F}; 404/400/403), AC-13
 * (sesi root superadmin dibaca user lain: baris di luar scope disaring, result_counts, is_partial).
 * Sesi dibuat lewat API opname ED-1026 oleh superadmin sementara (role 1). user2 = QA_USER2 sungguhan (SMR, List Archive tanpa
 * Opname Document); user3 = QA_USER role 3 (semua lokasi, bukan superadmin). Semua dipulihkan di finally.
 */
require_once __DIR__ . '/qa_hist.php';

/** Dua sesi dari folder yang kemudian dihapus (G1 milik qa28erin, G2 milik user uji sendiri). */
$q28_add_deleted = function ($t, $s, array &$x, array &$ids) {
    $x['G1F'] = q28_folder($t, 'G1F');
    $x['G2F'] = q28_folder($t, 'G2F');
    $ids['G1'] = q28_run($t, $s, $x['G1F'], [], []);
    $ids['G2'] = q28_run($t, $s, $x['G2F'], [], []);
    q28_set_opname($t, $ids['G1'], ['confirmed_at' => date('Y-m-d', strtotime('-2 days')) . ' 09:00:00', 'created_by' => 'qa28erin']);
    q28_set_opname($t, $ids['G2'], ['confirmed_at' => date('Y-m-d', strtotime('-1 day')) . ' 09:00:00']);
    q28_w($t, function ($c) use ($x) {
        $c->table('archives')->whereIn('id_archive', [$x['G1F'], $x['G2F']])->delete();
    });
};

$q28_norm = function ($v) use (&$q28_norm) {
    if (is_array($v)) {
        ksort($v);
        foreach ($v as $k => $item) {
            $v[$k] = $q28_norm($item);
        }
    }

    return $v;
};

$q28_default_order = function ($t, array $ids) {
    $rows = $t->db()->table('archive_opnames')->whereIn('id_archive_opname', $ids ?: ['-'])->get(['id_archive_opname', 'confirmed_at'])->all();
    usort($rows, function ($a, $b) {
        return strcmp($b->confirmed_at, $a->confirmed_at) ?: strcmp($b->id_archive_opname, $a->id_archive_opname);
    });

    return array_map(function ($r) {
        return $r->id_archive_opname;
    }, $rows);
};

/** Baris snapshot dokumen sesi yang boleh dilihat $u: [result(1-4) => [id_archive_opname_document, ...]] + jumlah tersembunyi. */
$q28_rows_oracle = function ($t, $idOpname, array $u) {
    $c = $t->db();
    $o = q28_oracle($t, $u);
    $locIds = null;
    if ($u['locs'] !== null) {
        $map = q28_locs($t);
        $locIds = array_map(function ($code) use ($map) {
            return $map[$code];
        }, $u['locs']);
    }
    $archiveLocs = [];
    foreach ($c->table('archive_locations')->get(['id_archive', 'id_location']) as $r) {
        $archiveLocs[$r->id_archive][] = $r->id_location;
    }
    $rows = $c->table('archive_opname_documents as d')->leftJoin('archives as a', 'a.id_archive', '=', 'd.id_archive')
        ->where('d.id_archive_opname', $idOpname)
        ->get(['d.id_archive_opname_document as id', 'd.id_archive', 'd.id_archive_folder', 'd.result', 'a.is_all_location as doc_all']);
    $shown = [1 => [], 2 => [], 3 => [], 4 => []];
    $hidden = 0;
    foreach ($rows as $r) {
        $show = true;
        if (!$u['bypass'] && $r->id_archive !== null) {
            $locOk = $locIds === null || (int) $r->doc_all === 1 || count(array_intersect($archiveLocs[$r->id_archive] ?? [], $locIds)) > 0;
            $folderOk = $r->id_archive_folder === null || isset($o['visible'][$r->id_archive_folder]);
            $show = $locOk && $folderOk;
        }
        if ($show) {
            $shown[(int) $r->result][] = $r->id;
        } else {
            $hidden++;
        }
    }

    return ['shown' => $shown, 'hidden' => $hidden];
};

/** Semua id baris dokumen (semua halaman) untuk filter result. */
$q28_all_docs = function ($t, $s, $idOpname, $result, $per = 500) {
    $ids = [];
    $total = null;
    for ($page = 1; $page < 400; $page++) {
        $r = $t->call($s, 'GET', q28_url(Q28_BASE . '/opnames/' . $idOpname . '/documents', ['result' => $result, 'pagination' => $per, 'page' => $page]));
        $t->status($r, 200, "documents $result hal $page");
        $total = $r[1]['result']['total'];
        $data = $r[1]['result']['data'];
        foreach ($data as $row) {
            $ids[] = $row['id_archive_opname_document'];
        }
        if (count($data) === 0 || $page >= $r[1]['result']['last_page']) {
            break;
        }
    }

    return [$ids, $total];
};

return [

    [
        'id'    => 'AC-8',
        'title' => 'visibilitas: superadmin (role 1 dan 2) semua sesi terkonfirmasi; user lain sesuai 04 K-4 + EPIC K-1 a (root semua, folder terlihat, pembuat, folder terhapus/tak terlihat hanya superadmin & pembuat) = oracle; 3 baris pertama halaman 1 = last_sessions GET verifications untuk user yang sama',
        'run'   => function ($t) use ($q28_add_deleted, $q28_default_order, $q28_norm) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s, $q28_add_deleted, $q28_default_order, $q28_norm) {
                $x = null;
                $ids = null;
                $set = null;
                q28_with_user($t, ['role' => 1], function ($setter) use ($t, $s, $q28_add_deleted, $q28_default_order, $q28_norm, &$x, &$ids) {
                    $x = q28_tree($t);
                    $ids = q28_hfix($t, $s, $x);
                    $q28_add_deleted($t, $s, $x, $ids);

                    $check = function ($label, $session, array $u, array $expectKeys) use ($t, $ids, $q28_default_order, $q28_norm) {
                        $r = q28_hist($t, $session, ['pagination' => 100]);
                        $t->status($r, 200, "$label: list");
                        $exp = $q28_default_order($t, q28_oracle_sessions($t, $u));
                        $t->eq(q28_hkeys(q28_hids($r), $ids), q28_hkeys($exp, $ids), "$label: sesi terlihat = oracle");
                        $t->eq(q28_hkeys(q28_hids($r), $ids), $expectKeys, "$label: sesi terlihat = angka tangan " . implode(',', $expectKeys));
                        $t->eq($r[1]['result']['total'], count($exp), "$label: total");
                        // 3 baris pertama = last_sessions GET verifications
                        $v = q28_verif($t, $session);
                        $t->status($v, 200, "$label: GET verifications");
                        $ls = $v[1]['result']['last_sessions'];
                        $first = array_map('q28_srow', array_slice($r[1]['result']['data'], 0, 3));
                        $t->eq(json_encode($q28_norm($first)), json_encode($q28_norm($ls)), "$label: 3 baris pertama = last_sessions (id, waktu, user, scope, angka)");
                        $t->eq(count($ls), min(3, count($exp)), "$label: last_sessions maksimal 3");

                        return $r;
                    };

                    $all = ['G2', 'Z', 'Y', 'E', 'D', 'C', 'B', 'A'];
                    $withG1 = ['G2', 'G1', 'Z', 'Y', 'E', 'D', 'C', 'B', 'A'];
                    $confirmed = $t->db()->table('archive_opnames')->where('status', 2)->count();
                    $t->eq($confirmed, 9, 'prasyarat: 9 sesi terkonfirmasi di DB (A,B,C,D,E,Y,Z,G1,G2)');

                    $r = $check('superadmin role 1', $s, q28_uu($t, 'super'), $withG1);
                    $t->eq($r[1]['result']['total'], $confirmed, 'role 1: total = semua sesi terkonfirmasi');
                    $setter(['role' => 2]);
                    $r = $check('superadmin role 2', $s, q28_uu($t, 'super'), $withG1);
                    $t->eq($r[1]['result']['total'], $confirmed, 'role 2 (Technical Support): total = semua sesi terkonfirmasi');

                    // user3 (role 3, semua lokasi, bukan superadmin): E (SP tanpa View) & G1 (folder terhapus, bukan pembuat) tak terlihat; G2 (pembuat) terlihat
                    $setter([]);
                    $check('user3 (role 3)', $s, q28_uu($t, 'user3'), ['G2', 'Z', 'Y', 'D', 'C', 'B', 'A']);
                    // user3 sebagai pembuat sesi di folder yang tak terlihat: ubah pemilik E ke user uji -> E ikut terlihat (pembuat)
                    q28_set_owner($t, $ids['E'], (string) q28_uname($t));
                    $check('user3 pembuat E', $s, q28_uu($t, 'user3'), ['G2', 'Z', 'Y', 'E', 'D', 'C', 'B', 'A']);
                    q28_set_owner($t, $ids['E'], 'qa28bob');
                });

                // user2 sungguhan (SMR)
                $s2 = q28_u2_session($t);
                $u2 = q28_uu($t, 'user2');
                $r = q28_hist($t, $s2, ['pagination' => 100]);
                $t->status($r, 200, 'user2: list');
                $exp = $q28_default_order($t, q28_oracle_sessions($t, $u2));
                $t->eq(q28_hkeys(q28_hids($r), $ids), q28_hkeys($exp, $ids), 'user2: sesi terlihat = oracle');
                $t->eq(q28_hkeys(q28_hids($r), $ids), ['Z', 'Y', 'C', 'B', 'A'], 'user2: A, B, C (root), Y, Z; bukan D (SJ lokasi JOG), E (SP tanpa View), G1/G2 (folder terhapus)');
                $t->eq($r[1]['result']['total'], 5, 'user2: total 5');
                $v = q28_verif($t, $s2);
                $t->status($v, 200, 'user2: GET verifications');
                $first = array_map('q28_srow', array_slice($r[1]['result']['data'], 0, 3));
                $t->eq(json_encode($q28_norm($first)), json_encode($q28_norm($v[1]['result']['last_sessions'])), 'user2: 3 baris pertama = last_sessions');
                // halaman 2 melanjutkan (sesi ke-4 dan ke-5)
                $p2 = q28_hist($t, $s2, ['pagination' => 3, 'page' => 2]);
                $t->eq(q28_hkeys(q28_hids($p2), $ids), ['B', 'A'], 'user2: halaman 2 (pagination 3) = B, A');
                $t->call($s2, 'GET', 'api/v5/auth/log-out');
            });
        },
    ],

    [
        'id'    => 'AC-9',
        'title' => 'konteks id_archive=F: hanya sesi yang mencakup F (F atau subfolder terlihat) = oracle; 3 baris pertama = last_sessions GET verifications/{F}; filter lain tetap berlaku (AND); 404 ARCHIVE400, 400 ARCHIVE417, 403 ARCHIVE407',
        'run'   => function ($t) use ($q28_default_order, $q28_norm) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s, $q28_default_order, $q28_norm) {
                $x = null;
                $ids = null;
                q28_with_user($t, ['role' => 1], function ($setter) use ($t, $s, $q28_default_order, $q28_norm, &$x, &$ids) {
                    $x = q28_tree($t);
                    $ids = q28_hfix($t, $s, $x);
                    $check = function ($label, $session, array $u, $folder, array $expectKeys) use ($t, $x, $ids, $q28_default_order, $q28_norm) {
                        $r = q28_hist($t, $session, ['pagination' => 100, 'id_archive' => $folder]);
                        $t->status($r, 200, "$label: list");
                        $exp = $q28_default_order($t, q28_oracle_sessions($t, $u, [$folder]));
                        $t->eq(q28_hkeys(q28_hids($r), $ids), q28_hkeys($exp, $ids), "$label: sesi yang mencakup folder = oracle");
                        $t->eq(q28_hkeys(q28_hids($r), $ids), $expectKeys, "$label: = angka tangan " . implode(',', $expectKeys));
                        $t->eq($r[1]['result']['total'], count($exp), "$label: total");
                        $v = q28_verif($t, $session, $folder);
                        $t->status($v, 200, "$label: GET verifications/{id}");
                        $ls = array_map('q28_srow', $v[1]['result']['last_sessions']);
                        $first = array_map('q28_srow', array_slice($r[1]['result']['data'], 0, 3));
                        $t->eq(json_encode($q28_norm($first)), json_encode($q28_norm($ls)), "$label: 3 baris pertama = last_sessions verifications/{F}");
                    };
                    // superadmin: F -> A (F), B (S2), Y (S1a), D (SJ), E (SP); S1 -> A, Y; OTH -> Z, C; S1a -> Y, A
                    $check('superadmin F', $s, q28_uu($t, 'super'), $x['F'], ['Y', 'E', 'D', 'B', 'A']);
                    $check('superadmin S1', $s, q28_uu($t, 'super'), $x['S1'], ['Y', 'A']);
                    $check('superadmin OTH', $s, q28_uu($t, 'super'), $x['OTH'], ['Z', 'C']);
                    $check('superadmin S1a', $s, q28_uu($t, 'super'), $x['S1a'], ['Y', 'A']);
                    $check('superadmin P (induk)', $s, q28_uu($t, 'super'), $x['P'], ['Y', 'E', 'D', 'B', 'A']);
                    $setter([]);
                    $check('user3 F', $s, q28_uu($t, 'user3'), $x['F'], ['Y', 'D', 'B', 'A']);
                    $check('user3 OTH', $s, q28_uu($t, 'user3'), $x['OTH'], ['Z', 'C']);
                    // folder kosong tanpa sesi -> kosong 200
                    $nf = q28_folder($t, 'EMPTY', ['parent' => $x['P']]);
                    $check('user3 folder tanpa sesi', $s, q28_uu($t, 'user3'), $nf, []);

                    // konteks + filter lain = AND
                    $r = q28_hist($t, $s, ['id_archive' => $x['F'], 'pagination' => 100, 'search' => ['query' => 'alice']]);
                    $t->eq(q28_hkeys(q28_hids($r), $ids), ['A'], 'F + query alice -> A');
                    $r = q28_hist($t, $s, ['id_archive' => $x['F'], 'pagination' => 100, 'search' => ['idArchives' => [$x['S2']]]]);
                    $t->eq(q28_hkeys(q28_hids($r), $ids), ['B'], 'F + idArchives [S2] -> B');
                    $r = q28_hist($t, $s, ['id_archive' => $x['F'], 'pagination' => 100, 'search' => ['idArchives' => [$x['OTH']]]]);
                    $t->eq(q28_hkeys(q28_hids($r), $ids), [], 'F + idArchives [OTH] -> kosong (konteks terkunci)');
                    $T = q28_times();
                    $r = q28_hist($t, $s, ['id_archive' => $x['F'], 'pagination' => 100, 'search' => ['confirmedAt' => [$T['B'], $T['D']]]]);
                    $t->eq(q28_hkeys(q28_hids($r), $ids), ['D', 'B'], 'F + confirmedAt [B, D] -> D, B');
                    $r = q28_hist($t, $s, ['id_archive' => '', 'pagination' => 100]);
                    $t->eq(count(q28_hids($r)), 6, 'id_archive kosong = tanpa konteks (6 sesi terlihat user3)');
                    $r = q28_hist($t, $s, ['id_archive' => $x['F'], 'pagination' => 2, 'page' => 2]);
                    $t->eq(q28_hkeys(q28_hids($r), $ids), ['B', 'A'], 'F + pagination 2 halaman 2 -> B, A');
                    $t->eq($r[1]['result']['total'], 4, 'F user3: total 4');

                    // penolakan (superadmin / user3)
                    $deny = function ($label, $session, $param, $http, $code) use ($t) {
                        $r = q28_hist($t, $session, ['id_archive' => $param]);
                        q28_no500($t, $r, $label);
                        q28_deny($t, $r, $http, $code, $label);
                        $t->true(empty($r[1]['result']), "$label: tanpa data");
                        $t->true(!empty($r[1]['message']), "$label: ada pesan");

                        return $r;
                    };
                    $deny('id tak ada', $s, '999999999999999999999', 404, 'ARCHIVE400');
                    $deny('id dokumen', $s, $x['dF1'], 400, 'ARCHIVE417');
                    $deny('folder nonaktif', $s, $x['SX'], 404, 'ARCHIVE400');
                    $deny('id sangat panjang', $s, str_repeat('9', 200), 404, 'ARCHIVE400');
                    $deny('id non-ASCII', $s, '日本語', 404, 'ARCHIVE400');
                    $deny('id berisi kutip', $s, "' OR 1=1 --", 404, 'ARCHIVE400');
                    $o = $t->call($s, 'GET', Q28_BASE . '/opnames?id_archive[]=x');
                    q28_no500($t, $o, 'id_archive array');
                    q28_deny($t, $o, 404, 'ARCHIVE400', 'id_archive array');
                    // user3 tanpa View ke SP -> 403 ARCHIVE407
                    $deny('user3 SP tanpa View', $s, $x['SP'], 403, 'ARCHIVE407');
                    $deny('user3 SPc (turunan SP)', $s, $x['SPc'], 403, 'ARCHIVE407');
                    $deny('user3 PRIV', $s, $x['PRIV'], 403, 'ARCHIVE407');
                    // konteks diperiksa sebelum nilai search: tanpa hak + search salah = 403 (bukan 422)
                    $o = q28_hist($t, $s, ['id_archive' => $x['SP'], 'search' => '{bad']);
                    q28_deny($t, $o, 403, 'ARCHIVE407', 'tanpa hak + search salah -> 403 dulu');
                    $o = q28_hist($t, $s, ['id_archive' => $x['F'], 'search' => '{bad']);
                    $t->status($o, 422, 'konteks sah + search salah -> 422');
                    // superadmin: SP boleh
                    $setter(['role' => 1]);
                    $r = q28_hist($t, $s, ['id_archive' => $x['SP'], 'pagination' => 100]);
                    $t->eq(q28_hkeys(q28_hids($r), $ids), ['E'], 'superadmin SP -> E');
                });

                // user2 sungguhan (SMR, bukan superadmin)
                $s2 = q28_u2_session($t);
                $u2 = q28_uu($t, 'user2');
                $r = q28_hist($t, $s2, ['id_archive' => $x['F'], 'pagination' => 100]);
                $t->status($r, 200, 'user2 F');
                $t->eq(q28_hkeys(q28_hids($r), $ids), ['Y', 'B', 'A'], 'user2 F -> Y, B, A (D & E tak terlihat)');
                $t->eq(q28_hkeys($q28_default_order($t, q28_oracle_sessions($t, $u2, [$x['F']])), $ids), ['Y', 'B', 'A'], 'oracle user2 F');
                $v = q28_verif($t, $s2, $x['F']);
                $t->status($v, 200, 'user2: GET verifications/{F}');
                $t->eq(json_encode($q28_norm(array_map('q28_srow', array_slice($r[1]['result']['data'], 0, 3)))), json_encode($q28_norm(array_map('q28_srow', $v[1]['result']['last_sessions']))), 'user2 F: 3 baris pertama = last_sessions');
                foreach (['SJ' => [403, 'ARCHIVE407'], 'JOGR' => [403, 'ARCHIVE407'], 'SP' => [403, 'ARCHIVE407'], 'SPc' => [403, 'ARCHIVE407'], 'PRIV' => [403, 'ARCHIVE407'], 'PRc' => [403, 'ARCHIVE407'], 'SV' => [403, 'ARCHIVE407'], 'SX' => [404, 'ARCHIVE400'], 'dF1' => [400, 'ARCHIVE417']] as $k => $exp) {
                    $o = q28_hist($t, $s2, ['id_archive' => $x[$k]]);
                    q28_no500($t, $o, "user2 $k");
                    q28_deny($t, $o, $exp[0], $exp[1], "user2 $k");
                    $t->true(empty($o[1]['result']), "user2 $k: tanpa data");
                }
                $o = q28_hist($t, $s2, ['id_archive' => $x['SP']]);
                $t->eq($o[1]['message'], "You don't have access to this folder or document", 'pesan ARCHIVE407 bahasa EN');
                $o = q28_hist($t, $s2, ['id_archive' => '999999999999']);
                $t->eq($o[1]['message'], "Document isn't found", 'pesan ARCHIVE400 bahasa EN');
                $r = q28_hist($t, $s2, ['id_archive' => $x['OTH'], 'pagination' => 100]);
                $t->eq(q28_hkeys(q28_hids($r), $ids), ['Z', 'C'], 'user2 OTH -> Z, C');
                $t->call($s2, 'GET', 'api/v5/auth/log-out');
            });
        },
    ],

    [
        'id'    => 'AC-13',
        'title' => 'sesi root superadmin mencakup lokasi/folder lain dibaca user lain: documents hanya baris dalam scope (+ Invalid), result_counts = jumlah baris itu per filter, is_partial=true; superadmin semua, is_partial=false, result_counts = counts; kartu counts tetap angka rekaman',
        'run'   => function ($t) use ($q28_rows_oracle, $q28_all_docs) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s, $q28_rows_oracle, $q28_all_docs) {
                $x = null;
                $P1 = null;
                $A = null;
                q28_with_user($t, ['role' => 1], function ($setter) use ($t, $s, $q28_rows_oracle, $q28_all_docs, &$x, &$P1, &$A) {
                    $x = q28_tree($t);
                    $N = function ($k) use ($t, $x) {
                        return q28_n($t, $x[$k]);
                    };
                    // P1: root + OTH + JOGR + PRIV; scan: dO1, dR (terlihat user2), dJR (JOG), dPR (PRIV), dJF & dF1 (di luar scope: not found), kode tak dikenal
                    $P1 = q28_run($t, $s, null, q28_sel([$x['OTH'], $x['JOGR'], $x['PRIV']]), [
                        $N('dO1'), $N('dR'), $N('dJR'), $N('dPR'), $N('dJF'), $N('dF1'), 'QA28-NOPE-' . uniqid(),
                    ]);
                    // A: F + S1 (kontrol sesi di folder bersama)
                    $A = q28_run($t, $s, $x['F'], q28_sel([$x['S1']]), [$N('dF2'), $N('d11')]);

                    $db = q28_opname($t, $P1);
                    $t->true((int) $db['not_found_count'] === 2 && (int) $db['invalid_count'] === 1 && (int) $db['verified_count'] === 4, 'P1: rekaman verified 4, not found 2, invalid 1 (' . json_encode(array_intersect_key($db, array_flip(['total_documents', 'verified_count', 'not_found_count', 'invalid_count', 'unscanned_count']))) . ')');

                    $show = $t->call($s, 'GET', Q28_BASE . '/opnames/' . $P1);
                    $t->status($show, 200, 'superadmin: show P1');
                    $counts = $show[1]['result']['counts'];
                    $rc = $show[1]['result']['result_counts'];
                    $t->eq($show[1]['result']['is_partial'], false, 'superadmin: is_partial false');
                    $t->eq([$rc['verified'], $rc['not_found'], $rc['invalid'], $rc['unscanned']], [$counts['verified'], $counts['not_found'], $counts['invalid'], $counts['unscanned']], 'superadmin: result_counts = counts');
                    $t->eq($rc['scanned'], $counts['scanned'], 'superadmin: scanned = counts.scanned');
                    $t->eq($rc['all'], $rc['scanned'] + $rc['unscanned'], 'superadmin: all = scanned + unscanned');
                    $o = $q28_rows_oracle($t, $P1, q28_uu($t, 'super'));
                    $t->eq($o['hidden'], 0, 'oracle superadmin: tak ada baris tersembunyi');
                    foreach (['all', 'scanned', 'verified', 'not_found', 'invalid', 'unscanned'] as $f) {
                        list($got, $total) = $q28_all_docs($t, $s, $P1, $f);
                        $t->eq($total, $rc[$f], "superadmin documents $f: total = result_counts");
                        $t->eq(count($got), $rc[$f], "superadmin documents $f: jumlah baris = result_counts");
                    }
                    $GLOBALS['q28_p1_counts'] = $counts;
                });

                $show0 = null;
                // pengamat: user2, user3 (pembuat sesi, role 3)
                $s2 = q28_u2_session($t);
                $views = ['user2' => [$s2, q28_uu($t, 'user2')], 'user3' => [$s, q28_uu($t, 'user3')]];
                foreach ($views as $label => $pair) {
                    list($sess, $u) = $pair;
                    $o = $q28_rows_oracle($t, $P1, $u);
                    $t->true($o['hidden'] > 0, "$label: kasus uji punya baris tersembunyi (" . $o['hidden'] . ')');
                    $shown = $o['shown'];
                    $expCounts = [
                        'verified' => count($shown[1]), 'not_found' => count($shown[2]), 'invalid' => count($shown[3]), 'unscanned' => count($shown[4]),
                        'scanned' => count($shown[1]) + count($shown[2]) + count($shown[3]),
                    ];
                    $expCounts['all'] = $expCounts['scanned'] + $expCounts['unscanned'];

                    $show = $t->call($sess, 'GET', Q28_BASE . '/opnames/' . $P1);
                    $t->status($show, 200, "$label: show P1");
                    $res = $show[1]['result'];
                    $t->eq($res['result_counts'], $expCounts, "$label: result_counts = oracle (baris yang boleh dilihat)");
                    $t->eq($res['is_partial'], true, "$label: is_partial true");
                    $t->eq($res['counts'], $GLOBALS['q28_p1_counts'], "$label: kartu counts tetap angka rekaman (sama dengan superadmin)");
                    $t->true(isset($res['scope']['is_root']) && $res['scope']['is_root'] === true, "$label: scope root");

                    $map = ['all' => [1, 2, 3, 4], 'scanned' => [1, 2, 3], 'verified' => [1], 'not_found' => [2], 'invalid' => [3], 'unscanned' => [4]];
                    foreach ($map as $f => $results) {
                        $exp = [];
                        foreach ($results as $k) {
                            $exp = array_merge($exp, $shown[$k]);
                        }
                        list($got, $total) = $q28_all_docs($t, $sess, $P1, $f, in_array($f, ['verified', 'not_found', 'invalid'], true) ? 3 : 500);
                        sort($exp);
                        sort($got);
                        $t->eq($got, $exp, "$label documents $f: baris = oracle (semua halaman)");
                        $t->eq($total, $expCounts[$f], "$label documents $f: total paginator = result_counts[$f]");
                    }
                    // baris Invalid selalu tampil
                    list($inv, $tot) = $q28_all_docs($t, $sess, $P1, 'invalid');
                    $t->eq(count($inv), 1, "$label: baris Invalid tetap tampil");
                    // tanpa filter result = all
                    $r = $t->call($sess, 'GET', Q28_BASE . '/opnames/' . $P1 . '/documents?pagination=100');
                    $t->eq($r[1]['result']['total'], $expCounts['all'], "$label: tanpa result = all");
                }

                // sesi A (F + S1): untuk user2 ada dokumen JOG-only (dJF) di F -> parsial; angka oracle
                $o = $q28_rows_oracle($t, $A, q28_uu($t, 'user2'));
                $show = $t->call($s2, 'GET', Q28_BASE . '/opnames/' . $A);
                $t->status($show, 200, 'user2: show A');
                $t->eq($show[1]['result']['is_partial'], $o['hidden'] > 0, 'user2: sesi A is_partial = (ada baris tersembunyi menurut oracle)');
                $t->eq($show[1]['result']['result_counts']['all'], count($o['shown'][1]) + count($o['shown'][2]) + count($o['shown'][3]) + count($o['shown'][4]), 'user2: sesi A result_counts.all = oracle');
                $t->call($s2, 'GET', 'api/v5/auth/log-out');
                unset($GLOBALS['q28_p1_counts']);
            });
        },
    ],
];
