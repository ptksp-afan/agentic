<?php
/**
 * Helper bersama skenario ED-1027 (BUKAN skenario: nama berkas tidak cocok scenario*.php).
 * Di-require_once oleh setiap scenario*.php.
 *
 * Aturan pakai:
 * - Data uji archive berawalan "QA27-" (folder, dokumen, lokasi, archive_documents, archive_permissions). Dibuat lewat
 *   q27_add(), dibuang lewat q27_purge(). Sesi opname dibuat lewat API opname ED-1026 REV (stateless: GET opnames/folders ->
 *   POST opnames/scan -> satu POST opnames = Konfirmasi; tidak ada draft/batal) dan dicatat di jurnal (.sessions-journal-q27.json);
 *   q27_cleanup() menghapus baris archive_opname* sesi itu dan mengembalikan kolom verifikasi `archives` yang ditandai sesi itu ke
 *   NILAI SEBELUMNYA (pra-citra .verif-journal-q27.json), bukan ke default. Prasyarat run: TIDAK ada (tabel opname boleh berisi data
 *   developer; q27_baseline() mencatat keadaannya, q27_cleanup() membuktikan sama persis).
 * - Permission sementara (mis. Opname Document untuk role uji) lewat q27_with_grant(): baris role_permissions dibuat dengan
 *   created_by QA27, id di jurnal (.grant-journal-q27.json), dihapus persis di finally.
 * - QA_USER2 tidak bisa login di api_sidomaju: "user lokasi terbatas / tanpa hak / superadmin" = QA_USER sendiri dengan
 *   keadaan sementara (role, lokasi employee, bahasa) lewat q27_with_user(); jurnal pemulihan di .restore-journal-q27.json.
 *   Role bawaan QA_USER = 3 (is_superadmin 3: TIDAK bypass lokasi/hak folder). Bypass (superadmin 1/2) = role 1 dan 2.
 *   Role 29 = hanya List Archive. Role 6 = tanpa permission Archive.
 * - Tulis lewat koneksi default non-read-only di dalam $t->probe(); baca lewat $t->db() (read-only).
 * - Oracle (q27_oracle) menghitung angka verifikasi dari tabel mentah, tanpa kode BE, untuk satu keadaan user.
 */

