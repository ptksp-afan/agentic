<?php
/**
 * Helper bersama skenario ED-1029 (BUKAN skenario: nama berkas tidak cocok scenario*.php).
 * Di-require_once oleh setiap scenario*.php.
 *
 * Aturan pakai:
 * - "User A" (pemanggil) = QA_USER dengan keadaan sementara lewat q9_with_user(): role (user_roles), lokasi employee
 *   dan bahasa. Role 3 (Super Admin, is_superadmin 3) BUKAN bypass hak folder; role 1/2 = bypass; role 6 = tanpa
 *   permission Archive; role 29 = hanya List Archive. QA_USER2 tidak bisa login di api_sidomaju (lihat AC permission).
 * - "User B" (target) = user aktif lain (q9_others). Satu-satunya baris yang ditulis QA ada di folder berawalan
 *   "QA29-" (tabel archives + archive_permissions + archive_locations + archive_documents); semuanya dibuang di
 *   finally dan diverifikasi (jumlah archive_permissions kembali ke awal).
 * - Jurnal pemulihan user: .restore-journal-q9.json. Bila run mati, run berikutnya memulihkan dari jurnal itu dulu.
 * - Tulis lewat koneksi non-read-only di dalam $t->probe(); baca lewat $t->db() (read-only).
 */

