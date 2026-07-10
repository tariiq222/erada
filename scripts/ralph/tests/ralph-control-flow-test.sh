#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
RALPH_DIR="$ROOT_DIR/scripts/ralph"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

assert_eq() {
    local expected="$1"
    local actual="$2"
    local message="$3"

    if [[ "$expected" != "$actual" ]]; then
        echo "FAIL: $message (expected=$expected actual=$actual)" >&2
        exit 1
    fi
}

printf '%s\n' '<promise>STOP</promise>' '<promise>COMPLETE</promise>' > "$TMP_DIR/stop-output.txt"
status="$($RALPH_DIR/ralph-output-status.sh "$TMP_DIR/stop-output.txt")"
assert_eq "stop" "$status" "STOP must take precedence over COMPLETE"

cat > "$TMP_DIR/dependency-prd.json" <<'JSON'
{
  "userStories": [
    {"id":"CFA-08","priority":8,"passes":false,"dependsOn":["CFA-09"]},
    {"id":"CFA-09","priority":9,"passes":false}
  ]
}
JSON

next_story="$($RALPH_DIR/select-next-story.sh "$TMP_DIR/dependency-prd.json")"
assert_eq "CFA-09" "$(jq -r '.id' <<< "$next_story")" "blocked CFA-08 must not run before CFA-09"

jq '(.userStories[] | select(.id == "CFA-09") | .passes) = true' \
    "$TMP_DIR/dependency-prd.json" > "$TMP_DIR/dependency-prd-complete.json"
next_story="$($RALPH_DIR/select-next-story.sh "$TMP_DIR/dependency-prd-complete.json")"
assert_eq "CFA-08" "$(jq -r '.id' <<< "$next_story")" "CFA-08 must become runnable after CFA-09"

stub_dir="$TMP_DIR/bin"
mkdir -p "$stub_dir"
cat > "$stub_dir/claude" <<'SH'
#!/usr/bin/env bash
count_file="${RALPH_TEST_COUNT_FILE:?}"
count=0
[[ -f "$count_file" ]] && count="$(cat "$count_file")"
printf '%s\n' "$((count + 1))" > "$count_file"
printf '%s\n' '<promise>STOP</promise>'
SH
chmod +x "$stub_dir/claude"

set +e
PATH="$stub_dir:$PATH" \
RALPH_TEST_COUNT_FILE="$TMP_DIR/invocations" \
RALPH_SLEEP_SECONDS=0 \
bash "$RALPH_DIR/ralph.sh" 3 > "$TMP_DIR/ralph.log" 2>&1
exit_code=$?
set -e

assert_eq "2" "$exit_code" "Ralph must exit with the hard-stop status"
assert_eq "1" "$(cat "$TMP_DIR/invocations")" "Ralph must not start another iteration after STOP"

for id in CFA-04 CFA-05 CFA-06; do
    assert_eq "true" "$(jq -r --arg id "$id" '.userStories[] | select(.id == $id) | .passes' "$RALPH_DIR/prd.json")" "$id must match merged main state"
done

assert_eq "CFA-09" "$(jq -r '.userStories[] | select(.id == "CFA-08") | .dependsOn[]' "$RALPH_DIR/prd.json")" "CFA-08 dependency metadata must be preserved"

echo "PASS: Ralph control flow"
