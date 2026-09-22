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

            if ( 'all' === $group ) {
                $this->assertNull( $types );
                continue;
            }

            $this->assertNotEmpty( $types, $group );

            foreach ( $types as $type ) {
                $this->assertContains( $type, ActivityQueryArgs::EVENT_TYPES, $group );
            }
        }
    }

    public function test_event_types_is_the_activity_event_vocabulary(): void {
        $this->assertSame( ActivityEvent::ALL, ActivityQueryArgs::EVENT_TYPES );
        $this->assertContains( ActivityEvent::ACCOUNT_DELETED, ActivityQueryArgs::EVENT_TYPES );
    }

    public function test_admin_group_includes_account_deleted(): void {
        $this->assertContains( ActivityEvent::ACCOUNT_DELETED, ActivityQueryArgs::event_types_for( 'admin' ) );
    }
}
