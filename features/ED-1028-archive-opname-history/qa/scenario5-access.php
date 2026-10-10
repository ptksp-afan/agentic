<?php
/**
 * ED-1028 - akses baca & permission: AC-12 (rev, K-8 a: user2 BER-Opname Document membaca opnames/{id} & /documents sesi terlihat = 200;
 * 404 ARCHIVE412 untuk sesi tak terlihat / tak ada / id bekas draft / sisa baris status 1 dan 3; alur opname ED-1026 jalan), AC-14 (rev:
 * R0 = role asli user2 tanpa Opname Document dan role 22 tanpa permission Archive: 403 GE0114 parameter "Opname Document" tanpa data;
 * select opname-users options []; select folders tetap scope), X-1 (display setting lewat API: kolom aktif = kunci baris, sort/filter
 * kolom tersembunyi tetap jalan), X-2 (banyak sesi: waktu respons + keluaran), X-3 (isolasi: gantian user tanpa bocor state; tenant tunggal =
 * tidak bisa diverifikasi). Permission dicari per NAMA; grant sementara & role user2 dipulihkan persis (jumlah + CRC role_permissions).
 */
require_once __DIR__ . '/qa_hist.php';

define('Q28_SEL_BASE', 'api/v5/select/document-archive/archive');

function q28_selopts_ac14($r)
{
    $out = [];
    foreach ($r[1]['result']['options'] ?? [] as $o) {
        $out[$o['value']] = $o['label'];
    }

    return $out;
}

