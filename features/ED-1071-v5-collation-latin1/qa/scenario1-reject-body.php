<?php
/**
 * ED-1071 AC-1..AC-3 - input tulis (body) dengan karakter di luar latin1 (S_BAD) ditolak 422 GE0748 di kunci field-nya,
 * tanpa tulis (snapshot semua kolom baris uji + jumlah baris tabel Archive sama), tanpa 5xx.
 * Data uji berawalan QA71-; dibuang di finally dan diperiksa bersih di akhir.
 */
require_once __DIR__ . '/qa_lib.php';

if (!function_exists('q71_s1_fixture')) {

    /** Fixture bersama AC-1..AC-3: P (folder), Q (saudara P di root), D (dokumen di P), PL (folder satu lokasi), T (folder kosong). */
    function q71_s1_fixture($t)
    {
        $locs = array_values(q71_locs($t));
        $f = [];
        $f['L1'] = $locs[0];
        $f['L2'] = $locs[1] ?? $locs[0];
        $f['P'] = q71_folder($t, 'P', ['description' => 'desc-awal']);
        $f['Q'] = q71_folder($t, 'Q');
        $f['D'] = q71_doc($t, 'D', ['parent' => $f['P']]);
        $f['PL'] = q71_folder($t, 'PL', ['all_location' => 0, 'locations' => [$f['L1']]]);
        $f['T'] = q71_folder($t, 'T');
        $f['pName'] = q71_row($t, $f['P'])['name'];
        $f['qName'] = q71_row($t, $f['Q'])['name'];

        return $f;
    }

    function q71_s1_ids(array $f)
    {
        return [$f['P'], $f['Q'], $f['D'], $f['PL'], $f['T']];
    }

    /** Body PUT archives/{id} minimal valid untuk folder P. */
    function q71_s1_put(array $f, array $extra = [])
    {
        return $extra + ['name' => $f['pName'], 'is_all_location' => 1];
    }

    /** Folder uji pertama yang dimiliki user lain (target user-permissions): aktif, bukan QA_USER. */
    function q71_s1_target($t)
    {
        return $t->db()->table('users')->where('is_active', 1)->where('username', '<>', $t->conf('QA_USER'))
            ->orderBy('username')->value('id_user');
    }
}

