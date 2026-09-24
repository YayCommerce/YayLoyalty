<?php
namespace YayLoyalty\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Enums\ActivityEvent;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Models\AbstractModel;
use YayLoyalty\Models\Customer;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CustomerMetaRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\CustomerActivityService;

/**
 * The three writers `Engine\CustomerActivityListener` delegates to, against
 * repository doubles (no WordPress, no database — see tests/bootstrap.php).
 * Doubles are anonymous classes extending the real repositories, the same
 * pattern `DashboardStatsTest` already uses; `AbstractRepository` has no
 * constructor, so each double's own constructor never calls
 * `parent::__construct()`.
 */
final class CustomerActivityServiceTest extends TestCase {

    /** @param array<string, bool> $has_map */
    private function recording_meta( array $has_map = [] ): CustomerMetaRepository {
        return new class( $has_map ) extends CustomerMetaRepository {
            /** @var array<string, bool> */
            private $has_map;

            /** @var AbstractModel[] */
            public $inserted = [];

            public function __construct( array $has_map ) {
                $this->has_map = $has_map;
            }

            public function has( int $user_id, string $meta_key ): bool {
                return $this->has_map[ $user_id . ':' . $meta_key ] ?? false;
            }

            public function insert( AbstractModel $model ): int {
                $this->inserted[] = $model;

                return count( $this->inserted );
            }
        };
    }

    /** @param array<int, Customer> $members */
    private function customers( array $members = [] ): CustomerRepository {
        return new class( $members ) extends CustomerRepository {
            /** @var array<int, Customer> */
            private $members;

            public function __construct( array $members ) {
                $this->members = $members;
            }

            public function find_by_user_id( int $user_id ): ?Customer {
                return $this->members[ $user_id ] ?? null;
            }
        };
    }

    private function ledger( int $total = 0 ): LedgerRepository {
        return new class( $total ) extends LedgerRepository {
            /** @var int */
            private $total;

            public function __construct( int $total ) {
                $this->total = $total;
            }

            public function count_for_user( int $user_id ): int {
                return $this->total;
            }
        };
    }

    private function make_ledger_entry(
        string $type,
        string $source,
        int $points = 100,
        ?int $actor_id = null,
        ?string $note = null
    ): LedgerEntry {
        return new LedgerEntry( 5, 42, $points, 500, $type, $source, null, null, null, null, null, $note, $actor_id, '2026-09-22 10:00:00' );
    }

    public function test_record_points_adjusted_writes_for_adjust_manual(): void {
        $meta    = $this->recording_meta();
        $service = new CustomerActivityService( $meta, $this->customers(), $this->ledger() );

        $service->record_points_adjusted( $this->make_ledger_entry( LedgerType::ADJUST, LedgerSource::MANUAL, -50, 9, 'goodwill' ) );

        $this->assertCount( 1, $meta->inserted );
        $this->assertSame( ActivityEvent::POINTS_ADJUSTED, $meta->inserted[0]->get_meta_key() );
        $this->assertSame(
            [
                'points' => -50,
                'note'   => 'goodwill',
            ],
            $meta->inserted[0]->get_data()
        );
    }

    public function test_record_points_adjusted_writes_nothing_for_earn_or_non_manual_adjust(): void {
        $meta    = $this->recording_meta();
        $service = new CustomerActivityService( $meta, $this->customers(), $this->ledger() );

        $service->record_points_adjusted( $this->make_ledger_entry( LedgerType::EARN, LedgerSource::ORDER ) );
        $service->record_points_adjusted( $this->make_ledger_entry( LedgerType::ADJUST, LedgerSource::IMPORT ) );

        $this->assertCount( 0, $meta->inserted );
    }

    /** A balance-only adjustment (CORRECTION) is still a manual admin action — same activity log entry as ADJUST. */
    public function test_record_points_adjusted_writes_for_correction_manual(): void {
        $meta    = $this->recording_meta();
        $service = new CustomerActivityService( $meta, $this->customers(), $this->ledger() );

        $service->record_points_adjusted( $this->make_ledger_entry( LedgerType::CORRECTION, LedgerSource::MANUAL, 100, 9, 'balance only' ) );

        $this->assertCount( 1, $meta->inserted );
        $this->assertSame( ActivityEvent::POINTS_ADJUSTED, $meta->inserted[0]->get_meta_key() );
    }

    public function test_record_account_created_writes_once(): void {
        $meta    = $this->recording_meta();
        $service = new CustomerActivityService( $meta, $this->customers(), $this->ledger() );

        $service->record_account_created( $this->make_ledger_entry( LedgerType::EARN, LedgerSource::ORDER ) );
        $this->assertCount( 1, $meta->inserted );

        $meta_seen = $this->recording_meta( [ '42:account_created' => true ] );
        $service   = new CustomerActivityService( $meta_seen, $this->customers(), $this->ledger() );
        $service->record_account_created( $this->make_ledger_entry( LedgerType::EARN, LedgerSource::ORDER ) );

        $this->assertCount( 0, $meta_seen->inserted, 'a write must not happen once has() reports true' );
    }

    public function test_record_account_deleted_writes_nothing_for_non_member(): void {
        $meta    = $this->recording_meta();
        $service = new CustomerActivityService( $meta, $this->customers(), $this->ledger() );

        $service->record_account_deleted( 42, 'member@example.com', 'Member Name' );

        $this->assertCount( 0, $meta->inserted );
    }

    public function test_record_account_deleted_captures_snapshot_and_omits_unbuildable_facts(): void {
        $meta    = $this->recording_meta();
        $service = new CustomerActivityService(
            $meta,
            $this->customers( [ 42 => new Customer( 42, 1200 ) ] ),
            $this->ledger( 7 )
        );

        $service->record_account_deleted( 42, 'member@example.com', 'Member Name' );

        $this->assertCount( 1, $meta->inserted );
        $data = $meta->inserted[0]->get_data();
        $this->assertSame( 'member@example.com', $data['email'] );
        $this->assertSame( 'Member Name', $data['display_name'] );
        $this->assertSame( 1200, $data['balance_at_deletion'] );
        $this->assertSame( 7, $data['kept_ledger_entries'] );
        $this->assertArrayNotHasKey( 'void_coupons', $data );
        $this->assertArrayNotHasKey( 'credited_referrals', $data );
        $this->assertArrayNotHasKey( 'counted_in_members', $data );
    }

    public function test_record_account_deleted_writes_nothing_when_already_captured(): void {
        $meta    = $this->recording_meta( [ '42:account_deleted' => true ] );
        $service = new CustomerActivityService( $meta, $this->customers( [ 42 => new Customer( 42 ) ] ), $this->ledger() );

        $service->record_account_deleted( 42, 'member@example.com', 'Member Name' );

        $this->assertCount( 0, $meta->inserted );
    }
}
