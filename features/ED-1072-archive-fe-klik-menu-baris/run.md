---
key: ED-1072
epic: ED-1022
title: Archive FE - klik tombol ⋯ pada baris dokumen ikut memicu klik sel dokumen
slug: archive-fe-klik-menu-baris
phase: done
qa_round: 1
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents:
  fe_dev: {id: a88e776716bad854f, at: 2026-10-10 16:06}
  fe_review: {id: aea3fcfc310545d95, at: 2026-10-10 16:01}
  qa_fe: {id: a87e44137fa781b67, at: 2026-10-10 17:31}
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
- 2026-10-08 15:42 gate1 ronde 2 disetujui developer (epics/ED-1022-gate1.md §9b); Jira: sprint 46 + komentar keputusan (Bug tanpa subtask, status tetap BUG sampai runner mulai); spec approved; phase: fe (long run)
- 2026-10-10 15:36 long-run: mulai fase fe; preflight OK; rebaseline BE 878314df7 / FE 47bb57d1d (0 kotor); Jira ED-1072 BUG -> In Progress, assignee diset
- 2026-10-10 15:37 repro HEAD: probe Playwright non-mutasi (scratchpad, bukan qa/e2e) di staging 47bb57d1d (tidak basi): dokumen root, klik ⋯ -> menu terbuka + `tr` ant-table-row-selected=true (sebelum: false); breadcrumb root tetap (dokumen root tanpa breadcrumbs); galeri work/e2e/ED-1072-repro-20261010-153707/; disableDefaultRowExpand tidak dipakai jalur onCell -> bug masih ada
- 2026-10-10 15:58 fe: FE dev (a88e776716bad854f, general-purpose) task docs/specs/Archive/021-fix-cell-action-click.md logic-done (slice n/a, scope logic); FE 4 M kode/test + _module.md M + 1 task baru; isCellActionClick (archive.function.js) + guard baris pertama onClickName/onClickDocument; eslint 0; Archive runInBand 11 suite 372/372 (14 baru; 4 test bug gagal bila guard dimatikan); build staging OK; full suite 1689/1693 (4 suite non-Archive pra-ada: BatchOut, InterbankTransferForm, production.function, CustomerPurchaseOrderView); Perlu dikonfirmasi 0; 0 ditolak
- 2026-10-10 16:01 fe-review: convention-reviewer (aea3fcfc310545d95, sonnet, fallback) Harus diperbaiki 0, Perlu dikonfirmasi 1 (AC-6 ikon Document Verified dan AC-9 menu konteks hanya diuji di e2e, bukan unit halaman -> QA FE e2e, review batch), catatan 4 (2 nit Prettier di test baru -> dirapikan FE dev; komentar >120 kolom; picker ArchiveTable di luar cakupan terverifikasi)
- 2026-10-10 16:06 fe fix nits: FE dev (a88e776716bad854f) 2 nit Prettier di test baru dirapikan (test saja); eslint 0; 2 berkas test 232/232 (run ke-1 1 timeout tooltip ED-1025 C-1 saat load ~4.7 = flake beban yang sudah tercatat); -> QA FE r1
- 2026-10-10 17:31 qa-fe r1: v5-qa (a87e44137fa781b67, sonnet) PASS 10/10 AC, 0 FAIL/0 MANUAL, 1 not_verifiable (Enter asli pada ⋯: N-1 pra-ada); build staging OK; Jest Archive 11 suite 372/372, full 79 suite -> 4 suite baseline non-Archive + flake tooltip ED-1025 C-1; e2e --workers 1 11 OK (galeri work/e2e/ED-1072-archive-fe-klik-menu-baris-20261010-172748/), kontrol negatif di build HEAD 47bb57d1d: AC-2/3/4/5/9 + K-2 GAGAL (work/e2e/...-20261010-163812/); regresi BE 1024 26, 1025 39/1 (X-12 = ED-1071), 1026 39, 1027 28/1 SKIP, 1028 24, 1029 29/1 SKIP, 1069 16; data: hanya fixture 0QA72- (6 archives, 2 archive_documents, 1 archive_permissions) dibuat+dibuang, sisa 0, checksum 4 tabel archive* = baseline; AC-9 baca-saja DO-JOG/2511/00007; 0 ditolak
- 2026-10-10 17:33 jira: bug baru ED-1126 (FE N-1: Enter/Spasi tidak mengaktifkan tombol Archive, keydown di-preventDefault useBarcodeScanner), label ai-found; N-2 (+N tanpa data nyata) hanya catatan review
- 2026-10-10 17:33 commit: FE 3ed9945af (6 berkas NEW: 4 kode/test + _module.md + task 021), BE tidak ada perubahan; Gate 2 long run = auto-commit lokal; phase: gate2 (menunggu review batch)
- 2026-10-10 17:34 jira: ED-1072 label ai-review + komentar siap review (tanpa hash)
- 2026-10-10 17:35 commit agentic: 0a73649 (features/ED-1072-*)
- 2026-10-12 review batch ronde 2: disetujui developer -> Jira Done (item + subtask), label ai-review dilepas, komentar serah terima; phase done
