# Tier Storefront (Tiers slice 5) Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. Read the spec linked below before starting; the plan argues from it.

**Status:** done 2026-11-05
**Amended:** 2026-11-10: Tasks 6–9 add the My Points tier section from the spec's Amendment 2026-11-10, and are done 2026-11-10. They are in the [Amendment 2026-11-10](#amendment-2026-11-10-my-points-tier-section) section at the end.
**Goal:** The launcher's member panel shows a Pro shopper's tier badge, progress to the next tier with a bar, and in Rolling the window and hold date; the Design preview shows a sample.
**Spec:** [docs/specs/2026-11-05-tier-storefront-design.md](../specs/2026-11-05-tier-storefront-design.md) (accepted 2026-11-05), a child of [the tiers master](../specs/2026-11-02-tiers-master-design.md).
**Architecture:** Lite's `LauncherPayload` applies a new internal filter for a member and stores the validated answer in `member.tier`; a Lite partial required only by `panel-member.php` prints it. Pro's `Pro\Storefront\TierProgress` answers the filter from `Pro\Services\TierStanding`, a reader extracted from the drawer route so the drawer and the launcher compute the same standing. This follows the `REDEEM_OFFERS_FILTER` / `RewardsPanel` seam rather than a Pro-printed template, so the Design preview gets the line through the same path.
**Touches:** `includes/Pro/Services/TierStanding.php` [new], `includes/Pro/Controllers/CustomerTierRestController.php`, `includes/Pro/Storefront/TierProgress.php` [new], `includes/Pro/YayLoyaltyPro.php`, `includes/Engine/Storefront/LauncherPayload.php` (shared: consumed by `Launcher`, `LauncherPreview`, and every `member` reader in the launcher templates; the change only adds the `member.tier` key), `includes/Engine/Storefront/templates/tier-progress.php` [new], `includes/Engine/Storefront/templates/panel-member.php`, `assets/storefront/account/tier-progress.css` [new], `assets/storefront/account/rewards-tokens.css` (shared: My Account, placements, launcher; one `@import` added), `docs/backlog/2026-10-06-tiers-not-built.md`, the spec and this plan (Status lines).
**Tests:** none. CLAUDE.md § Tests: this is display logic, not money or ledger logic, and no bug is reproduced; the user chose manual checks during design. Every task ends in an exact manual check.

## Global constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union types, `mixed`/`static`/`never` types, `catch` without a variable, `throw` as an expression, trailing comma after the last declared parameter, `new` in a default value, first-class callables, `$object::class` (hard rule 4, `.claude/rules/php-standards.md`). Only review catches this.
- Lite names Pro only in the guarded `YayLoyaltyPro::boot()` call. Pro attaches only through `LauncherPayload::MEMBER_TIER_FILTER`, added in `boot()` (hard rule 2). `LauncherPayload`, `tier-progress.php`, `panel-member.php` and the CSS must not name Pro.
- Storefront CSS is hand-written in `assets/storefront/account/`, every rule nested under `.yayloyalty-account`, px sizes, colours from `storefront-tokens.css` / the `--yayloyalty-account-*` tokens. Nothing under `apps/admin/`, no `yl:` classes, no Design tab values (`UiClasses`, `Launcher::theme_vars()`, `DesignHelper`).
- Dates are stored and passed in UTC (`Utils\Dates`) and printed with `Dates::to_site_date()`.
- Strings use the `yayloyalty` text domain, with a `translators:` comment on every string with a placeholder. Output is escaped (`esc_html`, `esc_attr`).
- Every hook the plugin fires has a docblock. New Pro hooks attach in a `register()` method that `YayLoyaltyPro::boot()` calls once.
- Logging only through `Log::warning( 'LauncherPayload', … )`.
- No tests are added (see **Tests**).
- **Lint only changed files.** PHP: `composer lint -- <files>`. Expected: no error or warning in those files. A whole-repo run fails on `dev/edd` too (docs/known-issues.md).
- **Shorthands** (as in the slice 4 plan):
  - `WP` means `pnpm --filter @yayloyalty/e2e exec wp-env run tests-cli wp`. The site is http://localhost:8889 with the `pro` SPA built (`./scripts/run.sh build pro`) or `./scripts/run.sh dev pro` running.
  - `REST <METHOD> <route> [json]` means `$WP eval 'wp_set_current_user(1); $q = new WP_REST_Request("<METHOD>", "/yayloyalty/v1<route>"); $q->set_header("content-type","application/json"); $q->set_body(<json or "">); $r = rest_do_request($q); echo wp_json_encode([$r->get_status(), $r->get_data()]);'`.
  - `PAYLOAD <user id>` means `$WP eval 'echo wp_json_encode(YayLoyalty\Engine\Storefront\LauncherPayload::for_user(YayLoyalty\Helpers\DesignHelper::get_design(), <user id>)["member"]);'`.
  - `RUN` means `$WP eval 'do_action("yayloyalty_tier_run");'`, repeated until `REST GET /tiers/settings` shows `"sweep":null`.
- **Branch:** all work is on `jason/feat/tiers`. Commit only when the user asks. Stage by name, use a Conventional Commits message, and end it with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review focus

- **The drawer card changing after the extraction.** Expected: `GET /customers/{id}/tier` returns byte-identical JSON for a Lifetime customer, a Rolling customer on hold, a tierless customer and one with a manual floor (spec AC 9). Owned by Task 1, Steps 1 and 3.
- **A malformed or throwing subscriber breaking the launcher.** Expected: `member.tier` is null, one `Log::warning` from `LauncherPayload`, the rest of the panel renders. Owned by Task 2, Step 4.
- **The line leaking outside the launcher.** Expected: My Account → My Points and `[yayloyalty_balance]` HTML unchanged (spec AC 7). Owned by Task 3, Step 1 and Step 5.
- **Division and overflow in the bar.** Expected: a `next.threshold` of 0 gives 100%, and progress past the threshold caps at 100% with `aria-valuenow` = threshold (spec AC 4). Owned by Task 3, Step 4.
- **Tiers off or Lite still printing.** Expected: no `yayloyalty-account__tier` in the HTML (spec AC 6). Owned by Task 3, Step 4, and Task 5, Step 2.
- **The preview showing a past-the-top line.** Expected: seed tiers preview Bronze, "400 Points to Silver", 50% (spec AC 8). Owned by Task 4, Step 2.

