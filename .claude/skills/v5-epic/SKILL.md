---
name: v5-epic
description: Long-run driver for a whole Jira epic of v5 work. First the BA analyses every Story, Task and Bug of the epic and the developer approves them all at one combined Gate 1; then every approved item runs unattended one after another through /v5-feature in long-run mode - anything that still needs a developer decision becomes a Jira Blocked card and the run moves on to items that do not depend on it; finished items are committed locally for a later batch review that approves them to Done. Use when the user says /v5-epic <EPIC>, "long run epic ...", "jalankan semua story di epic ...", or /v5-epic review|status <EPIC>.
user-invocable: true
---

# /v5-epic - BA for the whole epic → one Gate 1 → long run

**Talk to the user in Indonesian.** These instructions are in English.

```
/v5-epic ED-1200 [--brief "<text/paths/links>"] [--plan] [--max N] [--only ED-1,ED-2] [--dry-run]
                                                            BA all → Gate 1 → long run (resumable)
/v5-epic review ED-1200                                     batch review: approve → Done, or send back
/v5-epic status ED-1200                                     the epic log; no work
```

You are the **epic loop** in the main session. You never analyse or build an item yourself. State lives
in `epics/<EPIC>.md` (format at the end); keep it current. Epic phases: `ba → gate1 → run → done`.
On every start: `scripts/preflight.sh --long-run`, read the epic log if it exists, resume at its `phase`.

## 0 - Queue (developer present)
1. Preflight FAIL → stop. Show its WARN lines (sleep/hibernate, parked branches).
2. Load the Jira tools in one ToolSearch (as in `v5-feature`).
3. Build the queue (`references/queue.md`). Each item gets an action: `ba` (no approved spec yet),
   `run` (spec approved), `resume`, `unblock`, or `skip` + reason. **Empty epic + `--brief`, or `--plan`:**
   plan the items from the brief first (`references/plan.md`); the plan is the queue.
4. Show it; `--dry-run` stops here. Ask once: **Mulai BA** / **Ubah isi** / **Batal**.

## 1 - BA for every `ba` item (developer may leave; Gate 1 waits)
1. For each item: `scripts/new-feature.sh <KEY> <slug> --epic <EPIC>` (folder + baseline).
2. **Briefs** (`knowledge/design-sources.md` lists the supported forms):
   - `--brief` given → one `v5-brief` for the epic into `epics/<EPIC>-brief/` first.
   - Each item whose Jira description has attachments, file paths or design links → `v5-brief` for it
     (`features/<KEY>-<slug>/brief/`), given the epic digest path so it does not repeat it. Up to
     `BA_PARALLEL` at a time. claude.ai links an agent could not read: read them with the Artifact tool and hand over the file.
   - Sources still unreadable do not stop the BA; they are listed at Gate 1.
3. Spawn `v5-analyst` per item, **up to `BA_PARALLEL` at a time** (config, default 2) (`run_in_background: true`; analysts only read code
   and write their own feature folder, so parallel is safe). Each prompt carries the epic overview (every
   queue item as `KEY - type - summary`, one line each), the epic brief digest and the item's own digest,
   so the analyst can declare `depends_on` and avoid designing what another item owns.
4. New FE modules from any report: one FE agent runs `/ui-discuss <Module> --auto --spec <spec>
   <brief digest + renders>` for all of them (`v5-feature/references/agents.md` § FE dev prompt).
5. One `v5-analyst` in **epic-check** mode over all specs of this run → `epics/<EPIC>-gate1.md`.
6. Epic log `phase: gate1`. If the developer is away: `PushNotification` (if the tool exists)
   "BA ED-1200 selesai: N item siap Gate 1".

## 2 - Gate 1, combined (developer present)
Present per `v5-feature/references/gates.md` § Gate 1 gabungan, from `epics/<EPIC>-gate1.md` and the
analysts' reports. Then:
1. **Decisions** (every *Keputusan* of every item): AskUserQuestion **Terima semua rekomendasi** /
   **Jawab satu per satu** (batches of 4 questions, options a/b/c with the recommendation first);
   exceptions can be typed via "Other" as `ED-1234 K-2: b`.
