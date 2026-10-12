---
key: ED-1029
scope: be
round: 1
verdict: PASS
counts: {pass: 26, fail: 0, manual: 0, not_verifiable: 2}
be_server: fpm
---
## Ringkasan

Ronde 1 dari **revisi 3** (subtask ED-1128 [BE], ED-1130 [QA]; kode: 4 berkas BE di atas HEAD 581569981, semua NEW pada `changed.txt`:
`UserPermission/UpdateRequest.php`, `ArchivePermissionService.php` (`saveFolderFlag` baru, EPIC K-5 b), `ArchiveService.php` (memakai `saveFolderFlag`),
`UserPermissionService.php`). **Tanpa defect.** Semua AC [BE] (4-13, 21-23, 27, 32-35) dan sisi BE dari AC [BE+FE]/[FE] yang diserahkan ke QA BE
(AC-9, 14, 19, 20, 25, 28, 31, 36, 37) **PASS**. Sisi FE/e2e AC-29..31, 36, 37 = tahap fe.

- Suite ED-1029 akhir: **41 skenario: 40 PASS, 1 SKIP (X-ISO), 0 FAIL, 3.099 asersi**, dua kali berturut-turut hijau (`work/qa29r3/full-r2.txt`,
  `work/qa29r3/full-final.txt`; JSON `work/qa-http/ED-1029-20261012163353.json`). Rev3 menambah **11 skenario baru (1.049 asersi)**; 30 skenario lama (29 ber-asersi, 2.050 asersi, + X-ISO SKIP)
  = regresi (body tanpa `folders` = perilaku lama): 28 hijau tanpa perubahan, 2 diperbarui (AC-13b usang oleh data developer F-1; X-1 diperluas untuk `folders`, O-6). Sebelum pembaruan, suite lama
  = 28 PASS / 1 FAIL (AC-13b) / 1 SKIP (`work/qa29r3/pre-full.txt`), jadi **rev3 tidak mengubah perilaku lama**.
- Server: php-fpm, kode terbaru terbaca tanpa reload. **Tanpa 500 baru.** Log Laravel selama sesi (15:52-16:33 lokal) dipilah per entri: 128 galat dari `UserPermission*`
  semuanya X-1 (kolasi 1267/1270, id non-latin1: 16 di run awal + 28 x 4 run; milik ED-1071, termasuk 12 kasus `folders` baru per run, O-6); 80 kolasi + 12 bentuk
  `pagination`/`sorts`/`search` = ED-1025 X-12 (ED-1071) dan X-13 (ED-1070) dari run ED-1025 penuh; 14 penolakan OAuth = token yang sengaja dicabut `force_login`. Tidak ada galat lain.
- Regresi EPIC K-5 b: ED-1025 QA BE `--only=AC-5,AC-38` **PASS** (61 + 165 asersi; `work/qa29r3/ed1025-ac5-ac38.txt`). Ekstra: suite ED-1025 penuh 38 PASS / 2 FAIL
  (`work/qa29r3/ed1025-full.txt`): X-12 (diketahui, ED-1071) dan AC-25 (usang, bukan dari diff ini; lihat "Skenario usang milik item lain").
- Isolasi tenant: **tidak bisa diverifikasi** (`QA_DBS` hanya `api_sidomaju`; X-ISO SKIP).
- Data: hanya data berprefiks QA (`QA29-`, role `QA29-ROLE-*`), semuanya dibuang oleh skenario; hash 12 tabel sebelum vs sesudah **identik** (bagian Data).
- Kode aplikasi, spec, kontrak, Jira tidak disentuh. `qa/e2e/*` tidak disentuh (tahap fe).

