---
epic: ED-1022
mode: epic-check
round: 5
items: [ED-1070, ED-1124, ED-1125, ED-1126]
---
# Gate 1 ronde 5 ED-1022 - cek lintas item

Ringkas: tanpa tabrakan kode; 9 karakterisasi dibalik; `GE0749` bebas; depends_on `[]` benar; urutan = rank (1070 → 1124 → 1125 →
1126); 2 pertanyaan lintas item. Spec tidak diubah. Berlaku: r2 § Data uji, r3 §9, A-1..A-5 (tidak dibuka ulang).

## 1. Kepemilikan
| # | Hal | Pemilik → aturan |
|---|---|---|
| O-1 | `ArchiveService.php` | `index` (diubah `:31-128`) = 1070; `relatedTransaction` (`:574-711`) = 1124; panggilan `:156-157` tetap. Baris 1124 bergeser sesudah 1070 → rujuk nama method |
| O-2 | `showRelatedTransaction` | bentuk parameter = 1070 AC-2; data (folder senama nomor transaksi) = 1124 AC-1/2. Baseline 1070: `true` di root = 200 (kini tanpa folder senama) |
| O-3 | Model `Archive::findName`/`findSources`, `SelectArchiveService::location` | 1124 saja. Trait `Modules/V5/Entities/Traits/CustomizeBuilder.php` = 1070 saja (salinan V4 tidak) |
| O-4 | FE | 2 modal Pindahkan + `ArchiveTable/index.js` (+test) = 1125; `src/hooks/appHook.js` (+test) = 1126. `components/Search`, scanner saat modal (1126 K-3): tidak diubah |
| O-5 | Dok FE | 1125 `docs/specs/Archive/026-fix-*` + `_module.md`; 1126 `docs/specs/_global/` (folder baru, `docs/specs/README.md:13`); bila 1126 juga menulis task Archive → `027` |

## 2. Konflik dan catatan
| # | Temuan | Penyelesaian |
|---|---|---|
| C-1 | 1070 K-5 × A-3 | tanpa konflik: cabang `()` (`CustomizeBuilder.php:154-156`) diperiksa sebelum cabang yang diubah K-5 (`:164-171`); `()` tetap = A-3. Untuk menjawab K-5: "`truncate` tertutup" hanya jalur `{"k":{..}}`; `{"truncate()":"x"}` tetap sampai Query Builder lewat A-3 (`Eloquent/Builder.php:1321`, dari kode). A-3 tidak dibuka ulang |
| C-2 | 1070 K-5 b × 1124 | `findName($name)` (parameter wajib), `findSources()` (statis, Collection, tanpa kueri bila kosong, `Archive.php:145-157`) bukan relasi → diabaikan K-5 b, sebelum/sesudah 1124 |
| C-3 | 1125 × 1126 | hari ini Enter pada tombol terfokus di modal dibatalkan hook (scanner halaman tetap aktif saat modal terbuka). Sesudah 1126, Enter pada tombol **Cari** modal = submit form bersarang = muat ulang (jalur keyboard baru ke bug 1125). Kedua spec mencatatnya → urutan 1125 dulu + EPIC K-6 |
| C-4 | 1125 K-2 × 1126 K-1 a | form Customer (`CustomerForm/index.js:575-593`): sesudah 1126 Enter pada Cari terfokus ikut memicu; tiket K-2 a memuat klik + keyboard |
| C-5 | 1070 AC-8 × urutan QA | guard per tabel = count + `max_updated_at`, termasuk `archives` dan `column_display_settings` (dipakai `query`, `CustomizeBuilder.php:120-123`). Fixture sendiri atau suite AC-10 yang menulis baris bersama menggeser guard → list dilewati. Aturan: AC-8 diputar pertama, sebelum fixture dan suite |
| C-6 | 1070 riwayat | ditunda 2026-10-08 (r2 §9b); K-2 a, K-3 a dijawab sebelumnya = rek sekarang. K-1 d / Blocked: item lain tidak terpengaruh, F-1/F-2 tetap |
| C-7 | 1124 K-1 × 1125 | modal 1125 kirim `id_archives` baris list; K-1 a → sumber basi dihapus = 404 `ARCHIVE400`, toast yang ada, FE tetap |

