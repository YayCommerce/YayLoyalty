---
area: build-release
updated: 2026-11-12
updated_ref: 3f6424ea
covers: [scripts/, .github/workflows/, composer.json, package.json, pnpm-workspace.yaml, .nvmrc, phpcs.xml.dist, scoper.inc.php, readme.txt]
---

# Build and release

> Update when: `scripts/run.sh`, `scripts/validate-build.php`, the admin-shell patch, CI, or the toolchain pins change.

## Toolchain

- Node 22 (`.nvmrc`, `engines`), pnpm pinned by `packageManager` (`package.json`); workspaces `apps/admin`, `apps/e2e`. `apps/admin` runs `npx only-allow pnpm` on install.
- Root `postinstall` runs `composer install`, which clones the private `yaycommerce/admin-shell` repo over HTTPS.
- No lockfiles are tracked (`.gitignore`), so installs aren't reproducible and CI can't freeze or cache.
- `scripts/run.sh` resolves pnpm through PATH, then `corepack pnpm`, then the newest Node under `~/.nvm`.

## The admin shell and `vendor-prefixed/`

`yaycommerce/admin-shell` (Composer) is scoped by php-scoper (`scoper.inc.php`) into the `YayLoyaltyScoped` namespace and **committed** as `vendor-prefixed/` (`f197818e`). `YayLoyaltyPluginAdapter` is un-namespaced and excluded from scoping so its `class_exists()` calls see real WordPress and WooCommerce symbols. `dev` and `release` never rebuild it in the working tree; only `./scripts/run.sh shell-update` (`vendor/bin/yaycommerce-update`) does, through a temp folder that's swapped in. Commit what it changes, and check `git status` afterwards.

## `./scripts/run.sh`

`help` lists everything. `dev`, `build` and `release` take `pro` (default) or `lite`; `free` and `premium` are aliases.
- `dev`: `composer install` if `vendor/` is missing → install admin deps if missing → build storefront CSS and blocks once → Vite on :3030.
- `build`: `BUILD_TYPE=<edition> pnpm build` in `apps/admin` (see [admin-spa.md](admin-spa.md#builds)).
- `release [edition] [version] [--no-prompt]`: below.
- `bump`, `info`, `clean`, `backlog` (lists `docs/backlog/`).

## Release

`build_package` in `scripts/run.sh`:
1. Stamp the version (`Version:` header and `YAYLOYALTY_VERSION` in `yayloyalty.php`, `Stable tag:` in `readme.txt`) in the **working tree**. Run Pro first, then Lite with `--no-prompt`, so both carry the same number.
2. Build the SPA for the edition.
3. Stage a copy in `release/<folder>`: `yayloyalty-pro/` for Pro, `yayloyalty/` for Lite. Flip `YAYLOYALTY_IS_DEVELOPMENT` to `false` in the staged main file. Copy only `vendor/autoload.php` and `vendor/composer`. Rescope the shell into the stage (`vendor/bin/yaycommerce-prerelease`).
4. Lite only: delete `includes/Pro/`, drop " Pro" from the `Plugin Name:` header, apply `scripts/lite-admin-shell.patch` to the staged shell, delete the `LITE_SHELL_STRIP` paths (licensing, EDD updater, Recommended Plugins…), `php -l` the patched files. This is the WordPress.org-ready build (`40d6d9b1`).
5. `composer dump-autoload --no-dev --classmap-authoritative`; rewrite the shell's text domain to `yayloyalty`; delete `RegisterDev.php`, source maps, `.vite`, `.DS_Store`, `.gitkeep`.
6. Write the release manifest (SHA-256 per file), zip to `release/yayloyalty-pro-<v>.zip` or `release/yayloyalty-lite-<v>.zip`, run `php scripts/validate-build.php <zip> <edition>`. A failed validation renames the zip `*.zip.invalid` and exits 1.

The Lite patch registers the shell at version `0.0.1`, so a full shell from another active YayCommerce plugin wins the election. The patch is made against `vendor-prefixed/`; after `shell-update` it can stop applying and must be regenerated.

## `scripts/validate-build.php`

Checks the zip, not the tree: root folder; manifest, versions and hashes; `includes/Pro/` absent (Lite) or present (Pro); one `assets/dist/admin/js/main.js` entry chunk; `PRO_BUNDLE_SENTINEL` absent from Lite and present in Pro; the Lite upsell copy kept; no Pro flag in localized data; `php -l` on every file; Lite names Pro only in the guarded seam in `includes/YayLoyalty.php`; shell licensing stripped; text domain; no dev artifacts; header names the edition; `YAYLOYALTY_IS_DEVELOPMENT` false; one hard-coded inline Pro field gate (`custom_pages` in `DesignVisibilitySection.tsx`). With `YAYLOYALTY_WP_CLI` set it also boots the build headless. The header of the script documents its env vars.

The sentinel only proves whole Pro sections were tree-shaken. A deep `@/pro/...` import or a bare `<ProGate>` around Pro JSX ships Pro code that the validator doesn't see; only review catches it.

## CI (`.github/workflows/`)

Both run on PRs and pushes to `dev`, filtered by path:
- `php.yml`: `php -l` over product PHP on PHP 7.4. No phpcs, no PHPUnit.
- `js.yml`: `pnpm install --filter @yayloyalty/admin-page --ignore-scripts` (skips the Composer postinstall, which needs the private shell) and `tsc --noEmit`.

Unit, integration, e2e, lint and release validation don't run in CI.
