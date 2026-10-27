---
key: ED-1293
epic: ED-1022
title: Archive FE - filter Verification Status kembali ke root seperti filter lain (halaman & picker)
status: approved
type: task
batch: ED-1293
fe_modules: [Archive]
new_fe_modules: []
be_modules: []
qa_model: sonnet
contract: null                # endpoint tidak berubah
depends_on: []                # ED-1250 Done
size: S
---
## 1. Ringkasan

Feedback QA manusia: filter **Verification Status** (ED-1250), satu-satunya yang membuat list tetap di folder, kini **kembali ke root seperti filter lain**: diterapkan **sendiri** dari folder mana pun = list root (breadcrumb "Archive",
halaman 1) yang **tetap terstruktur** (folder root + dokumen root yang sesuai, bukan hasil datar). Sesudahnya nilai tetap aktif saat
membuka folder / breadcrumb / Back. Bersama Show Related Transaction tetap di folder (K-2 b). Sama di picker. Hanya FE (helper `archive.function.js`).

Sumber `jira.md` (developer 2026-10-27): J-0 teks feedback · J-1 keputusan 1 a (struktur tetap, hanya FE) · J-2 2 a (tetap aktif saat
navigasi = ED-1250 K-1 a) · J-3 3 a (picker ikut) · J-4 4 a (item baru, ED-1250 tetap Done) · J-5 "digabung filter lain: tidak berubah".

## 2. Aturan bisnis

| BR | Aturan | Sumber |
|---|---|---|
| BR-1 | Menerapkan Verification Status **sendiri** (tanpa key lain; lihat BR-6) dari level mana pun → `search` = `{verificationStatus}`, `idArchive: null`, halaman 1; breadcrumb "Archive", "+" dan `access` seperti root | J-0; filter lain `archive.function.js:146-149`, ED-1125 K-3 a |
| BR-2 | Root **terstruktur**: hanya baris level root (semua folder root + dokumen root cocok), `breadcrumbs` `[]`; angka Document Verified & ringkasan tetap semua. BE sudah begini | J-1; `ArchiveService.php:51-65,135-136,145-157` |
| BR-3 | Sesudahnya filter **tetap aktif** saat buka folder, breadcrumb, "Back to Archive" (`idArchive` folder + key, isi terstruktur tersaring, popover & indikator menyala); filter lain di-reset. Tidak berubah | J-2; `archive.function.js:163-166`; `ArchivePage/index.js:239-244` |
| BR-4 | Menerapkan **ulang** di dalam folder (ganti nilai, Cari / Enter dengan nilai terisi, termasuk Enter tanpa membuka popover; nilai dari store form) = BR-1 → root | akibat J-0; Cari di folder tanpa filter = root (ED-1125 K-3 a); `archive.function.js:140-142` |
| BR-5 | Mengosongkan: Reset ✕ = `search` null, tetap di folder (`apiHook.js:152`, tidak berubah); hapus nilai select lalu Cari = tetap di folder (K-1 a); scan QR = hanya `query`, root, filter kosong (`ArchivePage/index.js:475-484`) | ED-1250 BR-8; K-1 a |
| BR-6 | `{verificationStatus, showRelatedTransaction}` saja = **tetap di folder** seperti sekarang (K-2 b); `showRelatedTransaction` sendiri tetap di folder. `showRelatedTransaction: false` (centang dilepas) bersama Verification Status → **K-3** | K-2 b; `archive.function.js:110-116` |
| BR-7 | Bersama `query`/`name`/`type`/`updatedAt`/`updatedBy`: datar dari root; buka folder dari hasil → hanya `{verificationStatus}` (tidak berubah) | J-5; ED-1250 AC-16 |
| BR-8 | **Picker** (`ArchiveTable`) ikut BR-1..7 lewat helper yang sama (root picker, breadcrumb picker "Archive"). Tetap: dibuka kosong (ED-1250 K-5 a), request halaman tidak terpengaruh, folder asal/terpilih/tanpa View tidak bisa dibuka, pilihan tujuan tidak di-reset, scan picker, `listKey` ED-1150 | J-3; `ArchiveTable/index.js:74-96`; `ArchiveMoveModalForm.js:19-53`; `ModalAdd/index.js:64-66` |
| BR-9 | Selebihnya tetap (saringan server, `Opname Document`, display setting, halaman 1, refresh) | ED-1250 BR-3, 5, 6, 9-11, 13 |

