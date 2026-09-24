# Redemption scout — read-only inventory before the zone split

> Snapshot of 2026-09-22, branch `dev/edd` @ `6c3b6c4`, taken **before** the three-zone scaffold landed the same day. §1–§9 describe the pre-scaffold state; the current shape is in `includes/Engine/Redemption/README.md`. §12 recommendations were executed; §13.1 and §13.3 are resolved by the scaffold.

**Headline finding, stated before the sections:** the premise of the audit ("we implemented a points redemption feature at checkout") does not match this repository. There is **no checkout redemption code on any branch** (`git grep` over every local and `origin/*` ref for `shop_coupon`, `woocommerce_get_shop_coupon_data`, `WC()->session`, `StoreApi`, `woocommerce_store_api`, `add_fee`, `RedemptionRepository` hits only comments and the reward-engine README). What exists is: a `rewards` definition table, a pure `RewardEngine` scaffold that always denies, a drawer REST endpoint that always answers empty, and the spec in `docs/data-model.md`. Sections 3–9 therefore mostly report "none" — that is the finding, not an omission. Sections 10 and 13 carry the useful content for the restructure.

## 1. TREE

Status: DONE = behaviour complete for its stated scope; PARTIAL = real code, missing a stated piece; STUB = signatures/fixture only.

| Path | Class / module | Purpose | Status |
|---|---|---|---|
| includes/Engine/Rewards/RewardEngine.php | `RewardEngine` | `evaluate(Reward, Context)` → `Result`; registry lookup then handler quote; gates 1–3 are `// TODO` comments (L34–40) | STUB |
| includes/Engine/Rewards/Context.php | `Context` | Immutable claim facts: user, balance, points_requested, cart_subtotal, currency, at | DONE |
| includes/Engine/Rewards/Result.php | `Result` | `allowed(...)` / `denied(reason)` value object; carries points, value, currency, rate_snapshot | DONE |
| includes/Engine/Rewards/Handlers/RewardHandler.php | `RewardHandler` | Interface `type()`, `quote(Reward, Context): Result` | DONE |
| includes/Engine/Rewards/Handlers/Registry.php | `Registry` | type → handler; `with_lite_handlers()` fires `yayloyalty_register_reward_handlers` | DONE |
| includes/Engine/Rewards/Handlers/FlexibleDiscountHandler.php | `FlexibleDiscountHandler` | Lite's only type; `quote()` returns `denied(not_implemented)` (L27) | STUB |
| includes/Engine/Rewards/README.md | — | Engine contract + "Implementing this engine" order | DONE |
| includes/Models/Reward.php | `Reward` | Row model; `config_schema()`, `validate_config()`, `default_config()`, `cost_points_is_valid()`, `is_live_at()` | DONE |
| includes/Repositories/RewardRepository.php | `RewardRepository` | `find_reward`, `find_by_slug`, `points_per_currency_unit`, `paginate_filtered`, `seed_builtin` | DONE |
| includes/Database/Schema/RewardsTable.php | `RewardsTable` | DDL for `yayloyalty_rewards` | DONE |
| includes/Database/Installer.php L140–165 | `Installer::seed_builtin_reward()` | Seeds `cart-discount` (`flexible_discount`, `default_config`) | DONE |
| includes/Enums/RewardType.php | `RewardType` | `flexible_discount` + Pro's four via `yayloyalty_reward_types` | DONE |
| includes/Enums/RewardConditionType.php | `RewardConditionType` | Reward condition vocabulary incl. `redemption_count` (L39) | DONE |
| includes/Enums/LedgerType.php, ReferenceType.php | enums | `REDEEM`, `REFUND_REDEEM`; `ReferenceType::REDEMPTION` | DONE |
| includes/Engine/Points/Context.php L15 | `Context::REDEEM_COUPON_CODE` | `'yayloyalty-redeem'` — the earning engine's knowledge of the future coupon | DONE |
| includes/Engine/Points/ContextFactory.php L53–55, L165–177 | `ContextFactory` | Reads our coupon's discount off an order/cart into `loyalty_discount` | DONE |
| includes/Controllers/RedemptionsRestController.php | `RedemptionsRestController` | `GET /customers/{id}/redemptions`; real path = empty envelope (L13–17) | PARTIAL |
| includes/Controllers/Fixtures/CustomerDrawer/DrawerRedemptions.php | `DrawerRedemptions` | `WP_DEBUG` fixture rows for the drawer tab | DONE (dev-only) |
| includes/Pro/YayLoyaltyPro.php L67–68, L132 | `YayLoyaltyPro` | Adds Pro reward types + config whitelist keys | DONE |
| apps/admin/src/lib/schema/redemption.schema.ts | zod | Mirror of the controller's item schema | DONE |
| apps/admin/src/lib/api/customer-drawer.api.ts L59–67 | `getCustomerRedemptions` | Fetches the (empty) envelope | DONE |
| apps/admin/src/pages/customers/drawer/tabs/RedemptionsTab.tsx, RedemptionsTabRows.tsx, customer-drawer.redemption-rows.ts | React | Drawer tab; Pro-gated presentation | DONE |
| apps/admin/src/pages/rewards/RewardsPage.tsx | `RewardsPage` | `PagePlaceholder` only (7 lines) | STUB |
| apps/admin/src/lib/points-math/points-math.ts L64 | `pointsToCurrency` | TS twin of `PointsMath::points_to_currency` | DONE |
| tests/unit/Rewards/{RegistryTest,ResultTest,RewardEngineTest}.php | PHPUnit | 9 tests, all pass | DONE |

