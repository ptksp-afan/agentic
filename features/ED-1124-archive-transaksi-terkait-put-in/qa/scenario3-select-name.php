<?php
/**
 * ED-1124 AC-6 (select lokasi induk "0"), AC-7 (K-2 b: findName hanya dokumen: put-in name / hand-over / receive),
 * AC-8 (K-2 b: DocumentService::store tidak memakai folder senama), X-1 (tinjauan diff BE). Data uji berawalan QA124-.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'AC-6',
        'title' => 'select lokasi: id_archive_parent=0 -> 404 ARCHIVE400; tanpa parameter / kosong -> 200 semua lokasi aktif; induk berlokasi -> lokasinya; id acak -> 404',
        'run'   => function ($t) {
            $s = $t->session();
            q124_purge($t);
            $base = q124_base($t);

            try {
                $f = q124_soft($t);
                $active = $t->db()->table('locations')->where('is_active', 1)->orderBy('id_location')->pluck('id_location')->all();
                $t->true(count($active) >= 2, 'prasyarat: minimal 2 lokasi aktif (ada ' . count($active) . ')');
                $values = function ($r) {
                    $v = array_map(function ($o) { return $o['value']; }, $r[1]['result']['options'] ?? []);
                    sort($v);

                    return $v;
                };

                // "0" = id yang tidak ada
                foreach (['"0"' => '0', '" 0 " (dipangkas)' => ' 0 ', '"00"' => '00', '"0.0"' => '0.0', '"-0"' => '-0'] as $label => $v) {
                    $r = q124_loc($t, $s, ['id_archive_parent' => $v]);
                    $f->resp($r, 404, 'ARCHIVE400', "id_archive_parent=$label");
                }
                $r = q124_loc($t, $s, ['id_archive_parent' => '0']);
                $f->eq(q124_msg($r), 'Dokumen tidak ditemukan', 'teks ARCHIVE400 (ID)');
                $f->ok(!isset($r[1]['result']['options']), 'induk "0": tanpa options (bukan semua lokasi)');
                $r = $t->call($s, 'GET', 'api/v5/select/document-archive/archive/locations?id_archive_parent=0&search=a&except[]=' . $active[0]);
                $f->resp($r, 404, 'ARCHIVE400', 'id_archive_parent=0 bersama search + except');

                // tanpa parameter / kosong = semua lokasi aktif
                $r = q124_loc($t, $s);
                $f->resp($r, 200, null, 'tanpa id_archive_parent');
                $f->eq($values($r), $active, 'tanpa induk = semua lokasi aktif');
                $r = q124_loc($t, $s, ['id_archive_parent' => '']);
                $f->resp($r, 200, null, 'id_archive_parent kosong');
                $f->eq($values($r), $active, 'induk kosong = semua lokasi aktif');
                $r = $t->call($s, 'GET', 'api/v5/select/document-archive/archive/locations?id_archive_parent');
                $f->resp($r, 200, null, '?id_archive_parent (tanpa tanda sama dengan)');
                $f->eq($values($r), $active, 'tanpa nilai = semua lokasi aktif');
                $r = q124_loc($t, $s, ['id_archive_parent' => '   ']);
                $f->resp($r, 200, null, 'id_archive_parent spasi saja (dipangkas)');
                $f->eq($values($r), $active, 'spasi saja = semua lokasi aktif');

                // induk ada: lokasinya (semua lokasi / terbatas)
                $one = [$active[0]];
                $two = [$active[0], $active[1]];
                $FA = q124_folder($t, 'ALL');
                $F1 = q124_folder($t, 'L1', ['all_location' => 0, 'locations' => $one]);
                $F2 = q124_folder($t, 'L2', ['all_location' => 0, 'locations' => $two]);
                $FN = q124_folder($t, 'OFF', ['parent' => $F2, 'active' => 0, 'all_location' => 0, 'locations' => $two]);
                foreach (['semua lokasi' => [$FA, $active], 'satu lokasi' => [$F1, $one], 'dua lokasi' => [$F2, $two]] as $label => $pair) {
                    $r = q124_loc($t, $s, ['id_archive_parent' => $pair[0]]);
                    $f->resp($r, 200, null, "induk $label");
                    $f->eq($values($r), $pair[1], "induk $label = lokasinya");
                    $f->ok(array_key_exists('default', $r[1]['result'] ?? []) && $r[1]['result']['default'] === null, "induk $label: default = null");
                }
                // induk dihapus: perilaku lama tetap 200 lokasinya (di luar cakupan item)
                $r = q124_loc($t, $s, ['id_archive_parent' => $FN]);
                $f->resp($r, 200, null, 'induk dihapus tetap 200');
                $f->eq($values($r), $two, 'induk dihapus = lokasinya');

                // id acak / teks / panjang = 404 (ED-1069 AC-10 tetap); tipe salah = 422
                foreach (['id acak' => Q124_RANDOM_ID, 'teks "x"' => 'x', 'kutip' => "x' OR '1'='1", 'panjang 400 char' => str_repeat('9', 400)] as $label => $v) {
                    $r = q124_loc($t, $s, ['id_archive_parent' => $v]);
                    $f->resp($r, 404, 'ARCHIVE400', "induk tak ada ($label)");
                }
                foreach (['id_archive_parent[]=0', 'id_archive_parent[a]=0', 'id_archive_parent[]=x'] as $q) {
                    $r = $t->call($s, 'GET', 'api/v5/select/document-archive/archive/locations?' . $q);
                    $f->resp($r, 422, null, "?$q");
                }

                $f->done();
            } finally {
                q124_purge($t);
            }
            q124_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-7',
        'title' => 'K-2 b: folder FQ bernama N1 tanpa dokumen N1 -> put-in name / hand-over / receive = 404 ARCHIVE400, FQ tidak berubah; folder FQ3 (id lebih kecil) + dokumen D3 senama N3 -> put-in name memindah D3, hand-over/receive mengubah D3, FQ3 tidak berubah',
        'run'   => function ($t) {
            $s = $t->session();
            q124_purge($t);
            $base = q124_base($t);

            try {
                $f = q124_soft($t);
                $P = q124_folder($t, 'P');
                $T = q124_folder($t, 'T');

                // ---- bagian 1: nama hanya cocok folder
                $n1 = q124_name('N1');
                $FQ = q124_folder($t, 'FQ', ['parent' => $P, 'name' => $n1]);
                $snap = q124_snap($t, [$P, $T, $FQ]);
                $r = q124_putin($t, $s, $T, null, $n1);
                $f->resp($r, 404, 'ARCHIVE400', 'put-in name = nama folder saja');
                foreach (['hand-over', 'receive'] as $action) {
                    $r = q124_move($t, $s, $action, $n1);
                    $f->resp($r, 404, 'ARCHIVE400', "$action name = nama folder saja");
                    $f->eq(q124_msg($r), 'Dokumen tidak ditemukan', "$action: pesan ARCHIVE400 (ID)");
                }
                $f->ok(q124_snap($t, [$P, $T, $FQ]) === $snap, 'FQ (induk, status, history, is_active) dan P, T tidak berubah');
                $f->eq([q124_parent($t, $FQ), (int) q124_row($t, $FQ)['status'], q124_history($t, $FQ)], [$P, 1, []], 'FQ: induk tetap P, status 1, tanpa history');

                // ---- bagian 2: folder lebih tua + dokumen senama
                $n3 = q124_name('N3');
                // id = kode + time() + 10 angka acak (MyHelper::generateId): dua id dalam detik yang sama berurutan acak, jadi
                // urutan tidak boleh diserahkan ke waktu pembuatan -> dua id dibangkitkan lalu diurut; folder FQ3 mendapat yang lebih kecil
                do {
                    $pair = [q124_new_id($t), q124_new_id($t)];
                    sort($pair, SORT_STRING);
                } while ($pair[0] === $pair[1]);
                $FQ3 = q124_folder($t, 'FQ3', ['parent' => $P, 'name' => $n3, 'id' => $pair[0]]);   // folder lebih tua = id lebih kecil
                $P2 = q124_folder($t, 'P2');
                $D3 = q124_doc($t, 'D3', ['parent' => $P2, 'name' => $n3, 'id' => $pair[1]]);
                $t->true(strlen($FQ3) === strlen($D3) && strcmp($FQ3, $D3) < 0, 'prasyarat: id FQ3 < id D3 (folder lebih tua)');
                // kontrol K-2: tanpa penyaring type, baris pertama menurut indeks name = FQ3 (inilah yang dulu terpilih)
                $first = $t->db()->selectOne('select id_archive from archives where name = ? limit 1', [$n3]);
                $f->eq($first->id_archive ?? null, $FQ3, 'kontrol K-2: pencarian nama tanpa type mengembalikan folder FQ3 (baris tertua)');
                $snap3 = q124_snap($t, [$FQ3, $P]);

                $r = q124_putin($t, $s, $T, null, $n3);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in name = N3 (folder senama lebih tua)');
                $f->eq(array_column($r[1]['result'] ?? [], 'id_archive'), [$D3], 'result = D3 (bukan FQ3)');
                $f->eq([q124_parent($t, $D3), q124_actions($t, $D3)], [$T, ['move']], 'D3 pindah dari P2 ke T, history move');
                $f->ok(q124_snap($t, [$FQ3, $P]) === $snap3, 'FQ3 tidak berubah oleh put-in');

                $r = q124_move($t, $s, 'hand-over', $n3);
                $f->resp($r, 200, 'ARCHIVE209', 'hand-over N3');
                $f->eq(q124_row($t, $D3)['status'], 2, 'D3 status 2 (Handed Over)');
                $f->eq(q124_actions($t, $D3), ['move', 'give'], 'D3 history: move, give');
                $f->ok(q124_snap($t, [$FQ3, $P]) === $snap3, 'FQ3 tidak berubah oleh hand-over');

                $r = q124_move($t, $s, 'receive', $n3);
                $f->resp($r, 200, 'ARCHIVE210', 'receive N3');
                $f->eq(q124_row($t, $D3)['status'], 3, 'D3 status 3 (Taken)');
                $f->eq(q124_actions($t, $D3), ['move', 'give', 'receive'], 'D3 history: move, give, receive');
                $f->ok(q124_snap($t, [$FQ3, $P]) === $snap3, 'FQ3 tidak berubah oleh receive');
                $f->eq([(int) q124_row($t, $FQ3)['status'], q124_history($t, $FQ3), q124_parent($t, $FQ3)], [1, [], $P], 'FQ3: status 1, tanpa history, induk tetap P');

                $f->done();
            } finally {
                q124_purge($t);
            }
            q124_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-8',
        'title' => 'K-2 b: folder FQ2 bernama N2; DocumentService::store (transaction_no=N2, tipe 7) -> 1 baris archives type=2 is_active=-1 + archive_documents; panggilan kedua tidak menggandakan; FQ2 tidak berubah',
        'run'   => function ($t) {
            q124_purge($t);
            $base = q124_base($t);
            $db = q124_dbname($t);

            try {
                $f = q124_soft($t);
                $P = q124_folder($t, 'P');
                $n2 = q124_name('N2');
                $FQ2 = q124_folder($t, 'FQ2', ['parent' => $P, 'name' => $n2]);
                $snap = q124_snap($t, [$FQ2, $P]);
                $txId = 'QA124TXS' . substr(uniqid(), -6);

                $call = function () use ($t, $db, $n2, $txId) {
                    return $t->probe(function () use ($db, $n2, $txId) {
                        \Illuminate\Support\Facades\DB::setDefaultConnection($db);
                        $archive = (new \Modules\V5\Http\Services\DocumentArchive\DocumentService)->store([
                            'transaction_type' => 7, 'transaction_no' => $n2, 'id_transaction' => $txId, 'id_related_employee' => null,
                        ]);

                        return $archive ? [$archive->id_archive, $archive->type, $archive->is_active] : null;
                    });
                };

                $c = $t->db();
                $first = $call();
                $f->ok($first !== null, 'store panggilan pertama membuat dokumen baru');
                $f->ok($first !== null && $first[0] !== $FQ2, 'store tidak mengembalikan folder FQ2');
                $f->eq($c->table('archives')->where('name', $n2)->count(), 2, 'archives bernama N2: FQ2 + 1 dokumen baru');
                $f->eq($c->table('archives')->where('name', $n2)->where('type', 2)->count(), 1, 'tepat 1 baris type=2');
                $docRow = $c->table('archives')->where('name', $n2)->where('type', 2)->first();
                $f->eq([(int) ($docRow->is_active ?? 99), (int) ($docRow->type ?? 0)], [-1, 2], 'dokumen baru: is_active -1, type 2');
                $f->eq($docRow->id_archive ?? null, $first[0] ?? 'x', 'id dokumen baru = id yang dikembalikan');
                $adRows = $c->table('archive_documents')->where('transaction_no', $n2)->get();
                $f->eq(count($adRows), 1, 'tepat 1 baris archive_documents');
                $f->eq([$adRows[0]->id_archive ?? null, (int) ($adRows[0]->transaction_type ?? 0), $adRows[0]->id_transaction ?? null], [$docRow->id_archive ?? null, 7, $txId], 'archive_documents menunjuk dokumen baru, tipe 7, id_transaction QA');
                $f->ok(q124_snap($t, [$FQ2, $P]) === $snap, 'FQ2 tidak berubah oleh store');

                // panggilan kedua: memakai ulang dokumen yang ada
                $second = $call();
                $f->eq($second[0] ?? null, $first[0] ?? 'x', 'store kedua mengembalikan dokumen yang sama');
                $f->eq($c->table('archives')->where('name', $n2)->count(), 2, 'sesudah panggilan kedua: tetap 2 baris archives');
                $f->eq($c->table('archive_documents')->where('transaction_no', $n2)->count(), 1, 'sesudah panggilan kedua: tetap 1 baris archive_documents');
                $f->ok(q124_snap($t, [$FQ2, $P]) === $snap, 'FQ2 tidak berubah oleh store kedua');

                // dokumen dihapus tetap dipakai ulang (satu-satunya jalan menyimpan ulang; DocumentService.php store)
                q124_set($t, $first[0], ['is_active' => 0]);
                $third = $call();
                $f->eq($third[0] ?? null, $first[0] ?? 'x', 'store ketiga (dokumen dihapus) memakai ulang baris yang sama');
                $f->eq($c->table('archives')->where('name', $n2)->count(), 2, 'sesudah store ketiga: tetap 2 baris archives');
                $f->ok(q124_snap($t, [$FQ2, $P]) === $snap, 'FQ2 tidak berubah oleh store ketiga');

                // put-in name N2 sesudah store: memindah dokumen, bukan folder
                $T = q124_folder($t, 'T');
                $s = $t->session();
                $r = q124_putin($t, $s, $T, null, $n2);
                $f->resp($r, 200, 'ARCHIVE204', 'put-in name N2 sesudah store');
                $f->eq(array_column($r[1]['result'] ?? [], 'id_archive'), [$first[0] ?? 'x'], 'result = dokumen hasil store');
                $f->eq([q124_parent($t, $first[0]), (int) q124_row($t, $first[0])['is_active']], [$T, 1], 'dokumen hasil store di T, aktif');
                $f->ok(q124_snap($t, [$FQ2, $P]) === $snap, 'FQ2 tidak berubah oleh put-in');

                $f->done();
            } finally {
                q124_purge($t);
            }
            q124_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-3',
        'title' => 'kontrol negatif: kode baseline (be.head) di fixture yang sama = cacat tereproduksi (put-in mengaktifkan lagi sumber dihapus; findName/store memakai folder senama) - membuktikan AC-4/AC-7/AC-8 sensitif',
        'run'   => function ($t) {
            q124_purge($t);
            $base = q124_base($t);
            $db = q124_dbname($t);
            $be = $t->conf('BE_DIR');
            $show = function ($path) use ($be) {
                return shell_exec('git -C ' . escapeshellarg($be) . ' show ' . escapeshellarg(q124_baseline_ref() . ':' . $path) . ' 2>/dev/null');
            };
            $archiveSrc = $show('Modules/V5/Entities/Models/Archive.php');
            $serviceSrc = $show('Modules/V5/Http/Services/DocumentArchive/DocumentService.php');
            if (!is_string($archiveSrc) || strpos($archiveSrc, 'class Archive extends Model') === false || strpos($archiveSrc, 'ED-1124') !== false
                || !is_string($serviceSrc) || strpos($serviceSrc, 'class DocumentService extends DocumentArchiveService') === false) {
                $t->note('MANUAL: kode baseline (git show ' . q124_baseline_ref() . ') tidak bisa dibaca; kontrol negatif dilewati');

                return;
            }
            if (!class_exists('Modules\\V5\\Entities\\Models\\Q124BaseArchive', false)) {
                $a = preg_replace('/^<\?php/', '', $archiveSrc, 1);
                $a = str_replace('class Archive extends Model', "class Q124BaseArchive extends Model\n{\n    protected \$table = 'archives';\n    // ", $a);
                $a = preg_replace('/class Q124BaseArchive extends Model\n\{\n    protected \$table = \'archives\';\n    \/\/ \s*\n\{/', "class Q124BaseArchive extends Model\n{\n    protected \$table = 'archives';\n", $a, 1);
                eval($a);
                $d = preg_replace('/^<\?php/', '', $serviceSrc, 1);
                $d = str_replace('class DocumentService extends DocumentArchiveService', 'class Q124BaseDocumentService extends DocumentArchiveService', $d);
                $d = preg_replace('/\bArchive::/', '\\Modules\\V5\\Entities\\Models\\Q124BaseArchive::', $d);
                eval($d);
            }

            try {
                $f = q124_soft($t);
                $P = q124_folder($t, 'P');
                $T = q124_folder($t, 'T');
                $dDel = q124_doc($t, 'DDEL', ['parent' => $P, 'active' => 0]);
                $fDel = q124_folder($t, 'FDEL', ['parent' => $P, 'active' => 0]);
                $n2 = q124_name('N2');
                $FQ2 = q124_folder($t, 'FQ2', ['parent' => $P, 'name' => $n2]);

                $user = $t->conf('QA_USER');
                $out = $t->probe(function () use ($db, $T, $dDel, $fDel, $n2, $user) {
                    \Illuminate\Support\Facades\DB::setDefaultConnection($db);
                    $u = \Modules\V5\Entities\Models\User::where('username', $user)->first();
                    if ($u) {
                        \Illuminate\Support\Facades\Auth::setUser($u);
                    }
                    $res = [];
                    $res['findName'] = \Modules\V5\Entities\Models\Q124BaseArchive::findName($n2);
                    $res['findName'] = $res['findName'] ? [$res['findName']->id_archive, (int) $res['findName']->type] : null;
                    $svc = new \Modules\V5\Http\Services\DocumentArchive\Q124BaseDocumentService;
                    $stored = $svc->store(['transaction_type' => 7, 'transaction_no' => $n2, 'id_transaction' => 'QA124TXBASE', 'id_related_employee' => null]);
                    $res['store'] = $stored ? [$stored->id_archive, (int) $stored->type] : null;
                    $moved = $svc->putInFolder(['id_archive_parent' => $T, 'id_archives' => [$dDel, $fDel]]);
                    $res['putin'] = $moved->pluck('id_archive')->all();

                    return $res;
                });
                $t->note('baseline: ' . json_encode($out));
                $f->eq($out['findName'] ?? null, [$FQ2, 1], 'baseline findName(N2) = folder FQ2 (type 1)');
                $f->eq($out['store'] ?? null, [$FQ2, 1], 'baseline store() mengembalikan folder FQ2 (tanpa membuat dokumen)');
                $f->eq($t->db()->table('archives')->where('name', $n2)->where('type', 2)->count(), 0, 'baseline store(): tidak ada dokumen N2 terbuat');
                $ids = $out['putin'] ?? [];
                sort($ids);
                $want = [$dDel, $fDel];
                sort($want);
                $f->eq($ids, $want, 'baseline put-in [dokumen dihapus, folder dihapus] memproses keduanya');
                $f->eq([(int) q124_row($t, $dDel)['is_active'], (int) q124_row($t, $fDel)['is_active'], q124_parent($t, $dDel), q124_parent($t, $fDel)], [1, 1, $T, $T], 'baseline: keduanya aktif lagi di T (cacat butir 2)');

                $f->done();
            } finally {
                q124_purge($t);
            }
            q124_assert_clean($t, $base);
        },
    ],


    [
        'id'    => 'X-1',
        'title' => 'tinjauan diff BE: hanya 3 berkas Modules/V5, tanpa Updater/route/kode pesan baru, tanpa state statis/cache/mutasi config; php -l; kode pesan ARCHIVE400 ada di kedua berkas bahasa',
        'run'   => function ($t) {
            $be = $t->conf('BE_DIR');
            $expected = [
                'Modules/V5/Entities/Models/Archive.php',
                'Modules/V5/Http/Services/DocumentArchive/ArchiveService.php',
                'Modules/V5/Http/Services/Select/DocumentArchive/SelectArchiveService.php',
            ];
            $f = q124_soft($t);

            $porcelain = array_filter(preg_split('/\R/', (string) shell_exec('git -C ' . escapeshellarg($be) . ' status --porcelain 2>/dev/null')));
            $committed = count($porcelain) === 0;
            if ($committed) {
                $t->note('3 berkas sudah di-commit (git status BE bersih): pemeriksaan changed.txt/status/diff dilewati, php -l tetap');
            } else {
                $files = [];
                foreach (preg_split('/\R/', (string) file_get_contents(__DIR__ . '/../changed.txt')) as $line) {
                    $cols = preg_split('/\s+/', trim($line));
                    if (count($cols) >= 4 && $cols[0] === 'be') {
                        $files[] = $cols[3];
                    }
                }
                sort($files);
                $f->eq($files, $expected, 'changed.txt = 3 berkas BE yang diharapkan');

                // status git BE: tidak ada berkas lain berubah/baru (Updater, route, lang, migration)
                $paths = array_map(function ($l) { return trim(substr($l, 3)); }, $porcelain);
                sort($paths);
                $f->eq($paths, $expected, 'git status BE hanya memuat 3 berkas itu (tanpa Updater/route/lang/migration baru)');
            }

            $hazards = [];
            $codes = [];
            foreach ($expected as $path) {
                $php = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($be . '/' . $path) . ' 2>&1');
                $f->ok(strpos($php, 'No syntax errors') !== false, "php -l $path");
                $diff = (string) shell_exec('git -C ' . escapeshellarg($be) . ' diff HEAD -- ' . escapeshellarg($path) . ' 2>/dev/null');
                foreach (preg_split('/\R/', $diff) as $line) {
                    if (!isset($line[0]) || $line[0] !== '+' || strpos($line, '+++') === 0) {
                        continue;
                    }
                    $code = preg_replace('/\/\/.*$/', '', $line);
                    if (preg_match('/\b(static\s+\$|static::\$|self::\$[a-zA-Z_]+\s*=|Cache::|config\s*\(\s*\[|\$GLOBALS|putenv|setDefaultConnection|DB::purge|date_default_timezone_set|ini_set)/', $code)) {
                        $hazards[] = $path . ': ' . trim($line);
                    }
                    if (preg_match_all("/'((?:ARCHIVE|GE)\d+)'/", $line, $m)) {
                        $codes = array_merge($codes, $m[1]);
                    }
                }
            }
            $f->eq($hazards, [], 'diff tanpa state statis/cache/mutasi config/koneksi');
            $f->eq($codes, [], 'diff tidak menambah/memakai kode pesan baru');

            foreach (['id_ID.php', 'en_EN.php'] as $lang) {
                $txt = (string) @file_get_contents($be . '/app/Lib/lang/' . $lang);
                $f->ok(strpos($txt, "'ARCHIVE400'") !== false || strpos($txt, '"ARCHIVE400"') !== false, "kode ARCHIVE400 ada di $lang");
            }
            $f->done();
        },
    ],

];
