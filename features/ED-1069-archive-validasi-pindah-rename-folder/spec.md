---
key: ED-1069
epic: ED-1022
type: bug
title: Archive - celah validasi pindah/rename folder & 500 di select lokasi / transaksi terkait
status: approved
batch: ED-1069
fe_modules: []                # FE tetap; diuji e2e
new_fe_modules: []
be_modules: [DocumentArchive, Select/DocumentArchive]
qa_model: sonnet
contract: null                # bentuk endpoint tetap; hanya penolakan baru
depends_on: [ED-1025]         # cek hak + kelompok PUT = ED-1025 rev
---

## Ringkasan
Lima celah pra-ada di Archive (BE `44bea5136`): put-in ke induk tak ada memindah ke root, folder bisa dipindah ke
turunannya (siklus → folder hilang, membukanya 500), rename lolos nama kembar, select lokasi dan "transaksi terkait" 500;
plus celah sejenis (K-1, K-5, K-6). Perbaikan hanya BE. Item ini pemilik aturan tanpa-siklus (K-3) untuk put-in dan
`PUT archives/{id}`: **ED-1070 butir 2 (induk = diri sendiri) selesai di sini** (AC-4). Hak tetap ED-1025 rev; validasi
sesudah cek hak.

## Reproduksi
HTTP 2026-10-08, `api_sidomaju`, `QA_USER`, data `QA69-` dihapus lagi. Data QA: 22 folder, 0 siklus, 0 induk tak valid,
0 folder aktif senama. Harapan = Perbaikan.

| # | Langkah | Sekarang |
|---|---|---|
| 1 | A>B; put-in [B] ke id acak | 200 `ARCHIVE204`, B ke root |
| 1b | put-in / create-folder ke dokumen atau folder nonaktif | 200, baris "hilang" |
| 2, 2b | A>B>C; put-in [A] ke C; `name`=A ke A / B | 200, siklus; `GET archives?id_archive=B` 500 sesudah 38 s |
| 2c | `PUT archives/{B}` induk = C / = B | 200, siklus (= ED-1070 butir 2) |
| 3, 3b | rename B ke nama saudaranya; P1/S, P2/S put-in [S1, S2] ke T | 200, folder aktif senama |
| 3c | rename dokumen | 200 |
| 4 | `GET select/document-archive/archive/locations?id_archive_parent=<acak>` | 500 (`$parent` null) |
| 5 | dokumen `id_transaction` tak ada (7 tipe), `search={"showRelatedTransaction":true}` | 500 |

Lewat UI: butir 2 (Search modal Pindahkan memuat turunan, `ArchiveMoveModalForm.js:19-24`), 5; butir 3 tidak (`endpoints.js:58-80`).

## Akar masalah
| # | `file:line` | Sebab |
|---|---|---|
| 1 | `PutInFolderRequest.php:24`; `DocumentService.php:25,59` | tanpa `exists`; null → root |
| 1b | `CreateFolderRequest.php:24`, `UpdateRequest.php:25` | `exists` tanpa `type`/`is_active` |
| 2 | `SelfFolderRule.php:30-41` | hanya diri sendiri; non-implisit → sumber `name` lolos |
| 2 | `Archive.php:58-61` | `parents()` rekursif tak berujung pada siklus |
| 2c | `UpdateRequest.php:25` | tanpa cek diri/turunan |
| 3, 3c | `RenameFolderRequest.php:19` | tanpa `UniqueNameRule`, tanpa cek tipe |
| 3b | `Document/UniqueNameRule.php:41-45` | nama antar sumber tidak dibandingkan |
| 4 | `SelectArchiveService.php:19-26` | `$parent` null dipakai |
| 5 | `ArchiveService.php:405-495` | transaksi utama null dipakai (7 cabang) |

## Perbaikan (BE saja)
| # | Perubahan |
|---|---|
| 1, 1b | `id_archive_parent` put-in, create-folder, `PUT archives/{id}`: `bail`, `string`, ada **dan** folder (`type`=1) **dan** aktif → 422 (pola `CreateFolderRequest.php:24`; K-1 a) |
| 2, 2b, 2c | Satu aturan (K-3) "tujuan bukan sumber dan bukan turunan folder sumber": sumber = `Archive::findSources(id_archives, name)` (implisit, juga `name`); turunan = `descendantFolderIds` (`DocumentArchiveService.php:175`, aman siklus). Di `PutInFolderRequest` dan `UpdateRequest` (sumber = id route). 400 `ARCHIVE404`, teks K-2 a di `en_EN.php` + `id_ID.php` |
| 3 | `RenameFolderRequest`: `name` `bail|required|string` + `UniqueNameRule(id)` dengan induk **di DB** → 400 `ARCHIVE405`. 3b: `Document/UniqueNameRule` juga membandingkan nama antar folder sumber (K-5 a) |
| 3c | Rename hanya folder (K-6 a): dokumen → 400 kode baru `ARCHIVE419` "Hanya folder yang bisa diganti nama" / "Only folders can be renamed" |
| 4 | `SelectArchiveController@location`: `id_archive_parent` `nullable|string` (pola `:32-38`) → 422; tak ada → 404 `ARCHIVE400` (K-4 a) |
| 5 | Transaksi utama tidak ada → `related_transactions`=[] (7 tipe) |

