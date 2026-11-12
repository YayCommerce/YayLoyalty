# Phase 1: Calendar engine through REST

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** nothing. The Rolling plan (`docs/plans/2026-11-05-tier-rolling-mode/`) is done.
**Delivers:** Calendar mode works through REST: the settings, upgrades with period holds, the refund and deduction rules, the daily review and silent renewal, `to_calendar` on switch-in and on a period change, and leaving Calendar. The SPA keeps rendering when the API reports Calendar.
**Exit criterion:**
- `composer test -- --filter TierCalendarTest` passes (all cases).
- `composer test:integration -- --filter TierEvaluatorTest` passes, every existing case included, with the test count shown in the summary.
- On wp-env, Task 5's REST checks pass. These cover spec AC 1, 2, 3, 8 and 14. Spec AC 4–7 and 9–13 are covered by Task 3's and Task 4's integration cases.

Spec ACs below use the spec's thresholds (Bronze 0 / Silver 500 / Gold 1,000). The integration tests use the seeded defaults (Bronze 0 / Silver 800 / Gold 3,000), as the existing `TierEvaluatorTest` does, with the same rules.

### Task 1: The Calendar mode value and period setting (Lite)

**Files:**
- Modify: `includes/Enums/TierMode.php`
- Modify: `includes/Helpers/SettingsHelper.php` (`TIER_KEYS` at :37, defaults at :55, the schema map at :89, validation at :285, sanitizing at :372)

**Interfaces:**
- Consumes: nothing.
- Produces: the `TierMode` constants and methods, and the `tiers_calendar_period` setting, as in the README contract.

- [ ] **Step 1: Extend `TierMode`.**
  - Add `CALENDAR`, `CALENDAR_MONTH`, `CALENDAR_QUARTER`, `CALENDAR_YEAR`, `CALENDAR_PERIODS` and `DEFAULT_CALENDAR_PERIOD`, with `CALENDAR` added to `all()`.
  - Add `is_calendar_period( $value ): bool`: true only for a string in `CALENDAR_PERIODS`.
  - Add `calendar_period_months( string $period ): int`: `month` → 1, `quarter` → 3, anything else → 12.
  - The class docblock names the three modes: "every point earned, the points in a rolling window, or the points in fixed calendar periods".

- [ ] **Step 2: Add the setting to `SettingsHelper`.**
  - `TIER_KEYS` gains `'tiers_calendar_period'`.
  - Defaults gain `'tiers_calendar_period' => TierMode::DEFAULT_CALENDAR_PERIOD`.
  - The schema map gains `'tiers_calendar_period' => 'tier_calendar_period'`.
  - `case 'tier_calendar_period':` validates with `TierMode::is_calendar_period( $value )` and sanitizes to `is_valid_value( $value, $type ) ? (string) $value : null`, beside the `tier_rolling_months` cases.

- [ ] **Step 3: Verify.**
  - Run: `$WP eval 'echo wp_json_encode( [ YayLoyalty\Helpers\SettingsHelper::get_settings()["tiers_calendar_period"], YayLoyalty\Enums\TierMode::all() ] );'`. Expected: `["year",["lifetime","rolling","calendar"]]`.
  - Run: `composer lint -- includes/Enums/TierMode.php includes/Helpers/SettingsHelper.php`. Expected: no error or warning.

- [ ] **Step 4: Commit.**
  - Stage: `includes/Enums/TierMode.php`, `includes/Helpers/SettingsHelper.php`.
  - Message: `feat(tiers): add the calendar tier mode and its period setting`

### Task 2: `TierCalendar`, the period date math

**Files:**
- Create: `includes/Pro/Services/TierCalendar.php`
- Test: `tests/unit/Pro/TierCalendarTest.php`

**Interfaces:**
- Consumes: `TierMode::calendar_period_months()` (Task 1), and `Models\Tier::get_tier_rank()`.
- Produces: `TierCalendar`, as in the README contract.

Shared fixtures in the test:
- `HCM = new \DateTimeZone( 'Asia/Ho_Chi_Minh' )` (UTC+7, no DST).
- `NY = new \DateTimeZone( 'America/New_York' )`.
- `tier( $id, $rank )` built as in `TierDecisionTest::tier()`.

- [ ] **Step 1: Write the failing tests.**

