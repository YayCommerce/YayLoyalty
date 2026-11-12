---
id: B-1
title: Dates::site_day_to_utc accepts an impossible date instead of returning null
status: open
severity: low
editions: [pro, lite]
found_in:
fixed_in:
branch:
pr:
created: 2026-11-12
---
# B-1 Dates::site_day_to_utc accepts an impossible date instead of returning null

## Symptoms
Found while setting up tests, not reported by a merchant. A date-range filter given an impossible day (`2026-02-30`) filters from 2 March instead of adding no bound, which `AbstractRepository::site_day_range_where()` documents ("An empty or invalid day adds no bound").

## Environment
Any. Plugin 1.0.0, both editions, any PHP (`DateTimeImmutable::createFromFormat` overflow behaviour).

## Repro steps
1. `php -r 'var_dump(DateTimeImmutable::createFromFormat("!Y-m-d","2026-02-30", new DateTimeZone("UTC")));'` prints `2026-03-02 00:00:00`, not `false`.
2. So `Dates::site_day_to_utc( '2026-02-30' )` returns the UTC bound for 2 March instead of null.

## Root cause
`includes/Utils/Dates.php` `site_day_to_utc()`: `createFromFormat( '!Y-m-d', … )` rolls an out-of-range day or month over instead of failing, and the method only checks `false === $start`. Its docblock says "Null when $ymd is not a valid `Y-m-d`". Callers: `Repositories/AbstractRepository::site_day_range_where()` (list filters), `Services/Dashboard/DashboardNotices.php`, `Pro/Controllers/CampaignPerformanceRestController.php`. Confidence: high.

## Fix options
1. After parsing, return null unless `$start->format( 'Y-m-d' ) === $ymd` (also rejects unpadded `2026-2-3`).
2. Check `DateTimeImmutable::getLastErrors()` warnings after parsing (PHP 7.4 returns an array; 8.2+ may return false).

## Regression test proposal
[unit] `DatesTest::test_an_impossible_day_gives_no_bound` (`tests/php/unit/Utils/DatesTest.php`) — `site_day_to_utc( '2026-02-30' )` and `( '2026-2-3' )` return null, a valid day still returns its UTC bound. Stub `wp_timezone()`.
