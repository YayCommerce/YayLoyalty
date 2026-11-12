<?php
namespace YayLoyalty\Tests\Integration\Services;

use YayLoyalty\Enums\ActivityEvent;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Pro\Services\TierCalendar;
use YayLoyalty\Pro\Services\TierEvaluator;
use YayLoyalty\Pro\Services\TierSweep;
use YayLoyalty\Pro\YayLoyaltyPro;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\TierRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Json;
use YayLoyalty\Utils\Table;

/**
 * TierEvaluator's ledger writes: the entry bonus is paid once per customer per tier, never on placement, and
 * never lifts the customer a second step in the same evaluation; a refund or deduction moves them down.
 * In Rolling mode: the window total qualifies, an upgrade starts a hold, a negative row during a hold is
 * judged on the qualifying total, a hold end moves or renews, and the mode sweeps set or clear holds.
 * In Calendar mode: the better of the current and previous period qualifies, holds run to a period's end,
 * and a negative row during a hold is judged on the period that earned it.
 * Points move through LedgerService, whose recorded hook runs the evaluator after commit, as on a live site.
 */
final class TierEvaluatorTest extends IntegrationTestCase {

    /** @var bool The pinned `tiers_enabled`. */
    private $tiers_enabled = true;

    /** @var string The pinned `tiers_mode`. */
    private $tiers_mode = 'lifetime';

    /** @var int The pinned `tiers_rolling_months`. */
    private $rolling_months = 6;

    /** @var string The pinned `tiers_calendar_period`. */
    private $calendar_period = 'year';

    /** @var int[] Users this test created, deleted in tearDown(). */
    private $users = [];

    protected function setUp(): void {
        parent::setUp();

        if ( ! class_exists( YayLoyaltyPro::class ) ) {
            $this->markTestSkipped( 'Pro is not booted on this site.' );
        }

        add_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );

