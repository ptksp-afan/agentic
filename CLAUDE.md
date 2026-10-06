# Workspace agentic - pengembangan fitur v5 fullstack (BE ksp-erp + FE ksp-react)

Bicara dengan developer dalam **bahasa Indonesia**. Berkas ini dimuat di setiap giliran: jaga tetap pendek.

- Entry point: `/v5-feature <KEY>` (skill `.claude/skills/v5-feature`). Status: `/v5-feature status`.
- Long run satu epic: `/v5-epic <EPIC>` (skill `.claude/skills/v5-epic`); keputusan developer jadi kartu Blocked.
- Repo & port ada di `config/workspace.env` (`BE_DIR`, `FE_DIR`, ...). Jangan menulis path mesin di tempat lain.
- Satu folder per Story: `features/<KEY>-<slug>/` (spec, contract, run log, laporan QA, skrip QA).
- Pengetahuan lintas fitur: `knowledge/README.md` (indeks; baca berkas yang perlu saja).

Aturan tetap:
- Tidak pernah `git push`. Commit hanya sesudah Gate 2 (long run: commit lokal sesudah QA lolos), satu per
  repo, path eksplisit. Jira Done hanya sesudah developer menyetujui.
- Tidak pernah build FE ke `build/` developer; tidak pernah mematikan proses developer.
- Rahasia (`config/secrets.env`, token, password) tidak pernah dicetak atau ditulis ke berkas lain.
- Penolakan izin/classifier tidak diakali: laporkan dan minta developer memutuskan.
- Hemat konteks: kirim path bukan isi, baca per bagian (`scripts/section.sh`), satu fitur per sesi.
- Skill, agent, dan knowledge tidak diubah di tengah run fitur; usulan dicatat di `run.md`.
