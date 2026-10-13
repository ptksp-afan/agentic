<?php
/**
 * ED-1071 AC-6 (403 mengalahkan 422 untuk S_BAD di body), AC-7 (S_OK diterima utuh, tersimpan cp1252, ditemukan search,
 * Cafe/Cafe bersaudara, CAFE vs Cafe kembar) dan AC-8 (regresi Opname/Verification yang sudah benar).
 * "Tanpa hak" = keadaan sementara: lokasi kerja employee QA_USER dibatasi, baris archive_permissions, role fixture QA71-ROLE-*
 * yang menggantikan role QA_USER sebentar; semua dipulihkan persis (jurnal) di finally. Role 3 / 5 baseline tidak diubah.
 */
require_once __DIR__ . '/qa_lib.php';

if (!function_exists('q71_s7_search')) {
    function q71_s7_search($t, $s, $f, array $search, $idArchive = null)
    {
        return $f->call($s, 'GET', Q71_API . 'archives?pagination=100&search=' . rawurlencode(json_encode($search)) . ($idArchive ? '&id_archive=' . $idArchive : ''), null, 'search ' . json_encode($search, JSON_UNESCAPED_UNICODE));
    }
}

return [

    [
        'id'    => 'AC-6',
        'title' => 'S_BAD di body tetap kalah dari 403: folder di luar scope ARCHIVE407, tanpa Update efektif ARCHIVE408 (dan Store ARCHIVE410), role tanpa Update Folder / Folder Permissions / Add Folder / Move Archive / Handover / Receive = 403 GE0114; tanpa tulis',
        'run'   => function ($t) {
            q71_recover($t);
            $s = $t->session();
            q71_role_purge($t);
            q71_purge($t);
            $base = q71_base($t);
            $f = q71_soft($t);
            $bad = q71_bad();

            try {
                $locs = array_values(q71_locs($t));
                $t->true(count($locs) >= 2, 'ada minimal dua lokasi uji');
                [$L1, $L2] = [$locs[0], $locs[1]];
                $me = q71_uid($t);
                $target = $t->db()->table('users')->where('is_active', 1)->where('username', '<>', $t->conf('QA_USER'))->orderBy('username')->value('id_user');

                $FO = q71_folder($t, 'FO', ['all_location' => 0, 'locations' => [$L2]]);      // di luar scope (user dibatasi ke L1)
                $FIN = q71_folder($t, 'FIN', ['all_location' => 0, 'locations' => [$L1]]);    // di dalam scope
                $FU = q71_folder($t, 'FU', ['perm' => 1]);                                      // Update tidak efektif
                q71_perm($t, $FU, $me, 1, 0, 0, 1);
                $FS = q71_folder($t, 'FS', ['perm' => 1]);                                      // Store tidak efektif
                q71_perm($t, $FS, $me, 1, 1, 1, 0);
                $P = q71_folder($t, 'P');
                $T = q71_folder($t, 'T');
                $D = q71_doc($t, 'D', ['parent' => $P]);
                $ids = [$FO, $FIN, $FU, $FS, $P, $T, $D];
                $before = q71_snap($t, $ids);
                $pName = q71_row($t, $P)['name'];

                $chk = function ($label, $method, $uri, $body, $status, $code) use ($t, $s, $f, $ids, &$before) {
                    $r = $f->call($s, $method, Q71_API . $uri, $body, $label);
                    $f->resp($r, $status, $code, $label);
                    $f->eq(q71_snap($t, $ids), $before, "$label: tanpa tulis");

                    return $r;
                };
                $perm = function ($id) {
                    return ['id_archive' => $id, 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0];
                };
                $flag = function ($id) {
                    return ['id_archive' => $id, 'is_folder_permission' => 1];
                };

                // ---- 1. folder di luar scope lokasi = 403 ARCHIVE407 (S_BAD di body tidak diperiksa dulu)
                q71_with_user($t, ['emp' => [$L1]], function () use ($t, $s, $f, $chk, $bad, $L1, $FO, $FIN, $P, $D, $target, $perm, $flag, $pName) {
                    // kontrol: tanpa S_BAD, folder di luar scope memang 403 ARCHIVE407, folder dalam scope 200
                    $chk('kontrol scope: show FO', 'GET', 'archives/' . $FO, null, 403, 'ARCHIVE407');
                    $f->resp($f->call($s, 'GET', Q71_API . 'archives/' . $FIN, null, 'kontrol scope: show FIN'), 200, null, 'kontrol scope: show FIN dalam scope = 200');
                    foreach ($bad as $lab => $ch) {
                        $chk("scope PUT FO name $lab", 'PUT', 'archives/' . $FO, ['name' => $pName . $ch, 'is_all_location' => 1], 403, 'ARCHIVE407');
                        $chk("scope PUT FO description $lab", 'PUT', 'archives/' . $FO, ['name' => 'x', 'description' => $ch, 'is_all_location' => 1], 403, 'ARCHIVE407');
                        $chk("scope PUT FIN induk=FO + name $lab", 'PUT', 'archives/' . $FIN, ['name' => 'x' . $ch, 'is_all_location' => 0, 'id_locations' => [$L1], 'id_archive_parent' => $FO], 403, 'ARCHIVE407');
                        $chk("scope rename FO $lab", 'PUT', 'archives/rename/' . $FO, ['name' => 'x' . $ch], 403, 'ARCHIVE407');
                        $chk("scope create induk=FO + name $lab", 'POST', 'archives/create-folder', ['name' => 'x' . $ch, 'is_all_location' => 1, 'id_archive_parent' => $FO], 403, 'ARCHIVE407');
                        $chk("scope put-in induk=FO + name $lab", 'POST', 'documents/put-in', ['id_archive_parent' => $FO, 'name' => $ch], 403, 'ARCHIVE407');
                        $chk("scope put-in id_archives [FO, S_BAD] $lab", 'POST', 'documents/put-in', ['id_archive_parent' => $FIN, 'id_archives' => [$FO, $ch]], 403, 'ARCHIVE407');
                        $chk("scope user-permissions permissions [FO, S_BAD] $lab", 'PUT', 'user-permissions/' . $target, ['permissions' => [$perm($FO), $perm($ch)]], 403, 'ARCHIVE407');
                        $chk("scope user-permissions folders [FO, S_BAD] $lab", 'PUT', 'user-permissions/' . $target, ['permissions' => [], 'folders' => [$flag($FO), $flag($ch)]], 403, 'ARCHIVE407');
                    }
                });

                // ---- 2. tanpa hak efektif di folder: 403 ARCHIVE408 (Update) / ARCHIVE410 (Store)
                foreach ($bad as $lab => $ch) {
                    $chk("hak PUT FU name $lab", 'PUT', 'archives/' . $FU, ['name' => 'x' . $ch, 'is_all_location' => 1], 403, 'ARCHIVE408');
                    $chk("hak PUT FU description $lab", 'PUT', 'archives/' . $FU, ['name' => 'x', 'description' => $ch, 'is_all_location' => 1], 403, 'ARCHIVE408');
                    $chk("hak rename FU $lab", 'PUT', 'archives/rename/' . $FU, ['name' => 'x' . $ch], 403, 'ARCHIVE408');
                    $chk("hak put-in id_archives [FU, S_BAD] $lab", 'POST', 'documents/put-in', ['id_archive_parent' => $T, 'id_archives' => [$FU, $ch]], 403, 'ARCHIVE408');
                    $chk("hak create induk=FS + name $lab", 'POST', 'archives/create-folder', ['name' => 'x' . $ch, 'is_all_location' => 1, 'id_archive_parent' => $FS], 403, 'ARCHIVE410');
                    $chk("hak put-in induk=FS + name $lab", 'POST', 'documents/put-in', ['id_archive_parent' => $FS, 'name' => $ch], 403, 'ARCHIVE410');
                    $chk("hak put-in induk=FS + id_archives.1 $lab", 'POST', 'documents/put-in', ['id_archive_parent' => $FS, 'id_archives' => [$D, $ch]], 403, 'ARCHIVE410');
                    $chk("hak PUT P induk=FS + name $lab", 'PUT', 'archives/' . $P, ['name' => 'x' . $ch, 'is_all_location' => 1, 'id_archive_parent' => $FS], 403, 'ARCHIVE410');
                }

                // ---- 3. role tanpa permission: 403 GE0114 (middleware / authorize), bukan 422 dan bukan 404
                $roles = [
                    'tanpa Update Folder'   => q71_role($t, 'noUpdate', q71_perms_all(['Update Folder'])),
                    'tanpa Folder Permissions' => q71_role($t, 'noFP', q71_perms_all(['Folder Permissions'])),
                    'tanpa Add Folder'      => q71_role($t, 'noAdd', q71_perms_all(['Add Folder'])),
                    'tanpa Move Archive/Add Document' => q71_role($t, 'noMove', q71_perms_all(['Move Archive', 'Add Document'])),
                    'tanpa Handover Document' => q71_role($t, 'noHand', q71_perms_all(['Handover Document'])),
                    'tanpa Receive Document'  => q71_role($t, 'noRecv', q71_perms_all(['Receive Document'])),
                    'tanpa List Archive'    => q71_role($t, 'noList', q71_perms_all(['List Archive'])),
                ];
                $permBodies = ['id_user' => $target, 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0];
                foreach ($roles as $desc => $roleId) {
                    q71_with_user($t, ['role' => [$roleId]], function () use ($desc, $bad, $chk, $P, $T, $D, $target, $perm, $flag, $pName, $permBodies) {
                        foreach ($bad as $lab => $ch) {
                            switch ($desc) {
                                case 'tanpa Update Folder':
                                    $chk("role $desc: PUT name $lab", 'PUT', 'archives/' . $P, ['name' => $pName . $ch, 'is_all_location' => 1], 403, 'GE0114');
                                    $chk("role $desc: PUT description $lab", 'PUT', 'archives/' . $P, ['name' => $pName, 'description' => $ch, 'is_all_location' => 1], 403, 'GE0114');
                                    $chk("role $desc: rename $lab", 'PUT', 'archives/rename/' . $P, ['name' => 'x' . $ch], 403, 'GE0114');
                                    break;
                                case 'tanpa Folder Permissions':
                                    $chk("role $desc: user-permissions permissions $lab", 'PUT', 'user-permissions/' . $target, ['permissions' => [$perm($ch)]], 403, 'GE0114');
                                    $chk("role $desc: user-permissions folders $lab", 'PUT', 'user-permissions/' . $target, ['permissions' => [], 'folders' => [$flag($ch)]], 403, 'GE0114');
                                    $chk("role $desc: user-permissions GET $lab", 'GET', 'user-permissions/' . rawurlencode($ch), null, 403, 'GE0114');
                                    $chk("role $desc: PUT folder_permissions.0.id_user $lab", 'PUT', 'archives/' . $P, ['name' => $pName, 'is_all_location' => 1, 'folder_permissions' => [['id_user' => $ch, 'is_view' => 1, 'is_update' => 0, 'is_delete' => 0, 'is_store' => 0]]], 403, 'GE0114');
                                    break;
                                case 'tanpa Add Folder':
                                    $chk("role $desc: create name $lab", 'POST', 'archives/create-folder', ['name' => 'x' . $ch, 'is_all_location' => 1], 403, 'GE0114');
                                    break;
                                case 'tanpa Move Archive/Add Document':
                                    $chk("role $desc: put-in name $lab", 'POST', 'documents/put-in', ['id_archive_parent' => $T, 'name' => $ch], 403, 'GE0114');
                                    $chk("role $desc: put-in id_archives $lab", 'POST', 'documents/put-in', ['id_archive_parent' => $T, 'id_archives' => [$ch]], 403, 'GE0114');
                                    break;
                                case 'tanpa Handover Document':
                                    $chk("role $desc: hand-over $lab", 'POST', 'documents/hand-over', ['name' => $ch, 'action' => 'give'], 403, 'GE0114');
                                    break;
                                case 'tanpa Receive Document':
                                    $chk("role $desc: receive $lab", 'POST', 'documents/receive', ['name' => $ch, 'action' => 'receive'], 403, 'GE0114');
                                    break;
                                case 'tanpa List Archive':
                                    $chk("role $desc: list id_archive $lab", 'GET', 'archives?id_archive=' . rawurlencode($ch), null, 403, 'GE0114');
                                    $chk("role $desc: show $lab", 'GET', 'archives/' . rawurlencode($ch), null, 403, 'GE0114');
                                    $chk("role $desc: history $lab", 'GET', 'archives/history/' . rawurlencode($ch), null, 403, 'GE0114');
                                    break;
                            }
                        }
                    });
                }
                q71_role_purge($t);

                $f->eq(q71_snap($t, $ids), $before, 'akhir: baris uji dan jumlah tabel sama');
                $f->done();
            } finally {
                q71_role_purge($t);
                q71_purge($t);
                q71_recover($t);
            }
            q71_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-7',
        'title' => 'S_OK (ü ’ — € ™): create / PUT / rename = 200, HEX(name/description) cp1252 (€=80, ’=92), terbaca utuh di API, search {"query":"€"} menemukannya, put-in/hand-over/receive by name; Cafe + Cafe bersaudara boleh; CAFE (É) vs Cafe (é) = 400 ARCHIVE405',
        'run'   => function ($t) {
            q71_recover($t);
            $s = $t->session();
            q71_purge($t);
            $base = q71_base($t);
            $f = q71_soft($t);

            try {
                $uniq = substr(uniqid(), -6);
                $created = [];
                $pre = Q71_PREFIX . 'OK-' . $uniq . '-';

                // --- create / PUT / rename per karakter S_OK
                foreach (q71_ok() as $lab => [$ch, $hex]) {
                    $name = $pre . $lab . $ch . 'x';
                    $descr = 'd' . $ch . $ch;
                    $r = $f->call($s, 'POST', Q71_API . 'archives/create-folder', ['name' => $name, 'description' => $descr, 'is_all_location' => 1], "create $lab");
                    $f->resp($r, 200, 'ARCHIVE201', "create $lab");
                    $id = $r[1]['result']['id_archive'] ?? null;
                    $f->ok($id !== null, "create $lab: id_archive dikembalikan");
                    if ($id === null) {
                        continue;
                    }
                    $created[$lab] = $id;

                    $f->eq(q71_hex($t, $id, 'name'), q71_hex_cp1252($name), "create $lab: HEX(name) cp1252");
                    $f->ok(strpos(q71_hex($t, $id, 'name'), $hex) !== false, "create $lab: HEX(name) memuat byte $hex");
                    $f->eq(q71_hex($t, $id, 'description'), q71_hex_cp1252($descr), "create $lab: HEX(description) cp1252");
                    $f->eq(count(q71_history($t, $id)), 1, "create $lab: satu entri riwayat");

                    $show = $f->call($s, 'GET', Q71_API . 'archives/' . $id, null, "show $lab");
                    $f->eq($show[1]['result']['name'] ?? null, $name, "show $lab: name terbaca utuh");
                    $f->eq($show[1]['result']['description'] ?? null, $descr, "show $lab: description terbaca utuh");

                    // PUT
                    $name2 = $pre . $lab . $ch . 'p';
                    $descr2 = 'e' . $ch;
                    $r = $f->call($s, 'PUT', Q71_API . 'archives/' . $id, ['name' => $name2, 'description' => $descr2, 'is_all_location' => 1], "PUT $lab");
                    $f->resp($r, 200, 'ARCHIVE207', "PUT $lab");
                    $f->eq(q71_hex($t, $id, 'name'), q71_hex_cp1252($name2), "PUT $lab: HEX(name) cp1252");
                    $f->eq(q71_hex($t, $id, 'description'), q71_hex_cp1252($descr2), "PUT $lab: HEX(description) cp1252");
                    $f->ok(count(q71_history($t, $id)) >= 2, "PUT $lab: riwayat bertambah");

                    // rename
                    $name3 = $pre . $lab . $ch . 'r';
                    $r = $f->call($s, 'PUT', Q71_API . 'archives/rename/' . $id, ['name' => $name3], "rename $lab");
                    $f->resp($r, 200, 'ARCHIVE206', "rename $lab");
                    $f->eq(q71_hex($t, $id, 'name'), q71_hex_cp1252($name3), "rename $lab: HEX(name) cp1252");
                    $f->eq(q71_hex($t, $id, 'description'), q71_hex_cp1252($descr2), "rename $lab: description tidak berubah");

                    // search menemukannya: token unik + karakter
                    $q = $pre . $lab . $ch;
                    $r = q71_s7_search($t, $s, $f, ['query' => $q]);
                    $f->ok(in_array($id, q71_list_ids($r), true), "search query '$lab' (token unik + karakter) menemukan folder");
                }

                // --- semua lima karakter dalam satu nama
                $all = '';
                foreach (q71_ok() as [$ch]) {
                    $all .= $ch;
                }
                $nameAll = $pre . 'semua-' . $all;
                $r = $f->call($s, 'POST', Q71_API . 'archives/create-folder', ['name' => $nameAll, 'is_all_location' => 1], 'create semua S_OK');
                $f->resp($r, 200, 'ARCHIVE201', 'create semua S_OK');
                $idAll = $r[1]['result']['id_archive'] ?? null;
                if ($idAll !== null) {
                    $f->eq(q71_hex($t, $idAll, 'name'), q71_hex_cp1252($nameAll), 'create semua S_OK: HEX(name) = hex cp1252 (FC 92 97 80 99)');
                    $f->ok(strpos(q71_hex($t, $idAll, 'name'), 'FC92978099') !== false, 'create semua S_OK: berurutan FC 92 97 80 99');
                    $r = q71_s7_search($t, $s, $f, ['query' => $all]);
                    $f->ok(in_array($idAll, q71_list_ids($r), true), 'search lima karakter S_OK sekaligus menemukan folder');
                }

                // --- search {"query":"€"}: menemukan (spec AC-7) -- folder dari loop euro
                if (isset($created['euro'])) {
                    $r = q71_s7_search($t, $s, $f, ['query' => "\u{20AC}"]);
                    $f->ok(in_array($created['euro'], q71_list_ids($r), true), 'search {"query":"€"} menemukan folder berisi €');
                    $f->ok(($r[1]['result']['total'] ?? 0) >= 1, 'search {"query":"€"}: total >= 1');
                }

                // --- Cafe / Cafe: bersaudara boleh; CAFE (É) vs Cafe (é) kembar
                $cafe = "Caf\u{00E9}";
                $cafeUp = "CAF\u{00C9}";
                $r1 = $f->call($s, 'POST', Q71_API . 'archives/create-folder', ['name' => Q71_PREFIX . $uniq . '-' . $cafe, 'is_all_location' => 1], 'create Café');
                $f->resp($r1, 200, 'ARCHIVE201', 'create Café');
                $r2 = $f->call($s, 'POST', Q71_API . 'archives/create-folder', ['name' => Q71_PREFIX . $uniq . '-Cafe', 'is_all_location' => 1], 'create Cafe (bersaudara dengan Café)');
                $f->resp($r2, 200, 'ARCHIVE201', 'create Cafe bersaudara dengan Café');
                $r3 = $f->call($s, 'POST', Q71_API . 'archives/create-folder', ['name' => Q71_PREFIX . $uniq . '-' . $cafeUp, 'is_all_location' => 1], 'create CAFÉ (kembar Café)');
                $f->resp($r3, 400, 'ARCHIVE405', 'create CAFÉ kembar dengan Café = 400 ARCHIVE405');
                $idCafe = $r1[1]['result']['id_archive'] ?? null;
                $idCafeN = $r2[1]['result']['id_archive'] ?? null;
                if ($idCafe && $idCafeN) {
                    $r = $f->call($s, 'PUT', Q71_API . 'archives/rename/' . $idCafeN, ['name' => Q71_PREFIX . $uniq . '-' . $cafeUp], 'rename Cafe -> CAFÉ');
                    $f->resp($r, 400, 'ARCHIVE405', 'rename Cafe -> CAFÉ (kembar Café) = 400 ARCHIVE405');
                    $r = $f->call($s, 'PUT', Q71_API . 'archives/' . $idCafeN, ['name' => Q71_PREFIX . $uniq . '-' . $cafeUp, 'is_all_location' => 1], 'PUT Cafe -> CAFÉ');
                    $f->resp($r, 400, 'ARCHIVE405', 'PUT Cafe -> CAFÉ (kembar Café) = 400 ARCHIVE405');
                    $f->eq(q71_row($t, $idCafeN)['name'], Q71_PREFIX . $uniq . '-Cafe', 'nama Cafe tidak berubah sesudah penolakan');
                    $f->eq(q71_hex($t, $idCafe, 'name'), q71_hex_cp1252(Q71_PREFIX . $uniq . '-' . $cafe), 'HEX(name) Café = cp1252 (E9)');
                }

                // --- dokumen ber-nama S_OK: put-in / hand-over / receive by name
                $T = q71_folder($t, 'T');
                $dname = Q71_PREFIX . 'D' . $uniq . "\u{00FC}\u{20AC}";
                $D = q71_doc($t, 'D', ['name' => $dname]);
                $r = $f->call($s, 'POST', Q71_API . 'documents/put-in', ['id_archive_parent' => $T, 'name' => $dname], 'put-in by name S_OK');
                $f->resp($r, 200, null, 'put-in by name S_OK');
                $f->eq(q71_row($t, $D)['id_archive_parent'], $T, 'put-in by name S_OK: dokumen pindah ke T');
                $r = $f->call($s, 'POST', Q71_API . 'documents/hand-over', ['name' => $dname, 'action' => 'give'], 'hand-over by name S_OK');
                $f->resp($r, 200, null, 'hand-over by name S_OK');
                $r = $f->call($s, 'POST', Q71_API . 'documents/receive', ['name' => $dname, 'action' => 'receive'], 'receive by name S_OK');
                $f->resp($r, 200, null, 'receive by name S_OK');
                $r = $f->call($s, 'POST', Q71_API . 'documents/hand-over', ['name' => Q71_PREFIX . 'tidak-ada-' . $uniq . "\u{20AC}", 'action' => 'give'], 'hand-over nama S_OK tak ada');
                $f->resp($r, 404, 'ARCHIVE400', 'hand-over nama S_OK yang tidak ada = 404 ARCHIVE400 (bukan 500)');

                $f->done();
            } finally {
                q71_purge($t);
            }
            q71_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-8',
        'title' => 'Regresi (sudah benar, tak berubah): opnames?search S_BAD 200 kosong; opnames/{S_BAD} 404 ARCHIVE412; verifications/{S_BAD} dan opnames/folders?id_archive= 404 ARCHIVE400; select folders / opname-users search S_BAD = []; scan code S_BAD 422',
        'run'   => function ($t) {
            $s = $t->session();
            $f = q71_soft($t);
            $opnamesBefore = $t->db()->table('archive_opnames')->count();

            $forms = [];
            foreach (q71_bad() as $lab => $ch) {
                $forms[$lab] = rawurlencode($ch);
            }
            $forms['rusak'] = 'x%C3%28';

            foreach ($forms as $lab => $enc) {
                $broken = $lab === 'rusak';
                if (!$broken) {
                    $ch = q71_bad()[$lab];
                    $r = $f->call($s, 'GET', Q71_API . 'opnames?search=' . rawurlencode(json_encode(['query' => $ch])), null, "opnames search [$lab]");
                    $f->resp($r, 200, null, "opnames search [$lab]");
                    $f->eq($r[1]['result']['data'] ?? null, [], "opnames search [$lab]: data kosong");
                    $r = $f->call($s, 'POST', Q71_API . 'opnames/scan', ['code' => $ch], "scan code [$lab]");
                    $f->ge0748($r, [], "scan code [$lab]: 422");
                    $f->ok(isset($r[1]['errors']['code']), "scan code [$lab]: errors.code");
                }
                $r = $f->call($s, 'GET', Q71_API . 'opnames/' . $enc, null, "opnames/id [$lab]");
                $broken ? $f->eq($r[0], 404, "opnames/id [$lab]: 404 router") : $f->resp($r, 404, 'ARCHIVE412', "opnames/id [$lab]");
                $r = $f->call($s, 'GET', Q71_API . 'verifications/' . $enc, null, "verifications/id [$lab]");
                $broken ? $f->eq($r[0], 404, "verifications/id [$lab]: 404 router") : $f->resp($r, 404, 'ARCHIVE400', "verifications/id [$lab]");
                $r = $f->call($s, 'GET', Q71_API . 'opnames/folders?id_archive=' . $enc, null, "opnames/folders [$lab]");
                $f->resp($r, 404, 'ARCHIVE400', "opnames/folders?id_archive [$lab]");
                foreach (['folders', 'opname-users'] as $sel) {
                    $r = $f->call($s, 'GET', Q71_SEL . $sel . '?search=' . $enc, null, "select $sel [$lab]");
                    $f->resp($r, 200, null, "select $sel [$lab]");
                    $f->eq($r[1]['result']['options'] ?? null, [], "select $sel search [$lab]: options []");
                }
            }

            $f->eq($t->db()->table('archive_opnames')->count(), $opnamesBefore, 'tidak ada sesi opname baru');
            $f->done();
        },
    ],
];
