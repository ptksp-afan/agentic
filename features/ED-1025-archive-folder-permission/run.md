---
key: ED-1025
epic: ED-1022
title: Archive - Folder Permission per folder (tab Permission & akses ditolak)
slug: archive-folder-permission
phase: done
qa_round: 1   # revisi: ronde dihitung ulang dari 1
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents:
  analyst: {id: abdb886b9da10fc6a, at: 2026-10-06 16:20}
  qa_be: {id: a98b5d7f91511bb97, at: 2026-10-08 17:28}
  be_dev: {id: af75bddd604985321, at: 2026-10-08 15:57}
  fe_dev: {id: aa9d0be7fd76a0d9b, at: 2026-10-12 (rev3)}
  qa_fe: {id: a64bc7ff2a53a2f4e, at: 2026-10-12 (rev3)}
---
## Status ringkas
**Selesai (Done) - disetujui developer di review batch ED-1022 ronde 3 (2026-10-14).** Jira item + subtask Done, label ai-review dilepas, komentar serah terima ditambahkan.

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
| ED-1131 | [FE] (rev3) Archive - tab Izin: kolom Semua tercatat sebagai perubahan, satu baris per user saat Izin dinyalakan | FE | In Progress |
| ED-1132 | [FE] (rev3) Archive - ikon perisai Akses Terbatas di picker Pindahkan dan pindah massal | FE | In Progress |
| ED-1133 | [QA] (rev3) Skenario revisi ronde 3: Semua, baris tunggal, perisai picker, oracle "+" e2e | QA | In Progress |

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

- Rev3 FE: `willLoseOwnFolderAccess` (BR-21) ikut memakai `isSameFolderPermissionUser` (di luar §5, agar baris sendiri beda huruf/spasi akhir dikenali); test tooltip list pra-ada flaky distabilkan (hanya test)
- Rev3 FE review: Catatan saja - `_module.md:107` tidak menyebut fungsi baru; prettier 3 berkas test gagal hanya di baris lama (HEAD)
- QA FE rev3 F-1: di api_sidomaju role 3 & role 5 (QA_USER2) kini memegang `Folder Permissions` (role 5 juga seluruh permission Archive termasuk `Opname Document`), baris `permission_name` NULL - bukan dari QA, tidak diubah; fixture e2e membaca nama lewat id_permission. Skenario usang dicatat: BE `qa/scenario9-rev-seed-fuzz.php:168,172` (data), e2e ED-1029 `:364/:370`, `:499`, `:654`, profil `[user2]` e2e ED-1027/1028/1029; picker search tidak bisa dipakai (ED-1125, pra-ada)

## Usulan pipeline
- (rev3) FE dev: `testing.md` "Jebakan" - sebelum hover/tooltip baris `VirtualTable`, tunggu baris terakhir dirender + timeout `findByRole('tooltip')`; satu commit prettier untuk test Archive yang tidak rapi di HEAD
- (rev3) QA: unit Archive paralel pada load mesin ~9 timeout 8 test; v5-qa/testing: jalankan `--runInBand` sebagai angka resmi bila mesin sibuk
- (insiden rev) e2e `fixture.php` ED-1026/1027/1028 `purge()` menghapus seluruh tabel opname + reset kolom verifikasi tanpa cek milik fixture, dan harness `scripts/e2e/lib/fixtures.js` menjalankan `down` walau `up` gagal di prasyarat -> `down` hanya baris berprefiks/jurnal, tolak `down` tanpa jurnal, harness tanpa teardown bila `up` gagal; v5-qa: jangan jalankan e2e item lain bila QA sebelumnya mencatat prasyaratnya gagal karena data developer
- (rev) QA BE/FE: prasyarat "tabel kosong" di suite ED-1026/1027/1028 tidak cocok untuk DB uji bersama; perlu DB uji khusus QA atau prasyarat berbasis fixture
- QA BE `scope: be` memperluas fuzzing tipe/karakter tiap ronde (r2: 435 kasus baru) sehingga 500 pra-ada lintas v5 (CustomizeBuilder, kolasi latin1) muncul sebagai FAIL item; usul: aturan baku di v5-qa - 500 pra-ada di luar baris diff = "Dilacak di luar item" + usulan tiket, tidak menentukan verdict; ronde 2+ dibatasi ke kegagalan + regresi
- FE dev: orkestrator/BA menjalankan `/ui-discuss --auto` sebelum Gate 1 juga untuk module lama tanpa `_module.md` (kalau tidak, `/ui-plan` berhenti)
- FE dev: baseline full suite FE (4 suite gagal pra-ada: Production.function, InterbankTransferForm, components/Item/BatchOut, CustomerPurchaseOrderView) disimpan di knowledge/ agar QA tidak menghitungnya defect; `/ui-slice` boleh memakai endpoint GET yang sudah tersambung di module lama
- QA FE F-1: butir MANUAL AC-23 (tukar lisensi) tidak punya rumah di `## Daftar tes UI` (sama dengan usulan ED-1024 QA FE F-2): tempat baku di daftar Gate 2 epic

