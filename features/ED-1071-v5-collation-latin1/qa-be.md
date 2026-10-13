---
key: ED-1071
scope: be
round: 2
verdict: PASS
counts: {pass: 9, fail: 0, manual: 0, not_verifiable: 1}
be_server: fpm
---
## Ringkasan

BE `6a5c069bb` + 17 berkas ED-1071 belum di-commit (changed.txt, sama dengan ronde 1; fix D-1/D-2 ada di `MyHelper.php`, `PutInFolderRequest`, `HandOverRequest`, `ReceiveRequest`, `AddDocumentRequest`). `api_sidomaju` (tenant tunggal), `QA_USER` (role 3, bahasa ID; EN lewat pergantian bahasa sementara). php-fpm: `be-reload.sh` = no-op, kode baru terbaca.

- **D-1 (U+FFFD) tertutup**: 8 bentuk x 24 titik body, 14 body mentah (JSON `�` dan UTF-8 literal, form-urlencoded), 5 bentuk id x 9 titik id/path/query, search 6 bentuk JSON x 2 penulisan x root/folder x 8 bentuk, select (8 parameter) = 422 `GE0748` / 404 / 200 `total` 0 / `options` [], **0 respons 5xx, 0 tulisan, `description` tidak pernah `a?b`**. X-2 (seluruh BMP vs MySQL latin1): yang diterima helper (250) semuanya selamat di MySQL; satu-satunya selisih tinggal lima kontrol C1 yang sengaja ditolak (U+0081/008D/008F/0090/009D); U+FFFD tidak ada lagi di himpunan diterima.
- **D-2 (array + S_BAD) tertutup**: put-in/hand-over/receive `name` dan add-document `id_archive` dengan array berisi omega/CJK/emoji/e+U+0301/RTL/U+FFFD dan `["x"]` = 422 `errors.<field>` (28 dari 28); lebih luas: 35 bentuk non-teks x 24 titik body = 878 respons semuanya 4xx (868x422, 8x404, 2x400), 0 5xx.
- Suite ED-1071: **16/16 PASS** (14 skenario ronde 1 + R2-1 + R2-2 baru).
- Regresi BE 1024-1029, 1069: **hasil identik dengan ronde 1**: 0 kegagalan akibat ED-1071; 8 skenario gagal karena selisih data baseline developer (di luar prefiks QA, bukan kode ED-1071).
- Data uji dipulihkan persis (snapshot sebelum/sesudah, lihat bawah). Satu berkas artefak run tersisa karena penghapusan ditolak classifier (lihat "Penolakan").

## Hasil per AC

