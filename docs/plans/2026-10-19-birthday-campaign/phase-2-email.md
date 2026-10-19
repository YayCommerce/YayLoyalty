# Phase 2: Birthday email

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 1 Task 4 (`BirthdayRunner`, birthday ledger rows with source `birthday`)
**Delivers:** a birthday award emails "Happy birthday" with the points and the new balance, instead of Points earned.
**Exit criterion:** `composer test:integration -- --filter BirthdayPointsEmailTest` prints `OK (3 tests, …)`; on the Local site, a birthday award to a customer with an email address logs one "Happy birthday" email and no "You earned" email (Task 1 Step 7).

### Task 1: Send the Birthday points email

**Files:**
- Modify: `includes/Pro/Emails/BirthdayPointsEmail.php`
- Modify: `includes/Pro/Emails/templates/emails/yayloyalty-birthday-points.php`
- Modify: `includes/Pro/Emails/templates/emails/plain/yayloyalty-birthday-points.php`
- Modify: `includes/Engine/Emails/PointsEarnedEmail.php`
- Test: `tests/integration/Emails/BirthdayPointsEmailTest.php`

**Interfaces:**
- Consumes: `Emails::POINTS_EARNED_ACTION` (`'yayloyalty_points_earned_email'`, already in `woocommerce_email_actions`, relayed with the ledger row id after every award commits); `LedgerEntryEmail` (`trigger( int $entry_id )`, abstract `accepts()`, `amounts()`, `build_values()`, and `balance_values()`); `BirthdayRunner` from Phase 1 to make a real award in the test.
- Produces: `BirthdayPointsEmail::ID = 'yayloyalty_birthday_points'` (the id it already has, now a constant). Template variables `greeting_text`, `birthday_text`, `balance_sentence`, `balance_value_text`, `points`, `points_label`, `balance`, `entry` from `balance_values()` plus `birthday_text`. Filter `yayloyalty_birthday_points_text` ( string $text, LedgerEntry $entry ): string, mirroring `yayloyalty_points_earned_text`.

Design, fixed here so the steps don't decide it:
- `BirthdayPointsEmail` extends `LedgerEntryEmail`, not `ProLoyaltyEmail`. It overrides `templates_dir()` to return `__DIR__ . '/templates/'`, as `ProLoyaltyEmail` does, so the Lite build still strips the templates with the class.
- It listens on the same `Emails::POINTS_EARNED_ACTION . '_notification'` as Points earned; no new email action. Each email's `accepts()` takes only its own rows: Birthday points takes source `birthday`; Points earned takes everything else.
- Turning off the Birthday points email sends nothing for a birthday award. Points earned does not stand in for it.
- `accepts()` keeps Points earned's refund guard (`sum_refunded_for( $entry, false ) < points`), though no birthday row is refunded today, so a future clawback can't send a stale email.

- [ ] **Step 1: Write the failing test** — `tests/integration/Emails/BirthdayPointsEmailTest.php`, `final class BirthdayPointsEmailTest extends IntegrationTestCase`, using `WooCommerceOrdersFixture`.
  - `setUp()`: skip unless `YayLoyaltyPro` exists; `require_woocommerce()`; then, as `PointsEarnedEmailTest` does, take off the fixture's `__return_false` for both `woocommerce_email_enabled_` . `BirthdayPointsEmail::ID` and `PointsEarnedEmail::ID`, take off `pre_wp_mail` `__return_false`, and capture sends. Also record each sent email's id from WooCommerce's `woocommerce_email_sent` action ( bool $return, string $id, WC_Email $email ). Pin settings: labels `Point`/`Points`, `emails.throttle_per_week` null.
  - `tearDown()`: remove what `setUp()` added, put `pre_wp_mail` `__return_false` back, `cleanup_orders()`.
  - Helper `award_birthday(): int` creates a customer with an email (`customer_with_email()`), sets birthday 6/15, inserts a live birthday campaign `{ points: 150, days_before: 0 }`, runs `( new BirthdayRunner( new \DateTimeImmutable( '2026-06-15', wp_timezone() ) ) )->run()`, and returns the user id.

