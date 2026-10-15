---
key: ED-1070
epic: ED-1022
type: bug
title: V5 CustomizeBuilder - tutup celah keamanan + input salah bentuk di searchAll/sortAll/pagination (V4 dimatikan)
status: approved
batch: ED-1070
fe_modules: []            # FE tidak berubah; hanya regresi (golden + e2e)
new_fe_modules: []
be_modules: [Entities/Traits/CustomizeBuilder, Entities/Helper/CustomizeBuilderGuard, Http/Middleware, Routes, V4(disable)]
qa_model: opus           # menyentuh ~190 model list v5 + jalur hapus/SQL massal; regresi lintas modul
contract: null           # bentuk respons sukses & error tidak berubah (tetap 200)
depends_on: []           # butir 2 (siklus folder) sudah Done di ED-1069
---

## Ringkasan

Trait `Modules/V5/Entities/Traits/CustomizeBuilder.php` dipakai ~190 model list v5 (`searchAll` 173 panggilan/155 berkas,
`sortAll` 185/165). Ia meneruskan nama kunci `search`/`sorts` dari user ke query builder hampir tanpa saringan, dan
`pagination` dibaca mentah oleh 147 service. Akibat (temuan tim #1-#10, `brief/diskusi-tim.md`): (#1) kunci `search`
berakhiran `()` menjalankan method query builder apa pun lewat GET (`delete()`, `update()`, `truncate()`, `whereRaw()`) -
hapus/ubah massal, TRUNCATE, SQL mentah; (#2) kunci non-kolom dieksekusi sebagai method model (`latest`, `getTable`,
`truncate`); (#3) nama kunci `sum*`/identifier masuk `DB::raw` (injeksi SQL); (#4-#10) input salah bentuk = HTTP 500.
Perbaikan (desain tim, `brief/design.md` + `brief/plan.md`): satu kelas `CustomizeBuilderGuard`, entri berbahaya/salah
bentuk **dilewati + `Log::warning`**, respons tetap **200**, hanya V5. Ditambah di item ini (keputusan developer
2026-10-15): **middleware `normalize_search`** untuk `search` berbentuk array, dan **modul V4 dimatikan** (menutup salinan
trait V4 yang masih punya jalur `()`). Butir 2 Jira (folder jadi induk dirinya) sudah Done di ED-1069 - di sini hanya
regresi. Request FE yang sah tidak berubah (dibuktikan golden test). Menutup A-3 di `knowledge/decisions.md`.

Detail aturan guard ada di `brief/design.md` §5-§7; rencana implementasi di `brief/plan.md` (Task 1-7); daftar route
`normalize_pagination`/`normalize_search` untuk branch ini di `routes.md` + `routes.tsv`; daftar #9 untuk penguji di
`relation-sort-9.md`.

## Reproduksi

HTTP `api_sidomaju`, baseline BE `6fcc91fd0` (sahih juga di `0b8417778`; trait identik). Tabel contoh/hasil lengkap per
temuan ada di `brief/diskusi-tim.md` §1 (#1-#11) dan `brief/design.md` §6. List pembukti: `document-archive/archives`
(root & `id_archive`), `customer/customers`, `purchase/purchase-orders`, `sales/sales-invoices`, `item/items`,
`supplier/suppliers`; pembanding yang sudah benar (422) `document-archive/opnames` (ED-1028). Intisari:

- #1 `search={"delete()":1}` / `{"truncate()":"x"}` / `{"update()":{..}}` / `{"whereRaw()":"1=1"}` → method query builder
  **dijalankan** lewat GET (dari kode; **tidak** dijalankan terhadap data saat reproduksi/QA - proba aman §AC-2/3).
- #2 `{"latest":{"a":"b"}}`/`{"getTable":{..}}` terbukti dieksekusi lalu 500; `{"truncate":{..}}` (dari kode) = TRUNCATE.
- #3 `sum*`/identifier ke `DB::raw`. #4-#8,#10 input salah bentuk = 500 (atau 200 ukuran aneh untuk `pagination=0`/`2.5`).
- #9 sort kolom relasi FK!=PK (mis. Pelanggan "Dikelola Oleh") = 500. Butir 2 Jira (siklus folder) sudah 400 di ED-1069.

Perilaku "sesudah" setiap kasus = `brief/design.md` §6 (ringkas di tabel Perbaikan + AC-2..AC-7 di bawah).

## Akar masalah (`CustomizeBuilder.php` kecuali disebut)

- **#1** `:154-156` `Str::contains($key,'()')` → `$query->$where($search)`: nama method user tanpa allowlist.
- **#2** `:164-171` array 1 kunci → `orWhereHas(Str::camel($attribute),..)`: nama relasi user memanggil `$model->key()` tanpa cek relasi.
- **#3** `:160-161` `sum*` `DB::raw('COALESCE('.$attribute.',0)')`; `searchNumber :397,399`, `searchCondition :528-529`, `searchOwnTable :344` `DB::raw($attribute)`: identifier user ke SQL mentah.
- **#4** `:96-97` `throw ErrorMessageException('INVALID_DATA')` (`ErrorMessageException.php:32` default 500). **#5** `:103-139`(`query`), `:157-161`(`sub`/`sum` baca `$whr`/`$val` yang hanya terisi untuk array).
- **#6** `:214-215` `throw`; `:224-226` `MyHelper::toSnake`→`json_decode` atas array (`MyHelper.php:333`). **#7** service `$request->pagination` mentah ke `paginate()` (`ArchiveService.php:31`, +146 service). **#8** `searchDate :365` `[$start,$end]=$request` tanpa cek; filter relasi kolom tak dikenal.
- **#9** `:235-243` sort relasi `foreign_key=getKeyName()` (PK relasi) di kedua sisi join + hack `baseUnit`: FK induk != PK → kolom tak ada = 500/salah urut.
- **V4** salinan `()` yang sama di `Modules/V4/Entities/Traits/CustomizeBuilder.php:101-102`; 1320 route `api/v4/*` masih terdaftar.

## Perbaikan (ikuti `brief/plan.md` Task 1-7 kecuali deviasi di bawah)

| Lapisan | Perubahan | Rujukan |
|---|---|---|
| `CustomizeBuilderGuard` (baru, `Entities/Helper/`) | `isSafeIdentifier`, `isAllowedScope`, `isRelationMethod`, `relation`, `relatedHasColumn`, `isValidOperator/DateRange/NumberFilter`, `normalizeSortType`, `skip` | plan Task 1; design §5 |
| `scopeSearchAll` (`:92-209`) | #1 guard `isAllowedScope`; #2/#8 `isRelationMethod`+`relatedHasColumn`; #3 `isSafeIdentifier`; #4/#10 non-objek → abaikan+log; #5 isi skalar/array sesuai cabang | plan Task 2; design §6 |
| `scopeSearchOwnTable/Date/Number/Condition` | guard identifier raw + bentuk tanggal/angka di awal; input ditolak → `return $query` | plan Task 3 |
| `scopeSortAll` (`:211-254`) | #6 non-array/entri tanpa `sortBy` dilewati+log; `normalizeSortType`; #9 pasangan kolom dari objek `Relation`, hapus hack `baseUnit` | plan Task 4; design §7 |
| `NormalizePagination` (baru) + `Kernel.php` alias `normalize_pagination` | `pagination` bukan `/^[1-9][0-9]*$/` dibuang + log malformed; service jatuh ke default | plan Task 5 |
| **`NormalizeSearch` (baru, TAMBAHAN item ini)** + alias `normalize_search` | `search` yang bukan string (`search[]=x`, `search[a]=b`) dibuang di `query`/`request`/`json()` + log malformed, **sebelum** 147 service yang `json_decode($request->search)` sendiri (mis. `ArchiveService.php:43`). Pola sama `NormalizePagination`. `search` string tidak disentuh (validasi isi tetap di trait) | keputusan dev 3a; `routes.md` kolom S |
| Route V5 | pasang `normalize_pagination` di 262 statement (kolom P) & `normalize_search` di 584 statement (kolom S) sesuai `routes.md`/`routes.tsv` (branch ini, bukan Lampiran A tim) | plan Task 6 (diadaptasi) |
| **V4 dimatikan (TAMBAHAN item ini)** | `php7.3 artisan module:disable V4` → `Modules/V4/module.json` `active:0`. nwidart `getOrdered()=allEnabled()` (`FileRepository.php:264-281`) tidak lagi me-register `V4ServiceProvider` → route `api/v4/*` & `v4` hilang, config `v4.*`/view/migration V4 tak dimuat. Cache modul nonaktif (`config modules.cache.enabled=false`) → langsung berlaku. Trait V4 (`Modules/V4/Entities/Traits/CustomizeBuilder.php`) tidak disentuh (keputusan dev 2) | keputusan dev 2c |

**Deviasi dari plan tim (dicatat, tidak mengubah berkas tim):**
1. **Branch** `v5-opname-archive` (bukan `v5-salesman-activity`); `routes.md` menggantikan Lampiran A (202→262 P, +1 dibuang; lihat `routes.md` §beda).
2. **`normalize_search`** dan **mematikan V4** tidak ada di plan; ditambahkan oleh keputusan dev 3a/2c.
3. **Lingkungan**: tanpa docker (DB MariaDB lokal via socket, artisan `PHP_BIN`); hanya PHP 7.3 → Pint (butuh PHP ≥8.1) tak bisa jalan (lihat K-1). Commit sekali per repo sesudah QA (bukan per task, lihat K-2); gaya pesan tim `[FIX] CustomizeBuilder - ...` dengan trailer Co-Authored-By (developer 2026-10-15 08:51, mengganti "tanpa trailer").
4. **Binding `orderByRaw`** (design §7 catatan, plan Task 4 Step 1): sudah diuji di tempat (in-proc, read-only) di branch ini - `orderByRaw('(select 1) desc','desc')` **berhasil** (bukan HY093). Resolusi: **argumen kedua dibiarkan**. Dev tetap konfirmasi di mesinnya bila berbeda.
5. **Allowlist `isAllowedScope`** (dev minta cek `child_columns`): di DB & config tidak ada satu pun `data_index` berbentuk `xxx()` yang mengalir lewat `searchAll` (satu-satunya, `reportSupplier` `whereCurrentSupplierBalance()`, dibaca sendiri oleh `SupplierReportService.php:42`, lewat `selectBuilderQuery` bukan `searchAll`). Jadi allowlist **efektif kosong** untuk semua modul → `()` praktis tertutup penuh; `$searchableScopes` jadi satu-satunya pembuka. Rekomendasi: ambil dari `current_columns`+`available_columns`+`child_columns`+`grand_child_columns`+`children` (lebih aman, tanpa biaya).

## Acceptance criteria

`[BE]` = `http`, `api_sidomaju`. Data uji: hanya baris QA sendiri (prefiks `QA-`/`QA02-`, helper ED-1025), dibuang di
akhir; data developer hanya dibaca. Log diperiksa di `storage/logs/laravel-<tgl>.log`.

| AC | Given/When/Then | Tag | Cek |
|---|---|---|---|
| AC-1 | **(a) Golden regresi - request sah tak berubah.** Hasilkan request FE sistematis dari `Config/displayColumn` tiap list yang terjangkau di branch (tiap tipe filter, tiap kolom `sort:true`, sort relasi) + putar ulang `qa/baseline-valid-params.json` (59 req, 6 list). Oracle: tangkap "sebelum" di kode baseline (`0b8417778`, sebelum merge) - status, `total`, `per_page`, `current_page`, urutan `ids`, **dan** SQL (`DB::enableQueryLog`) - lalu bandingkan "sesudah" di DB yang sama. Sama persis. `guard` (count+max_updated_at tabel) berubah → list dilewati + dicatat; min. 6 list | BE | http+log |
| AC-2 | #1 jalur `()` **tertutup & aman**: `search={"delete()":1}`,`{"update()":{"is_active":9}}`,`{"truncate()":"x"}`,`{"whereRaw()":"1=1"}` pada list atas → **200**, `total` = tanpa search, tepat 1 log `[CustomizeBuilder][blocked]` per entri. **Aman**: jalankan hanya di tabel/baris QA sendiri **atau** dalam transaksi yang di-rollback; ambil DB fingerprint (count + checksum baris QA) sebelum/sesudah = identik; query log tidak memuat `delete`/`update`/`truncate`/`drop` | BE | http+log+DB |
| AC-3 | #2/#3 lain yang berbahaya: `{"latest":{"a":"b"}}`,`{"getTable":{"a":"b"}}`,`{"truncate":{"a":"b"}}` (bukan relasi) → 200 blocked; `{"sumX;DROP":[">=","1"]}` identifier tak aman → 200 blocked; fingerprint identik, query log tanpa DDL/DML | BE | http+log+DB |
| AC-4 | #4-#8/#10 salah bentuk → **200** + tepat 1 log `malformed` jenis benar, entri lain tetap jalan: `search` non-objek (`bukan-json`,`123`,`"str"`,`[1,2]`) seluruh search diabaikan; `{"query":["a"]}`,`{"subXyz":"a"}`,`{"sumXyz":"a"}`,`{"zzz":["a"]}` entri diabaikan; `{"updatedAt":["abc","def"]}`/`["x"]`, customers `{"location":{"zzz":"a"}}`; `sorts=bukan-json`/`{"name":"asc"}`/`[]`, `sorts[0]={"sortBy":"name"}`(tanpa sortType→desc)/`["x"]` | BE | http+log |
| AC-5 | **normalize_pagination**: `pagination=abc`/`-1`/`[]=1`/`[a]=1`/`0`/`2.5` → 200, `per_page` = `AppConfig::getPagination()`, 1 log malformed; `pagination=10&page=2` → `per_page`10/`current_page`2; `1000000` diterima (A-8). Uji ≥1 list + Raptor index (K-6) | BE | http+log |
| AC-6 | **normalize_search**: `search[]=x`,`search[a]=b` pada list yang `json_decode` sendiri (archives, + ≥2 service lain dari `routes.md` kolom S) → 200, search diabaikan, 1 log malformed; **tanpa 500** `json_decode() expects parameter 1 to be string` | BE | http+log |
| AC-7 | **#9 sort relasi FK!=PK**: untuk tiap baris `relation-sort-9.md`, `sorts[]={"sortBy":"<kolom>","sortType":"asc"}` & `desc` → 200 (dulu 500) + urutan sesuai join `baru` (oracle: join manual FK baru, baris QA/terbaca). Kolom sort relasi lain (FK=PK) di list sama tetap urutannya (sampel ≥1/list) | BE | http+DB |
| AC-8 | **Urutan penolakan tetap**: `archives?id_archive=<acak>&pagination=abc`/`search[]=x`/`search=bukan-json` → 404 `ARCHIVE400`; folder Izin On tanpa View + param salah → 403 `ARCHIVE407` (403/404 tetap sebelum pelewatan) | BE | http |
| AC-9 | **Karakterisasi item lain yang berbalik (dimiliki ED-1070)**: ubah ED-1025 `scenario4-select-misc.php` X-8 (search `bukan-json`/`{"query":["a"]}` dst, dulu "drift bila !=500") dan `scenario5-input-types.php` X-13 (13 varian, dulu "drift bila !=500") menjadi **asersi 200 + log**; ED-1027 `scenario9` catatan "500 lama ED-1070" (`sorts[0][sortBy]=`, bentuk array) → 200; ED-1028 catatan 500 `sorts` array → 200; ED-1071 "search JSON rusak di luar cakupan" tetap. `run.php` ED-1024/1025/1026/1027/1028/1029/1069/1071 hijau | BE | http |
| AC-10 | **V4 dimatikan**: `GET api/v4/auth/info` (dan ≥2 route v4 lain) → 404; app boot (`GET api/v5/document-archive/archives` 200); `artisan` jalan (mis. `route:list`/router one-liner tanpa error); V5 tak terpengaruh (golden AC-1 hijau); `App\Lib\lang\Status.php` yang `use` kelas V4 tidak fatal (kelas tetap ter-autoload PSR-4) | BE | http+cli |
| AC-11 | **Review Focus 1-5 (statis) + lint**: RF1 skrip pindai semua config relasi → semua lolos `isRelationMethod` (atau regex dilonggarkan); RF2 `grep` `()` data_index → hanya `reportSupplier` (dibaca service); RF3 range tanggal setengah terbuka lolos; RF4 kunci bersarang (`a.b`) lolos `isSafeIdentifier`; RF5 route multi-baris/komentar benar; `php7.3 -l` semua berkas diubah + grep larangan sintaks ≥7.4 | BE | unit+cli |
| AC-12 | **e2e** (QA_USER): Archive (root, folder, search, filter Tipe, sort Nama/Salesman, ukuran halaman), Pelanggan, Pesanan Pembelian, Faktur Penjualan (search, sort, ukuran halaman) → data tampil, tanpa error konsol/500 | FE | e2e |
| AC-13 | **Log bersih**: jendela QA, `storage/logs` tanpa error level `error` dari `CustomizeBuilder`/service list, hanya `warning` `[CustomizeBuilder][blocked|malformed]` yang diharapkan (hitung = jumlah entri uji) | BE | log |

## Keputusan untuk developer

| K | Pertanyaan & situasi | Opsi & akibat | Rek. | Risiko |
|---|---|---|---|---|
| K-1 | **Laravel Pint.** Plan tim mewajibkan Pint (format berkas baru, `--test` berkas lama). Pint butuh PHP ≥8.1; mesin ini hanya PHP 7.3 (`PHP_BIN` 7.3.33), Pint tidak terpasang. | a) lewati Pint di pipeline ini, hanya `php7.3 -l` + patuhi gaya tetangga manual (konsekuensi: diff tak dijamin lolos Pint; tim bisa jalankan `pint --test` sendiri saat review); b) developer pasang PHP 8.x global + Pint lalu agent jalankan (konsekuensi: setup lingkungan, waktu) | a; **Dijawab: a (2026-10-15)** - tanpa Pint; `php7.3 -l` + gaya kode sekitar | rendah |
| K-2 | **Granularitas commit.** Plan tim: 1 commit per task (7 commit), pesan `[FIX] CustomizeBuilder - ...`, tanpa trailer Co-Authored-By / "Generated with Claude Code". Pipeline: 1 commit per repo sesudah QA (P-2), dan reminder sesi menambahkan trailer. | a) ikut pipeline: 1 commit BE sesudah Gate 2, pesan gaya tim **tanpa** trailer (konsekuensi: menyimpang dari P-2 soal trailer + dari plan soal granularitas, tapi sesuai permintaan tim); b) 7 commit bergaya plan (konsekuensi: menyimpang dari pipeline, lebih banyak commit lokal) | a; **Dijawab: a (2026-10-15)** - satu commit BE sesudah QA, `[FIX] CustomizeBuilder - ...`; **diubah developer 2026-10-15 08:51: DENGAN trailer Co-Authored-By seperti item lain** (aturan "tanpa trailer" diganti) | rendah |
| K-3 | **Klien V4 di luar repo.** Mematikan V4 menghapus semua `api/v4/*` (1320 route). FE `ksp-react` memakai `api/v5` saja (`.env REACT_APP_API_BASEPATH=api/v5`; tanpa pemanggilan `api/v4`). Aplikasi mobile/integrasi pihak ketiga tidak bisa dipastikan dari repo. | a) matikan V4 (rek. tim, menutup jalur `()` V4; konsekuensi: klien luar yang masih pakai `api/v4` dapat 404 - tak terdeteksi dari repo); b) hanya tutup cabang `()` di trait V4, modul tetap hidup (konsekuensi: route v4 lain tetap terbuka, lingkup lebih kecil); c) biarkan V4 (konsekuensi: jalur `()` V4 tetap terbuka) | a; **Dijawab: a (2026-10-15)** - V4 dimatikan; 1320 route `api/v4` hilang | tinggi |
| K-4 | **Klien yang mengandalkan kunci yang kini diblokir.** Pindai statis: tak ada kode FE/BE V5 memakai kunci `()`, filter relasi non-relasi, atau nama `sum*` aneh. Klien luar tak terlihat. Perbaikan membuat filter itu **hilang diam-diam** (200, data tanpa filter) + log `blocked`. | a) lewati + log, pantau `[CustomizeBuilder][blocked]` seminggu pertama (rek. desain tim; konsekuensi: filter sah tak lazim bisa hilang tanpa error - terdeteksi via log); b) 422 untuk yang diblokir (konsekuensi: menyimpang dari desain "tetap 200", klien lama pecah keras) | a; **Dijawab: a (2026-10-15)** - lewati + log, pantau `[blocked]` seminggu | tinggi |
| K-5 | **#9 sort relasi FK!=PK: perbaiki sekarang atau biarkan?** 31 kolom sort (`relation-sort-9.md`) kini 500 / salah urut; FE benar-benar mengirim sebagiannya (mis. Pelanggan "Dikelola Oleh", Kurir "Karyawan Terkait"). Perbaikan mengubah **urutan hasil** pada list ini. | a) perbaiki di item ini (rek. desain §7; konsekuensi: urutan 31 kolom berubah ke benar, sort yang dulu 500 jadi jalan - perubahan perilaku disengaja, didokumentasikan); b) tiket terpisah (konsekuensi: list ini tetap 500 saat user sort kolom itu) | a; **Dijawab: a (2026-10-15)** - #9 diperbaiki di sini (design §7) | sedang |
| K-6 | **Raptor & FormRequest yang sudah memvalidasi `pagination`.** 11 route Raptor (`IndexRequest`/`SummaryDetailRequest`/`TransactionRequest`) punya rule `pagination=integer\|min:1` → `abc`/`-1` sudah **422** di sana (bukan 500). Memasang `normalize_pagination` di depannya membuat input invalid dibuang sebelum validasi → jadi **200** (pakai default), bukan 422. | a) tetap pasang (konsekuensi: perilaku Raptor `pagination` salah berubah 422→200, konsisten dengan list lain); b) kecualikan 11 route itu dari `normalize_pagination` (konsekuensi: Raptor tetap 422, tidak konsisten) | a; **Dijawab: a (2026-10-15)** | rendah |
| K-7 | **Judul & prioritas Jira.** Judul lama "V5 list - parameter search bukan JSON objek = 500; Archive - ubah folder bisa menjadikan folder induk dirinya sendiri", prioritas Medium. Lingkup kini jauh lebih besar (keamanan: hapus/ubah massal & SQL injection lewat GET). | a) ganti judul → **"V5 CustomizeBuilder hardening: tutup celah keamanan (`()`/relasi/injeksi SQL) + input salah bentuk searchAll/sortAll/pagination; matikan V4"**, prioritas **High/Highest** (konsekuensi: mencerminkan risiko keamanan); b) biarkan (konsekuensi: judul & prioritas menyesatkan) | a (High); **Dijawab: a (2026-10-15)** - judul baru + High; Jira diperbarui orkestrator | rendah |
| K-8 | **Data uji & lingkungan (Gate 1 § Data uji).** QA golden/probe akan: menulis & menghapus baris QA sendiri (prefiks `QA-`/`QA02-`) di `archives`, `customers`, `purchase_orders`, `roles`, `user_roles` (helper ED-1025); AC-2/3 butuh tabel/baris milik QA atau transaksi rollback untuk uji `()` aman; golden "sebelum" ditangkap pada kode baseline `0b8417778` di DB yang sama lalu dibandingkan "sesudah" (tak boleh ada perubahan data developer di antaranya). | a) jalankan di `api_sidomaju` (DB dev, ada backup P-4) dengan aturan "data sendiri dulu, pulihkan" (konsekuensi: aman, perlu DB stabil selama 2 tangkapan golden); b) developer sediakan DB uji khusus (konsekuensi: setup) | a; **Dijawab: a (2026-10-15)** - `api_sidomaju`, data sendiri dulu lalu pulihkan; developer tidak memakai DB selama run | rendah |

