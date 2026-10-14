<?php
/**
 * ED-1027 (REVISI 2026-10-08) - peta saringan display setting per permission (O-3, BR-19, ColumnDisplaySettingService::PERMISSION_FILTERS):
 *   AC-24  setting/display-columns/documentArchive: tanpa Opname Document GET tanpa entri documentVerified (current & available);
 *          PUT dari role itu (ubah urutan + sembunyikan kolom lain) -> 200, perubahan lain tersimpan, baris DB tetap memuat
 *          documentVerified (available, dan current sesudah kolom yang mendahuluinya); pemegang melihat entri; modul lain tak berubah;
 *   X-12   (celah) nama modul varian huruf besar/kecil / spasi akhir (kolasi latin1_general_ci DB menganggap sama) tidak boleh
 *          melewati saringan: GET tanpa entri, PUT mempertahankan entri;
 *   X-13   tepi PUT: list tidak dikirim tidak ditulis, available_columns null, PUT identik berulang tidak 500, pemegang boleh menyembunyikan.
 * Pasangan "dengan / tanpa" = preset QA27-ROLE-LIST (List Archive saja) yang SAMA + q27_with_grant (ED-1149: bukan role 29). Baris column_display_settings yang disentuh
 * (documentArchive, customer) dicadangkan utuh dan dikembalikan persis di finally; sidik jari seluruh tabel dicek.
 */
require_once __DIR__ . '/qa_lib.php';

$q27_seq10 = function ($list) {
    return array_map(function ($col) {
        return is_array($col['data_index']) ? implode('.', $col['data_index']) : $col['data_index'];
    }, (array) $list);
};

$q27_dsrow = function ($t, $module) {
    return q27_rows($t->db()->table('column_display_settings')->where('module_name', $module)->get());
};

/** Kembalikan baris column_display_settings $module ke $snapshot persis (hapus lalu masukkan). */
$q27_dsrestore = function ($t, $module, array $snapshot) {
    q27_w($t, function ($w) use ($module, $snapshot) {
        $w->table('column_display_settings')->where('module_name', $module)->delete();
        if ($snapshot) {
            $w->table('column_display_settings')->insert($snapshot);
        }
    });
};

$q27_dsurl = 'api/v5/setting/display-columns/';

