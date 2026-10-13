<?php
/**
 * ED-1071 AC-4 (id S_BAD = 404 ARCHIVE400 / ARCHIVE440, UTF-8 rusak di path = 404 router) dan AC-5 (search S_BAD = 200 kosong;
 * select users/locations search S_BAD = options []; excepts/selected_id S_BAD = tanpa parameter). Tanpa 5xx, tanpa tulis.
 */
require_once __DIR__ . '/qa_lib.php';

if (!function_exists('q71_s2_fixture')) {

    function q71_s2_fixture($t)
    {
        $f = [];
        $f['P'] = q71_folder($t, 'P');
        $f['D'] = q71_doc($t, 'D', ['parent' => $f['P']]);
        $f['C'] = q71_folder($t, 'C', ['parent' => $f['P']]);
        $f['pName'] = q71_row($t, $f['P'])['name'];

        return $f;
    }

    function q71_s2_search($t, $s, $f, $json, $idArchive = null, $label = null, $unescaped = false)
    {
        $encoded = $unescaped ? json_encode($json, JSON_UNESCAPED_UNICODE) : json_encode($json);
        $uri = Q71_API . 'archives?search=' . rawurlencode($encoded) . ($idArchive ? '&id_archive=' . $idArchive : '');

        return $f->call($s, 'GET', $uri, null, $label ?: ('GET archives?search=' . $encoded . ($idArchive ? ' (dalam folder)' : ' (root)')));
    }

    /** [value, ...] dari respons select. */
    function q71_s2_values($r)
    {
        $out = [];
        foreach ($r[1]['result']['options'] ?? [] as $o) {
            $out[] = $o['value'] ?? null;
        }

        return $out;
    }
}