## Di luar cakupan

- Trait V4 (`Modules/V4/Entities/Traits/CustomizeBuilder.php`) tidak diubah (dinonaktifkan via modul). V3 (`Modules/*` non-V4/V5) di luar lingkup.
- Batas atas `pagination` (A-8, diterima). `search=[1,2]` tetap "tanpa filter" (bukan 422). `"ASC"`/sortType selain `asc` → `desc` (tidak 422).
- Tidak ada perubahan permission, kode pesan, Updater, atau skema (hanya `module.json` V4). Respons sukses & error tetap (tidak ada `contract.md`).
- A-1, A-2 (route v4 display-columns - hilang bersama V4), A-4 (latin1 = ED-1071). Butir 2 Jira = ED-1069 (Done).
- ED-1124 (data `showRelatedTransaction`): di sini hanya bentuk parameter, tanpa `depends_on`.

## Catatan implementasi

BE dev 2026-10-15 (menyimpang/menambah dari plan tim; alasan utama: AC-1 SQL request FE harus identik):
1. **Tanpa cache statis** di `CustomizeBuilderGuard` (`connections.md` §5 (d): static mutable baru dilarang); `isRelationMethod`/`relatedHasColumn` dihitung ulang tiap panggilan.
2. **`relatedHasColumn`**: pasangan `[relasi, kolom]` yang tercantum di config `displayColumn` (sumber filter FE) diterima tanpa query skema; selain itu `getColumnListing`. Dengan `getColumnListing` murni, request FE filter relasi (mis. `receivablePaymentDetail`) mendapat query `information_schema` tambahan = DIFF golden.
3. **`scopeSearchNumber`/`scopeSearchDate`**: `$attribute` searchNumber tidak dicek `isSafeIdentifier` (scope Customer/Supplier/GlTransaction + `SupplierReportService.php:44` mengirim ekspresi SQL dari kode); nama kolom dari user sudah disaring di `scopeSearchOwnTable`. Nilai **skalar** tetap perilaku lama (golden FE: `currencyCode:"IDR"` -> `currency_code = 'I'`, `totalQty:"a"`, `expiredDate:"2026-01-02 08:00:00"`), kecuali `''` (dulu 500) -> malformed. Nilai array divalidasi ketat sesuai desain (`[null,null]` -> malformed).
4. **Sort relasi**: relasi yang gagal `isRelationMethod` atau bertipe lain (termasuk MorphTo/MorphOne/MorphMany) memakai pasangan kolom lama (tidak dilewati), supaya SQL tidak berubah. Entri sort bentuk array (`sorts[0][sortBy]=`) jadi `sort_by` oleh `req_snake` -> malformed (200 + log).
5. **`isAllowedScope`**: allowlist dari seluruh config modul (termasuk `current/available_queries`, tempat satu-satunya `whereCurrentSupplierBalance()`), denylist tidak peka huruf. `Str::replace` (tidak ada di Laravel 5.7) diganti `str_replace`: di branch ini jalur `()` dulu 500 BadMethodCall sebelum method dijalankan.
6. **`isRelationMethod`**: prefiks `scope|get|set|boot` hanya ditolak bila diikuti huruf besar/`_`/akhir nama (relasi seperti `settlement` tidak ikut gugur).
7. **Middleware**: `normalize_pagination`/`normalize_search` menormalkan request route **dan** request di container. `ChecknSet` (FeatureRouteMapping, lisensi SALESMAN_ACTIVITY) mem-proxy `customer/customers` dan `employee/employees` ke route salesman, tetapi controller menerima request asli: tanpa ini `customer/customers?pagination=abc` tetap 500. `search` null tidak disentuh.
8. Kecil: `search` kosong (`{}`/`[]`) tanpa log; kunci non-string di objek search -> malformed per kunci; `{"query":null}` dan nilai filter relasi null tetap perilaku lama; metode tambahan guard `isScalarOrScalarList`.
9. `Modules/V4/module.json`: hanya `"active": 0` (keluaran `module:disable` juga mengubah `"aliases": {}` -> `[]` + newline akhir; dikembalikan supaya diff minimal, efeknya sama).

