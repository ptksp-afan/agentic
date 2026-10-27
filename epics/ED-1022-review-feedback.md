---
epic: ED-1022
source: review batch developer, 2026-10-08 09:00
status: pembagian dikonfirmasi developer 2026-10-08; BA revisi MENUNGGU instruksi developer
next: BA revisi per story -> epic-check -> Gate 1 gabungan -> TUNGGU instruksi developer sebelum run
---
# Feedback review ED-1022 - dibagi per story

Teks asli developer ada di § Teks asli (paling bawah). Kode FB-n / D-n / N-n dipakai di run.md dan spec.

## ED-1024 - tanpa perubahan
- N-1 `getUserLocation()` dibiarkan apa adanya (helper bersama); tidak ada filter `is_active` tambahan.
- N-2 route BE `archives/add-document` tidak dipakai FE (FE memakai `document-archive/documents/put-in`,
  `endpoints.postPutDocument`) -> dibiarkan. Bug ED-1023 tetap tiket terpisah.
- **Disetujui developer → Done 2026-10-08** (Jira ED-1024 + 5 subtask Done, komentar hand-off).

## ED-1025 - Folder Permission per folder -> minta perbaikan
- FB-4a Permission baru **"Folder Permissions" / "Perizinan Folder"** (seed `permission_salesman.sql`, tanpa Updater - praktik repo D-7; dikoreksi epic-check ronde 2). Hanya user
  dengan permission ini yang bisa mengakses tab Izin di detail folder. (Halaman by User + menu "+" -> ED-1029.)
- FB-5 User yang mengaktifkan Izin Folder pada sebuah folder otomatis terdaftar dengan View, Update, Delete,
  Store di folder itu.
- FB-6 Kolom **All** di tabel izin folder, sesudah kolom Pengguna, untuk centang semua hak di baris itu.
- FB-7 Tooltip ikon Shield folder berizin: "Akses Terbatas" / "Restricted Access".
- FB-9 **Aturan baru: folder induk tidak lagi superior**, kecuali View. Update/Delete/Store dinilai di folder
  itu sendiri; View tetap butuh View di induk (tanpa View induk -> anak tidak terlihat). Contoh developer:
  User A punya View di "Cabang Jogja" dan View/Update/Delete/Store di "Area A" -> User A harus bisa ubah/hapus
  "Area A" (sekarang tidak bisa). Form Archive read-only penuh tanpa hak Update tetap benar, tapi mengikuti
  aturan baru ini.
- D-2 (P-2) Simpan tab Izin yang akan menghapus akses user itu sendiri dari folder -> **modal konfirmasi**;
  Ya = simpan (keputusan user), Tidak = tidak ada request ke API.
- N-3 id-ID `archive.Back to Archive` = "Kembali ke Arsip".
- Tetap: ARCHIVE407 dua bentuk body (tidak masalah); `_module.md` Archive (tidak masalah).
- QA usang diperbarui: AC-1 (Updater "kunci terbesar"), e2e jumlah tab drawer (3) dan item menu "+" (5).

## ED-1026 - Opname Document -> minta perbaikan
- FB-2 Step 2: tag di sebelah judul modal tetap folder yang diopname (mis. `root`, `Cabang Jogja`), ditambah
  jumlah subfolder yang dicentang di Step 1: "Cabang Jogja + 3 subfolder" (brief lama keliru).
- FB-3 **Data opname tidak disimpan ke DB sebelum user klik Konfirmasi Opname** di step terakhir. Batal /
  tutup modal tidak meninggalkan data (archive_opnames, archive_opname_folders, archive_opname_documents, dst.).
  Developer: data hasil scan belum dikirim ke BE sampai konfirmasi.
- FB-10 Filter dokumen (Step 2/3): `Segmented` -> `CheckableTag` antd; wrap di xs/sm. (Komponen bersama
  dengan riwayat ED-1028.)
- FB-11 Steps seperti `src/containers/ApprovalTransaction/approval-transaction-components/ApprovalTransactionCreatePin/index.js`:
  max-width, auto scroll di mobile, size small (xs/sm).
