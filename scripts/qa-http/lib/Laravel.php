<?php

namespace QaHttp;

use Illuminate\Support\Facades\DB;

/**
 * Laravel (BE_DIR) di proses CLI ini: dipakai untuk membaca DB (read-only) dan untuk probe in-process.
 * Di-bootstrap sekali, hanya saat dibutuhkan (`--list` tidak pernah memanggilnya).
 */
final class Laravel
{
    private static $booted = false;
    private static $readOnly = [];

    public static function boot()
    {
        if (self::$booted) {
            return;
        }

        $root = Config::beDir();
        foreach (['vendor/autoload.php', 'bootstrap/app.php'] as $file) {
            if (!is_file($root . '/' . $file)) {
                throw new InfraError("BE_DIR tidak berisi {$file} (BE_DIR={$root})");
            }
        }

        try {
            require_once $root . '/vendor/autoload.php';
            $app = require $root . '/bootstrap/app.php';
            $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        } catch (\Throwable $e) {
            throw new InfraError(Config::redact('bootstrap Laravel gagal: ' . $e->getMessage()));
        }
        self::$booted = true;
    }

    /** Nama koneksi default Laravel (DB_CONNECTION di .env BE_DIR). */
    public static function defaultConnection()
    {
        self::boot();

        return config('database.default');
    }

    /** Nama DB koneksi default (DB_DATABASE di .env BE_DIR); berubah mengikuti profil lisensi. */
    public static function globalDb()
    {
        self::boot();

        return config('database.connections.' . config('database.default') . '.database');
    }

    /**
     * Koneksi baca saja ke DB/koneksi $name (nama koneksi, atau nama DB yang didaftarkan BE).
     * Memakai salinan config koneksi itu bernama `qa_ro_<name>`, jadi koneksi yang dipakai probe()
     * tidak ikut read-only. Setiap sesi MySQL-nya menjalankan SET SESSION TRANSACTION READ ONLY
     * sebelum dipakai: tulisan apa pun ditolak server.
     *
     * @return \Illuminate\Database\Connection
     */
    public static function db($name)
    {
        self::boot();

        $readOnly = 'qa_ro_' . $name;
        if (!isset(self::$readOnly[$readOnly])) {
            $connections = config('database.connections', []);
            $settings = $connections[$name] ?? null;
            if ($settings === null && $name === self::globalDb()) {
                $settings = $connections[config('database.default')] ?? null;
            }
            if ($settings === null) {
                throw new InfraError("koneksi DB '{$name}' tidak terdaftar di database.connections BE_DIR");
            }

            config(['database.connections.' . $readOnly => $settings]);
            $connection = DB::connection($readOnly);
            $connection->statement('SET SESSION TRANSACTION READ ONLY');
            self::$readOnly[$readOnly] = $connection;
        }

        return self::$readOnly[$readOnly];
    }

    /** Jalankan kode di proses CLI yang sudah bootstrap; koneksi default dikembalikan sesudahnya. */
    public static function probe(callable $callback)
    {
        self::boot();

        $default = DB::getDefaultConnection();
        try {
            return $callback();
        } finally {
            DB::setDefaultConnection($default);
        }
    }
}
