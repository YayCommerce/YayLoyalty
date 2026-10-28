# Reward Claimed Email Design

**Status:** accepted 2026-10-27
**Related:** [Redemption design](2026-10-16-redemption-design.md) (left `RewardClaimedEmail` and `CouponIssuedEmail` untriggered), [the points discount and rewards stack](../decisions/2026-10-22-stack-points-discount-and-rewards.md)

## Goal

On Pro, a customer whose order spends points, through the points discount slider, a `fixed_coupon` or `percent_coupon` reward, or any mix of them, gets one "Reward claimed" email for that order: what they spent, on which lines, and the balance left. The never-sent "Coupon issued" email is removed, because no coupon is ever issued under the current redemption design.

## Context

- Pro registers four emails in `includes/Pro/Emails/ProEmails.php::add_classes()`. `RewardClaimedEmail` and `CouponIssuedEmail` (group `ProEmails::GROUP_REWARDS`, "Loyalty: Rewards") are stubs: an id, a title, a default subject and heading, templates with one fixed sentence, `$is_enabled_by_default = false`, and no trigger.
- They were written for a stored-coupon lifecycle: claim a reward for points (Reward claimed), receive a coupon code to use later (Coupon issued). `Redemption::STATUS_ISSUED` / `STATUS_USED`, the `coupon_id` and `expires_at` columns and each reward's `coupon_expiry_days` are what is left of it. The 2026-10-22 decision made rewards virtual coupons applied at checkout, so the claim and the use are one moment and nothing is issued. The two emails describe one event, and Coupon issued has nothing to report.
- After a redemption commits, Lite fires `yayloyalty_points_redeemed( Outcome $outcome, \WC_Order $order )` once per order, from `Transaction::after_commit()` (`includes/Engine/Redemption/RedemptionService.php::commit()`). It fires for slider-only, reward-only and mixed orders. `$outcome->get_entry()` is the REDEEM ledger row written last in the commit (`CommitWriter::commit()`, `commit_rewards()`, `commit_all()`), so its `balance_after` is the balance after every line of the order. REDEEM rows carry `source = order`, `reference_type = order`, `reference_id = <order id>` (`RedemptionDraft::of()`).
- Each committed line has a `yayloyalty_redemptions` row (`RedemptionRepository::for_order()`): `reward_id`, `points`, `value` (the line's discount in `currency`, the order's currency), `status` (`applied`, `cancelled`, `refunded`). The slider's reward is the seeded `flexible_discount` reward; the card labels its row "Points discount".
- The ledger-row email pattern: `LedgerEntryEmail` (`includes/Engine/Emails/LedgerEntryEmail.php`) reloads the row from the id its email action passes, asks `accepts()`, then sends through `LoyaltyEmail::send_prepared()`. `BirthdayPointsEmail` is the Pro example: it extends `LedgerEntryEmail` directly, overrides `templates_dir()` to `includes/Pro/Emails/templates/`, builds `amounts()` and `build_values()` on top of `balance_values()`, and has a `prepare_preview()`.
- The id-only email action pattern: `Emails::add_email_actions()` adds the action to `woocommerce_email_actions`, so WooCommerce builds its mailer and relays `{action}_notification`, which the email listens to. Only scalars cross WooCommerce's deferred queue.
- YayMail: `includes/Integrations/YayMail/YayMailIntegration.php` (Lite) registers the adapters for Points earned, Points expired and Points expiring soon on YayMail's `yaymail_register_emails` and `yaymail_register_shortcodes`, once YayMail 4.4.7 or later is active and licensed. Each adapter extends `LoyaltyYayMailEmail`, each shortcode set extends `LoyaltyEmailShortcodes`, and every shortcode value comes from the email's `get_template_values()`. Lite cannot name a Pro class (hard rule 2), so a Pro adapter needs a seam.

## Scope