## Hasil per AC

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-4..13 (lama, 10 AC) | skenario 1-3 tidak diubah (kecuali AC-13b) dijalankan ulang sebagai regresi dengan kode rev3 | PASS | AC-4 492, AC-5 83, AC-6 72, AC-6b 47, AC-7 52, AC-8 32, AC-9 57, AC-10 166, AC-11 173, AC-12 14, AC-5b 49, AC-13 34, AC-13b 44 asersi |
| AC-21, 22, 23, 27 (lama) | `scenario6-rev.php` ulang | PASS | 203 + 44 (AC-21b) + 50 + 37 + 24 asersi; urutan 440/407/422/400/441/411 lama utuh |
| AC-9 (rev, sisi BE) | AC-9 lama + AC-34 d/d2: hak C2 (Off) tanpa `folders` dan dengan `folders: []` = 441 parameter C2; bersama P sah tidak tersimpan | PASS | 57 + 90 asersi; snapshot data+riwayat+jumlah baris identik |
| AC-14 (sisi BE) | AC-4 + AC-34 a: GET node memuat `is_folder_permission`, nilai B tersimpan tetap tampil di folder Off, `access.manage_permission` true di semua node | PASS | 492 + 90 asersi |
| AC-32 | `scenario7-rev3-flag.php` AC-32 | PASS | 118 asersi. F (Off, tanpa baris): PUT `{B}` `folders [F:1]` + `[F:View]` = 200 `ARCHIVE207` (`result.id_user`=B); F flag 1, `updated_by`=A; (F,B) View; (F,A) 1/1/1/1 (`created_by`/`updated_by`=A); tepat 1 baris A dan 2 baris F; riwayat +1 `permission` oleh A; folder lain utuh. Kirim ulang = F tanpa perubahan. G (A View): baris yang **sama** (`id_archive_permission` tetap) dinaikkan 1/1/1/1, satu baris, tanpa (G,B). X (A pembuat) dan Y (A sudah 1/1/1/1: baris tidak ditulis ulang). H/K/Z sekaligus + hak B 0000 = tanpa baris; riwayat +1 per folder. Off->On->Off->On: baris A turun ke View lalu naik lagi, tetap 1 baris. Superadmin 1 dan 2: (H2/K, superadmin) 1/1/1/1, `updated_by` dan riwayat = user login. camelCase `idArchive`/`isFolderPermission` diterima |
| AC-33 | AC-33 | PASS | 42 asersi. Target = user login: `folders [F:1]` + `[F:1/1/0/1]` -> (F,A) 1/1/0/1 satu baris (tidak dinaikkan); `folders [K:1]` tanpa hak -> K tanpa baris sama sekali; (G,A) View tidak dinaikkan; hak 0000 = tanpa baris; folder sudah On tanpa baris otomatis |
| AC-34 | AC-34 (+AC-34b) | PASS | 90 + 22 asersi. `folders [P:0]` -> flag 0, (P,B) dan (P,A) tak tersentuh, tanpa baris otomatis, riwayat +1; K-10 b `[P:0]` + `[P:V+U]` -> (P,B) V+U, riwayat +1; 0000 pada folder yang dimatikan menghapus baris; `[C2:1]` + `[C2:View]` = 200 (+ baris A otomatis); `[C2:0]` (sudah Off) + `[C2:View]` = 400 `ARCHIVE441` parameter C2, data identik; 441 tetap bila folder lain dinyalakan/dimatikan di request yang sama (atomik); `[Q:1]` (sudah On) dan `[C2:0]` (sudah Off) = 200 tanpa tulisan/riwayat/baris. AC-34b: flag NULL di DB dibaca Off |
| AC-35 | `scenario8-rev3-reject.php` AC-35, AC-35b, X-8; X-1 | PASS | 487 + 28 + 32 asersi. D/E (en+id, 9 bentuk) = 403 `GE0114` parameter "Folder Permissions" tanpa `result` (mendahului 440/422/407); QA_USER2 dengan role preset tanpa FP juga 403 (AC-35b). A: 440 > 407 (permissions ATAU folders, tanpa parameter, mendahului 422) > 422 (22 bentuk: id ganda, flag 2/-1/"abc"/null/"", hilang, id int/array/"", folders string/angka/null/""/elemen string/null/[]/objek, tanpa `permissions`) > 400 `ARCHIVE400` (dokumen, tidak ada, nonaktif; juga bersama F sah) > 441 > 411; `folders [JB:1]`/`[JB:0]`/`[JC:1]` (A tanpa View) = 200; profil M 200; superadmin 1/2 `[L:1]` = 200 + baris otomatis; **atomik: 411 di tengah transaksi (flag + baris otomatis sudah ditulis) -> snapshot identik** (2.13-2.14). Fuzz id 16 bentuk tanpa non-latin1 x 3 mode: 0 x 5xx |
| AC-19 (sisi BE) | AC-19 | PASS | 34 asersi. P dimatikan lewat `PUT archives/{P}` (jalur method bersama): `updated_by`=A, baris tidak dihapus; Save hak P + C1 sah = 400 `ARCHIVE441` (en+id, pesan memuat nama), snapshot identik; `folders [P:1]` + hak -> 200, (P,A) V+U dinaikkan 1/1/1/1, satu baris |
| AC-20 (sisi BE) | AC-20 (judul/komentar diperbarui, logika dinamis) | PASS | 35 asersi; role tanpa FP dicari dari DB (6, 29; role 5 sudah memegangnya, F-1); tanpa id permission literal di semua `qa/*.php` termasuk berkas baru |
| AC-25 (sisi BE) | AC-25 lama + AC-37b | PASS | 21 + 36 asersi |
| AC-28 (sisi BE) | skenario usang milik sendiri diperbarui; semua skenario dijalankan ulang; X-1 tetap karakterisasi | PASS | lihat "Skenario milik sendiri"; X-1 49 kasus: 21 x 404, 28 x 500 (ED-1071), data utuh |
| AC-31 (sisi BE) | AC-33 butir 1 | PASS | body `folders [F:1]` + `permissions [F:1/1/0/1]` oleh A untuk A: flag 1, (F,A) 1/1/0/1; sisi e2e = tahap fe |
| AC-36 | AC-36 (dua pengguna nyata: A dan QA_USER2) | PASS | 41 asersi. Sesudah `folders [F:1]` untuk B2: `GET archives/{F}` flag 1, `folder_permissions` A 1/1/1/1 + B2 View; QA_USER2 (tanpa baris) buka F = 403 `ARCHIVE407`; diberi View = 200; dicabut = 403; F dimatikan dari halaman: tab Izin Off, baris tetap, QA_USER2 = 200; dinyalakan di tab Izin: halaman ini F On (nilai B2), QA_USER2 403 lagi; list: perisai (`is_folder_permission` baris F) 1/0/1 mengikuti. Sisi e2e = tahap fe |
| AC-37 | `scenario9-rev3-concurrency.php` AC-37, AC-37b + regresi penuh + ED-1025 | PASS | AC-37: 6 jenis putaran x 3 + stres 40 request = 19 putaran, 136 request, **0 x 5xx**, terlama 2,6 s. Dua user nyata (A + QA_USER2, role aslinya memegang FP): `folders [F:1]` identik 3 x 2 user -> tepat satu baris otomatis, milik pengaktif pertama (berganti antar putaran), hasil + status tiap request = SATU urutan serial dari model semua permutasi (2-110 hasil sah per jenis); hak berbeda, tab Izin (`PUT archives/{F}`) bersamaan, saklar bolak-balik (441 hanya bila urutan serial memungkinkan), dua folder urutan berlawanan (tanpa deadlock), tiga folder; tanpa baris ganda, riwayat JSON valid. X-5/6/7 ulang: 210 + 172 + 330 request, 0 x 5xx. AC-37b (EPIC K-5 b via `PUT archives/{id}`): flag saja = riwayat `permission` saja dan tanpa baris otomatis (O-3); nilai sama = tanpa tulisan; detail + flag satu request = `update` + `permission`; flag + `folder_permissions` = persis kiriman; flag 0 + `[]` = semua baris dihapus; hasil sama dengan PUT user-permissions. ED-1025 `--only=AC-5,AC-38` PASS. Sisi e2e = tahap fe |

