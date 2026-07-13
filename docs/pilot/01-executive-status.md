# Report 1 — Executive System Status Report

**Document type:** Status snapshot
**Audience:** Executive sponsors, steering committee, pilot stakeholders
**As-of date:** 2026-07-12
**Data source:** Multi-agent audit sweep (35 read-only research agents)

---

## 1. Headline

The system is **functionally complete** for the 11 modules in scope, with a **mature and well-tested authorization model** that supports cluster-tree visibility across 7 modules. **Process discipline is degraded** (CI bypass, unpushed work on `main`, red pipelines, WIP commits on the shipping branch) and **two P0 production-readiness gaps** must be closed before pilot launch (Docker root user + secrets in image; PII leak in `EmployeeController`). Cluster Full Authority is the active business model (since 2026-07-09) but is **not documented in the repo** — the contract change is visible only in agent-private memory.

## 2. Module status snapshot

| Module | Models | Controllers | FormRequests | Tests | Cluster widening | Authz state |
|---|---:|---:|---:|---:|:---:|:---|
| Core | 9 | 13 | 24 | 30 | Yes (User directory) | Engine |
| HR | 5 | 4 | 14 | 20 | **Excluded** (PII floor) | Engine |
| Meetings | 8 | 8 | 22 | 38 + 512 methods | CFA-06 read | Engine |
| OVR | 7 | 4 | 11 | 29 | CFA-09 aggregate only | Engine |
| Performance | 3 | 3 | 7 | 2 | CFA-01 + CFA-02 | Engine |
| Projects | 7 | 3 | 19 | ~62 files | CFA-04 view + status | Engine |
| RiskManagement | 9 | 5 | 17 | 19 | CFA-05 | Engine |
| Shared | 3 | 4 | 3 | 7 | CFA-11 (cluster_auditor) | Engine |
| Strategy | 5 | 5 | 29 | 0 dedicated isolation | CFA-01b + CFA-03 | Engine |
| Surveys | 10 | 9 | 38 | 16 | CFA-10 aggregate only | Engine |
| Tasks | 1 | 1 | 5 | 21 | CFA-08 (STOP for review) | Engine |

All 11 modules are **migrated to the AccessDecision engine**. No `hasPermissionTo` calls remain in production code (5 documented live sites are scoped-role compat shims, not real Spatie calls). 8 `*CapabilityProvider` files exist; 7 are tagged and registered with the engine.

## 3. Quality status

| Check | Last known status | Notes |
|---|---|---|
| Pint (PHP formatter) | Clean | No `--test` failures |
| PHPStan | Clean | Level 2 with baseline |
| TypeScript (tsc) | Clean | `npm run typecheck` |
| ESLint | Clean | Max-warnings 1200, currently well under |
| PHPUnit | 33% CI failure rate (last 30 runs) | Mostly known non-deterministic flake absorbed by isolation re-run; **>25 fails = real regression** logic works |
| Vitest | Clean | |
| Playwright E2E | `continue-on-error: true` | Full suite is non-blocking on PRs |
| `npm run design:check` | **Not run in CI** | Defined but missing from `.github/workflows/ci.yml::quality` |
| `composer ci` (script) | **Not run in CI** | `check-task-model.php` and `check-cluster-tree-contract.sh` are defined but never invoked |
| `npm run quality` (script) | Not run as a unit | Pieces (typecheck + lint + test + design:check) are run individually |
| sqlite-guard | Healthy | No failures in last 30 runs |
| CI-1 cache-path fix | Healthy | Verified in `ci.yml:102-126` |

## 4. CI / PR / merge cadence

