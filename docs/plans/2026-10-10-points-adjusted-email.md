# Points Adjusted Email Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. There is no spec; the Requirements section below is the source, with [the backlog item](../backlog/2026-10-08-adjust-points-customer-note-and-email-not-sent.md) as background.

**Status:** active
**Branch:** `<name>/feat/points-adjusted-email` from `dev/edd`; PR into `dev/edd`
**Goal:** make the Adjust points dialog's "Notify the customer by email" checkbox send a new "Points adjusted" loyalty email.
**Spec:** none, planned from the request.
**Architecture:** A new Lite `LoyaltyEmail` subclass, `PointsAdjustedEmail`, built like `PointsEarnedEmail`: the adjust endpoint gets a `notify` flag, and when it is set the controller hands the committed ledger row id to `Emails::relay_adjustment()`. That fires a WooCommerce email action, so WooCommerce sends it now or deferred, as it does for Points earned. The email is not sent from `yayloyalty_ledger_recorded`, because that hook doesn't know about the per-request notify choice and `LedgerService` must stay unaware of email. Three helpers that both emails need move from `PointsEarnedEmail` up to `LoyaltyEmail` instead of being copied.
**Touches:** `includes/Engine/Emails/LoyaltyEmail.php` (shared: base of all 7 loyalty emails; only protected static helpers are added), `PointsEarnedEmail.php`, `Emails.php`, new `PointsAdjustedEmail.php` and its two templates; `includes/Controllers/CustomerRestController.php`, `includes/Controllers/Schemas/CustomerRouteArgs.php`; SPA: `customer.schema.ts`, `customer.api.ts`, `CustomersAdjustPointsDialog.tsx`, `AdjustPointsOptions.tsx`; the backlog item.
**Tests:** none. No ledger write or points math changes: the email reads a committed row, and the balance value reuses the existing `PointsMath` helper unchanged. Verified by hand with a mail catcher.

## Requirements

- **Goal:** an admin who ticks "Notify the customer by email" when adjusting points (single or bulk) causes each adjusted customer to get a "Points adjusted" email that says how many points were added or removed and gives the new balance.
- **Out of scope:** the "Custom description for the customer" field (it needs a ledger column and a migration; its `TODO(adjust-points)` stays, and so does its part of the backlog item); a YayMail template for the new email; an activity-log row for the send; telling the admin, in the response or the UI, whether the email was actually sent; refund or expiry emails.
- **Acceptance criteria:**
  1. WooCommerce → Settings → Emails lists "Points adjusted" in the "Loyalty: Points" group, enabled by default. Its preview renders a sample "+50" adjustment. The plugin's Settings → Emails page lists it too.
  2. `POST /yayloyalty/v1/customers/{id}/adjust` accepts an optional boolean `notify`, which defaults to `false`. With `notify: true` the customer gets one email per request. With `notify` false or missing, no email is sent.
  3. A grant of 50 reads "We added **50 Points** to your account." A deduction of 50 reads "We removed **50 Points** from your account." Both are followed by "Your balance is now **N Points**." Subject: `[{site_title}]: Your {points_label} balance was updated`. Heading: `Your {points_label} balance was updated`. Both are editable in WooCommerce.
  4. No email is sent when "Points adjusted" is disabled in WooCommerce, or when the customer has reached the weekly cap (`emails.throttle_per_week`). A send that the transport accepts counts against the cap.
  5. End to end: in the admin SPA, a single adjustment with the box ticked delivers one email to the mail catcher, a bulk adjustment of 2 customers with the box ticked delivers 2 emails, and the same adjustments with the box unticked deliver none.
- **Decided with the user:**
  - Scope → email only. The customer-facing description stays a backlog item.
  - Direction → both grants and deductions, each with its own wording.
  - Gates → both apply: the email's WooCommerce on/off setting and the weekly cap. Note: the "response says whether it was sent" part of that option is out of scope here (see Out of scope), because WooCommerce may defer the send past the response.

## Global constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union types, `mixed`/`static` return types, or trailing commas in parameter declarations.
- Only `LedgerService` writes ledger rows and balances. This plan adds no writer. The email only reads rows, through `LedgerRepository::find()`.
- Lite code only: everything lives outside `includes/Pro/` and `apps/admin/src/pro/`.
- WordPress style per `phpcs.xml.dist`; lint only changed files with `composer lint -- <files>`. Whole-repo `composer lint` already exits 1 on `dev/edd` (warnings in three unrelated files). Your files must add no error or warning.
- Text domain `yayloyalty`; strings with placeholders get `/* translators: */`. One whole sentence per msgid, so translators never assemble fragments.
- Email template file names and their variables are a public contract: document every variable in both templates' docblocks, and keep the two lists identical.
- Wire casing is snake_case on the wire, camelCase in the SPA, and the mapping happens in `customer.api.ts`.
- Comments at minimal depth: one-line docblocks, a why only where code would look wrong. Every fired hook gets a docblock.
- Admin SPA checks: ESLint already fails on `dev/edd` (`no-empty` in `api.ts`) and `tsc` already fails with TS2307 for `@emoji-mart/*` and `@radix-ui/react-progress`. Add no new error or warning, and run Prettier on changed files only. To avoid pnpm's auto-install, run tools directly from `apps/admin`, for example `node node_modules/typescript/bin/tsc --noEmit`.
- Stage files by name; `./scripts/run.sh dev` can rewrite `composer.json` and `scoper.inc.php`, so leave those out.

