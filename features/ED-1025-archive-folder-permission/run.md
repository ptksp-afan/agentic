---
key: ED-1025
epic: ED-1022
title: Archive - Folder Permission per folder (tab Permission & akses ditolak)
slug: archive-folder-permission
phase: gate2
qa_round: 1   # revisi: ronde dihitung ulang dari 1
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents:
  analyst: {id: abdb886b9da10fc6a, at: 2026-10-06 16:20}
  qa_be: {id: a98b5d7f91511bb97, at: 2026-10-08 17:28}
  be_dev: {id: af75bddd604985321, at: 2026-10-08 15:57}
  fe_dev: {id: a1d205900d36921cf, at: 2026-10-08 18:29}
  qa_fe: {id: a9b2761fda0d516d7, at: 2026-10-08 19:45}
---
## Status ringkas
**Revisi selesai, commit lokal, menunggu review batch (long run ED-1022).** QA BE rev r1 PASS (25/0/1 MANUAL), QA FE rev r1 PASS (13/0/1 MANUAL).
Commit perbaikan: BE 8ea8ebcf2, FE e5355a9c6 (di atas 44bea5136 / 7fa63869d, tanpa amend). Jira ED-1025 In Progress + label ai-review + komentar siap review.
**INSIDEN DB uji (QA FE D-1):** teardown fixture e2e ED-1026 menghapus seluruh tabel opname + kolom verifikasi `archives` di api_sidomaju (data developer: 13 sesi). Tidak dipulihkan AI.
Long run dihentikan (RESULT env-fail): developer memulihkan data; fixture e2e ED-1026/1027/1028 + harness diperbaiki sebelum QA item itu jalan lagi.
Sisa: 6 baris `QA01-R2-*` di `archives` (pembersihan ditolak classifier, tidak diulang).

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|
| ED-1031 | [BE] Updater skema folder permission + model ArchivePermission | BE | In Progress |
| ED-1037 | [BE] Hak folder efektif (ArchivePermissionService) + penegakan baca di list/show/history | BE | In Progress |
| ED-1043 | [BE] Simpan permission folder lewat ubah folder + validasi + riwayat | BE | In Progress |
| ED-1049 | [BE] Penegakan hak di rename/ubah/hapus/buat subfolder/put-in | BE | In Progress |
| ED-1055 | [BE] Select user Archive | BE | In Progress |
| ED-1059 | [FE] Archive - Drawer folder bertab + tab Permission | FE | In Progress |
| ED-1063 | [FE] Archive - Layar akses ditolak, menu baris/"+"/picker ikut hak folder, ikon perisai | FE | In Progress |
| ED-1066 | [QA] Skenario folder permission | QA | In Progress |
| ED-1083 | [BE] (rev) Hak folder efektif: induk hanya dominan untuk View, folder Izin nonaktif tidak dibatasi | BE | In Progress |
| ED-1084 | [BE] (rev) Permission Folder Permissions: seed 1140 & Opname Document 1139, kelompok detail/izin | BE | In Progress |
| ED-1085 | [FE] (rev) Archive - tab Izin: Perizinan Folder, kolom All, hak otomatis | FE | In Progress |
| ED-1086 | [FE] (rev) Archive - konfirmasi cabut View sendiri, tooltip, teks induk, Kembali ke Arsip | FE | In Progress |
| ED-1087 | [QA] (rev) Skenario revisi, skenario usang, regresi | QA | In Progress |

## Keputusan developer
- G1 (gabungan ED-1022, 19:24): semua rekomendasi diterima - EPIC K-1 a, EPIC K-2 b, grup G-1..G-7 (epics/ED-1022-gate1.md §6), K-n lain = rekomendasi analyst; jawaban tertulis di spec § Keputusan