```php
public function test_bounds_per_period(): void {
    $this->assertSame( [ 'current_start' => '2025-12-31 17:00:00', 'next_start' => '2026-12-31 17:00:00', 'previous_start' => '2024-12-31 17:00:00' ], TierCalendar::bounds( '2026-11-11 05:00:00', 'year', $this->hcm() ) );
    $this->assertSame( [ 'current_start' => '2026-09-30 17:00:00', 'next_start' => '2026-12-31 17:00:00', 'previous_start' => '2026-06-30 17:00:00' ], TierCalendar::bounds( '2026-11-11 05:00:00', 'quarter', $this->hcm() ) );
    $this->assertSame( [ 'current_start' => '2026-10-31 17:00:00', 'next_start' => '2026-11-30 17:00:00', 'previous_start' => '2026-09-30 17:00:00' ], TierCalendar::bounds( '2026-11-11 05:00:00', 'month', $this->hcm() ) );
}

public function test_a_boundary_second_belongs_to_the_new_period(): void {
    $this->assertSame( '2025-12-31 17:00:00', TierCalendar::bounds( '2025-12-31 17:00:00', 'year', $this->hcm() )['current_start'] );
    $this->assertSame( '2024-12-31 17:00:00', TierCalendar::bounds( '2025-12-31 16:59:59', 'year', $this->hcm() )['current_start'] );
}

public function test_bounds_follow_wall_clock_across_dst(): void {
    $this->assertSame( [ 'current_start' => '2027-01-01 05:00:00', 'next_start' => '2027-04-01 04:00:00', 'previous_start' => '2026-10-01 04:00:00' ], TierCalendar::bounds( '2027-02-10 12:00:00', 'quarter', $this->ny() ) );
}

public function test_hold_dates_per_source(): void {
    $year = TierCalendar::bounds( '2026-11-11 05:00:00', 'year', $this->hcm() );

    $this->assertSame( [ 'from' => '2026-12-31 17:00:00', 'until' => '2027-12-31 16:59:59' ], TierCalendar::hold_dates( 'current', $year, 'year', $this->hcm() ) );
    $this->assertSame( [ 'from' => '2025-12-31 17:00:00', 'until' => '2026-12-31 16:59:59' ], TierCalendar::hold_dates( 'previous', $year, 'year', $this->hcm() ) );
    $this->assertSame( [ 'from' => '2026-11-30 17:00:00', 'until' => '2026-12-31 16:59:59' ], TierCalendar::hold_dates( 'current', TierCalendar::bounds( '2026-11-11 05:00:00', 'month', $this->hcm() ), 'month', $this->hcm() ) );
    $this->assertSame( [ 'from' => '2026-12-31 17:00:00', 'until' => '2027-03-31 16:59:59' ], TierCalendar::hold_dates( 'current', TierCalendar::bounds( '2026-11-11 05:00:00', 'quarter', $this->hcm() ), 'quarter', $this->hcm() ) );
    // Spec AC 15.
    $this->assertSame( [ 'from' => '2027-01-01 05:00:00', 'until' => '2027-04-01 03:59:59' ], TierCalendar::hold_dates( 'current', TierCalendar::bounds( '2026-11-11 12:00:00', 'quarter', $this->ny() ), 'quarter', $this->ny() ) );
}

public function test_qualifying_window_is_the_period_that_earned_the_hold(): void {
    $this->assertSame( [ 'from' => '2025-12-31 17:00:00', 'to' => '2026-12-31 16:59:59' ], TierCalendar::qualifying_window( '2026-12-31 17:00:00', '2027-12-31 16:59:59', 'year', $this->hcm() ) );
    $this->assertSame( [ 'from' => '2026-10-01 04:00:00', 'to' => '2027-01-01 04:59:59' ], TierCalendar::qualifying_window( '2027-01-01 05:00:00', '2027-04-01 03:59:59', 'quarter', $this->ny() ) );
}

public function test_qualifying_length_survives_a_time_zone_and_period_change(): void {
    // A yearly hold written in HCM, read in NY with the period now `month`: L stays 12.
    $this->assertSame( [ 'from' => '2025-12-31 17:00:00', 'to' => '2026-12-31 16:59:59' ], TierCalendar::qualifying_window( '2026-12-31 17:00:00', '2027-12-31 16:59:59', 'month', $this->ny() ) );
}

public function test_bad_hold_uses_the_setting(): void {
    $expected = [ 'from' => '2026-06-30 17:00:00', 'to' => '2026-09-30 16:59:59' ];

    $this->assertSame( $expected, TierCalendar::qualifying_window( null, '2026-12-31 16:59:59', 'quarter', $this->hcm() ) );
    $this->assertSame( $expected, TierCalendar::qualifying_window( '2027-01-01 00:00:00', '2026-12-31 16:59:59', 'quarter', $this->hcm() ) );
}

public function test_pick_prefers_rank_then_current(): void {
    $silver = $this->tier( 2, 2 );
    $gold   = $this->tier( 3, 3 );

    $this->assertSame( [ $silver, 'current' ], TierCalendar::pick( $silver, $this->tier( 2, 2 ) ) );
    $this->assertSame( [ $gold, 'previous' ], TierCalendar::pick( $silver, $gold ) );
    $this->assertSame( [ $gold, 'current' ], TierCalendar::pick( $gold, null ) );
    $this->assertSame( [ null, 'current' ], TierCalendar::pick( null, null ) );
}
```