---

### Task 1: Extract `TierStanding` from the drawer route

**Files:**
- Create: `includes/Pro/Services/TierStanding.php`
- Modify: `includes/Pro/Controllers/CustomerTierRestController.php` (`respond()`, about lines 139–203)

**Interfaces:**
- Consumes: `TierRepository::live(): Tier[]` (rank order), `LedgerRepository::window_total( int $user_id, string $from, string $to ): int`, `TierEvaluator::mode()`, `TierEvaluator::rolling_months()`, `TierMode::ROLLING`, `Dates::add_months()`, `Customer` getters (`get_tier_id`, `get_tier_progress_value`, `get_lifetime_earned`, `get_tier_held_until`, `get_tier_held_from`, `get_user_id`).
- Produces: `final class TierStanding` with
  - `public function __construct( LedgerRepository $ledger )`
  - `public function of( Customer $customer, array $live, string $now ): array` returning `array{tier: ?Tier, next: ?Tier, progress: int, mode: string, window_months: ?int, held_from: ?string, held_until: ?string}`.

- [ ] **Step 1: Capture the drawer baseline before touching code**

On wp-env, prepare four customers and save their `REST GET /customers/<id>/tier` output to `$SCRATCH/drawer-before-<id>.json` (`$SCRATCH` = this session's scratchpad directory):
  - A: Lifetime, Silver (`REST POST /customers/<A>/adjust '{"points":1000,"note":"plan","adjust_lifetime":true}'` with tiers on).
  - B: tierless: a customer with no ledger row and Bronze's threshold raised to 500 via `REST PUT /tiers/<bronze id> '{"threshold":500}'`, then `RUN`.
  - C: manual floor: `REST POST /customers/<C>/tier '{"tier_id":<gold id>,"days":30}'`.
  - D: Rolling on hold: `REST PUT /tiers/settings '{"tiers_mode":"rolling","tiers_rolling_months":6}'`, `RUN`, then capture D (a Silver customer, so it has `held_until`). Capture D last and switch back to Lifetime (`REST PUT /tiers/settings '{"tiers_mode":"lifetime"}'`, `RUN`) only after Step 3.

Expected: four files, each `[200, {...}]`; D's has non-null `held_until` and `window_months` 6.

- [ ] **Step 2: Implement `TierStanding::of()` and call it from `respond()`**

Move the `tier` / `next` / `progress` / mode / hold logic from `respond()` into `of()` unchanged, including the Lifetime fallback (`tier_progress_value`, else `lifetime_earned`) and "hold dates only while `held_until > $now`". The class docblock says the drawer route and `TierProgress` share it so they can't drift. `respond()` builds `new TierStanding( $this->ledger )`, calls `of( $customer, $this->tiers->live(), Dates::now() )`, maps `tier`/`next` to its existing arrays, and keeps `achieved_at` and `manual()` as they are. The response docblock stays as it is.

- [ ] **Step 3: Verify the drawer is unchanged**

Run: `REST GET /customers/<id>/tier` for A, B, C, D (D while still in Rolling), and `diff` each against its `drawer-before-<id>.json`.
Expected: no diff for any of the four (spec AC 9). Then open the drawer for D in wp-admin → Customers: the tier card shows the hold line and "/ … in the last 6 months" as before. Then switch back to Lifetime and `RUN`.

- [ ] **Step 4: Lint**

Run: `composer lint -- includes/Pro/Services/TierStanding.php includes/Pro/Controllers/CustomerTierRestController.php`
Expected: no error or warning.

- [ ] **Step 5: Commit**

Stage: `includes/Pro/Services/TierStanding.php`, `includes/Pro/Controllers/CustomerTierRestController.php`
Message: `refactor(tiers): read a customer's tier standing in one place`

### Task 2: The member-tier seam and Pro's live answer

**Files:**
- Modify: `includes/Engine/Storefront/LauncherPayload.php`
- Create: `includes/Pro/Storefront/TierProgress.php`
- Modify: `includes/Pro/YayLoyaltyPro.php` (beside `( new RewardsPanel() )->register();`, line 113)

**Interfaces:**
- Consumes: Task 1's `TierStanding::of()`; `TierEvaluator::is_enabled()`; `CustomerRepository::find_by_user_id()`.
- Produces:
  - `LauncherPayload::MEMBER_TIER_FILTER = 'yayloyalty_launcher_member_tier'`, applied as `apply_filters( self::MEMBER_TIER_FILTER, null, ?int $user_id, int $balance )`, docblocked as an internal Pro seam with the shape from the spec's Integration points (`name`, `color`, `next{name, threshold}`, `progress`, `needed`, `window_months`, `held_until`).
  - `$payload['member']['tier']`: `?array` of that shape. Present (possibly null) whenever `member` isn't null.
  - `final class TierProgress` with `register(): void` and `member_tier( $value, ?int $user_id, int $balance ): ?array`.

- [ ] **Step 1: Add the seam to `LauncherPayload`**

Add the constant and `private static function member_tier( ?int $user_id, int $balance ): ?array`: `try`/`catch ( \Throwable $e )` around `apply_filters` with `Log::warning( 'LauncherPayload', 'member tier failed: ' . $e->getMessage() )`, then the spec's validation (every rule in the spec's "Lite's validation" line); a value that fails becomes null with `Log::warning( 'LauncherPayload', 'member tier malformed' )`, and a plain null passes silently. In `for_balance()`, after building `member`, set `$member['tier'] = self::member_tier( $user_id, $balance )` when `member` isn't null. Extend `member()`'s `@return` with `tier: ?array` (filled by `for_balance()`).

