#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════════════════════════
# Script: verify-tariq-wiring.sh
# Purpose: Assert the Tariq agent-family wiring routes through the live Claude
#          provider (webvue) without leaking any secret to disk or stdout.
#
# Exits non-zero on any mismatch. Safe to run inside any project checkout. The
# script itself reads ~/.claude/ but does not require this repo.
#
# Usage:
#   scripts/verify-tariq-wiring.sh                 # static + live probe
#   TARIQ_SKIP_PROBE=1 scripts/verify-tariq-wiring.sh   # static only
#   TARIQ_PROVIDER=webvue scripts/verify-tariq-wiring.sh
# ════════════════════════════════════════════════════════════════════════════════

set -uo pipefail

PROVIDER="${TARIQ_PROVIDER:-webvue}"
BASE_URL="${TARIQ_BASE_URL:-https://ai.webvue.pro}"
EXPECTED_MODEL="${TARIQ_MODEL:-claude-fable-5-dd-3M-xaMiniM}"
KEYCHAIN_SERVICE="${TARIQ_KEYCHAIN_SERVICE:-CLIProxyAPI Production API Key}"
AGENT_DIR="${TARIQ_AGENT_DIR:-$HOME/.claude/agents}"
SETTINGS="${TARIQ_SETTINGS:-$HOME/.claude/settings.json}"
REQUIRED_AGENTS=(tariq-worker tariq-scout tariq-deep tariq-verifier)

GREEN=$'\033[0;32m'
RED=$'\033[0;31m'
YELLOW=$'\033[0;33m'
NC=$'\033[0m'

fail=0
total=0

pass() { printf '%s✔%s %s\n' "$GREEN" "$NC" "$*"; }
warn() { printf '%s⚠%s %s\n' "$YELLOW" "$NC" "$*"; }
err()  { printf '%s✖%s %s\n' "$RED"   "$NC" "$*" >&2; }
bump() { total=$((total + 1)); [[ "$1" == "0" ]] || fail=$((fail + 1)); }

# 1) settings.json base URL + top-level model must match the live provider
echo "==> settings.json"
if [[ ! -f "$SETTINGS" ]]; then
  err "missing $SETTINGS"
  bump 1
else
  actual_url=$(sed -n 's/.*"ANTHROPIC_BASE_URL"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$SETTINGS")
  if [[ -z "$actual_url" ]]; then
    err "ANTHROPIC_BASE_URL not set in $SETTINGS"
    bump 1
  elif [[ "$actual_url" != "$BASE_URL" ]]; then
    err "ANTHROPIC_BASE_URL='$actual_url' != expected '$BASE_URL'"
    bump 1
  else
    pass "ANTHROPIC_BASE_URL = $actual_url"
    bump 0
  fi

  actual_model=$(sed -n 's/.*"model"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$SETTINGS")
  if [[ "$actual_model" != "$EXPECTED_MODEL" ]]; then
    err "top-level model='$actual_model' != expected '$EXPECTED_MODEL'"
    bump 1
  else
    pass "top-level model = $actual_model"
    bump 0
  fi

  if grep -q "apiKeyHelper" "$SETTINGS"; then
    pass "apiKeyHelper is configured (secret kept in macOS Keychain)"
    bump 0
  else
    err "no apiKeyHelper in $SETTINGS — auth path is missing"
    bump 1
  fi
fi

# 2) Each Tariq profile must declare provider=PROVIDER + model: inherit + no CCR remnant
echo "==> Tariq profiles"
for a in "${REQUIRED_AGENTS[@]}"; do
  f="$AGENT_DIR/$a.md"
  if [[ ! -f "$f" ]]; then
    err "missing profile $f"
    bump 1
    continue
  fi

  # Provider line in YAML frontmatter (between two '---' delimiters)
  if awk 'BEGIN{front=0} /^---[[:space:]]*$/{front=!front; next} front{print}' "$f" | grep -q "^provider: ${PROVIDER}$"; then
    pass "$a.md : provider = ${PROVIDER}"
    bump 0
  else
    err "$a.md : missing 'provider: ${PROVIDER}' in YAML frontmatter"
    bump 1
  fi

  if awk 'BEGIN{front=0} /^---[[:space:]]*$/{front=!front; next} front{print}' "$f" | grep -q "^model: inherit$"; then
    pass "$a.md : model = inherit"
    bump 0
  else
    err "$a.md : missing 'model: inherit'"
    bump 1
  fi

  if grep -q "<CCR-SUBAGENT-MODEL>" "$f"; then
    err "$a.md : orphaned <CCR-SUBAGENT-MODEL> block (CCR/9Router retired 2026-07-06)"
    bump 1
  else
    pass "$a.md : no <CCR-SUBAGENT-MODEL> remnant"
    bump 0
  fi
done

# 3) macOS Keychain entry presence (do NOT echo the secret)
echo "==> macOS Keychain"
if security find-generic-password -s "$KEYCHAIN_SERVICE" >/dev/null 2>&1; then
  pass "Keychain entry '$KEYCHAIN_SERVICE' is present (secret not echoed)"
  bump 0
else
  err "Keychain entry '$KEYCHAIN_SERVICE' missing — auth requests will fail"
  bump 1
fi

# 4) Live non-mutating scout probe (skipped if TARIQ_SKIP_PROBE=1)
echo "==> live scout probe"
if [[ "${TARIQ_SKIP_PROBE:-0}" == "1" ]]; then
  warn "skipped (TARIQ_SKIP_PROBE=1)"
else
  if ! command -v claude >/dev/null 2>&1; then
    err "claude CLI not on PATH — skipping live probe"
    bump 1
  else
    expected="provider: ${PROVIDER}"
    prompt="Respond with the literal single line '${expected}' and nothing else. No preamble, no commentary, no tags, no markdown."
    out=$(claude --print --dangerously-skip-permissions --model "$EXPECTED_MODEL" "$prompt" 2>&1 || true)
    # Strip Claude <think>...</think> blocks via pure bash (no pipefail traps).
    cleaned=""
    in_think=0
    while IFS= read -r line; do
      if [[ "$in_think" == "1" ]]; then
        if [[ "$line" == *"</think>"* ]]; then in_think=0; fi
        continue
      fi
      if [[ "$line" == *"<think>"* && "$line" != *"</think>"* ]]; then
        in_think=1
        rest="${line##*<think>}"
        [[ -n "$rest" ]] && cleaned+="${rest}"$'\n'
        continue
      fi
      if [[ "$line" == *"<think>"* && "$line" == *"</think>"* ]]; then
        # Single line "<think>x</think>"
        pre="${line%%<think>*}"
        post="${line##*</think>}"
        cleaned+="${pre}${post}"$'\n'
        continue
      fi
      cleaned+="${line}"$'\n'
    done <<< "$out"
    # First non-empty trimmed line of the cleaned output.
    answer=""
    while IFS= read -r line; do
      trimmed=$(printf '%s' "$line" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')
      if [[ -n "$trimmed" ]]; then
        answer="$trimmed"
        break
      fi
    done <<< "$cleaned"
    if [[ "$answer" == "$expected" ]]; then
      pass "live scout returned '$answer'"
      bump 0
    else
      err "live scout probe failed; expected '$expected', got '$answer' (raw $(printf '%s' "$out" | wc -c | tr -d ' ') bytes)"
      bump 1
    fi
  fi
fi

# Summary
echo ""
echo "==> summary"
if (( fail == 0 )); then
  pass "all $total checks passed (provider=${PROVIDER}, base_url=${BASE_URL})"
  exit 0
else
  err "$fail of $total checks failed"
  exit 1
fi
