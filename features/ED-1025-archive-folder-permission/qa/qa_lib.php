<?php
/**
 * Helper bersama skenario ED-1025 (BUKAN skenario: nama berkas tidak cocok scenario*.php).
 * Di-require_once oleh setiap scenario*.php.
 *
 * Aturan pakai:
 * - QA_USER2 tidak bisa login di api_sidomaju, jadi "user tanpa hak" = QA_USER sendiri dengan keadaan sementara:
 *   role (user_roles) dan bahasa lewat q2_with_user(); hak folder lewat baris archive_permissions / is_folder_permission /
 *   created_by pada folder uji berawalan "QA02-" (q2_add/q2_perm). Semua dipulihkan di finally. Jurnal pemulihan user ada
 *   di .restore-journal-q2.json; bila run mati di tengah, run berikutnya memulihkan dari jurnal itu lebih dulu.
 * - Tulis lewat koneksi default non-read-only di dalam $t->probe(); baca lewat $t->db() (read-only).
 * - Role bawaan QA_USER = 3 (Super Admin, is_superadmin 3: TIDAK bypass hak folder). Bypass = role 1 dan 2.
 */

if (!function_exists('q2_dbname')) {

    define('Q2_PREFIX', 'QA02-');
    define('Q2_ROLE_PLAIN', 3);

    function q2_dbname($t)
    {
        return $t->dbs()[0];
    }

    /** Jalankan $fn($conn) dengan koneksi tulis ke DB QA (di dalam probe). */
    function q2_w($t, callable $fn)
    {
        $db = q2_dbname($t);

        return $t->probe(function () use ($fn, $db) {
            return $fn(\Illuminate\Support\Facades\DB::connection($db));
        });
    }

    function q2_rows($collection)
    {
        $out = [];
        foreach ($collection as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }

    function q2_journal()
    {
        return __DIR__ . '/.restore-journal-q2.json';
    }

    function q2_uid($t)
    {
        return $t->db()->table('users')->where('username', $t->conf('QA_USER'))->value('id_user');
    }

    function q2_uname($t)
    {
        return $t->db()->table('users')->where('username', $t->conf('QA_USER'))->value('username');
    }

    // ------------------------------------------------------------------ user QA (role / bahasa)

    function q2_user_snapshot($t)
    {
        $c = $t->db();
        $uid = q2_uid($t);

        return [
            'uid'   => $uid,
            'roles' => q2_rows($c->table('user_roles')->where('id_user', $uid)->orderBy('id_user_role')->get()),
            'lang'  => $c->table('users')->where('id_user', $uid)->value('language'),
        ];
    }

    function q2_user_restore($t, array $snap)
    {
        q2_w($t, function ($c) use ($snap) {
            $uid = $snap['uid'];
            $c->table('user_roles')->where('id_user', $uid)->delete();
            if ($snap['roles']) {
                $c->table('user_roles')->insert($snap['roles']);
            }
            $c->table('users')->where('id_user', $uid)->update(['language' => $snap['lang']]);
        });
    }

    /** opts: 'role' => int|int[] (id_role), 'lang' => 'ID'|'EN' */
    function q2_user_apply($t, array $snap, array $opts)
    {
        q2_w($t, function ($c) use ($snap, $opts) {
            $uid = $snap['uid'];
            $now = date('Y-m-d H:i:s');

            if (isset($opts['role'])) {
                $c->table('user_roles')->where('id_user', $uid)->delete();
                foreach ((array) $opts['role'] as $role) {
                    $c->table('user_roles')->insert([
                        'id_user' => $uid, 'id_role' => $role, 'is_all_location' => 1, 'id_location' => null,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }

            if (isset($opts['lang'])) {
                $c->table('users')->where('id_user', $uid)->update(['language' => $opts['lang']]);
            }
        });
    }

    function q2_recover($t)
    {
        if (is_file(q2_journal())) {
            $snap = json_decode(file_get_contents(q2_journal()), true);
            if (is_array($snap)) {
                q2_user_restore($t, $snap);
            }
            @unlink(q2_journal());
        }
    }

    /**
     * Jalankan $fn($set) dengan user QA di keadaan $opts. $set(array $opts) mengganti keadaan (selalu relatif terhadap
     * keadaan asli). Keadaan asli dipulihkan dan diverifikasi persis di finally.
     */
    function q2_with_user($t, array $opts, callable $fn)
    {
        q2_recover($t);
        $snap = q2_user_snapshot($t);
        file_put_contents(q2_journal(), json_encode($snap));

        $set = function (array $o) use ($t, $snap) {
            q2_user_restore($t, $snap);
            q2_user_apply($t, $snap, $o);
        };

        try {
            $set($opts);

            return $fn($set);
        } finally {
            q2_user_restore($t, $snap);
            $after = q2_user_snapshot($t);
            if (json_encode($after) !== json_encode($snap)) {
                $t->fail('pemulihan user QA tidak persis sama dengan sebelumnya (jurnal dipertahankan)');
            }
            @unlink(q2_journal());
        }
    }

    // ------------------------------------------------------------------ data uji archive

    function q2_new_id($t)
    {
        return q2_w($t, function ($c) {
            return \Modules\V5\Entities\Helper\MyHelper::generateId();
        });
    }

    /** Nama unik berawalan QA02-. */
    function q2_name($label)
    {
        return Q2_PREFIX . $label . '-' . substr(uniqid(), -6) . mt_rand(10, 99);
    }

    /**
     * Sisipkan satu baris archive uji.
     * $a: name, type (1 folder / 2 dokumen), parent, perm (is_folder_permission 0/1), by (created_by, default 'QA02'),
     *     active (default 1), doc => ['type' => int] untuk dokumen.
     * @return string id_archive
     */
    function q2_add($t, array $a)
    {
        $id = q2_new_id($t);
        $now = date('Y-m-d H:i:s');

        q2_w($t, function ($c) use ($a, $id, $now) {
            $c->table('archives')->insert([
                'id_archive'           => $id,
                'id_archive_parent'    => $a['parent'] ?? null,
                'name'                 => $a['name'],
                'type'                 => $a['type'] ?? 1,
                'status'               => 1,
                'is_all_location'      => 1,
                'is_folder_permission' => $a['perm'] ?? 0,
                'is_active'            => $a['active'] ?? 1,
                'created_at'           => $now,
                'created_by'           => $a['by'] ?? 'QA02',
                'updated_at'           => $now,
                'updated_by'           => $a['by'] ?? 'QA02',
            ]);

            if (($a['type'] ?? 1) == 2) {
                $doc = $a['doc'] ?? [];
                $c->table('archive_documents')->insert([
                    'id_archive_document' => \Modules\V5\Entities\Helper\MyHelper::generateId(),
                    'id_archive'          => $id,
                    'id_transaction'      => 'QA02TX' . substr($id, -10),
                    'transaction_no'      => $a['name'],
                    'transaction_type'    => $doc['type'] ?? 6,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
            }
        });

        return $id;
    }

    function q2_folder($t, $label, array $a = [])
    {
        return q2_add($t, $a + ['name' => q2_name($label), 'type' => 1]);
    }

    function q2_doc($t, $label, array $a = [])
    {
        return q2_add($t, $a + ['name' => q2_name($label), 'type' => 2]);
    }

    /** Sisipkan baris archive_permissions (hak 0/1). */
    function q2_perm($t, $idArchive, $idUser, $view, $update = 0, $delete = 0, $store = 0)
    {
        $now = date('Y-m-d H:i:s');
        q2_w($t, function ($c) use ($idArchive, $idUser, $view, $update, $delete, $store, $now) {
            $c->table('archive_permissions')->insert([
                'id_archive_permission' => \Modules\V5\Entities\Helper\MyHelper::generateId(),
                'id_archive'            => $idArchive,
                'id_user'               => $idUser,
                'is_view'               => $view,
                'is_update'             => $update,
                'is_delete'             => $delete,
                'is_store'              => $store,
                'created_at'            => $now,
                'created_by'            => 'QA02',
                'updated_at'            => $now,
                'updated_by'            => 'QA02',
            ]);
        });
    }

    /** Hapus semua baris permission satu user di satu folder. */
    function q2_unperm($t, $idArchive, $idUser)
    {
        q2_w($t, function ($c) use ($idArchive, $idUser) {
            $c->table('archive_permissions')->where('id_archive', $idArchive)->where('id_user', $idUser)->delete();
        });
    }

    function q2_set_archive($t, $id, array $cols)
    {
        q2_w($t, function ($c) use ($id, $cols) {
            $c->table('archives')->where('id_archive', $id)->update($cols);
        });
    }

    /** Hapus semua baris archive / dokumen / lokasi / permission berawalan QA02- (idempoten). */
    function q2_purge($t)
    {
        q2_w($t, function ($c) {
            $ids = $c->table('archives')->where('name', 'like', Q2_PREFIX . '%')->pluck('id_archive')->all();
            foreach (array_chunk($ids, 500) as $chunk) {
                $c->table('archive_permissions')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_documents')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_locations')->whereIn('id_archive', $chunk)->delete();
                $c->table('archives')->whereIn('id_archive', $chunk)->delete();
            }
        });
    }

    /** Jumlah baris archive_permissions saat ini (dasar untuk membuktikan tidak ada sisa). */
    function q2_perm_total($t)
    {
        return $t->db()->table('archive_permissions')->count();
    }

    function q2_assert_clean($t, $permBaseline, $label = 'data uji sudah dibuang')
    {
        $t->eq($t->db()->table('archives')->where('name', 'like', Q2_PREFIX . '%')->count(), 0, $label . ' (archives)');
        $t->eq($t->db()->table('archive_documents')->where('transaction_no', 'like', Q2_PREFIX . '%')->count(), 0, $label . ' (archive_documents)');
        $t->eq(q2_perm_total($t), $permBaseline, $label . ' (archive_permissions kembali ke jumlah awal)');
    }

    function q2_row($t, $id)
    {
        $row = $t->db()->table('archives')->where('id_archive', $id)->first();

        return $row ? (array) $row : null;
    }

    /** Baris archive_permissions satu folder, ternormalisasi, urut id_user: [id_user => [v,u,d,s]]. */
    function q2_perm_map($t, $idArchive)
    {
        $out = [];
        $rows = $t->db()->table('archive_permissions')->where('id_archive', $idArchive)->orderBy('id_user')->get();
        foreach ($rows as $r) {
            $out[$r->id_user] = [(int) $r->is_view, (int) $r->is_update, (int) $r->is_delete, (int) $r->is_store];
        }

        return $out;
    }

    /** Entri riwayat (history JSON) satu archive. */
    function q2_history($t, $id)
    {
        $raw = $t->db()->table('archives')->where('id_archive', $id)->value('history');
        $h = $raw ? json_decode($raw, true) : [];

        return is_array($h) ? $h : [];
    }

    function q2_history_actions($t, $id)
    {
        return array_map(function ($h) {
            return $h['action'] ?? null;
        }, q2_history($t, $id));
    }

    /** Snapshot baris (archives + archive_permissions + archive_documents) untuk dibandingkan persis. */
    function q2_snap($t, array $ids)
    {
        $c = $t->db();

        return [
            'a' => q2_rows($c->table('archives')->whereIn('id_archive', $ids)->orderBy('id_archive')->get()),
            'p' => q2_rows($c->table('archive_permissions')->whereIn('id_archive', $ids)->orderBy('id_archive')->orderBy('id_user')->get()),
            'd' => q2_rows($c->table('archive_documents')->whereIn('id_archive', $ids)->orderBy('id_archive_document')->get()),
        ];
    }

    function q2_same(array $a, array $b)
    {
        return json_encode($a) === json_encode($b);
    }

    /** n user aktif selain QA_USER (stabil, urut id_user), tanpa role superadmin 1/2: [[id_user, username], ...]. */
    function q2_others($t, $n)
    {
        $c = $t->db();
        $super = $c->table('user_roles')->join('roles', 'roles.id_role', '=', 'user_roles.id_role')
            ->whereIn('roles.is_superadmin', [1, 2])->pluck('user_roles.id_user')->all();

        $rows = $c->table('users')->where('is_active', 1)->where('username', '!=', $t->conf('QA_USER'))
            ->whereNotIn('id_user', $super ?: ['__none__'])
            ->where('username', 'regexp', '^[A-Za-z]+$')
            ->orderBy('id_user')->limit($n)->get(['id_user', 'username']);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [$r->id_user, $r->username];
        }
        if (count($out) < $n) {
            $t->fail('prasyarat: butuh ' . $n . ' user aktif lain di DB uji');
        }

        return $out;
    }

    // ------------------------------------------------------------------ request

    function q2_url($path, array $query = [])
    {
        return $path . ($query ? '?' . http_build_query($query) : '');
    }

    function q2_list($t, $s, array $query = [])
    {
        return $t->call($s, 'GET', q2_url('api/v5/document-archive/archives', $query));
    }

    /** Buka folder (isi) */
    function q2_open($t, $s, $id, array $extra = [])
    {
        return q2_list($t, $s, ['id_archive' => $id, 'pagination' => 100] + $extra);
    }

    function q2_show($t, $s, $id)
    {
        return $t->call($s, 'GET', 'api/v5/document-archive/archives/' . $id);
    }

    function q2_hist($t, $s, $id)
    {
        return $t->call($s, 'GET', 'api/v5/document-archive/archives/history/' . $id);
    }

    function q2_put($t, $s, $id, array $body)
    {
        return $t->call($s, 'PUT', 'api/v5/document-archive/archives/' . $id, $body);
    }

    /** Body PUT minimal yang valid untuk folder bernama $name. */
    function q2_body($name, array $extra = [])
    {
        return ['name' => $name, 'is_all_location' => 1] + $extra;
    }

    function q2_rename($t, $s, $id, $name)
    {
        return $t->call($s, 'PUT', 'api/v5/document-archive/archives/rename/' . $id, ['name' => $name]);
    }

    function q2_delete($t, $s, $id)
    {
        return $t->call($s, 'DELETE', 'api/v5/document-archive/archives/delete/' . $id);
    }

    function q2_create($t, $s, array $body)
    {
        return $t->call($s, 'POST', 'api/v5/document-archive/archives/create-folder', $body);
    }

    /** put-in: $parent = id folder tujuan (null = root), $sources = id|[id] , atau 'name' => nama */
    function q2_putin($t, $s, $parent, $sources = null, $name = null)
    {
        $body = [];
        if ($parent !== null) {
            $body['id_archive_parent'] = $parent;
        }
        if ($sources !== null) {
            $body['id_archives'] = (array) $sources;
        }
        if ($name !== null) {
            $body['name'] = $name;
        }

        return $t->call($s, 'POST', 'api/v5/document-archive/documents/put-in', $body);
    }

    // ------------------------------------------------------------------ pembacaan & asersi respons

    function q2_code($r)
    {
        return $r[1]['code'] ?? $r[1]['msg_code'] ?? null;
    }

    function q2_ids($r)
    {
        return array_map(function ($row) {
            return $row['id_archive'];
        }, $r[1]['result']['data'] ?? []);
    }

    /** Baris result.data dengan id tertentu (atau null). */
    function q2_find($r, $id)
    {
        foreach ($r[1]['result']['data'] ?? [] as $row) {
            if (($row['id_archive'] ?? null) === $id) {
                return $row;
            }
        }

        return null;
    }

    function q2_no500($t, $r, $label)
    {
        $t->true($r[0] < 500, $label . ': HTTP ' . $r[0] . ' (tidak boleh 5xx)');
    }

    /** Penolakan ErrorMessageException: HTTP, code, dan (bila diberi) parameter = [nama folder penolak]. */
    function q2_deny($t, $r, $http, $code, $label, $folderName = null)
    {
        $t->status($r, $http, $label);
        $t->eq(q2_code($r), $code, $label . ': code');
        if ($folderName !== null) {
            $t->eq(json_encode($r[1]['parameter'] ?? null), json_encode([$folderName]), $label . ': parameter = nama folder penolak');
        }
    }

    /** access = objek 5 kunci dengan nilai [view, update, delete, store] dan manage_permission = update. */
    function q2_access_is($t, $access, array $rights, $label)
    {
        $t->true(is_array($access), $label . ': access berupa objek');
        $expected = [
            'view'              => (bool) $rights[0],
            'update'            => (bool) $rights[1],
            'delete'            => (bool) $rights[2],
            'store'             => (bool) $rights[3],
            'manage_permission' => (bool) $rights[1],
        ];
        $t->eq(json_encode($access), json_encode($expected), $label . ': access');
    }

    function q2_all_true() { return [1, 1, 1, 1]; }

    define('Q2_RANDOM_ID', '999999999999999999999999999999');
}
