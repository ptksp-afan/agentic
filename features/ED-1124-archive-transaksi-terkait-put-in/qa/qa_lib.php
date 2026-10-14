<?php
/**
 * Helper bersama skenario ED-1124 (BUKAN skenario: nama berkas tidak cocok scenario*.php).
 * Di-require_once oleh setiap scenario*.php. Prefix fungsi q124_, data uji berawalan "QA124-" (created_by 'QA124').
 *
 * Aturan pakai (Gate 1 ED-1022 ronde 5 §3/§6/§10):
 * - Hanya QA_USER, tanpa mengubah baris user/role/employee/bahasa; tidak ada login user lain selain QA_USER2 (403).
 * - Tulis lewat koneksi default non-read-only di dalam $t->probe(); baca lewat $t->db() (read-only).
 * - Satu-satunya baris yang namanya TIDAK berawalan QA124- = folder K-3 (q124_k3_folder): bernama persis nomor transaksi
 *   nyata, ditaruh DI DALAM folder uji QA (bukan root), id dicatat di .tracked-ids-q124.json + work/ED-1124/k3-ids.log,
 *   dihapus lewat id oleh q124_purge (hanya bila created_by = 'QA124'). Tabel transaksi hanya dibaca.
 * - Hanya baris yang dibuat skrip ini yang dihapus; tidak ada tabel yang dikosongkan atau di-reset.
 * - Asersi lunak (q124_soft): semua kegagalan sebuah skenario dikumpulkan lalu dilaporkan sekaligus.
 */

