# Report 4 — Pilot Launch Plan

**Document type:** Sequencing and runbook for pilot
**Audience:** Pilot owner, sponsors, engineering leads, on-call rotation
**Pilot window:** target 2026-08-17 → 2026-09-13 (4 weeks)
**Prerequisite:** Report 5 (Go/No-Go) issued as **GO**

---

## 1. Pilot goals

1. **Validate the Cluster Full Authority model** under realistic load across 1 cluster with ≥3 child organizations.
2. **Exercise every approved CFA story** end-to-end with real users from pilot organizations:
   - CFA-01 (KPI view), CFA-02 (KPI export), CFA-03 (Strategy manage), CFA-04 (Projects view + status), CFA-05 (Risks), CFA-06 (Meetings), CFA-11 (ActivityLog).
   - The 3 STOP-for-review stories (CFA-08 Tasks, CFA-09 OVR, CFA-10 Surveys) — only if explicitly approved by sponsor during pilot prep.
   - CFA-07 Users and CFA-11 if re-spec'd and approved (otherwise excluded from pilot scope).
3. **Verify the Pilot Acceptance Criteria** in Report 2 §6.
4. **Surface remaining defects** without blocking the user base on a known-broken flow.

## 2. Pilot scope

### In scope
- **3 organizations** (1 cluster + 2 children + 1 grandchild) for cluster widening.
- **30-50 pilot users** distributed across the 3 organizations, with at least:
  - 1 super_admin (Tariq or designate)
  - 3 cluster_auditor (cross-org read)
  - 3 cluster_manager (cross-org manage)
  - 10+ org_admin / manager per org
  - 10+ regular staff per org
- **Modules exercised**: Strategy, Projects, Tasks, Performance, Risk, Meetings, OVR, Surveys, HR.
- **Languages**: Arabic (primary), English (secondary).
- **Browsers**: Chromium latest, Safari latest, Firefox latest (in priority order).
- **Devices**: Desktop primary; tablet secondary; mobile out of scope.

### Out of scope (deferred to GA)
- Mobile clients.
- Real-time collaboration.
- HR recruitment / payroll / leaves workflows.
- External integrations beyond PDPL scanner.
- Pilot users **may not** export PII (employee personal info) to external destinations except via the audit log and the cluster aggregate path.
- Pilot users **may not** mutate cross-org CRUD — only CFA-approved widening primitives apply.

## 3. Pre-launch checklist (Band A from Report 3)

| # | Item | Owner | Deadline | Blocker if not done |
|---|---|---|---|---|
| 1 | Band A-1: `main` is clean, no unpushed commits, no `wip:` commits on shipping branch | eng-lead | 2026-07-19 | YES — no PRs accepted until done |
| 2 | Band A-2: App container runs as `www-data`; `.env` not in image; runtime secret injection verified | devops | 2026-07-26 | YES |
| 3 | Band A-3: Redis `--maxmemory` + `--maxmemory-policy` set | devops | 2026-07-19 | YES |
| 4 | Band A-4: `EmployeeController::index/show` uses `EmployeeResource` | backend | 2026-07-26 | YES |
| 5 | Band A-5: `EmployeePersonalInfo` PII fields `'encrypted'` | backend | 2026-07-26 | YES |
| 6 | Band A-6: `$e->getSql()` redacted in logs | backend | 2026-07-26 | YES |
| 7 | Band A-7: `ActivityLog` honors `$sensitiveFields` | backend | 2026-08-02 | YES |
| 8 | Band A-8: `main` branch protection enforced | devops | 2026-07-19 | YES |
| 9 | Band A-9: Production deploy pipeline green (last 5 runs) | devops | 2026-08-09 | YES |
| 10 | Band A-10: WAL archiving + base backup + restore drill workflow green | devops | 2026-08-09 | YES |
| 11 | Band B: CFA-07 / CFA-11 re-spec'd and merged (if sponsor-approved) OR explicitly excluded from pilot | sponsor | 2026-08-02 | YES for inclusion |
| 12 | Band B: CFA-08 / CFA-09 / CFA-10 reviewed and approved with signed ADRs | sponsor | 2026-08-02 | YES |
| 13 | Band C: ADRs 0001-0006 published in `docs/adr/` | docs-writer | 2026-08-02 | YES |
| 14 | Band D: `npm run design:check` + `composer ci` wired into CI; E2E `continue-on-error: true` removed | devops | 2026-08-09 | NO (acceptable to launch with `continue-on-error` if explicitly noted) |
| 15 | Band E-7, E-8, E-9: middleware coverage gap closure (engine_capability, throttle:uploads/delete, idempotency) | backend | 2026-08-09 | YES for sensitive mutations |
| 16 | Synthetic data dry-run completes (48h, no P0 incident) | test-engineer | 2026-08-14 | YES |

