Implement the approved bounded fix in:
/Users/tariq/code/erada-platform/.worktrees/authorization-full-cutover/docs/runbooks/plans/fix-frontend-assignment-scope-contract.md

Workspace: /Users/tariq/code/erada-platform/.worktrees/authorization-full-cutover

The independent verifier found that `resources/js/entities/role/model/role.ts` still exposes unsupported canonical assignment scope literals `cluster`, `hospital`, and `team` through `AuthorizationAssignmentScopeType`, which types `AuthorizationRoleAssignmentWrite.scope_type`.

Make only the approved minimal correction. Preserve unrelated dirty-tree work. Run the plan's verification commands, report exact exit codes and test counts, and include `git diff -- resources/js/entities/role/model/role.ts` plus concise file coverage. Do not stage, commit, push, merge, deploy, or mutate databases.
