# Report 2 — System Scope and Requirements

**Document type:** Scope-of-work reference
**Audience:** Sponsors, scope owners, pilot evaluators, future contributors
**As-of date:** 2026-07-12
**Data source:** Module-by-module audit + REST contract audit + feature inventory

---

## 1. Scope statement

Erada PMO delivers the **end-to-end lifecycle of institutional project management** across 11 functional modules, under multi-tenant organization and cluster-tree hierarchies, with a custom AccessDecision authorization engine, Arabic-first UI, and full audit trail.

### In scope (this release)

- **Strategy**: Portfolio, Program, Project linkage, Review, Blocker.
- **Projects**: PMBOK charter, FOCUS-PDCA lifecycle, members, expenses, milestones, risks, settings, stakeholders, governing-departments, activity log.
- **Tasks**: Unified tasks endpoint, PDCA cycle, polymorphic source chain (project / department / KPI / risk / recommendation / OVR / milestone), status, assign, complete, gating.
- **Performance**: KPIs, KPI measurements, KPI links, import/export, cluster aggregate.
- **Risk**: Risk register, assessments, actions, alerts, status changes, types/impact taxonomies.
- **Meetings**: Meeting scheduling, agenda items (with scope bindings), attendees, minutes, recommendations (Direction B four-eyes), resolutions (Direction R task-generation), notifications.
- **OVR (incidents)**: Incident reports, status history, comments, participants, types, archive.
- **Surveys**: Survey designer (sections + fields + versions + answers + answer files), invitations, responses (anonymous + identified), data imports (template-driven), public short URL `/s/{code}`.
- **HR**: Departments (with cycle prevention), employees, certificates, personal info (PII), capacity roles.
- **Core**: Auth (login, OTP, 2FA, password reset), users, organizations (with hierarchical `parent_id`), departments, scoped-roles, capability engine, governance rules, system settings, notifications, audit log, activity log.
- **Shared (cross-cutting)**: Polymorphic comments, attachments, uploads, activity log + cross-org audit log, FileUploadValidator.
- **Admin (super_admin only)**: Independent admin SPA under `/admin/*` with org/role/scope/governance/scoped-role management, system settings, user directory, access summary.

### Out of scope (this release)

- Mobile clients (iOS / Android). SPA only.
- Real-time collaboration (WebSockets, presence). Future.
- External integrations beyond PDPL scanner.
- Multi-language beyond Arabic and English.
- HR recruitment pipeline, payroll, leaves (PII schema is present; workflows absent).
- Marketing site / landing pages.

## 2. Functional requirements by module

Each requirement below maps to a verified implementation; "Status" reflects the audit.

### Core (Auth + Identity + Tenancy + Authz engine)
| ID | Requirement | Status |
|---|---|---|
| C-01 | Sanctum cookie auth + bearer tokens; CSRF on state-changing API calls via `EnsureCsrfForStateChangingApi` | ✅ |
| C-02 | Login with email/password + OTP + 2FA (`/2fa/enable`, `/2fa/disable`, `/2fa/confirm`, `/2fa/recovery-codes`) | ✅ |
| C-03 | Password reset via OTP (`POST /password/otp/request`, `POST /password/otp/verify`, `POST /password/reset`) | ✅ |
| C-04 | Multi-tenant organization hierarchy with `parent_id` + cycle prevention (CHECK + application BFS) | ✅ |
| C-05 | Departments hierarchy (parent / child) under organization | ✅ |
| C-06 | Scoped roles (org / department / project / portfolio / program) with `permissions[]` JSON | ✅ |
| C-07 | Custom `AccessDecision::can()` engine with fail-closed org isolation, super_admin short-circuit, scoped-role resolution, cluster_tree rescue branch | ✅ |
| C-08 | 122 capability constants across 8 providers; 7 tagged and registered | ✅ |
| C-09 | System settings singleton + governance rules (cluster-default + org-specific) | ✅ |
| C-10 | Activity log (cross-cutting) with `organization_id` resolver and dual-serializer (same-org full / cross-org minimal envelope) | ✅ (Phase 1) |
| C-11 | Notifications + audit log + login attempts + email OTP | ✅ |

### HR (employees + PII)
| ID | Requirement | Status |
|---|---|---|
| H-01 | Employee profiles (one-per-user) | ✅ |
| H-02 | Employee certificates (file upload + meta) | ✅ |
| H-03 | Employee personal info (national_id, iqama, address, emergency contacts) | ✅ — but **plaintext stored**, **no encryption** |
| H-04 | Department capacity roles (assign roles to departments) | ✅ |
| H-05 | Cross-org isolation strict (HR is intentionally EXCLUDED from cluster_tree widening — PII floor) | ✅ |
| H-06 | **P0 gap**: `EmployeeController::index/show` returns raw JSON, leaks 22 PII fields to any `HR_VIEW` actor | ❌ |

