#!/bin/bash
#
# YayLoyalty harness check
# Usage: ./scripts/check-harness.sh
#
# Runs the mechanical checks on the agent and developer harness (CLAUDE.md,
# README.md, .claude/, docs/): references to removed files, doc citations and
# feature IDs in code, missing paths and broken links, missing Purpose and
# Update when headers, dead rule globs, skill names and description lengths,
# @imports in CLAUDE.md, and decision-record format. It prints one line per
# finding and exits 1 if there is any, 0 otherwise. It reads tracked files only
# and changes nothing.
#
# Update when: a harness file, a skill or a decision record is added in a place
# these checks don't cover, or a check's rule changes.
#

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$(dirname "$SCRIPT_DIR")" || exit 2

# References to the removed harness. code-corrector is removed, so any mention counts.
check_removed_refs() {
    git grep -nE 'code-standards|codebase-summary|system-architecture|deployment-guide|project-roadmap|project-changelog|data-model(\.md|/)|points-engine-scout|redemption-scout|check-docs|code-corrector|source-of-truth-check|claudekit|repomix|opencode|README invariants?' -- . ':!docs/plans/' ':!scripts/check-harness.sh'
}

# Doc-section citations in code. The campaign editor's own numbered sections are allowed.
check_section_citations() {
    git grep -n '§' -- includes apps tests scripts phpcs.xml.dist yayloyalty.php ':!scripts/check-harness.sh' \
        | grep -vE '^apps/admin/src/pro/campaigns/(editor/(CampaignEditorSections\.tsx|campaign-editor\.form\.ts)|add/campaign-templates\.ts):'
}

# Feature IDs (an F, two digits and an optional -N suffix) in code.
check_feature_ids() {
    git grep -nwE 'F[0-9]{2}(-[0-9]+)?' -- includes apps tests scripts
}

# Every docs/*.md path cited outside plans, specs and skill evals exists. Those
# three describe history or name example files on purpose.
check_cited_docs() {
    git grep -ohE 'docs/[A-Za-z0-9_./-]+\.md' -- . ':!docs/plans/' ':!docs/specs/' ':!.claude/skills/*/evals/*' \
        | sort -u | while read -r p; do [ -f "$p" ] || echo "MISSING $p"; done
}

# Relative markdown links in harness docs resolve.
check_links() {
    git ls-files -- '*.md' | grep -E '^(CLAUDE|README)\.md$|^\.claude/|^docs/' | while read -r f; do
        grep -oE '\]\([^)[:space:]]+\)' "$f" | sed -E 's/^\]\(//; s/\)$//; s/#.*$//' | grep -vE '^(https?:|mailto:|$)' \
            | while read -r t; do [ -e "$(dirname "$f")/$t" ] || echo "BROKEN $f -> $t"; done
    done
}

# Every doc, decision record, rule and skill has Purpose and Update when.
check_headers() {
    for f in CLAUDE.md README.md docs/*.md docs/decisions/*.md .claude/rules/*.md .claude/skills/*/SKILL.md; do
        grep -q 'Purpose' "$f" && grep -q 'Update when' "$f" || echo "NO HEADER $f"
    done
}

# Each rule glob matches at least one tracked file.
check_rule_globs() {
    for r in .claude/rules/*.md; do
        sed -n '/^---$/,/^---$/p' "$r" | grep -oE '"[^"]+"' | tr -d '"' | while read -r g; do
            [ -n "$(git ls-files ":(glob)$g" | head -1)" ] || echo "DEAD GLOB $r $g"
        done
    done
}

# Skill names are valid and equal their directory. Descriptions fit in 500
# characters, because the skill listing cuts them at 511.
check_skills() {
    for d in .claude/skills/*/; do
        n=$(sed -n 's/^name: *//p' "$d/SKILL.md" | head -1)
        [ "$n" = "$(basename "$d")" ] && [[ "$n" =~ ^[a-z0-9-]+$ ]] || echo "BAD NAME $d"
        s=$(sed -n 's/^description: *//p' "$d/SKILL.md" | head -1)
        [ ${#s} -le 500 ] || echo "LONG DESCRIPTION $d (${#s})"
    done
}

# No @imports in CLAUDE.md. Backticked mentions are fine.
check_imports() {
    grep -nE '(^|[[:space:]])@[A-Za-z0-9_.~/-]+' CLAUDE.md | grep -v '`'
}

# Backticked repo paths in harness docs exist. Templates, path:line suffixes,
# the dev/edd branch and gitignored build output are allowed.
check_backticked_paths() {
    for f in CLAUDE.md README.md docs/*.md .claude/rules/*.md .claude/skills/*/SKILL.md; do
        grep -oE '`[A-Za-z0-9_.-]+/[A-Za-z0-9_./-]+(:[0-9,-]+)?`' "$f" | tr -d '`' | sed -E 's/:[0-9,-]+$//' \
            | grep -vE 'YYYY|^docs/specs/?$|^(origin/)?dev/edd$' \
            | while read -r p; do [ -e "$p" ] || git check-ignore -q "$p" || echo "MISSING $f -> $p"; done
    done
}

# Decision records are named YYYY-MM-DD-<slug>.md and have the four headings.
check_decisions() {
    for f in docs/decisions/*.md; do
        basename "$f" | grep -qE '^[0-9]{4}-[0-9]{2}-[0-9]{2}-[a-z0-9-]+\.md$' || echo "BAD NAME $f"
        for h in Status Context Decision Consequences; do grep -qx "## $h" "$f" || echo "NO $h $f"; done
    done
}

failed=0
for check in removed_refs section_citations feature_ids cited_docs links headers rule_globs skills imports backticked_paths decisions; do
    out=$("check_$check")
    if [ -n "$out" ]; then
        printf '%s\n' "$out" | sed "s/^/[$check] /"
        failed=1
    fi
done

[ "$failed" -eq 0 ] && echo "Harness checks passed."
exit "$failed"
