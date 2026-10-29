# Reward Coupon Codes Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking.

**Status:** done 2026-10-29
**Goal:** Every reward, the built-in points discount included, gets one coupon code `yayloyalty-{8 characters}` when the reward is created. The code is stored on the reward row, never changes, and is the code of the reward's virtual coupon on carts and orders. It replaces `yayloyalty-redeem` and `yayloyalty-reward-{id}`.
**Spec:** none. This plan is built from the request and the answers in `## Requirements`.
**Architecture:** a new `rewards.coupon_code` column (unique) that `RewardRepository::insert()` fills. The coupons stay virtual (no `shop_coupon` post). Lite recognises a loyalty code by its shape (`Points\Context::is_loyalty_code()`, WordPress-free) and resolves it to its reward with one indexed lookup per code per request (`RedemptionService::reward_for_code()`). The slider is the reward whose type is `flexible_discount`; it is no longer a fixed code.
**Touches:** `includes/Database/` (RewardsTable, Installer), `includes/Models/Reward.php`, `includes/Repositories/RewardRepository.php`, `includes/Controllers/RewardRestController.php`, `includes/Engine/Points/Context.php`, `includes/Engine/Redemption/` (CouponProvider, RewardCoupons, RedemptionService, CommitWriter, RedemptionDraft, Reversal, Adapters/StoreApiAdapter), `includes/Engine/Rewards/ContextFactory.php`, `includes/Engine/Storefront/RedeemCard.php`, `includes/Pro/Services/DiscountRewardHandler.php`, `apps/admin/src/lib/schema/reward.schema.ts`, `apps/admin/src/pro/rewards/editor/`, `apps/admin/src/pages/rewards/BuiltInRewardEditor.tsx`, tests, `docs/architecture.md`, `docs/decisions/`.
**Tests:** two new tests, accepted 2026-10-28: `ReversalTest::test_a_cancel_of_a_mixed_order_restores_each_row_once_under_its_own_key` (integration) and `ContextTest` (unit) for `is_loyalty_code()`. Existing tests that hardcode the old codes are updated in Task 2. Every other task ends in an exact manual check.
**Branch:** `trace/feat/reward-coupon-codes` from `dev/edd`.

## Requirements

- R1. Every reward gets a code `yayloyalty-` plus 8 characters from `[a-z0-9]` once, when its row is inserted: catalog rewards created through REST, and the seeded built-in `cart-discount`. Editing a reward never changes it, and no redemption creates one.
- R2. Codes are unique across rewards (unique key), and generation skips a code a WooCommerce `shop_coupon` already uses.
- R3. The code is the reward's virtual coupon code on the cart, in the totals and on the order's coupon lines. It replaces `yayloyalty-reward-{id}` for catalog rewards and `yayloyalty-redeem` for the points discount. The coupons stay virtual: no `shop_coupon` post, so [the stacking decision](../decisions/2026-10-22-stack-points-discount-and-rewards.md)'s "no `shop_coupon` post" holds.
- R4. Redemption rows store the reward's code in `coupon_code`. Ledger keys keep their qualifiers: `flexible_discount` for the slider row and `reward_{id}` for a reward row.
- R5. A shopper who types a loyalty code into the coupon field gets today's behaviour, unchanged: WooCommerce accepts it, and the next totals run removes it with no discount.
- R6. The merchant sees the code, read-only, in the reward editor (Pro catalog rewards) and on the built-in reward's card. REST returns it as `coupon_code`, read-only.
- R7. Earning still ignores loyalty codes: `get_real_coupon_codes()` drops them, so `exclude_coupon_orders` still awards an order whose only discounts are loyalty ones.

