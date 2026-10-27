# BA ronde 2 ED-1022 - catatan lintas item (ditulis epic loop dari laporan analyst)

## Dari ED-1026 (rev) - 2026-10-08
- FB-3: sesi berjalan hanya di modal FE; Cancel/✕ tanpa request. Endpoint tanpa simpan: `POST opnames/scan`,
  `POST opnames/preview`, `POST opnames/preview/documents`. `POST opnames` = Konfirmasi (satu transaksi, `scans` +
  `selected_at`). Rute dihapus: PUT/DELETE `opnames/{id}`, POST `opnames/{id}/scan`, PUT `opnames/{id}/confirm`.
  Kode pesan dihapus: ARCHIVE211, ARCHIVE214, ARCHIVE413; ARCHIVE415 = `selected_at`/`scanned_at` bukan hari ini.
- Updater baru `DeleteArchiveOpnameDraft` (hapus sesi status 1/3 + barisnya) - K-13.
- Yang dibaca ED-1027/ED-1028 tidak berubah: sesi `status=2`, folder level 0-2, snapshot dokumen,
  `archives.is_verified`, `GET opnames/{id}` dan `/documents`. `scope` + field baru `subfolder_count`.
- K-14 (rekomendasi a): format Scope "<F / All Archive · root> + N subfolder" di semua tempat (riwayat ED-1028,
  3 sesi terakhir ED-1027) lewat `getScopeTag` + `scope.subfolder_count`.
- ED-1026 pemilik `ArchiveOpnameResult` (CheckableTag + prop `body`) dan `getScopeTag`; FB-10 di ED-1028 = regresi.
- `readable()` hanya sesi terkonfirmasi: "sesi batal dibaca pemilik" dan AC-3 ED-1028 (draft/batal tidak tampil)
  tidak relevan lagi.
- Helper QA alur lama harus diganti satu `POST opnames` (`scans` + `selected_at`): `q27_session`/scan/confirm di
  `features/ED-1027-*/qa/qa_lib.php`, `q28_session` + `DELETE` di `features/ED-1028-*/qa/qa_hist.php:155`.
- id-ID `archive.All Archive` = "Semua Arsip": teks modal Verification ED-1027 ikut berubah; test/e2e yang mengecek
  teks "All Archive" dalam bahasa id perlu disesuaikan.

## Dari ED-1025 (rev) - 2026-10-08
- Permission baru `Folder Permissions` / "Perizinan Folder" (seed `permission_salesman.sql`, id 1134 dicek ulang saat dev;
  tanpa Updater - role 1/2/3 saat seed ulang); konstanta FE `permissions.FolderPermissions`. Dimiliki ED-1025.
- Aturan baru hak efektif (FB-9): induk tidak superior kecuali View. Folder Izin Off di bawah induk On = K-12
  (rekomendasi b: ikut folder On terdekat di atasnya). Pindah folder = K-13 (rekomendasi a: Update di folder itu +
  Store di tujuan; induk asal tidak dicek).
- `access.manage_permission` = Update efektif (aturan baru) + permission `Folder Permissions` (K-15 a: tab Izin hanya
  tampil dengan permission itu; ubah butuh juga Update Folder + Update efektif; BE menolak field permission 403 GE0114;
  show tanpa permission tidak mengirim baris permission).
- ED-1029: pakai nama/konstanta di atas; `UserPermissionService::assertCanManage` masih `assertFolders(UPDATE)` →
  selaraskan dengan aturan baru; aturan sel + teks info "parent folder lebih dominan" ikut aturan baru; modal
  cabut-akses-sendiri di halaman by User = bagian ED-1029 bila diinginkan.
- ED-1026/1027/1028 hanya memakai View (`viewableFolderIds`, assert VIEW) → tidak berubah, cukup regresi.
- ED-1069/ED-1070 menyentuh berkas yang sama (`UpdateRequest`, `PutInFolderRequest`, `RenameFolderRequest`,
  `ArchiveService::update`) → urutan kerja diatur di epic; aturan pindah/put-in mengikuti K-13.