## 3. Karakterisasi yang dibalik
Hanya QA item pemilik yang membalik; item yang berjalan lebih dulu dan menjalankan suite itu mencatat "karakterisasi milik
<KEY>", bukan FAIL (r2 §5(2)). Skrip `qa/` item lama boleh disunting; `qa-*.md` item lain tidak (ED-1149 BR-7).

| # | Skenario | Hari ini → sesudah | Pemilik |
|---|---|---|---|
| F-1 | ED-1025 X-8 bagian search, `scenario4-select-misc.php:512-530` | 500 (drift kosong = PASS) → 422, asersi | 1070 AC-10 |
| F-2 | ED-1025 X-13, `scenario5-input-types.php:463-505` | 13/13 = 500 → 422/200 menurut AC-1/3/5 | 1070 AC-10 |
| F-3 | ED-1027 `scenario9-permission-opname-document.php:238-243` (sorts bentuk array, `$ra[0]==$rb[0]`) | 500=500 → 422=422, tetap PASS; teks catatan "500 lama" diperbarui | 1070 |
| F-4 | ED-1069 X-4, `scenario5-extra.php:298` | catatan (a) 500, (b) sumber aktif lagi → (a) 200 tanpa folder, (b) menurut 1124 K-1 | 1124 AC-9 |
| F-5 | ED-1069 `scenario4-select-related.php:106-108` "0" | catatan 200 → asersi 404 `ARCHIVE400` | 1124 AC-9 |
| F-6 | ED-1069 e2e B-FE-1, `ED-1069-validasi-pindah.spec.js:449-511` | 1 navigasi = PASS → 0 navigasi + GET search | 1125 AC-11 |
| F-7 | ED-1069 e2e AC-13 `armPickerSearch` (`:447-448`) | pengalih jaringan → ketik sungguhan | 1125 AC-11 |
| F-8 | ED-1069 `qa-fe.md` AC-13 MANUAL | → terbukti 1125 AC-1..4, dicatat di laporan 1125 | 1125 |
| F-9 | ED-1072 e2e AC-2 fallback `btn.click()` (`ED-1072-klik-sel.spec.js:228-235`), `qa-fe.md` not_verifiable 1, FE `021-fix-cell-action-click.md:98` | fallback → Enter asli membuka menu; fallback dijadikan asersi | 1126 AC-2, AC-12 |

Tidak berubah (dicek): ED-1025 e2e AC-40 `armPickerSearch` `:1707`; ED-1027 `scenario7-real-data.php:84` (tanpa trait); ED-1028 sorts
objek (OpnameService); put-in `-1` ED-1024 AC-10, ED-1027 AC-3 (= 1124 AC-5).

## 4. Kode pesan
`GE0749` bebas: HEAD `app/Lib/lang/*` (tertinggi `GE0748`, `en_EN.php:483`), 208 ref (`git grep` per ref), `git log --all -S` kosong, FE 0
rujukan. Hanya 1070 K-2 b yang mengklaimnya (rek a = 422 validasi, tanpa kode). 1124 memakai `ARCHIVE400/204/407` yang ada; 1125, 1126 tanpa
kode. Port ke branch rewrite tetap perlu pemetaan (r3 §3).

## 5. depends_on dan urutan jalan
Semua `[]` benar. Urutan = rank: **ED-1070 → ED-1124 → ED-1125 → ED-1126**. 1124 sesudah 1070: berkas sama, method beda; suite
1124 sudah melihat F-1/F-2 terbalik. 1125 sebelum 1126: C-3; tanpa `depends_on` (1125 Blocked → 1126 tetap jalan; bug sudah terjangkau
lewat klik). 1070 Blocked → `park.sh`; unpark sesudah commit 1124 di berkas sama bisa konflik (kecil) → blocker ulang. Baseline keempat
item = BE `6fcc91fd0` / FE `cf37552dd`, bersih; berkas commit item sebelumnya = `COMMITTED` di `changed.sh`, bukan milik item.

