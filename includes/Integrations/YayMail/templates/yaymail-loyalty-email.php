<?php
/**
 * A YayLoyalty email, rendered from the merchant's YayMail design.
 *
 * @var array<string, mixed> $args The WooCommerce template args; YayMail hands them to shortcodes as render_data.
 */

use YayLoyalty\Integrations\YayMail\LoyaltyYayMailEmail;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- function-local: wc_get_template() includes this file inside a function.
$yaymail_email = isset( $args['email']->id ) ? LoyaltyYayMailEmail::for_email_id( (string) $args['email']->id ) : null;
$template      = null !== $yaymail_email ? $yaymail_email->template : null;
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! empty( $template ) ) {
    yaymail_kses_post_e( $template->get_content( $args ) );
}
