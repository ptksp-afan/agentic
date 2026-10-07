<?php
/**
 * Helper bersama skenario ED-1028 (BUKAN skenario: nama berkas tidak cocok scenario*.php).
 * Di-require_once oleh setiap scenario*.php.
 *
 * Aturan pakai:
 * - Data uji archive berawalan "QA28-" (folder, dokumen, lokasi, archive_documents, archive_permissions). Dibuat lewat
 *   q28_add(), dibuang lewat q28_purge(). Sesi opname dibuat lewat API opname ED-1026 (bukan insert manual) dan dicatat
 *   di jurnal (.sessions-journal-q28.json); q28_cleanup() menghapus baris archive_opname* sesi itu dan mengembalikan
 *   kolom verifikasi `archives` ke default untuk baris yang ditandai id_archive_opname sesi itu.
 *   Prasyarat run: tabel opname kosong dan semua kolom verifikasi `archives` default (q28_baseline()).
 * - QA_USER2 tidak bisa login di api_sidomaju: "user lokasi terbatas / tanpa hak / superadmin" = QA_USER sendiri dengan
 *   keadaan sementara (role, lokasi employee, bahasa) lewat q28_with_user(); jurnal pemulihan di .restore-journal-q28.json.
 *   Role bawaan QA_USER = 3 (is_superadmin 3: TIDAK bypass lokasi/hak folder). Bypass (superadmin 1/2) = role 1 dan 2.
 *   Role 29 = hanya List Archive. Role 6 = tanpa permission Archive.
 * - Tulis lewat koneksi default non-read-only di dalam $t->probe(); baca lewat $t->db() (read-only).
 * - Oracle (q28_oracle) menghitung angka verifikasi dari tabel mentah, tanpa kode BE, untuk satu keadaan user.
 */

