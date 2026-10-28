# Reward Claimed Email Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. Read the spec linked below before starting; the plan argues from it.

**Status:** active
**Goal:** On Pro, send one "Reward claimed" email per order that spends points (slider, rewards or both), with a YayMail design, and remove the never-sent "Coupon issued" email.
**Spec:** [docs/specs/2026-10-27-reward-claimed-email-design.md](../specs/2026-10-27-reward-claimed-email-design.md)
**Architecture:** `RewardClaimedEmail` becomes a `LedgerEntryEmail`, as `BirthdayPointsEmail` is. `ProEmails` relays Lite's existing `yayloyalty_points_redeemed` as an id-only email action and builds its lines from the order's `applied` redemption rows. YayMail support reuses Lite's adapter bases through two new Lite actions fired inside YayMail's register hooks, so Lite never names a Pro class (hard rule 2).
**Touches:** `includes/Pro/Emails/` (RewardClaimed, ProEmails, Coupon issued removal, templates), `includes/Pro/YayLoyaltyPro.php`, `includes/Pro/Integrations/YayMail/` [new], `includes/Database/Installer.php`, `includes/Integrations/YayMail/` (shared; consumers: `PointsEarnedYayMailEmail`/`PointsEarnedShortcodes`, `PointsExpiredYayMailEmail`/`PointsExpiredShortcodes`, `PointsExpiringSoonYayMailEmail`/`PointsExpiringSoonShortcodes`), `docs/architecture.md`, `readme.txt`.
**Tests:** none. CLAUDE.md § Tests warrants a test only for money or ledger logic, or a reproduced bug. This change adds neither: it reads rows and formats them, and it does no money math or ledger writes. Every task ends in an exact manual check instead.

## Global constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union/`mixed`/`static` types, trailing comma after the last declared parameter, first-class callables (hard rule 4, `.claude/rules/php-standards.md`). No local PHP 7.4 binary exists, so review checks this.
- Lite names Pro only in `includes/YayLoyalty.php`'s guarded `YayLoyaltyPro::boot()` call. Pro attaches only from `YayLoyaltyPro::boot()` (hard rule 2).
- No ledger or redemption write: read `LedgerRepository`, `RedemptionRepository`, `RewardRepository` only (hard rule 5).
- Orders only through `wc_get_order()` (HPOS).
- Only the ledger row id crosses WooCommerce's deferred email queue.
- Email template file names and variables are a public contract: every variable is listed in each template's docblock.
- Line discounts: `wc_price( (float) $row->get_value(), [ 'currency' => $row->get_currency() ] )`, i.e. the order's currency, a deliberate spec choice over `docs/conventions.md` § Money's "Emails stay in the base currency". The balance value stays in the base currency through `LoyaltyEmail::balance_value()`.
- Points through `SettingsHelper::format_number()` / `points_text()` / `points_label()`.
- Strings in the `yayloyalty` text domain, a `translators:` comment on every string with a placeholder, whole sentences only.
- Every new hook has a docblock. A class file opens with namespace, `use` lines, `defined( 'ABSPATH' ) || exit;`, class docblock; no `strict_types`.
- Lint only changed files: `composer lint -- <files>`. Expected: no error and no warning in those files. A whole-repo run exits 1 on `dev/edd` too (docs/known-issues.md).
- Do not touch `includes/Pro/Emails/ExpiringFinalReminderEmail.php` or its templates.

## Review focus

