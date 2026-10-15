# Pengerasan `CustomizeBuilder` V5: rencana implementasi

> **Untuk pelaksana (manusia atau agent AI apa pun):**
> - Baca spec terlebih dahulu, lalu kerjakan task **berurutan**, karena Task 2–6 bergantung pada interface Task 1.
> - Tandai progres dengan checkbox `- [ ]` menjadi `- [x]`.
> - Setiap task diakhiri commit sendiri, supaya bisa direview atau di-revert per task.
> - Bila sebuah langkah tidak bisa dijalankan di lingkungan Anda (misalnya tidak ada docker), berhenti dan laporkan.
>   Jangan menebak hasilnya.

**Goal:** Menutup celah keamanan dan bug input salah bentuk di `searchAll`/`sortAll` V5 serta `pagination`, tanpa
mengubah hasil request yang sah.

**Architecture:**
- Semua aturan saringan dikumpulkan di kelas statis baru `CustomizeBuilderGuard`.
- Trait `CustomizeBuilder` memanggil guard di setiap cabang. Entri yang ditolak dilewati dan dicatat dengan
  `Log::warning`.
- `pagination` dinormalkan oleh middleware `normalize_pagination` yang dipasang per route list.

**Tech Stack:** Laravel 5.7.19, PHP 7.3, MySQL/MariaDB (PDO emulated prepares), Laravel Pint (tool eksternal).

**Spec:** `design.md` (direktori yang sama dengan plan ini)

**Sumber temuan:** `diskusi-tim.md` (direktori yang sama, ED-1070)

## Global Constraints

- **Lingkup:** hanya V5. `Modules/V4/**` tidak disentuh.
- **Kode wajib kompatibel PHP 7.3:** tanpa arrow function `fn`, typed property, `match`, nullsafe `?->`, atau named
  argument. Untuk mendeteksinya, `php -l` wajib dijalankan dengan `php7.3`; host default `php` adalah 8.5.
- **Artisan:** selalu `php7.3 artisan ...` di host. Perintah yang butuh DB dijalankan lewat
  `docker exec -i ksp-erp-salesman php artisan ...`, karena `db-mariadb` hanya ter-resolve dari dalam container.
- **Penolakan:** input berbahaya atau salah bentuk **dilewati**, respons tetap 200, dan setiap pembuangan dicatat
  `Log::warning("[CustomizeBuilder][blocked|malformed] ...")`. Jangan melempar exception baru.
- **Pengujian:** tidak ada test di plan ini (ada dokumen terpisah). Verifikasi tiap task:
  - `php7.3 -l` untuk setiap berkas yang disentuh;
  - Pint: `pint <file>` diterapkan pada **berkas baru**; `pint --test <file>` pada **berkas lama** hanya dilaporkan
    (bukan gerbang), supaya tidak terjadi format ulang seluruh berkas.
- **Pint:** belum terpasang. Pasang sekali di luar proyek: `composer global require laravel/pint:^1.18` (dengan
  PHP host 8.5). Binary di `~/.config/composer/vendor/bin/pint`. **Jangan** ditambahkan ke `composer.json` proyek.
- **Commit:** tanpa trailer `Co-Authored-By` Claude dan tanpa baris "Generated with Claude Code". Format pesan mengikuti
  repo, misalnya `[FIX] CustomizeBuilder - ...`.
- **Branch:** kerjakan di branch baru dari `v5-salesman-activity`, misalnya `v5-customizebuilder-hardening`. Jangan ikut
  meng-commit berkas yang tidak terkait (`ApplicationSetupService.php`, `PERBAIKAN-*.md`, `TEMUAN-*.md`, `.claude/`, serta direktori
  dokumen ini (`design.md`, `plan.md`, `diskusi-tim.md`), karena dokumen tidak ikut di-commit).

## Review Focus

Input yang paling mungkin merusak request sah tanpa ketahuan, karena guard melewatinya secara diam-diam. Plan ini tidak
memuat test, jadi tiap baris di bawah punya **langkah cek statis** di task pemiliknya, dan wajib masuk ke dokumen test
terpisah:

