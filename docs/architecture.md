# Architecture

**Purpose:** how YayLoyalty's parts fit together: boot, the admin-shell sync, the server layers and who writes what, data and upgrades, the Pro/Lite split, how the admin SPA reaches WordPress, release, and tests. Each claim names the file that decides it; read that file for the details.

**Update when:** a change moves a boundary described here: boot order or autoloading, the admin-shell sync, a layer or a writer, an installed table or the upgrade path, the Pro/Lite seam or its checks, how the SPA loads or calls WordPress, the release steps, or the test harness. A feature that stays inside an existing layer needs no update.

Commands and hard rules live in [CLAUDE.md](../CLAUDE.md). Citations give a path and a symbol, not a line number: search for the symbol.

## Boot and autoload

- `yayloyalty.php` is the entry point. Each of its constants is defined only when not already defined, so `wp-config.php` can override any of them. `YAYLOYALTY_IS_DEVELOPMENT` is the one a site sets (see [SPA and WordPress](#spa-and-wordpress)).
- Two autoloaders run. `composer.json` classmaps only the scoped admin-shell in `vendor-prefixed/src/` and `YayLoyaltyPluginAdapter.php`, loaded through `vendor/autoload.php`; `yayloyalty.php` also requires the adapter directly. Everything in the `YayLoyalty\` namespace goes through the loader in `yayloyalty.php`, which maps the namespace onto `includes/` and requires a file only if it exists.
- That `file_exists` check lets one tree run with parts missing. Lite has no `includes/Pro/`, and release zips have no `RegisterDev.php`, so code probes those classes with `class_exists` instead of requiring them.
- Boot runs on `plugins_loaded`:
  - priority 5: admin-shell boots and registers the adapter (see [the next section](#admin-shell-sync-and-scoping));
  - priority 10: `plugin_init()` shows a notice and stops if WooCommerce is missing. Otherwise it declares HPOS compatibility (`includes/Engine/ActDeact.php`), builds the `YayLoyalty` singleton and loads translations;
  - priority 20: `Installer::maybe_upgrade` and `Capabilities::migrate`, which run even without WooCommerce.
- `includes/YayLoyalty.php` wires the WooCommerce side by hand in its constructor, with no container. The order is: REST routes, the script registrar, the admin script enqueue (`Admin\Settings`), emails, the YayMail integration (`Integrations\YayMail\YayMailIntegration`), the Pro seam, the earning listeners, then on every request the storefront surfaces (`AccountRewards`, `Placements`, `ProductEarnMessage`, `ThankYouPoints`), and last, on front-end requests only, the storefront launcher (`Engine\Storefront\Launcher::register()`). Pro boots before them so its filters are attached before any campaign read. `EarningService::build()` applies `yayloyalty_earning_gates` once, at boot, so a gate added after it is never seen by `award()`; `EarningService::gate_reason()` re-applies it per call, which is how the storefront asks the same gates. Pro adds none today. `EarningService::award()` applies `yayloyalty_award_points` on every award, inside `LedgerService::earn()` under the customer's row lock and after the duplicate check, and a subscriber may only lower the points; a cut award fires `yayloyalty_award_limited` after commit. Pro's `DailyEarningLimit` uses the filter for the per-customer daily cap, and also answers `yayloyalty_points_left_today` (`EarningService::points_left_today()`), which the storefront reads so it never advertises past the cap.
- `yayloyalty.php` attaches the rest itself: the installer and capability upgrades, and the `CustomerActivityListener` hooks.
- Activation (`ActDeact::activate`) does nothing while WooCommerce is inactive; `Installer::maybe_upgrade` installs on a later request instead. Otherwise it runs `Installer::install()` on the site, or on every site when network-activated. `wp_initialize_site` covers sites added later. Deactivation only deletes the Rewards tab's rewrite stamp, so the next activation flushes rewrite rules. There is no uninstall routine, so tables, options and capabilities stay behind.

## Admin-shell sync and scoping

- Admin-shell is a private Composer package (`composer.json`) shared by the YayCommerce plugins. It draws the shared YayCommerce admin menu and its common pages (help, licences, recommended plugins). It also adds this plugin's submenu entry and plugin-row links.
- Every plugin ships its own copy. To keep the copies from colliding, php-scoper rewrites the package into `vendor-prefixed/` under the `YayLoyaltyScoped` namespace (`scoper.inc.php`). [The decision record](decisions/2026-10-01-admin-shell-scoped-with-php-scoper.md) says why.
  - The plugin uses only the scoped copy, which loads through the Composer classmap. The unscoped package is in Composer's autoload map too, so a stray `YayCommerce\AdminShell\` reference works in development and fails in a release zip, which doesn't ship `vendor/yaycommerce/`.
  - With several YayCommerce plugins active, each copy registers its own plugin, and the newest version draws the shared menu.
- `YayLoyaltyPluginAdapter.php` joins the two:
  - It is a global class that implements the scoped menu-adapter interface. It stays out of scoping, so it sees the real WordPress classes.
  - It names the page slug `yayloyalty` and the capability `Capabilities::VIEW`.
  - Its page callback prints the SPA's mount point.
- The sync lives in the package's own scripts. `./scripts/run.sh dev` runs a full `composer update`, then `vendor/bin/yaycommerce-update`, which:
  1. deletes `vendor-prefixed/` and updates admin-shell;
  2. requires php-scoper and thecodingmachine/safe at their latest versions as dev dependencies, which rewrites `composer.json`;
  3. regenerates `scoper.inc.php` from the package's template, keeping only the prefix and the adapter's name;
  4. scopes the package, copies its assets and dumps the classmap.
- Release runs a full `composer update`, then `vendor/bin/yaycommerce-prerelease`, once per zip. It repeats those steps and ends with `composer install --no-dev`. A plain `composer install` never builds `vendor-prefixed/`.

## Server layers and write ownership

- REST: `includes/Engine/RestAPI.php` is the one place controllers are registered. That happens on `rest_api_init`, under `BaseRestController::REST_NAMESPACE` (yayloyalty/v1). It builds the Lite controllers; Pro builds its own and adds them through the `yayloyalty_rest_controllers` filter on that list.
  - The redemptions route (`RedemptionsRestController`) is live in Lite. It always returns an empty page, because its table isn't built yet.
  - Its constructor also subscribes `DashboardCache::flush` to `yayloyalty_ledger_recorded` and `yayloyalty_campaigns_changed`. Above a member-count threshold, `includes/Services/Dashboard/DashboardCache.php` serves the dashboard payload from transients.
- Controllers extend `includes/Controllers/AbstractRestController.php` (schemas, args, permissions, the pagination envelope). That class extends `includes/Controllers/BaseRestController.php` (execution and errors).
  - Every route's permission callback is `requires()` with one capability. Access is checked there and nowhere deeper: services and the engine take an actor id instead (`includes/Access/Capabilities.php`).
  - The exception is shopper routes under `/me/` (`StorefrontActivityRestController`, `GET /me/activity`). They check only that the visitor is signed in, through cookie auth with the `wp_rest` nonce. They take no user id, so there is nothing to enumerate, and return a shopper-safe projection (`ShopperActivityRow`) with no admin note, actor id or campaign id. An adjustment's `customer_note`, unlike its admin `note`, is shopper-facing: the projection, the Rewards tab and the points adjusted email show it.
  - Writes go through `exec_write()`, which runs the callback inside `Transaction::run`. A returned `WP_Error` rolls back like an exception. Any other exception becomes a 500 that carries only a correlation id; the details go to the log.
- `includes/Database/Transaction.php` is the only code that issues transaction SQL.
  - Nested calls become savepoints.
  - If a caller, such as WooCommerce, already opened a transaction, it joins it with a savepoint rather than committing it.
  - `after_commit()` callbacks run after the outermost commit, or at once when no transaction is open. Inside a transaction another caller opened, they run when the plugin's part is released, and that caller can still roll back.
- The layers run controller → service → repository → `$wpdb`:
  - A repository holds all the SQL for one table and never opens a transaction (`includes/Repositories/AbstractRepository.php`). Every failed write throws, which is what makes `Transaction::run` roll back. A few read-side queries, such as the dashboard and the customer list, join other tables.
  - Models (`includes/Models/`) are typed rows that never touch the database. Enums (`includes/Enums/`) are classes of string constants, because the product runs on PHP 7.4; they share `is_valid()` and `assert()` through `StringEnumMethods`.
  - Campaigns have no service, because their invariants are validation. Their controller calls the repository directly. `CampaignRepository` keeps the campaign list in the object cache, and a write clears it and fires `yayloyalty_campaigns_changed`. The setup wizard's `SetupService` (`POST /setup`, `SetupRestController`) is the other campaign writer: it updates the built-in campaigns and the cart-discount reward in place through the same repositories, and also writes settings, design and `yayloyalty_setup_completed`. `POST /setup/dismiss` writes only that option.
- `includes/Services/LedgerService.php` is the only writer of ledger rows and of the customer's cached totals (balance, lifetime earned and redeemed):
  - In one transaction, it locks the customer row (`SELECT … FOR UPDATE`), appends the ledger row with its running `balance_after`, and updates the totals.
  - An earn row that names a campaign also takes that campaign row's shared lock (`LOCK IN SHARE MODE`), after the customer row. If the campaign is gone, the earn is skipped (`REASON_CAMPAIGN_DELETED`). The campaign delete route locks the row exclusively, then re-counts its ledger rows with a locking read, so a racing award either lands first and the delete answers 409, or finds the campaign gone. `ledger.campaign_id` has no foreign key, so nothing else stops an orphan.
  - It fires `yayloyalty_ledger_recorded` after commit.
  - System rows carry an idempotency key that a unique index backs, so a replayed event can't award twice.
  - Its docblock lists where it may be constructed.
  - A customer row is created at zero by `CustomerRepository::ensure_row()`, which `LedgerService`, `PendingPoints` and `CustomerActivityListener` call. Creating the row is not a ledger write.
  - [The decision record](decisions/2026-10-01-ledger-service-sole-writer.md) says why there is one writer and a cached total.
  - Points pending on an open order sit in order meta, and their sum is a separate cached column on the customer. `includes/Engine/Earning/PendingPoints.php` writes both.
- The domain lives in `includes/Engine/`:
  - Earning listeners (`includes/Engine/Earning/Listeners/`) hook WooCommerce order events and WordPress comment and registration events. They stage pending points through `EarningService::preview()` and award through `EarningService::award()` (`includes/Engine/Earning/EarningService.php`).
  - `award()` runs the gates and asks the Points engine. Then, in one transaction, it calls `LedgerService::earn()`, releases the order's pending points and records the award on the order.
  - Refunds, cancellations, trashed orders and reviews that lose approval go through `includes/Engine/Earning/Reversal.php`. It claws points back through `LedgerService`, or restores them when a refund is deleted.
  - Each listener runs inside `ListenerGuard`, which logs failures and never rethrows, so loyalty can't break an order save.
  - Emails (`includes/Engine/Emails/`) are `WC_Email` subclasses. Lite registers its emails in `Emails::add_classes()`, and Pro adds its own in `boot()`. Only the points earned and points adjusted emails send; both extend `LedgerEntryEmail`, whose `trigger()` reloads the ledger row from the id its email action passes. `EarningService::award()` fires `yayloyalty_points_awarded` after commit, once per awarded row. `Emails::relay_award()` turns it into `yayloyalty_points_earned_email` with only the ledger row id. That second action is the one `Emails::add_email_actions()` adds to `woocommerce_email_actions`, so WooCommerce builds its mailer and relays `yayloyalty_points_earned_email_notification`, which `PointsEarnedEmail::trigger()` listens to and reloads the row from. The id keeps WooCommerce's deferred queue safe: before 10.8 it serializes the arguments, and the award's `Context` can hold a `Closure`. `PointsEarnedEmail::accepts()` skips an award that was clawed back before a deferred send ran. The adjust endpoint calls `Emails::relay_adjustment()` only when its request sets `notify`, which fires `yayloyalty_points_adjusted_email` the same way. `EmailThrottle` enforces the merchant's weekly per-customer cap (`emails.throttle_per_week`, null = no cap) and counts only accepted sends.
  - YayMail (`includes/Integrations/YayMail/`) lets merchants design the points earned email in YayMail. It boots only when YayMail 4.4.7 or later is active and licensed (`YayMailIntegration::is_yaymail_active()`); older versions don't pass the email to the subject's shortcodes. It registers the email and its shortcodes on YayMail's `yaymail_register_emails` and `yaymail_register_shortcodes`, and `PointsEarnedYayMailEmail` swaps the template through `wc_get_template` when the merchant enables YayMail's version.
  - Storefront (`includes/Engine/Storefront/`) draws the read-only launcher on the shop: a floating button and a panel with the shopper's balance, ways to earn and recent activity, or the programme's pitch for a guest. `Launcher::register()` hooks three actions:
    - on `wp`, after the main query, `prepare()` decides once: `LauncherVisibility::is_visible()` (the Design tab's scope, chosen pages and hidden roles), then `Launcher::viewer_can_earn()`, which asks the filtered gate chain through `EarningService::gate_reason()`: a guest passes only on `ProgramGate::REASON_NO_CUSTOMER`, a member only with no reason, so a shopper who can't earn sees no panel about earning. It then builds the view through `LauncherPayload`, which also caps what it advertises at `EarningService::points_left_today()`. An exception hides the launcher and is logged; it never breaks the page;
    - on `wp_enqueue_scripts`, `enqueue()` loads the built `assets/dist/storefront/launcher.css` and the deferred plain script `assets/storefront/launcher.js`;
    - on `wp_footer`, `render()` prints `Launcher::markup()` from `templates/`. The panel is rendered on the server; no data goes out as JSON. `hide_on_mobile` is a CSS media query, so a page cached for one device still behaves on another.
    - On the first open the script fetches `GET /me/activity` and fills the activity rows; a failed load retries on the next open.
    - The balance is printed into the page for a signed-in shopper, so a page cache that serves pages to signed-in visitors can show one shopper's balance to another. Common page caches skip signed-in visitors by default.
  - My Account → Rewards (`AccountRewards`, also in `includes/Engine/Storefront/`) is the shopper's full page: balance, expiry, ways to earn and redeem, and the points history, 20 rows a page, filtered by the `LedgerQueryArgs` groups. The launcher's "View all rewards activity" link leads there.
    - The `rewards` endpoint is added through `woocommerce_get_query_vars` on every request, admin included, so a permalink save in wp-admin keeps it. The rewrite rules are flushed once per `AccountRewards::REWRITE_VERSION`, and deactivation clears the stamp.
    - It renders on the server from `includes/Engine/Storefront/templates/account-rewards.php`, in WooCommerce's account markup so the theme styles it. `assets/storefront/account.css` is plain layout CSS with no build step. Its data comes from `LauncherPayload` and `LedgerRepository::paginate_for_user()`, through `ShopperActivityRow::history_rows()`.
    - Like the launcher, it shows what points are worth, the redeem rule and the checkout link only while `RedemptionService::is_available()` is true.
    - `AccountHistoryCsv` streams the shopper's whole history as CSV on `template_redirect`, behind a nonce, for the signed-in user only.
  - Merchant placements (`Placements`) are the `[yayloyalty_balance]` and `[yayloyalty_ways_to_earn]` shortcodes and the matching dynamic "Points balance" and "Ways to earn" blocks, in a "YayLoyalty" inserter category. They print the Rewards tab's own partials, `includes/Engine/Storefront/templates/points-card.php` and `ways-to-earn.php`, with `account.css`, and print nothing while the program is off. A guest gets a sign-in link in place of the balance, and the rates with a signup row (`LauncherEarnRules::for_viewer()`). They are registered on every request because the editor previews them through the REST block renderer. The blocks' metadata is in `includes/Engine/Storefront/blocks/*/block.json`, and their editor script is built from `apps/admin/src/blocks/` by `vite.blocks.config.ts` into `assets/dist/blocks/`. The "Points redemption" and "Rewards catalog" blocks from the design aren't built yet. Like the launcher, the card prints the balance into the page.
  - The product-page earning line (`ProductEarnMessage`) says under the price what buying one of the product earns. Classic themes get it on `woocommerce_single_product_summary`, block themes on the product price block's `render_block_` filter for the main product only. A member's figure comes from `EarningService::preview()`, capped at `EarningService::points_left_today()`, so the earning gates and Pro's daily cap apply; a guest's is the base rate from `PointsEngine::evaluate()`, so cached pages hold nothing personal. Variable products carry each variation's sentence in WooCommerce's variation data as `yayloyalty_earn`, and `assets/storefront/product-earn.js` swaps it in. Design → Visibility `product_message` turns it off; `storefront_text.product_earn_line` is its wording.
  - The thank-you points block (`ThankYouPoints`) prints on `woocommerce_thankyou`, which WooCommerce fires in the classic order-received template and in the Order Confirmation block. It reads only what `PendingPoints` wrote on the order and the customer row: "You'll earn N" while the order's points are staged, "You earned N" once `_yayloyalty_points_earned` is set, then the balance and the customer's pending sum. The order-received URL opens for anyone with the order key, so it shows only to the signed-in customer who owns the order. Design → Visibility `thankyou_message` turns it off; `storefront_text.thankyou_pending_line` and `thankyou_earned_line` are its wording.
  - The Points engine (`includes/Engine/Points/`) scores events and writes nothing. It reads live campaigns and a `Context` that `ContextFactory` builds from WooCommerce data.
  - Redemption (`includes/Engine/Redemption/`, `includes/Engine/Rewards/`) is scaffolding, and nothing hooks it. `Quoter` and `RewardEngine` pass calls along, and `Rewards\Clamp` holds the clamp arithmetic. The redemption gates, the flexible-discount handler, `Rewards\ContextFactory`, `RedemptionService` apart from `is_available()`, `CouponProvider`, both checkout adapters, `SessionIntent` and the write side (`CommitWriter`, `LedgerService::redeem()`, `RedemptionRepository::find_by_order()`, `RedemptionsTable`) are stubs marked `TODO(redemption)` that throw. `RedemptionRepository::summary_by_rewards()` is live: the rewards routes read it, and it returns nothing until the table exists. `RedemptionService::is_available()` is false until redemption ships, and `LauncherPayload` (the launcher, the Rewards tab and the placements) and both ledger emails (`LoyaltyEmail::balance_value()`) show what points are worth only when it is true.
  - `includes/Engine/CustomerActivityListener.php` (hooked in `yayloyalty.php`) records account and points activity in `customer_meta`, through `includes/Services/CustomerActivityService.php`. On `user_register` it also creates the new member's customer row, skipping staff. On `yayloyalty_award_limited` it records an `earning_limited` entry with the evaluated, awarded and held-back points.

## Data and upgrades

- `Installer::schemas()` (`includes/Database/Installer.php`) is the list of installed tables, after the `yayloyalty_table_schemas` filter, which nothing hooks today. Each entry is a class in `includes/Database/Schema/` that returns its table's `dbDelta` DDL.
  - A schema class that isn't on the list is only a design.
  - `includes/Database/Schema/RedemptionsTable.php` throws instead of returning DDL.
- `Table::name()` (`includes/Utils/Table.php`) reads `$wpdb->prefix` on every call and never caches it, because multisite switches blogs. Integration tests rely on the same behaviour to move every plugin table to a scratch prefix.
- `Installer::install()` is safe to repeat:
  - It runs `dbDelta` for every schema, then seeds the built-ins.
  - It grants capabilities and runs migrations, then `verify()` logs any missing column or index, because `dbDelta` skips some failures silently.
  - It writes `yayloyalty_db_version` last, so a crash mid-install retries.
- `yayloyalty_db_version` holds the plugin version, not a schema version. `Installer::maybe_upgrade` re-runs the whole `install()` whenever it differs from `YAYLOYALTY_VERSION`, behind an `add_option` lock (`yayloyalty_upgrading`). A schema change therefore reaches existing sites only through a version bump.
- Capabilities have their own version. `Capabilities::install()` first applies the role changes of each version since the stored one (`upgrade_roles()`), then grants the defaults to administrator and shop_manager, then stores `Capabilities::VERSION` in `yayloyalty_caps_version`. `migrate()` re-runs it when the constant is higher than the stored value. `install()` also calls it, so every version bump gives those roles back any default a merchant took away.
- Settings (`yayloyalty_settings`) and design (`yayloyalty_design`) are options. Each belongs to one helper in `includes/Helpers/`. That helper merges code defaults on read, and whitelists and sanitises on both read and write. Settings keep their fields at the root; only the emails' fields sit in a group, `emails`. The emails' on/off switches live in WooCommerce's own email options.
- Other stored state: `yayloyalty_reviewed` (`SettingsHelper::OPTION_REVIEWED`, set by `POST /settings/mark-reviewed`), `yayloyalty_setup_completed` (`SettingsHelper::OPTION_SETUP_COMPLETED`, set by `SetupService` on finishing or exiting the wizard), `yayloyalty_rewrite_version` (`AccountRewards`' rewrite-flush stamp), Pro's `yayloyalty_customer_segments` (`includes/Pro/Helpers/CustomerSegmentsHelper.php`), and per-user dashboard notice dismissals in user meta (`includes/Services/Dashboard/NoticeDismissals.php`). User meta also holds each customer's recent loyalty email sends (`EmailThrottle::META_SENDS`) and an earning opt-out flag (`ProgramGate::META_OPTED_OUT`), which `ProgramGate` reads and nothing writes yet.
- Orders are read and written only through WooCommerce's CRUD API, never with SQL on order tables, which keeps the plugin HPOS-compatible. Pending and awarded points sit in order meta. `PendingPoints` saves only the meta, so no full order save runs inside an award.
- There are no cron or Action Scheduler jobs and no custom post types.

## Pro and Lite

One tree builds both editions. Lite is Pro without `includes/Pro/` and without the SPA's Pro code. The rules that keep the split safe are in [CLAUDE.md](../CLAUDE.md), and the reasons for it in [the decision record](decisions/2026-10-01-build-time-pro-lite-split.md); this section is how it works.

- PHP seam: the constructor of `includes/YayLoyalty.php` calls `YayLoyaltyPro::boot()` when `is_pro()` finds the class. This checks that the Pro code is present; it is not a licence check. In Lite it is false, because the autoloader skips the missing file.
- `boot()` in `includes/Pro/YayLoyaltyPro.php` is the full list of what Pro changes. Each hook it adds is a filter: either on WooCommerce's email list, or on an extension point that Lite applies itself with a working default. For example, with no conditions evaluator attached, campaign conditions are not applied. Pro adds no capabilities. `boot()` then fires `yayloyalty_pro_booted`, which nothing uses yet.
- Stored Pro data survives a move to Lite. `CampaignTrigger` and `RewardType` (`includes/Enums/`) separate `all()`, what this build offers, from `all_known()`, every value that may be stored, so Pro rows still validate.
- SPA flag: `apps/admin/vite.config.ts` turns `BUILD_TYPE` into `VITE_IS_PRO`. `lite` builds Lite; anything else, including unset, builds Pro. `apps/admin/src/lib/is-pro-build.ts` exposes the result as `IS_PRO_BUILD`. Only `scripts/run.sh` sets `BUILD_TYPE`.
- Lite drops Pro code only through constant folding and tree-shaking. There is no plug-in registry, alias swap or lint rule.
  - One route table (`apps/admin/src/router.tsx`) and the same pages serve both builds.
  - Shared code imports Pro components from `apps/admin/src/pro/index.ts` and renders them inside an `IS_PRO_BUILD` branch. In Lite, that branch shows a Lite version, an upsell or a not-found page instead.
  - `<ProGate>` (`apps/admin/src/components/pro/ProGate.tsx`) ships in both builds and swaps in the upsell at runtime. Its check runs inside its own module, so it can't drop its children from the bundle; only the branch at the call site does that.
- `scripts/validate-build.php` runs these Pro/Lite checks, among [others](#build-and-release). It fails a zip when:
  - Lite still has `includes/Pro/`, or Pro lacks `includes/Pro/YayLoyaltyPro.php`;
  - Lite PHP outside the vendored code names `Pro` anywhere but `includes/YayLoyalty.php`, or more than twice there, once comments are stripped;
  - the bundle sentinel from `apps/admin/src/pro/index.ts` is present in Lite or missing from Pro;
  - Lite lacks the upsell text;
  - the bundle has more than one JS chunk;
  - any packaged PHP file has an array key `isPro`, `isPremium`, `is_pro` or `isProBuild`;
  - the Pro-only design field `custom_pages` appears in `DesignVisibilitySection.tsx` without `IS_PRO_BUILD` in the same file. This one reads `apps/admin/src`, not the zip.
- Today the sentinel is reachable only through `registerPro()`, which `apps/admin/src/main.tsx` calls inside the build-flag branch. The sentinel check therefore proves only that the Lite flag reached Vite and folded that branch away. It doesn't prove every Pro module was dropped. For example, a Lite build with `<ProGate><DesignTypographySection /></ProGate>` and no `IS_PRO_BUILD` branch ships that section and still passes.

## SPA and WordPress

- The page: admin-shell adds the YayLoyalty submenu from the adapter, and the adapter's callback prints `<div id="yayloyalty">` for `apps/admin/src/main.tsx` to mount into.
  - `includes/Engine/Admin/Settings.php` enqueues the app only on that page's hook suffix, `yaycommerce_page_yayloyalty`.
  - It adds the `yay-ui` body class on every admin screen. That class scopes the SPA's Tailwind utilities (`apps/admin/src/main.css`).
- Loading: `includes/Engine/Register/RegisterFacade.php` chooses a registrar by `YAYLOYALTY_IS_DEVELOPMENT`, which defaults to `true`:
  - `true`: `RegisterDev` loads `/main.tsx` from the Vite dev server on port 3030, plus React's refresh preamble. Release zips have no `RegisterDev.php`, so a site running one must define the constant `false`, or the admin page stays blank;
  - anything else: `RegisterProd` loads the fixed file `assets/dist/admin/js/main.js`. There is no manifest.
  - The facade registers the built `assets/dist/admin/style.css` in both modes.
  - Either way the app is one ES module (the facade adds `type="module"`) that depends on WordPress's React and `@wordpress/*` scripts. The production build maps those imports to WordPress globals; the dev server maps only `@wordpress/hooks` and `@wordpress/i18n` and serves its own React (`apps/admin/vite.config.ts`).
- Data hand-off: `includes/Helpers/LocalizeHelper.php` builds two globals:
  - `yayLoyaltyAdmin` carries settings, design and the current user's capabilities, filtered by `yayloyalty_localize_admin_data`;
  - `yayLoyaltyMeta` carries the REST root, namespace and `wp_rest` nonce, plus site, plugin and WooCommerce data, filtered by `yayloyalty_localize_meta`.
  - Their TypeScript shapes are in `apps/admin/src/types/localize.type.ts`.
- REST calls go through one ky client (`apps/admin/src/lib/api/api.ts`). It prefixes the REST root, sends the nonce as `X-WP-Nonce`, and falls back to `?rest_route=` on plain permalinks.
  - Each resource family has a client module and TanStack Query options: in `apps/admin/src/lib/api/` and `apps/admin/src/lib/queries/` for Lite, and beside the feature in `apps/admin/src/pro/` for Pro.
  - Its zod schemas in `apps/admin/src/lib/schema/` mirror the PHP response shapes. A schema file's opening comment usually names the PHP code it mirrors, as [the convention](conventions.md#typescript-copies-of-php-name-their-owner) asks.
  - Settings and design start from the localized data instead of a first fetch.
- `yayLoyaltyMeta.loyaltyMeta.setupCompleted` decides whether `apps/admin/src/AppLayout.tsx` opens the setup wizard (`apps/admin/src/components/setup-wizard/`), for users with `MANAGE_SETTINGS` only. Finishing posts `/setup` and exiting posts `/setup/dismiss`; either way it never opens again.
- The Design tab's launcher preview is the server's own markup, not a React copy. `POST /design/preview` (`DesignRestController`) returns `LauncherPreview::documents()`: the unsaved design drawn for a sample member, or for a guest when `viewer` is `guest`, at the store's real rates, closed and open. `LauncherPreviewFrame.tsx` shows each document in an `<iframe sandbox="allow-same-origin">`, so it runs no script; the admin reaches in only to mark the text a Storefront text field edits (`data-yl-text`). `DESIGN_PREVIEW_FIELDS` (`apps/admin/src/lib/api/design.api.ts`) names the design fields that redraw it, and mirrors what `LauncherPayload::for_balance()` reads.
- Capabilities: `apps/admin/src/lib/access.tsx` mirrors the PHP capability names, and routes, menus and controls hide or disable by them. This is presentation only; the REST permission callbacks are what enforce access.
- Routing is hash-based (`createHashRouter` in `apps/admin/src/router.tsx`). The WordPress URL stays `admin.php?page=yayloyalty`, and the app's route follows the `#`.
- Translations:
  - PHP and JS share the `yayloyalty` text domain.
  - JS calls `@wordpress/i18n`, which the facade's `pre_load_script_translations` filter feeds from the plugin's MO file.
  - `makepot` skips `apps/`, so JS strings reach the POT only from the built bundle. Terser keeps the translation calls and `translators:` comments for it.

## Build and release

- `./scripts/run.sh build` type-checks, then runs `vite build` into `assets/dist/admin/` (`apps/admin/package.json`). `BUILD_TYPE` sets the edition.
- The same `build` script then runs `build:storefront`, which builds `apps/admin/src/storefront.css` through `apps/admin/vite.storefront.config.ts` into `assets/dist/storefront/launcher.css`, one file for both editions.
  - It is the admin kit's tokens and Tailwind utilities with the `yl:` prefix, scoped to `.yayloyalty-launcher` and without Tailwind's preflight, so the shop theme and the launcher leave each other alone.
  - Tailwind finds classes only by scanning `includes/Engine/Storefront/` and `assets/storefront/` (`@source`), so a class there is written out in full, never assembled at runtime. `UiClasses` copies the admin kit's button and skeleton class lists for that reason.
  - The Vite dev server doesn't serve it. `./scripts/run.sh dev` builds it and the blocks script once, and `pnpm dev:storefront` in `apps/admin` rebuilds it on change.
- It ends with `build:blocks`, which builds the blocks' editor script from `apps/admin/src/blocks/` through `apps/admin/vite.blocks.config.ts` into `assets/dist/blocks/editor.js`, one file for both editions, with React and `@wordpress/*` as WordPress globals.
- `./scripts/run.sh release` first stamps a new version, when one is given or entered, into the plugin header, `YAYLOYALTY_VERSION` and the `readme.txt` stable tag; `yayloyalty.php` is the version source. Then `build_package` in `scripts/run.sh`:
  1. runs the prerelease sync, which leaves only `--no-dev` Composer packages, and dumps the production classmap;
  2. builds the SPA;
  3. stages `release/yayloyalty/` from an explicit include list: the root plugin files, `includes/`, `assets/`, `languages/`, `vendor-prefixed/` and Composer's autoloader only;
  4. Lite only: deletes `includes/Pro/`;
  5. both editions: delete `RegisterDev.php` and dev artifacts;
  6. writes `release-manifest.json`, with a SHA-256 for each file, and zips;
  7. runs `scripts/validate-build.php` and renames a failing zip to `.invalid`.
- Both editions unpack to the same `yayloyalty/` folder, so Pro installs over Lite in place.
- Pro runs first because that run stamps the version, which Lite's `--no-prompt` run then reads back, so both zips carry the same version. The two runs share `assets/dist/` and the staging folder, so they must run one after the other.
- Besides the Pro/Lite checks above, the validator checks:
  - the manifest: build type, hashes, no unlisted files, and a version that matches the header and the constant;
  - `php -l` on every PHP file;
  - that no dev artifacts remain;
  - a headless WP-CLI boot of the zip, when `YAYLOYALTY_WP_CLI` is set. The boot also checks `is_pro()` against the edition and fails if either localized payload has a key with the whole word `pro` or `premium`. Otherwise it reports SKIP, or fails under `--require-boot`, which `run.sh` doesn't pass.
- `readme.txt` is the WordPress.org readme and carries the release notes.

## Tests

What follows is the harness the tests run in. When a test is warranted at all is in [CLAUDE.md § Tests](../CLAUDE.md#tests).

- **Unit** (`phpunit.xml.dist`, `tests/unit/`): plain PHP with no WordPress.
  - `tests/bootstrap.php` maps the namespace onto `includes/` and shims the WordPress functions the tested code reaches.
  - There is no `$wpdb`, so anything that queries belongs in integration.
  - `tests/unit/EngineTestCase.php` builds campaigns and contexts in memory.
- **Integration** (`phpunit-integration.xml.dist`, `tests/integration/`): `tests/integration/bootstrap.php` boots the WordPress site the plugin is installed in and needs the plugin active.
  - It sets `$wpdb->prefix` to `ylit_` and creates the plugin's tables there, empty. Other plugins' tables, WooCommerce's included, get empty copies under the same prefix. It drops all of them before the run and again at the end.
  - WordPress core tables, such as users, posts and options, stay real.
  - `tests/integration/IntegrationTestCase.php` truncates tables between tests rather than rolling back, because the code under test opens its own transactions. Core tables are real, so a test cleans up the users, posts and orders it creates. Route tests call `send()` after `act_as_admin()`.
  - `tests/integration/WooCommerceOrdersFixture.php` makes real orders, products, reviews and customers for tests that drive the listeners through WooCommerce's hooks. It short-circuits mail and deletes what it made in `cleanup_orders()`, which `tearDown()` calls.
- **Vitest** (`apps/admin/vitest.config.ts`) runs `apps/admin/tests/**/*.test.ts` in Node, with the `@` alias.
  - It doesn't load `vite.config.ts`, so `IS_PRO_BUILD` is false unless the shell exports `VITE_IS_PRO=true`.
  - Tests sit outside `tsconfig.json`, so `tsc` doesn't check them.
- **Browser** (`apps/e2e/`, the `@yayloyalty/e2e` package): Playwright drives the Pro build in wp-env's tests site on :8889. Why this shape is in [the decision record](decisions/2026-10-12-browser-tests-with-playwright-on-wp-env.md).
  - `apps/e2e/.wp-env.json` pins WordPress and WooCommerce and mounts this repo as the plugin, with `YAYLOYALTY_IS_DEVELOPMENT` false, so the site loads `assets/dist/`.
  - `apps/e2e/bin/setup.sh` seeds the store: WooCommerce pages and options, Check payments, held reviews, the setup wizard's campaigns, two products, an excluded role and an application password in `apps/e2e/.auth/`.
  - Specs set up and assert through REST (`apps/e2e/support/api.ts`), and act through the UI. Each test makes its own customer; specs run one at a time because they share settings and campaigns.
