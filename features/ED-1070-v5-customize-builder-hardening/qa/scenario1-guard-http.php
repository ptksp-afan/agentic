<?php
/**
 * ED-1070 - skenario HTTP (php-fpm, QA_USER, api_sidomaju). Hanya GET; payload yang tetap aman walau guard rusak
 * (whereRaw "1=1", latest/getTable -> 500 bila rusak, sumX;DROP -> syntax error bila rusak). Payload delete()/update()/
 * truncate()/truncate TIDAK dikirim lewat HTTP: dibuktikan in-process oleh probe/security-probe.php.
 * Log dibaca dari storage/logs BE (qa_lib.php). Data uji: hanya folder QA02- (helper ED-1025) di AC-8, dibuang di finally.
 */

require_once __DIR__ . '/qa_lib.php';
require_once __DIR__ . '/../../ED-1025-archive-folder-permission/qa/qa_lib.php';

$Q70_FOLDER = '250224000117709896682704327481'; // "Backup Arsip" (golden archives@folder), hanya dibaca
$Q70_LISTS = [
    'archives' => ['api/v5/document-archive/archives', 'id_archive', []],
    'archives@folder' => ['api/v5/document-archive/archives', 'id_archive', ['id_archive' => $Q70_FOLDER]],
    'customers' => ['api/v5/customer/customers', 'id_customer', []],
    'purchase-orders' => ['api/v5/purchase/purchase-orders', 'id_purchase_order', []],
    'sales-invoices' => ['api/v5/sales/sales-invoices', 'id_sales_invoice', []],
    'items' => ['api/v5/item/items', 'id_item', []],
    'suppliers' => ['api/v5/supplier/suppliers', 'id_supplier', []],
];

/** base (tanpa search) + noop ({"zzzQaNoop":null}: kunci tanpa efek, tanpa log). */
$q70_refs = function ($t, $s, $path, array $extra) {
    $base = q70_get($t, $s, q70_url($path, $extra + ['pagination' => 10]));
    $noop = q70_get($t, $s, q70_url($path, $extra + ['pagination' => 10, 'search' => '{"zzzQaNoop":null}']));
    $t->status([$base[0], $base[1]], 200, $path . ' base');
    $t->status([$noop[0], $noop[1]], 200, $path . ' noop');
    $t->eq(q70_count($noop[2], 'malformed') + q70_count($noop[2], 'blocked'), 0, $path . ' noop tanpa log');

    return [$base, $noop];
};

$q70_probe_lists = function ($t, array $probes) use ($Q70_LISTS, $q70_refs) {
    $s = $t->session();
    $seen = [];
    foreach ($Q70_LISTS as $name => $L) {
        list($path, , $extra) = $L;
        list($base, $noop) = $q70_refs($t, $s, $path, $extra);
        // ronde 2 (D-2): archives juga - search yang hanya berisi entri dilewati/null = seperti tanpa search (spec AC-2)
        $t->eq(q70_total($noop), q70_total($base), "$name: noop = base");
        foreach ($probes as $label => $search) {
            $r = q70_get($t, $s, q70_url($path, $extra + ['pagination' => 10, 'search' => json_encode($search)]));
            q70_expect($t, $r, "$name $label", 0, 1, null, q70_total($base));
            $seen[] = "$name $label=" . $r[0] . '/' . q70_total($r);
        }
    }
    $t->note(implode(' ; ', $seen));
};

