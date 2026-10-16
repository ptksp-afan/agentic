---
key: ED-1154
sources: 1
unreadable: 0
has_design: false
---
## Brief asli
```text
tolong buatkan tes untuk mengetes pembuatan archive baru lewat transaksi yang sebenarnya

flow nya:

1. Buat Sales Order dengan user `imansudjadi` untuk customer `BERKAT, TK.`, item apapun, assign ke salesman `adi`, lalu confirm
2. Buat Shipment untuk SO tersebut dengan user `imansudjadi`, assign ke driver `wawan`, Carrier nya terserah mana aja yang sedang tersedia, lalu confirm.
3. masuk ke sistem sebagai `wawan` lalu Accept Shipment tersebut. Di tahap ini document DO tersebut harusnya sudah terbentuk dan sudah `Taken` oleh `wawan` dari `imansudjadi` karena ada proses serah terima otomatis ke user `wawan` yang meng accept Shipment nya
4. masih login sebagai `wawan`, ke halaman Check In lalu check in ke customer tersebut. saya sudah allow checkin dan checkout outside radius untuk `wawan` 
5. masuk ke Shipment Acceptance, buat shipment acceptance untuk Shipment tersebut lalu confirm
6. masuk ke sistem sebagai `imansudjadi` lalu Accept Shipment Acceptance dari `wawan` tersebut
7. masuk ke sistem sebagai `wawan` lalu Check Out.
8. masuk ke sistem sebagai `imansudjadi` lalu ke halaman Shipment, klik Done untuk shipment tersebut. harusnya status dokumen di archive untuk nomor DO yang ada di Shipment tersebut masih Taken tapi taken nya oleh `imansudjadi` karena sudah ada proses serah terima secara otomatis
9. masuk ke sistem sebagai `imansudjadi` lalu buat Sales Invoice untuk customer `BERKAT, TK.`  dari DO yang ada di Shipment tadi, assign ke salesman `adi`
10. buat Billing untuk customer `BERKAT, TK.`  dari salesman `adi` lalu confirm
11. masuk sebagai `adi`, lalu accept Billing tersebut. saat ini harusya dokumen SI tersbut sudah terbentuk di archive dan sudah taken oleh `adi` karena proses serah terima otomatis
12. masih sebagai `adi` Check In ke customer tersebut. saya sudah mengijinkan check in/check out diluar radius untuk user `adi`
13. masih sebagai `adi` masuk ke Receivable Payment, buat RP berdasarkan customer dan SI tersebut lalu confirm
14. masih sebagai `adi` check out dari customer tersebut lalu close billing tersebut
15. masuk sebagai `imansudjadi` lalu close billing tersebut. saat ini harusnya dokumen SI masih `Taken` tapi sudah berpindah dari `adi` ke `imansudjadi`


tes store document

1. masuk sebagai `imansudjadi`
2. masuk ke archive dan buat folder di root
3. taruh document DO dan SI dari transaksi diatas ke folder tersebut. harusnya SI dan DO sekarang statusnya tidak `Taken` (`status = 3`) lagi tapi kembali normal (`status = 1`)


tes serah-terima manual, bukan otomatis dari transaksi

1. masuk sebagai `imansudjadi`
2. masuk ke archive dan Hand Over dokumen DO dari transaksi diatas. saat ini status dokumen DO tersebut harusnya `Handed Over` (`status = 2`) oleh `imansudjadi`
3. masuk sebagai `adi` 
4. masuk ke archive dan Receive dokumen DO dari transaksi diatas. saat ini status dokumen DO tersebut harusnya `Taken` (`status = 3`) oleh `adi`
5. masih sebagai `adi` lalu klik Hand Over Document untuk DO tersebut. saat ini harusnya Handed Over (status = 2) oleh `adi`
6. masuk sebagai `imansudjadi`
7. masuk ke archive dan Receive dokumen DO dari transaksi diatas. saat ini status dokumen DO tersebut harusnya Taken (status = 3) oleh `imansudjadi`
8. masih sebagai `imansudjadi` klik Store di folder tempat kamu taruh dokumen DO tadi, lalu store dokumen tersebut, saat ini status dokumennya harusnya normal (status = 1)
```

## Sumber
| # | Sumber | Jenis | Ukuran / diubah | Salinan | Status |
|---|---|---|---|---|---|
| S1 | Teks brief developer (blok di atas) | teks | - | tertulis di atas | dibaca; satu-satunya sumber (tanpa deskripsi Jira, lampiran, desain, tautan) |

