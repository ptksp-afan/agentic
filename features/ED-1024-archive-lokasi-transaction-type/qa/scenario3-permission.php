<?php
/**
 * ED-1024 - AC-12 (permission per route), AC-13 (bagian BE/http: tanpa permission modul Archive -> 403 GE0114,
 * bukan 500; pengecekan kode seed + guard FE). Tukar lisensi sungguhan = MANUAL di Gate 2 epic (PROFILES kosong).
 */
require_once __DIR__ . '/qa_lib.php';

/** Tabel route Archive non-select: [method, uri, body|null, permission yang diharapkan di `parameter`] */
$archiveRoutes = function ($id, $folder, $docName) {
    $base = 'api/v5/document-archive';

    return [
        'GET archives'         => ['GET', "$base/archives", null, 'List Archive'],
        'GET archives/{id}'    => ['GET', "$base/archives/$id", null, 'List Archive'],
        'GET history/{id}'     => ['GET', "$base/archives/history/$id", null, 'List Archive'],
        'POST create-folder'   => ['POST', "$base/archives/create-folder", ['name' => 'QA01-PERM', 'is_all_location' => 1], 'Add Folder'],
        'PUT archives/{id}'    => ['PUT', "$base/archives/$id", ['name' => 'QA01-PERM', 'is_all_location' => 1], 'Update Folder'],
        'PUT rename/{id}'      => ['PUT', "$base/archives/rename/$id", ['name' => 'QA01-PERM'], 'Update Folder'],
        'DELETE delete/{id}'   => ['DELETE', "$base/archives/delete/$id", null, 'Delete Folder'],
        'POST put-in'          => ['POST', "$base/documents/put-in", ['id_archive_parent' => $folder, 'name' => $docName], 'Move Archive, Add Document'],
        'POST hand-over'       => ['POST', "$base/documents/hand-over", ['name' => $docName], 'Handover Document'],
        'POST receive'         => ['POST', "$base/documents/receive", ['name' => $docName], 'Receive Document'],
    ];
};