1. **Relasi sah yang gagal `isRelationMethod`.** Penyebabnya bisa format `return\n $this\n ->hasMany(` lintas baris,
   atau relasi yang disimpan dulu ke variabel. Akibatnya filter dan sort relasi diam-diam hilang. Cek: Task 1 Step 4.
2. **`data_index` `xxx()` sah yang gagal `isAllowedScope`**, karena `moduleName` tidak diset di service tersebut.
   Akibatnya filter diam-diam hilang. Cek: Task 2 Step 3.
3. **Rentang tanggal setengah terbuka** `[null, "2026-01-01"]` / `["2026-01-01", null]` harus tetap lolos
   `isValidDateRange`. Cek: Task 1 Step 3.
4. **Kunci bersarang dari FE** (`purchaseOrders.idLocation`, `customer_type.customer_type_name`) harus lolos
   `isSafeIdentifier` setelah `Str::snake`. Cek: Task 1 Step 3.
5. **Statement route multi-baris** (`Route::get(...)\n->name(...)`) dan route berkomentar (`]); //->middleware(...)`):
   middleware harus terpasang sebelum `;` penutup statement, bukan di dalam komentar. Cek: Task 6 Step 2.

---

### Task 1: `CustomizeBuilderGuard`

**Files:**
- Create: `Modules/V5/Entities/Helper/CustomizeBuilderGuard.php`

**Interfaces:**
- Produces (semua `public static`, namespace `Modules\V5\Entities\Helper`):
  - `isSafeIdentifier($name): bool`
  - `isAllowedScope(\Illuminate\Database\Eloquent\Model $model, string $name, ?string $moduleName): bool`. `$name`
    tanpa `()`.
  - `isRelationMethod(\Illuminate\Database\Eloquent\Model $model, string $name): bool`
  - `relation(\Illuminate\Database\Eloquent\Model $model, string $name): ?\Illuminate\Database\Eloquent\Relations\Relation`.
    Mengembalikan `null` bila `isRelationMethod` gagal; selain itu memanggil method lewat `Relation::noConstraints`.
  - `relatedHasColumn(\Illuminate\Database\Eloquent\Model $model, string $relation, string $column): bool`
  - `isValidOperator($op): bool`
  - `isValidDateRange($value): bool`
  - `isValidNumberFilter($value): bool`
  - `normalizeSortType($type): string`, yang mengembalikan `'asc'` atau `'desc'`
  - `skip(string $kind, $model, string $key, string $reason, $value = null): void`, dengan `$kind` berisi `'blocked'`
    atau `'malformed'`
  - Konstanta: `SCOPE_DENYLIST`, `RELATION_METHODS`, `OPERATORS` (nilai di Step 1)

