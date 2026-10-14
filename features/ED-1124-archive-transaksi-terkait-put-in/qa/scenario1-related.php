<?php
/**
 * ED-1124 AC-1, AC-2, AC-3: "Tampilkan Transaksi Terkait" (showRelatedTransaction) dengan folder / baris rusak senama nomor
 * transaksi terkait, dan regresi hasil dokumen nyata terhadap kode baseline (be.head). Data uji berawalan QA124-; satu
 * baris bernama nomor transaksi nyata (K-3) di dalam folder uji QA, dihapus lewat id.
 */
require_once __DIR__ . '/qa_lib.php';

if (!function_exists('q124_setup_related')) {
/** Susun dokumen uji tipe 7 yang menunjuk DO nyata di folder $F; pilih yang punya transaksi terkait lewat HTTP. */
function q124_setup_related($t, $s, $F)
{
    $cands = q124_do_candidates($t, 6);
    if (!$cands) {
        $t->blocked('tidak ada DO nyata bertransaksi terkait (SO ber-dokumen aktif) di DB uji');
    }
    $docs = [];
    foreach ($cands as $i => $idTx) {
        $docs[q124_doc($t, 'D' . $i, ['parent' => $F, 'doc' => ['type' => 7, 'tx' => $idTx]])] = $idTx;
    }
    $r = q124_related($t, $s, $F);
    $t->status($r, 200, 'baseline: list F showRelatedTransaction');
    $rows = q124_by_id($r);
    $pick = null;
    foreach ($docs as $id => $idTx) {
        if (!empty($rows[$id]['related_transactions'])) {
            $pick = $id;
            break;
        }
    }
    $t->true($pick !== null, 'prasyarat: salah satu dokumen uji (DO nyata) punya transaksi terkait ber-dokumen');

    return [$docs, $pick, $rows];
}
}

