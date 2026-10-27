---
epic: ED-1022
title: Next Opname Archive
status: draft
items: 6
digest: epics/ED-1022-brief/README.md
---
# Rencana item ED-1022 - Opname Archive

## Item
| id | tipe | judul (summary Jira) | slug | cakupan | rujukan brief | depends_on | ukuran |
|---|---|---|---|---|---|---|---|
| NEW-ED1022-01 | Task | Archive - Visibilitas folder per lokasi kerja & daftar transaction type terpusat | archive-lokasi-transaction-type | BE: satu scope "folder/dokumen yang boleh dilihat user" (lokasi kerja employee, bypass `is_superadmin` 1/2) untuk list, search, show, select; satu definisi transaction type archive untuk `DocumentService::store`, `select/document-archive/archive/types`, label; akses lisensi Salesman Activity (seed `permission_salesman.sql`). FE Archive: list & query Type mengikuti. Tanpa tabel baru | R-2, R-7, R-8, R-23; layar 01 (list) | - | M |
| NEW-ED1022-02 | Story | Archive - Folder Permission per folder (tab Permission & akses ditolak) | archive-folder-permission | BE: Updater flag Enable Folder Permission + tabel hak user per folder (View/Update/Delete/Store); endpoint baca/simpan permission folder, select user; penegakan di scope 01 (view) dan di update/rename/delete/put-in/move/store. FE Archive: `ArchiveDrawer` jadi bertab Detail / Permission (toggle, tabel User x hak, + Add User), layar 06 (pesan + Back to Archive; dokumen tetap muncul di pencarian dan bisa dipindahkan), menu "+" & menu baris di dalam folder mengikuti hak folder | G-1; layar 01 (menu), 02 (tab Permission), 06; S4 hal.1-3 | 01 | L |
| NEW-ED1022-03 | Story | Archive - Opname Document per folder (pilih folder, scan, konfirmasi) | archive-opname-document | BE: Updater tabel sesi opname (sesi, folder per sesi, hasil scan) + status verified per dokumen; endpoint Step 1 (child folder dalam scope + status opname hari ini), cek hasil scan (verified / not found / invalid), konfirmasi (timpa vs lanjut opname); permission baru. FE Archive: "+" Opname Document & menu baris Opname, modal 3 step (`ModalAdd`), scanner (`ArchiveScanner` + ikon `QRScanSearch`), `Segmented`, gaya baris invalid khusus modal ini | R-1, R-3, R-4, R-5, R-6, R-9..R-22; layar 01 (menu), 04, 04a-c; K-1, K-2 | 01, 02 | L |
| NEW-ED1022-04 | Story | Archive - Status verifikasi dokumen (kolom Document Verified, tab Verification, detail per transaction type) | archive-status-verifikasi | BE: kolom verified/total di list (display column `documentArchive` + Updater reset cache), endpoint ringkasan verifikasi (semua archive / per folder, per transaction type, 3 sesi terakhir). FE Archive: kolom Document Verified + ikon dokumen, ringkasan "verified / total" + tautan di header list, tab Verification di drawer bertab (dari 02), modal 05 | layar 01 (kolom, ringkasan), 02 (tab Verification), 05; K-3 | 01, 02, 03 | M |
| NEW-ED1022-05 | Story | Archive - Opname History (semua sesi) | archive-opname-history | BE: endpoint daftar sesi (display setting, search username + nama folder/subfolder, queries rentang waktu / user / folder, pagination) + hasil satu sesi; select user & folder. FE Archive: drawer inline 05b dari CTA "Lihat semua history" (tab Verification & modal 05), pola tabel v5, hasil sesi (tabel Step 3 + `Segmented`) | R-24..R-28; layar 05b; K-5 | 03, 04 | M |
| NEW-ED1022-06 | Story | Archive - Set Permission by User | archive-set-permission-by-user | BE: endpoint baca/simpan hak semua folder untuk satu user (tabel dari 02), cari folder. FE Archive: menu "+" Set Permission by User, layar 03 (pilih User, cari folder, tabel hierarkis Permission On/Off x View/Update/Delete/Store, Reset, Save Permission) | G-1; layar 03; S4 hal.2 | 02 | M |

Urutan jalan = urutan id (lihat *Keputusan rencana*). Semua item mengubah module FE `src/containers/Archive`; tidak ada module FE baru.

