<?php
/**
 * ED-1251 e2e - setup/teardown fixture (BUKAN regresi BE; folder ini dipakai sebagai "folder fitur" runner oleh qa/e2e/fixtures.json:
 *   php scripts/qa-http/run.php features/ED-1251-*\/qa/e2e/fixrun --only=FIX-UP | --only=FIX-DOWN).
 * Memakai helper BE qa/qa_lib.php (journal di qa/.*-q251.json). Login runner (force_login QA_USER) hanya terjadi di setup/teardown,
 * tidak pernah di tengah spec (spec memakai fixture.php yang tanpa login).
 *
 * FIX-UP   pulihkan sisa run mati; catat keadaan awal (QA_USER, template/cds sendiri, template lain, data developer); jurnalkan user dan
 *          baris cds+template sendiri; buat S1 (folder F + subfolder A,B) dan S2 (folder G) lewat POST opnames oleh QA_USER
 *          (q251_fixture); tulis qa/e2e/.fixture-ids.json
 * FIX-DOWN pulihkan user, baris cds+template sendiri (persis), buang sesi + folder + dokumen 0QA251-, buktikan template lain sama
 *          persis (C-9), data developer sama persis, tanpa preset QA251-ROLE-*
 */
require_once __DIR__ . '/../../../qa_check.php';

if (!function_exists('e2e251_ids_file')) {
    function e2e251_ids_file()
    {
        return realpath(__DIR__ . '/../..') . '/.fixture-ids.json';
    }

    function e2e251_state_file()
    {
        return QA_AGENTIC . '/work/ed1251/fe-initial-state.json';
    }
}

