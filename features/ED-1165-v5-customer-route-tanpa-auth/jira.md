# ED-1165 (Bug, High, label ai-found) - V5 Setup Customer - route address / contacts / bank-accounts tanpa auth:api (bisa dibaca tanpa login)

Deskripsi Jira (disalin epic loop 2026-10-16):

Ditemukan QA AI saat long run ED-1022 (item ED-1070), di luar lingkup item itu. Tidak diperbaiki; perlu keputusan developer.

**Gejala**
`GET api/v5/customer/address`, `GET api/v5/customer/contacts`, `GET api/v5/customer/bank-accounts` **tanpa token** (header Accept JSON) -> 200 dan mengembalikan baris (di DB uji api_sidomaju total 6653 / 2125 / 1313; halaman 5 baris). Data berisi alamat, kontak, dan rekening bank pelanggan.

**Sebab**
`Modules/V5/Routes/Setup/customer.php` baris 22-40: grup `/address`, `/contacts`, `/bank-accounts` tidak memakai `middleware('auth:api')` dan tanpa `permission_v5`, berbeda dengan grup `/customer-types`, `/customers`, `/devices` di berkas yang sama. Sudah begitu sebelum ED-1070.

**Catatan**
* POST/PUT di grup yang sama (store/update) juga tanpa auth; tidak diuji (tulis DB).
* Pemetaan route janggal di berkas yang sama: grup kedua `/contacts` POST -> `CustomerLocationDiscountController@store`, grup kedua `/bank-accounts` POST -> `CustomerOpeningBalanceController@store` (bertabrakan dengan grup pertama).
* Bukti: agentic/features/ED-1070-v5-customize-builder-hardening/qa-be.md bagian "Di luar item".
