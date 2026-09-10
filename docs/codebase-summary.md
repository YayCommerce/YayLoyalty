# Codebase Summary

YayLoyalty — WooCommerce loyalty plugin: points, rewards, referral, VIP tiers.
One repo builds two packages, Pro and Lite (Pro minus `includes/Pro/`), via
`scripts/run.sh` — see `deployment-guide.md`.

## Layout

| Path | What's there |
|---|---|
| `yayloyalty.php` | WP plugin bootstrap header |
| `includes/YayLoyalty.php` | Plugin singleton, wires the boot sequence |
| `includes/Engine/` | Activation/deactivation, admin settings screen, REST API bootstrap, dev/prod asset registration (`Register/`) |
| `includes/Controllers/` | REST controllers — one per resource (Campaign, Customer, Ledger, Settings) over `AbstractRestController`/`BaseRestController` |
| `includes/Models/` | `AbstractModel` + Campaign, Customer, LedgerEntry |
| `includes/Repositories/` | `AbstractRepository` + one repo per table (Campaign, Customer, Ledger) — the single write path to the DB |
| `includes/Services/` | Business logic above repositories (`LedgerService`) |
| `includes/Database/` | `Installer`, `Transaction`, `Schema/` (table definitions: Campaigns, Customers, Ledger) |
| `includes/Enums/` | CampaignTrigger, LedgerSource, LedgerType, ReferenceType, Status |
| `includes/Helpers/` vs `includes/Utils/` | Helpers = WP-integration glue (Localize, Settings); Utils = framework-agnostic (Dates, Json, Log, Table, SingletonTrait) — boundary documented in `code-standards.md` |
| `includes/Pro/` | Pro-only code (stripped for Lite builds): `Controllers/CampaignPreviewRestController`, `Services/ConditionsEvaluator`, boot seam `YayLoyaltyPro.php` |
| `apps/admin/` | React/TS admin SPA (Vite, pnpm workspace member `@yayloyalty/admin-page`) |
| `apps/admin/src/pages/` | dashboard, points, rewards, referral, vip-tiers, campaigns, settings |
| `apps/admin/src/pro/` | Pro-only SPA slice (campaigns conditions preview), gated at build time |
| `docs/` | code-standards, data-model, deployment-guide (this file added by `/docs summarize`) |
| `plans/` | timestamped implementation plans |

## Build & Tooling

- PM: pnpm workspace (`pnpm-workspace.yaml`) + Composer for PHP deps and php-scoper prefixing.
- `package.json` scripts: `dev`/`build`/`release` delegate to `scripts/run.sh`; `dev:admin`/`build:admin` target the SPA directly.
- PHP lint: `phpcs`/`phpcbf` via Composer scripts, ruleset in `phpcs.xml.dist`.
- `scripts/validate-build.php` verifies packaged zips.

## Recent Work (per git log)

- Utils/Helpers boundary formalized; added `Log` and `Table` utils.
- Pro: campaign conditions, extra triggers, preview REST endpoint.
- Build pipeline packages Pro and Lite from one command each, with verification.
- Core data layer (campaigns, customers, ledger) and admin SPA slices for them landed earlier.

## Unresolved Questions

- No `project-overview-pdr.md`, `system-architecture.md`, `design-guidelines.md`, or `project-roadmap.md` exist yet — only `code-standards.md`, `data-model.md`, `deployment-guide.md` were present before this summary. Run `/docs init` if those are wanted.
- This summary used a light directory/git-log scan, not a full codebase read (per workflow default `should_scan_codebase=false`).
