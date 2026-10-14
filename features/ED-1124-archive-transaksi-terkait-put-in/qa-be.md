---
key: ED-1124
scope: be
round: 2
verdict: PASS
counts: {pass: 10, fail: 0, manual: 0, not_verifiable: 0}
be_server: fpm
---
## Ringkasan

Ronde 2: **PASS**. AC-1..AC-10 semua PASS, tanpa HTTP 500 baru. D-1 (ronde 1) selesai: tiga asersi lama "put-in `name` = nama folder" dibalik
ke 404 `ARCHIVE400` (milik ED-1124, putusan orkestrator), lalu ketiganya PASS dan seluruh suite AC-10 bersih. Kode BE tidak berubah sejak ronde 1
(`git diff` 3 berkas `Modules/V5`, mtime <= 14:46; hasil jelas memakai kode baru, `BE_SERVER=fpm` tanpa reload).

Satu temuan ronde 2 pada skenario SAYA sendiri (bukan cacat BE): AC-7 bagian 2 gagal sekali di run penuh pertama
(`prasyarat: id FQ3 < id D3`), karena `MyHelper::generateId` = kode + `time()` + 10 angka acak, sehingga dua id yang dibuat dalam detik yang sama
berurutan acak (ronde 1 kebetulan lulus). Diperbaiki di `qa/scenario3-select-name.php` (dua id dibangkitkan lalu diurut; folder FQ3 diberi yang lebih kecil
lewat `'id'`). Sesudahnya AC-7 PASS 6 kali berturut-turut dan run penuh akhir 10/10. Bukti kegagalan awal: `work/ED-1124/r2-suite-ED-1124.txt`.

Hasil suite (run ronde 2, `work/ED-1124/r2-suite-*.txt`, satu per satu): ED-1124 sendiri 10/10 (`r2-final.txt`), ED-1024 26/26, ED-1025 40/40, ED-1026 39/39,
ED-1027 28/28 (+1 SKIP isolasi tenant), ED-1028 24/24, ED-1029 40/40 (+1 SKIP isolasi), ED-1069 16/16, ED-1071 16/16. Semua "masih aktif: 0" (token dicabut).

Lingkungan: `api_sidomaju`, QA_USER saja (tanpa login `sam`; `force_login` hanya QA_USER), snapshot read-only ED-1149 sebelum (`snap-ED-1124-before`) dan sesudah
(`snap-ED-1124-r2-after`) **SAMA**; sisa QA = 0 (`snapshot.php --leftover`: "tidak ada sisa QA").

## Hasil per AC

Cara cek dan bukti AC-1..AC-9 tidak berubah dari ronde 1 (kode sama); dijalankan ulang penuh di ronde 2: `work/ED-1124/r2-final.txt` (run akhir) dan `r2-suite-ED-1124.txt`.

