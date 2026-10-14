---
key: ED-1150
epic: ED-1022
title: Archive - baris folder hilang sesudah navigasi breadcrumb pada daftar pendek
slug: archive-fe-virtualtable-list-pendek
phase: gate2
qa_round: 1
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents:
  fe_review: {id: a97caaef2fcb81250, at: 2026-10-14 03:55}
  fe_dev: {id: a024994fe1773c315, at: 2026-10-14 03:50}
  qa_fe: {id: a6a1ef20ed06475a2, at: 2026-10-14 04:20}
---
## Status ringkas
Long run ED-1022: QA FE r1 PASS, FE di-commit lokal (cf37552dd), BE tidak berubah. Jira ED-1150 In Progress + label ai-review + komentar siap review.
phase gate2 = menunggu review batch developer (/v5-epic review); Done hanya sesudah developer setuju. AC-10 manual di review.
Untuk dikonfirmasi di review: AC-4 reset pencarian kembali ke root (pencarian lintas folder, perilaku lama).
Berkas belum di-commit: tidak ada (BE/FE bersih; folder fitur di-commit di workspace).

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|

## Keputusan developer
- Gate 1 (2026-10-14): B2 a, B3 a, K-4 a (sam/`QA_USER3` dengan force_login selama run, log-out semua sesi), K-5 a (key juga per halaman/ukuran halaman/pencarian).

## Usulan pipeline
- Test picker ArchiveTable: tambah kasus ukuran halaman + pencarian; timeout per-tes 15-20 dtk untuk tes navigasi 5 langkah (QA r1, flake di bawah beban).
- Pindahkan helper test `holdableNetwork`/`openFolder` ke `testUtils/mockApi` bila dipakai lagi (FE dev + reviewer).
- Harness e2e: dukung profil `user3` = QA_USER3/QA_PASS3 seperti `user2` (sekarang lewat wrapper qa/e2e/run-sam.sh).
- Spec ED-1150 AC-1..AC-7: tambahkan kriteria "terlihat" (baris tidak terpotong wadah) di samping DOM = respons; AC-1 literal tidak menangkap bug ini (QA r0).

## Log
- 2026-10-14 01:19 start: baseline BE 6fcc91fd0 (0 berkas kotor), FE d56ce3962 (0 berkas kotor)
- 2026-10-14 rebaseline: BE 6fcc91fd0, FE d56ce3962 (sama dengan baseline awal, 0 berkas kotor). Preflight OK
- 2026-10-14 jira: ED-1150 BUG -> In Progress (sprint 46, assignee sudah ada)
- 2026-10-14 catatan spec: AC-1 menulis "AC-8 yang menentukan" bila tidak tereproduksi; maksudnya AC-10 (manual Gate 2), sesuai arahan epic loop
- 2026-10-14 02:35 qa-fe r0 baseline (aeb63e72d1dba4df2, sonnet): prasyarat data OK (root 3, A 2, B 398/10 per hal). AC-1 literal LOLOS di baseline (tr = respons), bug tereproduksi lewat kriteria terlihat: wadah VT memakai tinggi daftar sebelumnya (B 9/10 terlihat, A 1/2, root 2/3), deterministik 3 run. AC-2/3/4/6/7 gagal terlihat, AC-5 + AC-8 PASS. Akar masalah #4 spec (slice head/tail) tidak teramati; yang basi tinggi wadah, fix B2 a tetap berlaku (remount = konteks VT baru). Run e2e work/e2e/ED-1150-archive-fe-virtualtable-list-pendek-20261014-020558/. Profil `sam` via qa/e2e/run-sam.sh (pemetaan env QA_USER3 -> QA_SAM_*, harness bersama tidak diubah). Sesi sam: semua di-log-out, 0 token aktif
- 2026-10-14 03:50 fe: FE dev (a024994fe1773c315, general-purpose) task docs/specs/Archive/025-fix-table-remount-per-list.md logic-done (slice n/a); getArchiveListKey (archive.function.js) + listKey dari onSuccess params di ArchivePage + ArchiveTable; FE 6 M + 2 baru; eslint 0; Archive 13 suite 461/461 (+14; 9/10 test remount gagal tanpa key); full suite di bawah beban: 19 suite gagal timeout, ulang berurutan 14 lulus, sisa 4 pra-ada non-Archive (BatchOut, production.function, InterbankTransferForm, CustomerPurchaseOrderView) + 1 flake test baru diperkuat; build staging OK; Daftar tes UI 13 butir. Perlu dikonfirmasi 1: AC-4 reset pencarian kembali ke root (pencarian lintas folder, perilaku lama), bukan Folder A -> review batch
- 2026-10-14 03:55 fe-review: convention-reviewer (a97caaef2fcb81250, sonnet, fallback general-purpose) Harus diperbaiki 0; Perlu dikonfirmasi 1 (AC-4: reset pencarian kembali ke root karena pencarian lintas folder `idArchive: null`, perilaku lama, tidak diubah -> review batch); catatan 3 (posisi key vs spread tanpa efek, helper test dobel, eslint-disable no-node-access di test)
- 2026-10-14 04:20 qa-fe r1 (a6a1ef20ed06475a2, sonnet): PASS 9 / MANUAL 1 (AC-10) / FAIL 0. e2e sam 2 run identik: 39/39 langkah literal + terlihat OK (baseline terlihat 11/39); Folder B 350/350 (baseline 313). AC-5 tidak remount (scrollTop 100 tetap), AC-8 0 error. Archive 13 suite 461/461 (runInBand); full suite di bawah beban 15 suite gagal, 11 hijau saat diulang, sisa 4 pra-ada non-Archive. AC-2 bagian folder ditolak View SKIP (sam tidak punya). Sesi sam 3 login, semua log-out, SESI-1 0 token aktif. Galeri work/e2e/ED-1150-archive-fe-virtualtable-list-pendek-20261014-034752/
- 2026-10-14 04:25 commit: FE cf37552dd (8 berkas NEW: 3 kode + 3 test + task 025 + _module.md), BE tidak ada perubahan; Gate 2 long run = auto-commit lokal; phase: gate2 (menunggu review batch)
- 2026-10-14 04:26 jira: ED-1150 label ai-review + komentar siap review (tanpa hash); tetap In Progress
- 2026-10-14 04:27 cek kebocoran rahasia di folder fitur sebelum commit workspace: nilai QA_PASS* 0 berkas (CLIENT_ID 1 karakter = positif palsu)
