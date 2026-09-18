<?php
namespace YayLoyalty\Controllers;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a returned WP_Error out through Transaction::run(), which only
 * rolls back on a Throwable. Internal to exec_write().
 */
final class CallbackReturnedError extends \RuntimeException {

    /** @var \WP_Error */
    private $error;

    public function __construct( \WP_Error $error ) {
        $this->error = $error;

        parent::__construct( $error->get_error_message() );
    }

    public function get_error(): \WP_Error {
        return $this->error;
    }
}
