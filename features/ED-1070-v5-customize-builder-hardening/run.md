---
key: ED-1070
epic: ED-1022
title: V5 CustomizeBuilder - tutup celah keamanan + input salah bentuk di searchAll/sortAll/pagination (V4 dimatikan)
slug: v5-customize-builder-hardening
phase: done
qa_round: 2
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents:
  be_dev: {id: a41533cd58dbb206f, at: 2026-10-15 15:05}
  qa_be: {id: ae9553963d1668f32, at: 2026-10-15 16:26}
---
## Status ringkas
**Siap review developer (long run ED-1022), 2026-10-15.** Commit lokal BE 5c4c69bcd (86 berkas NEW, v5-opname-archive), agentic = commit `[DOC] ED-1070`; FE tidak berubah. Jira In Progress + label ai-review.
- QA BE r2 (opus) PASS 13/13 AC (`qa-be.md`); r1 FAIL D-1/D-2/D-3 diperbaiki di ronde perbaikan 1. Golden: 223 PASS / 4 DIFF data/seri (guard `users` dinetralkan, login QA_USER2), known500 63 now200 / 33 still500 (pra-ada, ED-1167). e2e 8/8 `work/e2e/ED-1070-v5-customize-builder-hardening-20261015-161622/`.
- Untuk review: (1) URL berkas buatan BE kini `/api/v5/select/files/download` (dulu `/api/v4/...`); di disk S3 V5 menjawab redirect 302 (V4 kirim berkas langsung); (2) cds 2199/2200 (raptorDeposit/raptorInvoice, dibuat app saat GET Raptor QA r1) belum dihapus - DELETE ditolak classifier; pilihan: `/usr/bin/php work/qa-ED-1070/r2/cds-cleanup.php --apply` atau biarkan; (3) review BE Perlu dikonfirmasi: log warning per entri tanpa batas jumlah, `isRelationMethod` membaca berkas model tiap panggilan (tanpa cache statis, aturan RR); (4) spec § Catatan implementasi 1-12 (mis. nilai skalar searchNumber/searchDate tetap perilaku lama demi golden); (5) jalur `()` di branch ini dulu sudah 500 (`Str::replace` tak ada di L5.7) sebelum method jalan.
- Tiket di luar item (ai-found): ED-1165 (High, customer/address|contacts|bank-accounts tanpa auth:api), ED-1166 (Low, files/download berkas hilang 500 bukan 404; allow-list e2e D-3), ED-1167 (Medium, 33 request list 500 pra-ada).
- Berikutnya: `/v5-epic review ED-1022` -> Done bila disetujui.
## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|

## Keputusan developer
- G1 K-1..K-8: a (spec § Keputusan; commit BE gaya tim tanpa trailer, V4 dimatikan via laravel-modules, data uji api_sidomaju)
- 2026-10-15 08:51 developer: K-2 diubah - commit BE `[FIX] CustomizeBuilder - ...` DENGAN trailer Co-Authored-By (spec K-2 + deviasi 3 diperbarui)

## Usulan pipeline
- Harness golden: guard tabel `users` sebaiknya mengecualikan semua user QA (QA_USER2 dst., bukan hanya QA_USER) - login suite QA membuat 228 list SKIPPED-guard di r2.
- QA yang membuka list Raptor membuat baris `column_display_settings` lewat app; DELETE baris milik QA ditolak classifier. Usul: fixture/teardown standar (atau izin) untuk efek samping app semacam ini, atau hindari GET pertama modul tanpa setting.
- Tangkapan golden "sesudah" ditulis ke `work/` (bukan `qa/golden/`), hanya ringkasan compare di qa; r1 `after/` dipindah ke `work/golden/ED-1070/after-r1/` agar commit workspace tidak membengkak.
- Agent repo `v5-be-dev`/`v5-be-reviewer` tidak terdaftar di sesi runner: dipakai fallback general-purpose + definisi berkas (agents.md).

