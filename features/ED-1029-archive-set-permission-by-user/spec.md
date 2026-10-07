---
key: ED-1029
epic: ED-1022
title: Archive - Set Permission by User
status: approved
batch: ED-1029
fe_modules: [Archive]
new_fe_modules: []            # halaman baru di dalam module Archive yang sudah ada, bukan module baru
be_modules: [DocumentArchive]
qa_model: sonnet
contract: contract.md
depends_on: [ED-1024, ED-1025]
---

## 1. Ringkasan
Admin arsip bisa mengatur hak folder **satu user untuk banyak folder sekaligus** dari satu halaman
"Archive Permission", dibuka lewat menu "+" **Set Permission by User** di Archive. Halaman menampilkan
seluruh folder sebagai tabel hierarkis: kolom Permission (On/Off = *Enable Folder Permission* folder itu)
dan centang View / Update / Delete / Store untuk user yang dipilih. Centang hanya bisa diubah di folder
yang permission-nya On dan yang boleh dikelola oleh user yang sedang login. Datanya **sama persis** dengan
tab Permission per folder (ED-1025): tabel `archive_permissions`, aturan hak efektif, validasi, dan
riwayat yang sama; item ini hanya menambah tampilan per user + dua endpoint baca/simpan per user.

Sumber: brief S1 tidak menyebut layar ini (G-1); masuk epic lewat PK-1 b. Perilaku dari desain S2 layar 03
(tile 03-04) dan 01 (menu "+", tile 01), serta CHANGES.pdf S4 hal.1-2; S2 menang bila beda (R-4). Tidak ada
`R-n` brief yang jatuh ke item ini.

