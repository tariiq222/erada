# Report 6 — Risks, Issues, and Decisions Log

**Document type:** Living risk register
**Audience:** Sponsor, engineering leads, on-call rotation
**As-of date:** 2026-07-12
**Update cadence:** Weekly during pilot; monthly otherwise

---

## 1. Risk scoring

- **Likelihood**: 1 (rare) to 5 (almost certain)
- **Impact**: 1 (negligible) to 5 (catastrophic / data loss / breach)
- **Score**: L × I (max 25)

| Score | Tier |
|---|---|
| 1-4 | Low — monitor only |
| 5-9 | Medium — schedule remediation |
| 10-14 | High — fix before next milestone |
| 15-25 | Critical — fix or accept NOW; do not ship |

## 2. P0 risks — must close before pilot

| ID | Title | Owner | L | I | Score | Status | Mitigation |
|---|---|---|---:|---:|---:|---|---|
| P0-1 | App container runs as root; any process vuln = root in container | devops | 3 | 5 | 15 | OPEN | Add `USER www-data` directive in Dockerfile runtime stage |
| P0-2 | `.env.example` baked into image layer; `DB_PASSWORD=secret` visible via `docker history` | devops | 4 | 5 | 20 | OPEN | Drop `cp .env.example .env` from build; runtime secret injection |
| P0-3 | Redis no `--maxmemory` / no `--maxmemory-policy`; OOM under load | devops | 4 | 4 | 16 | OPEN | Set `--maxmemory 512mb --maxmemory-policy allkeys-lru` |
| P0-4 | `EmployeeController::index/show` returns raw model JSON; leaks 22 PII fields (national_id, iqama_number, birth_date, address, emergency contacts) to any HR_VIEW actor | backend | 5 | 5 | 25 | OPEN | Replace with `EmployeeResource` honoring `$hidden` / `whenLoaded` / `gateSensitiveProfile` parity with `UserResource` |
| P0-5 | `employee_personal_info` PII fields (national_id, iqama_number, address, emergency_phone, birth_date) are plaintext indexed; backup theft = full PII exfiltration | backend | 4 | 5 | 20 | OPEN | Add `'encrypted'` casts to `EmployeePersonalInfo` |
| P0-6 | 28 unpushed commits on local `main`; CI bypassed for entire "independent admin application" stream; 3 `wip:` commits on shipping branch | eng-lead | 5 | 4 | 20 | OPEN | Move to `feat/independent-admin`, push, open PR, get reviewed merge |
| P0-7 | Production deploy pipeline red (last run failed at `Run Tests`); daily backup failed on Jul 11 + Jul 12; no restore drill ever executed | devops | 5 | 5 | 25 | OPEN | Fix deploy.yml::test to use two-stage flake isolation; add WAL archiving + automated restore drill workflow |

## 3. P1 risks — fix before GA; mitigate during pilot

