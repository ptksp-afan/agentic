---
key: ED-1069
epic: ED-1022
title: Archive - celah validasi pindah/rename folder & 500 di select lokasi / transaksi terkait
slug: archive-validasi-pindah-rename-folder
phase: done
qa_round: 1
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents:
  be_dev: {id: a60aadd92e43e5971, at: 2026-10-10 13:12}
  be_review: {id: a74a005506b0e3cfa, at: 2026-10-10 13:15}
  qa_be: {id: a54c94e5edf038868, at: 2026-10-10 14:05}
  qa_fe: {id: a5959b835ce69f1b6, at: 2026-10-10 15:30}
---
## Status ringkas
**Selesai (Done) - disetujui developer di review batch ED-1022 ronde 2 (2026-10-12).** Jira item + subtask Done, label ai-review dilepas, komentar serah terima ditambahkan.

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|

## Keputusan developer

## Usulan pipeline

## Log
- 2026-10-08 13:03 start: baseline BE 44bea5136 (0 berkas kotor), FE 7fa63869d (0 berkas kotor)
- 2026-10-08 15:40 gate1: jawaban diterapkan
- 2026-10-08 15:42 gate1 ronde 2 disetujui developer (epics/ED-1022-gate1.md §9b); Jira: sprint 46 + komentar keputusan (Bug tanpa subtask, status tetap BUG sampai runner mulai); spec approved; phase: be (long run)
- 2026-10-10 long-run: mulai fase be; preflight OK; rebaseline BE 265c0d1bd / FE 47bb57d1d (0 kotor); Jira ED-1069 BUG -> In Progress, assignee diset
- 2026-10-10 be: v5-be-dev (a60aadd92e43e5971, opus, fallback general-purpose) selesai; 13 M + 1 baru di BE (Rules/Requests/Services DocumentArchive, Select, lang en/id ARCHIVE404 teks + ARCHIVE419); php -l 14/14, router OK; smoke 6/6 PASS di qa/smoke-dev (QA69D-, dihapus, sisa 0); 1 baris Catatan implementasi (urutan antar-400 put-in); temuan di luar cakupan: relatedTransaction folder senama no transaksi -> 500 (perlu repro QA), putInFolder set is_active=1 sumber; 0 ditolak
- 2026-10-10 be-review: v5-be-reviewer (a74a005506b0e3cfa, sonnet, fallback) Harus diperbaiki 0, Perlu dikonfirmasi 4 (parentFolderRule+isValidParent ganda, cek ARCHIVE419 ganda di rename(), SelfFolderRule lintas namespace, UniqueNameRule Archive tanpa filter type = perilaku lama) -> review batch; -> QA BE r1
- 2026-10-10 qa-be r1: v5-qa (a54c94e5edf038868, sonnet) PASS 12/12 [BE] AC, 0 FAIL/0 MANUAL, 16 skenario / 821 asersi (qa/scenario1-5); isolasi tenant n/a (1 DB); regresi ED-1024 26/0, ED-1025 39/1 (X-12 usang karena B-3, milik ED-1071; X-8 induk=diri dibalik QA 1069, lulus), ED-1026 39/0, ED-1027 28/0/1 SKIP, ED-1028 24/0, ED-1029 29/0/1 SKIP; di luar item B-1 500 relatedTransaction folder senama no transaksi, B-2 put-in mengaktifkan sumber nonaktif, B-3 rename non-latin1 kini 500 (akar ED-1071, dulu 200 tersimpan ?), B-4 select lokasi induk '0' = root; data dihapus hanya QA69- + 1 folder fixture SI-JOG/2511/00002 + role QA69-ROLE-*, jumlah archives sama; 0 ditolak
- 2026-10-10 fe: tanpa kode FE (fe_modules []; contract null) -> digest, FE dev, convention-reviewer dilewati; -> QA FE r1 (build, Jest, e2e AC-13/14, regresi BE)
- 2026-10-10 qa-fe r1: v5-qa (a5959b835ce69f1b6, sonnet) PASS 2 (AC-13, AC-14) / MANUAL 1 (AC-13 ketik Cari modal: B-FE-1) / FAIL 0; build staging OK; Jest Archive 11 suite lulus (sendiri), full 12 gagal beban, 4 non-Archive pra-ada (B-FE-2); e2e --workers 1 5/5, galeri work/e2e/ED-1069-archive-validasi-pindah-rename-folder-20261010-152924/; regresi BE ED-1069 16, 1024 26, 1025 39/1 (X-12 = ED-1071), 1026 39, 1027 28/1 SKIP, 1028 24, 1029 29/1 SKIP; data dihapus hanya fixture 0QA69E-, jumlah archives sama; 0 ditolak
- 2026-10-10 commit: BE 878314df7 (14 berkas NEW), FE tidak ada perubahan; Gate 2 long run = auto-commit lokal; phase: gate2 (menunggu review batch)
- 2026-10-10 jira: bug baru ED-1124 (BE B-1/B-2/B-4) dan ED-1125 (FE B-FE-1), label ai-found; komentar B-3 di ED-1071; ED-1069 label ai-review + komentar siap review
- 2026-10-10 commit agentic: c625f4f (features/ED-1069-*, ED-1025 qa/scenario4 X-8)
- 2026-10-12 review batch ronde 2: disetujui developer -> Jira Done (item + subtask), label ai-review dilepas, komentar serah terima; phase done