**In:**
- `RewardClaimedEmail` (Pro) implemented: triggered once per order whose redemption commits, on classic and block checkout, for the slider, rewards or both. One line per `applied` redemption row on the order.
- HTML and plain templates rebuilt in the Birthday points email's structure.
- `CouponIssuedEmail`, `templates/emails/yayloyalty-coupon-issued.php` and `templates/emails/plain/yayloyalty-coupon-issued.php` deleted, and the class dropped from `ProEmails::add_classes()`.
- A Lite migration that deletes the option `woocommerce_yayloyalty_coupon_issued_settings`.
- A YayMail design for Reward claimed: a Lite seam of two actions, and a Pro adapter and shortcode set.
- `docs/architecture.md` updated: the Emails and YayMail paragraphs.
- A `readme.txt` changelog line.

**Out:**
- `ExpiringFinalReminderEmail`: not touched.
- Lite: no Reward claimed email, no Lite email for the slider. Lite's emails table is unchanged.
- A YayMail design for Birthday points (a backlog item, cheap once the seam exists).
- An activity-log row for the send, the Emails tab's "Send test" button, a CTA button.
- Stored coupons, a claim-from-My-Account flow, the `issued` / `used` statuses. Coupon issued comes back only with them, under a new decision record.
- An email on cancel, refund or restore of a redemption.
- Changing `$is_enabled_by_default`: the email stays off until the merchant turns it on.

## Acceptance criteria

