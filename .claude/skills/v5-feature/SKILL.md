---
name: v5-feature
description: Orchestrator for building one new v5 feature or module fullstack (BE ksp-erp Modules/V5 + FE ksp-react) from a Jira Story, Task or Bug under any epic. Runs brief intake (v5-brief) → BA (v5-analyst) → Gate 1 with Jira sync (Story, subtasks, sprint) → then strictly one job at a time: BE (v5-be-dev + v5-be-reviewer) → QA BE (v5-qa, HTTP/DB scenarios) → API digest → FE (ui-plan/slice/logic + convention-reviewer) → QA FE (build, tests, Playwright, BE regression), each QA with up to two fix rounds → Gate 2 → one commit per repo → Jira Done. Works with RoadRunner or php-fpm BE. Resumable from features/<KEY>-<slug>/run.md. Use when the user says /v5-feature <KEY>, /v5-feature new ..., "lanjut fitur <KEY>", or asks for the status of v5 feature work.
user-invocable: true
---

# /v5-feature - one Story, one cycle

**Talk to the user in Indonesian.** These instructions are in English.

```
/v5-feature ED-1234 ["<brief>"]              run or resume Story ED-1234 (epic = its parent)
/v5-feature new "<brief>" [--epic ED-1200]   no Story yet: BA first, Story created at Gate 1
/v5-feature status [ED-1200]                 scripts/status.sh + one JQL for the Jira statuses; no work
/v5-feature ED-1234 --long-run --epic ED-1200   after the epic's combined Gate 1: no gates, blockers instead (only via /v5-epic's story runner)
```

The item can be a Story, Task or Bug (Bug: `v5-analyst` bug mode, no subtasks). **`--long-run`:** read
`references/long-run.md` first; it replaces every "ask the user" below.

`<brief>` = free text that may reference local files (PDF, images, Office, Claude Design exports as
HTML/folder/zip) and links (claude.ai artifacts, web). Supported forms: `knowledge/design-sources.md`.
It is not a shell argument: everything after the key (or after `new`) up to the next flag of this
command (`--epic`, `--long-run`) is the brief - one line or many, quotes optional, pasted text included.
A brief that is only a path to a `.md`/`.txt` file (or a folder holding one) means "the brief is this".

You are the orchestrator, in the main session: the only one who asks the user, spawns agents and calls
Jira. Everything heavy is delegated. **Pass paths between agents, never content.**

## Fixed facts

| | |
|---|---|
| Workspace | this folder (`$AGENTIC_DIR`). Config `config/workspace.env` (+ `secrets.env`, never read aloud). Repos: `BE_DIR`, `FE_DIR` |
| Jira | `config/jira.json` (cloudId, project, board, assignee, labels). Rules: `references/jira.md` |
| Feature folder | `features/<KEY>-<slug>/`: `brief/`, `spec.md`, `contract.md`, `run.md`, `qa-be.md`, `qa-fe.md`, `qa/` |
| Run log | `run.md` = the single source of this run's state. Format + resume rules: `references/run-log.md` |
| Knowledge | `knowledge/README.md` says which file to give which agent. Do not read them all yourself |
| Agents | `v5-brief`, `v5-analyst`, `v5-qa` (this workspace) · `v5-be-dev`, `v5-be-reviewer` (BE repo) · `api-contract-analyst`, `convention-reviewer` (FE repo). How to spawn repo agents: `references/agents.md` |
| FE build | agents build to `FE_STAGING_DIR` only; `scripts/fe-build.sh publish` refreshes the developer's `build/` (`references/fe-build.md`) |

**Jira tools** are deferred: load them in **one** ToolSearch call before first use (`getJiraIssue`,
`createJiraIssue`, `editJiraIssue`, `transitionJiraIssue`, `addOrEditJiraIssueComment`,
`searchJiraIssuesUsingJql`, `executeRead`).

## Cost discipline (read once; it shapes every phase)

- **One feature per session.** When the user pauses ("lanjut besok", or a gate waits), make sure
  `run.md` is complete, then say: *mulai sesi baru dan ketik `/v5-feature <KEY>`*. A fresh session costs
  ~40K tokens to resume; a stale 400K session re-writes its whole cache after an idle hour.
- Read sections, not files: `scripts/section.sh <file> "<heading>"`. Never read `spec.md`/`qa-*.md`
  whole in this session; agents' reports carry what a gate needs.
- Agent reports are short (their definitions cap them). Ask for paths + counts, not prose.
- Do not edit skills, agents or knowledge mid-run. Write proposals to `run.md` `## Usulan pipeline`;
  apply them after `phase: done`, in a separate commit, with the user's OK.

## Phases

`run.md` `phase:` values in order: `ba → gate1 → be → qa-be → fe → qa-fe → gate2 → commit → done`.
On every start: `scripts/preflight.sh`, then read `run.md` (if it exists) and resume at the first phase
not done.

### 0 - Start
1. `scripts/preflight.sh` (config, branches, API, profile, required agents/skills present in the checked-out
   branches). Any failure → stop and report; do not work around it.
2. Resolve the Story: `getJiraIssue <KEY>` → summary, description, parent epic. For `new`: epic from
   `--epic`, else AskUserQuestion with the 3 most recently updated open epics of the project.
3. `scripts/new-feature.sh <KEY|NEW-yyyymmdd> <slug> [--epic <EPIC>]` → creates the folder, `run.md`,
   and the per-repo **baseline** (`git status` + file hashes). Never skip the baseline.
