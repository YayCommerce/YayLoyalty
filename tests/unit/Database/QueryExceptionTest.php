<?php
namespace YayLoyalty\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Database\QueryException;

/**
 * Reading the driver's error number, which decides whether the ledger's
 * UNIQUE index firing is "already awarded" or a real failure. The number
 * comes from a stand-in handle here; that MySQL raises 1062 on the second
 * insert of one idempotency key, and that wpdb still exposes it after a
 * suppressed insert, is proven against a server, not here.
 */
final class QueryExceptionTest extends TestCase {

    protected function tearDown(): void {
        $this->given_handle( null );
        parent::tearDown();
    }

    public function test_duplicate_entry_is_recognised_from_the_driver_number(): void {
        $this->given_errno( 1062 );

        $exception = new QueryException( 'insert', 'Duplicate entry', 'wp_yayloyalty_ledger' );

        $this->assertTrue( $exception->is_duplicate_key() );
        $this->assertSame( 1062, $exception->get_errno() );
    }

    public function test_another_constraint_failure_is_not_a_duplicate(): void {
        // 1452: foreign key. Anything that is not 1062 must reach the caller as a failure.
        $this->given_errno( 1452 );

        $this->assertFalse( ( new QueryException( 'insert' ) )->is_duplicate_key() );
    }

    public function test_a_message_that_says_duplicate_is_not_enough(): void {
        $this->given_errno( 0 );

        $exception = new QueryException( 'insert', "Duplicate entry 'earn:7:order:1:purchase' for key 'uniq_idempotency_key'" );

        $this->assertFalse( $exception->is_duplicate_key() );
    }

    public function test_an_unreadable_handle_is_not_a_duplicate(): void {
        $this->given_handle( new \stdClass() );

        $this->assertFalse( ( new QueryException( 'insert' ) )->is_duplicate_key() );
    }

    public function test_no_database_at_all_is_not_a_duplicate(): void {
        $this->given_handle( null );

        $this->assertFalse( ( new QueryException( 'insert' ) )->is_duplicate_key() );
    }

    /**
     * The errno is read in the constructor, because the next statement on the
     * connection replaces it before a caller thinks to ask.
     */
    public function test_the_number_is_captured_at_construction(): void {
        $driver = $this->given_errno( 1062 );

        $exception = new QueryException( 'insert' );

        $driver->errno = 0;

        $this->assertTrue( $exception->is_duplicate_key() );
    }

    /** Installs a handle reporting $errno and returns it, so a test can change it afterwards. */
    private function given_errno( int $errno ): \stdClass {
        $driver        = new \stdClass();
        $driver->errno = $errno;

        $this->given_handle( $driver );

        return $driver;
    }

    /** The one place the global is touched; null stands for "no database here". */
    private function given_handle( ?object $driver ): void {
        $wpdb = null;

        if ( null !== $driver ) {
            $wpdb      = new \stdClass();
            $wpdb->dbh = $driver;
        }

        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here; the suite shims what it needs.
        $GLOBALS['wpdb'] = $wpdb;
    }
}