Not present anywhere: cart/checkout UI, Store API extension, coupon hook, session read/write, `redemptions` table, `RedemptionRepository`, `LedgerService::redeem()`, `Engine/Rewards/ContextFactory`, order-lifecycle reversal.

## 2. PUBLIC SURFACE

```php
// Engine/Rewards
RewardEngine::__construct( Handlers\Registry $registry )
RewardEngine::evaluate( Reward $reward, Context $ctx ): Result            // throws InvalidArgumentException on unknown type
Context::__construct( ?int $user_id, int $balance, ?int $points_requested, ?float $cart_subtotal, string $currency, string $at )
Context::get_user_id(): ?int; get_balance(): int; get_points_requested(): ?int; get_cart_subtotal(): ?float; get_currency(): string; get_at(): string
Result::allowed( Reward $reward, int $points, float $value, string $currency, float $rate_snapshot ): self
Result::denied( string $reason ): self
Result::is_allowed(): bool; get_reward(): ?Reward; get_points(): int; get_value(): float; get_currency(): ?string; get_rate_snapshot(): float; get_reason(): string
Result::REASON_NOT_LIVE | REASON_INSUFFICIENT_BALANCE | REASON_CONDITIONS_NOT_MET | REASON_NOT_IMPLEMENTED
interface RewardHandler { type(): string; quote( Reward $reward, Context $ctx ): Result; }
Registry::register( RewardHandler $h ): void; has( string $type ): bool; get( string $type ): RewardHandler
Registry::with_lite_handlers(): self                                       // do_action( 'yayloyalty_register_reward_handlers', $registry )
FlexibleDiscountHandler::type(): string; quote( Reward, Context ): Result

// Model / repository
Reward::__construct( ?int $id, string $name, string $slug, string $type, string $status, int $sort_order, ?int $cost_points, array $config, array $conditions, ?string $starts_at, ?string $ends_at, bool $is_builtin, ?int $created_by, ?int $updated_by, string $created_at, string $updated_at, ?string $archived_at = null )
Reward::config_schema(): array; config_whitelist(): array; config_is_valid( string $type, array $config ): bool; validate_config( string $type, array $config ): array; default_config( string $type ): array
Reward::conditions_are_valid( array ): bool; cost_points_is_valid( string $type, ?int $cost_points ): bool; is_live_at( string $at ): bool; display_status( ?string $at = null ): string
RewardRepository::find_reward( int $id ): ?Reward; find_by_slug( string $slug ): ?Reward; points_per_currency_unit(): float; paginate_filtered( array $args = [] ): array; seed_builtin( Reward $reward ): void

// REST
RedemptionsRestController::get_rest_base(): string; register_routes(): void; get_history( WP_REST_Request $request )
```

