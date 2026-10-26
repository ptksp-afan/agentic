---
key: ED-1250
scope: be
round: 2
verdict: PASS
counts: {pass: 13, fail: 0, manual: 0, not_verifiable: 1}
be_server: fpm
---
## Ringkasan

QA BE ronde 2 = ronde revisi review (bukan ulang kegagalan): spec § "Revisi review 2026-10-26" **R-1** (urutan default filter; AC-1 direvisi) dan **R-2** (index
`GET setting/display-columns` disaring untuk non-pemegang `Opname Document`; AC-25 baru). Semua 13 AC `[BE]` (AC-1..AC-12, AC-25) PASS terhadap php-fpm `API_URL` /
DB `api_sidomaju`; 0 defect; tidak ada kode aplikasi diubah QA; berkas kotor developer (`baseline/be.status`) dan folder `ED-1251-*` tidak disentuh.
Run penuh ED-1250: `PASS=18 FAIL=0 SKIP=1` (C-0, AC-1..12, AC-25, X-1..X-3, C-8; SKIP = ISO-1, QA_DBS hanya satu DB) - `work/ed1250/r2-full.out`,
`work/qa-http/ED-1250-20261026122624.json`. Hasil kode lama tidak terlihat (php-fpm, `BE_RELOAD_CMD` kosong; urutan R-1 dan saringan index terbukti aktif di respons).

- DB uji: baris cds `documentArchive` sudah dibuat ulang developer di dalam proses (id 2260 -> **2586**, `updated_at` 2026-10-26 12:04:16, 364 baris tabel). Itu keadaan awal run
  (DU-5 a); isinya sudah urutan R-1 dan **sama persis** sesudah seluruh run (C-8, lihat Teardown).
- Skenario diubah/ditambah (`qa/`): `qa_lib.php` (konstanta `Q50_R1_*`, oracle aturan sisip `q50_restore_expect`, helper index), `scenario1-display-setting.php` (AC-1, AC-10, AC-11 ditulis ulang
  untuk urutan R-1 + respons PUT), `scenario6-index-filter.php` (baru: AC-25, X-3), `scenario9-teardown.php` (C-8 + urutan R-1). Berkas sementara (explore, r1-old, cek akhir) sudah dihapus.
- Jumlah asersi: AC-1 62 (r1: 43), AC-10 55 (39), AC-11 86 (37), AC-25 746 (baru), X-3 39 (baru), C-8 15 (13); AC-2..AC-9, AC-12, X-1, X-2 **jumlah asersi sama persis dengan r1** (3628/564/11054/132/264/134/210/654/38/33/64).