- **Deferred send after a cancel.** An order is cancelled before WooCommerce's deferred send runs, so every row is `cancelled`. Expected: no email. Checked in Task 2 Step 6.
- **Mixed order in one email.** Reward plus slider in one order. Expected: one email with both lines and the balance after both, never two emails. `commit_all()` may write more than one REDEEM row, but only one `yayloyalty_points_redeemed` fires. Checked in Task 2 Step 5.
- **Weekly cap.** The cap is already used up. Expected: the email still sends and counts toward the cap (`$is_capped = false`). Checked in Task 2 Step 7.
- **Shared YayMail base.** The `*_url` escaping and the `{order_number}` mapping in `LoyaltyEmailShortcodes` / `LoyaltyYayMailEmail` change code three Lite emails use. Expected: their YayMail designs render exactly as before. Checked in Task 3 Step 4.
- **Lite zip.** A Lite build with the new seam. Expected: the validator passes and nothing new is listed in YayMail on Lite. Checked in Task 5 Step 3.
- **Multi-currency order.** An order in a currency other than the base. Expected: lines show in the order's currency and the balance value in the base currency. Checked in Task 2 Step 5 (note the currency of each amount).

---

### Task 1: Remove Coupon issued

**Files:**
- Delete: `includes/Pro/Emails/CouponIssuedEmail.php`
- Delete: `includes/Pro/Emails/templates/emails/yayloyalty-coupon-issued.php`
- Delete: `includes/Pro/Emails/templates/emails/plain/yayloyalty-coupon-issued.php`
- Modify: `includes/Pro/Emails/ProEmails.php` (`add_classes()`)
- Modify: `includes/Database/Installer.php` (`run_migrations()`)

**Interfaces:**
- Consumes: nothing.
- Produces: nothing other tasks call.

- [ ] **Step 1: Drop `new CouponIssuedEmail()` from `ProEmails::add_classes()` and delete the three files**

- [ ] **Step 2: In `Installer::run_migrations()`, before the `yayloyalty_run_migrations` action, call `delete_option( 'woocommerce_yayloyalty_coupon_issued_settings' )`**

Add a one-line comment saying it is the settings of the removed Coupon issued email. It is idempotent, so every upgrade may call it. The option name is a literal: Lite names no Pro class.

- [ ] **Step 3: Verify**

Run: `git grep -n "CouponIssued\|coupon_issued" -- includes apps`
Expected: one hit only, the option name in `includes/Database/Installer.php`.

Run: `composer lint -- includes/Pro/Emails/ProEmails.php includes/Database/Installer.php`
Expected: no error or warning in these files.

Open: `/wp-admin/admin.php?page=wc-settings&tab=email` (Pro)
Expected: "Loyalty: Rewards" lists Reward claimed and Expiring – Final Reminder, and not Coupon issued. No PHP notice in `debug.log`.

Migration: in the local site's database, run `INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('woocommerce_yayloyalty_coupon_issued_settings', 'a:0:{}', 'no')` and `UPDATE wp_options SET option_value = '0' WHERE option_name = 'yayloyalty_db_version'`, then load any wp-admin page.
Expected: `SELECT option_name FROM wp_options WHERE option_name = 'woocommerce_yayloyalty_coupon_issued_settings'` returns no row, and `yayloyalty_db_version` is back to `YAYLOYALTY_VERSION`.

- [ ] **Step 4: Commit**

Stage: `includes/Pro/Emails/CouponIssuedEmail.php`, `includes/Pro/Emails/templates/emails/yayloyalty-coupon-issued.php`, `includes/Pro/Emails/templates/emails/plain/yayloyalty-coupon-issued.php`, `includes/Pro/Emails/ProEmails.php`, `includes/Database/Installer.php`
Message: `refactor(emails): remove the never-sent Coupon issued email`

### Task 2: Send Reward claimed through WooCommerce

**Files:**
- Modify: `includes/Pro/Emails/RewardClaimedEmail.php` (rewrite)
- Modify: `includes/Pro/Emails/templates/emails/yayloyalty-reward-claimed.php` (rewrite)
- Modify: `includes/Pro/Emails/templates/emails/plain/yayloyalty-reward-claimed.php` (rewrite)
- Modify: `includes/Pro/Emails/ProEmails.php` (add `add_email_actions()`)
- Modify: `includes/Pro/YayLoyaltyPro.php` (`boot()`)

