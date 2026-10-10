<?php
/**
 * Helper bersama skenario ED-1069 (BUKAN skenario: nama berkas tidak cocok scenario*.php).
 * Di-require_once oleh setiap scenario*.php. Prefix fungsi q69_, data uji berawalan "QA69-".
 *
 * Aturan pakai:
 * - Tulis lewat koneksi default non-read-only di dalam $t->probe(); baca lewat $t->db() (read-only).
 * - QA_USER = role 3 (Super Admin, is_superadmin 3: TIDAK bypass hak folder). "Tanpa hak" = keadaan sementara:
 *   baris archive_permissions / is_folder_permission pada folder uji, atau role fixture QA69-ROLE-* yang menggantikan
 *   role QA_USER sebentar (q69_with_user). Dipulihkan persis di finally; jurnal pemulihan user di
 *   .restore-journal-q69.json (run yang mati di tengah dipulihkan dari jurnal itu oleh run berikutnya).
 * - Hanya baris yang dibuat skrip ini (nama berawalan QA69-) yang dihapus; tabel archive_opname* tidak pernah disentuh.
 * - Asersi lunak (q69_soft): semua kegagalan sebuah skenario dikumpulkan lalu dilaporkan sekaligus.
 */

if (!function_exists('q69_dbname')) {

    define('Q69_PREFIX', 'QA69-');
    define('Q69_ROLE_PREFIX', 'QA69-ROLE-');
    define('Q69_RANDOM_ID', '999999999999999999999999999999');
    define('Q69_API', 'api/v5/document-archive/');

    function q69_dbname($t)
    {
        return $t->dbs()[0];
    }

    /** Jalankan $fn($conn) dengan koneksi tulis ke DB QA (di dalam probe). */
    function q69_w($t, callable $fn)
    {
        $db = q69_dbname($t);

        return $t->probe(function () use ($fn, $db) {
            return $fn(\Illuminate\Support\Facades\DB::connection($db));
        });
    }

    function q69_rows($collection)
    {
        $out = [];
        foreach ($collection as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }

    function q69_journal()
    {
        return __DIR__ . '/.restore-journal-q69.json';
    }

    function q69_uid($t)
    {
        return $t->db()->table('users')->where('username', $t->conf('QA_USER'))->value('id_user');
    }

    // ------------------------------------------------------------------ asersi lunak

    /** Pengumpul kegagalan: satu skenario melaporkan semua asersi yang gagal sekaligus. */
    class Q69Soft
    {
        private $t;
        private $bad = [];
        private $n = 0;

        public function __construct($t)
        {
            $this->t = $t;
        }

        private function run(callable $fn, $label)
        {
            $this->n++;
            try {
                $fn();
            } catch (\QaHttp\AssertionFailed $e) {
                $this->bad[] = $e->getMessage();
            }
        }

        public function eq($actual, $expected, $label)
        {
            $this->run(function () use ($actual, $expected, $label) {
                $this->t->eq($actual, $expected, $label);
            }, $label);
        }

        public function ok($cond, $label)
        {
            $this->run(function () use ($cond, $label) {
                $this->t->true($cond === true, $label);
            }, $label);
        }

        /** HTTP status + kode pesan (null = tidak diperiksa) */
        public function resp($r, $status, $code, $label)
        {
            $this->run(function () use ($r, $status, $label) {
                $this->t->status($r, $status, $label);
            }, $label);
            if ($code !== null) {
                $this->run(function () use ($r, $code, $label) {
                    $this->t->code($r, $code, $label);
                }, $label);
            }
        }

        /** Tidak boleh 5xx. */
        public function no5xx($r, $label)
        {
            $this->run(function () use ($r, $label) {
                $this->t->true($r[0] < 500, $label . ': HTTP ' . $r[0] . ' (tidak boleh 5xx)');
            }, $label);
        }

        public function done()
        {
            $this->t->note($this->n . ' asersi lunak');
            $this->t->eq($this->bad, [], 'tidak ada asersi yang gagal');
        }
    }

    function q69_soft($t)
    {
        return new Q69Soft($t);
    }

    // ------------------------------------------------------------------ user QA (role / bahasa)

    function q69_user_snapshot($t)
    {
        $c = $t->db();
        $uid = q69_uid($t);

        return [
            'uid'   => $uid,
            'roles' => q69_rows($c->table('user_roles')->where('id_user', $uid)->orderBy('id_user_role')->get()),
            'lang'  => $c->table('users')->where('id_user', $uid)->value('language'),
        ];
    }

    function q69_user_restore($t, array $snap)
    {
        q69_w($t, function ($c) use ($snap) {
            $uid = $snap['uid'];
            $c->table('user_roles')->where('id_user', $uid)->delete();
            if ($snap['roles']) {
                $c->table('user_roles')->insert($snap['roles']);
            }
            $c->table('users')->where('id_user', $uid)->update(['language' => $snap['lang']]);
        });
    }

    /** opts: 'role' => int|int[] (id_role), 'lang' => 'ID'|'EN' */
    function q69_user_apply($t, array $snap, array $opts)
    {
        q69_w($t, function ($c) use ($snap, $opts) {
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

    function q69_recover($t)
    {
        if (is_file(q69_journal())) {
            $snap = json_decode(file_get_contents(q69_journal()), true);
            if (is_array($snap)) {
                q69_user_restore($t, $snap);
            }
            @unlink(q69_journal());
        }
    }

    /**
     * Jalankan $fn($set) dengan user QA di keadaan $opts. $set(array $opts) mengganti keadaan (relatif terhadap keadaan
     * asli). Keadaan asli dipulihkan dan diverifikasi persis di finally.
     */
    function q69_with_user($t, array $opts, callable $fn)
    {
        q69_recover($t);
        $snap = q69_user_snapshot($t);
        file_put_contents(q69_journal(), json_encode($snap));

        $set = function (array $o) use ($t, $snap) {
            q69_user_restore($t, $snap);
            q69_user_apply($t, $snap, $o);
        };

        try {
            $set($opts);

            return $fn($set);
        } finally {
            q69_user_restore($t, $snap);
            $after = q69_user_snapshot($t);
            if (json_encode($after) !== json_encode($snap)) {
                $t->fail('pemulihan user QA tidak persis sama dengan sebelumnya (jurnal dipertahankan)');
            }
            @unlink(q69_journal());
        }
    }

    /** Bahasa user QA (EN/ID) selama $fn, dipulihkan sesudahnya. */
    function q69_with_lang($t, $lang, callable $fn)
    {
        return q69_with_user($t, ['lang' => $lang], $fn);
    }

    // ------------------------------------------------------------------ role fixture

    /**
     * Role fixture berawalan QA69-ROLE- dengan permission bernama $names, bukan superadmin (is_superadmin 4), aktif.
     * Dibuang oleh q69_role_purge(). Tidak menyentuh role bawaan.
     * @return int id_role
     */
    function q69_role($t, $label, array $names)
    {
        return q69_w($t, function ($c) use ($label, $names) {
            $now = date('Y-m-d H:i:s');
            $id = $c->table('roles')->insertGetId([
                'role_name' => Q69_ROLE_PREFIX . $label, 'description' => 'QA ED-1069 fixture', 'is_superadmin' => 4,
                'is_active' => 1, 'is_used' => 1, 'created_by' => 'QA69', 'created_at' => $now, 'updated_by' => 'QA69', 'updated_at' => $now,
            ]);
            foreach ($names as $name) {
                $perm = $c->table('permissions')->where('permission_name', $name)->first();
                if (!$perm) {
                    throw new \RuntimeException("prasyarat: permission '$name' tidak ada di DB uji");
                }
                $c->table('role_permissions')->insert([
                    'id_role' => $id, 'id_permission' => $perm->id_permission, 'permission_name' => $perm->permission_name,
                    'created_at' => $now, 'updated_at' => $now, 'created_by' => 'QA69', 'updated_by' => 'QA69',
                ]);
            }

            return (int) $id;
        });
    }

    /** Hapus semua role fixture QA69-ROLE- (role_permissions, user_roles yang menunjuknya, lalu role). Idempoten. */
    function q69_role_purge($t)
    {
        q69_w($t, function ($c) {
            $ids = $c->table('roles')->where('role_name', 'like', Q69_ROLE_PREFIX . '%')->pluck('id_role')->all();
            if ($ids) {
                $c->table('user_roles')->whereIn('id_role', $ids)->delete();
                $c->table('role_permissions')->whereIn('id_role', $ids)->delete();
                $c->table('roles')->whereIn('id_role', $ids)->delete();
            }
        });
    }

    // role uji: semua hak modul Archive kecuali yang disebut
    function q69_perms_all()
    {
        return ['List Archive', 'Update Folder', 'Delete Folder', 'Add Folder', 'Add Document', 'Move Archive', 'View Folder', 'Folder Permissions'];
    }

    /** Tanpa Update Folder (tetap Folder Permissions agar PUT archives/{id} lolos middleware). */
    function q69_perms_no_update()
    {
        return ['List Archive', 'Delete Folder', 'Add Folder', 'Add Document', 'Move Archive', 'View Folder', 'Folder Permissions'];
    }

    // ------------------------------------------------------------------ data uji archive

    function q69_new_id($t)
    {
        return q69_w($t, function ($c) {
            return \Modules\V5\Entities\Helper\MyHelper::generateId();
        });
    }

    /** Nama unik berawalan QA69- (maks 30 karakter: dipakai juga sebagai archive_documents.transaction_no). */
    function q69_name($label)
    {
        return Q69_PREFIX . $label . '-' . substr(uniqid(), -6) . mt_rand(10, 99);
    }

    /**
     * Sisipkan satu baris archive uji.
     * $a: name, type (1 folder / 2 dokumen), parent, perm (is_folder_permission), by (created_by, default 'QA69'),
     *     active (default 1), all_location (default 1), locations => [id_location], history (array),
     *     doc => ['type' => int, 'tx' => id_transaction, 'no' => transaction_no] untuk dokumen.
     * @return string id_archive
     */
    function q69_add($t, array $a)
    {
        $id = q69_new_id($t);
        $now = date('Y-m-d H:i:s');

        q69_w($t, function ($c) use ($a, $id, $now) {
            $c->table('archives')->insert([
                'id_archive'           => $id,
                'id_archive_parent'    => $a['parent'] ?? null,
                'name'                 => $a['name'],
                'type'                 => $a['type'] ?? 1,
                'status'               => 1,
                'is_all_location'      => $a['all_location'] ?? 1,
                'is_folder_permission' => $a['perm'] ?? 0,
                'is_active'            => $a['active'] ?? 1,
                'history'              => isset($a['history']) ? json_encode($a['history']) : null,
                'created_at'           => $now,
                'created_by'           => $a['by'] ?? 'QA69',
                'updated_at'           => $now,
                'updated_by'           => $a['by'] ?? 'QA69',
            ]);

            foreach ($a['locations'] ?? [] as $idLocation) {
                $c->table('archive_locations')->insert([
                    'id_archive'          => $id,
                    'id_location'         => $idLocation,
                ]);
            }

            if (($a['type'] ?? 1) == 2) {
                $doc = $a['doc'] ?? [];
                $c->table('archive_documents')->insert([
                    'id_archive_document' => \Modules\V5\Entities\Helper\MyHelper::generateId(),
                    'id_archive'          => $id,
                    'id_transaction'      => $doc['tx'] ?? ('QA69TX' . substr($id, -10)),
                    'transaction_no'      => $doc['no'] ?? $a['name'],
                    'transaction_type'    => $doc['type'] ?? 6,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
            }
        });

        return $id;
    }

    function q69_folder($t, $label, array $a = [])
    {
        return q69_add($t, $a + ['name' => q69_name($label), 'type' => 1]);
    }

    /** Dokumen uji; secara default yatim (id_transaction QA69TX... tidak ada di tabel transaksi mana pun). */
    function q69_doc($t, $label, array $a = [])
    {
        return q69_add($t, $a + ['name' => q69_name($label), 'type' => 2]);
    }

    /** Sisipkan baris archive_permissions (hak 0/1). */
    function q69_perm($t, $idArchive, $idUser, $view, $update = 0, $delete = 0, $store = 0)
    {
        $now = date('Y-m-d H:i:s');
        q69_w($t, function ($c) use ($idArchive, $idUser, $view, $update, $delete, $store, $now) {
            $c->table('archive_permissions')->insert([
                'id_archive_permission' => \Modules\V5\Entities\Helper\MyHelper::generateId(),
                'id_archive'            => $idArchive,
                'id_user'               => $idUser,
                'is_view'               => $view,
                'is_update'             => $update,
                'is_delete'             => $delete,
                'is_store'              => $store,
                'created_at'            => $now,
                'created_by'            => 'QA69',
                'updated_at'            => $now,
                'updated_by'            => 'QA69',
            ]);
        });
    }

    function q69_set($t, $id, array $cols)
    {
        q69_w($t, function ($c) use ($id, $cols) {
            $c->table('archives')->where('id_archive', $id)->update($cols);
        });
    }

    function q69_tracked_file()
    {
        return __DIR__ . '/.tracked-ids-q69.json';
    }

    /**
     * Catat id baris yang namanya TIDAK berawalan QA69- (mis. folder yang sengaja dinamai seperti nomor transaksi nyata)
     * agar q69_purge() tetap bisa menghapusnya, juga sesudah run yang mati di tengah.
     */
    function q69_track($id)
    {
        $ids = is_file(q69_tracked_file()) ? (json_decode(file_get_contents(q69_tracked_file()), true) ?: []) : [];
        $ids[] = $id;
        file_put_contents(q69_tracked_file(), json_encode(array_values(array_unique($ids))));
    }

    /** Hapus semua baris archive / dokumen / lokasi / permission berawalan QA69- dan id yang dicatat q69_track (idempoten). */
    function q69_purge($t)
    {
        $tracked = is_file(q69_tracked_file()) ? (json_decode(file_get_contents(q69_tracked_file()), true) ?: []) : [];

        q69_w($t, function ($c) use ($tracked) {
            $ids = $c->table('archives')->where('name', 'like', Q69_PREFIX . '%')->pluck('id_archive')->all();
            $ids = array_values(array_unique(array_merge($ids, $tracked)));
            foreach (array_chunk($ids, 500) as $chunk) {
                $c->table('archive_permissions')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_documents')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_locations')->whereIn('id_archive', $chunk)->delete();
                $c->table('archives')->whereIn('id_archive', $chunk)->delete();
            }
        });

        if ($tracked) {
            @unlink(q69_tracked_file());
        }
    }

    /**
     * Dasar "tidak ada sisa": jumlah baris yatim pra-ada (archive_locations tanpa archive; tidak bisa dikenali lewat
     * awalan nama). Jumlah baris archives/archive_documents TIDAK dibandingkan: developer boleh bekerja di DB yang sama.
     */
    function q69_base($t)
    {
        return [
            'orphan_locs' => $t->db()->table('archive_locations as l')->leftJoin('archives as a', 'a.id_archive', '=', 'l.id_archive')
                ->whereNull('a.id_archive')->count(),
        ];
    }

    function q69_assert_clean($t, array $base, $label = 'data uji sudah dibuang')
    {
        $c = $t->db();
        $t->eq($c->table('archives')->where('name', 'like', Q69_PREFIX . '%')->count(), 0, $label . ' (archives berawalan QA69-)');
        $t->eq($c->table('archive_documents')->where('transaction_no', 'like', Q69_PREFIX . '%')->count(), 0, $label . ' (archive_documents)');
        $t->true(!is_file(q69_tracked_file()), $label . ' (tidak ada id tercatat yang tersisa)');
        $t->eq($c->table('archive_documents')->where('id_transaction', 'like', 'QA69TX%')->count(), 0, $label . ' (archive_documents QA69TX)');
        $t->eq($c->table('archive_permissions')->where('created_by', 'QA69')->count(), 0, $label . ' (archive_permissions QA69)');
        $t->eq($c->table('roles')->where('role_name', 'like', Q69_ROLE_PREFIX . '%')->count(), 0, $label . ' (roles QA69-ROLE-)');
        $t->eq(q69_base($t), $base, $label . ' (archive_locations yatim kembali ke awal)');
    }

    function q69_row($t, $id)
    {
        $row = $t->db()->table('archives')->where('id_archive', $id)->first();

        return $row ? (array) $row : null;
    }

    function q69_parent($t, $id)
    {
        $row = q69_row($t, $id);

        return $row ? $row['id_archive_parent'] : '__tidak ada__';
    }

    function q69_name_of($t, $id)
    {
        return q69_row($t, $id)['name'];
    }

    function q69_history($t, $id)
    {
        $raw = $t->db()->table('archives')->where('id_archive', $id)->value('history');
        $h = $raw ? json_decode($raw, true) : [];

        return is_array($h) ? $h : [];
    }

    function q69_actions($t, $id)
    {
        return array_map(function ($h) {
            return $h['action'] ?? null;
        }, q69_history($t, $id));
    }

    /** Snapshot persis baris archives (semua kolom) untuk id tertentu: dibandingkan sebelum/sesudah request ditolak. */
    function q69_snap($t, array $ids)
    {
        $c = $t->db();

        return json_encode([
            'a' => q69_rows($c->table('archives')->whereIn('id_archive', $ids)->orderBy('id_archive')->get()),
            'd' => q69_rows($c->table('archive_documents')->whereIn('id_archive', $ids)->orderBy('id_archive_document')->get()),
        ]);
    }

    // ------------------------------------------------------------------ request

    function q69_put($t, $s, $id, array $body)
    {
        return $t->call($s, 'PUT', Q69_API . 'archives/' . $id, $body);
    }

    /** Body PUT archives/{id} minimal yang valid untuk folder bernama $name. */
    function q69_body($name, array $extra = [])
    {
        return ['name' => $name, 'is_all_location' => 1] + $extra;
    }

    function q69_rename($t, $s, $id, $name, array $extra = [])
    {
        return $t->call($s, 'PUT', Q69_API . 'archives/rename/' . $id, ['name' => $name] + $extra);
    }

    function q69_create($t, $s, array $body)
    {
        return $t->call($s, 'POST', Q69_API . 'archives/create-folder', $body);
    }

    /** put-in: $parent = id folder tujuan (null = root), $sources = id|[id], atau $name = nama sumber */
    function q69_putin($t, $s, $parent, $sources = null, $name = null)
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

        return $t->call($s, 'POST', Q69_API . 'documents/put-in', $body);
    }

    function q69_open($t, $s, $id, array $extra = [])
    {
        return $t->call($s, 'GET', Q69_API . 'archives?' . http_build_query(['id_archive' => $id, 'pagination' => 100] + $extra));
    }

    function q69_loc($t, $s, array $query = [])
    {
        return $t->call($s, 'GET', 'api/v5/select/document-archive/archive/locations' . ($query ? '?' . http_build_query($query) : ''));
    }

    function q69_hist_api($t, $s, $id)
    {
        return $t->call($s, 'GET', Q69_API . 'archives/history/' . $id);
    }

    function q69_code($r)
    {
        return $r[1]['code'] ?? $r[1]['msg_code'] ?? null;
    }

    /** Teks pesan respons (kunci message, msg, atau description), apa adanya. */
    function q69_msg($r)
    {
        return $r[1]['message'] ?? $r[1]['msg'] ?? $r[1]['description'] ?? null;
    }
}
