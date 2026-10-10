---
key: ED-1027
epic: ED-1022
title: Archive - Status verifikasi dokumen (kolom Document Verified, tab Verification, detail per transaction type)
status: approved
batch: ED-1027
fe_modules: [Archive]
new_fe_modules: []
be_modules: [DocumentArchive, UpdateVersion, Setup/Setting]
qa_model: sonnet
contract: contract.md
depends_on: [ED-1024, ED-1025, ED-1026]
---
## Revisi review 2026-10-08
Sumber: `epics/ED-1022-review-feedback.md` § ED-1027 + `epics/ED-1022-ba2-notes.md`. Dasar = kode terpasang (BE 49be126c7..44bea5136,
FE 48bbad16e..7fa63869d). BR/AC tanpa **(rev)** sudah terimplementasi dan tetap berlaku.

| Kode | Perubahan | BR / AC | Layer |
|---|---|---|---|
| FB-1 | `V/T` baris folder di list Archive = tombol tautan → `ArchiveVerificationModal` varian folder (isi = tab Verification); pakai ulang `GET verifications/{id}`. Menggantikan K-7 a | BR-2, 17; AC-13, 26, 27 | FE |
| FB-8b | Tanpa permission `Opname Document`: kolom Document Verified, entrinya di display setting, ringkasan header, modal (All & folder), tab Verification tidak ada. Server ikut menahan (K-9 a = EPIC K-3 a): `GET archives` tanpa kolom/angka, `verifications*` 403, display setting disaring + entri tersimpan dipertahankan | BR-9, 15, 18, 19; AC-17, 22-25 | BE+FE |
| ED-1026 K-14 a, ED-1028 K-10 b | Scope 3 sesi = "<folder / Semua Arsip · root> + N subfolder" (`scope.subfolder_count`); `scope` tanpa `first_name`/`other_count`; diubah ED-1026 di `getScopeTag`/`OpnameService::scope()`, di sini regresi | BR-12; AC-28 | (ED-1026) |
| ED-1026 N-4 | id-ID `archive.All Archive` = "Semua Arsip": subjudul modal All, Scope root | BR-7; AC-16, 28 | (ED-1026) |
| ED-1026 FB-3 | Hanya sesi terkonfirmasi di DB: AC-10 tanpa bagian draft; helper QA sesi → satu `POST opnames` (`scans` + `selected_at`) | AC-10 | QA |
| Sinkron kode | Modal tanpa footer (tutup ✕), layar penuh di mobile (developer, FE 7fa63869d) | BR-7; AC-16 | - |
| Tetap | Terjemahan id-ID (sudah dicek developer); +100-120 ms `GET archives`; gulir horizontal tabel modal/tab (menunggu klien) | - | - |

**Gate 1 ronde 2 (2026-10-08) diterapkan** (`epics/ED-1022-gate1.md` §9b, §1-§8): K-9 a = EPIC K-3 a (BR-15/18/19, AC-22..25);
O-3 peta saringan juga menerima entri seluruh modul (§3, §4, contract §4); C-7 (AC-24); C-3 + ED-1028 K-10 b `scope` (BR-12,
AC-28, contract); EPIC K-4 a id 1139 (BR-15, §3); §5 QA (§7, AC-29). ED-1070/ED-1071 ditunda: tanpa dependensi.

## 1. Ringkasan

Hasil opname (item 03) terlihat di halaman Archive tanpa membuka opname: kolom **Document Verified** (folder `verified / total`,
dokumen ikon verified/unverified), ringkasan **"Document Verified V / T"** di atas breadcrumb untuk seluruh Archive, modal **05
Detail verifikasi per Transaction Type** (angka per transaction type + 3 sesi opname terakhir), dan tab **Verification** read-only di
drawer folder. Angka hanya menghitung dokumen yang boleh dilihat user (lokasi 01 + View folder 02). Item ini **hanya membaca**.
**(rev)** Angka folder di list bisa diklik dan membuka modal verifikasi folder itu; seluruh tampilan verifikasi hanya untuk pemegang
permission `Opname Document`, dan server tidak mengirim/menghitung data verifikasi untuk yang lain. Tanpa tabel baru, tanpa Updater baru.

## 2. Aturan bisnis

