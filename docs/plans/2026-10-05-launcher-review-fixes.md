# Storefront launcher: fix forward the 2026-10-05 code review

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. There is no spec; the Requirements section below is what this plan argues from.

**Status:** done 2026-10-05
**Goal:** resolve the 14 findings that `/code-review` raised on `paul/feat/frontend-launcher`, against the three cleanup commits on top of the `dev/edd` merge. Each one is fixed in code, or recorded in the launcher note where the user chose that.
**Spec:** none, planned from the request
**Architecture:**
- **Points wording follows the email's rule:** an amount carries the merchant's label, in a format translators can reorder, and prose keeps the plain word "points".
- **Settings are read once per request:** `SettingsHelper::get_settings()` is memoised, keyed by locale and the raw option, so callers can ask for settings as often as they need.
- **Advertised earn rows have one owner:** `LauncherEarnRules::advertise()` decides every row the launcher shows.
- **The unused test fixtures go:** the integration fixture trait and its helper are deleted, as the user chose.

**Touches:** `includes/Helpers/SettingsHelper.php`, `includes/Engine/Earning/ProgramGate.php`, `includes/Engine/Emails/PointsEarnedEmail.php`, `includes/Engine/Storefront/LauncherCopy.php`, `LauncherEarnRules.php`, `LauncherPayload.php`, `includes/Helpers/DesignSchema.php`, `includes/Repositories/RewardRepository.php`, `apps/admin/src/lib/api/design.api.ts`, `apps/admin/src/lib/schema/design.schema.ts`, `apps/admin/tests/fixtures/design-defaults.json`, `tests/integration/IntegrationTestCase.php`, `tests/integration/bootstrap.php`, `docs/architecture.md`, `docs/notes/2026-10-04-points-earned-email-review.md`, `docs/notes/2026-10-05-storefront-launcher-review.md`; deletes `tests/integration/WooCommerceOrdersFixture.php`

## Requirements