## 3. Model data

Tidak ada tabel/kolom, Updater, display setting, permission, kode pesan.

## 4. Endpoint v5

| Route | Controller@method | FormRequest | Service | Permission | Status |
|---|---|---|---|---|---|
| GET `document-archive/archives` | `ArchiveController@index` | - | `ArchiveService::index` (BR-2) | `List Archive` | sudah ada, tidak berubah |

## 5. Perubahan FE - Archive

Aturan `getArchiveFilterRequest` (nilai dari store form):

| Isian (kosong dibuang) | Filter aktif sebelumnya | Request | Hasil |
|---|---|---|---|
| `{verificationStatus}` | apa pun | `{search, page: 1, idArchive: null}` | **baru**: root terstruktur |
| `{verificationStatus, showRelatedTransaction: true}` | apa pun | K-2 b: tanpa `idArchive` | tetap di folder |
| `{verificationStatus, showRelatedTransaction: false}` | apa pun | K-3 a: `idArchive: null` (`search` apa adanya) | root |
| `{showRelatedTransaction}` (true/false) | - | tanpa `idArchive` | tetap di folder |
| ada key lain | - | `idArchive: null` | datar |
| kosong | hanya kedua key itu | K-1 a: `{search: null, page: 1}` tanpa `idArchive` | tetap di folder |
| kosong | lainnya / tidak ada | `idArchive: null` | root |

| Bagian | Perubahan |
|---|---|
| `archive.function.js:105-151` | `archiveFolderFilters` tetap 2 key. Isian berisi: tetap di folder bila semua key ∈ `archiveFolderFilters` **dan** `showRelatedTransaction` ada, kecuali `false` bersama Verification Status (K-3 a); selain itu `idArchive: null`. Isian kosong (`:148`) tidak berubah (K-1 a). JSDoc `:105-139`; navigasi tidak berubah |
| `ArchivePage/index.js:226-232`, `ArchiveTable/index.js:69-76` | hanya komentar (VS sendiri = root) |
| Test | asersi berbalik diubah, bukan dihapus; root dari dalam folder dicek `idArchive` `toBeNull()` (bukan `toBeFalsy`); ≥1 test gagal dengan kode lama |
| Dok FE | Baru `docs/specs/Archive/029-enh-filter-verification-status-ke-root.md`; `_module.md` indeks, `:113`, `:157-158`; `_api.md:1485` "(rev ED-1293)" (tabel mode BE `:448` tetap); 027: satu baris penunjuk ke 029 |

Asersi ED-1250 yang berbalik:

| Tempat | Menjadi |
|---|---|
| `archive.function.test.js:181-186` (`{VS}` tetap), `:256-270` (store terisi + isian kosong tetap) | `idArchive: null`. Tidak berbalik: `:176-179`, `:188-193` (`{VS, SRT}`), `:195-201`, `:230-238` (K-1 a) |
| `ArchivePage/index.test.js:1314-1326`, `ArchiveTable/index.test.js:402-426` (pilih di folder → tetap) | root; navigasi dari root |
| `ArchivePage/index.test.js:1361-1378`, `ArchiveTable/index.test.js:428-443` | setup: pilih di root → buka folder → kosongkan |
| e2e `ED-1250-filter-verifikasi.spec.js` `applyFilter` di folder: AC-14 `:759,:781`, AC-15 `:872,:874,:881`, AC-16 `:909`, AC-18 `:1093,:1215,:1259,:1290`, AC-22 `:1413,:1453,:1464,:1518` | tunggu root; isi folder tersaring lewat navigasi. Regresi `:970-1037` (SRT, SRT + VS) tidak berbalik |
| ED-1250 spec BR-4 (FE), BR-12, AC-14 kal. 2, AC-17, AC-18; dok 027 `:55`, `:78-86`, Daftar tes UI 5, 8, 14 | diganti ED-1293 (folder ED-1250 tidak disunting; dicatat `run.md`) |

