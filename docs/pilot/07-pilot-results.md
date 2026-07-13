# Report 7 — Pilot Results Report

**Document type:** Template to be filled at pilot end
**Audience:** Sponsor, steering committee, engineering leads, future GA owner
**Pilot window (planned):** 2026-08-17 → 2026-09-13
**Pilot outcome (TBD):** _____________
**Date filled:** _____________

---

## 1. Executive summary

> *(Fill at pilot end. 1-2 paragraphs.)*

```
The pilot ran from <DATE> to <DATE> across <N_ORGS> organizations and <N_USERS>
pilot users, exercising <N_MODULES> modules. The Cluster Full Authority contract was
validated across <N_CFA_STORIES> stories with <N_IDOR_ATTEMPTS> cross-org access
attempts, of which <N_UNEXPECTED> were unexpected. Total incidents: <N_P0> P0,
<N_P1> P1, <N_P2> P2. Pilot exit criteria met: <yes/no/partial>. Recommendation:
<GO_FOR_GA / NO_GO / CONDITIONAL_GA>.
```

## 2. Pilot scope summary

| Item | Plan | Actual | Variance |
|---|---|---|---|
| Pilot window | 4 weeks | _____________ | _____________ |
| Organizations | 3 (1 cluster + 2 children + 1 grandchild) | _____________ | _____________ |
| Pilot users | 30-50 | _____________ | _____________ |
| Languages | Arabic + English | _____________ | _____________ |
| Browsers | Chromium / Safari / Firefox | _____________ | _____________ |
| Modules exercised | 11 | _____________ | _____________ |
| CFA stories exercised | CFA-01..06, 11 (+/- 07/08/09/10) | _____________ | _____________ |
| Restore drills executed | ≥1 | _____________ | _____________ |

## 3. Adoption metrics

| Metric | Target | Week 1 | Week 2 | Week 3 | Week 4 | End |
|---|---:|---:|---:|---:|---:|---:|
| DAU | — | _____ | _____ | _____ | _____ | _____ |
| DAU % of pilot users | ≥60% by W4 | _____ | _____ | _____ | _____ | _____ |
| WAU % of pilot users | ≥80% by W4 | _____ | _____ | _____ | _____ | _____ |
| Power users (5+ days/week) | — | _____ | _____ | _____ | _____ | _____ |
| Login success rate | >98% | _____ | _____ | _____ | _____ | _____ |
| New user onboarding completion | — | _____ | _____ | _____ | _____ | _____ |

## 4. Engagement metrics (per module)

| Module | Activity | Target | Actual |
|---|---|---|---|
| Strategy | Portfolios created | ≥1 per cluster | _____ |
| Strategy | Programs linked | ≥2 per cluster | _____ |
| Strategy | Reviews completed | ≥2 per cluster | _____ |
| Projects | Projects created | ≥3 per org | _____ |
| Projects | PDCA cycles completed | ≥3 per cluster | _____ |
| Tasks | Tasks created + completed | ≥30 total | _____ |
| Performance | KPIs imported | ≥1 per org (avg ≥50 rows) | _____ |
| Performance | Measurements recorded | ≥100 total | _____ |
| Risk | Risks identified | ≥5 per cluster | _____ |
| Risk | Risks treated + closed | ≥3 per cluster | _____ |
| Meetings | Meetings held | ≥2 per org/week | _____ |
| Meetings | Recommendations approved (4-eyes) | ≥2 per org | _____ |
| OVR | Incidents created | ≥1 per org | _____ |
| OVR | Incidents closed | ≥1 per org | _____ |
| Surveys | Surveys published | ≥1 per org | _____ |
| Surveys | Survey responses received | ≥10 per org | _____ |
| HR | Employees viewed | per use | _____ |
| HR | Certificates uploaded | per use | _____ |

## 5. System health metrics