## Global constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union/`mixed`/`static` types, a trailing comma after the last declared parameter, or first-class callables (hard rule 4, `.claude/rules/php-standards.md`).
- Lite names Pro only in `includes/YayLoyalty.php`'s guarded `YayLoyaltyPro::boot()` call (hard rule 2). Everything here is Lite except `DiscountRewardHandler` and the Pro editor, which call Lite, never the other way.
- SPA Pro code stays in `apps/admin/src/pro/` (hard rule 3).
- Only `LedgerService` writes ledger rows (hard rule 5). This plan adds no writer, and changes only how the qualifier is picked.
- Table changes follow the dbDelta rules in `AbstractTableSchema`'s docblock: `UNIQUE KEY name (col)`, one space between column and type.
- `Points\Context` stays WordPress-free: unit tests load it without a bootstrap.
- The coupon code is a WooCommerce coupon code, and WooCommerce lowercases codes (`wc_format_coupon_code()`), so the alphabet is lowercase only.
- Lint only changed files: `composer lint -- <files>`. Expected: no error or warning in those files. A whole-repo run exits 1 on `dev/edd` too ([known issue](../known-issues.md)).
- Integration tests run on the Local site with `YAYLOYALTY_TEST_SOCKET` exported and the plugin active; read the output, because exit 0 alone proves nothing ([known issue](../known-issues.md)).
- No upgrade migration for data shape: there are no live users. Reward rows written before the column existed keep a NULL code; dev and test databases get codes by reseeding (amended 2026-10-29: the planned backfill was removed at the user's request).

## Review focus

- **Ledger keys after a code change.** The slider row used to be recognised by its fixed code. If `qualifier_for()` picks the wrong qualifier, a cancel restores under a new key and a repeated cancel restores again. Covered by the accepted Reversal test in Task 2.
- **A merchant coupon in our shape.** A real coupon named `yayloyalty-` plus 8 lowercase alphanumerics is treated as a loyalty code and removed from the cart by `sync_cart()`. Generation avoids existing coupon codes, but a merchant can create a coupon later. Accepted risk; the namespace is documented in `docs/architecture.md` (Task 4). Check in Task 2 Step 9.
- **Hot path.** `virtual_coupon_data()` runs for every coupon WooCommerce loads. A code that fails `is_loyalty_code()` must return before any query, and a loyalty code is read once per request. Check in Task 2 Step 9.
- **A deleted reward's code on a cart.** A catalog reward with no claims can be deleted while it sits in a cart. Its code must still get a `fixed_cart` coupon at 0 (no WooCommerce notice naming the code) and be dropped by the next `sync_cart()`. Check in Task 2 Step 9.
- **Existing rows with no code.** The Local site and branchpress snapshots already have reward rows. With no backfill, a row written before the column existed has no code, so a built-in without one never applies the slider until that database is reseeded. Accepted: there are no live users. Check in Task 1 Step 6.

---

### Task 1: Store a generated code on every reward

The engine still uses the old codes after this task. This task only adds the column, fills it, and exposes it.

**Files:**
- Modify: `includes/Database/Schema/RewardsTable.php`
- Modify: `includes/Models/Reward.php`
- Modify: `includes/Repositories/RewardRepository.php`
- Modify: `includes/Database/Installer.php`
- Modify: `includes/Controllers/RewardRestController.php`
- Modify: `includes/Engine/Points/Context.php`

**Interfaces:**
- `Points\Context::COUPON_CODE_PREFIX = 'yayloyalty-'` and `Points\Context::COUPON_CODE_LENGTH = 8`, public constants and the format's only definition. Added beside the old constants, which Task 2 removes.
- `rewards.coupon_code varchar(32) DEFAULT NULL`, after `slug`, plus `UNIQUE KEY uk_coupon_code (coupon_code)`. NULL only on rows written before the column existed. Several NULLs are allowed by the unique key.
- `Reward`: constructor parameter `?string $coupon_code = null`, last, after `$archived_at`. `get_coupon_code(): string`, '' when unassigned. `set_coupon_code( string $code ): void`, called only by `RewardRepository::insert()`. `to_row()` writes `coupon_code` (NULL for ''), with a matching `%s` in `get_format()`. `to_array()` includes it. `immutable_columns()` adds `coupon_code`, so no update can change it.
- `RewardRepository::find_by_coupon_code( string $code ): ?Reward`, using `find_one_by( 'coupon_code', … )`.
- `RewardRepository::insert( AbstractModel $model ): int` overrides the parent: when the Reward's `get_coupon_code()` is '', set `unique_coupon_code()` first, then `parent::insert()`.
- `RewardRepository::unique_coupon_code(): string` (private): `COUPON_CODE_PREFIX` plus `COUPON_CODE_LENGTH` characters drawn with `random_int()` from `abcdefghijklmnopqrstuvwxyz0123456789`. It retries, up to 5 attempts, while `find_by_coupon_code()` finds the candidate or `wc_get_coupon_id_by_code()` (only when the function exists) returns a non-zero id. After the last attempt it returns the last candidate, and the unique key makes the insert throw `QueryException`.
- REST item schema: `coupon_code`, `type` string, `context` `[ 'view' ]`, `readonly` true, described as "The reward's coupon code, set once on create."

**Steps:**
- [ ] **Step 1:** Add the two constants to `Points\Context`.
- [ ] **Step 2:** Add the column and the unique key to `RewardsTable::definition()`, and add them to `expected_columns()` and `expected_indexes()`.
- [ ] **Step 3:** Add `coupon_code` to `Reward` as the Interfaces block describes, including `from_row()`.
- [ ] **Step 4:** Add `find_by_coupon_code()`, the `insert()` override, and `unique_coupon_code()` to `RewardRepository`. No backfill: rows that predate the column keep a NULL code.
- [ ] **Step 5:** Add `coupon_code` to `RewardRestController::define_item_schema()`.
- [ ] **Step 6:** Verify the install on the Local site (memory: wp-cli on the Local site). Run `wp eval '\YayLoyalty\Database\Installer::install(); global $wpdb; print_r( $wpdb->get_results( "SELECT id, slug, coupon_code FROM {$wpdb->prefix}yayloyalty_rewards" ) );'`. Expected: no error, the column and `uk_coupon_code` exist, and every row inserted after the change has a distinct `coupon_code` matching `^yayloyalty-[a-z0-9]{8}$`. Run it again: the codes are unchanged.
- [ ] **Step 7:** Verify create and update in wp-admin → Rewards (Pro). Create a fixed-coupon reward, then edit its name and save. Expected, in the network tab: the create response has `coupon_code` in the format, and the update response returns the same code. A REST `PATCH` that sends `coupon_code` leaves it unchanged.
- [ ] **Step 8:** Run `composer lint -- includes/Database/Schema/RewardsTable.php includes/Models/Reward.php includes/Repositories/RewardRepository.php includes/Database/Installer.php includes/Controllers/RewardRestController.php includes/Engine/Points/Context.php`. Expected: no error or warning.
- [ ] **Step 9:** Run `composer test:integration`. Expected: the same result as on `dev/edd`. Every test still uses the old codes, which the engine still uses.
- [ ] **Step 10:** Commit the six files: `feat(rewards): store a generated coupon code on every reward`.

---

### Task 2: Use the stored code as the reward's coupon

One task, because recognition, the cart, the commit and the reversal must switch together. Between any two of these steps, carts or commits would break.

**Files:**
- Modify: `includes/Engine/Points/Context.php`
- Modify: `includes/Engine/Redemption/CouponProvider.php`, `RewardCoupons.php`, `RedemptionService.php`, `CommitWriter.php`, `RedemptionDraft.php`, `Reversal.php`, `Adapters/StoreApiAdapter.php`
- Modify: `includes/Engine/Rewards/ContextFactory.php`
- Modify: `includes/Engine/Storefront/RedeemCard.php`
- Modify: `includes/Pro/Services/DiscountRewardHandler.php`
- Modify: `includes/Models/Redemption.php`, `includes/Engine/Rewards/Handlers/FlexibleDiscountHandler.php` (docblocks only)
- Modify: `tests/integration/IntegrationTestCase.php`, `tests/integration/Engine/Redemption/CommitWriterTest.php`, `ReversalTest.php`, `ClassicCheckoutAdapterTest.php`, `tests/unit/Engine/Points/PointsEngineEvaluateTest.php`, `apps/e2e/specs/reward-redemptions.spec.ts`
- Create: `tests/unit/Engine/Points/ContextTest.php`

**Interfaces:**
- `Points\Context::is_loyalty_code( string $code ): bool` is true when `$code` matches `/^yayloyalty-[a-z0-9]{8}$/D`, built from `COUPON_CODE_PREFIX` and `COUPON_CODE_LENGTH`. `REDEEM_COUPON_CODE` and `REWARD_COUPON_PREFIX` are removed.
- `CouponProvider`: `CODE`, `REWARD_PREFIX`, `reward_code()`, `reward_id_of()` and `applied_reward_ids()` are removed. Every hook resolves a code through `RedemptionService::reward_for_code()`, and each branch keeps its current behaviour:
  - `virtual_coupon_data()`: return `$data` unless `is_loyalty_code()`. For a slider reward, the current slider array. For any other reward, `coupon_data_for()`. When the reward is missing or has no handler, the zero `fixed_cart` data.
  - `sort_weight()`: the slider gets `PHP_INT_MAX`, a reward `weight_of()`, anything else `$sort`.
  - `label()`, `on_removed()` (the slider clears points, a reward removes its id), `wanted_slider()` (returns `[ $this->service->slider_code() ]`), and `wanted_rewards()` (uses `$row['reward']->get_coupon_code()`).
- `RedemptionService::reward_for_code( string $code ): ?Reward`: null without a query when `! is_loyalty_code( $code )`. Otherwise `RewardRepository::find_by_coupon_code()`, cached per code for the request (null cached too).
- `RedemptionService::is_slider( ?Reward $reward ): bool` (static): true for a reward of type `RewardType::FLEXIBLE_DISCOUNT`.
- `RedemptionService::slider_code(): string`: `flexible_reward()`'s code, '' when that reward is missing.
- `RedemptionService::applied_rewards( \WC_Cart $cart ): array<string, Reward>`: the cart's loyalty codes that resolve to a catalog (non-slider) reward, as code ⇒ reward, in cart order. It replaces `applied_reward_ids()` in `applied_reward_costs()` and `StoreApiAdapter::applied_rewards()`.
- `RedemptionService::commit()`: `$has_slider` means `slider_code()` is non-empty and among the order's codes. `$reward_codes` is every loyalty code on the order except the slider's.
- `RewardCoupons::coupon( string $code, array $props )`, `probe( string $code, array $props, \WC_Cart $cart )`, `capped( string $code, array $props, \WC_Cart $cart )`: the reward id parameter becomes the coupon code. Callers pass `$reward->get_coupon_code()` (`RedemptionService::coupon_data_for()`, `DiscountRewardHandler`).
- `CommitWriter::existing_rows( int $order_id, array $codes )` keeps its signature and matches the order's rows (`RedemptionRepository::for_order()`, one read) to `$codes` by their stored `coupon_code`, first row per code. (Amended during implementation: looking rows up by a resolved reward's id would miss the row of a reward that is no longer offered, which `offered_rewards_of()` maps to null. A repeat commit would then strip the coupon lines from an order that was already committed. Rows store their code, so the code finds them either way.) `redemption_from()` stores `$quote->get_reward()->get_coupon_code()` as `coupon_code`.
- `Rewards\ContextFactory::points_discount_of( \WC_Order $order, Reward $reward ): float` is `line_discount_of( $order, $reward->get_coupon_code() )`. Callers: `from_order()` and `CommitWriter::requote_slider()`, which already hold the flexible reward.
- `RedemptionDraft::qualifier_for( Redemption $row, ?Reward $reward ): string`: `RewardType::FLEXIBLE_DISCOUNT` when `RedemptionService::is_slider( $reward )`, else `reward_qualifier( $row->get_reward_id() )`. A null reward is never the slider: the built-in cannot be deleted, and a reward with redemption rows cannot be deleted either. `Reversal::draft()` becomes an instance method that passes `$this->rewards->find_reward( $row->get_reward_id() )`.
- `RedeemCard`'s `yayloyaltyRedeemBlocks.code`: the flexible reward's `get_coupon_code()`, '' when there is none.
- `IntegrationTestCase::order_with_points_discount( int $user_id, float $subtotal, float $discount, string $code )`: the coupon line's code becomes a parameter. Every caller passes the seeded reward's `get_coupon_code()`.

