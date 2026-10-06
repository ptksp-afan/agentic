<?php
// READ-ONLY. Mencetak secret oauth_clients <id> dari DB default server BE (DB_DATABASE di .env BE_DIR), tanpa
// newline. Dipakai harness E2E (lib/session.js) hanya sebagai cadangan bila secret di .env FE ditolak
// (invalid_client). Output ditangkap Node, tidak pernah dicetak ke layar.
//
//   "$PHP_BIN" scripts/e2e/lib/oauth-client-secret.php <client_id> <BE_DIR>

$beDir = $argv[2] ?? '';
if ($beDir === '' || !is_file($beDir . '/vendor/autoload.php')) {
    fwrite(STDERR, "BE_DIR tidak valid\n");
    exit(1);
}
chdir($beDir);
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = (int) ($argv[1] ?? 4);
$connection = Illuminate\Support\Facades\DB::connection();
$connection->statement('SET SESSION TRANSACTION READ ONLY');
$secret = $connection->table('oauth_clients')->where('id', $id)->where('revoked', 0)->value('secret');
if (!$secret) {
    fwrite(STDERR, "oauth_clients id {$id} tidak ditemukan / revoked\n");
    exit(1);
}
echo $secret;
