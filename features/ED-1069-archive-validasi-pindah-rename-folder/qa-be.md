---
key: ED-1069
scope: be
round: 1
verdict: PASS
counts: {pass: 12, fail: 0, manual: 0, not_verifiable: 1}
be_server: fpm
---
## Ringkasan

QA BE ronde 1 untuk Bug ED-1069 (tanpa subtask, `contract: null`). Diff BE yang diuji: 13 berkas `M` + `FolderOnlyRule.php` baru di `ksp-erp` (baseline `265c0d1bd`, belum di-commit), dibaca penuh. Server `fpm`, tanpa reload; respons sudah memuat kode baru (teks `ARCHIVE404` baru, `ARCHIVE419`).

- **12 AC `[BE]` (AC-1 s.d. AC-12) PASS, 0 FAIL.** AC-13 dan AC-14 bertag `[FE]`: milik stage `scope: fe`, tidak dinilai di sini.
- Skenario permanen di `qa/` (5 berkas `scenario*.php` + `qa_lib.php`): **16 skenario, 821 asersi, semua PASS** pada run penuh terakhir (`work/qa69/full2.txt`). Tidak ada HTTP 500 dari kode item ini pada jalur yang diuji.
- **Tidak ada defect pada item ini.** Ada 4 temuan di luar item (bagian "Bug di luar item"); salah satunya (B-3, rename + karakter non-latin1 kini 500) adalah efek samping aturan baru item ini yang melebarkan ED-1071: tidak dihitung FAIL sesuai arahan, tetapi **perlu keputusan** (ED-1071 harus mencakup rename, dan kalimat di spec ED-1071 "rename -> 200 tersimpan `?`" tidak lagi benar).
- Regresi ED-1024..ED-1029: semua PASS kecuali **ED-1025 X-12** (karakterisasi ED-1071 yang usang karena B-3). ED-1025 X-8 dibalik oleh QA ini (AC-12) dan lulus.
- Isolasi tenant antar-DB tidak bisa diverifikasi (`QA_DBS` = 1 DB); diganti tinjauan diff (X-5).
- Penolakan izin/classifier: 0.

## Hasil per AC