| BR | Aturan | Sumber |
|---|---|---|
| BR-1 | List Archive punya kolom **Document Verified** (sesudah Salesman, sebelum Status), ada di kolom aktif & tersedia display setting, tidak bisa di-sort, bukan filter | [S2 01]; `Config/displayColumn/documentArchive.php` |
| BR-2 (rev) | Baris folder: `verified/total` folder itu (K-3: folder + semua subfolder terlihat); di tabel halaman Archive nilainya tautan (BR-17), di tabel picker Pindahkan/Bulk Move teks biasa. Baris dokumen: ikon centang hijau (verified) / abu (unverified), tidak bisa diklik. Folder tanpa View (02 K-6 a): `-`, tidak bisa diklik | [S2 01]; 02 BR-10; FB-1 |
| BR-3 | "Total" = dokumen archive aktif `type = 2`, `is_active > 0`, status apa pun (di folder, Handed Over, Taken); `-1` (tercetak belum disimpan) dan `0` (dihapus) tidak | `ArchiveService.php:52`; `DocumentService.php:159` |
| BR-4 | "Verified" = status verified **saat ini** (`archives.is_verified`, ditulis Confirm Opname 03); "Unverified" = total − verified; ganti hari tidak me-reset (K-1) | R-13, R-14; K-1 |
| BR-5 | Dokumen dihitung = yang boleh dilihat user: scope lokasi per baris (01) **dan** di folder ber-View (02) atau di root; superadmin 1/2 semua. Angka folder = isi yang user lihat saat membuka folder itu | PK-2 a; 01 BR-1..5; 02 BR-4/5; K-2 |
| BR-6 | Ringkasan di atas breadcrumb: "Document Verified **V** / T" + progress + "seluruh dokumen di sistem" + tautan "Lihat detail per transaction type ›". Selalu **All Archive** (tidak ikut folder, pencarian, filter, halaman); diperbarui setiap tabel dimuat | [S2 01] |
| BR-7 (rev) | Tautan BR-6 membuka modal **Document Verified** subjudul "All Archive — seluruh dokumen di sistem" (id: "Semua Arsip — …", ED-1026 N-4): V / T + progress + "V dokumen terverifikasi dari T dokumen di seluruh folder"; tabel BR-8; overview BR-10; catatan "Handover, Receive, dan Store adalah aktivitas Archive…". Read-only, **tanpa footer** (tutup ✕), layar penuh di mobile (FE 7fa63869d). Angka header = ringkasan BR-6 | [S2 05]; [S4 hal.4]; R-4 |
| BR-8 | Tabel Transaction Type · Verified · Unverified · Total: satu baris per tipe 01 (`ArchiveDocument::TRANSACTION_TYPES`), urutan & label = filter Type; tipe 0 tetap tampil; baris **Total** = angka header. Handover/Receive/Store bukan baris | R-23; [S4 hal.4]; 01 BR-11/12 |
| BR-9 (rev) | Drawer folder ("Lihat") punya tab **Verification** read-only, tidak ikut Save: V / T folder + progress + "… di folder ini" (= angka kolom BR-2), tabel BR-8 folder itu, overview BR-11, catatan "Read-only. …". Tab hanya untuk pemegang `Opname Document` (BR-18); urutan Detail, Izin (hanya `Folder Permissions`, ED-1025 FB-4a), Verification | [S2 02]; 02 BR-15; FB-8b |
| BR-10 | Overview modal All: maks **3 sesi terkonfirmasi terbaru** yang terlihat user (K-4), terbaru di atas; Waktu (tanggal + jam), User, Scope, Total dokumen, Verified, Not found, Invalid = angka saat dikonfirmasi | [S2 05]; R-4 |
| BR-11 | Overview folder F (tab & modal folder): maks 3 sesi terkonfirmasi terbaru yang **mencakup F** (F atau subfoldernya, K-3); Waktu, User, Scope sesi, **Dokumen folder**, **Verified di folder ini** (potongan F saat sesi). Tanpa Not found/Invalid | [S2 02 keterangan] |
| BR-12 (rev) | Scope sesi = tag ED-1026: "<nama folder opname \| All Archive · root> + N subfolder", N = `scope.subfolder_count` (subfolder dicentang di Step 1), tanpa akhiran bila N = 0; id-ID root "Semua Arsip · root". Satu helper `getScopeTag` (milik ED-1026). `scope` di API tepat `{is_root, folder_name, subfolder_count}` (nama subfolder tidak dikirim) | R-15; ED-1026 BR-28/30, K-14 a; ED-1028 K-10 b |
| BR-13 (rev) | CTA "Lihat semua history ›" membuka riwayat ED-1028 dengan konteks overview-nya: modal All → semua sesi; tab & modal folder → sesi yang mencakup folder itu. Tanpa handler CTA tidak dirender (K-6) | [S2 02, 05]; R-24; ED-1028 BR-1 |
| BR-14 | Tanpa dokumen: "0 / 0", progress 0 %, semua tipe 0. Tanpa sesi: tabel overview kosong | turunan BR-6..11 |
| BR-15 (rev) | Archive hanya untuk lisensi Salesman Activity. `GET archives` = `List Archive`; `verifications*` = **`Opname Document`** (id **1139**, EPIC K-4 a; `permission_checks` (1139,1082) di `permission_salesman.sql`, seed diubah ED-1025, jadi pemegangnya juga punya `List Archive`; kode memakai nama). Tanpa permission/lisensi: 403 `GE0114`, tanpa data | R-2; 01 K-4; FB-8b; K-9 a = EPIC K-3 a |
| BR-16 | Detail folder: id tidak ada / nonaktif → 404 `ARCHIVE400`; di luar lokasi → 403 `ARCHIVE407`; tanpa View → 403 `ARCHIVE407`; id dokumen → 400 `ARCHIVE417` | D-6; 01 `findVisible`; 02 `assert` |
| BR-17 (rev) | Di tabel halaman Archive (root, dalam folder, hasil pencarian, juga mode Select tanpa mengubah pilihan), nilai `V/T` baris folder = antd `Button type="link"` kelas `min-h-0 p-0`. Klik → `ArchiveVerificationModal` folder itu: judul "Document Verified", subjudul nama folder, **satu tampilan tanpa tab**, isi = tab Verification folder itu (BR-9, BR-11, CTA BR-13); tanpa footer, layar penuh di mobile. Klik tidak membuka folder, tidak memilih baris, tidak mengubah breadcrumb. Folder hilang/tanpa View saat diklik → pesan error server, isi kosong (tanpa angka 0 palsu) | FB-1 (Teks asli butir 1) |
| BR-18 (rev) | Tanpa permission `Opname Document` tidak ada tampilan verifikasi: kolom Document Verified (halaman & picker), entrinya di Display Setting `documentArchive` (drawer di Archive dan halaman Display Setting), ringkasan header + tautan, modal Document Verified (All & folder), tab Verification. Tampilan Archive lain tidak berubah | FB-8b (Teks asli butir 8) |
| BR-19 (rev) | Server menahan untuk pemanggil tanpa `Opname Document` (K-9 a = EPIC K-3 a): `GET archives` tanpa `documentVerified` di `columns`, tanpa `document_verified`/`document_verified_summary`, tanpa menghitung; `verifications*` 403 `GE0114`; `GET setting/display-columns/documentArchive` tanpa entri `documentVerified`; `PUT` oleh pemanggil itu **mempertahankan** entri tersimpan (display setting satu baris per tenant, `ColumnDisplaySetting.php:25-28`; tanpa ini simpan oleh user tanpa permission menghapus kolom untuk semua user) | K-9; `ItemTransferReportService.php:43-53,336-351`; `ColumnDisplaySettingService.php:55-73` |

