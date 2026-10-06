# Module yang bergantung fitur lisensi

Sebagian module hanya ada untuk lisensi tertentu, misalnya **Archive**: module berdiri sendiri (menu
sendiri, bukan bagian Stock Opname) yang hanya untuk lisensi dengan fitur **Salesman Activity**
(developer, 2026-10-06).

## Mekanisme yang sudah ada (cek ulang di branch kerja; ini titik awal, bukan jawaban akhir)

| Sisi | Bukti | Catatan |
|---|---|---|
| BE | `LicenseHelper::featureLicenseByModule($module)` membaca `getData()['features']` (`Modules/V5/Entities/Helper/LicenseHelper.php`, branch `v5-opname-archive`); pemakai: `MyHelper::featureLicenseByModule('APPROVAL_TRANSACTION')` | nama fitur Salesman Activity di array `features` **belum dipastikan**: analyst mencarinya di kode/lisensi uji, kalau tidak ketemu → *Keputusan* |
| FE | `appFeatures = { approval: 'approvalTransaction', salesman: 'salesmanActivity' }` (`src/constant.js`, `next-canvasing`) | cari pemakainya untuk pola guard menu/route yang sudah ada |

## Yang wajib ada di spec module berlisensi

- **Model akses:** menu, route FE, dan endpoint BE untuk lisensi **dengan** dan **tanpa** fitur itu. Ikuti
  pola module berlisensi yang sudah ada (mis. Approval Transaction); jangan membuat mekanisme baru.
- **AC dua keadaan:**
  - dengan fitur → menu tampil, layar terbuka, aksi jalan;
  - tanpa fitur → menu tidak tampil, route FE tertolak, endpoint BE menolak dengan 4xx + kode pesan (bukan 500,
    bukan data bocor).
- **Uji nyata, bukan simulasi:** dua profil lisensi di `config/workspace.env` (`PROFILES`), dijalankan
  QA lewat `scripts/profile.sh run <profil> -- ...`. Developer menyiapkan berkas lisensi uji untuk tiap
  profil (`PROFILE_<nama>_LIC_DIR`, `PROFILE_<nama>_DB`). Belum ada → AC itu "tidak bisa diverifikasi" dan
  disebut di Gate 2.
- Lisensi integrasi (multi-DB) tidak relevan kecuali spec menyebutnya.
