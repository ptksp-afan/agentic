---
key: ED-1250
scope: be
round: 1
verdict: PASS
counts: {pass: 12, fail: 0, manual: 0, not_verifiable: 2}
be_server: fpm
---
## Ringkasan

QA BE ronde 1 (long run epic ED-1022, keputusan Gate 1 K-1 a, K-2 b, K-3 gugur, K-4 a, K-5 a, DU-5 a, DU-6 a berlaku). Semua 12 AC `[BE]`
(AC-1..AC-12) PASS terhadap server php-fpm `API_URL` / DB `api_sidomaju`; tidak ada defect; 6 berkas BE tidak diubah QA.
Run penuh terakhir: `PASS=16 FAIL=0 SKIP=1` (C-0 + 15 AC/ekstra; SKIP = ISO-1 isolasi tenant, QA_DBS hanya satu DB) -
`work/ed1250/final-r1b.out`, laporan JSON `work/qa-http/ED-1250-20261024220909.json`.

- Skenario permanen: `features/ED-1250-archive-filter-verification-status/qa/` (`qa_lib.php` helper, `scenario0-init.php`, `scenario1-display-setting.php`,
  `scenario2-list-hierarchy.php`, `scenario3-robust.php`, `scenario4-probe.php`, `scenario5-extra.php`, `scenario9-teardown.php`). Jalankan:
  `"$PHP_BIN" scripts/qa-http/run.php ED-1250` (run penuh ±3-7 menit; `--only=AC-n` untuk sebagian).
- Oracle = hitungan dari tabel mentah `archives`/`archive_documents`/`archive_locations` tanpa kode BE (`q50_expect`), pada user lokasi Semarang
  (preset OD + karyawan SMR) dan superadmin (role 1); data nyata 34.531 dokumen aktif + fixture `QA50-` (18 folder + 31 dokumen, K-4 a:
  `is_verified`/`verified_at`/`verified_by` diisi langsung, tanpa sesi opname).
- Hasil lama tidak terlihat: AC-1 membaca config baru, `GET archives` mengembalikan entri baru; tidak perlu `be-reload.sh` (php-fpm, `BE_RELOAD_CMD` kosong).
- Dua pengamatan di luar cakupan diff (bukan defect ED-1250, lihat "Pengamatan"): O-1 key `search` ber-titik tak dikenal = 500 (pra-ada, kontrol
  `foo.bar`), O-2 `GET setting/display-columns` (index) memuat entri filter untuk non-pemegang (pola ED-1027). Orkestrator/developer memutuskan.
  Catatan syarat verdict "tanpa 500": satu-satunya 500 yang terlihat adalah O-1, dipicu probe key sampah yang bukan bagian spec dan terjadi juga
  pada key sembarang; tidak ada 500 pada request sah mana pun.

