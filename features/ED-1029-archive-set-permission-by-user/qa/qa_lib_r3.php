<?php
/**
 * Helper rev3 ED-1029 (BUKAN skenario: nama berkas tidak cocok scenario*.php). Di-require_once oleh scenario7/8/9.
 * Memakai qa_lib.php (fixture QA29-, role fixture QA29-ROLE-, jurnal pemulihan user). Rev3 menambah:
 *  - body PUT dengan `folders` (saklar Izin Folder) + helper baca flag/baris/riwayat;
 *  - fixture F/G/H/K/X/Y/Z (folder Off), J (data FB-9), P/C1/C2/Q/R/R1/L (spec § 6);
 *  - pemanggil paralel dengan banyak sesi (r3_par) dan model serial untuk kasus konkurensi (AC-37).
 * Tanpa id permission literal (AC-20 memeriksa semua berkas qa/*.php).
 */
require_once __DIR__ . '/qa_lib.php';

if (!function_exists('r3_body')) {

    /** Body PUT user-permissions; $folders === null = tanpa kunci `folders` (perilaku lama). */
    function r3_body(array $perms, $folders = null)
    {
        $b = ['permissions' => $perms];
        if ($folders !== null) {
            $b['folders'] = $folders;
        }

        return $b;
    }

    function r3_fl($idArchive, $v)
    {
        return ['id_archive' => $idArchive, 'is_folder_permission' => $v];
    }

    function r3_flag($t, $id)
    {
        $r = q9_row($t, $id);

        return $r === null ? null : (int) $r['is_folder_permission'];
    }

    /** Nilai (folder, user) sebagai JSON [v,u,d,s] atau 'none'. */
    function r3_vec($t, $idArchive, $idUser)
    {
        $m = q9_user_map($t, $idUser, [$idArchive]);

        return isset($m[$idArchive]) ? json_encode($m[$idArchive]) : 'none';
    }

    function r3_row_count($t, $idArchive, $idUser)
    {
        return $t->db()->table('archive_permissions')->where('id_archive', $idArchive)->where('id_user', $idUser)->count();
    }

    function r3_total_rows($t, $idArchive)
    {
        return $t->db()->table('archive_permissions')->where('id_archive', $idArchive)->count();
    }

    function r3_row_id($t, $idArchive, $idUser)
    {
        return $t->db()->table('archive_permissions')->where('id_archive', $idArchive)->where('id_user', $idUser)->value('id_archive_permission');
    }

    function r3_row_full($t, $idArchive, $idUser)
    {
        $r = $t->db()->table('archive_permissions')->where('id_archive', $idArchive)->where('id_user', $idUser)->first();

        return $r ? (array) $r : null;
    }

    /** Semua baris folder: [id_user => '[v,u,d,s]'] urut id_user. */
    function r3_rows($t, $idArchive)
    {
        $out = [];
        foreach ($t->db()->table('archive_permissions')->where('id_archive', $idArchive)->get() as $r) {
            $out[(string) $r->id_user] = json_encode([(int) $r->is_view, (int) $r->is_update, (int) $r->is_delete, (int) $r->is_store]);
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    function r3_last_hist($t, $id)
    {
        $h = q9_history($t, $id);

        return $h ? end($h) : null;
    }

    function r3_set_flag($t, $id, $v)
    {
        q9_set_archive($t, $id, ['is_folder_permission' => $v]);
    }

    /** Hapus baris folder (semua user atau hanya $users) lewat DB. */
    function r3_clear_rows($t, $id, array $users = null)
    {
        q9_w($t, function ($c) use ($id, $users) {
            $q = $c->table('archive_permissions')->where('id_archive', $id);
            if ($users !== null) {
                $q->whereIn('id_user', $users);
            }
            $q->delete();
        });
    }

    /** Keadaan awal folder: flag + baris persis (rows = [id_user => [v,u,d,s]]). */
    function r3_reset($t, $id, $flag, array $rows = [])
    {
        r3_set_flag($t, $id, $flag);
        r3_clear_rows($t, $id);
        foreach ($rows as $user => $v) {
            q9_perm($t, $id, $user, $v[0], $v[1], $v[2], $v[3]);
        }
    }

    function r3_ctx_a(array $roles)
    {
        return ['role' => $roles['A'], 'emp' => ['SMR']];
    }

    /**
     * Fixture rev3 (spec § 6): q9_fixture (P, C1, C2, PX, DP, Q, R, R1, QC, N, L, LC, M) + data J (J, JA, JB, JC) + folder Off baru:
     * F, H, H2, K, Z, W (Off, tanpa baris), G (Off, A View saja), X (Off, pembuat = A, tanpa baris), Y (Off, A sudah 1/1/1/1).
     */
    function r3_fixture($t, $meId, $meName, $bId)
    {
        $f = q9_fixture($t, $meId, $meName, $bId);
        $f += q9_fixture_j($t, $meId);
        foreach (['F', 'H', 'H2', 'K', 'Z', 'W'] as $label) {
            $f[$label] = q9_folder($t, $label, ['perm' => 0]);
        }
        $f['G'] = q9_folder($t, 'G', ['perm' => 0]);
        $f['X'] = q9_folder($t, 'X', ['perm' => 0, 'by' => $meName]);
        $f['Y'] = q9_folder($t, 'Y', ['perm' => 0]);
        q9_perm($t, $f['G'], $meId, 1);
        q9_perm($t, $f['Y'], $meId, 1, 1, 1, 1);

        return $f;
    }

    /** Pembersihan akhir: data uji dan role fixture sudah dibuang, jumlah baris izin kembali. */
    function r3_clean($t, $base)
    {
        q9_assert_clean($t, $base);
        $t->eq($t->db()->table('roles')->where('role_name', 'like', Q9_ROLE_PREFIX . '%')->count(), 0, 'role fixture dibuang');
    }

    /** Data (archives + izin + lokasi + dokumen) folder-folder $ids sama persis dengan $before dan jumlah baris izin sama. */
    function r3_unchanged($t, array $ids, array $before, $permBefore, $label)
    {
        $t->true(q9_same($before, q9_snap($t, $ids)), "$label: data tidak berubah (archives, riwayat, izin)");
        $t->eq(q9_perm_total($t), $permBefore, "$label: jumlah baris izin tetap");
    }

    function r3_node($t, $s, $target, $idArchive)
    {
        $r = q9_get($t, $s, $target);
        $t->status($r, 200, "GET user-permissions/{$target}");
        $flat = q9_flatten($r[1]['result']['data']);

        return $flat[$idArchive] ?? null;
    }

    /** Respons 200 ARCHIVE207 dengan result.id_user = $idUser. */
    function r3_ok($t, $r, $idUser, $label)
    {
        $t->status($r, 200, $label);
        $t->eq(q9_code($r), 'ARCHIVE207', "$label: code");
        $t->eq($r[1]['result']['id_user'] ?? null, $idUser, "$label: result.id_user");
    }

    /**
     * Kirim semua request bersamaan (curl_multi) dengan sesi per request. $reqs = [[session, method, uri, body|null], ...].
     * Hasil searah: [[http, json|null, detik], ...].
     */
    function r3_par($t, array $reqs)
    {
        $base = rtrim($t->conf('API_URL'), '/') . '/';
        $mh = curl_multi_init();
        $handles = [];
        foreach ($reqs as $i => $rq) {
            [$sess, $method, $uri, $body] = $rq;
            $ch = curl_init($base . $uri);
            $opts = [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $sess->token],
            ];
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            }
            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.2);
        } while ($running > 0);

        $out = [];
        foreach ($handles as $i => $ch) {
            $raw = curl_multi_getcontent($ch);
            $j = json_decode((string) $raw, true);
            $out[$i] = [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), is_array($j) ? $j : null, (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return $out;
    }

    // ------------------------------------------------------------------ model serial untuk AC-37

    /**
     * Terapkan SATU request ke keadaan $state = [folder => ['flag' => 0/1, 'rows' => [user => '[v,u,d,s]']]] persis seperti kontrak:
     *  kind 'up'  : PUT user-permissions/{target} oleh 'login'; ops = [folder => ['flag' => 0/1/null, 'perm' => [v,u,d,s]/null]]
     *  kind 'tab' : PUT archives/{folder} oleh tab Izin; 'flag' 0/1/null, 'rows' = [user => [v,u,d,s]] (mengganti SEMUA baris) / null
     * Hasil: [state baru, http] (441 -> state tidak berubah, 400).
     */
    function r3_sim_apply(array $state, array $req)
    {
        if ($req['kind'] === 'tab') {
            $f = $req['folder'];
            if (($req['flag'] ?? null) !== null) {
                $state[$f]['flag'] = $req['flag'];
            }
            if (($req['rows'] ?? null) !== null) {
                $rows = [];
                foreach ($req['rows'] as $u => $v) {
                    if (array_sum($v) > 0) {
                        $rows[$u] = json_encode($v);
                    }
                }
                ksort($rows, SORT_STRING);
                $state[$f]['rows'] = $rows;
            }

            return [$state, 200];
        }

        $stored = [];
        foreach ($req['ops'] as $f => $op) {
            $stored[$f] = $state[$f]['flag'];
        }
        foreach ($req['ops'] as $f => $op) {
            if (($op['perm'] ?? null) !== null && !$stored[$f] && ($op['flag'] ?? null) !== 1) {
                return [$state, 400];
            }
        }
        foreach ($req['ops'] as $f => $op) {
            if (($op['flag'] ?? null) !== null) {
                if ($op['flag'] === 1 && !$stored[$f] && $req['login'] !== $req['target']) {
                    $state[$f]['rows'][$req['login']] = json_encode([1, 1, 1, 1]);
                }
                $state[$f]['flag'] = $op['flag'];
            }
        }
        foreach ($req['ops'] as $f => $op) {
            if (($op['perm'] ?? null) !== null) {
                if (array_sum($op['perm']) === 0) {
                    unset($state[$f]['rows'][$req['target']]);
                } else {
                    $state[$f]['rows'][$req['target']] = json_encode($op['perm']);
                }
            }
        }
        foreach ($state as $f => $x) {
            ksort($state[$f]['rows'], SORT_STRING);
        }

        return [$state, 200];
    }

    /** Semua urutan serial: himpunan string "keadaan akhir | status per request" yang sah. */
    function r3_sim_outcomes(array $initial, array $reqs)
    {
        $n = count($reqs);
        $out = [];
        $walk = function (array $order, array $left) use (&$walk, &$out, $initial, $reqs, $n) {
            if (!$left) {
                $state = $initial;
                $status = array_fill(0, $n, 0);
                foreach ($order as $i) {
                    [$state, $status[$i]] = r3_sim_apply($state, $reqs[$i]);
                }
                $out[json_encode($state) . '|' . json_encode($status)] = true;

                return;
            }
            foreach ($left as $k => $i) {
                $rest = $left;
                unset($rest[$k]);
                $walk(array_merge($order, [$i]), $rest);
            }
        };
        $walk([], range(0, $n - 1));

        return $out;
    }

    /** Keadaan folder-folder dari DB, kanonik seperti r3_sim_apply: [folder => ['flag', 'rows']]. */
    function r3_db_state($t, array $folders)
    {
        $state = [];
        foreach ($folders as $f) {
            $state[$f] = ['flag' => r3_flag($t, $f), 'rows' => r3_rows($t, $f)];
        }

        return $state;
    }

    /** Request HTTP dari spesifikasi model. $who = ['A' => [sesi, id_user], ...]. */
    function r3_req_http(array $req, array $who)
    {
        $sess = $who[$req['login']][0];
        if ($req['kind'] === 'tab') {
            $body = [];
            if (($req['flag'] ?? null) !== null) {
                $body['is_folder_permission'] = $req['flag'];
            }
            if (($req['rows'] ?? null) !== null) {
                $body['folder_permissions'] = [];
                foreach ($req['rows'] as $user => $v) {
                    $body['folder_permissions'][] = ['id_user' => $user, 'is_view' => $v[0], 'is_update' => $v[1], 'is_delete' => $v[2], 'is_store' => $v[3]];
                }
            }

            return [$sess, 'PUT', 'api/v5/document-archive/archives/' . $req['folder'], $body];
        }

        $perms = [];
        $flags = [];
        foreach ($req['ops'] as $f => $op) {
            if (($op['perm'] ?? null) !== null) {
                $perms[] = q9_row_body($f, $op['perm'][0], $op['perm'][1], $op['perm'][2], $op['perm'][3]);
            }
            if (($op['flag'] ?? null) !== null) {
                $flags[] = r3_fl($f, $op['flag']);
            }
        }

        return [$sess, 'PUT', 'api/v5/document-archive/user-permissions/' . rawurlencode($req['target']), r3_body($perms, $flags ?: null)];
    }
}
