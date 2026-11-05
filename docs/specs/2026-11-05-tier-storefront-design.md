# Tier Storefront (Tiers slice 5) Design

**Status:** accepted 2026-11-05
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
- My Account → My Points / Rewards, `[yayloyalty_balance]`, the balance block, and every other shortcode and block (master Out list). They render exactly as today.
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
