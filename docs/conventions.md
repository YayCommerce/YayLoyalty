# Conventions

**Purpose:** the team's rules that the code follows but can't show by itself, each with the reason the code gives for it.

**Update when:** the team adopts, changes or drops a convention. A new rule needs a reason found in the code or a teammate who confirms it.

The layering rules live in [architecture.md](architecture.md#server-layers-and-write-ownership) and aren't repeated here: one repository per table, no transactions in repositories, and capability checks only at the HTTP boundary. The hard rules are in [CLAUDE.md](../CLAUDE.md).

## Money

- **Rule:**
  - Stored money is `DECIMAL(19,4)`. PHP carries it as a string and binds it with `%s`, never `%f`. REST sends it as a string, and the SPA parses it with `z.string()`.
  - Computed money, such as a points value or a report total, is rounded once, to the store currency's decimals, never to a fixed 2.
  - Points are integers at every layer.
  - Points, point rates and percentages are shown with WooCommerce's price separators, as money is: `formatStoreNumber`, `formatStoreDecimal`, `formatPercent` and `formatPoints` in the SPA, `SettingsHelper::format_number()` and `points_text()` in PHP. Never `toLocaleString()` or `number_format_i18n()`, which follow the browser or WordPress locale instead.
- **Why:** "a float loses precision exactly where it is a financial defect". A fixed 2 "would drop a digit from a 3-decimal currency (KWD) and round a 0-decimal one (VND) twice". [The decision record](decisions/2026-10-01-money-as-decimal-strings.md) has the context and the costs.
- **In code:** `includes/Models/AbstractModel.php`, `includes/Utils/PointsMath.php`, `includes/Helpers/SettingsHelper.php`, `apps/admin/src/lib/helpers/format.helper.ts`, `apps/admin/src/lib/helpers/points.helper.ts`.

## Dates and time zones

- **Rule:**
  - Store and send datetimes as UTC `Y-m-d H:i:s`, through `includes/Utils/Dates.php`, and never store `current_time()`.
  - Use the site time zone only to group by site-calendar day, as in reports and daily caps.
  - The SPA converts for display and input.
- **Why:** "a site-time value surfaces months later as an off-by-hours expiry". [The decision record](decisions/2026-10-01-store-datetimes-in-utc.md) has the context and the costs.
- **In code:** `includes/Utils/Dates.php`, `apps/admin/src/lib/helpers/format.helper.ts`.

## No limit is null, not 0

- **Rule:**
  - A limit that can be off is stored as `null`, never 0. A set limit is at least 1, or above 0 for money.
  - The server refuses a 0, a negative or, for a whole-number limit, a fraction with a 400. It never reads one as "no limit".
  - The admin shows `null` as an empty field with a "No limit" placeholder (`OptionalCountInput` / `OptionalMoneyInput`).
- **Why:** a cap of 0 blocks everything (`Clamp` stops every redemption at a 0 cap, and `EmailThrottle` would stop every email), and reading it silently as "no limit" hides a client bug. One value for "no limit" keeps every reader's check a plain `null` test.
- **In code:** `SettingsHelper::is_valid()`, `ConfigSpec::is_limit()` (spec type `limit`), `Reward` specs with `exclusive_min: 0`, `apps/admin/src/components/ui/custom/amount-inputs.tsx`.

## Pro data survives Lite

- **Rule:** Lite drops Pro behaviour, never Pro data. Concretely:
  - Enums keep `all()`, what this build offers, apart from `all_known()`, every value that may be stored.
  - Config whitelists keep Pro keys.
  - Config for a value this build doesn't know is saved back untouched.
  - A missing Pro evaluator fails open.
  - Lite names Pro values for the domain (`BIRTHDAY`), never for the edition. A localized key, design and settings keys included, never has the whole word `pro` or `premium`: the validator's boot check fails it in either edition (`product_message` passes).
- **Why:** "'unknown' must never mean 'delete' on a downgrade". Failing closed "would silently disable every conditional campaign the moment a site downgraded from Pro".
- **In code:** `includes/Enums/CampaignTrigger.php`, `includes/Models/Campaign.php`, `includes/Models/Reward.php`, `includes/Engine/Points/PointsEngine.php`, `includes/Helpers/DesignSchema.php`, `apps/admin/src/lib/schema/campaign.schema.ts`.

## TypeScript copies of PHP name their owner

- **Rule:**
  - A TypeScript copy of a PHP shape or constant, such as a zod schema, the capability names or the points maths, opens with a comment whose first sentence is `Mirrors <PHP symbol>, the source of truth.`
  - A copy describes data for the UI. It never repeats PHP's validation.
- **Why:** a rule kept in two places drifts. The per-trigger whitelist "lives in PHP and would drift if duplicated".
- **In code:** `apps/admin/src/lib/schema/`, `apps/admin/src/lib/access.tsx`, `apps/admin/src/lib/points-math/points-math.ts`.

## Wire casing

- **Rule:**
  - REST entities use snake_case, as PHP does. Only the pagination envelope uses camelCase.
  - Map any camelCase the app uses in the `*.api.ts` modules of `apps/admin/src/lib/api/`, nowhere else.
- **Why:** the entities inside the envelope "stay snake_case to mirror PHP", so one shape serves both sides.
- **In code:** `apps/admin/src/lib/api/api.type.ts`, `respond_paginated()` in `includes/Controllers/AbstractRestController.php`.

## File names

- **Rule:**
  - PHP has one class per file. The file is named for the class, and its path under `includes/` follows the namespace. A class name ends in its directory's role, as in `CampaignRestController`, `LedgerRepository`, `CustomersTable` and `SettingsHelper`.
  - TypeScript components are PascalCase `.tsx`, and pages end in `Page`.
  - Other TypeScript modules are kebab-case `<owner>.<role>.ts`, as in `campaign.api.ts`, `campaign.queries.ts`, `campaign.schema.ts` and `design-settings.copy.ts`. A page-local module starts with its page or feature, as in `customer-drawer.ledger-rows.ts`.
  - Shared hooks are named `useThing` and live in `apps/admin/src/hooks/`.
  - The vendored UI primitives in `apps/admin/src/components/ui/` keep their upstream kebab-case names.
  - Tests are `<Subject>Test.php` in `tests/unit/` or `tests/integration/`, and `<module>.test.ts` in `apps/admin/tests/`.
- **Why:** the autoloader maps `YayLoyalty\Models\Customer` to `includes/Models/Customer.php`, so PHP names must match exactly (`phpcs.xml.dist`). The role suffixes and the TypeScript patterns are team convention, with no further reason written down.
- **In code:** the loader in `yayloyalty.php`, `apps/admin/vitest.config.ts`.

## Comments

- **Rule:**
  - Every class opens with a docblock that states its purpose. Comments explain why, not what.
  - A comment says what the code can't: a reason, a constraint or a contract. It doesn't narrate history or design reviews, describe a mockup, or repeat a reason that another file owns.
  - No decorative banners and no all-caps emphasis.
  - A to-do is one line, `TODO(<topic>):`, saying what to build, as in `TODO(redemption):`.
  - Every translatable string with a placeholder has a `translators:` comment. phpcs checks this in PHP; nothing checks it in the SPA. In the SPA it is `/* translators: … */`, directly above the `__()` or `_n()` call.
  - Every `phpcs:ignore` and `phpcs:disable` gives its reason after `--`, and so does every `eslint-disable`.
  - No comment cites another document's path or section number, a feature ID or a design-artboard ID. A comment carries its own reason.
- **Why:**
  - `phpcs.xml.dist` says the codebase "documents behaviour rather than restating signatures". It adds that docblocks "are required by review where the signature is not self-explanatory, not by sniff".
  - A pointer into another document breaks silently when that document changes.
  - A narrated history goes stale when the code moves on. The 2026-10-05 sweep found about 30 comments that named deleted tests, renamed methods or behaviour that had changed.
- **In code:** `phpcs.xml.dist`; `includes/Repositories/AbstractRepository.php` shows the docblock style.
