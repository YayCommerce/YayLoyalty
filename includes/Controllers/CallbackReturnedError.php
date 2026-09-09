<?php
namespace YayLoyalty\Controllers;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a WP_Error out through a transaction boundary.
 *
 * Transaction::run() rolls back on Throwable and commits otherwise, so a
 * callback that signals failure by RETURNING a WP_Error would be committed.
 * exec_write() wraps such a return in this exception to force the rollback,
 * then unwraps it so the caller still receives the original WP_Error and the
 * endpoint's response contract is unchanged.
 *
 * Internal to exec_write(). Nothing else should throw or catch it.
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
