<?php

namespace QaHttp;

/**
 * Objek `$t` yang diterima `run` sebuah skenario. Satu instance per AC; sesi dan koneksi DB
 * dipakai bersama sepanjang run.
 */
final class Tester
{
    /** Potongan body maksimum di laporan. */
    const BODY_LIMIT = 2048;

    private $sessions;
    private $assertions = 0;
    private $notes = [];
    private $lastRequest = null;
    private $lastBody = null;
    private $companyIds = [];

    public function __construct(Sessions $sessions)
    {
        $this->sessions = $sessions;
    }

    // ----------------------------------------------------------------- sesi & HTTP

    /** Sesi user utama yang terikat ke $db (satu token per DB per run); null = DB default (QA_DB). */
    public function session($db = null)
    {
        $db = $db === null ? Config::defaultDb() : $db;
        if ($db === null) {
            throw new InfraError('QA_DB/QA_DBS belum diisi');
        }

        return $this->sessions->session($db);
    }

    /** Login baru, token belum terikat DB. $who: QA_USER, QA_USER2, QA_<PROFIL>_USER; null = user utama. SKIP bila tidak diisi. */
    public function login($who = null)
    {
        return $this->sessions->login($who);
    }

    /**
     * PUT api/v5/auth/database dengan token $session.
     *
     * @param int|null $force 1/0, atau null = tanpa kunci force_login
     * @return array [status, json]
     */
    public function bind(Session $session, $db, $force = 1)
    {
        list($status, $json, $raw) = $this->sessions->bind($session, $db, $force);
        $this->remember('PUT api/v5/auth/database', $raw);

        return [$status, $json];
    }

    /** Buang sesi cache $db supaya session($db) berikutnya login ulang. */
    public function forget($db)
    {
        $this->sessions->forget($db);
    }

    /** @return array [status, json] */
    public function call(Session $session, $method, $uri, $body = null)
    {
        list($status, $raw, $json) = Http::request($method, $uri, $session->token, $body);
        $this->remember(strtoupper($method) . ' ' . Http::path($uri), $raw);

        return [$status, $json];
    }

    /** Tanpa token (route tanpa auth). @return array [status, json] */
    public function raw($method, $uri, $body = null)
    {
        list($status, $raw, $json) = Http::request($method, $uri, null, $body);
        $this->remember(strtoupper($method) . ' ' . Http::path($uri), $raw);

        return [$status, $json];
    }

    /**
     * Untuk respons BUKAN JSON (unduhan berkas): header dibutuhkan (content-type,
     * Content-Disposition), body tidak di-decode JSON dan tidak disimpan sebagai "last exchange"
     * (bisa besar/biner). $session = null berarti tanpa token, sama seperti raw().
     *
     * @return array [status, rawBody, headers] headers: nama huruf kecil => nilai
     */
    public function callFile($session, $method, $uri, $body = null)
    {
        $token = $session instanceof Session ? $session->token : null;
        list($status, $raw, , $headers) = Http::request($method, $uri, $token, $body);
        $this->remember(strtoupper($method) . ' ' . Http::path($uri), '(berkas, ' . strlen($raw) . ' byte, ' . ($headers['content-type'] ?? '-') . ')');

        return [$status, $raw, $headers];
    }

    /** Unggah berkas (mis. import Excel), multipart. @return array [status, json] */
    public function uploadFile(Session $session, $uri, $filePath, $fieldName = 'file')
    {
        list($status, $raw, $json) = Http::uploadFile($uri, $session->token, $filePath, $fieldName);
        $this->remember('POST ' . Http::path($uri) . ' (upload)', $raw);

        return [$status, $json];
    }

    // ----------------------------------------------------------------- DB & in-process

    /** Koneksi Laravel read-only ke $name (hanya SELECT); null = DB default QA (QA_DB). */
    public function db($name = null)
    {
        $name = $name === null ? Config::defaultDb() : $name;
        if ($name === null) {
            throw new InfraError('QA_DB/QA_DBS belum diisi');
        }

        return Laravel::db($name);
    }

    /** Jalankan kode di proses CLI yang sudah bootstrap Laravel (koneksi default dipulihkan sesudahnya). */
    public function probe(callable $callback)
    {
        return Laravel::probe($callback);
    }

    /** Nama DB koneksi default BE_DIR (DB_DATABASE di .env; mengikuti profil lisensi aktif). */
    public function globalDb()
    {
        return Laravel::globalDb();
    }

    /** Daftar DB yang boleh di-bind QA (QA_DB + QA_DBS, atau PROFILE_<nama>_DB bila profil aktif). */
    public function dbs()
    {
        return Config::qaDbs();
    }

    /** Nilai config non-rahasia (API_URL, QA_USER, QA_DB, CLIENT_ID, PROFILE_ACTIVE, ...). */
    public function conf($key, $default = null)
    {
        if (Config::isSecretKey($key)) {
            throw new \InvalidArgumentException("kunci {$key} rahasia, tidak boleh dibaca skenario");
        }

        return Config::get($key, $default);
    }

    // ----------------------------------------------------------------- hasil

    public function skip($message)
    {
        throw new Skipped($message);
    }

    public function blocked($message)
    {
        throw new Blocked($message);
    }

    public function fail($message)
    {
        throw new AssertionFailed($message);
    }

    /** Catatan tambahan yang ikut di pesan hasil. */
    public function note($message)
    {
        $this->notes[] = $message;
    }

