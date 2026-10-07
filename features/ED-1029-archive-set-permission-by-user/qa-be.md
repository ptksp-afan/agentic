---
key: ED-1029
scope: be
round: 2
verdict: PASS
counts: {pass: 10, fail: 0, manual: 0, not_verifiable: 1}
be_server: fpm
---
## Ringkasan

Scope BE ED-1029 (subtask ED-1035 baca, ED-1041 simpan), ronde 2. Semua 10 AC [BE] (AC-4 sampai AC-13) PASS; AC-20 bagian BE
tetap PASS. Dua temuan ronde 1 ditangani:

- **D-2 (PUT paralel -> 500 `1062`): TERBUKTI DIPERBAIKI** untuk kedua penulis `archive_permissions` (hanya dua pemanggil
  `saveUserRow`/`replaceFolderRows` di kode: `UserPermissionService::update` dan `ArchiveService::update`; dicek lewat grep).
  Sekitar 4.000 request paralel di 4 putaran penuh + 3 run tambahan: **0 x 5xx**, semua 200 `ARCHIVE207` (kecuali penolakan sah
  yang diharapkan di X-7 e/f), tepat satu baris per (folder, user), hasil akhir = satu kiriman utuh, riwayat `permission` hanya untuk
  perubahan nyata. Log Laravel: 0 kecocokan `1062` / deadlock / lock wait selama seluruh ronde.
- **D-1 (id non-latin1 -> 500 kolasi): dipindah ke "Dilacak di luar item" (ED-1071)** sesuai keputusan orkestrator; probe jadi
  karakterisasi bertag ED-1071 (X-1, hijau; gagal dengan sengaja "ED-1071 berubah" bila perilaku bergeser). AC-6 dinilai pada kasus
  ASCII/latin1: PASS.

Verdict PASS dengan dua catatan terbuka yang harus tetap terlihat: (1) 500 kolasi pada id non-Windows-1252 MASIH ADA (16 dari 16
kasus X-1; juga di keluaran regresi ED-1025 X-12 dan X-13/ED-1070), dilacak di ED-1071/ED-1070, bukan diperbaiki di item ini;
(2) isolasi tenant tidak bisa diuji (`QA_DBS` hanya `api_sidomaju`), dihitung `not_verifiable`.

- Server BE: php-fpm (`BE_SERVER=fpm`, `pm.max_children = 5`, jadi paralel efektif 5 request), kode terbaru terbaca tanpa reload.
- Skenario permanen: `features/ED-1029-archive-set-permission-by-user/qa/`: `qa_lib.php`, `scenario1-read.php` ... `scenario4-robust.php`
  dan BARU `scenario5-concurrency.php`. Run penuh: `work/qa29-r2-full.txt`, JSON `work/qa-http/ED-1029-20261007233815.json`:
  **PASS=22, FAIL=0, SKIP=1 (X-ISO), BLOCKED=0**.
- Regresi item lain (skenario di working tree): ED-1024 26/26; ED-1025 30 PASS + 1 usang; ED-1026 34 PASS + 3 usang; ED-1027 20 PASS
  + 1 SKIP; ED-1028 22/22. Tidak ada regresi nyata.
- Penolakan izin/classifier: tidak ada.
- Kode aplikasi, spec, kontrak tidak diubah QA. Yang diubah QA hanya `qa/scenario4-robust.php` (X-1 jadi karakterisasi; X-5 dipindah)
  dan `qa/scenario5-concurrency.php` (baru).

