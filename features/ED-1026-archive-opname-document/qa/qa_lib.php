<?php
/**
 * Helper bersama skenario ED-1026 (BUKAN skenario: nama berkas tidak cocok scenario*.php).
 * Di-require_once oleh setiap scenario*.php.
 *
 * Aturan pakai (revisi 2026-10-09: opname tanpa draft + data developer ada di DB uji):
 * - Data uji archive berawalan "QA26-" (folder, dokumen, lokasi, archive_documents). Dibuat lewat q26_add(), dibuang
 *   lewat q26_purge(). Sesi opname hanya ada sesudah POST opnames (Konfirmasi); sesi yang dibuat QA dicatat di jurnal
 *   sesi (.sessions-journal.json).
 * - Data developer BOLEH ada (sesi opname, dokumen verified). q26_baseline() TIDAK menuntut tabel opname kosong atau
 *   archives default: ia mencatat (a) jurnal pra-citra kolom verifikasi `archives` non-QA26 yang tidak default
 *   (.verif-journal.json) dan (b) checksum keadaan developer (q26_devstate). q26_cleanup() memulihkan NILAI SEBELUMNYA
 *   persis (bukan default) untuk baris archives yang ditandai sesi QA, membuang hanya sesi QA, lalu membuktikan checksum
 *   developer sama dengan sebelum AC. Jurnal bertahan bila run mati; baseline run berikutnya memulihkannya lebih dulu.
 * - Teardown tidak jalan bila setup gagal: q26_baseline() dipanggil DI LUAR try; q26_cleanup() hanya di finally.
 * - (ED-1149 BR-1/BR-2/BR-4) TIDAK ada prasyarat/grant pada role developer (3, 5, 6, 22, 29...): "user tanpa hak" / "hanya List
 *   Archive" = role preset QA26-ROLE-NONE / QA26-ROLE-LIST yang dibuat dari NAMA permission eksplisit (q26_preset), dipakai lewat
 *   q26_with_user(['role' => Q26_PRESET_*]) untuk QA_USER atau q26_with_u2_preset() untuk QA_USER2, dan dibuang persis
 *   (q26_preset_purge; bukti di q26_cleanup). Yang diubah sementara hanya user_roles/lokasi/bahasa QA_USER dan QA_USER2, berjurnal
 *   (.restore-journal-q26.json, .restore-journal-q26-user2.json) dan dipulihkan persis.
 *   QA_USER2 boleh login di api_sidomaju (bukan karyawan: preset memuat 'Allow Access Website'). "User lokasi lain" = QA_USER
 *   sendiri dengan keadaan sementara (role, lokasi employee, bahasa) lewat q26_with_user().
 *   Role bawaan QA_USER = 3 (Super Admin, is_superadmin 3: TIDAK bypass lokasi/hak folder). Bypass (superadmin 1/2) = role 1 dan 2.
 * - Tulis lewat koneksi default non-read-only di dalam $t->probe(); baca lewat $t->db() (read-only).
 */

