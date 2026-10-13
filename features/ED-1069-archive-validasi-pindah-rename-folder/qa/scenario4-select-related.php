<?php
/**
 * ED-1069 AC-10 (select lokasi: induk tak ada 404, tipe salah 422, pembatasan lokasi) dan AC-11 (dokumen yatim: transaksi
 * terkait kosong, bukan 500; dokumen bertransaksi sama dengan perilaku sebelum perubahan).
 */
require_once __DIR__ . '/qa_lib.php';

if (!function_exists('q69_baseline_class')) {

/** Muat ArchiveService versi commit (HEAD) sebagai kelas pembanding; return nama kelas, atau null bila git tidak bisa dibaca. */
function q69_baseline_class($t)
{
    $class = 'Q69BaselineArchiveService';
    $fqcn = 'Modules\\V5\\Http\\Services\\DocumentArchive\\' . $class;
    if (class_exists($fqcn, false)) {
        return $fqcn;
    }

    $be = $t->conf('BE_DIR');
    $src = shell_exec('git -C ' . escapeshellarg($be) . ' show HEAD:Modules/V5/Http/Services/DocumentArchive/ArchiveService.php 2>/dev/null');
    if (!is_string($src) || strpos($src, 'class ArchiveService extends DocumentArchiveService') === false) {
        return null;
    }

    $src = preg_replace('/^<\?php/', '', $src, 1);
    $src = str_replace('class ArchiveService extends DocumentArchiveService', 'class ' . $class . ' extends DocumentArchiveService', $src);
    eval($src);

    return $fqcn;
}

/** relatedTransaction() versi sebelum perubahan, untuk satu dokumen ($document = baris `document` dari list). */
function q69_baseline_related($t, $fqcn, array $document, $uid)
{
    $db = q69_dbname($t);

    return $t->probe(function () use ($fqcn, $document, $uid, $db) {
        \Illuminate\Support\Facades\DB::setDefaultConnection($db);
        $svc = new $fqcn;
        $m = new \ReflectionMethod($svc, 'relatedTransaction');
        $m->setAccessible(true);

        return $m->invoke($svc, $document, \Modules\V5\Entities\Models\Archive::userScopeLocations($uid));
    });
}

}

