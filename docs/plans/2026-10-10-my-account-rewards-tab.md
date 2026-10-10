# My Account Rewards Tab Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. There is no spec; the Requirements section below is the source of truth.

**Status:** active
**Goal:** A "Rewards" tab in WooCommerce My Account, where a signed-in customer sees their points balance, expiry, ways to earn and redeem, and their full points history, which they can filter, page through and download as CSV.
**Spec:** none, planned from the request (wireframe F14, artboard 1d, in `YayLoyalty Storefront.dc.html` of the "YayLoyalty admin interface" wireframe set)
**Architecture:** A WooCommerce account endpoint (`rewards`), rendered on the server from a PHP template with theme-native WooCommerce markup and no script. The data comes from code the launcher already uses: `LauncherPayload` for balance, worth and earn/redeem rules, `LedgerRepository::paginate_for_user()` with `LedgerQueryArgs` groups for history. This is a separate page rather than a launcher view because history needs paging and a download, which a floating panel can't hold.
**Touches:** `includes/Engine/Storefront/` (new `AccountRewards`, `AccountHistoryCsv`, template), `ShopperActivityRow` (shared: consumed by `StorefrontActivityRestController`, `LauncherPreview`), `LauncherPayload` (shared: consumed by `Launcher`, `LauncherPreview`), `Launcher` (shared: `asset_version()` made public), `ScriptName`, `ActDeact`, `includes/YayLoyalty.php`, new `assets/storefront/account.css`, `docs/architecture.md`
**Tests:** none. No points or money math and no ledger writes change: the page only reads, and "worth" reuses `Clamp` through `LauncherPayload`. Each task has a manual check instead.

## Requirements

- **Goal:** a signed-in customer opens My Account → Rewards and sees what they have, how to get more, and everything that happened to their points.
- **Out of scope:**
  - Pro additions: tier section, reward catalog, Referrals tab, the "1.5× FOR YOU" badge.
  - The redemption backend (`TODO(redemption)` in `includes/Engine/Redemption/`): another developer owns it. Nothing in this plan edits those files.
  - The redeem rule's minimum/step/cap sentence ("From 100 points, in steps of 100…"): it would need `Clamp` details that `LauncherPayload` doesn't expose.
  - The signup row in Ways to earn: a member already has the account it pays for (same as `panel-member.php`).
- **Acceptance criteria:**
  1. While the program is on, a signed-in customer's account menu shows "Rewards" before "Account details" (or before "Log out" when the theme removed "Account details"). It links to `/my-account/rewards/`.
  2. While the program is off, the menu item is gone and `/my-account/rewards/` shows the notice "Rewards are not available right now." in place of the content.
  3. A guest opening `/my-account/rewards/` gets WooCommerce's sign-in form.
  4. The points card shows the balance under the Design tab's `balance_heading`, and the expiry date in the site's date format when `customers.expires_at` is set and the balance is above 0.
  5. While `RedemptionService::is_available()` is false, the page shows no "worth", no Ways to redeem and no "Use points at checkout" button. When it turns true, all three appear without a code change.
  6. Ways to earn lists the purchase and review rules the launcher advertises, and is absent for a customer who is opted out or in an excluded role.
  7. History lists the customer's ledger rows newest first, 20 per page, with Date, Description, Points and Balance. Filters All / Earned / Spent / Expired map to the groups `all` / `earn` / `redeem` / `expire` of `LedgerQueryArgs`. Previous/Next keep the filter, and the range reads "1–20 of 63".
  8. "Download my history (CSV)" downloads every row of the customer's history, whatever the filter, as `points-history-YYYY-MM-DD.csv`.
  9. End to end: on the Local site, as a customer with at least 21 ledger rows, open the launcher, click "View all rewards activity", land on the Rewards tab, page to page 2 with the Earned filter on, and download the CSV. The CSV's row count matches the "of N" total under the All filter.
- **Decided with the user:**
  - Styling: theme-native. Use WooCommerce's account markup and classes so the theme styles it, plus a small layout-only stylesheet. Don't use the launcher's Tailwind kit.
  - Redeem parts are hidden until `RedemptionService::is_available()` is true, as in the launcher.
  - Every signed-in member gets the tab while the program is on, because the history is their data. Only Ways to earn is hidden for someone who cannot earn.

## Global constraints

