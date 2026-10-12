# Points Factory Explainer Design

**Status:** accepted 2026-10-13
**Related:** None

## Goal

An animated "points factory" page for the internal team (developers and support). It shows how points move through YayLoyalty today: customers and an admin feed packages of points through the real classes, and those packages end in the customer's balance. Each station is labelled with the class and file that does that work.

## Context

**Where it lives**
- `docs/` never ships. `build_package` in `scripts/run.sh` copies only `yayloyalty.php`, `index.php`, `YayLoyaltyPluginAdapter.php`, `readme.txt`, `assets/`, `includes/`, `languages/`, `vendor-prefixed/` and Composer's autoloader.
- `scripts/check-harness.sh` checks that relative markdown links in `docs/*.md` resolve.

**The earning path, as it is in the code at commit `f92b317`**
- **Order intake:** `OrderListener` (`includes/Engine/Earning/Listeners/OrderListener.php`) hooks `woocommerce_order_status_changed` and `woocommerce_new_order`.
  - On `pending` or `on-hold`, `on_stageable()` runs `EarningService::preview()`, then `PendingPoints::stage()`.
  - On `OrderStatus::AWARD` (`completed`), `on_awardable()` runs `EarningService::award()` for each trigger in `yayloyalty_order_award_triggers`, then `PendingPoints::release()`.
  - On `cancelled`, `trash` or `refunded`, it routes to `OrderReversalListener`.
- **Other intake:** `SignupListener` hooks `user_register` (`on_user_register()`). `ReviewListener` hooks `comment_post` and `transition_comment_status`.
- **Scoring:** `EarningService` (`includes/Engine/Earning/EarningService.php`) runs the gates, then `PointsEngine::evaluate()`. It then calls `LedgerService::earn()` and fires `yayloyalty_points_awarded`. The `yayloyalty_award_points` filter applies along the way.
- **Gate:** `ProgramGate` (`includes/Engine/Earning/ProgramGate.php`) checks, in order: program on, a customer, not opted out, role not excluded. The first failing rule wins.
- **Pending:** `PendingPoints` (`includes/Engine/Earning/PendingPoints.php`) keeps order meta `_yayloyalty_points_pending`, `_yayloyalty_points_released` and `_yayloyalty_points_earned`, and caches the total in `customers.pending_points`.
- **Writes:** `LedgerService` (`includes/Services/LedgerService.php`) is the only writer of ledger rows and balances (hard rule 5). Its writers are `earn()`, `adjust()`, `refund_earn()` and `restore_refund()`.
- **Reversal:** `OrderReversalListener` → `Reversal` (`includes/Engine/Earning/Reversal.php`) → `LedgerService::refund_earn()`.
- **Enums:**
  - `LedgerType` (`includes/Enums/LedgerType.php`): `EARN`, `REDEEM`, `REFUND_EARN`, `REFUND_REDEEM`, `EXPIRE`, `ADJUST`, `CORRECTION`.
  - `LedgerSource` (`includes/Enums/LedgerSource.php`): includes `ORDER`, `SIGNUP`, `REVIEW` and `MANUAL`.

**Not built yet**
- Redemption: `LedgerService::redeem()` throws "Not implemented", and `RedemptionService::is_available()` returns false.
- Expiry: no code writes `EXPIRE` rows. `LedgerService.php` says expiry needs the lots table.
- VIP tiers: only the `customers.tier_id`, `tier_evaluated_at` and `tier_progress_value` columns exist.

## Scope

**In:**
- One self-contained page, `docs/explainers/points-factory.html`, that plays automatically and loops.
- 12 clickable stations, each with a details panel.
- A 6-scene scripted loop with a caption naming the hook or method at each step.
- Play/Pause, Restart and jump-to-scene controls.
- Under-construction stations for redemption, expiry and VIP tiers.
- A link from `docs/architecture.md`.

**Out:**
- Any shipped plugin code, admin SPA route, REST call, or real or live store data. The data is fixed and illustrative.
- Pro-only triggers (`BIRTHDAY`, `FIRST_ORDER`, `ORDER_COUNT`, `SPEND_MILESTONE`), Pro reward types and `DailyEarningLimit`, and any marking of the Pro/Lite split.
- `restore_refund()`, `CORRECTION` rows and partial-refund maths (`ClawbackMath`).
- Dark mode, phone layouts and translation.
- An automated drift check. The page carries a date and commit stamp only.
- Tests. This is not money or ledger logic, so the manual check below stands in for tests.

## Acceptance criteria

1. Opened from `file://` in Chrome with network access, the page starts playing on its own. The 6 scenes play in this order, and after scene 6 it loops to scene 1:
   1. Alice places an order: her package goes `OrderListener` → `EarningService::preview()` → `ProgramGate` → `PendingPoints::stage()` and waits in the warehouse.
   2. Bob signs up: his package goes `SignupListener` → `EarningService::award()` → `ProgramGate` → `LedgerService::earn()` (`EARN`, `SIGNUP`) → vault.
   3. Alice's order is completed: `PendingPoints::release()`, then `EarningService::award()` → `LedgerService::earn()` (`EARN`, `ORDER`) → vault.
   4. Cara posts a review but has opted out: her package goes `ReviewListener` → `EarningService::award()` and is dropped into the reject bin at `ProgramGate`, captioned "opted out".
   5. The admin adjusts +50: the package goes `LedgerService::adjust()` (`ADJUST`, `MANUAL`) → vault.
   6. Alice's order is refunded: `OrderReversalListener` → `Reversal` → `LedgerService::refund_earn()` (`REFUND_EARN`) takes the package out of the vault and back along the return conveyor.