## 6. Acceptance criteria

DB `api_sidomaju`, QA_USER (preset `Opname Document`), fixture `QA50-` (§6a), oracle = DB / `fixture.php children`.

| AC | Given / When / Then | Layer | BR | Cek |
|---|---|---|---|---|
| AC-1 | Di `QA50-A` tanpa filter, Unverified → Cari → 1 GET, `search` = `{"verificationStatus":"UNVERIFIED"}`, tanpa `id_archive`, `page=1`; breadcrumb "Arsip"; baris level root (semua folder root, `QA50-RDU` ada, `QA50-RDV` tidak; semua halaman), `total` = oracle; indikator menyala. Sama: Verified dari `QA50-MIX-SUB` (level 3) | FE | 1, 2 | e2e + unit |
| AC-2 | Dari AC-1 buka `QA50-A`, `QA50-MIX`, breadcrumb `QA50-A`, "Arsip"; `QA50-PRIV` (403) → "Kembali ke Arsip": tiap request membawa key, `id_archive` = folder; isi = semua subfolder + dokumen cocok (oracle); popover menampilkan nilai | FE | 3 | e2e + unit |
| AC-3 | Filter aktif hasil navigasi di `QA50-A`: ganti nilai → root; Enter di kolom cari kosong tanpa membuka popover → `{verificationStatus}`, root; `QA50-B` halaman 2 → ganti nilai → root `page=1` | FE | 4 | e2e + unit |
| AC-4 | Filter aktif hasil navigasi di `QA50-A`: kosongkan select → Cari → (K-1 a) tanpa `search`, `id_archive` A; Reset ✕ → tanpa `search`, tetap A; scan QR → hanya `query`, root, select kosong | FE | 5 | e2e + unit |
| AC-5 | Di `QA50-A` Show Related Transaction saja → tetap A; + Verified → (K-2 b) kedua key, `id_archive` A tetap, anak A + transaksi terkait, dokumen hanya verified; centang dilepas (`false`) + Cari → (K-3 a) root. Filter aktif hasil navigasi di A + centang → tetap A | FE | 6 | e2e + unit |
| AC-6 | Di `QA50-A` Verified + Nama `QA50-MIX` (atau Type) → `id_archive` null, datar; buka folder dari hasil → hanya `{verificationStatus}` | FE | 7 | e2e |
| AC-7 | Picker Pindahkan (⋯ dokumen fixture) dan Pilih Folder: kosong saat dibuka; di `QA50-C` picker Unverified → tanpa `id_archive`, breadcrumb picker "Arsip", folder root semua, request halaman tetap; buka folder/breadcrumb picker → key ikut; ganti nilai → root; kosongkan (K-1 a) / Reset / + Show Related Transaction (K-2 b) → tetap; + Nama → datar; asal/terpilih/`QA50-PRIV` tidak bisa dibuka; tujuan tidak hilang; Pindahkan ke `QA50-DEST` sukses (SQL) | FE | 8 | e2e + unit |
| AC-8 | Unit helper: 7 kasus §5 + store form + navigasi (`{verificationStatus}`/`null`); halaman & `ArchiveTable` memakai helper yang sama | FE | 1, 4-8 | unit |
| AC-9 | Archive (root, folder, hasil cari, akses ditolak), kedua picker, drawer Display Setting terbuka tanpa error / 5xx / galat konsol | FE | 9 | e2e |
| AC-10 | Regresi: e2e ED-1125 dan ED-1069 hijau; unit ED-1150 (`getArchiveListKey`, remount saat folder/filter berganti) hijau; salinan e2e ED-1250 yang tidak berbalik (AC-13, 19, 20, 21, 22, 25; `[user2]` tanpa filter) hijau; full suite | FE | 8, 9 | e2e + unit |
| AC-11 | BE tidak disentuh: `git diff` BE dari `8d996000d` tanpa berkas item ini | BE | 2 | git |
| AC-12 | Alur sama dengan filter lain (Daftar tes UI 029) | FE | 1-8 | manual |