## 2. Aturan bisnis
| # | Aturan | Sumber |
|---|---|---|
| BR-1 | Menu "+" Archive punya item **Set Permission by User** (sesudah Opname Document milik 03), tampil di root maupun di dalam folder; membuka halaman "Archive Permission". Siapa yang melihat menu & boleh membuka halaman: K-1 | [S2 01 dropdown "+"]; [S4 hal.1 "ditempatkan pada tombol + di menu Archive"] |
| BR-2 | Halaman berdiri sendiri (judul header "Archive Permission", menu samping Archive tetap aktif), bukan drawer/modal; isinya selalu seluruh pohon folder dari root, dari mana pun dibuka | [S4 hal.2 "Halaman Set Permission by User"]; [S2 03 header "Archive Permission", tabel mulai dari folder root] |
| BR-3 | Pilih **User** (wajib sebelum tabel tampil). Daftar user = select item 02 (`select/document-archive/archive/users`: user aktif ber-`List Archive`, tanpa superadmin 1/2 & customer) - mengikuti jawaban 02 K-9 | [S2 03 field User]; 02 BR-14 |
| BR-4 | Tabel hierarkis: Folder (ikon + nama, indentasi per level, semua level terbuka), Permission (tag On/Off = `archives.is_folder_permission`, read-only - K-3), View, Update, Delete, Store (centang). Urut nama per level. Hanya folder aktif (`type`=1, `is_active`>0); dokumen tidak tampil | [S2 03 tabel]; [S4 hal.2 "daftar folder secara hierarkis"]; urutan nama seperti `ArchiveService.php:71` |
| BR-5 | Folder yang tampil = folder dalam scope lokasi user yang login (item 01 `scopeVisibleToUser`; superadmin 1/2 semua). Folder dalam scope yang induknya di luar scope tampil di level teratas. Folder tanpa View milik user login tetap tampil (read-only), mengikuti jawaban 02 K-6 | K-2; 01 §3 titik pakai ulang `scopeVisibleToUser`; 01 K-1 |
| BR-6 | Nilai centang = baris `archive_permissions` user terpilih untuk folder itu; tanpa baris = semua kosong. Yang ditampilkan nilai **tersimpan**, bukan hak efektif (dominasi induk 02 K-1, hak pembuat 02 K-3, lokasi user target tidak dihitung di sel) - K-5 | [S2 03]; 02 model data |
| BR-7 | Sel centang bisa diubah hanya bila (a) folder itu Permission **On**, dan (b) user login boleh mengelola permission folder itu (`access.manage_permission` dari 02, = aturan 02 K-4). Folder Off: centang terkunci dan menampilkan nilai tersimpan (02 K-10 ii: baris disimpan tapi diabaikan). Child dari folder Off yang dirinya On tetap bisa dicentang (K-4) | [S2 03 info + gambar: "SMLSMG - BRANGKAS" On di bawah "SMLSMG" Off tercentang]; [S4 hal.2 "hanya dapat dicentang pada folder yang ... aktif (On)"]; 02 BR-4/7 |
| BR-8 | Mencentang Update/Delete/Store ikut mencentang View; melepas View melepas semuanya; BE menolak Update/Delete/Store=1 dengan View=0 (400 `ARCHIVE411`) - aturan yang sama dengan 02 BR-3 | 02 BR-3 |
| BR-9 | Kotak teks info di atas tabel: "Checkbox hanya aktif pada folder yang *Enable Folder Permission*-nya On. Folder Off tidak bisa dicentang … — parent folder lebih dominan." (frasa "termasuk child dari folder Off" mengikuti K-4) | [S2 03 info] |
| BR-10 | **Search folder** menyaring pohon menurut nama folder (mengandung, tanpa peka huruf besar): folder cocok + semua induknya tampil. Menyaring **tidak** membuang perubahan yang belum disimpan; mengosongkan pencarian menampilkan pohon penuh lagi | [S2 03 "Search folder"]; dipilih saring di FE (seluruh pohon sudah dimuat; QA DB 17 folder aktif), beda dari rencana "cari folder" di BE supaya perubahan tidak hilang |
| BR-11 | **Save Permission** menyimpan semua perubahan user terpilih dalam satu request atomik: hanya folder yang berubah dikirim; per folder baris (folder, user) diganti; keempat hak 0 = baris dihapus; folder lain & user lain tidak tersentuh. Gagal satu → tidak ada yang tersimpan | K-7; 02 BR-16 (baris tanpa hak tidak disimpan) |
| BR-12 | Setiap folder yang barisnya benar-benar berubah mendapat satu entri riwayat aksi `permission` oleh user login (mengikuti jawaban 02 K-10 iii) | 02 BR-16 + label riwayat 02 |
| BR-13 | BE menolak baris untuk folder Off (400 `ARCHIVE441`), folder yang tidak boleh dikelola user login (403 `ARCHIVE408`), folder di luar scope lokasinya (403 `ARCHIVE407`), id yang bukan folder aktif (404 `ARCHIVE400`); user target tidak ada/nonaktif (404 `ARCHIVE440`) | D-6; K-4; 02 contract §3 |
| BR-14 | **Reset** dan ganti User saat ada perubahan belum disimpan: K-6 | [S2 03 tombol Reset] |
| BR-15 | Superadmin 1/2 boleh mengelola semua folder On (mengikuti jawaban 02 K-2) | 02 BR-5 |
| BR-16 | Perubahan dari halaman ini langsung berlaku di penegakan item 02 (buka folder, layar 06, aksi) dan terlihat di tab Permission folder itu, dan sebaliknya - satu sumber data | 02 §3 "Dibaca per folder (item ini) dan per user (item 06)" |
| BR-17 | Archive hanya untuk lisensi Salesman Activity: tanpa lisensi menu Archive tidak ada, route FE ditolak, endpoint baru menolak 4xx seperti endpoint Archive lain (mengikuti jawaban 01 K-4) | R-2; `knowledge/license-features.md` |

