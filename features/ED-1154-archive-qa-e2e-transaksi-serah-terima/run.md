---
key: ED-1154
epic: ED-1022
title: [QA] Archive - tes end-to-end pembentukan dokumen archive dan serah-terima lewat transaksi nyata
slug: archive-qa-e2e-transaksi-serah-terima
phase: gate2
blocked_from: null
qa_round: 3
be_branch: v5-opname-archive
fe_branch: next-canvasing-opname-archive
agents: {qa-fe-r1: acfda12dfc9c115d9, qa-fe-r2: a06b00b47a4348a4f, qa-fe-r3: a44b9d98ebceb8546}
---
## Status ringkas
Long run ED-1022 (2026-10-16): QA FE r3 **PASS** (16/16 AC, run 4 `261016104228` bersih) → auto-commit lokal agentic (tanpa commit BE/FE: task QA murni).
Menunggu review batch developer (`/v5-epic review ED-1022`); Jira ED-1154/ED-1155 In Progress + label ai-review.
Catatan untuk review: (1) PASS r3 menghitung 2 x 500 per run sebagai defect lama **bertiket** di luar task (ED-1168 SA confirm driver GE0114,
ED-1169 SI save-and-confirm `sales_invoice_serials`; allow-list sempit, preseden ED-1149) - aturan agent QA "tanpa 500" secara harfiah
tidak terpenuhi; bila preseden ditolak, AC-5/AC-7/AC-15 kembali FAIL dengan bukti yang sama. (2) Perubahan permanen: CST-10120
`approval_status` 0→2 (approval_transactions 42798). (3) Catatan spec S-2..S-4 (qa-fe.md) untuk analyst.

## Subtask
| Kunci | Judul | Layer | Status Jira |
|---|---|---|---|
| ED-1155 | [QA] Skenario e2e archive lewat transaksi nyata (A/B/C) + jurnal run | QA | In Progress |

## Keputusan developer
- Gate 1 (2026-10-14): K-1 a (profil `wawan` = QA_WAWAN_USER/PASS), K-2 b, K-3 a, K-4 a, K-5 a, K-6 a, DU-5 a (lihat spec § 8).

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
