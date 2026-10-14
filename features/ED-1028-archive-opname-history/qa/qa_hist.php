<?php
/**
 * Helper riwayat opname ED-1028 (BUKAN skenario). Memakai qa_lib.php (turunan helper ED-1027, awalan QA28-).
 *
 * - q28_hist / q28_hids / q28_hrow: panggil GET opnames dan baca hasilnya. (rev) 'pagination' => 100 TANPA 'page' = ambil SEMUA
 *   halaman (data developer boleh ada; sesi QA ada di antara sesi asing). q28_hkeys membuang id asing (bukan sesi fixture QA).
 * - q28_u2_*: QA_USER2 sungguhan (adi, non-karyawan, lokasi SMR). (ED-1149 BR-1/BR-4) q28_guard() mengganti user_roles QA_USER2 SEMENTARA ke
 *   preset QA28-ROLE-R0 (akses website + List Archive, TANPA Opname Document; BUKAN role 5) lalu (rev, K-8 a) memberinya permission
 *   Opname Document (per nama, grant sementara ke PRESET, berjurnal) selama AC; AC-14/AC-28 memakai ['od' => false] (R0 = preset tanpa
 *   OD; "tanpa permission Archive" = preset QA28-ROLE-NONE, bukan role 22). Baris user_roles asli QA_USER2 dipulihkan persis di akhir.
 * - q28_hfix: fixture sesi terkonfirmasi (dibuat lewat API opname ED-1026 oleh superadmin sementara = role 1; satu POST opnames
 *   per sesi), waktu & pemilik diatur lewat DB supaya urutan/rentang deterministik; waktu di atas semua sesi developer (q28_day).
 * - q28_oracle_sessions / q28_members: oracle independen (SQL mentah + PHP) untuk sesi terlihat & "mencakup folder".
 */
require_once __DIR__ . '/qa_lib.php';

