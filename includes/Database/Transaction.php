<?php
namespace YayLoyalty\Database;

use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Nesting-safe transactions on a $wpdb this plugin does not own; the only
 * place that issues START TRANSACTION, COMMIT, ROLLBACK or SAVEPOINT.
 * Nesting is emulated with savepoints. A foreign transaction (WooCommerce's
 * wc_transaction_query) is probed first, since START TRANSACTION would
 * implicitly commit it; the connection id is re-checked before commit and
 * rollback because wpdb silently reconnects; every statement's result is
 * checked because a deadlock destroys every savepoint.
 */
final class Transaction {

    /** 0 means no transaction is open on our behalf. */
    private static $depth = 0;

    /** Thread id the outermost transaction was opened on. */
    private static $connection_id = null;

    /** Whether we issued START TRANSACTION ourselves at depth 0. */
    private static $owns_outer_transaction = false;

    /** @var array<int, callable[]> after_commit() callbacks keyed by the depth that registered them. */
    private static $after_commit = [];

    private const PROBE_SAVEPOINT = 'yl_probe';

    /**
     * Runs a callback inside a transaction (a savepoint when nested); commits on success, rolls back and rethrows on any Throwable.
     *
     * @return mixed The callback's return value.
     * @throws \Throwable Whatever the callback threw, or RuntimeException when the transaction could not be managed.
     */
    public static function run( callable $callback ) {
        self::begin();

        try {
            $result = $callback();
        } catch ( \Throwable $e ) {
            self::rollback();
            throw $e;
        }

        self::commit();

        return $result;
    }

    /** Current nesting depth; 0 when nothing is open on our behalf. */
    public static function depth(): int {
        return self::$depth;
    }

    /**
     * Runs $callback once the outermost transaction commits; now when none is open.
     *
     * A released savepoint hands its callbacks to its parent and a rolled-back
     * one drops them, so a callback never fires for a write that was undone.
     * Under a foreign transaction "commit" is our level-0 savepoint release:
     * best effort, since the owner may still roll back after it.
     */
    public static function after_commit( callable $callback ): void {
        if ( 0 === self::$depth ) {
            self::run_callbacks( [ $callback ] );
            return;
        }

        self::$after_commit[ self::$depth ][] = $callback;
    }

    /** Whether a transaction we did not start was open at depth 0. Only meaningful while depth() > 0. */
    public static function has_foreign_transaction(): bool {
        return self::$depth > 0 && ! self::$owns_outer_transaction;
    }

    private static function begin(): void {
        if ( 0 === self::$depth ) {
            self::$connection_id = self::current_connection_id();

            if ( self::detect_open_transaction() ) {
                // Someone else's transaction is in flight; START would commit it.
                self::$owns_outer_transaction = false;
                self::execute( 'SAVEPOINT ' . self::savepoint_name( 0 ) );
            } else {
                self::$owns_outer_transaction = true;
                self::execute( 'START TRANSACTION' );
            }
        } else {
            self::execute( 'SAVEPOINT ' . self::savepoint_name( self::$depth ) );
        }

        ++self::$depth;
    }

    private static function commit(): void {
        // Decrement first so a throw below cannot strand the counter for the rest of the request.
        $level     = --self::$depth;
        $callbacks = self::$after_commit[ $level + 1 ] ?? [];

        unset( self::$after_commit[ $level + 1 ] );

        try {
            self::assert_same_connection( 'commit' );

            if ( 0 === $level ) {
                if ( self::$owns_outer_transaction ) {
                    self::execute( 'COMMIT' );
                } else {
                    self::execute( 'RELEASE SAVEPOINT ' . self::savepoint_name( 0 ) );
                }
            } else {
                self::execute( 'RELEASE SAVEPOINT ' . self::savepoint_name( $level ) );
            }
        } finally {
            if ( 0 === $level ) {
                self::reset();
            }
        }

        if ( $level > 0 ) {
            self::$after_commit[ $level ] = array_merge( self::$after_commit[ $level ] ?? [], $callbacks );
            return;
        }

        self::run_callbacks( $callbacks );
    }