## Dari ED-1027 (rev) - 2026-10-08
- FB-1: pakai ulang `GET verifications/{id}` (BE tidak berubah); FE `ArchiveVerificationModal.onOpen({ idArchive, name })`
  varian folder (satu tampilan + CTA riwayat folder); tombol hanya di tabel halaman Archive. Gate 1 lama K-7 gugur.
- K-9 (tinggi, rekomendasi a): FB-8b ditegakkan server + FE - `GET archives` tanpa kolom/`document_verified`/ringkasan
  untuk user tanpa `Opname Document`; `verifications*` → `permission_v5:Opname Document` (403 GE0114); display-columns
  `documentArchive` disaring runtime dan entri tersembunyi dipertahankan saat simpan (satu baris per tenant!).
  Peta `module_name → permission → [data_index]` di `ColumnDisplaySettingService` dibuat ED-1027; ED-1028 menambah entri.
- ED-1028 FB-8c sebaiknya ikut jawaban K-9 (membalik ED-1028 K-6 Gate 1 lama, AC-12/AC-21 pakai user2).
- ArchiveDrawer `extraTabs`: tab Verification hanya dengan `Opname Document` (pola sama tab Izin ED-1025) → ED-1025 dulu.
- QA: helper `qa/qa_lib.php:521-575` → satu `POST opnames`; buang `DELETE` `qa/scenario5-sessions.php:54`; e2e teks id
  "All Archive" di `ED-1027-verifikasi.spec.js:618,649,833`; role dengan/tanpa `Opname Document`.

## Dari ED-1028 (rev) - 2026-10-08
- D-1 (BR-16): non-superadmin (termasuk pembuat sesi) hanya menerima `folders[]` folder yang kini terlihat; baris root
  selalu ikut; `warnings[]` hanya folder terlihat, `unverify_count` dari baris terlihat (0 → tidak dikirim);
  `counts`/`result_counts`/`is_partial` tetap. Contract §6 ED-1026 ikut aturan ini.
- K-8 (tinggi) = jawaban sama dengan ED-1027 K-9 (rekomendasi a: `GET opnames`, `opnames/{id}`, `/documents` →
  `permission_v5:Opname Document`; select opname-users kosong; display setting `documentArchiveOpnameHistory` entri
  seluruh modul di peta ED-1027, show/put 403). Bila a: ED-1026 BR-2 + contract baris 4 ("baca sesi = List Archive")
  → Opname Document.
- K-9 (rendah): baris display setting di Setup disembunyikan lewat `index` BE (rekomendasi a).
- K-10 (rendah): `scope.first_name` bisa menyebut subfolder tak terlihat → rekomendasi b (hapus `first_name`/
  `other_count` dari `scope` di ED-1026, pemilik `scope()`) bila ED-1026 K-14 = a, selain itu c (null-kan).
- Tanpa subtask FE (FB-8c FE di ED-1027 rev, FB-10 di ED-1026). ED-1027 sebelum ED-1028 (`ColumnDisplaySettingService`;
  peta harus menerima entri seluruh modul).

## Dari ED-1029 (rev) - 2026-10-08
- FB-4b: item "+", route FE, guard halaman dan `permission_v5` GET/PUT `user-permissions/{id}`: `Update Folder` →
  `Folder Permissions` (403 GE0114 tanpa permission). `assertCanManage` tetap `assertFolders(UPDATE)`, mengikuti
  `deniedBy()` baru ED-1025 (bila ED-1025 K-15 = b, ini harus diubah).
- K-8 (rendah): modal cabut-akses-sendiri juga di halaman by User (rekomendasi a: pemicu sama tab Izin; pembuat folder
  dikecualikan lewat field baru `created_by` per node di GET). K-12/K-15/K-16 ED-1025 hanya dirujuk.
- `permission_v5` tidak mem-bypass superadmin → QA butuh seed ulang agar role 1/2/3 punya `Folder Permissions`.