    // ----------------------------------------------------------------- asersi

    public function eq($actual, $expected, $label = '')
    {
        $this->assertions++;
        if (!self::same($actual, $expected)) {
            $this->fail(self::label($label) . 'diharapkan ' . self::show($expected) . ', didapat ' . self::show($actual));
        }
    }

    public function true($condition, $label = '')
    {
        $this->assertions++;
        if ($condition !== true) {
            $this->fail(self::label($label) . 'kondisi tidak terpenuhi');
        }
    }

    /** Kunci ada (dot path), boleh bernilai null. */
    public function has($array, $path, $label = '')
    {
        $this->assertions++;
        $node = $array;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                $this->fail(self::label($label) . "kunci '{$path}' tidak ada");
            }
            $node = $node[$segment];
        }
    }

    /** @param array $response [status, json] dari call()/raw()/bind() */
    public function status($response, $expected, $label = '')
    {
        $this->assertions++;
        if ((int) $response[0] !== (int) $expected) {
            $this->fail(self::label($label) . "HTTP diharapkan {$expected}, didapat {$response[0]}");
        }
    }

    /** Kode pesan dari `msg_code` (amplop formatResponse) atau `code` (ErrorMessageException). */
    public function code($response, $expected, $label = '')
    {
        $this->assertions++;
        $json = $response[1];
        $actual = $json['msg_code'] ?? $json['code'] ?? $json['msgCode'] ?? null;
        if ($actual !== $expected) {
            $this->fail(self::label($label) . 'kode diharapkan ' . $expected . ', didapat ' . self::show($actual) . " (HTTP {$response[0]})");
        }
    }

    /** @param array $where kolom => nilai (null = IS NULL, array = IN) */
    public function rowExists($db, $table, array $where, $label = '')
    {
        $this->assertions++;
        if (!$this->query($db, $table, $where)->exists()) {
            $this->fail(self::label($label) . "tidak ada baris {$db}.{$table} " . self::show($where));
        }
    }

    public function rowCount($db, $table, array $where, $expected, $label = '')
    {
        $this->assertions++;
        $count = $this->query($db, $table, $where)->count();
        if ($count !== (int) $expected) {
            $this->fail(self::label($label) . "{$db}.{$table} " . self::show($where) . " berjumlah {$count}, diharapkan {$expected}");
        }
    }

    /**
     * GET api/v5/auth/info bergantian X, Y, X, ... ($n kali per sesi). Setiap respons harus
     * selected_database.db_name = DB sesinya dan company.id_company = baris pertama companies DB itu.
     * Kalau `companies` sebuah DB tidak berisi satu perusahaan yang jelas, cek manual dengan call().
     */
    public function isolation(Session $x, Session $y, $n = 10)
    {
        foreach ([$x, $y] as $session) {
            if ($session->db === null) {
                $this->fail('isolation: sesi belum terikat DB');
            }
        }

        for ($i = 1; $i <= $n; $i++) {
            foreach ([$x, $y] as $session) {
                $response = $this->call($session, 'GET', 'api/v5/auth/info');
                $label = "isolation #{$i} {$session->db}";
                $this->status($response, 200, $label);
                $this->eq($response[1]['result']['selected_database']['db_name'] ?? null, $session->db, "{$label} selected_database.db_name");
                $this->eq($response[1]['result']['company']['id_company'] ?? null, $this->companyId($session->db), "{$label} company.id_company");
            }
        }
    }

    // ----------------------------------------------------------------- untuk run.php

    public function assertions()
    {
        return $this->assertions;
    }

    public function notes()
    {
        return $this->notes;
    }

    /** Request terakhir + potongan body (sudah disamarkan, maks 2 KB). */
    public function lastExchange()
    {
        if ($this->lastRequest === null) {
            return null;
        }

        $body = Config::redact((string) $this->lastBody);
        if (strlen($body) > self::BODY_LIMIT) {
            $body = substr($body, 0, self::BODY_LIMIT) . '...';
        }

        return ['request' => Config::redact($this->lastRequest), 'body' => $body];
    }

    private function remember($request, $raw)
    {
        $this->lastRequest = $request;
        $this->lastBody = $raw;
    }

    private function query($db, $table, array $where)
    {
        $query = Laravel::db($db)->table($table);
        foreach ($where as $column => $value) {
            if ($value === null) {
                $query->whereNull($column);
            } elseif (is_array($value)) {
                $query->whereIn($column, $value);
            } else {
                $query->where($column, $value);
            }
        }

        return $query;
    }

    private function companyId($db)
    {
        if (!array_key_exists($db, $this->companyIds)) {
            $this->companyIds[$db] = Laravel::db($db)->table('companies')->value('id_company');
        }

        return $this->companyIds[$db];
    }

    private static function same($actual, $expected)
    {
        if ($actual === $expected) {
            return true;
        }
        if (is_scalar($actual) && is_scalar($expected) && !is_bool($actual) && !is_bool($expected)
            && is_numeric($actual) && is_numeric($expected)
        ) {
            return $actual == $expected;
        }
        if (is_array($actual) && is_array($expected)) {
            if (count($actual) !== count($expected)) {
                return false;
            }
            foreach ($expected as $key => $value) {
                if (!array_key_exists($key, $actual) || !self::same($actual[$key], $value)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private static function label($label)
    {
        return $label === '' ? '' : $label . ': ';
    }

    private static function show($value)
    {
        return Config::redact(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