- FB-8a Tanpa permission "Opname Document": tidak ada tampilan opname sama sekali (menu, dst.).
- N-4 "All Archive" (parameter ARCHIVE414 dan kolom Folder dokumen root) tidak di-hardcode; ada terjemahan id-ID.
- Tetap: gagal muat folder menutup modal (developer memang lebih suka data scan belum dikirim sampai konfirmasi).
- QA usang diperbarui: AC-1, AC-12/AC-15 (digantikan ED-1028); `qa-fe.md` baris 76 dibetulkan.

## ED-1027 - Status verifikasi -> minta perbaikan
- FB-1 Di list Archive, klik nilai kolom **Document Verified** pada baris **folder** -> `ArchiveVerificationModal`
  berisi data khusus folder itu. Mirip tab Verification di ArchiveDrawer, tapi satu tampilan (tanpa tab).
  Pemicu: button `type="link"` dengan class `min-h-0 p-0`.
- FB-8b Tanpa permission "Opname Document": kolom Document Verified di list, entrinya di display setting,
  tab Verification dan semua tampilan verifikasi tidak ditampilkan.
- Tetap: terjemahan id-ID (sudah dicek developer), +100-120 ms GET archives, gulir horizontal (menunggu klien).

## ED-1028 - Opname History -> minta perbaikan
- D-1 Butir 13: `folders[]` / `warnings[]` di `GET opnames/{id}` disaring sesuai hak View pemanggil.
- FB-10 Filter hasil riwayat: `Segmented` -> `CheckableTag`, wrap di xs/sm (komponen bersama dengan ED-1026).
- FB-8c Tanpa permission "Opname Document": riwayat opname (CTA, drawer, display setting
  documentArchiveOpnameHistory) tidak ditampilkan.
- Imbas FB-3: sesi draft/batal tidak ada lagi di DB -> riwayat hanya sesi terkonfirmasi.
- Tetap: gulir horizontal (menunggu klien); judul kolom dari server (ganti bahasa menutup modal/drawer dulu).

## ED-1029 - Set Permission by User -> minta perbaikan
- FB-4b Halaman Izin Arsip by User dan item "+" -> "Set Permission by User" hanya aktif untuk user dengan
  permission "Folder Permissions" (permission dibuat di ED-1025).
- Imbas FB-9: aturan sel tabel (BR-7, K-4) disesuaikan dengan induk tidak superior kecuali View.
- Imbas FB-5: konsisten dengan pemberian hak otomatis saat Izin Folder diaktifkan.
- D-3 Validasi user target `PUT user-permissions/{id}`: **developer: dibiarkan** (halaman sudah dibatasi permission
  Folder Permissions). Bukan Keputusan Gate 1 lagi.
- D-4 Kunci `lockFolders` (perbaikan D-2 QA BE: simpan paralel → 500 duplicate key) **dipertahankan** - sudah ada di
  commit ED-1029; developer setuju.

## Perubahan FE oleh developer (ksp-react, belum di-commit)
DefaultTable/index.js, ArchiveDrawer/index.js, ArchiveForm/index.js, ArchiveVerificationModal/index.js,
ArchiveFolderPermission/index.js, ArchiveOpnameHistory/Content.js, styles/themes/metronic/modal.less
(11+/13-). **Sudah di-commit 2026-10-08 atas OK developer: FE 7fa63869d** `[UPDATE] Archive - penyesuaian UI/UX dari
review developer (ED-1022)`; eslint bersih. Baseline BA/run berikutnya mulai dari commit ini.

## Skenario QA usang
Developer: "silakan diperbarui". Diperbarui oleh QA masing-masing item saat run perbaikan (ED-1025: AC-1 + 2 asersi e2e;
ED-1026: AC-1, AC-12/AC-15), karena kedua item itu dikerjakan ulang dan di-QA ulang. `qa-fe.md` ED-1026 baris 76 sudah
dikoreksi 2026-10-08.

## Teks asli