Perintah: `"$PHP_BIN" scripts/qa-http/run.php ED-1069 [--only=AC-n]`. Asersi lunak = semua kegagalan satu skenario dikumpulkan lalu dilaporkan sekaligus. "Data tetap" = snapshot semua kolom `archives` + `archive_documents` sebelum/sesudah identik. `QA_USER` = role 3; "tanpa hak" = baris `archive_permissions`/`is_folder_permission` pada folder uji atau role fixture `QA69-ROLE-*` yang menggantikan role `QA_USER` sementara.

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | `scenario1` AC-1: A>B, dokumen D di A. put-in [B], [D], [B,D], `name`=B/D, id pendek, id berkutip ke id tak ada; create-folder dan `PUT archives/{B}` ke id tak ada | PASS | 7 put-in = 422 `errors.id_archive_parent` tanpa kode bisnis; create/PUT 422; snapshot A,B,D identik (history D tetap 1 entri); tanpa baris baru; pembanding put-in ke root = 200 `take out` |
| AC-2 | `scenario1` AC-2: induk = dokumen D, folder nonaktif N (root), NA (nonaktif di bawah sumber A): put-in [X], [A], `name`; create-folder; `PUT archives/{B}`. Nama kembar + induk nonaktif | PASS | Semua 422 (put-in [A] ke NA = 422, bukan 400); create-folder tanpa baris; induk tidak sah + nama kembar = 422 (bukan 405); pembanding induk sah = 200 di tiga endpoint; snapshot identik |
| AC-3 | `scenario1` AC-3: A>B>C>E, NB nonaktif di bawah A, NC di bawah NB, X, D. 12 kasus: [A]->C/B/E/NC (lewat folder nonaktif)/A, `name`=A->B/A, [X,A]->C, [A,X]->C, [B]->C, [B,C]->E, [C]->C | PASS | 12x 400 `ARCHIVE404`, teks ID "Tidak bisa dipindahkan ke dirinya sendiri atau subfoldernya"; EN (bahasa user diganti sementara) "Can't be moved into itself or its subfolder"; kedua berkas bahasa memuat teksnya; snapshot 8 baris identik; urutan: 404 menang atas 406, 406 untuk tujuan sah |
| AC-4 | `scenario2` AC-4: `PUT archives/{A}` induk = A / C / B / E / NC; B induk = B / C / E; nama baru ikut dikirim; induk sekarang dikirim ulang (juga induk lama nonaktif); `null`; tanpa kunci; kelompok izin saja; pindah sah; siklus setelah pindah | PASS | 9x 400 `ARCHIVE404` (+2 sesudah pemindahan), A tetap root, tidak ada entri `update`, nama tidak ikut berubah; kirim ulang = 200 (+ `update`); induk lama nonaktif dikirim ulang = 200; kelompok izin saja = 200 tanpa entri history; A->B dan A->E (turunan setelah pemindahan) = 400 |
| AC-5 | `scenario1` AC-5: X>A>B>C, D di A: [C]->root, [C]->A, [B]->X, D->C, [A]->X, `name`=D->B, [C]->B lalu `PUT archives/{C}` induk=A, `GET archives?id_archive=C` / `=A` | PASS | Semua 200 `ARCHIVE204`/`ARCHIVE207`; history `take out` (+`from_folder`), `place`, `move` (+`from_folder`/`to_folder`), `update`; `breadcrumbs` GET C = X > A > C (value + label), A = X > A; isi A = [C] |
| AC-6 | `scenario2` AC-6: A>B>C (C On, `QA_USER` View saja), E anak C, R On View saja + S, R2/S2 Off. put-in [A]->C, `name`=A->C, ->id acak, `PUT A` induk=C / id acak, put-in [C]->E, rename R->nama S / unik, R2->nama S2. Role fixture tanpa `Update Folder` (tetap `Folder Permissions`): PUT induk=A / acak / B=B, rename. Pembanding: Store/Update di C diberikan | PASS | put-in [A]->C = 403 `ARCHIVE410` (parameter = [nama C]); id acak = 422; `PUT A` induk=C = 403 `ARCHIVE410`; [C]->E = 403 `ARCHIVE408`; rename R = 403 `ARCHIVE408` (juga ke nama unik), R2 (Off) = 400 `ARCHIVE405`; role tanpa Update Folder = 403 `GE0114` parameter "Update Folder" (induk acak pun 403, bukan 422), kelompok izin saja = 200; data tetap; dengan hak di C kasus yang sama menjadi 400 `ARCHIVE404` |
| AC-7 | `scenario3` AC-7: P (A, B, N nonaktif, dokumen), X, AR/BR di root. rename B->A, BR->AR, B->A + body `id_archive_parent` = X/null/""/acak/A, B->nama dokumen, `name` array/objek/""/null/5/true/tanpa name, A->A, B->N, B->unik | PASS | 400 `ARCHIVE405` (teks ID) di P dan di root, semua varian body induk tetap 400 (induk dari DB); 6 bentuk salah = 422 `errors.name`; A->A 200; B->N 200; B->unik 200 `ARCHIVE206` + history `rename` bertambah 1; snapshot sebelum penolakan identik. Catatan: nama beda huruf besar/kecil = 400 (kolasi `latin1_general_ci`) |
| AC-8 | `scenario3` AC-8: P1/S, P2/S, Z, T, U. put-in [S1,S2]->T, [S2,S1]->T, ->root, [S1,S2,Z]->T, ->P1; [S1]->T; [S2]->T; [S2]->U; [S2,S2]->P2 | PASS | 5x 400 `ARCHIVE405`, snapshot 7 baris identik; [S1]->T = 200 (+`move`); [S2]->T sesudahnya 400 (aturan lama); [S2]->U 200; id ganda [S2,S2] = 200 |
| AC-9 | `scenario3` AC-9: rename dokumen D (di folder) dan D0 (root) ke nama unik / nama folder F (kembar) / array; id acak; folder F | PASS | 400 `ARCHIVE419` (kembar pun 419 bukan 405), teks ID "Hanya folder yang bisa diganti nama", EN "Only folders can be renamed", kedua berkas bahasa; array = 422; id acak 404 `ARCHIVE400`; rename folder 200; baris D, D0 identik |
| AC-10 | `scenario4` AC-10: 3 folder berlokasi (semua, 1, 2 lokasi) + nonaktif; `GET select/document-archive/archive/locations` tanpa/kosong/semua/1/2 lokasi, `except`, induk tak ada (id acak, "x", kutip, 400 digit), `id_archive_parent[]=x`, `[a]=x`, `[0][b]=x`, `[]=` | PASS | tanpa induk/induk kosong/induk semua lokasi = 3 lokasi aktif (DB); induk 1 lokasi = lokasinya; 2 lokasi = keduanya; `except` menyaring; induk tak ada = 404 `ARCHIVE400` "Dokumen tidak ditemukan"; 4 bentuk array = 422 `errors.id_archive_parent` |
| AC-11 | `scenario4` AC-11: (1) 58 dokumen nyata aktif (tipe 6, 7, 8) dan 42 dokumen uji yang menunjuk transaksi NYATA untuk ketujuh tipe: respons `related_transactions` dibandingkan dengan `ArchiveService::relatedTransaction` kode sebelum perubahan (`git show HEAD:`, dimuat sebagai kelas pembanding, dipanggil lewat reflection). (2) Folder F dengan 7 dokumen yatim (tipe 6, 7, 8, 9, 29, 31, 222) + 1 tipe 999 | PASS | (1) 100 dokumen identik dengan baseline, 94 di antaranya punya transaksi terkait; tipe 9/29/31/222 hanya punya dokumen nyata `is_active = -1` di DB ini, jadi dicakup lewat dokumen uji. (2) `GET archives?id_archive=F&search={"showRelatedTransaction":true}` = 200, delapan baris, `related_transactions === []` di tiap baris; tanpa `showRelatedTransaction` kunci tidak ada |
| AC-12 | Ubah `ED-1025/qa/scenario4-select-misc.php` X-8 bagian induk = diri sendiri (karakterisasi 200 + siklus -> asersi 400 `ARCHIVE404` + induk tetap root, pemulihan defensif tetap); bagian search ED-1070 tidak diubah. `run.php ED-1025 --only=X-8` sebelum dan sesudah | PASS | Sebelum: FAIL "PUT id_archive_parent = diri sendiri kini HTTP 400" (usang, bagian search tetap 500 = sesuai). Sesudah: `X-8 PASS 15 asersi` (`work/qa69/ed1025-x8-after.txt`). Bagian X-13/X-12 tidak diubah |

