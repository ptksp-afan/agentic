<?php

namespace QaHttp;

/**
 * Konfigurasi sama dengan scripts/lib/env.sh: config/workspace.env, lalu config/secrets.env
 * (kunci yang sudah terisi tidak ditimpa), dan env proses menang atas keduanya.
 * Juga penyimpan rahasia: semua teks yang keluar dari runner (stdout, pesan error, laporan) lewat redact().
 */
final class Config
{
    private static $values = null;
    private static $secrets = [];

    public static function get($key, $default = null)
    {
        self::load();

        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }

        return isset(self::$values[$key]) && self::$values[$key] !== '' ? self::$values[$key] : $default;
    }

    /** Nama kunci wajib yang belum diisi (nilainya tidak pernah dikembalikan). */
    public static function missing()
    {
        $missing = [];
        foreach (['API_URL', 'BE_DIR'] as $key) {
            if (self::get($key) === null) {
                $missing[] = $key;
            }
        }
        if (empty(self::qaDbs())) {
            $missing[] = 'QA_DB (atau QA_DBS)';
        }
        $who = self::primaryWho();
        if (self::get($who) === null || self::get(self::passKey($who)) === null) {
            $missing[] = $who . ' / ' . self::passKey($who);
        }

        return $missing;
    }

    public static function base()
    {
        return rtrim(self::get('API_URL'), '/');
    }

    public static function beDir()
    {
        return rtrim(str_replace("\\", '/', (string) self::get('BE_DIR')), '/');
    }

    /** Profil lisensi aktif; hanya diisi oleh `scripts/profile.sh run <nama> -- ...`. */
    public static function activeProfile()
    {
        $profile = self::get('PROFILE_ACTIVE');

        return $profile !== null && preg_match('/^[A-Za-z0-9_]+$/', $profile) ? $profile : null;
    }

    /** DB yang boleh di-bind QA (urutan pertama = default). Profil aktif memakai PROFILE_<nama>_DB saja. */
    public static function qaDbs()
    {
        $profile = self::activeProfile();
        if ($profile !== null && self::get("PROFILE_{$profile}_DB") !== null) {
            return [self::get("PROFILE_{$profile}_DB")];
        }

        $list = [];
        $default = self::get('QA_DB');
        if ($default !== null) {
            $list[] = $default;
        }
        foreach (explode(',', (string) self::get('QA_DBS', '')) as $db) {
            $db = trim($db);
            if ($db !== '' && !in_array($db, $list, true)) {
                $list[] = $db;
            }
        }

        return $list;
    }

    public static function defaultDb()
    {
        $dbs = self::qaDbs();

        return empty($dbs) ? null : $dbs[0];
    }

    /** Kunci user login utama: QA_<PROFIL>_USER bila profil aktif dan lengkap, selain itu QA_USER. */
    public static function primaryWho()
    {
        $profile = self::activeProfile();
        if ($profile !== null) {
            $who = 'QA_' . strtoupper($profile) . '_USER';
            if (self::get($who) !== null && self::get(self::passKey($who)) !== null) {
                return $who;
            }
        }

        return 'QA_USER';
    }

    /** QA_USER -> QA_PASS, QA_USER2 -> QA_PASS2, QA_REGULER_USER -> QA_REGULER_PASS. */
    public static function passKey($who)
    {
        return str_replace('USER', 'PASS', $who);
    }

    /** Kunci yang tidak boleh dibaca skenario. */
    public static function isSecretKey($key)
    {
        return (bool) preg_match('/(PASS|SECRET|TOKEN)/i', $key);
    }

    public static function addSecret($value)
    {
        if (is_string($value) && strlen($value) >= 4) {
            self::$secrets[$value] = true;
        }
    }

    /** Samarkan rahasia yang dikenal + pola token/secret/password/Authorization. */
    public static function redact($text)
    {
        self::load();
        $text = (string) $text;

        $secrets = array_keys(self::$secrets);
        usort($secrets, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        foreach ($secrets as $secret) {
            $text = str_replace((string) $secret, '***', $text);
            $text = str_replace(json_encode((string) $secret), '"***"', $text);
        }

        $text = preg_replace('/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', '***', $text);
        $text = preg_replace('/"(access_token|refresh_token|client_secret|password|token|secret_code|approval_password|db_pass)"\s*:\s*"[^"]*"/i', '"$1":"***"', $text);
        $text = preg_replace('/(Authorization:\s*Bearer\s+)\S+/i', '$1***', $text);

        return $text;
    }

    private static function load()
    {
        if (self::$values !== null) {
            return;
        }

        self::$values = [];
        foreach (['workspace.env', 'secrets.env'] as $name) {
            self::parseFile(QA_AGENTIC . '/config/' . $name);
        }

        // Semua kunci rahasia (QA_PASS, QA_<PROFIL>_PASS, CLIENT_SECRET, ...), bukan daftar tetap.
        $keys = array_keys(self::$values);
        $envKeys = getenv();
        if (is_array($envKeys)) {
            $keys = array_merge($keys, array_keys($envKeys));
        }
        foreach (array_unique($keys) as $key) {
            if (!self::isSecretKey($key)) {
                continue;
            }
            $env = getenv($key);
            self::addSecret($env !== false && $env !== '' ? $env : (self::$values[$key] ?? null));
        }
    }

    /** Parser setara env.sh: KEY=value, komentar penuh/akhir baris (" #..."), kutip luar dibuang, kunci pertama menang. */
    private static function parseFile($file)
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $line = rtrim($line, "\r");
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            list($key, $value) = explode('=', $line, 2);
            $key = preg_replace('/\s+/', '', $key);
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) || array_key_exists($key, self::$values)) {
                continue;
            }
            $value = trim(preg_replace('/\s#.*$/', '', $value));
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
                $value = substr($value, 1, -1);
            }
            self::$values[$key] = $value;
        }
    }
}