## 3. Model data
| Hal | Isi |
|---|---|
| Tabel/kolom | **Tidak ada yang baru.** Dipakai dari 02 (DB tenant): `archive_permissions` (`id_archive`, `id_user`, `is_view/is_update/is_delete/is_store`, `UNIQUE (id_archive,id_user)`, `KEY id_user` - indeks itu yang dipakai baca per user), `archives.is_folder_permission`; dari tabel lama `archives` (`id_archive_parent`, `type`, `is_active`, `created_by`, `history`), `users` (`id_user`, `username`, `is_active`) |
| Updater | Tidak ada (Updater 02 sudah membuat semuanya) |
| Service | Pakai ulang `ArchivePermissionService` (02) untuk `access` user login per folder - satu kali hitung untuk seluruh pohon, tanpa cache static (D-3). Tulis baris (folder, user) + riwayat lewat **satu method bersama** dengan simpan tab Permission 02: `ArchivePermissionService::saveUserRow` (02 §3), supaya aturan "semua 0 = hapus", BR-8 dan riwayat tidak ditulis dua kali. Service baru `UserPermissionService extends DocumentArchiveService` (butuh `createHistory`, `DocumentArchiveService.php:11-29`) |
| Pohon | dibangun di BE dengan `children` (pola `AssetCategoryService.php:56,122-139`); `children` hanya ada bila punya subfolder |
| Permission seed | Tidak ada (K-1 a; satu-satunya permission baru epic = `Opname Document` 1133 milik 03). gate1 §8 #16 (id 1134/1135 bila K-1 b/c) tidak berlaku |
| Display setting | Tidak berubah (halaman tanpa `columns` server; tabel pohon kolom statis FE) |
| Kode pesan baru (`en_EN.php` + `id_ID.php`, blok `:3345-3363` / `:3222-3240`) | `ARCHIVE440` (404) user tidak ditemukan/nonaktif; `ARCHIVE441` (400) folder permission folder <b>[0]</b> belum aktif. Pakai ulang: `ARCHIVE200` (baca), `ARCHIVE207` (simpan), `ARCHIVE400` (404), `ARCHIVE407` (01), `ARCHIVE408`/`ARCHIVE411` (02); nomor final gate1 §5 |
| Kolom v3 | v3 tidak memakai `archives`/`archive_permissions` (02 §3) - tidak ada kolom v3 yang wajib dijaga |

## 4. Endpoint v5 (prefix `api/v5`, berkas `Routes/DocumentArchive/api.php`, group `document-archive`, `auth:api`)
| Route | Controller@method | FormRequest | Service | Permission | Status |
|---|---|---|---|---|---|
| GET `document-archive/user-permissions/{id}` (`{id}` = `id_user` target) | baru `UserPermissionController@show` | - | `UserPermissionService::show($id)`: cek user (404 `ARCHIVE440`); folder dalam scope 01; baris user target; `access` user login (02) → pohon | K-1 a: `permission_v5:Update Folder` (bila 01 K-4 a/b memasang `permission_v5` di route Archive) | baru |
| PUT `document-archive/user-permissions/{id}` | `UserPermissionController@update` (pola `ArchiveController.php:73-86`: `DB::transaction(..., MyHelper::ATTEMPTS)` + tangkap `ErrorMessageException`) | baru `Requests/DocumentArchive/UserPermission/UpdateRequest.php`: rules + `authorize()` (403 `ARCHIVE407`/`ARCHIVE408` sebelum 422, pola 02) | `UserPermissionService::update($request->validated(), $id)` → `ArchivePermissionService::saveUserRow` per folder | sama dengan GET | baru |
| GET `select/document-archive/archive/users` | `SelectArchiveController@user` | - | `SelectArchiveService::user` | - (select) | sudah ada **setelah 02** (dipakai ulang) |

Grup route baru `Route::prefix('user-permissions')` di dalam `document-archive` (tidak tertutup `archives/{id}`,
`api.php:19-28`). Nama `{id}` mengikuti tetangga di berkas yang sama. Detail body/response: contract.md.

## 5. Perubahan FE per module - `src/containers/Archive` (master, aksi baris kustom `archiveActionColumn`)
Tampilan mengikuti EQUAL; struktur & alur dari desain (`knowledge/design-sources.md`).

