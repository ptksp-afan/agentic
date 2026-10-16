---
key: ED-1165
scope: be
round: 1
verdict: PASS
counts: {pass: 6, fail: 0, manual: 0, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

Bug BE-only: 12 route `api/v5/customer/{address,contacts,bank-accounts}*` dikomentari di `Modules/V5/Routes/Setup/customer.php`
(satu-satunya berkas berubah, +29/-26; kontroler/service tidak disentuh). Stage ini memegang AC-1..AC-5 [BE] dan AC-6 [FE]
(tanpa tahap FE: FE tidak berubah, hanya build + e2e).

- **Semua 6 AC PASS.** Tanpa token dan dengan token QA_USER, 12 route = 404 `Route Not Found!`; router dump 3304 -> 3292 baris,
  selisih tepat 12 baris R1-R12; `customers` (list/detail) dan route tetangga tidak berubah; layar Pelanggan jalan penuh di browser.
- **Tidak ada data bisnis ditulis.** COUNT 6 tabel pelanggan sama persis sebelum/sesudah (BE scenario dan e2e). POST/PUT ke URI
  nonaktif hanya dengan body tidak valid; POST/PUT e2e ke `customer/customers` diabort `page.route`. Tidak ada purge/hapus.
- **Tidak ada HTTP 500 dari item ini.** Satu 500 pra-ada di luar item terlihat saat membuka drawer pelanggan berfoto
  (`select/files/download`, berkas foto tidak ada di disk QA) = tiket **ED-1166** (lihat "Temuan di luar item" O-1): tidak
  dihitung sebagai defect ED-1165 (preseden ED-1149/ED-1154: 500 dari defect bertiket di luar item = known). Flow AC-6 dijalankan
  dengan pelanggan tanpa foto supaya 0 respons >= 400 tanpa pengecualian; satu test tambahan (6d) men-abort unduh foto dan
  mencatat jumlahnya (disclosure di bawah).
- Tidak ada fixture permission/QA_USER2 (K-5 dijawab developer: abaikan); tidak ada endpoint baru; isolasi tenant tidak berlaku
  (`QA_DBS` = 1 DB). `artisan route:list` gagal di baseline (`ProfitLossReportService.php:29`, via `ProfitLossReportController.php:17`),
  hanya dicatat; one-liner router dipakai (exit 0).
- Sesi: login QA_USER di `api_sidomaju` memakai `force_login` (P-5, diterima); semua token di-log-out (qa-http 1 dibuat/1 log-out,
  e2e 1/1 dicabut tiap run). Server: php-fpm, tanpa reload (kode baru terlayani: 404 langsung).

## Hasil per AC

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 [BE] tanpa token 12 request = 404 `Route Not Found!` | `qa/scenario.php` AC-1: R1-R12 (GET `address?pagination=1`, `address/5`, dst.; POST/PUT body tidak valid `{"customer_addresses":[{}]}` / `customer_contacts` / `customer_bank_accounts`), tiap respons: status 404, `message`=`Route Not Found!`, tanpa `result`/`msg_code`, bukan 500; plus `address/`, `contacts/`, `bank-accounts/` (trailing slash) 404 | PASS | `"$PHP_BIN" scripts/qa-http/run.php ED-1165` -> `AC-1 PASS 55 asersi \| 12 request tanpa token = 404; COUNT {...}`; `work/ED-1165/qa-http-r1-final.txt`, `work/qa-http/ED-1165-20261016153539.json` |
| AC-2 [BE] token QA_USER = 404 sama; COUNT 4 tabel sama | AC-2: `session()` QA_USER, 12 request yang sama, COUNT `customer_addresses`/`customer_contacts`/`customer_bank_accounts`/`customer_location_discounts` (`$t->db()` read-only) sebelum = sesudah = awal run (AC-1) | PASS | `AC-2 PASS 50 asersi`; COUNT 6653 / 2125 / 1313 / 1 sebelum dan sesudah. Tanpa token `cns` memakai DB yang sama (`globalDb`=`api_sidomaju`), jadi COUNT itu juga mencakup jalur tanpa token |
| AC-3 [BE] router dump 3304 -> 3292, selisih tepat 12 baris R1-R12 | one-liner router `verification.md` §2 dengan `$r->middleware()` (bukan `gatherMiddleware`, yang membuat controller dan fatal), `diff` vs `baseline/routes-v5.txt` | PASS | `wc -l`: 3292 (baseline 3304). `diff` = `131,142d130`: 12 baris `<` (address x4, contacts x4, bank-accounts x4; 4 di antaranya menunjuk `CustomerLocationDiscountController`/`CustomerOpeningBalanceController`), 0 baris `>`; `work/ED-1165/routes.diff`. Nama 5 kontroler R1-R12: 12 kemunculan di baseline, 0 sesudah. Sisa `api/v5/customer/*` = customer-types (5), customers (7), devices (6) dengan `auth:api` utuh |
| AC-4 [BE] `php -l` OK; one-liner jalan; `route:list` hanya dicatat | `"$PHP_BIN" -l Modules/V5/Routes/Setup/customer.php`; one-liner exit 0; `artisan route:list` | PASS | `No syntax errors detected`; one-liner exit 0, stderr kosong (`work/ED-1165/routes-after.err` 0 byte); `route:list` exit 1 `ProfitLossReportService.php:29 Trying to get property 'uri' of non-object` (= baseline, dicatat saja, `work/ED-1165/route-list.out`) |
| AC-5 [BE] regresi `customers` | AC-5: list `customer/customers?pagination=5` -> 200, `result.total` = COUNT `customers`, 5 baris; detail pelanggan yang punya alamat+kontak+rekening (dicari lewat DB saat run, bukan id tangan) -> 200, `customer_addresses`/`customer_contacts`/`customer_bank_accounts` tidak kosong, jumlah <= baris DB, tiap `id_customer` = pelanggan itu; tanpa token `customers`, `customers/{id}`, `devices`, `customer-types` tetap 401 `GE0111` (BR-5) | PASS | `AC-5 PASS 24 asersi \| total=6294` (COUNT `customers` 6294); contoh pelanggan CST-17390: 1 alamat, 1 kontak, 2 rekening |
| AC-6 [FE] regresi layar Pelanggan | Build staging + e2e `qa/e2e/ED-1165-pelanggan.spec.js` + `routes.json` (lihat bagian e2e) | PASS | `scripts/fe-build.sh build` exit 0 (`Compiled successfully`, `work/fe-build.log`); e2e 7/7 OK, `work/e2e/ED-1165-v5-customer-route-tanpa-auth-20261016-083308/` |

### Rincian AC-6 (e2e, profil default = QA_USER, `api_sidomaju`)

| Test | Isi | Hasil |
|---|---|---|
| smoke `routes.json` | `/setups/customers/customers` terbuka tanpa console/page error, tanpa respons >= 400, `forbidApi` = regex R1-R12 tidak terpanggil | OK |
| prasyarat | COUNT 6 tabel pelanggan sebelum (`fixture.php counts`, READ ONLY) | OK |
| 6a | list tampil (GET `customer/customers` 200, baris > 0); cari kode pelanggan; buka detail (tombol ubah, BUKAN tombol merah nonaktifkan): GET `customers/{id}` 200, jumlah alamat/kontak/rekening API = DB; panel Kontak/Alamat menampilkan baris (1/1), Rekening kosong (DB 0) | OK |
| 6b Ubah | drawer detail: Tambah Alamat (negara terisi default `ID Indonesia`, provinsi Bali, kota Kabupaten Jembrana), Tambah Kontak (idem), Tambah Rekening Bank (bank = kolom teks, lihat catatan); foto wajib diisi lewat input file 1x1 PNG (hanya state form); Simpan -> **PUT `customer/customers/{id}` diintersep + abort**, body berisi alamat 2 (1 lama + 1 baru), kontak 2, rekening 1 | OK |
| 6c Buat | modal Tambah Pelanggan: nama, pemilik, tipe pelanggan (opsi pertama `CST-G001 UMUM`); lokasi, akun piutang, kategori/termin terisi default; drawer alamat/kontak/rekening sama seperti 6b; Simpan -> **POST `customer/customers` diintersep + abort**, body alamat 1, kontak 1, rekening 1 | OK |
| 6d | pelanggan dengan alamat+kontak+rekening existing (CST-17390): panel Kontak 1, Alamat 1, Rekening Bank 2 baris = DB; klik rekening existing: drawer terisi (nama bank, nomor, nama pemilik). Hanya tes ini yang men-abort `select/files/download` (2 request foto, O-1) | OK |
| penutup | COUNT 6 tabel sesudah == sebelum | OK |

Monitor jaringan seluruh alur (tiap test, dari `monitor.responses`): 19 / 33 / 27 / 19 respons API, **semua 200**; request ke
R1-R12 = **0**; request tulis di luar yang diintersep = **0** (semua non-GET lain akan di-abort dan dihitung gagal); diintersep tepat 1
(6b PUT, 6c POST). Body di kawat berkunci camelCase (`customerAddresses`, `customerContacts`, `customerBankAccounts`; `req_snake`
mengubahnya jadi `customer_addresses`/... di BE); test menerima kedua bentuk dan mencatat yang terlihat.

Pengungkapan metode (agar tidak dibaca lebih bersih dari kenyataan):
1. Abort `route.abort('aborted')` membuat FE mencatat 1 `console.error` (teks `o`, `main.*.js`) per request yang di-abort (2 total: PUT dan
   POST). Itu akibat langsung abort e2e, bukan error produk dan bukan error lama bertiket. Entri itu ditandai di laporan
   `allowed: ED-1165-e2e-abort` (tetap tampil di `report.json`/galeri; hanya console dalam 3 dtk sesudah abort). Bukan entri allow-list
   (tidak ada `allowlist.json`, `routes.json` `allow: []`); jenis error lain tetap menggagalkan test.
2. Fixture memilih pelanggan **tanpa foto** untuk 6a/6b (`fixture.php clean`), karena semua pelanggan berfoto memicu 500 pra-ada O-1.
   Akibat: tidak ada pelanggan di QA_DB dengan rekening bank tanpa foto (0 dari 1852), jadi baris rekening existing tampil di UI hanya
   di 6d (foto di-abort), dan diverifikasi tanpa pengecualian di API (AC-5).
3. Spec menyebut "select negara/provinsi/kota, **bank**": di FE ini bank adalah kolom teks (`#bankName`), bukan select; yang berupa
   select dan terisi = negara, provinsi, kota (dari `select/customer/{nationalities,provinces,cities}`).

## Defect

Tidak ada defect pada ED-1165.

## Isolasi tenant & permission

- Tenant: `QA_DBS` = `api_sidomaju` saja (< 2 DB) -> tidak dijalankan; item tidak menyentuh pemilihan koneksi (router menolak sebelum
  `cns`). Tanpa token `cns` memakai koneksi default = DB yang sama; COUNT dicek di sana.
- Permission: tidak ada endpoint baru; 12 route dinonaktifkan menolak di router sebelum middleware (404 dengan dan tanpa token,
  AC-1/AC-2). K-5 (fixture user tanpa hak) dijawab developer: abaikan. Route `customers` dll. tetap `auth:api` + `permission_v5`
  (AC-3 + AC-5: 401 `GE0111` tanpa token).

## Galeri e2e & regresi BE

- Galeri e2e (run bukti): `/var/www/opname-archive/agentic/work/e2e/ED-1165-v5-customer-route-tanpa-auth-20261016-083308/index.html`
  (`report.json`, `screenshots/default/`: daftar, detail panel, tiga panel, rekening existing, drawer alamat/kontak/rekening, buat/ubah
  sebelum Simpan dan sesudah intersep). Run-run sebelumnya di `work/e2e/ED-1165-*` = iterasi spec (selector, foto wajib, penanda abort).
- Regresi BE: seluruh `qa/scenario*.php` (1 berkas, AC-1/AC-2/AC-5) dijalankan ulang sesudah e2e: **3/3 PASS**, exit 0
  (`work/ED-1165/qa-http-r1-final.txt`). Referensi silang: `git grep` ksp-react (seluruh repo) 0 acuan ke `customer/(address|contacts|bank-accounts)`;
  agentic: satu-satunya acuan di suite lain = `features/ED-1070-.../qa/golden/requests.json` (O-2).
- Berkas QA permanen: `qa/scenario.php`, `qa/e2e/ED-1165-pelanggan.spec.js`, `qa/e2e/fixture.php` (read-only), `qa/e2e/routes.json`.

## Temuan di luar item (tidak memengaruhi verdict ED-1165)

- **O-1 (= ED-1166, Low, sudah bertiket, bukan tiket baru):** `GET api/v5/select/files/download?idAttachment=<foto pelanggan>` = HTTP **500**
  `{"message":"Attachment isn't found","code":"NOT_FOUND_ATTACHMENT"}` (`Modules/V5/Http/Services/Select/FileManager/SelectFileService.php:36,61`),
  karena `storage/app/attachments/customerPhotos` tidak ada di mesin QA (baris `transaction_photos`/`attachments` ada, berkas tidak).
  Terlihat di browser sebagai `net::ERR_BLOCKED_BY_ORB` (tipe image) pada drawer detail pelanggan berfoto (CST-17390). Dari 79 pelanggan aktif beralamat+berkontak+berekening,
  0 yang tanpa foto (1852 pelanggan aktif tanpa foto semuanya tanpa rekening); ED-1154 mencatat hal yang sama (E-1). Tidak ada hubungan dengan
  route R1-R12 (router `select/*` tidak berubah, AC-3). Menurut D-6 seharusnya 4xx.
- **O-2 (informasi regresi silang, ED-1070 Done):** `features/ED-1070-v5-customize-builder-hardening/qa/golden/requests.json` masih
  memuat 3 list yang kini 404 (`api/v5/customer/address`, `customer/contacts`, `customer/bank-accounts`; baris 3370, 3425, 3480). Replay
  golden ED-1070 (AC-8, hanya sekali, sudah lewat) akan menampilkan 3 DIFF 404 yang diharapkan; tidak ada suite lain yang memanggilnya
  (`grep` features/*/qa). Tidak dijalankan ulang.
- **O-3 (harness e2e, kosmetik):** `config.apiBase()` = `API_URL` (`http://127.0.0.1:80`) tidak cocok dengan URL browser tanpa `:80`
  (`http://127.0.0.1/api/v5/...`), sehingga `monitor.apiResponses()` kosong dan daftar `api` di `report.json` kosong. Deteksi respons
  >= 400 oleh monitor tidak terpengaruh (berdasar semua respons); spec ini memakai `monitor.responses` sendiri untuk hitungan. Tidak diubah.
- **O-4 (kata spec, bukan kode):** BR-1 menyebut "ke-12 statement route"; yang dikomentari 16 statement (4 di antaranya route yang ditimpa
  L54-55/L59-60) = 12 method+URI. Kode benar (sudah dicatat reviewer). Spec AC-6 menulis body `customer_addresses`; di kawat camelCase
  (lihat atas).

## Yang tidak bisa diverifikasi (dan kenapa)

Tidak ada. Hal yang tidak dijalankan karena memang di luar cakupan: uji 403/permission user lain (K-5), isolasi multi-tenant (1 DB),
profil lisensi (`PROFILES` kosong), tes unit FE (FE tidak berubah), replay golden ED-1070 (O-2).

## Riwayat ronde

- r1 (2026-10-16): PASS 6/6. BE: scenario 3/3, router diff tepat 12, `php -l` OK. FE: build OK, e2e 7/7 (2 request tulis diintersep, 0 R1-R12, COUNT sama).
