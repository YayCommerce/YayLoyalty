# Points Earned Email Rendering Tests Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. Read the backlog item linked below before starting; the plan argues from it.

**Status:** done 2026-10-11
**Goal:** Integration tests cover what the points earned email renders: entities in plain text, the value line hidden by default, and the preview sample.
**Spec:** none, planned from `docs/backlog/2026-10-04-points-earned-email-has-no-tests.md`
**Architecture:** Add the tests to the existing `tests/integration/Emails/PointsEarnedEmailTest.php`, which already pins the points label to `Point`/`Points` (`pinned_settings()`) and detaches YayMail through `require_woocommerce()`. The tests are ported from `f4290fe^:tests/integration/Emails/PointsEarnedEmailTest.php` (on `paul/feat/email-points-earned` only) and adjusted to today's code. `Installer::seed_builtin_reward()` is now private, and the value line is gated off by `RedemptionService::is_available()` (`includes/Engine/Redemption/RedemptionService.php:54`), which returns `false`.
**Touches:** `tests/integration/Emails/PointsEarnedEmailTest.php`
**Tests:** accepted by the user, all in `tests/integration/Emails/PointsEarnedEmailTest.php` (integration suite):
- `test_plain_text_decodes_entities` guards `LoyaltyEmail::get_content_plain()`.
- `test_the_value_line_is_hidden_by_default` guards the `is_available()` gate in `LoyaltyEmail::balance_value()`.
- `test_the_preview_shows_a_sample_award` guards `PointsEarnedEmail::prepare_preview()` and the `woocommerce_prepare_email_for_preview` hook.

## Requirements

- **Goal:** A regression in the email's rendering fails `composer test:integration`.
- **Out of scope:** production code; YayMail designs (`PointsEarnedYayMailEmail`), which the fixture detaches.
- **Acceptance criteria:**
  1. Each case under the backlog item's "Why it matters" has a test.
  2. `composer test:integration -- --filter PointsEarnedEmailTest` reports `OK (7 tests, …)`.
- **Decided with the user:** all three tests above.

## Global constraints

- Composer tooling needs PHP ≥ 8.2. Test code may use PHP 8 syntax; product PHP may not, and this plan changes none.
- The integration suite runs inside the Local site with the plugin active. Export `YAYLOYALTY_TEST_SOCKET` (see the header of `tests/integration/bootstrap.php`).
- Stage files by name. Commit messages follow Conventional Commits and end with the trailers CLAUDE.md names.

## Review focus

- A run that executes nothing: `composer test:integration` can exit 0 without running (`docs/known-issues.md`). Read the test count. Covered in Task 1 Step 3.
- Tests that pass without guarding anything: each must fail when the code it guards breaks. Covered in Task 1 Step 4.

---

### Task 1: Add the rendering tests

**Files:**
- Test: `tests/integration/Emails/PointsEarnedEmailTest.php`
- Delete: `docs/backlog/2026-10-04-points-earned-email-has-no-tests.md`

**Interfaces:**
- Consumes: `customer_with_email()` from `WooCommerceOrdersFixture`, `PointsEarnedEmail::prepare_preview()`, `get_template_values()`, `get_content_html()`, `get_content_plain()`, `get_subject()`, the `woocommerce_prepare_email_for_preview` filter
- Produces: the private helpers `preview_as( int $user_id ): PointsEarnedEmail` and `seed_discount_reward(): void`

- [x] **Step 1: Write the tests**

Change the class docblock to "The Points earned email's send path and rendering…". Import `YayLoyalty\Database\Installer`.

```php
/** Guards LoyaltyEmail::get_content_plain()'s entity decoding. */
public function test_plain_text_decodes_entities(): void {
    $user_id = $this->customer_with_email();
    update_user_meta( $user_id, 'first_name', "O'Brien" );

    $plain = $this->preview_as( $user_id )->get_content_plain();

    $this->assertStringContainsString( "Hi O'Brien,", $plain );
    $this->assertStringNotContainsString( '&#039;', $plain );
    $this->assertStringNotContainsString( '<strong>', $plain );
}

/** The value line stays off until redemption ships, even with a live discount reward; it fails on purpose when is_available() flips. */
public function test_the_value_line_is_hidden_by_default(): void {
    $this->seed_discount_reward();
    $email = $this->preview_as( $this->customer_with_email() );

    $this->assertStringNotContainsString( 'worth', $email->get_content_html() );
    $this->assertStringNotContainsString( 'worth', $email->get_content_plain() );
    $this->assertSame( '', $email->get_template_values()['balance_value'] );
}

public function test_the_preview_shows_a_sample_award(): void {
    $user_id = $this->customer_with_email();
    $email   = $this->preview_as( $user_id );
    $html    = $email->get_content_html();

    $this->assertStringContainsString( 'You earned 50 Points', $email->get_subject() );
    $this->assertStringContainsString( 'You earned <strong>50 Points</strong>.', $html );
    $this->assertStringContainsString( 'Your balance is now <strong>150 Points</strong>.', $html );
    $this->assertSame( get_userdata( $user_id )->display_name, $email->get_template_values()['greeting_name'] );
}
```

Helpers:
- `preview_as()` runs WooCommerce's settings preview as `$user_id`: a new `PointsEarnedEmail` with a dummy `WC_Order`, through the `woocommerce_prepare_email_for_preview` filter, resetting the user to 0 in `finally`.
- `seed_discount_reward()` runs the private `Installer::seed_builtin_reward()` through `ReflectionMethod`; it seeds the reward active.

The last assertion assumes `greeting_name` is the display name for a user with no first name. Check it against `LoyaltyEmail::greeting_name()` (`includes/Engine/Emails/LoyaltyEmail.php:138`), and fix the test, not the code, if it differs.

- [x] **Step 2: Run the new tests**

Run: `composer test:integration -- --filter 'test_plain_text_decodes_entities|test_the_value_line_is_hidden_by_default|test_the_preview_shows_a_sample_award'`
Expected: `OK (3 tests, …)`. These guard existing behaviour, so they pass on first run.

- [x] **Step 3: Run the class and the suite**

Run: `composer test:integration -- --filter PointsEarnedEmailTest`, then `composer test:integration`
Expected: `OK (7 tests, …)` for the class. The full suite shows no failures that `dev/edd` doesn't already have.

- [x] **Step 4: Prove each test bites, then revert**

Make each break below, rerun Step 2, then `git checkout` the file:
- `LoyaltyEmail::get_content_plain()` returns `wc_get_template_html()` without the entity callback. Expected: `test_plain_text_decodes_entities` fails.
- `RedemptionService::is_available()` returns `true`. Expected: `test_the_value_line_is_hidden_by_default` fails.
- `PointsEarnedEmail::prepare_preview()` passes `60` instead of `50`. Expected: `test_the_preview_shows_a_sample_award` fails.

Then run `git status`. Expected: only the test file is changed under `includes/` and `tests/`.

- [x] **Step 5: Close the backlog item**

Tick the two open boxes in `docs/backlog/2026-10-04-points-earned-email-has-no-tests.md`, citing the three test names and Step 3's count. Every box is then ticked, so delete the file.

- [x] **Step 6: Commit**

Stage: `tests/integration/Emails/PointsEarnedEmailTest.php`, `docs/backlog/2026-10-04-points-earned-email-has-no-tests.md`, `docs/plans/2026-10-11-points-earned-rendering-tests.md` (Status set to `done YYYY-MM-DD`)
Message: `test(emails): cover the points earned email's rendering`
