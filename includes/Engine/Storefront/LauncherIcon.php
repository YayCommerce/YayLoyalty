<?php
namespace YayLoyalty\Engine\Storefront;

defined( 'ABSPATH' ) || exit;

/** The launcher's inline SVG icons, in currentColor so they follow the button's foreground. */
final class LauncherIcon {

    /** lucide's Gift, on the launcher button and the panel header: AccountRewardsIcon's, sized for the launcher. */
    public static function gift(): string {
        return AccountRewardsIcon::get( 'gift', 'yl:size-5 yl:shrink-0' );
    }

    /** lucide's X, for the panel's close button. */
    public static function close(): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" class="yl:size-5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
    }
}