Skenario tambahan (bukan AC, semuanya PASS): **X-1** matriks izin endpoint yang berubah; **X-2** regresi aturan yang tidak berubah + urutan antar-400; **X-3** ketahanan tipe input; **X-4** dua petunjuk orkestrator (karakterisasi); **X-5** tinjauan diff.

## Defect

Tidak ada defect pada ED-1069 (semua AC `[BE]` PASS, tidak ada 500 pada jalur AC).

Catatan kebenaran kode (tinjauan diff, bukan defect): urutan penolakan sesuai spec dan "Catatan implementasi" (404 -> 403 di `authorize()` -> 422 -> 400; antar-400 put-in: `ARCHIVE404` -> `ARCHIVE403` -> `ARCHIVE406` -> `ARCHIVE405`, semuanya dibuktikan di AC-3 dan X-2); induk tidak sah melewati aturan 400; `PUT archives/{id}` menilai induk hanya bila berubah dan hanya di kelompok detail; tanpa Updater/tabel/permission baru (14 berkas sesuai `changed.txt`); `php -l` 14/14 OK; tidak ada `static`/`Cache::`/`config([...])` baru. Pengecekan ganda (`parentFolderRule` + `isValidParent`, `ARCHIVE419` di rule dan di service) tidak berbahaya: hanya dua query kecil ekstra per request.

## Bug di luar item

