# Jira - how the orchestrator keeps Jira in step

All ids come from `config/jira.json`. Never hardcode an epic: it is the Story's `parent`, or the `--epic`
given to `/v5-feature new`, recorded in `run.md` `epic:`.

## Status flow (developer's rule, 2026-09-28)

**Done means "development finished"**, not "tested": human QA takes over from Done, and this account
cannot perform the QA transitions (`jira.json` `forbiddenTargetStatuses`) nor move an issue back from Done.

| When | Story | Subtasks |
|---|---|---|
| Gate 1 approved | In Progress (after the subtasks exist) | To Do |
| An agent starts a subtask | - | In Progress |
| Waiting for the user's answer | Blocked only if the whole feature waits | Blocked |
| AI QA finds defects | stays In Progress; defects = **comments** | stays In Progress |
| QA PASS + Gate 2 approved + commit exists | Done | Done (every one, QA subtask included) |

Transitions depend on the current status: `executeRead` → `listJiraIssueTransitions`, then pick the
transition whose **target status name** matches `jira.json` `status.*`. Ids are not stable across statuses.

## Sprint (developer's rule, 2026-09-28)

Before work starts, the Story and **every** subtask must be in the active sprint, or they never show on
the board.
- `executeRead` → `listJiraBoardSprints` `{boardId: jira.boardId, state: "active"}`. None or more than one
  → ask the user which one.
- Subtasks do **not** inherit the parent's sprint here: set `sprintId` on each one (with
  `transitionJiraIssue`, or the sprint field on create).
- Verify with `getJiraIssue` `fields: [jira.sprintField]`. JQL `sprint = N` lags the index.

## Gate 1 sync ("update Jira kalau ada update")

1. **Story**
   - Exists: compare the approved spec's title/scope with the Story. If the summary no longer fits,
     `editJiraIssue` the summary. Append (never replace) a description block `Spec v5 (disetujui
     <date>)`: Ringkasan (3-6 lines), AC count, subtask list, spec path. Replace only this block on
     later revisions.
   - `new`: `createJiraIssue` type `jira.storyIssueType`, `parent` = epic, summary = spec title,
     description = the block above, assignee + labels from `jira.json`. Rename the feature folder
     `NEW-…` → `<KEY>-<slug>` and fix `run.md`.
2. **Subtasks**: one per row of the spec's `## Subtask` table, type `jira.subtaskIssueType`, `parent` =
   Story, assignee + labels, description = its ACs (ids + one line each) + spec path. Titles keep the
   spec prefixes: `[BE] …`, `[FE] <Module> - …`, `[QA] …`. Re-runs: match existing subtasks by title
   first; never create duplicates.
3. Story + subtasks into the active sprint (above), then Story → In Progress. **Epic Gate 1 (long run):**
   sprint yes, In Progress **no** - the story runner moves it when it actually starts the item.
4. Comment on the Story: spec path, counts (BR / AC / subtasks), decisions taken at Gate 1 (one line each).
5. Write every key into the spec's subtask table and `run.md`.

Later spec changes (Gate 2 fixes that add ACs or subtasks) follow the same rules: new subtask → sprint
→ In Progress; comment on the Story what changed.

## Done (after the commit)

1. Every subtask → Done (check the `[QA]` one; it was left To Do once).
2. Story → Done.
3. Hand-off comment on the Story for human QA: QA report path, manual UI test list paths
   (`docs/specs/<Module>/NNN-*.md` `## Daftar tes UI`) + item count, what was only code-reviewed or not
   verifiable, and out-of-scope tickets raised.
4. **No commit hashes or commit links in Jira** (developer's decision, 2026-09-29). Hashes go to `run.md`.

## Item types and start statuses

Epic children are `Story`, `Task` or `Bug` (`jira.itemIssueTypes`). Bugs start in status `BUG` (their
"To Do"; "Start Progress" → In Progress). Bugs get no subtasks: the Bug itself carries the statuses.
`BUG` is also where human QA sends work back; then it is a fix on committed code - resume its `run.md`.

## Blocker (long run; `references/long-run.md`)

The ED board has its own **Blocked** column (status `jira.status.blocked`).
1. Transition the item → Blocked ("Stuck"); its subtasks that are In Progress → Blocked too.
2. Add label `jira.longRun.labels.blocker` (lets the developer filter AI blockers from human ones).
3. Comment (Indonesian, plain text, starts with `jira.longRun.blockerMarker` so it can be found again):
   ```
   [AI-BLOCKER] Butuh keputusan developer - long run <EPIC>, <tanggal>
   Fase: <ba|dev|qa>. Kode: <belum ada | diparkir di branch park/<KEY> (BE, FE)>.
   Spec: agentic/features/<KEY>-<slug>/spec.md
   K-1. <pertanyaan>
        a) ...  b) ...  - rekomendasi: a (<alasan singkat>)
   K-2. ...
   Cara melepas: balas komentar ini (mis. "K-1: a, K-2: b"), lalu pindahkan kartu ke To Do.
   Long run berikutnya melanjutkan dari sini.
   Menghambat: <ED-x, ED-y | tidak ada>
   ```
4. Dependents: `createJiraIssueLink` type `jira.longRun.blocksLinkType` (outward = the blocker, inward = the
   dependent), and the dependent gets the same Blocked + label + a one-line comment "menunggu <KEY>".
5. Unblock (the developer moved the card): remove the label when work resumes; leave the comment.

## Ready for review (long run)

Committed locally, QA PASS, not Done. Item stays In Progress, label `jira.longRun.labels.review`, comment:
"Siap review developer: laporan QA <path>, tes UI manual <n> butir (<paths>), galeri <path>." (no
hashes). Subtasks stay In Progress. `/v5-epic review` moves them to Done after approval.

## Bugs found outside the feature

Do not fix them silently. At the next gate, propose: fix inside this feature (becomes a subtask), or a
separate ticket (`createJiraIssue` type Bug/Task under the same epic, label from `jira.json`). The user
decides.