- [ ] **Step 1: Buat kelas dengan konstanta dan signature di atas**

  Nilai dari spec:

  ```php
  const SCOPE_DENYLIST = ['selectFormat', 'dependsOn', 'selectFormatJSON', 'searchAll', 'sortAll',
      'selectBuilderQuery', 'exclude', 'searchOwnTable', 'searchDate', 'searchNumber', 'whereLocation', 'searchCondition'];
  const RELATION_METHODS = ['hasOne', 'hasMany', 'belongsTo', 'belongsToMany', 'hasOneThrough', 'hasManyThrough',
      'morphTo', 'morphOne', 'morphMany', 'morphToMany', 'morphedByMany'];
  const OPERATORS = ['=', '<>', '!=', '>', '>=', '<', '<=', 'between'];
  ```

  Aturan setiap method mengikuti spec §5.1–§5.6 secara harfiah. Keputusan yang tidak ditentukan oleh signature:

  - **`isSafeIdentifier`**: `is_string($name) && preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*$/', $name)`.
  - **`isAllowedScope`**: allowlist diambil dari `config('v5.displayColumn.' . $moduleName . '.current_columns', [])`,
    termasuk `children`, dengan membandingkan `data_index === $name . '()'`. Ditambah
    `property_exists($model, 'searchableScopes') ? $model->searchableScopes : []`. Dilarang memakai
    `MyHelper::getDisplaySetting` (sumbernya DB, bisa diubah user).
  - **`isRelationMethod`**: memakai `ReflectionMethod`. Source dibaca dari `getFileName()`, baris `getStartLine()` sampai
    `getEndLine()`, lalu dicocokkan dengan regex lintas baris:

    ```
    /return\s+\$this\s*->\s*(hasOne|hasMany|belongsTo|belongsToMany|hasOneThrough|hasManyThrough|morphTo|morphOne|morphMany|morphToMany|morphedByMany)\s*\(/
    ```

    Hasilnya di-cache dalam `private static $relationCache = []` dengan key `get_class($model) . '::' . $name`.
  - **`relatedHasColumn`**: memakai `relation()`, lalu `getRelated()`, lalu
    `getConnection()->getSchemaBuilder()->getColumnListing($table)`. Hasil di-cache per nama tabel, dan dibandingkan
    dengan `Str::snake($column)`.
  - **`isValidDateRange`**:
    - wajib `is_array`, `count === 2`, dan memiliki key `0` dan `1`;
    - tiap elemen `null` atau string yang `Carbon::parse` tanpa exception;
    - tidak boleh keduanya `null`.
  - **`isValidNumberFilter`**:
    - wajib `is_array` dan `isValidOperator($v[0] ?? null)`;
    - untuk `between`: `is_numeric($v[1] ?? null) && is_numeric($v[2] ?? null)`;
    - selain itu: `is_numeric($v[1] ?? null)`.
  - **`skip`**: isi konteks log persis seperti spec §5.6. Konteks `model` diisi `is_object($model) ? get_class($model) : (string) $model`.

- [ ] **Step 2: Lint dan Pint**

  Run: `php7.3 -l Modules/V5/Entities/Helper/CustomizeBuilderGuard.php && ~/.config/composer/vendor/bin/pint Modules/V5/Entities/Helper/CustomizeBuilderGuard.php`

  Expected: `No syntax errors detected`, lalu Pint selesai tanpa error.

- [ ] **Step 3: Cek nilai dari Review Focus #3 dan #4 (tanpa DB)**

  Run:

  ```bash
  php7.3 artisan tinker --execute='use Modules\V5\Entities\Helper\CustomizeBuilderGuard as G; var_dump(G::isSafeIdentifier("purchase_orders.id_location"), G::isSafeIdentifier("customer_type.customer_type_name"), G::isSafeIdentifier("sum_balance"), G::isSafeIdentifier("x)"), G::isSafeIdentifier("x/**/y"), G::isSafeIdentifier("a."), G::isValidDateRange([null,"2026-01-01"]), G::isValidDateRange(["2026-01-01",null]), G::isValidDateRange([null,null]), G::isValidDateRange(["2026-01-01"]), G::isValidDateRange(["abc","def"]), G::normalizeSortType("ASC"), G::normalizeSortType("a_s_c"), G::normalizeSortType("desc,(select 1)"));'
  ```

  Expected urutan: `true, true, true, false, false, false, true, true, false, false, false, "asc", "desc", "desc"`.

  Bila versi tinker tidak mendukung `--execute`, jalankan isi yang sama lewat `php7.3 artisan tinker` secara interaktif.
  Perintah ini cek sekali jalan, bukan berkas test.

- [ ] **Step 4: Cek Review Focus #1: semua relasi yang dipakai config lolos `isRelationMethod`**

  Jalankan skrip sekali pakai di direktori sementara di luar repo (jangan di-commit). Skrip membaca semua `Modules/V5/Config/displayColumn/**/*.php`.
  Untuk setiap `data_index` berbentuk array `[relasi, kolom]` (termasuk `children`), skrip mencari model V5 yang
  memiliki method `relasi`, lalu mencetak pasangan model dan relasi yang **gagal** `isRelationMethod`.

  Expected: daftar kosong. Bila ada yang gagal karena format kode relasi, jangan ubah modelnya. Perlonggar regex
  `isRelationMethod` secukupnya, lalu ulangi Step 2–4.