    private static function rollback(): void {
        $level = --self::$depth;

        unset( self::$after_commit[ $level + 1 ] );

        try {
            self::assert_same_connection( 'rollback' );

            if ( 0 === $level ) {
                if ( self::$owns_outer_transaction ) {
                    self::execute( 'ROLLBACK' );
                } else {
                    self::execute( 'ROLLBACK TO SAVEPOINT ' . self::savepoint_name( 0 ) );
                }
            } else {
                self::execute( 'ROLLBACK TO SAVEPOINT ' . self::savepoint_name( $level ) );
            }
        } finally {
            if ( 0 === $level ) {
                self::reset();
            }
        }
    }

    /**
     * Runs a transactional statement; failure is fatal, since a failed ROLLBACK TO SAVEPOINT is the deadlock case.
     *
     * @throws \RuntimeException
     */
    private static function execute( string $sql ): void {
        global $wpdb;

        $suppress = $wpdb->suppress_errors( true );
        $result   = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->suppress_errors( $suppress );

        if ( false === $result ) {
            throw new \RuntimeException(
                sprintf( 'YayLoyalty: transaction statement failed (%s): %s', $sql, $wpdb->last_error )
            );
        }
    }

    /**
     * Fails when the connection changed under us: after wpdb's silent reconnect the transaction is gone.
     *
     * @throws \RuntimeException
     */
    private static function assert_same_connection( string $operation ): void {
        $current = self::current_connection_id();

        if ( null === self::$connection_id || null === $current ) {
            return;
        }

        if ( $current !== self::$connection_id ) {
            throw new \RuntimeException(
                sprintf(
                    'YayLoyalty: database connection changed mid-transaction (was %d, now %d); cannot %s.',
                    self::$connection_id,
                    $current,
                    $operation
                )
            );
        }
    }

    /** Thread id of the current connection, or null when it cannot be read. */
    private static function current_connection_id(): ?int {
        global $wpdb;

        $suppress = $wpdb->suppress_errors( true );
        $id       = $wpdb->get_var( 'SELECT CONNECTION_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->suppress_errors( $suppress );

        return null === $id ? null : (int) $id;
    }

    /**
     * Whether a transaction we did not start is open. A savepoint round-trip:
     * outside a transaction the savepoint is released with the auto-committed
     * statement, so ROLLBACK TO SAVEPOINT fails (error 1305). Discards nothing.
     * Do not swap for @@in_transaction (MariaDB only) or INNODB_TRX (returns
     * zero rows on MySQL 8 without PROCESS, silently reporting "none").
     */
    private static function detect_open_transaction(): bool {
        global $wpdb;

        $suppress = $wpdb->suppress_errors( true );

        try {
            $created = $wpdb->query( 'SAVEPOINT ' . self::PROBE_SAVEPOINT ); // phpcs:ignore WordPress.DB

            if ( false === $created ) {
                // Savepoints unavailable (non-InnoDB): open our own, nothing else can work.
                return false;
            }

            $rolled_back = $wpdb->query( 'ROLLBACK TO SAVEPOINT ' . self::PROBE_SAVEPOINT ); // phpcs:ignore WordPress.DB

            if ( false === $rolled_back ) {
                // Savepoint vanished with the auto-committed statement: nothing was open.
                return false;
            }

            $wpdb->query( 'RELEASE SAVEPOINT ' . self::PROBE_SAVEPOINT ); // phpcs:ignore WordPress.DB

            return true;
        } finally {
            $wpdb->suppress_errors( $suppress );
        }
    }

    /**
     * A throwing subscriber must not make a committed write look failed to
     * its caller, so each failure is logged and the rest still run.
     *
     * @param callable[] $callbacks
     */
    private static function run_callbacks( array $callbacks ): void {
        foreach ( $callbacks as $callback ) {
            try {
                $callback();
            } catch ( \Throwable $e ) {
                Log::error( 'Transaction', 'after_commit callback failed: ' . $e->getMessage() );
            }
        }
    }

    private static function savepoint_name( int $level ): string {
        return 'yl_' . $level;
    }

    private static function reset(): void {
        self::$depth                  = 0;
        self::$connection_id          = null;
        self::$owns_outer_transaction = false;
        self::$after_commit           = [];
    }
}