## Log
- 2026-10-08 13:03 start: baseline BE 44bea5136 (0 berkas kotor), FE 7fa63869d (0 berkas kotor)
- 2026-10-14 14:14 Gate 1 ronde 5: DITUNDA lagi oleh developer (pengaruh banyak modul, diskusi tim dulu). Arah developer: jalur `()` (A-3, dulu ED-1144) ikut ditangani di item ini (setara K-5 d) - konfirmasi di Gate 1 berikutnya. Spec tetap draft; `qa/baseline-valid-params.json` (2026-10-14) perlu ditangkap ulang saat item dilanjutkan
- 2026-10-15 05:15 hasil diskusi tim: desain + rencana implementasi CustomizeBuilder V5 (skip + log, guard, middleware normalize_pagination, V4 di luar lingkup). Folder diganti nama dari ED-1070-v5-search-json-folder-induk; dokumen tim dipindah ke brief/: design.md (dulu spec-customize-builder.md), plan.md (dulu plan-customize-builder-design.md), diskusi-tim.md. spec.md (BA 2026-10-14, opsi 422) belum diperbarui - perlu BA ulang dari brief/
- 2026-10-15 06:28 developer (keputusan sesudah review dokumen tim): 1 a = dikerjakan pipeline di workspace ini, branch BE v5-opname-archive (bukan v5-salesman-activity); 2 c = modul V4 dimatikan lewat fitur laravel-modules (menutup jalur () V4); 3 a = middleware normalize_search untuk search berbentuk array di service yang json_decode sendiri; 4 b = pagination tanpa batas atas diterima (A-8). Rebaseline (be 0b8417778); BA ulang dari brief/ dimulai
- 2026-10-15 07:31 gate1 disetujui developer: K-1..K-8 a (epics/ED-1022.md Log); spec approved (13 AC); Jira: judul baru + High + sprint 46 + komentar (Bug tanpa subtask, status BUG sampai runner mulai); phase: be. DEVELOPER: JANGAN DIJALANKAN OTOMATIS - tunggu instruksi (/v5-epic ED-1022 --only ED-1070)
- 2026-10-15 08:41 runner long run mulai: preflight OK, BE bersih di 0b8417778; Jira ED-1070 BUG -> In Progress, assignee diisi
- 2026-10-15 10:27 developer: STOP sekarang. Runner dihentikan paksa (TaskStop) saat agent QA merapikan hasil tangkapan golden; tidak ada proses QA tersisa; tree BE/FE bersih; golden "sebelum" + cek stabilitas selesai (231/231 PASS sesudah penanda); qa-be.md belum ditulis; phase tetap be
- 2026-10-15 12:31 runner dilanjutkan (resume): preflight OK; BE bersih di 0b8417778; K-2 (trailer) dicatat di spec + Keputusan developer
- 2026-10-15 12:36 QA pra-dev (v5-qa opus ae5cb48165311ed18): compare before vs check-before2 dengan golden.php 10:26 = PASS 231 / DIFF 0 / known500 still500 96; before/ vs unmarked-backup hanya beda penanda unstable (9 list, 51 req); guard 154 tabel + 130 modul sama persis dengan tangkapan sebelum -> golden "sebelum" tetap berlaku, tidak ditangkap ulang (`qa/golden-before.md` § Verifikasi ulang). Berikutnya: BE dev
- 2026-10-15 12:37 BE dev mulai: general-purpose (opus) dengan definisi ksp-erp/.claude/agents/v5-be-dev.md (nama agent tidak terdaftar di sesi; fallback agents.md)
- 2026-10-15 13:05 BE dev selesai (a38f9d05477dfdf88): 3 berkas baru (CustomizeBuilderGuard, NormalizePagination, NormalizeSearch) + 71 diubah (trait V5, Kernel, 68 berkas Routes = 781 statement: P 262 / S 584 sesuai routes.tsv, V4/module.json active 0); lint OK, api/v4 1321->0 route, golden subset in-proc 36 list 35 PASS (employee.users#009 seri tak stabil), 33 known500 jadi 200; smoke 7/7; spec § Catatan implementasi 1-9. Temuan: jalur () di branch ini dulu 500 (Str::replace tak ada di L5.7) sebelum method jalan. Berikutnya: review BE
- 2026-10-15 13:12 review BE (general-purpose sonnet, definisi v5-be-reviewer; a06fc6c4a383139fa): lolos, 0 Harus diperbaiki. Perlu dikonfirmasi (batch review): (1) log warning per entri tanpa batas jumlah per request (panjang dibatasi); (2) isRelationMethod membaca berkas model tiap panggilan (tanpa cache statis, aturan RR); (3) di luar item: route customer/address|contacts|bank-accounts tanpa auth:api sejak HEAD -> diverifikasi QA (GET tanpa token) sebelum tiket. Berikutnya: QA BE ronde 1 (opus)
- 2026-10-15 14:33 QA BE r1 (v5-qa opus a5f548aa297b22306): FAIL - PASS 8 / FAIL 5 (AC-1 D-1, AC-2/3 D-2, AC-9/10 D-3); golden 225 PASS / 6 DIFF (4 seri tak stabil terbukti, 2 = D-1), known500 now200 63 / still500 33 (pra-ada); e2e 8/8 (work/e2e/ED-1070-v5-customize-builder-hardening-20261015-143114/); data QA02-P70 dibuat+dihapus, checksum sama; sesi log-out (token sisa 10:27 ikut tercabut). D-3 tinggi: route bernama select.files.download hanya di V4 -> 12 pemanggil V5 500. Di luar item: customer/address|contacts|bank-accounts tanpa token 200 + baris -> tiket. Jira: komentar defect (11531)
- 2026-10-15 14:40 ronde perbaikan 1: BE dev baru (laporan dev >1 jam) untuk D-3, D-1, D-2
- 2026-10-15 15:05 perbaikan r1 selesai (dev a41533cd58dbb206f): D-3 13 pemanggil V5 -> v5.select.files.download (endpoint V5 setara V4; beda hanya S3 = redirect 302), sapuan: tak ada dependensi V4 lain / v3; D-1 relasi dibuat sekali; D-2 ArchiveService::isSearching() + CustomizeBuilderGuard::searchMayApply() (tanpa log); spec Catatan 10-12; lint 17 berkas OK; golden subset 14 list PASS dengan guard users dinetralkan (users berubah 14:16 UTC, 1 user lain ter-update di jendela QA r1). Tiket di luar item: ED-1165 (Bug, High, ai-found) customer/address|contacts|bank-accounts tanpa auth:api
- 2026-10-15 15:08 QA BE r2 (opus) mulai
- 2026-10-15 15:10 jira: bug di luar item ED-1165 (customer/address|contacts|bank-accounts tanpa auth:api, High, ai-found)
- 2026-10-15 16:20 QA BE r2 (ae9553963d1668f32): PASS 13/13 AC; D-1/D-2/D-3 tertutup; golden mentah PASS 3 / SKIPPED-guard 228 (guard users berubah: login QA_USER2 21:16 WIB), dinetralkan PASS 223 / DIFF 4 (data/seri, dibuktikan) / SKIPPED 4 (cds Raptor; 78/78 request identik); known500 now200 63 / still500 33; suite 8 item hijau; e2e 8/8; data QA02-P70 dibuat+dihapus; sesi log-out
- 2026-10-15 16:24 jira: bug di luar item ED-1166 (files/download 500 bukan 404, Low) dan ED-1167 (33 request list 500 pra-ada, Medium), ai-found; QA: allow-list ED-1166 di spec e2e D-3 (1 OK, work/e2e/...-162348/); hapus cds 2199/2200 (milik QA, dicek read-only) DITOLAK classifier -> dibiarkan, keputusan developer di review
- 2026-10-15 16:30 qa/golden/after (r1, usang) dipindah ke work/golden/ED-1070/after-r1/ (ukuran commit)
- 2026-10-15 16:32 commit (long run, auto sesudah QA PASS, review 0 Harus diperbaiki): BE 5c4c69bcd (86 berkas NEW) dengan trailer Co-Authored-By (K-2 diubah 08:51), FE tidak berubah, agentic = commit ini (+ karakterisasi AC-9 ED-1025 scenario4/5, ED-1027 scenario9); Jira label ai-review + komentar siap review; phase: gate2 (menunggu review batch)
- 2026-10-16 06:02 review batch ED-1022: disetujui developer -> Jira Done (transisi 71), label ai-review dilepas, komentar serah terima (perilaku berubah + pantau log [blocked] seminggu); phase done. cds 2199/2200: belum diputuskan developer
- 2026-10-16 07:38 developer: baris column_display_settings 2199/2200 dibiarkan (tidak dihapus)
