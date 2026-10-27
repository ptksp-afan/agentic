---
key: ED-1022
sources: 4
unreadable: 0
has_design: true
---
## Brief asli
`--brief` = path berkas ini; isinya adalah brief (verbatim; `bar.N` di bawah = nomor baris `src/BRIEF.md`):

````text
Archive - Opname. 
Module berdiri sendiri, hanya untuk lisensi Salesman Activity.

Desain: /var/www/opname-archive/ksp-react/docs/briefs/Archive/Opname/Archive v2 Features.dc.html

Tujuan:
Saat ini fitur Archive hanya untuk melacak pergerakan dokumen, user tidak tau apakah dokumen itu benar-benar ada fisiknya atau tidak, maka dari itu dibuatlah fitur opname document. Sebuah fitur khusus untuk mengetahui dokumen mana yang benar-benar ada fisiknya. Ada dokumen penawaran awal yang sudah dipresentasikan ke client, dokumen nya ada disini: /var/www/opname-archive/ksp-react/docs/briefs/Archive/Opname/CHANGES.pdf. Akan tetapi dokumen tersebut sepertinya adalah dokumen awal sebelum ada revisi dari client setelah dipresentasikan. Jadi apabila ada perbedaan dengan desain yang ada di /var/www/opname-archive/ksp-react/docs/briefs/Archive/Opname/Archive v2 Features.dc.html, maka yang superior adalah desainnya bukan dokumen pdf tersebut. Pastikan kamu pelajari dulu BE dan FE untuk fitur-fitur di module Archive yang sekarang supaya kamu bisa menambahkan fitur opname pada module Archive nantinya.

Aturan yang tidak terlihat di desain:
- Opname Archive dilakukan per folder
- Opname Archive dilakukan per hari/per folder, ketika sudah berganti hari maka folder ditandai belum di opname.
- saat ini folder yang tampil seperti nya belum disesuaikan dengan lokasi tempat employee bekerja. padahal ada fitur tag lokasi di masing-masing folder untuk menentukan folder tersebut ada di semua lokasi atau hanya lokasi tertentu. untuk `role` dengan `is_superadmin` = 1 dan 2 bisa melihat semua folder tanpa pengecekan lokasi employee karena biasanya 2 user itu tidak memiliki data employee, `is_superadmin` = 1 digunakan untuk superadmin tertinggi di sistem, sedangkan `is_superadmin` = 2 untuk Technical Support
- ketika folder yang di opname memiliki child folder, maka pilih child folder nya juga. Defaultnya tercentang untuk child folder yang belum pernah di opname pada hari itu. ketika child folder tidak dicentang maka folder tersebut dan dokumen-dokumen tidak masuk dalam perhitungan pada sesi opname tersebut. hal ini juga untuk mengakomodir ketika di root folder strukturnya dibuat perlokasi seperti ini:

```
• Root
  • Folder Lokasi A
  • Folder Lokasi B
```

ketika user A dengan working location Lokasi A saja yang di set di module Employee melakukan opname di folder Root, maka `Folder Lokasi B` tidak tampil dan otomatis tidak tercentang. Tidak tercentang artinya tidak ikut di opname. Tidak ikut diopname artinya data opname nya tidak diubah. Jadi ketika `Folder Lokasi B` sudah di lakukan opname oleh user B yang memang bekerja di Lokasi B maka opname nya tidak conflict. Jadi ketika atasan mereka melakukan review dokumen di folder root `Folder Lokasi A` dan `Folder Lokasi B` sama sama sudah diopname.

- untuk 04. Opname Document
  - bentuknya modal, bisa pertimbangkan untuk menggunakan Modal Add di FE

