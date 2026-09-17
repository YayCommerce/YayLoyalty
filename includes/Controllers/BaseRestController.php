<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Class BaseRestController
 *
 * Provides common methods for REST controllers.
 */
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

    /**
     * Execute Routes which DO NOT Write to DB.
     * Treat deep thrown error as internal_error
     *
     * @param  callable         $callback
     * @param  \WP_REST_Request $request
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
     * Execute Routes which DO Write to DB, auto-rollback on error.
     *
     * Transaction handling is delegated to Transaction::run() rather than
     * issuing START TRANSACTION here. MySQL has no nested transactions, so a
     * service that opens its own would otherwise have its inner COMMIT commit
     * this method's work, and this method's ROLLBACK would be a silent no-op.
     *
     * A callback that RETURNS a WP_Error rolls back, exactly as one that throws
     * does. Returning an error was previously treated as success and committed;
     * SettingsRestController::save_settings() and CampaignRestController use
     * that idiom; without it a validation failure inside a real write would
     * commit partial work and still answer 4xx.
     *
     * @param  callable         $callback
     * @param  \WP_REST_Request $request
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
                        // Unwound by the catch below, which returns the
                        // original WP_Error untouched.
                        throw new CallbackReturnedError( $result );
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

    /**
     * Generic 500 with a correlation id.
     *
     * The exception message is logged, never returned: it carries SQL, table
     * names and filesystem paths.
     */
    protected function error_internal( \Throwable $ex ): \WP_Error {
        $correlation_id = substr( md5( uniqid( '', true ) ), 0, 12 );

        // ALWAYS logged, never gated on WP_DEBUG. The response hands the user
        // this correlation id to quote at support; if the matching line was
        // never written the id refers to nothing, and production is exactly
        // where WP_DEBUG is off.
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
