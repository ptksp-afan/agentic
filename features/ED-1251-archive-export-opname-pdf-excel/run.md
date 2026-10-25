---
key: ED-1251
epic: ED-1022
title: Archive - export PDF & Excel hasil opname (drawer Opname History & Opname Result)
slug: archive-export-opname-pdf-excel
phase: gate2
qa_round: 1
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents:
  be_dev: {id: ac41719b6fa3f3e7c, at: 2026-10-24 19:20}
  be_reviewer: {id: aa9071d73952c0be2, at: 2026-10-24 19:23}
  qa_be_r1: {id: a44ec559f0b2b9e6b, at: 2026-10-24 20:43}
  be_dev_fix1: {id: a91e05a301f61086a, at: 2026-10-24 20:59}
  qa_be_r2: {id: a5fb05ee47a27fa57, at: 2026-10-24 21:20}
  api_digest: {id: ac626b33334c899e8, at: 2026-10-24 21:31}
  fe_dev: {id: a2e956bb562b0da13, at: 2026-10-24 22:24}
  fe_reviewer: {id: a4184f3f9632087ff, at: 2026-10-24 22:27}
  qa_fe_r1: {id: a449a85e373cc6030, at: 2026-10-25 00:03}
  qa_f1: {id: a93e44988f615440b, at: 2026-10-25 00:08}
---
## Status ringkas
Long run ED-1022: siap review developer (commit lokal BE fba6a5883, FE 7ca714504; Jira In Progress + ai-review, komentar siap review; subtask In Progress).
QA BE r2 PASS (AC-1..15: 15/15; r1 D-1 xls hang diperbaiki); QA FE r1 PASS (AC-16..22 7/7, AC-23 MANUAL); e2e 11/11, galeri work/e2e/ED-1251-archive-export-opname-pdf-excel-20261024-234609/.
F-1 (ED-1028 scenario9, dua keadaan) dan F-2 (ED-1028 e2e histRows) diperbarui QA item ini (gate1 §3).
Batch review: (1) memori produksi sesi besar (128M gagal PDF 10.000; mesin QA 2048M lulus); (2) pdfPreview `$request->all()` tanpa FormRequest, kunci tak dikenal ikut template;
(3) GET select/pdf/xls membuat baris template/cds bila belum ada, tanpa penjaga race; (4) baris cds documentArchiveOpnameResult di Setup > Display Setting tanpa label FE (§9);
(5) `Continuous` di Customize = 422 + notifikasi; (6) nama kunci `getSelectArchiveOpnameReportPdf*` (ikut spec §5). Publish FE tidak dijalankan (saat /v5-epic review).
Temuan luar item: Bug ED-1283 (kode bersama laporan PDF/Excel). Tes UI manual 24 butir (ksp-react docs/specs/Archive/028-*.md).

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|
| ED-1258 | [BE] Archive - display setting & template PDF hasil opname (config display + pdfTemplate, registrasi, PERMISSION_FILTERS, route select template laporan) | BE | In Progress |
| ED-1259 | [BE] Archive - export PDF (template + pratinjau Customize) & Excel satu sesi opname, sumber kolom bersama | BE | In Progress |
| ED-1260 | [FE] Archive - tombol Print & Excel di Opname History dan Opname Result (DrawerPdf + Customize seperti PO Report) | FE | In Progress |
| ED-1261 | [QA] Skenario export PDF & Excel hasil opname (isi berkas, template/Customize, akses, display setting, skala, e2e, F-1/F-2) | QA | In Progress |

## Keputusan developer
- Gate 1 2026-10-24: `epics/ED-1022-gate1.md` §9 dan §9b; ringkas di spec § Tambahan Gate 1.

## Usulan pipeline
- prompt QA BE (long run): sebut eksplisit butir gate1 §3 "karakterisasi dibalik" milik item (F-1 tidak dikerjakan QA BE r1; ditutup addendum QA sesudah QA FE).
- usulan A-13 (knowledge/decisions.md, sesudah run): Customize laporan tanpa route `selected-pdf-templates` = 404 (PO Report dan ±32 laporan lain), K-11 c: dibiarkan tanpa tiket.