- untuk 04. Opname Document - Step 1 Select Folder
  - untuk folder/child folder yang sudah diopname lalu dicentang atau dilakukan opname ulang pada hari yang sama dan toggle "Lanjut opname" nya dibuat false maka data opname yang sebelumnya akan ditimpa dengan data opname terbaru. Artinya dokumen yang tidak di scan maka akan ditandai unverfied walaupun di opname sebelumnya sudah verified. Akan tetapi kalau "Lanjut opname" nilainya true maka dokumen yang sudah verified akan tetap verified walaupun tidak di scan di sesi tersebut.
  
- untuk 04. Opname Document - Step 2 Opname Folder
  - tag disebelah title modal berubah jadi summary child folder yang di select, rumusnya = folder pertama + count sisa folder
  - gunakan component Segmented dari antd untuk filter "Scan result" disini, tidak perlu warna warni, gunakan warna default dari komponen segmented antd
  - untuk perwarnaan background tr/td table untuk data invalid/notfound bisa gunakan referensi di /var/www/opname-archive/ksp-react/src/styles/themes/metronic/modal.less bagian .modal-import-export-excel. buatkan khusus aja untuk modal opname archive ini, supaya tidak tercampur dengan fitur import/export excel
  - pastikan scanner menggunakan component scanner yang sudah ada di FE sekarang supaya support 2 hardware yaitu kamera yang terpasang di device dan qr code scanner yang terhubung lewat usb sebagai keyboard, harusnya tinggal plug n play disini. ketika menggunakan kamera maka akan ada modal yang muncul sekaligus tampilan live view kameranya untuk scan dengan klik icon di sebelah input nomor dokumennya. Samakan icon nya dengan yang ada di component filter popover saat ini. Modal scan tidak tertutup walaupun berhasil scan. Untuk scan lewat hardware scanner USB tidak perlu membuka modal nya terus menerus, jadi user harus menutup manual modal nya ketika sudah selesai.
  
- untuk 04. Opname Document - Step 3 Confirm
  - filter nya yang default aktif adalah "Scanned"
  - gunakan component Segmented dari antd untuk filter disini

- untuk 05. Detail verifikasi per Transaction Type:
  - transaction type sesuai dengan yang didefinisikan di module Archive support transaksi apa aja, harusnya di code sekarang sudah ada. tidak semua transaksi support. jadi nanti 1 code tersebut jadi single source of truth untuk validasi transaksi yang bisa masuk archive sekaligus menampilkan list transaction type di tampilan ini.

- untuk 05b. Opname History (All History, bukan yang overview 3 history terakhir):
  - bentuknya drawer inline, jadi ketika di klik CTA nya dia akan memenuhi container tempat dia bernaung
  - saat ini di desainnya untuk filter diatas table itu tidak lazim, tolong ubah seperti filter dan search pada table di EQUAL v5 pada umumnya (punya display setting, punya queries, punya columns, memiliki pagination, punya searchbar dengan filter popover dll)
  - keterangan "7 sesi" tidak perlu ditampilkan karena tidak lazim
  - untuk searchbar mencakup: username dan nama folder (termasuk sub folder yang di opname)
  - untuk queries nya: date time range untuk filter dari kapan sampai kapan opname yang mau ditampilkan, user (select dengan endpoint) untuk menampilkan siapa yang mengopname, folder (multiple select, flat array, dengan endpoint) untuk menampilkan opname berdasarkan folder-folder tertentu saja
````

## Sumber
| # | Sumber | Jenis | Ukuran / diubah | Salinan | Status |
|---|---|---|---|---|---|
| S1 | ksp-react/docs/briefs/Archive/Opname/BRIEF.md | md | 6.2 KB / 2026-10-06 13:59 | src/BRIEF.md | dibaca penuh |
| S2 | .../Opname/Archive v2 Features.dc.html (9 mockup: 01, 02, 03, 04a-c, 05, 05b, 06) | html desain | 127 KB / 10:01 | src/Archive v2 Features.dc.html | dibaca dari teks HTML; **render PNG GAGAL** (Chrome sistem tidak ada), tanpa PNG |
| S3 | .../Opname/support.js | js (runtime desain) | 69 KB / 10:01 | src/support.js | hanya pemuat, tanpa kebutuhan; tidak dibaca |
| S4 | .../Opname/CHANGES.pdf (penawaran awal pra-revisi) | pdf, 4 hal | 466 KB / 13:03 | src/CHANGES.pdf | dibaca hal. 1-4 (teks + gambar, `render/CHANGES-p1..4.png`) |