Acuan: skenario **A** = alur transaksi (15 langkah), **B** = tes store document (3), **C** = tes serah-terima manual (8). `[S1 A.8]` = skenario A langkah 8. Kode status dari teks developer: 1 = normal, 2 = Handed Over, 3 = Taken (angka hanya disebut di B dan C; di A hanya kata "Taken").

## Pelaku & data
| User | Dipakai untuk | Langkah |
|---|---|---|
| `imansudjadi` | buat+confirm SO, Shipment, SI, Billing; Accept Shipment Acceptance; Done Shipment; close Billing; semua kerja di archive (buat folder, taruh, Hand Over, Receive, Store) | A.1-2, 6, 8-10, 15; B; C.1-2, 6-8 |
| `wawan` | driver Shipment: Accept Shipment, Check In, buat+confirm Shipment Acceptance, Check Out. Check in/out di luar radius sudah diizinkan developer | A.3-5, 7 |
| `adi` | salesman SO/SI/Billing: Accept Billing, Check In, buat+confirm RP, Check Out, close Billing; di archive: Receive, Hand Over Document. Izin di luar radius sudah diberikan developer | A.11-14; C.3-5 |

Data: customer `BERKAT, TK.`; item apapun; salesman `adi`; driver `wawan`; Carrier = mana saja yang sedang tersedia.

## Kebutuhan
R-1. Tes membentuk dokumen archive baru lewat transaksi yang sebenarnya, tiga skenario berurutan A, B, C; B dan C memakai DO dan SI "dari transaksi diatas" (A). [S1 kalimat pembuka, B.3, C.2]
R-2. `imansudjadi` buat Sales Order: customer `BERKAT, TK.`, item apapun, salesman `adi`; confirm. [S1 A.1]
R-3. `imansudjadi` buat Shipment untuk SO itu: driver `wawan`, Carrier apa saja yang tersedia; confirm. [S1 A.2]
R-4. `wawan` Accept Shipment. Arsip: "document DO tersebut harusnya sudah terbentuk dan sudah `Taken` oleh `wawan` dari `imansudjadi` karena ada proses serah terima otomatis ke user `wawan` yang meng accept Shipment nya". [S1 A.3]
R-5. `wawan` Check In ke customer lewat halaman Check In (luar radius diizinkan). [S1 A.4]
R-6. `wawan` buat Shipment Acceptance untuk Shipment itu; confirm. [S1 A.5]
R-7. `imansudjadi` Accept Shipment Acceptance dari `wawan`. [S1 A.6]
R-8. `wawan` Check Out. [S1 A.7]
R-9. `imansudjadi` klik Done di halaman Shipment. Arsip: "status dokumen di archive untuk nomor DO yang ada di Shipment tersebut masih Taken tapi taken nya oleh `imansudjadi` karena sudah ada proses serah terima secara otomatis". [S1 A.8]
R-10. `imansudjadi` buat Sales Invoice untuk `BERKAT, TK.` dari DO di Shipment tadi, salesman `adi`. [S1 A.9]
R-11. `imansudjadi` buat Billing untuk `BERKAT, TK.` dari salesman `adi`; confirm. [S1 A.10]
R-12. `adi` Accept Billing. Arsip: "dokumen SI tersbut sudah terbentuk di archive dan sudah taken oleh `adi` karena proses serah terima otomatis". [S1 A.11]
R-13. `adi` Check In ke customer (luar radius diizinkan). [S1 A.12]
R-14. `adi` buat Receivable Payment berdasarkan customer dan SI itu; confirm. [S1 A.13]
R-15. `adi` Check Out dari customer, lalu close billing. [S1 A.14]
R-16. `imansudjadi` close billing. Arsip: "dokumen SI masih `Taken` tapi sudah berpindah dari `adi` ke `imansudjadi`". [S1 A.15]
R-17. Store document: `imansudjadi` masuk archive, buat folder di root. [S1 B.1-2]
R-18. Taruh dokumen DO dan SI ke folder itu. Arsip: "SI dan DO sekarang statusnya tidak `Taken` (`status = 3`) lagi tapi kembali normal (`status = 1`)". [S1 B.3]
R-19. Manual: `imansudjadi` Hand Over dokumen DO di archive. Arsip: "`Handed Over` (`status = 2`) oleh `imansudjadi`". [S1 C.1-2]
R-20. `adi` Receive dokumen DO. Arsip: "`Taken` (`status = 3`) oleh `adi`". [S1 C.3-4]
R-21. `adi` klik Hand Over Document untuk DO. Arsip: "Handed Over (status = 2) oleh `adi`". [S1 C.5]
R-22. `imansudjadi` Receive dokumen DO. Arsip: "Taken (status = 3) oleh `imansudjadi`". [S1 C.6-7]
R-23. `imansudjadi` klik Store di folder tempat DO ditaruh (R-18), store dokumen. Arsip: "status dokumennya harusnya normal (status = 1)". [S1 C.8]

