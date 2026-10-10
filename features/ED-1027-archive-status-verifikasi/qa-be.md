---
key: ED-1027
scope: be
round: 2
verdict: PASS
counts: {pass: 17, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

Verdict **PASS** (ronde 2 revisi, 2026-10-09). Dua defect ronde 1 revisi sudah diperbaiki dan terbukti: **D-1** (AC-24 / BR-19, saringan display setting lolos lewat varian huruf besar/kecil/spasi akhir nama modul) dan **D-2** (komentar "id 1139" memutus ED-1025 AC-25). Tidak ada defect baru. D-3 (Bug ED-1122) dan D-4 (ED-1070) adalah 500 pra-ada di luar diff, tetap dicatat, bukan defect ED-1027.

- Cakupan tahap BE revisi: 15 AC `[BE]` (AC-1..12, 22, 23, 24) + sisi BE dari 3 AC `[BE+FE]` (AC-20, AC-28, AC-29). Dihitung 18: **17 PASS, 1 MANUAL (AC-29 BE, skenario ED-1028 usang), 0 FAIL**.
- Pemeriksaan ulang defect lebih dulu: `X-12` + `AC-24` + `X-13` (`work/qa-1027-rev2/d1.log`) PASS; `ED-1025 AC-25` PASS 39 asersi.
- Run penuh `qa/` (qa_lib.php + 10 berkas `scenario*.php`): **28 PASS, 0 FAIL, 1 SKIP (X-3 isolasi tenant, 1 DB)**, 25.508 asersi, 4 mnt (`work/qa-1027-rev2/full-1.log`; laporan `work/qa-http/ED-1027-20261009151004.json`). Ronde 1 revisi: 26/1/1.
- Skenario baru ronde 2 (permanen, `qa/scenario10-display-setting-filter.php`, 26 KB): X-12 diperluas dengan varian `DOCUMENTARCHIVE%20%20` dan `dOCUMENTaRCHIVE%20%20%20%20`; **X-14** baru (baris `documentArchive` tidak ada: GET tanpa OD membuat baris dari config tetapi respons tanpa entri; varian nama; pemegang OD melihat entri).
- Regresi (record, bukan hitungan ED-1027): ED-1024 **26/26**; ED-1025 **40/40** (AC-25 kini PASS); ED-1026 **39/39**; ED-1029 **29 PASS + 1 SKIP** (1 DB, 0 x 5xx dari 712 request); ED-1028 **2 PASS, 20 FAIL = skenario usang milik QA ED-1028** (semua `buat sesi` 422, alur draft dihapus ED-1026; identik dengan ronde 1; hanya dicatat).
- Server php-fpm tanpa reload (`BE_RELOAD_CMD` kosong); kode yang diuji = working tree (4 berkas BE, tidak distash/direvert/dikomit). Hasil X-12 memastikan kode baru termuat.
- DB `api_sidomaju`: sidik jari 17 tabel (jumlah baris + CRC semua kolom) **identik sebelum dan sesudah** (`work/qa-1027-rev2/fp-before.json` = `fp-after.json`, juga sama dengan akhir ronde 1); 0 baris `QA27%`, 0 baris `role_permissions` `created_by QA27%`, tabel opname 0 baris, tanpa berkas jurnal tersisa.

## Hasil per AC

Subtask: ED-1095 = AC-22, 23 · ED-1096 = AC-24 · ED-1033 = AC-1 · ED-1039 = AC-2,3,4,7,12 · ED-1045 = AC-5,6,8,9,10,11 · ED-1099 [QA] = seluruh skenario. Bukti = baris hasil runner (`work/qa-1027-rev2/full-1.log`).

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-22 (rev) | `scenario9`: role 29 (List Archive saja, lokasi SMR) memanggil `GET archives` 11 bentuk (root, folder, halaman 2, search, type, sort name, sort documentVerified) **tanpa** lalu **dengan** `Opname Document` (grant sementara role yang SAMA, dicabut persis). Tanpa: `columns` tanpa documentVerified, 0 baris ber-key `document_verified`, tanpa `document_verified_summary`, tanpa jejak di body; dengan: ketiganya ada; hasil dengan-OD dikurangi field verifikasi identik (JSON + urutan id) dengan tanpa-OD | PASS | `AC-22 PASS 118 asersi`; "tanpa hitung" dibuktikan dari diff `ArchiveService.php:37-40,68-72` (waktu 247 vs 285 ms hanya catatan) |
| AC-23 (rev) | `scenario9` (EN & ID): role 29 → `GET verifications`, `/{F}`, id tak ada, dokumen, SP tanpa View, SJ lokasi lain, id aneh: semuanya **403 GE0114** parameter "Opname Document", tanpa `result` (permission dicek sebelum id/lokasi/View). Dengan grant 200; urutan 404/400/403 benar; grant dicabut → 403 lagi; role 3 bawaan 200; QA_USER2 sungguhan (tanpa OD) 403 | PASS | `AC-23 PASS 94`; `X-8 PASS 139`; `X-9 PASS 33` |
| AC-24 (rev) | `scenario10`: baris `documentArchive` dicadangkan utuh. Tanpa OD (role 29): GET tanpa documentVerified (current & available, urutan sama, tanpa jejak di body); PUT (urutan dibalik + `history` disembunyikan) 200 → DB `current_columns` = [type,name,updatedAt,salesman,**documentVerified**], `available_columns` terbalik dengan documentVerified kembali sesudah Salesman; objek palsu "HACKED" diabaikan; pemegang OD melihat entri dan boleh menyembunyikannya; PUT tanpa-OD sesudahnya tak memunculkannya; modul `supplier` dan GET `customer` tak berubah; baris dipulihkan persis. **Varian nama modul** (X-12, 6 varian: `DOCUMENTARCHIVE`, `documentarchive`, `DocumentArchive`, `documentArchive%20`, `DOCUMENTARCHIVE%20%20`, `dOCUMENTaRCHIVE%20%20%20%20`): GET tanpa entri, PUT mempertahankan entri di DB. Baris tidak ada (X-14): GET tanpa entri | PASS | `AC-24 PASS 45`; `X-12 PASS 21 ... membocorkan: []; menghapus lewat PUT: []`; `X-13 PASS 24`; `X-14 PASS 21` |
| AC-1 | `scenario1`: kolom documentVerified sesudah Salesman/sebelum history, `sort=false`, judul en/id; `column_display_settings` = config; Updater terdaftar. X-1: Updater dijalankan sungguhan (idempoten, modul lain utuh); `updater_logs` id 197 dicadangkan lalu dikembalikan persis | PASS | `AC-1 PASS 25`, `X-1 PASS 19` |
| AC-2 | `scenario2`: root/isi folder/pencarian (query, Type, Folder), sort name & documentVerified (diabaikan): `document_verified` folder = oracle SQL + angka tangan, dokumen `{0\|1,1}`, folder tanpa View `null`; user Semarang & superadmin; data nyata + fixture. Sisi BE AC-20 ikut di sini | PASS | `AC-2 PASS 20041` |
| AC-3 | `scenario2`: `is_active` -1/0 tak dihitung; put-in/hand-over/receive nyata tetap dihitung | PASS | `AC-3 PASS 37` |
| AC-4 | `scenario2`: ringkasan sama di 11 bentuk panggilan = header `GET verifications` = oracle | PASS | `AC-4 PASS 63` |
| AC-5 | `scenario3`: `verified+unverified=total`, 7 baris tipe urutan/label = select types (EN/ID), tipe 0 ada, jumlah = header = oracle | PASS | `AC-5 PASS 259` |
| AC-6 | `scenario3`: header `/{F}` = `document_verified` F, tipe = oracle & angka tangan, folder kosong 0/0 | PASS | `AC-6 PASS 885` |
| AC-7 | `scenario4`: 5 keadaan user (SMR, JOG, SMR+JOG, role 1, role 2) = oracle; X-2 (hak View); QA_USER2 sungguhan di X-8 | PASS | `AC-7 PASS 349`, `X-2 PASS 57` |
| AC-8 | `scenario3`: 404 ARCHIVE400, 400 ARCHIVE417, 403 ARCHIVE407 (lokasi, View, turunan + nama folder penolak), nonaktif 404, 18 id aneh < 500, kode di dua bahasa; dicek sebagai pemegang OD | PASS | `AC-8 PASS 109` |
| AC-9 (rev) | `scenario3`: role 29 → list 200, verifications* 403 GE0114 "Opname Document"; role 6 → ketiganya 403 tanpa angka; role 1 200; tanpa token 401; `app/Sql` tanpa perubahan, seed memuat Opname Document + List Archive. Tukar lisensi sungguhan = MANUAL Gate 2 epic (EPIC K-2 b) | PASS (+ bagian lisensi MANUAL) | `AC-9 PASS 38` |
| AC-10 (rev) | `scenario5` (stateless): 7 sesi via `GET opnames/folders` → `POST opnames/scan` → `POST opnames`; maks 3 terbaru urut waktu turun; field = kontrak & baris DB; **`scope` tepat `is_root, folder_name, subfolder_count`** = `GET opnames/{id}`; visibilitas K-4 + EPIC K-1 a (superadmin, SMR, JOG, SMR+JOG, role 2, role 29 403 tanpa OD / 200 dengan OD, folder dinonaktifkan, pembuat huruf besar); angka sesi tetap | PASS | `AC-10 PASS 229` |
| AC-11 | `scenario5`: sesi mencakup subfolder, potongan snapshot, folder dipindah/dinonaktifkan; X-4 (induk berputar, folder dihapus fisik) | PASS | `AC-11 PASS 213`, `X-4 PASS 37` |
| AC-12 | `scenario5`: scan tidak menulis; Confirm A: dF2 verified, F/P/ringkasan/tipe +1; 3 hari kemudian tak di-reset; B timpa -2; C Lanjut; Not found/Invalid tak mengubah angka. X-7: sesi nyata 25 dokumen di folder nyata naik tepat 25 di semua tingkat | PASS | `AC-12 PASS 96`, `X-7 PASS 62` |
| AC-20 (BE) | Sisi BE regresi: sort name, filter Type, pencarian, paginasi, scan/put-in/hand-over/receive tak berubah: `scenario2` AC-2/AC-3, AC-22 (11 bentuk identik), regresi ED-1024/25/26/29 hijau. Sisi FE: tahap FE | PASS (sisi BE) | `AC-2 PASS 20041`; `AC-22 PASS 118`; regresi di atas |
| AC-28 (BE) | Kunci `last_sessions[].scope` tepat 3 (tanpa `first_name`/`other_count`), isi = `GET opnames/{id}`, contoh A/B/C terukur (AC-10). Format Scope id/en & e2e: tahap FE | PASS (sisi BE) | `AC-10 PASS 229` |
| AC-29 (BE) | Skenario ED-1028 dijalankan: **2 PASS / 20 FAIL usang** (semua `buat sesi`: `q28_session` memakai alur draft → 422; milik QA ED-1028, hanya dicatat). Sisi BE tak bisa dibuktikan penuh | MANUAL (usang, pemilik QA ED-1028) | `work/qa-1027-rev2/reg-ED-1028.log`: `Ringkasan: PASS=2 FAIL=20` |

Pemeriksaan tambahan PASS: X-5 (keadaan asli QA_USER & user tanpa lokasi = oracle; median `GET archives` 397 ms, `verifications` 332 ms, `{id}` 332 ms), X-6 (sapuan 20 folder nyata × 4 keadaan user, 2264 asersi), X-8, X-9, X-10 (tanpa OD key `search`/`sorts` kolom verifikasi diabaikan; pemegang OD berfungsi, 129 asersi), X-11 (`GET archives/{id}` tanpa 4 key verifikasi tanpa OD, 51 asersi).

## Defect

Tidak ada defect baru. Status defect ronde 1 revisi:

- **D-1 (AC-24 / BR-19 / K-9 a, subtask ED-1096) — DIPERBAIKI, terbukti.** `ColumnDisplaySettingService.php` kini memakai `permissionFilter()` (`:155-170`, dipakai `hiddenColumns()` `:132`): nama modul dicocokkan dengan `module_name` baris yang ditemukan lewat `where` yang sama (kolasi `latin1_general_ci`) memakai `strcasecmp(rtrim(...))`, dan juga dengan nama kiriman bila baris belum ada. Bukti: `X-12 PASS 21` (6 varian, GET tanpa entri, PUT mempertahankan entri), `X-14 PASS 21`. Cek tambahan SQL: varian beraksen (`documentArchíve`) dan tab (`documentArchive\t`) tidak mengenai baris, jadi tidak ada jalur bocor tambahan.
- **D-2 (rendah, ED-1095) — DIPERBAIKI, terbukti.** Komentar "id 1139" dihapus dari `VerificationService.php` (grep "1139" kosong); `ED-1025 AC-25 PASS 39 asersi`; regresi ED-1025 40/40.
- **D-3 (500 PRA-ADA, Bug ED-1122; bukan defect ED-1027)** — `PUT setting/display-columns/{modul}` dengan isi sama di **detik yang sama** → 500 `FAILED`: `update()` mengembalikan 0 baris sehingga `ColumnDisplaySettingService.php:121` (di `update()`) melempar. Sama untuk pemegang OD dan modul lain; jeda 1 detik 200. Terlihat lagi di `X-13` (catatan).
- **D-4 (500 PRA-ADA, ED-1070; bukan defect ED-1027)** — `GET archives?sorts[0][sortBy]=...` bentuk array → 500 `json_decode() expects parameter 1 to be string, array given` (`CustomizeBuilder::scopeSortAll` ← `MyHelper::toSnake:333`), untuk kolom apa pun, dengan/tanpa OD. Tidak ada jalur bocor (500 sebelum efek). Terlihat lagi di `X-10` (catatan).

Catatan non-defect (di luar cakupan item, tidak mengubah verdict): (a) `GET setting/display-columns` (index) dan respons `PUT` (memanggil `index()`) mengembalikan baris `documentArchive` mentah termasuk entri `documentVerified` bagi pemanggil tanpa OD — spec §4/§9 menyerahkannya ke ED-1028 K-9 a; FE tidak membacanya. (b) `GET opnames`, `GET opnames/{id}`, `/{id}/documents` masih `permission_v5:List Archive` (`api.php:35-45`; milik ED-1028). (c) `PUT current_columns` berupa skalar oleh pemanggil tanpa OD tidak dijaga (perilaku lama; merusak seluruh baris, bukan penghapusan terarah). (d) `GET verifications/{id dokumen}` teks `ARCHIVE417` menyebut opname (O-1 ronde lama, sesuai spec). (e) PUT ke baris yang tidak ada = 500 `FAILED` (kontrak §4, perilaku lama).

## Isolasi tenant & permission

- **Permission** (endpoint berubah/baru): `GET archives` (List Archive): QA_USER 200, QA_USER2 (Sales Supervisor, ada List Archive) 200, QA_USER2 role 22 (tanpa) 403 GE0114 (X-9). `GET verifications` & `/{id}` (Opname Document): role 3 200; role 29 dan QA_USER2 role asli 403 GE0114 `parameter` "Opname Document"; dengan grant sementara 200; tanpa token 401. `setting/display-columns/{modul}` tanpa permission route; saringan per OD di AC-24/X-12/X-13/X-14.
- **Cabang "seluruh modul" peta saringan (O-3 jenis 2)**: belum ada entri di `PERMISSION_FILTERS` item ini (milik ED-1028 AC-28), jadi tidak bisa lewat HTTP. Dicek tingkat servis dengan subkelas yang menimpa konstanta (skrip sementara di scratchpad, tanpa tulis DB; baris `column_display_settings` 361 → 361): tanpa OD (role 5) `show()` dan `update()` untuk modul uji (nama persis, varian huruf besar + spasi akhir, entri `columns: []`) → `ErrorMessageException` 403 `GE0114` `parameter` "Opname Document", baris tidak dibuat; pemegang (role 3) lolos (`show()` null; `update()` baris tak ada = 500 `FAILED` sesuai kontrak §4). Pengujian HTTP penuh tetap tugas QA ED-1028.
- **Isolasi tenant: tidak bisa diverifikasi** — `QA_DBS` hanya `api_sidomaju`; `X-3` SKIP. Pengganti tinjauan kode diff: `PERMISSION_FILTERS` = konstanta tak berubah, `$canVerify`/`$verificationCounts` variabel lokal per request, `hasPermission` dihitung per request, `permissionFilter()` membaca baris lewat koneksi tenant request itu dan tanpa `static`/cache; tidak ada properti pembawa data lintas request. Risiko kebocoran antarworker rendah; uji dua DB tetap dianjurkan bila DB kedua disiapkan.
- **Tinjauan kebenaran diff BE** (4 berkas, `ArchiveService.php` tidak berubah sejak ronde 1): kontrak vs respons nyata cocok (§1 `columns`/`document_verified`/`document_verified_summary`, §1b detail, §2/§3 403 `GE0114`, §4 GET disaring + PUT mempertahankan, `available_columns:null`, list tak dikirim tak ditulis); route `verifications*` `permission_v5:Opname Document` (`api.php:54-57`); tanpa tabel/kolom baru dan tanpa Updater baru; tanpa tulis data di endpoint verifikasi (tanpa transaksi/rollback yang perlu diuji; PUT display setting satu UPDATE); kolom v3 tidak berlaku. Tidak ada penyimpangan tersisa.

## Yang tidak bisa diverifikasi (dan kenapa)

1. Isolasi tenant dua DB (hanya satu DB di `QA_DBS`).
2. Tukar lisensi Salesman Activity sungguhan (AC-9): `PROFILES` kosong; dibuktikan lewat role & seed, sisanya MANUAL Gate 2 epic (EPIC K-2 b).
3. Regresi BE ED-1028 (AC-29): 20 dari 22 skenarionya usang (alur draft); hanya dicatat.
4. Perilaku dengan **data opname developer sungguhan**: tabel opname kosong saat run; kemampuan itu dibuktikan lewat simulasi di ronde 1 (sesi simulasi dihapus, baris `archives` dipulihkan).
5. Cabang "seluruh modul" peta saringan lewat HTTP (belum ada entri; ED-1028 AC-28); hanya tingkat servis (lihat di atas).
6. Semua AC `[FE]` dan sisi FE `[BE+FE]` (AC-13..19, 21, 25..27): tahap FE; e2e dan unit test FE tidak disentuh.
7. Penolakan izin/classifier: **tidak ada** di ronde 2.

## Data (dibuat, dihapus, dipulihkan) — ronde 2

- **Dibuat lalu dihapus**: fixture `QA27-*` (archives, archive_documents, archive_locations, archive_permissions) per AC; baris `role_permissions` grant sementara `Opname Document` (role 29 dan role QA_USER2; `created_by` QA27, id di jurnal); sesi opname QA per jurnal. Sesudah run: 0 sisa.
- **Diubah lalu dipulihkan persis**: role/lokasi/bahasa QA_USER dan role QA_USER2 (jurnal); baris `column_display_settings` `documentArchive` (cadangan utuh; X-14 menghapusnya sementara, GET membuat ulang dari config, lalu dihapus + dimasukkan kembali dari cadangan, `updated_at`/`created_by` asli) dan `supplier`; `updater_logs` id 197; kolom verifikasi `archives` baris yang ditandai sesi QA (tidak ada data developer non-default).
- **Bukti pemulihan**: sidik jari 17 tabel (jumlah baris + CRC semua kolom) identik sebelum/sesudah: `work/qa-1027-rev2/fp-before.json` = `fp-after.json`; `diff` kosong.
- **Skenario diubah**: hanya `qa/scenario10-display-setting-filter.php` (variant X-12 + X-14 baru). Berkas lain tak disentuh. Kode aplikasi, spec, kontrak, e2e dan test FE tidak disentuh.
- **Tidak ada data developer yang dihapus/diubah.** Dua token OAuth aktif milik user lain (dibuat 2026-10-08) tidak disentuh; semua token run QA di-log-out.

## Riwayat ronde

- Ronde 1 (2026-10-07): PASS. 12 AC `[BE]` PASS; 20 PASS + 1 SKIP pada run penuh; regresi ED-1024 26/26, ED-1025 30/31 dan ED-1026 36/37 (hanya AC-1 usang).
- Ronde 1 revisi (2026-10-09): **FAIL**. AC (rev) 22/23 PASS, AC-24 FAIL (D-1 varian nama modul), AC lama 1-12 PASS dengan skenario diperbarui (stateless, tahan data developer); D-2 komentar id 1139 memutus ED-1025 AC-25; D-3/D-4 500 pra-ada dicatat. Run penuh 26 PASS / 1 FAIL / 1 SKIP, 25.466 asersi; regresi ED-1024 26/26, ED-1025 39/40, ED-1026 39/39, ED-1029 29+1 SKIP, ED-1028 2/20 (usang).
- Ronde 2 revisi (2026-10-09): **PASS**. D-1 dan D-2 diperbaiki dan terbukti (X-12 6 varian PASS, X-14 baru PASS, ED-1025 AC-25 PASS); run penuh 28 PASS / 0 FAIL / 1 SKIP (X-3, 1 DB), 25.508 asersi; regresi ED-1024 26/26, ED-1025 40/40, ED-1026 39/39, ED-1029 29+1 SKIP, ED-1028 2/20 (usang, dicatat); sidik jari DB identik; D-3 (Bug ED-1122) dan D-4 (ED-1070) tetap dicatat pra-ada; cabang "seluruh modul" dicek tingkat servis.