- [ ] **Step 5: Commit**

  ```bash
  git add Modules/V5/Entities/Helper/CustomizeBuilderGuard.php
  git commit -m "[FIX] CustomizeBuilder - tambah CustomizeBuilderGuard (aturan saringan search/sort)"
  ```

### Task 2: `scopeSearchAll` (#1, #2, #3 `sum*`, #4, #5, #8 kolom relasi, #10, `limit`)

**Files:**
- Modify: `Modules/V5/Entities/Traits/CustomizeBuilder.php:92-209`

**Interfaces:**
- Consumes: `CustomizeBuilderGuard` (Task 1).
- Produces: signature `scopeSearchAll($query, $request, $exceptKeys = [])` tidak berubah.

- [ ] **Step 1: Ubah cabang-cabang `scopeSearchAll` sesuai spec §6**

  | Lokasi sekarang | Perubahan |
  |---|---|
  | `:95-98` | Bila hasil decode bukan array, **atau** array tanpa satu pun kunci string (`search=[1,2]`): `skip('malformed', $this, 'search', 'search is not a JSON object', $request)` lalu `return $query`. Ganti `throw` yang ada. |
  | `:103` cabang `query` | Bila `!is_scalar($search)`: `skip('malformed', ...)` lalu `continue`. |
  | `:154-156` cabang `()` | `$name = Str::replace('()', '', $key)`. Bila `!isAllowedScope($this, $name, $this->moduleName)`: `skip('blocked', ...)` lalu `continue`. Selain itu tetap `$query->$name($search)`. |
  | `:157-159` cabang `sub*` | Wajib `is_array($search) && !empty($search) && is_string($whr[0]) && isSafeIdentifier(Str::snake($whr[0])) && is_scalar($val[0])`. Kalau gagal: `skip('malformed', ...)` lalu `continue`. |
  | `:160-161` cabang `sum*` | Bila `!isSafeIdentifier($attribute)`: `skip('blocked', ...)` lalu `continue`. Bila `!isValidNumberFilter($search)`: `skip('malformed', ...)` lalu `continue`. |
  | `:162-163` cabang `limit` | Bila bukan bilangan bulat ≥ 1 (`/^[1-9][0-9]*$/` untuk string, atau int ≥ 1): `skip('malformed', ...)` lalu `continue`. |
  | `:164-171` cabang relasi satu-kunci | Bila `!isRelationMethod($this, Str::camel($attribute))`: `skip('blocked', ...)` lalu `continue`. Bila `!is_scalar($val[0])` atau `!relatedHasColumn($this, Str::camel($attribute), $key[0])`: `skip('malformed', ...)` lalu `continue`. |
  | `:191-198` cabang `whereHas($key, ...)` | Untuk setiap `$_key => $_val`: lewati entri (log `malformed`) bila `!is_scalar($_val)` atau `!relatedHasColumn($this, $key, $_key)`. |

  Gunakan **satu** `$this->moduleName` (properti trait yang sudah ada). Jangan menambah properti baru.

- [ ] **Step 2: Lint dan Pint**

  Run: `php7.3 -l Modules/V5/Entities/Traits/CustomizeBuilder.php && ~/.config/composer/vendor/bin/pint --test Modules/V5/Entities/Traits/CustomizeBuilder.php`

  Expected: `No syntax errors detected`. Output `pint --test` dicatat di pesan commit atau laporan, tapi tidak memblokir.

