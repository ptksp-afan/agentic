---
key: ED-1028
epic: ED-1022
title: Archive - Opname History (semua sesi)
slug: archive-opname-history
phase: gate2
qa_round: 1
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents:
  analyst: {id: ab2fd673cb9f6cf69, at: 2026-10-06 16:51}
  be_reviewer: {id: af04201aff685abf2, at: 2026-10-10 rev}
  qa_be: {id: a3c615fb4a26a1643, at: 2026-10-10 rev}
  api_digest: {id: ad90ad4414634fe91, at: 2026-10-10 rev}
  fe_dev: {id: a4bb335dda3139420, at: 2026-10-07 13:57}
  fe_reviewer: {id: a56ecf1287ea05ab2, at: 2026-10-07 14:00}
  qa_fe: {id: ab587cdff9aa18cf9, at: 2026-10-10 rev}
  be_dev: {id: aa9ea67666e9a04c0, at: 2026-10-10 rev}
---
## Status ringkas
**Revisi selesai, siap review batch (long run ED-1022, 2026-10-10).** QA BE PASS 16/16, QA FE PASS 12 / MANUAL 1 (AC-25), 0 defect. Commit lokal baru di atas commit lama: BE 265c0d1bd, FE 47bb57d1d (docs saja: digest + daftar tes UI 010/011), agentic (lihat Log). Jira ED-1028 In Progress + label ai-review; subtask tetap In Progress. Bug di luar item: ED-1123 (V4 display-columns tanpa saringan). Menunggu `/v5-epic review ED-1022`.

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|
| ED-1034 | [BE] Display setting & endpoint daftar sesi Opname History | BE | In Progress |
| ED-1040 | [BE] Select folder & user untuk query Opname History | BE | In Progress |
| ED-1046 | [BE] Akses baca hasil sesi terkonfirmasi (visibilitas sesi & saring baris `opnames/{id}`, `/documents`) | BE | In Progress |
| ED-1052 | [FE] Archive - drawer inline Opname History (tabel v5) & CTA modal 05 / tab Verification | FE | In Progress |
| ED-1058 | [FE] Archive - hasil satu sesi dari Opname History | FE | In Progress |
| ED-1062 | [QA] Skenario Opname History | QA | In Progress |
| ED-1100 | [BE] saring folders & warnings ringkasan sesi per hak View (rev) | BE | In Progress |
| ED-1101 | [BE] Opname History hanya untuk Opname Document (rev) | BE | In Progress |
| ED-1102 | [QA] Skenario Opname History revisi | QA | In Progress |

## Keputusan developer
- G1 (gabungan ED-1022, 19:24): semua rekomendasi diterima - EPIC K-1 a, EPIC K-2 b, grup G-1..G-7 (epics/ED-1022-gate1.md §6), K-n lain = rekomendasi analyst; jawaban tertulis di spec § Keputusan

## Catatan dev BE (untuk review batch)
- Perlu dikonfirmasi dev: (1) sesi batal tetap terbaca pemiliknya (perilaku 03; kontrak lama "Batal: 404"); (2) K-5 b menyaring juga pembuat sesi non-superadmin (Not found di luar lokasinya tersembunyi, is_partial=true bagi pembuat); (3) label path folder di select menampilkan nama induk walau induk tak terlihat (sama dengan breadcrumbs)
- Belum terverifikasi dev: AC-14 via HTTP (tak ada user tanpa List Archive), sesi dari Confirm nyata (AC-2, bagian QA)
- Efek samping runner qa-http: bind force_login=1 mencabut token lain QA_USER di api_sidomaju

## Review BE - Perlu dikonfirmasi (untuk review batch)
- (rev 2026-10-10) SelectArchiveService.php:144 opnameUser & ColumnDisplaySettingService.php:171 hiddenModules memakai MyHelper::hasPermission (beda dari middleware permission_v5: user customer tidak diloloskan, permissions.is_active tidak difilter); preseden hiddenColumns ED-1027
- OpnameService.php:925-945 validasi JSON search di service (Validator + ValidationException, 422), bukan FormRequest (sesuai spec §4)
- OpnameService.php:961-965 search idArchives [""] -> [] -> whereCovers([]) = 0 hasil (kontrak: [] = tanpa filter; [""] tidak disebut)
- SelectArchiveService.php:89-137 folder()/opnameUser() menulis ulang search/selected_id di PHP (bukan MyHelper::apiSelectQuery), excepts/exclude_id diabaikan, folder() memuat semua folder tenant per request

