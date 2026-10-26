---
key: ED-1250
epic: ED-1022
title: Archive - filter Verification Status (Verified / Unverified)
status: approved
batch: ED-1250
fe_modules: [Archive]
new_fe_modules: []
be_modules: [DocumentArchive, Setup/Setting, UpdateVersion]
qa_model: sonnet
contract: contract.md
depends_on: []
---
## Revisi Gate 1 (2026-10-24)

Sumber: jawaban developer `epics/ED-1022-gate1.md` §9, perubahan cek lintas item §8, dan koreksi developer (di bawah). BR/AC tanpa
**(rev)** tetap.

| Kode | Perubahan | BR / AC | Layer |
|---|---|---|---|
| **Koreksi folder** | Developer 2026-10-24: "saya tidak pernah menyuruh menyembunyikan folder karena filter ini aktif, yang disembunyikan itu dokumen yang tidak sesuai filter document status saja itu pun ketika filter ini aktif." → filter **hanya** menyembunyikan baris dokumen yang statusnya tidak sama; baris folder **tidak pernah** disembunyikan (isi apa pun, hak View apa pun), di halaman dan picker. Aturan folder `verified>0`/`unverified>0` dibuang; K-3 gugur | BR-3, 6, 7, 12; AC-2, 3, 5, 6, 14, 18, 21 | BE+FE |
| K-2 b | Filter tampil dan berlaku di picker Pindahkan / Pilih Folder seperti di halaman (tetap di folder picker, tetap saat navigasi picker, Reset mengosongkan, + filter lain = datar dari root); di picker pun hanya baris dokumen yang disaring | BR-12; AC-17..19, 24; subtask FE picker | FE |
| K-5 | a (default orkestrator): picker dibuka dengan filter kosong | BR-12; AC-18 | FE |
| K-1 a, K-4 a; DU-5 a, DU-6 a | dicatat; DU-5/DU-6 di §6a | §6a, §8 | QA |
| gate1 §8 | AC-22 + ED-1027 scenario1/2/9/10 + ED-1028 scenario9; AC-10 langkah bila di-unpark sesudah ED-1251; §6a teardown C-8; dok FE `027-*` | AC-10, 22; §5; §6a | BE+FE+QA |

## 1. Ringkasan

List Archive mendapat filter **Verification Status** (select: Verified / Unverified, opsional, default kosong = semua). Saat
aktif, baris **dokumen** yang statusnya tidak sama disembunyikan; baris **folder selalu tampil** seperti tanpa filter. Berbeda dengan
filter lain yang mengubah list menjadi hasil cari datar di semua level, filter ini **tidak mengubah struktur folder**: root tetap
root, isi folder tetap isi folder, breadcrumb dan hak folder tetap. Angka kolom **Document Verified** (ED-1027) dan ringkasan header
tetap menghitung semua dokumen. Filter hanya untuk pemegang permission **Opname Document**, dan berlaku sama di picker folder
tujuan (Pindahkan, Pilih Folder pindah massal). Tanpa tabel baru; satu Updater display setting.

Sumber `jira.md` (developer 2026-10-23): J-1 select Verified/Unverified, opsional, default kosong · J-2 aktif → dokumen berstatus
sesuai (folder tetap, koreksi 2026-10-24) · J-3 hanya menyembunyikan dokumen, tidak mengubah struktur folder seperti filter lain ·
J-4 counter Document Verified folder tetap menghitung semua · J-5 disembunyikan tanpa Opname Document. Archive tidak ada di v3.

## 2. Aturan bisnis

