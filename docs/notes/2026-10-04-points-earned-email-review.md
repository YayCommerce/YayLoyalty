# Points earned email: open findings from the 2026-10-04 review

**Purpose:** the findings that a code review of `paul/feat/email-points-earned` (against `dev/edd`, 2026-10-04) left unfixed, kept for later. For each one: how the code behaves today, why that is a bug, and a suggested fix. Three display bugs from the same review were fixed in `c80cf62`, and are not listed here.

**Update when:** a finding is fixed or dropped; remove its section. Delete this note when no section is left.

Citations give a path and a symbol; search for the symbol.

## Product behaviour

### 1. The weekly email cap is not enforced

- **Today:** the points earned email sends on every award. `PointsEarnedEmail::trigger()` checks only `is_enabled()`, the points and the recipient. The merchant's cap, `emails.throttle_per_week` (default 3, `SettingsHelper::get_default_settings()`), is read nowhere. The email is on by default (`LoyaltyEmail::$is_enabled_by_default`), so every store that updates starts sending.
- **Why it is a bug:** a customer whose 8 orders complete in one week, plus a signup and a review, gets 10 emails, against a setting that says 3. The settings screen still promises otherwise: `apps/admin/src/pages/settings/EmailsThrottleCard.tsx` says "Not enforced yet — this limit will apply once automatic sending ships", and the comment beside the default in `SettingsHelper` says "not enforced until emails are sent". Sending has shipped, so both are now false.
- **Suggested fix:** count the loyalty emails sent to the customer in the last 7 days, for example in user meta or a small table, and skip the send at the cap. Or ship the email off by default until the cap is enforced, and update both texts either way.

### 2. The email promises what customers cannot use

- **Today:**
  - `PointsEarnedEmail::balance_value_text()` adds "That's worth $X in rewards." whenever `RewardRepository::live_points_per_currency_unit()` is above 0. That is true whenever the built-in cart-discount reward is active, and the installer seeds it active (`Installer::seed_builtin_reward()`).
  - The "View your points" button links to My Account (`yayloyalty_points_earned_cta_url`, default `wc_get_page_permalink( 'myaccount' )`).
- **Why it is a bug:**
  - Redemption is scaffolding: the gates, the handler and the write side are stubs marked `TODO(redemption)`, and nothing hooks checkout. So on a fresh install every award email says the balance is worth money the customer cannot spend.
  - The plugin adds no My Account endpoint or points view, so the button lands on the plain dashboard with no points shown.
- **Suggested fix:** until redemption ships, leave out the value sentence: return 0 from the live rate, or gate it on a "redemption available" check. Then either add a My Account points view, or drop or retarget the button by default.

### 3. The enabled filter sees the previous award

- **Today:** `PointsEarnedEmail::trigger()` calls `$this->is_enabled()` before `prepare( $entry )` sets `$this->object`. WooCommerce's `is_enabled()` passes `$this->object` to `woocommerce_email_enabled_yayloyalty_points_earned`.
- **Why it is a bug:** the mailer instance is shared, so a filter that decides by the award, such as a per-customer opt-out reading `$object->get_user_id()`, sees the previous award's `LedgerEntry`, or null on the first send. In a bulk order completion, customer B's email is suppressed by customer A's opt-out, while A's own check runs against an older entry. WooCommerce's own emails set the object first.
- **Suggested fix:** call `prepare( $entry )` before `is_enabled()`. `trigger()` still checks the points and the recipient after it.

## Open bugs

### 4. Deferred sending fails before WooCommerce 10.8

- **Today:** `Emails::add_email_actions()` adds `yayloyalty_points_awarded` to `woocommerce_email_actions`. With deferral on (`woocommerce_defer_transactional_emails`), WooCommerce queues the action's arguments, `( Outcome $awarded, Context $ctx )`.
  - From 10.8, `DeferredEmailQueue` refuses the objects, and the email sends at once.
  - Before 10.8, `WC_Background_Emailer` saves the queue as a site option, which serializes the arguments. The plugin supports WooCommerce from 10.0 (`yayloyalty.php`, `WC requires at least`).
