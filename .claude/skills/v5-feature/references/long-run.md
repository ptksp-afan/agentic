# Long-run mode - one item, no human in the loop

`/v5-feature <KEY> --long-run --epic <EPIC>` is called by `/v5-epic` through a **story runner** subagent,
**after** the developer approved this item's spec at the epic's combined Gate 1. Everything in SKILL.md
applies, except what this file changes. You cannot ask the user: every point where SKILL.md says "ask"
either has a rule below or becomes a **blocker**.

**Precondition:** `spec.md` has `status: approved`. If not, change nothing and return
`RESULT: skipped` ("spec belum disetujui di Gate 1"). Start at `run.md` `phase` (normally `be`; `fe` for FE-only items); on the
first start move the item (Bug: from `BUG`) and its subtasks to In Progress, assigning it to
`jira.assigneeAccountId` if unassigned.

## Agents inside the runner

- Spawn every agent with `run_in_background: false`, **one at a time**, in the SKILL.md order
  (BE → QA BE → FE → QA FE). Never return while a child agent is still running.
- Same models, same reuse rules (`references/agents.md`).
- Add to every dev prompt: "Do not modify files listed as dirty in `<feature dir>/baseline/*.status`
  (the developer's own uncommitted work). If the task needs one, stop with `BLOCKED:`."

## Item types

| Jira type | Flow |
|---|---|
| Story / Task | BE → QA BE → FE → QA FE → auto-commit, with the subtasks created at Gate 1 |
| Bug | the same on the Bug issue itself (spec from the analyst's bug mode); no subtasks |

## Gate replacements

| Interactive | Long run |
|---|---|
| Gate 1 | already done by the developer for the whole epic (`/v5-epic` phase 2), including the wireframes of new FE modules |
| Digest *Perlu dikonfirmasi* | answer from BE code with evidence; what remains → blocker |
| Agent `BLOCKED:` | blocker (park the code) |
| Review *Perlu dikonfirmasi* | not a blocker; listed for the batch review |
| QA BE or QA FE FAIL after 2 fix rounds | blocker (park the code) |
| Gate 2 | **auto-commit locally** when QA BE and QA FE both PASS and no *Harus diperbaiki* is left; Jira stays In Progress with label `jira.longRun.labels.review` and a "siap review" comment. **Never Done**: Done happens only in `/v5-epic review` after the developer approves |
| Bug outside the item | not fixed; one new Bug ticket under the epic (label `jira.longRun.labels.found`), mention in the item's comment |
| Environment failure (server down, preflight FAIL, profile not restored) | **not** a blocker: stop and return `RESULT: env-fail`; the epic loop stops the whole run |

## Blocker procedure

1. Code exists? `scripts/park.sh <KEY> "<reason>"`: moves this item's changes (BE + FE) onto local
   branch `park/<KEY>`, restores the working tree, reloads RR. Exit 3 (mixed files / existing park) →
   stop with `RESULT: env-fail` and the script's message; do not improvise git commands.
2. Jira per `references/jira.md` § Blocker: item (and its open subtasks) → Blocked, label
   `jira.longRun.labels.blocker`, the blocker comment, `Blocks` links to items that depend on it.
3. `run.md`: `phase: blocked`, `blocked_from: <phase>`, `## Status ringkas` = the questions + park refs.
4. Return `RESULT: blocked`.

## Resuming a blocker (the developer moved the card out of Blocked)

1. Read the comments newer than the blocker comment (`executeRead` → `listJiraIssueComments`). Answers
   look like `K-1: a`; free text is fine. No answer → back to Blocked with a short comment asking again,
   `RESULT: blocked`.
2. Write the answers into the spec's *Keputusan* (and `run.md` `## Keputusan developer`), remove the
   blocker label.
3. Parked code: `scripts/new-feature.sh <KEY> <slug> --rebaseline`, then `scripts/unpark.sh <KEY>`.
   Exit 3 (conflict with later commits) → blocker again with the conflicting paths as the question.
4. Continue from `blocked_from`.

## Dependencies on earlier blockers

The epic loop already skips items whose spec `depends_on` an unfinished item. The runner also receives
the items blocked earlier in this run with their scope (modules, endpoints, tables). Before writing code,
if this item needs one of them in a way the spec missed (calls an endpoint, extends a module or table
they introduce), it is **blocked by** that item: Jira link `<blocker> blocks <this>`, comment "menunggu
<KEY>", `RESULT: blocked`. Otherwise continue.

## Return (≤ 10 lines)

```
RESULT: review | blocked | skipped | env-fail
KEY: ED-1234 (Story) - <title>
COMMITS: be <sha>, fe <sha>, agentic <sha>          (review only)
QA: PASS 14 / MANUAL 3, gallery work/e2e/<run>/     (review only)
BLOCKER: 1 pertanyaan, fase dev | depends on ED-1230 (blocked only)
SCOPE: modules/endpoints/tables touched or planned (one line)
FOUND: new bug tickets, if any
```
