<?php
/**
 * Contoh skenario QA HTTP. Salin ke features/<KEY>-<slug>/qa/scenario.php lalu ganti placeholder.
 * Jalankan: "$PHP_BIN" scripts/qa-http/run.php <KEY> [--only=AC-1] [--list]
 *
 * File me-return array AC. Satu asersi gagal = AC FAIL, runner lanjut ke AC berikutnya.
 * Satu AC = satu baris di spec.md. Pakai id yang sama dengan spec.
 */

// Ganti dengan endpoint dan tabel fitur ini.
$endpoint = 'api/v5/modul/resource';
$table = 'resources';

return [
    [
        'id'    => 'AC-1',
        'title' => 'Daftar resource: 200 dan berisi result.data',
        'needs' => [],                      // DB yang di-bind lebih dulu; kosong = sesi dibuat saat dipanggil
        'run'   => function ($t) use ($endpoint) {
            $s = $t->session();             // user QA terikat QA_DB (login + bind, sekali per run)
            $r = $t->call($s, 'GET', $endpoint);
            $t->status($r, 200, 'list');
            $t->has($r[1], 'result.data', 'list: kunci result.data');
            $t->true(is_array($r[1]['result']['data']), 'list: data berupa array');
        },
    ],

    [
        'id'    => 'AC-2',
        'title' => 'Tambah resource: tersimpan di DB; data uji dibuat sendiri dan dipulihkan',
        'run'   => function ($t) use ($endpoint, $table) {
            $s = $t->session();
            $name = 'QA-' . uniqid();       // penanda unik: tidak bergantung pada id/baris yang ada
            $db = $t->dbs()[0];             // DB default QA (QA_DB)
            $id = null;
            try {
                $r = $t->call($s, 'POST', $endpoint, ['name' => $name]);
                $t->status($r, 200, 'create');
                $id = $r[1]['result']['id'] ?? null;
                $t->true($id !== null, 'create: id dikembalikan');

                // Baca DB lewat koneksi read-only; $t->db() menolak tulisan.
                $t->rowExists($db, $table, ['name' => $name], 'baris tersimpan');
            } finally {
                // Pulihkan persis: hapus lewat API nyata. Kalau tidak ada endpoint hapus, hapus baris
                // lewat $t->probe(function () { \Illuminate\Support\Facades\DB::connection(...)->table(...)->delete(); }).
                if ($id !== null) {
                    $t->call($s, 'DELETE', $endpoint . '/' . $id);
                }
            }
            $t->rowCount($db, $table, ['name' => $name], 0, 'data uji sudah dibuang');
        },
    ],

    [
        'id'    => 'AC-3',
        'title' => 'Isolasi tenant: dua DB tidak saling bocor',
        'run'   => function ($t) {
            $dbs = $t->dbs();
            if (count($dbs) < 2) {
                $t->skip('butuh dua DB di QA_DBS');
            }
            $t->isolation($t->session($dbs[0]), $t->session($dbs[1]), 5);
        },
    ],
];
