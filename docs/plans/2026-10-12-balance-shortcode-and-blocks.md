# Balance Shortcode and Blocks Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. There is no spec; the Requirements section below is the source of truth.

**Status:** active
**Goal:** A merchant can place the shopper's points balance card and the "Ways to earn" list on any page, with the `[yayloyalty_balance]` / `[yayloyalty_ways_to_earn]` shortcodes or the "Points balance" / "Ways to earn" blocks in a "YayLoyalty" inserter category.
**Spec:** none, planned from the request (artboard 1f "Mini-cart and small placements", callouts 2 and 3, in `YayLoyalty Shopping Flow.dc.html` of the "YayLoyalty admin interface" wireframe set)
**Architecture:** Server-rendered only. The Rewards tab's card and Ways to earn section move into two partial templates, which the tab, the shortcodes and the blocks all include. The blocks are dynamic (`block.json` + PHP `render_callback` that calls the shortcode renderer), and their editor script is a small TSX entry built by a new Vite config into `assets/dist/blocks/`, previewing through `ServerSideRender`. Theme-native markup and `assets/storefront/account.css`, as on the Rewards tab.
**Touches:** `includes/Engine/Storefront/` (new `Placements`, two new partials, `AccountRewards`, `LauncherEarnRules` (shared: consumed by `panel-member.php`, `account-rewards.php`), `account-rewards.php`), new `includes/Engine/Storefront/blocks/*/block.json`, `ScriptName`, `includes/YayLoyalty.php`, `apps/admin/` (new `vite.blocks.config.ts`, `src/blocks/`, `package.json` scripts), `scripts/run.sh` (dev builds the blocks once), `docs/architecture.md`
**Tests:** none. No points or money math and no ledger writes change: everything reads through `LauncherPayload` and `LauncherEarnRules`. Each task has a manual check instead.

## Requirements

- **Goal:** a merchant writing a rewards landing page drops in the shopper's balance and the earning rules without styling anything twice.
- **Out of scope:**
  - The "Points redemption" block (waits for the redemption backend, `TODO(redemption)`) and the "Rewards catalog" block (waits for Pro). Neither is registered, not even as a placeholder.
  - The cart drawer from the same artboard.
  - Block settings (attributes, inspector controls): both blocks have none.
- **Acceptance criteria:**
  1. `[yayloyalty_balance]` on a page, signed in: the Rewards tab's points card (Design tab `balance_heading`, balance, worth line and "Use points at checkout" while redemption is available, expiry line) with the same markup and classes as the tab.
  2. Signed out, the same card shows a sign-in prompt where the number would be ("Sign in to see your Points", linked to My Account), and no balance, worth or expiry.
  3. `[yayloyalty_ways_to_earn]`: a member sees exactly the Rewards tab's Ways to earn (`member_rows()` under the daily limit, nothing when they cannot earn). A guest sees the purchase and review rates plus a "Create an account" row with the signup points when signup is advertised. No rows and no limit line → no output.
  4. While the program is off, both shortcodes and both blocks print nothing.
  5. The block inserter has a "YayLoyalty" category with "Points balance" ("The signed-in customer's balance and what it is worth") and "Ways to earn" ("The enabled earning rules as a list"). Inserted, each previews its server output in the editor, and on the front end prints the same HTML as its shortcode inside the block wrapper.
  6. My Account → Rewards looks and behaves exactly as before.
  7. End to end: on the Local site, a page holding both blocks and both shortcodes shows the same card and list twice for user 1, and the guest versions in a private window; it works the same in a Lite build.
- **Decided with the user:**
  - "rows()" is `LauncherEarnRules::member_rows()` (+ `with_daily_limit()`).
  - Guests get rates plus the signup row; members get `member_rows()`.
  - Blocks are dynamic with a Vite-built editor script.
- **Assumed (confirm in review):** `[yayloyalty_ways_to_earn]` ships too, as the wireframe says "the four blocks and their shortcode equivalents".

## Global constraints