- di list Archive ketika data didalam kolom Document Verified di klik dan dia tipenya folder maka akan muncul ArchiveVerificationModal dengan kondisi data khusus untuk folder tersebut yang baru saja diklik. Kondisinya mirip ketika melihat data di tab Verification pada ArchiveDrawer, tapi disini hanya ada 1 tampilan bukan multi tab. gunakan button tipe link, pastikan ada class `min-h-0 p-0` supaya button tidak terlalu tinggi dan lebar
- sepertinya saya salah memberi brief ketika opname dokumen, harusnya ketika masuk step 2 tag disebelah titel modal tetap sesuai dengan folder yang sedang diopname, misal `root` atau `Cabang Jogja` nah sebelah nya adalah count dari subfolder yang dipilih. misal kita sedang opname folder `Cabang Jogja`, didalamnya folder ada: Cabang Jogja > Area A, Area B, Area C. ketika user mencentang semua subfolder di folder `Cabang Jogja` di step 1, maka ketika step 2 tag disebelah title modal nya berubah dari `Cabang Jogja` menjadi `Cabang Jogja + 3 subfolder`
- perlu mekanisme untuk menghapus archive opname beserta data relasinya (archive opname document, archive opname folder, dll) supaya tidak menjadi data sampah misal ketika klik batal atau menutup modal opname atau malah sekalian aja ketika user belum klik konfirmasi opname di step terakhir, ya data opname nya jangan disimpan di db dulu, biar ga nyampah
- Tambah permission untuk ubah izin folder dengan nama "Folder Permissions/Perizinan Folder" hanya user dengan permission tersebut yang dapat mengakses tab Izin di detail folder dan halaman Izin Arsip by User. sama dropdown "Set Permission by User" di tombol + juga hanya aktif ketika user punya permission tersebut.
- Ketika ada user yang mengaktifkan Izin Folder pada folder tertentu, maka user tersebut otomatis terdaftar sebagai user yang bisa Lihat, Ubah, Hapus, dan Letakkan di folder tersebut
- Tambahkan kolom All di table izin folder setelah kolom Pengguna, supaya bisa langsung check all
- Ganti tooltip icon Shield untuk folder yang memiliki izin dengan kata-kata "Akses Terbatas/Restricted Access"
- Kalau user tidak memiliki permission "Opname Document" maka semua tampilan yang berhubungan dengan opname archive tidak perlu ditampilkan, termasuk kolom di list archive dan display setting
- Ada rules baru, folder induk tidak lagi superior. karena saat ini ketika folder induk superior maka user jadi tidak bisa update ke folder yang memang dia punya permission update di folder tersebut tapi tidak untuk parent nya, misal untuk `User A`: Cabang Jogja: View > Area A: View, Update, Delete, Store; Area B; Area C. sekarang yang terjadi adalah `User A` tidak bisa mengubah/menghapus folder `Area A` padahal harusnya dia bisa. yang masih superior di folder induk adalah `View` kalo dia tidak punya `View` di folder induk ya otomatis dia jadi tidak bisa lihat folder `Area A`, `Area B` dan `Area C`
- di brief saya sempat mention untuk menggunakan Segmented dari antd untuk component filter dokumen di opname dokumen dan history opname dokumen, saya mau itu diubah jadi CheckableTag dari antd, karena kalau menggunakan segmented agak susah di mobile untuk wrap nya. ketika nanti sudah menggunakan CheckableTag pastikan dia bisa wrap ketika di mobile (xs, sm) supaya lebih enak dilihat secara UI/UX
- buat step di Opname Document seperti yang ada disini: src/containers/ApprovalTransaction/approval-transaction-components/ApprovalTransactionCreatePin/index.js. disitu terdapat max-width, kemudian auto scroll di mobile, size nya juga small. jadi lebih cakep di mobile (xs, sm)
- ada beberapa changes di ksp-react, itu saya yang ubah. ada penyesuaian dikit di UI/UX biar lebih cantik, supaya lebih cepat jadi saya ubah langsung, nanti sekalian kamu commit aja