### 6a. Data uji & lingkungan

| Hal | Isi |
|---|---|
| Fixture | Salin `features/ED-1250-…/qa/e2e/{fixture.php,fixtures.json,routes.json}` + spec e2e ke `qa/e2e/` folder ini (prefix `QA50-`, preset `QA50-ROLE-E2E-NOOD`, jurnal sendiri, `up` menyapu sisa `QA50-`) = suite item ini |
| Di luar baris sendiri | Berjurnal, dipulihkan persis: snapshot cds `documentArchive` (tidak diubah; C-8 tetap: memuat `verificationStatus` + `documentVerified`), `user_roles` QA_USER2, bahasa QA_USER. Tidak ada tabel dikosongkan / di-reset |
| Lingkungan | DU-6 a (developer tidak memakai Archive/opname/display setting/role/karyawan `api_sidomaju` dan QA_USER* selama run); lisensi Salesman Activity aktif; `force_login` mencabut token lain user QA; e2e ED-1250 lama tidak bersamaan; e2e ED-1150 (`sam`) tidak dijalankan |

## 7. Subtask

| Kunci | Judul | Layer | AC | Module |
|---|---|---|---|---|
| ED-1294 | [FE] Archive - filter Verification Status kembali ke root di list | FE | 1-6, 8, 9, 12 | Archive (`archive.function.js`, `ArchivePage` + test, dok) |
| ED-1295 | [FE] Archive - filter Verification Status kembali ke root di picker Pindahkan / Pilih Folder | FE | 7-10 | Archive (`ArchiveTable` + test) |
| ED-1296 | [QA] Skenario filter Verification Status kembali ke root | QA | semua | - |

## 8. Keputusan untuk developer

| # | Pertanyaan + situasi | Opsi + akibat | Rekomendasi | Risiko |
|---|---|---|---|---|
| K-1 | Hapus nilai Verification Status di dalam folder lalu Cari | **Terjawab a** (2026-10-27): tetap di folder, seperti Reset | - | - |
| K-2 | Verification Status + Show Related Transaction saja | **Terjawab b** (2026-10-27): tetap di folder seperti sekarang; Verification Status sendiri → root | - | - |
| K-3 | Centang Show Related Transaction dilepas, Verification Status terisi | **Terjawab a** (2026-10-27): dihitung sendiri → root (`showRelatedTransaction: false` = tidak aktif) | - | - |

Bukan pertanyaan: J-1..J-5; dok FE task baru 029 (pola 013/015); e2e ED-1250 dicatat usang (pola AC-22/24).

## 9. Di luar cakupan

- BE, data, permission, display setting; filter lain, scan QR, Reset, mode Pilih; menyunting folder ED-1250; `forceRender` popover `Search`.

## Tambahan Gate 1 (2026-10-27, disetujui developer)
- Keputusan: awal 1 a / 2 a / 3 a / 4 a (`jira.md`), K-1 a, K-2 b, K-3 a. DU-5 a dan DU-6 a (ED-1022 gate1 §6) tetap berlaku.
- Run dibatasi developer: **hanya sampai dev FE + review konvensi selesai**; QA FE menunggu instruksi developer (runner berhenti `paused`, `run.md` `phase: qa-fe`).