```php
public function test_a_birthday_award_sends_the_birthday_email_only(): void {
    $this->award_birthday();

    $this->assertSame( [ BirthdayPointsEmail::ID ], $this->sent_ids );
    $this->assertCount( 1, $this->sent );
    $this->assertStringContainsString( 'Happy birthday', $this->sent[0]['subject'] );
    $this->assertStringContainsString( '150 Points', wp_strip_all_tags( $this->sent[0]['message'] ) );
}

public function test_a_disabled_birthday_email_sends_nothing(): void {
    add_filter( 'woocommerce_email_enabled_' . BirthdayPointsEmail::ID, '__return_false' );

    $this->award_birthday();

    $this->assertSame( [], $this->sent_ids );
}

public function test_points_earned_still_sends_for_other_awards(): void {
    $this->create_campaign( CampaignTrigger::PURCHASE );
    $this->transition( $this->create_order( $this->customer_with_email(), [ 100 ] ), 'completed' );

    $this->assertSame( [ PointsEarnedEmail::ID ], $this->sent_ids );
}
```

- [ ] **Step 2: Run** `composer test:integration -- --filter BirthdayPointsEmailTest`. Expected: FAIL. The first test sees `yayloyalty_points_earned`, not the birthday id, and the constant `BirthdayPointsEmail::ID` is undefined until Step 3. Fix only a test typo here, never the expectation.
- [ ] **Step 3: Implement `BirthdayPointsEmail`** per the design above:
  - `ID` constant.
  - Placeholders `{points}`, `{points_label}`, `{balance}`, initialised to `''` in the constructor before `parent::__construct()`, with the `add_action` there too.
  - `accepts()`.
  - `amounts()`, the same three keys and formatting as `PointsEarnedEmail::amounts()`.
  - `build_values()` = `balance_values( $entry )` plus `birthday_text`. That is escaped HTML, `/* translators: %s: points with label, e.g. "150 Points". */ __( 'To celebrate your birthday, we added %s to your account.', 'yayloyalty' )`, with the points in `<strong>` and filtered through `yayloyalty_birthday_points_text`, as `earned_text()` is.
  - `prepare_preview()` with a made-up `LedgerEntry( null, get_current_user_id(), 150, 300, LedgerType::EARN, LedgerSource::BIRTHDAY )`.
  - Replace the docblock "Nothing triggers it yet." with what the class does.
- [ ] **Step 4: Update both templates.**
  - Each gets a variable list in its docblock, as `yayloyalty-points-earned.php` has.
  - Render, in this order: the greeting, `birthday_text`, `balance_sentence`, then `balance_value_text` when non-empty.
  - Keep the current fixed sentence as the fallback when `$entry` is empty, as Points earned's template does.
  - Plain text goes through `Text::plain()`.
  - The file names stay unchanged: they are the theme-override contract.
- [ ] **Step 5: Exclude birthday rows from Points earned.** In `PointsEarnedEmail::accepts()`, add `LedgerSource::BIRTHDAY !== $entry->get_source()`, and update its docblock to say birthday awards have their own email.
- [ ] **Step 6: Run** `composer test:integration -- --filter 'BirthdayPointsEmailTest|PointsEarnedEmailTest'`.
  - Expected: `BirthdayPointsEmailTest` `OK (3 tests, …)`.
  - `PointsEarnedEmailTest` is no worse than on `dev/edd`, where `test_the_value_line_shows_while_the_discount_reward_is_live` already fails on this site's VND currency. That one failure only.
  - Then `composer lint -- <the five files>`: no findings.
- [ ] **Step 7: Verify on the Local site.**
  - Users 1 and 22 already hold a 2026 birthday row, so use user 23. Give it an email address if it has none.
  - Run `( new CustomerRepository() )->set_birthday( 23, 10, 19 )`, then `do_action( 'yayloyalty_birthday_run' )` in `wp eval`. Site today is 2026-10-19, and campaign 18 is live (150 points, 0 days before).
  - Expected: user 23 has one +150 `birthday` row, and YaySMTP → Email Logs shows one email to user 23 whose subject has "Happy birthday" and whose body says "To celebrate your birthday, we added 150 … to your account." There is no "You earned" email for it.
  - Then clear user 23's birthday. The ledger row stays.
- [ ] **Step 8: Verify the preview.** WooCommerce → Settings → Emails → Birthday points → preview. Expected: the greeting for the viewing admin, "To celebrate your birthday, we added 150 Points to your account." (in the site's own points label), and the balance sentence with 300.
- [ ] **Step 9: Commit.**
  - Stage: the five files above.
  - Message: `feat(birthday): send the birthday points email for birthday awards`.
