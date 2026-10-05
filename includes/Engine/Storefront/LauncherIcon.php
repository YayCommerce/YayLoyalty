<?php
namespace YayLoyalty\Engine\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * The launcher button's icon for each `launcher.icon` choice in the Design tab.
 *
 * Inline SVG in currentColor so it follows the button's foreground; `default` is lucide's Gift, the
 * rest are the plain shapes the Design tab names. `custom` is the merchant's uploaded image, falling
 * back to `default` when the attachment is gone.
 */
final class LauncherIcon {

    private const SHAPES = [
        'default' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"/>',
        'circle'  => '<circle cx="12" cy="12" r="8" fill="currentColor"/>',
        'diamond' => '<path d="M12 3l9 9-9 9-9-9z" fill="currentColor"/>',
        'bar'     => '<rect x="4" y="9" width="16" height="6" rx="1" fill="currentColor"/>',
        'ring'    => '<circle cx="12" cy="12" r="8"/>',
        'line'    => '<path d="M5 12h14"/>',
    ];

    /** Safe HTML for the button: an inline SVG, or an <img> for a custom upload. */
    public static function html( string $icon, int $upload_id ): string {
        if ( 'custom' === $icon && $upload_id > 0 ) {
            $url = wp_get_attachment_image_url( $upload_id, 'thumbnail' );

            if ( $url ) {
                return '<img src="' . esc_url( $url ) . '" alt="" class="yl:size-5 yl:object-contain yl:m-0" />';
            }
        }

        $shape = self::SHAPES[ $icon ] ?? self::SHAPES['default'];

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" class="yl:size-5 yl:shrink-0">' . $shape . '</svg>';
    }

    /** lucide's X, for the panel's close button. */
    public static function close(): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" class="yl:size-5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
    }
}
