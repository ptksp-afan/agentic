---
key: ED-1149
epic: ED-1022
type: task
title: "[QA] Archive - skenario regresi ED-1026/ED-1027/ED-1028 tahan data baseline developer"
status: approved              # Gate 1 2026-10-14
batch: ED-1149
fe_modules: []
new_fe_modules: []
be_modules: []
qa_model: sonnet
contract: null
depends_on: []
---

# ED-1149 - Skenario regresi Archive tahan data baseline developer

## 1. Ringkasan

Task QA murni (tanpa kode aplikasi BE/FE, keputusan developer R1 a, `epics/ED-1022-review-feedback.md:143`). Sejak developer
mencentang permission lewat UI role di `api_sidomaju`, 9 skenario BE lama berhenti di **prasyarat data**, bukan karena kode:
role 5 (QA_USER2) memegang `Opname Document` 1139 (baris `permission_name` NULL), role 3 memegang `Folder Permissions` 1140
dengan `permission_name` NULL, dan ada 343 `archives` terverifikasi `updated_by='sam'` (ED-1071 `qa-be.md:65-70`, ED-1025
`qa-fe.md:41` F-1). Task ini menulis ulang skenario itu di folder `qa/` item pemiliknya supaya **tidak bergantung pada
role/data developer**: role preset sementara milik QA (pola `QA25-ROLE-*`/`QA29F-ROLE-*`) yang dipulihkan persis, dan
asersi yang dibatasi ke baris QA sendiri (delta, bukan angka absolut). Hasil yang dirasakan developer: regresi epic bisa
dijalankan penuh tanpa "gagal baseline", dan data developer tidak pernah diubah, bahkan sementara.

## 2. Aturan bisnis (aturan alat uji)

| BR | Aturan | Sumber |
|---|---|---|
| BR-1 | Skenario tidak membaca himpunan permission role developer (role 3, 5, 6, 22, 29) sebagai prasyarat. Butuh "user tanpa X" atau "user hanya List Archive" → role preset QA berprefiks item (`QA26-ROLE-*`, `QA27-ROLE-*`, `QA28-ROLE-*`; `QA25-ROLE-*` sudah ada) | Task ED-1149; pola `ED-1025/qa/e2e/fixture.php:46,153` (`makeRole`), F-1 `ED-1025/qa-fe.md:41` |
| BR-2 | Role preset disusun dari **nama permission eksplisit** dicari ke `permissions.permission_name` → `id_permission` (bukan salinan role 5), baris `role_permissions` diisi `permission_name` dan `created_by` = tag QA. Preset yang dipakai login website wajib memuat permission akses website (role tanpa itu = token dicabut middleware) | `ED-1027/qa/scenario8-user2.php:206` (role 22 dipakai karena "punya Allow Access Website"); `ED-1025/qa/e2e/fixture.php:174-177` |
| BR-3 | Hak dibaca per `id_permission` (join `permissions`), tidak dari kolom `role_permissions.permission_name`: v5 sendiri menilai hak lewat id | `ksp-erp/Modules/V5/Http/Middleware/PermissionVersion5.php:40` → `Modules/V4/Entities/Models/Permission.php:21-28` |
| BR-4 | Tidak ada grant/revoke pada role developer, juga tidak sementara berjurnal (`q27_grant`/`q28_grant` dan grant-od e2e kini hanya ke role preset). Yang boleh diubah sementara: `user_roles`, lokasi, bahasa milik QA_USER/QA_USER2, berjurnal dan dipulihkan persis (pola sekarang) | Task ED-1149; `ED-1028/qa/qa_hist.php:445-466`; `ED-1027/qa/e2e/fixture.php:12-18` |
| BR-5 | Asersi jumlah/total di tabel bersama (`archives`, `role_permissions`, ...) dibatasi ke baris QA (prefiks nama/`created_by` QA) atau dihitung sebagai delta terhadap snapshot awal; tidak ada perbandingan dengan total absolut yang memuat data developer | Task ED-1149; ED-1027 X-10 (`scenario9-permission-opname-document.php:249`) |
| BR-6 | Kekuatan asersi tidak turun: setiap 403/200/filter yang dulu dibuktikan tetap dibuktikan (kode, `parameter`, isi baris), hanya sumber prasyaratnya yang pindah | Task ED-1149 |
| BR-7 | Yang menulis ulang hanya berkas di `features/<item>/qa/` dan `qa/e2e/` (K-1 b); laporan `qa-be.md`/`qa-fe.md` item lain tidak diedit, hasil dicatat di laporan ED-1149 | CLAUDE.md (folder per Story); ED-1025 `qa-fe.md:55` (pola "usang dicatat") |
| BR-8 | Kegagalan yang ternyata defect kode tidak diperbaiki dan tidak disamarkan: dicatat sebagai temuan dengan `file:line`. Karakterisasi bug pra-ada yang ada (ED-1070, ED-1124, ED-1125, X-13 ED-1027) tetap seperti adanya | ED-1071 `qa-be.md` § Yang tidak bisa diverifikasi butir 3 |

