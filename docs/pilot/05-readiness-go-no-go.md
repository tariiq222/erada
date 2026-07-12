# Report 5 — Readiness and Go/No-Go Decision

**Document type:** Decision document
**Audience:** Sponsor (Tariq), steering committee, engineering leads
**Decision window:** Target 2026-08-15 (T-2 days before pilot kickoff)
**Decision authority:** Sponsor

---

## 1. Decision framework

Go/No-Go is determined by the **conjunction** of three conditions:

1. **All P0 risks in Report 6 are CLOSED or formally ACCEPTED by sponsor in writing.**
2. **All CFA review items (CFA-07..11) are RESOLVED** (resolved = either merged with sponsor sign-off, OR explicitly excluded from pilot scope with sponsor sign-off).
3. **CI is GREEN on `main` for ≥5 consecutive days** AND the synthetic dry-run (Band A item 16) completes with no P0 incident over 48h.

If any of the three conditions is not met, the decision defaults to **NO-GO** with a documented remediation path.

## 2. Verdict (as of 2026-07-12)

**Current verdict: CONDITIONAL NO-GO.**

**Reasons:**
1. **5 P0 risks remain open** (Docker user, secrets in image, Redis config, PII leak in EmployeeController, no restore drill). Band A items 1-10 will close them in 3 weeks if started immediately (target: 2026-08-09).
2. **CFA-07 + CFA-11 are HARD STOPPED** with CI failed twice; re-spec pending. CFA-08 / CFA-09 / CFA-10 are STOPPED for review with CI green.
3. **CI on `main` is RED**: 28 unpushed commits, 3 `wip:` commits, 33% failure rate, deploy pipeline red, daily backup failed twice.

**Path to GO**: close Band A (3 weeks), resolve Band B (CFA reviews), publish Band C (ADRs), run Band A-16 dry-run. Re-evaluate Report 5 on **2026-08-15**.

## 3. Conditions checklist

### Condition 1 — All P0 risks CLOSED or ACCEPTED

| P0 risk | Required action | Status (2026-07-12) | Owner |
|---|---|---|---|
| Docker runs as root | Add `USER www-data` directive | ❌ open | devops |
| Secrets baked into image | Drop `cp .env.example .env` from build | ❌ open | devops |
| Redis no maxmemory | Set `--maxmemory 512mb --maxmemory-policy allkeys-lru` | ❌ open | devops |
| EmployeeController PII leak | Replace with `EmployeeResource` | ❌ open | backend |
| No encryption on personal_info | Add `'encrypted'` casts | ❌ open | backend |
| 28 unpushed commits + wip: on main | Move to feature branch, PR review | ❌ open | eng-lead |
| Deploy + backup pipelines red | Fix deploy + restore drill + WAL archiving | ❌ open | devops |

**Verdict**: ❌ FAILED — 7 P0 risks open, none accepted.

### Condition 2 — CFA review items RESOLVED

| Story | Status | Pilot scope decision needed |
|---|---|---|
| CFA-07 Users cluster widening | HARD STOP (CI failed twice, HIGH PII) | sponsor must decide: redesign OR exclude |
| CFA-08 Tasks cluster widening | STOP for review (CI green) | sponsor must approve |
| CFA-09 OVR cluster widening | STOP for review (CI green) | sponsor must approve (aggregate only) |
| CFA-10 Surveys cluster widening | STOP for review (CI green) | sponsor must approve (aggregate only) |
| CFA-11 ActivityLog cluster widening | HARD STOP (CI failed twice) | sponsor must decide: redesign OR exclude |

**Verdict**: ❌ FAILED — 5 stories unresolved.

### Condition 3 — CI GREEN ≥5 consecutive days + synthetic dry-run passes

- Last 30 CI runs: 19 success / 10 failure / 1 cancelled → **33% failure rate**.
- Deploy pipeline: **red** (last run failed at `Run Tests`).
- Daily backup: **red** (last 2 runs failed on Jul 11 + Jul 12).
- Synthetic dry-run: **not yet executed**.

**Verdict**: ❌ FAILED — CI not green.

## 4. Risk acceptance mechanism

For any P0 risk the sponsor wishes to **ACCEPT** (i.e., accept the residual risk during pilot rather than close it), the following template must be completed and signed before pilot launch:

```
PILOT RISK ACCEPTANCE

Risk ID: P0-N
Risk title:
Owner: (engineering / devops / security)
Description: (what could go wrong)
Probability × Impact: (1-5 × 1-5 = risk score)
Mitigation in place: (what's already done to reduce risk)
Residual risk: (what remains after mitigation)
Compensating controls: (monitoring, alerting, runbook)
Acceptance duration: (pilot window only, OR until <date>)
Sponsor signature: _____________ Date: _____
```

