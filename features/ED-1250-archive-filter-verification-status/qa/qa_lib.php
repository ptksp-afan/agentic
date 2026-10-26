<?php
/**
 * Helper bersama skenario ED-1250 (BUKAN skenario: nama berkas tidak cocok scenario*.php). Pola helper ED-1027
 * (features/ED-1027-archive-status-verifikasi/qa/qa_lib.php), awalan / jurnal / preset sendiri:
 *
 * - Data uji archive berawalan "QA50-" (folder, dokumen, lokasi, archive_documents, archive_permissions), dibuat q50_add() /
 *   q50_tree(), dibuang q50_purge(). K-4 a: is_verified / verified_at / verified_by di-set LANGSUNG pada dokumen fixture;
 *   TIDAK ada sesi opname, TIDAK menulis archive_opname*.
 * - Role preset QA50-ROLE-{OD,LIST,NOLIST} dari NAMA permission (bukan salinan role developer, ED-1149 BR-1); dibuang di akhir
 *   (q50_preset_purge). QA_USER ber-Opname Document = preset OD; tanpa Opname Document = preset LIST. QA_USER2 sementara di
 *   preset (jurnal .journal-q50-user2.json). Keadaan asli user (user_roles, bahasa, lokasi karyawan) dipulihkan persis (jurnal
 *   .journal-q50-user.json).
 * - Baris column_display_settings 'documentArchive' (disentuh AC-10/11): snapshot utuh + jurnal .journal-q50-cds.json, dipulihkan
 *   persis (keadaan SESUDAH Updater; DU-5 a: pilihan lama sebelum Updater tidak dipulihkan).
 * - Tulis lewat koneksi default non-read-only di dalam $t->probe(); baca lewat $t->db() (read-only).
 * - Oracle (q50_expect) menghitung baris yang seharusnya tampil dari tabel mentah (archives, archive_documents,
 *   archive_locations), tanpa kode BE.
 */