Ringkasan keadaan arsip yang dinyatakan developer (hanya ini yang dinyatakan):
| Sesudah | Dokumen | Status | Pemegang |
|---|---|---|---|
| A.3 | DO | Taken (3) | `wawan`, dari `imansudjadi` |
| A.8 | DO | Taken (3) | `imansudjadi` |
| A.11 | SI | Taken (3) | `adi` |
| A.15 | SI | Taken (3) | `imansudjadi` (dari `adi`) |
| B.3 | DO, SI | normal (1) | tidak disebut |
| C.2 | DO | Handed Over (2) | oleh `imansudjadi` |
| C.4 | DO | Taken (3) | `adi` |
| C.5 | DO | Handed Over (2) | oleh `adi` |
| C.7 | DO | Taken (3) | `imansudjadi` |
| C.8 | DO | normal (1) | tidak disebut |

## Layar dari desain
Tidak ada desain. Halaman/aksi UI yang disebut developer: Check In, Shipment Acceptance (Accept), Shipment (Done), Receivable Payment, Billing (Accept, close); Archive (buat folder di root, taruh dokumen, Hand Over / Hand Over Document, Receive, Store di folder).

## Konflik & celah
Tidak ada konflik antar sumber (sumber tunggal). Label aksi tidak seragam: "Hand Over" (C.2) vs "Hand Over Document" (C.5); "taruh" (B.3) vs "Store" (C.8). Perlu dicocokkan ke UI nyata.
- G-1. Kredensial/cara masuk sebagai `imansudjadi`, `wawan`, `adi` tidak disebut (bukan user QA yang sudah ada?). Minta ke developer lewat jalur rahasia; jangan ditulis di berkas.
- G-2. Bentuk tes tidak disebut: lewat UI browser, panggilan HTTP per user, atau campuran; dan DB/tenant mana yang dipakai ("transaksi yang sebenarnya"). Folder `qa/e2e` sudah ada, kosong.
- G-3. Pembersihan tidak disebut: SO, Shipment, Shipment Acceptance, SI, Billing, RP, check in/out, folder dan dokumen arsip. Efek ke stok/piutang, dan tes diulang akan menumpuk transaksi di `BERKAT, TK.`; "DO dan SI dari transaksi diatas" harus dikenali dari nomor yang dibuat run itu. Calon Keputusan Gate 1.
- G-4. Carrier "terserah ... yang sedang tersedia": aturan pilih, dan bila tidak ada yang tersedia.
- G-5. Item "apapun": kuantitas, harga, stok, satuan tidak disebut; apakah Shipment memuat seluruh qty SO; apakah satu Shipment = satu DO ("nomor DO yang ada di Shipment") dan satu DO = satu SI.
- G-6. "close billing": arti close oleh `adi` (A.14) dan oleh `imansudjadi` (A.15) tidak dijelaskan; keadaan SI sesudah A.14 tidak disebut; A.15 tidak menulis "otomatis" walau perpindahan `adi` ke `imansudjadi` tersirat otomatis.
- G-7. Keadaan arsip tidak disebut: sesudah A.1-2 (belum ada dokumen?), A.4-7 (DO), A.9-10 (SI belum ada sebelum A.11?), A.12-14 (SI), pemegang sesudah B dan C.8; apakah Shipment Acceptance, Billing, atau RP juga membentuk dokumen arsip. Pengecekan di luar yang dinyatakan = keputusan.
- G-8. Penerima Hand Over di C.2 dan C.5 tidak disebut (tersirat `adi` dan `imansudjadi` dari Receive berikutnya); apakah dialog meminta memilih penerima.
- G-9. Apakah DO tetap anggota folder selama C.2-C.7 (C.8 menyiratkan ya, Store "di folder tempat kamu taruh"); nama folder bebas.
- G-10. "oleh" dua arti: Handed Over "oleh X" = pengirim, Taken "oleh X" = penerima, A.3 dan A.15 menambah "dari". Kolom/field apa yang diperiksa dan dari mana (UI, API, DB `status`) tidak disebut.

## Tidak terbaca
Tidak ada.
