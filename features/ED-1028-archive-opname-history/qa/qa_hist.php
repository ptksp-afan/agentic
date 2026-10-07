<?php
/**
 * Helper riwayat opname ED-1028 (BUKAN skenario). Memakai qa_lib.php (turunan helper ED-1027, awalan QA28-).
 *
 * - q28_hist / q28_hids / q28_hrow: panggil GET opnames dan baca hasilnya.
 * - q28_u2_*: QA_USER2 sungguhan (role 5 = Sales Supervisor: List Archive TANPA Opname Document, lokasi SMR).
 * - q28_hfix: fixture sesi terkonfirmasi (dibuat lewat API opname ED-1026 oleh superadmin sementara = role 1),
 *   waktu & pemilik diatur lewat DB supaya urutan/rentang deterministik.
 * - q28_oracle_sessions / q28_members: oracle independen (SQL mentah + PHP) untuk sesi terlihat & "mencakup folder".
 */
require_once __DIR__ . '/qa_lib.php';

if (!function_exists('q28_hist')) {

    /** GET opnames; $q['search'] berupa array di-JSON-kan. */
    function q28_hist($t, $s, array $q = [])
    {
        if (isset($q['search']) && is_array($q['search'])) {
            $q['search'] = json_encode($q['search']);
        }

        return $t->call($s, 'GET', q28_url(Q28_BASE . '/opnames', $q));
    }

    function q28_hids($r)
    {
        return array_column($r[1]['result']['data'] ?? [], 'id_archive_opname');
    }

    /** id sesi -> kunci fixture ('A', 'B', ...), tanpa kunci = '?'. */
    function q28_hkeys(array $ids, array $names)
    {
        $out = [];
        foreach ($ids as $id) {
            $k = array_search($id, $names, true);
            $out[] = $k === false ? '?' : $k;
        }

        return $out;
    }

    // ------------------------------------------------------------------ QA_USER2 sungguhan

    function q28_u2_journal()
    {
        return __DIR__ . '/.restore-journal-q28-user2.json';
    }

    function q28_u2_id($t)
    {
        return $t->db()->table('users')->where('username', $t->conf('QA_USER2'))->value('id_user');
    }

    function q28_u2_name($t)
    {
        return (string) $t->db()->table('users')->where('username', $t->conf('QA_USER2'))->value('username');
    }

    /** Token user2 terikat DB QA (force_login hanya mencabut token user2 sendiri). */
    function q28_u2_session($t)
    {
        $s = $t->login('QA_USER2');
        $r = $t->bind($s, q28_dbname($t));
        $t->eq($r[0], 200, 'bind QA_USER2');

        return $s;
    }

    function q28_u2_snapshot($t)
    {
        return q28_rows($t->db()->table('user_roles')->where('id_user', q28_u2_id($t))->orderBy('id_user_role')->get());
    }

    function q28_u2_roles($t, array $roles)
    {
        $uid = q28_u2_id($t);
        q28_w($t, function ($c) use ($uid, $roles) {
            $now = date('Y-m-d H:i:s');
            $c->table('user_roles')->where('id_user', $uid)->delete();
            foreach ($roles as $role) {
                $c->table('user_roles')->insert(['id_user' => $uid, 'id_role' => $role, 'is_all_location' => 1, 'id_location' => null, 'created_at' => $now, 'updated_at' => $now]);
            }
        });
    }

    function q28_u2_restore($t, array $snap)
    {
        $uid = q28_u2_id($t);
        q28_w($t, function ($c) use ($uid, $snap) {
            $c->table('user_roles')->where('id_user', $uid)->delete();
            if ($snap) {
                $c->table('user_roles')->insert($snap);
            }
        });
    }

    function q28_u2_recover($t)
    {
        if (is_file(q28_u2_journal())) {
            $snap = json_decode(file_get_contents(q28_u2_journal()), true);
            if (is_array($snap) && $snap) {
                q28_u2_restore($t, $snap);
            }
            @unlink(q28_u2_journal());
        }
    }

    // ------------------------------------------------------------------ fixture sesi

    /** Waktu tetap (relatif hari ini) untuk sesi fixture: kunci => 'Y-m-d H:i:s'. */
    function q28_times()
    {
        $d = function ($days, $hms) {
            return date('Y-m-d', strtotime("-$days days")) . ' ' . $hms;
        };

        return [
            'A' => $d(9, '10:00:00'),
            'B' => $d(8, '11:30:15'),
            'C' => $d(7, '09:15:00'),
            'D' => $d(6, '16:45:30'),
            'E' => $d(5, '08:00:00'),
            'Y' => $d(4, '13:00:00'),
            'Z' => $d(3, '17:59:59'),
        ];
    }

    /**
     * Sesi terkonfirmasi fixture (dibuat superadmin; panggil di dalam q28_with_user role 1), lalu waktu & pemilik diatur:
     *   A = F + S1 (S1a ikut), 2 scan sah + 1 invalid + 1 not found   pemilik qa28alice   -9 hari
     *   B = S2                                                          pemilik qa28bob     -8 hari
     *   C = root + OTH                                                  pemilik qa28alice   -7 hari
     *   D = SJ (lokasi JOG saja)                                        pemilik qa28carol   -6 hari
     *   E = SP (folder-permission, user2 tanpa View)                    pemilik qa28bob     -5 hari
     *   Y = S1a                                                         pemilik qa28_under  -4 hari
     *   Z = OTH                                                         pemilik QA_USER     -3 hari
     * ditambah H = draft (milik qa28bob) dan K = sesi batal (milik QA_USER).
     * @return array kunci => id sesi
     */
    function q28_hfix($t, $s, array $x)
    {
        $N = function ($k) use ($t, $x) {
            return q28_n($t, $x[$k]);
        };
        $ids = [];
        $ids['A'] = q28_run($t, $s, $x['F'], q28_sel([$x['S1']]), [$N('dF2'), $N('d11'), 'QA28-TIDAK-ADA-' . uniqid(), $N('dO1')]);
        $ids['B'] = q28_run($t, $s, $x['S2'], [], [$N('d21')]);
        $ids['C'] = q28_run($t, $s, null, q28_sel([$x['OTH']]), [$N('dO1'), $N('dR')]);
        $ids['D'] = q28_run($t, $s, $x['SJ'], [], [$N('dj1')]);
        $ids['E'] = q28_run($t, $s, $x['SP'], [], [$N('dsp1')]);
        $ids['Y'] = q28_run($t, $s, $x['S1a'], [], [$N('dA1')]);
        $ids['Z'] = q28_run($t, $s, $x['OTH'], [], [$N('dO1')]);
        $ids['H'] = q28_session($t, $s, $x['F'], q28_sel([$x['S2']]));
        $ids['K'] = q28_session($t, $s, $x['S1a']);
        $t->status($t->call($s, 'DELETE', Q28_BASE . '/opnames/' . $ids['K']), 200, 'batalkan sesi K');

        foreach (q28_times() as $k => $when) {
            q28_set_opname($t, $ids[$k], ['confirmed_at' => $when]);
        }
        $owners = ['A' => 'qa28alice', 'B' => 'qa28bob', 'C' => 'qa28alice', 'D' => 'qa28carol', 'E' => 'qa28bob', 'Y' => 'qa28_under', 'H' => 'qa28bob'];
        foreach ($owners as $k => $name) {
            q28_set_owner($t, $ids[$k], $name);
        }

        return $ids;
    }

    // ------------------------------------------------------------------ oracle independen

    /** id sesi => [id_archive folder snapshot] (level 0-2) dari tabel mentah. */
    function q28_members($t, array $opnameIds)
    {
        $out = array_fill_keys($opnameIds, []);
        if (!$opnameIds) {
            return $out;
        }
        foreach ($t->db()->table('archive_opname_folders')->whereIn('id_archive_opname', $opnameIds)->get(['id_archive_opname', 'id_archive']) as $r) {
            $out[$r->id_archive_opname][] = $r->id_archive;
        }

        return $out;
    }

    /** Folder X + semua keturunannya yang terlihat (oracle['visible']); X tak terlihat = []. */
    function q28_vsubtree($t, array $visible, $idFolder)
    {
        if (!isset($visible[$idFolder])) {
            return [];
        }
        $parent = [];
        foreach ($t->db()->table('archives')->where('type', 1)->get(['id_archive', 'id_archive_parent']) as $r) {
            $parent[$r->id_archive] = $r->id_archive_parent;
        }
        $out = [];
        foreach (array_keys($visible) as $id) {
            $seen = [];
            for ($g = $id; $g !== null && !isset($seen[$g]); $g = $parent[$g] ?? null) {
                $seen[$g] = true;
                if ($g === $idFolder) {
                    $out[] = $id;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Sesi terkonfirmasi yang terlihat user $u (bentuk q28_u): superadmin semua; selain itu sesi dari root, buatan sendiri,
     * dan sesi yang punya baris folder (level 0-2) di folder terlihat. Opsi: 'cover' = [id folder,...] (mencakup salah satu
     * folder itu atau keturunan terlihatnya). Mengembalikan daftar id sesi (belum berurut).
     */
    function q28_oracle_sessions($t, array $u, array $cover = null)
    {
        $c = $t->db();
        $o = q28_oracle($t, $u);
        $rows = $c->table('archive_opnames')->where('status', 2)->get(['id_archive_opname', 'id_archive', 'created_by']);
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = $r->id_archive_opname;
        }
        $members = q28_members($t, $ids);

        $coverSet = null;
        if ($cover !== null) {
            $coverSet = [];
            foreach ($cover as $f) {
                foreach (q28_vsubtree($t, $o['visible'], $f) as $id) {
                    $coverSet[$id] = true;
                }
            }
        }

        $out = [];
        foreach ($rows as $r) {
            if (!$u['bypass']) {
                $mine = $r->created_by !== null && strcasecmp($r->created_by, $u['username']) === 0;
                $viaFolder = count(array_intersect($members[$r->id_archive_opname], array_keys($o['visible']))) > 0;
                if ($r->id_archive !== null && !$mine && !$viaFolder) {
                    continue;
                }
            }
            if ($coverSet !== null && count(array_intersect($members[$r->id_archive_opname], array_keys($coverSet))) === 0) {
                continue;
            }
            $out[] = $r->id_archive_opname;
        }

        return $out;
    }

    /** Keadaan user uji: superadmin (role 1), 'user3' (QA_USER role 3 semua lokasi), 'user2' (QA_USER2 SMR). */
    function q28_uu($t, $who)
    {
        if ($who === 'user2') {
            return ['username' => q28_u2_name($t), 'uid' => q28_u2_id($t), 'bypass' => false, 'locs' => ['SMR']];
        }
        if ($who === 'user3') {
            return ['username' => (string) q28_uname($t), 'uid' => q28_uid($t), 'bypass' => false, 'locs' => null];
        }

        return ['username' => (string) q28_uname($t), 'uid' => q28_uid($t), 'bypass' => true, 'locs' => null];
    }

    /** Ambil baris display setting riwayat dari DB. */
    function q28_dsrow($t)
    {
        $r = $t->db()->table('column_display_settings')->where('module_name', 'documentArchiveOpnameHistory')->first();

        return $r ? (array) $r : null;
    }

    /** Bentuk 'session_row' dari satu baris history (kunci kontrak last_sessions). */
    function q28_srow(array $row)
    {
        return array_intersect_key($row, array_flip(['id_archive_opname', 'confirmed_at', 'created_by', 'scope', 'total_documents', 'verified_count', 'not_found_count', 'invalid_count']));
    }

    /** Pulihkan baris display setting riwayat ke $snapshot (null = tidak ada baris). */
    function q28_ds_restore($t, $snapshot)
    {
        q28_w($t, function ($c) use ($snapshot) {
            $c->table('column_display_settings')->where('module_name', 'documentArchiveOpnameHistory')->delete();
            if ($snapshot !== null) {
                $c->table('column_display_settings')->insert($snapshot);
            }
        });
    }

    /** Awal-akhir AC: baseline bersih, snapshot display setting riwayat, jalankan $fn, lalu pulihkan semuanya. */
    function q28_guard($t, callable $fn)
    {
        q28_u2_recover($t);
        q28_baseline($t);
        $ds = q28_dsrow($t);
        try {
            return $fn($ds);
        } finally {
            q28_cleanup($t);
            q28_ds_restore($t, $ds);
            $t->eq(json_encode(q28_dsrow($t)), json_encode($ds), 'display setting riwayat dipulihkan persis');
        }
    }
}
