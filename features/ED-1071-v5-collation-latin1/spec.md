---
key: ED-1071
epic: ED-1022
type: bug
title: V5 - input berkarakter di luar latin1 (emoji, CJK, Yunani) = 500 "Illegal mix of collations" (Archive)
status: approved
batch: ED-1071
fe_modules: []                # FE tetap (K-6 a, Gate 1 r3)
new_fe_modules: []
be_modules: [DocumentArchive, Select/DocumentArchive, Entities/Helper]
qa_model: sonnet
contract: null                # bentuk tetap; hanya 422 baru GE0748
depends_on: [ED-1025, ED-1029, ED-1069]   # 1069 selesai; rev3 1025/1029 dulu (berkas & QA sama); bukan ED-1070 (ditunda)
---

## Ringkasan
Teks di luar latin1 MySQL (= Windows-1252: Yunani, CJK, emoji, aksen gabung, RTL, UTF-8 rusak) yang dibandingkan dengan kolom
`latin1_general_ci` Archive = 500; yang hanya disimpan jadi `?`. Keputusan developer (P1 2026-10-12 = K-1 a): skema tetap, input itu
**ditolak**, tidak pernah 500 atau `?`. Semua endpoint + select Archive, termasuk rename (ED-1069 B-3) dan `folders` ED-1029 rev3.
Karakter latin1 (`ü ’ — € ™`) tetap diterima utuh.

## Reproduksi
`api_sidomaju`, `QA_USER`, GET 2026-10-12, BE `581569981`, nilai `x`+karakter:

| Request | `Ω`/`😀` | `€` |
|---|---|---|
| `archives?id_archive=`, `archives/{id}`, `history/{id}`; `user-permissions/{id}` | 500 1267 | 404 |
| `archives?search={"query":..}` (root, dalam folder) | 500 | 200 `total` 0 |
| select `archive/users` `search`/`excepts[]`/`selected_id`; `archive/locations` `search`/`id_archive_parent` | 500 | 200 / 404 |
| UTF-8 rusak `x%C3%28`: `?id_archive`, select users/locations | 500 Malformed UTF-8 | - |

Tulis (ED-1025 X-12 `qa/scenario5-input-types.php:358-416`, 80 kasus,
QA ED-1069 di `878314df7` = HEAD untuk Archive): semua 500, juga **rename** (dulu 200 `?`, kini `UniqueNameRule` ED-1069); ED-1029 X-1
(`qa/scenario4-robust.php:13-75`) 500; `description` tersimpan `?`.

## Akar masalah
MariaDB 10.11.14: 514/517 tabel `latin1_general_ci`, koneksi `utf8mb3_unicode_ci`, `sql_mode` tanpa STRICT: parameter dikonversi ke cp1252,
di luar itu → 1267 (`=`, `LIKE`) / 1270 (`IN`), INSERT → `?`. `authorize()` sebelum `rules()` (`ValidatesWhenResolvedTrait.php:15-28`),
`rules()` berkueri, rule kustom membaca field lain: rule per field saja tidak cukup.

