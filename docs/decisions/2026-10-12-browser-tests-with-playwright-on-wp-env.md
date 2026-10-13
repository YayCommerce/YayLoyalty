# Run browser tests with Playwright on wp-env

**Purpose:** why the browser tests are Playwright against a wp-env site, apart from the integration suite's Local site, and what they cover.

**Update when:** never edit it. Supersede it with a new record if the browser tests move to another tool or another site, or start covering Lite.

## Status

Proposed, 2026-10-12.

## Context

- The repo tested PHP with PHPUnit (unit, and integration against the developer's own Local site) and the SPA with Vitest in Node, with no DOM. Nothing drove a browser, so plans closed with a manual "end-to-end check". The points workflow crosses WooCommerce checkout, the order screen, the storefront and the admin SPA, and only a browser sees all of them.
- The integration suite's site is a working Local site with real data. It keeps tests apart by table prefix, which works for PHP calls inside one process but not for a browser, which reads the site's real tables.
- Options turned down:
  - **Browser tests on the Local site:** fast to start, but the tests would write orders, users and ledger rows into a site people use, and depend on whatever that site's settings are.
  - **Integration tests only:** they cover the ledger math, but not checkout, the order screen, the admin dialog or what the shopper sees.
  - **Cypress or Puppeteer:** WordPress's own tooling (`@wordpress/env`, Gutenberg's e2e utilities) is built around Playwright.

## Decision

- Browser tests live in `apps/e2e/`, a private workspace package that never ships (`apps/` is outside the zip). They use Playwright.
- They run against wp-env's tests site, which `apps/e2e/.wp-env.json` pins to exact WordPress and WooCommerce versions and seeds with an idempotent script. The site is disposable, and no developer's site is touched.
- The ledger and points math stays in the PHPUnit integration suite on the Local site. The browser tests check that a shopper's or merchant's action reaches the ledger and shows up on screen.
- Specs set up state and assert totals through REST, as the admin with an application password. They act through the UI, and make a fresh customer per test.
- They cover the Pro build only, on PHP 8.2: the working tree's dev `vendor/` can't load on PHP 7.4.

## Consequences

- A change to checkout, the order screen, the storefront or the admin SPA can be checked end to end by a command, not by hand.
- Running them needs Docker, a built `pro` SPA and a Chromium download, so they are slower to start than the other suites, and nothing runs them automatically yet.
- The PHP 7.4 floor and the Lite build stay unchecked by them. Review and the release validator still own those.
- Pinned WordPress and WooCommerce versions have to be raised by hand. Until then, the tests can pass on versions that stores no longer run.
