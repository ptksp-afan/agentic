---
key: ED-1028
scope: be
round: 1
verdict: PASS
counts: {pass: 14, fail: 0, manual: 0, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

QA BE ronde 1, Story ED-1028 (subtask BE ED-1034, ED-1040, ED-1046; QA ED-1062). Cakupan: 14 AC `[BE]` (AC-1..AC-14).

- Skenario permanen di `features/ED-1028-archive-opname-history/qa/` (7 berkas `scenario*.php` + `qa_lib.php`, `qa_hist.php`): **22 skenario** (14 AC + 8 tambahan X-1..X-8), **2.642 asersi, semuanya PASS** pada run penuh terakhir (`work/qa-be-ed1028-r1-final.txt`, laporan JSON `work/qa-http/ED-1028-20261007200627.json`).
- Sesi opname dibuat lewat API opname ED-1026 (draft, scan, confirm, cancel) oleh superadmin sementara (role 1), lalu waktu/pemilik diatur di DB agar urutan dan rentang deterministik. Pengamat: superadmin role 1 dan 2, `user3` = QA_USER role 3 (semua lokasi, bukan superadmin), `user2` = QA_USER2 sungguhan (Sales Supervisor, lokasi SMR, **List Archive tanpa Opname Document**, bahasa EN). Setiap AC memverifikasi hasil terhadap **oracle independen** (SQL mentah + PHP, tanpa kode BE) selain angka tangan.
- Tidak ada HTTP 500 dari kode ED-1028 di ribuan request (fuzz 460 request, 20-30 ribu folder sisipan, 3.000 sesi sisipan, sesi 39 ribu baris).
- Regresi ED-1024..ED-1027: 110 skenario PASS, 4 FAIL yang semuanya bukan regresi (2 stale yang sudah diketahui, 2 asersi ED-1026 yang digantikan oleh perubahan sah item ini; lihat bagian Regresi).
- 1 defect kecil (D-1, kontrak, tidak memblokir AC). 3 hal untuk dikonfirmasi developer.
- Server `fpm` (kode PHP dibaca ulang tiap request, tidak ada reload). Tidak ada perintah yang ditolak izin/classifier.
- Efek samping runner: bind `force_login=1` mencabut token lain QA_USER di `api_sidomaju` (token run: 0 masih aktif di akhir). Role, lokasi karyawan, bahasa QA_USER dan role QA_USER2 dipulihkan persis (dicek: role [3] / [5], lokasi sama, ID/EN); tabel opname 0 baris, tanpa data `QA28`, baris display setting `documentArchiveOpnameHistory` dihapus kembali (hanya `documentArchive` ada, seperti sebelum run).
- Transparansi: sebelum menulis skenario saya sempat membaca `run.md` dan "Catatan implementasi" di spec yang memuat catatan dev (mis. risiko kolasi latin1, sort). Pengecekan tetap diturunkan dari spec + kontrak; catatan itu hanya membuat saya menyertakan kasus teks non-latin1, sort kolom tersembunyi dan sesi batal.

## Hasil per AC

Bukti = berkas skenario (di `qa/`) + jumlah asersi; semua dijalankan dengan `"$PHP_BIN" scripts/qa-http/run.php ED-1028 [--only=...]`.

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | `scenario1-list.php` AC-1: baris display setting dihapus lebih dulu, request pertama (data kosong dan dengan 7 sesi): 200 `ARCHIVE200`, semua kunci paginator, `columns` = 7 kolom urut BR-6 (= DB `current_columns` = config), `available_columns` = 7 + Scanned + Belum discan, `queries` = confirmedAt `dateTimeRange`, createdBy `select` endpoint opname-users, idArchives `select` endpoint folders `multiple`; baris `column_display_settings` terbentuk 1x (request kedua tidak menggandakan); urut `confirmed_at` turun; kunci baris = kolom aktif + `id_archive_opname`/`id_archive`/`scope`; pagination (halaman 1-4, 0/-3/abc/x tidak 500); pesan ID/EN | PASS | 146 asersi. Excerpt: `urut confirmed_at turun (Z, Y, D, C, B, A)`; `respons columns = DB current_columns` |
| AC-2 | `scenario1-list.php` AC-2: tiap baris = kolom `archive_opnames` (total/verified/not found/invalid, created_by, confirmed_at, id_archive); angka tangan sesi A = 9/2/1/1; `scope` = `scope` `GET opnames/{id}` (juga sesi root + 3 subfolder: `other_count` 2, folder + 2 subfolder: 1); dokumen ditambah ke F/S1/S2, dokumen di-unverify dan dinonaktifkan sesudah Confirm: seluruh baris identik | PASS | 153 asersi. Excerpt: `sesudah dokumen ditambah ... semua baris sama persis` |
| AC-3 | `scenario1-list.php` AC-3: draft (user lain dan milik sendiri) dan sesi batal tidak muncul untuk superadmin, user3, konteks folder dan filter user pembuat; kontrol positif: draft baru muncul tepat setelah Confirm | PASS | 70 asersi |
| AC-4 | `scenario2-search.php` AC-4: username sebagian / besar-kecil / trim; nama folder level 0, 1, 2 (snapshot; nama diganti sesudah Confirm: nama lama masih cocok); `%`, `_`, `\` harfiah; teks non-latin1/emoji/injeksi/3.000 karakter = 200 kosong; user2: nama folder tanpa View / lokasi JOG tidak mencocokkan, superadmin masih cocok; oracle penuh untuk 10 teks x user2/user3; search bukan JSON = 422 | PASS | 145 asersi. Excerpt: `user2: nama PRIV (tanpa View) tidak mencocokkan R`; `query "qa28_": "_" harfiah` |
| AC-5 | `scenario2-search.php` AC-5: rentang inklusif di kedua ujung (selisih 1 detik), hanya awal, hanya akhir, `[null,null]`, awal > akhir kosong; 10 bentuk tak valid (bulan 13, tanpa jam, teks, ISO `T`, angka, 1/3 elemen, string, objek) = 422 dengan kunci error `confirmedAt.0` / `.1` / `confirmedAt` | PASS | 93 asersi |
| AC-6 | `scenario2-search.php` AC-6: `createdBy` persis; `idArchives` [S2,OTH] = ATAU; [F] mencakup sesi subfolder (K-3 ii: A, B, Y, D, E); [S1a] -> A, Y; id ganda, id tak ada/dokumen/nonaktif = kosong 200; AND dengan query/confirmedAt (4 filter sekaligus); user2: id di luar scope tidak mencocokkan; non-array / isi angka / bersarang / createdBy array = 422 | PASS | 101 asersi. Excerpt: `idArchives [F] (superadmin): A (F), B (S2), Y (S1a), D (SJ), E (SP)` |
| AC-7 | `scenario2-search.php` AC-7: 8 kolom sortable x asc/desc x bentuk JSON-string FE dan objek = urutan oracle (nilai DB, pemecah seri `confirmed_at` turun lalu id turun); `scope`, kolom asing, relasi, `sortBy` array/injeksi diabaikan (urutan bawaan, 200); `sorts` mentah aneh tidak 500; dua sort; sort + pagination lintas halaman; sort + filter | PASS | 173 asersi. Catatan: param `sort` (tunggal) dipetakan ke `sorts` oleh middleware `ConvertRequestToSnakeCase` |
| AC-8 | `scenario3-visibility.php` AC-8: 9 sesi (termasuk 2 sesi dari folder yang dihapus fisik). Superadmin role 1 dan 2 = semua 9; user3 = 7 (tanpa E = SP tanpa View, tanpa G1 folder terhapus bukan pembuat; G2 terlihat sebagai pembuat); user3 sebagai pembuat E melihat E; user2 = 5 (Z, Y, C, B, A). Tiap kasus = oracle, dan 3 baris pertama halaman 1 = `last_sessions` `GET verifications` (id, waktu, user, scope, angka) | PASS | 88 asersi |
| AC-9 | `scenario3-visibility.php` AC-9: `id_archive`=F/S1/OTH/S1a/P untuk superadmin, user3, user2 = oracle + angka tangan, 3 baris pertama = `last_sessions` `GET verifications/{F}`; folder tanpa sesi = kosong 200; AND dengan query/idArchives/confirmedAt; 404 `ARCHIVE400` (id tak ada, nonaktif, panjang, non-ASCII, kutip, array), 400 `ARCHIVE417` (dokumen), 403 `ARCHIVE407` (SP, SPc, PRIV, lokasi JOG, tanpa View) tanpa data, pesan EN benar, konteks diperiksa sebelum validasi search (403 dulu) | PASS | 195 asersi |
| AC-10 | `scenario4-select.php` AC-10: himpunan folder = oracle SQL untuk superadmin role 1/2 (semua folder aktif, 32), user3 (28), user2 (21: tanpa dokumen, nonaktif, JOG, tanpa View); label = path dari tabel mentah; urut label; nama kembar = 2 opsi label beda; search nama (bukan path, tanpa beda huruf); `selected_id` string/array ikut hanya bila dalam scope (PRIV/SJ/SP/SX/dokumen/tak ada tidak ikut); bentuk salah tidak 500 | PASS | 223 asersi; ~190-250 ms per panggilan |
| AC-11 | `scenario4-select.php` AC-11: username unik sesi terkonfirmasi terlihat = oracle SQL (urut kolasi DB); superadmin pelaku ikut; draft-only / batal-only / user tanpa sesi (termasuk user2 sendiri) tidak muncul; user2 tanpa carol (sesi D) dan eve (sesi E); search, `%`, `_` harfiah, `selected_id`; value = label; tanpa sesi = `options` [] | PASS | 126 asersi |
| AC-12 | `scenario5-access.php` AC-12: prasyarat dari DB (role user2 punya 1082, tanpa 1133). user2: show + documents sesi terlihat A, B, C, Y, Z = 200; sesi D, E, G1, G2, draft user lain dan milik user QA, sesi batal user lain, id tak ada/panjang/kutip/non-ASCII = 404 `ARCHIVE412` tanpa data; user3: pembuat selalu terbaca (G2, E dipindah pemilik), draft sendiri 200, sesi batal sendiri 200 (perilaku 03); superadmin 1/2 semua terkonfirmasi, draft/batal user lain 404; user2 menulis (folders, POST, confirm, DELETE, scan) = 403 `GE0114` `Opname Document`, draft tak berubah; alur 03 pemegang Opname Document (Step 1 folders, scan, show draft, documents, Confirm, muncul di history) | PASS | 408 asersi |
| AC-13 | `scenario3-visibility.php` AC-13: sesi root superadmin (root + OTH + JOGR + PRIV; scan 4 verified, 2 not found, 1 invalid). Superadmin: `is_partial` false, `result_counts` = `counts`, jumlah baris = `result_counts` untuk 6 filter. user2 dan user3 (pembuat, bukan superadmin): `result_counts`, baris tiap filter (semua halaman, pagination 3 dan 500) dan `total` paginator = oracle baris tersaring (scope lokasi + View folder), Invalid selalu tampil, `is_partial` true, `counts` kartu tetap angka rekaman; sesi A untuk user2 parsial karena dJF lokasi JOG | PASS | 138 asersi; X-7 mengulang dengan 39.241 baris (53 asersi) |
| AC-14 | `scenario5-access.php` AC-14: user2 dengan role sementara 22 (tanpa 1082): 403 `GE0114` pada 8 URL (`GET opnames` dengan dan tanpa `id_archive`/`search`, `opnames/{id}`, `/documents`, id tak ada), parameter pesan = `List Archive`, tanpa data / tanpa kebocoran id sesi; role asli 200 sebelum dan sesudah; role dipulihkan persis; tanpa token 401 (3 endpoint + 2 select). Seed tidak berubah: `git status` BE hanya 9 berkas M + 1 baru (tanpa `.sql`, tanpa Updater) | PASS | 108 asersi. Bagian "tukar lisensi sungguhan" = MANUAL Gate 2 epic (lihat bawah) |

Skenario tambahan (di luar AC, tetap permanen): X-1 display setting lewat API v4 `PUT display-columns/documentArchiveOpnameHistory` (kolom aktif menentukan kunci baris, Scanned/Belum discan, sort/filter kolom tersembunyi, 0-1 kolom aktif) 81 asersi; X-2 3.000 sesi sisipan (list/search/filter/sort/select 220-250 ms) 83; X-3 isolasi antar user bergantian 60; X-4 fuzz 460 request (status 200/400/404/422, nol 5xx) 45; X-5 bentuk parameter FE (`idArchive` camelCase, Reset filter, kunci search snake_case diabaikan) 54; X-6 20.030 folder, select 283 ms 16; X-7 sesi 39.241 baris (show 335 ms, documents 400 ms) 53; X-8 30.000 folder + daftar `IN` panjang untuk user bukan superadmin (< 1,1 s) 83.

## Defect

**D-1 (rendah, kontrak; tidak memblokir AC)** - subtask ED-1034 `[BE]` (layer BE), rujukan kontrak §1 baris 422 / BR-13.
- Langkah: `GET /api/v5/document-archive/opnames?search=[1,2]` (juga `[{"query":"a"}]`), token QA_USER.
- Diharapkan: 422, kontrak §1 "`search` bukan JSON objek".
- Aktual: 200, tanpa filter (daftar penuh). `search=123`, `"x"`, `abc`, `[` benar 422; `[]` dan `{}` = 200 (wajar).
- Dugaan: `OpnameService.php:933` `if (!is_array($search))` meloloskan JSON list non-kosong. Perbaikan: tolak array list (`array_values($search) === $search && $search !== []`) atau longgarkan kalimat kontrak. FE tidak pernah mengirim bentuk ini.

## Perlu dikonfirmasi developer (bukan defect)

1. Kontrak §2 baris tabel 404 `ARCHIVE412` menyebut "sesi batal" tanpa syarat pemilik, sedangkan badan §2 dan perilaku ED-1026 (teruji) membolehkan pemilik membaca sesi batalnya sendiri; sesi batal milik user lain memang 404 (teruji). Usul: samakan kata-kata tabel.
2. `idArchives: [""]` (elemen kosong) = 200 dengan 0 baris (kontrak hanya mengatur `[]` = tanpa filter). FE yang mengosongkan multi-select harus mengirim `[]`.
3. Label path select folder memuat nama induk walau induk tidak terlihat user (sama dengan breadcrumb folder, tercatat di spec). Dan filter `idArchives` dikirim di query string GET: server web menolak > ~8 KB (percobaan 601 id = 414); 90 id aman.

## Isolasi tenant & permission

Permission (semua endpoint baru/diubah):

| Endpoint | Pemegang izin | Tanpa izin | Tanpa token |
|---|---|---|---|
| GET `opnames` (List Archive) | superadmin 1/2, user3, user2: 200 | role 22: 403 `GE0114` (param `List Archive`) | 401 |
| GET `opnames/{id}`, `/documents` (List Archive) | user2 (tanpa Opname Document) 200 / 404 `ARCHIVE412` sesuai visibilitas | role 22: 403 `GE0114`, juga untuk id tak ada | 401 |
| GET select `folders`, `opname-users` | konvensi select tanpa `permission_v5` (spec BR-12): 200, data tetap dalam scope user (teruji untuk role 22: = oracle scope user2) | n/a | 401 |
| Endpoint tulis 03 (folders, POST, scan, confirm, DELETE) | tetap `Opname Document`: user2 = 403 `GE0114` | | |

Isolasi tenant: **tidak bisa diverifikasi**, `QA_DBS` hanya `api_sidomaju` (1 DB). Pengganti yang dikerjakan: (a) tinjauan diff: tanpa `static`, singleton, `Cache::`, `config([...])` atau `$GLOBALS` baru; `context()`/`viewableFolderIds()` dihitung per request; (b) X-3: 6 putaran permintaan bergantian user2 dan user3 (list, select opname-users, select folders) memberi hasil identik di tiap putaran, bahasa (EN/ID) tidak bocor. Perlu diulang saat ada dua DB tenant.

## Tinjauan kebenaran diff BE (bukan gaya)

- Kontrak vs aktual: kunci baris, `columns`/`queries`, envelope `ARCHIVE200`/`SUCCESS`, kode 404/400/403/422, kunci error 422 (`confirmedAt.0`, `idArchives`, `createdBy`), `result_counts`/`is_partial` (draft: dari `counts`, `is_partial` false), `total` paginator = `result_counts[result]` = cocok, kecuali D-1.
- Kode pesan yang dipakai (`ARCHIVE200/400/407/412/417`, `GE0114`, `SUCCESS`) ada di `app/Lib/lang/id_ID.php` dan `en_EN.php`; tidak ada kode baru.
- Skema: tidak ada tabel/Updater/permission seed baru (sesuai spec); display setting dibuat otomatis oleh `MyHelper::getDisplaySetting` pada request pertama (teruji). Tidak ada kolom v3 yang disentuh (hanya baca).
- Rollback: semua endpoint baru hanya baca; endpoint opname 03 yang berubah (`readable()` mengembalikan `[opname, context]`) tetap lulus regresi 03.
- Performa: query `EXISTS` tanpa duplikasi baris; 3.000 sesi, 30.000 folder, 39 ribu baris snapshot semuanya < 1,1 s.

## Regresi BE ED-1024..ED-1027

Dijalankan penuh sesudah skenario ED-1028 (`work/qa-be-ed1028-r1-reg-ED-10xx.txt`).

| Item | PASS | FAIL | SKIP | Catatan |
|---|---|---|---|---|
| ED-1024 | 26 | 0 | 0 | bersih |
| ED-1025 | 30 | 1 | 0 | AC-1 = stale yang sudah diketahui ("Updater = kunci terbesar di `Updaters/config.php`") |
| ED-1026 | 34 | 3 | 0 | AC-1 = stale yang sudah diketahui; **AC-12 dan AC-15 digantikan oleh perubahan sah item ini** (lihat di bawah) |
| ED-1027 | 20 | 0 | 1 | X-3 SKIP (1 DB tenant) |

ED-1026 AC-12: asersi "sesi terkonfirmasi user lain, non-superadmin: show/documents 404" kini 200 untuk sesi yang terlihat (BR-3/BR-12, kontrak §2 "memperluas visibilitas"). ED-1026 AC-15: daftar kunci `show` persis tanpa `result_counts` dan `is_partial` (kontrak §2 menambahkannya). Karena AC berhenti di asersi gagal pertama, saya menjalankan **salinan di folder scratch** (bukan mengubah berkas item lain) dengan dua ekspektasi itu disesuaikan: AC-12 (140 asersi), AC-15 (40), AC-24, EXTRA-SCOPE, EXTRA-PERM, EXTRA-STALE semuanya PASS, jadi sisa asersi (draft user lain 404 untuk semua termasuk superadmin, sesi batal user lain 404, mutasi ditolak 400/404) tidak berubah. Berkas QA ED-1026 (dan dokumen 03 §4/§6) perlu diperbarui oleh pihak yang berwenang.

500 yang tampak di keluaran regresi: hanya ED-1025 X-12 (75 dari 80 kasus; tiket ED-1071: karakter di luar Windows-1252) dan X-13 (tiket ED-1070: `pagination`/`sorts`/`search` salah bentuk pada `GET archives`), keduanya karakterisasi bug pra-ada lintas v5 pada endpoint ED-1025/list Archive. Bukan kode ED-1028 dan tidak dihitung sebagai defect item ini; disebut karena aturan "setiap 500 adalah defect".

## Yang tidak bisa diverifikasi (dan kenapa)

- Isolasi tenant antar-DB: `QA_DBS` berisi 1 DB (lihat di atas).
- AC-14, bagian "tukar lisensi sungguhan sekali" (tanpa fitur Salesman Activity): `PROFILES` kosong; MANUAL di Gate 2 epic (cara seragam EPIC K-2 b). Bagian http (role tanpa List Archive), seed tidak berubah, dan 401 sudah PASS.
- AC `[FE]` dan `[BE+FE]` (AC-15..AC-25) bukan milik tahap ini.

## Riwayat ronde

- Ronde 1 (2026-10-07): 22 skenario PASS (2.642 asersi), 1 defect rendah (D-1), regresi ED-1024..1027 tanpa regresi nyata; verdict PASS.
