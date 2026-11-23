<?php
namespace YayLoyalty\Integrations\PageBuilders\Divi;

use YayLoyalty\Integrations\PageBuilders\PlacementWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * The four placements as Divi 4 modules. The Visual Builder draws them from their PHP output (partial support).
 *
 * @since 1.1.0
 */
final class DiviModules {

    public static function register(): void {
        add_action( 'et_builder_ready', [ self::class, 'register_modules' ] );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_builder_styles' ] );
    }

    /** Divi 4 registers a module by constructing it. */
    public static function register_modules(): void {
        new PointsBalanceModule();
        new WaysToEarnModule();
        new WaysToRedeemModule();
        new RedeemCardModule();
    }

    public static function enqueue_builder_styles(): void {
        if ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) {
            PlacementWidgets::enqueue_preview_styles();
        }
    }

    /** The Visual Builder page itself, or its AJAX request that renders a partial-support module. */
    public static function is_preview(): bool {
        if ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) {
            return true;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- only reads which request this is; Divi checks the nonce before rendering.
        return wp_doing_ajax() && isset( $_POST['action'] ) && 'et_fb_ajax_render_shortcode' === sanitize_key( wp_unslash( $_POST['action'] ) );
    }
}
