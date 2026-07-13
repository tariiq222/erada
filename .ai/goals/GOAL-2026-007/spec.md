# Codex Security Diff Scan Completion — Spec

## Scope

Read-only Codex Security diff scan for `/Users/tariq/code/erada-platform/.worktrees/authorization-full-cutover` against `main`. Locked to:

- **Branch:** `codex/authorization-full-cutover`
- **HEAD:** `ca23078ab3d7138b5c6b8cc5b0c59f55be33edd5`
- **Merge base:** `11f82ff995f2b1e1d10564c19d83c2ca5becfbb9`
- **Diff scan + locally uncommitted changes** (preserve exactly).
- **Worklist:** 309 source-file rows in `artifacts/02_discovery/deep_review_input.jsonl`.

## Inputs (existing artifacts)

| Artifact | Path | Use |
|---|---|---|
| Worklist | `artifacts/02_discovery/deep_review_input.jsonl` | Canonical 309-row source |
| Receipt ledger | `artifacts/02_discovery/work_ledger.jsonl` | Append-only file receipts |
| Raw candidates | `artifacts/02_discovery/raw_candidates.jsonl` | Append-only candidate declarations |
| Context | `artifacts/01_context/threat_model.md` | Threat model for triage |
| Candidate ledgers | `artifacts/05_findings/<id>/candidate_ledger.jsonl` | Per-candidate 3-phase receipts |
| Validation reports | `artifacts/05_findings/<id>/validation_report.md` | Per-candidate narrative |

## Outputs (to produce)

1. `artifacts/03_coverage/coverage.json` — worklist closure, disposition summary.
2. `artifacts/04_reconciliation/candidate_dedup.json` — explicit dedup decisions.
3. `artifacts/02_discovery/raw_candidates.jsonl` — final reconciled raw entries.
4. `artifacts/05_findings/findings.json` — reportable findings array.
5. `artifacts/00_manifest/scan-manifest.json` — scan metadata (run timestamp, base/head, file counts).
6. `docs/runbooks/authorization-full-cutover-security-review-handoff.md` — appended final report section.

## Behavior rules

- Append a single `full_file_read` or `not_applicable` / `deferred` receipt per worklist row.
- For each plausible finding, declare a canonical CSD-CA23078-* candidate id and append all three phases before moving on.
- For overlapping candidates, prefer a single `instance_key` and reference shared evidence; do not duplicate raw entries unless source/control/sink/impact differs.
- Read the entire file once per receipt — no partial reads.
- Reconcile the orphan `authorization-role-edit-non-superadmin-escalation` ledger against the canonical set.

## Out of scope

- Code changes to remediate findings.
- Re-running the full PHPUnit suite (test DB env unavailable).
- Commit, push, merge, deploy, production DB.

## Dispositions

| Disposition | Meaning |
|---|---|
| `full_file_read` | File exists; full content reviewed; no candidate surfaced in this row. |
| `not_applicable` | File absent from HEAD (deleted in diff) or out of audit scope; evidence required. |
| `deferred` | Awaiting external evidence unavailable in this session (e.g., live DB runtime). |
| `candidate_appended` | Receipt references a CSD-CA23078-* candidate that already has raw + ledger entries. |
