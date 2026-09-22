# Points engine scout — read-only inventory before the evaluate/award split

> Snapshot of 2026-09-22 **before** the `Engine/Earning/` scaffold landed. §3, §6 and §8 describe the pre-scaffold state; the current shape is in `includes/Engine/Earning/README.md`.

Scope notes (facts, not findings): `POINTS-ENGINE-PLAN.md` is not in the repo (root, `docs/`, `plans/`, git history — the engine commit `ec2ed12` cites "the plan" but never committed it; tests cite "Plan §6 cases N" to that missing file). Spec used instead: `includes/Engine/Points/README.md` (engine contract), `docs/data-model.md` (ledger/customers write rules), `docs/code-standards.md` §"The points engine is pure", `docs/project-roadmap.md` F01-4. There is no `src/`; PHP lives under `includes/`.

## 1. TREE

Status: DONE = behaviour complete for its stated scope; PARTIAL = real code, missing a stated piece; STUB = signatures/fixture only.

| Path | Class | Purpose | Status |
|---|---|---|---|
| includes/Engine/Points/PointsEngine.php | PointsEngine | `evaluate(trigger, Context, at)` → Result; pure calculation, writes nothing | DONE |
| includes/Engine/Points/Context.php | Context | Immutable event facts (user, occurred_at, lines, coupons, points_earned_today) | DONE |
| includes/Engine/Points/ContextFactory.php | ContextFactory | Builds Context from WC_Order / WC_Product / WC_Cart; only WooCommerce-touching engine class | DONE |
| includes/Engine/Points/ContextLineItem.php | ContextLineItem | One order line in order currency | DONE |
| includes/Engine/Points/Result.php | Result | Awarded points + campaign + ledger reference, or `none(reason)` | DONE |
| includes/Engine/Points/Breakdown.php | Breakdown | base/rate/raw/rounding behind a handler's points | DONE |
| includes/Engine/Points/BaseCalculator.php | BaseCalculator | Base-currency amount a purchase earns on (exclusions, tax, loyalty-coupon add-back) | DONE |
| includes/Engine/Points/CapPolicy.php | CapPolicy | Clamps to max_points_per_order and daily_cap | DONE |
| includes/Engine/Points/Triggers/TriggerHandler.php | TriggerHandler | Interface: trigger, is_eligible, compute_points, reference_type/id, source | DONE |
| includes/Engine/Points/Triggers/Registry.php | Registry | trigger → handler map; `with_lite_handlers()` fires Pro seam | DONE |
| includes/Engine/Points/Triggers/PurchaseHandler.php | PurchaseHandler | Rate/multiplier purchase points; min_subtotal / coupon gates | DONE |
| includes/Engine/Points/Triggers/SignupHandler.php | SignupHandler | Flat signup points, reference = user | DONE |
| includes/Engine/Points/Triggers/ReviewHandler.php | ReviewHandler | Flat review points, require_verified gate, reference = comment | DONE |
| includes/Engine/Rewards/RewardEngine.php | RewardEngine | `evaluate(Reward, Context)` → quote; scaffold | PARTIAL |
| includes/Engine/Rewards/Context.php | Context | Claim facts incl. balance and `at` | DONE |
| includes/Engine/Rewards/Result.php | Result | allowed/denied quote | DONE |
| includes/Engine/Rewards/Handlers/RewardHandler.php | RewardHandler | Interface: type, quote | DONE |
| includes/Engine/Rewards/Handlers/Registry.php | Registry | type → handler; Pro seam | DONE |
| includes/Engine/Rewards/Handlers/FlexibleDiscountHandler.php | FlexibleDiscountHandler | Points → cart discount quote | PARTIAL |
| includes/Engine/RestAPI.php | RestAPI | Constructs controllers + LedgerService; registers dashboard-flush hooks | DONE |
| includes/Engine/ActDeact.php | ActDeact | Activation/deactivation, WC-missing notice, HPOS compat | DONE |
| includes/Engine/Admin/Settings.php | Settings | Admin menu page + script enqueue | DONE |
| includes/Engine/Register/RegisterFacade.php | RegisterFacade | Picks dev/prod asset registrar | DONE |
| includes/Engine/Register/RegisterDev.php | RegisterDev | Vite dev-server assets | DONE |
| includes/Engine/Register/RegisterProd.php | RegisterProd | Built assets | DONE |
| includes/Engine/Register/ScriptName.php | ScriptName | Script handle constants | DONE |
| includes/Services/LedgerService.php | LedgerService | Sole ledger + customers writer; only `adjust()` exists | PARTIAL (no `earn()`) |
| includes/Services/Dashboard/AnomalyDetector.php | AnomalyDetector | Points-jump detector | DONE |
| includes/Services/Dashboard/DashboardCache.php | DashboardCache | Transient cache, flushed on ledger/campaign writes | DONE |
| includes/Services/Dashboard/DashboardMath.php | DashboardMath | Series zero-fill | DONE |
| includes/Services/Dashboard/DashboardNotices.php | DashboardNotices | Three notice detectors | DONE |
| includes/Services/Dashboard/DashboardStats.php | DashboardStats | Composes GET /dashboard payload | DONE |
| includes/Services/Dashboard/NoticeDismissals.php | NoticeDismissals | Per-user dismissals in user meta | DONE |
| includes/Services/Dashboard/PeriodWindow.php | PeriodWindow | Site-tz reporting window, injected "now" | DONE |
| includes/Repositories/AbstractRepository.php | AbstractRepository | find/insert/update/delete/paginate for one table | DONE |
| includes/Repositories/LedgerRepository.php | LedgerRepository | ledger SQL: find_by_reference, paginate, sum, latest | PARTIAL (no `points_earned_today`) |
| includes/Repositories/CustomerRepository.php | CustomerRepository | customers SQL: ensure_row, lock_and_get, apply_delta, admin list | DONE |
| includes/Repositories/CampaignRepository.php | CampaignRepository | campaigns SQL + wp_cache; `live_for_trigger($at)` | DONE |
| includes/Repositories/DashboardRepository.php | DashboardRepository | Read-only cross-table aggregates | DONE |
| includes/Repositories/RewardRepository.php | RewardRepository | rewards SQL | DONE |
| includes/Models/AbstractModel.php | AbstractModel | Typed row base, casts | DONE |
| includes/Models/Campaign.php | Campaign | Earning rule; `is_live_at($at)`, `conditions_match(user_id)`, config schema | DONE |
| includes/Models/CampaignCondition.php | CampaignCondition | One {type, operator, value} | DONE |
| includes/Models/CampaignConditionSet.php | CampaignConditionSet | Validated ANDed condition list | DONE |
| includes/Models/Customer.php | Customer | Member row (balance, pending_points, lifetime_*) | DONE |
| includes/Models/LedgerEntry.php | LedgerEntry | Immutable ledger row; `with_persisted()` | DONE |
| includes/Models/Reward.php | Reward | Reward definition | DONE |
| includes/Models/RewardCondition.php | RewardCondition | One reward condition | DONE |
| includes/Models/RewardConditionSet.php | RewardConditionSet | Validated reward conditions | DONE |
| includes/Enums/CampaignTrigger.php | CampaignTrigger | Trigger vocabulary; `all()` filtered by build | DONE |
| includes/Enums/CampaignConditionType.php | CampaignConditionType | Condition type vocabulary | DONE |
| includes/Enums/ConditionTypeMethods.php | ConditionTypeMethods | Trait: shared enum accessors | DONE |
| includes/Enums/CustomerFilters.php | CustomerFilters | Customers-list filter constants | DONE |
| includes/Enums/LedgerType.php | LedgerType | earn/redeem/refund_*/expire/adjust + aggregate rules | DONE |
| includes/Enums/LedgerSource.php | LedgerSource | Why a balance moved | DONE |
| includes/Enums/ReferenceType.php | ReferenceType | reference_id namespace | DONE |
| includes/Enums/RewardConditionType.php | RewardConditionType | Reward condition vocabulary | DONE |
| includes/Enums/RewardType.php | RewardType | Reward type vocabulary | DONE |
| includes/Enums/Status.php | Status | Definition status; `is_live_at(status, starts, ends, at)` | DONE |
| includes/Database/Transaction.php | Transaction | `run(callable)`: START TRANSACTION or savepoint, foreign-txn probe | DONE |
| includes/Database/Installer.php | Installer | dbDelta tables, seeds built-ins, install lock | DONE |
| includes/Database/InitYayLoyaltyCustomer.php | InitYayLoyaltyCustomer | One-time demo customers via `adjust()` + direct customers UPDATE | DONE (dev seed) |
| includes/Database/QueryException.php | QueryException | Write-failure exception | DONE |
| includes/Database/Schema/AbstractTableSchema.php | AbstractTableSchema | DDL base | DONE |
| includes/Database/Schema/CampaignsTable.php | CampaignsTable | campaigns DDL | DONE |
| includes/Database/Schema/CustomersTable.php | CustomersTable | customers DDL (has pending_points) | DONE |
| includes/Database/Schema/LedgerTable.php | LedgerTable | ledger DDL incl. `uniq_ledger_event` | DONE |
| includes/Database/Schema/RewardsTable.php | RewardsTable | rewards DDL | DONE |
| includes/Controllers/AbstractRestController.php | AbstractRestController | Route/schema/permission base | DONE |
| includes/Controllers/BaseRestController.php | BaseRestController | `exec_read`, `exec_write` (wraps in Transaction) | DONE |
| includes/Controllers/CallbackReturnedError.php | CallbackReturnedError | Carries WP_Error through Transaction | DONE |
| includes/Controllers/CustomerRestController.php | CustomerRestController | GET /customers, GET /customers/{id}, POST …/adjust | DONE |
| includes/Controllers/CustomerListFormatter.php | CustomerListFormatter | List wire shape | DONE |
| includes/Controllers/CustomerRowFilters.php | CustomerRowFilters | List filter → criteria | DONE |
| includes/Controllers/CustomerRowSort.php | CustomerRowSort | List sort keys | DONE |
| includes/Controllers/LedgerRestController.php | LedgerRestController | GET /customers/{id}/ledger | DONE |
| includes/Controllers/ActivityRestController.php | ActivityRestController | Empty envelope; no table | STUB |
| includes/Controllers/RedemptionsRestController.php | RedemptionsRestController | Empty envelope; no table | STUB |
| includes/Controllers/ReferralsRestController.php | ReferralsRestController | Empty envelope; no table | STUB |
| includes/Controllers/CampaignRestController.php | CampaignRestController | Campaigns CRUD; update validates, does not persist | PARTIAL |
| includes/Controllers/DashboardRestController.php | DashboardRestController | GET /dashboard | DONE |
| includes/Controllers/DashboardFixture.php | DashboardFixture | WP_DEBUG scenarios | STUB (fixture) |
| includes/Controllers/SettingsRestController.php | SettingsRestController | GET/POST /settings | DONE |
| includes/Controllers/Schemas/ActivityQueryArgs.php | ActivityQueryArgs | Activity query args | DONE |
| includes/Controllers/Schemas/CustomerItemSchema.php | CustomerItemSchema | Customer item schema | DONE |
| includes/Controllers/Schemas/LedgerQueryArgs.php | LedgerQueryArgs | Ledger query args | DONE |
| includes/Controllers/Fixtures/CustomerDrawer/CustomerDrawerFixture.php | CustomerDrawerFixture | Drawer scenario router | STUB (fixture) |
| includes/Controllers/Fixtures/CustomerDrawer/DrawerActivity.php | DrawerActivity | Fixture rows | STUB (fixture) |
| includes/Controllers/Fixtures/CustomerDrawer/DrawerExpiry.php | DrawerExpiry | Fixture rows | STUB (fixture) |
| includes/Controllers/Fixtures/CustomerDrawer/DrawerLedger.php | DrawerLedger | Fixture rows | STUB (fixture) |
| includes/Controllers/Fixtures/CustomerDrawer/DrawerLedgerGenerator.php | DrawerLedgerGenerator | Fixture rows | STUB (fixture) |
| includes/Controllers/Fixtures/CustomerDrawer/DrawerNegativeBalance.php | DrawerNegativeBalance | Fixture rows | STUB (fixture) |
| includes/Controllers/Fixtures/CustomerDrawer/DrawerProfiles.php | DrawerProfiles | Fixture rows | STUB (fixture) |
| includes/Controllers/Fixtures/CustomerDrawer/DrawerRedemptions.php | DrawerRedemptions | Fixture rows | STUB (fixture) |
| includes/Controllers/Fixtures/CustomerDrawer/DrawerReferrals.php | DrawerReferrals | Fixture rows | STUB (fixture) |
| includes/Pro/YayLoyaltyPro.php | YayLoyaltyPro | Pro boot: attaches 8 filters | DONE |
| includes/Pro/Services/ConditionsEvaluator.php | ConditionsEvaluator | Evaluates campaign conditions against customer facts (queries WC) | PARTIAL (order-facing types unsupported) |
| includes/Pro/Controllers/CampaignPreviewRestController.php | CampaignPreviewRestController | POST …/preview-conditions | DONE |
| includes/Pro/Controllers/CampaignPerformanceRestController.php | CampaignPerformanceRestController | GET …/performance | STUB (fixture) |
| includes/Pro/Controllers/CampaignPerformanceFixture.php | CampaignPerformanceFixture | Fixture data | STUB (fixture) |
| includes/Pro/Controllers/CustomerSegmentsRestController.php | CustomerSegmentsRestController | Saved segments | DONE |
| includes/Pro/Controllers/DashboardProRestController.php | DashboardProRestController | GET /dashboard/pro | DONE |
| includes/Pro/Helpers/CustomerSegmentsHelper.php | CustomerSegmentsHelper | Segment option storage | DONE |
| includes/Pro/Repositories/CampaignPointsRepository.php | CampaignPointsRepository | Read-only per-campaign ledger sums | DONE |
| includes/Access/Capabilities.php | Capabilities | Caps install/migrate | DONE |
| includes/Helpers/LocalizeHelper.php | LocalizeHelper | SPA payload | DONE |
| includes/Helpers/SettingsHelper.php | SettingsHelper | Settings option | DONE |
| includes/Utils/Dates.php | Dates | `now()`, `offset()`, `to_timestamp()`, `to_site_tz()` | DONE |
| includes/Utils/PointsMath.php | PointsMath | `round_points()` + ratio helpers | DONE |
| includes/Utils/Json.php | Json | JSON column codec | DONE |
| includes/Utils/Log.php | Log | WC logger wrapper | DONE |
| includes/Utils/ConditionValueRules.php | ConditionValueRules | Condition value validation | DONE |
| includes/Utils/SingletonTrait.php | SingletonTrait | Singleton | DONE |
| includes/Utils/Table.php | Table | Table name helper | DONE |
| includes/YayLoyalty.php | YayLoyalty | Boot; the one Pro seam | DONE |
| includes/I18n.php | I18n | Text domain | DONE |
| includes/Fallback.php | — | Autoload fallback notice | DONE |
| tests/bootstrap.php | — | No-WP bootstrap; hook shims | DONE |
| tests/unit/EngineTestCase.php | EngineTestCase | Campaign/Context fixture builders; stub CampaignRepository | DONE |
| tests/unit/PointsEngineTest.php | PointsEngineTest | 17 tests: liveness, selection, reasons, filters, signup/review | DONE |
| tests/unit/PurchaseHandlerTest.php | PurchaseHandlerTest | 15 tests: rate/multiplier, rounding, gates, caps | DONE |
| tests/unit/BaseCalculatorTest.php | BaseCalculatorTest | 14 tests: base amount | DONE |
| tests/unit/Rewards/RewardEngineTest.php | RewardEngineTest | 3 tests | DONE |
| tests/unit/Rewards/RegistryTest.php | RegistryTest | Registry | DONE |
| tests/unit/Rewards/ResultTest.php | ResultTest | Result | DONE |
| tests/unit/Dashboard/*Test.php (5 files) | AnomalyDetectorTest, DashboardMathTest, DashboardNoticesTest, DashboardStatsTest, PeriodWindowTest | Dashboard services | DONE |
| tests/unit/Models/CampaignValidationTest.php | CampaignValidationTest | Config/conditions validation | DONE |
| tests/unit/Controllers/CustomerDrawerFixtureTest.php, DrawerQueryArgsTest.php | — | Drawer helpers | DONE |
| tests/unit/Utils/PointsMathTest.php, PointsMathVectorsTest.php | — | Rounding + shared vectors | DONE |

## 2. PUBLIC SURFACE (the two-moment path; other classes are listed only in §1)

**PointsEngine** — ctor `(Registry $registry, CampaignRepository $campaigns, CapPolicy $caps)`
- `evaluate( string $trigger, Context $ctx, string $at ): Result`

**Context** — ctor `(?int $user_id, string $occurred_at, string $currency, float $base_rate, ContextLineItem[] $line_items, float $order_subtotal, float $order_discount_total, float $loyalty_discount, string[] $coupon_codes, string[] $user_roles, ?int $order_id = null, ?int $comment_id = null, int $points_earned_today = 0, array $extra = [])`
- `const REDEEM_COUPON_CODE`; getters for every ctor arg; `get_real_coupon_codes(): array`; `get_extra( string $key, $fallback = null )`

**ContextFactory** — static only
- `from_order( \WC_Order $order, int $points_earned_today = 0 ): Context`
- `from_product( \WC_Product $product, int $qty = 1, ?int $user_id = null ): Context`
- `from_cart( \WC_Cart $cart, ?int $user_id = null ): Context`

**Result** — private ctor
- `static awarded( int $points, Campaign $campaign, Breakdown $breakdown, string $reference_type, ?int $reference_id, string $source ): self`
- `static none( string $reason ): self`
- `is_awarded(): bool`, `get_points(): int`, `get_campaign(): ?Campaign`, `get_breakdown(): ?Breakdown`, `get_reference_type(): ?string`, `get_reference_id(): ?int`, `get_source(): ?string`, `get_reason(): string`

**Breakdown** — ctor `(int $points, float $base_amount, float $rate, float $raw_points, string $rounding, array $notes = [])`; `static flat( int $points, array $notes = [] )`; getters; `to_array()`

**BaseCalculator** — no deps; `compute( Campaign $campaign, Context $ctx ): float`

**CapPolicy** — no deps; `apply( int $points, Campaign $campaign, Context $ctx ): int`

**TriggerHandler** (interface) — `trigger(): string`, `is_eligible( Campaign, Context ): bool`, `compute_points( Campaign, Context ): Breakdown`, `reference_type(): string`, `reference_id( Context ): ?int`, `source(): string`

**Registry** — `register( TriggerHandler )`, `has( string )`, `get( string ): TriggerHandler` (throws), `static with_lite_handlers( CampaignRepository ): self`

**PurchaseHandler** — ctor `(CampaignRepository $campaigns, BaseCalculator $base_calculator)`; implements TriggerHandler
**SignupHandler**, **ReviewHandler** — no deps; implement TriggerHandler; ReviewHandler `const EXTRA_VERIFIED_PURCHASE`

**LedgerService** — ctor `(LedgerRepository $ledger, CustomerRepository $customers)`
- `adjust( int $user_id, int $points, string $note, int $actor_id ): LedgerEntry` — the only public write
- private: `record( LedgerEntry ): LedgerEntry`, `apply_aggregates( LedgerEntry )`, `dispatch_events()`

**LedgerRepository** — `find_by_reference( int $user_id, string $source, ?string $reference_type, ?int $reference_id ): ?LedgerEntry`, `paginate_for_user( int, array ): array`, `sum_points_for_user( int ): int`, `latest_for_user( int ): ?LedgerEntry`

**CustomerRepository** — `find_by_user_id( int ): ?Customer`, `ensure_row( int ): void`, `lock_and_get( int ): Customer`, `apply_delta( int $user_id, int $balance_delta, int $lifetime_earned_delta, int $lifetime_redeemed_delta, ?string $activity_at = null, ?string $expires_at = null ): void`, `paginate_list(...)`, `count_list(...)`

**CampaignRepository** (non-final) — `find_campaign( int )`, `find_by_slug( string )`, `all_non_archived(): Campaign[]`, `live_for_trigger( string $trigger, string $at ): Campaign[]`, `find_default_purchase(): ?Campaign`, `insert/update/delete` (flush cache), `flush_cache()`, `paginate_filtered( array )`, `seed_builtin( Campaign )`

**Campaign** — 16-arg ctor `(?int $id, string $name, string $slug, string $trigger, string $status, int $priority, array $config, array $conditions, ?string $starts_at, ?string $ends_at, bool $is_builtin, ?int $created_by, string $created_at, string $updated_at, ?int $updated_by, ?string $archived_at)`; `is_live_at( string $at ): bool`, `display_status( ?string $at = null )`, `conditions_match( int $user_id ): bool`, static config schema/validation, getters/setters

**LedgerEntry** — ctor `(?int $id, int $user_id, int $points, int $balance_after, string $type, string $source, ?string $reference_type = null, ?int $reference_id = null, ?int $campaign_id = null, ?int $redemption_id = null, ?int $order_id = null, ?string $note = null, ?int $actor_id = null, string $created_at = '')`; `with_persisted( int $id, int $balance_after ): self`; getters; `from_row/to_row/to_array`

**Transaction** — static `run( callable ): mixed`, `depth(): int`, `has_foreign_transaction(): bool`

**Status** — `static is_live_at( string $status, ?string $starts_at, ?string $ends_at, string $at ): bool`

**Dates** — `static now(): string`, `offset( int $seconds, ?string $from_utc = null )`, `to_timestamp( string )`, `to_site_tz( string, ?string )`, `is_valid( ?string )`

**RewardEngine** — ctor `(Rewards\Handlers\Registry)`; `evaluate( Reward $reward, Rewards\Context $ctx ): Rewards\Result`. **Rewards\Context** ctor `(?int $user_id, int $balance, ?int $points_requested, ?float $cart_subtotal, string $currency, string $at)`.

## 3. THE TWO MOMENTS TODAY

**Calculation entry point — `PointsEngine::evaluate()` (`includes/Engine/Points/PointsEngine.php:39`) is called from nowhere in production code.** Callers: `tests/unit/PointsEngineTest.php:32,123,168,185,203,216` only. `new PointsEngine` appears only at `tests/unit/PointsEngineTest.php:28`. `ContextFactory::from_*` has zero callers (`includes/`, `tests/`). `Registry::with_lite_handlers()` for triggers: tests only. The README's "Call site 1" (`includes/Engine/Points/README.md:39-57`) is prose, not code.

**Ledger / customers writers today:**
- `LedgerRepository::insert()` (inherited `AbstractRepository::insert`, `includes/Repositories/AbstractRepository.php:72`) — called only at `includes/Services/LedgerService.php:105`.
- `CustomerRepository::ensure_row()` :57, `lock_and_get()` :84, `apply_delta()` :118 — called only at `LedgerService.php:86,88,144`.
- `LedgerService::adjust()` (`LedgerService.php:44`) → private `record()` :75 — the single write path. Callers: `includes/Controllers/CustomerRestController.php:274` (POST /customers/{id}/adjust) and `includes/Database/InitYayLoyaltyCustomer.php:93` (one-time demo seed).
- Second customers writer outside LedgerService: `InitYayLoyaltyCustomer.php:96` `$customers->update( $user_id, build_row(...) )` writes `pending_points`, `lifetime_spend`, `last_activity_at`, `expires_at`, `reminder_sent_at`, `referral_code`, `birthday_*`, `joined_at` directly (dev seed, install-time only).
- No `earn()`, no `refund_*()`, no `redeem()`, no `expire()` exist on LedgerService.

**Same class / call stack?** No — they never meet. The only real chain is the manual adjust:
```
CustomerRestController::adjust_points  (CustomerRestController.php:244)
  └ [registered via exec_write → Transaction::run  BaseRestController.php:59]
  └ LedgerService::adjust                          (LedgerService.php:44)   Dates::now() stamped :63
      └ record()                                   (LedgerService.php:75)
          ├ Transaction::run (nested → savepoint)   :81
          ├ CustomerRepository::ensure_row          :86
          ├ CustomerRepository::lock_and_get        :88   SELECT … FOR UPDATE
          ├ LedgerRepository::find_by_reference     :91   idempotency pre-check (null for adjust)
          ├ LedgerRepository::insert                :105
          ├ apply_aggregates → CustomerRepository::apply_delta  :107 → :144
          └ dispatch_events → do_action('yayloyalty_ledger_recorded')  :117 → :169 (after commit)
```
The engine (`evaluate`) and the writer (`record`) share no class, no call stack, no interface. `Result` carries exactly what a future `earn()` needs (`points, campaign_id, source, reference_type, reference_id`) but nothing consumes it.

**Hooks registered (every `add_action`/`add_filter` in the codebase, by file):**
- `yayloyalty.php:27` (activation guard), `:88` (WC-missing branch), `:102` `admin_notices`, `:106` `before_woocommerce_init` (HPOS compat), `:114` `plugins_loaded` → `plugin_init`, `:117` `plugins_loaded` → `Installer::maybe_upgrade`, `:119` `plugins_loaded` → `Capabilities::migrate`, `:122` `wp_initialize_site`
- `includes/Engine/RestAPI.php:33` `rest_api_init`; `:34` `yayloyalty_ledger_recorded` → `DashboardCache::flush`; `:35` `yayloyalty_campaigns_changed` → `DashboardCache::flush`
- `includes/Engine/Admin/Settings.php:17,19` `admin_body_class`, `admin_enqueue_scripts`
- `includes/Engine/Register/RegisterProd.php:17`, `RegisterDev.php:20,22`, `RegisterFacade.php:20,21,22` — asset hooks (`init`, `admin_footer`, `script_loader_tag`, `pre_load_script_translations`)
- `includes/Database/InitYayLoyaltyCustomer.php:60` `woocommerce_email_enabled_customer_new_account` (temporary, removed at :66)
- `includes/Pro/YayLoyaltyPro.php:65-72` eight `yayloyalty_*` internal filters
- `includes/Fallback.php:4` `admin_notices`
- **Zero** `woocommerce_order_status_*`, `woocommerce_payment_complete`, `woocommerce_new_order`, `woocommerce_checkout_*`, `user_register`, `comment_post`, `transition_comment_status`, `wp_insert_comment` anywhere (grep over `includes/`, `yayloyalty.php`).

**Pending-points staging / release:** nowhere. `pending_points` exists as a column (`includes/Database/Schema/CustomersTable.php:26`), a model field (`includes/Models/Customer.php:20,125`), a list sort key (`CustomerRepository.php:21,193`), a wire field (`CustomerListFormatter.php:70`, `CustomerItemSchema.php:43`), and a seeded value (`InitYayLoyaltyCustomer.php:109,137-193`). No code increments, decrements, or releases it; `apply_delta()` does not touch it; `_yayloyalty_points_pending` order meta (data-model.md:471,537) is referenced by no PHP.

## 4. TIMESTAMP USAGE (fixtures under `Controllers/Fixtures/`, `DashboardFixture`, `CampaignPerformanceFixture` excluded — synthetic data)

| Location | Call | Meaning | Note |
|---|---|---|---|
| `Engine/Points/ContextFactory.php:46` | `$order->get_date_created()` | **event time** (only order-date read in the codebase) | correct |
| `Engine/Points/ContextFactory.php:50` | `gmdate('Y-m-d H:i:s', $created->getTimestamp())` | event time formatted UTC | correct |
| `Engine/Points/ContextFactory.php:50` | fallback `Dates::now()` when `get_date_created()` is null | **now used as event time** | conflation: an order without a creation date is scored under today's campaigns |
| `Engine/Points/ContextFactory.php:86` | `Dates::now()` in `from_product` | now, as preview `occurred_at` | by design (README:9) |
| `Engine/Points/ContextFactory.php:125` | `Dates::now()` in `from_cart` | now, as preview `occurred_at` | by design |
| `Engine/Points/PointsEngine.php:39,49` | `$at` param → `live_for_trigger($trigger, $at)` | event time (caller-supplied) | correct; nothing enforces `$at === $ctx->get_occurred_at()` |
| `Repositories/CampaignRepository.php:92-97` | `is_live_at($at)` | event time | correct |
| `Models/Campaign.php:174` / `Enums/Status.php:73-86` | string compare `starts_at > $at`, `ends_at <= $at` | event time | correct; lexical compare on MySQL strings |
| `Models/Campaign.php:187` | `display_status()` default `Dates::now()` | now (display) | fine |
| `Models/Campaign.php:538`, `Models/Reward.php:527` | `archived_at = Dates::now()` | write time | fine |
| `Services/LedgerService.php:63` | `created_at = Dates::now()` on adjust draft | **write time stamped as the row's only timestamp** | ledger has no `occurred_at`; a future late-processed earn would carry write time |
| `Services/LedgerService.php:142` | `activity_at = $entry->get_created_at()` | write time → `customers.last_activity_at` | data-model.md:217 says "timestamp of the last earn"; ambiguous for a late award (see §9) |
| `Repositories/CustomerRepository.php:60` | `Dates::now()` for `joined_at`/`updated_at` in `ensure_row` | write time | fine |
| `Repositories/CustomerRepository.php:138` | `Dates::now()` → `updated_at` in `apply_delta` | write time | fine |
| `Database/Installer.php:99,142` | `Dates::now()` seed `created_at`/`updated_at` | write time | fine |
| `Database/Installer.php:225,234,236` | `time()` install-lock TTL | now | fine |
| `Database/InitYayLoyaltyCustomer.php:52,111-117` | `Dates::now()`, `Dates::offset()` | now-relative demo dates | dev seed |
| `Controllers/CampaignRestController.php:151,241` | `Dates::now()` → row `created_at`/`updated_at` | write time | fine |
| `Controllers/CustomerRowFilters.php:136` | `Dates::now()`, `Dates::offset(days)` expiring-soon window | now | fine |
| `Controllers/DashboardRestController.php:128`, `Pro/Controllers/DashboardProRestController.php:84` | `new \DateTimeImmutable('now', wp_timezone())` | now (site tz) injected into `PeriodWindow` | fine |
| `Services/Dashboard/DashboardCache.php:41,55,56,85` | `Dates::now()`, `Dates::offset($ttl)` | now | fine |
| `Services/Dashboard/DashboardNotices.php:99,125-128,175` | derived from injected `$now_site`; `date_create_immutable($campaign->get_updated_at())` | now / row time | fine |
| `Controllers/Schemas/LedgerQueryArgs.php:88` | `DateTimeImmutable::createFromFormat('Y-m-d', $value)` | user input parse | fine |
| `Utils/Dates.php:17,22,24,28,37,53` | `gmdate()`, `time()`, `strtotime()`, `date_create()` | the clock primitives | all `now` callers funnel here |
| `Pro/Services/ConditionsEvaluator.php:326-336` | `wc_get_orders([... 'orderby' => 'date'])` | reads order history at **evaluation time (now)**, not at `$at` | `lifetime_spend`/`order_count`/purchase-history conditions are evaluated against the customer's state now, even for a late-processed order |
| `Engine/Rewards/Context.php:29-46` | `$at` ctor arg | claim time (caller) | README says claims evaluate at now by design |
| `current_time()` | — | **not used anywhere** | matches code-standards.md:44 |

## 5. PURITY VIOLATIONS (inside `includes/Engine/Points/`)

Spec (README:26, code-standards.md:325-327): no `$wpdb`, no WooCommerce globals, no current user, no `time()`; `CampaignRepository` is the one class that queries; `ContextFactory` the one that touches WooCommerce.

- `$wpdb`: none in `Engine/`. ✓
- `current_user_can` / `get_current_user_id` / `wp_get_current_user`: none in `Engine/` (README:65 shows `get_current_user_id()` at the *caller*, allowed). ✓
- WooCommerce/WP globals: only `ContextFactory.php:68,69,82,121` (`wc_get_price_*`, `get_woocommerce_currency`), `:145` `wp_get_post_terms`, `:158` `get_userdata` — the permitted class. ✓
- Wall clock: `ContextFactory.php:50` (fallback), `:86`, `:125` — permitted class, but `:50` is the one that leaks now into an award path.
- **By call stack, not by class:** `PointsEngine.php:63` → `Campaign::conditions_match()` (`Models/Campaign.php:196-212`) → `apply_filters('yayloyalty_campaign_conditions_evaluator')` → Pro `ConditionsEvaluator::evaluate()` which calls `get_userdata` (`:269`), `wc_get_customer_order_count` (`:288`), `wc_get_orders` (`:327`) and **`new CustomerRepository()->find_by_user_id()` (`:278`)** — a second querying class on the evaluation path, and one that reads the `customers` table the award will write. In Lite the seam returns `true`.
- `PointsEngine.php:63` casts `$ctx->get_user_id()` `null` → `0` for guests before `conditions_match()`.
- Filters fired inside the pure path: `yayloyalty_campaign_candidates` (:49), `yayloyalty_campaign_matches` (:63), `yayloyalty_earning_base_amount` (BaseCalculator:55), `yayloyalty_points_for_order` (PurchaseHandler:87), `yayloyalty_context_base_rate` (ContextFactory:171) — documented seams; any subscriber can break purity.

## 6. IDEMPOTENCY

Both, on the write side only; nothing on the evaluate side.

1. **Unique index** — `includes/Database/Schema/LedgerTable.php:40`:
   `UNIQUE KEY uniq_ledger_event (user_id,source,reference_type,reference_id)`; `expected_indexes()` :72 lists it so `Installer::verify()` can detect its loss.
2. **Pre-check inside the row lock** — `includes/Services/LedgerService.php:90-100`:
   ```php
   $existing = $this->ledger->find_by_reference( $user_id, $draft->get_source(), $draft->get_reference_type(), $draft->get_reference_id() );
   if ( null !== $existing ) { return $existing; }
   ```
   `find_by_reference()` (`LedgerRepository.php:27-48`) returns `null` immediately when `reference_type` or `reference_id` is null (:30), so `adjust()` rows never dedupe — intended (data-model.md:557).

Today the only writer is `adjust()` with NULL reference, so neither guard is ever exercised in production. A duplicate `earn` would hit the SELECT first; a race past the SELECT hits the index and surfaces as `QueryException` from `AbstractRepository::insert`, which `record()` does not catch (`Transaction::run` rolls back and rethrows).

## 7. TESTS

- Command: `composer test` (= `phpunit`, `phpunit.xml.dist` → `tests/unit`, bootstrap `tests/bootstrap.php`, no WordPress).
- Ran 2026-09-22: **OK (115 tests, 325 assertions)**, 0.03 s.
- Engine coverage: `BaseCalculatorTest` (14), `PurchaseHandlerTest` (15), `PointsEngineTest` (17) = 46 engine tests; stubbed `CampaignRepository` subclass, `Context` built by hand, `$at` fixed at `2026-06-15 12:00:00` (`EngineTestCase.php:21`).
- Empty / absent: no test touches `LedgerService`, `LedgerRepository`, `CustomerRepository`, `Transaction`, `ContextFactory`, `CampaignRepository::live_for_trigger` against SQL, or idempotency. No integration harness (README:84 "exercised manually via WP-CLI"). No test file is empty; none skipped/incomplete.
- Lint: `composer lint` (phpcs). Not re-run for this scout.

## 8. DEAD OR MISPLACED (recommend only)

| Item | Location | Recommendation |
|---|---|---|
| `Dates::now()` fallback as `occurred_at` | `ContextFactory.php:50` | MOVE the decision to the award caller (throw or pass explicit `$at`); the factory should not invent an event time |
| `$at` duplicated between `Context::occurred_at` and `evaluate()`'s third arg | `PointsEngine.php:39`, `Context.php:20` | DELETE one: `evaluate()` should read `$ctx->get_occurred_at()` — two sources of the same fact is where the conflation starts |
| `LedgerRepository::latest_for_user()` | `LedgerRepository.php:80` | DELETE ("Uncalled today" per its own docblock); `balance_after` comes from the locked customer row |
| `LedgerRepository::sum_points_for_user()` | `LedgerRepository.php:68` | Keep only if a rebuild tool is in scope; otherwise DELETE |
| `ReviewHandler` docblock "one_per_product the listener's" | `ReviewHandler.php:13` | MOVE that rule into whatever the award moment becomes; no listener exists to own it |
| `InitYayLoyaltyCustomer::build_row()` direct `customers` UPDATE | `InitYayLoyaltyCustomer.php:96` | MOVE behind whichever service owns `pending_points`/`lifetime_spend` after the split, or gate as dev-only; it is the second writer of `customers` |
| `LedgerService::$pending_events` name | `LedgerService.php:30` | Rename before "pending points" lands; the word will collide |
| README "Call site 1" code | `Engine/Points/README.md:39-60` | DELETE or rewrite once the award moment exists; it names `earn()`/`points_earned_today()` that do not exist |
| `Campaign::display_status()` default now | `Campaign.php:187` | Fine as display; keep out of the engine path |

## 9. CONTRADICTIONS (code vs docs, docs vs docs, ambiguities)

1. Brief names `POINTS-ENGINE-PLAN.md`; it is absent from the repo and git history. Tests reference "Plan §6 cases 1–35" that cannot be checked.
2. Brief says `src/`; code is under `includes/` (autoloader `yayloyalty.php`, `tests/bootstrap.php:24-30`).
3. README:84, README.md:119, code-standards.md:317 say "115 cases"; roadmap.md:138 says "46 engine unit tests" — both true, different scopes; roadmap counts engine only.
4. `Engine/Points/README.md:42-56` shows `$ledger->points_earned_today()` and `$ledger_service->earn()`; neither exists (`LedgerService.php`, `LedgerRepository.php`). Roadmap.md:166-167 acknowledges this.
5. data-model.md:531 "Only `LedgerService` inserts ledger rows or updates `customers` aggregates" vs `InitYayLoyaltyCustomer.php:96` updating `customers` directly (non-ledger columns, but same table).
6. data-model.md:213 defines `pending_points = SUM(_yayloyalty_points_pending)` and :537 "Order created → `customers.pending_points += N`"; no code implements either; roadmap's F01-4 list (:165-168) does not mention pending at all.
7. data-model.md:217 `last_activity_at` = "timestamp of the last earn or redeem" vs `LedgerService.php:142` using `created_at` (write time). For a late-processed order the two differ; docs do not say which wins.
8. Ledger has one timestamp (`created_at`, LedgerTable.php:38). Spec says liveness is scored at the order's time (data-model.md:77) but gives the ledger row no column for that time; dashboards bucket by `created_at` (`type_created` index :45). Ambiguous whether "issued last 30 days" means ordered or awarded.
9. `points_earned_today` (`Context.php:53`, `CapPolicy.php:29`, roadmap.md:167): "today" is undefined — site tz or UTC, and relative to `$at` or to now. README:71 only covers the preview case.
10. README:9 "`$at` is the event's UTC time … never `now()`" vs `ContextFactory.php:50` falling back to `Dates::now()` for an order with no creation date.
11. code-standards.md:327 "reads campaigns through `CampaignRepository` and nothing else" vs Pro `ConditionsEvaluator.php:278` reading `CustomerRepository` and `:327` `wc_get_orders` from inside `evaluate()`'s call stack. data-model.md:125 documents the evaluator's reads; the purity rule does not carve them out.
12. data-model.md:125 says Pro condition types `currency`, `country`, `payment_method` are unsupported "until the award engine passes the order"; `Campaign::conditions_match( int $user_id )` (`Campaign.php:196`) and the seam pass only a user id, while `Context` already carries currency/coupons/lines. Which object the split should pass to the evaluator is unstated.
13. README:26 "`ContextFactory` is the one class that touches WooCommerce" — true for `Engine/Points/`, false for the evaluation call stack (§5). The rule's boundary (class vs stack) is ambiguous.
14. system-architecture.md:250 "Lite's `conditions_match()` returns true → awards slightly too broadly" — consistent with `Campaign.php:208`; but data-model.md:125 "Lite … treats any non-empty set as matching" — same fact; noted only because §9 asks for anywhere the docs restate the fail-open without a stated product decision.
15. README:60 "Repeat for `first_order` and any other trigger the order satisfies; each is its own ledger row" vs data-model.md:239/:529 "One `earn` row per order" / "Exactly one `earn` row per order". Stacking across triggers on one order contradicts the one-row invariant.
16. `LedgerService.php:16-18` docblock names exactly two construction sites; a third (the future award path) will need the code-standards.md:166 review rule updated — not a bug, a gate.