## Kebutuhan
Semua dari S1. Bentrok S4 vs S2: S2 menang [S1 bar.7].
R-1. Tujuan: opname dokumen untuk tahu dokumen mana yang benar-benar ada fisiknya (Archive kini hanya melacak pergerakan) [S1 bar.6-7]
R-2. Module berdiri sendiri, hanya untuk lisensi Salesman Activity [S1 bar.1-2]
R-3. Opname ditambahkan pada module Archive; pelajari dulu BE dan FE Archive yang ada [S1 bar.7]
R-4. CHANGES.pdf beda dari desain HTML: desain yang berlaku [S1 bar.7]
R-5. Opname dilakukan per folder [S1 bar.10]
R-6. Opname per hari per folder; ganti hari, folder ditandai belum diopname [S1 bar.11]
R-7. Folder yang tampil disesuaikan lokasi kerja employee lewat tag lokasi folder (semua / tertentu) [S1 bar.12]
R-8. `is_superadmin` 1 (superadmin tertinggi) dan 2 (Technical Support) melihat semua folder tanpa cek lokasi employee [S1 bar.12]
R-9. Folder diopname punya child: child ikut dipilih; default tercentang yang belum diopname hari itu [S1 bar.13]
R-10. Child tak dicentang: folder dan dokumennya tak masuk hitungan sesi, data opname-nya tidak diubah [S1 bar.13, 21]
R-11. Child di luar lokasi user tak tampil dan otomatis tak tercentang; opname per lokasi oleh user berbeda tidak konflik, review atasan di Root melihat keduanya [S1 bar.15-21]
R-12. 04. Opname Document berbentuk modal; pertimbangkan Modal Add FE [S1 bar.23-24]
R-13. Step 1: folder yang sudah diopname hari itu diopname ulang dengan "Lanjut opname" false: data lama ditimpa, dokumen tak discan jadi unverified walau tadinya verified [S1 bar.27]
R-14. Step 1: "Lanjut opname" true: dokumen verified tetap verified walau tak discan [S1 bar.27]
R-15. Step 2: tag di samping judul modal = ringkasan child terpilih: folder pertama + jumlah sisa folder [S1 bar.30]
R-16. Step 2: filter "Scan result" pakai antd `Segmented`, warna bawaan [S1 bar.31]
R-17. Step 2: latar tr/td invalid/not found mengacu `src/styles/themes/metronic/modal.less` `.modal-import-export-excel`; dibuat khusus modal opname, tak bercampur import/export excel [S1 bar.32]
R-18. Step 2: pakai component scanner FE yang ada; kamera perangkat dan QR scanner USB (keyboard), plug and play [S1 bar.33]
R-19. Step 2: kamera lewat ikon di samping input nomor dokumen, membuka modal live view; ikon sama dengan component filter popover [S1 bar.33]
R-20. Step 2: modal scan tak menutup walau scan berhasil; scan USB tak perlu modal; user menutup manual [S1 bar.33]
R-21. Step 3: filter default aktif "Scanned" [S1 bar.36]
R-22. Step 3: filter pakai antd `Segmented` [S1 bar.37]
R-23. 05: transaction type = yang didukung module Archive di kode sekarang (tak semua transaksi); satu kode itu single source of truth untuk validasi transaksi yang boleh masuk archive dan daftar di tampilan ini [S1 bar.40]
R-24. 05b = All History (bukan overview 3 terakhir), drawer inline: memenuhi container tempatnya bernaung saat CTA diklik [S1 bar.42-43]
R-25. 05b: filter di atas tabel diganti pola tabel EQUAL v5 (display setting, queries, columns, pagination, searchbar + filter popover) [S1 bar.44]
R-26. 05b: "7 sesi" tidak ditampilkan [S1 bar.45]
R-27. 05b: searchbar = username + nama folder (termasuk subfolder yang diopname) [S1 bar.46]
R-28. 05b queries: date time range; user (select dengan endpoint); folder (multiple select, flat array, endpoint) [S1 bar.47]