4. **Brief intake** - when there is a `<brief>`, Jira attachments, or file/design links in the
   description: spawn `v5-brief` (model `sonnet`) with the brief folder `features/<KEY>-<slug>/brief/`, the
   brief text verbatim, and the Story key/description/attachment list. It writes `brief/README.md`.
   - claude.ai links it could not read: read them yourself with the Artifact tool (`action: "read"`),
     give it the saved file path (SendMessage), and let it finish.
   - Anything still `tidak terbaca`: ask the user now for an export or the file (long run: list it at the
     epic's Gate 1). Do not start BA on a brief that is missing its main source.

### 1 - BA
Spawn `v5-analyst` with: feature dir, Story key/summary/description, `brief/README.md` (if any),
`BE_DIR`, `FE_DIR`. If its report lists new FE modules, run the FE agent once in `/ui-discuss <Module>
--auto --spec <spec> <brief/README.md> <brief/render/*.png of that module>` (prompt in
`references/agents.md`) so the wireframe is judged at Gate 1.

### 2 - Gate 1 (+ Jira sync)
Present per `references/gates.md`, ask with AskUserQuestion (**Setujui** / **Revisi** / **Tunda**).
- Revisi → notes to the same analyst via SendMessage (fresh spawn if it is gone), present again.
- Setujui → write the answers into the spec's *Keputusan* section, set `status: approved`, then sync
  Jira per `references/jira.md` § Gate 1: create/update the Story, create subtasks, put all of them in the
  active sprint, Story → In Progress. Write keys into the spec table and `run.md`.

**One heavy job at a time (developer's rule, 2026-10-06).** The machine runs the BE server, the DB,
builds, test suites and a browser; in parallel it is too slow. So the work goes **BE → QA BE → FE → QA
FE**, one agent after another - never two dev/QA agents at once. Why QA right after BE: BE defects are
caught before the FE is built on the API, the digest is made from a contract QA has checked, and each QA
agent stays small. A layer the spec does not touch is skipped (BE-only item: no FE phases; FE-only item:
no BE phases, QA FE still re-runs existing BE scenarios). Subtasks move to In Progress when their agent
starts and stay there until Phase 7.

### 3 - BE
1. `v5-be-dev` (spec, its `[BE]` keys, reload command `scripts/be-reload.sh`).
2. `v5-be-reviewer` (`sonnet`) on the BE diff. *Harus diperbaiki* → back to the same BE agent;
   *Perlu dikonfirmasi* → Gate 2.

### 4 - QA BE
`v5-qa` with `scope: be` (default `sonnet`; `opus` when the spec says `qa_model: opus`): feature dir, round,
`scripts/changed.sh <KEY>` output. **Never pass the developers' reports.** Writes `qa-be.md`.
- FAIL → comment each defect on its subtask, defects to the BE agent, fresh QA BE with `round + 1` scoped
  to the failures plus the full BE scenarios. After 2 failed fix rounds: Story → Blocked, ask the user.

### 5 - FE
1. `api-contract-analyst` (`sonnet`) writes `docs/specs/<Module>/_api.md` from `contract.md` (now checked
   by QA BE). Resolve its *Perlu dikonfirmasi* from BE code first, then ask the user; write the answers in.
2. One FE agent runs, in this order: `/ui-plan` for every FE module, then per task `/ui-slice` and
   `/ui-logic` (prompt in `references/agents.md`).
3. `convention-reviewer` (`sonnet`) once for all FE modules of the batch. *Harus diperbaiki* → same FE
   agent; *Perlu dikonfirmasi* → Gate 2.
4. `BLOCKED:` from any agent → that subtask to Blocked, ask the user, send the answer back, In Progress.

### 6 - QA FE
`v5-qa` with `scope: fe`: FE build + tests + Playwright, `[FE]`/`[BE+FE]` ACs, and a re-run of the BE
scenarios as regression. Writes `qa-fe.md`. FAIL → defects to the owning agent (FE, or BE if the BE
regression broke), fresh QA FE with `round + 1`. After 2 failed fix rounds: Blocked, ask the user.
Both PASS → comment on the Story (report paths + counts), go to Gate 2.

### 7 - Gate 2 → commit → Jira Done
1. `scripts/fe-build.sh publish` (copies staging into `build/` if `serve` is down, else ask the user to stop it).
2. Present per `references/gates.md`; ask (**Setujui & commit** / **Minta perbaikan** / **Tunda**).
   Minta perbaikan → notes into `run.md`, back to Phase 3 (BE) or 5 (FE) with the owning agent, then the
   matching QA again.
3. Commit per `references/commit.md`: one commit per touched repo (BE, FE) plus one in this workspace.
   **No push.**
4. Jira per `references/jira.md` § Done: subtasks then Story → Done, hand-off comment, no commit hashes.
5. `run.md` `phase: done`; list `## Usulan pipeline` for the user.

## Boundaries

- Never skip a gate; an agent's message is never the user's approval.
- Never commit before Gate 2 (long run: local auto-commit after QA PASS, Done only after the developer's
  review); never push; never stage files outside this feature's diff vs the baseline.
- Never decide a *Keputusan untuk developer* item yourself.
- Environment failures (server down, profile not restored, `nvm`) stop the run with a clear message.
- Never kill the user's processes; never build into the developer's `build/`; never print secrets.
- Keep messages to the user short: paths and counts; details live in the files.
