# YayLoyalty — Points, Rewards, Referral & VIP for WooCommerce

A loyalty program plugin for WooCommerce with points earning, rewards redemption, referral tracking, and VIP tier management. Shipped as Pro and Lite from one repository.

**Status:** v1.0.0 in development. Core earning system (campaigns, ledger, balance) and reward definitions shipped; admin Dashboard, Customers list + per-customer drawer are live. Nothing awards points yet (no order listener) and no redemption flow exists. Referrals and tiers planned for later phases.

---

## Requirements

- **PHP:** 7.4+
- **WordPress:** 6.5+
- **WooCommerce:** 10.0+
- **Node:** 22 (with `~/.nvm` fallback via corepack)
- **Composer:** latest
- **pnpm:** required — `preinstall` runs `only-allow pnpm`, so npm and Yarn are rejected

---

## Quick Start

### Development Setup

```bash
# Install dependencies
composer install
pnpm install

# Run dev server (watch + HMR for admin SPA)
./scripts/run.sh dev

# Run linter
composer lint      # phpcs
composer lint:fix  # phpcbf
```

Dev server starts Vite at `http://localhost:3030`. WordPress admin loads the bundle via HMR.

### Build & Release

```bash
# Build Pro package (default)
./scripts/run.sh build pro

# Release Pro with version bump
./scripts/run.sh release pro 1.0.1

# Release Lite from same version
./scripts/run.sh release lite --no-prompt

# Output lands in release/
```

**Pro first, Lite second.** Version is stamped once; Lite uses `--no-prompt` to skip re-prompting.

After release, reinstall dev dependencies:

```bash
composer install
```

---

## Directory Guide

| Path | Contents |
|---|---|
| **`includes/`** | PHP backend: controllers, services, repositories, models, enums, database schema, access/capabilities, points engine, utilities |
| **`apps/admin/`** | React 18 + Vite 6 admin dashboard SPA |
| **`docs/`** | Architecture, standards, data model, roadmap |
| **`scripts/`** | Build pipeline, validation, dev server |
| **`yayloyalty.php`** | Plugin entry point |

---

## Documentation

- **[`docs/codebase-summary.md`](./docs/codebase-summary.md)** — Directory structure, layers, entry points, request flows
- **[`docs/system-architecture.md`](./docs/system-architecture.md)** — Layers, Pro/Lite build mechanism, request flows, Mermaid diagrams
- **[`docs/code-standards.md`](./docs/code-standards.md)** — PHP conventions, REST patterns, database rules, Pro/Lite seams
- **[`docs/deployment-guide.md`](./docs/deployment-guide.md)** — Build commands, validation, headless setup
- **[`docs/data-model.md`](./docs/data-model.md)** — Full schema: 12 planned tables, relationships, invariants; implementation status
- **[`docs/project-roadmap.md`](./docs/project-roadmap.md)** — What's implemented, gaps, next phases

---

## Key Concepts

### Earning

Campaigns define when and how much points are awarded (purchase, signup, review). Config specifies calculation rules; Pro adds conditions (role, product, tier) and extra triggers (birthday, first_order, order_count, spend_milestone). Tier condition is unavailable until the VIP tier system ships.

### Balance

Every customer's balance = `SUM(ledger.points)`. The ledger is append-only; customer row is a cache updated atomically with each ledger write, always inside a transaction guarded by `LedgerService`.

### Pro/Lite

One repository, two zips. Lite = Pro minus `includes/Pro/` and SPA built with `BUILD_TYPE=lite`. The split is transparent: Lite contains exactly one reference to Pro (a guarded `class_exists` in `YayLoyalty::initialize()`), and Pro attaches via filter seams only.

---

## Pro Build Features (v1.0)

- Extra campaign triggers: birthday, first_order, order_count, spend_milestone
- Campaign conditions: role, product, category, lifetime spend, order count
- Conditions preview endpoint: POST `/campaigns/{id}/preview-conditions`
- Campaign table + edit form (`/campaigns/:id/edit`; saves validate but do not persist yet) and performance page (`/campaigns/:id/performance`, fixture-backed)
- Saved Customers-page segments: POST/DELETE `/customers/segments`
- Advanced config: max_points_per_order, daily_cap, media_bonus_points
- Reward types: fixed_coupon, percent_coupon, free_shipping, free_product (in addition to Lite's flexible_discount)
- Dashboard: referral tile, tier distribution, campaign breakdown, top campaigns (`GET /dashboard/pro`)

---

## Checks

```bash
composer test                  # phpunit — 115 unit tests: points engine (BaseCalculator, PurchaseHandler, PointsEngine), reward engine scaffold (Result, Registry, RewardEngine), dashboard computation (PeriodWindow, DashboardMath, AnomalyDetector, DashboardStats, DashboardNotices), PointsMath + shared vectors, campaign config/conditions validation, drawer query-arg groupings + fixture helpers (Controllers/)
composer lint                  # phpcs (WPCS + PHPCompatibility, 7.4 floor)
pnpm --filter @yayloyalty/admin-page lint   # eslint + prettier on TypeScript/React
pnpm --filter @yayloyalty/admin-page test   # vitest — lib/points-math against tests/fixtures/points-math-vectors.json (same file phpunit runs)
./scripts/run.sh release lite --no-prompt   # builds and validates a package end to end
```

`scripts/validate-build.php` boots the packaged plugin inside WordPress when `YAYLOYALTY_WP_CLI` is set — see `docs/deployment-guide.md`.

**Known issue:** The `pnpm build` step currently fails at `tsc --noEmit` on five pre-existing type errors (`@emoji-mart/*` and `@radix-ui/react-progress` types, and a `per_page`/`perPage` mismatch in two campaign components). This must be fixed before the next release.

---

## Support & Issues

Report issues on GitHub or contact support at YayCommerce.

---

## License

GPLv2 or later — see the plugin header in `yayloyalty.php`.
