---
key: ED-1250
epic: ED-1022
title: Archive - filter Verification Status (Verified / Unverified)
slug: archive-filter-verification-status
phase: gate2
qa_round: 1
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
---
## Status ringkas
Long run ED-1022: siap review developer (commit lokal BE 9d6bc7b4f, FE 63ebc8cc6; Jira In Progress + ai-review, komentar siap review).
QA BE r1 PASS (AC-1..12: 12 PASS); QA FE r1 PASS (AC-13..24: 11 PASS, 1 MANUAL AC-23); e2e 16/16, galeri work/e2e/ED-1250-archive-filter-verification-status-20261024-184015/.
Batch review: (1) Updater reset cds documentArchive per tenant (DU-5 a); (2) Cari kosong saat filter aktif hanya verificationStatus/showRelatedTransaction = tetap di folder
(dulu showRelatedTransaction saja ke root; doc 027 Catatan); (3) index display-columns memuat nama entri filter utk non-OD (O-2, sesuai kontrak §2).
Temuan luar item: Bug ED-1262. Tes UI manual 22 butir (ksp-react docs/specs/Archive/027-*.md).

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|
| ED-1252 | [BE] Updater - filter Verification Status di display setting Archive | BE | In Progress |
| ED-1253 | [BE] List Archive - filter Verification Status (hanya dokumen, struktur folder tetap) | BE | In Progress |
| ED-1254 | [BE] Display setting - filter Verification Status hanya untuk Opname Document | BE | In Progress |
| ED-1255 | [FE] Archive - filter Verification Status di list (tetap di folder, tetap aktif saat navigasi) | FE | In Progress |
| ED-1256 | [FE] Archive - filter Verification Status di picker Pindahkan / Pilih Folder | FE | In Progress |
| ED-1257 | [QA] Skenario filter Verification Status | QA | In Progress |

## Keputusan developer
- Gate 1 2026-10-24: `epics/ED-1022-gate1.md` §9 dan §9b; ringkas di spec § Tambahan Gate 1.

## Usulan pipeline
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
