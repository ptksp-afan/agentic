# features/

Satu folder per Jira Story: `<KEY>-<slug>/` (sebelum Story dibuat: `NEW-yyyymmdd-<slug>/`, diganti
nama di Gate 1). Dibuat oleh `scripts/new-feature.sh`.

| Berkas | Penulis | Isi |
|---|---|---|
| `spec.md` | `v5-analyst` | aturan bisnis, model data, endpoint, FE per module, AC, subtask, keputusan |
| `brief/README.md` | `v5-brief` | digest brief: teks asli, sumber (PDF, desain, Jira), kebutuhan bersumber, layar dari desain, konflik. `brief/src/` (salinan) dan `brief/render/` (PNG desain) tidak di-commit |
| `contract.md` | `v5-analyst`, dirawat `v5-be-dev` | kontrak API snake_case untuk FE |
| `run.md` | orkestrator | state run (fase, subtask, keputusan, log) |
| `baseline/` | `new-feature.sh` | `git status` + hash per repo saat mulai; dasar `changed.sh` dan commit |
| `changed.txt` | `changed.sh` | berkas berubah per repo dibanding baseline |
| `qa-be.md`, `qa-fe.md` | `v5-qa` | laporan QA BE (sesudah BE) dan QA FE (sesudah FE), ronde terakhir |
| `qa/scenario*.php` | `v5-qa` | skenario HTTP/DB permanen (`scripts/qa-http`) |
| `qa/e2e/routes.json`, `qa/e2e/*.spec.js` | `v5-qa` | layar & alur browser (`scripts/e2e`) |

Task FE (`docs/specs/<Module>/NNN-*.md`, `_api.md`) tetap di repo FE, sesuai workflow FE.
