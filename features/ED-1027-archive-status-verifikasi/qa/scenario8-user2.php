<?php
/**
 * ED-1027 - user kedua sungguhan (QA_USER2 = Sales Supervisor, lokasi SMR, bahasa EN, bukan superadmin): X-8 (angka = oracle untuk user2,
 * tanpa folder ber-hak dan lokasi lain, detail 403 untuk folder terlarang, sesi terlihat user2 sesuai K-4), X-9 (user2 tanpa permission
 * Archive: 403 GE0114 pada kedua endpoint baru; role asli dipulihkan persis). Data uji dipulihkan di finally (q27_cleanup + jurnal role user2).
 */
require_once __DIR__ . '/qa_lib.php';

function q27_u2_journal()
{
    return __DIR__ . '/.restore-journal-q27-user2.json';
}

/** Token user2 terikat DB QA (force_login hanya mencabut token user2 sendiri). */
function q27_u2_session($t)
{
    $s = $t->login('QA_USER2');
    $r = $t->bind($s, q27_dbname($t));
    $t->eq($r[0], 200, 'bind QA_USER2');

    return $s;
}

function q27_u2_id($t)
{
    return $t->db()->table('users')->where('username', $t->conf('QA_USER2'))->value('id_user');
}

/** Ganti role user2 sementara; pulihkan persis dari jurnal. */
function q27_u2_roles($t, array $roles)
{
    $uid = q27_u2_id($t);
    q27_w($t, function ($c) use ($uid, $roles) {
        $now = date('Y-m-d H:i:s');
        $c->table('user_roles')->where('id_user', $uid)->delete();
        foreach ($roles as $role) {
            $c->table('user_roles')->insert(['id_user' => $uid, 'id_role' => $role, 'is_all_location' => 1, 'id_location' => null, 'created_at' => $now, 'updated_at' => $now]);
        }
    });
}

function q27_u2_snapshot($t)
{
    return q27_rows($t->db()->table('user_roles')->where('id_user', q27_u2_id($t))->orderBy('id_user_role')->get());
}

/** Pulihkan role user2 dari jurnal bila run sebelumnya mati di tengah X-9. */
function q27_u2_recover($t)
{
    if (is_file(q27_u2_journal())) {
        $snap = json_decode(file_get_contents(q27_u2_journal()), true);
        if (is_array($snap) && $snap) {
            q27_u2_restore($t, $snap);
        }
        @unlink(q27_u2_journal());
    }
}

function q27_u2_restore($t, array $snap)
{
    $uid = q27_u2_id($t);
    q27_w($t, function ($c) use ($uid, $snap) {
        $c->table('user_roles')->where('id_user', $uid)->delete();
        if ($snap) {
            $c->table('user_roles')->insert($snap);
        }
    });
}

return [

    [
        'id'    => 'X-8',
        'title' => 'QA_USER2 sungguhan (SMR, bukan superadmin): ringkasan, kolom folder, detail All & per folder = oracle & angka tangan; folder SV (hak khusus user lain), SP, SJ, JOGR terlarang 403; sesi terlihat sesuai K-4',
        'run'   => function ($t) {
            $s = $t->session();
            q27_baseline($t);
            q27_u2_recover($t);
            try {
                $x = q27_tree($t);
                $uid2 = q27_u2_id($t);
                $uname2 = (string) $t->db()->table('users')->where('id_user', $uid2)->value('username');
                $s2 = q27_u2_session($t);
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
                        q27_set_opname($t, $ids[$k], ['confirmed_at' => date('Y-m-d H:i:s', strtotime($when))]);
                        q27_set_owner($t, $ids[$k], 'otheruser');
                    }
                });
                $rv = $t->call($s2, 'GET', Q27_BASE . '/verifications');
                $t->status($rv, 200, 'user2: GET verifications dengan sesi');
                $seen = array_column($rv[1]['result']['last_sessions'], 'id_archive_opname');
                $t->eq($seen, [$ids['C'], $ids['A']], 'user2: sesi terlihat = C (root), A (F + S1); D (SJ) dan E (SP) tidak');
                $rf = $t->call($s2, 'GET', Q27_BASE . '/verifications/' . $x['F']);
                $t->eq(array_column($rf[1]['result']['last_sessions'], 'id_archive_opname'), [$ids['A']], 'user2: F: hanya sesi A');
                $t->true(isset($rf[1]['result']['last_sessions'][0]['folder_total_documents']), 'user2: F: slice ada');
                $t->eq(array_keys($rv[1]['result']['last_sessions'][0]), ['id_archive_opname', 'confirmed_at', 'created_by', 'scope', 'total_documents', 'verified_count', 'not_found_count', 'invalid_count'], 'user2: kunci session_row sesuai kontrak (urutan sama)');

                $t->call($s2, 'GET', 'api/v5/auth/log-out');
            } finally {
                q27_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'X-9',
        'title' => 'QA_USER2 tanpa permission List Archive (role sementara 22, tetap punya akses website): 403 GE0114 untuk kedua endpoint baru dan list; role asli dipulihkan persis',
        'run'   => function ($t) {
            $s = $t->session();
            q27_baseline($t);
            q27_u2_recover($t);
            $snap = q27_u2_snapshot($t);
            $t->true(count($snap) >= 1, 'prasyarat: user2 punya role');
            file_put_contents(q27_u2_journal(), json_encode($snap));
            try {
                $x = q27_tree($t);
                // role asli: 200
                $s2 = q27_u2_session($t);
                foreach (['verifications' => '/verifications', 'verifications/{F}' => '/verifications/' . $x['F'], 'archives' => '/archives?pagination=5'] as $label => $path) {
                    $t->status($t->call($s2, 'GET', Q27_BASE . $path), 200, "user2 role asli: $label");
                }
                q27_u2_roles($t, [22]); // role 22 'Pusat - Admin AR/AP': punya Allow Access Website, tanpa List Archive (role 6 mencabut token user non-karyawan di middleware website)
                foreach (['verifications' => '/verifications', 'verifications/{F}' => '/verifications/' . $x['F'], 'archives' => '/archives?pagination=5'] as $label => $path) {
                    $r = $t->call($s2, 'GET', Q27_BASE . $path);
                    q27_deny($t, $r, 403, 'GE0114', "user2 tanpa List Archive: $label");
                    $t->true(empty($r[1]['result']), "user2 tanpa List Archive: $label tanpa data");
                    $t->true(strpos(json_encode($r[1]), 'List Archive') !== false, "user2 tanpa List Archive: $label: parameter pesan = List Archive");
                }
                q27_u2_restore($t, $snap);
                foreach (['verifications' => '/verifications', 'verifications/{F}' => '/verifications/' . $x['F']] as $label => $path) {
                    $t->status($t->call($s2, 'GET', Q27_BASE . $path), 200, "user2 role dipulihkan: $label");
                }
                $t->call($s2, 'GET', 'api/v5/auth/log-out');
            } finally {
                q27_u2_restore($t, $snap);
                q27_cleanup($t);
            }
            $t->eq(json_encode(q27_u2_snapshot($t)), json_encode($snap), 'role user2 dipulihkan persis');
            @unlink(q27_u2_journal());
        },
    ],
];