| ID | Title | Owner | L | I | Score | Status | Mitigation |
|---|---|---|---:|---:|---:|---|---|
| P1-1 | `throttle:uploads` and `throttle:delete` limiters defined but never applied to any route | backend | 4 | 3 | 12 | OPEN | Append to all upload + delete routes |
| P1-2 | Idempotency middleware absent on Meetings + Shared + OVR + Strategy + Surveys + Performance + HR mutations | backend | 4 | 3 | 12 | OPEN | Add `idempotency` middleware to all sensitive mutations |
| P1-3 | `dangerouslySetInnerHTML` in `ProjectCharter.tsx:61` (currently hardcoded CSS only) | frontend | 2 | 3 | 6 | OPEN | Replace with `className` + print stylesheet |
| P1-4 | `bootstrap/app.php:143` logs `$e->getSql()` with bind values; PII in `national_id`/`email` queries persists in `storage/logs/` | backend | 4 | 4 | 16 | OPEN | Strip bind values; hash or remove |
| P1-5 | `ActivityLog::created` stores plaintext model `toArray()` on update; reads are scrubbed but the stored row still contains PII | backend | 4 | 4 | 16 | OPEN | Honor `$sensitiveFields` in `LogsActivity`; run `redact()` on write |
| P1-6 | No metrics exporter (Prometheus / OpenTelemetry); Sentry is the only post-collection destination | devops | 3 | 3 | 9 | OPEN | Install exporter before pilot OR use Dokploy built-in metrics |
| P1-7 | `survey_responses.respondent_organization_id` UNINDEXED — full scan on Phase 3 cluster aggregates | database | 4 | 3 | 12 | OPEN | Add partial composite index `survey_responses_respondent_org_idx` |
| P1-8 | `Organization::parent_id` schema lacks deferred multi-hop cycle trigger (only direct self-reference CHECK); multi-hop cycles rely on application BFS guards | database | 2 | 4 | 8 | OPEN | Add deferred trigger or recursive CTE guard |
| P1-9 | CFA-07 (Users) HARD STOPPED — HIGH PII risk, CI failed twice; cluster widening design not approved | sponsor + eng-lead | 4 | 5 | 20 | OPEN | Sponsor redesign OR exclude from pilot scope |
| P1-10 | CFA-11 (ActivityLog) HARD STOPPED — CI failed twice; cluster_auditor widening design not approved | sponsor + eng-lead | 4 | 4 | 16 | OPEN | Sponsor redesign OR exclude from pilot scope |
| P1-11 | CFA-08 / 09 / 10 STOPPED for review with CI green | sponsor | 3 | 3 | 9 | OPEN | Sponsor review + ADRs; resolve before pilot scope |
| P1-12 | Cluster Full Authority contract change (2026-07-09) is undocumented in repo | docs-writer + sponsor | 4 | 4 | 16 | OPEN | Write ADR-0001; move memory file into `docs/adr/` |
| P1-13 | PRs merged despite red CI; "STOP regardless of CI" titles (CFA-07/11 + others) | eng-lead + sponsor | 4 | 4 | 16 | OPEN | Branch protection + review policy |
| P1-14 | `engine_capability:` middleware only on Core/HR/OVR/Surveys; Projects/Meetings/Strategy/Performance/Tasks rely on FormRequest + Policy only | backend | 3 | 3 | 9 | OPEN | Add `engine_capability:` to all `Routes/api.php` mutation groups |
| P1-15 | Frontend: 0 `React.memo` in 318 .tsx files; `DataTable` re-renders full row set on every keystroke | frontend | 4 | 3 | 12 | OPEN | Memoize `DataTable`, `Pagination`, `Avatar`, `StatusBadge`, `EmptyState` |
| P1-16 | Frontend: 0 of 8 `<img>` tags use `loading="lazy"`; no `decoding="async"` | frontend | 4 | 2 | 8 | OPEN | Add image lazy + async decode + width/height |
| P1-17 | No restore drill; backup is not verified restorable | devops | 4 | 5 | 20 | OPEN | Add weekly restore drill workflow |
| P1-18 | No storage backup (attachments, exports) | devops | 4 | 4 | 16 | OPEN | Add `backup-storage.yml` + S3 lifecycle |
| P1-19 | Cache invalidation gap: `dashboard_stats` cache flushed only on `TaskObserver` / `ProjectObserver`; Milestone/Comment/Risk/OVR changes leave stale counts 5 min | backend | 4 | 2 | 8 | OPEN | Use `Cache::tags(['dashboard_stats'])->flush()` in all observers |
| P1-20 | `dashboard_stats` cache `key` includes `start_/end_` from request, but the cache TTL is 5 min so the cache flush on every observer invocation is the safety net | backend | 3 | 2 | 6 | OPEN | Add 5-min sliding-window or compute on demand |
| P1-21 | Queue topology — all 21 notifications + DataImport (sync) on `default` queue; numprocs=1 (Dockerfile does not set numprocs, contrary to CLAUDE.md claim of 2); slow SMTP blocks all queued work | devops | 4 | 3 | 12 | OPEN | Bump numprocs to 2 explicitly; add dedicated `notifications` queue |
| P1-22 | 4 asymmetric interpolation keys (`projects.delete_success`, `projects.step_of`, `projects.stats_over_budget`, `users.delete_confirm`) — silently broken in en | frontend | 4 | 2 | 8 | OPEN | Add `{{var}}` placeholders to en values |
| P1-23 | 0 Arabic pluralization variants — only `_other` everywhere | frontend + i18n | 3 | 3 | 9 | OPEN | Add `_zero`, `_one`, `_two`, `_few`, `_many`, `_other` |
| P1-24 | `shared/lib/utils.ts` hardcodes `'en-GB'` for all date/number formatters; Arabic users see British-formatted dates | frontend | 4 | 3 | 12 | OPEN | Read `i18n.language` in helpers; use `Intl.DateTimeFormat(locale)` |
| P1-25 | Engine `AccessDecision::whyCan()` denial path writes no audit row; only Spatie middleware logs to ActivityLog | sponsor + backend | 3 | 3 | 9 | OPEN | Add `static::recordDeny()` in `org_isolation_denied` + `none` branches |
| P1-26 | `deploy.yml::deploy` health check silently passes on failure (exits 0) | devops | 3 | 5 | 15 | OPEN | Fix to `exit 1` on persistent failure |
| P1-27 | `deploy.yml::test` does not use two-stage flake isolation; flake can fail prod deploy | devops | 4 | 3 | 12 | OPEN | Reuse `ci.yml::test` via reusable workflow |
| P1-28 | Survey raw-response export writes PII-laden CSV/JSON to `storage/app/exports/` with no download route + no anonymous-mode masking | backend | 4 | 4 | 16 | OPEN | Move to direct download via `response()->streamDownload()` (Phase 3B pattern) + apply privacy mask |
| P1-29 | `Program.scopeOrganizationId()` reads `$this->organization_id` first but the column is not in `$fillable` and not visible in migration history; relies on Portfolio fallback | backend + database | 2 | 3 | 6 | OPEN | Verify column exists; add migration if missing; align fillable |
| P1-30 | `Tasks.organization_id` column exists (Phase 2A) but NOT in `$fillable` — set by repository/observer, not by mass assignment | backend | 2 | 3 | 6 | OPEN | Either add to fillable + remove observer override, OR document the invariant |
| P1-31 | `ProjectResource` leaks `organization_id` + `created_by` on cluster reads (self-flagged TODO) | backend | 3 | 3 | 9 | OPEN | Add to `$hidden` when `$isClusterRead` true |
| P1-32 | Cross-org isolation test coverage: 56/137 Feature tests (41%); RiskManagement 0, Performance 0, Strategy 0 | test-engineer | 4 | 3 | 12 | OPEN | Add isolation tests for the three modules |
| P1-33 | `sanctum.php` hard-coded stateful fallback includes dev hosts (`localhost:5173`, `::1`) | devops | 2 | 3 | 6 | OPEN | Enforce production override explicitly |
| P1-34 | `vite` service has no healthcheck in docker-compose.yml | devops | 2 | 2 | 4 | OPEN | Add healthcheck (informational only) |
| P1-35 | Dockerfile floating base image tags (`node:20-alpine`, `php:8.4-fpm`) — no SHA256 digest | devops | 3 | 4 | 12 | OPEN | Pin to digests (Dockerfile.admin already does) |

