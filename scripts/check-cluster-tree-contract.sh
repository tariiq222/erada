#!/usr/bin/env bash
# scripts/check-cluster-tree-contract.sh
#
# Cluster-tree contract CI gate.
#
# Every cluster-tree primitive (Capability::CLUSTER_TREE_*) is required to ship
# with three test invariants in the test suite, otherwise it can widen read or
# write access across organizations without proof that:
#
#   1. The widening cannot leak upward (child_user_cannot_*).
#   2. The widening cannot leak sideways across clusters (sibling_cluster_*).
#   3. The widening fail-closes for actors whose organization_id is null
#      (null_org_* / fail_closed).
#
# This script scans the source for every constant defined next to the existing
# CLUSTER_TREE_VIEW constant in app/Modules/Core/Authorization/Capability.php,
# then ensures each one is referenced by at least one test matching each
# invariant pattern. It exits non-zero on the first missing invariant so it
# fails closed in CI.
#
# Portability: this script intentionally avoids Bash 4+ builtins (notably
# `mapfile`) so it runs under macOS Bash 3.2.57, the system default on the
# workstation and on the CI macOS runner.
#
# Exit codes:
#   0 - all cluster-tree primitives have the required test coverage.
#   1 - usage / source not found.
#   2 - a primitive is missing one or more invariant tests.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

CAP_FILE="app/Modules/Core/Authorization/Capability.php"
if [[ ! -f "$CAP_FILE" ]]; then
    echo "ERROR: $CAP_FILE not found - refusing to run." >&2
    exit 1
fi

# Bash 3 portable replacement for `mapfile -t ARRAY < <(...)`: a plain
# while-read loop that appends to the array. Avoids the Bash 4+ `mapfile`
# builtin, which is missing on macOS Bash 3.2.57.
PRIMITIVES=()
while IFS= read -r line; do
    PRIMITIVES+=("$line")
done < <(
    grep -oE "(^|[[:space:]])const CLUSTER_TREE_[A-Z_]+ *= *'" "$CAP_FILE" \
        | sed -E "s/.*(CLUSTER_TREE_[A-Z_]+).*/\1/" \
        | sort -u
)

if [[ ${#PRIMITIVES[@]} -eq 0 ]]; then
    echo "ERROR: no CLUSTER_TREE_* constants found in $CAP_FILE." >&2
    exit 1
fi

TEST_ROOTS=("tests/Unit" "tests/Feature")

slug() {
    printf '%s' "$1" | tr '[:upper:]' '[:lower:]'
}

fail=0
for primitive in "${PRIMITIVES[@]}"; do
    slug="$(slug "$primitive")"
    # Each invariant pattern is anchored to THIS primitive's slug (e.g.
    # `cluster_tree_manage`), not to the generic `cluster_tree` substring.
    # Tests named after a sibling primitive do NOT satisfy this gate —
    # a future CLUSTER_TREE_FOO primitive cannot pass using only the
    # VIEW/MANAGE/EXPORT markers below. Every CLUSTER_TREE_* primitive must
    # ship its own executable child_user_cannot_*<slug>,
    # sibling_cluster*<slug>, and (null_org|fail_closed)*<slug> coverage
    # that calls AccessDecision::can with its own capability constant.
    child_pat="child_user_cannot_.*${slug}"
    sibling_pat="sibling_cluster.*${slug}"
    nullorg_pat="(null_org|fail_closed).*${slug}"

    echo "checking ${primitive}:"

    missing=()
    if ! grep -rE "${child_pat}" "${TEST_ROOTS[@]}" --include='*.php' -q; then
        missing+=("child_user_cannot_*cluster_tree")
    fi
    if ! grep -rE "${sibling_pat}" "${TEST_ROOTS[@]}" --include='*.php' -q; then
        missing+=("sibling_cluster*cluster_tree")
    fi
    if ! grep -rE "${nullorg_pat}" "${TEST_ROOTS[@]}" --include='*.php' -q; then
        missing+=("(null_org|fail_closed)*cluster_tree")
    fi

    if [[ ${#missing[@]} -eq 0 ]]; then
        echo "  OK - child_user_cannot, sibling_cluster, null_org all present."
    else
        echo "  MISSING invariants:"
        for m in "${missing[@]}"; do
            echo "    - test name matching: $m"
        done
        fail=1
    fi
done

if [[ $fail -ne 0 ]]; then
    echo ""
    echo "ERROR: at least one CLUSTER_TREE_* primitive is missing required invariants." >&2
    echo "Add tests covering child_user_cannot_*, sibling_cluster_*, and (null_org_*|fail_closed_*)" >&2
    echo "patterns before adding a new primitive or extending an existing one." >&2
    exit 2
fi

echo ""
echo "All ${#PRIMITIVES[@]} cluster-tree primitive(s) have required test coverage."