Kolom v3: Archive tidak ada di v3 dan item ini tidak menulis tabel → tidak ada kolom v3 yang dijaga.

## 3. Model data

| Hal | Isi |
|---|---|
| Tabel baru/berubah | Tidak ada. Dibaca (tenant): `archives` (`is_verified`), `archive_documents`, `archive_locations`, `archive_permissions`, `archive_opnames` (`status=2`), `archive_opname_folders` (level 0-2) |
| Display setting | `Config/displayColumn/documentArchive.php`: kolom `documentVerified` (`{en: 'Document Verified', id: 'Dokumen Terverifikasi'}`, `string`, `sort: false`) di current & available (terpasang). `excepts` `selectBuilderQuery` (`ArchiveService.php:50`). **(rev)** config tetap; saringan runtime (BR-19) → **tanpa Updater baru** |
| Updater | ED-1033 `2026_10_07_14_13_16_AddDocumentVerifiedColumnArchive.php` (terpasang). (rev) tidak ada |
| Permission | Tidak ada yang baru. (rev) dipakai `Opname Document` (id **1139**, module 1266; EPIC K-4 a, seed `permission_salesman.sql` diubah ED-1025 - item ini tidak mengubah seed; BE `permission_v5`/`hasPermission` dan FE `permissions.OpnameDocument` memakai nama, tanpa id literal) |
| Kode pesan | Tidak ada yang baru. `ARCHIVE200`, `ARCHIVE400`, `ARCHIVE407`, `ARCHIVE417`, `GE0114` |
| Service | `VerificationService` (terpasang): satu tempat hitungan, sesi terlihat, `sessionRow`; dipakai ED-1028. **(rev)** `ArchiveService::index` cek `MyHelper::hasPermission('Opname Document')` (`MyHelper.php:521`) sekali per request; tanpa → lewati `counts()` (`ArchiveService.php:45-47`), buang kolom & field |
| (rev) Saringan display setting | `ColumnDisplaySettingService`: satu peta (konstanta) `module_name` (sesudah `alias`/`specialCase`, `:27,80`) → `permission` + `columns`. **(rev, O-3) dua jenis entri, keduanya dibuat item ini:** (1) per `data_index`: `show` membuang item dari current/available (sesudah saringan lisensi `:55-73`), `update` menyisipkan kembali dari baris tersimpan (contract §4); (2) seluruh modul (tanpa `columns`): tanpa permission → `show`/`update` 403 `GE0114` (`parameter` = nama permission, pola `PresetReportService.php:123-129`), dicek sebelum baris dibuat (`:33-38`) / ditulis. Cek `MyHelper::hasPermission` (`:521-532`, tanpa bypass superadmin = `permission_v5`). Isi item ini: `documentArchive → Opname Document → [documentVerified]`; **ED-1028** menambah entri seluruh modul `documentArchiveOpnameHistory` + saringan `index`, dan menguji jenis (2) (AC-28) |
| Model 03 | A-1..A-5 terpenuhi (Catatan implementasi). ED-1026 rev: skema tetap, `status` selalu 2; (rev Gate 1) `scope()` = `{is_root, folder_name, subfolder_count}` (dipakai `VerificationService.php:313`, tanpa perubahan kode di item ini) |

