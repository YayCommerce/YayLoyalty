# Points workflow e2e tests Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking.

**Status:** done 2026-10-12
**Goal:** Cover the points workflow end to end in two layers: whole-workflow PHPUnit integration flows for the ledger math, and a new Playwright suite on wp-env for what shoppers and admins actually see.
**Spec:** none (planned from the user's request on 2026-10-12)
**Branch:** `trace/test/points-e2e`, from `dev/edd`
**Out of scope:** redemption and rewards (left for later); expiry and the Pro triggers (birthday, first order, order count, spend milestone), because neither is built; Lite-build e2e; CI.

## Outcome and deviations

- Integration: 21 tests added (71 in the suite, all passing). Browser: 8 Playwright tests in 4 specs, passing twice in a row on one site. Rounding the clawback up fails `ReversalFlowTest`; unhooking `woocommerce_refund_deleted` fails both it and `reversals.spec.ts`.
- `test_refunded_status_without_refund_object_reverses_fully` became `test_refunded_status_reverses_fully_once`: WooCommerce answers the `refunded` status with a refund for the remainder, so no refund-less path is reachable through a status change.
- `test_negative_adjust_may_not_exceed_policy` was dropped: the adjust route has no such rule.
- The wp-env config lives in `apps/e2e/.wp-env.json` and pins WordPress 7.1.3 and WooCommerce 11.2.1, as on the Local site. It runs PHP 8.2, not 7.4: the dev `vendor/` doesn't load on 7.4 ([known issue](../known-issues.md)).
- The gate settings have no control in the SPA, so the gate tests set them through `/settings`, and the opt-out through wp-cli. Setup also seeds an `E2E Gadget` at 10.00 for two-line orders.
- The decision record is `docs/decisions/2026-10-12-browser-tests-with-playwright-on-wp-env.md`, status proposed.

## Context

The repo has no end-to-end coverage. Its three suites are PHPUnit unit, PHPUnit integration (against the Local site, scratch `ylit_` tables, `tests/integration/WooCommerceOrdersFixture.php`) and Vitest (Node only, no DOM). Plans have verified with manual "end-to-end checks". The user chose:
- **Layer:** both. Integration flows for ledger and points math, plus Playwright for the UI.
- **Environment:** wp-env (Docker) for Playwright. The integration flows stay on the existing Local-site harness.
- **Scope:** the purchase earn lifecycle, reversals, signup and review, admin adjustment and earning gates.

**Tests:** approving this plan accepts the tests named below (CLAUDE.md § Tests). Each one guards points math, ledger writes or balances, or the UI path that shows them.

## Facts the plan relies on

- **Earning wiring** (`includes/YayLoyalty.php:58-70`):
  - `OrderListener`: pending/on-hold stages pending points; `completed` awards and releases them; processing does nothing.
  - `OrderReversalListener` → `Reversal`: cancel or trash is a full reversal, a partial refund claws back proportionally (`ClawbackMath::owed`, floored), and deleting a refund restores the points.
  - `SignupListener`: self-registration only.
  - `ReviewListener`: an approved top-level product review earns; unapproving it reverses the award.
- **Gates** (`ProgramGate`): program disabled, guest, `_yayloyalty_opted_out` user meta, excluded role.
- **Default campaigns** (`Campaign::default_config`): purchase is 1 point per 1 unit, after discounts, rounded down; signup is 100; review is 50 with `require_verified` and `one_per_product` on.
- **REST** (`yayloyalty/v1`):
  - `GET /customers/{id}` returns the balance, `pending_points` and `lifetime_earned`.
  - `GET /customers/{id}/ledger`
  - `POST /customers/{id}/adjust`
  - `GET|POST /settings`
  - `/campaigns`
- **Storefront:**
  - Product earn message (`ProductEarnMessage`)
  - Thank-you points (`ThankYouPoints`)
  - My Account `rewards` endpoint (`AccountRewards`)
  - Launcher, which is hidden for admins
- **Plugin assets:** the plugin loads the Vite dev server unless `YAYLOYALTY_IS_DEVELOPMENT` is false (`yayloyalty.php:60`), so wp-env defines it false and uses `assets/dist/` from `./scripts/run.sh build pro`.
- **Release:** `apps/` never ships (`scripts/run.sh:317`), so a new `apps/e2e` package is safe for both builds.

---

### Task 0: Record the plan

- [x] Copy this plan to `docs/plans/2026-10-12-points-e2e-tests.md` with `Status: active`.
- [x] Write `docs/decisions/2026-10-12-browser-e2e-with-playwright-and-wp-env.md`. It records the new harness: why Playwright on wp-env, why integration flows stay on Local, and that only the Pro build is covered for now. Follow `docs/decisions/2026-10-04-decision-record-practice.md`.

### Task 1: Integration flows (PHPUnit, existing harness)

**Files:** new tests under `tests/integration/Earning/`.
- They extend `IntegrationTestCase` and use `WooCommerceOrdersFixture`.
- They reuse `create_customer`, `create_order`, `transition`, `refund_lines`, `refund`, `create_product`, `approve_new_review`, `create_campaign`, `ledger_points`, `ledger_rows`, `customer_row`, `send` and `act_as_admin`.
- If a helper is missing (deleting a refund, opting a user out), add it to the fixture trait.
- Before writing each test, grep the existing integration tests (`DailyEarningLimitTest`, `PointsEarnedEmailTest`, `PointsAdjustedEmailTest`, `LedgerServiceCampaignLockTest`). Drop any test below that one of them already covers.

**`PurchaseLifecycleTest.php`**
- `test_on_hold_stages_pending_then_completed_awards_and_releases`: pending points = order points, balance 0; after completed, balance = N, pending 0, one EARN row.
- `test_completing_twice_awards_once`: an idempotency check through completed → processing → completed.
- `test_points_use_after_discount_base_and_round_down`: line totals with a fraction, for example 19.99 → 19.
- `test_excluded_product_lines_earn_nothing`

**`ReversalFlowTest.php`**
- `test_partial_refund_claws_back_proportionally`
- `test_deleting_refund_restores_points`
- `test_cancel_after_partial_refund_reverses_the_rest_only`
- `test_refunded_status_without_refund_object_reverses_fully`
- `test_cancelled_on_hold_order_releases_pending`
- `test_reversed_order_is_not_re_awarded`: a cancelled order set back to completed earns nothing.

**`SignupReviewFlowTest.php`**
- `test_self_registration_awards_signup_points_once`
- `test_admin_created_user_earns_nothing`
- `test_verified_review_awards_and_unapprove_reverses`
- `test_unverified_review_earns_nothing`
- `test_second_review_same_product_earns_nothing`

**`EarningGatesTest.php`**
- `test_disabled_program_awards_nothing`
- `test_opted_out_customer_awards_nothing`
- `test_excluded_role_awards_nothing`
- `test_guest_order_awards_nothing`

**`tests/integration/Controllers/AdjustRouteTest.php`**
- `test_adjust_moves_balance_and_lifetime`
- `test_correction_moves_balance_only`: `adjust_lifetime=false`.
- `test_negative_adjust_may_not_exceed_policy`: only if the route rejects it. Read `CustomerRouteArgs` first, and drop this test if there's no such rule.

**Verify:** `YAYLOYALTY_TEST_SOCKET=<socket> composer test:integration`, and read the output. The tests must be listed as run, not just exit 0 (`docs/known-issues.md`).

### Task 2: wp-env

**Files:** `.wp-env.json` at the repo root, and `apps/e2e/bin/setup.sh`.

- **`.wp-env.json`:**
  - Pinned `core` (WordPress 6.8.x) and `phpVersion: "7.4"`. This exercises hard rule 4 for free.
  - `plugins: [".", "https://downloads.wordpress.org/plugin/woocommerce.<pinned>.zip"]`
  - `config: { "YAYLOYALTY_IS_DEVELOPMENT": false, "WP_DEBUG": true }`
  - The `tests` environment on :8889 is what the suite targets.
- **Prerequisites,** documented and checked by `setup.sh`: `vendor/`, `vendor-prefixed/` and `assets/dist/admin` exist. If not, the script prints "run `composer install` and `./scripts/run.sh build pro`".
- **`setup.sh`** is idempotent. It runs `wp-env run tests-cli wp …` to:
  - Set pretty permalinks.
  - Finish WooCommerce setup: install pages, set the currency to USD, turn off coming-soon mode, enable the Check payments gateway (orders land `on-hold`) and allow registration on My Account.
  - Require manual comment approval.
  - Create the product `E2E Widget` at 20.00.
  - Run the plugin's setup wizard seed. Prefer `POST /setup` or `SetupService` through `wp eval`, so the default purchase, signup and review campaigns exist.
  - Create an application password for `admin` and write it to `apps/e2e/.auth/app-password` (gitignored).

### Task 3: Playwright package

**Files:**
- `apps/e2e/package.json` (`@yayloyalty/e2e`, private): `@playwright/test` and `@wordpress/env` as devDependencies, pinned exact. Scripts:
  - `env:start`: `wp-env start && ./bin/setup.sh`
  - `env:stop`
  - `test`: `playwright test`
- `pnpm-workspace.yaml` adds `apps/e2e`.
- `apps/e2e/playwright.config.ts`:
  - `baseURL http://localhost:8889`, `workers: 1`. Specs share global settings and campaigns, so they run serially.
  - `trace: 'retain-on-failure'`.
  - The `globalSetup` logs in as admin and saves `.auth/admin.json`.
- `apps/e2e/tsconfig.json`
- Root `.gitignore`: `apps/e2e/.auth/`, `apps/e2e/test-results/`, `apps/e2e/playwright-report/`.
- **`apps/e2e/support/`:**
  - `api.ts`: REST client with Basic auth from the app password.
    - WooCommerce: `wc/v3` customers, orders, order status, refunds and refund delete.
    - Loyalty: `yayloyalty/v1` customer, ledger, settings, adjust.
  - `fixtures.ts`: a Playwright `test.extend` that adds:
    - `customer`: a fresh customer made via REST. It's admin-created, so it earns no signup points. Deleted afterwards.
    - `shopperPage`: logged in as that customer.
    - `adminPage`: uses the saved admin storage state.
    - `settings`: snapshot and restore of `/settings` and the campaigns, used by the gate specs.
  - `expectPoints(customerId, { balance, pending, lifetime })`: polls `GET /customers/{id}`.
- **Selectors:** use `getByRole`, `getByLabel` and `getByText` with the plugin's own copy. Add a `data-testid` to SPA or storefront markup only where an element has no accessible name. That change is Lite-safe and must not live under `src/pro/`.

### Task 4: Specs (`apps/e2e/specs/`)

**`purchase.spec.ts`** (UI-driven, asserted through UI and REST)
1. As a guest, the product page shows the earn message for 20 points.
2. Logged-in shopper: add to cart → checkout with Check payments.
   - The thank-you page shows 20 pending points.
   - REST shows pending 20 and balance 0.
3. Admin: WooCommerce → Orders → open the order → set status Completed → save.
   - REST shows balance 20 and pending 0, with one EARN ledger row.
   - The order notes mention the award.
4. Shopper:
   - My Account → Rewards shows balance 20 and a history row.
   - The launcher opens and shows 20.

**`reversals.spec.ts`** (setup via REST: a completed 2-line order of 20 + 10 = 30 points)
1. Admin partially refunds line 2 on the order edit screen → balance 20, with a REFUND_EARN row of −10.
2. Admin deletes that refund → balance 30.
3. A second on-hold order is cancelled in the UI → its pending points are released and the balance is unchanged.
4. The completed order is cancelled → balance 0. My Account history shows the reversal rows.

**`signup-review.spec.ts`**
1. A visitor registers on My Account → REST shows a signup EARN of 100. My Account Rewards shows 100.
2. A customer with a completed order for the widget (REST) leaves a review on the product page. It's held for moderation, so no points yet.
3. Admin approves it in Comments → +50.
4. Admin unapproves it → back to the previous balance.

**`admin-adjust-gates.spec.ts`**
1. Admin → YayLoyalty Customers → opens the customer → adjusts +25 with an internal note and a customer note.
   - The drawer's ledger shows the row.
   - REST shows the balance.
   - The shopper's My Account shows the customer note.
2. Admin adjusts −10 with "don't change lifetime" → the balance drops and lifetime is unchanged.
3. Gates. Each one is set through the SPA settings UI where a control exists, otherwise through REST or wp-cli. After each, a REST-completed order earns 0. Settings are restored in `afterEach`.
   - Program disabled.
   - Excluded role, using a custom role made in setup.
   - Opted-out user meta.

### Task 5: Docs and commands

- `CLAUDE.md` § Commands:
  - `pnpm --filter @yayloyalty/e2e env:start` (needs Docker, and a built `pro` SPA first)
  - `pnpm --filter @yayloyalty/e2e test`
- `docs/architecture.md` § Tests: a fourth harness paragraph on wp-env, Playwright, fixtures through REST and app passwords, serial workers and Pro build only.
- `docs/known-issues.md`: add entries only for gotchas actually hit, for example a stale `assets/dist` or the first `wp-env start` downloading WooCommerce.
- `docs/conventions.md:72`: specs are `<flow>.spec.ts` in `apps/e2e/specs/`.

## Review focus

- No product code changes, apart from any `data-testid` that was needed. Hard rules 1–5 are untouched.
- Fixtures clean up after themselves. The suite is independent of test order, apart from the gate specs' restore.
- Expected numbers come from the default campaigns that `setup.sh` seeds, never from hard-coded site state.

## Verification

1. `composer test:integration` with the Local socket: every new test runs and passes. Check that the test count rose by the number added.
2. `./scripts/run.sh build pro`, then `pnpm --filter @yayloyalty/e2e env:start`: `setup.sh` finishes without errors, and http://localhost:8889/shop shows the E2E Widget with its earn message.
3. `pnpm --filter @yayloyalty/e2e test`: all four specs pass. Run it again on the same environment and it still passes, which proves the suite is idempotent.
4. Break one rule on purpose, for example change `ClawbackMath::owed` to round up, and confirm both `ReversalFlowTest` and `reversals.spec.ts` fail. Then revert.
5. `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit` stays as it is on `dev/edd`. `git status` shows only the intended files.