## Hasil per AC

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 (rev R-1) | Config `documentArchive.php`: `current_queries` = name, type, updatedAt, showRelatedTransaction, **verificationStatus (terakhir)**; `available_queries` = urutan sama + **updatedBy terakhir**, updatedBy tidak di current; bentuk entri (title en/id, select, endpoint null, 2 opsi, tanpa initial_value/required). `GET archives` `result.queries` (user lokasi + superadmin) = urutan R-1, vs di indeks terakhir tepat sesudah showRelatedTransaction, tanpa updatedBy. Baris cds (current & available) = config dan urutan R-1. Updater dijalankan dalam transaksi + rollback: baris hasil = config, urutan R-1, baris asli tidak berubah. Terdaftar sekali di `Updaters/config.php` (kunci terbesar, epoch = nama berkas). Data FE (bagian BE AC-13): `GET display-columns/documentArchive` pemegang = urutan R-1 di Displayed Filters (current & available) | PASS | 62 asersi; `work/ed1250/r2-ac1.out`, `r2-full.out` |
| AC-2..AC-7, AC-9, AC-12 | Tidak berubah oleh R-1/R-2; dijalankan ulang penuh sebagai regresi (root/folder/cari, folder tak pernah disaring, angka, mode cari, paginasi, nilai salah, probe jumlah query) | PASS | jumlah asersi identik dengan r1; `r2-full.out` |
| AC-8 | Preset `List Archive` tanpa OD: 11 bentuk x kunci VERIFIED/UNVERIFIED = 200, `result` identik tanpa kunci, `queries` tanpa entri (urutan R-1 sisa: name,type,updatedAt,showRelatedTransaction), tanpa `document_verified*`; pembanding OD identik; QA_USER2 sungguhan; tanpa List Archive 403 `GE0114` | PASS | 210 asersi; `r2-full.out` |
| AC-10 | `GET display-columns/documentArchive`: non-pemegang current/available queries = tersimpan tanpa entri (urutan sama, kini `name,type,updatedAt,showRelatedTransaction` / `...,updatedBy`), kolom tanpa `documentVerified`, body tanpa jejak; pemegang = urutan R-1 lengkap; `customer`/`supplier` identik; `documentArchiveOpnameHistory` non-pemegang 403 `GE0114` (user lokasi + QA_USER2). **Langkah unpark ED-1251 kini berlaku dan dijalankan**: `documentArchiveOpnameResult` non-pemegang GET + PUT = 403 `GE0114` (tabel cds tidak berubah), tidak ada di index R0 bersama `documentArchiveOpnameHistory`; baris keduanya ada di DB dan tampil untuk pemegang | PASS | 55 asersi; `r2-ac10-11.out` |
| AC-11 | PUT `documentArchive` non-pemegang, 5 langkah. (a) isi default tak diubah: DB urutan R-1 tak bergeser. (b) urutan query dibalik, `updatedAt` hanya di available, item `verificationStatus` palsu: 200; DB current = `type,name,showRelatedTransaction,verificationStatus`, available = `updatedBy,showRelatedTransaction,verificationStatus,updatedAt,type,name` (sama dengan oracle aturan sisip kontrak §2: sesudah pendahulu showRelatedTransaction), objek entri = tersimpan, `documentVerified` tetap. (c) pendahulu entri dibuang dari kiriman (type di current, showRelatedTransaction di available): entri masuk ke indeks semula / akhir. (d) pemegang menyembunyikan entri: `queries` tanpa entri tetapi filter VERIFIED/UNVERIFIED tetap berlaku; PUT non-pemegang berikutnya tidak memunculkannya dan posisi di available tak bergeser; pemegang menampilkan lagi (di akhir). **Respons PUT** (= index tersaring, R-2) pada 7 PUT: non-pemegang tanpa kedua entri di `current_*`/`available_*` baris documentArchive, tanpa baris seluruh-modul, `total` = baris tabel - 2; pemegang lengkap. Baris cds dipulihkan persis (CRC tabel + isi baris) | PASS | 86 asersi; `r2-ac10-11.out` |
| AC-25 (baru, BE) | Index `GET setting/display-columns` (semua halaman, pagination 1000, 200, default 10, 7, 1, 150): pemegang (preset OD, superadmin role 1) = baris documentArchive lengkap (4 list); non-pemegang (preset LIST, NOLIST, QA_USER2 sungguhan) = tanpa `verificationStatus` di `current_queries`/`available_queries` dan tanpa `documentVerified` di `current_columns`/`available_columns` (judul en/id juga tak ada), item lain objek-per-objek identik dengan pemegang, field lain baris sama (`array_key_exists`), semua 361 modul lain identik, satu-satunya baris hilang = `documentArchiveOpnameHistory` + `documentArchiveOpnameResult` (AC-10), `total` = 364 - 2, urutan `updated_at` desc = pemegang tanpa 2 baris itu, `last_page` = ceil(total/per_page), gabungan halaman = total tanpa duplikat/hilang, halaman di luar batas = 200 data kosong, `documentArchive` baris 1 tersaring di tiap halaman. `search` index (7 kata kunci: documentArchive, documentarchive, Archive, customer, Opname, nama modul seluruh-modul, tak ada hasil): pemegang = DB LIKE, non-pemegang = DB LIKE tanpa seluruh-modul (nama seluruh-modul = 0 baris, 200 bukan 403), baris documentArchive di hasil search tersaring. Peta sama dengan `GET documentArchive`. Baris DB tidak ditulis (CRC tabel dan baris documentArchive identik sebelum/sesudah, `updated_at` tak maju) | PASS | 746 asersi; `work/ed1250/r2-ac25.out` |
| X-3 (ekstra R-2) | Nama baris diubah sementara ke varian `DOCUMENTARCHIVE `, `documentarchive`, `DocumentArchive  ` (kolasi `latin1_general_ci`, berjurnal): index non-pemegang tetap menyaring (`sameModule`), pemegang lengkap, total -2; baris dipulihkan persis (nama, id, isi, updated_at) | PASS | 39 asersi; `r2-full.out` |
| X-1, X-2 | Tepi PUT/GET queries (varian nama di URL, PUT tanpa list queries, queries null) dan filter bersama key lain | PASS | 33 / 64 asersi |
| ISO-1 | Isolasi tenant | SKIP (tidak bisa diverifikasi) | `QA_DBS` satu DB |
| AC-22 (bagian BE, milik stage fe) | Regresi ED-1027 scenario1/2/9/10 dan ED-1028 scenario9, lihat "Regresi" | 13 PASS (12 ED-1027 + 1 ED-1028), 1 FAIL usang (dicatat) | `r2-reg-ed1027.out`, `r2-reg-ed1028.out` |
| C-8 | Teardown, lihat di bawah | PASS | 15 asersi; `r2-c8-final.out` |

