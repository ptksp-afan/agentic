<?php
/**
 * Helper bersama skenario ED-1251 (BUKAN skenario: nama berkas tidak cocok scenario*.php).
 * Di-require_once oleh setiap scenario*.php.
 *
 * Aturan pakai:
 * - Data uji archive berawalan "QA251-" (folder, dokumen, lokasi, archive_documents, archive_permissions). Dibuat lewat
 *   q251_add(), dibuang lewat q251_purge(). Sesi opname dibuat lewat API opname ED-1026 REV (stateless: GET opnames/folders ->
 *   POST opnames/scan -> SATU POST opnames dengan `scans` + `selected_at` = Konfirmasi; tidak ada draft/batal/confirm/DELETE) dan
 *   dicatat di jurnal (.sessions-journal-q251.json).
 * - (rev 2026-10-10, gate1 ba2-notes "Pengaman data uji") Tabel opname TIDAK harus kosong dan kolom verifikasi `archives` TIDAK
 *   harus default: data developer boleh ada. q251_baseline() mencatat pra-citra kolom verifikasi baris non-QA251 yang tidak default
 *   (.verif-journal-q251.json) dan keadaan developer (jumlah + CRC tiga tabel opname + archives non-QA251); q251_sweep() menghapus
 *   hanya baris opname milik sesi di jurnal dan mengembalikan kolom verifikasi baris yang ditandai sesi itu ke NILAI SEBELUMNYA
 *   (bukan default); q251_cleanup() membuktikan keadaan developer sama persis. Semua asersi dilingkup ke sesi QA sendiri
 *   (q251_hkeys membuang sesi asing); sesi QA diberi confirmed_at DI ATAS semua sesi developer (q251_times/q251_day) supaya
 *   urutan/halaman deterministik, dan SEMUA sesi yang saling beririsan dibuat SEBELUM confirmed_at digeser ke depan (bentrok
 *   ARCHIVE414 membandingkan confirmed_at >= selected_at).
 * - (ED-1149 BR-1/BR-2/BR-4) TIDAK ada prasyarat/grant pada role developer (3, 5, 6, 22, 29, ...): "R0" (List Archive tanpa Opname
 *   Document), "tanpa permission Archive" dan role user2 = role preset QA251-ROLE-R0 / QA251-ROLE-NONE, dibuat dari NAMA permission
 *   eksplisit (q251_preset) dan dibuang persis (q251_preset_purge; bukti di q251_cleanup). Permission sementara (mis. Opname Document)
 *   hanya ke preset lewat q251_grant()/q251_with_grant(): baris role_permissions dibuat dengan created_by QA251, id di jurnal
 *   (.grant-journal-q251.json), dihapus persis di finally; q251_grant MENOLAK role non-preset. Permission dicari per NAMA.
 * - QA_USER2 (adi, non-karyawan, lokasi SMR, bisa login) = user2 sungguhan (qa_hist.php): user_roles-nya diganti SEMENTARA ke preset
 *   R0 oleh q251_guard (jurnal .restore-journal-q251-user2.json memegang baris ASLI, dipulihkan persis); QA_USER
 *   (role 3, TIDAK bypass lokasi/hak folder) juga dipakai dengan keadaan sementara (role: preset atau 1/2/3, lokasi employee, bahasa)
 *   lewat q251_with_user(); jurnal pemulihan di .restore-journal-q251.json. Bypass (superadmin 1/2) = role 1 dan 2.
 * - Tulis lewat koneksi default non-read-only di dalam $t->probe(); baca lewat $t->db() (read-only).
 * - Oracle (q251_oracle) menghitung angka verifikasi dari tabel mentah, tanpa kode BE, untuk satu keadaan user.
 */

