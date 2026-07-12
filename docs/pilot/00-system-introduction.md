# Report 0 — System Introduction and Executive Vision

**Document type:** Foundational reference
**Audience:** Executive sponsors, steering committee, pilot stakeholders, new contributors
**Status:** Living document (last revised 2026-07-12)
**Authors:** System analysis from a 35-agent audit sweep across the erada-platform monorepo

---

## 1. What this product is

**Erada PMO** is an institutional project-management office platform delivered as a modular monolith (Laravel 12 backend) plus a feature-sliced React 19 SPA. It supports the full lifecycle of strategic portfolios, programs, projects, tasks, KPIs, risks, OVR incidents, surveys, meetings, employees, and audit logging — across multi-tenant organizations with cluster-tree visibility.

The system is **Arabic-first** with RTL layout and English fallback, designed for ministries, hospitals, and centers that need hierarchical oversight across multiple sites.

## 2. Executive vision

> *Erada PMO is the single source of truth for institutional performance — from strategic intent (portfolio) down to operational task (PDCA), with multi-org oversight (cluster_tree) baked into the authorization model rather than bolted on.*

Three strategic commitments drive every architectural decision:

1. **Cluster Full Authority.** A cluster-level user has read AND managed governance over descendant organizations. This was the **2026-07-09 business-model pivot** that superseded the earlier read-only framing of cluster_tree widening. The implementation must keep up with this re-orientation; the authz engine is the single source of truth and all per-module widening must remain consistent with the CFA contract.
2. **Two-system authorization under one engine.** A custom `AccessDecision::can()` engine with tagged `CapabilityProvider`s coexists with legacy Spatie `hasPermissionTo` for un-migrated modules. Form-request `authorize()` is the canonical seam; controllers never authorize.
3. **Capability-driven cluster widening requires a dual pair.** Every cross-org read/write/export requires BOTH the module-level capability (e.g. `KPIS_VIEW`) AND a cluster primitive (`CLUSTER_TREE_VIEW` / `_MANAGE` / `_EXPORT`). Missing either ⇒ strict same-org (fail-closed).

## 3. System shape at a glance

| Layer | Tech | Notable |
|---|---|---|
| Backend | Laravel 12 / PHP 8.4 | Modular monolith under `app/Modules/{Core, HR, Meetings, OVR, Performance, Projects, RiskManagement, Shared, Strategy, Surveys, Tasks}/` |
| Frontend | React 19 + TS 5.7 + Vite 7 + Tailwind 4 | Feature-Sliced Design (app / pages / widgets / features / entities / shared); Arabic-first i18n via `lang/ar.json` master |
| Data | PostgreSQL 16 only | **SQLite forbidden** (CI guard); 217 migrations on disk; 75 Eloquent models |
| Cache / Queue | Redis 7 | Default driver `database`; Redis opt-in via env (documented contradiction with CLAUDE.md stack table) |
| Auth | Laravel Sanctum 4 | Stateful cookies for SPA + bearer tokens; CSRF via `EnsureCsrfForStateChangingApi` |
| RBAC | Spatie `laravel-permission` 6 (legacy) **+ custom `AccessDecision` engine** (active) | 122 capability constants; 8 `CapabilityProvider`s; 7 tagged and discovered by the engine |
| Tenancy | `organization_id` + hierarchical `departments` (`parent_id` + BFS walker) | Cluster Full Authority widens visibility to descendants only |
| Tests | PHPUnit 11 + Vitest 3 + Playwright 1.49 | CI runs PHPUnit + Vitest + Playwright + Pint + PHPStan + design:check |
| CI | GitHub Actions | 4 workflows: ci, deploy, backup, pdpl-scan. Two-stage PHPUnit flake re-run baked in |

## 4. Eleven modules, eleven responsibilities

| Module | Primary responsibility | Cluster widening status |
|---|---|---|
| **Core** | Auth, users, organizations, departments, scoped-roles, capability engine, activity log | YES — User directory widens for cluster admins only |
| **HR** | Employees, departments, certificates, personal info | **EXCLUDED** — PII floor; strict same-org |
| **Meetings** | Meetings, agenda, recommendations (Direction B), resolutions (Direction R), four-eyes | CFA-06 read widening; lifecycle stays strict |
| **OVR** | Incident reports, investigations, status history | Aggregate-only cluster widening (CFA-09); confidential floor |
| **Performance** | KPIs, measurements, links | CFA-01 / CFA-02 view + export widening |
| **Projects** | PMBOK charter, FOCUS-PDCA, members, expenses, milestones, risks | CFA-04 view + status-write widening; CRUD strict |
| **RiskManagement** | Risks, assessments, actions, alerts | CFA-05 widening |
| **Shared** | Comments, attachments, uploads, activity log, polymorphic sinks | CFA-11 cluster_auditor widening |
| **Strategy** | Portfolio, program, review, blocker | CFA-01b + CFA-03 view + manage widening |
| **Surveys** | Survey form, sections, fields, invitations, responses, data import | CFA-10 aggregate-only widening; raw responses strict |
| **Tasks** | Unified tasks endpoint, PDCA, polymorphic source chain | CFA-08 read + PDCA-write widening; OVR-confidential floor |

## 5. Cluster Tree (the load-bearing concept)

