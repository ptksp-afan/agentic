---
key: ED-1025
scope: be
round: 3
verdict: PASS
counts: {pass: 17, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

QA BE ronde 3 (terbatas: cek ulang kegagalan ronde 2, lalu regresi penuh) untuk Archive - Folder Permission per folder. Seluruh 17 AC `[BE]` **PASS**; AC-23 bagian BE PASS, tukar lisensi
sungguhan **MANUAL (Gate 2 epic)** (`PROFILES` kosong). AC `[FE]` (AC-3, 17-21) milik scope fe. Tidak ada defect di item ini; tidak ada 5xx di luar dua keluarga bug pra-ada yang dilacak di tiket lain
(karakterisasi sengaja, lihat "Dilacak di luar item").

- **D-3 (ronde 2) terbukti diperbaiki**: `id_locations` berisi elemen array/objek di daftar >= 2 elemen pada PUT `archives/{id}` dan POST `archives/create-folder` = **422**, data tidak berubah
  (X-11: 14/14 kasus 422, kontrol dua lokasi valid = 200 dengan 2 baris `archive_locations`).
- **LocationRule (ED-1024) tidak berubah perilakunya** untuk input yang valid/skalar, dan tipe salah kini 422 (bukan 500/400/200). Tiga lapis bukti:
  1. *Diferensial in-process* `LocationRule` HEAD (`git show HEAD:...`, kelas disalin) vs berkas sekarang: 9450 kombinasi (3 mode: create, update induk dari data, update induk semua lokasi; 7 nilai
     `id_archive_parent`; 15 nilai `is_all_location`; 30 nilai `id_locations`): **0 selisih pada 7725 kombinasi semua-skalar** (daftar valid, duplikat, kosong/null, id tak valid, ARCHIVE402). Selisih hanya
     pada 1725 kombinasi dengan elemen non-skalar: HEAD melempar `ErrorException` "Array to string conversion" (245, = 500) atau ARCHIVE402 (1480), kini aturan mengembalikan `true` dan aturan tipe yang menolak
     (`work/qa-runs/ed1025-r3-locrule-diff.txt`).
  2. *Aturan FormRequest asli* (`UpdateRequest`/`CreateFolderRequest::rules()` + validator) pada 832 kombinasi non-skalar x induk: **832 = 422, 0 lolos, 0 exception** (jadi pengembalian dini `true` di
     LocationRule tidak bisa dipakai menghindari ARCHIVE402) (`work/qa-runs/ed1025-r3-locrule-nonscalar.txt`).
  3. *HTTP* X-14 (skenario permanen baru): 473 kasus = 43 kasus x induk {lokasi tertentu {L1,L2}, {L1}, semua lokasi, tanpa induk} x mode {PUT induk dari data, PUT induk dikirim, create-folder}:
     harapan 200 x120, ARCHIVE402 (400) x72, 422 x281, **0 respons 5xx**; setiap penolakan: snapshot target (baris `archives`, `archive_locations`, `archive_permissions`) sama dan tidak ada folder baru;
     setiap 200: set lokasi tersimpan sesuai (daftar menggantikan lewat sync, duplikat dilipat, kosong/null/tanpa kunci tidak menyentuh). Uji mutasi: dua harapan sengaja dibalik -> X-14 FAIL (6 pelanggaran
     terlapor), dikembalikan.
- Pertanyaan khusus orkestrator: `is_all_location` bertipe salah (`[x]`, `{a:b}`, `[[x]]`, `[1]`, `[]`) dan `id_locations` bertipe salah (`[[x]]`, `[L1,[x]]`, `[[x],L3]`, `{a:[x]}`, `[[]]`, dst.) di bawah induk berlokasi tertentu
  = **422**, bukan 500; `all=[x]` + `id_locations=[L3]` (di luar induk) tetap 422, tidak 200. Perilaku pra-ada yang tidak berubah dan dicatat: `is_all_location=2` di bawah induk = 400 ARCHIVE402 (LocationRule mendahului `in:0,1`);
  tanpa induk = 422; `'abc'` = 422.
- **D-4 dan D-5 dipindah ke "Dilacak di luar item"** (ED-1071 dan ED-1070); X-12 dan X-13 kini **karakterisasi** perilaku pra-ada bertag tiket (pola X-8): suite tetap hijau sampai tiket mengubahnya, lalu gagal
  dengan sengaja ("ED-1071/ED-1070 berubah").
- Skenario permanen: `features/ED-1025-archive-folder-permission/qa/scenario1..6*.php` + `qa_lib.php` (**31 skenario**: 18 AC + X-1..X-6, X-8..X-14). Run penuh `work/qa-runs/ed1025-r3-full.txt` (runner
  `work/qa-http/ED-1025-20261007055820.json`): **31 PASS, 0 FAIL**, 1189 asersi. Regresi ED-1024 (`run.php ED-1024`, sekali): **26 PASS, 0 FAIL** (`work/qa-runs/ed1025-r3-regr-ed1024.txt`, `work/qa-http/ED-1024-20261007055931.json`).
- Lingkungan: `BE_SERVER=fpm` (`be-reload.sh` no-op); kode terbaru aktif (D-3 kini 422). DB uji `api_sidomaju` (satu DB: isolasi tenant tidak berlaku). `QA_USER2` tidak bisa login (`GE0109`): "user tanpa hak" lewat
  pertukaran role/baris izin `QA_USER` seperti ronde 1-2.
- **Keadaan dipulihkan**: `CHECKSUM TABLE` + hitungan baris 11 tabel (archives, archive_documents, archive_locations, archive_permissions, user_roles, roles, role_permissions, permissions, customers, employees,
  employee_locations), `users`, `user_roles`, `customers.id_user`, `roles.is_active` **identik sebelum dan sesudah** (`diff work/qa-runs/ed1025-r3-state-before.txt ed1025-r3-state-after.txt` kosong); 0 baris `QA02-%`;
  `archive_permissions` 0 baris; token run di-log-out (masih aktif: 0); tidak ada berkas jurnal pemulihan tersisa. Skrip pembantu in-process (tulis ke DB) memakai prefiks `QA02-DIFF-` dan dibuang di `finally` (sisa 0).
  Kode aplikasi BE tidak disentuh (21 berkas kotor sama dengan `changed.txt`).
- Log Laravel pada jendela run (`work/qa-runs/ed1025-r3-newlog.txt`): error hanya dari probe sengaja: 225 collation `latin1_general_ci` (X-12, ED-1071), `CustomizeBuilder`/`ArchiveService:31`/`Str.php` (X-8, X-13, ED-1070),
  21 `websockets_statistics_entries` (derau pra-ada). Tidak ada error dari `LocationRule` / FormRequest sebagai akar.

## Hasil per AC

Semua skenario dijalankan ulang di ronde 3 (hasil sama dengan ronde 1-2; bukti rinci ada di skenario). "Independen" = hitungan DB lewat query terpisah.

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | `SHOW COLUMNS/INDEX`, Updater 2x lewat probe, `config.php` | PASS | `AC-1` (22): kolom `archives.is_folder_permission`, tabel `archive_permissions` (PK, UNIQUE `(id_archive,id_user)`, KEY `id_user`, collation = `archives`), Updater idempoten, terdaftar terakhir di `config.php` |
| AC-2 | HTTP role 3 tanpa folder aktif: list/buka/show/history/create/put-in/rename/PUT/DELETE | PASS | `AC-2` (254): `access` semua true, `is_folder_permission` 0, folder baru nonaktif tanpa baris, semua aksi 200 |
| AC-4 | show folder aktif B View+Store, toggle mati, dokumen | PASS | `AC-4` (35): `folder_permissions` 3 baris sesuai kontrak, urut username; toggle mati: baris tetap, access semua true |
| AC-5 | PUT `is_folder_permission` + baris; ganti/hapus/tambah; input salah | PASS | `AC-5` (61): 200 `ARCHIVE207`, DB tepat 2 baris, pemanggil terkunci bila tak menyertakan diri; `id_user` ganda/tak ada/flag 2 = 422 |
| AC-6 | baris U/D/S tanpa View, 4 varian x 3 posisi, ID/EN | PASS | `AC-6` (59): 400 `ARCHIVE411` + pesan persis kontrak, atomik; 403 `ARCHIVE408` mendahului |
| AC-7 | list folder tanpa View (baris / U-D-S tanpa View / toggle mati) ID dan EN | PASS | `AC-7` (29): 403 `msg_code` `ARCHIVE407` + `result.denied_by/breadcrumbs`; 403 lokasi dan 404 tetap bentuk lama |
| AC-8 | induk aktif menolak anak aktif; 3 tingkat; induk nonaktif | PASS | `AC-8` (20): `denied_by` = folder teratas yang menolak; induk nonaktif tidak membatasi |
| AC-9 | rename/PUT/put-in/create di anak dengan induk (K-1 a) | PASS | `AC-9` (48): `ARCHIVE408/410` + `parameter` = nama folder penolak; Store/Update diwarisi dari induk dominan |
| AC-10 | role 1, role 2, role 3+2 pada folder aktif tanpa baris | PASS | `AC-10` (73): semua aksi 200, access true; kontrol role 3 = 403 |
| AC-11 | pembuat (`created_by`), anak milik orang lain, huruf besar | PASS | `AC-11` (29): pembuat punya semua hak di foldernya, tetap ditolak oleh induk milik orang lain |
| AC-12 | B View tanpa Update: PUT/rename/ganti induk | PASS | `AC-12` (39): 403 `ARCHIVE408`/`ARCHIVE410` (sebelum 422), DB tidak berubah |
| AC-13 | DELETE tanpa/dengan Delete, EN | PASS | `AC-13` (25): 403 `ARCHIVE409`, 404 mendahului 409; dengan Delete 200 `ARCHIVE203` |
| AC-14 | put-in/create tanpa Store, dokumen dari folder tanpa View, folder tanpa Update | PASS | `AC-14` (55): `ARCHIVE410`/`ARCHIVE408`, atomik, 403 sebelum validasi |
| AC-15 | pencarian di folder tanpa View | PASS | `AC-15` (29): hasil search muncul (`access.view` false), show/history folder 403, dokumen 200 |
| AC-16 | select users vs hitungan independen DB | PASS | `AC-16` (111): himpunan, label, urutan = hitungan DB; excepts/search/selected_id; `search[]` = 422; tanpa token 401 |
| AC-22 | riwayat `permission` (DB, `history/{id}`, label ID/EN, baris daftar) | PASS | `AC-22` (29): entri hanya saat hak berubah; label en/id persis kontrak |
| AC-24 | DELETE folder dengan subfolder aktif tanpa Delete | PASS | `AC-24` (35): 403 `ARCHIVE418`, tidak ada yang terhapus; urutan 418 sebelum 401; role 1/2 = 200 |
| AC-23 | (bagian BE, cara seragam EPIC K-2 b) role 6/29/16, tanpa token, guard menu FE | PASS (BE); **MANUAL** (tukar lisensi sungguhan) | `AC-23` (56): 403 `GE0114` + `parameter` benar pada rute tulis; modul dan hak folder sama-sama wajib; guard menu `ksp-react/src/configuration/menus.js:169`. Sisa MANUAL: tukar lisensi sekali di Gate 2 epic |
| AC-3, 17, 18, 19, 20, 21 | `[FE]` | di luar scope be | dikerjakan scope fe |

### Pemeriksaan tambahan (bukan baris AC)

| Id | Cek | Hasil | Bukti |
|---|---|---|---|
| X-1..X-6 | semantik field opsional PUT; teks `ARCHIVE408-410` ID/EN; hand-over/receive; 20 pergantian hak berurutan; input aneh ronde 1; `viewableFolderIds` | PASS | sama seperti ronde 1-2, run ulang hijau |
| X-8 | D-2 diperbaiki (asersi 422) + karakterisasi ED-1070 (search non-JSON = 500 x5, PUT parent = diri sendiri = 200 + siklus, dipulihkan) | PASS | 12 asersi; bila ED-1070 mengubah perilaku, X-8 gagal "ED-1070 berubah" |
| X-9 | tipe/nilai salah pada setiap field PUT/rename/create-folder/put-in/DELETE (14 nilai) | PASS | 353 kasus: 200 x102, 400 x24, 404 x21, 422 x206, **5xx x0**; setiap tolakan 4xx: snapshot sama |
| X-10 | sel `folder_permissions.*` bertipe salah, spasi/tab/huruf besar/duplikat semu, 2000 baris | PASS | 82 kasus: 200 x12, 422 x70, **5xx x0** |
| X-11 | **D-3 diperbaiki**: `id_locations` berisi array/objek di daftar >= 2 elemen (PUT dan create) = 422, data tidak berubah; kontrol 2 lokasi valid = 200 | PASS | 14/14 kasus 422 (ronde 2: 14/14 500); 6 asersi |
| X-12 | **karakterisasi ED-1071** (bukan perilaku benar): karakter di luar Windows-1252 | PASS | 80 kasus: 500 x75 (collation), 200 x5 (rename); data tidak berubah di setiap respons >= 400; gagal "ED-1071 berubah" bila ada kasus yang bergeser |
| X-13 | **karakterisasi ED-1070** (bukan perilaku benar): `pagination`/`sorts`/`search` salah bentuk pada list | PASS | 13/13 kasus 500; gagal "ED-1070 berubah" bila ada yang bergeser |
| X-14 | **baru**: matriks LocationRule + `id_locations` x induk x mode (PUT implisit/eksplisit, create) | PASS | 473 kasus: 200 x120, ARCHIVE402 x72, 422 x281, 5xx x0; 5 asersi; sensitif terhadap mutasi (uji dibalik -> FAIL) |

## Defect

Tidak ada defect terbuka di item ini. D-3 (ronde 2) diperbaiki dan diverifikasi (X-11, X-14). Tidak ada 5xx pada baris yang diubah/ditambah diff ED-1025 di luar akar kolasi (D-4) dan parameter list generik (D-5) di bawah.

## Dilacak di luar item

Bukan defect ED-1025 (aturan "bug di luar item = tiket terpisah, tidak diperbaiki di sini"); perilaku pra-ada di HEAD `b0efd0722`; sekarang karakterisasi di skenario permanen (hijau selama perilaku sama):
- **ED-1070** - D-1: `GET document-archive/archives?search=<bukan JSON objek>` (`bukan-json`, `123`, `"str"`, `{"query":["a"]}`, juga dengan `id_archive`) = 500 (`CustomizeBuilder.php:96-97,112`, trait v5 bersama); O-3: `PUT archives/{id}`
  dengan `id_archive_parent` = id folder itu sendiri = 200 dan folder menjadi induk dirinya (siklus). Karakterisasi: X-8.
- **ED-1070** - D-5 (ditambahkan ke ED-1070): `GET archives` dengan `pagination=abc|[]|-1`, `sorts=bukan-json|{"name":"asc"}|sorts[0] tanpa sortType|["x"]`, `search[]=x|search[a]=b|{"showRelatedTransaction":["a"]}` = 500
  (`ArchiveService.php:25` dan `:86` memakai `$request->pagination` langsung; `CustomizeBuilder::scopeSortAll`/`scopeSearchAll` melempar `ErrorMessageException` tanpa kode HTTP / `json_decode` pada array). Karakterisasi: X-13 (13 kasus).
- **ED-1071** - D-4: string dengan karakter di luar Windows-1252 (Yunani, CJK, emoji, aksen gabung, RTL) sebagai pembanding SQL = 500 `SQLSTATE 1267/1270 Illegal mix of collations (latin1_general_ci,IMPLICIT) and (utf8mb3_unicode_ci,COERCIBLE)`
  (kolom `archives*`/`users`/`locations` latin1, koneksi utf8: `config/database.php:52-53`). Lintas v5. Karakterisasi: X-12 (75 dari 80 kasus 500; `rename` lolos 200 dengan karakter tersimpan `?`).
  **Catatan untuk orkestrator**: 5 dari 75 kasus 500 lewat aturan yang ditambah item ini (`folder_permissions.*.id_user` -> `exists:users,id_user`, `UpdateRequest.php`); akar sama (kolasi), jadi tertangani bila ED-1071 memperbaiki
  koneksi/kolom, tetapi bila ED-1071 hanya memperbaiki aturan pra-ada, aturan baru ini perlu ikut dicakup.

## Review koreksi diff BE (bukan gaya)

- Perubahan sejak ronde 2 (fix D-3): `UpdateRequest` dan `CreateFolderRequest` (`hasScalarLocations()`: bila ada elemen non-skalar, aturan `exists` pada daftar tidak dijalankan dan `id_locations.*` => `string` yang menolak 422),
  `LocationRule` (pengembalian dini `true` bila `is_all_location` atau elemen `id_locations` non-skalar; selebihnya identik dengan HEAD). `php -l` pada 20 berkas berubah/baru: bersih. Urutan `authorize()` sebelum validasi tidak berubah
  (403/404 tetap mendahului 422: AC-12/13/14 hijau).
- Pengembalian dini di `LocationRule` aman: dua-duanya (`is_all_location` non-skalar -> `in:0,1`; elemen `id_locations` non-skalar -> `id_locations.*`) selalu ditolak aturan lain (832/832 = 422), jadi ARCHIVE402 tidak bisa dihindari.
- **Aturan spec / kontrak vs respons nyata**: BR-1..17 terpetakan ke AC di atas, tidak ada selisih; klaim kontrak §3 baris 121 dan §4 baris 136 (elemen `id_locations` bukan teks = 422, termasuk daftar >= 2 elemen) kini terpenuhi.
  Dua bentuk body `ARCHIVE407` (list = `msg_code`+`result`, show/history = `code`+`parameter`) sesuai kontrak; FE bercabang pada endpoint.
- **Skema via Updater**: idempoten (AC-1), tanpa migration. **Rollback**: `ARCHIVE411`, 403, 422 tidak meninggalkan perubahan sebagian (X-9/X-10/X-14 membandingkan snapshot tiap tolakan). **Kolom v3**: tabel `archives*` tidak dipakai v3.
  **State lintas request**: tidak ada `static`; X-4 membuktikan perubahan baris/role/toggle langsung tercermin.

## Isolasi tenant & permission

- **Tenant**: tidak berlaku (`QA_DBS` hanya `api_sidomaju`).
- **Permission**: user berhak = 2xx pada semua endpoint (AC-2, AC-10, AC-13/14/24, X-14); "tanpa permission" lewat pertukaran role `QA_USER` (role 6/29/16): 403 `GE0114` pada rute tulis, data tidak berubah (AC-23).
  Endpoint baru `select/.../archive/users` tanpa `permission_v5` menurut spec (O-2).

## Catatan untuk developer / FE (bukan defect)

- **O-1** Put-in **folder** ke root tetap butuh Update di folder itu (kontrak §4); spec K-7 (iv) dapat dibaca "tanpa cek apa pun". Mohon dikonfirmasi di review batch.
- **O-2** `select/.../archive/users` terbuka untuk semua user login (spec).
- **O-4** User yang bukan pembuat dan menyimpan tab Permission tanpa menyertakan dirinya kehilangan View/Update di folder itu (BR-4/6, by design): FE perlu memperingatkan atau menjaga baris pemanggil.
- **O-5** `message` `ARCHIVE408`-`410` memuat nama folder mentah di `<b>[0]</b>` (tidak di-escape; X-2): FE harus merender aman (R-3 reviewer).
- **O-6** DELETE folder tidak menulis riwayat (pra-ada, tidak diminta spec).
- **O-7** (pra-ada, bukan 500) `name` lebih dari 255 karakter pada PUT/create-folder = 200 dan disimpan terpotong 255 (mode SQL tanpa strict); aturan `max:255` tidak ada.
- **O-8** `id_user` di baris permission dipangkas spasi/tab oleh middleware TrimStrings sebelum validasi; duplikat semu (`U` dan `U ` / `0U`) = 422. Tidak ada celah.
- **O-9** (baru, pra-ada, bukan 500) `id_locations` skalar (bukan daftar) diterima sebagai satu lokasi (200); duplikat id dalam daftar dilipat (`sync`); `id_locations` kosong/null/tanpa kunci pada PUT tidak menghapus lokasi yang ada
  (hanya `!empty` yang di-`sync`); `is_all_location=2` di bawah induk lokasi tertentu = 400 ARCHIVE402 (bukan 422). Semuanya identik dengan HEAD (diferensial); tidak diminta spec ED-1025.

## Yang tidak bisa diverifikasi (dan kenapa)

- Tukar lisensi sungguhan (tanpa Salesman Activity): `PROFILES` kosong -> **MANUAL (Gate 2 epic)**; cara seragam sudah dicek (AC-23, bagian BE).
- `QA_USER2`: tidak bisa login (`GE0109`); diganti pertukaran role/hak `QA_USER`.
- Updater pada DB tenant baru tanpa tabel dan MySQL non-MariaDB untuk `ADD IF NOT EXISTS`: tidak diulang QA (server uji MariaDB 10.11; preseden sintaks sama di Updater lain).
- RoadRunner: tidak relevan (`BE_SERVER=fpm`).
- Perilaku `LocationRule` HEAD di level HTTP tidak dijalankan ulang (tidak boleh mengubah repo BE); pembandingan "seperti sebelumnya" dilakukan in-process pada kelas aturan (HEAD vs sekarang) dan di level HTTP terhadap harapan ED-1024
  (regresi ED-1024 26/26 + X-14).

## Riwayat ronde

- Ronde 1: 17 AC `[BE]` PASS, AC-23 PASS (BE) + MANUAL (lisensi), regresi ED-1024 26/26; verdict FAIL hanya karena 500 pra-ada D-1 (`search` non-JSON) dan D-2 (`name` array) di luar diff; 25 skenario (24 PASS, 1 FAIL X-8).
- Ronde 2: D-2 diperbaiki; D-1 + O-3 dipindah ke ED-1070 (karakterisasi X-8); probe tipe salah penuh (X-9/X-10) menemukan D-3 (`id_locations` array >= 2 elemen, item ini), D-4 (karakter non-Windows-1252), D-5 (parameter list generik)
  = 500; 30 skenario: 27 PASS, 3 FAIL (X-11/12/13); regresi ED-1024 26/26; verdict FAIL.
- Ronde 3: D-3 diperbaiki dan diverifikasi (X-11, matriks X-14, diferensial LocationRule 0 selisih pada input skalar, 832/832 non-skalar = 422); D-4 -> ED-1071 dan D-5 -> ED-1070 (luar item), X-12/X-13 jadi karakterisasi bertag tiket;
  31 skenario: 31 PASS, 0 FAIL, 1189 asersi; regresi ED-1024 26/26; keadaan DB identik sebelum/sesudah; verdict PASS.