Keputusan: 1. ED-1028 minta perbaikan. 2. ED-1025 cukup modal konfirmasi saja, aksi yang dia lakukan akan menghapus aksesnya dari folder tersebut, kalau memang iya yasudah itu keputusan user, kalau tidak jangan sampai di-post ke API. 3. ED-1029: "emang mau divalidasi seperti apa?"
Catatan: ED-1024 getUserLocation() dibiarkan; add-document dicek (kalau tidak dipakai FE, biarkan). ED-1025 ARCHIVE407 dua bentuk body tidak masalah; _module.md tidak masalah; terjemahan "Kembali ke Arsip"; read-only penuh benar, ubah terkait induk superior. ED-1026 "All Archive" bahasa Indonesia jangan hardcode; gagal muat tidak masalah (lebih suka data scan belum dikirim ke BE sampai konfirmasi); oke. ED-1027 terjemahan sudah dicek; belum terasa lambat; gulir nanti dipikirkan sambil tunggu feedback klien. ED-1028 gulir nanti; judul kolom tidak masalah (ganti bahasa harus menutup modal/drawer). ED-1029 "perbaikan D-2 ini apa?" Skenario QA usang: silakan diperbarui.
Sesudah BA + Gate 1: jangan langsung eksekusi/long run, tunggu instruksi developer.

---
# Review batch ronde 2 (2026-10-12)

## Keputusan P1-P8
- P1 (ED-1069 B-3, rename non-latin1 kini 500): **a + tolak** - diperbaiki bersama create/ubah folder di ED-1071, dan input berkarakter di luar latin1 **ditolak** (bukan 500, bukan disimpan `?`). ED-1071 tidak lagi ditunda.
- P2 a (hasPermission lebih ketat dari permission_v5, dibiarkan). P4 a (422 sebelum 403 diterima). P5 a (tanpa Updater 1133->1139).
- P3 a + syarat: display setting dipakai bersama; ubahan user tanpa `Opname Document` tidak boleh mengubah/menyembunyikan kolom verifikasi bagi pemegang permission; hanya pemegang yang mengubahnya. Sudah dibuktikan ED-1027 AC-24 (`qa-be.md`, scenario10): PUT tanpa OD mengembalikan `documentVerified` ke posisi semula dari baris tersimpan; pemegang boleh menyembunyikan; PUT tanpa OD sesudahnya tidak memunculkannya lagi.
- P6 a: e2e ED-1025 AC-3 C (oracle "+" masih Update Folder) diperbaiki di revisi ED-1025.
- P7 a: perubahan UI developer di-commit FE 41480e37c (+ Prettier, dokumen 002/018/_module.md). Jest Archive 372/372.
- P8 a: pipeline d65db69 (e2e default 1 worker; runner menunggu anak agent di foreground). Password literal `config/database.php` BE: developer 2026-10-12 = biarkan, agent tetap dilarang mencetaknya.

## Feedback developer, dibagi per item
### ED-1025 - Folder Permission -> minta perbaikan
- FB2-1: ArchiveDrawer tab Izin, checkbox kolom "Semua" tidak men-trigger `touched` → Simpan memunculkan peringatan "tidak ada perubahan".
- FB2-5: ArchiveBulkMoveModal dan ArchiveMoveModal menampilkan ikon Shield seperti di list, supaya user tahu folder itu ber-Folder Permission.
- (P6) e2e AC-3 C diperbarui.
### ED-1029 - Set Permission by User -> minta perbaikan
- FB2-2: halaman Izin Arsip by User punya checkbox kolom "Semua" (centang semua permission) seperti tab Izin di ArchiveDrawer.
- FB2-3: halaman Izin Arsip by User bisa mengaktifkan/menonaktifkan Izin folder langsung (tanpa ke tab Izin ArchiveDrawer); aturannya sama dengan tab Izin.
### ED-1071 - latin1 -> dikerjakan (P1)
- Tolak input berkarakter di luar latin1 (create, ubah, rename folder; termasuk efek ED-1069 B-3); ED-1025 X-12 (karakterisasi lama) diperbarui pemiliknya.
### Tanpa catatan: ED-1026, ED-1027, ED-1028, ED-1069, ED-1072 (menunggu konfirmasi developer untuk Done)

## Pertanyaan developer
- FB2-4 "struktur DOM ArchiveAccessDenied diubah, tidak masalah?" → tidak masalah: e2e memakai `.archive-access-denied` (kini di wadah, tetap ada); tidak ada unit test yang bergantung; dokumen task 002 disesuaikan.