Perintah dasar: `"$PHP_BIN" scripts/qa-http/run.php ED-1071` (laporan `work/qa-http/ED-1071-20261013110320.json`, keluaran `work/qa71-r2-final.txt`; satu run penuh, 16 skenario, token 1 dibuat 1 log-out). Asersi lunak: semua kegagalan dikumpulkan; setiap respons 5xx otomatis gagal.

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | create-folder & PUT `name`/`description` x 5 S_BAD; teks GE0748 ID dan EN persis; data/riwayat tetap | PASS | `--only=AC-1`: 217 asersi, 39 respons semua 422 |
| AC-2 | rename folder dan dokumen S_BAD (dokumen 422, bukan 400 ARCHIVE419); kontrol 400 ARCHIVE419/ARCHIVE405 | PASS | AC-2: 171 asersi, {422:31, 400:2} |
| AC-3 | semua field #3 (body, array `.n`, `folder_permissions.n.id_user`, put-in, hand-over/receive, add-document, user-permissions `permissions.n`/`folders.n`); kembar/ARCHIVE402 + S_BAD tetap 422; tanpa tulis | PASS | AC-3: 675 asersi, {422:130, 400:3 kontrol} |
| AC-4 | id S_BAD (+ UTF-8 rusak) di query/path semua endpoint + select `id_archive_parent` + `user-permissions/{id}` | PASS | AC-4: 602 asersi, {404:202, 200:1 kontrol} |
| AC-5 | `archives?search=` 11 bentuk, root/folder, JSON escape/mentah; select `search`/`excepts`/`selected_id`/dst | PASS | AC-5: 1386 asersi, 332x200, `total` 0, `options` [] |
| AC-6 | 403 mengalahkan 422 (ARCHIVE407/408/410, GE0114, 7 role fixture) | PASS | AC-6: 655 asersi, 161x403 |
| AC-7 | S_OK `ü ’ — € ™`: create/PUT/rename 200, HEX cp1252, search menemukan, `Café`/`Cafe` ok, `CAFÉ` 400 ARCHIVE405, put-in/hand-over/receive by name | PASS | AC-7: 164 asersi, {200:33, 400:3, 404:1}. Tambahan R2-1: "?" biasa, mojibake latin1 sah `ï¿½` (U+00EF/00BF/00BD) tetap 200 dan HEX `EFBFBD`, `description` utuh, search menemukan (tidak ada negatif palsu dari penolakan U+FFFD) |
| AC-8 | regresi Opname/Verification (`opnames?search`, `opnames/{S_BAD}`, `verifications/`, `opnames/folders`, select, scan `code`) | PASS | AC-8: 120 asersi, {200:17, 404:18, 422:5} |
| AC-9 | tiga QA usang: ED-1025 X-12, ED-1029 X-1, ED-1069 AC-10 PASS; 0 respons 5xx; regresi 7 suite satu per satu (tabel bawah) | PASS (catatan: 8 skenario regresi lama berhenti di prasyarat baseline, bukan ED-1071) | `run.php ED-1025`: X-12 80 kasus {200:5, 404:20, 422:55}; `run.php ED-1029`: X-1 49 kasus {404:29, 422:20}; `run.php ED-1069`: AC-10 48 asersi PASS (id Sirilik/Omega/emoji/CJK = 404 ARCHIVE400) |
| Ulang D-1 (U+FFFD) | `scenario5-bypass.php` X-4 (16 titik body, 6 id, 6 search, select) dan `scenario4-robust.php` X-2 (seluruh BMP) | PASS | X-4: 143 asersi {422:16, 404:9, 200:10}; X-2: 63487 code point, diterima helper 250, selamat di MySQL latin1 255, ditolak-padahal-tersimpan hanya 5 kontrol C1 |
| Ulang D-1 diperluas | `scenario6-round2.php` R2-1 (lihat bawah) | PASS | R2-1: 1867 asersi, 582 respons {200:329 (kontrol + search/select), 404:45, 422:208}, 0 5xx |
| Ulang D-2 (array) | `scenario5-bypass.php` X-5 dan `scenario6-round2.php` R2-2 | PASS | X-5: 82 asersi, 25 respons semua 422 (dulu 20 dari 20 = 500); R2-2: 2532 asersi, 878 respons 4xx |
| AC-10 | FE, tahap QA FE | di luar tahap ini | - |

Skenario ronde 1 tetap lulus: X-1 (body form-urlencoded/JSON rusak, C1, NUL, 200 KB, bersarang; karakter khusus cp1252 utuh), X-3 (tinjauan diff), X-6 (hand-over `action` S_BAD: 200, `action` diabaikan controller, riwayat tanpa `?`).

### Skenario baru ronde 2 (`qa/scenario6-round2.php`, permanen, bisa diulang)

