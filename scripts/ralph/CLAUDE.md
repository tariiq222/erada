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
5. Run `scripts/ralph/select-next-story.sh scripts/ralph/prd.json` and implement
   exactly the JSON story it returns. Never select a story by priority alone:
   `dependsOn` must be satisfied first. Exit with a stop report if the selector
   exits 3 because pending stories are dependency-blocked.
6. Implement that single user story.
7. Run quality checks (see `Quality Requirements` below).
8. Update nearby `CLAUDE.md` files if you discover reusable patterns.
9. If checks pass, commit ALL changes with message:
   `feat(<module>): [Story ID] - [Story Title]`
   OR `refactor(<module>): ...` for comment translation passes.
10. **Autonomous merge** — push the branch, open the PR, wait for CI, then merge
    if and only if all hard auto-merge conditions pass (see `## Autonomous CFA
    Loop` below). NO per-story approval needed between CFA stories.
11. Update the PRD to set `passes: true` for the completed story.
12. Append your progress to `progress.txt`.
13. **Continue to the next story.** Do NOT stop at "READY TO MERGE".

Stories with `autoMergeEligible: false` are the exception to steps 10-13:
push and open the PR, then emit the stop report and `<promise>STOP</promise>`.
Do not merge, mark the story passed, or start another story until Tariq reviews
and merges it.

## Progress Report Format

APPEND to `scripts/ralph/progress.txt` (never replace):

```

End every stop report with this exact machine-readable line:

```text
<promise>STOP</promise>
```

The wrapper exits 2 when it sees this line. Never emit it for ordinary progress.
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

## Update CLAUDE.md Files

Before committing, check if any edited files have learnings worth preserving in nearby `CLAUDE.md` files:

1. **Identify directories with edited files** - Look at which directories you modified
2. **Check for existing CLAUDE.md** - Look for CLAUDE.md in those directories or parent directories
3. **Add valuable learnings** - If you discovered something future developers/agents should know:
   - API patterns or conventions specific to that module
   - Gotchas or non-obvious requirements
   - Dependencies between files
   - Testing approaches for that area
   - Configuration or environment requirements

**Examples of good CLAUDE.md additions:**
- "When modifying X, also update Y to keep them in sync"
- "This module uses pattern Z for all API calls"
- "Tests require the dev server running on PORT 3000"
- "Field names must match the template exactly"

**Do NOT add:**
- Story-specific implementation details
- Temporary debugging notes
- Information already in progress.txt

Only update CLAUDE.md if you have **genuinely reusable knowledge** that would help future work in that directory.

## Quality Requirements (Erada PMO)

Backend quality gates (run before commit):

```bash
./vendor/bin/pint --test app database tests       # formatter dry-run
composer phpstan                                 # static analysis
php artisan test --filter=<FocusedTest>           # focused tests first
```

Acceptance gate for CFA cluster stories:

```bash
php artisan test --filter=<ScopeOfStory>
php artisan test --filter=ClusterTree
php artisan test --filter=AccessDecision
php artisan test --filter=OrganizationHierarchy
php artisan test --filter=CapabilityAlias
```

Do NOT commit if any of these fail. Do NOT skip tests.

## Autonomous CFA Loop

**Standing approval from Tariq (2026-07-09)** for the Cluster Full Authority (CFA)
story sequence: Ralph runs CFA stories end-to-end (branch → tests → push → PR → CI →
merge → next) WITHOUT per-story approval.

### Auto-merge conditions (ALL must hold)

1. All GitHub checks are SUCCESS (SQLite Guard · PDPL · Code Quality · Tests (PHP 8.4))
2. No merge conflicts
3. No migrations unless the story explicitly allows
4. No AccessDecision change unless the story explicitly allows
5. No raw PII exposure
6. No write widening outside the current story's scope
7. No forbidden modules touched (OVR / Surveys / HR / ActivityLog)
8. No P0/P1 security finding
9. Local tree clean before next story
10. Story scope matches the CFA-00 roadmap

If all 10 conditions hold → merge automatically. Do not ask Tariq.

### Hard stop conditions (any ONE halts the loop)

1. CI fails twice on the same story
2. Merge conflict
3. AccessDecision change needed outside the story's documented scope
4. Migration needed outside the story's documented scope
5. PII / raw sensitive data risk discovered
6. Write path widened outside the story's documented scope
7. Unclear authorization contract discovered during implementation
8. Branch protection blocks the merge
9. Security P0/P1 finding (auth bypass, IDOR, PII leak)
10. Direction R / Direction B integrity violation
11. KPI 9-D-D1a / Strategy 9-D-D1b / CFA-01 / CFA-02 / CFA-03 regression

### Stop report format (when hard stop fires)

```md
## Stop Report — CFA-NN

- Story:
- Branch:
- Blocker type: (ci_twice | merge_conflict | engine_change | migration | pii |
                  write_widening | unclear_contract | branch_protection |
                  security_p0 | direction_r_violation | regression)
