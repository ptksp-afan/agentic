# Biaya & prompt cache

Diukur dari transcript dua sesi multidb (`43f101b5` F00-F01, `f328d0e3` F01-F02), 2026-10-06.
Satuan "u" = harga 1 token input biasa. Tarif cache: baca 0,1u · tulis TTL 5 menit 1,25u · tulis TTL
1 jam 2,0u ([prompt caching](https://platform.claude.com/docs/en/build-with-claude/prompt-caching)).

## Yang terjadi

| | Panggilan | Konteks rata-rata | Cache baca | Cache tulis | Hit rate |
|---|---|---|---|---|---|
| Sesi utama `43f101b5` | 475 | 420K | 197M | 2,4M (1 jam) | 98,8% |
| Sesi utama `f328d0e3` | 504 | 460K | 224M | 7,8M (1 jam) | 96,7% |
| Subagent (dua sesi, 50 agent) | 7.455 | 340K | 2.513M | 51,1M (**5 menit**) | 98,0% |

Porsi biaya sisi input (dua sesi): **baca cache subagent 67%**, tulis cache subagent 17%, baca sesi utama
11%, tulis sesi utama 5%. Output < 2%.

Hit rate 97% memang tinggi, tapi bukan ukuran yang tepat:
- Yang dibayar adalah **token × panggilan**. Agent dengan konteks 400K membaca 400K token cache di
  *setiap* panggilan tool, walaupun 99% hit.
- 3% tulis cache bernilai 20-40% biaya, karena tarif tulis 12-20× tarif baca.

Penyebab tulis cache yang bisa dihindari:
- **Sesi utama:** 12 dari 13 tulis besar (6,6M dari 7,8M = 85%) terjadi sesudah sesi diam > 1 jam
  (menunggu developer / ganti hari). Seluruh konteks 450K ditulis ulang.
- **Subagent:** TTL 5 menit. 63 tulis besar (32M dari 51M = 63%) terjadi sesudah jeda > 5 menit:
  `yarn build`, full test suite, e2e, menunggu RR reload.

## Aturan yang dipasang di workflow ini

| Aturan | Di mana | Perkiraan efek* |
|---|---|---|
| `CLAUDE_CODE_SUBAGENT_PROMPT_CACHE_TTL=1h` (dan `CLAUDE_CODE_PROMPT_CACHE_TTL=1h`) | `.claude/settings.json` `env` | tulis cache subagent ~63,9 jt u → ~41 jt u untuk volume dua sesi di atas (-6% total); hit subagent ~99% |
| Satu fitur per sesi; jeda panjang = sesi baru `/v5-feature <KEY>` dari `run.md` | skill `v5-feature`, `gates.md` | konteks utama ~440K → ~100-150K: -10-12% total |
| Batas ukuran dokumen: spec ≤ 30 KB, `qa-be.md`/`qa-fe.md` ≤ 20 KB, `run.md` ≤ 8 KB, skenario dipecah > 40 KB (dulu spec 58-105 KB, laporan QA sampai 105 KB, skenario 160-270 KB) | agent `v5-analyst`, `v5-qa`, `run-log.md` | konteks subagent turun langsung; tiap KB dibaca ≥ 3 agent |
| Brief dibaca sekali oleh `v5-brief` (sonnet) jadi digest ≤ 12 KB; analyst, FE, QA membaca digest, bukan PDF/desain aslinya | `v5-brief` | PDF 30 halaman tidak dibaca ulang oleh 3-4 agent |
| Baca per bagian (`scripts/section.sh`), kirim path bukan isi, laporan agent ≤ 15-25 baris | skill + agent | konteks orkestrator kecil |
| Output panjang ke `work/*.log`, yang dibaca hanya ringkasan/tail | agent + script (`fe-build.sh`) | |
| Model sesuai beban: sonnet untuk digest API, review konvensi, QA default | `references/agents.md` | |
| Ronde QA = agent baru dengan cakupan defect + regresi, bukan agent lama yang konteksnya sudah 350K | `references/agents.md` | |
| Ronde perbaikan dev ke agent yang sama hanya bila laporannya < 1 jam (cache masih hangat) | `references/agents.md` | |
| Skill/agent/knowledge tidak diubah di tengah run (prefix cache & konsistensi) | `SKILL.md` | |

*Perkiraan dari angka di atas, bukan pengukuran ulang. Ukur lagi sesudah 1-2 fitur: jalankan
`/explain-usage` atau hitung dari transcript (`~/.claude/projects/<proyek>/<sesi>.jsonl`, field `usage`).

Catatan: sesi baru dimulai dengan menulis cache (~40K), jadi hit rate per sesi bisa sedikit **turun**
walaupun total biaya turun banyak. Pantau biaya total per fitur, bukan hit rate.

## Long run (`/v5-epic`)

BA semua item jalan paralel (maks `BA_PARALLEL`, default 2), masing-masing dengan konteks baru. Setiap item berjalan di story runner dengan konteks baru, jadi konteks tidak menumpuk antar item. Sesi
utama hanya memegang antrian dan laporan ≤ 10 baris per item. Batas rem biaya: maks 2 ronde perbaikan
QA per item, berhenti sesudah 3 blocker berturut-turut, dan `--max N`. Untuk percobaan pertama,
jalankan `--max 2` lalu ukur biayanya per item sebelum menjalankan satu epic penuh.

## Hal lain yang memutus cache (dokumentasi Claude Code)

Ganti model atau effort di tengah sesi, menyambung/melepas MCP server, mengaktifkan plugin, compaction.
Pilih model di awal sesi. MCP yang tidak dipakai workflow ini (Figma, Chrome) sebaiknya tidak tersambung
di sesi orkestrator.