| BR | Aturan | Sumber |
|---|---|---|
| BR-1 | Filter **Verification Status** (id "Status Verifikasi"), select satu nilai, Verified / Unverified (id "Terverifikasi" / "Belum terverifikasi", sama dengan label ikon ED-1027), opsional, default kosong = tanpa saringan. Label dari config display setting (en/id), bukan locale FE | J-1; `archive.locale.js:102,122,243,263`; opsi statis `displayColumn/shipmentDriver.php:200-229` |
| BR-2 | Status dokumen = `archives.is_verified` **saat ini** (ED-1027 BR-4). Verified = 1; Unverified = selain itu: belum pernah diopname (dokumen baru 0, default kolom 0), tidak discan pada sesi tanpa "lanjut opname" (direset 0). Not found/Invalid, pindah, hand over, receive tidak mengubah status (ED-1026 AC-21, BR-26). Handed Over/Taken tetap tampil, disaring menurut statusnya. `is_active` -1/0 tidak pernah di list | ED-1027 BR-3/4; `OpnameService.php:410-451`; `ArchiveService.php:88-91` |
| BR-3 (rev) | Filter aktif: **hanya baris dokumen** yang statusnya tidak sama yang disembunyikan (Verified → dokumen `is_verified = 1` saja; Unverified → dokumen `is_verified ≠ 1` saja). Baris folder **tidak pernah** disembunyikan oleh filter ini: folder kosong (0/0), all-verified, tanpa dokumen cocok, tanpa View - semua tampil persis seperti tanpa filter | J-2, J-3; koreksi developer 2026-10-24 |
| BR-4 | Struktur tetap: filter ini sendiri (atau bersama Show Related Transaction) tidak membuat list "mencari": di root tampil baris level root, dengan `id_archive` F tampil anak langsung F; `breadcrumbs`, `access` folder, layar akses ditolak 403 `ARCHIVE407` sama dengan tanpa filter. Pembanding: Name, Type, Date Modified, Modified By, teks cari, scan QR mencari datar di semua level, breadcrumb kembali ke "Archive" | J-3; `ArchiveService.php:42-51,66-73,116-122`; `ArchivePage/index.js:224-238` |
| BR-5 | Angka tidak berubah: `document_verified` tiap baris dan `document_verified_summary` sama persis dengan tanpa filter; modal Document Verified dan tab Verification tidak ikut filter | J-4; ED-1027 BR-6; `ArchiveService.php:75-80,145-150,193-195` |
| BR-6 (rev) | Visibilitas tetap (lokasi ED-1024 + View ED-1025; superadmin 1/2 semua): filter tidak menambah baris yang tadinya tidak terlihat. Folder tanpa View tetap tampil dengan angka `-` seperti sekarang; membukanya tetap 403 `ARCHIVE407` | ED-1027 BR-2/5; ED-1025 |
| BR-7 (rev) | Bersama filter lain (mode cari datar seperti sekarang): filter lain menentukan baris seperti sekarang, lalu filter ini hanya membuang baris dokumen yang tidak cocok, termasuk dokumen di folder tanpa View yang memang muncul di pencarian (ED-1025 BR-12); folder hasil cari tetap. Type = Folder + filter = hasil Type = Folder saja; Type = transaction type → hanya dokumen tipe itu berstatus sama | J-3; koreksi 2026-10-24; ED-1025 BR-12; `ArchiveService.php:93-114` |
| BR-8 | Navigasi (K-1 a): nilai filter **tetap aktif** saat membuka folder, klik breadcrumb, "Back to Archive"; filter lain tetap di-reset seperti sekarang. Reset mengosongkannya, tetap di folder saat ini. Scan QR mengosongkan semua filter termasuk ini (seperti sekarang) | K-1 a; `ArchivePage/index.js:249-278,472-481` |
| BR-9 | Paginasi & sort: `total`/halaman = baris yang tersisa (semua folder + dokumen cocok); ganti filter → halaman 1; sort dan urutan default (`type`, `name`) tetap | `ArchiveService.php:124-132` |
| BR-10 | Hanya pemegang **`Opname Document`** (1139, lewat nama, EPIC K-4 a). Tanpa itu filter tidak ada di panel filter halaman & picker dan di Display Setting Archive; server ikut menahan (ED-1027 K-9 a = EPIC K-3 a): `GET archives` `queries` tanpa entri, key `verificationStatus` diabaikan seolah tidak dikirim (200, bukan 403), `GET setting/display-columns/documentArchive` tanpa entri, `PUT` oleh pemanggil itu mempertahankan entri tersimpan | J-5; ED-1027 BR-18/19; `ArchiveService.php:38-62,82-87`; `ColumnDisplaySettingService.php:28-32,142-154,218-234` |
| BR-11 | Display setting: entri di `current_queries` dan `available_queries` sesudah **Type**; pemegang boleh menyembunyikan/mengurutkannya. Config berubah → Updater membuat ulang baris `documentArchive` (pilihan Archive yang pernah diubah kembali default; konvensi + ED-1033; DU-5 a) | `v5-be-conventions` SKILL.md:71-74; `Updaters/2026_10_07_14_13_16_…php` |
| BR-12 (rev) | **Picker** folder tujuan (modal Pindahkan, langkah Pilih Folder pindah massal; `ArchiveTable`) menampilkan filter ini dan berperilaku **sama dengan halaman** (K-2 b): hanya baris dokumen yang disaring, **semua folder tetap tampil dan tetap bisa dipilih sebagai tujuan**; sendiri/bersama Show Related Transaction tetap di folder picker saat ini; tetap aktif saat buka folder & klik breadcrumb di picker; Reset/hapus nilai mengosongkannya, tetap di folder; bersama filter lain = cari datar dari root (filter lain di picker tetap lompat ke root, ED-1125 K-3 a); scan di picker mengosongkan semua filter (seperti sekarang). Picker dibuka dengan filter kosong, tidak mewarisi nilai halaman (K-5 a). Aturan lama tetap: folder terpilih & tanpa View tidak bisa dibuka, cari picker ED-1125, `listKey` ED-1150, pilihan tujuan tidak di-reset oleh filter/navigasi | K-2 b, K-5 a; `ArchiveTable/index.js:34-42,68-111,173-182`; `ArchiveMoveModalForm.js:25-63`; `Search/index.js:83-121` |
| BR-13 | Aksi tidak berubah: aksi baris folder (Pindahkan, Opname, Hapus, mode Pilih + pindah massal) bekerja atas seluruh isi folder termasuk dokumen tersembunyi; refresh sesudah aksi mempertahankan filter | J-3; `ArchivePage/index.js:566-627` |
| BR-14 | Nilai `verificationStatus` selain tepat `VERIFIED` / `UNVERIFIED` (huruf kecil, angka, boolean, `null`, `""`, list, objek) diabaikan seolah tidak dikirim, 200, tanpa 500 | D-6; ED-1070 AC-4 |