if (!function_exists('q124_dbname')) {

    define('Q124_PREFIX', 'QA124-');
    define('Q124_BY', 'QA124');
    define('Q124_RANDOM_ID', '999999999999999999999999999999');
    define('Q124_API', 'api/v5/document-archive/');

    function q124_dbname($t)
    {
        return $t->dbs()[0];
    }

    /** Jalankan $fn($conn) dengan koneksi tulis ke DB QA (di dalam probe). */
    function q124_w($t, callable $fn)
    {
        $db = q124_dbname($t);

        return $t->probe(function () use ($fn, $db) {
            return $fn(\Illuminate\Support\Facades\DB::connection($db));
        });
    }

    function q124_rows($collection)
    {
        $out = [];
        foreach ($collection as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }

    // ------------------------------------------------------------------ asersi lunak

    class Q124Soft
    {
        private $t;
        private $bad = [];
        private $n = 0;

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

        public function no5xx($r, $label)
        {
            $this->run(function () use ($r, $label) {
                $this->t->true($r[0] < 500, $label . ': HTTP ' . $r[0] . ' (tidak boleh 5xx)');
            });
        }

        public function done()
        {
            $this->t->note($this->n . ' asersi lunak');
            $this->t->eq($this->bad, [], 'tidak ada asersi yang gagal');
        }
    }

    function q124_soft($t)
    {
        return new Q124Soft($t);
    }

    // ------------------------------------------------------------------ data uji archive

    function q124_new_id($t)
    {
        return q124_w($t, function () {
            return \Modules\V5\Entities\Helper\MyHelper::generateId();
        });
    }

    /** Nama unik berawalan QA124- (maks 30 karakter: dipakai juga sebagai archive_documents.transaction_no). */
    function q124_name($label)
    {
        return Q124_PREFIX . $label . '-' . substr(uniqid(), -6) . mt_rand(10, 99);
    }

    /**
     * Sisipkan satu baris archive uji.
     * $a: name, type (1 folder / 2 dokumen), parent, perm (is_folder_permission), active (default 1; boleh null),
     *     all_location (default 1), locations => [id_location], history (array), status (default 1),
     *     doc => ['type' => int, 'tx' => id_transaction, 'no' => transaction_no] untuk dokumen (doc === false: tanpa
     *     baris archive_documents).
     * @return string id_archive
     */
    function q124_add($t, array $a)
    {
        $id = $a['id'] ?? q124_new_id($t);
        $now = date('Y-m-d H:i:s');

        q124_w($t, function ($c) use ($a, $id, $now) {
            $c->table('archives')->insert([
                'id_archive'           => $id,
                'id_archive_parent'    => $a['parent'] ?? null,
                'name'                 => $a['name'],
                'type'                 => $a['type'] ?? 1,
                'status'               => $a['status'] ?? 1,
                'is_all_location'      => $a['all_location'] ?? 1,
                'is_folder_permission' => $a['perm'] ?? 0,
                'is_active'            => array_key_exists('active', $a) ? $a['active'] : 1,
                'history'              => isset($a['history']) ? json_encode($a['history']) : null,
                'created_at'           => $now,
                'created_by'           => Q124_BY,
                'updated_at'           => $now,
                'updated_by'           => Q124_BY,
            ]);

            foreach ($a['locations'] ?? [] as $idLocation) {
                $c->table('archive_locations')->insert(['id_archive' => $id, 'id_location' => $idLocation]);
            }

            if (($a['type'] ?? 1) == 2 && ($a['doc'] ?? []) !== false) {
                $doc = $a['doc'] ?? [];
                $c->table('archive_documents')->insert([
                    'id_archive_document' => \Modules\V5\Entities\Helper\MyHelper::generateId(),
                    'id_archive'          => $id,
                    'id_transaction'      => $doc['tx'] ?? ('QA124TX' . substr($id, -10)),
                    'transaction_no'      => $doc['no'] ?? $a['name'],
                    'transaction_type'    => $doc['type'] ?? 6,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
            }
        });

        return $id;
    }

    function q124_folder($t, $label, array $a = [])
    {
        return q124_add($t, $a + ['name' => q124_name($label), 'type' => 1]);
    }

    /** Dokumen uji; default yatim (id_transaction QA124TX... tidak ada di tabel transaksi mana pun). */
    function q124_doc($t, $label, array $a = [])
    {
        return q124_add($t, $a + ['name' => q124_name($label), 'type' => 2]);
    }

    function q124_set($t, $id, array $cols)
    {
        q124_w($t, function ($c) use ($id, $cols) {
            $c->table('archives')->where('id_archive', $id)->update($cols);
        });
    }

    // ------------------------------------------------------------------ K-3: folder bernama nomor transaksi nyata

    function q124_tracked_file()
    {
        return __DIR__ . '/.tracked-ids-q124.json';
    }

    function q124_k3_log($line)
    {
        $dir = dirname(__DIR__, 3) . '/work/ED-1124';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($dir . '/k3-ids.log', date('Y-m-d H:i:s') . ' ' . $line . "\n", FILE_APPEND);
    }

    /**
     * Baris K-3: bernama persis $realNo (nomor transaksi nyata), type $type (1 folder; 2 = baris dokumen tanpa
     * archive_documents untuk AC-2), di dalam folder uji QA $parent (WAJIB bukan root). Id dicatat sebelum insert.
     */
    function q124_k3_folder($t, $realNo, $parent, $type = 1)
    {
        if ($parent === null || $parent === '') {
            throw new \RuntimeException('K-3: baris bernama nomor nyata wajib di dalam folder uji QA (bukan root)');
        }
        $id = q124_new_id($t);
        $ids = is_file(q124_tracked_file()) ? (json_decode(file_get_contents(q124_tracked_file()), true) ?: []) : [];
        $ids[] = $id;
        file_put_contents(q124_tracked_file(), json_encode(array_values(array_unique($ids))));
        q124_k3_log("K-3 dibuat id=$id name=\"$realNo\" type=$type parent=$parent");
        q124_add($t, ['id' => $id, 'name' => $realNo, 'type' => $type, 'parent' => $parent, 'doc' => false]);

        return $id;
    }

    /** Hapus semua baris berawalan QA124- dan id K-3 yang dicatat (hanya bila created_by = QA124). Idempoten. */
    function q124_purge($t)
    {
        $tracked = is_file(q124_tracked_file()) ? (json_decode(file_get_contents(q124_tracked_file()), true) ?: []) : [];

        $deleted = q124_w($t, function ($c) use ($tracked) {
            // berawalan QA124- (juga baris buatan DocumentService::store: created_by = pengguna/sistem); id K-3 hanya bila created_by QA124
            $ids = $c->table('archives')->where('name', 'like', Q124_PREFIX . '%')->pluck('id_archive')->all();
            $k3 = $tracked ? $c->table('archives')->whereIn('id_archive', $tracked)->where('created_by', Q124_BY)->pluck('id_archive')->all() : [];
            $all = array_values(array_unique(array_merge($ids, $k3)));
            foreach (array_chunk($all, 500) as $chunk) {
                $c->table('archive_permissions')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_documents')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_locations')->whereIn('id_archive', $chunk)->delete();
                $c->table('archives')->whereIn('id_archive', $chunk)->delete();
            }

            return ['all' => count($all), 'k3' => $k3];
        });

        foreach ($deleted['k3'] as $id) {
            q124_k3_log("K-3 dihapus lewat id=$id");
        }
        if ($tracked) {
            @unlink(q124_tracked_file());
        }

        return $deleted['all'];
    }

    function q124_base($t)
    {
        return [
            'orphan_locs' => $t->db()->table('archive_locations as l')->leftJoin('archives as a', 'a.id_archive', '=', 'l.id_archive')
                ->whereNull('a.id_archive')->count(),
            'orphan_docs' => $t->db()->table('archive_documents as d')->leftJoin('archives as a', 'a.id_archive', '=', 'd.id_archive')
                ->whereNull('a.id_archive')->count(),
        ];
    }

    function q124_assert_clean($t, array $base, $label = 'data uji sudah dibuang')
    {
        $c = $t->db();
        $t->eq($c->table('archives')->where('name', 'like', Q124_PREFIX . '%')->count(), 0, $label . ' (archives berawalan QA124-)');
        $t->eq($c->table('archives')->where('created_by', Q124_BY)->count(), 0, $label . ' (archives created_by QA124, termasuk baris K-3)');
        $t->eq($c->table('archive_documents')->where('transaction_no', 'like', Q124_PREFIX . '%')->count(), 0, $label . ' (archive_documents)');
        $t->eq($c->table('archive_documents')->where('id_transaction', 'like', 'QA124TX%')->count(), 0, $label . ' (archive_documents QA124TX)');
        $t->true(!is_file(q124_tracked_file()), $label . ' (tidak ada id K-3 tercatat yang tersisa)');
        $t->eq(q124_base($t), $base, $label . ' (baris yatim archive_locations/archive_documents kembali ke awal)');
    }

    // ------------------------------------------------------------------ baca

    function q124_row($t, $id)
    {
        $row = $t->db()->table('archives')->where('id_archive', $id)->first();

        return $row ? (array) $row : null;
    }

    function q124_parent($t, $id)
    {
        $row = q124_row($t, $id);

        return $row ? $row['id_archive_parent'] : '__tidak ada__';
    }

    function q124_history($t, $id)
    {
        $raw = $t->db()->table('archives')->where('id_archive', $id)->value('history');
        $h = $raw ? json_decode($raw, true) : [];

        return is_array($h) ? $h : [];
    }

    function q124_actions($t, $id)
    {
        return array_map(function ($h) {
            return $h['action'] ?? null;
        }, q124_history($t, $id));
    }

    /** Snapshot persis baris archives + archive_documents (semua kolom) untuk id tertentu. */
    function q124_snap($t, array $ids)
    {
        $c = $t->db();

        return json_encode([
            'a' => q124_rows($c->table('archives')->whereIn('id_archive', $ids)->orderBy('id_archive')->get()),
            'd' => q124_rows($c->table('archive_documents')->whereIn('id_archive', $ids)->orderBy('id_archive_document')->get()),
            'l' => q124_rows($c->table('archive_locations')->whereIn('id_archive', $ids)->orderBy('id_archive')->orderBy('id_location')->get()),
        ]);
    }

    // ------------------------------------------------------------------ request

    /** put-in: $parent = id folder tujuan (null = root), $sources = id|[id], atau $name = nama sumber */
    function q124_putin($t, $s, $parent, $sources = null, $name = null)
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

        return $t->call($s, 'POST', Q124_API . 'documents/put-in', $body);
    }

    function q124_move($t, $s, $action, $name)
    {
        return $t->call($s, 'POST', Q124_API . 'documents/' . $action, ['name' => $name]);
    }

    function q124_loc($t, $s, array $query = [])
    {
        return $t->call($s, 'GET', 'api/v5/select/document-archive/archive/locations' . ($query ? '?' . http_build_query($query) : ''));
    }

    /** Request FE ArchivePage/index.js:229-237: id_archive + search JSON. */
    function q124_related($t, $s, $idArchive, array $search = ['showRelatedTransaction' => true])
    {
        return $t->call($s, 'GET', Q124_API . 'archives?pagination=100&id_archive=' . $idArchive . '&search=' . urlencode(json_encode($search)));
    }

    function q124_code($r)
    {
        return $r[1]['code'] ?? $r[1]['msg_code'] ?? null;
    }

    function q124_msg($r)
    {
        return $r[1]['message'] ?? $r[1]['msg'] ?? $r[1]['description'] ?? null;
    }

    /** Baris list per id_archive. */
    function q124_by_id($r)
    {
        $rows = [];
        foreach ($r[1]['result']['data'] ?? [] as $row) {
            $rows[$row['id_archive']] = $row;
        }

        return $rows;
    }

    // ------------------------------------------------------------------ kode pembanding (baseline) untuk relatedTransaction()

    /** Commit baseline BE item ini (baseline/be.head); cadangan HEAD. */
    function q124_baseline_ref()
    {
        $file = __DIR__ . '/../baseline/be.head';
        $ref = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return preg_match('/^[0-9a-f]{7,40}$/', $ref) ? $ref : 'HEAD';
    }

    /** Muat ArchiveService versi baseline sebagai kelas pembanding; return nama kelas, atau null bila git tidak bisa dibaca. */
    function q124_baseline_class($t)
    {
        $class = 'Q124BaselineArchiveService';
        $fqcn = 'Modules\\V5\\Http\\Services\\DocumentArchive\\' . $class;
        if (class_exists($fqcn, false)) {
            return $fqcn;
        }

        $be = $t->conf('BE_DIR');
        $src = shell_exec('git -C ' . escapeshellarg($be) . ' show ' . escapeshellarg(q124_baseline_ref() . ':Modules/V5/Http/Services/DocumentArchive/ArchiveService.php') . ' 2>/dev/null');
        if (!is_string($src) || strpos($src, 'class ArchiveService extends DocumentArchiveService') === false) {
            return null;
        }
        // jaminan: ini memang kode SEBELUM perubahan (tanpa penyaring type pada kueri akhir)
        if (strpos($src, 'ED-1124') !== false) {
            return null;
        }

        $src = preg_replace('/^<\?php/', '', $src, 1);
        $src = str_replace('class ArchiveService extends DocumentArchiveService', 'class ' . $class . ' extends DocumentArchiveService', $src);
        eval($src);

        return $fqcn;
    }

    /**
     * relatedTransaction() via refleksi. $fqcn = kelas baseline atau ArchiveService sekarang. Dipanggil sebagai QA_USER
     * (Auth::setUser) agar bahasa label sama dengan respons HTTP. Return ['ok' => hasil] atau ['err' => pesan].
     */
    function q124_related_unit($t, $fqcn, array $document)
    {
        $db = q124_dbname($t);
        $user = $t->conf('QA_USER');

        return $t->probe(function () use ($fqcn, $document, $db, $user) {
            \Illuminate\Support\Facades\DB::setDefaultConnection($db);
            $u = \Modules\V5\Entities\Models\User::where('username', $user)->first();
            if ($u) {
                \Illuminate\Support\Facades\Auth::setUser($u);
            }
            try {
                $svc = new $fqcn;
                $m = new \ReflectionMethod($svc, 'relatedTransaction');
                $m->setAccessible(true);

                return ['ok' => $m->invoke($svc, $document, \Modules\V5\Entities\Models\Archive::userScopeLocations($u ? $u->id_user : null))];
            } catch (\Throwable $e) {
                return ['err' => get_class($e) . ': ' . $e->getMessage()];
            }
        });
    }

    /**
     * Pilih dokumen DO (tipe 7) nyata yang punya transaksi terkait ber-dokumen aktif. Return daftar id_transaction
     * kandidat (dibaca saja dari tabel transaksi/arsip).
     */
    function q124_do_candidates($t, $limit = 6)
    {
        $rows = $t->db()->select("select distinct ad.id_transaction, d.sales_order_no
            from archive_documents ad
            join delivery_orders d on d.id_delivery_order = ad.id_transaction
            join archives a on a.name = d.sales_order_no and a.type = 2 and a.is_active > 0
            where ad.transaction_type = 7 and ad.id_transaction not like 'QA%'
            order by ad.id_transaction limit " . (int) $limit);

        return array_map(function ($r) {
            return $r->id_transaction;
        }, $rows);
    }
}