## Pertanyaan untuk BA item (bukan pertanyaan rencana)
| item | pertanyaan |
|---|---|
| 01 | G-2: employee >1 lokasi, folder "Several", non-superadmin tanpa data employee; superadmin vs folder `is_all_location=0` tanpa baris lokasi (kini tersembunyi juga untuk superadmin). G-7: Billing ikut daftar? (`SelectArchiveService.php:39-46` 6 tipe tanpa Billing; `BillingService.php:725-732` menyimpan Billing). Route Archive hanya `auth:api`: tambah `permission_v5` (ubah perilaku lama) untuk AC tanpa lisensi? |
| 02 | superadmin 1/2 lewati folder permission?; pewarisan ke child folder; hubungan hak folder dengan permission module 1266 (Update/Delete Folder, Add Document): keduanya wajib?; pembuat folder otomatis punya hak?; hak apa untuk memindahkan dokumen dari folder tanpa View (layar 06) |
| 03 | G-3 arti "ditandai belum diopname" (label Step 1 vs reset verified) & batas hari/zona waktu; K-6 kedalaman subfolder & lokasi sub-subfolder; G-4 permission menjalankan opname (hak folder dari 02 ikut?); G-5 modal kamera tanpa desain; G-6 modal mana di R-20; dokumen tercetak belum disimpan (`is_active=-1`) di-scan = not found atau invalid? |
| 04 | dampak G-3 pada angka verified; angka hanya dari folder dalam scope user (01 + 02)?; baris overview 3 sesi bisa diklik? |
| 05 | G-4 siapa boleh melihat history; G-5 history dari tab folder difilter folder itu?; tampilan hasil satu sesi (tanpa desain) |
| 06 | "child folder Off tak bisa" dicentang: aturan tampilan atau validasi BE?; Reset = kembali ke tersimpan atau kosongkan?; sumber daftar User; permission global yang membuka menu |
| semua | G-8: dokumen spesifikasi rujukan S2 (§6-§14) ada? |

## Temuan kode (titik awal analyst item)
| hal | bukti |
|---|---|
| Archive BE | `Modules/V5/Routes/DocumentArchive/api.php` (hanya `auth:api`), `Http/{Controllers,Services}/DocumentArchive/*` |
| Tabel | `archives` (folder & dokumen satu tabel: `type` 1/2, `status` 1/2/3, `history` JSON, `is_all_location`), `archive_documents`, `archive_locations` (Updater `SalesmanActivitySchema2025_10_16_13_10_32.php:595-654`). Belum ada tabel opname, verifikasi, maupun folder permission |
| Dokumen masuk | saat cetak PDF: `DocumentService::store` (`is_active=-1`), 8 service |
| Lokasi | `MyHelper::getUserLocation` (`MyHelper.php:1491-1528`) sudah dipakai list (`ArchiveService.php:25-36`); show/history/rename tanpa cek lokasi |
| Transaction type | belum satu sumber: `SelectArchiveService::type` (6), `ArchiveService::relatedTransaction` (7, + Billing), 8 pemanggil `store` |
| Lisensi | permission module 1266 (`app/Sql/data/permission_salesman.sql:67-76`) hanya di-seed untuk `SALESMAN_ACTIVITY` (`ApplicationSetupService.php:223-238`); FE route `permissions.ListArchive` (`src/routes/routes.js:2087-2094`) |
| FE | `ArchiveDrawer` (`DrawerUpdate`, satu form), `ArchiveButtonAdd` ("+"), `archive.function.js` (menu baris), `ArchiveScanner` (kamera + USB), `QRScanSearch` (ikon `ScanOutlined`), `ModalAdd` |

K-7 bukan pertanyaan: `knowledge/license-features.md` menetapkan Archive = module sendiri (bukan Stock Opname); opname masuk Archive (R-3).

## Di luar epic
- K-4 dokumen otomatis Verified saat dicetak: hanya di S4; desain menang (R-4).
- Temuan: `POST document-archive/archives/add-document` memanggil `ArchiveService::addDocument` yang tidak ada (`ArchiveController.php:100-113`) → 500; tidak dipakai FE. P-7: tiket terpisah.

## Keputusan rencana
**PK-1. G-1 Folder Permission masuk epic ini?** a) di luar epic; b) masuk sebagai 2 item.
Jawaban (developer 2026-10-06): **b** → NEW-ED1022-02 Folder Permission per folder, NEW-ED1022-06 Set Permission by User.

**PK-2. R-7/R-8 berlaku di mana?** a) seluruh Archive lewat satu scope di item 01; b) hanya Step 1 opname.
Jawaban: **a**.

**PK-3. Item opname (L) satu item atau dipecah?** a) satu item; b) 02a Step 1 / 02b Step 2-3.
Jawaban: **a** (kini NEW-ED1022-03).

**Urutan (poin epic Gate 1):** 01 → 02 Folder Permission → 03 Opname → 04 Status verifikasi → 05 History → 06 Set Permission by User. Folder Permission dimajukan karena hak View ikut menentukan "folder yang boleh dilihat" (bersama lokasi dari 01) yang dipakai Step 1 opname, angka verifikasi, dan filter folder history; kalau ditaruh sesudahnya, 03-05 harus diubah lagi. 02 juga membuat drawer folder bertab, lalu 04 menambah tab Verification. Set Permission by User tidak dibutuhkan item lain, jadi tetap di akhir.