## Review focus

- **Deduction below zero:** the email for a removal that leaves a negative balance must read "Your balance is now -20 Points." and must not show a "worth $X" line. `balance_value()` already returns '' for amounts ≤ 0. Verified in Task 2, Step 3.
- **Notify off is the default:** an old SPA build or any other API client that sends no `notify` must never email. Verified in Task 2, Step 2.
- **Bulk sends per request:** a bulk run of N customers makes N POSTs and so up to N synchronous sends, which slows each request. The weekly cap and the WooCommerce toggle still apply to each customer. Verified for 2 customers in Task 4. Larger runs are not load-tested here.
- **Deferred send after a later change:** WooCommerce may send at shutdown or from a background job, so the email reads the row at send time. `balance_after` is stored on the row, so a later change to the balance doesn't alter what the email says. No step needed.
- **Wrong row type:** `trigger()` must ignore any id whose row is not `source = manual` with type `adjust` or `correction`, so a stray action can't send an "adjusted" email for an earn row. Verified in Task 2, Step 3.

---

### Task 1: The Points adjusted email

**Files:**
- Create: `includes/Engine/Emails/PointsAdjustedEmail.php`
- Create: `includes/Engine/Emails/templates/emails/yayloyalty-points-adjusted.php`
- Create: `includes/Engine/Emails/templates/emails/plain/yayloyalty-points-adjusted.php`
- Modify: `includes/Engine/Emails/LoyaltyEmail.php`, `includes/Engine/Emails/PointsEarnedEmail.php`, `includes/Engine/Emails/Emails.php`

**Interfaces:**
- Produces: `PointsAdjustedEmail::ID = 'yayloyalty_points_adjusted'`; `Emails::POINTS_ADJUSTED_ACTION = 'yayloyalty_points_adjusted_email'`; `PointsAdjustedEmail::trigger( int $entry_id ): void` listening on `Emails::POINTS_ADJUSTED_ACTION . '_notification'`.
- Produces (on `LoyaltyEmail`, moved from `PointsEarnedEmail` unchanged in behaviour): `protected static function greeting_name( int $user_id ): string`, `protected static function balance_value( int $balance ): string`, `protected static function balance_value_text( string $balance_value ): string`.

- [ ] **Step 1: Move the three helpers to `LoyaltyEmail`**

Move `greeting_name()`, `balance_value()` and `balance_value_text()` from `PointsEarnedEmail` (private static) to `LoyaltyEmail` (protected static), with their docblocks and `use` lines (`RewardRepository`, `PointsMath`). Keep their bodies as they are. `PointsEarnedEmail::build_values()` keeps calling them through `self::`.

- [ ] **Step 2: Create `PointsAdjustedEmail extends LoyaltyEmail`**

Model it on `PointsEarnedEmail`, in the same file layout:
- Constructor: `id` = `self::ID`, title `Points adjusted`, description `Sent when a store manager adjusts a customer's points and asks to notify them.`, `email_group` = `Emails::GROUP_POINTS`, placeholders `{points}`, `{points_label}` and `{balance}`, and `add_action( Emails::POINTS_ADJUSTED_ACTION . '_notification', [ $this, 'trigger' ] )`.
- `get_default_subject()` returns `[{site_title}]: Your {points_label} balance was updated`. `get_default_heading()` returns `Your {points_label} balance was updated`.
- `trigger( int $entry_id ): void`: return early unless the row exists, `get_source() === LedgerSource::MANUAL`, `get_type()` is `LedgerType::ADJUST` or `LedgerType::CORRECTION`, and `get_points() !== 0`. Then follow `PointsEarnedEmail::trigger()`: set `$this->object`, check `is_enabled()`, look up the user, `setup_locale()`, prepare, set the recipient, call `send_to_customer()`, and `restore_locale()` in a `finally`. There is no refund check.
- Amounts: `points` = `SettingsHelper::format_number( abs( points ) )`, `points_label` = `SettingsHelper::points_label( abs( points ) )`, `balance` = `format_number( balance_after )`.
- Template values, cached per row like `PointsEarnedEmail::extra_template_args()`: the amounts plus `entry`, `greeting_name`, `adjusted_text`, `balance_text` (`SettingsHelper::points_text( balance_after )`), `balance_value` (only when `RedemptionService::is_available()`, else ''), `balance_value_text`.
- `adjusted_text`: escaped HTML with the points in bold. Use `We added %s to your account.` when points > 0 and `We removed %s from your account.` when points < 0, each its own msgid with a translators comment, and `%s` = `<strong>` + `esc_html( points_text( abs( points ) ) )` + `</strong>`.
- `prepare_preview()`: a made-up `new LedgerEntry( null, get_current_user_id(), 50, 150, LedgerType::ADJUST, LedgerSource::MANUAL )`.
- No `get_template_values()`: that exists for YayMail, which is out of scope.

