<?php
/**
 * ED-1150 - pemeriksaan pencabutan sesi `sam` (QA_USER3) sesudah run e2e (spec: "Data uji", K-4 a). BACA-SAJA: hanya SELECT di
 * oauth_access_tokens (koneksi default BE) untuk user QA_USER3; tidak login, tidak menulis. Tidak mencetak token/kredensial.
 * Jalankan setelah e2e: "$PHP_BIN" scripts/qa-http/run.php ED-1150 --only=SESI-1
 * Syarat: developer tidak memakai sesi sam selama run (K-4 a); sesi aktif milik sam = sisa run e2e = PASS gagal.
 */
return [
    [
        'id'    => 'SESI-1',
        'title' => 'Sesudah e2e: sam (QA_USER3) tidak punya token aktif (revoked=0 dan belum kedaluwarsa) di oauth_access_tokens',
        'run'   => function ($t) {
            $username = $t->conf('QA_USER3');
            if (!$username) {
                $t->skip('QA_USER3 belum diisi');
            }
            $global = $t->globalDb();
            $db = $t->db($global);
            $schema = $db->getSchemaBuilder();
            $userCol = null;
            foreach (['username', 'email', 'user_name'] as $c) {
                if ($schema->hasColumn('users', $c)) {
                    $userCol = $c;
                    break;
                }
            }
            if ($userCol === null) {
                $t->blocked('kolom username di tabel users (DB default) tidak ditemukan');
            }
            $cols = $schema->getColumnListing('users');
            $idCol = null;
            foreach (['id', 'id_user', 'user_id'] as $c) {
                if (in_array($c, $cols, true)) {
                    $idCol = $c;
                    break;
                }
            }
            if ($idCol === null) {
                $t->blocked('kolom id user di tabel users tidak ditemukan (kolom: ' . implode(',', $cols) . ')');
            }
            $users = $db->table('users')->where($userCol, $username)->pluck($idCol);
            $t->note('user QA_USER3 ditemukan di users: ' . count($users) . ' baris');
            $t->true(count($users) >= 1, 'QA_USER3 ada di tabel users (DB default)');
            $active = $db->table('oauth_access_tokens')
                ->whereIn('user_id', $users->all())
                ->where('revoked', 0)
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', date('Y-m-d H:i:s'));
                })
                ->count();
            $t->note("token aktif sam: {$active}");
            $t->eq($active, 0, 'token aktif sam sesudah run e2e');
        },
    ],
];