No constructor takes a session, a cart, a coupon, or a `LedgerService`. No class in the redemption domain has a dependency on `$wpdb` except `RewardRepository` (reads) and `seed_builtin()` (one insert).

## 3. ZONE VIOLATIONS

**Writes reachable from a cart render, cart update, coupon apply/remove, or checkout page load: NONE.** No code in the repo hooks any of those events. Exhaustive list of every database write anywhere near the redemption domain, with reachability:

| file:line | Write | Reachable from Zone 1? |
|---|---|---|
| includes/Repositories/RewardRepository.php:94 (`seed_builtin` → `$this->insert`) | INSERT one `rewards` row | **No.** Only via `Installer::install()` (L54 → L93 → L145), which runs on activation (`ActDeact.php:101`) or from `Installer::maybe_upgrade()` (`yayloyalty.php:117`, `plugins_loaded` 20). `maybe_upgrade()` returns early when `yayloyalty_db_version === YAYLOYALTY_VERSION` (Installer.php:71) and takes `yayloyalty_upgrading` lock (L75). A cart page load after a version bump can trigger it once; slug-guarded (L91) so never twice. Not a redemption write. |
| includes/Services/LedgerService.php:112–122 (`record()`: `ensure_row`, `lock_and_get`, `ledger->insert`, `apply_aggregates`) | ledger row + customers aggregates | **No.** `record()` is private; only `adjust()` (L47, REST `POST /customers/{id}/adjust`, cap `manage_points`) reaches it. `earn()` (L91) throws `RuntimeException('Not implemented')`. No `redeem()` exists. |
| includes/Repositories/CustomerRepository.php:57 `ensure_row`, :118 `apply_delta`, :177 `apply_pending_delta` (stub) | customers | **No.** Callers: `LedgerService::record()` only; `apply_pending_delta` has no caller. |
| Order meta (`_yayloyalty_points_redeemed`, `_yayloyalty_redeem_value`) | — | **Not written anywhere.** Named only in `docs/data-model.md:472`. |
| Options / transients | — | None in the redemption domain. `DashboardCache` transients are dashboard-only and read `RewardRepository::points_per_currency_unit()` (a read). |
| WC session (`yayloyalty_redeem_points`) | — | **Not read or written anywhere.** Named only in `docs/data-model.md:478`. |
| `redemptions` table | — | Does not exist (`Installer::schemas()` L31–36 lists four tables; none is redemptions). |

Conclusion for the restructure: Zone 1 is currently empty, so there is nothing to un-write. The risk is that the first implementation puts writes there; the spec already forbids it implicitly ("Redemption at checkout" is the only redemption write row in `data-model.md:542`).

## 4. WHERE THE DISCOUNT NUMBER LIVES

- **WC session:** nothing is stored. Spec (`data-model.md:478`) intends `yayloyalty_redeem_points` = **points** (an integer), not a money value. No code implements it.
- **Where the value is calculated:** nowhere yet. The only conversion primitive is `PointsMath::points_to_currency( int $points, $points_per_currency_unit ): ?float` (`includes/Utils/PointsMath.php:71`, 2 dp, null on rate ≤ 0) with its TS twin `pointsToCurrency` (`points-math.ts:64`) synced by `tests/fixtures/points-math-vectors.json`. `FlexibleDiscountHandler::quote()` is where the spec says the value must be computed (docblock L12–16: `value = points / points_per_currency_unit` rounded through `PointsMath`, `rate_snapshot = points_per_currency_unit`); its body is `denied(not_implemented)`. **Count of separate calculation sites: 0 in code; 1 designated.**
- **Rate source:** `RewardRepository::points_per_currency_unit()` (L51–55) reads `config.points_per_currency_unit` off the `cart-discount` row, 0.0 when missing. Consumers today are all read-side: `DashboardRepository:228`, `DashboardStats:41`, `CustomerRestController:240`, `CampaignPerformanceRestController:147`. None of them is a redemption path, but this is the one function a redemption quote must also use, or the admin's "worth" figures and the checkout discount will disagree.
- **Persisted or cached outside the order / redemptions row:** none in code. Spec-designated projections: order meta `_yayloyalty_points_redeemed`, `_yayloyalty_redeem_value` (`data-model.md:472`, "never read to compute a balance") and `redemptions.value/base_value/rate_snapshot` (`data-model.md:333–335`).
- **Recalculation on cart change:** there isn't one — there is no cart hook. The earning side already anticipates the coupon: `ContextFactory::from_cart()` (L165–177) reads `WC_Cart::get_coupon_discount_amount('yayloyalty-redeem')` into `Context::loyalty_discount` for the *earn* base add-back; this is a read of WooCommerce's own recomputed coupon amount, not a stored value, so it is not stale by construction.
- **Stored money values outside an order:** **none.** The fixture `DrawerRedemptions.php` carries literal `'value' => '12.00'` strings (L38, L47, …) but is `WP_DEBUG`-gated dev data, not persistence.

