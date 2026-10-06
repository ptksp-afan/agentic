# agentic - workflow fullstack v5 (BE ksp-erp + FE ksp-react)

Versi umum dari pipeline multi-DB (`/multidb-feature`, epic ED-944) untuk mengembangkan **fitur atau
module baru v5** di BE dan FE sekaligus, untuk epic Jira mana pun.

```
BA ─▶ Gate 1 (+ sinkron Jira) ─▶ Dev BE ‖ FE plan+slice ─▶ digest API ─▶ FE logic ─▶ review BE+FE
   ─▶ QA (HTTP/DB + test FE + Playwright, maks 2 ronde perbaikan) ─▶ Gate 2 ─▶ commit per repo ─▶ Jira Done
```

> **Nama folder.** Di simulasi ini namanya `agentic`. Saran saat dipindah ke `D:\dev`: **`ksp-agentic`**
> (sejajar dan berkelompok dengan `ksp-erp-*` / `ksp-react*`, jelas isinya). Tidak ada script atau
> dokumen yang bergantung pada nama folder ini.

## Isi

```
agentic/                       ← cwd sesi Claude; repo git sendiri
  CLAUDE.md                    aturan tetap (pendek: dimuat setiap giliran)
  .claude/
    settings.json              izin + TTL cache 1 jam (sesi utama & subagent)
    settings.local.json        (per mesin, dari .example) additionalDirectories = BE_DIR, FE_DIR
    skills/v5-feature/         orkestrator + references/ (jira, gates, agents, run-log, fe-build, commit)
    agents/v5-analyst.md       BA fullstack
    agents/v5-qa.md            QA independen
  config/                      workspace.env(.example), secrets.env(.example), jira.json
  knowledge/                   keputusan, pelajaran, lingkungan, biaya & cache
  scripts/                     preflight, new-feature, changed, section, status, fe-build, profile,
                               qa-http/ (runner skenario PHP), e2e/ (harness Playwright)
  templates/qa/                contoh skenario PHP dan tes e2e
  features/<KEY>-<slug>/       spec, contract, run log, laporan QA, skrip QA per Story
  docs/MIGRATION.md            peta dari pipeline multidb + strategi bebas konflik merge
```

Yang **ikut repo aplikasi** (disalin dari `../ksp-erp` dan `../ksp-react` di folder simulasi ini):

| Repo | Berkas | Kenapa di repo, bukan di sini |
|---|---|---|
| BE | `.claude/skills/v5-be-conventions/`, `.claude/agents/v5-be-dev.md`, `.claude/agents/v5-be-reviewer.md` | menjelaskan kode BE; berguna juga saat developer bekerja langsung di repo BE tanpa orkestrator |
| FE | `.claude/skills/{equal-conventions,ui-*}`, `.claude/agents/convention-reviewer.md`, `docs/WORKFLOW.md`, `docs/specs/README.md` | workflow FE (mode otomatis + batch) yang sudah matang di branch multidb; isinya sama persis supaya merge bersih |

Semua yang bersifat **proses lintas repo** (orkestrasi, BA, QA, Jira, harness uji, run log) ada di sini,
sehingga branch BE/FE tidak pernah konflik karena perubahan pipeline.

## Setup (sekali per mesin)

1. **Checkout terpisah dari multidb.** BE: `D:\dev\ksp-erp-next` (sudah ada, `v5-rr`, RR `:8002`). FE:
   ```bash
   git -C /d/dev/ksp-react worktree add /d/dev/ksp-react-v5 next-canvasing
   ```
   lalu `cd /d/dev/ksp-react-v5 && nvm use $(cat .nvmrc) && yarn install`.
2. **Commit berkas repo** (lihat `docs/MIGRATION.md` §3): salin `ksp-erp/.claude/**` ke checkout BE v5 dan
   `ksp-react/**` ke checkout FE v5, commit di branch v5 masing-masing.
3. **Config:** salin `config/workspace.env.example` → `config/workspace.env` dan
   `config/secrets.env.example` → `config/secrets.env`, isi. Pilih `QA_DB` yang tidak dipakai multidb.
4. **Repo sebagai direktori tambahan:** salin `.claude/settings.local.json.example` →
   `.claude/settings.local.json`, sesuaikan path. Skill dan agent BE/FE lalu termuat by-name.
5. **Harness e2e:** `cd scripts/e2e && npm ci` (Chrome sistem dipakai; tidak mengunduh browser).
6. `git init` folder ini (repo sendiri), lalu `scripts/preflight.sh` harus OK semua.

## Pemakaian

Buka sesi Claude dengan cwd folder ini, lalu:

| Perintah | Arti |
|---|---|
| `/v5-feature ED-1234` | jalankan atau lanjutkan Story ED-1234 (epic = parent Story) |
| `/v5-feature new docs/brief.md --epic ED-1200` | fitur tanpa Story: BA dulu, Story dibuat saat Gate 1 disetujui |
| `/v5-feature status [ED-1200]` | daftar fitur + fase + status Jira |

Developer hanya dibutuhkan di **Gate 1** (setujui spec, jawab keputusan) dan **Gate 2** (lihat galeri
e2e, jalankan daftar tes UI manual, setujui commit). Saat jeda panjang, mulai **sesi baru** dan ketik
`/v5-feature <KEY>`: state lengkap ada di `features/<KEY>-*/run.md` (lebih murah daripada melanjutkan
sesi lama, lihat `knowledge/cost-and-cache.md`).

## Status

Semua berkas ditulis dan diperiksa sintaksnya (`bash -n`, `php -l`, `node --check`, mode `--list`).
Alur lengkapnya **belum pernah dijalankan** pada Story sungguhan. Fitur pertama sebaiknya kecil, dan
usulan perbaikan dari run itu dicatat di `run.md` `## Usulan pipeline`.