## 3. Model data

| Hal | Isi |
|---|---|
| Tabel baru/berubah | Tidak ada. Dibaca (tenant): `archives` (`type`, `is_verified`) dalam query list yang sudah ada |
| Config display setting | `Config/displayColumn/documentArchive.php` `current_queries` (`:143`) dan `available_queries` (`:190`), sesudah entri `type` (`:162`, `:209`): `title {en: 'Verification Status', id: 'Status Verifikasi'}`, `data_index: 'verificationStatus'`, `type: 'select'`, `endpoint: NULL`, `options: [{en: 'Verified', id: 'Terverifikasi', value: 'VERIFIED'}, {en: 'Unverified', id: 'Belum terverifikasi', value: 'UNVERIFIED'}]` (tanpa `initial_value`/`required`) |
| Updater | Baru: `Modules/UpdateVersion/Updaters/<YYYY_MM_DD_HH_ii_ss>_AddVerificationStatusFilterArchive.php`, isi sama dengan ED-1033 (hapus baris `column_display_settings` `documentArchive`, lalu `MyHelper::getDisplaySetting('documentArchive')`) |
| Permission / kode pesan | Tidak ada yang baru. `Opname Document` lewat `VerificationService::PERMISSION` (`:44`) / FE `permissions.OpnameDocument`; `ARCHIVE200/400/407`, `GE0114` tetap |
| Konstanta | Usulan di `VerificationService`: `FILTER = 'verificationStatus'`, `FILTER_VERIFIED = 'VERIFIED'`, `FILTER_UNVERIFIED = 'UNVERIFIED'` |
| `ArchiveService::index` (rev) | (1) nilai dibaca dari `$searchParams` hanya bila `$canVerify` dan sah (BR-14); key selalu masuk `$searchExcepts` (`:50`) agar `isSearching()`/`searchAll` tidak menganggapnya mencari (`CustomizeBuilderGuard::searchMayApply` menganggap key config "dipakai" bila tidak dikecualikan). (2) Tanpa `Opname Document`: key dibuang dari `$searchParams` seperti `withoutVerificationSearch` (`:46-49`; `mentionsVerification` tidak menangkapnya) dan item dibuang dari `$settings['queries']` seperti kolom (`:82-87`). (3) Filter aktif: satu `where` sebelum paginate: `type = FOLDER` **atau** `(type = FILE dan is_verified = 1)` (Verified) / `(type = FILE dan (is_verified <> 1 atau NULL))` (Unverified); mode hirarki dan cari. **Tidak** memakai `counts()` untuk menyaring (angka tetap hanya untuk `document_verified`/ringkasan) |
| `ColumnDisplaySettingService` | `PERMISSION_FILTERS['documentArchive']` (`:29`) + `'queries' => [VerificationService::FILTER]`. `show()`: tanpa permission → item dibuang dari `current_queries`/`available_queries` (sesudah saringan lisensi `:82-100`). `update()`: item dikembalikan dari baris tersimpan, aturan sisip sama dengan kolom (`keepHiddenColumns`/`restoreColumns` `:218-270`). "Seluruh modul" = entri **tanpa `columns` dan tanpa `queries`** (cek `:149`, `:167`; gate1 O-1/C-1) |
| Performa (rev) | Satu kondisi tambahan di query list yang sama: tanpa query tambahan, tanpa query per baris, tanpa ketergantungan pada kedalaman pohon. `counts()` tetap dihitung seperti sekarang untuk pemegang (±100-120 ms, ED-1027). QA DB: 29 folder, 34.531 dokumen aktif |

## 4. Endpoint v5

Prefix `api/v5`. Bentuk lengkap: `contract.md`. Tidak ada route baru.

| Route | Controller@method | FormRequest | Service | Permission | Status |
|---|---|---|---|---|---|
| GET `document-archive/archives` | `ArchiveController@index` (`:28`) | - | `ArchiveService::index`: `search.verificationStatus` (BR-3..10, 14); `queries` disaring tanpa `Opname Document`. Dipakai halaman dan picker | `List Archive` (`Routes/DocumentArchive/api.php:23`) | ubah |
| GET `setting/display-columns/{moduleName}` | `ColumnDisplaySettingController@show` | - | `ColumnDisplaySettingService::show`: peta saringan juga `queries` | - (`Routes/Setup/setting.php:64`) | ubah |
| PUT `setting/display-columns/{moduleName}` | `ColumnDisplaySettingController@update` | validasi inline | `ColumnDisplaySettingService::update`: item `queries` tersaring dikembalikan | - (`setting.php:63`) | ubah |
| GET `archives/{id}`, `verifications*`, `opnames*` | - | - | - | - | sudah ada |

## 5. Perubahan FE per module

**Archive** (`src/containers/Archive`, master dengan aksi baris kustom). Blueprint = Archive; preseden "filter yang tetap di folder"
= Show Related Transaction (`ArchivePage/index.js:232-235`, `ArchiveTable/index.js:76-77`). Panel filter dirender dari `result.queries`
`GET archives` (`useTable` → `getQueries`, select opsi statis `tableHelper.js:501-525`): tanpa komponen, endpoint, atau locale baru.
Penyaringan baris sepenuhnya di server; FE tidak menyaring `dataSource`.

