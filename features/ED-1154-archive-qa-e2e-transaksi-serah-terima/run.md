---
key: ED-1154
epic: ED-1022
title: [QA] Archive - tes end-to-end pembentukan dokumen archive dan serah-terima lewat transaksi nyata
slug: archive-qa-e2e-transaksi-serah-terima
phase: done
blocked_from: null
qa_round: 5
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents: {qa-fe-r1: acfda12dfc9c115d9, qa-fe-r2: a06b00b47a4348a4f, qa-fe-r3: a44b9d98ebceb8546, qa-fe-r4: a0ad2b799181b1c9a, qa-fe-r5: ad0b5f4f19f45bf45}
---
## Status ringkas
Long run ED-1022, QA FE r5 **PASS** 16/16 (2026-10-16): run 6 `261016140921` sekali jalan tanpa resume, 0 5xx tanpa allow-list, jalur A-9/A-10 0 kali.
Siap review developer (Gate 2 long run): commit agentic lokal, BE/FE tanpa commit (tidak berubah). Jira ED-1154 + ED-1155 In Progress, label ai-review.
Done hanya lewat `/v5-epic review ED-1022` sesudah developer menyetujui.

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|
| ED-1155 | [QA] Skenario e2e archive lewat transaksi nyata (A/B/C) + jurnal run | QA | In Progress |

## Keputusan developer
- Gate 1 (2026-10-14): K-1 a (profil `wawan` = QA_WAWAN_USER/PASS), K-2 b, K-3 a, K-4 a, K-5 a, K-6 a, DU-5 a (lihat spec § 8).
- Blocker r1 (2026-10-16): R1-K1 c, R1-K2 biarkan dulu, R1-K3 c (PIN off).
- Blocker r4 (2026-10-16): R4-K1 - D-1 disengaja (A-9), D-2 diperbaiki task lain (A-10); A.5/A.6 + A.9 lewat jalur yang jalan, AC-15 tanpa allow-list; tiket ED-1168/ED-1169 dihapus developer.

## Usulan pipeline
- (dari QA FE r1) harness e2e: `monitor.apiResponses()` selalu kosong karena `API_URL` memakai `:80` sedangkan URL request browser
  tidak; daftar `api` di report.json jadi kosong (cek respons >= 400 hanya lewat jalur lain). Usul: normalisasi port bawaan saat
  mencocokkan URL API di `scripts/e2e/lib/monitor.js`.
- (dari QA FE r1) BA: spec transaksi nyata perlu memeriksa lisensi `APPROVAL_TRANSACTION` + `approval_configs`/`approval_status`
  customer di DB uji sebagai prasyarat (celah ED-1154 B-1); catatan S-1: Tambah Pengiriman = Simpan lalu Konfirmasi di drawer, bukan
  `shipments/save-and-confirm`.

- (dari QA FE r2) `scripts/e2e/run.sh` menolak jalan bila `E2E_PORT` sedang dipakai (r2: dua harness bentrok di 4100, tanpa efek data).
- (dari QA FE r2) pindahkan helper `clickReady` (antd menelan klik saat `ant-btn-loading`) + pola resume/snapshot per langkah ke harness e2e.
- (dari QA FE r2) BA: probe layar baca-saja sebelum Gate 1 untuk spec "transaksi nyata" (S-2..S-4 baru terlihat saat run).
- (dari QA FE r3) harness: API untuk mendaftarkan monitor konteks tambahan (w/a) ke `report.json`/galeri.
- (dari QA FE r3) agent `v5-qa`: frasa verdict "no 500 was seen" → "no 500 tanpa tiket" (sejalan preseden ED-1149, ED-1154 r3).

