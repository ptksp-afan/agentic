<?php
/**
 * Helper bersama skenario ED-1026 (BUKAN skenario: nama berkas tidak cocok scenario*.php).
 * Di-require_once oleh setiap scenario*.php.
 *
 * Aturan pakai:
 * - Data uji archive berawalan "QA26-" (folder, dokumen, lokasi, archive_documents). Dibuat lewat q26_add(), dibuang
 *   lewat q26_purge(). Sesi opname dibuat lewat API nyata dan dicatat di jurnal (.sessions-journal.json);
 *   q26_cleanup() menghapus baris archive_opname* sesi itu dan mengembalikan kolom verifikasi `archives` ke default
 *   (is_verified=0, verified_at/by/id_archive_opname=NULL) untuk baris yang ditandai id_archive_opname sesi itu.
 *   Prasyarat run: tabel opname kosong dan semua kolom verifikasi `archives` default (q26_baseline()).
 * - QA_USER2 tidak bisa login di api_sidomaju: "user tanpa hak" / "user lokasi lain" = QA_USER sendiri dengan keadaan
 *   sementara (role, lokasi employee, bahasa) lewat q26_with_user(); jurnal pemulihan user di .restore-journal-q26.json.
 *   Role bawaan QA_USER = 3 (Super Admin, is_superadmin 3: TIDAK bypass lokasi/hak folder, punya Opname Document).
 *   Bypass (superadmin 1/2) = role 1 dan 2. Role 29 = hanya List Archive. Role 6 = tanpa permission Archive.
 * - Tulis lewat koneksi default non-read-only di dalam $t->probe(); baca lewat $t->db() (read-only).
 */