return [

    [
        'id'    => 'AC-12',
        'title' => '(rev, K-8 a) user2 BER-Opname Document (grant sementara): GET opnames/{S} & /documents sesi terlihat = 200; sesi tidak terlihat / id tidak ada / id bekas draft (dan sisa baris status 1/3) = 404 ARCHIVE412; pembuat selalu; alur opname ED-1026 (Step 1, scan, satu POST opnames) jalan dan sesi baru muncul di riwayat',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s) {
                // prasyarat per NAMA permission (bukan id): role asli user2 = List Archive TANPA Opname Document (R0); Opname Document hanya dari grant sementara
                $roles = q28_u2_roles_now($t);
                $names = $t->db()->table('role_permissions as rp')->join('permissions as p', 'p.id_permission', '=', 'rp.id_permission')
                    ->whereIn('rp.id_role', $roles)->pluck('p.permission_name')->all();
                $t->true(in_array(Q28_LA, $names, true), 'prasyarat: role user2 punya ' . Q28_LA . ' (dicari per nama)');
                $t->true(in_array(Q28_OD, $names, true), 'prasyarat: role user2 punya ' . Q28_OD . ' (grant sementara QA28 aktif)');
                $t->eq($t->db()->table('role_permissions')->where('created_by', 'QA28')->whereIn('id_role', $roles)->count(), count($roles), 'prasyarat: ' . Q28_OD . ' milik role user2 HANYA dari grant sementara (role asli = R0)');
                $uMe = (string) q28_uname($t);

                $x = null;
                $ids = null;
                q28_with_user($t, ['role' => 1], function () use ($t, $s, &$x, &$ids) {
                    $x = q28_tree($t);
                    $ids = q28_hfix($t, $s, $x);
                    q28_add_deleted($t, $s, $x, $ids);
                    // folder baru untuk alur (tidak beririsan dengan sesi fixture, jadi aman dibuat sesudah confirmed_at digeser)
                    $x['FLOW'] = q28_folder($t, 'FLOW', ['parent' => $x['P']]);
                });

                $s2 = q28_u2_session($t);
                $ok = function ($label, $session, $id) use ($t) {
                    foreach (['' => 'show', '/documents' => 'documents'] as $suffix => $name) {
                        $r = $t->call($session, 'GET', Q28_BASE . '/opnames/' . $id . $suffix);
                        $t->status($r, 200, "$label: $name");
                        $t->eq($r[1]['msg_code'] ?? null, 'ARCHIVE200', "$label: $name msg_code");
                    }
                };
                $nf = function ($label, $session, $id) use ($t) {
                    foreach (['' => 'show', '/documents' => 'documents', '/documents?result=all' => 'documents all'] as $suffix => $name) {
                        $r = $t->call($session, 'GET', Q28_BASE . '/opnames/' . $id . $suffix);
                        q28_no500($t, $r, "$label: $name");
                        q28_deny($t, $r, 404, 'ARCHIVE412', "$label: $name");
                        $t->true(empty($r[1]['result']), "$label: $name tanpa data");
                    }
                };

                // --- user2 (+ Opname Document): sesi terlihat A, B, C (root), Y, Z -> 200
                foreach (['A', 'B', 'C', 'Y', 'Z'] as $k) {
                    $ok("user2 sesi $k terlihat", $s2, $ids[$k]);
                }
                // isi respons show: bentuk kontrak
                $r = $t->call($s2, 'GET', Q28_BASE . '/opnames/' . $ids['A']);
                $res = $r[1]['result'];
                foreach (['id_archive_opname', 'id_archive', 'status', 'status_label', 'scope', 'folders', 'counts', 'result_counts', 'is_partial', 'warnings', 'created_by', 'created_at', 'confirmed_at'] as $k) {
                    $t->true(array_key_exists($k, $res), "show A user2: kunci $k");
                }
                $t->eq(array_keys($res['result_counts']), ['all', 'scanned', 'verified', 'not_found', 'invalid', 'unscanned'], 'show A: kunci result_counts');
                $t->eq($res['status'], 2, 'show A: status 2');
                // tidak terlihat / tak ada -> 404 ARCHIVE412
                $nf('user2 sesi D (SJ lokasi JOG)', $s2, $ids['D']);
                $nf('user2 sesi E (SP tanpa View)', $s2, $ids['E']);
                $nf('user2 sesi G1 (folder terhapus, bukan pembuat)', $s2, $ids['G1']);
                $nf('user2 sesi G2 (folder terhapus, pembuat user QA)', $s2, $ids['G2']);
                $nf('user2 id tak ada', $s2, '999999999999999999999999999999');
                $nf('user2 id sangat panjang', $s2, str_repeat('9', 200));
                $nf('user2 id berisi kutip', $s2, rawurlencode("' OR 1=1 --"));
                $nf('user2 id non-ASCII', $s2, rawurlencode('日本語'));
                $r = $t->call($s2, 'GET', Q28_BASE . '/opnames/' . $ids['E']);
                $t->eq($r[1]['message'], "Opname session isn't found", 'pesan ARCHIVE412 bahasa EN');

                // --- (rev, FB-3) id bekas draft = tidak ada: sesi dibuat lalu barisnya dibuang (= draft/batal yang dibersihkan Updater) -> 404 untuk semua pembaca
                $gone = q28_run($t, $s, $x['FLOW'], [], []);
                $t->status($t->call($s, 'GET', Q28_BASE . '/opnames/' . $gone), 200, 'sesi sementara terbaca sebelum dibuang');
                q28_w($t, function ($c) use ($gone) {
                    $c->table('archive_opname_documents')->where('id_archive_opname', $gone)->delete();
                    $c->table('archive_opname_folders')->where('id_archive_opname', $gone)->delete();
                    $c->table('archive_opnames')->where('id_archive_opname', $gone)->delete();
                });
                $nf('user2 id bekas draft (baris sudah dibuang)', $s2, $gone);
                $nf('user3 (pembuat) id bekas draft', $s, $gone);
                // --- sisa baris status 1 (draft) / 3 (batal) bila masih ada di DB lama: tidak terbaca siapa pun (readable() hanya status 2), juga pembuatnya, dan tidak masuk riwayat
                $legacy = ['QA28BULKLEGACY1' => 1, 'QA28BULKLEGACY3' => 3];
                q28_w($t, function ($c) use ($legacy, $x, $uMe) {
                    $at = date('Y-m-d H:i:s');
                    foreach ($legacy as $id => $status) {
                        $c->table('archive_opnames')->insert([
                            'id_archive_opname' => $id, 'id_archive' => $x['FLOW'], 'status' => $status, 'scope_name' => 'legacy', 'scope_folder_count' => 0,
                            'total_documents' => 0, 'verified_before_count' => 0, 'scanned_count' => 0, 'verified_count' => 0,
                            'not_found_count' => 0, 'invalid_count' => 0, 'unscanned_count' => 0, 'unverified_count' => 0,
                            'selected_at' => $at, 'confirmed_at' => null, 'created_at' => $at, 'created_by' => $uMe, 'updated_at' => $at, 'updated_by' => $uMe,
                        ]);
                    }
                });
                foreach ($legacy as $id => $status) {
                    $nf("user3 (pembuat) sisa baris status $status", $s, $id);
                    $nf("user2 sisa baris status $status", $s2, $id);
                    $listUser3 = q28_hids(q28_hist($t, $s, ['pagination' => 100]));
                    $t->true(!in_array($id, $listUser3, true), "riwayat user3: baris status $status tidak muncul");
                }
                $t->true(!in_array('QA28BULKLEGACY1', q28_hids(q28_hist($t, $s2, ['pagination' => 100])), true), 'riwayat user2: baris status 1 tidak muncul');

                // --- user3 (QA_USER role 3, semua lokasi, pemegang Opname Document): E tak terlihat, G1 tak terlihat, G2 milik sendiri
                $nf('user3 sesi E (SP tanpa View, bukan pembuat)', $s, $ids['E']);
                $nf('user3 sesi G1 (bukan pembuat)', $s, $ids['G1']);
                foreach (['A', 'B', 'C', 'D', 'Y', 'Z', 'G2'] as $k) {
                    $ok("user3 sesi $k", $s, $ids[$k]);
                }
                // pembuat sesi di folder tak terlihat: E dipindah ke pemilik user QA -> terbaca
                q28_set_owner($t, $ids['E'], $uMe);
                $ok('user3 pembuat sesi E', $s, $ids['E']);
                q28_set_owner($t, $ids['E'], 'qa28bob');

                // --- superadmin (role 1 / 2): semua sesi terkonfirmasi
                q28_with_user($t, ['role' => 1], function ($setter) use ($t, $s, $ids, $ok, $nf, $gone) {
                    foreach (['A', 'B', 'C', 'D', 'E', 'Y', 'Z', 'G1', 'G2'] as $k) {
                        $ok("superadmin role 1 sesi $k", $s, $ids[$k]);
                    }
                    $nf('superadmin id bekas draft', $s, $gone);
                    $nf('superadmin sisa baris status 1', $s, 'QA28BULKLEGACY1');
                    $setter(['role' => 2]);
                    foreach (['A', 'E', 'G1'] as $k) {
                        $ok("superadmin role 2 sesi $k", $s, $ids[$k]);
                    }
                });

                // --- alur ED-1026 untuk pemegang Opname Document (user3): Step 1 folders, Step 2 scan, Step 3/Confirm = SATU POST opnames, show, documents, riwayat
                $doc = q28_doc($t, 'flow', ['parent' => $x['FLOW']], 6);
                $rf = $t->call($s, 'GET', q28_url(Q28_BASE . '/opnames/folders', ['id_archive' => $x['FLOW']]));
                $t->status($rf, 200, 'Step 1: GET opnames/folders');
                $h = q28_session($t, $s, $x['FLOW']);
                $t->status(q28_scan($t, $s, $h, q28_n($t, $doc)), 200, 'Step 2: scan (POST opnames/scan, tanpa simpan)');
                $t->eq($t->db()->table('archive_opnames')->where('id_archive', $x['FLOW'])->where('id_archive_opname', 'not like', 'QA28BULK%')->count(), 0, 'sebelum POST opnames: belum ada sesi tersimpan (scan tidak menulis)');
                $t->status(q28_confirm($t, $s, $h), 200, 'Confirm (POST opnames)');
                $flow = $h->id;
                $rs = $t->call($s, 'GET', Q28_BASE . '/opnames/' . $flow);
                $t->status($rs, 200, 'show sesi baru');
                $t->eq($rs[1]['result']['status'], 2, 'sesi baru: status 2');
                $t->eq($rs[1]['result']['is_partial'], false, 'sesi sendiri penuh terlihat: is_partial false');
                $t->eq($rs[1]['result']['result_counts']['verified'], 1, 'result_counts.verified 1');
                $rd = $t->call($s, 'GET', Q28_BASE . '/opnames/' . $flow . '/documents?result=scanned');
                $t->status($rd, 200, 'Step 3: documents scanned');
                $t->eq($rd[1]['result']['total'], 1, '1 baris scanned');
                $t->true(in_array($flow, q28_hids(q28_hist($t, $s, ['pagination' => 100])), true), 'sesi baru muncul di riwayat user3');
                $t->true(in_array($flow, q28_hids(q28_hist($t, $s2, ['pagination' => 100])), true), 'dan juga di riwayat user2 (folder FLOW terlihat)');
                $t->call($s2, 'GET', 'api/v5/auth/log-out');
            });
        },
    ],

    [
        'id'    => 'AC-14',
        'title' => '(rev, K-8 a) R0 = role asli user2 (List Archive tanpa Opname Document): GET opnames, opnames/{S}, /documents (juga id tidak ada & id_archive di luar scope) = 403 GE0114 parameter "Opname Document" tanpa data; select opname-users 200 options []; select folders tetap = scope; role 22 (tanpa permission Archive) = 403 GE0114 "Opname Document"; tulis opname 403; tanpa token 401; dengan grant sementara = 200; role & grant dipulihkan persis',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s) {
                $x = null;
                $ids = null;
                q28_with_user($t, ['role' => 1], function () use ($t, $s, &$x, &$ids) {
                    $x = q28_tree($t);
                    $ids = q28_hfix($t, $s, $x);
                });
                $snap = q28_u2_snapshot($t);
                $t->true(count($snap) >= 1, 'prasyarat: user2 punya role');
                file_put_contents(q28_u2_journal(), json_encode($snap));
                $roles = q28_u2_roles_now($t);
                $names = $t->db()->table('role_permissions as rp')->join('permissions as p', 'p.id_permission', '=', 'rp.id_permission')
                    ->whereIn('rp.id_role', $roles)->pluck('p.permission_name')->all();
                $t->true(in_array(Q28_LA, $names, true) && !in_array(Q28_OD, $names, true), 'prasyarat: R0 = role asli user2 punya ' . Q28_LA . ' TANPA ' . Q28_OD . ' (per nama)');
                $rpBefore = q28_rp_sum($t);
                $deny = function ($label, $r) use ($t, $ids) {
                    q28_no500($t, $r, $label);
                    q28_deny($t, $r, 403, 'GE0114', $label);
                    $t->eq($r[1]['parameter'] ?? null, Q28_OD, "$label: parameter = Opname Document");
                    $t->true(empty($r[1]['result']), "$label: tanpa data");
                    $body = json_encode($r[1]);
                    $t->true(strpos($body, 'QA28') === false && strpos($body, (string) $ids['A']) === false, "$label: tidak membocorkan data sesi");
                };
                try {
                    $s2 = q28_u2_session($t);
                    $urls = [
                        'GET opnames' => '/opnames',
                        'GET opnames?id_archive (dalam scope)' => '/opnames?id_archive=' . $x['F'],
                        'GET opnames?id_archive (di luar scope: SP)' => '/opnames?id_archive=' . $x['SP'],
                        'GET opnames?id_archive (tak ada)' => '/opnames?id_archive=999999999999',
                        'GET opnames?search' => '/opnames?search=' . rawurlencode('{"query":"alice"}'),
                        'GET opnames?search tak valid' => '/opnames?search=' . rawurlencode('{bad'),
                        'GET opnames/{id}' => '/opnames/' . $ids['A'],
                        'GET opnames/{id}/documents' => '/opnames/' . $ids['A'] . '/documents',
                        'GET opnames/{id}/documents?result=all' => '/opnames/' . $ids['A'] . '/documents?result=all',
                        'GET opnames/{id tak ada}' => '/opnames/999999999999',
                        'GET opnames/{id tak ada}/documents' => '/opnames/999999999999/documents',
                    ];
                    // --- R0: 403 GE0114 "Opname Document" pada semua (permission dicek PERTAMA, sebelum id / lokasi / View / validasi)
                    foreach ($urls as $label => $path) {
                        $deny("R0: $label", $t->call($s2, 'GET', Q28_BASE . $path));
                    }
                    // pesan bahasa EN
                    $r = $t->call($s2, 'GET', Q28_BASE . '/opnames');
                    $t->eq($r[1]['message'], "You don't have permission to Opname Document!", 'R0: pesan GE0114 bahasa EN');
                    // tulis opname ED-1026 (milik ED-1026 AC-3; dicatat di sini): folders / scan / preview / POST = 403 juga
                    $deny('R0: GET opnames/folders', $t->call($s2, 'GET', Q28_BASE . '/opnames/folders'));
                    $deny('R0: POST opnames', $t->call($s2, 'POST', Q28_BASE . '/opnames', ['id_archive' => $x['OTH'], 'folders' => []]));
                    $deny('R0: POST opnames/scan', $t->call($s2, 'POST', Q28_BASE . '/opnames/scan', ['id_archive' => $x['OTH'], 'folders' => [], 'code' => 'x']));
                    // select (konvensi select: tanpa permission_v5)
                    $rs = $t->call($s2, 'GET', Q28_SEL_BASE . '/folders');
                    $t->status($rs, 200, 'R0: select folders (konvensi select)');
                    $o = q28_oracle($t, q28_uu($t, 'user2'));
                    $got = array_keys(q28_selopts_ac14($rs));
                    $exp = array_keys($o['visible']);
                    sort($got);
                    sort($exp);
                    $t->eq($got, $exp, 'R0: select folders tetap = scope user (lokasi ∩ View)');
                    $ru = $t->call($s2, 'GET', Q28_SEL_BASE . '/opname-users');
                    $t->status($ru, 200, 'R0: select opname-users 200');
                    $t->eq($ru[1]['result']['options'], [], 'R0: select opname-users options []');
                    $t->true(array_key_exists('default', $ru[1]['result']) && $ru[1]['result']['default'] === null, 'R0: select opname-users default null');
                    $ru = $t->call($s2, 'GET', Q28_SEL_BASE . '/opname-users?search=' . rawurlencode('qa28') . '&selected_id=qa28alice');
                    $t->eq($ru[1]['result']['options'], [], 'R0: select opname-users dengan search / selected_id tetap []');
                    // sebagai kontrol: pemegang (QA_USER role 3) melihat username
                    $rq = $t->call($s, 'GET', Q28_SEL_BASE . '/opname-users');
                    $t->true(in_array('qa28alice', array_column($rq[1]['result']['options'], 'value'), true), 'kontrol: pemegang Opname Document melihat qa28alice di select opname-users');

                    // --- role 22 'Pusat - Admin AR/AP': tanpa permission Archive apa pun -> 403 GE0114 "Opname Document" (bukan "List Archive")
                    q28_u2_roles($t, [22]);
                    $perms = $t->db()->table('role_permissions as rp')->join('permissions as p', 'p.id_permission', '=', 'rp.id_permission')
                        ->where('rp.id_role', 22)->pluck('p.permission_name')->all();
                    $t->true(!in_array(Q28_LA, $perms, true) && !in_array(Q28_OD, $perms, true), 'prasyarat: role 22 tanpa ' . Q28_LA . ' dan tanpa ' . Q28_OD);
                    foreach ($urls as $label => $path) {
                        $deny("role 22: $label", $t->call($s2, 'GET', Q28_BASE . $path));
                    }
                    $ru = $t->call($s2, 'GET', Q28_SEL_BASE . '/opname-users');
                    $t->eq([$ru[0], $ru[1]['result']['options'] ?? null], [200, []], 'role 22: select opname-users 200 options []');

                    // --- role asli dipulihkan: tetap 403 (List Archive saja tidak cukup); lalu dengan grant sementara Opname Document: 200
                    q28_u2_restore($t, $snap);
                    $deny('role asli dipulihkan: GET opnames', $t->call($s2, 'GET', Q28_BASE . '/opnames'));
                    q28_with_grant($t, $roles, Q28_OD, function () use ($t, $s2, $ids) {
                        foreach (['/opnames' => 200, '/opnames/' . $ids['A'] => 200, '/opnames/' . $ids['A'] . '/documents' => 200, '/opnames/999999999999' => 404] as $path => $http) {
                            $r = $t->call($s2, 'GET', Q28_BASE . $path);
                            $t->status($r, $http, "dengan Opname Document (grant sementara): GET $path");
                        }
                        $ru = $t->call($s2, 'GET', Q28_SEL_BASE . '/opname-users');
                        $t->true(count($ru[1]['result']['options']) >= 1, 'dengan Opname Document: select opname-users memuat username');
                    });
                    $deny('grant dicabut: GET opnames kembali 403', $t->call($s2, 'GET', Q28_BASE . '/opnames'));
                    $t->call($s2, 'GET', 'api/v5/auth/log-out');
                } finally {
                    q28_u2_restore($t, $snap);
                    @unlink(q28_u2_journal());
                    q28_grant_recover($t);
                }
                $t->eq(json_encode(q28_u2_snapshot($t)), json_encode($snap), 'role user2 dipulihkan persis');
                $t->eq(json_encode(q28_rp_sum($t)), json_encode($rpBefore), 'role_permissions sama persis (grant sementara dicabut)');

                // tanpa token: 401 (semua endpoint baru & diubah)
                foreach (['/opnames', '/opnames/' . $ids['A'], '/opnames/' . $ids['A'] . '/documents'] as $path) {
                    $r = $t->raw('GET', Q28_BASE . $path);
                    $t->status($r, 401, "tanpa token $path");
                }
                foreach (['/folders', '/opname-users'] as $path) {
                    $r = $t->raw('GET', Q28_SEL_BASE . $path);
                    $t->status($r, 401, "select tanpa token $path");
                }
            }, ['od' => false]);
        },
    ],

    [
        'id'    => 'X-1',
        'title' => 'display setting lewat API v5 (PUT setting/display-columns/documentArchiveOpnameHistory; FE memakai api/v5): kolom aktif menentukan kunci baris; Scanned & Belum discan bisa ditambahkan; sort dan filter kolom yang disembunyikan tetap jalan; baris display setting dipulihkan persis',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function ($ds) use ($t, $s) {
                $x = null;
                $ids = null;
                q28_with_user($t, ['role' => 1], function () use ($t, $s, &$x, &$ids) {
                    $x = q28_tree($t);
                    $ids = q28_hfix($t, $s, $x);
                });
                $r = q28_hist($t, $s, ['pagination' => 100]);
                $t->status($r, 200, 'awal');
                $row = q28_dsrow($t);
                $t->true($row !== null, 'baris display setting terbentuk');
                $cur = json_decode($row['current_columns'], true);
                $avail = json_decode($row['available_columns'], true);

                // GET lewat API v5 (REACT_APP_API_BASEPATH=api/v5: dipakai FE DisplaySettingDrawer)
                $g = $t->call($s, 'GET', 'api/v5/setting/display-columns/documentArchiveOpnameHistory');
                $t->status($g, 200, 'GET display-columns/documentArchiveOpnameHistory');
                $t->true(isset($g[1]['result']['current_columns']) || isset($g[1]['result']['available_columns']) || isset($g[1]['current_columns']), 'GET display-columns: berisi kolom');
                $body = json_encode($g[1]);
                $t->true(strpos($body, 'unscannedCount') !== false && strpos($body, 'scannedCount') !== false, 'available_columns memuat Scanned & Belum discan');

                // ganti kolom aktif: sembunyikan User & Invalid, tambahkan Scanned dan Belum discan
                $keep = array_values(array_filter($cur, function ($c) {
                    return !in_array($c['data_index'], ['createdBy', 'invalidCount'], true);
                }));
                $add = array_values(array_filter($avail, function ($c) {
                    return in_array($c['data_index'], ['scannedCount', 'unscannedCount'], true);
                }));
                $new = array_merge($keep, $add);
                $put = $t->call($s, 'PUT', 'api/v5/setting/display-columns/documentArchiveOpnameHistory', ['current_columns' => $new, 'current_queries' => json_decode($row['current_queries'], true)]);
                $t->true($put[0] < 300, 'PUT display-columns: sukses (' . $put[0] . ')');
                $t->eq(array_column(json_decode(q28_dsrow($t)['current_columns'], true), 'data_index'), array_column($new, 'data_index'), 'DB current_columns berubah');

                $r = q28_hist($t, $s, ['pagination' => 100]);
                $t->status($r, 200, 'setelah ganti kolom');
                $t->eq(array_column($r[1]['result']['columns'], 'data_index'), ['confirmedAt', 'scope', 'totalDocuments', 'verifiedCount', 'notFoundCount', 'scannedCount', 'unscannedCount'], 'respons columns mengikuti display setting');
                $expectKeys = ['id_archive_opname', 'id_archive', 'confirmed_at', 'scope', 'total_documents', 'verified_count', 'not_found_count', 'scanned_count', 'unscanned_count'];
                foreach ($r[1]['result']['data'] as $rw) {
                    $a = array_keys($rw);
                    $b = $expectKeys;
                    sort($a);
                    sort($b);
                    $t->eq($a, $b, 'kunci baris mengikuti kolom aktif (tanpa created_by & invalid_count; dengan scanned_count & unscanned_count)');
                    $db = q28_opname($t, $rw['id_archive_opname']);
                    $t->eq($rw['scanned_count'], (int) $db['scanned_count'], 'scanned_count = DB');
                    $t->eq($rw['unscanned_count'], (int) $db['unscanned_count'], 'unscanned_count = DB');
                }
                // filter dan sort kolom yang disembunyikan tetap bekerja
                $r = q28_hist($t, $s, ['pagination' => 100, 'search' => ['createdBy' => 'qa28alice']]);
                $t->eq(q28_hkeys(q28_hids($r), $ids), ['C', 'A'], 'filter createdBy walau kolom User disembunyikan');
                $r = q28_hist($t, $s, ['pagination' => 100, 'search' => ['query' => 'qa28bob']]);
                $t->eq(q28_hkeys(q28_hids($r), $ids), ['B'], 'search username walau kolom User disembunyikan (user3 tak melihat E)');
                foreach (['createdBy' => 'created_by', 'invalidCount' => 'invalid_count', 'scannedCount' => 'scanned_count'] as $by => $col) {
                    $r = q28_hist($t, $s, ['pagination' => 100, 'sorts' => [json_encode(['sortBy' => $by, 'sortType' => 'asc'])]]);
                    $t->status($r, 200, "sort kolom tersembunyi/aktif $by");
                    $exp = $t->db()->table('archive_opnames')->whereIn('id_archive_opname', q28_oracle_sessions($t, q28_uu($t, 'user3')))->orderBy($col, 'asc')->orderBy('confirmed_at', 'desc')->orderBy('id_archive_opname', 'desc')->pluck('id_archive_opname')->all();
                    $t->eq(q28_hids($r), $exp, "sort $by asc = SQL oracle (kolom tersembunyi ikut jalan)");
                }
                // hanya satu kolom aktif: baris tetap memuat kunci tetap
                q28_w($t, function ($c) use ($new) {
                    $only = [$new[0]];
                    $c->table('column_display_settings')->where('module_name', 'documentArchiveOpnameHistory')->update(['current_columns' => json_encode($only)]);
                });
                $r = q28_hist($t, $s, ['pagination' => 100]);
                $t->status($r, 200, 'satu kolom aktif');
                $a = array_keys($r[1]['result']['data'][0]);
                sort($a);
                $b = ['confirmed_at', 'id_archive', 'id_archive_opname', 'scope'];
                sort($b);
                $t->eq($a, $b, 'satu kolom aktif: baris = kolom itu + id_archive_opname, id_archive, scope');
                // kolom kosong: tidak 500
                q28_w($t, function ($c) {
                    $c->table('column_display_settings')->where('module_name', 'documentArchiveOpnameHistory')->update(['current_columns' => json_encode([])]);
                });
                $r = q28_hist($t, $s, ['pagination' => 100]);
                q28_no500($t, $r, 'nol kolom aktif');
                $t->note('nol kolom aktif -> HTTP ' . $r[0]);
            });
        },
    ],

    [
        'id'    => 'X-2',
        'title' => 'banyak sesi (3000 sesi sisipan berpenanda qa28bulk): waktu respons list/search/filter/select tercatat, hasil benar, tanpa 500; sisipan dibuang',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s) {
                $x = null;
                $ids = null;
                q28_with_user($t, ['role' => 1], function () use ($t, $s, &$x, &$ids) {
                    $x = q28_tree($t);
                    $ids = q28_hfix($t, $s, $x);
                });
                $n = 3000;
                $locs = q28_locs($t);
                q28_w($t, function ($c) use ($n, $x) {
                    $now = date('Y-m-d H:i:s');
                    $ops = [];
                    $fs = [];
                    for ($i = 0; $i < $n; $i++) {
                        $id = 'QA28BULK' . str_pad((string) $i, 8, '0', STR_PAD_LEFT);
                        $at = date('Y-m-d H:i:s', strtotime('-60 days') + $i * 600);
                        $folder = $i % 3 === 0 ? $x['F'] : ($i % 3 === 1 ? $x['OTH'] : $x['S2']);
                        $ops[] = [
                            'id_archive_opname' => $id, 'id_archive' => $folder, 'status' => 2, 'scope_name' => 'bulk', 'scope_folder_count' => 0,
                            'total_documents' => $i, 'verified_before_count' => 0, 'scanned_count' => $i % 50, 'verified_count' => $i % 40,
                            'not_found_count' => $i % 7, 'invalid_count' => $i % 5, 'unscanned_count' => 10, 'unverified_count' => 0,
                            'selected_at' => $at, 'confirmed_at' => $at, 'created_at' => $at, 'created_by' => 'qa28bulk' . ($i % 20), 'updated_at' => $at, 'updated_by' => 'qa28bulk',
                        ];
                        $fs[] = [
                            'id_archive_opname_folder' => 'QA28BULKF' . str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'id_archive_opname' => $id, 'id_archive' => $folder,
                            'id_archive_parent' => null, 'name' => 'QA28-bulk-folder-' . ($i % 10), 'level' => 0, 'is_continue' => 0, 'is_opnamed_today' => 0,
                            'total_documents' => $i, 'verified_count' => 0, 'verified_after_count' => 0, 'unverified_count' => 0, 'created_at' => $at,
                        ];
                    }
                    foreach (array_chunk($ops, 500) as $chunk) {
                        $c->table('archive_opnames')->insert($chunk);
                    }
                    foreach (array_chunk($fs, 500) as $chunk) {
                        $c->table('archive_opname_folders')->insert($chunk);
                    }
                });
                try {
                    $time = function ($label, callable $call) use ($t) {
                        $t0 = microtime(true);
                        $r = $call();
                        $ms = round((microtime(true) - $t0) * 1000);
                        q28_no500($t, $r, $label);
                        $t->status($r, 200, $label);
                        $t->note("$label: $ms ms");

                        return [$r, $ms];
                    };
                    list($r, $ms) = $time('list p1 (3007 sesi, user role 3)', function () use ($t, $s) {
                        return q28_hist($t, $s, ['pagination' => 10]);
                    });
                    $total = $t->db()->table('archive_opnames')->where('status', 2)->count();
                    $e = count(q28_oracle_sessions($t, q28_uu($t, 'user3')));
                    $t->eq($r[1]['result']['total'], $e, 'total list = oracle (' . $e . ' dari ' . $total . ' sesi terkonfirmasi)');
                    $t->eq($r[1]['result']['last_page'], (int) ceil($e / 10), 'last_page');
                    $t->true($ms < 5000, "list p1 < 5 detik ($ms ms)");
                    list($r, $ms) = $time('list halaman jauh (page 250)', function () use ($t, $s) {
                        return q28_hist($t, $s, ['pagination' => 10, 'page' => 250]);
                    });
                    $t->eq(count($r[1]['result']['data']), 10, 'halaman 250: 10 baris');
                    list($r, $ms) = $time('search query username (qa28bulk7)', function () use ($t, $s) {
                        return q28_hist($t, $s, ['pagination' => 10, 'search' => ['query' => 'qa28bulk7']]);
                    });
                    $t->eq($r[1]['result']['total'], 150, 'search qa28bulk7: 150 sesi (3000/20)');
                    $t->true($ms < 5000, "search username < 5 detik ($ms ms)");
                    list($r, $ms) = $time('search query nama folder (bulk-folder-3)', function () use ($t, $s) {
                        return q28_hist($t, $s, ['pagination' => 10, 'search' => ['query' => 'bulk-folder-3']]);
                    });
                    $t->eq($r[1]['result']['total'], 300, 'search bulk-folder-3: 300 sesi (3000/10)');
                    $t->true($ms < 8000, "search nama folder < 8 detik ($ms ms)");
                    list($r, $ms) = $time('filter idArchives [F]', function () use ($t, $s, $x) {
                        return q28_hist($t, $s, ['pagination' => 10, 'search' => ['idArchives' => [$x['F']]]]);
                    });
                    $t->true($r[1]['result']['total'] >= 1000, 'idArchives F: >= 1000 sesi (' . $r[1]['result']['total'] . ')');
                    $t->true($ms < 8000, "filter folder < 8 detik ($ms ms)");
                    list($r, $ms) = $time('filter rentang waktu + user', function () use ($t, $s) {
                        return q28_hist($t, $s, ['pagination' => 10, 'search' => ['createdBy' => 'qa28bulk3', 'confirmedAt' => [date('Y-m-d H:i:s', strtotime('-50 days')), date('Y-m-d H:i:s', strtotime('-20 days'))]]]);
                    });
                    $exp = $t->db()->table('archive_opnames')->where('created_by', 'qa28bulk3')->where('confirmed_at', '>=', date('Y-m-d H:i:s', strtotime('-50 days')))->where('confirmed_at', '<=', date('Y-m-d H:i:s', strtotime('-20 days')))->count();
                    $t->true(abs($r[1]['result']['total'] - $exp) <= 3, 'rentang + user: total ~ SQL (' . $r[1]['result']['total'] . " vs $exp; selisih = batas detik hari berjalan)");
                    list($r, $ms) = $time('sort totalDocuments desc', function () use ($t, $s) {
                        return q28_hist($t, $s, ['pagination' => 10, 'sorts' => [json_encode(['sortBy' => 'totalDocuments', 'sortType' => 'desc'])]]);
                    });
                    $t->eq($r[1]['result']['data'][0]['total_documents'], (int) $t->db()->table('archive_opnames')->whereIn('id_archive_opname', q28_oracle_sessions($t, q28_uu($t, 'user3')))->max('total_documents'), 'sort total desc: nilai terbesar (SQL max) di atas');
                    $t->true($r[1]['result']['data'][0]['total_documents'] >= $r[1]['result']['data'][1]['total_documents'], 'sort total desc: baris 1 >= baris 2');
                    list($r, $ms) = $time('konteks folder F', function () use ($t, $s, $x) {
                        return q28_hist($t, $s, ['pagination' => 10, 'id_archive' => $x['F']]);
                    });
                    $time('select opname-users', function () use ($t, $s) {
                        return $t->call($s, 'GET', Q28_SEL_BASE . '/opname-users');
                    });
                    $r = $t->call($s, 'GET', Q28_SEL_BASE . '/opname-users');
                    $t->true(count($r[1]['result']['options']) >= 20, 'select opname-users: 20 user bulk + fixture (' . count($r[1]['result']['options']) . ')');
                    $time('select folders', function () use ($t, $s) {
                        return $t->call($s, 'GET', Q28_SEL_BASE . '/folders');
                    });
                    $s2 = q28_u2_session($t);
                    list($r, $ms) = $time('list p1 user2', function () use ($t, $s2) {
                        return q28_hist($t, $s2, ['pagination' => 10]);
                    });
                    $t->eq($r[1]['result']['total'], count(q28_oracle_sessions($t, q28_uu($t, 'user2'))), 'user2: total = oracle');
                    $t->call($s2, 'GET', 'api/v5/auth/log-out');
                } finally {
                    q28_w($t, function ($c) {
                        $c->table('archive_opname_folders')->where('id_archive_opname', 'like', 'QA28BULK%')->delete();
                        $c->table('archive_opnames')->where('id_archive_opname', 'like', 'QA28BULK%')->delete();
                    });
                    $t->eq($t->db()->table('archive_opnames')->where('id_archive_opname', 'like', 'QA28BULK%')->count(), 0, 'sesi sisipan dibuang');
                    $t->eq($t->db()->table('archive_opname_folders')->where('id_archive_opname', 'like', 'QA28BULK%')->count(), 0, 'baris folder sisipan dibuang');
                }
            });
        },
    ],

    [
        'id'    => 'X-3',
        'title' => 'isolasi: permintaan bergantian user2 / user3 / superadmin tidak bocor state (hasil tiap user identik di setiap putaran); tenant: butuh >= 2 DB di QA_DBS',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s) {
                $x = null;
                $ids = null;
                q28_with_user($t, ['role' => 1], function () use ($t, $s, &$x, &$ids) {
                    $x = q28_tree($t);
                    $ids = q28_hfix($t, $s, $x);
                });
                $s2 = q28_u2_session($t);
                // superadmin = token ketiga: user QA role 1 sementara tidak bisa bergantian dengan user3 pada token yang sama;
                // pakai user2 dan user3 secara bergantian (dua user, dua scope berbeda)
                $base2 = null;
                $base3 = null;
                for ($i = 0; $i < 6; $i++) {
                    $r2 = q28_hist($t, $s2, ['pagination' => 100]);
                    $r3 = q28_hist($t, $s, ['pagination' => 100]);
                    $s3a = $t->call($s, 'GET', Q28_SEL_BASE . '/opname-users');
                    $s2a = $t->call($s2, 'GET', Q28_SEL_BASE . '/opname-users');
                    $f2 = $t->call($s2, 'GET', Q28_SEL_BASE . '/folders');
                    $f3 = $t->call($s, 'GET', Q28_SEL_BASE . '/folders');
                    $cur2 = json_encode([q28_hids($r2), $s2a[1]['result']['options'], array_keys(q28_selopts_ac14($f2))]);
                    $cur3 = json_encode([q28_hids($r3), $s3a[1]['result']['options'], array_keys(q28_selopts_ac14($f3))]);
                    if ($base2 === null) {
                        $base2 = $cur2;
                        $base3 = $cur3;
                        $t->true($base2 !== $base3, 'kontrol: hasil user2 dan user3 memang berbeda');
                    }
                    $t->eq($cur2, $base2, "putaran $i: hasil user2 identik dengan putaran pertama");
                    $t->eq($cur3, $base3, "putaran $i: hasil user3 identik dengan putaran pertama");
                }
                $t->eq(q28_hkeys(json_decode($base2, true)[0], $ids), ['Z', 'Y', 'C', 'B', 'A'], 'user2 selalu 5 sesi (Z, Y, C, B, A)');
                $t->eq(q28_hkeys(json_decode($base3, true)[0], $ids), ['Z', 'Y', 'D', 'C', 'B', 'A'], 'user3 selalu 6 sesi (Z, Y, D, C, B, A)');
                // bahasa bergantian
                $m2 = q28_hist($t, $s2)[1]['message'];
                $m3 = q28_hist($t, $s)[1]['message'];
                $t->eq([$m2, $m3], ['Document is found', 'Dokumen ditemukan'], 'bahasa tiap user tidak bocor (EN/ID)');
                $t->call($s2, 'GET', 'api/v5/auth/log-out');

                // tenant
                $dbs = $t->dbs();
                if (count($dbs) < 2) {
                    $t->note('isolasi tenant TIDAK DAPAT DIVERIFIKASI: QA_DBS hanya ' . count($dbs) . ' DB (' . implode(',', $dbs) . ')');
                } else {
                    $t->isolation($t->session($dbs[0]), $t->session($dbs[1]), 10);
                }
            });
        },
    ],
];
