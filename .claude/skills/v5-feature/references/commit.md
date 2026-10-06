# Commit - after Gate 2 only, never push

`scripts/changed.sh <KEY>` compares each repo with the baseline taken at Phase 0 and prints:

| Class | Meaning | Action |
|---|---|---|
| `NEW` | not dirty at baseline, changed now | this feature's - stage it |
| `PRE+` | already dirty at baseline **and** changed since (hash differs) | mixed - stage **by hunk**; show the user which hunks are theirs if unsure |
| `PRE` | dirty at baseline, unchanged since | not this feature's - never stage |

Rules:
- Stage explicit paths only. Never `git add -A`, `git add .`, or `git commit -a`.
- Branch check before committing: `BE_BRANCH` / `FE_BRANCH`. Wrong branch → stop and ask.
- Never stage secrets, `config/workspace.env`, `config/secrets.env`, `work/`.
- One commit per touched repo, message in that repo's style (check `git log --oneline -10` first):

```
[ADD] <Module/feature title> (<KEY>)

- [BE] <what> (<subtask key>)
- [FE] <what> (<subtask key>)

Co-Authored-By: Claude <model name> <noreply@anthropic.com>
```

  `[FIX]` for bug fixes, `[DOC]` for docs-only commits.
- Workspace commit (this repo): `features/<KEY>-<slug>/**` (spec, contract, run, qa, qa scripts) as
  `[DOC] <KEY> - spec, QA, run log`.
- Pipeline changes (skills/agents/knowledge) never ride along with a feature commit: separate
  `[DOC] Pipeline - penyesuaian dari <KEY>` commit, after the user agreed to the proposals.
- Record hashes in `run.md` `## Log`. Not in Jira.
