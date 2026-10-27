---
key: ED-1250
epic: ED-1022
title: Archive - filter Verification Status (Verified / Unverified)
slug: archive-filter-verification-status
phase: done
qa_round: 3
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents:
  be_dev: {id: aee8cea2a42747a46, at: 2026-10-24 14:34}
  be_reviewer: {id: ad4bc2d7b504192bb, at: 2026-10-24 14:36}
  qa_be_r1: {id: a708ce69aebe209e1, at: 2026-10-24 15:10}
  api_digest: {id: af1c9ac1c2a09e5c8, at: 2026-10-24 15:20}
  fe_dev: {id: a1710aa06bcbfbb5e, at: 2026-10-24 16:45}
  fe_reviewer: {id: a9d6607c303417d76, at: 2026-10-24 16:50}
  qa_fe_r1: {id: a03e0a9e8e717fdfe, at: 2026-10-24 18:45}
  qa_fe_r3: {id: a6abaae31977db57d, at: 2026-10-26 07:15}
  qa_fe_r2: {id: a5d71fc7076d08e39, at: 2026-10-26 06:33}
  api_digest_rev: {id: a8cc50a9b4abf5b39, at: 2026-10-26 05:39}
  qa_be_r2: {id: a9194d260da43ac4b, at: 2026-10-26 05:32}
  be_reviewer_rev: {id: a42ee5c51ba456f26, at: 2026-10-26 05:11}
  be_dev_rev: {id: a2fa14a40569b6ba6, at: 2026-10-26 05:09}
---
## Status ringkas
Long run ED-1022, revisi review 2026-10-26 (R-1, R-2): siap review developer lagi. Commit fix lokal di atas commit item: BE 6e27d627b (di atas 9d6bc7b4f/fba6a5883), FE 1bf24cfa8 (dok saja; di atas 63ebc8cc6/7ca714504); Jira In Progress + ai-review.
QA BE r2 PASS (13 AC BE: AC-1..12 + AC-25; 7 asersi r1 terbalik oleh R-1); QA FE r2 FAIL hanya D-1 -> r3 PASS (12 PASS, 1 MANUAL AC-23); e2e ED-1250 18/18, galeri work/e2e/ED-1250-archive-filter-verification-status-20261026-070046/.
Batch review: (1) D-1: AC-25 bagian FE menyebut halaman Setup > Display Setting yang tidak di-route di FE; dicek pada drawer Display Setting Archive + respons index di sesi browser (catatan orkestrator di spec § Catatan implementasi, teks AC tidak diubah) - mohon konfirmasi; memasang halaman itu = item terpisah;
(2) Updater ED-1250 kodenya tetap (urutan dari config): DB yang sudah menjalankan versi lama butuh jalan ulang; di api_sidomaju sudah (cds 2260 -> 2586, DU-5 a); (3) O-6: PUT non-pemegang dengan `available_queries: null` menyisakan hanya entri tersaring (bukan bentuk kiriman FE).
Tes UI manual 23 butir (ksp-react docs/specs/Archive/027-*.md, butir 1 dan 23 diperbarui). Temuan luar item lama: Bug ED-1262.

## Review 2026-10-26: minta perbaikan
Developer meminta perbaikan; daftar dan keputusan di spec § "Revisi review 2026-10-26". Fase berikut: be (fix commit di atas commit item).

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|
| ED-1252 | [BE] Updater - filter Verification Status di display setting Archive | BE | Done |
| ED-1253 | [BE] List Archive - filter Verification Status (hanya dokumen, struktur folder tetap) | BE | Done |
| ED-1254 | [BE] Display setting - filter Verification Status hanya untuk Opname Document | BE | Done |
| ED-1255 | [FE] Archive - filter Verification Status di list (tetap di folder, tetap aktif saat navigasi) | FE | Done |
| ED-1256 | [FE] Archive - filter Verification Status di picker Pindahkan / Pilih Folder | FE | Done |
| ED-1257 | [QA] Skenario filter Verification Status | QA | Done |

