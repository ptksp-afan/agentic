<?php
/**
 * Pekerja CLI ED-1251 review r3 (BUKAN skenario): render PDF satu sesi opname DI DALAM PROSES (tanpa HTTP) sebagai pemilik sesi,
 * BACA SAJA - sesi MySQL default diset `SET SESSION TRANSACTION READ ONLY` sebelum apa pun, jadi tulisan apa pun (baris
 * template/cds, sesi opname) ditolak server dan terlihat sebagai EXC. Dipakai untuk sesi nyata developer yang tidak terlihat QA_USER
 * lewat HTTP (404 ARCHIVE412); tidak ada role/permission yang diberikan.
 * Pemakaian: php dev_render.php <BE_DIR> <id sesi> <username pemilik> <berkas keluaran .pdf> [berkas JSON payload pratinjau]
 * Keluaran (stderr): "RETURNED <bytes>" atau "EXC ...". Berkas keluaran = byte PDF.
 */
list(, $be, $sid, $user, $out) = $argv;
$payloadFile = $argv[5] ?? null;
chdir($be . '/public');
require $be . '/vendor/autoload.php';
$app = require $be . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::connection(config('database.default'))->statement('SET SESSION TRANSACTION READ ONLY');
$u = Modules\V5\Entities\Models\User::where('username', $user)->first();
if (!$u) {
    fwrite(STDERR, "EXC pemilik sesi tidak ditemukan\n");
    exit(2);
}
Illuminate\Support\Facades\Auth::shouldUse('api');
Illuminate\Support\Facades\Auth::guard('api')->setUser($u);
if (($old = getenv('QA251_OLD_VIEWS')) && is_dir($old)) {
    // kontrol negatif (review r3): view v5 dari direktori lain (mis. report-template-6 sebelum perbaikan R-3, `git show HEAD:`) diprioritaskan; kode aplikasi tidak diubah
    Illuminate\Support\Facades\View::prependNamespace('v5', $old);
}
set_time_limit(120);
ini_set('memory_limit', '1G');
ob_start();
$svc = new Modules\V5\Http\Services\DocumentArchive\OpnameExportService;
try {
    $payload = $payloadFile ? (json_decode(file_get_contents($payloadFile), true) ?: []) : null;
    $svc->pdf($sid, $payload);
    $bytes = ob_get_clean();
    file_put_contents($out, $bytes);
    fwrite(STDERR, 'RETURNED ' . strlen($bytes) . "\n");
} catch (\Throwable $e) {
    @ob_end_clean();
    fwrite(STDERR, 'EXC ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 300) . "\n");
    exit(1);
}