This acceptance is recorded in Report 6 (risk register) and re-evaluated at pilot end.

## 5. Go-decision flow

```
Day 0 (2026-08-09): All Band A items closed.
Day 1 (2026-08-10): CI green streak starts.
Day 6 (2026-08-15): Sponsor evaluates Go/No-Go.

  IF all 3 conditions met → GO
    → Issue Report 5 v2 with GO verdict
    → Pilot kickoff 2026-08-17 (Monday)

  IF any condition fails → NO-GO
    → Issue Report 5 v2 with NO-GO verdict + remediation list
    → Re-evaluate after 1 week of remediation (target 2026-08-22)
    → If still NO-GO, pilot postponed until conditions met
```

## 6. Conditional Go scenarios

The sponsor may issue a **CONDITIONAL GO** if any single condition has a documented path to close within the pilot's first 3 days. For example:

- **CONDITIONAL GO-1**: P0 EmployeeController leak CLOSED in CI but pending deploy; deploy scheduled Day 1 of pilot; pilot kicks off with `EmployeeController` shadowed.
- **CONDITIONAL GO-2**: CFA-08 review pending; pilot scope excludes Tasks cluster widening for week 1; widening enabled Day 8 after sponsor sign-off.
- **CONDITIONAL GO-3**: Restore drill not yet executed but scheduled Day 0 evening; pilot begins Day 1 with daily backup verified.

Each CONDITIONAL GO requires the sponsor's written acceptance of the residual risk.

## 7. NO-GO decision consequences

A NO-GO decision does **not** stop engineering; it adjusts the pilot window:

- All Band A items continue toward closure.
- ADRs (Band C) are written.
- CI hardening (Band D) continues.
- Performance + DX (Band E) continues.
- Synthetic dry-run is rerun weekly until clean.

When all 3 conditions are met, Report 5 v2 issues GO and pilot kicks off in the next available window.

## 8. Decision authority

The Go/No-Go decision is the **sponsor's alone**. The engineering team provides:

- Status of Band A/B/C items.
- Current CI signal.
- Synthetic dry-run result.
- Risk register (Report 6) with residual risks called out.

The pilot owner + steering committee provide:

- User readiness assessment.
- Communications + training readiness.
- Support staffing.

## 9. Go-decision record

When the GO verdict is issued, this section is completed:

```
PILOT GO DECISION

Date: _____________
Sponsor: _____________
Engineering lead: _____________

✓ Condition 1 — All P0 risks CLOSED or ACCEPTED (list any ACCEPTED risks):
  - P0-N: title, acceptance attached

✓ Condition 2 — CFA review items RESOLVED (list any EXCLUDED from pilot):
  - CFA-NN: status, pilot-scope decision

✓ Condition 3 — CI GREEN + dry-run passed:
  - CI streak: ___ consecutive green runs (target ≥5)
  - Synthetic dry-run result: pass/fail, duration, observations

Pilot window: 2026-08-17 → 2026-09-13 (4 weeks)
Pilot scope: see Report 4 §2
On-call rotation: confirmed by devops + backend
Communications: #pilot-announce / #pilot-support / #pilot-alerts all live

Sponsor signature: _____________ Date: _____
```

## 10. Conditional Go-decision record (if applicable)

```
CONDITIONAL PILOT GO DECISION

Date: _____________
Sponsor: _____________

Conditions attached (any unclosed P0 / CFA / CI items with deadline):
  1. __________________ — deadline: _____________
  2. __________________ — deadline: _____________

Risk acceptance records (cross-reference Report 6):
  - P0-N: acceptance ID, attached

If any condition is missed, pilot auto-pauses per Report 4 §7.

Sponsor signature: _____________ Date: _____
```

## 11. Post-decision review

At pilot end (or NO-GO escalation), this section is completed with retrospective notes:

```
DECISION RETROSPECTIVE

Date: _____________

If GO:
  - Pilot completed within window: yes/no
  - Exit criteria met: yes/no (see Report 4 §8)
  - Lessons learned: _____________
  - Band H (process) items completed: _____________

If NO-GO:
  - Conditions blocking: _____________
  - Remediation ETA: _____________
  - Next decision date: _____________
  - Lessons learned: _____________
```

---

**End of Report 5. Version 1 issued 2026-07-12 as CONDITIONAL NO-GO. Version 2 will be issued 2026-08-15 (or after).**