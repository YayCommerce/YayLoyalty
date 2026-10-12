<?php
namespace YayLoyalty\Tests\Integration\Services;

use YayLoyalty\Database\QueryException;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Table;

/** An earn row never names a deleted campaign: the earn and the campaign delete serialise on the campaign row. */
final class LedgerServiceCampaignLockTest extends IntegrationTestCase {

    private const USER_ID = 515151;

    /** @var \mysqli|null The second connection that plays the delete. */
    private $other = null;

    /** @var int|null The session's innodb_lock_wait_timeout before the test lowered it. */
    private $lock_wait = null;

    protected function tearDown(): void {
        global $wpdb;

        if ( null !== $this->other ) {
            $this->other->query( 'ROLLBACK' );
            $this->other->close();
        }

        if ( null !== $this->lock_wait ) {
            $wpdb->query( $wpdb->prepare( 'SET SESSION innodb_lock_wait_timeout = %d', $this->lock_wait ) ); // phpcs:ignore WordPress.DB -- a session setting, no table.
        }

        parent::tearDown();
    }

    public function test_earn_skips_a_deleted_campaign(): void {
        $id = $this->insert_campaign( 'Deleted', CampaignTrigger::PURCHASE, Status::ACTIVE, 1 );
        ( new CampaignRepository() )->delete( $id );

        $outcome = $this->ledger_service()->earn( $this->earn_draft( self::USER_ID, 1, 10, '', $id ) );

        $this->assertSame( Outcome::SKIPPED, $outcome->get_status() );
        $this->assertSame( LedgerService::REASON_CAMPAIGN_DELETED, $outcome->get_reason() );
        $this->assertSame( [], $this->ledger_points( self::USER_ID ) );
        $this->assertSame( 0, (int) $this->customer_row( self::USER_ID )['balance'] );
    }

    public function test_earn_waits_while_a_delete_holds_the_campaign(): void {
        global $wpdb;

        $id = $this->insert_campaign( 'Being deleted', CampaignTrigger::PURCHASE, Status::ACTIVE, 1 );

        list( $host, $port, $socket ) = $wpdb->parse_db_host( DB_HOST );
        $this->other                  = new \mysqli( (string) $host, DB_USER, DB_PASSWORD, DB_NAME, (int) $port, (string) $socket ); // phpcs:ignore WordPress.DB.RestrictedClasses -- a second connection, which $wpdb cannot be.
        $this->other->query( 'START TRANSACTION' );
        $this->other->query( 'SELECT id FROM `' . esc_sql( Table::name( 'campaigns' ) ) . '` WHERE id = ' . (int) $id . ' FOR UPDATE' );

        $this->lock_wait = (int) $wpdb->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' ); // phpcs:ignore WordPress.DB -- a session setting, no table.
        $wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 1' ); // phpcs:ignore WordPress.DB -- a session setting, no table.
        $suppressed = $wpdb->suppress_errors( true );

        try {
            $this->ledger_service()->earn( $this->earn_draft( self::USER_ID, 1, 10, '', $id ) );
            $this->fail( 'The earn wrote past a campaign row another transaction holds.' );
        } catch ( QueryException $e ) {
            $this->assertSame( [], $this->ledger_points( self::USER_ID ) );
        } finally {
            $wpdb->suppress_errors( $suppressed );
        }
    }
}
