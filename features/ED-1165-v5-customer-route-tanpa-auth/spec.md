---
key: ED-1165
epic: ED-1022
title: V5 Setup Customer - route address / contacts / bank-accounts tanpa auth:api (bisa dibaca tanpa login)
type: bug
status: approved
batch: ED-1165
fe_modules: []
new_fe_modules: []
be_modules: [Setup/Customer]
qa_model: sonnet
contract: null            # tidak ada endpoint yang dipakai FE berubah bentuk
depends_on: []
---

## Ringkasan

12 route `api/v5/customer/{address,contacts,bank-accounts}*` terdaftar tanpa `auth:api` dan `permission_v5`: tanpa login
orang bisa membaca semua alamat, kontak, dan rekening bank pelanggan, dan POST `address`/`contacts` bisa menulis ke DB
default. FE (web dan mobile, satu basis kode ksp-react) tidak memakai satu pun. Perbaikan (Gate 1, K-1/K-2): ke-12 route
**dinonaktifkan** (di-comment) di `Modules/V5/Routes/Setup/customer.php`; kontroler dibiarkan.

## Reproduksi

BE 5c4c69bcd, 2026-10-16, tanpa token, `Accept: application/json`: GET `customer/address?pagination=1`,
`contacts?…`, `bank-accounts?…` → **200** `CUSADDRESS200`/`CUSCONTACT200`/`CUSBANKACC200` berisi baris;
`address/0` → 200 `result:null`. Pembanding `customer/customers`, `customer/devices` → 401 `GE0111`. Tanpa token `cns`
memakai koneksi `mysql` (`AuthHelper.php:124-146`). POST/PUT tidak dipanggil; dari router dump
(`baseline/routes-v5.txt`, 3304 baris `api/v5`, `$r->middleware()`):

| # | Method URI (`api/v5/customer/…`) | Aksi terdaftar (baseline) | Baris `customer.php` |
|---|---|---|---|
| R1/R2 | GET `address`, `address/{id}` | CustomerAddressController@index/show | 23-24 |
| R3/R4 | POST `address`, PUT `address/update/{id}` | CustomerAddressController@store/update | 25-26 |
| R5/R6 | GET `contacts`, `contacts/{id}` | CustomerContactController@index/show | 30-31 |
| R7/R8 | POST `contacts`, PUT `contacts/update/{id}` | **CustomerLocationDiscountController@store/update** (L54-55 menimpa L32-33) | 32-33, 54-55 |
| R9/R10 | GET `bank-accounts`, `bank-accounts/{id}` | CustomerBankAccountController@index/show | 37-38 |
| R11/R12 | POST `bank-accounts`, PUT `bank-accounts/update/{id}` | **CustomerOpeningBalanceController@store/update** (L59-60 menimpa L39-40) | 39-40, 59-60 |

Penimpaan: method+URI sama mengganti route sebelumnya (`RouteCollection.php:65-73`).

## Akar masalah

`9fa148d22` (2024-01-29) memasang `auth:api` di seluruh prefix `customer`; `6b20ae9ae` (2024-01-30) memindahnya ke grup
`/customer-types` dan `/customers` saja, `/address`, `/contacts`, `/bank-accounts` tertinggal (regresi, terbawa ke V5 di
`ce049ce4e`). `eebccbd9b` (2024-06-11) menambah grup `//CustomerLocationDiscount`/`//CustomerOpeningBalance` dengan prefix
salin-tempel (`customer.php:52-61`).

**Bukti tidak dipakai FE** (diverifikasi ulang 2026-10-16, ksp-react 41146943c, `git grep` seluruh repo termasuk
`android/`, `electron/`, `public/`, `docs/`):
- Tidak ada string `customer/address`, `customer/contacts`, `customer/bank-accounts`, `…/update/` di repo;
  `src/configuration/endpoints.js` tidak berisi R1-R12.
- Form Pelanggan (`src/containers/Customer`) hanya memanggil `customer/customers` (get/post/put/approve,
  `customer.api.js:6-14`) dan endpoint `select/customer/*`; alamat/kontak/rekening/diskon lokasi/saldo awal dikirim
  bersarang (`customerAddresses`, `customerContacts`, `customerBankAccounts`, …) dan disimpan BE di
  `CustomerService.php:136-156,283-313`.
- `CustomerBankAccountShortcut` memakai `postCustomerCustomerBankAccounts` = POST `customer/customers/customer-bank-accounts`
  (`endpoints.js:1659`, `customer.api.js:15`): **bukan** R1-R12. Hari ini sudah 405 (tidak ada POST untuk URI itu) dan
  komponen tidak dipasang di layar mana pun (hanya diekspor, `customer-components/index.js:17`). Simulasi router tanpa
  R1-R12 (in-process, tanpa dispatch): tetap `MethodNotAllowedHttpException`; `customers/{id}` GET dan POST `customers`
  tetap ke CustomerController. Menonaktifkan R1-R12 tidak mengubah apa pun untuknya.

## Perbaikan

Hanya `Modules/V5/Routes/Setup/customer.php`. Tanpa seed, Updater, kode pesan, FE, contract.

