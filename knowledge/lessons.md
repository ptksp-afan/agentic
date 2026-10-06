# Pelajaran dari pipeline multi-DB (F00, F01, F13, F02 - Sep/Okt 2026)

Diambil dari run log, Gate 2 berulang, dan commit "penyesuaian pipeline" di branch multidb. Hanya yang
berlaku umum untuk fitur v5 mana pun. Tiap baris: aturan → kejadian asalnya.

## Spec (dibaca `v5-analyst`)

| Aturan | Asal |
|---|---|
| Tulis AC "layar terbuka tanpa error" untuk **setiap** layar yang disentuh: list, display setting, setiap select/FK yang dipanggil saat dibuka, detail/drawer. Lalu AC per aksi | F00: AC awal hanya menguji API, layar central error saat dibuka; 4 ronde Gate 2 |
| Cek tabel/kolom benar-benar ada di **DB yang dituju** (tenant vs global). Yang belum ada → subtask Updater, bukan jalur kode khusus | F00: `customers`, `attachments`, `employees`, ... tidak ada di DB global |
| Cek **lebar & tipe kolom** di semua tabel yang dilewati data (salin antar-tabel/antar-DB) | F02: nama > 30 karakter terpotong; perlu Updater pelebar + keputusan developer |
| Perilaku yang bergantung lisensi/modul → AC untuk kedua keadaan, dan nyatakan profil uji yang dipakai | F00/F13: perilaku tanpa lisensi integrasi harus sama dengan sebelumnya |
| v3 dan v5 hidup bersama di DB yang sama: catat kolom v3 yang **wajib tetap benar** saat v5 menulis | Keputusan #9/#11 (lihat `decisions.md`) |
| Bug v3 yang ditemukan → *Keputusan untuk developer* ("port apa adanya atau perbaiki?"), jangan diputuskan diam-diam | F13/F02: beberapa bug lama jadi tiket terpisah atas pilihan developer |
| Status HTTP tiap penolakan bisnis ditulis di AC (400/404/422 + kode pesan) | F13: "lampiran tidak ditemukan" dulu 500 |
| Usulan desain yang "lebih aman tapi besar" diberi opsi minimal juga | F13: signed URL ditolak developer ("terlalu jauh"); revisi dengan perubahan minimal disetujui |

## Dev BE (`v5-be-dev`)

| Aturan | Asal |
|---|---|
| Server RR memuat kode sekali per worker: **reload sesudah ubah PHP** (`artisan equal:rr-reload`), ulangi kalau crash `0xC0000005` | F00-F02: uji membaca kode lama |
| Re-run Updater **in-process** (bootstrap Laravel), jangan lewat `/api/check-server` (menulis ulang `.env`/supervisor) | re-baseline 2026-10-04 |
| `validate()` membalas 302 tanpa header `Accept: application/json`; smoke call selalu kirim header itu | F13 (`v5-conventions`) |
| Kontrak berubah → perbarui `contract.md` + catat di spec. FE dibangun dari kontrak; drift diam-diam merusak tanpa error | F01-F02 |

## Dev FE (skill FE mode otomatis)

| Aturan | Asal |
|---|---|
| Build hanya ke staging; developer menyajikan `build/` | EPERM mengosongkan `build/` dua kali (2026-09-29, 2026-10-01) |
| Staging build bisa tanpa aset `public/`; `publish` menyalinnya | F02 |
| Jawaban "tanya, jangan tebak" dicari di spec lalu di kode BE (`file:line`), sisanya `BLOCKED:` | auto-mode.md |
| Detail UX (tipe tombol, warna, posisi search bar di `sm`/`xs`) hanya ketahuan manusia → e2e memotret viewport mobile juga | F02 Gate 2 ronde 1 |

## QA (`v5-qa`)

| Aturan | Asal |
|---|---|
| Gambar/berkas dibuktikan dari **isi** (byte/dimensi), bukan HTTP 200 | F13: klaim "logo PDF ada" ternyata `no_image.jpg` mPDF |
| Skenario tidak bergantung id yang dicatat tangan; buat data sendiri, pulihkan persis (snapshot) | restore DB 2026-10-04 merusak skenario lama |
| Test FE yang timeout di full suite dijalankan ulang sendirian sebelum disebut defect | F13: 2 test Raptor timeout karena mesin sibuk |
| Harness browser memakai profil Chrome sendiri (headless) - **tidak pernah** profil developer | F02: agent membuka Chrome profil default developer |
| Profil lisensi selalu dikembalikan (trap) dan dicek sesudahnya | F02: lisensi salah di DB central memicu error server setting |
| Log out setiap sesi; batas user online bisa habis | F00-F01: `GE0106` |

## Orkestrator & lingkungan

| Aturan | Asal |
|---|---|
| Semua subtask (termasuk `[QA]`) dipindah ke Done di akhir | F13: ED-998 tertinggal di To Do |
| Satu fitur per sesi; transcript 23 MB membuat app gagal memuat sesi | sesi `43f101b5` (2026-09-30) |
| Penolakan classifier auto mode tidak diakali; minta developer menambah izin di settings | 2026-10-01 |
| Restart/hibernate Windows mematikan agent yang sedang jalan; lanjutkan dari `run.md`, cek perubahan setengah jadi dulu | 2026-09-30 |
| FE v3 (Node 12) dan FE v5 (`.nvmrc`) tidak pernah jalan bersamaan: nvm-windows mengganti versi global | F00 |
| Usulan perbaikan pipeline dikumpulkan dan di-commit terpisah sesudah fitur selesai | semua fitur |