## 4. Endpoint v5

Prefix `api/v5`. Bentuk lengkap: `contract.md`.

| Route | Controller@method | FormRequest | Service | Permission | Status |
|---|---|---|---|---|---|
| GET `document-archive/archives` | `ArchiveController@index` | - | `ArchiveService::index`: `document_verified` per baris + `document_verified_summary`; (rev) tanpa `Opname Document`: tanpa keduanya, `columns` tanpa `documentVerified`, tanpa hitungan | `List Archive` | ubah (rev) |
| GET `document-archive/verifications` | `VerificationController@index` | - | `VerificationService::detail(null)` | (rev) `Opname Document` (dulu `List Archive`, `Routes/DocumentArchive/api.php:55`) | ubah (rev) |
| GET `document-archive/verifications/{id}` | `VerificationController@show` | - | `VerificationService::detail($id)`; (rev) dipakai juga modal folder FB-1, tanpa perubahan | (rev) `Opname Document` (`api.php:56`) | ubah (rev) |
| GET `setting/display-columns/{moduleName}` | `ColumnDisplaySettingController@show` | - | `ColumnDisplaySettingService::show`: (rev) peta saringan §3 - entri `data_index` → buang item (BR-19); entri seluruh modul → 403 `GE0114` (O-3) | - (tetap, `Routes/Setup/setting.php:61-65`); cek permission di service | ubah (rev) |
| PUT `setting/display-columns/{moduleName}` | `ColumnDisplaySettingController@update` | validasi inline (`:31-35`) | `ColumnDisplaySettingService::update`: (rev) entri `data_index` → sisip kembali dari baris tersimpan; entri seluruh modul → 403 `GE0114` tanpa tulis (O-3) | - (tetap) | ubah (rev) |
| GET `setting/display-columns` | `@index` | - | tidak diubah item ini (saringan baris = ED-1028 K-9 a) | - | sudah ada |

Route `verifications` tetap `Route::prefix('verifications')` sendiri; middleware sama dengan `opnames/folders` (`api.php:43`).

## 5. Perubahan FE per module

**Archive** (`src/containers/Archive`, master dengan aksi baris kustom). Tampilan mengikuti EQUAL, struktur & alur dari desain.

| Bagian | Perubahan |
|---|---|
| Render kolom (terpasang) | `archive.function.js:602-668` `getDocumentVerifiedCell`/`renderDocumentVerified`/`withDocumentVerifiedRender`, dipakai `ArchivePage/index.js:331` dan `ArchiveTable/index.js:112` (picker Pindahkan & Bulk Move). **(rev)** opsi handler `onOpenFolder(row)`: bila ada (hanya `ArchivePage`), folder `V/T` = `<Button type="link" className="min-h-0 p-0">` dengan `stopPropagation` (pelajaran ED-1072); `-` dan ikon dokumen tetap; picker tanpa handler = teks biasa |
| `ArchiveVerificationSummary` (terpasang) | baris di atas `.breadcrumbs`, data `documentVerifiedSummary` milik `useTable`; tidak dirender bila tidak ada (`:20`) |
| `ArchiveVerification` (terpasang) | isi bersama, prop `idArchive` (`null` = All) + `onOpenHistory`; fetch `GET verifications[/{id}]` saat mount; gagal = toast + `Empty` |
| `ArchiveVerificationModal` (rev) | `onOpen({ idArchive, name } = {})` (`:23`): tanpa argumen = All (ringkasan); folder = subjudul nama folder, `ArchiveVerification idArchive={id}`, CTA → `history.onOpen({ idArchive, name })` (seperti `ArchiveDrawer/index.js:58-59`). Tanpa `Tabs`; `destroyOnClose`, `footer={null}`, `modal-fullscreen-mobile` tetap. Tanpa teks baru |
| `ArchivePage` (rev) | `canOpname` (`:167`): `ArchiveVerificationSummary` (`:505-511`) dan `ArchiveVerificationModal` (`:610-614`) hanya dirender bila true; handler FB-1 → `modalVerification.current.onOpen({ idArchive: row.idArchive, name })` diteruskan ke render kolom. Kolom & entri display setting tidak disaring FE (server, BR-19) |
| `ArchiveDrawer` (rev) | tab `verification` (`:83-87`) hanya bila `usePermission({ permission: permissions.OpnameDocument })`, pola sama dengan tab Izin ED-1025 rev; `ArchiveOpnameHistory` tidak di-mount tanpa permission. ED-1025 juga mengubah `extraTabs` → dikerjakan sesudah ED-1025 |
| Registrasi (terpasang) | `endpoints.js` `getArchiveVerifications`, `getArchiveVerification`; `archive.api.js`; locale `archive.*`. (rev) tidak ada endpoint/locale baru |

## 6. Acceptance criteria

