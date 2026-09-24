#!/usr/bin/env bash
# Checks the docs stay split and single-homed: code-cited doc paths exist, relative
# links resolve, no doc outgrows its budget, and test counts live only in
# docs/code-standards.md § 14 (the changelog and docs/archive/ are history, so they may repeat them).
# Run from the repo root; exits 1 on any failure.

set -u
# Link targets are word-split below; never let a `*` in one glob to a real file.
set -f
cd "$(dirname "$0")/.." || exit 1

failures=0
fail() {
	echo "FAIL $1: $2"
	failures=$((failures + 1))
}

doc_files() {
	{
		echo README.md
		find docs -name '*.md'
		find includes/Engine -mindepth 2 -maxdepth 2 -name README.md
	} | sort
}

check_code_refs() {
	local path
	[ -d includes ] && [ -d apps/admin/src ] || fail code-refs "includes/ or apps/admin/src/ missing"
	for path in $(grep -rhoE "docs/[A-Za-z0-9_./-]+\.md" includes apps/admin/src | sort -u); do
		[ -f "$path" ] || fail code-refs "$path cited in code but missing"
	done
}

check_md_links() {
	local file target
	while read -r file; do
		# Link targets only: skip URLs, pure anchors and mailto.
		for target in $(grep -oE '\]\([^)#[:space:]]+(#[^)]*)?\)' "$file" | sed -E 's/^\]\(//; s/\)$//; s/#.*$//' | grep -vE '^(https?:|mailto:)'); do
			[ -e "$(dirname "$file")/$target" ] || fail md-links "$file -> $target"
		done
	done < <(doc_files)
}

check_size() {
	local file lines
	while read -r file; do
		case "$file" in docs/*) ;; *) continue ;; esac
		lines=$(wc -l < "$file" | tr -d ' ')
		[ "$lines" -le 800 ] || fail size "$file has $lines lines (max 800)"
	done < <(doc_files)
	lines=$(wc -l < docs/data-model.md | tr -d ' ')
	[ "$lines" -lt 250 ] || fail size "docs/data-model.md has $lines lines (index must stay under 250)"
}

check_scouts_archived() {
	local file
	for file in docs/points-engine-scout.md docs/redemption-scout.md; do
		[ ! -e "$file" ] || fail scouts-archived "$file still in docs/"
	done
}

check_test_count_owner() {
	local hit
	while read -r hit; do
		[ -n "$hit" ] && fail test-count-owner "$hit"
	done < <(doc_files | grep -vE '^docs/(code-standards|project-changelog)\.md$|^docs/archive/' |
		xargs grep -HnE '[0-9]+[- ](((unit|PHPUnit|integration|vitest|incomplete) )?(tests?|cases?)|incomplete)\b' | cut -c1-120)
}

check_owner_map() {
	grep -q '^## Where each fact lives' docs/codebase-summary.md ||
		fail owner-map "docs/codebase-summary.md has no '## Where each fact lives' section"
}

check_code_refs
check_md_links
check_size
check_scouts_archived
check_test_count_owner
check_owner_map

if [ "$failures" -gt 0 ]; then
	echo "$failures check(s) failed"
	exit 1
fi
echo OK
