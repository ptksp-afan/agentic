<?php
/**
 * ED-1027 - data nyata api_sidomaju (34,5 rb dokumen aktif, 17 folder): X-6 (sapuan semua folder nyata: detail & isi folder = oracle
 * untuk keadaan asli, Semarang, JOG dan superadmin; parameter tak dikenal pada endpoint baru tidak 500), X-7 (satu sesi opname nyata di
 * folder nyata berukuran sedang lewat API 03: angka naik tepat sebesar yang discan di semua tingkat; dipulihkan persis oleh q27_cleanup).
 * Oracle = q27_oracle (SQL mentah, tanpa kode BE).
 */
require_once __DIR__ . '/qa_lib.php';

/** Bandingkan satu respons list (halaman terbatas) dengan oracle. */
$q27_rows_vs_oracle = function ($t, $r, array $o, $label) {
    $t->status($r, 200, "$label status");
    foreach ($r[1]['result']['data'] ?? [] as $row) {
        if (($row['type'] ?? null) === 'Folder') {
            $exp = isset($o['folders'][$row['id_archive']]) ? q27_vt($o['folders'][$row['id_archive']]) : null;
            $t->eq(q27_api_vt($row['document_verified']), $exp, "$label: folder {$row['id_archive']}");
        } else {
            $t->true(in_array($row['document_verified']['verified'], [0, 1], true) && $row['document_verified']['total'] === 1, "$label: dokumen {$row['id_archive']} 0|1 / 1");
        }
    }
};