## Defect

Tidak ada.

Pengamatan (bukan defect):
- Kontrak vs respons aktual cocok: 200 `ARCHIVE207` `{result:{id_user}}`, 403 `GE0114` `{message, code, parameter:"Folder Permissions"}`, 403 `ARCHIVE407` tanpa `parameter`, 400 `ARCHIVE441`
  `parameter:[nama]` (en+id), `folders: null` / bukan array = 422, urutan penolakan = kontrak §2. `ARCHIVE408` tidak pernah dikirim (pencatat kode di setiap skenario).
- `is_folder_permission` diterima sebagai `"1"`, `true`, `"01"`, `1.0` (aturan `in:0,1` Laravel membandingkan sebagai string) dan flag mengikuti nilainya; tidak ada 5xx. Sama dengan aturan flag `is_view`.. yang sudah ada.
- Id dengan spasi/tab/newline/NUL di tepi dipangkas middleware `TrimStrings` dan diperlakukan sebagai id yang sah (200 untuk F), bukan 404; hanya F yang berubah.
- `updated_by` bertipe varchar(30) (pra-ada): username > 30 karakter akan gagal tulis di semua penulis flag (juga `PUT archives/{id}`); tidak diuji.
- Kode: tidak ada `static`/cache/`$GLOBALS` baru di diff (grep); satu transaksi, `lockFolders` id gabungan tetap query pertama; hak otomatis hanya di `UserPermissionService::update`.
- `role_permissions.permission_name` NULL pada 312 dari 3.794 baris (data developer F-1, termasuk role 3 dan 5 untuk Folder Permissions): middleware membaca lewat id sehingga tidak berdampak pada ED-1029.

