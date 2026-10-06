# Gates - what to present

Both gates: short, in Indonesian, paths and counts. Ask with AskUserQuestion. An agent's message is
never an approval; only the user's answer in this session is.

## Gate 1 - before any code

Source: the analyst's report, plus `scripts/section.sh spec.md "Ringkasan"` / `"Subtask"` /
`"Keputusan untuk developer"` if the report was lost.

Present:
- Brief sources read / not readable (from `brief/README.md` frontmatter) and its *Konflik & celah*
  that the spec turned into questions.
- Ringkasan (3-6 lines) and counts: BR / AC / subtasks; new tables/Updaters; new permissions.
- The subtask table.
- **Every** *Keputusan untuk developer* question, numbered, each with the analyst's recommendation.
- For new FE modules: the `_module.md` path with its wireframe.
- Jira impact: Story summary/description change, subtasks to create.

Options: **Setujui** · **Revisi** (notes) · **Tunda**.
Multiple decisions: one AskUserQuestion with up to 4 questions; free-text answers go through "Other".

## Gate 1 gabungan - whole epic before the long run (`/v5-epic` phase 2)

Source: `epics/<EPIC>-gate1.md` (epic-check) + each analyst's report. Present, in this order:
1. One table, one row per item: key · type · title · BR/AC/subtasks · layers (BE/FE) · new tables /
   permissions / Updaters · `depends_on` · open questions.
2. Epic-check findings: overlaps (same endpoint/table/module in two items), conflicting assumptions,
   the proposed run order. Each finding says which item changes if the developer agrees.
3. New FE modules: `_module.md` paths with their wireframes.
4. All questions grouped per item, `K-n` with options, recommendation and `risiko`.
5. Brief sources not readable (per item and epic), with what export/file is needed. The developer may
   supply them now; the affected items then get a short brief + BA re-run before approval.

Then the two AskUserQuestion steps from `v5-epic` SKILL.md (decisions, then items). Keep each batch at
4 questions; the developer may answer many at once through "Other" (`ED-1234 K-2: b, ED-1235 K-1: a`).

## Gate 2 - before the commit

Present:
- QA BE and QA FE: verdict + counts (PASS / FAIL / MANUAL), report paths (`qa-be.md`, `qa-fe.md`), rounds.
- e2e gallery path `work/e2e/<run>/index.html` (screenshots to skim), and whether profile runs were done.
- Files changed per repo (from `scripts/changed.sh`), flagged where a file also had pre-existing changes.
- Manual UI test list: **paths** to the tasks' `## Daftar tes UI` + the total item count. With designs,
  remind the developer: structure and flow should match the design; the look follows EQUAL v5 on
  purpose (`knowledge/design-sources.md`). The tasks' "Beda dari desain" notes are listed too.
- Review findings still *Perlu dikonfirmasi* (BE and FE).
- What could not be verified, and why.
- Bugs found outside the feature, with the proposal (fix here / separate ticket).
- `build/` refreshed or not (`scripts/fe-build.sh publish` result).

Options: **Setujui & commit** · **Minta perbaikan** (notes) · **Tunda**.

## Pausing

"Tunda" or "lanjut besok" at any point: update `run.md` (`## Status ringkas` says exactly what is
waiting), leave agents alone (do not stop running ones without asking), and tell the user to resume in a
**new session** with `/v5-feature <KEY>`.