**Interfaces:**
- Consumes: `yayloyalty_points_redeemed( \YayLoyalty\Engine\Earning\Outcome $outcome, \WC_Order $order )` (Lite, existing); `LedgerEntryEmail` (`trigger()`, `accepts()`, `amounts()`, `build_values()`, `balance_values()`, `prepare()`, `extra_template_args()`); `RedemptionRepository::for_order( int ): Redemption[]`; `RewardRepository::find_reward( int ): ?Reward`.
- Produces:
  - `RewardClaimedEmail::ID = 'yayloyalty_reward_claimed'`, `ProEmails::REWARD_CLAIMED_ACTION = 'yayloyalty_reward_claimed_email'` and `RewardClaimedEmail::ACTION = ProEmails::REWARD_CLAIMED_ACTION`. The name lives on `ProEmails` because WooCommerce reads `woocommerce_email_actions` on `init`, before `WC_Email` loads; naming the email class there would fatal (amended during implementation).
  - Action `yayloyalty_reward_claimed_email( int $entry_id )`, relayed by WooCommerce as `yayloyalty_reward_claimed_email_notification`.
  - `ProEmails::add_email_actions( array $actions ): array`.
  - `get_template_values()` keys, which Task 4's shortcodes read: `points`, `points_label`, `balance`, `order_number`, `order_url`, `order`, `entry`, `greeting_name`, `greeting_text`, `balance_text`, `balance_sentence`, `balance_value`, `balance_value_text`, `claimed_text`, `reward_lines`, `reward_lines_html`.

- [ ] **Step 1: Rewrite `RewardClaimedEmail` as `class RewardClaimedEmail extends LedgerEntryEmail`**

- Constants: `ID`, `ACTION`. Properties: `$is_enabled_by_default = false` and `$is_capped = false`. Set `email_group = ProEmails::GROUP_REWARDS`, title "Reward claimed", description "Sent when a customer spends points on an order, through the points discount or a reward."
- Placeholders: `{points}`, `{points_label}`, `{balance}` and `{order_number}`, each `''`.
- Constructor hook: `add_action( self::ACTION . '_notification', [ $this, 'trigger' ] )`. The relay is not hooked here: the mailer may not exist yet when `yayloyalty_points_redeemed` fires.
- `templates_dir()` returns `__DIR__ . '/templates/'`.
- `get_default_subject()` returns `[{site_title}]: You used {points} {points_label} on order #{order_number}`. `get_default_heading()` returns `Reward claimed`.
- The class docblock says three things. It sends one receipt per order that spends points. It is Pro only. Its lines are the order's `applied` redemption rows.

- [ ] **Step 2: Add the trigger side**

- `public static function ProEmails::relay_redemption( Outcome $outcome ): void`, hooked from `boot()` on `yayloyalty_points_redeemed` (one argument, as `Emails::relay_award()`): if `$outcome->get_entry()` or its id is null, return. Otherwise `do_action( self::REWARD_CLAIMED_ACTION, $entry->get_id() )`. Give the action a docblock in the style of `Emails::relay_award()`, plus the same `DynamicHooknameFound` phpcs ignore.
- `protected function accepts( LedgerEntry $entry ): bool`: true when all four hold:
  - `LedgerType::REDEEM === $entry->get_type()`
  - `ReferenceType::ORDER === $entry->get_reference_type()`
  - `null !== $entry->get_reference_id()`
  - `[] !== $this->lines( $entry )`
- `private function lines( LedgerEntry $entry ): array`
  - Returns `array<int, array{name: string, points: int, points_text: string, discount: string}>`, one entry per `Redemption::STATUS_APPLIED` row of `RedemptionRepository::for_order( reference_id )`, in id order.
  - `name`: "Points discount" (translated) when the reward's `get_type()` is `RewardType::FLEXIBLE_DISCOUNT`, else the reward's `get_name()`. "Reward" (translated) when `find_reward()` returns null.
  - `points_text`: `SettingsHelper::points_text( points )`.
  - `discount`: the `wc_price()` call in Global constraints.
  - Memoise it per entry (by object identity, as `extra_template_args()` does), because `accepts()`, `amounts()` and `build_values()` each call it. While `$this->preview_lines` is non-null, return that instead. Declare `private $preview_lines = null;` (`@var array|null`) and `private $preview_order_number = '';` (`@var string`), untyped as the existing email properties are.