Urutan (ED-1025 rev; gate1 O-4): 404 → 403 di `authorize()` (`ARCHIVE407`, `GE0114`, `ARCHIVE408`, `ARCHIVE410`) → 422 →
400 (`ARCHIVE404/405/419`); aturan 400 dilewati bila induk tidak sah. `PUT archives/{id}` (K-15 b): aturan induk +
tanpa-siklus hanya di **kelompok detail** (sesudah `GE0114` `Update Folder`/`ARCHIVE408`/`ARCHIVE410`) dan hanya bila
induk berubah (pola `ArchiveService.php:336`); kelompok izin saja tidak menilai induk. Rename: `ARCHIVE408` (folder Off
cukup View, K-12 a) sebelum 400. Hak pindah folder (K-13 a) tetap. Tanpa Updater, permission, tabel, kueri data.

## Acceptance criteria
`[BE]` = http di `api_sidomaju` (`archives.id_archive_parent`, `name`, `history`), data dipulihkan QA. `QA_USER`
= role 3 (tidak bypass hak folder); "tanpa hak" = keadaan sementara pola ED-1025 `qa/qa_lib.php:7-12`.

| AC | Given / When / Then | Tag | Butir |
|---|---|---|---|
| AC-1 | A>B, dokumen D di A. put-in [B] dan [D] ke id acak → 422 `errors.id_archive_parent`; induk, `history` B/D tetap | [BE] | 1 |
| AC-2 | [K-1 a] induk = dokumen / folder `is_active`=0 (juga nonaktif di bawah sumber): put-in → 422 (bukan 400); create-folder → 422, tanpa baris baru; `PUT archives/{B}` → 422 | [BE] | 1b |
| AC-3 | A>B>C, X di root. put-in [A]→C, [A]→B, `name`=A→B, `name`=A→A, [X, A]→C → 400 `ARCHIVE404` (teks K-2 a); tak ada baris berubah | [BE] | 2 |
| AC-4 | [K-3; = ED-1070 butir 2] `PUT archives/{A}` detail induk = A → 400 `ARCHIVE404`; = C → 400; A tetap di root. Induk sekarang dikirim ulang → 200; kelompok izin saja → 200 | [BE] | 2c |
| AC-5 | Regresi: put-in [C]→root, [C]→A, [B]→X, D→C, [A]→X, `PUT archives/{C}` induk = A → 200, `history` `move`/`take out`/`place`; `GET archives?id_archive=C` 200, `breadcrumbs` benar | [BE] | 2 |
| AC-6 | 403 dulu. A, B Off; C On, `QA_USER` View saja: put-in [A]→C → 403 `ARCHIVE410` (bukan 400); ke id acak → 422; `PUT archives/{A}` induk = C → 403 `ARCHIVE410`; role tanpa `Update Folder`, induk = A → 403 `GE0114`; rename folder On tanpa Update ke nama saudaranya → 403 `ARCHIVE408`. Data tetap | [BE] | 1-3 |
| AC-7 | P berisi A, B, folder nonaktif N. rename B → A → 400 `ARCHIVE405`, B tetap; sama di root; nama A + body `id_archive_parent`=X → 400; A → A → 200; B → N → 200; B → unik → 200 + `history` `rename`; `name`=["x"] → 422 | [BE] | 3 |
| AC-8 | [K-5 a] P1/S, P2/S: put-in [S1, S2]→T → 400 `ARCHIVE405`, keduanya tetap; [S1]→T → 200 | [BE] | 3b |
| AC-9 | [K-6 a] dokumen D: `PUT archives/rename/{D}` → 400 `ARCHIVE419` (teks id/en), `name` tetap | [BE] | 3c |
| AC-10 | Select lokasi: induk acak → 404 `ARCHIVE400` (K-4 a); `id_archive_parent[]=x` → 422; tanpa induk → semua lokasi aktif; induk berlokasi terbatas → lokasinya; induk semua lokasi → semua | [BE] | 4 |
| AC-11 | Folder F, 7 dokumen yatim (tipe 6, 7, 8, 9, 29, 31, 222): `GET archives?id_archive=F&search={"showRelatedTransaction":true}` → 200, `related_transactions`=[]; dokumen bertransaksi = baseline `44bea5136` | [BE] | 5 |
| AC-12 | [gate1 §5] QA ini membalik karakterisasi ED-1025 X-8 bagian induk = diri sendiri (`qa/scenario4-select-misc.php:517-523`) jadi asersi 400 `ARCHIVE404` + induk tetap; `scenario4` dijalankan, X-8 lulus. X-8 search, X-13, X-12 tidak diubah | [BE] | 2c |
| AC-13 | e2e `QA_USER`: ⋯ Pindahkan A → Search C → Move → toast `ARCHIVE404` (id-ID), modal tetap, A tidak pindah; folder sah → sukses. Pindah massal [A, X] ke C → toast sama | [FE] | 2 |
| AC-14 | e2e tanpa error konsol/toast: Archive root & folder, Show Related Transaction di F (AC-11), drawer Detail (select Lokasi), modal Pindahkan & pindah massal | [FE] | 4, 5 |

