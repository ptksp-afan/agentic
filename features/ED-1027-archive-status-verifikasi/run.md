---
key: ED-1027
epic: ED-1022
title: Archive - Status verifikasi dokumen (kolom Document Verified, tab Verification, detail per transaction type)
slug: archive-status-verifikasi
phase: gate2
qa_round: 2
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents:
  analyst: {id: a88e4dd0d15979b40, at: 2026-10-06 16:36}
  be_dev: {id: ad8069ea3afc2709f, at: 2026-10-09 (rev, fix r2)}
  be_reviewer: {id: a2a3919e9b268a8ae, at: 2026-10-09 (rev, re-review r1b)}
  qa_be: {id: ad628a442b31579ec, at: 2026-10-09 (rev r2)}
  api_digest: {id: a396bd02c6a2da246, at: 2026-10-09 (rev)}
  fe_dev: {id: a49452f78735c64ad, at: 2026-10-10 (rev, fix r1)}
  fe_reviewer: {id: a7b2998ee79336645, at: 2026-10-09 (rev)}
  qa_fe: {id: a6cff5dff2d50b89e, at: 2026-10-10 08:28 (rev r1)}
---
## Status ringkas
**Revisi selesai, siap review batch (2026-10-10).** Commit lokal baru di atas HEAD lama (tanpa amend): BE 3ae86a5c1 (di atas cb2e78b44), FE 5a0c34be1 (di atas 5656a5e83), agentic = commit `[DOC] ED-1027 - revisi review: spec, QA, run log`. Tidak di-push.
QA BE r2 PASS (17 AC + 1 MANUAL AC-29 BE; qa/ 28 PASS + 1 SKIP), QA FE r2 PASS (13 AC + 1 MANUAL AC-21; D-1 tertutup), e2e 21 OK; galeri `work/e2e/ED-1027-archive-status-verifikasi-20261010-092655/`.
Jira: ED-1027 + subtask In Progress, label ai-review, komentar siap review. Done hanya lewat `/v5-epic review`.
Untuk review: § Catatan dev BE / Review BE / FE (baris (rev)); PK convention review BulkMoveModalForm:63 tanpa test sendiri; skenario usang ED-1028 tercatat di qa-be.md & qa-fe.md (pemilik QA 1028).

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|
| ED-1033 | [BE] Updater - kolom Document Verified di display setting Archive | BE | In Progress |
| ED-1039 | [BE] Hitungan verifikasi di list Archive (kolom per baris + ringkasan All Archive) | BE | In Progress |
| ED-1045 | [BE] Endpoint detail verifikasi All Archive & per folder + 3 sesi terakhir | BE | In Progress |
| ED-1051 | [FE] Archive - kolom Document Verified & ringkasan header | FE | In Progress |
| ED-1057 | [FE] Archive - modal Detail verifikasi per Transaction Type | FE | In Progress |
| ED-1061 | [FE] Archive - tab Verification di drawer folder | FE | In Progress |
| ED-1065 | [QA] Skenario status verifikasi dokumen | QA | In Progress |
| ED-1095 | [BE] Verifikasi hanya untuk permission Opname Document (rev) | BE | In Progress |
| ED-1096 | [BE] Display setting - peta saringan per permission (rev) | BE | In Progress |
| ED-1097 | [FE] Archive - klik Document Verified folder membuka modal verifikasi folder (rev) | FE | In Progress |
| ED-1098 | [FE] Archive - sembunyikan tampilan verifikasi tanpa permission Opname Document (rev) | FE | In Progress |
| ED-1099 | [QA] Skenario revisi status verifikasi + regresi ED-1026/ED-1028 (rev) | QA | In Progress |

## Keputusan developer
- G1 (gabungan ED-1022, 19:24): semua rekomendasi diterima - EPIC K-1 a, EPIC K-2 b, grup G-1..G-7 (epics/ED-1022-gate1.md §6), K-n lain = rekomendasi analyst; jawaban tertulis di spec § Keputusan
- 2026-10-09 (developer): penolakan classifier #2 di QA BE r1 (grep `contract.md` ED-1026 ditolak, lalu dibaca lewat Read) **tidak masalah - memang diinginkan**. Penolakan #1 (grep QA_USER di `secrets.env`) ditolak dengan benar dan tidak diulang.