- `private function order( LedgerEntry $entry ): ?\WC_Order`: `wc_get_order( reference_id )`, or null when that is not a `\WC_Order`. While the preview runs it returns null.

- [ ] **Step 3: Add the values**

- `protected function amounts( LedgerEntry $entry ): array`, returning `array{points: string, points_label: string, balance: string, order_number: string}`:
  - `points`: `SettingsHelper::format_number()` of the sum of the lines' `points`.
  - `points_label`: `SettingsHelper::points_label( sum )`.
  - `balance`: `SettingsHelper::format_number( $entry->get_balance_after() )`.
  - `order_number`: `(string) $order->get_order_number()`, else `$this->preview_order_number` while previewing, else `''`.
- `protected function build_values( LedgerEntry $entry ): array` returns `$this->balance_values( $entry )` plus:
  - `order`: `\WC_Order|null`.
  - `order_url`: `$order->get_view_order_url()`, or `''`.
  - `claimed_text`: escaped HTML, the total points in `<strong>`. It has three variants:
    - "You used %1$s on order %2$s." when there is an order URL. `%2$s` is `<a href="esc_url(url)">#number</a>`.
    - The same sentence with plain `#number` when there is a number but no URL.
    - "You used %s on your order." when there is no number.
    - Each is its own sentence with its own `translators:` comment.
  - `reward_lines`: the `lines()` array.
  - `reward_lines_html`: from a `private static function lines_html( array $lines ): string`. The HTML is a `<table class="yayloyalty-reward-lines" cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;">`, one `<tr>` per line, with three cells:
    - `esc_html( name )`
    - `esc_html( points_text )`
    - `discount`, prefixed by a minus. Use the translatable `/* translators: %s: discount amount, e.g. "$5.00". */ '−%s'` (U+2212), unescaped, because it is `wc_price()` HTML.
- `public function prepare_preview(): void`:
  - Set `$this->preview_lines` to two lines. Each `discount` uses `wc_price()` in the store currency:
    - "Points discount", 500 points, `5.00`.
    - "Free gift reward", 1,000 points, `10.00`.
  - Set `$this->preview_order_number = '1234'`.
  - Then `$this->prepare( new LedgerEntry( null, get_current_user_id(), -1500, 300, LedgerType::REDEEM, LedgerSource::ORDER, ReferenceType::ORDER, 0 ) )`.
  - It reads no database rows. Reference id 0 makes `order()` return null, so there is no URL.

- [ ] **Step 4: Rewrite both templates in the Birthday points templates' structure**

These are `templates/emails/yayloyalty-reward-claimed.php` and `plain/yayloyalty-reward-claimed.php`.

- Docblock: override path, "Variables mirror RewardClaimedEmail::build_values() and the plain/HTML template's list", and one `@var` per variable from Task 2's Produces list, plus `email_heading`, `additional_content` and `email`. Each `@var` gets an example and "Absent when there is no claim to show."
- HTML body, all inside `! empty( $entry )`:
  1. Greeting.
  2. `claimed_text` (`wp_kses_post`).
  3. `reward_lines_html`, echoed raw, with a phpcs ignore that gives Birthday's `<bdi>` reason.
  4. `balance_sentence`.
  5. `balance_value_text` (raw, same reason).
