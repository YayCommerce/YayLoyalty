# Launcher Earning Gates and Daily Cap Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. There is no spec; the Requirements section below is the source.

**Status:** done 2026-10-11
**Goal:** the storefront asks the same earning rules as the award does, so a shopper a Pro gate or the Pro daily cap stops from earning isn't promised points.
**Spec:** none, planned from two backlog items: `2026-10-05-launcher-skips-pro-earning-gates` and `2026-10-08-launcher-advertises-earning-past-the-daily-cap`, both removed once this plan landed.
**Architecture:** `EarningService` gets two public statics: `gate_reason()` runs the filtered gate chain without building the engine, and `points_left_today()` applies a new Lite filter that Pro's `DailyEarningLimit` answers. Lite never names Pro: with no subscriber, the figure is null (no limit). The obvious alternative, making the cap an earning gate, was turned down: a gate refuses outright, while the cap lowers an award, and a partial cut still pays.
**Touches:** `includes/Engine/Earning/EarningService.php` (shared: the award path and every caller of `preview()`), `includes/Engine/Earning/ProgramGate.php`, `includes/Engine/Storefront/Launcher.php`, `AccountRewards.php`, `LauncherPayload.php`, `LauncherCopy.php`, `ProductEarnMessage.php`, the templates `panel-member.php` and `account-rewards.php`, `includes/Pro/Services/DailyEarningLimit.php`, `includes/Pro/YayLoyaltyPro.php`, the two backlog items.
**Tests:** `tests/integration/Earning/DailyEarningLimitTest.php::test_points_left_today_counts_todays_earn_rows` (integration): the remaining-today figure that hides the rates.

## Requirements

- **Goal:** a member who can't earn, or has reached the Pro daily cap, sees no earning promise; a member under the cap sees what is left today.
- **Out of scope:** the guest pitch and the guest product estimate (a guest has no daily total); the Design tab preview (`LauncherPreview`, which keeps sample figures); `Redemption\ProgramGate`'s `TODO(redemption)`, which asks "is the program on", not "may this shopper earn"; making the cap an earning gate.
- **Acceptance criteria:**
  1. `Launcher::should_render()` and `AccountRewards::view()`'s `can_earn` run the chain that `yayloyalty_earning_gates` builds. A gate appended to that filter that blocks a member hides the launcher and My Account's "Ways to earn" for that member.
  2. Lite with no subscriber: `EarningService::points_left_today( $user_id )` returns null, and the launcher, My Account and product page are unchanged.
  3. Pro, `max_points_per_day` = 100, member earned 60 today: the launcher and My Account list the ways to earn followed by "You can earn 40 Points more today.", and a product that would earn 50 shows 40.
  4. Same member at 100 earned: the launcher and My Account show "You've reached today's earning limit. Earn more Points tomorrow." in place of the rates, and the product page shows no earning sentence.
  5. End to end: set the cap to 10, complete an order that earns 10, reload the shop: the launcher shows the limit line, the product page no sentence; set the cap back to empty, reload: the rates are back.