| Bagian | Perubahan |
|---|---|
| Helper (`archive.function.js`) | Satu tempat aturan, dipakai halaman **dan picker**: (a) key yang tidak mengubah struktur = `showRelatedTransaction`, `verificationStatus`; isian hanya berisi key itu → tetap di folder (hapus `idArchive` dari request); ada key lain → `idArchive: null` seperti sekarang. (b) search saat navigasi = `{ verificationStatus }` bila terisi, selain itu `null`. Unit test di `archive.function.test.js` |
| `ArchivePage` | `_onFinishFilter` (`:224-238`) memakai (a). Navigasi `onClickName :249-263`, `onClickBreadcrumb :265-271`, `onBackToArchive :274-278` (K-1 a): `run({ idArchive, search: <b> })`; sesudah `form.resetFields()` nilai `verificationStatus` diisi lagi ke form (popover & indikator `filtered` tetap). Scan QR (`:472-481`) tidak berubah |
| `ArchiveTable` (K-2 b) | `queries` tidak disaring (filter tampil). `_onFinishFilter` (`:68-80`) memakai (a) menggantikan cek `onlyRelatedTrans`; `onClickName` (`:91-103`) dan `onClickBreadcrumb` (`:105-111`) memakai (b) + isi ulang field, sama dengan halaman. Tetap: `isFolderLocked` (`:86-89`), `listKey` (`:34-36`), scan picker (`Search` tanpa `onScanSearch` → `resetFields` + `query`, `Search/index.js:95-121`), pilihan tujuan di `ArchiveMoveModalForm`/`ArchiveBulkMoveModalForm`. Picker dibuka tanpa nilai filter (K-5 a) |
| Permission | Tidak disaring FE: server tidak mengirim entri tanpa `Opname Document` (BR-10), sama dengan kolom Document Verified (ED-1027 §5) |
| Dok FE | `docs/specs/Archive/027-*` (gate1 O-4; `028` milik ED-1251), `_module.md` dan `_api.md` disunting berurutan dengan ED-1251 |
| Tidak berubah | Kolom Document Verified, tautan V/T, ringkasan, modal & tab Verification (ED-1027); mode Pilih; aksi baris |

## 6. Acceptance criteria

QA DB `api_sidomaju`, role QA ber-`Opname Document` (preset) kecuali disebut lain; "fixture" = data QA sendiri (§6a); "oracle" =
hitungan dari tabel mentah tanpa kode BE (pola `q27_oracle` ED-1027).

