# Email branch: finish the dev/edd merge onto the new harness

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. There is no spec; the Requirements section below is what this plan argues from.

**Status:** active
**Goal:** finish merging `dev/edd` (at `9bb3cfd`, PR #63) into `paul/feat/email-points-earned`, so the branch runs on the new harness and loses none of the facts Paul wrote into the old docs.
**Spec:** none, planned from the request
**Architecture:** the 5 conflicts are old-harness docs that Paul edited and `dev/edd` deleted (`UD`). They stay deleted. The email and YayMail facts Paul added to them move to their owners in the new harness: the mechanism goes to `docs/architecture.md`, and the owner of a copied text goes into a code docblock. Changelog, roadmap, test counts and shortcode lists are dropped: the new harness keeps none of them. The merge commit holds only the resolution, and each fix after it is its own commit, so a reviewer can tell the merge apart from new work.
**Touches:** `docs/architecture.md`, `tests/integration/bootstrap.php`, `includes/Integrations/YayMail/PointsEarnedYayMailEmail.php`; deletes `docs/code-standards.md`, `docs/codebase-summary.md`, `docs/project-changelog.md`, `docs/project-roadmap.md`, `docs/system-architecture.md`

## Requirements

- **Goal:** `paul/feat/email-points-earned` merges cleanly into `dev/edd` on the new harness, and its integration bootstrap loads.
- **Out of scope:**
  - the email feature's behaviour;
  - restoring the integration tests Paul removed in `f4290fe` (the "No tests" entry in `docs/known-issues.md` still holds);
  - pushing and opening the PR: the user does that in GitHub Desktop.
- **Acceptance criteria:**
  1. `git diff --name-only --diff-filter=U` prints nothing, and none of the 5 old docs is tracked.
  2. `./scripts/check-harness.sh` prints `Harness checks passed.` and exits 0.
  3. `docs/architecture.md` describes the points earned email's send path and the YayMail integration, lists the integration in the boot order, and describes the one integration fixture trait that remains.
  4. No tracked file references `WooCommerceEmailsFixture`, and `composer test:integration` no longer fails with `Failed opening required`.
  5. The docblock of `PointsEarnedYayMailEmail::get_default_elements()` names the code that owns its wording.
  6. End to end: `git diff origin/dev/edd --stat -- CLAUDE.md README.md .claude docs` lists only `docs/architecture.md` and this plan, so the branch changes no other harness file.
- **Decided with the user (2026-10-04):**
  - The merge also fixes the integration bootstrap, which on Paul's branch still requires the deleted `WooCommerceEmailsFixture.php`: yes.
  - The merge also adds a YayMail owner marker, carrying over the owner row from the deleted `code-standards.md`: yes.

## Global constraints

- Product PHP must run on PHP 7.4. The changes here touch comments and a `require_once` line only.
- No comment cites another document's path or section number, a feature ID or a design-artboard ID (`docs/conventions.md` § Comments).
- Docs cite code by path and symbol, never by line number.
- Never commit to `dev/edd` or `main` directly. Commit messages follow Conventional Commits, `type(scope): summary`, and end with the `Co-Authored-By` trailer that `CLAUDE.md` asks for.
- Check `git status`, and stage files by name.
- Don't push. The user publishes the branch in GitHub Desktop.

## Review focus

- **Auto-merged constructor:** `includes/YayLoyalty.php` has to keep both Paul's `YayMailIntegration::get_instance()` call and `dev/edd`'s comment on earning gates, with Emails, then YayMail, before the Pro seam. Task 1, step 2 checks it.
- **No old doc comes back:** a stray `git checkout --ours` would restore an old doc that the harness check then flags. Task 1, step 1 checks it.
- **The "sends at once" claim:** it has to match the code. Task 2, step 1 checks it against the trigger path before the line is written.

---

### Task 1: Resolve the conflicts and conclude the merge

**Files:**
- Delete: `docs/code-standards.md`, `docs/codebase-summary.md`, `docs/project-changelog.md`, `docs/project-roadmap.md`, `docs/system-architecture.md`

**Interfaces:**
- Produces: a merge commit whose second parent is `9bb3cfd`, with no unmerged paths.

- [ ] **Step 1: Keep dev/edd's deletion of the 5 old docs**

Run: `git rm -q docs/code-standards.md docs/codebase-summary.md docs/project-changelog.md docs/project-roadmap.md docs/system-architecture.md`
Then run `git diff --name-only --diff-filter=U`. Expected: no output.
Then run `git ls-files docs | grep -E 'code-standards|codebase-summary|project-(changelog|roadmap)|system-architecture'`. Expected: no output.

- [ ] **Step 2: Check the auto-merged constructor**

Run: `sed -n '/protected function __construct/,/^    }/p' includes/YayLoyalty.php`
Expected, in this order:
- `Emails::get_instance()`;
- `YayMailIntegration::get_instance()`;
- the `is_pro()` block;
- the comment `// After Pro's boot: build() applies yayloyalty_earning_gates once…`;
- `register_earning_listeners()`.

- [ ] **Step 3: Run the harness check**

Run: `./scripts/check-harness.sh`
Expected: `Harness checks passed.` and exit 0. Before step 1 it reported `removed_refs` and `cited_docs` findings, all from the 5 deleted docs.

- [ ] **Step 4: Commit the merge**

Stage: nothing more; step 1 staged the deletions.
Message: `Merge branch 'dev/edd' into paul/feat/email-points-earned`, with a body of one line: "Keep dev/edd's removal of the old harness docs; their email facts move to architecture.md in the next commit." Then the trailer.

### Task 2: Move the email and YayMail facts into `docs/architecture.md`

**Files:**
- Modify: `docs/architecture.md`, § Boot and autoload (the constructor-order bullet), § Server layers and write ownership (the domain list) and § Tests (the integration traits bullet)

**Interfaces:**
- Consumes: the merge from Task 1.
- Produces: nothing that code consumes.

- [ ] **Step 1: Check the facts against the code before writing them**

Each fact below must hold in the merged tree; drop any that doesn't:
- Lite registers 3 emails (`Emails::add_classes()` in `includes/Engine/Emails/Emails.php`), and Pro adds its own through `woocommerce_email_classes` in `YayLoyaltyPro::boot()`. Count Pro's list in `ProEmails::add_classes()`; don't copy "9" from the old doc.
- `EarningService::award()` fires `EarningService::AWARDED_ACTION` (`yayloyalty_points_awarded`) from `Transaction::after_commit()`.
- `Emails::add_email_actions()` adds that action to `woocommerce_email_actions`.
- `PointsEarnedEmail` hooks `trigger()` to `yayloyalty_points_awarded_notification`.
- The email is sent synchronously even when the store defers transactional emails. Confirm this from WooCommerce's handling of `woocommerce_email_actions` (`WC_Emails::queue_transactional_email` or `send_transactional_email`). If it can't be confirmed, leave the claim out.
- `YayMailIntegration::is_yaymail_active()` requires `YaymailPluginAdapter`, and `is_licensed()` where that method exists. The integration hooks `yaymail_register_emails` and `yaymail_register_shortcodes`, and `PointsEarnedYayMailEmail` filters `wc_get_template`.

- [ ] **Step 2: Edit the boot-order bullet**

In "The order is: …", insert "the YayMail integration (`Integrations\YayMail\YayMailIntegration`)" between "emails" and "the Pro seam".

- [ ] **Step 3: Add two bullets after the earning listeners' bullets in the domain list**

- **Emails:** `includes/Engine/Emails/` holds `WC_Email` subclasses. Lite registers its emails in `Emails::add_classes()`, and Pro adds its own in `boot()`. Only the points earned email sends. `EarningService::award()` fires `yayloyalty_points_awarded` after commit, once per awarded row. `Emails::add_email_actions()` adds that action to `woocommerce_email_actions`, so WooCommerce builds its mailer and relays `yayloyalty_points_awarded_notification`, which `PointsEarnedEmail::trigger()` listens to. Include the sentence on synchronous sending only if step 1 confirmed it.
- **YayMail:** `includes/Integrations/YayMail/` lets merchants design the points earned email in YayMail. It boots only when YayMail is active and licensed (`YayMailIntegration::is_yaymail_active()`). It registers the email and its shortcodes on YayMail's `yaymail_register_emails` and `yaymail_register_shortcodes`, and `PointsEarnedYayMailEmail` swaps the template through `wc_get_template` when the merchant enables YayMail's version.

- [ ] **Step 4: Rewrite the Tests bullet that names "the traits beside it"**

Rewrite it as: "The trait beside it, `WooCommerceOrdersFixture`, creates real users, products, orders, reviews and campaigns, switches every WooCommerce email off and keeps YayMail out of loyalty sends. Core tables are real, so a test must call its cleanup helpers."

- [ ] **Step 5: Verify**

Run: `./scripts/check-harness.sh`. Expected: exit 0.
Run: `git grep -n 'WooCommerceEmailsFixture\|snapshot the site' -- docs/architecture.md`. Expected: no output.

- [ ] **Step 6: Commit**

Stage: `docs/architecture.md`, `docs/plans/2026-10-04-email-branch-harness-merge.md`
Message: `docs(architecture): describe the points earned email and the YayMail integration`

### Task 3: Drop the deleted fixture from the integration bootstrap

**Files:**
- Modify: `tests/integration/bootstrap.php`, the `require_once` block at the end

**Interfaces:**
- Consumes: nothing.
- Produces: an integration bootstrap that loads.

- [ ] **Step 1: Remove the line `require_once __DIR__ . '/WooCommerceEmailsFixture.php';`**

Paul's commit `f4290fe` deleted that file, and nothing uses the trait any more.

- [ ] **Step 2: Verify**

Run: `git grep -n WooCommerceEmailsFixture`. Expected: matches only in `docs/plans/`, which is history.
Run: `php -l tests/integration/bootstrap.php`. Expected: `No syntax errors detected`.
Run: `composer test:integration`, with `YAYLOYALTY_TEST_SOCKET` set as the header of `tests/integration/bootstrap.php` describes. Expected: `No tests executed!`, as `docs/known-issues.md` records, and no `Failed opening required`.

- [ ] **Step 3: Commit**

Stage: `tests/integration/bootstrap.php`
Message: `fix(tests): stop requiring the removed emails fixture`

### Task 4: Name the owner of the YayMail default layout's wording

**Files:**
- Modify: `includes/Integrations/YayMail/PointsEarnedYayMailEmail.php`, the docblock of `get_default_elements()`

**Interfaces:**
- Consumes: nothing.
- Produces: an owner marker that `/yaytidy` reads.

- [ ] **Step 1: Add the docblock**

The method has no docblock today. Add one that says:
- it is the merchant-editable starting layout;
- its wording copies the points earned email's templates and the values from `PointsEarnedEmail::get_template_values()`, which own it, and uses the same msgids;
- it prints the greeting even when the name is `''`.

Cite no doc.

- [ ] **Step 2: Verify**

Run: `composer lint -- includes/Integrations/YayMail/PointsEarnedYayMailEmail.php`. Expected: no finding on the new docblock's lines. Any finding elsewhere in the file came from Paul's branch and stays.
Run: `./scripts/check-harness.sh`. Expected: exit 0.

- [ ] **Step 3: Close the plan**

Once the final verification below passes, set this plan's Status to `done 2026-10-04`.

- [ ] **Step 4: Commit**

Stage: `includes/Integrations/YayMail/PointsEarnedYayMailEmail.php`, `docs/plans/2026-10-04-email-branch-harness-merge.md`
Message: `docs(emails): name the owner of the YayMail default layout's wording`

### Final verification

- [ ] Run `git diff origin/dev/edd --stat -- CLAUDE.md README.md .claude docs`. Expected: only `docs/architecture.md` and `docs/plans/2026-10-04-email-branch-harness-merge.md`.
- [ ] Run `./scripts/check-harness.sh`. Expected: exit 0.
- [ ] Run `git status`. Expected: clean, with 4 commits on top of `origin/paul/feat/email-points-earned`: the merge and three fixes.
