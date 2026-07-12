# Delivery Map

| Slice | Area | Files Touched | Evidence Required |
|---|---|---|---|
| scout_remaining_rows | none (read) | classification output | /tmp/remaining.json |
| receipt_pass_not_applicable | artifacts | $SCAN/artifacts/02_discovery/work_ledger.jsonl (append-only) | jq . lines count = 309 unique worklist paths covered |
| receipt_pass_backend | artifacts | $SCAN/artifacts/02_discovery/work_ledger.jsonl | jq receipt count delta |
| receipt_pass_seeders_and_db | artifacts | work_ledger.jsonl | jq receipt count delta |
| receipt_pass_resources_js | artifacts | work_ledger.jsonl | jq receipt count delta |
| receipt_pass_configs_tooling_i18n | artifacts | work_ledger.jsonl | jq receipt count delta |
| reconcile_orphan_candidate | artifacts | raw_candidates.jsonl + 04_reconciliation/candidate_dedup.json | jq + count |
| verify_candidate_ledgers | artifacts | 05_findings/*/candidate_ledger.jsonl | sha256 of file |
| generate_canonical_artifacts | artifacts | 03_coverage/coverage.json + 00_manifest/scan-manifest.json + 05_findings/findings.json | jq -e . exit 0 |
| write_final_report | worktree docs only | docs/runbooks/authorization-full-cutover-security-review-handoff.md (append) | grep on closure markers |
| close | main checkout | .ai/goals/GOAL-2026-007/goal.yaml + Goal Plugin | jq + update_goal response |

## Gates

- `worklist_coverage`: 309/309 worklist rows have receipts (count from intersection of unique paths).
- `candidate_ledger_completeness`: every raw candidate has 3-phase ledger + validation_report.md.
- `candidate_deduplication`: candidate_dedup.json present and valid.
- `canonical_artifacts_valid`: 3 JSON files pass jq -e .
- `handoff_documented`: final report section appended.
- `read_only_invariant`: `git status --porcelain` after final report must show no new untracked source files except the append to the handoff doc.
