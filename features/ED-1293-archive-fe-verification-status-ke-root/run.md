---
key: ED-1293
epic: ED-1022
title: Archive FE - filter Verification Status kembali ke root seperti filter lain (halaman & picker)
slug: archive-fe-verification-status-ke-root
phase: gate2
qa_round: 1
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents:
  fe_dev: {id: aa6d41ac3f32a88b6, at: 2026-10-27 09:24}
  fe_review: {id: a5cfeef6c96f4f9ca, at: 2026-10-27 09:27}
  qa_fe: {id: aba584f0c4697bf04, at: 2026-10-27 11:52}
---
## Status ringkas
QA FE r1 **PASS** (11 PASS / 0 FAIL / 1 MANUAL AC-12; e2e 21/21; regresi ED-1125 18 OK, ED-1069 5 OK, unit ED-1150 hijau). Laporan `qa-fe.md`.
Long run: commit lokal FE `b7dabc8a9` (10 berkas, kelas NEW) + workspace; BE tidak disentuh. Publish FE saat `/v5-epic review`.
Jira: ED-1293 In Progress + label ai-review + komentar siap review; ED-1294/1295/1296 In Progress. **Done hanya sesudah developer menyetujui di `/v5-epic review`.**

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|
| ED-1294 | [FE] Archive - filter Verification Status kembali ke root di list | FE | In Progress |
| ED-1295 | [FE] Archive - filter Verification Status kembali ke root di picker Pindahkan / Pilih Folder | FE | In Progress |
| ED-1296 | [QA] Skenario filter Verification Status kembali ke root | QA | In Progress |

## Keputusan developer
- Gate 1 2026-10-27: jira.md (1 a / 2 a / 3 a / 4 a), K-1 a, K-2 b, K-3 a; spec § Tambahan Gate 1. Run sampai review FE saja, QA menunggu instruksi.

## Usulan pipeline

## Log
- 2026-10-27 08:43 start: baseline BE 8d996000d (0 berkas kotor), FE 004018d12 (0 berkas kotor)
- 2026-10-27 09:05 fe: preflight OK; Jira ED-1293, ED-1294, ED-1295 -> In Progress (ED-1296 tetap To Do); tanpa digest API (contract null). FE dev mulai
- 2026-10-27 09:24 fe: dev selesai, task 029 logic-done (ED-1294+ED-1295 satu task), 9 berkas diubah + 1 baru; unit 3 suite 332/332 lulus, 11 gagal dgn kode lama; eslint/prettier bersih; staging build OK. Review konvensi mulai
- 2026-10-27 09:27 fe: review konvensi bersih (0 HD / 0 PD). Catatan tak memblokir untuk review: (1) komentar `_onFinishFilter` ArchivePage/index.js:227-229 & ArchiveTable/index.js:70-73 tidak menyebut pengecualian K-3 a (JSDoc archive.function.js:133-136 sudah benar); (2) `_module.md:191` indeks 027 masih "tetap di folder" (judul 027 tidak disunting); (3) `_api.md:1485` kalimat lama utuh, rev ED-1293 di :1486
- 2026-10-27 09:27 catatan: asersi ED-1250 yang diganti ED-1293 (spec BR-4 FE, BR-12, AC-14 kal. 2, AC-17, AC-18; e2e ED-1250-filter-verifikasi.spec.js) - folder ED-1250 tidak disunting; e2e untuk QA FE
- 2026-10-27 09:27 berhenti sebelum QA FE atas instruksi developer; phase: gate2, perubahan FE tidak di-commit
- 2026-10-27 10:56 qa-fe: developer meminta QA; preflight OK, changed.sh = 10 berkas FE (sama), BE HEAD 8d996000d bersih; ED-1296 -> In Progress. QA FE round 1 mulai
- 2026-10-27 11:52 qa-fe: r1 PASS 11 / MANUAL 1 (`qa-fe.md`; galeri `work/e2e/ED-1293-archive-fe-verification-status-ke-root-20261027-111530/`, rerun AC-2+4 `-20261027-112501/`); suite e2e item ini di `qa/e2e/` (salinan ED-1250 + asersi yang dibalik); data `QA50-`/`0QA125-`/`0QA69E-` 0 baris, jurnal pulih (user_roles QA_USER2, bahasa QA_USER, cds 2586). Full suite: 4 suite gagal pra-ada (BatchOut, production.function, InterbankTransferForm, CustomerPurchaseOrderView; identik di HEAD bersih, sama seperti ED-1150/1250/1251) - bukan temuan baru
- 2026-10-27 11:55 commit (long run, lokal): FE b7dabc8a9 (10 berkas NEW), BE - (tidak disentuh); publish FE tidak dijalankan (saat /v5-epic review)
- 2026-10-27 11:56 jira: ED-1293 label ai-review + komentar siap review (tanpa hash); ED-1294/1295/1296 tetap In Progress; tidak ada temuan luar item (4 suite gagal pra-ada sudah tercatat di ED-1150/1250/1251)
