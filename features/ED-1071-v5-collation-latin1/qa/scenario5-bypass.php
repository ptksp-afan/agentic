<?php
/**
 * ED-1071 pencarian celah penjaga (di luar AC; hasil FAIL = defect):
 *   X-4 U+FFFD (tanda pengganti, "?" berlian): lolos MyHelper::isLatin1 di PHP 7.3 (mbstring memetakan U+FFFD <-> 0x9D di Windows-1252)
 *       padahal tidak ada di MySQL latin1 -> 500 1267 / tersimpan "?" di semua endpoint Archive;
 *   X-5 nilai ARRAY berisi S_BAD pada field yang belum bertipe string (put-in name, hand-over / receive name, add-document id_archive);
 *   X-6 hand-over action berisi S_BAD (field di luar daftar #3): tidak 5xx dan riwayat tidak memuat "?" pengganti.
 * Data uji berawalan QA71-.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'X-4',
        'title' => 'U+FFFD (tanda pengganti) di semua titik masuk Archive = diperlakukan sebagai S_BAD: body 422 GE0748, id 404, search 200 total 0, select options [] (tidak pernah 500 atau tersimpan "?")',
        'run'   => function ($t) {
            q71_recover($t);
            $s = $t->session();
            q71_purge($t);
            $base = q71_base($t);
            $f = q71_soft($t);
            $ch = "\u{FFFD}";

            try {
                $locs = array_values(q71_locs($t));
                $P = q71_folder($t, 'P', ['description' => 'desc-awal']);
                $T = q71_folder($t, 'T');
                $D = q71_doc($t, 'D', ['parent' => $P]);
                $pName = q71_row($t, $P)['name'];
                $ids = [$P, $T, $D];
                $before = q71_snap($t, $ids);
                $target = $t->db()->table('users')->where('is_active', 1)->where('username', '<>', $t->conf('QA_USER'))->orderBy('username')->value('id_user');
                $perm = function ($id) {
                    return ['id_archive' => $id, 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0];
                };
                $flag = function ($id) {
                    return ['id_archive' => $id, 'is_folder_permission' => 1];
                };

                $body = [
                    'create name'                  => ['POST', 'archives/create-folder', ['name' => 'QA71-f-' . uniqid() . $ch, 'is_all_location' => 1], ['name']],
                    'create description'           => ['POST', 'archives/create-folder', ['name' => 'QA71-f-' . uniqid(), 'description' => 'a' . $ch, 'is_all_location' => 1], ['description']],
                    'create id_archive_parent'     => ['POST', 'archives/create-folder', ['name' => 'QA71-f-' . uniqid(), 'id_archive_parent' => $ch, 'is_all_location' => 1], ['id_archive_parent']],
                    'create id_locations.1'        => ['POST', 'archives/create-folder', ['name' => 'QA71-f-' . uniqid(), 'is_all_location' => 0, 'id_locations' => [$locs[0], $ch]], ['id_locations.1']],
                    'PUT name'                     => ['PUT', 'archives/' . $P, ['name' => $pName . $ch, 'is_all_location' => 1], ['name']],
                    'PUT description'              => ['PUT', 'archives/' . $P, ['name' => $pName, 'description' => $ch, 'is_all_location' => 1], ['description']],
                    'PUT folder_permissions.0.id_user' => ['PUT', 'archives/' . $P, ['name' => $pName, 'is_all_location' => 1, 'folder_permissions' => [['id_user' => $ch, 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0]]], ['folder_permissions.0.id_user']],
                    'rename name'                  => ['PUT', 'archives/rename/' . $P, ['name' => 'QA71-r' . $ch], ['name']],
                    'put-in id_archive_parent'     => ['POST', 'documents/put-in', ['id_archive_parent' => $ch, 'id_archives' => [$D]], ['id_archive_parent']],
                    'put-in id_archives.0'         => ['POST', 'documents/put-in', ['id_archive_parent' => $T, 'id_archives' => [$ch]], ['id_archives.0']],
                    'put-in name'                  => ['POST', 'documents/put-in', ['id_archive_parent' => $T, 'name' => $ch], ['name']],
                    'hand-over name'               => ['POST', 'documents/hand-over', ['name' => $ch, 'action' => 'give'], ['name']],
                    'receive name'                 => ['POST', 'documents/receive', ['name' => $ch, 'action' => 'receive'], ['name']],
                    'add-document id_archive'      => ['POST', 'archives/add-document', ['id_archive' => $ch], ['id_archive']],
                    'user-permissions permissions.0.id_archive' => ['PUT', 'user-permissions/' . $target, ['permissions' => [$perm($ch)]], ['permissions.0.id_archive']],
                    'user-permissions folders.0.id_archive'     => ['PUT', 'user-permissions/' . $target, ['permissions' => [], 'folders' => [$flag($ch)]], ['folders.0.id_archive']],
                ];
                foreach ($body as $label => [$method, $uri, $b, $keys]) {
                    $r = $f->call($s, $method, Q71_API . $uri, $b, "U+FFFD $label");
                    $f->ge0748($r, $keys, "U+FFFD $label");
                    $f->eq(q71_snap($t, $ids), $before, "U+FFFD $label: tanpa tulis");
                }

                // id = 404
                $enc = rawurlencode($ch);
                foreach ([['GET', 'archives?id_archive=' . $enc], ['GET', 'archives/' . $enc], ['PUT', 'archives/' . $enc], ['GET', 'archives/history/' . $enc],
                    ['PUT', 'archives/rename/' . $enc], ['DELETE', 'archives/delete/' . $enc]] as [$method, $uri]) {
                    $r = $f->call($s, $method, Q71_API . $uri, in_array($method, ['PUT'], true) ? ['name' => q71_name('Z'), 'is_all_location' => 1] : null, "U+FFFD id $method $uri");
                    $f->resp($r, 404, 'ARCHIVE400', "U+FFFD id $method $uri");
                }
                $r = $f->call($s, 'GET', Q71_SEL . 'locations?id_archive_parent=' . $enc, null, 'U+FFFD select locations id_archive_parent');
                $f->resp($r, 404, 'ARCHIVE400', 'U+FFFD select locations id_archive_parent');
                foreach (['GET', 'PUT'] as $method) {
                    $r = $f->call($s, $method, Q71_API . 'user-permissions/' . $enc, $method === 'PUT' ? ['permissions' => []] : null, "U+FFFD user-permissions $method");
                    $f->resp($r, 404, 'ARCHIVE440', "U+FFFD user-permissions $method");
                }

                // search = 200 total 0; select = []
                foreach ([['query' => $ch], ['type' => $ch], ['name' => $ch]] as $json) {
                    foreach ([null, $P] as $ctx) {
                        $r = $f->call($s, 'GET', Q71_API . 'archives?search=' . rawurlencode(json_encode($json)) . ($ctx ? '&id_archive=' . $ctx : ''), null, 'U+FFFD search ' . json_encode(array_keys($json)) . ($ctx ? ' folder' : ' root'));
                        $f->resp($r, 200, null, 'U+FFFD search ' . json_encode(array_keys($json)));
                        $f->eq($r[1]['result']['total'] ?? null, 0, 'U+FFFD search ' . json_encode(array_keys($json)) . ': total 0');
                    }
                }
                foreach (['users', 'locations'] as $sel) {
                    $r = $f->call($s, 'GET', Q71_SEL . $sel . '?search=' . $enc, null, "U+FFFD select $sel search");
                    $f->resp($r, 200, null, "U+FFFD select $sel search");
                    $f->eq($r[1]['result']['options'] ?? null, [], "U+FFFD select $sel search: options []");
                    $r = $f->call($s, 'GET', Q71_SEL . $sel . '?excepts[]=' . $enc . '&selected_id=' . $enc, null, "U+FFFD select $sel excepts/selected_id");
                    $f->resp($r, 200, null, "U+FFFD select $sel excepts/selected_id");
                }

                $f->eq(q71_snap($t, $ids), $before, 'akhir: tanpa tulis');
                $f->done();
            } finally {
                q71_purge($t);
            }
            q71_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-5',
        'title' => 'Nilai ARRAY berisi S_BAD pada field tanpa aturan string (put-in name, hand-over / receive name, add-document id_archive) = 4xx, bukan 500 (dibanding string biasa = 422 GE0748)',
        'run'   => function ($t) {
            q71_recover($t);
            $s = $t->session();
            q71_purge($t);
            $base = q71_base($t);
            $f = q71_soft($t);

            try {
                $T = q71_folder($t, 'T');
                $D = q71_doc($t, 'D');
                $ids = [$T, $D];
                $before = q71_snap($t, $ids);

                foreach (q71_bad() as $lab => $ch) {
                    $cases = [
                        "put-in name [$lab]"            => ['POST', 'documents/put-in', ['id_archive_parent' => $T, 'name' => [$ch]]],
                        "put-in name [ok, $lab]"        => ['POST', 'documents/put-in', ['id_archive_parent' => $T, 'name' => ['x', $ch]]],
                        "hand-over name [$lab]"         => ['POST', 'documents/hand-over', ['name' => [$ch], 'action' => 'give']],
                        "receive name [$lab]"           => ['POST', 'documents/receive', ['name' => [$ch], 'action' => 'receive']],
                        "add-document id_archive [$lab]" => ['POST', 'archives/add-document', ['id_archive' => [$ch]]],
                    ];
                    foreach ($cases as $label => [$method, $uri, $b]) {
                        $r = $f->call($s, $method, Q71_API . $uri, $b, "array: $label");
                        $f->ok($r[0] >= 400 && $r[0] < 500, "array: $label = 4xx (HTTP {$r[0]})");
                        $f->eq(q71_snap($t, $ids), $before, "array: $label: tanpa tulis");
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
        'id'    => 'X-6',
        'title' => 'hand-over action berisi S_BAD (di luar daftar field #3): bukan 5xx dan riwayat tidak memuat "?" pengganti',
        'run'   => function ($t) {
            q71_recover($t);
            $s = $t->session();
            q71_purge($t);
            $base = q71_base($t);
            $f = q71_soft($t);

            try {
                foreach (q71_bad() as $lab => $ch) {
                    $D = q71_doc($t, 'D');
                    $name = q71_row($t, $D)['name'];
                    $r = $f->call($s, 'POST', Q71_API . 'documents/hand-over', ['name' => $name, 'action' => $ch], "hand-over action $lab");
                    $t->note("hand-over action $lab -> HTTP {$r[0]}");
                    // action diabaikan (controller menetapkan give / receive); yang dijaga: bukan 5xx dan riwayat tanpa '?' pengganti
                    $raw = (string) $t->db()->table('archives')->where('id_archive', $D)->value('history');
                    $f->ok(strpos($raw, '?') === false, "hand-over action $lab: riwayat tanpa '?' (" . substr($raw, 0, 160) . ')');
                    $f->ok($r[0] < 500, "hand-over action $lab: bukan 5xx (HTTP {$r[0]})");
                }
                $f->done();
            } finally {
                q71_purge($t);
            }
            q71_assert_clean($t, $base);
        },
    ],
];