| AC | Cara cek | Hasil | Bukti |
|---|---|---|---|
| AC-1 | `scenario1-related.php` AC-1: dokumen uji tipe 7 (`QA124-D*`, `id_transaction` = DO nyata `0000000JOG17713767742255183568`) di folder F; folder G bernama N = `SI-JOG/2602/00208` (K-3) di folder QA, bukan root; GET `archives?id_archive=F&search={"showRelatedTransaction":true}` dan + `"query"` | PASS | 200, `related_transactions` sama dengan sebelum G ada, G tidak ikut, tiap baris ber-`document.transaction_type`; kontrol negatif `relatedTransaction()` `be.head` = `ErrorException ... 'transaction_type' of non-object`. 56 asersi |
| AC-2 | idem: baris K-3 yang sama diubah jadi `type=2` tanpa `archive_documents` | PASS | 200, tidak ikut; baseline = galat yang sama. 28 asersi |
| AC-3 | `scenario1-related.php` AC-3: list tipe 6/7/8 nyata + 6 dokumen uji tipe 7 dibanding `relatedTransaction()` `be.head` (refleksi); tanpa filter / `showRelatedTransaction:false` tanpa kunci | PASS | 58 dokumen nyata (53 berisi; tipe 6 8/8, 7 20/25, 8 25/25), semua identik. 150 asersi |
| AC-4 | `scenario2-putin.php` AC-4: sumber dihapus (dokumen, folder, subfolder) -> T dan root; campuran; campuran yang dulu 400 `ARCHIVE405`/`ARCHIVE403` dengan kontrol "aktif -> 400"; di luar lokasi; urutan 403 -> 422 -> 400 -> 404 | PASS | Semua dihapus: 404 `ARCHIVE400` "Dokumen tidak ditemukan", snapshot identik; campuran: 200 `ARCHIVE204` hanya yang aktif + history `move`/`take out`; di luar lokasi 403 `ARCHIVE407`. 74 asersi |
| AC-5 | `scenario2-putin.php` AC-5: `-1` via id_archives, name dokumen `-1`, name dokumen dihapus | PASS | 200, `is_active=1`, history `place` (`place,move` bila ada induk lama); `is_active` NULL ikut pindah. 28 asersi |
| AC-6 | `scenario3-select-name.php` AC-6: `id_archive_parent=0` (+ varian `" 0 "`, `00`, `0.0`, `-0`), tanpa parameter / `=`, induk berlokasi, id acak, bentuk salah | PASS | `0` -> 404 `ARCHIVE400`; tanpa parameter/`=` -> 200 semua lokasi aktif (vs DB); induk dihapus tetap 200; id acak -> 404; `[]=0` -> 422. 52 asersi |
| AC-7 | `scenario3-select-name.php` AC-7 (bagian 1: folder saja; bagian 2: FQ3 id lebih kecil + D3 senama, kini id ditentukan eksplisit) | PASS | Bagian 1: put-in name/hand-over/receive -> 404 `ARCHIVE400`, snapshot FQ/P/T identik. Bagian 2 (kontrol K-2 `where name=? limit 1` = FQ3): put-in name -> D3 pindah (`move`), hand-over -> `ARCHIVE209` status 2 + history `give`, receive -> `ARCHIVE210` status 3; FQ3 identik. 35 asersi, 6x berturut-turut PASS |
| AC-8 | `scenario3-select-name.php` AC-8 (`DocumentService::store` di harness) | PASS | folder FQ2 bernama N2: store -> 1 baris `type=2`, `is_active=-1` + 1 `archive_documents`; panggilan ke-2/3 memakai ulang baris itu; FQ2 identik. 28 asersi; regresi cetak ED-1024 AC-11 PASS |
| AC-9 | Flip ED-1069 X-4 a/b dan `scenario4-select-related.php` AC-10 "0" (ronde 1: sebelum FAIL, sesudah PASS); ronde 2: kedua skenario dijalankan lagi di suite ED-1069 | PASS | ED-1069 `r2-suite-ED-1069.txt`: X-4 PASS (15 asersi), AC-10 PASS (50 asersi). Bukti sebelum-flip ronde 1: `work/ED-1124/ac9-x4-before.txt` |
| AC-10 | D-1 dulu: `run.php ED-1025 --only=AC-14,AC-37` (57 dan 41 asersi) dan `run.php ED-1069 --only=AC-3` (57 asersi | 49 lunak), lalu seluruh suite ED-1024..1029, 1069, 1071 satu per satu | PASS | Semua suite 0 FAIL (angka di Ringkasan). Karakterisasi ED-1070 tetap PASS: ED-1025 X-8 (15 asersi) dan X-13 (4 asersi, 500 lama), ED-1027 scenario9 (AC-22 120, AC-23 96, X-10 139, X-11 53 asersi, semua PASS; X-10 mencatat 500 lama ED-1070) dan catatan 500 lama di ED-1027 X-13. Tanpa 500 baru. Target khusus: ED-1024 AC-10 (put-in name Billing `-1`) PASS, ED-1027 AC-3 PASS, ED-1024 AC-11 PASS. `work/ED-1124/r2-flip-ED-1025.txt`, `r2-flip-ED-1069.txt`, `r2-suite-*.txt` |

AC [FE]/[BE+FE]: tidak ada (`fe_modules: []`).

## Defect

Tidak ada defect terbuka.

**D-1 (ronde 1, AC-10) - SELESAI.** Penyebab: 3 skenario sibling menguji folder sebagai sumber lewat `name`, yang dihapus K-2 b. Putusan orkestrator (long run): karakterisasi milik ED-1124,
dibalik oleh QA ED-1124 (Gate 1 §3). Skrip yang diedit, hanya asersi folder-lewat-`name`, masing-masing dengan komentar "dibalik ED-1124 K-2 b":

1. ED-1025 `qa/scenario3-enforce.php:134-136`: put-in `name` = folder FV tanpa Update ke S: 403 `ARCHIVE408` -> 404 `ARCHIVE400`; tambah asersi snapshot `[Fv, Fu, D3, S]` identik.
2. ED-1025 `qa/scenario7-rev-hak.php:442-447`: put-in `name` = JA5 (folder) ke X: 200 + JA5 di X -> 404 `ARCHIVE400` + JA5 tetap di J.
3. ED-1069 `qa/scenario1-putin.php:168-188`: dua kasus `name=A->B`, `name=A->A` dikeluarkan dari daftar 400 `ARCHIVE404`; dipanggil terpisah dan diasersi 404 `ARCHIVE400`, sebelum
   asersi snapshot "tidak ada baris berubah" yang sudah ada (jadi snapshot identik mencakup keduanya). **Satu tambahan di luar baris yang disebut**: `title` AC-3 di berkas yang sama (`:140`)
   disesuaikan agar tidak menyebut dua kasus itu sebagai 400 `ARCHIVE404` (judul sebelumnya akan menyesatkan); tidak ada logika lain diubah.

Sensitivitas: asersi lama gagal di kode baru (ronde 1), asersi baru lulus; asersi yang sama di baseline (be.head) akan gagal (folder dikenali `name`); kontrol negatif baseline AC-7/AC-8 ada di X-3 (PASS).
Asersi lain di tiga skrip tidak disentuh dan tetap lulus.