- [ ] **Step 3: Cek Review Focus #2: tidak ada pemakai `xxx()` yang bergantung pada `searchAll` tanpa `moduleName`**

  Run: `grep -rnE "'data_index' => '[A-Za-z_]+\(\)'" Modules/V5/Config/displayColumn`

  Untuk setiap hasil, buka service yang memakai modul itu dan pastikan salah satu kondisi berikut benar:
  - service memanggil `selectBuilderQuery('<modul>')` **sebelum** `searchAll`;
  - service membaca kunci tersebut sendiri (seperti `SupplierReportService.php:42`).

  Expected per 2026-10-15: hanya `reportSupplier.php:205,282` (`whereCurrentSupplierBalance()`), yang dibaca sendiri oleh
  service. Bila ditemukan kasus lain yang tidak memenuhi kedua kondisi, tambahkan scope-nya ke `$searchableScopes`
  milik model terkait.

- [ ] **Step 4: Commit**

  ```bash
  git add Modules/V5/Entities/Traits/CustomizeBuilder.php
  git commit -m "[FIX] CustomizeBuilder - saring kunci search (scope, relasi, sum/sub, limit) dan lewati bentuk salah"
  ```

### Task 3: Scope filter tabel sendiri (#3 identifier raw, #8 tanggal/angka)

**Files:**
- Modify: `Modules/V5/Entities/Traits/CustomizeBuilder.php`. Bagian yang diubah: `scopeSearchOwnTable` (`:315-352`),
  `scopeSearchDate` (`:363-383`), `scopeSearchNumber` (`:391-401`), `scopeSearchCondition` (`:523-532`).

**Interfaces:**
- Consumes: Task 1.
- Produces: signature keempat scope tidak berubah. Saat input ditolak, scope mengembalikan `$query` tanpa perubahan.

- [ ] **Step 1: Pasang guard di awal tiap scope**

  | Scope | Guard |
  |---|---|
  | `scopeSearchOwnTable` | Bila `!isSafeIdentifier($attribute)`: `skip('blocked', ...)` lalu `return $query`. Cabang operator (`:343-344`) juga wajib lolos `isValidNumberFilter($request)`; kalau gagal, `skip('malformed', ...)`. |
  | `scopeSearchDate` | Bila `!isValidDateRange($request)`: `skip('malformed', ...)` lalu `return $query`. |
  | `scopeSearchNumber` | Bila `!isSafeIdentifier($attribute)`: log `blocked`. Bila `!isValidNumberFilter($request)`: log `malformed`. Keduanya lalu `return $query`. |
  | `scopeSearchCondition` | Bila `!isSafeIdentifier($attribute) || !isSafeIdentifier($condition)`: log `blocked`. Bila `!is_array($request)` atau `!is_scalar($request[0] ?? null)`: log `malformed`. Keduanya lalu `return $query`. |

  Selain itu, di cabang `approval_status` dan `where/whereIn` di `scopeSearchOwnTable`: bila `$request` bukan skalar dan
  bukan array berisi skalar, log `malformed` lalu `return $query`.

- [ ] **Step 2: Lint dan Pint**

  Perintah dan Expected sama dengan Task 2 Step 2.

- [ ] **Step 3: Commit**

  ```bash
  git add Modules/V5/Entities/Traits/CustomizeBuilder.php
  git commit -m "[FIX] CustomizeBuilder - validasi identifier raw dan bentuk filter tanggal/angka"
  ```

### Task 4: `scopeSortAll` (#6, `sortType` raw, #9)

**Files:**
- Modify: `Modules/V5/Entities/Traits/CustomizeBuilder.php:211-254`

**Interfaces:**
- Consumes: `normalizeSortType`, `relation`, dan `skip` dari Task 1.
- Produces: signature `scopeSortAll($query, $request)` tidak berubah.

- [ ] **Step 1: Putuskan nasib binding `orderByRaw` (spec §7, catatan binding)**

  Run (di mesin yang memiliki docker):

  ```bash
  docker exec -i ksp-erp-salesman php artisan tinker <<'EOF'
  try { echo json_encode(DB::table('customers')->select('id_customer')->orderByRaw('(select 1) desc', 'desc')->limit(1)->get()); } catch (\Throwable $e) { echo get_class($e), ': ', substr($e->getMessage(), 0, 200); }
  EOF
  ```

  - Bila output mengandung `HY093`: hapus argumen kedua `orderByRaw` di Step 2.
  - Bila output berupa JSON: argumen kedua dibiarkan.

  Catat hasilnya di pesan commit.