AC `[FE]`/`[BE+FE]` (AC-13..AC-24) milik stage fe; bagian data BE AC-13 sudah dicek di AC-1, bagian BE AC-19/AC-20 (non-pemegang tanpa entri, pemegang sembunyikan/tampilkan) di AC-10/AC-11/AC-25.

## Asersi ronde 1 yang dibalik R-1 / R-2

Bukti pembalikan: asersi r1 dijalankan ulang terhadap kode baru (`work/ed1250/r2-r1old.out`, berkas sementara dihapus): AC-1 r1 FAIL, AC-11 r1 FAIL, AC-10 r1 PASS. Runner berhenti di asersi gagal pertama per AC, jadi baris
yang lain dari tabel ini ditentukan dari pembacaan skenario r1 dan dibuktikan oleh literal baru yang lulus.

| # | Asersi r1 | Ekspektasi lama | Ekspektasi baru | Hasil baru |
|---|---|---|---|---|
| 1 | AC-1 config `current_queries` | `verificationStatus` tepat sesudah `type` | `name,type,updatedAt,showRelatedTransaction,verificationStatus` (terakhir); `updatedBy` tidak di current | PASS (r1 FAIL: "kondisi tidak terpenuhi", `r2-r1old.out`) |
| 2 | AC-1 config `available_queries` | tepat sesudah `type` | urutan #1 + `updatedBy` paling akhir | PASS |
| 3 | AC-1 `GET archives` `result.queries` user lokasi | tepat sesudah `type` | urutan R-1; vs di indeks terakhir; tanpa `updatedBy` | PASS |
| 4 | AC-1 `GET archives` `result.queries` superadmin | idem | idem | PASS |
| 5 | AC-11 DB `current_queries` sesudah PUT non-pemegang | `type,verificationStatus,name,showRelatedTransaction` (pendahulu = type) | `type,name,showRelatedTransaction,verificationStatus` (pendahulu = showRelatedTransaction) | PASS (r1 FAIL: diharapkan ..., didapat ..., `r2-r1old.out`) |
| 6 | AC-11 DB `available_queries` | `updatedBy,showRelatedTransaction,updatedAt,type,verificationStatus,name` | `updatedBy,showRelatedTransaction,verificationStatus,updatedAt,type,name` | PASS |
| 7 | AC-11 GET pemegang sesudah PUT ("entri tampil sesudah type") | `type,verificationStatus,name,showRelatedTransaction` | `type,name,showRelatedTransaction,verificationStatus` | PASS |
| 8 | AC-10 prasyarat unpark ED-1251 | "TIDAK BERLAKU: ED-1251 belum di-commit" | berlaku; 403 `GE0114` GET+PUT, tidak di index R0 (sebab: commit ED-1251, bukan R-1/R-2) | PASS |
| 9 | R-2 (r1 hanya pengamatan O-2, tanpa asersi) | index memuat entri untuk non-pemegang (dicatat saja) | AC-25: tanpa entri; respons PUT ikut tersaring (7 PUT di AC-11) | PASS |