Titik lain (selain Perbaikan #3-#6): `Archive/UniqueNameRule.php:54`, `Document/*Rule` via `Archive::findSources`/`findName` (`Archive.php:133-156`),
`exists:` (`UpdateRequest.php:49,54`, `CreateFolderRequest.php:30`, `AddDocumentRequest.php:17`), `CustomizeBuilder.php:92-160`, `MyHelper.php:860-880`.
Preseden: id → 404 (`OpnameService.php:632,971`, `VerificationService.php:351`); cari → tidak cocok (`OpnameService.php:121-125`); scan
`code` → 422 (`ScanRequest.php:27`); tipe salah → 422 tanpa aturan bisnis (`PutInFolderRequest.php:30-33`).

## Perbaikan (BE saja)
| # | Lapisan | Perubahan |
|---|---|---|
| 1 | Helper | `MyHelper::isLatin1` (V5 `Entities/Helper`): UTF-8 sah yang bolak-balik utuh lewat Windows-1252 (`OpnameService.php:953-962`); gagal = S_BAD |
| 2 | Rule | `Rules/DocumentArchive/Latin1Rule`, pesan `Message::getMessage('GE0748')` (`SalesmanIsCheckedInRule.php:41`) → 422; sesudah aturan tipe, sebelum `exists`/rule kustom, `bail` |
| 3 | Request | create-folder & PUT: `name`, `description`, `id_archive_parent`, `id_locations`(+`.*`), `folder_permissions.*.id_user`; rename `name`; put-in `id_archive_parent`, `id_archives`(+`.*`), `name`; hand-over/receive `name`; add-document `id_archive`; PUT user-permissions `permissions.*.id_archive`, `folders.*.id_archive`. Ada field S_BAD → `rules()` hanya aturan tipe/wajib + Latin1Rule (tanpa `isValidParent`, `exists`, rule kustom) |
| 4 | Lookup | Baris = BE 581569981; sesudah ED-1029 rev3 bergeser: cari per method. `findVisible` (`DocumentArchiveService.php:122-132`), `findUser` (`UserPermissionService.php:23-34`): S_BAD → 404 `ARCHIVE400`/`440` tanpa kueri. `assertVisible` (`DocumentArchiveService.php:142-168`), `assertFolders` (`ArchivePermissionService.php:204-222`), `assertCanManage` (`UserPermissionService.php:163-187`), `isValidParent` (`DocumentArchiveService.php:192-203`): S_BAD dilewati/false, **termasuk lookup id gabungan ED-1029 rev3** (`permissions` + `folders` di `UserPermission/UpdateRequest::authorize()`/`assertCanManage`); `lockFolders` tak tercapai dengan S_BAD (422 dulu). Urutan 404/403 → 422 → 400 tetap |
| 5 | List | `ArchiveService::index` (`:42-48,86-88`): `search` ter-decode berisi teks S_BAD (level mana pun) → 200 kosong (`total` 0, `columns` dll. tetap) |
| 6 | Select | `SelectArchiveService.php:19-85` `user`/`location`: `search` S_BAD = tak ada yang cocok (`selected_id` sah tetap ikut); S_BAD di `excepts`/`except`/`selected_id`/`exclude_id` dibuang; `id_archive_parent` S_BAD → 404 `ARCHIVE400` |
| 7 | Kode | `GE0748` (K-4 a) di `en_EN.php` + `id_ID.php`: en "Contains unsupported characters (e.g. emoji, Greek or Chinese letters). Use Latin characters only." / id "Mengandung karakter yang tidak didukung (mis. emoji, huruf Yunani/Mandarin). Gunakan huruf Latin." |

Tanpa Updater/permission/skema/FE; Opname/Verification tetap. Penulisan flag Izin yang diekstrak ED-1029 rev3 ke `ArchivePermissionService`
(EPIC K-5 b) tidak berpengaruh: guard ada di depan (request + lookup), method itu hanya menerima id yang sudah lolos validasi.

## Acceptance criteria
S_BAD = `Ω`, `中文`, `😀`, `e`+U+0301, U+202E, UTF-8 rusak `%C3%28` (query/path); S_OK = `ü ’ — € ™`. `[BE]` = http + DB `api_sidomaju`.

| AC | Given / When / Then | Tag | Cek |
|---|---|---|---|
| AC-1 | create-folder / `PUT archives/{id}` `name`/`description` S_BAD → 422 `errors.<field>` = teks `GE0748` (bahasa user); data & riwayat tetap | BE | http+DB |
| AC-2 | `PUT archives/rename/{id}` `name` S_BAD → 422 `errors.name`, nama tetap (dokumen juga 422, bukan 400 `ARCHIVE419`) | BE | http+DB |
| AC-3 | Field #3 lain S_BAD → 422 di kuncinya (mis. `folder_permissions.1.id_user`, `id_locations.1`, `id_archives.0`, `permissions.0.id_archive`, `folders.0.id_archive`); + nama kembar/`ARCHIVE402` → tetap 422; tanpa tulis | BE | http+DB |
| AC-4 | Id S_BAD: `archives?id_archive=`, `archives/{id}` GET/PUT, `history/`, `rename/`, `delete/`, select `locations?id_archive_parent=` → 404 `ARCHIVE400` (UTF-8 rusak di path: 404 router); `user-permissions/{id}` GET/PUT → 404 `ARCHIVE440` | BE | http |
| AC-5 | `archives?search=` `{"query":S_BAD}` (root, folder), `{"type":S_BAD}` → 200 `total` 0; select `users`/`locations` `search=S_BAD` → `options` []; `excepts[]`/`selected_id` S_BAD = tanpa parameter | BE | http |
| AC-6 | S_BAD di body tetap kalah dari 403: folder di luar scope `ARCHIVE407`, tanpa Update efektif `ARCHIVE408`, role tanpa `Update Folder`/`Folder Permissions` `GE0114` | BE | http |
| AC-7 | S_OK: create/PUT/rename 200, `HEX(name)` cp1252 (`€`=`80`, `’`=`92`); `search={"query":"€"}` menemukannya; `Café`+`Cafe` bersaudara boleh; `CAFÉ` vs `Café` 400 `ARCHIVE405` | BE | http+DB |
| AC-8 | Regresi (sudah benar): `opnames?search` S_BAD 200 kosong; `opnames/{S_BAD}` 404 `ARCHIVE412`; `verifications/`, `opnames/folders?id_archive=` 404 `ARCHIVE400`; select `folders`/`opname-users` []; scan `code` 422 | BE | http |
| AC-9 | QA usang milik item ini: ED-1025 X-12, ED-1029 X-1 (`scenario4-robust.php:12-75`, termasuk probe non-latin1 `folders` dari QA 1029 rev3) dari karakterisasi 500 ke harapan AC-1..AC-5 (0 respons 5xx), lulus; ED-1069 `scenario4-select-related.php:106-110` jadi asersi 404; regresi QA BE 1024-1029, 1069 lulus | BE | http |
| AC-10 | List, drawer folder (Detail, Izin), Izin Arsip by User terbuka tanpa error. Folder baru `QA71 😀` → teks `GE0748` di bawah Nama, modal tetap, tanpa folder; Deskripsi `中文` di drawer → teks `GE0748`; Search `😀` → tabel kosong tanpa error; select User tab Izin `😀` → tanpa opsi/error | FE | e2e `QA_USER` |

**Data uji (Gate 1 r3):** baris QA sendiri (`archives` `QA71-%` + `archive_*`-nya); X-12/X-1 (`QA02-`, `QA29-`, `q5_drop_created`). Regresi
1027/1028 (DU-3 a): baris bersama `column_display_settings` `documentArchive`/`documentArchiveOpnameHistory` dihapus lalu dipulihkan, Updater
ED-1033 diulang + `updater_logs` dipulihkan, `Opname Document` sementara ke role QA_USER2; opname: hanya sesi QA + `QA28BULK%`; semua
dipulihkan persis. DU-1 a: developer tidak memakai api_sidomaju selama run. DU-2: tabel opname kosong (di-truncate developer). Lisensi
Salesman Activity; role 3 QA_USER memegang `Folder Permissions` 1140 + `Opname Document` 1139.

## Keputusan untuk developer
Gate 1 ronde 3 (developer, 2026-10-12): semua a. Tidak ada pertanyaan terbuka.

| K | Keputusan | Akibat |
|---|---|---|
| K-1 | a (P1): validasi karakter tanpa ubah skema, tolak | bukan 500, bukan `?` |
| K-2 | a: per arti | body 422 di field; id 404 seperti id tak ada; cari = kosong (#3-#6) |
| K-3 | a: Archive + select Archive | modul v5 lain = tiket lanjutan (terukur 500: Item, Item Category, PO) |
| K-4 | a: `GE0748` en + id | `GE0747` dipakai branch rewrite (`v5-pgsql`, `v5-new-purchase`, `v5-new-posgresql`) |
| K-5 | a: scan opname ED-1026 tetap ISO-8859-1 | `ScanRequest`/`PreviewRequest`/`StoreRequest` tidak diubah |
| K-6 | a: BE saja | FE memakai `setFieldsError` (`ArchiveForm/index.js:51`) + toast standar |
| K-7 | a: `e`+U+0301 ditolak 422 | tanpa normalisasi NFC / ext intl |

## Di luar cakupan
- Modul v5 selain Archive (K-3); konversi kolasi/utf8mb4; data lama ber-`?` (QA DB: 0 baris `archives`).
- `search` bukan JSON objek / tak ter-decode (termasuk UTF-8 rusak di JSON), `sorts`, `pagination` = ED-1070 (ditunda); lokasi `id_archive_parent=0` = ED-1124.
- Menyatukan guard Opname/Verification ke helper (D-2); kontrak ED-1025/1029 tidak ditulis ulang (422 baru tercatat di sini).

## Catatan implementasi
- (BE dev) #3: deteksi "ada field S_BAD" = `Latin1Rule::failsIn($rules, $this->all())`, dibaca dari atribut `$rules` yang memuat `Latin1Rule` (wildcard ikut) - field yang dideteksi selalu punya Latin1Rule, jadi jalur ringkas pasti 422. Dipakai create-folder, PUT `archives/{id}`, put-in; rename, add-document, hand-over/receive dan PUT user-permissions cukup `Latin1Rule` di rantai `bail` (sebelum `FolderOnlyRule`/`UniqueNameRule`/`exists`/`distinct`), karena `rules()`-nya tidak berkueri.
- (BE dev) #1: `MyHelper::isLatin1($value)` juga menerima array (semua key dan nilai, rekursif; dipakai #5 dan #6) dan bukan-teks = true. Karena bolak-balik Windows-1252 mbstring, U+0081/008D/008F/0090/009D (kontrol C1 yang di MySQL latin1 tetap tersimpan) ikut ditolak.
- (BE dev) #5: S_BAD di key maupun nilai `search` (termasuk `showRelatedTransaction`) = 200 kosong (`whereRaw('1 = 0')`, `searchAll`/filter `type` tidak dijalankan); key yang dibuang `withoutVerificationSearch` tidak dinilai.
- (BE dev) #6 locations: `Location` (di luar `be_modules`) tidak diubah. `search` S_BAD = query di `SelectArchiveService` (`Location::selectFormat` kolom/urutan sama + `whereRaw('1 = 0')` + `MyHelper::apiSelectQuery`, jadi `selected_id` sah tetap ikut); S_BAD di `except`/`excepts`/`selected_id`/`exclude_id` dibuang lewat `$request->merge` (pola `id_location` di method yang sama). users: `search` S_BAD = `1 = 0` di grup utama, `selected_id` sah tetap `orWhereIn`.
- (BE dev) #4: `lockFolders` tidak diubah (tak tercapai dengan S_BAD: `findVisible`/422 dulu). `TakeOutFolderRequest` tidak dipakai rute mana pun, tidak diubah.
- (BE dev, fix QA r1 D-1) #1: U+FFFD ditolak eksplisit di `MyHelper::isLatin1` (`strpos(..., "\xEF\xBF\xBD")`), karena mbstring PHP 7.3 memetakannya bolak-balik ke 0x9D; `OpnameService::isLatin1` (lubang sama) tidak diubah (Opname tetap).
- (BE dev, fix QA r1 D-2) #3: `string` sebelum `Latin1Rule` pada put-in `name`, hand-over/receive `name`, add-document `id_archive` (FE: `name` selalu teks dari scanner `result.text`/input teks; `archives/add-document` tidak dipanggil FE; `id_archive` = key teks); put-in `name` juga masuk `hasValidTypes()` (array → hanya aturan tipe, tanpa `ArchiveSourceRule`/`UniqueNameRule`). Akibat: array/angka di field itu = 422 (dulu `["x"]` 404, angka diterima).
