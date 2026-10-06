<?php
/**
 * ED-1024 - AC-9..AC-11: daftar transaction type archive terpusat (select Type, label baris, validasi store,
 * Billing) + regresi pencatatan saat cetak PDF.
 */
require_once __DIR__ . '/qa_lib.php';

$typesUrl = 'api/v5/select/document-archive/archive/types';

$expected = [
    'EN' => ['Folder', 'Sales Order', 'Delivery Order', 'Sales Invoice', 'Sales Return', 'Receivable Payment', 'Bilyet Giro/Cheque', 'Billing'],
    'ID' => ['Folder', 'Pesanan Penjualan', 'Pengantaran Pesanan', 'Faktur Penjualan', 'Retur Penjualan', 'Pembayaran Piutang', 'Bilyet Giro/Cek', 'Penagihan'],
];
$values = ['FOLDER', 6, 7, 8, 9, 29, 31, 222];

return [

    [
        'id'    => 'AC-9',
        'title' => 'GET types (ID lalu EN): options persis FOLDER,6,7,8,9,29,31,222 dengan label sesuai bahasa; label baris = label opsi',
        'run'   => function ($t) use ($typesUrl, $expected, $values) {
            $s = $t->session();
            q1_purge($t);

            q1_with_user($t, ['lang' => 'ID'], function ($set) use ($t, $s, $typesUrl, $expected, $values) {
                foreach (['ID', 'EN'] as $lang) {
                    $set(['lang' => $lang]);
                    $r = $t->call($s, 'GET', $typesUrl);
                    $t->status($r, 200, "types $lang");
                    $t->has($r[1], 'result.options', "types $lang: result.options");
                    $t->has($r[1], 'result.default', "types $lang: result.default");
                    $t->eq($r[1]['result']['default'], null, "types $lang: default null");
                    $opts = $r[1]['result']['options'];
                    $t->eq(array_column($opts, 'value'), $values, "types $lang: urutan value");
                    $t->eq(array_column($opts, 'label'), $expected[$lang], "types $lang: label");
                }

                // label baris dokumen = label opsi (BR-13), untuk ketujuh tipe, kedua bahasa
                $folder = q1_add($t, ['name' => 'QA01-TYPES', 'type' => 1, 'all' => 1]);
                $idByType = [];
                foreach (array_slice($values, 1) as $type) {
                    $idByType[$type] = q1_add($t, ['name' => "QA01-T$type", 'type' => 2, 'all' => 1, 'parent' => $folder, 'doc' => ['type' => $type]]);
                }
                $idByType[999] = q1_add($t, ['name' => 'QA01-T999', 'type' => 2, 'all' => 1, 'parent' => $folder, 'doc' => ['type' => 999]]);

                foreach (['ID', 'EN'] as $lang) {
                    $set(['lang' => $lang]);
                    $r = q1_list($t, $s, ['id_archive' => $folder, 'pagination' => 50]);
                    $t->status($r, 200, "list folder uji ($lang) memuat tipe tak berlabel");
                    $labels = [];
                    foreach ($r[1]['result']['data'] as $row) {
                        $labels[$row['name'][0]['transaction_type']] = $row['type'];
                    }
                    foreach (array_slice($values, 1) as $i => $type) {
                        $t->eq($labels[$type] ?? null, $expected[$lang][$i + 1], "label kolom Type baris tipe $type ($lang)");
                    }
                    $t->eq($labels[999] ?? null, '999', "tipe tanpa label tampil sebagai angkanya ($lang), bukan 500");
                }
            });

            // BR-13: kedua sumber label lama identik untuk 7 tipe (cek in-process)
            $same = $t->probe(function () use ($values) {
                $a = \App\Lib\lang\Transaction::list();
                $b = \Modules\V5\Entities\Languages\Transaction::list();
                $bad = [];
                foreach (array_slice($values, 1) as $type) {
                    foreach (['en', 'id'] as $l) {
                        if (($a[$type][$l] ?? null) !== ($b[$type][$l] ?? null)) {
                            $bad[] = "$type/$l";
                        }
                    }
                }

                return $bad;
            });
            $t->eq($same, [], 'BR-13: label lama App\\Lib\\lang\\Transaction = Languages\\Transaction untuk 7 tipe');

            q1_purge($t);
            q1_assert_clean($t, 'data uji AC-9 sudah dibuang');
        },
    ],

    [
        'id'    => 'AC-10',
        'title' => 'Dokumen Billing nyata (is_active=-1) di-put-in lewat name ke PUSAT - MAGELANG: filter type 222 menemukannya dengan label Penagihan/Billing; filter 6 tidak',
        'run'   => function ($t) {
            $s = $t->session();
            q1_purge($t);
            $pusat = q1_id_by_name($t, 'PUSAT - MAGELANG', null);

            // satu dokumen Billing nyata yang belum masuk folder (is_active = -1) dan Billing-nya ada
            $cand = $t->db()->selectOne(
                "select a.id_archive, a.name from archives a
                 join archive_documents d on d.id_archive = a.id_archive and d.transaction_type = 222
                 join billings b on b.id_billing = d.id_transaction
                 where a.type = 2 and a.is_active = -1 order by a.name desc limit 1"
            );
            if (!$cand) {
                $t->blocked('tidak ada dokumen Billing is_active=-1 di QA DB');
            }
            $name = $cand->name;
            $before = q1_snap($t, [$cand->id_archive]);
            $search = function (array $extra) use ($name) {
                return json_encode($extra + ['query' => $name]);
            };

            try {
                $r = q1_list($t, $s, ['search' => $search(['type' => 222])]);
                $t->eq($r[1]['result']['total'], 0, 'sebelum put-in: dokumen is_active=-1 tidak tampil di filter 222');

                $r = $t->call($s, 'POST', 'api/v5/document-archive/documents/put-in', ['id_archive_parent' => $pusat, 'name' => $name]);
                $t->status($r, 200, 'put-in lewat name');
                $t->code($r, 'ARCHIVE204', 'put-in');
                $row = q1_row($t, $cand->id_archive);
                $t->eq($row['id_archive_parent'], $pusat, 'DB: id_archive_parent = PUSAT - MAGELANG');
                $t->eq($row['is_active'], 1, 'DB: is_active = 1');
                $hist = json_decode($row['history'], true);
                $t->true(in_array(end($hist)['action'] ?? null, ['place', 'move'], true), 'DB: entri history terakhir = place/move (dokumen -1 yang sudah punya induk = move)');

                $f222 = q1_list($t, $s, ['search' => $search(['type' => 222])]);
                $t->status($f222, 200, 'filter type 222');
                $t->eq($f222[1]['result']['total'], 1, 'filter type 222 menemukan dokumen Billing');
                $t->eq($f222[1]['result']['data'][0]['type'], 'Penagihan', 'type baris (user ID) = Penagihan');
                $t->eq($f222[1]['result']['data'][0]['name'][0]['transaction_type'], 222, 'transaction_type = 222');

                $f6 = q1_list($t, $s, ['search' => $search(['type' => 6])]);
                $t->status($f6, 200, 'filter type 6');
                $t->eq($f6[1]['result']['total'], 0, 'filter type 6 tidak memuat dokumen Billing');

                $rel = q1_list($t, $s, ['search' => $search(['type' => 222, 'showRelatedTransaction' => true])]);
                $t->status($rel, 200, 'filter 222 + showRelatedTransaction (Billing nyata)');
                $t->true(is_array($rel[1]['result']['data'][0]['related_transactions'] ?? null), 'related_transactions Billing berupa array');

                // filter 222 tanpa query: seluruh hasil berlabel Penagihan
                $all = q1_list($t, $s, ['search' => json_encode(['type' => 222]), 'pagination' => 20]);
                $t->status($all, 200, 'filter type 222 tanpa query');
                foreach ($all[1]['result']['data'] as $rowApi) {
                    $t->eq($rowApi['type'], 'Penagihan', 'semua baris filter 222 berlabel Penagihan');
                }

                // bahasa EN
                q1_with_user($t, ['lang' => 'EN'], function ($set) use ($t, $s, $search) {
                    $r = q1_list($t, $s, ['search' => $search(['type' => 222])]);
                    $t->eq($r[1]['result']['data'][0]['type'] ?? null, 'Billing', 'type baris (user EN) = Billing');
                });
            } finally {
                q1_restore($t, $before);
                q1_purge($t);
            }
            $t->true(q1_same($before, q1_snap($t, [$cand->id_archive])), 'dokumen Billing dipulihkan persis');
            q1_assert_clean($t, 'data uji AC-10 sudah dibuang');
        },
    ],

    [
        'id'    => 'AC-11',
        'title' => 'Regresi pencatatan: cetak PDF SO -> 1 baris archives (+ archive_documents 6), cetak ulang tidak menggandakan; PDF Billing -> semua lokasi, 222; store tipe lain tidak mencatat',
        'run'   => function ($t) {
            $s = $t->session();
            q1_purge($t);

            $so = $t->db()->selectOne("select s.id_sales_order, s.sales_order_no, s.id_location from sales_orders s left join archives a on a.name = s.sales_order_no where a.id_archive is null order by s.sales_order_no desc limit 1");
            $bl = $t->db()->selectOne("select b.id_billing, b.billing_no from billings b left join archives a on a.name = b.billing_no where a.id_archive is null order by b.billing_no desc limit 1");
            if (!$so || !$bl) {
                $t->blocked('tidak ada SO/Billing tanpa baris archive di QA DB');
            }

            $cleanup = function ($names) use ($t) {
                q1_w($t, function ($c) use ($names) {
                    $ids = $c->table('archives')->whereIn('name', $names)->pluck('id_archive')->all();
                    if ($ids) {
                        $c->table('archive_documents')->whereIn('id_archive', $ids)->delete();
                        $c->table('archive_locations')->whereIn('id_archive', $ids)->delete();
                        $c->table('archives')->whereIn('id_archive', $ids)->delete();
                    }
                });
            };
            $db = q1_dbname($t);

            try {
                // --- SO
                $r = $t->callFile($s, 'GET', 'api/v5/sales/sales-orders/' . $so->id_sales_order . '/pdf');
                $t->status($r, 200, 'cetak PDF SO');
                $t->true(substr($r[1], 0, 5) === '%PDF-', 'PDF SO: isi berkas = PDF');
                $t->rowCount($db, 'archives', ['name' => $so->sales_order_no], 1, 'SO: tepat 1 baris archives');
                $row = $t->db()->table('archives')->where('name', $so->sales_order_no)->first();
                $t->eq($row->type, 2, 'SO: type = 2 (dokumen)');
                $t->eq($row->is_active, -1, 'SO: is_active = -1');
                $t->eq($row->is_all_location, 0, 'SO: is_all_location = 0');
                $t->eq($t->db()->table('archive_locations')->where('id_archive', $row->id_archive)->pluck('id_location')->all(), [$so->id_location], 'SO: lokasi = lokasi SO');
                $doc = $t->db()->table('archive_documents')->where('id_archive', $row->id_archive)->first();
                $t->eq($doc->transaction_type, 6, 'SO: archive_documents.transaction_type = 6');
                $t->eq($doc->id_transaction, $so->id_sales_order, 'SO: id_transaction');
                $t->eq($doc->transaction_no, $so->sales_order_no, 'SO: transaction_no');

                $r = $t->callFile($s, 'GET', 'api/v5/sales/sales-orders/' . $so->id_sales_order . '/pdf');
                $t->status($r, 200, 'cetak ulang PDF SO');
                $t->rowCount($db, 'archives', ['name' => $so->sales_order_no], 1, 'SO cetak ulang: tidak menggandakan archives');
                $t->rowCount($db, 'archive_documents', ['transaction_no' => $so->sales_order_no], 1, 'SO cetak ulang: tidak menggandakan archive_documents');

                // --- Billing
                $r = $t->callFile($s, 'GET', 'api/v5/sales/billings/' . $bl->id_billing . '/pdf');
                $t->status($r, 200, 'cetak PDF Billing');
                $t->true(substr($r[1], 0, 5) === '%PDF-', 'PDF Billing: isi berkas = PDF');
                $t->rowCount($db, 'archives', ['name' => $bl->billing_no], 1, 'Billing: tepat 1 baris archives');
                $row = $t->db()->table('archives')->where('name', $bl->billing_no)->first();
                $t->eq($row->is_all_location, 1, 'Billing: is_all_location = 1');
                $t->eq($row->is_active, -1, 'Billing: is_active = -1');
                $t->eq($t->db()->table('archive_locations')->where('id_archive', $row->id_archive)->count(), 0, 'Billing: tanpa baris lokasi');
                $doc = $t->db()->table('archive_documents')->where('id_archive', $row->id_archive)->first();
                $t->eq($doc->transaction_type, 222, 'Billing: archive_documents.transaction_type = 222');
                $t->eq($doc->id_transaction, $bl->id_billing, 'Billing: id_transaction');

                // --- BR-14: tipe di luar daftar tidak mencatat, tanpa error (in-process, store langsung)
                $res = $t->probe(function () {
                    $svc = new \Modules\V5\Http\Services\DocumentArchive\DocumentService;
                    $out = [];
                    $out['unsupported_po'] = $svc->store(['id_transaction' => 'QA01TX1', 'transaction_no' => 'QA01-UNSUP-PO', 'transaction_type' => \Modules\V5\Entities\Helper\TransType::PURCHASE_ORDER, 'id_related_employee' => null]);
                    $out['unsupported_999'] = $svc->store(['id_transaction' => 'QA01TX2', 'transaction_no' => 'QA01-UNSUP-999', 'transaction_type' => 999, 'id_related_employee' => null]);
                    $out['no_type'] = $svc->store(['id_transaction' => 'QA01TX3', 'transaction_no' => 'QA01-UNSUP-NONE', 'id_related_employee' => null]);
                    $first = $svc->store(['id_transaction' => 'QA01TX4', 'transaction_no' => 'QA01-SUP-6', 'transaction_type' => 6, 'id_locations' => [], 'id_related_employee' => null]);
                    $again = $svc->store(['id_transaction' => 'QA01TX4', 'transaction_no' => 'QA01-SUP-6', 'transaction_type' => 6, 'id_locations' => [], 'id_related_employee' => null]);
                    $out['sup_created'] = $first ? $first->id_archive : null;
                    $out['sup_same'] = $first && $again && $first->id_archive === $again->id_archive;

                    return $out;
                });
                $t->eq($res['unsupported_po'], null, 'BR-14: PURCHASE_ORDER -> tidak ada baris');
                $t->eq($res['unsupported_999'], null, 'BR-14: tipe 999 -> tidak ada baris');
                $t->eq($res['no_type'], null, 'BR-14: tanpa tipe -> tidak ada baris');
                $t->eq($t->db()->table('archives')->where('name', 'like', 'QA01-UNSUP%')->count(), 0, 'BR-14: DB tanpa baris tipe tak didukung');
                $t->true($res['sup_created'] !== null, 'BR-14: tipe 6 dicatat');
                $t->true($res['sup_same'] === true, 'store dua kali = baris yang sama (tidak menggandakan)');
            } finally {
                $cleanup([$so->sales_order_no, $bl->billing_no]);
                q1_purge($t);
            }
            $t->rowCount($db, 'archives', ['name' => $so->sales_order_no], 0, 'SO: baris uji dibuang');
            $t->rowCount($db, 'archives', ['name' => $bl->billing_no], 0, 'Billing: baris uji dibuang');
            q1_assert_clean($t, 'data uji AC-11 sudah dibuang');
        },
    ],

];
