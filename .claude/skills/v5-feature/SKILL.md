---
name: v5-feature
description: Orchestrator for building one new v5 feature or module fullstack (BE ksp-erp Modules/V5 + FE ksp-react) from a Jira Story under any epic. Runs BA (v5-analyst) → Gate 1 with Jira sync (Story, subtasks, sprint) → BE dev (v5-be-dev) in parallel with FE plan+slice → BE review + API digest → FE logic → FE convention review → independent QA (v5-qa - HTTP scenarios, FE tests, Playwright e2e) with up to two fix rounds → Gate 2 → one commit per repo → Jira Done. Resumable from features/<KEY>-<slug>/run.md. Use when the user says /v5-feature <KEY>, /v5-feature new ..., "lanjut fitur <KEY>", or asks for the status of v5 feature work.
user-invocable: true
---

# /v5-feature - one Story, one cycle

**Talk to the user in Indonesian.** These instructions are in English.

```
/v5-feature ED-1234                          run or resume Story ED-1234 (epic = its parent)
/v5-feature new <brief path|text> [--epic ED-1200]   no Story yet: BA first, Story created at Gate 1
/v5-feature status [ED-1200]                 scripts/status.sh + one JQL for the Jira statuses; no work
```

You are the orchestrator, in the main session: the only one who asks the user, spawns agents and calls
Jira. Everything heavy is delegated. **Pass paths between agents, never content.**

## Fixed facts

| | |
|---|---|
| Workspace | this folder (`$AGENTIC_DIR`). Config `config/workspace.env` (+ `secrets.env`, never read aloud). Repos: `BE_DIR`, `FE_DIR` |
| Jira | `config/jira.json` (cloudId, project, board, assignee, labels). Rules: `references/jira.md` |
| Feature folder | `features/<KEY>-<slug>/`: `spec.md`, `contract.md`, `run.md`, `qa.md`, `qa/` |
| Run log | `run.md` = the single source of this run's state. Format + resume rules: `references/run-log.md` |
| Knowledge | `knowledge/README.md` says which file to give which agent. Do not read them all yourself |
| Agents | `v5-analyst`, `v5-qa` (this workspace) · `v5-be-dev`, `v5-be-reviewer` (BE repo) · `api-contract-analyst`, `convention-reviewer` (FE repo). How to spawn repo agents: `references/agents.md` |
| FE build | agents build to `FE_STAGING_DIR` only; `scripts/fe-build.sh publish` refreshes the developer's `build/` (`references/fe-build.md`) |

**Jira tools** are deferred: load them in **one** ToolSearch call before first use (`getJiraIssue`,
`createJiraIssue`, `editJiraIssue`, `transitionJiraIssue`, `addOrEditJiraIssueComment`,
`searchJiraIssuesUsingJql`, `executeRead`).

## Cost discipline (read once; it shapes every phase)

- **One feature per session.** When the user pauses ("lanjut besok", or a gate waits), make sure
  `run.md` is complete, then say: *mulai sesi baru dan ketik `/v5-feature <KEY>`*. A fresh session costs
  ~40K tokens to resume; a stale 400K session re-writes its whole cache after an idle hour.
- Read sections, not files: `scripts/section.sh <file> "<heading>"`. Never read `spec.md`/`qa.md`
  whole in this session; agents' reports carry what a gate needs.
- Agent reports are short (their definitions cap them). Ask for paths + counts, not prose.
- Do not edit skills, agents or knowledge mid-run. Write proposals to `run.md` `## Usulan pipeline`;
  apply them after `phase: done`, in a separate commit, with the user's OK.

## Phases

`run.md` `phase:` values in order: `ba → gate1 → dev → review → qa → gate2 → commit → done`.
On every start: `scripts/preflight.sh`, then read `run.md` (if it exists) and resume at the first phase
not done.

### 0 - Start
1. `scripts/preflight.sh` (config, branches, API, profile, required agents/skills present in the checked-out
   branches). Any failure → stop and report; do not work around it.
