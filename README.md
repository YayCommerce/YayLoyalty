<!--
Purpose: the human front door: requirements, onboarding, the team workflow, when to use the agent tooling, and how to keep the harness current.
Update when: a requirement or a setup step changes, the review, promotion or release practice changes, or the harness setup changes.
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

   `vendor-prefixed/` (the scoped admin-shell) is committed, so the dev script only runs `composer install` when `vendor/` is missing, and never rebuilds `vendor-prefixed/`. The dev script ends by starting Vite on port 3030, which keeps running. To move to a newer admin-shell, run `./scripts/run.sh shell-update` and commit what it changes.

4. Activate the plugin in wp-admin. Activation installs the tables. `YAYLOYALTY_IS_DEVELOPMENT` defaults to `true`, so wp-admin loads the SPA from the dev server: keep `./scripts/run.sh dev` running while you work in the admin. To load a built bundle instead, define the constant as `false` in `wp-config.php`, then run `./scripts/run.sh build`.

5. Run the lint and test commands in [CLAUDE.md § Commands](CLAUDE.md#commands). Some fail today on `dev`; [docs/knowledge/known-issues.md](docs/knowledge/known-issues.md) lists each failure with the result to expect. Your result should match it.

6. Install the yay harness plugin once with `claude plugin install yay@yayharness`, then start Claude Code in the repo with `claude` and accept the trust dialog. Until you do, Claude ignores the command allowlist in `.claude/settings.json`. Then:
   - `CLAUDE.md` loads at the start of every session: the kit-managed workflow block and the project block;
   - a rule in `.claude/rules/` loads when Claude reads a file its `paths` match;
   - the `/yay:*` skills come from the plugin, and `.yay/config.json` holds the repo's commands, editions and generated paths.

## Daily workflow

The git rules are in [.claude/rules/yay-git.md](.claude/rules/yay-git.md): branch names, Conventional Commits, PR-only into `dev`. The parts only people do:

- **Review.** Every PR into `dev` needs one approval from a teammate other than its author, then it is squash-merged.
- **Promotion.** `dev` is the default branch and the only long-lived one, so a release is cut from `dev` itself. There is no promotion step.
- **Releases.** There is no release yet, and the cadence is set at the first one. Cut one with `/yay:release`. What the release builds and checks is in [docs/knowledge/build-release.md](docs/knowledge/build-release.md).

## Agent tooling

The repo uses the **yay** harness. Its skills take a change from idea to release tag; the table in [CLAUDE.md § How we work here](CLAUDE.md#how-we-work-here) says which skill does what, and [docs/yay-workflow.md](docs/yay-workflow.md) walks through one change with the exact prompts. Not sure where to start? Run `/yay:go`.

## Keeping the harness current

- **Kit-managed files** (the managed block of `CLAUDE.md`, `.claude/rules/yay-*.md`, `.claude/settings.json`, the PR template) come from the yay plugin. Don't edit them; run `/yay:harness-update`.
- **Project knowledge** (the project block of `CLAUDE.md` and `docs/knowledge/`) is written from the code. Each doc says when to update it; `/yay:knowledge-update` refreshes the stale ones.
- **Personal overrides** are gitignored, and the harness must work without them: `.claude/settings.local.json` and `CLAUDE.local.md`.

## License

GPLv2 or later. See the plugin header in `yayloyalty.php`.
