<?php
/**
 * ED-1025 ronde 2 - ketahanan input pada FormRequest endpoint tulis yang diubah item ini:
 *   PUT archives/{id} (UpdateRequest), PUT archives/rename/{id} (RenameFolderRequest),
 *   POST archives/create-folder (CreateFolderRequest), POST documents/put-in (PutInFolderRequest),
 *   DELETE archives/delete/{id} (DeleteRequest).
 * Setiap field dikirim dengan tipe salah (array, objek, bersarang, int, float, boolean, null, kosong, "0",
 * string sangat panjang, byte NUL, unicode): TIDAK ADA yang boleh 5xx (D-6); array/objek pada field teks = 422.
 * Data uji berawalan QA02-; hasil 2xx di-reset / dibuang per kasus; sisa diperiksa di akhir.
 */
require_once __DIR__ . '/qa_lib.php';

if (!function_exists('q5_values')) {

    /** Nilai bertipe salah (label => nilai). */
    function q5_values()
    {
        return [
            'array'   => ['x'],
            'objek'   => ['a' => 'b'],
            'bersarang' => [['x']],
            'int'     => 123,
            'float'   => 1.5,
            'true'    => true,
            'false'   => false,
            'null'    => null,
            'kosong'  => '',
            'nol'     => '0',
            'spasi'   => '   ',
            'nul'     => "a\0b",
            'unicode' => "\u{00FC}n\u{00EF} \u{2019}x\u{2014}\u{20AC}",   // semua ada di Windows-1252 (karakter lain: X-12)
            'panjang300' => str_repeat('a', 300),
        ];
    }

    /** Nilai yang bukan string dan bukan null: aturan `string` wajib menolaknya (422). */
    function q5_must_422($label)
    {
        return in_array($label, ['array', 'objek', 'bersarang', 'int', 'float', 'true', 'false'], true);
    }

    /** Kembalikan folder uji P ke keadaan awal (aman dipanggil berulang). */
    function q5_reset($t, $P, $pName)
    {
        q2_w($t, function ($c) use ($P, $pName) {
            $c->table('archive_permissions')->where('id_archive', $P)->delete();
            $c->table('archive_locations')->where('id_archive', $P)->delete();
            $c->table('archives')->where('id_archive', $P)->update([
                'name'                 => $pName,
                'description'          => null,
                'id_archive_parent'    => null,
                'is_all_location'      => 1,
                'is_folder_permission' => 0,
                'is_active'            => 1,
                'updated_by'           => 'QA02',
            ]);
        });
    }

    /** Buang folder yang diciptakan create-folder oleh kasus uji (berdasar waktu mulai + pembuat), apa pun namanya. */
    function q5_drop_created($t, $since, $username)
    {
        q2_w($t, function ($c) use ($since, $username) {
            $ids = $c->table('archives')->where('created_at', '>=', $since)->where('created_by', $username)
                ->where('type', 1)->pluck('id_archive')->all();
            foreach ($ids as $id) {
                $c->table('archive_permissions')->where('id_archive', $id)->delete();
                $c->table('archive_locations')->where('id_archive', $id)->delete();
                $c->table('archives')->where('id_archive', $id)->delete();
            }
        });
    }

    /** Snapshot baris archives yang relevan untuk membuktikan penolakan tidak mengubah data. */
    function q5_snap($t, $P)
    {
        return [
            'archive' => q2_row($t, $P),
            'perm'    => q2_perm_map($t, $P),
            'loc'     => $t->db()->table('archive_locations')->where('id_archive', $P)->count(),
        ];
    }

    /**
     * Jalankan daftar kasus [label, method, uri, body, must422]: tidak boleh 5xx; must422 = tepat 422 dan data tidak berubah.
     * $characterize = true (karakterisasi bug pra-ada yang dilacak di tiket lain): 5xx tidak dihitung pelanggaran, tetapi
     * data tidak boleh berubah pada setiap respons >= 400.
     * Mengembalikan [daftar pelanggaran, ringkasan 'label=status', peta label => status].
     */
    function q5_run_cases($t, $s, array $cases, $P, $pName, $username, $since, $characterize = false)
    {
        $bad = [];
        $seen = [];
        $map = [];
        foreach ($cases as $c) {
            q5_reset($t, $P, $pName);
            $before = q5_snap($t, $P);
            $r = $t->call($s, $c[1], $c[2], $c[3]);
            $seen[] = $c[0] . '=' . $r[0];
            $map[$c[0]] = (int) $r[0];

            if ($r[0] >= 500 && $characterize) {
                if (q5_snap($t, $P) !== $before) {
                    $bad[] = $c[0] . ' -> HTTP ' . $r[0] . ' dan data folder berubah';
                }
            } elseif ($r[0] >= 500) {
                $bad[] = $c[0] . ' -> HTTP ' . $r[0] . ' ' . substr(json_encode($r[1]), 0, 150);
            } elseif ($c[4] && (int) $r[0] !== 422) {
                $bad[] = $c[0] . ' -> HTTP ' . $r[0] . ', diharapkan 422 ' . substr(json_encode($r[1]), 0, 120);
            } elseif ($r[0] >= 400 && q5_snap($t, $P) !== $before) {
                $bad[] = $c[0] . ' -> HTTP ' . $r[0] . ' tetapi data folder berubah';
            }
        }
        q5_reset($t, $P, $pName);
        q5_drop_created($t, $since, $username);

        return [$bad, $seen, $map];
    }
}