QA DB = `api_sidomaju`; sesi & verified dibuat lewat endpoint opname 03 (rev: satu `POST opnames`), dipulihkan sesudahnya. Role QA
untuk AC-1..21, 26-29 punya `Opname Document` (rev: id 1139 sesudah seed ulang run ED-1025; grant & cek dicari per nama, bukan id); AC-22..25 memakai role dengan `List Archive` tanpa `Opname Document` (dipulihkan).

| AC | Given / When / Then | Layer | BR | Cek |
|---|---|---|---|---|
| AC-1 | Given Updater dijalankan, When `GET archives`, Then `result.columns` memuat `documentVerified` sesudah `document.relatedEmployeeName`, sebelum `history`, `sort=false`; `column_display_settings` `documentArchive` memuatnya | BE | 1 | http + DB |
| AC-2 | List root/folder/pencarian: tiap folder `document_verified.{verified,total}` = dokumen aktif terlihat di folder + subfoldernya (SQL), dokumen `total=1, verified∈{0,1}`, folder tanpa View `null`; tidak ada 500 | BE | 2,3,5 | http + SQL |
| AC-3 | Dokumen `is_active=-1`/`0` tidak dihitung; Handed Over (`status=2`) & Taken (`3`) dihitung | BE | 3 | http |
| AC-4 | `document_verified_summary` sama di root, di folder, dengan `search`, dan = header `GET verifications` | BE | 6,7 | http |
| AC-5 | `GET verifications`: `verified+unverified=total`; `transaction_types[]` = daftar 01 (urutan & label = `select/document-archive/archive/types` tanpa "Folder"); tipe 0 ada; jumlah kolom = header | BE | 7,8 | http |
| AC-6 | `GET verifications/{F}`: header = `document_verified` F di list induknya; jumlah baris tipe = header | BE | 9 | http |
| AC-7 | User lokasi terbatas vs superadmin: angka user terbatas hanya dari yang terlihat (BR-5); folder tanpa View tidak ikut di angka induk & ringkasan | BE | 5 | http dua user |
| AC-8 | `GET verifications/{id}`: tak ada → 404 `ARCHIVE400`; dokumen → 400 `ARCHIVE417`; di luar lokasi → 403 `ARCHIVE407`; tanpa View → 403 `ARCHIVE407`; tidak ada 500 | BE | 16 | http |
| AC-9 | Role tanpa `List Archive` → `GET archives` 403 `GE0114`; seed tidak berubah; tukar lisensi sungguhan manual di Gate 2 epic | BE | 15 | http (EPIC K-2 b) |
| AC-10 (rev) | Given ≥4 sesi terkonfirmasi (dibuat lewat `POST opnames`), When `GET verifications`, Then `last_sessions` = 3 terbaru, urut waktu turun, field sesuai kontrak, angka = saat konfirmasi; user terbatas melihat sesi sesuai K-4 termasuk sesi root superadmin (EPIC K-1 a); sesi yang semua foldernya kini tak terlihat hanya untuk superadmin 1/2 & pembuatnya. (Bagian "sesi belum dikonfirmasi" dihapus: ED-1026 FB-3) | BE | 10,12 | http |
| AC-11 | Sesi yang hanya mencakup subfolder G dari F muncul di `GET verifications/{F}`, sesi tanpa F tidak; `folder_total_documents`/`folder_verified` = snapshot potongan F | BE | 11 | http |
| AC-12 | Confirm Opname memverifikasi X di F → X `verified=1`, angka F, induk, ringkasan, baris tipe X naik 1; sesi ulang tanpa lanjut yang tidak men-scan X → turun lagi | BE | 4 | http (alur 03 rev) |
| AC-13 (rev) | Archive terbuka tanpa error di root, folder, pencarian; kolom tampil (folder `V/T` sebagai tautan, dokumen ikon, `-` folder tanpa View); ringkasan = `documentVerifiedSummary` | FE | 1,2,6 | e2e |
| AC-14 | Display Setting Archive terbuka tanpa error; Document Verified bisa disembunyikan/ditampilkan, tabel tetap tampil | FE | 1 | e2e |
| AC-15 | Modal Pindahkan & Bulk Move terbuka tanpa error; kolom di picker dirender (teks biasa, bukan objek mentah) | FE | 2 | e2e |
| AC-16 (rev) | Tautan ringkasan → modal All tanpa error, satu `GET verifications`, header = ringkasan, tabel tipe + Total, overview 3 sesi, catatan; subjudul id "Semua Arsip — seluruh dokumen di sistem" / en "All Archive — …"; tanpa footer, ✕ menutup | FE | 7,8,10 | e2e (id & en) |
| AC-17 (rev) | Drawer folder: tab Verification ada (pemegang `Opname Document`), terbuka tanpa error, `GET verifications/{id}` hanya saat tab dibuka, angka = kolom folder; Detail tetap terisi; Save hanya field Detail/Izin | FE | 9,11 | e2e (request body) |
| AC-18 | Folder kosong "0 / 0" + progress 0 %; tanpa sesi tabel kosong; tanpa NaN | FE | 14 | unit + e2e |
| AC-19 | CTA tidak dirender tanpa `onOpenHistory`; baris sesi tidak bisa diklik | FE | 13 | unit |
| AC-20 | Regresi: sort, filter Type, pencarian, scan QR, breadcrumb, mode Select, Store/Hand Over/Receive | BE+FE | - | e2e |
| AC-21 | Tampilan sesuai desain 01/02/05, gaya EQUAL | FE | 1-11 | manual (Gate 2) |
| AC-22 (rev) | Role tanpa `Opname Document`, When `GET archives` (root, folder, `search`), Then `columns` tanpa `documentVerified`, baris tanpa key `document_verified`, result tanpa `document_verified_summary`; baris, urutan, paginasi, field lain identik dengan user yang sama ber-`Opname Document` | BE | 18,19 | http (tukar role) |
| AC-23 (rev) | Role `List Archive` tanpa `Opname Document` → `GET verifications` dan `/{id folder}` 403 `GE0114` tanpa `result`; dengan `Opname Document` → 200 (AC-5/6) | BE | 15,19 | http |
| AC-24 (rev) | `setting/display-columns/documentArchive`: tanpa `Opname Document` GET tanpa entri `documentVerified` (current & available); PUT dari role itu (ubah urutan + sembunyikan kolom lain) → 200, perubahan lain tersimpan, baris DB tetap memuat `documentVerified` di `available_columns` dan di `current_columns` sesudah kolom yang tadinya mendahuluinya; role ber-permission GET melihat entri; modul lain (mis. `customer`) tidak berubah (rev C-7: `documentArchiveOpnameHistory` milik ED-1028 AC-28) | BE | 19 | http + DB `column_display_settings` (dipulihkan) |
| AC-25 (rev) | Profil role tanpa `Opname Document`: Archive (root, folder, pencarian) terbuka tanpa error, tanpa kolom Document Verified, tanpa ringkasan header; Display Setting Archive terbuka tanpa error tanpa entri itu, simpan sukses; drawer folder tanpa tab Verification; picker Pindahkan/Bulk Move tanpa kolom; tidak ada request `verifications*` | FE | 18 | e2e (role tanpa permission) |
| AC-26 (rev) | Klik `V/T` folder (root, dalam folder, hasil pencarian) → modal terbuka tanpa error: satu `GET verifications/{id folder}` per buka, subjudul nama folder, tanpa tab, header = nilai sel, tabel tipe + Total, overview varian folder (BR-11), catatan folder; CTA → riwayat folder itu tanpa error; ✕ menutup, buka lagi = request baru; tautan ringkasan tetap membuka varian All | FE | 13,17 | e2e |
| AC-27 (rev) | Sel folder = `button.ant-btn-link` berkelas `min-h-0` dan `p-0` berisi `V/T`; `-` dan ikon dokumen bukan tombol; picker Pindahkan/Bulk Move teks biasa; klik tidak membuka folder, tidak mengubah baris terpilih/breadcrumb; mode Select: pilihan tetap | FE | 2,17 | unit (`archive.function.test.js`) + e2e |
| AC-28 (rev) | Regresi ED-1026 rev: `last_sessions[].scope` = `scope` `GET opnames/{id}`, kunci tepat `is_root`, `folder_name`, `subfolder_count` (tanpa `first_name`/`other_count`, ED-1028 K-10 b); kolom Scope = format BR-12 (en & id, root "Semua Arsip · root" di id) di modal All, tab, modal folder | BE+FE | 7,12 | http + e2e (id & en) |
| AC-29 (rev) | Regresi ED-1028: CTA dari modal All, tab, dan modal folder membuka riwayat dengan konteks benar tanpa error; skenario QA ED-1028 dijalankan; yang usang (gate1 §5 baris 1028 + `scenario1-list.php:177-189`) hanya **dicatat**, tidak diubah | BE+FE | 13 | e2e + skenario ED-1028 |

