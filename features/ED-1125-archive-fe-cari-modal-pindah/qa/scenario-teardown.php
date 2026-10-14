<?php
/**
 * ED-1125 - bukti pembersihan sesudah run QA FE (BACA-SAJA: hanya SELECT; tidak login, tidak menulis, tidak mencetak token).
 * Dijalankan sesudah semua e2e: "$PHP_BIN" scripts/qa-http/run.php ED-1125 --only=CLEAN-1,CLEAN-2,SESI-1
 *
 *   CLEAN-1  tidak ada baris berawalan "0QA125-" di archives / archive_documents (fixture e2e dibuang persis)
 *   CLEAN-2  bahasa QA_USER = ID (syarat spec "Data uji"; e2e ED-1025 AC-40 en memulihkannya)
 *   SESI-1   QA_USER, QA_USER2, QA_USER3 (sam) tidak punya token aktif (revoked=0 dan belum kedaluwarsa) di oauth_access_tokens
 *
 * Bukan skenario regresi BE: item ini FE saja (contract: null).
 */
return [
    [
        'id'    => 'CLEAN-1',
        'title' => 'Sesudah e2e: tidak ada baris berawalan 0QA125- di archives dan archive_documents',
        'run'   => function ($t) {
            $db = $t->db($t->dbs()[0]);
            $arch = $db->table('archives')->where('name', 'like', '0QA125-%')->count();
            $docs = $db->table('archive_documents')->where('transaction_no', 'like', '0QA125-%')->count();
            $t->note("archives 0QA125-%: {$arch}; archive_documents 0QA125-%: {$docs}");
            $t->eq($arch, 0, 'baris archives berawalan 0QA125-');
            $t->eq($docs, 0, 'baris archive_documents berawalan 0QA125-');
        },
    ],
    [
        'id'    => 'CLEAN-2',
        'title' => 'Bahasa QA_USER = ID sesudah semua run',
        'run'   => function ($t) {
            $username = $t->conf('QA_USER');
            $db = $t->db($t->dbs()[0]);
            $lang = $db->table('users')->where('username', $username)->value('language');
            $t->note('bahasa QA_USER: ' . json_encode($lang));
            $t->eq($lang, 'ID', 'bahasa QA_USER');
        },
    ],
    [
        'id'    => 'SESI-1',
        'title' => 'Sesudah e2e: QA_USER, QA_USER2, QA_USER3 tidak punya token aktif',
        'run'   => function ($t) {
            $db = $t->db($t->globalDb());
            $schema = $db->getSchemaBuilder();
            $idCol = null;
            foreach (['id', 'id_user', 'user_id'] as $c) {
                if ($schema->hasColumn('users', $c)) {
                    $idCol = $c;
                    break;
                }
            }
            if ($idCol === null) {
                $t->blocked('kolom id user di tabel users (DB default) tidak ditemukan');
            }
            foreach (['QA_USER', 'QA_USER2', 'QA_USER3'] as $key) {
                $username = $t->conf($key);
                if (!$username) {
                    $t->note("{$key}: tidak diisi, dilewati");
                    continue;
                }
                $users = $db->table('users')->where('username', $username)->pluck($idCol);
                $t->true(count($users) >= 1, "{$key} ada di tabel users (DB default)");
                $active = $db->table('oauth_access_tokens')
                    ->whereIn('user_id', $users->all())
                    ->where('revoked', 0)
                    ->where(function ($q) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>', date('Y-m-d H:i:s'));
                    })
                    ->count();
                $t->note("token aktif {$key}: {$active}");
                $t->eq($active, 0, "token aktif {$key}");
            }
        },
    ],
];