- [ ] **Step 2: Ubah `scopeSortAll`**

  - **`:214-216`:** bila `!is_array($request)`, ganti `throw` menjadi `skip('malformed', $this, 'sorts', 'sorts is not an array', $request)` lalu `return $query`.
  - **Per entri `$sort`:** bila hasil decode bukan array atau tidak memiliki `sortBy` string, log `malformed` lalu `continue`.
    `sortType` dihitung lewat `normalizeSortType(MyHelper::toSnake($sort, 'sortType'))`. Bila `sortType` tidak ada,
    nilainya `desc`.
  - **Cabang relasi (`:231-248`):**
    - `$rel = CustomizeBuilderGuard::relation($this, $relation['relation_name'])`. Bila `null`, `continue` tanpa log
      (spec §5.6: `sortBy` yang tidak cocok tidak dicatat).
    - `BelongsTo`: subquery `related.{getOwnerKey()}` = `{$tableName}.{getForeignKey()}`.
    - `HasOne`/`HasMany` (`HasOneOrMany`): `related.{getForeignKeyName()}` = `{$tableName}.{getLocalKeyName()}`.
    - Tipe lain: tetap memakai pasangan lama (`$relation['foreign_key']` di kedua sisi).
    - Hapus blok `if ($relation['relation_name'] == 'baseUnit')`.
    - `$sortType` yang masuk `orderByRaw` adalah hasil `normalizeSortType`.

- [ ] **Step 3: Lint dan Pint**

  Perintah dan Expected sama dengan Task 2 Step 2.

- [ ] **Step 4: Commit**

  ```bash
  git add Modules/V5/Entities/Traits/CustomizeBuilder.php
  git commit -m "[FIX] CustomizeBuilder - sort relasi pakai kunci Relation, normalisasi sortType, lewati sorts salah bentuk"
  ```

### Task 5: Middleware `NormalizePagination`

**Files:**
- Create: `Modules/V5/Http/Middleware/NormalizePagination.php`
- Modify: `app/Http/Kernel.php:84-88` (blok `//v5` di `$routeMiddleware`)

**Interfaces:**
- Consumes: `CustomizeBuilderGuard::skip` (Task 1). Nilai `$model` diisi string `'pagination'`.
- Produces: alias route middleware `normalize_pagination`.

- [ ] **Step 1: Buat `handle($request, \Closure $next)`**

  Penentuan nilai valid:
  - Bila `pagination` tidak ada di request (`!$request->has('pagination')`) atau bernilai `null`, request diteruskan
    tanpa perubahan.
  - Nilai valid hanya int ≥ 1 atau string yang cocok `/^[1-9][0-9]*$/`.

  Selain nilai valid:
  - hapus dari `$request->query`, `$request->request`, dan dari `$request->json()` bila `$request->isJson()`;
  - panggil `CustomizeBuilderGuard::skip('malformed', 'pagination', 'pagination', 'pagination is not a positive integer', <nilai>)`.

- [ ] **Step 2: Daftarkan alias**

  Tambahkan `'normalize_pagination' => \Modules\V5\Http\Middleware\NormalizePagination::class,` di blok `//v5`
  pada `app/Http/Kernel.php`.

- [ ] **Step 3: Lint dan Pint**

  Run: `php7.3 -l Modules/V5/Http/Middleware/NormalizePagination.php && php7.3 -l app/Http/Kernel.php && ~/.config/composer/vendor/bin/pint Modules/V5/Http/Middleware/NormalizePagination.php && ~/.config/composer/vendor/bin/pint --test app/Http/Kernel.php`

  Expected: dua kali `No syntax errors detected`, Pint pada berkas baru selesai, dan output `--test` Kernel dicatat.

