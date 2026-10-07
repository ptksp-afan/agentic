---
key: ED-1026
scope: be
round: 2
verdict: PASS
counts: {pass: 19, fail: 0, manual: 0, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

Verdict **PASS** (ronde 2). D-1 (ED-1038) dan D-2 (ED-1044) dari ronde 1 **tertutup** dan diuji ulang dengan skenario baru yang lebih ketat; tidak ada regresi, tidak ada HTTP 500 dari endpoint opname.

- `counts` = 19 AC bertag `[BE]` (AC-1, 2, 5, 6, 9, 10, 11, 12, 15, 16, 18, 19, 20, 21, 23, 24, 25, 26, 27), semuanya PASS. Bagian BE dari 3 AC `[BE+FE]` (AC-3, AC-8, AC-22) juga PASS tetapi milik tahap FE sehingga tidak dihitung.
- Skenario permanen `features/ED-1026-archive-opname-document/qa/`: **37 PASS, 0 FAIL** dalam satu run penuh (`work/qa-be-ed1026-r2-final.txt`, laporan `work/qa-http/ED-1026-20261007120315.json`). 32 entri lama + 5 entri baru ronde 2 di `scenario7-round2.php` (34,7 KB; semua berkas < 40 KB): `R2-D1`, `R2-D2`, `R2-SCANVOL`, `R2-PARALLEL-DIFF`, `R2-SCHEMA`. Dua entri ronde 1 yang gagal kini hijau (`AC-9-LEN`, `EXTRA-ORDER`).
- Regresi BE: ED-1024 **26/26 PASS** (`work/qa-be-ed1026-r2-regress-1024.txt`). ED-1025 **30 PASS, 1 FAIL**: AC-1 hanya pada asersi usang "kunci Updater ED-1025 terbesar di `config.php`" (Updater ED-1026 sengaja lebih baru, kunci 1791342745 > 1791321927); bukan cacat produk, sama dengan ronde 1 (N-3). Salinan scratch AC-1 dengan asersi itu dilonggarkan: **PASS, 22 asersi** (berkas ED-1025 tidak diubah).
- Updater dan skema yang berubah di ronde ini diperiksa ulang: `AC-1` 161 asersi (2x run di DB QA, dari nol di DB scratch, SHOW CREATE identik) dan `R2-SCHEMA` 22 asersi (jalur DB yang sudah menjalankan versi awal, lihat di bawah).
- Kode terbaru dipakai (fpm, tanpa reload; perilaku baru terlihat di respons: `AC-9-LEN` 422 dan `scanned_at` bermikrodetik di DB).
- **DB `api_sidomaju` dibiarkan seperti ditemukan.** Awal run (diukur sebelum skenario pertama): `archive_opnames`/`archive_opname_folders`/`archive_opname_documents` = 0 baris; `archives` 49.145 baris, 0 baris kolom verifikasi tak-default; `archives.is_verified/verified_at/verified_by/id_archive_opname` default (0/NULL/NULL/NULL); `scanned_at` `datetime(6)`. **Akhir run** (diukur ulang sesudah semua run, termasuk regresi ED-1024/1025): tiga tabel opname 0 baris; `archives` 49.145 baris (sama), 0 tak-default, 0 baris `QA%`; `archive_documents` 49.126 (0 `QA%`); `archive_permissions` 0; 0 DB scratch `qa26_scratch_*`; tanpa berkas jurnal `.restore-journal-q26.json`/`.sessions-journal.json`; user QA identik dengan awal (hash snapshot role/bahasa/lokasi sama: 1 role id 3, bahasa ID, semua lokasi); 0 token aktif; permission 1133 tetap ada (seed bagian fitur).

## Hasil per AC

Perintah dasar: `"$PHP_BIN" scripts/qa-http/run.php ED-1026 [--only=...]`; "asersi" = jumlah asersi lulus pada run akhir. Oracle jumlah dokumen = SQL/PHP independen (`q26_oracle`), bukan kode BE.

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | `scenario1` AC-1: kolom/tipe/default/urutan/PK/UNIQUE/KEY/kolasi/InnoDB 3 tabel + 4 kolom & indeks `archives`; baris lama `is_verified=0`; Updater 2x di DB QA (SHOW CREATE + checksum tak berubah); dari nol di DB scratch (SHOW CREATE identik, 4 tabel); terdaftar `config.php` dengan kunci terbesar. Ronde 2 tambah `R2-SCHEMA` (lihat bawah) | PASS | `AC-1 PASS 161`, `R2-SCHEMA PASS 22` |
| AC-2 | `scenario1` AC-2: `1133`/`Opname Document` hanya di `permission_salesman.sql`; tuple, checks/unchecks; id 1133 > semua id `permissions.sql`; DB QA punya baris + role 1/2/3; seluruh `permission_salesman.sql` dijalankan di DB scratch | PASS (tukar lisensi sungguhan = MANUAL Gate 2 epic, EPIC K-2 b) | `AC-2 PASS 25` |
| AC-5 | `scenario2` AC-5: root sebagai user Semarang, superadmin 1 dan 2; urutan nama, `document_count` subtree, `has_children` = oracle, `opnamed_today` null, `is_default_checked` true, kunci persis kontrak; JOGJA hanya untuk superadmin | PASS | `AC-5 PASS 112` |
| AC-6 | `scenario2` AC-6: dokumen 400 `ARCHIVE417`; acak/nonaktif 404 `ARCHIVE400`; folder lokasi JOG sebagai Semarang 403 `ARCHIVE407`; superadmin bypass; id non-ASCII/31 karakter/array/SQL-meta tanpa 500. Ronde 2: `R2-D1` bagian GET folders (31 -> 422, 30 tak ada -> 404, non-ASCII 10 -> 404, 31 -> 422) | PASS | `AC-6 PASS 42`, `R2-D1 PASS 101` |
| AC-9 | `scenario2` AC-9 (valid + DB, root, camelCase, 416, F luar scope 403 sebelum 422, 422 untuk 10 bentuk body, PUT) + **D-1**: `AC-9-LEN` dan `R2-D1` (matriks id_archive di bawah) | PASS | `AC-9 PASS 101`, `AC-9-LEN PASS 7`, `R2-D1 PASS 101` |
| AC-10 | `scenario3` AC-10: 5 Verified, 7 Not found, 9 Invalid; DB `archive_opname_documents` (result, id_archive, id_archive_folder, `scanned_at` waktu server, `scanned_at` klien diabaikan) | PASS | `AC-10 PASS 267` |
| AC-11 | `scenario3` AC-11: kode sama (persis/huruf besar/kecil/spasi tepi) -> `is_duplicate:true`, `counts` & baris DB tetap. Ronde 2: duplikat tidak menggeser `scanned_at`/urutan (`R2-D2`) | PASS | `AC-11 PASS 48`, `R2-D2 PASS 172` |
| AC-12 | `scenario4` AC-12: 404 `ARCHIVE412` (6 operasi tulis + show + documents, draft user lain, termasuk superadmin), 400 `ARCHIVE413`; terkonfirmasi user lain: non-superadmin 404, superadmin 1/2 200; DB tak berubah | PASS | `AC-12 PASS 144` |
| AC-15 | `scenario4` AC-15: `counts` (BR-20), `warnings`, `folders`, kunci persis kontrak; berubah mengikuti scan; nilai tersimpan sesudah Confirm; `EXTRA-SCOPE` | PASS | `AC-15 PASS 40`, `EXTRA-SCOPE PASS 21` |
| AC-16 | `scenario3` AC-16: 6 filter (= kartu), paginator tanpa `columns`/`queries`, urutan, Not found `folder_name` null + `is_out_of_scope`, unscanned `scanned_at`/id null, `result` salah 422, snapshot. Ronde 2: `scanned_at` response tetap `Y-m-d H:i:s` di `/scan`, `/documents` draft dan terkonfirmasi (`R2-D2`); `EXTRA-PAGING` | PASS | `AC-16 PASS 187`, `EXTRA-PAGING PASS 241`, `R2-D2 PASS 172` |
| AC-18 | `scenario5` AC-18: discan -> `is_verified=1` + `verified_at/by` + `id_archive_opname`; tak discan -> 0; status 2 + 8 angka final; folder level 0-2; baris `result=4`; read dari snapshot | PASS | `AC-18 PASS 105` |
| AC-19 | `scenario5` AC-19: Lanjut aktif -> tetap; nonaktif -> 0 dan `unverified_count` = warnings; campuran; turunan mengikuti Step 1 | PASS | `AC-19 PASS 50` |
| AC-20 | `scenario5` AC-20: subfolder tak dicentang/lokasi lain tak tersentuh; Semarang + JOG opname root (~30 rb dokumen, dipulihkan); superadmin melihat keduanya; `EXTRA-PERM` | PASS | `AC-20 PASS 36`, `EXTRA-PERM PASS 29` |
| AC-21 | `scenario5` AC-21: 7 Not found + 3 Invalid; `archives` dan `archive_documents` (JSON penuh) identik sesudah scan dan Confirm | PASS | `AC-21 PASS 23` |
| AC-23 | `scenario6` AC-23: draft kemarin 23:59:59 -> 400 `ARCHIVE415`, 00:00:00 hari ini lolos; batas tengah malam "diopname hari ini" | PASS | `AC-23 PASS 34` |
| AC-24 | `scenario4` AC-24: DELETE draft -> status 3 + `ARCHIVE214`; tak dianggap diopname; confirm/scan/PUT/DELETE sesudahnya 400 `ARCHIVE413` | PASS | `AC-24 PASS 38` |
| AC-25 | `scenario5` AC-25: pindah keluar/masuk/nonaktif via API put-in; `EXTRA-STALE` tanpa 500 | PASS | `AC-25 PASS 30`, `EXTRA-STALE PASS 46` |
| AC-26 | `scenario6` AC-26: list, create folder, put-in, hand-over, receive tetap; `is_verified` tak berubah oleh pindah/hand-over/receive; dokumen baru `is_verified=0` | PASS | `AC-26 PASS 41` |
| AC-27 | `scenario6` AC-27 (Backup Arsip 24.279 dokumen): folders root 0,33 dtk, store 0,23, show 0,30, documents all 0,31, **Confirm 1,46 dtk**, snapshot hal 150 0,23 (semua < 30 dtk, tanpa 500) | PASS | `AC-27 PASS 72` |
| AC-3 (bagian BE) | `scenario2` AC-3: tanpa token 401; role 6 -> 8 endpoint 403 `GE0114`; role 29 (List) -> hanya show/documents 200, enam lain 403 `parameter: "Opname Document"`; DB tak berubah. Ronde 2: urutan 401 > 403 `GE0114` dengan `id_archive` 31 karakter / non-ada / dokumen (`R2-D1`) | PASS (bagian FE di tahap FE) | `AC-3 PASS 62`, `R2-D1` |
| AC-8 (bagian BE) | `scenario2` AC-8: ZULFA `skip_select_folder:true`, `scope`, total = oracle | PASS (bagian FE di tahap FE) | `AC-8 PASS 26` |
| AC-22 (bagian BE) | `scenario6` AC-22: 400 `ARCHIVE414` + `msg_code`, `result.folders[]`, DB tak berubah; `EXTRA-CONCURRENT` (dua Confirm bersamaan x3 = tepat satu 200 + satu 400, tanpa 5xx); `EXTRA-PARALLEL-SCAN` | PASS (bagian FE di tahap FE) | `AC-22 PASS 65`, `EXTRA-CONCURRENT PASS 28`, `EXTRA-PARALLEL-SCAN PASS 22` |

Tambahan lulus: `EXTRA-LANG` 68 asersi (10 kode pesan en/id persis kontrak), `EXTRA-SCAN` 98 asersi (validasi `code`, Latin-1, id sesi aneh tanpa 500).

### Pengecekan ulang defect ronde 1

**D-1 (ED-1038) tertutup.** `POST opnames` dengan `id_archive` 31 karakter kini **422** (dulu 404 `ARCHIVE400`). `R2-D1` (101 asersi):
- 31/32/60/255/300 karakter -> 422 (pesan menyebut `id_archive`, bukan `ARCHIVE400`); juga tanpa `folders`, dengan field lain salah, dan `idArchive` camelCase; 1/29/30 karakter tak ada -> 404 `ARCHIVE400`; id acak 30 digit 404.
- Non-ASCII 10 karakter (lolos rule) -> 404 `ARCHIVE400`, 31 karakter -> 422; `"x\0y"` 404/422 (bukan 5xx).
- int / true / array / objek / float -> 422; string kosong dan null -> root (200, DB `id_archive` NULL, dibatalkan); dokumen -> 400 `ARCHIVE417`; folder valid 200; `folders.*` 30 karakter tak ada -> 400 `ARCHIVE416`, 31 karakter -> 422.
- GET `opnames/folders` memberi jawaban sama untuk input yang sama (konsisten dengan POST).
- Urutan penolakan terjaga: tanpa token 401 > role 6/29 403 `GE0114` (`parameter` = `Opname Document`, juga untuk id 31 karakter) > user Semarang + F lokasi JOG 403 `ARCHIVE407` sebelum 422 (body salah) > 422. Penolakan tidak menyimpan sesi (jumlah sesi hanya +3 yang sah).

**D-2 (ED-1044) tertutup.** `scanned_at` = `DATETIME(6)`; urutan "scan terbaru dulu" pasti untuk beberapa scan per detik. `EXTRA-ORDER` (3 sesi x 6 scan sedetik) kini hijau. `R2-D2` (172 asersi):
- 17 scan acak (verified/not found/invalid berselang), maks 3+ scan per detik: DB `scanned_at` 26 karakter `Y-m-d H:i:s.uuuuuu`, **naik ketat** sesuai urutan scan; ada nilai satu-detik yang sama dengan mikrodetik berbeda.
- `/documents?result=scanned` (pagination 4 dan 100) = kebalikan urutan scan; filter verified/not_found/invalid = himpunan bagian dengan urutan sama; `all` = scan terbaru dulu lalu belum discan per `code` naik; `scanned_at` di response tetap `Y-m-d H:i:s` (di `/scan`, `/documents`, draft maupun terkonfirmasi); sama dengan DB pada tingkat detik.
- Scan duplikat (huruf besar) -> `is_duplicate:true`, baris lama, DB tak berubah.
- Jam mundur (scan terakhir diset `2099-01-01 00:00:00.500000` di DB) -> scan baru `...500001`, baris pertama; limpah (`23:59:59.999999`) -> detik berikutnya `.000000`; PUT (Back/Next) mempertahankan urutan.
- Confirm: `scanned_at` snapshot **identik persis** (mikrodetik) dengan draft; sesudah Confirm `scanned`/filter/`all` tetap urut sama (pagination 4); superadmin 1 membaca sesi terkonfirmasi dengan urutan sama.
- `R2-SCANVOL`: 100 scan beruntun: DB naik ketat, tabel urut benar, `show.counts.scanned` 100; scan rata-rata 0,20 dtk (10 pertama) vs 0,22 dtk (10 terakhir), maks 0,31 dtk (tanpa pelambatan).
- `R2-PARALLEL-DIFF`: 4 putaran x 8 scan **kode berbeda bersamaan**: semua 200, 32 baris, **0** `scanned_at` kembar, tabel = urutan `scanned_at` menurun, Confirm 200 dan urutan sama.

**Updater / skema berubah (`R2-SCHEMA`, 22 asersi).** DB QA: `scanned_at` `datetime(6)`, nullable, default NULL; kolom waktu lain (`created_at`, `selected_at`, `confirmed_at`, `updated_at`) tetap `datetime` tanpa pecahan. DB scratch yang meniru DB yang sudah menjalankan versi awal (`scanned_at DATETIME` + 3 baris termasuk NULL): sesudah Updater presisi 0 -> 6, data utuh (`...10:00:00.000000`, NULL tetap NULL), run ke-2 tanpa perubahan (SHOW CREATE dan data identik), skema akhir identik dengan DB QA, mikrodetik `...02.123456` tersimpan, DB scratch dibuang.

## Defect

Tidak ada defect terbuka. Defect ronde 1 (D-1 ED-1038, D-2 ED-1044) dinyatakan tertutup di atas.

## Isolasi tenant & permission

- **Permission** (tiap endpoint baru): lihat AC-3 di atas: Opname Document -> 2xx; role 29 dan 6 -> 403 `GE0114` (`parameter` = nama permission); tanpa token 401; hak folder 403 `ARCHIVE407` (`AC-6`, `EXTRA-PERM`). `QA_USER2` tidak bisa login di `api_sidomaju`, jadi "user tanpa hak" = QA_USER dengan role/lokasi sementara (role 6, 29, 1, 2, 3; lokasi SMR/JOG), selalu dipulihkan persis dan diverifikasi.
- **Isolasi tenant (butuh >= 2 DB): tidak bisa diverifikasi lewat HTTP.** `QA_DBS` hanya berisi `api_sidomaju`, sehingga langkah isolasi (`$t->isolation`) tidak berlaku. Dilengkapi tinjauan kode (diulang di ronde 2 terhadap perubahan baru): `OpnameService`, `OpnameController`, 5 FormRequest dan 3 model tanpa properti `static`, `Cache::`, `global`, atau `setConnection`; `StoreRequest::authorize()` membuat `new OpnameService` per request; `scanTime()` hanya memakai parameter dan query per request. Risiko kebocoran antar-request di fpm/RR rendah; tetap perlu satu uji dua-DB bila developer menyiapkan DB kedua.

## Yang tidak bisa diverifikasi (dan kenapa)

1. Tukar lisensi sungguhan (tanpa Salesman Activity -> menu hilang, route FE tertolak, endpoint 403 `GE0114`): `PROFILES` kosong; sesuai EPIC K-2 b hanya baris seed yang dibuktikan (AC-2), sisanya manual Gate 2 epic.
2. Isolasi tenant dua DB (di atas).
3. Bagian FE dari AC-3, AC-8, AC-22 serta AC-4, 7, 13, 14, 17: tahap FE. Kamera nyata (AC-13): manual Gate 2.

## Catatan (bukan cacat)

- N-1 (ronde 1) Bentrok BR-23 dihitung pada detik yang sama (`confirmed_at >= selected_at`): aman; skenario QA menyisipkan jeda 1-2 dtk.
- N-2 (ronde 1) `ARCHIVE414` untuk root memakai teks tetap "All Archive" di id_ID juga (sesuai kontrak).
- N-3 Asersi ED-1025 `AC-1` ("kunci `config.php` terbesar") usang sejak Updater ED-1026 ditambahkan; dilaporkan sama seperti ronde 1, QA ED-1025 sebaiknya melonggarkannya ("terdaftar"). Berkas ED-1025 tidak diubah.
- N-4 (ronde 1) Draft hari lain masih bisa di-PUT, Confirm tetap 400 `ARCHIVE415`; sesuai BR-23.
- N-7 `spec.md` §3 (tabel Model data) masih menulis `scanned_at DATETIME NULL`; implementasi dan kontrak §6 + "Catatan implementasi" memakai `DATETIME(6)`. Hanya ketidakselarasan dokumen, tidak menghalangi (kontrak = acuan FE); rapikan saat spec diperbarui.
- N-8 Log Laravel pada jendela run ED-1026 (11:44:30-11:47:49, 11:49-11:51:23, 12:00-12:03:15 WIB) bersih dari error aplikasi dan tanpa jejak `Opname*` (0 kecocokan di berkas log). Error di log hanya dari (a) proses websocket pra-ada (`websockets_statistics_entries` tidak ada, tiap menit, tak terkait), dan (b) 11:54-11:56 WIB = regresi ED-1025 yang sengaja memicu karakterisasi ED-1071/ED-1070 (kolasi `latin1` pada karakter di luar Windows-1252, 500 pra-ada pada route Archive lama, dilacak tiket; ED-1026 menghindarinya dengan menolak `code` non-Latin-1 di 422). Bukan dari endpoint opname.

## Riwayat ronde

- Ronde 1 (2026-10-07): 19 AC `[BE]` + bagian BE 3 AC `[BE+FE]`; 30/32 skenario PASS; FAIL: D-1 (ED-1038, id 31 karakter -> 404 bukan 422), D-2 (ED-1044, urutan scan sedetik acak); regresi ED-1024 26/26, ED-1025 30/31 (asersi usang, N-3).
- Ronde 2 (2026-10-07): D-1 dan D-2 tertutup; kontrak §2/§6 dan Updater (scanned_at `DATETIME(6)`, jalur MODIFY) diperiksa; 37/37 skenario PASS (5 baru); regresi ED-1024 26/26, ED-1025 30/31 (N-3 sama); DB dibiarkan seperti ditemukan.
