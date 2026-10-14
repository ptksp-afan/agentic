---
key: ED-1125
epic: ED-1022
title: Archive FE - modal Pindahkan / Pilih Folder pindah massal - Cari memuat ulang aplikasi (form bersarang) dan scan barcode mencari di tabel halaman
slug: archive-fe-cari-modal-pindah
phase: gate2
qa_round: 2
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents:
  fe_dev: {id: a87d611ac58664c3d, at: 2026-10-14 22:13}
  fe_review: {id: a956643d74eb3ee5f, at: 2026-10-14 22:13}
  qa_fe: {id: a1919e171614cfb04, at: 2026-10-14 23:46}
---
## Status ringkas
Long run ED-1022, ronde perbaikan (bug C / ED-1153) selesai: QA FE r2 PASS 19 / MANUAL 1 (AC-13) / FAIL 0; commit lokal FE b916ab99f di atas 800e42faf; Jira In Progress + ai-review + komentar siap review (ED-1153 disebut, kartunya tidak dipindah). Menunggu review batch developer (/v5-epic review). Perlu dikonfirmasi: AC-13 tampilan modal + scanner USB asli; scan di Konfirmasi massal sesudah Pilih Folder dibuka diterima picker tersembunyi; 'Simpan & Baru' di review membiarkan scanner halaman mati (sesuai BR-8). Catatan: e2e ED-1150 sam asli SKIP (guard root = 3 baris vs Folder X/Y/Z developer), salinan guard longgar 3 OK.

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|

## Keputusan developer

## Usulan pipeline
- (QA, O-4 r2) guard `requireRoot` e2e ED-1150 (`rows.length === 3`) rapuh terhadap data developer di root sam; usul: cukup syarat Folder A ada (salinan `qa/ed1150-sam-longgar/`). Tidak diubah (ED-1149 BR-7).
- (kode, bukan pipeline) onAdd/onCloseAdd identik di Letakkan/Serahkan/Terima Dokumen; kandidat hook bersama (FE dev + reviewer), tidak diterapkan.
- (kode, bukan pipeline) aturan _onFinishFilter kini tersalin di ArchivePage + ArchiveTable; kandidat fungsi murni bersama di archive.function.js (FE dev + reviewer), tidak diterapkan karena di luar tabel Perbaikan.

