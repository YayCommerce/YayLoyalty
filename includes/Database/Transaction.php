<?php
namespace YayLoyalty\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Nesting-safe database transactions on a connection this plugin does not own.
 *
 * MySQL has no nested transactions, so nesting is emulated with SAVEPOINTs.
 * Three failure modes drive this design; each one silently breaks the ledger
 * invariants if ignored.
 *
 * 1. FOREIGN TRANSACTIONS. WooCommerce issues raw START TRANSACTION on the same
 *    global $wpdb (wc_transaction_query), and so do sibling plugins on this
 *    site. Issuing START TRANSACTION while one is open makes MySQL implicitly
 *    COMMIT their in-flight work — a loyalty plugin corrupting order data on
 *    the host's rollback path. So at depth 0 we probe first and take a
 *    SAVEPOINT instead whenever a transaction is already open.
 *
 * 2. RECONNECTS. wpdb silently reconnects on error 2006 and resumes on a fresh,
 *    autocommitting session. A depth counter alone would keep counting a
 *    transaction that no longer exists, making the later COMMIT a no-op. So the
 *    connection thread id is captured at depth 0 and re-checked before every
 *    commit and rollback.
 *
 * 3. DEADLOCKS. On error 1213 MySQL rolls back everything and destroys every
 *    savepoint, so ROLLBACK TO SAVEPOINT then fails. Every transactional
 *    statement's return value is checked; a failure throws rather than letting
 *    the caller return success for a row that no longer exists.
 *
 * This is the only place in the plugin that issues START TRANSACTION, COMMIT,
 * ROLLBACK or SAVEPOINT.
 */
final class Transaction {

    /** Nesting depth. 0 means no transaction is open on our behalf. */
    private static $depth = 0;

    /** Thread id of the connection the outermost transaction was opened on. */
    private static $connection_id = null;

    /** True when we issued START TRANSACTION ourselves at depth 0. */
    private static $owns_outer_transaction = false;

    /** Savepoint name used by the foreign-transaction probe. */
    private const PROBE_SAVEPOINT = 'yl_probe';

    /**
     * Run a callback inside a transaction (or savepoint when nested).
     *
     * Commits on success, rolls back and rethrows on any Throwable.
     *
     * @param  callable $callback
     * @return mixed The callback's return value.
     * @throws \Throwable Whatever the callback threw, or RuntimeException when
     *                    the transaction itself could not be managed.
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

    /**
     * Current nesting depth. 0 when nothing is open on our behalf.
     */
    public static function depth(): int {
        return self::$depth;
    }

    /**
     * True when a transaction was already open at depth 0 that we did not start.
     * Only meaningful while depth() > 0.
     */
    public static function has_foreign_transaction(): bool {
        return self::$depth > 0 && ! self::$owns_outer_transaction;
    }

    private static function begin(): void {
        if ( 0 === self::$depth ) {
            self::$connection_id = self::current_connection_id();

            if ( self::detect_open_transaction() ) {
                // Someone else's transaction is in flight. Never START here.
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
        // Decrement first and in a finally-equivalent position so a throw below
        // cannot strand the counter above 0 for the rest of the request.
        $level = --self::$depth;

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
    }

    private static function rollback(): void {
        $level = --self::$depth;

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
     * Run a transactional statement and treat failure as fatal.
     *
     * A failed ROLLBACK TO SAVEPOINT is exactly the deadlock case: continuing
     * would let the caller report success for work that was discarded.
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
     * Fail loudly when the connection changed under us. wpdb's silent reconnect
     * means the transaction is gone; pretending to commit or roll back would
     * report success for work that was never in a transaction at all.
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

    /**
     * Thread id of the current connection, or null when it cannot be read.
     */
    private static function current_connection_id(): ?int {
        global $wpdb;

        $suppress = $wpdb->suppress_errors( true );
        $id       = $wpdb->get_var( 'SELECT CONNECTION_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->suppress_errors( $suppress );

        return null === $id ? null : (int) $id;
    }

    /**
     * Is a transaction already open on this connection that we did not start?
     *
     * Implemented as a savepoint round-trip: take a savepoint, then roll back to
     * it. Inside a transaction both statements succeed. Outside one, MySQL
     * releases the savepoint as soon as the auto-committed statement that
     * created it ends, so ROLLBACK TO SAVEPOINT fails with error 1305. The
     * failure is the signal.
     *
     * Rolling back to a savepoint taken at the current point discards nothing —
     * verified against MySQL 8.0.35 with an uncommitted INSERT in flight, which
     * survived the probe intact.
     *
     * Two server-variable probes were tried first and both failed on the target
     * server:
     *   - MariaDB's @@in_transaction does not exist on MySQL ("Unknown system
     *     variable 'in_transaction'").
     *   - information_schema.INNODB_TRX returned zero rows on MySQL 8.0.35 even
     *     as root (which holds PROCESS) with an open, written-to transaction.
     *     Worse, it returns '0' rather than erroring, so a capability check that
     *     only asks "did the query run?" accepts it and then silently reports
     *     "no transaction" forever — which is exactly how the first version of
     *     this class committed WooCommerce's in-flight work in testing.
     *
     * The savepoint probe needs no privileges, is standard SQL on both engines,
     * and validates itself: it can only succeed where savepoints actually work,
     * which is the same condition this class needs anyway.
     */
    private static function detect_open_transaction(): bool {
        global $wpdb;

        $suppress = $wpdb->suppress_errors( true );

        try {
            $created = $wpdb->query( 'SAVEPOINT ' . self::PROBE_SAVEPOINT ); // phpcs:ignore WordPress.DB

            if ( false === $created ) {
                // Savepoints unavailable (non-InnoDB, or an engine that refuses
                // them). Treat as "no transaction" so we open our own; nothing
                // else here can work otherwise.
                return false;
            }

            $rolled_back = $wpdb->query( 'ROLLBACK TO SAVEPOINT ' . self::PROBE_SAVEPOINT ); // phpcs:ignore WordPress.DB

            if ( false === $rolled_back ) {
                // Savepoint vanished with the auto-committed statement: no
                // transaction was open.
                return false;
            }

            // Leave the savepoint namespace as we found it.
            $wpdb->query( 'RELEASE SAVEPOINT ' . self::PROBE_SAVEPOINT ); // phpcs:ignore WordPress.DB

            return true;
        } finally {
            $wpdb->suppress_errors( $suppress );
        }
    }

    private static function savepoint_name( int $level ): string {
        return 'yl_' . $level;
    }

    private static function reset(): void {
        self::$depth                  = 0;
        self::$connection_id          = null;
        self::$owns_outer_transaction = false;
    }
}
