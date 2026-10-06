# Dari pipeline multidb ke workflow v5 general

Sumber: sesi `f328d0e3` (dan pendahulunya `43f101b5`), branch BE `v5-rr-multidb`, FE
`next-canvasing-multidb-ai`, per 2026-10-06. Berkas di branch multidb **tidak diubah sama sekali**:
pipeline multidb tetap jalan seperti sekarang.

## 1. Peta berkas

| Sumber (multidb) | Tujuan (general) | Perubahan |
|---|---|---|
| BE `.claude/skills/multidb-feature/SKILL.md` | agentic `.claude/skills/v5-feature/` (+ `references/`) | epic dinamis (parent Story / `--epic`), mode `new`, Gate 1 sekaligus sinkron Jira, BE ‖ FE plan+slice paralel, review BE baru, path & port dari config, aturan hemat konteks. Detail Jira/gate/commit dipindah ke `references/` supaya SKILL.md tetap kecil |
| BE `.claude/agents/multidb-analyst.md` | agentic `.claude/agents/v5-analyst.md` | sumber = Story + brief (+ v3 bila ada); bagian baru *Model data*; AC menyebut cara cek (`http`/`unit`/`e2e`/`manual`); batas 30 KB. Matriks central/cabang & alur lintas DB dihapus |
| BE `.claude/agents/multidb-qa.md` | agentic `.claude/agents/v5-qa.md` | DB uji dari config; cek permission 403 & isolasi tenant (bila ≥ 2 DB); bukti isi gambar; batas laporan 25 KB; ronde berikut = agent baru |
| BE `.claude/agents/v5-backend-dev.md` | BE `.claude/agents/v5-be-dev.md` | tanpa epic/multidb; mode pipeline **dan** mandiri; preload skill `v5-be-conventions` |
| - | BE `.claude/agents/v5-be-reviewer.md` | **baru**: pasangan BE dari `convention-reviewer` FE (sonnet, mekanis) |
| BE `.claude/skills/v5-conventions/` | BE `.claude/skills/v5-be-conventions/` | hanya fakta yang benar di `origin/v5-rr` (~30 sitasi `file:line` yang bergeser diperbaiki); `cross-db.md` → `connections.md` (aturan "dua koneksi" umum, tanpa helper multidb); topologi central/cabang, lisensi integrasi, ekspor laporan central dibuang |
| BE `scripts/multidb/run.php`, `lib/*` | agentic `scripts/qa-http/` | config dari `config/*.env`; `session($db)` dibatasi `QA_DBS`; `centralDb()` → `globalDb()`; fixture F01/F13 dibuang |
| BE `scripts/multidb/license.sh`, `license-probe.php` | agentic `scripts/profile.sh`, `scripts/lib/profile-probe.php` | profil dari config (bukan integrasi/reguler tetap); `status` exit 0/4/2; profil nonaktif → `run` gagal |
| BE `scripts/multidb/e2e/**` | agentic `scripts/e2e/` | profil login dari config; rute & tes per fitur di `features/<KEY>/qa/e2e/`; tes F01/F02/F13 dibuang |
| BE `scripts/multidb/scenarios/F*.php` | - | spesifik fitur multidb, tetap di branch multidb |
| BE `docs/multidb/README.md` (keputusan #1-#11) | agentic `knowledge/decisions.md` | hanya yang berlaku umum |
| BE `docs/multidb/env.md` | agentic `knowledge/environment.md` + `config/workspace.env` | fakta mesin; data central/cabang dibuang |
| BE `docs/multidb/runs/*`, `features/*` | agentic `features/<KEY>-<slug>/` (format) | run log jadi `run.md` dengan `## Status ringkas`; baseline jadi berkas + hash (`changed.sh` membedakan NEW / PRE / PRE+) |
| BE `docs/multidb/v3-map.md` | - | khusus mekanisme multi-DB v3 |
| memory `jira-ed-conventions` | `.claude/skills/v5-feature/references/jira.md` + `config/jira.json` | sama, tanpa ED-944 |
| memory `fe-build-path` | `scripts/fe-build.sh` + `references/fe-build.md` | aturan jadi script: `build` ke staging, `publish` cek port lalu salin |
| memory `php-nts-binary`, `force-login-allowed` | `knowledge/environment.md`, `decisions.md` | |
| FE `.claude/**`, `docs/WORKFLOW.md`, `docs/specs/README.md` (perubahan workflow di branch multidb-ai) | FE (folder `ksp-react/`) | **sama persis byte-per-byte** dengan multidb-ai, kecuali hal yang menyebut kode khusus multidb (§3) |
| BE `.claude/settings.json` | agentic `.claude/settings.json` | branch v5 BE **tidak** menambah settings.json (hindari add/add) |
| BE `scripts/conformance/`, `.claude/agents/conformance-reviewer.md` | - | proyek terpisah milik developer (untracked), tidak disentuh |

## 2. Yang sengaja tidak dibawa

Lisensi `equal_integration` sebagai syarat setiap kondisi, sesi central/cabang, `routeScopes.js`,
`MyHelper::isCentralSession`/`onDefaultConnection` (belum ada di `v5-rr`), Updater `CentralDatabase`,
urutan commit lintas DB, skenario dan fixture F00-F14. Semua itu tetap hidup di branch multidb.

## 3. Strategi bebas konflik

**Prinsip:** branch v5 general akan di-merge ke branch multidb. Konflik git hanya muncul bila kedua sisi
mengubah baris yang sama secara berbeda, atau menambah path yang sama dengan isi berbeda.

| Repo | Strategi | Hasil simulasi* |
|---|---|---|
| BE | **nama berbeda** untuk semua berkas baru (`v5-be-*`), tidak menyentuh `v5-conventions`, `multidb-*`, `v5-backend-dev`, `settings.json` | merge `v5-rr`+berkas ini → `v5-rr-multidb`: **0 konflik**; hasilnya = multidb HEAD + 10 berkas baru, tidak ada berkas multidb yang berubah |
| FE | **isi identik**: berkas workflow diambil persis dari `next-canvasing-multidb-ai`, jadi kedua sisi membuat perubahan yang sama | merge `next-canvasing`+berkas ini → `next-canvasing-multidb-ai`: **0 konflik**, dan hasil merge di `.claude/`, `docs/`, `CLAUDE.md` **identik** dengan multidb-ai (yang berbeda hanya `src/` dari commit next-canvasing biasa) |
| agentic | repo sendiri | tidak pernah konflik dengan BE/FE |

*Simulasi: clone `--shared` di scratchpad, commit tiruan di atas `origin/next-canvasing` / `origin/v5-rr`,
lalu `git merge-tree --write-tree` ke branch multidb. Repo asli tidak disentuh.

FE yang **sengaja tidak** disalin dari multidb-ai (perubahannya menyebut kode yang hanya ada di branch
multidb, mis. `getAttachmentUrl`, `ContactCenterAlert`, `mapUserIntegrationError`):
`references/component-catalog.md`, `references/hooks-helpers.md`, `CLAUDE.md`, dan angka "117
components" di deskripsi `equal-conventions`. Karena hanya sisi multidb yang mengubahnya, merge tetap bersih.

Aturan agar tetap bebas konflik ke depan:
1. Perubahan workflow FE dibuat **di branch multidb-ai dulu** (di sana pipeline paling aktif), lalu
   disalin **utuh** ke branch v5 - jangan menyunting berkas yang sama secara berbeda di dua branch.
2. Bila tetap konflik di `.claude/**` FE saat merge ke multidb-ai: ambil versi multidb-ai
   (`git checkout --ours -- <path>`), karena ia superset.
3. Di BE, branch v5 hanya menambah/mengubah `v5-be-*`. Branch multidb hanya mengubah `v5-conventions`,
   `multidb-*`, `v5-backend-dev`.

## 4. Sesudah merge (opsional, di branch multidb)

Setelah branch v5 masuk ke multidb, BE punya dua skill konvensi: `v5-be-conventions` (umum) dan
`v5-conventions` (umum + multi-DB). Supaya tidak dobel, di satu commit `[DOC]` terpisah:
1. Ganti nama `v5-conventions` → `v5-multidb-conventions`, sisakan hanya bagian multi-DB (`cross-db.md`,
   keputusan multi-DB, RR khusus lintas DB), dengan baris "muat `v5-be-conventions` lebih dulu".
2. Agent `v5-backend-dev` dan `multidb-*` memuat keduanya.
Ini perubahan di branch multidb sendiri, bukan konflik merge; boleh ditunda sampai epic ED-944 selesai.
