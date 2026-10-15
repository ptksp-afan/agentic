<?php
/**
 * Helper skenario ED-1070 (BUKAN skenario: nama berkas tidak cocok scenario*.php). Di-require_once oleh scenario*.php.
 *
 * - Log BE: storage/logs/laravel-<tanggal WIB>.log (AppServiceProvider: Asia/Jakarta) (php-fpm menulis; QA hanya membaca). q70_mark() mencatat ukuran berkas,
 *   q70_since() membaca bagian baru, q70_count() menghitung "[CustomizeBuilder][blocked|malformed]". Developer tidak
 *   memakai server selama run, jadi bagian baru = request QA sendiri.
 * - Semua request di sini GET dan tidak menulis data (payload berbahaya TIDAK dikirim lewat HTTP; lihat
 *   probe/security-probe.php). Data uji yang dibuat hanya folder "QA02-" lewat helper ED-1025 (AC-8), dibuang di finally.
 */

if (!function_exists('q70_url')) {

    function q70_url($path, array $q = [])
    {
        $parts = [];
        foreach ($q as $k => $v) {
            if (is_int($k)) {
                $parts[] = $v; // query mentah, mis. 'search[]=x'
            } else {
                $parts[] = rawurlencode($k) . '=' . rawurlencode(is_array($v) ? json_encode($v) : (string) $v);
            }
        }

        return $path . ($parts ? (strpos($path, '?') === false ? '?' : '&') . implode('&', $parts) : '');
    }

    function q70_log_file($t)
    {
        return rtrim(str_replace('\\', '/', (string) $t->conf('BE_DIR')), '/') . '/storage/logs/laravel-' . (new \DateTime('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d') . '.log';
    }

    function q70_mark($t)
    {
        clearstatcache();
        $f = q70_log_file($t);

        return [$f, is_file($f) ? filesize($f) : 0];
    }

    function q70_since($t, array $mark)
    {
        clearstatcache();
        list($f, $pos) = $mark;
        if (!is_file($f)) {
            return '';
        }
        $size = filesize($f);
        if ($size < $pos) {
            $pos = 0; // berkas diputar
        }

        return (string) file_get_contents($f, false, null, $pos);
    }

    /** Hitung entri log: kind = blocked|malformed|error (error = level ERROR/CRITICAL/ALERT/EMERGENCY apa pun). */
    function q70_count($text, $kind)
    {
        if ($kind === 'error') {
            return preg_match_all('/^\[\d{4}-\d{2}-\d{2} [^\]]+\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY):/m', $text);
        }

        return substr_count($text, '[CustomizeBuilder][' . $kind . ']');
    }

    /** Alasan (teks sesudah tag) tiap entri [CustomizeBuilder] di $text. */
    function q70_reasons($text)
    {
        preg_match_all('/\[CustomizeBuilder\]\[(blocked|malformed)\] ([^{\n]*?) \{/', $text, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $x) {
            $out[] = $x[1] . ':' . trim($x[2]);
        }

        return $out;
    }

    /**
     * GET + log: [status, json, logText]. Request dijalankan, lalu bagian log baru dibaca.
     */
    function q70_get($t, $s, $uri)
    {
        $mark = q70_mark($t);
        $r = $t->call($s, 'GET', $uri);
        usleep(50000);

        return [$r[0], $r[1], q70_since($t, $mark)];
    }

    function q70_total($r)
    {
        return $r[1]['result']['total'] ?? null;
    }

    function q70_per_page($r)
    {
        $res = $r[1]['result'] ?? [];

        return $res['perPage'] ?? $res['per_page'] ?? null;
    }

    function q70_page($r)
    {
        $res = $r[1]['result'] ?? [];

        return $res['currentPage'] ?? $res['current_page'] ?? null;
    }

    /** id baris (kolom $idField snake atau camel) berurutan. */
    function q70_ids($r, $idField)
    {
        $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $idField))));
        $out = [];
        foreach ((array) ($r[1]['result']['data'] ?? []) as $row) {
            $out[] = (string) ($row[$idField] ?? $row[$camel] ?? '?');
        }

        return $out;
    }

    /**
     * Asersi: status 200, tepat $nMal malformed dan $nBlk blocked di log request ini, tanpa log error, alasan
     * malformed memuat $reason (bila diberikan), dan total = $expTotal (bila tidak null).
     */
    function q70_expect($t, array $r, $label, $nMal, $nBlk = 0, $reason = null, $expTotal = null)
    {
        $t->status([$r[0], $r[1]], 200, $label);
        $t->eq(q70_count($r[2], 'malformed'), $nMal, $label . ': jumlah log malformed');
        $t->eq(q70_count($r[2], 'blocked'), $nBlk, $label . ': jumlah log blocked');
        $t->eq(q70_count($r[2], 'error'), 0, $label . ': tanpa log level error');
        if ($reason !== null) {
            $hit = false;
            foreach (q70_reasons($r[2]) as $x) {
                if (strpos($x, $reason) !== false) {
                    $hit = true;
                }
            }
            $t->true($hit, $label . ': alasan log memuat "' . $reason . '" (' . implode(' | ', q70_reasons($r[2])) . ')');
        }
        if ($expTotal !== null) {
            $t->eq(q70_total([$r[0], $r[1]]), $expTotal, $label . ': total');
        }
    }
}