## Log
- 2026-10-14 12:52 start: baseline BE 6fcc91fd0 (0 berkas kotor), FE cf37552dd (0 berkas kotor)
- 2026-10-14 14:45 gate1 ronde 5 (epic ED-1022) disetujui developer: K-1 a, K-2 c, K-3 a, K-4 a; tambahan scope scan saat modal terbuka (dari ED-1126 K-3; scan di modal = cari di picker); spec approved (17 AC); Jira: judul disesuaikan, sprint 46 + komentar (Bug tanpa subtask, status BUG sampai runner mulai); phase: fe (FE saja, long run)
- 2026-10-14 16:40 long run mulai (story runner): preflight OK; Jira ED-1125 BUG -> In Progress, assignee diisi; rebaseline BE 0b8417778 (commit ED-1124 bukan milik item ini), FE cf37552dd, 0 berkas kotor. Tanpa digest API (contract: null, FE saja)
- 2026-10-14 17:06 fe: FE dev (a69a00853d60b52c6, general-purpose) task docs/specs/Archive/026-fix-cari-scan-modal-pindah.md logic-done (slice n/a); component={false} di 2 form modal, _onFinishFilter ArchiveTable = ArchivePage (K-3 a), qrScanSearchRef ke 2 modal + setActiveScanner false/true; FE 9 M + 2 baru (changed.txt); components/Search dan appHook tidak disentuh; eslint 0; Archive 14 suite 476/476 (putaran 1 475/476: 1 test lama ArchivePage timeout di bawah beban, ulang 45/45); mutasi balik: tiap fix dibalik -> test gagal (2/6/2); build staging OK; Daftar tes UI 13 butir. Perlu dikonfirmasi 2 (review batch): tampilan modal tanpa form.ant-form-vertical belum dilihat di browser (AC-13); scan di Konfirmasi massal sesudah Pilih Folder pernah dibuka diterima picker tersembunyi (panel rc-tabs ter-mount; langkah/item/halaman tetap)
- 2026-10-14 17:09 fe-review: convention-reviewer (af950b1fa27648da6, sonnet, fallback general-purpose) Harus diperbaiki 0; Perlu dikonfirmasi 0; catatan 3 (_onFinishFilter tersalin di ArchivePage + ArchiveTable, usulan fungsi bersama tidak diterapkan; ArchiveTable/index.test.js di luar tabel Perbaikan = test K-3 a; ArchiveTable hanya dipakai 2 modal). Berikutnya: QA FE r1
- 2026-10-14 19:00 qa-fe r1 (ae325d8bc6e73fecf, sonnet): PASS 16 / MANUAL 1 (AC-13, Gate 2) / FAIL 0. e2e ED-1125 14 OK (13 test + smoke, --workers 1); kontrol negatif vs build cf37552dd 9 GAGAL / 4 OK (regresi). Jest Archive 14 suite 476/476 (AC-10 26/26). AC-11: e2e ED-1069 dibalik (karakterisasi B-FE-1 jadi asersi, armPickerSearch dihapus) 5 OK. AC-12 regresi hijau: ED-1025 5, ED-1027 1, ED-1072 2, ED-1150 (sam) 3, ED-1026 GAMMA 1, ED-1028 AC-22 1. Full Jest: 4 suite gagal pra-ada non-Archive (BatchOut, production.function, InterbankTransferForm, CustomerPurchaseOrderView). Regresi BE 240 PASS / 0 FAIL / 2 SKIP (isolasi tenant, 1 DB). Data: fixture 0QA125- (8 archives + 1 archive_locations) dibuat dan dibuang persis di 8 run; CLEAN-1/CLEAN-2/SESI-1 PASS; snapshot ED-1149 sama dengan ED-1124-r2-after; force_login sam mencabut 1 sesi sam lain (K-4 a / DU-4 a). Galeri work/e2e/ED-1125-archive-fe-cari-modal-pindah-20261014-185200/ (kontrol negatif -174744/). Pengamatan di luar item: O-1 -> tiket ED-1153; O-2 = Perlu dikonfirmasi FE dev; O-3 (components/Search refocus 100 ms, pra-ada) tanpa tiket
- 2026-10-14 19:01 bug di luar item: ED-1153 (Bug, parent ED-1022, label ai-found): Letakkan Dokumen - sesudah Kembali dari langkah review, scan juga mencari di tabel halaman (ArchiveDocumentModal/index.js:20-24)
- 2026-10-14 19:01 commit: FE 800e42faf (11 berkas NEW: 6 kode + 3 test + task 026 + _module.md), BE tidak ada perubahan; Gate 2 long run = auto-commit lokal; Jira ED-1125 In Progress + label ai-review + komentar siap review; phase: gate2 (menunggu review batch)
- 2026-10-14 19:03 commit agentic: 88ddaac (features/ED-1125-* + e2e ED-1069 AC-11)
- 2026-10-14 21:23 review batch ED-1022 ronde 5: developer MINTA PERBAIKAN - gabungkan Bug ED-1153 (ArchiveDocumentModal onCloseAdd selalu setActiveScanner(true), juga saat Kembali/✕ di langkah review; scanner halaman hanya aktif lagi saat modal benar-benar ditutup). Spec ditambah analyst (AC baru); phase: fe (commit perbaikan di atas 800e42faf, lalu QA FE ulang); Jira: label ai-review dilepas + komentar, ED-1153 dikomentari 'diperbaiki di ED-1125'
- 2026-10-14 21:25 spec: ED-1153 masuk (bug C, AC-17 e2e nyata, AC-18 unit); developer: modal Serah Terima dan Terima Dokumen (pola onCloseAdd sama) ikut diperbaiki di item ini (opsi a) -> analyst menambah AC
- 2026-10-14 21:27 spec: modal Serahkan + Terima Dokumen ikut (AC-18 unit diperluas, AC-19/AC-20 e2e baru; perlu permission Handover/Receive Document, role preset bila perlu); preflight OK; runner ED-1125 ronde perbaikan mulai (fase fe)
- 2026-10-14 21:28 runner ronde perbaikan: preflight OK; FE bersih di 800e42faf, BE 0b8417778 bersih; Jira In Progress (tanpa ai-review); FE dev baru (id lama > 1 jam) untuk bug C (AC-17..AC-20)
- 2026-10-14 22:11 fe (perbaikan C): FE dev (a87d611ac58664c3d, general-purpose) task 026 diperluas, logic-done; onCloseAdd 3 modal (Letakkan/Serahkan/Terima) setActiveScanner(true) hanya di cabang tutup; 3 test baru AC-18 15/15; mutasi 4/4, 2/5, 2/6 gagal ((b)/(d) sudah benar sebelumnya); eslint 0; Jest Archive runInBand 17 suite 489/491 (2 timeout ArchiveTable ED-1150 di bawah beban, ulang sendiri 7/7); build staging OK; Daftar tes UI C 4 butir (11 ditulis ulang, 14-16 baru). FE 5 M + 3 baru. Perlu dikonfirmasi (review batch): 'Simpan & Baru' di review kini membiarkan scanner halaman mati (sesuai BR-8, belum dicoba di browser). Usulan kode: hook bersama onAdd/onCloseAdd 3 modal (tidak diterapkan)
- 2026-10-14 22:13 fe-review (perbaikan C): convention-reviewer (a956643d74eb3ee5f, sonnet, fallback general-purpose) Harus diperbaiki 0; Perlu dikonfirmasi 0; catatan 4 (onCloseAdd identik di 3 modal = usulan hook bersama; mock kamera saja; teks 'Scan Code' hardcode pra-ada; 'Simpan & Baru' tanpa unit). Berikutnya: QA FE r2 (seluruh spec)
- 2026-10-14 23:46 qa-fe r2 (a1919e171614cfb04, sonnet): PASS 19 / MANUAL 1 (AC-13) / FAIL 0, seluruh spec. e2e ED-1125 18 OK (AC-17 ditulis ulang, AC-19, AC-20, + 2 jalur sukses) --workers 1; kontrol negatif vs build 800e42faf: AC-17/19/20 + Simpan & Baru GAGAL di asersi bug; unit AC-18 vs kode lama 8/15 gagal. Jest Archive runInBand 17 suite 491/491. AC-12: ED-1025 5, ED-1027 1, ED-1072 2, ED-1026 GAMMA 1, ED-1028 AC-22 1, ED-1069 5 (AC-11); ED-1150 sam asli 3 SKIP BLOCKED-by-data (guard root 3 baris; developer menambah Folder X/Y/Z sebelum ronde ini), salinan guard longgar `qa/ed1150-sam-longgar/` 3 OK dua kali -> diterima runner sebagai PASS dengan catatan untuk review. Regresi BE 240 PASS / 0 FAIL / 2 SKIP. Data: fixture 0QA125- dibuat/dibuang persis di 8 run, dokumen uji D6 dipulihkan per baris; CLEAN-1/CLEAN-2/SESI-1 PASS; bahasa QA_USER = ID; token aktif QA_USER/QA_USER2/sam = 0; tanpa role preset (QA_USER role 3 sudah punya Handover/Receive Document). Galeri work/e2e/ED-1125-archive-fe-cari-modal-pindah-20261014-233211/ (kontrol negatif -222823/). Pengamatan: O-1 selesai di item ini; O-2 tetap (Perlu dikonfirmasi); O-3 pra-ada; O-4 guard ED-1150 (usulan, tanpa tiket); O-5 Simpan & Baru dicoba di browser, sesuai BR-8/BR-9
- 2026-10-14 23:46 commit: FE b916ab99f (8 berkas NEW: 3 kode + 3 test + task 026 + _module.md; di atas 800e42faf, tanpa amend), BE tidak ada perubahan; Jira ED-1125 label ai-review + komentar siap review (ED-1153 disebut, tidak ditransisikan); phase: gate2 (menunggu review batch)