## 6. Data uji & lingkungan (`api_sidomaju`)
| Item | Baris sendiri | Sesi | Di luar baris sendiri (sementara, dipulihkan) |
|---|---|---|---|
| 1070 | `QA-`/`QA02-` (helper ED-1025); e2e hanya baca | QA_USER | baca 6 list; suite AC-10 1025/1027/1069/1071 (aturan r3 §6, `q5_drop_created`) |
| 1124 | `QA124-` | QA_USER | K-3: folder bernama nomor nyata di folder QA, dihapus lewat id (tak terlihat sapu `QA%` ED-1149 `snapshot.php:70`). Dokumen uji: `transaction_no` `QA124-`, hanya `id_transaction` nyata (`:579` memakai id). Suite 1024..1029/1069/1071 (1024 AC-10: Billing nyata, `q1_restore`) |
| 1125 | `0QA125-` (`archives`, `archive_locations`) | QA_USER, QA_USER2, `sam` (`force_login`, K-4 a) | bahasa QA_USER; e2e 1025 (tukar role QA_USER/QA_USER2), 1027, 1072, 1150 dengan fixture sendiri |
| 1126 | `0QA126-` | QA_USER | `user_roles` QA_USER ke preset ber-`Opname Document` bila perlu (ED-1149 BR-4), bahasa; opname hanya `scan`/`preview`, tutup tanpa Konfirmasi |

Tidak ada tabel dikosongkan/di-reset. Asumsi: QA_USER membuka list Pelanggan/PO/Barang/Pemasok/Faktur (baseline 1070 = 200) dan DO;
`sam` = `QA_USER3` bisa login (ED-1150, 2026-10-14); ada DO nyata bertransaksi terkait (ED-1069 B-1) dan Billing `is_active=-1`; tabel
opname kosong (r3 DU-2); profil `default`; e2e `--workers 1`; FE staging (P-6).

## 7. Pertanyaan lintas item
**EPIC K-6. Enter pada tombol Cari terfokus di modal Pindahkan: siapa yang mengujinya?** - risiko: rendah
Situasi: 1125 menguji Enter di kolom Cari + klik; 1126 mengaktifkan Enter pada tombol terfokus, termasuk Cari di popover modal.
Jalur itu aman hanya sesudah 1125 (C-3); tidak ada spec yang mengujinya.
| Opsi | Akibat |
|---|---|
| a | 1126 menambah 1 langkah e2e: kedua modal, fokus tombol Cari + Enter → GET search, 0 navigasi, modal tetap; e2e 1125 masuk AC-12. Bila 1125 belum Done: langkah dicatat "menunggu ED-1125", bukan FAIL |
| b | `depends_on` 1126: [ED-1125], langkah wajib; 1125 Blocked → 1126 ikut Blocked |
| c | tidak diuji otomatis; hanya Gate 2 |
Rekomendasi: **a** - bukti jalur gabungan tanpa mengikat 1126 ke nasib 1125.

**DU-4. Jendela run dan baseline 1070?** - risiko: rendah
Situasi: 1070 AC-8 memutar ulang 59 request (tangkapan 2026-10-14 20:06); guard berubah di salah satu dari 7 tabel (C-5, plus
`customers`, `purchase_orders`, `items`, `suppliers`, `sales_invoices`) → list dilewati; < 4 list → FAIL → 1070 Blocked. 1125 login `sam`
dengan `force_login` (sesi `sam` lain ter-logout). Teardown membandingkan snapshot (r3 DU-1).
| Opsi | Akibat |
|---|---|
| a | seperti r3 DU-1 a, diperluas: developer tidak memakai `api_sidomaju` (termasuk Pelanggan/PO/Barang/Pemasok/Faktur dan user `sam`) sampai run selesai. Di awal 1070 (sebelum dev, BE masih `6fcc91fd0`) runner memeriksa guard; berubah → tangkap ulang request yang sama, dicatat |
| b | tanpa tangkap ulang: list ber-guard berubah dilewati; < 4 list → AC-8 MANUAL (bukan FAIL), item lanjut |
| c | developer tetap bekerja: bukti AC-8 melemah, `sam` bisa ter-logout, beda snapshot dicatat bukan FAIL |
Rekomendasi: **a** - bukti regresi 1070 terjaga, run tidak berhenti. r3 DU-3 a berlaku lagi.

