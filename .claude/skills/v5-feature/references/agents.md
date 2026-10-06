# Spawning agents

## Which agent, which model

| Step | Agent | Lives in | Model | Why |
|---|---|---|---|---|
| BA | `v5-analyst` | this workspace | opus | everything downstream trusts the spec |
| BE dev | `v5-be-dev` | BE repo | opus | transactional code, layering judgement |
| FE dev (discuss/plan/slice/logic) | `general-purpose` + FE skills | - | inherit | one agent per batch: conventions loaded once |
| API digest | `api-contract-analyst` | FE repo | **sonnet** (override its opus) | mechanical distillation of `contract.md` |
| BE review | `v5-be-reviewer` | BE repo | sonnet | mechanical |
| FE review | `convention-reviewer` | FE repo | sonnet | mechanical |
| QA | `v5-qa` | this workspace | sonnet; opus if spec `qa_model: opus` | mostly mechanical; opus for transactional features |

Repo agents are discovered by name when `BE_DIR` and `FE_DIR` are in `.claude/settings.local.json`
`permissions.additionalDirectories` (set up once; restart the session after changing it). If a name is
missing from the agent list, fall back: Read `<repo>/.claude/agents/<name>.md` and spawn
`general-purpose` with its body as the prompt and the model from the table.

## Prompt shape (keeps cache prefixes stable and contexts small)

1. **Repo header** for repo agents and the FE dev - always first, always the same words:
   ```
   Repo root: <DIR> (Git Bash: <unix path>). Every relative path in your instructions resolves
   against it; run shell commands as `cd <unix path> && ...`. Workspace config: <AGENTIC_DIR>/config/workspace.env.
   ```
2. Inputs as **paths** (spec, contract, digest, changed-files list), keys, round number.
3. The one variable instruction for this call, last.

Never paste a spec, a report or a diff into a prompt.

## FE dev prompt (general-purpose)

After the repo header for `FE_DIR`:
- Read `CLAUDE.md` and `.claude/skills/equal-conventions/SKILL.md`. Run the FE skills
  (`ui-discuss`, `ui-plan`, `ui-slice`, `ui-logic`) via the Skill tool when they are listed, otherwise
  follow `.claude/skills/<name>/SKILL.md` by path - always with `--auto --spec <spec path>`, as
  `.claude/skills/equal-conventions/references/auto-mode.md` describes.
- Where auto-mode.md names a BE path or a build folder, use **this run's** values: BE code is in `BE_DIR`
  (`<path>`); build only with `<AGENTIC_DIR>/scripts/fe-build.sh build` (staging folder, never `build/`).
- Batch id `<KEY>`; FE subtask keys `<list>`.
- This call: `<"/ui-plan for modules X, Y, then /ui-slice task by task" | "/ui-logic task by task">`.
- Return the per-task report from auto-mode.md.

## API digest prompt (`api-contract-analyst`)

After the repo header for `FE_DIR`: contract = `<feature dir>/contract.md` (outside the FE repo; it is the
raw contract, so nothing is copied into `docs/api-contracts/`); in each digest's `sources:` write
`agentic:features/<KEY>-<slug>/contract.md`. Modules to create/update: the spec's `fe_modules` +
`new_fe_modules` that call new or changed endpoints. Return the *Perlu dikonfirmasi* list.

## Reuse vs fresh

- Fix rounds and `/ui-logic` after slicing go to the **same** agent via SendMessage while its last report
  is < ~1 hour old (its prompt cache is still warm, and it already holds the conventions).
- Older than that, or the agent is gone: spawn fresh; it reads the files. Record ids + time in `run.md`.
- QA rounds are always fresh agents (independence; smaller context). Round 2+ gets the previous `qa.md`
  and is told to re-check failures first, then run the full regression scripts.

## Long commands inside agents

Builds, full test suites and e2e runs can take 5-15 minutes. The workspace sets
`CLAUDE_CODE_SUBAGENT_PROMPT_CACHE_TTL=1h` so an agent's cache survives the wait; do not remove it.
Agents still send long output to a log file and print only the tail/summary.
