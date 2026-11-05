# Tier Storefront (Tiers slice 5) Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. Read the spec linked below before starting; the plan argues from it.

**Status:** done 2026-11-05
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
