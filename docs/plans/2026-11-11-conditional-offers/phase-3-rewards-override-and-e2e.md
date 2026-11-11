# Phase 3: Locked rewards, teaser override, e2e

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 1 (`OfferLines::teaser()`, `ConditionPhrases`) and Phase 2 (the cart and product surfaces the e2e spec covers)
**Delivers:** Ways to redeem shows locked rewards with a teaser and the redeem card hides them; merchants set Auto / Custom / Off per campaign and reward; e2e coverage of spec criteria 1–6.
**Exit criterion:** `pnpm --filter @yayloyalty/e2e test specs/conditional-offers.spec.ts` passes on wp-env; `./scripts/run.sh release lite --no-prompt` passes the validator (then `composer install`); `composer test` passes; a manual walk of spec criteria 1–6 on the Local site holds (spec criterion 12).

### Task 1: The `advert_mode` / `advert_text` keys and the "Storefront teaser" field

**Files:**
- Modify: `includes/Engine/Storefront/OfferLines.php` (`CONFIG_SPECS`: the two keys' specs, from `MODE_*` and `TEXT_MAX`)
- Modify: `includes/Utils/ConfigSpec.php` (`string` specs take `max_length`, counted after `sanitize_text_field()`)
- Modify: `includes/Models/Campaign.php` (`config_schema()`: both keys under every Lite trigger)
- Modify: `includes/Models/Reward.php` (`config_schema()`: both keys under every Lite type; length cap on save)
- Modify: `includes/Pro/YayLoyaltyPro.php` (`add_campaign_config_schema()`, `add_reward_config_schema()`, `add_config_keys()`: both keys for Pro triggers and types)
- Modify: `includes/Pro/Controllers/CampaignPreviewRestController.php` (response `phrase`; `user_id` optional)
- Modify: the zod schemas for campaign and reward config under `apps/admin/src/lib/schema/` (`campaign.schema.ts`, `reward.schema.ts`)
- Modify: `apps/admin/src/pro/campaigns/conditions-preview.api.ts` (`phrase`, optional `userId`)
- Create: `apps/admin/src/pro/campaigns/conditions-preview.queries.ts` (the phrase query)
- Create: `apps/admin/src/pro/shared/StorefrontTeaserField.tsx`; export it through `apps/admin/src/pro/index.ts`
- Create: `apps/admin/src/pro/campaigns/editor/CampaignEditorTeaser.tsx`
- Modify: the Pro campaign and reward editor condition sections under `apps/admin/src/pro/campaigns/` and `apps/admin/src/pro/rewards/` (`CampaignEditorSections.tsx`, `RewardEditorSections.tsx`, `RewardEditor.tsx`: render the field when the item has at least one condition)

**Interfaces:**
- Consumes: `OfferLines::MODE_*`, `TEXT_MAX`, and `ConditionPhrases::phrase()` through the `yayloyalty_offer_condition_phrase` filter
- Produces: the config keys and the preview `phrase` (README contract)

Two findings shape this task:
- The reward editor has no conditions section: rewards get conditions only through the REST API, and the form sends the stored list back untouched. The field therefore renders as its own step under the reward's value when the stored reward (or the reward being duplicated) has at least one condition.
- The preview route needs a `user_id`, and the SPA knows no current user id. The phrase depends only on the conditions, so `user_id` becomes optional: without it the route explains for no one (`user_id` 0, every customer condition `no_customer`) and still answers `phrase`. Rewards have no preview route, so the reward field shows no auto preview, only that Auto words the conditions.

- [ ] **Step 1: One spec for both keys**

`OfferLines::CONFIG_SPECS = [ 'advert_mode' => [ 'type' => 'enum', 'values' => [ self::MODE_AUTO, self::MODE_CUSTOM, self::MODE_OFF ] ], 'advert_text' => [ 'type' => 'string', 'max_length' => self::TEXT_MAX ] ]`. `ConfigSpec::matches()` `string` case: `is_string( $value )` and, when the spec has `max_length`, `mb_strlen( sanitize_text_field( $value ) ) <= max_length` (`trim()` in place of `sanitize_text_field()` where WordPress is not loaded, as under plain PHPUnit). Document `max_length` in the class docblock.

- [ ] **Step 2: Lite schemas**

`Campaign::config_schema()`: `+ OfferLines::CONFIG_SPECS` on `purchase`, `signup` and `review`. `Reward::config_schema()`: the same on `flexible_discount`. A 121-character `advert_text` then fails `config_is_valid()`, so both REST controllers refuse it with their existing "unknown key or a value of the wrong type" error, the one every other config key uses.

- [ ] **Step 3: Pro schemas**

`add_campaign_config_schema()`: `+ OfferLines::CONFIG_SPECS` on `first_order`, `order_count`, `spend_milestone` and `birthday`. `add_config_keys()`: `advert_mode` and `advert_text` appended to the three whitelists it replaces (birthday's comes from its schema). `add_reward_config_schema()`: `+ OfferLines::CONFIG_SPECS` on the four Pro types.

- [ ] **Step 4: `phrase` on the campaign preview**

`CampaignPreviewRestController::preview()`: both answers add `'phrase' => self::phrase( $conditions )`, where `private static function phrase( array $conditions ): ?string` is `null` for no conditions, else `apply_filters( OfferLines::PHRASE_FILTER, null, $conditions )` kept only when a non-empty string. `user_id` arg: `[ 'required' => false ] + $this->user_id_param()`; `(int) $request['user_id']` is 0 without it, and `from_user( 0, … )` explains every customer condition as `no_customer`.

- [ ] **Step 5: zod mirrors**

- `campaign.schema.ts`: `ADVERT_MODES = ['auto', 'custom', 'off'] as const` (mirrors `OfferLines::MODE_*`), `ADVERT_TEXT_MAX = 120` (mirrors `OfferLines::TEXT_MAX`), `type AdvertMode`, and `interface AdvertConfig { advert_mode?: AdvertMode; advert_text?: string }`; `PurchaseConfig`, `SignupConfig`, `ReviewConfig` and `BirthdayConfig` extend it. Campaign config stays a loose record on the wire.
- `reward.schema.ts`: `advertConfigSchema = { advert_mode: z.enum(ADVERT_MODES).optional(), advert_text: z.string().max(ADVERT_TEXT_MAX).optional() }` spread into each of the five `REWARD_CONFIG_SCHEMAS` objects. Those are plain `z.object`s, which strip unknown keys, so without it a reward save from the editor would drop both keys.
- `conditions-preview.api.ts`: `phrase: z.string().nullable()` in `conditionsPreviewSchema`; `previewConditions( campaignId: number, userId?: number )` sends `user_id` only when given.

- [ ] **Step 6: `StorefrontTeaserField`**

`export function StorefrontTeaserField({ config, onPatch, autoPhrase }: { config: Record<string, unknown>; onPatch: (patch: AdvertConfig) => void; autoPhrase?: string | null })`. A `RadioGroup` Auto / Custom / Off (`advert_mode`, missing reads as `auto`). Auto shows the phrase in quotes when `autoPhrase` is a string, "No teaser can be worded from these conditions, so none is shown." when null, and "Worded from the conditions." when undefined. Custom shows an `Input` (`maxLength={ADVERT_TEXT_MAX}`, `advert_text`) with an "N / 120" counter. Off says the offer is not advertised to shoppers who don't qualify. Title "Storefront teaser"; description: the line shown under the offer to shoppers who don't meet its conditions. Exported from `apps/admin/src/pro/index.ts`.

- [ ] **Step 7: Campaign editor**

`conditions-preview.queries.ts`: `CONDITIONS_PREVIEW_QUERIES.phrase( campaignId )` (`queryOptions`, key `['campaigns', campaignId, 'preview-phrase']`, `previewConditions( campaignId )`), and `useConditionsPhraseQuery( campaignId, enabled )`. `CampaignEditorTeaser.tsx`: watches `conditions` and `config`; renders nothing with no condition; else `StorefrontTeaserField` with `onPatch` merging into `config` (`shouldDirty`), and `autoPhrase` from the query in edit mode (enabled only there, so the phrase is the saved conditions', refetched when the campaign query is invalidated after a save; undefined in create mode or while loading). `CampaignEditorSections.tsx` renders it under `<CampaignEditorConditions />` in step 3.

- [ ] **Step 8: Reward editor**

`RewardEditorSections` takes `hasConditions: boolean`; when true it renders a step 3 `EditorSection` "Storefront teaser" with `StorefrontTeaserField` over `useRewardConfig()`, no `autoPhrase`. `RewardEditor.tsx` passes `normalizeRewardConditions( reward.conditions ).length > 0` in edit mode and the duplicated reward's in create mode.

- [ ] **Step 9: Type-check and unit-test the SPA**

Run: from `apps/admin`, `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit` and `pnpm --filter @yayloyalty/admin-page test` (Node 22+; else `node_modules/.bin/tsc --noEmit` and `node_modules/.bin/vitest run`)
Expected: exit 0 for both.

- [ ] **Step 10: Verify the keys on the Local site**

Run: `cd /Users/nguyentruong/Valet/brandy && php <scratchpad>/phase3/task1.php`. In memory only, the script:
- builds a `purchase` campaign, a `birthday` and an `order_count` campaign, and a `flexible_discount` and a `fixed_coupon` reward (`set_config()`) with `advert_mode` `custom` and `advert_text` "for our insiders", and prints `get_config()` and `config_is_valid()` for each;
- checks `config_is_valid()` with `advert_mode` `bogus`, with `advert_text` of 120 and of 121 characters, and of 121 characters that `sanitize_text_field()` trims to 120 (`"<b>…</b>"` around 118 characters);
- calls `CampaignRestController::create_item()` with a `WP_REST_Request` whose config has a 121-character `advert_text`: it validates before it writes, so the refusal returns before any insert;
- removes Pro's schema and whitelist filters (`remove_all_filters( 'yayloyalty_campaign_config_schema' )`, `…_whitelist`, `yayloyalty_reward_config_schema`) to stand in for a Lite build and rebuilds the same models from the same config, printing `get_config()`;
- calls the preview controller's private `phrase()` through reflection on `user_role in [customer]` and on `[]`, and `preview()` on the stored purchase campaign without `user_id`.
Expected: both keys kept on every model; `bogus` and 121 characters invalid; 120 valid; the tag-wrapped 118 valid; `create_item()` answers 400 "The campaign config has an unknown key or a value of the wrong type."; under the Lite stand-in the purchase campaign and flexible reward keep both keys, and the `birthday` / `order_count` campaigns and the `fixed_coupon` reward keep their whole config; `phrase` "for Customer members" and null; `preview()` without `user_id` answers with `user_id` 0 and a `phrase` key.

- [ ] **Step 11: Run the unit suite and lint**

Run: `composer test`, then `composer lint -- includes/Engine/Storefront/OfferLines.php includes/Utils/ConfigSpec.php includes/Models/Campaign.php includes/Models/Reward.php includes/Pro/YayLoyaltyPro.php includes/Pro/Controllers/CampaignPreviewRestController.php`
Expected: OK; no reports for these files.

- [ ] **Step 12: Commit**

Stage: the files listed under **Files** for this task, by name
Message: `feat(offers): let merchants set or turn off the storefront teaser per campaign and reward`

### Task 2: Locked rewards in Ways to redeem, hidden in the redeem card

**Files:**
- Modify: `includes/Engine/Storefront/LauncherPayload.php` (`redeem_offers( string $now, int $user_id )`, the filter's third argument)
- Modify: `includes/Engine/Storefront/LauncherPreview.php` (the Design preview keeps the store's own offers, samples only when there are none)
- Modify: `includes/Engine/Redemption/RewardGate.php` (`CONDITIONS_FILTER`)
- Create: `includes/Pro/Services/RewardConditionsMatch.php` (Pro's subscriber to `yayloyalty_reward_conditions_match`)
- Modify: `includes/Pro/YayLoyaltyPro.php` (register it)
- Modify: `includes/Pro/Storefront/RewardsPanel.php` (`redeem_offers( array $offers, string $now, int $user_id )`, `rows()` drops `REASON_CONDITIONS_NOT_MET`)
- Modify: `includes/Engine/Storefront/templates/ways-to-redeem.php`, `rule-list-item.php` (`tone` `locked` → `.yayloyalty-rule--locked`, the teaser as the text line)
- Modify: `assets/storefront/account/rule-list-item.css`

**Interfaces:**
- Consumes: the `yayloyalty_reward_conditions_match` filter on a `Engine\Rewards\Context` for the viewer, `OfferLines::teaser( $reward->get_config(), $reward->get_conditions() )`, `OfferLines::enabled()`
- Produces: the offer keys `locked` and `teaser`, and the filter's third argument (README contract)

Finding: nothing subscribes to `yayloyalty_reward_conditions_match` on this branch (the spec's "Pro's existing evaluator over facts" is not built), so every reward's conditions match today, at checkout too. Ways to redeem and the card can only tell a failing reward through that seam, so Pro gains the subscriber. It also makes checkout refuse a reward whose conditions the shopper fails, which is what the seam's docblock and the redemption design promise.

- [ ] **Step 1: `RewardGate::CONDITIONS_FILTER = 'yayloyalty_reward_conditions_match'`**, used by `check()` in place of the literal.

- [ ] **Step 2: `Pro\Services\RewardConditionsMatch`**

`__construct( ConditionsEvaluator $evaluator )`, `register(): void` (`add_filter( RewardGate::CONDITIONS_FILTER, [ $this, 'matches' ], 10, 3 )`), `matches( $matches, Reward $reward, Context $ctx ): bool`: false when `$matches` is already false; true for no conditions; false for a guest (`null` user id); else `$this->evaluator->evaluate( $reward->get_conditions(), $viewer )` where `$viewer` is `Points\ContextFactory::from_user( $user_id, $ctx->get_at() )`, memoised per user id and time so one page reads a member's facts once. Reward condition types (`user_role`, `customer_ids`, `lifetime_points`, `tier`) share the campaign types' names and value shapes, and the evaluator rejects `tier` as not yet evaluable. `YayLoyaltyPro::boot()` registers it with `self::evaluator()`.

- [ ] **Step 3: The filter's third argument**

`LauncherPayload::redeem_offers( string $now, int $user_id ): array` passes `$user_id` to `REDEEM_OFFERS_FILTER` (docblock: `@param int $user_id The viewer; 0 for a guest`; an offer may carry `locked: bool` and `teaser: string`). `for_balance()` passes its `$viewer_id`. The row check keeps an offer whose `locked`, when present, is a bool, and drops a locked one without a non-empty string `teaser`.

- [ ] **Step 4: Locked rewards in `RewardsPanel::redeem_offers( array $offers, string $now, int $user_id = 0 )`**

Registered with 3 arguments; each live reward goes through `public static function offer_for( Reward $reward, int $user_id, string $now ): ?array`. A reward with no conditions, or whose conditions hold for the viewer, is `LauncherPayload::offer( $reward )` as today. Otherwise, when `OfferLines::enabled()` and `OfferLines::teaser( $reward->get_config(), $reward->get_conditions() )` is a string, it is `offer( $reward ) + [ 'locked' => true, 'teaser' => $teaser ]`; else it is dropped. `private static function conditions_hold( Reward $reward, int $user_id, string $now ): bool`: false for a guest; else `(bool) apply_filters( RewardGate::CONDITIONS_FILTER, true, $reward, $ctx )` on `new Rewards\Context( $user_id, PHP_INT_MAX, null, null, $currency, $now, null, null, CurrencyRate::rate( $currency ) )`, the balance unbounded so it never decides, and liveness already settled by `live_of_types()`.

- [ ] **Step 5: The card drops rewards the shopper's conditions rule out**

`rows()`: after the program-gate check, drop each option whose quote reason is `Result::REASON_CONDITIONS_NOT_MET`, or whose reward fails `conditions_hold()` (a short balance is reported before conditions, so a member below the cost would otherwise still see a disabled row for a reward they can never claim). Nothing left → `$rows` unchanged.

- [ ] **Step 6: Print the locked row**

`ways-to-redeem.php`: an offer with `locked` true gets `'tone' => 'locked'` and its `teaser` as `text`. `rule-list-item.php`: `tone` `locked` adds `yayloyalty-rule--locked` to the `<li>` (documented with the other tones). `rule-list-item.css`: a locked row's icon at half opacity and its name and value muted, its text line in the accent at weight 500 like a bonus line.

- [ ] **Step 7: The Design preview**

`LauncherPreview::documents()`: sample rewards only when the payload has no offers, so the guest preview shows the store's conditional rewards as locked rows when they have a teaser.

- [ ] **Step 8: Verify criterion 5 and the reward half of 6 on the Local site**

Run: `cd /Users/nguyentruong/Valet/brandy && php <scratchpad>/phase3/task2.php`. The script builds in memory a "VIP $10" `fixed_coupon` reward (500 points, `user_role in [customer]`, standing in for `vip`) and an "Open $5" one with no conditions. `RewardRepository` has no read filter, so the script swaps Pro's `REDEEM_OFFERS_FILTER` subscriber for one that maps those models through `RewardsPanel::offer_for()` (Step 4's per-reward step) and prints `LauncherPayload::for_user()['offers']` and the Ways to redeem markup for a guest, a customer (stands for `vip`) and the administrator (non-VIP); then with `advert_mode` `custom` "for our insiders", with `off`, and with `show_bonus_offers` filtered to false. It also prints `RewardConditionsMatch::matches()` for each viewer on that reward, and `RewardsPanel::rows()` filtering on a fake `options` list carrying a `REASON_CONDITIONS_NOT_MET` quote.
Expected:
- administrator and guest: "VIP $10" locked, text "for Customer members", `<li class="yayloyalty-account__item yayloyalty-rule--locked">`; customer: a normal row with the coupon line;
- custom: text "for our insiders"; off, or `show_bonus_offers` false: absent for the administrator and guest, normal for the customer;
- "Open $5" normal for everyone;
- `matches()`: guest false, administrator false, customer true;
- the filtered card options hold only the rewards whose conditions hold.

- [ ] **Step 9: Verify the Design preview**

Run: `php <scratchpad>/phase3/task2.php` with `CASE=preview`: the same injected rewards, `LauncherPreview::documents( DesignHelper::get_design(), false )['expanded']` searched for the locked row.
Expected: one `yayloyalty-rule--locked` row reading "for Customer members"; with no injected rewards the samples print as before. How the row looks is a browser check.

- [ ] **Step 10: Run the unit suite and lint**

Run: `composer test`, then `composer lint -- <the PHP files of this task>`, and `php -l includes/Pro/Services/RewardConditionsMatch.php`
Expected: OK; no reports for these files; no syntax errors.

- [ ] **Step 11: Commit**

Stage: the files listed under **Files** for this task, by name
Message: `feat(storefront): show rewards a shopper can't claim yet as locked, and keep them off the redeem card`

### Task 3: e2e spec, docs, final verification

**Files:**
- Create: `apps/e2e/specs/conditional-offers.spec.ts`
- Modify: `apps/e2e/bin/setup.php` or `setup.sh` only if the `vip` role and a Shoes category can't be seeded from the spec through `wpCli()` (prefer seeding inside the spec)
- Modify: `docs/architecture.md` (one paragraph: `PointsEngine::offers()` / `OfferLines` as the single source for storefront advertising, and the draft-Context rule in the evaluator)
- Modify: `docs/plans/2026-11-11-conditional-offers/README.md` (Status `done 2026-MM-DD` and phase statuses, in the PR that finishes the plan)

**Interfaces:**
- Consumes: `apps/e2e/support/` (`adminApi`, `wpCli`, `logIn`, `openLauncher`, `addWidgetsToCart`)

- [ ] **Step 1: Seed in `beforeAll`, clean up in `afterAll`**

Through `adminApi()`: the `vip` role (`wpCli( 'role', 'create', 'vip', 'VIP', '--clone=customer' )` when `wp role exists vip` fails); the "Shoes" product category (`wc/v3/products/categories`); products `e2e-shoes` ($100, in Shoes) and `e2e-plain` ($100, uncategorised) through `wc/v3/products`; a page with `[yayloyalty_ways_to_earn]` and `[yayloyalty_ways_to_redeem]` (`wp/v2/pages`); campaigns "VIP" (purchase, 2/unit, `user_role in [vip]`) then "Shoes" (purchase, 3/unit, `category_in_cart in [Shoes id]`) through `yayloyalty/v1/campaigns`, each created on top of the purchase group, so the order is Shoes, VIP, then the seeded base at 1/unit; reward "VIP $10" (`fixed_coupon`, $10, 500 points, `user_role in [vip]`). `afterAll` deletes all of them and the role.

- [ ] **Step 2: Criteria 1–6**

One `test.describe.serial`, three viewers: a guest context, a plain customer and a `vip` customer (`api.createCustomer( 'vip' )`), each member given 1,000 points so the card lists rewards. Criterion 1 on the placement page and in the launcher (two guest contexts' `.yayloyalty-account__list` HTML equal); 2 on the placement; 3 on both product pages; 4 on the Cart block; 5 on the placement's Ways to redeem and the Cart block's card; 6 by `PUT yayloyalty/v1/campaigns/{VIP}` and `PUT yayloyalty/v1/rewards/{VIP $10}` with `advert_mode` `off` and `custom` "for our insiders", restored to `auto` after.

- [ ] **Step 3: Type-check and run the spec**

Run: `./scripts/run.sh build pro`, `pnpm --filter @yayloyalty/e2e env:start`, `pnpm --filter @yayloyalty/e2e exec tsc --noEmit`, `pnpm --filter @yayloyalty/e2e test specs/conditional-offers.spec.ts`
Expected: type-check exit 0; every test passes.

- [ ] **Step 4: `docs/architecture.md`**

One paragraph under the storefront surfaces: every surface that advertises earning or a conditional reward reads `PointsEngine::offers()` through `OfferLines`, never its own priority or match rules; the evaluator compares order conditions on a Context without an order only when their input is there (`needs_order` otherwise), and keeps its answer for a Context with an `order_id`; Pro's `RewardConditionsMatch` answers `yayloyalty_reward_conditions_match`, and `RewardsPanel` asks it for the viewer to lock a reward in Ways to redeem and drop it from the card. The Design preview sentence follows Task 2 Step 7.

- [ ] **Step 5: Lite build**

Run: `./scripts/run.sh release pro`, then `./scripts/run.sh release lite --no-prompt`, then `composer install`; and `grep -n 'Pro\\\|YayLoyaltyPro\|ConditionsEvaluator\|ConditionPhrases' <the changed Lite PHP files>`
Expected: the validator passes for both zips; the grep finds no Pro name in Lite files.

- [ ] **Step 6: Run the unit suite**

Run: `composer test`
Expected: OK.

- [ ] **Step 7: Commit**

Stage: `apps/e2e/specs/conditional-offers.spec.ts`, `docs/architecture.md`, this phase file, and `README.md` (Phase 3's row `done` only once its exit criterion is verified)
Message: `test(offers): cover conditional offers end to end, and document the advertising source`
