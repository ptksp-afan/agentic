<?php
/**
 * ED-1165 - 12 route api/v5/customer/{address,contacts,bank-accounts}* dinonaktifkan (AC-1, AC-2, AC-5).
 * AC-3/AC-4 (router dump, php -l) = unit, dijalankan lewat shell (lihat qa-be.md). AC-6 = e2e (qa/e2e).
 *
 * Tanpa data bisnis ditulis: POST/PUT hanya dengan body TIDAK VALID (spec AC) dan COUNT 4 tabel dicek
 * sebelum/sesudah. Tidak ada id yang disalin tangan: pelanggan acuan AC-5 dicari lewat DB saat run.
 */

if (!function_exists('ed1165_routes')) {
    /** 12 route R1-R12 (id 5 untuk {id}); body POST/PUT tidak valid. */
    function ed1165_routes()
    {
        $base = 'api/v5/customer/';
        return [
            'R1  GET address'            => ['GET',  $base . 'address?pagination=1', null],
            'R2  GET address/5'          => ['GET',  $base . 'address/5', null],
            'R3  POST address'           => ['POST', $base . 'address', ['customer_addresses' => [new \stdClass()]]],
            'R4  PUT address/update/5'   => ['PUT',  $base . 'address/update/5', ['customer_addresses' => [new \stdClass()]]],
            'R5  GET contacts'           => ['GET',  $base . 'contacts?pagination=1', null],
            'R6  GET contacts/5'         => ['GET',  $base . 'contacts/5', null],
            'R7  POST contacts'          => ['POST', $base . 'contacts', ['customer_contacts' => [new \stdClass()]]],
            'R8  PUT contacts/update/5'  => ['PUT',  $base . 'contacts/update/5', ['customer_contacts' => [new \stdClass()]]],
            'R9  GET bank-accounts'      => ['GET',  $base . 'bank-accounts?pagination=1', null],
            'R10 GET bank-accounts/5'    => ['GET',  $base . 'bank-accounts/5', null],
            'R11 POST bank-accounts'     => ['POST', $base . 'bank-accounts', ['customer_bank_accounts' => [new \stdClass()]]],
            'R12 PUT bank-accounts/update/5' => ['PUT', $base . 'bank-accounts/update/5', ['customer_bank_accounts' => [new \stdClass()]]],
        ];
    }

    /** COUNT 4 tabel di $db (koneksi read-only). */
    function ed1165_counts($t, $db)
    {
        $out = [];
        foreach (['customer_addresses', 'customer_contacts', 'customer_bank_accounts', 'customer_location_discounts'] as $table) {
            $out[$table] = (int) $t->db($db)->table($table)->count();
        }
        return $out;
    }

    /** Kirim R1-R12 (tanpa token bila $s === null); tiap respons harus 404 + message 'Route Not Found!'. */
    function ed1165_assert_disabled($t, $s, $tag)
    {
        foreach (ed1165_routes() as $name => $def) {
            list($method, $uri, $body) = $def;
            $r = $s === null ? $t->raw($method, $uri, $body) : $t->call($s, $method, $uri, $body);
            $t->true($r[0] !== 500, "{$tag} {$name}: tidak boleh 500 (HTTP {$r[0]})");
            $t->status($r, 404, "{$tag} {$name}");
            $t->eq($r[1]['message'] ?? null, 'Route Not Found!', "{$tag} {$name} message");
            $t->true(!isset($r[1]['result']) && !isset($r[1]['msg_code']), "{$tag} {$name}: body tanpa result/msg_code (bukan dari controller)");
        }
    }
}