## 8. Keputusan Gate 1 (gabungan)
| # | Asal | Isi singkat | Rek. | Risiko |
|---|---|---|---|---|
| 1-7 | 1070 K-1..K-7 | cakupan; 422 vs `GE0749`; isi salah tipe; `[1,2]`/`0` → 422; filter relasi (C-1); tanggal; sort Karyawan | b, a, a, a, b, b, b | K-5 tinggi |
| 8-10 | 1124 K-1..K-3 | sumber dihapus → 404; `findName` hanya dokumen; folder senama nomor nyata di folder QA | a, b, a | K-2 tinggi |
| 11-14 | 1125 K-1..K-4 | `component={false}`; Customer = tiket; picker → root; regresi + `sam` | a ×4 | rendah |
| 15-18 | 1126 K-1..K-4 | rentetan ≤30 ms = scanner; uji Archive + DO; modal, checkbox = tiket | a ×4 | rendah |
| 19-20 | EPIC K-6, DU-4 | uji Enter Cari modal; jendela run + baseline 1070 | a, a | rendah |

## 9. Perubahan spec sesudah Gate 1
1070: QA AC-8 pertama (C-5) + tangkap ulang menurut DU-4; F-3 di AC-10; situasi K-5 + kalimat C-1 · 1124: rujukan method, bukan
baris (O-1); dokumen uji berawalan `QA124-` (§6) · 1125: teks tiket K-2 (C-4) · 1126: langkah EPIC K-6 + e2e 1125 di AC-12; F-9 di AC-12.

## 10. Jawaban developer (2026-10-14 14:14, sebagian)
- ED-1070: **ditunda** dari run ini (mempengaruhi banyak modul; developer diskusi dulu dengan tim). Arah developer: jalur `()` (A-3,
  dulu ED-1144) perlu ikut ditangani di ED-1070. Minta penjelasan opsi mana yang menyentuh banyak berkas/modul.
- ED-1124: K-1 dan K-2 ditanya ulang (penjelasan); K-3 **a**.
- ED-1125: K-1 ditanya ulang (apa itu `component={false}`); K-2 **c** (abaikan); K-3 **a**; K-4 **a**.
- ED-1126: K-1 ditanya ulang (developer: Enter di kotak Cari list Archive bekerja); K-2 menunggu K-1; K-3 **a**; K-4 **a**.
- EPIC K-6 **a**. DU-4 **a**: bagian baseline ED-1070 gugur (item ditunda); larangan memakai `api_sidomaju` dan user `sam` selama run tetap.
- Gugur karena ED-1070 ditunda: F-1, F-2, F-3 (karakterisasi ED-1025 X-8/X-13, ED-1027 scenario9 tetap seperti sekarang, bukan FAIL).
- 2026-10-14 14:30 lanjutan: ED-1124 K-1 **a** (developer bertanya: folder berisi dokumen memang tidak bisa dihapus - benar, ARCHIVE401
  untuk dokumen aktif di folder/turunan; yang bisa hidup lagi hanya folder kosong (subfolder kosongnya tetap terhapus) atau dokumen
  yang dihapus sendiri), K-2 **b**. ED-1125 K-1 **a**. ED-1126 K-1 **e**: tidak diperbaiki, diterima sebagai A-6
  (`knowledge/decisions.md`), ED-1126 keluar dari run; K-2 gugur; EPIC K-6 gugur (tanpa ED-1126, Enter pada tombol terfokus tetap
  dibatalkan hook, jalur C-3 tidak terbuka). F-9 gugur.
- 2026-10-14 14:41 item: ED-1124 dan ED-1125 **disetujui**. ED-1126 K-3: developer minta bug scan saat modal Pindahkan / Pindah massal terbuka masuk run ini -> digabung ke ED-1125 (revisi spec, tambahan scope ditunjukkan ke developer sebelum ED-1125 jalan).