| Bagian | Perubahan |
|---|---|
| Halaman baru `ArchivePermissionPage/` (sejajar `ArchivePage/`) | `usePage({ title: <"Archive Permission">, activeMenu: paths.archive })`; `Card`: baris atas = `SelectUser` (`endpoint` = `endpoints.getSelectArchiveUsers` dari 02, `components/Select/SelectUser/index.js:13-14`), input cari folder (placeholder "Search folder"), tombol **Reset** (`common.Reset`) dan **Save Permission** (primary); `Alert` info BR-9; `DefaultTable` data pohon (`children`, terbuka semua, `pagination={false}`, `rowKey="idArchive"`). Kolom statis FE: Folder (ikon folder yang sudah dipakai `ArchivePage`, `FolderTwoTone`), Permission (`Tag` On hijau / Off abu), View/Update/Delete/Store (`Checkbox`, `disabled` menurut BR-7). Sebelum user dipilih: tabel kosong + teks "pilih user", Reset & Save nonaktif |
| State & logika (`archive.function.js` atau hook controller halaman) | simpan pohon asli + peta perubahan per `idArchive`; auto-centang View / lepas semua (BR-8 - pakai ulang fungsi 02 di `archive.function.js`); saring pohon (BR-10) tanpa mengubah peta perubahan; diff → body `permissions` (BR-11); Reset/ganti user (K-6, konfirmasi lewat `Modal.confirm`/`Popconfirm` dari `components`). Sesudah Save sukses: muat ulang GET user itu |
| `archive.api.js` | `getArchiveUserPermission` (`withParams(endpoints.getArchiveUserPermission, { id })`), `putArchiveUserPermission` (`method: 'put', showMessage: true`) |
| "+" (`archive-components/ArchiveButtonAdd/index.js:8-48`) | item `set-permission` "Set Permission by User" (paling akhir, sesudah Opname Document 03) bila permission K-1; klik → `navigate(paths.archivePermission)` |
| Registrasi | `routes/paths.js:34` + `archivePermission: '/archives/permissions'`; `routes/routes.js:2085-2094` + route lazy `containers/Archive/ArchivePermissionPage` dengan `PrivateRoute permission={permissions.UpdateFolder}` (K-1 a); `configuration/endpoints.js:58-67` + `getArchiveUserPermission` / `putArchiveUserPermission: 'document-archive/user-permissions/:id'`; locale `archive.*` di `entries/en-US.js` + `id-ID.js` (judul, label, info, teks kosong, konfirmasi); `routes/permissions.js:328-337` hanya bila K-1 b/c |
| Tidak berubah | `ArchivePage` selain "+"; `menus.js:162-169` (menu Archive tetap satu) |

## 6. Acceptance criteria
Profil uji: lisensi QA_DB `api_sidomaju` (Salesman Activity aktif). Data uji dibuat sendiri (pola 02): user A
(non-superadmin; `List Archive`, `Update Folder`, …), user B (target, ber-`List Archive`), user E (tanpa
`Update Folder`), superadmin `is_superadmin` 1 dan 2; folder P (On, A punya Update), anak C1 (On) dan C2 (Off) di
bawah P, folder Q (On, A tanpa Update), folder R (Off) dengan anak R1 (On), folder L di luar lokasi A.

