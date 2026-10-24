<?php
/**
 * ED-1250 - teardown (spec §6a, C-8): dijalankan TERAKHIR (berkas terurut paling akhir), hanya membaca.
 *   C-8  setelah run: tidak ada sisa QA50- (archives, archive_documents, archive_permissions, role preset, role_permissions), baris cds
 *        documentArchive wajib memuat verificationStatus (current & available) dan documentVerified, user QA (user_roles, bahasa, lokasi
 *        karyawan) sama dengan keadaan awal run, keadaan di luar baris sendiri sama persis (CRC), tidak ada jurnal basi di features/*\/qa.
 *        (Token QA aktif dicek sesudah run, bukan di dalam run: sesi runner masih hidup.) Beda updater_logs / cds sebelum-vs-sesudah Updater = diizinkan (DU-5 a), hanya dicatat.
 * Keadaan awal: work/ed1250/initial-state.json, dicatat oleh C-0 (scenario0-init.php) di awal run penuh; pemulihan tiap AC juga dibuktikan di AC itu.
 */
require_once __DIR__ . '/qa_lib.php';

return [
    [
        'id'    => 'C-8',
        'title' => 'teardown: tanpa sisa QA50- / preset / jurnal basi; baris cds documentArchive memuat verificationStatus (current & available) dan documentVerified; user QA & keadaan di luar baris sendiri sama dengan awal',
        'run'   => function ($t) {
            $file = QA_AGENTIC . '/work/ed1250/initial-state.json';
            $init = is_file($file) ? json_decode(file_get_contents($file), true) : null;
            if ($init === null) {
                $t->note('C-0 tidak dijalankan di run ini (--only): perbandingan dengan keadaan awal dilewati, hanya invarian yang diperiksa');
            }
            $c = $t->db();

            // sisa data uji
            $t->eq($c->table('archives')->where('name', 'like', Q50_PREFIX . '%')->count(), 0, 'archives QA50- = 0');
            $t->eq($c->table('archive_documents')->where('transaction_no', 'like', Q50_PREFIX . '%')->count(), 0, 'archive_documents QA50- = 0');
            $t->eq($c->table('archive_permissions')->where('created_by', 'QA50')->count(), 0, 'archive_permissions QA50 = 0');
            $t->eq($c->table('roles')->where('role_name', 'like', 'QA%')->count(), 0, 'role QA% (preset QA50-ROLE-*, QA27-ROLE-*, QA2%-ROLE-*) = 0');
            $t->eq($c->table('role_permissions')->where('created_by', 'like', 'QA%')->count(), 0, 'role_permissions created_by QA% = 0');

            // baris cds: wajib memuat entri; sama persis dengan keadaan sesudah Updater
            $row = q50_cds_row($t);
            $t->eq(count($row), 1, 'tepat 1 baris cds documentArchive');
            $cq = q50_seq(json_decode($row[0]['current_queries'], true));
            $aq = q50_seq(json_decode($row[0]['available_queries'], true));
            $cc = q50_seq(json_decode($row[0]['current_columns'], true));
            $ac = q50_seq(json_decode($row[0]['available_columns'], true));
            $t->true(in_array('verificationStatus', $cq, true) && in_array('verificationStatus', $aq, true), 'cds: verificationStatus di current & available queries (C-8)');
            $t->true(in_array('documentVerified', $cc, true) && in_array('documentVerified', $ac, true), 'cds: documentVerified di current & available columns (C-8)');
            if ($init !== null) {
                $t->eq(json_encode($row), json_encode($init['cds']), 'cds documentArchive sama persis dengan keadaan awal run (= sesudah Updater: id, isi, created_at, updated_at)');
            }

            // user QA
            if ($init !== null) {
                $t->eq(json_encode(q50_user_snapshot($t)), json_encode($init['user']), 'QA_USER: user_roles, bahasa, lokasi karyawan sama dengan awal');
                $t->eq(json_encode(q50_rows($c->table('user_roles')->whereIn('id_user', [q50_uid($t), q50_u2_id($t)])->orderBy('id_user_role')->get())), json_encode($init['user_roles_qa']), 'user_roles QA_USER + QA_USER2 sama dengan awal');
            }

            // keadaan di luar baris sendiri
            if ($init !== null) {
                $now = q50_devstate($t);
                $diff = [];
                foreach ($init['devstate'] as $k => $v) {
                    if (json_encode($v) !== json_encode($now[$k])) {
                        $diff[] = $k . ' ' . json_encode($v) . ' -> ' . json_encode($now[$k]);
                    }
                }
                $t->true($diff === [], 'keadaan di luar baris sendiri sama persis dengan awal (' . implode('; ', $diff) . ')');
            }

            // jurnal basi
            $stale = [];
            foreach (glob(QA_AGENTIC . '/features/*/qa/.*journal*') as $f) {
                $stale[] = basename(dirname(dirname($f))) . '/' . basename($f);
            }
            foreach (glob(QA_AGENTIC . '/features/*/qa/.fixture-journal.json') as $f) {
                $stale[] = basename(dirname(dirname($f))) . '/' . basename($f);
            }
            $t->true($stale === [], 'tidak ada jurnal basi di features/*/qa (' . implode(', ', array_unique($stale)) . ')');

            // token QA aktif: sesi runner sendiri masih hidup di dalam run ini; pencabutannya dilaporkan runner di baris akhir
            // ("Token run: ... masih aktif: 0") dan dicek ulang sesudah run (oauth_access_tokens revoked = 0 untuk user QA)

            // dicatat (diizinkan)
            $ul = $c->table('updater_logs')->count();
            $t->note('updater_logs sekarang ' . $ul . ($init !== null ? ' (awal run ' . $init['updater_logs'] . ($ul === (int) $init['updater_logs'] ? ', sama' : ', beda: diizinkan, DU-5 a') . ')' : ''));
        },
    ],
];