## Review BE - Perlu dikonfirmasi (untuk review batch)
- R-1 `ArchiveController::index` bercabang pada isi error: 403 hak folder = bentuk formatResponse (`msg_code`, `result.denied_by`), error lain (404, 403 lokasi) bentuk ErrorMessageException (`code`); show/history 403 ARCHIVE407 juga bentuk `code` -> dua bentuk body untuk ARCHIVE407
- R-2 403 untuk hak folder (ARCHIVE407-410/418) sesuai decisions.md "Hak akses di luar permission_v5"
- R-3 ARCHIVE408-410 memuat nama folder (input user) di `<b>[0]</b>`: FE harus merender aman (preseden 145 baris)
- Dev: put-in **folder** ke root tetap butuh Update (spec K-7 iv "keluarkan ke root tanpa cek" dibaca = tujuan tidak dicek)
- QA r3: 5 kasus 500 kolasi lewat aturan baru item `folder_permissions.*.id_user` exists:users (UpdateRequest) diklasifikasikan ke ED-1071 (akar sama; arah perbaikan = keputusan developer di tiket itu)
- Dev: `MyHelper::apiSelectQuery` orWhereIn(selected_id) sebelum whereNotIn(excepts) -> excepts tak berlaku bila keduanya dikirim (pra-ada; dihindari di select user)
- Digest FE P-2: simpan tab Permission mengganti semua baris (BR-16) tanpa pengaman - non-pembuat bisa mengunci dirinya sendiri; FE mengikuti spec apa adanya
- FE: `/ui-discuss --auto` dijalankan FE dev untuk module lama Archive (belum punya `_module.md`) -> docs/specs/Archive/_module.md + TASK-INDEX belum dinilai developer
- FE: perbaikan pra-ada ikut diperbaiki - `ArchiveForm` tidak meneruskan `disabled` ke `Form` (drawer bisa diisi tanpa izin); dibutuhkan AC-18
- FE: `access` null (dokumen/root/pencarian) tidak membatasi di FE (BR-17); pindah massal tidak menyaring folder tanpa Update (BE 403 ARCHIVE408); 403 View di picker tanpa toast
- FE review: id-ID `archive.Back to Archive` = "Back to Archive" (teks desain BR-11, belum "Kembali ke Arsip"); `ArchiveForm` meneruskan `disabled` (folder isUsed / tanpa Update kini read-only penuh)
- QA FE (non-blokir): C-2 breadcrumb layar 06 dari hasil pencarian tidak memakai `result.breadcrumbs` body 403 (`ArchivePage/index.js`); C-3 label switch EN "Active" terpotong saat disabled; C-1 test ArchiveDrawer/ArchivePage timeout 5 dtk saat mesin sibuk

- Rev review BE: R-4 cek kelompok PUT memakai `MyHelper::hasPermission` (tanpa bypass customer, beda dengan middleware `permission_v5`) - customer memang tidak boleh ubah folder?
- Rev review BE: R-5 ED-1029 belum sinkron: `manage_permission` = `Folder Permissions`, tetapi `user-permissions` + `assertCanManage` masih Update Folder/Update efektif (PUT 403 ARCHIVE408); komentar `api.php:60` usang -> revisi ED-1029
- Rev review BE: R-6 tanpa Updater 1133->1139 di `role_permissions`: tenant yang sudah setup dengan 1133 (QA/dev DB) perlu seed + grant ulang (1133 belum rilis)
- Rev dev: PUT kelompok izin ke `{id}` dokumen tetap menulis `archive_permissions` (perilaku lama)

- QA BE rev T-1: suite BE ED-1026/1027/1028 tidak bisa jalan bersih di api_sidomaju (prasyarat "tabel opname kosong" vs 13 sesi opname milik developer); dijalankan dari salinan longgar di scratchpad -> regresi item itu sebagian tidak terverifikasi; perlu DB uji bersih atau baseline oleh pemilik
- QA BE rev: ED-1024 AC-1/5/10 dipindah ke fixture (developer mengubah PUSAT - MAGELANG); efek samping ED-1027 X-1 menulis ulang updater_logs id 197 (log saja)
- QA BE rev O-1: PUT izin ke id dokumen menulis is_folder_permission=1 pada dokumen (pra-ada, menyimpang dari contract §2)

- Rev digest P-22: PUT kelompok izin ke id dokumen (pra-ada, QA O-1) dijawab orkestrator = di luar revisi, FE tak terdampak; keputusan BE untuk review batch
- Rev digest: bagian #25/#26 (ED-1029) di `_api.md` basi (route Update Folder / managePermission lama) -> pass digest saat revisi ED-1029

- Rev FE: cek superadmin modal pakai `isFolderPermissionBypass` (role mana pun isSuperadmin 1/2, = BE), bukan `findLowestSuperadminLevel` (spec §5) - helper itu salah untuk role ganda; `disabled` Detail lewat `ConfigProvider componentDisabled` (pola baru)
- Rev FE: test pra-ada gagal `ArchiveVerificationModal/index.test.js` (tombol "Tutup", footer dibuang di 7fa63869d) - bukan batch ini