if (!function_exists('q26_dbname')) {

    define('Q26_PREFIX', 'QA26-');
    define('Q26_BASE', 'api/v5/document-archive');
    define('Q26_ROLE_PLAIN', 3); // role bawaan QA_USER sendiri (bukan syarat permission)
    define('Q26_ROLE_PREFIX', 'QA26-ROLE-');
    define('Q26_PRESET_NONE', 'NONE'); // preset: akses website saja (tanpa permission Archive)
    define('Q26_PRESET_LIST', 'LIST'); // preset: akses website + List Archive (tanpa Opname Document)
    define('Q26_RANDOM_ID', '999999999999999999999999999999');

    function q26_dbname($t)
    {
        return $t->dbs()[0];
    }

    /** Jalankan $fn($conn) dengan koneksi tulis ke DB QA (di dalam probe). */
    function q26_w($t, callable $fn)
    {
        $db = q26_dbname($t);

        return $t->probe(function () use ($fn, $db) {
            return $fn(\Illuminate\Support\Facades\DB::connection($db));
        });
    }

    function q26_rows($collection)
    {
        $out = [];
        foreach ($collection as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }

    function q26_journal()
    {
        return __DIR__ . '/.restore-journal-q26.json';
    }

    function q26_sjournal()
    {
        return __DIR__ . '/.sessions-journal.json';
    }

    function q26_uid($t)
    {
        return $t->db()->table('users')->where('username', $t->conf('QA_USER'))->value('id_user');
    }

    function q26_uname($t)
    {
        return $t->db()->table('users')->where('username', $t->conf('QA_USER'))->value('username');
    }

    /** kode lokasi => id lokasi */
    function q26_locs($t)
    {
        return $t->db()->table('locations')->pluck('id_location', 'location_code')->all();
    }

    // ------------------------------------------------------------------ waktu (tanggal server Asia/Jakarta)

    function q26_now($modify = null)
    {
        $d = new \DateTime('now', new \DateTimeZone('Asia/Jakarta'));
        if ($modify) {
            $d->modify($modify);
        }

        return $d;
    }

    function q26_today()
    {
        return q26_now()->format('Y-m-d');
    }

    // ------------------------------------------------------------------ user QA (role / lokasi / bahasa)

    function q26_user_snapshot($t)
    {
        $c = $t->db();
        $uid = q26_uid($t);
        $emp = $c->table('employees')->where('id_user', $uid)->first();

        return [
            'uid'      => $uid,
            'roles'    => q26_rows($c->table('user_roles')->where('id_user', $uid)->orderBy('id_user_role')->get()),
            'lang'     => $c->table('users')->where('id_user', $uid)->value('language'),
            'emp_id'   => $emp->id_employee ?? null,
            'emp_all'  => $emp->is_all_location ?? null,
            'emp_locs' => $emp ? q26_rows($c->table('employee_locations')->where('id_employee', $emp->id_employee)->orderBy('id_employee_location')->get()) : [],
        ];
    }

    function q26_user_restore($t, array $snap)
    {
        q26_w($t, function ($c) use ($snap) {
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

    /** opts: 'role' => kunci preset ('NONE'|'LIST') | id role sistem 1/2/3 | daftar keduanya, 'emp' => 'all'|['SMR', ...] (kode lokasi), 'lang' => 'ID'|'EN' */
    function q26_user_apply($t, array $snap, array $opts)
    {
        $locs = q26_locs($t);
        $roleIds = isset($opts['role']) ? array_map(function ($r) use ($t) {
            return q26_role_ref($t, $r);
        }, (array) $opts['role']) : [];
        q26_w($t, function ($c) use ($snap, $opts, $locs, $roleIds) {
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

    function q26_recover_user($t)
    {
        if (is_file(q26_journal())) {
            $snap = json_decode(file_get_contents(q26_journal()), true);
            if (is_array($snap)) {
                q26_user_restore($t, $snap);
            }
            @unlink(q26_journal());
        }
    }

    /**
     * Jalankan $fn($set) dengan user QA di keadaan $opts. $set(array $opts) mengganti keadaan (relatif terhadap keadaan
     * asli). Keadaan asli dipulihkan dan diverifikasi persis di finally.
     */
    function q26_with_user($t, array $opts, callable $fn)
    {
        q26_recover_user($t);
        $snap = q26_user_snapshot($t);
        file_put_contents(q26_journal(), json_encode($snap));

        $set = function (array $o) use ($t, $snap) {
            q26_user_restore($t, $snap);
            q26_user_apply($t, $snap, $o);
        };

        try {
            $set($opts);

            return $fn($set);
        } finally {
            q26_user_restore($t, $snap);
            $after = q26_user_snapshot($t);
            if (json_encode($after) !== json_encode($snap)) {
                $t->fail('pemulihan user QA tidak persis sama dengan sebelumnya (jurnal dipertahankan)');
            }
            @unlink(q26_journal());
        }
    }

    // ------------------------------------------------------------------ role preset QA26-ROLE-* (ED-1149 BR-1/BR-2/BR-4)

    /** Nama permission eksplisit per preset (dicari ke permissions.permission_name -> id_permission; BUKAN salinan role developer). */
    function q26_preset_perms($key)
    {
        $p = [
            Q26_PRESET_NONE => ['Allow Access Website'],
            Q26_PRESET_LIST => ['Allow Access Website', 'List Archive'],
        ];
        if (!isset($p[$key])) {
            throw new \InvalidArgumentException("preset role '$key' tidak dikenal");
        }

        return $p[$key];
    }

    /** Hapus SEMUA role preset QA26-ROLE-* (user_roles yang menunjuknya, role_permissions, role). Idempoten; role lain tidak tersentuh. */
    function q26_preset_purge($t)
    {
        q26_w($t, function ($c) {
            $ids = $c->table('roles')->where('role_name', 'like', Q26_ROLE_PREFIX . '%')->pluck('id_role')->all();
            if ($ids) {
                $c->table('user_roles')->whereIn('id_role', $ids)->delete();
                $c->table('role_permissions')->whereIn('id_role', $ids)->delete();
                $c->table('roles')->whereIn('id_role', $ids)->delete();
            }
        });
        $GLOBALS['q26_presets'] = [];
    }

    /** id_role preset $key (dibuat bila belum ada di run ini): bukan superadmin, aktif, permission dari NAMA, created_by QA26. */
    function q26_preset($t, $key)
    {
        if (isset($GLOBALS['q26_presets'][$key])) {
            return $GLOBALS['q26_presets'][$key];
        }
        $names = q26_preset_perms($key);
        $id = q26_w($t, function ($c) use ($key, $names) {
            $now = date('Y-m-d H:i:s');
            $old = $c->table('roles')->where('role_name', Q26_ROLE_PREFIX . $key)->pluck('id_role')->all();
            if ($old) { // sisa tak dikenal: buang dulu, bangun ulang dari nama
                $c->table('user_roles')->whereIn('id_role', $old)->delete();
                $c->table('role_permissions')->whereIn('id_role', $old)->delete();
                $c->table('roles')->whereIn('id_role', $old)->delete();
            }
            $id = $c->table('roles')->insertGetId([
                'role_name' => Q26_ROLE_PREFIX . $key, 'description' => 'QA ED-1026 preset (ED-1149)', 'is_superadmin' => 4,
                'is_active' => 1, 'is_used' => 1, 'created_by' => 'QA26', 'created_at' => $now, 'updated_by' => 'QA26', 'updated_at' => $now,
            ]);
            foreach ($names as $name) {
                $perm = $c->table('permissions')->where('permission_name', $name)->first();
                if (!$perm) {
                    throw new \RuntimeException("prasyarat: permission '$name' tidak ada di DB uji");
                }
                $c->table('role_permissions')->insert([
                    'id_role' => $id, 'id_permission' => $perm->id_permission, 'permission_name' => $perm->permission_name,
                    'created_at' => $now, 'updated_at' => $now, 'created_by' => 'QA26', 'updated_by' => 'QA26',
                ]);
            }

            return (int) $id;
        });
        $GLOBALS['q26_presets'][$key] = $id;

        return $id;
    }

    /** Nama permission yang dipegang role $role, dibaca per id_permission (join permissions; BR-3), bukan dari kolom role_permissions.permission_name. */
    function q26_role_names($t, $role)
    {
        return $t->db()->table('role_permissions as rp')->join('permissions as p', 'p.id_permission', '=', 'rp.id_permission')
            ->where('rp.id_role', $role)->pluck('p.permission_name')->all();
    }

    /** Kunci preset -> id_role preset; angka hanya boleh role sistem 1/2/3 (role developer lain ditolak: BR-1/BR-4). */
    function q26_role_ref($t, $role)
    {
        if (is_int($role) || (is_string($role) && ctype_digit($role))) {
            if (!in_array((int) $role, [1, 2, 3], true)) {
                throw new \InvalidArgumentException("role developer $role tidak boleh dipakai skenario (pakai preset QA26-ROLE-*)");
            }

            return (int) $role;
        }

        return q26_preset($t, $role);
    }

    // ------------------------------------------------------------------ QA_USER2 sungguhan (user_roles sementara, berjurnal)

    function q26_u2_journal()
    {
        return __DIR__ . '/.restore-journal-q26-user2.json';
    }

    function q26_u2_id($t)
    {
        return $t->db()->table('users')->where('username', $t->conf('QA_USER2'))->value('id_user');
    }

    function q26_u2_rows($t)
    {
        return q26_rows($t->db()->table('user_roles')->where('id_user', q26_u2_id($t))->orderBy('id_user_role')->get());
    }

    function q26_u2_write($t, $uid, array $rows)
    {
        q26_w($t, function ($c) use ($uid, $rows) {
            $c->table('user_roles')->where('id_user', $uid)->delete();
            if ($rows) {
                $c->table('user_roles')->insert($rows);
            }
        });
    }

    /** Pulihkan user_roles QA_USER2 dari jurnal bila run sebelumnya mati di tengah. */
    function q26_u2_recover($t)
    {
        if (is_file(q26_u2_journal())) {
            $snap = json_decode(file_get_contents(q26_u2_journal()), true);
            if (is_array($snap) && !empty($snap['rows'])) {
                q26_u2_write($t, $snap['uid'], $snap['rows']);
            }
            @unlink(q26_u2_journal());
        }
    }

    /** Jalankan $fn($idRolePreset) dengan QA_USER2 HANYA di preset $key; user_roles aslinya dipulihkan persis (jurnal + bukti). */
    function q26_with_u2_preset($t, $key, callable $fn)
    {
        q26_u2_recover($t);
        $uid = q26_u2_id($t);
        $rows = q26_u2_rows($t);
        $t->true($uid !== null && count($rows) >= 1, 'prasyarat: QA_USER2 ada dan punya role (dipulihkan persis sesudahnya)');
        file_put_contents(q26_u2_journal(), json_encode(['uid' => $uid, 'rows' => $rows]));
        try {
            $role = q26_preset($t, $key);
            $now = date('Y-m-d H:i:s');
            q26_u2_write($t, $uid, [['id_user' => $uid, 'id_role' => $role, 'is_all_location' => 1, 'id_location' => null, 'created_at' => $now, 'updated_at' => $now]]);

            return $fn($role);
        } finally {
            q26_u2_write($t, $uid, $rows);
            $after = q26_u2_rows($t);
            @unlink(q26_u2_journal());
            if (json_encode($after) !== json_encode($rows)) {
                $t->fail('pemulihan user_roles QA_USER2 tidak persis sama dengan sebelumnya');
            }
        }
    }

    // ------------------------------------------------------------------ data uji archive

    function q26_new_id($t)
    {
        return q26_w($t, function ($c) {
            return \Modules\V5\Entities\Helper\MyHelper::generateId();
        });
    }

    /** Nama unik berawalan QA26-. */
    function q26_name($label)
    {
        return Q26_PREFIX . $label . '-' . substr(uniqid(), -6) . mt_rand(10, 99);
    }

    /**
     * Sisipkan satu baris archive uji.
     * $a: name, type (1 folder / 2 dokumen), parent, all (is_all_location 0/1, default 1), locs (kode lokasi),
     *     perm (is_folder_permission), by (created_by, default 'QA26'), active (default 1), status (default 1),
     *     verified (0/1, hanya dokumen), doc => ['type' => int, 'salesman' => string, 'no' => string].
     * @return string id_archive
     */
    function q26_add($t, array $a)
    {
        $id = q26_new_id($t);
        $locs = q26_locs($t);
        $now = date('Y-m-d H:i:s');

        q26_w($t, function ($c) use ($a, $id, $now, $locs) {
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
                'created_by'           => $a['by'] ?? 'QA26',
                'updated_at'           => $now,
                'updated_by'           => $a['by'] ?? 'QA26',
            ];
            if (!empty($a['verified'])) {
                $row['is_verified'] = 1;
                $row['verified_at'] = $now;
                $row['verified_by'] = 'QA26';
            }
            $c->table('archives')->insert($row);

            foreach ($a['locs'] ?? [] as $code) {
                $c->table('archive_locations')->insert(['id_archive' => $id, 'id_location' => $locs[$code]]);
            }

            if (($a['type'] ?? 1) == 2) {
                $doc = $a['doc'] ?? [];
                $c->table('archive_documents')->insert([
                    'id_archive_document' => \Modules\V5\Entities\Helper\MyHelper::generateId(),
                    'id_archive'          => $id,
                    'id_transaction'      => 'QA26TX' . substr($id, -10),
                    'transaction_no'      => $doc['no'] ?? $a['name'],
                    'transaction_type'    => $doc['type'] ?? 6,
                    'related_employee_name' => $doc['salesman'] ?? null,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
            }
        });

        return $id;
    }

    function q26_folder($t, $label, array $a = [])
    {
        return q26_add($t, $a + ['name' => q26_name($label), 'type' => 1]);
    }

    function q26_doc($t, $label, array $a = [])
    {
        return q26_add($t, $a + ['name' => q26_name($label), 'type' => 2]);
    }

    function q26_set_archive($t, $id, array $cols)
    {
        q26_w($t, function ($c) use ($id, $cols) {
            $c->table('archives')->where('id_archive', $id)->update($cols);
        });
    }

    function q26_row($t, $id)
    {
        $row = $t->db()->table('archives')->where('id_archive', $id)->first();

        return $row ? (array) $row : null;
    }

    /** [is_verified, verified_at !== null, verified_by, id_archive_opname] satu dokumen. */
    function q26_v($t, $id)
    {
        $r = q26_row($t, $id);

        return [(int) $r['is_verified'], $r['verified_at'] !== null, $r['verified_by'], $r['id_archive_opname']];
    }

    /** Sisipkan baris archive_permissions (hak 0/1) satu user di satu folder. */
    function q26_perm($t, $idArchive, $idUser, $view, $update = 0, $delete = 0, $store = 0)
    {
        $now = date('Y-m-d H:i:s');
        q26_w($t, function ($c) use ($idArchive, $idUser, $view, $update, $delete, $store, $now) {
            $c->table('archive_permissions')->insert([
                'id_archive_permission' => \Modules\V5\Entities\Helper\MyHelper::generateId(),
                'id_archive'            => $idArchive,
                'id_user'               => $idUser,
                'is_view'               => $view,
                'is_update'             => $update,
                'is_delete'             => $delete,
                'is_store'              => $store,
                'created_at'            => $now,
                'created_by'            => 'QA26',
                'updated_at'            => $now,
                'updated_by'            => 'QA26',
            ]);
        });
    }

    /** Hapus semua baris archive / dokumen / lokasi / permission berawalan QA26- (idempoten). */
    function q26_purge($t)
    {
        q26_w($t, function ($c) {
            $ids = $c->table('archives')->where('name', 'like', Q26_PREFIX . '%')->pluck('id_archive')->all();
            foreach (array_chunk($ids, 500) as $chunk) {
                $c->table('archive_permissions')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_documents')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_locations')->whereIn('id_archive', $chunk)->delete();
                $c->table('archives')->whereIn('id_archive', $chunk)->delete();
            }
        });
    }

    /**
     * Fixture standar (semua berawalan QA26-, dibuang oleh q26_purge):
     *   F (root, all)            folder opname
     *    |- S1 (all)             subfolder langsung; S1a (all) sub-subfolder di bawah S1
     *    |- S2 (all)             subfolder langsung
     *    |- SJ (JOG saja)        subfolder lokasi lain (tak terlihat user Semarang)
     *    |- SX (all, nonaktif)   subfolder nonaktif
     *   dokumen: dF1,dF2 (langsung di F), dJF (langsung di F, lokasi JOG saja), d11 (S1), dA1 (S1a), d21 (S2),
     *            dj1 (SJ), dx1 (SX), dNEG (di F, is_active=-1), dDEL (di F, is_active=0)
     *   OTH (root, all) + dO1 (di OTH); dR (dokumen tanpa folder)
     * Mengembalikan peta kunci => id_archive; nama dokumen lewat q26_n().
     */
    function q26_tree($t)
    {
        $x = [];
        $x['F'] = q26_folder($t, 'F');
        $x['S1'] = q26_folder($t, 'S1', ['parent' => $x['F']]);
        $x['S1a'] = q26_folder($t, 'S1a', ['parent' => $x['S1']]);
        $x['S2'] = q26_folder($t, 'S2', ['parent' => $x['F']]);
        $x['SJ'] = q26_folder($t, 'SJ', ['parent' => $x['F'], 'all' => 0, 'locs' => ['JOG']]);
        $x['SX'] = q26_folder($t, 'SX', ['parent' => $x['F'], 'active' => 0]);
        $x['OTH'] = q26_folder($t, 'OTH');
        $x['dF1'] = q26_doc($t, 'dF1', ['parent' => $x['F'], 'doc' => ['type' => 6, 'salesman' => 'Budi']]);
        $x['dF2'] = q26_doc($t, 'dF2', ['parent' => $x['F']]);
        $x['dJF'] = q26_doc($t, 'dJF', ['parent' => $x['F'], 'all' => 0, 'locs' => ['JOG']]);
        $x['d11'] = q26_doc($t, 'd11', ['parent' => $x['S1']]);
        $x['dA1'] = q26_doc($t, 'dA1', ['parent' => $x['S1a']]);
        $x['d21'] = q26_doc($t, 'd21', ['parent' => $x['S2']]);
        $x['dj1'] = q26_doc($t, 'dj1', ['parent' => $x['SJ'], 'all' => 0, 'locs' => ['JOG']]);
        $x['dx1'] = q26_doc($t, 'dx1', ['parent' => $x['SX']]);
        $x['dNEG'] = q26_doc($t, 'dNEG', ['parent' => $x['F'], 'active' => -1]);
        $x['dDEL'] = q26_doc($t, 'dDEL', ['parent' => $x['F'], 'active' => 0]);
        $x['dO1'] = q26_doc($t, 'dO1', ['parent' => $x['OTH']]);
        $x['dR'] = q26_doc($t, 'dR');

        return $x;
    }

    /** Nama (= kode scan) satu archive uji. */
    function q26_n($t, $id)
    {
        return $t->db()->table('archives')->where('id_archive', $id)->value('name');
    }

    /** Pilihan folder body: [['id_archive' => id, 'is_continue' => bool], ...] dari daftar id / [id => continue]. */
    function q26_sel(array $ids)
    {
        $out = [];
        foreach ($ids as $k => $v) {
            if (is_int($k)) {
                $out[] = ['id_archive' => $v, 'is_continue' => false];
            } else {
                $out[] = ['id_archive' => $k, 'is_continue' => (bool) $v];
            }
        }

        return $out;
    }


    // ------------------------------------------------------------------ jurnal sesi, pra-citra verifikasi, keadaan developer

    function q26_track($id)
    {
        if (!$id) {
            return;
        }
        $ids = is_file(q26_sjournal()) ? (json_decode(file_get_contents(q26_sjournal()), true) ?: []) : [];
        $ids[] = $id;
        file_put_contents(q26_sjournal(), json_encode(array_values(array_unique($ids))));
    }

    function q26_tracked()
    {
        return is_file(q26_sjournal()) ? (json_decode(file_get_contents(q26_sjournal()), true) ?: []) : [];
    }

    function q26_vjournal()
    {
        return __DIR__ . '/.verif-journal.json';
    }

    /** Baris archives non-QA26 dengan kolom verifikasi tidak default (builder baca saja). */
    function q26_nondefault($t)
    {
        return $t->db()->table('archives')->where('name', 'not like', Q26_PREFIX . '%')->where(function ($q) {
            $q->whereNull('is_verified')->orWhere('is_verified', '!=', 0)->orWhereNotNull('verified_at')
                ->orWhereNotNull('verified_by')->orWhereNotNull('id_archive_opname');
        });
    }

    /**
     * Tulis pra-citra: nilai kolom verifikasi SEMUA baris archives non-QA26 yang tidak default (data developer), persis
     * sebelum AC jalan. Baris lain default (0, NULL, NULL, NULL) menurut definisi. Mengembalikan jumlah baris.
     */
    function q26_write_vjournal($t)
    {
        $rows = [];
        foreach (q26_nondefault($t)->get(['id_archive', 'is_verified', 'verified_at', 'verified_by', 'id_archive_opname']) as $r) {
            $rows[(string) $r->id_archive] = [$r->is_verified === null ? null : (int) $r->is_verified, $r->verified_at, $r->verified_by, $r->id_archive_opname];
        }
        file_put_contents(q26_vjournal(), json_encode(['taken_at' => date('c'), 'rows' => $rows]));

        return count($rows);
    }

    function q26_read_vjournal()
    {
        return is_file(q26_vjournal()) ? (json_decode(file_get_contents(q26_vjournal()), true) ?: []) : [];
    }

    /**
     * Hapus data opname sesi QA tercatat dan kembalikan kolom verifikasi `archives` yang ditandai sesi itu ke NILAI
     * SEBELUMNYA (pra-citra jurnal; bukan default). Baris tanpa pra-citra = default. Hanya baris yang ditandai
     * `id_archive_opname` sesi QA yang disentuh; sesi dan baris developer tidak. Mengembalikan jumlah baris archives dipulihkan.
     */
    function q26_sweep($t)
    {
        $ids = q26_tracked();
        $pre = q26_read_vjournal()['rows'] ?? [];
        $restored = 0;
        if ($ids) {
            $restored = q26_w($t, function ($c) use ($ids, $pre) {
                $n = 0;
                foreach (array_chunk($ids, 100) as $chunk) {
                    $groups = [];
                    foreach ($c->table('archives')->whereIn('id_archive_opname', $chunk)->pluck('id_archive')->all() as $id) {
                        $groups[json_encode($pre[(string) $id] ?? [0, null, null, null])][] = $id;
                    }
                    foreach ($groups as $json => $list) {
                        $v = json_decode($json, true);
                        foreach (array_chunk($list, 500) as $part) {
                            $c->table('archives')->whereIn('id_archive', $part)->update([
                                'is_verified' => $v[0], 'verified_at' => $v[1], 'verified_by' => $v[2], 'id_archive_opname' => $v[3],
                            ]);
                        }
                        $n += count($list);
                    }
                    $c->table('archive_opname_documents')->whereIn('id_archive_opname', $chunk)->delete();
                    $c->table('archive_opname_folders')->whereIn('id_archive_opname', $chunk)->delete();
                    $c->table('archive_opnames')->whereIn('id_archive_opname', $chunk)->delete();
                }

                return $n;
            });
        }
        @unlink(q26_sjournal());

        return $restored;
    }

    function q26_cols($t, $table)
    {
        $out = [];
        foreach ($t->db()->select("SHOW COLUMNS FROM `$table`") as $r) {
            $out[] = $r->Field;
        }

        return $out;
    }

    /** [jumlah baris, jumlah CRC32 per baris] satu tabel (opsional WHERE). */
    function q26_sum($t, $table, array $cols, $where = '', array $bind = [])
    {
        $parts = array_map(function ($c) {
            return "IFNULL(CAST(`$c` AS CHAR),'~')";
        }, $cols);
        $r = $t->db()->selectOne("SELECT COUNT(*) n, IFNULL(SUM(CRC32(CONCAT_WS('|'," . implode(',', $parts) . '))),0) h FROM `' . $table . '` ' . $where, $bind);

        return [(int) $r->n, (string) $r->h];
    }

    /**
     * Keadaan tiga tabel opname (semua baris) + kolom verifikasi archives. $devOnly: archives tanpa baris QA26- (data
     * developer). Dipakai untuk "request ini tidak menulis" (sebelum/sesudah) dan "data developer tidak berubah".
     */
    function q26_state($t, $devOnly = false)
    {
        $out = [];
        foreach (['archive_opnames' => 'opnames', 'archive_opname_folders' => 'folders', 'archive_opname_documents' => 'documents'] as $table => $key) {
            $out[$key] = q26_sum($t, $table, q26_cols($t, $table));
        }
        $out['archives'] = $devOnly
            ? q26_sum($t, 'archives', ['id_archive', 'is_verified', 'verified_at', 'verified_by', 'id_archive_opname'], "WHERE name NOT LIKE ?", [Q26_PREFIX . '%'])
            : q26_sum($t, 'archives', ['id_archive', 'is_verified', 'verified_at', 'verified_by', 'id_archive_opname']);

        return $out;
    }

    function q26_devstate($t)
    {
        return q26_state($t, true);
    }

    function q26_same_state($a, $b)
    {
        return json_encode($a) === json_encode($b);
    }

    /**
     * Awal AC. Pulihkan sisa run yang mati (dari pra-citra LAMA), buang data QA26-, catat pra-citra baru dan keadaan
     * developer. TIDAK menuntut tabel opname kosong atau archives default.
     */
    function q26_baseline($t)
    {
        q26_recover_user($t);
        q26_u2_recover($t);
        q26_preset_purge($t);
        q26_sweep($t);
        @unlink(q26_vjournal());
        q26_purge($t);
        $n = q26_write_vjournal($t);
        $GLOBALS['q26_base'] = q26_devstate($t);
        $GLOBALS['q26_last'] = 0;
        $b = $GLOBALS['q26_base'];
        $t->note(sprintf('data developer di DB uji dijaga: %d sesi, %d baris folder, %d baris dokumen opname; %d baris archives verifikasi tidak default', $b['opnames'][0], $b['folders'][0], $b['documents'][0], $n));

        return $b;
    }

    /** Akhir AC (finally): pulihkan nilai sebelumnya, buang data QA, buktikan data developer sama persis. */
    function q26_cleanup($t, $label = 'data uji dibuang')
    {
        $tracked = q26_tracked();
        q26_sweep($t);
        q26_purge($t);
        q26_preset_purge($t);
        $c = $t->db();
        $gone = $tracked ? $c->table('archive_opnames')->whereIn('id_archive_opname', $tracked)->count() : 0;
        $now = q26_devstate($t);
        $base = $GLOBALS['q26_base'] ?? null;
        $t->eq($gone, 0, $label . ' (sesi opname QA)');
        $t->eq($c->table('archives')->where('name', 'like', Q26_PREFIX . '%')->count(), 0, $label . ' (archives QA26-)');
        $t->eq($c->table('archive_documents')->where('transaction_no', 'like', Q26_PREFIX . '%')->count(), 0, $label . ' (archive_documents)');
        $t->eq($c->table('archive_permissions')->where('created_by', 'QA26')->count(), 0, $label . ' (archive_permissions QA26)');
        $t->eq($c->table('roles')->where('role_name', 'like', Q26_ROLE_PREFIX . '%')->count(), 0, $label . ' (role preset QA26-ROLE-)');
        $t->eq($c->table('role_permissions')->where('created_by', 'QA26')->count(), 0, $label . ' (role_permissions QA26)');
        if ($base === null) {
            $t->fail($label . ': keadaan developer sebelum AC tidak tercatat (baseline tidak jalan)');
        }
        $diff = [];
        foreach ($base as $k => $v) {
            if (json_encode($v) !== json_encode($now[$k])) {
                $diff[] = $k . ' ' . json_encode($v) . ' -> ' . json_encode($now[$k]);
            }
        }
        $t->true($diff === [], $label . ': data developer sama persis dengan sebelum AC (' . implode('; ', $diff) . ')');
        @unlink(q26_vjournal());
    }

    // ------------------------------------------------------------------ request

    function q26_url($path, array $query = [])
    {
        return $path . ($query ? '?' . http_build_query($query) : '');
    }

    function q26_code($r)
    {
        return $r[1]['code'] ?? $r[1]['msg_code'] ?? null;
    }

    function q26_no500($t, $r, $label)
    {
        $t->true($r[0] < 500, $label . ': HTTP ' . $r[0] . ' (tidak boleh 5xx)');
    }

    /** Penolakan: HTTP status + code (code/msg_code). */
    function q26_deny($t, $r, $http, $code, $label)
    {
        $t->status($r, $http, $label);
        $t->eq(q26_code($r), $code, $label . ': code');
    }

    function q26_folders($t, $s, $id = null)
    {
        return $t->call($s, 'GET', q26_url(Q26_BASE . '/opnames/folders', $id === null ? [] : ['id_archive' => $id]));
    }

    /** Body pilihan Step 1: folder opname (null = root), subfolder terpilih (q26_sel), is_continue folder opname. */
    function q26_selection($folder, array $folders = [], $continue = null)
    {
        $b = ['id_archive' => $folder, 'folders' => $folders];
        if ($continue !== null) {
            $b['is_continue'] = $continue;
        }

        return $b;
    }

    /** Tunggu sampai detik server berikutnya sesudah Konfirmasi terakhir: selected_at baru tidak bentrok dengannya. */
    function q26_wait()
    {
        while (time() <= (int) ($GLOBALS['q26_last'] ?? 0)) {
            usleep(100000);
        }
    }

    /** Muat Step 1 (GET opnames/folders) dan kembalikan selected_at (waktu server). */
    function q26_selected($t, $s, $folder)
    {
        q26_wait();
        $r = q26_folders($t, $s, $folder);
        $t->status($r, 200, 'muat Step 1 (selected_at)');
        $at = $r[1]['result']['selected_at'] ?? null;
        $t->true(is_string($at) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $at) === 1, 'selected_at = Y-m-d H:i:s');

        return $at;
    }

    /** POST opnames/scan (klasifikasi satu kode, tanpa simpan). */
    function q26_scan($t, $s, array $sel, $code)
    {
        return $t->call($s, 'POST', Q26_BASE . '/opnames/scan', $sel + ['code' => $code]);
    }

    function q26_preview($t, $s, array $sel, array $scans = [])
    {
        return $t->call($s, 'POST', Q26_BASE . '/opnames/preview', $sel + ['scans' => $scans]);
    }

    /** POST opnames/preview/documents; $q = result/page/pagination (di body). */
    function q26_pdocs($t, $s, array $sel, array $scans = [], array $q = [])
    {
        return $t->call($s, 'POST', Q26_BASE . '/opnames/preview/documents', $sel + ['scans' => $scans] + $q);
    }

    /** Semua halaman preview/documents satu filter: [row...]. */
    function q26_pall($t, $s, array $sel, array $scans, $result, $pagination = 200, $maxPages = 400)
    {
        $rows = [];
        $page = 1;
        do {
            $r = q26_pdocs($t, $s, $sel, $scans, ['result' => $result, 'pagination' => $pagination, 'page' => $page]);
            $t->status($r, 200, "preview/documents $result hal $page");
            foreach ($r[1]['result']['data'] ?? [] as $row) {
                $rows[] = $row;
            }
            $last = $r[1]['result']['last_page'] ?? 1;
            $page++;
        } while ($page <= $last && $page <= $maxPages);

        return $rows;
    }

    /** Scan tiap kode lewat POST opnames/scan seperti FE; kembalikan [scans (untuk body), rows (respons)]. */
    function q26_scan_all($t, $s, array $sel, array $codes)
    {
        $scans = [];
        $rows = [];
        foreach ($codes as $code) {
            $r = q26_scan($t, $s, $sel, $code);
            $t->status($r, 200, "scan $code");
            $row = $r[1]['result']['row'];
            $scans[] = ['code' => $code, 'scanned_at' => $row['scanned_at']];
            $rows[] = $row;
        }

        return [$scans, $rows];
    }

    /** POST opnames = Konfirmasi Opname; sesi sukses dicatat di jurnal. */
    function q26_store($t, $s, array $sel, array $scans, $selectedAt)
    {
        $r = $t->call($s, 'POST', Q26_BASE . '/opnames', $sel + ['scans' => $scans, 'selected_at' => $selectedAt]);
        $GLOBALS['q26_last'] = time();
        q26_track($r[1]['result']['id_archive_opname'] ?? null);

        return $r;
    }

    /**
     * Alur lengkap seperti FE: muat Step 1 (selected_at), scan tiap kode, Konfirmasi. $selectedAt diberikan = dipakai apa
     * adanya (mis. kemarin). Mengembalikan ['id', 'r' (respons Konfirmasi), 'sel', 'scans', 'rows', 'selected_at'].
     */
    function q26_do($t, $s, $folder, array $folders = [], $continue = null, array $codes = [], $selectedAt = null)
    {
        $sel = q26_selection($folder, $folders, $continue);
        if ($selectedAt === null) {
            $selectedAt = q26_selected($t, $s, $folder);
        }
        list($scans, $rows) = q26_scan_all($t, $s, $sel, $codes);
        $r = q26_store($t, $s, $sel, $scans, $selectedAt);

        return ['id' => $r[1]['result']['id_archive_opname'] ?? null, 'r' => $r, 'sel' => $sel, 'scans' => $scans, 'rows' => $rows, 'selected_at' => $selectedAt];
    }

    /** q26_do() yang menuntut 200 dan mengembalikan id sesi. */
    function q26_ok($t, $s, $folder, array $folders = [], $continue = null, array $codes = [], $label = 'Konfirmasi')
    {
        $o = q26_do($t, $s, $folder, $folders, $continue, $codes);
        $t->status($o['r'], 200, $label);
        $t->eq($o['r'][1]['msg_code'] ?? null, 'ARCHIVE213', $label . ': ARCHIVE213');
        $t->true($o['id'] !== null, $label . ': id sesi dikembalikan');

        return $o['id'];
    }

    function q26_show($t, $s, $id)
    {
        return $t->call($s, 'GET', Q26_BASE . '/opnames/' . $id);
    }

    function q26_docs($t, $s, $id, array $query = [])
    {
        return $t->call($s, 'GET', q26_url(Q26_BASE . '/opnames/' . $id . '/documents', $query));
    }

    /** Semua halaman /documents satu filter: [row...]. */
    function q26_all_docs($t, $s, $id, $result, $pagination = 200, $maxPages = 400)
    {
        $rows = [];
        $page = 1;
        do {
            $r = q26_docs($t, $s, $id, ['result' => $result, 'pagination' => $pagination, 'page' => $page]);
            $t->status($r, 200, "documents $result hal $page");
            foreach ($r[1]['result']['data'] ?? [] as $row) {
                $rows[] = $row;
            }
            $last = $r[1]['result']['last_page'] ?? 1;
            $page++;
        } while ($page <= $last && $page <= $maxPages);

        return $rows;
    }

    /** Ubah pemilik sesi di DB (mensimulasikan sesi milik user lain). */
    function q26_set_owner($t, $id, $username)
    {
        q26_w($t, function ($c) use ($id, $username) {
            $c->table('archive_opnames')->where('id_archive_opname', $id)->update(['created_by' => $username]);
        });
    }

    /** Ubah kolom sesi di DB (waktu) untuk skenario hari lain. */
    function q26_set_opname($t, $id, array $cols)
    {
        q26_w($t, function ($c) use ($id, $cols) {
            $c->table('archive_opnames')->where('id_archive_opname', $id)->update($cols);
        });
    }

    /** Sisipkan baris sesi langsung di DB (data lama, mis. status 1/3 versi draft) dan catat di jurnal. */
    function q26_insert_session($t, $status, $folder = null, array $extra = [])
    {
        $id = q26_new_id($t);
        $now = date('Y-m-d H:i:s');
        q26_track($id);
        q26_w($t, function ($c) use ($id, $status, $folder, $now, $extra) {
            $c->table('archive_opnames')->insert($extra + [
                'id_archive_opname' => $id, 'id_archive' => $folder, 'status' => $status, 'selected_at' => $now,
                'created_at' => $now, 'created_by' => 'QA26', 'updated_at' => $now, 'updated_by' => 'QA26',
            ] + ['id_archive_opname' => $id]);
        });

        return $id;
    }

    /** Sisipkan baris folder snapshot untuk sesi (data lama / simulasi), dibuang bersama sesinya oleh q26_sweep. */
    function q26_insert_session_folder($t, $sessionId, $folderId, $name, $level = 0)
    {
        $now = date('Y-m-d H:i:s');
        q26_w($t, function ($c) use ($sessionId, $folderId, $name, $level, $now) {
            $c->table('archive_opname_folders')->insert([
                'id_archive_opname_folder' => \Modules\V5\Entities\Helper\MyHelper::generateId(), 'id_archive_opname' => $sessionId,
                'id_archive' => $folderId, 'name' => $name, 'level' => $level, 'created_at' => $now,
            ]);
        });
    }

    /** Sesi baris DB (array) atau null. */
    function q26_opname($t, $id)
    {
        $r = $t->db()->table('archive_opnames')->where('id_archive_opname', $id)->first();

        return $r ? (array) $r : null;
    }

    /** Baris snapshot dokumen sesi by code (lowercase) => row. */
    function q26_opdocs($t, $id)
    {
        $out = [];
        foreach ($t->db()->table('archive_opname_documents')->where('id_archive_opname', $id)->get() as $r) {
            $out[strtolower($r->code)] = (array) $r;
        }

        return $out;
    }

    function q26_opfolders($t, $id)
    {
        $out = [];
        foreach ($t->db()->table('archive_opname_folders')->where('id_archive_opname', $id)->orderBy('level')->orderBy('name')->get() as $r) {
            $out[] = (array) $r;
        }

        return $out;
    }

    /** Cari baris result.children dengan id_archive. */
    function q26_child($r, $id)
    {
        foreach ($r[1]['result']['children'] ?? [] as $row) {
            if (($row['id_archive'] ?? null) === $id) {
                return $row;
            }
        }

        return null;
    }

    function q26_child_ids($r)
    {
        return array_map(function ($row) {
            return $row['id_archive'];
        }, $r[1]['result']['children'] ?? []);
    }

    /**
     * Folder (dan root '*') yang tercakup sesi terkonfirmasi hari ini (tanggal Asia/Jakarta), dari DB: kunci => [id sesi,
     * confirmed_at, created_by]. Aturan sama dengan "Sudah diopname hari ini": level 0-2, root hanya level 0, konfirmasi terakhir.
     */
    function q26_opnamed_today($t)
    {
        $start = q26_today() . ' 00:00:00';
        $end = q26_now('+1 day')->format('Y-m-d') . ' 00:00:00';
        $rows = $t->db()->table('archive_opname_folders as f')
            ->join('archive_opnames as o', 'o.id_archive_opname', '=', 'f.id_archive_opname')
            ->where('o.status', 2)->where('o.confirmed_at', '>=', $start)->where('o.confirmed_at', '<', $end)
            ->orderBy('o.confirmed_at')
            ->get(['f.id_archive', 'f.level', 'o.id_archive_opname', 'o.confirmed_at', 'o.created_by']);
        $out = [];
        foreach ($rows as $r) {
            if ($r->id_archive === null && (int) $r->level !== 0) {
                continue;
            }
            $out[$r->id_archive === null ? '*' : $r->id_archive] = [$r->id_archive_opname, $r->confirmed_at, $r->created_by];
        }

        return $out;
    }
}

require_once __DIR__ . '/qa_lib_oracle.php';