## 3. Model data

Tidak ada tabel/kolom, Updater, display setting, permission seed, atau kode pesan baru. Data uji: lihat § 10.

## 4. Endpoint v5

Tidak ada endpoint baru atau berubah. Endpoint yang diuji ulang sudah ada (milik ED-1025..1029, ED-1069, ED-1071).

## 5. Perubahan FE per module

Tidak ada (kode FE tidak disentuh). Yang berubah hanya alat e2e item lain (K-1 b) (`qa/e2e/*.spec.js`, `fixture.php`).

## 6. Acceptance criteria

Semua dijalankan di `api_sidomaju` dengan baseline developer **apa adanya** (role 5 ber-1139, role 3 ber-1140 NULL, 343 baris `sam`).

| AC | Given / When / Then | Tag | BR | Cara cek |
|---|---|---|---|---|
| AC-1 | ED-1025 AC-25 (`qa/scenario9-rev-seed-fuzz.php:168,172`): (K-4 b) grant role 1/2/3 atas 1139/1140 + `permission_name` = seed diasersi di **DB scratch** hasil seed utuh; DB bersama hanya baris `permissions` 1139/1140 per id (tanpa asersi `role_permissions` developer) → PASS; asersi seed lain (`:155-166`) tetap | [BE] | 3, 6 | http/DB: `run.php ED-1025 --only=AC-25` |
| AC-2 | ED-1026 AC-3 (`scenario2-folders-store.php:451`): 5 rute opname (`folders`, `scan`, `preview`, `preview/documents`, `POST opnames`) → 403 `GE0114`, `parameter` = `Opname Document`, DB tidak berubah, untuk preset `QA26-ROLE-NONE` (tanpa permission Archive), `QA26-ROLE-LIST` (List Archive saja) dan QA_USER2 di preset tanpa Opname Document (bukan role 5/6/29, `:481`, `:505`); tanpa token tetap 401 | [BE] | 1, 2, 6 | http: `run.php ED-1026 --only=AC-3` |
| AC-3 | ED-1027 X-8 (`scenario8-user2.php:75`, prasyarat `:91`): QA_USER2 di preset `QA27-ROLE-U2` (akses website + List Archive, tanpa OD) → `verifications`, `verifications/{F}` 403 `GE0114`, list 200 tanpa `document_verified_summary`; dengan OD diberikan ke **preset** → angka = oracle user2 (lokasi SMR), detail 403 folder terlarang, sesi terlihat sesuai K-4 ED-1027 | [BE] | 1, 2, 4, 6 | http: `run.php ED-1027 --only=X-8` |
| AC-4 | ED-1027 X-9 (`:183`): sama dengan AC-3 untuk role "asli" = preset; langkah "tanpa List Archive" memakai preset `QA27-ROLE-NOLIST` (akses website, tanpa List Archive) ganti role 22 (`:206`) → 403 `GE0114`, `parameter` List Archive/Opname Document; `user_roles` QA_USER2 dipulihkan persis (jurnal) | [BE] | 1, 2, 4 | http + DB hash `user_roles` |
| AC-5 | ED-1027 X-10 (`scenario9-permission-opname-document.php:188`, gagal `:249`): pemegang OD, `search {"isVerified":1}` dibatasi ke pohon/prefiks QA → hasil = **tepat** baris QA terverifikasi (id sama dengan oracle DB), `{"isVerified":0}` = tepat baris QA belum terverifikasi; tidak dibandingkan dengan total root; tanpa OD key verifikasi tetap diabaikan (bagian `:200-244`); role 29 (`Q27_ROLE_LIST_ONLY`) diganti preset. 343 baris `sam` tidak disentuh (K-2 a) | [BE] | 1, 5, 6 | http + DB oracle |
| AC-6 | ED-1028 AC-12 (`scenario5-access.php:27`, prasyarat `:36-38`): user2 di preset + OD diberikan ke preset (baris `created_by` QA28 = jumlah role preset) → `opnames/{id}` dan `/documents` sesi terlihat 200; 404 `ARCHIVE412` untuk tak terlihat/tak ada/bekas draft | [BE] | 1, 4, 6 | http: `run.php ED-1028 --only=AC-12` |
| AC-7 | ED-1028 AC-14 (`:172`, prasyarat `:189`, role 22 `:245-248`): R0 = preset tanpa OD → `opnames`, `opnames/{S}`, `/documents` 403 `GE0114` `parameter` Opname Document tanpa data; preset tanpa List Archive/OD ganti role 22 | [BE] | 1, 2, 6 | http |
| AC-8 | ED-1028 AC-28 (`scenario9-display-setting.php:22`, prasyarat `:35`): R0 = preset → GET/PUT display setting `documentArchiveOpnameHistory` sesuai aturan lama; baris setting dan `updater_logs` dipulihkan (hash sama) | [BE] | 1, 4, 6 | http + DB hash |
| AC-9 | e2e `[user2]` tanpa OD: ED-1027 `ED-1027-verifikasi.spec.js:1570` (AC-25) dan ED-1028 `ED-1028-opname-history.spec.js:1606` (AC-27 R0) memakai QA_USER2 di preset via `fixture.php`; `grant-od` (`ED-1027 fixture.php:300-342`, `ED-1028 :313-342`) hanya ke preset → lulus; layar Archive terbuka tanpa error | [FE] | 1, 4 | e2e `--profile default,user2 --workers 1` |
| AC-10 | Tidak tersisa ketergantungan role developer: konstanta `Q26/Q27/Q28_ROLE_LIST_ONLY` (29), `_ROLE_NONE` (6) (`qa_lib.php` ED-1026:29-30, ED-1027:27-28, ED-1028:33-34), `q27/q28_u2_roles([22])`, dan mode `norole` e2e ED-1026 (`fixture.php:22,144,398`, mencabut OD dari role QA_USER lalu memulihkan) diganti preset; grep di `features/ED-102[4-9]*/qa` tidak menemukan grant/revoke atau prasyarat permission pada role developer | [BE+FE] | 1, 4 | grep + run suite pemilik |
| AC-11 | Regresi penuh BE: `run.php` ED-1024, 1025, 1026, 1027, 1028, 1029, 1069, 1071 (satu suite per giliran) → **0 FAIL berasal dari baseline**; perkiraan: semua PASS kecuali SKIP isolasi satu-DB (ED-1027 X-3, ED-1029 X-ISO); FAIL lain = defect, dicatat (BR-8); 0 respons 5xx baru | [BE] | 6, 8 | http, log `work/qa-runs/` |
| AC-12 | Regresi penuh e2e 8 item (`scripts/e2e/run.sh <KEY> --workers 1`, profil sesuai header spec masing-masing) → 0 gagal berasal dari baseline; flaky diulang sendirian dulu (lessons QA) | [FE] | 6, 8 | e2e + galeri |
| AC-13 | Data developer utuh: snapshot sebelum vs sesudah seluruh run (jumlah + `CHECKSUM TABLE`/hash) **sama** untuk `roles`, `role_permissions` (seluruh + hash role 3 dan 5), `user_roles`, `permissions`, `archives` (+ hash kolom verifikasi baris non-QA, termasuk 343 `sam`), `archive_documents`, `archive_locations`, `archive_permissions`, `archive_opname*`, `column_display_settings`, `updater_logs`, `employees`, `employee_locations`; beda yang boleh: `users.updated_at` QA_USER/QA_USER2 (login). Sisa `QA2x-ROLE-%`/baris QA = 0 | [BE+FE] | 4, 5 | DB (pola ED-1071 `qa-be.md:77`) |

