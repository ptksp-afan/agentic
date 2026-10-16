<?php
/**
 * ED-1165 - helper e2e BACA SAJA (sesi MySQL READ ONLY via QaHttp\Laravel::db): tidak ada setup/teardown data.
 *
 *   "$PHP_BIN" fixture.php customer   pelanggan aktif yang punya alamat + kontak + rekening bank -> JSON {id, code, name, n_*}
 *   "$PHP_BIN" fixture.php clean      pelanggan aktif dengan alamat + kontak, TANPA foto (berkas foto tidak ada di disk QA)
 *   "$PHP_BIN" fixture.php counts     COUNT tabel pelanggan -> JSON
 *
 * Keluaran: satu baris JSON terakhir di stdout. Tanpa password/token.
 */

namespace QaHttp;

define('QA_AGENTIC', str_replace('\\', '/', realpath(__DIR__ . '/../../../..')));

require QA_AGENTIC . '/scripts/qa-http/lib/Outcome.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Config.php';
require QA_AGENTIC . '/scripts/qa-http/lib/Laravel.php';

$db = Laravel::db(Config::defaultDb());
$cmd = isset($argv[1]) ? $argv[1] : '';

/** Kolom hitung anak per pelanggan */
$counts = '(select count(*) from customer_addresses a where a.id_customer = c.id_customer) n_addresses,'
    . ' (select count(*) from customer_contacts k where k.id_customer = c.id_customer) n_contacts,'
    . ' (select count(*) from customer_bank_accounts b where b.id_customer = c.id_customer) n_banks';
$hasAddr = ' exists (select 1 from customer_addresses a where a.id_customer = c.id_customer)';
$hasContact = ' exists (select 1 from customer_contacts k where k.id_customer = c.id_customer)';
$hasBank = ' exists (select 1 from customer_bank_accounts b where b.id_customer = c.id_customer)';
// Bukan bagian ED-1165: berkas foto pelanggan tidak ada di disk QA (storage/app/attachments/customerPhotos tidak ada)
// -> GET select/files/download = 500 NOT_FOUND_ATTACHMENT. `clean` = pelanggan TANPA foto supaya monitor jaringan
// AC-6 hanya mengukur dampak ED-1165 (lihat qa-be.md "Temuan di luar item").
$noPhoto = ' not exists (select 1 from transaction_photos p where p.id_transaction = c.id_customer)'
    . ' and not exists (select 1 from customer_contacts k2 join attachments t2 on t2.id_transaction = k2.id_customer_contact where k2.id_customer = c.id_customer)';

if ($cmd === 'customer' || $cmd === 'clean') {
    // customer = alamat + kontak + rekening (semua punya foto); clean = alamat + kontak, tanpa foto
    $where = $cmd === 'customer' ? $hasAddr . ' and' . $hasContact . ' and' . $hasBank : $hasAddr . ' and' . $hasContact . ' and' . $noPhoto;
    $rows = $db->select(
        'select c.id_customer id, c.customer_code code, c.customer_name name, ' . $counts
        . ' from customers c where c.is_active = 1 and' . $where . ' order by c.customer_code limit 1'
    );
    echo json_encode($rows ? $rows[0] : null, JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

if ($cmd === 'counts') {
    $out = [];
    foreach (['customers', 'customer_addresses', 'customer_contacts', 'customer_bank_accounts', 'customer_location_discounts', 'customer_opening_balances'] as $table) {
        $out[$table] = (int) $db->table($table)->count();
    }
    echo json_encode($out), "\n";
    exit(0);
}

fwrite(STDERR, "perintah: customer | clean | counts\n");
exit(2);