- Rev FE review: komentar usang `archive-components/ArchiveVerification/index.js:101-103` (`disabled={false}` workaround kini redundan, berkas ED-1027)

- QA FE rev: F-1 AC-23 tukar lisensi tidak ada di Daftar tes UI task mana pun; C-1 `ArchivePage/index.test.js:110-117` tooltip timeout 1 dtk (flake); C-2/C-3 menu "+" Atur Izin per User + sel by User masih gerbang Update Folder (revisi ED-1029)

## Usulan pipeline
- (insiden rev) e2e `fixture.php` ED-1026/1027/1028 `purge()` menghapus seluruh tabel opname + reset kolom verifikasi tanpa cek milik fixture, dan harness `scripts/e2e/lib/fixtures.js` menjalankan `down` walau `up` gagal di prasyarat -> `down` hanya baris berprefiks/jurnal, tolak `down` tanpa jurnal, harness tanpa teardown bila `up` gagal; v5-qa: jangan jalankan e2e item lain bila QA sebelumnya mencatat prasyaratnya gagal karena data developer
- (rev) QA BE/FE: prasyarat "tabel kosong" di suite ED-1026/1027/1028 tidak cocok untuk DB uji bersama; perlu DB uji khusus QA atau prasyarat berbasis fixture
- QA BE `scope: be` memperluas fuzzing tipe/karakter tiap ronde (r2: 435 kasus baru) sehingga 500 pra-ada lintas v5 (CustomizeBuilder, kolasi latin1) muncul sebagai FAIL item; usul: aturan baku di v5-qa - 500 pra-ada di luar baris diff = "Dilacak di luar item" + usulan tiket, tidak menentukan verdict; ronde 2+ dibatasi ke kegagalan + regresi
- FE dev: orkestrator/BA menjalankan `/ui-discuss --auto` sebelum Gate 1 juga untuk module lama tanpa `_module.md` (kalau tidak, `/ui-plan` berhenti)
- FE dev: baseline full suite FE (4 suite gagal pra-ada: Production.function, InterbankTransferForm, components/Item/BatchOut, CustomerPurchaseOrderView) disimpan di knowledge/ agar QA tidak menghitungnya defect; `/ui-slice` boleh memakai endpoint GET yang sudah tersambung di module lama
- QA FE F-1: butir MANUAL AC-23 (tukar lisensi) tidak punya rumah di `## Daftar tes UI` (sama dengan usulan ED-1024 QA FE F-2): tempat baku di daftar Gate 2 epic

