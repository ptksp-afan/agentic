---
key: ED-1026
epic: ED-1022
title: Archive - Opname Document per folder (pilih folder, scan, konfirmasi)
slug: archive-opname-document
phase: gate2
qa_round: 1
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents:
  analyst: {id: a2f048b007b06c2dd, at: 2026-10-06 16:42}
  be_dev: {id: a149babf5cdf8586a, at: 2026-10-09 rev fix r1}
  be_reviewer: {id: aeec83134c457ad54, at: 2026-10-09 rev}
  qa_be: {id: ab5903699e0f6efba, at: 2026-10-09 rev r2}
  api_digest: {id: a65c15168c7f4604e, at: 2026-10-09 rev}
  qa_fe: {id: af23c1c372884327b, at: 2026-10-09 rev r1}
  fe_dev: {id: ad037f4a43d8cd894, at: 2026-10-09 rev}
---
## Status ringkas
**Revisi selesai, siap review batch (2026-10-09).** Commit lokal baru di atas HEAD lama (tanpa amend): BE cb2e78b44, FE 5656a5e83, agentic = commit `[DOC] ED-1026 - revisi review: spec, QA, run log`. Tidak di-push.
QA BE r2 PASS (23 AC, 39/39 skenario), QA FE r1 PASS (13 + 1 MANUAL AC-3 lisensi), e2e 15 OK; galeri `work/e2e/ED-1026-archive-opname-document-20261009-055322/`.
Jira: ED-1026 + subtask In Progress, label ai-review, komentar siap review. Done hanya lewat `/v5-epic review`.
Untuk review: § Catatan dev BE / Review BE / FE (baris (rev)); skenario usang ED-1027/ED-1028 tercatat di qa-be.md & qa-fe.md (pemilik QA 1027/1028).

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|
| ED-1032 | [BE] Archive - skema & seed opname (Updater, model, permission `Opname Document`, kode pesan) | BE | In Progress |
| ED-1038 | [BE] Archive - Step 1 & sesi draft (folders, store, update, show, cancel) | BE | In Progress |
| ED-1044 | [BE] Archive - scan & hasil sesi (scan, documents) | BE | In Progress |
| ED-1050 | [BE] Archive - konfirmasi opname (timpa/lanjut, snapshot, bentrok) | BE | In Progress |
| ED-1056 | [FE] Archive - menu Opname (+ & menu baris), permission, endpoint, locale | FE | In Progress |
| ED-1060 | [FE] Archive - modal Opname Step 1 Select Folder | FE | In Progress |
| ED-1064 | [FE] Archive - modal Opname Step 2 scan (input, kamera, USB, Segmented, gaya baris) | FE | In Progress |
| ED-1067 | [FE] Archive - modal Opname Step 3 Confirm (`ArchiveOpnameResult`) | FE | In Progress |
| ED-1068 | [QA] Skenario opname per folder (lokasi, lanjut/timpa, bentrok, lisensi) | QA | In Progress |
| ED-1088 | (rev) [BE] opname tanpa draft: scan & pratinjau tanpa simpan, hapus rute draft/batal, scope | BE | In Progress |
| ED-1089 | (rev) [BE] Konfirmasi Opname sekali kirim | BE | In Progress |
| ED-1090 | (rev) [BE] Updater pembersih sesi draft/batal, hapus kode pesan lama | BE | In Progress |
| ED-1091 | (rev) [FE] modal Opname tanpa draft | FE | In Progress |
| ED-1092 | (rev) [FE] filter hasil CheckableTag | FE | In Progress |
| ED-1093 | (rev) [FE] Steps, Scope helper, terjemahan "All Archive", tanpa permission | FE | In Progress |
| ED-1094 | (rev) [QA] skenario tanpa draft + skenario usang | QA | In Progress |

## Keputusan developer
- G1 (gabungan ED-1022, 19:24): semua rekomendasi diterima - EPIC K-1 a, EPIC K-2 b, grup G-1..G-7 (epics/ED-1022-gate1.md §6), K-n lain = rekomendasi analyst; jawaban tertulis di spec § Keputusan
- G1 ronde 2 (revisi, 2026-10-08): FB-2/3/8a/10/11, N-4 + EPIC K-3 a (baca sesi = ED-1028, Opname Document), EPIC K-4 a (Opname Document 1139, Folder Permissions 1140), ED-1028 K-10 b, K-12..K-16 a; ED-1070/1071 ditunda; detail spec § "Gate 1 ronde 2"

