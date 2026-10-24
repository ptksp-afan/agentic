<?php
/**
 * ED-1250 - probe in-process dan isolasi tenant (BE):
 *   AC-12  ArchiveService::index dipanggil di proses CLI (Laravel ter-bootstrap, user QA ber-OD di Auth) untuk root dan folder level 3,
 *          jumlah query SQL (DB::listen) dengan filter VERIFIED / UNVERIFIED = tanpa filter (urutan tanpa-filter / filter diselang-seling
 *          supaya cache proses tidak mengacaukan); tidak bertambah dengan jumlah baris (pagination 20 vs 200); waktu dicatat (informasi).
 *   ISO-1  isolasi tenant: butuh >= 2 DB di QA_DBS (di sini satu DB) -> SKIP bila kurang.
 */
require_once __DIR__ . '/qa_lib.php';

/** Jalankan ArchiveService::index in-process; mengembalikan [jumlah query, ms, jumlah baris, total, sql]. */
$q50_inproc = function ($t, $uid, array $query) {
    return $t->probe(function () use ($uid, $query) {
        $user = \App\Models\User::where('id_user', $uid)->first();
        if (!$user) {
            throw new \RuntimeException('user QA tidak ditemukan');
        }
        \Illuminate\Support\Facades\Auth::setUser($user);
        $request = \Illuminate\Http\Request::create('/api/v5/document-archive/archives', 'GET', $query);
        $count = 0;
        $sqls = [];
        $on = true;
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$count, &$sqls, &$on) {
            if ($on) {
                $count++;
                $sqls[] = preg_replace('/\s+/', ' ', substr($q->sql, 0, 160));
            }
        });
        $t0 = microtime(true);
        try {
            $out = (new \Modules\V5\Http\Services\DocumentArchive\ArchiveService)->index($request);
        } finally {
            $on = false;
        }

        return ['n' => $count, 'ms' => (int) round((microtime(true) - $t0) * 1000), 'rows' => count($out['data'] ?? []), 'total' => $out['total'] ?? null, 'sql' => $sqls];
    });
};

return [

    [
        'id'    => 'AC-12',
        'title' => 'probe in-process ArchiveService::index (root dan folder level 3): jumlah query SQL dengan filter = tanpa filter, tidak bertambah dengan jumlah baris; waktu dicatat (informasi)',
        'run'   => function ($t) use ($q50_inproc) {
            q50_baseline($t);
            try {
                $x = q50_tree($t);
                $uid = q50_uid($t);
                foreach (['smr', 'bypass'] as $mode) {
                    q50_with_user($t, q50_opts($mode), function () use ($t, $x, $uid, $mode, $q50_inproc) {
                        $cases = [
                            'root'      => ['pagination' => 20],
                            'folder L3' => ['pagination' => 20, 'id_archive' => $x['MX']],
                        ];
                        foreach ($cases as $label => $q) {
                            $none = $q50_inproc($t, $uid, $q);
                            $seq = [
                                'tanpa#2'        => $q50_inproc($t, $uid, $q),
                                'VERIFIED'       => $q50_inproc($t, $uid, $q + ['search' => json_encode([Q50_FILTER => 'VERIFIED'])]),
                                'tanpa#3'        => $q50_inproc($t, $uid, $q),
                                'UNVERIFIED'     => $q50_inproc($t, $uid, $q + ['search' => json_encode([Q50_FILTER => 'UNVERIFIED'])]),
                                'tanpa#4'        => $q50_inproc($t, $uid, $q),
                            ];
                            $t->true($none['n'] > 0, "[$mode] $label: listener menghitung query ({$none['n']})");
                            $t->eq($seq['tanpa#2']['n'], $seq['tanpa#3']['n'], "[$mode] $label: jumlah query tanpa filter stabil (hangat)");
                            $t->eq($seq['VERIFIED']['n'], $seq['tanpa#3']['n'], "[$mode] $label: query dengan VERIFIED = tanpa filter (" . $seq['VERIFIED']['n'] . ')');
                            $t->eq($seq['UNVERIFIED']['n'], $seq['tanpa#4']['n'], "[$mode] $label: query dengan UNVERIFIED = tanpa filter (" . $seq['UNVERIFIED']['n'] . ')');
                            $t->eq($seq['VERIFIED']['n'], $seq['UNVERIFIED']['n'], "[$mode] $label: VERIFIED = UNVERIFIED");
                            // filter benar-benar bekerja di probe (kontrol): jumlah baris berbeda dari tanpa filter
                            $t->true($seq['VERIFIED']['total'] !== $seq['tanpa#3']['total'] || $seq['UNVERIFIED']['total'] !== $seq['tanpa#3']['total'], "[$mode] $label: filter menyaring di probe (total tanpa {$seq['tanpa#3']['total']}, V {$seq['VERIFIED']['total']}, U {$seq['UNVERIFIED']['total']})");
                            // tidak ada query per baris: pagination 200 = pagination 20
                            $big = $q50_inproc($t, $uid, array_merge($q, ['pagination' => 200]) + ['search' => json_encode([Q50_FILTER => 'UNVERIFIED'])]);
                            $t->true($big['rows'] > $seq['UNVERIFIED']['rows'] || $seq['UNVERIFIED']['rows'] < 20, "[$mode] $label: pagination 200 memuat lebih banyak baris ({$big['rows']} vs {$seq['UNVERIFIED']['rows']})");
                            $t->eq($big['n'], $seq['UNVERIFIED']['n'], "[$mode] $label: jumlah query tidak bertambah dengan jumlah baris");
                            $t->note(sprintf('[%s] %s: query %d (tanpa/V/U sama); ms tanpa %d, V %d, U %d', $mode, $label, $seq['tanpa#3']['n'], $seq['tanpa#3']['ms'], $seq['VERIFIED']['ms'], $seq['UNVERIFIED']['ms']));
                        }
                    });
                }
            } finally {
                q50_cleanup($t);
            }
        },
    ],

    [
        'id'    => 'ISO-1',
        'title' => 'isolasi tenant: permintaan bergantian terikat dua tenant membaca DB masing-masing (butuh >= 2 DB di QA_DBS)',
        'run'   => function ($t) {
            $dbs = $t->dbs();
            if (count($dbs) < 2) {
                $t->skip('QA_DBS memuat satu DB (' . implode(',', $dbs) . '): isolasi tenant tidak dapat diuji (tidak berlaku)');
            }
            $t->isolation($t->session($dbs[0]), $t->session($dbs[1]), 10);
        },
    ],
];