## 4. P2 risks — improve over time

| ID | Title | Owner | L | I | Score | Status | Mitigation |
|---|---|---|---:|---:|---:|---|---|
| P2-1 | `sanitizeInput` middleware strips HTML on every API request; could break future user-content fields | backend | 2 | 2 | 4 | ACCEPTED | Document; test with legit HTML if added |
| P2-2 | 33 page files use raw Tailwind color utilities bypassing `--status-*` tokens | frontend | 4 | 2 | 8 | OPEN | ESLint `no-restricted-syntax` rule; `@layer components` map |
| P2-3 | 9 admin pages implement raw `<table>` instead of `DataTable` | frontend | 4 | 2 | 8 | OPEN | Migrate to `DataTable` |
| P2-4 | 225 files import directly from `@tabler/icons-react` (bypassing curated barrel) | frontend | 4 | 2 | 8 | OPEN | ESLint `no-restricted-imports` for the icons module |
| P2-5 | `ErrorBoundary` imported only in `app.tsx`; no per-page error boundary | frontend | 3 | 2 | 6 | OPEN | Add per-`<Suspense>` ErrorBoundary |
| P2-6 | Route change focus management missing (AppLayout/AdminLayout `<main>` lacks `id`/`tabIndex`/`ref`) | frontend | 4 | 3 | 12 | OPEN | Add focus management |
| P2-7 | Legacy `Dropdown.tsx` lacks keyboard handling (ArrowDown, ArrowUp/Down, Escape) | frontend | 3 | 3 | 9 | OPEN | Add WAI-ARIA APG combobox/listbox pattern |
| P2-8 | `SkipToMain` rendered only on 6 OVR pages (should be in AppLayout) | frontend | 3 | 2 | 6 | OPEN | Move to layout |
| P2-9 | CLAUDE.md / AGENTS.md are byte-near-duplicates (264 lines each) | docs-writer | 2 | 1 | 2 | ACCEPTED | Keep one or split audience |
| P2-10 | CLAUDE.md says "Redis 7 for cache/queue/session"; defaults in `config/{cache,queue}.php` are `database`; `.env.example` ships `CACHE_STORE=redis` and `SESSION_DRIVER=database` | devops | 4 | 2 | 8 | OPEN | Write ADR-0004 resolving the contradiction |
| P2-11 | CLAUDE.md says "11 modules"; `ls app/Modules/` shows 12 directories (Shared is infra) | docs-writer | 2 | 1 | 2 | ACCEPTED | Wording fix |
| P2-12 | 4 missing `lang/ar/*.php` files (`auth.php`, `pagination.php`, `passwords.php`); Arabic users see English validation-rule text | backend | 4 | 2 | 8 | OPEN | Mirror from `lang/en/` |
| P2-13 | Frontend bundle: `@tabler/icons-react` eagerly bundled in `ui` chunk (4 000 icons) | frontend | 4 | 2 | 8 | OPEN | Move to per-page named imports with `optimizeDeps.exclude` |
| P2-14 | Cluster scope memoization gap — each widening scope fires 2 queries per request | backend | 3 | 2 | 6 | OPEN | Add request-scoped memoization on `Organization::descendantIds()` |
| P2-15 | N+1 hotspots in `StrategyDashboardController::index`, `PortfolioController::index`, `DashboardStatisticsService::calculateDepartmentsPerformance` + `calculateMonthlyTrends` (18 COUNT queries) | backend | 4 | 3 | 12 | OPEN | Consolidate queries; use `withCount`/`selectRaw` |
| P2-16 | `getProjectsByStatus` recomputes per request; `ProjectSetting` cached but no `Cache::tags` | backend | 3 | 2 | 6 | OPEN | Use `Cache::tags` for grouped invalidation |
| P2-17 | Dead husky wiring (`"prepare": "husky"` with no `.husky/`) | devops | 2 | 1 | 2 | ACCEPTED | Either implement real pre-commit or remove dead script |
| P2-18 | `npm run design:check` not run in CI; design-token guard never fires on PR | devops | 4 | 2 | 8 | OPEN | Add to `ci.yml::quality` |
| P2-19 | `composer ci` (check-task-model + check-residual-hardening + check-cluster-tree-contract) not run in CI as a unit | devops | 3 | 2 | 6 | OPEN | Add to `ci.yml::quality` |
| P2-20 | E2E `continue-on-error: true` on main quality job | devops | 3 | 3 | 9 | OPEN | Drop once GHA runner green run confirmed |
| P2-21 | No fork-PR protection; secrets gated by GitHub but default token has write perms in `ci.yml` / `deploy.yml` | devops | 3 | 3 | 9 | OPEN | Add `permissions: contents: read` and `if: head.repo == repository` |
| P2-22 | No `timeout-minutes` on any job | devops | 3 | 2 | 6 | OPEN | Set `timeout-minutes: 15` on test, 30 on migrate/deploy |
| P2-23 | No vite build cache, no Docker cache in CI | devops | 3 | 2 | 6 | OPEN | Add `actions/cache@v4` for `node_modules/.vite` |
| P2-24 | No concurrency block on `deploy.yml` | devops | 3 | 2 | 6 | OPEN | Add `concurrency:` keyed by `github.ref` |
| P2-25 | Strict dup routes: `/api/organizations/*` declared under both standard and `/admin/*` paths (Core/Routes/api.php:202 vs :235) | backend | 2 | 3 | 6 | OPEN | Consolidate or document deprecation |
| P2-26 | Verb-in-path state transitions (`/recommendations/{id}/accept|defer|complete|reject|approve`, etc.) — non-RESTful | backend | 3 | 2 | 6 | OPEN | Document; introduce v2 with PATCH+body{action} |
| P2-27 | Status code inconsistency on POST/DELETE: Tasks, Projects, Strategy, Surveys, Risk return `200 + Resource` on store/destroy (should be 201/204) | backend | 4 | 2 | 8 | OPEN | Add explicit `JsonResponse::HTTP_CREATED` / `HTTP_NO_CONTENT` |
| P2-28 | Timestamp format inconsistency: 4 different serializers for `created_at` across 9 resources | backend | 4 | 1 | 4 | OPEN | Standardize on `toIso8601String()` |
| P2-29 | No full-text search (`tsvector`) — `IncidentReportController::index` does `orWhere('incident_description', 'like', '%...%')` on TEXT | database | 3 | 2 | 6 | OPEN | Add GIN trigram or `tsvector` column |
| P2-30 | Worktrees in use despite retirement (`.worktrees/admin-task9-resume`, `.worktrees/authorization-full-cutover`) | eng-lead | 3 | 1 | 3 | OPEN | Prune; clean up |
| P2-31 | 28 commits on local `main` not pushed; LR-008 risk of concurrent-session clobber | eng-lead | 5 | 4 | 20 | OPEN (same as P0-6) | Push + PR |
| P2-32 | No CHANGELOG.md; release notes live in PR bodies | docs-writer | 3 | 2 | 6 | OPEN | Adopt `cliff` or hand-curated CHANGELOG |
| P2-33 | `requirements` from `dashboard_stats` cache TTL is 5 min; cache miss + recompute burst can stampede | backend | 3 | 3 | 9 | OPEN | Cache stampede mitigation (lock + jittered TTL) |
| P2-34 | Frontend bundle missing `dist/` (no `npm run build` ever executed); cannot measure production bundle size | frontend | 3 | 2 | 6 | OPEN | Run `npm run build` + `npm run analyze` |
| P2-35 | No slow-query logging; DB regressions only surface via user's 500 | backend | 3 | 3 | 9 | OPEN | Add `DB::listen` with threshold + Sentry breadcrumb |
| P2-36 | `DataImportRequest`, `DataMappingTemplate`, `SurveyAnswerFile`, `SurveyField`, `SurveyFieldAnswer`, `SurveySection`, `SurveyVersion` derive org via Survey chain — no dedicated scope class | backend | 2 | 3 | 6 | OPEN | Verify scope coverage; add tests |