- [ ] **Step 4: Commit**

  ```bash
  git add Modules/V5/Http/Middleware/NormalizePagination.php app/Http/Kernel.php
  git commit -m "[FIX] V5 - middleware normalize_pagination untuk pagination tidak valid"
  ```

### Task 6: Pasang `normalize_pagination` di 202 route (`design.md` Lampiran A)

**Files:**
- Modify: 58 berkas di `Modules/V5/Routes/**`, sesuai kolom "Berkas route" dan "Baris" di Lampiran A spec.

**Interfaces:**
- Consumes: alias `normalize_pagination` (Task 5).

- [ ] **Step 1: Tambahkan middleware pada setiap statement route di Lampiran A**

  Aturannya:
  - Sisipkan `->middleware('normalize_pagination')` tepat sebelum `;` penutup **statement** yang dimulai di baris
    tercantum. Statement bisa berlanjut ke baris berikutnya, misalnya `->name(...)`.
  - Bila ada komentar `// ...` setelah `)`, sisipan diletakkan sebelum `;` kode, bukan di dalam komentar.
  - Bila statement sudah memiliki `->middleware('permission_v5:...')`, tambahkan sebagai pemanggilan terpisah setelahnya.
    Jangan mengubah string permission.
  - Kerjakan dari baris terbawah ke atas di tiap berkas, supaya nomor baris Lampiran A tetap valid selama proses edit.

- [ ] **Step 2: Verifikasi jumlah dan sintaks (Review Focus #5)**

  Run:

  ```bash
  grep -rc "normalize_pagination" Modules/V5/Routes | awk -F: '{s+=$2} END {print s}'
  for f in $(grep -rl "normalize_pagination" Modules/V5/Routes); do php7.3 -l "$f" | grep -v "^No syntax"; done
  grep -rnE "//.*normalize_pagination" Modules/V5/Routes
  php7.3 artisan route:list --middleware=normalize_pagination | grep -c "normalize_pagination"
  ```

  Expected:
  - baris 1 mencetak `202`;
  - baris 2 tidak mengeluarkan apa pun;
  - baris 3 tidak mengeluarkan apa pun;
  - baris 4 mencetak jumlah route URI unik yang terpasang. Angkanya bisa lebih kecil dari 202 bila ada URI yang sama
    didaftarkan dua kali, tapi tidak boleh `0`.

  Bila `route:list` tidak memiliki opsi `--middleware` di 5.7, ganti baris 4 dengan
  `php7.3 artisan route:list | grep -c normalize_pagination`.

- [ ] **Step 3: Commit**

  ```bash
  git add Modules/V5/Routes
  git commit -m "[FIX] V5 - pasang normalize_pagination di route list"
  ```

### Task 7: Pemeriksaan akhir

**Files:** tidak ada perubahan baru.

- [ ] **Step 1: Lint semua berkas yang berubah di branch**

  Run: `git diff --name-only v5-salesman-activity...HEAD -- '*.php' | xargs -n1 php7.3 -l | grep -v "^No syntax"`

  Expected: tidak ada output.

- [ ] **Step 2: Cek larangan sintaks PHP ≥ 7.4**

  Run: `git diff v5-salesman-activity...HEAD -- '*.php' | grep -nE "^\+.*(\bfn\s*\(|\?->|\bmatch\s*\(|(private|protected|public)\s+\??[A-Za-z\\\\]+\s+\\$)"`

  Expected: tidak ada output.

- [ ] **Step 3: Cek bahwa tidak ada `throw` tersisa di jalur search/sort**

  Run: `grep -n "throw new ErrorMessageException('INVALID_DATA'" Modules/V5/Entities/Traits/CustomizeBuilder.php`

  Expected: tidak ada output.

- [ ] **Step 4: Ringkas hasil**

  Laporkan hal berikut ke user:
  - output `pint --test` untuk berkas lama;
  - keputusan binding dari Task 4 Step 1;
  - daftar butir Review Focus beserta hasil cek statisnya, sebagai masukan untuk dokumen test terpisah.
