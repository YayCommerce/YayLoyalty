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