## 5. THE CLAMP

No clamp is enforced anywhere. Each limit exists only as a **stored config key with validation**, never as an evaluation:

| Limit | Where it lives | Enforced? |
|---|---|---|
| Balance vs cost | `RewardEngine.php:35–37` — `// TODO gate 2, balance: a balance below the cost denies REASON_INSUFFICIENT_BALANCE. The cost is the reward's cost_points for fixed-cost types and the context's points_requested for flexible_discount.` | No |
| `min_points` | `Reward::config_schema()` L214; default 100 (`default_config` L408); handler docblock L13 | No |
| `increment` (step) | `Reward::config_schema()` L226; default 100 (L411); handler docblock L14 | No |
| `max_points_per_order` | `Reward::config_schema()` L218; default null (L409); handler docblock L14 | No |
| `max_percent_of_subtotal` (cart ceiling) | `Reward::config_schema()` L222; default null (L410); handler docblock L14 | No |
| `max_total` (global cap) | `Reward::config_schema()`; `default_config()` L414 (`null`); `data-model.md:173` — "stored from 1.0 even though nothing does yet" | No |
| Rounding of the money value | `PointsMath::points_to_currency` L71–78 (`round(…, 2)`) | Exists as a primitive; not called by any redemption code |
| Liveness (`starts_at`/`ends_at`/status) | `Reward::is_live_at( string $at )` L179; `RewardEngine.php:34` `// TODO gate 1` | Model method exists; engine does not call it |

Duplicated clamps: none, because there are zero clamps. The design does split them across two places by intent — generic gates (liveness, balance, conditions) in `RewardEngine::evaluate()` and type-specific ones (`min_points`, `increment`, `max_*`) in `FlexibleDiscountHandler::quote()` — which is fine as long as the balance check is not repeated in the handler.

## 6. THE COUPON

No mechanism is implemented. No `add_filter('woocommerce_get_shop_coupon_data', …)`, no `shop_coupon` post creation, no `WC_Cart::add_fee`, no total manipulation, no `woocommerce_coupon_is_valid` — repo-wide, all branches.

What is fixed already:
- The virtual coupon code: `Context::REDEEM_COUPON_CODE = 'yayloyalty-redeem'` (`includes/Engine/Points/Context.php:15`), matching `data-model.md:336` ("`yayloyalty-redeem` (virtual) for the flexible discount"). The earning engine excludes it from `get_real_coupon_codes()` (L145–147) so `exclude_coupon_orders` does not punish redeemers.
- Catalog rewards (Pro) are specified as real `shop_coupon` posts (`data-model.md:129`, `:337` `coupon_id`), none generated yet (`project-roadmap.md:70`).
- `individual_use`: not set anywhere; not specified in docs.
- Ordering against other coupons: not controlled anywhere; not specified.
- Free shipping: `RewardType::FREE_SHIPPING` (`RewardType.php:19`) is a distinct Pro reward type with config `min_order, currency, coupon_expiry_days, max_total` (`data-model.md:168`); no expression in code. The flexible discount has no free-shipping component.

## 7. BLOCKS VS CLASSIC