**Steps:**
- [ ] **Step 1:** Write `tests/unit/Engine/Points/ContextTest.php` (namespace `YayLoyalty\Tests\Unit\Engine\Points`, extends `PHPUnit\Framework\TestCase`):
  ```php
  public function test_a_generated_code_is_a_loyalty_code(): void {
      $this->assertTrue( Context::is_loyalty_code( 'yayloyalty-ab12cd34' ) );
  }

  public function test_other_codes_are_not_loyalty_codes(): void {
      foreach ( [ 'summer10', 'yayloyalty-redeem', 'yayloyalty-reward-5', 'yayloyalty-AB12CD34', 'yayloyalty-ab12cd345', 'xyayloyalty-ab12cd34' ] as $code ) {
          $this->assertFalse( Context::is_loyalty_code( $code ), $code );
      }
  }
  ```
  Run `composer test -- --filter ContextTest`. Expected: both fail under today's rule. The first fails because the generated shape is not recognised, and the second fails on `yayloyalty-redeem`.
- [ ] **Step 2:** Change `Points\Context` as the Interfaces block describes. Run `composer test -- --filter ContextTest`. Expected: 2 tests pass.
- [ ] **Step 3:** Change `CouponProvider`, `RewardCoupons`, `RedemptionService`, `DiscountRewardHandler`, `StoreApiAdapter` and `RedeemCard` as the Interfaces block describes. Update the docblocks that name the old codes (`CouponProvider`, `RewardCoupons`, `Reversal`, `Redemption`, `FlexibleDiscountHandler`, `RedemptionService::reward_for_code()`, `Context::is_loyalty_code()`).
- [ ] **Step 4:** Change `CommitWriter`, `Rewards\ContextFactory`, `RedemptionDraft` and `Reversal` as the Interfaces block describes.
- [ ] **Step 5:** Run `git grep -nE "REDEEM_COUPON_CODE|REWARD_COUPON_PREFIX|reward_id_of|reward_code\(|applied_reward_ids|CouponProvider::CODE|REWARD_PREFIX|yayloyalty-reward-'|yayloyalty-reward-\{id\}" -- includes tests apps assets`. Expected: no output. Then run `git grep -n "'yayloyalty-redeem'" -- includes tests`. Expected: only `StoreApiAdapter::CALLBACK` and `RedeemCard::NONCE_ACTION`, which are not coupon codes.
- [ ] **Step 6:** Update the existing tests to the stored codes:
  - `IntegrationTestCase::order_with_points_discount()`: the `$code` parameter.
  - `CommitWriterTest`: the `'yayloyalty-reward-' . $x->get_id()` keys become `$x->get_coupon_code()`, and `'yayloyalty-redeem'` becomes the seeded reward's code, assertions included.
  - `ReversalTest`: the same change.
  - `ClassicCheckoutAdapterTest`: `assertNotContains` on the seeded reward's code.
  - `PointsEngineEvaluateTest`: `'yayloyalty-ab12cd34'` and `'yayloyalty-ef56gh78'` for the two reward codes.
  - `reward-redemptions.spec.ts`: `not.toContainText('yayloyalty-')`.
