<?php
/**
 * Helper review r3 ED-1251 (BUKAN skenario; di-require_once oleh scenario8b-review.php): pemeriksaan footer/header per halaman PDF
 * (R-3, R-5), geometri teks (Ghostscript txtwrite, tanpa tumpang tindih), ukuran kertas (R-4), render PDF sesi developer di dalam
 * proses BACA SAJA (dev_render.php) dan render laporan lain di dalam proses (vat_render.php).
 *
 *   q251r_pages($bytes, $user)           footer + header per halaman dari teks PDF (q251_pdf): pages, ok[], missing[], rows
 *   q251r_gs($bytes)                     halaman -> baris teks [y, x0, size, text] (null bila gs tak ada)
 *   q251r_geom($lines, $user)            per halaman: celah footer-isi terakhir, celah header-isi pertama (pt)
 *   q251r_assert($t, $label, $bytes, $o) gabungan asersi footer/header/geometri/halaman; mengembalikan ringkasan
 */

require_once __DIR__ . '/qa_check.php';

if (!function_exists('q251r_pages')) {

    define('Q251R_FOOTER_RX', '/^(Printed at|Dicetak pada|Printed by|Dicetak oleh|Page \d+ of \d+|Halaman \d+ dari \d+)/u');

    /** Satu halaman PDF -> footer ("Printed by <user>"/"Dicetak oleh" + "Page i of N"/"Halaman i dari N") dan header (nama perusahaan). */
    function q251r_pages($bytes, $user, $company = null)
    {
        $p = q251_pdf($bytes);
        $n = $p['pages'];
        $out = ['pages' => $n, 'media' => $p['media'], 'footer_ok' => [], 'footer_missing' => [], 'header_ok' => [], 'header_missing' => [], 'texts' => $p['texts']];
        foreach ($p['texts'] as $i => $texts) {
            $txt = q251_norm(implode("\n", $texts));
            $by = (bool) preg_match('/(Printed by|Dicetak oleh)\s+' . preg_quote($user, '/') . '/u', $txt);
            $pg = preg_match('/(Page|Halaman)\s+(\d+)\s+(of|dari)\s+(\d+)/u', $txt, $m) ? [(int) $m[2], (int) $m[4]] : null;
            if ($by && $pg === [$i + 1, $n]) {
                $out['footer_ok'][] = $i + 1;
            } else {
                $out['footer_missing'][] = $i + 1;
            }
            if ($company === null || strpos($txt, $company) !== false) {
                $out['header_ok'][] = $i + 1;
            } else {
                $out['header_missing'][] = $i + 1;
            }
        }

        return $out;
    }

    /** Halaman -> daftar baris teks (span) lewat gs txtwrite TextFormat=0: [y (dari atas, pt), x0, ukuran font, teks]. null bila gs tidak ada. */
    function q251r_gs($bytes)
    {
        $gs = trim((string) @shell_exec('command -v gs 2>/dev/null'));
        if ($gs === '') {
            return null;
        }
        $f = q251_tmpdir() . '/r3-' . uniqid() . '.pdf';
        file_put_contents($f, $bytes);
        $xml = (string) shell_exec(escapeshellarg($gs) . ' -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=txtwrite -dTextFormat=0 -sOutputFile=- ' . escapeshellarg($f) . ' 2>/dev/null');
        @unlink($f);
        $pages = [];
        foreach (array_slice(explode('<page>', $xml), 1) as $pg) {
            $lines = [];
            if (preg_match_all('#<span bbox="([\d.\-]+) ([\d.\-]+) ([\d.\-]+) ([\d.\-]+)"[^>]*size="([\d.]+)">(.*?)</span>#s', $pg, $sp, PREG_SET_ORDER)) {
                foreach ($sp as $s) {
                    preg_match_all('/ c="([^"]*)"/', $s[6], $cs);
                    $text = html_entity_decode(implode('', $cs[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                    $lines[] = [(float) $s[2], (float) $s[1], (float) $s[5], $text, (float) $s[3]];
                }
            }
            $pages[] = $lines;
        }

        return $pages;
    }

    /**
     * Geometri per halaman. Footer = span berpola Q251R_FOOTER_RX; header = span satu-satunya di barisnya yang teksnya sama di halaman 1
     * dan 2 (header berulang) - dipakai bila PDF >= 2 halaman, selain itu $headTexts (regex) dari pemanggil. isi = sisanya.
     * gap_footer = (atas teks footer) - (dasar teks isi terakhir) dalam pt; gap_header = (atas teks isi pertama) - (dasar teks header terakhir).
     * Positif = tidak bertumpuk.
     *
     * @return array per halaman ['footer_y', 'body_last_y', 'gap_footer', 'gap_header', 'n_body']
     */
    function q251r_geom(array $pages, array $headRx = [], array $media = [])
    {
        $headTexts = [];
        if ($headRx === [] && count($pages) >= 2) {
            // header report-header-1 = baris-baris di TENGAH halaman (text-align:center); judul kolom tabel yang membungkus bukan di tengah
            $centre = isset($media[0][0]) ? $media[0][0] / 2 : null;
            $byLine = function ($lines) use ($centre) {
                $g = [];
                foreach ($lines as $l) {
                    if (preg_match(Q251R_FOOTER_RX, $l[3])) {
                        continue;
                    }
                    if ($centre !== null && abs((($l[1] + ($l[4] ?? $l[1])) / 2) - $centre) > 12) {
                        continue;
                    }
                    $g[(string) round($l[0])][] = $l[3];
                }

                return $g;
            };
            $a = $byLine($pages[0]);
            $b = $byLine($pages[1]);
            foreach ($a as $y => $texts) {
                if (count($texts) === 1 && isset($b[$y]) && $b[$y] === $texts && (float) $y < 120) {
                    $headTexts[] = $texts[0];
                }
            }
        }
        $isHead = function ($text) use ($headTexts, $headRx) {
            if (in_array($text, $headTexts, true)) {
                return true;
            }
            foreach ($headRx as $rx) {
                if (preg_match($rx, $text)) {
                    return true;
                }
            }

            return false;
        };
        $res = [];
        foreach ($pages as $lines) {
            $foot = $head = $body = [];
            foreach ($lines as $l) {
                if (trim($l[3]) === '') {
                    continue;
                }
                if (preg_match(Q251R_FOOTER_RX, $l[3])) {
                    $foot[] = $l;
                } elseif ($isHead($l[3])) {
                    $head[] = $l;
                } else {
                    $body[] = $l;
                }
            }
            $r = ['n_head' => count($head), 'n_body' => count($body), 'n_foot' => count($foot), 'footer_y' => null, 'body_last_y' => null, 'gap_footer' => null, 'gap_header' => null];
            if ($foot) {
                $top = min(array_map(function ($l) {
                    return $l[0] - 0.9 * $l[2];
                }, $foot));
                $r['footer_y'] = round(min(array_column($foot, 0)), 1);
                if ($body) {
                    $bottom = max(array_map(function ($l) {
                        return $l[0] + 0.25 * $l[2];
                    }, $body));
                    $r['body_last_y'] = round(max(array_column($body, 0)), 1);
                    $r['gap_footer'] = round($top - $bottom, 1);
                }
            }
            if ($head && $body) {
                $hb = max(array_map(function ($l) {
                    return $l[0] + 0.25 * $l[2];
                }, $head));
                $bt = min(array_map(function ($l) {
                    return $l[0] - 0.9 * $l[2];
                }, $body));
                $r['gap_header'] = round($bt - $hb, 1);
            }
            $res[] = $r;
        }

        return $res;
    }

    /** Kondisi dikumpulkan (tidak langsung menghentikan AC) supaya satu run melaporkan SEMUA halaman/varian yang gagal; q251r_finish() menyatakan gagal. */
    function q251r_chk($t, $cond, $msg)
    {
        if ($cond !== true) {
            $GLOBALS['q251r_fail'][] = $msg;
        } else {
            $GLOBALS['q251r_pass'] = ($GLOBALS['q251r_pass'] ?? 0) + 1;
        }
    }

    /** Akhir AC: gagal bila ada kondisi terkumpul yang tidak terpenuhi (pesan lengkap). */
    function q251r_finish($t)
    {
        $f = $GLOBALS['q251r_fail'] ?? [];
        $p = $GLOBALS['q251r_pass'] ?? 0;
        $GLOBALS['q251r_fail'] = [];
        $GLOBALS['q251r_pass'] = 0;
        $t->note("kondisi terkumpul: $p lulus, " . count($f) . ' gagal');
        $t->true($f === [], count($f) . ' kondisi gagal: ' . implode(' || ', $f));
    }

    /** Ukuran kertas yang diharapkan (pt) dari ukuran mm. */
    function q251r_pt($mm)
    {
        return round($mm * 72 / 25.4, 1);
    }

    /**
     * Asersi gabungan satu PDF. $o: user (wajib, pemilik teks "Printed by"), company, min_pages, media [w,h] (semua halaman), rows (jumlah
     * kode baris yang diharapkan, pola $o['row_rx']), head_rx, geom (bool, default true), header (bool, default true).
     * Mengembalikan ['pages', 'footer', 'geom'].
     */
    function q251r_assert($t, $label, $bytes, array $o)
    {
        q251r_chk($t, strpos($bytes, '%PDF-') === 0, "$label: diawali %PDF-");
        $r = q251r_pages($bytes, $o['user'], $o['company'] ?? null);
        $n = $r['pages'];
        q251r_chk($t, $n >= ($o['min_pages'] ?? 1), "$label: halaman $n >= " . ($o['min_pages'] ?? 1));
        $ok = count($r['footer_ok']);
        $msg = "$label: footer (printed by + nomor halaman i/N) ada di $ok/$n halaman" . ($r['footer_missing'] ? ' - TANPA footer: hal. ' . implode(',', array_slice($r['footer_missing'], 0, 15)) : '');
        if ($o['soft_footer'] ?? false) {
            // template yang tidak diubah item ini (report-template-1/-2: footer di akhir): hanya dicatat
            $t->note('[catatan, bukan asersi] ' . $msg);
        } else {
            q251r_chk($t, $ok === $n, $msg);
        }
        if (($o['header'] ?? true) && isset($o['company'])) {
            q251r_chk($t, count($r['header_ok']) === $n, "$label: header (perusahaan) di " . count($r['header_ok']) . "/$n halaman");
        }
        if (isset($o['media'])) {
            $bad = [];
            foreach ($r['media'] as $i => $m) {
                if ($m === null || abs($m[0] - $o['media'][0]) > 0.3 || abs($m[1] - $o['media'][1]) > 0.3) {
                    $bad[] = ($i + 1) . ':' . json_encode($m);
                }
            }
            q251r_chk($t, $bad === [], "$label: MediaBox semua $n halaman = " . json_encode($o['media']) . ($bad ? ' - menyimpang ' . implode(' ', array_slice($bad, 0, 5)) : ''));
        }
        if (isset($o['row_rx'])) {
            $codes = [];
            foreach ($r['texts'] as $texts) {
                foreach ($texts as $tx) {
                    if (preg_match($o['row_rx'], $tx)) {
                        $codes[] = $tx;
                    }
                }
            }
            if (isset($o['rows'])) {
                q251r_chk($t, count($codes) === $o['rows'], "$label: jumlah baris Rincian di PDF = " . count($codes) . ' (harus ' . $o['rows'] . ')');
                q251r_chk($t, count(array_unique($codes)) === $o['rows'], "$label: kode baris unik (tanpa baris ganda/hilang)");
            }
        }
        $geom = null;
        if ($o['geom'] ?? true) {
            $pages = q251r_gs($bytes);
            if ($pages === null) {
                $t->note("$label: gs tidak ada, geometri dilewati");
            } else {
                q251r_chk($t, count($pages) === $n, "$label: gs membaca " . count($pages) . " halaman (harus $n)");
                $geom = q251r_geom($pages, $o['head_rx'] ?? [], $r['media']);
                $gf = array_filter(array_column($geom, 'gap_footer'), function ($v) {
                    return $v !== null;
                });
                $gh = array_filter(array_column($geom, 'gap_header'), function ($v) {
                    return $v !== null;
                });
                $minF = $gf ? min($gf) : null;
                $minH = $gh ? min($gh) : null;
                if ($o['soft_footer'] ?? false) {
                    $t->note("[catatan] $label: celah footer-isi minimum " . var_export($minF, true) . ' pt');
                } else {
                    q251r_chk($t, $minF === null || $minF > 0, "$label: footer tidak menimpa baris isi terakhir (celah minimum " . var_export($minF, true) . " pt, " . count($gf) . " halaman diukur)");
                }
                if ($o['soft_header'] ?? false) {
                    // pengamatan (bukan asersi): kode bersama getHeaderHeight tidak punya ukuran A5 untuk report-header-1 (lihat laporan)
                    $t->note("[pengamatan] $label: celah header-isi pertama minimum " . var_export($minH, true) . ' pt' . ($minH !== null && $minH <= 0 ? ' (header terakhir tertutup baris judul tabel)' : ''));
                } elseif ($o['geom_header'] ?? true) {
                    q251r_chk($t, $minH === null || $minH > 0, "$label: header tidak menimpa baris isi pertama (celah minimum " . var_export($minH, true) . " pt, " . count($gh) . " halaman diukur)");
                }
            }
        }

        return ['pages' => $n, 'footer_ok' => $ok, 'geom' => $geom, 'parse' => $r];
    }

    /**
     * PDF sesi opname DI DALAM PROSES sebagai $owner, BACA SAJA (dev_render.php, sesi MySQL read-only). $payload null = GET pdf, array =
     * pratinjau. Mengembalikan ['bytes' => string|null, 'err' => string, 'sec' => float].
     */
    function q251r_dev_render($t, $id, $owner, $payload = null, $oldViews = null)
    {
        $out = q251_tmpdir() . '/devr-' . uniqid() . '.pdf';
        $args = [PHP_BINARY, __DIR__ . '/dev_render.php', (string) $t->conf('BE_DIR'), $id, $owner, $out];
        $pf = null;
        if ($payload !== null) {
            $pf = q251_tmpdir() . '/devp-' . uniqid() . '.json';
            file_put_contents($pf, json_encode((object) $payload));
            $args[] = $pf;
        }
        $t0 = microtime(true);
        // $oldViews: direktori view v5 yang diprioritaskan (kontrol negatif: report-template-6 SEBELUM perbaikan R-3, qa/fixtures/oldviews)
        $env = $oldViews !== null ? 'QA251_OLD_VIEWS=' . escapeshellarg($oldViews) . ' ' : '';
        $err = (string) shell_exec($env . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1 >/dev/null');
        $bytes = is_file($out) ? file_get_contents($out) : null;
        @unlink($out);
        if ($pf !== null) {
            @unlink($pf);
        }

        return ['bytes' => $bytes, 'err' => trim($err), 'sec' => round(microtime(true) - $t0, 1)];
    }
}