### Meetings (governance)
| ID | Requirement | Status |
|---|---|---|
| M-01 | Meeting CRUD (date, location, type, category, settings) | ✅ |
| M-02 | Agenda items with request/approve/reject/reorder | ✅ |
| M-03 | Attendees (composite-key pivot) | ✅ |
| M-04 | Recommendations (Direction B): ruling + action item, four-eyes self-approval block | ✅ |
| M-05 | Resolutions (Direction R): task-generating decisions, convertToTasks | ✅ |
| M-06 | Settings per organization (singleton) | ✅ |
| M-07 | CFA-06 cluster_tree read widening | ✅ |
| M-08 | Notifications + reminders + overdue tracking | ✅ |
| M-09 | **P1 gap**: idempotency middleware absent on all meeting/recommendation/resolution mutations | ❌ |

### OVR (incidents)
| ID | Requirement | Status |
|---|---|---|
| O-01 | Incident report lifecycle: REPORTED → INVESTIGATING → RESPONDING → CLOSED | ✅ |
| O-02 | Participants (cross-dept invitations) | ✅ |
| O-03 | Comments, status history | ✅ |
| O-04 | SLA notifications + reminders + pending-timeout re-notifications | ✅ |
| O-05 | Archive of 30-day-old closed reports | ✅ |
| O-06 | Patient PII (`patient_name` encrypted, `patient_file_number` encrypted, DOB / gender plaintext) | ⚠ partial |
| O-07 | CFA-09 cluster_tree aggregate widening (STOP for review) | 🟡 review |
| O-08 | **P1 gap**: idempotency middleware absent on OVR mutations | ❌ |

### Performance (KPIs)
| ID | Requirement | Status |
|---|---|---|
| P-01 | KPI CRUD with category, status, owner | ✅ |
| P-02 | KPI measurements (periodic values) | ✅ |
| P-03 | KPI links (project / strategy / objective) | ✅ |
| P-04 | KPI import (XLSX/CSV via PhpSpreadsheet) — **synchronous in HTTP request, blocks PHP-FPM** | ⚠ |
| P-05 | KPI export (CSV / XLSX) — streamed, both same-org and cluster aggregate paths | ✅ |
| P-06 | 9-D-D1a cluster_tree read widening | ✅ |
| P-07 | CFA-02 cluster_tree export widening (strict `KPIS_EXPORT + CLUSTER_TREE_EXPORT` pair) | ✅ |
| P-08 | **P1 gap**: 0 dedicated cross-org isolation tests in Performance module | ❌ |

### Projects (PMBOK + PDCA)
| ID | Requirement | Status |
|---|---|---|
| PR-01 | Project CRUD + lifecycle (planning / executing / closing) | ✅ |
| PR-02 | PMBOK charter (business_case, success_criteria, manager_authority, exit_criteria) | ✅ |
| PR-03 | FOCUS-PDCA lifecycle with sequential transitions + check-phase KPI gate | ✅ |
| PR-04 | Members, stakeholders, expenses, milestones, risks | ✅ |
| PR-05 | Settings (type → department map) | ✅ |
| PR-06 | Activity log, completion-side-effects, lessons-learned, outcome-summary | ✅ |
| PR-07 | CFA-04 cluster_tree view + status-write widening | ✅ |
| PR-08 | **P2 gap**: `ProjectResource` leaks `organization_id` + `created_by` on cluster reads (self-flagged TODO) | ⚠ |
| PR-09 | **P2 gap**: no direct feature test for `ProjectResource` cluster PII-stripping | ❌ |

### Risk
| ID | Requirement | Status |
|---|---|---|
| R-01 | Risk CRUD + lifecycle (IDENTIFIED → ASSESSED → TREATED → CLOSED) | ✅ |
| R-02 | Assessments, actions, action updates, alerts | ✅ |
| R-03 | Risk types + impact types (global lookup) | ✅ |
| R-04 | CFA-05 cluster_tree view + reassess + change-status widening | ✅ |
| R-05 | Cluster export CSV / PDF (RISKS_VIEW_REPORTS + CLUSTER_TREE_EXPORT) | ✅ |
| R-06 | **P1 gap**: 0 dedicated cross-org isolation tests in RiskManagement module | ❌ |

