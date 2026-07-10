#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 1 ]]; then
    echo "Usage: $0 <output-file>" >&2
    exit 64
fi

output_file="$1"

if [[ ! -f "$output_file" ]]; then
    echo "Output file not found: $output_file" >&2
    exit 66
fi

if grep -q '<promise>STOP</promise>' "$output_file"; then
    echo "stop"
elif grep -q '<promise>COMPLETE</promise>' "$output_file"; then
    echo "complete"
else
    echo "continue"
fi
