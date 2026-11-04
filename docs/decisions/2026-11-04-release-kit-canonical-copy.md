# The release kit's canonical copy lives in YayCommerce/Yay-Release-Kit

**Purpose:** where the one copy of the release skills, their scripts and RELEASING.md is kept and changed, and how plugin repos take it.

**Update when:** never edit it. Supersede it with a new record when the release kit's canonical copy moves or the rule for copying it changes.

## Status

Accepted, 2026-11-04, by Trace Bui. It supersedes no decision: [the canonical-copy record](2026-10-03-shared-skills-canonical-copy.md) still holds for the five planning and review skills, and this record covers only the release kit, which that record never named.

## Context

- The release kit is the eight skills `yayinit`, `yaystatus`, `yaybuild`, `yayrelease`, `yayrelease-fix`, `yayfinalize`, `yaysupport` and `yayorchestrate`, the `yayrelease-worker` agent, `.claude/release-kit/` and `RELEASING.md`. Every YayCommerce plugin repo is meant to use it, most of them with several edition branches.
- Its first copy was written in this repo, on the branch of PR #173, following the canonical-copy record.
- Unlike the planning skills, the kit is mostly scripts that are tried against several plugin repos at once, and it has its own version (`.claude/release-kit/VERSION`) that each copy reports. Keeping it inside one plugin repo would tie its changes and versions to that plugin's PRs.
- Options turned down:
  - keeping the canonical copy here: kit changes would go through YayLoyalty PRs and its release practice;
  - a Claude Code plugin from the kit repo instead of copies: skills would be namespaced (`/<plugin>:yaybuild`), and each repo's committed copy would no longer show which kit version its releases used.

## Decision

- The canonical copy of the release kit is YayCommerce/Yay-Release-Kit. A change lands there first, by PR, and gets a version and a tag.
- A plugin repo takes the kit with that repo's `install` script (`yr kit sync`), whole, and commits it identically on every edition dev branch. It never edits its copy.
- A repo's own release facts (editions, branches, build commands, version locations) stay in its `.claude/release.config.json`, never in the kit.
- This repo is a consumer of the kit like any other.

## Consequences

- One place to fix and review the kit, with versions every copy reports; `/yaystatus` shows an old copy.
- A copy lags until someone runs `install` and commits the update on each dev branch.
- A change found while using a copy in a plugin repo goes to Yay-Release-Kit first; an edit made in a copy is lost at the next sync.
