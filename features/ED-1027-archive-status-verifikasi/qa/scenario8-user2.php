<?php
/**
 * ED-1027 - user kedua sungguhan (QA_USER2 = adi, non-karyawan, lokasi SMR, bahasa EN, bukan superadmin): X-8 (angka = oracle untuk user2,
 * tanpa folder ber-hak dan lokasi lain, detail 403 untuk folder terlarang, sesi terlihat user2 sesuai K-4), X-9 (user2 tanpa
 * permission Archive: 403 GE0114 pada kedua endpoint baru).
 * (ED-1149 BR-1/BR-2/BR-4) QA_USER2 TIDAK memakai role developer (5/22/...) sebagai prasyarat: user_roles-nya diganti SEMENTARA ke
 * preset QA27-ROLE-U2 (akses website + List Archive, TANPA Opname Document; dibuat dari nama permission) atau QA27-ROLE-NOLIST (akses
 * website saja) lewat q27_with_u2_preset (jurnal .restore-journal-q27-user2.json, dipulihkan persis). Opname Document hanya diberikan ke
 * PRESET (q27_grant menolak role non-preset) dan dicabut persis; role_permissions sama persis dengan sebelum AC (jumlah + CRC).
 * (rev 2026-10-09, EPIC K-3 a) verifications* = Opname Document: preset U2 (List Archive TANPA Opname Document) -> verifications* 403
 * GE0114 (bukti AC-23 dengan user sungguhan); X-8 memberi preset itu Opname Document sementara.
 */
require_once __DIR__ . '/qa_lib.php';

