# Report 3 — Roadmap and Remaining Work

**Document type:** Forward-looking delivery plan
**Audience:** Sponsors, engineering leads, pilot owners
**As-of date:** 2026-07-12
**Window covered:** Jul 2026 → end of pilot readiness window

---

## 1. Where we are

The system has progressed through 6 phase bands in 5 days (Jul 7–11):
- **Phase 9-A/B/C/D-A/D-B (organization hierarchy)** — merged via PR #2..#4 (5 files / +1134, 11 files / +970, 5 files / +519)
- **CFA-01..06 (cluster widening core)** — 6 stories merged, 440+ tests added
- **CFA-08/09/10 (Tasks / OVR / Surveys widening)** — STOP for sponsor review with CI green (PRs #19, #20, #21)
- **CFA-07/11 (Users / ActivityLog widening)** — HARD STOP after 2 CI failures (HIGH/CRITICAL riskLevel; PII for #07)
- **Stabilization Phase 0..5** — Tariq wiring verified; audit-log boundary; tasks cluster; surveys snapshot + direct download; FE alignment; role provisioning + migration safety
- **CI-1 fix** — pre-create storage/framework before composer install (fixes fresh-checkout CI)

**What is NOT in any of those bands:**
- The Cluster Full Authority contract change is **undocumented** (memory-only).
- 5 P0 production-readiness gaps (Docker user, secrets, Redis config, PII in controller, backup drill).
- Process discipline: 28 unpushed commits, 3 `wip:` commits on `main`, red pipelines.

## 2. Roadmap bands

### Band A — Production-readiness hardening (BEFORE pilot)

These must close before pilot launch. Each item has a single PR with its own review; merge gate = green CI + sponsor sign-off.

| # | Item | Owner type | Effort | Critical path? |
|---|---|---|---|---|
| A-1 | Move 28 unpushed commits + 64 uncommitted files to `feat/independent-admin`, push, open PR, get reviewed merge | eng-lead | S | YES — nothing else proceeds until `main` is clean |
| A-2 | Docker: add `USER www-data`, drop `cp .env.example .env` from build, add runtime-only secret injection | devops | M | YES |
| A-3 | Redis: `--maxmemory 512mb --maxmemory-policy allkeys-lru` (or `volatile-lru` if sessions must persist) | devops | XS | YES |
| A-4 | Replace `EmployeeController::index/show` raw JSON with `EmployeeResource` honoring `$hidden` / `whenLoaded` / `gateSensitiveProfile` parity with `UserResource` | backend | M | YES |
| A-5 | Add `'encrypted'` casts to `EmployeePersonalInfo` (`national_id`, `iqama_number`, `address`, `emergency_phone`, `birth_date`) | backend | S | YES |
| A-6 | Strip bind values from `$e->getSql()` log line in `bootstrap/app.php:143` (hash or remove) | backend | XS | YES |
| A-7 | Strip plaintext PII from `ActivityLog` writes — honor `$sensitiveFields` in `LogsActivity` trait | backend | M | YES |
| A-8 | Enable `main` branch protection: require PR + green `test`/`quality`/`pdpl` + ≥1 review + linear history | devops | XS | YES |
| A-9 | Fix production deploy pipeline + daily backup (currently red) | devops | M | YES |
| A-10 | Add WAL archiving + base backup + automated restore drill workflow | devops | L | YES |

Estimated Band A: ~3 weeks of focused engineering (sequential dependencies).

### Band B — CFA review items (post-Band A, BEFORE pilot data collection)

Three CFA stories are STOPped for sponsor review. Resolution determines whether the system can exercise the affected flows under pilot.

| # | Item | Owner | Status |
|---|---|---|---|
| B-1 | **CFA-07 Users cluster widening** (HIGH PII risk; CI failed twice). HARD STOP — needs design re-spec, ADR-CFA-01, second reviewer | sponsor + eng-lead | NEITHER START NOR MERGE until redesigned; preserve HIGH-risk policy |
| B-2 | **CFA-08 Tasks cluster widening** (open design question: `KPIS_VIEW` vs `KPIS_EXPORT` semantics; OVR-confidential floor; polymorphic source chain) | sponsor + eng-lead | STOP for review (PR #19); decide on dual-pair semantics, then implement widening test surface |
| B-3 | **CFA-09 OVR cluster widening** (aggregate only; CFA-09 contract preserved) | sponsor | STOP for review (PR #20); narrow to aggregate widening per design |
| B-4 | **CFA-10 Surveys cluster widening** (aggregate only; raw responses stay strict same-org) | sponsor | STOP for review (PR #21); confirm aggregate-only constraint and unblock |
| B-5 | **CFA-11 ActivityLog cluster widening** (CI failed twice; cluster_auditor widening on read + export) | sponsor + eng-lead | HARD STOP — same as CFA-07; review dual-serializer for completeness, re-spec, then re-implement |

Estimated Band B: ~1 week after design sign-off, per story (4 reviews × 1 week = 4 weeks, parallelizable to 2 weeks with one engineer per review).

### Band C — Engine / authz documentation (parallel to Band B)

| # | Item | Owner | Effort |
|---|---|---|---|
| C-1 | Write **ADR-0001 Cluster Full Authority contract** (supersedes 9-D-D read-only framing). Open question: is `MANAGE` write or read+write? What is "full"? Where does it stop? | docs-writer + sponsor | S |
| C-2 | Write **ADR-0002 AccessDecision + Spatie hybrid** — which modules remain on Spatie `hasPermissionTo`, migration order, rollback strategy | docs-writer + sponsor | S |
| C-3 | Write **ADR-0003 Single-queue topology** — forbids `->onQueue()`, codifies worker count, documents KPI-import-vs-queue decision | docs-writer | S |
| C-4 | Write **ADR-0004 Redis-as-default vs database-as-default** — resolves the contradiction between CLAUDE.md, `.env.example`, and `config/*.php` defaults | docs-writer | XS |
| C-5 | Write **ADR-0005 Capability naming + provider taxonomy** — canonical per-module provider layout, migration path for Performance/Strategy/Tasks which currently lack `*CapabilityProvider` | docs-writer | S |
| C-6 | Write **ADR-0006 FSD cutover policy** — when does a legacy `pages/*.tsx` migrate; ESLint-boundary enforcement rationale | frontend-engineer | S |
| C-7 | Resolve open design questions inventory (per audit): KPIS_VIEW vs KPIS_EXPORT, cluster write semantics, hybrid engine cutover | sponsor | M |
| C-8 | Move agent-private "Cluster Full Authority contract change" memory file into `docs/adr/` as ADR-0001 | docs-writer | XS |

### Band D — Quality and observability (parallel to Bands B+C)

| # | Item | Owner | Effort |
|---|---|---|---|
| D-1 | Wire `npm run design:check` + `composer ci` (check-task-model.php + check-cluster-tree-contract.sh) into `ci.yml::quality` | devops | S |
| D-2 | Wire E2E (drop `continue-on-error: true` once a green GHA runner run is observed) | devops + test-engineer | S |
| D-3 | Add `permissions: contents: read` at workflow level + fork-PR gate | devops | XS |
| D-4 | Convert `deploy.yml::test` to reuse `ci.yml::test` (reusable workflow) and fail-on-deploy-health-check (currently silent-success) | devops | M |
| D-5 | Set `timeout-minutes` on all CI jobs (currently default 360 min — masks hangs) | devops | XS |
| D-6 | Add concurrency block on `deploy.yml` keyed by `github.ref` (cancel overlapping deploys) | devops | XS |
| D-7 | Promote `docs/authz/` to `docs/adr/` with renumbered files (0001..0003) and add 0004-0006 from C-1..C-6 | docs-writer | S |
| D-8 | Add Prometheus metrics exporter (or OpenTelemetry) — endpoint latency, queue depth, login failure rate, job retry budget | backend + devops | L |
| D-9 | Wire `LOG_STACK=daily,sentry` as the production default + `LOG_DAILY_DAYS=30` | devops | XS |
| D-10 | Add `Sentry\configureScope` enrichment: `tags: { request_id, organization_id, environment, release }`, `user: { id }` | backend | S |
| D-11 | Add Sentry sourcemap upload + release tagging in CI (`@sentry/vite-plugin` + `sentry-cli releases new`) | devops + frontend | S |
| D-12 | Fix `deploy.yml::deploy` silent health-check pass | devops | XS |

### Band E — Performance and DX (parallel)

| # | Item | Owner | Effort |
|---|---|---|---|
| E-1 | Memoize `DataTable`, `Pagination`, `Avatar`, `StatusBadge`, `EmptyState` with `React.memo` | frontend | S |
| E-2 | Add `loading="lazy"` + `decoding="async"` to all non-LCP `<img>`; add explicit `width`/`height` for CLS | frontend | S |
| E-3 | Drop `axios` from `package.json`; remove `bootstrap.js` (unloaded); remove `errorHandler.parseApiError` (dead axios path) | frontend | XS |
| E-4 | Add cancellation (`AbortController`) + default timeout to `ApiClient` | frontend | S |
| E-5 | Consolidate `ApiError` types (delete the duplicate in `shared/lib/errorHandler.ts`) | frontend | XS |
| E-6 | Drop `app.tsx` inner `ErrorBoundary` (outer already covers) | frontend | XS |
| E-7 | Add `engine_capability:` middleware to Projects/Meetings/Strategy/Performance/Tasks `Routes/api.php` (currently Core/HR/OVR/Surveys only) | backend | S |
| E-8 | Apply `throttle:uploads` to all upload routes + `throttle:delete` to all delete routes | backend | XS |
| E-9 | Apply `idempotency` middleware to all sensitive mutations in Meetings + Shared + OVR + Strategy + Surveys + Performance + HR | backend | S |
| E-10 | Cache coverage expansion: `ProjectSetting`, `ScopeType`, `ScopedRoleDefinition` lookups already cached; expand to `Project`, `Kpi`, `Risk` index endpoints with `Cache::tags` + invalidate via observers | backend | M |
| E-11 | Tag-flush `dashboard_stats` cache via observers (currently Milestone/Comment/Risk/OVR changes leave stale counts 5 min) | backend | S |
| E-12 | Drop the inner `ErrorBoundary` + add per-page `ErrorBoundary` around lazy-loaded routes | frontend | S |
| E-13 | Split `@tabler/icons-react` out of `ui` chunk via per-page named imports (with `optimizeDeps.exclude` for icons) | frontend | S |
| E-14 | Migrate 9 files with raw `<table>` to `DataTable` (admin pages) | frontend | M |
| E-15 | Replace 225 files' direct `@tabler/icons-react` imports with `@shared/ui/icons` (ESLint `no-restricted-imports`) | frontend | M |
| E-16 | Move `tasks.chart`/`charts` heavy composites behind `React.lazy` | frontend | S |

### Band F — Frontend i18n / a11y polish (parallel)

| # | Item | Owner | Effort |
|---|---|---|---|
| F-1 | Thread `i18n.language` through `shared/lib/utils.ts` formatters; replace ad-hoc `'ar-EG-u-nu-latn'` / no-arg call sites | frontend | S |
| F-2 | Add Arabic plural variants (`_zero`, `_one`, `_two`, `_few`, `_many`, `_other`) for every `count`-bearing key | frontend + i18n | M |
| F-3 | Enable `saveMissing: import.meta.env.DEV` + `missingKeyHandler` in i18n config | frontend | XS |
| F-4 | Fix the 4 asymmetric interpolation keys (`projects.delete_success`, `projects.step_of`, `projects.stats_over_budget`, `users.delete_confirm`) | frontend | XS |
| F-5 | Localize `DatePicker` month/day names via `Intl.DateTimeFormat` (not hardcoded arrays) | frontend | S |
| F-6 | Add `<main id="main-content" tabIndex={-1} ref>` + `useEffect([location.pathname])` to focus new content on route change | frontend | S |
| F-7 | Add keyboard handling to `Dropdown.tsx` (ArrowDown to open, ArrowUp/Down/Home/End in listbox, Escape to close) | frontend | S |
| F-8 | Move `SkipToMain` to AppLayout/AdminLayout (currently 6 OVR pages only) | frontend | XS |
| F-9 | Mirror `lang/ar/auth.php`, `pagination.php`, `passwords.php`; align `validation.php` attributes map | backend | S |
| F-10 | Add `aria-label` to `Select` search input | frontend | XS |
| F-11 | Remove dead `<label htmlFor aria-hidden>` next to Toast close button | frontend | XS |

### Band G — Database and migrations

| # | Item | Owner | Effort |
|---|---|---|---|
| G-1 | Add `survey_responses_respondent_org_idx` partial composite index for cluster aggregate | database | XS |
| G-2 | Add deferred multi-hop cycle trigger on `organizations.parent_id` (or recursive CTE guard) | database | M |
| G-3 | Verify `Program.scopeOrganizationId()` reads a real `organization_id` column; if missing, add migration + scope fallback | database | S |
| G-4 | Add GIN trigram indexes for `ILIKE`/`like` search on `incident_description`, `survey description`, `project description` | database | M |
| G-5 | Decide on natural-language full-text (`tsvector GENERATED ALWAYS AS … STORED` + GIN) — needed for survey response text search | database | M |

### Band H — Process and governance

| # | Item | Owner | Effort |
|---|---|---|---|
| H-1 | Define and publish Definition of Done (i18n ar+en, RTL, 5-persona authz, empty/loading/error, a11y, responsive, CHANGELOG) | eng-lead | S |
| H-2 | Add PR template encoding the DoD checklist | eng-lead | XS |
| H-3 | Decide and codify: should PRs be merged-by-Ralph only after green CI on a non-flake run? | eng-lead + sponsor | XS |
| H-4 | Forbid merging HIGH/CRITICAL riskLevel stories (CFA-07, 08, 09, 10, 11) without green CI | eng-lead + sponsor | XS |
| H-5 | Split CFA / Phase PRs to <1,000 reviewable lines (current average 1,700; outliers 5k+) | eng-lead | S |
| H-6 | Prune stale local branches (worktrees, codex/*, gone upstream, etc.) | eng-lead | XS |
| H-7 | Add on-call rotation doc + restore drill schedule (monthly) | devops | M |

## 3. Sequencing

```
Week 1 (Jul 13-19): Band A items 1, 2, 3, 8 (DOCKER + branch protection + main cleanup)
Week 2 (Jul 20-26): Band A items 4, 5, 6, 7 (PII + log scrubbing)
Week 3 (Jul 27 - Aug 2): Band A items 9, 10 (deploy + backup)
Week 4 (Aug 3-9): Band B CFA-07/08/09/10/11 reviews; Band C ADRs; Band D CI wiring
Week 5 (Aug 10-16): Band E performance + DX; Band F i18n; Band G DB
Week 6 (Aug 17+): Pilot dry-run with synthetic data
```

This is one possible sequencing; the actual order is sponsor's call. Critical path is **A-1 (main cleanup) → A-4 + A-5 (PII) → A-9 + A-10 (deploy + backup) → pilot launch**.

## 4. Items NOT on the near-term roadmap (acknowledged)

- HR workflow expansion (recruitment, payroll, leaves)
- Real-time collaboration (WebSockets)
- Mobile clients
- External integrations beyond PDPL scanner
- `axios` removal E2E (some legacy code may still import it)
- Filament removal (audit reports 0 hits — done)
- Spatie `hasPermissionTo` elimination from un-migrated modules (User, SystemSettings, Meeting, Recommendation, SurveyResponse, Comment, Attachment — per audit) — only after Band B/C
- `git worktree` re-introduction for session isolation (retired per LR-008)
- Vite build cache + Docker cache in CI
- WebSocket broadcasts
- English-Indic numeral rendering via `Intl` per-locale customization

These are tracked in Report 2 (out of scope) and may enter future phases.

## 5. Open design questions (sponsor's queue)

These block Band B (CFA reviews) and Band C (ADRs):

| Q | Question | Owner | Blocker for |
|---|---|---|---|
| Q1 | Is Cluster Full Authority "manage" = read+write (governance) or only write? | sponsor | CFA-07/08/09/10/11 review |
| Q2 | KPIS_VIEW vs KPIS_EXPORT — why two? Reversal conditions? | sponsor | CFA-08 design |
| Q3 | Hybrid engine cutover order — which of the 6 un-migrated modules first? | sponsor + eng-lead | Band C-2 ADR |
| Q4 | `Program.organization_id` — does the column actually exist? | backend-eng | Band G-3 |
| Q5 | Should `dangerouslySetInnerHTML` in `ProjectCharter.tsx` be removed entirely (replaced with className + print stylesheet)? | frontend | Band E |
| Q6 | Should `audit.log` denial writes be added to engine path (currently only Spatie middleware logs)? | sponsor + backend | Band D |
| Q7 | Pilot scope — single-tenant test, multi-tenant test, or cluster test? | sponsor | Report 4 |
| Q8 | Restore drill cadence — weekly or monthly? | devops + sponsor | Band H-7 |

## 6. What success looks like at pilot kickoff

- All Band A items closed.
- All Band B items resolved (CFA reviews with sponsor sign-off).
- ADRs 0001-0006 published in `docs/adr/`.
- CI green on `main` for ≥5 consecutive days.
- Restore drill executed at least once successfully.
- Synthetic-data dry-run produces no P0 incidents over 48 hours.
- Sponsor sign-off recorded in Report 5 (Go/No-Go).