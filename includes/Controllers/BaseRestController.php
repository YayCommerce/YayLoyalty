<?php
namespace YayLoyalty\Controllers;

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
            return new \WP_Error( 'internal_error', $ex->getMessage(), [ 'status' => 500 ] );
        }
    }

    /**
     * Execute Routes which DO Write to DB, auto-rollback on error.
     * Treat deep thrown error as internal_error
     *
     * @param  callable         $callback
     * @param  \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error|void
     */
    public function exec_write( $callback, $request ) {
        global $wpdb;

        try {
            $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB
            if ( is_callable( $callback ) ) {
                $response = $callback( $request );
            }
            $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB

            return rest_ensure_response( $response );

        } catch ( \Throwable $ex ) {
            $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB

            return new \WP_Error( 'internal_error', $ex->getMessage(), [ 'status' => 500 ] );
        }
    }
}