return [

    [
        'id'    => 'X-8',
        'title' => 'QA_USER2 sungguhan (SMR, bukan superadmin): (rev) tanpa Opname Document verifications* 403 + list tanpa field verifikasi; dengan Opname Document (grant sementara): ringkasan, kolom folder, detail All & per folder = oracle & angka tangan; folder SV (hak khusus user lain), SP, SJ, JOGR terlarang 403; sesi terlihat sesuai K-4',
        'run'   => function ($t) {
            $s = $t->session();
            q27_baseline($t);
            $rpBefore = q27_rp_sum($t);
            try {
                $x = q27_tree($t);
                $uid2 = q27_u2_id($t);
                $uname2 = (string) $t->db()->table('users')->where('id_user', $uid2)->value('username');
                q27_with_u2_preset($t, Q27_PRESET_U2, function ($role2) use ($t, $s, $x, $uid2, $uname2) {
                $s2 = q27_u2_session($t);

                // --- (rev) preset U2 (List Archive TANPA Opname Document; BUKAN role 5): verifications* 403 GE0114, list 200 tanpa field verifikasi
                $roles2 = [$role2];
                $names2 = q27_role_names($t, $role2);
                $t->true(in_array('List Archive', $names2, true) && in_array('Allow Access Website', $names2, true), 'prasyarat: preset U2 memegang List Archive + Allow Access Website (per id_permission)');
                $t->true(!in_array(Q27_OD, $names2, true), 'prasyarat: preset U2 belum memegang Opname Document');
                $t->true(!q27_has_perm($t, $role2, Q27_OD), 'prasyarat: preset U2 belum memegang Opname Document (join id)');
                foreach (['GET verifications' => '/verifications', 'GET verifications/{F}' => '/verifications/' . $x['F']] as $label => $path) {
                    $r0 = $t->call($s2, 'GET', Q27_BASE . $path);
                    q27_deny($t, $r0, 403, 'GE0114', "user2 tanpa Opname Document: $label");
                    $t->true(empty($r0[1]['result']) && strpos(json_encode($r0[1]), Q27_OD) !== false, "user2 tanpa Opname Document: $label tanpa data, parameter = Opname Document");
                }
                $r0 = q27_list($t, $s2);
                $t->status($r0, 200, 'user2 tanpa Opname Document: list 200');
                $t->true(!array_key_exists('document_verified_summary', $r0[1]['result']), 'user2 tanpa Opname Document: tanpa document_verified_summary');
                $t->true(array_key_exists('data', $r0[1]['result']) && count($r0[1]['result']['data']) > 0 && !array_key_exists('document_verified', $r0[1]['result']['data'][0]), 'user2 tanpa Opname Document: baris tanpa document_verified');
                q27_grant($t, $roles2, Q27_OD); // HANYA ke preset (q27_grant menolak role non-preset)
                $t->eq($t->db()->table('role_permissions')->where('created_by', 'QA27')->where('id_role', $role2)->count(), count(q27_preset_perms(Q27_PRESET_U2)) + 1, 'Opname Document milik preset U2 = satu baris grant QA27 di atas baris dasar preset');
                $u = ['username' => $uname2, 'uid' => $uid2, 'bypass' => false, 'locs' => ['SMR']];
                $o = q27_oracle($t, $u);

                $r = $t->call($s2, 'GET', q27_url(Q27_BASE . '/archives', ['pagination' => 1000]));
                $t->status($r, 200, 'user2: list root');
                $t->eq(q27_api_vt($r[1]['result']['document_verified_summary']), q27_vt($o['summary']), 'user2: ringkasan = oracle');
                $n = 0;
                foreach ($r[1]['result']['data'] as $row) {
                    if (($row['type'] ?? null) === 'Folder') {
                        $exp = isset($o['folders'][$row['id_archive']]) ? q27_vt($o['folders'][$row['id_archive']]) : null;
                        $t->eq(q27_api_vt($row['document_verified']), $exp, "user2: folder {$row['id_archive']} = oracle");
                        $n++;
                    }
                }
                $t->true($n > 3, "user2: $n folder dicek");
                $map = q27_map($r);
                $t->eq(q27_api_vt($map[$x['P']]['document_verified']), [4, 10], 'user2: P = 4/10 (angka tangan: tanpa SV milik user lain, tanpa dJF/SJ)');
                $t->true($map[$x['PRIV']]['document_verified'] === null, 'user2: PRIV tanpa View = null');
                $t->true(!isset($map[$x['JOGR']]), 'user2: JOGR (lokasi JOG) tak ada di list');

                $rv = $t->call($s2, 'GET', Q27_BASE . '/verifications');
                $t->status($rv, 200, 'user2: GET verifications');
                $t->eq([$rv[1]['result']['verified'], $rv[1]['result']['total']], q27_vt($o['summary']), 'user2: header All = oracle');
                $t->eq(array_column($rv[1]['result']['transaction_types'], 'label')[0], 'Sales Order', 'user2 (bahasa EN): label tipe 6 = Sales Order');

                foreach (['P', 'F', 'S1', 'S1a', 'S2', 'OTH'] as $k) {
                    $rd = $t->call($s2, 'GET', Q27_BASE . '/verifications/' . $x[$k]);
                    $t->status($rd, 200, "user2: detail $k");
                    $t->eq([$rd[1]['result']['verified'], $rd[1]['result']['total']], q27_vt($o['folders'][$x[$k]]), "user2: detail $k = oracle");
                    foreach ($rd[1]['result']['transaction_types'] as $tr) {
                        $exp = $o['folders'][$x[$k]]['types'][$tr['transaction_type']] ?? [0, 0];
                        $t->eq([$tr['verified'], $tr['total']], [$exp[0], $exp[1]], "user2: detail $k tipe {$tr['transaction_type']} = oracle");
                    }
                }
                $rf = $t->call($s2, 'GET', Q27_BASE . '/verifications/' . $x['F']);
                $t->eq([$rf[1]['result']['verified'], $rf[1]['result']['total']], [4, 10], 'user2: F = 4/10 (angka tangan)');
                foreach (['SV', 'SP', 'SPc', 'PRIV', 'PRc', 'SJ', 'JOGR'] as $k) {
                    $rd = $t->call($s2, 'GET', Q27_BASE . '/verifications/' . $x[$k]);
                    q27_deny($t, $rd, 403, 'ARCHIVE407', "user2: detail $k terlarang");
                    $t->true(empty($rd[1]['result']), "user2: detail $k tanpa data");
                }
                $q = $t->call($s2, 'GET', Q27_BASE . '/verifications/' . $x['SX']);
                q27_deny($t, $q, 404, 'ARCHIVE400', 'user2: folder nonaktif 404');
                $q = $t->call($s2, 'GET', Q27_BASE . '/verifications/' . $x['dF1']);
                q27_deny($t, $q, 400, 'ARCHIVE417', 'user2: dokumen 400');

                // sesi (dibuat superadmin lewat API): A = F + S1, D = SJ (lokasi JOG), E = SP (tanpa View user2), C = root
                $ids = [];
                q27_with_user($t, ['role' => 1], function () use ($t, $s, $x, &$ids) {
                    $N = function ($k) use ($t, $x) {
                        return q27_n($t, $x[$k]);
                    };
                    $ids['A'] = q27_run($t, $s, $x['F'], q27_sel([$x['S1']]), [$N('dF2'), $N('d11')]);
                    $ids['D'] = q27_run($t, $s, $x['SJ'], [], [$N('dj1')]);
                    $ids['E'] = q27_run($t, $s, $x['SP'], [], [$N('dsp1')]);
                    $ids['C'] = q27_run($t, $s, null, q27_sel([$x['OTH']]), [$N('dO1')]);
                    foreach (['A' => '-4 days', 'D' => '-3 days', 'E' => '-2 days', 'C' => '-1 day'] as $k => $when) {
                        q27_set_opname($t, $ids[$k], ['confirmed_at' => q27_ts($when)]); // di atas sesi developer (last_sessions maks 3)
                        q27_set_owner($t, $ids[$k], 'otheruser');
                    }
                });
                $rv = $t->call($s2, 'GET', Q27_BASE . '/verifications');
                $t->status($rv, 200, 'user2: GET verifications dengan sesi');
                $seen = array_column($rv[1]['result']['last_sessions'], 'id_archive_opname');
                $t->eq(array_values(array_intersect($seen, array_values($ids))), [$ids['C'], $ids['A']], 'user2: sesi QA terlihat = C (root), A (F + S1); D (SJ) dan E (SP) tidak (sesi developer, bila ada, tidak ikut asersi)');
                $rf = $t->call($s2, 'GET', Q27_BASE . '/verifications/' . $x['F']);
                $t->eq(array_column($rf[1]['result']['last_sessions'], 'id_archive_opname'), [$ids['A']], 'user2: F: hanya sesi A');
                $t->true(isset($rf[1]['result']['last_sessions'][0]['folder_total_documents']), 'user2: F: slice ada');
                $t->eq(array_keys($rv[1]['result']['last_sessions'][0]), ['id_archive_opname', 'confirmed_at', 'created_by', 'scope', 'total_documents', 'verified_count', 'not_found_count', 'invalid_count'], 'user2: kunci session_row sesuai kontrak (urutan sama)');

                $t->call($s2, 'GET', 'api/v5/auth/log-out');
                });
            } finally {
                q27_cleanup($t);
                q27_grant_recover($t);
                $t->eq(q27_rp_sum($t), $rpBefore, 'role_permissions dipulihkan persis (preset dibuang, tidak ada sisa grant Opname Document)');
            }
        },
    ],

    [
        'id'    => 'X-9',
        'title' => 'QA_USER2 di preset U2 (List Archive, tanpa Opname Document; BUKAN role 5): list 200 + verifications* 403; dengan Opname Document diberikan ke PRESET 200; tanpa List Archive (preset NOLIST: akses website saja, BUKAN role 22): 403 GE0114 untuk kedua endpoint baru dan list (parameter sesuai permission route); user_roles QA_USER2 asli dipulihkan persis',
        'run'   => function ($t) {
            $s = $t->session();
            q27_baseline($t);
            $orig = q27_u2_snapshot($t); // role asli QA_USER2 (developer): hanya dibaca untuk membuktikan pemulihan, tidak dipakai sebagai prasyarat
            $t->true(count($orig) >= 1, 'prasyarat: user2 punya role');
            $rpBefore = q27_rp_sum($t);
            try {
                $x = q27_tree($t);
                q27_with_u2_preset($t, Q27_PRESET_U2, function ($roleU2, $use) use ($t, $x) {
                    $names = q27_role_names($t, $roleU2);
                    $t->true(in_array('List Archive', $names, true) && !in_array(Q27_OD, $names, true), 'prasyarat: preset U2 punya List Archive TANPA Opname Document (per id_permission)');
                    // preset U2: list 200 (List Archive), verifications* 403 (tanpa Opname Document); dengan grant sementara ke PRESET 200
                    $s2 = q27_u2_session($t);
                    $t->status($t->call($s2, 'GET', Q27_BASE . '/archives?pagination=5'), 200, 'user2 preset U2: archives');
                    foreach (['verifications' => '/verifications', 'verifications/{F}' => '/verifications/' . $x['F']] as $label => $path) {
                        q27_deny($t, $t->call($s2, 'GET', Q27_BASE . $path), 403, 'GE0114', "user2 preset U2 (tanpa Opname Document): $label");
                    }
                    q27_grant($t, [$roleU2], Q27_OD);
                    foreach (['verifications' => '/verifications', 'verifications/{F}' => '/verifications/' . $x['F'], 'archives' => '/archives?pagination=5'] as $label => $path) {
                        $t->status($t->call($s2, 'GET', Q27_BASE . $path), 200, "user2 preset U2 + Opname Document: $label");
                    }
                    q27_grant_recover($t);
                    $use(Q27_PRESET_NONE); // preset NOLIST: akses website saja, tanpa List Archive dan tanpa Opname Document
                    $namesNone = q27_role_names($t, q27_preset($t, Q27_PRESET_NONE));
                    $t->true(!in_array('List Archive', $namesNone, true) && !in_array(Q27_OD, $namesNone, true) && in_array('Allow Access Website', $namesNone, true), 'prasyarat: preset NOLIST = akses website saja (per id_permission)');
                    foreach (['verifications' => '/verifications', 'verifications/{F}' => '/verifications/' . $x['F'], 'archives' => '/archives?pagination=5'] as $label => $path) {
                        $r = $t->call($s2, 'GET', Q27_BASE . $path);
                        q27_deny($t, $r, 403, 'GE0114', "user2 tanpa List Archive: $label");
                        $t->true(empty($r[1]['result']), "user2 tanpa List Archive: $label tanpa data");
                        $need = $label === 'archives' ? 'List Archive' : Q27_OD;
                        $t->true(strpos(json_encode($r[1]), $need) !== false, "user2 tanpa List Archive: $label: parameter pesan = $need");
                    }
                    $use(Q27_PRESET_U2);
                    $t->status($t->call($s2, 'GET', Q27_BASE . '/archives?pagination=5'), 200, 'user2 preset U2 dipulihkan: archives 200');
                    q27_deny($t, $t->call($s2, 'GET', Q27_BASE . '/verifications'), 403, 'GE0114', 'user2 preset U2 dipulihkan: verifications tetap 403 (tanpa Opname Document)');
                    $t->call($s2, 'GET', 'api/v5/auth/log-out');
                });
                $t->eq(json_encode(q27_u2_snapshot($t)), json_encode($orig), 'user_roles QA_USER2 dipulihkan persis (hash baris identik)');
                $t->true(!is_file(q27_u2_journal()), 'jurnal user2 dibersihkan');
            } finally {
                q27_cleanup($t);
                q27_grant_recover($t);
                $t->eq(q27_rp_sum($t), $rpBefore, 'role_permissions dipulihkan persis (preset dibuang, tanpa sisa grant)');
            }
            $t->eq(json_encode(q27_u2_snapshot($t)), json_encode($orig), 'role user2 dipulihkan persis');
        },
    ],
];
