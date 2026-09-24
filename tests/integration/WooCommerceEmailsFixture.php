<?php
namespace YayLoyalty\Tests\Integration;

/**
 * Setup shared by tests that touch WooCommerce emails: skip without
 * WooCommerce, load WC_Email, and put back every option the test changes, so
 * the site's own email settings survive the run.
 */
trait WooCommerceEmailsFixture {

    /** @var array<string, mixed> Option name => value before the test (null = absent). */
    private $saved_options = [];

    /** Skips without WooCommerce; otherwise builds its mailer, which loads WC_Email for every email class. */
    private function require_woocommerce_mailer(): void {
        if ( ! function_exists( 'WC' ) ) {
            $this->markTestSkipped( 'WooCommerce is not active on this site.' );
        }

        WC()->mailer();
    }

    /** Remembers these options so restore_options() can put them back; call from setUp(). */
    private function snapshot_options( string ...$names ): void {
        foreach ( $names as $name ) {
            $this->saved_options[ $name ] = get_option( $name, null );
        }
    }

    /** Call from tearDown(). */
    private function restore_options(): void {
        foreach ( $this->saved_options as $name => $value ) {
            if ( null === $value ) {
                delete_option( $name );
            } else {
                update_option( $name, $value );
            }
        }
    }
}