- **Decided with the user:**
  - `program_enabled()` → stays for "is the program on" (My Account menu and page, the product message's first check). Only "may this shopper earn" checks move to the chain. The gates item's second box is narrowed to that.
  - Where the cap applies → the launcher, My Account's "Ways to earn" and the member's product message.
  - Below the cap → show what is left today.

## Global constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types; also no `mixed`, `static` or `never` types (`.claude/rules/php-standards.md`).
- Lite names Pro in exactly one place (CLAUDE.md hard rule 2). The new filter is Lite's; Pro hooks it in `YayLoyaltyPro::boot()`.
- Every hook the plugin fires has a docblock. Hook names use the `yayloyalty_` prefix.
- Translatable strings use the `yayloyalty` text domain, one whole sentence per string, with a `translators:` comment for each placeholder.
- Comments at minimal depth: one-line docblocks, a why only where code would look wrong.
- Email and storefront templates are a public contract: add view keys, don't rename existing ones.
- Known failures on `dev/edd`: `composer lint` over the whole repo. Lint only the changed files with `composer lint -- <files>`: expect no errors.
- `composer test:integration` needs `YAYLOYALTY_TEST_SOCKET`, and exit 0 alone proves nothing: read the test count.
- Branch from `dev/edd` as `trace/fix/launcher-earning-gates`. Commits follow Conventional Commits and end with the `Co-Authored-By` trailer from CLAUDE.md. Commit only when the user asks.
- `trace/fix/backlog-quick-fixes` (unmerged) edits `EarningService::award()`, `announce_limited()` and the `LIMITED_ACTION` docblock. Leave those three untouched here, so the two branches merge cleanly in either order.

## Review focus

- **A guest is "blocked" by the chain.** `ProgramGate` answers `REASON_NO_CUSTOMER` for a guest whenever the program is on, so the launcher must read that reason as "show the pitch", not "hide". Checked in Task 1 Step 3.
- **An opted-out member loses their history.** My Account's page and menu keep `program_enabled()`; only `can_earn` moves. Checked in Task 1 Step 3.
- **The cap over-counts adjustments or yesterday.** The figure must match what `DailyEarningLimit::clamp()` would allow: gross EARN in the site-timezone day, no adjustments. Covered by the accepted test in Task 2.
- **A subscriber answers garbage.** A non-numeric answer counts as null (no limit), a negative one as 0. Checked in Task 2 Step 5.
- **Page caches.** Both figures are per member and printed only for signed-in shoppers, as the balance already is (`LauncherPayload` class docblock). No new exposure.

---

### Task 1: Storefront "can earn" checks run the filtered gate chain

**Files:**
- Modify: `includes/Engine/Earning/EarningService.php` (`gates()`, `blocked_reason()`)
- Modify: `includes/Engine/Storefront/Launcher.php` (`should_render()`)
- Modify: `includes/Engine/Storefront/AccountRewards.php` (`view()`)
- Modify: `includes/Engine/Earning/ProgramGate.php` (`program_enabled()` docblock)

**Interfaces:**
- Produces: `EarningService::gate_reason( string $trigger, Context $ctx ): ?string`, public static. The first reason of `self::gates()` (the filtered chain), or null.

- [ ] **Step 1: Add `gate_reason()` and share the loop**

Move the body of `blocked_reason()` into `private static function first_reason( array $gates, string $trigger, Context $ctx ): ?string`. `blocked_reason()` calls it with `$this->gates`; `gate_reason()` calls it with `self::gates()`. `gates()` stays private.

- [ ] **Step 2: Use it in the launcher and My Account**

- `Launcher::should_render()`: a guest renders when `ProgramGate::REASON_NO_CUSTOMER === EarningService::gate_reason( CampaignTrigger::PURCHASE, ContextFactory::for_advert( Dates::now() ) )`; a member when `null === EarningService::gate_reason( CampaignTrigger::PURCHASE, ContextFactory::from_user( $user_id, Dates::now() ) )`. Drop the `ProgramGate` construction.
- `AccountRewards::view()`: `can_earn` is `null === EarningService::gate_reason( … from_user … )`. `add_menu_item()` and `render()` keep `ProgramGate::program_enabled()`.
- `ProgramGate::program_enabled()` docblock: "Whether the program is on. Asks nothing about the shopper: a 'may this shopper earn' check runs EarningService::gate_reason()."

- [ ] **Step 3: Verify on the shop**

Add to the site, temporarily, an mu-plugin that appends a gate blocking user 1: `add_filter( 'yayloyalty_earning_gates', fn( $g ) => array_merge( $g, [ new class implements \YayLoyalty\Engine\Earning\Gate { public function check( string $t, \YayLoyalty\Engine\Points\Context $c ): ?string { return 1 === $c->get_user_id() ? 'test_block' : null; } } ] ) );`
Expected, with the mu-plugin: signed in as user 1, the shop has no launcher and My Account → Rewards shows the balance and history but no "Ways to earn"; signed out, the launcher shows the guest pitch. Without it: user 1 sees the launcher and "Ways to earn" again. Then set user 1's `_yayloyalty_opted_out` meta to 1: My Account → Rewards still lists the history. Delete the mu-plugin and the meta.

- [ ] **Step 4: Lint**

Run: `composer lint -- includes/Engine/Earning/EarningService.php includes/Engine/Storefront/Launcher.php includes/Engine/Storefront/AccountRewards.php includes/Engine/Earning/ProgramGate.php`
Expected: no errors.

- [ ] **Step 5: Commit**

Stage: the four files above.
Message: `fix(storefront): run the filtered earning gates before promising points`

### Task 2: Points a member can still earn today

**Files:**
- Modify: `includes/Engine/Earning/EarningService.php`
- Modify: `includes/Pro/Services/DailyEarningLimit.php`
- Modify: `includes/Pro/YayLoyaltyPro.php` (`boot()`)
- Test: `tests/integration/Earning/DailyEarningLimitTest.php`

**Interfaces:**
- Produces:
  - `EarningService::POINTS_LEFT_TODAY_FILTER = 'yayloyalty_points_left_today'`: filter `( ?int $left, int $user_id ): ?int`, default null (no limit). Docblock: "Internal seam, not a public API."
  - `EarningService::points_left_today( int $user_id ): ?int`, public static: the filter's answer, a non-numeric answer as null, a negative one as 0.
  - `DailyEarningLimit::left_today( $left, int $user_id ): ?int`: null when `max_points_per_day` is null, else `max( 0, $cap - points_earned_today( $user_id, Dates::now(), null ) )`. `$left` is ignored.

- [ ] **Step 1: Write the failing test**

```php
final class DailyEarningLimitTest extends IntegrationTestCase {

    /** @var int|null */
    private $cap = null;

    protected function setUp(): void {
        parent::setUp();
        add_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );
    }

    protected function tearDown(): void {
        remove_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );
        parent::tearDown();
    }

    public function pinned_settings(): array {
        return [
            'schema_version'     => SettingsHelper::SCHEMA_VERSION,
            'max_points_per_day' => $this->cap,
        ];
    }

    public function test_points_left_today_counts_todays_earn_rows(): void {
        $user_id = 515151;

        $this->assertNull( EarningService::points_left_today( $user_id ), 'No cap is no limit.' );

        $this->cap = 100;
        $this->earn( $user_id, 1, 60, Dates::now() );
        $this->earn( $user_id, 2, 500, Dates::offset( -DAY_IN_SECONDS ) ); // yesterday
        $this->ledger_service()->adjust( $user_id, 500, 'goodwill', 1 );   // manual, never capped
        $this->assertSame( 40, EarningService::points_left_today( $user_id ) );

        $this->earn( $user_id, 3, 60, Dates::now() );
        $this->assertSame( 0, EarningService::points_left_today( $user_id ) );
    }
}
```

`earn( int $user_id, int $order_id, int $points, string $created_at )` is a private helper: `IntegrationTestCase::earn_draft()` with its `created_at` replaced, written through `ledger_service()->earn()`. Pro must be booted on the test site; skip with `markTestSkipped()` when `! class_exists( YayLoyaltyPro::class )`.

- [ ] **Step 2: Run the test to confirm it fails**

Run: `composer test:integration -- --filter test_points_left_today_counts_todays_earn_rows`
Expected: FAIL, `EarningService::points_left_today` undefined.

- [ ] **Step 3: Implement the filter, `points_left_today()` and `DailyEarningLimit::left_today()`**

In `YayLoyaltyPro::boot()`, build one `DailyEarningLimit` and hook both: the existing `AWARD_POINTS_FILTER` → `clamp` and `EarningService::POINTS_LEFT_TODAY_FILTER` → `left_today` (priority 10, 2 args). In the `DailyEarningLimit` class docblock, replace "preview() is not capped, so pending points and storefront figures stay the uncapped best case." with "preview() is not capped, so pending points stay the uncapped best case; the storefront reads left_today() instead."

- [ ] **Step 4: Run the test to confirm it passes**

Run: `composer test:integration -- --filter test_points_left_today_counts_todays_earn_rows`
Expected: PASS, 1 test.

- [ ] **Step 5: Verify bad answers**

Run from `/Users/shrief/Local-Sites/yaydevs/app/public` (the wp-cli line in the `wp-cli-local-socket` memory): `wp eval 'add_filter( "yayloyalty_points_left_today", fn() => "x", 20 ); var_dump( YayLoyalty\Engine\Earning\EarningService::points_left_today( 1 ) ); remove_all_filters( "yayloyalty_points_left_today", 20 ); add_filter( "yayloyalty_points_left_today", fn() => -5, 20 ); var_dump( YayLoyalty\Engine\Earning\EarningService::points_left_today( 1 ) );'`
Expected: `NULL`, then `int(0)`.

- [ ] **Step 6: Lint and commit**

Run: `composer lint -- includes/Engine/Earning/EarningService.php includes/Pro/Services/DailyEarningLimit.php includes/Pro/YayLoyaltyPro.php tests/integration/Earning/DailyEarningLimitTest.php`. Expected: no errors.
Stage: those four files.
Message: `feat(earning): let the storefront read the points left under the daily cap`

### Task 3: The launcher and My Account show the daily limit

**Files:**
- Modify: `includes/Engine/Storefront/LauncherPayload.php` (`build()`, `for_balance()`)
- Modify: `includes/Engine/Storefront/AccountRewards.php` (`view()`)
- Modify: `includes/Engine/Storefront/LauncherCopy.php`
- Modify: `includes/Engine/Storefront/templates/panel-member.php`, `includes/Engine/Storefront/templates/account-rewards.php`

**Interfaces:**
- Consumes: `EarningService::points_left_today()` (Task 2).
- Produces:
  - `LauncherPayload::for_balance( array $design, ?int $balance, ?int $left_today = null ): array`, with a new payload key `left_today` (?int, null = no limit). `build()` passes `EarningService::points_left_today( $user_id )` for a member, null for a guest. `LauncherPreview` passes nothing.
  - `LauncherCopy::daily_limit_line( ?int $left_today ): string`: '' for null; at 0, "You've reached today's earning limit. Earn more %s tomorrow." (`%s`: `SettingsHelper::points_label_plural()`); above 0, "You can earn %s more today." (`%s`: `SettingsHelper::points_text( $left_today )`).

- [ ] **Step 1: Carry `left_today` in the payload**

`AccountRewards::view()` passes `EarningService::points_left_today( $user_id )` as `for_balance()`'s third argument.

- [ ] **Step 2: Add `LauncherCopy::daily_limit_line()`**

- [ ] **Step 3: Print it in both templates**

- `panel-member.php`: rows are `[]` when `0 === $view['left_today']`. The "Ways to earn" section renders when there are rows or the line is not ''; the line follows the rows, as a `<p>` in the section, `yl:text-xs yl:text-muted-foreground`.
- `account-rewards.php`: the same rule inside `$view['can_earn']`, and the `yayloyalty-account__rules` wrapper also renders when the line is not ''. The line is a `<p>` under the list.
- Add the `@var` line for `left_today` to each template's header if it documents the view.

- [ ] **Step 4: Verify on the shop**

With Pro, set Settings → General → daily limit to 100 and give user 1 earn rows totalling 60 today (complete orders, or a `LedgerService::earn()` call in `wp eval`).
Expected: signed in as user 1, the launcher and My Account → Rewards list the ways to earn and then "You can earn 40 Points more today."; at 100 earned, both show "You've reached today's earning limit. Earn more Points tomorrow." and no rates; with the limit emptied, both show the rates and no line. The Design tab preview is unchanged.

- [ ] **Step 5: Lint and commit**

Run: `composer lint -- includes/Engine/Storefront/LauncherPayload.php includes/Engine/Storefront/AccountRewards.php includes/Engine/Storefront/LauncherCopy.php includes/Engine/Storefront/templates/panel-member.php includes/Engine/Storefront/templates/account-rewards.php`. Expected: no errors.
Stage: those five files.
Message: `feat(storefront): show the daily earning limit in the launcher and My Account`

### Task 4: The product message respects the daily limit

**Files:**
- Modify: `includes/Engine/Storefront/ProductEarnMessage.php` (`member_points()`)

**Interfaces:**
- Consumes: `EarningService::points_left_today()` (Task 2).

- [ ] **Step 1: Cap the member's figure**

`member_points()` returns `min( preview points, $left )` when `EarningService::points_left_today( get_current_user_id() )` is not null. Read the figure once per request, beside `self::$sentences`, since a variable product computes many sentences. `compute()` already prints nothing for 0.

- [ ] **Step 2: Verify on a product page**

Same setup as Task 3 Step 4, on a simple product that would earn 50.
Expected: at 60 earned, "40" in the sentence; at 100, no earning sentence; on a variable product at 100, no sentence after picking a variation; signed out, the guest sentence is unchanged.

- [ ] **Step 3: Lint and commit**

Run: `composer lint -- includes/Engine/Storefront/ProductEarnMessage.php`. Expected: no errors.
Stage: that file.
Message: `fix(storefront): cap the product earning message at the points left today`

### Task 5: Close the backlog items

**Files:**
- Delete: `docs/backlog/2026-10-08-launcher-advertises-earning-past-the-daily-cap.md`
- Delete or modify: `docs/backlog/2026-10-05-launcher-skips-pro-earning-gates.md`

- [ ] **Step 1: Run the end-to-end check**

Requirements criterion 5. Expected: as stated there.

- [ ] **Step 2: Update the items**

Check each box with `/yaybacklog check`. Narrow the gates item's second box to "No 'may this shopper earn' check calls `ProgramGate::program_enabled()`: `git grep -n program_enabled -- includes` lists only `ProgramGate` itself, `AccountRewards::add_menu_item()`, `AccountRewards::render()`, `ProductEarnMessage::sentence()` and the redemption TODO", as decided with the user. Remove each item once every box is ticked, and set this plan's Status to `done YYYY-MM-DD`.

- [ ] **Step 3: Run the integration suite**

Run: `composer test:integration`
Expected: every test passes, the count including `DailyEarningLimitTest`.

- [ ] **Step 4: Commit**

Stage: the backlog files and this plan.
Message: `docs(backlog): close the launcher gates and daily cap items`