## 5. Active issues (in-flight engineering work)

| ID | Title | Owner | Created | Status | Notes |
|---|---|---|---|---|---|
| I-01 | Meetings uncommitted WIP (16 files, +440 lines) including MeetingResolutionController + 4 untracked test files | eng-lead | 2026-07-10 | OPEN | Pre-pilot cleanup |
| I-02 | 28 unpushed commits on local `main` | eng-lead | 2026-07-08 | OPEN | Same as P0-6 / P2-31 |
| I-03 | Stale branch `phase-9d-d1b-strategy-cluster-tree-read` (upstream gone) | eng-lead | 2026-07-09 | OPEN | Prune |
| I-04 | Local `codex/*` branches with no remote / no PR | eng-lead | 2026-07-08 | OPEN | Prune |
| I-05 | `worktrees/*` directories in active use despite retirement | eng-lead | 2026-07-10 | OPEN | Prune |
| I-06 | `composer.lock` not in version control for some deploy contexts | devops | 2026-07-09 | OPEN | Verify lock committed |
| I-07 | `php artisan migrate:status` shows 220 applied migrations on dev DB; some are blocked by `scripts/migration-safety-preflight.sh` — fresh-install deadlock risk | devops | 2026-07-12 | OPEN | Verify playbook supports fresh install |
| I-08 | `survey_responses.respondent_organization_id` UNINDEXED | database | 2026-07-12 | OPEN | Same as P1-7 |