2. **Items**: **Setujui semua** / **Pilih per item** (batches of 4: Setujui / Revisi / Tunda).
   - Revisi → notes to that analyst (SendMessage, or fresh), re-run epic-check if scope changed,
     present only the revised items again.
   - Tunda → excluded from this run; epic log `ditunda (Gate 1)`; Jira untouched.
3. For each approved item: answers into its spec, `status: approved`, Jira sync per
   `v5-feature/references/jira.md` § Gate 1 **without** moving it to In Progress (that happens when its
   runner starts). Order the run by Jira rank, moved only where `depends_on` requires.
4. Epic log `phase: run`. Tell the developer the run starts now and needs nobody until the review.

## 3 - Long run (unattended), one item at a time
Shared working trees and BE server: never two items in parallel. For each approved item:
1. `scripts/preflight.sh`. FAIL → stop the run.
2. Its `depends_on` (spec) or Jira `is blocked by` points at an item that ended `blocked` or is not
   Done/`review` → Blocked + label + link + comment "menunggu <KEY>" (`references/queue.md`). Next.
3. Spawn the **story runner** (`general-purpose`, `run_in_background: true`):
   ```
   You are the story runner for <KEY> (<type>) of epic <EPIC>, long-run mode. Invoke the Skill
   `v5-feature` with args `<KEY> --long-run --epic <EPIC>` and follow it, including
   references/long-run.md, until this one item ends. Its spec is approved; start at phase dev.
   Items blocked earlier in this run: <KEY - scope, one line each, or "none">.
   Return exactly the block described in long-run.md § Return.
   ```
4. Wait for its notification (answer the developer from the epic log meanwhile). Record the result.
5. Stop when: `RESULT: env-fail`; **3 blocked in a row**; `--max` reached; the developer says stop.

## 4 - End
Rewrite `## Ringkasan` (counts per result, blocked items with their questions, new bug tickets, time).
`PushNotification` if available. Point the developer to: answering blockers in Jira (reply to
`[AI-BLOCKER]`, move the card to To Do) and `/v5-epic review <EPIC>`. Answered blockers and items added
to the epic later are picked up by the next `/v5-epic <EPIC>` (new items get BA + Gate 1 first).

## Review (`/v5-epic review <EPIC>`; developer present)
1. Items with label `jira.longRun.labels.review` in the epic, with their `run.md` / `qa-be.md` / `qa-fe.md` frontmatter.
2. `scripts/fe-build.sh publish` once.
3. Per item: QA counts + gallery, manual UI test list paths + count, commits, review findings *Perlu
   dikonfirmasi*, new-module layouts marked `ui_review: gate-2`.
4. AskUserQuestion, up to 4 items per call: **Setujui** / **Minta perbaikan** (notes) / **Tunda**.
   - Setujui → Jira § Done (subtasks, item, hand-off comment), remove the review label, `run.md`
     `phase: done`, delete leftover `park/<KEY>` branches.
   - Minta perbaikan → notes into `run.md`, `phase: dev`, remove the label, comment; the next run
     resumes it with a fix commit on top.

## Epic log - `epics/<EPIC>.md`

```markdown
---
epic: ED-1200
title: <epic summary>
phase: ba | gate1 | run | done
status: running | stopped | done
started: 2026-10-06 20:00
current: ED-1234
---
## Ringkasan
<= 10 lines, rewritten after each item

## Antrian
| # | Key | Tipe | Judul | Aksi | Gate 1 | Hasil | Catatan |
|---|---|---|---|---|---|---|---|
| 1 | ED-1234 | Story | ... | ba | disetujui | review | be a1b2c3, fe d4e5f6 |
| 2 | ED-1235 | Bug | ... | ba | disetujui | blocked | dev: 1 pertanyaan |
| 3 | ED-1236 | Story | ... | ba | disetujui | blocked | menunggu ED-1235 |
| 4 | ED-1237 | Task | ... | ba | ditunda | - | |

## Log
- 2026-10-06 21:40 ED-1234 review (1j 35m)
```

## Boundaries
- No code before Gate 1 approval of that item. Never Done without `/v5-epic review`. Never push.
- During the run nobody is asked: developer decisions become blockers; environment failures stop the run.
- Never touch items assigned to someone else, or In Progress items without a `run.md` here.
- One item at a time during the run. Never kill the developer's processes.
