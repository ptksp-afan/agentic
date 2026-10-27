---
epic: ED-1022
mode: epic-check
round: 3
items: [ED-1025, ED-1029, ED-1071]
---
# Gate 1 ronde 3 ED-1022 - cek lintas item

Ringkas: 6 kepemilikan, 6 konflik/koreksi, `GE0748` bebas di semua branch, depends_on benar, urutan = rank (1025 → 1029 → 1071);
13 pertanyaan (1 tinggi). Spec tidak diubah. Jawaban r2 yang berlaku: `ED-1022-gate1-r2.md` §9b + § Data uji.

## 1. Kepemilikan
| # | Hal | Pemilik → aturan |
|---|---|---|
| O-1 | `archive.function.js` (+test): `getFolderPermissionAllState`/`onChangeAllFolderPermission` (`:157-176`) | 1025. Rev3 tidak mengubah tanda tangan/hasil keduanya; perbaikan FB2-1 lewat fungsi murni baru (entri `setFields` + `touched`). 1029 memakainya apa adanya; helper barunya (`changeUserPermissionFlag`, `changeAllUserPermission`, ubah `canChangeUserPermission :1132`, `toUserPermissionRequest :1244`) milik 1029 |
| O-2 | Perisai bersama (`ArchiveRestrictedIcon`, `archive-components/index.js`) | 1025 (list + 2 picker). 1029 tidak memakainya (saklar menunjukkan status); bila kelak dipakai = impor, bukan salinan. Tanpa tumpang tindih |
| O-3 | `ArchiveService::update` (`:415-424`: flag + `updated_by` + detail dalam satu `update()`) | 1025. Ekstraksi oleh 1029 → EPIC K-5. Apa pun jawabannya: hak otomatis K-9 a **hanya** di `UserPermissionService::update`, tidak di helper bersama/`PUT archives/{id}` (kalau di sana, baris user login muncul lagi walau dibuang sebelum Simpan atau "Ya" pada modal BR-21 → melanggar 1025 BR-19/BR-21) |
| O-4 | `UserPermission/UpdateRequest`, `UserPermissionService` (`findUser`, `assertCanManage`), `ArchivePermissionService::assertFolders` | logika = 1029 (rev3: `folders`, id gabungan di `authorize()`); 1071 hanya guard di depan, mencakup lookup baru 1029 (nomor baris 1071 #4 = BE 581569981, bergeser). `lockFolders` (`:302`) tak tercapai dengan S_BAD (422 dulu; 1071 AC-3/4) |
| O-5 | FE `docs/specs/Archive/` (nomor task, `_module.md`) | urut jalan |
| O-6 | Karakterisasi latin1: 1025 X-12 (`qa/scenario5-input-types.php:358-416`), 1029 X-1 (`qa/scenario4-robust.php:12-75`), 1069 `scenario4-select-related.php:106-110` (catatan) | dibalik QA 1071 (AC-9; aturan r2 §5(2)). QA 1029 rev3: probe non-latin1 di `folders` masuk X-1 (harapan 500), fuzz `folders` tanpa non-latin1 - kalau tidak, QA 1029 FAIL karena bug milik 1071 |

## 2. Konflik dan koreksi
| # | Temuan | Penyelesaian |
|---|---|---|
| C-1 | Hak otomatis: 1025 BR-19 (FE, terlihat, bisa diubah; pemicu = event toggle Off→On di form) vs 1029 BR-23/K-9 a (target = diri sendiri: FE; target lain: BE, tidak terlihat; pemicu = **tersimpan** Off → On). Tujuan sama (FB-5); superadmin & pembuat sama | konsisten bila 1029 BR-23 menulis: FE self-grant hanya bila folder tersimpan Off (sama pemicu BE). Baris K-9 a tidak bisa dibuang sebelum Simpan = sifat opsi a (sudah di situasi K-9). K-9 c → kalimat BR-9 "memberi Anda…" dihapus |
| C-2 | 1025 K-17 opsi b: "bila ED-1071 lebih dulu, X-12 gagal" | keliru: X-12 sudah FAIL di HEAD sejak 1069 B-3 (rename 200 `?` → 500; `ED-1069/qa-be.md:16,56,72`). Di b/c FAIL itu pasti, dicatat milik 1071. Rek a tetap |
| C-3 | 1029 AC-37 "QA BE ED-1025 diulang" (bila ekstraksi) | jalankan `--only` AC-5, AC-38 (X-12 FAIL diketahui); gugur bila EPIC K-5 a |
| C-4 | 1029 frontmatter + §7 "ED-1025 rev3 tidak ditunggu" vs runner (`v5-epic` SKILL:76-77: depends_on blocked/belum Done/review → Blocked) | tetap menunggu (berkas FE bersama, O-1); komentar dihapus |
| C-5 | 1025 §8 merujuk `ED-1022-gate1.md` §9b | kini berkas ronde 3 → `ED-1022-gate1-r2.md` §9b |
| C-6 | Ronde 2 mencatat `GE0747` bebas (hanya `app/Lib/lang`) | branch rewrite memakai `lang/{en,id}/message.php` → §3; 1071 K-4 benar |

Tanpa konflik: 1071 #3/AC-3 memuat `folders.*.id_archive` (`in:0,1`, `distinct` tanpa kueri); urutan 1029 BR-13 = 1071 AC-4/AC-6
(`{id}` S_BAD 404 `ARCHIVE440`; `folders` [L, S_BAD] = 407).

## 3. Kode pesan (`app/Lib/lang/en_EN.php`/`id_ID.php` HEAD + 208 ref, termasuk `lang/*/message.php`)
| Kode | Status | Pemilik |
|---|---|---|
| `GE0747` | bebas di HEAD; = "lokasi di luar radius" di 7 branch rewrite (origin/v5-pgsql*, v5-new-purchase, v5-new-posgresql) | jangan dipakai |
| `GE0748`, `GE0749`, `ARCHIVE420` | bebas di semua ref; `git log --all -S GE0748` kosong; FE 0 rujukan | 1071 K-4 a = `GE0748` |
| catatan | `GE0748` juga nomor berikutnya di branch rewrite (tertinggi `GE0747`, sudah beda arti di `GE0746`) → port tetap perlu pemetaan | - |
| `ARCHIVE419` (1069), `440`/`441` (1029) | dipakai; 1025/1029 rev3 tanpa kode baru | - |

## 4. depends_on dan urutan jalan
Benar: 1025 `[ED-1024]`, 1029 `[ED-1024, ED-1025]`, 1071 `[ED-1025, ED-1029, ED-1069]` (1069, 1024 Done; 1070 ditunda, bukan
dependensi). Urutan = rank, tanpa pindah: **ED-1025 → ED-1029 → ED-1071**. 1029 memakai helper 1025 (berkas sama); 1071 menjaga
`folders` 1029 dan membalik X-12/X-1 sesudah QA 1025/1029. 1025 Blocked → 1029, 1071 Blocked berantai (aturan runner).

## 5. Pertanyaan lintas item
**EPIC K-5. Flag Izin Folder dari halaman by User: tulis sendiri atau ekstrak dari `ArchiveService::update`?** - risiko: rendah
Situasi: PUT `user-permissions/{B}` `folders` [F: 1] harus mengubah `is_folder_permission` + `updated_by` F "sama dengan `PUT archives/{id}`".
Kode itu kini di `ArchiveService.php:415-424`, bersatu dengan simpan detail. Spec 1029 §3: "boleh diekstrak".
| Opsi | Akibat |
|---|---|
| a | 1029 menulis flag di `UserPermissionService` (±5 baris serupa); berkas 1025 tidak disentuh, QA BE 1025 tidak diulang |
| b | ekstrak ke `ArchivePermissionService`, dipakai keduanya: satu tempat; `update()` 1025 dipecah, ulang AC-5/AC-38 1025 (C-3); hak otomatis tetap di luar helper (O-3) |
Rekomendasi: **a** - kodenya kecil, perilaku 1025 sudah lulus QA, tanpa risiko regresi silang.

## 6. Data uji & lingkungan (`api_sidomaju`)
| Item | Di luar baris sendiri (sementara, dipulihkan) | Dibandingkan di teardown |
|---|---|---|
| 1025 (K-17 a: unit + e2e) | role + bahasa QA_USER (A) & QA_USER2 (B) ditukar; role `QA25-ROLE-*`; folder `0QA25-` | COUNT+CHECKSUM `archives`, `archive_documents`, `archive_locations`, `archive_permissions`; `roles`, `role_permissions`, `user_roles` = baseline. K-17 b/c: + suite BE (`QA02-`, `q5_drop_created` = **semua** folder buatan QA_USER sejak skenario mulai), e2e 1024 (arsip `billing` nyata diubah lalu dipulihkan) |
| 1029 (BE + e2e) | QA_USER: role (sementara 1/2/6/29), lokasi employee (MGL saja), bahasa; QA_USER2: role 5 → 18 / `QA29F-ROLE-D/M`; user nyata B/B2 dapat baris hanya di folder QA; baris otomatis K-9 a QA_USER/superadmin hanya di folder QA | total `archive_permissions`; CHECKSUM 6 tabel (4 `archive*`, `roles`, `role_permissions`) + baris A/E di `user_roles`/`employees`/`employee_locations` |
| 1071 (BE + regresi AC-9 + e2e) | `QA71-%`, X-12/X-1 (`QA02-`, `QA29-`, `q5_drop_created`). Regresi 1027/1028: baris **bersama** `column_display_settings` `documentArchive`/`…OpnameHistory` dihapus lalu dipulihkan, Updater ED-1033 diulang + `updater_logs` dipulihkan, `Opname Document` sementara ke role nyata QA_USER2; opname: hanya sesi QA tercatat + `QA28BULK%` | per suite |

Asumsi: lisensi Salesman Activity; role 3 QA_USER memegang `Folder Permissions` 1140 + `Opname Document` 1139 (seed ulang r2); QA_USER2
tidak login BE (`ED-1029/qa/qa_lib.php:9`), e2e lewat tukar role ber-`Allow Access Website`; e2e 1 worker (d65db69); tanpa purge tabel.

**DU-1. Jendela run tanpa aktivitas developer?** Situasi: teardown 1025/1029 membandingkan COUNT+CHECKSUM; satu aksi Archive (izin, pindah,
Confirm opname = tulis `archives.is_verified`), ubah role/karyawan, atau login QA_USER/QA_USER2 selama run = "beda baseline" → FAIL palsu. a) developer tidak memakai Archive/opname/role/karyawan/QA_USER* sampai run selesai; bila tetap ada beda
di luar prefix QA: catat, buktikan baris QA bersih, lanjut. b) hanya cadangan itu (developer tetap bekerja): bukti pemulihan melemah.
c) beda = FAIL, item Blocked: run bisa berhenti. Rek **a**. Risiko rendah.