## Log
- 2026-10-14 21:08 start: baseline BE 0b8417778 (0 berkas kotor), FE 800e42faf (0 berkas kotor)
- 2026-10-14 21:11 start: /v5-feature new --epic ED-1022 (permintaan developer: tes pembentukan archive lewat transaksi nyata, 3 skenario); brief v5-brief -> brief/README.md (R-1..R-23, G-1..G-10); BA mulai
- 2026-10-14 21:14 developer: wawan + adi ditambahkan ke secrets.env; bentuk tes UI Playwright (Check In lewat web, bukan mobile); data transaksi dibiarkan (tanpa cleanup); carrier = kurir mana saja yang tersedia dan cukup; item bebas; close billing: adi = request close, imansudjadi = close sesudah request -> diteruskan ke analyst
- 2026-10-14 21:50 gate1 disetujui developer: K-1 (QA_WAWAN_USER/PASS ditambah developer), K-2 b, K-3 a, K-4 a (karakterisasi, bukan rekomendasi b), K-5 a, K-6 a, DU-5 a; jawaban dimasukkan analyst; spec approved; Jira: Task ED-1154 dibuat (parent ED-1022, sprint 46, To Do) + subtask ED-1155 [QA] (sprint 46) + komentar Gate 1; folder NEW-20261014 -> ED-1154; phase: be (task QA murni, pola ED-1149). DEVELOPER: JANGAN DIJALANKAN OTOMATIS - tunggu instruksi developer
- 2026-10-15 16:29 long run start (`/v5-epic ED-1022 --only ED-1070,ED-1154`, developer: Mulai): rebaseline BE 5c4c69bcd (sesudah ED-1070, 0 kotor), FE 41146943c (0 kotor)
- 2026-10-15 16:35 preflight OK (fpm); spec `status: approved`; ED-1154 + ED-1155 → In Progress (sprint 46 sudah)
- 2026-10-15 be: n/a (task QA murni, `be_modules: []`); qa-be: n/a (tidak ada AC `[BE]`, diff BE/FE 0 berkas: changed.txt kosong); fe dev: n/a (`fe_modules: []`)
- 2026-10-15 catatan: tes berjalan di atas kode ED-1070 (be 5c4c69bcd); kegagalan karena ED-1070 = temuan terhadap ED-1070 di run.md + qa-fe.md (tanpa tiket baru)
- 2026-10-15 qa-fe r1 mulai (v5-qa opus, `qa_model: opus`)
- 2026-10-15 17:06 qa-fe r1 (v5-qa opus, acfda12dfc9c115d9, 35 mnt): FAIL/BLOCKED - PASS 1 (AC-2: 14 layar x 3 profil, 0 error, 2 run) /
  tidak bisa diverifikasi 15 (AC-1 BLOCKED X-1/X-2; AC-3..AC-16 tidak jalan). Prasyarat tertulis AC-1 terpenuhi (role adi 5, tanpa
  attendance terbuka, CST-10120 aktif Semarang, carrier GUDANG SMG, item 019223 stok 264, worker antrian hidup). Build staging FE OK
  (93 dtk); yarn test n/a (diff FE kosong); regresi BE n/a. Run 1 `261016000149`, run 2 `261016000404` (jurnal
  `work/ED-1154/runs/*.json`, status blocked, 0 nomor dokumen); 0 baris archives berubah, 0 5xx, token 3/3 dicabut, `--leftover` 0.
  Galeri `work/e2e/ED-1154-archive-qa-e2e-transaksi-serah-terima-20261015-170145/`, `-20261015-170400/`. Defect aplikasi 0; temuan
  terhadap ED-1070 0 (baris `[CustomizeBuilder]` di log BE selama jendela run = 0, 0 404). Berkas: qa-fe.md, qa/e2e/{ED-1154-archive-transaksi.spec.js,
  fixture.php, routes.json, checkin.jpg}
- 2026-10-15 17:09 blocker: keputusan baru di luar Gate 1 (cara A.1 di lingkungan ber-approval) → Jira ED-1154 + ED-1155 → Blocked,
  label ai-blocker (ED-1154), komentar `[AI-BLOCKER]` 11536 (K-1, K-2); tanpa park (tidak ada kode BE/FE); tidak ada item yang
  bergantung; tanpa commit (QA belum lolos); tiket baru 0 (O-1 hanya hasil baca kode → K-2)
