<?php
/**
 * Helper ED-1026 (bagian 2, BUKAN skenario): request paralel (curl multi) dan oracle independen (SQL + PHP, tanpa kode BE).
 * Di-require_once oleh qa_lib.php.
 */

if (!function_exists('q26c_parallel')) {

    /** Kirim banyak request sekaligus (curl multi): [[method, path, body|null]] -> [[http, json]] searah. */
    function q26c_parallel($t, $s, array $reqs)
    {
        $base = rtrim($t->conf('API_URL'), '/');
        $mh = curl_multi_init();
        $handles = [];
        foreach ($reqs as $i => $r) {
            $ch = curl_init($base . '/' . $r[1]);
            $headers = ['Accept: application/json', 'Authorization: Bearer ' . $s->token];
            $opts = [CURLOPT_CUSTOMREQUEST => $r[0], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180];
            if (isset($r[2])) {
                $headers[] = 'Content-Type: application/json';
                $opts[CURLOPT_POSTFIELDS] = json_encode($r[2]);
            }
            $opts[CURLOPT_HTTPHEADER] = $headers;
            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.05);
            }
        } while ($running && $st === CURLM_OK);
        $out = [];
        foreach ($handles as $i => $ch) {
            $out[$i] = [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode(curl_multi_getcontent($ch), true)];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        $GLOBALS['q26_last'] = time();

        return $out;
    }

    // ------------------------------------------------------------------ oracle independen (SQL + PHP, tanpa kode BE)

    /**
     * Hitung dari DB (tanpa service BE): folder terlihat (aktif, lokasi) dan jumlah dokumen aktif terlihat per subtree.
     * $locCodes null = lihat semua (superadmin 1/2: tanpa lokasi, tanpa hak folder). Selain itu (rev ED-1025) folder dengan
     * is_folder_permission = 1 di jalur root -> folder hanya terlihat bila pembuatnya QA_USER (created_by) atau QA_USER punya
     * baris archive_permissions ber-View di folder itu (induk dominan); $withView false = abaikan hak folder.
     * @return array ['children' => [id => [name, count, has_children]], 'direct' => n (dokumen langsung $parent)]
     */
    function q26_oracle($t, $parent, $locCodes, $withView = true)
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

        $viewOk = null;
        if ($locCodes !== null && $withView) {
            $uname = q26_uname($t);
            $all = [];
            foreach ($c->table('archives')->where('type', 1)->get(['id_archive', 'id_archive_parent', 'is_folder_permission', 'created_by']) as $f) {
                $all[$f->id_archive] = $f;
            }
            $granted = array_flip($c->table('archive_permissions')->where('id_user', q26_uid($t))->where('is_view', 1)->pluck('id_archive')->all());
            $memo = [];
            $viewOk = function ($id) use (&$viewOk, &$memo, $all, $granted, $uname) {
                if (!isset($all[$id])) {
                    return true;
                }
                if (isset($memo[$id])) {
                    return $memo[$id];
                }
                $memo[$id] = false;
                $f = $all[$id];
                $ok = true;
                if ((int) $f->is_folder_permission === 1) {
                    $ok = ($f->created_by !== null && strcasecmp($f->created_by, $uname) === 0) || isset($granted[$f->id_archive]);
                }
                if ($ok && $f->id_archive_parent !== null) {
                    $ok = $viewOk($f->id_archive_parent);
                }

                return $memo[$id] = $ok;
            };
        }

        $children = [];
        $names = [];
        foreach ($c->table('archives')->where('type', 1)->where('is_active', '>', 0)->get(['id_archive', 'id_archive_parent', 'name', 'is_all_location']) as $f) {
            if (!$visible($f) || ($viewOk !== null && !$viewOk($f->id_archive))) {
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