- [ ] **Step 3: Create the two templates**

Copy the structure of `yayloyalty-points-earned.php` (HTML and plain) into `yayloyalty-points-adjusted.php`. Print `$adjusted_text` where the earned template prints `$earned_text`. Drop `$order_number` and `$cta_url`. The fallback branch (no `$entry`) reads `Your %s balance was updated.`, where `%s` is `SettingsHelper::points_label_plural()`. Both docblocks list the same variables, with the override path `yourtheme/woocommerce/emails/[plain/]yayloyalty-points-adjusted.php`.

- [ ] **Step 4: Register it in `Emails`**

Add `public const POINTS_ADJUSTED_ACTION = 'yayloyalty_points_adjusted_email';`. Append it in `add_email_actions()`. Add `new PointsAdjustedEmail()` to `add_classes()` after `PointsEarnedEmail`.

- [ ] **Step 5: Lint**

Run: `composer lint -- includes/Engine/Emails`
Expected: no errors or warnings.

- [ ] **Step 6: Verify the preview and both email lists**

Open: `http://yaydevs.local/wp-admin/admin.php?page=wc-settings&tab=email`
Expected: "Points adjusted" appears under "Loyalty: Points", enabled.
Open: `…&section=yayloyalty_points_adjusted` and click the preview.
Expected: the heading reads "Your Points balance was updated", the body reads "We added **50 Points** to your account." then "Your balance is now **150 Points**.", and there's no PHP notice in `wp-content/debug.log`.
Open: `http://yaydevs.local/wp-admin/admin.php?page=yayloyalty#/settings/emails`
Expected: "Points adjusted" is listed beside "Points earned".
Open: the Points earned preview.
Expected: it's unchanged from `dev/edd` (the helper move changed nothing).

- [ ] **Step 7: Commit**

Stage: the three created files, `LoyaltyEmail.php`, `PointsEarnedEmail.php`, `Emails.php`
Message: `feat(emails): add the points adjusted email`

### Task 2: The `notify` flag on the adjust endpoint

**Files:**
- Modify: `includes/Controllers/Schemas/CustomerRouteArgs.php` (`adjust()`)
- Modify: `includes/Controllers/CustomerRestController.php` (`adjust_points()`)
- Modify: `includes/Engine/Emails/Emails.php`

**Interfaces:**
- Consumes: `Emails::POINTS_ADJUSTED_ACTION` (Task 1).
- Produces: `notify` in the body of `POST /yayloyalty/v1/customers/{id}/adjust`, `type` `boolean`, `default` `false`, described as `Whether to email the customer about this adjustment.`. Also `public static function relay_adjustment( LedgerEntry $entry ): void` on `Emails`.

- [ ] **Step 1: Add the arg and the relay**

- `CustomerRouteArgs::adjust()`: add `notify` as above.
- `Emails::relay_adjustment( LedgerEntry $entry ): void`: return when `null === $entry->get_id()`. Otherwise `do_action( self::POINTS_ADJUSTED_ACTION, $entry->get_id() )`, with a hook docblock like the one in `relay_award()` and the same phpcs ignore. It's a static method, not a hook subscriber, because the notify choice belongs to the request.
- `CustomerRestController::adjust_points()`: after `adjust()` returns (the row is committed by then), call `Emails::relay_adjustment( $entry )` when `(bool) $request['notify']` is true. The response is unchanged.

- [ ] **Step 2: Verify that notify defaults to off**

With Local's Mailpit open (Local → the site → Tools → Mailpit), adjust customer 1 without `notify`, from `/Users/shrief/Local-Sites/yaydevs/app/public`:

Run: `php -d "mysqli.default_socket=/Users/shrief/Library/Application Support/Local/run/DmdPSy05S/mysql/mysqld.sock" /opt/homebrew/bin/wp eval 'wp_set_current_user(1); $r = new WP_REST_Request("POST", "/yayloyalty/v1/customers/1/adjust"); $r->set_body_params(["points"=>5,"note"=>"plan check"]); echo rest_do_request($r)->get_status();'`
Expected: `200`, and no new email in Mailpit.

