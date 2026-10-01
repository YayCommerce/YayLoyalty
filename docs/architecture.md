# Architecture

**Purpose:** how YayLoyalty's parts fit together: boot, the admin-shell sync, the server layers and who writes what, data and upgrades, the Pro/Lite split, how the admin SPA reaches WordPress, release, and tests. Each claim names the file that decides it; read that file for the details.

**Update when:** a change moves a boundary described here: boot order or autoloading, the admin-shell sync, a layer or a writer, an installed table or the upgrade path, the Pro/Lite seam or its checks, how the SPA loads or calls WordPress, the release steps, or the test harness. A feature that stays inside an existing layer needs no update.

Commands and hard rules live in [CLAUDE.md](../CLAUDE.md). Citations give a path and a symbol, not a line number: search for the symbol.

## Boot and autoload

- `yayloyalty.php` is the entry point. Each of its constants is defined only when not already defined, so `wp-config.php` can override any of them. `YAYLOYALTY_IS_DEVELOPMENT` is the one a site sets (see [SPA and WordPress](#spa-and-wordpress)).
- Two autoloaders run. `composer.json` classmaps only the scoped admin-shell in `vendor-prefixed/` and `YayLoyaltyPluginAdapter.php`, loaded through `vendor/autoload.php`. Everything in the `YayLoyalty\` namespace goes through the loader in `yayloyalty.php`, which maps the namespace onto `includes/` and requires a file only if it exists.
- That `file_exists` check lets one tree run with parts missing. Lite has no `includes/Pro/`, and release zips have no `RegisterDev.php`, so code probes those classes with `class_exists` instead of requiring them.
- Boot runs on `plugins_loaded`:
  - priority 5: admin-shell boots and registers the adapter (see [the next section](#admin-shell-sync-and-scoping));
  - priority 10: `plugin_init()` shows a notice and stops if WooCommerce is missing. Otherwise it declares HPOS compatibility (`includes/Engine/ActDeact.php`), builds the `YayLoyalty` singleton and loads translations;
  - priority 20: `Installer::maybe_upgrade` and `Capabilities::migrate`, which run even without WooCommerce.
- `includes/YayLoyalty.php` wires the plugin by hand in its constructor, with no container. The order is: REST routes, the script registrar, the admin page, emails, the Pro seam, then the earning listeners. Pro boots before the listeners because `EarningService::build()` reads its filters once, at boot.
- Activation (`ActDeact::activate`) runs `Installer::install()` on the site, or on every site when network-activated. `wp_initialize_site` covers sites added later. Deactivation does nothing and there is no uninstall routine, so tables, options and capabilities stay behind.

## Admin-shell sync and scoping

- Admin-shell is a private Composer package (`composer.json`) shared by the YayCommerce plugins. It draws the shared YayCommerce admin menu and its common pages (help, licences, recommended plugins). It also adds this plugin's submenu entry and plugin-row links.
- Every plugin ships its own copy. To keep the copies from colliding, php-scoper rewrites the package into `vendor-prefixed/` under the `YayLoyaltyScoped` namespace (`scoper.inc.php`).
  - The plugin uses only the scoped copy, and that copy loads only through the Composer classmap.
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
- Release runs `vendor/bin/yaycommerce-prerelease`. It repeats those steps and ends with `composer install --no-dev`. A plain `composer install` never builds `vendor-prefixed/`.

## Server layers and write ownership

- REST: `includes/Engine/RestAPI.php` is the one place controllers are built and registered. That happens on `rest_api_init`, under `BaseRestController::REST_NAMESPACE` (yayloyalty/v1). Pro adds its controllers through the `yayloyalty_rest_controllers` filter on that list.
- Controllers extend `includes/Controllers/AbstractRestController.php` (schemas, args, permissions, the pagination envelope). That class extends `includes/Controllers/BaseRestController.php` (execution and errors).
  - Every route's permission callback is `requires()` with one capability. Capabilities are checked there and nowhere deeper: services and the engine take an actor id instead (`includes/Access/Capabilities.php`).
  - Writes go through `exec_write()`, which runs the callback inside `Transaction::run`. A returned `WP_Error` rolls back like an exception. Any other exception becomes a 500 that carries only a correlation id; the details go to the log.
- `includes/Database/Transaction.php` is the only code that issues transaction SQL.
  - Nested calls become savepoints.
  - If WooCommerce already opened a transaction, it joins it rather than committing it.
  - `after_commit()` callbacks run only after the outermost commit.
- The layers run controller → service → repository → `$wpdb`:
  - A repository holds all the SQL for one table and never opens a transaction (`includes/Repositories/AbstractRepository.php`). Every failed write throws, which is what makes `Transaction::run` roll back. A few read-side queries, such as the dashboard and the customer list, join other tables.
  - Models (`includes/Models/`) are typed rows that never touch the database. Enums (`includes/Enums/`) are classes of string constants, because the product runs on PHP 7.4.
  - Campaigns have no service, because their invariants are validation. Their controller calls the repository directly.
- `includes/Services/LedgerService.php` is the only writer of ledger rows and of the customer's cached totals (balance, lifetime earned and redeemed):
  - In one transaction, it locks the customer row (`SELECT … FOR UPDATE`), appends the ledger row with its running `balance_after`, and updates the totals.
  - It fires `yayloyalty_ledger_recorded` after commit.
  - System rows carry an idempotency key that a unique index backs, so a replayed event can't award twice.
  - Its docblock lists where it may be constructed.
  - Points pending on an open order are a separate cached column, owned by `includes/Engine/Earning/PendingPoints.php`.
- The domain lives in `includes/Engine/`:
  - Earning listeners (`includes/Engine/Earning/Listeners/`) on WooCommerce order, review and signup hooks call `EarningService::award()` (`includes/Engine/Earning/EarningService.php`). It runs the gates, asks the Points engine, then calls `LedgerService::earn()`, all in one transaction.
  - Refunds, cancellations and trashed orders go through `includes/Engine/Earning/Reversal.php`. It claws points back through `LedgerService`, or restores them when a refund is deleted.
  - Each listener runs inside `ListenerGuard`, which logs failures and never rethrows, so loyalty can't break an order save.
  - The Points engine (`includes/Engine/Points/`) scores events and writes nothing. It reads live campaigns and a `Context` that `ContextFactory` builds from WooCommerce data.
  - Redemption (`includes/Engine/Redemption/`, `includes/Engine/Rewards/`) is designed, not built yet: its stubs are marked `TODO(redemption)`, and nothing hooks them.
  - `includes/Engine/CustomerActivityListener.php` (hooked in `yayloyalty.php`) records account and points activity in `customer_meta`, through `includes/Services/CustomerActivityService.php`.

## Data and upgrades

- `Installer::schemas()` (`includes/Database/Installer.php`) is the list of installed tables. Each entry is a class in `includes/Database/Schema/` that returns its table's `dbDelta` DDL.
  - A schema class that isn't on the list is only a design.
  - `includes/Database/Schema/RedemptionsTable.php` throws instead of returning DDL.
- `Table::name()` (`includes/Utils/Table.php`) reads `$wpdb->prefix` on every call and never caches it, because multisite switches blogs. Integration tests rely on the same behaviour to move every plugin table to a scratch prefix.
- `Installer::install()` is safe to repeat:
  - It runs `dbDelta` for every schema, then seeds the built-ins.
  - It seeds demo customers, unless the `yayloyalty_init_demo_customers` filter turns that off.
  - It grants capabilities and runs migrations.
  - It writes `yayloyalty_db_version` last, so a crash mid-install retries.
- `yayloyalty_db_version` holds the plugin version, not a schema version. `Installer::maybe_upgrade` re-runs the whole `install()` whenever it differs from `YAYLOYALTY_VERSION`, behind an `add_option` lock. A schema change therefore reaches existing sites only through a version bump.
- Capabilities have their own version. `Capabilities::install()` grants the defaults to administrator and shop_manager, then stores `Capabilities::VERSION` in `yayloyalty_caps_version`. `migrate()` re-runs it when the constant is higher than the stored value.
- Settings (`yayloyalty_settings`) and design (`yayloyalty_design`) are options. Each belongs to one helper in `includes/Helpers/`. That helper merges code defaults on read, and whitelists and sanitises on both read and write. The emails' on/off switches live in WooCommerce's own email options.
- Orders are read and written only through WooCommerce's CRUD API, never with SQL on order tables, which keeps the plugin HPOS-compatible. Pending and awarded points sit in order meta. `PendingPoints` saves only the meta, so no full order save runs inside an award.
- There are no cron or Action Scheduler jobs and no custom post types.

## Pro and Lite

One tree builds both editions. Lite is Pro without `includes/Pro/` and without the SPA's Pro code. The rules that keep the split safe are in [CLAUDE.md](../CLAUDE.md); this section is how it works.

- PHP seam: the constructor of `includes/YayLoyalty.php` calls `YayLoyaltyPro::boot()` when `is_pro()` finds the class. This checks that the Pro code is present; it is not a licence check. In Lite it is false, because the autoloader skips the missing file.
- `boot()` in `includes/Pro/YayLoyaltyPro.php` is the full list of what Pro changes. Each entry is a filter: either on WooCommerce's email list, or on an extension point that Lite applies itself with a working default. For example, with no conditions evaluator attached, campaign conditions are not applied. Pro adds no capabilities.
- Stored Pro data survives a move to Lite. `CampaignTrigger` and `RewardType` (`includes/Enums/`) separate `all()`, what this build offers, from `all_known()`, every value that may be stored, so Pro rows still validate.
- SPA flag: `apps/admin/vite.config.ts` turns `BUILD_TYPE` into `VITE_IS_PRO`. `lite` builds Lite; anything else, including unset, builds Pro. `apps/admin/src/lib/is-pro-build.ts` exposes the result as `IS_PRO_BUILD`. Only `scripts/run.sh` sets `BUILD_TYPE`.
- Lite drops Pro code only through constant folding and tree-shaking. There is no plug-in registry, alias swap or lint rule.
  - One route table (`apps/admin/src/router.tsx`) and the same pages serve both builds.
  - Shared code imports Pro components from `apps/admin/src/pro/index.ts` and renders them inside an `IS_PRO_BUILD` branch. In Lite, that branch shows an upsell or a not-found page instead.
  - `<ProGate>` (`apps/admin/src/components/pro/ProGate.tsx`) ships in both builds and swaps in the upsell at runtime. Its check runs inside its own module, so it can't drop its children from the bundle; only the branch at the call site does that.
- `scripts/validate-build.php` fails a zip when:
  - Lite still has `includes/Pro/`;
  - Lite PHP names `Pro` outside `includes/YayLoyalty.php`, once comments are stripped;
  - the bundle sentinel from `apps/admin/src/pro/index.ts` is present in Lite or missing from Pro;
  - Lite lacks the upsell text;
  - the bundle has more than one JS chunk;
  - any PHP array carries a Pro-flag key such as `isPro`.
- The sentinel is reachable only through `registerPro()`, which `apps/admin/src/main.tsx` calls inside the build-flag branch. The sentinel check therefore proves only that the Lite flag reached Vite and folded that branch away. It doesn't prove every Pro module was dropped. For example, a Lite build with `<ProGate><DesignTypographySection /></ProGate>` and no `IS_PRO_BUILD` branch ships that section and still passes.

## SPA and WordPress

- The page: admin-shell adds the YayLoyalty submenu from the adapter, and the adapter's callback prints `<div id="yayloyalty">` for `apps/admin/src/main.tsx` to mount into.
  - `includes/Engine/Admin/Settings.php` enqueues the app only on that page's hook suffix, `yaycommerce_page_yayloyalty`.
  - It also adds the `yay-ui` body class, which scopes the SPA's Tailwind utilities (`apps/admin/src/main.css`).
- Loading: `includes/Engine/Register/RegisterFacade.php` chooses a registrar by `YAYLOYALTY_IS_DEVELOPMENT`:
  - `true`: `RegisterDev` loads `/main.tsx` from the Vite dev server on port 3030, plus React's refresh preamble;
  - anything else: `RegisterProd` loads the fixed files `assets/dist/admin/js/main.js` and `style.css`. There is no manifest.
  - Either way the app is one ES module (the facade adds `type="module"`) that depends on WordPress's React and `@wordpress/*` scripts. The production build maps those imports to WordPress globals (`apps/admin/vite.config.ts`).
- Data hand-off: `includes/Helpers/LocalizeHelper.php` builds two globals:
  - `yayLoyaltyAdmin` carries settings, design and the current user's capabilities, filtered by `yayloyalty_localize_admin_data`;
  - `yayLoyaltyMeta` carries the REST root, namespace and `wp_rest` nonce, plus site, plugin and WooCommerce data.
  - Their TypeScript shapes are in `apps/admin/src/types/localize.type.ts`.
- REST calls go through one ky client (`apps/admin/src/lib/api/api.ts`). It prefixes the REST root, sends the nonce as `X-WP-Nonce`, and falls back to `?rest_route=` on plain permalinks.
  - Each resource family has a client module in `apps/admin/src/lib/api/` and TanStack Query options in `apps/admin/src/lib/queries/`.
  - Its zod schemas in `apps/admin/src/lib/schema/` mirror the PHP response shapes. A schema file's opening comment usually names the PHP code it mirrors.
  - Settings and design start from the localized data instead of a first fetch.
- Capabilities: `apps/admin/src/lib/access.tsx` mirrors the PHP capability names, and routes, menus and controls hide or disable by them. This is presentation only; the REST permission callbacks are what enforce access.
- Routing is hash-based (`createHashRouter` in `apps/admin/src/router.tsx`). The WordPress URL stays `admin.php?page=yayloyalty`, and the app's route follows the `#`.
- Translations:
  - PHP and JS share the `yayloyalty` text domain.
  - JS calls `@wordpress/i18n`, which the facade's `pre_load_script_translations` filter feeds from the plugin's MO file.
  - `makepot` skips `apps/`, so JS strings reach the POT only from the built bundle. Terser keeps the translation calls and `translators:` comments for it.

## Build and release

- `./scripts/run.sh build` type-checks, then runs `vite build` into `assets/dist/admin/` (`apps/admin/package.json`). `BUILD_TYPE` sets the edition.
- `./scripts/run.sh release` first stamps the version into the plugin header, `YAYLOYALTY_VERSION` and the `readme.txt` stable tag; `yayloyalty.php` is the version source. Then `build_package` in `scripts/run.sh`:
  1. runs the prerelease sync, which leaves only `--no-dev` Composer packages, and dumps the production classmap;
  2. builds the SPA;
  3. stages `release/yayloyalty/` from an explicit include list: the root plugin files, `includes/`, `assets/`, `languages/`, `vendor-prefixed/` and Composer's autoloader only;
  4. Lite only: deletes `includes/Pro/`;
  5. both editions: delete `RegisterDev.php` and dev artifacts;
  6. writes `release-manifest.json`, with a SHA-256 for each file, and zips;
  7. runs `scripts/validate-build.php` and renames a failing zip to `.invalid`.
- Both editions unpack to the same `yayloyalty/` folder, so Pro installs over Lite in place.
- Pro runs first because that run stamps the version, which Lite's `--no-prompt` run then reads back, so both zips carry the same version. The two runs share `assets/dist/admin/` and the staging folder, so they must run one after the other.
- Besides the Pro/Lite checks above, the validator checks:
  - the manifest: build type, hashes, no unlisted files, and a version that matches the header and the constant;
  - `php -l` on every PHP file;
  - that no dev artifacts remain;
  - a headless WP-CLI boot of the zip, when `YAYLOYALTY_WP_CLI` is set. Otherwise it reports SKIP.
- `readme.txt` is the WordPress.org readme and carries the release notes.

## Tests

- **Unit** (`phpunit.xml.dist`, `tests/unit/`): plain PHP with no WordPress.
  - `tests/bootstrap.php` maps the namespace onto `includes/` and shims the WordPress functions the tested code reaches.
  - There is no `$wpdb`, so anything that queries belongs in integration.
  - `tests/unit/EngineTestCase.php` builds campaigns and contexts in memory.
- **Integration** (`phpunit-integration.xml.dist`, `tests/integration/`): `tests/integration/bootstrap.php` boots the WordPress site the plugin is installed in and needs the plugin active.
  - It sets `$wpdb->prefix` to `ylit_`, which moves every plugin table to a scratch copy, and gives other plugins' tables, WooCommerce's included, empty scratch copies too. It drops all of them at the end.
  - WordPress core tables, such as users and posts, stay real.
  - `tests/integration/IntegrationTestCase.php` truncates tables between tests rather than rolling back, because the code under test opens its own transactions.
  - The traits beside it create real WooCommerce orders, users, reviews and email settings.
- **Vitest** (`apps/admin/vitest.config.ts`) runs `apps/admin/tests/**/*.test.ts` in Node, with the `@` alias.
  - It doesn't load `vite.config.ts`, so `IS_PRO_BUILD` is false unless the shell exports `VITE_IS_PRO=true`.
  - Tests sit outside `tsconfig.json`, so `tsc` doesn't check them.
- **Shared fixtures:**
  - `tests/fixtures/` holds data that both languages test against, with PHP as the source of truth. For example, `tests/fixtures/points-math-vectors.json` pins `includes/Utils/PointsMath.php` and its twin `apps/admin/src/lib/points-math/points-math.ts`.
  - `scripts/dump-design-defaults.php` regenerates `apps/admin/tests/fixtures/design-defaults.json` from `includes/Helpers/DesignHelper.php`.
