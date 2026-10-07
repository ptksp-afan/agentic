---
key: ED-1025
epic: ED-1022
title: Archive - Folder Permission per folder (tab Permission & akses ditolak)
slug: archive-folder-permission
phase: gate2
qa_round: 1
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents:
  analyst: {id: abdb886b9da10fc6a, at: 2026-10-06 16:20}
  qa_be: {id: ab355587439620c95, at: 2026-10-06 22:58}
  be_dev: {id: acbb8627855d7e2cb, at: 2026-10-06 22:37}
  fe_dev: {id: a78e9e5a4694293dd, at: 2026-10-07 02:07}
  qa_fe: {id: aa711278b641b0805, at: 2026-10-07 02:57}
---
## Status ringkas
Long run ED-1022: selesai sampai auto-commit lokal (BE 9fec7c427, FE 89b3b98a6). QA BE PASS r3, QA FE PASS r1 (qa-be.md, qa-fe.md).
Menunggu `/v5-epic review ED-1022`: developer menilai daftar Perlu dikonfirmasi di bawah + 34 butir Daftar tes UI (docs/specs/Archive/001, 002) + AC-23 manual.
Jira: ED-1025 In Progress + label ai-review + komentar siap review; 8 subtask In Progress. Bug luar item: ED-1070, ED-1071, ED-1072.

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

## Usulan pipeline
- QA BE `scope: be` memperluas fuzzing tipe/karakter tiap ronde (r2: 435 kasus baru) sehingga 500 pra-ada lintas v5 (CustomizeBuilder, kolasi latin1) muncul sebagai FAIL item; usul: aturan baku di v5-qa - 500 pra-ada di luar baris diff = "Dilacak di luar item" + usulan tiket, tidak menentukan verdict; ronde 2+ dibatasi ke kegagalan + regresi
- FE dev: orkestrator/BA menjalankan `/ui-discuss --auto` sebelum Gate 1 juga untuk module lama tanpa `_module.md` (kalau tidak, `/ui-plan` berhenti)
- FE dev: baseline full suite FE (4 suite gagal pra-ada: Production.function, InterbankTransferForm, components/Item/BatchOut, CustomerPurchaseOrderView) disimpan di knowledge/ agar QA tidak menghitungnya defect; `/ui-slice` boleh memakai endpoint GET yang sudah tersambung di module lama
- QA FE F-1: butir MANUAL AC-23 (tukar lisensi) tidak punya rumah di `## Daftar tes UI` (sama dengan usulan ED-1024 QA FE F-2): tempat baku di daftar Gate 2 epic

## Log
- 2026-10-06 16:09 start: baseline BE 42f3e07cb (0 berkas kotor), FE 6afd19054 (0 berkas kotor)
- 2026-10-06 16:20 ba: selesai, 17 BR / 24 AC / 8 subtask / 11 K (spec.md, contract.md)
- 2026-10-06 19:50 gate1: disetujui (gabungan); Jira ED-1025 + 8 subtask dibuat, sprint 46
- 2026-10-06 21:17 long-run: mulai fase be; preflight OK (WARN additionalDirectories -> fallback agen by path); rebaseline BE b0efd0722 / FE 6afd19054 (0 kotor) karena HEAD BE bergeser oleh commit ED-1024; ED-1025 + 8 subtask -> In Progress
- 2026-10-06 21:35-23:02 be+qa-be (diringkas): v5-be-dev 5/5 subtask, 19->21 berkas NEW, Updater dijalankan di api_sidomaju; be-review 0 harus/3 konfirmasi; QA BE r1 FAIL (D-1,D-2), r2 FAIL (D-3; D-4/D-5 pra-ada), r3 PASS 17 AC/1 MANUAL, 31/31, ED-1024 26/26; found ED-1070 (D-1,D-5,O-3), ED-1071 (D-4 kolasi); pause 23:02 atas permintaan developer, phase fe
- 2026-10-07 01:23 resume: long run ED-1022 melanjutkan fase fe; preflight OK; BE 21 berkas NEW tetap di working tree, FE bersih (HEAD 6afd19054)
- 2026-10-07 01:31 fe digest: api-contract-analyst (a049a8f393527a74e, sonnet) -> ksp-react docs/specs/Archive/_api.md baru, 11 endpoint; 7 Perlu dikonfirmasi dijawab orkestrator dari spec/kode BE (ditulis di digest), 0 blocker
- 2026-10-07 02:07 fe: FE dev (a78e9e5a4694293dd) ui-discuss(konfirmasi)/ui-plan/slice/logic -> task 001 (ED-1059) + 002 (ED-1063) logic-done; 12 berkas M + 6 baru; eslint 0, build staging OK x4, test Archive 62/62, full suite 1373/1383 (10 gagal = 5 timeout lulus ulang + 4 suite gagal sama di HEAD bersih)
- 2026-10-07 02:09 fe-review: convention-reviewer (a720fbfbe9463fce3, sonnet) Harus diperbaiki 0, Perlu dikonfirmasi 2 (locale id-ID "Back to Archive" tak diterjemahkan; ArchiveForm kini meneruskan disabled)
- 2026-10-07 02:57 qa-fe r1: PASS (AC-3,17-21 PASS, AC-23 MANUAL; e2e 7/7 A+B; unit Archive 62/62; full suite 4 suite gagal = baseline HEAD; regresi BE ED-1025 31/31, ED-1024 26/26); galeri work/e2e/ED-1025-archive-folder-permission-20261007-025357/; qa-fe.md: 1 username QA_USER diredaksi orkestrator
- 2026-10-07 02:58 found: ED-1072 Bug (ai-found) di bawah ED-1022 - klik ⋯ baris dokumen ikut memicu klik sel (QA FE C-5, pra-ada)
- 2026-10-07 02:59 commit: BE 9fec7c427 (21 berkas NEW), FE 89b3b98a6 (22 berkas NEW); Jira ED-1025 label ai-review + komentar siap review; phase: gate2 (menunggu review batch)