return [
    [
        'id'    => 'AC-1',
        'title' => 'Tanpa token: R1-R12 (GET/POST/PUT) = 404 "Route Not Found!"; tidak ada tulisan ke DB',
        'run'   => function ($t) {
            $db = $t->dbs()[0];
            $before = ed1165_counts($t, $db);
            $GLOBALS['ed1165_start_counts'] = $GLOBALS['ed1165_start_counts'] ?? $before;
            ed1165_assert_disabled($t, null, 'tanpa-token');
            // varian: trailing slash, tanpa query - tetap 404
            foreach (['address/', 'contacts/', 'bank-accounts/'] as $p) {
                $r = $t->raw('GET', 'api/v5/customer/' . $p);
                $t->status($r, 404, 'GET ' . $p);
                $t->eq($r[1]['message'] ?? null, 'Route Not Found!', 'GET ' . $p . ' message');
            }
            $t->eq(ed1165_counts($t, $db), $before, 'COUNT 4 tabel sesudah AC-1 = sebelum');
            $t->note('12 request tanpa token = 404; COUNT ' . json_encode($before));
        },
    ],

    [
        'id'    => 'AC-2',
        'title' => 'Token QA_USER: R1-R12 = 404 sama; COUNT 4 tabel sebelum = sesudah',
        'run'   => function ($t) {
            $db = $t->dbs()[0];
            $s = $t->session();
            $before = ed1165_counts($t, $db);
            ed1165_assert_disabled($t, $s, 'token');
            $after = ed1165_counts($t, $db);
            $t->eq($after, $before, 'COUNT 4 tabel sesudah = sebelum');
            if (isset($GLOBALS['ed1165_start_counts'])) {
                $t->eq($after, $GLOBALS['ed1165_start_counts'], 'COUNT sesudah AC-1..2 = awal run (AC-1)');
            }
            $t->note('12 request dengan token = 404; COUNT ' . json_encode($after));
        },
    ],

    [
        'id'    => 'AC-5',
        'title' => 'Regresi: customers (list + detail) tetap 200; route tetangga tanpa token tetap 401 GE0111',
        'run'   => function ($t) {
            $db = $t->dbs()[0];
            $s = $t->session();

            // list: total = COUNT customers
            $r = $t->call($s, 'GET', 'api/v5/customer/customers?pagination=5');
            $t->status($r, 200, 'GET customers');
            $t->has($r[1], 'result.total', 'customers: result.total');
            $t->eq($r[1]['result']['total'], (int) $t->db($db)->table('customers')->count(), 'total = COUNT customers');
            $t->true(count($r[1]['result']['data']) === 5, 'customers: 5 baris per halaman');

            // detail pelanggan yang punya alamat+kontak+rekening (dicari lewat DB saat run)
            $rows = $t->db($db)->select(
                'select c.id_customer id from customers c'
                . ' where exists (select 1 from customer_addresses a where a.id_customer = c.id_customer)'
                . ' and exists (select 1 from customer_contacts k where k.id_customer = c.id_customer)'
                . ' and exists (select 1 from customer_bank_accounts b where b.id_customer = c.id_customer)'
                . ' order by c.id_customer limit 1'
            );
            if (!$rows) {
                $t->skip('tidak ada pelanggan dengan alamat+kontak+rekening di ' . $db);
            }
            $id = $rows[0]->id;
            $d = $t->call($s, 'GET', 'api/v5/customer/customers/' . $id);
            $t->status($d, 200, 'GET customers/{id}');
            $t->eq($d[1]['result']['id_customer'] ?? null, $id, 'detail: id_customer');
            foreach ([
                'customer_addresses'     => ['customer_addresses', 'id_customer_address'],
                'customer_contacts'      => ['customer_contacts', 'id_customer_contact'],
                'customer_bank_accounts' => ['customer_bank_accounts', 'id_customer_bank_account'],
            ] as $key => $def) {
                $list = $d[1]['result'][$key] ?? [];
                $t->true(is_array($list) && count($list) >= 1, "detail: {$key} berisi");
                $dbCount = (int) $t->db($db)->table($def[0])->where('id_customer', $id)->count();
                $t->true(count($list) <= $dbCount, "detail: {$key} (" . count($list) . ") <= baris DB ({$dbCount})");
                foreach ($list as $row) {
                    $t->eq($row['id_customer'] ?? null, $id, "detail: {$key}.id_customer");
                }
            }
            $t->note('pelanggan acuan punya alamat/kontak/rekening; total=' . $r[1]['result']['total']);

            // BR-5: route tetangga tidak berubah -> tanpa token tetap 401 GE0111
            foreach (['customer/customers?pagination=1', 'customer/customers/' . $id, 'customer/devices?pagination=1', 'customer/customer-types?pagination=1'] as $uri) {
                $n = $t->raw('GET', 'api/v5/' . $uri);
                $t->status($n, 401, 'tanpa token ' . $uri);
                $t->code($n, 'GE0111', 'tanpa token ' . $uri);
            }
        },
    ],
];
