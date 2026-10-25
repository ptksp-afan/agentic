<?php
/**
 * ED-1251 - teardown (C-9 / gate1 §5): dijalankan TERAKHIR pada run penuh. Membuktikan tanpa sisa 0QA251- / QA251BULK / preset
 * QA251-ROLE-* / jurnal basi, user QA (user_roles, bahasa, lokasi) dan role_permissions sama dengan awal, template lain
 * (transaction_pdf_templates selain documentArchiveOpnameResult) dan baris cds lain sama persis, tidak ada nama template baru
 * selain documentArchiveOpnameResult, data developer sama persis. Dengan --only (tanpa C-0) hanya invarian yang tak butuh keadaan awal.
 */
require_once __DIR__ . '/qa_check.php';

return [
    [
        'id'    => 'C-9',
        'title' => 'teardown + guard C-9: tanpa sisa data/preset/jurnal; template & cds lain sama persis; user QA dan keadaan developer sama dengan awal',
        'run'   => function ($t) {
            $c = $t->db();
            // sisa apa pun dari run yang mati dipulihkan lebih dulu, lalu dibuktikan bersih
            q251_u2_recover($t);
            q251_baseline($t);
            q251_tpl_recover($t);
            q251_cleanup($t, 'teardown');
            $t->eq($c->table('archives')->where('name', 'like', '%QA251%')->count(), 0, 'tanpa archives berawalan QA251');
            $t->eq($c->table('archive_opnames')->where('id_archive_opname', 'like', 'QA251%')->count(), 0, 'tanpa sesi sisipan QA251BULK*');
            $t->eq($c->table('archive_opname_documents')->where('id_archive_opname', 'like', 'QA251%')->count(), 0, 'tanpa baris dokumen sisipan');
            $t->eq($c->table('roles')->where('role_name', 'like', 'QA%-ROLE-%')->count(), 0, 'tanpa preset QA*-ROLE-* (e2e ED-1154 P-1 tidak terblokir)');
            $t->eq($c->table('role_permissions')->where('created_by', 'like', 'QA%')->count(), 0, 'tanpa baris role_permissions buatan QA');
            foreach (glob(__DIR__ . '/.*-q251*.json') as $j) {
                $t->fail('jurnal basi: ' . basename($j));
            }
            $uid = q251_uid($t);
            $u2 = q251_u2_id($t);
            // token milik runner sendiri (QA_USER, dicabut runner di akhir run) boleh ada satu; QA_USER2 tidak boleh punya token aktif
            $t->eq($c->table('oauth_access_tokens')->where('user_id', $u2)->where('revoked', 0)->count(), 0, 'tanpa token aktif QA_USER2 (semua sesi user2 di-log-out)');
            $t->true($c->table('oauth_access_tokens')->where('user_id', $uid)->where('revoked', 0)->count() <= 1, 'QA_USER: paling banyak 1 token aktif (milik runner, dicabut di akhir run)');
            // template: nama selain miliknya tidak bertambah/berkurang
            $names = $c->table('transaction_pdf_templates')->pluck('transaction_name')->all();
            $t->true(count($names) === count(array_unique($names)) || count(array_filter(array_count_values($names), function ($n) {
                return $n > 1;
            })) === count(array_filter(array_count_values($names), function ($n, $k) {
                return $n > 1 && $k !== Q251_MODULE;
            }, ARRAY_FILTER_USE_BOTH)), 'baris template milik sendiri tidak ganda');
            $t->true($c->table('transaction_pdf_templates')->where('transaction_name', Q251_MODULE)->count() <= 1, 'paling banyak satu baris documentArchiveOpnameResult');

            $file = QA_AGENTIC . '/work/ed1251/initial-state.json';
            if (!is_file($file)) {
                $t->note('initial-state.json tidak ada (run dengan --only tanpa C-0): perbandingan dengan keadaan awal dilewati');

                return;
            }
            $st = json_decode(file_get_contents($file), true);
            $t->eq(q251_tpl_other($t), $st['tpl_other'], 'C-9: transaction_pdf_templates selain milik sendiri sama persis (count + checksum)');
            $t->eq(json_encode(q251_user_snapshot($t)), json_encode($st['user']), 'user_roles/bahasa/lokasi QA_USER sama dengan awal');
            $t->eq(json_encode(q251_u2_snapshot($t)), json_encode($st['user2_roles']), 'user_roles QA_USER2 sama dengan awal');
            $t->eq(json_encode(q251_rp_sum($t)), json_encode($st['rp']), 'role_permissions sama dengan awal (count + checksum)');
            $t->eq(json_encode(q251_devstate($t)), json_encode($st['devstate']), 'data developer (3 tabel opname + archives non-QA) sama persis dengan awal');
            $cdsAll = array_map(function ($r) {
                return $r['module_name'];
            }, q251_rows($c->table('column_display_settings')->orderBy('id_column_display_setting')->get(['module_name'])));
            $t->eq(array_values(array_diff($cdsAll, [Q251_MODULE])), array_values(array_diff($st['cds_all'], [Q251_MODULE])), 'baris cds modul lain sama dengan awal (tidak ada yang ditambah/dibuang)');
            $t->eq($c->table('updater_logs')->count(), $st['updater_logs'], 'updater_logs tidak berubah');
            $t->eq($c->table('roles')->where('role_name', 'like', 'QA%-ROLE-%')->count(), $st['roles_qa'], 'jumlah preset QA*-ROLE-* sama dengan awal');
            $t->note('baris cds sendiri akhir: ' . count(q251_cds_rows($t)) . ', template sendiri akhir: ' . count(q251_tpl_row($t)) . ' (awal: ' . count($st['cds_own']) . ' / ' . count($st['tpl_own']) . ')');
        },
    ],
];