## Hasil per AC

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | `--only=AC-1`: config `documentArchive.php` (current & available: `verificationStatus` tepat sesudah `type`, bentuk entri, tanpa `initial_value`/`required`); `GET archives` `result.queries` (user lokasi + superadmin); baris cds = config; Updater dijalankan **di dalam transaksi lalu rollback** (hasil = config, baris tidak berubah); terdaftar tepat sekali di `Updaters/config.php`, kunci terbesar, epoch = nama berkas (WIB) | PASS | 43 asersi; `work/ed1250/final-r1b.out` |
| AC-2 | Root + VERIFIED/UNVERIFIED, semua halaman (pagination 1000), user lokasi + superadmin: baris level root (induk null di DB), himpunan folder = hasil root tanpa filter, dokumen = dokumen root tanpa filter yang statusnya sama, `total` = oracle, `breadcrumbs` `[]`, `access` null, urutan type,name; asersi tangan fixture (dR/dR3/dR2, ER, ALV, PRIV, JOGR) | PASS | 3.628 asersi |
| AC-3 | `id_archive` = P, F, E, AV, MX, MXc (level 4), NV, SV, OTH, ER, ALV + kedua nilai: himpunan = oracle, semua subfolder sama dengan tanpa filter (kosong 0/0, all-verified, tanpa dokumen cocok), semua baris anak langsung (DB), `breadcrumbs`/`access` = tanpa filter; daftar tangan F: UNVERIFIED = subfolder + dF2,dF4(Taken),dF5(NULL),dF6(is_verified 2) (+dJF superadmin); VERIFIED = subfolder + dF1,dF3(Handed Over); dNEG(-1)/dDEL(0)/SX tidak pernah | PASS | 564 asersi |
| AC-4 | Root, F, MX, cari `name=QA50-` x kedua nilai: untuk setiap baris hasil berfilter, `document_verified` sama dengan tanpa filter (tidak ada baris tambahan), dokumen `{verified 1/0,total 1}` sesuai filter; `document_verified_summary`, `columns`, `queries` identik | PASS | 11.054 asersi |
| AC-5 | User lokasi Semarang: PRIV (root) & SP (di F) Izin On tanpa View tetap tampil di kedua nilai, `document_verified` null, `access.view` false, baris identik tanpa filter; dokumen/folder JOG tidak tampil; `id_archive`=PRIV/SP/SPc + filter = 403 `ARCHIVE407` + `result.denied_by` identik tanpa filter; SV (View diberi) terbuka; cari `name` memuat dokumen di folder tanpa View sesuai lokasi (BR-12 ED-1025). Superadmin 1 dan 2: PRIV terbuka, SJ tampil, `access.view` true, angka terisi | PASS | 132 asersi |
| AC-6 | Datar: `type=6` dan `"6"` + VERIFIED (semua level, oracle); `type=FOLDER` + tiap nilai identik `type=FOLDER` (urutan, isi, total); `name=QA50-` + tiap nilai (semua folder fixture cocok tetap + dokumen status sama, juga di folder tanpa View); `updatedBy`; `type=7+name`; `showRelatedTransaction` + `id_archive=F` (semua subfolder, `related_transactions` hanya di dokumen, = tanpa filter, breadcrumbs F); root + related = hirarki root | PASS | 264 asersi |
| AC-7 | F dengan pagination 2 (>= 3 halaman) dan 3, cari `name` pagination 7, root pagination 500: gabungan halaman = oracle, tanpa duplikat, `total`/`current_page` konsisten, `sorts` name desc/asc berlaku lintas halaman (dibandingkan ORDER BY DB), tanpa sort = type,name | PASS | 134 asersi |
| AC-8 | Preset `QA50-ROLE-LIST` (List Archive tanpa OD): 11 bentuk (root hal. 1-2, F, F hal. 2, MX, name, name+F, type FOLDER, type 7+name, related+F, sorts) x key VERIFIED/UNVERIFIED = 200, seluruh `result` identik tanpa key (kecuali URL paginasi), `queries` tanpa entri, tanpa kolom/ringkasan/`document_verified`; pembanding OD (field verifikasi + entri `verificationStatus` dibuang) identik hasil non-OD untuk 11 bentuk; QA_USER2 sungguhan (preset LIST) 200 identik; preset NOLIST = 403 `GE0114` dengan/tanpa key | PASS | 210 asersi |
| AC-9 | Pemegang (lokasi + superadmin), 20 nilai salah (`"verified"`, `"X"`, 1, 0, true, false, null, `""`, `[]`, `["VERIFIED"]`, `{"a":1}`, `{"VERIFIED":1}`, spasi, 1.5, non-latin1, U+FFFD, ...) x 5 bentuk (root, F, type, name, F+name) = 200 identik tanpa key (id berurutan, total, halaman, breadcrumbs, access); `name` non-latin1 + VERIFIED = `name` non-latin1 saja; search mentah rusak / bukan objek / key ganda tanpa 5xx; `verificationStatus` sebagai query param di luar `search` diabaikan; kontrol: nilai sah menyaring | PASS | 654 asersi (+ catatan O-1, O-4) |
| AC-10 | Non-OD: `GET display-columns/documentArchive` current/available queries = tersimpan tanpa entri (urutan sama), kolom tanpa `documentVerified`, body tanpa jejak; OD: ada, sesudah `type`; `customer` dan `supplier` identik dengan/tanpa OD; `documentArchiveOpnameHistory` non-OD 403 `GE0114` (user lokasi dan QA_USER2), OD 200; index tanpa modul itu untuk non-OD. Langkah unpark-sesudah-ED-1251 (`documentArchiveOpnameResult`) belum berlaku: ED-1251 belum di-commit | PASS (+1 langkah tidak berlaku) | 39 asersi |
| AC-11 | PUT non-OD (urutan query dibalik, `updatedAt` hanya di available, item palsu `verificationStatus` di body): 200; DB current = `type, verificationStatus, name, showRelatedTransaction`, available = `updatedBy, showRelatedTransaction, updatedAt, type, verificationStatus, name`, objek entri = tersimpan (bukan palsu), `documentVerified` tetap; GET non-OD tanpa entri. Pemegang menyembunyikan entri dari current: GET archives tanpa entri tetapi **filter tetap berlaku** (VERIFIED/UNVERIFIED di F); PUT non-OD berikutnya tidak memunculkannya; pemegang menampilkan lagi. Baris cds dipulihkan persis (CRC seluruh tabel + isi baris) | PASS | 37 asersi |
| AC-12 | Probe in-process `ArchiveService::index` (Auth user QA, `DB::listen`), root dan folder level 3, user lokasi + superadmin, urutan tanpa/V/tanpa/U/tanpa: jumlah query sama dengan dan tanpa filter (lokasi: root 33, L3 40; superadmin: root 24, L3 29), tidak bertambah dengan jumlah baris (pagination 200 vs 20), filter menyaring di probe. Waktu (info): ±55-170 ms per panggilan (hangat), tanpa beda berarti dengan/tanpa filter | PASS | 38 asersi |
| Ekstra X-1 | Tepi `ColumnDisplaySettingService` untuk `queries`: varian nama modul (`DOCUMENTARCHIVE`, `documentarchive`, `documentArchive%20`) tanpa kebocoran, PUT tanpa list queries tidak menulis queries, `available_queries: null` (non-OD) 200 dan entri tetap, PUT lewat varian nama tidak membuat baris baru | PASS | 33 asersi |
| Ekstra X-2 | Filter bersama `query` (cari bebas), `updatedAt` (rentang), `name`+`updatedAt`: folder = hasil key lain, dokumen = hasil key lain yang statusnya sama (tidak bocor lewat OR); SQL dibaca: `... and (type <> 2 or type is null or is_verified = 1)` satu grup AND | PASS | 64 asersi |

