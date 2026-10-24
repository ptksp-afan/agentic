<?php
/**
 * ED-1250 - keadaan awal untuk teardown (C-8). Dijalankan PERTAMA (berkas terurut paling awal) pada run penuh; hanya membaca DB,
 * menulis work/ed1250/initial-state.json (di luar repo aplikasi, gitignored). Dengan --only dilewati: C-8 lalu hanya memeriksa
 * invarian yang tidak butuh keadaan awal.
 */
require_once __DIR__ . '/qa_lib.php';

return [
    [
        'id'    => 'C-0',
        'title' => 'catat keadaan awal (user QA, baris cds documentArchive, CRC tabel di luar baris sendiri) untuk teardown C-8',
        'run'   => function ($t) {
            $c = $t->db();
            // sisa run yang mati (jurnal user / user2 / cds) dipulihkan lebih dulu, sebelum keadaan awal dicatat
            q50_recover_user($t);
            q50_u2_recover($t);
            q50_cds_recover($t);
            $uid = q50_uid($t);
            $u2 = q50_u2_id($t);
            $state = [
                'taken_at'        => date('c'),
                'devstate'        => q50_devstate($t),
                'user_roles_qa'   => q50_rows($c->table('user_roles')->whereIn('id_user', [$uid, $u2])->orderBy('id_user_role')->get()),
                'user'            => q50_user_snapshot($t),
                'cds'             => q50_cds_row($t),
                'updater_logs'    => $c->table('updater_logs')->count(),
                'oauth_active_qa' => $c->table('oauth_access_tokens')->whereIn('user_id', [$uid, $u2])->where('revoked', 0)->count(),
            ];
            $t->true(count($state['cds']) === 1, 'prasyarat: 1 baris cds documentArchive');
            @mkdir(QA_AGENTIC . '/work/ed1250', 0775, true);
            file_put_contents(QA_AGENTIC . '/work/ed1250/initial-state.json', json_encode($state));
            $t->note('keadaan awal dicatat: cds id ' . $state['cds'][0]['id_column_display_setting'] . ', updated_at ' . $state['cds'][0]['updated_at'] . ', updater_logs ' . $state['updater_logs']);
        },
    ],
];