return [

    [
        'id'    => 'X-9',
        'title' => 'Tipe/nilai salah pada setiap field FormRequest PUT archives/{id}, rename, create-folder, put-in dan DELETE: tidak ada 5xx (D-2 diperbaiki); array/objek/angka/boolean pada field teks = 422 dan data tidak berubah',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $archivesBefore = $t->db()->table('archives')->count();
            $username = $t->db()->table('users')->where('id_user', q2_uid($t))->value('username');
            $since = date('Y-m-d H:i:s', time() - 1);
            $locations = $t->db()->table('locations')->orderBy('id_location')->limit(2)->pluck('id_location')->all();
            $t->true(count($locations) >= 1, 'ada minimal satu lokasi uji');

            try {
                $P = q2_folder($t, 'P', ['perm' => 0]);
                $pName = q2_row($t, $P)['name'];
                $Q = q2_folder($t, 'Q', ['perm' => 0]);
                $D = q2_doc($t, 'D', ['parent' => $P]);
                $api = 'api/v5/document-archive/';
                $values = q5_values();
                $cases = [];

                // ---- PUT archives/{id}: field teks (name, description, id_archive_parent) dan is_all_location
                foreach ($values as $label => $v) {
                    $must = q5_must_422($label);
                    $cases[] = ['PUT name ' . $label, 'PUT', $api . 'archives/' . $P, ['name' => $v, 'is_all_location' => 1], $must || $label === 'null' || $label === 'kosong'];
                    $cases[] = ['PUT description ' . $label, 'PUT', $api . 'archives/' . $P, q2_body($pName, ['description' => $v]), $must];
                    $cases[] = ['PUT id_archive_parent ' . $label, 'PUT', $api . 'archives/' . $P, q2_body($pName, ['id_archive_parent' => $v]), $must];
                    $cases[] = ['PUT is_all_location ' . $label, 'PUT', $api . 'archives/' . $P, ['name' => $pName, 'is_all_location' => $v], in_array($label, ['array', 'objek', 'bersarang', 'float', 'null', 'kosong', 'panjang300'], true)];
                    $cases[] = ['PUT is_folder_permission ' . $label, 'PUT', $api . 'archives/' . $P, q2_body($pName, ['is_folder_permission' => $v]), in_array($label, ['array', 'objek', 'bersarang', 'float', 'panjang300'], true)];
                    $cases[] = ['PUT id_locations ' . $label, 'PUT', $api . 'archives/' . $P, ['name' => $pName, 'is_all_location' => 0, 'id_locations' => $v], in_array($label, ['int', 'float', 'true', 'false'], true) ? false : false];
                    $cases[] = ['PUT id_locations[] ' . $label, 'PUT', $api . 'archives/' . $P, ['name' => $pName, 'is_all_location' => 0, 'id_locations' => [$v]], in_array($label, ['array', 'objek', 'bersarang', 'int', 'float', 'true', 'false'], true)];
                    // elemen array bersarang di daftar >= 2 elemen: defect D-3, ada di X-11
                    if (!is_array($v)) {
                        $cases[] = ['PUT id_locations[valid, ' . $label . ']', 'PUT', $api . 'archives/' . $P, ['name' => $pName, 'is_all_location' => 0, 'id_locations' => [$locations[0], $v]], in_array($label, ['int', 'float', 'true', 'false'], true)];
                    }
                    $cases[] = ['PUT folder_permissions ' . $label, 'PUT', $api . 'archives/' . $P, q2_body($pName, ['folder_permissions' => $v]), in_array($label, ['int', 'float', 'true', 'nul', 'unicode', 'panjang300', 'nol', 'spasi'], true) && false];
                }

                // ---- PUT: bentuk folder_permissions / id_locations lain
                $cases[] = ['PUT folder_permissions "x"', 'PUT', $api . 'archives/' . $P, q2_body($pName, ['folder_permissions' => 'x']), true];
                $cases[] = ['PUT folder_permissions 5', 'PUT', $api . 'archives/' . $P, q2_body($pName, ['folder_permissions' => 5]), true];
                $cases[] = ['PUT folder_permissions [[[]]]', 'PUT', $api . 'archives/' . $P, q2_body($pName, ['folder_permissions' => [[[]]]]), true];
                $cases[] = ['PUT folder_permissions [1,2]', 'PUT', $api . 'archives/' . $P, q2_body($pName, ['folder_permissions' => [1, 2]]), true];
                $cases[] = ['PUT folder_permissions ["a"]', 'PUT', $api . 'archives/' . $P, q2_body($pName, ['folder_permissions' => ['a']]), true];
                $cases[] = ['PUT id_locations objek berkunci', 'PUT', $api . 'archives/' . $P, ['name' => $pName, 'is_all_location' => 0, 'id_locations' => ['k' => $locations[0]]], false];
                $cases[] = ['PUT id_locations [[valid]]', 'PUT', $api . 'archives/' . $P, ['name' => $pName, 'is_all_location' => 0, 'id_locations' => [[$locations[0]]]], true];
                $cases[] = ['PUT tubuh bukan objek ("x" sebagai JSON)', 'PUT', $api . 'archives/' . $P, 'x', false];
                $cases[] = ['PUT tubuh daftar [1,2]', 'PUT', $api . 'archives/' . $P, [1, 2], false];
                $cases[] = ['PUT tubuh kosong []', 'PUT', $api . 'archives/' . $P, [], true];

                // ---- rename
                foreach ($values as $label => $v) {
                    $cases[] = ['rename name ' . $label, 'PUT', $api . 'archives/rename/' . $P, ['name' => $v], q5_must_422($label) || $label === 'null' || $label === 'kosong'];
                }
                $cases[] = ['rename tanpa name', 'PUT', $api . 'archives/rename/' . $P, [], true];
                $cases[] = ['rename name di luar body (query)', 'PUT', $api . 'archives/rename/' . $P . '?name[]=x', [], true];

                // ---- create-folder
                foreach ($values as $label => $v) {
                    $must = q5_must_422($label);
                    $cases[] = ['create name ' . $label, 'POST', $api . 'archives/create-folder', ['name' => $v, 'is_all_location' => 1], $must || $label === 'null' || $label === 'kosong'];
                    $cases[] = ['create description ' . $label, 'POST', $api . 'archives/create-folder', ['name' => q2_name('C'), 'description' => $v, 'is_all_location' => 1], $must];
                    $cases[] = ['create id_archive_parent ' . $label, 'POST', $api . 'archives/create-folder', ['name' => q2_name('C'), 'id_archive_parent' => $v, 'is_all_location' => 1], $must];
                    $cases[] = ['create is_all_location ' . $label, 'POST', $api . 'archives/create-folder', ['name' => q2_name('C'), 'is_all_location' => $v], in_array($label, ['array', 'objek', 'bersarang', 'float', 'null', 'kosong', 'panjang300'], true)];
                    $cases[] = ['create id_locations ' . $label, 'POST', $api . 'archives/create-folder', ['name' => q2_name('C'), 'is_all_location' => 0, 'id_locations' => $v], false];
                    $cases[] = ['create id_locations[] ' . $label, 'POST', $api . 'archives/create-folder', ['name' => q2_name('C'), 'is_all_location' => 0, 'id_locations' => [$v]], in_array($label, ['array', 'objek', 'bersarang', 'int', 'float', 'true', 'false'], true)];
                    $cases[] = ['create is_folder_permission ' . $label, 'POST', $api . 'archives/create-folder', ['name' => q2_name('C'), 'is_all_location' => 1, 'is_folder_permission' => $v], false];
                    $cases[] = ['create folder_permissions ' . $label, 'POST', $api . 'archives/create-folder', ['name' => q2_name('C'), 'is_all_location' => 1, 'folder_permissions' => $v], false];
                }
                $cases[] = ['create tubuh kosong', 'POST', $api . 'archives/create-folder', [], true];

                // ---- put-in
                foreach ($values as $label => $v) {
                    $cases[] = ['put-in id_archive_parent ' . $label, 'POST', $api . 'documents/put-in', ['id_archive_parent' => $v, 'id_archives' => [$D]], q5_must_422($label)];
                    $cases[] = ['put-in id_archives ' . $label, 'POST', $api . 'documents/put-in', ['id_archive_parent' => $P, 'id_archives' => $v], false];
                    $cases[] = ['put-in id_archives[] ' . $label, 'POST', $api . 'documents/put-in', ['id_archive_parent' => $P, 'id_archives' => [$v]], in_array($label, ['array', 'objek', 'bersarang', 'int', 'float', 'true', 'false'], true)];
                    $cases[] = ['put-in id_archives[valid, ' . $label . ']', 'POST', $api . 'documents/put-in', ['id_archive_parent' => $P, 'id_archives' => [$D, $v]], in_array($label, ['array', 'objek', 'bersarang', 'int', 'float', 'true', 'false'], true)];
                    $cases[] = ['put-in name ' . $label, 'POST', $api . 'documents/put-in', ['id_archive_parent' => $P, 'name' => $v], false];
                    $cases[] = ['put-in name ' . $label . ' + id_archives', 'POST', $api . 'documents/put-in', ['id_archive_parent' => $P, 'id_archives' => [$D], 'name' => $v], false];
                }
                $cases[] = ['put-in tubuh kosong', 'POST', $api . 'documents/put-in', [], false];
                $cases[] = ['put-in id_archives objek berkunci', 'POST', $api . 'documents/put-in', ['id_archive_parent' => $P, 'id_archives' => ['k' => $D]], false];
                $cases[] = ['put-in id_archives [[D]]', 'POST', $api . 'documents/put-in', ['id_archive_parent' => $P, 'id_archives' => [[$D]]], true];
                $cases[] = ['put-in tubuh bukan objek', 'POST', $api . 'documents/put-in', 'x', false];

                // ---- DELETE: tubuh/query aneh, id di route aneh (RouteRequest tanpa aturan)
                $cases[] = ['DELETE dengan tubuh array', 'DELETE', $api . 'archives/delete/' . $Q, ['id' => ['x']], false];
                $cases[] = ['DELETE id %00', 'DELETE', $api . 'archives/delete/a%00b', null, false];
                $cases[] = ['DELETE id panjang', 'DELETE', $api . 'archives/delete/' . str_repeat('a', 400), null, false];

                [$bad, $seen] = q5_run_cases($t, $s, $cases, $P, $pName, $username, $since);
                $statusCount = [];
                foreach ($seen as $one) {
                    $code = substr($one, strrpos($one, '=') + 1);
                    $statusCount[$code] = ($statusCount[$code] ?? 0) + 1;
                }
                ksort($statusCount);
                $t->note(count($cases) . ' kasus; status: ' . json_encode($statusCount));
                $t->eq($bad, [], 'tidak ada 5xx / status tak terduga');

            } finally {
                q5_drop_created($t, $since, $username);
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
            $t->eq($t->db()->table('archives')->count(), $archivesBefore, 'jumlah baris archives kembali ke awal');
        },
    ],

    [
        'id'    => 'X-10',
        'title' => 'Setiap sel baris folder_permissions (id_user, is_view, is_update, is_delete, is_store) bertipe salah, id_user dengan spasi/huruf besar/duplikat semu, dan baris sangat banyak: tidak ada 5xx, data tidak berubah bila ditolak',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);
            $o = q2_others($t, 2);
            $username = $t->db()->table('users')->where('id_user', $b)->value('username');
            $since = date('Y-m-d H:i:s', time() - 1);

            try {
                $P = q2_folder($t, 'P', ['perm' => 0]);
                $pName = q2_row($t, $P)['name'];
                $api = 'api/v5/document-archive/';
                $good = ['id_user' => $o[0][0], 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0];
                $self = ['id_user' => $b, 'is_view' => 1, 'is_update' => 1, 'is_delete' => 1, 'is_store' => 1];
                $put = function ($rows, array $extra = []) use ($pName) {
                    return q2_body($pName, ['is_folder_permission' => 1, 'folder_permissions' => $rows] + $extra);
                };
                $cases = [];
                $cellMust422 = function ($cell, $label) {
                    // id_user: string wajib (exists + string); flag: in:0,1 menolak array/objek/float/null/kosong/teks lain
                    if ($cell === 'id_user') {
                        return in_array($label, ['array', 'objek', 'bersarang', 'int', 'float', 'true', 'false', 'null', 'kosong', 'nol', 'spasi', 'nul', 'unicode', 'panjang300'], true);
                    }

                    return in_array($label, ['array', 'objek', 'bersarang', 'float', 'null', 'kosong', 'spasi', 'nul', 'unicode', 'panjang300'], true);
                };

                foreach (q5_values() as $label => $v) {
                    foreach (['id_user', 'is_view', 'is_update', 'is_delete', 'is_store'] as $cell) {
                        $row = [$cell => $v] + $good;
                        $cases[] = ['sel ' . $cell . ' ' . $label, 'PUT', $api . 'archives/' . $P, $put([$self, $row]), $cellMust422($cell, $label)];
                    }
                }

                // id_user: spasi di ujung / awal, huruf besar, ganda semu (PAD SPACE / collation ci)
                $cases[] = ['id_user + spasi akhir', 'PUT', $api . 'archives/' . $P, $put([$self, ['id_user' => $o[0][0] . ' '] + $good]), false];
                $cases[] = ['id_user + spasi awal', 'PUT', $api . 'archives/' . $P, $put([$self, ['id_user' => ' ' . $o[0][0]] + $good]), false];
                $cases[] = ['id_user + tab akhir', 'PUT', $api . 'archives/' . $P, $put([$self, ['id_user' => $o[0][0] . "\t"] + $good]), false];
                $cases[] = ['id_user ganda semu (spasi akhir)', 'PUT', $api . 'archives/' . $P, $put([$self, $good, ['id_user' => $o[0][0] . ' '] + $good]), false];
                $cases[] = ['id_user ganda semu (dua spasi akhir)', 'PUT', $api . 'archives/' . $P, $put([$self, ['id_user' => $o[0][0] . '  '] + $good, ['id_user' => $o[0][0] . ' '] + $good]), false];
                $cases[] = ['id_user ganda semu (huruf besar)', 'PUT', $api . 'archives/' . $P, $put([$self, ['id_user' => strtoupper($o[0][0])] + $good, ['id_user' => strtolower($o[0][0])] + $good]), false];
                $cases[] = ['id_user ganda semu (+0 depan numerik)', 'PUT', $api . 'archives/' . $P, $put([$self, $good, ['id_user' => '0' . $o[0][0]] + $good]), false];
                $cases[] = ['id_user ganda persis', 'PUT', $api . 'archives/' . $P, $put([$self, $good, $good]), true];
                $cases[] = ['id_user dua user berbeda + ganda semu', 'PUT', $api . 'archives/' . $P, $put([$self, $good, ['id_user' => $o[1][0]] + $good, ['id_user' => $o[1][0] . ' '] + $good]), false];
                $cases[] = ['kunci baris tambahan bertipe array', 'PUT', $api . 'archives/' . $P, $put([$self, $good + ['x' => ['a' => [1]]]]), false];
                $cases[] = ['2000 baris id_user berbeda tak ada', 'PUT', $api . 'archives/' . $P, $put(array_map(function ($i) use ($good) {
                    return ['id_user' => 'QA02-NOPE-' . $i] + $good;
                }, range(1, 2000))), true];
                $cases[] = ['PUT folder_permissions + is_folder_permission salah tipe', 'PUT', $api . 'archives/' . $P, q2_body($pName, ['is_folder_permission' => [1], 'folder_permissions' => [$self]]), true];

                [$bad, $seen] = q5_run_cases($t, $s, $cases, $P, $pName, $username, $since);
                $statusCount = [];
                foreach ($seen as $one) {
                    $code = substr($one, strrpos($one, '=') + 1);
                    $statusCount[$code] = ($statusCount[$code] ?? 0) + 1;
                }
                ksort($statusCount);
                $t->note(count($cases) . ' kasus; status: ' . json_encode($statusCount));
                $t->eq($bad, [], 'tidak ada 5xx / status tak terduga');
            } finally {
                q5_drop_created($t, $since, $username);
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],


    [
        'id'    => 'X-11',
        'title' => 'D-3: id_locations (PUT archives/{id}, create-folder) berisi elemen array/objek di daftar >= 2 elemen = 422, bukan 500 (exists + array_unique); kontrol: dua lokasi valid = 200',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $username = $t->db()->table('users')->where('id_user', q2_uid($t))->value('username');
            $since = date('Y-m-d H:i:s', time() - 1);
            $locations = $t->db()->table('locations')->orderBy('id_location')->limit(2)->pluck('id_location')->all();

            try {
                $P = q2_folder($t, 'P', ['perm' => 0]);
                $pName = q2_row($t, $P)['name'];
                $api = 'api/v5/document-archive/';
                $loc = $locations[0];
                $lists = [
                    '[valid, [x]]'      => [$loc, ['x']],
                    '[[x], valid]'      => [['x'], $loc],
                    '[[x], [y]]'        => [['x'], ['y']],
                    '[nope, [x]]'       => ['nope', ['x']],
                    '[valid, {a:b}]'    => [$loc, ['a' => 'b']],
                    '[valid, [[x]]]'    => [$loc, [['x']]],
                    '[valid, valid, [x]]' => [$loc, $loc, ['x']],
                ];
                $cases = [];
                foreach ($lists as $label => $list) {
                    $cases[] = ['PUT id_locations ' . $label, 'PUT', $api . 'archives/' . $P, ['name' => $pName, 'is_all_location' => 0, 'id_locations' => $list], true];
                    $cases[] = ['create id_locations ' . $label, 'POST', $api . 'archives/create-folder', ['name' => q2_name('C'), 'is_all_location' => 0, 'id_locations' => $list], true];
                }
                [$bad, $seen] = q5_run_cases($t, $s, $cases, $P, $pName, $username, $since);
                $t->note(count($cases) . ' kasus: ' . implode(' ; ', $seen));
                $t->eq($bad, [], 'tidak ada 5xx; semua 422 dan data tidak berubah');

                // kontrol: dua lokasi valid (bila ada) tetap diterima
                if (count($locations) === 2) {
                    q5_reset($t, $P, $pName);
                    $r = $t->call($s, 'PUT', $api . 'archives/' . $P, ['name' => $pName, 'is_all_location' => 0, 'id_locations' => $locations]);
                    $t->status($r, 200, 'kontrol: dua lokasi valid');
                    $t->eq($t->db()->table('archive_locations')->where('id_archive', $P)->count(), 2, 'kontrol: dua baris archive_locations');
                }
            } finally {
                q5_drop_created($t, $since, $username);
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-12',
        'title' => 'ED-1071 (karakterisasi bug pra-ada lintas v5, BUKAN perilaku yang benar): karakter di luar Windows-1252 (Yunani, CJK, emoji, aksen gabung, tanda RTL) pada name, id_archive_parent, id_locations, id_user, put-in, id_archive list = 500 (Illegal mix of collations); rename = 200; data tidak berubah',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);
            $b = q2_uid($t);
            $o = q2_others($t, 1);
            $username = $t->db()->table('users')->where('id_user', $b)->value('username');
            $since = date('Y-m-d H:i:s', time() - 1);
            $loc = $t->db()->table('locations')->orderBy('id_location')->value('id_location');

            try {
                $P = q2_folder($t, 'P', ['perm' => 0]);
                $pName = q2_row($t, $P)['name'];
                $D = q2_doc($t, 'D', ['parent' => $P]);
                $api = 'api/v5/document-archive/';
                $chars = ['omega' => "\u{03A9}", 'cjk' => "\u{4E2D}\u{6587}", 'emoji' => "\u{1F600}", 'aksen-gabung' => "e\u{0301}", 'rtl' => "\u{202E}"];
                $self = ['id_user' => $b, 'is_view' => 1, 'is_update' => 1, 'is_delete' => 1, 'is_store' => 1];
                $cases = [];
                foreach ($chars as $label => $ch) {
                    $cases[] = ['PUT name ' . $label, 'PUT', $api . 'archives/' . $P, ['name' => $pName . $ch, 'is_all_location' => 1], false];
                    $cases[] = ['create name ' . $label, 'POST', $api . 'archives/create-folder', ['name' => q2_name('C') . $ch, 'is_all_location' => 1], false];
                    $cases[] = ['PUT id_archive_parent ' . $label, 'PUT', $api . 'archives/' . $P, q2_body($pName, ['id_archive_parent' => $ch]), false];
                    $cases[] = ['create id_archive_parent ' . $label, 'POST', $api . 'archives/create-folder', ['name' => q2_name('C'), 'id_archive_parent' => $ch, 'is_all_location' => 1], false];
                    $cases[] = ['PUT id_locations ' . $label, 'PUT', $api . 'archives/' . $P, ['name' => $pName, 'is_all_location' => 0, 'id_locations' => [$ch]], false];
                    $cases[] = ['PUT id_locations[valid, ' . $label . ']', 'PUT', $api . 'archives/' . $P, ['name' => $pName, 'is_all_location' => 0, 'id_locations' => [$loc, $ch]], false];
                    $cases[] = ['PUT folder_permissions id_user ' . $label, 'PUT', $api . 'archives/' . $P, q2_body($pName, ['folder_permissions' => [$self, ['id_user' => $ch, 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0]]]), false];
                    $cases[] = ['put-in id_archive_parent ' . $label, 'POST', $api . 'documents/put-in', ['id_archive_parent' => $ch, 'id_archives' => [$D]], false];
                    $cases[] = ['put-in id_archives ' . $label, 'POST', $api . 'documents/put-in', ['id_archive_parent' => $P, 'id_archives' => [$ch]], false];
                    $cases[] = ['put-in name ' . $label, 'POST', $api . 'documents/put-in', ['id_archive_parent' => $P, 'name' => $ch], false];
                    $cases[] = ['list id_archive ' . $label, 'GET', $api . 'archives?id_archive=' . urlencode($ch), null, false];
                    $cases[] = ['list search query ' . $label, 'GET', $api . 'archives?search=' . urlencode(json_encode(['query' => $ch])), null, false];
                    $cases[] = ['rename name ' . $label, 'PUT', $api . 'archives/rename/' . $P, ['name' => $pName . $ch], false];
                    $cases[] = ['show id ' . $label, 'GET', $api . 'archives/' . urlencode($ch), null, false];
                    $cases[] = ['history id ' . $label, 'GET', $api . 'archives/history/' . urlencode($ch), null, false];
                    $cases[] = ['DELETE id ' . $label, 'DELETE', $api . 'archives/delete/' . urlencode($ch), null, false];
                }
                // ED-1071 (karakterisasi): perilaku pra-ada yang dilacak di Jira ED-1071 (kolom archives*/users/locations berkolasi latin1,
                // koneksi utf8 -> 500 saat karakter di luar Windows-1252 jadi pembanding SQL; sama di HEAD, baris di luar diff ED-1025).
                // Bila ED-1071 mengubah perilaku (500 menjadi 4xx/200), skenario ini GAGAL dengan sengaja: perbarui karakterisasi menjadi
                // asersi "tidak 5xx" dan hapus tag. Rename: tanpa aturan unik, karakter tersimpan sebagai '?' (200).
                [$bad, $seen, $map] = q5_run_cases($t, $s, $cases, $P, $pName, $username, $since, true);
                $drift = [];
                foreach ($cases as $c) {
                    $want = strpos($c[0], 'rename name ') === 0 ? 200 : 500;
                    if (($map[$c[0]] ?? null) !== $want) {
                        $drift[] = 'ED-1071 berubah: ' . $c[0] . ' kini HTTP ' . ($map[$c[0]] ?? '?') . ' (sebelumnya ' . $want . ')';
                    }
                }
                $counts = array_count_values($map);
                ksort($counts);
                $t->note(count($cases) . ' kasus; status: ' . json_encode($counts));
                $t->eq($bad, [], 'data folder tidak berubah pada respons >= 400');
                $t->eq($drift, [], 'karakterisasi ED-1071 sama dengan perilaku pra-ada (bila gagal: ED-1071 telah mengubah perilaku, perbarui skenario)');
            } finally {
                q5_drop_created($t, $since, $username);
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'X-13',
        'title' => 'ED-1070 (karakterisasi bug pra-ada parameter list generik v5, BUKAN perilaku yang benar): pagination, sorts, search bertipe/bentuk salah pada GET archives = 500',
        'run'   => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $base = q2_perm_total($t);

            try {
                $P = q2_folder($t, 'P', ['perm' => 0]);
                $api = 'api/v5/document-archive/archives';
                $sort = function ($v) {
                    return 'sorts[0]=' . urlencode(json_encode($v));
                };
                $qs = [
                    'pagination=abc', 'pagination[]=1', 'pagination[a]=1', 'pagination=-1',
                    'sorts=bukan-json', 'sorts=' . urlencode('{"name":"asc"}'),
                    $sort(['sortBy' => 'name']), $sort(['sortBy' => 'name', 'sortType' => null]), $sort(['sortBy' => ['a'], 'sortType' => 'asc']),
                    'sorts[0]=' . urlencode('["x"]'),
                    'search[]=x', 'search[a]=b',
                    'search=' . urlencode('{"showRelatedTransaction":["a"]}'),
                ];
                // ED-1070 (karakterisasi): D-5 ditambahkan ke ED-1070 (keluarga D-1: CustomizeBuilder scopeSortAll/scopeSearchAll, ArchiveService
                // memakai $request->pagination langsung; sama di HEAD, baris di luar diff ED-1025). Bila ED-1070 mengubah salah satu (500 menjadi 4xx),
                // skenario ini GAGAL dengan sengaja: perbarui karakterisasi menjadi asersi 4xx dan hapus tag.
                $drift = [];
                $seen = [];
                foreach ($qs as $q) {
                    $r = $t->call($s, 'GET', $api . '?' . $q);
                    $seen[] = $q . '=' . $r[0];
                    if ($r[0] !== 500) {
                        $drift[] = 'ED-1070 berubah: GET archives?' . $q . ' kini HTTP ' . $r[0] . ' (sebelumnya 500)';
                    }
                }
                $t->note(implode(' ; ', $seen));
                $t->eq($drift, [], 'karakterisasi ED-1070 sama dengan perilaku pra-ada (bila gagal: ED-1070 telah mengubah perilaku, perbarui skenario)');
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $base);
        },
    ],

];
