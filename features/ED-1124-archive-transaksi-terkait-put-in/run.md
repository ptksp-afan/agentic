---
key: ED-1124
epic: ED-1022
title: Archive - transaksi terkait 500 bila ada folder senama nomor transaksi; put-in mengaktifkan lagi sumber nonaktif
slug: archive-transaksi-terkait-put-in
phase: gate2
qa_round: 2
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents:
  be_dev: {id: a9fec9a091ba0b02c, at: 2026-10-14 14:50}
  be_reviewer: {id: adc8a8c8f18dd0f6f, at: 2026-10-14 14:54}
  qa_be_r2: {id: ad5341ae475fc2be3, at: 2026-10-14 16:37}
  qa_be_r1: {id: a21ca3f616a7ada1d, at: 2026-10-14 15:52}
---
## Status ringkas
Long run ED-1022, siap review developer. BE commit lokal 0b8417778 (3 berkas Modules/V5); FE tidak berubah (fase FE/QA FE dilewati, item BE saja). QA BE ronde 2 PASS 10/10 (qa-be.md); ronde 1 D-1 = 3 asersi lama dibalik (Catatan review). Jira ED-1124 In Progress + label ai-review. Bug luar item: ED-1152. Menunggu /v5-epic review.

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|

## Keputusan developer
- G1 K-1: a (sumber dihapus lewat id_archives = id tak ada, 404 ARCHIVE400; campuran = yang aktif saja)
- G1 K-2: b (findName hanya dokumen, dikerjakan di item ini)
- G1 K-3: a (dokumen uji QA124-, id_transaction nyata; folder senama nomor nyata di folder QA, hapus lewat id)

## Catatan review (batch review)
- Review BE: Harus diperbaiki 0, Perlu dikonfirmasi 0. Saran: komentar usang `DocumentService.php:31-32` dan docblock `PutInFolderRequest.php:79` (sumber lewat name kini selalu dokumen) - di luar diff, dirapikan item berikutnya; `Archive/UniqueNameRule.php:54` tanpa `type`: membuat folder N di induk yang sama dengan dokumen N aktif tetap 405 (perilaku lama, kini lebih sering terjangkau karena K-2 b).

- Runner (QA BE r1 D-1): 3 asersi lama put-in `name` = folder (ED-1025 AC-14 `scenario3-enforce.php:134`, AC-37 `scenario7-rev-hak.php:443-446`; ED-1069 AC-3 `scenario1-putin.php:169-170`) dibalik ke 404 `ARCHIVE400` sebagai karakterisasi milik ED-1124 (konsekuensi langsung K-2 b; Gate 1 r5 §3 izinkan sunting skrip qa/ item lama). Bukan keputusan baru; ditampilkan di review batch.

- QA BE r2: QA juga menyesuaikan judul AC-3 `ED-1069 scenario1-putin.php:140` (tanpa logika) dan memperbaiki urutan id AC-7 di skrip sendiri (`generateId` acak dalam detik yang sama).

## Usulan pipeline
- Analyst bug mode: saat perilaku diubah keputusan K, grep skrip `features/*/qa/` yang menguji jalur itu dan daftarkan sebagai karakterisasi yang dibalik (ED-1124 melewatkan 3 skrip ED-1025/ED-1069).

## Log
- 2026-10-14 12:52 start: baseline BE 6fcc91fd0 (0 berkas kotor), FE cf37552dd (0 berkas kotor)
- 2026-10-14 14:42 gate1 ronde 5 (epic ED-1022) disetujui developer: K-1 a, K-2 b, K-3 a (epics/ED-1022-gate1.md §10); spec approved; Jira: sprint 46 + komentar keputusan (Bug tanpa subtask, status tetap BUG sampai runner mulai); phase: be (long run)
- 2026-10-14 14:43 runner mulai: preflight OK; changed.sh 0 berkas; Jira ED-1124 BUG -> In Progress, assignee diisi; v5-be-dev (general-purpose + definisi repo, opus) mulai
- 2026-10-14 14:50 be: v5-be-dev selesai, 3 berkas Modules/V5 (Archive.php, ArchiveService.php, SelectArchiveService.php); smoke dev 7/7 PASS, baris QA124-DEV- dihapus; AC-1/2 tidak diuji dev (folder nomor nyata = QA). v5-be-reviewer mulai
- 2026-10-14 14:54 be: review BE 0 Harus diperbaiki / 0 Perlu dikonfirmasi / 3 saran (Catatan review); phase qa-be, v5-qa scope be ronde 1 mulai
- 2026-10-14 15:52 qa-be r1: FAIL (PASS 9, FAIL 1 AC-10 = D-1 skrip lama vs K-2 b); bug luar item O-1 -> ED-1152 (Bug, ai-found, sprint 46); komentar Jira D-1; ronde 2: QA membalik 3 asersi, kode BE tetap
- 2026-10-14 16:37 qa-be r2: PASS 10/10 (FAIL 0, MANUAL 0); suite ED-1024..1029/1069/1071 lulus; karakterisasi ED-1070 tetap; skrip lama disunting: ED-1025 scenario3-enforce, scenario7-rev-hak; ED-1069 scenario1-putin (+ r1: scenario4-select-related, scenario5-extra = AC-9); K-3 folder dibuat/dihapus lewat id (work/ED-1124/k3-ids.log); sisa QA 0, snapshot ED-1149 sama
- 2026-10-14 16:37 fe/qa-fe: dilewati (spec fe_modules [], FE tidak berubah)
- 2026-10-14 16:37 cek kebocoran rahasia (folder fitur + 5 skrip lama): nilai QA_PASS* 0 berkas
- 2026-10-14 16:37 commit: BE 0b8417778 (3 berkas NEW, path eksplisit), FE tidak ada perubahan; Gate 2 long run = auto-commit lokal; phase: gate2 (menunggu review batch)
