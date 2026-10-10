<?php
/**
 * ED-1069 AC-7 (rename: nama kembar), AC-8 (put-in massal dua folder senama), AC-9 (rename dokumen).
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-7',
        'title' => 'rename B -> nama A = 400 ARCHIVE405 (juga di root, body id_archive_parent diabaikan); A->A 200; B->nama folder nonaktif 200; B->unik 200 + history rename; name bukan teks 422',
        'run'   => function ($t) {
            q69_recover($t);
            $s = $t->session();
            q69_purge($t);
            $base = q69_base($t);

            try {
                $P = q69_folder($t, 'P');
                $A = q69_folder($t, 'A', ['parent' => $P]);
                $B = q69_folder($t, 'B', ['parent' => $P]);
                $N = q69_folder($t, 'N', ['parent' => $P, 'active' => 0]);
                $X = q69_folder($t, 'X');
                $DOC = q69_doc($t, 'DOC', ['parent' => $P]);
                $Ar = q69_folder($t, 'AR');                                        // di root
                $Br = q69_folder($t, 'BR');
                $nameA = q69_name_of($t, $A);
                $nameB = q69_name_of($t, $B);
                $nameN = q69_name_of($t, $N);
                $f = q69_soft($t);

                $snap = q69_snap($t, [$P, $A, $B, $N, $X, $DOC, $Ar, $Br]);

                // nama kembar di induk yang sama
                $r = q69_rename($t, $s, $B, $nameA);
                $f->resp($r, 400, 'ARCHIVE405', 'rename B -> nama A (induk P)');
                $f->eq(q69_msg($r), 'Sudah ada nama yang sama dalam folder tersebut', 'teks ARCHIVE405 (ID)');
                // sama di root
                $r = q69_rename($t, $s, $Br, q69_name_of($t, $Ar));
                $f->resp($r, 400, 'ARCHIVE405', 'rename BR -> nama AR (root)');
                // body id_archive_parent diabaikan: induk dibaca dari DB (nama A ada di P, bukan di X)
                foreach (['X (folder lain)' => $X, 'null' => null, 'root ""' => '', 'id acak' => Q69_RANDOM_ID, 'A' => $A] as $label => $bodyParent) {
                    $r = q69_rename($t, $s, $B, $nameA, ['id_archive_parent' => $bodyParent]);
                    $f->resp($r, 400, 'ARCHIVE405', "rename B -> nama A + body id_archive_parent = $label");
                }
                // nama dokumen saudara juga sudah dipakai (perilaku UniqueNameRule Archive tanpa filter type, sama dengan create-folder)
                $r = q69_rename($t, $s, $B, q69_name_of($t, $DOC));
                $rc = q69_create($t, $s, ['name' => q69_name_of($t, $DOC), 'is_all_location' => 1, 'id_archive_parent' => $P]);
                $f->eq([$r[0], q69_code($r)], [$rc[0], q69_code($rc)], 'rename ke nama dokumen saudara = perilaku create-folder (' . $rc[0] . ' ' . q69_code($rc) . ')');
                $f->ok(q69_snap($t, [$P, $A, $B, $N, $X, $DOC, $Ar, $Br]) === $snap, 'semua penolakan: B, BR (nama, history, updated_*) tidak berubah');

                // name bukan teks / kosong = 422 (tidak sampai ke aturan bisnis), nama tidak berubah
                foreach (['array ["x"]' => ['x'], 'objek' => ['a' => 'b'], 'kosong ""' => '', 'null' => null, 'angka 5' => 5, 'true' => true] as $label => $v) {
                    $r = q69_rename($t, $s, $B, $v);
                    $f->resp($r, 422, null, "rename name = $label");
                    $f->ok(isset($r[1]['errors']['name']), "rename name = $label: errors.name");
                }
                $r = $t->call($s, 'PUT', Q69_API . 'archives/rename/' . $B, []);
                $f->resp($r, 422, null, 'rename tanpa name');
                $f->ok(q69_snap($t, [$P, $A, $B, $N, $X, $DOC, $Ar, $Br]) === $snap, '422: data tidak berubah');

                // rename ke nama sendiri = 200 (dikecualikan dari pengecekan)
                $r = q69_rename($t, $s, $A, $nameA);
                $f->resp($r, 200, null, 'rename A -> A');
                $f->eq(q69_name_of($t, $A), $nameA, 'nama A sama');

                // ke nama folder nonaktif saudaranya = 200 (nonaktif tidak dihitung)
                $r = q69_rename($t, $s, $B, $nameN);
                $f->resp($r, 200, null, 'rename B -> nama N (nonaktif)');
                $f->eq(q69_name_of($t, $B), $nameN, 'nama B = nama N');
                $f->eq(array_slice(q69_actions($t, $B), -1), ['rename'], 'history B: rename');

                // ke nama unik = 200 + history rename
                $uniq = q69_name('UNIK');
                $before = count(q69_history($t, $B));
                $r = q69_rename($t, $s, $B, $uniq);
                $f->resp($r, 200, null, 'rename B -> unik');
                $f->eq(q69_name_of($t, $B), $uniq, 'nama B = unik');
                $f->eq(count(q69_history($t, $B)), $before + 1, 'history B bertambah 1 entri');
                $f->eq(array_slice(q69_actions($t, $B), -1), ['rename'], 'entri terakhir = rename');
                $f->eq([q69_code($r), $r[1]['result']['id_archive'] ?? null], ['ARCHIVE206', $B], 'respons ARCHIVE206 memuat id_archive');

                // nama lama B bebas lagi
                $r = q69_rename($t, $s, $Br, $nameB);
                $f->resp($r, 200, null, 'rename BR (root) -> nama lama B di P (induk berbeda) = 200');

                // pembanding perilaku: huruf besar/kecil (kolasi kolom name) - dicatat, bukan asersi
                $r = q69_rename($t, $s, $B, strtoupper($nameA) === $nameA ? strtolower($nameA) : strtoupper($nameA));
                $t->note('rename ke nama A berbeda huruf besar/kecil = HTTP ' . $r[0] . ' ' . (q69_code($r) ?? '') . ' (kolasi name latin1_general_ci)');
                $f->no5xx($r, 'rename beda huruf besar/kecil');

                $f->done();
            } finally {
                q69_purge($t);
            }
            q69_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-8',
        'title' => 'P1/S, P2/S: put-in [S1,S2]->T = 400 ARCHIVE405, keduanya tetap; [S1]->T = 200',
        'run'   => function ($t) {
            q69_recover($t);
            $s = $t->session();
            q69_purge($t);
            $base = q69_base($t);

            try {
                $nameS = q69_name('S');
                $P1 = q69_folder($t, 'P1');
                $P2 = q69_folder($t, 'P2');
                $S1 = q69_folder($t, 'S1', ['parent' => $P1, 'name' => $nameS]);
                $S2 = q69_folder($t, 'S2', ['parent' => $P2, 'name' => $nameS]);
                $Z = q69_folder($t, 'Z', ['parent' => $P2]);
                $T = q69_folder($t, 'T');
                $U = q69_folder($t, 'U');
                $all = [$P1, $P2, $S1, $S2, $Z, $T, $U];
                $snap = q69_snap($t, $all);
                $f = q69_soft($t);

                $r = q69_putin($t, $s, $T, [$S1, $S2]);
                $f->resp($r, 400, 'ARCHIVE405', 'put-in [S1,S2]->T');
                $r = q69_putin($t, $s, $T, [$S2, $S1]);
                $f->resp($r, 400, 'ARCHIVE405', 'put-in [S2,S1]->T (urutan terbalik)');
                $r = q69_putin($t, $s, null, [$S1, $S2]);
                $f->resp($r, 400, 'ARCHIVE405', 'put-in [S1,S2]->root');
                $r = q69_putin($t, $s, $T, [$S1, $S2, $Z]);
                $f->resp($r, 400, 'ARCHIVE405', 'put-in [S1,S2,Z]->T (ada folder lain yang unik)');
                $r = q69_putin($t, $s, $P1, [$S1, $S2]);
                $f->resp($r, 400, 'ARCHIVE405', 'put-in [S1,S2]->P1 (tujuan = induk S1)');
                $f->ok(q69_snap($t, $all) === $snap, 'S1, S2, Z, T tetap persis (induk, history, updated_*)');
                $f->eq(q69_parent($t, $S1), $P1, 'S1 tetap di P1');
                $f->eq(q69_parent($t, $S2), $P2, 'S2 tetap di P2');

                // satu per satu
                $r = q69_putin($t, $s, $T, [$S1]);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in [S1]->T');
                $f->eq(q69_parent($t, $S1), $T, 'S1 di T');
                $f->eq(array_slice(q69_actions($t, $S1), -1), ['move'], 'history S1: move');
                // tujuan T kini berisi S1: S2 ke T tetap ditolak oleh aturan nama kembar di tujuan (aturan lama)
                $r = q69_putin($t, $s, $T, [$S2]);
                $f->resp($r, 400, 'ARCHIVE405', 'put-in [S2]->T sesudah S1 di T (aturan lama: senama di tujuan)');
                $f->eq(q69_parent($t, $S2), $P2, 'S2 tetap di P2');
                // S2 ke tujuan lain yang tidak punya S = 200
                $r = q69_putin($t, $s, $U, [$S2]);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in [S2]->U');
                // id ganda dalam daftar bukan "dua folder senama"
                $r = q69_putin($t, $s, $P2, [$S2, $S2]);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in [S2,S2]->P2 (id ganda = satu folder)');

                // pembanding perilaku: nonaktif / huruf beda - dicatat, bukan asersi spec
                q69_set($t, $S1, ['id_archive_parent' => $P1]);
                q69_set($t, $S2, ['id_archive_parent' => $P2, 'is_active' => 0]);
                $r = q69_putin($t, $s, $T, [$S1, $S2]);
                $t->note('[S1 aktif, S2 nonaktif]->T = HTTP ' . $r[0] . ' ' . (q69_code($r) ?? ''));
                $f->no5xx($r, 'sumber senama, satu nonaktif');

                $f->done();
            } finally {
                q69_purge($t);
            }
            q69_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-9',
        'title' => 'rename dokumen D = 400 ARCHIVE419 (teks id/en), name tetap; folder tetap bisa diganti nama; id acak 404',
        'run'   => function ($t) {
            q69_recover($t);
            $s = $t->session();
            q69_purge($t);
            $base = q69_base($t);

            try {
                $P = q69_folder($t, 'P');
                $D = q69_doc($t, 'D', ['parent' => $P]);
                $D0 = q69_doc($t, 'D0');                                          // dokumen di root
                $F = q69_folder($t, 'F', ['parent' => $P]);
                $all = [$P, $D, $D0, $F];
                $snap = q69_snap($t, $all);
                $snapDocs = q69_snap($t, [$D, $D0]);
                $f = q69_soft($t);

                $idText = 'Hanya folder yang bisa diganti nama';
                $enText = 'Only folders can be renamed';
                foreach (['D di folder' => $D, 'D0 di root' => $D0] as $label => $doc) {
                    $r = q69_rename($t, $s, $doc, q69_name('NEWDOC'));
                    $f->resp($r, 400, 'ARCHIVE419', "rename $label ke nama unik");
                    $f->eq(q69_msg($r), $idText, "rename $label: teks ID");
                }
                // nama kembar + dokumen: ARCHIVE419 menang atas ARCHIVE405
                $r = q69_rename($t, $s, $D, q69_name_of($t, $F));
                $f->resp($r, 400, 'ARCHIVE419', 'rename D ke nama F (kembar) = 419, bukan 405');
                // name tidak sah: 422 menang (tipe)
                $r = q69_rename($t, $s, $D, ['x']);
                $f->resp($r, 422, null, 'rename D name array = 422');
                $f->ok(q69_snap($t, $all) === $snap, 'name dokumen tetap, history tidak bertambah');

                q69_with_lang($t, 'EN', function () use ($t, $s, $D, $f, $enText) {
                    $r = q69_rename($t, $s, $D, q69_name('NEWDOC'));
                    $f->resp($r, 400, 'ARCHIVE419', 'EN rename D');
                    $f->eq(q69_msg($r), $enText, 'EN: teks');
                });
                $f->eq(q69_msg(q69_rename($t, $s, $D, q69_name('NEWDOC'))), $idText, 'bahasa kembali ID');

                $be = $t->conf('BE_DIR');
                $f->ok(strpos((string) file_get_contents($be . '/app/Lib/lang/id_ID.php'), "'ARCHIVE419' => 'Hanya folder yang bisa diganti nama'") !== false, 'id_ID.php memuat ARCHIVE419');
                $f->ok(strpos((string) file_get_contents($be . '/app/Lib/lang/en_EN.php'), "'ARCHIVE419' => \"Only folders can be renamed\"") !== false, 'en_EN.php memuat ARCHIVE419');

                // id acak = 404 ARCHIVE400; folder = 200
                $r = q69_rename($t, $s, Q69_RANDOM_ID, q69_name('X'));
                $f->resp($r, 404, 'ARCHIVE400', 'rename id acak');
                $r = q69_rename($t, $s, $F, q69_name('FOLDER'));
                $f->resp($r, 200, null, 'rename folder F (pembanding)');

                $f->ok(q69_snap($t, [$D, $D0]) === $snapDocs, 'baris dokumen D, D0 tetap persis sampai akhir');

                $f->done();
            } finally {
                q69_purge($t);
            }
            q69_assert_clean($t, $base);
        },
    ],

];