if (!function_exists('q50_w')) {

    define('Q50_PREFIX', 'QA50-');
    define('Q50_BASE', 'api/v5/document-archive');
    define('Q50_DS', 'api/v5/setting/display-columns/');
    define('Q50_ROLE_PREFIX', 'QA50-ROLE-');
    define('Q50_OD', 'Opname Document');
    define('Q50_FILTER', 'verificationStatus');
    define('Q50_RANDOM_ID', '999999999999999999999999999999');
    // Urutan default filter Archive sesudah review 2026-10-26 R-1 (spec Revisi review, BR-11 baru): current = Name, Type, Date Modified,
    // Show Related Transaction, Verification Status (terakhir); available = urutan sama + Modified By paling akhir.
    define('Q50_R1_CURRENT', ['name', 'type', 'updatedAt', 'showRelatedTransaction', 'verificationStatus']);
    define('Q50_R1_AVAILABLE', ['name', 'type', 'updatedAt', 'showRelatedTransaction', 'verificationStatus', 'updatedBy']);

    function q50_dbname($t)
    {
        return $t->dbs()[0];
    }

    /** Jalankan $fn($conn) dengan koneksi tulis ke DB QA (koneksi default BE_DIR) di dalam probe. */
    function q50_w($t, callable $fn)
    {
        $db = q50_dbname($t);

        return $t->probe(function () use ($fn, $db) {
            $conn = \Illuminate\Support\Facades\DB::connection();
            if ($conn->getDatabaseName() !== $db) {
                throw new \RuntimeException('koneksi default BE_DIR bukan ' . $db . ' (' . $conn->getDatabaseName() . ')');
            }

            return $fn($conn);
        });
    }

    function q50_rows($collection)
    {
        $out = [];
        foreach ($collection as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }

    function q50_jfile($name)
    {
        return __DIR__ . '/.journal-q50-' . $name . '.json';
    }

    function q50_uid($t)
    {
        return $t->db()->table('users')->where('username', $t->conf('QA_USER'))->value('id_user');
    }

    function q50_uname($t)
    {
        return (string) $t->db()->table('users')->where('username', $t->conf('QA_USER'))->value('username');
    }

    /** kode lokasi => id lokasi */
    function q50_locs($t)
    {
        return $t->db()->table('locations')->pluck('id_location', 'location_code')->all();
    }

    // ------------------------------------------------------------------ user QA (role / lokasi / bahasa)

    function q50_user_snapshot($t)
    {
        $c = $t->db();
        $uid = q50_uid($t);
        $emp = $c->table('employees')->where('id_user', $uid)->first();

        return [
            'uid'      => $uid,
            'roles'    => q50_rows($c->table('user_roles')->where('id_user', $uid)->orderBy('id_user_role')->get()),
            'lang'     => $c->table('users')->where('id_user', $uid)->value('language'),
            'emp_id'   => $emp->id_employee ?? null,
            'emp_all'  => $emp->is_all_location ?? null,
            'emp_locs' => $emp ? q50_rows($c->table('employee_locations')->where('id_employee', $emp->id_employee)->orderBy('id_employee_location')->get()) : [],
        ];
    }

    function q50_user_restore($t, array $snap)
    {
        q50_w($t, function ($c) use ($snap) {
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

    /** opts: 'role' => kunci preset (OD|LIST|NOLIST) | 1/2/3 | daftar, 'emp' => ['SMR', ...] (kode lokasi), 'lang' => 'ID'|'EN' */
    function q50_user_apply($t, array $snap, array $opts)
    {
        $locs = q50_locs($t);
        $roleIds = isset($opts['role']) ? array_map(function ($r) use ($t) {
            return q50_role_ref($t, $r);
        }, (array) $opts['role']) : [];
        q50_w($t, function ($c) use ($snap, $opts, $locs, $roleIds) {
            $uid = $snap['uid'];
            $now = date('Y-m-d H:i:s');

            if (isset($opts['role'])) {
                $c->table('user_roles')->where('id_user', $uid)->delete();
                foreach ($roleIds as $role) {
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
                $c->table('employees')->where('id_employee', $snap['emp_id'])->update(['is_all_location' => 0]);
                $c->table('employee_locations')->where('id_employee', $snap['emp_id'])->delete();
                foreach ($opts['emp'] as $i => $code) {
                    $c->table('employee_locations')->insert([
                        'id_employee' => $snap['emp_id'], 'id_location' => $locs[$code],
                        'is_default' => $i === 0 ? 1 : 0, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    function q50_recover_user($t)
    {
        if (is_file(q50_jfile('user'))) {
            $snap = json_decode(file_get_contents(q50_jfile('user')), true);
            if (is_array($snap)) {
                q50_user_restore($t, $snap);
            }
            @unlink(q50_jfile('user'));
        }
    }

    /**
     * Jalankan $fn($set) dengan QA_USER di keadaan $opts. $set(array $opts) mengganti keadaan (relatif terhadap keadaan asli).
     * Keadaan asli dipulihkan dan diverifikasi persis di finally.
     */
    function q50_with_user($t, array $opts, callable $fn)
    {
        q50_recover_user($t);
        $snap = q50_user_snapshot($t);
        file_put_contents(q50_jfile('user'), json_encode($snap));

        $set = function (array $o) use ($t, $snap) {
            q50_user_restore($t, $snap);
            q50_user_apply($t, $snap, $o);
        };

        try {
            $set($opts);

            return $fn($set);
        } finally {
            q50_user_restore($t, $snap);
            $after = q50_user_snapshot($t);
            if (json_encode($after) !== json_encode($snap)) {
                $t->fail('pemulihan user QA tidak persis sama dengan sebelumnya (jurnal dipertahankan)');
            }
            @unlink(q50_jfile('user'));
        }
    }

    // ------------------------------------------------------------------ role preset QA50-ROLE-*

    function q50_preset_perms($key)
    {
        $p = [
            'OD'     => ['Allow Access Website', 'List Archive', Q50_OD],
            'LIST'   => ['Allow Access Website', 'List Archive'],
            'NOLIST' => ['Allow Access Website'],
        ];
        if (!isset($p[$key])) {
            throw new \InvalidArgumentException("preset role '$key' tidak dikenal");
        }

        return $p[$key];
    }

    /** Hapus SEMUA role preset QA50-ROLE-* (user_roles yang menunjuknya, role_permissions, role). Idempoten. */
    function q50_preset_purge($t)
    {
        q50_w($t, function ($c) {
            $ids = $c->table('roles')->where('role_name', 'like', Q50_ROLE_PREFIX . '%')->pluck('id_role')->all();
            if ($ids) {
                $c->table('user_roles')->whereIn('id_role', $ids)->delete();
                $c->table('role_permissions')->whereIn('id_role', $ids)->delete();
                $c->table('roles')->whereIn('id_role', $ids)->delete();
            }
        });
        $GLOBALS['q50_presets'] = [];
    }

    /** id_role preset $key (dibuat bila belum ada di run ini). */
    function q50_preset($t, $key)
    {
        if (isset($GLOBALS['q50_presets'][$key])) {
            return $GLOBALS['q50_presets'][$key];
        }
        $names = q50_preset_perms($key);
        $id = q50_w($t, function ($c) use ($key, $names) {
            $now = date('Y-m-d H:i:s');
            $old = $c->table('roles')->where('role_name', Q50_ROLE_PREFIX . $key)->pluck('id_role')->all();
            if ($old) {
                $c->table('user_roles')->whereIn('id_role', $old)->delete();
                $c->table('role_permissions')->whereIn('id_role', $old)->delete();
                $c->table('roles')->whereIn('id_role', $old)->delete();
            }
            $id = $c->table('roles')->insertGetId([
                'role_name' => Q50_ROLE_PREFIX . $key, 'description' => 'QA ED-1250 preset', 'is_superadmin' => 4,
                'is_active' => 1, 'is_used' => 1, 'created_by' => 'QA50', 'created_at' => $now, 'updated_by' => 'QA50', 'updated_at' => $now,
            ]);
            foreach ($names as $name) {
                $perm = $c->table('permissions')->where('permission_name', $name)->first();
                if (!$perm) {
                    throw new \RuntimeException("prasyarat: permission '$name' tidak ada di DB uji");
                }
                $c->table('role_permissions')->insert([
                    'id_role' => $id, 'id_permission' => $perm->id_permission, 'permission_name' => $perm->permission_name,
                    'created_at' => $now, 'updated_at' => $now, 'created_by' => 'QA50', 'updated_by' => 'QA50',
                ]);
            }

            return (int) $id;
        });
        $GLOBALS['q50_presets'][$key] = $id;

        return $id;
    }

    /** Kunci preset -> id_role preset; angka hanya boleh role sistem 1/2/3 (role developer lain ditolak). */
    function q50_role_ref($t, $role)
    {
        if (is_int($role) || (is_string($role) && ctype_digit($role))) {
            if (!in_array((int) $role, [1, 2, 3], true)) {
                throw new \InvalidArgumentException("role developer $role tidak boleh dipakai skenario (pakai preset QA50-ROLE-*)");
            }

            return (int) $role;
        }

        return q50_preset($t, $role);
    }

    // ------------------------------------------------------------------ QA_USER2 (user_roles sementara = preset, berjurnal)

    function q50_u2_id($t)
    {
        return $t->db()->table('users')->where('username', $t->conf('QA_USER2'))->value('id_user');
    }

    function q50_u2_snapshot($t)
    {
        return q50_rows($t->db()->table('user_roles')->where('id_user', q50_u2_id($t))->orderBy('id_user_role')->get());
    }

    function q50_u2_write($t, $uid, array $rows)
    {
        q50_w($t, function ($c) use ($uid, $rows) {
            $c->table('user_roles')->where('id_user', $uid)->delete();
            if ($rows) {
                $c->table('user_roles')->insert($rows);
            }
        });
    }

    function q50_u2_recover($t)
    {
        if (is_file(q50_jfile('user2'))) {
            $snap = json_decode(file_get_contents(q50_jfile('user2')), true);
            if (is_array($snap) && !empty($snap['rows'])) {
                q50_u2_write($t, $snap['uid'], $snap['rows']);
            }
            @unlink(q50_jfile('user2'));
        }
    }

    /** Jalankan $fn($idRolePreset, $use) dengan QA_USER2 HANYA di preset $key; $use($key2) menukar preset. Aslinya dipulihkan di finally. */
    function q50_with_u2_preset($t, $key, callable $fn)
    {
        q50_u2_recover($t);
        $uid = q50_u2_id($t);
        $rows = q50_u2_snapshot($t);
        $t->true($uid !== null && count($rows) >= 1, 'prasyarat: QA_USER2 ada dan punya role (dipulihkan persis sesudahnya)');
        file_put_contents(q50_jfile('user2'), json_encode(['uid' => $uid, 'rows' => $rows]));
        $use = function ($k) use ($t, $uid) {
            $role = q50_preset($t, $k);
            $now = date('Y-m-d H:i:s');
            q50_u2_write($t, $uid, [['id_user' => $uid, 'id_role' => $role, 'is_all_location' => 1, 'id_location' => null, 'created_at' => $now, 'updated_at' => $now]]);

            return $role;
        };
        try {
            $role = $use($key);

            return $fn($role, $use);
        } finally {
            q50_u2_write($t, $uid, $rows);
            $after = q50_u2_snapshot($t);
            @unlink(q50_jfile('user2'));
            if (json_encode($after) !== json_encode($rows)) {
                $t->fail('pemulihan user_roles QA_USER2 tidak persis sama dengan sebelumnya');
            }
        }
    }

    /** Token QA_USER2 terikat DB QA (force_login hanya mencabut token user2 sendiri). */
    function q50_u2_session($t)
    {
        $s = $t->login('QA_USER2');
        $r = $t->bind($s, q50_dbname($t));
        $t->eq($r[0], 200, 'bind QA_USER2');

        return $s;
    }

    // ------------------------------------------------------------------ baris column_display_settings documentArchive

    function q50_cds_row($t)
    {
        return q50_rows($t->db()->table('column_display_settings')->where('module_name', 'documentArchive')->get());
    }

    /** Snapshot utuh baris documentArchive + jurnal; pulihkan lewat q50_cds_restore (hapus lalu masukkan, id sama). */
    function q50_cds_snapshot($t)
    {
        $rows = q50_cds_row($t);
        file_put_contents(q50_jfile('cds'), json_encode($rows));

        return $rows;
    }

    function q50_cds_restore($t, array $rows)
    {
        q50_w($t, function ($c) use ($rows) {
            $c->table('column_display_settings')->where('module_name', 'documentArchive')->delete();
            if ($rows) {
                $c->table('column_display_settings')->insert($rows);
            }
        });
        @unlink(q50_jfile('cds'));
    }

    function q50_cds_recover($t)
    {
        if (is_file(q50_jfile('cds'))) {
            $rows = json_decode(file_get_contents(q50_jfile('cds')), true);
            if (is_array($rows) && $rows) {
                q50_cds_restore($t, $rows);
            }
            @unlink(q50_jfile('cds'));
        }
    }

    /** data_index tiap item (array data_index ditulis 'a.b'). */
    function q50_seq($list)
    {
        return array_map(function ($col) {
            return is_array($col['data_index']) ? implode('.', $col['data_index']) : $col['data_index'];
        }, (array) $list);
    }

    // ------------------------------------------------------------------ data uji archive

    function q50_new_id($t)
    {
        return q50_w($t, function ($c) {
            return \Modules\V5\Entities\Helper\MyHelper::generateId();
        });
    }

    function q50_name($label)
    {
        return Q50_PREFIX . $label . '-' . substr(uniqid(), -6) . mt_rand(10, 99);
    }

    /**
     * Sisipkan satu baris archive uji.
     * $a: name, type (1 folder / 2 dokumen), parent, all (is_all_location 0/1, default 1), locs (kode lokasi),
     *     perm (is_folder_permission), by (created_by, default 'QA50'), active (default 1), status (default 1),
     *     verified (1 = is_verified 1 + verified_at + verified_by, K-4 a), iv (nilai mentah is_verified: null | 0 | 2 ...),
     *     doc => ['type' => int, 'salesman' => string].
     * @return string id_archive
     */
    function q50_add($t, array $a)
    {
        $id = q50_new_id($t);
        $locs = q50_locs($t);
        $now = date('Y-m-d H:i:s');

        q50_w($t, function ($c) use ($a, $id, $now, $locs) {
            $row = [
                'id_archive'           => $id,
                'id_archive_parent'    => $a['parent'] ?? null,
                'name'                 => $a['name'],
                'type'                 => $a['type'] ?? 1,
                'status'               => $a['status'] ?? 1,
                'is_all_location'      => $a['all'] ?? 1,
                'is_folder_permission' => $a['perm'] ?? 0,
                'is_active'            => $a['active'] ?? 1,
                'created_at'           => $now,
                'created_by'           => $a['by'] ?? 'QA50',
                'updated_at'           => $now,
                'updated_by'           => $a['by'] ?? 'QA50',
            ];
            if (!empty($a['verified'])) {
                $row['is_verified'] = 1;
                $row['verified_at'] = $now;
                $row['verified_by'] = 'QA50';
            } elseif (array_key_exists('iv', $a)) {
                $row['is_verified'] = $a['iv'];
            }
            $c->table('archives')->insert($row);

            foreach ($a['locs'] ?? [] as $code) {
                $c->table('archive_locations')->insert(['id_archive' => $id, 'id_location' => $locs[$code]]);
            }

            if (($a['type'] ?? 1) == 2) {
                $doc = $a['doc'] ?? [];
                $c->table('archive_documents')->insert([
                    'id_archive_document'   => \Modules\V5\Entities\Helper\MyHelper::generateId(),
                    'id_archive'            => $id,
                    'id_transaction'        => 'QA50TX' . substr($id, -10),
                    'transaction_no'        => $a['name'],
                    'transaction_type'      => $doc['type'] ?? 6,
                    'related_employee_name' => $doc['salesman'] ?? null,
                    'created_at'            => $now,
                    'updated_at'            => $now,
                ]);
            }
        });

        return $id;
    }

    function q50_folder($t, $label, array $a = [])
    {
        return q50_add($t, $a + ['name' => q50_name($label), 'type' => 1]);
    }

    /** $tx = transaction type (default 6). */
    function q50_doc($t, $label, array $a = [], $tx = 6)
    {
        return q50_add($t, $a + ['name' => q50_name($label), 'type' => 2, 'doc' => ['type' => $tx]]);
    }

    function q50_set_archive($t, $id, array $cols)
    {
        q50_w($t, function ($c) use ($id, $cols) {
            $c->table('archives')->where('id_archive', $id)->update($cols);
        });
    }

    function q50_row($t, $id)
    {
        $row = $t->db()->table('archives')->where('id_archive', $id)->first();

        return $row ? (array) $row : null;
    }

    /** Sisipkan baris archive_permissions (hak 0/1) satu user di satu folder. */
    function q50_perm($t, $idArchive, $idUser, $view, $update = 0, $delete = 0, $store = 0)
    {
        $now = date('Y-m-d H:i:s');
        q50_w($t, function ($c) use ($idArchive, $idUser, $view, $update, $delete, $store, $now) {
            $c->table('archive_permissions')->insert([
                'id_archive_permission' => \Modules\V5\Entities\Helper\MyHelper::generateId(),
                'id_archive'            => $idArchive,
                'id_user'               => $idUser,
                'is_view'               => $view,
                'is_update'             => $update,
                'is_delete'             => $delete,
                'is_store'              => $store,
                'created_at'            => $now,
                'created_by'            => 'QA50',
                'updated_at'            => $now,
                'updated_by'            => 'QA50',
            ]);
        });
    }

    /** Hapus semua baris archive / dokumen / lokasi / permission berawalan QA50- (idempoten). */
    function q50_purge($t)
    {
        q50_w($t, function ($c) {
            $ids = $c->table('archives')->where('name', 'like', Q50_PREFIX . '%')->pluck('id_archive')->all();
            foreach (array_chunk($ids, 500) as $chunk) {
                $c->table('archive_permissions')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_documents')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_locations')->whereIn('id_archive', $chunk)->delete();
                $c->table('archives')->whereIn('id_archive', $chunk)->delete();
            }
        });
    }

    /**
     * Fixture standar (semua berawalan QA50-). SMR = lokasi kerja user uji, JOG = lokasi lain. v = verified, u = unverified.
     *
     *   P (root)
     *    +- F
     *        +- E  (kosong 0/0)
     *        +- AV (semua verified: dav1 v 6, dav2 v 7)
     *        +- MX (campuran: dmx1 v 7, dmx2 u 7, dmx3 u 29)  -- MXc (level 4: dmxc1 v 8)
     *        +- NV (tanpa subfolder, tanpa verified: dnv1 u 6, dnv2 NULL 8)
     *        +- SJ (JOG saja: dsj1 u, dsj2 v)       +- SX (nonaktif: dsx1 u)
     *        +- SP (Izin On, tanpa View: dsp1 v 6)  -- SPc (dspc1 u 7)
     *        +- SV (Izin On, View untuk QA_USER: dsv1 v 7)
     *        dokumen F: dF1 v 6, dF2 u 7, dF3 v status 2 (Handed Over) 8, dF4 u status 3 (Taken) 6, dF5 NULL 9,
     *                   dF6 is_verified=2 7, dJF u JOG saja 7, dNEG v is_active -1 6, dDEL v is_active 0 7
     *   OTH (root: dO1 u 7)   ER (root, kosong)   ALV (root, semua verified: dalv1 v 6)
     *   PRIV (root, Izin On, tanpa View: dPR u 8) -- PRc (dPRc u 6)   JOGR (root, JOG saja: dJR u 6)
     *   dokumen root: dR v 8, dR2 u JOG saja 7, dR3 u 6
     * Mengembalikan peta kunci => id_archive.
     */
    function q50_tree($t)
    {
        $uid = q50_uid($t);
        $x = [];
        $x['P'] = q50_folder($t, 'P');
        $x['F'] = q50_folder($t, 'F', ['parent' => $x['P']]);
        $x['E'] = q50_folder($t, 'E', ['parent' => $x['F']]);
        $x['AV'] = q50_folder($t, 'AV', ['parent' => $x['F']]);
        $x['MX'] = q50_folder($t, 'MX', ['parent' => $x['F']]);
        $x['MXc'] = q50_folder($t, 'MXc', ['parent' => $x['MX']]);
        $x['NV'] = q50_folder($t, 'NV', ['parent' => $x['F']]);
        $x['SJ'] = q50_folder($t, 'SJ', ['parent' => $x['F'], 'all' => 0, 'locs' => ['JOG']]);
        $x['SX'] = q50_folder($t, 'SX', ['parent' => $x['F'], 'active' => 0]);
        $x['SP'] = q50_folder($t, 'SP', ['parent' => $x['F'], 'perm' => 1]);
        $x['SPc'] = q50_folder($t, 'SPc', ['parent' => $x['SP']]);
        $x['SV'] = q50_folder($t, 'SV', ['parent' => $x['F'], 'perm' => 1]);
        q50_perm($t, $x['SV'], $uid, 1);
        $x['OTH'] = q50_folder($t, 'OTH');
        $x['ER'] = q50_folder($t, 'ER');
        $x['ALV'] = q50_folder($t, 'ALV');
        $x['PRIV'] = q50_folder($t, 'PRIV', ['perm' => 1]);
        $x['PRc'] = q50_folder($t, 'PRc', ['parent' => $x['PRIV']]);
        $x['JOGR'] = q50_folder($t, 'JOGR', ['all' => 0, 'locs' => ['JOG']]);

        $x['dav1'] = q50_doc($t, 'dav1', ['parent' => $x['AV'], 'verified' => 1], 6);
        $x['dav2'] = q50_doc($t, 'dav2', ['parent' => $x['AV'], 'verified' => 1], 7);
        $x['dmx1'] = q50_doc($t, 'dmx1', ['parent' => $x['MX'], 'verified' => 1], 7);
        $x['dmx2'] = q50_doc($t, 'dmx2', ['parent' => $x['MX']], 7);
        $x['dmx3'] = q50_doc($t, 'dmx3', ['parent' => $x['MX']], 29);
        $x['dmxc1'] = q50_doc($t, 'dmxc1', ['parent' => $x['MXc'], 'verified' => 1], 8);
        $x['dnv1'] = q50_doc($t, 'dnv1', ['parent' => $x['NV']], 6);
        $x['dnv2'] = q50_doc($t, 'dnv2', ['parent' => $x['NV'], 'iv' => null], 8);
        $x['dsj1'] = q50_doc($t, 'dsj1', ['parent' => $x['SJ'], 'all' => 0, 'locs' => ['JOG']], 6);
        $x['dsj2'] = q50_doc($t, 'dsj2', ['parent' => $x['SJ'], 'all' => 0, 'locs' => ['JOG'], 'verified' => 1], 6);
        $x['dsx1'] = q50_doc($t, 'dsx1', ['parent' => $x['SX']], 6);
        $x['dsp1'] = q50_doc($t, 'dsp1', ['parent' => $x['SP'], 'verified' => 1], 6);
        $x['dspc1'] = q50_doc($t, 'dspc1', ['parent' => $x['SPc']], 7);
        $x['dsv1'] = q50_doc($t, 'dsv1', ['parent' => $x['SV'], 'verified' => 1], 7);
        $x['dF1'] = q50_doc($t, 'dF1', ['parent' => $x['F'], 'verified' => 1], 6);
        $x['dF2'] = q50_doc($t, 'dF2', ['parent' => $x['F']], 7);
        $x['dF3'] = q50_doc($t, 'dF3', ['parent' => $x['F'], 'verified' => 1, 'status' => 2], 8);
        $x['dF4'] = q50_doc($t, 'dF4', ['parent' => $x['F'], 'status' => 3], 6);
        $x['dF5'] = q50_doc($t, 'dF5', ['parent' => $x['F'], 'iv' => null], 9);
        $x['dF6'] = q50_doc($t, 'dF6', ['parent' => $x['F'], 'iv' => 2], 7);
        $x['dJF'] = q50_doc($t, 'dJF', ['parent' => $x['F'], 'all' => 0, 'locs' => ['JOG']], 7);
        $x['dNEG'] = q50_doc($t, 'dNEG', ['parent' => $x['F'], 'active' => -1, 'verified' => 1], 6);
        $x['dDEL'] = q50_doc($t, 'dDEL', ['parent' => $x['F'], 'active' => 0, 'verified' => 1], 7);
        $x['dO1'] = q50_doc($t, 'dO1', ['parent' => $x['OTH']], 7);
        $x['dalv1'] = q50_doc($t, 'dalv1', ['parent' => $x['ALV'], 'verified' => 1], 6);
        $x['dPR'] = q50_doc($t, 'dPR', ['parent' => $x['PRIV']], 8);
        $x['dPRc'] = q50_doc($t, 'dPRc', ['parent' => $x['PRc']], 6);
        $x['dJR'] = q50_doc($t, 'dJR', ['parent' => $x['JOGR'], 'all' => 0, 'locs' => ['JOG']], 6);
        $x['dR'] = q50_doc($t, 'dR', ['verified' => 1], 8);
        $x['dR2'] = q50_doc($t, 'dR2', ['all' => 0, 'locs' => ['JOG']], 7);
        $x['dR3'] = q50_doc($t, 'dR3', [], 6);

        return $x;
    }

    // ------------------------------------------------------------------ keadaan developer, baseline, cleanup

    function q50_cols($t, $table)
    {
        $out = [];
        foreach ($t->db()->select("SHOW COLUMNS FROM `$table`") as $r) {
            $out[] = $r->Field;
        }

        return $out;
    }

    /** [jumlah baris, jumlah CRC32 per baris] satu tabel (opsional WHERE). */
    function q50_sum($t, $table, array $cols, $where = '', array $bind = [])
    {
        $parts = array_map(function ($c) {
            return "IFNULL(CAST(`$c` AS CHAR),'~')";
        }, $cols);
        $r = $t->db()->selectOne("SELECT COUNT(*) n, IFNULL(SUM(CRC32(CONCAT_WS('|'," . implode(',', $parts) . '))),0) h FROM `' . $table . '` ' . $where, $bind);

        return [(int) $r->n, (string) $r->h];
    }

    /**
     * Keadaan di luar baris sendiri: archives & turunannya (selain QA50-), tabel opname (semua), roles / role_permissions /
     * user_roles (semua; preset sudah dibuang saat dibandingkan), users (id, bahasa), employees (id, user, lokasi), seluruh
     * column_display_settings. updater_logs hanya dihitung (DU-5 a: boleh berbeda, dicatat).
     */
    function q50_devstate($t)
    {
        $out = [];
        $like = [Q50_PREFIX . '%'];
        $out['archives'] = q50_sum($t, 'archives', q50_cols($t, 'archives'), 'WHERE name NOT LIKE ?', $like);
        $out['archive_documents'] = q50_sum($t, 'archive_documents', q50_cols($t, 'archive_documents'), 'WHERE IFNULL(transaction_no,"") NOT LIKE ?', $like);
        $out['archive_locations'] = q50_sum($t, 'archive_locations', q50_cols($t, 'archive_locations'), 'WHERE id_archive NOT IN (SELECT id_archive FROM archives WHERE name LIKE ?)', $like);
        $out['archive_permissions'] = q50_sum($t, 'archive_permissions', q50_cols($t, 'archive_permissions'), 'WHERE id_archive NOT IN (SELECT id_archive FROM archives WHERE name LIKE ?)', $like);
        foreach (['archive_opnames', 'archive_opname_folders', 'archive_opname_documents', 'roles', 'role_permissions', 'user_roles', 'employee_locations', 'column_display_settings'] as $table) {
            $out[$table] = q50_sum($t, $table, q50_cols($t, $table));
        }
        $out['users(id,language)'] = q50_sum($t, 'users', ['id_user', 'language']);
        $out['employees(id,user,all)'] = q50_sum($t, 'employees', ['id_employee', 'id_user', 'is_all_location']);

        return $out;
    }

    /**
     * Awal AC: pulihkan sisa run yang mati (user, user2, cds), buang preset dan data QA50- (sapu sisa), catat keadaan di luar
     * baris sendiri.
     */
    function q50_baseline($t)
    {
        q50_recover_user($t);
        q50_u2_recover($t);
        q50_cds_recover($t);
        q50_preset_purge($t);
        q50_purge($t);
        $GLOBALS['q50_base'] = q50_devstate($t);
        $b = $GLOBALS['q50_base'];
        $t->note(sprintf('keadaan di luar baris QA50- dicatat: %d archives, %d dokumen, %d role, %d user_roles', $b['archives'][0], $b['archive_documents'][0], $b['roles'][0], $b['user_roles'][0]));

        return $b;
    }

    /** Akhir AC (finally): buang data QA50- dan preset, buktikan keadaan di luar baris sendiri sama persis. */
    function q50_cleanup($t, $label = 'data uji dibuang')
    {
        q50_purge($t);
        q50_preset_purge($t);
        $c = $t->db();
        $now = q50_devstate($t);
        $base = $GLOBALS['q50_base'] ?? null;
        $t->eq($c->table('archives')->where('name', 'like', Q50_PREFIX . '%')->count(), 0, $label . ' (archives QA50-)');
        $t->eq($c->table('archive_documents')->where('transaction_no', 'like', Q50_PREFIX . '%')->count(), 0, $label . ' (archive_documents)');
        $t->eq($c->table('archive_permissions')->where('created_by', 'QA50')->count(), 0, $label . ' (archive_permissions)');
        $t->eq($c->table('roles')->where('role_name', 'like', Q50_ROLE_PREFIX . '%')->count(), 0, $label . ' (role preset QA50-ROLE-)');
        $t->eq($c->table('role_permissions')->where('created_by', 'QA50')->count(), 0, $label . ' (role_permissions QA50)');
        if ($base === null) {
            $t->fail($label . ': keadaan sebelum AC tidak tercatat (baseline tidak jalan)');
        }
        $diff = [];
        foreach ($base as $k => $v) {
            if (json_encode($v) !== json_encode($now[$k])) {
                $diff[] = $k . ' ' . json_encode($v) . ' -> ' . json_encode($now[$k]);
            }
        }
        $t->true($diff === [], $label . ': keadaan di luar baris sendiri sama persis dengan sebelum AC (' . implode('; ', $diff) . ')');
    }

    // ------------------------------------------------------------------ request

    function q50_url($path, array $query = [])
    {
        return $path . ($query ? '?' . http_build_query($query) : '');
    }

    function q50_code($r)
    {
        return $r[1]['code'] ?? $r[1]['msg_code'] ?? null;
    }

    function q50_no500($t, $r, $label)
    {
        $t->true($r[0] < 500, $label . ': HTTP ' . $r[0] . ' (tidak boleh 5xx)');
    }

    /** GET archives; 'search' boleh array (di-JSON-kan) atau string JSON mentah; pagination default 1000. */
    function q50_get($t, $s, array $q = [])
    {
        if (isset($q['search']) && is_array($q['search'])) {
            $q['search'] = json_encode($q['search'], JSON_UNESCAPED_UNICODE);
        }
        if (!array_key_exists('pagination', $q)) {
            $q['pagination'] = 1000;
        }

        return $t->call($s, 'GET', q50_url(Q50_BASE . '/archives', $q));
    }

    /** Semua halaman GET archives: ['first' => respons hal. 1, 'rows' => semua baris, 'ids' => id urut, 'total' => total hal. 1, 'pages' => n]. */
    function q50_all($t, $s, array $q = [], $label = 'list')
    {
        $rows = [];
        $first = null;
        $page = 1;
        do {
            $r = q50_get($t, $s, $q + ['pagination' => 1000] + ['page' => $page]);
            $r[0] === 200 || $t->fail($label . ' hal. ' . $page . ': HTTP ' . $r[0] . ' ' . substr(json_encode($r[1]), 0, 300));
            $first = $first ?? $r;
            foreach ($r[1]['result']['data'] ?? [] as $row) {
                $rows[] = $row;
            }
            $last = (int) ($r[1]['result']['last_page'] ?? 1);
            $page++;
            $q['page'] = $page;
        } while ($page <= $last && $page < 400);

        return [
            'first' => $first,
            'rows'  => $rows,
            'ids'   => array_column($rows, 'id_archive'),
            'total' => (int) ($first[1]['result']['total'] ?? -1),
            'pages' => $page - 1,
        ];
    }

    /** id_archive => baris dari hasil q50_all. */
    function q50_map(array $all)
    {
        $map = [];
        foreach ($all['rows'] as $row) {
            $map[$row['id_archive']] = $row;
        }

        return $map;
    }

    function q50_is_folder_row(array $row)
    {
        return ($row['type'] ?? null) === 'Folder';
    }

    /** Himpunan sama persis (tanpa memperhatikan urutan) + tanpa duplikat; pesan memuat selisih bernama. */
    function q50_same_set($t, array $got, array $exp, $label, $names = null)
    {
        $t->eq(count($got), count(array_unique($got)), $label . ': tanpa duplikat antar halaman');
        $missing = array_values(array_diff($exp, $got));
        $extra = array_values(array_diff($got, $exp));
        $nm = function ($ids) use ($names) {
            return implode(',', array_map(function ($i) use ($names) {
                return $names[$i] ?? $i;
            }, array_slice($ids, 0, 8)));
        };
        $t->true($missing === [] && $extra === [], $label . ': himpunan baris = oracle (kurang ' . count($missing) . ' [' . $nm($missing) . '], lebih ' . count($extra) . ' [' . $nm($extra) . '])');
    }

    // ------------------------------------------------------------------ oracle

    /** Keadaan user uji untuk oracle: ['smr' | 'jog' | 'smrjog' | 'bypass']. */
    function q50_u($t, $mode)
    {
        $base = ['username' => q50_uname($t), 'uid' => q50_uid($t)];
        switch ($mode) {
            case 'smr':
                return $base + ['bypass' => false, 'locs' => ['SMR']];
            case 'jog':
                return $base + ['bypass' => false, 'locs' => ['JOG']];
            default:
                return $base + ['bypass' => true, 'locs' => null];
        }
    }

    function q50_opts($mode, $role = 'OD')
    {
        switch ($mode) {
            case 'smr':
                return ['role' => $role, 'emp' => ['SMR']];
            case 'jog':
                return ['role' => $role, 'emp' => ['JOG']];
            default:
                return ['role' => 1];
        }
    }

    /**
     * Baris archive aktif (is_active > 0) dari tabel mentah: id => [parent, type, name, all, iv, tx[]]. Tanpa kode BE.
     */
    function q50_model($t)
    {
        $c = $t->db();
        $m = [];
        foreach ($c->table('archives as a')->leftJoin('archive_documents as d', 'd.id_archive', '=', 'a.id_archive')
            ->where('a.is_active', '>', 0)
            ->get(['a.id_archive', 'a.id_archive_parent', 'a.name', 'a.type', 'a.is_all_location', 'a.is_verified', 'a.updated_by', 'a.updated_at', 'd.transaction_type']) as $r) {
            if (!isset($m[$r->id_archive])) {
                $m[$r->id_archive] = [
                    'parent' => $r->id_archive_parent, 'type' => (int) $r->type, 'name' => $r->name, 'uby' => $r->updated_by, 'uat' => $r->updated_at, 'all' => (int) $r->is_all_location,
                    'iv' => $r->is_verified === null ? null : (int) $r->is_verified, 'tx' => [],
                ];
            }
            if ($r->transaction_type !== null) {
                $m[$r->id_archive]['tx'][] = (int) $r->transaction_type;
            }
        }
        $loc = [];
        foreach ($c->table('archive_locations')->get(['id_archive', 'id_location']) as $r) {
            $loc[$r->id_archive][] = $r->id_location;
        }
        foreach ($m as $id => &$row) {
            $row['loc'] = $loc[$id] ?? [];
        }
        unset($row);

        return $m;
    }

    /**
     * Baris yang seharusnya ada: id => [folder?, nama]. $o: parent (null = level root, id = isi folder, 'FLAT' = cari datar),
     * f (VERIFIED|UNVERIFIED|null), type ('FOLDER' | int | null), name / updby (substring | null), uat ([tgl awal, tgl akhir] Y-m-d inklusif | null). Visibilitas = is_active > 0 + lokasi
     * user (is_all_location 1 atau irisan lokasi; bypass = semua). Filter f hanya membuang baris dokumen (type 2).
     */
    function q50_expect($t, array $u, array $o, array $model = null)
    {
        $model = $model ?? q50_model($t);
        $locIds = null;
        if ($u['locs'] !== null) {
            $map = q50_locs($t);
            $locIds = array_map(function ($code) use ($map) {
                return $map[$code];
            }, $u['locs']);
        }
        $flat = ($o['parent'] ?? null) === 'FLAT';
        $out = [];
        foreach ($model as $id => $r) {
            if (!($locIds === null || $r['all'] === 1 || count(array_intersect($r['loc'], $locIds)) > 0)) {
                continue;
            }
            if (!$flat) {
                if (($o['parent'] ?? null) === null ? $r['parent'] !== null : $r['parent'] !== $o['parent']) {
                    continue;
                }
            }
            if (isset($o['type'])) {
                if ($o['type'] === 'FOLDER') {
                    if ($r['type'] !== 1) {
                        continue;
                    }
                } elseif ($r['type'] !== 2 || !in_array((int) $o['type'], $r['tx'], true)) {
                    continue;
                }
            }
            if (isset($o['name']) && stripos($r['name'], $o['name']) === false) {
                continue;
            }
            if (isset($o['updby']) && stripos((string) $r['uby'], $o['updby']) === false) {
                continue;
            }
            if (isset($o['uat']) && !($r['uat'] !== null && substr($r['uat'], 0, 10) >= $o['uat'][0] && substr($r['uat'], 0, 10) <= $o['uat'][1])) {
                continue;
            }
            if (($o['f'] ?? null) !== null && $r['type'] === 2) {
                $ver = $r['iv'] === 1;
                if (($o['f'] === 'VERIFIED') !== $ver) {
                    continue;
                }
            }
            $out[$id] = [$r['type'] === 1, $r['name']];
        }

        return $out;
    }

    /** id => nama untuk pesan selisih. */
    function q50_names(array $exp)
    {
        return array_map(function ($v) {
            return $v[1];
        }, $exp);
    }

    /** module_name semua modul di GET setting/display-columns (index), semua halaman. */
    function q50_index_modules($t, $s)
    {
        $names = [];
        $page = 1;
        do {
            $r = $t->call($s, 'GET', rtrim(Q50_DS, '/') . '?pagination=200&page=' . $page);
            $t->status($r, 200, 'index display-columns hal. ' . $page);
            foreach ($r[1]['result']['data'] ?? [] as $row) {
                $names[] = $row['module_name'] ?? ($row['moduleName'] ?? null);
            }
            $last = (int) ($r[1]['result']['last_page'] ?? 1);
            $page++;
        } while ($page <= $last && $page < 100);
    
        return $names;
    }

    /**
     * Urutan baris $ids (urutan respons) sama dengan ORDER BY di DB. $by = [[kolom, 'asc'|'desc'], ...]. Membandingkan nilai
     * kolom (huruf kecil), bukan id, jadi baris bernama sama boleh bertukar tempat.
     */
    function q50_order_ok($t, array $ids, array $by)
    {
        $ids = array_slice($ids, 0, 2000); // awal urutan respons cukup (urutan relatif tetap sama bila terurut global)
        if (!$ids) {
            return true;
        }
        $got = [];
        $byId = [];
        foreach (array_chunk($ids, 2000) as $chunk) {
            foreach ($t->db()->table('archives')->whereIn('id_archive', $chunk)->get(['id_archive', 'type', 'name']) as $r) {
                $byId[$r->id_archive] = (array) $r;
            }
        }
        foreach ($ids as $id) {
            $got[] = array_map(function ($b) use ($byId, $id) {
                return strtolower((string) $byId[$id][$b[0]]);
            }, $by);
        }
        $q = $t->db()->table('archives')->whereIn('id_archive', $ids);
        foreach ($by as $b) {
            $q->orderBy($b[0], $b[1]);
        }
        $exp = [];
        foreach ($q->get(array_map(function ($b) {
            return $b[0];
        }, $by)) as $r) {
            $exp[] = array_map(function ($b) use ($r) {
                return strtolower((string) ((array) $r)[$b[0]]);
            }, $by);
        }

        return json_encode($got) === json_encode($exp);
    }

    // ------------------------------------------------------------------ R-1 / R-2 (review 2026-10-26)

    /**
     * Oracle aturan sisip kontrak §2 (PUT non-pemegang): item tersaring ($hidden) kiriman dibuang, item tersaring dari baris tersimpan
     * disisipkan sesudah item yang mendahuluinya di baris tersimpan; pendahulu tidak ada di kiriman -> indeks semula, paling jauh di akhir.
     * Semua argumen = urutan data_index (string).
     */
    function q50_restore_expect(array $stored, array $sent, array $hidden)
    {
        $result = array_values(array_filter($sent, function ($k) use ($hidden) {
            return !in_array($k, $hidden, true);
        }));
        $stored = array_values($stored);
        foreach ($stored as $i => $k) {
            if (!in_array($k, $hidden, true)) {
                continue;
            }
            $pos = min($i, count($result));
            $prev = $i > 0 ? $stored[$i - 1] : null;
            if ($prev !== null) {
                $at = array_search($prev, $result, true);
                if ($at !== false) {
                    $pos = $at + 1;
                }
            }
            array_splice($result, $pos, 0, [$k]);
        }

        return $result;
    }

    /** Baris $module di respons index display-columns (atau respons PUT): result.data[]; null bila tidak ada di halaman ini. */
    function q50_resp_row(array $resp, $module = 'documentArchive')
    {
        foreach ($resp['result']['data'] ?? [] as $row) {
            if (($row['module_name'] ?? null) === $module) {
                return $row;
            }
        }

        return null;
    }

    /** Urutan data_index suatu list baris respons (list bisa array atau string JSON). */
    function q50_row_seq(array $row, $list)
    {
        $v = $row[$list] ?? null;
        if (is_string($v)) {
            $v = json_decode($v, true);
        }

        return q50_seq((array) $v);
    }

    /**
     * Semua baris index display-columns (semua halaman, urutan respons). Mengembalikan [rows, total, last_page, halaman_terbaca].
     * $extra = query tambahan (mis. ['search' => 'documentArchive']).
     */
    function q50_index_all($t, $s, $pagination = 200, array $extra = [])
    {
        $rows = [];
        $page = 1;
        $total = null;
        $last = 1;
        do {
            $q = http_build_query($extra + ['pagination' => $pagination, 'page' => $page]);
            $r = $t->call($s, 'GET', rtrim(Q50_DS, '/') . '?' . $q);
            $t->status($r, 200, 'index display-columns hal. ' . $page . ' pagination ' . $pagination);
            foreach ($r[1]['result']['data'] ?? [] as $row) {
                $rows[] = $row;
            }
            $total = $r[1]['result']['total'] ?? $total;
            $last = (int) ($r[1]['result']['last_page'] ?? 1);
            $page++;
        } while ($page <= $last && $page < 200);

        return [$rows, $total, $last, $page - 1];
    }
}