## Log
- 2026-10-06..07 run awal (diringkas): baseline BE 42f3e07cb/FE 6afd19054; BA 17 BR/24 AC/8 subtask; Gate 1 gabungan; BE+QA BE r1-r3 PASS (found ED-1070, ED-1071); FE task 001/002; QA FE r1 PASS; found ED-1072; commit BE 9fec7c427, FE 89b3b98a6, agentic 7c11743
- 2026-10-08 revisi ronde 2 (diringkas; 17 baris): review batch Minta perbaikan -> BA rev (22 BR/38 AC, subtask ED-1083..1087, K-12..K-16) -> Gate 1 r2 -> rebaseline 44bea5136/7fa63869d -> BE (ArchivePermissionService, seed 1139/1140) + review R-4..R-6 -> QA BE r1 PASS 25/0/1 (suite ED-1025 40/40) -> digest -> FE task 013/014 + review (prettier) -> QA FE r1 PASS 13/0/1 (e2e 17/17) -> INSIDEN QA FE D-1 (teardown e2e ED-1026 menghapus tabel opname developer) -> commit BE 8ea8ebcf2, FE e5355a9c6, agentic 6b494b5; label ai-review; RESULT env-fail
- 2026-10-12 ba (rev3): spec.md diperbarui untuk review batch ronde 2 (FB2-1 akar `setFieldValue` tanpa touched -> `useUpdate` "No changes to save"; FB2-5 picker = `ArchiveTable`, `is_folder_permission` sudah dari GET archives, BE tidak diubah; P6 oracle `expectedPlus` :124) - 24 BR / 41 AC (BR-23/24, AC-39..41 baru), 3 subtask rev3 (2 FE, 1 QA), K-17 (lingkup QA, rendah); contract tetap; X-12 ada di scenario5-input-types.php:358 (milik ED-1071); spec 30 KB, status draft
- 2026-10-12 gate1 (rev3): jawaban `epics/ED-1022-gate1.md` §9 diterapkan - K-17 a (opsi b dikoreksi C-2); syarat ED-1029 K-9 a -> BR-25/AC-42 (FE: penjaga "+ Add User" + pencocokan user tanpa beda huruf/spasi; BE sudah menolak ganda: `distinct` + `saveUserRow`/UNIQUE); EPIC K-5 b dicatat (§9); koreksi §8 rujukan gate1-r2 (C-5), helper All tetap + fungsi murni baru (O-1). 25 BR / 42 AC / 3 subtask rev3 (2 FE, 1 QA); spec approved; phase: fe
- 2026-10-12 gate1 ronde 3 (epic loop): Jira subtask rev3 ED-1131 [FE], ED-1132 [FE], ED-1133 [QA] (sprint 46) + komentar Gate 1 di ED-1025
- 2026-10-12 long-run rev3: preflight OK; rebaseline BE 581569981 / FE 41480e37c (0 kotor); ED-1131, ED-1132, ED-1133 -> In Progress; digest API dilewati (contract tetap, BE tetap); phase fe
- 2026-10-12 fe (rev3): FE dev (aa9d0be7fd76a0d9b) ui-plan -> task 022 (ED-1131, fix/logic) + 023 (ED-1132, enh/ui) logic-done; 6 src M/1 baru (ArchiveRestrictedIcon) + 5 test; tes Archive 402/405 (3 timeout beban mesin, lulus saat diulang sendiri), eslint 0, build staging OK; beda: `willLoseOwnFolderAccess` ikut pencocok baru, test tooltip list pra-ada flaky distabilkan; full suite tidak dijalankan (K-17 a)
- 2026-10-12 fe-review (rev3): convention-reviewer (ad2e906c7c4cd4530, sonnet, general-purpose dgn definisi agent) Harus diperbaiki 0, Perlu dikonfirmasi 0, Catatan 3 (tanpa test sendiri ArchiveFolderPermission/ArchiveTable = diuji lewat Drawer/MoveModal; `_module.md:107` tak memuat fungsi baru; prettier gagal hanya baris lama); phase qa-fe r1
- 2026-10-12 qa-fe (rev3) r1: v5-qa (a64bc7ff2a53a2f4e, sonnet) PASS - AC-39/40/41/42 PASS, 4/0/1 MANUAL; unit Archive 405/405 in-band (8 timeout beban saat paralel, lulus diulang); e2e ED-1025 21/21 (+4 flow baru, oracle :124 -> Folder Permissions), kontrol negatif HEAD: AC-39/40 gagal; data: hanya teardown fixture QA (30 baris `0QA25-`, 3 role `QA25-ROLE-`), archive* = baseline; `QA01-R2-*` sudah 0 baris; penolakan 0
- 2026-10-12 commit (rev3): FE f1fd4f69e (14 berkas, path eksplisit); BE tidak berubah; agentic: commit [DOC] ED-1025 rev3 (hash di laporan runner)
- 2026-10-14 review batch ronde 3: disetujui developer -> Jira Done (item + subtask), label ai-review dilepas, komentar serah terima; phase done