| # | Given / When / Then | Tag | BR | Cek |
|---|---|---|---|---|
| AC-1 | Given A di root dan di dalam P When klik "+" Then ada "Set Permission by User" (sesudah Opname Document bila 03 sudah ada); Given E Then item tidak ada | [FE] | 1 | unit (`ArchiveButtonAdd`) + e2e (A, E) |
| AC-2 | When A membuka halaman lewat "+" dan lewat URL `/archives/permissions` Then halaman "Archive Permission" terbuka tanpa error konsol/toast; menu Archive aktif; dropdown User memanggil `select/document-archive/archive/users`; sebelum memilih user tabel kosong, Reset & Save nonaktif | [FE] | 2, 3 | e2e (A) |
| AC-3 | Given E (tanpa permission K-1) When membuka `/archives/permissions` Then halaman Unauthorized (`PrivateRoute`), tidak ada request user-permissions | [FE] | 1 | e2e (E) |
| AC-4 | When A GET `user-permissions/{B}` Then 200 `ARCHIVE200`; `result.user.username`=B; `result.data` pohon folder aktif dalam scope A (L tidak ada), urut nama, R1 di bawah R; tiap node: `is_folder_permission`, nilai B (0 bila tanpa baris), `access.manage_permission` A (P true, Q false); dokumen tidak ada | [BE] | 4, 5, 6 | http: GET + bandingkan `archive_permissions` di QA_DB |
| AC-5 | When superadmin (1 dan 2) GET `user-permissions/{B}` Then L ikut tampil dan `access.manage_permission`=true di semua folder | [BE] | 5, 15 | http (2 user) |
| AC-6 | When GET/PUT `user-permissions/<id tidak ada>` atau user nonaktif Then 404 `ARCHIVE440` | [BE] | 13 | http |
| AC-7 | When A PUT `{B}` `permissions`=[P: View+Store, C1: View] Then 200 `ARCHIVE207`; `archive_permissions` (P,B) dan (C1,B) sesuai; baris user lain dan folder lain milik B tidak berubah; `history` P dan C1 bertambah satu entri `permission` oleh A; GET `archives/{P}` (02) memuat baris B yang sama | [BE] | 11, 12, 16 | http + DB |
| AC-8 | Given (P,B) ada When PUT P dengan keempat hak 0 Then baris (P,B) terhapus; PUT dengan nilai sama seperti tersimpan Then tanpa entri riwayat baru; `permissions`=[] Then 200 tanpa perubahan | [BE] | 11, 12 | http + DB |
| AC-9 | When PUT baris C2 (Off) Then 400 `ARCHIVE441` (parameter nama C2); bersama baris P yang sah dalam request yang sama Then P juga tidak tersimpan (atomik) | [BE] | 7, 11, 13 | http + DB |
| AC-10 | When PUT baris `is_update`=1, `is_view`=0 Then 400 `ARCHIVE411`, data tidak berubah | [BE] | 8 | http |
| AC-11 | When A PUT baris Q Then 403 `ARCHIVE408` (sebelum 422 walau body lain tidak valid); baris L Then 403 `ARCHIVE407`; `id_archive` dokumen/tidak ada Then 404 `ARCHIVE400`; `id_archive` ganda atau flag kosong Then 422 | [BE] | 13 | http |
| AC-12 | Given R Off, R1 On When A (punya Update di R1) PUT baris R1 Then 200 [K-4 a] | [BE] | 7 | http |
| AC-13 | Given A memberi B View di P lewat PUT When B GET `archives?id_archive=P` Then 200; A mencabutnya When B mengulang Then 403 `ARCHIVE407` (penegakan 02) | [BE] | 16 | http (A, B) |
| AC-14 | Given A pilih B When tabel tampil Then hierarki & indentasi sesuai GET, semua level terbuka, kolom Permission On/Off; centang aktif hanya di folder On yang `manage_permission` (P, C1, R1), terkunci di C2/R/Q; info BR-9 tampil | [FE] | 4, 7, 9 | e2e (A) + `manual (Gate 2)` tampilan |
| AC-15 | When centang Update di P Then View ikut tercentang; lepas View Then keempatnya lepas | [FE] | 8 | unit (fungsi) + e2e |
| AC-16 | Given centang diubah di P When cari "C1" Then hanya P (induk) dan C1 tampil; kosongkan pencarian Then perubahan di P masih ada | [FE] | 10 | unit (saring pohon) + e2e |
| AC-17 | When ubah P dan C1 lalu Save Permission Then body PUT hanya berisi P dan C1, toast sukses, tabel dimuat ulang dengan nilai tersimpan | [FE] | 11 | e2e (cek request) |
| AC-18 | [K-6 a] Given ada perubahan When Reset Then kembali ke nilai tersimpan tanpa request; When ganti User Then muncul konfirmasi, Batal = tetap di user lama dengan perubahannya | [FE] | 14 | e2e |
| AC-19 | Given BE menolak (mis. 400 `ARCHIVE441` karena P dimatikan di tab Permission sesudah halaman dibuka) When Save Then pesan error tampil sebagai toast, perubahan di layar tidak hilang | [FE] | 11, 13 | e2e (ubah DB di antara buka & Save) |
| AC-20 | Given lisensi tanpa Salesman Activity Then menu Archive tidak tampil, `/archives/permissions` tertolak, kedua endpoint 4xx (bukan 500) | [BE+FE] | 17 | cara seragam EPIC K-2 b: http GET/PUT `user-permissions/{B}` dengan role tanpa `Update Folder` → 403 `GE0114`, data tetap; seed: tidak ada baris baru (permission Archive hanya di `permission_salesman.sql`); FE: guard menu Archive + `PrivateRoute` dicek di kode; tukar lisensi sungguhan sekali, manual, di Gate 2 epic |