2. Resolve the Story: `getJiraIssue <KEY>` → summary, description, parent epic. For `new`: epic from
   `--epic`, else AskUserQuestion with the 3 most recently updated open epics of the project.
3. `scripts/new-feature.sh <KEY|NEW-yyyymmdd> <slug> [--epic <EPIC>]` → creates the folder, `run.md`,
   and the per-repo **baseline** (`git status` + file hashes). Never skip the baseline.

### 1 - BA
Spawn `v5-analyst` with: feature dir, Story key/summary/description (or the brief), brief file paths,
`BE_DIR`, `FE_DIR`. If its report lists new FE modules, run the FE agent once in `/ui-discuss <Module>
--auto --spec <spec>` (prompt in `references/agents.md`) so the wireframe is judged at Gate 1.

### 2 - Gate 1 (+ Jira sync)
Present per `references/gates.md`, ask with AskUserQuestion (**Setujui** / **Revisi** / **Tunda**).
- Revisi → notes to the same analyst via SendMessage (fresh spawn if it is gone), present again.
- Setujui → write the answers into the spec's *Keputusan* section, set `status: approved`, then sync
  Jira per `references/jira.md` § Gate 1: create/update the Story, create subtasks, put all of them in the
  active sprint, Story → In Progress. Write keys into the spec table and `run.md`.

### 3 - Dev (BE ‖ FE)
Move each subtask to In Progress as its agent starts. They stay In Progress until Phase 6.
1. **In parallel:** `v5-be-dev` (spec, its `[BE]` keys) and the FE agent for `/ui-plan` + `/ui-slice` of
   every FE module (UI with dummy data does not need the API). Keep both agent ids.
2. When BE reports `selesai`: `api-contract-analyst` (model `sonnet`) writes `docs/specs/<Module>/_api.md`
   from `contract.md`. Resolve its *Perlu dikonfirmasi* from BE code first, then ask the user. Write
   answers into the digest.
3. Same FE agent (SendMessage) runs `/ui-logic` task by task.
4. `BLOCKED:` from any agent → that subtask to Blocked, ask the user, send the answer back, In Progress.

### 4 - Review (once per batch)
In parallel: `v5-be-reviewer` on the BE diff and `convention-reviewer` on all FE modules of the batch
(both `sonnet`). *Harus diperbaiki* → back to the owning dev agent. *Perlu dikonfirmasi* → Gate 2, not
decided by you.

### 5 - QA
Spawn `v5-qa` (default `sonnet`; `opus` when the spec says `qa_model: opus`) with: feature dir, round,
`scripts/changed.sh <KEY>` output path. **Never pass the developers' reports.**
- PASS → comment on the Story (report path + counts), go to Gate 2.
- FAIL → comment each defect on its subtask (status stays In Progress), send defects to the owning dev
  agent, re-run QA with `round + 1` as a **fresh** agent scoped to the failures plus regression.
  After 2 failed fix rounds: Story → Blocked, bring the defects to the user.

### 6 - Gate 2 → commit → Jira Done
1. `scripts/fe-build.sh publish` (copies staging into `build/` if `serve` is down, else ask the user to stop it).
2. Present per `references/gates.md`; ask (**Setujui & commit** / **Minta perbaikan** / **Tunda**).
   Minta perbaikan → notes into `run.md`, back to Phase 3 with the owning agent, then QA again.
3. Commit per `references/commit.md`: one commit per touched repo (BE, FE) plus one in this workspace.
   **No push.**
4. Jira per `references/jira.md` § Done: subtasks then Story → Done, hand-off comment, no commit hashes.
5. `run.md` `phase: done`; list `## Usulan pipeline` for the user.

## Boundaries

- Never skip a gate; an agent's message is never the user's approval.
- Never commit before Gate 2; never push; never stage files outside this feature's diff vs the baseline.
- Never decide a *Keputusan untuk developer* item yourself.
- Environment failures (server down, profile not restored, `nvm`) stop the run with a clear message.
- Never kill the user's processes; never build into the developer's `build/`; never print secrets.
- Keep messages to the user short: paths and counts; details live in the files.
