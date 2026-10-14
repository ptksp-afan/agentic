---
key: ED-1125
epic: ED-1022
title: Archive FE - modal Pindahkan / Pilih Folder pindah massal - Cari memuat ulang aplikasi (form bersarang) dan scan barcode mencari di tabel halaman
slug: archive-fe-cari-modal-pindah
phase: gate2
qa_round: 1
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents:
  fe_dev: {id: a69a00853d60b52c6, at: 2026-10-14 17:06}
  fe_review: {id: af950b1fa27648da6, at: 2026-10-14 17:09}
  qa_fe: {id: ae325d8bc6e73fecf, at: 2026-10-14 19:00}
---
## Status ringkas
Long run ED-1022: QA FE r1 PASS, commit lokal FE 800e42faf; Jira In Progress + ai-review. Menunggu review batch developer (/v5-epic review). Perlu dikonfirmasi: tampilan modal tanpa form.ant-form-vertical (AC-13), scan di Konfirmasi massal sesudah Pilih Folder dibuka diterima picker tersembunyi. Bug di luar item: ED-1153.

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|

## Keputusan developer

## Usulan pipeline
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
