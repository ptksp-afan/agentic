<?php
/**
 * ED-1071 ketahanan (di luar AC, melengkapi AC-1..AC-9):
 *   X-1 bentuk body lain (form-urlencoded, JSON rusak), kontrol C1 / NUL / panjang / bersarang, karakter khusus Windows-1252 diterima utuh;
 *   X-2 MyHelper::isLatin1 dibanding perilaku MySQL latin1 yang sebenarnya untuk seluruh BMP (diterima helper = selamat di latin1);
 *   X-3 tinjauan diff BE: kode GE0748 di kedua berkas bahasa, php -l 17 berkas, tanpa state statis/cache baru, bentuk 422 sama dengan 422 lain.
 * Tanpa 5xx, tanpa tulis pada penolakan. Data uji berawalan QA71-.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'X-1',
        'title' => 'Body form-urlencoded / JSON rusak, C1, NUL, string sangat panjang, nilai bersarang: tanpa 5xx, S_BAD tetap 422 GE0748, tanpa tulis; karakter khusus Windows-1252 (Œ Š Ž Ÿ • … ‰ „ “ ”) diterima utuh (HEX cp1252)',
        'run'   => function ($t) {
            q71_recover($t);
            $s = $t->session();
            q71_purge($t);
            $base = q71_base($t);
            $f = q71_soft($t);

            try {
                $locs = array_values(q71_locs($t));
                $me = q71_uid($t);
                $target = $t->db()->table('users')->where('is_active', 1)->where('username', '<>', $t->conf('QA_USER'))->orderBy('username')->value('id_user');
                $P = q71_folder($t, 'P', ['description' => 'desc-awal']);
                $T = q71_folder($t, 'T');
                $D = q71_doc($t, 'D', ['parent' => $P]);
                $pName = q71_row($t, $P)['name'];
                $ids = [$P, $T, $D];
                $before = q71_snap($t, $ids);
                $form = 'application/x-www-form-urlencoded';

                $deny = function ($label, $method, $uri, $raw, array $keys, $ct = 'application/x-www-form-urlencoded') use ($t, $s, $f, $ids, &$before) {
                    $r = $f->raw($s, $method, Q71_API . $uri, $raw, $ct, $label);
                    $f->ge0748($r, $keys, $label);
                    $f->eq(q71_snap($t, $ids), $before, "$label: tanpa tulis");
                };

                // --- form-urlencoded: UTF-8 rusak (%C3%28) dan S_BAD (Ω = %CE%A9) di setiap field Latin1Rule
                foreach (['rusak' => '%C3%28', 'omega' => '%CE%A9', 'emoji' => '%F0%9F%98%80'] as $lab => $enc) {
                    $deny("form create name [$lab]", 'POST', 'archives/create-folder', "name=QA71-x$enc&is_all_location=1", ['name']);
                    $deny("form create description [$lab]", 'POST', 'archives/create-folder', "name=QA71-ok-" . uniqid() . "&description=a$enc&is_all_location=1", ['description']);
                    $deny("form create id_archive_parent [$lab]", 'POST', 'archives/create-folder', "name=QA71-ok-" . uniqid() . "&id_archive_parent=$enc&is_all_location=1", ['id_archive_parent']);
                    $deny("form create id_locations.1 [$lab]", 'POST', 'archives/create-folder', 'name=QA71-ok-' . uniqid() . '&is_all_location=0&id_locations[0]=' . $locs[0] . "&id_locations[1]=$enc", ['id_locations.1']);
                    $deny("form PUT name [$lab]", 'PUT', 'archives/' . $P, 'name=' . rawurlencode($pName) . "$enc&is_all_location=1", ['name']);
                    $deny("form PUT description [$lab]", 'PUT', 'archives/' . $P, 'name=' . rawurlencode($pName) . "&description=a$enc&is_all_location=1", ['description']);
                    $deny("form rename name [$lab]", 'PUT', 'archives/rename/' . $P, "name=QA71-r$enc", ['name']);
                    $deny("form put-in id_archive_parent [$lab]", 'POST', 'documents/put-in', "id_archive_parent=$enc&id_archives[0]=$D", ['id_archive_parent']);
                    $deny("form put-in id_archives.0 [$lab]", 'POST', 'documents/put-in', "id_archive_parent=$T&id_archives[0]=$enc", ['id_archives.0']);
                    $deny("form put-in name [$lab]", 'POST', 'documents/put-in', "id_archive_parent=$T&name=$enc", ['name']);
                    $deny("form hand-over name [$lab]", 'POST', 'documents/hand-over', "name=$enc&action=give", ['name']);
                    $deny("form receive name [$lab]", 'POST', 'documents/receive', "name=$enc&action=receive", ['name']);
                    $deny("form add-document id_archive [$lab]", 'POST', 'archives/add-document', "id_archive=$enc", ['id_archive']);
                    $deny("form user-permissions permissions.0.id_archive [$lab]", 'PUT', 'user-permissions/' . $target, "permissions[0][id_archive]=$enc&permissions[0][is_view]=1&permissions[0][is_update]=0&permissions[0][is_delete]=0&permissions[0][is_store]=0", ['permissions.0.id_archive']);
                    $deny("form user-permissions folders.0.id_archive [$lab]", 'PUT', 'user-permissions/' . $target, "permissions[0][id_archive]=$P&permissions[0][is_view]=1&permissions[0][is_update]=0&permissions[0][is_delete]=0&permissions[0][is_store]=0&folders[0][id_archive]=$enc&folders[0][is_folder_permission]=1", ['folders.0.id_archive']);
                }

                // --- JSON dengan byte rusak / surrogate sendirian: body tak ter-decode = input kosong -> 422 aturan wajib (bukan 500), tanpa tulis
                foreach (['byte rusak' => "{\"name\":\"QA71-x\xC3\x28\",\"is_all_location\":1}", 'surrogate sendirian' => '{"name":"QA71-x\ud800","is_all_location":1}'] as $lab => $raw) {
                    foreach ([['POST', 'archives/create-folder'], ['PUT', 'archives/' . $P], ['PUT', 'archives/rename/' . $P], ['POST', 'documents/put-in'], ['POST', 'archives/add-document']] as [$method, $uri]) {
                        $r = $f->raw($s, $method, Q71_API . $uri, $raw, 'application/json', "JSON $lab $method $uri");
                        // put-in tanpa isian bukan 422 (semua field nullable; aturan sumber 400), selebihnya aturan wajib = 422
                        $f->ok($uri === 'documents/put-in' ? ($r[0] >= 400 && $r[0] < 500) : $r[0] === 422, "JSON $lab $method $uri: 4xx/422 (HTTP {$r[0]})");
                        $f->eq(q71_snap($t, $ids), $before, "JSON $lab $method $uri: tanpa tulis");
                    }
                }

                // --- C1 (U+0081 dst: tersimpan di MySQL latin1 tetapi tak bolak-balik di Windows-1252 mbstring = ditolak, catatan implementasi), Latin Extended-A, BOM
                $deny422 = function ($label, $method, $uri, $body) use ($t, $s, $f, $ids, &$before) {
                    $r = $f->call($s, $method, Q71_API . $uri, $body, $label);
                    $f->ge0748($r, [], $label);
                    $f->eq(q71_snap($t, $ids), $before, "$label: tanpa tulis");
                };
                foreach (['C1 U+0081' => "\u{0081}", 'C1 U+008D' => "\u{008D}", 'C1 U+009D' => "\u{009D}", 'A-makron U+0100' => "\u{0100}", 'i-tanpa-titik U+0131' => "\u{0131}", 'BOM U+FEFF' => "\u{FEFF}", 'ZWJ U+200D' => "\u{200D}", 'aksen gabung U+0300' => "\u{0300}", 'astral U+10000' => "\u{10000}"] as $lab => $ch) {
                    $deny422("create name $lab", 'POST', 'archives/create-folder', ['name' => 'QA71-c-' . uniqid() . $ch, 'is_all_location' => 1]);
                    $deny422("rename $lab", 'PUT', 'archives/rename/' . $P, ['name' => 'QA71-c-' . uniqid() . $ch]);
                }

                // --- string sangat panjang (S_BAD di ujung) = 422 cepat; bersarang / tipe salah = 422 tanpa 5xx
                $t0 = microtime(true);
                $r = $f->call($s, 'POST', Q71_API . 'archives/create-folder', ['name' => str_repeat('a', 200000) . "\u{1F600}", 'is_all_location' => 1], 'name 200 KB + emoji');
                $f->ge0748($r, ['name'], 'name 200 KB + emoji');
                $f->ok((microtime(true) - $t0) < 5.0, 'name 200 KB + emoji ditolak < 5 detik (' . round(microtime(true) - $t0, 2) . ' dtk)');
                $r = $f->call($s, 'PUT', Q71_API . 'archives/' . $P, ['name' => $pName, 'is_all_location' => 1, 'description' => str_repeat("\u{4E2D}", 70000)], 'description 70000 CJK');
                $f->ge0748($r, ['description'], 'description 70000 CJK');
                $f->eq(q71_snap($t, $ids), $before, 'string panjang: tanpa tulis');

                $nested = [
                    'create name [S_BAD]'                       => ['POST', 'archives/create-folder', ['name' => ["\u{03A9}"], 'is_all_location' => 1]],
                    'create name {a: S_BAD}'                     => ['POST', 'archives/create-folder', ['name' => ['a' => "\u{03A9}"], 'is_all_location' => 1]],
                    'create id_locations [[S_BAD]]'              => ['POST', 'archives/create-folder', ['name' => 'QA71-n-' . uniqid(), 'is_all_location' => 0, 'id_locations' => [["\u{03A9}"]]]],
                    'create id_archive_parent [S_BAD]'           => ['POST', 'archives/create-folder', ['name' => 'QA71-n-' . uniqid(), 'is_all_location' => 1, 'id_archive_parent' => ["\u{03A9}"]]],
                    'PUT folder_permissions [{id_user:[S_BAD]}]' => ['PUT', 'archives/' . $P, ['name' => $pName, 'is_all_location' => 1, 'folder_permissions' => [['id_user' => ["\u{03A9}"], 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0]]]],
                    'PUT folder_permissions [[S_BAD]]'           => ['PUT', 'archives/' . $P, ['name' => $pName, 'is_all_location' => 1, 'folder_permissions' => [["\u{03A9}"]]]],
                    'rename name [S_BAD]'                        => ['PUT', 'archives/rename/' . $P, ['name' => ["\u{1F600}"]]],
                    'put-in id_archives [[S_BAD]]'               => ['POST', 'documents/put-in', ['id_archive_parent' => $T, 'id_archives' => [["\u{03A9}"]]]],
                    'put-in name {S_BAD:x}'                      => ['POST', 'documents/put-in', ['id_archive_parent' => $T, 'name' => ["\u{03A9}" => 'x']]],
                    'user-permissions permissions [[S_BAD]]'     => ['PUT', 'user-permissions/' . $target, ['permissions' => [["\u{03A9}"]]]],
                    'user-permissions permissions.0.id_archive [S_BAD]' => ['PUT', 'user-permissions/' . $target, ['permissions' => [['id_archive' => ["\u{03A9}"], 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0]]]],
                    'user-permissions folders [[S_BAD]]'         => ['PUT', 'user-permissions/' . $target, ['permissions' => [], 'folders' => [["\u{03A9}"]]]],
                ];
                foreach ($nested as $label => [$method, $uri, $body]) {
                    $r = $f->call($s, $method, Q71_API . $uri, $body, "bersarang: $label");
                    // put-in name {S_BAD: x}: name berupa array belum bertipe string (nilai 'x' dicari, tidak ketemu = 404); varian array berisi
                    // S_BAD ada di scenario5-bypass.php X-5. Selebihnya 422.
                    if ($label === 'put-in name {S_BAD:x}') {
                        $f->ok(in_array($r[0], [404, 422], true), "bersarang: $label = 404/422 (HTTP {$r[0]})");
                    } else {
                        $f->ok($r[0] === 422, "bersarang: $label = 422 (HTTP {$r[0]})");
                    }
                    $f->eq(q71_snap($t, $ids), $before, "bersarang: $label: tanpa tulis");
                }

                // --- NUL: bukan S_BAD (Windows-1252 memuatnya), perilaku lama dipertahankan: tidak ada 5xx
                $r = $f->call($s, 'POST', Q71_API . 'archives/create-folder', ['name' => "QA71-nul-\0-x", 'is_all_location' => 1], 'create name NUL');
                $t->note('NUL di name -> HTTP ' . $r[0]);
                $f->ok($r[0] < 500, 'NUL di name bukan 5xx');
                q71_purge($t);
                $before = q71_snap($t, $ids);

                // --- karakter khusus Windows-1252 (0x80-0x9F) + Latin-1 supplement: diterima utuh
                $specials = [
                    'Œ' => "\u{0152}", 'œ' => "\u{0153}", 'Š' => "\u{0160}", 'š' => "\u{0161}", 'Ž' => "\u{017D}", 'ž' => "\u{017E}", 'Ÿ' => "\u{0178}",
                    'ƒ' => "\u{0192}", 'ˆ' => "\u{02C6}", '˜' => "\u{02DC}", '‚' => "\u{201A}", '„' => "\u{201E}", '…' => "\u{2026}", '†' => "\u{2020}",
                    '‡' => "\u{2021}", '‰' => "\u{2030}", '‹' => "\u{2039}", '›' => "\u{203A}", '‘' => "\u{2018}", '“' => "\u{201C}", '”' => "\u{201D}",
                    '•' => "\u{2022}", '–' => "\u{2013}", 'NBSP' => "\u{00A0}", 'shy' => "\u{00AD}", 'ÿ' => "\u{00FF}", 'ñ' => "\u{00F1}", 'ß' => "\u{00DF}",
                    '¿' => "\u{00BF}", '½' => "\u{00BD}",
                ];
                $uniq = substr(uniqid(), -6);
                $okIds = [];
                $n = 0;
                foreach ($specials as $lab => $ch) {
                    $name = Q71_PREFIX . 'W-' . $uniq . '-' . (++$n) . 'a' . $ch . 'b';
                    $r = $f->call($s, 'POST', Q71_API . 'archives/create-folder', ['name' => $name, 'is_all_location' => 1], "create khusus $lab");
                    $f->resp($r, 200, 'ARCHIVE201', "create khusus $lab");
                    $id = $r[1]['result']['id_archive'] ?? null;
                    if ($id !== null) {
                        $f->eq(q71_hex($t, $id, 'name'), q71_hex_cp1252($name), "khusus $lab: HEX(name) cp1252");
                    }
                }

                $f->done();
            } finally {
                q71_purge($t);
            }
            q71_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-2',
        'title' => 'MyHelper::isLatin1 vs MySQL latin1 yang sebenarnya: tabel nilai (teks/angka/array/rusak) dan seluruh BMP (U+0001..U+FFFF) - semua yang diterima helper selamat bolak-balik di latin1 (tidak pernah jadi "?" atau gagal kolasi); selisih hanya lima kontrol C1 yang ditolak sengaja',
        'run'   => function ($t) {
            $isLatin1 = function ($v) use ($t) {
                return \Modules\V5\Entities\Helper\MyHelper::isLatin1($v);
            };

            // tabel nilai
            $true = [
                'kosong' => '', 'nol' => '0', 'ascii' => 'abc XYZ-123', 'S_OK' => "\u{00FC}\u{2019}\u{2014}\u{20AC}\u{2122}", 'Œ' => "\u{0152}", '…' => "\u{2026}",
                'NBSP' => "\u{00A0}", 'ÿ' => "\u{00FF}", 'int' => 123, 'float' => 1.5, 'bool' => true, 'null' => null, 'array kosong' => [],
                'array S_OK' => ['a' => 'b', "\u{00FC}" => ["\u{20AC}"]], 'NUL' => "a\0b",
            ];
            $false = [
                'omega' => "\u{03A9}", 'cjk' => "\u{4E2D}\u{6587}", 'emoji' => "\u{1F600}", 'aksen gabung' => "e\u{0301}", 'rtl' => "\u{202E}",
                'rusak C3 28' => "x\xC3\x28", 'rusak FF' => "\xFF", 'overlong' => "\xC0\xAF", 'surrogate UTF-8' => "\xED\xA0\x80", 'BOM' => "\u{FEFF}",
                'makron' => "\u{0100}", 'C1 U+0081' => "\u{0081}",
                'array nilai' => ['a' => "\u{03A9}"], 'array key' => ["\u{03A9}" => 'a'], 'array dalam' => [['b' => ['x', "\u{1F600}"]]], 'array rusak' => ['ok', "x\xC3\x28"],
            ];
            $bad = [];
            foreach ($true as $lab => $v) {
                if ($isLatin1($v) !== true) {
                    $bad[] = "harus true: $lab";
                }
            }
            foreach ($false as $lab => $v) {
                if ($isLatin1($v) !== false) {
                    $bad[] = "harus false: $lab";
                }
            }
            $t->eq($bad, [], 'tabel nilai isLatin1');

            // seluruh BMP terhadap MySQL (koneksi DB uji): diterima helper => selamat bolak-balik di latin1
            $cps = [];
            for ($cp = 1; $cp <= 0xFFFF; $cp++) {
                if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                    continue;
                }
                $cps[] = $cp;
            }
            $db = q71_dbname($t);
            $survives = $t->probe(function () use ($cps, $db) {
                $out = [];
                foreach (array_chunk($cps, 400) as $chunk) {
                    $sql = [];
                    $bind = [];
                    foreach ($chunk as $cp) {
                        $sql[] = 'SELECT ? AS i, CONVERT(CONVERT(? USING latin1) USING utf8) AS r';
                        $bind[] = $cp;
                        $bind[] = mb_chr($cp, 'UTF-8');
                    }
                    foreach (\Illuminate\Support\Facades\DB::connection($db)->select(implode(' UNION ALL ', $sql), $bind) as $row) {
                        $out[(int) $row->i] = $row->r === mb_chr((int) $row->i, 'UTF-8');
                    }
                }

                return $out;
            });

            $acceptedLossy = [];
            $rejectedButStored = [];
            $accepted = 0;
            foreach ($cps as $cp) {
                $char = mb_chr($cp, 'UTF-8');
                $ok = \Modules\V5\Entities\Helper\MyHelper::isLatin1($char);
                $accepted += $ok ? 1 : 0;
                $stored = $survives[$cp] ?? false;
                if ($ok && !$stored) {
                    $acceptedLossy[] = sprintf('U+%04X', $cp);
                }
                if (!$ok && $stored) {
                    $rejectedButStored[] = sprintf('U+%04X', $cp);
                }
            }
            $t->note(count($cps) . ' code point BMP; diterima helper ' . $accepted . '; selamat di MySQL latin1 ' . count(array_filter($survives)) . '; ditolak padahal tersimpan: ' . json_encode($rejectedButStored));
            $t->eq($acceptedLossy, [], 'tidak ada karakter yang diterima helper tetapi rusak ("?") di MySQL latin1');
            $t->eq($rejectedButStored, ['U+0081', 'U+008D', 'U+008F', 'U+0090', 'U+009D'], 'selisih hanya lima kontrol C1 (ditolak sengaja, catatan implementasi spec)');
        },
    ],

    [
        'id'    => 'X-3',
        'title' => 'Tinjauan diff BE: GE0748 di en_EN + id_ID (teks persis spec), php -l semua berkas berubah, tanpa state statis / cache / config baru (isolasi tenant), bentuk 422 GE0748 = bentuk 422 lain; QA_DBS = 1 DB sehingga isolasi antar-DB tidak bisa diuji langsung',
        'run'   => function ($t) {
            $be = $t->conf('BE_DIR');
            $s = $t->session();

            // 1. kode bahasa
            $en = file_get_contents($be . '/app/Lib/lang/en_EN.php');
            $id = file_get_contents($be . '/app/Lib/lang/id_ID.php');
            $t->eq(substr_count($en, "'GE0748'"), 1, "GE0748 tepat satu di en_EN.php");
            $t->eq(substr_count($id, "'GE0748'"), 1, "GE0748 tepat satu di id_ID.php");
            $t->true(strpos($en, Q71_GE0748_EN) !== false, 'teks en persis spec');
            $t->true(strpos($id, Q71_GE0748_ID) !== false, 'teks id persis spec');
            $t->eq(substr_count($en, "'GE0747'"), 0, 'GE0747 tidak dipakai item ini (dicadangkan branch rewrite)');

            // 2. php -l semua berkas berubah (daftar dari changed.txt hasil scripts/changed.sh)
            $changed = file(dirname(__DIR__) . '/changed.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $files = [];
            foreach ($changed as $line) {
                $cols = explode("\t", $line);
                if (($cols[0] ?? '') === 'be' && isset($cols[3]) && substr($cols[3], -4) === '.php') {
                    $files[] = $cols[3];
                }
            }
            $t->eq(count($files), 17, 'changed.txt memuat 17 berkas PHP BE');
            $lint = [];
            foreach ($files as $file) {
                $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($be . '/' . $file) . ' 2>&1');
                if (strpos((string) $out, 'No syntax errors') === false) {
                    $lint[] = $file . ': ' . trim((string) $out);
                }
            }
            $t->eq($lint, [], 'php -l 17 berkas');

            // 3. tanpa state statis / cache / config / global di baris tambahan diff (+ berkas baru)
            $diff = (string) shell_exec('git -C ' . escapeshellarg($be) . ' diff -U0 -- Modules app 2>/dev/null');
            $diff .= "\n" . implode("\n", array_map(function ($l) {
                return '+' . $l;
            }, file($be . '/Modules/V5/Rules/DocumentArchive/Latin1Rule.php', FILE_IGNORE_NEW_LINES)));
            $suspicious = [];
            foreach (explode("\n", $diff) as $line) {
                if ($line === '' || $line[0] !== '+' || strpos($line, '+++') === 0) {
                    continue;
                }
                $code = preg_replace('#//.*$#', '', $line);
                if (preg_match('/\b(static\s+\$|self::\$|static::\$|Cache::|config\(|Config::|app\(\)->|\$GLOBALS|putenv|ini_set|setlocale|mb_internal_encoding|mb_substitute_character|date_default_timezone_set)/', $code)) {
                    $suspicious[] = trim($line);
                }
            }
            $t->eq($suspicious, [], 'tidak ada properti statis / cache / config / global / ubah locale di diff');

            // 4. bentuk 422 sama dengan 422 lain (message + errors)
            $a = $t->call($s, 'POST', Q71_API . 'archives/create-folder', ['is_all_location' => 1]);
            $b = $t->call($s, 'POST', Q71_API . 'archives/create-folder', ['name' => "QA71-\u{1F600}", 'is_all_location' => 1]);
            $t->eq($a[0], 422, '422 lain (name wajib)');
            $t->eq($b[0], 422, '422 GE0748');
            $t->eq(array_keys($b[1]), array_keys($a[1]), 'kunci tingkat atas 422 GE0748 = 422 lain');
            $t->eq($b[1]['message'] ?? null, $a[1]['message'] ?? null, 'message 422 sama');
            $t->eq(is_array($b[1]['errors']['name'] ?? null), true, 'errors.name berupa array pesan');

            // 5. isolasi tenant
            if (count($t->dbs()) < 2) {
                $t->note('QA_DBS memuat satu DB (' . $t->dbs()[0] . '): isolasi antar-tenant tidak bisa diuji; Latin1Rule / isLatin1 tanpa state (butir 3)');
            }
        },
    ],
];
