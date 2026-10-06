<?php
// READ-ONLY. Mencetak lisensi aktif dan DB default sesuai .env BE_DIR, satu baris:
//   equal_integration=<0|1> db=<nama> client=<nama>
// Dipakai scripts/profile.sh untuk memverifikasi pergantian profil lisensi.
//   php profile-probe.php [BE_DIR]      (BE_DIR default: env BE_DIR)

$root = isset($argv[1]) ? $argv[1] : getenv('BE_DIR');
if (!$root || !is_file($root . '/vendor/autoload.php') || !is_file($root . '/bootstrap/app.php')) {
    fwrite(STDERR, "BE_DIR tidak valid (butuh vendor/autoload.php dan bootstrap/app.php)\n");
    exit(2);
}

chdir($root);
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = Illuminate\Support\Facades\DB::getDatabaseName();
if (!class_exists('App\Lib\MyHelper')) {
    echo "equal_integration=? db={$db} client=? (App\\Lib\\MyHelper tidak ada)\n";
    exit(1);
}

$license = (new App\Lib\MyHelper)->getLicenseData();
if ($license === null) {
    echo "equal_integration=? db={$db} client=? (lisensi gagal diverifikasi)\n";
    exit(1);
}

echo 'equal_integration=' . (int) ($license['equal_integration'] ?? 0)
    . ' db=' . $db
    . ' client=' . ($license['client_name'] ?? '-')
    . "\n";
