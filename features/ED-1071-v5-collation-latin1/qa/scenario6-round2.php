<?php
/**
 * ED-1071 ronde 2 - cek ulang fix D-1 (U+FFFD) dan D-2 (array berisi S_BAD), diperluas (di luar AC; FAIL = defect):
 *   R2-1 U+FFFD dalam 9 bentuk (sendirian, awalan, sufiks, tengah, ganda, campur latin1 / S_OK / spasi) di SEMUA titik masuk
 *        body (16 + rename), id (path/query), search (UTF-8 mentah dan escape �, semua bentuk), select; body JSON mentah
 *        (UTF-8 literal dan �) serta form-urlencoded; description tidak boleh tersimpan "a?b"; kontrol: "?" biasa dan
 *        mojibake latin1 sah (i-diaeresis, inverted question mark, one-half) tetap diterima utuh (tidak ada negatif palsu);
 *   R2-2 nilai non-teks (array S_BAD, array U+FFFD, array biasa, objek, bersarang, angka, bool) di SEMUA field body yang
 *        berlatin1 (bukan hanya empat field D-2): selalu 4xx dan tanpa tulis, 422 untuk bentuk array di field bertipe string;
 *        kontrol: teks biasa (S_OK) di empat field D-2 tetap lewat penjaga (bukan 422 GE0748/tipe).
 * Data uji berawalan QA71-.
 */
require_once __DIR__ . '/qa_lib.php';

