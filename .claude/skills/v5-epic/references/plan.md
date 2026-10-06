# Planning an epic from a brief (empty epic, or `--plan`)

Used when the queue has no runnable items but a `--brief` is given, or with `--plan` (then only for
brief requirements that no existing item covers). The output replaces the Jira queue for this run; the
items become real Stories at Gate 1.

1. `v5-brief` for the epic → `epics/<EPIC>-brief/README.md` (screens, requirements, conflicts).
2. `v5-analyst` in **epic-plan** mode → `epics/<EPIC>-plan.md`: proposed items with temporary ids
   `NEW-<EPIC>-01..` (epic key without the dash, e.g. `NEW-ED1022-01`), type, title, slug, scope,
   `depends_on`, the brief requirements each covers, and plan-level questions.
3. Show the plan as the queue (step 0), with its questions. AskUserQuestion: **Mulai BA** / **Ubah rencana**
   (notes → same analyst revises the plan) / **Batal**. Plan questions are answered here, since the split
   depends on them.
4. Phase 1 per planned item: `scripts/new-feature.sh NEW-<EPIC>-nn <slug> --epic <EPIC>`, then BA as usual;
   the analyst prompt adds the item's row from the plan as its scope. Items get no own `v5-brief`: they
   use the epic digest.
5. Gate 1 approval of a planned item = `createJiraIssue` (type from the plan, `parent` = epic, assignee,
   labels) in plan order, then: rename `features/NEW-<EPIC>-nn-<slug>` → `features/<KEY>-<slug>`, set
   `key:` in `run.md` and `spec.md`, record the mapping in the epic log, then the normal Gate 1 Jira sync.
   Rejected/deferred planned items create nothing in Jira.