**Bug di luar item:** O-1 (hand-over/receive lewat `name` pada dokumen dihapus tidak konsisten: `is_active`) sudah menjadi tiket **ED-1152**; tidak dilaporkan ulang. Tidak ada bug luar item baru di ronde 2.

Catatan (bukan defect): komentar usang `DocumentService.php:31-32` dan docblock `PutInFolderRequest.php:79` sudah dicatat reviewer; bagian select lokasi di kontrak ED-1024 belum menyebut 404 `ARCHIVE400`
(berasal dari ED-1069 K-4 a); FE tidak terpengaruh.

## Isolasi tenant & permission

- Review diff BE (skenario X-1 PASS, 9 asersi; diff ulang ronde 2 sama dengan ronde 1): 3 berkas `Archive.php`, `ArchiveService.php`, `SelectArchiveService.php`; tanpa Updater/route/lang/migration;
  tanpa `static`/`Cache::`/`config([...])`/`setDefaultConnection`; `ARCHIVE400` ada di `id_ID.php` dan `en_EN.php`. `findSources` cabang `id_archives` memakai `is_active <> 0 OR is_active IS NULL` dalam satu grup
  (kolom nullable); `findName` hanya `type = FILE`; `relatedTransaction` + `type = FILE` + `whereHas('document')`; `location()` memakai `filled()`. Rollback: put-in tetap di `DB::transaction`; penolakan tidak menulis (snapshot identik AC-4/AC-7).
- Tenant: `QA_DBS` hanya 1 DB: isolasi dua-tenant **tidak bisa diuji** (SKIP juga di ED-1027 X-3, ED-1029 X-ISO). Pengganti: tinjauan diff tanpa state statis/cache.
- Permission: tidak ada endpoint baru, route/middleware tidak disentuh. 403 `ARCHIVE407` diuji di AC-4 (QA_USER); QA_USER2 dicakup suite ED-1069 X-1 (login QA_USER2: put-in induk acak -> 422, lulus di AC-10); QA_USER2 tidak dipakai di skenario ED-1124 sendiri (instruksi sesi QA_USER saja).

## Yang tidak bisa diverifikasi (dan kenapa)

- Isolasi tenant antar-DB (satu DB di `QA_DBS`).
- Teks `ARCHIVE400` bahasa EN lewat HTTP: bahasa QA_USER tidak diubah; teks EN diperiksa di berkas bahasa (X-1) dan oleh suite ED-1069 yang mengganti bahasa.
- "Sumber dihapus di luar lokasi" memakai baris QA `is_all_location=0` tanpa tag lokasi (mengubah lokasi/role user di luar baris `QA124-` tidak diizinkan Gate 1); varian user ber-lokasi-terbatas nyata dicakup ED-1024 AC-6 (PASS).

## Data uji (dibuat dan dihapus) - ronde 2

- Baris `archives`/`archive_documents`/`archive_locations` berawalan `QA124-` (juga `id_transaction` `QA124TX*`): dibuat per skenario, dihapus di `finally` (`q124_purge`); sisa sesudah semua run = 0; tanpa baris yatim;
  tidak ada baris bukan-buatan-sendiri yang dihapus; tidak ada tabel dikosongkan. Fixture ED-1025/ED-1069 dibuat dan dihapus oleh skrip masing-masing (jurnal id).
- K-3 (folder/baris bernama nomor transaksi nyata `SI-JOG/2602/00208`, di dalam folder QA, bukan root, dihapus lewat id; `work/ED-1124/k3-ids.log`). Ronde 2 dua run ED-1124 penuh, masing-masing satu baris type 1 lalu satu baris type 2:
  `250224000117919932630941704297` (type 1), `250224000117919932655858768546` (type 2) [run pertama, AC-7 FAIL prasyarat]; `250224000117919957247754150034` (type 1), `250224000117919957272314142677` (type 2) [run akhir].
  Semua "dihapus lewat id". Ronde 1 (4 id) sudah dicatat di log yang sama.
- Suite sibling: ED-1069 X-4 membuat folder `SI-JOG/2511/00002` di dalam `QA69-FG`, dihapus lewat jurnal id oleh skripnya.
- Sesi: tiap run mencabut token yang dibuat ("masih aktif: 0"); tanpa login `sam`. Snapshot ED-1149 sebelum == sesudah ronde 2.

## Riwayat ronde

- Ronde 1: AC-1..AC-9 PASS, AC-10 FAIL (D-1: tiga skenario sibling vs K-2 b); skenario sendiri 10/10; `work/qa-http/ED-1124-20261014224835.json`.
- Ronde 1 -> 2: orkestrator memutuskan D-1 = karakterisasi milik ED-1124; QA membalik tiga asersi (ED-1025 AC-14, AC-37; ED-1069 AC-3), tanpa perubahan kode BE.
- Ronde 2: verdict PASS (10/10 AC). Temuan skenario sendiri: prasyarat urutan id AC-7 acak dalam detik yang sama, diperbaiki (id eksplisit); run akhir `work/qa-http/ED-1124-20261014233541.json`.
