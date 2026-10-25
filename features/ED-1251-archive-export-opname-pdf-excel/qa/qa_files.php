<?php
/**
 * Pembaca isi berkas ED-1251 (BUKAN skenario): XLSX lewat ZipArchive + SimpleXML, PDF lewat FlateDecode (gzuncompress) +
 * ToUnicode CMap font (mPDF menulis teks sebagai kode 2 byte Identity-H; CMap -> Unicode).
 *
 *   q251_xlsx($bytes)  -> ['rows' => [no => [kolom => sel]], 'merges' => [...], 'dim' => 'A1:E21', 'sheet' => nama]
 *       sel = ['v' => string|float, 'num' => bool, 'fmt' => kode format angka|null, 'fill' => 'FFRRGGBB'|null (solid),
 *              'color' => 'FFRRGGBB'|null (font), 'italic' => bool, 'bold' => bool, 's' => indeks gaya]
 *   q251_pdf($bytes)   -> ['pages' => n, 'media' => [[w,h],...], 'texts' => [[teks, ...]] per halaman (urutan tulis),
 *                          'text' => semua teks digabung "\n", 'fills' => ['r g b' => n] warna sebelum "re f",
 *                          'colors' => ['r g b' => n] semua operator rg/RG/g/G]
 */

if (!function_exists('q251_xlsx')) {

    function q251_tmpdir()
    {
        $dir = QA_AGENTIC . '/work/ed1251/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    function q251_sx($xml)
    {
        // buang namespace bawaan supaya XPath polos bekerja
        $xml = preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $xml);
        $xml = preg_replace('/\s[A-Za-z]+:[A-Za-z]+="[^"]*"/', '', $xml); // atribut berawalan namespace (r:id, xml:space)
        $x = simplexml_load_string($xml);
        if ($x === false) {
            throw new \RuntimeException('XML tidak terbaca');
        }

        return $x;
    }

    function q251_xlsx($bytes)
    {
        $path = q251_tmpdir() . '/x-' . uniqid() . '.xlsx';
        file_put_contents($path, $bytes);
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                throw new \RuntimeException('bukan ZIP');
            }
            $get = function ($name) use ($zip) {
                $c = $zip->getFromName($name);

                return $c === false ? null : $c;
            };
            $sheetXml = $get('xl/worksheets/sheet1.xml');
            if ($sheetXml === null) {
                throw new \RuntimeException('sheet1.xml tidak ada');
            }
            $names = [];
            if (($ss = $get('xl/sharedStrings.xml')) !== null) {
                foreach (q251_sx($ss)->si as $si) {
                    $txt = '';
                    if (isset($si->t)) {
                        $txt = (string) $si->t;
                    } else {
                        foreach ($si->r as $r) {
                            $txt .= (string) $r->t;
                        }
                    }
                    $names[] = $txt;
                }
            }
            $wb = $get('xl/workbook.xml');
            $sheetName = $wb !== null ? (string) (q251_sx($wb)->sheets->sheet[0]['name'] ?? '') : '';

            // gaya
            $xfs = [];
            if (($st = $get('xl/styles.xml')) !== null) {
                $sx = q251_sx($st);
                $fmts = [];
                foreach ($sx->numFmts->numFmt ?? [] as $nf) {
                    $fmts[(int) $nf['numFmtId']] = (string) $nf['formatCode'];
                }
                $fonts = [];
                foreach ($sx->fonts->font ?? [] as $f) {
                    $fonts[] = [
                        'color' => isset($f->color['rgb']) ? (string) $f->color['rgb'] : null,
                        'italic' => isset($f->i) && !in_array((string) $f->i['val'], ['0', 'false'], true),
                        'bold' => isset($f->b) && !in_array((string) $f->b['val'], ['0', 'false'], true),
                    ];
                }
                $fills = [];
                foreach ($sx->fills->fill ?? [] as $f) {
                    $pt = isset($f->patternFill['patternType']) ? (string) $f->patternFill['patternType'] : null;
                    $fills[] = $pt === 'solid' ? (isset($f->patternFill->fgColor['rgb']) ? (string) $f->patternFill->fgColor['rgb'] : 'solid?') : null;
                }
                foreach ($sx->cellXfs->xf ?? [] as $xf) {
                    $id = (int) $xf['numFmtId'];
                    $xfs[] = [
                        'fmt' => $fmts[$id] ?? ($id === 0 ? null : 'builtin:' . $id),
                        'font' => $fonts[(int) $xf['fontId']] ?? ['color' => null, 'italic' => false, 'bold' => false],
                        'fill' => $fills[(int) $xf['fillId']] ?? null,
                    ];
                }
            }

            $rows = [];
            $sheet = q251_sx($sheetXml);
            foreach ($sheet->sheetData->row ?? [] as $row) {
                $r = (int) $row['r'];
                foreach ($row->c as $c) {
                    $ref = (string) $c['r'];
                    $col = preg_replace('/\d+/', '', $ref);
                    $type = (string) $c['t'];
                    $s = (int) $c['s'];
                    $raw = isset($c->v) ? (string) $c->v : null;
                    if ($type === 's') {
                        $v = $names[(int) $raw] ?? '';
                        $num = false;
                    } elseif ($type === 'inlineStr') {
                        $v = (string) $c->is->t;
                        $num = false;
                    } elseif ($type === 'str' || $type === 'b' || $type === 'e') {
                        $v = (string) $raw;
                        $num = false;
                    } elseif ($raw === null) {
                        $v = null;
                        $num = false;
                    } else {
                        $v = (float) $raw;
                        $num = true;
                    }
                    $xf = $xfs[$s] ?? ['fmt' => null, 'font' => ['color' => null, 'italic' => false, 'bold' => false], 'fill' => null];
                    $formula = isset($c->f) ? (string) $c->f : null;
                    $rows[$r][$col] = ['v' => $v, 'num' => $num, 'fmt' => $xf['fmt'], 'fill' => $xf['fill'], 'color' => $xf['font']['color'],
                        'italic' => $xf['font']['italic'], 'bold' => $xf['font']['bold'], 's' => $s, 'f' => $formula, 't' => $type];
                }
            }
            $merges = [];
            foreach ($sheet->mergeCells->mergeCell ?? [] as $m) {
                $merges[] = (string) $m['ref'];
            }
            $zip->close();

            return ['rows' => $rows, 'merges' => $merges, 'dim' => (string) ($sheet->dimension['ref'] ?? ''), 'sheet' => $sheetName,
                'names' => $names];
        } finally {
            @unlink($path);
        }
    }

    /** Teks sel A..Z urut kolom dari satu baris, hanya sel berisi (kosong dibuang). */
    function q251_xrow(array $xl, $r)
    {
        $out = [];
        foreach ($xl['rows'][$r] ?? [] as $col => $cell) {
            if ($cell['v'] !== null && $cell['v'] !== '') {
                $out[$col] = $cell['v'];
            }
        }

        return $out;
    }

    /** Semua nilai sel (string) dalam urutan baris/kolom. */
    function q251_xall(array $xl)
    {
        $out = [];
        ksort($xl['rows']);
        foreach ($xl['rows'] as $r => $cols) {
            foreach ($cols as $col => $cell) {
                if ($cell['v'] !== null && $cell['v'] !== '') {
                    $out[] = is_float($cell['v']) ? (string) $cell['v'] : $cell['v'];
                }
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ PDF

    /** Semua objek PDF: nomor => [dict (teks), stream terdekompres|null]. */
    function q251_pdf_objects($b)
    {
        $objs = [];
        if (!preg_match_all('/(?:^|[\r\n])(\d+) 0 obj(.*?)endobj/s', $b, $m, PREG_SET_ORDER)) {
            return $objs;
        }
        foreach ($m as $x) {
            $body = $x[2];
            $stream = null;
            $dict = $body;
            if (preg_match('/stream\r?\n(.*)endstream/s', $body, $sm, PREG_OFFSET_CAPTURE)) {
                $dict = substr($body, 0, $sm[0][1]);
                $raw = $sm[1][0];
                if (strpos($dict, 'FlateDecode') !== false) {
                    $raw = rtrim($raw, "\r\n");
                    $un = @gzuncompress($raw);
                    if ($un === false) {
                        $un = @gzuncompress($sm[1][0]);
                    }
                    $stream = $un === false ? null : $un;
                } else {
                    $stream = $raw;
                }
            }
            $objs[(int) $x[1]] = [$dict, $stream];
        }

        return $objs;
    }

    /** CMap ToUnicode -> function(code) : string UTF-8. */
    function q251_cmap($stream)
    {
        $map = [];
        $ranges = [];
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $stream, $m)) {
            foreach ($m[1] as $block) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $p) {
                        $map[hexdec($p[1])] = mb_convert_encoding(hex2bin($p[2]), 'UTF-8', 'UTF-16BE');
                    }
                }
            }
        }
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $stream, $m)) {
            foreach ($m[1] as $block) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<([0-9A-Fa-f]+)>|\[(.*?)\])/s', $block, $rs, PREG_SET_ORDER)) {
                    foreach ($rs as $r) {
                        $ranges[] = [hexdec($r[1]), hexdec($r[2]), $r[4] ?? '', $r[5] ?? ''];
                    }
                }
            }
        }

        return function ($code) use ($map, $ranges) {
            if (isset($map[$code])) {
                return $map[$code];
            }
            foreach ($ranges as $r) {
                if ($code >= $r[0] && $code <= $r[1]) {
                    if ($r[2] !== '') {
                        $base = hexdec($r[2]);

                        return mb_convert_encoding(pack('n', $base + $code - $r[0]), 'UTF-8', 'UTF-16BE');
                    }
                    if (preg_match_all('/<([0-9A-Fa-f]+)>/', $r[3], $dst)) {
                        $h = $dst[1][$code - $r[0]] ?? null;

                        return $h === null ? '' : mb_convert_encoding(hex2bin($h), 'UTF-8', 'UTF-16BE');
                    }
                }
            }

            return '';
        };
    }

    /** String PDF literal ( ... ) -> byte mentah. */
    function q251_pdf_unescape($s)
    {
        $out = '';
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $ch = $s[$i];
            if ($ch !== '\\') {
                $out .= $ch;
                continue;
            }
            $i++;
            $e = $s[$i] ?? '';
            if ($e >= '0' && $e <= '7') {
                $o = $e;
                for ($k = 0; $k < 2 && isset($s[$i + 1]) && $s[$i + 1] >= '0' && $s[$i + 1] <= '7'; $k++) {
                    $o .= $s[++$i];
                }
                $out .= chr(octdec($o) & 255);
            } else {
                $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0c"];
                $out .= $map[$e] ?? $e;
            }
        }

        return $out;
    }

    function q251_pdf($b)
    {
        if (strpos($b, '%PDF-') !== 0) {
            throw new \RuntimeException('bukan PDF');
        }
        $objs = q251_pdf_objects($b);

        // font -> cmap
        $cmaps = [];
        foreach ($objs as $n => $o) {
            if (preg_match('#/Type\s*/Font\b#', $o[0]) && preg_match('#/ToUnicode\s+(\d+)\s+0\s+R#', $o[0], $m)) {
                $cm = $objs[(int) $m[1]][1] ?? null;
                if ($cm !== null) {
                    $cmaps[$n] = q251_cmap($cm);
                }
            }
        }

        // halaman berurutan: /Kids dari /Pages akar
        $pages = [];
        $kidsOrder = function ($n) use (&$kidsOrder, $objs, &$pages) {
            $d = $objs[$n][0] ?? '';
            if (preg_match('#/Type\s*/Pages#', $d) && preg_match('#/Kids\s*\[(.*?)\]#s', $d, $k)) {
                preg_match_all('/(\d+)\s+0\s+R/', $k[1], $ids);
                foreach ($ids[1] as $id) {
                    $kidsOrder((int) $id);
                }
            } elseif (preg_match('#/Type\s*/Page\b#', $d)) {
                $pages[] = $n;
            }
        };
        $root = null;
        foreach ($objs as $n => $o) {
            if (preg_match('#/Type\s*/Pages#', $o[0]) && !preg_match('#/Parent\s+\d+#', $o[0])) {
                $root = $n;
                break;
            }
        }
        if ($root !== null) {
            $kidsOrder($root);
        }

        $res = ['pages' => count($pages), 'media' => [], 'texts' => [], 'fills' => [], 'colors' => [], 'text' => ''];
        foreach ($pages as $pn) {
            $d = $objs[$pn][0];
            $media = null;
            if (preg_match('#/MediaBox\s*\[\s*([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s*\]#', $d, $mm)) {
                $media = [(float) $mm[3] - (float) $mm[1], (float) $mm[4] - (float) $mm[2]];
            } elseif (preg_match('#/Parent\s+(\d+)\s+0\s+R#', $d, $pp) && preg_match('#/MediaBox\s*\[\s*([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s*\]#', $objs[(int) $pp[1]][0] ?? '', $mm)) {
                $media = [(float) $mm[3] - (float) $mm[1], (float) $mm[4] - (float) $mm[2]];
            }
            $res['media'][] = $media;

            // font resource nama -> objek font
            $fontRes = [];
            $resObj = null;
            if (preg_match('#/Resources\s+(\d+)\s+0\s+R#', $d, $rm)) {
                $resObj = $objs[(int) $rm[1]][0] ?? '';
            } elseif (preg_match('#/Resources\s*<<(.*?)>>\s*/#s', $d, $rm)) {
                $resObj = $rm[1];
            }
            if ($resObj !== null && preg_match('#/Font\s*<<(.*?)>>#s', $resObj, $fm)) {
                preg_match_all('#/(\w+)\s+(\d+)\s+0\s+R#', $fm[1], $fs, PREG_SET_ORDER);
                foreach ($fs as $f) {
                    $fontRes[$f[1]] = (int) $f[2];
                }
            }
            $contents = [];
            if (preg_match('#/Contents\s*\[(.*?)\]#s', $d, $cm)) {
                preg_match_all('/(\d+)\s+0\s+R/', $cm[1], $ids);
                $contents = array_map('intval', $ids[1]);
            } elseif (preg_match('#/Contents\s+(\d+)\s+0\s+R#', $d, $cm)) {
                $contents = [(int) $cm[1]];
            }
            $texts = [];
            foreach ($contents as $cn) {
                $stream = $objs[$cn][1] ?? null;
                if ($stream === null) {
                    continue;
                }
                q251_pdf_content($stream, $fontRes, $cmaps, $texts, $res);
            }
            $res['texts'][] = $texts;
            $res['text'] .= implode("\n", $texts) . "\n";
        }

        return $res;
    }

    /** Parse satu content stream: teks (Tj/TJ) dan warna. */
    function q251_pdf_content($stream, array $fontRes, array $cmaps, array &$texts, array &$res)
    {
        $font = null;
        $lastColor = null; // warna isi terakhir ("r g b")
        $pending = null;   // warna yang berlaku untuk path berikutnya
        $len = strlen($stream);
        $tokens = [];
        $i = 0;
        // tokenizer sederhana: string literal, array, angka, nama, operator
        while ($i < $len) {
            $ch = $stream[$i];
            if (ctype_space($ch)) {
                $i++;
                continue;
            }
            if ($ch === '(') {
                $depth = 1;
                $j = $i + 1;
                $buf = '';
                while ($j < $len && $depth > 0) {
                    $c = $stream[$j];
                    if ($c === '\\') {
                        $buf .= $c . ($stream[$j + 1] ?? '');
                        $j += 2;
                        continue;
                    }
                    if ($c === '(') {
                        $depth++;
                    } elseif ($c === ')') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                    $buf .= $c;
                    $j++;
                }
                $tokens[] = ['s', $buf];
                $i = $j + 1;
                continue;
            }
            if ($ch === '[') {
                $tokens[] = ['[', null];
                $i++;
                continue;
            }
            if ($ch === ']') {
                $tokens[] = [']', null];
                $i++;
                continue;
            }
            if ($ch === '<' && ($stream[$i + 1] ?? '') !== '<') {
                $j = strpos($stream, '>', $i);
                $tokens[] = ['h', substr($stream, $i + 1, $j - $i - 1)];
                $i = $j + 1;
                continue;
            }
            if ($ch === '/') {
                $j = $i + 1;
                while ($j < $len && !ctype_space($stream[$j]) && strpos('/[]()<>', $stream[$j]) === false) {
                    $j++;
                }
                $tokens[] = ['n', substr($stream, $i + 1, $j - $i - 1)];
                $i = $j;
                continue;
            }
            $j = $i;
            while ($j < $len && !ctype_space($stream[$j]) && strpos('/[]()<>', $stream[$j]) === false) {
                $j++;
            }
            if ($j === $i) {
                $i++;
                continue;
            }
            $w = substr($stream, $i, $j - $i);
            $tokens[] = [is_numeric($w) ? 'num' : 'op', $w];
            $i = $j;
        }

        $decode = function ($bytes) use (&$font, $cmaps, $fontRes) {
            $fn = $font !== null && isset($fontRes[$font]) && isset($cmaps[$fontRes[$font]]) ? $cmaps[$fontRes[$font]] : null;
            $out = '';
            $n = strlen($bytes);
            for ($k = 0; $k + 1 < $n; $k += 2) {
                $code = (ord($bytes[$k]) << 8) | ord($bytes[$k + 1]);
                $out .= $fn ? $fn($code) : mb_convert_encoding(pack('n', $code), 'UTF-8', 'UTF-16BE');
            }

            return $out;
        };

        $stack = [];
        $arr = null;
        foreach ($tokens as $tk) {
            list($ty, $val) = $tk;
            if ($ty === '[') {
                $arr = [];
                continue;
            }
            if ($ty === ']') {
                $stack[] = ['arr', $arr ?? []];
                $arr = null;
                continue;
            }
            if ($arr !== null) {
                $arr[] = [$ty, $val];
                continue;
            }
            if ($ty !== 'op') {
                $stack[] = [$ty, $val];
                continue;
            }
            // operator
            switch ($val) {
                case 'Tf':
                    foreach (array_reverse($stack) as $s) {
                        if ($s[0] === 'n') {
                            $font = $s[1];
                            break;
                        }
                    }
                    break;
                case 'Tj':
                    $s = end($stack);
                    if ($s && $s[0] === 's') {
                        $texts[] = $decode(q251_pdf_unescape($s[1]));
                    } elseif ($s && $s[0] === 'h') {
                        $texts[] = $decode(hex2bin($s[1]));
                    }
                    break;
                case 'TJ':
                    $s = end($stack);
                    if ($s && $s[0] === 'arr') {
                        $t = '';
                        foreach ($s[1] as $e) {
                            if ($e[0] === 's') {
                                $t .= $decode(q251_pdf_unescape($e[1]));
                            } elseif ($e[0] === 'h') {
                                $t .= $decode(hex2bin($e[1]));
                            }
                        }
                        $texts[] = $t;
                    }
                    break;
                case 'rg':
                case 'RG':
                case 'g':
                case 'G':
                    $nums = [];
                    foreach (array_slice($stack, -($val === 'rg' || $val === 'RG' ? 3 : 1)) as $s) {
                        $nums[] = $s[1];
                    }
                    if ($val === 'g' || $val === 'G') {
                        $nums = [$nums[0], $nums[0], $nums[0]];
                    }
                    $key = implode(' ', array_map(function ($v) {
                        return number_format((float) $v, 3, '.', '');
                    }, $nums));
                    $res['colors'][$key] = ($res['colors'][$key] ?? 0) + 1;
                    if ($val === 'rg' || $val === 'g') {
                        $lastColor = $key;
                    }
                    break;
                case 'f':
                case 'F':
                case 'f*':
                case 'B':
                    if ($lastColor !== null) {
                        $res['fills'][$lastColor] = ($res['fills'][$lastColor] ?? 0) + 1;
                    }
                    break;
            }
            $stack = [];
        }
    }
}