## 7. Subtask
| Kunci | Judul | Layer | AC | Module |
|---|---|---|---|---|
| ED-1035 | [BE] Baca hak folder per user (pohon folder dalam scope + access) | BE | 4, 5, 6 | DocumentArchive |
| ED-1041 | [BE] Simpan hak folder per user (validasi, atomik, riwayat, kode pesan ARCHIVE440-441) | BE | 6-13 | DocumentArchive |
| ED-1047 | [FE] Archive - Halaman Set Permission by User + menu "+" + route | FE | 1-3, 14-19 | Archive |
| ED-1053 | [QA] Skenario Set Permission by User | QA | semua | Archive |

## 8. Keputusan untuk developer
| # | Pertanyaan | Opsi | Rekomendasi | Risiko | Jawaban Gate 1 |
|---|---|---|---|---|---|
| K-1 | Permission yang membuka menu "+" Set Permission by User, route FE, dan kedua endpoint | a) tanpa permission baru: `Update Folder` (module 1266) - sejalan 02 K-4 a; bila 02 K-4 dijawab b, pakai permission baru dari 02. Folder yang bisa diubah tetap per folder (`manage_permission`); b) permission baru `Set Permission by User` (module 1266, id max+1 sesudah `Opname Document` 03 → risiko tabrakan id) hanya untuk membuka halaman, folder tetap dibatasi `manage_permission`; c) seperti b, tetapi pemegangnya boleh mengubah semua folder On dalam scope lokasinya (admin arsip, melewati hak folder 02); d) hanya superadmin 1/2 | a - S2/S4 tidak menyebut permission baru; satu aturan "siapa boleh mengelola" dengan 02 | tinggi | **Jawaban Gate 1 (2026-10-06):** a - G-4 (tanpa permission baru; gate1 §8 #16 tidak berlaku) |
| K-2 | Folder yang tampil di halaman | a) folder dalam scope lokasi user login (01), termasuk yang tanpa View-nya (read-only, ikut 02 K-6); b) semua folder aktif tanpa cek lokasi; c) a dan juga dalam scope lokasi user target | a - satu scope Archive (PK-2 a); BE tetap menolak folder di luar scope (403) | rendah | **Jawaban Gate 1 (2026-10-06):** a - G-1 |
| K-3 | Kolom Permission On/Off | a) read-only (tag); mengaktifkan/mematikan lewat tab Permission folder (02); b) bisa di-toggle di sini (mengubah `is_folder_permission` folder untuk semua user) | a - layar ini per user, toggle berdampak ke semua user | rendah | **Jawaban Gate 1 (2026-10-06):** a - rekomendasi |
| K-4 | Teks desain "Folder Off tidak bisa dicentang, **termasuk child dari folder Off**" bertentangan dengan gambar desain (SMLSMG - BRANGKAS On di bawah SMLSMG Off tercentang) dan 02 BR-4 (folder Off di jalur tidak membatasi) | a) hanya flag folder itu sendiri yang menentukan; frasa "termasuk child…" dibuang dari teks info; b) child dari folder Off ikut terkunci. Untuk keduanya: FE mengunci **dan** BE menolak (400 `ARCHIVE441`) | a + validasi BE | rendah | **Jawaban Gate 1 (2026-10-06):** a + validasi BE - rekomendasi |
| K-5 | Nilai tersimpan vs hak efektif user target di tabel | a) hanya nilai tersimpan (desain); dominasi induk (02 K-1), hak pembuat folder (02 K-3), dan lokasi user target hanya dijelaskan teks info; b) tambah penanda per sel/baris (ikon + tooltip "dibatasi folder induk X" / "pembuat folder: semua hak" / "di luar lokasi user"); c) kunci sel yang tidak efektif karena induk | a - minimal, sesuai desain; b bisa menyusul | rendah | **Jawaban Gate 1 (2026-10-06):** a - rekomendasi |
| K-6 | Tombol Reset & perubahan belum disimpan | a) Reset = kembali ke nilai tersimpan user terpilih (tanpa request, tanpa konfirmasi); ganti User saat ada perubahan → konfirmasi; b) Reset = kosongkan semua centang user ini di folder yang bisa diubah (baru tersimpan saat Save) | a | rendah | **Jawaban Gate 1 (2026-10-06):** a - rekomendasi |
| K-7 | Isi request Save | a) hanya folder yang berubah; per folder baris (folder, user) diganti, folder lain tidak disentuh; b) semua folder yang bisa diubah dikirim (menimpa perubahan orang lain lewat tab Permission 02 sejak halaman dibuka) | a | rendah | **Jawaban Gate 1 (2026-10-06):** a - rekomendasi |
| EPIC K-2 | Cara cek AC lisensi (`PROFILES` kosong) | a/b/c di gate1 §7 | b | rendah | **Jawaban Gate 1 (2026-10-06):** b - rekomendasi; cara seragam di AC-20 |

