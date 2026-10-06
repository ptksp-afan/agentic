# FE build - staging, never the developer's build/

Developer's rules (2026-10-01): the developer serves `<FE_DIR>/<FE_SERVE_DIR>` with
`serve .\build\ -p <FE_SERVE_PORT>`. CRA empties its target folder first, so building into `build/` while
`serve` holds files fails with EPERM and leaves `build/` empty (happened twice).

| Who | Does |
|---|---|
| FE dev, QA | `scripts/fe-build.sh build` → `BUILD_PATH=$FE_STAGING_DIR yarn build` (Node from `.nvmrc`), log in `work/fe-build.log`. Never delete the staging folder |
| Orchestrator, before Gate 2 (or when the user wants to see the UI) | `scripts/fe-build.sh publish` |

`publish` checks the serve port itself (developer's request, 2026-10-01):
- nothing listening → **copies** staging into `build/` right away (never rebuilds), then adds `public/*`
  assets that the staging build lacks (except `index.html`). Tell the user to start `serve` again.
- listening → exits 3. Ask the user to stop `serve`; when they say so, run `publish` again.

`scripts/fe-build.sh status` prints the port state and the staging build time.

Never kill `serve` or any other user process.
