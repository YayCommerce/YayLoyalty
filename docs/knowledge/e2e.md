---
area: e2e
updated: 2026-11-13
updated_ref: 6ec1e2d
covers: [tests/e2e/]
---

# E2E

> Update when: the wp-env setup, the seed, a fixture or the gate bypass changes, or a flow gets a spec.

## Running

| Do | Command |
|---|---|
| Install (once) | `yayh run e2e-install` (the package is installed with `--ignore-workspace`; it isn't a pnpm workspace member) |
| Start or restart the site | `yayh e2e env start` (Docker; dev site :9190, tests site :9191 for the PHP integration suite) |
| All specs | `yayh e2e run` |
| One flow | `yayh e2e run --grep @flow-redeem-block-checkout` |
| WP-CLI in the site | `yayh e2e cli wp …` |
| Wipe and reseed | `yayh e2e env reset` |

The site runs this checkout with `YAYLOYALTY_IS_DEVELOPMENT` false, so it serves `assets/dist/`: run `./scripts/run.sh build pro` first, and again after SPA or storefront CSS changes. WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.2, block theme, block Cart and Checkout pages, HPOS **off** (orders at `post.php?post=<id>&action=edit`).

## Seed (`tests/e2e/bin/setup-env.sh`, after every start and reset)

- Users `admin`/`password` and `customer`/`password` (Jane Smith, `customer@example.com`, billing address in San Francisco).
- `e2e-simple-product`: $10, **virtual**, so checkout needs no shipping zone. USD, US:CA, COD on, pretty permalinks.
- `yayloyalty_setup_completed` = 1, so the first-run wizard doesn't cover the admin screens.
- The licence placeholder (see Gates).
- The plugin's own installer seeds the built-ins: purchase 1 point per $1, signup 100, review 50, and the "Points discount" reward (100 points = $1, min 100, `auto_max`).

`wp user create` users get no signup points (signup awards self-registration only), so `customer` starts at 0.

## Fixtures

- `fixtures/test.ts`: `test`, `expect`, `wp()` (WP-CLI in the dev site) and `customerPage`. Admin is the default storage state.
- `fixtures/loyalty.ts`:
  - `userId(login)`, `balanceOf(id)`, `pendingOf(id)`: read through the admin REST route.
  - `adjustPoints(id, points)`, `setBalance(id, points)`: write through `POST /yayloyalty/v1/customers/{id}/adjust`, so `LedgerService` writes as it does for an admin. Never insert ledger rows.
  - `emptyCart(id)`: drops the persistent cart **and** the WooCommerce session, where a points intent from an earlier test otherwise survives.
- Tags: every test carries `@flow-<slug>`; the smoke spec carries `@smoke`. A plan's or bug's spec adds `@P-<n>` / `@B-<n>`.

## Gates and how the site gets past them

- **Licence:** the Pro tree is present, and AdminShell shows its "Activate Licenses" page instead of the SPA until `yayloyalty_license_key` and `yayloyalty_license_info` are stored (`vendor-prefixed/src/Menu/PluginSubmenu.php`, `LicenseState::allows_features`). The seed stores a placeholder key and `{"license":"valid","expires":"lifetime"}`. `WP_HTTP_BLOCK_EXTERNAL` in `.wp-env.json` keeps the placeholder from ever reaching the licence server.
- **Edition:** the SPA is the Pro build, so the Pro screens (campaign and reward editors, batch expiry) are what the specs see.
- **Earning gates:** the launcher and the redeem card hide for a paused program or an excluded role (`ProgramGate`).

## Traps

- The WordPress login page focuses the username field on a timer; `fixtures/auth.setup.ts` waits for that focus before typing, or the password lands in the username field.
- The launcher panel is `aria-modal`: while it's open the trigger leaves the accessibility tree, so read its state through `[data-yl-open]`.
- The redeem card prints `$5 off`, not `$5.00 off`; the order totals print `$5.00`.
- On-hold orders from an aborted run keep counting in the customer's pending total; assert pending relative to `pendingOf()`.
- Another plugin's wp-env running commands at the same time can stall `wp-env run` for minutes. Re-run once it's quiet.
- `yayh` checks the site over localhost; inside a network sandbox it reports the site down.
- `sendmail: can't connect` on stderr is harmless: the site has no mailer, so emails can't be asserted here.

## Flows

| flow | actor | priority | coverage | source |
|---|---|---|---|---|
| General settings save and reload | admin | important | `specs/settings-general.spec.ts` @flow-settings-general | `apps/admin/src/pages/settings/GeneralSettingsPage.tsx` |
| Adjust a customer's points | admin | critical | `specs/customer-adjust-points.spec.ts` @flow-customer-adjust-points | `apps/admin/src/pages/customers/components/CustomersAdjustPointsDialog.tsx` |
| Redeem points at block cart and checkout | customer | critical | `specs/redeem-block-checkout.spec.ts` @flow-redeem-block-checkout | `includes/Engine/Redemption/Adapters/StoreApiAdapter.php` |
| Redeem points at classic cart and checkout | customer | critical | `specs/redeem-classic-checkout.spec.ts` @flow-redeem-classic-checkout | `includes/Engine/Redemption/Adapters/ClassicCheckoutAdapter.php` |
| Earn on a completed order, seen in My Points | customer | critical | `specs/earn-on-completed-order.spec.ts` @flow-earn-on-completed-order | `includes/Engine/Earning/Listeners/OrderListener.php` |
| Launcher opens, closes, hides for excluded roles | customer | important | `specs/launcher.spec.ts` @flow-launcher | `includes/Engine/Storefront/Launcher.php` |
| Redeem card below the minimum | customer | important | `specs/redeem-block-checkout.spec.ts` @flow-redeem-block-checkout | `includes/Engine/Storefront/RedeemCard.php` |
| Redeem card for a guest | guest | minor | candidate | `includes/Engine/Storefront/templates/redeem-card.php` |
| Setup wizard completes and goes live | admin | important | candidate | `apps/admin/src/components/setup-wizard/SetupWizardModal.tsx` |
| Setup wizard exit never reopens | admin | minor | candidate | `apps/admin/src/components/setup-wizard/SetupWizardModal.tsx` |
| Expiry settings save with confirmation | admin | important | candidate | `apps/admin/src/pages/settings/ExpirySettingsPage.tsx` |
| Design settings save and reset | admin | minor | candidate | `apps/admin/src/pages/settings/DesignSettingsPage.tsx` |
| Email settings and weekly throttle | admin | important | candidate | `apps/admin/src/pages/settings/EmailsSettingsPage.tsx` |
| Campaign condition groups save and reload | admin | important | `specs/campaign-condition-groups.spec.ts` @flow-campaign-condition-groups | `apps/admin/src/pro/conditions/ConditionGroupsEditor.tsx` |
| Campaign create, edit, pause | admin | important | candidate | `apps/admin/src/pro/campaigns/editor/CampaignEditor.tsx` |
| Reward create and edit | admin | important | candidate | `apps/admin/src/pro/rewards/editor/RewardEditor.tsx` |
| Customer drawer, ledger and activity tabs | admin | important | candidate | `apps/admin/src/pages/customers/drawer/CustomerDrawer.tsx` |
| Dashboard renders | admin | minor | candidate | `apps/admin/src/pages/dashboard/DashboardPage.tsx` |
| Claim a guest order from My Points | customer | important | candidate (branches: `tests/integration/Storefront/ClaimGuestOrderTest.php`) | `includes/Engine/Storefront/AccountClaimOrder.php` |
| Product page earn message (block theme) | guest, customer | important | candidate | `includes/Engine/Storefront/ProductEarnMessage.php` |
| Thank-you page points | customer | important | candidate | `includes/Engine/Storefront/ThankYouPoints.php` |
| My Points history filters and CSV | customer | minor | candidate | `includes/Engine/Storefront/AccountMyPoints.php` |
| Signup awards points (logged-out registration) | guest | important | candidate | `includes/Engine/Earning/Listeners/SignupListener.php` |
| Remove a loyalty coupon from an order, restore or keep points | admin | important | candidate (restore: `tests/integration/Engine/Redemption/ReversalTest.php`) | `includes/Engine/Admin/OrderCouponRemoval.php` |
| Grant missing points from the order screen | admin | important | candidate (REST: `tests/integration/Earning/OrderPointsGrantTest.php`) | `includes/Engine/Admin/OrderMissingPoints.php` |
| Orders list points column and Missing filter | admin | minor | candidate | `includes/Engine/Admin/OrderMissingPoints.php` |
| Review reversal note in the Reviews table | admin | minor | candidate | `includes/Engine/Admin/ReviewPointsNote.php` |
| Shortcodes and blocks render | guest, customer | minor | unit: none yet | `includes/Engine/Storefront/Placements.php` |
| Completing an order awards points | admin | critical | unit: `tests/integration/Earning/PurchaseLifecycleTest.php` | `includes/Engine/Earning/Listeners/OrderListener.php` |
| Refund, cancel or trash reverses points | admin | critical | unit: `tests/integration/Earning/ReversalFlowTest.php` | `includes/Engine/Earning/Listeners/OrderReversalListener.php` |
| Review approval awards and unapproval reverses | admin | important | unit: `tests/integration/Earning/SignupReviewFlowTest.php` | `includes/Engine/Earning/Listeners/ReviewListener.php` |
| Loyalty emails sent | system | important | unit: `tests/integration/Emails/PointsEarnedEmailTest.php` | `includes/Engine/Emails/Emails.php` |
| Expiry job expires and reminds | system | important | unit: `tests/integration/Engine/Expiry/ExpiryRemindersTest.php` | `includes/Engine/Expiry/ExpiryRunner.php` |