Cluster_tree is the cross-org visibility primitive. It walks `Organization::descendantIds()` via BFS over `parent_id` (depth cap 32, visited-set cycle guard, fail-closed on null). A user with the **dual pair** (module capability + cluster_tree primitive) sees descendants; without both, they see strict same-org.

The three cluster primitives:
- `CLUSTER_TREE_VIEW` — read across descendants
- `CLUSTER_TREE_MANAGE` — governance writes (status / PDCA / resolve) across descendants
- `CLUSTER_TREE_EXPORT` — aggregate exports across descendants

Verified widening scopes: KPI (9-D-D1a), Strategy (9-D-D1b), Projects (CFA-04), Risks (CFA-05), Meetings (CFA-06), ActivityLog (CFA-11), OVR aggregate (CFA-09 STOP for review), Surveys aggregate (CFA-10 STOP for review), Tasks (CFA-08 STOP for review), Users cluster directory (CFA-07 hard-stopped).

## 6. Multi-tenancy posture

- **Org-column coverage: 36 % of models have direct `organization_id`**; the remaining 64 % derive via parent relations (project_id, kpi_id, survey_id, etc.) or via the polymorphic `Task::source` / `Comment::commentable` chains.
- **Cluster boundary safety** is implicit in the tree shape (sibling clusters are not descendants of each other); there is no explicit `isCluster()` check in `descendantIds()`. Sibling-cluster isolation is asserted by tests in every widening module.
- **Public route** `/s/{code}` is a SPA shell only; the actual data endpoint `/api/surveys/public/{code}` is gated by `is_public` + `requires_auth` + `isActive()` + version hash + `throttle:survey-submit` and never returns internal ids or org names.
- **Sanctum has no org claim** — every API call re-evaluates the actor's `organization_id` at the policy/scope layer, so a stolen token cannot escape org isolation.

## 7. Recent milestones

- **Phase 9-A/B/C/D-A/D-B (2026-07-07)** — Organization hierarchy schema + admin UI + cluster_tree minimal engine primitive merged via PR #2..#4.
- **CFA-01 through CFA-06 (2026-07-09)** — Cluster widening for KPI / Strategy / Projects / Risks / Meetings / ActivityLog merged.
- **CFA-07 + CFA-11 (2026-07-09)** — HARD STOPPED for review (HIGH/CRITICAL riskLevel: Users widening, ActivityLog widening); CI failed twice.
- **CFA-08 / 09 / 10 (2026-07-10)** — STOPPED for Tariq review (Tasks / OVR / Surveys) with CI green.
- **Stabilization Phase 0..5 (2026-07-10..11)** — Tariq wiring verification, audit-log boundary, tasks cluster, surveys snapshot + direct download, FE alignment, role provisioning + migration safety.
- **CI-1 fix (2026-07-08)** — Pre-create `storage/framework/{views,cache,sessions}` before composer install; fixes the `realpath()` cache-path failure that broke fresh-checkout CI.

## 8. Top-of-mind risks for sponsors

| # | Risk | Severity |
|---|---|---|
| 1 | Main `Dockerfile` runs as root, with `DB_PASSWORD=secret` baked into image layers | **P0** |
| 2 | Redis ships with no `--maxmemory` and no eviction policy (OOM under load) | **P0** |
| 3 | `EmployeeController::index/show` returns raw model JSON bypassing `UserResource`, exposing 22 PII fields to any HR_VIEW actor | **P0** |
| 4 | `ActivityLogService::logCreated` stores plaintext model `toArray()` on update; reads are scrubbed but the stored row still contains PII | **P1** |
| 5 | `engine_pivot 2026-07-09` Cluster Full Authority contract change is **undocumented in the repo** — visible only in agent-private memory; future modules risk regressing to the read-only framing | **P1** |
| 6 | `git log main..origin/main` shows 28 unpushed commits + 64 uncommitted files bypassing CI | **P0** |
| 7 | Three `wip:` commits landed directly on `main` (no PR, no CI) | **P1** |
| 8 | 33% CI failure rate over the last 30 runs; PRs merged despite red CI ("STOP regardless of CI") | **P1** |
| 9 | Production deploy + daily backup pipelines are RED (last 2 backup runs failed) | **P0** |
| 10 | `Organization::parent_id` schema allows multi-hop cycles (only direct self-reference CHECK present); relies on application-level BFS guards | **P2** |

Full detail in Report 6 (risk log).

## 9. What this means for the pilot

The system is **functionally rich and architecturally mature** — authz, tenancy, i18n, design system, and CI infrastructure are all in place. But it is **not yet production-safe** in its current state. Critical security hardening (Docker user, secrets in image, PII encryption, backup verification) must precede pilot launch.

The pilot is the right next step. It will exercise the system under realistic multi-tenant load and produce the data needed to prioritize the remaining hardening items (CFA-07/08/09/10/11 review items, PII encryption, cluster pivot documentation).

See:
- **Report 1** — current system status (where we are today)
- **Report 2** — scope and requirements (what the system covers)
- **Report 3** — roadmap (what remains)
- **Report 4** — pilot launch plan
- **Report 5** — Go/No-Go readiness verdict
- **Report 6** — risk register
- **Report 7** — pilot results template (filled after pilot)
- **Report 8** — handover and acceptance template (filled after pilot)