- **R2-1** (U+FFFD, 8 bentuk: sendirian, awalan, sufiks, tengah, ganda, campur `ü`, campur `€’`, spasi): (a) 24 titik body JSON (create 5, PUT 5, rename folder+dokumen, put-in 5, hand-over, receive, add-document, user-permissions `permissions.0|1`/`folders.0|1`) = 422 tepat di kunci itu dengan teks GE0748; setiap bentuk: snapshot semua kolom + jumlah 4 tabel sama dan `HEX(description)` folder uji tidak berubah; (b) 14 body mentah: JSON `�`, JSON UTF-8 literal `EF BF BD`, form-urlencoded, bentuk `name[]=`/`id_archive[]=`; (c) bahasa EN: teks GE0748 EN tepat di create dan PUT; (d) id: U+FFFD sendirian / sesudah id sah / sebelum / di tengah / ganda di `?id_archive=`, `archives/{id}` GET/PUT, `history/`, `rename/`, `delete/`, select `locations?id_archive_parent=` = 404 ARCHIVE400, `user-permissions/{id}` GET/PUT = 404 ARCHIVE440; (e) search `query`/`type`/`name`/bersarang/key/`showRelatedTransaction` x JSON ter-escape dan UTF-8 mentah x root/folder = 200 `total` 0; select users/locations `search`, `excepts[]` (1 dan 2 nilai), `except`, `selected_id`, `selected_id[]`, `exclude_id` = 200, `search` `options` []; (f) kontrol tanpa negatif palsu: "?" biasa, teks harfiah `�`, mojibake `ï¿½`.
- **R2-2** (non-teks): 35 bentuk (array/objek/bersarang berisi omega, CJK, emoji, e+U+0301, RTL, U+FFFD, teks U+FFFD; `["x"]`, `[]`, `{}`, 12345, 1.5, true, false) x 24 titik body = 878 respons semuanya 4xx; array di field bertipe teks = 422 dengan `errors.<field>` (atau `<field>.n`); tanpa tulis; keempat field D-2 x 7 bentuk array = 422 `errors.<field>`; teks omega/U+FFFD di empat field itu = 422 GE0748 tepat; kontrol teks `€’...` lolos penjaga (put-in/hand-over/receive: 4xx bisnis non-422; add-document 422 hanya dari aturan `exists`, bukan GE0748/tipe).

## Defect

Tidak ada. D-1 dan D-2 ronde 1 sudah tertutup (bukti di atas). Tidak ada respons 5xx pada jalur kode ED-1071 dalam seluruh run ED-1071 (asersi otomatis).

