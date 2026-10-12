# `run.sh clean` exits 1 when no terminal answers its prompt

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the 2026-10-12 check of `cmd_clean` on branch `trace/chore/clear-small-backlog`, run on a scratch copy with stdin closed.

## Today

`scripts/run.sh` runs under `set -e`. After `cmd_clean` removes `assets/dist`, it asks `read -p "Remove node_modules? (y/N): " -n 1 -r`. With no terminal, as when Claude, CI or a pipe runs it, `read` hits end of input and returns non-zero. `set -e` then ends the run with exit 1, before `print_success "Clean complete"`. The clean itself has already happened.

## Why it matters

A clean that worked reports failure. A script or agent that chains on `./scripts/run.sh clean && …` stops there. `.claude/rules/release.md` says every prompt in `run.sh` needs a way around it, and `release` already has one (`--no-prompt`), but `clean` has none.

## Suggested fix

Give `cmd_clean` the same escape `release` has. For example, skip the prompt and keep `node_modules` when stdin isn't a terminal (`[ -t 0 ]`), or when `--no-prompt` is passed. It was out of scope for the backlog sweep that found it.

## Done when

- [ ] On a scratch copy holding `scripts/run.sh` and a stub `yayloyalty.php`, `bash scripts/run.sh clean </dev/null` exits 0 and prints `Clean complete`.
- [ ] Run the same way, it leaves `apps/admin/node_modules` in place when that directory exists.