## QA BE - Perlu dikonfirmasi (untuk review batch)
- (rev 2026-10-10) PUT display-columns R0 tanpa current_columns = 422 (validasi controller lebih dulu), body valid = 403; B-2 hasPermission vs permission_v5 (sama dengan review BE); B-1 -> ED-1123 (V4 display-columns tanpa saringan, keputusan developer)
- Kontrak §2 tabel 404 ARCHIVE412 "sesi batal" tanpa syarat pemilik (pemilik boleh membaca sesi batalnya, perilaku 03)
- idArchives [""] = 200 0 baris; label path select folder memuat induk tak terlihat; idArchives di query string GET > ~8 KB = 414 (601 id), 90 id aman
- Regresi ED-1026 AC-12 & AC-15 digantikan perubahan sah item ini (sesi terkonfirmasi terlihat kini 200; show + result_counts/is_partial): berkas QA ED-1026 + dokumen 03 §4/§6 perlu diperbarui (tidak diubah di item ini)

## Digest - dijawab orkestrator (untuk review batch)
- (rev 2026-10-10) 26 customer vs hasPermission (spec §4), 27 sisa documentVerified di baris index documentArchive (ED-1027), 29 = ED-1122; dicatat di _api.md, tidak memblokir. Teks Pass kesembilan "Kode FE belum mengikuti" mungkin usang (ArchiveDrawer:49 canVerify) - milik ED-1027
- 13 folders[]/warnings[] GET opnames/{id} sesi terkonfirmasi tidak disaring per pemanggil (nama + jumlah dokumen semua folder sesi terlihat oleh pemanggil parsial); FE tidak merendernya (BR-10); penyaringan BE = keputusan developer
- 14-17: pembuat ikut disaring (isPartial), label path apa adanya (K-3 iii), multi Folder tanpa batas FE, confirmedAt ikut dateTimeRange tableHelper (BR-9 a)

## FE - keputusan dev sendiri (untuk review batch)
- Wadah drawer inline lewat closest('.ant-modal-content, .ant-drawer-content') dari jangkar (bukan prop container ref spec §5); riwayat di ArchiveDrawer dirender di luar ArchiveForm (disabled read-only tidak menembus)
- Scanner: Search riwayat punya scanner aktif (scan masuk ke search riwayat, bukan list Archive) - dinilai memenuhi BR-14; ArchiveVerificationModal: prop onOpenHistory dibuang, + qrScanSearchRef
- Kartu angka jadi komponen bersama ArchiveOpnameCounts (Step 3 ED-1026 ikut memakai); ArchiveOpnameResult + prop opsional resultCounts
- #14 diminta tiap buka hasil, #17 sesudah #14 sukses (satu toast 404); subjudul dari respons #14; layar kecil: klik baris juga membuka expand bawaan DefaultTable
- Beda dari desain: 05b drawer inline (✕ kiri judul, tanpa footer Close); judul kolom dari display setting server (id tetap "User/Verified/Not Found"); catatan parsial = Alert info; catatan kaki 05b di bawah tabel
- FE review Perlu dikonfirmasi: ArchiveOpnameCounts/index.js:28 Col flex "1 1 160px" (bukan formLayout; dipindah dari ArchiveOpnameConfirm ED-1026); catatan: ArchiveOpnameCounts tanpa test sendiri, komentar JSX ArchiveDrawer:91 125 karakter
- Belum dilihat di browser (z-index DisplaySettingDrawer dari dalam modal 05 hanya dinilai dari kode); Daftar tes UI: 010 24 butir, 011 19 butir

## QA FE - catatan (untuk review batch)
- (rev 2026-10-10) Setup > Display Setting UI tanpa route di build ini: K-9 a dibuktikan lewat GET setting/display-columns per profil; tes tooltip ArchivePage flaky saat beban (lulus 30/30 sendiri); 4 tes Jest baseline non-Archive gagal (pra-ada)
- O-1: modal 05 (736 px) kolom Verified/Not Found/Invalid di luar area terlihat; drawer folder (450 px) hanya Waktu, User, sebagian Scope; terjangkau gulir horizontal tanpa scrollbar terlihat; usul butir Daftar tes UI 010 "geser tabel ke kanan"
- Judul kolom dari server (User/Verified/Not Found/Invalid) tidak ikut bahasa ID; sort per header bertumpuk (perilaku useTable)
- Transparansi QA: guard tool menolak satu `sleep 60` foreground, diganti loop `until` sesuai anjuran pesan guard (bukan penolakan izin/classifier); QA BE & QA FE sempat membaca run.md (catatan dev) sebelum menulis skenario

## Usulan pipeline
- Berkas QA ED-1026 AC-12 & AC-15 (dan dokumen 03 §4/§6 FE) perlu diperbarui: digantikan perubahan sah ED-1028 (sesi terkonfirmasi terlihat = 200 untuk List Archive; show + result_counts/is_partial)
- Agen QA membaca run.md (catatan dev) sebelum menulis skenario: pertimbangkan larangan eksplisit di definisi v5-qa demi independensi
- (BE dev) CustomizeBuilder::scopeSortAll 500 bila elemen sort array / atribut relasi (semua list v5); LIKE/= teks non-latin1 ke kolom latin1 -> 500 "Illegal mix of collations" (mungkin juga searchAll list Archive)

