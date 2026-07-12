# Report 8 — Handover and Acceptance Minutes

**Document type:** Template to be filled at GA decision
**Audience:** Sponsor, engineering leads, future GA owner, on-call team
**Date filled:** _____________

---

## 1. Meeting details

```
Date: _____________
Time: _____________ → _____________
Location: _____________ (video / in-person)
Minute-taker: _____________
Attendees:
  - Sponsor: _____________ (signature: _____)
  - Engineering lead: _____________ (signature: _____)
  - DevOps lead: _____________ (signature: _____)
  - Security lead: _____________ (signature: _____)
  - QA lead: _____________ (signature: _____)
  - i18n / UX lead: _____________ (signature: _____)
  - Pilot owner: _____________ (signature: _____)
  - Future GA owner: _____________ (signature: _____)
  - On-call primary: _____________ (signature: _____)
  - On-call secondary: _____________ (signature: _____)
```

## 2. Acceptance verdict

| Item | Verdict | Notes |
|---|---|---|
| Pilot Results Report (Report 7) reviewed | yes / no | _____ |
| Pilot exit criteria (Report 4 §8) met | yes / no / partial | _____ |
| All P0 risks from Report 6 mitigated or formally accepted | yes / no | _____ |
| All CFA stories resolved | yes / no | _____ |
| Restore drill executed successfully | yes / no | _____ |
| Synthetic data dry-run completed | yes / no | _____ |
| ADRs 0001-0006 published in `docs/adr/` | yes / no | _____ |
| Pilot exit acceptance | **GO / CONDITIONAL GO / NO GO** | _____ |
| GA decision | **GA APPROVED / CONDITIONAL GA / GA REJECTED** | _____ |

## 3. Conditions of acceptance

If CONDITIONAL GO or CONDITIONAL GA, list every condition with deadline and owner:

| # | Condition | Owner | Deadline | Status at handover |
|---|---|---|---|---|
| 1 | _____ | _____ | _____ | _____ |
| 2 | _____ | _____ | _____ | _____ |
| 3 | _____ | _____ | _____ | _____ |
| 4 | _____ | _____ | _____ | _____ |

If any condition is missed post-handover, the system reverts to maintenance-only mode (no new feature work) until remediated.

## 4. Scope of handover

### 4.1 In scope (delivered to GA owner)

- **Source code**: `erada-platform` repository at commit SHA `_____________`.
- **Database schema**: 220 migrations applied; backup snapshot dated `_____________`.
- **API surface**: ~340 routes (~166 GET, ~107 POST, ~36 PUT, ~15 PATCH, ~41 DELETE, ~13 apiResource) across 11 modules.
- **Frontend SPA**: React 19 + TS 5.7 + Vite 7, FSD-layered, 196 files importing from `@shared/ui`.
- **Documentation**:
  - `CLAUDE.md`, `AGENTS.md`
  - `docs/adr/0001..0006`
  - `docs/authz/{record-rule-evaluator-column-policy, resource-authorization-graph, organization-permissions-remediation-design}.md`
  - `docs/migrations-remediation-playbook.md`
  - `docs/superpowers/{plans,specs}/*`
  - `docs/pilot/00..08` (this set)
  - `.github/{RUNBOOK,DEPLOYMENT}.md`
  - API reference (TBD — see open items)
  - Database guidelines (TBD)
  - Design rules (TBD)
- **Tests**: PHPUnit (~700 tests), Vitest (~200 tests), Playwright (~30 specs).
- **CI**: GitHub Actions (4 workflows), 11 jobs.
- **Backups**: daily pg_dump to S3 with restore drill, weekly.
- **Monitoring**: Sentry (errors), Dokploy (deploy metrics), Slack webhooks (backup/deploy failure).

### 4.2 Out of scope (deferred to post-GA)

- Mobile clients.
- Real-time collaboration (WebSockets).
- External integrations beyond PDPL scanner.
- HR workflow expansion (recruitment / payroll / leaves).
- WebSocket broadcasts.
- `axios` removal (low priority).
- `Program.organization_id` column investigation (verify column existence).
- Multi-region deployment.
- SSO / SAML / OIDC integration.
- Custom branding per cluster.
- Webhook outbound system.

## 5. Operational handover

### 5.1 Infrastructure access

| Asset | Owner | Handover status |
|---|---|---|
| Dokploy project | devops | transferred / pending / partial |
| S3 backup bucket | devops | transferred / pending / partial |
| Sentry project | backend | transferred / pending / partial |
| SMTP credentials | devops | transferred / pending / partial |
| GitHub repository | eng-lead | transferred / pending / partial |
| Slack channels | eng-lead | transferred / pending / partial |
| PDPL scanner config | security | transferred / pending / partial |
| Domain + DNS | devops | transferred / pending / partial |
| TLS certificates | devops | transferred / pending / partial |
| Secrets (1Password / Vault) | sponsor | transferred / pending / partial |

### 5.2 On-call rotation

| Tier | Primary | Secondary | Escalation |
|---|---|---|---|
| DevOps | _____ | _____ | eng-lead |
| Backend | _____ | _____ | eng-lead |
| Frontend | _____ | _____ | eng-lead |
| Security | _____ | _____ | sponsor |
| Database | _____ | _____ | backend / devops |

Rotation cadence: weekly hand-off, handoff document in `docs/oncall/handoff-<DATE>.md`.

### 5.3 Runbook updates needed