- Plain body: greeting and `claimed_text` through `Text::plain()`. Then one line per `reward_lines` item, built as `'- ' . name . ': ' . points_text . ' (−' . Text::plain( discount ) . ')'`. Then the balance sentence and the balance value sentence.
- Fallback when `$entry` is empty: "You used your loyalty points on an order. Enjoy!"
- Keep these exactly as Birthday has them: header and footer hooks, the `email-introduction` wrapper under `email_improvements`, the additional-content block, and the phpcs disables.

- [ ] **Step 5: Wire the action, then verify the sends**

Add `public static function add_email_actions( array $actions ): array` to `ProEmails`, appending `self::REWARD_CLAIMED_ACTION`. Give it the same docblock reason as `Emails::add_email_actions()`. In `YayLoyaltyPro::boot()`, next to the other email filters, add `add_filter( 'woocommerce_email_actions', [ ProEmails::class, 'add_email_actions' ] )` and `add_action( 'yayloyalty_points_redeemed', [ ProEmails::class, 'relay_redemption' ] )`.

Setup:
- Pro site with a mail catcher.
- WooCommerce → Settings → Emails → Reward claimed: turn it on.
- Store rate 100 points = 1 unit. A customer with 9,850 points.
- Pro rewards A (`fixed_coupon`, 500 points, 5.00) and B (1,500 points, 10.00).

Run each order and check its email:
- **Classic checkout, 500 slider points only.** Exactly one email. Subject `[Site]: You used 500 Points on order #N`. One line: "Points discount", "500 Points", "−$5.00". The order number links to `/my-account/view-order/N/`. "Your balance is now 9,350 Points." (AC 2)
- **Block checkout, A and B.** One email. Two lines, A then B. Subject `{points}` is `2,000`. The balance matches the customer drawer. (AC 3)
- **One order with A and 300 slider points.** One email. Two lines, A then "Points discount". `{points}` is `800`. The balance is the balance after both lines. (AC 4)
- **Duplicate trigger.** From a temporary mu-plugin snippet run once on `admin_init`, fire `do_action( 'woocommerce_checkout_order_processed', N )` for the first order, then remove the snippet. No second email. (AC 5)
- **Weekly cap.** Set YayLoyalty → Settings → Emails weekly cap to 1, send any capped loyalty email to the customer, then place a slider order. The Reward claimed email still arrives. (AC 7)
- **Email off.** Turn Reward claimed off and place another order. No email. (AC 8)

Expected: each check above holds, with no PHP notice in `debug.log`.

- [ ] **Step 6: Verify the deferred and stale paths**

Turn on deferred emails with the snippet `add_filter( 'woocommerce_defer_transactional_emails', '__return_true' );`. Place a slider order, then cancel it in the same request before shutdown. For example, use a temporary snippet on `yayloyalty_points_redeemed` at priority 20 that calls `$order->update_status( 'cancelled' )`.
Expected: no Reward claimed email, because every row is `cancelled`. Remove the snippet afterwards. (AC 6)

- [ ] **Step 7: Verify the preview, the templates and lint**