- [ ] **Step 2: Run the tests and confirm they fail.**
  - Run: `composer test -- --filter TierCalendarTest`.
  - Expected: errors, because class `YayLoyalty\Pro\Services\TierCalendar` is not found.

- [ ] **Step 3: Implement `TierCalendar` in `includes/Pro/Services/TierCalendar.php`.**
  - It is `final` with only static methods, and calls no WordPress function. Callers pass `wp_timezone()`.
  - `bounds()`:
    - converts `$now_utc` to `$tz`, then sets the time to 00:00 on day 1 of the month, of the quarter's first month (Jan, Apr, Jul or Oct), or of January;
    - `next_start` adds `calendar_period_months( $period )` months, and `previous_start` subtracts them;
    - all three are formatted through `Dates::to_utc()`.
  - `pick()`: the higher `get_tier_rank()` wins, a tie or two nulls give `SOURCE_CURRENT`, and a null loses to a tier.
  - `hold_dates()`:
    - `current`: `from` = `next_start`, `until` = `next_start` + m months − 1 s, with wall-clock months in `$tz`;
    - `previous`: `from` = `current_start`, `until` = `next_start` − 1 s.
  - `qualifying_window()`:
    - when `$held_from` is null or not before `$held_until`, the start is `$held_until` + 1 s − m(`$period`) months in `$tz`, and L = m;
    - otherwise L = the months from `$held_from` to `$held_until` + 1 s in `$tz`, as y × 12 + m, plus 1 when the leftover is 15 days or more, and at least 1;
    - the result is `from` = start − L months in `$tz`, `to` = start − 1 s.
  - The class docblock states the period rule (site-time-zone wall clock; a period is [start, next − 1 s]) and that the evaluator is its only caller besides `TierStanding` and `TierProgress`.

- [ ] **Step 4: Run the tests and confirm they pass.**
  - Run: `composer test -- --filter TierCalendarTest`. Expected: OK (8 tests).
  - Run: `composer lint -- includes/Pro/Services/TierCalendar.php tests/unit/Pro/TierCalendarTest.php`. Expected: no error or warning.

- [ ] **Step 5: Commit.**
  - Stage: `includes/Pro/Services/TierCalendar.php`, `tests/unit/Pro/TierCalendarTest.php`.
  - Message: `feat(tiers): add calendar period boundaries and hold dates`

### Task 3: Calendar evaluation in `TierEvaluator`

