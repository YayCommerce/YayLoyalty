# Thank-You Page Points Block Implementation Plan

> Execute tasks in order; steps use `- [ ]`.

**Status:** active
**Goal:** On the order-received (thank-you) page, show a signed-in customer what the order earns: "You'll earn 270 Points when your order completes" while pending, "You earned 270 Points on this order" once completed, followed by a balance line with the points still pending.
**Spec:** none. The request is the thank-you page block, earned/pending version (artboard 1e).
**Architecture:** One new class, `ThankYouPoints`, reads the order's existing `PendingPoints` meta and the customer row, and prints a small block on `woocommerce_thankyou`. WooCommerce fires that action in the classic `checkout/thankyou.php` template and in the block theme's Order Confirmation → Additional Information block, so one hook covers both. It only reads data; nothing is computed or written at render time.
**Touches:** `includes/Helpers/DesignSchema.php` (shared: consumed by `DesignHelper`, `LauncherCopy`, `LauncherPreview`, the Design REST routes), `includes/Engine/Storefront/LauncherCopy.php` (shared: launcher templates, `AccountRewards`, `ProductEarnMessage`), `apps/admin/src/lib/schema/design.schema.ts`, `apps/admin/src/lib/api/design.api.ts` (comment only), `apps/admin/src/pages/settings/DesignVisibilitySection.tsx`, `DesignStorefrontTextSection.tsx`, `design-settings.copy.ts`, new `includes/Engine/Storefront/ThankYouPoints.php`, `includes/YayLoyalty.php`, `docs/architecture.md`
**Tests:** none. The block only displays figures `PendingPoints` and `LedgerService` already wrote; it does no points math and writes nothing, so CLAUDE.md's test rule doesn't call for one. Each task has a manual check instead.

## Requirements

- Already implemented? No. `git grep` finds no thank-you or `woocommerce_thankyou` code in the plugin (checked 2026-10-11 on `trace/fix/validator-pro-key-scan`, at `dev/edd` 196a458).
- Shown only to a signed-in viewer who is the order's customer, while the program is enabled and Design → Visibility `thankyou_message` is on.
- **Pending state** (order has `PendingPoints::META_PENDING` ≥ 1 and no `META_RELEASED`): the `thankyou_pending_line` sentence, default "You'll earn {points} {points_label} when your order completes", with `{points}` = `META_PENDING`.
- **Earned state** (`PendingPoints::META_EARNED` ≥ 1): the `thankyou_earned_line` sentence, default "You earned {points} {points_label} on this order", with `{points}` = `META_EARNED`. This covers virtual orders that complete during payment.
- Balance line under the sentence, fixed translatable copy: pending state "Balance: {balance}"; earned state "New balance: {balance}". Either state appends " · {pending} pending until your orders complete" when `customers.pending_points` ≥ 1, using that cached sum.
- Anything else (no staged or earned meta, released with nothing earned, failed or cancelled order, guest order, guest viewer, another user): print nothing.
- Out of scope: the guest "claim your points" version (needs a held-points mechanism) and "Points were spent on this order" (waits for redemption).

## Changes during implementation

- **Branch:** `trace/feat/thank-you-points`, matching the repo's other branches.
- **Test orders:** made with `wc_create_order()` in `wp eval` (pending, then processing, as a COD order goes), not through checkout; the order-received page was then opened in the browser. Order #534: "You'll earn 40" with pending 445 → 485, then completed: "You earned 40", balance 53,774 → 53,814, pending back to 445.
- **Theme check:** the site already runs Twenty Twenty-Five, so Step 6 needed no theme switch; the block shows once, in Additional Information. The classic template was checked by firing `woocommerce_thankyou` in `wp eval` rather than by switching themes.
- **Visibility description:** Design → Visibility's description now names the thank-you page points too.

## Global constraints

- Product PHP must run on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types; also no `mixed`/`static`/`never` types, `catch` without a variable, `throw` as an expression, or first-class callables.
- Lite names Pro only in `includes/YayLoyalty.php`. Nothing here touches `includes/Pro/`.
- Only `LedgerService` writes ledger rows and balances. This plan writes none, and never calls `PendingPoints::stage()` or `release()`.
- New design keys go in both `DesignSchema::get_default_design()` and `schema()`, and in `apps/admin/src/lib/schema/design.schema.ts`.
- Show points through `LauncherCopy::points()` and `SettingsHelper::points_label()` / `points_text()`, never `number_format_i18n()`.
- Read the raw `storefront_text` values from `DesignHelper::get_design()`, not `LauncherCopy::storefront_text()`, which fills `{points_label}` with the plural label ("1 Point" must stay singular).
- Text domain `yayloyalty`; every new string translatable, with translators comments on placeholders. Comments are minimal: one-line docblocks.
- Branch from `dev/edd` as `trace/feat/thank-you-points`. Conventional Commits.
- Checks that fail on `dev/edd` today (`docs/known-issues.md`): `composer lint` over the whole repo, admin `lint`, admin `tsc --noEmit` (TS2307 for `@emoji-mart/*`, `@radix-ui/react-progress`), `./scripts/run.sh build`. Run phpcs on changed PHP files only and expect no errors or warnings; for `tsc`, expect only those TS2307 errors.

