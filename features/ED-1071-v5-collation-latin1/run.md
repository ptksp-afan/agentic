---
key: ED-1071
epic: ED-1022
title: V5 - input berkarakter di luar latin1 (emoji, CJK, Yunani) = 500 "Illegal mix of collations" (Archive)
slug: v5-collation-latin1
phase: gate2
qa_round: 2
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents:
  be_dev: {id: a9f6702ef61528f76, at: 2026-10-13 01:45}
  be_review: {id: a2c8053693e3005bf, at: 2026-10-13 01:48}
  be_dev_fix1: {id: a80f3578c0b7a330a, at: 2026-10-13 03:03}
  qa_fe: {id: a7604bc6bce351139, at: 2026-10-13 04:58}
  qa_be: {id: aa83814b128e7e63d, at: 2026-10-13 04:06}
---
## Status ringkas
**Siap review developer (long run ED-1022).** QA BE r2 PASS, QA FE r1 PASS; commit lokal BE 6fcc91fd0 (FE tidak berubah), agentic lihat Log. Jira ED-1071 In Progress + label ai-review + komentar siap review. Done hanya lewat `/v5-epic review`.
Untuk review batch: be-review Perlu dikonfirmasi 2 (isLatin1 ganda dgn OpnameService; cabang S_BAD select lokasi menyalin Location::optionSelects); fix D-2: angka/array di put-in/hand-over/receive name + add-document id_archive kini 422; 8 skenario regresi lama berhenti karena baseline developer (qa-be.md).
Bug baru: ED-1144 (scopeSearchAll method sembarang, keamanan), ED-1145 (lanjutan K-3 + Opname U+FFFD).
Untuk developer: ED-1027/qa/.restore-journal-q27-user2.json (untracked, hapus ditolak classifier) dibiarkan.

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|

## Keputusan developer
- 2026-10-12 review batch ronde 2 P1 = K-1 a: validasi karakter tanpa ubah skema; input di luar latin1 **ditolak** (bukan 500, bukan `?`), termasuk rename (ED-1069 B-3). Jawaban K-2 a, K-4 a, K-5 a sebelum ditunda (2026-10-08) batal, ditanya ulang (K-2..K-7).
- 2026-10-12 Gate 1 ronde 3 (`epics/ED-1022-gate1.md` §9): K-2 a, K-3 a, K-4 a (`GE0748`), K-5 a, K-6 a, K-7 a; DU-1 a, DU-2 (tabel opname di-truncate), DU-3 a; EPIC K-5 b (ekstraksi flag Izin ED-1029) tidak menyentuh guard item ini. Spec approved.

## Usulan pipeline