## 7. Subtask

| Kunci | Judul | Layer | AC | Module |
|---|---|---|---|---|
| ED-1033 | [BE] Updater - kolom Document Verified di display setting Archive | BE | 1 | UpdateVersion |
| ED-1039 | [BE] Hitungan verifikasi di list Archive (kolom per baris + ringkasan All Archive) | BE | 2-4, 7, 12 | DocumentArchive |
| ED-1045 | [BE] Endpoint detail verifikasi All Archive & per folder + 3 sesi terakhir | BE | 5, 6, 8-11 | DocumentArchive |
| ED-1051 | [FE] Archive - kolom Document Verified & ringkasan header | FE | 13-15, 18 | Archive |
| ED-1057 | [FE] Archive - modal Detail verifikasi per Transaction Type | FE | 16, 18, 19 | Archive |
| ED-1061 | [FE] Archive - tab Verification di drawer folder | FE | 17-19 | Archive |
| ED-1065 | [QA] Skenario status verifikasi dokumen | QA | semua | - |
| (rev) | [BE] Verifikasi hanya untuk permission Opname Document (list Archive & endpoint verifications) (rev) | BE | 22, 23 | DocumentArchive (`ArchiveService::index`, route) |
| (rev) | [BE] Display setting - peta saringan per permission (entri kolom & seluruh modul); Document Verified disaring & dipertahankan saat simpan (rev) | BE | 24 (cabang seluruh modul: ED-1028 AC-28) | Setup/Setting (`ColumnDisplaySettingService`) - sebelum subtask BE ED-1028 |
| (rev) | [FE] Archive - klik Document Verified folder membuka modal verifikasi folder (rev) | FE | 26, 27, (13, 16) | Archive |
| (rev) | [FE] Archive - sembunyikan tampilan verifikasi tanpa permission Opname Document (rev) | FE | 25, (17) | Archive |
| (rev) | [QA] Skenario revisi status verifikasi + regresi ED-1026/ED-1028 (rev) | QA | 10, 22-29 + ulang 1-21 | - |

