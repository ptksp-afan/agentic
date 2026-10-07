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
  be_reviewer: {id: aeb3772b34b2e56f5, at: 2026-10-07 12:02}
  qa_be: {id: ac956adcc8c13feec, at: 2026-10-07 13:09}
  api_digest: {id: a86531a2426a9ccd3, at: 2026-10-07 13:23}
  fe_dev: {id: a4bb335dda3139420, at: 2026-10-07 13:57}
  fe_reviewer: {id: a56ecf1287ea05ab2, at: 2026-10-07 14:00}
  qa_fe: {id: a6c5c9e36adee588b, at: 2026-10-07 15:21}
  be_dev: {id: a201b58741336d8af, at: 2026-10-07 13:11}
---
## Status ringkas
Long run: siap review. QA BE r1 PASS (14/14 AC BE, 22 skenario; D-1 rendah diperbaiki, terverifikasi di QA FE), QA FE r1 PASS (10 PASS / 1 MANUAL AC-25; e2e 13/13 default+user2). Commit lokal BE dc052a4bc, FE b7e08edcb (tanpa push).
Menunggu `/v5-epic review ED-1022`: developer menilai Perlu dikonfirmasi di bawah (dev BE, review BE/FE, digest 13-17, QA) + 43 butir Daftar tes UI (ksp-react docs/specs/Archive/010 24, 011 19) + O-1 qa-fe (kolom kanan tabel modal 05/drawer folder lewat gulir horizontal tanpa scrollbar terlihat).
Galeri: work/e2e/ED-1028-archive-opname-history-20261007-151138/.
Jira: ED-1028 In Progress + label ai-review + komentar siap review; 6 subtask In Progress. Bug luar item: tidak ada.

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|
| ED-1034 | [BE] Display setting & endpoint daftar sesi Opname History | BE | In Progress |
| ED-1040 | [BE] Select folder & user untuk query Opname History | BE | In Progress |
| ED-1046 | [BE] Akses baca hasil sesi terkonfirmasi (visibilitas sesi & saring baris `opnames/{id}`, `/documents`) | BE | In Progress |
| ED-1052 | [FE] Archive - drawer inline Opname History (tabel v5) & CTA modal 05 / tab Verification | FE | In Progress |
| ED-1058 | [FE] Archive - hasil satu sesi dari Opname History | FE | In Progress |
| ED-1062 | [QA] Skenario Opname History | QA | In Progress |

## Keputusan developer
- G1 (gabungan ED-1022, 19:24): semua rekomendasi diterima - EPIC K-1 a, EPIC K-2 b, grup G-1..G-7 (epics/ED-1022-gate1.md §6), K-n lain = rekomendasi analyst; jawaban tertulis di spec § Keputusan

## Catatan dev BE (untuk review batch)
- Perlu dikonfirmasi dev: (1) sesi batal tetap terbaca pemiliknya (perilaku 03; kontrak lama "Batal: 404"); (2) K-5 b menyaring juga pembuat sesi non-superadmin (Not found di luar lokasinya tersembunyi, is_partial=true bagi pembuat); (3) label path folder di select menampilkan nama induk walau induk tak terlihat (sama dengan breadcrumbs)
- Belum terverifikasi dev: AC-14 via HTTP (tak ada user tanpa List Archive), sesi dari Confirm nyata (AC-2, bagian QA)
- Efek samping runner qa-http: bind force_login=1 mencabut token lain QA_USER di api_sidomaju

## Review BE - Perlu dikonfirmasi (untuk review batch)
- OpnameService.php:925-945 validasi JSON search di service (Validator + ValidationException, 422), bukan FormRequest (sesuai spec §4)
- OpnameService.php:961-965 search idArchives [""] -> [] -> whereCovers([]) = 0 hasil (kontrak: [] = tanpa filter; [""] tidak disebut)
- SelectArchiveService.php:89-137 folder()/opnameUser() menulis ulang search/selected_id di PHP (bukan MyHelper::apiSelectQuery), excepts/exclude_id diabaikan, folder() memuat semua folder tenant per request

## QA BE - Perlu dikonfirmasi (untuk review batch)
- Kontrak §2 tabel 404 ARCHIVE412 "sesi batal" tanpa syarat pemilik (pemilik boleh membaca sesi batalnya, perilaku 03)
- idArchives [""] = 200 0 baris; label path select folder memuat induk tak terlihat; idArchives di query string GET > ~8 KB = 414 (601 id), 90 id aman
- Regresi ED-1026 AC-12 & AC-15 digantikan perubahan sah item ini (sesi terkonfirmasi terlihat kini 200; show + result_counts/is_partial): berkas QA ED-1026 + dokumen 03 §4/§6 perlu diperbarui (tidak diubah di item ini)

## Digest - dijawab orkestrator (untuk review batch)
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
- O-1: modal 05 (736 px) kolom Verified/Not Found/Invalid di luar area terlihat; drawer folder (450 px) hanya Waktu, User, sebagian Scope; terjangkau gulir horizontal tanpa scrollbar terlihat; usul butir Daftar tes UI 010 "geser tabel ke kanan"
- Judul kolom dari server (User/Verified/Not Found/Invalid) tidak ikut bahasa ID; sort per header bertumpuk (perilaku useTable)
- Transparansi QA: guard tool menolak satu `sleep 60` foreground, diganti loop `until` sesuai anjuran pesan guard (bukan penolakan izin/classifier); QA BE & QA FE sempat membaca run.md (catatan dev) sebelum menulis skenario

