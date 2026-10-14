# Keputusan developer yang berlaku untuk semua fitur v5

Sudah diputuskan; agent tidak membukanya ulang. Pengecualian hanya lewat *Keputusan untuk developer* di
spec, dan dijawab developer di Gate 1. Asal: README epic multidb ED-944 (keputusan #1-#11) dan memory
sesi, 2026-09-28 s.d. 2026-10-05.

## Kode

| # | Keputusan | Akibat praktis |
|---|---|---|
| D-1 | Gaya kode BE = skill `v5-be-conventions` (repo BE); FE = `CLAUDE.md` + skill `equal-conventions` (repo FE). Konvensi diturunkan dari kode yang ada; usulan perbaikan dikumpulkan di akhir, tidak diterapkan diam-diam | reviewer menilai terhadap skill itu, bukan "best practice" luar |
| D-2 | Inkonsistensi v5 yang sudah diputuskan ada di `v5-be-conventions` `references/decisions.md`; berlaku untuk kode baru dan method yang diubah, **bukan** refactor massal | |
| D-3 | **php-fpm dan RoadRunner sama-sama didukung.** Reset state per request ada di `app/RoadRunner/AppState.php`. Tidak ada kode khusus RR di controller/service. Pindah koneksi di dalam request dikembalikan lewat `try/finally`. Butuh static mutable / mutasi config per request yang belum di-reset `AppState` → **berhenti dan lapor** | |
| D-4 | **v3 dan v5 berjalan bersamaan di produksi, di DB yang sama.** Histori transaksi v5 (JSON) hanya ke `description_`; `description` tetap berformat v3 (di `v5-rr`, `TransactionHelper::statusHistory` sendiri yang menambahkan teks format v3 ke sana) | QA memeriksa kedua kolom |
| D-5 | **Kompatibilitas satu arah v5 → v3** (2026-10-05). v5 menjaga v3 tetap benar di DB yang sama (mis. ikut menulis kolom yang dibaca v3). Perubahan dari v3 **tidak perlu** tercermin di kolom khusus v5; v3 tidak diporting mengikuti v5 | spec menyebut kolom v3 yang wajib tetap benar |
| D-6 | Penolakan bisnis = 4xx (400/404/422) dengan kode pesan di kedua berkas bahasa; 500 hanya untuk kegagalan tak terduga | QA: setiap 500 = defect |
| D-7 | Perubahan skema lewat Updater, bukan migration; permission lewat berkas SQL seed | subtask Updater di spec |

## Proses

| # | Keputusan | |
|---|---|---|
| P-1 | Satuan review = satu fitur (batch): review konvensi FE dan BE sekali di akhir batch | |
| P-2 | Satu commit per repo per fitur, sesudah Gate 2; gaya pesan mengikuti repo (`[ADD]`/`[FIX]`/`[DOC]` + kunci Jira); **tanpa push** | |
| P-3 | Jira: Story + subtask masuk sprint aktif sebelum dikerjakan; tetap In Progress selama dev & QA AI (defect = komentar); Done hanya sesudah QA bersih + Gate 2 + commit; **tanpa hash commit di Jira** | `references/jira.md` |
| P-4 | DB lokal boleh dikotori (developer punya backup), tetapi QA memulihkan yang ia ubah | |
| P-5 | `force_login` boleh dipakai dev/QA saat bind DB lokal (2026-09-29) | dapat me-logout sesi developer bila user sama |
| P-6 | FE dibangun ke folder staging; `build/` developer hanya diisi dengan **menyalin** saat `serve` mati (2026-10-01) | `scripts/fe-build.sh` |
| P-7 | Bug di luar cakupan: tidak diperbaiki diam-diam; developer memilih "kerjakan di fitur ini" atau tiket terpisah | |

## Perilaku yang diterima (bug dihapus developer, tidak diperbaiki)

Developer menghapus tiketnya di Jira (2026-10-14, epic ED-1022): "tidak perlu di fix". Bila QA melihatnya lagi,
catat sebagai "diterima (A-n)", **jangan buat tiket baru** dan jangan dihitung sebagai defect item. Item yang memang
mengubah kode tersebut boleh mengusulkan perbaikan lewat *Keputusan untuk developer* di Gate 1.

| # | Perilaku | Tiket lama (dihapus) |
|---|---|---|
| A-1 | `PUT api/v5/setting/display-columns/{modul}` dengan isi sama di detik yang sama = 500 `FAILED` (`ColumnDisplaySettingService::update` mendapat 0 baris berubah); jeda 1 detik = 200 | ED-1122 |
| A-2 | Route `api/v4/setting/display-columns*` tanpa saringan permission Opname Document (`documentArchive`, `documentArchiveOpnameHistory`); route v4 tidak dipakai FE | ED-1123 |
| A-3 | `scopeSearchAll` (trait `CustomizeBuilder`, semua list v5): key `search` berakhiran `()` memanggil method query builder apa pun | ED-1144 |
| A-4 | Input di luar latin1 = 500 di modul v5 selain Archive (Archive menolak dengan 422 `GE0748`, ED-1071); search Opname berisi U+FFFD = 500 | ED-1145 |
