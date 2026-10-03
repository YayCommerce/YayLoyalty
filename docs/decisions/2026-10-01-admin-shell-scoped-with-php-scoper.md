# Bundle admin-shell, scoped with php-scoper

**Purpose:** why YayLoyalty draws the shared YayCommerce admin through the admin-shell package, and why it ships a scoped copy of it.

**Update when:** never edit it. Supersede it with a new record if the plugin stops using admin-shell or stops scoping it.

## Status

Accepted, 2026-10-01, by Trace Bui, who confirmed the reasons. Backfilled: the decision was made on 2026-08-28, in `0270fd4`, which built the plugin's base on YayWholesale's pattern.

## Context

- YayCommerce plugins share one admin menu and its common pages: licences, other plugins and help. The private Composer package yaycommerce/admin-shell provides them, so no plugin builds its own. Every YayCommerce plugin uses it the same way, and YayLoyalty follows that standard.
- WordPress plugins can't share a Composer dependency: each ships its own copy. PHP declares a class name only once, so when two active plugins bundle the same unscoped classes, the first copy loaded wins, and the other plugin runs against a copy it wasn't built with.
- The package is built to be scoped. Each plugin runs php-scoper with its own prefix before it builds a zip, and when several copies are loaded, the newest one draws the shared menu (the package's README and CONTRIBUTING).

## Decision

- YayLoyalty requires admin-shell through Composer, and ships only a copy scoped under the `YayLoyaltyScoped` prefix (`scoper.inc.php`). The copy is generated into `vendor-prefixed/` and loads through Composer's classmap.
- `YayLoyaltyPluginAdapter.php` stays global and unscoped. It tells the shell about this plugin, and it sees the real WordPress classes.
- The package's own scripts do the scoping, run by `./scripts/run.sh dev` and `release`. [architecture.md](../architecture.md#admin-shell-sync-and-scoping) has the steps.

## Consequences

- YayLoyalty runs beside any other YayCommerce plugin, whatever admin-shell version each one bundles.
- `vendor-prefixed/` is generated and gitignored, so a fresh clone can't install until the sync has run once: see [known-issues.md](../known-issues.md#a-fresh-clones-first-install-fails-on-vendor-prefixedsrc).
- The sync updates dependencies and may rewrite `composer.json` and `scoper.inc.php`: see [known-issues.md](../known-issues.md#scriptsrunsh-dev-updates-dependencies-and-may-rewrite-tracked-files).
- The tooling needs a newer PHP than the product: php-scoper needs PHP 8.2 or later, while the plugin must still run on 7.4.
- Every global name the shell uses, such as a WordPress function, class or constant, has to be excluded in `scoper.inc.php`. A name that isn't excluded gets the prefix and no longer reaches WordPress.
- Each sync fetches the newest admin-shell, so two builds can bundle different versions: see [known-issues.md](../known-issues.md#untracked-lockfiles-let-installs-drift).