return [

    [
        'id'    => 'AC-10',
        'title' => 'select lokasi: induk tak ada 404 ARCHIVE400, id_archive_parent[]=x 422, tanpa induk = semua lokasi aktif, induk berlokasi terbatas = lokasinya, induk semua lokasi = semua',
        'run'   => function ($t) {
            q69_recover($t);
            $s = $t->session();
            q69_purge($t);
            $base = q69_base($t);

            try {
                $c = $t->db();
                $active = $c->table('locations')->where('is_active', 1)->orderBy('id_location')->pluck('id_location')->all();
                $t->true(count($active) >= 3, 'prasyarat: minimal 3 lokasi aktif di DB uji (ada ' . count($active) . ')');
                $values = function ($r) {
                    $v = array_map(function ($o) { return $o['value']; }, $r[1]['result']['options'] ?? []);
                    sort($v);

                    return $v;
                };
                $f = q69_soft($t);

                $one = [$active[0]];
                $two = [$active[0], $active[1]];
                $FA = q69_folder($t, 'ALL');                                              // semua lokasi
                $F1 = q69_folder($t, 'L1', ['all_location' => 0, 'locations' => $one]);   // satu lokasi
                $F2 = q69_folder($t, 'L2', ['all_location' => 0, 'locations' => $two]);   // dua lokasi
                $FN = q69_folder($t, 'OFF', ['parent' => $F2, 'active' => 0, 'all_location' => 0, 'locations' => $two]); // nonaktif (perilaku lama: tetap dibaca)

                // tanpa induk
                $r = q69_loc($t, $s);
                $f->resp($r, 200, null, 'tanpa id_archive_parent');
                $f->eq($values($r), $active, 'tanpa induk = semua lokasi aktif');
                $r = q69_loc($t, $s, ['id_archive_parent' => '']);
                $f->resp($r, 200, null, 'id_archive_parent kosong');
                $f->eq($values($r), $active, 'induk kosong = semua lokasi aktif');

                // induk ada
                $r = q69_loc($t, $s, ['id_archive_parent' => $FA]);
                $f->resp($r, 200, null, 'induk semua lokasi');
                $f->eq($values($r), $active, 'induk semua lokasi = semua lokasi aktif');
                $r = q69_loc($t, $s, ['id_archive_parent' => $F1]);
                $f->resp($r, 200, null, 'induk satu lokasi');
                $f->eq($values($r), $one, 'induk satu lokasi = lokasinya');
                $r = q69_loc($t, $s, ['id_archive_parent' => $F2]);
                $f->resp($r, 200, null, 'induk dua lokasi');
                $f->eq($values($r), $two, 'induk dua lokasi = kedua lokasinya');
                $f->ok(array_key_exists('default', $r[1]['result'] ?? []) && $r[1]['result']['default'] === null, 'default = null');

                // induk tak ada = 404 ARCHIVE400 (K-4 a)
                $f->eq(q69_msg(q69_loc($t, $s, ['id_archive_parent' => Q69_RANDOM_ID])), 'Dokumen tidak ditemukan', 'teks ARCHIVE400 (ID)');
                foreach (['id acak' => Q69_RANDOM_ID, 'teks "x"' => 'x', 'kutip' => "x' OR '1'='1", 'panjang 400 char' => str_repeat('9', 400)] as $label => $v) {
                    $r = q69_loc($t, $s, ['id_archive_parent' => $v]);
                    $f->resp($r, 404, 'ARCHIVE400', "induk tak ada ($label)");
                }

                // dicatat, bukan asersi item ini: id "0" dibaca kosong oleh empty() (200 semua lokasi; ED-1124)
                $r0 = q69_loc($t, $s, ['id_archive_parent' => '0']);
                $t->note('observasi: id_archive_parent="0" -> HTTP ' . $r0[0] . ' (ED-1124)');

                // ED-1071 (AC-4): id di luar latin1 tidak mungkin ada = 404 ARCHIVE400 tanpa kueri (dulu 500 kolasi)
                foreach (['sirilik' => "\u{0431}\u{044f}", 'omega' => "\u{03A9}", 'emoji' => "\u{1F600}", 'cjk' => "\u{4E2D}\u{6587}"] as $label => $v) {
                    $r = q69_loc($t, $s, ['id_archive_parent' => $v]);
                    $f->resp($r, 404, 'ARCHIVE400', "induk di luar latin1 ($label)");
                }

                // tipe salah = 422 (bukan 500)
                foreach (['id_archive_parent[]=x', 'id_archive_parent[a]=x', 'id_archive_parent[0][b]=x', 'id_archive_parent[]='] as $q) {
                    $r = $t->call($s, 'GET', 'api/v5/select/document-archive/archive/locations?' . $q);
                    $f->resp($r, 422, null, "?$q");
                    $f->ok(isset($r[1]['errors']['id_archive_parent']), "?$q: errors.id_archive_parent");
                }

                // parameter lain tetap bekerja bersama induk
                $r = q69_loc($t, $s, ['id_archive_parent' => $F2, 'except' => [$active[1]]]);
                $f->resp($r, 200, null, 'induk dua lokasi + except');
                $f->eq($values($r), [$active[0]], 'except menyaring dari lokasi induk');

                // perilaku lama dipertahankan: induk nonaktif tetap dibaca (cek tipe/aktif ada di endpoint tulis, bukan select)
                $r = q69_loc($t, $s, ['id_archive_parent' => $FN]);
                $f->resp($r, 200, null, 'induk nonaktif (perilaku lama)');

                $f->done();
            } finally {
                q69_purge($t);
            }
            q69_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-11',
        'title' => 'folder F dengan 7 dokumen yatim (tipe 6,7,8,9,29,31,222): list showRelatedTransaction = 200 dan related_transactions=[]; dokumen bertransaksi = perilaku sebelum perubahan',
        'run'   => function ($t) {
            q69_recover($t);
            $s = $t->session();
            q69_purge($t);
            $base = q69_base($t);
            $uid = q69_uid($t);
            $f = q69_soft($t);

            try {
                // ---- bagian 1: dokumen bertransaksi nyata = hasil kode sebelum perubahan (ArchiveService di HEAD)
                $fqcn = q69_baseline_class($t);
                if ($fqcn === null) {
                    $t->note('MANUAL: kode pembanding (git show HEAD:...ArchiveService.php) tidak bisa dibaca; bagian "dokumen bertransaksi = baseline" dilewati');
                } else {
                    $nonEmpty = 0;
                    $compared = 0;
                    $perType = [];
                    q69_with_lang($t, 'EN', function () use ($t, $s, $f, $fqcn, $uid, &$nonEmpty, &$compared, &$perType) {
                        foreach ([6, 7, 8, 9, 29, 31, 222] as $type) {
                            $search = json_encode(['showRelatedTransaction' => true, 'type' => $type]);
                            $r = $t->call($s, 'GET', Q69_API . 'archives?pagination=25&search=' . urlencode($search));
                            $f->resp($r, 200, null, "list real tipe $type showRelatedTransaction");
                            $perType[$type] = [0, 0];
                            foreach ($r[1]['result']['data'] ?? [] as $row) {
                                if (strpos((string) ($row['name'][0]['transaction_no'] ?? ''), Q69_PREFIX) === 0) {
                                    continue;
                                }
                                $expected = q69_baseline_related($t, $fqcn, $row['document'], $uid);
                                $f->eq(json_encode($row['related_transactions'] ?? 'tidak ada'), json_encode($expected), 'tipe ' . $type . ' ' . ($row['name'][0]['transaction_no'] ?? '?') . ': related_transactions = baseline');
                                $compared++;
                                $perType[$type][0]++;
                                if (!empty($expected)) {
                                    $nonEmpty++;
                                    $perType[$type][1]++;
                                }
                            }
                        }
                    });
                    $t->note('dibandingkan dengan baseline (dokumen nyata aktif): ' . $compared . ' dokumen, ' . $nonEmpty . ' dengan transaksi terkait; per tipe [dokumen, berisi]: ' . json_encode($perType));
                    $f->ok($compared >= 20, 'minimal 20 dokumen nyata aktif dibandingkan (ada ' . $compared . ')');
                    $f->ok($nonEmpty >= 5, 'minimal 5 di antaranya punya transaksi terkait (ada ' . $nonEmpty . '), agar perbandingan bermakna');

                    // 1b. tipe 9, 29, 31, 222 hanya punya dokumen nyata nonaktif (is_active = -1) di DB ini: pakai dokumen uji milik
                    // sendiri yang menunjuk transaksi NYATA (id_transaction disalin dari baris archive_documents yang ada) di folder uji,
                    // agar ketujuh cabang transaksi utama dibandingkan dengan kode sebelum perubahan
                    $FR = q69_folder($t, 'FR');
                    $fixtures = [];
                    $perType2 = [];
                    foreach ([6, 7, 8, 9, 29, 31, 222] as $type) {
                        $picked = $t->db()->table('archive_documents')->where('transaction_type', $type)
                            ->where('id_transaction', 'not like', 'QA69TX%')->orderBy('id_archive_document')->distinct()->limit(6)->pluck('id_transaction')->all();
                        foreach ($picked as $i => $idTx) {
                            $id = q69_doc($t, 'R' . $type . 'x' . $i, ['parent' => $FR, 'doc' => ['type' => $type, 'tx' => $idTx]]);
                            $fixtures[$id] = $type;
                        }
                        $perType2[$type] = [count($picked), 0, 0];
                    }
                    $nonEmpty2 = 0;
                    $threw = 0;
                    q69_with_lang($t, 'EN', function () use ($t, $s, $f, $fqcn, $uid, $FR, $fixtures, &$perType2, &$nonEmpty2, &$threw) {
                        $search = json_encode(['showRelatedTransaction' => true]);
                        $r = $t->call($s, 'GET', Q69_API . 'archives?pagination=100&id_archive=' . $FR . '&search=' . urlencode($search));
                        $f->resp($r, 200, null, 'list FR (dokumen uji menunjuk transaksi nyata) showRelatedTransaction');
                        $rows = [];
                        foreach ($r[1]['result']['data'] ?? [] as $row) {
                            $rows[$row['id_archive']] = $row;
                        }
                        $f->eq(count($rows), count($fixtures), 'FR memuat semua dokumen uji');
                        foreach ($fixtures as $id => $type) {
                            $row = $rows[$id] ?? null;
                            if ($row === null) {
                                $f->ok(false, "dokumen uji tipe $type tidak ada di list");
                                continue;
                            }
                            try {
                                $expected = q69_baseline_related($t, $fqcn, $row['document'], $uid);
                            } catch (\Throwable $e) {
                                // kode sebelum perubahan 500 pada dokumen ini (transaksi nyata di archive_documents tetapi sudah tak ada di tabelnya):
                                // kode baru harus [] (tanpa 500)
                                $expected = [];
                                $threw++;
                                $perType2[$type][2]++;
                            }
                            $f->eq(json_encode($row['related_transactions'] ?? 'tidak ada'), json_encode($expected), "tipe $type tx " . $row['document']['id_transaction'] . ': related_transactions = baseline');
                            if (!empty($expected)) {
                                $nonEmpty2++;
                                $perType2[$type][1]++;
                            }
                        }
                    });
                    $t->note('dokumen uji -> transaksi nyata, per tipe [dipilih, berisi, baseline-melempar]: ' . json_encode($perType2) . '; berisi total ' . $nonEmpty2 . ', baseline melempar ' . $threw);
                    $f->ok(count($fixtures) >= 20, 'minimal 20 dokumen uji menunjuk transaksi nyata (ada ' . count($fixtures) . ')');
                    $f->ok($nonEmpty2 >= 5, 'minimal 5 dokumen uji punya transaksi terkait (ada ' . $nonEmpty2 . ')');
                    q69_purge($t);
                }

                // ---- bagian 2: dokumen yatim
                $F = q69_folder($t, 'F');
                $docs = [];
                foreach ([6, 7, 8, 9, 29, 31, 222] as $type) {
                    $docs[$type] = q69_doc($t, 'O' . $type, ['parent' => $F, 'doc' => ['type' => $type]]);
                }
                // dokumen bertransaksi tipe tak dikenal / nol: cabang default juga harus [] (pembanding)
                $docs[999] = q69_doc($t, 'O999', ['parent' => $F, 'doc' => ['type' => 999]]);

                $search = json_encode(['showRelatedTransaction' => true]);
                $r = $t->call($s, 'GET', Q69_API . 'archives?pagination=100&id_archive=' . $F . '&search=' . urlencode($search));
                $f->resp($r, 200, null, 'list F showRelatedTransaction (7 dokumen yatim)');
                $rows = [];
                foreach ($r[1]['result']['data'] ?? [] as $row) {
                    $rows[$row['id_archive']] = $row;
                }
                $f->eq(count($rows), 8, 'F berisi 8 dokumen (7 tipe + 1 tipe tak dikenal)');
                foreach ($docs as $type => $id) {
                    $row = $rows[$id] ?? null;
                    $f->ok($row !== null, "dokumen tipe $type ada di list");
                    if ($row !== null) {
                        $f->ok(array_key_exists('related_transactions', $row) && $row['related_transactions'] === [], "tipe $type: related_transactions === []");
                    }
                }

                // tanpa showRelatedTransaction: kunci tidak ada, 200 (jalur lama)
                $r = $t->call($s, 'GET', Q69_API . 'archives?pagination=100&id_archive=' . $F);
                $f->resp($r, 200, null, 'list F tanpa showRelatedTransaction');
                $f->ok(!array_key_exists('related_transactions', $r[1]['result']['data'][0] ?? []), 'tanpa showRelatedTransaction: tanpa kunci related_transactions');

                // showRelatedTransaction + search lain / bentuk lain
                foreach ([
                    'bersama type' => ['showRelatedTransaction' => true, 'type' => 7],
                    'bersama FOLDER' => ['showRelatedTransaction' => true, 'type' => 'FOLDER'],
                    'false' => ['showRelatedTransaction' => false],
                ] as $label => $sp) {
                    $r = $t->call($s, 'GET', Q69_API . 'archives?pagination=100&id_archive=' . $F . '&search=' . urlencode(json_encode($sp)));
                    $f->resp($r, 200, null, "list F search $label");
                }
                $r = $t->call($s, 'GET', Q69_API . 'archives?pagination=50&search=' . urlencode(json_encode(['showRelatedTransaction' => true, 'type' => 6])));
                $f->resp($r, 200, null, 'list semua level, tipe 6, dengan dokumen yatim di DB');
                $r = $t->call($s, 'GET', Q69_API . 'archives?pagination=50&search=' . urlencode(json_encode(['showRelatedTransaction' => true, 'type' => 222])));
                $f->resp($r, 200, null, 'list semua level, tipe 222, dengan dokumen yatim di DB');

                $f->done();
            } finally {
                q69_purge($t);
            }
            q69_assert_clean($t, $base);
        },
    ],

];
