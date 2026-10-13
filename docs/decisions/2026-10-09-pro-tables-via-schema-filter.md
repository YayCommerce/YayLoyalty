# Pro registers its own tables, and the installer re-installs when a registered table was never installed

**Purpose:** why Pro-only tables reach the installer through a filter, and why the installer checks installed table slugs as well as the plugin version.

**Update when:** never edit it. Supersede it with a new record if Pro tables move into the core list, or the installer stops tracking installed slugs.

## Status

Superseded by [Both builds keep points lots](2026-10-13-lots-in-core.md), 2026-10-13. It was never accepted.

## Context

- Per-earning points expiry needs two tables, `yayloyalty_lots` and `yayloyalty_lot_allocations`, and only Pro uses them. Lite drops Pro behaviour, never Pro data, so a downgrade must not delete them.
- `Installer::schemas()` already applies `yayloyalty_table_schemas`, and Pro attaches to Lite only through filters that `YayLoyaltyPro::boot()` adds.
- `Installer::maybe_upgrade()` used to run `install()` only when `yayloyalty_db_version` differed from `YAYLOYALTY_VERSION`. A table registered by a filter could still be skipped in three cases:
  - Activation: `ActDeact::activate()` runs `install()` before `plugin_init` boots Pro, so the filter isn't attached and the version gets stamped without the Pro tables.
  - A Lite site switched to Pro at the same version: the stored version already matches.
  - A Pro site with WooCommerce inactive: Pro doesn't boot, but `maybe_upgrade()` still installs and stamps the version.
- Options turned down:
  - Putting the tables in the core list, installed in both builds: simplest, but every Lite site would carry two empty Pro tables.
  - Pro running its own `dbDelta` behind a `yayloyalty_pro_db_version` option: it duplicates the install lock and `verify()`.
  - Stamping `yayloyalty_db_version` as a version plus a hash of the slugs: the option would stop meaning the plugin version.

## Decision

- A table only Pro uses gets its schema class under `includes/Pro/Database/Schema/`, and `YayLoyaltyPro::boot()` adds it through `yayloyalty_table_schemas`. Lite sites never create it.
- `Installer::install()` stores the slugs it installed in `yayloyalty_db_tables`, before the version stamp.
- `Installer::maybe_upgrade()` runs `install()` when the version differs, or when a registered slug is missing from `yayloyalty_db_tables`. A slug that is stored but no longer registered doesn't count.
- A downgrade to Lite leaves Pro tables and their rows in place. Lite neither verifies nor writes them.

## Consequences

- A new Pro table reaches every Pro site on the next request, without a version bump. That includes sites that installed as Lite or activated before Pro booted.
- Every existing install runs `install()` once after this ships, because `yayloyalty_db_tables` is absent at first. Like any version bump, that install runs at `plugins_loaded` and can log WordPress's early-translation notice under `WP_DEBUG`.
- `maybe_upgrade()` reads one more autoloaded option on every request.
- A Pro table that `dbDelta` can't create is stored as installed anyway, exactly as the version stamp already behaves. `verify()` logs it, and only the next version bump retries.
- After a Pro→Lite→Pro round trip the Pro tables are present but stale, since Lite never wrote to them. Rebuilding them is up to the feature that writes them.