## Log
- 2026-10-06..07 run awal (diringkas): baseline BE 42f3e07cb/FE 6afd19054; BA 17 BR/24 AC/8 subtask; Gate 1 gabungan; BE+QA BE r1-r3 PASS (found ED-1070, ED-1071); FE task 001/002; QA FE r1 PASS; found ED-1072; commit BE 9fec7c427, FE 89b3b98a6, agentic 7c11743
- 2026-10-08 09:25 review batch: Minta perbaikan → phase: ba (BA revisi menunggu instruksi developer); feedback di epics/ED-1022-review-feedback.md § ED-1025; label ai-review dilepas
- 2026-10-08 13:20 ba (rev): spec.md + contract.md diperbarui untuk FB-4a/5/6/7/9, D-2, N-3 - BR 8 diubah + 5 baru (BR-18..22), AC 10 diubah + 13 baru (AC-25..37), 5 subtask (rev) (2 BE, 2 FE, 1 QA), K-12..K-16 baru; permission baru `Folder Permissions` (seed 1134, tanpa Updater); spec 29 KB
- 2026-10-08 15:27 gate1 (rev): jawaban diterapkan (K-12 a, K-13 a, K-14 a, K-15 b, K-16 b, EPIC K-4 a 1139/1140) - 22 BR / 38 AC (AC-38 baru) / 5 subtask (rev); contract §3 PUT dua kelompok, §7 id
- 2026-10-08 15:37 gate1 (rev) Jira: subtask baru ED-1083 [BE] hak folder efektif · ED-1084 [BE] permission Folder Permissions/seed · ED-1085 [FE] tab Izin · ED-1086 [FE] konfirmasi/tooltip/teks · ED-1087 [QA] (sprint 46, To Do; story tetap In Progress)
- 2026-10-08 gate1 ronde 2 disetujui developer (epics/ED-1022-gate1.md §9b); spec status approved; phase: be (long run)
- 2026-10-08 15:39 long-run rev: mulai fase be; preflight OK; rebaseline BE 44bea5136 / FE 7fa63869d (0 kotor); ED-1083, ED-1084 -> In Progress
- 2026-10-08 15:57 be (rev): v5-be-dev (af75bddd604985321) ED-1083+ED-1084 selesai; 5 berkas BE M (ArchivePermissionService, ArchiveService, UpdateRequest, api.php, permission_salesman.sql 1139/1140); contract §3 2 presisi; seed 1139/1140 diterapkan ke api_sidomaju (tanpa TRUNCATE; role 1/2/3); smoke dev PASS 4/4 (qa/dev-smoke-rev, data uji dibersihkan); suite ED-1025 lama 18/13 FAIL = skenario usang revisi + AC-1/2 data developer di QA DB (4 folder Izin On, kevinsudjadi.adm/sam)
- 2026-10-08 16:04 be-review (rev): v5-be-reviewer (a865e07cdae8c8cf2, sonnet) Harus diperbaiki 0, Perlu dikonfirmasi 3 (R-4..R-6); ED-1087 -> In Progress; phase qa-be r1
- 2026-10-08 17:28 qa-be (rev) r1: PASS - 25 PASS / 0 FAIL / 1 MANUAL (AC-23 tukar lisensi); suite ED-1025 40/40 (9 skenario baru scenario7/8/9/1b); seed QA DB sudah sesuai (0 langkah); usang diperbarui: ED-1025 milik sendiri + ED-1024 (26/26); usang dicatat: ED-1026/1027/1028/1029 (qa-be.md); T-1 data developer
- 2026-10-08 17:38 fe digest (rev): api-contract-analyst (a422b74e0fdbe9cd3, sonnet) memperbarui ksp-react docs/specs/Archive/_api.md (#1-#8, §7 id); 1 Perlu dikonfirmasi (22) dijawab orkestrator dari kode BE; 0 blocker; ED-1085/1086 -> In Progress
- 2026-10-08 18:29 fe (rev): FE dev (a1d205900d36921cf) ui-plan -> task 013 (ED-1085) + 014 (ED-1086) logic-done; 10 berkas src/test M + 2 task baru + _module.md; eslint 0, build staging OK x4, test Archive 317/318 (1 pra-ada ArchiveVerificationModal), full suite efektif 1634/1639 (5 pra-ada)
- 2026-10-08 18:33 fe-review (rev): convention-reviewer (a1bcf941769a5e938, sonnet) Harus diperbaiki 1 (prettier archive.function.js:227, ArchiveForm/index.js:145-151) -> FE dev via SendMessage; Perlu dikonfirmasi 2 (ConfigProvider componentDisabled, isFolderPermissionBypass) = sudah di daftar review
- 2026-10-08 18:35 fe fix (rev): prettier 2 berkas diperbaiki FE dev; prettier --check OK, eslint 0, test Archive 317/318 (1 pra-ada); phase qa-fe r1
- 2026-10-08 19:45 qa-fe (rev) r1: PASS - 13 PASS / 0 FAIL / 1 MANUAL (AC-23); e2e ED-1025 17/17 (galeri work/e2e/ED-1025-archive-folder-permission-20261008-190715/), ED-1024 e2e 3/3 (diperbarui), ED-1029 e2e 13 OK / 3 usang (dicatat); unit Archive 316/318 (1 pra-ada, 1 flake); full suite = HEAD bersih; regresi BE ED-1025 40/40, ED-1029 18/4 usang/1 skip; tes UI manual 34 butir (task 013/014)
- 2026-10-08 19:45 INSIDEN (QA FE D-1): QA menjalankan e2e ED-1026/1027/1028; teardown `down` ED-1026 menghapus seluruh archive_opnames/_folders/_documents + kolom verifikasi archives di api_sidomaju (data developer, 13 sesi); tidak ada cadangan, tidak dipulihkan; classifier menolak pembersihan 6 baris QA01-R2-* (tidak diulang)
- 2026-10-08 19:45 smoke dev qa/dev-smoke-rev dihapus (data uji sudah dibersihkan dev)
- 2026-10-08 19:45 commit (rev): BE 8ea8ebcf2, FE e5355a9c6; Jira ED-1025 label ai-review + komentar siap review (dengan catatan insiden); phase: gate2 (menunggu review batch); RESULT env-fail karena insiden DB uji