QA (rev, gate1 §5 - QA item ini memperbarui foldernya sendiri): helper sesi `q27_store/q27_session/q27_scan/q27_confirm/q27_run`
(`qa/qa_lib.php:521-575`) → satu `POST opnames` (`scans` + `selected_at`); `DELETE opnames/{id}`
`qa/scenario5-sessions.php:54` dibuang (AC-10); kunci & contoh `scope` `qa/scenario5-sessions.php:103-118` →
`is_root, folder_name, subfolder_count` (AC-28 rev); fixture e2e hanya sesi `status=2` (`qa/e2e/fixture.php:249-259`); teks id
"All Archive" → "Semua Arsip" dan Scope format BR-12 di `qa/e2e/ED-1027-verifikasi.spec.js:618,649,833`; jumlah tab drawer
bergantung permission (Izin = `Folder Permissions`, Verification = `Opname Document`; ED-1025 AC-3 rev). Role uji mendapat
`Opname Document` 1139 lewat nama. Skenario item lain yang usang hanya dicatat (AC-29).

**Jira subtask (rev), dibuat 2026-10-08 (sprint 46):** ED-1095 [BE] verifikasi per permission · ED-1096 [BE] peta saringan display setting · ED-1097 [FE] klik Document Verified folder · ED-1098 [FE] sembunyikan verifikasi · ED-1099 [QA]

## 8. Keputusan untuk developer

Terjawab Gate 1 (2026-10-06), tetap kecuali K-7:

| # | Pertanyaan | Jawaban |
|---|---|---|
| K-1 | Arti verified lintas hari | a - status bertahan sampai opname berikutnya (G-5) |
| K-2 | Dokumen yang dihitung | a - lokasi 01 + View 02, superadmin semua (G-1) |
| K-3 | Angka folder | a - rekursif folder + subfolder terlihat; overview = sesi yang mencakup subtree (G-5) |
| K-4 | Sesi di overview | a + EPIC K-1 a: sesi mencakup folder terlihat, sesi root untuk semua, sesi yatim hanya superadmin 1/2 + pembuat (G-1). (rev EPIC K-3 a) "semua" = semua pemegang `Opname Document` |
| K-5 | Baris overview bisa diklik | a - tidak (G-7) |
| K-6 | CTA sebelum 05 | a - tidak dirender tanpa handler |
| K-7 | Angka `V/T` folder bisa diklik | a (teks biasa) - **diganti review 2026-10-08 FB-1**: tautan → modal verifikasi folder (BR-17) |
| K-8 | Dokumen root di ringkasan | a - ikut (G-5) |
| EPIC K-1 | Sesi root & sesi yatim | a (rev EPIC K-3 a: pembaca = pemegang `Opname Document`) |
| EPIC K-2 | Cara cek AC lisensi | b |

Revisi (Gate 1 ronde 2):

- **K-9** (tinggi) FB-8b: server ikut menahan data verifikasi bagi user tanpa `Opname Document`? a) server + FE (`GET archives`
  tanpa kolom/field/hitungan, `verifications*` 403 `GE0114`, display-columns disaring + dipertahankan); b) server hanya
  display-columns; c) FE saja. Rek. a. = ED-1028 K-8 (EPIC K-3).
  Jawaban Gate 1 (2026-10-08): a - server + FE (= EPIC K-3 a); BR-15/18/19, AC-22..25, peta saringan §3 (O-3).
- Lintas item: **EPIC K-4** Jawaban Gate 1 (2026-10-08): a - id 1139, seed ED-1025, item ini memakai nama. **ED-1028 K-10**
  Jawaban Gate 1 (2026-10-08): b - `scope` tanpa `first_name`/`other_count` (ED-1026). **ED-1026 K-14** Jawaban Gate 1
  (2026-10-08): a - satu format Scope (BR-12). **ED-1070, ED-1071** Tidak berlaku: ditunda (task lain), tanpa dependensi.

## 9. Di luar cakupan

