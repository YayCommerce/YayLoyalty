<?php
namespace YayLoyalty\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Controllers\Schemas\ActivityQueryArgs;
use YayLoyalty\Controllers\Schemas\LedgerQueryArgs;
use YayLoyalty\Enums\ActivityEvent;
use YayLoyalty\Enums\LedgerType;

/**
 * The drawer's two filter vocabularies are groupings over a larger enum;
 * every raw value must land in a declared group and every group must name
 * only declared values.
 */
final class DrawerQueryArgsTest extends TestCase {

    public function test_every_ledger_type_groups_into_a_filter_type(): void {
        foreach ( LedgerType::all() as $type ) {
            $this->assertContains( LedgerQueryArgs::group_of( $type ), LedgerQueryArgs::filter_types(), $type );
        }
    }

    public function test_refund_types_group_with_their_forward_type(): void {
        $this->assertSame( 'earn', LedgerQueryArgs::group_of( LedgerType::REFUND_EARN ) );
        $this->assertSame( 'redeem', LedgerQueryArgs::group_of( LedgerType::REFUND_REDEEM ) );
    }

    public function test_every_activity_group_names_only_declared_event_types(): void {
        foreach ( ActivityQueryArgs::GROUPS as $group ) {
            $types = ActivityQueryArgs::event_types_for( $group );

            $this->assertNotEmpty( $types, $group );

            foreach ( $types as $type ) {
                $this->assertContains( $type, ActivityEvent::ALL, $group );
            }
        }
    }

    public function test_all_group_returns_the_full_activity_event_vocabulary(): void {
        $this->assertSame( ActivityEvent::ALL, ActivityQueryArgs::event_types_for( 'all' ) );
        $this->assertContains( ActivityEvent::ACCOUNT_DELETED, ActivityQueryArgs::event_types_for( 'all' ) );
    }

    public function test_admin_group_includes_account_deleted(): void {
        $this->assertContains( ActivityEvent::ACCOUNT_DELETED, ActivityQueryArgs::event_types_for( 'admin' ) );
    }
}