- Product PHP must run on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types.
- Lite names Pro in exactly one place. Nothing in this plan references `includes/Pro/`.
- Only `LedgerService` writes ledger rows and balances. This plan writes none.
- Store and send datetimes as UTC through `includes/Utils/Dates.php`. Display them with `Dates::to_site_tz()`.
- Show points and rates with `SettingsHelper::format_number()` (through `LauncherCopy`) and money through `LauncherCopy::money()`. Never use `number_format_i18n()`.
- One class per file, named for the class, with the path following the namespace.
- Comments are minimal: one-line docblocks, plus a why only where the code would look wrong.
- Never hand-edit `assets/dist/`. `assets/storefront/account.css` is hand-written source, like `launcher.js`.
- Branch from `dev/edd` as `<name>/feat/my-account-rewards`. Commit messages are Conventional Commits.
- `composer lint` fails on `dev/edd` today (see `docs/known-issues.md`). Run phpcs on the changed files only, and expect no errors or warnings in them.

## Review focus

- Someone saves Settings → Permalinks in wp-admin: `/my-account/rewards/` must still resolve afterwards. The query var is registered on every request, not just front-end ones (Task 1, Step 4).
- A site updated in place without reactivating the plugin: the endpoint must work without a manual permalink save (Task 1, Step 4).
- A customer who has never earned has no `customers` row: the card shows 0, no expiry and the empty history state, with no PHP notice (Task 2, Step 4).
- `?points_type=bogus` or `/rewards/999/`: unknown filters fall back to All, and a page past the end shows the empty state, not an error (Task 3, Step 4).
- The CSV must hold only the signed-in customer's rows, and requires a valid nonce. A shared link with a stale nonce gets a 403, not someone else's data (Task 4, Step 3).
- An adjustment the store made with an admin note: the note must not appear on the page or in the CSV (Task 3 and Task 4 verification).

---

### Task 1: Rewards endpoint, menu item and title

**Files:**
- Create: `includes/Engine/Storefront/AccountRewards.php`
- Create: `includes/Engine/Storefront/templates/account-rewards.php` (placeholder body this task, filled in Task 2)
- Modify: `includes/YayLoyalty.php:37-40`
- Modify: `includes/Engine/ActDeact.php:97-98`

**Interfaces:**
- Produces:
  - `final class AccountRewards` in namespace `YayLoyalty\Engine\Storefront`
  - `public const ENDPOINT = 'rewards'`
  - `public const REWRITE_VERSION = '1'`
  - `public const OPTION_REWRITE_VERSION = 'yayloyalty_rewrite_version'`
  - `public static function register(): void`
  - `public static function url( int $page = 1, string $filter = 'all' ): string`: the endpoint URL, with the page as the endpoint value when above 1 and `points_type` when not `all`

- [ ] **Step 1: Implement `AccountRewards::register(): void`**

  It hooks:
  - filter `woocommerce_get_query_vars`: adds `self::ENDPOINT => self::ENDPOINT`. WooCommerce then registers the rewrite endpoint itself.
  - action `init` at priority 99: when `get_option( self::OPTION_REWRITE_VERSION ) !== self::REWRITE_VERSION`, calls `flush_rewrite_rules( false )`, then `update_option( self::OPTION_REWRITE_VERSION, self::REWRITE_VERSION )`.
  - filter `woocommerce_account_menu_items`: when `ProgramGate::program_enabled()`, inserts `self::ENDPOINT => __( 'Rewards', 'yayloyalty' )` before `edit-account`, else before `customer-logout`, else at the end.
  - filter `woocommerce_endpoint_rewards_title`: returns `__( 'Rewards', 'yayloyalty' )`.
  - action `woocommerce_account_rewards_endpoint`: calls `render( $value )`. If the program is off, it prints `wc_print_notice( __( 'Rewards are not available right now.', 'yayloyalty' ), 'notice' )` and returns. Otherwise it includes `templates/account-rewards.php`. A `\Throwable` is caught and logged with `Log::warning( 'AccountRewards', … )`, and the notice is printed instead, as `Launcher::prepare()` does.

- [ ] **Step 2: Wire it in**

  In `includes/YayLoyalty.php`, call `\YayLoyalty\Engine\Storefront\AccountRewards::register();` right before the `if ( ! is_admin() )` launcher block, outside it. Admin requests must register the query var too, or a permalink save in wp-admin drops the endpoint. In `ActDeact::deactivate()`, call `delete_option( AccountRewards::OPTION_REWRITE_VERSION )` so the next activation flushes again.

