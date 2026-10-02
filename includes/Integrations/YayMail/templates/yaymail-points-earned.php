<?php
/**
 * Points earned email, rendered from the merchant's YayMail design.
 *
 * @var array<string, mixed> $args The WooCommerce template args; YayMail hands them to shortcodes as render_data.
 */

use YayLoyalty\Integrations\YayMail\PointsEarnedYayMailEmail;

defined( 'ABSPATH' ) || exit;

$template = PointsEarnedYayMailEmail::get_instance()->template; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- function-local: wc_get_template() includes this file inside a function.

if ( ! empty( $template ) ) {
    yaymail_kses_post_e( $template->get_content( $args ) );
}
