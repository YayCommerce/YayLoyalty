# YayLoyalty — Points, Rewards, Referral & VIP for WooCommerce

A loyalty program plugin for WooCommerce with points earning, rewards redemption, referral tracking, and VIP tier management. Shipped as Pro and Lite from one repository.

**Status:** v1.0.0 in development. Core earning system (campaigns, ledger, balance) and reward definitions shipped; admin Dashboard, Customers list + per-customer drawer are live. Points now award: purchases stage as pending and award at `completed`, signups on storefront self-registration, reviews on approval (one per product, daily cap) — `includes/Engine/Earning/`. Points come back out on cancel, trash, refunds (proportional, cumulative) and review removal; a deleted refund is restored. Nothing spends points yet: the redemption path in `includes/Engine/Redemption/` (service, gates, commit writer, checkout adapters, `LedgerService::redeem()`) is scaffolded with stub bodies over a real, tested `Clamp`/`Quoter` and a `redemptions` table that is designed but not installed. Referrals and tiers planned for later phases.

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
| **`includes/`** | PHP backend: controllers, services, repositories, models, enums, database schema, access/capabilities, the points/earning/reward/redemption engines, utilities |
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
- **[`docs/data-model.md`](./docs/data-model.md)** — Full schema: 13 planned tables, relationships, invariants; implementation status
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
- Campaign table + full CRUD (`/campaigns/new` template chooser → create form, `/campaigns/:id/edit` six-section form + summary rail, persist for real, `/campaigns/:id/performance` real ledger-backed performance analytics for purchase/signup/review only)
- Saved Customers-page segments: POST/DELETE `/customers/segments`
- Advanced config: max_points_per_order, daily_cap, media_bonus_points
- Reward types: fixed_coupon, percent_coupon, free_shipping, free_product (in addition to Lite's flexible_discount)
- Dashboard: referral tile, tier distribution, campaign breakdown, top campaigns (`GET /dashboard/pro`)

---

## Checks

What each suite checks and its current count: [`docs/code-standards.md` § 14](./docs/code-standards.md#14-running-the-linter-and-tests).

```bash
composer test                  # phpunit, unit suite
composer test:integration      # phpunit against a real site and server; see docs/deployment-guide.md
composer lint                  # phpcs
./scripts/check-docs.sh        # docs structure and single-owner checks
/yay:code-corrector            # Claude Code skill: rules re-declared outside their § 16 owner
pnpm --filter @yayloyalty/admin-page lint   # eslint + prettier on TypeScript/React
pnpm --filter @yayloyalty/admin-page test   # vitest
./scripts/run.sh release lite --no-prompt   # builds and validates a package end to end
```

`scripts/validate-build.php` boots the packaged plugin inside WordPress when `YAYLOYALTY_WP_CLI` is set — see `docs/deployment-guide.md`.

**Known issue:** The `pnpm build` step currently fails at `tsc --noEmit` on three pre-existing type errors: `emoji-picker-content.tsx` (×2, `@emoji-mart/*` types undeclared) and `progress.tsx` (`@radix-ui/react-progress` not installed). This must be fixed before the next release.

---

## Support & Issues

Report issues on GitHub or contact support at YayCommerce.

---

## License

GPLv2 or later — see the plugin header in `yayloyalty.php`.