- [ ] **Step 2: Implement `TierProgress::member_tier()` for a live user**

`$user_id` int: return null when `! TierEvaluator::is_enabled()` or `( new TierRepository() )->live()` is `[]`; else the customer is `find_by_user_id( $user_id ) ?? new Customer( $user_id )`, and the result maps `( new TierStanding( new LedgerRepository() ) )->of( $customer, $live, Dates::now() )`: `name`/`color` from `tier` (null when tierless), `next` as `['name' => …, 'threshold' => …]` or null, `progress`, `needed` = `null === next ? 0 : max( 0, next.threshold - progress )`, `window_months`, and `held_until` only when `tier` isn't null. `$user_id` null: return `$value` for now (Task 4 fills it). Register it in `register()` with priority 10 and 3 args, and call `( new TierProgress() )->register()` in `boot()`.

- [ ] **Step 3: Verify the payload**

With tiers on, Lifetime, seed thresholds (Bronze back to 0: `REST PUT /tiers/<bronze id> '{"threshold":0}'`, `RUN`):
Run: `PAYLOAD <A>` (Silver at 1,000).
Expected: `"tier":{"name":"Silver","color":"#C0C0C0","next":{"name":"Gold","threshold":3000},"progress":1000,"needed":2000,"window_months":null,"held_until":null}`.
Run: `REST PUT /tiers/settings '{"tiers_enabled":false}'`, then `PAYLOAD <A>`.
Expected: `"tier":null`. Turn tiers back on.

- [ ] **Step 4: Verify a broken subscriber is contained**

Run: `$WP eval 'add_filter("yayloyalty_launcher_member_tier", function(){ throw new Exception("boom"); }, 99); echo wp_json_encode(YayLoyalty\Engine\Storefront\LauncherPayload::for_user(YayLoyalty\Helpers\DesignHelper::get_design(), <A>)["member"]);'`, then the same with `function(){ return ["name" => 5]; }`.
Expected: both print `"tier":null` with the rest of `member` intact, and the WooCommerce log (`yayloyalty` source) gains "member tier failed: boom" and "member tier malformed".

- [ ] **Step 5: Lint**

Run: `composer lint -- includes/Engine/Storefront/LauncherPayload.php includes/Pro/Storefront/TierProgress.php includes/Pro/YayLoyaltyPro.php`
Expected: no error or warning.

- [ ] **Step 6: Commit**

Stage: `includes/Engine/Storefront/LauncherPayload.php`, `includes/Pro/Storefront/TierProgress.php`, `includes/Pro/YayLoyaltyPro.php`
Message: `feat(tiers): pass the member's tier standing to the launcher`

### Task 3: The launcher tier line

**Files:**
- Create: `includes/Engine/Storefront/templates/tier-progress.php`
- Modify: `includes/Engine/Storefront/templates/panel-member.php`
- Create: `assets/storefront/account/tier-progress.css`
- Modify: `assets/storefront/account/rewards-tokens.css`

**Interfaces:**
- Consumes: Task 2's `$view['member']['tier']`.
- Produces: the `.yayloyalty-account__tier` section and its element classes `__tier-badge`, `__tier-dot`, `__tier-text`, `__tier-bar`, `__tier-fill`, `__tier-caption`.

- [ ] **Step 1: Capture the HTML that must not change**

Before editing, as customer A, save the HTML of My Account → My Points and of a page holding `[yayloyalty_balance]` (`$WP post create --post_type=page --post_status=publish --post_title=Balance --post_content='[yayloyalty_balance]'`). Use `curl` with A's logged-in cookie or the browser's "Save page" to `$SCRATCH/account-before.html` and `$SCRATCH/balance-before.html`.
Expected: two files containing `yayloyalty-account__card`.

- [ ] **Step 2: Write `tier-progress.php` and require it**

The markup, text, bar and caption rules are the spec's **UI** section, verbatim: badge row only when `name` isn't null; "Top tier" / "%1$s to %2$s" (via `SettingsHelper::points_text( needed )`) / "%s unlocks with your next earn"; the bar only when `next` isn't null, with `pct` = `0 === threshold ? 100 : min( 100, intdiv( progress * 100, threshold ) )` and `aria-valuenow` = `min( progress, threshold )`; the caption only when `window_months` isn't null, the hold sentence only when `held_until` isn't null. The file docblock names `$view` as its input and `tier-progress.css` as its stylesheet. In `panel-member.php`, `require __DIR__ . '/tier-progress.php';` right after `points-card.php`, and the docblock's partial list adds it.

- [ ] **Step 3: Write `tier-progress.css` and import it**

Per the spec's CSS line: the section a column with an 8px gap, 16px padding, `1px solid var(--yayloyalty-account-border)`, `var(--yayloyalty-account-radius)`; the dot 10px round; the name semibold in `--yayloyalty-account-text`; the bar 6px high, track `--yayloyalty-account-icon-bg`, fill `--yayloyalty-account-accent` (the launcher panel re-points it to the brand colour), radius 999px; the caption 12px in `--yayloyalty-account-muted`. Every selector starts with `.yayloyalty-account`. Add `@import url("tier-progress.css");` after `points-card.css` in `rewards-tokens.css`.

- [ ] **Step 4: Verify the panel**

Open http://localhost:8889 as each customer and open the launcher. Expected:
  - A (Silver, 1,000): Silver badge with a `#C0C0C0` dot, "2,000 Points to Gold", bar 33% (spec AC 1).
  - A Gold customer (`REST POST /customers/<id>/adjust '{"points":3000,"note":"plan","adjust_lifetime":true}'`): Gold badge, "Top tier", no bar (AC 2).
  - B with Bronze at 500 (as in Task 1): no badge, "500 Points to Bronze", bar 0% (AC 3).
  - A after `$WP db query "UPDATE wp_yayloyalty_customers SET tier_progress_value=3050 WHERE user_id=<A>"`: "Gold unlocks with your next earn", bar 100%, `aria-valuenow="3000"` (AC 4). Then set it back to 1000 with the same query.
  - Rolling 6 months, customer D held: caption "Counts Points earned in the last 6 months. Silver kept until {date}" in the site date format (AC 5). Switch back to Lifetime afterwards.
  - Tiers off, and signed out: view source has no `yayloyalty-account__tier` (AC 6).
  - The browser console shows no error, and the narrow (375px) panel shows no overflow.