## Catatan dev BE (untuk review batch)
- (rev) Updater DeleteArchiveOpnameDraft dijalankan langsung (bukan runner UpdateVersion; ada Version5Procedure tertunda di DB ini) -> runner resmi menjalankannya sekali lagi, idempoten
- (rev) dedup kode scan di PHP dengan mb_strtolower (meniru kolasi); is_continue dinilai saat request; selected_at > sekarang -> 400 ARCHIVE415 (teks "dimulai di hari lain" kurang pas untuk jam masa depan hari ini); scans.*.scanned_at masa depan hari ini diterima (K-15 a)
- (rev) ARCHIVE414 bila beberapa folder bentrok: [0] = folder pertama menurut confirmed_at (perilaku lama)
- `newIds()` lokal di OpnameService (satu generateId + urutan; generateId per baris = 1 query/baris, ~3% tabrakan pada 24 rb baris); usul helper bersama di MyHelper
- label status/result = konstanta en/id di model (pola DocumentArchiveService::action()); BR-6 dipakai `is_active = 1` (list memakai `> 0`, identik pada data kini)
- Confirm tidak menyentuh archives.updated_at/updated_by/history (opname bukan edit dokumen); scope.folder_name dari snapshot folder level 0

## Review BE - Perlu dikonfirmasi (untuk review batch)
- (rev) `result`/`page`/`pagination` preview/documents divalidasi inline di OpnameController:61-67 (sama dengan documents()); RESULT_FILTERS di dua tempat
- (rev) aturan pilihan Step 1 + authorize() disalin identik di ScanRequest/PreviewRequest/StoreRequest (tanpa contoh pewarisan FormRequest di v5)
- (rev) GET opnames, {id}, {id}/documents masih `List Archive` (api.php:44,49,50) - sesuai spec: pindah ke Opname Document = ED-1028
- OpnameController folders()/documents() memakai `$request->validate()` inline (pola hanya di controller Select)
- authorize() di Confirm/Scan/Update/StoreRequest melempar 404 (ARCHIVE412/400) dan 400 (ARCHIVE413/415/417); 400 dari authorize() = perluasan baru (service memeriksa ulang)
- scan mengembalikan row + counts (bukan hanya id) - sengaja untuk FE, tercatat di kontrak
- OpnameService::newIds() id massal dari satu generateId() (menyimpang dari satu generateId per baris)
- (orkestrator, digest #8) kolom Folder Step 2/3 untuk dokumen root (verified/unscanned, folderName null) = "All Archive" (BR-3/BR-15/K-4, OpnameService:1087); not_found "Di luar scope opname", invalid "—"
- StoreRequest::authorize() memakai Validator::make atas rules() sendiri (fix D-1; belum ada contoh di Modules/V5)
- OpnameService:1086 fallback 'All Archive' hardcode Inggris di parameter ARCHIVE414 (muncul juga di id_ID)

## FE - keputusan dev sendiri (untuk review batch)
- (rev, review) ArchiveOpnameFilter baru tanpa index.test.js sendiri (teruji lewat Result/History/Modal); render tag Scope `tag.id ? FormattedMessage : text` di 3 tempat (Modal:33, archive.function.js:953, ResultDrawer.js:19)
- (rev) request scan & klasifikasi ulang di ArchiveOpnameModal/index.js (bukan ArchiveOpnameScan.js); respons modal yang sudah ditutup diabaikan lewat nomor sesi; ARCHIVE415 saat Konfirmasi = toast, tetap Step 3
- (rev) tag Step 2-3 dari pilihan lokal; Steps max-width 803px (dari .steps-container yang ada); gaya CheckableTag bawaan antd; id "Semua Arsip" ikut mengubah judul modal ED-1027 & drawer ED-1028 (BR-28)
- (rev) test ED-1027/1028 disesuaikan hanya untuk scope baru / "Semua Arsip" / selector CheckableTag (O-8)
- FE review Perlu dikonfirmasi: twoThirdColumn lokal (ArchiveOpnameScan.js:31) bukan formLayout; Col span/flex untuk kartu angka (Scan.js:243, Confirm.js:32); locale archive.verified vs archive.Verified hampir kembar
- GET opnames/folders gagal -> modal ditutup, sesi yang ada dibatalkan; POST/PUT/DELETE sesi tanpa toast sukses (hanya confirm)
- sesudah ARCHIVE414 pilihan folder dipertahankan saat Step 1 dimuat ulang; label hasil id = resultLabel BE
- Beda dari desain: Card/Segmented/Tag/Switch/Alert (EQUAL v5); baris folder opname sendiri diberi teks "Dokumen yang langsung berada di folder ini"

## Usulan pipeline
- (rev) Jest penuh di mesin 4 CPU: `--maxWorkers=2` di knowledge/agent QA (4 worker = timeout palsu)
- (rev) Skenario ED-1027 X-1 menimpa `updater_logs` id 197 (executed_at/execution_time) tiap run; QA 1026 memulihkannya 3x - perbaiki di QA ED-1027
- (rev) spec-format.md: butir Daftar tes UI usang "dibuang" vs revisi yang menggantikan task lama -> aturan "coret + digantikan NNN"; katalog komponen FE usang (4 entri)
- (FE dev) ModalAdd menimpa className; useBarcodeScanner mengabaikan semua INPUT (termasuk radio Segmented); NumberLocale crash tanpa app.user.number; formLayout.twoThirdColumn; testTimeout/maxWorkers full suite
- Skenario QA ED-1025 AC-1 mengasersikan Updater ED-1025 = kunci terbesar di Updaters/config.php: pasti gagal sesudah Updater fitur berikutnya; longgarkan jadi "terdaftar"
- Smoke dev di scratchpad bersama (smoke-<KEY>/qa/) ikut jalan lagi di sesi berikut dan mengubah data QA: smoke dev sebaiknya di folder sekali-pakai + cleanup wajib

## Log
- 2026-10-06..08 (diringkas): BA 27 BR/27 AC/9 subtask, Gate 1 gabungan; long run asli be (2 fix ronde QA BE: D-1 id 31 char, D-2 urutan scan) -> fe -> QA FE r1 PASS; commit BE 20c4f0f19, FE fc35d3fdd, agentic f5a84d3; review batch 2026-10-08 09:25 minta perbaikan (FB-2/3/8a/10/11, N-4); BA revisi + Gate 1 ronde 2 (EPIC K-3 a, K-4 a, K-12..K-16 a); subtask rev ED-1088..ED-1094 (sprint 46); insiden lama: penggantian username via python sesudah sed ditolak (2026-10-07)
- 2026-10-09 long-run revisi: preflight OK; rebaseline BE 33f1feeb3 / FE 442ae9851 (0 kotor); ED-1088/1089/1090 -> In Progress; mulai be
- 2026-10-09 be (rev): v5-be-dev (af87b5d3196e2beef, opus, fallback general-purpose) ED-1088/1089/1090 selesai; 10 M/D + 2 baru di BE; Updater DeleteArchiveOpnameDraft (kunci 1791512946) dijalankan langsung 2x di api_sidomaju (hanya menghapus sesi palsu smoke; data developer 0 sesi 1/3); smoke 13/13 PASS di scratchpad sekali pakai (dihapus, data SMK26R- dibuang); kontrak §5 + 2 Catatan implementasi; GET opnames* tetap List Archive (ED-1028); -> be-review
- 2026-10-09 be-review (rev): v5-be-reviewer (aeec83134c457ad54, sonnet, fallback) Harus diperbaiki 0, Perlu dikonfirmasi 3 (dicatat); -> QA BE r1 (rev)
- 2026-10-09 qa-be r1 (rev): v5-qa (a073f115d5c150de6, sonnet) FAIL 22 PASS / 1 FAIL (AC-22) / 0 MANUAL; skenario 38/39; D-1 (ED-1089, selected_at masa depan -> kirim ulang = 2 sesi); qa_lib sweep/baseline/cleanup kini aman dengan data developer (diuji dgn data simulasi QADEVSIM sendiri, dihapus); regresi ED-1024 26/26, ED-1025 40/40, ED-1029 29+1 SKIP, ED-1027 12/8 gagal + ED-1028 2/20 gagal (usang: draft dihapus, kunci Updater; dicatat); data: 1 sesi yatim QA dihapus, updater_logs 197 executed_at dipulihkan; komentar D-1 di ED-1089; -> be-fix r1
- 2026-10-09 be-fix r1 (rev): v5-be-dev fresh (a149babf5cdf8586a, opus, fallback) D-1: assertToday menolak selected_at > sekarang (400 ARCHIVE415), acuan bentrok = selected_at; EXTRA-FUTURE PASS, run penuh 39/39; kontrak §5 + Catatan implementasi dikoreksi; -> QA BE r2
- 2026-10-09 qa-be r2 (rev): v5-qa (ab5903699e0f6efba, sonnet) PASS 23/0/0; skenario 39/39 (EXTRA-FUTURE diperkuat); D-1 tertutup; regresi ED-1024 26/26, ED-1025 40/40, ED-1029 29+1 SKIP, ED-1027 12/8 + ED-1028 2/20 (usang sama, milik QA 1027/1028); data: 0 hapus, updater_logs 197 dipulihkan lagi (ditimpa ED-1027 X-1); catatan dev: scanned_at masa depan hari ini diterima, teks ARCHIVE415; -> fe (digest)
- 2026-10-09 fe digest (rev): api-contract-analyst (a65c15168c7f4604e, sonnet, fallback) ksp-react docs/specs/Archive/_api.md: +3 endpoint (#27-#29), 4 diubah, 4 ditandai dihapus, scope baru di #20-#22; Perlu dikonfirmasi 3 dijawab orkestrator dari spec: (1) baca sesi masih List Archive = route ED-1028 (EPIC K-3 a), FE guard opname pakai Opname Document; (2) folders/warnings disaring = ED-1028 BR-16, FE tidak merendernya; (3) tanpa batas jumlah scans, FE tidak membatasi; 0 blocker; -> FE dev
- 2026-10-09 fe (rev): FE dev (ad037f4a43d8cd894) task 016/017/018 (ED-1091/1092/1093) logic-done; FE 23 M + 4 baru (termasuk _api.md digest); eslint 0, build staging OK x4, test Archive 336/338 (ArchiveVerificationModal "Tutup" usang sejak 7fa63869d milik ED-1027 + tooltip ArchivePage flaky, lulus sendiri), full suite maxWorkers=2 1652/1659 (5 suite di luar Archive); 0 BLOCKED; tes UI 18+10+13 butir; -> convention-reviewer
- 2026-10-09 fe-review (rev): convention-reviewer (aefe2328493bada48, sonnet, fallback) Harus diperbaiki 0, Perlu dikonfirmasi 0, Catatan 4 (digest _api.md:71,1179 basi + nomor baris _module.md -> dikirim ke FE dev; ArchiveOpnameFilter tanpa test sendiri, render tag Scope di 3 tempat = dicatat)
- 2026-10-09 fe-fix dok (rev): FE dev (SendMessage) _api.md rujukan basi (:71,:1179) + nomor baris endpoints.js di _api.md/_module.md dibetulkan; -> QA FE r1 (rev); ED-1094 -> In Progress
- 2026-10-09 qa-fe r1 (rev): v5-qa (af23c1c372884327b, sonnet) PASS 13 / MANUAL 1 (AC-3 lisensi) / FAIL 0; build OK; Jest Archive 336/338 (ED-1027 "Tutup" usang + tooltip flaky), full 1653/1659; e2e main 15 OK/1 SKIP, norole 2 OK, galeri work/e2e/ED-1026-archive-opname-document-20261009-055322/; e2e ditulis ulang tanpa draft + skenario usang dari ED-1029 dibereskan; regresi BE/e2e ED-1024/1025/1029 hijau (ED-1025 e2e :124 usang), ED-1027/1028 usang dicatat; data: tidak ada data developer dihapus, updater_logs 197 dipulihkan; F-1 (AC-3 manual tak di Daftar tes UI), F-2 (Daftar tes UI 004-006 masih alur draft/Segmented) -> perbaikan dok FE
- 2026-10-09 fe-dok (rev): agent sonnet (aa4f3dc699cc3a278) F-1/F-2: Daftar tes UI 004-006 butir usang dicoret + catatan ke 016-018; butir MANUAL lisensi AC-3 ditambah di 018
- 2026-10-09 commit (rev): BE cb2e78b44 (14 berkas NEW), FE 5656a5e83 (30 berkas NEW), agentic = commit ini; Jira label ai-review + komentar siap review; phase: gate2 (menunggu review batch)
