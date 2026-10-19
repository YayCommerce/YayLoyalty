# Phase 1: Award

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** nothing
**Delivers:** a Pro merchant creates a Birthday campaign, customers set their birthday, the daily run pays it.
**Exit criterion:** Acceptance 7 except the email check; both Phase 1 tests print `OK (n tests, m assertions)` (integration exit 0 alone doesn't prove it ran — see `docs/known-issues.md`).

### Task 1: Birthday date math

**Files:**
- Create: `includes/Pro/Birthday/BirthdayWindow.php`
- Test: `tests/unit/Pro/BirthdayWindowTest.php`

**Interfaces:** Produces `BirthdayWindow::next()`, `BirthdayWindow::due_days()` (README contract). Pure PHP, no WordPress calls, so the unit suite can load it.

- [ ] **Step 1: Write the failing test**

```php
public function test_next_birthday_is_today(): void {
    $this->assertSame( [ 'year' => 2026, 'days_until' => 0 ], BirthdayWindow::next( 10, 19, new \DateTimeImmutable( '2026-10-19' ) ) );
}
public function test_next_birthday_already_passed_rolls_to_next_year(): void {
    $this->assertSame( [ 'year' => 2027, 'days_until' => 364 ], BirthdayWindow::next( 10, 18, new \DateTimeImmutable( '2026-10-19' ) ) );
}
public function test_next_birthday_across_new_year(): void {
    $this->assertSame( [ 'year' => 2027, 'days_until' => 4 ], BirthdayWindow::next( 1, 2, new \DateTimeImmutable( '2026-12-29' ) ) );
}
public function test_feb_29_falls_on_feb_28_in_a_non_leap_year(): void {
    $this->assertSame( [ 'year' => 2027, 'days_until' => 0 ], BirthdayWindow::next( 2, 29, new \DateTimeImmutable( '2027-02-28' ) ) );
    $this->assertSame( [ 'year' => 2028, 'days_until' => 0 ], BirthdayWindow::next( 2, 29, new \DateTimeImmutable( '2028-02-29' ) ) );
}
public function test_due_days_spans_new_year(): void {
    $this->assertSame(
        [ [ 'month' => 12, 'day' => 30 ], [ 'month' => 12, 'day' => 31 ], [ 'month' => 1, 'day' => 1 ] ],
        BirthdayWindow::due_days( new \DateTimeImmutable( '2026-12-30' ), 2 )
    );
}
public function test_due_days_adds_feb_29_in_a_non_leap_year(): void {
    $this->assertSame(
        [ [ 'month' => 2, 'day' => 28 ], [ 'month' => 2, 'day' => 29 ] ],
        BirthdayWindow::due_days( new \DateTimeImmutable( '2027-02-28' ), 0 )
    );
}
```

- [ ] **Step 2: Run** `composer test -- --filter BirthdayWindowTest` — expected FAIL, class not found.
- [ ] **Step 3: Implement** `BirthdayWindow` (final class, two static methods per the contract). Compare dates at midnight of `$site_today`'s own timezone.
- [ ] **Step 4: Run** `composer test -- --filter BirthdayWindowTest` — expected `OK (6 tests, 7 assertions)`.
- [ ] **Step 5: Commit** — stage both files; `feat(birthday): add birthday window date math`.

### Task 2: Birthday on the customer row

**Files:**
- Modify: `includes/Models/Customer.php` (constructor, `from_row`, `to_row`, `get_format`, getters; not `to_array`, so REST is unchanged)
- Modify: `includes/Repositories/CustomerRepository.php`
- Modify: `includes/Database/Schema/CustomersTable.php` (docblock only: birthday columns are now in use)

**Interfaces:** Produces `get_birthday_month/day()`, `set_birthday()`, `birthday_user_ids()` (README contract). No DDL change: the columns already exist.

- [ ] **Step 1: Map `birthday_month` / `birthday_day` (`%d`, nullable) on `Customer`.**
- [ ] **Step 2: Add `set_birthday()`** — `ensure_row()`, then `update()` both columns (null binds as SQL NULL).
- [ ] **Step 3: Add `birthday_user_ids()`** — `WHERE user_id > %d AND ( (birthday_month = %d AND birthday_day = %d) OR … ) ORDER BY user_id LIMIT %d`; empty `$days` returns `[]` without a query.
- [ ] **Step 4: Verify** with `wp eval` (see memory note on the Local socket):
  `$r = new YayLoyalty\Repositories\CustomerRepository(); $r->set_birthday( 2, 10, 19 ); var_dump( $r->find_by_user_id( 2 )->get_birthday_day(), $r->birthday_user_ids( [ [ 'month' => 10, 'day' => 19 ] ], 0, 100 ) ); $r->set_birthday( 2, null, null ); var_dump( $r->find_by_user_id( 2 )->get_birthday_day() );`
  Expected: `int(19)`, an array containing `2`, then `NULL`.
- [ ] **Step 5: Commit** — the three files; `feat(customers): store and select customer birthdays`.

### Task 3: Birthday trigger in the engine

**Files:**
- Modify: `includes/Models/Campaign.php` (`config_schema()` applies `yayloyalty_campaign_config_schema`; update its docblock and the "accepted untyped" comment)
- Modify: `includes/Engine/Points/ContextFactory.php` (`from_user()` gains `array $extra = []`, passed to `Context`)
- Create: `includes/Pro/Birthday/BirthdayHandler.php`
- Modify: `includes/Pro/YayLoyaltyPro.php` (`add_filter( 'yayloyalty_campaign_config_schema', … )` adding birthday's typed spec; remove `birthday` from `add_config_keys()`; `add_action( 'yayloyalty_register_trigger_handlers', … )` registering `new BirthdayHandler()`; fix the `ADDED_TRIGGERS` docblock only if it now misstates birthday)

**Interfaces:** Consumes `BirthdayWindow` (not directly — the runner computes extras). Produces the filter, `from_user()` signature, `BirthdayHandler` (README contract). `advertised_value()` returns `(float) $campaign->flat_points()` as `SignupHandler` does.

- [ ] **Step 1: Add the schema filter and Pro spec** — `points` `int min 0`, `days_before` `int min 0 max 30`.
- [ ] **Step 2: Extend `from_user()`.**
- [ ] **Step 3: Implement and register `BirthdayHandler`.**
- [ ] **Step 4: Verify the config rules over REST** (logged-in admin, Pro build):
  `POST /wp-json/yayloyalty/v1/campaigns` with `trigger: birthday, config: { points: "abc" }` → 400; `{ days_before: 31 }` → 400; `{ points: 150, days_before: 0 }` → 201; a signup campaign save still → 201. The handler itself is exercised by Task 4's test.
- [ ] **Step 5: Commit** — the four files; `feat(birthday): add the birthday trigger handler and config schema`.

### Task 4: Daily birthday run

**Files:**
- Create: `includes/Pro/Birthday/BirthdayRunner.php`
- Modify: `includes/Pro/YayLoyaltyPro.php` (`( new BirthdayRunner() )->register();` in `boot()`)
- Modify: `includes/Engine/ActDeact.php` and `includes/Engine/Expiry/ExpiryRunner.php` (`deactivate()` cancels the whole `ExpiryRunner::GROUP`, so Pro's job stops too without Lite naming it: `ExpiryRunner::unschedule()`, whose only caller is `deactivate()`, becomes `unschedule_group()` and calls `as_unschedule_all_actions( '', [], self::GROUP )`)
- Test: `tests/integration/Earning/BirthdayAwardTest.php`

**Interfaces:** Consumes `BirthdayWindow`, `birthday_user_ids()`, `from_user( …, $extra )`, `BirthdayHandler`, `EarningService::build()->award()`, `LedgerRepository::find_by_idempotency_key()`, `IdempotencyKey::compose()`, `CampaignRepository::live_for_trigger()`. Produces `BirthdayRunner` (README contract).

`register()`/`schedule()` copy `ExpiryRunner`'s (admin non-ajax or cron only; `as_schedule_recurring_action( time(), DAY_IN_SECONDS, RUN_ACTION, [], GROUP )`). `run( $after_user_id = 0 )`:
1. Live birthday campaigns at `Dates::now()`; none → return. `$days_before` = max of their `days_before`.
2. `$site_today` from the constructor, else today in `wp_timezone()`; `$ids = birthday_user_ids( BirthdayWindow::due_days( $site_today, $days_before ), $after_user_id, BATCH_SIZE )`.
3. Per id, inside a try/catch that logs via `Log::warning( 'BirthdayRunner', … )`: `next()` from the stored month/day; skip if `find_by_idempotency_key( IdempotencyKey::compose( LedgerType::EARN, $id, ReferenceType::BIRTHDAY, $year, CampaignTrigger::BIRTHDAY ) )` exists; else `award( CampaignTrigger::BIRTHDAY, ContextFactory::from_user( $id, Dates::now(), [ 'birthday_year' => …, 'birthday_days_until' => … ] ) )`.
4. Full batch → `as_enqueue_async_action( RUN_ACTION, [ end( $ids ) ], GROUP )`.

- [ ] **Step 1: Write the failing test** (extends `IntegrationTestCase`; create users/campaigns with its fixtures, as `SignupReviewFlowTest` does; every run pins site day 2026-06-15 so the test never crosses a year end):

```php
private const DAY = '2026-06-15';

private function run_on( string $ymd ): void {
    ( new BirthdayRunner( new \DateTimeImmutable( $ymd, wp_timezone() ) ) )->run();
}

public function test_pays_once_per_birthday_year(): void {
    $user = $this->customer_with_birthday( 6, 15 );
    $this->live_birthday_campaign( [ 'points' => 150, 'days_before' => 0 ] );
    $this->run_on( self::DAY );
    $this->run_on( self::DAY );
    $rows = $this->birthday_rows( $user );
    $this->assertCount( 1, $rows );
    $this->assertSame( 150, $rows[0]->get_points() );
    $this->assertSame( 2026, $rows[0]->get_reference_id() );
}
public function test_birthday_moved_later_after_payment_pays_nothing_more(): void {
    $user = $this->customer_with_birthday( 6, 15 );
    $this->live_birthday_campaign( [ 'points' => 150, 'days_before' => 0 ] );
    $this->run_on( self::DAY );
    ( new CustomerRepository() )->set_birthday( $user, 6, 16 );
    $this->run_on( '2026-06-16' );
    $this->assertCount( 1, $this->birthday_rows( $user ) );
}
public function test_outside_the_window_pays_nothing(): void {
    $user = $this->customer_with_birthday( 6, 18 );
    $this->live_birthday_campaign( [ 'points' => 150, 'days_before' => 2 ] );
    $this->run_on( self::DAY );
    $this->assertCount( 0, $this->birthday_rows( $user ) );
}
public function test_campaign_pays_only_inside_its_own_window(): void {
    $user = $this->customer_with_birthday( 6, 18 );
    $this->live_birthday_campaign( [ 'points' => 150, 'days_before' => 0 ], 10 ); // the engine tries it first
    $this->live_birthday_campaign( [ 'points' => 40, 'days_before' => 5 ], 20 );
    $this->run_on( self::DAY );
    $this->assertSame( 40, $this->birthday_rows( $user )[0]->get_points() );
}
public function test_no_live_campaign_pays_nothing(): void {
    $user = $this->customer_with_birthday( 6, 15 );
    $this->run_on( self::DAY );
    $this->assertCount( 0, $this->birthday_rows( $user ) );
}
```

Helpers are private to the test; reuse `IntegrationTestCase` fixtures where they exist. Confirm the priority direction against `CampaignRepository` ordering before writing the fourth test.

- [ ] **Step 2: Run** `composer test:integration -- --filter BirthdayAwardTest` — expected FAIL, class not found.
- [ ] **Step 3: Implement `BirthdayRunner`, register it, widen `deactivate()`.**
- [ ] **Step 4: Run** `composer test:integration -- --filter BirthdayAwardTest` — expected `OK (5 tests, …)`.
- [ ] **Step 5: Verify continuation by hand:** temporarily set 101 customers' birthday to today (`wp eval` loop over `set_birthday`), run `do_action( 'yayloyalty_birthday_run' )`; expected 100 awards and one pending `yayloyalty_birthday_run` async action with args `[<100th user id>]` in Tools → Scheduled Actions; run it; expected the 101st paid and no further action queued. Clear the birthdays afterwards.
- [ ] **Step 6: Verify deactivation:** deactivate/reactivate the plugin; expected no `yayloyalty_birthday_run` or `yayloyalty_expiry_run` pending right after deactivation, both back after the next wp-admin load.
- [ ] **Step 7: Commit** — the four files; `feat(birthday): award birthday points from a daily run`.

### Task 5: Birthday field in My Account

**Files:**
- Create: `includes/Pro/Birthday/BirthdayFields.php`
- Modify: `includes/Pro/YayLoyaltyPro.php` (`( new BirthdayFields() )->register();`)

**Interfaces:** Consumes `set_birthday()`, `find_by_user_id()`, `live_for_trigger()`. Produces `BirthdayFields` (README contract).

Hooks, all no-ops unless `is_offered()`: render on `woocommerce_register_form` and `woocommerce_edit_account_form` (two `<select>`s: month names from `$wp_locale->get_month()`, days 1–31, plus a blank option; preselect the stored value on the edit form; label "Birthday (optional)", help "We'll add bonus points on your birthday."); validate on `woocommerce_registration_errors` (filter, 3 args) and `woocommerce_save_account_details_errors` (action, 2 args); save on `woocommerce_created_customer` and `woocommerce_save_account_details`. Valid = both blank, or both set and `checkdate( $month, $day, 2000 )`. Error: `__( 'Please enter a valid birthday.', 'yayloyalty' )`. `$_POST` reads use `absint( wp_unslash( … ) )` with `phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified its form nonce before this hook.`

- [ ] **Step 1: Implement and register `BirthdayFields`.**
- [ ] **Step 2: Verify edit account** (logged in as a customer, live Birthday campaign): `/my-account/edit-account/` → set Oct 19, save → reload shows Oct 19 and `wp eval` `get_birthday_day()` = 19; choose month only → "Please enter a valid birthday." and the stored value is unchanged; Feb + 30 → same error; clear both → stored `NULL`. Pause the campaign → the field is gone.
- [ ] **Step 3: Verify registration** (logged out, "allow registration on My Account" on): register with Mar 5 → new customer row has 3/5; register with day only → error, no user created.
- [ ] **Step 4: Commit** — both files; `feat(birthday): let customers enter their birthday in My Account`.

### Task 6: Birthday campaign in the admin

**Files:**
- Modify: `apps/admin/src/lib/schema/campaign.schema.ts` (`BirthdayConfig` type `{ points: number; days_before: number }`, with the `Mirrors Campaign::config_schema()` comment)
- Modify: `apps/admin/src/pro/campaigns/add/campaign-templates.ts` (type `CreatableCampaignTrigger = LiteCampaignTrigger | 'birthday'`; a Birthday card: title "Birthday", description "Bonus points once a year on each customer's birthday.", example "e.g. 200 points, a week early"; seed config `{ points: 100, days_before: 0 }` built inside the function)
- Modify: `apps/admin/src/pro/campaigns/editor/campaign-editor.form.ts` (`duplicateToFormValues` keeps a birthday source as birthday)
- Modify: `apps/admin/src/pro/campaigns/editor/CampaignEditorReward.tsx` (`case 'birthday'` → `RewardFieldsBirthday`: "Award" points input like `RewardFieldsSignup`, and "Award early" `days_before` input 0–30 with unit "days before", description "0 awards on the birthday itself.")
- Modify: `apps/admin/src/pro/campaigns/campaign-labels.ts` only if `givesLabel()` does not already read `config.points` for birthday

**Interfaces:** Consumes the PHP schema from Task 3 (the server is the validator; the SPA does not repeat the 0–30 rule beyond the input's min/max).

- [ ] **Step 1: Implement the four changes.**
- [ ] **Step 2: Run** `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit` — expected no errors; `pnpm --filter @yayloyalty/admin-page lint` — no new findings in the changed files.
- [ ] **Step 3: Verify in the admin** (`./scripts/run.sh dev pro`): Campaigns → Add → Birthday card → set 150 / 0 → Save → list shows "Birthday" with "150 points"; reopen → fields hold 150 / 0; Duplicate → the copy is a Birthday campaign. `./scripts/run.sh build lite` succeeds and `git grep -c "Bonus points once a year" assets/dist/admin` finds nothing in the Lite build output.
- [ ] **Step 4: Phase exit check** — run Acceptance 7 (ignore the email line) and both Phase 1 tests.
- [ ] **Step 5: Commit** — the changed SPA files; `feat(campaigns): add the birthday campaign to the admin`.