- Product PHP must run on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types.
- Lite names Pro in exactly one place. Nothing here references `includes/Pro/` or `apps/admin/src/pro/`; the blocks bundle is the same for both editions and does not read `BUILD_TYPE`.
- Only `LedgerService` writes ledger rows and balances. This plan writes none.
- Display dates with `Dates::to_site_date()`, points with `LauncherCopy` / `SettingsHelper::points_text()`, money with `LauncherCopy::money()`. Never `number_format_i18n()`.
- One class per file, named for the class, path following the namespace. Comments minimal: one-line docblocks, a why only where code would look wrong.
- Never hand-edit `assets/dist/`. The blocks bundle is built by `pnpm build:blocks`.
- Branch from `dev/edd` as `<name>/feat/balance-shortcode-and-blocks`. Conventional Commits.
- Failing today on `dev/edd` (see `docs/known-issues.md`): `composer lint`, the admin `lint`, `tsc --noEmit` (TS2307 for `@emoji-mart/*` and `@radix-ui/react-progress`), `./scripts/run.sh build`. Run phpcs and eslint on the changed files only and expect nothing in them; for `tsc`, expect only the base's errors. Build the bundle with `pnpm build:blocks`.

## Review focus

- The balance is printed into the page HTML. A store that caches signed-in pages would show one shopper's balance to another — same exposure as the launcher (`LauncherPayload` docblock); guests get no balance, so a cached guest page is safe (Task 2, Step 4).
- ServerSideRender renders through the REST block renderer, which is not `is_admin()` and runs as the editor's user: the shortcodes and block types must be registered on every request (Task 2 Step 2, Task 3 Step 4).
- A shortcode used twice, or in a widget/excerpt where `wp_enqueue_scripts` has already run: the stylesheet must still load (late enqueue prints in the footer) and nothing errors (Task 2, Step 4).
- A throwing earn subscriber or engine must cost only the block, never the page: render wrapped like `AccountRewards::render()` (Task 2, Step 2).
- The tab refactor must not change its HTML (Task 1, Step 3).

---

### Task 1: Points card and Ways to earn as shared partials

**Files:**
- Create: `includes/Engine/Storefront/templates/points-card.php`
- Create: `includes/Engine/Storefront/templates/ways-to-earn.php`
- Modify: `includes/Engine/Storefront/templates/account-rewards.php`
- Modify: `includes/Engine/Storefront/AccountRewards.php`
- Modify: `includes/Engine/Storefront/LauncherEarnRules.php`

**Interfaces:**
- Produces:
  - `AccountRewards::card_view( int $user_id ): array{payload: array, expires_at: ?string}`: `LauncherPayload::build()` for the user (null for 0, a guest) and the customer row's `get_expires_at()`. `view()` uses it and drops its own `can_earn` key.
  - `LauncherEarnRules::guest_rows( array $earn, string $rate_line, float $purchase_min ): array<int, array{trigger, label, value}>`: a `signup` row first (label `__( 'Create an account', 'yayloyalty' )`, value `SettingsHelper::points_text( signup )`) when `earn['signup']` is not null, then `member_rows()`.
  - `LauncherEarnRules::for_viewer( array $payload, int $user_id ): array{rows, limit_line}`: guest (0) → `guest_rows()` and `''`; a member who fails `EarningService::gate_reason( CampaignTrigger::PURCHASE, ContextFactory::from_user( $user_id, Dates::now() ) )` → `with_daily_limit( [], null )`; else `with_daily_limit( member_rows(...), $payload['left_today'] )`. This replaces the ternary at the top of `account-rewards.php`. `panel-member.php` is unchanged.
  - `templates/points-card.php`, reads `$card` (`card_view()`): the `<section class="yayloyalty-account__card">` moved verbatim from `account-rewards.php`. When `payload['member']` is null (guest), in place of the balance `<p>`: `<p class="yayloyalty-account__signin"><a href="account_url">` + `/* translators: %s: the store's points label, as "Points". */ sprintf( __( 'Sign in to see your %s', 'yayloyalty' ), SettingsHelper::points_label( 0 ) )`, and no worth, expiry or checkout lines.
  - `templates/ways-to-earn.php`, reads `$earn` (`for_viewer()`): the Ways to earn `<section>` moved verbatim; prints nothing when rows are `[]` and the limit line is `''`.

- [ ] **Step 1:** add `card_view()`, `guest_rows()`, `for_viewer()` as above.
- [ ] **Step 2:** move the two sections into the partials; `account-rewards.php` includes them (`$card = $view`, `$earn = LauncherEarnRules::for_viewer( $view['payload'], get_current_user_id() )`). The `__rules` grid wrapper and its condition stay in the tab.
- [ ] **Step 3: Verify the tab is unchanged.** Before the change, save the HTML of `/my-account/rewards/` as user 1 (view source → file); after, diff it. Expected: no difference. Repeat for a customer with `_yayloyalty_opted_out` = 1: Ways to earn still absent.
- [ ] **Step 4: Lint.** `vendor/bin/phpcs` on the five files. Expected: no errors or warnings.
- [ ] **Step 5: Commit.** Stage the five files. Message: `refactor(storefront): share the points card and ways to earn as partials`

