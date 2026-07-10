#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 1 ]]; then
    echo "Usage: $0 <prd.json>" >&2
    exit 64
fi

prd_file="$1"

if [[ ! -f "$prd_file" ]]; then
    echo "PRD file not found: $prd_file" >&2
    exit 66
fi

pending_count="$(jq '[.userStories[] | select(.passes == false)] | length' "$prd_file")"

if [[ "$pending_count" -eq 0 ]]; then
    jq -cn '{status:"complete"}'
    exit 0
fi

next_story="$(jq -c '
    . as $root
    | [
        .userStories[] as $story
        | select($story.passes == false)
        | select(
            all(($story.dependsOn // [])[];
                . as $dependency
                | any($root.userStories[];
                    .id == $dependency and .passes == true
                )
            )
        )
        | $story
      ]
    | sort_by(.priority)
    | .[0] // empty
' "$prd_file")"

if [[ -z "$next_story" ]]; then
    jq -c '
        {
            status: "blocked",
            pending: [
                .userStories[]
                | select(.passes == false)
                | {id, dependsOn: (.dependsOn // [])}
            ]
        }
    ' "$prd_file" >&2
    exit 3
fi

printf '%s\n' "$next_story"
