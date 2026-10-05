# `GET /me/activity` returns a field nothing reads

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the 2026-10-05 `/simplify`, `/yaytidy` and `/code-review` of the storefront launcher, before it merged into `dev/edd` (#41).

## Today

Each item has `label`, `meta`, `points` and `delta` (`ShopperActivityRow::items()`, and the item schema in `StorefrontActivityRestController`). `assets/storefront/launcher.js` and `includes/Engine/Storefront/templates/activity-row.php` read only `label`, `meta` and `delta`, and `delta` already carries the signed value.

## Why it matters

An unused field in a public REST response is a contract kept for nobody. Removing it is cheapest before the first release: the route has not been in a release yet, so nothing outside the plugin can rely on it. After release, removing the field breaks any client that reads it.

## Suggested fix

Drop `points` from `ShopperActivityRow::items()`, its `@return` shape and the item schema. Check that `/me/activity` still returns 200 and that the launcher's markup is unchanged. If a shopper-facing client needs the raw number later, add the field back then.

## Done when

- [ ] `ShopperActivityRow::items()` returns no `points` key, and its `@return` shape doesn't list one.
- [ ] The item schema in `StorefrontActivityRestController` has no `points` property.
- [ ] `GET /me/activity` returns 200 for a signed-in shopper, and the launcher's activity rows render as before.