## Teks asli (ronde 2)
P1: perbaiki sekalian, tapi pastikan untuk menolaknya ya, jangan ada char diluar latin1 / P2: a / P3: a, pastikan jangan membuat error untuk user dengan permission `Opname Document` karena table untuk menyimpan display setting nya sama, misal: ketika tampilan awal kolom verifikasi tampil, lalu user tanpa permission `Opname Document` mengubah urutan/tampilan kolom, maka kolom verifikasi tetap tampil di user yang memiliki permission `Opname Document`, ketika user yang memiliki permission `Opname Document` mengubah urutan/menyembunyikan kolom tersebut, baru kolom tersebut diubah/disembunyikan / P4: a / P5: a / P6: a / P7: a / P8: a
- di ArchiveDrawer tab Izin, checkbox di kolom "Semua" tidak mentrigger touched pada field sehingga ketika button save di simpan muncul warning tidak terdeksi ada perubahan karena tidak ada field yang touched jadi anggapannya tidak ada field yang diubah
- di halaman Izin Arsip by User seharusnya juga ada checkbox dengan kolom "Semua" untuk centang semua permission seperti di ArchiveDrawer tab Izin
- di halaman Izin Arsip by User seharusnya bisa mengaktifkan/menonaktifkan izin dari folder supaya lebih efisien, tidak perlu ke ArchiveDrawer tab Izin terlebih dahulu, aturannya juga masih sama dengan ArchiveDrawer tab Izin
- saya mengubah struktur DOM di ArchiveAccessDenied gamasalah kan? perubahannya untuk perbaikan UI supaya lebih cepat saya kerjakan sendiri
- di ArchiveBulkMoveModal dan ArchiveMoveModal tampilkan icon Shield sama seperti di list supaya user tau kalau ini folder dengan Folder Permission
- ada beberapa perubahan UI yang saya lakukan sendiri biar cepat, sekalian aja kamu commit nanti kalau tidak mengganggu knowledge/konsistensi knowledge/code mu

---
# Review batch ronde 3 (2026-10-13)
- R1 **a**: task QA ED-1149 (skenario regresi tahan data baseline developer: role 5 memegang Opname Document, 343 archives terverifikasi `sam`). Jira Task dibuat, sprint 46; BA mulai.
- R2 **c**: ED-1144 (High, `scopeSearchAll` memanggil method Builder dari key search) dibiarkan dulu.
- Bug developer (user `sam` = `QA_USER3` di secrets.env): root → Folder A → Folder B, kembali ke Folder A lewat breadcrumb → hanya Folder B tampil, Folder C hilang (API mengirimnya); kembali ke root lewat breadcrumb → Folder A hilang; reload memperbaiki; tidak terjadi untuk `kevinsudjadi.adm`. BA akar masalah + pemilik item: `epics/ED-1022-review3-bug.md`.
- Developer: sampai tahap BA saja (pulang ±45 menit), tanpa long run.
- Persetujuan item ED-1025 / ED-1029 / ED-1071 belum dijawab.
- Jawaban (2026-10-14): B1 - Folder C tadi diubah developer untuk tes, kini kembali All Location (= keadaan saat bug dilaporkan) → analyst mengulang reproduksi. B2 **a** (`key` per folder di tabel ArchivePage + picker ArchiveTable). B3 **a** (tiket Bug baru). ED-1149: K-1 **b** (e2e user2 1027/1028 + regresi e2e penuh), K-2 **a** (X-10 dibatasi baris QA), K-3 **b** (role developer 6/22/29 + mode norole diganti preset), K-4 ditanya ulang (penjelasan), K-5 gugur (berkas sudah tidak ada).

---
# Bug dihapus developer (2026-10-14)
- ED-1123: dihapus di Jira karena route v4 tidak digunakan ("saya mau hapus ED-1123 (route v4) karena route v4 tidak digunakan").
- ED-1122, ED-1144, ED-1145: dihapus di Jira, "tidak perlu di fix". ED-1144 menggantikan R2 c (dibiarkan dulu) di atas.
- ED-1023: dihapus juga di Jira (route `archives/add-document` tidak dipakai FE, lihat N-2 di atas).
- Perilaku diterima: `knowledge/decisions.md` A-1..A-5.