| # | Temuan | Reproduksi | Status |
|---|---|---|---|
| B-1 | **500** list "transaksi terkait" bila ada **folder** yang namanya sama dengan nomor transaksi terkait sebuah dokumen. Sudah ada sebelum item; guard null ED-1069 tidak mencakupnya. Terjangkau dari UI: pengguna cukup membuat folder bernama mis. nomor faktur | (1) dokumen uji tipe 7 dengan `id_transaction` nyata di folder F, (2) buat folder di root bernama persis salah satu nomor di `related_transactions` dokumen itu (di sini `SI-JOG/2511/00002`), (3) `GET api/v5/document-archive/archives?id_archive=F&search={"showRelatedTransaction":true}` -> **500** `Trying to get property 'transaction_type' of non-object`, `ArchiveService.php:701`. Akar: kueri akhir `relatedTransaction()` (`whereIn('name', ...)`) tanpa filter `type`, lalu `$archive->document->transaction_type` pada folder | Bug baru, belum bertiket. Dikarakterisasi di `scenario5` X-4 (bila kelak bukan 500, skenario sengaja gagal: ubah jadi asersi 200) |
| B-2 | `POST documents/put-in` pada sumber **nonaktif** (`is_active=0`, mis. hasil `delete`) membuatnya **aktif lagi** | dokumen dan folder `is_active=0`, `put-in` [keduanya] ke folder aktif -> 200 `ARCHIVE204`, kedua `is_active` = 1. Kode pra-ada `DocumentService.php:68` (`$file->is_active = 1`) | Pra-ada (juga dicatat dev BE). Perlu keputusan apakah disengaja (pemulihan lewat scan) atau tiket. Dikarakterisasi di X-4 |
| B-3 | **Rename + karakter di luar latin1** (Yunani, CJK, emoji, aksen gabung, RTL) kini **500**; dulu 200 dan tersimpan `?`. Efek samping aturan baru `UniqueNameRule` di `RenameFolderRequest` (item ini) yang membandingkan `name` dengan kolom `latin1_general_ci` -> akar ED-1071 | `PUT archives/rename/{id}` `name` = `<nama>Ω` -> 500 `SQLSTATE[HY000] 1267 Illegal mix of collations ... select exists(select * from archives where name = ...)`; karakter latin1 (`€`) tetap 200. Folder tidak berubah | Bukan FAIL item (akar ED-1071, Archive sudah 500 di create/PUT). **Rename harus masuk cakupan ED-1071**: `Latin1Rule`/tanpa pembanding juga di `RenameFolderRequest`; kalimat spec ED-1071 "rename -> 200 tersimpan ?" kini usang |
| B-4 | Select lokasi: `id_archive_parent=0` (string "0") dibaca kosong oleh `empty()` -> 200 semua lokasi (bukan 404). Id non-latin1 -> 500 (ED-1071 butir 5 sudah menjanjikan 404 `ARCHIVE400` di sana) | `GET select/document-archive/archive/locations?id_archive_parent=0` -> 200 | Kecil, tanpa 500. "0" bukan id yang realistis; non-latin1 = ED-1071 |

Skenario usang milik item lain (dicatat, tidak diubah): **ED-1025 X-12** (milik ED-1071) usang karena B-3: kasus `rename name omega/cjk/emoji/aksen-gabung/rtl` kini 500 (karakterisasi menunggu 200); dari 80 kasus kini semuanya 500. Skenario ED-1070 (ED-1025 X-8 bagian search, X-13) tetap lulus (masih 500 seperti karakterisasinya). Tidak ada skenario usang lain di ED-1024, 1026, 1027, 1028, 1029.

## Isolasi tenant & permission

- **Permission** (`scenario5` X-1, role fixture + user nyata): tanpa token 401 di 5 endpoint; role hanya `List Archive` = 403 `GE0114` di put-in, create-folder, rename, `PUT` (juga untuk payload yang akan 422/400, jadi middleware mendahului validasi); `Move Archive` saja dan `Add Document` saja = put-in tervalidasi (422 induk acak, 400 `ARCHIVE404`, 200 sah), create/rename/PUT 403; `Add Folder` saja = create-folder tervalidasi, put-in 403; `Update Folder` saja = rename (400 `ARCHIVE405`/`ARCHIVE419`) dan `PUT` (400 `ARCHIVE404`, 422) tervalidasi, put-in dan create 403; select lokasi tanpa permission khusus (rute tidak memakai `permission_v5`): 200/404. `QA_USER2` bind tanpa `force_login`: put-in induk acak = 422, select induk acak = 404; sesi di-log-out. Hak folder (ED-1025 rev) diuji di AC-6.
- **Isolasi tenant antar-DB: tidak bisa diverifikasi** (`QA_DBS` = `api_sidomaju` saja). Pengganti: `X-5` memindai baris yang ditambahkan di 14 berkas (diff `git diff HEAD` + berkas baru utuh) untuk `static`, `Cache::`, `config([`, `$GLOBALS`, `setDefaultConnection`, `ini_set`: 0 temuan; 14/14 `php -l` OK. `BE_SERVER=fpm` tanpa state antar-request. Ulangi saat ada dua DB tenant.