| Metric | Target | Week 1 | Week 2 | Week 3 | Week 4 | End |
|---|---:|---:|---:|---:|---:|---:|
| P95 page load (cold cache) | <2s | _____ | _____ | _____ | _____ | _____ |
| P95 page load (warm cache) | <1s | _____ | _____ | _____ | _____ | _____ |
| P95 API latency | <500ms | _____ | _____ | _____ | _____ | _____ |
| 5xx rate | <0.1% | _____ | _____ | _____ | _____ | _____ |
| 4xx rate | <5% | _____ | _____ | _____ | _____ | _____ |
| CSRF 419 rate | <1% | _____ | _____ | _____ | _____ | _____ |
| Queue backlog (default) | <50 | _____ | _____ | _____ | _____ | _____ |
| Queue backlog (notifications) | <50 | _____ | _____ | _____ | _____ | _____ |
| Failed jobs (count) | 0 sustained | _____ | _____ | _____ | _____ | _____ |
| Cache hit rate | >80% | _____ | _____ | _____ | _____ | _____ |
| Backup success rate | 100% | _____ | _____ | _____ | _____ | _____ |
| Restore drill success | 100% | _____ | _____ | _____ | _____ | _____ |

## 6. CFA story outcomes

For each CFA story exercised in pilot:

### CFA-01 KPI cluster view
- **Exercised**: yes/no
- **Test cases run**: _____ (list)
- **Result**: pass/fail/partial
- **Incidents**: _____
- **Notes**: _____

### CFA-02 KPI cluster export
- **Exercised**: yes/no
- **Test cases run**: _____ (list)
- **Result**: pass/fail/partial
- **Incidents**: _____
- **Notes**: _____

### CFA-03 Strategy cluster manage
- **Exercised**: yes/no
- **Test cases run**: _____ (list)
- **Result**: pass/fail/partial
- **Incidents**: _____
- **Notes**: _____

### CFA-04 Projects cluster view + status
- **Exercised**: yes/no
- **Test cases run**: _____ (list)
- **Result**: pass/fail/partial
- **Incidents**: _____
- **Notes**: _____

### CFA-05 Risks cluster
- **Exercised**: yes/no
- **Test cases run**: _____ (list)
- **Result**: pass/fail/partial
- **Incidents**: _____
- **Notes**: _____

### CFA-06 Meetings cluster read
- **Exercised**: yes/no
- **Test cases run**: _____ (list)
- **Result**: pass/fail/partial
- **Incidents**: _____
- **Notes**: _____

### CFA-07 Users cluster widening
- **Exercised**: yes/no (depends on re-spec sign-off)
- **Test cases run**: _____ (list)
- **Result**: pass/fail/partial
- **Incidents**: _____
- **Notes**: _____

### CFA-08 Tasks cluster widening
- **Exercised**: yes/no (depends on review sign-off)
- **Test cases run**: _____ (list)
- **Result**: pass/fail/partial
- **Incidents**: _____
- **Notes**: _____

### CFA-09 OVR cluster widening
- **Exercised**: yes/no (depends on review sign-off)
- **Test cases run**: _____ (list)
- **Result**: pass/fail/partial
- **Incidents**: _____
- **Notes**: _____

### CFA-10 Surveys cluster widening
- **Exercised**: yes/no (depends on review sign-off)
- **Test cases run**: _____ (list)
- **Result**: pass/fail/partial
- **Incidents**: _____
- **Notes**: _____

### CFA-11 ActivityLog cluster_auditor
- **Exercised**: yes/no (depends on re-spec sign-off)
- **Test cases run**: _____ (list)
- **Result**: pass/fail/partial
- **Incidents**: _____
- **Notes**: _____

## 7. Incident log (pilot window)

| ID | Date | Severity | Title | Module | Resolution | Closed? | P0/P1/P2 |
|---|---|---|---|---|---|---|---|
| INC-001 | _____ | _____ | _____ | _____ | _____ | _____ | _____ |
| INC-002 | _____ | _____ | _____ | _____ | _____ | _____ | _____ |
| ... | _____ | _____ | _____ | _____ | _____ | _____ | _____ |

### Major incidents (P0)

Detailed write-up for each P0:

```
INC-XXX
Date: _____
Severity: P0
Module: _____
Summary: _____
Root cause: _____
Resolution: _____
Time-to-detect: _____
Time-to-mitigate: _____
Time-to-resolve: _____
Customer impact: _____
Recurrence risk: _____
Postmortem action items: _____
```