- [ ] **Step 7:** Add to `ReversalTest`:
  ```php
  public function test_a_cancel_of_a_mixed_order_restores_each_row_once_under_its_own_key(): void {
      $this->ledger_service()->earn( $this->earn_draft( 7, 100, 9850 ) );
      $flexible = $this->seeded_reward();
      $a        = $this->pro_reward( RewardType::FIXED_COUPON, 500, [ 'amount' => 5 ] );
      $order    = $this->order_with_reward_lines( 7, 100.0, [ $a->get_coupon_code() => 5.0, $flexible->get_coupon_code() => 5.0 ] );
      $order->set_total( 90.0 );
      $order->save();
      $this->assertSame( Outcome::REDEEMED, $this->commit_writer()->commit_all( $order, [ $a->get_coupon_code() => $a ], $flexible )->get_status() );
      $this->assertSame( [ $a->get_coupon_code(), $flexible->get_coupon_code() ], array_column( $this->redemption_rows( 7 ), 'coupon_code' ) );

      $this->reversal()->cancelled( $order );
      $this->reversal()->cancelled( $order );

      $this->assertSame( [ 9850, -500, -500, 500, 500 ], $this->ledger_points( 7 ) );
      $this->assertSame(
          [
              sprintf( 'refund_redeem:7:order:%d:reward_%d', $order->get_id(), $a->get_id() ),
              sprintf( 'refund_redeem:7:order:%d:flexible_discount', $order->get_id() ),
          ],
          array_column( array_slice( $this->ledger_rows( 7 ), 3 ), 'idempotency_key' )
      );
      $this->assertSame( 9850, (int) $this->customer_row( 7 )['balance'] );
  }
  ```
  Expected values follow `CommitWriterTest::test_a_stacked_order_writes_every_row_in_one_commit`: the reward debit first, then the slider's 500 points for 5.00.