- 2026-10-16 01:40 developer menjawab blocker: K-1 c (data approval disiapkan developer: imansudjadi kini approver CUSTOMER L4 dan SALES_ORDER L2, keduanya mandatory; dicek read-only: CST-10120 masih approval_status=0 -> tes menyetujui customer lewat UI), K-2 'biarkan dulu' (O-1 tanpa tiket, tidak dilaporkan ulang); analyst memperbarui spec (A.0 approval customer, A.1 approval SO, X-3, S-1, A.9)
- 2026-10-16 01:41 unblock: Jira ED-1154 + ED-1155 Blocked -> In Progress, label ai-blocker dilepas, komentar jawaban; phase: qa-fe (QA FE r2 sesudah spec diperbarui). Run hanya atas instruksi developer
- 2026-10-16 01:50 developer: R1-K3 c (PIN approval dimatikan global); dicek read-only app_configs.use_approval_pin=0 (diubah developer 08:50); spec § 8 diperbarui; semua blocker terjawab - QA FE r2 menunggu instruksi developer
- 2026-10-16 long run lanjut (story runner, `/v5-feature ED-1154 --long-run --epic ED-1022`): preflight OK (fpm); teks BR-13/AC-1 disamakan dengan R1-K3 c (tanpa PIN, cek `use_approval_pin=0`); qa-fe r2 mulai (v5-qa opus), alat uji r1 dipakai ulang
- 2026-10-16 03:30 qa-fe r2 (v5-qa opus, a06b00b47a4348a4f, 93 mnt): FAIL - PASS 13 / FAIL 3 (AC-5, AC-7, AC-15) / manual 0 / n.v. 0; blocker r1 selesai (AC-1 r2 terpenuhi);
  A→B→C lengkap lewat UI di 3 run (`261016092317` debug+resume, `261016100813`, `261016102000` bersih); FAIL hanya karena 2 defect aplikasi lama di luar task
  (D-1 SA confirm driver 500 GE0114, D-2 SI save-and-confirm sesudah impor DO 500 `sales_invoice_serials`); temuan ED-1070 0; E-1 foto customer lokal tidak ada (= ED-1166).
  Dibuat: SO-SMR/2610/00032..00034, SP 00005..00007, DO 00043..00045, SA 00028..00030, SI 00019..00021, BILL 00002..00004, RP 00019..00021,
  folder E2E-ARSIP-<runId> x3; approval_transactions 42798 (CUSTOMER CST-10120 L4, approval_status 0→2, permanen) + 42799..42801 (SALES_ORDER L2); jurnal `work/ED-1154/runs/*.json`
- 2026-10-16 03:36 bug di luar item (long run): ED-1168 (D-1) + ED-1169 (D-2) dibuat (Bug, parent ED-1022, label v5 + ai-found); tidak diperbaiki di task ini
- 2026-10-16 03:37 ke QA r2 (agent sama, SendMessage): ref allow-list/KNOWN_5XX → ED-1168/ED-1169/ED-1166, nilai ulang AC-5/AC-7/AC-15 menurut preseden ED-1149 (500 dari defect bertiket di luar task = known), run konfirmasi sekali dengan alat uji final
- 2026-10-16 03:59 qa-fe r3 (v5-qa opus, a44b9d98ebceb8546, 17 mnt): **PASS** 16/16 (manual 0, n.v. 0). Alat uji: ref allow-list E-1/D-1/D-2 → ED-1166/ED-1168/ED-1169,
  known-5xx dipersempit (aktor+method+path+500+isi respons), AC-15 = 0 5xx tanpa tiket. Run 4 `261016104228` bersih sekali jalan (30 OK, 1 SKIP K-6), 5xx = 2, keduanya
  bertiket; defect baru 0; temuan ED-1070 0; `--leftover` 0; token 3/3 dicabut. Galeri `work/e2e/ED-1154-archive-qa-e2e-transaksi-serah-terima-20261016-034219/`.
  Dibuat run 4: SO-SMR/2610/00035, SP 00008, DO 00046, SA 00031, SI 00022, BILL 00005, RP 00022, folder E2E-ARSIP-261016104228; approval_transactions 42802
  (SALES_ORDER L2) + persetujuan customer SO/RP; A.0 dilewati (CST-10120 sudah 2); stok 019223 261→260; jurnal `work/ED-1154/runs/261016104228.json`