## Log
- 2026-10-08 13:03 start: baseline BE 44bea5136 (0 berkas kotor), FE 7fa63869d (0 berkas kotor)
- 2026-10-08 14:00 BA (draft) selesai; Gate 1 ronde 2: item ditunda
- 2026-10-12 BA refresh (P1 tolak non-latin1): probe GET read-only di BE 581569981 (api_sidomaju, QA_USER, 3 sesi force_login, token di-log-out; laporan probe dihapus); spec bug ditulis ulang: 7 langkah perbaikan BE, 10 AC, K-2..K-7 (rendah), tanpa subtask; depends_on [ED-1025, ED-1029, ED-1069] (ED-1070 dibuang); temuan: GE0747 dipakai branch rewrite (K-4)
- 2026-10-12 Gate 1 ronde 3 diterapkan: status approved, §8 (#4 lookup id gabungan 1029 rev3, AC-9 X-1 + `folders`), kode GE0748, Data uji DU-1..3; phase be (rebaseline saat run mulai)
- 2026-10-13 long-run: mulai fase be; preflight OK; rebaseline BE 6a5c069bb / FE d56ce3962 (0 kotor); Jira ED-1071 BUG -> In Progress, assignee diset, summary disesuaikan ke judul spec (Archive, K-3 a)
- 2026-10-13 be: v5-be-dev (a9f6702ef61528f76, opus, fallback general-purpose) selesai; 16 M + 1 baru di BE (Latin1Rule, MyHelper::isLatin1, 8 Request, 5 Service, lang GE0748); php -l 17/17, router 28 rute tetap; smoke 6/6 PASS 176 asersi qa/smoke-dev (QA71D-, sisa 0); 5 baris Catatan implementasi; belum diverifikasi: 403 GE0114 (perlu role sementara), UTF-8 rusak di body JSON; di luar cakupan: CustomizeBuilder::scopeSearchAll memanggil method Builder apa pun dari key `x()` di search (dugaan `delete()`, belum diuji), hand-over/receive name + search select lokasi array -> 500 (lama); 0 ditolak
- 2026-10-13 be-review: v5-be-reviewer (a2c8053693e3005bf, sonnet, fallback) Harus diperbaiki 0, Perlu dikonfirmasi 2 (MyHelper::isLatin1 vs OpnameService::isLatin1 ganda - Opname tetap per spec; SelectArchiveService::location cabang S_BAD menyalin Location::optionSelects) -> review batch; qa/smoke-dev dihapus (pelajaran 2); -> QA BE r1
- 2026-10-13 qa-be r1: v5-qa (a15e38df94e26be5b, sonnet) FAIL; AC-1..AC-9 lulus untuk S_BAD/S_OK spec, 14 skenario qa/scenario1..5 (11 PASS, 3 FAIL); D-1 U+FFFD lolos MyHelper::isLatin1 (500 di 27 titik, description tersimpan `?`), D-2 array S_BAD di put-in/hand-over/receive name, add-document id_archive = 500; AC-9 X-12/X-1/1069 diubah, lulus; regresi 1024 26/26, 1025 39/40, 1026 38/39, 1027 25/3F/1S, 1028 21/24, 1029 40/1S, 1069 16/16 (8 gagal = selisih baseline developer: role 3 1140 permission_name NULL, role 5 Opname Document, 343 archives verified milik sam); data: hanya QA71-/QA71-ROLE-, snapshot sama, jurnal sisa ED-1027 q27-user2 dihapus; usang lain: komentar ED-1029 scenario8-rev3-reject.php:7 (dicatat); pra-ada: OpnameService::isLatin1 U+FFFD -> opnames?search 500; 0 ditolak; komentar defect di Jira ED-1071
- 2026-10-13 03:03 be fix r1: v5-be-dev fresh (a80f3578c0b7a330a, opus, fallback) D-1 U+FFFD ditolak eksplisit di MyHelper::isLatin1 (Opname tetap); D-2 `string` sebelum Latin1Rule di put-in/hand-over/receive name + add-document id_archive, put-in name masuk hasValidTypes(); 5 berkas, php -l OK; run.php ED-1071 14/14 PASS (X-2/X-4/X-5 lulus); 2 baris Catatan implementasi; 0 ditolak -> QA BE r2
- 2026-10-13 04:06 qa-be r2: v5-qa (aa83814b128e7e63d, sonnet) PASS 9/0/0, NV 1 (8 skenario regresi lama = baseline developer, isolasi 1 DB); D-1/D-2 tertutup (X-2/X-4/X-5 + baru qa/scenario6-round2.php R2-1 1867 asersi, R2-2 2532 asersi); regresi 1024 26, 1025 39/1, 1026 38/1, 1027 25/3/1S, 1028 21/3, 1029 40/1S, 1069 16, 1071 16 (sama dgn r1); pra-ada dicatat: 1025 X-13 (ED-1070), 1069 X-4(a), 1027 X-13; data: snapshot 22 tabel sama, tidak ada hapus di luar QA71-; ditolak classifier: hapus ED-1027/qa/.restore-journal-q27-user2.json (artefak QA, tidak diakali, dibiarkan untracked); 1 baca ArchiveService.php ditolak -> dibaca ulang dgn Read; -> fe dilewati (fe_modules []), QA FE r1
- 2026-10-13 04:58 qa-fe r1: v5-qa (a7604bc6bce351139, sonnet) PASS 1/0/0 (AC-10); sidik jari BE sama dgn QA BE r2; build staging OK; Jest Archive 12 suite lulus sendiri (5 timeout beban), full 71/80, 4 non-Archive pra-ada (BatchOut, production.function, CustomerPurchaseOrderView, InterbankTransferForm); e2e --workers 1 7/7, galeri work/e2e/ED-1071-v5-collation-latin1-20261013-045901/; regresi BE 1071 16/16, 1025 39/1 (AC-25 baseline), 1029 40/1S, 1069 16/16; 1024/1026/1027/1028 dikutip dari qa-be r2 (kode sama); data: fixture QA71-E2E-A dibuang, snapshot sama; 0 ditolak
- 2026-10-13 05:03 commit: BE 6fcc91fd0 (17 berkas NEW, path eksplisit), FE tidak ada perubahan; Gate 2 long run = auto-commit lokal; phase: gate2 (menunggu review batch)
- 2026-10-13 05:03 jira: bug baru ED-1144 (scopeSearchAll memanggil method Builder dari key search, High, dari baca kode) dan ED-1145 (lanjutan K-3 modul lain + OpnameService::isLatin1 U+FFFD), label ai-found, parent ED-1022; ED-1071 label ai-review + komentar siap review