## Catatan dev BE (untuk review batch)
- Perlu dikonfirmasi dev: folder_verified = archive_opname_folders.verified_count (bukan verified_after_count); "mencakup F" memakai pohon folder sekarang (bukan snapshot); ARCHIVE417 (teks "Opname ...") dipakai ulang untuk verifications/{id dokumen}; GET archives +100-120 ms (agregat 34,5 rb dokumen); sesi terlihat di overview bisa belum bisa dibuka (GET opnames/{id} 03 hanya pemilik/superadmin sampai 05)
- Konvensi: sortAll 500 bila sort dikirim sebagai objek (perilaku lama)

## Review BE - Perlu dikonfirmasi (untuk review batch)
- VerificationService:339 ARCHIVE417 (teks opname) dipakai ulang untuk verifications/{id dokumen} (sesuai spec BR-16, gate1 O-3)
- ArchiveService:45-47 context()+counts() di setiap GET archives: agregat seluruh dokumen aktif tanpa paginasi (sesuai desain spec, D-3)
- (rev) index display-columns + body PUT tidak disaring = ED-1028 §9; hasPermission vs middleware pola lama; body current_columns non-array perilaku lama
- (rev re-review r1b) ArchiveService:227-253 withoutVerificationSearch: tanpa Opname Document, filter verifikasi dibuang diam-diam (tanpa 403/400), sesuai maksud r1