return [

    [
        'id'    => 'X-6',
        'title' => 'sapuan data nyata: setiap folder aktif nyata: GET verifications/{id} (angka + angka per tipe) dan isi folder = oracle untuk keadaan asli, Semarang, JOG dan superadmin; tak terlihat 403/404; parameter tak dikenal pada endpoint baru tidak 500',
        'run'   => function ($t) use ($q27_rows_vs_oracle) {
            $s = $t->session();
            q27_baseline($t);
            try {
                $c = $t->db();
                $folders = $c->table('archives')->where('type', 1)->where('is_active', '>', 0)->pluck('name', 'id_archive')->all();
                $t->true(count($folders) >= 10, 'folder aktif nyata: ' . count($folders));
                $locsAll = array_keys(q27_locs($t));
                $uname = q27_uname($t);
                $uid = q27_uid($t);
                $modes = [
                    'asli'   => [null, ['username' => (string) $uname, 'uid' => $uid, 'bypass' => false, 'locs' => $locsAll]],
                    'smr'    => [q27_opts('smr'), q27_u($t, 'smr')],
                    'jog'    => [q27_opts('jog'), q27_u($t, 'jog')],
                    'bypass' => [q27_opts('bypass'), q27_u($t, 'bypass')],
                ];
                $summary = [];
                foreach ($modes as $mode => [$opts, $u]) {
                    $run = function () use ($t, $s, $mode, $u, $folders, $q27_rows_vs_oracle, &$summary) {
                        $o = q27_oracle($t, $u);
                        $checked = $denied = 0;
                        foreach ($folders as $id => $name) {
                            $r = q27_verif($t, $s, $id);
                            if (isset($o['visible'][$id])) {
                                $t->status($r, 200, "[$mode] detail $name");
                                $res = $r[1]['result'];
                                $t->eq([$res['verified'], $res['total']], q27_vt($o['folders'][$id]), "[$mode] detail $name = oracle");
                                $got = [];
                                foreach ($res['transaction_types'] as $tr) {
                                    $got[$tr['transaction_type']] = [$tr['verified'], $tr['total']];
                                }
                                foreach ($got as $tp => $vt) {
                                    $exp = $o['folders'][$id]['types'][$tp] ?? [0, 0];
                                    $t->eq($vt, [$exp[0], $exp[1]], "[$mode] detail $name tipe $tp = oracle");
                                }
                                // isi folder (halaman 1) = oracle
                                $q27_rows_vs_oracle($t, q27_list($t, $s, ['id_archive' => $id, 'pagination' => 100]), $o, "[$mode] isi $name");
                                $checked++;
                            } else {
                                $t->true(in_array($r[0], [403, 404], true), "[$mode] detail $name tak terlihat: HTTP {$r[0]}");
                                $denied++;
                            }
                        }
                        $summary[$mode] = "$checked terbuka, $denied tak terlihat";
                        $t->true($checked > 0, "[$mode] minimal satu folder nyata terbuka ($checked)");
                    };
                    if ($opts === null) {
                        $run();
                    } else {
                        q27_with_user($t, $opts, $run);
                    }
                }
                $t->note(json_encode($summary));

                // parameter tak dikenal / aneh pada endpoint baru: diabaikan, bukan 500
                $anyFolder = array_keys($folders)[0];
                foreach (['?foo=bar', '?page=abc', '?pagination=-1', '?search[]=x', '?id_archive=' . $anyFolder, '?sorts=zzz', '?type=7&x[a]=1'] as $qs) {
                    $r = $t->call($s, 'GET', Q27_BASE . '/verifications' . $qs);
                    $t->status($r, 200, "verifications$qs");
                    $r = $t->call($s, 'GET', Q27_BASE . '/verifications/' . $anyFolder . $qs);
                    $t->true($r[0] < 500, "verifications/{id}$qs: HTTP {$r[0]}");
                }
            } finally {
                q27_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'X-7',
        'title' => 'satu sesi opname nyata di folder nyata (API 03, 25 dokumen discan): angka folder, induk, ringkasan, baris tipe naik tepat 25 di semua tingkat sesuai oracle; slice sesi & last_sessions benar; semua dipulihkan persis',
        'run'   => function ($t) use ($q27_rows_vs_oracle) {
            $s = $t->session();
            q27_baseline($t);
            try {
                $c = $t->db();
                // folder nyata aktif dengan 30..2000 dokumen langsung (terkecil)
                $counts = $c->table('archives')->where('type', 2)->where('is_active', 1)->whereNotNull('id_archive_parent')->groupBy('id_archive_parent')
                    ->selectRaw('id_archive_parent, COUNT(*) n')->pluck('n', 'id_archive_parent')->all();
                asort($counts);
                $folder = null;
                foreach ($counts as $id => $n) {
                    if ($n >= 30 && $n <= 2000 && (int) $c->table('archives')->where('id_archive', $id)->where('type', 1)->where('is_active', '>', 0)->count() === 1) {
                        $folder = $id;
                        $direct = $n;
                        break;
                    }
                }
                if ($folder === null) {
                    $t->skip('tidak ada folder nyata aktif dengan 30..2000 dokumen langsung');
                }
                $fname = $c->table('archives')->where('id_archive', $folder)->value('name');
                $parent = $c->table('archives')->where('id_archive', $folder)->value('id_archive_parent');
                $docs = $c->table('archives as a')->join('archive_documents as d', 'd.id_archive', '=', 'a.id_archive')
                    ->where('a.id_archive_parent', $folder)->where('a.type', 2)->where('a.is_active', 1)->where('a.is_verified', 0)
                    ->orderBy('a.id_archive')->limit(80)->get(['a.id_archive', 'a.name', 'd.transaction_type'])->all();
                // nama unik di seluruh archives (kode scan tidak ambigu)
                $names = array_column($docs, 'name');
                $dupes = $c->table('archives')->whereIn('name', $names)->groupBy('name')->havingRaw('COUNT(*) > 1')->pluck('name')->all();
                $pick = [];
                foreach ($docs as $d) {
                    if (!in_array($d->name, $dupes, true) && count($pick) < 25) {
                        $pick[] = $d;
                    }
                }
                $t->eq(count($pick), 25, "25 dokumen nyata berkode unik dipilih dari folder '$fname' ($direct dokumen langsung)");
                $byType = [];
                foreach ($pick as $d) {
                    $byType[(int) $d->transaction_type] = ($byType[(int) $d->transaction_type] ?? 0) + 1;
                }

                q27_with_user($t, ['role' => 1], function ($set) use ($t, $s, $folder, $parent, $pick, $byType, $fname, $direct, $q27_rows_vs_oracle) {
                    $u = q27_u($t, 'bypass');
                    $o0 = q27_oracle($t, $u);
                    $b = [
                        'folder'  => q27_vt($o0['folders'][$folder]),
                        'summary' => q27_vt($o0['summary']),
                        'types'   => $o0['folders'][$folder]['types'],
                    ];
                    $rp = q27_list($t, $s, $parent === null ? ['pagination' => 100] : ['id_archive' => $parent, 'pagination' => 100]);
                    $apiBefore = q27_api_vt(q27_map($rp)[$folder]['document_verified'] ?? null);
                    $t->eq($apiBefore, $b['folder'], 'sebelum: baris folder = oracle');
                    $t->eq($b['folder'][0], 0, 'sebelum: folder nyata belum ada yang verified');

                    $id = q27_session($t, $s, $folder, []);
                    foreach ($pick as $d) {
                        $t->status(q27_scan($t, $s, $id, $d->name), 200, 'scan ' . $d->name);
                    }
                    $t->status(q27_confirm($t, $s, $id), 200, 'confirm sesi nyata');
                    $db = q27_opname($t, $id);
                    $t->eq((int) $db['verified_count'], 25, 'sesi: verified_count = 25 discan');
                    $t->eq((int) $db['total_documents'], $direct, "sesi: total_documents = $direct dokumen langsung folder");

                    $o1 = q27_oracle($t, $u);
                    $t->eq(q27_vt($o1['folders'][$folder]), [$b['folder'][0] + 25, $b['folder'][1]], 'oracle: folder naik tepat 25');
                    $rp = q27_list($t, $s, $parent === null ? ['pagination' => 100] : ['id_archive' => $parent, 'pagination' => 100]);
                    $q27_rows_vs_oracle($t, $rp, $o1, 'induk sesudah sesi');
                    $t->eq(q27_api_vt(q27_map($rp)[$folder]['document_verified']), [$b['folder'][0] + 25, $b['folder'][1]], 'baris folder: +25 verified, total tetap');
                    $t->eq(q27_api_vt($rp[1]['result']['document_verified_summary']), [$b['summary'][0] + 25, $b['summary'][1]], 'ringkasan: +25, total tetap');

                    // leluhur folder ikut naik 25
                    $cur = $parent;
                    $steps = 0;
                    while ($cur !== null && $steps++ < 10) {
                        $row = $t->db()->table('archives')->where('id_archive', $cur)->first();
                        $gp = $row->id_archive_parent;
                        $lr = q27_list($t, $s, $gp === null ? ['pagination' => 100] : ['id_archive' => $gp, 'pagination' => 100]);
                        $t->eq(q27_api_vt(q27_map($lr)[$cur]['document_verified']), q27_vt($o1['folders'][$cur]), "leluhur $steps = oracle");
                        $t->eq($o1['folders'][$cur]['v'] - $o0['folders'][$cur]['v'], 25, "leluhur $steps naik tepat 25");
                        $cur = $gp;
                    }

                    $rd = q27_verif($t, $s, $folder);
                    $t->status($rd, 200, 'detail folder');
                    $res = $rd[1]['result'];
                    $t->eq([$res['verified'], $res['total']], [$b['folder'][0] + 25, $b['folder'][1]], 'detail: header +25');
                    foreach ($res['transaction_types'] as $tr) {
                        $was = $b['types'][$tr['transaction_type']][0] ?? 0;
                        $t->eq($tr['verified'] - $was, $byType[$tr['transaction_type']] ?? 0, "detail: tipe {$tr['transaction_type']} naik sesuai yang discan (" . ($byType[$tr['transaction_type']] ?? 0) . ')');
                    }
                    $t->eq($res['last_sessions'][0]['id_archive_opname'] ?? null, $id, 'detail: sesi nyata ada di last_sessions folder');
                    $t->eq([$res['last_sessions'][0]['folder_total_documents'], $res['last_sessions'][0]['folder_verified']], [$direct, 25], 'detail: slice sesi = [dokumen folder, 25]');
                    $ra = q27_verif($t, $s);
                    $t->eq([$ra[1]['result']['verified'], $ra[1]['result']['total']], [$b['summary'][0] + 25, $b['summary'][1]], 'detail All: +25');
                    $t->eq($ra[1]['result']['last_sessions'][0]['id_archive_opname'] ?? null, $id, 'detail All: sesi nyata terbaru');
                    $t->eq($ra[1]['result']['last_sessions'][0]['verified_count'], 25, 'detail All: verified_count 25');

                    // user Semarang: angka sesuai oracle sesudah sesi
                    $set(q27_opts('smr'));
                    $o2 = q27_oracle($t, q27_u($t, 'smr'));
                    $ra = q27_verif($t, $s);
                    $t->eq([$ra[1]['result']['verified'], $ra[1]['result']['total']], q27_vt($o2['summary']), 'user Semarang: All = oracle sesudah sesi');
                    if (isset($o2['visible'][$folder])) {
                        $rd = q27_verif($t, $s, $folder);
                        $t->eq([$rd[1]['result']['verified'], $rd[1]['result']['total']], q27_vt($o2['folders'][$folder]), 'user Semarang: folder = oracle sesudah sesi');
                    }
                });
            } finally {
                q27_cleanup($t);
            }
        },
    ],
];