- Menulis status verified, sesi/hasil scan, modal opname, `getScopeTag`/`OpnameService::scope()` (rev: tanpa `first_name`/`other_count`, juga test `ArchiveVerification/index.test.js:28,126,222`, O-8), locale "Semua Arsip", FB-8a (tanpa tampilan opname) → **ED-1026**.
- Drawer riwayat semua sesi, hasil satu sesi, FB-8c (riwayat, permission `GET opnames*`, entri seluruh modul `documentArchiveOpnameHistory` di peta saringan item ini, saringan `index`) → **ED-1028** (memakai `VerificationService` dan peta saringan display setting, O-3).
- Scope lokasi & transaction type → **ED-1024**; hak View, tab Izin + permission `Folder Permissions`, FB-9 (tidak mengubah View → angka tidak berubah) → **ED-1025**; Set Permission by User → **ED-1029**; klik ⋯ baris dokumen → **ED-1072**.
- (rev) Tautan `V/T` di tabel picker Pindahkan/Bulk Move ("di list Archive" saja); daftar modul `GET setting/display-columns` (index) tidak disaring item ini (`documentArchive` milik semua; baris modul seluruh-modul → ED-1028 K-9 a).
- Filter/sort berdasarkan status verified; verified otomatis saat cetak.
- R-1, R-3, R-5..R-22, R-24..R-28 milik item lain (`epics/ED-1022-plan.md`).

## Catatan implementasi
- (v5-be-dev, 2026-10-07) Model 03 = A-1..A-5: verified = `archives.is_verified`; sesi = `archive_opnames` `status=2` (angka saat Confirm, `scope` lewat `OpnameService::scope()`); folder per sesi = `archive_opname_folders` level 0-2; indeks `(status, confirmed_at)`, `(id_archive, id_archive_opname)`. 03 menghitung `is_active = 1`, item ini `> 0` (data hanya -1/0/1, hasil sama).
- (v5-be-dev) "Sesi mencakup F" = baris folder level 0-2 untuk F atau subfolder F yang **saat ini** terlihat (pohon sekarang), satu definisi SQL (`VerificationService::whereCovers` + `subtreeIds`); potongan = jumlah snapshot baris itu.
- (v5-be-dev) Sesi terlihat = `VerificationService::sessionQuery($context)` (aturan contract objek bersama; `created_by` = username, kolasi `latin1_general_ci`). `folder_verified` = jumlah `verified_count` (contract §3).
- (v5-be-dev) Angka folder: subfolder tak terlihat terputus beserta turunannya. Ringkasan All per dokumen (root + setiap folder terlihat).
- (v5-be-dev) Dokumen tanpa transaction type dikenal: di total, tanpa baris tipe (hanya data lama; QA DB 0).
- (v5-be-dev) `GET verifications/{id}`: id di luar ASCII cetak / > 30 karakter → 404 `ARCHIVE400` tanpa query; 403 tanpa View lewat `ArchivePermissionService::assert` (`parameter` = nama folder penolak).
- (v5-be-dev) Biaya list ±100-120 ms per `GET archives` (34,5 rb dokumen), tanpa cache static (D-3); (rev) dilewati tanpa `Opname Document`.
- (fe-dev 2026-10-07) Ringkasan disembunyikan di layar akses ditolak & bila respons tanpa `documentVerifiedSummary`; baris Total dari header; gagal muat = toast + `Empty`; modal `destroyOnClose`; tab Verification lazy.
- (v5-be-dev 2026-10-09, rev ED-1095) `VerificationService::PERMISSION` (`Opname Document`) + `COLUMN` (`documentVerified`). `ArchiveService::index`: satu `MyHelper::hasPermission` per request; tanpa → `context()`/`counts()` tidak dipanggil, `documentVerified` dibuang dari `columns` di service itu sendiri (bukan lewat peta display setting), key `document_verified`/`document_verified_summary` tidak dibuat. Route `verifications*` `permission_v5:Opname Document`.
- (v5-be-dev 2026-10-09, rev fix r1) Tanpa `Opname Document`, `ArchiveService::index` membuang key `search` / item `sorts` yang menyebut `VerificationService::ARCHIVE_COLUMNS` (`is_verified`, `verified_at`, `verified_by`, `id_archive_opname`; bentuk snake/camel, `tabel.kolom`, `where...()`, key di dalam filter relasi) sebelum `searchAll`/`sortAll`, seolah tidak dikirim (contract §1); kolom itu juga masuk except `searchAll`. `CustomizeBuilder` tidak diubah. Peta display setting memakai `VerificationService::PERMISSION`/`COLUMN`.
- (v5-be-dev 2026-10-09, rev fix r1b) `ArchiveService::show` (`GET archives/{id}`): tanpa `Opname Document` key `VerificationService::ARCHIVE_COLUMNS` dibuang dari `result` (folder & dokumen), key lain tetap (contract §1b). FE tidak membaca key itu dari detail.
- (v5-be-dev 2026-10-09, rev ED-1096) Peta `ColumnDisplaySettingService::PERMISSION_FILTERS`; entri tanpa `columns` atau `columns` kosong = seluruh modul (gagal-tertutup), dicek di `show` sebelum query/buat baris dan di `update` sebelum tulis. `data_index` array = kunci `a.b`. PUT: list yang tidak dikirim tidak ditulis; `available_columns: null` diisi item tersimpan yang disaring. Klarifikasi di contract §4, tanpa perubahan field.