## Review focus

- Another person opening the order-received URL (logged out, or signed in as someone else) must see no balance (Task 2, Step 5).
- A COD or BACS order: staged at creation, still pending on the thank-you page (Task 2, Step 4).
- A virtual order completed during payment: earned state, and no leftover pending for that order (Task 2, Step 4).
- An order where no campaign applied: no block, not "You'll earn 0" (Task 2, Step 5).
- Block theme: the block appears once, in Additional Information (Task 2, Step 6).
- Existing installs get the defaults without saving the Design tab (Task 1, Step 3).

---

### Task 1: Design settings for the block

**Files:**
- Modify: `includes/Helpers/DesignSchema.php` (defaults ~23-42, `schema()` ~78-110)
- Modify: `apps/admin/src/lib/schema/design.schema.ts`, `apps/admin/src/lib/api/design.api.ts:22` (comment)
- Modify: `apps/admin/src/pages/settings/DesignVisibilitySection.tsx`, `DesignStorefrontTextSection.tsx`, `design-settings.copy.ts`

**Interfaces:**
- Produces:
  - `visibility.thankyou_message`: `bool`, default `true`.
  - `storefront_text.thankyou_pending_line`: `text`, `required: true`, default `__( "You'll earn {points} {points_label} when your order completes", 'yayloyalty' )`.
  - `storefront_text.thankyou_earned_line`: `text`, `required: true`, default `__( 'You earned {points} {points_label} on this order', 'yayloyalty' )`.

- [x] **Step 1: Add the three keys to `get_default_design()` and `schema()`**, after `product_earn_line` / `product_message`, each default with the "keep every {…} placeholder" translators comment that `product_earn_line` uses. Extend the `{points_label}` comment above `storefront_text` to name the new lines as filled by `LauncherCopy::product_earn()`.
- [x] **Step 2: Mirror them in the SPA**
  - `design.schema.ts`: `thankyou_pending_line: z.string().min(1)`, `thankyou_earned_line: z.string().min(1)`, `thankyou_message: z.boolean()`.
  - `design-settings.copy.ts`: `VISIBILITY_THANKYOU_MESSAGE_LABEL` ("Show points on the thank-you page"), `STOREFRONT_THANKYOU_PENDING_LINE_LABEL` ("Thank-you page, points pending"), `STOREFRONT_THANKYOU_EARNED_LINE_LABEL` ("Thank-you page, points earned").
  - `DesignVisibilitySection.tsx`: a switch for `visibility.thankyou_message`, id `design-visibility-thankyou-message`, after the `product_message` switch, same markup.
  - `DesignStorefrontTextSection.tsx`: two `<StorefrontTextField>`s after `product_earn_line`; update the docblock's field count.
  - `design.api.ts:22`: name the two new lines next to `product_earn_line` (drawn by `ThankYouPoints`).
- [x] **Step 3: Verify defaults on the existing install**
  From `/Users/shrief/Local-Sites/yaydevs/app/public`: `php -d "mysqli.default_socket=/Users/shrief/Library/Application Support/Local/run/DmdPSy05S/mysql/mysqld.sock" /opt/homebrew/bin/wp eval '$d=\YayLoyalty\Helpers\DesignHelper::get_design(); var_export([$d["visibility"]["thankyou_message"],$d["storefront_text"]["thankyou_pending_line"],$d["storefront_text"]["thankyou_earned_line"]]);'`
  Expected: `true` and the two default sentences.
- [x] **Step 4: Verify in the admin.** `./scripts/run.sh dev`, open Design. Turn the switch off, edit both lines, save, reload: the values persist. Clear one line and save: the required-field error shows and nothing saves. Restore the defaults.
- [x] **Step 5: Checks.** `composer lint -- includes/Helpers/DesignSchema.php`: clean. `node node_modules/typescript/bin/tsc --noEmit` in `apps/admin`: only the existing TS2307s. `pnpm --filter @yayloyalty/admin-page test`: passes.
- [x] **Step 6: Commit** the six files above. Message: `feat(design): settings for the thank-you page points block`