AC `[FE]`/`[BE+FE]` (AC-13..AC-24) dimiliki stage fe; tidak dicek di sini. AC-22 (BE+FE): bagian regresi BE-nya sudah dijalankan sekarang (lihat "Regresi").

## Defect

Tidak ada.

## Pengamatan (bukan defect ED-1250; diserahkan ke orkestrator)

- **O-1 (pra-ada, 500)**: `GET archives` dengan `search` ber-key ber-titik tak dikenal (`{"foo.bar":"X"}`, `{"filter.verificationStatus":"VERIFIED"}`,
  `{"a.b":"X"}`) = HTTP 500 `Unknown column ... in 'WHERE'` dari `CustomizeBuilder::scopeSearchAll` (`searchOwnTable` untuk key ber-titik). Key sembarang
  menghasilkan 500 yang sama, kode jalur ini tidak diubah diff ED-1250, request tidak dikirim FE. Kandidat A-13 / tiket ED-1070. Terdokumentasi di AC-9 (kontrol).
- **O-2 (sama pola ED-1027)**: `GET setting/display-columns` (index) mengembalikan baris `documentArchive` utuh, jadi non-pemegang membaca nama entri
  `verificationStatus` dan `documentVerified` di `current_*`/`available_*` (bukti: pemanggil preset LIST). Hanya konfigurasi, tanpa data; `show`/`update`
  tersaring sesuai BR-10 dan kontrak §2 (index hanya menyaring entri seluruh modul). Halaman daftar FE (`DisplaySettingPage`) tidak merujuk `current_*`/`available_*`. Saran: saring `index()`
  dengan peta yang sama bila ingin konsisten.