return [

    [
        'id'    => 'AC-12',
        'title' => 'Permission per route: role 6 semua 403 GE0114; role 29 (List) hanya GET 200; role 16 (Move) put-in lolos guard; role 18 (Add Document) put-in lolos; tanpa token 401',
        'run'   => function ($t) use ($archiveRoutes) {
            $s = $t->session();
            q1_purge($t);

            $real = q1_id_by_name($t, 'Backup Arsip', null);
            $qaFolder = q1_add($t, ['name' => 'QA01-PERMFOLDER', 'type' => 1, 'all' => 1]);
            $qaDoc = q1_add($t, ['name' => 'QA01-PERMDOC', 'type' => 2, 'all' => 1, 'doc' => ['type' => 6]]);
            $routes = $archiveRoutes($real, $qaFolder, 'QA01-PERMDOC');
            $before = q1_snap($t, [$qaFolder, $qaDoc]);
            $totalRows = $t->db()->table('archives')->count();

            $call = function ($r) use ($t, $s) {
                return $t->call($s, $r[0], $r[1], $r[2]);
            };
            $forbid = function ($r, $label, $permission) use ($t) {
                $t->status($r, 403, $label);
                $t->code($r, 'GE0114', $label);
                $t->eq($r[1]['parameter'] ?? null, $permission, $label . ': parameter');
            };

            try {
                // --- tanpa token
                $r = $t->raw('GET', 'api/v5/document-archive/archives');
                $t->status($r, 401, 'tanpa token');
                $t->code($r, 'GE0111', 'tanpa token');

                // --- role 6 (Sales): tidak punya permission Archive apa pun
                q1_with_user($t, ['role' => 6], function ($set) use ($t, $routes, $call, $forbid) {
                    foreach ($routes as $label => $r) {
                        $forbid($call($r), "role 6: $label", $r[3]);
                    }
                });

                // --- role 29: hanya List Archive
                q1_with_user($t, ['role' => 29], function ($set) use ($t, $routes, $call, $forbid) {
                    foreach (['GET archives', 'GET archives/{id}', 'GET history/{id}'] as $label) {
                        $r = $call($routes[$label]);
                        $t->status($r, 200, "role 29: $label");
                    }
                    foreach ($routes as $label => $r) {
                        if (strpos($label, 'GET') === 0) {
                            continue;
                        }
                        $forbid($call($r), "role 29: $label", $r[3]);
                    }
                });
                $t->true(q1_same($before, q1_snap($t, [$qaFolder, $qaDoc])), 'DB: baris uji tidak berubah oleh penolakan role 6/29');
                $t->eq($t->db()->table('archives')->count(), $totalRows, 'DB: tidak ada baris baru dari penolakan role 6/29');

                // --- role 16: List + Move + Handover + Receive
                q1_with_user($t, ['role' => 16], function ($set) use ($t, $routes, $call, $forbid, $qaFolder) {
                    $t->status($call($routes['GET archives']), 200, 'role 16: GET archives');
                    $r = $call($routes['POST put-in']);
                    $t->status($r, 200, 'role 16 (Move Archive): put-in lolos guard');
                    $t->code($r, 'ARCHIVE204', 'role 16: put-in');
                    $r = $call($routes['POST hand-over']);
                    $t->status($r, 200, 'role 16: hand-over (Handover Document)');
                    $t->code($r, 'ARCHIVE209', 'role 16: hand-over');
                    $r = $call($routes['POST receive']);
                    $t->status($r, 200, 'role 16: receive (Receive Document)');
                    $t->code($r, 'ARCHIVE210', 'role 16: receive');
                    foreach (['POST create-folder', 'PUT archives/{id}', 'PUT rename/{id}', 'DELETE delete/{id}'] as $label) {
                        $forbid($call($routes[$label]), "role 16: $label", $routes[$label][3]);
                    }
                });

                // --- role 18: List + Handover + Receive + Add Document (tanpa Move Archive)
                q1_with_user($t, ['role' => 18], function ($set) use ($t, $routes, $call, $forbid, $qaDoc) {
                    $r = $call($routes['POST put-in']);
                    $t->status($r, 200, 'role 18 (Add Document saja): put-in lolos guard (salah satu permission cukup)');
                    $forbid($call($routes['POST create-folder']), 'role 18: create-folder', 'Add Folder');
                });

                // --- superadmin 1: semua lolos guard
                q1_with_user($t, ['role' => 1], function ($set) use ($t, $routes, $call) {
                    $t->status($call($routes['GET archives']), 200, 'role 1: GET archives');
                    $t->status($call($routes['GET history/{id}']), 200, 'role 1: history');
                });
            } finally {
                q1_purge($t);
            }
            q1_assert_clean($t, 'data uji AC-12 sudah dibuang');
        },
    ],

    [
        'id'    => 'AC-13',
        'title' => '(bagian BE) tanpa permission modul Archive: 403 GE0114 di semua route, bukan 500, tanpa data; seed hanya di permission_salesman.sql; guard FE ada di kode. Tukar lisensi = MANUAL Gate 2',
        'run'   => function ($t) use ($archiveRoutes) {
            $s = $t->session();
            q1_purge($t);
            $real = q1_id_by_name($t, 'Backup Arsip', null);
            $routes = $archiveRoutes($real, $real, 'QA01-NOPE');
            $totalRows = $t->db()->table('archives')->count();

            // 1. cara seragam EPIC K-2 b: role tanpa List Archive -> 403 GE0114 + parameter
            q1_with_user($t, ['role' => 6], function ($set) use ($t, $s) {
                $r = q1_list($t, $s);
                $t->status($r, 403, 'role tanpa List Archive');
                $t->code($r, 'GE0114', 'role tanpa List Archive');
                $t->eq($r[1]['parameter'] ?? null, 'List Archive', 'parameter');
                $t->true(!array_key_exists('result', $r[1]), 'tanpa data (result) pada penolakan');
            });

            // 2. simulasi tenant tanpa seed Archive: permissions 1081-1090 tidak ada -> bahkan superadmin/Super Admin 403
            q1_without_archive_permissions($t, function () use ($t, $s, $routes) {
                foreach ([3, 1] as $role) {
                    q1_with_user($t, ['role' => $role], function ($set) use ($t, $s, $routes, $role) {
                        foreach ($routes as $label => $rt) {
                            $r = $t->call($s, $rt[0], $rt[1], $rt[2]);
                            q1_no500($t, $r, "tanpa seed, role $role, $label");
                            $t->status($r, 403, "tanpa seed, role $role, $label");
                            $t->code($r, 'GE0114', "tanpa seed, role $role, $label");
                            $t->true(!array_key_exists('result', $r[1]), "tanpa seed, role $role, $label: tanpa data");
                        }
                    });
                }
            });
            $r = q1_list($t, $s, ['pagination' => 5]);
            $t->status($r, 200, 'sesudah permission dipulihkan: GET archives 200 lagi');
            $t->eq($t->db()->table('archives')->count(), $totalRows, 'DB: jumlah baris archives tidak berubah');

            // 3. bukti kode (statis): seed permission Archive hanya dimuat untuk SALESMAN_ACTIVITY
            $be = rtrim($t->conf('BE_DIR'), '/');
            $setup = file_get_contents($be . '/Modules/V5/Http/Services/Application/Setup/ApplicationSetupService.php');
            $t->true(strpos($setup, "'SALESMAN_ACTIVITY' => 'permission_salesman.sql'") !== false, 'ApplicationSetupService memuat permission_salesman.sql hanya untuk fitur SALESMAN_ACTIVITY');
            $sql = file_get_contents($be . '/app/Sql/data/permission_salesman.sql');
            $t->true(strpos($sql, "'List Archive'") !== false && strpos($sql, "'Receive Document'") !== false, 'permission_salesman.sql memuat permission Archive');
            $others = glob($be . '/app/Sql/data/*.sql');
            $holders = [];
            foreach ($others as $file) {
                if (strpos(file_get_contents($file), "'List Archive'") !== false) {
                    $holders[] = basename($file);
                }
            }
            $t->eq($holders, ['permission_salesman.sql'], 'permission Archive hanya ada di permission_salesman.sql');

            // 4. guard FE menu/route (statis, hanya dibaca)
            $fe = rtrim($t->conf('FE_DIR'), '/');
            $menus = file_get_contents($fe . '/src/configuration/menus.js');
            $t->true((bool) preg_match('/key:\s*paths\.archive,.*?permission:\s*hasSalesmanFeature\s*\?\s*permissions\.ListArchive\s*:\s*shouldNotAppear/s', $menus), 'FE menus.js: menu Archive dijaga hasSalesmanFeature + ListArchive');
            $routesJs = file_get_contents($fe . '/src/routes/routes.js');
            $t->true((bool) preg_match('/path:\s*paths\.archive,\s*permission:\s*permissions\.ListArchive,.*?<PrivateRoute permission=\{permissions\.ListArchive\}>/s', $routesJs), 'FE routes.js: route /archive dijaga PrivateRoute ListArchive');

            $t->note('Bagian [FE] AC-13 dan tukar lisensi sungguhan tanpa SALESMAN_ACTIVITY: MANUAL di Gate 2 epic (PROFILES kosong).');
        },
    ],

];
