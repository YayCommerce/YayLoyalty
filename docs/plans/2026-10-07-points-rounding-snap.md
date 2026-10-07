# Fix float error in points rounding

**Status:** active
**Branch:** `trace/feat/campaign-spend-rate`. It goes in as a second commit after the spend-rate commit, as the user chose.
**Tests:** `tests/unit/Utils/PointsMathTest.php` (unit suite, `composer test`) and `apps/admin/tests/points-math.test.ts` (Vitest). Both use the same cases, and the user accepted them.

## Context

The purchase engine computes `raw = base × rate` and then rounds it with `PointsMath::round_points()` (`includes/Utils/PointsMath.php`). That is `floor`, `ceil` or `round` on a binary float, with no tolerance. When the true value is a whole number, float error can push it to the wrong side. Checked in PHP on this machine:

| Calculation | Float result | Rounding | Today | Should be |
|---|---|---|---|---|
| 0.57 × 100 | 56.999999999999993 | Down | 56 | 57 |
| 0.07 × 100 | 7.0000000000000009 | Up | 8 | 7 |

The new "Spend X to receive Y" rates (Y ÷ X, such as 0.0001 or 1/3) make such rates common. `ClawbackMath` (`includes/Engine/Earning/ClawbackMath.php:27`) uses the same function for refunds, so it gets the same fix. The goal is that a value which is mathematically whole rounds as that whole number in every mode.

## Change

1. **`PointsMath::round_points()`:** snap the raw value before choosing the direction. Add `$raw = round( $raw, self::SNAP_DECIMALS );` with `private const SNAP_DECIMALS = 9;`, then run the existing switch. The docblock gets one line: snap first, so float error never crosses a whole number.
   - **Why 9:** money is at most 4 decimals and rates are human-entered. A genuine raw value within 1e-9 of a whole number, but not equal to it, doesn't occur, while float error sits around 1e-14.
   - **PHP 7.4:** `round()` with a precision argument is fine.
2. **TS mirror `roundPoints()`:** `apps/admin/src/lib/points-math/points-math.ts` applies the same snap, using the existing PHP-compatible `roundTo(raw, 9)` before `ceil`, `floor` or nearest. Update its comment. The mirror keeps the Lite worked example (`BuiltInCampaignList.tsx:412`) in step with the engine.
3. **Tests:** the same cases go in both files.

   | Raw | Down | Nearest | Up | What it guards |
   |---|---|---|---|---|
   | `0.57 * 100` | 57 | 57 | 57 | Error just below a whole number |
   | `0.07 * 100` | 7 | 7 | 7 | Error just above a whole number |
   | `2.5` | 2 | 3 | 3 | Ordinary value unchanged |
   | `0.3 * 3` (0.9) | 0 | 1 | 1 | Ordinary value unchanged |
   | `0.0` | 0 | 0 | 0 | Zero unchanged |
   | `(1 / 3) * 3` | 1 | 1 | 1 | Spend-rate case |

   - The PHP test is a plain `PHPUnit\Framework\TestCase` with a data provider, following `tests/unit/Models/RewardValidationTest.php` for namespace and style.
   - The Vitest file uses `it.each`.

**Out of scope:**
- Rounding `base` or `rate` themselves.
- Switching to bcmath or decimal strings.
- `pct_delta`, `pct_of` and `points_to_currency`, which already round to fixed decimals.

## Steps
1. Commit the pending spend-rate work as `feat(campaigns): enter the purchase rate as spend X to receive Y`. Stage it by name: the three SPA files and `docs/plans/2026-10-07-campaign-spend-rate.md`.
2. Save this plan as `docs/plans/2026-10-07-points-rounding-snap.md`.
3. Make changes 1–3.
4. Commit only when asked: `fix(points): snap raw points before rounding so float error never crosses a whole number`.

## Verification
- Run `composer test`. The new PointsMathTest cases should pass, and should fail against the old `round_points` (check by temporarily stashing the PHP change).
- Run `pnpm --filter @yayloyalty/admin-page test`, which should give 64 tests plus the new cases, all passing.
- Lint only the changed PHP files: `composer lint -- includes/Utils/PointsMath.php tests/unit/Utils/PointsMathTest.php`. Run eslint and prettier on `points-math.ts` and the new test file.
- Optional end to end: a campaign at Spend 100 → 57 pts on a test order of 100.00 should give a ledger row of 57 points, not 56.