## Layar dari desain
Dari teks HTML [S2]; tanpa PNG (lihat Sumber).
### 01. List Archive - kolom Document Verified & menu Opname [S2 01; S4 hal.1]
Search, reload, Select, "+" (Add Folder, Hand Over, Receive, Store Document, Opname Document, Set Permission by User); ringkasan "Document Verified 1.204 / 3.680" + tautan detail per transaction type (ke 05); breadcrumb; Display Setting. Kolom: Name, Date Modified, Type, Salesman, Document Verified, Status, aksi. Folder "verified/total"; dokumen ikon centang/lingkaran. Menu baris folder: Info, Pindahkan, Lihat, Opname, Hapus. Opname dari "+" = root, atau folder aktif bila di dalamnya.
### 02. Folder Detail drawer - tab Detail / Permission / Verification [S2 02; S4 hal.2]
Detail: Name (wajib, 255), Folder Location (All / Several), Location (multi, bila Several), Description. Permission: toggle Enable Folder Permission (default off), tabel User x View/Update/Delete/Store, + Add User. Verification (read-only): "7 / 21" + progress; tabel Transaction Type x Verified/Unverified/Total; overview Opname History 3 sesi + CTA "Lihat semua history" (ke 05b). Save, tutup.
### 03. Set Permission by User [S2 03; S4 hal.2]
Dari "+": pilih User, cari folder, Reset, Save Permission; tabel hierarkis Folder, Permission On/Off, View, Update, Delete, Store; centang hanya bila Permission On (child folder Off tak bisa).
### 04. Opname Document - modal 3 step [S2 04; S4 hal.4]
Judul + tag scope + stepper. Folder tanpa subfolder melewati Step 1. Dari "+" (root / di dalam folder) atau menu baris folder.
### 04. Opname Document - Step 1 Select Folder [S2 04a]
Info: satu level subfolder, sub-subfolder ikut otomatis. Tabel centang: Folder, Document (jumlah), Opname hari ini ("Belum diopname" / "Sudah diopname hari ini - jam"); yang sudah diopname tak tercentang. Dicentang ulang: toggle "Lanjut opname" (default aktif) + teks akibat. Footer "4 folder dipilih - 3.400 dokumen", Cancel, Next.
### 04. Opname Document - Step 2 Opname Folder [S2 04b]
Input "Scan QR / barcode dokumen"; kartu "Status verifikasi saat ini 1.088 / 3.400" + progress; kartu "Sesi opname berjalan": scan, verified, not found, invalid (Not found = ada di sistem tapi di luar folder; Invalid = QR tak terdaftar). Filter Scan result: All, Verified, Not found, Invalid. Tabel: Document No, Transaction Type, Salesman, Time, Result; baris bermasalah berlatar merah, tanpa alert terpisah. Back, Finish Opname.
### 04. Opname Document - Step 3 Confirm [S2 04c]
Kartu: Total dokumen, Verified sesi ini, Not found, Invalid, Belum discan. Filter: All, Scanned, Verified, Not Found, Invalid. Tabel: Document No, Transaction Type, Folder ("Di luar scope opname" bila not found), Time, Result. Peringatan per folder tanpa lanjut opname (N dokumen verified jadi unverified). Back, Confirm Opname.
### 05. Detail verifikasi per Transaction Type [S2 05; S4 hal.4]
Modal "Document Verified - All Archive": "1.204 / 3.680" + progress; tabel Transaction Type, Verified, Unverified, Total; overview Opname History 3 sesi (Waktu, User, Scope, Total dokumen, Verified, Not found, Invalid) + CTA ke 05b. Handover/Receive/Store bukan transaction type. Close.
### 05b. Opname History [S2 05b]
"All Archive - seluruh sesi opname". Desain: filter bulan / folder / user + "7 sesi" di atas tabel (diganti R-25..R-28). Tabel: Waktu, User, Scope, Total dokumen, Verified, Not found, Invalid. Klik baris membuka hasil sesi (tabel Step 3 + filternya). Close.
### 06. Akses ditolak [S2 06; S4 hal.3]
Folder tanpa permission view: pesan "Anda tidak punya akses ke folder ini", tombol Back to Archive; dokumennya tetap muncul di pencarian dan bisa dipindahkan.