## Hasil per AC

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-4 | `scenario1-read` AC-4: GET `{B}` oleh A (lokasi SMR) di fixture P/C1/C2/Q/R/R1/QC/N/L/LC/M + dokumen + folder nonaktif; himpunan folder & urutan DFS vs SQL; kunci node vs kontrak; nilai B & `is_folder_permission` vs DB; `access` A | PASS | 435 asersi, `work/qa29-r2-full.txt`. 200 `ARCHIVE200`; L/PX/DP tidak ada; LC di level 0 (`id_archive_parent`=L); R1 di bawah R; P manage true, Q false, QC (pembuat) 4 hak; `children` hanya pada folder ber-subfolder |
| AC-5 | `scenario1-read` AC-5: role 1 dan 2 | PASS | 77 asersi: L tampil, jumlah & urutan = semua folder aktif SQL, `access` 5 kunci true di semua node |
| AC-6 (GET) | `scenario1-read` AC-6: id acak, user `is_active` 0/2/null, EN dan ID, 18 id aneh (SQL, panjang, path, spasi, latin1 `ü`) | PASS | 72 asersi: 404 `ARCHIVE440` "User isn't found"/"User tidak ditemukan", tanpa `result` |
| AC-6 (PUT) | `scenario2-write` AC-6b: id tidak ada / nonaktif dengan body valid, invalid, ditolak, `[]` | PASS | 47 asersi: 404 `ARCHIVE440` mendahului 422/403; data tidak berubah |
| AC-6 (kontrol latin1/Win-1252) | `scenario4-robust` X-1, bagian kontrol: `ü`, `é`, `€` di path GET, path PUT, body `id_archive`, body `[P, x]` | PASS | 12 dari 12 = 404 (`ARCHIVE440` path, `ARCHIVE400` body), data tidak berubah (`work/qa29-r2-x1.txt`) |
| AC-6 (non-latin1) | X-1, bagian karakterisasi ED-1071 | DILACAK DI LUAR ITEM | 16 dari 16 = 500; lihat bagian "Dilacak di luar item". Tidak dihitung terhadap AC-6 (keputusan orkestrator) |
| AC-7 | `scenario2-write` AC-7: PUT `[P V+S, C1 V]`, B punya baris di P/C2/Q, B2 di P/C1 | PASS | 52 asersi: 200 `ARCHIVE207`, `result={id_user}`; (P,B) diganti, (C1,B) dibuat, baris lain persis sama; riwayat `permission` +1 hanya P dan C1 oleh A; GET `archives/{P}` (02) memuat baris B sama; dua arah (tab Permission <-> per user) |
| AC-8 | `scenario2-write` AC-8: hapus, hapus ulang, nilai sama, `[]`, camelCase | PASS | 32 asersi: baris terhapus (+1 riwayat); ulang/sama/`[]` = 200 tanpa riwayat dan tanpa perubahan data (`updated_at` utuh) |
| AC-9 | `scenario2-write` AC-9 (EN+ID): C2 (Off), R (Off), kombinasi dengan P/C1 sah | PASS | 57 asersi: 400 `ARCHIVE441` `parameter`=[nama]; atomik: tidak ada baris/riwayat tersimpan |
| AC-10 | `scenario2-write` AC-10 (EN+ID): update/delete/store tanpa view; baris sah ditulis lebih dulu | PASS | 166 asersi: 400 `ARCHIVE411`, rollback penuh (tanpa baris dan riwayat sisa) |
| AC-11 | `scenario2-write` AC-11: Q, N, QCh, L, dokumen, id tidak ada, folder nonaktif, 15 kasus 422, 5 `permissions` salah bentuk | PASS | 178 asersi: 403 `ARCHIVE408` (mendahului 422), L 403 `ARCHIVE407`, 404 `ARCHIVE400`, id ganda/flag salah 422; tanpa 5xx; data tidak berubah |
| AC-12 | `scenario2-write` AC-12: R Off, R1 On | PASS | 14 asersi: PUT R1 200 dan tersimpan; bersama R (Off) = 441 atomik |
| AC-13 | `scenario3-access` AC-13 dan AC-13b (B nyata = QA_USER2) | PASS | 31 + 27 asersi: B buka P 403 `ARCHIVE407` -> A beri View -> 200 -> dicabut -> 403 lagi; B (View saja) tidak boleh PUT (408) |
| AC-20 (bagian BE) | `scenario3-access` AC-20 dan X-PERM | PASS (bagian BE) | 17 + 157 asersi: 403 `GE0114` `parameter`="Update Folder", data tetap, bukan 500; tidak ada permission baru di DB (id maks 1133). Bagian FE/lisensi nyata = scope fe / Gate 2 epic |
| D-2 recheck | `scenario5-concurrency` X-5, X-6, X-7 (lihat bagian berikut) | PASS | 22 PASS di run penuh; 4 putaran + 3 run tambahan, 0 x 5xx |

AC [FE] (1-3, 14-19) bukan milik stage ini.

## Verifikasi D-2 (PUT paralel pada baris yang sama)

Pola uji: `curl_multi`, semua request dikirim bersamaan, keadaan awal dipulihkan di antara putaran lewat probe tulis, hasil dibaca di DB
(read-only), riwayat dihitung dari delta entri `permission` di kolom `archives.history`.