## 6. Decisions log

| Date | Decision | Rationale | Decided by | ADR |
|---|---|---|---|---|
| 2026-07-09 | **Cluster Full Authority** — cluster has full authority over descendants (read + manage + export) | Business model pivot; supersedes 9-D-D read-only framing | Tariq | ADR-0001 pending |
| 2026-07-08 | Use `Storage::disk('local')` for survey raw exports, then serve via direct download (Phase 3B) | Avoid orphan PII files; direct download is safer than disk-served files | Tariq + eng | ADR pending |
| 2026-07-08 | `respondent_organization_id` snapshot pattern for SurveyResponse | Avoid cluster aggregate drift via users.organization_id changes | Tariq + eng | ADR pending |
| 2026-07-08 | CI-1 fix: pre-create `storage/framework/{views,cache,sessions}` BEFORE composer install | Fixes fresh-checkout CI failure (composer post-autoload-dump → package:discover → realpath on missing storage) | Tariq | (implementation note) |
| 2026-07-07 | Single Repository (EloquentTaskRepository) for Tasks; all other modules talk to Eloquent directly | Consistency over abstraction | Tariq | (CLAUDE.md convention) |
| 2026-07-06 | Delete `PROJECTS_MANAGE_MEMBERS` capability; unify into `PROJECTS_ASSIGN_ROLES` | Capability consolidation | Tariq | (CHANGELOG) |
| 2026-07-04 | Backfill `inherit_to_children` semantics into scoped roles | Pre-CFA cluster enablement | Tariq | (migration) |
| 2026-06-30 | Remove legacy `/api/tasks/*`; replace with `/api/unified-tasks/*` | Consolidate after cluster widening | Tariq | (route migration) |
| 2026-06-28 | 4 migrations are blocked: `patient_file_number`, `drop_decisions`, `drop_legacy_kpi`, `drop_legacy_department_role` | Idempotency + safety | Tariq | ADR pending (docs/migrations-remediation-playbook.md) |
| 2026-06-19 | Drop legacy KPI tables (`strategic_kpis`, `strategic_kpi_measurements`, `project_kpis`) | Pre-CFA schema cleanup | Tariq | (migration) |
| 2026-06-14 | Migrate `project_members` → scoped roles | Cluster-friendly permission model | Tariq | (migration) |
| 2026-01-21 | Sanctum SPA stateful cookie auth | SPA-friendly + CSRF-safe | Tariq | (config) |
| 2025-12-31 | Remove `organization_id` from `projects` | Pre-rework; later re-added 2026-06-08 | Tariq | (migration; flagged P0-6 risk — drop+re-add pattern) |
| Earlier | Form-request `authorize()` is the authz seam, not controllers | Audit visibility; single IDOR surface | Tariq | CLAUDE.md convention |