return [

    [
        'id'    => 'AC-4',
        'title' => 'Id S_BAD: archives?id_archive=, archives/{id} GET/PUT, history, rename, delete, select locations?id_archive_parent= = 404 ARCHIVE400; user-permissions/{id} GET/PUT = 404 ARCHIVE440; UTF-8 rusak di path = 404 router, di query = 404 ARCHIVE400; tanpa tulis',
        'run'   => function ($t) {
            q71_recover($t);
            $s = $t->session();
            q71_purge($t);
            $base = q71_base($t);
            $f = q71_soft($t);

            try {
                $x = q71_s2_fixture($t);
                $ids = [$x['P'], $x['D'], $x['C']];
                $before = q71_snap($t, $ids);
                $ok = ['name' => q71_name('Z'), 'is_all_location' => 1];

                // [label, id sudah ter-encode] untuk S_BAD teks UTF-8 sah
                $forms = [];
                foreach (q71_bad() as $lab => $ch) {
                    $forms[$lab] = rawurlencode($ch);
                    $forms[$lab . '+latin1'] = rawurlencode($ch . "\u{00FC}");     // S_BAD bercampur S_OK
                    $forms[$lab . '-dalam-id'] = rawurlencode('QA71-' . $ch . '-abc');
                }
                $forms['rusak'] = 'x%C3%28';                                       // UTF-8 rusak, literal
                $forms['rusak-sendirian'] = '%FF';

                foreach ($forms as $lab => $enc) {
                    $broken = strpos($lab, 'rusak') === 0;
                    // path: UTF-8 rusak ditolak router (404 tanpa kode); S_BAD sah = 404 ARCHIVE400 dari service
                    $path = [
                        ['GET', 'archives/' . $enc, null],
                        ['PUT', 'archives/' . $enc, $ok],
                        ['GET', 'archives/history/' . $enc, null],
                        ['PUT', 'archives/rename/' . $enc, ['name' => q71_name('Z')]],
                        ['DELETE', 'archives/delete/' . $enc, null],
                    ];
                    foreach ($path as [$method, $uri, $body]) {
                        $label = "$method $uri [$lab]";
                        $r = $f->call($s, $method, Q71_API . $uri, $body, $label);
                        $f->eq($r[0], 404, "$label: 404");
                        if (!$broken) {
                            $f->eq(q71_code($r), 'ARCHIVE400', "$label: ARCHIVE400");
                        }
                    }

                    // query: id_archive= dan select locations id_archive_parent= selalu lewat service (404 ARCHIVE400)
                    foreach ([Q71_API . 'archives?id_archive=' . $enc, Q71_SEL . 'locations?id_archive_parent=' . $enc,
                        Q71_API . 'archives?pagination=5&id_archive=' . $enc . '&search=' . rawurlencode('{"query":"QA71"}')] as $uri) {
                        $r = $f->call($s, 'GET', $uri, null, "GET $uri [$lab]");
                        $f->resp($r, 404, 'ARCHIVE400', "GET $uri [$lab]");
                    }

                    // user-permissions/{id}
                    foreach ([['GET', null], ['PUT', ['permissions' => []]], ['PUT', ['permissions' => [], 'folders' => []]]] as [$method, $body]) {
                        $label = "$method user-permissions/$enc [$lab]";
                        $r = $f->call($s, $method, Q71_API . 'user-permissions/' . $enc, $body, $label);
                        if ($broken) {
                            $f->eq($r[0], 404, "$label: 404 router");
                        } else {
                            $f->resp($r, 404, 'ARCHIVE440', $label);
                        }
                    }
                }

                // teks pesan
                $r = $t->call($s, 'GET', Q71_API . 'archives/' . rawurlencode("\u{03A9}"));
                $f->eq($r[1]['message'] ?? null, 'Dokumen tidak ditemukan', 'teks ARCHIVE400 (ID)');
                $r = $t->call($s, 'GET', Q71_API . 'user-permissions/' . rawurlencode("\u{03A9}"));
                $f->eq($r[1]['message'] ?? null, 'User tidak ditemukan', 'teks ARCHIVE440 (ID)');

                // kontrol: id sah tetap bekerja (200), id Windows-1252 yang tidak ada = 404 (bukan 500)
                $f->resp($f->call($s, 'GET', Q71_API . 'archives/' . $x['P'], null, 'kontrol show P'), 200, null, 'kontrol: show P = 200');
                foreach (q71_ok() as $lab => [$ch, $hex]) {
                    $f->resp($f->call($s, 'GET', Q71_API . 'archives/' . rawurlencode($ch), null, "S_OK show $lab"), 404, 'ARCHIVE400', "S_OK show $lab = 404 ARCHIVE400");
                    $f->resp($f->call($s, 'GET', Q71_API . 'archives?id_archive=' . rawurlencode($ch), null, "S_OK list $lab"), 404, 'ARCHIVE400', "S_OK list id_archive $lab = 404 ARCHIVE400");
                    $f->resp($f->call($s, 'GET', Q71_API . 'user-permissions/' . rawurlencode($ch), null, "S_OK user-permissions $lab"), 404, 'ARCHIVE440', "S_OK user-permissions $lab = 404 ARCHIVE440");
                }

                $f->eq(q71_snap($t, $ids), $before, 'tanpa tulis: baris uji dan jumlah tabel sama');
                $f->done();
            } finally {
                q71_purge($t);
            }
            q71_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-5',
        'title' => 'archives?search= {"query":S_BAD} (root, dalam folder), {"type":S_BAD}, kunci/nilai S_BAD level mana pun = 200 total 0 (bentuk respons tetap); select users/locations search S_BAD = options []; excepts/except/selected_id/exclude_id S_BAD = tanpa parameter',
        'run'   => function ($t) {
            q71_recover($t);
            $s = $t->session();
            q71_purge($t);
            $base = q71_base($t);
            $f = q71_soft($t);

            try {
                $x = q71_s2_fixture($t);
                $ids = [$x['P'], $x['D'], $x['C']];
                $before = q71_snap($t, $ids);
                $locs = array_values(q71_locs($t));

                // --- list: kontrol bentuk respons, lalu S_BAD di root dan dalam folder
                foreach ([null, $x['P']] as $ctx) {
                    $where = $ctx ? 'folder' : 'root';
                    $ctrl = q71_s2_search($t, $s, $f, ['query' => 'QA71-'], $ctx, "kontrol query QA71- ($where)");
                    $f->resp($ctrl, 200, null, "kontrol query QA71- ($where)");
                    $f->ok(($ctrl[1]['result']['total'] ?? 0) >= 1, "kontrol ($where): pencarian QA71- menemukan fixture (total >= 1)");
                    $ctrlNone = q71_s2_search($t, $s, $f, ['query' => 'zzz-tidak-ada-' . uniqid()], $ctx, "kontrol tanpa hasil ($where)");
                    $f->eq($ctrlNone[1]['result']['total'] ?? null, 0, "kontrol tanpa hasil ($where): total 0");
                    $shape = array_keys($ctrlNone[1]['result'] ?? []);
                    $f->ok(in_array('columns', $shape, true), "kontrol ($where): result.columns ada");

                    $cases = [];
                    foreach (q71_bad() as $lab => $ch) {
                        $cases["query $lab"] = ['query' => $ch];
                        $cases["query sufiks $lab"] = ['query' => 'QA71-' . $ch];
                        $cases["query campur latin1 $lab"] = ['query' => "\u{00FC}" . $ch];
                        $cases["type $lab"] = ['type' => $ch];
                        $cases["query+type FOLDER $lab"] = ['query' => $ch, 'type' => 'FOLDER'];
                        $cases["query ok + type $lab"] = ['query' => 'QA71-', 'type' => $ch];
                        $cases["kolom name $lab"] = ['name' => $ch];
                        $cases["bersarang $lab"] = ['query' => ['a' => ['b' => $ch]]];
                        $cases["kunci $lab"] = [$ch => 'x'];
                        $cases["showRelatedTransaction + query $lab"] = ['showRelatedTransaction' => true, 'query' => $ch];
                        $cases["showRelatedTransaction=S_BAD $lab"] = ['showRelatedTransaction' => $ch];
                    }
                    foreach ($cases as $lab => $json) {
                        foreach ([false, true] as $unescaped) {
                            $label = "search $lab ($where" . ($unescaped ? ', UTF-8 mentah)' : ', \\u-escape)');
                            $r = q71_s2_search($t, $s, $f, $json, $ctx, $label, $unescaped);
                            $f->resp($r, 200, null, $label);
                            $f->eq($r[1]['result']['total'] ?? null, 0, "$label: total 0");
                            $f->eq($r[1]['result']['data'] ?? null, [], "$label: data []");
                            $f->eq(array_keys($r[1]['result'] ?? []), $shape, "$label: bentuk respons sama dengan pencarian tanpa hasil");
                        }
                    }
                }

                // UTF-8 rusak di dalam query list selain search: id_archive (AC-4 mencakup); search JSON rusak = ED-1070 (di luar cakupan)

                // --- select users
                $usersBase = $f->call($s, 'GET', Q71_SEL . 'users', null, 'select users tanpa parameter');
                $f->resp($usersBase, 200, null, 'select users tanpa parameter');
                $baseUsers = q71_s2_values($usersBase);
                $t->true(count($baseUsers) >= 2, 'select users: minimal dua opsi sebagai bahan uji');
                $u1 = $baseUsers[0];
                $u2 = $baseUsers[1];
                $selOne = $f->call($s, 'GET', Q71_SEL . 'users?selected_id=' . $u1, null, 'select users selected_id sah');
                $f->resp($selOne, 200, null, 'select users selected_id sah');

                $badForms = [];
                foreach (q71_bad() as $lab => $ch) {
                    $badForms[$lab] = rawurlencode($ch);
                }
                $badForms['rusak'] = 'x%C3%28';

                foreach ($badForms as $lab => $enc) {
                    // search S_BAD = tidak ada yang cocok
                    $r = $f->call($s, 'GET', Q71_SEL . 'users?search=' . $enc, null, "users search [$lab]");
                    $f->resp($r, 200, null, "users search [$lab]");
                    $f->eq(q71_s2_values($r), [], "users search [$lab]: options []");
                    // search S_BAD + selected_id sah: yang dipilih tetap ikut
                    $r = $f->call($s, 'GET', Q71_SEL . 'users?search=' . $enc . '&selected_id=' . $u1, null, "users search+selected_id sah [$lab]");
                    $f->eq(q71_s2_values($r), [$u1], "users search [$lab] + selected_id sah: hanya yang dipilih");
                    // excepts[] / selected_id S_BAD dibuang (= tanpa parameter)
                    $r = $f->call($s, 'GET', Q71_SEL . 'users?excepts[]=' . $enc, null, "users excepts[] [$lab]");
                    $f->eq(q71_s2_values($r), $baseUsers, "users excepts[]=S_BAD [$lab] = tanpa parameter");
                    $r = $f->call($s, 'GET', Q71_SEL . 'users?excepts[]=' . $enc . '&excepts[]=' . $u1, null, "users excepts[S_BAD,sah] [$lab]");
                    $f->eq(q71_s2_values($r), array_values(array_diff($baseUsers, [$u1])), "users excepts[S_BAD, sah] [$lab]: hanya yang sah disembunyikan");
                    $r = $f->call($s, 'GET', Q71_SEL . 'users?selected_id=' . $enc, null, "users selected_id [$lab]");
                    $f->eq(q71_s2_values($r), $baseUsers, "users selected_id=S_BAD [$lab] = tanpa parameter");
                    $r = $f->call($s, 'GET', Q71_SEL . 'users?selected_id[]=' . $enc . '&selected_id[]=' . $u2 . '&excepts[]=' . $u2, null, "users selected_id[] campur [$lab]");
                    $f->resp($r, 200, null, "users selected_id[] campur [$lab]");
                    $f->eq(q71_s2_values($r), $baseUsers, "users selected_id[S_BAD, u2] + excepts[u2] [$lab]: u2 tetap ikut sebagai dipilih");
                }

                // --- select locations
                $locBase = $f->call($s, 'GET', Q71_SEL . 'locations', null, 'select locations tanpa parameter');
                $f->resp($locBase, 200, null, 'select locations tanpa parameter');
                $baseLocs = q71_s2_values($locBase);
                $t->true(count($baseLocs) >= 2, 'select locations: minimal dua opsi sebagai bahan uji');
                $l1 = $baseLocs[0];

                foreach ($badForms as $lab => $enc) {
                    $r = $f->call($s, 'GET', Q71_SEL . 'locations?search=' . $enc, null, "locations search [$lab]");
                    $f->resp($r, 200, null, "locations search [$lab]");
                    $f->eq(q71_s2_values($r), [], "locations search [$lab]: options []");
                    foreach (['excepts[]=' => 'excepts[]', 'except=' => 'except', 'selected_id=' => 'selected_id', 'selected_id[]=' => 'selected_id[]', 'exclude_id=' => 'exclude_id'] as $param => $name) {
                        $r = $f->call($s, 'GET', Q71_SEL . 'locations?' . $param . $enc, null, "locations $name [$lab]");
                        $f->resp($r, 200, null, "locations $name [$lab]");
                        $f->eq(q71_s2_values($r), $baseLocs, "locations $name=S_BAD [$lab] = tanpa parameter");
                    }
                    $r = $f->call($s, 'GET', Q71_SEL . 'locations?excepts[]=' . $enc . '&excepts[]=' . $l1, null, "locations excepts[S_BAD,sah] [$lab]");
                    $f->eq(q71_s2_values($r), array_values(array_diff($baseLocs, [$l1])), "locations excepts[S_BAD, sah] [$lab]: hanya yang sah disembunyikan");
                    $r = $f->call($s, 'GET', Q71_SEL . 'locations?except=' . $enc . '&exclude_id=' . $l1, null, "locations except S_BAD + exclude_id sah [$lab]");
                    $f->eq(q71_s2_values($r), array_values(array_diff($baseLocs, [$l1])), "locations except=S_BAD + exclude_id sah [$lab]");
                    $r = $f->call($s, 'GET', Q71_SEL . 'locations?search=' . $enc . '&selected_id=' . $l1, null, "locations search+selected_id sah [$lab]");
                    $f->eq(q71_s2_values($r), [$l1], "locations search [$lab] + selected_id sah: hanya yang dipilih (spec #6)");
                }

                // catatan observasi (bukan asersi): perilaku search tanpa hasil biasa + selected_id di select lokasi
                $obs = $t->call($s, 'GET', Q71_SEL . 'locations?search=' . rawurlencode('zzz-tidak-ada') . '&selected_id=' . $l1);
                $t->note('observasi: locations?search=zzz-tidak-ada&selected_id=<sah> -> ' . json_encode(q71_s2_values($obs)) . ' (S_BAD + selected_id sah -> [' . $l1 . '])');

                // S_OK: tetap bekerja (kontrol)
                foreach (q71_ok() as $lab => [$ch, $hex]) {
                    $enc = rawurlencode($ch);
                    $f->resp($f->call($s, 'GET', Q71_SEL . 'users?search=' . $enc, null, "S_OK users search $lab"), 200, null, "S_OK users search $lab");
                    $f->resp($f->call($s, 'GET', Q71_SEL . 'locations?search=' . $enc, null, "S_OK locations search $lab"), 200, null, "S_OK locations search $lab");
                    $f->resp($f->call($s, 'GET', Q71_SEL . 'users?excepts[]=' . $enc . '&selected_id=' . $enc, null, "S_OK users excepts/selected $lab"), 200, null, "S_OK users excepts/selected $lab");
                }

                $f->eq(q71_snap($t, $ids), $before, 'tanpa tulis: baris uji dan jumlah tabel sama');
                $f->done();
            } finally {
                q71_purge($t);
            }
            q71_assert_clean($t, $base);
        },
    ],
];
