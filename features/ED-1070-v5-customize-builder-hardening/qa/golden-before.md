# ED-1070 AC-1 - golden "sebelum"

- BE `v5-opname-archive` **0b8417778**, tree bersih (awal & akhir), php-fpm; DB `api_sidomaju`; QA_USER. Waktu WIB.
- `golden/before/` ditangkap 2026-10-15 16:11:00-16:49:26, in-process + HTTP. Ulangan sesi baru 16:50-17:23 (`work/golden/ED-1070/check-before2`).
- Harness `golden/golden.php` (PHP 7.3, cara kerja di kepala berkas): Laravel di-boot sekali, tiap request di-fork lewat HTTP kernel
  dengan token asli QA_USER; SQL = `DB::listen` ternormalisasi; semua koneksi dalam transaksi yang selalu di-rollback; log/cache
  proses ke `work/golden/ED-1070/capture-*/`. Nol baris dibuat; token di-log-out.

## Cakupan
- 248 route (`routes.tsv` GET+P, tanpa `{param}`, bukan export) -> **231 list golden** (+ `archives@folder` = "Backup Arsip";
  6 list lama tercakup) + 3 list halaman-1 = 500.
- **5622 request**: golden **5526**, known-500 **96** (`requests.json` `set`). Sort 2609 (tiap `sort:true`, relasi asc+desc),
  filter 1377 (tiap query, nilai per tipe FE), page 693, combo 419, base 234, search 231, replay 59.
- Known-500: 81 `Unknown column` (sort relasi #9 dll.), 10 to-do-list sort `json_decode`, 2 shipments filter `idCarrier`, 1 periods
  `periodYear`, 2 halaman 1 (setup/customers, old-production-requests/recipes).
- Dilewati 18 (`skipped_lists`): 5 x 403 QA_USER tanpa izin (Raptor deposit/invoice pending+confirmed, raptor-discounts), 4 x 422
  select butuh parameter, 6 bukan list berpaginasi, 3 halaman 1 = 500 (di atas).
- 82 list kosong: oracle status + SQL. 7 list menulis `column_display_settings` saat GET: in-process saja, tanpa HTTP.

## Stabilitas
- Ulangan vs `before`: PASS 218 / DIFF 13 -> `--mark-unstable` 51 request (9 list): urutan seri kolom sort tidak unik (ids +
  eager-load; `sql_core` tetap dinilai), jendela `now()-3 bulan` (sales-orders salesman). Sesudahnya PASS 231; known-500 tetap 500.
- HTTP vs in-process: beda hanya `ids` (urutan seri) 32 request; status/total/per_page/current_page selalu sama.

## Guard vs tangkapan lama (2026-10-14, 6fcc91fd0)
| tabel | lama | kini |
|---|---|---|
| archives | 49149 / 10-13 16:36:08 | **49152 / 10-15 08:17:16** |
| customers, purchase_orders, items, suppliers, sales_invoices, column_display_settings | 6294, 1458, 393, 31, 16960, 361 | sama |

DU-4 a: archives berubah -> tangkapan ini oracle. Replay 59: 49 sama, 10 beda (semua archives). Guard lengkap (154 tabel +
130 modul) di `before/_meta.json`.

## "Sesudah" (dari agentic/, BE kode baru, SEBELUM fixture/suite QA - C-5)
```bash
G=features/ED-1070-v5-customize-builder-hardening/qa/golden
"$PHP_BIN" $G/golden.php capture --out=$G/after --mode=both --sets=golden,known500 > work/golden/ED-1070/after.log 2>&1
"$PHP_BIN" $G/golden.php compare $G/before $G/after   # exit 0 = tanpa DIFF; detail $G/after/_compare.json
```
~35 menit. Hindari jam 07:45-08:15 dan 23:45-00:15 WIB. `per_page` "10" vs 10 = DIFF(type-only). Log in-process ada di
`capture-*/laravel-*.log` (`[CustomizeBuilder]` harus 0 untuk golden). Known-500 dinilai AC-7. Jangan `--mark-unstable` di sini.

## Verifikasi ulang (resume 2026-10-15)
- 19:32-19:35 WIB, BE 0b8417778 bersih (`v5-opname-archive`), `golden.php` versi 10:26 UTC (17:26 WIB) tidak diubah; `php7.3 -l` OK.
- `compare before check-before2` (tanpa `--mark-unstable`): **PASS 231 / DIFF 0 / SKIPPED-guard 0 / MISSING 0**; known-500 96
  -> still500 96; exit 0 (`work/golden/ED-1070/compare-resume-1015.txt`).
- `before/` vs `work/golden/ED-1070/before-unmarked-backup/`: 225 berkas identik; 9 list beda HANYA kunci `unstable`
  (51 request); `_meta.json` hanya bertambah `guard_vs_old_capture`, `stability_check`, `times` (guard sama).
- Guard sekarang (fungsi `guard()` harness apa adanya, SELECT saja, tanpa login) vs `before/_meta.json`: 154 tabel + 130 modul
  `column_display_settings` **sama**; 0 list akan SKIPPED-guard (`work/golden/ED-1070/guard-resume-1234.json`).
- Tangkap ulang "sebelum": **tidak** (tidak perlu). Blok perintah "Sesudah" di atas tetap berlaku apa adanya.