- **O-3 (usang, dicatat sesuai AC-22)**: ED-1027 `scenario9` AC-22 FAIL karena asersinya membandingkan `result` non-OD dengan OD tanpa field verifikasi **termasuk
  `queries`**; kini pemegang OD mendapat entri `verificationStatus` (BR-10, yang memang diminta). AC-8 di sini mengganti pembandingnya (entri dibuang) dan lulus untuk 11 bentuk.
- **O-4 (perilaku umum)**: ejaan key lain (`verification_status`, `VerificationStatus`, `verificationstatus`, `"verificationStatus "`) dan key sembarang (`zzz`) = 200 dan list **datar tanpa saringan**
  (total 34.560 baris semua level untuk QA_USER bawaan); bukan filter ini, sama seperti key tak dikenal mana pun. Hanya key persis `verificationStatus` yang dipakai FE.
- Diterima (A-1): PUT display setting isi sama = 500 tidak dipicu (semua PUT uji mengubah isi). A-8 (pagination tanpa batas) dipakai wajar (1000).

## Isolasi tenant & permission

- **Tenant**: `QA_DBS=api_sidomaju` (satu DB) -> ISO-1 SKIP "tidak berlaku"; tidak terverifikasi (lihat bawah). Tidak ada state statis baru di diff (konstanta, satu `where`); php-fpm memproses
  tiap request terpisah.
- **Permission** (tidak ada endpoint baru): `List Archive` tanpa -> 403 `GE0114` tidak berubah (preset NOLIST, dengan/tanpa key). Tanpa `Opname Document`: 200 (bukan 403), key diabaikan, `queries`
  tanpa entri; `documentArchiveOpnameHistory` 403 `GE0114` (user lokasi dan QA_USER2); pemegang 2xx. Role: preset `QA50-ROLE-{OD,LIST,NOLIST}` dan role sistem 1/2; role developer tidak diubah.

## Tinjauan diff BE terhadap spec/kontrak

- `ArchiveService::index`: key dibaca dari `search` sebelum `searchAll`/`isSearching` (nilai sah hanya bila `Opname Document`, `in_array` ketat `VERIFIED`/`UNVERIFIED`), key selalu di-`unset`; hasilnya satu grup
  `where` (`type <> FILE or type is null or is_verified = 1` / `<> 1 or null`) sebelum paginate. Setara `type = FOLDER or (...)` pada data (type hanya 1/2), dan memenuhi BR-3 rev (baris non-dokumen tidak pernah hilang);
  kontrak §1 sudah disesuaikan. `counts()` tidak dipakai menyaring (angka tetap, AC-4). `$searchRequest` menjadi array sesudah `unset`; `scopeSearchAll` menerimanya (AC-6/9).
- Nilai non-latin1 pada key filter tidak memicu `searchMatchesNothing` (key sudah dibuang), sesuai Catatan implementasi dan BR-14; `name` non-latin1 + filter tetap kosong seperti sebelumnya (AC-9).
- `queries` tanpa OD dibuang di respons (`result.queries`) dan di display setting; PUT mengembalikan entri sesudah pendahulunya; entri "seluruh modul" lewat `isWholeModule()` (ED-1028 AC-28 PASS, `documentArchiveOpnameHistory` tetap 403).
- Updater: salinan pola ED-1033 (hapus baris cds + `getDisplaySetting`); dibuktikan di AC-1 dengan transaksi + rollback. Tanpa skema baru, tanpa kolom v3 (Archive tidak ada di v3), tanpa query tambahan (AC-12).
- Kontrak vs respons nyata: bentuk entri `queries`, `breadcrumbs`/`access`, `ARCHIVE407` + `denied_by`, `GE0114`, `document_verified` null `access.view` false: semua cocok; tidak ada selisih nama field/envelope/kode.

## Regresi (bagian BE AC-22; AC-22 sendiri milik stage fe)