Mengikuti jawaban item lain (tidak ditanya ulang): 02 K-1 (hak efektif), K-2 (superadmin), K-3 (pembuat), K-4
(siapa boleh mengelola = `manage_permission`), K-6 (folder tanpa View tetap tampil), K-9 (daftar user), K-10 (ii
baris folder Off disimpan, iii riwayat); 01 K-1 (lokasi per baris), K-4 (`permission_v5` di route Archive).

## 9. Di luar cakupan
- Tabel `archive_permissions`, kolom `is_folder_permission`, Updater, `ArchivePermissionService`, select user, tab
  Permission per folder, layar 06, penegakan hak di aksi Archive → **ED-1025**.
- Scope lokasi folder dan `permission_v5`/lisensi di route Archive → **ED-1024**.
- Item "+" Opname Document → **ED-1026**.
- Menyalin hak dari user lain, set massal per kolom (centang semua), dan ekspor daftar hak.
- Mengubah `is_folder_permission` dari halaman ini (K-3 a).
- Penanda hak efektif per sel (K-5 b) bila tidak dipilih.

## Catatan implementasi
- 2026-10-07 BE (ED-1041): PUT `user-permissions/{id}` 403 `ARCHIVE407` hanya untuk folder di luar scope lokasi (dipakai ulang `DocumentArchiveService::assertVisible`, teks generik tanpa `parameter`, sama dengan 01/02). Folder tanpa View efektif user login mendapat 403 `ARCHIVE408` (`manage_permission` = Update efektif, BR-7/BR-13, sama dengan PUT `archives/{id}` 02), bukan `ARCHIVE407` seperti tulisan awal kontrak. contract.md §2 disesuaikan.
- 2026-10-07 BE (ED-1041): 404 `ARCHIVE440` juga dicek di `UpdateRequest::authorize()` (sebelum 422, pola `Opname/UpdateRequest`), selain di service; `id_archive` yang bukan folder aktif dilewati `authorize()` dan menjadi 404 `ARCHIVE400` di service (sesudah 422). contract.md §2 kolom "Dicek di" disesuaikan.
- 2026-10-07 BE (ED-1035/ED-1041): `ArchivePermissionService::saveUserRow` (02) hanya menulis/menghapus baris dan mengembalikan "berubah"; riwayat `permission` ditulis pemanggil (`UserPermissionService::update` lewat `createHistory`, sama dengan `ArchiveService::update` 02). Baca baris per user lewat method baru `ArchivePermissionService::userRows($idUser)` (pasangan `folderRows`).
- 2026-10-07 BE fix ronde 1 (D-2): simpan paralel baris (folder, user) yang sama tidak lagi 500 (1062). `ArchivePermissionService::lockFolders` mengunci baris `archives` folder (FOR UPDATE, satu query titik per id, urut id) sebagai query pertama transaksi `UserPermissionService::update` dan `ArchiveService::update` (tab Permission 02); penyimpan folder yang sama bergiliran dan membaca nilai terbaru, jadi hasil akhir = kiriman terakhir dan riwayat `permission` tetap hanya untuk perubahan nyata. Bentuk response dan kode pesan tidak berubah.
