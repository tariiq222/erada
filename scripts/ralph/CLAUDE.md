# Ralph Agent Instructions — Erada PMO

You are an autonomous coding agent working on the **Erada PMO** project
(Laravel 12 + React 19 SPA, modular monolith under `app/Modules/`).

## Your Task

1. Read the PRD at `scripts/ralph/prd.json` (in the same directory as this file).
2. Read the progress log at `scripts/ralph/progress.txt` — check `## Codebase Patterns` section FIRST.
3. **Read `CLAUDE.md`** at the project root before touching any code (LR-001). It has
   project-wide conventions, authz architecture, multi-tenancy rules, and quality commands.
4. Check you're on the correct branch from PRD `branchName`. If not, check it out
   (do NOT create from main unless `git status` is clean — see LR-008).
5. Pick the **highest priority** user story where `passes: false`.
6. Implement that single user story (read-only — never widen writes).
7. Run quality checks (see `Quality Requirements` below).
8. Update nearby `CLAUDE.md` files if you discover reusable patterns.
9. If checks pass, commit ALL changes with message:
   `feat(<module>): [Story ID] - [Story Title]`
   OR `refactor(<module>): ...` for comment translation passes.
10. Update the PRD to set `passes: true` for the completed story.
11. Append your progress to `progress.txt`.

## Progress Report Format

APPEND to `scripts/ralph/progress.txt` (never replace):

```
## [Date/Time] - [Story ID]
- What was implemented
- Files changed
- **Learnings for future iterations:**
  - Patterns discovered
  - Gotchas encountered
  - Useful context
---
```

## Consolidate Patterns

If you discover a **reusable pattern**, add it to the `## Codebase Patterns` section
at the TOP of `progress.txt`. Keep this section focused on cross-iteration guidance.

## Quality Requirements (Erada PMO)

Backend quality gates (run before commit):

```bash
./vendor/bin/pint --test app database tests       # formatter dry-run
composer phpstan                                 # static analysis
php artisan test --filter=<FocusedTest>           # focused tests first
```

Acceptance gate for this phase (Phase 9-D-D1b — Strategy cluster_tree read):

```bash
php artisan test --filter=Strategy
php artisan test --filter=ClusterTree
php artisan test --filter=AccessDecision
php artisan test --filter=OrganizationHierarchy
php artisan test --filter=CapabilityAlias
```

Do NOT commit if any of these fail. Do NOT skip tests.

## Stop Condition

After completing a user story, check if ALL stories have `passes: true`.

If ALL complete → reply with: `<promise>COMPLETE</promise>`

Otherwise, end normally (next iteration picks up next story).

## Hard Rules (Erada PMO — non-negotiable)

These come from the global Phase 9-D execution guide and CLAUDE.md. **Do NOT violate
any of these without explicit Tariq approval:**

- **Read-only cluster_tree widening.** Never widen writes (create / update / delete /
  status change / link / unlink / resolve / escalate / priority / owner assignment).
- **No AccessDecision changes.** Engine is stable. Use the rescue branch pattern.
- **No `sameOrganization` / `extractOrganizationId` / `buildScopeChain` changes.**
- **No `SCOPE_CLUSTER` revival.**
- **No migrations** in this epic.
- **No new cluster write capabilities.**
- **No touching OVR / Surveys / HR / ActivityLog / Tasks** (excluded or deferred).
- **No reverting Direction R / restoring legacy decisions routes / restoring
  manageMembers.**
- **No skipping tests.**
- **Do not merge without Tariq approval.** Push + open PR + wait for CI + report.
- **All comments, code, commit messages in English** (LR — global preference).

## Authorization Contract (every story must follow)

A user can read cluster-descendant records ONLY when they have **BOTH**:

```text
1. <module>.view
2. core.cluster_tree.view
```

Denial matrix (all must hold):

| Scenario | Expected |
|---|---|
| Missing module view capability | Denied or empty result |
| Missing `core.cluster_tree.view` | Denied or empty result |
| Sibling organization | Denied or hidden |
| Child → parent (no widening to parent) | Denied or hidden |
| Null-org non-super user | Fail-closed (whereRaw false) |
| Sensitive target | Denied unless existing module policy explicitly permits same-org access |
| Write endpoint | Remains strict same-org |
| Super admin | Unchanged (short-circuit) |

## Reference Pattern (KPI 9-D-D1a — `phase-9d-d1a-kpi-cluster-tree-read`)

When implementing a new module's cluster_tree read widening, mirror the KPI pattern:

1. **Scope** (`app/Modules/<Module>/Scopes/User<Module>Scope.php`):
   - `applyTo<Entity>s($query, $actor)` mirrors KPI's `applyToKpis`/`applyToMeasurements`/`applyToLinks`.
   - super_admin → no filter.
   - null-org actor → `whereRaw('false')` (fail-closed).
   - regular actor → `whereIn('<table>.organization_id', $this->clusterVisibleOrgIds($actor))`.
   - `clusterVisibleOrgIds()` checks BOTH module.view + `CLUSTER_TREE_VIEW`, then unions
     `Organization::descendantIds()` (BFS via `parent_id`, depth cap 32, fail-closed on cycle).

2. **Policy** (`app/Modules/<Module>/Policies/<Entity>Policy.php`):
   - `view()` uses the two-path pattern (Path 1: same-org via engine; Path 2: rescue via
     `AccessDecision::can($actor, CLUSTER_TREE_VIEW, $entity)` — but only if BOTH module.view +
     CLUSTER_TREE_VIEW are held on actor.org).
   - Writes (`update`/`delete`/`create`/etc.) stay strict same-org.
   - `viewAny()` requires STRATEGY_VIEW module capability.

3. **FormRequest authorize()**:
   - `show`-style requests delegate to `app(<Entity>Policy::class)->view($user, $model)`.
   - List-style requests keep STRATEGY_VIEW capability check.

4. **Controllers**:
   - List/index/show endpoints use the Scope.
   - Write endpoints keep their precheck guards.
   - The null-org fail-closed check is replaced by the Scope.

5. **Tests**:
   - `tests/Unit/<Module>/Policies/ClusterTree<Module>PolicyTest.php` — policy coverage.
   - `tests/Unit/<Module>/Scopes/ClusterTreeUser<Module>ScopeTest.php` — scope coverage.
   - Cover all 6 acceptance scenarios (both grants, missing module, missing cluster_tree,
     sibling, child-to-parent, null-org, write-still-strict, super_admin).

## Branch & Git Discipline (LR-008)

- Branch name comes from PRD `branchName`. DO NOT create from main while uncommitted
  changes exist (run `git status --short` first).
- Never `git stash` / `reset --hard` / `checkout <ref>` / `rebase` / `add -A` against
  a shared working tree.
- Always stage explicit paths and `git status`-verify before committing.
- If `git status` shows files you did not touch (a concurrent session's edits), STOP
  and verify rather than committing or re-editing.

## Important

- Work on ONE story per iteration.
- Commit frequently (one commit per story is fine).
- Keep CI green.
- Read the `## Codebase Patterns` section in `progress.txt` before starting.
- **Arabic comments / Arabic commit messages / Arabic code-adjacent text are FORBIDDEN**
  in this project (global preference). Translate before commit.