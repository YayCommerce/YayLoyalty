# Tier Storefront (Tiers slice 5) Design

**Status:** accepted 2026-11-05
**Amended:** 2026-11-10: My Account → My Points gains a "Your tier" section (accepted 2026-11-10), and the tier progress sentences move into `LauncherCopy`. [Amendment 2026-11-10](#amendment-2026-11-10-my-points-tier-section) at the end describes it; the Out line marked "amended 2026-11-10" points there.
**Related:** [Tiers master design](2026-11-02-tiers-master-design.md) (Delivery slice 5, AC 20 and AC 22.3), [slice 2 design](2026-11-04-tier-lifetime-evaluation-design.md), [slice 4 design](2026-11-05-tier-rolling-mode-design.md), `docs/backlog/2026-10-06-tiers-not-built.md`

This child spec refines the master for slice 5. Everything the master decides (D1–D22) and everything slices 1–4 fixed still holds. The master's Delivery table marks slice 5 "Plan only"; **Refinements of the master** says why it got a spec anyway.

## Goal

A signed-in shopper on a Pro store with tiers on sees their tier badge and their progress to the next tier in the launcher's member panel. In Rolling mode they also see the window length and, during a hold, the date their tier is kept until.

## Context

- **Launcher payload** (`includes/Engine/Storefront/LauncherPayload.php`): `build()` reads the customer once and calls `for_balance( $design, $balance, $left_today, $user_id )`. `member` is `{balance, worth, expires_at}`, null for a guest. `REDEEM_OFFERS_FILTER` (`yayloyalty_launcher_redeem_offers`) is the precedent for an internal Pro seam: `$user_id` null for a guest and the Design preview, and a throwing subscriber or a malformed row is dropped with `Log::warning`.
- **Pro subscriber precedent:** `Pro\Storefront\RewardsPanel::register()` attaches to `REDEEM_OFFERS_FILTER`, called from `YayLoyaltyPro::boot()`.
- **Member panel** (`templates/panel-member.php`): requires `points-card.php`, `new-points-notice.php`, then the ways lists and `recent-activity.php`. `points-card.php` is shared: the launcher, My Account → My Points (`account-rewards.php`), and `[yayloyalty_balance]` with its block (`Placements.php`).
- **Storefront CSS:** `assets/storefront/account/rewards-tokens.css` is the one enqueued file (`AccountMyPoints::STYLE_PATH`). It `@import`s one file per template, every rule nested under `.yayloyalty-account`, px sizes, colours from `../storefront-tokens.css`. The launcher enqueues it (`ScriptName::ACCOUNT_STYLE`), and `LauncherPreview::document()` links it.
- **Design preview** (`LauncherPreview::documents()`): calls `for_balance( $design, SAMPLE_BALANCE )` (9,850) with no user id.
- **Drawer standing** (`Pro\Controllers\CustomerTierRestController::respond()`): computes `tier` (the stored tier if it is live), `next` (the lowest live tier ranked above it, or the lowest tier for a tierless customer), `progress_value` (Rolling: `LedgerRepository::window_total( $user_id, Dates::add_months( $now, -$months ), $now )`; Lifetime: `tier_progress_value`, else `lifetime_earned`), `mode`, `window_months`, and `held_from` / `held_until` only while the hold runs.
- **Evaluator settings:** `TierEvaluator::is_enabled()`, `mode()`, `rolling_months()`. `TierRepository::live()` returns live tiers in rank order.
- **Removed v1.0 placeholders:** `panel.show_progress_to_next_tier`, `storefront_text.tier_progress_line`, `tier.badges` (commit `a4db4406`). They stay removed.

## Scope

**In:**
- The tier badge, progress text, progress bar and Rolling caption in the launcher's member panel only.
- A shared Pro reader for a customer's tier standing, used by the drawer route and the launcher.
- A sample tier line in the Design tab's member preview when tiers are on.
- The backlog update for the storefront box.

**Out:**
- My Account → My Points / Rewards (amended 2026-11-10: My Points has a tier section, see [Amendment 2026-11-10](#amendment-2026-11-10-my-points-tier-section)), `[yayloyalty_balance]`, the balance block, and every other shortcode and block (master Out list). They render exactly as today.
- Any Design tab key, toggle, editable copy or badge style. No `DesignSchema` change, no SPA change.
- Any guest-facing tier content (Ways to earn, guest panel).
- Tier emails, and anything on the dashboard (master Out list).
- Any change to the drawer card's REST shape or UI.
- New automated tests (see Acceptance criteria: verified manually).

## Acceptance criteria

All in the launcher member panel on the wp-env Pro site, seed tiers unless stated, Lifetime mode unless stated, tiers on unless stated. Points text uses the store's label, shown here as the default "Points".

1. A Silver customer with `tier_progress_value` 1,000 (Gold at 3,000) sees a Silver badge (dot `#C0C0C0`, name "Silver"), "2,000 Points to Gold", and a bar at 33% (`aria-valuenow` 1000, `aria-valuemax` 3000).
2. A Gold customer sees the Gold badge and "Top tier", and no bar.
3. A tierless customer with 0 points earned, with the lowest live tier "Bronze" at threshold 500, sees no badge row, "500 Points to Bronze", and a bar at 0%.
4. A Silver customer whose `tier_progress_value` is 3,050 (Silver's entry bonus carried them past Gold, D11) sees "Gold unlocks with your next earn" and a bar at 100%.
5. In Rolling mode, 6 months: a Silver customer with `tier_held_until` 2027-07-10 sees progress from the live window total, the caption "Counts Points earned in the last 6 months." and "Silver kept until {2027-07-10 in the site date format}". Once the hold has passed (`held_until` null from the reader), the second sentence is gone.
6. With tiers off, with no live tier, on Lite, or for a guest, the launcher's HTML contains no `yayloyalty-account__tier` element.
7. My Account → My Points and a page with `[yayloyalty_balance]` render the same HTML for the same customer before and after this slice.
8. Design tab → member preview, tiers on: the panel shows the Bronze badge, "400 Points to Silver" and a 50% bar (midpoint of 0 and 800). With only one live tier it shows that tier and "Top tier". With tiers off it shows no tier line.
9. `GET` on the drawer's customer tier route returns the same JSON for the same customer before and after the `TierStanding` refactor (Lifetime, Rolling held, tierless, manual floor).
10. **End to end** (master AC 22.3): on the wp-env Pro site, with tiers on, Lifetime mode, the seed tiers, Silver's bonus at 100 and the default purchase campaign at 1 pt/unit, a new customer completes an 800-unit order. Their launcher shows the Silver badge and "2,100 Points to Gold" (900 earned).

## Constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union types, `mixed`/`static`/`never` types, or the other items in `.claude/rules/php-standards.md`.
- Lite names Pro only in the guarded `YayLoyaltyPro::boot()` call. Pro attaches only through `LauncherPayload::MEMBER_TIER_FILTER`, added in `boot()`.
- Storefront CSS is hand-written in `assets/storefront/account/`, nested under `.yayloyalty-account`, px sizes, colours from `storefront-tokens.css`. Nothing under `apps/admin/`, no `yl:` classes, no Design tab values.
- Dates are stored and passed in UTC and printed with `Dates::to_site_date()`.
- All strings use the `yayloyalty` text domain with translator comments.
- No tests are added (CLAUDE.md Tests: not money or ledger logic, no reproduced bug). Verification is the manual checks above.
- All work lands on `jason/feat/tiers`. Commit only when the user asks.

## Approach

**Chosen:** a payload seam in Lite filled by Pro, with a Lite partial that prints it, and one shared Pro reader for the tier standing.

**Architecture:** `LauncherPayload::for_balance()` asks a new internal filter for the member's tier line and stores the answer in `member.tier`. Pro's `TierProgress` answers it from `TierStanding`, the reader the drawer route now also uses, so the launcher and the drawer can't disagree. For the Design preview (`$user_id` null) Pro returns a sample. A new Lite partial, required only by `panel-member.php`, prints the line when `member.tier` isn't null. Lite has no subscriber, so it never prints.

**Components:**
- `includes/Pro/Services/TierStanding.php` [new]: `public static function of( Customer $customer, array $live, string $now ): array`, returning `{tier: ?Tier, next: ?Tier, progress: int, mode: string, window_months: ?int, held_from: ?string, held_until: ?string}`. It holds the logic moved from `CustomerTierRestController::respond()`, unchanged. Its window total reads `LedgerRepository::window_total()`, so it takes a `LedgerRepository` (constructor or argument, the planner's choice, matching the controller's existing dependency).
- `includes/Pro/Controllers/CustomerTierRestController.php` [edit]: `respond()` calls `TierStanding` and maps it to its existing shape, then adds `achieved_at` and `manual`. Its response doesn't change.
- `includes/Pro/Storefront/TierProgress.php` [new]: `register()` adds `MEMBER_TIER_FILTER`. `member_tier( $value, ?int $user_id, int $balance ): ?array` returns the shape below, the sample for a null user id, or null.
- `includes/Pro/YayLoyaltyPro.php` [edit]: `boot()` calls `( new TierProgress() )->register()`.
- `includes/Engine/Storefront/LauncherPayload.php` [edit]: `MEMBER_TIER_FILTER` constant with its docblock. A private `member_tier( ?int $user_id, int $balance ): ?array` applies the filter inside `try`/`catch`, validates the shape, and returns null with `Log::warning( 'LauncherPayload', … )` on a throw or a malformed value. `for_balance()` sets `member.tier` when `member` isn't null. The `member()` docblock's return type gains `tier`.
- `includes/Engine/Storefront/templates/tier-progress.php` [new]: the markup below.
- `includes/Engine/Storefront/templates/panel-member.php` [edit]: `require __DIR__ . '/tier-progress.php';` right after `points-card.php`, before `new-points-notice.php`. The docblock lists it.
- `assets/storefront/account/tier-progress.css` [new]: styles for the partial.
- `assets/storefront/account/rewards-tokens.css` [edit]: `@import url("tier-progress.css");` after `points-card.css`.
- `docs/backlog/2026-10-06-tiers-not-built.md` [edit]: see Rollout.

**Data and storage:** None. Nothing new is stored. The line reads `customers.tier_id`, `tier_progress_value`, `lifetime_earned`, `tier_held_until`, the ledger window total, the live tiers and the `tiers_*` settings.

**Integration points:**
- New filter `yayloyalty_launcher_member_tier` (`LauncherPayload::MEMBER_TIER_FILTER`), an internal Pro seam, not a public API:
  `apply_filters( 'yayloyalty_launcher_member_tier', null, ?int $user_id, int $balance )`. Applied only for a member (live or the preview's sample); `$user_id` null means the Design preview. Return null to print nothing, or:
  ```
  array{
    name: ?string,                                   // null = tierless (no badge row)
    color: ?string,                                  // '#RRGGBB'; null when tierless
    next: array{name: string, threshold: int}|null,  // null = top tier
    progress: int,                                   // the qualifying total shown
    needed: int,                                     // max(0, next.threshold - progress); 0 when next is null
    window_months: ?int,                             // Rolling only
    held_until: ?string,                             // UTC; Rolling, a running hold, and a tier
  }
  ```
  Lite's validation: `name` and `color` string or null; `next` null or `name` non-empty string with `threshold` int ≥ 0; `progress` and `needed` int ≥ 0; `window_months` null or int ≥ 1; `held_until` null or `Dates::is_valid()`. A value that fails any check becomes null.
- `TierProgress::member_tier()`, live (`$user_id` int):
  - null when `! TierEvaluator::is_enabled()` or `TierRepository::live()` is empty;
  - the customer is `CustomerRepository::find_by_user_id( $user_id ) ?? new Customer( $user_id )`;
  - maps `TierStanding::of( $customer, $live, Dates::now() )`: `name`/`color` from `tier`, `next` from `next`, `progress`, `needed`, `window_months`, and `held_until` only when `tier` isn't null.
- `TierProgress::member_tier()`, preview (`$user_id` null):
  - null under the same two conditions;
  - `tier` is the lowest live tier, and `next` the next live tier by rank, or null when only one exists;
  - `progress` is `intdiv( tier.threshold + next.threshold, 2 )`, or `tier.threshold` with no next tier;
  - Rolling adds `window_months` = `TierEvaluator::rolling_months()` and `held_until` = `Dates::add_months( Dates::now(), window_months )`. `$balance` is ignored.

**UI:** the partial `tier-progress.php`, printed only when `$view['member']['tier']` isn't null (`$view` as `panel-member.php` names it):

```
<section class="yayloyalty-account__tier">
  [badge row, only when name isn't null]
    <span class="yayloyalty-account__tier-badge">
      <span class="yayloyalty-account__tier-dot" style="background-color: {esc_attr(color)}" aria-hidden="true"></span>
      {esc_html(name)}
    </span>
  <p class="yayloyalty-account__tier-text">{text}</p>
  [bar, only when next isn't null]
    <div class="yayloyalty-account__tier-bar" role="progressbar" aria-valuemin="0"
         aria-valuemax="{next.threshold}" aria-valuenow="{min(progress, next.threshold)}" aria-label="{text}">
      <span class="yayloyalty-account__tier-fill" style="width: {pct}%"></span>
    </div>
  [caption, only when window_months isn't null]
    <p class="yayloyalty-account__tier-caption">{window sentence}[ {hold sentence}]</p>
</section>
```

- **Text:**
  - `next` null: `__( 'Top tier', 'yayloyalty' )`.
  - `needed > 0`: `sprintf( __( '%1$s to %2$s', 'yayloyalty' ), SettingsHelper::points_text( needed ), next.name )`, with a translator comment for 1: points with the store's label, as "120 Points", and 2: the next tier's name. Example: "120 Points to Gold".
  - `needed === 0`: `sprintf( __( '%s unlocks with your next earn', 'yayloyalty' ), next.name )`.
- **Bar fill:** `pct` = `0 === next.threshold ? 100 : min( 100, intdiv( progress * 100, next.threshold ) )`.
- **Window sentence:** `sprintf( __( 'Counts %1$s earned in the last %2$d months.', 'yayloyalty' ), SettingsHelper::points_label_plural(), window_months )`.
- **Hold sentence:** `sprintf( __( '%1$s kept until %2$s.', 'yayloyalty' ), name, Dates::to_site_date( held_until ) )`.
- **CSS** (`tier-progress.css`): the section is a column with an 8px gap and the card's padding and border tokens. The dot is 10px round. The badge name is semibold in the text colour; only the dot carries the tier colour, so no contrast check is needed. The bar is 6px high, with the muted token for the track, the primary token for the fill, and a full radius. The caption is 12px in the muted text token. All rules are nested under `.yayloyalty-account`.

**Edge cases and failure modes:**
- **Manual floor above the points:** shows the floor tier and progress to the tier ranked above it, as the drawer does.
- **Rolling, held above the window total:** the held tier's badge, progress to the next tier from the live window total, and the "kept until" sentence.
- **Stored tier soft-deleted before the sweep re-evaluates:** `TierStanding` gives `tier` null, so the shopper reads as tierless with progress to the lowest tier, as the drawer does.
- **Never-evaluated shopper and a lowest threshold of 0:** `needed` is 0, so the text reads "Bronze unlocks with your next earn". That is accurate: the next ledger write places them.
- **A threshold of 0 as `next`:** the bar is 100%, with no division by zero.
- **A throwing subscriber or a malformed value:** no tier line, a `Log::warning`, and the rest of the launcher renders.
- **Page caches:** the line is per member and printed into the page like the balance; `LauncherPayload`'s class docblock caveat already covers it.
- **Lite:** no subscriber, so `member.tier` is always null and nothing prints. No Pro name is added to Lite: the filter, the partial and the CSS are tier-generic.

**Decision record:** None. This follows the existing internal-seam pattern and changes no architectural choice.

## Refinements of the master

| Master says | This spec |
|---|---|
| Delivery slice 5: "Plan only" | A child spec, because placement (the shared points card), the Design tab keys and the Rolling copy were not decided by the master. |
| Components: `includes/Engine/Storefront/` [edit] "through a launcher-payload filter that Pro fills" | The filter is `yayloyalty_launcher_member_tier`, plus a shared Pro reader `TierStanding` and the Pro subscriber `Pro\Storefront\TierProgress`. |
| AC 20: "X points to {next tier}", "Top tier", tierless "X points to {lowest tier}" | Kept, plus "{next tier} unlocks with your next earn" when `needed` is 0, a progress bar, and the Rolling caption. |
| Backlog box: "configured in Design" | No Design configuration. The box is reworded (see Rollout). |

## Alternatives considered

| Option | Why rejected |
|---|---|
| Put the line inside `points-card.php` | It would also render on My Account and `[yayloyalty_balance]` and its block, which the master puts out of scope. |
| Restore `panel.show_progress_to_next_tier` (and `storefront_text.tier_progress_line`) in Design | Not wanted. It needs a `DesignSchema` key in Lite, an SPA control and a second copy source, for a line that only shows when tiers are on. |
| Rolling: window caption only, or no Rolling wording | Shoppers can't tell why progress moves down or how long their tier holds. The drawer already shows both. |
| No tier line in the Design preview | Merchants couldn't see the line before shoppers do. |
| Preview progress from `SAMPLE_BALANCE` (9,850) | It sits past Gold on the seed tiers and would preview "…unlocks with your next earn" instead of a typical line. |
| "0 Points to Gold" when the threshold is already reached | Reads like a bug to a shopper. D11 means the next earn moves them. |
| Text only, no bar | The user chose the bar, matching the drawer card. |
| Pro prints its own template through an action in `panel-member.php` | The preview would need its own flag path, the HTML would be hard to verify, and the data wouldn't be inspectable in the payload. |
| Compute the tier inside Lite's `LauncherPayload` | Hard rule 2: Pro attaches only through filters, and the mode and hold logic is Pro code. |
| Duplicate the standing logic in the launcher | It would drift from the drawer card. One reader keeps them equal (AC 9). |
| Unit or e2e tests | CLAUDE.md Tests: not money or ledger logic, no reproduced bug. The user chose manual checks. |

## Rollout

- No setting, flag or migration. The line appears on the first page load after the update for stores with tiers on, and never for stores with tiers off or on Lite.
- `docs/backlog/2026-10-06-tiers-not-built.md`:
  - reword "The storefront shows tier badges and progress to the next tier, configured in Design." to "The launcher shows the tier badge and progress to the next tier." and tick it;
  - drop the `tier.badges` / `storefront_text.tier_progress_line` / `panel.show_progress_to_next_tier` restore bullet, and add the launcher line to **Today**.
  - The tier emails box stays open, so the item stays.
- Master spec: no status change. It stays `accepted`. This doc is linked as the slice 5 artifact.

## Open questions

None.

## Amendment 2026-11-10: My Points tier section

This amendment adds what the scope above and the master left out: a tier section on My Account → My Points. Everything else above, and everything the master (D1–D22) and slices 1–4 decided, still holds. The user shared a screenshot as a layout example only. Within this amendment, "AC n" means the criteria under its own Acceptance criteria heading.

### Goal

A signed-in customer on a Pro store with tiers on opens My Account → My Points and, under the "Your Points" card, sees a "Your tier" section: their tier, its earn multiplier, their progress to the next tier, when they reached the tier, and every tier with its threshold and perks.

### Context

- **My Points tab** (`includes/Engine/Storefront/AccountMyPoints.php`): `render()` prints the unavailable card when the program is off or the viewer can't earn, else includes `templates/account-rewards.php` with `view()`: `card_view()` (`LauncherPayload::for_user()`), `earn`, `history`. The template requires `points-card.php`, then `ways-to-earn.php` / `ways-to-redeem.php` (or `no-ways-card.php`), then `points-history.php`. Sections use `yayloyalty-account__section` and an `h3.yayloyalty-account__title`.
- **History swap:** `AccountHistoryAjax` replaces only the history partial, so anything above it stays.
- **Storefront CSS:** `assets/storefront/account/rewards-tokens.css` is the one enqueued file; it `@import`s one file per template, rules nested under `.yayloyalty-account`, px sizes, colours from `../storefront-tokens.css`.
- **Launcher tier line (this spec, above):** `LauncherPayload::MEMBER_TIER_FILTER` (`yayloyalty_launcher_member_tier`) answered by `Pro\Storefront\TierProgress::member_tier()`, which builds its answer in private `TierProgress::line()`. `templates/tier-progress.php` computes the progress text, bar percentage and Rolling caption inline. `LauncherPayload::member_tier()` validates the answer (`is_member_tier()`), drops a throwing or malformed one with `Log::warning`.
- **Standing reader:** `Pro\Services\TierStanding::of( Customer, Tier[] $live, string $now )` returns `tier`, `next`, `progress`, `mode`, `window_months`, `held_from`, `held_until`. The drawer route and `TierProgress` both use it.
- **Data:** `Customer::get_tier_achieved_at()` is set by `TierEvaluator` to the evaluation time whenever the tier changes (up or down). `Tier` has `get_name()`, `get_color()`, `get_threshold()`, `get_tier_rank()`, `get_multiplier()` (decimal string, minimum `'1.00'`), `get_entry_bonus()`. `TierRepository::live()` returns live tiers in rank order. `TierEvaluator::is_enabled()` is the tiers switch.
- **Copy helpers:** `LauncherCopy` (static storefront copy), `SettingsHelper::points_text()` / `points_label_plural()`, `Dates::to_site_date()`.

### Scope

**In:**
- A "Your tier" section on My Account → My Points, between the points card and Ways to earn: status card, details list, "All tiers" list.
- A new internal filter in `AccountMyPoints` and its Pro subscriber `Pro\Storefront\AccountTier`.
- Moving the tier progress text, bar percentage and Rolling caption out of `tier-progress.php` into `LauncherCopy`, used by both the launcher line and the new section. The launcher's output stays the same.
- An amendment note on the master's Out list, and this amendment in place of the Out line above.

**Out:**
- Any change to what the launcher tier line shows, the Design tab or its preview, `DesignSchema`, or the SPA.
- `[yayloyalty_balance]`, its block, and every other shortcode and block.
- Guests (they get WooCommerce's sign-in form on My Account anyway).
- The screenshot's "points used" and "points remaining" rows: the points card above already shows the balance.
- A separate tier rules page or link: the "All tiers" list stands in for it.
- Mentioning a staff-assigned (manual) tier or its end date to the customer.
- Tier emails and the dashboard (master Out list).
- New automated tests (CLAUDE.md Tests: not money or ledger logic, no reproduced bug).

### Acceptance criteria

All on the wp-env Pro site, signed in as a customer, at `/my-account/my-points/`, Lifetime mode and tiers on unless stated. Tiers set up as: Bronze threshold 500, multiplier 1.00, entry bonus 0, colour `#CD7F32`; Silver threshold 1,000, multiplier 1.50, entry bonus 0; Gold threshold 3,000, multiplier 2.00, entry bonus 200. Points text uses the store's label, shown here as the default "Points".

1. A Silver customer with progress 1,000 and `tier_achieved_at` 2026-10-26 sees, right under the points card and above Ways to earn, an `h3` "Your tier" and a card with: the Silver badge (dot in Silver's colour, "Silver"), a chip "1.5× Points", "2,000 Points to Gold", a bar with `aria-valuenow` 1000 and `aria-valuemax` 3000 (33% wide), then details "Tier since" = 2026-10-26 in the site date format and "Tier Points" = "1,000". No "Kept until" row and no caption.
2. The same page's "All tiers" list shows three rows in rank order: "Bronze · From 500 Points" with no perks; "Silver · From 1,000 Points · 1.5× Points" with a "You" chip and `aria-current="true"`; "Gold · From 3,000 Points · 2× Points · +200 Points bonus".
3. A Gold customer sees the Gold badge, the chip "2× Points", "Top tier" and no bar; the Gold row is marked "You".
4. A Bronze customer sees the Bronze badge and no multiplier chip (multiplier 1.00).
5. A tierless customer with 0 points earned sees "No tier yet" with no dot and no chip, "500 Points to Bronze", a bar at 0%, "Tier Points" = "0", no "Tier since" row, and no row marked "You".
6. A Silver customer whose progress is 3,050 sees "Gold unlocks with your next earn" and a bar at 100%.
7. Rolling mode, 6 months: a Silver customer with `tier_held_until` 2027-07-10 sees progress from the live window total, the caption "Counts Points earned in the last 6 months." and a details row "Kept until" = 2027-07-10 in the site date format. Without a running hold there is no "Kept until" row.
8. With tiers off, or with every tier deleted, the tab shows no tier section and is otherwise unchanged. The same holds on a Lite build.
9. A filter subscriber that throws, or returns an array missing a key, leaves the tab without the section, writes a `Log::warning` from `AccountMyPoints`, and the rest of the tab renders.
10. The launcher tier line for the customers in AC 1, 3, 5, 6 and 7 reads exactly as before this change.
11. At 375px wide the section has no horizontal scroll: the card's top row and each "All tiers" row stack.
12. Clicking a history filter tab or page link swaps the history and leaves the tier section in place; `/my-account/my-points/2/` also shows the section.
13. End-to-end verification: on wp-env with the AC setup, open My Points as a Silver, a Gold and a tierless customer (AC 1, 2, 3, 5), switch to Rolling with a held Silver customer (AC 7), then turn tiers off (AC 8). Each page matches its criterion and Chrome's console shows no errors.

### Constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types (CLAUDE.md hard rule 4).
- Lite names Pro nowhere outside the guarded `YayLoyaltyPro::boot()` call; Pro attaches only through filters added in `boot()` (hard rule 2). The new filter, template and CSS are tier-generic Lite code.
- Storefront CSS: one new file imported by `rewards-tokens.css`, every rule under `.yayloyalty-account`, px sizes, colours from `storefront-tokens.css` tokens; nothing under `apps/admin`, no Design tab values.
- Dates are stored UTC and shown with `Dates::to_site_date()`.
- Every string translatable in the `yayloyalty` text domain, with translators comments on placeholders; all output escaped.
- Never hand-edit `assets/dist/`.

### Approach

**Chosen:** a new My Account data filter, answered by a Pro subscriber, rendered by a tier-generic Lite partial.

**Architecture:** `AccountMyPoints::view()` adds a `tier` key from a new private `member_tier( int $user_id ): ?array`, which applies `MEMBER_TIER_FILTER` inside a `try`/`catch` and validates the answer as `LauncherPayload::member_tier()` does. `account-rewards.php` requires `account-tier.php` right after `points-card.php`; the partial returns early on `null`. In Pro, `AccountTier` reads the same `TierStanding` the launcher and drawer read, so the three never disagree. The progress copy moves into `LauncherCopy` so the launcher line and the section say the same thing in the same words.

**Components:**
- `includes/Engine/Storefront/AccountMyPoints.php` [edit]: `public const MEMBER_TIER_FILTER = 'yayloyalty_account_member_tier'`; `view()` gains `tier`; private `member_tier()` and `is_member_tier()` validation.
- `includes/Engine/Storefront/templates/account-rewards.php` [edit]: `$yayloyalty_tier = $view['tier']`, require `account-tier.php` after `points-card.php`.
- `includes/Engine/Storefront/templates/account-tier.php` [new]: the section markup.
- `assets/storefront/account/account-tier.css` [new], `assets/storefront/account/rewards-tokens.css` [edit: `@import url("account-tier.css");` after `tier-progress.css`].
- `includes/Engine/Storefront/LauncherCopy.php` [edit]: `tier_progress_text( ?array $next, int $needed ): string`, `tier_progress_percent( int $progress, int $threshold ): int`, `tier_window_caption( int $window_months ): string`, `tier_multiplier( string $multiplier ): string` ("1.50" → "1.5× Points", "2.00" → "2× Points").
- `includes/Engine/Storefront/templates/tier-progress.php` [edit]: uses the first three `LauncherCopy` methods; same output.
- `includes/Pro/Storefront/AccountTier.php` [new]: `register()` adds the filter; `member_tier( $value, int $user_id ): ?array`.
- `includes/Pro/Storefront/TierProgress.php` [edit]: `line()` becomes `public static` so `AccountTier` builds on it.
- `includes/Pro/YayLoyaltyPro.php` [edit]: `( new AccountTier() )->register();` beside `TierProgress`.
- `docs/architecture.md` [edit]: one line under the My Points bullet naming the filter and `AccountTier`.

**Filter contract** (`yayloyalty_account_member_tier`, `( null, int $user_id )`, internal seam for Pro, not a public API). Return null to print nothing, or an array with:
- `name` ?string, `color` ?string (`#RRGGBB`), `multiplier` ?string (decimal ≥ `'1.00'`), `achieved_at` ?string (UTC, valid per `Dates::is_valid()`): the member's tier; all null when tierless.
- `next` ?array{name: string (non-empty), threshold: int ≥ 0}; null at the top tier.
- `progress` int ≥ 0; `needed` int ≥ 0 (0 when `next` is null).
- `window_months` ?int ≥ 1, Rolling only; `held_until` ?string UTC, only while a hold runs.
- `tiers` non-empty list of array{name: string, color: string, threshold: int ≥ 0, multiplier: string, entry_bonus: int ≥ 0, is_current: bool}, rank order; at most one `is_current`.

`AccountTier::member_tier()` returns null when `! TierEvaluator::is_enabled()` or `TierRepository::live()` is empty; otherwise `TierProgress::line( $tier, $next, $progress, $window_months, $held_until )` (with `held_until` null when tierless, as `TierProgress` does) plus `multiplier`, `achieved_at` (`Customer::get_tier_achieved_at()`, null when tierless) and `tiers` (`is_current` when the id equals the standing's tier id). The customer is `CustomerRepository::find_by_user_id()` or `new Customer( $user_id )`.

**Data and storage:** None. Read-only; no option, column, meta or migration.

**Integration points:** new filter `yayloyalty_account_member_tier` (Lite applies, Pro answers). `LauncherCopy` gains four static methods consumed by `tier-progress.php` and `account-tier.php`. `TierProgress::line()` gains `public` visibility. No REST route, AJAX action or hook removed or renamed.

**UI:** classic PHP template in the My Points tab, theme-native like the rest of it.
- `<section class="yayloyalty-account__section yayloyalty-account__tiers">` with `h3.yayloyalty-account__title` "Your tier".
- Status card (`yayloyalty-account__tiers-card`, the points card's border, radius and padding tokens):
  - top row: badge (10px round dot in the tier colour, name semibold in the text colour), or "No tier yet" when tierless; right side, a chip with `LauncherCopy::tier_multiplier()` only when the multiplier is not `'1.00'`;
  - progress text, then the bar (6px, muted track, primary fill, full radius, `role="progressbar"`, `aria-valuemin` 0, `aria-valuemax` = next threshold, `aria-valuenow` = min( progress, threshold ), `aria-label` = the progress text); no bar when `next` is null;
  - Rolling caption (12px, muted) when `window_months` is set;
  - divider, then `<dl>` rows, label left and value right: "Tier since" (`achieved_at`, hidden when null), "Tier %s" with the plural points label (`progress`, formatted like the balance), "Kept until" (`held_until`, only when set).
- "All tiers": a subheading (`h4`), then a `<ul>`; each `<li>` holds the dot and name, "From %s" (`points_text( threshold )`), and the perks: `tier_multiplier()` when not `'1.00'`, "+%s bonus" (`points_text( entry_bonus )`) when above 0, joined by " · ", or nothing. The current row gets a "You" chip, a highlighted background token and `aria-current="true"`.
- Only the dot carries the tier colour, so no contrast check is needed.
- Below 480px, the card's top row and each tier row stack (flex-wrap / column).

**Edge cases and failure modes:**
- Tierless: "No tier yet", progress to the lowest live tier, no "Tier since", no "You" row.
- Manual floor above the points: shows the floor tier and progress to the tier above it, as the drawer does; the assignment itself is not mentioned.
- Stored tier soft-deleted before the sweep re-evaluates: `TierStanding` gives `tier` null, so the customer reads as tierless.
- One live tier: one row; a tierless customer sees progress to it, a member "Top tier".
- Next threshold 0: bar at 100%, "{next} unlocks with your next earn"; no division by zero.
- Throwing subscriber or malformed answer: no section, `Log::warning( 'AccountMyPoints', … )`, rest of the tab renders.
- Program off or viewer can't earn: the existing unavailable card renders before `view()` runs, so the filter never fires.
- History pages and AJAX filter swap: the section prints on every page; the swap leaves it alone.
- Lite: no subscriber, `tier` is null, nothing prints.

**Decision record:** None. This follows the existing internal-seam pattern (`MEMBER_TIER_FILTER`, `REDEEM_OFFERS_FILTER`) and changes no architectural choice.

### Refinements of the master and of the original scope above

| Said | This amendment |
|---|---|
| Master Out: "My Account → Rewards tier section" | My Account → My Points gets a tier section. Shortcodes and blocks stay out. |
| Out, above: "My Account → My Points / Rewards … render exactly as today" | My Points gains the section; `points-card.php` itself is unchanged, so the shortcode and block still render as before. |
| Components, above: progress copy inline in `tier-progress.php` | The copy lives in `LauncherCopy`, shared with `account-tier.php`; the launcher's output is unchanged. |

### Alternatives considered

| Option | Why rejected |
|---|---|
| Add `achieved_at`, `multiplier` and `tiers` to `LauncherPayload::MEMBER_TIER_FILTER` | Every launcher render would compute and print the full tier list, which the launcher never shows. |
| Pro prints its own HTML through an action in `account-rewards.php` | Needs a second stylesheet beside the one enqueued account file, and the data can't be validated or inspected; the original scope rejected the same shape for the launcher. |
| Reuse only the launcher's `tier-progress.php` under a heading | Shows the least; the user chose the status card, details and all-tiers list. |
| Status card without the "All tiers" list | The user chose the list; it explains how to reach the next tier without a separate rules page. |
| Hide the section for tierless customers | The user chose progress to the lowest tier, matching the launcher. |
| Show "Assigned by the store until …" for a manual floor | The user chose not to mention it; it stays a staff detail in the drawer. |
| Show "1× Points" on tiers without perks | The user chose a blank perks spot. |
| "Points used" / "Points remaining" rows from the screenshot | The points card directly above already shows the balance. |
| Duplicate the progress copy in the new template | The launcher and My Points would drift; one set of `LauncherCopy` methods keeps them equal (AC 10). |
| Unit or e2e tests | CLAUDE.md Tests: not money or ledger logic, no reproduced bug. Verified manually. |

### Rollout

- No setting, flag or migration. The section appears on the first load after the update for Pro stores with tiers on and at least one live tier; never on Lite or with tiers off.
- `docs/specs/2026-11-02-tiers-master-design.md` gets an `**Amended:** 2026-11-10` line and an inline "(amended 2026-11-10: …)" marker on its Out line, pointing here; its Status does not change. This spec's Out line gets the same marker.
- The reference screenshot the user shared (`docs/image-myaccount-tiers/Tier-section.png`) was a layout example only and is not committed.

### Open questions

None.