if (!function_exists('q9_dbname')) {

    define('Q9_PREFIX', 'QA29-');

    function q9_dbname($t)
    {
        return $t->dbs()[0];
    }

    /** Jalankan $fn($conn) dengan koneksi tulis ke DB QA (di dalam probe). */
    function q9_w($t, callable $fn)
    {
        $db = q9_dbname($t);

        return $t->probe(function () use ($fn, $db) {
            return $fn(\Illuminate\Support\Facades\DB::connection($db));
        });
    }

    function q9_rows($collection)
    {
        $out = [];
        foreach ($collection as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }

    function q9_journal()
    {
        return __DIR__ . '/.restore-journal-q9.json';
    }

    function q9_uid($t)
    {
        return $t->db()->table('users')->where('username', $t->conf('QA_USER'))->value('id_user');
    }

    function q9_uname($t)
    {
        return $t->db()->table('users')->where('username', $t->conf('QA_USER'))->value('username');
    }

    /** kode lokasi => id lokasi */
    function q9_locs($t)
    {
        return $t->db()->table('locations')->orderBy('id_location')->pluck('id_location', 'location_code')->all();
    }

    // ------------------------------------------------------------------ user QA (role / lokasi / bahasa)

    function q9_user_snapshot($t)
    {
        $c = $t->db();
        $uid = q9_uid($t);
        $emp = $c->table('employees')->where('id_user', $uid)->first();

        return [
            'uid'      => $uid,
            'roles'    => q9_rows($c->table('user_roles')->where('id_user', $uid)->orderBy('id_user_role')->get()),
            'lang'     => $c->table('users')->where('id_user', $uid)->value('language'),
            'emp_id'   => $emp->id_employee ?? null,
            'emp_all'  => $emp->is_all_location ?? null,
            'emp_locs' => $emp ? q9_rows($c->table('employee_locations')->where('id_employee', $emp->id_employee)->orderBy('id_employee_location')->get()) : [],
        ];
    }

    function q9_user_restore($t, array $snap)
    {
        q9_w($t, function ($c) use ($snap) {
            $uid = $snap['uid'];
            $c->table('user_roles')->where('id_user', $uid)->delete();
            if ($snap['roles']) {
                $c->table('user_roles')->insert($snap['roles']);
            }
            $c->table('users')->where('id_user', $uid)->update(['language' => $snap['lang']]);
            if ($snap['emp_id']) {
                $c->table('employees')->where('id_employee', $snap['emp_id'])
                    ->update(['id_user' => $uid, 'is_all_location' => $snap['emp_all']]);
                $c->table('employee_locations')->where('id_employee', $snap['emp_id'])->delete();
                if ($snap['emp_locs']) {
                    $c->table('employee_locations')->insert($snap['emp_locs']);
                }
            }
        });
    }

    /** opts: 'role' => int|int[] (id_role), 'emp' => 'all'|['JOG', ...] (kode lokasi), 'lang' => 'ID'|'EN' */
    function q9_user_apply($t, array $snap, array $opts)
    {
        $locs = q9_locs($t);
        q9_w($t, function ($c) use ($snap, $opts, $locs) {
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

            if (isset($opts['emp']) && $snap['emp_id']) {
                $emp = $opts['emp'];
                if ($emp === 'all') {
                    $c->table('employees')->where('id_employee', $snap['emp_id'])->update(['is_all_location' => 1]);
                } else {
                    $c->table('employees')->where('id_employee', $snap['emp_id'])->update(['is_all_location' => 0]);
                    $c->table('employee_locations')->where('id_employee', $snap['emp_id'])->delete();
                    foreach ($emp as $i => $code) {
                        $c->table('employee_locations')->insert([
                            'id_employee' => $snap['emp_id'], 'id_location' => $locs[$code],
                            'is_default' => $i === 0 ? 1 : 0, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                }
            }
        });
    }

    function q9_recover($t)
    {
        if (is_file(q9_journal())) {
            $snap = json_decode(file_get_contents(q9_journal()), true);
            if (is_array($snap)) {
                q9_user_restore($t, $snap);
            }
            @unlink(q9_journal());
        }
    }

    /**
     * Jalankan $fn($set) dengan user QA di keadaan $opts. $set(array $opts) mengganti keadaan (selalu relatif terhadap
     * keadaan asli). Keadaan asli dipulihkan dan diverifikasi persis di finally.
     */
    function q9_with_user($t, array $opts, callable $fn)
    {
        q9_recover($t);
        $snap = q9_user_snapshot($t);
        file_put_contents(q9_journal(), json_encode($snap));

        $set = function (array $o) use ($t, $snap) {
            q9_user_restore($t, $snap);
            q9_user_apply($t, $snap, $o);
        };

        try {
            $set($opts);

            return $fn($set);
        } finally {
            q9_user_restore($t, $snap);
            $after = q9_user_snapshot($t);
            if (json_encode($after) !== json_encode($snap)) {
                $t->fail('pemulihan user QA tidak persis sama dengan sebelumnya (jurnal dipertahankan)');
            }
            @unlink(q9_journal());
        }
    }

    // ------------------------------------------------------------------ data uji archive

    function q9_new_id($t)
    {
        return q9_w($t, function ($c) {
            return \Modules\V5\Entities\Helper\MyHelper::generateId();
        });
    }

    function q9_name($label)
    {
        return Q9_PREFIX . $label . '-' . substr(uniqid(), -6) . mt_rand(10, 99);
    }

    /**
     * Sisipkan satu baris archive uji.
     * $a: name, type (1 folder / 2 dokumen), parent, perm (is_folder_permission), by (created_by, default 'QA29'),
     *     active (default 1), all (is_all_location, default 1), locs => [kode lokasi] (dipakai bila all = 0).
     */
    function q9_add($t, array $a)
    {
        $id = q9_new_id($t);
        $now = date('Y-m-d H:i:s');
        $locs = q9_locs($t);

        q9_w($t, function ($c) use ($a, $id, $now, $locs) {
            $c->table('archives')->insert([
                'id_archive'           => $id,
                'id_archive_parent'    => $a['parent'] ?? null,
                'name'                 => $a['name'],
                'type'                 => $a['type'] ?? 1,
                'status'               => 1,
                'is_all_location'      => $a['all'] ?? 1,
                'is_folder_permission' => $a['perm'] ?? 0,
                'is_active'            => $a['active'] ?? 1,
                'created_at'           => $now,
                'created_by'           => $a['by'] ?? 'QA29',
                'updated_at'           => $now,
                'updated_by'           => $a['by'] ?? 'QA29',
            ]);

            foreach ($a['locs'] ?? [] as $code) {
                $c->table('archive_locations')->insert(['id_archive' => $id, 'id_location' => $locs[$code]]);
            }

            if (($a['type'] ?? 1) == 2) {
                $c->table('archive_documents')->insert([
                    'id_archive_document' => \Modules\V5\Entities\Helper\MyHelper::generateId(),
                    'id_archive'          => $id,
                    'id_transaction'      => 'QA29TX' . substr($id, -10),
                    'transaction_no'      => $a['name'],
                    'transaction_type'    => 6,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
            }
        });

        return $id;
    }

    function q9_folder($t, $label, array $a = [])
    {
        return q9_add($t, $a + ['name' => q9_name($label), 'type' => 1]);
    }

    function q9_doc($t, $label, array $a = [])
    {
        return q9_add($t, $a + ['name' => q9_name($label), 'type' => 2]);
    }

    /** Sisipkan baris archive_permissions (hak 0/1). */
    function q9_perm($t, $idArchive, $idUser, $view, $update = 0, $delete = 0, $store = 0)
    {
        $now = date('Y-m-d H:i:s');
        q9_w($t, function ($c) use ($idArchive, $idUser, $view, $update, $delete, $store, $now) {
            $c->table('archive_permissions')->insert([
                'id_archive_permission' => \Modules\V5\Entities\Helper\MyHelper::generateId(),
                'id_archive'            => $idArchive,
                'id_user'               => $idUser,
                'is_view'               => $view,
                'is_update'             => $update,
                'is_delete'             => $delete,
                'is_store'              => $store,
                'created_at'            => $now,
                'created_by'            => 'QA29',
                'updated_at'            => $now,
                'updated_by'            => 'QA29',
            ]);
        });
    }

    function q9_set_archive($t, $id, array $cols)
    {
        q9_w($t, function ($c) use ($id, $cols) {
            $c->table('archives')->where('id_archive', $id)->update($cols);
        });
    }

    function q9_set_user($t, $idUser, array $cols)
    {
        q9_w($t, function ($c) use ($idUser, $cols) {
            $c->table('users')->where('id_user', $idUser)->update($cols);
        });
    }

    /** Hapus semua baris archive / dokumen / lokasi / permission berawalan QA29- (idempoten). */
    function q9_purge($t)
    {
        q9_w($t, function ($c) {
            $ids = $c->table('archives')->where('name', 'like', Q9_PREFIX . '%')->pluck('id_archive')->all();
            foreach (array_chunk($ids, 500) as $chunk) {
                $c->table('archive_permissions')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_documents')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_locations')->whereIn('id_archive', $chunk)->delete();
                $c->table('archives')->whereIn('id_archive', $chunk)->delete();
            }
        });
    }

    function q9_perm_total($t)
    {
        return $t->db()->table('archive_permissions')->count();
    }

    function q9_assert_clean($t, $permBaseline, $label = 'data uji sudah dibuang')
    {
        $t->eq($t->db()->table('archives')->where('name', 'like', Q9_PREFIX . '%')->count(), 0, $label . ' (archives)');
        $t->eq($t->db()->table('archive_documents')->where('transaction_no', 'like', Q9_PREFIX . '%')->count(), 0, $label . ' (archive_documents)');
        $t->eq(q9_perm_total($t), $permBaseline, $label . ' (archive_permissions kembali ke jumlah awal)');
    }

    function q9_row($t, $id)
    {
        $row = $t->db()->table('archives')->where('id_archive', $id)->first();

        return $row ? (array) $row : null;
    }

    /** Baris archive_permissions satu user (semua folder uji yang diberikan): [id_archive => [v,u,d,s]]. */
    function q9_user_map($t, $idUser, array $ids)
    {
        $out = [];
        $rows = $t->db()->table('archive_permissions')->where('id_user', $idUser)->whereIn('id_archive', $ids)->orderBy('id_archive')->get();
        foreach ($rows as $r) {
            $out[$r->id_archive] = [(int) $r->is_view, (int) $r->is_update, (int) $r->is_delete, (int) $r->is_store];
        }

        return $out;
    }

    /** Semua baris archive_permissions di folder-folder itu (semua user), urut tetap, ternormalisasi. */
    function q9_perm_dump($t, array $ids)
    {
        $out = [];
        $rows = $t->db()->table('archive_permissions')->whereIn('id_archive', $ids)->orderBy('id_archive')->orderBy('id_user')->get();
        foreach ($rows as $r) {
            $out[] = [$r->id_archive, $r->id_user, (int) $r->is_view, (int) $r->is_update, (int) $r->is_delete, (int) $r->is_store];
        }

        return $out;
    }

    function q9_history($t, $id)
    {
        $raw = $t->db()->table('archives')->where('id_archive', $id)->value('history');
        $h = $raw ? json_decode($raw, true) : [];

        return is_array($h) ? $h : [];
    }

    function q9_hist_count($t, $id, $action = 'permission')
    {
        $n = 0;
        foreach (q9_history($t, $id) as $h) {
            if (($h['action'] ?? null) === $action) {
                $n++;
            }
        }

        return $n;
    }

    /** Snapshot baris (archives + archive_permissions + archive_locations + archive_documents) untuk dibandingkan persis. */
    function q9_snap($t, array $ids)
    {
        $c = $t->db();

        return [
            'a' => q9_rows($c->table('archives')->whereIn('id_archive', $ids)->orderBy('id_archive')->get()),
            'p' => q9_rows($c->table('archive_permissions')->whereIn('id_archive', $ids)->orderBy('id_archive')->orderBy('id_user')->get()),
            'l' => q9_rows($c->table('archive_locations')->whereIn('id_archive', $ids)->orderBy('id_archive')->orderBy('id_location')->get()),
            'd' => q9_rows($c->table('archive_documents')->whereIn('id_archive', $ids)->orderBy('id_archive_document')->get()),
        ];
    }

    function q9_same($a, $b)
    {
        return json_encode($a) === json_encode($b);
    }

    /** n user aktif selain QA_USER (stabil, urut id_user), tanpa role superadmin 1/2: [[id_user, username], ...]. */
    function q9_others($t, $n)
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

    // ------------------------------------------------------------------ request & asersi

    /** Semua msg_code yang dilihat q9_get/q9_put sejak q9_codes_reset() (untuk asersi "tanpa ARCHIVE408", rev K-15 b). */
    function q9_codes_reset()
    {
        $GLOBALS['q9_codes_seen'] = [];
    }

    function q9_codes_note($r)
    {
        $GLOBALS['q9_codes_seen'][] = $r[1]['code'] ?? $r[1]['msg_code'] ?? ('HTTP' . $r[0]);

        return $r;
    }

    /** ARCHIVE408 tidak pernah dikirim endpoint user-permissions (rev). */
    function q9_assert_no408($t, $label)
    {
        $seen = $GLOBALS['q9_codes_seen'] ?? [];
        $t->true(!in_array('ARCHIVE408', $seen, true), $label . ': tidak ada ARCHIVE408 di ' . count($seen) . ' respons');
    }

    function q9_get($t, $s, $idUser)
    {
        return q9_codes_note($t->call($s, 'GET', 'api/v5/document-archive/user-permissions/' . rawurlencode($idUser)));
    }

    function q9_put($t, $s, $idUser, $body)
    {
        return q9_codes_note($t->call($s, 'PUT', 'api/v5/document-archive/user-permissions/' . rawurlencode($idUser), $body));
    }

    /** Satu baris body permissions. */
    function q9_row_body($idArchive, $v = 0, $u = 0, $d = 0, $st = 0)
    {
        return ['id_archive' => $idArchive, 'is_view' => $v, 'is_update' => $u, 'is_delete' => $d, 'is_store' => $st];
    }

    function q9_code($r)
    {
        return $r[1]['code'] ?? $r[1]['msg_code'] ?? null;
    }

    function q9_no500($t, $r, $label)
    {
        $t->true($r[0] < 500, $label . ': HTTP ' . $r[0] . ' (tidak boleh 5xx)');
    }

    /** Penolakan: HTTP, code, dan (bila diberi) parameter = [nama folder]. */
    function q9_deny($t, $r, $http, $code, $label, $folderName = null)
    {
        $t->status($r, $http, $label);
        $t->eq(q9_code($r), $code, $label . ': code');
        if ($folderName !== null) {
            $t->eq(json_encode($r[1]['parameter'] ?? null), json_encode([$folderName]), $label . ': parameter = nama folder');
        }
    }

    /** Ratakan pohon result.data menjadi [id_archive => node] (tanpa children) + daftar urutan DFS. */
    function q9_flatten(array $tree, array &$flat = [], array &$order = [], $level = 0)
    {
        foreach ($tree as $node) {
            $copy = $node;
            unset($copy['children']);
            $copy['__level'] = $level;
            $flat[$node['id_archive']] = $copy;
            $order[] = $node['id_archive'];
            if (!empty($node['children'])) {
                q9_flatten($node['children'], $flat, $order, $level + 1);
            }
        }

        return $flat;
    }

    /**
     * access = objek 5 kunci [view, update, delete, store] + manage_permission. Rev (ED-1025 K-15 b): manage_permission =
     * role pemanggil memegang Folder Permissions, sama di semua folder; di endpoint ini selalu true (default $manage).
     */
    function q9_access_is($t, $access, array $rights, $label, $manage = true)
    {
        $expected = [
            'view'              => (bool) $rights[0],
            'update'            => (bool) $rights[1],
            'delete'            => (bool) $rights[2],
            'store'             => (bool) $rights[3],
            'manage_permission' => (bool) $manage,
        ];
        $t->eq(json_encode($access), json_encode($expected), $label . ': access');
    }

    /**
     * Fixture standar (semua berawalan QA29-, dibuat oleh 'QA29' kecuali QC). Pemanggil A = $me, target B = $b.
     * Hak A: P (V+U), C1 (V+U), R1 (V+U), Q (V saja). Hak B: P (V+Store), C1 (V), C2 (V+U, folder Off).
     */
    function q9_fixture($t, $meId, $meName, $bId, $bRows = true)
    {
        $f = [];
        $f['P'] = q9_folder($t, 'P', ['perm' => 1]);
        $f['C1'] = q9_folder($t, 'C1', ['perm' => 1, 'parent' => $f['P']]);
        $f['C2'] = q9_folder($t, 'C2', ['perm' => 0, 'parent' => $f['P']]);
        $f['PX'] = q9_folder($t, 'PX', ['perm' => 1, 'parent' => $f['P'], 'active' => 0]);   // nonaktif: tidak tampil
        $f['DP'] = q9_doc($t, 'DP', ['parent' => $f['P']]);                                  // dokumen: tidak tampil
        $f['Q'] = q9_folder($t, 'Q', ['perm' => 1]);
        $f['R'] = q9_folder($t, 'R', ['perm' => 0]);
        $f['R1'] = q9_folder($t, 'R1', ['perm' => 1, 'parent' => $f['R']]);
        $f['QC'] = q9_folder($t, 'QC', ['perm' => 1, 'by' => $meName]);                      // A pembuat: semua hak
        $f['N'] = q9_folder($t, 'N', ['perm' => 1]);                                         // On, A tanpa baris
        $f['L'] = q9_folder($t, 'L', ['perm' => 0, 'all' => 0, 'locs' => ['MGL']]);          // di luar lokasi A (SMR)
        $f['LC'] = q9_folder($t, 'LC', ['perm' => 1, 'parent' => $f['L']]);                  // dalam scope, induk di luar scope
        $f['M'] = q9_folder($t, 'M', ['perm' => 1, 'all' => 0, 'locs' => ['SMR', 'MGL']]);   // satu dari dua lokasi = SMR: tampil

        q9_perm($t, $f['P'], $meId, 1, 1);
        q9_perm($t, $f['C1'], $meId, 1, 1);
        q9_perm($t, $f['R1'], $meId, 1, 1);
        q9_perm($t, $f['Q'], $meId, 1);
        if ($bRows) {
            q9_perm($t, $f['P'], $bId, 1, 0, 0, 1);
            q9_perm($t, $f['C1'], $bId, 1);
            q9_perm($t, $f['C2'], $bId, 1, 1);
        }

        return $f;
    }

    /** Folder aktif yang tampil menurut SQL: $codes = null (semua) atau kode lokasi. Urut nama per induk. */
    function q9_expected_ids($t, $codes)
    {
        $q = $t->db()->table('archives')->where('type', 1)->where('is_active', '>', 0);
        if ($codes !== null) {
            $ids = array_values(array_intersect_key(q9_locs($t), array_flip($codes)));
            $q->where(function ($w) use ($ids) {
                $w->where('is_all_location', 1)->orWhereExists(function ($e) use ($ids) {
                    $e->selectRaw('1')->from('archive_locations')
                        ->whereRaw('archive_locations.id_archive = archives.id_archive')
                        ->whereIn('archive_locations.id_location', $ids);
                });
            });
        }

        return $q->orderBy('name')->orderBy('id_archive')->get(['id_archive', 'id_archive_parent', 'name', 'is_folder_permission'])->all();
    }

    /** DFS id yang diharapkan dari daftar datar SQL (urut nama per level, anak di bawah induk, yatim di level teratas). */
    function q9_expected_order(array $rows)
    {
        $set = [];
        foreach ($rows as $r) {
            $set[$r->id_archive] = true;
        }
        $kids = [];
        $roots = [];
        foreach ($rows as $r) {
            if ($r->id_archive_parent !== null && isset($set[$r->id_archive_parent]) && $r->id_archive_parent !== $r->id_archive) {
                $kids[$r->id_archive_parent][] = $r->id_archive;
            } else {
                $roots[] = $r->id_archive;
            }
        }
        $order = [];
        $walk = function ($id) use (&$walk, &$order, $kids) {
            $order[] = $id;
            foreach ($kids[$id] ?? [] as $k) {
                $walk($k);
            }
        };
        foreach ($roots as $r) {
            $walk($r);
        }

        return [$order, $kids, $roots];
    }

    /** Set baris (folder, user) persis ke $flags [v,u,d,s] lewat DB (null = hapus). Dipakai membersihkan efek PUT uji. */
    function q9_perm_restore_row($t, $idArchive, $idUser, $flags)
    {
        q9_w($t, function ($c) use ($idArchive, $idUser) {
            $c->table('archive_permissions')->where('id_archive', $idArchive)->where('id_user', $idUser)->delete();
        });
        if ($flags !== null) {
            q9_perm($t, $idArchive, $idUser, $flags[0], $flags[1], $flags[2], $flags[3]);
        }
    }

    // ------------------------------------------------------------------ role fixture & data J (rev 2026-10-08)

    define('Q9_ROLE_PREFIX', 'QA29-ROLE-');

    /** Nama permission per profil uji spec § 6 (nama persis di tabel permissions; tanpa id literal). */
    function q9_perms_profile($profile)
    {
        $p = [
            'A' => ['List Archive', 'View Folder', 'Update Folder', 'Folder Permissions'],
            'M' => ['List Archive', 'View Folder', 'Folder Permissions'],
            'D' => ['List Archive', 'View Folder', 'Update Folder'],
            'E' => ['List Archive', 'View Folder'],
        ];

        return $p[$profile];
    }

    /**
     * Buat role fixture QA29-ROLE-<label> (bukan superadmin, aktif) dengan permission bernama $names. Dibuang q9_role_purge().
     * @return int id_role
     */
    function q9_role($t, $label, array $names)
    {
        return q9_w($t, function ($c) use ($label, $names) {
            $now = date('Y-m-d H:i:s');
            $id = $c->table('roles')->insertGetId([
                'role_name' => Q9_ROLE_PREFIX . $label, 'description' => 'QA ED-1029 fixture', 'is_superadmin' => 4,
                'is_active' => 1, 'is_used' => 1, 'created_by' => 'QA29', 'created_at' => $now, 'updated_by' => 'QA29', 'updated_at' => $now,
            ]);
            foreach ($names as $name) {
                $perm = $c->table('permissions')->where('permission_name', $name)->first();
                if (!$perm) {
                    throw new \RuntimeException("prasyarat: permission '$name' tidak ada di DB uji (seed ulang ED-1025 belum diterapkan?)");
                }
                $c->table('role_permissions')->insert([
                    'id_role' => $id, 'id_permission' => $perm->id_permission, 'permission_name' => $perm->permission_name,
                    'created_at' => $now, 'updated_at' => $now, 'created_by' => 'QA29', 'updated_by' => 'QA29',
                ]);
            }

            return (int) $id;
        });
    }

    /** Hapus semua role fixture QA29-ROLE- (user_roles yang menunjuknya, role_permissions, lalu role). Idempoten. */
    function q9_role_purge($t)
    {
        q9_w($t, function ($c) {
            $ids = $c->table('roles')->where('role_name', 'like', Q9_ROLE_PREFIX . '%')->pluck('id_role')->all();
            if ($ids) {
                $c->table('user_roles')->whereIn('id_role', $ids)->delete();
                $c->table('role_permissions')->whereIn('id_role', $ids)->delete();
                $c->table('roles')->whereIn('id_role', $ids)->delete();
            }
        });
    }

    /** Buat keempat role profil spec: ['A' => id, 'M' => id, 'D' => id, 'E' => id]. */
    function q9_roles_profile($t)
    {
        $out = [];
        foreach (['A', 'M', 'D', 'E'] as $p) {
            $out[$p] = q9_role($t, $p, q9_perms_profile($p));
        }

        return $out;
    }

    /** id_role yang memegang (atau tidak) permission bernama $name, di antara $candidates (tanpa id permission literal). */
    function q9_roles_by_permission($t, $name, array $candidates, $holds)
    {
        $with = $t->db()->table('role_permissions')->join('permissions', 'permissions.id_permission', '=', 'role_permissions.id_permission')
            ->where('permissions.permission_name', $name)->pluck('role_permissions.id_role')->all();
        $out = [];
        foreach ($candidates as $r) {
            if (in_array($r, $with) === $holds && $t->db()->table('roles')->where('id_role', $r)->exists()) {
                $out[] = $r;
            }
        }

        return $out;
    }

    /**
     * Tambah satu role ke user LAIN (bukan QA_USER) selama $fn, lalu hapus baris user_roles yang ditambahkan itu. Role asli
     * user itu tidak disentuh. Hanya untuk user nyata kedua (QA_USER2).
     */
    function q9_with_extra_role($t, $idUser, $idRole, callable $fn)
    {
        $before = $t->db()->table('user_roles')->where('id_user', $idUser)->count();
        q9_w($t, function ($c) use ($idUser, $idRole) {
            $now = date('Y-m-d H:i:s');
            $c->table('user_roles')->insert([
                'id_user' => $idUser, 'id_role' => $idRole, 'is_all_location' => 1, 'id_location' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        });
        try {
            return $fn();
        } finally {
            q9_w($t, function ($c) use ($idUser, $idRole) {
                $c->table('user_roles')->where('id_user', $idUser)->where('id_role', $idRole)->delete();
            });
            $t->eq($t->db()->table('user_roles')->where('id_user', $idUser)->count(), $before, 'user_roles user kedua kembali ke jumlah awal');
        }
    }

    /**
     * Data FB-9 (pola ED-1025, dibuat user lain 'QA29'): J (On, A View saja) dengan anak JA (On, A V/U/D/S), JB (On, tanpa baris A),
     * JC (Off). $meId = pemanggil A.
     */
    function q9_fixture_j($t, $meId)
    {
        $f = [];
        $f['J'] = q9_folder($t, 'J', ['perm' => 1]);
        $f['JA'] = q9_folder($t, 'JA', ['perm' => 1, 'parent' => $f['J']]);
        $f['JB'] = q9_folder($t, 'JB', ['perm' => 1, 'parent' => $f['J']]);
        $f['JC'] = q9_folder($t, 'JC', ['perm' => 0, 'parent' => $f['J']]);
        q9_perm($t, $f['J'], $meId, 1);
        q9_perm($t, $f['JA'], $meId, 1, 1, 1, 1);

        return $f;
    }

    /** Nilai tersimpan satu node (is_view..is_store) sebagai JSON [v,u,d,s]. */
    function q9_node_vals($n)
    {
        return json_encode([$n['is_view'], $n['is_update'], $n['is_delete'], $n['is_store']]);
    }


    define('Q9_RANDOM_ID', '999999999999999999999999999999');
}
