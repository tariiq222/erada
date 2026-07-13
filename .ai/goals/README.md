# Goal Ledger

Each goal is stored in `.ai/goals/<goal-id>/`. `goal.yaml` is the persistent
source of truth between OpenCode sessions. The Goal Plugin stores session state
and continuation only.

Files: `goal.yaml`, `contract.md`, `spec.md`, `delivery-map.md`, `plan.md`,
`evidence.md`, and `handoff.md` when paused or blocked.

Do not manually delete worktrees or goal folders from a cancelled goal. Review
the recorded branch, worktree, commits, and evidence first.