- 2026-10-16 cek kebocoran rahasia (17 berkas folder fitur): nilai password 0 berkas; hanya username login (QA_USER, QA_WAWAN_USER) yang memang disebut spec Gate 1
- 2026-10-16 BE 5c4c69bcd / FE 41146943c bersih (0 berkas berubah, changed.txt 0 baris) → tanpa commit BE/FE
- 2026-10-16 commit agentic: 4835c53 (17 berkas: features/ED-1154-*); BE/FE tanpa commit (tidak berubah)
- 2026-10-16 jira: ED-1154 label ai-review + komentar siap review 11544 (tanpa hash; menyebut ED-1168/ED-1169/ED-1166 + catatan preseden 500 bertiket); ED-1154/ED-1155 tetap In Progress; ED-1168/ED-1169 status BUG
- 2026-10-16 06:02 review batch ED-1022: developer MINTA COBA LAGI dua 500 (ED-1168 Shipment Acceptance driver Konfirmasi GE0114, ED-1169 SI Simpan & Konfirmasi sesudah Impor) - kemungkinan karena developer belum menjalankan server setting sesudah merge dari branch utama; dicek read-only: tabel sales_invoice_serials kini ada. phase: qa-fe (QA FE r4 tanpa allow-list 500 bertiket); label ai-review dilepas
- 2026-10-16 06:03 long run lanjut (story runner, QA FE r4): preflight OK (fpm); spec approved; BE 5c4c69bcd / FE 41146943c, changed.txt 0 baris; qa-fe r4 mulai (v5-qa opus): (1) coba ulang 2 aksi gagal (ED-1168, ED-1169), (2) e2e A/B/C penuh tanpa allow-list 500 bertiket
- 2026-10-16 06:23 qa-fe r4 (v5-qa opus, a0ad2b799181b1c9a, 19 mnt): **FAIL** PASS 13 / FAIL 3 (AC-5 ED-1168, AC-7 ED-1169, AC-15 2 x 500) / manual 0 / n.v. 0.
  Coba ulang di rekaman r3 tidak bisa (SA 00031 st 6, DO 00046 st 4, SI 00022 sudah ditagih), jadi dicoba ulang di rantai baru run 5 `261016131002`, yang sekaligus e2e A/B/C penuh tanpa allow-list
  (3 pemanggilan harness: 060950 + 2x `ED1154_RESUME`). ED-1168: w Konfirmasi SA-SMR/2610/00032 → 500 GE0114, DB tetap. ED-1169: i Simpan & Konfirmasi sesudah Impor DO-SMR/2610/00047 → 500
  Unknown column `sales_invoice_serials` (log BE 13:13:46), DB tetap. Dicek ulang read-only oleh runner: `ConfirmRequest.php:26-32` + log baris 5584 cocok. Tabel `sales_invoice_serials` ada sejak
  2026-10-06, jadi bukan server setting. Defect baru 0 (D-1 = ED-1168, D-2 = ED-1169, tanpa duplikat), temuan ED-1070 0, `--leftover` 0, token 3/3 dicabut, penolakan izin 0.
  Dibuat: SO-SMR/2610/00036, SP 00009, DO 00047, SA 00032, SI 00023, BILL 00006, RP 00023, folder E2E-ARSIP-261016131002, approval_transactions 42803 (SALES_ORDER L2), attendance w + a,
  stok 019223 260→259. Galeri `work/e2e/ED-1154-archive-qa-e2e-transaksi-serah-terima-20261016-{060950,061315,061535}/`. Alat uji: allow-list ED-1168/ED-1169 + KNOWN_5XX bawaan mati (`ED1154_ALLOW_TICKETED=1` menghidupkannya), jurnal `retry`
- 2026-10-16 06:25 blocker: QA FE gagal karena 2 bug bertiket di luar task, tidak bisa diperbaiki di task QA murni. Jira ED-1154 + ED-1155 → Blocked, label ai-blocker (ED-1154), komentar `[AI-BLOCKER]` 11547 (K-1),
  link ED-1168 blocks ED-1154 + ED-1169 blocks ED-1154. Status ED-1168/ED-1169 tidak diubah. Tanpa park (tidak ada kode BE/FE), tanpa commit (QA belum lolos), tiket baru 0