return [

    [
        'id'    => 'AC-1',
        'title' => 'create-folder dan PUT archives/{id}: name/description S_BAD (omega, CJK, emoji, aksen gabung, RTL) = 422 errors.<field> tepat teks GE0748 (ID dan EN); data dan riwayat tetap, tidak ada folder baru',
        'run'   => function ($t) {
            q71_recover($t);
            $s = $t->session();
            q71_role_purge($t);
            q71_purge($t);
            $base = q71_base($t);
            $f = q71_soft($t);

            try {
                $x = q71_s1_fixture($t);
                $ids = q71_s1_ids($x);
                $before = q71_snap($t, $ids);
                $histBefore = q71_history($t, $x['P']);
                $t->true(count($histBefore) === 1, 'fixture: P punya satu entri riwayat');

                $run = function ($label, $method, $uri, $body, array $keys, $text = Q71_GE0748_ID) use ($t, $s, $f, $ids, &$before) {
                    $r = $f->call($s, $method, $uri, $body, $label);
                    $f->ge0748($r, $keys, $label, $text);
                    $f->eq(q71_snap($t, $ids), $before, "$label: tanpa tulis (semua kolom baris uji dan jumlah baris sama)");
                };

                foreach (q71_bad() as $lab => $ch) {
                    // create-folder
                    $run("create name $lab", 'POST', Q71_API . 'archives/create-folder', ['name' => q71_name('C') . $ch, 'is_all_location' => 1], ['name']);
                    $run("create name=saja $lab", 'POST', Q71_API . 'archives/create-folder', ['name' => $ch, 'is_all_location' => 1], ['name']);
                    $run("create description $lab", 'POST', Q71_API . 'archives/create-folder', ['name' => q71_name('C'), 'description' => 'x' . $ch, 'is_all_location' => 1], ['description']);
                    $run("create name+description $lab", 'POST', Q71_API . 'archives/create-folder', ['name' => q71_name('C') . $ch, 'description' => $ch, 'is_all_location' => 1], ['name', 'description']);
                    // PUT archives/{id}
                    $run("PUT name $lab", 'PUT', Q71_API . 'archives/' . $x['P'], q71_s1_put($x, ['name' => $x['pName'] . $ch]), ['name']);
                    $run("PUT description $lab", 'PUT', Q71_API . 'archives/' . $x['P'], q71_s1_put($x, ['description' => 'x' . $ch]), ['description']);
                    $run("PUT name+description $lab", 'PUT', Q71_API . 'archives/' . $x['P'], q71_s1_put($x, ['name' => $ch . $x['pName'], 'description' => $ch]), ['name', 'description']);
                }

                // riwayat dan baris P persis sama (tanpa entri baru)
                $f->eq(q71_history($t, $x['P']), $histBefore, 'riwayat P tidak bertambah');
                $f->eq(q71_row($t, $x['P'])['description'], 'desc-awal', 'description P tetap');
                $f->eq(q71_row($t, $x['P'])['name'], $x['pName'], 'name P tetap');
                $f->eq($t->db()->table('archives')->where('name', 'like', Q71_PREFIX . '%')->count(), 5, 'hanya lima baris fixture QA71- (tidak ada folder baru dari create)');

                // bahasa EN: teks GE0748 bahasa user (bukan id)
                q71_with_user($t, ['lang' => 'EN'], function () use ($t, $s, $f, $x, $ids, $run) {
                    $om = "\u{03A9}";
                    $run('EN create name', 'POST', Q71_API . 'archives/create-folder', ['name' => q71_name('C') . $om, 'is_all_location' => 1], ['name'], Q71_GE0748_EN);
                    $run('EN create description', 'POST', Q71_API . 'archives/create-folder', ['name' => q71_name('C'), 'description' => "\u{1F600}", 'is_all_location' => 1], ['description'], Q71_GE0748_EN);
                    $run('EN PUT name', 'PUT', Q71_API . 'archives/' . $x['P'], q71_s1_put($x, ['name' => $x['pName'] . $om]), ['name'], Q71_GE0748_EN);
                    $run('EN PUT description', 'PUT', Q71_API . 'archives/' . $x['P'], q71_s1_put($x, ['description' => "\u{4E2D}\u{6587}"]), ['description'], Q71_GE0748_EN);
                });

                $f->done();
            } finally {
                q71_role_purge($t);
                q71_purge($t);
            }
            q71_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-2',
        'title' => 'PUT archives/rename/{id}: name S_BAD = 422 errors.name (folder dan dokumen; dokumen 422, bukan 400 ARCHIVE419); nama dan riwayat tetap; kontrol rename dokumen valid = 400 ARCHIVE419',
        'run'   => function ($t) {
            q71_recover($t);
            $s = $t->session();
            q71_purge($t);
            $base = q71_base($t);
            $f = q71_soft($t);

            try {
                $x = q71_s1_fixture($t);
                $ids = q71_s1_ids($x);
                $before = q71_snap($t, $ids);
                $dName = q71_row($t, $x['D'])['name'];

                // kontrol (perilaku ED-1069 yang tetap): dokumen + nama valid = 400 ARCHIVE419; folder + nama kembar = 400 ARCHIVE405
                $f->resp($f->call($s, 'PUT', Q71_API . 'archives/rename/' . $x['D'], ['name' => q71_name('R')], 'kontrol rename dokumen valid'), 400, 'ARCHIVE419', 'kontrol: dokumen + nama valid = 400 ARCHIVE419');
                $f->resp($f->call($s, 'PUT', Q71_API . 'archives/rename/' . $x['P'], ['name' => $x['qName']], 'kontrol rename kembar'), 400, 'ARCHIVE405', 'kontrol: folder + nama kembar = 400 ARCHIVE405');
                $f->eq(q71_snap($t, $ids), $before, 'kontrol: tanpa tulis');

                foreach (q71_bad() as $lab => $ch) {
                    foreach (['folder' => $x['P'], 'dokumen' => $x['D']] as $kind => $id) {
                        foreach (['sufiks' => q71_name('R') . $ch, 'saja' => $ch, 'awalan' => $ch . q71_name('R')] as $form => $name) {
                            $label = "rename $kind $form $lab";
                            $r = $f->call($s, 'PUT', Q71_API . 'archives/rename/' . $id, ['name' => $name], $label);
                            $f->ge0748($r, ['name'], $label);
                            $f->eq(q71_snap($t, $ids), $before, "$label: tanpa tulis");
                        }
                    }
                }

                $f->eq(q71_row($t, $x['P'])['name'], $x['pName'], 'nama folder P tetap');
                $f->eq(q71_row($t, $x['D'])['name'], $dName, 'nama dokumen D tetap');
                $f->eq(count(q71_history($t, $x['P'])), 1, 'riwayat P tidak bertambah');

                // juga di bahasa EN
                q71_with_user($t, ['lang' => 'EN'], function () use ($t, $s, $f, $x) {
                    $r = $f->call($s, 'PUT', Q71_API . 'archives/rename/' . $x['P'], ['name' => "QA71-R\u{1F600}"], 'EN rename');
                    $f->ge0748($r, ['name'], 'EN rename', Q71_GE0748_EN);
                });

                $f->done();
            } finally {
                q71_purge($t);
            }
            q71_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-3',
        'title' => 'Field lain dengan S_BAD = 422 di kuncinya (id_archive_parent, id_locations(.n), folder_permissions.n.id_user, put-in id_archive_parent/id_archives.n/name, hand-over/receive name, add-document id_archive, user-permissions permissions.n.id_archive / folders.n.id_archive); nama kembar / ARCHIVE402 + S_BAD tetap 422; tanpa tulis',
        'run'   => function ($t) {
            q71_recover($t);
            $s = $t->session();
            q71_purge($t);
            $base = q71_base($t);
            $f = q71_soft($t);

            try {
                $x = q71_s1_fixture($t);
                $ids = q71_s1_ids($x);
                $me = q71_uid($t);
                $target = q71_s1_target($t);
                $t->true($target !== null, 'ada user target aktif selain QA_USER');
                $targetBefore = json_encode(q71_rows($t->db()->table('archive_permissions')->where('id_user', $target)->orderBy('id_archive_permission')->get()));
                $before = q71_snap($t, $ids);
                $selfRow = ['id_user' => $me, 'is_view' => 1, 'is_update' => 1, 'is_delete' => 1, 'is_store' => 1];

                $run = function ($label, $method, $uri, $body, array $keys) use ($t, $s, $f, $ids, &$before) {
                    $r = $f->call($s, $method, $uri, $body, $label);
                    $f->ge0748($r, $keys, $label);
                    $f->eq(q71_snap($t, $ids), $before, "$label: tanpa tulis");
                };

                // kontrol sebelum S_BAD: pelanggaran bisnis tanpa S_BAD tetap 400 (membuktikan S_BAD mengalahkan keduanya)
                $f->resp($f->call($s, 'POST', Q71_API . 'archives/create-folder', ['name' => $x['qName'], 'is_all_location' => 1], 'kontrol nama kembar'), 400, 'ARCHIVE405', 'kontrol: create nama kembar = 400 ARCHIVE405');
                $f->resp($f->call($s, 'POST', Q71_API . 'archives/create-folder', ['name' => q71_name('K'), 'is_all_location' => 1, 'id_archive_parent' => $x['PL']], 'kontrol lokasi'), 400, 'ARCHIVE402', 'kontrol: create semua lokasi di induk satu lokasi = 400 ARCHIVE402');
                $f->resp($f->call($s, 'PUT', Q71_API . 'archives/' . $x['P'], q71_s1_put($x, ['name' => $x['qName']]), 'kontrol PUT kembar'), 400, 'ARCHIVE405', 'kontrol: PUT nama kembar = 400 ARCHIVE405');
                $f->eq(q71_snap($t, $ids), $before, 'kontrol: tanpa tulis');

                foreach (q71_bad() as $lab => $ch) {
                    // id_archive_parent
                    $run("create id_archive_parent $lab", 'POST', Q71_API . 'archives/create-folder', ['name' => q71_name('C'), 'id_archive_parent' => $ch, 'is_all_location' => 1], ['id_archive_parent']);
                    $run("PUT id_archive_parent $lab", 'PUT', Q71_API . 'archives/' . $x['P'], q71_s1_put($x, ['id_archive_parent' => $ch]), ['id_archive_parent']);
                    // id_locations (+.*)
                    $run("create id_locations.1 $lab", 'POST', Q71_API . 'archives/create-folder', ['name' => q71_name('C'), 'is_all_location' => 0, 'id_locations' => [$x['L1'], $ch]], ['id_locations.1']);
                    $run("create id_locations.0 $lab", 'POST', Q71_API . 'archives/create-folder', ['name' => q71_name('C'), 'is_all_location' => 0, 'id_locations' => [$ch]], ['id_locations.0']);
                    $run("create id_locations teks $lab", 'POST', Q71_API . 'archives/create-folder', ['name' => q71_name('C'), 'is_all_location' => 0, 'id_locations' => $ch], ['id_locations']);
                    $run("PUT id_locations.1 $lab", 'PUT', Q71_API . 'archives/' . $x['P'], q71_s1_put($x, ['is_all_location' => 0, 'id_locations' => [$x['L1'], $ch]]), ['id_locations.1']);
                    $run("PUT id_locations.0 $lab", 'PUT', Q71_API . 'archives/' . $x['P'], q71_s1_put($x, ['is_all_location' => 0, 'id_locations' => [$ch]]), ['id_locations.0']);
                    // folder_permissions.*.id_user
                    $run("PUT folder_permissions.1.id_user $lab", 'PUT', Q71_API . 'archives/' . $x['P'],
                        q71_s1_put($x, ['folder_permissions' => [$selfRow, ['id_user' => $ch, 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0]]]), ['folder_permissions.1.id_user']);
                    // put-in
                    $run("put-in id_archive_parent $lab", 'POST', Q71_API . 'documents/put-in', ['id_archive_parent' => $ch, 'id_archives' => [$x['D']]], ['id_archive_parent']);
                    $run("put-in id_archives.0 $lab", 'POST', Q71_API . 'documents/put-in', ['id_archive_parent' => $x['T'], 'id_archives' => [$ch]], ['id_archives.0']);
                    $run("put-in id_archives.1 $lab", 'POST', Q71_API . 'documents/put-in', ['id_archive_parent' => $x['T'], 'id_archives' => [$x['D'], $ch]], ['id_archives.1']);
                    $run("put-in id_archives teks $lab", 'POST', Q71_API . 'documents/put-in', ['id_archive_parent' => $x['T'], 'id_archives' => $ch], ['id_archives']);
                    $run("put-in name $lab", 'POST', Q71_API . 'documents/put-in', ['id_archive_parent' => $x['T'], 'name' => $ch], ['name']);
                    // hand-over / receive
                    $run("hand-over name $lab", 'POST', Q71_API . 'documents/hand-over', ['name' => $ch, 'action' => 'give'], ['name']);
                    $run("receive name $lab", 'POST', Q71_API . 'documents/receive', ['name' => $ch, 'action' => 'receive'], ['name']);
                    // add-document
                    $run("add-document id_archive $lab", 'POST', Q71_API . 'archives/add-document', ['id_archive' => $ch], ['id_archive']);
                    // user-permissions: permissions.*.id_archive dan folders.*.id_archive
                    $perm = function ($id) {
                        return ['id_archive' => $id, 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0];
                    };
                    $flag = function ($id) {
                        return ['id_archive' => $id, 'is_folder_permission' => 1];
                    };
                    $run("user-permissions permissions.0.id_archive $lab", 'PUT', Q71_API . 'user-permissions/' . $target, ['permissions' => [$perm($ch)]], ['permissions.0.id_archive']);
                    $run("user-permissions permissions.1.id_archive $lab", 'PUT', Q71_API . 'user-permissions/' . $target, ['permissions' => [$perm($x['P']), $perm($ch)]], ['permissions.1.id_archive']);
                    $run("user-permissions folders.0.id_archive $lab", 'PUT', Q71_API . 'user-permissions/' . $target, ['permissions' => [], 'folders' => [$flag($ch)]], ['folders.0.id_archive']);
                    $run("user-permissions folders.1.id_archive $lab", 'PUT', Q71_API . 'user-permissions/' . $target, ['permissions' => [], 'folders' => [$flag($x['P']), $flag($ch)]], ['folders.1.id_archive']);
                    $run("user-permissions permissions.0 + folders.0 $lab", 'PUT', Q71_API . 'user-permissions/' . $target, ['permissions' => [$perm($ch)], 'folders' => [$flag($ch)]], ['permissions.0.id_archive', 'folders.0.id_archive']);
                    $run("user-permissions permissions P valid + folders.0 $lab", 'PUT', Q71_API . 'user-permissions/' . $target, ['permissions' => [$perm($x['P'])], 'folders' => [$flag($ch)]], ['folders.0.id_archive']);

                    // pelanggaran bisnis + S_BAD (nama kembar, ARCHIVE402) tetap 422 hanya di field S_BAD
                    $run("create nama kembar + description $lab", 'POST', Q71_API . 'archives/create-folder', ['name' => $x['qName'], 'description' => $ch, 'is_all_location' => 1], ['description']);
                    $run("create ARCHIVE402 + description $lab", 'POST', Q71_API . 'archives/create-folder', ['name' => q71_name('C'), 'description' => $ch, 'is_all_location' => 1, 'id_archive_parent' => $x['PL']], ['description']);
                    $run("create ARCHIVE402 + id_locations.1 $lab", 'POST', Q71_API . 'archives/create-folder', ['name' => q71_name('C'), 'is_all_location' => 0, 'id_locations' => [$x['L2'], $ch], 'id_archive_parent' => $x['PL']], ['id_locations.1']);
                    $run("PUT nama kembar + description $lab", 'PUT', Q71_API . 'archives/' . $x['P'], q71_s1_put($x, ['name' => $x['qName'], 'description' => $ch]), ['description']);
                }

                $f->eq(q71_snap($t, $ids), $before, 'akhir: semua baris uji dan jumlah tabel sama dengan awal');
                $f->eq(json_encode(q71_rows($t->db()->table('archive_permissions')->where('id_user', $target)->orderBy('id_archive_permission')->get())), $targetBefore, 'hak user target tidak berubah');

                $f->done();
            } finally {
                q71_purge($t);
            }
            q71_assert_clean($t, $base);
        },
    ],
];