## Keputusan untuk developer
Risiko rendah semua; sumber `epics/ED-1022-gate1.md` §9b.

| K | Pertanyaan (opsi; rek.) | Jawaban |
|---|---|---|
| K-1 | Induk = dokumen / folder nonaktif: a) 422 di put-in, create-folder, ubah; b) tiket lain; rek. a | Jawaban Gate 1 (2026-10-08): a - 422 di ketiga endpoint (AC-2) |
| K-2 | Kode siklus: a) `ARCHIVE404`, teks "Tidak bisa dipindahkan ke dirinya sendiri atau subfoldernya" / "Can't be moved into itself or its subfolder"; b) kode baru; rek. a | Jawaban Gate 1 (2026-10-08): a - `ARCHIVE404`, teks diperluas (AC-3, 4) |
| K-3 | Pemilik aturan tanpa-siklus ubah folder: a) ED-1069; b) ED-1070; rek. a | Jawaban Gate 1 (2026-10-08): a - pemilik ED-1069 (put-in + `PUT archives/{id}`); ED-1070 ditunda, butir 2 selesai di sini (AC-4, 12) |
| K-4 | Select lokasi, induk tak ada: a) 404 `ARCHIVE400`; b) 200 `options: []`; c) abaikan; rek. a | Jawaban Gate 1 (2026-10-08): a - 404 `ARCHIVE400` (AC-10) |
| K-5 | Pindah massal dua folder senama ke satu tujuan: a) 400 `ARCHIVE405`; b) tiket lain; rek. a | Jawaban Gate 1 (2026-10-08): a - ditolak di sini (AC-8) |
| K-6 | Rename dokumen lewat `archives/rename/{id}`: a) hanya folder, 400 kode baru; b) di luar cakupan; rek. a | Jawaban Gate 1 (2026-10-08): a - 400 `ARCHIVE419` (AC-9) |
| K-7 | Siklus lama di produksi (QA DB: 0): a) hanya mencegah; b) a + breadcrumb aman siklus; c) b + Updater memutus; rek. a | Jawaban Gate 1 (2026-10-08): a tanpa kueri - hanya mencegah siklus baru; tidak ada kueri cek produksi |

## Di luar cakupan
- Hak pindah/rename/ubah (ED-1025 rev BR-4, 7, 9; K-12 a, K-13 a, K-15 b) tidak diubah; di sini hanya validasi data.
- Siklus lama (K-7 a): tidak dicek/diputus; breadcrumb tetap `with('parents')`.
- Ditunda Gate 1 (task lain, tidak ditunggu): `search`/`pagination`/`sorts` list v5 (ED-1070 butir 1, 3), kolasi (ED-1071);
  karakterisasinya di QA ED-1025 (X-8 search, X-12, X-13) tetap. Klik ⋯ dokumen → ED-1072.
- FE: modal Pindahkan tetap memuat turunan; BE menolak, toast tampil.
- Select lokasi tanpa cek scope/View induk; ubah dokumen lewat `PUT archives/{id}`; data yatim; `add-document`.
- Berkas bersama: ED-1025 rev (`depends_on`); ED-1027 rev (`ArchiveService::index`, method lain).

## Subtask (usulan)
| Judul | Layer | AC |
|---|---|---|
| [BE] Archive - validasi induk, tanpa siklus, nama kembar, guard null select lokasi & transaksi terkait | BE | 1-11 |
| [QA] Skenario validasi pindah/rename folder & 500 Archive | QA | 1-14 |

## Catatan implementasi
- (BE, 2026-10-10) Bukan penyimpangan AC; untuk QA: cek siklus put-in kini aturan di `id_archive_parent` (`SelfFolderRule`,
  sumber dari `id_archives` atau `name`), jadi urutan antar-400 put-in = `ARCHIVE404` → `ARCHIVE403` → `ARCHIVE406` → `ARCHIVE405`
  (sebelumnya `ARCHIVE403` lebih dulu). Induk tidak sah (422) juga melewati `ARCHIVE405` nama di create-folder dan `PUT archives/{id}`.