| Skenario | Penulis | Putaran (request) | Yang dicek | Hasil |
|---|---|---|---|---|
| X-5 | PUT `user-permissions/{B}` | (a) nilai berbeda dari kosong 6x10; (b) nilai sama dari kosong 4x12; (c) sama dengan tersimpan 2x12; (d) hapus (semua 0) dari baris ada 3x10; (e) hapus lawan tulis 4x12 = 210 | semua 200 `ARCHIVE207`; tepat 1 baris; nilai akhir = satu kiriman; delta riwayat: (b) tepat 1, (c) tepat 0 dan `updated_at` utuh, (d) tepat 1; (e) akhir = tidak ada atau V2 | PASS |
| X-6 | PUT `archives/{P}` tab Permission (`folder_permissions`), `saveUserRow` bersama | (a) 5x8; (b) 4x12; (c) 12; (d) dua user sekaligus 3x8; (e) ganti-semua {B} lawan {B2}, dua keadaan awal 4x12 = 172 | semua 200; 1 baris per user; (b) delta tepat 1; (c) tepat 0; (d) tepat 2 baris, delta 1; (e) akhir tepat himpunan {B} atau {B2} utuh | PASS |
| X-7 | campuran | (a) 5 penulis 1 + 5 penulis 2, nilai beda, 6x10; (b) nilai sama 4x12 (delta tepat 1); (c) banyak folder urutan berlawanan [F1,F2,F3] vs [F3,F2,F1] 4x12 + (c2) ditambah tab Permission pada F2 3x12; (d) dua user satu folder 3x12 (delta tepat 2); (e) id tidak ada (gap lock) + create-folder + PUT sah 3x18; (f) flag `is_folder_permission` dibalik saat PUT 4x12 = 330 | 0 x 5xx (tidak ada deadlock), (c) ketiga folder sama = satu kiriman utuh, (e) 404 `ARCHIVE400` / 200 sesuai, (f) hanya 200 atau 400 `ARCHIVE441` | PASS |

Jumlah: 4 putaran penuh X-5..X-7 (4 x 664 request, versi sebelum (f)) + 2 run X-7 (2 x 330) + run penuh akhir (712) = **sekitar 4.030
request, 0 x 5xx**, request terlama 1,9 s (tidak ada yang menggantung). Skenario asli ronde 1 (X-5, 8 paralel x 3 putaran = 24 request)
juga 24 x 200. Log Laravel (`work/qa29-r2-newlog.txt`, 2,7 MB, seluruh ronde): 0 x `1062`, 0 deadlock, 0 lock wait.

Batas bukti yang jujur: kode lama tidak bisa dipasang kembali oleh QA, jadi tidak ada kontrol negatif pada kode ini. Bukti bahwa pola
memicu balapan di mesin ini adalah ronde 1 (pola sama pada kode sebelum perbaikan: 6 dari 24 = 500 `1062`). Paralel efektif dibatasi
`pm.max_children = 5`.

## Defect

Tidak ada defect baru. D-2 tertutup (terverifikasi di atas). D-1 dipindah ke bagian berikut.

## Dilacak di luar item

**ED-1071 (pra-ada, modul-lebar; semula D-1 ronde 1): karakter di luar Windows-1252 pada id = HTTP 500 kolasi.**
- Endpoint ED-1029 yang kena: GET/PUT `user-permissions/{id}` (path, `id_user`) dan PUT body `permissions.*.id_archive`. 16 dari 16 kasus
  X-1 (`٣`, `日本`, `Ω`, `😀` x 4 bentuk request) = 500 `SQLSTATE 1267/1270 Illegal mix of collations (latin1_general_ci,IMPLICIT) and
  (utf8mb3_unicode_ci,COERCIBLE)`. Bingkai di log: `UserPermissionService.php:26` (`findUser`), `:172` (`assertCanManage`), `:46`
  (`show`), `UserPermissionController.php:24`. Akar yang sama di endpoint lama modul (`archives/{id}`, `archives/history/{id}`, select
  users `selected_id`) dan di ED-1025 X-12.
- Aman: data tidak berubah pada semua respons >= 400 (diperiksa per kasus); kontrol Latin1/Windows-1252 (`ü`, `é`, `€`) = 404 yang benar.
- Karakterisasi X-1 hijau sekarang; bila ED-1071 mengubah perilaku, X-1 gagal dengan sengaja "ED-1071 berubah: ..." (perbarui jadi
  asersi tidak-5xx dan hapus tag).
- Catatan untuk tiket: badan respons 500 memuat teks `SQLSTATE` (mesin QA memakai mode debug; pastikan tidak terjadi di produksi).
- Juga terlihat di keluaran regresi (bukan kode ED-1029): ED-1025 X-12 (ED-1071) dan X-13 (ED-1070: `pagination`/`sorts`/`search`
  salah bentuk pada `GET archives`), karakterisasi sengaja.

## Isolasi tenant & permission

- Permission (X-PERM, 157 asersi, ulang di run penuh ronde 2): tanpa token 401 `GE0111`; role tanpa `Update Folder` (6, 16, 18, 19, 20,
  25, 29) 403 `GE0114` `parameter`="Update Folder" untuk GET, PUT sah/invalid/`[]`/ke user tidak ada (403 mendahului 404), data dan
  jumlah `archive_permissions` tidak berubah; role dengan `Update Folder` (1, 2, 3, 5, 26) GET 200 dan PUT `[]` 200.
