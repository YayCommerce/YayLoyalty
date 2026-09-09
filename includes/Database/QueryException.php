<?php
namespace YayLoyalty\Database;

defined( 'ABSPATH' ) || exit;

/**
 * A database write failed.
 *
 * This exception is load-bearing, not decorative. $wpdb->insert() and friends
 * return false on failure — they do not throw. Rollback in this codebase is
 * exception-driven (Transaction::run() commits unless a Throwable escapes), so
 * a repository that quietly returns false would let a FAILED write commit and
 * report success.
 *
 * Every repository write therefore checks the return value and $wpdb->last_error
 * and throws this.
 *
 * The message carries SQL detail and is safe to log but never to display;
 * BaseRestController::error_internal() enforces that.
 */
final class QueryException extends \RuntimeException {

    /** @var string */
    private $db_error;

    /** @var string */
    private $operation;

    public function __construct( string $operation, string $db_error = '', string $context = '' ) {
        $this->operation = $operation;
        $this->db_error  = $db_error;

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
}