### Task 2: `[yayloyalty_balance]` and `[yayloyalty_ways_to_earn]`

**Files:**
- Create: `includes/Engine/Storefront/Placements.php`
- Modify: `includes/Engine/Storefront/AccountRewards.php` (`enqueue()` split)
- Modify: `includes/YayLoyalty.php` (next to `AccountRewards::register()`, outside `! is_admin()`)

**Interfaces:**
- Produces:
  - `final class Placements` in `YayLoyalty\Engine\Storefront`: the merchant-placed shortcodes and blocks.
  - `public const BALANCE_SHORTCODE = 'yayloyalty_balance'`, `public const WAYS_TO_EARN_SHORTCODE = 'yayloyalty_ways_to_earn'`
  - `public static function register(): void`: `add_shortcode()` for both, on every request.
  - `public static function balance(): string` and `public static function ways_to_earn(): string`: the shortcode callbacks (attributes ignored). Each returns `''` when `! ProgramGate::program_enabled()`; else calls `AccountRewards::enqueue_style()`, includes its partial into a buffer wrapped in `<div class="yayloyalty-account yayloyalty-placement">`, and returns it. A `\Throwable` drops the buffer to its starting level, logs `Log::warning( 'Placements', … )` and returns `''`, as `AccountRewards::render()` does.
  - `AccountRewards::enqueue_style(): void`: the `wp_enqueue_style( ScriptName::ACCOUNT_STYLE, … )` line from `enqueue()`, which now calls it behind its endpoint check.

- [ ] **Step 1:** split `AccountRewards::enqueue_style()` out.
- [ ] **Step 2:** write `Placements::register()`, `balance()`, `ways_to_earn()`; call `Placements::register()` in `includes/YayLoyalty.php`.
- [ ] **Step 3: Docs comment.** One-line docblock on each callback naming the shortcode.
- [ ] **Step 4: Verify.** Create a page with `[yayloyalty_balance]` and `[yayloyalty_ways_to_earn]` twice each.
  - As user 1: two identical cards matching `/my-account/rewards/`'s card, two identical lists matching its Ways to earn; `account.css` is loaded once (view source).
  - Private window: "Sign in to see your …" linking to My Account, no number; the list starts with "Create an account" when the signup campaign is on and registration is open, and lacks it when registration is off.
  - Program off in wp-admin: both render nothing.
  - Add `[yayloyalty_balance]` to a Text widget in the footer: it renders styled.
  - No PHP notices in `wp-content/debug.log`.
- [ ] **Step 5: Lint.** phpcs on the three files. Expected: none.
- [ ] **Step 6: Commit.** Stage the three files. Message: `feat(storefront): add the balance and ways to earn shortcodes`

### Task 3: "Points balance" and "Ways to earn" blocks

**Files:**
- Create: `includes/Engine/Storefront/blocks/points-balance/block.json`, `includes/Engine/Storefront/blocks/ways-to-earn/block.json`
- Create: `apps/admin/vite.blocks.config.ts`, `apps/admin/src/blocks/index.tsx`, `apps/admin/src/blocks/wordpress.d.ts`
- Modify: `apps/admin/package.json` (scripts `build:blocks`; `build` runs it after `build:storefront`), `scripts/run.sh` (`cmd_dev` runs `$PNPM build:blocks` next to `build:storefront`)
- Modify: `includes/Engine/Storefront/Placements.php`, `includes/Engine/Register/ScriptName.php`