if (!function_exists('q28_dbname')) {

    define('Q28_PREFIX', 'QA28-');
    define('Q28_BASE', 'api/v5/document-archive');
    define('Q28_ROLE_LIST_ONLY', 29);
    define('Q28_ROLE_NONE', 6);
    define('Q28_RANDOM_ID', '999999999999999999999999999999');

    function q28_dbname($t)
    {
        return $t->dbs()[0];
    }

    /** Jalankan $fn($conn) dengan koneksi tulis ke DB QA (koneksi default BE_DIR) di dalam probe. */
    function q28_w($t, callable $fn)
    {
        $db = q28_dbname($t);

        return $t->probe(function () use ($fn, $db) {
            $conn = \Illuminate\Support\Facades\DB::connection();
            if ($conn->getDatabaseName() !== $db) {
                throw new \RuntimeException('koneksi default BE_DIR bukan ' . $db . ' (' . $conn->getDatabaseName() . ')');
            }

            return $fn($conn);
        });
    }

    function q28_rows($collection)
    {
        $out = [];
        foreach ($collection as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }

    function q28_journal()
    {
        return __DIR__ . '/.restore-journal-q28.json';
    }

    function q28_sjournal()
    {
        return __DIR__ . '/.sessions-journal-q28.json';
    }

    function q28_uid($t)
    {
        return $t->db()->table('users')->where('username', $t->conf('QA_USER'))->value('id_user');
    }

    function q28_uname($t)
    {
        return $t->db()->table('users')->where('username', $t->conf('QA_USER'))->value('username');
    }

    /** kode lokasi => id lokasi */
    function q28_locs($t)
    {
        return $t->db()->table('locations')->pluck('id_location', 'location_code')->all();
    }

    // ------------------------------------------------------------------ user QA (role / lokasi / bahasa)

    function q28_user_snapshot($t)
    {
        $c = $t->db();
        $uid = q28_uid($t);
        $emp = $c->table('employees')->where('id_user', $uid)->first();

        return [
            'uid'      => $uid,
            'roles'    => q28_rows($c->table('user_roles')->where('id_user', $uid)->orderBy('id_user_role')->get()),
            'lang'     => $c->table('users')->where('id_user', $uid)->value('language'),
            'emp_id'   => $emp->id_employee ?? null,
            'emp_all'  => $emp->is_all_location ?? null,
            'emp_locs' => $emp ? q28_rows($c->table('employee_locations')->where('id_employee', $emp->id_employee)->orderBy('id_employee_location')->get()) : [],
        ];
    }

    function q28_user_restore($t, array $snap)
    {
        q28_w($t, function ($c) use ($snap) {
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
    function q28_user_apply($t, array $snap, array $opts)
    {
        $locs = q28_locs($t);
        q28_w($t, function ($c) use ($snap, $opts, $locs) {
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

    function q28_recover_user($t)
    {
        if (is_file(q28_journal())) {
            $snap = json_decode(file_get_contents(q28_journal()), true);
            if (is_array($snap)) {
                q28_user_restore($t, $snap);
            }
            @unlink(q28_journal());
        }
    }

    /**
     * Jalankan $fn($set) dengan user QA di keadaan $opts. $set(array $opts) mengganti keadaan (relatif terhadap keadaan
     * asli). Keadaan asli dipulihkan dan diverifikasi persis di finally.
     */
    function q28_with_user($t, array $opts, callable $fn)
    {
        q28_recover_user($t);
        $snap = q28_user_snapshot($t);
        file_put_contents(q28_journal(), json_encode($snap));

        $set = function (array $o) use ($t, $snap) {
            q28_user_restore($t, $snap);
            q28_user_apply($t, $snap, $o);
        };

        try {
            $set($opts);

            return $fn($set);
        } finally {
            q28_user_restore($t, $snap);
            $after = q28_user_snapshot($t);
            if (json_encode($after) !== json_encode($snap)) {
                $t->fail('pemulihan user QA tidak persis sama dengan sebelumnya (jurnal dipertahankan)');
            }
            @unlink(q28_journal());
        }
    }

    // ------------------------------------------------------------------ data uji archive

    function q28_new_id($t)
    {
        return q28_w($t, function ($c) {
            return \Modules\V5\Entities\Helper\MyHelper::generateId();
        });
    }

    function q28_name($label)
    {
        return Q28_PREFIX . $label . '-' . substr(uniqid(), -6) . mt_rand(10, 99);
    }

    /**
     * Sisipkan satu baris archive uji.
     * $a: name, type (1 folder / 2 dokumen), parent, all (is_all_location 0/1, default 1), locs (kode lokasi),
     *     perm (is_folder_permission), by (created_by, default 'QA28'), active (default 1), status (default 1),
     *     verified (0/1, hanya dokumen), doc => ['type' => int, 'salesman' => string].
     * @return string id_archive
     */
    function q28_add($t, array $a)
    {
        $id = q28_new_id($t);
        $locs = q28_locs($t);
        $now = date('Y-m-d H:i:s');

        q28_w($t, function ($c) use ($a, $id, $now, $locs) {
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
                'created_by'           => $a['by'] ?? 'QA28',
                'updated_at'           => $now,
                'updated_by'           => $a['by'] ?? 'QA28',
            ];
            if (!empty($a['verified'])) {
                $row['is_verified'] = 1;
                $row['verified_at'] = $now;
                $row['verified_by'] = 'QA28';
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
                    'id_transaction'        => 'QA28TX' . substr($id, -10),
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

    function q28_folder($t, $label, array $a = [])
    {
        return q28_add($t, $a + ['name' => q28_name($label), 'type' => 1]);
    }

    /** $tx = transaction type (default 6). */
    function q28_doc($t, $label, array $a = [], $tx = 6)
    {
        return q28_add($t, $a + ['name' => q28_name($label), 'type' => 2, 'doc' => ['type' => $tx]]);
    }

    function q28_set_archive($t, $id, array $cols)
    {
        q28_w($t, function ($c) use ($id, $cols) {
            $c->table('archives')->where('id_archive', $id)->update($cols);
        });
    }

    function q28_row($t, $id)
    {
        $row = $t->db()->table('archives')->where('id_archive', $id)->first();

        return $row ? (array) $row : null;
    }

    function q28_n($t, $id)
    {
        return $t->db()->table('archives')->where('id_archive', $id)->value('name');
    }

    /** Sisipkan baris archive_permissions (hak 0/1) satu user di satu folder. */
    function q28_perm($t, $idArchive, $idUser, $view, $update = 0, $delete = 0, $store = 0)
    {
        $now = date('Y-m-d H:i:s');
        q28_w($t, function ($c) use ($idArchive, $idUser, $view, $update, $delete, $store, $now) {
            $c->table('archive_permissions')->insert([
                'id_archive_permission' => \Modules\V5\Entities\Helper\MyHelper::generateId(),
                'id_archive'            => $idArchive,
                'id_user'               => $idUser,
                'is_view'               => $view,
                'is_update'             => $update,
                'is_delete'             => $delete,
                'is_store'              => $store,
                'created_at'            => $now,
                'created_by'            => 'QA28',
                'updated_at'            => $now,
                'updated_by'            => 'QA28',
            ]);
        });
    }

    /** Hapus semua baris archive / dokumen / lokasi / permission berawalan QA28- (idempoten). */
    function q28_purge($t)
    {
        q28_w($t, function ($c) {
            $ids = $c->table('archives')->where('name', 'like', Q28_PREFIX . '%')->pluck('id_archive')->all();
            foreach (array_chunk($ids, 500) as $chunk) {
                $c->table('archive_permissions')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_documents')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_locations')->whereIn('id_archive', $chunk)->delete();
                $c->table('archives')->whereIn('id_archive', $chunk)->delete();
            }
        });
    }

    /**
     * Fixture standar (semua berawalan QA28-, dibuang oleh q28_purge). Lokasi: SMR = Semarang (lokasi kerja user uji),
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
    function q28_tree($t)
    {
        $uid = q28_uid($t);
        $x = [];
        $x['P'] = q28_folder($t, 'P');
        $x['F'] = q28_folder($t, 'F', ['parent' => $x['P']]);
        $x['S1'] = q28_folder($t, 'S1', ['parent' => $x['F']]);
        $x['S1a'] = q28_folder($t, 'S1a', ['parent' => $x['S1']]);
        $x['S2'] = q28_folder($t, 'S2', ['parent' => $x['F']]);
        $x['SJ'] = q28_folder($t, 'SJ', ['parent' => $x['F'], 'all' => 0, 'locs' => ['JOG']]);
        $x['SX'] = q28_folder($t, 'SX', ['parent' => $x['F'], 'active' => 0]);
        $x['SP'] = q28_folder($t, 'SP', ['parent' => $x['F'], 'perm' => 1]);
        $x['SPc'] = q28_folder($t, 'SPc', ['parent' => $x['SP']]);
        $x['SV'] = q28_folder($t, 'SV', ['parent' => $x['F'], 'perm' => 1]);
        q28_perm($t, $x['SV'], $uid, 1);
        $x['OTH'] = q28_folder($t, 'OTH');
        $x['PRIV'] = q28_folder($t, 'PRIV', ['perm' => 1]);
        $x['PRc'] = q28_folder($t, 'PRc', ['parent' => $x['PRIV']]);
        $x['JOGR'] = q28_folder($t, 'JOGR', ['all' => 0, 'locs' => ['JOG']]);

        $x['dF1'] = q28_doc($t, 'dF1', ['parent' => $x['F'], 'verified' => 1], 6);
        $x['dF2'] = q28_doc($t, 'dF2', ['parent' => $x['F']], 7);
        $x['dF3'] = q28_doc($t, 'dF3', ['parent' => $x['F'], 'verified' => 1, 'status' => 2], 8);
        $x['dF4'] = q28_doc($t, 'dF4', ['parent' => $x['F'], 'status' => 3], 6);
        $x['dF5'] = q28_doc($t, 'dF5', ['parent' => $x['F']], 9);
        $x['dJF'] = q28_doc($t, 'dJF', ['parent' => $x['F'], 'all' => 0, 'locs' => ['JOG']], 7);
        $x['dNEG'] = q28_doc($t, 'dNEG', ['parent' => $x['F'], 'active' => -1, 'verified' => 1], 6);
        $x['dDEL'] = q28_doc($t, 'dDEL', ['parent' => $x['F'], 'active' => 0, 'verified' => 1], 7);
        $x['d11'] = q28_doc($t, 'd11', ['parent' => $x['S1'], 'verified' => 1], 7);
        $x['d12'] = q28_doc($t, 'd12', ['parent' => $x['S1']], 29);
        $x['dA1'] = q28_doc($t, 'dA1', ['parent' => $x['S1a']], 8);
        $x['d21'] = q28_doc($t, 'd21', ['parent' => $x['S2']], 31);
        $x['d22'] = q28_doc($t, 'd22', ['parent' => $x['S2'], 'verified' => 1], 222);
        $x['dj1'] = q28_doc($t, 'dj1', ['parent' => $x['SJ'], 'all' => 0, 'locs' => ['JOG']], 6);
        $x['dx1'] = q28_doc($t, 'dx1', ['parent' => $x['SX']], 6);
        $x['dsp1'] = q28_doc($t, 'dsp1', ['parent' => $x['SP'], 'verified' => 1], 6);
        $x['dspc1'] = q28_doc($t, 'dspc1', ['parent' => $x['SPc']], 7);
        $x['dsv1'] = q28_doc($t, 'dsv1', ['parent' => $x['SV'], 'verified' => 1], 7);
        $x['dPR'] = q28_doc($t, 'dPR', ['parent' => $x['PRIV']], 8);
        $x['dPRc'] = q28_doc($t, 'dPRc', ['parent' => $x['PRc']], 6);
        $x['dJR'] = q28_doc($t, 'dJR', ['parent' => $x['JOGR'], 'all' => 0, 'locs' => ['JOG']], 6);
        $x['dO1'] = q28_doc($t, 'dO1', ['parent' => $x['OTH']], 7);
        $x['dR'] = q28_doc($t, 'dR', ['verified' => 1], 8);
        $x['dR2'] = q28_doc($t, 'dR2', ['all' => 0, 'locs' => ['JOG']], 7);

        return $x;
    }

    // ------------------------------------------------------------------ sesi opname (API 03) & pembersihan

    function q28_track($id)
    {
        $ids = is_file(q28_sjournal()) ? (json_decode(file_get_contents(q28_sjournal()), true) ?: []) : [];
        $ids[] = $id;
        file_put_contents(q28_sjournal(), json_encode(array_values(array_unique($ids))));
    }

    function q28_tracked()
    {
        return is_file(q28_sjournal()) ? (json_decode(file_get_contents(q28_sjournal()), true) ?: []) : [];
    }

    /** Hapus data opname sesi tercatat dan kembalikan kolom verifikasi archives yang ditandai sesi itu. */
    function q28_sweep($t)
    {
        $ids = q28_tracked();
        if ($ids) {
            q28_w($t, function ($c) use ($ids) {
                foreach (array_chunk($ids, 100) as $chunk) {
                    $c->table('archives')->whereIn('id_archive_opname', $chunk)->update([
                        'is_verified' => 0, 'verified_at' => null, 'verified_by' => null, 'id_archive_opname' => null,
                    ]);
                    $c->table('archive_opname_documents')->whereIn('id_archive_opname', $chunk)->delete();
                    $c->table('archive_opname_folders')->whereIn('id_archive_opname', $chunk)->delete();
                    $c->table('archive_opnames')->whereIn('id_archive_opname', $chunk)->delete();
                }
            });
        }
        @unlink(q28_sjournal());
    }

    /** Jumlah baris opname (3 tabel) + baris archives dengan kolom verifikasi tidak default (di luar baris QA28-). */
    function q28_dirty($t)
    {
        $c = $t->db();

        return [
            'opnames'   => $c->table('archive_opnames')->count(),
            'folders'   => $c->table('archive_opname_folders')->count(),
            'documents' => $c->table('archive_opname_documents')->count(),
            'archives'  => $c->table('archives')->where('name', 'not like', Q28_PREFIX . '%')->where(function ($q) {
                $q->where('is_verified', '!=', 0)->orWhereNotNull('verified_at')->orWhereNotNull('verified_by')->orWhereNotNull('id_archive_opname');
            })->count(),
        ];
    }

    /** Awal AC: pulihkan sisa run yang mati, lalu pastikan keadaan = default (opname kosong, verifikasi default). */
    function q28_baseline($t)
    {
        q28_recover_user($t);
        q28_sweep($t);
        q28_purge($t);
        $d = q28_dirty($t);
        $t->eq(json_encode($d), json_encode(['opnames' => 0, 'folders' => 0, 'documents' => 0, 'archives' => 0]),
            'prasyarat: tabel opname kosong & kolom verifikasi archives default');
    }

    /** Akhir AC (finally): bersihkan dan buktikan tidak ada sisa. */
    function q28_cleanup($t, $label = 'data uji dibuang')
    {
        q28_sweep($t);
        q28_purge($t);
        $d = q28_dirty($t);
        $t->eq(json_encode($d), json_encode(['opnames' => 0, 'folders' => 0, 'documents' => 0, 'archives' => 0]),
            $label . ': opname kosong & verifikasi default');
        $t->eq($t->db()->table('archives')->where('name', 'like', Q28_PREFIX . '%')->count(), 0, $label . ' (archives QA28-)');
        $t->eq($t->db()->table('archive_documents')->where('transaction_no', 'like', Q28_PREFIX . '%')->count(), 0, $label . ' (archive_documents)');
        $t->eq($t->db()->table('archive_permissions')->where('created_by', 'QA28')->count(), 0, $label . ' (archive_permissions)');
    }

    // ------------------------------------------------------------------ request

    function q28_url($path, array $query = [])
    {
        return $path . ($query ? '?' . http_build_query($query) : '');
    }

    function q28_code($r)
    {
        return $r[1]['code'] ?? $r[1]['msg_code'] ?? null;
    }

    function q28_no500($t, $r, $label)
    {
        $t->true($r[0] < 500, $label . ': HTTP ' . $r[0] . ' (tidak boleh 5xx)');
    }

    function q28_deny($t, $r, $http, $code, $label)
    {
        $t->status($r, $http, $label);
        $t->eq(q28_code($r), $code, $label . ': code');
    }

    /** GET archives (pagination 1000 bila tidak diisi). */
    function q28_list($t, $s, array $q = [])
    {
        return $t->call($s, 'GET', q28_url(Q28_BASE . '/archives', $q + ['pagination' => 1000]));
    }

    /** id_archive => baris dari respons list. */
    function q28_map($r)
    {
        $map = [];
        foreach ($r[1]['result']['data'] ?? [] as $row) {
            $map[$row['id_archive']] = $row;
        }

        return $map;
    }

    function q28_verif($t, $s, $id = null)
    {
        return $t->call($s, 'GET', Q28_BASE . '/verifications' . ($id === null ? '' : '/' . $id));
    }

    function q28_search($filters)
    {
        return json_encode($filters);
    }

    function q28_store($t, $s, $folder, array $folders = [], $continue = null)
    {
        $body = ['id_archive' => $folder, 'folders' => $folders];
        if ($continue !== null) {
            $body['is_continue'] = $continue;
        }
        $r = $t->call($s, 'POST', Q28_BASE . '/opnames', $body);
        $id = $r[1]['result']['id_archive_opname'] ?? null;
        if ($id) {
            q28_track($id);
        }

        return $r;
    }

    /** Buat sesi dan kembalikan id (gagal bila tidak 200). */
    function q28_session($t, $s, $folder, array $folders = [], $continue = null)
    {
        $r = q28_store($t, $s, $folder, $folders, $continue);
        $t->status($r, 200, 'buat sesi');
        $id = $r[1]['result']['id_archive_opname'] ?? null;
        $t->true($id !== null, 'buat sesi: id dikembalikan');

        return $id;
    }

    function q28_sel(array $ids)
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

    function q28_scan($t, $s, $id, $code)
    {
        return $t->call($s, 'POST', Q28_BASE . '/opnames/' . $id . '/scan', ['code' => $code]);
    }

    function q28_confirm($t, $s, $id)
    {
        return $t->call($s, 'PUT', Q28_BASE . '/opnames/' . $id . '/confirm');
    }

    /** Sesi sukses: buat, scan kode-kode, confirm. Mengembalikan id sesi. */
    function q28_run($t, $s, $folder, array $folders, array $codes, $continue = null)
    {
        $id = q28_session($t, $s, $folder, $folders, $continue);
        foreach ($codes as $code) {
            $t->status(q28_scan($t, $s, $id, $code), 200, "scan $code");
        }
        $t->status(q28_confirm($t, $s, $id), 200, 'confirm');
        sleep(1); // sesi yang tumpang tindih di detik yang sama dianggap bentrok (ED-1026 BR-23)

        return $id;
    }

    function q28_set_owner($t, $id, $username)
    {
        q28_w($t, function ($c) use ($id, $username) {
            $c->table('archive_opnames')->where('id_archive_opname', $id)->update(['created_by' => $username]);
        });
    }

    function q28_set_opname($t, $id, array $cols)
    {
        q28_w($t, function ($c) use ($id, $cols) {
            $c->table('archive_opnames')->where('id_archive_opname', $id)->update($cols);
        });
    }

    function q28_opname($t, $id)
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
    function q28_oracle($t, array $u)
    {
        $c = $t->db();
        $locIds = null;
        if ($u['locs'] !== null) {
            $map = q28_locs($t);
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
    function q28_u($t, $mode)
    {
        $base = ['username' => (string) q28_uname($t), 'uid' => q28_uid($t)];
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

    /** opts untuk q28_with_user per mode. */
    function q28_opts($mode)
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
    function q28_vt($e)
    {
        return [(int) $e['v'], (int) $e['t']];
    }

    function q28_api_vt($o)
    {
        return $o === null ? null : [(int) $o['verified'], (int) $o['total']];
    }
}
