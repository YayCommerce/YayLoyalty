<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/** Request execution wrappers shared by every REST controller. */
abstract class BaseRestController {

    public const REST_NAMESPACE = 'yayloyalty/v1';

    protected function error_unauthorized( $message = 'You are not allowed to perform this action.' ) {
        return new \WP_Error( 'unauthorized', $message, [ 'status' => 401 ] );
    }

    protected function error_forbidden( $message = 'You are not allowed to perform this action.' ) {
        return new \WP_Error( 'forbidden', $message, [ 'status' => 403 ] );
    }

    protected function error_not_found( $message = 'Not Found.' ) {
        return new \WP_Error( 'not_found', $message, [ 'status' => 404 ] );
    }

    protected function error_invalid_arguments( $message = 'Invalid Arguments.' ) {
        return new \WP_Error( 'invalid_arguments', $message, [ 'status' => 400 ] );
    }

    /** The request is well-formed but the resource's current state refuses it (e.g. a delete blocked by references elsewhere). */
    protected function error_conflict( $message = 'Conflict.' ) {
        return new \WP_Error( 'conflict', $message, [ 'status' => 409 ] );
    }

    /**
     * Runs a read-only route; any throwable becomes a 500.
     *
     * @return \WP_REST_Response|\WP_Error|void
     */
    public function exec_read( $callback, \WP_REST_Request $request ) {
        try {
            if ( is_callable( $callback ) ) {
                $response = $callback( $request );
                return rest_ensure_response( $response );
            }
        } catch ( \Throwable $ex ) {
            return $this->error_internal( $ex );
        }
    }

    /**
     * Runs a writing route inside Transaction::run(). A callback that returns
     * a WP_Error rolls back like one that throws, or a validation failure
     * would commit partial work and still answer 4xx.
     *
     * @return \WP_REST_Response|\WP_Error|void
     */
    public function exec_write( $callback, $request ) {
        if ( ! is_callable( $callback ) ) {
            return;
        }

        try {
            $response = Transaction::run(
                function () use ( $callback, $request ) {
                    $result = $callback( $request );

                    if ( is_wp_error( $result ) ) {
                        throw new CallbackReturnedError( $result ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- carries a WP_Error object, not a message; exec() returns it as the response.
                    }

                    return $result;
                }
            );

            return rest_ensure_response( $response );

        } catch ( CallbackReturnedError $ex ) {
            return $ex->get_error();

        } catch ( \Throwable $ex ) {
            return $this->error_internal( $ex );
        }
    }

    /** Generic 500 with a correlation id. The message is logged, never returned: it carries SQL and paths. */
    protected function error_internal( \Throwable $ex ): \WP_Error {
        $correlation_id = substr( md5( uniqid( '', true ) ), 0, 12 );

        // Never gated on WP_DEBUG: the correlation id must refer to a line that was written.
        Log::error(
            'REST',
            sprintf(
                '%s: %s in %s:%d',
                $correlation_id,
                $ex->getMessage(),
                $ex->getFile(),
                $ex->getLine()
            )
        );

        return new \WP_Error(
            'internal_error',
            sprintf(
                /* translators: %s: support correlation id */
                __( 'An unexpected error occurred. Reference: %s', 'yayloyalty' ),
                $correlation_id
            ),
            [
                'status'         => 500,
                'correlation_id' => $correlation_id,
            ]
        );
    }
}
