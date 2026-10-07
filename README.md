<!--
Purpose: the human front door: requirements, onboarding, the team workflow, when to use the agent tooling, and how to keep the harness current.
Update when: a requirement or a setup step changes, the review, promotion or release practice changes, a skill is added, renamed or removed, or a harness file gains or loses a topic in the owner map.
-->

# YayLoyalty: Points, Rewards, Referral & VIP for WooCommerce

A loyalty plugin for WooCommerce: points, rewards, referrals and VIP tiers. One repository ships a Pro and a Lite build.

## Requirements

- A WordPress site with WooCommerce active. The minimum WordPress, WooCommerce and PHP versions are in the plugin header of `yayloyalty.php`.
- PHP 8.2 or later for the dev tooling. The product itself must run on PHP 7.4.
- Composer 2.
- Node 22, with pnpm through Corepack. `package.json` pins the pnpm version.
- A GitHub account with read access to the private [admin-shell repository](https://github.com/YayCommerce/yaycommerce-plugin-admin-shell). Composer clones it over HTTPS.
- WP-CLI, for `pnpm makepot` and the release validator's optional boot check.
- `jq`, which the Claude Code guard hook needs.
- Claude Code 2.1.283 or later.

## Onboarding

1. Clone into the site's plugins directory:

   ```bash
   cd <site>/wp-content/plugins
   git clone https://github.com/YayCommerce/YayLoyalty.git yayloyalty
   cd yayloyalty
   ```

2. Switch to Node 22 and enable pnpm:

   ```bash
   nvm use
   corepack enable
   ```

3. Run the dev script, then install from a second terminal:

   ```bash
   ./scripts/run.sh dev
   pnpm install
   ```

   On a fresh clone, `pnpm install` fails until the dev script has created `vendor-prefixed/` ([known issue](docs/known-issues.md#a-fresh-clones-first-install-fails-on-vendor-prefixedsrc)). The dev script ends by starting Vite on port 3030, which keeps running. Run `git status` once it has started: the dev script can rewrite `composer.json` and `scoper.inc.php`.

4. Activate the plugin in wp-admin. Activation installs the tables. `YAYLOYALTY_IS_DEVELOPMENT` defaults to `true`, so wp-admin loads the SPA from the dev server: keep `./scripts/run.sh dev` running while you work in the admin. To load a built bundle instead, define the constant as `false` in `wp-config.php`; the build fails today ([known issue](docs/known-issues.md#type-check-build-and-release-stop-at-missing-modules)).

5. Run the lint and test commands in [CLAUDE.md § Commands](CLAUDE.md#commands). Several fail today on `dev/edd`; [docs/known-issues.md](docs/known-issues.md) lists each failure with the output to expect. Your result should match it exactly.

6. Start Claude Code in the repo with `claude`, and accept the trust dialog. Until you do, Claude ignores the command allowlist in `.claude/settings.json`. Then:
   - `CLAUDE.md` loads at the start of every session;
   - a rule in `.claude/rules/` loads when Claude reads a file its `paths` match;
   - the skills in `.claude/skills/` show up in the skill list, and each loads when you call it or your request matches its description;
   - the guard hook in `.claude/hooks/` stops Claude's file tools from editing generated directories.

## Daily workflow

The git rules are in [CLAUDE.md § Git](CLAUDE.md#git): branch names, Conventional Commits, PR-only into `dev/edd`, and what an agent may never do. The parts only people do:

- **Review.** Every PR into `dev/edd` needs one approval from a teammate other than its author, then it is squash-merged.
- **Promotion.** Trace promotes `dev/edd` to `main` before each release, by a PR with a merge commit.
- **Releases.** There is no release yet, and the cadence is set at the first one. The release commands are in [CLAUDE.md § Commands](CLAUDE.md#commands). What the release builds and checks is in [docs/architecture.md § Build and release](docs/architecture.md#build-and-release), and the rules for changing it are in [.claude/rules/release.md](.claude/rules/release.md).

## Agent tooling

The team's skills cover a change from idea to PR. Each one is called by name, and its `SKILL.md` holds the details:

| Skill | Run it when | It writes |
|---|---|---|
| [`/yaybrainstorming`](.claude/skills/yaybrainstorming/SKILL.md) | you want to design a feature, a behaviour change, a refactor or an integration before planning it | a design doc in `docs/specs/` |
| [`/yayplan`](.claude/skills/yayplan/SKILL.md) | you have an accepted design doc, or a request you want planned without one | a plan in `docs/plans/` |
| [`/yaytidy`](.claude/skills/yaytidy/SKILL.md) | before you open a PR | a report of duplicated facts and code, and the fixes you pick |
| [`/yayplancheck`](.claude/skills/yayplancheck/SKILL.md) | after each phase of a plan, and before merging | a PASS or FAIL review against the plan, from a fresh subagent |
| [`/yaybacklog`](.claude/skills/yaybacklog/SKILL.md) | you want to see what reviews left open, check whether an item is done, or record a finding you won't fix in this PR | ticked boxes and new items in `docs/backlog/`, and removes the items that are done |

A typical change runs `/yaybrainstorming`, then `/yayplan`, then you implement it, running `/yayplancheck` after each phase. Before the final review, `/yayplancheck` asks whether to run `/yaytidy` first. A change may also skip the design and go straight to `/yayplan`. A review finding you don't fix in the PR goes to the backlog with `/yaybacklog add`, and the PR that fixes it later ticks its boxes with `/yaybacklog check`.

**Status lifecycle.** Specs and plans stay in the repo as history. An agent acts only on a spec whose Status is `accepted` or a plan whose Status is `active`. The Status words and when each one is set belong to the templates: [the design template](.claude/skills/yaybrainstorming/references/design-template.md) for specs, and [the plan template](.claude/skills/yayplan/references/plan-template.md) for plans.

**Decision records.** Write one when a change makes or reverses a choice that is costly to undo and that the code can't justify by itself. [The decision-record practice](docs/decisions/2026-10-04-decision-record-practice.md) gives the examples, the file name and the four headings. The record lands in the PR that makes the change.

## Keeping the harness current

**Owner map.** Each topic has one home. Other files only link to it.

| Topic | Home |
|---|---|
| Daily commands, hard rules, git rules | [CLAUDE.md](CLAUDE.md) |
| PHP, SPA and release-tooling rules | [.claude/rules/](.claude/rules/) |
| How the pieces fit | [docs/architecture.md](docs/architecture.md) |
| Conventions the code can't show | [docs/conventions.md](docs/conventions.md) |
| Gotchas and checks that fail today | [docs/known-issues.md](docs/known-issues.md) |
| Follow-ups that reviews left open | [docs/backlog/](docs/backlog/) |
| Why X, not Y | [docs/decisions/](docs/decisions/) |
| Spec and plan formats and their Status words | the `yaybrainstorming` and `yayplan` skills |
| Reviewing work against its plan | the `yayplancheck` skill |
| The backlog item format, and checking items against the code | the `yaybacklog` skill |
| Duplicated facts before a PR, and the stacked-branch PR base | the `yaytidy` skill |
| Onboarding, review, promotion and release cadence, agent tooling, harness upkeep | this README |

**Update-when triggers.** Every harness file opens with a Purpose line and an Update when line: in an HTML comment in `CLAUDE.md`, this README and the rules, in the first lines of each doc and decision record, and in YAML comments in each skill's frontmatter. When your change matches a file's trigger, update that file in the same PR.

**Personal overrides.** These are gitignored, and the harness must work without them:
- `.claude/settings.local.json`: your own permissions and settings on top of `.claude/settings.json`;
- `CLAUDE.local.md`: your own instructions on top of `CLAUDE.md`. It may drop the commit and PR trailers that `CLAUDE.md` asks for.

**Shared skills.** The YayCommerce plugins share the five skills, and this repo holds their canonical copy. A change to a skill lands here first, and another repo copies the skill's directory whole, never editing its copy. See [the canonical-copy record](docs/decisions/2026-10-03-shared-skills-canonical-copy.md).

**Mechanical checks.** Run `./scripts/check-harness.sh` before merging a PR that changes a harness file. It checks paths, links, headers, rule globs, skill names and decision records, prints one line per finding, and exits 1 if there is any.

**Prompt audit.** Run `/doctor prompt-audit` in Claude Code after upgrading Claude Code and before merging a PR that changes `CLAUDE.md`, a rule or a skill. Every finding gets a fix, or a reason to keep the text.

## License

GPLv2 or later. See the plugin header in `yayloyalty.php`.