- Open the Reward claimed email at `/wp-admin/admin.php?page=wc-settings&tab=email&section=wc_email_yayloyalty_reward_claimed` and use its preview (WooCommerce's email preview, and the YayLoyalty Emails tab).
  Expected: the greeting is to the current user, with two lines ("Points discount" 500 Points, "Free gift reward" 1,000 Points), "#1234" without a link, and "Your balance is now 300 Points." No PHP notice. (AC 10)
- Copy both templates to `<theme>/woocommerce/emails/` and `<theme>/woocommerce/emails/plain/`, add a marker word, send once, then delete the copies.
  Expected: the marker appears in both HTML and plain. (AC 11)
- Check the strings in the subject, heading and additional content.
  Expected: `{points}`, `{points_label}`, `{balance}` and `{order_number}` resolve in all three. (AC 9)
- Run `composer lint -- includes/Pro/Emails/RewardClaimedEmail.php includes/Pro/Emails/ProEmails.php includes/Pro/YayLoyaltyPro.php includes/Pro/Emails/templates/emails/yayloyalty-reward-claimed.php includes/Pro/Emails/templates/emails/plain/yayloyalty-reward-claimed.php`.
  Expected: no error or warning.

- [ ] **Step 8: Commit**

Stage: `includes/Pro/Emails/RewardClaimedEmail.php`, `includes/Pro/Emails/templates/emails/yayloyalty-reward-claimed.php`, `includes/Pro/Emails/templates/emails/plain/yayloyalty-reward-claimed.php`, `includes/Pro/Emails/ProEmails.php`, `includes/Pro/YayLoyaltyPro.php`
Message: `feat(emails): send the Reward claimed email when an order spends points`

### Task 3: Lite YayMail seam and shared-base changes (shared)

**Files:**
- Modify: `includes/Integrations/YayMail/YayMailIntegration.php`
- Modify: `includes/Integrations/YayMail/LoyaltyEmailShortcodes.php` (`render()`)
- Modify: `includes/Integrations/YayMail/LoyaltyYayMailEmail.php` (`placeholders_to_shortcodes()`)
- Modify: `includes/Integrations/YayMail/templates/yaymail-loyalty-email.php` (docblock)

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - Action `yayloyalty_yaymail_register_emails( \YayMail\Emails\YayMailEmails $yaymail_emails )`.
  - Action `yayloyalty_yaymail_register_shortcodes()`.
  - Both fire only inside YayMail's own register hooks, when YayMail 4.4.7+ is active and licensed.

- [ ] **Step 1: Fire the seam actions**

The last statement of `register_emails()` is `do_action( 'yayloyalty_yaymail_register_emails', $yaymail_emails )`. The last statement of `register_shortcodes()` is `do_action( 'yayloyalty_yaymail_register_shortcodes' )`.

Each action's docblock says it fires inside YayMail's matching hook, only while YayMail is active and licensed, so a subscriber may name YayMail classes. Update the class docblock: other editions' emails register through these actions.

- [ ] **Step 2: Escape every `_url` key in `LoyaltyEmailShortcodes::render()`**

Replace `'points_url' === $key` with `str_ends_with( $key, '_url' )` (WordPress 6.5 polyfills it).

- [ ] **Step 3: Map `{order_number}` and make the template docblock generic**

- In `LoyaltyYayMailEmail::placeholders_to_shortcodes()`, add `'{order_number}' => LoyaltyEmailShortcodes::tag( 'order_number' )`.
- In `yaymail-loyalty-email.php`, change the first docblock line to "A YayLoyalty email, rendered from the merchant's YayMail design."

- [ ] **Step 4: Check the three Lite emails still render the same in YayMail**

Setup: Pro site with YayMail 4.4.7+ active and licensed, and expiry on.

1. Before this task's edits, open the designs for Points earned, Points expired and Points expiring soon in YayMail's editor. For each, note the preview's rendered text and its `Points Page URL` shortcode output.
2. After the edits, open them again.
   Expected: the text is identical and the editor console shows no new error.
3. Send a test of Points earned.
   Expected: it arrives unchanged.

Run: `composer lint -- includes/Integrations/YayMail/YayMailIntegration.php includes/Integrations/YayMail/LoyaltyEmailShortcodes.php includes/Integrations/YayMail/LoyaltyYayMailEmail.php includes/Integrations/YayMail/templates/yaymail-loyalty-email.php`
Expected: no error or warning.

Run: `git grep -n "Pro" -- includes/Integrations/YayMail`
Expected: no new hit naming the `YayLoyalty\Pro` namespace.

- [ ] **Step 5: Commit**

Stage: the four files above
Message: `feat(yaymail): add register actions for other editions' emails`

### Task 4: Reward claimed in YayMail (Pro)

**Files:**
- Create: `includes/Pro/Integrations/YayMail/ProYayMail.php`
- Create: `includes/Pro/Integrations/YayMail/RewardClaimedYayMailEmail.php`
- Create: `includes/Pro/Integrations/YayMail/RewardClaimedShortcodes.php`
- Modify: `includes/Pro/YayLoyaltyPro.php` (`boot()`)

**Interfaces:**
- Consumes:
  - Task 3's two actions.
  - Task 2's `RewardClaimedEmail::ID` and its `get_template_values()` keys.
  - `LoyaltyYayMailEmail` (`email_id()`, `default_body()`).
  - `LoyaltyEmailShortcodes` (`VALUE_KEYS`, `HTML_KEYS`, `yaymail_email()`, `descriptions()`, `tag()`).
  - `LoyaltyEmail::greeting_text()` and `LoyaltyEmail::balance_sentence()`.
- Produces:
  - `ProYayMail::register_emails( $yaymail_emails ): void`.
  - `ProYayMail::register_shortcodes(): void`.

- [ ] **Step 1: Create `final class ProYayMail`**

- `public static function register_emails( $yaymail_emails ): void` (docblock `@param \YayMail\Emails\YayMailEmails $yaymail_emails`) calls `$yaymail_emails->register( RewardClaimedYayMailEmail::get_instance() )`.
- `public static function register_shortcodes(): void` calls `RewardClaimedShortcodes::get_instance()`.
- The docblock says the adapters extend YayMail's classes, so they are first named here, inside YayMail's hooks.

- [ ] **Step 2: Create `class RewardClaimedYayMailEmail extends LoyaltyYayMailEmail`**

- `use YayMail\Utils\SingletonTrait;` and `@method static RewardClaimedYayMailEmail get_instance()`.
- `email_id()` returns `RewardClaimedEmail::ID`.
- `default_body()` is built as `PointsEarnedYayMailEmail::default_body()` is, as four paragraphs:
  1. `greeting_text( tag( 'customer_name' ) )`
  2. `tag( 'claimed_points' )`
  3. `tag( 'reward_lines' )`, in a `<div>` rather than a `<p>`, since it is a table
  4. `balance_sentence( tag( 'balance_points' ) )`

- [ ] **Step 3: Create `class RewardClaimedShortcodes extends LoyaltyEmailShortcodes`**

- Uses YayMail's `SingletonTrait`. `$available_email_ids = [ RewardClaimedEmail::ID ]`. `yaymail_email()` returns `RewardClaimedYayMailEmail::get_instance()`.
- `VALUE_KEYS`:
  - `claimed_points` → `claimed_text`
  - `reward_lines` → `reward_lines_html`
  - `balance_points` → `balance_text`
- `HTML_KEYS`: `claimed_points`, `reward_lines`, `balance_value` and `balance_value_text`.
- `descriptions()`, in this order:
  - `claimed_points`: "Points Used Message"
  - `reward_lines`: "Rewards Used"
  - `points`: "Points Used"
  - `points_label`: "Points Label"
  - `balance`: "Balance"
  - `balance_points`: "Balance With Label"
  - `balance_value`: "Balance Value"
  - `balance_value_text`: "Balance Value Message"
  - `order_number`: "Order Number"
  - `order_url`: "Order URL"

  `order_url` is escaped by Task 3 Step 2.

- [ ] **Step 4: Wire into `boot()`**

Add `add_action( 'yayloyalty_yaymail_register_emails', [ ProYayMail::class, 'register_emails' ] )` and `add_action( 'yayloyalty_yaymail_register_shortcodes', [ ProYayMail::class, 'register_shortcodes' ] )` next to the email filters.

- [ ] **Step 5: Verify in YayMail**

Setup: Pro site with YayMail 4.4.7+ active and licensed.

1. Open YayMail's email list.
   Expected: "Reward claimed" is listed under YayLoyalty.
2. Open its editor.
   Expected: logo, then the heading "Reward claimed", then greeting, claimed sentence, the lines table with the sample lines (Points discount 500, Free gift reward 1,000), and "Your balance is now 300 Points.", then the footer. Every shortcode in the editor menu renders a value except `order_url`, which is `''` for the sample.
3. Send a YayMail test.
   Expected: it arrives with the same sample values.
4. Enable the YayMail template and place a slider plus reward order.
   Expected: the YayMail design arrives, with the same lines, points and balance as Task 2's WooCommerce email for an equivalent order. (AC 12)
5. Deactivate YayMail and place an order.
   Expected: the WooCommerce template sends, with no fatal.

Run: `composer lint -- includes/Pro/Integrations/YayMail/ProYayMail.php includes/Pro/Integrations/YayMail/RewardClaimedYayMailEmail.php includes/Pro/Integrations/YayMail/RewardClaimedShortcodes.php includes/Pro/YayLoyaltyPro.php`
Expected: no error or warning.

- [ ] **Step 6: Commit**

Stage: the three new files, `includes/Pro/YayLoyaltyPro.php`
Message: `feat(yaymail): design the Reward claimed email in YayMail`

### Task 5: Docs and release check

**Files:**
- Modify: `docs/architecture.md` (Emails paragraph, line ~71; YayMail paragraph, line ~72)
- Modify: `readme.txt` (`== Changelog ==`)

**Interfaces:**
- Consumes: Tasks 1–4.
- Produces: nothing.

- [ ] **Step 1: Update `docs/architecture.md`**

Emails paragraph: add the Pro Reward claimed flow, in the style of the existing sentences.
- `yayloyalty_points_redeemed` → `ProEmails::relay_redemption()` → `yayloyalty_reward_claimed_email` (id only), added by `ProEmails::add_email_actions()`.
- `accepts()` requires an `applied` redemption row on the order.
- The cap: the expiry reminder and Reward claimed are uncapped. Replace "today only the expiry reminder" to say so.

YayMail paragraph: add the two seam actions, Pro's `ProYayMail`, and the `RewardClaimed*` adapters.

- [ ] **Step 2: Add the changelog line**

In `readme.txt`, add the line as a `* ` bullet under the existing top entry `= 1.0.0 =` (the plugin is unreleased; the release run stamps the version). The line: "Pro: the Reward claimed email now sends when a customer spends points on an order. The Coupon issued email, which was never sent, is removed."

- [ ] **Step 3: Release validation (AC 13, 14)**

Run: `./scripts/run.sh release pro`, then `./scripts/run.sh release lite --no-prompt`, then `composer install` (docs/known-issues.md: release drops the dev packages).
Expected: both validations pass, including "Lite: includes/Pro/ stripped" and the namespace check.

Run: `unzip -l release/*lite*.zip | grep -i "reward-claimed\|coupon-issued\|ProYayMail"`
Expected: no hit.

On a Lite install with YayMail active, check two places.
Expected: YayMail lists no Reward claimed email. WooCommerce → Settings → Emails has no "Loyalty: Rewards" group. (AC 1)

- [ ] **Step 4: End-to-end (spec AC 15)**

1. On the local Pro site, turn on "Reward claimed" and use a mail catcher.
2. As a customer, place one block-checkout order with a Pro reward plus slider points.
   Expected: exactly one "Reward claimed" email, listing both lines. Its new balance equals the balance in the customer drawer (YayLoyalty → Customers).
3. Enable YayMail's design for Reward claimed and place a second order.
   Expected: the YayMail design arrives with that order's values, in the same format.

Chrome console check: the user runs it, because the local site URL is not in the repo. Open these pages:
- WooCommerce → Settings → Emails, then Reward claimed and its preview
- YayLoyalty → Settings → Emails
- YayMail's Reward claimed editor
- the block checkout

Expected: no new console error.

- [ ] **Step 5: Commit**

Stage: `docs/architecture.md`, `readme.txt`, and this plan with `**Status:** done YYYY-MM-DD` in the PR that finishes it.
Message: `docs(emails): document the Reward claimed email and the YayMail seam`