if (!function_exists('q26_dbname')) {

    define('Q26_PREFIX', 'QA26-');
    define('Q26_BASE', 'api/v5/document-archive');
    define('Q26_ROLE_PLAIN', 3);
    define('Q26_ROLE_LIST_ONLY', 29);
    define('Q26_ROLE_NONE', 6);
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

    /** opts: 'role' => int|int[] (id_role), 'emp' => 'all'|['SMR', ...] (kode lokasi), 'lang' => 'ID'|'EN' */
    function q26_user_apply($t, array $snap, array $opts)
    {
        $locs = q26_locs($t);
        q26_w($t, function ($c) use ($snap, $opts, $locs) {
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

    // ------------------------------------------------------------------ jurnal sesi opname & pembersihan

    function q26_track($id)
    {
        $ids = is_file(q26_sjournal()) ? (json_decode(file_get_contents(q26_sjournal()), true) ?: []) : [];
        $ids[] = $id;
        file_put_contents(q26_sjournal(), json_encode(array_values(array_unique($ids))));
    }

    function q26_tracked()
    {
        return is_file(q26_sjournal()) ? (json_decode(file_get_contents(q26_sjournal()), true) ?: []) : [];
    }

    /** Hapus data opname sesi tercatat dan kembalikan kolom verifikasi archives yang ditandai sesi itu. */
    function q26_sweep($t)
    {
        $ids = q26_tracked();
        if ($ids) {
            q26_w($t, function ($c) use ($ids) {
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
        @unlink(q26_sjournal());
    }

    /** Jumlah baris opname (3 tabel) + baris archives dengan kolom verifikasi tidak default (di luar baris QA26-). */
    function q26_dirty($t)
    {
        $c = $t->db();

        return [
            'opnames'   => $c->table('archive_opnames')->count(),
            'folders'   => $c->table('archive_opname_folders')->count(),
            'documents' => $c->table('archive_opname_documents')->count(),
            'archives'  => $c->table('archives')->where('name', 'not like', Q26_PREFIX . '%')->where(function ($q) {
                $q->where('is_verified', '!=', 0)->orWhereNotNull('verified_at')->orWhereNotNull('verified_by')->orWhereNotNull('id_archive_opname');
            })->count(),
        ];
    }

    /** Awal AC: pulihkan sisa run yang mati, lalu pastikan keadaan = default (opname kosong, verifikasi default). */
    function q26_baseline($t)
    {
        q26_recover_user($t);
        q26_sweep($t);
        q26_purge($t);
        $d = q26_dirty($t);
        $t->eq(json_encode($d), json_encode(['opnames' => 0, 'folders' => 0, 'documents' => 0, 'archives' => 0]),
            'prasyarat: tabel opname kosong & kolom verifikasi archives default');
    }

    /** Akhir AC (finally): bersihkan dan buktikan tidak ada sisa. */
    function q26_cleanup($t, $label = 'data uji dibuang')
    {
        q26_sweep($t);
        q26_purge($t);
        $d = q26_dirty($t);
        $t->eq(json_encode($d), json_encode(['opnames' => 0, 'folders' => 0, 'documents' => 0, 'archives' => 0]),
            $label . ': opname kosong & verifikasi default');
        $t->eq($t->db()->table('archives')->where('name', 'like', Q26_PREFIX . '%')->count(), 0, $label . ' (archives QA26-)');
        $t->eq($t->db()->table('archive_documents')->where('transaction_no', 'like', Q26_PREFIX . '%')->count(), 0, $label . ' (archive_documents)');
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

    /** POST opnames; sesi sukses dicatat di jurnal. */
    function q26_store($t, $s, $folder, array $folders = [], $continue = null)
    {
        $body = ['id_archive' => $folder, 'folders' => $folders];
        if ($continue !== null) {
            $body['is_continue'] = $continue;
        }
        $r = $t->call($s, 'POST', Q26_BASE . '/opnames', $body);
        $id = $r[1]['result']['id_archive_opname'] ?? null;
        if ($id) {
            q26_track($id);
        }

        return $r;
    }

    /** Buat sesi dan kembalikan id (gagal bila tidak 200). */
    function q26_session($t, $s, $folder, array $folders = [], $continue = null)
    {
        $r = q26_store($t, $s, $folder, $folders, $continue);
        $t->status($r, 200, 'buat sesi');
        $id = $r[1]['result']['id_archive_opname'] ?? null;
        $t->true($id !== null, 'buat sesi: id dikembalikan');

        return $id;
    }

    function q26_update($t, $s, $id, array $folders = [], $continue = null)
    {
        $body = ['folders' => $folders];
        if ($continue !== null) {
            $body['is_continue'] = $continue;
        }

        return $t->call($s, 'PUT', Q26_BASE . '/opnames/' . $id, $body);
    }

    function q26_show($t, $s, $id)
    {
        return $t->call($s, 'GET', Q26_BASE . '/opnames/' . $id);
    }

    function q26_docs($t, $s, $id, array $query = [])
    {
        return $t->call($s, 'GET', q26_url(Q26_BASE . '/opnames/' . $id . '/documents', $query));
    }

    function q26_scan($t, $s, $id, $code)
    {
        return $t->call($s, 'POST', Q26_BASE . '/opnames/' . $id . '/scan', ['code' => $code]);
    }

    function q26_confirm($t, $s, $id)
    {
        return $t->call($s, 'PUT', Q26_BASE . '/opnames/' . $id . '/confirm');
    }

    function q26_cancel($t, $s, $id)
    {
        return $t->call($s, 'DELETE', Q26_BASE . '/opnames/' . $id);
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

    /** Ubah pemilik sesi di DB (mensimulasikan sesi milik user lain; QA_USER2 tak bisa login di DB ini). */
    function q26_set_owner($t, $id, $username)
    {
        q26_w($t, function ($c) use ($id, $username) {
            $c->table('archive_opnames')->where('id_archive_opname', $id)->update(['created_by' => $username]);
        });
    }

    /** Ubah kolom sesi di DB (waktu, status) untuk skenario hari lain. */
    function q26_set_opname($t, $id, array $cols)
    {
        q26_w($t, function ($c) use ($id, $cols) {
            $c->table('archive_opnames')->where('id_archive_opname', $id)->update($cols);
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

    /** Cari baris result.data dengan id_archive. */
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

    // ------------------------------------------------------------------ oracle independen (SQL + PHP, tanpa kode BE)

    /**
     * Hitung dari DB (tanpa service BE): folder terlihat (aktif, lokasi, tanpa folder-permission aktif di fixture) dan
     * jumlah dokumen aktif terlihat per subtree. $locCodes null = lihat semua (superadmin 1/2).
     * @return array ['children' => [id => [name, count]], 'direct' => n (dokumen langsung $parent), 'tree' => ...]
     */
    function q26_oracle($t, $parent, $locCodes)
    {
        $c = $t->db();
        $locIds = null;
        if ($locCodes !== null) {
            $map = q26_locs($t);
            $locIds = array_map(function ($code) use ($map) {
                return $map[$code];
            }, $locCodes);
        }

        $archiveLocs = [];
        foreach ($c->table('archive_locations')->get(['id_archive', 'id_location']) as $r) {
            $archiveLocs[$r->id_archive][] = $r->id_location;
        }
        $visible = function ($row) use ($locIds, $archiveLocs) {
            if ($locIds === null || (int) $row->is_all_location === 1) {
                return true;
            }

            return count(array_intersect($archiveLocs[$row->id_archive] ?? [], $locIds)) > 0;
        };

        $children = [];
        $names = [];
        foreach ($c->table('archives')->where('type', 1)->where('is_active', '>', 0)->get(['id_archive', 'id_archive_parent', 'name', 'is_all_location']) as $f) {
            if (!$visible($f)) {
                continue;
            }
            $children[$f->id_archive_parent === null ? '*' : $f->id_archive_parent][] = $f->id_archive;
            $names[$f->id_archive] = $f->name;
        }

        $direct = [];
        $q = $c->table('archives')->where('type', 2)->where('is_active', 1)->get(['id_archive', 'id_archive_parent', 'is_all_location']);
        foreach ($q as $d) {
            if (!$visible($d)) {
                continue;
            }
            $k = $d->id_archive_parent === null ? '*' : $d->id_archive_parent;
            $direct[$k] = ($direct[$k] ?? 0) + 1;
        }

        $sub = function ($id) use (&$sub, $children, $direct) {
            $n = $direct[$id] ?? 0;
            foreach ($children[$id] ?? [] as $ch) {
                $n += $sub($ch);
            }

            return $n;
        };

        $key = $parent === null ? '*' : $parent;
        $out = ['children' => [], 'direct' => $direct[$key] ?? 0];
        $list = $children[$key] ?? [];
        usort($list, function ($a, $b) use ($names) {
            return strcasecmp($names[$a], $names[$b]) ?: strcmp($a, $b);
        });
        foreach ($list as $ch) {
            $out['children'][$ch] = [$names[$ch], $sub($ch), !empty($children[$ch])];
        }

        return $out;
    }
}
