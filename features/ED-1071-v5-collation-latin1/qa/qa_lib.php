<?php
/**
 * Helper bersama skenario ED-1071 (BUKAN skenario: nama berkas tidak cocok scenario*.php).
 * Di-require_once oleh setiap scenario*.php. Prefix fungsi q71_, data uji berawalan "QA71-".
 *
 * Aturan pakai:
 * - Tulis lewat koneksi default non-read-only di dalam $t->probe(); baca lewat $t->db() (read-only).
 * - QA_USER = role 3 (Super Admin, is_superadmin 3: TIDAK bypass hak folder) dan memegang semua hak Archive + Opname Document
 *   (data baseline developer: tidak diubah). "Tanpa hak" = keadaan SEMENTARA: role fixture QA71-ROLE-* yang menggantikan role
 *   QA_USER sebentar, lokasi kerja employee QA_USER dibatasi sebentar (q71_with_user), baris archive_permissions /
 *   is_folder_permission pada folder uji. Dipulihkan persis di finally; jurnal di .restore-journal-q71.json (run yang mati di
 *   tengah dipulihkan dari jurnal itu oleh run berikutnya).
 * - Hanya baris yang dibuat skrip ini (nama berawalan QA71-) yang dihapus; tabel archive_opname* tidak pernah disentuh.
 * - Asersi lunak (Q71Soft): semua kegagalan sebuah skenario dikumpulkan lalu dilaporkan sekaligus; setiap respons 5xx yang
 *   lewat Q71Soft::call() otomatis menjadi kegagalan.
 */