**DU-2. Data opname developer (dibuat ulang lewat UI, insiden r2 K-1 a): kapan?** Confirm opname mengubah CHECKSUM `archives`. a) sebelum
run dimulai: masuk baseline; sweep QA hanya sesi QA tercatat (`ED-1026/qa/qa_lib.php:469-498`). b) sesudah run selesai. c) selama run → DU-1.
Rek **a** (bila belum, b). Risiko rendah.

**DU-3. Regresi 1071 menyentuh baris bersama (display setting, `updater_logs`, grant sementara)?** a) ya, dipulihkan persis (pola r2,
P3). b) tanpa skenario itu: bukti regresi 1027/1028 berkurang. Rek **a**; developer tidak mengubah tampilan kolom Archive selama run.
Risiko rendah.

## 7. Keputusan Gate 1 (gabungan)
| # | Asal | Isi | Rek. | Risiko |
|---|---|---|---|---|
| 1 | 1029 K-9 | hak otomatis saat menyalakan Izin untuk user terpilih lain: a BE menulis baris user login di PUT yang sama / b PUT kedua dari FE / c tanpa | a | tinggi |
| 2 | 1029 K-10 | matikan saklar membuang perubahan sel folder itu (441 untuk hak di folder Off) / b ikut tersimpan seperti tab Izin | a | rendah |
| 3 | 1025 K-17 | luas QA rev3: a unit + seluruh e2e 1025 / b + suite BE / c + e2e 1024, 1029 (C-2) | a | rendah |
| 4 | EPIC K-5 | flag di `UserPermissionService` vs ekstraksi | a | rendah |
| 5-10 | 1071 K-2..K-7 | bentuk tolak per arti (422/404/kosong); cakupan Archive + select (tiket lanjutan); kode `GE0748`; scan opname tetap; BE saja; `e`+U+0301 ditolak | a ×6 | rendah |
| 11-13 | DU-1..DU-3 | jendela run; data opname developer; baris bersama regresi 1071 | a, a, a | rendah |

