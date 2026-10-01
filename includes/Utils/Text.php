<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Store-supplied names as plain text. WooCommerce and WordPress keep some
 * names with markup or entities ("Côte d&#039;Ivoire"); the SPA renders
 * text, never HTML, so every name it receives passes through here.
 */
final class Text {

    /** $value with tags removed and entities, quotes included, decoded. */
    public static function plain( string $value ): string {
        return html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES );
    }

    /** The site title as plain text, for copy shown to shoppers ("Adjustment by {store}"). */
    public static function store_name(): string {
        return self::plain( (string) get_bloginfo( 'name' ) );
    }
}
