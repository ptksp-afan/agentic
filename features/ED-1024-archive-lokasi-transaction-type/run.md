---
key: ED-1024
epic: ED-1022
title: Archive - Visibilitas folder per lokasi kerja & daftar transaction type terpusat
slug: archive-lokasi-transaction-type
phase: gate2
qa_round: 1
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents:
  analyst: {id: aa6a94ab9641affdd, at: 2026-10-06 16:25}
  be_dev: {id: a715245cc4e08048e, at: 2026-10-06 20:41}
  qa_fe: {id: ad86dc6ca7776a297, at: 2026-10-06 21:12}
  qa_be: {id: aafa26c064f460d6f, at: 2026-10-06 20:47}
---
## Status ringkas
Long run ED-1022: selesai sampai auto-commit lokal (BE b0efd0722; FE tanpa perubahan). QA BE PASS r2, QA FE PASS r1.
Menunggu `/v5-epic review ED-1022` (label ai-review; Jira In Progress, belum Done). Tes manual: AC-13 tukar lisensi.
Bug luar item: ED-1069 (baru), ED-967 (sudah ada, PrivateRoute).

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|
| ED-1030 | [BE] Archive - scope lokasi kerja terpusat (list, pencarian, transaksi terkait, show/history, aksi berbasis id, kode ARCHIVE407, 404) | BE | In Progress |
| ED-1036 | [BE] Archive - daftar transaction type terpusat (select Type, label, validasi store, Billing) | BE | In Progress |
| ED-1042 | [BE] Archive - permission lisensi Salesman Activity di route Archive | BE | In Progress |
| ED-1048 | [BE] Archive - status HTTP kode lama (ARCHIVE401-406), validasi lokasi folder/dokumen & hapus folder (K-6/K-7) | BE | In Progress |
| ED-1054 | [QA] Skenario visibilitas lokasi, transaction type & lisensi Archive | QA | In Progress |

## Keputusan developer
- G1 (gabungan ED-1022, 19:24): semua rekomendasi diterima - EPIC K-1 a, EPIC K-2 b, grup G-1..G-7 (epics/ED-1022-gate1.md §6), K-n lain = rekomendasi analyst; jawaban tertulis di spec § Keputusan

## Review BE - Perlu dikonfirmasi (untuk review batch)
- R-1 route `archives/add-document` tetap tanpa permission_v5 (spec §4: tidak disentuh)
- R-2 lompatan kode ARCHIVE407 -> ARCHIVE418 (408-411 milik item 02, sesuai spec §3)
- R-3 authorize() melempar 403/404 lewat service: penerapan pertama decisions.md "Hak akses di luar permission_v5"
- QA O-1 BR-2 "hanya lokasi aktif" vs getUserLocation() tanpa filter locations.is_active: perbaiki kalimat spec atau tambah filter (qa-be.md)
- QA O-4 id_archives skalar non-teks -> 404 ARCHIVE400 (bukan 422), tidak berbahaya
- QA FE F-1 PrivateRoute tidak mengalihkan ke /unathorized (pra-ada, ED-967): klausa "route tertolak" AC-13 hanya ditegakkan BE + menu
- Dev: hapus folder kini hanya terhalang dokumen is_active > 0 (dokumen -1/0 tidak menghalangi), spec ## Catatan implementasi

## Usulan pipeline
- Item BE-only tanpa task FE: butir MANUAL (mis. AC-13) tidak punya rumah `## Daftar tes UI`; usul: tempat baku di qa-fe.md / daftar Gate 2 epic (QA FE F-2)
- QA_USER2 di config/secrets.env ditolak login (GE0109, user tidak ada di api_sidomaju): perbaiki kredensial atau cek di preflight

## Log
- 2026-10-06 16:09 start: baseline BE 42f3e07cb (0 berkas kotor), FE 6afd19054 (0 berkas kotor)
- 2026-10-06 16:25 ba: selesai, 17 BR / 17 AC / 5 subtask / 8 K (spec.md, contract.md)
- 2026-10-06 19:50 gate1: disetujui (gabungan); Jira ED-1024 + 5 subtask dibuat, sprint 46
- 2026-10-06 19:53 long-run: mulai fase be; preflight OK; ED-1024 + subtask -> In Progress
- 2026-10-06 20:11 be: v5-be-dev selesai 4/4 subtask (lint 19 berkas OK, smoke 11 PASS); temuan luar cakupan 1-5 di laporan dev (Catatan implementasi spec)
- 2026-10-06 20:14 be-review: v5-be-reviewer (a9eb1b049584ff15d) Harus diperbaiki 0, Perlu dikonfirmasi 3
- 2026-10-06 20:37 qa-be r1: FAIL (15 PASS / 1 MANUAL AC-13; D-1 put-in array -> 500, ED-1048); O-1..O-3 perlu dikonfirmasi; komentar D-1 di ED-1048; fix ronde 1 ke be_dev
- 2026-10-06 20:39 found: ED-1069 Bug (ai-found) di bawah ED-1022 - 5 celah pra-ada Archive (put-in induk tak ada/siklus, rename nama kembar, select locations 500, related yatim 500)
- 2026-10-06 20:41 be fix r1: D-1 diperbaiki (PutInFolderRequest -> 422); runner dev 25 PASS / 0 FAIL; QA BE ronde 2 dimulai
- 2026-10-06 20:47 qa-be r2: PASS (15 PASS / 1 MANUAL, regresi 26/26, 0x 500); O-1 (BR-2 lokasi nonaktif) + O-4 catatan -> review batch
- 2026-10-06 21:12 qa-fe r1: PASS (AC-16/17 e2e PASS, AC-13 MANUAL, regresi BE 26/26); galeri work/e2e/ED-1024-archive-lokasi-transaction-type-20261006-2108*/-2109*
- 2026-10-06 21:14 commit: BE b0efd0722 (19 berkas NEW), FE tidak ada perubahan; Jira ED-1024 label ai-review + komentar siap review