## 8. Cross-org access audit (CFA validation)

| Cluster actor | Source org | Target org | Action | Expected | Actual | OK? |
|---|---|---|---|---|---|---|
| cluster_auditor | Cluster A | Child A.1 | View project | allowed | _____ | _____ |
| cluster_auditor | Cluster A | Sibling Cluster B | View project | denied | _____ | _____ |
| cluster_auditor | Cluster A | Parent org | View project | allowed | _____ | _____ |
| cluster_manager | Cluster A | Child A.1 | Change project status | allowed | _____ | _____ |
| cluster_manager | Cluster A | Sibling Cluster B | Change project status | denied | _____ | _____ |
| cluster_auditor | Cluster A | Child A.1 | View OVR incident | denied (confidential floor) | _____ | _____ |
| cluster_auditor | Cluster A | Child A.1 | View OVR aggregate stats | allowed | _____ | _____ |
| cluster_manager | Cluster A | Child A.1 | View survey responses | denied (raw strict) | _____ | _____ |
| cluster_manager | Cluster A | Child A.1 | Export survey aggregate | allowed | _____ | _____ |

**Total cross-org access attempts**: _____
**Unexpected (denied but attempted)**: _____
**Cross-cluster leakage**: 0 (target)

## 9. PDPL scanner weekly summary

| Week | New patterns | Resolved | Open |
|---:|---:|---:|---:|
| 1 | _____ | _____ | _____ |
| 2 | _____ | _____ | _____ |
| 3 | _____ | _____ | _____ |
| 4 | _____ | _____ | _____ |

## 10. User feedback summary

### What worked well
1. _____
2. _____
3. _____

### What didn't work
1. _____
2. _____
3. _____

### Most-requested improvements
1. _____
2. _____
3. _____

### Languages / i18n observations
- _____

### RTL / Arabic observations
- _____

### Performance observations
- _____

### Mobile / responsive observations
- _____

## 11. Security observations

- **PII leak attempts blocked**: _____
- **Cross-org IDOR attempts blocked**: _____
- **Privilege escalation attempts blocked**: _____
- **Brute-force login attempts blocked**: _____
- **Sanctum token theft attempts**: _____
- **CSRF attempts blocked**: _____
- **Other**: _____

## 12. Operational observations

### Backups
- Daily backups executed: _____
- Pre-migrate backups executed: _____
- Restore drills executed: _____
- Restore drill success rate: _____

### Deployments
- Deployments during pilot: _____
- Successful: _____
- Failed (auto-rolled-back): _____
- Average deploy duration: _____

### On-call
- Pages: _____
- Avg response time: _____
- Avg resolution time: _____

## 13. Pilot exit criteria assessment

| Criterion (from Report 4 §8) | Met? | Notes |
|---|:---:|---|
| All P0 risks from Report 6 mitigated or formally accepted | _____ | _____ |
| CFA stories exercised with zero cross-org IDOR / privilege escalation | _____ | _____ |
| All pilot metrics meet targets | _____ | _____ |
| Restore drill executed successfully | _____ | _____ |
| No more than 5 P1 open issues at pilot end | _____ | _____ |
| All Band A-C items closed | _____ | _____ |

## 14. Open issues at pilot end

| ID | Title | Severity | Owner | ETA |
|---|---|---|---|---|
| _____ | _____ | _____ | _____ | _____ |

## 15. Recommendations for GA

```
GA RECOMMENDATION

Status: GO_FOR_GA / CONDITIONAL_GA / NO_GO

Required actions before GA:
1. _____
2. _____
3. _____

Acceptable to defer to post-GA:
1. _____
2. _____
3. _____

GA date target: _____

Sponsor signature: _____ Date: _____
Engineering lead signature: _____ Date: _____
```

## 16. Appendices

- A: Detailed incident timelines (link or attach)
- B: Pilot user roster (anonymized)
- C: Synthetic data set used (link)
- D: Restore drill reports
- E: PDPL scanner weekly reports
- F: Weekly metrics dashboards
- G: User feedback raw data (if consent obtained)

---

**End of Report 7. Filled at pilot end with retrospective data.**