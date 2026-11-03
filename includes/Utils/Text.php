<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Store-supplied names as plain text. WooCommerce and WordPress keep some names with markup or entities
 * ("Côte d&#039;Ivoire"). The SPA renders text, never HTML, so every name it receives passes through here.
 */
final class Text {

    /** $value with tags removed and entities, quotes included, decoded. */
    public static function plain( string $value ): string {
        return html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES );
    }

    /** A user's display name as WordPress stores it, or '' when the user no longer exists. */
    public static function user_display_name( int $user_id ): string {
        $user = get_userdata( $user_id );

        return $user instanceof \WP_User ? $user->display_name : '';
    }

    /** The site title as plain text, for copy shown to shoppers ("Adjustment by {store}"). */
    public static function store_name(): string {
        return self::plain( (string) get_bloginfo( 'name' ) );
    }
}
