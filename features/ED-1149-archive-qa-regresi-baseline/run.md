---
key: ED-1149
epic: ED-1022
title: "[QA] Archive - skenario regresi ED-1026/ED-1027/ED-1028 tahan data baseline developer"
slug: archive-qa-regresi-baseline
phase: gate2
qa_round: 1
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents: {qa-be-r1: a3007a098e768914c, qa-fe-r1: ada5f7df6346e5e63}
---
## Status ringkas
Long run ED-1022 (2026-10-14): QA BE r1 PASS + QA FE r1 PASS → auto-commit lokal agentic (tanpa commit BE/FE: task QA murni).
Menunggu review batch developer (`/v5-epic review ED-1022`); Jira ED-1149/ED-1151 In Progress + label ai-review.
Catatan untuk review: (1) ED-1026 spec BR-29/AC-29 ("desktop lebar dibatasi") kini beda dengan FE 41480e37c (developer, 2026-10-12;
asersi e2e ED-1026 :1713 disesuaikan ke desain sekarang); (2) ketergantungan baca-saja yang tersisa: QA_USER [default] = role 3 berhak
Archive penuh, ED-1150 memakai role asli sam (5), role sistem 1 untuk state superadmin; (3) `updated_by='sam'` kini 400 (spec: 343).
Berkas belum di-commit sesudah commit: run.md ini (hash commit). Bukan milik run ini: features/ED-1150-*/run.md, epics/*, features/ED-1070-*.

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|
| - | [QA] Skenario regresi Archive tahan data baseline developer (ED-1025..ED-1028 + regresi penuh) | QA | ED-1151 (In Progress) |

## Keputusan developer
- Gate 1 (2026-10-13/14): K-1 b (e2e `[user2]` diperbaiki + regresi e2e penuh 8 item), K-2 a (X-10 dibatasi baris QA), K-3 b (role 6/22/29 dan mode `norole` ED-1026 diganti preset), K-4 b (AC-25 di DB scratch, DB bersama hanya `permissions`), K-5 gugur (jurnal sudah tidak ada).

## Usulan pipeline
- (dari QA FE) e2e ED-1024 `noperm`: smoke route 403 memang perilaku yang diharapkan (`ED-1024/qa-fe.md:43`); regresi penuh perlu
  `--grep AC-13` untuk state itu. Usul: catat cara jalan per state di header spec e2e ED-1024 / knowledge QA, supaya regresi epic
  berikutnya tidak salah baca.

## Log
- 2026-10-13 09:17 start: baseline BE 6fcc91fd0 (0 berkas kotor), FE d56ce3962 (0 berkas kotor)
- 2026-10-13 BA: spec.md ditulis (QA murni, tanpa contract; 8 BR, 13 AC, 1 subtask [QA], 5 keputusan)
- 2026-10-14 Gate 1: jawaban K-1..K-5 dimasukkan ke spec, `status: approved`, `phase: be` (task QA murni)
- 2026-10-14 gate1 (epic loop): Jira subtask ED-1151 [QA] (sprint 46) + komentar Gate 1 di ED-1149
- 2026-10-14 long run start: preflight OK; rebaseline BE 6fcc91fd0 (0 kotor), FE cf37552dd (0 kotor); ED-1149 + ED-1151 → In Progress
- 2026-10-14 be: n/a (QA-only task, `be_modules: []`); fe dev: n/a (QA-only task, `fe_modules: []`)
- 2026-10-14 qa-be r1 (v5-qa sonnet, a3007a098e768914c): PASS 11 / not_verifiable 2 (isolasi satu-DB). AC-1..AC-8 PASS; regresi 9 suite
  230 PASS / 2 SKIP (ED-1027 X-3, ED-1029 X-ISO) / 0 FAIL; 500 hanya known ED-1070, ED-1122, ED-1124; defect baru 0. Di luar daftar
  awal juga ditulis ulang: ED-1024 (AC-13 hapus sementara 12 `permissions`, X-5 nonaktifkan role 2 → role fixture), ED-1029 scenario3.
  Snapshot before vs after-be SAMA (beda hanya users.updated_at QA_USER/QA_USER2). Catatan: `updated_by='sam'` kini 400 (terverifikasi
  sam 12), bukan 343; tidak berpengaruh.
- 2026-10-14 qa-fe r1 (v5-qa sonnet, ada5f7df6346e5e63): PASS 4 (AC-9, AC-10 FE, AC-12, AC-13). Build staging FE cf37552dd. Regresi e2e
  10 item, --workers 1: 142 OK / 0 gagal akhir (ED-1024 jog 2, super 2, noperm AC-13 1; ED-1025 21; ED-1026 15+norole 2; ED-1027 21;
  ED-1028 16; ED-1029 30; ED-1069 5; ED-1071 7; ED-1072 11; ED-1150 sam 9), 0 5xx, defect kode 0. 3 kegagalan awal = alat QA usang
  (ED-1026 spec.js:1713 vs FE 41480e37c; ED-1072 spec.js:265,468 judul drawer; ED-1024 noperm smoke 403 sesuai qa-fe ED-1024) → dibetulkan.
  e2e ditulis ulang: ED-1024 (state.php, spec, fixtures), ED-1025 (spec catatan), ED-1026/1027/1028/1029 (fixture, spec, fixtures,
  ED-1029 routes), ED-1072 (spec). AC-13 before vs after-fe SAMA (hash role 3/5 dan baris sam identik; beda hanya users.updated_at
  QA_USER/QA_USER2); sisa QA 0, jurnal 0, token aktif 0; tidak ada data developer dihapus.
- 2026-10-14 gate2 (long run): QA BE + QA FE PASS, tidak ada Harus diperbaiki → auto-commit lokal agentic saja (BE/FE tidak berubah).
  Cek kebocoran rahasia pada 54 berkas kandidat: nilai QA_PASS* 0 berkas. FOUND: tidak ada bug baru (tidak ada tiket).
