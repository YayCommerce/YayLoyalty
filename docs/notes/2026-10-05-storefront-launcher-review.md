# Storefront launcher: open findings from the 2026-10-05 review

**Purpose:** the findings that the 2026-10-05 reviews of the storefront launcher (`/simplify`, `/yaytidy` and `/code-review`, before it merged into `dev/edd`) left open, kept for a deeper look. For each one: how the code behaves today, why it matters, and the options. The fixes those reviews led to landed with the launcher, and are not listed here.

**Update when:** a finding is fixed or dropped; remove its section. Delete this note when no section is left.

Citations give a path and a symbol; search for the symbol.

## 1. The launcher repeats the engine's campaign choice

- **Today:** two loops pick "the campaign that pays" for a trigger.
  - The engine's is `PointsEngine::evaluate()`. It takes the live campaigns through the `yayloyalty_campaign_candidates` filter, keeps those that pass the `yayloyalty_campaign_matches` filter and the handler's `is_eligible()`, and stops at the first one.
  - The launcher's is `LauncherEarnRules::advertise()`, which picks what "Ways to earn" shows. It walks `CampaignRepository::live_for_trigger()` directly and keeps the first campaign that `PointsEngine::applies_to_everyone()` passes.
- **Why it matters:** the two already differ.
  - **Zero points:** when the first campaign the engine reaches pays 0, the engine stops with `Result::REASON_ZERO_POINTS`. The launcher skips that campaign (`purchase_rate()` and `flat_points()` return null at 0) and advertises the next one. The panel can then promise a rate the engine never pays.
  - **Pro:** a candidates or matches filter, or a Pro trigger handler, changes what the engine pays but not what the launcher shows.
  - **Order rules:** `is_eligible()` and the per-order caps need a real event, so the launcher can't run them. This gap can only be documented, not closed.
- **Why it wasn't fixed in the review:** the engine's filters and handlers all take a `Context` built from an event (an order, a review, a signup), and the launcher advertises with no event. One shared loop needs a new engine method, such as `PointsEngine::advertised( string $trigger, string $at )`, plus a handler method for the rate or flat points a campaign advertises. Pro's filters would then have to say how they behave with no event. That is a design decision, so start with `/yaybrainstorming`.
- **Smaller step, if the design waits:** make `LauncherEarnRules::winners()` follow the engine's stop rule. When the first campaign that applies to everyone pays 0, advertise nothing for that trigger. This closes the zero-points difference only.

## 2. The launcher shows the earning panel past gates that Pro may add

- **Today:** `Launcher::should_render()` builds `ProgramGate` itself. A guest gets `ProgramGate::program_enabled()`, and a member gets `ProgramGate::check()`. Earning runs a gate chain instead: `EarningService::gates()` is `ProgramGate` plus whatever the `yayloyalty_earning_gates` filter adds, and `EarningService::blocked_reason()` runs it.
- **Why it matters:** Pro adds no gate today. Once it does, a shopper that gate blocks from earning will still see a panel about earning.
- **Suggested fix:** expose the filtered chain, for example by making `blocked_reason()` public or adding a static that runs the gates. The launcher then calls it and reads `ProgramGate::REASON_NO_CUSTOMER` as "a guest: show the pitch", and `program_enabled()` can go.

## 3. `GET /me/activity` returns a field nothing reads

- **Today:** each item has `label`, `meta`, `points` and `delta` (`ShopperActivityRow::items()`, and the item schema in `StorefrontActivityRestController`). `assets/storefront/launcher.js` and `templates/activity-row.php` read only `label`, `meta` and `delta`, and `delta` already carries the signed value.
- **Why it matters:** an unused field in a public REST response is a contract kept for nobody. Removing it is cheapest before the first release: the route has not been in a release yet, so nothing outside the plugin can rely on it. After release, removing the field breaks any client that reads it.
- **Suggested fix:** drop `points` from `ShopperActivityRow::items()`, its `@return` shape and the item schema. Check that `/me/activity` still returns 200 and that the launcher's markup is unchanged. If a shopper-facing client needs the raw number later, add the field back then.

## 4. The launcher promises redemption that isn't built

- **Today:** while the built-in cart-discount reward is live (`RewardRepository::find_live_cart_discount()`), the guest panel lists "Spend them at checkout — 100 Points = $1 off" (`templates/panel-guest.php`, from `LauncherPayload::redeem_rule()`). The member panel shows "worth $X" under the balance (`templates/panel-member.php`, from `LauncherPayload::member()`). The installer seeds the reward active.
- **Why it matters:** redemption is still stubs marked `TODO(redemption)`, and nothing at checkout redeems the reward. So on a fresh install every shopper is promised money off they can't get. The points earned email no longer does: it leaves out its value sentence unless the `yayloyalty_points_earned_show_balance_value` filter turns it on.
- **Suggested fix:** one "redemption available" check, false until redemption ships, that `LauncherPayload::for_balance()` consults, and that replaces the email's filter default. When it is false, the redeem step and the worth line are left out.

## 5. No test guards the shopper-safe activity projection

- **Today:** `ShopperActivityRow::items()` is the only code that turns ledger rows into what `GET /me/activity` shows, with no admin note, actor id or campaign id. It calls `Text::store_name()`, `get_option()` and `wc_get_order()`, so a unit test without WordPress can't reach it. The public `from_entry()` seam was removed, and the user chose not to restore it.
- **Why it matters:** a change that leaks admin data into the public response ships unseen. The repo has no tests today (`docs/known-issues.md`), so this gap is part of that, but it is the one with a privacy cost.
- **Suggested fix:** when tests come back, add an integration test of `GET /me/activity` that writes an adjustment with an admin note and asserts the item keys are exactly `label`, `meta`, `points` and `delta` (without `points` once section 3 lands), with no note text. Or bring back a WordPress-free seam for the label mapping and unit-test that.