if (!function_exists('q251_dbname')) {

    define('Q251_PREFIX', '0QA251-');
    define('Q251_BASE', 'api/v5/document-archive');
    define('Q251_ROLE_PREFIX', 'QA251-ROLE-');
    define('Q251_PRESET_FULL', 'FULL'); // preset QA_USER: akses website + List Archive + Opname Document
    define('Q251_PRESET_U2', 'U2');     // preset QA_USER2 (user2): Opname Document
    define('Q251_PRESET_R0', 'R0');     // preset R0: akses website + List Archive, TANPA Opname Document (role user2 di q251_guard)
    define('Q251_PRESET_NONE', 'NONE'); // preset: akses website saja (tanpa permission Archive apa pun)
    define('Q251_RANDOM_ID', '999999999999999999999999999999');
    define('Q251_OD', 'Opname Document'); // permission riwayat & data opname (id 1139, dicari per NAMA)
    define('Q251_LA', 'List Archive');

    function q251_dbname($t)
    {
        return $t->dbs()[0];
    }

    /** Jalankan $fn($conn) dengan koneksi tulis ke DB QA (koneksi default BE_DIR) di dalam probe. */
    function q251_w($t, callable $fn)
    {
        $db = q251_dbname($t);

        return $t->probe(function () use ($fn, $db) {
            $conn = \Illuminate\Support\Facades\DB::connection();
            if ($conn->getDatabaseName() !== $db) {
                throw new \RuntimeException('koneksi default BE_DIR bukan ' . $db . ' (' . $conn->getDatabaseName() . ')');
            }

            return $fn($conn);
        });
    }

    function q251_rows($collection)
    {
        $out = [];
        foreach ($collection as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }

    function q251_journal()
    {
        return __DIR__ . '/.restore-journal-q251.json';
    }

    function q251_sjournal()
    {
        return __DIR__ . '/.sessions-journal-q251.json';
    }

    function q251_uid($t)
    {
        return $t->db()->table('users')->where('username', $t->conf('QA_USER'))->value('id_user');
    }

    function q251_uname($t)
    {
        return $t->db()->table('users')->where('username', $t->conf('QA_USER'))->value('username');
    }

    /** kode lokasi => id lokasi */
    function q251_locs($t)
    {
        return $t->db()->table('locations')->pluck('id_location', 'location_code')->all();
    }

    // ------------------------------------------------------------------ user QA (role / lokasi / bahasa)

    function q251_user_snapshot($t)
    {
        $c = $t->db();
        $uid = q251_uid($t);
        $emp = $c->table('employees')->where('id_user', $uid)->first();

        return [
            'uid'      => $uid,
            'roles'    => q251_rows($c->table('user_roles')->where('id_user', $uid)->orderBy('id_user_role')->get()),
            'lang'     => $c->table('users')->where('id_user', $uid)->value('language'),
            'emp_id'   => $emp->id_employee ?? null,
            'emp_all'  => $emp->is_all_location ?? null,
            'emp_locs' => $emp ? q251_rows($c->table('employee_locations')->where('id_employee', $emp->id_employee)->orderBy('id_employee_location')->get()) : [],
        ];
    }

    function q251_user_restore($t, array $snap)
    {
        q251_w($t, function ($c) use ($snap) {
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

    /** opts: 'role' => int|int[] (id_role), 'emp' => ['SMR', ...] (kode lokasi), 'lang' => 'ID'|'EN' */
    function q251_user_apply($t, array $snap, array $opts)
    {
        $locs = q251_locs($t);
        $roleIds = isset($opts['role']) ? array_map(function ($r) use ($t) {
            return q251_role_ref($t, $r);
        }, (array) $opts['role']) : [];
        q251_w($t, function ($c) use ($snap, $opts, $locs, $roleIds) {
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

    function q251_recover_user($t)
    {
        if (is_file(q251_journal())) {
            $snap = json_decode(file_get_contents(q251_journal()), true);
            if (is_array($snap)) {
                q251_user_restore($t, $snap);
            }
            @unlink(q251_journal());
        }
    }

    /**
     * Jalankan $fn($set) dengan user QA di keadaan $opts. $set(array $opts) mengganti keadaan (relatif terhadap keadaan
     * asli). Keadaan asli dipulihkan dan diverifikasi persis di finally.
     */
    function q251_with_user($t, array $opts, callable $fn)
    {
        q251_recover_user($t);
        $snap = q251_user_snapshot($t);
        file_put_contents(q251_journal(), json_encode($snap));

        $set = function (array $o) use ($t, $snap) {
            q251_user_restore($t, $snap);
            q251_user_apply($t, $snap, $o);
        };

        try {
            $set($opts);

            return $fn($set);
        } finally {
            q251_user_restore($t, $snap);
            $after = q251_user_snapshot($t);
            if (json_encode($after) !== json_encode($snap)) {
                $t->fail('pemulihan user QA tidak persis sama dengan sebelumnya (jurnal dipertahankan)');
            }
            @unlink(q251_journal());
        }
    }

    // ------------------------------------------------------------------ role preset QA251-ROLE-* (ED-1149 BR-1/BR-2/BR-4)

    /** Nama permission eksplisit per preset (dicari ke permissions.permission_name -> id_permission; BUKAN salinan role developer). */
    function q251_preset_perms($key)
    {
        $p = [
            Q251_PRESET_FULL => ['Allow Access Website', 'List Archive', 'Opname Document'], // QA_USER: semua lokasi (employee), OD
            Q251_PRESET_U2   => ['Allow Access Website', 'List Archive', 'Opname Document'], // QA_USER2 (user2): OD, lokasi SMR/View terbatas oleh data
            Q251_PRESET_R0   => ['Allow Access Website', 'List Archive'],                    // List Archive tanpa Opname Document
        ];
        if (!isset($p[$key])) {
            throw new \InvalidArgumentException("preset role '$key' tidak dikenal");
        }

        return $p[$key];
    }

    /** Hapus SEMUA role preset QA251-ROLE-* (user_roles yang menunjuknya, role_permissions, role). Idempoten; role lain tidak tersentuh. */
    function q251_preset_purge($t)
    {
        q251_w($t, function ($c) {
            $ids = $c->table('roles')->where('role_name', 'like', Q251_ROLE_PREFIX . '%')->pluck('id_role')->all();
            if ($ids) {
                $c->table('user_roles')->whereIn('id_role', $ids)->delete();
                $c->table('role_permissions')->whereIn('id_role', $ids)->delete();
                $c->table('roles')->whereIn('id_role', $ids)->delete();
            }
        });
        $GLOBALS['q251_presets'] = [];
    }

    /** id_role preset $key (dibuat bila belum ada di run ini): bukan superadmin, aktif, permission dari NAMA, created_by QA251. */
    function q251_preset($t, $key)
    {
        if (isset($GLOBALS['q251_presets'][$key])) {
            return $GLOBALS['q251_presets'][$key];
        }
        $names = q251_preset_perms($key);
        $id = q251_w($t, function ($c) use ($key, $names) {
            $now = date('Y-m-d H:i:s');
            $old = $c->table('roles')->where('role_name', Q251_ROLE_PREFIX . $key)->pluck('id_role')->all();
            if ($old) { // sisa tak dikenal: buang dulu, bangun ulang dari nama
                $c->table('user_roles')->whereIn('id_role', $old)->delete();
                $c->table('role_permissions')->whereIn('id_role', $old)->delete();
                $c->table('roles')->whereIn('id_role', $old)->delete();
            }
            $id = $c->table('roles')->insertGetId([
                'role_name' => Q251_ROLE_PREFIX . $key, 'description' => 'QA ED-1251 preset (ED-1149)', 'is_superadmin' => 4,
                'is_active' => 1, 'is_used' => 1, 'created_by' => 'QA251', 'created_at' => $now, 'updated_by' => 'QA251', 'updated_at' => $now,
            ]);
            foreach ($names as $name) {
                $perm = $c->table('permissions')->where('permission_name', $name)->first();
                if (!$perm) {
                    throw new \RuntimeException("prasyarat: permission '$name' tidak ada di DB uji");
                }
                $c->table('role_permissions')->insert([
                    'id_role' => $id, 'id_permission' => $perm->id_permission, 'permission_name' => $perm->permission_name,
                    'created_at' => $now, 'updated_at' => $now, 'created_by' => 'QA251', 'updated_by' => 'QA251',
                ]);
            }

            return (int) $id;
        });
        $GLOBALS['q251_presets'][$key] = $id;

        return $id;
    }

    /** Kunci preset -> id_role preset; angka hanya boleh role sistem 1/2/3 (role developer lain ditolak: BR-1/BR-4). */
    function q251_role_ref($t, $role)
    {
        if (is_int($role) || (is_string($role) && ctype_digit($role))) {
            if (!in_array((int) $role, [1, 2, 3], true)) {
                throw new \InvalidArgumentException("role developer $role tidak boleh dipakai skenario (pakai preset QA251-ROLE-*)");
            }

            return (int) $role;
        }

        return q251_preset($t, $role);
    }

    /** Hanya preset yang boleh menerima permission: kunci preset atau id role berawalan QA251-ROLE- (selain itu: tolak). */
    function q251_preset_ref($t, $role)
    {
        if (is_int($role) || (is_string($role) && ctype_digit($role))) {
            $name = $t->db()->table('roles')->where('id_role', (int) $role)->value('role_name');
            if ($name === null || strpos($name, Q251_ROLE_PREFIX) !== 0) {
                throw new \InvalidArgumentException("role $role bukan preset QA251-ROLE-*: grant permission pada role developer dilarang (BR-4)");
            }

            return (int) $role;
        }

        return q251_preset($t, $role);
    }

    /** Nama permission yang dipegang role $role, dibaca per id_permission (join permissions; BR-3), bukan dari kolom role_permissions.permission_name. */
    function q251_role_names($t, $role)
    {
        return $t->db()->table('role_permissions as rp')->join('permissions as p', 'p.id_permission', '=', 'rp.id_permission')
            ->where('rp.id_role', $role)->pluck('p.permission_name')->all();
    }

    // ------------------------------------------------------------------ data uji archive

    function q251_new_id($t)
    {
        return q251_w($t, function ($c) {
            return \Modules\V5\Entities\Helper\MyHelper::generateId();
        });
    }

    function q251_name($label)
    {
        return Q251_PREFIX . $label . '-' . substr(uniqid(), -6) . mt_rand(10, 99);
    }

    /**
     * Sisipkan satu baris archive uji.
     * $a: name, type (1 folder / 2 dokumen), parent, all (is_all_location 0/1, default 1), locs (kode lokasi),
     *     perm (is_folder_permission), by (created_by, default 'QA251'), active (default 1), status (default 1),
     *     verified (0/1, hanya dokumen), doc => ['type' => int, 'salesman' => string].
     * @return string id_archive
     */
    function q251_add($t, array $a)
    {
        $id = q251_new_id($t);
        $locs = q251_locs($t);
        $now = date('Y-m-d H:i:s');

        q251_w($t, function ($c) use ($a, $id, $now, $locs) {
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
                'created_by'           => $a['by'] ?? 'QA251',
                'updated_at'           => $now,
                'updated_by'           => $a['by'] ?? 'QA251',
            ];
            if (!empty($a['verified'])) {
                $row['is_verified'] = 1;
                $row['verified_at'] = $now;
                $row['verified_by'] = 'QA251';
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
                    'id_transaction'        => 'QA251TX' . substr($id, -10),
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

    function q251_folder($t, $label, array $a = [])
    {
        return q251_add($t, $a + ['name' => q251_name($label), 'type' => 1]);
    }

    /** $tx = transaction type (default 6). */
    function q251_doc($t, $label, array $a = [], $tx = 6)
    {
        return q251_add($t, $a + ['name' => q251_name($label), 'type' => 2, 'doc' => ['type' => $tx]]);
    }

    function q251_set_archive($t, $id, array $cols)
    {
        q251_w($t, function ($c) use ($id, $cols) {
            $c->table('archives')->where('id_archive', $id)->update($cols);
        });
    }

    function q251_row($t, $id)
    {
        $row = $t->db()->table('archives')->where('id_archive', $id)->first();

        return $row ? (array) $row : null;
    }

    function q251_n($t, $id)
    {
        return $t->db()->table('archives')->where('id_archive', $id)->value('name');
    }

    /** Sisipkan baris archive_permissions (hak 0/1) satu user di satu folder. */
    function q251_perm($t, $idArchive, $idUser, $view, $update = 0, $delete = 0, $store = 0)
    {
        $now = date('Y-m-d H:i:s');
        q251_w($t, function ($c) use ($idArchive, $idUser, $view, $update, $delete, $store, $now) {
            $c->table('archive_permissions')->insert([
                'id_archive_permission' => \Modules\V5\Entities\Helper\MyHelper::generateId(),
                'id_archive'            => $idArchive,
                'id_user'               => $idUser,
                'is_view'               => $view,
                'is_update'             => $update,
                'is_delete'             => $delete,
                'is_store'              => $store,
                'created_at'            => $now,
                'created_by'            => 'QA251',
                'updated_at'            => $now,
                'updated_by'            => 'QA251',
            ]);
        });
    }

    /** Hapus semua baris archive / dokumen / lokasi / permission berawalan QA251- (idempoten). */
    function q251_purge($t)
    {
        q251_w($t, function ($c) {
            $ids = $c->table('archives')->where('name', 'like', Q251_PREFIX . '%')->pluck('id_archive')->all();
            foreach (array_chunk($ids, 500) as $chunk) {
                $c->table('archive_permissions')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_documents')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_locations')->whereIn('id_archive', $chunk)->delete();
                $c->table('archives')->whereIn('id_archive', $chunk)->delete();
            }
        });
    }

    /**
     * Fixture standar (semua berawalan QA251-, dibuang oleh q251_purge). Lokasi: SMR = Semarang (lokasi kerja user uji),
     * JOG = lokasi lain. Folder tanpa tanda lokasi = is_all_location 1.
     *
     *   P (root)
     *    +- F
     *        +- S1 -- S1a          S2          SJ (JOG saja)      SX (nonaktif)
     *        +- SP (folder-permission aktif, user tanpa View) -- SPc
     *        +- SV (folder-permission aktif, user dapat baris View)
     *   OTH (root)   PRIV (root, folder-permission aktif, tanpa View) -- PRc   JOGR (root, JOG saja)
     * Dokumen (tipe transaksi, v = verified):
     *   F: dF1 6 v, dF2 7, dF3 8 v status 2 (Handed Over), dF4 6 status 3 (Taken), dF5 9; dJF 7 (JOG saja);
     *      dNEG 6 v (is_active -1), dDEL 7 v (is_active 0)
     *   S1: d11 7 v, d12 29; S1a: dA1 8; S2: d21 31, d22 222 v; SJ: dj1 6 (JOG saja); SX: dx1 6
     *   SP: dsp1 6 v; SPc: dspc1 7; SV: dsv1 7 v; PRIV: dPR 8; PRc: dPRc 6; JOGR: dJR 6 (JOG saja)
     *   OTH: dO1 7; root: dR 8 v, dR2 7 (JOG saja)
     * Mengembalikan peta kunci => id_archive.
     */
    function q251_tree($t)
    {
        $uid = q251_uid($t);
        $x = [];
        $x['P'] = q251_folder($t, 'P');
        $x['F'] = q251_folder($t, 'F', ['parent' => $x['P']]);
        $x['S1'] = q251_folder($t, 'S1', ['parent' => $x['F']]);
        $x['S1a'] = q251_folder($t, 'S1a', ['parent' => $x['S1']]);
        $x['S2'] = q251_folder($t, 'S2', ['parent' => $x['F']]);
        $x['SJ'] = q251_folder($t, 'SJ', ['parent' => $x['F'], 'all' => 0, 'locs' => ['JOG']]);
        $x['SX'] = q251_folder($t, 'SX', ['parent' => $x['F'], 'active' => 0]);
        $x['SP'] = q251_folder($t, 'SP', ['parent' => $x['F'], 'perm' => 1]);
        $x['SPc'] = q251_folder($t, 'SPc', ['parent' => $x['SP']]);
        $x['SV'] = q251_folder($t, 'SV', ['parent' => $x['F'], 'perm' => 1]);
        q251_perm($t, $x['SV'], $uid, 1);
        $x['OTH'] = q251_folder($t, 'OTH');
        $x['PRIV'] = q251_folder($t, 'PRIV', ['perm' => 1]);
        $x['PRc'] = q251_folder($t, 'PRc', ['parent' => $x['PRIV']]);
        $x['JOGR'] = q251_folder($t, 'JOGR', ['all' => 0, 'locs' => ['JOG']]);

        $x['dF1'] = q251_doc($t, 'dF1', ['parent' => $x['F'], 'verified' => 1], 6);
        $x['dF2'] = q251_doc($t, 'dF2', ['parent' => $x['F']], 7);
        $x['dF3'] = q251_doc($t, 'dF3', ['parent' => $x['F'], 'verified' => 1, 'status' => 2], 8);
        $x['dF4'] = q251_doc($t, 'dF4', ['parent' => $x['F'], 'status' => 3], 6);
        $x['dF5'] = q251_doc($t, 'dF5', ['parent' => $x['F']], 9);
        $x['dJF'] = q251_doc($t, 'dJF', ['parent' => $x['F'], 'all' => 0, 'locs' => ['JOG']], 7);
        $x['dNEG'] = q251_doc($t, 'dNEG', ['parent' => $x['F'], 'active' => -1, 'verified' => 1], 6);
        $x['dDEL'] = q251_doc($t, 'dDEL', ['parent' => $x['F'], 'active' => 0, 'verified' => 1], 7);
        $x['d11'] = q251_doc($t, 'd11', ['parent' => $x['S1'], 'verified' => 1], 7);
        $x['d12'] = q251_doc($t, 'd12', ['parent' => $x['S1']], 29);
        $x['dA1'] = q251_doc($t, 'dA1', ['parent' => $x['S1a']], 8);
        $x['d21'] = q251_doc($t, 'd21', ['parent' => $x['S2']], 31);
        $x['d22'] = q251_doc($t, 'd22', ['parent' => $x['S2'], 'verified' => 1], 222);
        $x['dj1'] = q251_doc($t, 'dj1', ['parent' => $x['SJ'], 'all' => 0, 'locs' => ['JOG']], 6);
        $x['dx1'] = q251_doc($t, 'dx1', ['parent' => $x['SX']], 6);
        $x['dsp1'] = q251_doc($t, 'dsp1', ['parent' => $x['SP'], 'verified' => 1], 6);
        $x['dspc1'] = q251_doc($t, 'dspc1', ['parent' => $x['SPc']], 7);
        $x['dsv1'] = q251_doc($t, 'dsv1', ['parent' => $x['SV'], 'verified' => 1], 7);
        $x['dPR'] = q251_doc($t, 'dPR', ['parent' => $x['PRIV']], 8);
        $x['dPRc'] = q251_doc($t, 'dPRc', ['parent' => $x['PRc']], 6);
        $x['dJR'] = q251_doc($t, 'dJR', ['parent' => $x['JOGR'], 'all' => 0, 'locs' => ['JOG']], 6);
        $x['dO1'] = q251_doc($t, 'dO1', ['parent' => $x['OTH']], 7);
        $x['dR'] = q251_doc($t, 'dR', ['verified' => 1], 8);
        $x['dR2'] = q251_doc($t, 'dR2', ['all' => 0, 'locs' => ['JOG']], 7);

        return $x;
    }

    // ------------------------------------------------------------------ jurnal sesi, pra-citra verifikasi, keadaan developer
    // (rev 2026-10-10, gate1 §5 / ba2-notes "Pengaman data uji"): tabel opname TIDAK harus kosong dan kolom verifikasi `archives`
    // TIDAK harus default. Sebelum AC jalan dicatat nilai asli kolom verifikasi SEMUA baris non-QA251 yang tidak default (pra-citra);
    // sesudahnya baris yang ditandai sesi QA dikembalikan ke nilai itu (bukan ke default) dan keadaan developer dibuktikan sama persis.

    function q251_track($id)
    {
        if (!$id) {
            return;
        }
        $ids = is_file(q251_sjournal()) ? (json_decode(file_get_contents(q251_sjournal()), true) ?: []) : [];
        $ids[] = $id;
        file_put_contents(q251_sjournal(), json_encode(array_values(array_unique($ids))));
    }

    function q251_tracked()
    {
        return is_file(q251_sjournal()) ? (json_decode(file_get_contents(q251_sjournal()), true) ?: []) : [];
    }

    function q251_vjournal()
    {
        return __DIR__ . '/.verif-journal-q251.json';
    }

    /** Baris archives non-QA251 dengan kolom verifikasi tidak default (builder baca saja). */
    function q251_nondefault($t)
    {
        return $t->db()->table('archives')->where('name', 'not like', Q251_PREFIX . '%')->where(function ($q) {
            $q->whereNull('is_verified')->orWhere('is_verified', '!=', 0)->orWhereNotNull('verified_at')
                ->orWhereNotNull('verified_by')->orWhereNotNull('id_archive_opname');
        });
    }

    /** Tulis pra-citra: nilai kolom verifikasi SEMUA baris non-QA251 yang tidak default (data developer). Mengembalikan jumlah baris. */
    function q251_write_vjournal($t)
    {
        $rows = [];
        foreach (q251_nondefault($t)->get(['id_archive', 'is_verified', 'verified_at', 'verified_by', 'id_archive_opname']) as $r) {
            $rows[(string) $r->id_archive] = [$r->is_verified === null ? null : (int) $r->is_verified, $r->verified_at, $r->verified_by, $r->id_archive_opname];
        }
        file_put_contents(q251_vjournal(), json_encode(['taken_at' => date('c'), 'rows' => $rows]));

        return count($rows);
    }

    function q251_read_vjournal()
    {
        return is_file(q251_vjournal()) ? (json_decode(file_get_contents(q251_vjournal()), true) ?: []) : [];
    }

    /**
     * Hapus data opname sesi QA tercatat (jurnal) dan kembalikan kolom verifikasi `archives` yang ditandai sesi itu ke NILAI
     * SEBELUMNYA (pra-citra; baris tanpa pra-citra = default). Hanya baris yang ditandai `id_archive_opname` sesi QA yang
     * disentuh; sesi dan baris developer tidak.
     */
    function q251_sweep($t)
    {
        $ids = q251_tracked();
        $pre = q251_read_vjournal()['rows'] ?? [];
        if ($ids) {
            q251_w($t, function ($c) use ($ids, $pre) {
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
                    }
                    $c->table('archive_opname_documents')->whereIn('id_archive_opname', $chunk)->delete();
                    $c->table('archive_opname_folders')->whereIn('id_archive_opname', $chunk)->delete();
                    $c->table('archive_opnames')->whereIn('id_archive_opname', $chunk)->delete();
                }
            });
        }
        @unlink(q251_sjournal());
    }

    /** Sisa sisipan massal berpenanda QA251BULK* (X-2, X-7) dari run yang mati: hanya baris berawalan itu. */
    function q251_bulk_purge($t)
    {
        q251_w($t, function ($c) {
            $c->table('archive_opname_documents')->where('id_archive_opname', 'like', 'QA251BULK%')->delete();
            $c->table('archive_opname_folders')->where('id_archive_opname', 'like', 'QA251BULK%')->delete();
            $c->table('archive_opnames')->where('id_archive_opname', 'like', 'QA251BULK%')->delete();
        });
    }

    function q251_cols($t, $table)
    {
        $out = [];
        foreach ($t->db()->select("SHOW COLUMNS FROM `$table`") as $r) {
            $out[] = $r->Field;
        }

        return $out;
    }

    /** [jumlah baris, jumlah CRC32 per baris] satu tabel (opsional WHERE). */
    function q251_sum($t, $table, array $cols, $where = '', array $bind = [])
    {
        $parts = array_map(function ($c) {
            return "IFNULL(CAST(`$c` AS CHAR),'~')";
        }, $cols);
        $r = $t->db()->selectOne("SELECT COUNT(*) n, IFNULL(SUM(CRC32(CONCAT_WS('|'," . implode(',', $parts) . '))),0) h FROM `' . $table . '` ' . $where, $bind);

        return [(int) $r->n, (string) $r->h];
    }

    /** Keadaan developer: tiga tabel opname (semua baris) + kolom verifikasi archives non-QA251. */
    function q251_devstate($t)
    {
        $out = [];
        foreach (['archive_opnames' => 'opnames', 'archive_opname_folders' => 'folders', 'archive_opname_documents' => 'documents'] as $table => $key) {
            $out[$key] = q251_sum($t, $table, q251_cols($t, $table));
        }
        $out['archives'] = q251_sum($t, 'archives', ['id_archive', 'is_verified', 'verified_at', 'verified_by', 'id_archive_opname'], 'WHERE name NOT LIKE ?', [Q251_PREFIX . '%']);

        return $out;
    }

    /**
     * Awal AC: pulihkan sisa run yang mati (user, grant, sesi, sisipan massal), buang data QA251-, catat pra-citra baru dan keadaan
     * developer. TIDAK menuntut tabel opname kosong atau archives default. Menetapkan $GLOBALS['q251_now'] = acuan "sekarang" untuk
     * waktu sesi QA (q251_day): 10 hari di depan sesi developer terbaru (atau waktu nyata bila lebih baru).
     */
    function q251_baseline($t)
    {
        q251_recover_user($t);
        q251_grant_recover($t);
        q251_preset_purge($t);
        q251_sweep($t);
        @unlink(q251_vjournal());
        q251_bulk_purge($t);
        q251_purge($t);
        $n = q251_write_vjournal($t);
        $newest = $t->db()->table('archive_opnames')->max('confirmed_at');
        $GLOBALS['q251_now'] = max(time(), $newest ? strtotime($newest) : 0) + 10 * 86400;
        $GLOBALS['q251_last'] = 0;
        $GLOBALS['q251_base'] = q251_devstate($t);
        $b = $GLOBALS['q251_base'];
        $t->note(sprintf('data developer di DB uji dijaga: %d sesi, %d baris folder, %d baris dokumen opname; %d baris archives verifikasi tidak default', $b['opnames'][0], $b['folders'][0], $b['documents'][0], $n));

        return $b;
    }

    /** Tanggal (Y-m-d) "$daysAgo hari lalu" menurut acuan q251_now (di atas semua sesi developer). */
    function q251_day($daysAgo)
    {
        return date('Y-m-d', ($GLOBALS['q251_now'] ?? (time() + 10 * 86400)) - (int) $daysAgo * 86400);
    }

    /** Akhir AC (finally): pulihkan nilai sebelumnya, buang data QA, buktikan data developer sama persis. */
    function q251_cleanup($t, $label = 'data uji dibuang')
    {
        $tracked = q251_tracked();
        q251_sweep($t);
        q251_bulk_purge($t);
        q251_purge($t);
        q251_grant_recover($t);
        q251_preset_purge($t);
        $c = $t->db();
        $gone = $tracked ? $c->table('archive_opnames')->whereIn('id_archive_opname', $tracked)->count() : 0;
        $now = q251_devstate($t);
        $base = $GLOBALS['q251_base'] ?? null;
        $t->eq($gone, 0, $label . ' (sesi opname QA)');
        $t->eq($c->table('archives')->where('name', 'like', Q251_PREFIX . '%')->count(), 0, $label . ' (archives QA251-)');
        $t->eq($c->table('archive_documents')->where('transaction_no', 'like', Q251_PREFIX . '%')->count(), 0, $label . ' (archive_documents)');
        $t->eq($c->table('archive_permissions')->where('created_by', 'QA251')->count(), 0, $label . ' (archive_permissions)');
        $t->eq($c->table('roles')->where('role_name', 'like', Q251_ROLE_PREFIX . '%')->count(), 0, $label . ' (role preset QA251-ROLE-)');
        $t->eq($c->table('role_permissions')->where('created_by', 'QA251')->count(), 0, $label . ' (role_permissions QA251)');
        $t->eq($c->table('archive_opnames')->where('id_archive_opname', 'like', 'QA251BULK%')->count(), 0, $label . ' (sisipan massal)');
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
        @unlink(q251_vjournal());
    }

    // ------------------------------------------------------------------ grant permission sementara (role_permissions), berjurnal

    function q251_gjournal()
    {
        return __DIR__ . '/.grant-journal-q251.json';
    }

    /** Hapus baris role_permissions yang dibuat QA (id tercatat di jurnal; hanya created_by QA251). */
    function q251_grant_recover($t)
    {
        if (is_file(q251_gjournal())) {
            $ids = json_decode(file_get_contents(q251_gjournal()), true);
            if (is_array($ids) && $ids) {
                q251_w($t, function ($c) use ($ids) {
                    $c->table('role_permissions')->whereIn('id_role_permission', $ids)->where('created_by', 'QA251')->delete();
                });
            }
            @unlink(q251_gjournal());
        }
    }

    function q251_perm_id($t, $name)
    {
        return $t->db()->table('permissions')->where('permission_name', $name)->value('id_permission');
    }

    function q251_has_perm($t, $role, $name)
    {
        return $t->db()->table('role_permissions as rp')->join('permissions as p', 'p.id_permission', '=', 'rp.id_permission')
            ->where('rp.id_role', $role)->where('p.permission_name', $name)->exists();
    }

    /** Beri role(s) permission $name (per NAMA); mengembalikan [id baris dibuat]. Dicatat di jurnal. Hanya role preset QA251-ROLE-*. Role yang sudah memegangnya tidak disentuh. */
    function q251_grant($t, $roles, $name)
    {
        // BR-4: hanya role preset QA251-ROLE-* (kunci preset atau id-nya); role developer ditolak
        $roles = array_map(function ($r) use ($t) {
            return q251_preset_ref($t, $r);
        }, (array) $roles);
        $pid = q251_perm_id($t, $name);
        $t->true($pid !== null, "permission '$name' ada (dicari per nama, id $pid)");
        $made = [];
        q251_w($t, function ($c) use ($roles, $name, $pid, &$made) {
            foreach ($roles as $role) {
                $has = $c->table('role_permissions')->where('id_role', $role)->where('id_permission', $pid)->exists();
                if (!$has) {
                    $made[] = $c->table('role_permissions')->insertGetId([
                        'id_role' => $role, 'id_permission' => $pid, 'permission_name' => $name,
                        'created_at' => date('Y-m-d H:i:s'), 'created_by' => 'QA251',
                    ]);
                }
            }
        });
        $old = is_file(q251_gjournal()) ? (json_decode(file_get_contents(q251_gjournal()), true) ?: []) : [];
        file_put_contents(q251_gjournal(), json_encode(array_merge($old, $made)));

        return $made;
    }

    /** Ringkasan (jumlah+CRC) role_permissions: bukti pemulihan persis. */
    function q251_rp_sum($t)
    {
        return q251_sum($t, 'role_permissions', q251_cols($t, 'role_permissions'));
    }

    /**
     * Jalankan $fn dengan role(s) $roles memegang permission $name. Baris role_permissions yang dibuat dicatat di jurnal dan dihapus
     * persis di finally; bukti: jumlah+CRC role_permissions sama dengan sebelumnya.
     */
    function q251_with_grant($t, $roles, $name, callable $fn)
    {
        $roles = array_map(function ($r) use ($t) {
            return q251_preset_ref($t, $r);
        }, (array) $roles); // preset dibuat DULU (jumlah+CRC role_permissions "sebelum" sudah memuat baris dasarnya)
        q251_grant_recover($t);
        $before = q251_rp_sum($t);
        try {
            q251_grant($t, $roles, $name);

            return $fn();
        } finally {
            q251_grant_recover($t);
            $after = q251_rp_sum($t);
            if (json_encode($after) !== json_encode($before)) {
                $t->fail("role_permissions tidak sama persis sesudah grant '$name' dicabut: " . json_encode($before) . ' -> ' . json_encode($after));
            }
        }
    }

    // ------------------------------------------------------------------ baris konfigurasi permanen documentArchiveOpnameResult (DU-5 a)

    define('Q251_MODULE', 'documentArchiveOpnameResult');
    define('Q251_DS', 'api/v5/setting/display-columns/');
    define('Q251_SEL', 'api/v5/select/report/document-archive/opname/');

    function q251_tjournal()
    {
        return __DIR__ . '/.journal-config-q251.json';
    }

    /** Baris cds documentArchiveOpnameResult (array baris utuh). */
    function q251_cds_rows($t)
    {
        return q251_rows($t->db()->table('column_display_settings')->where('module_name', Q251_MODULE)->orderBy('id_column_display_setting')->get());
    }

    /** Baris transaction_pdf_templates documentArchiveOpnameResult (array baris utuh). */
    function q251_tpl_row($t)
    {
        return q251_rows($t->db()->table('transaction_pdf_templates')->where('transaction_name', Q251_MODULE)->orderBy('id_transaction_pdf_template')->get());
    }

    /** [jumlah, CRC] baris transaction_pdf_templates SELAIN milik sendiri (guard C-9). */
    function q251_tpl_other($t)
    {
        return q251_sum($t, 'transaction_pdf_templates', q251_cols($t, 'transaction_pdf_templates'), 'WHERE transaction_name <> ?', [Q251_MODULE]);
    }

    /** Catat baris cds + template sendiri (utuh) di jurnal; dipulihkan q251_tpl_restore / q251_tpl_recover. */
    function q251_tpl_save($t)
    {
        $snap = ['cds' => q251_cds_rows($t), 'tpl' => q251_tpl_row($t)];
        file_put_contents(q251_tjournal(), json_encode($snap));

        return $snap;
    }

    function q251_tpl_restore($t, array $snap)
    {
        q251_w($t, function ($c) use ($snap) {
            foreach (['cds' => ['column_display_settings', 'module_name'], 'tpl' => ['transaction_pdf_templates', 'transaction_name']] as $k => $spec) {
                $c->table($spec[0])->where($spec[1], Q251_MODULE)->delete();
                foreach ($snap[$k] as $row) {
                    $c->table($spec[0])->insert($row);
                }
            }
        });
    }

    /** Pulihkan baris cds + template sendiri dari jurnal bila run sebelumnya mati. */
    function q251_tpl_recover($t)
    {
        if (is_file(q251_tjournal())) {
            $snap = json_decode(file_get_contents(q251_tjournal()), true);
            if (is_array($snap) && isset($snap['cds'], $snap['tpl'])) {
                q251_tpl_restore($t, $snap);
            }
            @unlink(q251_tjournal());
        }
    }

    /**
     * Jalankan $fn dengan jaminan: baris cds + template sendiri dipulihkan PERSIS (nilai kolom) di finally, dan template lain
     * (count + checksum), selain itu tidak ada baris template baru (guard C-9).
     */
    function q251_with_config($t, callable $fn)
    {
        q251_tpl_recover($t);
        $snap = q251_tpl_save($t);
        $other = q251_tpl_other($t);
        $names = $t->db()->table('transaction_pdf_templates')->pluck('transaction_name')->all();
        try {
            return $fn($snap);
        } finally {
            q251_tpl_restore($t, $snap);
            @unlink(q251_tjournal());
            $t->eq(json_encode(q251_cds_rows($t)), json_encode($snap['cds']), 'baris cds dipulihkan persis');
            $t->eq(json_encode(q251_tpl_row($t)), json_encode($snap['tpl']), 'baris template sendiri dipulihkan persis');
            $t->eq(json_encode(q251_tpl_other($t)), json_encode($other), 'template lain tidak berubah (count + checksum, C-9)');
            $after = $t->db()->table('transaction_pdf_templates')->pluck('transaction_name')->all();
            $t->eq(json_encode(array_values(array_diff($after, $names))), json_encode([]), 'tidak ada nama template baru');
        }
    }


    // ------------------------------------------------------------------ guard + fixture ED-1251

    /**
     * Awal-akhir AC: baseline (sisa run mati dipulihkan, data QA251- dibuang), jurnal baris cds + template sendiri, QA_USER -> preset FULL
     * (bahasa $opt['lang'], default EN) dan QA_USER2 -> preset U2 ($opt['u2'] = 'U2' | 'R0' | false = tidak diubah), jalankan $fn,
     * lalu pulihkan semuanya dan buktikan: user_roles QA_USER/QA_USER2, bahasa, role_permissions, baris config, data developer sama
     * persis dengan sebelum AC (role developer tidak pernah disentuh).
     */
    function q251_guard($t, callable $fn, array $opt = [])
    {
        $t->session(); // login QA_USER SEBELUM role diganti: employee non-superadmin tanpa geotag = 422 di POST auth/token
        q251_u2_recover($t);
        q251_baseline($t);
        $rpBefore = q251_rp_sum($t);
        $u2Orig = q251_u2_snapshot($t);
        $t->true(count($u2Orig) >= 1, 'prasyarat: QA_USER2 punya role (dipulihkan persis sesudah AC)');
        file_put_contents(q251_u2_journal(), json_encode(['uid' => q251_u2_id($t), 'rows' => $u2Orig]));
        $userBefore = json_encode(q251_user_snapshot($t));
        $err = null;
        try {
            return q251_with_config($t, function () use ($t, $fn, $opt) {
                return q251_with_user($t, ['role' => $opt['role'] ?? Q251_PRESET_FULL, 'lang' => $opt['lang'] ?? 'EN'], function ($set) use ($t, $fn, $opt) {
                    $u2 = $opt['u2'] ?? Q251_PRESET_U2;
                    if ($u2) {
                        q251_u2_use($t, $u2);
                    }

                    return $fn($set);
                });
            });
        } catch (\Throwable $e) {
            $err = $e;
            throw $e;
        } finally {
            try {
                q251_grant_recover($t);
                q251_u2_recover($t);
                q251_cleanup($t); // membuang preset QA251-ROLE-* dan membuktikan sisa = 0, data developer sama persis
                $t->eq(json_encode(q251_u2_snapshot($t)), json_encode($u2Orig), 'user_roles QA_USER2 dipulihkan persis (sama dengan sebelum AC)');
                $t->eq(json_encode(q251_user_snapshot($t)), $userBefore, 'user_roles/bahasa/lokasi QA_USER dipulihkan persis');
                $t->eq(json_encode(q251_rp_sum($t)), json_encode($rpBefore), 'role_permissions sama persis dengan sebelum AC (preset dibuang)');
            } catch (\Throwable $c) {
                if ($err === null) {
                    throw $c;
                }
                $t->note('pembersihan juga gagal: ' . $c->getMessage());
            }
        }
    }

    /** Dokumen uji ber-tipe transaksi $tx (null = tanpa tipe) dengan nama tetap-berawalan; $a['name'] opsional. */
    function q251_xdoc($t, $label, array $a = [], $tx = 6)
    {
        $id = q251_new_id($t);
        $name = $a['name'] ?? q251_name($label);
        $now = date('Y-m-d H:i:s');
        $locs = q251_locs($t);
        q251_w($t, function ($c) use ($a, $id, $name, $now, $tx) {
            $c->table('archives')->insert([
                'id_archive' => $id, 'id_archive_parent' => $a['parent'] ?? null, 'name' => $name, 'type' => 2, 'status' => 1,
                'is_all_location' => 1, 'is_folder_permission' => 0, 'is_active' => 1,
                'created_at' => $now, 'created_by' => 'QA251', 'updated_at' => $now, 'updated_by' => 'QA251',
            ]);
            $c->table('archive_documents')->insert([
                'id_archive_document' => \Modules\V5\Entities\Helper\MyHelper::generateId(), 'id_archive' => $id,
                'id_transaction' => 'QA251TX' . substr($id, -10), 'transaction_no' => $name, 'transaction_type' => $tx,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        });

        return [$id, $name];
    }

    /**
     * Fixture standar ED-1251 (semua berawalan 0QA251-). Dibuat oleh $s (QA_USER, preset FULL):
     *   F (tanpa izin folder)  -- A (izin folder: QA_USER dan user2 View)   -- B (izin folder: hanya QA_USER)
     *   OUT (folder lain: dokumen di luar scope) ; G (sepenuhnya terlihat user2)
     * S1 = opname F + subfolder A, B (scope_folder_count 2). Dokumen:
     *   F: dF1 (tipe 6) discan -> Verified; dF2 (tipe 7) tidak discan; dF3 (tanpa tipe) discan; dFx (nama berisi < & ") belum discan
     *   A: dA1 (tipe 8) discan; dA2 (tipe 9) tidak discan        B: dB1 (tipe 9) discan; dB2 (tipe 6) tidak discan
     *   OUT: dO1 (tipe 6) discan -> Not found ; kode tak ada -> Invalid
     * S2 = opname G: g1 (tipe 6) discan, g2 (tipe 7) tidak discan. Mengembalikan [peta fixture, ['S1' => id, 'S2' => id]].
     */
    function q251_fixture($t, $s)
    {
        $uid = q251_uid($t);
        $u2 = q251_u2_id($t);
        $x = [];
        $x['F'] = q251_folder($t, 'F');
        $x['A'] = q251_folder($t, 'A', ['parent' => $x['F'], 'perm' => 1]);
        $x['B'] = q251_folder($t, 'B', ['parent' => $x['F'], 'perm' => 1]);
        q251_perm($t, $x['A'], $uid, 1);
        q251_perm($t, $x['A'], $u2, 1);
        q251_perm($t, $x['B'], $uid, 1);
        $x['OUT'] = q251_folder($t, 'OUT');
        $x['G'] = q251_folder($t, 'G');
        $n = [];
        foreach ([
            'dF1' => ['F', 6], 'dF2' => ['F', 7], 'dF3' => ['F', null], 'dFx' => ['F', 8],
            'dA1' => ['A', 8], 'dA2' => ['A', 9], 'dB1' => ['B', 9], 'dB2' => ['B', 6],
            'dO1' => ['OUT', 6], 'g1' => ['G', 6], 'g2' => ['G', 7],
        ] as $k => $spec) {
            $a = ['parent' => $x[$spec[0]]];
            if ($k === 'dFx') {
                $a['name'] = Q251_PREFIX . 'dFx<b>&"' . substr(uniqid(), -5);
            }
            list($x[$k], $n[$k]) = q251_xdoc($t, $k, $a, $spec[1]);
        }
        $x['n'] = $n;
        $invalid = Q251_PREFIX . 'TIDAK-ADA-' . substr(uniqid(), -6);
        $x['invalid'] = $invalid;
        $ids = [];
        $ids['S1'] = q251_run($t, $s, $x['F'], q251_sel([$x['A'], $x['B']]), [$n['dF1'], $n['dF3'], $n['dA1'], $n['dB1'], $n['dO1'], $invalid]);
        $ids['S2'] = q251_run($t, $s, $x['G'], [], [$n['g1']]);

        return [$x, $ids];
    }

    /** GET dengan unduhan (pdf/xls): [status, body, headers]. */
    function q251_file($t, $s, $id, $kind, array $q = [])
    {
        return $t->callFile($s, 'GET', q251_url(Q251_BASE . '/opnames/' . $id . '/' . $kind, $q));
    }

    /** Mentah DB confirmed_at (teks apa adanya). */
    function q251_confirmed($t, $id)
    {
        return $t->db()->selectOne('SELECT CAST(confirmed_at AS CHAR) c FROM archive_opnames WHERE id_archive_opname = ?', [$id])->c;
    }

    // ------------------------------------------------------------------ request

    function q251_url($path, array $query = [])
    {
        return $path . ($query ? '?' . http_build_query($query) : '');
    }

    function q251_code($r)
    {
        return $r[1]['code'] ?? $r[1]['msg_code'] ?? null;
    }

    function q251_no500($t, $r, $label)
    {
        $t->true($r[0] < 500, $label . ': HTTP ' . $r[0] . ' (tidak boleh 5xx)');
    }

    function q251_deny($t, $r, $http, $code, $label)
    {
        $t->status($r, $http, $label);
        $t->eq(q251_code($r), $code, $label . ': code');
    }

    /** GET archives (pagination 1000 bila tidak diisi). */
    function q251_list($t, $s, array $q = [])
    {
        return $t->call($s, 'GET', q251_url(Q251_BASE . '/archives', $q + ['pagination' => 1000]));
    }

    /** id_archive => baris dari respons list. */
    function q251_map($r)
    {
        $map = [];
        foreach ($r[1]['result']['data'] ?? [] as $row) {
            $map[$row['id_archive']] = $row;
        }

        return $map;
    }

    function q251_verif($t, $s, $id = null)
    {
        return $t->call($s, 'GET', Q251_BASE . '/verifications' . ($id === null ? '' : '/' . $id));
    }

    function q251_search($filters)
    {
        return json_encode($filters);
    }

    // ------------------------------------------------------------------ sesi opname 03 (rev: tanpa draft; POST opnames = Konfirmasi)

    /** Handle satu sesi stateless: pilihan Step 1, selected_at, daftar scan; id terisi sesudah Konfirmasi sukses. */
    class Q251Session
    {
        public $sel;
        public $selectedAt;
        public $scans = [];
        public $id;
        public $confirm;
    }

    /** Body pilihan Step 1: folder opname (null = root), subfolder terpilih (q251_sel), is_continue folder opname. */
    function q251_selection($folder, array $folders = [], $continue = null)
    {
        $b = ['id_archive' => $folder, 'folders' => $folders];
        if ($continue !== null) {
            $b['is_continue'] = $continue;
        }

        return $b;
    }

    /** Tunggu sampai detik server berikutnya sesudah Konfirmasi terakhir: selected_at baru tidak bentrok dengannya (ARCHIVE414). */
    function q251_wait()
    {
        while (time() <= (int) ($GLOBALS['q251_last'] ?? 0)) {
            usleep(100000);
        }
    }

    /** Muat Step 1 (GET opnames/folders) -> handle sesi dengan selected_at server. */
    function q251_session($t, $s, $folder, array $folders = [], $continue = null)
    {
        q251_wait();
        $r = $t->call($s, 'GET', q251_url(Q251_BASE . '/opnames/folders', $folder === null ? [] : ['id_archive' => $folder]));
        $t->status($r, 200, 'muat Step 1 (selected_at)');
        $at = $r[1]['result']['selected_at'] ?? null;
        $t->true(is_string($at) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $at) === 1, 'selected_at = Y-m-d H:i:s');
        $h = new Q251Session();
        $h->sel = q251_selection($folder, $folders, $continue);
        $h->selectedAt = $at;

        return $h;
    }

    /** POST opnames/scan (klasifikasi satu kode, tanpa simpan); scan sukses dicatat di handle. Mengembalikan respons. */
    function q251_scan($t, $s, Q251Session $h, $code)
    {
        $r = $t->call($s, 'POST', Q251_BASE . '/opnames/scan', $h->sel + ['code' => $code]);
        if ($r[0] === 200 && isset($r[1]['result']['row']['scanned_at'])) {
            $h->scans[] = ['code' => $code, 'scanned_at' => $r[1]['result']['row']['scanned_at']];
        }

        return $r;
    }

    /** POST opnames = Konfirmasi Opname (satu-satunya penulisan sesi); sesi sukses dicatat di jurnal. Mengembalikan respons. */
    function q251_confirm($t, $s, Q251Session $h)
    {
        $r = $t->call($s, 'POST', Q251_BASE . '/opnames', $h->sel + ['scans' => $h->scans, 'selected_at' => $h->selectedAt]);
        $GLOBALS['q251_last'] = time();
        $h->confirm = $r;
        $id = $r[1]['result']['id_archive_opname'] ?? null;
        if ($r[0] === 200 && $id) {
            $h->id = $id;
            q251_track($id);
        }

        return $r;
    }

    function q251_sel(array $ids)
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

    /** Sesi sukses seperti FE: muat Step 1, scan kode-kode, satu POST opnames (200 dituntut). Mengembalikan id sesi (string). */
    function q251_run($t, $s, $folder, array $folders, array $codes, $continue = null)
    {
        $h = q251_session($t, $s, $folder, $folders, $continue);
        foreach ($codes as $code) {
            $t->status(q251_scan($t, $s, $h, $code), 200, "scan $code");
        }
        $t->status(q251_confirm($t, $s, $h), 200, 'confirm (POST opnames)');
        $t->true($h->id !== null, 'confirm: id sesi dikembalikan');

        return $h->id;
    }

    function q251_set_owner($t, $id, $username)
    {
        q251_w($t, function ($c) use ($id, $username) {
            $c->table('archive_opnames')->where('id_archive_opname', $id)->update(['created_by' => $username]);
        });
    }

    function q251_set_opname($t, $id, array $cols)
    {
        q251_w($t, function ($c) use ($id, $cols) {
            $c->table('archive_opnames')->where('id_archive_opname', $id)->update($cols);
        });
    }

    function q251_opname($t, $id)
    {
        $r = $t->db()->table('archive_opnames')->where('id_archive_opname', $id)->first();

        return $r ? (array) $r : null;
    }

    // ------------------------------------------------------------------ oracle independen (SQL + PHP, tanpa kode BE)

    /**
     * Hitung dari tabel mentah, tanpa kode BE, angka verifikasi yang terlihat satu user.
     * $u: bypass (bool, superadmin 1/2), locs (array kode lokasi | null = semua lokasi), username, uid.
     * Hasil:
     *   folders  = [id => ['v','t','types'=>[tipe=>[v,t]], 'parent','name']] hanya folder terlihat, angka = folder + subfolder terlihat
     *   summary  = ['v','t','types'] semua dokumen terlihat (root + setiap folder terlihat)
     *   visible  = [id => true] folder terlihat (aktif + lokasi + View efektif)
     *   viewable = [id => bool] View efektif folder aktif (tanpa melihat lokasi)
     *   direct   = [id|'*' => ['v','t','types']] angka langsung
     */
    function q251_oracle($t, array $u)
    {
        $c = $t->db();
        $locIds = null;
        if ($u['locs'] !== null) {
            $map = q251_locs($t);
            $locIds = array_map(function ($code) use ($map) {
                return $map[$code];
            }, $u['locs']);
        }

        $archiveLocs = [];
        foreach ($c->table('archive_locations')->get(['id_archive', 'id_location']) as $r) {
            $archiveLocs[$r->id_archive][] = $r->id_location;
        }
        $visLoc = function ($id, $isAll) use ($locIds, $archiveLocs) {
            if ($locIds === null || (int) $isAll === 1) {
                return true;
            }

            return count(array_intersect($archiveLocs[$id] ?? [], $locIds)) > 0;
        };

        $perms = [];
        if (!$u['bypass']) {
            foreach ($c->table('archive_permissions')->where('id_user', $u['uid'])->get() as $r) {
                $perms[$r->id_archive] = $r;
            }
        }

        $all = [];
        foreach ($c->table('archives')->where('type', 1)->get(['id_archive', 'id_archive_parent', 'name', 'is_active', 'is_all_location', 'is_folder_permission', 'created_by']) as $f) {
            $all[$f->id_archive] = $f;
        }

        $grants = function ($f) use ($u, $perms) {
            if ((int) $f->is_folder_permission !== 1) {
                return true;
            }
            if ($f->created_by !== null && $u['username'] !== '' && strcasecmp($f->created_by, $u['username']) === 0) {
                return true;
            }
            $row = $perms[$f->id_archive] ?? null;

            return $row !== null && (int) $row->is_view === 1;
        };
        $viewable = [];
        foreach ($all as $id => $f) {
            if ($u['bypass']) {
                $viewable[$id] = true;
                continue;
            }
            $ok = true;
            $seen = [];
            for ($g = $f; $g !== null && !isset($seen[$g->id_archive]); $g = $g->id_archive_parent === null ? null : ($all[$g->id_archive_parent] ?? null)) {
                $seen[$g->id_archive] = true;
                if (!$grants($g)) {
                    $ok = false;
                    break;
                }
            }
            $viewable[$id] = $ok;
        }

        $visible = [];
        $children = [];
        foreach ($all as $id => $f) {
            if ((int) $f->is_active > 0 && $visLoc($id, $f->is_all_location) && $viewable[$id]) {
                $visible[$id] = true;
            }
        }
        foreach ($visible as $id => $_) {
            $p = $all[$id]->id_archive_parent;
            if ($p !== null && isset($visible[$p])) {
                $children[$p][] = $id;
            }
        }

        $direct = [];
        $add = function (&$e, $v, $tx) {
            $e['v'] += $v;
            $e['t'] += 1;
            if ($tx !== null) {
                $e['types'][(int) $tx][0] = ($e['types'][(int) $tx][0] ?? 0) + $v;
                $e['types'][(int) $tx][1] = ($e['types'][(int) $tx][1] ?? 0) + 1;
            }
        };
        $rows = $c->table('archives as a')->leftJoin('archive_documents as d', 'd.id_archive', '=', 'a.id_archive')
            ->where('a.type', 2)->where('a.is_active', '>', 0)
            ->get(['a.id_archive', 'a.id_archive_parent', 'a.is_all_location', 'a.is_verified', 'd.transaction_type']);
        foreach ($rows as $d) {
            if (!$visLoc($d->id_archive, $d->is_all_location)) {
                continue;
            }
            $k = $d->id_archive_parent === null ? '*' : $d->id_archive_parent;
            if ($k !== '*' && !isset($visible[$k])) {
                continue;
            }
            $direct[$k] = $direct[$k] ?? ['v' => 0, 't' => 0, 'types' => []];
            $add($direct[$k], (int) $d->is_verified === 1 ? 1 : 0, $d->transaction_type);
        }

        $merge = function (&$a, $b) {
            $a['v'] += $b['v'];
            $a['t'] += $b['t'];
            foreach ($b['types'] as $tx => $vt) {
                $a['types'][$tx][0] = ($a['types'][$tx][0] ?? 0) + $vt[0];
                $a['types'][$tx][1] = ($a['types'][$tx][1] ?? 0) + $vt[1];
            }
        };
        $sub = function ($id) use (&$sub, $children, $direct, $merge) {
            $e = ['v' => 0, 't' => 0, 'types' => []];
            if (isset($direct[$id])) {
                $merge($e, $direct[$id]);
            }
            foreach ($children[$id] ?? [] as $ch) {
                $merge($e, $sub($ch));
            }

            return $e;
        };

        $folders = [];
        foreach ($visible as $id => $_) {
            $folders[$id] = $sub($id) + ['parent' => $all[$id]->id_archive_parent, 'name' => $all[$id]->name];
        }
        $summary = ['v' => 0, 't' => 0, 'types' => []];
        foreach ($direct as $e) {
            $merge($summary, $e);
        }

        return ['folders' => $folders, 'summary' => $summary, 'visible' => $visible, 'viewable' => $viewable, 'direct' => $direct, 'subtree' => $sub];
    }

    /** Keadaan user uji untuk oracle: ['smr' | 'jog' | 'smrjog' | 'bypass']. */
    function q251_u($t, $mode)
    {
        $base = ['username' => (string) q251_uname($t), 'uid' => q251_uid($t)];
        switch ($mode) {
            case 'smr':
                return $base + ['bypass' => false, 'locs' => ['SMR']];
            case 'jog':
                return $base + ['bypass' => false, 'locs' => ['JOG']];
            case 'smrjog':
                return $base + ['bypass' => false, 'locs' => ['SMR', 'JOG']];
            default:
                return $base + ['bypass' => true, 'locs' => null];
        }
    }

    /** opts untuk q251_with_user per mode. */
    function q251_opts($mode)
    {
        switch ($mode) {
            case 'smr':
                return ['emp' => ['SMR']];
            case 'jog':
                return ['emp' => ['JOG']];
            case 'smrjog':
                return ['emp' => ['SMR', 'JOG']];
            default:
                return ['role' => 1];
        }
    }

    /** Pasangan [verified, total] dari oracle atau respons ke bentuk array dua angka. */
    function q251_vt($e)
    {
        return [(int) $e['v'], (int) $e['t']];
    }

    function q251_api_vt($o)
    {
        return $o === null ? null : [(int) $o['verified'], (int) $o['total']];
    }
    // ------------------------------------------------------------------ QA_USER2 sungguhan

    function q251_u2_journal()
    {
        return __DIR__ . '/.restore-journal-q251-user2.json';
    }

    function q251_u2_id($t)
    {
        return $t->db()->table('users')->where('username', $t->conf('QA_USER2'))->value('id_user');
    }

    function q251_u2_name($t)
    {
        return (string) $t->db()->table('users')->where('username', $t->conf('QA_USER2'))->value('username');
    }

    /** Token user2 terikat DB QA (force_login hanya mencabut token user2 sendiri). */
    function q251_u2_session($t)
    {
        $s = $t->login('QA_USER2');
        $r = $t->bind($s, q251_dbname($t));
        $t->eq($r[0], 200, 'bind QA_USER2');

        return $s;
    }

    /** id_role user2 sekarang (dibaca dari DB). */
    function q251_u2_roles_now($t)
    {
        return $t->db()->table('user_roles')->where('id_user', q251_u2_id($t))->pluck('id_role')->all();
    }

    function q251_u2_snapshot($t)
    {
        return q251_rows($t->db()->table('user_roles')->where('id_user', q251_u2_id($t))->orderBy('id_user_role')->get());
    }

    function q251_u2_write($t, $uid, array $rows)
    {
        q251_w($t, function ($c) use ($uid, $rows) {
            $c->table('user_roles')->where('id_user', $uid)->delete();
            if ($rows) {
                $c->table('user_roles')->insert($rows);
            }
        });
    }

    /** QA_USER2 HANYA di preset $key (kunci preset; role developer tidak diterima). Jurnal tetap memegang user_roles ASLI. Mengembalikan id preset. */
    function q251_u2_use($t, $key)
    {
        $uid = q251_u2_id($t);
        $role = q251_preset($t, $key);
        $now = date('Y-m-d H:i:s');
        q251_u2_write($t, $uid, [['id_user' => $uid, 'id_role' => $role, 'is_all_location' => 1, 'id_location' => null, 'created_at' => $now, 'updated_at' => $now]]);

        return $role;
    }

    /** Pulihkan user_roles ke baris $snap (daftar baris). */
    function q251_u2_restore($t, array $snap)
    {
        q251_u2_write($t, q251_u2_id($t), $snap);
    }

    /** Pulihkan user_roles ASLI QA_USER2 dari jurnal bila run sebelumnya mati di tengah (juga bentuk jurnal lama = daftar baris). */
    function q251_u2_recover($t)
    {
        if (is_file(q251_u2_journal())) {
            $snap = json_decode(file_get_contents(q251_u2_journal()), true);
            if (is_array($snap) && !empty($snap['rows'])) {
                q251_u2_write($t, $snap['uid'], $snap['rows']);
            } elseif (is_array($snap) && $snap && isset($snap[0]['id_user'])) {
                q251_u2_write($t, $snap[0]['id_user'], $snap);
            }
            @unlink(q251_u2_journal());
        }
    }

    /** Keadaan user uji: superadmin (role 1), 'user3' (QA_USER role 3 semua lokasi), 'user2' (QA_USER2 SMR). */
    function q251_uu($t, $who)
    {
        if ($who === 'user2') {
            return ['username' => q251_u2_name($t), 'uid' => q251_u2_id($t), 'bypass' => false, 'locs' => ['SMR']];
        }
        if ($who === 'user3') {
            return ['username' => (string) q251_uname($t), 'uid' => q251_uid($t), 'bypass' => false, 'locs' => null];
        }

        return ['username' => (string) q251_uname($t), 'uid' => q251_uid($t), 'bypass' => true, 'locs' => null];
    }

    /**
     * Oracle ringkasan sesi (BR-16, D-1) dari tabel mentah untuk pemanggil $u (bentuk q251_uu): baris snapshot yang boleh ditampilkan
     * (superadmin: semua; selain itu root `id_archive` NULL + folder terlihat saat ini), warnings (level 0-1, bukan is_continue,
     * jumlah unverified_count baris terlihat yang berkelompok ke baris itu lewat id_archive_parent snapshot; 0 = tak dikirim)
     * dan tally baris dokumen yang boleh dilihat (result 1 verified, 2 not found, 3 invalid, 4 unscanned) + jumlah tersembunyi.
     * @return array{folders: array, warnings: array, hidden_folders: array, tally: array, hidden_docs: int}
     */
    function q251_summary_oracle($t, $idOpname, array $u)
    {
        $c = $t->db();
        $o = q251_oracle($t, $u);
        $rows = $c->table('archive_opname_folders')->where('id_archive_opname', $idOpname)->orderBy('level')->orderBy('name')->get()->all();
        $key = function ($row) {
            return $row->id_archive === null ? '#root' : (string) $row->id_archive;
        };
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$key($r)] = $r;
        }
        $shown = function ($r) use ($u, $o) {
            return $u['bypass'] || $r->id_archive === null || isset($o['visible'][$r->id_archive]);
        };

        $folders = [];
        $hiddenFolders = [];
        $sum = [];
        foreach ($rows as $r) {
            if (!$shown($r)) {
                $hiddenFolders[] = $r->name;
                continue;
            }
            $folders[] = ['id_archive' => $r->id_archive, 'name' => $r->name, 'level' => (int) $r->level, 'is_continue' => (int) $r->is_continue === 1, 'document_count' => (int) $r->total_documents];
            $g = $r;
            for ($guard = 0; (int) $g->level > 1 && $guard < 1000; $guard++) {
                $p = $g->id_archive_parent === null ? null : ($byKey[(string) $g->id_archive_parent] ?? null);
                if ($p === null) {
                    break;
                }
                $g = $p;
            }
            $sum[$key($g)] = ($sum[$key($g)] ?? 0) + (int) $r->unverified_count;
        }
        $warnings = [];
        foreach ($rows as $r) {
            if ($shown($r) && (int) $r->level <= 1 && (int) $r->is_continue !== 1 && ($sum[$key($r)] ?? 0) > 0) {
                $warnings[] = ['id_archive' => $r->id_archive, 'name' => $r->name, 'unverify_count' => $sum[$key($r)]];
            }
        }

        $locIds = null;
        if ($u['locs'] !== null) {
            $map = q251_locs($t);
            $locIds = array_map(function ($code) use ($map) {
                return $map[$code];
            }, $u['locs']);
        }
        $archiveLocs = [];
        foreach ($c->table('archive_locations')->get(['id_archive', 'id_location']) as $r) {
            $archiveLocs[$r->id_archive][] = $r->id_location;
        }
        $tally = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        $hiddenDocs = 0;
        $docs = $c->table('archive_opname_documents as d')->leftJoin('archives as a', 'a.id_archive', '=', 'd.id_archive')
            ->where('d.id_archive_opname', $idOpname)->get(['d.id_archive', 'd.id_archive_folder', 'd.result', 'a.is_all_location as doc_all']);
        foreach ($docs as $r) {
            $show = true;
            if (!$u['bypass'] && $r->id_archive !== null) {
                $locOk = $locIds === null || (int) $r->doc_all === 1 || count(array_intersect($archiveLocs[$r->id_archive] ?? [], $locIds)) > 0;
                $folderOk = $r->id_archive_folder === null || isset($o['visible'][$r->id_archive_folder]);
                $show = $locOk && $folderOk;
            }
            if ($show) {
                $tally[(int) $r->result]++;
            } else {
                $hiddenDocs++;
            }
        }

        return ['folders' => $folders, 'warnings' => $warnings, 'hidden_folders' => $hiddenFolders, 'tally' => $tally, 'hidden_docs' => $hiddenDocs];
    }

}