## 4. Infrastructure for pilot

### Single-tenant test (week 1)
- 1 organization, 5 users, 1 super_admin.
- Exercise: project CRUD, task PDCA, KPI import (small CSV), 1 meeting with 1 recommendation, 1 OVR incident lifecycle.
- Goal: smoke test the production-like stack end-to-end.

### Multi-tenant test (week 2)
- 2 organizations (no cluster relation), 1 super_admin.
- Exercise: cross-org isolation tests, `super_admin` org-switch, audit log boundary.
- Goal: verify cross-org isolation contract.

### Cluster test (weeks 3-4)
- 1 cluster + 2 children + 1 grandchild, 30-50 users with mixed roles.
- Exercise: every approved CFA story, KPI export, project cluster-view, ActivityLog cross-org read.
- Goal: validate Cluster Full Authority contract under realistic load.

### Stack
- **Hosting**: Dokploy (existing), single tenant for the pilot period.
- **Domain**: `pilot.erada.example` (placeholder — Tariq to confirm).
- **DB**: PostgreSQL 16 with WAL archiving + S3 backup, 30-day retention.
- **Redis**: 512 MB maxmemory, allkeys-lru.
- **Mail**: SMTP via Postmark (placeholder — Tariq to confirm).
- **Sentry**: DSN configured, release tagging via `SENTRY_RELEASE=$(git rev-parse --short HEAD)`.
- **Backup**: daily 03:07 UTC to S3, 30-day retention; pre-migrate snapshot before every deploy; weekly restore drill.

### Monitoring
- **Metrics**: install Prometheus exporter (Band D-8) before pilot OR use Dokploy built-in metrics for pilot duration.
- **Alerting**: Slack webhook on backup failure, deploy failure, queue backlog > 100, 5xx rate > 0.5% over 5 min window.
- **On-call**: devops primary, backend secondary, 24/7 during pilot weeks 3-4.

## 5. User onboarding

### T-7 days (2026-08-10)
- Send pilot invite emails with:
  - Account credentials (super_admin / cluster_auditor / cluster_manager / org_admin / staff)
  - Quick-start guide (Arabic + English)
  - Link to pilot-specific FAQ
- Provision org + cluster + child org + grandchild org in seed data.