return [

    [
        'id'    => 'AC-1',
        'title' => 'folder bernama persis nomor transaksi terkait (K-3, di folder QA): list showRelatedTransaction (id_archive=F, + query) = 200, related sama dengan sebelum G ada, tanpa G, tiap baris ber-type transaksi; kontrol negatif: kode baseline = 500',
        'run'   => function ($t) {
            $s = $t->session();
            q124_purge($t);
            $base = q124_base($t);

            try {
                $f = q124_soft($t);
                $F = q124_folder($t, 'F');
                $FG = q124_folder($t, 'FG');
                list($docs, $D, $rowsBefore) = q124_setup_related($t, $s, $F);
                $rel0 = $rowsBefore[$D]['related_transactions'];
                $N = $rel0[0]['name'];
                $nameD = q124_row($t, $D)['name'];
                $t->note('D tipe 7 uji -> DO nyata ' . $docs[$D] . ' punya ' . count($rel0) . ' transaksi terkait; N="' . $N . '"');

                // query varian sebelum G: dengan id_archive=F dan tanpa id_archive (FE: query + showRelatedTransaction)
                $sq = ['showRelatedTransaction' => true, 'query' => $nameD];
                $rq0 = q124_related($t, $s, $F, $sq);
                $f->resp($rq0, 200, null, 'sebelum G: list F + query nama D');
                $rqAll0 = $t->call($s, 'GET', Q124_API . 'archives?pagination=100&search=' . urlencode(json_encode($sq)));
                $f->resp($rqAll0, 200, null, 'sebelum G: query nama D tanpa id_archive');
                $f->ok(isset(q124_by_id($rq0)[$D]) && isset(q124_by_id($rqAll0)[$D]), 'sebelum G: query menemukan D (dengan dan tanpa id_archive)');

                // folder G bernama N (K-3), di dalam folder uji QA (bukan root)
                $G = q124_k3_folder($t, $N, $FG);
                $f->eq(q124_row($t, $G)['type'], 1, 'G = folder (type 1)');
                $f->ok(q124_row($t, $G)['id_archive_parent'] === $FG, 'G di dalam folder uji QA, bukan root');

                // kontrol negatif: kode baseline (be.head) = galat yang dilaporkan (bukti fixture memang memicu bug)
                $fqcn = q124_baseline_class($t);
                if ($fqcn === null) {
                    $t->note('MANUAL: kode baseline (git show ' . q124_baseline_ref() . ') tidak bisa dibaca; kontrol negatif dilewati');
                } else {
                    $old = q124_related_unit($t, $fqcn, $rowsBefore[$D]['document']);
                    $t->note('kontrol negatif baseline: ' . json_encode($old));
                    $f->ok(isset($old['err']) && strpos($old['err'], 'transaction_type') !== false, 'baseline + G = galat "property transaction_type of non-object" (fixture memicu bug)');
                }

                // request FE
                $r = q124_related($t, $s, $F);
                $f->resp($r, 200, null, 'G ada: list F showRelatedTransaction');
                $f->no5xx($r, 'G ada: list F');
                $rows = q124_by_id($r);
                $f->ok(isset($rows[$D]), 'G ada: D ada di list');
                foreach (array_keys($docs) as $id) {
                    $f->eq(json_encode($rows[$id]['related_transactions'] ?? 'tidak ada'), json_encode($rowsBefore[$id]['related_transactions'] ?? 'tidak ada'), 'related_transactions ' . $docs[$id] . ' sama dengan sebelum G ada');
                }
                $f->eq(json_encode($rows[$D]['related_transactions'] ?? null), json_encode($rel0), 'related_transactions D sama dengan sebelum G ada');
                foreach ($rows as $row) {
                    foreach ($row['related_transactions'] ?? [] as $rt) {
                        $f->ok($rt['id_archive'] !== $G, 'G tidak ikut di related_transactions ' . $row['id_archive']);
                        $f->ok(!empty($rt['document']['transaction_type']) && is_string($rt['type']) && $rt['type'] !== '' && $rt['type'] !== 'Folder', 'baris terkait ' . $rt['name'] . ' ber-type transaksi (' . ($rt['type'] ?? '?') . ')');
                    }
                }

                // + "query":"<nama D>" (dengan id_archive=F, dan tanpa id_archive seperti FE)
                $rq = q124_related($t, $s, $F, $sq);
                $f->resp($rq, 200, null, 'G ada: list F + query nama D');
                $rqAll = $t->call($s, 'GET', Q124_API . 'archives?pagination=100&search=' . urlencode(json_encode($sq)));
                $f->resp($rqAll, 200, null, 'G ada: query nama D tanpa id_archive');
                foreach (['dengan id_archive=F' => [$rq, $rq0], 'tanpa id_archive' => [$rqAll, $rqAll0]] as $label => $pair) {
                    $now = q124_by_id($pair[0])[$D]['related_transactions'] ?? 'tidak ada';
                    $f->eq(json_encode($now), json_encode($rel0), "query nama D $label: related D sama dengan sebelum G ada");
                    $f->ok(!in_array($G, array_map(function ($x) { return $x['id_archive']; }, is_array($now) ? $now : []), true), "query $label: G tidak ikut");
                }

                // G tetap utuh (tidak diubah oleh GET)
                $f->eq(q124_row($t, $G)['name'], $N, 'G tidak berubah oleh GET');

                $f->done();
            } finally {
                q124_purge($t);
            }
            q124_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-2',
        'title' => 'baris archives type=2 bernama N tanpa baris archive_documents (data rusak, memakai baris K-3 yang sama): tidak ikut transaksi terkait, 200',
        'run'   => function ($t) {
            $s = $t->session();
            q124_purge($t);
            $base = q124_base($t);

            try {
                $f = q124_soft($t);
                $F = q124_folder($t, 'F');
                $FG = q124_folder($t, 'FG');
                list($docs, $D, $rowsBefore) = q124_setup_related($t, $s, $F);
                $rel0 = $rowsBefore[$D]['related_transactions'];
                $N = $rel0[0]['name'];

                // baris rusak: type 2, bernama N, tanpa archive_documents; aktif dan terlihat (di dalam folder uji QA)
                $X = q124_k3_folder($t, $N, $FG, 2);
                $f->eq(q124_row($t, $X)['type'], 2, 'X = type 2');
                $f->eq($t->db()->table('archive_documents')->where('id_archive', $X)->count(), 0, 'X tanpa archive_documents');
                $f->eq(q124_row($t, $X)['is_active'], 1, 'X aktif');

                $fqcn = q124_baseline_class($t);
                if ($fqcn === null) {
                    $t->note('MANUAL: kode baseline tidak bisa dibaca; kontrol negatif dilewati');
                } else {
                    $old = q124_related_unit($t, $fqcn, $rowsBefore[$D]['document']);
                    $t->note('kontrol negatif baseline: ' . json_encode($old));
                    $f->ok(isset($old['err']), 'baseline + baris rusak X = galat (fixture memicu bug)');
                }

                $r = q124_related($t, $s, $F);
                $f->resp($r, 200, null, 'list F showRelatedTransaction dengan baris rusak X');
                $f->no5xx($r, 'list F');
                $rows = q124_by_id($r);
                $f->eq(json_encode($rows[$D]['related_transactions'] ?? null), json_encode($rel0), 'related_transactions D sama dengan sebelum X ada');
                foreach ($rows as $row) {
                    foreach ($row['related_transactions'] ?? [] as $rt) {
                        $f->ok($rt['id_archive'] !== $X, 'X tidak ikut di related_transactions ' . $row['id_archive']);
                    }
                }

                $f->done();
            } finally {
                q124_purge($t);
            }
            q124_assert_clean($t, $base);
        },
    ],

    [
        'id'    => 'AC-3',
        'title' => 'regresi tanpa folder senama: related_transactions dokumen nyata tipe 6/7/8 dan dokumen uji tipe 7 (-> DO nyata) identik dengan relatedTransaction() baseline; tanpa filter kunci related_transactions tidak ada',
        'run'   => function ($t) {
            $s = $t->session();
            q124_purge($t);
            $base = q124_base($t);

            try {
                $f = q124_soft($t);
                $fqcn = q124_baseline_class($t);
                if ($fqcn === null) {
                    $t->blocked('kode baseline (git show ' . q124_baseline_ref() . ') tidak bisa dibaca: pembanding AC-3 tidak ada');
                }
                $compared = 0;
                $nonEmpty = 0;
                $perType = [];
                foreach ([6, 7, 8] as $type) {
                    $r = $t->call($s, 'GET', Q124_API . 'archives?pagination=25&search=' . urlencode(json_encode(['showRelatedTransaction' => true, 'type' => $type])));
                    $f->resp($r, 200, null, "list real tipe $type showRelatedTransaction");
                    $perType[$type] = [0, 0];
                    foreach ($r[1]['result']['data'] ?? [] as $row) {
                        if (!array_key_exists('related_transactions', $row)) {
                            $f->ok(false, 'tipe ' . $type . ' ' . $row['id_archive'] . ': kunci related_transactions ada');
                            continue;
                        }
                        $old = q124_related_unit($t, $fqcn, $row['document']);
                        $f->ok(isset($old['ok']), 'baseline tipe ' . $type . ' tanpa galat: ' . json_encode($old['err'] ?? null));
                        $f->eq(json_encode($row['related_transactions']), json_encode($old['ok'] ?? 'baseline galat'), 'tipe ' . $type . ' ' . ($row['name'][0]['transaction_no'] ?? '?') . ': related_transactions = baseline');
                        $compared++;
                        $perType[$type][0]++;
                        if (!empty($old['ok'])) {
                            $nonEmpty++;
                            $perType[$type][1]++;
                        }
                    }
                }
                $t->note('dokumen nyata dibandingkan dengan baseline: ' . $compared . ' dokumen, ' . $nonEmpty . ' dengan transaksi terkait; per tipe [dokumen, berisi]: ' . json_encode($perType));
                $f->ok($compared >= 20, 'minimal 20 dokumen nyata dibandingkan (ada ' . $compared . ')');

                // dokumen uji tipe 7 -> DO nyata (tanpa folder senama) = baseline
                $F = q124_folder($t, 'F');
                $docs = [];
                foreach (q124_do_candidates($t, 6) as $i => $idTx) {
                    $docs[q124_doc($t, 'D' . $i, ['parent' => $F, 'doc' => ['type' => 7, 'tx' => $idTx]])] = $idTx;
                }
                $f->ok(count($docs) >= 1, 'ada dokumen uji tipe 7 -> DO nyata');
                $r = q124_related($t, $s, $F);
                $f->resp($r, 200, null, 'list F (dokumen uji -> DO nyata)');
                $rows = q124_by_id($r);
                $nonEmpty2 = 0;
                foreach ($docs as $id => $idTx) {
                    $old = q124_related_unit($t, $fqcn, $rows[$id]['document'] ?? ['transaction_type' => 7, 'id_transaction' => $idTx]);
                    $f->eq(json_encode($rows[$id]['related_transactions'] ?? 'tidak ada'), json_encode($old['ok'] ?? 'baseline galat'), "dokumen uji -> $idTx: related_transactions = baseline");
                    if (!empty($old['ok'])) {
                        $nonEmpty2++;
                    }
                }
                $f->ok($nonEmpty2 >= 1, 'minimal 1 dokumen uji punya transaksi terkait (ada ' . $nonEmpty2 . ')');

                // tanpa filter kunci: tidak ada related_transactions; showRelatedTransaction:false juga tidak
                $r = $t->call($s, 'GET', Q124_API . 'archives?pagination=100&id_archive=' . $F);
                $f->resp($r, 200, null, 'list F tanpa search');
                foreach ($r[1]['result']['data'] ?? [] as $row) {
                    $f->ok(!array_key_exists('related_transactions', $row), 'tanpa filter: tanpa kunci related_transactions');
                }
                $r = q124_related($t, $s, $F, ['showRelatedTransaction' => false]);
                $f->resp($r, 200, null, 'list F showRelatedTransaction=false');
                foreach ($r[1]['result']['data'] ?? [] as $row) {
                    $f->ok(!array_key_exists('related_transactions', $row), 'showRelatedTransaction=false: tanpa kunci related_transactions');
                }

                $f->done();
            } finally {
                q124_purge($t);
            }
            q124_assert_clean($t, $base);
        },
    ],

];