## Keputusan developer
- Gate 1 2026-10-24: `epics/ED-1022-gate1.md` §9 dan §9b; ringkas di spec § Tambahan Gate 1.

## Usulan pipeline
- (revisi 2026-10-26) `long-run.md`: aturan untuk QA FAIL yang hanya karena premis spec salah (layar sasaran AC tidak ada; semua opsi tidak mengubah kode item) - kali ini dijawab orkestrator dari kode dengan bukti (pola digest Perlu dikonfirmasi) + QA ronde berikut, bukan blocker; usul dijadikan aturan tertulis
- (revisi 2026-10-26) `api-contract-analyst`: digest ED-1028 menulis "satu-satunya pemakai FE: Setup > Display Setting" tanpa cek route; usul: setiap klaim layar FE dicek ke `src/routes/routes.js`/`paths.js`/`menus.js`
- (revisi 2026-10-26) knowledge (QA/dev): di `api_sidomaju` kolom `role_permissions.permission_name` tidak bisa dipakai untuk cek hak (QA_USER2 role 5 memegang Opname Document lewat `id_permission` 1139); pakai `id_permission` seperti `MyHelper::hasPermission`
- (revisi 2026-10-26) e2e harness: hanya menyimpan galat pertama per test (QA FE r2 menghitung 2 asersi terbalik dari nilai teramati); `page.screenshot` timeout 15 dtk jadi flake di AC-18 picker
- ED-1027 `qa/scenario9` AC-22 usang sejak ED-1250 (pemegang OD kini punya entri `verificationStatus` di `queries`): perbarui pembandingnya bila skrip ED-1027 dipakai lagi (QA O-3)
- Agent repo (`v5-be-dev`, `v5-be-reviewer`, `api-contract-analyst`, `convention-reviewer`) tidak terdaftar di daftar agent sesi runner: dipakai lewat fallback general-purpose + badan agent
- FE `Search` global: popover filter di suffix Form.Item `query` hilang dari store sesudah `resetFields` (usulan FE dev: `forceRender` / pindah popover) - keputusan developer, menyentuh semua list

