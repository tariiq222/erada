# Fix frontend canonical assignment scope contract

## Scope

- Edit only `resources/js/entities/role/model/role.ts` unless a targeted test proves a directly coupled fixture must change.
- Remove `cluster`, `hospital`, and `team` from `AuthorizationAssignmentScopeType`.
- Preserve the exact canonical values: `all`, `organization`, `department`, `own`, `project`, `program`, `portfolio`, `kpi`, `meeting`, and `survey`.

## Verification

- Run the handoff Step 1 `rg` command and review contextual hits.
- Run `npm run typecheck`.
- Run the focused canonical authorization/scope Vitest files.
- Run `git diff --check` for the owned file.

## Safety

- No database mutation.
- No staging, commit, push, merge, or deploy.
- Preserve all unrelated dirty-tree changes.
