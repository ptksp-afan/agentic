<?php
/**
 * ED-1028 - select untuk query filter: AC-10 (GET select/document-archive/archive/folders) dan AC-11 (.../opname-users).
 * Folder: folder aktif dalam lokasi ∩ View user (oracle SQL mentah), label path, search nama, selected_id, urut label, superadmin semua.
 * User: username unik pembuat sesi terkonfirmasi yang terlihat pemanggil (termasuk superadmin bila pelaku), search, selected_id, urut.
 */
require_once __DIR__ . '/qa_hist.php';

define('Q28_SEL', 'api/v5/select/document-archive/archive');

function q28_sel_folders($t, $s, array $q = [])
{
    return $t->call($s, 'GET', q28_url(Q28_SEL . '/folders', $q));
}

function q28_sel_users($t, $s, array $q = [])
{
    return $t->call($s, 'GET', q28_url(Q28_SEL . '/opname-users', $q));
}

/** nilai => label dari respons select. */
function q28_selopts($r)
{
    $out = [];
    foreach ($r[1]['result']['options'] ?? [] as $o) {
        $out[$o['value']] = $o['label'];
    }

    return $out;
}

/** Path folder dari root, dihitung dari tabel mentah: "Induk / ... / Folder". */
function q28_path_oracle($t)
{
    $rows = [];
    foreach ($t->db()->table('archives')->where('type', 1)->get(['id_archive', 'id_archive_parent', 'name']) as $r) {
        $rows[$r->id_archive] = $r;
    }
    $path = [];
    foreach ($rows as $id => $r) {
        $names = [];
        $seen = [];
        for ($g = $r; $g !== null && !isset($seen[$g->id_archive]); $g = $g->id_archive_parent === null ? null : ($rows[$g->id_archive_parent] ?? null)) {
            $seen[$g->id_archive] = true;
            array_unshift($names, $g->name);
        }
        $path[$id] = implode(' / ', $names);
    }

    return $path;
}