## Isolasi tenant & permission

- Permission (PUT `folders`): D (Update Folder tanpa FP) dan E, en+id, 9 bentuk = 403 `GE0114` "Folder Permissions"; QA_USER2 diberi role preset tanpa FP = 403; pemegang FP
  (A, M tanpa Update Folder, superadmin 1 dan 2, QA_USER2 dengan role aslinya) = 200 dan baris otomatis atas nama user login masing-masing. Tidak ada endpoint baru.
- Isolasi tenant: **TIDAK BISA DIVERIFIKASI** (`QA_DBS` hanya `api_sidomaju`; dua DB tidak tersedia). Tinjauan kode: diff tidak menambah state statis/cache; `context()` dan folder
  dihitung per request.

## Skenario milik sendiri yang diperbarui (AC-28, AC-37)

| Berkas | Perubahan | Sebab |
|---|---|---|
| `qa/scenario3-access.php` AC-13b | QA_USER2 sementara diganti role preset `QA29-ROLE-DW` (List Archive, View Folder, Update Folder, Allow Access Website; tanpa Folder Permissions), `user_roles` dipulihkan persis (jurnal `.restore-journal-q9u2.json`, diverifikasi) | **Data developer F-1**: role 5 kini memegang Folder Permissions, jadi asersi "B tanpa FP = 403 GE0114" gagal (200). Bukan perubahan perilaku rev3. Tanpa `Allow Access Website` middleware mencabut token user bukan-karyawan (QA_USER2 tipe 1): preset memuatnya |
| `qa/scenario3-access.php` X-PERM, AC-20 | hanya judul/komentar (logika sudah dinamis lewat nama permission) | role 5 tidak lagi "tanpa FP" |
| `qa/scenario4-robust.php` X-1 | +12 kasus `folders` (4 non-latin1 x 3 bentuk = 500 karakterisasi ED-1071, 3 latin1 x 3 = 404 `ARCHIVE400`); aturan kode ARCHIVE400 untuk label "body" | O-6: probe non-latin1 `folders` hanya di X-1 |
| `qa/qa_lib.php` | `q9_recover` juga memulihkan jurnal user kedua; `q9_u2_swap/restore`; profil `DW` | pendukung AC-13b |
| baru | `qa/qa_lib_r3.php`, `scenario7-rev3-flag.php` (AC-32, 33, 34, 34b, 19, 36), `scenario8-rev3-reject.php` (AC-35, 35b, X-8), `scenario9-rev3-concurrency.php` (AC-37, 37b) | rev3 |