- [ ] **Step 5: Verify nothing else changed**

Re-save the two pages from Step 1 as `*-after.html` and `diff` them against the `*-before.html` files, ignoring nonce and asset `?ver=` values (`diff <(sed -E 's/(nonce|ver)=[^"&]*//g' a) <(sed -E 's/(nonce|ver)=[^"&]*//g' b)`).
Expected: no diff, and no `yayloyalty-account__tier` in either (spec AC 7).

- [ ] **Step 6: Lint**

Run: `composer lint -- includes/Engine/Storefront/templates/tier-progress.php includes/Engine/Storefront/templates/panel-member.php`
Expected: no error or warning.

- [ ] **Step 7: Commit**

Stage: `includes/Engine/Storefront/templates/tier-progress.php`, `includes/Engine/Storefront/templates/panel-member.php`, `assets/storefront/account/tier-progress.css`, `assets/storefront/account/rewards-tokens.css`
Message: `feat(tiers): show the tier badge and progress in the launcher`

### Task 4: The Design preview sample

**Files:**
- Modify: `includes/Pro/Storefront/TierProgress.php`

**Interfaces:**
- Consumes: Task 2's `member_tier()`; `LauncherPreview::documents()` already calls `for_balance()` with no user id, so nothing in Lite changes.
- Produces: the sample answer for `$user_id` null.

- [ ] **Step 1: Return the sample for `$user_id` null**

Same two null conditions as live. Otherwise: `tier` = the first of `live()`, `next` = the second or null; `progress` = `intdiv( tier.threshold + next.threshold, 2 )`, or `tier.threshold` with no next; `needed` as live; in Rolling `window_months` = `TierEvaluator::rolling_months()` and `held_until` = `Dates::add_months( Dates::now(), window_months )`. `$balance` is ignored; the method docblock says why (the sample balance 9,850 sits past Gold on the seed tiers).

- [ ] **Step 2: Verify the preview**

Open wp-admin → YayLoyalty → Design, switch the preview to the signed-in shopper, and open the panel. Expected (seed tiers, Bronze at 0, Lifetime): Bronze badge, "400 Points to Silver", bar 50%.
Run: `$WP eval 'echo wp_json_encode((new YayLoyalty\Pro\Storefront\TierProgress())->member_tier(null, null, 9850));'`
Expected: `{"name":"Bronze","color":"#CD7F32","next":{"name":"Silver","threshold":800},"progress":400,"needed":400,"window_months":null,"held_until":null}`.
One tier: note the live Silver and Gold ids from `REST GET /tiers`, create a scratch site state with `$WP db query "UPDATE wp_yayloyalty_tiers SET deleted_at=UTC_TIMESTAMP() WHERE id IN (<silver id>,<gold id>)"`, rerun the eval, expect `"next":null,"progress":0,"needed":0`, then restore with `... SET deleted_at=NULL WHERE id IN (<silver id>,<gold id>)`. Restore by id, never by name: older soft-deleted rows can share the name, and undeleting them lets the next sweep move customers onto them. (A direct query, not `DELETE /tiers`, so no sweep runs and nothing is re-evaluated.)
In Rolling, the preview adds the caption with a date six months ahead. With tiers off, the preview shows no tier line (spec AC 8).

- [ ] **Step 3: Lint and commit**

Run: `composer lint -- includes/Pro/Storefront/TierProgress.php`. Expected: no error or warning.
Stage: `includes/Pro/Storefront/TierProgress.php`
Message: `feat(tiers): preview a sample tier line in the Design tab`

### Task 5: Lite check, backlog, end to end

**Files:**
- Modify: `docs/backlog/2026-10-06-tiers-not-built.md`
- Modify: `docs/plans/2026-11-05-tier-storefront.md` (Status). The spec stays `accepted`.

**Interfaces:**
- Consumes: Tasks 1–4.
- Produces: none.

- [ ] **Step 1: Update the backlog**

Per the spec's Rollout: add "The launcher's member panel shows the tier badge and progress to the next tier, with the window and hold in Rolling." to **Today**; reword the storefront box to "The launcher shows the tier badge and progress to the next tier." and tick it; drop the bullet that restores `tier.badges`, `storefront_text.tier_progress_line` and `panel.show_progress_to_next_tier`. The tier emails box stays open, so the file stays.

- [ ] **Step 2: Verify Lite**

Run: `./scripts/run.sh release pro --no-prompt`, then `./scripts/run.sh release lite --no-prompt`, then `composer install`.
Expected: both validate. `grep -r "TierProgress\|TierStanding" ` over the unzipped Lite build finds nothing, and the Lite zip contains `tier-progress.php` and `tier-progress.css`, which print nothing with no subscriber (spec AC 6).

- [ ] **Step 3: End to end**

On wp-env Pro, tiers on, Lifetime, seed tiers with Bronze at 0, Silver's bonus at 100 (`REST PUT /tiers/<silver id> '{"entry_bonus":100}'`), the default purchase campaign at 1 pt/unit: a new customer completes an 800-unit order (create and complete it in WooCommerce → Orders). Open the launcher as that customer.
Expected: the Silver badge and "2,100 Points to Gold" with the bar at 30% (900 earned; spec AC 10, master AC 22.3). The console shows no error.

- [ ] **Step 4: Mark done and commit**

Set this plan's Status to `done YYYY-MM-DD` (the day the PR lands is the user's; use the day the last check passed).
Stage: `docs/backlog/2026-10-06-tiers-not-built.md`, `docs/specs/2026-11-05-tier-storefront-design.md`, `docs/plans/2026-11-05-tier-storefront.md`
Message: `docs(tiers): add the tier storefront spec and plan, marked done, and update the tiers backlog`

