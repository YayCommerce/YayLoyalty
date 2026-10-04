# Split Pro and Lite at build time, and validate every zip

**Purpose:** why one tree builds both editions, why the edition is fixed when a zip is built rather than checked at runtime, and why every zip is validated.

**Update when:** never edit it. Supersede it with a new record if the editions stop being split this way.

## Status

Accepted, 2026-10-01, by Trace Bui, who confirmed the reasons. Backfilled: the decision was made on 2026-09-09, in `6dda53e` (the build-time gate, with no Pro flag sent to the browser) and `8a8a827` (packaging and the zip validator).

## Context

- YayLoyalty ships a free edition, Lite, and a paid one, Pro, and the team keeps one codebase for both.
- Lite is meant for WordPress.org, whose plugin guidelines don't allow a hosted plugin to ship locked or trial features, and Pro's paid code mustn't ship in a free download anyway. So Lite can't contain Pro's code, and a licence check can't be what turns Pro on.
- Stripping code out of shared files needs paired markers. yay-reviews strips its premium code that way, and its markers need a validation script of their own to catch the times stripping goes wrong.
- A stripping build fails silently: a zip that still carries code it was meant to drop, or lacks code it was meant to keep, installs, activates and looks correct.

## Decision

- **PHP:** Lite is Pro without `includes/Pro/`. Lite names Pro in one place, the guarded `YayLoyaltyPro::boot()` call in `includes/YayLoyalty.php`, and Pro changes behaviour only through the filters that `boot()` adds. Release deletes the directory; there are no strip markers.
- **SPA:** the edition is a build-time constant, `IS_PRO_BUILD`, set from `BUILD_TYPE` in `apps/admin/vite.config.ts`. Pro code sits behind it, and the Lite build drops that code by dead-code elimination.
- **No runtime edition check:** no Pro flag is sent to the browser, and licence state is never an input. One zip carries both its PHP and its bundle, so the edition is never ambiguous, and there is nothing for a runtime flag to tell apart. An expired licence stops updates, not features.
- **Every zip is validated:** release runs `scripts/validate-build.php` on the zip itself, not on the tree, and stops on a failure. [architecture.md](../architecture.md#pro-and-lite) lists its checks.
- Lite drops Pro behaviour, never Pro data: see [conventions.md](../conventions.md#pro-data-survives-lite).

## Consequences

- The Lite build is a directory deletion plus a flag, with no edits to shared files, and Pro installs over Lite in place.
- Every Pro feature needs a seam: a filter that Lite applies with a working default, or an `IS_PRO_BUILD` branch at the call site in the SPA.
- The SPA split rests on constant folding. A Pro component rendered inside `<ProGate>` without an `IS_PRO_BUILD` branch ships in Lite, and the validator's sentinel check doesn't notice it, so only review catches it.
- Only `scripts/run.sh` sets `BUILD_TYPE`, and a build without it is Pro: see [known-issues.md](../known-issues.md#builds-outside-runsh-are-pro).
- A mistake in the split shows up at release, when the validator runs on the zip, not while developing.
