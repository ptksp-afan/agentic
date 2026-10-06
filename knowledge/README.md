# Indeks knowledge

Jangan dibaca semua. Orkestrator memberi tiap agent path berkas yang ia perlukan.

| Berkas | Isi | Dibaca oleh |
|---|---|---|
| `decisions.md` | keputusan developer yang berlaku untuk semua fitur v5 (tidak dibuka ulang) | analyst, dev BE, QA |
| `lessons.md` | pelajaran dari pipeline multidb, per peran | analyst (§ Spec), dev, QA, orkestrator |
| `environment.md` | fakta mesin lokal: PHP, RR, DB, Node, build FE, `serve` | siapa pun yang menjalankan perintah, saat butuh |
| `cost-and-cache.md` | angka biaya/cache dan aturan hemat konteks | orkestrator, developer |

Konvensi kode **tidak** ada di sini, karena ikut repo masing-masing:
- BE: `<BE_DIR>/.claude/skills/v5-be-conventions/`
- FE: `<FE_DIR>/CLAUDE.md` + `<FE_DIR>/.claude/skills/equal-conventions/`

Aturan Jira ada di `.claude/skills/v5-feature/references/jira.md` (dipakai orkestrator saja).
