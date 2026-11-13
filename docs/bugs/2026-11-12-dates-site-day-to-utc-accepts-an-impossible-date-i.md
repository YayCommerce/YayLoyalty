---
id: B-1
title: Dates::site_day_to_utc accepts an impossible date instead of returning null
status: fixed
severity: low
editions: [pro, lite]
found_in: 1.0.0
fixed_in:
branch: fix/b-1-dates-site-day-to-utc-accepts-an-impossible-da
pr: 190
created: 2026-11-12
---
# B-1 Dates::site_day_to_utc accepts an impossible date instead of returning null

## Symptoms
Found while setting up tests, not reported by a merchant. A date-range filter given an impossible day (`2026-02-30`) filters from 2 March instead of adding no bound, which `AbstractRepository::site_day_range_where()` documents ("An empty or invalid day adds no bound").

## Environment
Any. Plugin 1.0.0, both editions, any PHP (`DateTimeImmutable::createFromFormat` overflow behaviour).

## Repro steps
1. `php -r 'var_dump(DateTimeImmutable::createFromFormat("!Y-m-d","2026-02-30", new DateTimeZone("UTC"))->format("Y-m-d"));'` prints `2026-03-02` (PHP 8.2.33). `2026-13-01` gives `2027-01-01`, and `2026-2-3` gives `2026-02-03`. Trailing junk (`2026-02-28x`) does fail and returns `false`.
2. So `Dates::site_day_to_utc( '2026-02-30' )` returns the UTC bound for 2 March, not null.
3. Not reachable through REST. The ledger and activity routes validate `from` and `to` with `LedgerQueryArgs::validate_date()` (`includes/Controllers/Schemas/LedgerQueryArgs.php:106-114`). It applies the same strict round-trip check, so an impossible day gets a 400 before it reaches the repository. The bug only shows for a direct PHP caller of `site_day_to_utc()` or `site_day_range_where()`.

## Root cause
`Dates::site_day_to_utc()` (`includes/Utils/Dates.php:71-79`) parses the day with `\DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() )` (`:72`). PHP rolls an out-of-range day or month over into the next valid date instead of returning `false`, and it accepts unpadded parts. The method rejects only `false === $start` (`:74`), so an impossible day becomes a real bound. That breaks its own docblock, "Null when $ymd is not a valid `Y-m-d`" (`:69`), and the contract of `AbstractRepository::site_day_range_where()`, "An empty or invalid day adds no bound" (`includes/Repositories/AbstractRepository.php:276-277`, call at `:285-286`).

The other callers can't reach it either: `CampaignPerformanceRestController.php:262,266` and `DashboardNotices.php:126-127` pass days they formatted themselves with `format( 'Y-m-d' )`.

Introduced in 957cc74 (2026-10-05, "Enable filter in ledger tab of customer drawer", #43), so it is in 1.0.0.

Confidence: high. The overflow is PHP's documented `createFromFormat` behaviour, reproduced above, and the code checks nothing else.

## Fix options
1. **Recommended.** In `site_day_to_utc()`, return null unless `$start->format( 'Y-m-d' ) === $ymd`. One line in `includes/Utils/Dates.php`, no edition impact. It also rejects unpadded days (`2026-2-3`), which matches the docblock's strict `Y-m-d`.
2. Check `\DateTimeImmutable::getLastErrors()` for warnings after parsing. This is more code: PHP 7.4 returns an array, and 8.2+ returns `false` when there are no errors. It also still lets unpadded days through.
3. Also give `from` and `to` a `Y-m-d` pattern in the ledger and activity REST args. That is a bigger change and only covers REST. Option 1 already fixes every caller.

## Regression test proposal
[unit] `DatesTest::test_an_impossible_day_gives_no_bound` in a new `tests/php/unit/Utils/DatesTest.php` (Brain Monkey, stub `wp_timezone()` to UTC). It asserts that `site_day_to_utc()` returns null for `'2026-02-30'`, `'2026-13-01'` and `'2026-2-3'`, and that `'2026-02-28'` and `'2026-02-28', '+1 day'` return `2026-02-28 00:00:00` and `2026-03-01 00:00:00`. This guards the rollover the root cause lives in.

## Fix
`Dates::site_day_to_utc()` (`includes/Utils/Dates.php:74-76`) now returns null unless the parsed day formats back to exactly the input. The check is the same strict round-trip `LedgerQueryArgs::validate_date()` already uses. That is enough because every caller goes through this one method: the REST paths already validate this way, and the dashboard and campaign-performance callers pass `format( 'Y-m-d' )` output, which always round-trips. Unpadded input (`2026-2-3`) is now rejected as well. No caller sends that form.

Verified:
- `tests/php/unit/Utils/DatesTest.php` (`@group B-1`) fails without the fix (`2026-02-30` gave a bound) and passes with it.
- `yayh test run unit`: 69 OK.
- `composer test`: 70 OK.
- `php -l` and phpcs on `Dates.php`: clean. The test file has only the namespace-prefix error that every test file has.
- yay:bug-reviewer: clean. It also checked the site-timezone and DST cases by reading the code. A DST gap at midnight moves to 01:00 on the same day, so the date still round-trips.