- Store API registrations: **none.** `grep -r "woocommerce_store_api\|StoreApi\|ExtendSchema\|register_endpoint_data\|register_update_callback"` over `includes/` and `apps/` returns nothing.
- Classic checkout: **none.** No `woocommerce_cart_totals_*`, `woocommerce_review_order_*`, `woocommerce_checkout_*` hooks in the repo.
- Shared logic: n/a. The only storefront-adjacent code is `ContextFactory::from_cart()` / `from_product()` (`ContextFactory.php:111, 144`), designed for the *earn* preview (roadmap TODO 8, `project-roadmap.md:172`), and it reads WooCommerce, never renders.
- Spec position: `project-roadmap.md:188` lists "Checkout hook caller — apply reward, write ledger on valid result. Store API caller." as one line item, i.e. two callers are anticipated but no rule-sharing design exists.

## 8. COMMIT PATH

- Hook that creates the `redemptions` row: **none** (no table, no repository).
- Hook that creates the `redeem` ledger row: **none.** `LedgerService` exposes `adjust()` (L47) and the stub `earn()` (L91). `LedgerType::REDEEM` is only consumed by `apply_aggregates()` (L152–154: `lifetime_redeemed_delta = -$points`), so the aggregate math for a redeem row already exists and is reached by nothing.
- Transaction: `Transaction::run( callable )` (`includes/Database/Transaction.php:34`) wraps `record()` (L108). Would be reused.
- Locking read: `CustomerRepository::lock_and_get( int $user_id ): Customer` (L84, `SELECT … FOR UPDATE`), called at `LedgerService.php:115` after `ensure_row()` (L113). Would be reused.
- Re-validation before debit: none exists (nothing debits).
- Double-debit guard: none exists. The earn-side pattern is the single-column UNIQUE `idempotency_key` (`LedgerTable.php`; `IdempotencyKey::compose()` L33) with MySQL 1062 → `Outcome::duplicate` (`LedgerService.php:82–85` docblock). `IdempotencyKey::compose()` asserts `LedgerType::assert($type)` (accepts `redeem`) but its fifth segment is `CampaignTrigger::is_known( $trigger )` (L49) — a redeem row has no campaign trigger, so the composer as written **cannot produce a redeem key** without a change (see §13).

## 9. VALIDATION TIMING

**none.** No checkout-submission hook (`woocommerce_checkout_process`, `woocommerce_after_checkout_validation`, `woocommerce_store_api_checkout_order_processed`, `woocommerce_checkout_order_processed`, `woocommerce_checkout_create_order`) is registered anywhere in the plugin.

## 10. REUSABLE

Earning layer lives at `includes/Engine/Earning/` (scaffold, 2026-09-22) and `includes/Services/LedgerService.php`.