## Galeri e2e & regresi BE

Galeri e2e: tidak berlaku (scope be; AC-13/14 di stage fe).

Regresi BE, satu suite per giliran, sesudah skenario item ini (log di `work/qa69/reg-<key>.txt`):

| Item | Hasil | Skenario / asersi | Catatan |
|---|---|---|---|
| ED-1024 | 26 PASS, 0 FAIL | 26 / 841 | - |
| ED-1025 | 39 PASS, **1 FAIL** | 40 / 3.533 (+ X-12) | X-8 lulus sesudah dibalik (AC-12). **X-12 usang** (karakterisasi ED-1071, lihat B-3); bukan FAIL item ini |
| ED-1026 | 39 PASS, 0 FAIL | 39 / 3.251 | - |
| ED-1027 | 28 PASS, 1 SKIP, 0 FAIL | 29 / 25.508 | `X-3` SKIP: isolasi tenant, 1 DB |
| ED-1028 | 24 PASS, 0 FAIL | 24 / 3.309 | - |
| ED-1029 | 29 PASS, 1 SKIP, 0 FAIL | 30 / 2.066 | `X-ISO` SKIP: 1 DB |

## Yang tidak bisa diverifikasi (dan kenapa)

- Isolasi tenant antar-DB (satu DB di `QA_DBS`); diganti tinjauan diff X-5.
- Siklus yang sudah ada di data lama (K-7 a): sengaja tidak dibuat di DB bersama (breadcrumb `with('parents')` rekursif tak berujung, 38 s lalu 500 menurut spec); item hanya mencegah siklus baru, dan siklus baru tidak bisa dibentuk lewat 3 endpoint (AC-2/3/4).
- Tipe 9/29/31/222 tidak punya dokumen aktif nyata di `api_sidomaju`; dicakup lewat dokumen uji yang menunjuk transaksi nyata (AC-11 bagian 1).
- Teks pesan dua bahasa diuji pada `ARCHIVE404` dan `ARCHIVE419` (ID dan EN); teks `ARCHIVE405`/`ARCHIVE400` ID saja (tidak berubah).

## Data uji dan pemulihan

- Hanya baris buatan sendiri yang dihapus: folder/dokumen berawalan `QA69-` (beserta `archive_documents`, `archive_permissions`, `archive_locations` mereka), satu folder bernama nomor transaksi nyata (`SI-JOG/2511/00002`, dicatat id-nya di jurnal `.tracked-ids-q69.json`, dihapus lewat id), dan role fixture `QA69-ROLE-*` (+ `role_permissions`). Tidak ada folder, dokumen, transaksi, atau tabel `archive_opname*` milik developer yang diubah atau dihapus; tidak ada data lain dihapus.
- `user_roles`/`language` `QA_USER` diganti sementara (role fixture, bahasa EN) lalu dipulihkan persis dan diverifikasi dengan snapshot (jurnal `.restore-journal-q69.json`); sesudah semua run: role `[3]`, bahasa `ID`.
- Pemeriksaan akhir (run terpisah, sebelum run penuh terakhir): `archives` 49.148 (22 folder, 49.126 dokumen) = jumlah awal; 0 baris `QA69`, 0 `QA69TX`, 0 role `QA69`, 0 izin `QA69`, 0 folder aktif senama dalam satu induk, 0 induk = diri sendiri.
- Berkas di luar `features/ED-1069.../qa/` yang diubah: `features/ED-1025-archive-folder-permission/qa/scenario4-select-misc.php` (hanya bagian induk = diri sendiri di X-8 + judulnya; AC-12).
- Semua token yang dibuat runner di-log-out (`masih aktif: 0`).

## Riwayat ronde

- Ronde 1 (2026-10-10): 12/12 AC `[BE]` PASS, 16 skenario / 821 asersi, 0 defect item; 4 temuan di luar item (B-1 s.d. B-4); regresi ED-1024..1029 PASS kecuali ED-1025 X-12 (usang, ED-1071).
