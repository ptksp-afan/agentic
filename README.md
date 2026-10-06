# agentic - workflow fullstack v5 (BE ksp-erp + FE ksp-react)

Versi umum dari pipeline multi-DB (`/multidb-feature`, epic ED-944) untuk mengembangkan **fitur atau
module baru v5** di BE dan FE sekaligus, untuk epic Jira mana pun.

```
brief ─▶ BA ─▶ Gate 1 (+ sinkron Jira) ─▶ BE + review ─▶ QA BE ─▶ digest API ─▶ FE + review ─▶ QA FE
   ─▶ Gate 2 ─▶ commit per repo ─▶ Jira Done        (bergiliran, satu pekerjaan berat sekali waktu)
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
    skills/v5-feature/         orkestrator + references/ (jira, gates, agents, run-log, fe-build, commit, long-run)
    skills/v5-epic/            long run satu epic + review gabungan
    agents/v5-analyst.md       BA fullstack
    agents/v5-qa.md            QA independen
  config/                      workspace.env(.example), secrets.env(.example), jira.json
  knowledge/                   keputusan, pelajaran, lingkungan, biaya & cache
  epics/<EPIC>.md              log long run per epic (antrian, hasil, ringkasan)
  scripts/                     preflight, new-feature, changed, section, status, fe-build, profile, park/unpark,
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
| `/v5-feature ED-1234 ["<brief>"]` | jalankan atau lanjutkan Story ED-1234 (epic = parent Story) |
| `/v5-feature new "<brief>" --epic ED-1200` | fitur tanpa Story: BA dulu, Story dibuat saat Gate 1 disetujui |
| `/v5-feature status [ED-1200]` | daftar fitur + fase + status Jira |

Developer hanya dibutuhkan di **Gate 1** (setujui spec, jawab keputusan) dan **Gate 2** (lihat galeri
e2e, jalankan daftar tes UI manual, setujui commit). Saat jeda panjang, mulai **sesi baru** dan ketik
`/v5-feature <KEY>`: state lengkap ada di `features/<KEY>-*/run.md` (lebih murah daripada melanjutkan
sesi lama, lihat `knowledge/cost-and-cache.md`).

### Brief

Brief boleh berupa teks singkat yang merujuk berkas lokal dan tautan, sekaligus. Ini berlaku di run biasa
maupun long run (`/v5-epic ED-1200 --brief "..."` untuk brief tingkat epic). Deskripsi, lampiran, dan
tautan di kartu Jira juga ikut dibaca.

```
/v5-feature new "Gudang transit. Aturan bisnis di D:\docs\transit.pdf hal. 3-9, layar di
  D:\export\gudang-transit.zip (export Claude Design), contoh laporan D:\docs\contoh.xlsx" --epic ED-1200
```

Yang didukung: teks, `.md/.txt`, PDF, gambar, `.docx/.xlsx/.pptx`, export Claude Design (HTML, folder,
zip), tautan artifact claude.ai, lampiran Jira. Agent `v5-brief` membaca semuanya **sekali**, me-render
desain HTML ke PNG desktop + mobile, lalu menulis satu digest `features/<KEY>/brief/README.md`. Agent
lain membaca digest itu, bukan sumber aslinya. Sumber yang tidak terbaca (mis. tautan Claude Design yang
belum di-export) ditanyakan ke kamu sebelum BA (run biasa) atau di Gate 1 (long run).

**Desain Claude Design = acuan struktur, bukan tampilan.** Karena design system EQUAL v5 belum ada di
Claude Design, agent hanya mengambil layar, field, kolom, aksi, alur, dan state dari desain. Tampilan
mengikuti komponen dan pola FE v5 yang sudah ada. Perbedaan visual dicatat sebagai "Beda dari desain",
bukan dianggap bug. Aturan lengkapnya ada di `knowledge/design-sources.md`.

## Long run: satu epic sekaligus

| Perintah | Arti |
|---|---|
| `/v5-epic ED-1200 --dry-run` | tampilkan antrian (Story, Task, Bug di epic, urutan board) tanpa menjalankan apa pun |
| `/v5-epic ED-1200 [--max N] [--only ...]` | BA semua item → Gate 1 gabungan → long run; bisa dilanjutkan kalau terhenti |
| `/v5-epic review ED-1200` | review gabungan: tiap item **Setujui** (→ Done) / **Minta perbaikan** / **Tunda** |
| `/v5-epic status ED-1200` | isi `epics/ED-1200.md` |

Urutannya:

```
antrian (kamu setujui) ─▶ BA semua item (maks BA_PARALLEL, default 2) ─▶ cek silang antar spec (tumpang tindih, dependensi)
  ─▶ GATE 1 GABUNGAN (kamu: jawab semua keputusan, setujui / revisi / tunda per item; Jira disinkronkan)
  ─▶ LONG RUN tanpa kamu, per item: BE → QA BE → FE → QA FE → commit lokal, satu item sekali waktu
  ─▶ REVIEW GABUNGAN (kamu: setujui → Done / minta perbaikan)