- [ ] **Step 3: Verify grant, deduction, negative balance and wrong row type**

Run the same command with `"notify"=>true`, once with `"points"=>50` and once with `"points"=>-50`.
Expected: two emails to customer 1's address, reading "We added **50 Points**…" and "We removed **50 Points**…", each with the matching new balance.
Run it with `"points"=>-100000,"notify"=>true`.
Expected: the email reads "Your balance is now **-N Points**." and has no "worth" line.
Run: `… wp eval 'do_action("yayloyalty_points_adjusted_email", <id of any earn row>);'` (find one with `$wpdb->get_var` on the ledger table, `type = 'earn'`).
Expected: no email.

- [ ] **Step 4: Verify the gates**

Set Settings → Emails → Throttle to 1 per week in the SPA, then run the notify command twice for a customer with no sends this week.
Expected: one email.
Clear the throttle, disable "Points adjusted" in WooCommerce, and run it again.
Expected: no email. Re-enable it afterwards.

- [ ] **Step 5: Lint and commit**

Run: `composer lint -- includes/Controllers/CustomerRestController.php includes/Controllers/Schemas/CustomerRouteArgs.php includes/Engine/Emails/Emails.php`
Expected: no errors or warnings.
Stage: the three files above.
Message: `feat(customers): email the customer when an adjustment asks to notify`

### Task 3: Send the checkbox from the SPA

**Files:**
- Modify: `apps/admin/src/lib/schema/customer.schema.ts` (`adjustPointsSchema`)
- Modify: `apps/admin/src/lib/api/customer.api.ts` (`adjustCustomerPoints`)
- Modify: `apps/admin/src/pages/customers/components/CustomersAdjustPointsDialog.tsx` (`handleSubmit`)
- Modify: `apps/admin/src/pages/customers/components/adjust-points/AdjustPointsOptions.tsx`

**Interfaces:**
- Consumes: the `notify` body arg (Task 2).
- Produces: `AdjustPointsInput.notify: boolean`.

- [ ] **Step 1: Wire `notify` through**

- `adjustPointsSchema`: add `notify: z.boolean()`, and extend the docblock to say that `notify` goes on the wire unchanged.
- `adjustCustomerPoints`: add `notify: input.notify` to `json`.
- `handleSubmit`: pass `notify: state.notifyByEmail`. Bulk reuses the same input through `settleInChunks`, so it needs no change of its own.
- `AdjustPointsOptions.tsx`: delete the `TODO(adjust-points): send notifyByEmail…` comment. Change the component docblock to `The adjustment's options, for both layouts. The customer note does not reach the server yet.` Keep the `customerNote` TODO.

- [ ] **Step 2: Type-check, lint and format the changed files**

Run (from `apps/admin`): `node node_modules/typescript/bin/tsc --noEmit`
Expected: only the TS2307 errors already on `dev/edd`.
Run: `node node_modules/eslint/bin/eslint.js <the four files>` and `node node_modules/prettier/bin/prettier.cjs --check <the four files>`
Expected: no errors or warnings, and Prettier passes.

- [ ] **Step 3: Commit**

Stage: the four files above.
Message: `feat(customers): send the adjust dialog's notify choice`

### Task 4: End-to-end check and backlog update

**Files:**
- Modify: `docs/backlog/2026-10-08-adjust-points-customer-note-and-email-not-sent.md`
- Modify: this plan (Status)

- [ ] **Step 1: Verify in the admin SPA (acceptance criterion 5)**

With `./scripts/run.sh dev` running, open `http://yaydevs.local/wp-admin/admin.php?page=yayloyalty#/customers` with Mailpit open.
Action: adjust one customer by +10 with "Notify the customer by email" ticked.
Expected: one email.
Action: select 2 customers, bulk adjust by −5 with the box ticked, then repeat both adjustments with the box unticked.
Expected: 2 more emails ("We removed **5 Points**…"), then none.

- [ ] **Step 2: Update the backlog item**

Tick the third Done-when box. In "Today", "Suggested fix" and the first and last boxes, keep only the customer-facing description: the notify flag is now sent by the SPA, accepted by `CustomerRouteArgs`, and emailed through `PointsAdjustedEmail`. Rename the title and file to say only the description is missing: `2026-10-08-adjust-points-customer-note-not-sent.md` (use `git mv`).

- [ ] **Step 3: Close the plan and commit**

Set this plan's Status to `done YYYY-MM-DD`.
Stage: the backlog file (old and new paths) and this plan.
Message: `docs(backlog): narrow the adjust-points item to the customer note`