### T-3 days (2026-08-14)
- Conduct training webinar (Arabic, recorded) covering:
  - Project lifecycle (PMBOK + PDCA)
  - KPI import (small CSV example)
  - Meeting + recommendation four-eyes
  - OVR incident lifecycle
  - Cluster widening boundaries (what cluster_auditor can/can't do)
- Demo accounts reset (admin@admin.com / password per CLAUDE.md).

### T-1 day (2026-08-16)
- Pilot users confirm they can log in.
- Synthetic-data dry-run completed by test-engineer.

### T-0 (2026-08-17, Monday)
- Pilot kickoff.
- Daily standup (Arabic, 15 min) for the first 2 weeks.
- Weekly steering review with sponsors.

## 6. Pilot metrics (to be tracked weekly)

### Adoption
- **Daily active users** (target: ≥60% of pilot users by week 4).
- **Weekly active users** (target: ≥80% of pilot users by week 4).
- **Login frequency distribution** (5+ days/week = power user).

### Engagement
- **Projects created** (target: ≥3 per org by week 4).
- **Tasks completed** (PDCA cycle end-to-end) (target: ≥30 across all orgs).
- **KPIs imported** (target: ≥1 import per org, with avg ≥50 rows).
- **Meetings with recommendations approved** (target: ≥2 per org).
- **OVR incidents created and closed** (target: ≥1 per org).
- **Surveys published with responses** (target: ≥1 per org).

### System health
- **P95 page load** (target: <2s; warn: >3s; fail: >5s).
- **P95 API latency** per resource (target: <500ms).
- **Queue backlog** (target: <50 jobs at all times; warn: >100; fail: >500).
- **5xx rate** (target: <0.1%; warn: >0.5%; fail: >2%).
- **CSRF 419 rate** (proxy for cookie freshness) (warn: >1%).
- **Login success rate** (target: >98% excluding failed password attempts).

### Cross-cutting
- **Cross-org access attempts logged** (count + nature; aim for 0 unexpected).
- **Audit log row count** by module (expect Projects + Tasks + Surveys highest).
- **PDPL scanner weekly report** — no new P0 patterns.

## 7. Risk and incident response

### Severity tiers
- **P0 — data loss, security breach, system down**: page on-call within 5 minutes. Engage sponsor within 30 minutes.
- **P1 — single module down, security smell, performance regression**: Slack #pilot-alerts within 30 minutes. Resolve within 24h.
- **P2 — degraded UX, minor bug**: log in tracker. Resolve within pilot window.
- **P3 — cosmetic / nice-to-have**: log in tracker. Triage at pilot end.

### Rollback decision
- **Trigger**: any P0 that cannot be mitigated within 60 minutes.
- **Process**: revert to the previous deploy; restore DB from `pre-migrate` snapshot (if migration-induced); restore `storage/app/private` from S3 backup.
- **Communication**: sponsor notified within 30 minutes of rollback decision.

### Pilot pause / suspension criteria
- 3+ P0 incidents in a single week.
- Any data-loss event.
- Any successful cross-org unauthorized access.
- Performance regression > 50% vs week 1 baseline.

## 8. Pilot exit criteria

The pilot is deemed successful if:
1. **All P0 gaps from Report 6 are mitigated or formally accepted**.
2. **CFA stories (1-6, 11) exercised in pilot data with zero cross-org IDOR / privilege escalation incidents**.
3. **All pilot metrics meet targets** (or are documented exceptions with sponsor sign-off).
4. **Restore drill executed successfully during pilot**.
5. **No more than 5 P1 open issues at pilot end**.
6. **All Band A-C items closed**.

If exit criteria are not met, pilot extends by 2 weeks OR declares a No-Go with documented reasons.

## 9. Communications

| Channel | Purpose | Cadence |
|---|---|---|
| `#pilot-announce` (Slack) | Kickoff, milestones, schedule changes | as needed |
| `#pilot-support` (Slack) | User Q&A during pilot | continuous |
| `#pilot-alerts` (Slack) | P0/P1 incidents | as needed |
| Daily standup (Arabic, video) | Engineering + test | 15 min, weekdays, weeks 1-2 |
| Weekly steering review | Sponsor + leads | 60 min, Mondays |
| Pilot retrospective | All stakeholders | week 4 end |

## 10. Roles

| Role | Person / team |
|---|---|
| **Pilot owner** | Sponsor designate |
| **Engineering lead** | eng-lead (Tariq) |
| **DevOps lead** | devops engineer on rotation |
| **Security lead** | security-auditor agent + sponsor designate |
| **QA lead** | test-engineer + E2E engineer |
| **i18n / UX lead** | frontend-engineer + i18n-engineer |
| **On-call primary** | devops |
| **On-call secondary** | backend |
| **Sponsor** | Tariq |

## 11. Deliverables

During and at the end of pilot:
- Weekly metrics dashboard (auto-generated).
- Weekly incident log (P0/P1).
- Band-tracking report (which Band A-C items closed, which open).
- Report 7 — Pilot Results Report (filled at pilot end).
- Report 8 — Handover and Acceptance Minutes (filled at GA decision).