- Superadmin (AC-5b, 49 asersi): role 1 dan 2 boleh PUT di folder On tanpa hak khusus; folder Off tetap 441; riwayat atas nama pemanggil.
- Isolasi tenant (X-ISO): **TIDAK BISA DIVERIFIKASI**, `QA_DBS` hanya `api_sidomaju` (SKIP otomatis). Tinjauan kode ronde 2: tambahan
  perbaikan (`lockFolders`) tidak memakai `static`/singleton/cache, kunci hanya hidup di dalam transaksi satu request.

## Galeri e2e & regresi BE

E2E: bukan scope be. Regresi BE (skenario permanen item 1024-1028, working tree), run 2026-10-07 (`work/qa29-r2-reg-ED-10xx.txt`):

| Item | Hasil | Catatan |
|---|---|---|
| ED-1024 | PASS 26, FAIL 0 | bersih |
| ED-1025 | PASS 30, FAIL 1 | perhatian khusus (`ArchiveService::update` berubah): seluruh skenario tab Permission/penegakan/input/lokasi hijau, X-12 (ED-1071) dan X-13 (ED-1070) tetap hijau; AC-1 usang (Updater "kunci terbesar" `Updaters/config.php`, kini milik item lain), bukan regresi |
| ED-1026 | PASS 34, FAIL 3 | usang yang sudah diketahui: AC-1 (sama), AC-12 dan AC-15 (diubah sah oleh ED-1028); bukan regresi |
| ED-1027 | PASS 20, SKIP 1 | X-3 SKIP: isolasi tenant, satu DB |
| ED-1028 | PASS 22, FAIL 0 | bersih |

File QA item lain tidak diubah.

Review diff BE ronde 2 (hanya yang berubah sejak ronde 1; bukan gaya):
- `ArchivePermissionService::lockFolders($ids)`: `SELECT ... FOR UPDATE` per id pada `archives`, urut string tetap, sebagai query pertama
  transaksi di `UserPermissionService::update` dan `ArchiveService::update` (satu baris tambahan di awal). Query `authorize()` FormRequest
  berjalan sebelum transaksi (autocommit), jadi tidak membuat read view sebelum kunci. Urutan kunci tetap = tidak ada siklus (terbukti X-7 c,
  urutan berlawanan, 0 deadlock). Id yang tidak ada hanya menghasilkan gap lock singkat; diuji bersama create-folder (X-7 e), tanpa 5xx.
- Bentuk response, kode pesan, dan permission route tidak berubah: kontrak §1-§2 masih cocok (paragraf "simpan bersamaan bergiliran"
  di kontrak §2 sesuai perilaku: semua 200, hasil akhir = kiriman yang diproses terakhir, riwayat hanya untuk perubahan nyata).
- Perilaku fungsional yang sudah PASS ronde 1 tetap PASS di run penuh ronde 2 (AC-4..AC-13, X-PERM, X-2..X-4 tanpa perubahan angka asersi).

## Yang tidak bisa diverifikasi (dan kenapa)

- Isolasi tenant dua DB: `QA_DBS=api_sidomaju` saja; `X-ISO` sudah ada dan akan jalan bila DB kedua ditambahkan.
- Kontrol negatif D-2 (bukti bahwa uji ini menangkap kode lama): tidak mungkin tanpa mengubah kode aplikasi; bukti ronde 1 dipakai.
- Tukar lisensi sungguhan (AC-20): `PROFILES` kosong; cara seragam EPIC K-2 b dipakai (403 `GE0114`); tukar lisensi nyata = MANUAL Gate 2 epic.
- Perilaku B sebagai user acak: kredensial B tidak ada; dipakai QA_USER2 (adi) dan identitas QA_USER dalam role non-bypass.

Bersih-bersih (diperiksa dengan snapshot sebelum dan sesudah ronde, `work/qa-http/snap-*.json`): jumlah baris `archives` 49145,
`archive_permissions` 0, `archive_locations` 48033, `archive_documents` 49126, `user_roles` 126 kembali persis; hash md5 `archives`
(id, `updated_at`, `history`) dan `archive_permissions` sama dengan sebelum ronde; 0 baris `QA29-`; role QA_USER `[3]`, bahasa `ID`;
jurnal pemulihan `.restore-journal-q9.json` tidak tersisa. Semua token run di-log-out; `force_login` hanya mencabut token lain milik
user QA sendiri di DB QA.

## Riwayat ronde

- Ronde 1: 9 PASS, 1 FAIL (AC-6, D-1 non-latin1 -> 500) + D-2 (PUT paralel -> 500 `1062`) = FAIL; regresi 1024-1028 tanpa regresi nyata.
- Ronde 2 (ini): D-2 diperbaiki dan terverifikasi di kedua penulis (~4.030 request paralel, 0 x 5xx); D-1 dipindah ke ED-1071 dan jadi
  karakterisasi X-1; 10/10 AC [BE] PASS; run penuh 22 PASS + 1 SKIP; regresi bersih (4 asersi usang yang diketahui).