### Task 2: The block on the thank-you page

**Files:**
- Create: `includes/Engine/Storefront/ThankYouPoints.php`
- Modify: `includes/YayLoyalty.php` (after `ProductEarnMessage::register();`)
- Modify: `docs/architecture.md:81` (a Storefront bullet after the product-page line)

**Interfaces:**
- Consumes: Task 1's keys; `PendingPoints::META_PENDING`, `META_RELEASED`, `META_EARNED`; `CustomerRepository::find_by_user_id()`, `Customer::get_balance()`, `get_pending_points()`; `LauncherCopy::product_earn( string $template, int $points )` (it fills exactly `{points}` and `{points_label}`, so reuse it for both lines); `ProgramGate::program_enabled()`.
- Produces: `final class ThankYouPoints` in `YayLoyalty\Engine\Storefront`:
  - `public const CSS_CLASS = 'yayloyalty-thankyou-points';`
  - `public static function register(): void`: `add_action( 'woocommerce_thankyou', [ self::class, 'render' ], 5 )` (priority 5 puts it above the classic order-details table at 10).
  - `public static function render( $order_id ): void`: echoes `markup( \WC_Order $order )`.
  - `private static function markup( \WC_Order $order ): string`: the HTML, or `''`.

- [x] **Step 1: Implement `markup()`** per the Requirements states. Return `''` when the program is off, the switch is off, `get_current_user_id()` is 0 or differs from `$order->get_customer_id()`, the order `has_status( [ 'failed', 'cancelled', 'refunded' ] )`, or neither state applies. Earned state wins over pending when both meta are set. No customer row: return `''`. Wrap the body in `try { … } catch ( \Throwable $e )`, logging `Log::warning( 'ThankYouPoints', … )` and returning `''`, so the order page never breaks.
  Markup: `<section class="yayloyalty-thankyou-points" style="margin:1.5em 0"><p class="yayloyalty-thankyou-points__line" style="margin:0"><strong>{esc_html sentence}</strong></p><p class="yayloyalty-thankyou-points__balance" style="margin:0">{esc_html balance line}</p></section>`.
  Balance line strings: `/* translators: %s: points with label, as "1,240 Points" */ __( 'Balance: %s' )`, `__( 'New balance: %s' )`, and `__( '%s pending until your orders complete' )` joined with ` · `; amounts through `SettingsHelper::points_text()`.
- [x] **Step 2: `render()`**: `wc_get_order( (int) $order_id )`; when it's a `\WC_Order`, echo `markup()` with the `phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup() escapes every part.` comment.
- [x] **Step 3: Wire and document.** `includes/YayLoyalty.php`: `\YayLoyalty\Engine\Storefront\ThankYouPoints::register();` right after `ProductEarnMessage::register();`. `docs/architecture.md`: one bullet: `ThankYouPoints` on `woocommerce_thankyou` (classic template and the Order Confirmation block), reads `PendingPoints` order meta and the customer row only, signed-in owner only, `visibility.thankyou_message` and the two `storefront_text` lines.
- [x] **Step 4: Verify the pending and earned states.** Signed in as user 1 on the Local site (classic checkout), place a Cash on Delivery order for a product that earns. Expected on order-received: "You'll earn N Points when your order completes" (N = the order's `_yayloyalty_points_pending`) and "Balance: B · P pending…", where B and P match `wp eval 'var_export((new \YayLoyalty\Repositories\CustomerRepository())->find_by_user_id(1));'`. Then `wp eval '$o=wc_get_order(ID);$o->update_status("completed");'` and reload: "You earned N Points on this order" and "New balance: B+N", with the pending part shrunk by N (or gone).
- [x] **Step 5: Verify who sees nothing.** Same URL logged out, and signed in as another user: no `.yayloyalty-thankyou-points` in the page. An order whose items earn nothing: no block. Design switch off: no block. Program disabled: no block.
- [x] **Step 6: Verify the block theme.** Under Twenty Twenty-Five (block checkout), place an order: the block shows once, inside Additional Information. Restore the previous theme.
- [x] **Step 7: Checks.** `composer lint -- includes/Engine/Storefront/ThankYouPoints.php includes/YayLoyalty.php`: clean. Grep the new file for PHP 8-only syntax.
- [x] **Step 8: Commit** `includes/Engine/Storefront/ThankYouPoints.php`, `includes/YayLoyalty.php`, `docs/architecture.md`. Message: `feat(storefront): show order points on the thank-you page`

Final verification: Task 2, Step 4 passes end to end, in both the pending and the completed state.