- [ ] `docs/RUNBOOK.md` updated with current Sentry, Dokploy, S3, SMTP details.
- [ ] `docs/DEPLOYMENT.md` updated with the GA-approved deploy flow.
- [ ] Restore drill runbook with actual command + timing data.
- [ ] On-call escalation tree.
- [ ] PDPL scanner weekly report workflow.
- [ ] Synthetic-data seed command (`composer dev` or equivalent).

### 5.4 Known operational debt

| Item | Severity | Owner | ETA |
|---|---|---|---|
| Backup pipeline lacks S3 lifecycle rules | medium | devops | 2 weeks post-GA |
| No metrics exporter (Prometheus) | medium | devops | 1 month post-GA |
| Log scrubbing for PII partial | high | backend | already fixed in Band A-6 + A-7 |
| `Program` org column investigation | low | backend | 2 weeks post-GA |
| SurveyResponse index missing | medium | database | already added in Band G-1 |

## 6. Knowledge transfer

### 6.1 Documentation review session

A live walk-through with the GA owner covering:

- [ ] Architecture overview (FSD + 11 modules + AccessDecision engine)
- [ ] Authorization model (engine + Spatie hybrid, dual-pair cluster widening)
- [ ] Multi-tenancy (org hierarchy + BFS walker + cluster widening)
- [ ] Database schema (75 models, 220 migrations, blocked migrations)
- [ ] Frontend FSD layers + design system
- [ ] CI / CD pipelines
- [ ] Backup / restore drill
- [ ] Incident response
- [ ] Pilot retro insights
- [ ] Open questions + sponsor's queue

### 6.2 Knowledge transfer artifacts

- [ ] Architecture diagram (Mermaid / draw.io)
- [ ] Capability map (`docs/adr/0005` + linked tables)
- [ ] Cross-module dependency matrix (`docs/architecture/dependency-matrix.md`)
- [ ] Module responsibility matrix (from Report 2)
- [ ] Cluster widening primitive matrix (which primitive widens which surface)
- [ ] Onboarding checklist for new engineers (1-week ramp plan)

### 6.3 Recorded sessions

- [ ] Architecture walk-through (60 min)
- [ ] Authz engine deep-dive (60 min)
- [ ] Frontend FSD tour (45 min)
- [ ] Cluster widening primitives demo (45 min)
- [ ] CI / CD pipeline walkthrough (30 min)
- [ ] Backup + restore drill (30 min)
- [ ] Incident response scenarios (45 min)

## 7. Open work at handover

### 7.1 Engineering backlog

| ID | Title | Owner | Severity | ETA |
|---|---|---|---|---|
| _____ | _____ | _____ | _____ | _____ |
| _____ | _____ | _____ | _____ | _____ |
| _____ | _____ | _____ | _____ | _____ |

(Link to issue tracker.)

### 7.2 Open design questions (from Report 6 §7)

| Q | Question | Owner | Status at handover |
|---|---|---|---|
| Q1 | Is `CLUSTER_TREE_MANAGE` = read+write or write only? | sponsor | _____ |
| Q2 | KPIS_VIEW vs KPIS_EXPORT — why two? | sponsor | _____ |
| Q3 | Hybrid engine cutover order | sponsor + eng-lead | _____ |
| Q4 | `Program.organization_id` column existence | backend | _____ |
| Q5 | `dangerouslySetInnerHTML` in ProjectCharter | frontend | _____ |
| Q6 | Engine `AccessDecision::whyCan()` denial writes | sponsor | _____ |
| Q7 | Pilot scope (was decided at GO time) | sponsor | _____ |
| Q8 | Restore drill cadence | devops + sponsor | _____ |
| Q9 | `lang/ar/*.php` files | backend | _____ |
| Q10 | Cross-org CRUD in pilot | sponsor | _____ |
| Q11 | Audit log table for engine deny rows | sponsor + backend | _____ |
| Q12 | Survey snapshot enforcement | sponsor | _____ |

### 7.3 Risk register hand-off

The active risk register (Report 6) is transferred to the GA owner. Risks remaining at handover are listed there.

## 8. Acceptance signatures

```
SPONSOR ACCEPTANCE

I, __________________, accept the system as delivered for GA on the terms above.

Signature: __________________ Date: _____________
```

```
ENGINEERING LEAD ACCEPTANCE

I, __________________, confirm the source code, tests, and CI are in the
state described and that all Band A items are closed.

Signature: __________________ Date: _____________
```

```
DEVOPS LEAD ACCEPTANCE

I, __________________, confirm the infrastructure (Dokploy, S3, Sentry, SMTP,
GitHub Actions, secrets) is transferred and operational.

Signature: __________________ Date: _____________
```

```
SECURITY LEAD ACCEPTANCE

I, __________________, confirm that all P0 risks from Report 6 are mitigated
or formally accepted, and that the audit trail is operational.

Signature: __________________ Date: _____________
```

```
GA OWNER ACCEPTANCE

I, __________________, accept operational responsibility for the system from
this date forward.

Signature: __________________ Date: _____________
```

## 9. Appendices

- A: Report 7 (Pilot Results) — attached
- B: Report 6 v2 (Risk Register at handover) — attached
- C: ADR-0001 to 0006 — referenced
- D: Source code commit SHA + tag — recorded
- E: Final synthetic data + restore drill reports — attached
- F: Customer-facing release notes — attached
- G: SLA targets for GA (availability, performance, support response) — attached

---

**End of Report 8. To be filled at the GA decision meeting and signed by all parties.**