### Shared (comments + attachments + audit)
| ID | Requirement | Status |
|---|---|---|
| S-01 | Polymorphic comments (commentable_type/id) | ✅ |
| S-02 | Polymorphic attachments (attachable_type/id) | ✅ |
| S-03 | File upload (FileUploadValidator central validator — extension + size + finfo MIME) | ⚠ inconsistent usage |
| S-04 | Activity log with org isolation + cluster widening + dual serializer | ✅ (Phase 1) |
| S-05 | CFA-11 cluster_auditor widening on read AND export | ✅ (but STOP for review) |
| S-06 | **P1 gap**: throttle:uploads defined but not applied to any route | ❌ |

### Strategy
| ID | Requirement | Status |
|---|---|---|
| ST-01 | Portfolio, Program, Review, Blocker CRUD | ✅ |
| ST-02 | 9-D-D1b cluster_tree read widening | ✅ |
| ST-03 | CFA-03 cluster_tree manage widening (governance writes only) | ✅ |
| ST-04 | StrategicObjective legacy dropped (2026-01-16) | ✅ |
| ST-05 | **P2 gap**: `Program.scopeOrganizationId()` reads `$this->organization_id` first but the column is not in `$fillable`; verify existence | ❓ verify |
| ST-06 | **P1 gap**: 0 dedicated cross-org isolation tests in Strategy module | ❌ |

### Surveys
| ID | Requirement | Status |
|---|---|---|
| SU-01 | Survey designer (sections + fields + versions) | ✅ |
| SU-02 | Survey responses (anonymous + identified, encrypted `respondent_name`) | ✅ |
| SU-03 | Invitations (token-based, org derived via survey) | ✅ |
| SU-04 | Data import (template-driven, synchronous in HTTP) | ⚠ |
| SU-05 | Public short URL `/s/{code}` (SPA shell + public API) | ✅ |
| SU-06 | Phase 3A snapshot `respondent_organization_id` (not `organization_id`) | ✅ |
| SU-07 | Phase 3B cluster export — direct download, no disk write | ✅ |
| SU-08 | CFA-10 cluster_tree aggregate widening (STOP for review) | 🟡 review |
| SU-09 | **P1 gap**: `survey_responses.respondent_organization_id` UNINDEXED — full scan on cluster aggregate | ❌ |
| SU-10 | **P1 gap**: `survey raw-response export` writes PII-laden CSV to `storage/app/exports/` with no download route + no anonymous-mode masking | ❌ |

### Tasks
| ID | Requirement | Status |
|---|---|---|
| T-01 | Unified tasks endpoint `/api/unified-tasks` (legacy `/api/tasks` removed) | ✅ |
| T-02 | PDCA cycle: plan → do → check → act | ✅ |
| T-03 | Polymorphic `source` chain (project / department / KPI / risk / recommendation / OVR / milestone) | ✅ |
| T-04 | Status transitions, assign, complete, gating | ✅ |
| T-05 | Phase 2 cluster widening — is_private floor + direct column read | ✅ |
| T-06 | Phase 2B cross-org shape sanitization (strips names + counts) | ✅ |
| T-07 | Phase 2C source-only coverage (Recommendation / Risk / Kpi / Milestone / OVR variants) | ✅ |
| T-08 | CFA-08 cluster_tree read + PDCA-write widening (STOP for review) | 🟡 review |
| T-09 | **P2 gap**: `Task.organization_id` column exists but NOT in `$fillable` | ⚠ |

### Shared platform requirements

| ID | Requirement | Status |
|---|---|---|
| PL-01 | Arabic-first i18n with RTL layout, English fallback | ✅ |
| PL-02 | Laravel Sanctum SPA (HttpOnly cookies) + CSRF | ✅ |
| PL-03 | Form-request `authorize()` is the authz seam | ✅ |
| PL-04 | Multi-tenant strict org isolation + cluster_tree widening | ✅ |
| PL-05 | Activity log on every meaningful mutation | ✅ |
| PL-06 | WCAG 2.1 AA contrast on all semantic tokens | ✅ |
| PL-07 | Cluster Full Authority contract (2026-07-09 pivot) — currently **undocumented in repo** | ❌ P1 |
| PL-08 | Branch protection on `main` | ❌ P0 |
| PL-09 | Production-ready Docker (non-root, secrets out of layer) | ❌ P0 |
| PL-10 | Restore drill + WAL archiving | ❌ P0 |
| PL-11 | PDPL scanner workflow | ✅ |
| PL-12 | Backup workflow (daily + pre-migrate) | ⚠ red |

