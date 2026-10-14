<?php
/**
 * ED-1069 AC-1, AC-2, AC-3, AC-5: put-in / create-folder / PUT archives/{id} (induk tak ada, bukan folder aktif, siklus)
 * dan regresi pindah yang sah. Data uji berawalan QA69-, dibuang di finally.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-1',
        'title' => 'put-in [B] dan [D] ke id tak ada = 422 errors.id_archive_parent; induk dan history B/D tetap (juga lewat name, create-folder, PUT archives/{id})',
        'run'   => function ($t) {
            q69_recover($t);
            $s = $t->session();
            q69_purge($t);
            $base = q69_base($t);

            try {
                $A = q69_folder($t, 'A');
                $B = q69_folder($t, 'B', ['parent' => $A]);
                $D = q69_doc($t, 'D', ['parent' => $A, 'history' => [['action' => 'place', 'related_username' => 'QA69', 'timestamp' => '01 Jan 2026 00:00:00']]]);
                $nameB = q69_name_of($t, $B);
                $nameD = q69_name_of($t, $D);
                $snap = q69_snap($t, [$A, $B, $D]);
                $f = q69_soft($t);

                $cases = [
                    'put-in [B]'            => q69_putin($t, $s, Q69_RANDOM_ID, [$B]),
                    'put-in [D]'            => q69_putin($t, $s, Q69_RANDOM_ID, [$D]),
                    'put-in [B, D]'         => q69_putin($t, $s, Q69_RANDOM_ID, [$B, $D]),
                    'put-in name=B'         => q69_putin($t, $s, Q69_RANDOM_ID, null, $nameB),
                    'put-in name=D'         => q69_putin($t, $s, Q69_RANDOM_ID, null, $nameD),
                    'put-in id pendek "x"'  => q69_putin($t, $s, 'x', [$B]),
                    'put-in id bertanda kutip' => q69_putin($t, $s, "x' OR '1'='1", [$B]),
                ];
                foreach ($cases as $label => $r) {
                    $f->resp($r, 422, null, $label);
                    $f->ok(isset($r[1]['errors']['id_archive_parent']) && !isset($r[1]['code']), $label . ': errors.id_archive_parent (bukan kode bisnis)');
                }

                $r = q69_create($t, $s, ['name' => q69_name('NEW'), 'is_all_location' => 1, 'id_archive_parent' => Q69_RANDOM_ID]);
                $f->resp($r, 422, null, 'create-folder induk tak ada');
                $f->ok(isset($r[1]['errors']['id_archive_parent']), 'create-folder: errors.id_archive_parent');
                $r = q69_put($t, $s, $B, q69_body($nameB, ['id_archive_parent' => Q69_RANDOM_ID]));
                $f->resp($r, 422, null, 'PUT archives/{B} induk tak ada');
                $f->ok(isset($r[1]['errors']['id_archive_parent']), 'PUT: errors.id_archive_parent');

                $f->ok(q69_snap($t, [$A, $B, $D]) === $snap, 'baris A, B, D (termasuk history, updated_*) tidak berubah sama sekali');
                $f->eq(q69_parent($t, $B), $A, 'induk B tetap A');
                $f->eq(q69_parent($t, $D), $A, 'induk D tetap A');
                $f->eq(count(q69_history($t, $D)), 1, 'history D tetap 1 entri');
                $f->eq($t->db()->table('archives')->where('name', 'like', Q69_PREFIX . 'NEW%')->count(), 0, 'create-folder yang ditolak tidak membuat baris');

                // pembanding: tujuan = root (tanpa id_archive_parent) tetap sah (null = root)
                $r = q69_putin($t, $s, null, [$B]);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in [B] ke root (pembanding)');
                $f->eq(q69_parent($t, $B), null, 'B kini di root');
                $f->eq(q69_actions($t, $B), ['take out'], 'history B = take out');

                $f->done();
            } finally {
                q69_purge($t);
            }
            q69_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-2',
        'title' => 'induk = dokumen / folder nonaktif (juga nonaktif di bawah sumber): put-in 422 (bukan 400), create-folder 422 tanpa baris baru, PUT archives/{B} 422',
        'run'   => function ($t) {
            q69_recover($t);
            $s = $t->session();
            q69_purge($t);
            $base = q69_base($t);

            try {
                $A = q69_folder($t, 'A');
                $B = q69_folder($t, 'B', ['parent' => $A]);
                $D = q69_doc($t, 'D', ['parent' => $A]);               // dokumen di dalam A (di bawah sumber A)
                $N = q69_folder($t, 'N', ['active' => 0]);              // nonaktif di root
                $NA = q69_folder($t, 'NA', ['parent' => $A, 'active' => 0]); // nonaktif di bawah sumber A
                $X = q69_folder($t, 'X');
                $nameB = q69_name_of($t, $B);
                $snap = q69_snap($t, [$A, $B, $D, $N, $NA, $X]);
                $f = q69_soft($t);

                $targets = ['dokumen D' => $D, 'folder nonaktif N' => $N, 'folder nonaktif NA di bawah A' => $NA];
                foreach ($targets as $label => $target) {
                    $r = q69_putin($t, $s, $target, [$X]);
                    $f->resp($r, 422, null, "put-in [X] ke $label");
                    $f->ok(isset($r[1]['errors']['id_archive_parent']), "put-in ke $label: errors.id_archive_parent");

                    // sumber A, tujuan di bawah A tetapi tidak sah: 422 (aturan siklus 400 dilewati)
                    $r = q69_putin($t, $s, $target, [$A]);
                    $f->resp($r, 422, null, "put-in [A] ke $label (bukan 400)");
                    $f->ok(!isset($r[1]['code']), "put-in [A] ke $label: tanpa kode bisnis 400");

                    $r = q69_putin($t, $s, $target, null, q69_name_of($t, $X));
                    $f->resp($r, 422, null, "put-in name=X ke $label");

                    $r = q69_create($t, $s, ['name' => q69_name('NEW'), 'is_all_location' => 1, 'id_archive_parent' => $target]);
                    $f->resp($r, 422, null, "create-folder di bawah $label");
                    $f->ok(isset($r[1]['errors']['id_archive_parent']), "create-folder $label: errors.id_archive_parent");

                    $r = q69_put($t, $s, $B, q69_body($nameB, ['id_archive_parent' => $target]));
                    $f->resp($r, 422, null, "PUT archives/{B} induk = $label");
                    $f->ok(isset($r[1]['errors']['id_archive_parent']), "PUT $label: errors.id_archive_parent");
                }

                // induk tidak sah + nama kembar: 422 menang atas ARCHIVE405 (create-folder dan PUT)
                $r = q69_create($t, $s, ['name' => $nameB, 'is_all_location' => 1, 'id_archive_parent' => $N]);
                $f->resp($r, 422, null, 'create-folder nama kembar tetapi induk nonaktif = 422');
                $r = q69_put($t, $s, $B, q69_body(q69_name_of($t, $X), ['id_archive_parent' => $N]));
                $f->resp($r, 422, null, 'PUT nama = nama X tetapi induk nonaktif = 422');

                $f->ok(q69_snap($t, [$A, $B, $D, $N, $NA, $X]) === $snap, 'tidak ada baris yang berubah');
                $f->eq($t->db()->table('archives')->where('name', 'like', Q69_PREFIX . 'NEW%')->count(), 0, 'create-folder yang ditolak tidak membuat baris');

                // pembanding: induk sah (folder aktif) tetap 200 di tiga endpoint
                $r = q69_create($t, $s, ['name' => q69_name('NEW'), 'is_all_location' => 1, 'id_archive_parent' => $X]);
                $f->resp($r, 200, null, 'create-folder di folder aktif X (pembanding)');
                $r = q69_put($t, $s, $B, q69_body($nameB, ['id_archive_parent' => $X]));
                $f->resp($r, 200, null, 'PUT B ke folder aktif X (pembanding)');
                $f->eq(q69_parent($t, $B), $X, 'B pindah ke X');
                $r = q69_putin($t, $s, $X, [$A]);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in [A] ke X (pembanding)');

                $f->done();
            } finally {
                q69_purge($t);
            }
            q69_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-3',
        'title' => 'A>B>C, X di root: put-in [A]->C, [A]->B, [X,A]->C, [A]->A = 400 ARCHIVE404 (teks K-2 a id/en); name=A->B, name=A->A (A folder) = 404 ARCHIVE400 (dibalik ED-1124 K-2 b); tak ada baris berubah',
        'run'   => function ($t) {
            q69_recover($t);
            $s = $t->session();
            q69_purge($t);
            $base = q69_base($t);

            try {
                $A = q69_folder($t, 'A');
                $B = q69_folder($t, 'B', ['parent' => $A]);
                $C = q69_folder($t, 'C', ['parent' => $B]);
                $E = q69_folder($t, 'E', ['parent' => $C]);                    // A>B>C>E (4 tingkat)
                $NB = q69_folder($t, 'NB', ['parent' => $A, 'active' => 0]);    // nonaktif di bawah A
                $NC = q69_folder($t, 'NC', ['parent' => $NB]);                  // aktif, tetapi turunan lewat folder nonaktif
                $X = q69_folder($t, 'X');
                $D = q69_doc($t, 'D', ['parent' => $B]);
                $nameA = q69_name_of($t, $A);
                $all = [$A, $B, $C, $E, $NB, $NC, $X, $D];
                $snap = q69_snap($t, $all);
                $f = q69_soft($t);

                $idText = 'Tidak bisa dipindahkan ke dirinya sendiri atau subfoldernya';
                $enText = "Can't be moved into itself or its subfolder";

                $cases = [
                    'put-in [A]->C'              => function () use ($t, $s, $A, $C) { return q69_putin($t, $s, $C, [$A]); },
                    'put-in [A]->B'              => function () use ($t, $s, $A, $B) { return q69_putin($t, $s, $B, [$A]); },
                    'put-in [A]->E (4 tingkat)'  => function () use ($t, $s, $A, $E) { return q69_putin($t, $s, $E, [$A]); },
                    'put-in [A]->NC (lewat nonaktif)' => function () use ($t, $s, $A, $NC) { return q69_putin($t, $s, $NC, [$A]); },
                    'put-in [A]->A'              => function () use ($t, $s, $A) { return q69_putin($t, $s, $A, [$A]); },
                    'put-in [X, A]->C'           => function () use ($t, $s, $X, $A, $C) { return q69_putin($t, $s, $C, [$X, $A]); },
                    'put-in [A, X]->C'           => function () use ($t, $s, $X, $A, $C) { return q69_putin($t, $s, $C, [$A, $X]); },
                    'put-in [B]->C'              => function () use ($t, $s, $B, $C) { return q69_putin($t, $s, $C, [$B]); },
                    'put-in [B, C]->E'           => function () use ($t, $s, $B, $C, $E) { return q69_putin($t, $s, $E, [$B, $C]); },
                    'put-in [C]->C'              => function () use ($t, $s, $C) { return q69_putin($t, $s, $C, [$C]); },
                ];
                foreach ($cases as $label => $call) {
                    $r = $call();
                    $f->resp($r, 400, 'ARCHIVE404', $label);
                    $f->eq(q69_msg($r), $idText, $label . ': teks ID');
                }
                // dibalik ED-1124 K-2 b: put-in name=A->B dan name=A->A (A folder): name yang hanya cocok folder = tidak ada (findName hanya dokumen) -> 404 ARCHIVE400, bukan 400 ARCHIVE404
                foreach (['put-in name=A->B' => $B, 'put-in name=A->A' => $A] as $label => $dest) {
                    $r = q69_putin($t, $s, $dest, null, $nameA);
                    $f->resp($r, 404, 'ARCHIVE400', $label . ' (nama folder tidak dikenal)');
                }
                $f->ok(q69_snap($t, $all) === $snap, 'tidak ada baris berubah (archives + archive_documents, semua kolom termasuk history/updated_*)');
                $f->eq(q69_parent($t, $A), null, 'A tetap di root');
                $f->eq(q69_parent($t, $B), $A, 'B tetap di A');
                $f->eq(q69_parent($t, $C), $B, 'C tetap di B');

                // teks bahasa Inggris (bahasa user QA diganti sementara)
                q69_with_lang($t, 'EN', function () use ($t, $s, $A, $C, $f, $enText) {
                    $r = q69_putin($t, $s, $C, [$A]);
                    $f->resp($r, 400, 'ARCHIVE404', 'EN put-in [A]->C');
                    $f->eq(q69_msg($r), $enText, 'EN: teks');
                });
                $f->eq(q69_msg(q69_putin($t, $s, $C, [$A])), $idText, 'bahasa user kembali ke ID: teks ID');

                // berkas bahasa: kedua teks ada persis
                $be = $t->conf('BE_DIR');
                $f->ok(strpos((string) file_get_contents($be . '/app/Lib/lang/id_ID.php'), "'ARCHIVE404' => 'Tidak bisa dipindahkan ke dirinya sendiri atau subfoldernya'") !== false, 'id_ID.php memuat teks K-2 a');
                $f->ok(strpos((string) file_get_contents($be . '/app/Lib/lang/en_EN.php'), "'ARCHIVE404' => \"Can't be moved into itself or its subfolder\"") !== false, 'en_EN.php memuat teks K-2 a');

                // urutan antar-400 put-in (Catatan implementasi): ARCHIVE404 -> ARCHIVE406 -> ARCHIVE405 (dan 403 lokasi)
                $r = q69_putin($t, $s, $C, [$A], $nameA);
                $f->resp($r, 400, 'ARCHIVE404', 'id_archives + name sekaligus ke turunan: ARCHIVE404 menang atas ARCHIVE406');
                $r = q69_putin($t, $s, $X, [$B], $nameA);
                $f->resp($r, 400, 'ARCHIVE406', 'id_archives + name sekaligus ke tujuan sah: ARCHIVE406');
                $f->ok(q69_snap($t, $all) === $snap, 'masih tidak ada baris berubah');

                $f->done();
            } finally {
                q69_purge($t);
            }
            q69_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-5',
        'title' => 'regresi pindah sah: [C]->root, [C]->A, [B]->X, D->C, [A]->X, PUT archives/{C} induk=A = 200, history take out/place/move; GET archives?id_archive=C 200 + breadcrumbs benar',
        'run'   => function ($t) {
            q69_recover($t);
            $s = $t->session();
            q69_purge($t);
            $base = q69_base($t);

            try {
                $X = q69_folder($t, 'X');
                $A = q69_folder($t, 'A');
                $B = q69_folder($t, 'B', ['parent' => $A]);
                $C = q69_folder($t, 'C', ['parent' => $B]);
                $D = q69_doc($t, 'D', ['parent' => $A]);
                $nameC = q69_name_of($t, $C);
                $f = q69_soft($t);

                $r = q69_putin($t, $s, null, [$C]);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in [C]->root');
                $f->eq(q69_parent($t, $C), null, 'C di root');
                $f->eq(q69_actions($t, $C), ['take out'], 'history C: take out');
                $h = q69_history($t, $C);
                $f->eq($h[0]['from_folder'] ?? null, q69_name_of($t, $B), 'take out: from_folder = B');

                $r = q69_putin($t, $s, $A, [$C]);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in [C]->A');
                $f->eq(q69_parent($t, $C), $A, 'C di A');
                $f->eq(q69_actions($t, $C), ['take out', 'place'], 'history C: + place');

                $r = q69_putin($t, $s, $X, [$B]);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in [B]->X');
                $f->eq(q69_parent($t, $B), $X, 'B di X');
                $f->eq(q69_actions($t, $B), ['move'], 'history B: move');
                $h = q69_history($t, $B);
                $f->eq([$h[0]['from_folder'] ?? null, $h[0]['to_folder'] ?? null], [q69_name_of($t, $A), q69_name_of($t, $X)], 'move: from A to X');

                $r = q69_putin($t, $s, $C, [$D]);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in D->C');
                $f->eq(q69_parent($t, $D), $C, 'D di C');
                $f->eq(q69_actions($t, $D), ['move'], 'history D: move');
                $f->eq(array_slice(array_column($r[1]['result'][0]['breadcrumbs'] ?? [], 'value'), -1), [$C], 'respons put-in: breadcrumbs berujung di C');

                $r = q69_putin($t, $s, $X, [$A]);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in [A]->X');
                $f->eq(q69_parent($t, $A), $X, 'A di X');
                $f->eq(q69_actions($t, $A), ['place'], 'history A: place');

                // by name (sumber lewat name = scan dokumen) ke folder sah
                $r = q69_putin($t, $s, $B, null, q69_name_of($t, $D));
                $f->resp($r, 200, 'ARCHIVE204', 'put-in name=D->B');
                $f->eq(q69_parent($t, $D), $B, 'D di B (lewat name)');

                // PUT archives/{C} induk = A (sebelumnya C pindah dulu ke B agar induknya benar-benar berubah)
                $r = q69_putin($t, $s, $B, [$C]);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in [C]->B');
                $r = q69_put($t, $s, $C, q69_body($nameC, ['id_archive_parent' => $A]));
                $f->resp($r, 200, null, 'PUT archives/{C} induk = A');
                $f->eq(q69_parent($t, $C), $A, 'C kembali di A');
                $f->eq(array_slice(q69_actions($t, $C), -1), ['update'], 'history C: update');

                // GET archives?id_archive=C
                $r = q69_open($t, $s, $C);
                $f->resp($r, 200, null, 'GET archives?id_archive=C');
                $want = [['value' => $X, 'label' => q69_name_of($t, $X)], ['value' => $A, 'label' => q69_name_of($t, $A)], ['value' => $C, 'label' => $nameC]];
                $f->eq($r[1]['result']['breadcrumbs'] ?? null, $want, 'breadcrumbs = X > A > C');
                $r = q69_open($t, $s, $A);
                $f->resp($r, 200, null, 'GET archives?id_archive=A');
                $f->eq(array_column($r[1]['result']['breadcrumbs'] ?? [], 'value'), [$X, $A], 'breadcrumbs A = X > A');
                $ids = array_column($r[1]['result']['data'] ?? [], 'id_archive');
                sort($ids);
                $f->eq($ids, [$C], 'isi A = C');

                $f->done();
            } finally {
                q69_purge($t);
            }
            q69_assert_clean($t, $base);
        },
    ],

];
