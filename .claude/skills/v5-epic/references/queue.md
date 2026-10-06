# Building the queue

All names from `config/jira.json`. One JQL, then a few `getJiraIssue` calls only where needed.

```
parent = <EPIC> AND issuetype in (Story, Task, Bug)
AND status in ("To Do", "BUG", "In Progress", "Blocked")
AND (assignee = <assigneeAccountId> OR assignee is EMPTY)
ORDER BY Rank ASC
```
Fields: `summary, status, issuetype, assignee, labels, issuelinks`. Page until `isLast`.

| Status | `features/<KEY>-*/` | Label | Action |
|---|---|---|---|
| To Do / BUG | none, or spec not `approved` | - | **ba** (analysed in phase 1, judged at Gate 1) |
| To Do / BUG | spec `approved` | - | **run** |
| To Do / BUG / In Progress | `run.md` `phase: blocked` | blocker | **unblock** (developer moved the card out of Blocked) |
| In Progress | `run.md`, `phase` not `done` | none | **resume** (incl. "Minta perbaikan" from review) |
| In Progress | no `run.md` | - | skip: someone else's work |
| In Progress | `run.md` | review | skip: waiting for review |
| Blocked | - | - | skip: still blocked |

`--only` filters the list; `--max N` cuts it after N runnable items.

Unassigned items get assigned to `assigneeAccountId` when their runner starts them (the runner does
it together with "Start Progress").

## Dependencies

- Jira: an item whose `issuelinks` has `is blocked by X` with X not Done → runnable only if X ends as
  `review` earlier in **this** run (its code is committed). Otherwise skip with reason.
- Spec: `depends_on: [ED-x]` written by the analyst at BA (checked again by epic-check).
- In-run: when an item ends `blocked`, its SCOPE line goes into the prompt of every later runner; a
  runner that finds a dependency the spec missed returns `blocked` (`long-run.md` § Dependencies).
- Skipped-because-blocked items: Blocked + label + link `X blocks <item>` + comment "menunggu X" - done
  by the epic loop itself (no runner needed).

## Order

Jira rank (board order) is the developer's priority; keep it. Move an item later only when its
`is blocked by` target comes later in the same queue.