- **27 PRs** total · **22 merged** · **5 closed unmerged** · **0 currently open**.
- **22 PRs merged in the last week** (Jul 7-11): extremely high cadence (CFA-01..11 + Stabilization 1-5 + Phase 9 series).
- **Average PR size: ~1,700 lines**; several PRs exceed the reviewable-diff threshold (>1,000 lines).
- **Merge strategy:** merge commits (not squash); history is noisy but PR-traceable.
- **PR merge times:** self-merged at high velocity (e.g., PR #2 merged 90s after creation, PR #3 merged 68s after creation).
- **Conventional commits:** `feat:` / `fix:` / `test:` / `docs:` / `wip:` / `chore:` distribution shows 16/17/6/5/3/3. All commit messages are English (CLAUDE.md hard rule satisfied).
- **3 `wip:` commits landed directly on `main`** (`e1854fd wip: independent admin application paused at 9/11`, `d6fe12c`, `68451bb`).

**Critical process issues:**
- **P0 — 28 commits on local `main` never pushed** (`main` is `ahead 28` of `origin/main`). The entire "independent admin application" stream was committed **directly to local `main`** with no branch, no PR, no CI. **64 uncommitted files** sit on top.
- **P1 — PRs merged despite red CI.** PRs #18, #19, #20, #21, #22 carry titles literally saying "STOP regardless of CI"; CFA-07/CFA-11 CI failed twice and were merged anyway.
- **P0 — Deploy + Backup pipelines are RED.** Latest deploy run failed at `Run Tests`. Daily backup failed on Jul 11 and Jul 12.

## 5. Security posture (P0 → P2)

| Sev | Finding | Where |
|---|---|---|
| **P0** | App container runs as **root**; vulnerability in any process = root in container | `Dockerfile:71-73, 95-132` |
| **P0** | `.env.example` baked into every image layer; `DB_PASSWORD=secret` visible to `docker pull` | `Dockerfile:60-61` |
| **P0** | Redis ships with no `--maxmemory` / `--maxmemory-policy`; OOM under load | `docker-compose.yml:46-59` |
| **P0** | `EmployeeController::index/show` returns raw model JSON, leaking 22 PII fields (`national_id`, `iqama_number`, `birth_date`, address, emergency contacts) to any `HR_VIEW` actor | `app/Modules/HR/Http/Controllers/EmployeeController.php:64,74` |
| **P0** | No encryption on `employee_personal_info` (national_id, iqama_number are plaintext indexed) | `app/Modules/HR/Models/EmployeePersonalInfo.php` |
| **P0** | 28 unpushed commits on local `main` (CI bypass) | `main` vs `origin/main` |
| **P1** | `dangerouslySetInnerHTML` in `ProjectCharter.tsx:61` (currently hardcoded CSS only) | `resources/js/pages/projects/charter/ProjectCharter.tsx` |
| **P1** | `bootstrap/app.php:143` logs `$e->getSql()` with bind values; PII in `national_id`/`email` queries persists in `storage/logs/` | `bootstrap/app.php:143` |
| **P1** | `ActivityLog::created` stores plaintext model `toArray()` on update | `app/Modules/Shared/Traits/LogsActivity.php` |
| **P1** | `throttle:uploads` and `throttle:delete` limiters defined but never applied to any route | `AppServiceProvider:186-289` |
| **P1** | Idempotency middleware absent on Meetings + Shared + OVR + Strategy + Surveys + Performance + HR mutations | various `Routes/api.php` |
| **P1** | No metrics exporter (Prometheus / OpenTelemetry); Sentry is the only post-collection destination | (absent) |
| **P1** | `survey_responses.respondent_organization_id` UNINDEXED — full scan on Phase 3 cluster aggregates | migration `2026_07_10_120000` |
| **P1** | `Organization::parent_id` schema lacks deferred multi-hop cycle trigger (only direct self-reference CHECK) | migration `2026_07_12_000003` |
| **P2** | Engine `AccessDecision::whyCan()` denial path writes no audit row (only Spatie middleware denies log to ActivityLog) | `app/Modules/Core/Authorization/AccessDecision.php` |
| **P2** | `sanctum.php` hard-coded stateful fallback includes dev hosts (`localhost:5173`, `::1`) | `config/sanctum.php:18-28` |
| **P2** | `requirements` from `getProjectsByStatus` + `calculateMonthlyTrends` fire 1 + 18 SQL queries vs achievable 2 | `app/Modules/Core/Services/DashboardStatisticsService.php:225-319` |
| **P2** | 33 page files use raw Tailwind color utilities bypassing `--status-*` tokens | various |
| **P2** | 9 files implement raw `<table>` instead of `DataTable` | admin pages |
| **P2** | `ProjectResource` leaks `organization_id` + `created_by` on cluster reads (self-flagged TODO) | `app/Modules/Projects/Http/Resources/ProjectResource.php` |
| **P2** | `SanitizeInput` middleware strips tags on every request — could break legit HTML in user-content fields (none currently affected) | `app/Http/Middleware/SanitizeInput.php` |

## 6. Performance snapshot

- **Cache coverage of expensive endpoints: ~25%**. Dashboard stats (Redis, 300s TTL) is the main beneficiary; all list/index endpoints (projects, tasks, meetings, KPIs, etc.) bypass cache entirely.
- **Cache invalidation gap**: `dashboard_stats` cache flushed only on `TaskObserver` / `ProjectObserver`; Milestone / Comment / Risk / OVR changes leave stale counts for 5 min.
- **Cluster scope efficiency**: each widening scope fires 2 queries per request (1 `Organization::find` + 1 BFS via `descendantIds`); NOT memoized per-request across scopes.
- **N+1 hotspots**: top 10 found, including `StrategyDashboardController::index`, `PortfolioController::index`, `TaskResource::toArray` fallback, `DashboardStatisticsService::calculateDepartmentsPerformance`, `calculateMonthlyTrends` (18 COUNT(*) queries).
- **Queue topology**: all jobs on `default` queue (no `->onQueue()`); `numprocs=1` (Dockerfile claim of 2 is unverifiable — see Report 6). A slow `DataImport` job blocks every login OTP. **No dedicated `notifications`/`imports`/`exports` queues.**
- **Frontend bundle**: `npm run build` not run; no `dist/` exists. Largest configured chunk: `@tabler/icons-react` (4 000 icons in one barrel, eagerly bundled). `recharts` and `@dnd-kit/*` are lazy.
- **Frontend memoization**: 0 `React.memo` in 318 .tsx files. `DataTable` (~570 lines) re-renders the entire row set on every keystroke in a filter input. Top fix candidate: memoize `DataTable`, `Pagination`, `Avatar`, `StatusBadge`, `EmptyState`.
- **Frontend image lazy**: 0 of 8 `<img>` tags use `loading="lazy"`; no `decoding="async"`; no explicit `width`/`height` to prevent CLS.

## 7. Multi-tenancy posture

- **75 Eloquent models audited; 27 (36%) have direct `organization_id`**.
- **Projects is weakest (14%)** — only `Project` has the column; Milestone, MilestoneDeliverable, ProjectExpense, ProjectRisk, Stakeholder all derive via `project_id` with no dedicated scope class.
- **Surveys is second-weakest (10%)** — only `Survey` has `organization_id`; `SurveyResponse` uses `respondent_organization_id` snapshot (Phase 3A); 8 models derive via Survey chain.
- **Tasks: org_id added in Phase 2A but NOT in `$fillable`** — set by repository/observer, not by mass assignment.
- **Strategy `Program` anomaly**: `scopeOrganizationId()` reads `$this->organization_id` first but the column is not in `$fillable` and not visible in migration history; relies on Portfolio fallback. **Verify column existence.**
- **Cross-org isolation test coverage: 56/137 Feature tests (41%)** exercise org isolation or cluster_tree widening. Weakest: RiskManagement (0 dedicated), Performance (0 dedicated), Strategy (0 dedicated).
- **All cluster_tree widening scopes verified PASS**: dual pair (module cap + cluster_tree primitive) required; descendants-only via BFS; fail-closed on null org actor; super_admin short-circuit; sibling clusters isolated.

## 8. i18n / accessibility / design system

- **i18n key parity: 100%** — 3,028 keys in both `ar.json` and `en.json`, 0 missing.
- **4 asymmetric interpolation keys** (ar has `{{var}}` placeholder, en doesn't) — silently broken in en: `projects.delete_success`, `projects.step_of`, `projects.stats_over_budget`, `users.delete_confirm`.
- **0 Arabic pluralization variants** — only `_other` everywhere; `_one`, `_two`, `_few`, `_many`, `_zero` missing.
- **Intl/format coverage: ~5-8%** — `shared/lib/utils.ts` hardcodes `'en-GB'` for all date/number formatters; Arabic users see British-formatted dates. PHP `lang/ar/` missing `auth.php`, `pagination.php`, `passwords.php` — Arabic users see English validation-rule text.
- **WCAG contrast: PASS overall** — all semantic token pairs (text/border/surface) clear AA in light and dark themes. Two borderline pairs (`--text-tertiary` on white / `--surface-subtle`) hover just above 4.5:1.
- **Top a11y gaps**: no focus management on route change (`AppLayout.tsx` `<main>` lacks `id`/`tabIndex`/`ref`); legacy `Dropdown.tsx` lacks keyboard handling; `SkipToMain` rendered only on 6 OVR pages (should be in AppLayout/AdminLayout).
- **Design system**: `design-tokens.css` is **exemplary** (OKLCH, tinted neutrals, AA annotations, complete `.dark` parallel). Shared/ui has 47 primitives; 196 files import from `@shared/ui`. **Top violations**: 225 files import directly from `@tabler/icons-react` (bypassing curated barrel); 33 files use raw Tailwind color utilities; 9 files implement raw `<table>`; `ErrorBoundary` imported only in `app.tsx`.

## 9. Documentation

- **0 formal ADRs** in `docs/adr/` or `ADR-*` patterns.
- **8 markdown files** in `docs/` (all <30 days old, none stale by age):
  - `docs/authz/record-rule-evaluator-column-policy.md`
  - `docs/authz/resource-authorization-graph.md`
  - `docs/authz/2026-07-11-organization-permissions-remediation-design.md`
  - `docs/superpowers/plans/{ralph-cfa-recovery, independent-admin-application, authorization-full-cutover}.md`
  - `docs/superpowers/specs/{independent-admin-application-design, local-system-stabilization-design}.md`
  - `docs/migrations-remediation-playbook.md`
- **No `docs/API.md`, `docs/03-TECHNICAL_ARCHITECTURE.md`, `docs/DESIGN_RULES.md`, or `docs/DATABASE_GUIDELINES.md`** (referenced by `docs-writer` skill convention).
- **CLAUDE.md / AGENTS.md are byte-near-duplicates** (264 lines each) — keep one or split audience explicitly.
- **Cluster Full Authority pivot (2026-07-09) is NOT documented in the repo** — visible only in agent-private memory. The contract change that supersedes the read-only 9-D-D framing is invisible to anyone reading the repo.
- **2 known contradictions between docs and code**:
  - CLAUDE.md says "Redis 7 for cache/queue/session"; defaults in `config/{cache,queue}.php` are `database`; `.env.example` ships `CACHE_STORE=redis` and `SESSION_DRIVER=database`.
  - CLAUDE.md says "11 modules"; `ls app/Modules/` shows 12 directories (Shared is infra; CLAUDE.md treats it as not counting).

## 10. Cluster Full Authority contract change — the load-bearing risk

The 2026-07-09 business-model pivot changed Cluster from read-only oversight to full authority over descendant organizations. This affects every future cluster story and possibly the engine primitive itself.

The contract change is documented ONLY in:
- `~/.claude/projects/.../memory/cluster-full-authority-contract-change-2026-07-09.md` (out-of-tree, agent-private)
- Block comments inside `app/Modules/Core/Authorization/Capability.php`

Until an ADR (`ADR-CFA-01`) lands in `docs/adr/`, contributors risk:
1. Reverting to the read-only 9-D-D framing on new modules.
2. Mis-widening the wrong primitives (e.g., extending `CLUSTER_TREE_VIEW` instead of `CLUSTER_TREE_MANAGE` for governance writes).
3. Bypassing the dual-pair contract for "convenience" and reintroducing IDOR.

## 11. Top 5 actions to close before pilot

1. **Move the 28 unpushed commits + 64 uncommitted files to a feature branch, push, and open a reviewed PR.** Nothing reaches `main` without CI.
2. **Close the three Docker P0 gaps** — non-root user, secrets out of the image layer, Redis `--maxmemory` + eviction policy.
3. **Replace `EmployeeController::index/show` raw JSON responses with a PII-aware `EmployeeResource`** and add `'encrypted'` casts to `EmployeePersonalInfo`.
4. **Enable `main` branch protection** — require PR + green `test`/`quality`/`pdpl` checks + ≥1 review + linear history. Block direct pushes.
5. **Fix the production deploy pipeline** (currently red) and the daily backup (failed twice).

Full detail in Report 5 (Go/No-Go) and Report 6 (risk register).