## 3. Non-functional requirements

| Category | Target | Status |
|---|---|---|
| **Performance** | First-paint < 2s on cold cache; list pages render < 200ms for 100 rows | 🟡 partial — DataTable re-renders full row set on every keystroke; `DataTable` is the priority memoization target |
| **Concurrency** | 2 worker processes (CLAUDE.md claim; **Dockerfile shows numprocs not set — defaults to 1**) | ⚠ unverifiable |
| **Availability** | 99.5% over pilot window | 🟡 — no SLO; no monitoring |
| **Recoverability** | RPO 24h, RTO 60min (RUNBOOK.md aspirational) | 🟡 — no WAL archiving; no restore drill; no storage backup |
| **Security** | OWASP ASVS Level 2 (multi-tenant + PII + role-based + cluster) | 🟡 — 5 P0 gaps (Docker root, secrets in image, PII in controller, no encryption, no backup drill) |
| **Auditability** | Every org-scoped mutation produces an ActivityLog row; cluster cross-org access is logged | ⚠ engine deny path is silent |
| **Observability** | Sentry integration + structured logs + correlation ID end-to-end | ✅ correlation works; **no metrics exporter** |
| **i18n** | Arabic + English parity | ✅ 100% key parity; ⚠ 4 asymmetric interpolation; ⚠ 0 Arabic plurals |
| **A11y** | WCAG 2.1 AA | ✅ contrast; ⚠ route-change focus missing; ⚠ Dropdown keyboard handling |
| **Test coverage** | PHPUnit + Vitest + Playwright; isolated classes pass deterministically | ✅ PHPUnit known-flake handled by isolation re-run; ⚠ E2E non-blocking |

## 4. Constraint catalogue (binding)

- **Stack lock**: Laravel 12 / PHP 8.4 / PostgreSQL 16 only. SQLite forbidden (CI guard).
- **No dedicated queues**: all jobs on `default` queue (CLAUDE.md hard rule). Supervisor `numprocs` claim of 2 is unverifiable from the Dockerfile.
- **Form-request `authorize()` is the only authz seam** for controllers; policies delegate to `AccessDecision::can`.
- **Module migrations live under `database/migrations/{module}/`** for `meetings`, `ovr`, `risk_management`; others go to root.
- **No `->onQueue()` calls** anywhere; this is a project invariant.
- **Code is English-only** (CLAUDE.md global rule) — comments, docblocks, commit messages, log strings. Chat replies to the user may be Arabic.
- **Push policy**: explicit user ask required for `origin`; never `--force`. Local `git commit` is the caller's choice.
- **Concurrent sessions share working tree** (LR-008); no worktree isolation.

## 5. Integration surface

| Direction | Surface | Status |
|---|---|---|
| Inbound | `/s/{code}` public survey URL | ✅ |
| Inbound | `/login`, `/register`, `/language/{locale}` (cookie-based) | ✅ |
| Inbound | OTP (`/password/otp/request`, `/password/otp/verify`) | ✅ |
| Inbound | Sanctum SPA bearer token | ✅ |
| Outbound | SMTP mail (auth / OTP / notifications) | ⚠ driver is `log` by default; production needs SMTP |
| Outbound | Sentry error tracking | ✅ DSN-gated, opt-in |
| Outbound | Slack webhook on backup failure | ✅ |
| Outbound | Dokploy webhook on deploy | ✅ |
| Internal | Queue worker → 21 notifications | ✅ all on default queue |
| Internal | Scheduler → 11 cron tasks | ✅ |
| External | PDPL scanner (SARIF → Code Scanning) | ✅ |
| External | S3 backup bucket | ✅ no lifecycle policy |

## 6. Acceptance criteria summary (for pilot)

A pilot launch will be deemed ready when the system meets **all P0 requirements** in §2 and §3 above. Specifically:

1. App container runs as `www-data`, not root.
2. `.env` is not baked into the image; production secrets injected at runtime.
3. Redis is configured with `--maxmemory` + `--maxmemory-policy`.
4. `EmployeeController::index/show` returns a PII-aware `EmployeeResource` with `$hidden` / `whenLoaded` gating matching `UserResource`.
5. `employee_personal_info` PII fields have `'encrypted'` casts.
6. `main` has branch protection enforced.
7. Production deploy and daily backup pipelines are green.
8. All P0 risks in Report 6 are mitigated or accepted in writing by sponsor.

See Report 4 (pilot launch plan) for the sequencing and Report 5 (Go/No-Go) for the final verdict.