**Interfaces:**
- Produces:
  - `block.json` (apiVersion 3, `textdomain: yayloyalty`, `category: yayloyalty`, `editorScript: yayloyalty/script/blocks-editor`, `supports: { html: false }`, no attributes): `yayloyalty/points-balance` "Points balance", icon `star-filled`; `yayloyalty/ways-to-earn` "Ways to earn", icon `list-view`; descriptions from criterion 5.
  - `ScriptName::BLOCKS_EDITOR_SCRIPT = 'yayloyalty/script/blocks-editor'`
  - `Placements::register()` additionally hooks `init`: `wp_register_script( BLOCKS_EDITOR_SCRIPT, YAYLOYALTY_PLUGIN_URL . 'assets/dist/blocks/editor.js', [ 'react', 'wp-blocks', 'wp-block-editor', 'wp-server-side-render' ], Launcher::asset_version( … ), true )`, then `register_block_type( __DIR__ . '/blocks/<name>', [ 'render_callback' => … ] )` for both. The callback returns `''` when the shortcode renderer does, else `sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), Placements::balance() )` (resp. `ways_to_earn()`). Filter `block_categories_all` prepends `[ 'slug' => 'yayloyalty', 'title' => 'YayLoyalty' ]` once.
  - `src/blocks/index.tsx`: for each of the two names, `registerBlockType( name, { edit, save: () => null } )`, where `edit` renders `<div {...useBlockProps()}><ServerSideRender block={name} /></div>`. Metadata (title, icon, category) comes from the server registration, not repeated in TS. No translatable strings.
  - `src/blocks/wordpress.d.ts`: ambient `declare module` types for the three functions used (`registerBlockType`, `useBlockProps`, default `ServerSideRender`), so no `@wordpress/*` package is added.
  - `vite.blocks.config.ts`: `build.lib` entry `src/blocks/index.tsx`, format `iife`, `outDir` `assets/dist/blocks`, file `editor.js`, `emptyOutDir: true`; react plugin with `jsxRuntime: 'classic'`; `rollupOptions.external` + `output.globals` mapping `react` → `React`, `@wordpress/blocks` → `wp.blocks`, `@wordpress/block-editor` → `wp.blockEditor`, `@wordpress/server-side-render` → `wp.serverSideRender`. Same bundle for Lite and Pro.

- [ ] **Step 1:** write the two `block.json`, the TSX entry and its d.ts, the Vite config and the `build:blocks` script; wire `run.sh` dev.
- [ ] **Step 2:** add the script handle, registration, render callbacks and category to `Placements`.
- [ ] **Step 3: Build.** In `apps/admin`: `pnpm build:blocks`. Expected: `assets/dist/blocks/editor.js` exists, no `React`/`wp` code inlined (it is a few KB).
- [ ] **Step 4: Verify in the editor.** New page in the block editor, type `/` → search "points": "YayLoyalty" category with exactly the two blocks and their descriptions. Insert both: each previews the server output (user 1's card and list). Publish, open the page: same HTML as the shortcodes, inside `wp-block-yayloyalty-*` wrappers. Browser console in the editor: no errors. Program off → the front end prints nothing for either block.
- [ ] **Step 5: Checks.** `node node_modules/typescript/bin/tsc --noEmit` from `apps/admin`: only the base's TS2307 errors. `node node_modules/eslint/bin/eslint.js src/blocks vite.blocks.config.ts`: no problems. phpcs on `Placements.php`, `ScriptName.php`: none.
- [ ] **Step 6: Commit.** Stage the files above by name (`assets/dist/` is gitignored; check `git status` for a stray `composer.json` change from the dev sync). Message: `feat(storefront): add the points balance and ways to earn blocks`

### Task 4: Document it and verify end to end

**Files:**
- Modify: `docs/architecture.md` (Storefront bullet, after the `AccountRewards` sub-bullet)

- [ ] **Step 1:** add a sub-bullet: `Placements` registers `[yayloyalty_balance]`, `[yayloyalty_ways_to_earn]` and the matching dynamic blocks on every request (the editor previews through the REST block renderer). Both render the Rewards tab's partials (`points-card.php`, `ways-to-earn.php`); the blocks' editor script is built by `vite.blocks.config.ts` into `assets/dist/blocks/`. Redemption and Rewards catalog blocks are not built.
- [ ] **Step 2: Verify end to end (criterion 7).** Run `./scripts/run.sh dev` once to confirm it builds the blocks bundle, then on the Local site a page with both blocks and both shortcodes: user 1 sees the card and list twice, identical to the Rewards tab; a private window sees the sign-in card and guest rows. For Lite: `git grep -n -i "pro" -- includes/Engine/Storefront/Placements.php includes/Engine/Storefront/blocks apps/admin/src/blocks apps/admin/vite.blocks.config.ts` finds no reference to `YayLoyalty\Pro`, `includes/Pro` or `src/pro`.
- [ ] **Step 3: Commit.** Stage `docs/architecture.md`. Message: `docs(architecture): describe the balance and ways to earn placements`