| AC | Given / When / Then | Layer | BR | Cek |
|---|---|---|---|---|
| AC-1 | Given Updater dijalankan, When `GET archives`, Then `result.queries` memuat `verificationStatus` tepat sesudah `type`: `title` en "Verification Status" / id "Status Verifikasi", `select`, `endpoint` null, tepat 2 opsi (`VERIFIED` Verified/Terverifikasi, `UNVERIFIED` Unverified/Belum terverifikasi); baris cds `documentArchive` memuatnya di current & available; lainnya = config | BE | 1, 11 | http + DB |
| AC-2 (rev) | Root + `VERIFIED`: semua baris `id_archive_parent` null; himpunan baris folder **sama** dengan tanpa filter; dokumen semua `verified=1` dan setiap dokumen root verified ada (semua halaman); `total` = folder root + dokumen root verified (oracle); `breadcrumbs` `[]`, `access` null; sama untuk `UNVERIFIED`. Tanpa 500 | BE | 3, 4 | http + SQL |
| AC-3 (rev) | Folder fixture F (3 level), `id_archive=F` + `UNVERIFIED`: semua subfolder anak F tampil (termasuk 0/0, all-verified, tanpa dokumen cocok), dokumen anak F hanya yang unverified; `VERIFIED` kebalikannya untuk dokumen, folder tetap semua; tidak ada baris dari level lain; `breadcrumbs`/`access` = tanpa filter | BE | 3, 4 | http |
| AC-4 | Baris yang ada di hasil berfilter dan tanpa filter: `document_verified` sama; `document_verified_summary` dan `columns` sama (root, F, mode cari) | BE | 5 | http |
| AC-5 (rev) | User fixture non-bypass: folder P Izin On tanpa View tetap ada di hasil kedua nilai dengan `document_verified` null dan `access.view` false, seperti tanpa filter; dokumen di luar lokasi tetap tidak tampil; `id_archive=P` + filter → 403 `ARCHIVE407` seperti tanpa filter; superadmin 1/2 seperti tanpa batas hak | BE | 6 | http (berjurnal) |
| AC-6 (rev) | Mode cari: `{"type":<tx>,"verificationStatus":"VERIFIED"}` → dokumen tipe tx verified semua level; `{"type":"FOLDER","verificationStatus":"UNVERIFIED"}` → identik dengan `{"type":"FOLDER"}`; `{"name":"QA50-","verificationStatus":"UNVERIFIED"}` → semua folder fixture yang cocok nama + dokumen fixture unverified yang cocok nama, termasuk dokumen di folder tanpa View; `{"showRelatedTransaction":true,"verificationStatus":"VERIFIED"}` + `id_archive=F` → semua folder anak F + dokumen verified anak F + `related_transactions` | BE | 7, 4 | http |
| AC-7 | `pagination=2` + filter: gabungan semua halaman = oracle tanpa duplikat; `sorts` name desc berlaku; tanpa sort = `type`, `name` | BE | 9 | http |
| AC-8 | Preset `List Archive` tanpa `Opname Document`: `search={"verificationStatus":"VERIFIED"}` (root, `id_archive=F`, bersama `name`) → 200, `data`/`total`/urutan identik dengan tanpa key; `queries` tanpa entri; tanpa `document_verified*` (ED-1027 AC-22 tetap) | BE | 10 | http (preset) |
| AC-9 | Pemegang, `verificationStatus` = `"verified"`, `"X"`, `1`, `0`, `true`, `null`, `""`, `[]`, `{"a":1}` → 200 identik dengan tanpa key; bersama `type` = hasil `type` saja; tanpa 500 | BE | 14 | http |
| AC-10 | `GET setting/display-columns/documentArchive`: tanpa `Opname Document` → current/available queries tanpa entri (kolom tetap tanpa `documentVerified`); pemegang → ada; `customer` tidak berubah; `documentArchiveOpnameHistory` tanpa permission tetap 403 `GE0114`. **Bila item ini di-unpark sesudah commit ED-1251** (gate1 §4): 2 modul hasil opname ED-1251 tetap 403 untuk non-pemegang dan tidak ada di index R0 | BE | 10, 11 | http |
| AC-11 | `PUT` `documentArchive` oleh role tanpa `Opname Document` (urutan query diubah + `updatedAt` hanya di available) → 200; DB: perubahan lain tersimpan, `verificationStatus` tetap di available & current sesudah item pendahulunya; `documentVerified` tetap (ED-1027 AC-24). Pemegang memindahkan entri keluar dari current → `GET archives` `queries` tanpa entri. Baris dipulihkan | BE | 10, 11 | http + DB |
| AC-12 | Probe in-process `ArchiveService::index` (root dan folder level 3): jumlah query SQL dengan filter = tanpa filter; waktu dicatat (informasi) | BE | 3 | unit (probe `DB::listen`) |
| AC-13 | Archive terbuka tanpa error (root, folder, hasil cari); popover memuat select "Verification Status" (en) / "Status Verifikasi" (id), 2 opsi sesuai bahasa, kosong default; tanpa memilih → request tanpa key. Drawer Display Setting Archive terbuka tanpa error, tab Displayed Filters memuat entri | FE | 1, 11 | e2e (id & en) |
| AC-14 (rev) | Root, pilih Unverified → satu `GET archives` dengan `search` hanya `{"verificationStatus":"UNVERIFIED"}`, tanpa `id_archive`; breadcrumb "Archive"; baris = respons: semua folder root tetap tampil, dokumen hanya ikon unverified; V/T folder & ringkasan sama dengan sebelum filter. Dalam F pilih Verified → `id_archive=F` tetap, breadcrumb & tombol tambah tetap | FE | 3, 4, 5 | e2e (log request) |
| AC-15 | (K-1 a) Filter aktif: buka subfolder, klik breadcrumb, "Back to Archive" → tiap request membawa key, popover menampilkan nilai, indikator menyala; Reset → tanpa `search`, tetap di folder; scan QR → `search` hanya `query`, field kosong | FE | 8 | e2e |
| AC-16 | Filter + Type (atau Name) → request membawa keduanya, `id_archive` null (datar, breadcrumb "Archive"); buka folder dari hasil → hirarki dengan hanya `verificationStatus` | FE | 7, 8 | e2e |
| AC-17 | Unit helper: `{verificationStatus}` dan `{verificationStatus, showRelatedTransaction}` → tetap di folder; `{verificationStatus, type}` → `idArchive: null`; `{}` → `search: null`; search navigasi = hanya `{verificationStatus}` atau `null`. Halaman dan `ArchiveTable` memakai helper yang sama | FE | 4, 8, 12 | unit (`archive.function.test.js`, `ArchiveTable/index.test.js`) |
| AC-18 (rev) | (K-2 b) Picker Pindahkan (⋯ dokumen) dan Pilih Folder pindah massal terbuka tanpa error; popover picker memuat filter, kosong saat dibuka walau filter halaman aktif (K-5 a); pilih Unverified di root picker → request tanpa `id_archive`, breadcrumb picker "Archive", **semua folder tetap**, dokumen hanya unverified; di folder picker F → `id_archive=F` tetap; buka subfolder & klik breadcrumb picker → key ikut, nilai tetap; Reset → tanpa `search`, tetap di folder picker; + Type/Name → `id_archive` null (datar dari root); folder terpilih/tanpa View tetap tidak bisa dibuka; folder kosong tetap bisa dipilih sebagai tujuan, Pindahkan (fixture) sukses dengan filter aktif; request halaman tidak berubah oleh filter picker | FE | 12 | e2e (log request) |
| AC-19 | Role tanpa `Opname Document`: Archive (root, folder, cari) dan picker Pindahkan/Pilih Folder terbuka tanpa error, tanpa filter Verification Status; drawer Display Setting Archive tanpa entri, simpan sukses; tanpa 5xx | FE | 10, 12 | e2e (preset) |
| AC-20 | Pemegang: sembunyikan entri di Displayed Filters → simpan → tidak ada di panel halaman & picker; tampilkan lagi → kembali; baris dipulihkan | FE | 11 | e2e |
| AC-21 (rev) | Folder fixture tanpa subfolder dan tanpa dokumen verified + Verified → tabel kosong (Empty), paginasi 0, tanpa error (halaman & picker); folder induknya tetap tampil saat filter aktif | FE | 3, 12 | e2e |
| AC-22 | Regresi dengan filter aktif: kolom Document Verified, tautan V/T + modal folder, ringkasan + modal All (ED-1027 AC-13/16/26); halaman 2 tetap berfilter; refresh sesudah Store/Hand Over/Receive tetap berfilter; Pindahkan folder fixture saat filter aktif memindahkan seluruh isinya (SQL). Skenario dijalankan ulang (gate1 §3/§8): ED-1027 scenario1, 2, 9, 10 dan ED-1028 scenario9 (menguji cek seluruh modul `:149/:167`); yang usang hanya dicatat | BE+FE | 5, 9, 13 | http + e2e |
| AC-23 | Letak filter di popover halaman & picker dan label sesuai gaya EQUAL | FE | 1, 12 | manual (Gate 2) |
| AC-24 | Regresi picker tanpa memakai filter: e2e ED-1125 (cari modal Pindahkan/Pilih Folder: Enter/ikon filter → Cari = 0 navigasi, 1 GET search, scan di picker) dan ED-1069 (validasi pindah) hijau; ED-1150: tabel picker & halaman mulai dari awal saat folder/halaman/filter berganti (`listKey` memuat `search`), daftar pendek tidak terpotong; yang usang karena field baru di popover hanya dicatat | FE | 12 | e2e (ED-1125, ED-1069) + unit |

