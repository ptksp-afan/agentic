<?php
/**
 * ED-1251 - keadaan awal untuk guard C-9 / teardown. Dijalankan PERTAMA (berkas terurut paling awal) pada run penuh; hanya
 * membaca DB (ditambah pemulihan sisa run yang mati), menulis work/ed1251/initial-state.json (gitignored).
 */
require_once __DIR__ . '/qa_lib.php';

return [
    [
        'id'    => 'C-0',
        'title' => 'catat keadaan awal (user QA, cds/template documentArchiveOpnameResult, CRC template lain) untuk guard C-9 dan teardown',
        'run'   => function ($t) {
            $c = $t->db();
            // sisa run yang mati dipulihkan lebih dulu (jurnal user, user2, grant, preset, sesi, sisipan massal, data QA251-)
            q251_u2_recover($t);
            q251_baseline($t);
            q251_tpl_recover($t);
            $uid = q251_uid($t);
            $u2 = q251_u2_id($t);
            $state = [
                'taken_at'        => date('c'),
                'devstate'        => q251_devstate($t),
                'user'            => q251_user_snapshot($t),
                'user2_roles'     => q251_u2_snapshot($t),
                'rp'              => q251_rp_sum($t),
                'tpl_own'         => q251_tpl_row($t),
                'tpl_other'       => q251_tpl_other($t),
                'cds_own'         => q251_cds_rows($t),
                'cds_all'         => array_map(function ($r) { return $r['module_name']; }, q251_rows($c->table('column_display_settings')->orderBy('id_column_display_setting')->get(['module_name']))),
                'updater_logs'    => $c->table('updater_logs')->count(),
                'oauth_active_qa' => $c->table('oauth_access_tokens')->whereIn('user_id', [$uid, $u2])->where('revoked', 0)->count(),
                'roles_qa'        => $c->table('roles')->where('role_name', 'like', 'QA%-ROLE-%')->count(),
            ];
            @mkdir(QA_AGENTIC . '/work/ed1251', 0775, true);
            file_put_contents(QA_AGENTIC . '/work/ed1251/initial-state.json', json_encode($state));
            $t->note('keadaan awal dicatat: template sendiri ' . count($state['tpl_own']) . ' baris, cds sendiri ' . count($state['cds_own']) . ' baris, template lain ' . $state['tpl_other'][0] . ' baris, preset QA*-ROLE-* ' . $state['roles_qa']);
        },
    ],
];
