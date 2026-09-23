<?php
namespace YayLoyalty\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Controllers\CustomerItemFormatter;
use YayLoyalty\Models\Customer;
use YayLoyalty\Models\CustomerMeta;

/**
 * Pure payload assembly — no WP bootstrap needed (CustomerItemFormatter
 * never touches $wpdb/get_userdata()/__()). The caller is responsible for
 * resolving `$deletion` to null unless BOTH a deletion row exists AND
 * get_userdata() currently returns false; this test exercises the
 * formatter's own contract given each already-resolved input.
 */
final class CustomerItemFormatterTest extends TestCase {

    private function deletion_row(): CustomerMeta {
        return CustomerMeta::from_row(
            CustomerMeta::event(
                42,
                'account_deleted',
                [
                    'email'               => 'member@example.com',
                    'display_name'        => 'Member Name',
                    'balance_at_deletion' => 1200,
                    'kept_ledger_entries' => 24,
                    'void_coupons'        => null,
                    'credited_referrals'  => null,
                    'counted_in_members'  => null,
                ],
                'automatic',
                null,
                false,
                '2026-09-01 12:00:00'
            )->to_row() + [ 'id' => 9 ]
        );
    }

    public function test_live_identity_wins_over_snapshot(): void {
        $base = ( new Customer( 42 ) )->to_array();

        $result = CustomerItemFormatter::format(
            $base,
            [
                'display_name' => 'Live Name',
                'email'        => 'live@example.com',
            ],
            5,
            2,
            $this->deletion_row(),
            1.0
        );

        $this->assertSame( 'Live Name', $result['name'] );
        $this->assertSame( 'live@example.com', $result['email'] );
    }

    public function test_snapshot_identity_used_when_live_identity_is_null(): void {
        $base = ( new Customer( 42 ) )->to_array();

        $result = CustomerItemFormatter::format( $base, null, 5, 2, $this->deletion_row(), 1.0 );

        $this->assertSame( 'Member Name', $result['name'] );
        $this->assertSame( 'member@example.com', $result['email'] );
    }

    public function test_identity_is_empty_string_with_no_live_identity_and_no_deletion(): void {
        $base   = ( new Customer( 42 ) )->to_array();
        $result = CustomerItemFormatter::format( $base, null, 5, 2, null, 1.0 );

        $this->assertSame( '', $result['name'] );
        $this->assertSame( '', $result['email'] );
    }

    public function test_deleted_at_is_null_without_a_deletion_row(): void {
        $base   = ( new Customer( 42 ) )->to_array();
        $result = CustomerItemFormatter::format(
            $base,
            [
                'display_name' => 'Live',
                'email'        => 'live@example.com',
            ],
            5,
            2,
            null,
            1.0
        );

        $this->assertNull( $result['deleted_at'] );
        $this->assertNull( $result['loose_ends'] );
    }

    public function test_deleted_at_and_loose_ends_come_from_the_deletion_row(): void {
        $base   = ( new Customer( 42 ) )->to_array();
        $result = CustomerItemFormatter::format( $base, null, 5, 2, $this->deletion_row(), 1.0 );

        $this->assertSame( '2026-09-01 12:00:00', $result['deleted_at'] );
        $this->assertSame(
            [
                'void_coupons'        => null,
                'credited_referrals'  => null,
                'counted_in_members'  => null,
                'kept_ledger_entries' => 24,
            ],
            $result['loose_ends']
        );
    }

    public function test_counts_map_ledger_and_activity_real_redemptions_referrals_zero(): void {
        $base   = ( new Customer( 42 ) )->to_array();
        $result = CustomerItemFormatter::format( $base, null, 5, 3, null, 1.0 );

        $this->assertSame(
            [
                'ledger'      => 5,
                'activity'    => 3,
                'redemptions' => 0,
                'referrals'   => 0,
            ],
            $result['counts']
        );
    }
}
