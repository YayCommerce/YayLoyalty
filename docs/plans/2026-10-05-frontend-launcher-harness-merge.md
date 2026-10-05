# Frontend launcher branch: finish the dev/edd merge onto the new harness

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. There is no spec; the Requirements section below is what this plan argues from.

**Status:** active
**Goal:** finish merging `dev/edd` (at `957cc74`, PR #43) into `paul/feat/frontend-launcher`, so the branch runs on the new harness and loses none of the storefront launcher facts Paul wrote into the old docs.
**Spec:** none, planned from the request
**Architecture:** 9 conflicts. 5 are old-harness docs that Paul edited and `dev/edd` deleted (`UD`); they stay deleted. `README.md` takes `dev/edd`'s side whole: the new README is the harness guide, and its old product sections are gone. The 3 code conflicts are unions of both sides, with `dev/edd`'s `CART_DISCOUNT_SLUG` as the one name for the built-in reward's slug. The launcher's mechanism, which lived in the old README and docs, moves to `docs/architecture.md`; its owner rows already sit in code docblocks. The merge commit holds only the resolution, and each fix after it is its own commit.
**Touches:** `README.md`, `includes/Utils/Dates.php`, `includes/Repositories/RewardRepository.php`, `includes/Database/Installer.php`, `includes/Engine/Storefront/` (PHP, templates), `includes/Controllers/StorefrontActivityRestController.php`, `assets/storefront/launcher.js`, `apps/admin/src/styles/storefront-launcher-layout.css`, `apps/admin/src/pages/settings/DesignLauncherPreview.tsx`, `docs/architecture.md`, `docs/known-issues.md`, `CLAUDE.md`; deletes `docs/code-standards.md`, `docs/codebase-summary.md`, `docs/project-changelog.md`, `docs/project-roadmap.md`, `docs/system-architecture.md`

## Requirements

- **Goal:** `paul/feat/frontend-launcher` merges cleanly into `dev/edd` on the new harness, with the launcher and the points earned email both working.
- **Out of scope:**
  - the launcher's behaviour;
  - restoring tests (the "No tests" entry in `docs/known-issues.md` still holds);
  - the changelog, roadmap and test counts Paul added to the old docs: the new harness keeps none of them;
  - pushing and opening the PR: the user does that in GitHub Desktop.
- **Acceptance criteria:**
  1. `git diff --name-only --diff-filter=U` prints nothing, no conflict marker is left, and none of the 5 old docs is tracked.
  2. `git grep -n BUILTIN_DISCOUNT_SLUG -- includes` prints nothing. `RewardRepository` has `CART_DISCOUNT_SLUG`, `points_per_currency_unit()`, `live_points_per_currency_unit()` and `public static rate_of( ?Reward $reward ): float`. `Dates` has both `site_day_to_utc()` and Paul's `wp_date()`-based `to_site_tz()`.
  3. No comment in the launcher's code cites a doc, a feature ID or a design artboard (`docs/conventions.md` § Comments).
  4. `docs/architecture.md` describes the launcher's boot, request flow, shopper REST route, Design tab preview and CSS build, and no longer says every route's permission is a capability.
  5. `CLAUDE.md` names `assets/dist/` as generated output and says `build` also builds the storefront CSS.
  6. `./scripts/check-harness.sh` prints `Harness checks passed.` and exits 0.
  7. End to end: `git diff origin/dev/edd --stat -- CLAUDE.md README.md .claude docs` lists only `CLAUDE.md`, `docs/architecture.md`, `docs/known-issues.md` and this plan, and a signed-in shopper sees the launcher with their balance on the shop page.
- **Decided with the user (2026-10-05):**
  - The slug constant keeps `dev/edd`'s name, `CART_DISCOUNT_SLUG`; the launcher's one use is renamed.
  - Every design-artboard ID in the launcher's comments goes, not only the ones the harness check flags.
  - `CLAUDE.md` is fixed in this merge, with a prompt audit.

## Global constraints

- Product PHP must run on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types. The PHP changes here are a merge union and comments.
- No comment cites another document's path or section number, a feature ID or a design-artboard ID (`docs/conventions.md` § Comments).
- Docs cite code by path and symbol, never by line number.
- `CLAUDE.md` stays under 100 lines.
- Never commit to `dev/edd` or `main` directly. Commit messages follow Conventional Commits, `type(scope): summary`, and end with the `Co-Authored-By` trailer that `CLAUDE.md` asks for.
- Check `git status`, and stage files by name.
- Don't push. The user publishes the branch in GitHub Desktop.

## Review focus

- **Two writers of one rate reader:** `RewardRepository` must keep `dev/edd`'s `live_points_per_currency_unit()` (the points earned email shows money only for a live reward) and Paul's public `rate_of()` (the launcher reads the rate from the reward it already loaded). Dropping either side breaks the other feature: `PointsEarnedEmail` or `LauncherPayload::redeem_rule()` fatals. Task 1, step 6 checks both callers.
- **Installer seed:** `seed_builtin_reward()` must still seed slug `cart-discount`. A wrong constant there fatals during `Installer::install()`, which every version bump re-runs. Task 1, step 6 runs `php -l` and greps the constant.
- **No old doc comes back:** a stray `git checkout --ours` would restore an old doc, or Paul's old README sections, which the harness check then flags. Task 1, steps 1 and 2 check it.
- **Facts written from memory:** every claim moved into `docs/architecture.md` must hold in the merged tree. Task 3, step 1 checks each against the code before it is written; the "sandboxed iframe" claim from Paul's owner row is the likeliest to be wrong.

---

### Task 1: Resolve the conflicts and conclude the merge

**Files:**
- Delete: `docs/code-standards.md`, `docs/codebase-summary.md`, `docs/project-changelog.md`, `docs/project-roadmap.md`, `docs/system-architecture.md`
- Resolve: `README.md`, `includes/Utils/Dates.php`, `includes/Repositories/RewardRepository.php`, `includes/Database/Installer.php`
- Modify: `includes/Engine/Storefront/LauncherPayload.php`, `for_balance()` (one constant name)

**Interfaces:**
- Produces: a merge commit whose second parent is `957cc74`, with no unmerged paths.
- Produces, in `YayLoyalty\Repositories\RewardRepository`:
  - `public const CART_DISCOUNT_SLUG = 'cart-discount';`
  - `public function points_per_currency_unit(): float`
  - `public function live_points_per_currency_unit(): float`
  - `public static function rate_of( ?Reward $reward ): float` (0.0 for null)

- [x] **Step 1: Keep dev/edd's deletion of the 5 old docs**

Run: `git rm -q docs/code-standards.md docs/codebase-summary.md docs/project-changelog.md docs/project-roadmap.md docs/system-architecture.md`
Then run `git ls-files docs | grep -E 'code-standards|codebase-summary|project-(changelog|roadmap)|system-architecture'`. Expected: no output.

- [x] **Step 2: Take dev/edd's README whole**

Run: `git checkout --theirs README.md && git add README.md`
Then run `git diff 957cc74 -- README.md`. Expected: no output. Paul's launcher sections move to `docs/architecture.md` in Task 3.

- [x] **Step 3: Resolve `includes/Utils/Dates.php` as a union**

Keep `dev/edd`'s `site_day_to_utc()` with its docblock, then `to_site_tz()` with Paul's docblock ("A UTC DATETIME in the site's timezone, for display only. Through wp_date() …") in place of `dev/edd`'s `/** For display only. */`. The body of `to_site_tz()` is already Paul's (`wp_date()`), outside the conflict.

- [x] **Step 4: Resolve `includes/Repositories/RewardRepository.php` as a union**

- Class docblock: `/** yayloyalty_rewards. No cache yet: the hottest reads are one indexed slug lookup per storefront page and one per points earned email. */`
- Constant: `CART_DISCOUNT_SLUG`, with `dev/edd`'s docblock. Drop `BUILTIN_DISCOUNT_SLUG`.
- `points_per_currency_unit()`: `return self::rate_of( $this->find_by_slug( self::CART_DISCOUNT_SLUG ) );`
- `live_points_per_currency_unit()`: `dev/edd`'s, unchanged.
- `rate_of()`: Paul's signature, docblock and body (`public static`, takes `?Reward`, returns 0.0 for null). `live_points_per_currency_unit()` passes it a non-null reward, so it still works.
- Keep `use YayLoyalty\Utils\Dates;` at the top, which `dev/edd` added outside the conflict.

- [x] **Step 5: Resolve the Installer and rename the launcher's use**

- `includes/Database/Installer.php`, `seed_builtin_reward()`: keep the `dev/edd` line, `\YayLoyalty\Repositories\RewardRepository::CART_DISCOUNT_SLUG,`. Edit the hunk; don't `checkout --theirs` the file.
- `includes/Engine/Storefront/LauncherPayload.php`, `for_balance()`: `RewardRepository::BUILTIN_DISCOUNT_SLUG` becomes `RewardRepository::CART_DISCOUNT_SLUG`.

- [x] **Step 6: Verify the resolution**

Run: `git grep -n -E '^(<<<<<<<|=======|>>>>>>>)' -- includes README.md`. Expected: no output.
Run: `php -l` on `includes/Utils/Dates.php`, `includes/Repositories/RewardRepository.php`, `includes/Database/Installer.php` and `includes/Engine/Storefront/LauncherPayload.php`. Expected: `No syntax errors detected` for each.
Run: `git grep -n -E 'BUILTIN_DISCOUNT_SLUG|CART_DISCOUNT_SLUG|rate_of\(|live_points_per_currency_unit' -- includes`. Expected: no `BUILTIN_DISCOUNT_SLUG`; `CART_DISCOUNT_SLUG` in `RewardRepository`, `Installer` and `LauncherPayload`; `RewardRepository::rate_of( $reward )` in `LauncherPayload::redeem_rule()`; `live_points_per_currency_unit()` in `PointsEarnedEmail`. `PurchaseHandler::rate_of` is a different method and stays.
Run: `sed -n '/protected function __construct/,/^    }/p' includes/YayLoyalty.php`. Expected, in order: emails, YayMail, the `is_pro()` block, `register_earning_listeners()`, then `Launcher::register()` inside `if ( ! is_admin() )`.

- [x] **Step 7: Stage and check what is left**

Stage: `README.md`, `includes/Utils/Dates.php`, `includes/Repositories/RewardRepository.php`, `includes/Database/Installer.php`, `includes/Engine/Storefront/LauncherPayload.php`
Run: `git diff --name-only --diff-filter=U`. Expected: no output.
Run: `./scripts/check-harness.sh`. Expected: exit 1 with exactly 5 findings, all fixed in Task 2: `removed_refs` and `section_citations` for `UiClasses.php` and `StorefrontActivityRestController.php`, and `feature_ids` for `Launcher.php`. Before step 1 it also reported findings from the 5 old docs.

- [x] **Step 8: Commit the merge**

Message: keep Git's `Merge branch 'dev/edd' into paul/feat/frontend-launcher`, drop the `# Conflicts:` lines, and add a body of two lines: "Keep dev/edd's removal of the old harness docs and its README; the launcher's facts move to architecture.md in a later commit." and "RewardRepository keeps dev/edd's CART_DISCOUNT_SLUG and live rate, and the launcher's public rate_of()." Then the trailer.

### Task 2: Drop doc, feature and artboard citations from the launcher's comments

**Files:**
- Modify: comments only, in the files listed in step 1.

**Interfaces:**
- Consumes: the merge from Task 1.
- Produces: nothing that code consumes.

- [x] **Step 1: Reword each comment**

The rule for every line: delete the parenthetical ID or citation. Where the ID is the subject, name what it stands for instead: 1a is the floating button, 1b the member panel, 1c the guest panel, 1e mobile. The reason the comment gives stays.

| File | Symbol or line | Becomes |
|---|---|---|
| `includes/Controllers/StorefrontActivityRestController.php` | class docblock | drop `(code-standards.md § 9)` and `(design 1b, note 3)` |
| `includes/Engine/Storefront/UiClasses.php` | class docblock | `Allowed mirror: code-standards.md § 16.` becomes `button.tsx owns these lists; change both together.` |
| `includes/Engine/Storefront/Launcher.php` | class docblock | drop `(designs 1a, 1b, 1c, 1e)`; `Redeeming arrives with F08.` becomes `Redeeming is not built yet.` |
| `includes/Engine/Storefront/LauncherPayload.php` | `product_estimate()` docblock | drop `(design 1c, note 3)` |
| `includes/Engine/Storefront/LauncherPreview.php` | `$is_member` param; the sample-history docblock | `A signed-in sample shopper, or a guest.`; `The member panel's sample history, newest first: …` |
| `includes/Engine/Storefront/templates/launcher.php` | file docblock | `the floating button, the overlay and the panel frame (a bottom sheet on mobile).` |
| `includes/Engine/Storefront/templates/panel-guest.php`, `panel-member.php` | file docblock | drop `(1c)`; drop `(1b; 1e on mobile)` |
| `assets/storefront/launcher.js` | bottom sheet handle docblock | drop `(1e)` |
| `apps/admin/src/styles/storefront-launcher-layout.css` | 5 comments | drop `(1a)`, `(1b, 1c)` twice and `(1e)`; `1e differs from the desktop panel` becomes `On mobile the launcher differs from the desktop panel` |
| `apps/admin/src/pages/settings/DesignLauncherPreview.tsx` | component docblock | drop `(designs 1a–1c, 1e)` |

- [x] **Step 2: Verify**

Run: `grep -rnE 'F[0-9]{2}\b|[Dd]esigns? 1[a-e]|\b1[a-e]\b|note [0-9]|\.md\b|§' includes/Engine/Storefront includes/Controllers/StorefrontActivityRestController.php assets/storefront apps/admin/src/styles/storefront-launcher-layout.css apps/admin/src/pages/settings/DesignLauncherPreview.tsx`. Expected: no output.
Run: `git diff --stat HEAD`. Expected: only the files in the step 1 table, and `git diff HEAD` shows comment lines only.
Run: `php -l` on each changed PHP file. Expected: `No syntax errors detected`.
Run: `./scripts/check-harness.sh`. Expected: `Harness checks passed.` and exit 0.

- [x] **Step 3: Commit**

Stage: the files in the step 1 table, by name.
Message: `docs(storefront): drop doc, feature and design citations from comments`

### Task 3: Move the launcher's facts into `docs/architecture.md`

**Files:**
- Modify: `docs/architecture.md`, § Boot and autoload, § Server layers and write ownership, § SPA and WordPress, § Build and release
- Modify: `docs/known-issues.md`, § Type-check, build and release stop at missing modules (the workaround)

**Interfaces:**
- Consumes: the merge from Task 1.
- Produces: nothing that code consumes.

- [x] **Step 1: Check the facts against the code before writing them**

Each fact below must hold in the merged tree; drop any that doesn't:
- `YayLoyalty::__construct()` calls `Engine\Storefront\Launcher::register()` last, only when `! is_admin()`. `register()` hooks `prepare` on `wp`, `enqueue` on `wp_enqueue_scripts` and `render` on `wp_footer`.
- `Launcher::prepare()` decides once: `LauncherVisibility::is_visible()`, then `ProgramGate::program_enabled()` for a guest or `ProgramGate::check()` for a member. An exception hides the launcher and is logged.
- `LauncherPayload::for_balance()` builds the view, rendered server-side through `Launcher::markup()` and `templates/`; nothing is sent as JSON or an inline data script. `hide_on_mobile` is a CSS media query.
- `StorefrontActivityRestController` serves `GET /me/activity` with `is_signed_in()` (`is_user_logged_in()`) as its permission, no user id param, `ROWS` rows, through `ShopperActivityRow`, which has no note, actor or campaign id. `launcher.js` fetches it on the first open with the `wp_rest` nonce.
- `POST /design/preview` (`DesignRestController`, `MANAGE_SETTINGS`) returns `LauncherPreview::documents()`, which `DesignLauncherPreview.tsx` shows. Confirm from that component whether it is a sandboxed iframe before saying so. `DESIGN_PREVIEW_FIELDS` in `apps/admin/src/lib/api/design.api.ts` mirrors the fields `LauncherPayload::for_balance()` reads.
- `apps/admin/package.json`'s `build` ends with `build:storefront`, which builds `apps/admin/src/storefront.css` through `vite.storefront.config.ts` into `assets/dist/storefront/launcher.css`. `storefront.css` has no preflight and scans only `includes/Engine/Storefront` and `assets/storefront` (`@source`). `./scripts/run.sh dev` builds it once; `pnpm dev:storefront` rebuilds it on change.

- [x] **Step 2: Edit § Boot and autoload**

In "The order is: …", append "and last, on front-end requests only, the storefront launcher (`Engine\Storefront\Launcher::register()`)".

- [x] **Step 3: Edit § Server layers and write ownership**

- In the bullet "Every route's permission callback is `requires()` with one capability", add the exception: shopper routes under `/me/` (`StorefrontActivityRestController`, `GET /me/activity`) check only that the visitor is signed in (cookie auth with the `wp_rest` nonce), take no user id, so there is nothing to enumerate, and return a shopper-safe projection (`ShopperActivityRow`).
- After the YayMail bullet in the domain list, add a **Storefront** bullet: `includes/Engine/Storefront/` draws the read-only launcher on the shop, from the facts step 1 confirmed: the three hooks, the one decision in `prepare()`, the server-rendered view, CSS-only `hide_on_mobile`, and the lazy `/me/activity` fetch. End with the cache caveat: the balance is printed into the page for a signed-in shopper, so a page cache that serves signed-in visitors can show one shopper's balance to another; common caches skip signed-in visitors by default.

- [x] **Step 4: Edit § SPA and WordPress**

Add a bullet: the Design tab's launcher preview is the server's own markup, from `POST /design/preview` (`LauncherPreview::documents()`, a sample shopper at the real rates), not a React copy. `DESIGN_PREVIEW_FIELDS` names the design fields that redraw it and mirrors `LauncherPayload::for_balance()`. Use the step 1 wording for how it is shown.

- [x] **Step 5: Edit § Build and release**

- The `./scripts/run.sh build` bullet: after the SPA build, `build` runs `build:storefront`: the storefront CSS from the step 1 facts, one file for both editions, scoped to `.yayloyalty-launcher` with the `yl:` prefix so the shop theme and the launcher leave each other alone. Tailwind finds classes only by scanning the templates, `UiClasses` and `launcher.js`, so a class there is written out in full, never assembled at runtime. The Vite dev server doesn't serve it.
- The release-order bullet: "The two runs share `assets/dist/admin/`" becomes "share `assets/dist/`".

- [x] **Step 6: Edit `docs/known-issues.md`**

In § Type-check, build and release stop at missing modules, the workaround becomes: "For a local bundle, run the `vite build` half of the `build` script by hand, then `pnpm build:storefront` in `apps/admin` for the launcher's CSS."

- [x] **Step 7: Verify**

Run: `./scripts/check-harness.sh`. Expected: exit 0.
Run: `git grep -n -E 'with one capability' -- docs/architecture.md`. Expected: the bullet now names the `/me/` exception.

- [x] **Step 8: Commit**

Stage: `docs/architecture.md`, `docs/known-issues.md`
Message: `docs(architecture): describe the storefront launcher and its CSS build`

### Task 4: Name the storefront build in `CLAUDE.md`

**Files:**
- Modify: `CLAUDE.md`, § Commands (the `build` line) and § Hard rules (rule 1)

**Interfaces:**
- Consumes: Task 3's § Build and release.
- Produces: nothing that code consumes.

- [x] **Step 1: Edit the two lines**

- `build`: `type-check, then build the SPA into \`assets/dist/admin/\` and the storefront launcher's CSS into \`assets/dist/storefront/\`. [Fails today](docs/known-issues.md).`
- Rule 1: `assets/dist/admin/` becomes `assets/dist/`, which `.claude/hooks/guard-generated-paths.sh` already guards.

- [ ] **Step 2: Verify**

Done 2026-10-05: `wc -l CLAUDE.md` gives 56, and the harness check exits 0. Pending: the prompt audit, which only the user can run.

Run: `wc -l CLAUDE.md`. Expected: under 100.
Run: `./scripts/check-harness.sh`. Expected: exit 0.
Run `/doctor prompt-audit` in Claude Code, as README § Keeping the harness current asks for a `CLAUDE.md` change. Expected: every finding on the two changed lines gets a fix or a reason to keep the text. Findings elsewhere were on `dev/edd` already; leave them.

- [ ] **Step 3: Close the plan**

Once the final verification below passes, set this plan's Status to `done <date>` and tick every step.

- [x] **Step 4: Commit**

Stage: `CLAUDE.md`, `docs/plans/2026-10-05-frontend-launcher-harness-merge.md`
Message: `docs(claude): name the storefront CSS build and its generated output`

### Final verification

If a check fails, set Status back to `active` and fix it in a new commit.

- [x] Run `git diff origin/dev/edd --stat -- CLAUDE.md README.md .claude docs`. Expected: only `CLAUDE.md`, `docs/architecture.md`, `docs/known-issues.md` and `docs/plans/2026-10-05-frontend-launcher-harness-merge.md`.
- [x] Run `./scripts/check-harness.sh`. Expected: exit 0.
- [x] Run `composer lint -- includes/Repositories/RewardRepository.php includes/Utils/Dates.php includes/Database/Installer.php includes/Engine/Storefront includes/Controllers/StorefrontActivityRestController.php`. Expected: no finding on a line this plan changed. `composer lint` exits 1 on `dev/edd` with no errors (`docs/known-issues.md`), so judge by the findings, not the exit code.
- [x] By hand, with `pnpm build:storefront` run in `apps/admin` first: sign in to the Local site as a customer who is not staff and open the shop page. Expected: the launcher shows, its panel shows the balance and its worth, and opening it loads up to 3 activity rows. In wp-admin, the Design tab's launcher preview draws. Done 2026-10-05 with an auth cookie for a customer with 18,400 points: the shop page printed the balance and "worth £184", `GET /me/activity` returned 200 with that customer's rows (401 for a guest), and `LauncherPreview::documents()` returned both documents.
- [ ] Complete a test order for that customer. Expected: the points earned email still sends with its money line, as on `dev/edd`. Not run, so no order was created on the site; `live_points_per_currency_unit()`, which the email reads, returns 100.
- [x] Run `git status`. Expected: clean, with 4 commits on top of `origin/paul/feat/frontend-launcher`: the merge and three fixes.