## 7. Open design questions (sponsor's queue)

| Q | Question | Owner | Blocks |
|---|---|---|---|
| Q1 | Is `CLUSTER_TREE_MANAGE` = read+write or write only? | sponsor | CFA-07/08/09/10/11 |
| Q2 | KPIS_VIEW vs KPIS_EXPORT — why two primitives? | sponsor | CFA-08 |
| Q3 | Hybrid engine cutover order — which 6 un-migrated modules first? | sponsor + eng-lead | Band C-2 |
| Q4 | Does `Program.organization_id` column actually exist? | backend | Band G-3 |
| Q5 | Should `dangerouslySetInnerHTML` in `ProjectCharter.tsx` be removed entirely? | frontend | Band E |
| Q6 | Should engine `AccessDecision::whyCan()` denial writes be added? | sponsor | Band D |
| Q7 | Pilot scope — single-tenant test, multi-tenant, or cluster? | sponsor | Report 4 |
| Q8 | Restore drill cadence — weekly or monthly? | devops + sponsor | Band H-7 |
| Q9 | Should `lang/ar/auth.php` / `pagination.php` / `passwords.php` be created? | backend | Band F-9 |
| Q10 | Should pilot users be allowed to mutate cross-org CRUD? | sponsor | Report 4 §2 |
| Q11 | Is the audit log table sufficient for the `engine deny` rows (AuthorizationDecisionAudit) or do we need a new sink? | sponsor + backend | Band D |
| Q12 | Should `Survey` snapshots (respondent_organization_id) be enforced on ALL survey types or only the authenticated-respondent path? | sponsor | Band B-4 (CFA-10) |

## 8. Risk acceptance register (template)

When a P0/P1 risk is formally accepted for pilot duration, record here:

```
RISK ACCEPTANCE — ID: ___
Risk title: ___
Owner: ___
Description: ___
Probability × Impact: ___
Mitigation: ___
Residual risk: ___
Compensating controls: ___
Acceptance duration: ___ to ___
Sponsor signature: ___ Date: ___
```

(Empty at v1. Filled as needed during pilot prep.)

## 9. Risk trend

| Week | P0 open | P1 open | P2 open | Notes |
|---|---:|---:|---:|---|
| 2026-07-12 (now) | 7 | 35 | 36 | Initial baseline |
| 2026-07-19 (target) | 0 | 25 | 30 | Band A1+A2+A3+A8 closed |
| 2026-07-26 (target) | 0 | 18 | 25 | Band A4+A5+A6+A7 closed |
| 2026-08-02 (target) | 0 | 10 | 18 | Band B CFA reviews + Band C ADRs |
| 2026-08-09 (target) | 0 | 5 | 12 | Band A9+A10 + Band D wiring |
| 2026-08-15 (target) | 0 | 3 | 8 | All Bands A-D closed; pilot GO |

(Actual trend to be filled at each update.)