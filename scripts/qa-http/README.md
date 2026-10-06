# Runner QA HTTP

Menjalankan acceptance criteria `[BE]` sebuah fitur terhadap server BE (`API_URL`) dan membaca hasilnya di
database (`BE_DIR`, read-only). Skenario ditulis QA per fitur, permanen sebagai regresi.

```bash
# dari agentic/ (Git Bash). PHP = $PHP_BIN dari config/workspace.env (php-nts 7.3.33, sama dengan worker RR).
source scripts/lib/env.sh
"$PHP_BIN" scripts/qa-http/run.php ED-1234                    # semua AC di features/ED-1234-*/qa/scenario*.php
"$PHP_BIN" scripts/qa-http/run.php features/ED-1234-slug      # atau path folder fitur
"$PHP_BIN" scripts/qa-http/run.php ED-1234 --only=AC-1,AC-6   # sebagian
"$PHP_BIN" scripts/qa-http/run.php ED-1234 --list             # daftar AC; tanpa login, tanpa jaringan
```

Keluaran: tabel di stdout dan `work/qa-http/<KEY>-<YmdHis>.json` (id, judul, status PASS/FAIL/SKIP/BLOCKED,
durasi, pesan; untuk FAIL/BLOCKED juga request terakhir dan potongan body maks 2 KB). Exit `0` = tanpa FAIL,
`1` = ada FAIL, `2` = config/infra error (server mati, login QA gagal, berkas skenario salah).

**Aturan RR:** RoadRunner memuat kode PHP sekali per worker. Sesudah mengubah PHP di `BE_DIR`, reload dulu
(`scripts/be-reload.sh`: RoadRunner di-reload bila `BE_SERVER=rr`; php-fpm tidak perlu kecuali `BE_RELOAD_CMD` diisi), kalau tidak runner menguji
kode lama. Runner tidak me-reload sendiri.

## Config

Dibaca dari `config/workspace.env`, lalu `config/secrets.env` (kunci yang sudah terisi tidak ditimpa), lalu env
proses menang atas keduanya (sama dengan `scripts/lib/env.sh`).

| Kunci | Wajib | Arti |
|---|---|---|
| `API_URL` | ya | URL server BE, mis. `http://127.0.0.1:8004` |
| `BE_DIR` | ya | Checkout BE: Laravel di-bootstrap dari sini (baca DB, `client_secret`, cek token) |
| `QA_DB`, `QA_DBS` | salah satu | DB tenant default dan daftar DB yang boleh di-bind (urutan pertama = default) |
| `QA_USER`, `QA_PASS` | ya | User QA utama |
| `QA_USER2`, `QA_PASS2` | tidak | User berhak lebih sedikit (uji 403). Kosong: `login('QA_USER2')` = SKIP |
| `CLIENT_ID` | tidak | OAuth client password grant, default `4`; secret dibaca dari `oauth_clients` |
| `PROFILE_ACTIVE` | otomatis | Diisi `scripts/profile.sh run <nama> --`. DB = `PROFILE_<nama>_DB`; user = `QA_<NAMA>_USER/PASS` bila ada |

Rahasia (`*PASS*`, `*SECRET*`, `*TOKEN*`, token, refresh token, client secret) tidak pernah dicetak: semua
teks keluar (stdout, error, laporan) disamarkan, dan `$t->conf()` menolak kunci rahasia.

## Sesi dan token

- `$t->session($db)`: sesi user utama terikat `$db` (default `QA_DB`), dibuat sekali per run: login
  (`POST api/v5/auth/token`) lalu langsung bind (`PUT api/v5/auth/database`, `force_login=1`). Berurutan per DB.
  `$db` di luar `QA_DBS` = BLOCKED.
- `force_login=1` hanya mencabut token LAIN milik user QA itu sendiri di DB yang sama, tidak pernah milik user lain.
- Bind ditolak `GE0106` (batas user online lisensi) = AC **BLOCKED**, bukan FAIL.
- Di akhir run (juga bila run mati di tengah) setiap token yang dibuat runner di-log-out
  (`GET api/v5/auth/log-out`), lalu dicek di `oauth_access_tokens` berapa yang masih `revoked=0`.

## Format skenario

`features/<KEY>-<slug>/qa/scenario*.php` (boleh beberapa berkas; id harus unik antar berkas) me-return array.
Contoh lengkap: `templates/qa/scenario.example.php`.