return [
    [
        'id'    => 'FIX-UP',
        'title' => 'setup e2e FE ED-1251: S1/S2 lewat POST opnames oleh QA_USER, jurnal user + baris cds/template sendiri',
        'run'   => function ($t) {
            $s = $t->session(); // QA_USER login (force_login) SEBELUM spec membuka browser
            $c = $t->db();
            q251_u2_recover($t);
            q251_baseline($t); // sisa run mati (user, grant, preset, sesi, data QA251-) dibuang; data developer dicatat
            q251_tpl_recover($t);
            $uid = q251_uid($t);
            $u2 = q251_u2_id($t);
            $state = [
                'taken_at'     => date('c'),
                'devstate'     => q251_devstate($t),
                'user'         => q251_user_snapshot($t),
                'user2_roles'  => q251_u2_snapshot($t),
                'rp'           => q251_rp_sum($t),
                'tpl_own'      => q251_tpl_row($t),
                'tpl_other'    => q251_tpl_other($t),
                'tpl_names'    => $c->table('transaction_pdf_templates')->pluck('transaction_name')->all(),
                'cds_own'      => q251_cds_rows($t),
                'cds_all'      => array_map(function ($r) {
                    return $r['module_name'];
                }, q251_rows($c->table('column_display_settings')->orderBy('id_column_display_setting')->get(['module_name']))),
                'updater_logs' => $c->table('updater_logs')->count(),
                'roles_qa'     => $c->table('roles')->where('role_name', 'like', 'QA%-ROLE-%')->count(),
            ];
            @mkdir(QA_AGENTIC . '/work/ed1251', 0775, true);
            file_put_contents(e2e251_state_file(), json_encode($state));
            // jurnal pemulihan: user QA_USER (dipulihkan q251_baseline -> q251_recover_user) dan baris cds+template sendiri (q251_tpl_recover)
            file_put_contents(q251_journal(), json_encode($state['user']));
            q251_tpl_save($t);

            list($x, $ids) = q251_fixture($t, $s);
            $out = ['ids' => $ids, 'folders' => ['F' => $x['F'], 'A' => $x['A'], 'B' => $x['B'], 'G' => $x['G'], 'OUT' => $x['OUT']],
                'names' => $x['n'], 'invalid' => $x['invalid'], 'sessions' => []];
            foreach (['F', 'A', 'B', 'G', 'OUT'] as $k) {
                $out['folder_names'][$k] = $c->table('archives')->where('id_archive', $x[$k])->value('name');
            }
            foreach ($ids as $k => $id) {
                $o = q251_opname($t, $id);
                $out['sessions'][$k] = [
                    'id' => $id, 'confirmed_at' => $o['confirmed_at'], 'created_by' => $o['created_by'], 'scope_name' => $o['scope_name'] ?? null,
                    'scope_folder_count' => $o['scope_folder_count'] ?? null,
                    'counts' => [(int) $o['total_documents'], (int) $o['verified_count'], (int) $o['not_found_count'], (int) $o['invalid_count'], (int) $o['unscanned_count']],
                ];
            }
            $out['lang'] = $state['user']['lang'];
            $out['user'] = q251_uname($t);
            file_put_contents(e2e251_ids_file(), json_encode($out));
            $t->note('S1=' . $ids['S1'] . ' S2=' . $ids['S2'] . ' dibuat; template sendiri ' . count($state['tpl_own']) . ' baris, cds sendiri ' . count($state['cds_own']) . ' baris; bahasa QA_USER ' . $state['user']['lang']);
        },
    ],
    [
        'id'    => 'FIX-DOWN',
        'title' => 'teardown e2e FE ED-1251: buang S1/S2 + data 0QA251-, pulihkan user/cds/template persis, buktikan C-9 dan data developer',
        'run'   => function ($t) {
            $t->session();
            $c = $t->db();
            q251_u2_recover($t);
            q251_baseline($t);      // sweep sesi tercatat, buang data QA251-, pulihkan user (jurnal) -> baseline baru
            q251_tpl_recover($t);   // pulihkan baris cds+template sendiri persis seperti sebelum FIX-UP
            q251_cleanup($t, 'teardown');
            @unlink(e2e251_ids_file());
            $t->eq($c->table('roles')->where('role_name', 'like', 'QA%-ROLE-%')->count(), 0, 'tanpa preset QA*-ROLE-* (e2e ED-1154 P-1 tidak terblokir)');
            $t->eq($c->table('archives')->where('name', 'like', '%QA251%')->count(), 0, 'tanpa archives berawalan QA251');
            foreach (glob(__DIR__ . '/../../../.*-q251*.json') as $j) {
                $t->fail('jurnal basi: ' . basename($j));
            }
            $file = e2e251_state_file();
            if (!is_file($file)) {
                $t->fail('keadaan awal (work/ed1251/fe-initial-state.json) tidak ada: FIX-UP tidak pernah jalan');
            }
            $st = json_decode(file_get_contents($file), true);
            $t->eq(q251_tpl_other($t), $st['tpl_other'], 'C-9: transaction_pdf_templates selain milik sendiri sama persis (count + checksum)');
            $t->eq(json_encode(q251_tpl_row($t)), json_encode($st['tpl_own']), 'baris template sendiri sama persis dengan awal (' . count($st['tpl_own']) . ' baris)');
            $t->eq(json_encode(q251_cds_rows($t)), json_encode($st['cds_own']), 'baris cds sendiri sama persis dengan awal (' . count($st['cds_own']) . ' baris)');
            $t->eq(json_encode($c->table('transaction_pdf_templates')->pluck('transaction_name')->all()), json_encode($st['tpl_names']), 'tidak ada nama template baru');
            $t->eq(json_encode(q251_user_snapshot($t)), json_encode($st['user']), 'user_roles/bahasa/lokasi QA_USER sama dengan awal');
            $t->eq(json_encode(q251_u2_snapshot($t)), json_encode($st['user2_roles']), 'user_roles QA_USER2 sama dengan awal');
            $t->eq(json_encode(q251_rp_sum($t)), json_encode($st['rp']), 'role_permissions sama dengan awal');
            $t->eq(json_encode(q251_devstate($t)), json_encode($st['devstate']), 'data developer (3 tabel opname + archives non-QA) sama persis dengan awal');
            $cdsAll = array_map(function ($r) {
                return $r['module_name'];
            }, q251_rows($c->table('column_display_settings')->orderBy('id_column_display_setting')->get(['module_name'])));
            $t->eq($cdsAll, $st['cds_all'], 'baris cds semua modul sama dengan awal');
            $t->eq($c->table('updater_logs')->count(), $st['updater_logs'], 'updater_logs tidak berubah');
            $t->eq($c->table('roles')->where('role_name', 'like', 'QA%-ROLE-%')->count(), $st['roles_qa'], 'jumlah preset QA*-ROLE-* sama dengan awal');
        },
    ],
];