if (!function_exists('q71r2_fffd')) {

    /** Bentuk U+FFFD yang diuji: label => nilai. */
    function q71r2_fffd()
    {
        $c = "\u{FFFD}";

        return [
            'alone'    => $c,
            'prefix'   => $c . 'x',
            'suffix'   => 'x' . $c,
            'middle'   => 'a' . $c . 'b',
            'double'   => $c . $c,
            'latin1'   => "\u{00FC}" . $c,
            'euro'     => "\u{20AC}" . $c . "\u{2019}",
            'spaced'   => 'a ' . $c . ' b',
            'escaped'  => 'x\\ufffd',   // teks harfiah "�" (6 karakter ASCII): sah, bukan U+FFFD (kontrol di R2-1)
        ];
    }

    /**
     * Titik masuk body: label => [method, uri, body, kunci errors yang diharapkan]. $v = nilai yang disuntikkan.
     * $P folder uji, $T folder tujuan, $D dokumen, $target id user lain, $loc id lokasi sah.
     */
    function q71r2_points($v, $P, $pName, $T, $D, $target, $loc, $sfx = true)
    {
        $uniq = function ($s) {
            return 'QA71-f-' . substr(uniqid(), -6) . mt_rand(10, 99) . $s;
        };
        $perm = function ($id) {
            return ['id_archive' => $id, 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0];
        };
        $flag = function ($id) {
            return ['id_archive' => $id, 'is_folder_permission' => 1];
        };
        $vs = is_string($v) ? $v : null; // untuk titik yang memakai penyambungan teks
        $nm = function ($suffix) use ($uniq, $v, $vs, $sfx) {
            return ($sfx && $vs !== null) ? $uniq('') . $vs : $v;
        };

        return [
            'create name'              => ['POST', 'archives/create-folder', ['name' => $nm(''), 'is_all_location' => 1], ['name']],
            'create description'       => ['POST', 'archives/create-folder', ['name' => $uniq(''), 'description' => $v, 'is_all_location' => 1], ['description']],
            'create id_archive_parent' => ['POST', 'archives/create-folder', ['name' => $uniq(''), 'id_archive_parent' => $v, 'is_all_location' => 1], ['id_archive_parent']],
            'create id_locations'      => ['POST', 'archives/create-folder', ['name' => $uniq(''), 'is_all_location' => 0, 'id_locations' => $v], ['id_locations']],
            'create id_locations.1'    => ['POST', 'archives/create-folder', ['name' => $uniq(''), 'is_all_location' => 0, 'id_locations' => [$loc, $v]], ['id_locations.1']],
            'PUT name'                 => ['PUT', 'archives/' . $P, ['name' => $sfx && $vs !== null ? $pName . $vs : $v, 'is_all_location' => 1], ['name']],
            'PUT description'          => ['PUT', 'archives/' . $P, ['name' => $pName, 'description' => $v, 'is_all_location' => 1], ['description']],
            'PUT id_archive_parent'    => ['PUT', 'archives/' . $P, ['name' => $pName, 'id_archive_parent' => $v, 'is_all_location' => 1], ['id_archive_parent']],
            'PUT id_locations.1'       => ['PUT', 'archives/' . $P, ['name' => $pName, 'is_all_location' => 0, 'id_locations' => [$loc, $v]], ['id_locations.1']],
            'PUT folder_permissions.0.id_user' => ['PUT', 'archives/' . $P, ['name' => $pName, 'is_all_location' => 1, 'folder_permissions' => [['id_user' => $v, 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0]]], ['folder_permissions.0.id_user']],
            'rename name'              => ['PUT', 'archives/rename/' . $P, ['name' => $sfx && $vs !== null ? 'QA71-r' . $vs : $v], ['name']],
            'rename doc name'          => ['PUT', 'archives/rename/' . $D, ['name' => $sfx && $vs !== null ? 'QA71-r' . $vs : $v], ['name']],
            'put-in id_archive_parent' => ['POST', 'documents/put-in', ['id_archive_parent' => $v, 'id_archives' => [$D]], ['id_archive_parent']],
            'put-in id_archives'       => ['POST', 'documents/put-in', ['id_archive_parent' => $T, 'id_archives' => $v], ['id_archives']],
            'put-in id_archives.0'     => ['POST', 'documents/put-in', ['id_archive_parent' => $T, 'id_archives' => [$v]], ['id_archives.0']],
            'put-in id_archives.1'     => ['POST', 'documents/put-in', ['id_archive_parent' => $T, 'id_archives' => [$D, $v]], ['id_archives.1']],
            'put-in name'              => ['POST', 'documents/put-in', ['id_archive_parent' => $T, 'name' => $v], ['name']],
            'hand-over name'           => ['POST', 'documents/hand-over', ['name' => $v, 'action' => 'give'], ['name']],
            'receive name'             => ['POST', 'documents/receive', ['name' => $v, 'action' => 'receive'], ['name']],
            'add-document id_archive'  => ['POST', 'archives/add-document', ['id_archive' => $v], ['id_archive']],
            'user-permissions permissions.0.id_archive' => ['PUT', 'user-permissions/' . $target, ['permissions' => [$perm($v)]], ['permissions.0.id_archive']],
            'user-permissions permissions.1.id_archive' => ['PUT', 'user-permissions/' . $target, ['permissions' => [$perm($P), $perm($v)]], ['permissions.1.id_archive']],
            'user-permissions folders.0.id_archive'     => ['PUT', 'user-permissions/' . $target, ['permissions' => [], 'folders' => [$flag($v)]], ['folders.0.id_archive']],
            'user-permissions folders.1.id_archive'     => ['PUT', 'user-permissions/' . $target, ['permissions' => [], 'folders' => [$flag($P), $flag($v)]], ['folders.1.id_archive']],
        ];
    }
}