| What | Path | Signature | Note |
|---|---|---|---|
| Ledger writer (transaction, ensure/lock/insert/aggregates, post-commit events) | includes/Services/LedgerService.php:102 | `private record( LedgerEntry $draft ): LedgerEntry` | Private; `redeem()` would be a sibling of `earn()` (L91) delegating to it. Already handles `REDEEM` aggregates at L152–154. |
| Idempotent write entry point (pattern) | includes/Services/LedgerService.php:91 | `earn( LedgerEntry $draft ): Outcome` | Stub. The docblock (L75–89) is the contract to copy for `redeem()`: 1062 → `Outcome::duplicate`. |
| Idempotency key composer | includes/Engine/Earning/IdempotencyKey.php:33 | `static compose( string $type, int $user_id, string $reference_type, int $reference_id, string $trigger ): string` | Vocabulary-checked, ≤96 chars. Fifth segment is campaign-trigger-only (L49) — needs a redeem shape (`redeem:{user}:redemption:{id}:…` or `order`). |
| Outcome value object | includes/Engine/Earning/Outcome.php:36–63 | `awarded( LedgerEntry, Points\Result )`, `skipped( string $reason, ?Result )`, `duplicate( LedgerEntry )`; `get_status/get_entry/get_evaluation/get_reason` | `get_evaluation()` is typed `Engine\Points\Result`; a redeem outcome carries `Engine\Rewards\Result`. Either generalise or add a sibling. |
| Gate interface | includes/Engine/Earning/Gate.php:21 | `check( string $trigger, Context $ctx ): ?string` | Bound to `Engine\Points\Context`; the shape (null = pass, string = reason) is what a redemption gate chain should copy. |
| Program gate (opt-out, excluded roles) | includes/Engine/Earning/ProgramGate.php:24 | `check( string $trigger, Context $ctx ): ?string` | Stub. Same eligibility rules apply to spending; reuse once `Context` binding is resolved. |
| Transaction helper | includes/Database/Transaction.php:34 | `static run( callable $callback )` | Nesting-aware, foreign-transaction probe. Use as-is. |
| Locking read | includes/Repositories/CustomerRepository.php:84 | `lock_and_get( int $user_id ): Customer` | Use as-is; `ensure_row()` (L57) first. |
| Aggregate update | includes/Repositories/CustomerRepository.php:118 | `apply_delta( int $user_id, int $balance_delta, int $lifetime_earned_delta, int $lifetime_redeemed_delta, … )` | Already signed for redeem. |
| Duplicate detection | includes/Database/QueryException.php:53 | `is_duplicate_key(): bool` | Use as-is. |
| Points → money | includes/Utils/PointsMath.php:71 | `static points_to_currency( int $points, $points_per_currency_unit ): ?float` | The one conversion. TS twin + shared vectors. |
| Rate source | includes/Repositories/RewardRepository.php:51 | `points_per_currency_unit(): float` | Already the admin's rate; must be the checkout's too. |
| Reward evaluation | includes/Engine/Rewards/RewardEngine.php:31 | `evaluate( Reward, Context ): Result` | The pure quote; caller supplies `balance` (from `lock_and_get()` inside the commit, from a plain read in Zone 1). |
| Composition-root pattern | includes/Engine/Earning/EarningService.php:47 | `static build(): self` | Named construction site convention (`code-standards.md` §8 review rule) — a `RedemptionService::build()` would be the fourth. |
| Ledger draft constructor | includes/Models/LedgerEntry.php | `with_persisted( int $id, int $balance_after )`, `get_idempotency_key(): ?string` | Row model already carries the key column. |

## 11. TESTS

- Command: `composer test` (all, 152 cases, 27 incomplete by design) · `composer test -- --filter Rewards` (9 tests, 24 assertions, **OK** — run 2026-09-22).
- `tests/unit/Rewards/RewardEngineTest.php`: `test_lite_build_denies_not_implemented` (L31 — asserts the stub, to be replaced), `test_unregistered_type_throws` (L39), `test_returns_the_handlers_quote_unchanged` (L45).
- `tests/unit/Rewards/RegistryTest.php`: register/get (L38), unknown throws naming it (L48), Lite owns `flexible_discount` (L55), fires the Pro seam (L59).
- `tests/unit/Rewards/ResultTest.php`: `allowed` exposes the quote (L12), `denied` carries only the reason (L25).
- Empty / missing: no tests for `FlexibleDiscountHandler` rules, no gate tests, no `RewardRepository` tests (needs `$wpdb`), no SPA tests for the Redemptions tab (`project-roadmap.md:152`). No integration harness (`tests/integration/` does not exist).

## 12. DEAD OR MISPLACED

