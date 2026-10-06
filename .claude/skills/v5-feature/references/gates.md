# Gates - what to present

Both gates: short, in Indonesian, paths and counts. Ask with AskUserQuestion. An agent's message is
never an approval; only the user's answer in this session is.

## Gate 1 - before any code

Source: the analyst's report, plus `scripts/section.sh spec.md "Ringkasan"` / `"Subtask"` /
`"Keputusan untuk developer"` if the report was lost.

Present:
- Ringkasan (3-6 lines) and counts: BR / AC / subtasks; new tables/Updaters; new permissions.
- The subtask table.
- **Every** *Keputusan untuk developer* question, numbered, each with the analyst's recommendation.
- For new FE modules: the `_module.md` path with its wireframe.
- Jira impact: Story summary/description change, subtasks to create.

Options: **Setujui** · **Revisi** (notes) · **Tunda**.
Multiple decisions: one AskUserQuestion with up to 4 questions; free-text answers go through "Other".

## Gate 2 - before the commit

Present:
- QA verdict + counts (PASS / FAIL / MANUAL), report path, round number.
- e2e gallery path `work/e2e/<run>/index.html` (screenshots to skim), and whether profile runs were done.
- Files changed per repo (from `scripts/changed.sh`), flagged where a file also had pre-existing changes.
- Manual UI test list: **paths** to the tasks' `## Daftar tes UI` + the total item count.
- Review findings still *Perlu dikonfirmasi* (BE and FE).
- What could not be verified, and why.
- Bugs found outside the feature, with the proposal (fix here / separate ticket).
- `build/` refreshed or not (`scripts/fe-build.sh publish` result).

Options: **Setujui & commit** · **Minta perbaikan** (notes) · **Tunda**.

## Pausing

"Tunda" or "lanjut besok" at any point: update `run.md` (`## Status ringkas` says exactly what is
waiting), leave agents alone (do not stop running ones without asking), and tell the user to resume in a
**new session** with `/v5-feature <KEY>`.