return [

    [
        'id'    => 'R2-1',
        'title' => 'U+FFFD (9 bentuk) di semua titik masuk Archive: body 422 GE0748 tepat di kuncinya (ID dan EN), id 404, search 200 total 0, select options []; body JSON mentah dan form-urlencoded; description tidak tersimpan "?"; kontrol "?" dan mojibake latin1 sah diterima utuh',
        'run'   => function ($t) {
            q71_recover($t);
            $s = $t->session();
            q71_purge($t);
            $base = q71_base($t);
            $f = q71_soft($t);

            try {
                $locs = array_values(q71_locs($t));
                $P = q71_folder($t, 'P', ['description' => 'desc-awal']);
                $T = q71_folder($t, 'T');
                $D = q71_doc($t, 'D', ['parent' => $P]);
                $pName = q71_row($t, $P)['name'];
                $ids = [$P, $T, $D];
                $before = q71_snap($t, $ids);
                $descHex = q71_hex($t, $P, 'description');
                $target = $t->db()->table('users')->where('is_active', 1)->where('username', '<>', $t->conf('QA_USER'))->orderBy('username')->value('id_user');

                // (a) body JSON lewat helper (json_encode meng-escape �) - semua bentuk x semua titik
                foreach (q71r2_fffd() as $lab => $v) {
                    if ($lab === 'escaped') {
                        continue; // kontrol terpisah di bawah
                    }
                    foreach (q71r2_points($v, $P, $pName, $T, $D, $target, $locs[0]) as $label => [$method, $uri, $b, $keys]) {
                        $r = $f->call($s, $method, Q71_API . $uri, $b, "FFFD[$lab] $label");
                        $f->ge0748($r, $keys, "FFFD[$lab] $label");
                    }
                    $f->ok(q71_snap($t, $ids) === $before, "FFFD[$lab]: tanpa tulis");
                    $f->eq(q71_hex($t, $P, 'description'), $descHex, "FFFD[$lab]: description tidak berubah (bukan '?')");
                }

                // (b) body JSON mentah: UTF-8 literal (EF BF BD) dan escape � di tangan; form-urlencoded
                $literal = "\xEF\xBF\xBD";
                $rawCases = [
                    'json literal create name'        => ['POST', 'archives/create-folder', '{"name":"QA71-f-raw' . $literal . '","is_all_location":1}', 'application/json', ['name']],
                    'json escape create name'         => ['POST', 'archives/create-folder', '{"name":"QA71-f-raw\\ufffd","is_all_location":1}', 'application/json', ['name']],
                    'json literal create description' => ['POST', 'archives/create-folder', '{"name":"QA71-f-raw2","description":"a' . $literal . ' b","is_all_location":1}', 'application/json', ['description']],
                    'json escape PUT description'     => ['PUT', 'archives/' . $P, '{"name":"' . $pName . '","description":"a\\ufffd b","is_all_location":1}', 'application/json', ['description']],
                    'json literal PUT description'    => ['PUT', 'archives/' . $P, '{"name":"' . $pName . '","description":"a' . $literal . ' b","is_all_location":1}', 'application/json', ['description']],
                    'json literal rename'             => ['PUT', 'archives/rename/' . $P, '{"name":"QA71-r' . $literal . '"}', 'application/json', ['name']],
                    'json escape hand-over'           => ['POST', 'documents/hand-over', '{"name":"\\ufffd","action":"give"}', 'application/json', ['name']],
                    'form create name'                => ['POST', 'archives/create-folder', 'name=QA71-f-form%EF%BF%BD&is_all_location=1', 'application/x-www-form-urlencoded', ['name']],
                    'form create description'         => ['POST', 'archives/create-folder', 'name=QA71-f-form2&description=a%EF%BF%BDb&is_all_location=1', 'application/x-www-form-urlencoded', ['description']],
                    'form PUT description'            => ['PUT', 'archives/' . $P, 'name=' . rawurlencode($pName) . '&description=a%EF%BF%BDb&is_all_location=1', 'application/x-www-form-urlencoded', ['description']],
                    'form rename'                     => ['PUT', 'archives/rename/' . $P, 'name=QA71-r%EF%BF%BD', 'application/x-www-form-urlencoded', ['name']],
                    'form put-in name'                => ['POST', 'documents/put-in', 'id_archive_parent=' . $T . '&name=%EF%BF%BD', 'application/x-www-form-urlencoded', ['name']],
                    'form put-in name[]'              => ['POST', 'documents/put-in', 'id_archive_parent=' . $T . '&name[]=%EF%BF%BD', 'application/x-www-form-urlencoded', ['name']],
                    'form add-document id_archive[]'  => ['POST', 'archives/add-document', 'id_archive[]=%EF%BF%BD', 'application/x-www-form-urlencoded', ['id_archive']],
                ];
                foreach ($rawCases as $label => [$method, $uri, $raw, $ctype, $keys]) {
                    $r = $f->raw($s, $method, Q71_API . $uri, $raw, $ctype, "raw $label");
                    $isArrayShape = strpos($label, '[]') !== false;
                    if ($isArrayShape) {
                        $f->ok($r[0] === 422 && isset($r[1]['errors'][$keys[0]]), "raw $label: 422 errors.{$keys[0]} (HTTP {$r[0]})");
                    } else {
                        $f->ge0748($r, $keys, "raw $label");
                    }
                }
                $f->ok(q71_snap($t, $ids) === $before, 'raw: tanpa tulis');
                $f->eq(q71_hex($t, $P, 'description'), $descHex, "raw: description tidak berubah (bukan '?')");

                // (c) bahasa EN: teks GE0748 EN tepat untuk U+FFFD
                q71_with_user($t, ['lang' => 'EN'], function ($set) use ($t, $f, $s, $P, $pName) {
                    $r = $f->call($s, 'POST', Q71_API . 'archives/create-folder', ['name' => 'QA71-f-en' . "\u{FFFD}", 'description' => "a\u{FFFD}", 'is_all_location' => 1], 'EN create');
                    $f->ge0748($r, ['name', 'description'], 'EN create', Q71_GE0748_EN);
                    $r = $f->call($s, 'PUT', Q71_API . 'archives/' . $P, ['name' => $pName, 'description' => "x\u{FFFD}", 'is_all_location' => 1], 'EN PUT');
                    $f->ge0748($r, ['description'], 'EN PUT', Q71_GE0748_EN);
                });

                // (d) id (path/query): U+FFFD sendirian, awalan/sufiks pada id sah, tengah id
                $idForms = [
                    'alone'      => "\u{FFFD}",
                    'id+fffd'    => $P . "\u{FFFD}",
                    'fffd+id'    => "\u{FFFD}" . $P,
                    'mid'        => substr($P, 0, 10) . "\u{FFFD}" . substr($P, 10),
                    'double'     => "\u{FFFD}\u{FFFD}",
                ];
                foreach ($idForms as $lab => $idv) {
                    $enc = rawurlencode($idv);
                    foreach ([['GET', 'archives?id_archive=' . $enc], ['GET', 'archives/' . $enc], ['PUT', 'archives/' . $enc], ['GET', 'archives/history/' . $enc],
                        ['PUT', 'archives/rename/' . $enc], ['DELETE', 'archives/delete/' . $enc]] as [$method, $uri]) {
                        $r = $f->call($s, $method, Q71_API . $uri, $method === 'PUT' ? ['name' => q71_name('Z'), 'is_all_location' => 1] : null, "id[$lab] $method $uri");
                        $f->resp($r, 404, 'ARCHIVE400', "id[$lab] $method $uri");
                    }
                    $r = $f->call($s, 'GET', Q71_SEL . 'locations?id_archive_parent=' . $enc, null, "id[$lab] select locations id_archive_parent");
                    $f->resp($r, 404, 'ARCHIVE400', "id[$lab] select locations id_archive_parent");
                    foreach (['GET', 'PUT'] as $method) {
                        $r = $f->call($s, $method, Q71_API . 'user-permissions/' . $enc, $method === 'PUT' ? ['permissions' => []] : null, "id[$lab] user-permissions $method");
                        $f->resp($r, 404, 'ARCHIVE440', "id[$lab] user-permissions $method");
                    }
                }
                $f->ok(q71_snap($t, $ids) === $before, 'id: tanpa tulis');

                // (e) search (root dan folder), JSON escape (default json_encode) dan UTF-8 mentah, semua bentuk x semua bentuk U+FFFD
                foreach (q71r2_fffd() as $lab => $v) {
                    if ($lab === 'escaped') {
                        continue;
                    }
                    $jsons = [
                        'query'      => ['query' => $v],
                        'type'       => ['type' => $v],
                        'name'       => ['name' => $v],
                        'nested'     => ['a' => ['b' => [$v]]],
                        'key'        => [$v => 'x'],
                        'related'    => ['showRelatedTransaction' => $v],
                    ];
                    foreach ($jsons as $jl => $json) {
                        foreach (['esc' => json_encode($json), 'raw' => json_encode($json, JSON_UNESCAPED_UNICODE)] as $form => $enc) {
                            foreach ([null, $P] as $ctx) {
                                $label = "search[$lab/$jl/$form]" . ($ctx ? ' folder' : ' root');
                                $r = $f->call($s, 'GET', Q71_API . 'archives?search=' . rawurlencode($enc) . ($ctx ? '&id_archive=' . $ctx : ''), null, $label);
                                $f->resp($r, 200, null, $label);
                                $f->eq($r[1]['result']['total'] ?? null, 0, "$label: total 0");
                            }
                        }
                    }
                    foreach (['users', 'locations'] as $sel) {
                        $e = rawurlencode($v);
                        $r = $f->call($s, 'GET', Q71_SEL . $sel . '?search=' . $e, null, "select $sel search[$lab]");
                        $f->resp($r, 200, null, "select $sel search[$lab]");
                        $f->eq($r[1]['result']['options'] ?? null, [], "select $sel search[$lab]: options []");
                        foreach (['excepts[]=' . $e, 'excepts[]=' . $e . '&excepts[]=' . $e, 'except=' . $e, 'selected_id=' . $e, 'selected_id[]=' . $e, 'exclude_id=' . $e, 'excepts[]=' . $e . '&selected_id=' . $e] as $qs) {
                            $r = $f->call($s, 'GET', Q71_SEL . $sel . '?' . $qs, null, "select $sel $qs [$lab]");
                            $f->resp($r, 200, null, "select $sel $qs [$lab]");
                        }
                    }
                }
                $f->ok(q71_snap($t, $ids) === $before, 'search/select: tanpa tulis');

                // (f) kontrol: tidak ada negatif palsu - "?" biasa, teks harfiah "�", mojibake latin1 sah (i-diaeresis, inverted ?, 1/2)
                $mojibake = "\u{00EF}\u{00BF}\u{00BD}";
                $controls = [
                    'question mark' => ['QA71-f-q' . substr(uniqid(), -5) . '?', '3F'],
                    'backslash-u'   => ['QA71-f-b' . substr(uniqid(), -4) . '\\ufffd', null],
                    'mojibake'      => ['QA71-f-m' . substr(uniqid(), -4) . $mojibake, 'EFBFBD'],
                ];
                foreach ($controls as $lab => [$name, $hexEnd]) {
                    $r = $f->call($s, 'POST', Q71_API . 'archives/create-folder', ['name' => $name, 'description' => 'd ' . $name, 'is_all_location' => 1], "kontrol create [$lab]");
                    $f->resp($r, 200, null, "kontrol create [$lab] = 200");
                    $row = $t->db()->table('archives')->where('name', $name)->first();
                    $f->ok($row !== null, "kontrol create [$lab]: baris tersimpan dengan nama utuh");
                    if ($row && $hexEnd !== null) {
                        $hex = q71_hex($t, $row->id_archive, 'name');
                        $f->ok(substr($hex, -strlen($hexEnd)) === $hexEnd, "kontrol create [$lab]: HEX(name) berakhir $hexEnd (got " . substr((string) $hex, -8) . ')');
                        $hd = q71_hex($t, $row->id_archive, 'description');
                        $f->ok(substr($hd, -strlen($hexEnd)) === $hexEnd, "kontrol create [$lab]: HEX(description) berakhir $hexEnd");
                    }
                    if ($row) {
                        // PUT description dan search menemukannya
                        $r = $f->call($s, 'PUT', Q71_API . 'archives/' . $row->id_archive, ['name' => $name, 'description' => 'u ' . $name, 'is_all_location' => 1], "kontrol PUT [$lab]");
                        $f->resp($r, 200, null, "kontrol PUT [$lab] = 200");
                        // "\" di pola LIKE MySQL = karakter escape (perilaku lama, bukan ED-1071): cari awalan nama tanpa "\ufffd"
                        $needle = $lab === 'backslash-u' ? substr($name, 0, strpos($name, '\\')) : $name;
                        $r = $f->call($s, 'GET', Q71_API . 'archives?search=' . rawurlencode(json_encode(['query' => $needle])), null, "kontrol search [$lab]");
                        $f->resp($r, 200, null, "kontrol search [$lab] = 200");
                        $f->ok(in_array($row->id_archive, q71_list_ids($r), true), "kontrol search [$lab]: ditemukan lewat '$needle'");
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
        'id'    => 'R2-2',
        'title' => 'Nilai non-teks (array S_BAD, array U+FFFD, array biasa, objek, bersarang, angka, bool) di semua field body berlatin1: selalu 4xx, tanpa tulis, 422 untuk array di field bertipe teks; kontrol: teks biasa di empat field D-2 lolos penjaga',
        'run'   => function ($t) {
            q71_recover($t);
            $s = $t->session();
            q71_purge($t);
            $base = q71_base($t);
            $f = q71_soft($t);

            try {
                $locs = array_values(q71_locs($t));
                $P = q71_folder($t, 'P', ['description' => 'desc-awal']);
                $T = q71_folder($t, 'T');
                $D = q71_doc($t, 'D', ['parent' => $P]);
                $pName = q71_row($t, $P)['name'];
                $ids = [$P, $T, $D];
                $before = q71_snap($t, $ids);
                $descHex = q71_hex($t, $P, 'description');
                $target = $t->db()->table('users')->where('is_active', 1)->where('username', '<>', $t->conf('QA_USER'))->orderBy('username')->value('id_user');

                $shapes = [];
                foreach (array_merge(q71_bad(), ['fffd' => "\u{FFFD}", 'fffd-text' => "x\u{FFFD}y"]) as $lab => $ch) {
                    $shapes["[$lab]"] = [$ch];
                    $shapes["[ok,$lab]"] = ['x', $ch];
                    $shapes["{k:$lab}"] = ['k' => $ch];
                    $shapes["[[$lab]]"] = [[$ch]];
                }
                $shapes['[x]'] = ['x'];
                $shapes['[]'] = [];
                $shapes['{}'] = new \stdClass();
                $shapes['12345'] = 12345;
                $shapes['1.5'] = 1.5;
                $shapes['true'] = true;
                $shapes['false'] = false;

                $codes = [];
                foreach ($shapes as $lab => $v) {
                    foreach (q71r2_points($v, $P, $pName, $T, $D, $target, $locs[0], false) as $label => [$method, $uri, $b, $keys]) {
                        // id_locations kosong ([] / {}) + is_all_location 0 = folder sah tanpa lokasi (perilaku lama, bukan S_BAD)
                        if ($label === 'create id_locations' && (is_object($v) || $v === [])) {
                            continue;
                        }
                        $r = $f->call($s, $method, Q71_API . $uri, $b, "shape $lab $label");
                        $codes[$r[0]] = ($codes[$r[0]] ?? 0) + 1;
                        if ($r[0] !== 422) {
                            $t->note("shape $lab $label -> HTTP {$r[0]} " . ($r[1]['msg_code'] ?? $r[1]['code'] ?? ''));
                        }
                        $f->ok($r[0] >= 400 && $r[0] < 500, "shape $lab $label = 4xx (HTTP {$r[0]}" . ($r[0] >= 400 && $r[0] < 500 ? '' : ' ' . substr(json_encode($r[1]), 0, 120)) . ')');
                        // array/objek di field bertipe teks = 422 di kunci itu (atau turunan); angka/bool di field teks = 422 juga
                        $textFields = ['name', 'description', 'id_archive_parent', 'id_archive', 'id_archives.0', 'id_archives.1', 'id_locations.1',
                            'folder_permissions.0.id_user', 'permissions.0.id_archive', 'permissions.1.id_archive', 'folders.0.id_archive', 'folders.1.id_archive'];
                        if ($r[0] === 422 && in_array($keys[0], $textFields, true) && !(is_array($v) && !$v) && !is_bool($v) && !is_object($v)) {
                            $errs = array_keys($r[1]['errors'] ?? []);
                            $hit = false;
                            foreach ($errs as $e) {
                                if ($e === $keys[0] || strpos($e, $keys[0] . '.') === 0) {
                                    $hit = true;
                                }
                            }
                            $f->ok($hit, "shape $lab $label: errors memuat {$keys[0]} (" . implode(',', $errs) . ')');
                        }
                    }
                    $f->ok(q71_snap($t, $ids) === $before, "shape $lab: tanpa tulis");
                    $f->eq(q71_hex($t, $P, 'description'), $descHex, "shape $lab: description tidak berubah");
                }
                ksort($codes);
                $t->note('status respons seluruh bentuk: ' . json_encode($codes));

                // keempat field D-2 secara khusus: array S_BAD = 422 di kuncinya (bukan 404/500)
                $d2 = [
                    'put-in name'             => ['POST', 'documents/put-in', function ($v) use ($T) { return ['id_archive_parent' => $T, 'name' => $v]; }, 'name'],
                    'hand-over name'          => ['POST', 'documents/hand-over', function ($v) { return ['name' => $v, 'action' => 'give']; }, 'name'],
                    'receive name'            => ['POST', 'documents/receive', function ($v) { return ['name' => $v, 'action' => 'receive']; }, 'name'],
                    'add-document id_archive' => ['POST', 'archives/add-document', function ($v) { return ['id_archive' => $v]; }, 'id_archive'],
                ];
                foreach ($d2 as $label => [$method, $uri, $mk, $key]) {
                    foreach ([['Ω'], ["\u{4E2D}\u{6587}"], ["\u{1F600}"], ["e\u{0301}"], ["\u{202E}"], ["\u{FFFD}"], ['x']] as $arr) {
                        $r = $f->call($s, $method, Q71_API . $uri, $mk($arr), "D-2 $label " . json_encode($arr));
                        $f->ok($r[0] === 422 && isset($r[1]['errors'][$key]), "D-2 $label " . json_encode($arr) . ": 422 errors.$key (HTTP {$r[0]})");
                    }
                    // teks (bukan array) S_BAD = 422 GE0748 tepat, S_OK / S_OK + U+FFFD
                    foreach (['Ω', "\u{FFFD}"] as $txt) {
                        $r = $f->call($s, $method, Q71_API . $uri, $mk($txt), "D-2 $label teks " . json_encode($txt));
                        $f->ge0748($r, [$key], "D-2 $label teks " . json_encode($txt));
                    }
                    // kontrol S_OK: lolos penjaga (bukan 422 GE0748/tipe); boleh 404/400 bisnis
                    $r = $f->call($s, $method, Q71_API . $uri, $mk("\u{20AC}\u{2019}ok-" . substr(uniqid(), -5)), "D-2 $label kontrol S_OK");
                    if ($r[0] === 422) {
                        // add-document: id yang tak ada = 422 aturan exists (perilaku lama); yang dijaga: bukan GE0748 dan bukan galat tipe
                        $msg = (string) ($r[1]['errors'][$key][0] ?? '');
                        $f->ok($uri === 'archives/add-document' && $msg !== Q71_GE0748_ID && stripos($msg, 'string') === false, "D-2 $label kontrol S_OK: 422 hanya dari exists (" . $msg . ')');
                    } else {
                        $f->ok($r[0] < 500, "D-2 $label kontrol S_OK: lolos penjaga (HTTP {$r[0]} " . ($r[1]['code'] ?? $r[1]['msg_code'] ?? '') . ')');
                    }
                }
                $f->ok(q71_snap($t, $ids) === $before, 'D-2: tanpa tulis');

                $f->done();
            } finally {
                q71_purge($t);
            }
            q71_assert_clean($t, $base);
        },
    ],
];