Tidak terbalik (diperiksa): AC-10 baris "dengan/tanpa OD = tersimpan" (dihitung dari baris tersimpan, r1 lolos juga, hanya label "sesudah type" diganti), AC-8 (kunci dibuang dari `queries`, relatif), ED-1027 s1/s2/s9/s10
(tidak ada asersi isi/urutan index; hanya GET/PUT satu modul), ED-1028 AC-28 (total -2, search 3 baris: tidak terpengaruh saringan item). **Hitungan: 7 asersi r1 terbalik (#1-#7), 1 prasyarat usang oleh ED-1251 (#8), 1 area R-2 baru (#9).**
ED-1027 `scenario9` AC-22 tetap FAIL usang seperti r1 (O-3): selisih tunggal `queries` 4 vs 5 entri (pemegang OD memuat `verificationStatus`); dihitung ulang dari laporan JSON.

## Defect

Tidak ada.

## Pengamatan (bukan defect; carry-over, tidak diubah ronde ini)

- **O-1..O-4 ronde 1** tetap (O-1 key `search` ber-titik tak dikenal = 500 pra-ada; O-3 AC-22 ED-1027 usang; O-4 ejaan key lain = list datar). **O-2 selesai** (R-2: index kini tersaring).
- **O-5 (pra-ada, ED-1028)**: `ColumnDisplaySettingService::index` mengurutkan `updated_at desc` tanpa tie-breaker; 301 dari 364 baris punya `updated_at` kembar. Urutan antar-kembar tidak dijamin, jadi asersi QA membandingkan urutan lewat nilai `updated_at` + himpunan nama.
  Pada semua pagination yang diuji (default 10, 7, 1, 150, 200, 1000) tidak ada duplikat/baris hilang; bukan akibat R-2.
- **O-6 (carry r1, X-1)**: PUT non-pemegang dengan `available_queries: null` menyisakan hanya entri tersaring di DB (`available_queries` = `[verificationStatus]`); 200, tanpa 500, entri tidak hilang; bentuk bukan dikirim FE.
- Satu-satunya 500 di run ini: ED-1027 X-13 sengaja memicu "PUT isi sama di detik yang sama" = 500 `FAILED` (keputusan **A-1**, diterima, pra-ada). Tidak ada 500 pada request sah mana pun di ED-1250.
- Updater `AddVerificationStatusFilterArchive` hanya berubah docblock (kodenya tetap). Bila ada DB lain yang sudah menjalankannya dengan urutan r1, Updater tidak jalan lagi (`updater_logs`); spec menyatakan belum dirilis, `api_sidomaju` sudah dijalankan ulang developer.

## Isolasi tenant & permission

- **Tenant**: `QA_DBS=api_sidomaju` (satu DB) -> ISO-1 SKIP, tidak terverifikasi. Tinjauan kode: R-2 tidak menambah state statis (`$owned` variabel lokal metode; `hasPermission` sekali per permission per request, hanya bila baris berentri ada di halaman); php-fpm memproses tiap request terpisah.
- **Permission** (tanpa endpoint baru; route display-columns lama tanpa `permission_v5`): index/GET/PUT non-pemegang = 200 tersaring (bukan 403); entri seluruh-modul (`documentArchiveOpnameHistory`, `documentArchiveOpnameResult`) non-pemegang 403 `GE0114` / tidak ada di index; pemegang (preset OD, role 1) 2xx lengkap. QA_USER2 sungguhan (preset LIST) dicek di AC-10, AC-25.

## Tinjauan diff BE terhadap spec/kontrak (3 berkas)

- `Config/displayColumn/documentArchive.php`: current dan available memakai urutan R-1; `updatedBy` hanya di available; bentuk entri sama dengan kontrak §1 (AC-1).
- `ColumnDisplaySettingService`: `index()` memanggil `withoutHiddenItems()` pada koleksi halaman (hanya respons, baris tidak ditulis; terbukti CRC tabel tak berubah), peta sama dengan `show()/update()` (`filteredLists`), pencocokan nama
  `sameModule` (tanpa peka huruf besar/kecil + spasi akhir; X-3), entri seluruh-modul tetap dibuang oleh `hiddenModules()` sebelum paginasi. Respons PUT (controller memanggil `index`) ikut tersaring; kontrak §2 sudah menyebutnya. Tidak ada selisih
  nama field/envelope/kode antara kontrak dan respons nyata. Tanpa skema baru, tanpa kolom v3 (Archive tidak ada di v3), rollback tidak relevan (tidak ada tulis baru).
- Updater: kode tidak berubah; hasilnya = config (dibuktikan transaksi + rollback).

## Regresi (bagian BE AC-22; AC-22 sendiri milik stage fe)

| Skenario | Hasil |
|---|---|
| ED-1027 scenario1 (AC-1, X-1), scenario2 (AC-2, AC-3, AC-4), scenario9 (AC-23, X-10, X-11), scenario10 (AC-24, X-12, X-13, X-14) | 12 PASS (`work/ed1250/r2-reg-ed1027.out`, `work/qa-http/ED-1027-20261026122805.json`) |
| ED-1027 scenario9 AC-22 | FAIL **usang** (O-3, sama dengan r1): hanya `queries` pemegang OD 5 entri vs 4; dicatat, tidak diubah |
| ED-1028 scenario9 AC-28 | PASS, 219 asersi; modul seluruh-modul 403, index R0 total -2, search 3 baris, pagination 7 = 362 baris (`work/ed1250/r2-reg-ed1028.out`, `work/qa-http/ED-1028-20261026122843.json`) |

## Data uji, perubahan di luar baris sendiri, teardown

- Dibuat dan dibuang per AC: baris `archives`/`archive_documents`/`archive_locations`/`archive_permissions` berawalan `QA50-`, role `QA50-ROLE-{OD,LIST,NOLIST}` (+`role_permissions`, `user_roles`); sapu sisa `QA50-` di awal tiap AC.
  **Tidak ada baris yang bukan milik QA yang dihapus atau di-reset** (gate1 § Data uji tidak dipakai); tidak ada tabel dikosongkan.
- Diubah sementara dan dipulihkan persis (jurnal `.journal-q50-*.json`, dibuktikan di akhir tiap AC): `user_roles` QA_USER (imansudjadi, role 3) dan QA_USER2 (adi, role 5); lokasi karyawan QA_USER; baris cds `documentArchive`
  (isi di AC-11/X-1; **nama modul** diubah sementara di X-3). Dampak samping: penghitung auto-increment `roles`/`user_roles` maju.
- **Teardown C-8 PASS** (15 asersi, dijalankan di akhir run penuh dan sekali lagi sesudah regresi ED-1027/1028 dan run r1-old): baris cds `documentArchive` id **2586**, `created_at`/`updated_at` 2026-10-26 12:04:16 (sama dengan awal), isi persis sama dengan awal run;
  `current_queries` = `name,type,updatedAt,showRelatedTransaction,verificationStatus`, `available_queries` = `name,type,updatedAt,showRelatedTransaction,verificationStatus,updatedBy`, `current_columns` dan `available_columns` memuat `documentVerified`;
  `archives` nama `QA%` = 0 (QA50- = 0), `archive_documents` `QA%` = 0, `roles` `QA%` = 0, `role_permissions` `QA%` = 0; tidak ada `.*journal*` / `.fixture-journal.json` di `features/*/qa`;
  `user_roles` QA_USER/QA_USER2, bahasa, lokasi karyawan sama dengan awal; CRC `archives` (non-QA50), `archive_*`, `roles`, `role_permissions`, `user_roles`, `employees`, `employee_locations`, seluruh `column_display_settings`, `users(id,language)` sama; `is_verified=1` = 22,
  `updater_logs` 99 -> 99, 364 baris cds; token aktif QA_USER/QA_USER2 = 0 (cek DB langsung). Berkas `work/ed1250/initial-state.json` (gitignored) menyimpan keadaan awal r2; salinan r1: `initial-state-r1.json`.

## Yang tidak bisa diverifikasi (dan kenapa)

1. **Isolasi tenant** (langkah 6): `QA_DBS` hanya `api_sidomaju`; ISO-1 SKIP. Perlu dua DB tenant.
2. Halaman Setup > Display Setting FE terbuka tanpa error (bagian AC-25 yang FE): milik stage fe, bukan dicek di sini.
3. AC-12 waktu: informasi saja (±55-170 ms per panggilan), tanpa ambang di spec.

## Riwayat ronde

- Ronde 1 (2026-10-24): 12/12 AC BE PASS, 0 defect; regresi ED-1027 (12 PASS, 1 usang) dan ED-1028 AC-28 PASS; teardown C-8 PASS; pengamatan O-1..O-4.
- Ronde 2 (2026-10-26, revisi review R-1/R-2): 13/13 AC BE PASS (AC-1, AC-10, AC-11 ditulis ulang untuk urutan R-1; AC-25 baru), 0 defect; 7 asersi r1 terbalik oleh R-1; regresi ED-1027 12 PASS + 1 usang, ED-1028 AC-28 PASS; teardown C-8 PASS.