## Log
- 2026-10-06..08 (diringkas): BA 15 BR/25 AC/6 subtask, Gate 1 gabungan; be (v5-be-dev) -> review 0 HD -> QA BE r1 PASS 14/14 (D-1 rendah diperbaiki) -> digest -> FE 010-011 -> QA FE r1 PASS 10/MANUAL 1; commit BE dc052a4bc, FE b7e08edcb, agentic 934f11f; review batch 2026-10-08 minta perbaikan; BA revisi 17 BR/29 AC/3 subtask (ED-1100..ED-1102), Gate 1 ronde 2 disetujui (K-8 a, K-9 a, K-10 b, EPIC K-4 a); detail: git log agentic
- 2026-10-10 long-run rev: mulai fase be; preflight OK; rebaseline BE 3ae86a5c1 / FE 5a0c34be1 (0 kotor); ED-1100..ED-1102 -> In Progress
- 2026-10-10 be (rev): v5-be-dev (aa9ea67666e9a04c0, opus, fallback general-purpose) ED-1100 + ED-1101 selesai; 4 M di BE (OpnameService, SelectArchiveService, ColumnDisplaySettingService, api.php); php -l 4/4, route OK; smoke 2 skenario PASS (90 asersi) di qa/smoke-rev (dihapus), data QAD28 + grant role 5 dipulihkan, archive_opnames 0 (kosong sebelum); regresi ED-1027 AC-24/X-12/X-13 PASS; kontrak tetap; 0 ditolak
- 2026-10-10 be-review (rev): v5-be-reviewer (af04201aff685abf2, sonnet, fallback) Harus diperbaiki 0, Perlu dikonfirmasi 1 (hasPermission vs permission_v5); -> QA BE r1 (rev)
- 2026-10-10 qa-be r1 (rev): v5-qa (a3c615fb4a26a1643, sonnet) PASS 16/16 (AC-3 dihapus), 24 skenario / 3309 asersi; 0 defect; skrip QA usang diperbarui (qa_lib, qa_hist, scenario1-5,7 + baru scenario8/9), uji juga dengan data developer simulasi (DEVSIM, dibuang, DB identik); regresi ED-1024 26/0, ED-1025 40/0, ED-1026 39/0, ED-1027 28/0/1 SKIP, ED-1029 29/0/1 SKIP; data developer dihapus 0; bug di luar item B-1 -> ED-1123 (ai-found), B-3 = ED-1070/ED-1071 pra-ada; 0 ditolak; -> fe (digest)
- 2026-10-10 fe digest (rev): api-contract-analyst (ad90ad4414634fe91, sonnet, fallback) _api.md Pass kesepuluh: #14 (permission + saring folders/warnings), #17, #22, #24, #30-#31 (+ index); 3 jawaban penuh + 1 sebagian dari kode BE; 4 sisa Perlu dikonfirmasi dijawab orkestrator (tidak memblokir); 0 ditolak
- 2026-10-10 fe (rev): tanpa kode FE (spec §5: FB-8c = ED-1027, FB-10 = ED-1026); FE dev & convention-reviewer dilewati (diff FE hanya _api.md); -> QA FE r1 (rev)
- 2026-10-10 qa-fe r1 (rev): v5-qa (ab587cdff9aa18cf9, sonnet) PASS 12 / MANUAL 1 (AC-25) / FAIL 0; build staging OK; Jest Archive 358 lulus; full suite 10 gagal = 4 baseline + 6 timeout beban; e2e --workers 1 default+user2 16/16 (tabel kosong) dan 16/16 (5 sesi developer simulasi), galeri work/e2e/ED-1028-archive-opname-history-20261010-121046/; e2e ditulis ulang (CheckableTag, getScopeTag, tanpa footer modal, user2 + OD, AC-27 R0, AC-29, takeStateLock, fixture 2031); regresi BE ED-1028 24/0, ED-1024 26/0, ED-1025 40/0, ED-1026 39/0, ED-1027 28/0/1, ED-1029 29/0/1; data developer dihapus 0, DB identik (count + CHECKSUM); 0 ditolak
- 2026-10-10 fe docs (rev): general-purpose (af7d8b16ec35bdd8b, sonnet) 010 (Daftar tes UI 25 butir, +1 aturan permission) dan 011 (19 butir, Segmented -> CheckableTag) disesuaikan UI terpasang; _module.md judul 011 oleh orkestrator; 0 ditolak
- 2026-10-10 commit (rev): BE 265c0d1bd (4 berkas NEW), FE 47bb57d1d (4 berkas docs NEW); Gate 2 long run = auto-commit lokal; phase: gate2 (menunggu review batch)
- 2026-10-10 jira (rev): ED-1028 label ai-review + komentar siap review (QA, tes UI 44 butir, galeri, ED-1123); subtask ED-1100..ED-1102 tetap In Progress
