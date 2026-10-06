<?php

namespace QaHttp;

/** Satu token API milik runner. `db` = DB tempat token terikat (null = belum bind). */
final class Session
{
    public $who;
    public $user;
    public $token;
    public $jti;
    public $db = null;

    public function __construct($who, $user, $token, $jti)
    {
        $this->who = $who;
        $this->user = $user;
        $this->token = $token;
        $this->jti = $jti;
    }

    /** Jangan pernah mencetak token lewat var_dump/print_r. */
    public function __debugInfo()
    {
        return ['who' => $this->who, 'user' => $this->user, 'db' => $this->db];
    }
}

/**
 * Login, bind, cache sesi per DB, dan log-out semua token di akhir run.
 *
 * Aturan server yang perlu diingat (DatabaseUserService::update):
 * - bind (PUT api/v5/auth/database) mencabut token user itu yang BELUM bernama, jadi login dan bind
 *   dijalankan berurutan per DB;
 * - bind dengan force_login=1 mencabut token lain user itu yang terikat ke DB yang sama;
 * - GE0106 = batas user online lisensi penuh: bind tidak bisa dilakukan, itu BLOCKED (bukan FAIL).
 */
final class Sessions
{
    const LICENSE_LIMIT_CODE = 'GE0106';

    private $clientId;
    private $clientSecret = null;
    /** @var Session[] cache sesi user utama per DB */
    private $cache = [];
    /** @var string[] DB yang bind-nya BLOCKED (batas user online) */
    private $blocked = [];
    /** @var Session[] semua token yang dibuat run ini */
    private $created = [];

    public function __construct()
    {
        $this->clientId = (int) Config::get('CLIENT_ID', 4);
    }

    /**
     * Login baru (token belum terikat DB).
     *
     * @param string|null $who QA_USER, QA_USER2, QA_<PROFIL>_USER (password = kunci yang USER-nya diganti PASS); null = user utama
     * @return Session
     */
    public function login($who = null)
    {
        $who = $who === null ? Config::primaryWho() : $who;
        if (!preg_match('/^QA_[A-Z0-9_]*USER[0-9]*$/', $who)) {
            throw new InfraError("kunci user '{$who}' tidak valid (pakai QA_USER, QA_USER2, QA_<PROFIL>_USER)");
        }

        $user = Config::get($who);
        $passKey = Config::passKey($who);
        $pass = Config::get($passKey);
        if ($user === null || $pass === null) {
            throw new Skipped("{$who}/{$passKey} tidak diisi di config/secrets.env");
        }

        list($status, $raw, $json) = Http::request('POST', 'api/v5/auth/token', null, [
            'username'      => $user,
            'password'      => $pass,
            'grant_type'    => 'password',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret(),
        ]);

        $token = $json['result']['access_token'] ?? null;
        Config::addSecret($token);
        Config::addSecret($json['result']['refresh_token'] ?? null);

        if (!$token) {
            $code = $json['msg_code'] ?? $json['code'] ?? '-';
            throw new InfraError("login {$who} ({$user}) gagal: HTTP {$status}, kode {$code}");
        }

        $session = new Session($who, $user, $token, $this->jti($token));
        $this->created[] = $session;

        return $session;
    }

    /**
     * PUT api/v5/auth/database. Tidak melempar: skenario memeriksa hasilnya sendiri.
     *
     * @param int|null $force 1/0, atau null = tanpa kunci force_login
     * @return array [status, json, raw]
     */
    public function bind(Session $session, $db, $force = 1)
    {
        $body = ['db_name' => $db];
        if ($force !== null) {
            $body['force_login'] = $force;
        }

        list($status, $raw, $json) = Http::request('PUT', 'api/v5/auth/database', $session->token, $body);
        $bound = $status === 200 && ($json['result']['database']['db_name'] ?? null) === $db;

        if ($bound) {
            $session->db = $db;

            // force_login mencabut token lain user ini di DB itu, termasuk sesi cache
            if ($force == 1 && isset($this->cache[$db]) && $this->cache[$db] !== $session
                && $this->cache[$db]->user === $session->user
            ) {
                unset($this->cache[$db]);
            }
        }

        return [$status, $json, $raw];
    }