Tidak ada skenario lama yang perlu diubah karena perilaku rev3 (semua kecuali AC-13b hijau sebelum dan sesudah).

## Skenario usang milik item lain (DICATAT, TIDAK diubah)

| Item | Skenario | Sebab |
|---|---|---|
| ED-1025 | `qa/scenario9-rev-seed-fuzz.php:168` (AC-25, "DB: role 3 memegang Folder Permissions (1140)") dan `:172` | memfilter `role_permissions.permission_name = 'Folder Permissions'`; di data developer baris (role 3, Folder Permissions) memiliki `permission_name` NULL (join lewat id tetap benar). Bukan regresi |
| ED-1025 | `qa/scenario9-rev-seed-fuzz.php` X-12 (kasus `rename name` omega/cjk/emoji/aksen/rtl 200 -> 500; kini 80 dari 80 kasus 500) | milik ED-1071 (diketahui). Selisih 5 kasus rename berasal dari commit ED-1069 (878314df7: `RenameFolderRequest`/`UniqueNameRule`), bukan dari diff ini |

## Yang tidak bisa diverifikasi (dan kenapa)

1. **Isolasi tenant**: `QA_DBS` hanya satu DB.
2. **Jalur user customer** (middleware `permission_v5` mem-bypass customer; cadangan `GE0114` di `authorize()`/`assertCanManage`): tidak ada user customer ber-kredensial; tinjauan kode saja (tidak berubah sejak ronde lalu).
3. Sisi FE/e2e AC-29..31, 36, 37 dan penukaran lisensi AC-20 = tahap fe / Gate 2 epic.

## Data

- Dibuat dan dibuang oleh skenario sendiri: folder `QA29-*` (fixture ~30 folder per skenario, 120 anak di X-8), baris `archive_permissions`/`archive_locations`/`archive_documents` terkait,
  role `QA29-ROLE-A/M/D/E/DW`. Pemeriksaan akhir tiap skenario: `archives` berprefiks = 0, jumlah `archive_permissions` kembali, role fixture = 0.
- Hash sebelum vs sesudah seluruh ronde (`work/qa29r3/counts-before.json` vs `counts-final2.json`): jumlah baris 16 tabel, md5 `archives` (id, flag, `updated_at`, `updated_by`),
  `archive_permissions`, `role_permissions`, `user_roles`, `employees`, `users` (bahasa, aktif) **identik**. Tidak ada baris selain milik QA yang dihapus atau di-reset; data developer F-1 tidak diubah.
- Diubah sementara dan dipulihkan persis: role/lokasi/bahasa QA_USER (jurnal), `user_roles` QA_USER2 (AC-13b, AC-35b), `users.is_active` target B (satu kasus 2.17). Efek samping tak terhindarkan:
  `force_login=1` runner mencabut token lain QA_USER; satu percobaan AC-13b tanpa `Allow Access Website` membuat middleware mencabut token QA_USER2 dan menulis `is_login=0` (+`updated_at`) pada user itu
  (keadaan sama dengan log-out biasa). Penolakan izin/classifier: tidak ada.

## Riwayat ronde

- Ronde 1-2 (2026-10-07, run awal): R1 FAIL (id non-latin1 -> 500 = ED-1071; PUT paralel -> 500 `1062`); R2 PASS setelah `lockFolders`.
- Revisi 2026-10-08/09 (FB-4b, FB-9, FB-5, K-15 b): ronde 1 PASS, 17 AC [BE] PASS, 0 defect, 2 not_verifiable.
- Revisi 3 (2026-10-12), ronde 1 (ini): PASS, 0 defect; 11 skenario baru (1.049 asersi), regresi 29 skenario, ED-1025 AC-5/AC-38 PASS.