```

Cara kerjanya:
- Developer hadir di tiga titik: menyetujui antrian, Gate 1 gabungan, dan review akhir. Selama BA
  berjalan kamu boleh pergi; Gate 1 menunggu (notifikasi push kalau tersedia). Kode baru ditulis sesudah
  item itu disetujui di Gate 1.
- **Keputusan yang baru muncul saat long run** (agent dev `BLOCKED:`, pertanyaan digest API yang tidak
  terjawab dari kode BE, QA gagal 2 ronde) → kartu pindah ke kolom **Blocked** + label `ai-blocker` +
  komentar `[AI-BLOCKER]` berisi pertanyaan dan rekomendasi. Kode yang sudah ada diparkir ke branch lokal
  `park/<KEY>`, working tree kembali bersih, lalu item berikutnya jalan.
- Item yang **bergantung** pada item yang blocked (`depends_on` di spec, link Jira "is blocked by", atau
  memakai endpoint/module/tabel item itu) ikut Blocked dengan link `Blocks`. Yang tidak bergantung tetap
  dikerjakan.
- Item yang ditambahkan ke epic belakangan ikut BA + Gate 1 di `/v5-epic` berikutnya.
- **Epic masih kosong** (belum ada Story) tapi ada brief: `/v5-epic ED-1022 --brief "..."` lebih dulu
  memecah epic jadi rencana item (`epics/ED-1022-plan.md`), kamu setujui rencananya, lalu BA per item →
  Gate 1 gabungan. Story baru dibuat di Jira saat item disetujui di Gate 1.
- **Melepas blocker:** balas komentar `[AI-BLOCKER]` (mis. `K-1: a, K-2: b`), lalu pindahkan kartu ke
  To Do. Long run berikutnya membaca jawaban itu dan melanjutkan dari fase yang terhenti.
- Item yang lolos QA di-**commit lokal** (tanpa push), tetap In Progress dengan label `ai-review`.
  **Done hanya lewat `/v5-epic review`**, karena Done menyerahkan pekerjaan ke QA manusia dan tidak bisa
  ditarik balik dari akun ini.
- Run berhenti sendiri kalau lingkungan bermasalah (server mati, preflight gagal) atau 3 item berturut-turut
  blocked (kemungkinan masalah sistemik).

Sebelum long run:
- Pastikan mesin tidak tidur. `scripts/preflight.sh --long-run` mengeceknya (Windows `powercfg`, macOS
  `pmset`, Linux `sleep.target`). PC Windows ini hibernate setelah 3 jam tanpa input:
  `powercfg /change hibernate-timeout-ac 0`. Di macOS bisa juga menjalankan sesi di bawah `caffeinate -i`.
- Pakai mode izin **auto** di sesi itu, dan tambahkan server MCP Jira ke `allow` di
  `.claude/settings.local.json` (contoh di `.example`; id server bisa berbeda per akun). Prompt izin yang
  muncul saat kamu pergi menahan run sampai kamu kembali.
- Satu item dikerjakan sekali waktu, karena working tree dan server BE dipakai bersama.

## Windows, Linux, macOS

Workflow ini jalan di ketiganya. Yang dibutuhkan di setiap mesin:

| Perlu | Windows | Linux | macOS |
|---|---|---|---|
| Shell untuk script | **Git Bash** (bukan PowerShell/cmd) | bash | bash 3.2 bawaan sudah cukup |
| Git ≥ 2.23, curl, Node + nvm (FE), PHP 7.3 (BE) | nvm-windows; `PHP_BIN` = php-nts | nvm (`~/.nvm`) | nvm (`~/.nvm`) |
| Chrome untuk e2e | Chrome terpasang | `google-chrome` / `/opt/google/chrome` | `/Applications/Google Chrome.app` |
| Server BE | RoadRunner/php-fpm per checkout, port di `.rr.env` | sama | sama |

- Semua path mesin ada di `config/workspace.env`. Di Windows boleh `D:/dev/...` (script mengubahnya ke
  `/d/dev/...`); di Linux/macOS pakai path biasa.
- `.gitattributes` memaksa LF, karena script dengan CRLF gagal di bash Linux/macOS.
- Script tidak memakai fitur khusus GNU/bash 4 (`sed -i`, `date -r`, `mapfile`, `${x,,}`), dan sudah diuji
  di Git Bash Windows. Di Linux/macOS **belum dijalankan**: jalankan `scripts/preflight.sh` dan
  `scripts/e2e/run.sh --doctor` sekali saat setup.
- `knowledge/environment.md` berisi fakta mesin Windows developer; mesin lain cukup menyesuaikan config.

## Status

Semua berkas ditulis dan diperiksa sintaksnya (`bash -n`, `php -l`, `node --check`, mode `--list`).
Alur lengkapnya **belum pernah dijalankan** pada Story sungguhan. Fitur pertama sebaiknya kecil, dan
usulan perbaikan dari run itu dicatat di `run.md` `## Usulan pipeline`.