if (!function_exists('q27_dbname')) {

    define('Q27_PREFIX', 'QA27-');
    define('Q27_BASE', 'api/v5/document-archive');
    define('Q27_ROLE_LIST_ONLY', 29);
    define('Q27_ROLE_NONE', 6);
    define('Q27_RANDOM_ID', '999999999999999999999999999999');
    define('Q27_OD', 'Opname Document'); // permission data verifikasi (id 1139; dicari per nama)

    function q27_dbname($t)
    {
        return $t->dbs()[0];
    }

    /** Jalankan $fn($conn) dengan koneksi tulis ke DB QA (koneksi default BE_DIR) di dalam probe. */
    function q27_w($t, callable $fn)
    {
        $db = q27_dbname($t);

        return $t->probe(function () use ($fn, $db) {
            $conn = \Illuminate\Support\Facades\DB::connection();
            if ($conn->getDatabaseName() !== $db) {
                throw new \RuntimeException('koneksi default BE_DIR bukan ' . $db . ' (' . $conn->getDatabaseName() . ')');
            }

            return $fn($conn);
        });
    }

    function q27_rows($collection)
    {
        $out = [];
        foreach ($collection as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }

    function q27_journal()
    {
        return __DIR__ . '/.restore-journal-q27.json';
    }

    function q27_sjournal()
    {
        return __DIR__ . '/.sessions-journal-q27.json';
    }

    function q27_uid($t)
    {
        return $t->db()->table('users')->where('username', $t->conf('QA_USER'))->value('id_user');
    }

    function q27_uname($t)
    {
        return $t->db()->table('users')->where('username', $t->conf('QA_USER'))->value('username');
    }

    /** kode lokasi => id lokasi */
    function q27_locs($t)
    {
        return $t->db()->table('locations')->pluck('id_location', 'location_code')->all();
    }

    // ------------------------------------------------------------------ user QA (role / lokasi / bahasa)

    function q27_user_snapshot($t)
    {
        $c = $t->db();
        $uid = q27_uid($t);
        $emp = $c->table('employees')->where('id_user', $uid)->first();

        return [
            'uid'      => $uid,
            'roles'    => q27_rows($c->table('user_roles')->where('id_user', $uid)->orderBy('id_user_role')->get()),
            'lang'     => $c->table('users')->where('id_user', $uid)->value('language'),
            'emp_id'   => $emp->id_employee ?? null,
            'emp_all'  => $emp->is_all_location ?? null,
            'emp_locs' => $emp ? q27_rows($c->table('employee_locations')->where('id_employee', $emp->id_employee)->orderBy('id_employee_location')->get()) : [],
        ];
    }

    function q27_user_restore($t, array $snap)
    {
        q27_w($t, function ($c) use ($snap) {
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
    function q27_user_apply($t, array $snap, array $opts)
    {
        $locs = q27_locs($t);
        q27_w($t, function ($c) use ($snap, $opts, $locs) {
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

    /** Ganti bahasa QA_USER sementara (dipulihkan oleh q27_with_user). */
    function q27_user_apply_lang($t, $lang)
    {
        q27_w($t, function ($c) use ($t, $lang) {
            $c->table('users')->where('username', $t->conf('QA_USER'))->update(['language' => $lang]);
        });
    }

    function q27_recover_user($t)
    {
        if (is_file(q27_journal())) {
            $snap = json_decode(file_get_contents(q27_journal()), true);
            if (is_array($snap)) {
                q27_user_restore($t, $snap);
            }
            @unlink(q27_journal());
        }
    }

    /**
     * Jalankan $fn($set) dengan user QA di keadaan $opts. $set(array $opts) mengganti keadaan (relatif terhadap keadaan
     * asli). Keadaan asli dipulihkan dan diverifikasi persis di finally.
     */
    function q27_with_user($t, array $opts, callable $fn)
    {
        q27_recover_user($t);
        $snap = q27_user_snapshot($t);
        file_put_contents(q27_journal(), json_encode($snap));

        $set = function (array $o) use ($t, $snap) {
            q27_user_restore($t, $snap);
            q27_user_apply($t, $snap, $o);
        };

        try {
            $set($opts);

            return $fn($set);
        } finally {
            q27_user_restore($t, $snap);
            $after = q27_user_snapshot($t);
            if (json_encode($after) !== json_encode($snap)) {
                $t->fail('pemulihan user QA tidak persis sama dengan sebelumnya (jurnal dipertahankan)');
            }
            @unlink(q27_journal());
        }
    }

    // ------------------------------------------------------------------ data uji archive

    function q27_new_id($t)
    {
        return q27_w($t, function ($c) {
            return \Modules\V5\Entities\Helper\MyHelper::generateId();
        });
    }

    function q27_name($label)
    {
        return Q27_PREFIX . $label . '-' . substr(uniqid(), -6) . mt_rand(10, 99);
    }

    /**
     * Sisipkan satu baris archive uji.
     * $a: name, type (1 folder / 2 dokumen), parent, all (is_all_location 0/1, default 1), locs (kode lokasi),
     *     perm (is_folder_permission), by (created_by, default 'QA27'), active (default 1), status (default 1),
     *     verified (0/1, hanya dokumen), doc => ['type' => int, 'salesman' => string].
     * @return string id_archive
     */
    function q27_add($t, array $a)
    {
        $id = q27_new_id($t);
        $locs = q27_locs($t);
        $now = date('Y-m-d H:i:s');

        q27_w($t, function ($c) use ($a, $id, $now, $locs) {
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
                'created_by'           => $a['by'] ?? 'QA27',
                'updated_at'           => $now,
                'updated_by'           => $a['by'] ?? 'QA27',
            ];
            if (!empty($a['verified'])) {
                $row['is_verified'] = 1;
                $row['verified_at'] = $now;
                $row['verified_by'] = 'QA27';
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
                    'id_transaction'        => 'QA27TX' . substr($id, -10),
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

    function q27_folder($t, $label, array $a = [])
    {
        return q27_add($t, $a + ['name' => q27_name($label), 'type' => 1]);
    }

    /** $tx = transaction type (default 6). */
    function q27_doc($t, $label, array $a = [], $tx = 6)
    {
        return q27_add($t, $a + ['name' => q27_name($label), 'type' => 2, 'doc' => ['type' => $tx]]);
    }

    function q27_set_archive($t, $id, array $cols)
    {
        q27_w($t, function ($c) use ($id, $cols) {
            $c->table('archives')->where('id_archive', $id)->update($cols);
        });
    }

    function q27_row($t, $id)
    {
        $row = $t->db()->table('archives')->where('id_archive', $id)->first();

        return $row ? (array) $row : null;
    }

    function q27_n($t, $id)
    {
        return $t->db()->table('archives')->where('id_archive', $id)->value('name');
    }

    /** Sisipkan baris archive_permissions (hak 0/1) satu user di satu folder. */
    function q27_perm($t, $idArchive, $idUser, $view, $update = 0, $delete = 0, $store = 0)
    {
        $now = date('Y-m-d H:i:s');
        q27_w($t, function ($c) use ($idArchive, $idUser, $view, $update, $delete, $store, $now) {
            $c->table('archive_permissions')->insert([
                'id_archive_permission' => \Modules\V5\Entities\Helper\MyHelper::generateId(),
                'id_archive'            => $idArchive,
                'id_user'               => $idUser,
                'is_view'               => $view,
                'is_update'             => $update,
                'is_delete'             => $delete,
                'is_store'              => $store,
                'created_at'            => $now,
                'created_by'            => 'QA27',
                'updated_at'            => $now,
                'updated_by'            => 'QA27',
            ]);
        });
    }

    /** Hapus semua baris archive / dokumen / lokasi / permission berawalan QA27- (idempoten). */
    function q27_purge($t)
    {
        q27_w($t, function ($c) {
            $ids = $c->table('archives')->where('name', 'like', Q27_PREFIX . '%')->pluck('id_archive')->all();
            foreach (array_chunk($ids, 500) as $chunk) {
                $c->table('archive_permissions')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_documents')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_locations')->whereIn('id_archive', $chunk)->delete();
                $c->table('archives')->whereIn('id_archive', $chunk)->delete();
            }
        });
    }

    /**
     * Fixture standar (semua berawalan QA27-, dibuang oleh q27_purge). Lokasi: SMR = Semarang (lokasi kerja user uji),
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
    function q27_tree($t)
    {
        $uid = q27_uid($t);
        $x = [];
        $x['P'] = q27_folder($t, 'P');
        $x['F'] = q27_folder($t, 'F', ['parent' => $x['P']]);
        $x['S1'] = q27_folder($t, 'S1', ['parent' => $x['F']]);
        $x['S1a'] = q27_folder($t, 'S1a', ['parent' => $x['S1']]);
        $x['S2'] = q27_folder($t, 'S2', ['parent' => $x['F']]);
        $x['SJ'] = q27_folder($t, 'SJ', ['parent' => $x['F'], 'all' => 0, 'locs' => ['JOG']]);
        $x['SX'] = q27_folder($t, 'SX', ['parent' => $x['F'], 'active' => 0]);
        $x['SP'] = q27_folder($t, 'SP', ['parent' => $x['F'], 'perm' => 1]);
        $x['SPc'] = q27_folder($t, 'SPc', ['parent' => $x['SP']]);
        $x['SV'] = q27_folder($t, 'SV', ['parent' => $x['F'], 'perm' => 1]);
        q27_perm($t, $x['SV'], $uid, 1);
        $x['OTH'] = q27_folder($t, 'OTH');
        $x['PRIV'] = q27_folder($t, 'PRIV', ['perm' => 1]);
        $x['PRc'] = q27_folder($t, 'PRc', ['parent' => $x['PRIV']]);
        $x['JOGR'] = q27_folder($t, 'JOGR', ['all' => 0, 'locs' => ['JOG']]);

        $x['dF1'] = q27_doc($t, 'dF1', ['parent' => $x['F'], 'verified' => 1], 6);
        $x['dF2'] = q27_doc($t, 'dF2', ['parent' => $x['F']], 7);
        $x['dF3'] = q27_doc($t, 'dF3', ['parent' => $x['F'], 'verified' => 1, 'status' => 2], 8);
        $x['dF4'] = q27_doc($t, 'dF4', ['parent' => $x['F'], 'status' => 3], 6);
        $x['dF5'] = q27_doc($t, 'dF5', ['parent' => $x['F']], 9);
        $x['dJF'] = q27_doc($t, 'dJF', ['parent' => $x['F'], 'all' => 0, 'locs' => ['JOG']], 7);
        $x['dNEG'] = q27_doc($t, 'dNEG', ['parent' => $x['F'], 'active' => -1, 'verified' => 1], 6);
        $x['dDEL'] = q27_doc($t, 'dDEL', ['parent' => $x['F'], 'active' => 0, 'verified' => 1], 7);
        $x['d11'] = q27_doc($t, 'd11', ['parent' => $x['S1'], 'verified' => 1], 7);
        $x['d12'] = q27_doc($t, 'd12', ['parent' => $x['S1']], 29);
        $x['dA1'] = q27_doc($t, 'dA1', ['parent' => $x['S1a']], 8);
        $x['d21'] = q27_doc($t, 'd21', ['parent' => $x['S2']], 31);
        $x['d22'] = q27_doc($t, 'd22', ['parent' => $x['S2'], 'verified' => 1], 222);
        $x['dj1'] = q27_doc($t, 'dj1', ['parent' => $x['SJ'], 'all' => 0, 'locs' => ['JOG']], 6);
        $x['dx1'] = q27_doc($t, 'dx1', ['parent' => $x['SX']], 6);
        $x['dsp1'] = q27_doc($t, 'dsp1', ['parent' => $x['SP'], 'verified' => 1], 6);
        $x['dspc1'] = q27_doc($t, 'dspc1', ['parent' => $x['SPc']], 7);
        $x['dsv1'] = q27_doc($t, 'dsv1', ['parent' => $x['SV'], 'verified' => 1], 7);
        $x['dPR'] = q27_doc($t, 'dPR', ['parent' => $x['PRIV']], 8);
        $x['dPRc'] = q27_doc($t, 'dPRc', ['parent' => $x['PRc']], 6);
        $x['dJR'] = q27_doc($t, 'dJR', ['parent' => $x['JOGR'], 'all' => 0, 'locs' => ['JOG']], 6);
        $x['dO1'] = q27_doc($t, 'dO1', ['parent' => $x['OTH']], 7);
        $x['dR'] = q27_doc($t, 'dR', ['verified' => 1], 8);
        $x['dR2'] = q27_doc($t, 'dR2', ['all' => 0, 'locs' => ['JOG']], 7);

        return $x;
    }

    // ------------------------------------------------------------------ jurnal sesi, pra-citra verifikasi, keadaan developer
    // (rev 2026-10-09, gate1 §5 / ba2-notes "Pengaman data uji"): tabel opname TIDAK harus kosong dan kolom verifikasi `archives`
    // TIDAK harus default. Sebelum AC jalan dicatat nilai asli kolom verifikasi semua baris non-QA27 yang tidak default (pra-citra);
    // sesudahnya baris yang ditandai sesi QA dikembalikan ke nilai itu (bukan ke default) dan keadaan developer dibuktikan sama persis.

    function q27_track($id)
    {
        if (!$id) {
            return;
        }
        $ids = is_file(q27_sjournal()) ? (json_decode(file_get_contents(q27_sjournal()), true) ?: []) : [];
        $ids[] = $id;
        file_put_contents(q27_sjournal(), json_encode(array_values(array_unique($ids))));
    }

    function q27_tracked()
    {
        return is_file(q27_sjournal()) ? (json_decode(file_get_contents(q27_sjournal()), true) ?: []) : [];
    }

    function q27_vjournal()
    {
        return __DIR__ . '/.verif-journal-q27.json';
    }

    /** Baris archives non-QA27 dengan kolom verifikasi tidak default (builder baca saja). */
    function q27_nondefault($t)
    {
        return $t->db()->table('archives')->where('name', 'not like', Q27_PREFIX . '%')->where(function ($q) {
            $q->whereNull('is_verified')->orWhere('is_verified', '!=', 0)->orWhereNotNull('verified_at')
                ->orWhereNotNull('verified_by')->orWhereNotNull('id_archive_opname');
        });
    }

    /** Tulis pra-citra: nilai kolom verifikasi SEMUA baris non-QA27 yang tidak default (data developer). Mengembalikan jumlah baris. */
    function q27_write_vjournal($t)
    {
        $rows = [];
        foreach (q27_nondefault($t)->get(['id_archive', 'is_verified', 'verified_at', 'verified_by', 'id_archive_opname']) as $r) {
            $rows[(string) $r->id_archive] = [$r->is_verified === null ? null : (int) $r->is_verified, $r->verified_at, $r->verified_by, $r->id_archive_opname];
        }
        file_put_contents(q27_vjournal(), json_encode(['taken_at' => date('c'), 'rows' => $rows]));

        return count($rows);
    }

    function q27_read_vjournal()
    {
        return is_file(q27_vjournal()) ? (json_decode(file_get_contents(q27_vjournal()), true) ?: []) : [];
    }

    /**
     * Hapus data opname sesi QA tercatat dan kembalikan kolom verifikasi `archives` yang ditandai sesi itu ke NILAI SEBELUMNYA
     * (pra-citra; baris tanpa pra-citra = default). Hanya baris yang ditandai `id_archive_opname` sesi QA yang disentuh; sesi dan
     * baris developer tidak.
     */
    function q27_sweep($t)
    {
        $ids = q27_tracked();
        $pre = q27_read_vjournal()['rows'] ?? [];
        if ($ids) {
            q27_w($t, function ($c) use ($ids, $pre) {
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
        @unlink(q27_sjournal());
    }

    function q27_cols($t, $table)
    {
        $out = [];
        foreach ($t->db()->select("SHOW COLUMNS FROM `$table`") as $r) {
            $out[] = $r->Field;
        }

        return $out;
    }

    /** [jumlah baris, jumlah CRC32 per baris] satu tabel (opsional WHERE). */
    function q27_sum($t, $table, array $cols, $where = '', array $bind = [])
    {
        $parts = array_map(function ($c) {
            return "IFNULL(CAST(`$c` AS CHAR),'~')";
        }, $cols);
        $r = $t->db()->selectOne("SELECT COUNT(*) n, IFNULL(SUM(CRC32(CONCAT_WS('|'," . implode(',', $parts) . '))),0) h FROM `' . $table . '` ' . $where, $bind);

        return [(int) $r->n, (string) $r->h];
    }

    /** Keadaan developer: tiga tabel opname (semua baris) + kolom verifikasi archives non-QA27. */
    function q27_devstate($t)
    {
        $out = [];
        foreach (['archive_opnames' => 'opnames', 'archive_opname_folders' => 'folders', 'archive_opname_documents' => 'documents'] as $table => $key) {
            $out[$key] = q27_sum($t, $table, q27_cols($t, $table));
        }
        $out['archives'] = q27_sum($t, 'archives', ['id_archive', 'is_verified', 'verified_at', 'verified_by', 'id_archive_opname'], 'WHERE name NOT LIKE ?', [Q27_PREFIX . '%']);

        return $out;
    }

    /**
     * Awal AC: pulihkan sisa run yang mati (user, grant, sesi), buang data QA27-, catat pra-citra baru dan keadaan developer.
     * TIDAK menuntut tabel opname kosong atau archives default.
     */
    function q27_baseline($t)
    {
        q27_recover_user($t);
        q27_grant_recover($t);
        q27_sweep($t);
        @unlink(q27_vjournal());
        q27_purge($t);
        $n = q27_write_vjournal($t);
        // dasar waktu "di atas semua sesi yang sudah ada" (data developer): sesi QA yang confirmed_at-nya diatur lewat q27_ts() selalu
        // lebih baru daripada sesi developer, jadi last_sessions (maks 3 terbaru) memuat sesi QA lebih dulu
        $newest = $t->db()->table('archive_opnames')->max('confirmed_at');
        $GLOBALS['q27_ts_base'] = max(time(), $newest ? strtotime($newest) : 0) + 7 * 86400;
        $GLOBALS['q27_base'] = q27_devstate($t);
        $GLOBALS['q27_last'] = 0;
        $b = $GLOBALS['q27_base'];
        $t->note(sprintf('data developer di DB uji dijaga: %d sesi, %d baris folder, %d baris dokumen opname; %d baris archives verifikasi tidak default', $b['opnames'][0], $b['folders'][0], $b['documents'][0], $n));

        return $b;
    }

    /** confirmed_at untuk sesi QA yang harus lebih baru dari semua sesi developer: dasar (>= 7 hari di depan sesi terbaru) + $modify. */
    function q27_ts($modify)
    {
        return date('Y-m-d H:i:s', strtotime($modify, $GLOBALS['q27_ts_base'] ?? (time() + 7 * 86400)));
    }

    /** Akhir AC (finally): pulihkan nilai sebelumnya, buang data QA, buktikan data developer sama persis. */
    function q27_cleanup($t, $label = 'data uji dibuang')
    {
        $tracked = q27_tracked();
        q27_sweep($t);
        q27_purge($t);
        $c = $t->db();
        $gone = $tracked ? $c->table('archive_opnames')->whereIn('id_archive_opname', $tracked)->count() : 0;
        $now = q27_devstate($t);
        $base = $GLOBALS['q27_base'] ?? null;
        $t->eq($gone, 0, $label . ' (sesi opname QA)');
        $t->eq($c->table('archives')->where('name', 'like', Q27_PREFIX . '%')->count(), 0, $label . ' (archives QA27-)');
        $t->eq($c->table('archive_documents')->where('transaction_no', 'like', Q27_PREFIX . '%')->count(), 0, $label . ' (archive_documents)');
        $t->eq($c->table('archive_permissions')->where('created_by', 'QA27')->count(), 0, $label . ' (archive_permissions)');
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
        @unlink(q27_vjournal());
    }

    // ------------------------------------------------------------------ grant permission sementara (role_permissions), berjurnal

    function q27_gjournal()
    {
        return __DIR__ . '/.grant-journal-q27.json';
    }

    /** Hapus baris role_permissions yang dibuat QA (id tercatat di jurnal). */
    function q27_grant_recover($t)
    {
        if (is_file(q27_gjournal())) {
            $ids = json_decode(file_get_contents(q27_gjournal()), true);
            if (is_array($ids) && $ids) {
                q27_w($t, function ($c) use ($ids) {
                    $c->table('role_permissions')->whereIn('id_role_permission', $ids)->where('created_by', 'QA27')->delete();
                });
            }
            @unlink(q27_gjournal());
        }
    }

    function q27_has_perm($t, $role, $name)
    {
        return $t->db()->table('role_permissions as rp')->join('permissions as p', 'p.id_permission', '=', 'rp.id_permission')
            ->where('rp.id_role', $role)->where('p.permission_name', $name)->exists();
    }

    /** Beri role(s) permission $name (per NAMA); mengembalikan [id baris dibuat]. Dicatat di jurnal. Role yang sudah memegangnya tidak disentuh. */
    function q27_grant($t, $roles, $name)
    {
        $roles = (array) $roles;
        $pid = $t->db()->table('permissions')->where('permission_name', $name)->value('id_permission');
        $t->true($pid !== null, "permission '$name' ada (dicari per nama, id $pid)");
        $made = [];
        q27_w($t, function ($c) use ($roles, $name, $pid, &$made) {
            foreach ($roles as $role) {
                $has = $c->table('role_permissions')->where('id_role', $role)->where('id_permission', $pid)->exists();
                if (!$has) {
                    $made[] = $c->table('role_permissions')->insertGetId([
                        'id_role' => $role, 'id_permission' => $pid, 'permission_name' => $name,
                        'created_at' => date('Y-m-d H:i:s'), 'created_by' => 'QA27',
                    ]);
                }
            }
        });
        $old = is_file(q27_gjournal()) ? (json_decode(file_get_contents(q27_gjournal()), true) ?: []) : [];
        file_put_contents(q27_gjournal(), json_encode(array_merge($old, $made)));

        return $made;
    }

    /** Ringkasan (jumlah+CRC) role_permissions: bukti pemulihan persis. */
    function q27_rp_sum($t)
    {
        return q27_sum($t, 'role_permissions', q27_cols($t, 'role_permissions'));
    }

    /**
     * Jalankan $fn dengan role(s) $roles memegang permission $name. Baris role_permissions yang dibuat dicatat di jurnal dan dihapus
     * persis di finally; bukti: jumlah+CRC role_permissions sama dengan sebelumnya.
     */
    function q27_with_grant($t, $roles, $name, callable $fn)
    {
        q27_grant_recover($t);
        $before = q27_rp_sum($t);
        try {
            q27_grant($t, $roles, $name);

            return $fn();
        } finally {
            q27_grant_recover($t);
            $after = q27_rp_sum($t);
            if (json_encode($after) !== json_encode($before)) {
                $t->fail("role_permissions tidak sama persis sesudah grant '$name' dicabut: " . json_encode($before) . ' -> ' . json_encode($after));
            }
        }
    }

    // ------------------------------------------------------------------ request

    function q27_url($path, array $query = [])
    {
        return $path . ($query ? '?' . http_build_query($query) : '');
    }

    function q27_code($r)
    {
        return $r[1]['code'] ?? $r[1]['msg_code'] ?? null;
    }

    function q27_no500($t, $r, $label)
    {
        $t->true($r[0] < 500, $label . ': HTTP ' . $r[0] . ' (tidak boleh 5xx)');
    }

    function q27_deny($t, $r, $http, $code, $label)
    {
        $t->status($r, $http, $label);
        $t->eq(q27_code($r), $code, $label . ': code');
    }

    /** GET archives (pagination 1000 bila tidak diisi). */
    function q27_list($t, $s, array $q = [])
    {
        return $t->call($s, 'GET', q27_url(Q27_BASE . '/archives', $q + ['pagination' => 1000]));
    }

    /** id_archive => baris dari respons list. */
    function q27_map($r)
    {
        $map = [];
        foreach ($r[1]['result']['data'] ?? [] as $row) {
            $map[$row['id_archive']] = $row;
        }

        return $map;
    }

    function q27_verif($t, $s, $id = null)
    {
        return $t->call($s, 'GET', Q27_BASE . '/verifications' . ($id === null ? '' : '/' . $id));
    }

    function q27_search($filters)
    {
        return json_encode($filters);
    }

    // ------------------------------------------------------------------ sesi opname 03 (rev: tanpa draft; POST opnames = Konfirmasi)

    /** Handle satu sesi stateless: pilihan Step 1, selected_at, daftar scan; id terisi sesudah Konfirmasi sukses. */
    class Q27Session
    {
        public $sel;
        public $selectedAt;
        public $scans = [];
        public $id;
        public $confirm;
    }

    /** Body pilihan Step 1: folder opname (null = root), subfolder terpilih (q27_sel), is_continue folder opname. */
    function q27_selection($folder, array $folders = [], $continue = null)
    {
        $b = ['id_archive' => $folder, 'folders' => $folders];
        if ($continue !== null) {
            $b['is_continue'] = $continue;
        }

        return $b;
    }

    /** Tunggu sampai detik server berikutnya sesudah Konfirmasi terakhir: selected_at baru tidak bentrok dengannya (ARCHIVE414). */
    function q27_wait()
    {
        while (time() <= (int) ($GLOBALS['q27_last'] ?? 0)) {
            usleep(100000);
        }
    }

    /** Muat Step 1 (GET opnames/folders) -> handle sesi dengan selected_at server. */
    function q27_session($t, $s, $folder, array $folders = [], $continue = null)
    {
        q27_wait();
        $r = $t->call($s, 'GET', q27_url(Q27_BASE . '/opnames/folders', $folder === null ? [] : ['id_archive' => $folder]));
        $t->status($r, 200, 'muat Step 1 (selected_at)');
        $at = $r[1]['result']['selected_at'] ?? null;
        $t->true(is_string($at) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $at) === 1, 'selected_at = Y-m-d H:i:s');
        $h = new Q27Session();
        $h->sel = q27_selection($folder, $folders, $continue);
        $h->selectedAt = $at;

        return $h;
    }

    /** POST opnames/scan (klasifikasi satu kode, tanpa simpan); scan sukses dicatat di handle. Mengembalikan respons. */
    function q27_scan($t, $s, Q27Session $h, $code)
    {
        $r = $t->call($s, 'POST', Q27_BASE . '/opnames/scan', $h->sel + ['code' => $code]);
        if ($r[0] === 200 && isset($r[1]['result']['row']['scanned_at'])) {
            $h->scans[] = ['code' => $code, 'scanned_at' => $r[1]['result']['row']['scanned_at']];
        }

        return $r;
    }

    /** POST opnames = Konfirmasi Opname; sesi sukses dicatat di jurnal. Mengembalikan respons. */
    function q27_confirm($t, $s, Q27Session $h)
    {
        $r = $t->call($s, 'POST', Q27_BASE . '/opnames', $h->sel + ['scans' => $h->scans, 'selected_at' => $h->selectedAt]);
        $GLOBALS['q27_last'] = time();
        $h->confirm = $r;
        $id = $r[1]['result']['id_archive_opname'] ?? null;
        if ($r[0] === 200 && $id) {
            $h->id = $id;
            q27_track($id);
        }

        return $r;
    }

    function q27_sel(array $ids)
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

    /** Sesi sukses seperti FE: muat Step 1, scan kode-kode, Konfirmasi (200 dituntut). Mengembalikan id sesi (string). */
    function q27_run($t, $s, $folder, array $folders, array $codes, $continue = null)
    {
        $h = q27_session($t, $s, $folder, $folders, $continue);
        foreach ($codes as $code) {
            $t->status(q27_scan($t, $s, $h, $code), 200, "scan $code");
        }
        $t->status(q27_confirm($t, $s, $h), 200, 'confirm');
        $t->true($h->id !== null, 'confirm: id sesi dikembalikan');

        return $h->id;
    }

    function q27_set_owner($t, $id, $username)
    {
        q27_w($t, function ($c) use ($id, $username) {
            $c->table('archive_opnames')->where('id_archive_opname', $id)->update(['created_by' => $username]);
        });
    }

    function q27_set_opname($t, $id, array $cols)
    {
        q27_w($t, function ($c) use ($id, $cols) {
            $c->table('archive_opnames')->where('id_archive_opname', $id)->update($cols);
        });
    }

    function q27_opname($t, $id)
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
    function q27_oracle($t, array $u)
    {
        $c = $t->db();
        $locIds = null;
        if ($u['locs'] !== null) {
            $map = q27_locs($t);
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
    function q27_u($t, $mode)
    {
        $base = ['username' => (string) q27_uname($t), 'uid' => q27_uid($t)];
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

    /** opts untuk q27_with_user per mode. */
    function q27_opts($mode)
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
    function q27_vt($e)
    {
        return [(int) $e['v'], (int) $e['t']];
    }

    function q27_api_vt($o)
    {
        return $o === null ? null : [(int) $o['verified'], (int) $o['total']];
    }
}
