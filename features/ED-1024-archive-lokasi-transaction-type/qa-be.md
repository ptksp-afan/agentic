---
key: ED-1024
scope: be
round: 2
verdict: PASS
counts: {pass: 15, fail: 0, manual: 1, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

QA BE ronde 2 untuk Archive - visibilitas per lokasi kerja & daftar transaction type terpusat. Kegagalan ronde 1 dicek ulang
lebih dulu: **D-1 (put-in dengan field bertipe array = 500) sudah diperbaiki** dan **skenario X-7 kini PASS**. Regresi penuh
semua `qa/scenario*.php`: **26 PASS, 0 FAIL, 0 SKIP, 0 BLOCKED** (16 skenario AC + 9 pemeriksaan tambahan X-1..X-9 + 1 skenario
baru R2-1 untuk perbaikan). Semua 15 AC `[BE]` (AC-1..AC-12, AC-14, AC-15, AC-18) PASS; AC-13 (`[BE+FE]`) bagian http/BE PASS,
bagian tukar lisensi sungguhan **MANUAL (Gate 2 epic)** karena `PROFILES` kosong. AC-16/AC-17 `[FE]` milik scope fe. Tidak ada
HTTP 500 pada seluruh run ini. Verdict **PASS**.

- Satu-satunya berkas yang berubah sejak ronde 1: `Modules/V5/Http/Requests/DocumentArchive/Document/PutInFolderRequest.php`
  (mtime 20:39; 18 berkas BE lain tidak berubah sejak 20:03). Direview di bagian "Review perbaikan ronde 1".
- Skenario permanen: `features/ED-1024-archive-lokasi-transaction-type/qa/scenario1..6*.php` (+ `qa_lib.php`); ronde ini menambah
  `scenario6-round2.php` (R2-1). Run penuh: `work/qa-runs/r2-full.txt`, laporan runner `work/qa-http/ED-1024-20261007034516.json`.
- Lingkungan: `BE_SERVER=fpm` (`be-reload.sh` no-op); hasil memuat kode baru (422 pada put-in bertipe salah), jadi tidak ada reload.
  DB uji `api_sidomaju` (satu-satunya di `QA_DBS`).
- `QA_USER2` tidak bisa login (500 `GE0109`, pra-ada, secrets tidak diubah). Seperti ronde 1, keadaan user (employee JOG/JOG+MGL/SMR/
  tanpa employee, role 1/2/3/6/16/18/29, bahasa EN/ID) dibuat dengan menukar role/employee/bahasa `QA_USER` sementara lewat jurnal
  pemulihan (`qa_lib.php::q1_with_user`), lalu dipulihkan.
- Pemulihan DB: `CHECKSUM TABLE` + hitungan baris `archives`, `archive_locations`, `archive_documents`, `user_roles`,
  `employee_locations`, `roles`, `locations`, `permissions`, `role_permissions`, plus baris user QA/employee/bahasa/`is_active`
  lokasi dan role 2, **identik sebelum dan sesudah run** (`work/qa-runs/r2-state-before.txt` = `r2-state-after.txt`) dan sama dengan
  keadaan ronde 1; 0 baris `QA01-%`; semua token run di-log-out (masih aktif: 0). Log Laravel pada jendela run hanya berisi
  galat tabel `websockets_statistics_entries` yang tidak ada (derau pra-ada, bukan dari Archive).

## Hasil per AC

Skenario = id di runner. "Independen" = hitungan dari DB lewat query terpisah (bukan dari BE).

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | HTTP root sebagai role 3 + employee JOG; JOG+SMR; MGL | PASS | `AC-1` (15 asersi): Backup Arsip, CABANG - JOGJA, PUSAT - MAGELANG tampil, CABANG - SEMARANG tidak; total = hitungan independen DB (770); 0 baris di luar scope; JOG+SMR = gabungan; MGL tanpa kedua CABANG |
| AC-2 | HTTP `archives?id_archive=Backup Arsip`, JOG lalu JOG+MGL | PASS | `AC-2` (9): JOG hanya SMLYK, SMLYK - BRANGKAS; JOG+MGL keenam folder; `result.total` = hitungan DB; folder `is_active<=0` tidak tampil |
| AC-3 | HTTP `search={"query":"SML"}` semua level + dokumen bertag MGL | PASS | `AC-3` (16): SMLHO/SMLSMG tidak muncul untuk JOG; semua baris hasil lolos cek DB (all=1 atau lokasi JOG); `DO-MGL/2608` 0 hasil untuk JOG; kontrol superadmin melihat semuanya |
| AC-4 | Sisip `QA01-TANPA-LOKASI` + `QA01-SMR`; role 1, 2, 1 tanpa employee, 16, 3 | PASS | `AC-4` (16): `is_superadmin` 1/2 melihat keduanya; role 16+JOG dan role 3+JOG tidak; role 3 + employee semua lokasi: folder tanpa lokasi tetap tidak tampil (3 tidak di-bypass). Dibuang, 0 sisa |
| AC-5 | HTTP root & Backup Arsip, role 3 tanpa employee | PASS | `AC-5` (6): root persis `[Backup Arsip, PUSAT - MAGELANG]`, total = hitungan DB (hanya `is_all_location=1`) |
| AC-6 | 15 permintaan berbasis id ke CABANG - SEMARANG / dokumen {SMR} sebagai JOG; superadmin sesudahnya | PASS | `AC-6` (66): list `id_archive`, show, history, PUT, rename, DELETE, create-folder, put-in (induk dan `id_archives` SMR, campuran, `+name`), PUT `id_archive_parent` baru di luar scope = semua 403 `ARCHIVE407`, termasuk body kosong/tak valid (403 sebelum 422/400); snapshot DB tidak berubah; superadmin 200 (`ARCHIVE207/206/201/204/203`) |
| AC-7 | HTTP id acak sebagai role 3, JOG, role 1 tanpa employee | PASS | `AC-7` (56): list `id_archive`, show, history, update, rename, delete = 404 `ARCHIVE400` pada ketiga keadaan; juga put-in/hand-over/receive |
| AC-8 | SO uji + DO terkait; tag DO dipindah JOG -> SMR | PASS | `AC-8` (10): JOG melihat DO di `related_transactions`; sesudah tag DO = {SMR}: JOG tidak, superadmin ya, user SMR ya; tag dipulihkan persis |
| AC-9 | HTTP types, bahasa ID lalu EN; label baris; BR-13 | PASS | `AC-9` (33): `options` = `FOLDER,6,7,8,9,29,31,222`, `default` null; label ID/EN sesuai AC; label kolom Type baris = label opsi untuk 7 tipe di kedua bahasa; tipe tanpa label = angka (tidak 500) |
| AC-10 | Dokumen Billing nyata `is_active=-1` di-put-in lewat `name` ke PUSAT - MAGELANG | PASS | `AC-10` (20): filter `{"type":222}` menemukannya dengan label "Penagihan"/"Billing"; `{"type":6}` tidak; DB parent=PUSAT, `is_active=1`, history place/move; dipulihkan persis |
| AC-11 | HTTP cetak PDF SO dan Billing nyata; `store` in-process (BR-14) | PASS | `AC-11` (31): SO: 1 baris `type=2, is_active=-1`, lokasi = lokasi SO, `transaction_type=6`, cetak ulang tetap 1; Billing: `is_all_location=1`, tipe 222; PDF valid `%PDF-`; `store` tipe PO/999/tanpa tipe tidak membuat baris dan tanpa error |
| AC-12 | HTTP 10 route x role 6, 29, 16, 18, 1, tanpa token | PASS | `AC-12` (85): tanpa token 401 `GE0111`; role 6: semua 403 `GE0114` dengan `parameter` tepat; role 29: 3 GET 200, sisanya 403 dengan parameter benar; role 16/18: put-in/hand-over/receive lolos, create/update/rename/delete 403 (role 16); DB tidak berubah oleh penolakan |
| AC-13 | (bagian BE) role tanpa `List Archive` 403 `GE0114`; simulasi tanpa seed; kode seed & guard FE | PASS (bagian BE); **MANUAL** (tukar lisensi sungguhan) | `AC-13` (92): role 6 403 `GE0114` tanpa `result`; tanpa seed (permissions 1081-1090 dihapus sementara, dipulihkan persis), role 3 dan 1 di 10 route = 403 `GE0114`, bukan 500; `ApplicationSetupService` memuat `permission_salesman.sql` hanya untuk `SALESMAN_ACTIVITY`; FE `menus.js` / `routes.js:2086-2094` memuat guard. Sisa MANUAL: layar/menu tanpa lisensi, tukar lisensi sekali di Gate 2 epic (spec) |
| AC-14 | HTTP create/PUT/put-in di bawah CABANG - JOGJA {JOG} dengan lokasi di luar induk dan subset | PASS | `AC-14` (55): create `[SMR]`, `[JOG,SMR]`, semua lokasi = 400 `ARCHIVE402`; subset `[JOG]` 200; PUT keluar induk 400 `ARCHIVE402`; put-in dokumen {SMR}/semua/campuran/folder {SMR} (juga `name`) 400 `ARCHIVE403`; subset 200; DB tidak berubah pada penolakan |
| AC-15 | HTTP DELETE pohon X>Y>Z dengan dokumen aktif di Z/Y/X; X>Y{SMR}; urutan cek | PASS | `AC-15` (48): dokumen aktif di cucu/anak/langsung = 400 `ARCHIVE401`, DB tidak berubah; JOG pada X>Y{SMR} = 403 `ARCHIVE418`, superadmin 200 `ARCHIVE203` (X dan Y `is_active=0`); 418 sebelum 401; kedalaman 3; dokumen `is_active=-1` tidak menghalangi dan tidak diubah |
| AC-18 | HTTP put-in diri sendiri / nama kembar / `id_archives`+`name` | PASS | `AC-18` (31): 400 `ARCHIVE404` (juga dalam banyak id), `ARCHIVE405` (create, PUT, PUT pindah, put-in folder), `ARCHIVE406` (`id_archives`+`name`, tanpa sumber); tanpa 500, snapshot DB tidak berubah |
| AC-16, AC-17 | `[FE]` (e2e) | di luar scope be | dikerjakan scope fe |

Pemeriksaan tambahan (bukan baris AC; memperkuat BR/kontrak), semua PASS: X-1 BR-8 hand-over/receive/put-in lewat `name` pada dokumen {SMR}
oleh JOG = 200; X-2 pesan ARCHIVE400..407/418 ada dan beda antar bahasa; X-3 hasil mengikuti keadaan user terbaru pada 12 pergantian berurutan;
X-4 bentuk `result` semua endpoint sesuai `contract.md`; X-5 role ganda/role nonaktif untuk bypass; X-8 kontrol positif JOG; X-9 BR-9 cetak PDF SO {SMR}
oleh JOG tetap mencatat baris. **X-6** (BR-2) hanya karakterisasi, lihat O-1. **X-7 kini PASS** (ronde 1 FAIL = D-1).

## Pemeriksaan ulang kegagalan ronde 1

| Butir | Hasil ronde 2 | Bukti |
|---|---|---|
| D-1 langkah A: put-in `id_archive_parent` array | **Selesai**: 422 (sebelumnya 500), sebagai role 3 dan sebagai user JOG | `X-7` + `R2-1`: `asli`/`JOG | put-in parent array -> 422`; kunci `errors` = `id_archive_parent` |
| D-1 langkah B: put-in `id_archives` bersarang `[["x"]]` | **Selesai**: 422 (sebelumnya 500) | `X-7` + `R2-1`: 422, kunci `errors` = `id_archives.0` |
| Skenario X-7 | **PASS** (3 asersi: 13 input aneh x 2 keadaan, tanpa 5xx) | `work/qa-runs/r2-x7.txt` |

## Defect

Tidak ada defect terbuka. Tidak ada HTTP 500 pada seluruh run ronde 2 (26 skenario, 819 asersi; R2-1 memakai 18 input bertipe-salah x 2 keadaan).
D-1 (ED-1048, [BE]) ditutup.

## Review perbaikan ronde 1 (`PutInFolderRequest.php`) terhadap spec dan kontrak

- **Kontrak**: "422 (termasuk `id_archive_parent` bukan teks, atau elemen `id_archives` bukan teks/null: sebelumnya 500)" terpenuhi. Aturan baru
  `id_archive_parent` = `nullable|string`, `id_archives.*` = `nullable|string`; bila tipe salah hanya aturan tipe yang jalan, sehingga `SelfFolderRule`
  tidak lagi menerima array (penyebab D-1 di `SelfFolderRule.php:36-38`).
- **R2-1 (32 asersi)** membuktikan: parent array/objek/int/float/bool, `id_archives` bersarang/`[int]`/`[bool]`/`[objek]`/campuran `[id, array]`/`[id, int]`,
  dan kombinasinya = 422 (bukan 500), sebagai role 3 semua lokasi dan sebagai user JOG; DB (archives, archive_locations, archive_documents) tidak berubah
  oleh satu pun permintaan; `message` ada pada setiap 422.
- **Urutan authorize tetap benar**: user JOG dengan parent array berisi id folder {SMR}, atau `id_archives` `[dokumen {SMR}, array]`, atau parent {SMR} +
  `id_archives` bersarang = 403 `ARCHIVE407` (sebelum 422), karena `assertVisible()` hanya menyaring elemen skalar.
- **Aturan bisnis tidak berubah** untuk input bertipe benar (user penuh): dokumen {SMR} ke folder {JOG} 400 `ARCHIVE403`; folder ke dirinya 400 `ARCHIVE404`;
  `id_archives`+`name` / tanpa sumber / `id_archives: []` tanpa `name` 400 `ARCHIVE406`; DB tidak berubah. Kontrol positif tetap 200 `ARCHIVE204` (array, ke root,
  lewat `name`, `id_archives` berbentuk objek `{k: id}` = daftar seperti dulu) dengan `id_archive_parent`/`is_active` benar di DB. AC-14/AC-18 di regresi penuh juga PASS.
- **Catatan kecil (bukan defect, O-4)**: `id_archives` berupa skalar non-teks (int/float/bool, bukan array) tidak kena aturan tipe (`id_archives.*` hanya untuk
  array), jadi aturan bisnis (`ARCHIVE403..406`) dilewati dan hasilnya 404 `ARCHIVE400`, bukan 422. Tidak berbahaya: semua `id_archive` di DB bertipe teks
  numerik 30 karakter (`length=30` untuk 49.145 baris), sehingga angka JSON tidak pernah cocok dengan baris mana pun; tidak ada 5xx, tidak ada perubahan DB
  (R2-1: "id_archives int/float/bool skalar" dan "+ name" = 404 `ARCHIVE400`, asli dan JOG). Kontrak diam tentang kasus ini. Opsional bila developer ingin 422 juga untuk kasus ini: beri `id_archives` aturan tipe (array atau string).

## Perlu dikonfirmasi (bukan defect, tidak memengaruhi verdict)

O-2, O-3 dan celah pra-ada lain di Archive kini **dilacak di luar item ini pada Jira Bug ED-1069** (di bawah ED-1022).

- **O-1 BR-2 lokasi nonaktif** (masih terbuka untuk keputusan developer). BR-2 menulis "hanya lokasi aktif", tetapi `MyHelper::getUserLocation()` (sumber BR-2,
  dipakai `Archive::userScopeLocations`) tidak menyaring `locations.is_active` (`MyHelper.php:1491-1528`, `AuthHelper::permittedLocation`). Dengan lokasi JOG dinonaktifkan
  sementara, user JOG masih melihat CABANG - JOGJA dan baris bertag JOG (total tetap 770; `X-6` ronde 2 mengulang karakterisasi yang sama). Implementasi setia pada
  fungsi yang disebut spec; yang tidak cocok adalah kalimat "hanya lokasi aktif". Pilihan: perbaiki kalimat spec, atau tambahkan filter `is_active` (konsekuensi: beda dari layar lain
  yang memakai `getUserLocation`).
- **O-2 put-in ke `id_archive_parent` yang tidak ada** (id acak) = 200 `ARCHIVE204`, dokumen dipindah ke root. Tidak ada `exists` pada parent put-in; pra-ada, di luar BR-10,
  tetapi tidak intuitif (seharusnya 404/422). **Dilacak di ED-1069.** Tidak diubah oleh perbaikan ronde 1 (R2-1 tidak mengujinya; karakterisasi tetap seperti ronde 1).
- **O-3 dokumen archive yatim di "transaksi terkait".** `ArchiveService::relatedTransaction` (`:327` Billing; juga SO/DO/SI/Return/Payment/BG) memakai `$transaction->...`
  tanpa cek null: bila `archive_documents.id_transaction` menunjuk transaksi yang tidak ada, `showRelatedTransaction=true` = 500. Pra-ada (tidak diubah); data QA DB: 0 dokumen
  yatim untuk 5 tipe yang dicek. **Dilacak di ED-1069.**
- **O-4 (baru, kecil)** `id_archives` skalar non-teks = 404 bukan 422, lihat "Review perbaikan ronde 1".

## Isolasi tenant & permission

- **Isolasi tenant: tidak berlaku** - `QA_DBS` hanya berisi `api_sidomaju` (satu DB). Pengganti pada php-fpm: X-3 mengganti keadaan user (JOG, superadmin, tanpa employee, SMR)
  berulang tiga putaran pada sesi yang sama; setiap respons mengikuti keadaan terbaru (total = hitungan DB), jadi tidak ada cache lokasi antar-request. Review kode:
  `Archive::userScopeLocations()` dihitung per panggilan, tanpa `static` (D-3). Ronde 2 mengulang X-3 PASS.
- **Permission (K-4 a):** semua route Archive non-select dijaga `permission_v5` sesuai tabel §4 spec (`archives/add-document` memang tidak disentuh). AC-12/AC-13 ronde 2:
  user berhak 2xx (role 1; role 29 untuk 3 GET; role 16/18 put-in; role 16 hand-over/receive), tanpa permission 403 `GE0114` dengan `parameter` tepat, tanpa token 401 `GE0111`.
  `QA_USER2` tidak dapat login (lihat Ringkasan), diganti pertukaran role pada `QA_USER` (spec membolehkan).

## Review diff BE terhadap spec dan kontrak (tidak berubah sejak ronde 1, kecuali perbaikan di atas)

- Titik pakai ulang §3 ada dan tunggal: `Archive::scopeVisibleToUser`/`userScopeLocations`, `DocumentArchiveService::findVisible`/`assertVisible`,
  `ArchiveDocument::TRANSACTION_TYPES`/`transactionTypeOptions`/`transactionTypeLabel`/`isSupportedTransactionType`.
- Kontrak vs respons nyata: status, `code`, `msg_code`, bentuk `result` dan `parameter` cocok (X-2, X-4, AC-6/7/12). Pesan ARCHIVE407/418 ada di `en_EN.php` dan
  `id_ID.php` dengan teks persis kontrak (cek ulang `en_EN.php:3357-3365`). Urutan cek delete 407/404 -> 418 -> 401 sesuai kontrak; 403/404 `{id}` dicek sebelum validasi field.
- Rollback: penolakan dari `authorize()` dan rule terjadi sebelum transaksi; penolakan service (401/418/402/403/404/405/406) membuat DB tidak berubah (snapshot pada AC-6/14/15/18, R2-1).
- Skema: tidak ada Updater/migration/tabel baru (sesuai spec); `archives*` tidak dipakai di kode v3, jadi tidak ada kolom v3 yang harus dijaga.
- Catatan perilaku (sesuai catatan implementasi spec): hapus folder hanya diblok oleh dokumen `is_active > 0`; dokumen `-1`/`0` tidak lagi memblok (folder Backup Arsip nyata berisi 11.926
  dokumen `-1`, sehingga penghapusan folder semacam itu kini lolos bila tak ada dokumen aktif).

## Yang tidak bisa diverifikasi (dan kenapa)

- Tukar lisensi sungguhan tanpa `SALESMAN_ACTIVITY` (AC-13 bagian lisensi/FE): `PROFILES` kosong, manual di Gate 2 epic sesuai spec; yang dibuktikan: guard permission, simulasi tanpa seed,
  cek kode seed dan guard FE.
- Alur Billing/Shipment `documentHistory` (BR-9 jalur serah-terima internal): tidak dijalankan lewat HTTP (mengubah transaksi nyata); jalur cetak PDF (BR-9) dibuktikan di X-9.
- Aplikasi mobile (header `mobile-app`) yang memanggil hand-over/receive: tidak ada klien uji; role-nya wajib punya `Handover/Receive Document` (K-4 a).
- Login `QA_USER2`: server membalas 500 `GE0109` untuk kredensial yang tak dikenal (perilaku auth pra-ada, di luar fitur ini).
- `POST archives/add-document` (500, di luar cakupan spec §9) tidak dites.

## Riwayat ronde

- Ronde 1: 15 AC [BE] PASS, AC-13 bagian BE PASS + MANUAL lisensi; 9 pemeriksaan tambahan (8 PASS, 1 FAIL = D-1 put-in array -> 500); verdict FAIL karena aturan "setiap 500 = defect".
- Ronde 1: tiga butir perlu konfirmasi (O-1 BR-2 lokasi nonaktif, O-2 put-in ke parent tak ada = 200, O-3 dokumen yatim di transaksi terkait = 500 pra-ada).
- Ronde 2: D-1 diperbaiki (PutInFolderRequest -> 422), X-7 PASS, regresi penuh 26 PASS / 0 FAIL (+R2-1); verdict PASS; O-1..O-3 dipertahankan, O-2/O-3 dilacak di ED-1069; O-4 baru (kecil).