if (!function_exists('q71_dbname')) {

    define('Q71_PREFIX', 'QA71-');
    define('Q71_ROLE_PREFIX', 'QA71-ROLE-');
    define('Q71_API', 'api/v5/document-archive/');
    define('Q71_SEL', 'api/v5/select/document-archive/archive/');
    define('Q71_RANDOM_ID', '999999999999999999999999999999');
    define('Q71_GE0748_ID', 'Mengandung karakter yang tidak didukung (mis. emoji, huruf Yunani/Mandarin). Gunakan huruf Latin.');
    define('Q71_GE0748_EN', 'Contains unsupported characters (e.g. emoji, Greek or Chinese letters). Use Latin characters only.');

    function q71_dbname($t)
    {
        return $t->dbs()[0];
    }

    /** Jalankan $fn($conn) dengan koneksi tulis ke DB QA (di dalam probe). */
    function q71_w($t, callable $fn)
    {
        $db = q71_dbname($t);

        return $t->probe(function () use ($fn, $db) {
            return $fn(\Illuminate\Support\Facades\DB::connection($db));
        });
    }

    function q71_rows($collection)
    {
        $out = [];
        foreach ($collection as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }

    // ------------------------------------------------------------------ himpunan karakter (spec: S_BAD / S_OK)

    /** S_BAD berbentuk teks UTF-8 sah: label => nilai. UTF-8 rusak ada di q71_broken(). */
    function q71_bad()
    {
        return [
            'omega'        => "\u{03A9}",
            'cjk'          => "\u{4E2D}\u{6587}",
            'emoji'        => "\u{1F600}",
            'aksen-gabung' => "e\u{0301}",
            'rtl'          => "\u{202E}",
        ];
    }

    /** UTF-8 rusak: "x" + 0xC3 0x28 (byte mentah). Di query/path ditulis literal "x%C3%28". */
    function q71_broken()
    {
        return "x\xC3\x28";
    }

    /** S_OK (ada di Windows-1252): label => [nilai, hex cp1252]. */
    function q71_ok()
    {
        return [
            'u-umlaut'  => ["\u{00FC}", 'FC'],
            'kutip'     => ["\u{2019}", '92'],
            'em-dash'   => ["\u{2014}", '97'],
            'euro'      => ["\u{20AC}", '80'],
            'trademark' => ["\u{2122}", '99'],
        ];
    }

    function q71_enc($s)
    {
        return rawurlencode($s);
    }

    // ------------------------------------------------------------------ jurnal & user QA (role / lokasi kerja / bahasa)

    function q71_journal()
    {
        return __DIR__ . '/.restore-journal-q71.json';
    }

    function q71_uid($t)
    {
        return $t->db()->table('users')->where('username', $t->conf('QA_USER'))->value('id_user');
    }

    function q71_uname($t)
    {
        return $t->conf('QA_USER');
    }

    function q71_locs($t)
    {
        return $t->db()->table('locations')->orderBy('id_location')->pluck('id_location', 'location_code')->all();
    }

    function q71_user_snapshot($t)
    {
        $c = $t->db();
        $uid = q71_uid($t);
        $emp = $c->table('employees')->where('id_user', $uid)->first();

        return [
            'uid'      => $uid,
            'roles'    => q71_rows($c->table('user_roles')->where('id_user', $uid)->orderBy('id_user_role')->get()),
            'lang'     => $c->table('users')->where('id_user', $uid)->value('language'),
            'emp_id'   => $emp->id_employee ?? null,
            'emp_all'  => $emp->is_all_location ?? null,
            'emp_locs' => $emp ? q71_rows($c->table('employee_locations')->where('id_employee', $emp->id_employee)->orderBy('id_employee_location')->get()) : [],
        ];
    }

    function q71_user_restore($t, array $snap)
    {
        q71_w($t, function ($c) use ($snap) {
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

    /** opts: 'role' => int|int[] (id_role), 'lang' => 'ID'|'EN', 'emp' => [id_location, ...] (lokasi kerja terbatas) */
    function q71_user_apply($t, array $snap, array $opts)
    {
        q71_w($t, function ($c) use ($snap, $opts) {
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
                foreach (array_values($opts['emp']) as $i => $idLocation) {
                    $c->table('employee_locations')->insert([
                        'id_employee' => $snap['emp_id'], 'id_location' => $idLocation,
                        'is_default' => $i === 0 ? 1 : 0, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    function q71_recover($t)
    {
        if (is_file(q71_journal())) {
            $snap = json_decode(file_get_contents(q71_journal()), true);
            if (is_array($snap)) {
                q71_user_restore($t, $snap);
            }
            @unlink(q71_journal());
        }
    }

    /**
     * Jalankan $fn($set) dengan user QA di keadaan $opts. $set(array $opts) mengganti keadaan (relatif terhadap keadaan
     * asli). Keadaan asli dipulihkan dan diverifikasi persis di finally.
     */
    function q71_with_user($t, array $opts, callable $fn)
    {
        q71_recover($t);
        $snap = q71_user_snapshot($t);
        file_put_contents(q71_journal(), json_encode($snap));

        $set = function (array $o) use ($t, $snap) {
            q71_user_restore($t, $snap);
            q71_user_apply($t, $snap, $o);
        };

        try {
            $set($opts);

            return $fn($set);
        } finally {
            q71_user_restore($t, $snap);
            $after = q71_user_snapshot($t);
            if (json_encode($after) !== json_encode($snap)) {
                $t->fail('pemulihan user QA tidak persis sama dengan sebelumnya (jurnal dipertahankan)');
            }
            @unlink(q71_journal());
        }
    }

    // ------------------------------------------------------------------ role fixture

    /** Role fixture QA71-ROLE- dengan permission bernama $names, bukan superadmin (4), aktif. @return int id_role */
    function q71_role($t, $label, array $names)
    {
        return q71_w($t, function ($c) use ($label, $names) {
            $now = date('Y-m-d H:i:s');
            $id = $c->table('roles')->insertGetId([
                'role_name' => Q71_ROLE_PREFIX . $label, 'description' => 'QA ED-1071 fixture', 'is_superadmin' => 4,
                'is_active' => 1, 'is_used' => 1, 'created_by' => 'QA71', 'created_at' => $now, 'updated_by' => 'QA71', 'updated_at' => $now,
            ]);
            foreach ($names as $name) {
                $perm = $c->table('permissions')->where('permission_name', $name)->first();
                if (!$perm) {
                    throw new \RuntimeException("prasyarat: permission '$name' tidak ada di DB uji");
                }
                $c->table('role_permissions')->insert([
                    'id_role' => $id, 'id_permission' => $perm->id_permission, 'permission_name' => $perm->permission_name,
                    'created_at' => $now, 'updated_at' => $now, 'created_by' => 'QA71', 'updated_by' => 'QA71',
                ]);
            }

            return (int) $id;
        });
    }

    function q71_role_purge($t)
    {
        q71_w($t, function ($c) {
            $ids = $c->table('roles')->where('role_name', 'like', Q71_ROLE_PREFIX . '%')->pluck('id_role')->all();
            if ($ids) {
                $c->table('user_roles')->whereIn('id_role', $ids)->delete();
                $c->table('role_permissions')->whereIn('id_role', $ids)->delete();
                $c->table('roles')->whereIn('id_role', $ids)->delete();
            }
        });
    }

    /** Semua hak modul Archive kecuali yang disebut di $without. */
    function q71_perms_all(array $without = [])
    {
        $all = ['List Archive', 'Update Folder', 'Delete Folder', 'Add Folder', 'Add Document', 'Move Archive', 'View Folder',
            'Handover Document', 'Receive Document', 'Folder Permissions'];

        return array_values(array_diff($all, $without));
    }

    // ------------------------------------------------------------------ data uji archive

    function q71_new_id($t)
    {
        return q71_w($t, function ($c) {
            return \Modules\V5\Entities\Helper\MyHelper::generateId();
        });
    }

    /** Nama unik berawalan QA71- (maks 30 karakter: dipakai juga sebagai archive_documents.transaction_no). */
    function q71_name($label)
    {
        return Q71_PREFIX . $label . '-' . substr(uniqid(), -6) . mt_rand(10, 99);
    }

    /**
     * Sisipkan satu baris archive uji.
     * $a: name, type (1 folder / 2 dokumen), parent, perm (is_folder_permission), by (created_by, default 'QA71'),
     *     active (default 1), all_location (default 1), locations => [id_location], description,
     *     history (default satu entri 'create'), doc => ['type' => int, 'tx' => id_transaction, 'no' => transaction_no].
     */
    function q71_add($t, array $a)
    {
        $id = q71_new_id($t);
        $now = date('Y-m-d H:i:s');
        $history = $a['history'] ?? [['action' => 'create', 'related_username' => 'QA71', 'timestamp' => '01 Jan 2026 00:00:00']];

        q71_w($t, function ($c) use ($a, $id, $now, $history) {
            $c->table('archives')->insert([
                'id_archive'           => $id,
                'id_archive_parent'    => $a['parent'] ?? null,
                'name'                 => $a['name'],
                'description'          => $a['description'] ?? null,
                'type'                 => $a['type'] ?? 1,
                'status'               => 1,
                'is_all_location'      => $a['all_location'] ?? 1,
                'is_folder_permission' => $a['perm'] ?? 0,
                'is_active'            => $a['active'] ?? 1,
                'history'              => json_encode($history),
                'created_at'           => $now,
                'created_by'           => $a['by'] ?? 'QA71',
                'updated_at'           => $now,
                'updated_by'           => $a['by'] ?? 'QA71',
            ]);

            foreach ($a['locations'] ?? [] as $idLocation) {
                $c->table('archive_locations')->insert(['id_archive' => $id, 'id_location' => $idLocation]);
            }

            if (($a['type'] ?? 1) == 2) {
                $doc = $a['doc'] ?? [];
                $c->table('archive_documents')->insert([
                    'id_archive_document' => \Modules\V5\Entities\Helper\MyHelper::generateId(),
                    'id_archive'          => $id,
                    'id_transaction'      => $doc['tx'] ?? ('QA71TX' . substr($id, -10)),
                    'transaction_no'      => $doc['no'] ?? $a['name'],
                    'transaction_type'    => $doc['type'] ?? 6,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
            }
        });

        return $id;
    }

    function q71_folder($t, $label, array $a = [])
    {
        return q71_add($t, $a + ['name' => q71_name($label), 'type' => 1]);
    }

    function q71_doc($t, $label, array $a = [])
    {
        return q71_add($t, $a + ['name' => q71_name($label), 'type' => 2]);
    }

    function q71_perm($t, $idArchive, $idUser, $view, $update = 0, $delete = 0, $store = 0)
    {
        $now = date('Y-m-d H:i:s');
        q71_w($t, function ($c) use ($idArchive, $idUser, $view, $update, $delete, $store, $now) {
            $c->table('archive_permissions')->insert([
                'id_archive_permission' => \Modules\V5\Entities\Helper\MyHelper::generateId(),
                'id_archive'            => $idArchive,
                'id_user'               => $idUser,
                'is_view'               => $view,
                'is_update'             => $update,
                'is_delete'             => $delete,
                'is_store'              => $store,
                'created_at'            => $now,
                'created_by'            => 'QA71',
                'updated_at'            => $now,
                'updated_by'            => 'QA71',
            ]);
        });
    }

    /** Hapus semua baris archive / dokumen / lokasi / permission berawalan QA71- (idempoten). */
    function q71_purge($t)
    {
        q71_w($t, function ($c) {
            $ids = $c->table('archives')->where('name', 'like', Q71_PREFIX . '%')->pluck('id_archive')->all();
            foreach (array_chunk($ids, 500) as $chunk) {
                $c->table('archive_permissions')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_documents')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_locations')->whereIn('id_archive', $chunk)->delete();
                $c->table('archives')->whereIn('id_archive', $chunk)->delete();
            }
            $c->table('archive_permissions')->where('created_by', 'QA71')->delete();
        });
    }

    /** Dasar "tidak ada sisa": baris yatim pra-ada (archive_locations tanpa archive). */
    function q71_base($t)
    {
        return [
            'orphan_locs' => $t->db()->table('archive_locations as l')->leftJoin('archives as a', 'a.id_archive', '=', 'l.id_archive')
                ->whereNull('a.id_archive')->count(),
        ];
    }

    function q71_assert_clean($t, array $base, $label = 'data uji sudah dibuang')
    {
        $c = $t->db();
        $t->eq($c->table('archives')->where('name', 'like', Q71_PREFIX . '%')->count(), 0, $label . ' (archives berawalan QA71-)');
        $t->eq($c->table('archive_documents')->where('id_transaction', 'like', 'QA71TX%')->count(), 0, $label . ' (archive_documents QA71TX)');
        $t->eq($c->table('archive_permissions')->where('created_by', 'QA71')->count(), 0, $label . ' (archive_permissions QA71)');
        $t->eq($c->table('roles')->where('role_name', 'like', Q71_ROLE_PREFIX . '%')->count(), 0, $label . ' (roles QA71-ROLE-)');
        $t->true(!is_file(q71_journal()), $label . ' (jurnal pemulihan user sudah dibuang)');
        $t->eq(q71_base($t), $base, $label . ' (archive_locations yatim kembali ke awal)');
    }

    function q71_row($t, $id)
    {
        $row = $t->db()->table('archives')->where('id_archive', $id)->first();

        return $row ? (array) $row : null;
    }

    function q71_hex($t, $id, $column = 'name')
    {
        $rows = $t->db()->select("select hex($column) as h from archives where id_archive = ?", [$id]);

        return $rows ? $rows[0]->h : null;
    }

    /** Hex cp1252 dari teks yang hanya berisi karakter Windows-1252. */
    function q71_hex_cp1252($utf8)
    {
        return strtoupper(bin2hex(mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8')));
    }

    function q71_history($t, $id)
    {
        $raw = $t->db()->table('archives')->where('id_archive', $id)->value('history');
        $h = $raw ? json_decode($raw, true) : [];

        return is_array($h) ? $h : [];
    }

    /**
     * Snapshot persis (semua kolom) baris archive + dokumen + hak + lokasi untuk $ids, ditambah jumlah baris berawalan QA71-
     * dan jumlah baris total tabel Archive: dibandingkan sebelum/sesudah request ditolak (tanpa tulis).
     */
    function q71_snap($t, array $ids)
    {
        $c = $t->db();

        return json_encode([
            'a'  => q71_rows($c->table('archives')->whereIn('id_archive', $ids)->orderBy('id_archive')->get()),
            'd'  => q71_rows($c->table('archive_documents')->whereIn('id_archive', $ids)->orderBy('id_archive_document')->get()),
            'p'  => q71_rows($c->table('archive_permissions')->whereIn('id_archive', $ids)->orderBy('id_archive_permission')->get()),
            'l'  => q71_rows($c->table('archive_locations')->whereIn('id_archive', $ids)->orderBy('id_location')->get()),
            'qa' => $c->table('archives')->where('name', 'like', Q71_PREFIX . '%')->count(),
            'n'  => [
                $c->table('archives')->count(), $c->table('archive_documents')->count(),
                $c->table('archive_permissions')->count(), $c->table('archive_locations')->count(),
            ],
        ]);
    }

    // ------------------------------------------------------------------ request mentah (isi body persis seperti dikirim)

    /**
     * Kirim body MENTAH (mis. UTF-8 rusak) dengan token sesi $s. Token tidak pernah dicetak.
     * $contentType: 'application/json' atau 'application/x-www-form-urlencoded'.
     * @return array [status, json]
     */
    function q71_raw($s, $method, $uri, $rawBody, $contentType = 'application/json')
    {
        $ch = curl_init(\QaHttp\Config::base() . '/' . ltrim($uri, '/'));
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: ' . $contentType, 'Authorization: Bearer ' . $s->token],
            CURLOPT_POSTFIELDS     => $rawBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 120,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, is_string($raw) ? json_decode($raw, true) : null];
    }

    function q71_code($r)
    {
        return $r[1]['code'] ?? $r[1]['msg_code'] ?? null;
    }

    /** Daftar id_archive pada result.data respons list. */
    function q71_list_ids($r)
    {
        $ids = [];
        foreach ($r[1]['result']['data'] ?? [] as $row) {
            $ids[] = $row['id_archive'] ?? null;
        }

        return $ids;
    }

    // ------------------------------------------------------------------ asersi lunak

    class Q71Soft
    {
        private $t;
        private $bad = [];
        private $n = 0;
        private $statuses = [];

        public function __construct($t)
        {
            $this->t = $t;
        }

        private function run(callable $fn)
        {
            $this->n++;
            try {
                $fn();
            } catch (\QaHttp\AssertionFailed $e) {
                $this->bad[] = $e->getMessage();
            }
        }

        /** Request biasa; respons >= 500 otomatis menjadi kegagalan (setiap 500 adalah defect). */
        public function call($s, $method, $uri, $body = null, $label = null)
        {
            $r = $this->t->call($s, $method, $uri, $body);

            return $this->seen($r, $label ?: ($method . ' ' . $uri));
        }

        public function raw($s, $method, $uri, $rawBody, $contentType, $label)
        {
            return $this->seen(q71_raw($s, $method, $uri, $rawBody, $contentType), $label);
        }

        private function seen($r, $label)
        {
            $this->statuses[] = (int) $r[0];
            $this->run(function () use ($r, $label) {
                $this->t->true($r[0] < 500, $label . ': HTTP ' . $r[0] . ' (setiap 5xx adalah defect) ' . substr(json_encode($r[1]), 0, 160));
            });

            return $r;
        }

        public function eq($actual, $expected, $label)
        {
            $this->run(function () use ($actual, $expected, $label) {
                $this->t->eq($actual, $expected, $label);
            });
        }

        public function ok($cond, $label)
        {
            $this->run(function () use ($cond, $label) {
                $this->t->true($cond === true, $label);
            });
        }

        /** HTTP status + kode pesan (null = tidak diperiksa) */
        public function resp($r, $status, $code, $label)
        {
            $this->run(function () use ($r, $status, $label) {
                $this->t->status($r, $status, $label);
            });
            if ($code !== null) {
                $this->run(function () use ($r, $code, $label) {
                    $this->t->code($r, $code, $label);
                });
            }
        }

        /**
         * 422 validasi: errors tepat berisi kunci $keys (urutan bebas) dan masing-masing tepat [teks GE0748 $text].
         * $keys = [] hanya memeriksa 422.
         */
        public function ge0748($r, array $keys, $label, $text = Q71_GE0748_ID)
        {
            $this->run(function () use ($r, $label) {
                $this->t->status($r, 422, $label);
            });
            if (!$keys) {
                return;
            }
            $errors = $r[1]['errors'] ?? [];
            $got = array_keys($errors);
            sort($got);
            $want = $keys;
            sort($want);
            $this->run(function () use ($got, $want, $label) {
                $this->t->eq($got, $want, $label . ': kunci errors');
            });
            foreach ($keys as $key) {
                $this->run(function () use ($errors, $key, $text, $label) {
                    $this->t->eq($errors[$key] ?? null, [$text], $label . ": errors.$key = teks GE0748");
                });
            }
        }

        public function done()
        {
            $counts = array_count_values($this->statuses);
            ksort($counts);
            $this->t->note($this->n . ' asersi lunak; ' . count($this->statuses) . ' respons ' . json_encode($counts));
            $this->t->eq($this->bad, [], 'tidak ada asersi yang gagal');
        }
    }

    function q71_soft($t)
    {
        return new Q71Soft($t);
    }
}