- 2026-10-16 06:27 cek kebocoran rahasia (18 berkas folder fitur + jurnal r4): nilai rahasia 0 berkas; perubahan agentic r4 (qa-fe.md, qa/e2e/ED-1154-archive-transaksi.spec.js, run.md) dibiarkan tanpa commit sampai QA lolos
- 2026-10-16 06:38 developer menjawab blocker r4: ED-1168 = pengecekan BE yang disengaja (driver tidak boleh confirm walau punya permission), dibiarkan; ED-1169 = diperbaiki di task lain, dibiarkan; kedua tiket akan dihapus developer -> knowledge/decisions.md A-9/A-10. Konsekuensi (setara K-1 c): A.5 wawan hanya Simpan SA (tanpa Konfirmasi driver), A.6 imansudjadi Accept; A.9 SI lewat Simpan lalu Konfirmasi di drawer; AC-15 tetap 0 5xx tanpa allow-list. Analyst memperbarui spec; phase: qa-fe (QA FE r5 atas instruksi developer)
- 2026-10-16 06:41 spec diperbarui analyst (R4-K1): A.5 w simpan SA (status 4) tanpa Konfirmasi driver, i Konfirmasi Transaksi di drawer SA (status 6); A.9 SI Impor -> Simpan -> Konfirmasi drawer; AC-5/AC-7 disesuaikan; AC-15 tetap 0 5xx tanpa allow-list; spec 12,9 KB; siap QA FE r5 atas instruksi developer
- 2026-10-16 long run lanjut (story runner, QA FE r5): preflight OK (fpm); spec approved (R4-K1); BE 5c4c69bcd / FE 41146943c bersih, changed.txt 0 baris; Jira ED-1154 + ED-1155 sudah In Progress, label ai-blocker sudah lepas (dicek); ED-1168/ED-1169 sudah tidak ada di Jira; qa-fe r5 mulai (v5-qa opus): alat uji ikut BR-8/BR-14 (A.5 w Simpan SA, i Konfirmasi Transaksi; A.9 Impor → Simpan → Konfirmasi drawer), allow-list 5xx mati, A/B/C sekali di rantai baru
- 2026-10-16 07:23 qa-fe r5 (v5-qa opus, ad0b5f4f19f45bf45, 16 mnt): **PASS** 16/16 (manual 0, n.v. 0). Alat uji: allow-list 5xx dihapus seluruhnya (`ED1154_ALLOW_TICKETED`, `KNOWN_5XX`, jurnal `retry`/`defects`, ref ED-1166/1168/1169), jalur A.5/A.6 (w Simpan SA, i Konfirmasi Transaksi) + A.9 (Impor → Simpan → Konfirmasi drawer) + pencatat `forbidden_paths`; fixture.php/routes.json/checkin.jpg tetap.
  Run 6 `261016140921` (07:09-07:16 UTC) satu pemanggilan tanpa resume (+1 `--list` tanpa login): 30 OK, 0 GAGAL, 1 SKIP (K-6). AC-5: SA 00033 st 4 oleh w (confirmed_by null, 0 PUT confirm dari w) → i Konfirmasi 200 → 6, confirmed_by i, is_pay 0, carrier 7; AC-7: SI st 1 → Konfirmasi drawer 200 (Q) → SI 00024 st 2, 0 save-and-confirm; AC-15: 0 5xx di 3 konteks, `forbidden_paths` [].
  Dibuat: SO-SMR/2610/00037, SP 00010, DO 00048, SA 00033, SI 00024, BILL 00007, RP 00024, folder E2E-ARSIP-261016140921, attendance w + a, approval_transactions 42804 (SALES_ORDER L2) + persetujuan customer SO/RP; A.0 dilewati (CST-10120 sudah 2); stok 019223 259→258. Jurnal `work/ED-1154/runs/261016140921.json`; galeri `work/e2e/ED-1154-archive-qa-e2e-transaksi-serah-terima-20261016-070910/`; bukti `work/ED-1154/r5/`.
  Defect baru 0; temuan ED-1070 0 (log BE: 3 OAuthServerException dari verifikasi log-out saja); `--leftover` 0 sebelum/sesudah; token 3/3 dicabut, tanpa force_login; penolakan izin 0; build staging tidak diulang (FE tidak berubah, `stale: false`); yarn test + regresi BE n/a
- 2026-10-16 cek kebocoran rahasia (195 berkas: folder fitur + work/ED-1154): nilai rahasia 0 berkas; spec e2e 0 ref ED-1166/1168/1169/KNOWN_5XX
- 2026-10-16 BE 5c4c69bcd / FE 41146943c bersih (0 berkas berubah, changed.txt 0 baris) → tanpa commit BE/FE
- 2026-10-16 commit agentic: a174c56 (4 berkas: qa-fe.md, qa/e2e/ED-1154-archive-transaksi.spec.js, run.md, spec.md; changed.txt sama dengan HEAD); BE/FE tanpa commit (tidak berubah)
- 2026-10-16 jira: ED-1154 label ai-review + komentar siap review 11549 (tanpa hash); ED-1154/ED-1155 tetap In Progress; tiket baru 0
- 2026-10-16 09:23 review batch ED-1022: disetujui developer -> Jira ED-1154 + subtask ED-1155 Done (transisi 71), label ai-review dilepas, komentar serah terima; phase done