return [

    [
        'id'    => 'AC-24',
        'title' => '(rev) display-columns documentArchive: tanpa Opname Document GET tanpa entri documentVerified (current & available); PUT (ubah urutan + sembunyikan kolom lain) 200, perubahan lain tersimpan, baris DB tetap memuat documentVerified (available; current sesudah kolom pendahulunya); pemegang melihat entri; modul lain (customer) tak berubah',
        'run'   => function ($t) use ($q27_seq10, $q27_dsrow, $q27_dsrestore, $q27_dsurl) {
            $s = $t->session();
            q27_baseline($t);
            $snap = $q27_dsrow($t, 'documentArchive');
            $snapCust = $q27_dsrow($t, 'supplier'); // modul lain tanpa alias (customer beralias ke salesmanActivity.customer di lisensi Salesman Activity)
            $t->eq(count($snapCust), 1, 'prasyarat: 1 baris supplier');
            $t->eq(count($snap), 1, 'prasyarat: 1 baris documentArchive');
            $fpBefore = q27_sum($t, 'column_display_settings', q27_cols($t, 'column_display_settings'));
            $storedCur = json_decode($snap[0]['current_columns'], true);
            $storedAvail = json_decode($snap[0]['available_columns'], true);
            $sc = $q27_seq10($storedCur);
            $sa = $q27_seq10($storedAvail);
            $t->true(in_array('documentVerified', $sc, true) && in_array('documentVerified', $sa, true), 'prasyarat: baris tersimpan memuat documentVerified di current & available: ' . implode(',', $sc));
            $iV = array_search('documentVerified', $sc, true);
            $pred = $sc[$iV - 1] ?? null; // kolom yang mendahului documentVerified di baris tersimpan (document.relatedEmployeeName)
            $t->eq($pred, 'document.relatedEmployeeName', 'prasyarat: pendahulu documentVerified di current = Salesman');
            try {
                q27_with_user($t, ['role' => Q27_PRESET_LIST, 'emp' => ['SMR']], function () use ($t, $s, $q27_seq10, $q27_dsurl, $sc, $sa, $storedCur, $storedAvail, $q27_dsrow, $q27_dsrestore, $snapCust) {
                    // ---------- A. tanpa Opname Document: GET disaring
                    $g = $t->call($s, 'GET', $q27_dsurl . 'documentArchive');
                    $t->status($g, 200, 'tanpa OD: GET documentArchive');
                    $res = $g[1]['result'];
                    $cur = $q27_seq10($res['current_columns']);
                    $avail = $q27_seq10($res['available_columns']);
                    $t->eq($cur, array_values(array_diff($sc, ['documentVerified'])), 'tanpa OD: current_columns = tersimpan tanpa documentVerified (urutan sama)');
                    $t->eq($avail, array_values(array_diff($sa, ['documentVerified'])), 'tanpa OD: available_columns = tersimpan tanpa documentVerified (urutan sama)');
                    $t->true(strpos(json_encode($g[1]), 'documentVerified') === false && strpos(json_encode($g[1]), 'Document Verified') === false, 'tanpa OD: body GET tanpa jejak documentVerified / Document Verified');
                    $t->true(array_key_exists('current_queries', $res) && array_key_exists('available_queries', $res), 'tanpa OD: queries tetap dikirim (tidak terdampak)');
                    $gCust = $t->call($s, 'GET', $q27_dsurl . 'customer');
                    $t->status($gCust, 200, 'tanpa OD: GET customer (hanya baca)');

                    // ---------- B. tanpa OD: PUT ubah urutan + sembunyikan kolom lain
                    // current baru: type, name, updatedAt, salesman (history disembunyikan); available baru: urutan dibalik
                    $c = $res['current_columns'];
                    $newCur = [$c[2], $c[0], $c[1], $c[3]];
                    $newAvail = array_reverse($res['available_columns']);
                    $body = ['current_columns' => $newCur, 'available_columns' => $newAvail, 'current_queries' => $res['current_queries'], 'available_queries' => $res['available_queries']];
                    $p = $t->call($s, 'PUT', $q27_dsurl . 'documentArchive', $body);
                    $t->status($p, 200, 'tanpa OD: PUT documentArchive (urutan + sembunyikan history)');
                    $row = $q27_dsrow($t, 'documentArchive');
                    $t->eq(count($row), 1, 'sesudah PUT: tetap 1 baris documentArchive');
                    $dbCur = $q27_seq10(json_decode($row[0]['current_columns'], true));
                    $dbAvail = $q27_seq10(json_decode($row[0]['available_columns'], true));
                    $t->eq($dbCur, ['type', 'name', 'updatedAt', 'document.relatedEmployeeName', 'documentVerified'], 'DB current_columns: urutan baru tersimpan, history disembunyikan, documentVerified dikembalikan sesudah Salesman');
                    $t->eq($dbAvail, ['updatedBy', 'history', 'document.relatedEmployeeName', 'documentVerified', 'type', 'updatedAt', 'name'], 'DB available_columns: urutan terbalik tersimpan, documentVerified dikembalikan sesudah Salesman');
                    $iV = array_search('documentVerified', $sc, true);
                    $stored = json_decode($row[0]['current_columns'], true);
                    $t->eq($stored[4], $storedCur[$iV], 'objek documentVerified yang dikembalikan = objek tersimpan semula (bukan kiriman pemanggil)');
                    // GET lagi (tanpa OD): perubahan lain terlihat, tanpa entri
                    $g2 = $t->call($s, 'GET', $q27_dsurl . 'documentArchive');
                    $t->eq($q27_seq10($g2[1]['result']['current_columns']), ['type', 'name', 'updatedAt', 'document.relatedEmployeeName'], 'tanpa OD: GET sesudah PUT = urutan baru tanpa entri');
                    $t->true(strpos(json_encode($g2[1]), 'documentVerified') === false, 'tanpa OD: GET sesudah PUT tanpa jejak documentVerified');

                    // ---------- C. kiriman pemanggil yang menyertakan documentVerified diabaikan (nilai tersimpan menang)
                    $fake = $storedCur[$iV];
                    $fake['title'] = ['en' => 'HACKED', 'id' => 'HACKED'];
                    $p = $t->call($s, 'PUT', $q27_dsurl . 'documentArchive', ['current_columns' => array_merge(array_reverse($newCur), [$fake]), 'available_columns' => $newAvail]);
                    $t->status($p, 200, 'tanpa OD: PUT dengan documentVerified palsu di body (urutan current dibalik)');
                    $row = $q27_dsrow($t, 'documentArchive');
                    $t->true(strpos($row[0]['current_columns'], 'HACKED') === false, 'kiriman documentVerified pemanggil diabaikan (nilai tersimpan menang)');
                    $t->eq(substr_count($row[0]['current_columns'], 'documentVerified'), 1, 'documentVerified tepat satu di current_columns (tanpa ganda)');

                    // ---------- D. pemegang Opname Document: melihat entri (urutan tersimpan), boleh menyembunyikan
                    q27_with_grant($t, Q27_PRESET_LIST, Q27_OD, function () use ($t, $s, $q27_seq10, $q27_dsurl, $q27_dsrow, $gCust) {
                        $g = $t->call($s, 'GET', $q27_dsurl . 'documentArchive');
                        $t->status($g, 200, 'dengan OD: GET documentArchive');
                        $t->eq($q27_seq10($g[1]['result']['current_columns']), ['document.relatedEmployeeName', 'documentVerified', 'updatedAt', 'name', 'type'], 'dengan OD: current_columns memuat documentVerified tepat sesudah Salesman (urutan tersimpan)');
                        $t->true(in_array('documentVerified', $q27_seq10($g[1]['result']['available_columns']), true), 'dengan OD: available_columns memuat documentVerified');
                        // modul lain tidak berubah antara dengan / tanpa OD
                        $gc = $t->call($s, 'GET', $q27_dsurl . 'customer');
                        $t->status($gc, 200, 'dengan OD: GET customer');
                        $t->eq(json_encode($gc[1]['result']), json_encode($gCust[1]['result']), 'modul customer: GET identik dengan / tanpa OD');
                        // pemegang menyembunyikan documentVerified dari current: diterima apa adanya
                        $res = $g[1]['result'];
                        $cur = array_values(array_filter($res['current_columns'], function ($c) {
                            return ($c['data_index'] ?? null) !== 'documentVerified';
                        }));
                        $p = $t->call($s, 'PUT', $q27_dsurl . 'documentArchive', ['current_columns' => $cur, 'available_columns' => $res['available_columns'], 'current_queries' => $res['current_queries'], 'available_queries' => $res['available_queries']]);
                        $t->status($p, 200, 'dengan OD: PUT menyembunyikan documentVerified');
                        $row = $q27_dsrow($t, 'documentArchive');
                        $t->true(strpos($row[0]['current_columns'], 'documentVerified') === false, 'dengan OD: documentVerified hilang dari current_columns (pemegang boleh)');
                        $t->true(strpos($row[0]['available_columns'], 'documentVerified') !== false, 'dengan OD: documentVerified tetap di available_columns');
                    });

                    // ---------- E. tanpa OD lagi: entri yang disembunyikan pemegang tidak "dimunculkan" oleh PUT pemanggil ini
                    $g = $t->call($s, 'GET', $q27_dsurl . 'documentArchive');
                    $t->true(strpos(json_encode($g[1]), 'documentVerified') === false, 'tanpa OD (setelah disembunyikan pemegang): GET tanpa entri');
                    $res = $g[1]['result'];
                    $p = $t->call($s, 'PUT', $q27_dsurl . 'documentArchive', ['current_columns' => array_reverse($res['current_columns']), 'available_columns' => $res['available_columns']]);
                    $t->status($p, 200, 'tanpa OD: PUT sesudah entri disembunyikan pemegang');
                    $row = $q27_dsrow($t, 'documentArchive');
                    $t->true(strpos($row[0]['current_columns'], 'documentVerified') === false, 'documentVerified tetap tidak ada di current_columns (tadinya tidak ada di sana)');
                    $t->true(strpos($row[0]['available_columns'], 'documentVerified') !== false, 'documentVerified tetap ada di available_columns');

                    // ---------- F. modul lain (supplier): PUT dari role tanpa OD tersimpan apa adanya (saringan tidak menyentuh modul lain)
                    $gs = $t->call($s, 'GET', $q27_dsurl . 'supplier');
                    $t->status($gs, 200, 'tanpa OD: GET supplier');
                    $rc = $gs[1]['result'];
                    $cc = array_reverse($rc['current_columns']);
                    $p = $t->call($s, 'PUT', $q27_dsurl . 'supplier', ['current_columns' => $cc, 'available_columns' => $rc['available_columns'], 'current_queries' => $rc['current_queries'], 'available_queries' => $rc['available_queries']]);
                    $t->status($p, 200, 'tanpa OD: PUT supplier');
                    $after = $q27_dsrow($t, 'supplier');
                    $t->eq($q27_seq10(json_decode($after[0]['current_columns'], true)), $q27_seq10($cc), 'supplier: current_columns tersimpan persis kiriman (saringan tidak menyentuh modul lain)');
                    $t->true(strpos($after[0]['current_columns'], 'documentVerified') === false && strpos($after[0]['available_columns'], 'documentVerified') === false, 'supplier: tak ada jejak documentVerified');
                });
            } finally {
                $q27_dsrestore($t, 'documentArchive', $snap);
                $q27_dsrestore($t, 'supplier', $snapCust);
                q27_cleanup($t);
            }
            $t->eq(json_encode($q27_dsrow($t, 'documentArchive')), json_encode($snap), 'baris documentArchive dipulihkan persis');
            $t->eq(json_encode($q27_dsrow($t, 'supplier')), json_encode($snapCust), 'baris supplier dipulihkan persis');
            $t->eq(q27_sum($t, 'column_display_settings', q27_cols($t, 'column_display_settings')), $fpBefore, 'seluruh tabel column_display_settings sama persis dengan sebelum AC');
        },
    ],

    [
        'id'    => 'X-12',
        'title' => '(celah) nama modul varian huruf besar/kecil atau spasi akhir (DB menganggap sama) tidak melewati saringan: GET tanpa Opname Document tetap tanpa entri documentVerified; PUT tetap mempertahankan entri tersimpan',
        'run'   => function ($t) use ($q27_seq10, $q27_dsrow, $q27_dsrestore, $q27_dsurl) {
            $s = $t->session();
            q27_baseline($t);
            $snap = $q27_dsrow($t, 'documentArchive');
            $fpBefore = q27_sum($t, 'column_display_settings', q27_cols($t, 'column_display_settings'));
            try {
                q27_with_user($t, ['role' => Q27_PRESET_LIST, 'emp' => ['SMR']], function () use ($t, $s, $q27_seq10, $q27_dsrow, $q27_dsrestore, $q27_dsurl, $snap) {
                    $variants = ['DOCUMENTARCHIVE', 'documentarchive', 'DocumentArchive', 'documentArchive%20', 'DOCUMENTARCHIVE%20%20', 'dOCUMENTaRCHIVE%20%20%20%20'];
                    $leaks = [];
                    foreach ($variants as $v) {
                        $g = $t->call($s, 'GET', $q27_dsurl . $v);
                        $t->true($g[0] < 500, "GET [$v]: HTTP {$g[0]} (tidak 500)");
                        if (strpos(json_encode($g[1]), 'documentVerified') !== false) {
                            $leaks[] = $v;
                        }
                    }
                    $wipes = [];
                    foreach ($variants as $v) {
                        // kirim body tanpa documentVerified (seperti form FE) ke nama varian; entri tersimpan harus tetap ada di DB
                        $base = $t->call($s, 'GET', $q27_dsurl . 'documentArchive');
                        $res = $base[1]['result'];
                        $q27_dsrestore($t, 'documentArchive', $snap);
                        sleep(1);
                        $p = $t->call($s, 'PUT', $q27_dsurl . $v, ['current_columns' => array_slice($res['current_columns'], 0, 3), 'available_columns' => $res['available_columns'], 'current_queries' => $res['current_queries'], 'available_queries' => $res['available_queries']]);
                        $t->true($p[0] < 500, "PUT [$v]: HTTP {$p[0]} (tidak 500)");
                        $row = $q27_dsrow($t, 'documentArchive');
                        if (strpos($row[0]['available_columns'], 'documentVerified') === false || strpos($row[0]['current_columns'], 'documentVerified') === false) {
                            $wipes[] = $v . ' (HTTP ' . $p[0] . ')';
                        }
                    }
                    $t->note('varian nama modul yang membocorkan entri lewat GET: ' . json_encode($leaks) . '; yang menghapus entri tersimpan lewat PUT: ' . json_encode($wipes));
                    $t->true($leaks === [], 'tanpa OD GET varian nama modul tanpa entri documentVerified; membocorkan: ' . json_encode($leaks));
                    $t->true($wipes === [], 'tanpa OD PUT varian nama modul mempertahankan entri documentVerified di DB; menghapus: ' . json_encode($wipes));
                });
            } finally {
                $q27_dsrestore($t, 'documentArchive', $snap);
                q27_cleanup($t);
            }
            $t->eq(json_encode($q27_dsrow($t, 'documentArchive')), json_encode($snap), 'baris documentArchive dipulihkan persis');
            $t->eq(q27_sum($t, 'column_display_settings', q27_cols($t, 'column_display_settings')), $fpBefore, 'seluruh tabel column_display_settings sama persis dengan sebelum AC');
        },
    ],

    [
        'id'    => 'X-13',
        'title' => 'tepi PUT display-columns documentArchive tanpa Opname Document: list yang tidak dikirim tidak ditulis; available_columns null tidak menghapus entri; PUT identik berulang tidak 500; body kosong/invalid tidak 500; GET modul tak dikenal tidak 500',
        'run'   => function ($t) use ($q27_seq10, $q27_dsrow, $q27_dsrestore, $q27_dsurl) {
            $s = $t->session();
            q27_baseline($t);
            $snap = $q27_dsrow($t, 'documentArchive');
            $fpBefore = q27_sum($t, 'column_display_settings', q27_cols($t, 'column_display_settings'));
            try {
                q27_with_user($t, ['role' => Q27_PRESET_LIST, 'emp' => ['SMR']], function () use ($t, $s, $q27_seq10, $q27_dsrow, $q27_dsurl, $snap) {
                    $g = $t->call($s, 'GET', $q27_dsurl . 'documentArchive');
                    $res = $g[1]['result'];
                    $cur = array_slice($res['current_columns'], 0, 4);

                    // hanya current_columns dikirim: available_columns di DB tidak ditulis
                    $p = $t->call($s, 'PUT', $q27_dsurl . 'documentArchive', ['current_columns' => array_reverse($cur)]);
                    $t->status($p, 200, 'PUT hanya current_columns');
                    $row = $q27_dsrow($t, 'documentArchive');
                    $t->eq($row[0]['available_columns'], $snap[0]['available_columns'], 'available_columns tidak dikirim -> tidak ditulis (identik dengan semula)');
                    $t->true(strpos($row[0]['current_columns'], 'documentVerified') !== false, 'current_columns DB masih memuat documentVerified');

                    // available_columns null: entri tersimpan dipertahankan
                    $p = $t->call($s, 'PUT', $q27_dsurl . 'documentArchive', ['current_columns' => $cur, 'available_columns' => null]);
                    $t->status($p, 200, 'PUT available_columns null');
                    $row = $q27_dsrow($t, 'documentArchive');
                    $t->true(strpos($row[0]['available_columns'], 'documentVerified') !== false, 'available_columns null: documentVerified tetap ada di DB');

                    // PUT identik berulang. PERILAKU LAMA update(): bila affected rows 0 (nilai sama DAN updated_at tidak berubah karena masih
                    // di detik yang sama) -> ErrorMessageException FAILED 500; berlaku juga untuk pemegang OD dan modul lain. Di sini jeda 1 detik
                    // supaya updated_at berubah: kedua PUT harus 200; kasus detik-sama diukur dan dicatat sebagai CATATAN (bukan jalur baru).
                    $same = ['current_columns' => $cur, 'available_columns' => $res['available_columns'], 'current_queries' => $res['current_queries'], 'available_queries' => $res['available_queries']];
                    $t->status($t->call($s, 'PUT', $q27_dsurl . 'documentArchive', $same), 200, 'PUT ke-1 (berbeda dari DB)');
                    sleep(1);
                    $t->status($t->call($s, 'PUT', $q27_dsurl . 'documentArchive', $same), 200, 'PUT identik ke-2 (jeda 1 detik)');
                    sleep(1);
                    $t->status($t->call($s, 'PUT', $q27_dsurl . 'documentArchive', $same), 200, 'PUT identik ke-3 (jeda 1 detik)');
                    $quick = $t->call($s, 'PUT', $q27_dsurl . 'documentArchive', $same)[0];
                    $t->note('CATATAN (pra-ada): PUT identik di detik yang sama = HTTP ' . $quick . ' (500 = update() affected rows 0 -> FAILED, ColumnDisplaySettingService::update; sama untuk pemegang OD)');

                    // body tidak valid: tidak 500 (validasi 422 / perilaku lama)
                    foreach (['current_columns kosong' => ['current_columns' => []], 'tanpa current_columns' => ['available_columns' => []], 'current_columns null' => ['current_columns' => null], 'current_columns bukan array' => ['current_columns' => 'x']] as $label => $b) {
                        $p = $t->call($s, 'PUT', $q27_dsurl . 'documentArchive', $b);
                        $t->true($p[0] < 500, "PUT [$label]: HTTP {$p[0]} (tidak 500)");
                        $row = $q27_dsrow($t, 'documentArchive');
                        $t->true(strpos($row[0]['available_columns'], 'documentVerified') !== false, "PUT [$label]: documentVerified masih di available_columns DB");
                    }

                    // modul tak dikenal: sama dengan / tanpa OD
                    $u1 = $t->call($s, 'GET', $q27_dsurl . 'moduleTidakAda' . uniqid());
                    $t->true($u1[0] < 500, 'GET modul tak dikenal tanpa OD: HTTP ' . $u1[0]);
                });
            } finally {
                $q27_dsrestore($t, 'documentArchive', $snap);
                q27_cleanup($t);
            }
            $t->eq(json_encode($q27_dsrow($t, 'documentArchive')), json_encode($snap), 'baris documentArchive dipulihkan persis');
            $t->eq(q27_sum($t, 'column_display_settings', q27_cols($t, 'column_display_settings')), $fpBefore, 'seluruh tabel column_display_settings sama persis dengan sebelum AC');
        },
    ],

    [
        'id'    => 'X-14',
        'title' => '(ronde 2) baris documentArchive belum ada: GET tanpa Opname Document membuat baris dari config tetapi responsnya tanpa entri documentVerified (juga varian nama); pemegang melihat entri; baris dipulihkan persis',
        'run'   => function ($t) use ($q27_seq10, $q27_dsrow, $q27_dsrestore, $q27_dsurl) {
            $s = $t->session();
            q27_baseline($t);
            $snap = $q27_dsrow($t, 'documentArchive');
            $t->eq(count($snap), 1, 'prasyarat: 1 baris documentArchive');
            $fpBefore = q27_sum($t, 'column_display_settings', q27_cols($t, 'column_display_settings'));
            try {
                q27_with_user($t, ['role' => Q27_PRESET_LIST, 'emp' => ['SMR']], function () use ($t, $s, $q27_seq10, $q27_dsrow, $q27_dsrestore, $q27_dsurl, $snap) {
                    // a) nama persis, baris tidak ada -> show() membuat baris dari config; respons tetap disaring
                    $q27_dsrestore($t, 'documentArchive', []); // hapus baris
                    $t->eq(count($q27_dsrow($t, 'documentArchive')), 0, 'baris documentArchive dihapus sementara');
                    $g = $t->call($s, 'GET', $q27_dsurl . 'documentArchive');
                    $t->status($g, 200, 'baris tidak ada, tanpa OD: GET documentArchive');
                    $t->true(is_array($g[1]['result']['current_columns'] ?? null) && count($g[1]['result']['current_columns']) > 0, 'baris tidak ada, tanpa OD: current_columns terisi (config)');
                    $t->true(strpos(json_encode($g[1]), 'documentVerified') === false && strpos(json_encode($g[1]), 'Document Verified') === false, 'baris tidak ada, tanpa OD: respons tanpa jejak documentVerified');
                    $made = $q27_dsrow($t, 'documentArchive');
                    $t->eq(count($made), 1, 'baris dibuat dari config oleh GET (perilaku lama)');
                    $t->true(strpos($made[0]['current_columns'], 'documentVerified') !== false, 'baris yang dibuat dari config memuat documentVerified di DB (disaring hanya di respons)');
                    // b) varian nama (baris sudah ada lagi) tetap disaring
                    foreach (['DOCUMENTARCHIVE', 'documentArchive%20%20'] as $v) {
                        $gv = $t->call($s, 'GET', $q27_dsurl . $v);
                        $t->status($gv, 200, "tanpa OD: GET [$v]");
                        $t->true(strpos(json_encode($gv[1]), 'documentVerified') === false, "tanpa OD: GET [$v] tanpa jejak documentVerified");
                    }
                    // c) pemegang Opname Document melihat entri dari baris yang sama
                    q27_with_grant($t, Q27_PRESET_LIST, Q27_OD, function () use ($t, $s, $q27_seq10, $q27_dsurl) {
                        $go = $t->call($s, 'GET', $q27_dsurl . 'DOCUMENTARCHIVE');
                        $t->status($go, 200, 'dengan OD: GET [DOCUMENTARCHIVE]');
                        $t->true(in_array('documentVerified', $q27_seq10($go[1]['result']['current_columns']), true), 'dengan OD: entri documentVerified ada (varian nama)');
                    });
                });
            } finally {
                $q27_dsrestore($t, 'documentArchive', $snap);
                q27_cleanup($t);
            }
            $t->eq(json_encode($q27_dsrow($t, 'documentArchive')), json_encode($snap), 'baris documentArchive dipulihkan persis');
            $t->eq(q27_sum($t, 'column_display_settings', q27_cols($t, 'column_display_settings')), $fpBefore, 'seluruh tabel column_display_settings sama persis dengan sebelum AC');
        },
    ],
];