## Konflik & celah
S4 vs S2 (S2 menang [S1 bar.7]); calon Keputusan bila ubah perilaku:
- K-1. S4 opname global satu layar tanpa pilih folder, Cancel/Finish Opname [hal.4]; S2 per folder, 3 step, Lanjut opname, reset harian, pilih child [04a-c].
- K-2. S4 hasil scan Verified/Not found saja (QR tak dikenal = Not found) [hal.3-4]; S2 menambah Invalid dan mengubah arti Not found [04b].
- K-3. S4 tanpa Opname History; S2 punya overview (02, 05) dan 05b.
- K-4. S4: dokumen Verified otomatis saat dicetak [hal.3]; tak ada di S2 maupun S1. Berlaku?
Lain:
- K-5. 05b: filter/"7 sesi" S2 diganti S1 (R-25..R-28); FE jangan ikut desain.
- K-6. S2 04a: satu level subfolder; S1 tak menyebut tingkat; filter lokasi sub-subfolder belum diatur.
- K-7. R-2 "berdiri sendiri" vs R-3 "ditambahkan pada module Archive"; cara kerja "lisensi Salesman Activity" tak dijelaskan.
- G-1. Folder Permission (tab Permission 02, 03, 06, menu "Set Permission by User"; S4 hal.1-3) ada di S2/S4, tak disebut S1: masuk epic ini?
- G-2. R-7: hanya Step 1 opname atau seluruh daftar Archive? Aturan bila employee >1 lokasi / folder "Several Location" / non-superadmin tanpa data employee.
- G-3. R-6: "ditandai belum diopname" = label Step 1 saja, atau mereset verified dan angka Document Verified? Batas hari/zona waktu?
- G-4. Hak akses menjalankan/melihat opname dan history tak disebut.
- G-5. Tanpa desain: modal kamera; hasil satu sesi di 05b. History dari tab Verification folder: difilter folder itu atau semua?
- G-6. R-20 ambigu ("tak perlu membuka modal terus menerus, jadi user harus menutup manual"): modal mana?
- G-7. Enam transaction type di S2 = data contoh; daftar nyata dari kode (R-23).
- G-8. S2 merujuk §6, §7.1, §8.1, §10.3-10.4, §11-§14 dokumen spesifikasi yang tidak disertakan.

## Tidak terbaca
- ~~S2 render PNG gagal~~ → **terselesaikan 2026-10-06 16:14**: Chrome dipasang; render ada di `render/tiles/design-01..08.png` (potongan 1500 px dari atas ke bawah: 01 = 01 List Archive + 02 drawer; 02-03 = tab Permission/Verification; 03-04 = 03 Set Permission by User; 04 = 04a Step 1; 05 = 04b Step 2 + 04c Step 3; 06-07 = 05 Detail verifikasi; 07-08 = 05b Opname History; 08 = 06 Akses ditolak). Batas antar potongan perkiraan; lihat potongan sebelah bila layar terpotong. Tidak ada instruksi tersembunyi di sumber.
