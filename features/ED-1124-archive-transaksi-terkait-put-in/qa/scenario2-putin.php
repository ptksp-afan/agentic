<?php
/**
 * ED-1124 AC-4 (K-1 a: put-in id_archives sumber dihapus = id tak ada), AC-5 (regresi: -1 / scan name tetap memulihkan),
 * Data uji berawalan QA124-, dibuang di finally.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-4',
        'title' => 'put-in id_archives: semua sumber dihapus -> 404 ARCHIVE400 tanpa perubahan (ke folder T dan root); campuran -> hanya yang aktif dipindah (200), termasuk sumber dihapus yang dulu 400 ARCHIVE405/ARCHIVE403; di luar lokasi -> 403 ARCHIVE407; urutan 403 -> 422 -> 400 -> 404',
        'run'   => function ($t) {
            $s = $t->session();
            q124_purge($t);
            $base = q124_base($t);

            try {
                $f = q124_soft($t);
                $loc = $t->db()->table('locations')->where('is_active', 1)->orderBy('id_location')->value('id_location');
                $t->true($loc !== null, 'prasyarat: ada lokasi aktif');

                $P = q124_folder($t, 'P');
                $T = q124_folder($t, 'T');
                $hist = [['action' => 'place', 'related_username' => 'QA124', 'timestamp' => '01 Jan 2026 00:00:00']];
                $dDel = q124_doc($t, 'DDEL', ['parent' => $P, 'active' => 0, 'history' => $hist]);
                $fDel = q124_folder($t, 'FDEL', ['parent' => $P, 'active' => 0, 'history' => $hist]);
                $fDelChild = q124_folder($t, 'FDELC', ['parent' => $fDel, 'active' => 0]);
                $snapDel = q124_snap($t, [$P, $T, $dDel, $fDel, $fDelChild]);

                // 1. semua dihapus -> 404 ARCHIVE400 (ke T dan ke root), tanpa perubahan
                foreach (['ke folder T' => $T, 'ke root' => null] as $label => $parent) {
                    $r = q124_putin($t, $s, $parent, [$dDel, $fDel]);
                    $f->resp($r, 404, 'ARCHIVE400', "put-in [dokumen dihapus, folder dihapus] $label");
                    $f->eq(q124_msg($r), 'Dokumen tidak ditemukan', "put-in $label: pesan ARCHIVE400 (ID)");
                    $f->ok(!isset($r[1]['result']) || $r[1]['result'] === null || $r[1]['result'] === [], "put-in $label: tanpa result");
                    $f->ok(q124_snap($t, [$P, $T, $dDel, $fDel, $fDelChild]) === $snapDel, "put-in $label: baris archives + archive_documents + archive_locations tidak berubah sama sekali");
                }
                foreach (['[dokumen dihapus]' => [$dDel], '[folder dihapus]' => [$fDel], '[folder dihapus, dua kali id sama]' => [$fDel, $fDel]] as $label => $src) {
                    $r = q124_putin($t, $s, $T, $src);
                    $f->resp($r, 404, 'ARCHIVE400', "put-in $label ke T");
                }
                $f->ok(q124_snap($t, [$P, $T, $dDel, $fDel, $fDelChild]) === $snapDel, 'sesudah semua penolakan: sumber dihapus tetap is_active 0, induk tetap, history tetap, subfolder tetap 0');
                $f->eq([q124_row($t, $dDel)['is_active'], q124_row($t, $fDel)['is_active'], q124_row($t, $fDelChild)['is_active']], [0, 0, 0], 'is_active dokumen, folder, subfolder = 0');

                // 2. campuran ke folder T: yang aktif saja
                $A = q124_doc($t, 'A', ['parent' => $P]);
                $snapX = q124_snap($t, [$dDel, $fDel, $fDelChild]);
                $r = q124_putin($t, $s, $T, [$A, $dDel, $fDel]);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in campuran [A aktif, dokumen dihapus, folder dihapus] ke T');
                $f->eq(array_column($r[1]['result'] ?? [], 'id_archive'), [$A], 'result hanya A');
                $rowA = q124_row($t, $A);
                $f->eq([$rowA['id_archive_parent'], (int) $rowA['is_active'], (int) $rowA['status']], [$T, 1, 1], 'A pindah ke T, aktif, status 1');
                $f->eq(q124_actions($t, $A), ['move'], 'history A = move');
                $hA = q124_history($t, $A);
                $f->eq([$hA[0]['from_folder'] ?? null, $hA[0]['to_folder'] ?? null], [q124_row($t, $P)['name'], q124_row($t, $T)['name']], 'history A: from_folder P, to_folder T');
                $f->ok(q124_snap($t, [$dDel, $fDel, $fDelChild]) === $snapX, 'X (dokumen dihapus, folder dihapus, subfolder) tidak berubah');

                // 3. campuran ke root: take out
                $A2 = q124_doc($t, 'A2', ['parent' => $T]);
                $r = q124_putin($t, $s, null, [$fDel, $A2]);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in campuran [folder dihapus, A2 aktif] ke root');
                $f->eq(array_column($r[1]['result'] ?? [], 'id_archive'), [$A2], 'result hanya A2');
                $f->eq([q124_parent($t, $A2), q124_actions($t, $A2)], [null, ['take out']], 'A2 di root, history take out');
                $f->ok(q124_snap($t, [$dDel, $fDel, $fDelChild]) === $snapX, 'X tetap tidak berubah (ke root)');

                // 4. campuran, sumber dihapus yang DULU gagal 400 ARCHIVE405 (nama kembar di tujuan)
                $T2 = q124_folder($t, 'T2');
                $dupName = q124_name('DUP');
                $dupActive = q124_folder($t, 'DUPA', ['parent' => $T2, 'name' => $dupName]);
                $xDup = q124_folder($t, 'XDUP', ['parent' => $P, 'name' => $dupName]);   // sementara aktif: kontrol
                $A3 = q124_doc($t, 'A3', ['parent' => $P]);
                $snapCtl = q124_snap($t, [$T2, $dupActive, $xDup, $A3]);
                $r = q124_putin($t, $s, $T2, [$A3, $xDup]);
                $f->resp($r, 400, 'ARCHIVE405', 'kontrol: [A3, XDUP aktif] ke T2 = 400 ARCHIVE405 (nama folder kembar di tujuan)');
                $f->ok(q124_snap($t, [$T2, $dupActive, $xDup, $A3]) === $snapCtl, 'kontrol: ditolak tanpa perubahan');
                q124_set($t, $xDup, ['is_active' => 0]);
                $snapXd = q124_snap($t, [$xDup, $dupActive]);
                $r = q124_putin($t, $s, $T2, [$A3, $xDup]);
                $f->resp($r, 200, 'ARCHIVE204', 'XDUP dihapus: [A3, XDUP] ke T2 = 200 (yang aktif saja)');
                $f->eq(array_column($r[1]['result'] ?? [], 'id_archive'), [$A3], 'result hanya A3');
                $f->eq(q124_parent($t, $A3), $T2, 'A3 pindah ke T2');
                $f->ok(q124_snap($t, [$xDup, $dupActive]) === $snapXd, 'XDUP (dihapus) dan folder senama aktif tidak berubah');
                $r = q124_putin($t, $s, $T2, [$xDup]);
                $f->resp($r, 404, 'ARCHIVE400', 'XDUP dihapus saja = 404 ARCHIVE400 (bukan 400 ARCHIVE405)');
                // dua sumber dihapus senama satu sama lain (dulu ARCHIVE405 "sumber senama")
                $xDup2 = q124_folder($t, 'XDUP2', ['parent' => $P, 'name' => $dupName, 'active' => 0]);
                $r = q124_putin($t, $s, $T, [$xDup, $xDup2]);
                $f->resp($r, 404, 'ARCHIVE400', 'dua folder dihapus senama = 404 ARCHIVE400 (bukan 400 ARCHIVE405)');

                // 5. campuran, sumber dihapus yang DULU gagal 400 ARCHIVE403 (lokasi tujuan terbatas)
                $T3 = q124_folder($t, 'T3', ['all_location' => 0, 'locations' => [$loc]]);
                $xLoc = q124_folder($t, 'XLOC', ['parent' => $P, 'all_location' => 1]);       // sementara aktif: kontrol
                $A4 = q124_doc($t, 'A4', ['parent' => $P, 'all_location' => 0, 'locations' => [$loc]]);
                $snapCtl = q124_snap($t, [$T3, $xLoc, $A4]);
                $r = q124_putin($t, $s, $T3, [$A4, $xLoc]);
                $f->resp($r, 400, 'ARCHIVE403', 'kontrol: [A4, XLOC aktif semua lokasi] ke T3 (terbatas) = 400 ARCHIVE403');
                $f->ok(q124_snap($t, [$T3, $xLoc, $A4]) === $snapCtl, 'kontrol lokasi: ditolak tanpa perubahan');
                q124_set($t, $xLoc, ['is_active' => 0]);
                $snapXl = q124_snap($t, [$xLoc]);
                $r = q124_putin($t, $s, $T3, [$A4, $xLoc]);
                $f->resp($r, 200, 'ARCHIVE204', 'XLOC dihapus: [A4, XLOC] ke T3 = 200 (yang aktif saja)');
                $f->eq(array_column($r[1]['result'] ?? [], 'id_archive'), [$A4], 'result hanya A4');
                $f->eq(q124_parent($t, $A4), $T3, 'A4 pindah ke T3');
                $f->ok(q124_snap($t, [$xLoc]) === $snapXl, 'XLOC (dihapus) tidak berubah');
                $r = q124_putin($t, $s, $T3, [$xLoc]);
                $f->resp($r, 404, 'ARCHIVE400', 'XLOC dihapus saja ke T3 = 404 ARCHIVE400 (bukan 400 ARCHIVE403)');

                // 6. sumber dihapus di luar lokasi user (tanpa tag lokasi = di luar scope siapa pun selain superadmin 1/2): tetap 403 ARCHIVE407
                $xOut = q124_doc($t, 'XOUT', ['parent' => $P, 'active' => 0, 'all_location' => 0]);
                $A5 = q124_doc($t, 'A5', ['parent' => $P]);
                $snapOut = q124_snap($t, [$xOut, $A5, $T]);
                foreach (['[XOUT dihapus]' => [$xOut], '[A5 aktif, XOUT dihapus]' => [$A5, $xOut]] as $label => $src) {
                    $r = q124_putin($t, $s, $T, $src);
                    $f->resp($r, 403, 'ARCHIVE407', "sumber dihapus di luar lokasi $label");
                    $f->ok(strpos((string) q124_msg($r), 'Anda tidak punya akses') === 0, "ARCHIVE407 $label: pesan ID");
                }
                // 403 lebih dulu dari 422 (tujuan tak ada) dan dari 404
                $r = q124_putin($t, $s, Q124_RANDOM_ID, [$xOut]);
                $f->resp($r, 403, 'ARCHIVE407', '403 sebelum 422: sumber dihapus di luar lokasi + tujuan tak ada');
                $f->ok(q124_snap($t, [$xOut, $A5, $T]) === $snapOut, 'ditolak 403 tanpa perubahan');

                // 7. urutan 422 -> 400 -> 404 (semua sumber dihapus)
                $r = q124_putin($t, $s, Q124_RANDOM_ID, [$dDel]);
                $f->resp($r, 422, null, '422 sebelum 404: tujuan tak ada + sumber dihapus');
                $f->ok(isset($r[1]['errors']['id_archive_parent']), '422: errors.id_archive_parent');
                $r = $t->call($s, 'POST', Q124_API . 'documents/put-in', ['id_archive_parent' => $T, 'id_archives' => [$dDel], 'name' => 'x']);
                $f->resp($r, 400, 'ARCHIVE406', '400 ARCHIVE406 (id_archives + name) sebelum 404');
                $f->ok(q124_snap($t, [$P, $T, $dDel, $fDel, $fDelChild]) === $snapDel, 'dokumen/folder dihapus tidak berubah sesudah semua urutan');

                $f->done();
            } finally {
                q124_purge($t);
            }
            q124_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-5',
        'title' => 'regresi: put-in id_archives dokumen tercetak (-1) -> 200 is_active 1; put-in name dokumen -1 dan dokumen dihapus -> 200 is_active 1, history place/move; is_active NULL bukan "dihapus"',
        'run'   => function ($t) {
            $s = $t->session();
            q124_purge($t);
            $base = q124_base($t);

            try {
                $f = q124_soft($t);
                $P = q124_folder($t, 'P');
                $T = q124_folder($t, 'T');

                // id_archives, dokumen -1 (tercetak belum disimpan)
                $d1 = q124_doc($t, 'D1', ['active' => -1]);
                $r = q124_putin($t, $s, $T, [$d1]);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in id_archives dokumen -1');
                $f->eq(array_column($r[1]['result'] ?? [], 'id_archive'), [$d1], 'result = D1');
                $f->eq([(int) q124_row($t, $d1)['is_active'], q124_parent($t, $d1), q124_actions($t, $d1)], [1, $T, ['place']], 'D1: is_active 1, di T, history place');

                // id_archives, campuran -1 dan aktif: keduanya
                $d1b = q124_doc($t, 'D1B', ['active' => -1]);
                $a1 = q124_doc($t, 'A1', ['parent' => $P]);
                $r = q124_putin($t, $s, $T, [$d1b, $a1]);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in campuran [-1, aktif]');
                $ids = array_column($r[1]['result'] ?? [], 'id_archive');
                sort($ids);
                $want = [$d1b, $a1];
                sort($want);
                $f->eq($ids, $want, 'result = D1B, A1');
                $f->eq([(int) q124_row($t, $d1b)['is_active'], q124_actions($t, $d1b), q124_actions($t, $a1)], [1, ['place'], ['move']], 'D1B aktif place; A1 move');

                // name, dokumen -1
                $d2 = q124_doc($t, 'D2', ['active' => -1]);
                $r = q124_putin($t, $s, $T, null, q124_row($t, $d2)['name']);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in name dokumen -1');
                $f->eq([(int) q124_row($t, $d2)['is_active'], q124_parent($t, $d2), q124_actions($t, $d2)], [1, $T, ['place']], 'D2: is_active 1, di T, history place');

                // name, dokumen dihapus (scan memulihkan): dengan induk lama -> move; tanpa induk -> place
                $hist = [['action' => 'place', 'related_username' => 'QA124', 'timestamp' => '01 Jan 2026 00:00:00']];
                $d3 = q124_doc($t, 'D3', ['active' => 0, 'parent' => $P, 'history' => $hist]);
                $r = q124_putin($t, $s, $T, null, q124_row($t, $d3)['name']);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in name dokumen dihapus (induk lama)');
                $f->eq([(int) q124_row($t, $d3)['is_active'], q124_parent($t, $d3), q124_actions($t, $d3)], [1, $T, ['place', 'move']], 'D3: is_active 1, di T, history place + move');
                $d4 = q124_doc($t, 'D4', ['active' => 0]);
                $r = q124_putin($t, $s, $T, null, q124_row($t, $d4)['name']);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in name dokumen dihapus (tanpa induk)');
                $f->eq([(int) q124_row($t, $d4)['is_active'], q124_parent($t, $d4), q124_actions($t, $d4)], [1, $T, ['place']], 'D4: is_active 1, di T, history place');

                // is_active NULL: bukan dihapus -> ikut dipindah
                $d5 = q124_doc($t, 'D5', ['active' => null, 'parent' => $P]);
                $f->ok(q124_row($t, $d5)['is_active'] === null, 'prasyarat: D5 is_active NULL');
                $r = q124_putin($t, $s, $T, [$d5]);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in id_archives is_active NULL');
                $f->eq([(int) q124_row($t, $d5)['is_active'], q124_parent($t, $d5)], [1, $T], 'D5: is_active 1, di T');

                $f->done();
            } finally {
                q124_purge($t);
            }
            q124_assert_clean($t, $base);
        },
    ],

];
