<?php
/**
 * Pekerja CLI ED-1251 (BUKAN skenario): memanggil OpnameExportService::xls/pdf/pdf-preview di proses terpisah dengan batas waktu CPU 8 detik,
 * supaya request yang berputar tak berujung (loop kolom Excel) tidak menggantung worker php-fpm. Dipanggil q251_xls_cli().
 * Pemakaian: php xls_worker.php <BE_DIR> <id sesi> <username> <xls|pdf|preview> [berkas JSON payload pratinjau]
 * Keluaran (stderr): "RETURNED"/"EXC ..." lalu "SHUTDOWN {error_get_last}" (error "Maximum execution time" = loop tak berujung).
 */
list(, $be, $sid, $user, $kind) = $argv;
$payloadFile = $argv[5] ?? null;
chdir($be . '/public');
require $be . '/vendor/autoload.php';
$app = require $be . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$u = Modules\V5\Entities\Models\User::where('username', $user)->first();
Illuminate\Support\Facades\Auth::shouldUse('api');
Illuminate\Support\Facades\Auth::guard('api')->setUser($u);
set_time_limit((int) (getenv('QA251_TL') ?: 8));
register_shutdown_function(function () {
    $e = error_get_last();
    fwrite(STDERR, 'SHUTDOWN ' . ($e ? json_encode($e) : '') . ' PEAK_MB=' . round(memory_get_peak_usage(true) / 1048576, 1) . "\n");
});
ob_start(function ($b) {
    return '';
});
$svc = new Modules\V5\Http\Services\DocumentArchive\OpnameExportService;
try {
    if ($kind === 'preview') {
        $r = $svc->pdf($sid, $payloadFile ? (json_decode(file_get_contents($payloadFile), true) ?: []) : []);
    } else {
        $r = $kind === 'pdf' ? $svc->pdf($sid) : $svc->xls($sid);
    }
    fwrite(STDERR, 'RETURNED ' . (is_object($r) ? get_class($r) : gettype($r)) . "\n");
} catch (\Throwable $e) {
    fwrite(STDERR, 'EXC ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200) . "\n");
}