## 7. Subtask

Satu subtask (preseden ED-1102 "QA, folder ini saja"); tidak ada subtask BE/FE karena tidak ada kode aplikasi.

| Judul | Layer | AC | Module |
|---|---|---|---|
| [QA] Skenario regresi Archive tahan data baseline developer (ED-1025..ED-1028 + regresi penuh) | QA | AC-1..AC-13 | `features/ED-1024..1029, ED-1069, ED-1071/qa/` (ditulis ulang: ED-1025..1028 `qa/` + `qa/e2e/` ED-1026..1028; lainnya hanya dijalankan) |

## 8. Keputusan untuk developer

Dijawab di Gate 1 (2026-10-13/14). Tidak ada pertanyaan terbuka.

| K | Pertanyaan | Jawaban developer | Akibat di spec |
|---|---|---|---|
| K-1 | Ikut perbaiki & jalankan e2e? (`[user2]` ED-1027 `:1570`, ED-1028 `:1606` mengandaikan role 5 tanpa OD) | **b** - perbaiki e2e `[user2]` + regresi e2e penuh 8 item | AC-9, AC-12 wajib |
| K-2 | 343 `archives` terverifikasi `sam` (X-10: 350 vs 7) | **a** - asersi dibatasi ke baris QA sendiri; baris `sam` tidak direset/di-SKIP | AC-5 |
| K-3 | Ganti juga ketergantungan role developer yang belum gagal (role 6/22/29, mode `norole` e2e ED-1026)? | **b** - semua diganti role preset | AC-10, BR-1, BR-4 |
| K-4 | ED-1025 AC-25: di mana hak role 1/2/3 diasersi? | **b** - grant + `permission_name` di DB scratch dengan seed utuh; DB bersama hanya baris `permissions` | AC-1 |
| K-5 | Berkas jurnal sisa `ED-1027/qa/.restore-journal-q27-user2.json` | **gugur** - berkas sudah tidak ada (dicek 2026-10-14) | tidak ada tindakan |

