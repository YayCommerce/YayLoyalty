<?php
namespace YayLoyalty\Tests\Integration\Services;

use YayLoyalty\Enums\ActivityEvent;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Pro\Services\TierEvaluator;
use YayLoyalty\Pro\Services\TierSweep;
use YayLoyalty\Pro\YayLoyaltyPro;
use YayLoyalty\Repositories\TierRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Json;
use YayLoyalty\Utils\Table;

/**
 * TierEvaluator's ledger writes: the entry bonus is paid once per customer per tier, never on placement, and
 * never lifts the customer a second step in the same evaluation; a refund or deduction moves them down.
 * Points move through LedgerService, whose recorded hook runs the evaluator after commit, as on a live site.
 */
final class TierEvaluatorTest extends IntegrationTestCase {

    /** @var bool The pinned `tiers_enabled`. */
    private $tiers_enabled = true;

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
            'schema_version' => SettingsHelper::SCHEMA_VERSION,
            'tiers_enabled'  => $this->tiers_enabled,
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
