# The launcher repeats the engine's campaign choice

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the 2026-10-05 `/simplify`, `/yaytidy` and `/code-review` of the storefront launcher, before it merged into `dev/edd` (#41).

## Today

Two loops pick "the campaign that pays" for a trigger.

- The engine's is `PointsEngine::evaluate()`. It takes the live campaigns through the `yayloyalty_campaign_candidates` filter, keeps those that pass the `yayloyalty_campaign_matches` filter and the handler's `is_eligible()`, and stops at the first one.
- The launcher's is `LauncherEarnRules::advertise()`, which picks what "Ways to earn" shows. It walks `CampaignRepository::live_for_trigger()` directly and keeps the first campaign that `PointsEngine::applies_to_everyone()` passes.

## Why it matters

The two already differ.

- **Zero points:** when the first campaign the engine reaches pays 0, the engine stops with `Result::REASON_ZERO_POINTS`. The launcher skips that campaign (`purchase_rate()` and `flat_points()` return null at 0) and advertises the next one. The panel can then promise a rate the engine never pays.
- **Pro:** a candidates or matches filter, or a Pro trigger handler, changes what the engine pays but not what the launcher shows.
- **Order rules:** `is_eligible()` and the per-order caps need a real event, so the launcher can't run them. This gap can only be documented, not closed.

## Suggested fix

One shared loop needs a new engine method, such as `PointsEngine::advertised( string $trigger, string $at )`, plus a handler method for the rate or flat points a campaign advertises. It wasn't done in the review because the engine's filters and handlers all take a `Context` built from an event (an order, a review, a signup), and the launcher advertises with no event: Pro's filters would have to say how they behave with no event. That is a design decision, so start with `/yaybrainstorming`.

Smaller step, if the design waits: make `LauncherEarnRules::winners()` follow the engine's stop rule. When the first campaign that applies to everyone pays 0, advertise nothing for that trigger. This closes the zero-points difference only.

## Done when

- [ ] When the first live campaign for a trigger that applies to everyone pays 0, `LauncherEarnRules::advertise()` advertises nothing for that trigger.
- [ ] The launcher's "Ways to earn" and `PointsEngine` pick their campaign through one shared method, which the candidates and matches filters reach.
