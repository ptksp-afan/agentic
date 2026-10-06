<?php
/**
 * Helper bersama skenario ED-1024 (BUKAN skenario: nama berkas tidak cocok scenario*.php).
 * Di-require_once oleh setiap scenario*.php.
 *
 * Aturan pakai:
 * - Perubahan data hanya lewat q1_with_user() (role/lokasi employee/bahasa user QA) dan q1_add_*() (baris
 *   berawalan "QA01-"), selalu dipulihkan di finally. Jurnal pemulihan user ada di .restore-journal.json;
 *   bila sebuah run mati di tengah, run berikutnya memulihkan dari jurnal itu lebih dulu.
 * - Tulis lewat koneksi default non-read-only di dalam $t->probe(); baca lewat $t->db() (read-only).
 */

if (!function_exists('q1_dbname')) {

    define('Q1_PREFIX', 'QA01-');

    function q1_dbname($t)
    {
        return $t->dbs()[0];
    }

    /** Jalankan $fn($conn) dengan koneksi tulis ke DB QA (di dalam probe). */
    function q1_w($t, callable $fn)
    {
        $db = q1_dbname($t);

        return $t->probe(function () use ($fn, $db) {
            return $fn(\Illuminate\Support\Facades\DB::connection($db));
        });
    }

    function q1_journal()
    {
        return __DIR__ . '/.restore-journal.json';
    }

    /** id_user akun QA_USER */
    function q1_uid($t)
    {
        return $t->db()->table('users')->where('username', $t->conf('QA_USER'))->value('id_user');
    }

    /** kode lokasi => id lokasi */
    function q1_locs($t)
    {
        return $t->db()->table('locations')->pluck('id_location', 'location_code')->all();
    }

    function q1_rows($collection)
    {
        $out = [];
        foreach ($collection as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }

    // ------------------------------------------------------------------ user QA (role / lokasi / bahasa)

    function q1_user_snapshot($t)
    {
        $c = $t->db();
        $uid = q1_uid($t);
        $emp = $c->table('employees')->where('id_user', $uid)->first();

        return [
            'uid'      => $uid,
            'roles'    => q1_rows($c->table('user_roles')->where('id_user', $uid)->orderBy('id_user_role')->get()),
            'lang'     => $c->table('users')->where('id_user', $uid)->value('language'),
            'emp_id'   => $emp->id_employee ?? null,
            'emp_all'  => $emp->is_all_location ?? null,
            'emp_locs' => $emp ? q1_rows($c->table('employee_locations')->where('id_employee', $emp->id_employee)->orderBy('id_employee_location')->get()) : [],
        ];
    }

    function q1_user_restore($t, array $snap)
    {
        q1_w($t, function ($c) use ($snap) {
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

    /**
     * opts: 'role' => int|int[] (id_role), 'emp' => 'all'|'none'|['JOG', ...] (kode lokasi), 'lang' => 'ID'|'EN'
     */
    function q1_user_apply($t, array $snap, array $opts)
    {
        $locs = q1_locs($t);
        q1_w($t, function ($c) use ($snap, $opts, $locs) {
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
                if ($emp === 'none') {
                    $c->table('employees')->where('id_employee', $snap['emp_id'])->update(['id_user' => 'QA01-NOEMP']);
                } elseif ($emp === 'all') {
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

    /** Pulihkan user QA dari jurnal bila run sebelumnya mati sebelum sempat memulihkan. */
    function q1_recover($t)
    {
        if (is_file(q1_journal())) {
            $snap = json_decode(file_get_contents(q1_journal()), true);
            if (is_array($snap)) {
                q1_user_restore($t, $snap);
            }
            @unlink(q1_journal());
        }
    }

    /**
     * Jalankan $fn($set) dengan user QA di keadaan $opts. $set(array $opts) mengganti keadaan (selalu relatif
     * terhadap keadaan asli). Keadaan asli dipulihkan dan diverifikasi persis di finally.
     */
    function q1_with_user($t, array $opts, callable $fn)
    {
        q1_recover($t);
        $snap = q1_user_snapshot($t);
        file_put_contents(q1_journal(), json_encode($snap));

        $set = function (array $o) use ($t, $snap) {
            q1_user_restore($t, $snap);
            q1_user_apply($t, $snap, $o);
        };

        try {
            $set($opts);

            return $fn($set);
        } finally {
            q1_user_restore($t, $snap);
            $after = q1_user_snapshot($t);
            if (json_encode($after) !== json_encode($snap)) {
                $t->fail('pemulihan user QA tidak persis sama dengan sebelumnya (jurnal dipertahankan)');
            }
            @unlink(q1_journal());
        }
    }

    // ------------------------------------------------------------------ data archive uji

    function q1_new_id($t)
    {
        return q1_w($t, function ($c) {
            return \Modules\V5\Entities\Helper\MyHelper::generateId();
        });
    }

    /**
     * Sisipkan satu baris archive (folder/dokumen) uji berawalan QA01-.
     * $a: name, type (1 folder / 2 dokumen), parent, all (0/1), locs (kode lokasi), active (default 1),
     *     doc => ['type' => int, 'id_transaction' => string] untuk dokumen.
     * @return string id_archive
     */
    function q1_add($t, array $a)
    {
        $id = q1_new_id($t);
        $locs = q1_locs($t);
        $now = date('Y-m-d H:i:s');

        q1_w($t, function ($c) use ($a, $id, $locs, $now) {
            $c->table('archives')->insert([
                'id_archive'        => $id,
                'id_archive_parent' => $a['parent'] ?? null,
                'name'              => $a['name'],
                'type'              => $a['type'] ?? 1,
                'status'            => 1,
                'is_all_location'   => $a['all'] ?? 1,
                'is_active'         => $a['active'] ?? 1,
                'created_at'        => $now,
                'created_by'        => 'QA01',
                'updated_at'        => $now,
                'updated_by'        => 'QA01',
            ]);

            foreach ($a['locs'] ?? [] as $code) {
                $c->table('archive_locations')->insert(['id_archive' => $id, 'id_location' => $locs[$code]]);
            }

            if (($a['type'] ?? 1) == 2) {
                $doc = $a['doc'] ?? [];
                $c->table('archive_documents')->insert([
                    'id_archive_document' => \Modules\V5\Entities\Helper\MyHelper::generateId(),
                    'id_archive'          => $id,
                    'id_transaction'      => $doc['id_transaction'] ?? ('QA01TX' . substr($id, -10)),
                    'transaction_no'      => $a['name'],
                    'transaction_type'    => $doc['type'] ?? 6,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
            }
        });

        return $id;
    }

    /** Hapus semua baris archive/lokasi/dokumen berawalan QA01- (idempoten). */
    function q1_purge($t)
    {
        q1_w($t, function ($c) {
            $ids = $c->table('archives')->where('name', 'like', Q1_PREFIX . '%')->pluck('id_archive')->all();
            foreach (array_chunk($ids, 500) as $chunk) {
                $c->table('archive_documents')->whereIn('id_archive', $chunk)->delete();
                $c->table('archive_locations')->whereIn('id_archive', $chunk)->delete();
                $c->table('archives')->whereIn('id_archive', $chunk)->delete();
            }
        });
    }

    /** Snapshot baris yang ada (archives + archive_locations + archive_documents) untuk dipulihkan persis. */
    function q1_snap($t, array $ids)
    {
        $c = $t->db();

        return [
            'ids'  => $ids,
            'a'    => q1_rows($c->table('archives')->whereIn('id_archive', $ids)->orderBy('id_archive')->get()),
            'l'    => q1_rows($c->table('archive_locations')->whereIn('id_archive', $ids)->orderBy('id_archive')->orderBy('id_location')->get()),
            'd'    => q1_rows($c->table('archive_documents')->whereIn('id_archive', $ids)->orderBy('id_archive_document')->get()),
        ];
    }

    function q1_restore($t, array $snap)
    {
        q1_w($t, function ($c) use ($snap) {
            $ids = $snap['ids'];
            $c->table('archive_documents')->whereIn('id_archive', $ids)->delete();
            $c->table('archive_locations')->whereIn('id_archive', $ids)->delete();
            $c->table('archives')->whereIn('id_archive', $ids)->delete();
            if ($snap['a']) {
                $c->table('archives')->insert($snap['a']);
            }
            if ($snap['l']) {
                $c->table('archive_locations')->insert($snap['l']);
            }
            if ($snap['d']) {
                $c->table('archive_documents')->insert($snap['d']);
            }
        });
    }

    /** Dua snapshot sama persis? */
    function q1_same(array $a, array $b)
    {
        return json_encode($a) === json_encode($b);
    }

    // ------------------------------------------------------------------ request & pembacaan respons

    function q1_url($path, array $query = [])
    {
        return $path . ($query ? '?' . http_build_query($query) : '');
    }

    /** GET api/v5/document-archive/archives dengan query. */
    function q1_list($t, $s, array $query = [])
    {
        return $t->call($s, 'GET', q1_url('api/v5/document-archive/archives', $query));
    }

    /** Daftar nama (name[0].transaction_no) pada result.data. */
    function q1_names($r)
    {
        $names = [];
        foreach ($r[1]['result']['data'] ?? [] as $row) {
            $names[] = $row['name'][0]['transaction_no'] ?? null;
        }

        return $names;
    }

    function q1_ids($r)
    {
        return array_map(function ($row) {
            return $row['id_archive'];
        }, $r[1]['result']['data'] ?? []);
    }

    /** Ambil semua halaman daftar (maks $maxPages) sebagai [rows...]. */
    function q1_all_rows($t, $s, array $query, $pagination = 200, $maxPages = 6)
    {
        $rows = [];
        $page = 1;
        do {
            $r = q1_list($t, $s, $query + ['pagination' => $pagination, 'page' => $page]);
            $t->status($r, 200, 'list halaman ' . $page);
            foreach ($r[1]['result']['data'] ?? [] as $row) {
                $rows[] = $row;
            }
            $last = (int) ($r[1]['result']['last_page'] ?? 1);
            $page++;
        } while ($page <= $last && $page <= $maxPages);

        return [$rows, $last, $r[1]['result']['total'] ?? null];
    }

    /** Hitung independen (dari DB) baris aktif di bawah $parent (null = root) yang terlihat untuk $locationIds (null = semua). */
    function q1_expected_count($t, $parent, $locationIds)
    {
        $q = $t->db()->table('archives')->where('is_active', '>', 0);
        $parent === null ? $q->whereNull('id_archive_parent') : $q->where('id_archive_parent', $parent);

        if ($locationIds !== null) {
            $q->where(function ($w) use ($locationIds) {
                $w->where('is_all_location', 1);
                if ($locationIds) {
                    $w->orWhereExists(function ($e) use ($locationIds) {
                        $e->selectRaw('1')->from('archive_locations')
                            ->whereColumn('archive_locations.id_archive', 'archives.id_archive')
                            ->whereIn('archive_locations.id_location', $locationIds);
                    });
                }
            });
        }

        return $q->count();
    }

    /** Baris archive dari DB (array) atau null. */
    function q1_row($t, $id)
    {
        $row = $t->db()->table('archives')->where('id_archive', $id)->first();

        return $row ? (array) $row : null;
    }

    function q1_id_by_name($t, $name, $parent = false)
    {
        $q = $t->db()->table('archives')->where('name', $name);
        if ($parent !== false) {
            $parent === null ? $q->whereNull('id_archive_parent') : $q->where('id_archive_parent', $parent);
        }

        return $q->orderBy('id_archive')->value('id_archive');
    }

    function q1_loc_codes($t, $idArchive)
    {
        $ids = $t->db()->table('archive_locations')->where('id_archive', $idArchive)->pluck('id_location')->all();
        $map = array_flip(q1_locs($t));
        $codes = array_map(function ($id) use ($map) {
            return $map[$id] ?? $id;
        }, $ids);
        sort($codes);

        return $codes;
    }

    /** Pastikan tidak ada kode ID/EN pesan yang bocor sebagai kunci mentah dan status bukan 5xx. */
    function q1_no500($t, $r, $label)
    {
        $t->true($r[0] < 500, $label . ': HTTP ' . $r[0] . ' (tidak boleh 5xx)');
    }

    function q1_code($r)
    {
        return $r[1]['code'] ?? $r[1]['msg_code'] ?? null;
    }

    /** Id (dari $ids) yang TIDAK terlihat oleh user berlokasi $locIds (null = semua terlihat); hitung independen dari DB. */
    function q1_hidden_ids($t, array $ids, $locIds)
    {
        if ($locIds === null || !$ids) {
            return [];
        }

        $hidden = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $q = $t->db()->table('archives')->whereIn('id_archive', $chunk)
                ->whereRaw('coalesce(archives.is_all_location, 0) <> 1')
                ->whereNotExists(function ($e) use ($locIds) {
                    $e->selectRaw('1')->from('archive_locations')
                        ->whereColumn('archive_locations.id_archive', 'archives.id_archive')
                        ->whereIn('archive_locations.id_location', $locIds ?: ['__none__']);
                });
            $hidden = array_merge($hidden, $q->pluck('id_archive')->all());
        }

        return $hidden;
    }

    /** Lokasi (id) dari kode, untuk q1_expected_count / q1_hidden_ids. */
    function q1_loc_ids($t, array $codes)
    {
        $map = q1_locs($t);

        return array_map(function ($code) use ($map) {
            return $map[$code];
        }, $codes);
    }

    /** Tulis sembarang ke koneksi uji: update baris archive (kolom => nilai) untuk id tertentu. */
    function q1_set_archive($t, $id, array $cols)
    {
        q1_w($t, function ($c) use ($id, $cols) {
            $c->table('archives')->where('id_archive', $id)->update($cols);
        });
    }

    /** Ganti tag lokasi suatu baris archive (kode lokasi). */
    function q1_set_locs($t, $id, array $codes)
    {
        $locs = q1_locs($t);
        q1_w($t, function ($c) use ($id, $codes, $locs) {
            $c->table('archive_locations')->where('id_archive', $id)->delete();
            foreach ($codes as $code) {
                $c->table('archive_locations')->insert(['id_archive' => $id, 'id_location' => $locs[$code]]);
            }
        });
    }

    /** Tidak ada sisa baris archive berawalan QA01- (dan tanpa dokumen/lokasi yatim). */
    function q1_assert_clean($t, $label = 'data uji sudah dibuang')
    {
        $t->eq($t->db()->table('archives')->where('name', 'like', Q1_PREFIX . '%')->count(), 0, $label);
        $t->eq($t->db()->table('archive_documents')->where('transaction_no', 'like', Q1_PREFIX . '%')->count(), 0, $label . ' (archive_documents)');
    }


    // ------------------------------------------------------------------ simulasi "tanpa lisensi Salesman Activity"

    function q1_perm_journal()
    {
        return __DIR__ . '/.perm-journal.json';
    }

    function q1_perm_restore($t, array $rows)
    {
        q1_w($t, function ($c) use ($rows) {
            $c->table('permissions')->whereBetween('id_permission', [1081, 1090])->delete();
            $c->table('permissions')->insert($rows);
        });
    }

    /**
     * Simulasi tenant tanpa seed permission Archive: baris permissions 1081-1090 dihapus sementara
     * (persis seperti bila permission_salesman.sql tidak dimuat), dipulihkan persis di finally.
     */
    function q1_without_archive_permissions($t, callable $fn)
    {
        if (is_file(q1_perm_journal())) {
            $old = json_decode(file_get_contents(q1_perm_journal()), true);
            if (is_array($old) && $old) {
                q1_perm_restore($t, $old);
            }
            @unlink(q1_perm_journal());
        }

        $rows = q1_rows($t->db()->table('permissions')->whereBetween('id_permission', [1081, 1090])->orderBy('id_permission')->get());
        $t->eq(count($rows), 10, 'prasyarat: 10 baris permissions modul Archive ada');
        file_put_contents(q1_perm_journal(), json_encode($rows));

        try {
            q1_w($t, function ($c) {
                $c->table('permissions')->whereBetween('id_permission', [1081, 1090])->delete();
            });

            return $fn();
        } finally {
            q1_perm_restore($t, $rows);
            $after = q1_rows($t->db()->table('permissions')->whereBetween('id_permission', [1081, 1090])->orderBy('id_permission')->get());
            if (json_encode($after) !== json_encode($rows)) {
                $t->fail('pemulihan permissions Archive tidak persis (jurnal dipertahankan)');
            }
            @unlink(q1_perm_journal());
        }
    }

    define('Q1_RANDOM_ID', '999999999999999999999999999999');
}