## FE - keputusan dev sendiri (untuk review batch)
- Ringkasan disembunyikan di layar akses ditolak & bila respons tanpa documentVerifiedSummary; tautan hanya bila onOpenDetail; baris related transaction: sel kosong (BE tidak mengirim)
- Baris Total dari header (jawaban digest #12); gagal muat = toast + Empty (tanpa 0/0 palsu); modal destroyOnClose (1 request per buka); tab Verification lazy (tanpa forceRender), tetap ada saat drawer read-only
- (rev) FE review Perlu dikonfirmasi: ArchiveBulkMoveModalForm.js:63 tanpa test sendiri (diuji lewat ArchivePage test Bulk Move)
- FE review Perlu dikonfirmasi: ArchiveVerification/index.js:63,72 Col flex none/auto di kartu angka (bukan formLayout); catatan: ArchiveMoveModal test baris ~66 diformat ulang (noise diff)
- Terjemahan id-ID istilah desain (Dokumen Terverifikasi, Riwayat Opname, Lihat semua riwayat, tab Verifikasi, ...) - mohon dinilai
- (rev) Bulk Move konfirmasi: kolom Document Verified teks biasa (tanpa tautan); subjudul modal folder = row.name; onClose modal tidak mengosongkan folder (tanpa GET ekstra saat animasi); tanpa Opname Document + tanpa Folder Permissions drawer tanpa Tabs (perilaku lama); renderDocumentVerifiedCell dipisah (argumen ke-3 antd = index)
- (rev fix r1) risiko laten di luar diff: ArchiveDrawer/index.js:71 (ED-1028, sudah di-commit) jatuh ke `record?.name` mentah bila respons detail tanpa `name` (detail `name` = string, jadi belum terjadi)
- Beda dari desain: ringkasan satu baris Space tanpa bingkai, progress warna tema, angka folder teks biasa (K-7), ikon CheckCircleFilled/CloseCircleOutlined + tooltip, Card small + Title, DefaultTable bold-row Total, tanggal "Agt" (moment)

## Usulan pipeline
- Spec e2e ED-1025 punya 3 asersi usang (jumlah tab drawer 2 -> 3 karena ED-1027; menu "+" 4 -> 5 karena ED-1026); longgarkan (qa-fe O-2)
- (FE dev) getOpnamePercent dipakai di luar opname -> nama umum; ScopeTagText lokal ArchiveOpnameModal -> archive.function.js; suite berat timeout di full suite paralel (testTimeout belum ada preseden)
- Skenario QA ED-1026 AC-1 punya asersi usang yang sama dengan ED-1025 AC-1 (Updater item = kunci terbesar config.php); longgarkan jadi "terdaftar"
- (QA FE rev r2, Q-1) harness e2e `scripts/e2e/playwright.config.js` default `fullyParallel: true` + `workers: 2`: spec berstate bersama (Display Setting global, grant role, Confirm Opname) gagal acak; ED-1027 memakai kunci lintas-proses `takeStateLock`; item lain dengan pola sama (ED-1025/1028/1029) perlu `--workers 1` atau kunci serupa; smoke "Archive B" harness bisa tumpang tindih dengan grant sementara bila paralel

## Log
- 2026-10-06 16:09 start: baseline BE 42f3e07cb (0 berkas kotor), FE 6afd19054 (0 berkas kotor)
- 2026-10-06 16:36 ba: selesai, 16 BR / 21 AC / 7 subtask / 8 K (spec.md, contract.md); akses DB ditolak classifier
- 2026-10-06 19:50 gate1: disetujui (gabungan); Jira ED-1027 + 7 subtask dibuat, sprint 46
- 2026-10-07 07:03 long-run: mulai fase be; preflight OK; rebaseline BE 20c4f0f19 / FE fc35d3fdd (0 kotor) karena HEAD bergeser oleh commit ED-1024..ED-1026; ED-1027 + 7 subtask -> In Progress
- 2026-10-07 07:18 be: v5-be-dev (a3666f53662f3b23c, opus, fallback general-purpose) 3/3 subtask selesai; 4 M + 3 baru di BE; Updater dijalankan di api_sidomaju (idempoten); smoke 2/2 PASS (96 asersi) di folder sekali-pakai, data dipulihkan, folder dihapus; kontrak + spec Catatan implementasi diperbarui (klarifikasi, 0 field berubah)
- 2026-10-07 07:23 be-review: v5-be-reviewer (acd9fa9cc542a18fe, sonnet, fallback general-purpose) Harus diperbaiki 0, Perlu dikonfirmasi 2; -> QA BE r1
- 2026-10-07 08:00 qa-be r1: v5-qa (aaa29755d556344cb, sonnet) PASS 12/12 AC BE, skenario 20 PASS/0 FAIL/1 SKIP (isolasi tenant, QA_DBS 1 DB); 0 defect; O-1 ARCHIVE417 teks opname, O-2 500 lama sorts/pagination (ED-1070), O-3 updater_logs; regresi ED-1024 26/26, ED-1025 30/31, ED-1026 36/37 (keduanya AC-1 asersi usang); DB dipulihkan; -> fe
- 2026-10-07 08:07 fe digest: api-contract-analyst (aa090f1f31dfd98d6, sonnet, fallback) ksp-react docs/specs/Archive/_api.md: +2 endpoint (#20-#21), 1 berubah (#1 documentVerified + summary), 16 jawaban dari kode BE; 4 Perlu dikonfirmasi (9-12) dijawab orkestrator dari spec (BR-16, BR-10, K-5 a, BR-8: Total dari header); 0 blocker
- 2026-10-07 08:54 fe: FE dev (a72955d6fe7d55bc2, general-purpose) ui-plan/slice/logic -> task 007-009 (ED-1051/1057/1061) logic-done; 13 M + 8 baru (+ _api.md orkestrator); eslint 0, build staging OK x6, test Archive+Raptor runInBand 220/220, full suite 1491/1511 (4 suite baseline + 16 timeout beban, lulus runInBand); 0 BLOCKED; -> convention-reviewer
- 2026-10-07 08:58 fe-review: convention-reviewer (ada5b8d1c63ba3e52, sonnet, fallback) Harus diperbaiki 0, Perlu dikonfirmasi 1 (Col flex lokal), 4 catatan ringan; -> QA FE r1
- 2026-10-07 09:03 instruksi developer (loop epic): berhenti sesudah QA FE r1, tanpa ronde perbaikan; PASS -> lanjut normal
- 2026-10-07 10:12 qa-fe r1: v5-qa (a4c0dcba81b358111, sonnet) PASS 8 AC FE / MANUAL 1 (AC-21 tampilan vs desain); build staging OK, test Archive 190/190, full suite 26 gagal = 4 baseline + 22 timeout beban (Archive+Raptor runInBand 411/411); e2e 13/13 (default, user2); regresi BE ED-1027 20/0/1 SKIP, ED-1024 26/26, ED-1025 30/31, ED-1026 36/37 (AC-1 usang); O-1 kolom kanan tabel modal/drawer lewat gulir horizontal; DB dipulihkan; 0 perintah ditolak
- 2026-10-07 10:15 commit: BE 49be126c7 (7 berkas NEW), FE 48bbad16e (22 berkas NEW), agentic d9a2e04; Jira ED-1027 label ai-review + komentar siap review; Gate 2 long run = auto-commit lokal; phase: gate2 (menunggu review batch)
- 2026-10-08 09:25 review batch: Minta perbaikan → phase: ba (BA revisi menunggu instruksi developer); feedback di epics/ED-1022-review-feedback.md § ED-1027; label ai-review dilepas
- 2026-10-08 13:30 ba (rev): spec + contract diperbarui untuk FB-1 (modal verifikasi folder, pakai ulang GET verifications/{id}), FB-8b (tanpa Opname Document: tanpa tampilan verifikasi; K-9 server ikut menahan), imbas ED-1026 rev (K-14 scope, Semua Arsip, helper QA POST opnames); 19 BR (3 baru) / 29 AC (8 baru) / 5 subtask baru (rev) / 1 K baru (K-9); tanpa tabel, permission, Updater baru; status draft
- 2026-10-08 15:30 gate1 (rev): jawaban diterapkan - K-9 a (EPIC K-3 a), O-3 peta saringan entri kolom + seluruh modul, C-7, scope tanpa first_name/other_count, Opname Document 1139, QA §5; 19 BR / 29 AC / 5 subtask (rev); status draft
- 2026-10-08 15:37 gate1 (rev) Jira: subtask baru ED-1095 [BE] verifikasi per permission · ED-1096 [BE] peta saringan display setting · ED-1097 [FE] klik Document Verified folder · ED-1098 [FE] sembunyikan verifikasi · ED-1099 [QA] (sprint 46, To Do; story tetap In Progress)
- 2026-10-08 gate1 ronde 2 disetujui developer (epics/ED-1022-gate1.md §9b); spec status approved; phase: be (long run)
- 2026-10-09 long-run (rev): mulai fase be; preflight OK; rebaseline BE cb2e78b44 / FE 5656a5e83 (0 kotor); ED-1095..ED-1099 -> In Progress
- 2026-10-09 be (rev): v5-be-dev (a2dc206dab4669e6e, opus, fallback general-purpose) ED-1095 + ED-1096 selesai; 4 M di BE (route verifications -> Opname Document, ArchiveService::index tanpa kolom/angka, ColumnDisplaySettingService PERMISSION_FILTERS); smoke 3/3 PASS (239 asersi) di scratch, role QA + baris display setting dipulihkan persis, scratch dihapus; kontrak §4 + Catatan implementasi klarifikasi (0 field); Perlu dikonfirmasi 3 (pasangan role AC-22 beda manage_permission, index display-columns mentah = ED-1028, PUT seluruh modul tanpa baris 500 lama)
- 2026-10-09 be-review (rev): v5-be-reviewer (a2a3919e9b268a8ae, sonnet, fallback) Harus diperbaiki 0, Perlu dikonfirmasi 5; orkestrator ambil 2 (bocor via search/sort is_verified dkk. = K-9 a/BR-19; literal ganda) -> fix r1; sisanya untuk review batch (index display-columns + body PUT = ED-1028 §9; hasPermission vs middleware pola lama; body non-array perilaku lama)
- 2026-10-09 be fix r1/r1b (rev): v5-be-dev (a2dc206dab4669e6e) search/sort kolom verifikasi dibuang tanpa Opname Document; GET archives/{id} tanpa is_verified/verified_at/verified_by/id_archive_opname bila tanpa permission (FE tidak memakai key itu); peta pakai konstanta VerificationService; smoke SMK-4 34 + SMK-5 24 asersi PASS; user_roles dipulihkan persis, scratch dihapus; kontrak §1/§1b + Catatan implementasi klarifikasi
- 2026-10-09 jeda: runner diminta mengembalikan laporan sebelum re-review delta selesai; kode BE tidak di-commit; lanjut: re-review delta -> qa-be
- 2026-10-09 07:09 be-review delta r1/r1b (rev): v5-be-reviewer (a2a3919e9b268a8ae; laporan sampai ke epic loop sesudah runner lama berhenti, scratchpad ED-1027-rereview-r1b.md) Harus diperbaiki 0, Perlu dikonfirmasi 1 (withoutVerificationSearch membuang filter diam-diam, sesuai r1); butir lama 2/3/5 tidak dihitung ulang; -> qa-be r1 (rev)
- 2026-10-09 qa-be r1 (rev): v5-qa (ad642fb9e3dcc0a81, sonnet) FAIL - AC 16 PASS / 1 FAIL (AC-24) / 1 MANUAL (AC-29 BE); run qa/ 26 PASS / 1 FAIL (X-12) / 1 SKIP (X-3); D-1 sedang ED-1096 (saringan display setting lolos lewat huruf besar/kecil/spasi akhir nama modul), D-2 rendah ED-1095 (komentar "id 1139" -> ED-1025 AC-25 gagal); D-3 500 pra-ada PUT display-columns detik sama -> Bug ED-1122 (ai-found); D-4 500 pra-ada sorts array = ED-1070; regresi ED-1024 26/26, ED-1026 39/39, ED-1029 29+1 SKIP, ED-1025 39/40 (D-2), ED-1028 2/20 usang (dicatat); skenario usang milik item ini diperbaiki (scenario1 AC-1/X-1 updater_logs 197, qa_lib stateless + tahan data developer, scenario3/5/7/8) + baru scenario9/10; data dipulihkan (17 tabel sidik jari sama); 2 penolakan classifier (Credential Materialization) - satu dibaca ulang lewat Read (kontrak non-rahasia), dicatat untuk dinilai developer; komentar defect di ED-1096/ED-1095
- 2026-10-09 be fix r2 (rev): v5-be-dev (ad8069ea3afc2709f, opus, fallback general-purpose) D-1 ColumnDisplaySettingService permissionFilter() (module_name baris + strcasecmp/rtrim, semua entri peta), D-2 komentar tanpa id; php -l OK; smoke 43 asersi PASS (scratch dihapus, user_roles + column_display_settings md5 sama); kontrak/spec tidak berubah; -> qa-be r2
- 2026-10-09 qa-be r2 (rev): v5-qa (ad628a442b31579ec, sonnet) PASS - AC 17 PASS / 0 FAIL / 1 MANUAL (AC-29 BE: skenario ED-1028 usang); D-1/D-2 tertutup; run qa/ 28 PASS / 1 SKIP (X-3); scenario10 X-12 diperluas 6 varian + X-14 baru; regresi ED-1024 26/26, ED-1025 40/40, ED-1026 39/39, ED-1029 29+1 SKIP, ED-1028 2/20 usang (dicatat); 500 pra-ada D-3 (ED-1122) + D-4 (ED-1070) saja; data dipulihkan (17 tabel identik); 0 penolakan; -> fe
- 2026-10-09 fe digest (rev): api-contract-analyst (a396bd02c6a2da246, sonnet, fallback) ksp-react docs/specs/Archive/_api.md pass kesembilan: #20/#21 -> Opname Document, #1/#2 tanpa key verifikasi bagi non-pemegang, baru #30/#31 display-columns; Perlu dikonfirmasi 26-29 dijawab dari kode BE (customer lolos permission_v5 tapi hasPermission berbasis role; index display-columns = ED-1028; entri seluruh modul menunggu ED-1028; PUT detik sama = ED-1122); 0 blocker
- 2026-10-09 fe (rev): FE dev (a98d23cb8f2050727, general-purpose) ui-plan/slice/logic -> task 019 (ED-1097) + 020 (ED-1098) logic-done; unit test modal "Tutup" diperbarui ke modal tanpa footer (ikon Close); eslint 0, build staging OK x2, test Archive runInBand 351/351 (+Drawer/Page 56/56), full suite 21 gagal = 4 suite baseline + timeout beban (runInBand 564/565, sisa 1 lulus sendiri); 0 BLOCKED; 0 penolakan; -> convention-reviewer
- 2026-10-09 fe-review (rev): convention-reviewer (a7b2998ee79336645, sonnet, fallback) Harus diperbaiki 0, Perlu dikonfirmasi 1 (BulkMoveModalForm tanpa test sendiri), catatan 4 (_module.md:144 renderDocumentVerified basi, :89/:111 belum sebut Button link + penjaga tab, querySelector pola lama, klaim build tidak diverifikasi); -> qa-fe
- 2026-10-09 jeda (permintaan developer lewat epic loop): berhenti sesudah convention review; temuan tidak ditindaklanjuti; kode BE + FE tidak di-commit; lanjut: qa-fe r1 (rev) -> auto-commit
- 2026-10-10 07:24 lanjut (long run rev): preflight OK; kode BE 4 M / FE 11 M + 2 task baru utuh di atas cb2e78b44 / 5656a5e83; tindak lanjut convention review: _module.md :89/:111/:144 dirapikan FE dev (docs saja), PK BulkMoveModalForm:63 -> review batch (tanpa ronde perbaikan)
- 2026-10-10 07:25 fe docs (rev): FE dev (af96c6e7ab4867890, general-purpose) _module.md :89-91/:111/:144-145 diselaraskan dengan kode (withDocumentVerifiedRender/renderDocumentVerifiedCell, Button link V/T, tab Verification hanya dengan Opname Document); docs saja, FE tetap 11 M + 2 baru; -> qa-fe r1 (rev)
- 2026-10-10 07:25 qa-fe r1 (rev): v5-qa (sonnet) dimulai; changed.txt 17 baris (BE 4 M, FE 11 M + 2 baru)
- 2026-10-10 08:28 qa-fe r1 (rev): v5-qa (a6cff5dff2d50b89e, sonnet) FAIL - AC 9 PASS / 2 FAIL (AC-26, AC-27 klik) / 1 MANUAL (AC-21) / 2 sebagian tidak bisa diverifikasi (AC-28/29 cabang modal folder); D-1 tinggi ED-1097: klik V/T folder crash (name folder dari GET archives = larik, diteruskan mentah ke subjudul modal; ArchivePage/index.js:325-327, ArchiveVerificationModal/index.js:54-56); build staging OK, unit Archive 356/357 (1 = flake ED-1025 C-1, gagal juga di HEAD bersih, sudah tercatat di ED-1025 run.md - tanpa tiket baru), full suite 1673/1678 (4 baseline + 1 timeout beban); e2e 15 OK / 5 GAGAL (semua D-1), galeri work/e2e/ED-1027-archive-status-verifikasi-20261010-081829/; e2e usang milik item ini diperbarui (ED-1026 :327/:618/:744/:1028/:685/:1065/:1203/:1226-1261, ED-1029 oracle CTA) + test baru AC-25/26/27/29, fixture tanpa sesi draft; regresi BE ED-1027 28/0/1 SKIP, ED-1024 26/26, ED-1025 40/40, ED-1026 39/39, ED-1029 29+1 SKIP, ED-1028 2/20 usang (dicatat untuk QA ED-1028: q28_session alur draft, scenario1-list.php:177-189, e2e); 500 pra-ada ED-1122/ED-1070 saja; DB identik (17 tabel, updater_logs 197, grant sementara role 5 dicabut); 0 penolakan; komentar defect di ED-1097; -> fe fix r1
- 2026-10-10 08:35 fe fix r1 (rev): FE dev (a49452f78735c64ad, general-purpose) D-1: ArchivePage/index.js:324-330 label folder = pola inline :241/:360 (Array.isArray(name) ? name[0].transactionNo : name) untuk subjudul modal + CTA riwayat; ArchiveVerificationModal JSDoc; test ArchivePage folder name larik (listName) + test baru CTA modal folder; task 019 Catatan; eslint 0, Archive runInBand 358/358, build staging OK; tanpa smoke/DB; FE tetap 11 M + 2 baru; -> qa-fe r2
- 2026-10-10 09:35 qa-fe r2 (rev): v5-qa (a520d6933b646645c, sonnet) PASS - D-1 tertutup (API asli, tanpa ubah respons); AC 13 PASS / 0 FAIL / 1 MANUAL (AC-21); build staging OK; unit Archive 357/358 (1 = flake ED-1025 C-1), full suite 1673/1679 (4 baseline + 2 flake beban, lulus sendiri); e2e 21 OK / 0 GAGAL (default + user2), galeri work/e2e/ED-1027-archive-status-verifikasi-20261010-092655/; Q-1 e2e paralel (state bersama) diperbaiki di qa/e2e item ini (takeStateLock) + test jalur error nyata, fixture set-folder-active -> usulan pipeline; regresi BE ED-1027 28/0/1 SKIP, ED-1024 26/26, ED-1025 40/40, ED-1026 39/39, ED-1029 29+1 SKIP, ED-1028 2/20 usang (dicatat); 500 pra-ada ED-1122/ED-1070/ED-1071 saja; DB identik (17 tabel), grant sementara dicabut; 0 penolakan; tes UI manual rev: 019 (17) + 020 (8) butir
- 2026-10-10 09:35 commit (rev): BE 3ae86a5c1 (4 berkas NEW), FE 5a0c34be1 (13 berkas NEW), agentic = commit ini; Jira ED-1027 label ai-review + komentar siap review; phase: gate2 (menunggu review batch)