- Files involved:
- Current behavior:
- Expected behavior:
- Why this cannot proceed safely:
- Proposed options:
- Recommended option:
```

### CFA story sequence (per CFA-00 audit 2026-07-09)

| Story | Branch | Scope |
|---|---|---|
| CFA-01 | `phase-cfa-01-cluster-tree-manage-export-primitives` | ✅ DONE — engine primitives |
| CFA-02 | `phase-cfa-02-kpi-cluster-export-widening` | ✅ DONE — KPI export via `KPIS_EXPORT + CLUSTER_TREE_EXPORT` |
| CFA-03 | `phase-cfa-03-strategy-cluster-manage-widening` | ✅ DONE — Portfolio/Blocker governance writes via `STRATEGY_* + CLUSTER_TREE_MANAGE` |
| CFA-04 | `phase-cfa-04-projects-cluster-widening` | Projects cluster read + status writes + sanitized show |
| CFA-05 | `phase-cfa-05-risks-cluster-widening` | Risks cluster read + reassess/status writes + export gate |
| CFA-06 | `phase-cfa-06-meetings-cluster-widening` | Meetings cluster read + governance writes (Direction R/B integrity) |
| CFA-07 | `phase-cfa-07-users-cluster-directory-widening` | Users cluster limited directory (HIGH PII) |
| CFA-08 | `phase-cfa-08-tasks-cluster-widening` | Tasks cluster read (after CFA-09 OVR primitive) |
| CFA-09 | `phase-cfa-09-ovr-cluster-aggregate-reporting` | OVR aggregate reporting (never raw) |
| CFA-10 | `phase-cfa-10-surveys-cluster-aggregate-reporting` | Surveys aggregate reporting (never raw) |
| CFA-11 | `phase-cfa-11-activitylog-cluster-audit-role` | ActivityLog cluster audit role + IP/UA redaction |

## Hard Rules (Erada PMO — non-negotiable)

These come from the global Phase 9-D / CFA execution guides and CLAUDE.md. **Do NOT violate
any of these without explicit Tariq approval:**

- **CFA cluster_tree widening is read + governance write + export ONLY.** CFA-00 owner
  decisions explicitly EXCLUDE: CRUD outside governance writes, project role assignment,
  meeting operational widening, recommendation transitions, raw OVR/Surveys export, full HR PII.
- **Engine primitives (`CLUSTER_TREE_VIEW` / `MANAGE` / `EXPORT`) are read-only strings.**
  No AccessDecision change unless a story explicitly allows it.
- **No `sameOrganization` / `extractOrganizationId` / `buildScopeChain` changes.**
- **No `SCOPE_CLUSTER` revival.**
- **No migrations** unless a story explicitly allows it.
- **No touching OVR / Surveys / HR / ActivityLog** (each has its own dedicated CFA story).
- **No reverting Direction R / Direction B / KPI 9-D-D1a / Strategy 9-D-D1b / CFA-01..03.**
- **No skipping tests.**
- **All comments, code, commit messages in English** (LR — global preference).

## Authorization Contract (every story must follow)

A user can read / manage / export cluster-descendant records ONLY when they have **BOTH**:

```text
read   across cluster:   <module>.view                + core.cluster_tree.view
write  across cluster:   <module>.{edit|manage|approve|...} + core.cluster_tree.manage
export across cluster:   <module>.export|audit.export + core.cluster_tree.export
```

Denial matrix (all must hold):

| Scenario | Expected |
|---|---|
| Missing module capability | Denied or empty result |
| Missing cluster primitive | Denied or empty result |
| Sibling organization | Denied or hidden |
| Child organization trying to see parent | Denied or hidden |
| Null-org non-super user | Fail-closed (whereRaw false) |
| Sensitive target | Denied (SensitivelyScoped floor) |
| Write endpoint outside story scope | Remains strict same-org |
| Super admin | Unchanged (short-circuit) |

## Reference Pattern (KPI 9-D-D1a + CFA-01..03)

When implementing a new CFA story, mirror the established pattern:

1. **Scope** (`app/Modules/<Module>/Scopes/User<Module>Scope.php`):
   - Add `applyTo<Entity>s($query, $actor)` per entity type.
   - super_admin → no filter.
   - null-org actor → `whereRaw('false')` (fail-closed).
   - regular actor → `whereIn('<table>.organization_id', $this->clusterVisibleOrgIds($actor, $purpose))`.
   - `clusterVisibleOrgIds($actor, $purpose)` checks BOTH the appropriate module cap + cluster cap,
     then unions `Organization::descendantIds()` (BFS via `parent_id`, depth cap 32, fail-closed on cycle).
   - `$purpose` is `'view'` (default) or `'export'` — each fires its own primitive pair.

2. **Policy** (`app/Modules/<Module>/Policies/<Entity>Policy.php`):
   - `view()` / `manage*()` / `change*()` / `assign*()` use the two-path pattern (Path 1:
     same-org via engine; Path 2: cross-org rescue via `AccessDecision::can($actor,
     <CLUSTER_PRIMITIVE>, $entity)` — but only if BOTH the module + cluster primitive
     entitlements are held on actor.organization_id).
   - Writes outside the story scope stay strict same-org.
   - `viewAny()` requires the module read capability.

3. **FormRequest authorize()**:
   - `show`-style requests delegate to `app(<Entity>Policy::class)->view($user, $model)`.
   - List-style requests keep the module capability check.

4. **Controllers**:
   - List/index/show endpoints use the Scope.
   - Write endpoints keep their precheck guards.
   - The null-org fail-closed check is replaced by the Scope.

5. **Tests**:
   - `tests/Unit/<Module>/Policies/ClusterTree<Module><Aspect>Test.php` — policy coverage.
   - Cover all 6+ acceptance scenarios per surface.

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
- **Autonomous merge is the default** for CFA stories — do not stop and ask for
  per-story merge approval. Hard stop only on the conditions listed above.