Bukan pertanyaan: 1071 K-1 a (P1); FB2-1/2/3/5, P6 a, P7 a; r2 §9b. Terbuka di luar item: P8 password literal `config/database.php`
BE (tidak masuk commit item mana pun).

## 8. Perubahan spec sesudah Gate 1
1025: §8 rujukan (C-5); K-17 opsi b (C-2); §5 tanda tangan helper tetap (O-1) · 1029: hapus "tidak ditunggu" (C-4); BR-23 pemicu
tersimpan Off (C-1); §3/AC-37 menurut EPIC K-5 (a: kalimat ekstraksi + ulang QA 1025 dihapus); hak otomatis hanya di `update` (O-3);
QA non-latin1 `folders` → X-1 (O-6); K-9 c → BR-9 · 1071: #4 "termasuk lookup id gabungan 1029 rev3, baris sesudah 1029" (O-4); AC-9 X-1
termasuk `folders`; kode menurut K-4.

## 9. Jawaban developer (2026-10-12)
| # | Asal | Jawaban |
|---|---|---|
| 1 | 1029 K-9 | **a** + syarat: hak otomatis tidak boleh menulis baris ganda untuk user yang sama - bila user (pengaktif) sudah punya/menambahkan baris untuk dirinya sendiri, baris itu dipakai/dinaikkan, bukan baris kedua. Berlaku di tab Izin (ED-1025, FE auto-add K-14 a) **dan** di halaman by User (ED-1029 BE) |
| 2 | 1029 K-10 | **b** - seperti tab Izin: perubahan sel di folder yang saklarnya dimatikan tetap ikut tersimpan; BE menerima hak untuk folder yang dimatikan di request yang sama |
| 3 | 1025 K-17 | a |
| 4 | EPIC K-5 | **b** - penulisan flag Izin diekstrak ke satu method bersama (`ArchivePermissionService`), dipakai `ArchiveService::update` dan `UserPermissionService::update`; hak otomatis K-9 tetap hanya di `UserPermissionService::update` (O-3); ED-1029 mengulang QA BE ED-1025 `--only` AC-5, AC-38 (C-3; X-12 FAIL diketahui milik 1071) |
| 5-10 | 1071 K-2..K-7 | a, a, a (`GE0748`), a, a, a |
| 11 | DU-1 | a - developer tidak memakai api_sidomaju (Archive/opname/role/karyawan/QA_USER*) sampai run selesai |
| 12 | DU-2 | data opname developer sudah dibuat lalu di-**truncate** sebelum Gate 1: tabel opname kosong di baseline; tidak ada data opname developer selama run |
| 13 | DU-3 | a |
Item: ED-1025, ED-1029, ED-1071 disetujui (developer: Gate 1 lalu long run tanpa menunggu, review ronde 2 jawaban 2 a). §8 diterapkan.
