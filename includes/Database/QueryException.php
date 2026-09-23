<?php
namespace YayLoyalty\Database;

defined( 'ABSPATH' ) || exit;

/**
 * A database write failed. Load-bearing: $wpdb returns false rather than
 * throwing, and Transaction::run() commits unless a Throwable escapes, so
 * every repository write must throw this. The message carries SQL: log it,
 * never display it.
 */
final class QueryException extends \RuntimeException {

    /** MySQL's duplicate-entry code; the only driver number this class interprets. */
    private const ER_DUP_ENTRY = 1062;

    /** @var string */
    private $db_error;

    /** @var string */
    private $operation;

    /** @var int Driver error number, 0 when none was readable. */
    private $errno;

    public function __construct( string $operation, string $db_error = '', string $context = '' ) {
        $this->operation = $operation;
        $this->db_error  = $db_error;
        $this->errno     = self::read_errno();

        $message = sprintf( 'YayLoyalty: database %s failed', $operation );

        if ( '' !== $context ) {
            $message .= sprintf( ' (%s)', $context );
        }

        if ( '' !== $db_error ) {
            $message .= ': ' . $db_error;
        }

        parent::__construct( $message );
    }

    /** The raw driver error, when one was available. */
    public function get_db_error(): string {
        return $this->db_error;
    }

    /** insert, update, delete, select. */
    public function get_operation(): string {
        return $this->operation;
    }

    /**
     * Whether the failed write hit a UNIQUE index (MySQL error 1062). The
     * award path treats that as "already awarded", a normal outcome, so
     * the unique constraint — not a pre-check — is the guarantee. Decided
     * from the driver error, never from the message text.
     */
    public function is_duplicate_key(): bool {
        return self::ER_DUP_ENTRY === $this->errno;
    }

    /** The driver number this exception was built from; 0 when none was readable. */
    public function get_errno(): int {
        return $this->errno;
    }

    /**
     * Read at construction: the next statement on the connection overwrites
     * the driver's error, so by the time a caller asks it is gone. Asks the
     * handle for a number, never the message for a substring, and returns 0
     * when no handle carries one — a db drop-in may front another driver,
     * and an unreadable errno must not be mistaken for "not a duplicate", so
     * a caller that has to be sure re-reads the row instead.
     */
    private static function read_errno(): int {
        global $wpdb;

        if ( ! is_object( $wpdb ) ) {
            return 0;
        }

        // wpdb keeps dbh protected and exposes it through __get().
        $dbh = $wpdb->dbh;

        return is_object( $dbh ) && isset( $dbh->errno ) ? (int) $dbh->errno : 0;
    }
}