- [ ] **Step 8:** Run `composer test` and then `composer test:integration` (socket exported, plugin active, Task 1's install already run). Expected: both suites pass, including the two new tests and every updated one. Read the PHPUnit summary line; exit 0 alone is not enough.
- [ ] **Step 9:** Browser check on the Local site or a branchpress site (memory: storefront checks, branchpress testing), as a shopper with points, on Pro:
  - Tick a fixed reward and the points discount on the Cart block. Expected: the totals show the reward's name and "Points discount", never a `yayloyalty-` code. The Store API response's `extensions.yayloyalty.rewards[].code` equals the reward's `coupon_code`.
  - Place the order. Expected: the order's coupon lines are the two rewards' stored codes. `SELECT coupon_code FROM wp_yayloyalty_redemptions WHERE order_id = <id>` returns the same two codes.
  - Cancel the order, then set it to Cancelled again. Expected: the points come back once. The customer's history shows one restore per row.
  - On a fresh cart, type the fixed reward's code into the classic cart's coupon field. Expected: today's behaviour, with no discount once the page reloads its totals (R5).
  - Create a merchant coupon `summer10` and apply it beside a reward. Expected: it stays on the cart and discounts. Query Monitor, if installed, shows no `yayloyalty_rewards` query for `summer10`.
  - Delete a never-claimed catalog reward while it sits in another cart, then reload that cart. Expected: no WooCommerce notice names a code, and the reward line is gone.
- [ ] **Step 10:** Run `composer lint` on every PHP file changed in this task. Expected: no error or warning.
- [ ] **Step 11:** Commit every file in Files: `feat(redemption): spend each reward through its stored coupon code`.

---

### Task 3: Show the code in the admin

**Files:**
- Modify: `apps/admin/src/lib/schema/reward.schema.ts`
- Modify: `apps/admin/src/pro/rewards/editor/RewardEditor.tsx`, `RewardEditorSections.tsx`
- Modify: `apps/admin/src/pages/rewards/BuiltInRewardEditor.tsx`

**Interfaces:**
- `rewardSchema.coupon_code: z.string()`, with the comment "Set once when the reward is created (RewardRepository::insert()); never changes."
- `RewardEditorSections({ type, couponCode }: { type: RewardType; couponCode?: string })`. When `couponCode` is set (the edit page), the Basics section ends with a read-only `Input` labelled "Coupon code", with the description "Orders that use this reward show this code on their coupon lines. It never changes." On the create page `couponCode` is unset, so the field is absent. `RewardEditorLayout` passes `reward?.coupon_code`.
- `BuiltInRewardCard`: the same read-only field, as a `ConfigField` after Name, with the same label and description.

**Steps:**
- [ ] **Step 1:** Make the three changes in Interfaces.
- [ ] **Step 2:** Run `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`. Expected: no errors.
- [ ] **Step 3:** Run `pnpm --filter @yayloyalty/admin-page exec eslint <the four files>` and `pnpm --filter @yayloyalty/admin-page exec prettier --check <the four files>`. Expected: no new ESLint error or warning compared with `dev/edd`, and Prettier clean ([known issue](../known-issues.md)).
- [ ] **Step 4:** Browser check with `./scripts/run.sh dev pro`, in wp-admin → Rewards (memory: browser check of the admin SPA). Expected:
  - The built-in card and an existing catalog reward's editor show the same code as the REST response, and the field cannot be typed into.
  - The create page has no Coupon code field.
  - Saving any other field leaves the code unchanged.
- [ ] **Step 5:** Run `./scripts/run.sh build lite` and open the built-in card on the Lite build. Expected: the field shows there too, and the build validates.
- [ ] **Step 6:** Commit the four files: `feat(admin): show each reward's coupon code`.

---

### Task 4: Record the change

**Files:**
- Create: `docs/decisions/2026-10-28-stack-points-discount-and-rewards-with-stored-codes.md`
- Modify: `docs/decisions/2026-10-22-stack-points-discount-and-rewards.md` (Status line only)
- Modify: `docs/architecture.md`

**Steps:**
- [ ] **Step 1:** Write the new record in the [decision-record practice](../decisions/2026-10-04-decision-record-practice.md)'s format. It restates every decision of the 2026-10-22 record that is still in force, with the code rule changed:
  - Each reward's virtual coupon code is its stored `coupon_code`, `yayloyalty-` plus 8 lowercase alphanumerics, set once on create.
  - Loyalty codes are recognised by that shape.
  - The slider is the `flexible_discount` reward.

  Context gives the reasons: one stable code per reward that the merchant can match to orders, and no `shop_coupon` post. It lists the options turned down: real `shop_coupon` posts, and a code per redemption. Consequences name the reserved namespace and the per-request lookup. Status: `proposed`, for the user to accept in the PR.
- [ ] **Step 2:** Set the 2026-10-22 record's Status to `superseded by` the new record.
- [ ] **Step 3:** In `docs/architecture.md`, the two redemption bullets that name `yayloyalty-redeem` and `yayloyalty-reward-{id}`: name the stored code instead, and state that codes matching `yayloyalty-` plus 8 lowercase alphanumerics are reserved for loyalty coupons.
- [ ] **Step 4:** Run `git grep -nE "yayloyalty-reward-\{id\}|virtual coupon .yayloyalty-redeem" -- docs/architecture.md docs/conventions.md`. Expected: no output. Specs, plans and superseded records stay as history.
- [ ] **Step 5:** Commit the three files: `docs(redemption): record stored reward coupon codes`.

---

### Final verification

- [ ] Run `pnpm --filter @yayloyalty/e2e env:start` after `./scripts/run.sh build pro`, reinstalling the schema on a reused wp-env (memory: wp-env shopper testing). Then run `pnpm --filter @yayloyalty/e2e test -- reward-redemptions`. Expected: the spec passes, and no discount row contains `yayloyalty-`.
- [ ] End to end (R1–R6): create a catalog reward in wp-admin, note its code in the editor, redeem it with the points discount as a shopper, and open the order. Expected: the order's two coupon lines are exactly the code shown in the editor and the built-in card's code, and both redemption rows store them.