## Dari ED-1069 (bug) - 2026-10-08
- 5 butir Jira terbukti lewat HTTP (data QA69- dihapus, sisa 0). Siklus put-in → buka folder 500 sesudah 38 dtk.
  Koreksi: butir 3 (rename kembar) tidak lewat UI (FE pakai `PUT archives/{id}`); butir 2 bisa lewat UI (Search modal
  Pindahkan / pindah massal).
- Perbaikan BE saja: `id_archive_parent` = folder aktif yang ada (422); satu aturan tanpa-siklus untuk put-in + `PUT
  archives/{id}` (400 ARCHIVE404, teks diperluas); rename + `UniqueNameRule` (induk dari DB); select lokasi guard;
  transaksi terkait `[]` bila transaksi hilang; cek hak (403 ED-1025) tetap lebih dulu.
- K-3: ED-1069 pemilik aturan tanpa-siklus; ED-1070 butir 2 = rujukan + regresi (pesan dikirim ke analyst ED-1070).
- depends_on [ED-1025] (berkas Request sama). ED-1027 rev juga menyentuh `ArchiveService` (method berbeda).

## Dari ED-1070 (bug) - 2026-10-08
- Butir 1/3 terjadi di SEMUA list v5 (archives, customer/customers, purchase/purchase-orders identik); `GET opnames`
  (ED-1028) sudah 422. Akar: `CustomizeBuilder.php:96-97,:214-215` exception tanpa HTTP status; tipe tak dijaga
  `:103-171`, `:225-226`; `pagination` mentah (`ArchiveService.php:25`, pola sama di 160 service).
- Perbaikan: trait (searchAll 178 panggilan/156 berkas, sortAll 187/166) → 422 untuk search bukan JSON objek & sorts
  salah bentuk; isi salah tipe diabaikan; Archive `index`: pagination bukan int ≥1 → 422 (sesudah 404/403).
  K-1 rekomendasi b: trait untuk semua list, pagination hanya Archive (list lain tiket lanjutan).
- Butir 2 → aturan ED-1069 (setuju). depends_on [ED-1069]; urutan: ED-1070 sesudah ED-1027 rev dan ED-1069
  (`ArchiveService::index`). X-8 O-3 QA ED-1025 diperbarui QA ED-1069; X-8 D-1 + X-13 diperbarui ED-1070.
- Catatan: runner QA login `force_login=1` → token lain user QA di api_sidomaju ikut dicabut.

## Dari ED-1072 (bug) - 2026-10-08
- Akar: `ArchivePage/index.js:320-339` onCell → onClickDocument di semua kolom selain Nama (termasuk kolom ⋯);
  Dropdown/Button ⋯ tanpa stopPropagation; item menu (portal) tetap merambat lewat pohon React. Juga tautan nomor
  transaksi + popover "+N" di sel Nama dokumen. Baris folder tidak terkena.
- Perbaikan AT-1: helper `isCellActionClick(e)` di `archive.function.js` (target di luar currentTarget = portal, atau
  di dalam a[href]/button/input/[role=button]/[role=menuitem]/[data-cell-action]) di awal onClickDocument + onClickName.
- Syarat ED-1027 rev: tombol V/T = antd Button di kolom Document Verified (bukan Nama), modal dibuka lewat ref halaman.
- depends_on []; overlap berkas FE dengan ED-1025/1026/1027/1029 rev → ED-1072 dikerjakan terakhir.

## Dari ED-1071 (bug) - 2026-10-08
- Terukur (api_sidomaju, MariaDB 10.11.14): 514/514 tabel latin1_general_ci, 4250 kolom teks latin1; koneksi utf8.
  500 di Archive: archives?search/?id_archive, archives/{id}, history/{id}, user-permissions/{id}, select users/locations.
  Juga di luar Archive (Item, Item Category, PO). Penanganan lokal sudah ada: `OpnameService::isLatin1`, regex id ASCII.