### 6a. Data uji & lingkungan

| Hal | Isi |
|---|---|
| Keadaan `api_sidomaju` (probe baca 2026-10-23) | 29 folder aktif (12 root, 13 level 2, 4 level 3), 34.531 dokumen aktif, **22 verified** (semua di folder level 2), 3 sesi terkonfirmasi, tanpa baris `QA%`; baris cds `documentArchive` ada (diubah 2026-10-08); permission 1082/1139/1140 ada |
| Fixture sendiri | Prefix `QA50-`: pohon 3 level (folder kosong, all-verified, campuran, tanpa subfolder dan tanpa verified, Izin On tanpa View, lokasi terbatas, dokumen root) + `archive_documents`, `archive_locations`, `archive_permissions`; dibuat & dibuang dengan pola helper ED-1027 (`features/ED-1027-archive-status-verifikasi/qa/qa_lib.php`: `q27_add`, `q27_set_archive`, `q27_purge`), prefix & jurnal sendiri. Sapu sisa `QA50-` dulu |
| Status verified | (K-4 a) `is_verified`/`verified_at`/`verified_by` di-set langsung pada dokumen fixture; **tanpa sesi opname**, tanpa tulis `archive_opname*` |
| Di luar baris sendiri | (1) Updater membuat ulang baris cds `documentArchive` (+ `updater_logs` bila lewat pipeline): **DU-5 a** - beda di snapshot ED-1149 dicatat "diizinkan" di teardown, bukan FAIL; pilihan lama tidak dipulihkan. (2) AC-11/AC-20 mengubah baris itu → snapshot & pulihkan ke keadaan sesudah Updater. (3) Role preset `QA50-ROLE-*` + `user_roles` QA_USER/QA_USER2, lokasi employee & bahasa QA_USER sementara, berjurnal (pola `q27_with_user`, `q27_with_u2_preset`). Tidak ada tabel dikosongkan/di-reset |
| Teardown (C-8) | Updater tetap terpasang: sesudah run baris cds `documentArchive` **wajib memuat** `verificationStatus` (current & available) dan `documentVerified`; cek tidak ada `.fixture-journal.json` basi di `features/*/qa` (`displayRestore` e2e ED-1027/1028 akan memulihkan baris tanpa filter); bila baris kehilangan entri → FAIL teardown |
| Lingkungan | **DU-6 a**: developer tidak memakai Archive/opname/display setting/role/karyawan `api_sidomaju` dan QA_USER* sampai run selesai. Lisensi Salesman Activity aktif; QA_USER ber-`Opname Document` lewat preset (ED-1149 BR-1); QA_USER2 opsional; `force_login` mencabut token lain user QA yang sama (P-5); asersi root memakai oracle, bukan angka tetap |

## 7. Subtask