- **Goal:** every finding from the review is fixed forward, or recorded in `docs/notes/2026-10-05-storefront-launcher-review.md` where the user chose a note.
- **Findings and their task:**

  | # | Finding | Task |
  |---|---|---|
  | 1 | The launcher promises redemption that isn't built, and `find_live_cart_discount()` says "customers can spend" it | 5: docblocks and note only |
  | 2 | One sentence mixes the label and the word "points" | 1 |
  | 3 | Signup points are hidden when the store only has "create an account after checkout" | 3 |
  | 4 | `ProgramGate::check()` repeats the program-on rule | 2 |
  | 5 | The launcher note cites commit hashes and "not on dev/edd" | 5 |
  | 6 | `from_entry()` was removed | not restored (user's choice); recorded as a test gap in Task 5 |
  | 7 | `points_text()` isn't translatable | 1 |
  | 8 | `earned_text()` formats points by hand | 1 |
  | 9 | The advertised earn rows are decided in two classes | 3 |
  | 10 | `redeem_rate()` compares floats exactly | 1 |
  | 11 | The `rate()` comment promises digits it can't keep | 5 |
  | 12 | Settings are rebuilt on every label call | 2 |
  | 13 | `previewDesign()` casts its response | 4 |
  | 14 | The fixture cleanup is half done | 6 |

- **Out of scope:**
  - building redemption, and hiding the launcher's or the email's redemption promises (finding 1 stays a note);
  - WordPress's own registration (`users_can_register`) as a signup route;
  - restoring any test, or the `from_entry()` seam;
  - finding 7 of the yaytidy run (the unused `points` field) and the note's other open sections;
  - pushing and opening the PR: the user does that in GitHub Desktop.
- **Acceptance criteria:**
  1. With a purchase and a review campaign live and default settings, the guest panel reads "Earn 1 Point per £1 spent, and 50 Points for a review". With the labels set to Star and Stars, it reads "Earn 1 Star per £1 spent, and 50 Stars for a review". Stores that saved the Design tab keep their stored `earn_rate_line` text.
  2. `SettingsHelper::points_text( 50 )` returns "50 Points" through a translatable format. `PointsEarnedEmail::earned_text()` uses it.
  3. `LauncherCopy::redeem_rate()` prints the singular label exactly when `LauncherCopy::rate()` prints "1".
  4. Repeated `get_settings()` calls in one request reuse one result until the locale or the stored option changes. `update_settings()` followed by `get_settings()` in the same request returns the new value.
  5. `ProgramGate::check()` decides "program paused" through `ProgramGate::program_enabled()`.
  6. When `woocommerce_enable_delayed_account_creation` is `yes` and both other registration options are off, `register_route()` returns `'checkout'` and the guest panel shows the signup step. `LauncherEarnRules::advertise()` alone decides whether the signup row exists.
  7. `previewDesign()` parses its response with `designPreviewSchema`.
  8. `tests/integration/WooCommerceOrdersFixture.php` and `IntegrationTestCase::first_admin_id()` are gone, and no tracked file outside `docs/plans/` names either. `composer test:integration` prints "No tests executed!" with no `Failed opening required`.
  9. `./scripts/check-harness.sh` exits 0, and `composer lint` on the changed PHP reports nothing.
  10. End to end: on the Local site, a signed-in customer's shop page shows the launcher with balance and worth, `GET /me/activity` returns 200, a guest's panel shows the wording in criterion 1, and the Design tab preview renders.
- **Decided with the user (2026-10-05):**
  - Finding 1: correct the docblock and record the promise in the note; the launcher text stays.
  - Finding 3: treat after-checkout account creation as the `'checkout'` route.
  - Findings 6 and 14: finish deleting the fixtures, and leave `from_entry()` removed.

## Global constraints

- Product PHP must run on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types. Nor `mixed`, `static` or `never` types, a `catch` without a variable, `throw` as an expression, a trailing comma after a declaration's last parameter, or `new` in a default value.
- Never hand-edit generated output. `apps/admin/tests/fixtures/design-defaults.json` is regenerated by `scripts/dump-design-defaults.php`.
- Every translatable string with a placeholder has a `translators:` comment. No comment cites another document's path or section number, a feature ID or a design-artboard ID.
- A TypeScript copy of a PHP shape names the PHP that owns it, in its header. REST responses are parsed with their zod schema in the resource's `*.api.ts` module, never cast with `.json<T>()`.
- Never commit to `dev/edd` or `main` directly. Commit messages follow Conventional Commits, `type(scope): summary`, and end with the `Co-Authored-By` trailer that `CLAUDE.md` asks for. Check `git status`, and stage files by name.
- Avoid pnpm commands. They install first and rewrite `vendor/` (`docs/known-issues.md`). Run eslint and Vite through `node node_modules/…` in `apps/admin`.

## Review focus

- **Stale memoised settings:** a cached `get_settings()` must never return the old value after a save, or the wrong language when WooCommerce switches the locale for a customer's email. Task 2, step 2 checks both cases.
- **Saved designs keep the old line:** the new `earn_rate_line` default reaches only stores that never saved the Design tab. Task 1, step 6 checks that a stored line without `{points_label}` still renders.
- **Signup row ownership:** after Task 3 the guest template must not re-check the route. Task 3, step 3 checks that nothing outside `LauncherEarnRules` sets the signup row.

---

### Task 1: One translatable format for points amounts (findings 2, 7, 8, 10)

**Files:**
- Modify: `includes/Helpers/SettingsHelper.php` (`points_text()`)
- Modify: `includes/Engine/Emails/PointsEarnedEmail.php` (`earned_text()`)
- Modify: `includes/Engine/Storefront/LauncherCopy.php` (`earn_rate()`, `redeem_rate()`, new `rate_label()`)
- Modify: `includes/Helpers/DesignSchema.php` (the `earn_rate_line` default)
- Regenerate: `apps/admin/tests/fixtures/design-defaults.json`

**Interfaces:**
- `SettingsHelper::points_text( int $points ): string` keeps its signature. Its format is `_x( '%1$s %2$s', 'points amount', 'yayloyalty' )`, with the comment `/* translators: 1: number of points, 2: the store's points label, e.g. "Points". */`.
- `private static function rate_label( float $rate ): string` in `LauncherCopy`: `SettingsHelper::points_label( '1' === self::rate( $rate ) ? 1 : 0 )`.
- The `earn_rate_line` template gains a placeholder, `{points_label}`, the same name the email uses. Default: `'Earn {points} {points_label} per {amount} spent'`.

- [x] **Step 1:** Rewrite `points_text()` with the format above, keeping `number_format_i18n( $points )` for the number.
- [x] **Step 2:** In `earned_text()`, build `$points` from `SettingsHelper::points_text( $entry->get_points() )` inside the existing `<strong>` and `esc_html()`. The subject's `{points} {points_label}` placeholders stay.
- [x] **Step 3:** Add `rate_label()`. Use it in `redeem_rate()` in place of `1.0 === $points_per_unit`, and in `earn_rate()`'s `strtr()` map as `'{points_label}' => self::rate_label( $points_per_unit )`.
- [x] **Step 4:** Change the `earn_rate_line` default in `DesignSchema`. Then, from the repo root, run `php scripts/dump-design-defaults.php > apps/admin/tests/fixtures/design-defaults.json`. Expected: `git diff` on the fixture changes only that one line.
- [x] **Step 5: Verify the wording.** Run `wp eval` on the Local site, as in `docs/known-issues.md`'s wp-cli workaround. Expected:
  - `SettingsHelper::points_text( 50 )` → `50 Points`, and `points_text( 1 )` → `1 Point`;
  - `LauncherCopy::redeem_rate( 1.00000000001 )` → `1 Point = £1`;
  - `LauncherCopy::earn_rate( '', 1.0, 0.0 )` → `Earn 1 Point per £1 spent`.
- [x] **Step 6: Verify a saved design.** Run `LauncherCopy::earn_rate( 'Earn {points} points per {amount} spent', 2.0, 0.0 )` in `wp eval`. Expected: `Earn 2 points per £1 spent`. A stored line without the placeholder renders unchanged.
- [x] **Step 7:** Run `composer lint -- includes/Helpers/SettingsHelper.php includes/Engine/Emails/PointsEarnedEmail.php includes/Engine/Storefront/LauncherCopy.php includes/Helpers/DesignSchema.php`. Expected: no findings.
- [x] **Step 8: Commit.** Stage the five files above. Message: `fix(storefront): format points amounts the same translatable way everywhere`.

### Task 2: Read settings once per request (findings 4, 12)

**Files:**
- Modify: `includes/Helpers/SettingsHelper.php` (`get_settings()`, a new private static cache)
- Modify: `includes/Engine/Earning/ProgramGate.php` (`check()`)

**Interfaces:**
- `get_settings(): array` keeps its signature and result. The cache, `private static $cache` (`array{locale: string, saved: mixed, settings: array}|null`), is reused only when `get_locale()` and the raw `get_option( self::OPTION_SETTINGS, [] )` both equal the cached ones (`===`). `update_settings()` needs no change, because the raw option it writes no longer matches.
- `ProgramGate::check()` returns `REASON_PROGRAM_PAUSED` when `! self::program_enabled()`, then reads `$general` for the remaining rules.

- [x] **Step 1:** Add the cache to `get_settings()`, with a one-line docblock reason: settings are read many times per page, and keying on the locale keeps emails sent in a customer's language right.
- [x] **Step 2: Verify the cache invalidates.** In one `wp eval`, read the private cache through `new ReflectionProperty( SettingsHelper::class, 'cache' )`. Leave no change in the site's settings. Expected:
  - after `get_settings()`, the cache's `locale` is `get_locale()`;
  - after `update_settings( [ 'general' => [ 'points_label_plural' => 'Stars' ] ] )`, `get_settings()['general']['points_label_plural']` is `Stars`. Restore the original label afterwards;
  - after `add_filter( 'locale', fn() => 'xx_XX' )` and `get_settings()`, the cache's `locale` is `xx_XX`.
- [x] **Step 3:** Restore `self::program_enabled()` in `check()`.
- [x] **Step 4: Smoke-test.** Load the shop page as a signed-in customer and as a guest, with curl and a wp-cli auth cookie. Expected: 200 for both, and the launcher markup present.
- [x] **Step 5:** Run `composer lint -- includes/Helpers/SettingsHelper.php includes/Engine/Earning/ProgramGate.php`. Expected: no findings.
- [x] **Step 6: Commit.** Stage both files. Message: `perf(settings): read the settings once per request and locale`.

### Task 3: One owner for the advertised earn rows, and after-checkout signup (findings 3, 9)

**Files:**
- Modify: `includes/Engine/Storefront/LauncherEarnRules.php` (`advertise()`)
- Modify: `includes/Engine/Storefront/LauncherPayload.php` (`for_balance()`, `register_route()`)

**Interfaces:**
- `LauncherEarnRules::advertise( array $live, ?Campaign $default_purchase, bool $can_register ): array`. When `$can_register` is false, `earn['signup']` is null. Its docblock says why: the signup campaign pays only a self-registration.
- `LauncherPayload::register_route(): ?string` returns `'checkout'` when `woocommerce_enable_signup_and_login_from_checkout` or `woocommerce_enable_delayed_account_creation` is `'yes'` and My Account registration is off. Its docblock names both options.

- [x] **Step 1:** Add the parameter to `advertise()` and apply it.
- [x] **Step 2:** In `for_balance()`, compute `$register` first, pass `null !== $register`, and remove the block that nulls the signup row afterwards. Extend `register_route()`.
- [x] **Step 3:** Run `git grep -n "CampaignTrigger::SIGNUP ] = null" -- includes`. Expected: matches only in `LauncherEarnRules.php`.
- [x] **Step 4: Verify on the Local site.** In `wp eval`, set the three registration options with `pre_option_<name>` filters, so nothing is written, and call `LauncherPayload::for_balance()` for a guest. Expected:
  - only delayed creation on: `register` is `'checkout'` and `earn['signup']` is the signup campaign's points;
  - all three off: `register` is null and `earn['signup']` is null.
- [x] **Step 5:** Run `composer lint -- includes/Engine/Storefront`. Expected: no findings.
- [x] **Step 6: Commit.** Stage both files. Message: `fix(storefront): offer signup points after checkout, decided in one place`.

### Task 4: Parse the preview response (finding 13)

**Files:**
- Modify: `apps/admin/src/lib/schema/design.schema.ts`, adding `designPreviewSchema`
- Modify: `apps/admin/src/lib/api/design.api.ts` (`previewDesign()`, removing the hand-written `DesignPreview` type)

**Interfaces:**
- `export const designPreviewSchema = z.object({ collapsed: z.string(), expanded: z.string() })` and `export type DesignPreview = z.infer<typeof designPreviewSchema>`. The owner comment moves with them: it mirrors `LauncherPreview::documents()`.
- `export async function previewDesign( design, viewer ): Promise<DesignPreview>` returns `designPreviewSchema.parse(await api.post('design/preview', { json: { design, viewer } }).json())`, as `campaign.api.ts` does.

- [x] **Step 1:** Add the schema and type, and update `previewDesign()`.
- [x] **Step 2: Verify.** In `apps/admin`, run `node node_modules/eslint/bin/eslint.js src/lib/api/design.api.ts src/lib/schema/design.schema.ts` and `node node_modules/prettier/bin/prettier.cjs --check` on both files. Expected: clean.
- [x] **Step 3: Type-check.** Run `node node_modules/typescript/bin/tsc --noEmit`. Expected: only the errors `docs/known-issues.md` lists (`@emoji-mart/*`, `@radix-ui/react-progress`), none in these files.
- [x] **Step 4: Commit.** Stage both files. Message: `fix(admin): parse the launcher preview response with its schema`.

### Task 5: Correct the claims in comments and notes (findings 1, 5, 6, 11)

**Files:**
- Modify: `includes/Repositories/RewardRepository.php`, docblocks of `find_live_cart_discount()` and `live_points_per_currency_unit()`
- Modify: `includes/Engine/Storefront/LauncherCopy.php`, docblock of `rate()`
- Modify: `docs/notes/2026-10-05-storefront-launcher-review.md`
- Modify: `docs/notes/2026-10-04-points-earned-email-review.md`, section 2

**Interfaces:** none.

- [x] **Step 1: The reward docblocks.** Say what "live" means, and that until redemption ships nothing at checkout redeems the reward. Drop "the one customers can spend" and "customers are never shown a reward they cannot spend".
- [x] **Step 2: The email note.** In section 2, remove the bullet saying the docblock is false and the "correct the docblock" clause, because step 1 did both.
- [x] **Step 3: The `rate()` docblock.** PHP prints at most 10 decimal places, so it shows the same digits as `pointsPerCurrencyLabel()` for any rate entered with 10 or fewer.
- [x] **Step 4: The launcher note.**
  - Purpose: drop the commit hashes, and say the fixes landed with this branch.
  - Section 3: "not on `dev/edd` yet" becomes "not in a release yet".
  - Add a section, "The launcher promises redemption that isn't built": the guest redeem step and the member's worth line. It points to the email note's section 2 for one shared fix.
  - Add a section, "No test guards the shopper-safe activity projection": `ShopperActivityRow::items()` needs WordPress, so a unit test can't reach it, and the user chose not to restore a seam.
- [x] **Step 5:** Run `composer lint -- includes/Repositories/RewardRepository.php includes/Engine/Storefront/LauncherCopy.php` and `./scripts/check-harness.sh`. Expected: no findings, and exit 0.
- [x] **Step 6: Commit.** Stage the four files. Message: `docs(storefront): correct the reward and rate claims and the launcher note`.

### Task 6: Finish deleting the unused integration fixtures (finding 14)

**Files:**
- Delete: `tests/integration/WooCommerceOrdersFixture.php`
- Modify: `tests/integration/IntegrationTestCase.php` (remove `first_admin_id()`), `tests/integration/bootstrap.php` (remove its `require_once`)
- Modify: `docs/architecture.md`, § Tests (remove the trait's sentence)
- Modify: `docs/notes/2026-10-04-points-earned-email-review.md`, § Test gap

**Interfaces:** none.

- [x] **Step 1:** Delete the trait, the method and the `require_once`.
- [x] **Step 2:** Remove the `WooCommerceOrdersFixture` bullet from architecture.md § Tests. Keep the core-tables sentence by moving it onto the `IntegrationTestCase` bullet: "Core tables are real, so a test cleans up what it creates".
- [x] **Step 3:** In the email note's Test gap:
  - say that the branch also deleted `WooCommerceOrdersFixture`, which already existed on `dev/edd`, so a restored test brings both fixtures back from history;
  - keep the `detach_yaymail()` remark, worded as a note on the restored trait.
- [x] **Step 4: Verify.**
  - Run `git grep -n -E 'WooCommerceOrdersFixture|first_admin_id' -- . ':!docs/plans'`. Expected: matches only in the email note's Test gap.
  - Run `php -l tests/integration/bootstrap.php tests/integration/IntegrationTestCase.php`. Expected: no syntax errors.
  - Run `composer test:integration` with `YAYLOYALTY_TEST_SOCKET` set. Expected: `No tests executed!`, with no `Failed opening required`.
  - Run `./scripts/check-harness.sh`. Expected: exit 0.
- [x] **Step 5: Commit.** Stage the five files by name, the deletion included. Message: `test: remove the integration fixtures no test uses`.

### Final verification

- [x] Run `composer lint` on every PHP file this plan changed. Expected: no findings.
- [x] Run `./scripts/check-harness.sh`. Expected: exit 0.
- [x] Rebuild the storefront CSS: `node node_modules/vite/bin/vite.js build --config vite.storefront.config.ts` in `apps/admin`. Then, on the Local site, use a customer auth cookie from `wp eval` and check:
  - the shop page shows the launcher with balance and worth;
  - `GET /me/activity` returns 200 with the nonce, and 401 for a guest;
  - the guest panel's how-it-works step reads "Earn 1 Point per £1 spent, and 50 Points for a review" with default settings, unless the site's Design tab has saved its own `earn_rate_line`;
  - `LauncherPreview::documents()` returns both documents for a member and a guest.
- [x] Set this plan's Status to `done <date>`, tick its steps, and commit it. Message: `docs(plans): record the launcher review fixes`.
- [x] Run `git status`. Expected: clean, with 7 commits on top of `df8c8ba`: the six tasks and the plan.