| Aturan | Sumber |
|---|---|
| BR-1 Lima blok grup L21-41 (`/address`, `/contacts`, `/bank-accounts`) dan L52-61 (grup kedua `/contacts`, `/bank-accounts`) di-comment beserta ke-12 statement route-nya, sehingga tidak ada grup kosong tersisa | Gate 1 K-1/K-2 |
| BR-2 Di atas blok yang dinonaktifkan ada komentar singkat, mis. `// ED-1165: route address/contacts/bank-accounts dinonaktifkan - tanpa auth:api, tidak dipakai FE (web/mobile); data disimpan lewat /customers` | Gate 1 |
| BR-3 Kontroler, FormRequest, service, model tidak diubah/dihapus; `use` di `customer.php:3-9` boleh tetap | Gate 1 |
| BR-4 Ke-12 URI/method → 404 JSON `{"message":"Route Not Found!"}` (`Handler.php:57-58`), dengan atau tanpa token (router menolak sebelum middleware) | simulasi router: 12/12 `NotFoundHttpException`, tidak ada route wildcard lain yang cocok |
| BR-5 Route lain tidak berubah (`/customer-types`, `/customers`, `/devices`, seluruh `api/v5`) | - |

## Acceptance criteria

`Accept: application/json` di semua request. Probe POST/PUT ke URI yang dinonaktifkan memakai body tidak valid
(`{"customer_addresses":[{}]}` dst.), jadi tidak menulis walau route ternyata masih aktif. Tidak ada data ditulis.

| AC | Given / When / Then | Tag | BR | Cek |
|---|---|---|---|---|
| AC-1 | Tanpa token: 12 request R1-R12 (id `5` untuk `{id}`) → 404, body `message`=`Route Not Found!` | [BE] | 1,4 | http |
| AC-2 | Token QA_USER: 12 request yang sama → 404 yang sama; COUNT `customer_addresses`, `customer_contacts`, `customer_bank_accounts`, `customer_location_discounts` QA_DB sama sebelum/sesudah AC-1..2 | [BE] | 1,4 | http |
| AC-3 | Router dump (verification.md §2, `$r->middleware()`) vs `baseline/routes-v5.txt`: 3304 → 3292 baris; selisih **tepat** 12 baris R1-R12 (`diff` hanya baris `<`), tidak ada baris lain berubah | [BE] | 1,5 | unit |
| AC-4 | `php -l Modules/V5/Routes/Setup/customer.php` = No syntax errors; router one-liner (AC-3) jalan tanpa error. `artisan route:list` sudah gagal di baseline (`ProfitLossReportController.php:17`, verification.md §2): hanya dicatat, bukan syarat | [BE] | 1,2 | unit |
| AC-5 | Regresi API: token QA_USER, GET `customer/customers?pagination=5` → 200 `total` = COUNT `customers`; GET `customer/customers/{id}` pelanggan yang punya alamat/kontak/rekening → 200 berisi ketiganya | [BE] | 5 | http |
| AC-6 | Regresi FE, QA_USER, Setup > Pelanggan (`/setups/customers/customers`): list tampil; detail pelanggan yang ada terbuka; form **Buat** dan **Ubah** terbuka, drawer tambah alamat, kontak, rekening bank (beserta select negara/provinsi/kota, bank) terisi tanpa error; saat Simpan, request POST/PUT `customer/customers` diintersep Playwright (`page.route`, abort) dan diasersi berisi `customer_addresses`/`customer_contacts`/`customer_bank_accounts` - tidak ada data tersimpan. Monitor jaringan seluruh alur: 0 request ke URI R1-R12, 0 respons 401/403/404/5xx selain request yang diintersep | [FE] | 5 | e2e |

## Keputusan untuk developer

| K | Jawaban |
|---|---|
| K-1 Permission R1-R12 | **Dijawab (2026-10-16):** ksp-react tidak memakai R1-R12 (app mobile dibangun dari basis kode yang sama, jadi juga tidak) - jangan tambah middleware; comment ke-12 route dengan komentar "tidak dipakai FE (web/mobile), dinonaktifkan karena tanpa auth:api (ED-1165)"; kontroler tidak dihapus |
| K-2 Pemetaan ganda `/contacts`, `/bank-accounts` | **Dijawab (2026-10-16):** sama dengan K-1 - ikut dinonaktifkan |
| K-3 10 route V5 lain tanpa `auth:api` | **Dijawab (2026-10-16):** abaikan - route itu memang sengaja tanpa `auth:api` |
| K-4 Bug lain di kontroler yang sama | **Dijawab (2026-10-16):** abaikan (tidak relevan, route dinonaktifkan) |
| K-5 User tanpa hak untuk uji 403 | **Dijawab (2026-10-16):** abaikan - tidak perlu fixture permission |

## Di luar cakupan

FE (tidak berubah); kontroler/service R1-R12 (dibiarkan, kini tidak terjangkau); 10 route V5 lain tanpa `auth:api`
(sengaja, K-3); `CustomerBankAccountShortcut` (komponen mati, route-nya 405 sejak sebelum item). A-1..A-10 tidak
tersentuh; `normalize_pagination`/`normalize_search` (ED-1070) tidak diubah.