| Item | Recommendation | Why |
|---|---|---|
| `RewardEngineTest::test_lite_build_denies_not_implemented` (L31) | DELETE when handler is implemented | Asserts the stub; README L73 already says so. |
| `Result::REASON_NOT_IMPLEMENTED` (`Result.php:20`) + `FlexibleDiscountHandler::quote()` body (L27) | DELETE (constant) once no handler returns it | Scaffold marker only. |
| `Engine/Rewards/Context::$at` as a string with no factory | MOVE responsibility: add `Engine/Rewards/ContextFactory` (spec'd, README L75) so Zone 1 and Zone 2 build the same `Context` | Two hand-built contexts = two sources of `balance`/`cart_subtotal`. |
| `IdempotencyKey::compose()` trigger-only fifth segment (L49) | MOVE/GENERALISE into a key shape that admits `redeem` before the commit path is written | Otherwise a parallel composer appears. |
| `Outcome::get_evaluation(): ?Points\Result` (`Outcome.php:59`) | GENERALISE or add `Engine/Rewards/Outcome` | Avoid a second near-identical outcome class. |
| `Gate` / `ProgramGate` bound to `Engine\Points\Context` (`Gate.php:21`) | GENERALISE if opt-out/excluded-roles must gate spending; otherwise leave | Decide before duplicating `ProgramGate` for rewards. |
| `RewardRepository::points_per_currency_unit()` docblock L45–49 ("customer drawer's `worth` stat"), `RestAPI.php:57–58`, `CustomerRestController.php:34` | Stale comments (`worth` was removed, `CustomerRestController.php:237`) | Comment-only; fix when touching. |
| `DrawerRedemptions.php` (158 lines) | KEEP until the `redemptions` table exists, then DELETE with the `scenario` gate | Dev-only fixture by design (`code-standards.md:189`). |
| `docs/data-model.md:478` session key `yayloyalty_redeem_points` | Keep as spec, but note it stores **points**, never money | Names the Zone 1 contract. |

## 13. CONTRADICTIONS

1. **Directory name:** `docs/code-standards.md:139` says "Future engines follow the same shape: `Engine/Redemption/` …"; the engine lives at `includes/Engine/Rewards/` (and `system-architecture.md:420`, `project-roadmap.md:67` call it `Engine/Rewards/`).
2. **Roadmap "SHIPPED" vs reality:** `project-roadmap.md:67–71` marks Flexible discount / Fixed-percent coupon / Free product / Free shipping "✅ SHIPPED (schema)" while the same table's Details say "no checkout integration", "no coupon generation", "no logic". Status column and details disagree; `project-roadmap.md:139` says "No redemption" for the same feature.
3. **Idempotency key composer vs ledger vocabulary:** `IdempotencyKey.php:36` docblock says `$type` is "earn today; refund types when clawbacks land" and L49 requires a campaign trigger; `data-model.md:250` and `LedgerType::REDEEM` say redeem rows reference a `redemption` id with no trigger. The composer cannot key a redeem row; the data model implies it must (`data-model.md:532` "exactly one `redeem` row per redemption").
4. **`max_total`** is stored (`Reward::default_config()` L414 sets it `null`) and `data-model.md:173` admits "nothing enforces it yet" — spec and code agree the key is dead weight until a clamp exists; listed so the restructure does not treat its presence as enforcement.
5. **`show_on[]` / `refund_policy`** are seeded (`default_config()` L412–413) but no code reads either; `data-model.md:165` presents them as behaviour.
6. **`redemption_count` condition:** `RewardConditionType::REDEMPTION_COUNT` (L39) is a valid stored condition, and `data-model.md:201` says the engine "must evaluate `redemption_count` at claim time … `COUNT(*) FROM yayloyalty_redemptions`"; `RewardEngine.php:38–40` leaves gate 3 as a filter defaulting to `true`. A merchant can store a "once per customer" rule that nothing enforces.
7. **Session content:** `data-model.md:478` stores points in session; `Engine\Rewards\Context` takes `points_requested` **and** `cart_subtotal` **and** `balance` — the spec never says which of these Zone 1 may cache; only `points_requested` is safe to hold across requests.
8. **`worth` stat:** `RewardRepository.php:49` and `RestAPI.php:57` still describe `points_per_currency_unit()` as serving the drawer's `worth` stat; `CustomerRestController.php:237` and `codebase-summary.md` say `worth` was removed.
9. **README L5** says "reward definitions shipped … no redemption flow exists" — consistent with code; `project-roadmap.md:18` "rewards ✅ SHIPPED" is consistent only if read as schema-only.

## Unresolved questions

- Which context/session was the audit prompt written against? No branch or stash in this repository contains a checkout redemption implementation; if one exists elsewhere (another plugin, a fork, an uncommitted tree), point this scout at it.
- Should `IdempotencyKey` grow a redeem shape (`redeem:{user}:redemption:{id}:{reward_type}`?) or should redemptions key on `(user_id, order_id)` instead? The spec does not say.