        $tiers = new TierRepository();
        $tiers->seed_defaults( Dates::now() );
        $tiers->rerank();
    }

    protected function tearDown(): void {
        remove_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );

        require_once ABSPATH . 'wp-admin/includes/user.php';

        foreach ( $this->users as $user_id ) {
            wp_delete_user( $user_id );
        }

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    public function pinned_settings(): array {
        return [
            'schema_version'        => SettingsHelper::SCHEMA_VERSION,
            'tiers_enabled'         => $this->tiers_enabled,
            'tiers_mode'            => $this->tiers_mode,
            'tiers_rolling_months'  => $this->rolling_months,
            'tiers_calendar_period' => $this->calendar_period,
        ];
    }

    public function test_upgrade_pays_bonus_once_with_its_key(): void {
        $this->set_bonus( 'Silver', 100 );
        $user = $this->customer();
        $this->ledger_service()->adjust( $user, 780, 'seed', 1 );
        $this->earn( $user, 1001, 30 );

        $this->assertSame( 'Silver', $this->tier_of( $user ) );

        $bonus = $this->bonus_rows( $user );

        $this->assertCount( 1, $bonus );
        $this->assertSame( 100, (int) $bonus[0]['points'] );
        $this->assertSame( 'earn:' . $user . ':tier:' . $this->tier_id( 'Silver' ) . ':tier_bonus', $bonus[0]['idempotency_key'] );
        $this->assertSame( [ 'tier', $this->tier_id( 'Silver' ) ], [ $bonus[0]['reference_type'], (int) $bonus[0]['reference_id'] ] );
        $this->assertSame( 910, (int) $this->customer_row( $user )['lifetime_earned'] );
        $this->assertSame( [ 'Bronze', 'Silver', 'earn' ], $this->last_change( $user, [ 'from_name', 'to_name', 'reason' ] ) );
    }

    public function test_reaching_the_tier_again_pays_no_second_bonus(): void {
        $this->set_bonus( 'Silver', 100 );
        $user = $this->customer();
        $this->ledger_service()->adjust( $user, 900, 'seed', 1 );  // Silver + 100 bonus = 1000.
        $this->ledger_service()->adjust( $user, -300, 'undo', 1 ); // 700 → Bronze.

        $this->assertSame( 'Bronze', $this->tier_of( $user ) );

        $this->ledger_service()->adjust( $user, 200, 'again', 1 ); // 900 → Silver.

        $this->assertSame( 'Silver', $this->tier_of( $user ) );
        $this->assertCount( 1, $this->bonus_rows( $user ) );
    }

    public function test_bonus_row_does_not_cascade(): void {
        $this->set_threshold( 'Gold', 850 );
        $this->set_bonus( 'Silver', 100 );
        $user = $this->customer();
        $this->ledger_service()->adjust( $user, 780, 'seed', 1 );
        $this->earn( $user, 1002, 30 ); // 810 → Silver, bonus → 910 ≥ 850.

        $this->assertSame( 'Silver', $this->tier_of( $user ) );

        $this->earn( $user, 1003, 1 );

        $this->assertSame( 'Gold', $this->tier_of( $user ) );
    }

    public function test_refund_downgrades_and_keeps_the_bonus(): void {
        $this->set_bonus( 'Silver', 100 );
        $user = $this->customer();
        $this->ledger_service()->adjust( $user, 780, 'seed', 1 );
        $earn = $this->earn( $user, 1004, 130 );                              // 910 → Silver, bonus → 1010.
        $this->ledger_service()->refund_earn( $earn, 130, 'refund', 1 );   // 880: still ≥ 800.

        $this->assertSame( 'Silver', $this->tier_of( $user ) );

        $this->ledger_service()->adjust( $user, -100, 'down', 1 ); // 780.

        $this->assertSame( 'Bronze', $this->tier_of( $user ) );
        $this->assertCount( 1, $this->bonus_rows( $user ) );
    }

    /** @group tier-sweep */
    public function test_placement_pays_no_bonus(): void {
        $this->set_bonus( 'Silver', 100 );
        $user = $this->customer();
        $this->with_tiers_off(
            function () use ( $user ): void {
                $this->ledger_service()->adjust( $user, 900, 'seed', 1 );
            }
        );

        $this->assertNull( $this->tier_of( $user ) );

        TierEvaluator::build()->sweep_one( $user, TierSweep::MODE_PLACEMENT );

        $this->assertSame( 'Silver', $this->tier_of( $user ) );
        $this->assertCount( 0, $this->bonus_rows( $user ) );
        $this->assertSame( [ 'placement' ], $this->last_change( $user, [ 'reason' ] ) );
    }

    public function test_tiers_off_writes_nothing(): void {
        $user = $this->customer();
        $this->with_tiers_off(
            function () use ( $user ): void {
                $this->ledger_service()->adjust( $user, 900, 'seed', 1 );
            }
        );

        $this->assertNull( $this->customer_row( $user )['tier_evaluated_at'] );
        $this->assertSame( [], $this->changes( $user ) );
    }

    public function test_manual_floor_pays_bonus_once_and_low_floor_pays_none(): void {
        $this->set_bonus( 'Gold', 50 );
        $gold      = $this->tier_id( 'Gold' );
        $user      = $this->customer();
        $evaluator = TierEvaluator::build();
        $this->ledger_service()->adjust( $user, 100, 'seed', 1 ); // Bronze.

        $evaluator->assign_floor( $user, $gold, null, 1 );

        $this->assertSame( 'Gold', $this->tier_of( $user ) );
        $bonus = $this->bonus_rows( $user );
        $this->assertCount( 1, $bonus );
        $this->assertSame( 50, (int) $bonus[0]['points'] );
        $this->assertSame( 'earn:' . $user . ':tier:' . $gold . ':tier_bonus', $bonus[0]['idempotency_key'] );
        $this->assertSame( [ 'Bronze', 'Gold', 'manual', 1 ], $this->last_change( $user, [ 'from_name', 'to_name', 'reason', 'actor_id' ] ) );

        $evaluator->clear_floor( $user, 1 ); // 150 → Bronze.

        $this->assertSame( 'Bronze', $this->tier_of( $user ) );
        $this->assertNull( $this->customer_row( $user )['tier_manual_id'] );

        $evaluator->assign_floor( $user, $gold, 30, 1 );

        $this->assertSame( 'Gold', $this->tier_of( $user ) );
        $this->assertCount( 1, $this->bonus_rows( $user ) );

        $changes = count( $this->changes( $user ) );
        $evaluator->assign_floor( $user, $this->tier_id( 'Silver' ), null, 1 ); // Below Gold: stored only.

        $row = $this->customer_row( $user );
        $this->assertSame( 'Gold', $this->tier_of( $user ) );
        $this->assertSame( $this->tier_id( 'Silver' ), (int) $row['tier_manual_id'] );
        $this->assertNull( $row['tier_manual_until'] );
        $this->assertCount( $changes, $this->changes( $user ) );
        $this->assertCount( 1, $this->bonus_rows( $user ) );
    }

    public function test_rolling_upgrade_starts_a_hold(): void {
        $user = $this->customer();
        $this->with_tiers_off(
            function () use ( $user ): void {
                $this->backdate( $this->ledger_service()->adjust( $user, 1220, 'old', 1 ), $this->months_ago( 8 ) );
                $this->ledger_service()->adjust( $user, 780, 'recent', 1 );
            }
        );

        $this->with_rolling(
            6,
            function () use ( $user ): void {
                $this->earn( $user, 2001, 30 );
            }
        );

        $this->assertSame( 'Silver', $this->tier_of( $user ) );
        [ $from, $until ] = $this->hold_of( $user );
        $this->assertNotNull( $from );
        $this->assertSame( Dates::add_months( $from, 6 ), $until );
        $this->assertSame( '810.0000', $this->customer_row( $user )['tier_progress_value'] );
        $this->assertSame( [ 'earn' ], $this->last_change( $user, [ 'reason' ] ) );
    }

    public function test_rolling_refund_in_qualifying_window_drops(): void {
        $user = $this->customer();

        $this->with_rolling(
            6,
            function () use ( $user ): void {
                $earn = $this->earn( $user, 2002, 3000 );

                $this->assertSame( 'Gold', $this->tier_of( $user ) );

                $this->ledger_service()->refund_earn( $earn, 1500, 'refund', 1 );
            }
        );

        $this->assertSame( 'Silver', $this->tier_of( $user ) );
        $this->assertSame( [ 'refund' ], $this->last_change( $user, [ 'reason' ] ) );
        [ $from, $until ] = $this->hold_of( $user );
        $this->assertSame( Dates::add_months( $from, 6 ), $until );
    }

    public function test_rolling_refund_after_hold_start_keeps(): void {
        $user = $this->held_gold( 2800, 200 );
        $hold = $this->hold_of( $user );

        $this->with_rolling(
            6,
            function () use ( $user ): void {
                $earn = $this->earn( $user, 2003, 100 );
                $this->ledger_service()->refund_earn( $earn, 100, 'refund', 2 );
            }
        );

        $this->assertSame( 'Gold', $this->tier_of( $user ) );
        $this->assertSame( $hold, $this->hold_of( $user ) );
    }

    public function test_rolling_staff_deduction_counts_against_the_hold(): void {
        $user = $this->held_gold( 2700, 500 );
        $hold = $this->hold_of( $user );

        $this->with_rolling(
            6,
            function () use ( $user, $hold ): void {
                $this->ledger_service()->adjust( $user, -100, 'staff', 1 ); // Qualifying 3,100.

                $this->assertSame( 'Gold', $this->tier_of( $user ) );

                $this->ledger_service()->adjust( $user, -200, 'staff', 1 ); // Qualifying 2,900, window 200.

                $this->assertSame( 'Silver', $this->tier_of( $user ) );
                $this->assertSame( [ 'adjustment' ], $this->last_change( $user, [ 'reason' ] ) );
                $this->assertSame( $hold, $this->hold_of( $user ) );

                $this->ledger_service()->adjust( $user, 400, 'staff', 1 ); // Window 600: up only, reaches Bronze.
            }
        );

        $this->assertSame( 'Silver', $this->tier_of( $user ) );
    }

    public function test_hold_end_moves_and_renews(): void {
        $moved   = $this->ended_hold( 'Gold', 900 );
        $renewed = $this->ended_hold( 'Silver', 1000 );
        $bronze  = $this->ended_hold( 'Gold', 0 );
        $rows    = count( $this->changes( $renewed ) );

        $this->with_rolling(
            6,
            function () use ( $moved, $renewed, $bronze ): void {
                $evaluator = TierEvaluator::build();
                $evaluator->end_hold( $moved );
                $evaluator->end_hold( $renewed );
                $evaluator->end_hold( $bronze );
            }
        );

        $this->assertSame( 'Silver', $this->tier_of( $moved ) );
        $this->assertSame( [ 'Gold', 'Silver', 'hold_end' ], $this->last_change( $moved, [ 'from_name', 'to_name', 'reason' ] ) );
        $this->assertFreshHold( $moved );

        $this->assertSame( 'Silver', $this->tier_of( $renewed ) );
        $this->assertCount( $rows, $this->changes( $renewed ) );
        $this->assertFreshHold( $renewed );

        $this->assertSame( 'Bronze', $this->tier_of( $bronze ) );
        $this->assertFreshHold( $bronze );

        $this->set_threshold( 'Bronze', 100 );
        $none = $this->ended_hold( 'Gold', 0 );

        $this->with_rolling(
            6,
            function () use ( $none ): void {
                TierEvaluator::build()->end_hold( $none );
            }
        );

        $this->assertNull( $this->tier_of( $none ) );
        $this->assertSame( [ null, null ], $this->hold_of( $none ) );
        $this->assertSame( [ 'hold_end' ], $this->last_change( $none, [ 'reason' ] ) );
    }

    public function test_end_hold_skips_a_hold_that_has_not_ended(): void {
        $user = $this->held_gold( 2800, 200 );
        $hold = $this->hold_of( $user );

        $this->with_rolling(
            6,
            function () use ( $user ): void {
                TierEvaluator::build()->end_hold( $user );
            }
        );

        $this->assertSame( 'Gold', $this->tier_of( $user ) );
        $this->assertSame( $hold, $this->hold_of( $user ) );
    }

    public function test_floor_replaces_hold_and_its_end_starts_one(): void {
        $user = $this->customer();
        $this->with_tiers_off(
            function () use ( $user ): void {
                $this->ledger_service()->adjust( $user, 900, 'seed', 1 );
            }
        );

        $this->with_rolling(
            6,
            function () use ( $user ): void {
                $evaluator = TierEvaluator::build();
                $evaluator->sweep_one( $user, TierSweep::MODE_PLACEMENT );

                $this->assertSame( 'Silver', $this->tier_of( $user ) );
                $this->assertNotNull( $this->hold_of( $user )[1] );

                $evaluator->assign_floor( $user, $this->tier_id( 'Gold' ), 30, 1 );

                $this->assertSame( 'Gold', $this->tier_of( $user ) );
                $this->assertSame( [ null, null ], $this->hold_of( $user ) );

                $this->set_customer( $user, [ 'tier_manual_until' => $this->months_ago( 1 ) ] );
                $evaluator->clear_floor( $user, null );

                $this->assertSame( 'Silver', $this->tier_of( $user ) );
                $this->assertSame( [ 'manual_end' ], $this->last_change( $user, [ 'reason' ] ) );
                $this->assertFreshHold( $user );

                $hold = $this->hold_of( $user );
                $evaluator->assign_floor( $user, $this->tier_id( 'Bronze' ), null, 1 ); // A low floor.

                $this->assertSame( 'Silver', $this->tier_of( $user ) );
                $this->assertSame( $hold, $this->hold_of( $user ) );
            }
        );
    }

    public function test_months_change_keeps_the_hold_and_its_length(): void {
        $user = $this->customer();
        $from = $this->months_ago( 2 );
        $this->with_tiers_off(
            function () use ( $user, $from ): void {
                $this->backdate( $this->earn( $user, 2004, 3100 ), Dates::add_months( $from, -10 ) );
            }
        );
        $this->set_customer(
            $user,
            [
                'tier_id'         => $this->tier_id( 'Gold' ),
                'tier_held_from'  => $from,
                'tier_held_until' => Dates::add_months( $from, 12 ),
            ]
        );
        $hold = $this->hold_of( $user );

        $this->with_rolling(
            6,
            function () use ( $user ): void {
                $this->ledger_service()->adjust( $user, -10, 'staff', 1 ); // Window −10; qualifying over L = 12 is 3,090.
            }
        );

        $this->assertSame( 'Gold', $this->tier_of( $user ) );
        $this->assertSame( $hold, $this->hold_of( $user ) );
    }

    public function test_bad_hold_dates_use_the_setting(): void {
        $user = $this->customer();
        $this->with_tiers_off(
            function () use ( $user ): void {
                $this->backdate( $this->earn( $user, 2005, 3100 ), $this->months_ago( 8 ) );
            }
        );
        $this->set_customer(
            $user,
            [
                'tier_id'         => $this->tier_id( 'Gold' ),
                'tier_held_from'  => null,
                'tier_held_until' => Dates::add_months( Dates::now(), 3 ),
            ]
        );

        $this->with_rolling(
            6,
            function () use ( $user ): void {
                $this->ledger_service()->adjust( $user, -10, 'staff', 1 ); // Start read as until − 6: qualifying 3,090.
            }
        );

        $this->assertSame( 'Gold', $this->tier_of( $user ) );
        $this->assertNotNull( $this->customer_row( $user )['tier_evaluated_at'] );
    }

    /** @group tier-sweep */
    public function test_mode_sweeps(): void {
        $evaluator = TierEvaluator::build();
        $silver    = $this->customer();
        $floored   = $this->customer();
        $this->with_tiers_off(
            function () use ( $silver, $floored ): void {
                $this->ledger_service()->adjust( $silver, 900, 'seed', 1 );
                $this->ledger_service()->adjust( $floored, 100, 'seed', 1 );
            }
        );
        $evaluator->sweep_one( $silver, TierSweep::MODE_PLACEMENT );
        $evaluator->assign_floor( $floored, $this->tier_id( 'Gold' ), null, 1 );
        $rows = [ count( $this->changes( $silver ) ), count( $this->changes( $floored ) ) ];

        // Lifetime is still stored: the sweep runs its own path.
        $evaluator->sweep_one( $silver, TierSweep::MODE_TO_ROLLING );
        $evaluator->sweep_one( $floored, TierSweep::MODE_TO_ROLLING );

        $this->assertSame( 'Silver', $this->tier_of( $silver ) );
        $this->assertFreshHold( $silver );
        $this->assertSame( 'Gold', $this->tier_of( $floored ) );
        $this->assertSame( [ null, null ], $this->hold_of( $floored ) );
        $this->assertSame( $rows, [ count( $this->changes( $silver ) ), count( $this->changes( $floored ) ) ] );

        $lifetime = $this->customer();
        $this->with_tiers_off(
            function () use ( $lifetime ): void {
                $this->ledger_service()->adjust( $lifetime, 3500, 'seed', 1 );
            }
        );
        $this->set_customer(
            $lifetime,
            [
                'tier_id'         => $this->tier_id( 'Silver' ),
                'tier_held_from'  => Dates::now(),
                'tier_held_until' => Dates::add_months( Dates::now(), 6 ),
            ]
        );

        $this->with_rolling(
            6,
            function () use ( $evaluator, $lifetime ): void {
                $evaluator->sweep_one( $lifetime, TierSweep::MODE_TO_LIFETIME );
            }
        );

        $this->assertSame( 'Gold', $this->tier_of( $lifetime ) );
        $this->assertSame( [ 'mode_change' ], $this->last_change( $lifetime, [ 'reason' ] ) );
        $this->assertSame( [ null, null ], $this->hold_of( $lifetime ) );
    }

    public function test_calendar_upgrade_holds_through_next_period(): void {
        $yearly  = $this->customer();
        $monthly = $this->customer();

        $this->with_calendar(
            'year',
            function () use ( $yearly ): void {
                $this->earn( $yearly, 3001, 900 );
                $this->assertSame( 'Silver', $this->tier_of( $yearly ) );
                $this->assertSame( $this->calendar_hold( 'current', 'year' ), $this->hold_of( $yearly ) );
                $this->assertSame( 900, (int) $this->customer_row( $yearly )['tier_progress_value'] );
            }
        );
        $this->with_calendar(
            'month',
            function () use ( $monthly ): void {
                $this->earn( $monthly, 3002, 900 );
                $this->assertSame( $this->calendar_hold( 'current', 'month' ), $this->hold_of( $monthly ) );
            }
        );
    }

    public function test_calendar_refund_inside_and_outside_the_qualifying_period(): void {
        $old  = $this->held_from_previous( 3000 );
        $user = $old->get_user_id();
        $hold = $this->hold_of( $user );

        $this->with_calendar(
            'year',
            function () use ( $old, $user, $hold ): void {
                $new = $this->earn( $user, 3003, 100 );
                $this->ledger_service()->refund_earn( $new, 100, 'refund', 2 ); // Kept-period order: no effect.
                $this->assertSame( 'Gold', $this->tier_of( $user ) );
                $this->assertSame( $hold, $this->hold_of( $user ) );

                $this->ledger_service()->refund_earn( $old, 500, 'refund', 3 ); // Previous and qualifying 2,500.
                $this->assertSame( 'Silver', $this->tier_of( $user ) );
                $this->assertSame( [ 'refund' ], $this->last_change( $user, [ 'reason' ] ) );
                $this->assertSame( $this->calendar_hold( 'previous', 'year' ), $this->hold_of( $user ) );
            }
        );
    }

    public function test_calendar_staff_deduction_counts_against_the_hold(): void {
        $user = $this->held_from_previous( 3200 )->get_user_id();

        $this->with_calendar(
            'year',
            function () use ( $user ): void {
                $this->ledger_service()->adjust( $user, -100, 'staff', 1 ); // Previous and qualifying 3,100.
                $this->assertSame( 'Gold', $this->tier_of( $user ) );

                $this->ledger_service()->adjust( $user, -200, 'staff', 1 ); // Previous and qualifying 2,900.
                $this->assertSame( 'Silver', $this->tier_of( $user ) );
                $this->assertSame( [ 'adjustment' ], $this->last_change( $user, [ 'reason' ] ) );
                $this->assertSame( $this->calendar_hold( 'previous', 'year' ), $this->hold_of( $user ) );
            }
        );
    }

    public function test_calendar_placement_uses_the_previous_period(): void {
        $user = $this->customer();
        $this->set_bonus( 'Silver', 50 );
        $this->with_tiers_off(
            function () use ( $user ): void {
                $this->backdate( $this->earn( $user, 3004, 900 ), $this->in_previous_period( 'year' ) );
                $this->earn( $user, 3005, 200 );
            }
        );

        $this->with_calendar(
            'year',
            function () use ( $user ): void {
                TierEvaluator::build()->sweep_one( $user, TierSweep::MODE_PLACEMENT );
                $this->assertSame( 'Silver', $this->tier_of( $user ) );
                $this->assertSame( [ 'placement' ], $this->last_change( $user, [ 'reason' ] ) );
                $this->assertSame( $this->calendar_hold( 'previous', 'year' ), $this->hold_of( $user ) );
                $this->assertSame( [], $this->bonus_rows( $user ) );
            }
        );
    }

    public function test_calendar_floor_end_starts_a_period_hold(): void {
        $user      = $this->customer();
        $evaluator = TierEvaluator::build();

        $this->with_calendar(
            'year',
            function () use ( $user, $evaluator ): void {
                $this->earn( $user, 3006, 900 );
                $evaluator->assign_floor( $user, $this->tier_id( 'Gold' ), 30, 1 );
                $this->assertSame( [ null, null ], $this->hold_of( $user ) );

                $evaluator->clear_floor( $user, null );
                $this->assertSame( 'Silver', $this->tier_of( $user ) );
                $this->assertSame( [ 'manual_end' ], $this->last_change( $user, [ 'reason' ] ) );
                $this->assertSame( $this->calendar_hold( 'current', 'year' ), $this->hold_of( $user ) );
            }
        );
    }

    public function test_calendar_hold_end_reviews_the_previous_period(): void {
        $this->with_calendar(
            'year',
            function (): void {
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
            }
        );
    }

    public function test_to_calendar_step(): void {
        $evaluator = TierEvaluator::build();
        $silver    = $this->customer();
        $floored   = $this->customer();
        $this->with_tiers_off(
            function () use ( $silver, $floored ): void {
                $this->ledger_service()->adjust( $silver, 900, 'seed', 1 );
                $this->ledger_service()->adjust( $floored, 100, 'seed', 1 );
            }
        );
        $evaluator->sweep_one( $silver, TierSweep::MODE_PLACEMENT );
        $evaluator->assign_floor( $floored, $this->tier_id( 'Gold' ), null, 1 );
        $this->set_customer(
            $silver,
            [
                'tier_held_from'  => Dates::now(),
                'tier_held_until' => Dates::add_months( Dates::now(), 6 ),
            ]
        ); // A Rolling hold to replace.
        $rows = [ count( $this->changes( $silver ) ), count( $this->changes( $floored ) ) ];

        $this->with_calendar(
            'year',
            function () use ( $evaluator, $silver, $floored ): void {
                $evaluator->sweep_one( $silver, TierSweep::MODE_TO_CALENDAR );
                $evaluator->sweep_one( $floored, TierSweep::MODE_TO_CALENDAR );
                $this->assertSame( 'Silver', $this->tier_of( $silver ) );
                $this->assertSame( $this->calendar_hold( 'previous', 'year' ), $this->hold_of( $silver ) );
                $this->assertSame( 'Gold', $this->tier_of( $floored ) );
                $this->assertSame( [ null, null ], $this->hold_of( $floored ) );
            }
        );
        $this->with_calendar(
            'quarter',
            function () use ( $evaluator, $silver ): void {
                $evaluator->sweep_one( $silver, TierSweep::MODE_TO_CALENDAR ); // Period change.
                $this->assertSame( $this->calendar_hold( 'previous', 'quarter' ), $this->hold_of( $silver ) );
            }
        );

        $this->assertSame( $rows, [ count( $this->changes( $silver ) ), count( $this->changes( $floored ) ) ] );
    }

    /** @group tier-sweep */
    public function test_mode_round_trip_keeps_tiers(): void {
        $this->set_bonus( 'Silver', 100 );
        $this->set_bonus( 'Gold', 200 );
        $evaluator = TierEvaluator::build();
        $silver    = $this->customer();
        $floored   = $this->customer();
        $earn      = null;
        $this->with_tiers_off(
            function () use ( $silver, $floored, &$earn ): void {
                $earn = $this->earn( $silver, 3300, 900 );
                ( new CustomerRepository() )->ensure_row( $floored );
            }
        );
        $this->backdate( $earn, $this->in_previous_period( 'year' ) );
        $evaluator->sweep_one( $silver, TierSweep::MODE_PLACEMENT );
        $evaluator->assign_floor( $floored, $this->tier_id( 'Gold' ), null, 1 );
        $before = [ $this->changes( $silver ), $this->bonus_rows( $silver ), $this->changes( $floored ), $this->bonus_rows( $floored ) ];

        $this->with_calendar(
            'year',
            function () use ( $evaluator, $silver, $floored ): void {
                $evaluator->sweep_one( $silver, TierSweep::MODE_TO_CALENDAR );
                $evaluator->sweep_one( $floored, TierSweep::MODE_TO_CALENDAR );
                $this->assertSame( $this->calendar_hold( 'previous', 'year' ), $this->hold_of( $silver ) );
            }
        );
        $this->assertSame( [ 'Silver', 'Gold' ], [ $this->tier_of( $silver ), $this->tier_of( $floored ) ] );
        $this->assertSame( [ null, null ], $this->hold_of( $floored ) );

        $this->with_rolling(
            6,
            function () use ( $evaluator, $silver, $floored ): void {
                $evaluator->sweep_one( $silver, TierSweep::MODE_TO_ROLLING );
                $evaluator->sweep_one( $floored, TierSweep::MODE_TO_ROLLING );
            }
        );
        $this->assertSame( [ 'Silver', 'Gold' ], [ $this->tier_of( $silver ), $this->tier_of( $floored ) ] );
        $this->assertSame( [ null, null ], $this->hold_of( $floored ) );
        $this->assertFreshHold( $silver );

        $this->with_calendar(
            'month',
            function () use ( $evaluator, $silver, $floored ): void {
                $evaluator->sweep_one( $silver, TierSweep::MODE_TO_CALENDAR );
                $evaluator->sweep_one( $floored, TierSweep::MODE_TO_CALENDAR );
                $this->assertSame( $this->calendar_hold( 'previous', 'month' ), $this->hold_of( $silver ) ); // The Rolling hold is replaced.
            }
        );
        $this->assertSame( [ 'Silver', 'Gold' ], [ $this->tier_of( $silver ), $this->tier_of( $floored ) ] );
        $this->assertSame( [ null, null ], $this->hold_of( $floored ) );

        $evaluator->sweep_one( $silver, TierSweep::MODE_TO_LIFETIME );
        $evaluator->sweep_one( $floored, TierSweep::MODE_TO_LIFETIME );

        $this->assertSame( [ 'Silver', 'Gold' ], [ $this->tier_of( $silver ), $this->tier_of( $floored ) ] );
        $this->assertSame( [ null, null ], $this->hold_of( $silver ) );
        $this->assertSame( [ null, null ], $this->hold_of( $floored ) );
        // No switch moved anyone or paid a bonus.
        $this->assertSame( $before, [ $this->changes( $silver ), $this->bonus_rows( $silver ), $this->changes( $floored ), $this->bonus_rows( $floored ) ] );
    }

    public function test_lifetime_writes_no_hold(): void {
        $user = $this->customer();
        $this->ledger_service()->adjust( $user, 780, 'seed', 1 );
        $this->earn( $user, 2006, 30 );

        $this->assertSame( 'Silver', $this->tier_of( $user ) );
        $this->assertSame( [ null, null ], $this->hold_of( $user ) );
    }

    /**
     * A Gold customer mid-hold (started a month ago, six months long): $qualifying_only points before the
     * current window, and $both points inside both the window and the qualifying window.
     */
    private function held_gold( int $qualifying_only, int $both ): int {
        $user = $this->customer();
        $from = $this->months_ago( 1 );
        $this->with_tiers_off(
            function () use ( $user, $qualifying_only, $both ): void {
                $this->backdate( $this->earn( $user, 2101, $qualifying_only ), Dates::add_months( Dates::offset( -15 * DAY_IN_SECONDS ), -6 ) );
                $this->backdate( $this->earn( $user, 2102, $both ), $this->months_ago( 2 ) );
            }
        );
        $this->set_customer(
            $user,
            [
                'tier_id'         => $this->tier_id( 'Gold' ),
                'tier_held_from'  => $from,
                'tier_held_until' => Dates::add_months( $from, 6 ),
            ]
        );

        return $user;
    }

    /**
     * A Gold customer holding the tier to the end of this year, earned on $points dated in the previous year
     * (order 3100, written with tiers off).
     */
    private function held_from_previous( int $points ): LedgerEntry {
        $user = $this->customer();
        $earn = null;
        $this->with_tiers_off(
            function () use ( $user, $points, &$earn ): void {
                $earn = $this->earn( $user, 3100, $points );
            }
        );
        $this->backdate( $earn, $this->in_previous_period( 'year' ) );
        [ $from, $until ] = $this->calendar_hold( 'previous', 'year' );
        $this->set_customer(
            $user,
            [
                'tier_id'         => $this->tier_id( 'Gold' ),
                'tier_held_from'  => $from,
                'tier_held_until' => $until,
            ]
        );

        return $earn;
    }

    /**
     * A customer at $tier whose calendar hold ended with the previous year, with $previous_points earned in that
     * year (written with tiers off), or just a customer row when 0.
     */
    private function ended_calendar_hold( string $tier, int $previous_points ): int {
        static $order = 0;

        $user = $this->customer();

        if ( $previous_points > 0 ) {
            ++$order;
            $earn = null;
            $this->with_tiers_off(
                function () use ( $user, $previous_points, $order, &$earn ): void {
                    $earn = $this->earn( $user, 3200 + $order, $previous_points );
                }
            );
            $this->backdate( $earn, $this->in_previous_period( 'year' ) );
        } else {
            ( new CustomerRepository() )->ensure_row( $user );
        }

        $bounds = TierCalendar::bounds( Dates::now(), 'year', $this->tz() );
        $this->set_customer(
            $user,
            [
                'tier_id'         => $this->tier_id( $tier ),
                'tier_held_from'  => $bounds['previous_start'],
                'tier_held_until' => Dates::offset( -1, $bounds['current_start'] ),
            ]
        );

        return $user;
    }

    /** A customer at $tier whose six-month hold ended a day ago, with $window points in the current window. */
    private function ended_hold( string $tier, int $window ): int {
        $user = $this->customer();

        if ( $window > 0 ) {
            $this->with_tiers_off(
                function () use ( $user, $window ): void {
                    $this->ledger_service()->adjust( $user, $window, 'seed', 1 );
                }
            );
        } else {
            ( new CustomerRepository() )->ensure_row( $user );
        }

        $until = Dates::offset( -DAY_IN_SECONDS );
        $this->set_customer(
            $user,
            [
                'tier_id'         => $this->tier_id( $tier ),
                'tier_held_from'  => Dates::add_months( $until, -6 ),
                'tier_held_until' => $until,
            ]
        );

        return $user;
    }

    /** A hold that starts within the last minute and runs six months. */
    private function assertFreshHold( int $user_id ): void {
        [ $from, $until ] = $this->hold_of( $user_id );

        $this->assertNotNull( $from );
        $this->assertGreaterThanOrEqual( Dates::offset( -60 ), $from );
        $this->assertSame( Dates::add_months( $from, 6 ), $until );
    }

    /** @return array{0: string|null, 1: string|null} tier_held_from and tier_held_until as stored. */
    private function hold_of( int $user_id ): array {
        $row = $this->customer_row( $user_id );

        return [ $row['tier_held_from'] ?? null, $row['tier_held_until'] ?? null ];
    }

    private function months_ago( int $months ): string {
        return Dates::add_months( Dates::now(), -$months );
    }

    private function backdate( LedgerEntry $entry, string $at ): void {
        global $wpdb;

        $wpdb->update( Table::name( 'ledger' ), [ 'created_at' => $at ], [ 'id' => (int) $entry->get_id() ], [ '%s' ], [ '%d' ] ); // phpcs:ignore WordPress.DB -- a test fixture moves a row in time.
    }

    /** @param array<string, int|string|null> $columns Customer columns a fixture writes past the evaluator. */
    private function set_customer( int $user_id, array $columns ): void {
        global $wpdb;

        $wpdb->update( Table::name( 'customers' ), $columns, [ 'user_id' => $user_id ] ); // phpcs:ignore WordPress.DB -- a test fixture sets state directly.
    }

    /** Calendar mode with $period, in a fixed UTC+7 site time zone while $body runs. */
    private function with_calendar( string $period, callable $body ): void {
        $zone = static function (): string {
            return 'Asia/Ho_Chi_Minh';
        };

        $this->tiers_mode      = 'calendar';
        $this->calendar_period = $period;
        add_filter( 'pre_option_timezone_string', $zone );

        try {
            $body();
        } finally {
            remove_filter( 'pre_option_timezone_string', $zone );
            $this->tiers_mode      = 'lifetime';
            $this->calendar_period = 'year';
        }
    }

    /** The site time zone with_calendar() pins. */
    private function tz(): \DateTimeZone {
        return new \DateTimeZone( 'Asia/Ho_Chi_Minh' );
    }

    /**
     * The hold TierCalendar gives a tier reached in $source's period, as of now.
     *
     * @return array{0: string, 1: string} tier_held_from and tier_held_until.
     */
    private function calendar_hold( string $source, string $period ): array {
        $hold = TierCalendar::hold_dates( $source, TierCalendar::bounds( Dates::now(), $period, $this->tz() ), $period, $this->tz() );

        return [ $hold['from'], $hold['until'] ];
    }

    /** A moment one day into the previous $period. */
    private function in_previous_period( string $period ): string {
        return Dates::offset( DAY_IN_SECONDS, TierCalendar::bounds( Dates::now(), $period, $this->tz() )['previous_start'] );
    }

    private function with_rolling( int $months, callable $body ): void {
        $this->tiers_mode     = 'rolling';
        $this->rolling_months = $months;

        try {
            $body();
        } finally {
            $this->tiers_mode     = 'lifetime';
            $this->rolling_months = 6;
        }
    }

    /** A purchase EARN row, written through LedgerService so the recorded hook fires. */
    private function earn( int $user_id, int $order_id, int $points ): LedgerEntry {
        return $this->ledger_service()->earn( $this->earn_draft( $user_id, $order_id, $points ) )->get_entry();
    }

    /** A real customer: the evaluator skips user ids with no WordPress user. */
    private function customer(): int {
        $user_id = wp_insert_user(
            [
                'user_login' => 'tier_' . wp_generate_password( 8, false ),
                'user_pass'  => wp_generate_password(),
                'role'       => 'customer',
            ]
        );

        $this->assertIsInt( $user_id );
        $this->users[] = $user_id;

        return $user_id;
    }

    private function tier_id( string $name ): int {
        foreach ( ( new TierRepository() )->live() as $tier ) {
            if ( $name === $tier->get_name() ) {
                return (int) $tier->get_id();
            }
        }

        $this->fail( 'No live tier named ' . $name );
    }

    private function set_bonus( string $name, int $points ): void {
        ( new TierRepository() )->update( $this->tier_id( $name ), [ 'entry_bonus' => $points ], [ '%d' ] );
    }

    private function set_threshold( string $name, int $points ): void {
        $tiers = new TierRepository();
        $tiers->update( $this->tier_id( $name ), [ 'threshold' => $points ], [ '%d' ] );
        $tiers->rerank();
    }

    private function tier_of( int $user_id ): ?string {
        $tier_id = $this->customer_row( $user_id )['tier_id'] ?? null;

        if ( null === $tier_id ) {
            return null;
        }

        $tier = ( new TierRepository() )->find_tier( (int) $tier_id );

        return null === $tier ? null : $tier->get_name();
    }

    private function bonus_rows( int $user_id ): array {
        return array_values(
            array_filter(
                $this->ledger_rows( $user_id ),
                static function ( array $row ): bool {
                    return LedgerSource::TIER_BONUS === $row['source'];
                }
            )
        );
    }

    /** @return array<int, array<string, mixed>> The `tier_changed` data, oldest first. */
    private function changes( int $user_id ): array {
        global $wpdb;

        $raw = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB -- tests read the table directly, past any cache; the table name is escaped.
            $wpdb->prepare(
                'SELECT meta_value FROM `' . esc_sql( Table::name( 'customer_meta' ) ) . '` WHERE user_id = %d AND meta_key = %s ORDER BY id ASC',
                $user_id,
                ActivityEvent::TIER_CHANGED
            )
        );

        return array_map(
            static function ( $value ): array {
                return (array) ( Json::decode( (string) $value )['data'] ?? [] );
            },
            $raw
        );
    }

    /**
     * @param string[] $keys
     * @return array<int, mixed>
     */
    private function last_change( int $user_id, array $keys ): array {
        $changes = $this->changes( $user_id );

        $this->assertNotEmpty( $changes, 'No tier_changed row.' );

        $last = end( $changes );

        return array_map(
            static function ( string $key ) use ( $last ) {
                return $last[ $key ] ?? null;
            },
            $keys
        );
    }

    private function with_tiers_off( callable $write ): void {
        $this->tiers_enabled = false;

        try {
            $write();
        } finally {
            $this->tiers_enabled = true;
        }
    }
}