- [ ] **Step 3: Verify the tab as a customer**

  Open `/my-account/` signed in as a customer.
  Expected: "Rewards" sits right above "Account details", and clicking it opens `/my-account/rewards/` with the page title "Rewards" and the placeholder body. Sign out and open `/my-account/rewards/`: WooCommerce's sign-in form shows. In wp-admin, turn the program off and reload the customer's account: the menu item is gone and the URL shows "Rewards are not available right now."

- [ ] **Step 4: Verify rewrite survival**

  Run: `wp eval 'delete_option( "yayloyalty_rewrite_version" );'` from a plain terminal, pointing mysqli at the Local site's socket (`wp db query` doesn't work there). Then load any front-end page, then open `/my-account/rewards/`. Expected: 200, no 404.
  Then save wp-admin → Settings → Permalinks without changes and open `/my-account/rewards/` again. Expected: 200.

- [ ] **Step 5: Lint**

  Run: `vendor/bin/phpcs includes/Engine/Storefront/AccountRewards.php includes/YayLoyalty.php includes/Engine/ActDeact.php`
  Expected: no errors or warnings.

- [ ] **Step 6: Commit**

  Stage: `includes/Engine/Storefront/AccountRewards.php`, `includes/Engine/Storefront/templates/account-rewards.php`, `includes/YayLoyalty.php`, `includes/Engine/ActDeact.php`
  Message: `feat(storefront): add the My Account rewards endpoint`

### Task 2: Points card, Ways to earn, Ways to redeem

**Files:**
- Modify: `includes/Engine/Storefront/AccountRewards.php`
- Modify: `includes/Engine/Storefront/templates/account-rewards.php`
- Modify: `includes/Engine/Storefront/Launcher.php` (`asset_version()` private → public)
- Modify: `includes/Engine/Register/ScriptName.php`
- Create: `assets/storefront/account.css`

**Interfaces:**
- Consumes: `LauncherPayload::build( array $design, ?int $user_id ): array` (keys `text`, `earn`, `purchase_min`, `redeem`, `member`, `checkout_url`), `DesignHelper::get_design()`, `ProgramGate`, `ContextFactory::from_user()`, `CustomerRepository::find_by_user_id()`, `Customer::get_expires_at(): ?string`
- Produces:
  - `ScriptName::ACCOUNT_STYLE = 'yayloyalty/style/storefront-account'`
  - `Launcher::asset_version( string $relative_path ): string`, now public
  - Template variables: `$view` with keys `payload` (the `LauncherPayload::build()` array), `expires_at` (`?string`, UTC), `can_earn` (`bool`), `history` (Task 3)

- [ ] **Step 1: Build the view in `AccountRewards::render()`**

  `payload` is `( new LauncherPayload( new CampaignRepository(), new RewardRepository(), new CustomerRepository() ) )->build( DesignHelper::get_design(), get_current_user_id() )`.
  `expires_at` is the customer row's `get_expires_at()`, or null when there is no row.
  `can_earn` is `null === ( new ProgramGate() )->check( CampaignTrigger::PURCHASE, ContextFactory::from_user( $user_id, Dates::now() ) )`.

- [ ] **Step 2: Write the card and both rule columns in the template**

  Wrap everything in `<div class="yayloyalty-account">`. Inside it, use only WooCommerce's own classes (for example `woocommerce-Button button`) plus `yayloyalty-account__*` hooks for `account.css`. No `yl:` utilities.

  - **Points card:**
    - Heading: `$payload['text']['balance_heading']`.
    - Number: `LauncherCopy::points( balance )`.
    - When `member['worth']` is not null: `sprintf( __( 'worth %s off your next order', 'yayloyalty' ), LauncherCopy::money( worth ) )`.
    - When `expires_at` is not null and the balance is above 0: `/* translators: 1: points label, 2: date */ sprintf( __( 'Your %1$s expire on %2$s', 'yayloyalty' ), SettingsHelper::points_label( 0 ), Dates::to_site_tz( $expires_at, get_option( 'date_format' ) ) )`.
    - When `redeem` is not null and `worth` is not null: a "Use points at checkout" link to `checkout_url` with class `woocommerce-Button button`.
  - **Ways to earn:** rendered only when `can_earn` and at least one of `earn['purchase']` / `earn['review']` is set. Same rows and copy as `panel-member.php`: "Make a purchase" with `LauncherCopy::earn_rate( text['earn_rate_line'], purchase, purchase_min )`, and "Write a review" with `SettingsHelper::points_text( review )`.
  - **Ways to redeem:** rendered only when `redeem` is not null.
    - Title "Points for discount".
    - Rate line `sprintf( '%1$s %2$s = %3$s', LauncherCopy::rate( redeem['points_per_unit'] ), SettingsHelper::points_label( 0 ), LauncherCopy::money( 1.0 ) )`.
    - When `worth` is not null, also `sprintf( __( 'You could take %s off today.', 'yayloyalty' ), LauncherCopy::money( worth ) )`.