if (!function_exists('q28_hist')) {

    define('Q28_ALL', 100); // 'pagination' => Q28_ALL tanpa 'page' = ambil semua halaman

    /**
     * GET opnames; $q['search'] berupa array di-JSON-kan. 'pagination' => 100 tanpa 'page': halaman 1..last_page digabung ke
     * result.data (total/last_page dari halaman 1), supaya sesi QA tetap terlihat walau ada sesi developer; respons non-200 apa adanya.
     */
    function q28_hist($t, $s, array $q = [])
    {
        if (isset($q['search']) && is_array($q['search'])) {
            $q['search'] = json_encode($q['search']);
        }

        $r = $t->call($s, 'GET', q28_url(Q28_BASE . '/opnames', $q));
        if (($q['pagination'] ?? null) === Q28_ALL && !isset($q['page']) && $r[0] === 200 && isset($r[1]['result']['last_page'])) {
            $res = $r[1]['result'];
            for ($page = 2; $page <= (int) $res['last_page'] && $page <= 600; $page++) {
                $n = $t->call($s, 'GET', q28_url(Q28_BASE . '/opnames', $q + ['page' => $page]));
                if ($n[0] !== 200) {
                    return $n;
                }
                $res['data'] = array_merge($res['data'], $n[1]['result']['data'] ?? []);
            }
            $r[1]['result'] = $res;
        }

        return $r;
    }

    function q28_hids($r)
    {
        return array_column($r[1]['result']['data'] ?? [], 'id_archive_opname');
    }

    /** id sesi -> kunci fixture ('A', 'B', ...); id yang bukan sesi fixture QA (sesi developer) DIBUANG (asersi dilingkup ke sesi QA). */
    function q28_hkeys(array $ids, array $names)
    {
        $out = [];
        foreach ($ids as $id) {
            $k = array_search($id, $names, true);
            if ($k !== false) {
                $out[] = $k;
            }
        }

        return $out;
    }

    /** Jumlah id sesi fixture QA di $ids (sesi asing tidak dihitung). */
    function q28_hcount(array $ids, array $names)
    {
        return count(q28_hkeys($ids, $names));
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

    /** id_role user2 sekarang (dibaca dari DB). */
    function q28_u2_roles_now($t)
    {
        return $t->db()->table('user_roles')->where('id_user', q28_u2_id($t))->pluck('id_role')->all();
    }

    function q28_u2_snapshot($t)
    {
        return q28_rows($t->db()->table('user_roles')->where('id_user', q28_u2_id($t))->orderBy('id_user_role')->get());
    }

    function q28_u2_write($t, $uid, array $rows)
    {
        q28_w($t, function ($c) use ($uid, $rows) {
            $c->table('user_roles')->where('id_user', $uid)->delete();
            if ($rows) {
                $c->table('user_roles')->insert($rows);
            }
        });
    }

    /** QA_USER2 HANYA di preset $key (kunci preset; role developer tidak diterima). Jurnal tetap memegang user_roles ASLI. Mengembalikan id preset. */
    function q28_u2_use($t, $key)
    {
        $uid = q28_u2_id($t);
        $role = q28_preset($t, $key);
        $now = date('Y-m-d H:i:s');
        q28_u2_write($t, $uid, [['id_user' => $uid, 'id_role' => $role, 'is_all_location' => 1, 'id_location' => null, 'created_at' => $now, 'updated_at' => $now]]);

        return $role;
    }

    /** Pulihkan user_roles ke baris $snap (daftar baris). */
    function q28_u2_restore($t, array $snap)
    {
        q28_u2_write($t, q28_u2_id($t), $snap);
    }

    /** Pulihkan user_roles ASLI QA_USER2 dari jurnal bila run sebelumnya mati di tengah (juga bentuk jurnal lama = daftar baris). */
    function q28_u2_recover($t)
    {
        if (is_file(q28_u2_journal())) {
            $snap = json_decode(file_get_contents(q28_u2_journal()), true);
            if (is_array($snap) && !empty($snap['rows'])) {
                q28_u2_write($t, $snap['uid'], $snap['rows']);
            } elseif (is_array($snap) && $snap && isset($snap[0]['id_user'])) {
                q28_u2_write($t, $snap[0]['id_user'], $snap);
            }
            @unlink(q28_u2_journal());
        }
    }

    // ------------------------------------------------------------------ fixture sesi

    /**
     * Waktu tetap untuk sesi fixture: kunci => 'Y-m-d H:i:s'. (rev) Relatif terhadap acuan q28_now (10 hari di atas sesi developer
     * terbaru): A = 9 hari sebelum acuan ... Z = 3 hari sebelum acuan, jadi semua sesi QA lebih baru daripada sesi developer.
     */
    function q28_times()
    {
        $d = function ($days, $hms) {
            return q28_day($days) . ' ' . $hms;
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
     * Sesi terkonfirmasi fixture (dibuat superadmin; panggil di dalam q28_with_user role 1; tiap sesi = satu POST opnames), lalu waktu
     * & pemilik diatur:
     *   A = F + S1 (S1a ikut), 2 scan sah + 1 invalid + 1 not found   pemilik qa28alice   hari -9
     *   B = S2                                                          pemilik qa28bob     hari -8
     *   C = root + OTH                                                  pemilik qa28alice   hari -7
     *   D = SJ (lokasi JOG saja)                                        pemilik qa28carol   hari -6
     *   E = SP (folder-permission, user2 tanpa View)                    pemilik qa28bob     hari -5
     *   Y = S1a                                                         pemilik qa28_under  hari -4
     *   Z = OTH                                                         pemilik QA_USER     hari -3
     * (rev, FB-3) tidak ada lagi draft H / sesi batal K.
     * $extras: kunci => ['run' => callable (membuat sesi tambahan, mengembalikan id), 'at' => 'Y-m-d H:i:s'|null, 'owner' => string|null].
     * Sesi tambahan dibuat SEBELUM confirmed_at semua sesi digeser (bentrok ARCHIVE414 membandingkan confirmed_at >= selected_at);
     * sesi yang beririsan dengan fixture TIDAK boleh dibuat sesudah q28_hfix().
     * @return array kunci => id sesi
     */
    function q28_hfix($t, $s, array $x, array $extras = [])
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
        foreach ($extras as $k => $spec) {
            $ids[$k] = $spec['run']();
        }

        foreach (q28_times() as $k => $when) {
            q28_set_opname($t, $ids[$k], ['confirmed_at' => $when]);
        }
        $owners = ['A' => 'qa28alice', 'B' => 'qa28bob', 'C' => 'qa28alice', 'D' => 'qa28carol', 'E' => 'qa28bob', 'Y' => 'qa28_under'];
        foreach ($owners as $k => $name) {
            q28_set_owner($t, $ids[$k], $name);
        }
        foreach ($extras as $k => $spec) {
            $cols = [];
            if (!empty($spec['at'])) {
                $cols['confirmed_at'] = $spec['at'];
            }
            if (!empty($spec['owner'])) {
                $cols['created_by'] = $spec['owner'];
            }
            if ($cols) {
                q28_set_opname($t, $ids[$k], $cols);
            }
        }

        return $ids;
    }

    /**
     * Dua sesi dari folder yang kemudian dihapus fisik (G1 milik qa28erin hari -2, G2 milik user uji sendiri hari -1). Folder G1F/G2F
     * baru dan tidak beririsan dengan fixture, jadi aman dibuat sesudah q28_hfix().
     */
    function q28_add_deleted($t, $s, array &$x, array &$ids)
    {
        $x['G1F'] = q28_folder($t, 'G1F');
        $x['G2F'] = q28_folder($t, 'G2F');
        $ids['G1'] = q28_run($t, $s, $x['G1F'], [], []);
        $ids['G2'] = q28_run($t, $s, $x['G2F'], [], []);
        q28_set_opname($t, $ids['G1'], ['confirmed_at' => q28_day(2) . ' 09:00:00', 'created_by' => 'qa28erin']);
        q28_set_opname($t, $ids['G2'], ['confirmed_at' => q28_day(1) . ' 09:00:00']);
        q28_w($t, function ($c) use ($x) {
            $c->table('archives')->whereIn('id_archive', [$x['G1F'], $x['G2F']])->delete();
        });
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

    /**
     * Oracle ringkasan sesi (BR-16, D-1) dari tabel mentah untuk pemanggil $u (bentuk q28_uu): baris snapshot yang boleh ditampilkan
     * (superadmin: semua; selain itu root `id_archive` NULL + folder terlihat saat ini), warnings (level 0-1, bukan is_continue,
     * jumlah unverified_count baris terlihat yang berkelompok ke baris itu lewat id_archive_parent snapshot; 0 = tak dikirim)
     * dan tally baris dokumen yang boleh dilihat (result 1 verified, 2 not found, 3 invalid, 4 unscanned) + jumlah tersembunyi.
     * @return array{folders: array, warnings: array, hidden_folders: array, tally: array, hidden_docs: int}
     */
    function q28_summary_oracle($t, $idOpname, array $u)
    {
        $c = $t->db();
        $o = q28_oracle($t, $u);
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
            $map = q28_locs($t);
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

    /**
     * Awal-akhir AC: baseline (pemulihan sisa run yang mati, pra-citra, keadaan developer; presets lama dibuang), snapshot display
     * setting riwayat, QA_USER2 -> preset QA28-ROLE-R0 (jurnal memegang user_roles asli) + grant sementara Opname Document KE PRESET itu
     * (K-8 a; lewati dengan ['od' => false]), jalankan $fn, lalu pulihkan semuanya dan buktikan: user_roles QA_USER2, role_permissions,
     * display setting riwayat dan data developer sama persis dengan sebelum AC (role developer tidak pernah disentuh).
     */
    function q28_guard($t, callable $fn, array $opt = [])
    {
        q28_u2_recover($t);
        q28_baseline($t);
        $ds = q28_dsrow($t);
        $rpBefore = q28_rp_sum($t); // sebelum preset dibuat (q28_baseline membuang preset lama)
        $u2Orig = q28_u2_snapshot($t);
        $t->true(count($u2Orig) >= 1, 'prasyarat: QA_USER2 punya role (dipulihkan persis sesudah AC)');
        file_put_contents(q28_u2_journal(), json_encode(['uid' => q28_u2_id($t), 'rows' => $u2Orig]));
        $err = null;
        try {
            $roleR0 = q28_u2_use($t, Q28_PRESET_R0);
            if ($opt['od'] ?? true) {
                q28_grant($t, [$roleR0], Q28_OD);
            }

            return $fn($ds);
        } catch (\Throwable $e) {
            $err = $e;
            throw $e;
        } finally {
            try {
                q28_grant_recover($t);
                q28_u2_recover($t);
                q28_cleanup($t); // membuang preset QA28-ROLE-* dan membuktikan sisa = 0
                $t->eq(json_encode(q28_u2_snapshot($t)), json_encode($u2Orig), 'user_roles QA_USER2 dipulihkan persis (sama dengan sebelum AC)');
                $t->eq(json_encode(q28_rp_sum($t)), json_encode($rpBefore), 'role_permissions sama persis dengan sebelum AC (preset dibuang, tanpa sisa grant)');
                q28_ds_restore($t, $ds);
                $t->eq(json_encode(q28_dsrow($t)), json_encode($ds), 'display setting riwayat dipulihkan persis');
            } catch (\Throwable $c) {
                if ($err === null) {
                    throw $c;
                }
                $t->note('pembersihan juga gagal: ' . $c->getMessage());
            }
        }
    }
}