- `authorize()` jalan sebelum `rules()` → id di URL / authorize juga harus dijaga.
- K-1 (tinggi) arah: a validasi karakter (rekomendasi) · b COLLATE/CONVERT (index hilang, aksen) · c utf8mb4 via
  Updater (514 tabel/tenant, downtime, ubah v3, makna unique berubah) · d a sekarang + c epic terpisah.
- K-2 a: body 422 `GE0747` (kode generik baru), id URL → 404, teks cari → hasil kosong; 403/404 tetap sebelum 422.
  K-3 a: Archive saja, sisa v5 tiket lanjutan. K-4 a: `GE0747` (hindari bentrok ARCHIVE419/420 usulan ED-1069).
- depends_on [ED-1025, ED-1029, ED-1069, ED-1070] → item BE terakhir. Karakterisasi ED-1025 X-12 + probe ED-1029 D-1
  dibalik oleh QA ED-1071.

## Gate 1 apply - dari ED-1026 (2026-10-08)
- `scope()` = `{is_root, folder_name, subfolder_count}` (tanpa `first_name`/`other_count`); satu `getScopeTag`.
- Baca sesi (`GET opnames*`) = `Opname Document`, milik ED-1028 (AC-14). `preview` tidak disaring (O-7).
- Masih perlu diperbarui item lain: contract ED-1028 `:30,40-42` dan ED-1027 `:36,46-48,147` (`first_name`/`other_count`);
  ED-1028 BR-12 + §3 masih id 1133 → 1139. Skenario usang tambahan karena K-10 b: 1027 `scenario5-sessions.php:103-118`,
  1028 `scenario1-list.php:177-189` (dicatat QA 1026, diubah QA pemiliknya).

## Gate 1 apply - dari ED-1025 (2026-10-08)
- K-15 b diterapkan: `PUT archives/{id}` route `permission_v5:Update Folder|Folder Permissions`; kelompok detail butuh
  `Update Folder` (lalu ARCHIVE408/410), kelompok izin hanya `Folder Permissions` tanpa cek hak folder (folder tanpa View
  boleh, asal dalam scope lokasi); body tanpa kedua kelompok 422; FE tidak mengirim kelompok detail bila tanpa Update.
- Seed: `Folder Permissions` 1140 TIDAK lagi terhubung ke `Update Folder` (1085) di permission_checks; hanya All Archive,
  List Archive, View Folder. → BR-18 ED-1029 ("role ber-FP ikut ber-Update Folder") harus dibetulkan.
- Predikat per baris untuk ED-1029 (O-10) dihapus (ED-1029 K-8 c); `willLoseOwnFolderAccess` hanya di drawer, hanya View.
- Profil uji user D: List Archive + View Folder + Folder Permissions saja. Opname Document 1139 di seed (ED-1025 mengubah).
- Akibat: pemegang Folder Permissions bisa memberi dirinya V/U/D/S di folder mana pun dalam scope lokasinya (K-15 b + K-14 a);
  pemegang View di induk bisa memindahkan subfolder Izin Off keluar (K-12 a + K-13 a).

## Pengaman data uji (commit pipeline, 2026-10-08 22:3x UTC) - catatan untuk runner ED-1026/1027/1028
- Fixture e2e ketiga item sudah aman (jurnal v2, hanya baris sendiri). Sesi ROOT yang dibuat QA_USER/QA_USER2 selama
  run e2e dianggap milik fixture dan dibuang.
- Masih perlu diurus QA pemilik (di folder sendiri): `q26/q27/q28_sweep` mereset kolom verifikasi ke bawaan (bukan nilai
  sebelumnya); `q26/q27/q28_baseline` + `*_cleanup` menegaskan tabel opname kosong → skenario BE GAGAL bila ada data
  developer. Spec e2e yang mengandalkan tabel kosong: ED-1027 `:636,647-651,655,1090,1183-1188`; ED-1028 `:344-347,389,
  593,740,1255,1508,1339,616,707,717,727,737,806-807,861,879,907,1069,1082,1256,1284`.
- Izin Gate 1 § Data uji: boleh hapus/reset di api_sidomaju, data QA sendiri dulu; jangan hentikan run.