BE dev 2026-10-15 ronde perbaikan 1 (D-1, D-2, D-3 di `qa-be.md`):
10. **D-3, URL unduhan berkas pindah ke V5.** Nama route `select.files.download` hanya didaftarkan V4 (`Modules/V4/Routes/Select/select.php:1242`); 13 pemanggil di `Modules/V5` (12 PHP + email OTP `salesman-activity/email-template/otp-request.blade.php`, yang `{{ route() }}`-nya tetap dijalankan Blade meski di dalam komentar HTML) kini memakai `v5.select.files.download`. **URL yang dikembalikan ke klien berubah** dari `/api/v4/select/files/download?...` menjadi `/api/v5/select/files/download?...` (logo perusahaan `logo_small_url` dan `Company::info()->logo_url` untuk PDF, foto/thumbnail barang, `main_photo`, foto KTP kontak pelanggan, foto check-in absensi, lampiran Raptor, thumbnail item request). Kesetaraan dicek: grup middleware sama (`api, req_snake, res_camel, cns`, tanpa `auth:api`), parameter sama (`idAttachment`, `transactionType`/`transaction_type`), controller identik kecuali namespace; service V5 = superset V4 (+3 tipe dokumen) dan, bila disk mendukung `temporaryUrl` (S3), menjawab redirect 302 ke URL bertanda tangan 5 menit alih-alih stream; disk `local` (default mesin ini) = respons sama (`Storage::download`). FE `getAttachment` sudah memakai `select/files/download` di basis `api/v5`; `CheckMobileAppVersion` sudah mengecualikan `/api/v5/select/files/download`. Sapuan seluruh repo (tanpa `vendor/`, `Modules/V4/`): tidak ada dependensi lain pada yang hanya didaftarkan V4 (route bernama lain, `config('v4.`, view/lang `v4::`, `api/v4` tertulis); modul v3 tidak bergantung. Kelas `Modules\V4\...` di `app/Lib/lang/Status.php` dan `app/Http/Middleware/v4/*` hanya lewat autoload.
11. **D-1, model relasi dibuat sekali.** Sort relasi memakai ulang model relasi dari objek `Relation` (`getRelated()->newQuery()`) bila kelasnya sama dengan model hasil eager-load; selain itu `$relation['model']::select()` seperti dulu. `relatedHasColumn` mengecek pasangan config **sebelum** membuat objek relasi, sehingga filter relasi FE tidak menambah konstruksi model (konstruktor `Account` membaca `column_display_settings`). Pasangan di luar config (bukan dari FE) tetap membuat model relasi dua kali (cek skema guard + `whereHas`); jalur itu memang sudah menambah query skema (catatan 2).
12. **D-2, "mencari" di list Archive** = `type` terisi (disaring `ArchiveService`) atau `CustomizeBuilderGuard::searchMayApply()` (tanpa log; cermin cabang `scopeSearchAll`/`scopeSearchOwnTable`, ubah bersama). Search yang hanya berisi entri yang pasti tidak dipakai trait (diblokir, salah bentuk, nilai `null`, list `[1,2]`, `type` kosong) = seperti tanpa search: root/isi folder, dan folder Izin On tanpa View → 403 `ARCHIVE407` seperti tanpa search. Log tetap ditulis trait (1 per entri). Entri yang efeknya bergantung pada data (kunci skalar tak dikenal `{"zzz":"a"}`, tanpa log) tetap dianggap mencari (perilaku lama). Tanpa query untuk filter FE: kunci yang hasilnya bergantung pada kolom tabel dianggap dipakai bila ada di config `displayColumn` modul; selain itu (request non-FE) skema tabel dibaca sekali, hanya bila belum ada entri lain yang mungkin dipakai. Teks non-latin1 (ED-1071) tetap total 0.