---

## Amendment 2026-11-10: My Points tier section

Tasks 6–9 implement [the spec's Amendment 2026-11-10](../specs/2026-11-05-tier-storefront-design.md#amendment-2026-11-10-my-points-tier-section), accepted 2026-11-10. They are done (see the `**Amended:**` line). Tasks 1–5 above are unchanged. In Tasks 6–9, "spec AC n" means the amendment's own acceptance criteria, not those of the original spec.

- **Goal:** My Account → My Points shows a Pro customer a "Your tier" section under the points card: status card, details list and an "All tiers" list.
- **Architecture:** Lite's `AccountMyPoints::view()` applies a new internal filter, validates the answer and hands it to a tier-generic partial, `account-tier.php`. Pro's `Pro\Storefront\AccountTier` answers from `TierStanding`, building on `TierProgress::line()`, so the launcher, the drawer and My Points read one standing. The progress copy moves into `LauncherCopy` first, so the launcher line and the section share their sentences. A Pro-printed template was rejected in the spec (no validation, a second stylesheet).
- **Touches:** `includes/Engine/Storefront/LauncherCopy.php` (shared: every launcher, My Points and placement template reads it; this only adds methods), `includes/Engine/Storefront/templates/tier-progress.php` (launcher only), `includes/Engine/Storefront/AccountMyPoints.php`, `includes/Engine/Storefront/templates/account-rewards.php`, `includes/Engine/Storefront/templates/account-tier.php` [new], `assets/storefront/account/account-tier.css` [new], `assets/storefront/account/rewards-tokens.css` (shared: My Points, placements, launcher, Design preview; one `@import` added), `includes/Pro/Storefront/AccountTier.php` [new], `includes/Pro/Storefront/TierProgress.php`, `includes/Pro/YayLoyaltyPro.php`, `docs/architecture.md`, the master spec (amendment note), `docs/specs/2026-11-05-tier-storefront-design.md` (amendment), this plan (amendment).
- **Tests:** none. CLAUDE.md § Tests: display logic, not money or ledger logic, and no reproduced bug; the spec chose manual checks. Every task ends in an exact manual check.
- **How it was verified:** wp-env's seed failed, so the checks ran on the local Pro site with its own tiers (Bronze 0, Gold 3,000 ×1.5 +100, Platinum 6,000 ×2 +50) and in-process fixtures for the AC values; nothing in the site's settings or data was changed. A plan review flagged that the filter answer's validation skipped the `#RRGGBB` colour and the multiplier minimum; both are enforced, and the second review passed.

### Amendment global constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union types, `mixed`/`static`/`never` types, `catch` without a variable, `throw` as an expression, trailing comma after the last declared parameter, `new` in a default value, first-class callables (hard rule 4). Only review catches this.
- Lite names Pro only in the guarded `YayLoyaltyPro::boot()` call; Pro attaches only through filters added in `boot()` (hard rule 2). `AccountMyPoints`, `LauncherCopy`, `account-tier.php`, `account-rewards.php` and the CSS must not name Pro.
- Storefront CSS is hand-written in `assets/storefront/account/`, imported by `rewards-tokens.css` and never enqueued on its own, every rule nested under `.yayloyalty-account`, px sizes, colours from the `--yayloyalty-account-*` tokens in `storefront-tokens.css`. Nothing under `apps/admin/`, no `yl:` classes, no Design tab values.
- Dates are stored and passed in UTC (`Utils\Dates`) and printed with `Dates::to_site_date()`.
- Strings use the `yayloyalty` text domain, a `translators:` comment on every placeholder, and escaped output (`esc_html`, `esc_attr`).
- Every hook the plugin fires has a docblock. The new Pro subscriber attaches in a `register()` that `YayLoyaltyPro::boot()` calls once.
- Logging only through `Log::warning( 'AccountMyPoints', … )` for the new seam.
- No tests are added (see **Tests**).
- **Lint only changed files.** `composer lint -- <files>`. Expected: no error or warning in those files. A whole-repo run fails on `dev/edd` too (docs/known-issues.md).
- **Shorthands:**
  - `WP` means `pnpm --filter @yayloyalty/e2e exec wp-env run tests-cli wp` against http://localhost:8889 with the `pro` SPA built. If `env:start`'s seed fails ("One of the redemption values is not a valid number."), use the local Pro site's `wp` instead, with `YAYLOYALTY_TEST_SOCKET` set as in `tests/integration/bootstrap.php`'s header; the expected results are the same.
  - `TIERS` means the spec's AC setup: Bronze threshold 500, multiplier 1.00, bonus 0, colour `#CD7F32`; Silver 1,000, 1.50, 0; Gold 3,000, 2.00, 200; tiers on, Lifetime. Set through wp-admin → Tiers, or `REST PUT /tiers/<id>` and `REST PUT /tiers/settings` as in the slice 5 plan.
  - `LINE <user id>` means `$WP eval 'wp_set_current_user(<id>); $yayloyalty_member = YayLoyalty\Engine\Storefront\LauncherPayload::for_user(YayLoyalty\Helpers\DesignHelper::get_design(), <id>)["member"]; include WP_PLUGIN_DIR . "/YayLoyalty/includes/Engine/Storefront/templates/tier-progress.php";'` (the launcher's tier line HTML).
  - `TAB <user id>` means `$WP eval 'wp_set_current_user(<id>); YayLoyalty\Engine\Storefront\AccountMyPoints::render();'` (the My Points tab HTML).
  - `$SCRATCH` is the session's scratchpad directory.
- **Branch:** `jason/feat/tiers`. Commit only when the user asks. Stage by name, Conventional Commits, ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

### Amendment review focus

- **The launcher line changing after the copy moves.** Expected: `LINE` HTML byte-identical for a Silver, a Gold, a tierless, a past-the-threshold and a Rolling-held customer (spec AC 10). Owned by Task 6, Steps 1 and 3.
- **A throwing or malformed subscriber breaking the tab.** Expected: no section, one `Log::warning` from `AccountMyPoints`, the rest of the tab renders (spec AC 9). Owned by Task 7, Step 6.
- **The section showing with tiers off, no live tiers, or on Lite.** Expected: no `yayloyalty-account__tiers` in `TAB` HTML (spec AC 8). Owned by Task 8, Step 5.
- **Bar maths at the edges.** Expected: next threshold 0 → 100% and "… unlocks with your next earn"; progress past the threshold caps at 100% with `aria-valuenow` = threshold (spec AC 6). Owned by Task 7, Step 5, and Task 8, Step 4.
- **The multiplier chip on a 1× tier.** Expected: no chip for Bronze (1.00), "1.5× Points" for Silver, "2× Points" for Gold (spec AC 1, 3, 4). Owned by Task 8, Step 4.
- **Narrow screens.** Expected: no horizontal scroll at 375px (spec AC 11). Owned by Task 9, Step 3.
- **History AJAX swap removing the section.** Expected: the section stays after a filter click and on page 2 (spec AC 12). Owned by Task 9, Step 3.

### Task 6: Move the tier progress copy into `LauncherCopy`

**Files:**
- Modify: `includes/Engine/Storefront/LauncherCopy.php`
- Modify: `includes/Engine/Storefront/templates/tier-progress.php`

**Interfaces:**
- Consumes: `SettingsHelper::points_text( int ): string`, `SettingsHelper::points_label_plural(): string`.
- Produces, all `public static` on `LauncherCopy`:
  - `tier_progress_text( ?array $next, int $needed ): string`: `$next` is `?array{name: string, threshold: int}`. "Top tier" when `$next` is null; "%1$s to %2$s" (`points_text( $needed )`, next name) when `$needed > 0`; else "%s unlocks with your next earn". Same strings and translators comments as `tier-progress.php` today.
  - `tier_progress_percent( int $progress, int $threshold ): int`: 100 when `$threshold` is 0, else `min( 100, intdiv( $progress * 100, $threshold ) )`.
  - `tier_window_caption( int $window_months ): string`: "Counts %1$s earned in the last %2$d months." with `points_label_plural()`.
  - `tier_multiplier( string $multiplier ): string`: the decimal without trailing zeros or a trailing point, then "%1$s× %2$s" with `points_label_plural()` ("1.50" → "1.5× Points", "2.00" → "2× Points", "1.25" → "1.25× Points"). Translators comment: 1: multiplier number, 2: the store's points label.

- [ ] **Step 1: Capture the launcher baseline**

With `TIERS`, prepare customers: S (Silver, tier progress 1,000), G (Gold), N (tierless, 0 earned), P (Silver, progress 3,050), and R (Rolling 6 months, Silver, held until a future date: switch to Rolling, run the sweep as in the slice 5 plan). Save `LINE <id>` for S, G, N, P to `$SCRATCH/line-before-<id>.html`, and R's while Rolling.
Expected: five non-empty files; R's contains "Counts Points earned in the last 6 months." and "kept until".

- [ ] **Step 2: Add the four methods and use the first three in `tier-progress.php`**

The template keeps its markup and the held "%1$s kept until %2$s." sentence; only the text, percentage and caption base come from `LauncherCopy`. Update the template docblock to say the copy is shared with `account-tier.php`.

- [ ] **Step 3: Verify the launcher is unchanged**

Run `LINE <id>` for S, G, N, P (Lifetime) and R (Rolling), `diff` each against its baseline.
Expected: no diff (spec AC 10). Then `$WP eval 'echo YayLoyalty\Engine\Storefront\LauncherCopy::tier_multiplier("1.50"), "|", YayLoyalty\Engine\Storefront\LauncherCopy::tier_multiplier("2.00"), "|", YayLoyalty\Engine\Storefront\LauncherCopy::tier_multiplier("1.25");'` prints `1.5× Points|2× Points|1.25× Points`.

- [ ] **Step 4: Lint**

Run: `composer lint -- includes/Engine/Storefront/LauncherCopy.php includes/Engine/Storefront/templates/tier-progress.php`
Expected: no error or warning.

- [ ] **Step 5: Commit**

Stage: `includes/Engine/Storefront/LauncherCopy.php`, `includes/Engine/Storefront/templates/tier-progress.php`
Message: `refactor(storefront): share the tier progress copy`

### Task 7: The My Points tier section in Lite

**Files:**
- Modify: `includes/Engine/Storefront/AccountMyPoints.php` (`view()`, new private methods)
- Modify: `includes/Engine/Storefront/templates/account-rewards.php`
- Create: `includes/Engine/Storefront/templates/account-tier.php`
- Create: `assets/storefront/account/account-tier.css`
- Modify: `assets/storefront/account/rewards-tokens.css` (`@import url("account-tier.css");` right after `tier-progress.css`)

**Interfaces:**
- Consumes: Task 6's `LauncherCopy` methods, `LauncherCopy::points( int )`, `SettingsHelper::points_text()`, `SettingsHelper::points_label_plural()`, `Dates::to_site_date()`, `Dates::is_valid()`, `Log::warning()`.
- Produces:
  - `AccountMyPoints::MEMBER_TIER_FILTER = 'yayloyalty_account_member_tier'`, applied as `apply_filters( MEMBER_TIER_FILTER, null, int $user_id )`, with the docblock listing the spec's **Filter contract** keys and types and "Internal seam for Pro, not a public API".
  - `view()` return type gains `tier: ?array` (the validated answer, or null).
  - private `member_tier( int $user_id ): ?array`: `try`/`catch ( \Throwable $e )` around the filter, `Log::warning( 'AccountMyPoints', 'member tier failed: ' . … )` → null; a non-null answer that fails validation → `Log::warning( 'AccountMyPoints', 'member tier malformed' )` → null; else the answer reduced to the contract's keys.
  - private static `is_member_tier( $tier ): bool`: every contract key present with its type and bounds (spec **Filter contract**), `tiers` a non-empty list whose rows all have the six keys with their types, and at most one `is_current`.
  - `account-tier.php` reads `$yayloyalty_tier` (set in `account-rewards.php` from `$view['tier']`) and returns early on null.

- [ ] **Step 1: Add the filter, `member_tier()` and `is_member_tier()` to `AccountMyPoints`, and `tier` to `view()`**

- [ ] **Step 2: Write `account-tier.php` and require it after `points-card.php` in `account-rewards.php`**

Markup and classes as the spec's **UI** section: `section.yayloyalty-account__section.yayloyalty-account__tiers`, `h3.yayloyalty-account__title` "Your tier", card `yayloyalty-account__tiers-card` (top row with badge or "No tier yet" and the multiplier chip only when `(float) multiplier > 1`; progress text; progressbar only when `next` is set, `aria-valuenow` = min( progress, threshold ); caption when `window_months` is set; divider; `<dl>` with "Tier since" when `achieved_at` is set, "Tier %s" (plural points label) = `LauncherCopy::points( progress )`, "Kept until" when `held_until` is set), then `h4` "All tiers" and a `<ul>` of rows (dot, name, "From %s", perks joined by " · ", "You" chip and `aria-current="true"` on the current row). The file docblock names `AccountMyPoints::MEMBER_TIER_FILTER` and the CSS file. Update `account-rewards.php`'s docblock to list the section.

- [ ] **Step 3: Write `account-tier.css` and import it**

Per the spec's **UI**: card uses the points card's border, radius and padding tokens; 10px round dot; chip with `--yayloyalty-account-subtle` background and `--yayloyalty-account-radius-sm`; 6px bar with `--yayloyalty-account-icon-bg` track and `--yayloyalty-account-accent` fill; 12px muted caption; `<dl>` rows as a two-column flex row with the value right-aligned; current tier row on `--yayloyalty-account-subtle`. `@media (max-width: 479px)`: the card's top row and each tier row wrap to a column. File header comment as `tier-progress.css`'s.

- [ ] **Step 4: Verify nothing prints without a subscriber**

Run: `TAB <S>` (Task 6's Silver customer; Pro's `AccountTier` doesn't exist yet).
Expected: no `yayloyalty-account__tiers` in the HTML; the points card, ways lists and history all print.

- [ ] **Step 5: Verify the section from a fixture subscriber**

Create a scratch mu-plugin `yl-tier-fixture.php` in the site's `wp-content/mu-plugins/` (outside the repo, never staged) that adds a filter on `yayloyalty_account_member_tier` (priority 10, 2 args) returning the case named by the option `yl_tier_fixture`:
  - `silver`: the spec AC 1–2 values (name Silver, color `#C0C0C0`, multiplier "1.50", achieved_at "2026-10-26 08:00:00", next {Gold, 3000}, progress 1000, needed 2000, window_months null, held_until null, tiers Bronze/Silver/Gold with Silver `is_current`);
  - `zero`: next {Bronze, 0}, progress 0, needed 0, name/color/multiplier/achieved_at null, no `is_current`.
Run `TAB <S>` with each.
Expected, `silver`: "Your tier", Silver badge, "1.5× Points", "2,000 Points to Gold", `aria-valuenow="1000" aria-valuemax="3000"`, width 33%, "Tier since" with 2026-10-26 in the site format, "Tier Points" 1,000, no "Kept until"; rows "From 500 Points" (no perks), Silver with "You" and `aria-current="true"`, Gold "2× Points · +200 Points bonus". `zero`: "No tier yet", no chip, "Bronze unlocks with your next earn", width 100%, no "Tier since", no "You".

- [ ] **Step 6: Verify the failure paths**

With the fixture set to `throw` (throws a `RuntimeException`) and then `malformed` (the `silver` array without `tiers`), run `TAB <S>` and read the plugin log.
Expected: no section either time, the points card and history still print, and one `AccountMyPoints` warning each ("member tier failed: …", "member tier malformed"). Delete the mu-plugin and the option.

- [ ] **Step 7: Lint**

Run: `composer lint -- includes/Engine/Storefront/AccountMyPoints.php includes/Engine/Storefront/templates/account-rewards.php includes/Engine/Storefront/templates/account-tier.php`
Expected: no error or warning.

- [ ] **Step 8: Commit**

Stage: `includes/Engine/Storefront/AccountMyPoints.php`, `includes/Engine/Storefront/templates/account-rewards.php`, `includes/Engine/Storefront/templates/account-tier.php`, `assets/storefront/account/account-tier.css`, `assets/storefront/account/rewards-tokens.css`
Message: `feat(storefront): add a tier section seam to My Points`

### Task 8: Pro answers the My Points tier filter

**Files:**
- Create: `includes/Pro/Storefront/AccountTier.php`
- Modify: `includes/Pro/Storefront/TierProgress.php` (`line()` visibility and docblock)
- Modify: `includes/Pro/YayLoyaltyPro.php` (boot, after `TierProgress`)

**Interfaces:**
- Consumes: `AccountMyPoints::MEMBER_TIER_FILTER` (Task 7), `TierStanding::of()`, `TierRepository::live()`, `TierEvaluator::is_enabled()`, `CustomerRepository::find_by_user_id()`, `Customer::get_tier_achieved_at()`, `Tier` getters.
- Produces:
  - `TierProgress::line( ?Tier $tier, ?Tier $next, int $progress, ?int $window_months, ?string $held_until ): array` becomes `public static`; its docblock names `AccountTier` as the second caller.
  - `final class AccountTier` with `register(): void` (adds the filter, priority 10, 2 args) and `member_tier( $value, int $user_id ): ?array` returning the spec's **Filter contract**: null when `! TierEvaluator::is_enabled()` or `live()` is empty; else `TierProgress::line( $tier, $next, $progress, $window_months, null === $tier ? null : $held_until )` plus `multiplier` (`$tier->get_multiplier()` or null), `achieved_at` (`$customer->get_tier_achieved_at()` when `$tier` is set, else null) and `tiers` (every live tier in rank order; `is_current` when its id equals `$tier`'s). The customer is `find_by_user_id( $user_id ) ?? new Customer( $user_id )`; `$now` is `Dates::now()`.

- [ ] **Step 1: Make `TierProgress::line()` public and add `AccountTier`**

- [ ] **Step 2: Register it in `YayLoyaltyPro::boot()` with `( new AccountTier() )->register();`**

- [ ] **Step 3: Set up the spec's customers**

With `TIERS`: S (Silver, progress 1,000; set `tier_achieved_at` to `2026-10-26 08:00:00` through `$WP db query` on the customers table), G (Gold), B (Bronze), N (tierless, 0 earned), P (Silver, progress 3,050).

- [ ] **Step 4: Verify the Lifetime criteria**

Run `TAB <id>` for each.
Expected: S matches spec AC 1 and 2 (as Task 7 Step 5's `silver`, with Silver's real colour); G: Gold badge, "2× Points", "Top tier", no progressbar, Gold row "You" (AC 3); B: Bronze badge, no chip (AC 4); N: "No tier yet", "500 Points to Bronze", width 0%, "Tier Points" 0, no "Tier since", no "You" (AC 5); P: "Gold unlocks with your next earn", width 100%, `aria-valuenow="3000"` (AC 6).

- [ ] **Step 5: Verify Rolling and the hidden cases**

Switch to Rolling 6 months and run the sweep; R is a Silver customer held until 2027-07-10 (`tier_held_until` set as in the slice 5 plan). `TAB <R>`: progress equals `LedgerRepository::window_total()` for the last 6 months, caption "Counts Points earned in the last 6 months.", "Kept until" 2027-07-10 in the site format (AC 7); a Rolling customer with no running hold has no "Kept until". Back to Lifetime. Then turn tiers off: `TAB <S>` has no `yayloyalty-account__tiers`; turn tiers on and delete every tier: same; restore. Lite equivalent: `$WP eval 'remove_all_filters(YayLoyalty\Engine\Storefront\AccountMyPoints::MEMBER_TIER_FILTER); wp_set_current_user(<S>); YayLoyalty\Engine\Storefront\AccountMyPoints::render();'` has no section (AC 8).
Expected: as stated for each.

- [ ] **Step 6: Lint**

Run: `composer lint -- includes/Pro/Storefront/AccountTier.php includes/Pro/Storefront/TierProgress.php includes/Pro/YayLoyaltyPro.php`
Expected: no error or warning.

- [ ] **Step 7: Commit**

Stage: `includes/Pro/Storefront/AccountTier.php`, `includes/Pro/Storefront/TierProgress.php`, `includes/Pro/YayLoyaltyPro.php`
Message: `feat(tiers): show the customer's tier on My Account → My Points`

### Task 9: Docs and the end-to-end check

**Files:**
- Modify: `docs/architecture.md` (the My Points bullet, around line 83)
- Modify: `docs/specs/2026-11-02-tiers-master-design.md` (Status block and the Out line "My Account → Rewards tier section…")
- Modify: `docs/specs/2026-11-05-tier-storefront-design.md` (Status block, the Out line "My Account → My Points / Rewards…", and the Amendment section)
- Modify: this plan (Amended line and this section)

**Interfaces:**
- Consumes: everything above. Produces: nothing.

- [ ] **Step 1: Docs**

`docs/architecture.md`: one sub-bullet under the My Points bullet: the tab's "Your tier" section comes from `AccountMyPoints::MEMBER_TIER_FILTER` (`yayloyalty_account_member_tier`), answered by Pro's `AccountTier` from `TierStanding` (as the launcher line and drawer are), printed by `account-tier.php`; nothing prints on Lite or with tiers off. The master spec: an `**Amended:** 2026-11-10` line under Status pointing at this spec's Amendment section, and "(amended 2026-11-10: My Points has a tier section, …)" on its Out line. This spec: the same marker on its Out line, an `**Amended:**` line, and the Amendment section. Neither Status changes.

- [ ] **Step 2: Lint the PHP touched by the whole plan**

Run: `composer lint -- includes/Engine/Storefront/LauncherCopy.php includes/Engine/Storefront/AccountMyPoints.php includes/Engine/Storefront/templates/tier-progress.php includes/Engine/Storefront/templates/account-rewards.php includes/Engine/Storefront/templates/account-tier.php includes/Pro/Storefront/AccountTier.php includes/Pro/Storefront/TierProgress.php includes/Pro/YayLoyaltyPro.php`
Expected: no error or warning.

- [ ] **Step 3: End-to-end in Chrome (spec AC 13, plus AC 11 and 12)**

Signed in as S, G and N in turn, open `/my-account/my-points/` on the site: each matches its criterion from Task 8 Step 4, the section sits between the points card and Ways to earn, and Chrome's console shows no errors. As S: click the "Earned" history filter, then a page link: the section stays (AC 12); open `/my-account/my-points/2/`: the section shows. Resize to 375px: no horizontal scroll, the card's top row and tier rows stack (AC 11). Switch to Rolling with R: AC 7's caption and "Kept until". Turn tiers off: no section (AC 8). Restore Lifetime and tiers on. Open the launcher as S: its tier line reads as in Task 6's baseline (AC 10).
Expected: every check as stated.

- [ ] **Step 4: Record in this plan's `**Amended:**` line that Tasks 6–9 are done, with the date**

- [ ] **Step 5: Commit**

Stage: `docs/architecture.md`, `docs/specs/2026-11-02-tiers-master-design.md`, `docs/specs/2026-11-05-tier-storefront-design.md`, `docs/plans/2026-11-05-tier-storefront.md`
Message: `docs(tiers): record the My Points tier section`
