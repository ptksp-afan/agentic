---
key: ED-1165
epic: ED-1022
title: V5 Setup Customer - route address / contacts / bank-accounts tanpa auth:api (bisa dibaca tanpa login)
slug: v5-customer-route-tanpa-auth
phase: done
qa_round: 1
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents:
  be_dev: {id: a4b03172f03674238, at: 2026-10-16 08:08}
  be_reviewer: {id: a3e1b40725a9ecf61, at: 2026-10-16 08:10}
  qa_be: {id: a9b5fa0abe8eda003, at: 2026-10-16 08:38}
---
## Status ringkas
Long run ED-1022: siap review developer (commit lokal, Jira In Progress + ai-review). QA BE r1 PASS 6/6 (AC-6 e2e 7/7).
BE 13014964d. Batch review: BR-1 "12 statement" = 16 statement / 12 method+URI (wording spec); golden ED-1070 memuat 3 list yang kini 404.

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|

## Keputusan developer
- G1 K-1/K-2: 12 route R1-R12 (5 blok grup) di customer.php dikomentari + komentar kode singkat; tanpa middleware baru; kontroler tidak diubah
- G1 K-3: route V5 lain tanpa auth:api memang disengaja, tidak dilaporkan; K-4, K-5 gugur

## Usulan pipeline
- ED-1070 `qa/golden/requests.json` memuat 3 list (customer/address, contacts, bank-accounts) yang kini 404: replay golden akan DIFF; buang ketiganya dari golden bila dipakai lagi
- e2e harness `apiResponses()` tidak cocok dengan API_URL berport `:80` (kosmetik, QA ED-1165)

## Log
- 2026-10-16 07:39 start: baseline BE 5c4c69bcd (0 berkas kotor), FE 41146943c (0 berkas kotor)
- 2026-10-16 08:01 Gate 1 ED-1165 (developer): K-1 + K-2 = route R1-R12 di customer.php DIKOMENTARI (tidak dipakai FE web/mobile, dimatikan karena tanpa auth:api), tanpa middleware baru; K-3 diabaikan (route lain memang tanpa auth:api); K-4, K-5 gugur; disetujui, jalankan sekarang. Analyst menyesuaikan spec (AC: 12 route 404, diff router tepat 12, regresi layar Pelanggan)
- 2026-10-16 08:03 spec ditulis ulang analyst (8,2 KB): 5 blok grup customer.php (L21-41, L52-61) dikomentari beserta R1-R12; cek ulang ksp-react (termasuk android/, electron/): 0 pemanggil; CustomerBankAccountShortcut memanggil route lain (sudah 405, tidak dipasang); AC: 404 dgn/tanpa token, router dump 3304 -> 3292, regresi customers + e2e Pelanggan (POST/PUT dicegat, tanpa tulis data); spec approved; Jira sprint 46 + komentar; phase: be
- 2026-10-16 08:05 be: preflight OK; Jira ED-1165 BUG -> In Progress, assignee diisi; BE HEAD = baseline 5c4c69bcd, 0 berkas berubah
- 2026-10-16 08:08 be-dev: selesai; 1 berkas (customer.php +29/-26, 5 blok/16 statement = 12 route R1-R12 dikomentari + komentar ED-1165); php -l OK; router 3304->3292, diff hanya 131,142d130; smoke PASS 4/0 (GET 404 dgn/tanpa token, customers 200); POST/PUT tidak dikirim
- 2026-10-16 08:10 be-review: 0 harus diperbaiki; perlu dikonfirmasi (untuk batch review): BR-1 menyebut "12 statement", sebenarnya 16 statement = 12 method+URI; kode benar
- 2026-10-16 08:11 qa-be r1 mulai (v5-qa sonnet, scope be + AC-6 e2e)
- 2026-10-16 08:38 qa-be r1: PASS 6/0/0 (qa-be.md); e2e 7/7 work/e2e/ED-1165-v5-customer-route-tanpa-auth-20261016-083308/; POST/PUT customers diintersep (1+1), 0 request ke R1-R12; COUNT tabel sama; 500 select/files/download foto = ED-1166 (sudah bertiket, di luar item)
- 2026-10-16 08:40 commit: BE 13014964d ([FIX] Customer ... (ED-1165)); agentic: commit folder fitur ini; Jira In Progress + ai-review + komentar siap review; menunggu /v5-epic review
- 2026-10-16 09:23 review batch ED-1022: disetujui developer -> Jira ED-1165 Done (transisi 71), label ai-review dilepas, komentar serah terima; phase done