    /**
     * Sesi user utama yang terikat ke $db, dibuat sekali per run (login lalu langsung bind force_login=1).
     * force_login=1 hanya mencabut token LAIN milik user QA itu sendiri di DB yang sama, tidak pernah
     * milik user lain. $db harus ada di QA_DB/QA_DBS (atau PROFILE_<nama>_DB bila profil aktif).
     */
    public function session($db)
    {
        if (isset($this->cache[$db])) {
            return $this->cache[$db];
        }
        if (isset($this->blocked[$db])) {
            throw new Blocked($this->blocked[$db]);
        }
        if (!in_array($db, Config::qaDbs(), true)) {
            throw new Blocked("DB '{$db}' tidak ada di QA_DBS: " . implode(', ', Config::qaDbs()));
        }

        $session = $this->login();
        list($status, $json) = $this->bind($session, $db, 1);
        if ($session->db !== $db) {
            $code = $json['msg_code'] ?? $json['code'] ?? '-';
            if ($code === self::LICENSE_LIMIT_CODE) {
                $this->blocked[$db] = "batas user online lisensi penuh (bind {$db}: HTTP {$status}, " . self::LICENSE_LIMIT_CODE . ')';
                throw new Blocked($this->blocked[$db]);
            }

            throw new AssertionFailed("bind {$db} gagal: HTTP {$status}, kode {$code}");
        }

        $this->cache[$db] = $session;

        return $session;
    }

    /** Buang sesi cache $db (mis. sesudah skenario sengaja mencabutnya). */
    public function forget($db)
    {
        unset($this->cache[$db]);
    }

    /**
     * GET api/v5/auth/log-out untuk setiap token run ini, lalu cek di DB default berapa yang masih aktif.
     * Aman dipanggil dua kali: token yang sudah di-log-out tidak diproses ulang.
     *
     * @return array ringkasan tanpa token
     */
    public function logoutAll()
    {
        $summary = ['tokens' => count($this->created), 'log_out_200' => 0, 'already_revoked' => 0, 'errors' => 0, 'still_active' => null];

        foreach ($this->created as $session) {
            try {
                list($status) = Http::request('GET', 'api/v5/auth/log-out', $session->token, null, 30);
                if ($status === 200) {
                    $summary['log_out_200']++;
                } elseif ($status === 401) {
                    $summary['already_revoked']++;
                } else {
                    $summary['errors']++;
                }
            } catch (\Throwable $e) {
                $summary['errors']++;
            }
        }

        $jtis = array_values(array_filter(array_map(function ($session) {
            return $session->jti;
        }, $this->created)));
        $this->created = [];
        $this->cache = [];

        if (!empty($jtis)) {
            try {
                $summary['still_active'] = Laravel::db(Laravel::defaultConnection())->table('oauth_access_tokens')
                    ->whereIn('id', $jtis)
                    ->where('revoked', 0)
                    ->count();
            } catch (\Throwable $e) {
                $summary['still_active'] = 'tidak bisa dicek: ' . Config::redact($e->getMessage());
            }
        } else {
            $summary['still_active'] = 0;
        }

        return $summary;
    }

    private function clientSecret()
    {
        if ($this->clientSecret === null) {
            $this->clientSecret = Laravel::db(Laravel::defaultConnection())->table('oauth_clients')->where('id', $this->clientId)->value('secret');
            if (!$this->clientSecret) {
                throw new InfraError("oauth_clients id {$this->clientId} tidak ditemukan di DB default BE_DIR");
            }
            Config::addSecret($this->clientSecret);
        }

        return $this->clientSecret;
    }

    /** jti (= oauth_access_tokens.id) dari payload JWT. */
    private function jti($token)
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        return $payload['jti'] ?? null;
    }
}
