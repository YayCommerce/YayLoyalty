#!/bin/bash
#
# Purpose: stop Claude's file tools from editing generated output. A PreToolUse hook,
# wired up in .claude/settings.json: exit 2 blocks the tool call and shows Claude the
# message, and exit 0 hands the call on to the usual permission check.
# Update when: a generated directory, or the command that rebuilds it, changes.
#
# Shell commands never reach this hook. Without jq it exits 1, which Claude Code reports
# as a hook error before letting the edit through.

set -eu

if ! command -v jq >/dev/null; then
    echo "guard-generated-paths: jq isn't installed, so edits to generated directories aren't blocked." >&2
    exit 1
fi

root="${CLAUDE_PROJECT_DIR:-$PWD}"
physical_root="$(cd "$root" 2>/dev/null && pwd -P || echo "$root")"

# The target's path inside the project, with "." and ".." resolved so that
# includes/../vendor/ is caught too. Empty when the target is outside the project.
rel="$(jq -r --arg root "$root" --arg physical "$physical_root" '
    def segments: split("/") | reduce .[] as $s ([];
        if $s == "" or $s == "." then . elif $s == ".." then .[:-1] else . + [$s] end);
    (.tool_input.file_path // .tool_input.notebook_path // empty) as $p
    | ($p | if startswith("/") then . else "\($root)/\(.)" end | segments) as $path
    | [($root, $physical) | segments | length as $n | select($path[:$n] == .) | $path[$n:] | join("/")]
    | .[0] // empty
')"

# Claude Code's own worktrees sit inside the project, in .claude/worktrees/<name>/.
rel="${rel#.claude/worktrees/*/}"

case "$rel" in
    vendor/*)                        rebuild='composer install' ;;
    vendor-prefixed/*)               rebuild='./scripts/run.sh shell-update (then commit it)' ;;
    node_modules/*|*/node_modules/*) rebuild='pnpm install' ;;
    assets/dist/*)                   rebuild='./scripts/run.sh build' ;;
    release/*)                       rebuild='./scripts/run.sh release' ;;
    *)                               exit 0 ;;
esac

echo "Blocked: $rel is generated output, rebuilt by $rebuild. Change the input it is built from and rerun that, and don't write the file through the shell instead." >&2
exit 2