1. **Registered on Pro only.** On Pro, WooCommerce → Settings → Emails and YayLoyalty → Settings → Emails list "Reward claimed" under "Loyalty: Rewards", recipient customer, off on a store that never saved it. "Coupon issued" is listed in neither. On Lite neither email is listed, and the "Loyalty: Rewards" group is absent.
2. **Slider only.** Pro, email on, customer balance 1,000, store rate 100 points = 1 unit. A classic checkout with 500 points on the slider commits; the customer receives exactly one "Reward claimed" email with one line, "Points discount", "500 Points", the line's discount (`5.00` in the order's currency, formatted with `wc_price()`), the order number linking to the order's My Account view, and "Your balance is now 500 Points."
3. **Rewards only.** A block checkout with reward A (500 points, `5.0000`) and reward B (1,500 points, `10.0000`) commits from a balance of 9,850; one email lists two lines in redemption-row order, A then B, the reward names, their points and discounts, and "Your balance is now 7,850 Points." The `{points}` placeholder is `2,000`.
4. **Mixed order.** An order carrying reward A and 300 slider points sends one email with two lines (A, then "Points discount"), `{points}` the sum of both rows, and the balance after both.
5. **Exactly once.** A repeated `woocommerce_checkout_order_processed` or `woocommerce_store_api_checkout_order_processed` for the same order, which `CommitWriter` answers as a duplicate, sends nothing more (`yayloyalty_points_redeemed` only fires on `Outcome::REDEEMED`).
6. **Stale deferred send.** With WooCommerce's deferred emails on, an order cancelled before the deferred send runs (every redemption row `cancelled`) sends nothing. A row partly restored by a refund but still `applied` is listed with its original points and value.
7. **Not capped.** With `emails.throttle_per_week = 1` and one loyalty email already sent this week, the Reward claimed email still sends, and `EmailThrottle` records it.
8. **Switched off.** With the email off in WooCommerce, a committed redemption sends nothing and writes nothing.
9. **Placeholders.** Default subject `[{site_title}]: You used {points} {points_label} on order #{order_number}`, default heading `Reward claimed`. `{points}`, `{points_label}`, `{balance}` and `{order_number}` resolve in subject, heading and additional content.
10. **Preview.** WooCommerce's email preview of Reward claimed shows sample data: two lines ("Points discount", 500 Points; a sample reward, 1,000 Points), sample order number, a balance of 300 Points, greeting the current user. No PHP notice.
11. **Templates.** `yayloyalty-reward-claimed.php` (HTML and plain) follow the Birthday points templates: header and footer hooks, `email-introduction` wrapper under `email_improvements`, greeting, the claimed sentence, the lines, the balance sentence, the balance value sentence, additional content. Both list every variable in their docblock. A theme copy under `woocommerce/emails/` overrides each. With no prepared row (WooCommerce's dummy order preview) they print a fallback sentence and no warning.
12. **YayMail.** With YayMail 4.4.7+ active and licensed on Pro, YayMail lists "Reward claimed" under YayLoyalty. Its starting design is the logo, the heading with `{points}`-style placeholders rewritten as shortcodes, a body of greeting, claimed sentence, lines and balance sentence, and the footer. Its shortcodes render the same values as the WooCommerce template, sample values in the editor and test send. Enabling the YayMail template sends that design. On Lite with YayMail active, nothing new is listed.
13. **Coupon issued gone.** After upgrading a site where `woocommerce_yayloyalty_coupon_issued_settings` exists, the option is deleted. No file in the package names `CouponIssuedEmail` or `yayloyalty_coupon_issued`, except the migration's option name.
14. **Build checks.** `./scripts/run.sh release lite --no-prompt` validates: no Lite PHP names `Pro` beyond `includes/YayLoyalty.php`.
15. End-to-end verification: on the local Pro site with "Reward claimed" on and a mail catcher, place one block-checkout order with a Pro reward plus slider points as a customer; exactly one "Reward claimed" email arrives listing both lines and the new balance matching the customer drawer's balance. Then enable YayMail's design for it and place a second order; the YayMail design arrives with the same values.

## Constraints

- Product PHP runs on PHP 7.4 (hard rule 4 and `.claude/rules/php-standards.md`).
- Lite names Pro in no new place (hard rule 2). The YayMail seam's actions and the migration live in Lite and name no Pro class; Pro attaches only from `YayLoyaltyPro::boot()`.
- No ledger or redemption write: the email reads `LedgerRepository`, `RedemptionRepository` and `RewardRepository` only (hard rule 5).
- Orders are read only through `wc_get_order()` (HPOS).
- Only the ledger row id crosses WooCommerce's deferred email queue.
- Email template file names and the variables they receive are a public contract; this is their first real version, documented in each template's docblock.
- Money: a line's discount is shown in the redemption row's `currency` (the order's), the amount the customer saw at checkout, through `wc_price( $value, [ 'currency' => $currency ] )`. The balance value stays in the base currency through `LoyaltyEmail::balance_value()`, as every loyalty email does.
- Points through `SettingsHelper::format_number()` / `points_text()` / `points_label()`.
- New strings in the `yayloyalty` text domain; every string with a placeholder has a `translators:` comment; whole sentences, never assembled fragments.
- New hooks have docblocks.
- Tests follow CLAUDE.md § Tests: none is planned (no money math or ledger write is added); a test is added only if the user accepts one when asked.

## Approach

**Chosen:** a Pro `LedgerEntryEmail` on Lite's existing `yayloyalty_points_redeemed`, with a two-action YayMail seam in Lite.

**Architecture:** `RewardClaimedEmail` listens to `yayloyalty_points_redeemed` and relays the Outcome's REDEEM row id on its own email action, which `ProEmails` adds to `woocommerce_email_actions`. WooCommerce then relays `{action}_notification` to `LedgerEntryEmail::trigger()`, which reloads the row and asks `accepts()`. `build_values()` reads the order's `applied` redemption rows and turns them into lines. YayMail support reuses Lite's adapter base classes; Lite's integration fires two actions inside YayMail's register hooks so Pro can register its adapter and shortcodes without Lite naming them.

**Components:**
- `includes/Pro/Emails/RewardClaimedEmail.php` [edit]: extends `LedgerEntryEmail` (not `ProLoyaltyEmail`), `ID = 'yayloyalty_reward_claimed'`, `ACTION = 'yayloyalty_reward_claimed_email'`, `$is_enabled_by_default = false`, `$is_capped = false`, `email_group = ProEmails::GROUP_REWARDS`, `templates_dir()` → `__DIR__ . '/templates/'`. Constructor: `add_action( 'yayloyalty_points_redeemed', [ $this, 'relay' ], 10, 2 )` and `add_action( self::ACTION . '_notification', [ $this, 'trigger' ] )`. Title "Reward claimed"; description "Sent when a customer spends points on an order, through the points discount or a reward."
  - `relay( Outcome $outcome, \WC_Order $order )`: fires `self::ACTION` with `$outcome->get_entry()->get_id()`; does nothing when the entry or its id is null.
  - `accepts( LedgerEntry $entry )`: `LedgerType::REDEEM === get_type()`, `ReferenceType::ORDER === get_reference_type()`, a non-null reference id, and at least one `applied` row from `lines()`.
  - `lines( LedgerEntry $entry )`: the order's `RedemptionRepository::for_order()` rows with status `applied`, oldest first, memoised per entry.
  - `amounts()`: `points` (sum of the lines' points, formatted), `points_label` (for that sum), `balance` (`balance_after`, formatted), `order_number` (`$order->get_order_number()`, '' when the order is gone).
  - `build_values()`: `balance_values()` plus `order` (`\WC_Order|null`), `order_number`, `order_url` (`$order->get_view_order_url()`, '' without an order), `claimed_text`, `reward_lines`, `reward_lines_html`.
  - `prepare_preview()`: a made-up REDEEM entry for the current user (−1,500 points, balance 300) with two in-memory lines, "Points discount" 500 Points `5.00` and "Free gift reward" 1,000 Points `10.00` in the store currency, order number `1234`, no order URL. The lines are injected so the preview reads no database rows.
- `includes/Pro/Emails/templates/emails/yayloyalty-reward-claimed.php` and `plain/yayloyalty-reward-claimed.php` [edit]: Birthday points' structure. Body: greeting, `claimed_text`, the lines (HTML: a `<table>` of name / points / discount; plain: one `- Name: 500 Points (−$5.00)` line each), `balance_sentence`, `balance_value_text`, additional content. Fallback with no `entry`: "You used your loyalty points on an order. Enjoy!"
- `includes/Pro/Emails/ProEmails.php` [edit]: drops `CouponIssuedEmail` from `add_classes()`; adds `add_email_actions( array $actions )` appending `RewardClaimedEmail::ACTION`, hooked from `boot()` on `woocommerce_email_actions`.
- `includes/Pro/Emails/CouponIssuedEmail.php`, `templates/emails/yayloyalty-coupon-issued.php`, `templates/emails/plain/yayloyalty-coupon-issued.php` [delete].
- `includes/Pro/YayLoyaltyPro.php` [edit]: `add_filter( 'woocommerce_email_actions', [ ProEmails::class, 'add_email_actions' ] )`; `add_action( 'yayloyalty_yaymail_register_emails', [ ProYayMail::class, 'register_emails' ] )`; `add_action( 'yayloyalty_yaymail_register_shortcodes', [ ProYayMail::class, 'register_shortcodes' ] )`.
- `includes/Pro/Integrations/YayMail/ProYayMail.php` [new]: the two callbacks; names the adapter classes only inside them, so nothing autoloads them without YayMail.
- `includes/Pro/Integrations/YayMail/RewardClaimedYayMailEmail.php` [new]: extends `LoyaltyYayMailEmail`, YayMail's `SingletonTrait`, `email_id()` → `RewardClaimedEmail::ID`, `default_body()`: greeting, `[yaymail_yayloyalty_claimed_points]`, `[yaymail_yayloyalty_reward_lines]`, balance sentence.
- `includes/Pro/Integrations/YayMail/RewardClaimedShortcodes.php` [new]: extends `LoyaltyEmailShortcodes`. Shortcodes (key → template value): `claimed_points` → `claimed_text`, `reward_lines` → `reward_lines_html`, `points`, `points_label`, `balance`, `balance_points` → `balance_text`, `balance_value`, `balance_value_text`, `order_number`, `order_url`. `HTML_KEYS`: `claimed_points`, `reward_lines`, `balance_value`, `balance_value_text`. `order_url` is escaped with `esc_url()`, as `points_url` is today.
- `includes/Integrations/YayMail/YayMailIntegration.php` [edit]: `register_emails()` ends with `do_action( 'yayloyalty_yaymail_register_emails', $yaymail_emails )`; `register_shortcodes()` ends with `do_action( 'yayloyalty_yaymail_register_shortcodes' )`. Class docblock says other editions' emails register through them.
- `includes/Integrations/YayMail/LoyaltyEmailShortcodes.php` [edit]: `render()` escapes every key ending in `_url` with `esc_url()` (today only `points_url`).
- `includes/Integrations/YayMail/LoyaltyYayMailEmail.php` [edit]: `placeholders_to_shortcodes()` also maps `{order_number}` → `[yaymail_yayloyalty_order_number]`.
- `includes/Integrations/YayMail/templates/yaymail-loyalty-email.php` [edit]: docblock no longer lists the emails by name.
- `includes/Database/Installer.php` [edit]: `run_migrations()` calls `delete_option( 'woocommerce_yayloyalty_coupon_issued_settings' )`.
- `docs/architecture.md` [edit], `readme.txt` [edit].

**Data and storage:** No new table, column, option or meta. The email's on/off and texts live in WooCommerce's `woocommerce_yayloyalty_reward_claimed_settings`, as today. The migration deletes `woocommerce_yayloyalty_coupon_issued_settings`; it is idempotent and reaches existing sites at the next version bump (`Installer::maybe_upgrade`). Nothing new to remove on uninstall.

**Integration points:**
- Listens: `yayloyalty_points_redeemed` (Lite, existing, public).
- Fires: `yayloyalty_reward_claimed_email( int $entry_id )` (Pro, new), relayed by WooCommerce as `yayloyalty_reward_claimed_email_notification`.
- Filters: `woocommerce_email_actions` (adds the action), `woocommerce_email_classes` (existing).
- Lite seam: `yayloyalty_yaymail_register_emails( \YayMail\Emails\YayMailEmails $yaymail_emails )` and `yayloyalty_yaymail_register_shortcodes()` (new; documented as fired inside YayMail's register hooks).
- YayMail: `yaymail_register_emails`, `yaymail_register_shortcodes` through the seam; YayMail 4.4.7 floor unchanged.

**UI:** None in the SPA. The Emails tab lists the Pro emails from WooCommerce's registry, so the Coupon issued row disappears and the Reward claimed row shows its new description with no SPA change.

**Edge cases and failure modes:**
- Guest order: nothing commits, so nothing fires.
- Order deleted before a deferred send: `accepts()` still finds rows by order id; `order_number` and `order_url` are '' and the template drops the order sentence part; the email still sends.
- Reward deleted after the claim: the reward-delete route refuses a reward with redemption rows, but a missing reward falls back to the label "Reward".
- The slider's row: always labelled "Points discount", whatever the seeded reward's name.
- Partial refund before a deferred send: rows still `applied` are listed with their original points and value; only fully `cancelled` / `refunded` rows drop out.
- Order in a currency other than the base (YayCurrency): line discounts show in the order's currency; the balance value in the base currency.
- A REDEEM row from another source (none today) without applied rows: not accepted.
- YayMail inactive, older than 4.4.7 or unlicensed: the seam never fires, the WooCommerce template sends.

**Decision record:** None. This builds inside the 2026-10-22 decision, which already left Coupon issued with nothing to report.

## Alternatives considered

| Option | Why rejected |
|---|---|
| Keep both emails and build stored coupons (claim, then a coupon code by Coupon issued) | A claim flow, a coupon lifecycle and expiry, and a new decision record superseding 2026-10-22; far beyond an email change. |
| Keep `CouponIssuedEmail` registered but hidden | Dead code and templates with nothing to send; it can be rebuilt if stored coupons ever arrive. |
| Report only Pro rewards, not the slider | Superseded by the user's decision: one Pro receipt for every way an order spends points. |
| A Lite "Points redeemed" email that Pro extends | The user wants no slider email in Lite. |
| Send when the order is paid (processing/completed) | The points are debited at placement; the email would lag the balance change it reports. |
| Relay the order id instead of the ledger row id | The REDEEM row gives the post-order balance and reuses `LedgerEntryEmail`'s trigger, as Birthday points does. |
| Capped by `emails.throttle_per_week` | It is a receipt for the customer's own action; the user chose uncapped. |
| Put the Pro YayMail adapter in `includes/Integrations/YayMail/` | Lite would name a Pro class (hard rule 2); the validator fails the Lite zip. |

## Rollout

- Stores that never saved the Reward claimed settings keep it off; nothing changes until a merchant turns it on.
- The Coupon issued option is removed at the next version bump. Release note: "Pro: the Reward claimed email now sends when a customer spends points on an order. The Coupon issued email, which was never sent, is removed."

## Open questions

None.