| Kunci | Judul | Layer | AC | Module |
|---|---|---|---|---|
| ED-1252 | [BE] Updater - filter Verification Status di display setting Archive | BE | 1 | UpdateVersion, `displayColumn/documentArchive.php` |
| ED-1253 | [BE] List Archive - filter Verification Status (hanya dokumen, struktur folder tetap) | BE | 2-9, 12 | DocumentArchive (`ArchiveService::index`, konstanta `VerificationService`) |
| ED-1254 | [BE] Display setting - filter Verification Status hanya untuk Opname Document | BE | 10, 11 | Setup/Setting (`ColumnDisplaySettingService`) |
| ED-1255 | [FE] Archive - filter Verification Status di list (tetap di folder, tetap aktif saat navigasi) | FE | 13-17, 19-21, 23 | Archive (`ArchivePage`, `archive.function.js`, dok `027`) |
| ED-1256 | [FE] Archive - filter Verification Status di picker Pindahkan / Pilih Folder | FE | 17-19, 21, 24 | Archive (`ArchiveTable` + test) |
| ED-1257 | [QA] Skenario filter Verification Status | QA | semua (22, 24 regresi) | - |

Urutan: Updater → list → display setting → FE list → FE picker → QA. `PERMISSION_FILTERS` juga disentuh ED-1251 (gate1 O-1/C-1):
kerjakan berurutan sesuai rank (1250 → 1251).

## 8. Keputusan untuk developer

Terjawab Gate 1 ronde 6 (2026-10-24, `epics/ED-1022-gate1.md` §9) dan koreksi developer 2026-10-24:

| # | Pertanyaan | Jawaban |
|---|---|---|
| K-1 | Filter tetap aktif saat buka folder / breadcrumb / Back? | **a** - tetap; filter lain di-reset, Reset/scan QR mengosongkan (BR-8) |
| K-2 | Filter di picker Pindahkan / Pilih Folder? (rek. a: sembunyikan) | **b** - tampil dan berlaku seperti di halaman (hanya baris dokumen disaring, semua folder tetap): tetap di folder picker, tetap saat navigasi picker, Reset mengosongkan, + filter lain = datar dari root; filter lain di picker tetap lompat ke root; disembunyikan tanpa Opname Document (BR-12) |
| K-3 | Folder tanpa View saat filter aktif? | **gugur** - koreksi developer: filter tidak pernah menyembunyikan folder, jadi folder tanpa View tetap tampil dengan `-` seperti sekarang (BR-3, BR-6) |
| K-4 | Data uji perlu sesi opname? | **a** - `is_verified` langsung pada fixture, tanpa sesi (§6a) |
| K-5 | Picker dibuka dengan filter halaman? | **a** (default, folder tidak pernah disembunyikan) - picker dibuka dengan filter kosong seperti filter lain yang tidak diwariskan; akibatnya hanya pada baris dokumen picker (BR-12) |
| DU-5, DU-6 | cds permanen; jendela run | **a**, **a** (§6a) |

Tidak ada pertanyaan terbuka. Sudah diputuskan, bukan pertanyaan: tanpa `Opname Document` key diabaikan (200) dan entri disaring server
(ED-1027 K-9 a = EPIC K-3 a); arti Verified/Unverified (ED-1027 K-1 a); Updater reset baris display setting (konvensi BE + ED-1033,
DU-5 a); input salah bentuk = 200 diabaikan (ED-1070); filter lain di picker tetap lompat ke root (ED-1125 K-3 a, developer 2026-10-24);
folder tidak pernah disaring filter ini (koreksi developer 2026-10-24).

## 9. Di luar cakupan

- Filter Verification Status di modal opname (Step 1 pilih folder), Opname History/Result, modal & tab Verification (angka tetap semua).
- Menyembunyikan folder berdasarkan isinya (koreksi developer 2026-10-24); menyimpan pilihan filter antar sesi/muat ulang; deep link.
- Mengubah perilaku filter lain (tetap datar, di picker tetap lompat ke root), scan QR, mode Pilih; sort berdasarkan status verified.
- Menulis status verified (ED-1026); export PDF/Excel opname → **ED-1251** (entri `PERMISSION_FILTERS` miliknya, dok `028`).
- Perilaku diterima A-1 (PUT display setting isi sama di detik yang sama = 500) dan A-8 (`pagination` tanpa batas): dicatat "diterima" bila terlihat.

## Tambahan Gate 1 (2026-10-24, disetujui developer; epic-check r6 §8)
- AC-10 langkah unpark sesudah ED-1251: modul pembanding = `documentArchiveOpnameResult` (bukan "2 modul hasil opname").
- §6a / AC-24: e2e ED-1150 (`run-sam.sh`, login `sam`) **tidak dijalankan**; regresi `listKey` lewat e2e lain dan unit.
- Jawaban Gate 1: `epics/ED-1022-gate1.md` §9 dan §9b (K-1 a, K-2 b, K-3 gugur, K-4 a, K-5 a default, DU-5 a, DU-6 a).

## Catatan implementasi
- 2026-10-24 v5-be-dev (ED-1253): `verificationStatus` **diambil dari `search` sebelum `searchAll`/`isSearching`** (bukan lewat
  `$searchExcepts`); nilai sah disimpan terpisah lalu diterapkan sebagai satu `where`. Akibatnya nilai tidak sah apa pun, termasuk
  teks non-latin1 (yang lewat `$searchExcepts` akan memicu `searchMatchesNothing` = hasil kosong), sama persis dengan tanpa key (BR-14).