| Skenario | Hasil |
|---|---|
| ED-1027 scenario1 (AC-1, X-1), scenario2 (AC-2, AC-3, AC-4), scenario9 (AC-23, X-10, X-11), scenario10 (AC-24, X-12, X-13, X-14) | 12 PASS (`work/ed1250/reg-ed1027-r1.out`; JSON `work/qa-http/ED-1027-20261024214121.json`) |
| ED-1027 scenario9 AC-22 | FAIL **usang** (O-3): satu-satunya selisih = entri `verificationStatus` di `queries` pemegang OD; digantikan AC-8 |
| ED-1028 scenario9 AC-28 (cek seluruh modul `:149/:167`) | PASS, 219 asersi (`work/ed1250/reg-ed1028-r1.out`) |

## Data uji, perubahan di luar baris sendiri, teardown

- Dibuat dan dibuang per AC: baris `archives`/`archive_documents`/`archive_locations`/`archive_permissions` berawalan `QA50-`, role `QA50-ROLE-OD/LIST/NOLIST` (+`role_permissions`, `user_roles`). Sapu sisa `QA50-` di awal tiap AC.
- Diubah sementara dan dipulihkan persis (jurnal `.journal-q50-*.json`, dibuktikan di akhir tiap AC): `user_roles` QA_USER (imansudjadi, role 3) dan QA_USER2 (adi, role 5);
  `employees.is_all_location`/`employee_locations` QA_USER; baris cds `documentArchive` (snapshot utuh, AC-11/X-1). Tidak ada baris yang bukan milik QA dihapus/di-reset; tidak ada tabel dikosongkan.
  Dampak samping: penghitung auto-increment `roles`/`user_roles`/`column_display_settings` maju (rollback Updater juga mengambil satu nomor).
- Regresi ED-1027/1028 memakai fixture `QA27-`/`QA28-`, sesi opname dan preset sendiri, semuanya dibuang/dipulihkan oleh skenario itu.
- **Teardown C-8 PASS** (`scenario9-teardown.php`, run penuh + dicek ulang sesudahnya): baris cds `documentArchive` masih id 2260, `updated_at` 2026-10-24 21:27:41 (sama dengan awal run), current_queries = `name,type,verificationStatus,updatedAt,showRelatedTransaction`,
  available_queries memuat `verificationStatus`, `documentVerified` di current & available columns; tidak ada sisa `QA%` (archives, dokumen, permission, role, role_permissions); tidak ada `.*journal*`/`.fixture-journal.json` basi di `features/*/qa`;
  CRC `archives` (non-QA50), `archive_*`, `roles`, `role_permissions`, `user_roles`, `employees`, `employee_locations`, seluruh `column_display_settings`, `users(id,language)` sama dengan awal; tabel opname 3/4/896 dan
  `is_verified=1` = 22 seperti awal; token QA aktif 0 (runner: "masih aktif: 0"). `updater_logs` 99 -> 99. DU-5 a: beda cds sebelum-vs-sesudah Updater (1683 -> 2260, dibuat developer) dan `updater_logs` bukan FAIL.

## Yang tidak bisa diverifikasi (dan kenapa)

1. **Isolasi tenant** (langkah 6): `QA_DBS` hanya `api_sidomaju`; ISO-1 SKIP. Perlu dua DB tenant untuk dijalankan.
2. **AC-10 langkah unpark sesudah ED-1251**: `documentArchiveOpnameResult` belum ada di `PERMISSION_FILTERS` (ED-1251 belum di-commit); diperiksa ulang bila item ini di-unpark sesudahnya.
3. AC-12 waktu: hanya informasi, tidak ada ambang di spec.

Bukan dijalankan sesuai keputusan: e2e ED-1150 (`run-sam.sh`, login `sam`) tidak dipakai (spec Tambahan Gate 1); tidak menyentuh folder ED-1251.

## Riwayat ronde

- Ronde 1 (2026-10-24): 12/12 AC BE PASS, 0 defect; regresi ED-1027 (12 PASS, 1 usang) dan ED-1028 AC-28 PASS; teardown C-8 PASS; pengamatan O-1..O-4.