- **Why it is a bug:** `Context` can still hold its customer-facts loader, a `Closure` from `ContextFactory`, which `Context::get_customer_facts()` replaces only when something reads it. Serializing a `Closure` throws, so on shutdown the save fails, and every email queued in that request is lost, WooCommerce's own order emails included.
- **Suggested fix:** relay only scalars. Hook `yayloyalty_points_awarded` yourself and fire a separate email action with the ledger row's id, so the queue stores an integer and `trigger()` reloads the row. Or resolve the facts before the action fires.

### 5. YayMail subject shortcodes read the mailer's shared email

- **Today:** for a subject render, YayMail passes empty render data. `PointsEarnedShortcodes::values()` then falls back to `PointsEarnedYayMailEmail::get_instance()->get_root_email()`, the `PointsEarnedEmail` held by `WC()->mailer()`, and reads whatever award that instance last prepared.
- **Why it is a bug:** a send made on any other instance, such as a resend tool, or a test that builds `new PointsEarnedEmail()`, gets another award's name and points in its subject, or none.
- **Suggested fix:** take the email from the subject filter's own `$email` argument (`woocommerce_email_subject_{id}` passes it), and pass it through to the shortcode render.

## Test gap

- **Today:** `f4290fe` deleted the feature's integration tests, `tests/integration/Emails/PointsEarnedEmailTest.php` (21 tests) and `YayMailPointsEarnedTest.php`. It also deleted the shared `tests/integration/WooCommerceEmailsFixture.php`, which already existed on `dev/edd`. `WooCommerceOrdersFixture::require_woocommerce()` turns off every WooCommerce email, this one included.
- **Why it is a bug:** nothing exercises the send path. A regression in what the deleted tests checked ships unseen:
  - one send per award, never a second for a duplicate;
  - the award kept when the mailer throws;
  - apostrophes in plain text;
  - the value line hidden while the reward is paused;
  - the preview sample.
  
  Also, `WooCommerceOrdersFixture::detach_yaymail()` can never matter while the email is off, and it removes every subject filter and YayMail's `wc_get_template` hook without putting them back.
- **Suggested fix:** restore the fixture and the email tests, turning this one email back on in them. Then either delete `detach_yaymail()`, or make it restore what it removes.

## Cleanup left open

- **Values rebuilt per render:** `PointsEarnedEmail::extra_template_args()` reruns the reward query in `balance_value()`, `wc_get_order()`, `get_userdata()`, settings reads and both filters on every render. A multipart send renders it twice, and YayMail again. Compute the values once in `prepare()`.
- **The mailer is built on every award:** because the award is relayed through `woocommerce_email_actions`, `WC_Emails::send_transactional_email()` builds the whole mailer on every award, even when the email is off. Item 4's fix, a separate email action, can check the email's enabled option before relaying.
- **The greeting and balance sentences have three copies:** "Hi %s," and "Your balance is now %s." are written out in `templates/emails/yayloyalty-points-earned.php`, in `templates/emails/plain/yayloyalty-points-earned.php`, and in `PointsEarnedYayMailEmail::get_default_elements()`. Building them in `extra_template_args()`, as `earned_text` is built, would remove the template copies. This adds template variables, a public contract, so it was left for the feature's owner.
- **The `&#039;` patch fixes a symptom:** `LoyaltyEmail::get_content_plain()` replaces `&#039;` after esc_html()'d plain templates. Other entities that WooCommerce's plain-text cleanup does not list are still deleted. Not HTML-escaping plain output, or decoding with `ENT_QUOTES`, fixes the cause.
- **A `phpcs:ignore` without a reason:** in `templates/emails/yayloyalty-points-earned.php`, `echo $balance_value_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped` gives no reason after `--`. The reason is in the comment on the line above.