## Log
- 2026-10-23 11:38 start: baseline BE 13014964d (0 berkas kotor), FE 41146943c (0 berkas kotor)
- 2026-10-24 18:52 rebaseline ED-1251 (C-4): be 9d6bc7b4f, fe 63ebc8cc6
- 2026-10-24 18:54 long run mulai: preflight OK; Story + 4 subtask -> In Progress; agent repo tidak terdaftar per nama -> fallback general-purpose + `<BE_DIR>/.claude/agents/*.md`
- 2026-10-24 19:20 be: v5-be-dev selesai ED-1258 + ED-1259 (12 berkas PHP, kontrak §1/2/4/5/8 ditandai (BE), Catatan implementasi spec); smoke 200/4xx tanpa 500; data QA251-DEV-* dihapus. 6 usulan kode bersama (PDF404=500, getTemplate id, selected-* 500, Excel '=' sebagai rumus, desimal PDF, footer jam 12) -> dicatat, diputuskan sesudah QA
- 2026-10-24 19:23 be-review: 0 Harus diperbaiki; Perlu dikonfirmasi (batch review): (1) pdfPreview `$request->all()` tanpa FormRequest, kunci payload tak dikenal ikut ke template; (2) GET select/pdf/xls membuat baris template/cds bila belum ada, tanpa penjaga race; (3) `Opname Document` hanya di permission_salesman.sql (pra-ada)
- 2026-10-24 19:23 qa-be r1 mulai
- 2026-10-24 20:43 qa-be r1: FAIL, 14 PASS / 1 FAIL / 0 MANUAL (AC-1..15); D-1 ED-1259 (xls hang, ReportHeader1 kolom 0); komentar ED-1259. Di luar item: 10 butir (qa-be.md § Di luar item), a.l. C-9 terbukti (guard PASS), Excel '=' rumus aktif, memori 128M vs 2048M (AC-10 lulus di mesin ini), ED-1028 scenario9 F-1 perlu diperbarui pemilik. Data QA dihapus, preset QA251-ROLE-* = 0
- 2026-10-24 20:45 be fix r1 mulai (D-1)
- 2026-10-24 20:59 be fix r1: D-1 selesai, hanya OpnameExportService.php (fallback template -> display setting -> config untuk PDF/XLS/template); kontrak §2/§5 + Catatan implementasi; suite QA dev-run 29/29 PASS; cds/template row kembali tidak ada
- 2026-10-24 21:00 qa-be r2 mulai
- 2026-10-24 21:20 qa-be r2: PASS 15/0/0, D-1 tertutup, regresi qa/ 32/32, ED-1028 4/4, ED-1250 2/2; 500 di kode bersama (#5 template rusak buatan tangan, #6 bahasa tak sah, #7 A-1) digolongkan di luar item oleh orkestrator -> tiket ai-found di akhir. Data QA 0 sisa
- 2026-10-24 21:21 fe mulai: api-contract-analyst
- 2026-10-24 21:31 api digest: `docs/specs/Archive/_api.md` #32-#43; Perlu dikonfirmasi 33-39 dijawab dari spec/kode BE di § Jawaban (orkestrator ED-1251): 33 tanpa tindakan FE (§9), 34/35 risiko -> batch review, 36 C-9 Gate 1, 37 diterima, 38/39 kode bersama -> tiket ai-found
- 2026-10-24 21:33 fe dev mulai (ui-plan Archive, ui-slice, ui-logic)
- 2026-10-24 22:24 fe dev: task 028 logic-done (13 berkas FE: komponen ArchiveOpnameExport, endpoints 11, DrawerPdf constant, Content/ResultDrawer, 12 test baru PASS); build staging 2x OK; full suite 4 gagal pra-ada + timeout beban (ulang runInBand PASS)
- 2026-10-24 22:25 convention-reviewer mulai
- 2026-10-24 22:27 fe review: 0 Harus diperbaiki; Perlu dikonfirmasi (batch review): nama kunci `getSelectArchiveOpnameReportPdf*` (ikut spec §5, beda urutan dari `getSelectReport<Modul>Pdf*`), tanpa entri displaySetting.constant (butir 33), tanpa usePermission (drawer sudah dijaga); catatan: komentar `archive.api.js:47` menyebut nama lama `archiveOpnamePdfEndpoints`
- 2026-10-24 22:28 qa-fe r1 mulai (qa_round direset ke 1 untuk tahap fe)
- 2026-10-25 00:03 qa-fe r1: PASS, AC-16..22 7 PASS, AC-23 MANUAL; build OK; unit: 4 gagal pra-ada + timeout beban (lulus sendiri); e2e 11/11 tanpa allow-list; regresi BE 32/32, ED-1028 4/4, ED-1250 2/2; F-2: ED-1028 e2e `histRows` -> `td:not(.p-0)` (13/13). Di luar item baru: DrawerCustomize pageerror Simpan cepat, kolom dicentang ulang ke urutan akhir
- 2026-10-25 00:08 qa F-1 (addendum, gate1 §3 pemilik 1251 AC-12): ED-1028 `scenario9-display-setting.php` asersi bersyarat baris Result; ED-1028 AC-1/12/13/28 PASS di keadaan tanpa dan dengan baris; cds/template dipulihkan 0/0, C-9 PASS, QA251-ROLE-* 0
- 2026-10-25 00:09 orkestrator: dok FE 028 § Daftar tes UI + butir 24 (AC-23 hitam-putih) dan catatan urutan kolom di butir 15 (saran QA FE)
- 2026-10-25 00:10 commit (long run, lokal): BE fba6a5883, FE 7ca714504; publish FE tidak dijalankan (dilakukan saat /v5-epic review); Jira ED-1251 label ai-review + komentar siap review; subtask tetap In Progress; temuan luar item Bug ED-1283 (ai-found)