```php
<?php
return [
    [
        'id'    => 'AC-1',                       // sama dengan id di spec.md
        'title' => 'Daftar resource 200',
        'needs' => [],                           // opsional: DB yang di-bind sebelum run
        'run'   => function ($t) {
            $r = $t->call($t->session(), 'GET', 'api/v5/modul/resource');
            $t->status($r, 200);
        },
    ],
];
```

Satu asersi gagal = AC FAIL, runner lanjut ke AC berikutnya. Exception lain juga FAIL.

## API `$t`

Respons `[status, json]`: `$r[0]` HTTP, `$r[1]` body JSON (array).

| Method | Arti |
|---|---|
| `session($db = null)` | Sesi user utama terikat `$db` (lihat atas) |
| `login($who = null)` | Token baru belum terikat DB. `QA_USER`, `QA_USER2`, `QA_<PROFIL>_USER`; SKIP bila kosong |
| `bind($s, $db, $force = 1)` | `PUT api/v5/auth/database` -> `[status, json]`; `null` = tanpa `force_login`. Tidak melempar |
| `forget($db)` | Buang sesi cache `$db` |
| `call($s, $method, $uri, $body = null)` | Request dengan token; `$uri` relatif ke `API_URL` |
| `raw($method, $uri, $body = null)` | Request tanpa token |
| `callFile($s\|null, $method, $uri)` | Respons bukan JSON (unduhan): `[status, rawBody, headers]` |
| `uploadFile($s, $uri, $path, $field = 'file')` | Unggah multipart -> `[status, json]` |
| `db($name = null)` | Koneksi Laravel **read-only** (`SET SESSION TRANSACTION READ ONLY`); hanya SELECT |
| `probe(callable)` | Jalankan kode di proses CLI yang sudah bootstrap Laravel (untuk tulis/pulihkan data uji) |
| `globalDb()` / `dbs()` | Nama DB koneksi default `BE_DIR` / daftar DB QA |
| `conf($key, $default)` | Nilai config non-rahasia |
| `eq($actual, $expected, $label)` | Sama persis; angka dan string angka dianggap sama |
| `true($cond, $label)` | `$cond === true` |
| `has($array, 'a.b.c', $label)` | Kunci ada (nilai boleh null) |
| `status($r, 200)` / `code($r, 'GE0106')` | HTTP status / kode dari `msg_code` atau `code` |
| `rowExists($db, $table, $where)` | Ada baris; `$where` = `kolom => nilai` (`null` = IS NULL, array = IN) |
| `rowCount($db, $table, $where, $n)` | Jumlah baris = `$n` |
| `isolation($sX, $sY, $n = 10)` | `GET api/v5/auth/info` bergantian; tiap respons `selected_database.db_name` = DB sesinya dan `company.id_company` = baris pertama `companies` DB itu |
| `skip($msg)` / `blocked($msg)` / `fail($msg)` | Akhiri AC dengan status itu |
| `note($msg)` | Catatan yang ikut di pesan hasil |

## Data uji yang bisa diulang

Skenario harus bisa dijalankan berulang di DB yang sama dan tidak boleh bergantung pada id/baris yang dicatat
tangan atau yang bisa hilang saat DB di-restore.

- Buat data uji sendiri lewat API nyata dengan penanda unik (`'QA-' . uniqid()`), baca id acuan saat run.
- Pulihkan persis di `finally`: hapus lewat API, atau lewat `$t->probe()` dengan `DB::connection()`.
- Bila harus mengubah data yang sudah ada: snapshot baris (dan berkas) sebelum, kembalikan persis sesudah.
- Pastikan sesudah AC tidak ada sisa (`rowCount(..., 0)`). Run yang mati di tengah tidak boleh membuat run
  berikutnya gagal: AC memeriksa dan membuang sisa berpenanda `QA-` lebih dulu.
- `--only` melewati AC lain: jangan buat AC bergantung pada data yang dibuat AC lain.

## Berkas

| Berkas | Isi |
|---|---|
| `run.php` | CLI: argumen, pemuatan skenario, loop AC, log-out, laporan |
| `lib/Config.php` | Loader config (setara `env.sh`), penyamaran rahasia |
| `lib/Http.php`, `lib/Sessions.php` | curl; login, bind, cache sesi, log-out semua token |
| `lib/Laravel.php`, `lib/Tester.php` | bootstrap Laravel, DB read-only; objek `$t` |
| `lib/Outcome.php` | Exception hasil (FAIL/SKIP/BLOCKED/infra) |
| `work/qa-http/` | Laporan JSON (gitignored) |
