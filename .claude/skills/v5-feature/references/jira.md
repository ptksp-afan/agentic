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
3. Story + subtasks into the active sprint (above), then Story → In Progress.
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

## Bugs found outside the feature

Do not fix them silently. At the next gate, propose: fix inside this feature (becomes a subtask), or a
separate ticket (`createJiraIssue` type Bug/Task under the same epic, label from `jira.json`). The user
decides.