return [

    [
        'id'    => 'AC-10',
        'title' => 'select archive/folders: user2/user3 hanya folder aktif dalam lokasi ∩ View (= oracle SQL; tanpa dokumen/nonaktif), label path, urut label, search nama (bukan path), selected_id ikut hanya bila dalam scope; superadmin semua folder aktif',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s) {
                $x = q28_tree($t);
                // nama kembar di dua induk berbeda
                $dupName = 'QA28-DUP-' . uniqid();
                $d1 = q28_add($t, ['name' => $dupName, 'type' => 1, 'parent' => $x['P']]);
                $d2 = q28_add($t, ['name' => $dupName, 'type' => 1, 'parent' => $x['OTH']]);
                $paths = q28_path_oracle($t);

                $check = function ($label, $session, array $u) use ($t, $x, $paths, $d1, $d2) {
                    $r = q28_sel_folders($t, $session, []);
                    $t0 = microtime(true);
                    $r = q28_sel_folders($t, $session, []);
                    $ms = round((microtime(true) - $t0) * 1000);
                    $t->status($r, 200, "$label: select folders");
                    $t->eq($r[1]['msg_code'] ?? null, 'SUCCESS', "$label: msg_code");
                    $t->true(array_key_exists('default', $r[1]['result']) && $r[1]['result']['default'] === null, "$label: default null");
                    $opts = q28_selopts($r);
                    $o = q28_oracle($t, $u);
                    // oracle: superadmin = semua folder aktif (is_active=1); lainnya = aktif + lokasi + View
                    if ($u['bypass']) {
                        $expect = $t->db()->table('archives')->where('type', 1)->where('is_active', 1)->pluck('id_archive')->all();
                    } else {
                        $expect = array_keys($o['visible']);
                    }
                    $got = array_keys($opts);
                    sort($expect);
                    sort($got);
                    $t->eq($got, $expect, "$label: himpunan folder = oracle (" . count($expect) . ' folder)');
                    $t->eq(count($got), count(array_unique($got)), "$label: tanpa duplikat");
                    // tanpa dokumen, tanpa nonaktif
                    $docs = $t->db()->table('archives')->where('type', 2)->whereIn('id_archive', $got)->count();
                    $t->eq($docs, 0, "$label: tidak ada dokumen di opsi");
                    $inactive = $t->db()->table('archives')->where('type', 1)->where('is_active', '!=', 1)->whereIn('id_archive', $got)->count();
                    $t->eq($inactive, 0, "$label: tidak ada folder nonaktif di opsi");
                    $t->true(!isset($opts[$x['SX']]), "$label: folder nonaktif SX tidak ada");
                    // label = path
                    foreach ($opts as $id => $lab) {
                        $t->eq($lab, $paths[$id], "$label: label path $id");
                    }
                    // urut label (tanpa beda huruf)
                    $labels = array_values($opts);
                    $sorted = $labels;
                    usort($sorted, 'strcasecmp');
                    $t->eq($labels, $sorted, "$label: urut label");
                    // nama kembar: dua opsi, label beda
                    if (isset($opts[$d1]) || isset($opts[$d2])) {
                        $t->true(isset($opts[$d1]) && isset($opts[$d2]) && $opts[$d1] !== $opts[$d2], "$label: dua folder bernama sama punya label path berbeda");
                    }
                    $t->note("$label: " . count($opts) . " opsi, $ms ms");

                    return $opts;
                };

                // --- superadmin (role 1) dan role 2
                q28_with_user($t, ['role' => 1], function ($setter) use ($t, $s, $x, $check, $d1, $d2, $paths, $dupName) {
                    $opts = $check('superadmin role 1', $s, q28_uu($t, 'super'));
                    foreach (['P', 'F', 'S1', 'S1a', 'S2', 'SJ', 'SP', 'SPc', 'SV', 'OTH', 'PRIV', 'PRc', 'JOGR'] as $k) {
                        $t->true(isset($opts[$x[$k]]), "superadmin: folder $k ada");
                    }
                    $t->true(isset($opts[$d1]) && isset($opts[$d2]), 'superadmin: kedua folder kembar ada');
                    $setter(['role' => 2]);
                    $check('superadmin role 2', $s, q28_uu($t, 'super'));
                    $setter(['role' => 1]);

                    // search nama (bukan path)
                    $N = function ($k) use ($t, $x) {
                        return q28_n($t, $x[$k]);
                    };
                    $r = q28_sel_folders($t, $s, ['search' => $N('F')]);
                    $t->eq(array_keys(q28_selopts($r)), [$x['F']], 'search nama F: hanya F (anak F tidak ikut: cocokkan nama, bukan path)');
                    $r = q28_sel_folders($t, $s, ['search' => strtoupper($N('S1'))]);
                    $t->eq(array_keys(q28_selopts($r)), [$x['S1']], 'search nama S1 huruf besar: hanya S1');
                    $r = q28_sel_folders($t, $s, ['search' => 'qa28-s1']);
                    $got = array_keys(q28_selopts($r));
                    $t->true(in_array($x['S1'], $got, true) && in_array($x['S1a'], $got, true), 'search awalan "qa28-s1": S1 dan S1a (mengandung, tanpa beda huruf)');
                    $r = q28_sel_folders($t, $s, ['search' => $dupName]);
                    $o = q28_selopts($r);
                    $t->eq(count($o), 2, 'search nama kembar: 2 opsi');
                    $expDup = [$paths[$d1], $paths[$d2]];
                    usort($expDup, 'strcasecmp');
                    $t->eq(array_values($o), $expDup, 'search nama kembar: label = path masing-masing, urut label');
                    $r = q28_sel_folders($t, $s, ['search' => 'zzz-tidak-ada-' . uniqid()]);
                    $t->status($r, 200, 'search tanpa hasil');
                    $t->eq($r[1]['result']['options'], [], 'search tanpa hasil: options []');
                    foreach (['日本語', "' OR 1=1 --", '%', '_', '\\', str_repeat('x', 2000)] as $odd) {
                        $r = q28_sel_folders($t, $s, ['search' => $odd]);
                        q28_no500($t, $r, 'search aneh ' . substr($odd, 0, 10));
                        $t->status($r, 200, 'search aneh ' . substr(json_encode($odd), 0, 20));
                    }
                    // selected_id: tetap ikut walau tak lolos search
                    $r = q28_sel_folders($t, $s, ['search' => $N('F'), 'selected_id' => $x['S2']]);
                    $got = array_keys(q28_selopts($r));
                    sort($got);
                    $e = [$x['F'], $x['S2']];
                    sort($e);
                    $t->eq($got, $e, 'selected_id (string) ikut walau tak lolos search');
                    $r = q28_sel_folders($t, $s, ['search' => $N('F'), 'selected_id' => [$x['S2'], $x['OTH']]]);
                    $got = array_keys(q28_selopts($r));
                    sort($got);
                    $e = [$x['F'], $x['S2'], $x['OTH']];
                    sort($e);
                    $t->eq($got, $e, 'selected_id (array) ikut');
                    $r = q28_sel_folders($t, $s, ['selected_id' => $x['SX']]);
                    $t->true(!isset(q28_selopts($r)[$x['SX']]), 'selected_id folder nonaktif tidak ikut');
                    $r = q28_sel_folders($t, $s, ['search' => $N('F'), 'selected_id' => $x['dF1']]);
                    $t->eq(array_keys(q28_selopts($r)), [$x['F']], 'selected_id dokumen tidak ikut');
                    $r = q28_sel_folders($t, $s, ['search' => $N('F'), 'selected_id' => '999999999999']);
                    $t->eq(array_keys(q28_selopts($r)), [$x['F']], 'selected_id tak ada tidak ikut');
                    // parameter bentuk salah: tanpa 500
                    foreach (['search[]=a', 'selected_id[][a]=b', 'selected_id[0][]=x'] as $raw) {
                        $r = $t->call($s, 'GET', Q28_SEL . '/folders?' . $raw);
                        q28_no500($t, $r, "select folders mentah $raw");
                        $t->true(in_array($r[0], [200, 422], true), "select folders mentah $raw: 200/422 (" . $r[0] . ')');
                    }
                    $setter([]);
                    $check('user3 (role 3)', $s, q28_uu($t, 'user3'));
                });

                // --- user2 sungguhan (SMR, bukan superadmin)
                $s2 = q28_u2_session($t);
                $opts = $check('user2', $s2, q28_uu($t, 'user2'));
                foreach (['P', 'F', 'S1', 'S1a', 'S2', 'OTH'] as $k) {
                    $t->true(isset($opts[$x[$k]]), "user2: folder $k ada");
                }
                foreach (['SJ', 'SP', 'SPc', 'SV', 'PRIV', 'PRc', 'JOGR', 'SX'] as $k) {
                    $t->true(!isset($opts[$x[$k]]), "user2: folder $k (lokasi lain / tanpa View / nonaktif) tidak ada");
                }
                $N = function ($k) use ($t, $x) {
                    return q28_n($t, $x[$k]);
                };
                $r = q28_sel_folders($t, $s2, ['search' => $N('PRIV')]);
                $t->eq($r[1]['result']['options'], [], 'user2: search nama PRIV (tanpa View) -> kosong');
                $r = q28_sel_folders($t, $s2, ['search' => $N('F'), 'selected_id' => [$x['PRIV'], $x['SJ'], $x['SP'], $x['S2']]]);
                $got = array_keys(q28_selopts($r));
                sort($got);
                $e = [$x['F'], $x['S2']];
                sort($e);
                $t->eq($got, $e, 'user2: selected_id di luar scope (PRIV, SJ, SP) tidak ikut; S2 (dalam scope) ikut');
                $r = q28_sel_folders($t, $s2, ['search' => $N('S1')]);
                $t->eq(array_keys(q28_selopts($r)), [$x['S1']], 'user2: search nama S1');
                $t->eq(array_values(q28_selopts(q28_sel_folders($t, $s2, ['search' => $N('S2')]))), [$paths[$x['S2']]], 'user2: label path S2');
                // pesan EN
                $t->eq(q28_sel_folders($t, $s2)[1]['message'], 'Success', 'user2: pesan SUCCESS bahasa EN');
                $t->call($s2, 'GET', 'api/v5/auth/log-out');
            });
        },
    ],

    [
        'id'    => 'AC-11',
        'title' => 'select archive/opname-users: username unik pembuat sesi terkonfirmasi yang terlihat pemanggil (superadmin bila pelaku; user tanpa sesi, draft-only, batal-only tidak muncul), search, selected_id, urut username',
        'run'   => function ($t) {
            $s = $t->session();
            q28_guard($t, function () use ($t, $s) {
                $x = null;
                $ids = null;
                q28_with_user($t, ['role' => 1], function () use ($t, $s, &$x, &$ids) {
                    $x = q28_tree($t);
                    $ids = q28_hfix($t, $s, $x);
                    // E milik qa28eve (E tak terlihat user2/user3); draft H & batal K milik user yang tak punya sesi terkonfirmasi
                    q28_set_owner($t, $ids['E'], 'qa28eve');
                    q28_set_owner($t, $ids['H'], 'qa28draftonly');
                    q28_set_owner($t, $ids['K'], 'qa28cancelonly');
                });
                $me = (string) q28_uname($t);
                $opts = function ($r) {
                    return array_keys(q28_selopts($r));
                };
                // urutan menurut DB (kolasi kolom): oracle SQL independen dari daftar sesi terlihat
                $sqlOrder = function (array $sessionIds) use ($t) {
                    return $t->db()->table('archive_opnames')->whereIn('id_archive_opname', $sessionIds ?: ['-'])->distinct()->orderBy('created_by')->pluck('created_by')->all();
                };

                $verify = function ($label, $session, array $u, array $expectSet) use ($t, $opts, $sqlOrder) {
                    $r = q28_sel_users($t, $session);
                    $t->status($r, 200, "$label: select opname-users");
                    $t->eq($r[1]['msg_code'] ?? null, 'SUCCESS', "$label: msg_code");
                    $t->true(array_key_exists('default', $r[1]['result']) && $r[1]['result']['default'] === null, "$label: default null");
                    $got = $opts($r);
                    $exp = $sqlOrder(q28_oracle_sessions($t, $u));
                    $t->eq($got, $exp, "$label: username unik + urut = oracle SQL (DISTINCT created_by sesi terlihat)");
                    $a = $got;
                    $b = $expectSet;
                    sort($a);
                    sort($b);
                    $t->eq($a, $b, "$label: himpunan = angka tangan " . implode(',', $expectSet));
                    foreach ($r[1]['result']['options'] as $o) {
                        $t->eq($o['value'], $o['label'], "$label: value = label ({$o['value']})");
                    }
                    $t->eq(count($got), count(array_unique($got)), "$label: tanpa duplikat");
                    foreach (['qa28draftonly', 'qa28cancelonly'] as $bad) {
                        $t->true(!in_array($bad, $got, true), "$label: $bad (hanya draft / batal) tidak muncul");
                    }

                    return $got;
                };

                // --- superadmin
                q28_with_user($t, ['role' => 1], function ($setter) use ($t, $s, $me, $verify, $opts) {
                    $got = $verify('superadmin', $s, q28_uu($t, 'super'), ['qa28alice', 'qa28bob', 'qa28carol', 'qa28eve', 'qa28_under', $me]);
                    $t->true(in_array($me, $got, true), 'superadmin pelaku sesi (Z) ikut di daftar');
                    $setter(['role' => 2]);
                    $verify('superadmin role 2', $s, q28_uu($t, 'super'), ['qa28alice', 'qa28bob', 'qa28carol', 'qa28eve', 'qa28_under', $me]);
                    $setter(['role' => 1]);

                    // search (mengandung, tanpa beda huruf)
                    $r = q28_sel_users($t, $s, ['search' => 'ALI']);
                    $t->eq($opts($r), ['qa28alice'], 'search ALI -> qa28alice');
                    $r = q28_sel_users($t, $s, ['search' => 'qa28_']);
                    $t->eq($opts($r), ['qa28_under'], 'search "qa28_" harfiah -> qa28_under');
                    $r = q28_sel_users($t, $s, ['search' => '%']);
                    $t->eq($opts($r), [], 'search "%" harfiah -> kosong');
                    $r = q28_sel_users($t, $s, ['search' => 'zzz-' . uniqid()]);
                    $t->status($r, 200, 'search tanpa hasil');
                    $t->eq($r[1]['result']['options'], [], 'search tanpa hasil: options []');
                    $r = q28_sel_users($t, $s, ['search' => 'qa28c']);
                    $t->eq($opts($r), ['qa28carol'], 'search qa28c -> qa28carol');
                    foreach (['日本語', "' OR 1=1 --", '\\', str_repeat('x', 2000)] as $odd) {
                        $r = q28_sel_users($t, $s, ['search' => $odd]);
                        q28_no500($t, $r, 'search aneh');
                        $t->status($r, 200, 'search aneh ' . substr(json_encode($odd), 0, 20));
                    }
                    // selected_id
                    $r = q28_sel_users($t, $s, ['search' => 'ALI', 'selected_id' => 'qa28bob']);
                    $got = $opts($r);
                    sort($got);
                    $t->eq($got, ['qa28alice', 'qa28bob'], 'selected_id (string) ikut walau tak lolos search');
                    $r = q28_sel_users($t, $s, ['search' => 'ALI', 'selected_id' => ['qa28bob', 'qa28eve', 'tidak-ada-' . uniqid(), 'qa28draftonly']]);
                    $got = $opts($r);
                    sort($got);
                    $t->eq($got, ['qa28alice', 'qa28bob', 'qa28eve'], 'selected_id (array): hanya yang ada di daftar; draft-only & tak dikenal tidak ikut');
                    foreach (['search[]=a', 'selected_id[][a]=b'] as $raw) {
                        $r = $t->call($s, 'GET', Q28_SEL . '/opname-users?' . $raw);
                        q28_no500($t, $r, "select users mentah $raw");
                        $t->true(in_array($r[0], [200, 422], true), "select users mentah $raw: 200/422 (" . $r[0] . ')');
                    }
                    $setter([]);
                    // user3: A,B,C,D,Y,Z + G2 (pembuat)
                    $verify('user3', $s, q28_uu($t, 'user3'), ['qa28alice', 'qa28bob', 'qa28carol', 'qa28_under', $me]);
                });

                // --- user2 sungguhan: A, B, C, Y, Z
                $s2 = q28_u2_session($t);
                $u2name = q28_u2_name($t);
                $got = $verify('user2', $s2, q28_uu($t, 'user2'), ['qa28alice', 'qa28bob', 'qa28_under', $me]);
                $t->true(!in_array('qa28carol', $got, true), 'user2: qa28carol (sesi D tak terlihat) tidak ada');
                $t->true(!in_array('qa28eve', $got, true), 'user2: qa28eve (sesi E tak terlihat) tidak ada');
                $t->true(!in_array($u2name, $got, true), 'user2: user tanpa sesi (dirinya) tidak ada');
                $r = q28_sel_users($t, $s2, ['selected_id' => ['qa28carol', 'qa28eve']]);
                $t->true(!in_array('qa28carol', $opts($r), true) && !in_array('qa28eve', $opts($r), true), 'user2: selected_id di luar daftar terlihat tidak ikut');
                $r = q28_sel_users($t, $s2, ['search' => 'qa28b']);
                $t->eq($opts($r), ['qa28bob'], 'user2: search qa28b -> qa28bob');
                $t->eq(q28_sel_users($t, $s2)[1]['message'], 'Success', 'user2: pesan SUCCESS bahasa EN');
                $t->call($s2, 'GET', 'api/v5/auth/log-out');

                // tanpa sesi sama sekali: options kosong
                q28_sweep($t);
                $r = q28_sel_users($t, $s);
                $t->status($r, 200, 'tanpa sesi: 200');
                $t->eq($r[1]['result']['options'], [], 'tanpa sesi terkonfirmasi: options []');
            });
        },
    ],
];