return [
    [
        'id' => 'AC-2-http',
        'title' => 'AC-2 (HTTP, payload aman): {"whereRaw()":"1=1"} pada 7 list -> 200, total = tanpa entri, tepat 1 log blocked, tanpa log error',
        'run' => function ($t) use ($q70_probe_lists) {
            $q70_probe_lists($t, ['whereRaw()' => ['whereRaw()' => '1=1']]);
        },
    ],
    [
        'id' => 'AC-3-http',
        'title' => 'AC-3 (HTTP, payload aman): latest/getTable bukan relasi, sumX;DROP identifier tak aman pada 7 list -> 200, total = tanpa entri, tepat 1 log blocked',
        'run' => function ($t) use ($q70_probe_lists) {
            $q70_probe_lists($t, [
                'latest' => ['latest' => ['a' => 'b']],
                'getTable' => ['getTable' => ['a' => 'b']],
                'sumX;DROP' => ['sumX;DROP' => ['>=', '1']],
            ]);
        },
    ],
    [
        'id' => 'AC-2-archives-total',
        'title' => 'AC-2/AC-3 teks spec "total = tanpa search" pada archives root & id_archive: search yang hanya berisi entri diblokir',
        'run' => function ($t) use ($Q70_FOLDER) {
            $s = $t->session();
            $out = [];
            // ronde 2: payload aman AC-2/AC-3 + campuran 3 entri diblokir (+ showRelatedTransaction) -> total & baris = tanpa search
            $payloads = [
                '{"whereRaw()":"1=1"}' => 1,
                '{"latest":{"a":"b"}}' => 1,
                '{"getTable":{"a":"b"}}' => 1,
                '{"truncate":{"a":"b"}}' => 1,
                '{"sumX;DROP":[">=","1"]}' => 1,
                '{"whereRaw()":"1=1","latest":{"a":"b"},"sumX;DROP":[">=","1"]}' => 3,
            ];
            foreach (['root' => [], 'folder' => ['id_archive' => $Q70_FOLDER]] as $label => $extra) {
                $base = q70_get($t, $s, q70_url('api/v5/document-archive/archives', $extra + ['pagination' => 10]));
                foreach ($payloads as $q => $nBlk) {
                    $r = q70_get($t, $s, q70_url('api/v5/document-archive/archives', $extra + ['pagination' => 10, 'search' => $q]));
                    q70_expect($t, $r, "archives $label $q", 0, $nBlk, null, q70_total($base));
                    $t->eq(q70_ids($r, 'id_archive'), q70_ids($base, 'id_archive'), "archives $label $q: baris = tanpa search");
                    $out[] = "$label $q=" . q70_total($r);
                }
                $out[] = "$label tanpa search=" . q70_total($base);
            }
            $t->note(implode(' ; ', $out));
        },
    ],
    [
        'id' => 'AC-4',
        'title' => 'Input salah bentuk -> 200 + tepat 1 log malformed per entri (alasan benar), entri lain tetap jalan: search non-objek, query/sub/sum/relasi, tanggal, relasi customers, limit, sorts',
        'run' => function ($t) {
            $s = $t->session();
            $lists = [
                'archives' => ['api/v5/document-archive/archives', 'id_archive', '{"name":"Backup"}'],
                'customers' => ['api/v5/customer/customers', 'id_customer', '{"query":"a"}'],
                'purchase-orders' => ['api/v5/purchase/purchase-orders', 'id_purchase_order', '{"query":"a"}'],
            ];
            foreach ($lists as $name => $L) {
                list($path, $idf, $legitJson) = $L;
                $isArchive = $name === 'archives';
                $base = q70_get($t, $s, q70_url($path, ['pagination' => 10]));
                $noop = q70_get($t, $s, q70_url($path, ['pagination' => 10, 'search' => '{"zzzQaNoop":null}']));
                $legit = q70_get($t, $s, q70_url($path, ['pagination' => 10, 'search' => $legitJson]));
                $t->status([$legit[0], $legit[1]], 200, "$name filter sah");
                $ref = q70_total($isArchive ? $noop : $base);

                // search non-objek: seluruh search diabaikan
                foreach (['bukan-json', '123', '"str"', '[1,2]'] as $v) {
                    $r = q70_get($t, $s, q70_url($path, ['pagination' => 10, 'search' => $v]));
                    // archives: bukan-json/123/"str" = tidak "mencari" (root); [1,2] = array -> mode mencari (noop)
                    $exp = $isArchive && $v !== '[1,2]' ? q70_total($base) : $ref;
                    q70_expect($t, $r, "$name search=$v", 1, 0, 'search is not a JSON object', $exp);
                }
                // entri salah bentuk diabaikan (sendiri) - alasan per jenis
                foreach ([
                    '{"query":["a"]}' => 'query must be a scalar',
                    '{"subXyz":"a"}' => 'sub filter must be',
                    '{"sumXyz":"a"}' => 'sum filter must be',
                    '{"zzz":["a"]}' => 'relation filter must be',
                    '{"limit":"abc"}' => 'limit is not a positive integer',
                    '{"updatedAt":["abc","def"]}' => 'date range must be',
                    '{"updatedAt":["x"]}' => 'date range must be',
                    '{"updatedAt":[null,null]}' => 'date range must be',
                ] as $q => $reason) {
                    $r = q70_get($t, $s, q70_url($path, ['pagination' => 10, 'search' => $q]));
                    q70_expect($t, $r, "$name search=$q", 1, 0, $reason, $ref);
                }
                // entri lain tetap jalan: filter sah + 3 entri salah bentuk = hasil filter sah saja, 3 log
                $mixed = json_decode($legitJson, true) + ['zzz' => ['a'], 'sumXyz' => 'a', 'subXyz' => 'a'];
                $r = q70_get($t, $s, q70_url($path, ['pagination' => 10, 'search' => json_encode($mixed)]));
                q70_expect($t, $r, "$name filter sah + 3 salah bentuk", 3, 0, null, q70_total($legit));
                $t->eq(q70_ids($r, $idf), q70_ids($legit, $idf), "$name filter sah + 3 salah bentuk: baris = filter sah saja");
                // RF3 runtime: rentang tanggal setengah terbuka tetap berlaku (tanpa log)
                foreach (['[null,"2030-01-01"]', '["2000-01-01",null]'] as $dr) {
                    $r = q70_get($t, $s, q70_url($path, ['pagination' => 10, 'search' => '{"updatedAt":' . $dr . '}']));
                    q70_expect($t, $r, "$name updatedAt $dr", 0, 0);
                }
            }

            // customers: kolom relasi tak dikenal
            $base = q70_get($t, $s, q70_url('api/v5/customer/customers', ['pagination' => 10]));
            $r = q70_get($t, $s, q70_url('api/v5/customer/customers', ['pagination' => 10, 'search' => '{"location":{"zzz":"a"}}']));
            q70_expect($t, $r, 'customers location.zzz', 1, 0, 'unknown relation column', q70_total($base));

            // sorts (archives root + customers)
            foreach (['archives' => ['api/v5/document-archive/archives', 'id_archive', 'name'], 'customers' => ['api/v5/customer/customers', 'id_customer', 'customerName']] as $name => $L) {
                list($path, $idf, $col) = $L;
                $base = q70_get($t, $s, q70_url($path, ['pagination' => 10]));
                foreach (['bukan-json', '{"' . $col . '":"asc"}', '[]'] as $v) {
                    $r = q70_get($t, $s, q70_url($path, ['pagination' => 10, 'sorts' => $v]));
                    q70_expect($t, $r, "$name sorts=$v", 1, 0, 'sorts is not an array', q70_total($base));
                }
                $desc = q70_get($t, $s, q70_url($path, ['pagination' => 10, 'sorts[0]=' . rawurlencode(json_encode(['sortBy' => $col, 'sortType' => 'desc']))]));
                $asc = q70_get($t, $s, q70_url($path, ['pagination' => 10, 'sorts[0]=' . rawurlencode(json_encode(['sortBy' => $col, 'sortType' => 'asc']))]));
                $noType = q70_get($t, $s, q70_url($path, ['pagination' => 10, 'sorts[0]=' . rawurlencode(json_encode(['sortBy' => $col]))]));
                q70_expect($t, $noType, "$name sorts tanpa sortType", 0, 0, null, q70_total($base));
                $t->eq(q70_ids($noType, $idf), q70_ids($desc, $idf), "$name sorts tanpa sortType = desc");
                $t->true(q70_ids($asc, $idf) !== q70_ids($desc, $idf), "$name sort asc != desc (pembeda)");
                $r = q70_get($t, $s, q70_url($path, ['pagination' => 10, 'sorts[0]=' . rawurlencode('["x"]')]));
                q70_expect($t, $r, "$name sorts[0]=[\"x\"]", 1, 0, 'sort entry has no sortBy', q70_total($base));
                $r = q70_get($t, $s, q70_url($path, ['pagination' => 10, 'sorts[0]=' . rawurlencode('["x"]'), 'sorts[1]=' . rawurlencode(json_encode(['sortBy' => $col, 'sortType' => 'asc']))]));
                q70_expect($t, $r, "$name sorts [x] + $col asc", 1, 0, 'sort entry has no sortBy', q70_total($base));
                $t->eq(q70_ids($r, $idf), q70_ids($asc, $idf), "$name sorts [x] + $col asc: sort sah tetap berlaku");
                // bentuk array sorts[0][sortBy]= (req_snake -> sort_by) = malformed, 200
                $r = q70_get($t, $s, q70_url($path, ['pagination' => 10, 'sorts[0][sortBy]=' . $col, 'sorts[0][sortType]=asc']));
                q70_expect($t, $r, "$name sorts bentuk array", 1, 0, 'sort entry has no sortBy', q70_total($base));
            }
        },
    ],
    [
        'id' => 'AC-5',
        'title' => 'normalize_pagination: abc/-1/0/2.5/[]=1/[a]=1 -> 200, per_page default, 1 log malformed; 10&page=2 dihormati; 1000000 diterima (A-8); termasuk customers (proxy ChecknSet) dan Raptor (FormRequest, K-6)',
        'run' => function ($t) {
            $s = $t->session();
            $lists = [
                'archives' => 'api/v5/document-archive/archives',
                'customers' => 'api/v5/customer/customers',
                'purchase-orders' => 'api/v5/purchase/purchase-orders',
                'raptor deposit failed' => 'api/v5/raptor/deposit/failed',
                'raptor invoice queue' => 'api/v5/raptor/invoice/queue',
            ];
            $seen = [];
            foreach ($lists as $name => $path) {
                $base = q70_get($t, $s, $path);
                $t->status([$base[0], $base[1]], 200, "$name tanpa pagination");
                $def = q70_per_page($base);
                $t->true($def !== null && (int) $def >= 1, "$name per_page default terbaca ($def)");
                foreach (['pagination=abc', 'pagination=-1', 'pagination=0', 'pagination=2.5', 'pagination[]=1', 'pagination[a]=1'] as $raw) {
                    $r = q70_get($t, $s, $path . '?' . $raw);
                    q70_expect($t, $r, "$name $raw", 1, 0, 'pagination is not a positive integer', q70_total($base));
                    $t->eq(q70_per_page($r), $def, "$name $raw: per_page = default");
                    $seen[] = "$name $raw=" . $r[0] . '/pp' . q70_per_page($r);
                }
                $r = q70_get($t, $s, $path . '?pagination=10&page=2');
                q70_expect($t, $r, "$name pagination=10&page=2", 0, 0);
                $t->eq(q70_per_page($r), 10, "$name per_page 10");
                $t->eq(q70_page($r), 2, "$name current_page 2");
            }
            $r = q70_get($t, $s, 'api/v5/supplier/suppliers?pagination=1000000');
            q70_expect($t, $r, 'suppliers pagination=1000000 (A-8)', 0, 0);
            $t->eq(q70_per_page($r), 1000000, 'suppliers per_page 1000000');
            $t->note(implode(' ; ', $seen));
        },
    ],
    [
        'id' => 'AC-6',
        'title' => 'normalize_search: search[]=x / search[a]=b pada list yang json_decode sendiri (archives + 3 service lain kolom S) -> 200, search diabaikan, 1 log malformed, tanpa 500',
        'run' => function ($t) {
            $s = $t->session();
            $seen = [];
            foreach ([
                'archives' => 'api/v5/document-archive/archives',
                'users' => 'api/v5/employee/users',
                'sales-invoices' => 'api/v5/sales/sales-invoices',
                'receivable-payments' => 'api/v5/financial/receivable-payments',
            ] as $name => $path) {
                $base = q70_get($t, $s, $path . '?pagination=10');
                $t->status([$base[0], $base[1]], 200, "$name base");
                foreach (['search[]=x', 'search[a]=b', 'search[a][b]=c'] as $raw) {
                    $r = q70_get($t, $s, $path . '?pagination=10&' . $raw);
                    q70_expect($t, $r, "$name $raw", 1, 0, 'search is not a string', q70_total($base));
                    $t->true(strpos($r[2], 'json_decode() expects parameter 1') === false, "$name $raw: tanpa error json_decode di log");
                    $seen[] = "$name $raw=" . $r[0];
                }
            }
            $t->note(implode(' ; ', $seen));
        },
    ],
    [
        'id' => 'AC-8',
        'title' => 'Urutan penolakan tetap: id_archive acak + param salah = 404 ARCHIVE400; folder Izin On tanpa View + param salah = 403 ARCHIVE407',
        'run' => function ($t) {
            q2_recover($t);
            $s = $t->session();
            q2_purge($t);
            $permBase = q2_perm_total($t);
            // ronde 2 (D-2): + search yang hanya berisi entri diblokir = seperti tanpa search -> 404/403 tetap
            $bad = ['pagination=abc', 'search[]=x', 'search=bukan-json', 'sorts=bukan-json', 'search[a]=b', 'search=' . rawurlencode('{"whereRaw()":"1=1"}')];
            try {
                $rand = 'QA70' . substr(str_replace('.', '', uniqid('', true)), 0, 20);
                $t->eq($t->db()->table('archives')->where('id_archive', $rand)->count(), 0, 'id acak tidak ada');
                foreach (array_merge([''], $bad) as $raw) {
                    $r = $t->call($s, 'GET', 'api/v5/document-archive/archives?id_archive=' . $rand . ($raw !== '' ? '&' . $raw : ''));
                    $t->status($r, 404, "id acak $raw");
                    $t->code($r, 'ARCHIVE400', "id acak $raw");
                }
                // folder Izin On (is_folder_permission=1), dibuat 'QA02' -> QA_USER (role non-bypass) tanpa View
                $P = q2_folder($t, 'P70', ['perm' => 1]);
                foreach (array_merge([''], $bad) as $raw) {
                    $r = $t->call($s, 'GET', 'api/v5/document-archive/archives?id_archive=' . $P . ($raw !== '' ? '&' . $raw : ''));
                    $t->status($r, 403, "folder Izin On tanpa View $raw");
                    $t->code($r, 'ARCHIVE407', "folder Izin On tanpa View $raw");
                }
            } finally {
                q2_purge($t);
            }
            q2_assert_clean($t, $permBase);
        },
    ],
    [
        'id' => 'AC-10-http',
        'title' => 'V4 dimatikan: route api/v4 (auth/info, setting/display-columns, employee/users) dan /v4 = 404; app V5 boot (archives 200)',
        'run' => function ($t) {
            $s = $t->session();
            foreach (['api/v4/auth/info', 'api/v4/setting/display-columns/documentArchive', 'api/v4/employee/users', 'api/v4/auth/database', 'v4'] as $u) {
                $r = $t->call($s, 'GET', $u);
                $t->status($r, 404, $u);
                $r = $t->raw('GET', $u);
                $t->status($r, 404, $u . ' tanpa token');
            }
            $r = $t->call($s, 'GET', 'api/v5/document-archive/archives?pagination=10');
            $t->status($r, 200, 'api/v5 archives');
            $r = $t->call($s, 'GET', 'api/v5/auth/info');
            $t->status($r, 200, 'api/v5 auth/info');
        },
    ],
];