## Log
- 2026-10-23 11:38 start: baseline BE 13014964d (0 berkas kotor), FE 41146943c (0 berkas kotor)
- 2026-10-24 14:20 be: preflight OK; HEAD BE/FE = baseline, pohon bersih; Story + 6 subtask -> In Progress; v5-be-dev mulai (general-purpose + badan agent, opus; agent repo tidak terdaftar)
- 2026-10-24 14:33 be: v5-be-dev aee8cea2 selesai ED-1252/1253/1254 (6 berkas BE; smoke 6 PASS/1 SKIP, probe 65 PASS; kontrak §1 + spec Catatan implementasi diperbarui); Updater dijalankan di api_sidomaju (cds documentArchive 1683 -> 2260, DU-5 a); v5-be-reviewer mulai
- 2026-10-24 14:35 be: v5-be-reviewer ad4bc2d7 - 0 Harus diperbaiki; Perlu dikonfirmasi 1 (Updater reset baris cds documentArchive per tenant = DU-5 a, ke batch review); 3 saran. QA BE r1 mulai
- 2026-10-24 15:12 qa-be r1: PASS 12 AC / 0 FAIL / 0 MANUAL / 2 tidak terverifikasi (ISO-1 satu DB; AC-10 unpark sesudah ED-1251); regresi ED-1027 12 PASS + 1 usang (O-3), ED-1028 AC-28 PASS; teardown C-8 PASS; O-1 -> Bug ED-1262 (ai-found); O-2 (index display-columns memuat entri, pola ED-1027) ke batch review
- 2026-10-24 15:13 fe: api-contract-analyst mulai
- 2026-10-24 15:19 fe: digest docs/specs/Archive/_api.md diperbarui (af1c9ac1); Perlu dikonfirmasi 31 (index, = kontrak §2/butir 27) dan 32 (DU-5 a; render dari queries) dijawab orkestrator dari kontrak/Gate 1, tanpa blocker; FE dev mulai (ui-plan/slice/logic)
- 2026-10-24 16:45 fe: FE dev a1710aa0 selesai, task 027 logic-done (ED-1255+ED-1256 satu task); build staging OK; 26 test baru lulus; full suite 16 suite gagal = 4 pra-ada (QA ED-1150) + 12 timeout (lulus runInBand); Beda dari spec 1 (Cari kosong saat hanya filter verifikasi aktif = tetap di folder) ke batch review; convention-reviewer mulai
- 2026-10-24 16:50 fe: convention-reviewer a9d6607c - 0 Harus diperbaiki; Perlu dikonfirmasi 1 (Cari kosong dgn hanya showRelatedTransaction aktif kini tetap di folder, dulu root) ke batch review; 4 saran (reformat prettier baris lama, openArchive disalin 2x, rujukan doc 027:115 - diperbaiki orkestrator, test panjang 5 s); QA FE r1 mulai
- 2026-10-24 18:45 qa-fe r1: PASS AC-13..24 11 PASS / 1 MANUAL (AC-23) / 0 FAIL; e2e ED-1250 16/16, ED-1125 18 OK, ED-1069 5 OK, regresi BE 16 PASS/1 SKIP; full suite 4 suite gagal pra-ada (QA ED-1150); teardown C-8 PASS; qa-fe.md
- 2026-10-24 18:50 commit (long run, lokal): BE 9d6bc7b4f, FE 63ebc8cc6; publish FE tidak dijalankan (dilakukan saat /v5-epic review); Jira ED-1250 label ai-review + komentar siap review; subtask tetap In Progress
- 2026-10-26 04:57 review: minta perbaikan (spec § Revisi review 2026-10-26); rebaseline be fba6a5883, fe 7ca714504 (3 berkas BE kotor milik developer, bukan item ini); commit item: be 9d6bc7b4f, fe 63ebc8cc6
- 2026-10-26 04:59 be (revisi review): preflight OK; HEAD BE fba6a5883 / FE 7ca714504 = baseline; BE kotor = 3 berkas developer (PRE, untuk ED-1251, tidak disentuh); Jira ED-1250 In Progress tanpa ai-review; FE tanpa perubahan kode (urutan filter dirender dari `queries` BE); v5-be-dev (R-1, R-2) mulai
- 2026-10-26 05:09 be (revisi): v5-be-dev a2fa14a4 selesai R-1 (ED-1252: config documentArchive urutan name,type,updatedAt,showRelatedTransaction,verificationStatus (+updatedBy available); docblock Updater; Updater dijalankan ulang in-process di api_sidomaju, cds documentArchive 2260 -> 2586, updater_logs tidak disentuh, DU-5 a) dan R-2 (ED-1254: index display-columns tersaring `withoutHiddenItems`, respons PUT ikut tersaring); 3 berkas BE NEW, 3 berkas developer PRE tidak disentuh; smoke 4 PASS/51 asersi; kontrak §1/§2 + spec Catatan implementasi diperbarui; v5-be-reviewer mulai
- 2026-10-26 05:11 be (revisi): v5-be-reviewer a42ee5c5 - 0 Harus diperbaiki; Perlu dikonfirmasi 1 (Updater kodenya tetap, tenant yang sudah menjalankannya butuh jalan ulang = R-1/DU-5 a, ke batch review); 2 saran (cek hasPermission 2x di index; BR-11 spec masih 'sesudah Type', ditimpa R-1 - diteruskan ke QA); QA BE r2 mulai
- 2026-10-26 05:32 qa-be r2 (revisi): PASS 13 AC BE (AC-1..12, AC-25) / 0 FAIL / 0 MANUAL / 1 tidak terverifikasi (ISO-1); run penuh 18 PASS/1 SKIP; 7 asersi r1 terbalik oleh R-1 (AC-1 x4, AC-11 x3), tabel di qa-be.md; regresi ED-1027 12 PASS + 1 usang (O-3), ED-1028 AC-28 PASS; teardown C-8 PASS (cds 2586 tetap, urutan R-1); skrip QA scenario1/6(baru)/9 + qa_lib diubah
- 2026-10-26 05:32 fe (revisi): tanpa perubahan kode FE (urutan dari `queries` BE); yang usang: digest `docs/specs/Archive/_api.md` #1/#30/#31 (index kini tersaring, urutan R-1) dan doc 027 (rujukan config 'sesudah Type', tes UI butir 1, butir AC-25 belum ada); api-contract-analyst mulai
- 2026-10-26 05:39 fe (revisi): digest `_api.md` diperbarui (a8cc50a9; #1 urutan R-1, #30/#31 index + respons PUT tersaring; Perlu dikonfirmasi 27/31 TERJAWAB; 0 Perlu dikonfirmasi baru); doc 027 diperbarui orkestrator (Catatan rujukan BE R-1/R-2, tes UI butir 1 urutan, butir 23 baru AC-25); tanpa kode FE -> FE dev dan convention-reviewer dilewati; QA FE r2 mulai
- 2026-10-26 06:33 qa-fe r2 (revisi): FAIL hanya D-1 - AC-13..22, AC-24 PASS (11), AC-23 MANUAL, AC-25 bagian FE tidak bisa diverifikasi: halaman Setup > Display Setting tidak di-route di FE (`DisplaySettingPage` tanpa route/paths/menu sejak c61af18d0); bukti pengganti (index dari sesi browser + drawer Display Setting Archive) PASS; build OK, unit 5 suite/341 PASS, e2e ED-1250 18/18, ED-1125 18, ED-1069 5, regresi BE 18 PASS/1 SKIP; 5 asersi e2e r1 terbalik oleh R-1; teardown C-8 PASS; galeri work/e2e/ED-1250-archive-filter-verification-status-20261026-060248/
- 2026-10-26 06:33 qa-fe (perbaikan D-1, orkestrator): D-1 bukan cacat kode dan kedua opsi QA tidak mengubah kode item -> dijawab dari kode FE dengan bukti (pola digest Perlu dikonfirmasi), bukan blocker: spec § Catatan implementasi (catatan orkestrator 2026-10-26, AC tidak diubah), doc FE 027 butir 23 -> drawer Display Setting Archive, digest `_api.md` #30 (halaman tidak di-route); keinginan memasang halaman itu = item terpisah, ke batch review. QA FE r3 mulai (cek ulang D-1 dulu, lalu regresi skrip ED-1250)
- 2026-10-26 07:15 qa-fe r3: PASS 12 / 0 FAIL / 1 MANUAL (AC-23); D-1 ditutup (klaim route dicek QA dari kode; AC-25 FE pada drawer + index sesi browser; butir 23 bisa dijalankan); kode identik r2 (hasil build/unit/ED-1125/ED-1069/ED-1027/ED-1028 r2 dibawa); e2e ED-1250 18/18 (run pertama 17/1 = flake screenshot timeout AC-18, lulus ulang); BE run.php ED-1250 18 PASS/1 SKIP; teardown C-8 PASS; O-1 redaksi doc 027 butir 20/23 ('ikon pengaturan' -> tautan "Pengaturan Tampilan") diperbaiki orkestrator
- 2026-10-26 07:15 commit (long run, lokal, fix di atas commit item): BE 6e27d627b (3 berkas, kelas NEW; 3 berkas developer PRE tidak di-stage), FE 1bf24cfa8 (2 dok); publish FE tidak dijalankan (tanpa perubahan kode FE)
- 2026-10-26 07:16 jira: ED-1250 label ai-review + komentar siap review (3 butir konfirmasi: D-1, Updater jalan ulang, O-6); subtask tetap In Progress; commit workspace menyusul
- 2026-10-27 02:28 review 2026-10-27: disetujui developer -> Jira Done (Story + 6 subtask), label ai-review dilepas, komentar serah terima