- [ ] **Step 3: Add the layout stylesheet and enqueue it**

  `assets/storefront/account.css`, scoped to `.yayloyalty-account`, covers layout only: spacing between blocks, a larger balance number, and the two rule columns side by side (CSS grid, two columns) that stack below 600px. No colours or fonts, so the theme owns them.

  In `AccountRewards::register()`, hook `wp_enqueue_scripts` to enqueue it under `ScriptName::ACCOUNT_STYLE` only when `is_wc_endpoint_url( self::ENDPOINT )`, with version `Launcher::asset_version( 'assets/storefront/account.css' )`.

- [ ] **Step 4: Verify the states**

  Open `/my-account/rewards/` as each of these customers:
  1. A customer with a balance and `expires_at` set (set one with the admin's Reset Expiry modal if needed). Expected: the balance matches the launcher badge, the expiry date is in the site's date format, Ways to earn shows the purchase and review rows, and there is no worth line, Ways to redeem or checkout button (redemption is off).
  2. A user who has never earned, with no `customers` row (create a fresh customer, with the signup campaign paused). Expected: balance 0, no expiry line, and no PHP notice in `wp-content/debug.log`.
  3. The first customer with `_yayloyalty_opted_out` user meta set to `1`. Expected: Ways to earn is gone and the card is unchanged.
  4. A 390px-wide viewport. Expected: the columns stack and nothing scrolls sideways.
  5. Temporarily change `RedemptionService::is_available()` to `return true;` and reload, then revert it and don't commit the change. Expected: the worth line, Ways to redeem with "You could take …", and the checkout button all appear.

- [ ] **Step 5: Lint**

  Run: `vendor/bin/phpcs includes/Engine/Storefront/AccountRewards.php includes/Engine/Storefront/templates/account-rewards.php includes/Engine/Storefront/Launcher.php includes/Engine/Register/ScriptName.php`
  Expected: no errors or warnings.

- [ ] **Step 6: Commit**

  Stage: `includes/Engine/Storefront/AccountRewards.php`, `includes/Engine/Storefront/templates/account-rewards.php`, `includes/Engine/Storefront/Launcher.php`, `includes/Engine/Register/ScriptName.php`, `assets/storefront/account.css`
  Message: `feat(storefront): show the points card and rules on the account rewards tab`

### Task 3: Points history table with filters and paging

**Files:**
- Modify: `includes/Engine/Storefront/ShopperActivityRow.php` (shared: `items()` keeps its output for `StorefrontActivityRestController` and `LauncherPreview`)
- Modify: `includes/Engine/Storefront/AccountRewards.php`
- Modify: `includes/Engine/Storefront/templates/account-rewards.php`

**Interfaces:**
- Consumes:
  - `LedgerRepository::paginate_for_user( int $user_id, array $args ): array{items: LedgerEntry[], total: int, page: int, per_page: int}` with args `types`, `page`, `per_page`
  - `LedgerQueryArgs::types_of( string $group ): string[]`
- Produces:
  - `ShopperActivityRow::history_rows( array $entries ): array<int, array{created_at: string, label: string, order: string, points: int, balance: int}>`. Raw values: `created_at` in UTC, `order` the order number or `''`, `balance` from `get_balance_after()`. The caller formats them.
  - `AccountRewards::PER_PAGE = 20`
  - `AccountRewards::FILTERS = [ 'all' => 'All', 'earn' => 'Earned', 'redeem' => 'Spent', 'expire' => 'Expired' ]` (labels translated at render)
  - Query arg `points_type`; page number as the endpoint value (`/my-account/rewards/2/`)

- [ ] **Step 1: Add `ShopperActivityRow::history_rows()`**

  It reuses the private `label()`. Pull the order-number lookup out of `meta_line()` into `private static function order_number( int $order_id ): string`, used by both. `items()` output must not change.

- [ ] **Step 2: Load the page of history in `AccountRewards::render( $value )`**

  - The page is `max( 1, absint( $value ) )`.
  - The filter is `sanitize_key( wp_unslash( $_GET['points_type'] ?? 'all' ) )`, falling back to `all` when it isn't a key of `FILTERS`.
  - Add `view['history']` = `{ rows, total, page, filter }` from `paginate_for_user( $user_id, [ 'types' => LedgerQueryArgs::types_of( $filter ), 'page' => $page, 'per_page' => self::PER_PAGE ] )` passed through `history_rows()`.

- [ ] **Step 3: Write the history section in the template**

  - Heading "Points history".
  - A filter link row of `FILTERS`, each pointing at `AccountRewards::url( 1, $key )`, with the current one marked `aria-current="page"`.
  - A table `woocommerce-orders-table shop_table shop_table_responsive` with columns Date, Description, Points, Balance:
    - Date: `Dates::to_site_tz( created_at, date_format )`.
    - Description: the label, plus "Order #N" on a second line when `order` isn't empty.
    - Points: `LauncherCopy::signed_points()`.
    - Balance: `LauncherCopy::points()`.
    - Give each `<td>` a `data-title` for the theme's responsive table.
  - Below the table, `sprintf( __( '%1$s–%2$s of %3$s', 'yayloyalty' ), … )` and "‹ Previous" / "Next ›" links as `woocommerce-button woocommerce-Button button`, each through `AccountRewards::url( page ± 1, filter )`, shown only when that page exists.
  - When there are no rows, use the launcher's empty copy, `sprintf( __( 'No %s activity yet.', 'yayloyalty' ), SettingsHelper::points_label( 0 ) )`, in place of the table.

- [ ] **Step 4: Verify the table**

  As a customer with more than 20 ledger rows (seed them with the admin's Adjust Points modal), open `/my-account/rewards/`.
  Expected:
  - 20 rows, newest first, and "1–20 of N" where N is the total in the admin customer drawer's ledger tab.
  - Each Balance equals the drawer's balance-after for the same row.
  - Next → `/my-account/rewards/2/`. Earned → only earn and refund-earn rows, and Next keeps `?points_type=earn`.
  - `?points_type=bogus` shows the All list.
  - `/my-account/rewards/999/` shows the empty state.
  - Write one adjustment with an admin note: its row reads "Adjustment by {store name}" and the note text appears nowhere on the page (view source).
  - Open the launcher on a product page: Recent activity rows are unchanged.

- [ ] **Step 5: Lint**

  Run: `vendor/bin/phpcs includes/Engine/Storefront/ShopperActivityRow.php includes/Engine/Storefront/AccountRewards.php includes/Engine/Storefront/templates/account-rewards.php`
  Expected: no errors or warnings.

- [ ] **Step 6: Commit**

  Stage: `includes/Engine/Storefront/ShopperActivityRow.php`, `includes/Engine/Storefront/AccountRewards.php`, `includes/Engine/Storefront/templates/account-rewards.php`
  Message: `feat(storefront): list points history on the account rewards tab`

### Task 4: CSV download of the history

**Files:**
- Create: `includes/Engine/Storefront/AccountHistoryCsv.php`
- Modify: `includes/Engine/Storefront/AccountRewards.php`
- Modify: `includes/Engine/Storefront/templates/account-rewards.php`

**Interfaces:**
- Consumes: `ShopperActivityRow::history_rows()`, `LedgerRepository::paginate_for_user()`, `AccountRewards::ENDPOINT`
- Produces:
  - `final class AccountHistoryCsv` in namespace `YayLoyalty\Engine\Storefront`
  - `public const QUERY_ARG = 'yayloyalty_export'`
  - `public const NONCE_ACTION = 'yayloyalty_export_history'`
  - `public static function url(): string`: the endpoint URL with `QUERY_ARG=csv` and a fresh nonce
  - `public static function maybe_send(): void`, hooked on `template_redirect`

- [ ] **Step 1: Implement `AccountHistoryCsv::maybe_send(): void`**

  - Return unless all of these hold: `is_user_logged_in()`, `is_wc_endpoint_url( AccountRewards::ENDPOINT )`, and `'csv' === ( $_GET[ self::QUERY_ARG ] ?? '' )`.
  - On a failed `wp_verify_nonce( …, self::NONCE_ACTION )`, call `wp_die( esc_html__( 'This download link has expired. Reload the page and try again.', 'yayloyalty' ), '', [ 'response' => 403 ] )`.
  - Otherwise:
    - Call `nocache_headers()` and send `Content-Type: text/csv; charset=utf-8` and `Content-Disposition: attachment; filename="points-history-<Dates::to_site_tz( Dates::now(), 'Y-m-d' )>.csv"`.
    - Write a UTF-8 BOM, then the header `Date, Description, Order, Points, Balance`.
    - Write rows with `fputcsv` to `php://output`, reading the current user's whole history in pages of 500 until `total` is reached:
      - Date: `Dates::to_site_tz( created_at, 'Y-m-d H:i' )`.
      - Points and Balance: raw ints.
    - Then `exit`.
  - Prefix any Description or Order field that starts with `=`, `+`, `-`, `@`, a tab or a CR with `'`, so a spreadsheet won't run it as a formula.

- [ ] **Step 2: Hook it and add the link**

  In `AccountRewards::register()`, call `add_action( 'template_redirect', [ AccountHistoryCsv::class, 'maybe_send' ] )`. In the template, add the link "Download my history (CSV)" to `AccountHistoryCsv::url()` next to the filter row, shown only when the All total is above 0.

- [ ] **Step 3: Verify the download**

  Use the same customer as Task 3, Step 4. Click "Download my history (CSV)".
  Expected:
  - `points-history-2026-…csv` downloads, with a header row plus exactly N rows (N from "of N" under All), newest first, and Excel/Numbers shows the dashes and the "Adjustment by" text correctly.
  - The admin note text isn't in the file (`grep` it).
  - Open the same URL with `_wpnonce=0`: a 403 page.
  - Open it signed out: WooCommerce's sign-in form, and no file.
  - Open it signed in as a second customer with the first customer's link (its nonce is invalid for that user): a 403.

- [ ] **Step 4: Lint**

  Run: `vendor/bin/phpcs includes/Engine/Storefront/AccountHistoryCsv.php includes/Engine/Storefront/AccountRewards.php includes/Engine/Storefront/templates/account-rewards.php`
  Expected: no errors or warnings.

- [ ] **Step 5: Commit**

  Stage: `includes/Engine/Storefront/AccountHistoryCsv.php`, `includes/Engine/Storefront/AccountRewards.php`, `includes/Engine/Storefront/templates/account-rewards.php`
  Message: `feat(storefront): let customers download their points history as CSV`

### Task 5: Point the launcher at the tab, and document it

**Files:**
- Modify: `includes/Engine/Storefront/LauncherPayload.php:74` (`account_url`)
- Modify: `docs/architecture.md:71-74` (the storefront bullet)

**Interfaces:**
- Consumes: `AccountRewards::url()`
- Produces: `LauncherPayload` key `account_url` is now the Rewards tab URL. The launcher template's "View all rewards activity" link and `LauncherPreview` read it unchanged.

- [ ] **Step 1: Change `account_url`**

  In `LauncherPayload::for_balance()`, set `'account_url' => AccountRewards::url()`.

- [ ] **Step 2: Document it**

  In `docs/architecture.md`, under the Storefront bullet, add a sub-bullet for `AccountRewards`. It is the My Account `rewards` endpoint, registered through `woocommerce_get_query_vars` on every request (admin included, so a permalink save keeps it) and flushed once per `REWRITE_VERSION`. It renders theme-native from `LauncherPayload` plus `LedgerRepository::paginate_for_user()`. `AccountHistoryCsv` serves the nonce-checked download on `template_redirect`. Both are read-only and show worth and redeem only while `RedemptionService::is_available()` is true.

- [ ] **Step 3: Verify end to end (acceptance criterion 9)**

  On the Local site, as a customer with at least 21 ledger rows:
  - Open a product page and the launcher, then click "View all rewards activity". Expected: you land on `/my-account/rewards/`.
  - Click Earned, then Next. Expected: `/my-account/rewards/2/?points_type=earn` with earn rows only.
  - Click All, then download the CSV. Expected: data rows equal the "of N" total.
  - In wp-admin → YayLoyalty → Settings → Design, check the preview. Expected: it still renders and its footer link points at the rewards URL.

- [ ] **Step 4: Lint**

  Run: `vendor/bin/phpcs includes/Engine/Storefront/LauncherPayload.php`
  Expected: no errors or warnings.

- [ ] **Step 5: Commit**

  Stage: `includes/Engine/Storefront/LauncherPayload.php`, `docs/architecture.md`
  Message: `feat(storefront): link the launcher to the account rewards tab`