## Usulan pipeline
- Berkas QA ED-1026 AC-12 & AC-15 (dan dokumen 03 §4/§6 FE) perlu diperbarui: digantikan perubahan sah ED-1028 (sesi terkonfirmasi terlihat = 200 untuk List Archive; show + result_counts/is_partial)
- Agen QA membaca run.md (catatan dev) sebelum menulis skenario: pertimbangkan larangan eksplisit di definisi v5-qa demi independensi
- (BE dev) CustomizeBuilder::scopeSortAll 500 bila elemen sort array / atribut relasi (semua list v5); LIKE/= teks non-latin1 ke kolom latin1 -> 500 "Illegal mix of collations" (mungkin juga searchAll list Archive)

## Log
- 2026-10-06 16:09 start: baseline BE 42f3e07cb (0 berkas kotor), FE 6afd19054 (0 berkas kotor)
- 2026-10-06 16:51 ba: selesai, 15 BR / 25 AC / 6 subtask / 7 K (spec.md, contract.md)
- 2026-10-06 19:50 gate1: disetujui (gabungan); Jira ED-1028 + 6 subtask dibuat, sprint 46
- 2026-10-07 11:35 long-run: mulai fase be; preflight OK; rebaseline BE 49be126c7 / FE 48bbad16e (0 kotor) karena HEAD bergeser oleh commit ED-1024..ED-1027; ED-1028 + 6 subtask -> In Progress
- 2026-10-07 11:56 be: v5-be-dev (a4fd8b29333302243, opus, fallback general-purpose) 3/3 subtask selesai; 9 M + 1 baru di BE; php -l 10/10, route OK; smoke qa-http 2 skenario PASS (87 cek) di folder sekali-pakai, data QA28SMK + display setting dihapus, folder dihapus; kontrak + spec Catatan implementasi diperbarui; 3 Perlu dikonfirmasi; 0 ditolak
- 2026-10-07 12:02 be-review: v5-be-reviewer (aeb3772b34b2e56f5, sonnet, fallback general-purpose) Harus diperbaiki 0, Perlu dikonfirmasi 3; -> QA BE r1
- 2026-10-07 13:09 qa-be r1: v5-qa (ac956adcc8c13feec, sonnet) PASS 14/14 AC BE, 22 skenario / 2642 asersi PASS; D-1 rendah (ED-1034, search JSON list 200 bukan 422); regresi ED-1024 26/26, ED-1025 30/31 (AC-1 usang), ED-1026 34/37 (AC-1 usang; AC-12, AC-15 digantikan perubahan sah item ini), ED-1027 20/0/1 SKIP; DB dipulihkan; 0 ditolak
- 2026-10-07 13:11 be-fix D-1: v5-be-dev (a201b58741336d8af, opus, fallback, agent baru sempit) OpnameService.php:932-933 list JSON non-kosong -> 422; php -l OK; smoke 5/5 ([1,2], [{query}] 422; [], {}, {query} 200); kontrak §1 baris 422 diperjelas; komentar D-1 di ED-1034; -> fe
- 2026-10-07 13:23 fe digest: api-contract-analyst (a86531a2426a9ccd3, sonnet, fallback) ksp-react docs/specs/Archive/_api.md: +3 endpoint (#22-#24), 2 berubah (#14, #17), 19 jawaban dari kode BE; 5 Perlu dikonfirmasi (13-17) dijawab orkestrator dari spec (BR-10, BR-11/K-5 b, K-3 iii, BR-9 a/c); 0 blocker; -> FE dev
- 2026-10-07 13:57 fe: FE dev (a4bb335dda3139420, general-purpose) ui-plan/slice/logic -> task 010-011 (ED-1052/1058) logic-done; 16 M + 7 baru (+ _api.md orkestrator); eslint 0, prettier OK, build staging OK x4, test Archive runInBand 216/216, full suite 1524/1537 (4 suite baseline + 5 timeout beban, lulus runInBand); 0 BLOCKED; 0 ditolak; -> convention-reviewer
- 2026-10-07 14:00 fe-review: convention-reviewer (a56ecf1287ea05ab2, sonnet, fallback) Harus diperbaiki 0, Perlu dikonfirmasi 1 (Col flex di ArchiveOpnameCounts), 3 catatan ringan; -> QA FE r1
- 2026-10-07 15:21 qa-fe r1: v5-qa (a6c5c9e36adee588b, sonnet) PASS 10 AC FE / MANUAL 1 (AC-25 tampilan vs desain); build staging OK, test Archive 216/216, full suite 15 gagal = 4 baseline + 6 suite timeout beban (lulus runInBand 444/444); e2e 13/13 (default, user2); regresi BE ED-1028 22/22 + D-1 fixed (12 bentuk 422, 7 valid 200), ED-1024 26/26, ED-1025 30/31, ED-1026 34/37 (AC-1 usang; AC-12/AC-15 digantikan), ED-1027 20/0/1 SKIP; e2e usang ED-1025 dicatat; DB dipulihkan; 0 ditolak izin/classifier
- 2026-10-07 15:21 commit: BE dc052a4bc (10 berkas NEW), FE b7e08edcb (25 berkas NEW); Jira ED-1028 label ai-review + komentar siap review; Gate 2 long run = auto-commit lokal; phase: gate2 (menunggu review batch)