- 2026-10-24 v5-be-dev (ED-1253): kondisi saringan = "buang hanya `type = FILE` yang statusnya tidak sama"
  (`type <> FILE OR type IS NULL OR is_verified = 1` / `... OR is_verified <> 1 OR is_verified IS NULL`), bukan `type = FOLDER OR (...)`.
  Di data identik (`archives.type` hanya 1/2 di QA DB); baris non-dokumen tidak pernah hilang (BR-3 rev). `contract.md` §1 disesuaikan.
- 2026-10-24 v5-be-dev (ED-1254): `ColumnDisplaySettingService` - `hiddenColumns`/`keepHiddenColumns` menjadi
  `hiddenItems`/`keepHiddenItems` (peta list → data_index lewat `FILTERED_LISTS`), cek seluruh modul lewat `isWholeModule()`
  (tanpa `columns` dan tanpa `queries`). ED-1251 menambah entrinya di `PERMISSION_FILTERS` memakai bentuk yang sama.
- 2026-10-24 v5-be-dev (ED-1252): opsi config memakai literal `'VERIFIED'`/`'UNVERIFIED'` (config tidak memuat kelas service; preseden
  `use` di `Config/displayColumn/*` hanya model/helper); konstanta `VerificationService::FILTER*` dipakai di service.
- 2026-10-26 v5-be-dev (ED-1252, review R-1): kode Updater tetap (hapus baris + `getDisplaySetting`), hanya docblock; urutan baru datang dari
  config. Di `api_sidomaju` efeknya dijalankan ulang in-process (`$updater->run()` dalam transaksi, tanpa `updater_logs`): cds
  `documentArchive` id 2260 → 2586, jumlah baris tabel tetap 364.
- 2026-10-26 v5-be-dev (ED-1254, review R-2): saringan index hanya di respons (baris tidak ditulis), dicocokkan per `module_name` seperti
  `permissionFilter()`; `hasPermission` dipanggil sekali per permission per request, hanya bila baris berentri ada di halaman. Akibat
  ikutan: `result` respons PUT (controller memanggil `index()`) untuk non-pemegang ikut tersaring. `contract.md` §1 dan §2 disesuaikan.
- 2026-10-26 orkestrator (QA FE r2 D-1, dijawab dari kode FE dengan bukti, tanpa mengubah AC): halaman "Setup > Display Setting" yang disebut AC-25
  **tidak di-route di FE** - `DisplaySettingPage` (`ksp-react` `src/containers/DisplaySetting/DisplaySettingPage`) tanpa entri di `src/routes/routes.js`,
  `src/routes/paths.js` (`paths.displaySetting` tidak ada) dan `src/configuration/menus.js` sejak `c61af18d0`; itu satu-satunya pemanggil FE `GET setting/display-columns`
  (index), jadi tidak ada layar FE yang bisa rusak oleh R-2. Bagian FE AC-25 dicek pada layar display setting FE yang ada: `DisplaySettingDrawer` Archive
  (`GET`/`PUT setting/display-columns/documentArchive`) pemegang vs non-pemegang, tanpa error/5xx, ditambah respons index dari sesi browser; doc FE 027
  butir 23 dan digest `_api.md` #30 diselaraskan. Ke batch review: bila developer ingin halaman itu dipasang (route + menu Setup), itu item terpisah.

## Revisi review 2026-10-26 (developer, `/v5-epic review`; menimpa butir lama yang bertentangan)
- **R-1 urutan default filter** (Updater + `Config/displayColumn/documentArchive.php`): `current_queries` = Name (`name`), Type (`type`), Date Modified
  (`updatedAt`), Show Related Transaction (`showRelatedTransaction`), **Verification Status (`verificationStatus`) terakhir**. `available_queries` = urutan
  yang sama + Modified By (`updatedBy`) paling akhir, tidak aktif default (tidak ada di current). AC-1 direvisi: `queries` memuat `verificationStatus` di
  indeks terakhir current (sesudah `showRelatedTransaction`), bukan "tepat sesudah `type`". Updater ED-1250 belum dirilis (commit lokal): ubah Updater yang
  sama; di `api_sidomaju` jalankan ulang hasilnya (DU-5 a tetap berlaku).
- **R-2 daftar display setting tersaring** (O-2, keputusan b): `GET setting/display-columns` (index) untuk pemanggil tanpa `Opname Document` tidak lagi
  memuat entri `verificationStatus` (queries) maupun `documentVerified` (kolom ED-1027) di `current_*`/`available_*` baris `documentArchive`, memakai peta
  yang sama dengan `show`/`update` (BR-10). Pemegang: tidak berubah. AC-25 baru: index non-pemegang tanpa kedua entri, pemegang dengan keduanya, modul lain
  identik; halaman Setup > Display Setting FE terbuka tanpa error.
- Dikonfirmasi tanpa perubahan: Updater reset `documentArchive` per tenant (DU-5 a); Cari kosong saat filter aktif hanya Verification Status / Show Related
  Transaction tetap di folder.