**Files:**
- Modify: `includes/Pro/Services/TierEvaluator.php` (`mode()` at :89, `apply()`'s mode branch at :331, the class docblock at :27)
- Test: `tests/integration/Services/TierEvaluatorTest.php`

**Interfaces:**
- Consumes:
  - `TierCalendar` (Task 2);
  - `TierMode::CALENDAR` and `is_calendar_period()` (Task 1);
  - `LedgerRepository::window_total()` and `staff_adjust_since()`;
  - `TierDecision::rolling_move()`, `target()`, `HOLD_*` and `KIND_*`.
- Produces: `TierEvaluator::mode()` returning `'calendar'`, and `TierEvaluator::calendar_period(): string`.

Test fixtures to add to `TierEvaluatorTest`:
- property `$calendar_period = 'year'`, returned by `pinned_settings()` as `tiers_calendar_period`;
- `with_calendar( string $period, callable $body ): void`: like `with_rolling()`. It sets `tiers_mode` to `calendar` and the period, and while `$body` runs it adds a `pre_option_timezone_string` filter that returns `'Asia/Ho_Chi_Minh'`. It restores both in `finally`.
- `tz(): \DateTimeZone` → `Asia/Ho_Chi_Minh`;
- `calendar_hold( string $source, string $period ): array`: `[ from, until ]` from `TierCalendar::hold_dates( $source, TierCalendar::bounds( Dates::now(), $period, $this->tz() ), $period, $this->tz() )`;
- `in_previous_period( string $period ): string`: `Dates::offset( DAY_IN_SECONDS, bounds['previous_start'] )`;
- `held_from_previous( int $points ): LedgerEntry`:
  - a new customer whose earn of `$points` (order 3100, written with tiers off) is backdated to `in_previous_period( 'year' )`;
  - `set_customer` sets `tier_id` Gold, and the hold to `calendar_hold( 'previous', 'year' )` (computed inside the timezone filter);
  - it returns the earn.

- [ ] **Step 1: Write the failing tests.**

```php
public function test_calendar_upgrade_holds_through_next_period(): void {
    $yearly  = $this->customer();
    $monthly = $this->customer();

    $this->with_calendar( 'year', function () use ( $yearly ): void {
        $this->earn( $yearly, 3001, 900 );
        $this->assertSame( 'Silver', $this->tier_of( $yearly ) );
        $this->assertSame( $this->calendar_hold( 'current', 'year' ), $this->hold_of( $yearly ) );
        $this->assertSame( 900, (int) $this->customer_row( $yearly )['tier_progress_value'] );
    } );
    $this->with_calendar( 'month', function () use ( $monthly ): void {
        $this->earn( $monthly, 3002, 900 );
        $this->assertSame( $this->calendar_hold( 'current', 'month' ), $this->hold_of( $monthly ) );
    } );
}

public function test_calendar_refund_inside_and_outside_the_qualifying_period(): void {
    $old  = $this->held_from_previous( 3000 );
    $user = $old->get_user_id();
    $hold = $this->hold_of( $user );

    $this->with_calendar( 'year', function () use ( $old, $user, $hold ): void {
        $new = $this->earn( $user, 3003, 100 );
        $this->ledger_service()->refund_earn( $new, 100, 'refund', 2 ); // Kept-period order: no effect.
        $this->assertSame( 'Gold', $this->tier_of( $user ) );
        $this->assertSame( $hold, $this->hold_of( $user ) );

        $this->ledger_service()->refund_earn( $old, 500, 'refund', 3 ); // Previous and qualifying 2,500.
        $this->assertSame( 'Silver', $this->tier_of( $user ) );
        $this->assertSame( [ 'refund' ], $this->last_change( $user, [ 'reason' ] ) );
        $this->assertSame( $this->calendar_hold( 'previous', 'year' ), $this->hold_of( $user ) );
    } );
}

public function test_calendar_staff_deduction_counts_against_the_hold(): void {
    $user = $this->held_from_previous( 3200 )->get_user_id();

    $this->with_calendar( 'year', function () use ( $user ): void {
        $this->ledger_service()->adjust( $user, -100, 'staff', 1 ); // Previous and qualifying 3,100.
        $this->assertSame( 'Gold', $this->tier_of( $user ) );

        $this->ledger_service()->adjust( $user, -200, 'staff', 1 ); // Previous and qualifying 2,900.
        $this->assertSame( 'Silver', $this->tier_of( $user ) );
        $this->assertSame( [ 'adjustment' ], $this->last_change( $user, [ 'reason' ] ) );
        $this->assertSame( $this->calendar_hold( 'previous', 'year' ), $this->hold_of( $user ) );
    } );
}

public function test_calendar_placement_uses_the_previous_period(): void {
    $user = $this->customer();
    $this->set_bonus( 'Silver', 50 );
    $this->with_tiers_off( function () use ( $user ): void {
        $this->backdate( $this->earn( $user, 3004, 900 ), $this->in_previous_period( 'year' ) );
        $this->earn( $user, 3005, 200 );
    } );

    $this->with_calendar( 'year', function () use ( $user ): void {
        TierEvaluator::build()->sweep_one( $user, TierSweep::MODE_PLACEMENT );
        $this->assertSame( 'Silver', $this->tier_of( $user ) );
        $this->assertSame( [ 'placement' ], $this->last_change( $user, [ 'reason' ] ) );
        $this->assertSame( $this->calendar_hold( 'previous', 'year' ), $this->hold_of( $user ) );
        $this->assertSame( [], $this->bonus_rows( $user ) );
    } );
}

public function test_calendar_floor_end_starts_a_period_hold(): void {
    $user      = $this->customer();
    $evaluator = TierEvaluator::build();

    $this->with_calendar( 'year', function () use ( $user, $evaluator ): void {
        $this->earn( $user, 3006, 900 );
        $evaluator->assign_floor( $user, $this->tier_id( 'Gold' ), 30, 1 );
        $this->assertSame( [ null, null ], $this->hold_of( $user ) );

        $evaluator->clear_floor( $user, null );
        $this->assertSame( 'Silver', $this->tier_of( $user ) );
        $this->assertSame( [ 'manual_end' ], $this->last_change( $user, [ 'reason' ] ) );
        $this->assertSame( $this->calendar_hold( 'current', 'year' ), $this->hold_of( $user ) );
    } );
}
```

- [ ] **Step 2: Run the tests and confirm they fail.**
  - Run: `composer test:integration -- --filter test_calendar_`.
  - Expected: FAIL, because the holds are NULL (the evaluator treats `calendar` as Lifetime).

- [ ] **Step 3: Read the mode and period in `TierEvaluator`.**
  - `mode()` returns the stored `tiers_mode` when it is `TierMode::ROLLING` or `TierMode::CALENDAR`, and `LIFETIME` otherwise.
  - New `public static function calendar_period(): string` returns the stored `tiers_calendar_period` when `TierMode::is_calendar_period()`, else `TierMode::DEFAULT_CALENDAR_PERIOD`.

- [ ] **Step 4: Add the Calendar branch to `apply()` and a private `calendar_decision()`.**

  The branch runs when `TierMode::CALENDAR === $mode`. It calls:

  `private function calendar_decision( Customer $customer, array $live, ?Tier $floor, int $current_rank, string $kind, string $now ): array{changed: bool, to: Tier|null, is_upgrade: bool, hold: string, source: string, bounds: array, total: int}`

  `calendar_decision()`, with `$tz = wp_timezone()` and `$period = self::calendar_period()`:
  - `$bounds = TierCalendar::bounds( $now, $period, $tz )`.
  - `$prev_end = Dates::offset( -1, $bounds['current_start'] )`.
  - **current** = `window_total( user, $bounds['current_start'], $now )`.
  - **previous** = `window_total( user, $bounds['previous_start'], $prev_end )` + `staff_adjust_since( user, $prev_end )`. This is the spec's Previous total, including this period's staff adjustments.
  - `[ $window_tier, $source ] = TierCalendar::pick( target( $live, current, null ), target( $live, previous, null ) )`.
  - With `KIND_HOLD_ONLY`, `$source` is forced to `SOURCE_PREVIOUS`, so `to_calendar` holds run to the current period's end.
  - **Hold active:** `tier_id` isn't null and `tier_held_until` > `$now`. Then:
    - `$qw = TierCalendar::qualifying_window( held_from, held_until, $period, $tz )`;
    - the qualifying tier is `target( $live, window_total( user, $qw['from'], $qw['to'] ) + staff_adjust_since( user, $qw['to'] ), null )`.
  - It returns `TierDecision::rolling_move( current_id, $current_rank, $window_tier, $qualifying, $floor, $hold_active, $kind )` plus `source`, `bounds` and `total` = current.

  In `apply()`:
  - `HOLD_START` writes `TierCalendar::hold_dates( $decision['source'], $decision['bounds'], $period, $tz )` into `tier_held_from` and `tier_held_until`.
  - `HOLD_CLEAR` writes both as NULL.
  - `$total` is the decision's `total`, so `tier_progress_value` is the current-period total plus any bonus paid.

  The class docblock adds Calendar: "Calendar qualifies on the better of the current and previous calendar period (TierCalendar), holding a tier through the period after the one that earned it."

- [ ] **Step 5: Run the tests and confirm they pass.**
  - Run: `composer test:integration -- --filter TierEvaluatorTest`.
  - Expected: OK. The 5 new cases and every existing Lifetime and Rolling case pass.
  - Run: `composer lint -- includes/Pro/Services/TierEvaluator.php tests/integration/Services/TierEvaluatorTest.php`. Expected: no error or warning.

- [ ] **Step 6: Commit.**
  - Stage: `includes/Pro/Services/TierEvaluator.php`, `tests/integration/Services/TierEvaluatorTest.php`.
  - Message: `feat(tiers): evaluate customers on calendar periods`

### Task 4: Hold end and the `to_calendar` sweep

**Files:**
- Modify: `includes/Pro/Services/TierEvaluator.php` (`sweep_one()` at :128, `end_hold()` at :183)
- Modify: `includes/Pro/Services/TierSweep.php` (constants at :23–32, `current()`'s mode list at :79, `is_mode_sweep()` at :142)
- Modify: `includes/Pro/Services/TierRunner.php` (the hold-end step at :63, class docblock at :14)
- Test: `tests/integration/Services/TierEvaluatorTest.php`

**Interfaces:**
- Consumes: `calendar_decision()` (Task 3), and `KIND_HOLD_ONLY` with the forced previous source.
- Produces: `TierSweep::MODE_TO_CALENDAR`, `end_hold()` in Calendar, and `sweep_one( $id, 'to_calendar' )`.

Test fixture `ended_calendar_hold( string $tier, int $previous_points ): int`:
- a new customer with `$previous_points` earned (with tiers off, order 3200 + n) backdated to `in_previous_period( 'year' )`, or just a row when 0;
- `set_customer` sets `tier_id` = `$tier`, `tier_held_from` = bounds `previous_start`, and `tier_held_until` = `Dates::offset( -1, bounds['current_start'] )`, computed inside the timezone filter.

- [ ] **Step 1: Write the failing tests.**

```php
public function test_calendar_hold_end_reviews_the_previous_period(): void {
    $this->with_calendar( 'year', function (): void {
        $moved   = $this->ended_calendar_hold( 'Gold', 900 );
        $renewed = $this->ended_calendar_hold( 'Silver', 900 );
        $dropped = $this->ended_calendar_hold( 'Gold', 0 );
        $waiting = $this->ended_calendar_hold( 'Gold', 0 );
        $this->set_customer( $waiting, [ 'tier_held_until' => Dates::now() ] ); // Inside the period's last second.
        $rows      = count( $this->changes( $renewed ) );
        $evaluator = TierEvaluator::build();

        foreach ( [ $moved, $renewed, $dropped, $waiting ] as $user ) {
            $evaluator->end_hold( $user );
        }

        $this->assertSame( [ 'Gold', 'Silver', 'hold_end' ], $this->last_change( $moved, [ 'from_name', 'to_name', 'reason' ] ) );
        $this->assertSame( $this->calendar_hold( 'previous', 'year' ), $this->hold_of( $moved ) );
        $this->assertSame( 'Silver', $this->tier_of( $renewed ) );
        $this->assertCount( $rows, $this->changes( $renewed ) );
        $this->assertSame( $this->calendar_hold( 'previous', 'year' ), $this->hold_of( $renewed ) );
        $this->assertSame( 'Bronze', $this->tier_of( $dropped ) );
        $this->assertSame( $this->calendar_hold( 'current', 'year' ), $this->hold_of( $dropped ) ); // Tie goes to current.
        $this->assertSame( 'Gold', $this->tier_of( $waiting ) );
    } );
}

public function test_to_calendar_step(): void {
    $evaluator = TierEvaluator::build();
    $silver    = $this->customer();
    $floored   = $this->customer();
    $this->with_tiers_off( function () use ( $silver, $floored ): void {
        $this->ledger_service()->adjust( $silver, 900, 'seed', 1 );
        $this->ledger_service()->adjust( $floored, 100, 'seed', 1 );
    } );
    $evaluator->sweep_one( $silver, TierSweep::MODE_PLACEMENT );
    $evaluator->assign_floor( $floored, $this->tier_id( 'Gold' ), null, 1 );
    $this->set_customer( $silver, [ 'tier_held_from' => Dates::now(), 'tier_held_until' => Dates::add_months( Dates::now(), 6 ) ] ); // A Rolling hold to replace.
    $rows = [ count( $this->changes( $silver ) ), count( $this->changes( $floored ) ) ];

    $this->with_calendar( 'year', function () use ( $evaluator, $silver, $floored ): void {
        $evaluator->sweep_one( $silver, TierSweep::MODE_TO_CALENDAR );
        $evaluator->sweep_one( $floored, TierSweep::MODE_TO_CALENDAR );
        $this->assertSame( 'Silver', $this->tier_of( $silver ) );
        $this->assertSame( $this->calendar_hold( 'previous', 'year' ), $this->hold_of( $silver ) );
        $this->assertSame( 'Gold', $this->tier_of( $floored ) );
        $this->assertSame( [ null, null ], $this->hold_of( $floored ) );
    } );
    $this->with_calendar( 'quarter', function () use ( $evaluator, $silver ): void {
        $evaluator->sweep_one( $silver, TierSweep::MODE_TO_CALENDAR ); // Period change.
        $this->assertSame( $this->calendar_hold( 'previous', 'quarter' ), $this->hold_of( $silver ) );
    } );

    $this->assertSame( $rows, [ count( $this->changes( $silver ) ), count( $this->changes( $floored ) ) ] );
}
```

- [ ] **Step 2: Run the tests and confirm they fail.**
  - Run: `composer test:integration -- --filter "test_calendar_hold_end|test_to_calendar_step"`.
  - Expected: errors, because `TierSweep::MODE_TO_CALENDAR` is undefined and `end_hold()` returns early outside Rolling.

- [ ] **Step 3: Extend `TierSweep` and `TierRunner`.**
  - `TierSweep::MODE_TO_CALENDAR = 'to_calendar'`, with the docblock "Qualification switched to Calendar, or its period changed: every member who isn't floor-held keeps their tier, held to the end of the current period."
  - Add it to `current()`'s `$modes` and to `is_mode_sweep()`.
  - `TierRunner::run()`:
    - runs the hold-end step while `TierEvaluator::mode()` is `rolling` or `calendar`;
    - in Calendar it calls `hold_ended_user_ids( Dates::offset( -1 ), self::BATCH_SIZE )`, so a hold inside its last second isn't returned and the continuation doesn't spin on it;
    - the docblock says "in Rolling or Calendar mode".

- [ ] **Step 4: Extend `TierEvaluator`.**
  - **`end_hold()`:**
    - it returns early unless the mode is `rolling` or `calendar`;
    - inside the plan callback, in Calendar it returns null while `Dates::offset( 1, $held_until ) > Dates::now()`;
    - the docblock covers both modes.
  - **`sweep_one()`:**
    - `MODE_TO_CALENDAR` takes the `to_rolling` path, `plan( MOVE_UP, TIER_DELETED, KIND_HOLD_ONLY )` with `$plan['mode'] = TierMode::CALENDAR`, after the deleted-tier check;
    - the docblock adds "to_calendar sets or clears each hold to the current period's end without moving anyone".

- [ ] **Step 5: Run the tests and confirm they pass.**
  - Run: `composer test:integration -- --filter TierEvaluatorTest`. Expected: OK, all cases.
  - Run: `composer test -- --filter TierDecisionTest`. Expected: OK, unchanged.
  - Run: `$WP eval 'echo wp_json_encode( ( new YayLoyalty\Repositories\CustomerRepository() )->hold_ended_user_ids( YayLoyalty\Utils\Dates::offset( -1 ), 5 ) );'`. Expected: a JSON array (no error).
  - Run: `composer lint -- includes/Pro/Services/TierEvaluator.php includes/Pro/Services/TierSweep.php includes/Pro/Services/TierRunner.php tests/integration/Services/TierEvaluatorTest.php`. Expected: no error or warning.

- [ ] **Step 6: Commit.**
  - Stage: `includes/Pro/Services/TierEvaluator.php`, `includes/Pro/Services/TierSweep.php`, `includes/Pro/Services/TierRunner.php`, `tests/integration/Services/TierEvaluatorTest.php`.
  - Message: `feat(tiers): review calendar holds at period boundaries and add the to_calendar sweep`

### Task 5: Settings REST and the SPA schema

**Files:**
- Modify: `includes/Pro/Controllers/TiersRestController.php` (args at :60–85, `get_settings()` at :132, `update_settings()` at :151)
- Modify: `apps/admin/src/lib/schema/tier.schema.ts` (:63–86)

**Interfaces:**
- Consumes: `TierEvaluator::calendar_period()` (Task 3) and `TierSweep::MODE_TO_CALENDAR` (Task 4).
- Produces: the REST settings shape and the SPA schemas, as in the README contract.

- [ ] **Step 1: Add the period to the settings arg and `get_settings()`.**
  - New arg `tiers_calendar_period`: `type` `string`, `enum` `TierMode::CALENDAR_PERIODS`, description "The calendar period whose points count: month, quarter or year."
  - The `tiers_mode` description becomes "How customers qualify for a tier: lifetime, rolling or calendar."
  - `get_settings()` adds `'tiers_calendar_period' => TierEvaluator::calendar_period()`, and its `@return` adds `tiers_calendar_period: string`.

- [ ] **Step 2: Handle mode and period changes in `update_settings()`.**
  - `$period_changed` = the request sends `tiers_calendar_period` and it differs from `TierEvaluator::calendar_period()`.
  - When `( $mode_changed || $period_changed ) && TierSweep::is_pending()`, return the existing 409.
  - The request branch for tiers staying on becomes `$enabled && ( $mode_changed || ( $period_changed && TierMode::CALENDAR === $mode ) )`. It requests `MODE_TO_CALENDAR` for `calendar`, `MODE_TO_ROLLING` for `rolling` and `MODE_TO_LIFETIME` for `lifetime`.
  - The turn-on and turn-off branches stay as they are.
  - The docblock adds: "a period change while Calendar is (or becomes) the mode requests to_calendar, which resets every hold to the current period's end".

- [ ] **Step 3: Widen the SPA schemas in `tier.schema.ts`.**
  - `tierModeSchema = z.enum(['lifetime', 'rolling', 'calendar'])`.
  - New `tiersCalendarPeriodSchema` and `TIERS_CALENDAR_PERIODS`, each mirroring `TierMode::CALENDAR_PERIODS`.
  - `tierSettingsSchema` adds `tiers_calendar_period: tiersCalendarPeriodSchema`.
  - `sweep.mode` adds `'to_calendar'`.
  - `TierSettingsInput` adds `'tiers_calendar_period'` to its `Pick`.
  - `Mirrors` comments are updated.

- [ ] **Step 4: Verify that the SPA compiles and renders with Calendar stored.**
  - Run: `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`. Expected: exit 0.
  - Run: `pnpm --filter @yayloyalty/admin-page exec eslint src/lib/schema/tier.schema.ts` and `prettier --check` on the same file. Expected: no error.
  - After Step 5's `PUT {"tiers_mode":"calendar"}`, open `http://localhost:8889/wp-admin/admin.php?page=yayloyalty#/tiers` and a customer drawer under `#/customers`.
  - Expected:
    - both pages render;
    - the Qualification card shows neither radio checked (the Calendar option comes in Phase 2);
    - the console shows no zod or `yayloyalty` error.

- [ ] **Step 5: Verify through REST on wp-env** (with `TZ` and `TIERS`, tiers on, Lifetime, no sweep pending).
  - **Spec AC 1:**
    - `REST GET /tiers/settings`: Expected `"tiers_calendar_period":"year"`.
    - `REST PUT /tiers/settings '{"tiers_mode":"weekly"}'`, `'{"tiers_calendar_period":"week"}'` and `'{"tiers_calendar_period":3}'`: Expected `[400, …]` each, with `GET` unchanged.
  - **Spec AC 2:**
    - Set customer A to Silver and customer B to a Gold floor (`REST POST /customers/<B>/tier '{"tier_id":<Gold>,"days":null}'`).
    - `REST PUT /tiers/settings '{"tiers_mode":"calendar"}'`: Expected `200` with `"sweep":{"mode":"to_calendar","done":0,…}`.
    - While it's pending, `'{"tiers_calendar_period":"quarter"}'` returns `[409, {"code":"yayloyalty_tier_sweep_running",…}]`, and `'{"tiers_rolling_months":12}'` returns 200.
    - `RUN`. Then `$WP db query "SELECT user_id, tier_held_from, tier_held_until FROM wp_yayloyalty_customers WHERE user_id IN (<A>,<B>)"`. Expected: A `2025-12-31 17:00:00` / `2026-12-31 16:59:59`, B `NULL` / `NULL`, and no new `tier_changed` row for either.
  - **Spec AC 3:** repeat from Rolling with customer C holding Silver. Expected: C's hold equals A's dates.
  - **Spec AC 8:**
    - `REST PUT /tiers/settings '{"tiers_calendar_period":"quarter"}'`, then `RUN`. Expected: A's hold is `2026-09-30 17:00:00` / `2026-12-31 16:59:59`.
    - With tiers off (`'{"tiers_enabled":false}'`), `'{"tiers_calendar_period":"year"}'`. Expected: `"sweep":null`, and A's hold is unchanged.
  - **Spec AC 14:**
    - Turn tiers on, `RUN`.
    - `REST PUT /tiers/settings '{"tiers_mode":"lifetime"}'`, then `RUN`. Expected: `sweep.mode` `to_lifetime`, and every hold column NULL.
    - Switch to `calendar`, `RUN`, then `'{"tiers_mode":"rolling"}'`, `RUN`. Expected: A's hold is now → now + 6 months.
  - Run: `composer lint -- includes/Pro/Controllers/TiersRestController.php`. Expected: no error or warning.

- [ ] **Step 6: Commit.**
  - Stage: `includes/Pro/Controllers/TiersRestController.php`, `apps/admin/src/lib/schema/tier.schema.ts`.
  - Message: `feat(tiers): accept calendar mode and period in the tier settings API`