Catatan: ED-1025, ED-1029, ED-1071 sudah Done (2026-10-14); folder `qa/` mereka tetap disunting/dijalankan oleh task ini (BR-7).

## 9. Di luar cakupan

- Kode BE/FE, seed, Updater: tidak diubah. Bug pra-ada yang terlihat (ED-1070 `search`/`pagination` 500, ED-1069 X-4(a), ED-1027 X-13, ED-1124, ED-1125) tetap karakterisasi.
- Memulihkan baseline developer (mengembalikan role 3/5 atau 343 baris `sam`): bukan tugas ini (R1 a).
- `role_permissions.permission_name` NULL hasil UI role: v5 aman (BR-3), tetapi v3 `app/Http/Middleware/MultiPermission.php:24` membaca kolom itu; tidak diuji di sini, dicatat sebagai catatan untuk developer.
- Isolasi antar-tenant (hanya satu DB QA) dan tukar lisensi sungguhan: tetap MANUAL Gate 2 epic.
- Laporan `qa-be.md`/`qa-fe.md` item lain tidak ditulis ulang (BR-7).

## 10. Data uji

| Hal | Isi |
|---|---|
| Dibuat & dibuang tiap run | role `QA25/26/27/28-ROLE-%` + `role_permissions`/`user_roles`-nya; folder/dokumen/sesi berprefiks item (`QA26-`, `QA27-`, `QA28-`, `0QA2x-`); grant OD hanya ke preset |
| Diubah sementara, dipulihkan persis (jurnal, `finally` + pemulihan awal run) | `user_roles` QA_USER dan QA_USER2; lokasi/bahasa/`is_all_location` user QA |
| Tidak pernah diubah (juga tidak sementara) | role developer 1-29 dan `role_permissions`-nya (termasuk 3, 5, 6, 22, 29), `permissions`, `archives` non-QA (343 `sam`), tabel opname developer |
| Dipurge di luar baris sendiri | **tidak ada** |
| Asumsi lingkungan | `QA_DBS` = `api_sidomaju` saja; QA_USER2 bisa login; tabel opname boleh kosong atau berisi data developer (`q27_baseline`/`q28_baseline` sudah toleran); `PROFILES` lisensi tidak ditukar; e2e `--workers 1` |
