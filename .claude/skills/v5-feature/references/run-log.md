# run.md - the run's state

`scripts/new-feature.sh` creates it. You keep it current after every step that changes state, so a
fresh session (or a compacted one) can continue from it alone.

```markdown
---
key: ED-1234
epic: ED-1200
title: <spec title>
slug: <slug>
phase: be             # ba | gate1 | be | qa-be | fe | qa-fe | gate2 | commit | done | blocked
qa_round: 0
be_branch: v5-rr
fe_branch: next-canvasing
agents:               # last known agent ids + when they last reported (for SendMessage reuse)
  be_dev: {id: a1b2c3, at: 2026-10-06 10:40}
  fe_dev: {id: d4e5f6, at: 2026-10-06 10:55}
---
## Status ringkas
<= 8 lines, rewritten (not appended) at every phase change: where we are, what waits on whom.

## Subtask
| Kunci | Judul | Layer | Status Jira |

## Keputusan developer
- G1 K-1: ... (one line each, with the gate they came from)

## Usulan pipeline
- proposals for skills/agents/knowledge, applied only after `phase: done`

## Log
- 2026-10-06 09:10 ba: selesai, 9 BR / 14 AC / 6 subtask
- 2026-10-06 11:30 commit: BE abc1234, FE def5678, agentic 0a1b2c3
```

Rules:
- The baseline lives in `baseline/` (written by the script), not in this file. Never edit it.
- `## Status ringkas` is the first thing a fresh session reads; keep it true and short.
- `## Log` is append-only, one line per event. No pasted outputs: link files instead.
- Keep the whole file under ~8 KB. When the log grows past that, fold older lines into one summary line.

Resume = `scripts/preflight.sh`, then `scripts/section.sh run.md "Status ringkas"` plus the frontmatter,
then continue at `phase:`. Agent ids older than ~1 hour are stale for cost purposes (their cache has
expired): prefer a fresh spawn that reads the files.