2. The caption bar shows, for the step that is playing, the hook or `Class::method()` named in criterion 1.
3. The 12 stations are:
   - `OrderListener`, `SignupListener`, `ReviewListener`, `OrderReversalListener`
   - `EarningService` + `PointsEngine`, `ProgramGate`, `PendingPoints`, `LedgerService`
   - Customer vault (`customers.balance`, `lifetime_earned`)
   - Redemption, Expiry, VIP tiers

   Clicking any station pauses the loop and opens a side panel with its summary, repo-relative file path and key methods. Closing the panel resumes the loop.
4. Redemption, Expiry and VIP tiers are drawn hatched, with a "not built yet" tag. Their panels say what is missing, using the facts in Context. No package ever enters them.
5. Play/Pause works, and so does the Space key. Restart returns to scene 1. Clicking a scene in the caption list jumps to the start of that scene.
6. With `prefers-reduced-motion: reduce`, the page loads paused, with every package at its end position.
7. If the GSAP scripts fail to load (offline), the static floor plan and the station panels still work, and a notice says "Animation needs network access".
8. The header reads "Accurate as of `f92b317` (2026-10-13)". The implementer updates both values to the commit the page was verified against.
9. `docs/architecture.md` links to the page from "Server layers and write ownership", and `./scripts/check-harness.sh` passes.
10. End-to-end verification: open `docs/explainers/points-factory.html` in Chrome at 1440×900 and watch a full loop. All 6 scenes play as in criterion 1 with no console errors. Then click all 12 stations: each file path shown exists (`ls` it from the repo root).

## Constraints

- One file with inline CSS, SVG and JS. The only external resources are these two scripts, pinned:
  - `https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js`
  - `https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/MotionPathPlugin.min.js`
- No build step, no npm dependency, and nothing added to `apps/` or `includes/`.
- `docs/` stays out of `build_package`, so the release zip is unchanged.
- Layout works at 1024 px wide and up. Below that, the floor scrolls horizontally inside its own container, and the page itself never scrolls horizontally.
- Class names, methods, hooks, meta keys and enum values are written exactly as in the code at the stamped commit.

## Approach

**Chosen:** a single HTML file with GSAP and MotionPathPlugin from cdnjs.

**Architecture:** A hand-drawn inline SVG lays out the floor from left to right:
- the customer dock and admin desk;
- the intake hoppers, then scoring, then the gate;
- the warehouse, the ledger press and the vault;
- the hatched under-construction wing;
- a return conveyor underneath.

Conveyors are SVG `<path>` elements with ids. Two data arrays at the top of the script drive everything:
- `STATIONS`: `{ id, label, file, methods[], summary, built }`. It feeds the SVG labels and the details panel.
- `SCENES`: `{ id, caption, steps[] }`. Each scene builds one `gsap.timeline()`, which moves a package `<g>` along the conveyor paths with `motionPath`.

A master timeline chains the scenes and repeats with `repeat: -1`. Pause, Restart and jump map to `master.pause()`, `master.restart()` and `master.seek(label)`.

**Components:**
- `docs/explainers/points-factory.html`: the page.
- `docs/architecture.md`: one link line.

**Data and storage:** None. The data is fixed in the file. No browser storage.

**Integration points:** None at runtime. The page only names hooks; it calls none.

**UI:**
- Header: title and stamp.
- SVG floor.
- Caption bar with the scene list.
- Controls: Play/Pause and Restart.
- Right-hand details panel.
- A package is a rounded box showing its points and `LedgerSource`, coloured by flow: earn green, adjust blue, refund red, rejected grey.

**Edge cases and failure modes:**
- CDN fails: criterion 7.
- Reduced motion: criterion 6.
- Panel open while the loop runs: the loop pauses (criterion 3).
- A tab left in the background: GSAP lag smoothing keeps the timeline from jumping.
- The code drifts after the stamp: accepted. The stamp tells readers how old the page is.

**Decision record:** None. This makes no architectural choice.

## Alternatives considered

| Option | Why rejected |
|---|---|
| Vanilla SVG and JS, no dependencies | More animation code to hand-write. The user picked GSAP for simpler sequencing. |
| Dev-only route in the admin SPA (React + `motion`) | Ties a doc to the SPA build, routing and the Pro/Lite split, and needs WordPress running to view. |
| Shared claude.ai Artifact | Not versioned with the code it describes. |
| Live or store-config data | The audience is internal and it must work on an empty store. Live data needs REST access and WordPress. |
| Animating redemption, expiry and tiers as if built | Would mislead developers about what works today. |
| Automated drift check (paths in check-harness) | The user chose a date stamp only. |

## Rollout

None. It's internal documentation, it isn't in the release zip, and merchants are unaffected. It lands through a PR into `dev/edd`.

## Open questions

None.