Catatan perilaku (bukan defect, sudah ada di Catatan implementasi spec):
- N-1 (rendah): akibat fix D-2, `name` JSON angka/array di put-in/hand-over/receive dan `id_archive` angka/array di add-document sekarang 422 (dulu angka diterima dan `["x"]` = 404). Menurut catatan developer FE tidak terpengaruh (`name` selalu teks dari scanner/input, `add-document` tidak dipanggil FE; `archive.api.js` hanya memakai endpoint put-in/hand-over/receive lewat modal; belum diperiksa e2e, tahap QA FE). String angka (`"12345"`) tetap lewat.
- N-2: spec #3 menyebut `folder_permissions.*.id_user` untuk create-folder dan PUT; `CreateFolderRequest` tidak punya aturan `folder_permissions` dan `ArchiveService` hanya membacanya di update (baris `show` hanya keluaran), jadi di create field itu diabaikan sepenuhnya (200, tidak ada yang tersimpan/dibandingkan) dan tidak perlu penjaga. Penjaga ada di PUT (diuji).
- N-3 (pra-ada, bukan ED-1071): `search` JSON dengan `\` di pola LIKE (karakter escape MySQL) tidak menemukan nama berisi `\`; tidak terkait karakter S_OK.

## Isolasi tenant & permission

- Isolasi: `QA_DBS` = `api_sidomaju` saja, tidak bisa diuji antar-DB. Pengganti: diff fix ronde 2 ditinjau ulang (`git diff` `MyHelper.php`, 4 Request, `Latin1Rule.php`): hanya `strpos` pada string, aturan `string`, tanpa properti statis, `Cache::`, `config()`, global, locale/mbstring; `isLatin1`/`Latin1Rule::failsIn` murni fungsi. X-3 (13 asersi) PASS. ED-1027 X-3 dan ED-1029 X-ISO SKIP (alasan sama).
- Permission: tidak ada endpoint baru. 403 mengalahkan 422 (AC-6, 161x403 dengan role fixture sementara, dipulihkan); jalur diizinkan 200 (AC-7). QA_USER2 (role 5) tidak dipakai untuk 403 karena baseline role 5 memegang semua hak Archive + Opname Document (F-1).
- Kontrak: `contract: null`; bentuk 422 GE0748 = bentuk 422 lain (`message` + `errors`); `GE0748` ada satu kali di `en_EN.php` dan `id_ID.php` dengan teks persis spec (X-3).

Tinjauan kebenaran diff fix ronde 2 (bukan gaya): `MyHelper::isLatin1` menolak U+FFFD lewat `strpos($value, "\xEF\xBF\xBD")` sesudah `mb_check_encoding`, sebelum bolak-balik Windows-1252 (pemeriksaan byte pada UTF-8 aman: UTF-8 self-synchronizing, mojibake `ï¿½` = `C3 AF C2 BF C2 BD` tidak terkena, terbukti oleh kontrol R2-1); rekursif untuk key dan nilai array. `string` ditempatkan sebelum `Latin1Rule` dalam rantai `bail` (tipe salah 422 sebelum penjaga dan `exists`); put-in `name` masuk `hasValidTypes()` sehingga array hanya kena aturan tipe tanpa `ArchiveSourceRule`/`UniqueNameRule` yang berkueri; `Latin1Rule::failsIn` dipakai put-in untuk jalur ringkas. Urutan 404/403 -> 422 -> 400 tetap; tidak ada tulis sebelum validasi, rollback tidak relevan; tanpa Updater/skema/kolom v3. `OpnameService::isLatin1` (lubang U+FFFD yang sama) tidak diubah sesuai spec (K-5 a); `opnames?search={"query":"U+FFFD"}` = 500 tetap bug pra-ada yang diketahui.

## Yang tidak bisa diverifikasi (dan kenapa)

1. **Regresi, 8 skenario berhenti di asersi baseline** (hasil sama dengan ronde 1; bukan defect ED-1071, tidak ada jalur kode ED-1071 pada asersi gagal, semuanya pemeriksaan baris DB/role):
   - ED-1025 AC-25: `role_permissions(role 3, 1140).permission_name` NULL (diharapkan 'Folder Permissions').
   - ED-1026 AC-3, ED-1027 X-8 dan X-9, ED-1028 AC-12, AC-14, AC-28: prasyarat "role asli QA_USER2 (role 5) tanpa Opname Document" tidak terpenuhi; baseline `role_permissions(role 5, 1139)` ada (permission_name NULL), catatan F-1.
   - ED-1027 X-10: 343 baris `archives` terverifikasi milik developer (`updated_by='sam'`); total search `isVerified=1` 350 tidak lebih kecil dari root 7.
   Skenario berhenti di asersi keras pertama sehingga sisa isinya tidak terjalankan; kode yang diuji (middleware izin, filter verifikasi) tidak diubah ED-1071. Perlu keputusan developer: sesuaikan prasyarat QA lama atau pulihkan baseline.
2. Isolasi antar-tenant (satu DB), lihat atas.
3. `search` JSON dengan UTF-8 rusak (`{"query":"x%C3%28"}`) = 500 "Search request is invalid": ED-1070 (ditunda). Bug pra-ada lain yang terlihat di suite lama (bukan jalur ED-1071, hanya dicatat): ED-1025 X-13 (`pagination`/`sorts` tak sah = 500, karakterisasi ED-1070), ED-1069 X-4(a) (`showRelatedTransaction` pada folder bernama nomor transaksi = 500 "Trying to get property"), ED-1027 X-13 (PUT `column_display_settings` identik di detik yang sama = 500, `ColumnDisplaySettingService::update`), `select locations?search[]=x` = 500 (tanpa S_BAD), `id_archive_parent=0` (ED-1124).
4. Body JSON dengan byte UTF-8 rusak tidak bisa sampai ke validator (PHP men-decode jadi input kosong, hasil 422 aturan wajib atau 400); jalur form-urlencoded rusak dibuktikan 422 GE0748.

## Data uji, pemulihan, QA lama yang usang

- Data sendiri: `archives` `QA71-%` (+ `archive_*`), role `QA71-ROLE-%`, sementara `user_roles`/`employee_locations`/`is_all_location`/bahasa QA_USER. Semua dibuang/dipulihkan di `finally` dan diperiksa bersih (`q71_assert_clean`).
- Snapshot read-only sebelum dan sesudah seluruh pekerjaan (22 tabel/kunci: hitungan + `CHECKSUM TABLE`): **sama** untuk `archives` (49148), `archive_permissions` (6), `archive_documents` (49126), `archive_locations` (48037), `archive_opname*` (0), `roles` (27; QA% 0), `user_roles`, `role_permissions` (role 3 dan 5 hash sama), `column_display_settings` (361, hash sama), `updater_logs` (99), `employees`, `employee_locations`, `permissions`, `archives QA%` 0. Satu-satunya beda: `users` checksum (`updated_at` QA_USER/QA_USER2 karena login/logout API, sama seperti ronde 1).
- DU-3 a: grant sementara `Opname Document` dan baris bersama (`column_display_settings`, `updater_logs`) dipulihkan oleh suite 1027/1028 sendiri; hash sesudahnya sama.
- Dihapus di luar data sendiri: **tidak ada**.
- Berkas QA: baru ronde 2 `ED-1071/qa/scenario6-round2.php`; yang diubah ronde 1 (AC-9) tidak diubah lagi. Komentar kepala `ED-1029/qa/scenario8-rev3-reject.php:7` ("harapan 500 sekarang, milik ED-1071") masih usang (tidak diubah, dicatat).
- Penolakan izin/classifier (tidak diakali): (1) menghapus `features/ED-1027-archive-status-verifikasi/qa/.restore-journal-q27-user2.json` (artefak run saya: ED-1027 X-9 berhenti di asersi baseline sebelum membersihkannya; isinya = baris `user_roles` role 5 yang sudah sama dengan DB, hash `user_roles` sama) ditolak classifier ("Irreversible Local Destruction"); berkas **tetap ada** (untracked). Tidak berbahaya: run ED-1027 X-9 berikutnya memulihkan dari jurnal itu (isi sama) lalu membuangnya; orang lain boleh menghapus manual. Pada ronde 1 berkas yang sama dihapus tanpa masalah. (2) Satu perintah `sed`/`grep` pembaca `ArchiveService.php` ditolak; dibaca ulang lewat tool Read pada berkas yang sama (berkas non-rahasia).

## Regresi BE (AC-9), satu suite per giliran

| Suite | Hasil | Catatan |
|---|---|---|
| ED-1024 | 26 PASS / 0 FAIL | |
| ED-1025 | 39 PASS / 1 FAIL | AC-25 baseline (role 3 `permission_name` NULL); X-12 PASS (0 5xx) |
| ED-1026 | 38 PASS / 1 FAIL | AC-3 baseline (role 5 memegang Opname Document) |
| ED-1027 | 25 PASS / 3 FAIL / 1 SKIP | X-8, X-9, X-10 baseline; X-3 SKIP (satu DB) |
| ED-1028 | 21 PASS / 3 FAIL | AC-12, AC-14, AC-28 baseline (prasyarat role 5) |
| ED-1029 | 40 PASS / 0 FAIL / 1 SKIP | X-1 PASS (0 5xx); X-ISO SKIP (satu DB) |
| ED-1069 | 16 PASS / 0 FAIL | AC-10 PASS |
| ED-1071 | 16 PASS / 0 FAIL | 14 lama + R2-1 + R2-2 |

## Riwayat ronde

- Ronde 1 (2026-10-13): AC-1..AC-9 PASS (8 skenario regresi gagal karena baseline); 2 defect (D-1 U+FFFD lolos `MyHelper::isLatin1` = 500 di 27 titik dan `description` tersimpan `a?b`; D-2 array berisi S_BAD di put-in/hand-over/receive `name` dan add-document `id_archive` = 500); verdict FAIL karena 500 terlihat.
- Ronde 2 (2026-10-13): D-1 dan D-2 tertutup (X-4, X-5, X-2 + skenario baru R2-1/R2-2); AC-1..AC-9 PASS; regresi identik ronde 1 (8 gagal baseline); 0 defect baru; verdict PASS.
